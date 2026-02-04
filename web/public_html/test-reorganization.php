<?php
/**
 * Reorganization Verification Test
 *
 * Run this script to verify directory reorganization was successful
 *
 * Usage: php web/public_html/test-reorganization.php
 *    OR: Visit in browser: http://localhost/test-reorganization.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "SIGNula Directory Reorganization Test\n";
echo str_repeat("=", 50) . "\n\n";

// Test 1: Load configuration
echo "Test 1: Loading configuration...\n";
$configPath = __DIR__ . '/../../_config/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
    echo "  ✅ Configuration loaded\n";
} else {
    echo "  ❌ Configuration file not found at: $configPath\n";
    exit(1);
}

// Test 2: Check constants
echo "\nTest 2: Checking path constants...\n";
$constants = [
    'ROOT_DIR' => ROOT_DIR,
    'CONFIG_DIR' => CONFIG_DIR,
    'INCLUDES_DIR' => INCLUDES_DIR,
    'PRIVATE_DIR' => PRIVATE_DIR,
    'PUBLIC_DIR' => PUBLIC_DIR,
    'LOGS_DIR' => LOGS_DIR
];

foreach ($constants as $name => $path) {
    echo "  $name: $path\n";
}

// Test 3: Verify directories exist
echo "\nTest 3: Verifying directories exist...\n";
$checkDirs = [
    'CONFIG_DIR' => CONFIG_DIR,
    'INCLUDES_DIR' => INCLUDES_DIR,
    'PRIVATE_DIR' => PRIVATE_DIR,
    'PUBLIC_DIR' => PUBLIC_DIR
];

$allGood = true;
foreach ($checkDirs as $name => $path) {
    if (is_dir($path)) {
        echo "  ✅ $name exists\n";
    } else {
        echo "  ❌ $name does NOT exist: $path\n";
        $allGood = false;
    }
}

// Test 4: Test critical includes
echo "\nTest 4: Testing critical includes...\n";
$testIncludes = [
    'SecurityUtils' => INCLUDES_DIR . '/security/SecurityUtils.php',
    'ErrorLogger' => INCLUDES_DIR . '/utils/ErrorLogger.php',
    'Response' => INCLUDES_DIR . '/api/Response.php'
];

foreach ($testIncludes as $name => $file) {
    if (file_exists($file)) {
        echo "  ✅ $name found\n";
    } else {
        echo "  ❌ $name NOT found: $file\n";
        $allGood = false;
    }
}

// Final result
echo "\n" . str_repeat("=", 50) . "\n";
if ($allGood) {
    echo "✅ ALL TESTS PASSED - Reorganization successful!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED - Check errors above\n";
    exit(1);
}
