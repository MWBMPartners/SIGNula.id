<?php
/**
 * ============================================================================
 * 🔑 SIGNula - Session Guard (Fingerprinting)
 * ============================================================================
 *
 * Purpose: Session fingerprinting to detect and prevent session hijacking
 * PHP Version: 8.3+
 *
 * Generates a composite fingerprint from the client's IP address, User-Agent,
 * Accept-Language, Accept-Encoding headers and a server-side salt. The
 * fingerprint is stored in the session and validated on every subsequent
 * request. If a mismatch is detected the configured action (invalidate,
 * warn, or log) is taken, protecting against session fixation and hijacking
 * attacks.
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.6.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🛡️ Session Guard Class
 *
 * Provides session fingerprinting to detect session hijacking attempts.
 * All methods are static for easy access throughout the application.
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html
 */
class SessionGuard
{
    /**
     * @var string|null Cached salt value for the current request lifetime
     */
    private static ?string $cachedSalt = null;

    // ========================================================================
    // 🔧 CONFIGURATION
    // ========================================================================

    /**
     * ✅ Check if Session Fingerprinting is Enabled
     *
     * Reads the 'security.session_fingerprinting.enabled' setting from the
     * database via getSetting(). Defaults to true if not configured.
     *
     * @return bool True if fingerprinting is enabled
     * @see https://www.php.net/manual/en/function.filter-var.php
     */
    public static function isEnabled(): bool
    {
        return (bool) getSetting('security.session_fingerprinting.enabled', true);
    }

    // ========================================================================
    // 🚀 INITIALIZATION & LIFECYCLE
    // ========================================================================

    /**
     * 🏁 Initialize Session Fingerprinting
     *
     * Called during session startup from config.php's initializeSession().
     * Creates a new fingerprint if none exists, or validates the existing one.
     * Safe to call multiple times within the same request.
     *
     * @return void
     */
    public static function initialize(): void
    {
        // 🔇 Skip entirely if fingerprinting is disabled
        if (!self::isEnabled()) {
            return;
        }

        // 🆕 No fingerprint yet — first request in this session
        if (!isset($_SESSION['session_fingerprint'])) {
            self::createFingerprint();
            return;
        }

        // 🔍 Fingerprint exists — validate it against current request data
        if (!self::validate()) {
            self::handleMismatch();
        }
    }

    // ========================================================================
    // 🔐 FINGERPRINT CREATION
    // ========================================================================

    /**
     * 🏗️ Create Session Fingerprint
     *
     * Generates a SHA-256 hash from the client's IP component, User-Agent,
     * Accept-Language, Accept-Encoding and a server-side salt. Stores the
     * hash and debug metadata in the session.
     *
     * @return string The generated fingerprint hash (64-char hex string)
     * @see https://www.php.net/manual/en/function.hash.php
     */
    public static function createFingerprint(): string
    {
        // 📡 Gather the IP component based on the configured ip_match mode
        $ipMode = getSetting('security.session_fingerprinting.ip_match', 'subnet');
        $clientIP = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $ipComponent = self::getIPComponent($clientIP);

        // 🌐 Gather browser headers (empty string fallback for each)
        $userAgent      = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';

        // 🧂 Get the server-side salt (auto-generated if empty)
        $salt = self::getSalt();

        // 🔒 Build the composite fingerprint string and hash it
        $rawFingerprint = implode('|', [
            $ipComponent,
            $userAgent,
            $acceptLanguage,
            $acceptEncoding,
            $salt
        ]);

        $hash = hash('sha256', $rawFingerprint);

        // 💾 Store fingerprint and debug metadata in the session
        $_SESSION['session_fingerprint'] = $hash;

        $_SESSION['session_fp_components'] = [
            'ip_mode'          => $ipMode,
            'ip_present'       => ($ipComponent !== ''),
            'ua_present'       => ($userAgent !== ''),
            'accept_lang'      => ($acceptLanguage !== ''),
            'accept_encoding'  => ($acceptEncoding !== ''),
        ];

        $_SESSION['session_fp_created'] = time();

        return $hash;
    }

