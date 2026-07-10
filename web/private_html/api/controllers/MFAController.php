<?php
/**
 * ============================================================================
 * 🔐 SIGNula - MFA API Controller
 * ============================================================================
 *
 * Handles Multi-Factor Authentication endpoints.
 *
 * Endpoints:
 * - POST /api/v1/mfa/enable - Enable MFA
 * - POST /api/v1/mfa/disable - Disable MFA
 * - POST /api/v1/mfa/verify - Verify MFA code
 * - GET /api/v1/mfa/setup - Get MFA setup information
 * - GET /api/v1/mfa/backup-codes - Get backup codes
 * - POST /api/v1/mfa/backup-codes/regenerate - Regenerate backup codes
 * - POST /api/v1/mfa/backup-codes/verify - Verify backup code
 *
 * @package    SIGNula
 * @subpackage API\Controllers
 * @version    1.0.0
 * @link       https://SIGNula.id
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * Class MFAController
 *
 * Handles MFA API endpoints
 */
class MFAController extends BaseController
{
    /**
     * ✅ POST /api/v1/mfa/enable
     *
     * Enable two-factor authentication
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function enable(array $params): void
    {
        $user = $this->requireAuth();

        // ✅ Validate input
        $data = $this->validate([
            'password' => 'required',
        ]);

        try {
            // Already enabled
            if ($user['mfaEnabled']) {
                $this->error('MFA is already enabled', Response::HTTP_BAD_REQUEST);
                return;
            }

            // 🔍 Verify password
            if (!password_verify($data['password'], $user['passwordHash'])) {
                $this->error('Password is incorrect', Response::HTTP_UNAUTHORIZED);
                return;
            }

            // 🔑 Generate MFA secret
            $secret = $this->generateMFASecret();

            // 💾 Store MFA secret
            $query = "
                INSERT INTO tblUserMFA (
                    userID, mfaSecret, mfaMethod, createdAt
                ) VALUES (?, ?, 'totp', NOW())
            ";

            Database::query($query, [$user['userID'], $secret], 'is');

            // ✅ Enable MFA for user
            Database::query(
                "UPDATE tblUsers SET mfaEnabled = 1 WHERE userID = ?",
                [$user['userID']],
                'i'
            );

            // 📊 Log activity
            ActivityLogger::log($user['userID'], 'mfa_enabled', 'success', 'MFA enabled via API');

            // 🎫 Generate backup codes
            $backupCodes = $this->generateBackupCodes($user['userID']);

            // 📱 Generate QR code data
            $qrCodeData = $this->generateQRCodeData($user['email'], $secret);

            $this->success([
                'mfa_enabled' => true,
                'secret' => $secret,
                'qr_code_data' => $qrCodeData,
                'backup_codes' => $backupCodes,
                'message' => 'Scan the QR code with your authenticator app',
            ], 'MFA enabled successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to enable MFA', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * ❌ POST /api/v1/mfa/disable
     *
     * Disable two-factor authentication
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function disable(array $params): void
    {
        $user = $this->requireAuth();

        // ✅ Validate input
        $data = $this->validate([
            'password' => 'required',
        ]);

        try {
            // Not enabled
            if (!$user['mfaEnabled']) {
                $this->error('MFA is not enabled', Response::HTTP_BAD_REQUEST);
                return;
            }

            // 🔍 Verify password
            if (!password_verify($data['password'], $user['passwordHash'])) {
                $this->error('Password is incorrect', Response::HTTP_UNAUTHORIZED);
                return;
            }

            // 🗑️ Delete MFA data
            Database::query(
                "DELETE FROM tblUserMFA WHERE userID = ?",
                [$user['userID']],
                'i'
            );

            // 🗑️ Delete backup codes
            Database::query(
                "DELETE FROM tblMFABackupCodes WHERE userID = ?",
                [$user['userID']],
                'i'
            );

            // ❌ Disable MFA for user
            Database::query(
                "UPDATE tblUsers SET mfaEnabled = 0 WHERE userID = ?",
                [$user['userID']],
                'i'
            );

            // 📊 Log activity
            ActivityLogger::log($user['userID'], 'mfa_disabled', 'success', 'MFA disabled via API');

            $this->success([
                'mfa_enabled' => false,
            ], 'MFA disabled successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to disable MFA', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * ✔️ POST /api/v1/mfa/verify
     *
     * Verify MFA code
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function verify(array $params): void
    {
        // ✅ Validate input
        $data = $this->validate([
            'mfa_token' => 'required',
            'code' => 'required|string',
        ]);

        try {
            // Check MFA pending session
            if (!isset($_SESSION['mfa_pending'])) {
                $this->error('No MFA verification pending', Response::HTTP_BAD_REQUEST);
                return;
            }

            $mfaPending = $_SESSION['mfa_pending'];

            // Check token match
            if (!hash_equals($mfaPending['token'], hash('sha256', $data['mfa_token']))) {
                $this->error('Invalid MFA token', Response::HTTP_UNAUTHORIZED);
                return;
            }

            // Check expiration
            if ($mfaPending['expires'] < time()) {
                unset($_SESSION['mfa_pending']);
                $this->error('MFA token expired', Response::HTTP_UNAUTHORIZED);
                return;
            }

            $userId = $mfaPending['user_id'];

            // 🔍 Get MFA secret
            $query = "SELECT mfaSecret FROM tblUserMFA WHERE userID = ?";
            $result = Database::query($query, [$userId], 'i');
            $mfaData = $result ? $result->fetch_assoc() : null;

            if (!$mfaData) {
                $this->error('MFA not configured', Response::HTTP_BAD_REQUEST);
                return;
            }

            // ✅ Verify TOTP code
            $isValid = $this->verifyTOTP($mfaData['mfaSecret'], $data['code']);

            if (!$isValid) {
                // Try backup code
                $isValid = $this->verifyBackupCode($userId, $data['code']);
            }

            if (!$isValid) {
                ActivityLogger::log($userId, 'mfa_failed', 'failed', 'Invalid MFA code');
                $this->error('Invalid MFA code', Response::HTTP_UNAUTHORIZED);
                return;
            }

            // ✅ Complete login
            $user = $this->getUserById($userId);
            $sessionToken = $this->createUserSession($userId);

            // 🧹 Clear MFA pending
            unset($_SESSION['mfa_pending']);

            // 📊 Log successful login
            ActivityLogger::log($userId, 'login', 'success', 'Login with MFA via API');

            $this->success([
                'user_id' => $user['userID'],
                'email' => $user['email'],
                'display_name' => $user['displayName'],
                'session_token' => $sessionToken,
            ], 'MFA verification successful');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('MFA verification failed', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 📋 GET /api/v1/mfa/setup
     *
     * Get MFA setup information
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function getSetup(array $params): void
    {
        $user = $this->requireAuth();

        try {
            $query = "SELECT * FROM tblUserMFA WHERE userID = ?";
            $result = Database::query($query, [$user['userID']], 'i');
            $mfaData = $result ? $result->fetch_assoc() : null;

            if (!$mfaData) {
                $this->success([
                    'mfa_enabled' => false,
                    'setup_required' => true,
                ], 'MFA not configured');
                return;
            }

            // 📱 Generate QR code data
            $qrCodeData = $this->generateQRCodeData($user['email'], $mfaData['mfaSecret']);

            $this->success([
                'mfa_enabled' => true,
                'mfa_method' => $mfaData['mfaMethod'],
                'secret' => $mfaData['mfaSecret'],
                'qr_code_data' => $qrCodeData,
                'backup_codes_count' => $this->getBackupCodesCount($user['userID']),
            ], 'MFA setup information retrieved');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to get MFA setup', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🎫 GET /api/v1/mfa/backup-codes
     *
     * Get backup codes (masked)
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function getBackupCodes(array $params): void
    {
        $user = $this->requireAuth();

        try {
            $count = $this->getBackupCodesCount($user['userID']);

            $this->success([
                'backup_codes_count' => $count,
                'message' => 'Backup codes are encrypted and cannot be retrieved. You can regenerate new codes if needed.',
            ], 'Backup codes information retrieved');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to get backup codes', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🔄 POST /api/v1/mfa/backup-codes/regenerate
     *
     * Regenerate backup codes
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function regenerateBackupCodes(array $params): void
    {
        $user = $this->requireAuth();

        // ✅ Validate input
        $data = $this->validate([
            'password' => 'required',
        ]);

        try {
            // 🔍 Verify password
            if (!password_verify($data['password'], $user['passwordHash'])) {
                $this->error('Password is incorrect', Response::HTTP_UNAUTHORIZED);
                return;
            }

            // MFA must be enabled
            if (!$user['mfaEnabled']) {
                $this->error('MFA is not enabled', Response::HTTP_BAD_REQUEST);
                return;
            }

            // 🗑️ Delete old backup codes
            Database::query(
                "DELETE FROM tblMFABackupCodes WHERE userID = ?",
                [$user['userID']],
                'i'
            );

            // 🎫 Generate new backup codes
            $backupCodes = $this->generateBackupCodes($user['userID']);

            // 📊 Log activity
            ActivityLogger::log($user['userID'], 'backup_codes_regenerated', 'success', 'Backup codes regenerated via API');

            $this->success([
                'backup_codes' => $backupCodes,
                'message' => 'Save these codes in a secure location. They cannot be retrieved again.',
            ], 'Backup codes regenerated successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to regenerate backup codes', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🔑 Generate MFA secret (Base32)
     *
     * @return string MFA secret
     */
    private function generateMFASecret(): string
    {
        $secret = '';
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32 alphabet

        for ($i = 0; $i < 32; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }

        return $secret;
    }

