<?php
/**
 * ============================================================================
 * 📊 SIGNula - Usage Metrics & Billing Management API (Admin)
 * ============================================================================
 *
 * Purpose: Admin API endpoint for managing usage-based metrics, rates, billing
 *          summaries, and raw usage records. Provides CRUD operations on usage
 *          metrics (tblUsageMetrics), rate configuration (tblUsageRates),
 *          billing summary browsing (tblUsageBillingSummary), and raw record
 *          inspection (tblUsageRecords).
 *
 * Actions (GET):
 *   - get_metrics        — List all usage metrics (global + partner-specific)
 *   - get_rates          — List usage rates for a specific tier
 *   - get_summaries      — List billing summaries with filters (user/partner/status)
 *   - get_summary_detail — Get a single billing summary with line items
 *   - get_stats          — Dashboard statistics for usage billing
 *   - get_usage_records  — Browse raw usage records with filters & pagination
 *
 * Actions (POST):
 *   - create_metric      — Create a new usage metric definition
 *   - update_metric      — Update an existing usage metric
 *   - delete_metric      — Soft-delete a usage metric (set isActive=0)
 *   - create_rate        — Create a new usage rate for a tier
 *   - update_rate        — Update an existing usage rate
 *   - delete_rate        — Soft-delete a usage rate (set isActive=0)
 *   - process_billing    — Trigger usage billing processing batch
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
 * @subpackage Admin\API
 * @version    1.0.0
 * @since      2.7.0-beta
 * @link       https://SIGNula.id
 * @see        https://www.php.net/manual/en/book.mysqli.php (MySQLi Reference)
 * @see        https://owasp.org/www-community/attacks/csrf (OWASP CSRF Prevention)
 * ============================================================================
 */

// ============================================================================
// 🚀 BOOTSTRAP — Load core system files
// ============================================================================

// ⚙️ Main configuration (defines SIGNULA_INIT, path constants, loads settings, initializes session)
// @see web/_config/config.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton (MySQLi prepared statements)
// @see web/_backend/Database.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🎫 Session manager (authentication state)
// @see web/_backend/SessionManager.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🔐 Access control (role-based permissions)
// @see web/_backend/AccessControl.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 📊 UsageTracker class (metric definitions, usage recording & stats)
// @see web/private_html/payments/UsageTracker.php
$usageTrackerPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'UsageTracker.php';

// 📂 Verify the UsageTracker class file exists before loading
// @see https://www.php.net/manual/en/function.file-exists.php
if (file_exists($usageTrackerPath)) {
    require_once $usageTrackerPath;
} else {
    // 🚨 UsageTracker class not found — cannot proceed
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'UsageTracker class not found. Ensure UsageTracker.php exists in the payments directory.'
    ]);
    exit;
}

// 💰 UsageBillingService class (rate management, billing processing, summaries)
// @see web/private_html/payments/UsageBillingService.php
$usageBillingPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'UsageBillingService.php';

// 📂 Verify the UsageBillingService class file exists before loading
// @see https://www.php.net/manual/en/function.file-exists.php
if (file_exists($usageBillingPath)) {
    require_once $usageBillingPath;
} else {
    // 🚨 UsageBillingService class not found — cannot proceed
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'UsageBillingService class not found. Ensure UsageBillingService.php exists in the payments directory.'
    ]);
    exit;
}

// ============================================================================
// 📤 RESPONSE HEADERS
// ============================================================================

// 📤 Set JSON response content type header
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
header('Content-Type: application/json');

// 🛡️ Security headers to prevent caching of sensitive data
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ============================================================================
// 🔐 AUTHENTICATION & AUTHORIZATION
// ============================================================================

// 🗄️ Initialize core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔐 Check authentication — user must be logged in
// @see https://owasp.org/www-project-web-security-testing-guide/ (OWASP Testing Guide)
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 🔐 Require super admin — only super admins can manage usage metrics & billing
// This is the highest privilege level in SIGNula's multi-tier admin system
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
// GET requests use URL query parameters; POST requests use JSON body
// @see https://www.php.net/manual/en/wrappers.php.php (PHP Input/Output Streams)
$input = json_decode(file_get_contents('php://input'), true);

// 🔀 Determine action based on request method
// GET requests use query parameters, POST requests use JSON body
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// 🛡️ CSRF validation for POST requests — prevents cross-site request forgery
// The token is sent either in the JSON body or as a custom HTTP header
// @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Guide
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired CSRF token. Please refresh the page.'
        ]);
        exit;
    }
}

// ============================================================================
// 🗺️ ACTION ROUTER — Dispatch to appropriate handler function
// ============================================================================

try {
    switch ($action) {

        // ═══════════════════════════════════════════════════════
        // 📋 GET ACTIONS — Read-only data retrieval
        // ═══════════════════════════════════════════════════════

        // 📊 List all usage metrics (global + partner-specific)
        case 'get_metrics':
            handleGetMetrics();
            break;

        // 💰 List usage rates for a tier
        case 'get_rates':
            handleGetRates();
            break;

        // 📄 List billing summaries (with filters)
        case 'get_summaries':
            handleGetSummaries();
            break;

        // 🔍 Get a single billing summary with line items
        case 'get_summary_detail':
            handleGetSummaryDetail();
            break;

        // 📈 Dashboard statistics for usage billing
        case 'get_stats':
            handleGetStats();
            break;

        // 📝 Browse raw usage records with filters
        case 'get_usage_records':
            handleGetUsageRecords();
            break;

        // ═══════════════════════════════════════════════════════
        // ⚡ POST ACTIONS — Write operations (CSRF protected)
        // ═══════════════════════════════════════════════════════

        // ➕ Create a new usage metric
        case 'create_metric':
            handleCreateMetric($input);
            break;

        // ✏️ Update an existing usage metric
        case 'update_metric':
            handleUpdateMetric($input);
            break;

        // 🗑️ Soft-delete a usage metric
        case 'delete_metric':
            handleDeleteMetric($input);
            break;

        // ➕ Create a new usage rate
        case 'create_rate':
            handleCreateRate($input);
            break;

        // ✏️ Update an existing usage rate
        case 'update_rate':
            handleUpdateRate($input);
            break;

        // 🗑️ Soft-delete a usage rate
        case 'delete_rate':
            handleDeleteRate($input);
            break;

        // ⚡ Trigger usage billing processing batch
        case 'process_billing':
            handleProcessBilling($input);
            break;

        // 🚫 Unknown action — return error
        default:
            throw new Exception('Invalid action: ' . htmlspecialchars($action));
    }
} catch (Exception $e) {
    // 🚨 Catch-all error handler — return structured error response
    // ⚠️ Never expose raw exception details to the client in production
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}


