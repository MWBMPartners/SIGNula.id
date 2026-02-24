<?php
/**
 * ============================================================================
 * 🔌 SIGNula - Payment Provider Management API (Admin)
 * ============================================================================
 *
 * Purpose: Admin API endpoint for managing payment provider configuration
 *          (Stripe, PayPal, Coinbase Commerce, Ko-fi, Patreon). Allows super admins to view,
 *          update, and test payment provider settings stored in tblSettings.
 *
 * Actions (GET):
 *   - get_providers       — Retrieve status and config of all 3 providers
 *   - get_webhook_logs    — Retrieve recent inbound webhook log entries
 *
 * Actions (POST):
 *   - update_provider     — Update settings for a specific provider
 *   - test_connection     — Test connectivity to a payment provider
 *
 * Authentication: Super Admin only (isSuperAdmin = 1)
 * CSRF Protection: Required for all POST requests
 *
 * PHP Version: 8.3+
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\API\Payments
 * @version    1.0.0
 * @since      2.3.0-beta
 * @link       https://SIGNula.id
 * @see        https://docs.stripe.com/api (Stripe API Reference)
 * @see        https://developer.paypal.com/docs/api/overview/ (PayPal API Reference)
 * @see        https://docs.cdp.coinbase.com/commerce-onchain/docs/ (Coinbase Commerce Docs)
 * ============================================================================
 */

// ============================================================================
// 🚀 BOOTSTRAP — Load core system files
// ============================================================================

// ⚙️ Main configuration (defines constants, loads settings, initializes session)
// @see web/_config/config.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton (MySQLi prepared statements)
// @see web/_config/database.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🎫 Session manager (authentication state)
// @see web/_backend/SessionManager.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🔐 Access control (role-based permissions)
// @see web/_backend/AccessControl.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 📤 Set JSON response content type header
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
header('Content-Type: application/json');

// ============================================================================
// 🔐 AUTHENTICATION & AUTHORIZATION
// ============================================================================

// 🗄️ Initialize core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔐 Check authentication — user must be logged in
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 🔐 Require super admin — only super admins can manage payment providers
$userInfo = $sessionManager->getUserInfo();
if (!isset($userInfo['isSuperAdmin']) || $userInfo['isSuperAdmin'] != 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Super admin access required']);
    exit;
}

// ============================================================================
// 📥 REQUEST PARSING
// ============================================================================

// 📥 Get request data — supports both GET params and POST JSON body
$input = json_decode(file_get_contents('php://input'), true);
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// 🛡️ CSRF for POST requests — prevents cross-site request forgery
// @see https://owasp.org/www-community/attacks/csrf
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
        exit;
    }
}

// ============================================================================
// 🗺️ ACTION ROUTER — Dispatch to appropriate handler
// ============================================================================

