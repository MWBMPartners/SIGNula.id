<?php
/**
 * ============================================================================
 * 📊 SIGNula - Partner Usage Management API Endpoint
 * ============================================================================
 *
 * JSON API endpoint for managing partner-scoped usage metrics, rates,
 * customer usage records, and billing summaries. Called via AJAX from
 * the admin UI at /partners/admin/usage-metrics.php.
 *
 * All operations are scoped to the authenticated partner — partners can
 * only view/modify their own metrics, rates, and customer data.
 *
 * Supported Actions:
 * ──────────────────────────────────────────────────────────────────────────
 * GET  get_metrics            → List metrics (global + this partner's)
 * POST create_metric          → Create a partner-specific metric
 * POST update_metric          → Update a partner-owned metric only
 * POST delete_metric          → Soft-delete a partner-owned metric only
 * GET  get_rates              → List rates for this partner's tiers only
 * POST create_rate            → Create a rate for a partner tier
 * POST update_rate            → Update a rate for a partner tier
 * POST delete_rate            → Soft-delete a rate for a partner tier
 * GET  get_customer_usage     → View usage records for partner's customers
 * GET  get_customer_summaries → View billing summaries for partner's subs
 * GET  get_summary_detail     → View a specific summary with line items
 * GET  get_stats              → Partner-scoped usage statistics
 * ──────────────────────────────────────────────────────────────────────────
 *
 * Security:
 * - All actions require authenticated session (SessionManager::isLoggedIn())
 * - All actions require partner admin role (AccessControl::isPartnerAdmin())
 * - POST actions require valid CSRF token (SecurityUtils::verifyCSRFToken())
 * - Partner ownership is verified before any write operation
 * - All user-supplied data is sanitised and parameterised (prepared statements)
 *
 * Database Tables:
 *   - tblUsageMetrics        (metric definitions)
 *   - tblUsageRates          (per-tier pricing)
 *   - tblUsageRecords        (usage events)
 *   - tblUsageBillingSummary (billing summaries)
 *   - tblPartnerSubscriptionTiers (tier ownership verification)
 *
 * Admin UI: /partners/admin/usage-metrics.php
 *
 * @see /web/public_html/partners/admin/usage-metrics.php  Frontend UI consumer
 * @see /_database/migrations/018_usage_billing.sql         Schema definition
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Partners\API
 * @version    1.0.0
 * @since      2.7.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 📦 DEPENDENCY LOADING
// ============================================================================

// 🔧 Load core configuration (defines SIGNULA_INIT constant, DB credentials, etc.)
// dirname(__DIR__, 3) resolves to /web/ from /web/public_html/partners/api/
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton — provides MySQLi prepared statement interface
// @see https://www.php.net/manual/en/book.mysqli.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🔐 Session management — handles login state, session tokens, regeneration
// @see https://www.php.net/manual/en/book.session.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🛡️ Access control — role-based permission checks for partner admin actions
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 📝 Activity logger — logs all admin actions for audit trail
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'ActivityLogger.php';

// ============================================================================
// 📡 RESPONSE CONFIGURATION
// ============================================================================

// 📋 Set Content-Type header to JSON for all responses from this endpoint
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
header('Content-Type: application/json');

// 🔒 Security headers to prevent MIME type sniffing
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Content-Type-Options
header('X-Content-Type-Options: nosniff');

// ============================================================================
// 🚀 SERVICE INITIALISATION
// ============================================================================

// 📡 Instantiate core services via singleton/DI pattern
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);
$activityLogger = new ActivityLogger($db, $sessionManager);

// ============================================================================
// 🔒 AUTHENTICATION CHECK
// ============================================================================

// 🚫 Reject unauthenticated requests with HTTP 401 Unauthorized
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/401
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized — please log in to continue'
    ]);
    exit;
}

// ============================================================================
// 📥 REQUEST PARSING
// ============================================================================

// 📋 Determine the action based on request method
// GET requests: action from URL parameter
// POST requests: action from JSON body
// @see https://www.php.net/manual/en/reserved.variables.server.php
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 🔍 GET requests — read-only operations
    $action = $_GET['action'] ?? '';
    $input = $_GET; // Use URL parameters as input data
} else {
    // 📝 POST requests — state-changing operations
    // Read JSON body from php://input stream
    // @see https://www.php.net/manual/en/wrappers.php.php#wrappers.php.input
    $input = json_decode(file_get_contents('php://input'), true);

    // 🛡️ Validate that the JSON body was parsed successfully
    if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON in request body'
        ]);
        exit;
    }

    $action = $input['action'] ?? '';
}

// ============================================================================
// 🛡️ CSRF VERIFICATION (POST REQUESTS ONLY)
// ============================================================================

// 🔐 Verify CSRF token for all state-changing (POST) requests to prevent
// cross-site request forgery attacks.
// @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
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
// 🏢 PARTNER ID EXTRACTION & ADMIN VERIFICATION
// ============================================================================

// 📋 Extract partnerID from the request (required for all actions)
$partnerID = (int) ($input['partnerID'] ?? 0);

if ($partnerID <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Partner ID is required'
    ]);
    exit;
}

// 🛡️ Verify the current user has admin-level access for this partner
// This is enforced globally so every handler benefits from this check
if (!$accessControl->isPartnerAdmin($partnerID)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Admin access required to manage usage metrics and billing'
    ]);
    exit;
}

// ============================================================================
// 🎯 ACTION ROUTING
// ============================================================================

// 📋 Route the request to the appropriate handler function based on action
// Each handler is responsible for its own input validation
try {
    switch ($action) {
        // ────────────── METRICS ──────────────

        // 🔍 GET: Retrieve all metrics (global + partner-specific)
        case 'get_metrics':
            handleGetMetrics($partnerID, $db);
            break;

        // ➕ POST: Create a partner-specific metric
        case 'create_metric':
            handleCreateMetric($partnerID, $input, $db, $activityLogger);
            break;

        // ✏️ POST: Update a partner-owned metric
        case 'update_metric':
            handleUpdateMetric($partnerID, $input, $db, $activityLogger);
            break;

        // 🗑️ POST: Soft-delete a partner-owned metric
        case 'delete_metric':
            handleDeleteMetric($partnerID, $input, $db, $activityLogger);
            break;

        // ────────────── RATES ──────────────

        // 🔍 GET: Retrieve rates for this partner's tiers
        case 'get_rates':
            handleGetRates($partnerID, $db);
            break;

        // ➕ POST: Create a rate for a partner tier
        case 'create_rate':
            handleCreateRate($partnerID, $input, $db, $activityLogger);
            break;

        // ✏️ POST: Update a rate for a partner tier
        case 'update_rate':
            handleUpdateRate($partnerID, $input, $db, $activityLogger);
            break;

        // 🗑️ POST: Soft-delete a rate for a partner tier
        case 'delete_rate':
            handleDeleteRate($partnerID, $input, $db, $activityLogger);
            break;

        // ────────────── CUSTOMER DATA (Read-Only) ──────────────

        // 📝 GET: View usage records for partner's customers
        case 'get_customer_usage':
            handleGetCustomerUsage($partnerID, $input, $db);
            break;

        // 🧾 GET: View billing summaries for partner's subscriptions
        case 'get_customer_summaries':
            handleGetCustomerSummaries($partnerID, $input, $db);
            break;

        // 🔍 GET: View a specific billing summary with line items
        case 'get_summary_detail':
            handleGetSummaryDetail($partnerID, $input, $db);
            break;

        // 📊 GET: Partner-scoped usage statistics for dashboard cards
        case 'get_stats':
            handleGetStats($partnerID, $db);
            break;

        // ❌ Unknown action — return a 400 Bad Request error
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action specified: "' . htmlspecialchars($action) . '"'
            ]);
            break;
    }
} catch (Exception $e) {
    // 🚨 Catch-all for unhandled exceptions — return a generic error
    // Never expose internal error details to the client (security best practice)
    // @see https://cheatsheetseries.owasp.org/cheatsheets/Error_Handling_Cheat_Sheet.html
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again later.'
    ]);

    // 📝 Log the full error details internally for debugging
    error_log('[SIGNula] usage-actions.php unhandled exception: ' . $e->getMessage());
}


// ============================================================================
// ============================================================================
// 🔧 HANDLER FUNCTIONS
// ============================================================================
// ============================================================================


/**
 * ============================================================================
 * 🔍 GET METRICS — List all metrics (global + partner-specific)
 * ============================================================================
 *
 * Returns all active and inactive metrics visible to this partner:
 * - Global metrics (partnerID IS NULL) — read-only for the partner
 * - Partner-specific metrics (partnerID = this partner) — editable
 *
 * Response:
 * - success (bool)  — Whether the operation succeeded
 * - data    (array) — Array of metric objects
 * - count   (int)   — Total number of metrics returned
 *
 * @param int      $partnerID The authenticated partner's ID
 * @param Database $db        Database singleton instance
 *
 * @return void Outputs JSON response directly
 */