// ============================================================================
// ============================================================================
// 📋 HANDLER FUNCTIONS
// ============================================================================
// ============================================================================


// ============================================================================
// 📊 HANDLER: get_metrics
// ============================================================================

/**
 * 📊 Retrieve all usage metrics (global + partner-specific).
 *
 * Delegates to UsageTracker::getMetrics() passing true to include inactive
 * metrics. Returns paginated results based on GET parameters.
 *
 * GET Parameters:
 *   - limit (int, optional): Number of records per page (default: 50, max: 100)
 *   - offset (int, optional): Record offset for pagination (default: 0)
 *
 * @return void Outputs JSON response with metrics array and pagination metadata
 *
 * @see UsageTracker::getMetrics() — Backend metric retrieval method
 * @see tblUsageMetrics — Usage metrics definition table
 */
function handleGetMetrics(): void
{
    // 📥 Parse pagination parameters from GET query string
    $limit  = (int) ($_GET['limit'] ?? 50);
    $offset = (int) ($_GET['offset'] ?? 0);

    // 🔒 Clamp limit to safe range (1–100) to prevent excessive queries
    $limit  = max(1, min(100, $limit));
    $offset = max(0, $offset);

    // 📊 Delegate to UsageTracker — pass true to include inactive metrics
    // This returns both active and soft-deleted metrics for admin visibility
    $result = UsageTracker::getMetrics(true);

    // 📦 Extract metrics array from the result
    $allMetrics = $result['metrics'] ?? $result['data'] ?? $result ?? [];

    // 📊 Calculate pagination from the full metrics set
    $total = count($allMetrics);
    $pagedMetrics = array_slice($allMetrics, $offset, $limit);
    $totalPages = ($limit > 0) ? (int) ceil($total / $limit) : 1;

    // ✅ Return structured response with pagination metadata
    echo json_encode([
        'success' => true,
        'data'    => array_values($pagedMetrics),
        'total'   => $total,
        'pages'   => $totalPages,
        'limit'   => $limit,
        'offset'  => $offset,
    ]);
}


// ============================================================================
// ➕ HANDLER: create_metric
// ============================================================================

/**
 * ➕ Create a new usage metric definition.
 *
 * Creates a metric in tblUsageMetrics via UsageTracker::createMetric().
 * Metrics can be global (partnerID=NULL) or partner-specific.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - metricCode (string, required): Unique short code (e.g., 'api_calls', 'storage_gb')
 *   - metricName (string, required): Human-readable metric name
 *   - metricDescription (string, optional): Longer description of the metric
 *   - unitLabel (string, required): Display label for the unit (e.g., 'calls', 'GB')
 *   - billingGranularity (string, required): Granularity level ('hourly','daily','monthly')
 *   - aggregationType (string, required): How to aggregate usage ('sum','max','avg','last')
 *   - partnerID (int, optional): Partner ID for partner-specific metrics (NULL = global)
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response with created metric ID
 *
 * @throws Exception If required fields are missing or validation fails
 *
 * @see UsageTracker::createMetric() — Backend creation method
 * @see tblUsageMetrics — Usage metrics definition table
 */
function handleCreateMetric(?array $input): void
{
    // 📥 Extract and sanitize required fields
    // @see SecurityUtils::sanitizeString() — XSS-safe string sanitization
    $metricCode        = SecurityUtils::sanitizeString($input['metricCode'] ?? '');
    $metricName        = SecurityUtils::sanitizeString($input['metricName'] ?? '');
    $metricDescription = SecurityUtils::sanitizeString($input['metricDescription'] ?? '');
    $unitLabel         = SecurityUtils::sanitizeString($input['unitLabel'] ?? '');
    $billingGranularity = SecurityUtils::sanitizeString($input['billingGranularity'] ?? '');
    $aggregationType   = SecurityUtils::sanitizeString($input['aggregationType'] ?? '');
    $partnerID         = isset($input['partnerID']) && $input['partnerID'] !== ''
                         ? (int) $input['partnerID']
                         : null;

    // ✅ Validate metricCode — must not be empty
    if (empty($metricCode)) {
        throw new Exception('Metric code is required');
    }

    // ✅ Validate metricCode format — alphanumeric and underscores only
    // @see https://www.php.net/manual/en/function.preg-match.php
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $metricCode)) {
        throw new Exception('Metric code must contain only letters, numbers, and underscores');
    }

    // ✅ Validate metricName — must not be empty
    if (empty($metricName)) {
        throw new Exception('Metric name is required');
    }

    // ✅ Validate unitLabel — must not be empty
    if (empty($unitLabel)) {
        throw new Exception('Unit label is required');
    }

    // ✅ Validate billingGranularity — must be a valid granularity value
    $validGranularities = ['hourly', 'daily', 'monthly'];
    if (empty($billingGranularity) || !in_array($billingGranularity, $validGranularities, true)) {
        throw new Exception('Billing granularity is required and must be one of: ' . implode(', ', $validGranularities));
    }

    // ✅ Validate aggregationType — must be a valid aggregation method
    $validAggregations = ['sum', 'max', 'avg', 'last'];
    if (empty($aggregationType) || !in_array($aggregationType, $validAggregations, true)) {
        throw new Exception('Aggregation type is required and must be one of: ' . implode(', ', $validAggregations));
    }

    // ✅ Validate partnerID — if provided, must be a positive integer
    if ($partnerID !== null && $partnerID <= 0) {
        throw new Exception('Partner ID must be a positive integer or left empty for a global metric');
    }

    // ➕ Delegate to UsageTracker for metric creation
    $result = UsageTracker::createMetric([
        'metricCode'        => $metricCode,
        'metricName'        => $metricName,
        'metricDescription' => $metricDescription,
        'unitLabel'         => $unitLabel,
        'billingGranularity' => $billingGranularity,
        'aggregationType'   => $aggregationType,
        'partnerID'         => $partnerID,
    ]);

    // 📊 Check if metric creation was successful
    if (!($result['success'] ?? false)) {
        throw new Exception($result['message'] ?? 'Failed to create usage metric');
    }

    // 📝 Log the metric creation in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'usage_metric_created',
        'admin',
        'info',
        'Usage metric "' . $metricCode . '" (' . $metricName . ') created' . ($partnerID ? ' for partner #' . $partnerID : ' (global)'),
        [
            'metricID'          => $result['metricID'] ?? 0,
            'metricCode'        => $metricCode,
            'metricName'        => $metricName,
            'billingGranularity' => $billingGranularity,
            'aggregationType'   => $aggregationType,
            'partnerID'         => $partnerID,
        ]
    );

    // ✅ Return success response with new metric ID
    echo json_encode([
        'success'  => true,
        'metricID' => $result['metricID'] ?? 0,
        'message'  => 'Usage metric "' . htmlspecialchars($metricCode) . '" created successfully',
    ]);
}