try {
    switch ($action) {

        // 📊 GET — Retrieve all provider configurations
        case 'get_providers':
            handleGetProviders();
            break;

        // ✏️ POST — Update a specific provider's settings
        case 'update_provider':
            handleUpdateProvider($input);
            break;

        // 🧪 POST — Test connection to a payment provider
        case 'test_connection':
            handleTestConnection($input);
            break;

        // 📋 GET — Retrieve recent webhook log entries
        case 'get_webhook_logs':
            handleGetWebhookLogs();
            break;

        // ❌ Unknown action — return error
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    // 🚨 Catch-all error handler — return structured error response
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}


// ============================================================================
// 📊 HANDLER: get_providers
// ============================================================================

/**
 * 📊 Retrieve the status and configuration of all payment providers
 *
 * Fetches all payment.* settings from tblSettings, organizes them by provider
 * (stripe, paypal, crypto), and masks sensitive values (secret keys, webhook
 * secrets) for safe display in the admin UI.
 *
 * Public values like Stripe's publishable_key and PayPal's client_id are NOT
 * masked because they are designed to be exposed in client-side code.
 *
 * @return void Outputs JSON response with provider configurations
 *
 * @see https://docs.stripe.com/keys (Stripe API Keys — publishable vs secret)
 * @see https://developer.paypal.com/api/rest/authentication/ (PayPal Client ID is public)
 */
function handleGetProviders(): void
{
    // 🗄️ Fetch all payment-related settings from the database
    // Settings are stored with keys like "payment.stripe.enabled", "payment.paypal.mode", etc.
    $results = Database::fetchAll(
        "SELECT settingKey, settingValue, isSensitive FROM tblSettings WHERE settingCategory = 'payment' ORDER BY settingKey",
        [],
        ''
    );

    // 🏗️ Initialize provider structure with empty defaults
    // This ensures all expected keys exist even if not yet in the database
    $providers = [
        'stripe'  => [],
        'paypal'  => [],
        'crypto'  => [],
        'kofi'    => [],
        'patreon' => [],
    ];

    // 🔑 Keys that should NEVER be masked — they are public by design
    // - Stripe publishable_key: Embedded in client-side JavaScript for Stripe.js
    //   @see https://docs.stripe.com/keys#publishable-and-secret-keys
    // - PayPal client_id: Used in client-side PayPal SDK initialization
    //   @see https://developer.paypal.com/sdk/js/configuration/#link-queryparameters
    $publicKeys = [
        'payment.stripe.publishable_key',
        'payment.paypal.client_id',
        'payment.kofi.page_url',
        'payment.patreon.campaign_id',
    ];

    // 🔄 Process each setting and organize by provider
    foreach ($results as $row) {
        $fullKey = $row['settingKey'];       // e.g., "payment.stripe.secret_key"
        $value   = $row['settingValue'];      // Raw value from database
        $isSensitive = (int) $row['isSensitive']; // 1 = sensitive, 0 = public

        // 🔪 Split the key into parts: ["payment", "stripe", "secret_key"]
        $parts = explode('.', $fullKey);

        // ⚠️ Validate key format — must be "payment.<provider>.<setting>"
        if (count($parts) < 3 || $parts[0] !== 'payment') {
            continue; // Skip malformed keys
        }

        // 📌 Extract provider name and setting name
        $provider   = $parts[1]; // e.g., "stripe", "paypal", "crypto"
        $settingName = implode('_', array_slice($parts, 2)); // e.g., "secret_key", "link_enabled"

        // 🔐 Mask sensitive values for safe display in admin UI
        // Public keys (publishable_key, client_id) are shown in full
        if ($isSensitive && !in_array($fullKey, $publicKeys, true)) {
            $value = maskSensitiveValue($value);
        }

        // 📂 Store in the appropriate provider bucket
        if (isset($providers[$provider])) {
            $providers[$provider][$settingName] = $value;
        }
    }

    // ✅ Return structured response
    echo json_encode([
        'success'   => true,
        'providers' => $providers,
    ]);
}

/**
 * 🎭 Mask a sensitive value for safe display
 *
 * Shows "••••" followed by the last 4 characters if the value is longer than
 * 8 characters, allowing admins to identify which key is configured without
 * exposing the full secret. For short or empty values, returns "••••••••".
 *
 * Examples:
 *   - "sk_test_4eC39HqLyjWDarjtT1zdp7dc" → "••••7dc"  (last 4 visible)
 *   - "short"                              → "••••••••" (too short to partially reveal)
 *   - ""                                   → "••••••••" (empty/not configured)
 *
 * @param string $value The sensitive value to mask
 * @return string The masked value safe for display
 */
function maskSensitiveValue(string $value): string
{
    // 📏 If value is long enough, show last 4 characters with mask prefix
    if (strlen($value) > 8) {
        return '••••' . substr($value, -4);
    }

    // 🔒 For short or empty values, show full mask
    return '••••••••';
}


// ============================================================================
// ✏️ HANDLER: update_provider
// ============================================================================

/**
 * ✏️ Update settings for a specific payment provider
 *
 * Receives a provider name and a key-value map of settings to update.
 * Each key is mapped to its full tblSettings key (e.g., "secret_key" →
 * "payment.stripe.secret_key"). Sensitive values are encrypted before storage.
 *
 * Masked values (starting with "••••") are skipped — they indicate the admin
 * did not change the field, so we preserve the existing database value.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - provider (string): "stripe", "paypal", or "crypto"
 *   - settings (array): Key-value pairs to update
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If provider is invalid or no valid settings provided
 *
 * @see SecurityUtils::encrypt() for encryption of sensitive values
 * @see https://owasp.org/www-project-web-security-testing-guide/ (OWASP Testing Guide)
 */
function handleUpdateProvider(?array $input): void
{
    // ✅ Validate provider name
    $provider = trim($input['provider'] ?? '');
    $validProviders = ['stripe', 'paypal', 'crypto', 'kofi', 'patreon'];

    if (!in_array($provider, $validProviders, true)) {
        throw new Exception('Invalid provider. Must be one of: ' . implode(', ', $validProviders));
    }

    // ✅ Validate settings array
    $settings = $input['settings'] ?? [];

    if (empty($settings) || !is_array($settings)) {
        throw new Exception('No settings provided to update');
    }

    // 🔑 Define which setting keys are sensitive (require encryption)
    // These correspond to keys in tblSettings with isSensitive = 1
    $sensitiveKeys = [
        'payment.stripe.secret_key',
        'payment.stripe.webhook_secret',
        'payment.stripe.publishable_key', // Marked sensitive in DB but public in nature
        'payment.paypal.client_secret',
        'payment.crypto.api_key',
        'payment.crypto.webhook_secret',
        'payment.kofi.verification_token',
        'payment.patreon.client_id',
        'payment.patreon.client_secret',
        'payment.patreon.webhook_secret',
        'payment.patreon.creator_access_token',
        'payment.patreon.creator_refresh_token',
    ];

    // 📝 Track which keys were actually updated (for activity logging)
    $keysUpdated = [];
    $updateCount = 0;

    // 🔄 Process each setting key-value pair
    foreach ($settings as $settingName => $value) {

        // 🔪 Build the full setting key: "payment.<provider>.<settingName>"
        $fullKey = 'payment.' . $provider . '.' . $settingName;

        // ⏭️ Skip masked values — admin didn't change this field
        // Masked values look like "••••••••" or "••••abcd" (last 4 chars)
        if ($value === '••••••••' || str_starts_with((string) $value, '••••')) {
            continue;
        }

        // 🔍 Verify the setting exists in the database before updating
        // This prevents creation of arbitrary settings via this endpoint
        $existing = Database::fetchOne(
            "SELECT settingID FROM tblSettings WHERE settingKey = ?",
            [$fullKey],
            's'
        );

        if (!$existing) {
            // ⚠️ Setting doesn't exist — skip silently (don't expose DB structure)
            continue;
        }

        // 🔐 Encrypt sensitive values before storing in the database
        // @see SecurityUtils::encrypt() — uses AES-256-CBC with salt
        if (in_array($fullKey, $sensitiveKeys, true)) {
            $value = SecurityUtils::encrypt((string) $value);
        }

        // 🗄️ Update the setting in the database
        // Using prepared statement to prevent SQL injection
        // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
        Database::query(
            "UPDATE tblSettings SET settingValue = ? WHERE settingKey = ?",
            [$value, $fullKey],
            'ss'
        );

        // 📝 Track the updated key name (NOT the value — for security)
        $keysUpdated[] = $settingName;
        $updateCount++;
    }

    // 📝 Log the provider settings update in the activity log
    // @see ActivityLogger::log() — 6 parameters required
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],       // 👤 User who made the change
        'provider_settings_updated',      // 📌 Activity type
        'payment',                        // 📂 Category
        'info',                           // ℹ️ Severity level
        'Payment provider settings updated: ' . $provider, // 📝 Description
        ['provider' => $provider, 'keys_updated' => $keysUpdated] // 📊 Metadata
    );

    // ✅ Return success response with count of updated settings
    echo json_encode([
        'success' => true,
        'message' => ucfirst($provider) . ' settings updated successfully (' . $updateCount . ' field(s) changed)',
        'updated' => $keysUpdated,
    ]);
}


