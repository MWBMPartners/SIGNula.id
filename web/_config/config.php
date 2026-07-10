<?php
/**
 * ============================================================================
 * ⚙️ SIGNula Universal Login System - Main Configuration
 * ============================================================================
 *
 * Purpose: Central configuration loader and system initialization
 * PHP Version: 8.3+
 *
 * This file:
 * - Defines system constants
 * - Loads configuration from database
 * - Initializes error handling
 * - Sets up session management
 * - Loads required libraries and utilities
 *
 * @package    SIGNula
 * @subpackage Configuration
 * @version    1.0.0
 * @link       https://SIGNula.id
 * ============================================================================
 */

// 🚀 Mark system as initialized (prevents direct file access)
define('SIGNULA_INIT', true);

// ============================================================================
// 📁 PATH CONSTANTS
// ============================================================================

/**
 * 🏠 Root directory of the application
 */
define('ROOT_DIR', dirname(__DIR__));

/**
 * 🔗 SIGNULA_ROOT — legacy alias for ROOT_DIR
 *
 * 🐛 B-060: 37 files under web/public_html/ (settings/*, webhooks/*,
 * api/webauthn/*, auth/passkey-*.php + auth/passwordless-*.php, checkout/*,
 * pricing/index.php, admin/email-*.php) predate the introduction of
 * ROOT_DIR above and reference SIGNULA_ROOT as their web-root path prefix —
 * always as `SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . …`.
 * SIGNULA_ROOT was never actually define()'d anywhere in the codebase, so
 * PHP 8's promotion of undefined-constant use to a fatal Error meant every
 * one of those 37 files died on load. Rather than rewrite 37 files' path
 * prefixes, define the missing alias ONCE, here, pointing at the exact same
 * directory ROOT_DIR resolves to (web/) — every existing use already assumes
 * that target directory.
 *
 * @see https://www.php.net/manual/en/language.constants.php
 */
if (!defined('SIGNULA_ROOT')) {
    define('SIGNULA_ROOT', ROOT_DIR);
}

/**
 * 🔒 Private files directory (outside web root)
 */
