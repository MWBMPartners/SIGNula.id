<?php
/**
 * ============================================================================
 * 🧪 PHPUnit Bootstrap File
 * ============================================================================
 *
 * Sets up the testing environment for SIGNula.
 * Provides helper functions, global stubs, and test utilities.
 *
 * This bootstrap:
 * - Defines SIGNULA_INIT so source files can be loaded without access guards
 * - Provides stub implementations of global functions (getSetting, etc.)
 * - Loads Composer autoloader if available
 * - Provides helper functions for fixtures, mocking, and database operations
 *
 * @package SIGNula\Tests
 * @version 2.6.0-beta
 * @see     https://docs.phpunit.de/en/10.5/configuration.html
 */

// ============================================================================
// 🔧 CORE CONSTANTS & ERROR REPORTING
// ============================================================================

// 📢 Full error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 🕐 Consistent timezone for all tests
date_default_timezone_set('UTC');

// 🔐 Define SIGNULA_INIT to allow loading source files
// All source files check: if (!defined('SIGNULA_INIT')) { die(); }
if (!defined('SIGNULA_INIT')) {
    define('SIGNULA_INIT', true);
}

// 📁 Define project paths
define('PROJECT_ROOT', dirname(__DIR__));
define('TESTS_ROOT', __DIR__);

// 🔑 Define encryption key for SecurityUtils tests
// Uses phpunit.xml env var or fallback test key
if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: 'test-encryption-key-32-chars-xx');
}

// 🌐 Define base URL for tests
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost');
}

// ============================================================================
// 📦 AUTOLOADER
// ============================================================================

// Load Composer autoloader (if available)
$autoloadPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

// ============================================================================
// ⚙️ TEST SETTINGS (Global Stub Storage)
// ============================================================================

// 📋 Global test settings store, used by getSetting() stub
// Tests can set values via setTestSetting() or load from fixtures
$GLOBALS['test_settings'] = [];

/**
 * 📝 Set a test setting value
 *
 * Use this to configure settings before calling code that depends on getSetting().
 *
 * @param string $key   Setting key (e.g., 'security.password.min_length')
 * @param mixed  $value Setting value
 * @return void
 *
 * @example
 * ```php
 * setTestSetting('security.password.min_length', 12);
 * $result = SecurityUtils::validatePassword('short');
 * ```
 */
function setTestSetting(string $key, mixed $value): void
{
    $GLOBALS['test_settings'][$key] = $value;
}

/**
 * 🧹 Clear all test settings
 *
 * Called automatically in TestCase::setUp() to ensure clean state.
 *
 * @return void
 */
function clearTestSettings(): void
{
    $GLOBALS['test_settings'] = [];
}

/**
 * 📋 Load test settings from fixture file
 *
 * Loads default settings from _tests/Fixtures/settings.json
 *
 * @return void
 */
function loadDefaultTestSettings(): void
{
    $settingsFile = TESTS_ROOT . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'settings.json';

    if (file_exists($settingsFile)) {
        $content = file_get_contents($settingsFile);
        $settings = json_decode($content, true);

        if (is_array($settings)) {
            $GLOBALS['test_settings'] = array_merge($GLOBALS['test_settings'], $settings);
        }
    }
}

// ============================================================================
// 🔌 GLOBAL FUNCTION STUBS
// ============================================================================
// These stubs replace functions defined in web/_config/config.php
// Source classes call these globally, so they must exist in the test environment.
// Stubs are only defined if not already loaded (allows integration tests to
// optionally load the real config.php instead).

/**
 * ⚙️ Get Setting Value (Stub)
 *
 * Returns settings from $GLOBALS['test_settings'] or the provided default.
 * Production version reads from database via tblSettings.
 *
 * @param string $key     Setting key
 * @param mixed  $default Default value if not found
 * @return mixed Setting value
 *
 * @see web/_config/config.php for production implementation
 */
if (!function_exists('getSetting')) {
    function getSetting(string $key, mixed $default = null): mixed
    {
        return $GLOBALS['test_settings'][$key] ?? $default;
    }
}

/**
 * 🌐 Get Client IP Address (Stub)
 *
 * @return string IP address
 */
