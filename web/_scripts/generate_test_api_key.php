<?php
/**
 * Generate Test API Key Script
 *
 * Utility script to generate API keys for development and testing
 *
 * Usage:
 *   php _scripts/generate_test_api_key.php
 *   php _scripts/generate_test_api_key.php --live
 *   php _scripts/generate_test_api_key.php --partner-id=2 --name="Production Key"
 *
 * @package SIGNula
 * @version 2.2.0-beta
 * @since 2026-02-04
 */

// Initialize SIGNula
define('SIGNULA_INIT', true);
require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once PRIVATE_DIR . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'APIKeyManager.php';

// ============================================================================
// 📋 COMMAND LINE ARGUMENTS
// ============================================================================

$options = getopt('', ['live', 'test', 'partner-id:', 'name:', 'expires:', 'help']);

// Show help
if (isset($options['help'])) {
    showHelp();
    exit(0);
}

// Determine environment
$environment = isset($options['live']) ? 'live' : 'test';

// Get partner ID (default to test partner)
$partnerID = isset($options['partner-id']) ? (int)$options['partner-id'] : 1;

// Get key name
$keyName = isset($options['name']) ? $options['name'] :
    ($environment === 'live' ? 'Live API Key' : 'Test API Key');

// Get expiration (optional)
$expiresAt = isset($options['expires']) ? $options['expires'] : null;

// ============================================================================
// 🔑 GENERATE API KEY
// ============================================================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         SIGNula API Key Generator (Development Tool)         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Verify partner exists
$stmt = $db->prepare("SELECT partnerID, partnerName, accountTier, isActive FROM tblPartners WHERE partnerID = ?");
$stmt->bind_param('i', $partnerID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "❌ Error: Partner ID $partnerID not found\n\n";
    echo "Available partners:\n";

    $stmt = $db->prepare("SELECT partnerID, partnerName, accountTier FROM tblPartners WHERE isActive = 1");
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        echo "  - ID {$row['partnerID']}: {$row['partnerName']} ({$row['accountTier']})\n";
    }

    echo "\nUsage: php generate_test_api_key.php --partner-id=X\n\n";
    exit(1);
}

$partner = $result->fetch_assoc();
$stmt->close();

// Check if partner is active
if (!$partner['isActive']) {
    echo "❌ Error: Partner '{$partner['partnerName']}' is inactive\n\n";
    exit(1);
}

// Display configuration
echo "📋 Configuration:\n";
echo "   Partner:     {$partner['partnerName']} (ID: {$partner['partnerID']})\n";
echo "   Tier:        {$partner['accountTier']}\n";
echo "   Environment: " . strtoupper($environment) . "\n";
echo "   Key Name:    $keyName\n";
echo "   Expires:     " . ($expiresAt ? $expiresAt : 'Never') . "\n";
echo "\n";

// Initialize API Key Manager
$apiKeyManager = new APIKeyManager($db);

// Set up options
$keyOptions = [
    'permissions' => ['*'], // Full access for test keys
    'expiresAt' => $expiresAt
];

// Generate key
echo "🔐 Generating API key...\n";
$result = $apiKeyManager->generateKey($partnerID, $keyName, $environment, $keyOptions);

if (!$result) {
    echo "❌ Failed to generate API key\n";
    echo "\nPossible reasons:\n";
    echo "  - Partner has reached maximum key limit\n";
    echo "  - Database error\n";
    echo "  - Invalid configuration\n\n";
    exit(1);
}

// ============================================================================
// ✅ SUCCESS - DISPLAY KEY
// ============================================================================

echo "✅ API Key generated successfully!\n\n";

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                       🔑 YOUR API KEY                        ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "  {$result['key']}\n";
echo "\n";
echo "⚠️  IMPORTANT: Save this key now! It will not be shown again.\n";
echo "\n";

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                      📊 KEY INFORMATION                      ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "  Key ID:      {$result['keyData']['keyID']}\n";
echo "  Prefix:      {$result['keyData']['keyPrefix']}\n";
echo "  Environment: {$result['keyData']['environment']}\n";
echo "  Created:     {$result['keyData']['createdAt']}\n";
echo "  Expires:     " . ($result['keyData']['expiresAt'] ? $result['keyData']['expiresAt'] : 'Never') . "\n";
echo "\n";

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                      🚀 USAGE EXAMPLES                       ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Get base URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $protocol . $host;