function handleGetMetrics(int $partnerID, Database $db): void
{
    // 📊 Fetch all metrics that are either global or belong to this partner
    // Ordered by scope (global first) then sortOrder for consistent display
    // @see https://dev.mysql.com/doc/refman/8.0/en/select.html
    $stmt = $db->prepare("
        SELECT metricID, metricCode, metricName, metricDescription,
               unitLabel, billingGranularity, aggregationType,
               partnerID, isActive, sortOrder, createdAt, updatedAt
        FROM tblUsageMetrics
        WHERE partnerID IS NULL OR partnerID = ?
        ORDER BY partnerID IS NOT NULL ASC, sortOrder ASC, metricName ASC
    ");
    $stmt->bind_param('i', $partnerID);
    $stmt->execute();
    $result = $stmt->get_result();

    $metrics = [];
    while ($row = $result->fetch_assoc()) {
        $metrics[] = $row;
    }

    // ✅ Return the metrics data
    echo json_encode([
        'success' => true,
        'data'    => $metrics,
        'count'   => count($metrics)
    ]);
}


/**
 * ============================================================================
 * ➕ CREATE METRIC — Create a partner-specific usage metric
 * ============================================================================
 *
 * Creates a new metric in tblUsageMetrics with partnerID forced to the
 * authenticated partner (prevents a partner from creating metrics for
 * another partner or global metrics).
 *
 * Request Body (JSON):
 * - metricCode         (string, required) — Machine-readable code
 * - metricName         (string, required) — Human-readable display name
 * - metricDescription  (string, optional) — Detailed description
 * - unitLabel          (string, optional) — Display unit label (default: 'units')
 * - billingGranularity (int, optional)    — Bill per N units (default: 1)
 * - aggregationType    (string, optional) — sum, max, or avg (default: 'sum')
 * - sortOrder          (int, optional)    — Display ordering (default: 0)
 *
 * @param int             $partnerID      The authenticated partner's ID
 * @param array           $input          Parsed JSON request body
 * @param Database        $db             Database singleton instance
 * @param ActivityLogger  $activityLogger Activity logging service
 *
 * @return void Outputs JSON response directly
 */
function handleCreateMetric(int $partnerID, array $input, Database $db, ActivityLogger $activityLogger): void
{
    // 📋 Extract and validate required fields
    $metricCode = trim($input['metricCode'] ?? '');
    $metricName = trim($input['metricName'] ?? '');

    if (empty($metricCode)) {
        echo json_encode(['success' => false, 'message' => 'Metric code is required']);
        return;
    }

    if (empty($metricName)) {
        echo json_encode(['success' => false, 'message' => 'Metric name is required']);
        return;
    }

    // 🔒 Sanitise the metric code (lowercase, alphanumeric, underscores only)
    // @see https://www.php.net/manual/en/function.preg-replace.php
    $metricCode = preg_replace('/[^a-z0-9_]/', '', strtolower($metricCode));

    if (empty($metricCode)) {
        echo json_encode(['success' => false, 'message' => 'Invalid metric code — must contain at least one alphanumeric character']);
        return;
    }

    if (strlen($metricCode) > 50) {
        echo json_encode(['success' => false, 'message' => 'Metric code must not exceed 50 characters']);
        return;
    }

    // 📋 Extract optional fields with defaults
    $metricDescription  = trim($input['metricDescription'] ?? '');
    $unitLabel          = trim($input['unitLabel'] ?? 'units');
    $billingGranularity = max(1, (int) ($input['billingGranularity'] ?? 1));
    $sortOrder          = max(0, (int) ($input['sortOrder'] ?? 0));

    // 🔒 Validate aggregation type against allowed ENUM values
    $allowedAggregations = ['sum', 'max', 'avg'];
    $aggregationType = $input['aggregationType'] ?? 'sum';
    if (!in_array($aggregationType, $allowedAggregations, true)) {
        $aggregationType = 'sum';
    }

    // 🔍 Check for duplicate metricCode within this partner's scope
    // The unique constraint is (metricCode, partnerID) so we check manually for a clear error
    $stmt = $db->prepare(
        "SELECT metricID FROM tblUsageMetrics WHERE metricCode = ? AND partnerID = ?"
    );
    $stmt->bind_param('si', $metricCode, $partnerID);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'A metric with code "' . htmlspecialchars($metricCode) . '" already exists for your organisation']);
        return;
    }

    // ➕ Insert the new metric
    // partnerID is FORCED to the authenticated partner (security: prevents elevation)
    // @see https://www.php.net/manual/en/mysqli.prepare.php
    $stmt = $db->prepare("
        INSERT INTO tblUsageMetrics
            (metricCode, metricName, metricDescription, unitLabel, billingGranularity,
             aggregationType, partnerID, isActive, sortOrder)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
    ");
    $stmt->bind_param(
        'ssssisii',
        $metricCode,
        $metricName,
        $metricDescription,
        $unitLabel,
        $billingGranularity,
        $aggregationType,
        $partnerID,
        $sortOrder
    );

    if (!$stmt->execute()) {
        error_log('[SIGNula] Failed to create usage metric: ' . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Failed to create metric. Please try again.']);
        return;
    }

    $newMetricID = $stmt->insert_id;

    // 📝 Log the admin action for audit trail
    $activityLogger->logActivity(
        'partner_usage_metric_created',
        'Usage metric "' . $metricName . '" (code: ' . $metricCode . ') created for partner ' . $partnerID,
        [
            'partnerID'  => $partnerID,
            'metricID'   => $newMetricID,
            'metricCode' => $metricCode,
            'metricName' => $metricName,
        ]
    );

    // ✅ Return success with the new metric ID
    echo json_encode([
        'success'  => true,
        'message'  => 'Metric "' . htmlspecialchars($metricName) . '" created successfully',
        'metricID' => $newMetricID
    ]);
}


/**
 * ============================================================================
 * ✏️ UPDATE METRIC — Update a partner-owned usage metric
 * ============================================================================
 *
 * Updates an existing metric that belongs to this partner. Global metrics
 * (partnerID IS NULL) cannot be updated through this endpoint.
 *
 * Request Body (JSON):
 * - metricID (int, required) — The metric to update
 * - (plus any fields from create_metric to update)
 *
 * @param int             $partnerID      The authenticated partner's ID
 * @param array           $input          Parsed JSON request body
 * @param Database        $db             Database singleton instance
 * @param ActivityLogger  $activityLogger Activity logging service
 *
 * @return void Outputs JSON response directly
 */
function handleUpdateMetric(int $partnerID, array $input, Database $db, ActivityLogger $activityLogger): void
{
    $metricID = (int) ($input['metricID'] ?? 0);

    if ($metricID <= 0) {
        echo json_encode(['success' => false, 'message' => 'Metric ID is required']);
        return;
    }

    // 🔍 Fetch the existing metric and verify ownership
    $stmt = $db->prepare("SELECT * FROM tblUsageMetrics WHERE metricID = ?");
    $stmt->bind_param('i', $metricID);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Metric not found']);
        return;
    }

    // 🛡️ OWNERSHIP CHECK — only partner-specific metrics can be updated
    if ($existing['partnerID'] === null) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Global metrics cannot be edited. Contact a SIGNula administrator.']);
        return;
    }

    if ((int) $existing['partnerID'] !== $partnerID) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This metric does not belong to your organisation']);
        return;
    }

    // 📋 Build the update with only provided fields
    $updates = [];
    $types = '';
    $values = [];

    // 🏷️ Metric Code
    if (isset($input['metricCode'])) {
        $code = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($input['metricCode'])));
        if (empty($code)) {
            echo json_encode(['success' => false, 'message' => 'Invalid metric code']);
            return;
        }
        $updates[] = 'metricCode = ?';
        $types .= 's';
        $values[] = $code;
    }

    // 📋 Metric Name
    if (isset($input['metricName'])) {
        $name = trim($input['metricName']);
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Metric name cannot be empty']);
            return;
        }
        $updates[] = 'metricName = ?';
        $types .= 's';
        $values[] = $name;
    }

    // 📝 Description
    if (array_key_exists('metricDescription', $input)) {
        $updates[] = 'metricDescription = ?';
        $types .= 's';
        $values[] = trim($input['metricDescription'] ?? '');
    }

    // 📏 Unit Label
    if (isset($input['unitLabel'])) {
        $updates[] = 'unitLabel = ?';
        $types .= 's';
        $values[] = trim($input['unitLabel']) ?: 'units';
    }

    // 🔢 Billing Granularity
    if (isset($input['billingGranularity'])) {
        $updates[] = 'billingGranularity = ?';
        $types .= 'i';
        $values[] = max(1, (int) $input['billingGranularity']);
    }

    // 📈 Aggregation Type
    if (isset($input['aggregationType'])) {
        $allowed = ['sum', 'max', 'avg'];
        $agg = $input['aggregationType'];
        if (in_array($agg, $allowed, true)) {
            $updates[] = 'aggregationType = ?';
            $types .= 's';
            $values[] = $agg;
        }
    }

    // 🔢 Sort Order
    if (isset($input['sortOrder'])) {
        $updates[] = 'sortOrder = ?';
        $types .= 'i';
        $values[] = max(0, (int) $input['sortOrder']);
    }

    if (empty($updates)) {
        echo json_encode(['success' => true, 'message' => 'No changes to apply']);
        return;
    }

    // ✏️ Execute the update query
    $sql = "UPDATE tblUsageMetrics SET " . implode(', ', $updates) . " WHERE metricID = ?";
    $types .= 'i';
    $values[] = $metricID;

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);

    if (!$stmt->execute()) {
        error_log('[SIGNula] Failed to update usage metric: ' . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Failed to update metric. Please try again.']);
        return;
    }

    // 📝 Log the admin action
    $activityLogger->logActivity(
        'partner_usage_metric_updated',
        'Usage metric "' . $existing['metricName'] . '" (ID: ' . $metricID . ') updated for partner ' . $partnerID,
        [
            'partnerID'     => $partnerID,
            'metricID'      => $metricID,
            'updatedFields' => array_keys(array_intersect_key($input, array_flip([
                'metricCode', 'metricName', 'metricDescription', 'unitLabel',
                'billingGranularity', 'aggregationType', 'sortOrder'
            ]))),
        ]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Metric updated successfully'
    ]);
}


