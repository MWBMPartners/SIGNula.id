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
     * @var bool Whether the RP requires user verification (UV flag).
     *
     * Registration options request userVerification:"preferred", so by default
     * we do NOT hard-fail when UV is absent (some roaming keys only do user
     * presence). A deployment can force UV via auth.webauthn.require_uv = true.
     */
    private bool $requireUserVerification = false;

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        $this->rpName = getSetting('auth.webauthn.rp_name', 'SIGNula');
        $this->rpId = getSetting('auth.webauthn.rp_id', 'signula.id');
        $this->rpOrigin = getSetting('site.url', 'https://signula.id');
        $this->challengeValidity = (int)getSetting('auth.webauthn.challenge_validity', 5);
        $this->requireUserVerification = (bool)getSetting('auth.webauthn.require_uv', false);
    }

    /**
     * 📦 Load the self-hosted CBOR/COSE helper.
     *
     * No Composer in production (Dreamhost shared) → the helper is vendored
     * under web/_lib/webauthn/ and loaded with require_once + existence check.
     * From web/private_html/auth/ the project's web/ root is dirname(__DIR__, 2).
     *
     * @return void
     * @throws RuntimeException If the helper cannot be located/loaded.
     */
    private function loadCborHelper(): void
    {
        if (class_exists('WebAuthnCbor')) {
            return; // ✅ Already loaded.
        }

        $cborPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . '_lib' . DIRECTORY_SEPARATOR
            . 'webauthn' . DIRECTORY_SEPARATOR
            . 'WebAuthnCbor.php';

        if (!is_file($cborPath)) {
            throw new RuntimeException('WebAuthn CBOR helper not found: ' . $cborPath);
        }

        require_once $cborPath;

        if (!class_exists('WebAuthnCbor')) {
            throw new RuntimeException('WebAuthn CBOR helper failed to load');
        }
    }

    /**
     * 🔓 Tolerant base64 / base64url decoder for client-supplied blobs.
     *
     * The browser sends most response fields as standard base64 (btoa), but
     * credential IDs (assertion.id) are base64url. Decode either form strictly
     * (reject anything that is not valid base64), returning the raw bytes.
     *
     * @param string $input base64 or base64url string.
     * @return string Decoded raw bytes.
     * @throws RuntimeException On invalid encoding.
     */
    private function b64decode(string $input): string
    {
        // Normalise base64url → base64 and restore padding.
        $normalised = strtr($input, '-_', '+/');
        $remainder  = strlen($normalised) % 4;
        if ($remainder !== 0) {
            $normalised .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($normalised, true); // strict mode
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64 in credential data');
        }

        return $decoded;
    }

    /**
     * 🌐 Build the set of acceptable WebAuthn origins.
     *
     * The configured site.url is the primary origin; an optional
     * auth.webauthn.allowed_origins setting (JSON array or comma list) extends
     * it for multi-subdomain / app deployments. All entries are normalised by
     * trimming a trailing slash so comparison is exact.
     *
     * @return array<string> Allowlisted origins (no trailing slash).
     */
    private function allowedOrigins(): array
    {
        $origins = [rtrim($this->rpOrigin, '/')];

        $extra = getSetting('auth.webauthn.allowed_origins', null);
        if (is_string($extra) && $extra !== '') {
            $decoded = json_decode($extra, true);
            $list    = is_array($decoded) ? $decoded : explode(',', $extra);
            foreach ($list as $origin) {
                $origin = rtrim(trim((string)$origin), '/');
                if ($origin !== '') {
                    $origins[] = $origin;
                }
            }
        } elseif (is_array($extra)) {
            foreach ($extra as $origin) {
                $origin = rtrim(trim((string)$origin), '/');
                if ($origin !== '') {
                    $origins[] = $origin;
                }
            }
        }

        return array_values(array_unique($origins));
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

            // ✅ Verify clientDataJSON presence
            if (empty($response['clientDataJSON'])) {
                throw new RuntimeException('Missing client data');
            }
            if (empty($response['attestationObject'])) {
                throw new RuntimeException('Missing attestation object');
            }

            // 🔓 W3C §7.1 step 5-6: decode + parse clientDataJSON.
            $clientDataJSON = $this->b64decode($response['clientDataJSON']);
            $clientData     = json_decode($clientDataJSON, true);
            if (!is_array($clientData)
                || !isset($clientData['type'], $clientData['challenge'], $clientData['origin'])) {
                throw new RuntimeException('Malformed client data');
            }

            // ✅ §7.1 step 7: ceremony type must be "webauthn.create".
            if ($clientData['type'] !== 'webauthn.create') {
                throw new RuntimeException('Invalid ceremony type');
            }

            // ✅ §7.1 step 8: challenge must equal the stored, unused challenge.
            if (!$this->verifyChallenge($clientData['challenge'], $userID, 'registration')) {
                throw new RuntimeException('Invalid or expired challenge');
            }

            // ✅ §7.1 step 9: origin must be in the RP allowlist.
            if (!in_array($clientData['origin'], $this->allowedOrigins(), true)) {
                throw new RuntimeException('Origin mismatch');
            }

            // 📦 §7.1 step 11-12: CBOR-decode the attestation object → authData.
            $this->loadCborHelper();
            $attestationObject = $this->b64decode($response['attestationObject']);
            $attestation       = WebAuthnCbor::decode($attestationObject);

            if (!is_array($attestation) || !isset($attestation['authData'])
                || !is_string($attestation['authData'])) {
                throw new RuntimeException('Malformed attestation object');
            }

            $attestationFmt = is_string($attestation['fmt'] ?? null) ? $attestation['fmt'] : 'none';

            // 🔬 Parse authenticatorData and the attested credential (incl. COSE key).
            $parsed = $this->parseAuthenticatorData($attestation['authData'], true);

            // ✅ §7.1 step 13: rpIdHash must equal SHA-256(rpId).
            if (!hash_equals(hash('sha256', $this->rpId, true), $parsed['rpIdHash'])) {
                throw new RuntimeException('RP ID hash mismatch');
            }

            // ✅ §7.1 step 14: User Present (UP) flag MUST be set.
            if (!$parsed['userPresent']) {
                throw new RuntimeException('User presence flag not set');
            }

            // ✅ §7.1 step 15: User Verified (UV) flag, only if the RP requires it.
            if ($this->requireUserVerification && !$parsed['userVerified']) {
                throw new RuntimeException('User verification required but not performed');
            }

            // 🔑 §7.1 step 16-21: attested credential data must be present, and
            //    its COSE public key must convert to a usable PEM key.
            if (empty($parsed['credentialId']) || empty($parsed['coseKey'])) {
                throw new RuntimeException('Attested credential data missing');
            }

            $pubKey = WebAuthnCbor::coseKeyToPem($parsed['coseKey']);
            if (empty($pubKey['pem']) || openssl_pkey_get_public($pubKey['pem']) === false) {
                throw new RuntimeException('Unsupported or invalid credential public key');
            }

            // 🔐 The credential id the client reports must match the one inside
            //    the (authenticator-signed) attestedCredentialData. Compare on
            //    raw bytes to defeat encoding tricks.
            $reportedCredId = $this->b64decode($credentialData['id']);
            if (!hash_equals($parsed['credentialId'], $reportedCredId)) {
                throw new RuntimeException('Credential ID mismatch');
            }

            // 💾 Store the PEM public key + initial sign-count for later
            //    cryptographic assertion verification (NOT the raw blob).
            $credentialID = $this->storeCredential(
                userID: $userID,
                credentialPublicKeyID: $credentialData['id'],
                credentialPublicKey: $pubKey['pem'],
                attestationType: $attestationFmt,
                deviceName: $deviceName,
                signCount: $parsed['signCount'],
                aaguid: $parsed['aaguid'] ?? null,
                coseAlg: $pubKey['alg']
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

            // ✅ clientDataJSON must be present to begin verification.
            if (empty($response['clientDataJSON'])) {
                throw new RuntimeException('Missing client data');
            }

            // 🔓 §7.2 step 8-9: decode + parse clientDataJSON.
            $clientDataJSON = $this->b64decode($response['clientDataJSON']);
            $clientData     = json_decode($clientDataJSON, true);
            if (!is_array($clientData)
                || !isset($clientData['type'], $clientData['challenge'], $clientData['origin'])) {
                throw new RuntimeException('Malformed client data');
            }

            // ✅ §7.2 step 11: ceremony type must be "webauthn.get".
            if ($clientData['type'] !== 'webauthn.get') {
                throw new RuntimeException('Invalid ceremony type');
            }

            // ✅ §7.2 step 12: challenge must equal the stored, unused challenge.
            if (!$this->verifyChallenge($clientData['challenge'], $credential['userID'], 'authentication')) {
                throw new RuntimeException('Invalid or expired challenge');
            }

            // ✅ §7.2 step 13: origin must be in the RP allowlist.
            if (!in_array($clientData['origin'], $this->allowedOrigins(), true)) {
                throw new RuntimeException('Origin mismatch');
            }

            // 🚨 FG-013 CORE: authenticatorData AND signature MUST both be
            //    present. An assertion lacking either can never be verified and
            //    is rejected here, BEFORE any session is granted. (Checked after
            //    the clientData binding checks per W3C §7.2 step ordering.)
            if (empty($response['authenticatorData'])) {
                throw new RuntimeException('Missing authenticator data');
            }
            if (empty($response['signature'])) {
                throw new RuntimeException('Missing assertion signature');
            }

            // 🔬 §7.2 step 14-17: parse authenticatorData, verify rpIdHash + UP/UV.
            $authData = $this->b64decode($response['authenticatorData']);
            $parsed   = $this->parseAuthenticatorData($authData, false);

            if (!hash_equals(hash('sha256', $this->rpId, true), $parsed['rpIdHash'])) {
                throw new RuntimeException('RP ID hash mismatch');
            }
            if (!$parsed['userPresent']) {
                throw new RuntimeException('User presence flag not set');
            }
            if ($this->requireUserVerification && !$parsed['userVerified']) {
                throw new RuntimeException('User verification required but not performed');
            }

            // 🔐 §7.2 step 19-20: cryptographically verify the assertion signature.
            //    signedData = authenticatorData || SHA-256(clientDataJSON)
            //    Verified with the STORED public key (PEM) via openssl_verify.
            $signature = $this->b64decode($response['signature']);
            $publicKey = (string)($credential['credentialPublicKey'] ?? '');

            if (!$this->verifyAssertionSignature($authData, $clientDataJSON, $signature, $publicKey)) {
                throw new RuntimeException('Assertion signature verification failed');
            }

            // 🛡️ §7.2 step 21: sign-count clone detection.
            //    If either the received or stored counter is non-zero, the
            //    received counter MUST be strictly greater than the stored one.
            //    If both are zero some authenticators simply don't increment —
            //    that is permitted.
            $storedCount   = (int)($credential['signCount'] ?? 0);
            $receivedCount = (int)$parsed['signCount'];

            if ($storedCount > 0 || $receivedCount > 0) {
                if ($receivedCount <= $storedCount) {
                    // 📝 Possible cloned authenticator — log + reject.
                    //    ActivityLogger::log() signature:
                    //    (userID, activityType, category, severity, description, metadata[]).
                    ActivityLogger::log(
                        (int)$credential['userID'],
                        'login',
                        'authentication',
                        'high',
                        'WebAuthn sign-count regression (possible cloned authenticator)',
                        [
                            'result'        => 'failure',
                            'method'        => 'webauthn',
                            'credential_id' => $credential['credentialID'],
                            'stored_count'  => $storedCount,
                            'received_count' => $receivedCount,
                        ]
                    );
                    throw new RuntimeException('Sign count regression detected');
                }
            }

            // 📊 Update credential usage AND persist the new sign-count.
            Database::query(
                "UPDATE tblWebAuthnCredentials
                 SET lastUsedAt = NOW(), usageCount = usageCount + 1, signCount = ?
                 WHERE credentialID = ?",
                [$receivedCount, $credential['credentialID']],
                'ii'
            );

            // 📊 Mark challenge as used
            $this->markChallengeUsed($clientData['challenge']);

            // 📝 Log authentication (ActivityLogger::log signature:
            //    userID, activityType, category, severity, description, metadata[]).
            ActivityLogger::log(
                (int)$credential['userID'],
                'login',
                'authentication',
                'info',
                'WebAuthn/PassKey authentication',
                [
                    'result'        => 'success',
                    'method'        => 'webauthn',
                    'credential_id' => $credential['credentialID'],
                    'device_name'   => $credential['deviceName'],
                ]
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
     * @param int         $userID                User ID
     * @param string      $credentialPublicKeyID Credential public key ID (client form)
     * @param string      $credentialPublicKey   PEM-encoded public key for verification
     * @param string|null $attestationType       Attestation format/type
     * @param string|null $deviceName            Device name
     * @param int         $signCount             Initial signature counter from authData
     * @param string|null $aaguid                Authenticator AAGUID (hex), if present
     * @param int|null    $coseAlg               COSE algorithm id (e.g. -7, -257) — informational
     * @return int Credential ID
     */
    private function storeCredential(
        int $userID,
        string $credentialPublicKeyID,
        string $credentialPublicKey,
        ?string $attestationType = null,
        ?string $deviceName = null,
        int $signCount = 0,
        ?string $aaguid = null,
        ?int $coseAlg = null
    ): int {
        // ℹ️ $coseAlg is retained for clarity / future auditing; the stored PEM
        //    is self-describing for openssl_verify, so it is not persisted to a
        //    dedicated column (none exists in tblWebAuthnCredentials).
        unset($coseAlg);

        $query = "
            INSERT INTO tblWebAuthnCredentials (
                userID, credentialPublicKeyID, credentialPublicKey,
                attestationType, aaguid, signCount, deviceName, userAgent, createdIP
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $credentialID = Database::insert($query, [
            $userID,
            $credentialPublicKeyID,
            $credentialPublicKey,
            $attestationType,
            $aaguid,
            $signCount,
            $deviceName ?? $this->detectDeviceName(),
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null
        ], 'issssisss');

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
        ], 'issssssi'); // 🔧 8 binds: i,s,s,s,s,s,s,i (was 'isssssi' — one 's' short)
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
    // 🔬 CRYPTOGRAPHIC VERIFICATION (pure — no DB, unit-testable)
    // ========================================================================

    /**
     * 🔬 Parse WebAuthn authenticatorData.
     *
     * Layout (W3C WebAuthn L2 §6.1):
     *   rpIdHash      32 bytes  (SHA-256 of the RP ID)
     *   flags          1 byte   (bit0 UP, bit2 UV, bit6 AT, bit7 ED)
     *   signCount      4 bytes  (uint32, big-endian)
     *   [attestedCredentialData]  — present iff AT flag set:
     *       aaguid              16 bytes
     *       credentialIdLength   2 bytes (uint16 BE)
     *       credentialId         L bytes
     *       credentialPublicKey  CBOR COSE_Key (variable)
     *   [extensions]  — present iff ED flag set (CBOR; ignored here)
     *
     * @param string $authData            Raw authenticatorData bytes.
     * @param bool   $expectAttestedData  Require attestedCredentialData (registration).
     * @return array{
     *     rpIdHash:string, userPresent:bool, userVerified:bool, signCount:int,
     *     aaguid:?string, credentialId:?string, coseKey:?array
     * }
     * @throws RuntimeException On a malformed / too-short structure.
     */
    public function parseAuthenticatorData(string $authData, bool $expectAttestedData): array
    {
        // 🔒 Minimum length: 32 (rpIdHash) + 1 (flags) + 4 (signCount) = 37.
        if (strlen($authData) < 37) {
            throw new RuntimeException('authenticatorData too short');
        }

        $rpIdHash = substr($authData, 0, 32);
        $flags    = ord($authData[32]);
        $signCount = unpack('N', substr($authData, 33, 4))[1];

        $userPresent  = (bool)($flags & 0x01); // bit 0 — UP
        $userVerified = (bool)($flags & 0x04); // bit 2 — UV
        $attestedData = (bool)($flags & 0x40); // bit 6 — AT

        $result = [
            'rpIdHash'     => $rpIdHash,
            'userPresent'  => $userPresent,
            'userVerified' => $userVerified,
            'signCount'    => (int)$signCount,
            'aaguid'       => null,
            'credentialId' => null,
            'coseKey'      => null,
        ];

        // 🔑 Attested credential data (only present on registration / AT flag).
        if ($attestedData) {
            $offset = 37;

            // aaguid (16 bytes)
            if (strlen($authData) < $offset + 16 + 2) {
                throw new RuntimeException('authenticatorData truncated (attested credential header)');
            }
            $aaguidRaw = substr($authData, $offset, 16);
            $offset   += 16;

            // credentialIdLength (uint16 BE)
            $credIdLen = unpack('n', substr($authData, $offset, 2))[1];
            $offset   += 2;

            // credentialId (credIdLen bytes)
            if (strlen($authData) < $offset + $credIdLen) {
                throw new RuntimeException('authenticatorData truncated (credentialId)');
            }
            $credentialId = substr($authData, $offset, $credIdLen);
            $offset      += $credIdLen;

            // credentialPublicKey — the remaining bytes begin a CBOR COSE_Key.
            $this->loadCborHelper();
            $remaining = substr($authData, $offset);
            $consumed  = 0;
            $coseKey   = WebAuthnCbor::decodeFirst($remaining, $consumed);

            if (!is_array($coseKey)) {
                throw new RuntimeException('Invalid COSE public key in authenticatorData');
            }

            $result['aaguid']       = bin2hex($aaguidRaw);
            $result['credentialId'] = $credentialId;
            $result['coseKey']      = $coseKey;
        } elseif ($expectAttestedData) {
            // Registration REQUIRES attested credential data.
            throw new RuntimeException('Attested credential data flag (AT) not set');
        }

        return $result;
    }

    /**
     * 🔐 Verify a WebAuthn assertion signature.
     *
     * Computes signedData = authenticatorData || SHA-256(clientDataJSON) and
     * verifies $signature over it with the stored PEM public key using
     * openssl_verify() with SHA-256.
     *
     *   - ES256: the authenticator emits an ASN.1-DER ECDSA signature, which
     *     openssl_verify() accepts directly with an EC PEM key.
     *   - RS256: a raw PKCS#1 v1.5 signature, accepted with an RSA PEM key.
     *
     * Only openssl primitives are used — no hand-rolled EC/RSA math.
     *
     * @param string $authData       Raw authenticatorData bytes.
     * @param string $clientDataJSON Raw clientDataJSON bytes (NOT base64).
     * @param string $signature      Raw signature bytes.
     * @param string $publicKeyPem   Stored PEM public key.
     * @return bool True ONLY when openssl_verify() returns exactly 1.
     */
    public function verifyAssertionSignature(
        string $authData,
        string $clientDataJSON,
        string $signature,
        string $publicKeyPem
    ): bool {
        // 🚫 Empty signature can never verify — reject up front.
        if ($signature === '' || $publicKeyPem === '') {
            return false;
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            return false;
        }

        // 🧮 signedData per W3C §7.2 step 20.
        $signedData = $authData . hash('sha256', $clientDataJSON, true);

        $verifyResult = openssl_verify(
            $signedData,
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256
        );

        // openssl_verify(): 1 = valid, 0 = invalid, -1 = error. Accept ONLY 1.
        return $verifyResult === 1;
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