echo "1️⃣  Using cURL (X-API-Key header - recommended):\n\n";
echo "   curl -H \"X-API-Key: {$result['key']}\" \\\n";
echo "        {$baseUrl}/api/v1/health\n\n";

echo "2️⃣  Using cURL (Authorization header):\n\n";
echo "   curl -H \"Authorization: Bearer {$result['key']}\" \\\n";
echo "        {$baseUrl}/api/v1/health\n\n";

echo "3️⃣  Using JavaScript (fetch API):\n\n";
echo "   fetch('{$baseUrl}/api/v1/health', {\n";
echo "     headers: {\n";
echo "       'X-API-Key': '{$result['key']}'\n";
echo "     }\n";
echo "   })\n";
echo "   .then(response => response.json())\n";
echo "   .then(data => console.log(data));\n\n";

echo "4️⃣  Using PHP:\n\n";
echo "   \$apiKey = '{$result['key']}';\n";
echo "   \$headers = ['X-API-Key: ' . \$apiKey];\n";
echo "   \$response = file_get_contents('{$baseUrl}/api/v1/health', false,\n";
echo "       stream_context_create(['http' => ['header' => \$headers]]));\n\n";

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                      ⚡ QUICK VALIDATION                     ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Test your key immediately:\n\n";
echo "  curl -i -H \"X-API-Key: {$result['key']}\" {$baseUrl}/api/v1/health\n\n";

echo "Expected response:\n";
echo "  HTTP/1.1 200 OK\n";
echo "  X-RateLimit-Limit: ...\n";
echo "  X-RateLimit-Remaining: ...\n";
echo "  Content-Type: application/json\n\n";

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                      🛡️  SECURITY NOTES                      ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "  • Store this key securely (environment variables, secrets manager)\n";
echo "  • Never commit API keys to version control\n";
echo "  • " . ($environment === 'test' ? "This is a TEST key - use for development only" : "This is a LIVE key - use in production") . "\n";
echo "  • Regenerate if compromised\n";
echo "  • Monitor usage in tblAPIKeyUsage table\n";
echo "\n";

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                      📈 MONITORING                           ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "View usage statistics:\n\n";
echo "  SELECT COUNT(*) as requests, AVG(responseTime) as avg_ms\n";
echo "  FROM tblAPIKeyUsage WHERE keyID = {$result['keyData']['keyID']};\n\n";

// ============================================================================
// 🎯 SUMMARY
// ============================================================================

echo "✅ Key generation complete!\n\n";

// ============================================================================
// 📚 HELP FUNCTION
// ============================================================================

function showHelp() {
    echo "\n";
    echo "SIGNula API Key Generator\n";
    echo "=========================\n\n";
    echo "Usage:\n";
    echo "  php generate_test_api_key.php [options]\n\n";
    echo "Options:\n";
    echo "  --test              Generate test environment key (default)\n";
    echo "  --live              Generate live environment key\n";
    echo "  --partner-id=<id>   Partner ID (default: 1 - test partner)\n";
    echo "  --name=<name>       Human-readable key name\n";
    echo "  --expires=<date>    Expiration date (YYYY-MM-DD HH:MM:SS)\n";
    echo "  --help              Show this help message\n\n";
    echo "Examples:\n";
    echo "  php generate_test_api_key.php\n";
    echo "    Generates test key for test partner\n\n";
    echo "  php generate_test_api_key.php --live --partner-id=2 --name=\"Production API Key\"\n";
    echo "    Generates live key for partner ID 2\n\n";
    echo "  php generate_test_api_key.php --expires=\"2027-12-31 23:59:59\"\n";
    echo "    Generates key that expires on Dec 31, 2027\n\n";
}