    /**
     * 📱 Generate QR code data (otpauth:// URI)
     *
     * @param string $email User email
     * @param string $secret MFA secret
     * @return string QR code data URI
     */
    private function generateQRCodeData(string $email, string $secret): string
    {
        $issuer = getSetting('app.name', 'SIGNula');
        $label = urlencode($issuer . ':' . $email);

        return "otpauth://totp/$label?secret=$secret&issuer=" . urlencode($issuer);
    }

    /**
     * ✅ Verify TOTP code
     *
     * @param string $secret MFA secret
     * @param string $code User-provided code
     * @return bool True if valid
     */
    private function verifyTOTP(string $secret, string $code): bool
    {
        // Simple TOTP verification (30-second window, +/- 1 period tolerance)
        $timeStep = 30;
        $currentTime = time();

        for ($i = -1; $i <= 1; $i++) {
            $time = floor($currentTime / $timeStep) + $i;
            $hash = hash_hmac('sha1', pack('N*', 0, $time), $this->base32Decode($secret), true);
            $offset = ord($hash[19]) & 0xf;
            $otp = (
                ((ord($hash[$offset]) & 0x7f) << 24) |
                ((ord($hash[$offset + 1]) & 0xff) << 16) |
                ((ord($hash[$offset + 2]) & 0xff) << 8) |
                (ord($hash[$offset + 3]) & 0xff)
            ) % 1000000;

            if (str_pad($otp, 6, '0', STR_PAD_LEFT) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * 🔓 Decode Base32
     *
     * @param string $secret Base32 encoded secret
     * @return string Decoded binary
     */
    private function base32Decode(string $secret): string
    {
        $secret = strtoupper($secret);
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';

        foreach (str_split($secret) as $char) {
            $val = strpos($alphabet, $char);
            if ($val === false) continue;
            $binary .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }

    /**
     * 🎫 Generate backup codes
     *
     * @param int $userId User ID
     * @return array Backup codes (plaintext)
     */
    private function generateBackupCodes(int $userId): array
    {
        $codes = [];

        for ($i = 0; $i < 10; $i++) {
            $code = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(6))), 0, 8);
            $code = strtoupper($code);
            $codes[] = $code;

            // Hash and store
            $codeHash = password_hash($code, PASSWORD_ARGON2ID);

            Database::query(
                "INSERT INTO tblMFABackupCodes (userID, codeHash, createdAt) VALUES (?, ?, NOW())",
                [$userId, $codeHash],
                'is'
            );
        }

        return $codes;
    }

    /**
     * ✅ Verify backup code
     *
     * @param int $userId User ID
     * @param string $code Backup code
     * @return bool True if valid
     */
    private function verifyBackupCode(int $userId, string $code): bool
    {
        $query = "SELECT * FROM tblMFABackupCodes WHERE userID = ? AND usedAt IS NULL";
        $result = Database::query($query, [$userId], 'i');

        if (!$result) {
            return false;
        }

        while ($row = $result->fetch_assoc()) {
            if (password_verify($code, $row['codeHash'])) {
                // Mark code as used
                Database::query(
                    "UPDATE tblMFABackupCodes SET usedAt = NOW() WHERE backupCodeID = ?",
                    [$row['backupCodeID']],
                    'i'
                );

                return true;
            }
        }

        return false;
    }

    /**
     * 🔢 Get backup codes count
     *
     * @param int $userId User ID
     * @return int Unused backup codes count
     */
    private function getBackupCodesCount(int $userId): int
    {
        $query = "SELECT COUNT(*) as count FROM tblMFABackupCodes WHERE userID = ? AND usedAt IS NULL";
        $result = Database::query($query, [$userId], 'i');

        if ($result && $row = $result->fetch_assoc()) {
            return (int) $row['count'];
        }

        return 0;
    }

    /**
     * 🔍 Get user by ID (helper)
     *
     * @param int $userId User ID
     * @return array|null User data
     */
    private function getUserById(int $userId): ?array
    {
        $query = "SELECT * FROM tblUsers WHERE userID = ?";
        $result = Database::query($query, [$userId], 'i');
        return $result ? $result->fetch_assoc() : null;
    }

    /**
     * 🎫 Create user session (helper)
     *
     * @param int $userId User ID
     * @return string Session token
     */
    private function createUserSession(int $userId): string
    {
        $sessionToken = bin2hex(random_bytes(32));
        $lifetime = (int) getSetting('security.session.lifetime', 3600);
        $expiresAt = date('Y-m-d H:i:s', time() + $lifetime);

        $query = "
            INSERT INTO tblUserSessions (
                userID, sessionToken, ipAddress, userAgent, expiresAt, createdAt
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ";

        Database::query(
            $query,
            [
                $userId,
                hash('sha256', $sessionToken),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                $expiresAt
            ],
            'issss'
        );

        $_SESSION['user_id'] = $userId;
        // 🐛 B-065: camelCase alias for the settings/*.php pages that read
        // `$_SESSION['userID']` (see AuthController's establishSession() for the
        // full rationale). Additive — `user_id` is left in place for every
        // existing reader.
        $_SESSION['userID'] = $userId;
        $_SESSION['session_token'] = hash('sha256', $sessionToken);

        return $sessionToken;
    }
}

// ✅ MFAController loaded successfully
