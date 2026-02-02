<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Multi-Factor Authentication (MFA) Manager
 * ============================================================================
 *
 * Purpose: Centralized MFA management for all authentication methods
 * PHP Version: 8.3+
 *
 * Features:
 * - TOTP (Time-based OTP) management
 * - Backup/recovery code generation and verification
 * - Email OTP generation and verification
 * - SMS OTP support (infrastructure ready)
 * - Push notification support (infrastructure ready)
 * - Trusted device management
 * - MFA session handling
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @link       https://signulo.id
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🛡️ Multi-Factor Authentication Class
 *
 * Manages all MFA methods and provides unified interface for
 * authentication across different second-factor methods.
 */
class MFA
{
    /**
     * @var int Backup code count
     */
    private const BACKUP_CODE_COUNT = 10;

    /**
     * @var int Backup code length
     */
    private const BACKUP_CODE_LENGTH = 8;

    /**
     * @var int Email OTP code length
     */
    private const EMAIL_OTP_LENGTH = 6;

    /**
     * @var int Email OTP validity in seconds (default: 15 minutes)
     */
    private const EMAIL_OTP_VALIDITY = 900;

    /**
     * @var int SMS OTP code length
     */
    private const SMS_OTP_LENGTH = 6;

    /**
     * @var int SMS OTP validity in seconds (default: 10 minutes)
     */
    private const SMS_OTP_VALIDITY = 600;

    /**
     * @var int Trusted device cookie duration (default: 30 days)
     */
    private const TRUSTED_DEVICE_DURATION = 2592000;

    // ========================================================================
    // 🎫 TOTP MANAGEMENT
    // ========================================================================

    /**
     * 🔑 Enable TOTP for User
     *
     * Generates TOTP secret and stores in database.
     *
     * @param int $userID User ID
     * @return array Result with secret and provisioning URI
     * @throws RuntimeException If operation fails
     *
     * @example
     * ```php
     * $result = MFA::enableTOTP($userID);
     * echo $result['secret']; // Base32 secret
     * echo $result['qr_uri']; // otpauth:// URI for QR code
     * ```
     */
    public static function enableTOTP(int $userID): array
    {
        try {
            // 🔐 Generate TOTP secret
            $secret = TOTP::generateSecret();

            // 📧 Get user email for provisioning URI
            $user = Database::fetchOne(
                "SELECT email, username FROM tblUsers WHERE userID = ?",
                [$userID],
                'i'
            );

            if (!$user) {
                throw new RuntimeException('User not found');
            }

            // 🔒 Encrypt secret before storing
            $encryptedSecret = SecurityUtils::encrypt($secret);

            // 💾 Store in database (initially unverified)
            $query = "
                INSERT INTO tblUserMFA
                (userID, mfaType, mfaEnabled, mfaSecret, isVerified, createdAt)
                VALUES (?, 'totp', 0, ?, 0, NOW())
                ON DUPLICATE KEY UPDATE
                mfaSecret = ?,
                mfaEnabled = 0,
                isVerified = 0,
                updatedAt = NOW()
            ";

            Database::query(
                $query,
                [$userID, $encryptedSecret, $encryptedSecret],
                'iss'
            );

            // 📱 Generate provisioning URI for QR code
            $accountName = $user['email'];
            $issuer = getSetting('app.name', 'SIGNula');
            $provisioningUri = TOTP::getProvisioningUri($secret, $accountName, $issuer);

            // 📊 Log activity
            ActivityLogger::log($userID, 'mfa_totp_setup_started', 'User started TOTP setup');

            return [
                'success' => true,
                'secret' => $secret,
                'qr_uri' => $provisioningUri,
                'message' => 'TOTP secret generated successfully'
            ];

        } catch (Exception $e) {
            error_log('TOTP enable error: ' . $e->getMessage());
            throw new RuntimeException('Failed to enable TOTP');
        }
    }

