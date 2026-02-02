<?php
/**
 * ============================================================================
 * 🔐 SIGNula - TOTP (Time-based One-Time Password) Implementation
 * ============================================================================
 *
 * Purpose: RFC 6238 compliant TOTP implementation for MFA
 * PHP Version: 8.3+
 *
 * Features:
 * - TOTP secret generation
 * - TOTP code generation and verification
 * - QR code provisioning URI generation
 * - Support for standard authenticator apps (Google Authenticator, Microsoft Authenticator, etc.)
 * - Time window verification for clock drift tolerance
 *
 * Standards:
 * - RFC 6238: TOTP: Time-Based One-Time Password Algorithm
 * - RFC 4226: HOTP: An HMAC-Based One-Time Password Algorithm
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @link       https://signulo.id
 * @see        https://www.rfc-editor.org/rfc/rfc6238
 * @see        https://www.rfc-editor.org/rfc/rfc4226
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🔑 TOTP (Time-based One-Time Password) Class
 *
 * Implements RFC 6238 TOTP algorithm for two-factor authentication.
 * Compatible with standard authenticator apps like Google Authenticator,
 * Microsoft Authenticator, Authy, etc.
 */
class TOTP
{
    /**
     * @var int Time step in seconds (standard is 30 seconds)
     * @see https://www.rfc-editor.org/rfc/rfc6238#section-4
     */
    private const TIME_STEP = 30;

    /**
     * @var int Code length (standard is 6 digits)
     */
    private const CODE_LENGTH = 6;

    /**
     * @var int Number of time windows to check for clock drift (±1 window = ±30 seconds)
     * Allows for 30 seconds of clock drift in either direction
     */
    private const TIME_WINDOW_TOLERANCE = 1;

    /**
     * @var int Secret key length in bytes (160 bits for SHA1)
     * RFC 6238 recommends 160 bits (20 bytes) for SHA1
     */
    private const SECRET_LENGTH = 20;

    /**
     * @var string Hashing algorithm (SHA1 is standard for TOTP)
     */
    private const HASH_ALGORITHM = 'sha1';

    // ========================================================================
    // 🔑 SECRET KEY GENERATION
    // ========================================================================

    /**
     * 🎲 Generate TOTP Secret
     *
     * Generates a cryptographically secure random secret for TOTP.
     * Returns Base32-encoded secret compatible with authenticator apps.
     *
     * @return string Base32-encoded secret key
     * @throws RuntimeException If random bytes generation fails
     *
     * @example
     * ```php
     * $secret = TOTP::generateSecret();
     * // Example output: "JBSWY3DPEHPK3PXP"
     * ```
     */
    public static function generateSecret(): string
    {
        try {
            // 🔐 Generate cryptographically secure random bytes
            // RFC 6238 recommends at least 160 bits (20 bytes) for SHA1
            $randomBytes = random_bytes(self::SECRET_LENGTH);

            // 📝 Encode to Base32 (required for authenticator apps)
            // Most authenticator apps expect Base32-encoded secrets
            $secret = self::base32Encode($randomBytes);

            return $secret;

        } catch (Exception $e) {
            error_log('TOTP secret generation error: ' . $e->getMessage());
            throw new RuntimeException('Failed to generate TOTP secret');
        }
    }

    // ========================================================================
    // 🔢 TOTP CODE GENERATION
    // ========================================================================

    /**
     * 🎫 Generate TOTP Code
     *
     * Generates current TOTP code for given secret.
     * Primarily used for testing/verification purposes.
     *
     * @param string $secret Base32-encoded secret key
     * @param int|null $timestamp Unix timestamp (null = current time)
     * @return string 6-digit TOTP code
     *
     * @example
     * ```php
     * $code = TOTP::generateCode($secret);
     * // Example output: "123456"
     * ```
     */
    public static function generateCode(string $secret, ?int $timestamp = null): string
    {
        // 🕐 Use current time if not specified
        $timestamp = $timestamp ?? time();

        // 📊 Calculate time step counter
        // T = (Current Unix time - T0) / X
        // where T0 = 0 (Unix epoch) and X = 30 seconds
        $timeCounter = (int)floor($timestamp / self::TIME_STEP);

        // 🔢 Generate HOTP code for this time counter
        $code = self::generateHOTP($secret, $timeCounter);

        return $code;
    }

