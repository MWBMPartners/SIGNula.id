<?php
/**
 * ============================================================================
 * 💳 Partner Payment Configuration API
 * ============================================================================
 *
 * Purpose: JSON API endpoint for managing partner payment provider configurations.
 *          Supports retrieving, saving, and testing payment provider configs
 *          (Stripe, PayPal, Coinbase Commerce) for partners.
 *
 * PHP Version: 8.3+ (targeting 8.4 for longevity)
 *
 * Supported Actions:
 * ─────────────────────────────────────────────────────────────────────────────
 * GET  | get_configs      — Retrieve all payment configurations for a partner
 * POST | save_config      — Save/update a payment provider configuration
 * POST | test_connection  — Test API credentials for a payment provider
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Access Control:
 * - All actions require an authenticated session
 * - All actions require finance role or higher for the specified partner
 *   (or super admin access)
 * - POST actions require a valid CSRF token
 *
 * Response Format (JSON):
 * - { "success": true/false, "message": "...", "data": { ... } }
 *
 * Dependencies:
 * - /_config/config.php                              — SIGNula bootstrap
 * - /_backend/Database.php                           — MySQLi database singleton
 * - /_backend/SessionManager.php                     — Session handling
 * - /_backend/AccessControl.php                      — Multi-tier RBAC
 * - /_backend/ActivityLogger.php                     — Activity logging
 * - /private_html/payments/PartnerPaymentService.php — Payment config CRUD
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods — HTTP Methods
 * @see https://www.php.net/manual/en/function.json-encode.php    — JSON encoding
 * @see https://owasp.org/www-community/attacks/csrf              — CSRF protection
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Partners\API
 * @version    1.0.0
 * @since      2.4.0-beta
 * ============================================================================
 */

// ============================================================================
// 📦 DEPENDENCY LOADING
// ============================================================================

// 🔧 Load core configuration (defines SIGNULA_INIT, DB credentials, SecurityUtils, etc.)
// dirname(__DIR__, 3) navigates from /web/public_html/partners/api/ up to /web/
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton — all queries use MySQLi prepared statements
// @see https://www.php.net/manual/en/class.mysqli.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🔐 Session manager — handles login state, user info, CSRF tokens
// @see https://www.php.net/manual/en/book.session.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🛡️ Access control — multi-tier RBAC with role hierarchy
// Roles: super-admin(100) > root-admin(80) > admin(60) > developer(40) > support(30) > finance(20) > user(10)
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 📝 Activity logger — logs user actions for auditing and debugging
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'ActivityLogger.php';

// 💳 PartnerPaymentService — CRUD for partner payment provider configs
// Located in private_html (not web-accessible) for security
// @see /web/private_html/payments/PartnerPaymentService.php
$partnerPaymentServicePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'PartnerPaymentService.php';
if (file_exists($partnerPaymentServicePath)) {
    require_once $partnerPaymentServicePath;
} else {
    // ⚠️ PartnerPaymentService is essential for this API
    error_log('[SIGNula] payment-config-actions.php: PartnerPaymentService.php not found at ' . $partnerPaymentServicePath);
}

// ============================================================================
// 📡 RESPONSE HEADERS
// ============================================================================

// 📦 Set JSON content type for all responses
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
header('Content-Type: application/json');

// 🔒 Security headers — prevent caching of sensitive API responses
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ============================================================================
// 🔌 SERVICE INITIALISATION
// ============================================================================

// 🗄️ Get the database singleton instance
$db = Database::getInstance();

// 🔐 Create session manager for authentication checks
$sessionManager = new SessionManager($db);

// 🛡️ Create access control for role-based permission checks
$accessControl = new AccessControl($db, $sessionManager);

// 📝 Create activity logger for audit trail
$activityLogger = new ActivityLogger($db, $sessionManager);

// ============================================================================
// 🔒 AUTHENTICATION CHECK
// ============================================================================

// 🔒 Verify the user is logged in — return 401 Unauthorized if not
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/401
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Please log in to access this resource.'
    ]);
    exit;
}

// ============================================================================
// 📋 REQUEST PARSING
// ============================================================================

// 📋 Parse the incoming request data
// GET requests: action comes from query string
// POST requests: action comes from JSON body
// @see https://www.php.net/manual/en/function.json-decode.php
// @see https://www.php.net/manual/en/wrappers.php.php (php://input)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 📋 For GET requests, read parameters from the query string
    $input = $_GET;
    $action = $input['action'] ?? '';
} else {
    // 📋 For POST requests, read JSON body
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    // ❌ Invalid JSON body
    if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON request body'
        ]);
        exit;
    }

    $action = $input['action'] ?? '';
}