/**
 * ============================================================================
 * 🗑️ DELETE METRIC — Soft-delete a partner-owned usage metric
 * ============================================================================
 *
 * Deactivates a metric by setting isActive=0. Does NOT perform a hard
 * DELETE because existing usage records and rates reference this metric.
 *
 * Request Body (JSON):
 * - metricID (int, required) — The metric to deactivate
 *
 * @param int             $partnerID      The authenticated partner's ID
 * @param array           $input          Parsed JSON request body
 * @param Database        $db             Database singleton instance
 * @param ActivityLogger  $activityLogger Activity logging service
 *
 * @return void Outputs JSON response directly
 */
function handleDeleteMetric(int $partnerID, array $input, Database $db, ActivityLogger $activityLogger): void
{
    $metricID = (int) ($input['metricID'] ?? 0);

    if ($metricID <= 0) {
        echo json_encode(['success' => false, 'message' => 'Metric ID is required']);
        return;
    }

    // 🔍 Fetch and verify ownership
    $stmt = $db->prepare("SELECT metricID, metricName, partnerID FROM tblUsageMetrics WHERE metricID = ?");
    $stmt->bind_param('i', $metricID);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Metric not found']);
        return;
    }

    // 🛡️ OWNERSHIP CHECK
    if ($existing['partnerID'] === null) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Global metrics cannot be deactivated by partners']);
        return;
    }

    if ((int) $existing['partnerID'] !== $partnerID) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This metric does not belong to your organisation']);
        return;
    }

    // 🗑️ Soft-delete: set isActive = 0
    $stmt = $db->prepare("UPDATE tblUsageMetrics SET isActive = 0 WHERE metricID = ?");
    $stmt->bind_param('i', $metricID);

    if (!$stmt->execute()) {
        error_log('[SIGNula] Failed to deactivate usage metric: ' . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Failed to deactivate metric']);
        return;
    }

    // 📝 Log the admin action
    $activityLogger->logActivity(
        'partner_usage_metric_deactivated',
        'Usage metric "' . $existing['metricName'] . '" (ID: ' . $metricID . ') deactivated for partner ' . $partnerID,
        ['partnerID' => $partnerID, 'metricID' => $metricID, 'metricName' => $existing['metricName']]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Metric "' . htmlspecialchars($existing['metricName']) . '" deactivated successfully'
    ]);
}