    /**
     * 🔐 Generate HOTP Code (RFC 4226)
     *
     * Generates HMAC-based One-Time Password.
     * TOTP is essentially HOTP where the counter is time-based.
     *
     * @param string $secret Base32-encoded secret key
     * @param int $counter Counter value
     * @return string 6-digit code
     *
     * @see https://www.rfc-editor.org/rfc/rfc4226#section-5
     */
    private static function generateHOTP(string $secret, int $counter): string
    {
        // 📝 Decode Base32 secret to binary
        $secretBinary = self::base32Decode($secret);

        // 🔢 Convert counter to 8-byte big-endian binary string
        // Counter must be 64-bit (8 bytes) in big-endian format
        $counterBinary = pack('J', $counter);

        // 🔐 Generate HMAC-SHA1 hash
        // HMAC provides cryptographic security
        $hash = hash_hmac(self::HASH_ALGORITHM, $counterBinary, $secretBinary, true);

        // ✂️ Dynamic truncation (RFC 4226 Section 5.3)
        // Extract 4 bytes from the hash based on offset
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncatedHash = substr($hash, $offset, 4);

        // 🔢 Convert to integer and apply modulo
        $code = unpack('N', $truncatedHash)[1];
        $code = $code & 0x7FFFFFFF; // Remove sign bit
        $code = $code % pow(10, self::CODE_LENGTH); // Get last 6 digits

        // 📏 Pad with leading zeros to ensure 6 digits
        return str_pad((string)$code, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    // ========================================================================
    // ✅ TOTP CODE VERIFICATION
    // ========================================================================

    /**
     * ✅ Verify TOTP Code
     *
     * Verifies if provided code matches current TOTP code.
     * Includes time window tolerance for clock drift (±30 seconds default).
     *
     * @param string $secret Base32-encoded secret key
     * @param string $code User-provided 6-digit code
     * @param int|null $timestamp Unix timestamp (null = current time)
     * @param int $window Time window tolerance (±windows to check)
     * @return bool True if code is valid
     *
     * @example
     * ```php
     * if (TOTP::verifyCode($secret, $userCode)) {
     *     // Code is valid
     * }
     * ```
     */
    public static function verifyCode(
        string $secret,
        string $code,
        ?int $timestamp = null,
        int $window = self::TIME_WINDOW_TOLERANCE
    ): bool {
        // 🕐 Use current time if not specified
        $timestamp = $timestamp ?? time();

        // 🔢 Remove any spaces or dashes user might have entered
        $code = str_replace([' ', '-'], '', $code);

        // ✅ Validate code format
        if (!preg_match('/^\d{6}$/', $code)) {
            return false; // Code must be exactly 6 digits
        }

        // 🔍 Check current time window and ±N windows for clock drift tolerance
        // This allows for clock differences between server and client device
        // Window of 1 = checks current time, -30s, and +30s
        for ($i = -$window; $i <= $window; $i++) {
            $testTimestamp = $timestamp + ($i * self::TIME_STEP);
            $expectedCode = self::generateCode($secret, $testTimestamp);

            // ⏱️ Timing-safe comparison to prevent timing attacks
            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }

        return false; // Code did not match any time window
    }

    // ========================================================================
    // 📱 QR CODE PROVISIONING URI
    // ========================================================================

    /**
     * 📱 Generate Provisioning URI
     *
     * Generates otpauth:// URI for QR code generation.
     * This URI is used to set up the account in authenticator apps.
     *
     * @param string $secret Base32-encoded secret key
     * @param string $accountName Account identifier (usually email)
     * @param string $issuer Service name (e.g., "SIGNula")
     * @param array $options Additional options (algorithm, digits, period)
     * @return string Provisioning URI
     *
     * @example
     * ```php
     * $uri = TOTP::getProvisioningUri($secret, 'user@example.com', 'SIGNula');
     * // Output: "otpauth://totp/SIGNula:user@example.com?secret=JBSWY3DPEHPK3PXP&issuer=SIGNula"
     * ```
     *
     * @see https://github.com/google/google-authenticator/wiki/Key-Uri-Format
     */
    public static function getProvisioningUri(
        string $secret,
        string $accountName,
        string $issuer = 'SIGNula',
        array $options = []
    ): string {
        // 🔧 Default options
        $algorithm = $options['algorithm'] ?? strtoupper(self::HASH_ALGORITHM);
        $digits = $options['digits'] ?? self::CODE_LENGTH;
        $period = $options['period'] ?? self::TIME_STEP;

        // 🏷️ Build label (Issuer:AccountName format)
        $label = $issuer . ':' . $accountName;

        // 🔗 Build query parameters
        $params = [
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => $algorithm,
            'digits' => $digits,
            'period' => $period,
        ];

        // 📝 Construct otpauth URI
        // Format: otpauth://totp/Label?Parameters
        $uri = 'otpauth://totp/' . rawurlencode($label) . '?' . http_build_query($params);

        return $uri;
    }

    // ========================================================================
    // 🔤 BASE32 ENCODING/DECODING
    // ========================================================================

    /**
     * 📝 Base32 Encode
     *
     * Encodes binary data to Base32 (RFC 4648).
     * Base32 is used because it's case-insensitive and URL-safe.
     *
     * @param string $data Binary data
     * @return string Base32-encoded string
     *
     * @see https://www.rfc-editor.org/rfc/rfc4648#section-6
     */
    private static function base32Encode(string $data): string
    {
        // 🔤 Base32 character set (RFC 4648)
        // A-Z (uppercase) and 2-7 (32 characters total)
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $encoded = '';
        $bits = '';

        // 🔢 Convert each byte to binary string
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        // 📦 Process 5 bits at a time (Base32 uses 5-bit groups)
        foreach (str_split($bits, 5) as $chunk) {
            // Pad last chunk if necessary
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0');
            }

            // Convert binary to decimal and lookup character
            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }

    /**
     * 📝 Base32 Decode
     *
     * Decodes Base32 string to binary data (RFC 4648).
     *
     * @param string $data Base32-encoded string
     * @return string Binary data
     * @throws InvalidArgumentException If invalid Base32 string
     *
     * @see https://www.rfc-editor.org/rfc/rfc4648#section-6
     */
    private static function base32Decode(string $data): string
    {
        // 🔤 Base32 character set
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        // 🧹 Remove spaces and convert to uppercase
        $data = strtoupper(str_replace(' ', '', $data));

        // ✅ Validate Base32 string
        if (!preg_match('/^[A-Z2-7]+=*$/', $data)) {
            throw new InvalidArgumentException('Invalid Base32 string');
        }

        // 🗺️ Create lookup map
        $map = array_flip(str_split($alphabet));
        $decoded = '';
        $bits = '';

        // 🔢 Convert each character to 5-bit binary
        foreach (str_split(rtrim($data, '=')) as $char) {
            if (!isset($map[$char])) {
                throw new InvalidArgumentException('Invalid Base32 character: ' . $char);
            }

            $bits .= str_pad(decbin($map[$char]), 5, '0', STR_PAD_LEFT);
        }

        // 📦 Process 8 bits at a time to get bytes
        foreach (str_split($bits, 8) as $chunk) {
            // Skip incomplete bytes (padding)
            if (strlen($chunk) < 8) {
                continue;
            }

            $decoded .= chr(bindec($chunk));
        }

        return $decoded;
    }

    // ========================================================================
    // 🧪 UTILITY METHODS
    // ========================================================================

    /**
     * ⏱️ Get Current Time Step
     *
     * Returns current time step counter.
     * Useful for debugging and testing.
     *
     * @param int|null $timestamp Unix timestamp (null = current time)
     * @return int Time step counter
     */
    public static function getCurrentTimeStep(?int $timestamp = null): int
    {
        $timestamp = $timestamp ?? time();
        return (int)floor($timestamp / self::TIME_STEP);
    }

    /**
     * ⏰ Get Remaining Time in Current Window
     *
     * Returns seconds remaining in current time window.
     * Useful for showing countdown in UI.
     *
     * @param int|null $timestamp Unix timestamp (null = current time)
     * @return int Seconds remaining (0-29)
     *
     * @example
     * ```php
     * $remaining = TOTP::getRemainingTime();
     * echo "Code expires in {$remaining} seconds";
     * ```
     */
    public static function getRemainingTime(?int $timestamp = null): int
    {
        $timestamp = $timestamp ?? time();
        return self::TIME_STEP - ($timestamp % self::TIME_STEP);
    }

    /**
     * 🔍 Validate Secret Format
     *
     * Validates if secret is properly formatted Base32 string.
     *
     * @param string $secret Secret to validate
     * @return bool True if valid
     */
    public static function validateSecret(string $secret): bool
    {
        try {
            // Try to decode - will throw exception if invalid
            self::base32Decode($secret);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

// ✅ TOTP class loaded successfully