// ============================================================================
// 🛡️ CSRF VERIFICATION (POST requests only)
// ============================================================================

// 🛡️ Verify CSRF token for all state-changing (POST) requests
// Accepts token from JSON body or X-CSRF-TOKEN header
// @see https://owasp.org/www-community/attacks/csrf
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired CSRF token. Please refresh the page and try again.'
        ]);
        exit;
    }
}

// ============================================================================
// 🔀 ACTION ROUTER
// ============================================================================

// 🔀 Route to the appropriate handler function based on the action parameter
// Each handler validates its own inputs and access permissions
try {
    switch ($action) {

        // 🔍 GET: Retrieve all payment configurations for a partner
        case 'get_configs':
            handleGetConfigs($input, $db, $accessControl, $sessionManager);
            break;

        // 💾 POST: Save/update a payment provider configuration
        case 'save_config':
            handleSaveConfig($input, $db, $accessControl, $sessionManager, $activityLogger);
            break;

        // 🧪 POST: Test a payment provider connection
        case 'test_connection':
            handleTestConnection($input, $db, $accessControl, $sessionManager, $activityLogger);
            break;

        // ❌ Unknown action — return error
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action. Supported actions: get_configs, save_config, test_connection'
            ]);
            break;
    }
} catch (Exception $e) {
    // ❌ Catch-all error handler — return a safe error message
    // Never expose internal exception details to the client
    // @see https://owasp.org/www-project-web-security-testing-guide/latest/4-Web_Application_Security_Testing/08-Testing_for_Error_Handling/01-Testing_For_Improper_Error_Handling
    error_log('[SIGNula] payment-config-actions.php exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An internal error occurred. Please try again later.'
    ]);
}


// ============================================================================
// ============================================================================
// 🔧 HANDLER FUNCTIONS
// ============================================================================
// ============================================================================


/**
 * ============================================================================
 * 🔍 Handle GET: Retrieve all payment configurations for a partner
 * ============================================================================
 *
 * Fetches the payment provider configurations for Stripe, PayPal, and Coinbase
 * for the specified partner. Returns masked credential values (never exposes
 * full API keys in the response).
 *
 * Required Parameters:
 * - partnerID (int) — The partner whose configs to retrieve
 *
 * Access: Finance role or higher for the specified partner, or super admin
 *
 * @param array          $input          Request input (query parameters or JSON)
 * @param Database       $db             Database singleton instance
 * @param AccessControl  $accessControl  Access control instance
 * @param SessionManager $sessionManager Session manager instance
 *
 * @return void Outputs JSON response and exits
 *
 * @see PartnerPaymentService::getPartnerPaymentConfig()
 */
function handleGetConfigs(
    array $input,
    $db,
    AccessControl $accessControl,
    $sessionManager
): void {
    // 📋 Extract and validate the partner ID
    $partnerID = (int)($input['partnerID'] ?? 0);

    if ($partnerID <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Valid partner ID is required'
        ]);
        return;
    }

    // 🛡️ Verify the user has finance role or higher for this partner
    // canManagePartnerPayments() returns true for finance(20)+ or super admin
    // @see AccessControl::canManagePartnerPayments()
    if (!$accessControl->canManagePartnerPayments($partnerID)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Insufficient permissions. Finance role or higher required.'
        ]);
        return;
    }

    // 🔍 Check that PartnerPaymentService is available
    if (!class_exists('PartnerPaymentService')) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Payment service is not available. Please contact support.'
        ]);
        return;
    }

    // 💳 Fetch configurations for all three providers
    // Each returns null if no config exists, or an array with decrypted keys
    $providers = ['stripe', 'paypal', 'coinbase'];
    $configs = [];

    foreach ($providers as $provider) {
        $config = PartnerPaymentService::getPartnerPaymentConfig($partnerID, $provider);

        if ($config !== null) {
            // 🔒 Mask credential values before sending to client
            // Never expose full API keys in API responses
            $config['apiKey']         = maskCredentialValue($config['apiKey'] ?? '');
            $config['apiSecret']      = maskCredentialValue($config['apiSecret'] ?? '');
            $config['webhookSecret']  = maskCredentialValue($config['webhookSecret'] ?? '');
            $config['publishableKey'] = maskCredentialValue($config['publishableKey'] ?? '');
        }

        $configs[$provider] = $config;
    }

    // ✅ Return the configurations
    echo json_encode([
        'success' => true,
        'message' => 'Payment configurations retrieved successfully',
        'data'    => $configs
    ]);
}