// ============================================================================
// ✏️ HANDLER: update_metric
// ============================================================================

/**
 * ✏️ Update an existing usage metric definition.
 *
 * Updates metric fields in tblUsageMetrics via UsageTracker::updateMetric().
 * Only the provided fields are updated; omitted fields remain unchanged.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - metricID (int, required): The metric ID to update
 *   - metricName (string, optional): Updated metric name
 *   - metricDescription (string, optional): Updated description
 *   - unitLabel (string, optional): Updated unit display label
 *   - billingGranularity (string, optional): Updated granularity ('hourly','daily','monthly')
 *   - aggregationType (string, optional): Updated aggregation method ('sum','max','avg','last')
 *   - isActive (int, optional): Active status (1=active, 0=inactive)
 *   - sortOrder (int, optional): Display sort order
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If metricID is missing or invalid
 *
 * @see UsageTracker::updateMetric() — Backend update method
 * @see tblUsageMetrics — Usage metrics definition table
 */
function handleUpdateMetric(?array $input): void
{
    // ✅ Validate metricID parameter — must be a positive integer
    $metricID = (int) ($input['metricID'] ?? 0);
    if ($metricID <= 0) {
        throw new Exception('Valid metric ID is required');
    }

    // 📥 Build update data array from provided fields
    // Only include fields that were actually sent in the request
    $updateData = [];

    // 📝 Sanitize and include optional string fields if provided
    if (isset($input['metricName'])) {
        $updateData['metricName'] = SecurityUtils::sanitizeString($input['metricName']);
        if (empty($updateData['metricName'])) {
            throw new Exception('Metric name cannot be empty');
        }
    }

    if (isset($input['metricDescription'])) {
        $updateData['metricDescription'] = SecurityUtils::sanitizeString($input['metricDescription']);
    }

    if (isset($input['unitLabel'])) {
        $updateData['unitLabel'] = SecurityUtils::sanitizeString($input['unitLabel']);
        if (empty($updateData['unitLabel'])) {
            throw new Exception('Unit label cannot be empty');
        }
    }

    // ✅ Validate billingGranularity if provided
    if (isset($input['billingGranularity'])) {
        $billingGranularity = SecurityUtils::sanitizeString($input['billingGranularity']);
        $validGranularities = ['hourly', 'daily', 'monthly'];
        if (!in_array($billingGranularity, $validGranularities, true)) {
            throw new Exception('Billing granularity must be one of: ' . implode(', ', $validGranularities));
        }
        $updateData['billingGranularity'] = $billingGranularity;
    }

    // ✅ Validate aggregationType if provided
    if (isset($input['aggregationType'])) {
        $aggregationType = SecurityUtils::sanitizeString($input['aggregationType']);
        $validAggregations = ['sum', 'max', 'avg', 'last'];
        if (!in_array($aggregationType, $validAggregations, true)) {
            throw new Exception('Aggregation type must be one of: ' . implode(', ', $validAggregations));
        }
        $updateData['aggregationType'] = $aggregationType;
    }

    // 🔢 Include isActive flag if provided (must be 0 or 1)
    if (isset($input['isActive'])) {
        $updateData['isActive'] = (int) $input['isActive'] === 1 ? 1 : 0;
    }

    // 🔢 Include sortOrder if provided
    if (isset($input['sortOrder'])) {
        $updateData['sortOrder'] = (int) $input['sortOrder'];
    }

    // ⚠️ Ensure at least one field is being updated
    if (empty($updateData)) {
        throw new Exception('No fields provided for update');
    }

    // ✏️ Delegate to UsageTracker for metric update
    $result = UsageTracker::updateMetric($metricID, $updateData);

    // 📊 Check if update was successful
    if (!($result['success'] ?? false)) {
        throw new Exception($result['message'] ?? 'Failed to update usage metric');
    }

    // 📝 Log the metric update in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'usage_metric_updated',
        'admin',
        'info',
        'Usage metric #' . $metricID . ' updated (fields: ' . implode(', ', array_keys($updateData)) . ')',
        [
            'metricID'     => $metricID,
            'updatedFields' => array_keys($updateData),
        ]
    );

    // ✅ Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Usage metric #' . $metricID . ' updated successfully',
    ]);
}


// ============================================================================
// 🗑️ HANDLER: delete_metric
// ============================================================================

/**
 * 🗑️ Soft-delete a usage metric by setting isActive=0.
 *
 * Delegates to UsageTracker::deleteMetric() which performs a soft-delete
 * rather than a hard delete. The metric remains in the database but is
 * excluded from active usage tracking and rate calculations.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - metricID (int, required): The metric ID to soft-delete
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If metricID is missing or invalid
 *
 * @see UsageTracker::deleteMetric() — Backend soft-delete method
 * @see tblUsageMetrics — Usage metrics definition table
 */