define('PRIVATE_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . '_private');

/**
 * ⚙️ Configuration directory
 */
define('CONFIG_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . '_config');

/**
 * 📚 Includes directory (reusable components)
 */
define('INCLUDES_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . '_includes');

/**
 * 📦 Libraries directory (third-party libraries)
 */
define('LIB_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . '_lib');

/**
 * 🌐 Public web directory
 */
define('PUBLIC_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'public_html');

/**
 * 🧪 Alpha version directory
 */
define('ALPHA_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'alpha_html');

/**
 * 🚧 Beta version directory
 */
define('BETA_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'beta_html');

/**
 * 📝 Logs directory
 */
define('LOGS_DIR', PRIVATE_DIR . DIRECTORY_SEPARATOR . 'logs');

/**
 * 🗄️ SQL directory
 */
define('SQL_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . '_sql');

// ============================================================================
// 🔧 SYSTEM CONSTANTS
// ============================================================================

/**
 * 🏷️ Application name
 */
define('APP_NAME', 'SIGNula');

/**
 * 📌 Application version
 */
define('APP_VERSION', '1.0.0');

/**
 * 🌐 Environment (loaded from settings or auth.php)
 */
define('ENVIRONMENT', getenv('ENVIRONMENT') ?: 'production');

/**
 * 🐛 Debug mode (NEVER enable in production!)
 */
define('DEBUG_MODE', ENVIRONMENT === 'development');

/**
 * 📺 Display errors (controlled by URL parameter in debug mode)
 */
define('DISPLAY_ERRORS', DEBUG_MODE && isset($_GET['debug']) && $_GET['debug'] === 'true');

// ============================================================================
// ⚠️ ERROR HANDLING CONFIGURATION
// ============================================================================

/**
 * 🔧 Configure PHP error reporting based on environment
 */
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', DISPLAY_ERRORS ? '1' : '0');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

/**
 * 📝 Set error log file
 */
ini_set('error_log', LOGS_DIR . DIRECTORY_SEPARATOR . 'php_errors.log');

/**
 * 🚨 Custom error handler
 * Logs errors to database and file system
 */
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Don't log suppressed errors (@ operator)
    if (error_reporting() === 0) {
        return false;
    }

    $errorTypes = [
        E_ERROR             => 'E_ERROR',
        E_WARNING           => 'E_WARNING',
        E_PARSE             => 'E_PARSE',
        E_NOTICE            => 'E_NOTICE',
        E_CORE_ERROR        => 'E_CORE_ERROR',
        E_CORE_WARNING      => 'E_CORE_WARNING',
        E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
        E_USER_ERROR        => 'E_USER_ERROR',
        E_USER_WARNING      => 'E_USER_WARNING',
        E_USER_NOTICE       => 'E_USER_NOTICE',
        E_STRICT            => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED        => 'E_DEPRECATED',
        E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
    ];

    $errorType = $errorTypes[$errno] ?? 'UNKNOWN';

    // 📝 Log to file
    $logMessage = sprintf(
        "[%s] %s: %s in %s on line %d",
        date('Y-m-d H:i:s'),
        $errorType,
        $errstr,
        $errfile,
        $errline
    );

    error_log($logMessage . PHP_EOL, 3, LOGS_DIR . DIRECTORY_SEPARATOR . 'php_errors.log');

    // 🗄️ Log to database (if ErrorLogger is available)
    if (class_exists('ErrorLogger')) {
        ErrorLogger::logError($errorType, $errstr, $errfile, $errline);
    }

    // 🖥️ Display error if debug mode is enabled
    if (DISPLAY_ERRORS) {
        echo "<div style='background:#f8d7da;color:#721c24;padding:10px;margin:10px;border:1px solid #f5c6cb;'>";
        echo "<strong>{$errorType}:</strong> {$errstr} in <strong>{$errfile}</strong> on line <strong>{$errline}</strong>";
        echo "</div>";
    }

    // Don't execute PHP's internal error handler
    return true;
});

/**
 * 💥 Custom exception handler
 */
set_exception_handler(function($exception) {
    $logMessage = sprintf(
        "[%s] Exception: %s in %s on line %d\nStack trace:\n%s",
        date('Y-m-d H:i:s'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );

    error_log($logMessage . PHP_EOL, 3, LOGS_DIR . DIRECTORY_SEPARATOR . 'php_errors.log');

    if (DISPLAY_ERRORS) {
        echo "<div style='background:#f8d7da;color:#721c24;padding:10px;margin:10px;border:1px solid #f5c6cb;'>";
        echo "<strong>Exception:</strong> " . htmlspecialchars($exception->getMessage());
        echo "<br><strong>File:</strong> " . htmlspecialchars($exception->getFile());
        echo "<br><strong>Line:</strong> " . $exception->getLine();
        echo "<pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
        echo "</div>";
    } else {
        http_response_code(500);
        echo "An error occurred. Please try again later.";
    }
});

// ============================================================================
// 📂 CREATE REQUIRED DIRECTORIES
// ============================================================================

/**
 * 🏗️ Ensure all required directories exist
 */
$requiredDirs = [
    LOGS_DIR,
    PRIVATE_DIR . DIRECTORY_SEPARATOR . 'keys',
    PRIVATE_DIR . DIRECTORY_SEPARATOR . 'backups',
    PRIVATE_DIR . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'email',
    PRIVATE_DIR . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars', // 🖼️ Avatar uploads
];

foreach ($requiredDirs as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            die("Failed to create directory: {$dir}");
        }
    }
}

// ============================================================================
// 🔐 SECURITY HEADERS
// ============================================================================

/**
 * 🛡️ Set security headers
 * @see https://owasp.org/www-project-secure-headers/
 */
if (!headers_sent()) {
    // Prevent clickjacking attacks
    header('X-Frame-Options: SAMEORIGIN');

    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');

    // Enable XSS protection
    header('X-XSS-Protection: 1; mode=block');

    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // 🔒 Content Security Policy — controls which resources the browser is allowed to load
    // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP
    // @see https://content-security-policy.com/
    header("Content-Security-Policy: "
        . "default-src 'self'; "                                                                    // 🛡️ Default: only allow same-origin
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://challenges.cloudflare.com https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; " // 📜 Scripts: self + inline + CDNs + CAPTCHA providers
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; " // 🎨 Styles: self + inline + CDNs + Google Fonts
        . "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; "               // 🔤 Fonts: FontAwesome + Google Fonts
        . "img-src 'self' data: https:; "                                                           // 🖼️ Images: self + data URIs (avatars) + any HTTPS
        . "frame-src 'self' https://challenges.cloudflare.com https://www.google.com/recaptcha/; "  // 🖼️ Frames: CAPTCHA provider widgets
        . "connect-src 'self' https://challenges.cloudflare.com"                                    // 🔗 AJAX/Fetch: same-origin + Turnstile
    );

    // 🔐 HTTP Strict Transport Security — force HTTPS for all future requests
    // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Strict-Transport-Security
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

    // Permissions policy
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

// ============================================================================
// 🗄️ LOAD DATABASE CONNECTION
// ============================================================================

require_once CONFIG_DIR . DIRECTORY_SEPARATOR . 'database.php';

// ============================================================================
// ⚙️ LOAD SETTINGS FROM DATABASE
// ============================================================================

/**
 * 📊 Settings cache
 */
$GLOBALS['settings'] = [];

/**
 * 🔧 Load settings from database into global array
 */
function loadSettings(): void
{
    try {
        $query = "SELECT settingKey, settingValue, settingType, isSensitive FROM tblSettings";
        $result = Database::fetchAll($query);

        foreach ($result as $setting) {
            $key = $setting['settingKey'];
            $value = $setting['settingValue'];
            $type = $setting['settingType'];
            $isSensitive = (bool)$setting['isSensitive'];

            // 🔓 Decrypt sensitive values — resilient: a single blank/undecryptable
            // row must NOT abort loading the rest of the settings (fresh installs
            // seed blank placeholder credential rows for unconfigured OAuth/
            // payment/CAPTCHA providers). Skip+log the bad one and carry on.
            // 🐛 B-052 fix: previously this call sat unguarded inside the OUTER
            // try/catch below — the first blank/undecryptable sensitive value
            // threw a RuntimeException (SecurityUtils::decrypt() throws on a
            // blank/invalid ciphertext) which propagated straight out of the
            // foreach loop, aborting the ENTIRE settings load. Every setting
            // after that row (often most of the table) silently disappeared.
            if ($isSensitive && class_exists('SecurityUtils')) {
                if ($value === null || $value === '') {
                    // 🈳 Unconfigured/blank sensitive setting — nothing to decrypt.
                    $value = '';
                } else {
                    try {
                        $value = SecurityUtils::decrypt($value);
                    } catch (\Throwable $decryptError) {
                        // 🚑 Do not let one undecryptable secret starve every
                        // other setting — log it and keep processing the rest.
                        error_log("loadSettings: could not decrypt sensitive setting '" . $key . "': " . $decryptError->getMessage());
                        $value = ''; // leave empty; continue loading remaining rows
                    }
                }
            }

            // 🔄 Convert to appropriate type
            $value = match($type) {
                'integer' => (int)$value,
                'float' => (float)$value,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'json', 'array' => json_decode($value, true),
                default => $value
            };

            $GLOBALS['settings'][$key] = $value;
        }
    } catch (Exception $e) {
        // If database not available, settings will be empty
        error_log("Failed to load settings: " . $e->getMessage());
    }
}

/**
 * 🔍 Get setting value by key
 *
 * @param string $key Setting key (supports dot notation)
 * @param mixed $default Default value if setting not found
 * @return mixed Setting value
 */
function getSetting(string $key, mixed $default = null): mixed
{
    return $GLOBALS['settings'][$key] ?? $default;
}

/**
 * ✏️ Set setting value (runtime only, doesn't persist to database)
 *
 * @param string $key Setting key
 * @param mixed $value Setting value
 */
function setSetting(string $key, mixed $value): void
{
    $GLOBALS['settings'][$key] = $value;
}

// ============================================================================
// 🎫 SESSION CONFIGURATION
// ============================================================================

/**
 * 🔧 Configure session settings
 */
function initializeSession(): void
{
    // Session security settings
    ini_set('session.cookie_httponly', '1'); // Prevent JavaScript access to session cookie
    ini_set('session.use_only_cookies', '1'); // Only use cookies for sessions
    ini_set('session.cookie_secure', ENVIRONMENT === 'production' ? '1' : '0'); // HTTPS only in production
    ini_set('session.cookie_samesite', 'Lax'); // CSRF protection

    // Session name
    ini_set('session.name', 'SIGNULA_SESSION');

    // Session lifetime (loaded from settings or default 24 hours)
    $sessionLifetime = getSetting('security.session.lifetime', 86400);
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    ini_set('session.cookie_lifetime', (string)$sessionLifetime);

    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 🔄 Regenerate session ID periodically for security
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 300) { // Every 5 minutes
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }

    // 🔑 Session fingerprinting — detect session hijacking attempts
    // @see web/private_html/security/SessionGuard.php
    if (class_exists('SessionGuard') && SessionGuard::isEnabled()) {
        SessionGuard::initialize();
    }
}

// ============================================================================
// 📚 AUTO-LOADER FOR INCLUDE FILES
// ============================================================================

/**
 * 🔄 Auto-load include files when needed
 */
spl_autoload_register(function($className) {
    // Convert class name to file path
    // Example: SecurityUtils -> _includes/security/SecurityUtils.php

    $possiblePaths = [
        // 📂 Search _includes/ subdirectories
        INCLUDES_DIR . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . $className . '.php',
        INCLUDES_DIR . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . $className . '.php',
        INCLUDES_DIR . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . $className . '.php',
        INCLUDES_DIR . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . $className . '.php',
        // 🎮 API controllers (AuthController, JwtAuthController, UserController, …)
        INCLUDES_DIR . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . $className . '.php',
        INCLUDES_DIR . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . $className . '.php',
        INCLUDES_DIR . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . $className . '.php',
        // 📂 Search private_html/ subdirectories (security classes, API middleware, auth, utils)
        ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . $className . '.php',
        ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . $className . '.php',
        // 🎮 API controllers in the in-repo private_html layout
        ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . $className . '.php',
        ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . $className . '.php',
        ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . $className . '.php',
        ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . $className . '.php',
    ];

    foreach ($possiblePaths as $file) {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// ============================================================================
// 🚀 INITIALIZE SYSTEM
// ============================================================================

try {
    // Load settings from database
    loadSettings();

    // Initialize session management
    initializeSession();

    // 🛡️ Security middleware — IP blocklist, bot detection, IP reputation checks
    // Runs on every request. Each check is independently toggleable via tblSettings.
    // @see web/private_html/security/SecurityMiddleware.php
    if (class_exists('SecurityMiddleware')) {
        SecurityMiddleware::handle('web');
    }

} catch (Exception $e) {
    error_log("System initialization failed: " . $e->getMessage());

    if (DEBUG_MODE) {
        die("System initialization failed: " . $e->getMessage());
    } else {
        http_response_code(500);
        die("System temporarily unavailable. Please try again later.");
    }
}

// ============================================================================
// 🔧 UTILITY FUNCTIONS
// ============================================================================

/**
 * 🧹 Sanitize input data
 *
 * @param mixed $data Data to sanitize
 * @return mixed Sanitized data
 */
function sanitizeInput(mixed $data): mixed
{
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }

    if (is_string($data)) {
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }

    return $data;
}

/**
 * 🔍 Validate email address
 *
 * @param string $email Email address
 * @return bool Valid status
 */
function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * 🌐 Get client IP address
 *
 * @return string IP address (IPv4 or IPv6)
 */
function getClientIP(): string
{
    $ipKeys = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR'
    ];

    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            $ip = trim($ips[0]);

            // Validate IP
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * 📱 Get user agent
 *
 * @return string User agent string
 */
function getUserAgent(): string
{
    return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
}

/**
 * 🔗 Get current URL
 *
 * @return string Current URL
 */
function getCurrentURL(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '';

    return $protocol . $host . $uri;
}

/**
 * 🔀 Redirect to URL
 *
 * @param string $url Target URL
 * @param int $statusCode HTTP status code (default: 302)
 */
function redirect(string $url, int $statusCode = 302): void
{
    if (!headers_sent()) {
        header("Location: {$url}", true, $statusCode);
        exit;
    }
}

/**
 * 🛡️ Sanitize a redirect URL to prevent open redirect attacks
 *
 * Only allows relative paths starting with '/' (not '//' which is protocol-relative).
 * Rejects absolute URLs, javascript: URIs, and data: URIs.
 * Returns the fallback URL if the input is unsafe.
 *
 * @param string $url The redirect URL to validate
 * @param string $fallback Safe fallback URL (default: '/dashboard')
 * @return string A safe redirect URL
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html
 */
function sanitizeRedirectUrl(string $url, string $fallback = '/dashboard'): string
{
    $url = trim($url);

    // ❌ Reject empty URLs
    if ($url === '') {
        return $fallback;
    }

    // ❌ Reject protocol-relative URLs (//evil.com) and absolute URLs
    if (preg_match('#^(https?://|//|[a-z]+:)#i', $url)) {
        return $fallback;
    }

    // ✅ Must start with a single forward slash (relative path)
    if ($url[0] !== '/') {
        return $fallback;
    }

    // ❌ Reject double slashes after the first character (//evil.com disguised)
    if (isset($url[1]) && $url[1] === '/') {
        return $fallback;
    }

    return $url;
}

/**
 * ✅ JSON response helper
 *
 * @param bool $success Success status
 * @param string $message Response message
 * @param mixed $data Additional data
 * @param int $statusCode HTTP status code
 */
function jsonResponse(bool $success, string $message, mixed $data = null, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

// ============================================================================
// 🔐 AUTHENTICATION HELPERS (B-060)
// ============================================================================
// 🐛 While defining SIGNULA_ROOT above (see that constant's doc-block), the
// same set of pages turned out to depend on THREE more bare global helper
// functions that were never defined anywhere in the codebase either —
// requireLogin() (15 call sites), getCurrentUser() (13 call sites, DISTINCT
// from the Auth::getCurrentUser() STATIC METHOD and BaseController::
// getCurrentUser() PROTECTED METHOD — neither of those is a bare global
// function), and isLoggedIn() (5 call sites). Without these too, "fixing"
// SIGNULA_ROOT alone would still leave nearly every settings/* and
// auth/passkey-*|passwordless-*.php page fataling on its very next line —
// so they're fixed here as the same class of shared plumbing.
//
// All three are thin wrappers over the PROVEN-WORKING Auth class
// (web/private_html/auth/Auth.php) — the same class settings/connected-
// apps.php and oauth/authorize-idp.php already gate on successfully.

/**
 * 🔐 Require an authenticated session, or redirect to /login
 *
 * Delegates to Auth::requireAuth(), which redirects to /login preserving the
 * current URL as ?redirect=, AND enforces this app's email-verification
 * policy (Auth::requiresEmailVerification()) — both checks are the correct
 * default for the account-management pages that call this (settings/*,
 * auth/passkey-register.php, admin/email-webhooks.php).
 *
 * Every call site invokes requireLogin() with zero arguments; Auth::
 * requireAuth() already falls back to the current request URL via
 * getCurrentURL() when no explicit redirect target is supplied, so no
 * parameter is needed here either.
 *
 * @return void
 * @see web/private_html/auth/Auth.php (Auth::requireAuth())
 */
function requireLogin(): void
{
    if (class_exists('Auth')) {
        Auth::requireAuth();
        return;
    }

    // 🚨 Defensive fallback only — Auth.php is autoloadable via the
    // spl_autoload_register() above and should always be present. A gated
    // page must never silently render if, somehow, it isn't.
    redirect('/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/dashboard'));
}

/**
 * ✅ Is there a currently authenticated user?
 *
 * Thin wrapper over Auth::isAuthenticated() — used by pre-login pages
 * (auth/passkey-login.php, auth/passwordless-login.php, auth/passwordless-
 * request.php, api/webauthn/register-options.php, api/webauthn/register-
 * verify.php) to short-circuit away when a session already exists.
 *
 * @return bool
 * @see web/private_html/auth/Auth.php (Auth::isAuthenticated())
 */
function isLoggedIn(): bool
{
    return class_exists('Auth') && Auth::isAuthenticated();
}

/**
 * 👤 Get the current authenticated user's full record (or null)
 *
 * Thin wrapper over Auth::getCurrentUser(). Several call sites pass
 * `$refresh = true` immediately after mutating the user's own row (e.g.
 * settings/profile.php after an avatar/profile update, settings/mfa.php
 * after enabling MFA) and expect the NEXT call to see the fresh data —
 * Auth::$currentUser is a PUBLIC static property specifically so callers
 * outside Auth can invalidate its memoized cache (the same reset mechanism
 * _tests/Integration/Auth/GetCurrentUserAdminFlagsTest.php uses), so
 * `$refresh` clears it before delegating.
 *
 * @param bool $refresh Bust Auth's memoized user cache before returning
 * @return array|null
 * @see web/private_html/auth/Auth.php (Auth::getCurrentUser(), Auth::$currentUser)
 */
function getCurrentUser(bool $refresh = false): ?array
{
    if (!class_exists('Auth')) {
        return null;
    }

    if ($refresh) {
        Auth::$currentUser = null;
    }

    return Auth::getCurrentUser();
}

/**
 * 🛡️ Is the current authenticated user an admin?
 *
 * 🐛 B-066: web/public_html/admin/email-webhooks.php, email-config.php and
 * email-dashboard.php all gate on a bare `isAdmin()` call
 * (`if (!isAdmin()) { http_response_code(403); die(...); }`), but that
 * global function was never defined anywhere reachable from these pages —
 * only a same-named LOCAL function inside admin/api/deploy-migration.php
 * (a different script, never loaded here) and the unrelated
 * `AccessControl::isAdmin()` INSTANCE method (web/_backend/AccessControl.php,
 * a different/legacy session stack). Every request to those 3 pages fataled
 * with "Call to undefined function isAdmin()" before any admin check could
 * even run.
 *
 * Thin wrapper over the SAME `isAdmin` flag Auth::getCurrentUser() already
 * derives (isSuperAdmin OR an active tblPartnerTeamMembers membership — see
 * that method's own doc-block), so this delegates rather than
 * re-implementing the admin-resolution logic a second time.
 *
 * @return bool True if there is a logged-in user AND they are an admin
 * @see web/private_html/auth/Auth.php (Auth::getCurrentUser(), the `isAdmin` key)
 */
function isAdmin(): bool
{
    $user = getCurrentUser();

    return !empty($user['isAdmin']);
}

/**
 * ⏱️ Human-readable "time ago" formatter
 *
 * 🐛 B-066: settings/index.php, activity.php, privacy.php and
 * connected-accounts.php all call `timeAgo($someDateTimeString)` to render
 * "5 minutes ago"-style relative timestamps, but this function was never
 * defined ANYWHERE in the codebase — every one of those pages fataled with
 * "Call to undefined function timeAgo()" as soon as it had at least one row
 * to render (e.g. the very first "login_success" activity row a real
 * Auth::login() creates for the user rendering the page).
 *
 * Accepts a MySQL DATETIME string (as returned by Database::fetchOne()/
 * fetchAll() — mysqli returns DATETIME columns as strings, never DateTime
 * objects) and returns a short relative-time phrase. Gracefully degrades on
 * null/blank/unparseable input rather than throwing, since callers pass
 * values straight from a DB row that may be NULL (e.g. tblAPIKeys.lastUsedAt
 * before the key has ever been used).
 *
 * @param string|null $datetime MySQL "Y-m-d H:i:s" datetime string, or null
 * @return string Relative time phrase, e.g. "5 minutes ago", "2 days ago"
 * @see https://www.php.net/manual/en/function.strtotime.php
 */
function timeAgo(?string $datetime): string
{
    if (empty($datetime)) {
        return 'just now';
    }

    $timestamp = strtotime($datetime);

    // 🛡️ strtotime() returns false on an unparseable string — degrade
    // gracefully instead of feeding `false` into arithmetic below.
    if ($timestamp === false) {
        return 'unknown';
    }

    $diffSeconds = time() - $timestamp;

    // 🕐 A timestamp in the future (clock skew, or a scheduled value) — treat
    // as "just now" rather than showing a nonsensical negative duration.
    if ($diffSeconds < 60) {
        return 'just now';
    }

    // 📏 Largest-unit-first breakdown, mirroring the common "time ago" idiom.
    $intervals = [
        31536000 => 'year',
        2592000  => 'month',
        604800   => 'week',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute',
    ];

    foreach ($intervals as $seconds => $label) {
        $count = intdiv($diffSeconds, $seconds);
        if ($count >= 1) {
            return $count . ' ' . $label . ($count > 1 ? 's' : '') . ' ago';
        }
    }

    return 'just now';
}

// ✅ Configuration loaded successfully