if (!function_exists('getClientIP')) {
    function getClientIP(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

/**
 * 🖥️ Get User Agent (Stub)
 *
 * @return string User agent string
 */
if (!function_exists('getUserAgent')) {
    function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'PHPUnit/TestAgent';
    }
}

/**
 * 🔗 Get Current URL (Stub)
 *
 * @return string Current URL
 */
if (!function_exists('getCurrentURL')) {
    function getCurrentURL(): string
    {
        return 'http://localhost/test';
    }
}

/**
 * ↪️ Redirect (Stub)
 *
 * In tests, throws RuntimeException instead of calling header() + exit().
 * Test code can catch this to verify redirects happen.
 *
 * @param string $url        Redirect URL
 * @param int    $statusCode HTTP status code (default: 302)
 * @return void
 * @throws RuntimeException Always, to prevent test execution from terminating
 */
if (!function_exists('redirect')) {
    function redirect(string $url, int $statusCode = 302): void
    {
        throw new RuntimeException("Redirect to: {$url} (status: {$statusCode})");
    }
}

/**
 * 📤 JSON Response (Stub)
 *
 * In tests, stores the response data in $GLOBALS for later assertion
 * instead of sending headers and echoing JSON.
 *
 * @param bool        $success Success flag
 * @param string      $message Response message
 * @param array       $data    Response data
 * @param int         $code    HTTP status code
 * @return void
 */
if (!function_exists('jsonResponse')) {
    function jsonResponse(bool $success, string $message, array $data = [], int $code = 200): void
    {
        $GLOBALS['last_json_response'] = [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'code' => $code
        ];
    }
}

/**
 * 🧼 Sanitize Input (Stub)
 *
 * @param mixed $data Input data
 * @return mixed Sanitized data
 */
if (!function_exists('sanitizeInput')) {
    function sanitizeInput(mixed $data): mixed
    {
        if (is_array($data)) {
            return array_map('sanitizeInput', $data);
        }

        if (is_string($data)) {
            return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
        }

        return $data;
    }
}

// ============================================================================
// 🛠️ TEST HELPER FUNCTIONS
// ============================================================================

/**
 * 📂 Get path to fixtures directory
 *
 * @param string $path Optional subdirectory/file within Fixtures/
 * @return string Full path to fixture
 *
 * @example
 * ```php
 * $path = fixture_path('users.json');
 * ```
 */
function fixture_path(string $path = ''): string
{
    return TESTS_ROOT . DIRECTORY_SEPARATOR . 'Fixtures' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR . '/') : '');
}

/**
 * 📄 Load fixture data from JSON file
 *
 * @param string $filename Fixture filename (without .json extension)
 * @return array Decoded JSON data
 * @throws RuntimeException if fixture file not found
 *
 * @example
 * ```php
 * $users = load_fixture('users');
 * ```
 */
function load_fixture(string $filename): array
{
    $path = fixture_path($filename . '.json');

    if (!file_exists($path)) {
        throw new RuntimeException("Fixture file not found: {$path}");
    }

    $content = file_get_contents($path);
    $decoded = json_decode($content, true);

    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Invalid JSON in fixture file: {$path} - " . json_last_error_msg());
    }

    return $decoded;
}

/**
 * 📦 Load a source file from the web/ directory
 *
 * Handles path construction using DIRECTORY_SEPARATOR for platform neutrality.
 *
 * @param string $relativePath Path relative to web/ (e.g., 'private_html/security/SecurityUtils.php')
 * @return void
 * @throws RuntimeException if file not found
 *
 * @example
 * ```php
 * requireSource('private_html/security/SecurityUtils.php');
 * ```
 */
function requireSource(string $relativePath): void
{
    $fullPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    if (!file_exists($fullPath)) {
        throw new RuntimeException("Source file not found: {$fullPath}");
    }

    require_once $fullPath;
}

/**
 * 🔌 Create a mock database connection for testing
 *
 * Returns a singleton MySQLi connection to the test database.
 * Connection credentials come from phpunit.xml environment variables.
 *
 * @return mysqli Database connection
 * @throws RuntimeException if connection fails
 */