/**
 * ============================================================================
 * 💾 Handle POST: Save/update a payment provider configuration
 * ============================================================================
 *
 * Saves or updates the payment provider configuration for the specified partner.
 * Accepts the payment mode (own_keys vs signula_keys), API credentials,
 * environment mode, and enabled state.
 *
 * Required Parameters:
 * - partnerID    (int)    — The partner whose config to save
 * - provider     (string) — Payment provider: 'stripe', 'paypal', or 'coinbase'
 * - usesOwnKeys  (bool)  — true for Option 2a (own keys), false for Option 2b
 *
 * Optional Parameters:
 * - apiKey         (string) — API key / Client ID
 * - apiSecret      (string) — API secret / Client Secret
 * - webhookSecret  (string) — Webhook signing secret
 * - publishableKey (string) — Publishable key (Stripe only)
 * - mode           (string) — Environment: 'sandbox' or 'live'
 * - isEnabled      (bool)   — Whether this provider is enabled
 *
 * Access: Finance role or higher for the specified partner, or super admin
 *
 * @param array           $input          JSON request body
 * @param Database        $db             Database singleton instance
 * @param AccessControl   $accessControl  Access control instance
 * @param SessionManager  $sessionManager Session manager instance
 * @param ActivityLogger  $activityLogger Activity logger instance
 *
 * @return void Outputs JSON response and exits
 *
 * @see PartnerPaymentService::savePartnerPaymentConfig()
 */