function handleDeleteMetric(?array $input): void
{
    // ✅ Validate metricID parameter
    $metricID = (int) ($input['metricID'] ?? 0);
    if ($metricID <= 0) {
        throw new Exception('Valid metric ID is required');
    }

    // 🗑️ Delegate to UsageTracker for soft-delete
    $result = UsageTracker::deleteMetric($metricID);

    // 📊 Check if deletion was successful
    if (!($result['success'] ?? false)) {
        throw new Exception($result['message'] ?? 'Failed to delete usage metric');
    }

    // 📝 Log the metric deletion in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'usage_metric_deleted',
        'admin',
        'warning',
        'Usage metric #' . $metricID . ' soft-deleted (set isActive=0)',
        [
            'metricID' => $metricID,
        ]
    );

    // ✅ Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Usage metric #' . $metricID . ' has been deactivated',
    ]);
}


// ============================================================================
// 💰 HANDLER: get_rates
// ============================================================================

/**
 * 💰 Retrieve usage rates for a specific tier.
 *
 * Delegates to UsageBillingService::getActiveRatesForTier() which returns
 * all active rate configurations for the given tier (subscription plan,
 * partner tier, or custom tier).
 *
 * GET Parameters:
 *   - tierID (int, required): The tier ID to retrieve rates for
 *   - tierType (string, required): The tier type ('subscription','partner','custom')
 *
 * @return void Outputs JSON response with rates array
 *
 * @throws Exception If required parameters are missing or invalid
 *
 * @see UsageBillingService::getActiveRatesForTier() — Backend rate retrieval
 * @see tblUsageRates — Usage rate configuration table
 */
function handleGetRates(): void
{
    // 📥 Extract and validate required GET parameters
    $tierID   = (int) ($_GET['tierID'] ?? 0);
    $tierType = SecurityUtils::sanitizeString($_GET['tierType'] ?? '');

    // ✅ Validate tierID — must be a positive integer
    if ($tierID <= 0) {
        throw new Exception('Valid tier ID is required');
    }

    // ✅ Validate tierType — must be a recognised tier type
    $validTierTypes = ['subscription', 'partner', 'custom'];
    if (empty($tierType) || !in_array($tierType, $validTierTypes, true)) {
        throw new Exception('Tier type is required and must be one of: ' . implode(', ', $validTierTypes));
    }

    // 💰 Delegate to UsageBillingService for rate retrieval
    $rates = UsageBillingService::getActiveRatesForTier($tierID, $tierType);

    // ✅ Return structured response
    echo json_encode([
        'success' => true,
        'data'    => $rates,
        'total'   => count($rates),
    ]);
}


// ============================================================================
// ➕ HANDLER: create_rate
// ============================================================================

/**
 * ➕ Create a new usage rate for a tier.
 *
 * Creates a rate configuration in tblUsageRates via UsageBillingService::createRate().
 * Rates define the per-unit cost, free allowance, and overage pricing for
 * a specific metric within a specific tier.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - metricID (int, required): The metric this rate applies to
 *   - tierID (int, required): The tier this rate applies to
 *   - tierType (string, required): Tier type ('subscription','partner','custom')
 *   - ratePerUnit (float, required): Cost per unit of usage
 *   - freeAllowance (float, optional): Units included free (default: 0)
 *   - overageRate (float, optional): Per-unit cost after free allowance (default: 0)
 *   - overageCapUnits (float, optional): Maximum billable overage units (NULL = unlimited)
 *   - currency (string, optional): ISO 4217 currency code (default: 'USD')
 *   - effectiveFrom (string, optional): Date the rate becomes active (default: now)
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response with created rate ID
 *
 * @throws Exception If required fields are missing or validation fails
 *
 * @see UsageBillingService::createRate() — Backend rate creation method
 * @see tblUsageRates — Usage rate configuration table
 * @see https://en.wikipedia.org/wiki/ISO_4217 — Currency code standard
 */