// ============================================================================
// 🧪 HANDLER: test_connection
// ============================================================================

/**
 * 🧪 Test connectivity to a payment provider
 *
 * Loads the appropriate provider class and calls its test/verification method:
 *   - Stripe: StripeProvider::getBalance() — retrieves account balance
 *   - PayPal: PayPalProvider::testConnection() — generates OAuth access token
 *   - Coinbase: CoinbaseProvider::testConnection() — verifies API key validity
 *
 * The provider classes return structured arrays with success/failure status,
 * which are passed through as-is to the admin UI.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - provider (string): "stripe", "paypal", or "crypto"
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response from the provider's test method
 *
 * @throws Exception If provider is invalid or provider class cannot be loaded
 *
 * @see StripeProvider::getBalance() — Calls Stripe Balance API
 * @see PayPalProvider::testConnection() — Calls PayPal OAuth2 token endpoint
 * @see CoinbaseProvider::testConnection() — Calls Coinbase Commerce API
 * @see https://docs.stripe.com/api/balance/balance_retrieve (Stripe Balance API)
 * @see https://developer.paypal.com/docs/api/get-an-access-token/ (PayPal OAuth)
 */
function handleTestConnection(?array $input): void
{
    // ✅ Validate provider name
    $provider = trim($input['provider'] ?? '');
    $validProviders = ['stripe', 'paypal', 'crypto', 'kofi', 'patreon'];

    if (!in_array($provider, $validProviders, true)) {
        throw new Exception('Invalid provider. Must be one of: ' . implode(', ', $validProviders));
    }

    // 🏗️ Build the path to the provider class file
    // Provider classes live in: web/private_html/payments/
    // @see ROOT_DIR constant defined in config.php (web/ directory root)
    $providerBasePath = ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR;

    // 🔀 Route to appropriate provider test method
    $result = match ($provider) {

        // 🔵 STRIPE — Test by retrieving account balance
        // @see https://docs.stripe.com/api/balance/balance_retrieve
        'stripe' => (function () use ($providerBasePath) {
            $filePath = $providerBasePath . 'StripeProvider.php';

            // 📂 Verify the provider class file exists before loading
            if (!file_exists($filePath)) {
                throw new Exception('Stripe provider class not found. Ensure StripeProvider.php exists in the payments directory.');
            }

            require_once $filePath;
            return StripeProvider::getBalance();
        })(),

        // 🟡 PAYPAL — Test by generating an OAuth access token
        // @see https://developer.paypal.com/docs/api/get-an-access-token/
        'paypal' => (function () use ($providerBasePath) {
            $filePath = $providerBasePath . 'PayPalProvider.php';

            // 📂 Verify the provider class file exists before loading
            if (!file_exists($filePath)) {
                throw new Exception('PayPal provider class not found. Ensure PayPalProvider.php exists in the payments directory.');
            }

            require_once $filePath;
            return PayPalProvider::testConnection();
        })(),

        // 🟠 COINBASE COMMERCE — Test by verifying API key
        // @see https://docs.cdp.coinbase.com/commerce-onchain/docs/
        'crypto' => (function () use ($providerBasePath) {
            $filePath = $providerBasePath . 'CoinbaseProvider.php';

            // 📂 Verify the provider class file exists before loading
            if (!file_exists($filePath)) {
                throw new Exception('Coinbase provider class not found. Ensure CoinbaseProvider.php exists in the payments directory.');
            }

            require_once $filePath;
            return CoinbaseProvider::testConnection();
        })(),

        // ☕ KO-FI — Test configuration
        // @see https://ko-fi.com/manage/webhooks — Ko-fi Webhook Settings
        'kofi' => (function () use ($providerBasePath) {
            $filePath = $providerBasePath . 'KofiProvider.php';

            // 📂 Verify the provider class file exists before loading
            if (!file_exists($filePath)) {
                throw new Exception('Ko-fi provider class not found. Ensure KofiProvider.php exists in the payments directory.');
            }

            require_once $filePath;
            return KofiProvider::testConnection();
        })(),

        // 🎨 PATREON — Test by fetching campaign info
        // @see https://docs.patreon.com/ — Patreon API Documentation
        'patreon' => (function () use ($providerBasePath) {
            $filePath = $providerBasePath . 'PatreonProvider.php';

            // 📂 Verify the provider class file exists before loading
            if (!file_exists($filePath)) {
                throw new Exception('Patreon provider class not found. Ensure PatreonProvider.php exists in the payments directory.');
            }

            require_once $filePath;
            return PatreonProvider::testConnection();
        })(),

        // ❌ Fallback (should not reach here due to earlier validation)
        default => throw new Exception('Unsupported provider for connection testing'),
    };

    // 📝 Log the connection test attempt in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    $testSuccess = $result['success'] ?? false;
    ActivityLogger::log(
        (int) $userInfo['userID'],                         // 👤 User who ran the test
        'provider_connection_tested',                       // 📌 Activity type
        'payment',                                          // 📂 Category
        $testSuccess ? 'info' : 'warning',                 // ℹ️ Severity (warning if failed)
        'Payment provider connection test: ' . $provider . ' — ' . ($testSuccess ? 'SUCCESS' : 'FAILED'), // 📝 Description
        ['provider' => $provider, 'success' => $testSuccess] // 📊 Metadata
    );

    // ✅ Return the provider's test result as-is
    // Provider classes return structured arrays like: ['success' => true, 'message' => '...']
    echo json_encode($result);
}


