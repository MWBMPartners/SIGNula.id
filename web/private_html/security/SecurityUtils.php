<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Security Utilities
 * ============================================================================
 *
 * Purpose: Comprehensive security functions for encryption, hashing, and validation
 * PHP Version: 8.3+
 *
 * Features:
 * - AES-256-CBC encryption/decryption
 * - Password hashing with Argon2id
 * - Secure token generation
 * - CSRF token management
 * - Rate limiting
 * - Input validation
 *
 * @package    SIGNula
 * @subpackage Security
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
 * 🛡️ Security Utilities Class
 *
 * Provides comprehensive security functions for the SIGNula system.
 * All methods are static for easy access throughout the application.
 */
class SecurityUtils
{
    /**
     * @var string Encryption algorithm
     * @see https://www.php.net/manual/en/function.openssl-get-cipher-methods.php
     */
    private const CIPHER_METHOD = 'AES-256-CBC';

    /**
     * @var int Token length for secure tokens
     */
    private const TOKEN_LENGTH = 64;

    /**
     * @var int CSRF token length
     */
    private const CSRF_TOKEN_LENGTH = 32;

    // ========================================================================
    // 🔐 ENCRYPTION & DECRYPTION
    // ========================================================================

    /**
     * 🔒 Encrypt Sensitive Data
     *
     * Uses AES-256-CBC encryption with random IV for maximum security.
     * Encrypted data format: base64(IV + encrypted_data)
     *
     * @param string $data Data to encrypt
     * @param string|null $key Encryption key (uses default if null)
     * @return string Encrypted data (base64 encoded)
     * @throws RuntimeException If encryption fails
     *
     * @example
     * ```php
     * $encrypted = SecurityUtils::encrypt('sensitive_data');
     * ```
     */
    public static function encrypt(string $data, ?string $key = null): string
    {
        try {
            // Get encryption key
            $key = $key ?? self::getEncryptionKey();

            // Generate random IV (Initialization Vector)
            $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
            $iv = openssl_random_pseudo_bytes($ivLength);

            // Encrypt data
            $encrypted = openssl_encrypt(
                $data,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($encrypted === false) {
                throw new RuntimeException('Encryption failed');
            }

            // Combine IV and encrypted data, then base64 encode
            return base64_encode($iv . $encrypted);

        } catch (Exception $e) {
            error_log('Encryption error: ' . $e->getMessage());
            throw new RuntimeException('Failed to encrypt data');
        }
    }

    /**
     * 🔓 Decrypt Sensitive Data
     *
     * Decrypts data encrypted with the encrypt() method.
     *
     * @param string $encryptedData Encrypted data (base64 encoded)
     * @param string|null $key Encryption key (uses default if null)
     * @return string Decrypted data
     * @throws RuntimeException If decryption fails
     *
     * @example
     * ```php
     * $decrypted = SecurityUtils::decrypt($encrypted);
     * ```
     */
    public static function decrypt(string $encryptedData, ?string $key = null): string
    {
        try {
            // Get encryption key
            $key = $key ?? self::getEncryptionKey();

            // Decode base64
            $data = base64_decode($encryptedData, true);

            if ($data === false) {
                throw new RuntimeException('Invalid encrypted data format');
            }

            // Extract IV and encrypted data
            $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
            $iv = substr($data, 0, $ivLength);
            $encrypted = substr($data, $ivLength);

            // Decrypt
            $decrypted = openssl_decrypt(
                $encrypted,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($decrypted === false) {
                throw new RuntimeException('Decryption failed');
            }

            return $decrypted;

        } catch (Exception $e) {
            error_log('Decryption error: ' . $e->getMessage());
            throw new RuntimeException('Failed to decrypt data');
        }
    }

    /**
     * 🔑 Get Encryption Key
     *
     * Retrieves encryption key from configuration with fallback to generated key.
     *
     * @return string Encryption key
     * @throws RuntimeException If no encryption key available
     */
    private static function getEncryptionKey(): string
    {
        // Try to get key from auth.php
        if (defined('ENCRYPTION_KEY') && !empty(ENCRYPTION_KEY)) {
            return ENCRYPTION_KEY;
        }

        // Try to get from environment
        $envKey = getenv('ENCRYPTION_KEY');
        if (!empty($envKey)) {
            return $envKey;
        }

        throw new RuntimeException('Encryption key not configured');
    }

    // ========================================================================
    // 🔑 PASSWORD HASHING
    // ========================================================================

    /**
     * 🔒 Hash Password
     *
     * Uses Argon2id algorithm (recommended by OWASP) or bcrypt as fallback.
     * Automatically includes salt and cost factor.
     *
     * @param string $password Plain text password
     * @return string Hashed password
     * @throws RuntimeException If hashing fails
     *
     * @see https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html
     *
     * @example
     * ```php
     * $hash = SecurityUtils::hashPassword('user_password');
     * ```
     */
    public static function hashPassword(string $password): string
    {
        // Use Argon2id if available (PHP 7.3+, recommended)
        if (defined('PASSWORD_ARGON2ID')) {
            $hash = password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,  // 64 MB
                'time_cost'   => 4,      // 4 iterations
                'threads'     => 2       // 2 parallel threads
            ]);
        } else {
            // Fallback to bcrypt
            $hash = password_hash($password, PASSWORD_BCRYPT, [
                'cost' => 12  // Increased cost for better security
            ]);
        }

        if ($hash === false) {
            throw new RuntimeException('Password hashing failed');
        }

        return $hash;
    }

