<?php
/**
 * ============================================================================
 * 🧪 SIGNula - Phase 1 Setup Verification Script
 * ============================================================================
 *
 * Purpose: Verify Phase 1 authentication setup is correct
 * PHP Version: 8.3+
 *
 * Usage: php _tests/verify-phase1-setup.php
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

echo "🧪 SIGNula Phase 1 - Setup Verification\n";
echo str_repeat('=', 60) . "\n\n";

$passed = 0;
$failed = 0;
$warnings = 0;

/**
 * 🔍 Test Function
 */
function test($name, $callback) {
    global $passed, $failed, $warnings;

    echo "Testing: {$name}... ";

    try {
        $result = $callback();

        if ($result === true) {
            echo "✅ PASS\n";
            $passed++;
        } elseif ($result === 'warning') {
            echo "⚠️  WARNING\n";
            $warnings++;
        } else {
            echo "❌ FAIL: {$result}\n";
            $failed++;
        }
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n";
        $failed++;
    }
}

// ============================================================================
// 📊 DATABASE TESTS
// ============================================================================

echo "📊 Database Tests\n";
echo str_repeat('-', 60) . "\n";

test("Database connection", function() {
    $result = Database::testConnection();
    return $result ? true : "Cannot connect to database";
});

test("tblWebAuthnCredentials table exists", function() {
    $result = Database::fetchOne("SHOW TABLES LIKE 'tblWebAuthnCredentials'");
    return $result ? true : "Table not found - run migration";
});

test("tblWebAuthnChallenges table exists", function() {
    $result = Database::fetchOne("SHOW TABLES LIKE 'tblWebAuthnChallenges'");
    return $result ? true : "Table not found - run migration";
});

test("tblPasswordlessTokens table exists", function() {
    $result = Database::fetchOne("SHOW TABLES LIKE 'tblPasswordlessTokens'");
    return $result ? true : "Table not found - run migration";
});