// ============================================================================
// 📋 HANDLER: get_webhook_logs
// ============================================================================

/**
 * 📋 Retrieve recent inbound webhook log entries from tblInboundWebhooks
 *
 * Returns metadata for recent webhook events (no full payloads — they are too
 * large for list views). Supports optional filtering by provider name and
 * configurable result limit.
 *
 * GET Parameters:
 *   - provider (string, optional): Filter by provider name ("stripe", "paypal", "coinbase")
 *   - limit (int, optional): Maximum number of results (default: 50, max: 500)
 *
 * @return void Outputs JSON response with webhook log entries
 *
 * @see tblInboundWebhooks — Created by migration 011_payment_providers.sql
 * @see https://docs.stripe.com/webhooks (Stripe Webhooks)
 * @see https://developer.paypal.com/docs/api-basics/notifications/webhooks/ (PayPal Webhooks)
 * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/webhooks (Coinbase Webhooks)
 */
function handleGetWebhookLogs(): void
{
    // 📥 Parse optional GET parameters
    $provider = trim($_GET['provider'] ?? '');
    $limit = (int) ($_GET['limit'] ?? 50);

    // 🔒 Clamp limit to safe range (1–500) to prevent excessive queries
    $limit = max(1, min(500, $limit));

    // 🏗️ Build the query dynamically based on filters
    $conditions = [];
    $params = [];
    $types = '';

    // 🔍 Optional provider filter — only return logs for a specific provider
    if ($provider !== '') {
        $validProviders = ['stripe', 'paypal', 'coinbase', 'kofi', 'patreon'];

        if (!in_array($provider, $validProviders, true)) {
            throw new Exception('Invalid provider filter. Must be one of: ' . implode(', ', $validProviders));
        }

        $conditions[] = 'provider = ?';
        $params[] = $provider;
        $types .= 's';
    }

    // 🔪 Assemble WHERE clause
    $whereClause = !empty($conditions)
        ? 'WHERE ' . implode(' AND ', $conditions)
        : '';

    // 📏 Add LIMIT parameter
    $params[] = $limit;
    $types .= 'i';

    // 🗄️ Fetch webhook log entries — metadata only (no full payload)
    // The payload column is intentionally excluded because it can be very large
    // (full JSON from Stripe/PayPal/Coinbase) and would slow down the response.
    // Admins can view individual payloads via a detail endpoint if needed.
    $logs = Database::fetchAll(
        "SELECT
            webhookID,
            provider,
            eventType,
            eventID,
            verified,
            status,
            errorMessage,
            httpStatusReturned,
            processingTimeMs,
            ipAddress,
            createdAt,
            processedAt
         FROM tblInboundWebhooks
         {$whereClause}
         ORDER BY createdAt DESC
         LIMIT ?",
        $params,
        $types
    );

    // 📊 Also fetch total count for informational purposes
    $countResult = Database::fetchOne(
        "SELECT COUNT(*) as total FROM tblInboundWebhooks {$whereClause}",
        // ⚠️ Re-use params but without the LIMIT param
        array_slice($params, 0, -1),
        rtrim($types, 'i') // Remove the trailing 'i' used for LIMIT
    );
    $total = $countResult ? (int) $countResult['total'] : 0;

    // ✅ Return structured response
    echo json_encode([
        'success' => true,
        'data'    => $logs,
        'total'   => $total,
        'limit'   => $limit,
        'filter'  => $provider !== '' ? $provider : 'all',
    ]);
}