function handleCreateRate(?array $input): void
{
    // 📥 Extract and validate required fields
    $metricID       = (int) ($input['metricID'] ?? 0);
    $tierID         = (int) ($input['tierID'] ?? 0);
    $tierType       = SecurityUtils::sanitizeString($input['tierType'] ?? '');
    $ratePerUnit    = (float) ($input['ratePerUnit'] ?? -1);
    $freeAllowance  = (float) ($input['freeAllowance'] ?? 0);
    $overageRate    = (float) ($input['overageRate'] ?? 0);
    $overageCapUnits = isset($input['overageCapUnits']) && $input['overageCapUnits'] !== ''
                       ? (float) $input['overageCapUnits']
                       : null;
    $currency       = SecurityUtils::sanitizeString($input['currency'] ?? 'USD');
    $effectiveFrom  = SecurityUtils::sanitizeString($input['effectiveFrom'] ?? '');

    // ✅ Validate metricID — must be a positive integer
    if ($metricID <= 0) {
        throw new Exception('Valid metric ID is required');
    }

    // ✅ Validate tierID — must be a positive integer
    if ($tierID <= 0) {
        throw new Exception('Valid tier ID is required');
    }

    // ✅ Validate tierType — must be a recognised tier type
    $validTierTypes = ['subscription', 'partner', 'custom'];
    if (empty($tierType) || !in_array($tierType, $validTierTypes, true)) {
        throw new Exception('Tier type is required and must be one of: ' . implode(', ', $validTierTypes));
    }

    // ✅ Validate ratePerUnit — must be zero or positive
    if ($ratePerUnit < 0) {
        throw new Exception('Rate per unit is required and must be zero or a positive number');
    }

    // ✅ Validate freeAllowance — must be zero or positive
    if ($freeAllowance < 0) {
        throw new Exception('Free allowance must be zero or a positive number');
    }

    // ✅ Validate overageRate — must be zero or positive
    if ($overageRate < 0) {
        throw new Exception('Overage rate must be zero or a positive number');
    }

    // ✅ Validate overageCapUnits — if provided, must be positive
    if ($overageCapUnits !== null && $overageCapUnits <= 0) {
        throw new Exception('Overage cap must be a positive number or left empty for unlimited');
    }

    // ✅ Validate currency — must be a 3-letter ISO 4217 code
    // @see https://en.wikipedia.org/wiki/ISO_4217
    if (!preg_match('/^[A-Z]{3}$/', strtoupper($currency))) {
        throw new Exception('Currency must be a valid 3-letter ISO 4217 code (e.g., USD, EUR, GBP)');
    }
    $currency = strtoupper($currency);

    // 📅 Validate effectiveFrom date if provided
    if (!empty($effectiveFrom)) {
        $parsedDate = strtotime($effectiveFrom);
        if ($parsedDate === false) {
            throw new Exception('Invalid effectiveFrom date format. Please use a valid date/time.');
        }
        $effectiveFrom = date('Y-m-d H:i:s', $parsedDate);
    } else {
        // 📅 Default to current timestamp if not provided
        $effectiveFrom = date('Y-m-d H:i:s');
    }

    // ➕ Delegate to UsageBillingService for rate creation
    $result = UsageBillingService::createRate([
        'metricID'        => $metricID,
        'tierID'          => $tierID,
        'tierType'        => $tierType,
        'ratePerUnit'     => $ratePerUnit,
        'freeAllowance'   => $freeAllowance,
        'overageRate'     => $overageRate,
        'overageCapUnits' => $overageCapUnits,
        'currency'        => $currency,
        'effectiveFrom'   => $effectiveFrom,
    ]);

    // 📊 Check if rate creation was successful
    if (!($result['success'] ?? false)) {
        throw new Exception($result['message'] ?? 'Failed to create usage rate');
    }

    // 📝 Log the rate creation in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'usage_rate_created',
        'admin',
        'info',
        'Usage rate created for metric #' . $metricID . ' on ' . $tierType . ' tier #' . $tierID . ' at ' . $currency . ' ' . number_format($ratePerUnit, 4) . '/unit',
        [
            'rateID'        => $result['rateID'] ?? 0,
            'metricID'      => $metricID,
            'tierID'        => $tierID,
            'tierType'      => $tierType,
            'ratePerUnit'   => $ratePerUnit,
            'freeAllowance' => $freeAllowance,
            'currency'      => $currency,
        ]
    );

    // ✅ Return success response with new rate ID
    echo json_encode([
        'success' => true,
        'rateID'  => $result['rateID'] ?? 0,
        'message' => 'Usage rate created successfully',
    ]);
}


// ============================================================================
// ✏️ HANDLER: update_rate
// ============================================================================

/**
 * ✏️ Update an existing usage rate.
 *
 * Updates rate fields in tblUsageRates via UsageBillingService::updateRate().
 * Only provided fields are updated; omitted fields remain unchanged.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - rateID (int, required): The rate ID to update
 *   - ratePerUnit (float, optional): Updated per-unit cost
 *   - freeAllowance (float, optional): Updated free units allowance
 *   - overageRate (float, optional): Updated overage per-unit cost
 *   - overageCapUnits (float, optional): Updated maximum billable overage (NULL = unlimited)
 *   - isActive (int, optional): Active status (1=active, 0=inactive)
 *   - effectiveFrom (string, optional): Updated start date for the rate
 *   - effectiveUntil (string, optional): End date for the rate (NULL = no end)
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If rateID is missing or invalid
 *
 * @see UsageBillingService::updateRate() — Backend rate update method
 * @see tblUsageRates — Usage rate configuration table
 */
function handleUpdateRate(?array $input): void
{
    // ✅ Validate rateID parameter — must be a positive integer
    $rateID = (int) ($input['rateID'] ?? 0);
    if ($rateID <= 0) {
        throw new Exception('Valid rate ID is required');
    }

    // 📥 Build update data array from provided fields
    $updateData = [];

    // 💰 Validate and include ratePerUnit if provided
    if (isset($input['ratePerUnit'])) {
        $ratePerUnit = (float) $input['ratePerUnit'];
        if ($ratePerUnit < 0) {
            throw new Exception('Rate per unit must be zero or a positive number');
        }
        $updateData['ratePerUnit'] = $ratePerUnit;
    }

    // 🎁 Validate and include freeAllowance if provided
    if (isset($input['freeAllowance'])) {
        $freeAllowance = (float) $input['freeAllowance'];
        if ($freeAllowance < 0) {
            throw new Exception('Free allowance must be zero or a positive number');
        }
        $updateData['freeAllowance'] = $freeAllowance;
    }

    // 📈 Validate and include overageRate if provided
    if (isset($input['overageRate'])) {
        $overageRate = (float) $input['overageRate'];
        if ($overageRate < 0) {
            throw new Exception('Overage rate must be zero or a positive number');
        }
        $updateData['overageRate'] = $overageRate;
    }

    // 🔒 Validate and include overageCapUnits if provided
    if (array_key_exists('overageCapUnits', $input)) {
        if ($input['overageCapUnits'] === null || $input['overageCapUnits'] === '') {
            // 🔓 NULL means unlimited overage
            $updateData['overageCapUnits'] = null;
        } else {
            $overageCapUnits = (float) $input['overageCapUnits'];
            if ($overageCapUnits <= 0) {
                throw new Exception('Overage cap must be a positive number or null for unlimited');
            }
            $updateData['overageCapUnits'] = $overageCapUnits;
        }
    }

    // 🔢 Include isActive flag if provided (must be 0 or 1)
    if (isset($input['isActive'])) {
        $updateData['isActive'] = (int) $input['isActive'] === 1 ? 1 : 0;
    }

    // 📅 Validate and include effectiveFrom if provided
    if (isset($input['effectiveFrom'])) {
        $effectiveFrom = SecurityUtils::sanitizeString($input['effectiveFrom']);
        if (!empty($effectiveFrom)) {
            $parsedDate = strtotime($effectiveFrom);
            if ($parsedDate === false) {
                throw new Exception('Invalid effectiveFrom date format');
            }
            $updateData['effectiveFrom'] = date('Y-m-d H:i:s', $parsedDate);
        }
    }

    // 📅 Validate and include effectiveUntil if provided
    if (array_key_exists('effectiveUntil', $input)) {
        if ($input['effectiveUntil'] === null || $input['effectiveUntil'] === '') {
            // 🔓 NULL means no end date (rate remains active indefinitely)
            $updateData['effectiveUntil'] = null;
        } else {
            $effectiveUntil = SecurityUtils::sanitizeString($input['effectiveUntil']);
            $parsedDate = strtotime($effectiveUntil);
            if ($parsedDate === false) {
                throw new Exception('Invalid effectiveUntil date format');
            }
            $updateData['effectiveUntil'] = date('Y-m-d H:i:s', $parsedDate);
        }
    }

    // ⚠️ Ensure at least one field is being updated
    if (empty($updateData)) {
        throw new Exception('No fields provided for update');
    }

    // ✏️ Delegate to UsageBillingService for rate update
    $result = UsageBillingService::updateRate($rateID, $updateData);

    // 📊 Check if update was successful
    if (!($result['success'] ?? false)) {
        throw new Exception($result['message'] ?? 'Failed to update usage rate');
    }

    // 📝 Log the rate update in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'usage_rate_updated',
        'admin',
        'info',
        'Usage rate #' . $rateID . ' updated (fields: ' . implode(', ', array_keys($updateData)) . ')',
        [
            'rateID'        => $rateID,
            'updatedFields' => array_keys($updateData),
        ]
    );

    // ✅ Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Usage rate #' . $rateID . ' updated successfully',
    ]);
}


