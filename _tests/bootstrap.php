<?php
/**
 * PHPUnit Bootstrap File
 * Sets up the testing environment for SIGNula
 *
 * @package SIGNula\Tests
 * @version 1.0.0
 */

// Error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Set timezone
date_default_timezone_set('UTC');

// Define project root
define('PROJECT_ROOT', dirname(__DIR__));
define('TESTS_ROOT', __DIR__);

// Load Composer autoloader (if available)
$autoloadPath = PROJECT_ROOT . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

// Load test helpers
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * Test Helper Functions
 */

/**
 * Get path to fixtures directory
 *
 * @param string $path Optional subdirectory/file
 * @return string Full path to fixture
 */
function fixture_path(string $path = ''): string
{
    return TESTS_ROOT . '/Fixtures' . ($path ? '/' . ltrim($path, '/') : '');
}

/**
 * Load fixture data from JSON file
 *
 * @param string $filename Fixture filename (without .json extension)
 * @return array Decoded JSON data
 * @throws RuntimeException if fixture file not found
 */
function load_fixture(string $filename): array
{
    $path = fixture_path($filename . '.json');

    if (!file_exists($path)) {
        throw new RuntimeException("Fixture file not found: {$path}");
    }

    $content = file_get_contents($path);
    return json_decode($content, true);
}

/**
 * Create a mock database connection for testing
 *
 * @return mysqli Mock database connection
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
            throw new RuntimeException('Database connection failed: ' . $connection->connect_error);
        }
    }

    return $connection;
}

/**
 * Reset database to clean state for testing
 * Truncates all tables (except tblSettings)
 *
 * @return void
 */
function reset_database(): void
{
    $mysqli = mock_database();

    // Disable foreign key checks
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');

    // Get all tables
    $result = $mysqli->query("SHOW TABLES");
    $tables = [];

    while ($row = $result->fetch_array()) {
        $table = $row[0];
        // Don't truncate settings table
        if ($table !== 'tblSettings') {
            $tables[] = $table;
        }
    }

    // Truncate all tables
    foreach ($tables as $table) {
        $mysqli->query("TRUNCATE TABLE `{$table}`");
    }

    // Re-enable foreign key checks
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * Seed database with test data
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
            $columnList = implode(', ', $columns);

            $query = "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})";
            $stmt = $mysqli->prepare($query);

            // Bind parameters dynamically
            $types = str_repeat('s', count($values)); // Default to string type
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
        }
    }
}

/**
 * Generate a random email for testing
 *
 * @param string $prefix Optional prefix
 * @return string Random email address
 */
function random_email(string $prefix = 'test'): string
{
    return $prefix . '_' . uniqid() . '@example.com';
}

/**
 * Generate a random string
 *
 * @param int $length Length of string to generate
 * @return string Random string
 */
function random_string(int $length = 10): string
{
    return bin2hex(random_bytes($length / 2));
}

/**
 * Create a mock HTTP request
 *
 * @param array $server $_SERVER variables
 * @param array $post $_POST variables
 * @param array $get $_GET variables
 * @return void
 */
function mock_request(array $server = [], array $post = [], array $get = []): void
{
    $_SERVER = array_merge($_SERVER, $server);
    $_POST = $post;
    $_GET = $get;
}

/**
 * Start output buffering to capture echoed content
 *
 * @return void
 */
function start_capture(): void
{
    ob_start();
}

/**
 * Get captured output and clean buffer
 *
 * @return string Captured output
 */
function get_capture(): string
{
    return ob_get_clean();
}

// Echo test environment info
if (PHP_SAPI === 'cli') {
    echo "\n";
    echo "====================================\n";
    echo "  SIGNula Test Suite Bootstrap\n";
    echo "====================================\n";
    echo "PHP Version: " . PHP_VERSION . "\n";
    echo "PHPUnit: " . (class_exists('PHPUnit\Framework\TestCase') ? 'Loaded' : 'Not Found') . "\n";
    echo "Environment: " . (getenv('APP_ENV') ?: 'testing') . "\n";
    echo "====================================\n";
    echo "\n";
}