    /**
     * ✅ Verify and Activate TOTP
     *
     * Verifies TOTP code and activates MFA for user.
     *
     * @param int $userID User ID
     * @param string $code 6-digit TOTP code
     * @return array Verification result with backup codes
     *
     * @example
     * ```php
     * $result = MFA::verifyAndActivateTOTP($userID, '123456');
     * if ($result['success']) {
     *     // Show backup codes to user
     *     foreach ($result['backup_codes'] as $code) {
     *         echo $code . "\n";
     *     }
     * }
     * ```
     */
    public static function verifyAndActivateTOTP(int $userID, string $code): array
    {
        try {
            // 🔍 Get encrypted secret from database
            $mfaData = Database::fetchOne(
                "SELECT mfaSecret FROM tblUserMFA WHERE userID = ? AND mfaType = 'totp'",
                [$userID],
                'i'
            );

            if (!$mfaData) {
                return ['success' => false, 'message' => 'TOTP not set up for this account'];
            }

            // 🔓 Decrypt secret
            $secret = SecurityUtils::decrypt($mfaData['mfaSecret']);

            // ✅ Verify TOTP code
            if (!TOTP::verifyCode($secret, $code)) {
                // 📊 Log failed verification
                ActivityLogger::log($userID, 'mfa_totp_verification_failed', 'Failed TOTP verification attempt');

                return ['success' => false, 'message' => 'Invalid verification code'];
            }

            // 🎲 Generate backup codes
            $backupCodes = self::generateBackupCodes($userID);

            // ✅ Activate TOTP
            Database::query(
                "UPDATE tblUserMFA SET mfaEnabled = 1, isVerified = 1, updatedAt = NOW() WHERE userID = ? AND mfaType = 'totp'",
                [$userID],
                'i'
            );

            // 📊 Log successful activation
            ActivityLogger::log($userID, 'mfa_totp_activated', 'TOTP authentication activated successfully');

            return [
                'success' => true,
                'message' => 'TOTP activated successfully',
                'backup_codes' => $backupCodes
            ];

        } catch (Exception $e) {
            error_log('TOTP verification error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Verification failed'];
        }
    }

    /**
     * 🔓 Verify TOTP Code (for login)
     *
     * Verifies TOTP code during login process.
     *
     * @param int $userID User ID
     * @param string $code 6-digit TOTP code
     * @return bool True if code is valid
     */
    public static function verifyTOTP(int $userID, string $code): bool
    {
        try {
            // 🔍 Get encrypted secret
            $mfaData = Database::fetchOne(
                "SELECT mfaSecret FROM tblUserMFA WHERE userID = ? AND mfaType = 'totp' AND mfaEnabled = 1",
                [$userID],
                'i'
            );

            if (!$mfaData) {
                return false;
            }

            // 🔓 Decrypt secret
            $secret = SecurityUtils::decrypt($mfaData['mfaSecret']);

            // ✅ Verify code
            $isValid = TOTP::verifyCode($secret, $code);

            // 📊 Log verification attempt
            if ($isValid) {
                ActivityLogger::log($userID, 'mfa_totp_verified', 'TOTP code verified successfully');
            } else {
                ActivityLogger::log($userID, 'mfa_totp_failed', 'Invalid TOTP code entered');
            }

            return $isValid;

        } catch (Exception $e) {
            error_log('TOTP verification error: ' . $e->getMessage());
            return false;
        }
    }

    // ========================================================================
    // 🎲 BACKUP CODES MANAGEMENT
    // ========================================================================

    /**
     * 🎲 Generate Backup Codes
     *
     * Generates and stores backup recovery codes for user.
     * Returns plain codes to display to user (store securely!).
     *
     * @param int $userID User ID
     * @param bool $regenerate Whether to regenerate (invalidates old codes)
     * @return array Array of backup codes
     *
     * @example
     * ```php
     * $codes = MFA::generateBackupCodes($userID);
     * // Display these codes to user ONCE - they cannot be retrieved again
     * ```
     */
    public static function generateBackupCodes(int $userID, bool $regenerate = false): array
    {
        try {
            // 🗑️ Remove old codes if regenerating
            if ($regenerate) {
                Database::query(
                    "DELETE FROM tblUserMFA WHERE userID = ? AND mfaType = 'backup_code'",
                    [$userID],
                    'i'
                );
            }

            // 🎲 Generate backup codes
            $backupCodes = SecurityUtils::generateBackupCodes(
                self::BACKUP_CODE_COUNT,
                self::BACKUP_CODE_LENGTH
            );

            // 💾 Store hashed codes in database
            foreach ($backupCodes as $code) {
                // 🔒 Hash code before storing (one-way hash, cannot retrieve)
                $hashedCode = SecurityUtils::hashPassword($code);

                $query = "
                    INSERT INTO tblUserMFA
                    (userID, mfaType, mfaSecret, mfaEnabled, isVerified, createdAt)
                    VALUES (?, 'backup_code', ?, 1, 1, NOW())
                ";

                Database::query(
                    $query,
                    [$userID, $hashedCode],
                    'is'
                );
            }

            // 📊 Log activity
            $action = $regenerate ? 'mfa_backup_codes_regenerated' : 'mfa_backup_codes_generated';
            ActivityLogger::log($userID, $action, 'Backup codes ' . ($regenerate ? 'regenerated' : 'generated'));

            // ✅ Return plain codes (display to user only once!)
            return $backupCodes;

        } catch (Exception $e) {
            error_log('Backup code generation error: ' . $e->getMessage());
            throw new RuntimeException('Failed to generate backup codes');
        }
    }