function mock_database(): mysqli
{
    static $connection = null;

    if ($connection === null) {
        $host = getenv('DB_HOST') ?: 'localhost';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $name = getenv('DB_NAME') ?: 'signula_test';

        $connection = new mysqli($host, $user, $pass, $name);

        if ($connection->connect_error) {
            throw new RuntimeException('Test database connection failed: ' . $connection->connect_error);
        }

        // Match production settings
        $connection->set_charset('utf8mb4');
    }

    return $connection;
}

/**
 * 🧹 Reset database to clean state for testing
 *
 * Truncates all tables except tblSettings and tblMigrations.
 *
 * @return void
 */
function reset_database(): void
{
    $mysqli = mock_database();

    // 🔓 Disable foreign key checks for truncation
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');

    // Get all tables
    $result = $mysqli->query("SHOW TABLES");
    $tables = [];

    while ($row = $result->fetch_array()) {
        $table = $row[0];
        // Don't truncate settings or migration tracking tables
        if (!in_array($table, ['tblSettings', 'tblMigrations'], true)) {
            $tables[] = $table;
        }
    }

    // Truncate all tables
    foreach ($tables as $table) {
        $mysqli->query("TRUNCATE TABLE `{$table}`");
    }

    // 🔒 Re-enable foreign key checks
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * 🌱 Seed database with test data
 *
 * @param array $data Array of table => rows data
 * @return void
 */
function seed_database(array $data): void
{
    $mysqli = mock_database();

    foreach ($data as $table => $rows) {
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $values = array_values($row);

            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $columnList = implode(', ', array_map(function ($col) {
                return "`{$col}`";
            }, $columns));

            $query = "INSERT INTO `{$table}` ({$columnList}) VALUES ({$placeholders})";
            $stmt = $mysqli->prepare($query);

            // Determine parameter types dynamically
            $types = '';
            foreach ($values as $value) {
                if (is_int($value)) {
                    $types .= 'i';
                } elseif (is_float($value)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
            }

            $stmt->bind_param($types, ...$values);
            $stmt->execute();
        }
    }
}

/**
 * 📧 Generate a random email for testing
 *
 * @param string $prefix Optional prefix
 * @return string Random email address
 */
function random_email(string $prefix = 'test'): string
{
    return $prefix . '_' . uniqid() . '@example.com';
}

/**
 * 🔤 Generate a random string
 *
 * @param int $length Length of string to generate
 * @return string Random hex string
 */
function random_string(int $length = 10): string
{
    return bin2hex(random_bytes((int)ceil($length / 2)));
}

/**
 * 🌐 Create a mock HTTP request
 *
 * Sets $_SERVER, $_POST, and $_GET superglobals for testing.
 *
 * @param array $server $_SERVER variables
 * @param array $post   $_POST variables
 * @param array $get    $_GET variables
 * @return void
 */
function mock_request(array $server = [], array $post = [], array $get = []): void
{
    $_SERVER = array_merge($_SERVER, $server);
    $_POST = $post;
    $_GET = $get;
}

/**
 * 📺 Start output buffering to capture echoed content
 *
 * @return void
 */
function start_capture(): void
{
    ob_start();
}

/**
 * 📺 Get captured output and clean buffer
 *
 * @return string Captured output
 */
function get_capture(): string
{
    return ob_get_clean();
}

// ============================================================================
// 🚀 BOOTSTRAP INITIALIZATION
// ============================================================================

// Load default test settings from fixture
loadDefaultTestSettings();

// Load base test case classes
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TestCase.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'DatabaseTestCase.php';

// 📢 Display test environment info in CLI
if (PHP_SAPI === 'cli') {
    echo PHP_EOL;
    echo "====================================" . PHP_EOL;
    echo "  SIGNula Test Suite Bootstrap" . PHP_EOL;
    echo "====================================" . PHP_EOL;
    echo "PHP Version: " . PHP_VERSION . PHP_EOL;
    echo "PHPUnit: " . (class_exists('PHPUnit\Framework\TestCase') ? 'Loaded' : 'Not Found') . PHP_EOL;
    echo "Environment: " . (getenv('APP_ENV') ?: 'testing') . PHP_EOL;
    echo "Encryption Key: " . (defined('ENCRYPTION_KEY') ? 'Defined' : 'Missing') . PHP_EOL;
    echo "====================================" . PHP_EOL;
    echo PHP_EOL;
}
