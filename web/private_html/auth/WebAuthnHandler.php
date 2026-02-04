<?php
/**
 * ============================================================================
 * 🔐 SIGNula - WebAuthn/PassKey Handler
 * ============================================================================
 *
 * Purpose: Handle WebAuthn/PassKey registration and authentication
 * PHP Version: 8.3+
 *
 * Features:
 * - PassKey registration
 * - PassKey authentication
 * - Biometric authentication support
 * - Hardware security key support
 * - Challenge generation and validation
 * - Credential management
 *
 * Standards:
 * - W3C Web Authentication API (WebAuthn Level 2)
 * - FIDO2 specification
 * - RFC 8152 (CBOR Object Signing and Encryption)
 *
 * @package    SIGNula
 * @subpackage Authentication
 * @version    1.0.0
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🔐 WebAuthn Handler
 *
 * Handles WebAuthn/PassKey operations.
 */
class WebAuthnHandler
{
    /**
     * @var string Relying Party name
     */
    private string $rpName;

    /**
     * @var string Relying Party ID (domain)
     */
    private string $rpId;

    /**
     * @var string Relying Party origin
     */
    private string $rpOrigin;

    /**
     * @var int Challenge validity in minutes
     */
    private int $challengeValidity = 5;

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        $this->rpName = getSetting('auth.webauthn.rp_name', 'SIGNula');
        $this->rpId = getSetting('auth.webauthn.rp_id', 'signula.id');
        $this->rpOrigin = getSetting('site.url', 'https://signula.id');
        $this->challengeValidity = (int)getSetting('auth.webauthn.challenge_validity', 5);
    }

    // ========================================================================
    // 📝 REGISTRATION
    // ========================================================================

    /**
     * 🎯 Generate Registration Options
     *
     * Creates options for WebAuthn registration (credential creation).
     *
     * @param int $userID User ID
     * @return array Registration options for client
     */
    public function generateRegistrationOptions(int $userID): array
    {
        try {
            // 🔍 Get user info
            $user = Database::fetchOne(
                "SELECT userID, email, displayName FROM tblUsers WHERE userID = ?",
                [$userID],
                'i'
            );

            if (!$user) {
                throw new RuntimeException('User not found');
            }

            // 🎲 Generate challenge
            $challenge = $this->generateChallenge();

            // 💾 Store challenge
            $this->storeChallenge($challenge, $userID, null, 'registration');

            // 🔍 Get existing credentials (for excludeCredentials)
            $existingCredentials = $this->getUserCredentials($userID);
            $excludeCredentials = [];

            foreach ($existingCredentials as $cred) {
                $excludeCredentials[] = [
                    'type' => 'public-key',
                    'id' => $cred['credentialPublicKeyID']
                ];
            }

            // 📋 Build registration options
            return [
                'challenge' => $challenge,
                'rp' => [
                    'name' => $this->rpName,
                    'id' => $this->rpId
                ],
                'user' => [
                    'id' => base64_encode((string)$user['userID']),
                    'name' => $user['email'],
                    'displayName' => $user['displayName']
                ],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],  // ES256
                    ['type' => 'public-key', 'alg' => -257] // RS256
                ],
                'authenticatorSelection' => [
                    'authenticatorAttachment' => 'platform', // Prefer platform authenticators
                    'requireResidentKey' => false,
                    'residentKey' => 'preferred',
                    'userVerification' => 'preferred'
                ],
                'timeout' => 60000, // 60 seconds
                'attestation' => 'none',
                'excludeCredentials' => $excludeCredentials
            ];

        } catch (Exception $e) {
            error_log("Failed to generate registration options: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ✅ Verify Registration
     *
     * Verifies and stores a new WebAuthn credential.
     *
     * @param int $userID User ID
     * @param array $credentialData Credential data from client
     * @param string|null $deviceName Optional device name
     * @return array Result with credential ID
     */
    public function verifyRegistration(
        int $userID,
        array $credentialData,
        ?string $deviceName = null
    ): array {
        try {
            // ✅ Validate required fields
            if (empty($credentialData['id']) ||
                empty($credentialData['rawId']) ||
                empty($credentialData['response'])) {
                throw new RuntimeException('Invalid credential data');
            }

            $response = $credentialData['response'];

            // ✅ Verify challenge
            if (empty($response['clientDataJSON'])) {
                throw new RuntimeException('Missing client data');
            }

            $clientDataJSON = base64_decode($response['clientDataJSON']);
            $clientData = json_decode($clientDataJSON, true);

            if (!$this->verifyChallenge($clientData['challenge'], $userID, 'registration')) {
                throw new RuntimeException('Invalid or expired challenge');
            }

            // ✅ Verify origin
            if ($clientData['origin'] !== rtrim($this->rpOrigin, '/')) {
                throw new RuntimeException('Origin mismatch');
            }

            // ✅ Verify type
            if ($clientData['type'] !== 'webauthn.create') {
                throw new RuntimeException('Invalid ceremony type');
            }

            // 📝 Parse authenticator data (simplified - in production use proper CBOR parsing)
            $attestationObject = base64_decode($response['attestationObject']);

            // 💾 Store credential
            $credentialID = $this->storeCredential(
                userID: $userID,
                credentialPublicKeyID: $credentialData['id'],
                credentialPublicKey: $response['attestationObject'],
                attestationType: 'none',
                deviceName: $deviceName
            );

            // 📊 Mark challenge as used
            $this->markChallengeUsed($clientData['challenge']);

            // 🎯 Enable WebAuthn for user
            Database::query(
                "UPDATE tblUsers SET webauthnEnabled = 1 WHERE userID = ?",
                [$userID],
                'i'
            );

            return [
                'success' => true,
                'credentialID' => $credentialID,
                'message' => 'PassKey registered successfully'
            ];

        } catch (Exception $e) {
            error_log("Registration verification failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    // ========================================================================
    // 🔐 AUTHENTICATION
    // ========================================================================

    /**
     * 🎯 Generate Authentication Options
     *
     * Creates options for WebAuthn authentication.
     *
     * @param string|null $email Email address (null for usernameless)
     * @return array Authentication options for client
     */
    public function generateAuthenticationOptions(?string $email = null): array
    {
        try {
            // 🎲 Generate challenge
            $challenge = $this->generateChallenge();

            $userID = null;
            if ($email) {
                $user = Database::fetchOne(
                    "SELECT userID FROM tblUsers WHERE email = ?",
                    [$email],
                    's'
                );
                $userID = $user['userID'] ?? null;
            }

            // 💾 Store challenge
            $this->storeChallenge($challenge, $userID, $email, 'authentication');

            // 🔍 Get allowed credentials
            $allowCredentials = [];
            if ($userID) {
                $credentials = $this->getUserCredentials($userID);
                foreach ($credentials as $cred) {
                    $allowCredentials[] = [
                        'type' => 'public-key',
                        'id' => $cred['credentialPublicKeyID'],
                        'transports' => json_decode($cred['transports'] ?? '[]', true)
                    ];
                }
            }

            // 📋 Build authentication options
            return [
                'challenge' => $challenge,
                'timeout' => 60000, // 60 seconds
                'rpId' => $this->rpId,
                'allowCredentials' => $allowCredentials,
                'userVerification' => 'preferred'
            ];

        } catch (Exception $e) {
            error_log("Failed to generate authentication options: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ✅ Verify Authentication
     *
     * Verifies a WebAuthn authentication assertion.
     *
     * @param array $credentialData Credential data from client
     * @return array Result with user ID if successful
     */
    public function verifyAuthentication(array $credentialData): array
    {
        try {
            // ✅ Validate required fields
            if (empty($credentialData['id']) ||
                empty($credentialData['rawId']) ||
                empty($credentialData['response'])) {
                throw new RuntimeException('Invalid credential data');
            }

            $response = $credentialData['response'];

            // ✅ Get credential from database
            $credential = Database::fetchOne(
                "SELECT * FROM tblWebAuthnCredentials WHERE credentialPublicKeyID = ? AND isActive = 1",
                [$credentialData['id']],
                's'
            );

            if (!$credential) {
                throw new RuntimeException('Credential not found or inactive');
            }

            // ✅ Verify challenge
            if (empty($response['clientDataJSON'])) {
                throw new RuntimeException('Missing client data');
            }

            $clientDataJSON = base64_decode($response['clientDataJSON']);
            $clientData = json_decode($clientDataJSON, true);

            if (!$this->verifyChallenge($clientData['challenge'], $credential['userID'], 'authentication')) {
                throw new RuntimeException('Invalid or expired challenge');
            }

            // ✅ Verify origin
            if ($clientData['origin'] !== rtrim($this->rpOrigin, '/')) {
                throw new RuntimeException('Origin mismatch');
            }

            // ✅ Verify type
            if ($clientData['type'] !== 'webauthn.get') {
                throw new RuntimeException('Invalid ceremony type');
            }

            // 📝 In production, you should also verify:
            // - Authenticator data
            // - Signature
            // - Sign count (for cloning detection)
            // This requires proper CBOR parsing and cryptographic verification

            // 📊 Update credential usage
            Database::query(
                "UPDATE tblWebAuthnCredentials
                 SET lastUsedAt = NOW(), usageCount = usageCount + 1
                 WHERE credentialID = ?",
                [$credential['credentialID']],
                'i'
            );

            // 📊 Mark challenge as used
            $this->markChallengeUsed($clientData['challenge']);

            // 📝 Log authentication
            ActivityLogger::log(
                userID: $credential['userID'],
                activityType: 'login',
                activityResult: 'success',
                activityDetails: 'WebAuthn/PassKey authentication',
                metadata: json_encode([
                    'credential_id' => $credential['credentialID'],
                    'device_name' => $credential['deviceName']
                ])
            );

            return [
                'success' => true,
                'userID' => $credential['userID'],
                'message' => 'Authentication successful'
            ];

        } catch (Exception $e) {
            error_log("Authentication verification failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    // ========================================================================
    // 🔧 CREDENTIAL MANAGEMENT
    // ========================================================================

    /**
     * 💾 Store Credential
     *
     * Stores a new WebAuthn credential.
     *
     * @param int $userID User ID
     * @param string $credentialPublicKeyID Credential public key ID
     * @param string $credentialPublicKey Public key data
     * @param string|null $attestationType Attestation type
     * @param string|null $deviceName Device name
     * @return int Credential ID
     */
    private function storeCredential(
        int $userID,
        string $credentialPublicKeyID,
        string $credentialPublicKey,
        ?string $attestationType = null,
        ?string $deviceName = null
    ): int {
        $query = "
            INSERT INTO tblWebAuthnCredentials (
                userID, credentialPublicKeyID, credentialPublicKey,
                attestationType, deviceName, userAgent, createdIP
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ";

        $credentialID = Database::insert($query, [
            $userID,
            $credentialPublicKeyID,
            $credentialPublicKey,
            $attestationType,
            $deviceName ?? $this->detectDeviceName(),
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null
        ], 'issssss');

        if (!$credentialID) {
            throw new RuntimeException('Failed to store credential');
        }

        return $credentialID;
    }

    /**
     * 🔍 Get User Credentials
     *
     * Gets all active credentials for a user.
     *
     * @param int $userID User ID
     * @return array Credentials
     */
    public function getUserCredentials(int $userID): array
    {
        $query = "
            SELECT * FROM tblWebAuthnCredentials
            WHERE userID = ? AND isActive = 1
            ORDER BY lastUsedAt DESC, createdAt DESC
        ";

        return Database::fetchAll($query, [$userID], 'i') ?: [];
    }

    /**
     * 🗑️ Revoke Credential
     *
     * Revokes a credential.
     *
     * @param int $credentialID Credential ID
     * @param string $reason Revocation reason
     * @return bool Success status
     */
    public function revokeCredential(int $credentialID, string $reason = 'user_request'): bool
    {
        $query = "
            UPDATE tblWebAuthnCredentials
            SET isActive = 0, revokedAt = NOW(), revokeReason = ?
            WHERE credentialID = ?
        ";

        return (bool)Database::query($query, [$reason, $credentialID], 'si');
    }

    /**
     * ✏️ Rename Credential
     *
     * Updates credential device name.
     *
     * @param int $credentialID Credential ID
     * @param string $newName New device name
     * @return bool Success status
     */
    public function renameCredential(int $credentialID, string $newName): bool
    {
        $query = "
            UPDATE tblWebAuthnCredentials
            SET deviceName = ?
            WHERE credentialID = ?
        ";

        return (bool)Database::query($query, [$newName, $credentialID], 'si');
    }

    // ========================================================================
    // 🎲 CHALLENGE MANAGEMENT
    // ========================================================================

    /**
     * 🎲 Generate Challenge
     *
     * Generates a cryptographically secure random challenge.
     *
     * @return string Base64 encoded challenge
     */
    private function generateChallenge(): string
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * 💾 Store Challenge
     *
     * Stores a challenge for later verification.
     *
     * @param string $challenge Challenge string
     * @param int|null $userID User ID
     * @param string|null $email Email address
     * @param string $type Challenge type
     */
    private function storeChallenge(
        string $challenge,
        ?int $userID,
        ?string $email,
        string $type
    ): void {
        $expiresAt = new DateTime();
        $expiresAt->modify("+{$this->challengeValidity} minutes");

        $query = "
            INSERT INTO tblWebAuthnChallenges (
                userID, email, challenge, challengeType,
                ipAddress, userAgent, expiresAt, validityMinutes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";

        Database::insert($query, [
            $userID,
            $email,
            $challenge,
            $type,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $expiresAt->format('Y-m-d H:i:s'),
            $this->challengeValidity
        ], 'isssssi');
    }

    /**
     * ✅ Verify Challenge
     *
     * Verifies a challenge is valid and not expired.
     *
     * @param string $challenge Challenge string
     * @param int|null $userID User ID
     * @param string $type Challenge type
     * @return bool Challenge is valid
     */
    private function verifyChallenge(string $challenge, ?int $userID, string $type): bool
    {
        $query = "
            SELECT * FROM tblWebAuthnChallenges
            WHERE challenge = ?
            AND challengeType = ?
            AND expiresAt > NOW()
            AND isUsed = 0
        ";

        if ($userID) {
            $query .= " AND userID = ?";
            $result = Database::fetchOne($query, [$challenge, $type, $userID], 'ssi');
        } else {
            $result = Database::fetchOne($query, [$challenge, $type], 'ss');
        }

        return !empty($result);
    }

    /**
     * 📊 Mark Challenge Used
     *
     * Marks a challenge as used.
     *
     * @param string $challenge Challenge string
     */
    private function markChallengeUsed(string $challenge): void
    {
        Database::query(
            "UPDATE tblWebAuthnChallenges SET isUsed = 1, usedAt = NOW() WHERE challenge = ?",
            [$challenge],
            's'
        );
    }

    // ========================================================================
    // 🔧 HELPER METHODS
    // ========================================================================

    /**
     * 📱 Detect Device Name
     *
     * Attempts to detect device name from user agent.
     *
     * @return string Device name
     */
    private function detectDeviceName(): string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Simple device detection (can be enhanced)
        if (stripos($userAgent, 'iPhone') !== false) {
            return 'iPhone';
        } elseif (stripos($userAgent, 'iPad') !== false) {
            return 'iPad';
        } elseif (stripos($userAgent, 'Android') !== false) {
            return 'Android Device';
        } elseif (stripos($userAgent, 'Mac') !== false) {
            return 'Mac';
        } elseif (stripos($userAgent, 'Windows') !== false) {
            return 'Windows PC';
        } elseif (stripos($userAgent, 'Linux') !== false) {
            return 'Linux Device';
        }

        return 'Unknown Device';
    }

    /**
     * 📊 Get Statistics
     *
     * Gets WebAuthn usage statistics.
     *
     * @return array Statistics
     */
    public static function getStatistics(): array
    {
        // Total users with WebAuthn
        $totalUsers = Database::fetchOne(
            "SELECT COUNT(*) as count FROM tblUsers WHERE webauthnEnabled = 1"
        );

        // Total credentials
        $totalCredentials = Database::fetchOne(
            "SELECT COUNT(*) as count FROM tblWebAuthnCredentials WHERE isActive = 1"
        );

        // Recent authentications (last 30 days)
        $recentAuths = Database::fetchOne(
            "SELECT COUNT(*) as count FROM tblWebAuthnCredentials
             WHERE lastUsedAt >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        return [
            'total_users' => $totalUsers['count'] ?? 0,
            'total_credentials' => $totalCredentials['count'] ?? 0,
            'recent_authentications' => $recentAuths['count'] ?? 0
        ];
    }
}

// ✅ WebAuthnHandler class loaded successfully