function handleSaveConfig(
    array $input,
    $db,
    AccessControl $accessControl,
    $sessionManager,
    $activityLogger
): void {
    // ========================================================================
    // 📋 INPUT EXTRACTION & VALIDATION
    // ========================================================================

    // 📋 Extract required parameters
    $partnerID   = (int)($input['partnerID'] ?? 0);
    $provider    = trim($input['provider'] ?? '');
    $usesOwnKeys = (bool)($input['usesOwnKeys'] ?? false);

    // 📋 Extract optional credential parameters
    $apiKey         = trim($input['apiKey'] ?? '');
    $apiSecret      = trim($input['apiSecret'] ?? '');
    $webhookSecret  = trim($input['webhookSecret'] ?? '');
    $publishableKey = trim($input['publishableKey'] ?? '');

    // 📋 Extract optional settings
    $mode      = trim($input['mode'] ?? 'sandbox');
    $isEnabled = (bool)($input['isEnabled'] ?? false);

    // 🛡️ Validate required parameters
    if ($partnerID <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Valid partner ID is required'
        ]);
        return;
    }

    // 🛡️ Validate provider is one of the supported values
    $validProviders = ['stripe', 'paypal', 'coinbase'];
    if (!in_array($provider, $validProviders, true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid payment provider. Supported: ' . implode(', ', $validProviders)
        ]);
        return;
    }

    // 🛡️ Validate environment mode
    $validModes = ['sandbox', 'live'];
    if (!in_array($mode, $validModes, true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid environment mode. Supported: ' . implode(', ', $validModes)
        ]);
        return;
    }

    // ========================================================================
    // 🔒 AUTHORISATION CHECK
    // ========================================================================

    // 🛡️ Verify the user has finance role or higher for this partner
    // @see AccessControl::canManagePartnerPayments()
    if (!$accessControl->canManagePartnerPayments($partnerID)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Insufficient permissions. Finance role or higher required.'
        ]);
        return;
    }

    // ========================================================================
    // 🔍 CHECK SERVICE AVAILABILITY
    // ========================================================================

    if (!class_exists('PartnerPaymentService')) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Payment service is not available. Please contact support.'
        ]);
        return;
    }

    // ========================================================================
    // 🔒 CREDENTIAL FILTERING — Skip masked/placeholder values
    // ========================================================================

    // 🔒 If credentials contain bullet characters (masked values from UI),
    // we need to preserve the existing stored credentials rather than
    // overwriting them with the masked display values.
    // The UI pre-fills fields with "••••••••abcd" format for existing creds.
    $bulletChar = "\u{2022}"; // Unicode bullet character used in masking

    // 🔍 Fetch existing config to merge with (preserve existing creds if not changed)
    $existingConfig = PartnerPaymentService::getPartnerPaymentConfig($partnerID, $provider);

    // 🔑 Only update credentials if the user provided new (non-masked) values
    // If the field contains bullet characters, the user hasn't changed it
    if (strpos($apiKey, $bulletChar) !== false || $apiKey === '') {
        $apiKey = $existingConfig['apiKey'] ?? '';
    }

    if (strpos($apiSecret, $bulletChar) !== false || $apiSecret === '') {
        $apiSecret = $existingConfig['apiSecret'] ?? '';
    }

    if (strpos($webhookSecret, $bulletChar) !== false || $webhookSecret === '') {
        $webhookSecret = $existingConfig['webhookSecret'] ?? '';
    }

    if (strpos($publishableKey, $bulletChar) !== false || $publishableKey === '') {
        $publishableKey = $existingConfig['publishableKey'] ?? '';
    }

    // ========================================================================
    // 💾 SAVE CONFIGURATION
    // ========================================================================

    // 🔀 Determine the mode string for PartnerPaymentService
    // 'own_keys' = partner uses their own API keys (Option 2a)
    // 'signula_keys' = partner uses SIGNula's keys (Option 2b)
    $modeString = $usesOwnKeys ? 'own_keys' : 'signula_keys';

    // 📦 Build the credentials array for PartnerPaymentService
    $credentials = [
        'apiKey'         => $apiKey,
        'apiSecret'      => $apiSecret,
        'webhookSecret'  => $webhookSecret,
        'publishableKey' => $publishableKey,
    ];

    // 📦 Build the options array
    $options = [
        'isTestMode' => ($mode === 'sandbox'),
    ];

    // 💾 Save the configuration via PartnerPaymentService
    // This encrypts credentials and performs an upsert into tblPartnerPaymentConfig
    // @see PartnerPaymentService::savePartnerPaymentConfig()
    $result = PartnerPaymentService::savePartnerPaymentConfig(
        $partnerID,
        $provider,
        $modeString,
        $credentials,
        $options
    );

    // ========================================================================
    // 🔘 UPDATE ENABLED STATE (if save was successful)
    // ========================================================================

    if ($result['success']) {
        // 💾 Update the isEnabled flag separately
        // The savePartnerPaymentConfig always sets isEnabled=1 (on save),
        // so we need to explicitly set it to the user's chosen value
        // @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
        $stmt = $db->prepare(
            "UPDATE tblPartnerPaymentConfig SET isEnabled = ? WHERE partnerID = ? AND provider = ?"
        );
        $enabledInt = $isEnabled ? 1 : 0;
        $stmt->bind_param('iis', $enabledInt, $partnerID, $provider);
        $stmt->execute();
    }

    // ========================================================================
    // 📝 ACTIVITY LOGGING
    // ========================================================================

    if ($result['success']) {
        // 📝 Log the admin action for the audit trail
        // @see AccessControl::logAdminAction()
        $accessControl->logAdminAction('save_payment_config', 'partner_payment', $partnerID, [
            'provider'    => $provider,
            'mode'        => $modeString,
            'envMode'     => $mode,
            'isEnabled'   => $isEnabled,
            'usesOwnKeys' => $usesOwnKeys,
            'hasApiKey'   => !empty($apiKey),
            'hasSecret'   => !empty($apiSecret),
        ]);

        // 📝 Log activity via ActivityLogger for system-wide auditing
        // @see ActivityLogger::logActivity()
        $activityLogger->logActivity(
            'partner_payment_config_saved',
            'Payment config saved for partner ' . $partnerID . ': ' . $provider . ' (' . $modeString . ')',
            [
                'partnerID'   => $partnerID,
                'provider'    => $provider,
                'mode'        => $modeString,
                'isEnabled'   => $isEnabled,
            ]
        );
    }

    // ✅ Return the result
    echo json_encode($result);
}


/**
 * ============================================================================
 * 🧪 Handle POST: Test a payment provider connection
 * ============================================================================
 *
 * Tests the partner's stored API credentials by attempting a live connection
 * to the payment provider's API. Only meaningful when the partner uses their
 * own keys (Option 2a). For partners using SIGNula's keys (Option 2b), the
 * test returns success without actually testing.
 *
 * On success, updates isVerified=1 and verifiedAt=NOW() in tblPartnerPaymentConfig.
 * On failure, sets isVerified=0 and verifiedAt=NULL.
 *
 * Required Parameters:
 * - partnerID (int)    — The partner whose credentials to test
 * - provider  (string) — Payment provider: 'stripe', 'paypal', or 'coinbase'
 *
 * Access: Finance role or higher for the specified partner, or super admin
 *
 * @param array           $input          JSON request body
 * @param Database        $db             Database singleton instance
 * @param AccessControl   $accessControl  Access control instance
 * @param SessionManager  $sessionManager Session manager instance
 * @param ActivityLogger  $activityLogger Activity logger instance
 *
 * @return void Outputs JSON response and exits
 *
 * @see PartnerPaymentService::testPartnerConnection()
 * @see StripeProvider::testConnection()
 * @see PayPalProvider::testConnection()
 * @see CoinbaseProvider::testConnection()
 */