    /**
     * ✅ Verify Password
     *
     * Verifies password against stored hash.
     * Timing-safe comparison prevents timing attacks.
     *
     * @param string $password Plain text password
     * @param string $hash Stored password hash
     * @return bool Verification result
     *
     * @example
     * ```php
     * if (SecurityUtils::verifyPassword($inputPassword, $storedHash)) {
     *     // Password correct
     * }
     * ```
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * 🔄 Check if Password Needs Rehash
     *
     * Checks if password hash needs to be updated due to algorithm or cost changes.
     *
     * @param string $hash Password hash
     * @return bool True if rehash needed
     */
    public static function needsRehash(string $hash): bool
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 2
            ]);
        }

        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    // ========================================================================
    // 🎟️ TOKEN GENERATION
    // ========================================================================

    /**
     * 🎫 Generate Secure Random Token
     *
     * Generates cryptographically secure random token.
     *
     * @param int $length Token length (default: 64)
     * @return string Secure random token (hexadecimal)
     *
     * @example
     * ```php
     * $token = SecurityUtils::generateToken();
     * ```
     */
    public static function generateToken(int $length = self::TOKEN_LENGTH): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * 🔢 Generate Numeric OTP Code
     *
     * Generates random numeric code for OTP/MFA.
     *
     * @param int $length Code length (default: 6)
     * @return string Numeric code
     *
     * @example
     * ```php
     * $otp = SecurityUtils::generateNumericCode(6); // "123456"
     * ```
     */
    public static function generateNumericCode(int $length = 6): string
    {
        $min = pow(10, $length - 1);
        $max = pow(10, $length) - 1;

        return str_pad((string)random_int($min, $max), $length, '0', STR_PAD_LEFT);
    }

    /**
     * 🔤 Generate Alphanumeric Code
     *
     * Generates random alphanumeric code (excludes ambiguous characters).
     *
     * @param int $length Code length (default: 8)
     * @return string Alphanumeric code
     *
     * @example
     * ```php
     * $code = SecurityUtils::generateAlphanumericCode(8); // "A3X9K2P7"
     * ```
     */
    public static function generateAlphanumericCode(int $length = 8): string
    {
        // Exclude ambiguous characters: 0, O, 1, I, l
        $characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $code;
    }

    /**
     * 🎲 Generate Backup Codes
     *
     * Generates array of backup recovery codes.
     *
     * @param int $count Number of codes to generate
     * @param int $length Length of each code
     * @return array Array of backup codes
     *
     * @example
     * ```php
     * $codes = SecurityUtils::generateBackupCodes(10);
     * ```
     */
    public static function generateBackupCodes(int $count = 10, int $length = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = self::generateAlphanumericCode($length);
        }

        return $codes;
    }

    // ========================================================================
    // 🛡️ CSRF PROTECTION
    // ========================================================================

    /**
     * 🎫 Generate CSRF Token
     *
     * Generates and stores CSRF token in session.
     *
     * @return string CSRF token
     *
     * @example
     * ```php
     * $token = SecurityUtils::generateCSRFToken();
     * echo "<input type='hidden' name='csrf_token' value='{$token}'>";
     * ```
     */
    public static function generateCSRFToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = bin2hex(random_bytes(self::CSRF_TOKEN_LENGTH));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();

        return $token;
    }

    /**
     * ✅ Verify CSRF Token
     *
     * Verifies CSRF token from request against session.
     * Uses timing-safe comparison.
     *
     * @param string|null $token Token to verify (from POST/GET)
     * @param int $maxAge Maximum token age in seconds (default: 1 hour)
     * @return bool Verification result
     *
     * @example
     * ```php
     * if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'])) {
     *     die('Invalid CSRF token');
     * }
     * ```
     */
    public static function verifyCSRFToken(?string $token, int $maxAge = 3600): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }

        // Check token age
        if (!empty($_SESSION['csrf_token_time'])) {
            if (time() - $_SESSION['csrf_token_time'] > $maxAge) {
                unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
                return false;
            }
        }

        // Timing-safe comparison
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    // ========================================================================
    // 🔒 PASSWORD VALIDATION
    // ========================================================================

    /**
     * ✅ Validate Password Strength
     *
     * Validates password against security requirements.
     * Requirements loaded from database settings.
     *
     * @param string $password Password to validate
     * @return array Validation result ['valid' => bool, 'errors' => array]
     *
     * @example
     * ```php
     * $validation = SecurityUtils::validatePassword($password);
     * if (!$validation['valid']) {
     *     echo implode(', ', $validation['errors']);
     * }
     * ```
     */
    public static function validatePassword(string $password): array
    {
        $errors = [];

        // Get password requirements from settings
        $minLength = getSetting('security.password.min_length', 12);
        $requireUppercase = getSetting('security.password.require_uppercase', true);
        $requireLowercase = getSetting('security.password.require_lowercase', true);
        $requireNumbers = getSetting('security.password.require_numbers', true);
        $requireSymbols = getSetting('security.password.require_symbols', true);

        // Check minimum length
        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters long";
        }

        // Check uppercase requirement
        if ($requireUppercase && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }

        // Check lowercase requirement
        if ($requireLowercase && !preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }

        // Check numbers requirement
        if ($requireNumbers && !preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }

        // Check symbols requirement
        if ($requireSymbols && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }

        // Check for common passwords (basic check)
        if (self::isCommonPassword($password)) {
            $errors[] = "Password is too common. Please choose a more unique password";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * 🚫 Check if Password is Common
     *
     * Checks password against list of common/weak passwords.
     *
     * @param string $password Password to check
     * @return bool True if password is common
     */
    private static function isCommonPassword(string $password): bool
    {
        // List of most common passwords (expand this list as needed)
        $commonPasswords = [
            'password', 'password123', '123456', '12345678', 'qwerty',
            'abc123', 'monkey', '1234567', 'letmein', 'trustno1',
            'dragon', 'baseball', 'iloveyou', 'master', 'sunshine',
            'ashley', 'bailey', 'passw0rd', 'shadow', '123123',
            '654321', 'superman', 'qazwsx', 'michael', 'football'
        ];

        return in_array(strtolower($password), $commonPasswords, true);
    }

    // ========================================================================
    // 🚦 RATE LIMITING
    // ========================================================================

    /**
     * ⏱️ Check Rate Limit
     *
     * Checks if action is rate limited for given identifier.
     *
     * @param string $identifier Identifier (IP, user ID, etc.)
     * @param string $action Action being rate limited
     * @param int $maxRequests Maximum requests allowed
     * @param int $windowSeconds Time window in seconds
     * @return bool True if within rate limit, false if exceeded
     *
     * @example
     * ```php
     * if (!SecurityUtils::checkRateLimit(getClientIP(), 'login', 5, 300)) {
     *     die('Too many login attempts. Please try again later.');
     * }
     * ```
     */
    public static function checkRateLimit(
        string $identifier,
        string $action,
        int $maxRequests,
        int $windowSeconds
    ): bool {
        try {
            $now = new DateTime();
            $windowStart = (clone $now)->modify("-{$windowSeconds} seconds");

            // 🔧 B-049 FIX — WINDOWED count, not a single row.
            //    updateRateLimit() keys each row on a 1-second `windowStart`
            //    (the UNIQUE key is identifier+identifierType+endpoint+windowStart),
            //    so requests paced > 1/sec each land in a DISTINCT second and mint
            //    a FRESH row with requestCount = 1. The previous single-row read
            //    (SELECT requestCount ... LIMIT 1) therefore NEVER saw more than 1,
            //    letting an attacker sit just under 1 req/sec and spray forever
            //    (cross-account password spraying from one IP) without ever
            //    tripping the limit.
            //
            //    Fix: SUM the counts across ALL rows whose window is still open
            //    within the last `windowSeconds`. This turns the per-second rows
            //    into a proper sliding-ish fixed window: the count now accumulates
            //    across paced requests exactly as it does across bursty ones. The
            //    insert path (updateRateLimit) is unchanged and the SUM is served
            //    by the existing composite index on (identifier, identifierType).
            //
            // 🔧 B-043/B-037: identifierType is ENUM('ip','user','api_key')
            //    (see _database/migrations/007_rate_limiting.sql). The former
            //    literal 'ip_address' was NOT a member of that ENUM, so under
            //    STRICT_ALL_TABLES MySQL truncated it to '' and warned. The
            //    canonical value is 'ip' (matches RateLimiter::checkLimit and
            //    the tblRateLimitConfig seed rows).
            $query = "
                SELECT COALESCE(SUM(requestCount), 0) AS totalCount
                FROM tblRateLimits
                WHERE identifier = ?
                AND identifierType = 'ip'
                AND endpoint = ?
                AND windowStart > ?
            ";

            $result = Database::fetchOne(
                $query,
                [$identifier, $action, $windowStart->format('Y-m-d H:i:s')],
                'sss'
            );

            $currentCount = (int) ($result['totalCount'] ?? 0);
            if ($currentCount >= $maxRequests) {
                return false; // Rate limit exceeded (windowed total already at/over max)
            }

            // Update or insert rate limit record
            self::updateRateLimit($identifier, $action, $windowSeconds);

            return true;

        } catch (Exception $e) {
            error_log('Rate limit check error: ' . $e->getMessage());
            return true; // Allow on error to prevent blocking legitimate users
        }
    }

    /**
     * 📊 Update Rate Limit Counter
     *
     * Updates rate limit counter in database.
     *
     * @param string $identifier Identifier
     * @param string $action Action
     * @param int $windowSeconds Window duration
     */
    private static function updateRateLimit(string $identifier, string $action, int $windowSeconds): void
    {
        try {
            $now = new DateTime();
            $windowEnd = (clone $now)->modify("+{$windowSeconds} seconds");

            // 🔧 B-043/B-037: was 'ip_address' — not a valid ENUM member of
            //    identifierType ENUM('ip','user','api_key'); truncated to ''
            //    under STRICT_ALL_TABLES. Canonical value is 'ip' (kept in sync
            //    with the matching SELECT in checkRateLimit() above).
            $query = "
                INSERT INTO tblRateLimits
                (identifier, identifierType, endpoint, requestCount, windowStart, windowEnd)
                VALUES (?, 'ip', ?, 1, ?, ?)
                ON DUPLICATE KEY UPDATE
                requestCount = requestCount + 1,
                updatedAt = CURRENT_TIMESTAMP
            ";

            Database::query(
                $query,
                [$identifier, $action, $now->format('Y-m-d H:i:s'), $windowEnd->format('Y-m-d H:i:s')],
                'ssss'
            );

        } catch (Exception $e) {
            error_log('Rate limit update error: ' . $e->getMessage());
        }
    }

    // ========================================================================
    // 🧹 INPUT SANITIZATION
    // ========================================================================

    /**
     * 🧼 Sanitize String Input
     *
     * Removes HTML tags and special characters.
     *
     * @param string $input Input string
     * @return string Sanitized string
     */
    public static function sanitizeString(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * 📧 Sanitize Email
     *
     * Sanitizes and validates email address.
     *
     * @param string $email Email address
     * @return string|false Sanitized email or false if invalid
     */
    public static function sanitizeEmail(string $email): string|false
    {
        $email = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * 🔗 Sanitize URL
     *
     * Sanitizes and validates URL.
     *
     * @param string $url URL
     * @return string|false Sanitized URL or false if invalid
     */
    public static function sanitizeURL(string $url): string|false
    {
        $url = filter_var(trim($url), FILTER_SANITIZE_URL);
        return filter_var($url, FILTER_VALIDATE_URL);
    }

    /**
     * 🔢 Sanitize Integer
     *
     * Sanitizes input to integer.
     *
     * @param mixed $input Input value
     * @return int Sanitized integer
     */
    public static function sanitizeInt(mixed $input): int
    {
        return (int)filter_var($input, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * 💯 Sanitize Float
     *
     * Sanitizes input to float.
     *
     * @param mixed $input Input value
     * @return float Sanitized float
     */
    public static function sanitizeFloat(mixed $input): float
    {
        return (float)filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }
}

// ✅ SecurityUtils loaded successfully