/**
 * ============================================================================
 * 🔍 GET RATES — List rates for this partner's tiers only
 * ============================================================================
 *
 * Returns all usage rates that are associated with tiers owned by this
 * partner. Joins tblUsageRates with tblPartnerSubscriptionTiers and
 * tblUsageMetrics for display names.
 *
 * @param int      $partnerID The authenticated partner's ID
 * @param Database $db        Database singleton instance
 *
 * @return void Outputs JSON response directly
 */
function handleGetRates(int $partnerID, Database $db): void
{
    // 💰 Fetch rates joined with tier names and metric names
    // Only returns rates where the tier belongs to this partner
    // @see https://dev.mysql.com/doc/refman/8.0/en/join.html
    $stmt = $db->prepare("
        SELECT ur.rateID, ur.metricID, ur.tierID, ur.tierType,
               ur.ratePerUnit, ur.freeAllowance, ur.overageRate,
               ur.overageCapUnits, ur.currency, ur.isActive,
               ur.effectiveFrom, ur.effectiveUntil,
               ur.createdAt, ur.updatedAt,
               um.metricCode, um.metricName, um.unitLabel,
               pst.tierName
        FROM tblUsageRates ur
        INNER JOIN tblPartnerSubscriptionTiers pst
            ON ur.tierID = pst.tierID AND ur.tierType = 'partner'
        LEFT JOIN tblUsageMetrics um
            ON ur.metricID = um.metricID
        WHERE pst.partnerID = ?
        ORDER BY pst.displayOrder ASC, um.sortOrder ASC, ur.effectiveFrom DESC
    ");
    $stmt->bind_param('i', $partnerID);
    $stmt->execute();
    $result = $stmt->get_result();

    $rates = [];
    while ($row = $result->fetch_assoc()) {
        $rates[] = $row;
    }

    echo json_encode([
        'success' => true,
        'data'    => $rates,
        'count'   => count($rates)
    ]);
}


/**
 * ============================================================================
 * ➕ CREATE RATE — Create a usage rate for a partner tier
 * ============================================================================
 *
 * Creates a new rate in tblUsageRates. Verifies that the specified tier
 * belongs to this partner before allowing creation. The tierType is forced
 * to 'partner' to prevent manipulation.
 *
 * Request Body (JSON):
 * - metricID       (int, required)    — FK to tblUsageMetrics
 * - tierID         (int, required)    — FK to tblPartnerSubscriptionTiers
 * - ratePerUnit    (float, required)  — Cost per billing granularity unit
 * - freeAllowance  (float, optional)  — Included free units per cycle
 * - overageRate    (float, optional)  — Override rate after free allowance
 * - overageCapUnits (float, optional) — Max billable units per cycle
 * - currency       (string, optional) — ISO 4217 code (default: 'GBP')
 * - effectiveFrom  (string, required) — Date rate takes effect (Y-m-d)
 * - effectiveUntil (string, optional) — Date rate expires (Y-m-d)
 *
 * @param int             $partnerID      The authenticated partner's ID
 * @param array           $input          Parsed JSON request body
 * @param Database        $db             Database singleton instance
 * @param ActivityLogger  $activityLogger Activity logging service
 *
 * @return void Outputs JSON response directly
 */
function handleCreateRate(int $partnerID, array $input, Database $db, ActivityLogger $activityLogger): void
{
    // 📋 Extract and validate required fields
    $metricID      = (int) ($input['metricID'] ?? 0);
    $tierID        = (int) ($input['tierID'] ?? 0);
    $ratePerUnit   = (float) ($input['ratePerUnit'] ?? 0);
    $effectiveFrom = trim($input['effectiveFrom'] ?? '');

    if ($metricID <= 0) {
        echo json_encode(['success' => false, 'message' => 'Metric is required']);
        return;
    }

    if ($tierID <= 0) {
        echo json_encode(['success' => false, 'message' => 'Tier is required']);
        return;
    }

    if (empty($effectiveFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
        echo json_encode(['success' => false, 'message' => 'Valid effective-from date is required (YYYY-MM-DD)']);
        return;
    }

    // 🛡️ Verify the tier belongs to this partner
    $stmt = $db->prepare("SELECT tierID FROM tblPartnerSubscriptionTiers WHERE tierID = ? AND partnerID = ?");
    $stmt->bind_param('ii', $tierID, $partnerID);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This tier does not belong to your organisation']);
        return;
    }

    // 🔍 Verify the metric exists and is accessible (global or this partner's)
    $stmt = $db->prepare(
        "SELECT metricID FROM tblUsageMetrics WHERE metricID = ? AND (partnerID IS NULL OR partnerID = ?) AND isActive = 1"
    );
    $stmt->bind_param('ii', $metricID, $partnerID);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Selected metric not found or not available']);
        return;
    }

    // 📋 Extract optional fields
    $freeAllowance  = max(0, (float) ($input['freeAllowance'] ?? 0));
    $overageRate    = isset($input['overageRate']) && $input['overageRate'] !== null ? (float) $input['overageRate'] : null;
    $overageCapUnits = isset($input['overageCapUnits']) && $input['overageCapUnits'] !== null ? (float) $input['overageCapUnits'] : null;
    $effectiveUntil = !empty($input['effectiveUntil']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['effectiveUntil'])
        ? $input['effectiveUntil'] : null;

    // 💱 Validate currency
    $allowedCurrencies = ['GBP', 'USD', 'EUR'];
    $currency = strtoupper(trim($input['currency'] ?? 'GBP'));
    if (!in_array($currency, $allowedCurrencies, true)) {
        $currency = 'GBP';
    }

    // 🔒 Force tierType to 'partner' — this is a partner endpoint
    $tierType = 'partner';

    // ➕ Insert the new rate
    $stmt = $db->prepare("
        INSERT INTO tblUsageRates
            (metricID, tierID, tierType, ratePerUnit, freeAllowance,
             overageRate, overageCapUnits, currency, isActive,
             effectiveFrom, effectiveUntil)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
    ");
    $stmt->bind_param(
        'iisddddsis',
        $metricID,
        $tierID,
        $tierType,
        $ratePerUnit,
        $freeAllowance,
        $overageRate,
        $overageCapUnits,
        $currency,
        $effectiveFrom,
        $effectiveUntil
    );

    if (!$stmt->execute()) {
        error_log('[SIGNula] Failed to create usage rate: ' . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Failed to create rate. Please try again.']);
        return;
    }

    $newRateID = $stmt->insert_id;

    // 📝 Log the admin action
    $activityLogger->logActivity(
        'partner_usage_rate_created',
        'Usage rate created for metric ' . $metricID . ' on tier ' . $tierID . ' for partner ' . $partnerID,
        [
            'partnerID'   => $partnerID,
            'rateID'      => $newRateID,
            'metricID'    => $metricID,
            'tierID'      => $tierID,
            'ratePerUnit' => $ratePerUnit,
        ]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Usage rate created successfully',
        'rateID'  => $newRateID
    ]);
}


/**
 * ============================================================================
 * ✏️ UPDATE RATE — Update a rate for a partner tier
 * ============================================================================
 *
 * Updates an existing rate record. Verifies that the rate's tier belongs
 * to this partner before allowing the update.
 *
 * Request Body (JSON):
 * - rateID (int, required) — The rate to update
 * - (plus any fields from create_rate to update)
 *
 * @param int             $partnerID      The authenticated partner's ID
 * @param array           $input          Parsed JSON request body
 * @param Database        $db             Database singleton instance
 * @param ActivityLogger  $activityLogger Activity logging service
 *
 * @return void Outputs JSON response directly
 */
function handleUpdateRate(int $partnerID, array $input, Database $db, ActivityLogger $activityLogger): void
{
    $rateID = (int) ($input['rateID'] ?? 0);

    if ($rateID <= 0) {
        echo json_encode(['success' => false, 'message' => 'Rate ID is required']);
        return;
    }

    // 🔍 Fetch the existing rate and verify ownership via tier
    $stmt = $db->prepare("
        SELECT ur.*, pst.partnerID AS tierPartnerID
        FROM tblUsageRates ur
        INNER JOIN tblPartnerSubscriptionTiers pst
            ON ur.tierID = pst.tierID AND ur.tierType = 'partner'
        WHERE ur.rateID = ?
    ");
    $stmt->bind_param('i', $rateID);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Rate not found or not associated with a partner tier']);
        return;
    }

    // 🛡️ OWNERSHIP CHECK — verify the tier (and thus rate) belongs to this partner
    if ((int) $existing['tierPartnerID'] !== $partnerID) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This rate does not belong to your organisation']);
        return;
    }

    // 📋 Build the update with only provided fields
    $updates = [];
    $types = '';
    $values = [];

    if (isset($input['ratePerUnit'])) {
        $updates[] = 'ratePerUnit = ?';
        $types .= 'd';
        $values[] = (float) $input['ratePerUnit'];
    }

    if (isset($input['freeAllowance'])) {
        $updates[] = 'freeAllowance = ?';
        $types .= 'd';
        $values[] = max(0, (float) $input['freeAllowance']);
    }

    if (array_key_exists('overageRate', $input)) {
        $updates[] = 'overageRate = ?';
        $types .= 'd';
        $values[] = $input['overageRate'] !== null ? (float) $input['overageRate'] : null;
    }

    if (array_key_exists('overageCapUnits', $input)) {
        $updates[] = 'overageCapUnits = ?';
        $types .= 'd';
        $values[] = $input['overageCapUnits'] !== null ? (float) $input['overageCapUnits'] : null;
    }

    if (isset($input['currency'])) {
        $allowed = ['GBP', 'USD', 'EUR'];
        $cur = strtoupper(trim($input['currency']));
        if (in_array($cur, $allowed, true)) {
            $updates[] = 'currency = ?';
            $types .= 's';
            $values[] = $cur;
        }
    }

    if (isset($input['effectiveFrom']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['effectiveFrom'])) {
        $updates[] = 'effectiveFrom = ?';
        $types .= 's';
        $values[] = $input['effectiveFrom'];
    }

    if (array_key_exists('effectiveUntil', $input)) {
        $updates[] = 'effectiveUntil = ?';
        $types .= 's';
        $values[] = (!empty($input['effectiveUntil']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['effectiveUntil']))
            ? $input['effectiveUntil'] : null;
    }

    // 📊 If new metricID provided, verify it's accessible
    if (isset($input['metricID'])) {
        $newMetricID = (int) $input['metricID'];
        $stmt2 = $db->prepare(
            "SELECT metricID FROM tblUsageMetrics WHERE metricID = ? AND (partnerID IS NULL OR partnerID = ?) AND isActive = 1"
        );
        $stmt2->bind_param('ii', $newMetricID, $partnerID);
        $stmt2->execute();
        if ($stmt2->get_result()->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Selected metric not found or not available']);
            return;
        }
        $updates[] = 'metricID = ?';
        $types .= 'i';
        $values[] = $newMetricID;
    }

    // 🏷️ If new tierID provided, verify ownership
    if (isset($input['tierID'])) {
        $newTierID = (int) $input['tierID'];
        $stmt2 = $db->prepare("SELECT tierID FROM tblPartnerSubscriptionTiers WHERE tierID = ? AND partnerID = ?");
        $stmt2->bind_param('ii', $newTierID, $partnerID);
        $stmt2->execute();
        if ($stmt2->get_result()->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Selected tier does not belong to your organisation']);
            return;
        }
        $updates[] = 'tierID = ?';
        $types .= 'i';
        $values[] = $newTierID;
    }

    if (empty($updates)) {
        echo json_encode(['success' => true, 'message' => 'No changes to apply']);
        return;
    }

    // ✏️ Execute the update
    $sql = "UPDATE tblUsageRates SET " . implode(', ', $updates) . " WHERE rateID = ?";
    $types .= 'i';
    $values[] = $rateID;

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);

    if (!$stmt->execute()) {
        error_log('[SIGNula] Failed to update usage rate: ' . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Failed to update rate. Please try again.']);
        return;
    }

    // 📝 Log the admin action
    $activityLogger->logActivity(
        'partner_usage_rate_updated',
        'Usage rate (ID: ' . $rateID . ') updated for partner ' . $partnerID,
        ['partnerID' => $partnerID, 'rateID' => $rateID]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Rate updated successfully'
    ]);
}


