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
 * @link       https://signulo.id
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

    // Content Security Policy (adjust as needed)
    // header('Content-Security-Policy: default-src \'self\'');

    // HTTPS enforcement (uncomment in production with SSL)
    // header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

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

            // 🔓 Decrypt sensitive values
            if ($isSensitive && class_exists('SecurityUtils')) {
                $value = SecurityUtils::decrypt($value);
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
        INCLUDES_DIR . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . $className . '.php',
        INCLUDES_DIR . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . $className . '.php',
        INCLUDES_DIR . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . $className . '.php',
        INCLUDES_DIR . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . $className . '.php',
        INCLUDES_DIR . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . $className . '.php',
        INCLUDES_DIR . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . $className . '.php',
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

// ✅ Configuration loaded successfully