// ============================================================================
// 🗑️ HANDLER: delete_rate
// ============================================================================

/**
 * 🗑️ Soft-delete a usage rate by setting isActive=0.
 *
 * Delegates to UsageBillingService::deleteRate() which performs a soft-delete.
 * The rate remains in the database for historical billing reference but is
 * excluded from future billing calculations.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - rateID (int, required): The rate ID to soft-delete
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If rateID is missing or invalid
 *
 * @see UsageBillingService::deleteRate() — Backend soft-delete method
 * @see tblUsageRates — Usage rate configuration table
 */
function handleDeleteRate(?array $input): void
{
    // ✅ Validate rateID parameter
    $rateID = (int) ($input['rateID'] ?? 0);
    if ($rateID <= 0) {
        throw new Exception('Valid rate ID is required');
    }

    // 🗑️ Delegate to UsageBillingService for soft-delete
    $result = UsageBillingService::deleteRate($rateID);

    // 📊 Check if deletion was successful
    if (!($result['success'] ?? false)) {
        throw new Exception($result['message'] ?? 'Failed to delete usage rate');
    }

    // 📝 Log the rate deletion in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'usage_rate_deleted',
        'admin',
        'warning',
        'Usage rate #' . $rateID . ' soft-deleted (set isActive=0)',
        [
            'rateID' => $rateID,
        ]
    );

    // ✅ Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Usage rate #' . $rateID . ' has been deactivated',
    ]);
}


// ============================================================================
// 📄 HANDLER: get_summaries
// ============================================================================

/**
 * 📄 Retrieve paginated billing summaries with optional filters.
 *
 * Queries tblUsageBillingSummary directly with dynamic WHERE clauses based
 * on the provided filter parameters. Supports filtering by user, partner,
 * and billing status.
 *
 * GET Parameters:
 *   - userID (int, optional): Filter by specific user
 *   - partnerID (int, optional): Filter by specific partner
 *   - status (string, optional): Filter by billing status ('pending','invoiced','paid','void')
 *   - limit (int, optional): Records per page (default: 25, max: 100)
 *   - offset (int, optional): Record offset for pagination (default: 0)
 *
 * @return void Outputs JSON response with summaries array and pagination metadata
 *
 * @see tblUsageBillingSummary — Billing summary table
 * @see https://dev.mysql.com/doc/refman/8.0/en/select.html — MySQL SELECT
 */
function handleGetSummaries(): void
{
    // 📥 Parse filter and pagination parameters from GET query string
    $userID    = isset($_GET['userID']) && $_GET['userID'] !== '' ? (int) $_GET['userID'] : null;
    $partnerID = isset($_GET['partnerID']) && $_GET['partnerID'] !== '' ? (int) $_GET['partnerID'] : null;
    $status    = isset($_GET['status']) && $_GET['status'] !== '' ? SecurityUtils::sanitizeString($_GET['status']) : null;
    $limit     = (int) ($_GET['limit'] ?? 25);
    $offset    = (int) ($_GET['offset'] ?? 0);

    // 🔒 Clamp limit to safe range (1–100)
    $limit  = max(1, min(100, $limit));
    $offset = max(0, $offset);

    // 🏗️ Build dynamic WHERE clause based on provided filters
    $conditions = [];
    $params     = [];
    $types      = '';

    // 👤 Filter by user ID
    if ($userID !== null && $userID > 0) {
        $conditions[] = 'ubs.userID = ?';
        $params[]     = $userID;
        $types       .= 'i';
    }

    // 🏢 Filter by partner ID
    if ($partnerID !== null && $partnerID > 0) {
        $conditions[] = 'ubs.partnerID = ?';
        $params[]     = $partnerID;
        $types       .= 'i';
    }

    // 📋 Filter by billing status
    if ($status !== null) {
        $validStatuses = ['pending', 'invoiced', 'paid', 'void'];
        if (in_array($status, $validStatuses, true)) {
            $conditions[] = 'ubs.status = ?';
            $params[]     = $status;
            $types       .= 's';
        }
    }

    // 🔗 Assemble the WHERE clause
    $whereClause = !empty($conditions)
        ? 'WHERE ' . implode(' AND ', $conditions)
        : '';

    // 📊 Get total count of matching summaries for pagination
    $countResult = Database::fetchOne(
        "SELECT COUNT(*) AS total
         FROM tblUsageBillingSummary ubs
         {$whereClause}",
        $params,
        $types
    );
    $total = $countResult ? (int) $countResult['total'] : 0;

    // 📋 Fetch paginated summaries with user details via JOIN
    // Add pagination parameters to the existing params array
    $paginatedParams = array_merge($params, [$offset, $limit]);
    $paginatedTypes  = $types . 'ii';

    $summaries = Database::fetchAll(
        "SELECT
            ubs.summaryID,
            ubs.userID,
            ubs.partnerID,
            ubs.billingPeriodStart,
            ubs.billingPeriodEnd,
            ubs.totalAmount,
            ubs.currency,
            ubs.status,
            ubs.invoiceID,
            ubs.processedAt,
            ubs.createdAt,
            u.firstName,
            u.lastName,
            u.emailAddress
         FROM tblUsageBillingSummary ubs
         LEFT JOIN tblUsers u ON ubs.userID = u.userID
         {$whereClause}
         ORDER BY ubs.createdAt DESC
         LIMIT ?, ?",
        $paginatedParams,
        $paginatedTypes
    );

    // 📊 Calculate total pages for pagination
    $totalPages = ($limit > 0) ? (int) ceil($total / $limit) : 1;

    // ✅ Return structured response with pagination metadata
    echo json_encode([
        'success' => true,
        'data'    => $summaries,
        'total'   => $total,
        'pages'   => $totalPages,
        'limit'   => $limit,
        'offset'  => $offset,
    ]);
}