/**
 * ============================================================================
 * 🗑️ DELETE RATE — Soft-delete a rate for a partner tier
 * ============================================================================
 *
 * Deactivates a rate by setting isActive=0. Verifies tier ownership.
 *
 * Request Body (JSON):
 * - rateID (int, required) — The rate to deactivate
 *
 * @param int             $partnerID      The authenticated partner's ID
 * @param array           $input          Parsed JSON request body
 * @param Database        $db             Database singleton instance
 * @param ActivityLogger  $activityLogger Activity logging service
 *
 * @return void Outputs JSON response directly
 */
function handleDeleteRate(int $partnerID, array $input, Database $db, ActivityLogger $activityLogger): void
{
    $rateID = (int) ($input['rateID'] ?? 0);

    if ($rateID <= 0) {
        echo json_encode(['success' => false, 'message' => 'Rate ID is required']);
        return;
    }

    // 🔍 Fetch and verify ownership via tier
    $stmt = $db->prepare("
        SELECT ur.rateID, pst.partnerID AS tierPartnerID
        FROM tblUsageRates ur
        INNER JOIN tblPartnerSubscriptionTiers pst
            ON ur.tierID = pst.tierID AND ur.tierType = 'partner'
        WHERE ur.rateID = ?
    ");
    $stmt->bind_param('i', $rateID);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Rate not found']);
        return;
    }

    if ((int) $existing['tierPartnerID'] !== $partnerID) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This rate does not belong to your organisation']);
        return;
    }

    // 🗑️ Soft-delete: set isActive = 0
    $stmt = $db->prepare("UPDATE tblUsageRates SET isActive = 0 WHERE rateID = ?");
    $stmt->bind_param('i', $rateID);

    if (!$stmt->execute()) {
        error_log('[SIGNula] Failed to deactivate usage rate: ' . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Failed to deactivate rate']);
        return;
    }

    // 📝 Log the admin action
    $activityLogger->logActivity(
        'partner_usage_rate_deactivated',
        'Usage rate (ID: ' . $rateID . ') deactivated for partner ' . $partnerID,
        ['partnerID' => $partnerID, 'rateID' => $rateID]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Rate deactivated successfully'
    ]);
}