    // ========================================================================
    // ✅ VALIDATION
    // ========================================================================

    /**
     * 🔍 Validate Session Fingerprint
     *
     * Generates a fresh fingerprint from the current request data and
     * compares it against the stored session fingerprint using a
     * timing-safe comparison to prevent timing attacks.
     *
     * @return bool True if the fingerprints match, false otherwise
     * @see https://www.php.net/manual/en/function.hash-equals.php
     */
    public static function validate(): bool
    {
        // 🛑 Nothing to validate if no fingerprint is stored
        if (!isset($_SESSION['session_fingerprint'])) {
            return false;
        }

        // 📡 Regenerate the fingerprint from current request data
        $clientIP = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $ipComponent = self::getIPComponent($clientIP);

        $userAgent      = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';

        $salt = self::getSalt();

        $rawFingerprint = implode('|', [
            $ipComponent,
            $userAgent,
            $acceptLanguage,
            $acceptEncoding,
            $salt
        ]);

        $currentHash = hash('sha256', $rawFingerprint);

        // 🔐 Timing-safe comparison to prevent side-channel attacks
        return hash_equals($_SESSION['session_fingerprint'], $currentHash);
    }

    // ========================================================================
    // ⚠️ MISMATCH HANDLING
    // ========================================================================

    /**
     * 🚨 Handle Fingerprint Mismatch
     *
     * Takes the configured action when a session fingerprint mismatch is
     * detected. Possible actions:
     *   - 'invalidate': destroy session and force re-authentication
     *   - 'warn':       log the event but keep the session alive
     *   - 'log':        silently log the event only
     *
     * In all cases, attempts to create a SecurityAlert if the
     * SecurityAlertManager class is available.
     *
     * @return void
     */
    public static function handleMismatch(): void
    {
        $action = getSetting('security.session_fingerprinting.on_mismatch', 'invalidate');

        // 📊 Prepare metadata for logging
        $clientIP = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $metadata = [
            'action_taken'     => $action,
            'ip_address'       => $clientIP,
            'user_agent'       => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'fp_created'       => $_SESSION['session_fp_created'] ?? null,
            'fp_components'    => $_SESSION['session_fp_components'] ?? [],
            'user_id'          => $_SESSION['user_id'] ?? null,
        ];

        // 📝 Log via ActivityLogger (all actions log)
        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                $_SESSION['user_id'] ?? null,
                'session_fingerprint_mismatch',
                'security',
                'warning',
                'Session fingerprint mismatch detected — action: ' . $action,
                $metadata
            );
        }

        // 🚨 Create a SecurityAlert if SecurityAlertManager is available
        if (class_exists('SecurityAlertManager')) {
            SecurityAlertManager::create(
                SecurityAlertManager::TYPE_SESSION_HIJACK,
                SecurityAlertManager::SEVERITY_HIGH,
                'Session fingerprint mismatch detected',
                $metadata,
                $_SESSION['user_id'] ?? null,
                $clientIP
            );
        }

        // 🔀 Branch on configured action
        if ($action === 'invalidate') {
            // 💣 Destroy the session completely and start a fresh one
            session_unset();
            session_destroy();
            session_start();
            session_regenerate_id(true);

            // 💬 Set flash error message for the sign-in page
            $_SESSION['flash_error'] = 'Your session has expired for security reasons. Please sign in again.';

        } elseif ($action === 'warn') {
            // ⚠️ Warn mode: log already happened above, nothing else to do
            // Session is kept alive intentionally
        } else {
            // 📋 Log-only mode: no further action required
        }
    }

    // ========================================================================
    // 🔄 REFRESH
    // ========================================================================

    /**
     * 🔄 Refresh Session Fingerprint
     *
     * Regenerates and stores a new fingerprint without validation.
     * Use after legitimate changes such as MFA verification where
     * the client IP might change, or when the user explicitly
     * changes settings.
     *
     * @return void
     */
    public static function refreshFingerprint(): void
    {
        // 🔇 Skip if fingerprinting is disabled
        if (!self::isEnabled()) {
            return;
        }

        self::createFingerprint();
    }

    // ========================================================================
    // 🔒 PRIVATE HELPERS
    // ========================================================================

    /**
     * 🌐 Get IP Component Based on Match Mode
     *
     * Returns the appropriate IP component string depending on the
     * configured ip_match setting ('exact', 'subnet', or 'none').
     *
     * @param string $ip The client IP address (IPv4 or IPv6)
     * @return string The IP component for fingerprint generation
     */
    private static function getIPComponent(string $ip): string
    {
        $mode = getSetting('security.session_fingerprinting.ip_match', 'subnet');

        if ($mode === 'exact') {
            return $ip;
        }

        if ($mode === 'subnet') {
            return self::getSubnet($ip);
        }

        // 'none' — skip IP in fingerprint entirely
        return '';
    }

    /**
     * 🔢 Get Subnet from IP Address
     *
     * Extracts the subnet portion of an IP address:
     *   - IPv4: first 3 octets (/24), e.g. '192.168.1' from '192.168.1.100'
     *   - IPv6: first 3 groups (/48), e.g. '2001:db8:85a3' from '2001:db8:85a3::8a2e:370:7334'
     *
     * @param string $ip The full IP address
     * @return string The subnet portion of the IP address
     * @see https://www.php.net/manual/en/function.filter-var.php
     */
    private static function getSubnet(string $ip): string
    {
        // 🔍 Detect IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = explode('.', $ip);
            // Return first 3 octets (e.g. '192.168.1')
            return implode('.', array_slice($octets, 0, 3));
        }

        // 🔍 Detect IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $groups = explode(':', $ip);
            // Return first 3 groups (e.g. '2001:db8:85a3')
            return implode(':', array_slice($groups, 0, 3));
        }

        // 🤷 Unknown format — return the full IP as a fallback
        return $ip;
    }

    /**
     * 🧂 Get Server-Side Salt
     *
     * Retrieves the fingerprinting salt from settings. If the salt is empty
     * or not configured, generates a cryptographically secure random 32-byte
     * hex string and attempts to persist it back to the database. The salt
     * is cached in a static property for the request lifetime to avoid
     * repeated database lookups.
     *
     * @return string The salt string
     * @see https://www.php.net/manual/en/function.random-bytes.php
     */
    private static function getSalt(): string
    {
        // ⚡ Return cached salt if available (avoids repeated DB lookups)
        if (self::$cachedSalt !== null) {
            return self::$cachedSalt;
        }

        $salt = getSetting('security.session_fingerprinting.salt', '');

        // 🆕 Generate a new salt if none is configured
        if ($salt === '' || $salt === null) {
            $salt = bin2hex(random_bytes(32));

            // 💾 Attempt to persist the generated salt back to the database
            if (function_exists('setSetting')) {
                setSetting('security.session_fingerprinting.salt', $salt);
            }

            // 🗄️ Also try to persist via Database::query if the class is available
            if (class_exists('Database')) {
                try {
                    Database::query(
                        "INSERT INTO tblSettings (settingKey, settingValue, settingType, isSensitive, description, createdAt, updatedAt)
                         VALUES (?, ?, 'string', 0, 'Auto-generated salt for session fingerprinting', NOW(), NOW())
                         ON DUPLICATE KEY UPDATE settingValue = ?, updatedAt = NOW()",
                        [
                            'security.session_fingerprinting.salt',
                            $salt,
                            $salt
                        ],
                        'sss'
                    );
                } catch (Exception $e) {
                    // 📝 Log the failure but continue — the in-memory salt still works
                    error_log('SessionGuard: Failed to persist fingerprinting salt: ' . $e->getMessage());
                }
            }
        }

        // ⚡ Cache for the remainder of this request
        self::$cachedSalt = $salt;

        return $salt;
    }
}

// ✅ SessionGuard loaded successfully