// ============================================================================
// 🔍 HANDLER: get_summary_detail
// ============================================================================

/**
 * 🔍 Retrieve a single billing summary with its line items.
 *
 * Delegates to UsageBillingService::getSummary() which returns the summary
 * header plus all associated line items from tblUsageBillingLineItems.
 *
 * GET Parameters:
 *   - summaryID (int, required): The billing summary ID to retrieve
 *
 * @return void Outputs JSON response with summary header and line items
 *
 * @throws Exception If summaryID is missing or invalid
 *
 * @see UsageBillingService::getSummary() — Backend summary retrieval method
 * @see tblUsageBillingSummary — Billing summary header table
 * @see tblUsageBillingLineItems — Billing line items table
 */
function handleGetSummaryDetail(): void
{
    // 📥 Extract and validate summaryID from GET parameters
    $summaryID = (int) ($_GET['summaryID'] ?? 0);
    if ($summaryID <= 0) {
        throw new Exception('Valid summary ID is required');
    }

    // 🔍 Delegate to UsageBillingService for full summary with line items
    $result = UsageBillingService::getSummary($summaryID);

    // 📊 Check if summary was found
    if (!$result || (!isset($result['summary']) && !isset($result['summaryID']))) {
        throw new Exception('Billing summary #' . $summaryID . ' not found');
    }

    // ✅ Return structured response
    echo json_encode([
        'success' => true,
        'data'    => $result,
    ]);
}


// ============================================================================
// ⚡ HANDLER: process_billing
// ============================================================================

/**
 * ⚡ Trigger usage billing processing batch.
 *
 * Delegates to UsageBillingService::processUsageBilling() which scans for
 * unprocessed usage records, aggregates them by metric and user, applies
 * the appropriate rates, and generates billing summaries with line items.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - batchSize (int, optional): Maximum records to process (default: 100, max: 500)
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response with processing results
 *
 * @see UsageBillingService::processUsageBilling() — Main billing processing method
 * @see tblUsageRecords — Raw usage records to process
 * @see tblUsageBillingSummary — Generated billing summaries
 */
function handleProcessBilling(?array $input): void
{
    // 📥 Parse and validate batch size parameter
    $batchSize = (int) ($input['batchSize'] ?? 100);

    // 🔒 Clamp batch size to safe range (1–500)
    // Prevents excessive processing that could timeout on shared hosting
    $batchSize = max(1, min(500, $batchSize));

    // ⚡ Delegate to UsageBillingService for usage billing processing
    $result = UsageBillingService::processUsageBilling($batchSize);

    // 📝 Log the manual billing processing trigger in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'usage_billing_processed',
        'admin',
        'info',
        'Manual usage billing processing triggered (batch size: ' . $batchSize . ', processed: ' . ($result['processed'] ?? 0) . ', summaries: ' . ($result['summariesCreated'] ?? 0) . ')',
        [
            'batchSize'        => $batchSize,
            'processed'        => $result['processed'] ?? 0,
            'summariesCreated' => $result['summariesCreated'] ?? 0,
            'errors'           => $result['errors'] ?? 0,
        ]
    );

    // ✅ Return structured response with processing results
    echo json_encode([
        'success' => $result['success'] ?? false,
        'data'    => [
            'processed'        => $result['processed'] ?? 0,
            'summariesCreated' => $result['summariesCreated'] ?? 0,
            'errors'           => $result['errors'] ?? 0,
            'details'          => $result['details'] ?? [],
        ],
        'message' => 'Usage billing processing complete: ' . ($result['processed'] ?? 0) . ' records processed, ' . ($result['summariesCreated'] ?? 0) . ' summaries created.',
    ]);
}


// ============================================================================
// 📈 HANDLER: get_stats
// ============================================================================

/**
 * 📈 Retrieve usage billing statistics for the admin dashboard.
 *
 * Combines data from UsageBillingService::getUsageBillingStats() and
 * UsageTracker::getUsageStats() to provide a comprehensive overview of
 * the usage billing system's current state.
 *
 * Returns:
 *   - billingStats: Summary counts, revenue totals, pending amounts
 *   - usageStats: Active metrics count, total records, processing status
 *
 * @return void Outputs JSON response with combined statistics
 *
 * @see UsageBillingService::getUsageBillingStats() — Billing-side statistics
 * @see UsageTracker::getUsageStats() — Usage tracking statistics
 */
function handleGetStats(): void
{
    // 📊 Retrieve billing-side statistics (revenue, summaries, pending amounts)
    $billingStats = UsageBillingService::getUsageBillingStats();

    // 📈 Retrieve usage tracking statistics (metrics count, record volumes)
    $usageStats = UsageTracker::getUsageStats();

    // ✅ Return combined statistics response
    echo json_encode([
        'success' => true,
        'data'    => [
            'billingStats' => $billingStats,
            'usageStats'   => $usageStats,
        ],
    ]);
}