/**
 * ============================================================================
 * 📝 GET CUSTOMER USAGE — View usage records for partner's customers
 * ============================================================================
 *
 * Returns paginated usage records from tblUsageRecords where the
 * partnerID matches this partner. Supports date range filtering.
 * Joins with tblUsers and tblUsageMetrics for display names.
 *
 * Query Parameters:
 * - dateFrom (string, optional) — Start date filter (Y-m-d)
 * - dateTo   (string, optional) — End date filter (Y-m-d)
 * - limit    (int, optional)    — Records per page (default: 20, max: 100)
 * - offset   (int, optional)    — Pagination offset (default: 0)
 *
 * @param int      $partnerID The authenticated partner's ID
 * @param array    $input     Request parameters
 * @param Database $db        Database singleton instance
 *
 * @return void Outputs JSON response directly
 */
function handleGetCustomerUsage(int $partnerID, array $input, Database $db): void
{
    // 📄 Pagination parameters
    $limit  = min(100, max(1, (int) ($input['limit'] ?? 20)));
    $offset = max(0, (int) ($input['offset'] ?? 0));

    // 📅 Date filters
    $dateFrom = (!empty($input['dateFrom']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['dateFrom']))
        ? $input['dateFrom'] : null;
    $dateTo = (!empty($input['dateTo']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['dateTo']))
        ? $input['dateTo'] : null;

    // 📊 Build the WHERE clause dynamically based on provided filters
    $where = "ur.partnerID = ?";
    $types = 'i';
    $params = [$partnerID];

    if ($dateFrom !== null) {
        $where .= " AND ur.recordedAt >= ?";
        $types .= 's';
        $params[] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== null) {
        $where .= " AND ur.recordedAt <= ?";
        $types .= 's';
        $params[] = $dateTo . ' 23:59:59';
    }

    // 🔢 Count total records for pagination
    $countStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM tblUsageRecords ur WHERE " . $where);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    // 📝 Fetch the usage records with joins for display names
    $sql = "
        SELECT ur.recordID, ur.userID, ur.metricID, ur.quantity,
               ur.recordedAt, ur.billingPeriodStart, ur.billingPeriodEnd,
               ur.isProcessed, ur.processedAt,
               COALESCE(CONCAT(u.firstName, ' ', u.lastName), CONCAT('User #', ur.userID)) AS userName,
               COALESCE(um.metricName, um.metricCode, 'Unknown') AS metricName,
               um.metricCode
        FROM tblUsageRecords ur
        LEFT JOIN tblUsers u ON ur.userID = u.userID
        LEFT JOIN tblUsageMetrics um ON ur.metricID = um.metricID
        WHERE {$where}
        ORDER BY ur.recordedAt DESC
        LIMIT ? OFFSET ?
    ";

    $types .= 'ii';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'records' => $records,
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'pages'   => max(1, (int) ceil($total / $limit)),
        ]
    ]);
}