    /**
     * ✅ Verify Backup Code
     *
     * Verifies backup code and marks it as used (single-use).
     *
     * @param int $userID User ID
     * @param string $code Backup code
     * @return bool True if code is valid
     */
    public static function verifyBackupCode(int $userID, string $code): bool
    {
        try {
            // 🧹 Clean input
            $code = strtoupper(str_replace([' ', '-'], '', $code));

            // 🔍 Get all unused backup codes for user
            $query = "
                SELECT mfaID, mfaSecret
                FROM tblUserMFA
                WHERE userID = ?
                AND mfaType = 'backup_code'
                AND mfaEnabled = 1
                AND isVerified = 1
            ";

            $codes = Database::fetchAll($query, [$userID], 'i');

            if (empty($codes)) {
                return false;
            }

            // 🔐 Check against each hashed code
            foreach ($codes as $row) {
                if (SecurityUtils::verifyPassword($code, $row['mfaSecret'])) {
                    // ✅ Code is valid - mark as used (disable it)
                    Database::query(
                        "UPDATE tblUserMFA SET mfaEnabled = 0, updatedAt = NOW() WHERE mfaID = ?",
                        [$row['mfaID']],
                        'i'
                    );

                    // 📊 Log usage
                    ActivityLogger::log($userID, 'mfa_backup_code_used', 'Backup code used successfully');

                    // ⚠️ Check remaining codes and warn if low
                    $remaining = Database::fetchOne(
                        "SELECT COUNT(*) as count FROM tblUserMFA WHERE userID = ? AND mfaType = 'backup_code' AND mfaEnabled = 1",
                        [$userID],
                        'i'
                    );

                    if ($remaining['count'] < 3) {
                        // 🚨 Log warning about low backup codes
                        ActivityLogger::log($userID, 'mfa_backup_codes_low', "Only {$remaining['count']} backup codes remaining");
                    }

                    return true;
                }
            }

            // 📊 Log failed attempt
            ActivityLogger::log($userID, 'mfa_backup_code_failed', 'Invalid backup code entered');

            return false;

        } catch (Exception $e) {
            error_log('Backup code verification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 📊 Get Remaining Backup Codes Count
     *
     * Returns count of unused backup codes.
     *
     * @param int $userID User ID
     * @return int Count of unused codes
     */
    public static function getRemainingBackupCodes(int $userID): int
    {
        try {
            $result = Database::fetchOne(
                "SELECT COUNT(*) as count FROM tblUserMFA WHERE userID = ? AND mfaType = 'backup_code' AND mfaEnabled = 1",
                [$userID],
                'i'
            );

            return $result['count'] ?? 0;

        } catch (Exception $e) {
            error_log('Get backup codes count error: ' . $e->getMessage());
            return 0;
        }
    }

    // ========================================================================
    // 📧 EMAIL OTP MANAGEMENT
    // ========================================================================

    /**
     * 📧 Send Email OTP
     *
     * Generates and sends OTP code via email.
     *
     * @param int $userID User ID
     * @return array Result ['success' => bool, 'message' => string]
     */
    public static function sendEmailOTP(int $userID): array
    {
        try {
            // 📧 Get user email
            $user = Database::fetchOne(
                "SELECT email, username FROM tblUsers WHERE userID = ?",
                [$userID],
                'i'
            );

            if (!$user) {
                return ['success' => false, 'message' => 'User not found'];
            }

            // 🔢 Generate numeric OTP
            $otp = SecurityUtils::generateNumericCode(self::EMAIL_OTP_LENGTH);

            // 🔒 Hash OTP before storing
            $hashedOTP = SecurityUtils::hashPassword($otp);

            // 💾 Store OTP in database with expiration
            $expiresAt = new DateTime();
            $expiresAt->modify('+' . self::EMAIL_OTP_VALIDITY . ' seconds');

            $query = "
                INSERT INTO tblVerificationTokens
                (userID, tokenType, token, expiresAt, createdAt)
                VALUES (?, 'email_otp', ?, ?, NOW())
            ";

            Database::query(
                $query,
                [$userID, $hashedOTP, $expiresAt->format('Y-m-d H:i:s')],
                'iss'
            );

            // 📨 Send email
            $emailSent = EmailService::send(
                $user['email'],
                'Your SIGNula Verification Code',
                'email_otp',
                [
                    'username' => $user['username'],
                    'otp_code' => $otp,
                    'validity_minutes' => self::EMAIL_OTP_VALIDITY / 60
                ]
            );

            if (!$emailSent) {
                return ['success' => false, 'message' => 'Failed to send verification email'];
            }

            // 📊 Log activity
            ActivityLogger::log($userID, 'mfa_email_otp_sent', 'Email OTP sent to ' . $user['email']);

            return [
                'success' => true,
                'message' => 'Verification code sent to your email'
            ];

        } catch (Exception $e) {
            error_log('Email OTP send error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to send verification code'];
        }
    }

    /**
     * ✅ Verify Email OTP
     *
     * Verifies email OTP code.
     *
     * @param int $userID User ID
     * @param string $code OTP code
     * @return bool True if code is valid
     */
    public static function verifyEmailOTP(int $userID, string $code): bool
    {
        try {
            // 🧹 Clean input
            $code = str_replace([' ', '-'], '', $code);

            // 🔍 Get recent OTP tokens
            $query = "
                SELECT tokenID, token
                FROM tblVerificationTokens
                WHERE userID = ?
                AND tokenType = 'email_otp'
                AND isUsed = 0
                AND expiresAt > NOW()
                ORDER BY createdAt DESC
                LIMIT 5
            ";

            $tokens = Database::fetchAll($query, [$userID], 'i');

            if (empty($tokens)) {
                ActivityLogger::log($userID, 'mfa_email_otp_failed', 'No valid email OTP found');
                return false;
            }

            // 🔐 Check against each token
            foreach ($tokens as $token) {
                if (SecurityUtils::verifyPassword($code, $token['token'])) {
                    // ✅ Valid - mark as used
                    Database::query(
                        "UPDATE tblVerificationTokens SET isUsed = 1, usedAt = NOW() WHERE tokenID = ?",
                        [$token['tokenID']],
                        'i'
                    );

                    // 🗑️ Invalidate other OTP tokens for this user
                    Database::query(
                        "UPDATE tblVerificationTokens SET isUsed = 1 WHERE userID = ? AND tokenType = 'email_otp' AND tokenID != ?",
                        [$userID, $token['tokenID']],
                        'ii'
                    );

                    // 📊 Log success
                    ActivityLogger::log($userID, 'mfa_email_otp_verified', 'Email OTP verified successfully');

                    return true;
                }
            }

            // 📊 Log failure
            ActivityLogger::log($userID, 'mfa_email_otp_failed', 'Invalid email OTP entered');

            return false;

        } catch (Exception $e) {
            error_log('Email OTP verification error: ' . $e->getMessage());
            return false;
        }
    }

    // ========================================================================
    // 🔍 MFA STATUS & MANAGEMENT
    // ========================================================================

    /**
     * ❓ Check if User Has MFA Enabled
     *
     * Checks if user has any MFA method enabled.
     *
     * @param int $userID User ID
     * @return bool True if MFA is enabled
     */
    public static function isEnabled(int $userID): bool
    {
        try {
            $result = Database::fetchOne(
                "SELECT COUNT(*) as count FROM tblUserMFA WHERE userID = ? AND mfaType != 'backup_code' AND mfaEnabled = 1 AND isVerified = 1",
                [$userID],
                'i'
            );

            return ($result['count'] ?? 0) > 0;

        } catch (Exception $e) {
            error_log('MFA status check error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 📊 Get User MFA Methods
     *
     * Returns list of enabled MFA methods for user.
     *
     * @param int $userID User ID
     * @return array Array of enabled MFA types
     */
    public static function getEnabledMethods(int $userID): array
    {
        try {
            $query = "
                SELECT mfaType
                FROM tblUserMFA
                WHERE userID = ?
                AND mfaType != 'backup_code'
                AND mfaEnabled = 1
                AND isVerified = 1
            ";

            $results = Database::fetchAll($query, [$userID], 'i');

            return array_column($results, 'mfaType');

        } catch (Exception $e) {
            error_log('Get MFA methods error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 🚫 Disable MFA Method
     *
     * Disables specific MFA method for user.
     *
     * @param int $userID User ID
     * @param string $method MFA method type (totp, email, sms, etc.)
     * @return bool True if successful
     */
    public static function disableMethod(int $userID, string $method): bool
    {
        try {
            Database::query(
                "UPDATE tblUserMFA SET mfaEnabled = 0, updatedAt = NOW() WHERE userID = ? AND mfaType = ?",
                [$userID, $method],
                'is'
            );

            // 📊 Log activity
            ActivityLogger::log($userID, 'mfa_method_disabled', "MFA method '{$method}' disabled");

            return true;

        } catch (Exception $e) {
            error_log('MFA disable error: ' . $e->getMessage());
            return false;
        }
    }

    // ========================================================================
    // 🖥️ TRUSTED DEVICES
    // ========================================================================

    /**
     * ✅ Mark Device as Trusted
     *
     * Marks current device as trusted to skip MFA.
     *
     * @param int $userID User ID
     * @param string $deviceFingerprint Unique device identifier
     * @return bool True if successful
     */
    public static function trustDevice(int $userID, string $deviceFingerprint): bool
    {
        try {
            // 🍪 Set secure cookie
            $tokenValue = SecurityUtils::generateToken(64);
            $hashedToken = SecurityUtils::hashPassword($tokenValue);

            // 💾 Store in database
            $expiresAt = new DateTime();
            $expiresAt->modify('+' . self::TRUSTED_DEVICE_DURATION . ' seconds');

            $query = "
                INSERT INTO tblVerificationTokens
                (userID, tokenType, token, expiresAt, createdAt)
                VALUES (?, 'trusted_device', ?, ?, NOW())
            ";

            Database::query(
                $query,
                [$userID, $hashedToken, $expiresAt->format('Y-m-d H:i:s')],
                'iss'
            );

            // 🍪 Set cookie on client
            setcookie(
                'mfa_trust_' . $userID,
                $tokenValue,
                [
                    'expires' => time() + self::TRUSTED_DEVICE_DURATION,
                    'path' => '/',
                    'domain' => '',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]
            );

            // 📊 Log activity
            ActivityLogger::log($userID, 'mfa_device_trusted', 'Device marked as trusted');

            return true;

        } catch (Exception $e) {
            error_log('Trust device error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * ❓ Check if Device is Trusted
     *
     * Checks if current device is trusted for this user.
     *
     * @param int $userID User ID
     * @return bool True if device is trusted
     */
    public static function isDeviceTrusted(int $userID): bool
    {
        try {
            // 🍪 Check for trust cookie
            $cookieName = 'mfa_trust_' . $userID;

            if (empty($_COOKIE[$cookieName])) {
                return false;
            }

            $tokenValue = $_COOKIE[$cookieName];

            // 🔍 Get tokens from database
            $query = "
                SELECT token
                FROM tblVerificationTokens
                WHERE userID = ?
                AND tokenType = 'trusted_device'
                AND isUsed = 0
                AND expiresAt > NOW()
            ";

            $tokens = Database::fetchAll($query, [$userID], 'i');

            // 🔐 Check against stored hashes
            foreach ($tokens as $row) {
                if (SecurityUtils::verifyPassword($tokenValue, $row['token'])) {
                    return true;
                }
            }

            return false;

        } catch (Exception $e) {
            error_log('Trust device check error: ' . $e->getMessage());
            return false;
        }
    }
}

// ✅ MFA class loaded successfully