// ============================================================================
// 📝 HANDLER: get_usage_records
// ============================================================================

/**
 * 📝 Browse raw usage records with filters and pagination.
 *
 * Queries tblUsageRecords directly with dynamic WHERE clauses based on
 * the provided filter parameters. Supports filtering by user, metric,
 * partner, date range, and processing status.
 *
 * GET Parameters:
 *   - userID (int, optional): Filter by specific user
 *   - metricID (int, optional): Filter by specific metric
 *   - partnerID (int, optional): Filter by specific partner
 *   - startDate (string, optional): Filter records from this date (inclusive)
 *   - endDate (string, optional): Filter records up to this date (inclusive)
 *   - isProcessed (int, optional): Filter by processing status (0=unprocessed, 1=processed)
 *   - limit (int, optional): Records per page (default: 50, max: 200)
 *   - offset (int, optional): Record offset for pagination (default: 0)
 *
 * @return void Outputs JSON response with records array and pagination metadata
 *
 * @see tblUsageRecords — Raw usage records table
 * @see https://dev.mysql.com/doc/refman/8.0/en/select.html — MySQL SELECT
 * @see https://www.php.net/manual/en/function.strtotime.php — Date parsing
 */
function handleGetUsageRecords(): void
{
    // 📥 Parse filter parameters from GET query string
    $userID      = isset($_GET['userID']) && $_GET['userID'] !== '' ? (int) $_GET['userID'] : null;
    $metricID    = isset($_GET['metricID']) && $_GET['metricID'] !== '' ? (int) $_GET['metricID'] : null;
    $partnerID   = isset($_GET['partnerID']) && $_GET['partnerID'] !== '' ? (int) $_GET['partnerID'] : null;
    $startDate   = isset($_GET['startDate']) && $_GET['startDate'] !== '' ? SecurityUtils::sanitizeString($_GET['startDate']) : null;
    $endDate     = isset($_GET['endDate']) && $_GET['endDate'] !== '' ? SecurityUtils::sanitizeString($_GET['endDate']) : null;
    $isProcessed = isset($_GET['isProcessed']) && $_GET['isProcessed'] !== '' ? (int) $_GET['isProcessed'] : null;
    $limit       = (int) ($_GET['limit'] ?? 50);
    $offset      = (int) ($_GET['offset'] ?? 0);

    // 🔒 Clamp limit to safe range (1–200) for raw records browsing
    $limit  = max(1, min(200, $limit));
    $offset = max(0, $offset);

    // 🏗️ Build dynamic WHERE clause based on provided filters
    $conditions = [];
    $params     = [];
    $types      = '';

    // 👤 Filter by user ID
    if ($userID !== null && $userID > 0) {
        $conditions[] = 'ur.userID = ?';
        $params[]     = $userID;
        $types       .= 'i';
    }

    // 📊 Filter by metric ID
    if ($metricID !== null && $metricID > 0) {
        $conditions[] = 'ur.metricID = ?';
        $params[]     = $metricID;
        $types       .= 'i';
    }

    // 🏢 Filter by partner ID
    if ($partnerID !== null && $partnerID > 0) {
        $conditions[] = 'ur.partnerID = ?';
        $params[]     = $partnerID;
        $types       .= 'i';
    }

    // 📅 Filter by start date (inclusive) — records on or after this date
    if ($startDate !== null) {
        $parsedStart = strtotime($startDate);
        if ($parsedStart !== false) {
            $conditions[] = 'ur.recordedAt >= ?';
            $params[]     = date('Y-m-d 00:00:00', $parsedStart);
            $types       .= 's';
        }
    }

    // 📅 Filter by end date (inclusive) — records on or before this date
    if ($endDate !== null) {
        $parsedEnd = strtotime($endDate);
        if ($parsedEnd !== false) {
            $conditions[] = 'ur.recordedAt <= ?';
            $params[]     = date('Y-m-d 23:59:59', $parsedEnd);
            $types       .= 's';
        }
    }

    // ⚙️ Filter by processing status (0=unprocessed, 1=processed)
    if ($isProcessed !== null) {
        $conditions[] = 'ur.isProcessed = ?';
        $params[]     = $isProcessed === 1 ? 1 : 0;
        $types       .= 'i';
    }

    // 🔗 Assemble the WHERE clause
    $whereClause = !empty($conditions)
        ? 'WHERE ' . implode(' AND ', $conditions)
        : '';

    // 📊 Get total count of matching records for pagination
    $countResult = Database::fetchOne(
        "SELECT COUNT(*) AS total
         FROM tblUsageRecords ur
         {$whereClause}",
        $params,
        $types
    );
    $total = $countResult ? (int) $countResult['total'] : 0;

    // 📋 Fetch paginated usage records with metric and user details via JOINs
    $paginatedParams = array_merge($params, [$offset, $limit]);
    $paginatedTypes  = $types . 'ii';

    $records = Database::fetchAll(
        "SELECT
            ur.recordID,
            ur.userID,
            ur.metricID,
            ur.partnerID,
            ur.quantity,
            ur.recordedAt,
            ur.isProcessed,
            ur.processedAt,
            ur.metadata,
            um.metricCode,
            um.metricName,
            um.unitLabel,
            u.firstName,
            u.lastName,
            u.emailAddress
         FROM tblUsageRecords ur
         LEFT JOIN tblUsageMetrics um ON ur.metricID = um.metricID
         LEFT JOIN tblUsers u ON ur.userID = u.userID
         {$whereClause}
         ORDER BY ur.recordedAt DESC
         LIMIT ?, ?",
        $paginatedParams,
        $paginatedTypes
    );

    // 📊 Calculate total pages for pagination
    $totalPages = ($limit > 0) ? (int) ceil($total / $limit) : 1;

    // ✅ Return structured response with pagination metadata
    echo json_encode([
        'success' => true,
        'data'    => $records,
        'total'   => $total,
        'pages'   => $totalPages,
        'limit'   => $limit,
        'offset'  => $offset,
    ]);
}