/**
 * ============================================================================
 * 🧾 GET CUSTOMER SUMMARIES — View billing summaries for partner's subs
 * ============================================================================
 *
 * Returns paginated billing summaries from tblUsageBillingSummary where
 * the partnerID matches this partner. Supports status filtering.
 *
 * Query Parameters:
 * - status (string, optional) — Filter by status (pending, invoiced, paid, etc.)
 * - limit  (int, optional)    — Records per page (default: 20, max: 100)
 * - offset (int, optional)    — Pagination offset (default: 0)
 *
 * @param int      $partnerID The authenticated partner's ID
 * @param array    $input     Request parameters
 * @param Database $db        Database singleton instance
 *
 * @return void Outputs JSON response directly
 */
function handleGetCustomerSummaries(int $partnerID, array $input, Database $db): void
{
    // 📄 Pagination parameters
    $limit  = min(100, max(1, (int) ($input['limit'] ?? 20)));
    $offset = max(0, (int) ($input['offset'] ?? 0));

    // 📊 Status filter
    $allowedStatuses = ['pending', 'invoiced', 'paid', 'void', 'failed'];
    $status = $input['status'] ?? '';

    // 📊 Build the WHERE clause
    $where = "ubs.partnerID = ?";
    $types = 'i';
    $params = [$partnerID];

    if (!empty($status) && in_array($status, $allowedStatuses, true)) {
        $where .= " AND ubs.status = ?";
        $types .= 's';
        $params[] = $status;
    }

    // 🔢 Count total records
    $countStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM tblUsageBillingSummary ubs WHERE " . $where);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    // 🧾 Fetch summaries with user name
    $sql = "
        SELECT ubs.summaryID, ubs.subscriptionID, ubs.userID, ubs.partnerID,
               ubs.billingPeriodStart, ubs.billingPeriodEnd, ubs.billingMode,
               ubs.totalUsageCost, ubs.tierFixedPrice, ubs.cappedAmount,
               ubs.capApplied, ubs.savingsAmount, ubs.currency, ubs.status,
               ubs.calculatedAt,
               COALESCE(CONCAT(u.firstName, ' ', u.lastName), CONCAT('User #', ubs.userID)) AS userName
        FROM tblUsageBillingSummary ubs
        LEFT JOIN tblUsers u ON ubs.userID = u.userID
        WHERE {$where}
        ORDER BY ubs.billingPeriodStart DESC, ubs.summaryID DESC
        LIMIT ? OFFSET ?
    ";

    $types .= 'ii';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $summaries = [];
    while ($row = $result->fetch_assoc()) {
        $summaries[] = $row;
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'summaries' => $summaries,
            'total'     => $total,
            'limit'     => $limit,
            'offset'    => $offset,
            'pages'     => max(1, (int) ceil($total / $limit)),
        ]
    ]);
}