function handleTestConnection(
    array $input,
    $db,
    AccessControl $accessControl,
    $sessionManager,
    $activityLogger
): void {
    // ========================================================================
    // 📋 INPUT EXTRACTION & VALIDATION
    // ========================================================================

    $partnerID = (int)($input['partnerID'] ?? 0);
    $provider  = trim($input['provider'] ?? '');

    // 🛡️ Validate partner ID
    if ($partnerID <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Valid partner ID is required'
        ]);
        return;
    }

    // 🛡️ Validate provider
    $validProviders = ['stripe', 'paypal', 'coinbase'];
    if (!in_array($provider, $validProviders, true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid payment provider. Supported: ' . implode(', ', $validProviders)
        ]);
        return;
    }

    // ========================================================================
    // 🔒 AUTHORISATION CHECK
    // ========================================================================

    // 🛡️ Verify the user has finance role or higher for this partner
    if (!$accessControl->canManagePartnerPayments($partnerID)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Insufficient permissions. Finance role or higher required.'
        ]);
        return;
    }

    // ========================================================================
    // 🔍 CHECK SERVICE AVAILABILITY
    // ========================================================================

    if (!class_exists('PartnerPaymentService')) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Payment service is not available. Please contact support.'
        ]);
        return;
    }

    // ========================================================================
    // 🧪 TEST CONNECTION
    // ========================================================================

    // 🧪 Delegate to PartnerPaymentService::testPartnerConnection()
    // This method:
    // 1. Fetches the partner's config with decrypted keys
    // 2. Routes to the correct provider's test method (Stripe/PayPal/Coinbase)
    // 3. Updates the isVerified and verifiedAt columns in the database
    // 4. Logs the test result
    // @see PartnerPaymentService::testPartnerConnection()
    $result = PartnerPaymentService::testPartnerConnection($partnerID, $provider);

    // ========================================================================
    // 📝 ACTIVITY LOGGING
    // ========================================================================

    // 📝 Log the admin action for the audit trail
    $accessControl->logAdminAction('test_payment_connection', 'partner_payment', $partnerID, [
        'provider' => $provider,
        'success'  => $result['success'],
        'message'  => $result['message'] ?? '',
    ]);

    // 📝 Log via ActivityLogger for system-wide auditing
    $activityLogger->logActivity(
        $result['success'] ? 'partner_payment_test_success' : 'partner_payment_test_failed',
        'Payment connection test for partner ' . $partnerID . ': ' . $provider
            . ' — ' . ($result['success'] ? 'SUCCESS' : 'FAILED'),
        [
            'partnerID' => $partnerID,
            'provider'  => $provider,
            'success'   => $result['success'],
        ]
    );

    // ✅ Return the test result
    echo json_encode($result);
}


// ============================================================================
// ============================================================================
// 🔧 UTILITY FUNCTIONS
// ============================================================================
// ============================================================================


/**
 * 🔒 Mask a credential value for safe transmission in API responses
 *
 * Replaces all but the last 4 characters of a credential string with bullet
 * characters. This allows UI display of "which key is configured" without
 * exposing the full secret value.
 *
 * @param string|null $value The credential value to mask
 * @return string Masked credential (e.g., "••••••••abcd") or empty string
 *
 * @see https://www.php.net/manual/en/function.str-repeat.php
 * @see https://www.php.net/manual/en/function.substr.php
 */
function maskCredentialValue(?string $value): string
{
    // ❌ Return empty if no value provided
    if (empty($value)) {
        return '';
    }

    // 🔒 Show bullets + last 4 characters for identification
    $visibleChars = 4;
    $masked = str_repeat("\u{2022}", 8); // 8 bullet characters (Unicode bullet: U+2022)

    if (strlen($value) > $visibleChars) {
        $masked .= substr($value, -$visibleChars);
    } else {
        // 🔒 Very short values: mask entirely for safety
        $masked .= str_repeat("\u{2022}", strlen($value));
    }

    return $masked;
}