test("tblUsers has webauthnEnabled column", function() {
    $result = Database::fetchOne("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'tblUsers'
        AND COLUMN_NAME = 'webauthnEnabled'
    ");
    return $result ? true : "Column not found - run migration";
});

test("tblUsers has passwordlessEnabled column", function() {
    $result = Database::fetchOne("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'tblUsers'
        AND COLUMN_NAME = 'passwordlessEnabled'
    ");
    return $result ? true : "Column not found - run migration";
});

test("Cleanup stored procedure exists", function() {
    $result = Database::fetchOne("
        SELECT ROUTINE_NAME FROM information_schema.ROUTINES
        WHERE ROUTINE_SCHEMA = DATABASE()
        AND ROUTINE_NAME = 'cleanupExpiredAuthTokens'
    ");
    return $result ? true : "Stored procedure not found - run migration";
});

echo "\n";

// ============================================================================
// ⚙️ CONFIGURATION TESTS
// ============================================================================

echo "⚙️ Configuration Tests\n";
echo str_repeat('-', 60) . "\n";

test("WebAuthn enabled setting", function() {
    $value = getSetting('auth.webauthn.enabled');
    return $value == '1' ? true : "WebAuthn not enabled - check settings";
});

test("WebAuthn RP name configured", function() {
    $value = getSetting('auth.webauthn.rp_name');
    return !empty($value) ? true : "RP name not configured";
});

test("WebAuthn RP ID configured", function() {
    $value = getSetting('auth.webauthn.rp_id');
    if (empty($value)) {
        return "RP ID not configured";
    }
    // Check if it matches current host
    $currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if ($value !== $currentHost && $value !== 'localhost') {
        return 'warning';
    }
    return true;
});

test("Passwordless login enabled", function() {
    $value = getSetting('auth.passwordless.enabled');
    return $value == '1' ? true : "Passwordless login not enabled";
});

test("Passwordless token validity configured", function() {
    $value = getSetting('auth.passwordless.token_validity');
    return !empty($value) ? true : "Token validity not configured";
});

test("Site URL configured", function() {
    $value = getSetting('site.url');
    return !empty($value) ? true : "Site URL not configured - needed for email links";
});

echo "\n";

// ============================================================================
// 📧 EMAIL SYSTEM TESTS
// ============================================================================

echo "📧 Email System Tests\n";
echo str_repeat('-', 60) . "\n";

test("Email provider configured", function() {
    $value = getSetting('email.provider');
    if (empty($value)) {
        return "No email provider configured - passwordless login will fail";
    }
    return true;
});

test("EmailService class exists", function() {
    return class_exists('EmailService') ? true : "EmailService class not found";
});

echo "\n";

// ============================================================================
// 🔐 AUTHENTICATION CLASSES
// ============================================================================

echo "🔐 Authentication Classes\n";
echo str_repeat('-', 60) . "\n";

test("WebAuthnHandler class exists", function() {
    $file = SIGNULA_ROOT . '/_includes/auth/WebAuthnHandler.php';
    if (!file_exists($file)) {
        return "WebAuthnHandler.php not found";
    }
    require_once $file;
    return class_exists('WebAuthnHandler') ? true : "Class not loaded";
});

test("PasswordlessLoginHandler class exists", function() {
    $file = SIGNULA_ROOT . '/_includes/auth/PasswordlessLoginHandler.php';
    if (!file_exists($file)) {
        return "PasswordlessLoginHandler.php not found";
    }
    require_once $file;
    return class_exists('PasswordlessLoginHandler') ? true : "Class not loaded";
});

echo "\n";

// ============================================================================
// 🌐 API ENDPOINTS
// ============================================================================

echo "🌐 API Endpoints\n";
echo str_repeat('-', 60) . "\n";

test("WebAuthn register-options.php exists", function() {
    $file = SIGNULA_ROOT . '/public_html/api/webauthn/register-options.php';
    return file_exists($file) ? true : "File not found";
});

test("WebAuthn register-verify.php exists", function() {
    $file = SIGNULA_ROOT . '/public_html/api/webauthn/register-verify.php';
    return file_exists($file) ? true : "File not found";
});

test("WebAuthn auth-options.php exists", function() {
    $file = SIGNULA_ROOT . '/public_html/api/webauthn/auth-options.php';
    return file_exists($file) ? true : "File not found";
});

test("WebAuthn auth-verify.php exists", function() {
    $file = SIGNULA_ROOT . '/public_html/api/webauthn/auth-verify.php';
    return file_exists($file) ? true : "File not found";
});

echo "\n";

// ============================================================================
// 📄 USER PAGES
// ============================================================================

echo "📄 User Pages\n";
echo str_repeat('-', 60) . "\n";

test("PassKey registration page exists", function() {
    $file = SIGNULA_ROOT . '/public_html/auth/passkey-register.php';
    return file_exists($file) ? true : "File not found";
});

test("PassKey login page exists", function() {
    $file = SIGNULA_ROOT . '/public_html/auth/passkey-login.php';
    return file_exists($file) ? true : "File not found";
});

test("Passwordless request page exists", function() {
    $file = SIGNULA_ROOT . '/public_html/auth/passwordless-request.php';
    return file_exists($file) ? true : "File not found";
});

test("Passwordless login page exists", function() {
    $file = SIGNULA_ROOT . '/public_html/auth/passwordless-login.php';
    return file_exists($file) ? true : "File not found";
});

test("PassKey management page exists", function() {
    $file = SIGNULA_ROOT . '/public_html/settings/passkeys.php';
    return file_exists($file) ? true : "File not found";
});

echo "\n";

// ============================================================================
// 🔒 SECURITY CHECKS
// ============================================================================

echo "🔒 Security Checks\n";
echo str_repeat('-', 60) . "\n";

test("HTTPS check", function() {
    if (php_sapi_name() === 'cli') {
        return 'warning'; // Can't check in CLI
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $_SERVER['SERVER_PORT'] == 443
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1']);

    if (!$isHttps && !$isLocalhost) {
        return "HTTPS required for WebAuthn (except on localhost)";
    }

    return true;
});

test("PHP version check", function() {
    $version = PHP_VERSION;
    if (version_compare($version, '8.3.0', '<')) {
        return "PHP 8.3+ required, found: {$version}";
    }
    return true;
});

test("required PHP extensions", function() {
    $required = ['mysqli', 'json', 'openssl', 'mbstring'];
    $missing = [];

    foreach ($required as $ext) {
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }

    if (!empty($missing)) {
        return "Missing extensions: " . implode(', ', $missing);
    }

    return true;
});

echo "\n";

// ============================================================================
// 📊 SUMMARY
// ============================================================================

echo str_repeat('=', 60) . "\n";
echo "📊 Test Summary\n";
echo str_repeat('=', 60) . "\n";

$total = $passed + $failed + $warnings;

echo "✅ Passed:   {$passed}\n";
echo "❌ Failed:   {$failed}\n";
echo "⚠️  Warnings: {$warnings}\n";
echo "📊 Total:    {$total}\n\n";

if ($failed === 0) {
    echo "🎉 All critical tests passed!\n";

    if ($warnings > 0) {
        echo "⚠️  Some warnings detected - review them above.\n";
    } else {
        echo "✅ System is ready for testing!\n";
    }
} else {
    echo "❌ Some tests failed - fix issues before testing.\n";
    echo "📝 Review failed tests above and consult documentation.\n";
}

echo "\n";

// Exit with appropriate code
exit($failed > 0 ? 1 : 0);