/**
 * ============================================================================
 * 🔍 GET SUMMARY DETAIL — View a specific billing summary with line items
 * ============================================================================
 *
 * Returns the full detail of a single billing summary, including the
 * lineItems JSON field. Verifies that the summary belongs to this partner.
 *
 * Query Parameters:
 * - summaryID (int, required) — The summary to retrieve
 *
 * @param int      $partnerID The authenticated partner's ID
 * @param array    $input     Request parameters
 * @param Database $db        Database singleton instance
 *
 * @return void Outputs JSON response directly
 */
function handleGetSummaryDetail(int $partnerID, array $input, Database $db): void
{
    $summaryID = (int) ($input['summaryID'] ?? 0);

    if ($summaryID <= 0) {
        echo json_encode(['success' => false, 'message' => 'Summary ID is required']);
        return;
    }

    // 🔍 Fetch the full summary record with user name
    $stmt = $db->prepare("
        SELECT ubs.*,
               COALESCE(CONCAT(u.firstName, ' ', u.lastName), CONCAT('User #', ubs.userID)) AS userName
        FROM tblUsageBillingSummary ubs
        LEFT JOIN tblUsers u ON ubs.userID = u.userID
        WHERE ubs.summaryID = ?
    ");
    $stmt->bind_param('i', $summaryID);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();

    if (!$summary) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Billing summary not found']);
        return;
    }

    // 🛡️ OWNERSHIP CHECK — verify the summary belongs to this partner
    if ($summary['partnerID'] !== null && (int) $summary['partnerID'] !== $partnerID) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This billing summary does not belong to your organisation']);
        return;
    }

    // 📋 Decode the lineItems JSON for display
    if (is_string($summary['lineItems'])) {
        $summary['lineItems'] = json_decode($summary['lineItems'], true) ?? [];
    }

    echo json_encode([
        'success' => true,
        'data'    => $summary
    ]);
}


/**
 * ============================================================================
 * 📊 GET STATS — Partner-scoped usage statistics for dashboard cards
 * ============================================================================
 *
 * Returns aggregate counts for the partner's dashboard stat cards:
 * - Partner-specific metrics count
 * - Active rates count (for partner tiers)
 * - Total usage records count
 * - Total billing summaries count
 *
 * @param int      $partnerID The authenticated partner's ID
 * @param Database $db        Database singleton instance
 *
 * @return void Outputs JSON response directly
 */
function handleGetStats(int $partnerID, Database $db): void
{
    // 📊 Count partner-specific metrics (active)
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM tblUsageMetrics WHERE partnerID = ? AND isActive = 1"
    );
    $stmt->bind_param('i', $partnerID);
    $stmt->execute();
    $partnerMetrics = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    // 💰 Count active rates for partner tiers
    $stmt = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM tblUsageRates ur
        INNER JOIN tblPartnerSubscriptionTiers pst ON ur.tierID = pst.tierID AND ur.tierType = 'partner'
        WHERE pst.partnerID = ? AND ur.isActive = 1
    ");
    $stmt->bind_param('i', $partnerID);
    $stmt->execute();
    $activeRates = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    // 📝 Count usage records for this partner
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM tblUsageRecords WHERE partnerID = ?"
    );
    $stmt->bind_param('i', $partnerID);
    $stmt->execute();
    $usageRecords = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    // 🧾 Count billing summaries for this partner
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM tblUsageBillingSummary WHERE partnerID = ?"
    );
    $stmt->bind_param('i', $partnerID);
    $stmt->execute();
    $billingSummaries = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    echo json_encode([
        'success' => true,
        'data'    => [
            'partnerMetrics'   => $partnerMetrics,
            'activeRates'      => $activeRates,
            'usageRecords'     => $usageRecords,
            'billingSummaries' => $billingSummaries,
        ]
    ]);
}
