<?php
/**
 * 💸 Remittance Management API (Super Admin)
 *
 * Purpose: Admin API for managing partner remittances (payouts), including listing
 *          pending/processed remittances, processing/rejecting individual payouts,
 *          batch processing, earnings reports, and remittance statistics.
 *
 * Actions (GET):
 *   - get_pending_remittances     : List pending remittances (optional partnerID filter)
 *   - get_processed_remittances   : List processed remittances with pagination
 *   - get_remittance_stats        : Remittance statistics overview
 *   - get_earnings_report         : Earnings report per partner (with date range)
 *   - get_partner_list            : List active partners for filter dropdowns
 *
 * Actions (POST):
 *   - process_remittance  : Process a single remittance (marks as processing/completed)
 *   - reject_remittance   : Reject a remittance (updates status to 'rejected')
 *   - batch_process       : Process multiple remittances in one request
 *
 * @see /web/private_html/payments/ServiceFeeManager.php — Backend service fee logic
 * @see /web/public_html/admin/payments/remittances.php — Frontend UI page
 * @see https://developer.paypal.com/docs/payouts/ — PayPal Payouts API Reference
 * @see https://docs.stripe.com/connect/payouts — Stripe Connect Payouts Reference
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.4.0-beta
 */

// ═══════════════════════════════════════════════════════════════════════════════
// 🔌 BOOTSTRAP — Load core configuration and backend services
// ═══════════════════════════════════════════════════════════════════════════════

// 📦 Load SIGNula core configuration (defines SIGNULA_INIT, DB credentials, etc.)
// @see https://www.php.net/manual/en/function.require-once.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📦 Load backend service classes
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 📦 Load the ServiceFeeManager for remittance business logic
// @see /web/private_html/payments/ServiceFeeManager.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'ServiceFeeManager.php';

// ═══════════════════════════════════════════════════════════════════════════════
// 📤 RESPONSE HEADERS — Set JSON content type for all API responses
// ═══════════════════════════════════════════════════════════════════════════════

// 🔧 Set JSON content type — all responses from this endpoint are JSON
// @see https://www.php.net/manual/en/function.header.php
header('Content-Type: application/json');

// ═══════════════════════════════════════════════════════════════════════════════
// 🔌 INITIALISE CORE SERVICES
// ═══════════════════════════════════════════════════════════════════════════════

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// ═══════════════════════════════════════════════════════════════════════════════
// 🔐 AUTHENTICATION CHECK — Require logged-in user
// ═══════════════════════════════════════════════════════════════════════════════

// 🔒 Check if the user is authenticated — return 401 if not
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/401
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🛡️ SUPER ADMIN AUTHORISATION — Only super admins can manage remittances
// ═══════════════════════════════════════════════════════════════════════════════

// 🔐 Verify super admin status — return 403 if not a super admin
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/403
$userInfo = $sessionManager->getUserInfo();
if (!isset($userInfo['isSuperAdmin']) || $userInfo['isSuperAdmin'] != 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Super admin access required']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 📥 REQUEST PARSING — Determine action from GET or POST request body
// ═══════════════════════════════════════════════════════════════════════════════

// 📥 Parse POST body as JSON for POST requests
// @see https://www.php.net/manual/en/wrappers.php.php
$input = json_decode(file_get_contents('php://input'), true);

// 🎯 Determine the action based on HTTP method
// GET: action from query parameter; POST: action from JSON body
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// ═══════════════════════════════════════════════════════════════════════════════
// 🛡️ CSRF VERIFICATION — Required for all POST requests
// @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Prevention
// ═══════════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔐 Extract CSRF token from JSON body or HTTP header
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    // 🔍 Verify the CSRF token against the session-stored token
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid or expired CSRF token. Please refresh the page.'
        ]);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🎯 ACTION ROUTER — Route to the appropriate handler based on action parameter
// ═══════════════════════════════════════════════════════════════════════════════

try {
    switch ($action) {

        // ═══════════════════════════════════════════════════════════
        // 📋 GET ACTIONS — Read-only data retrieval
        // ═══════════════════════════════════════════════════════════

        case 'get_pending_remittances':
            // ⏳ List pending remittances (optional partner filter)
            handleGetPendingRemittances();
            break;

        case 'get_processed_remittances':
            // ✅ List processed/completed/rejected remittances with pagination
            handleGetProcessedRemittances();
            break;

        case 'get_remittance_stats':
            // 📊 Get remittance overview statistics
            handleGetRemittanceStats();
            break;

        case 'get_earnings_report':
            // 📈 Generate earnings report for a partner or all partners
            handleGetEarningsReport();
            break;

        case 'get_partner_list':
            // 📋 List active partners for filter dropdowns
            handleGetPartnerList();
            break;

        // ═══════════════════════════════════════════════════════════
        // ✏️ POST ACTIONS — Data modification (CSRF required)
        // ═══════════════════════════════════════════════════════════

        case 'process_remittance':
            // ✅ Process a single pending remittance
            handleProcessRemittance($input);
            break;

        case 'reject_remittance':
            // ❌ Reject a pending remittance
            handleRejectRemittance($input);
            break;

        case 'batch_process':
            // 🔄 Batch process multiple pending remittances
            handleBatchProcess($input);
            break;

        default:
            // ⚠️ Unknown action — return error
            throw new Exception('Invalid action: ' . htmlspecialchars($action));
    }
} catch (Exception $e) {
    // ═══════════════════════════════════════════════════════════════
    // ❌ GLOBAL ERROR HANDLER — Catch any unhandled exceptions
    // Returns a consistent error response without exposing internals
    // ═══════════════════════════════════════════════════════════════
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}


// ═══════════════════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════════════════════
//
// 📋 HANDLER FUNCTIONS — Each function handles a specific API action
//
// ═══════════════════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════════════════════


/**
 * ⏳ Get Pending Remittances
 *
 * Retrieves all remittances with status = 'pending', optionally filtered by
 * a specific partner. Uses ServiceFeeManager::getPendingRemittances() for the
 * database query which JOINs tblRemittances with tblPartners for partner names.
 *
 * Query Parameters:
 *   - partnerID (int, optional): Filter to a specific partner
 *
 * @return void Outputs JSON response directly
 *
 * @see ServiceFeeManager::getPendingRemittances()
 * @see tblRemittances — Remittance records table
 * @see tblPartners — Partner master records table
 */
function handleGetPendingRemittances(): void
{
    // 🏢 Optional partner filter from query string
    // @see https://www.php.net/manual/en/function.intval.php
    $partnerID = isset($_GET['partnerID']) && $_GET['partnerID'] !== ''
        ? (int) $_GET['partnerID']
        : null;

    // 📋 Fetch pending remittances via ServiceFeeManager
    // Returns array with partner details (partnerName, billingEmail, payoutEmail, etc.)
    $remittances = ServiceFeeManager::getPendingRemittances($partnerID);

    // 📤 Return JSON response
    echo json_encode([
        'success' => true,
        'data'    => $remittances,
        'count'   => count($remittances),
    ]);
}


/**
 * ✅ Get Processed Remittances
 *
 * Retrieves remittances that are NOT in 'pending' status (i.e., processing,
 * completed, failed, rejected, cancelled), with pagination support and
 * optional partner/status filters. JOINs with tblPartners for partner names
 * and tblUsers for the processedBy admin name.
 *
 * Query Parameters:
 *   - partnerID (int, optional): Filter to a specific partner
 *   - status    (string, optional): Filter to a specific status
 *   - limit     (int, optional): Records per page (default: 25)
 *   - offset    (int, optional): Pagination offset (default: 0)
 *
 * @return void Outputs JSON response directly
 *
 * @see tblRemittances — Remittance records table
 * @see tblPartners — Partner master records table
 * @see tblUsers — User records for processedBy lookup
 */
function handleGetProcessedRemittances(): void
{
    // 📄 Pagination parameters — default 25 per page
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 25)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    // 🏗️ Build dynamic WHERE clause for filtering
    $conditions = ["r.status != 'pending'"];
    $params = [];
    $types = '';

    // 🏢 Optional partner filter
    if (isset($_GET['partnerID']) && $_GET['partnerID'] !== '') {
        $conditions[] = 'r.partnerID = ?';
        $params[] = (int) $_GET['partnerID'];
        $types .= 'i';
    }

    // 📊 Optional status filter
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $conditions[] = 'r.status = ?';
        $params[] = $_GET['status'];
        $types .= 's';
    }

    // 🔗 Combine conditions into WHERE clause
    $whereClause = implode(' AND ', $conditions);

    // ═══════════════════════════════════════════════════════════════
    // 📊 COUNT QUERY — Get total matching records for pagination
    // ═══════════════════════════════════════════════════════════════

    $countResult = Database::fetchOne(
        "SELECT COUNT(*) AS total
         FROM tblRemittances r
         WHERE " . $whereClause,
        $params,
        $types
    );
    $total = $countResult ? (int) $countResult['total'] : 0;

    // 📄 Calculate total pages
    $pages = ($total > 0) ? (int) ceil($total / $limit) : 1;
    $currentPage = ($offset > 0) ? (int) floor($offset / $limit) + 1 : 1;

    // ═══════════════════════════════════════════════════════════════
    // 📋 DATA QUERY — Get paginated results with JOINs
    // JOINs tblPartners for partner name and tblUsers for admin name
    // ═══════════════════════════════════════════════════════════════

    $dataParams = array_merge($params, [$limit, $offset]);
    $dataTypes = $types . 'ii';

    $remittances = Database::fetchAll(
        "SELECT r.*,
                p.partnerName,
                p.billingEmail AS partnerEmail,
                u.displayName AS processedByName
         FROM tblRemittances r
         JOIN tblPartners p ON r.partnerID = p.partnerID
         LEFT JOIN tblUsers u ON r.processedBy = u.userID
         WHERE " . $whereClause . "
         ORDER BY COALESCE(r.processedAt, r.updatedAt, r.createdAt) DESC
         LIMIT ? OFFSET ?",
        $dataParams,
        $dataTypes
    );

    // 📤 Return JSON response with pagination metadata
    echo json_encode([
        'success'     => true,
        'data'        => $remittances,
        'total'       => $total,
        'pages'       => $pages,
        'currentPage' => $currentPage,
        'limit'       => $limit,
        'offset'      => $offset,
    ]);
}


/**
 * 📊 Get Remittance Statistics
 *
 * Returns high-level remittance statistics for the admin dashboard. Uses
 * ServiceFeeManager::getAdminStats() which provides fee collection,
 * remittance, and partner activity metrics.
 *
 * Query Parameters:
 *   - days (int, optional): Lookback period in days (default: 30)
 *
 * @return void Outputs JSON response directly
 *
 * @see ServiceFeeManager::getAdminStats() — Backend statistics method
 */
function handleGetRemittanceStats(): void
{
    // 📅 Lookback period — default 30 days
    $days = max(1, (int) ($_GET['days'] ?? 30));

    // 📊 Fetch admin stats from ServiceFeeManager
    $stats = ServiceFeeManager::getAdminStats($days);

    // 📤 Return JSON response
    echo json_encode([
        'success' => true,
        'data'    => $stats,
    ]);
}


/**
 * 📈 Get Earnings Report
 *
 * Generates an earnings report for a specific partner (or aggregated across
 * all partners) over a given date range. Uses ServiceFeeManager::getEarningsReport()
 * which provides gross, fee, and net totals with a monthly breakdown.
 *
 * If no partnerID is provided, the report aggregates ALL partners' fee transactions
 * using a direct database query (since getEarningsReport() requires a partnerID).
 *
 * Query Parameters:
 *   - partnerID (int, optional): Partner to report on (omit for all partners)
 *   - dateFrom  (string, required): Start date in Y-m-d format
 *   - dateTo    (string, required): End date in Y-m-d format
 *
 * @return void Outputs JSON response directly
 *
 * @see ServiceFeeManager::getEarningsReport() — Backend report method
 * @see tblServiceFeeTransactions — Fee transaction records
 */
function handleGetEarningsReport(): void
{
    // 📅 Date range parameters — required
    $dateFrom = $_GET['dateFrom'] ?? '';
    $dateTo = $_GET['dateTo'] ?? '';

    // 🔍 Validate date inputs
    if (empty($dateFrom) || empty($dateTo)) {
        throw new Exception('Both dateFrom and dateTo parameters are required');
    }

    // 📅 Validate date format (Y-m-d)
    // @see https://www.php.net/manual/en/function.strtotime.php
    if (!strtotime($dateFrom) || !strtotime($dateTo)) {
        throw new Exception('Invalid date format. Please use Y-m-d (e.g. 2026-01-01)');
    }

    // 🏢 Optional partner filter
    $partnerID = isset($_GET['partnerID']) && $_GET['partnerID'] !== ''
        ? (int) $_GET['partnerID']
        : null;

    if ($partnerID !== null) {
        // ═══════════════════════════════════════════════════════════
        // 📊 Single partner report — use ServiceFeeManager method
        // ═══════════════════════════════════════════════════════════
        $report = ServiceFeeManager::getEarningsReport($partnerID, $dateFrom, $dateTo);
    } else {
        // ═══════════════════════════════════════════════════════════
        // 📊 All partners aggregated report — custom query
        // ServiceFeeManager::getEarningsReport() requires a partnerID,
        // so we build a custom aggregation for the "all partners" view.
        // ═══════════════════════════════════════════════════════════

        // 📊 Overall totals across all partners
        $totals = Database::fetchOne(
            "SELECT
                COUNT(*) AS transactionCount,
                COALESCE(SUM(grossAmount), 0) AS totalGross,
                COALESCE(SUM(feeAmount), 0) AS totalFees,
                COALESCE(SUM(netToPartner), 0) AS totalNet,
                MIN(currency) AS currency
             FROM tblServiceFeeTransactions
             WHERE createdAt >= ?
               AND createdAt <= CONCAT(?, ' 23:59:59')",
            [$dateFrom, $dateTo],
            'ss'
        );

        $totalGross = round((float) ($totals['totalGross'] ?? 0), 2);
        $totalFees = round((float) ($totals['totalFees'] ?? 0), 2);
        $totalNet = round((float) ($totals['totalNet'] ?? 0), 2);
        $transactionCount = (int) ($totals['transactionCount'] ?? 0);
        $currency = $totals['currency'] ?? 'GBP';

        // 🧮 Calculate weighted average fee rate
        $averageFeeRate = ($totalGross > 0)
            ? round(($totalFees / $totalGross) * 100, 2)
            : 0.00;

        // 📅 Monthly breakdown across all partners
        // @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html#function_date-format
        $monthlyBreakdown = Database::fetchAll(
            "SELECT
                DATE_FORMAT(createdAt, '%Y-%m') AS month,
                COUNT(*) AS transactionCount,
                COALESCE(SUM(grossAmount), 0) AS grossAmount,
                COALESCE(SUM(feeAmount), 0) AS feeAmount,
                COALESCE(SUM(netToPartner), 0) AS netAmount
             FROM tblServiceFeeTransactions
             WHERE createdAt >= ?
               AND createdAt <= CONCAT(?, ' 23:59:59')
             GROUP BY DATE_FORMAT(createdAt, '%Y-%m')
             ORDER BY month ASC",
            [$dateFrom, $dateTo],
            'ss'
        );

        // 🔢 Ensure numeric types in the monthly breakdown
        foreach ($monthlyBreakdown as &$month) {
            $month['grossAmount'] = round((float) $month['grossAmount'], 2);
            $month['feeAmount'] = round((float) $month['feeAmount'], 2);
            $month['netAmount'] = round((float) $month['netAmount'], 2);
            $month['transactionCount'] = (int) $month['transactionCount'];
        }
        unset($month); // 🔗 Break reference after foreach

        // 📋 Build the report array (same structure as ServiceFeeManager)
        $report = [
            'totalGross'       => $totalGross,
            'totalFees'        => $totalFees,
            'totalNet'         => $totalNet,
            'transactionCount' => $transactionCount,
            'averageFeeRate'   => $averageFeeRate,
            'currency'         => $currency,
            'periodStart'      => $dateFrom,
            'periodEnd'        => $dateTo,
            'monthlyBreakdown' => $monthlyBreakdown,
        ];
    }

    // 📤 Return JSON response
    echo json_encode([
        'success' => true,
        'data'    => $report,
    ]);
}


/**
 * 📋 Get Partner List
 *
 * Returns a list of all active partners for populating filter dropdown menus
 * in the UI. Only returns partnerID and partnerName to keep the response lean.
 *
 * @return void Outputs JSON response directly
 *
 * @see tblPartners — Partner master records table
 */
function handleGetPartnerList(): void
{
    // 📋 Fetch all active partners ordered by name
    $partners = Database::fetchAll(
        "SELECT partnerID, partnerName
         FROM tblPartners
         WHERE status = 'active'
         ORDER BY partnerName ASC",
        [],
        ''
    );

    // 📤 Return JSON response
    echo json_encode([
        'success' => true,
        'data'    => $partners,
    ]);
}


/**
 * ✅ Process a Single Remittance
 *
 * Processes a pending remittance by calling ServiceFeeManager::processRemittance().
 * The logged-in admin's userID is used as the processedBy value. Optionally stores
 * a transaction reference and admin notes in the remittance metadata.
 *
 * POST Body (JSON):
 *   - remittanceID         (int, required): The remittance ID to process
 *   - transactionReference (string, optional): External payment transaction ref
 *   - notes                (string, optional): Admin notes
 *   - csrf_token           (string, required): CSRF protection token
 *
 * @param array $input Parsed JSON request body
 *
 * @return void Outputs JSON response directly
 *
 * @see ServiceFeeManager::processRemittance() — Backend processing logic
 * @see tblRemittances — Remittance records table
 */
function handleProcessRemittance(array $input): void
{
    global $sessionManager;

    // ═══════════════════════════════════════════════════════════════
    // 🔍 INPUT VALIDATION
    // ═══════════════════════════════════════════════════════════════

    // 🔢 Validate remittance ID — must be a positive integer
    $remittanceID = (int) ($input['remittanceID'] ?? 0);
    if ($remittanceID <= 0) {
        throw new Exception('Valid remittance ID is required');
    }

    // 👤 Get the current admin's user ID for processedBy
    $userInfo = $sessionManager->getUserInfo();
    $processedBy = (int) ($userInfo['userID'] ?? 0);

    if ($processedBy <= 0) {
        throw new Exception('Unable to determine admin user ID');
    }

    // ═══════════════════════════════════════════════════════════════
    // ✅ PROCESS THE REMITTANCE via ServiceFeeManager
    // ═══════════════════════════════════════════════════════════════

    $result = ServiceFeeManager::processRemittance($remittanceID, $processedBy);

    // ═══════════════════════════════════════════════════════════════
    // 📝 UPDATE METADATA — Store transaction reference and notes
    // If a transaction reference or notes are provided, update the
    // remittance's metadata JSON column to include them.
    // ═══════════════════════════════════════════════════════════════

    if ($result['success']) {
        $transactionReference = trim($input['transactionReference'] ?? '');
        $notes = trim($input['notes'] ?? '');

        // 📋 Build metadata updates if any additional info was provided
        if ($transactionReference !== '' || $notes !== '') {
            // 📊 Fetch existing metadata to merge with new data
            $existing = Database::fetchOne(
                "SELECT metadata, transactionReference FROM tblRemittances WHERE remittanceID = ?",
                [$remittanceID],
                'i'
            );

            // 🔄 Parse existing metadata JSON (if any)
            $metadata = [];
            if ($existing && !empty($existing['metadata'])) {
                $metadata = json_decode($existing['metadata'], true) ?: [];
            }

            // 📝 Add notes to metadata if provided
            if ($notes !== '') {
                $metadata['notes'] = $notes;
            }

            // 📝 Add transaction reference to metadata and dedicated column
            if ($transactionReference !== '') {
                $metadata['transactionReference'] = $transactionReference;
            }

            // 💾 Update the remittance record with merged metadata
            // @see https://www.php.net/manual/en/function.json-encode.php
            Database::query(
                "UPDATE tblRemittances
                 SET metadata = ?,
                     transactionReference = COALESCE(?, transactionReference),
                     updatedAt = NOW()
                 WHERE remittanceID = ?",
                [
                    json_encode($metadata),
                    $transactionReference !== '' ? $transactionReference : null,
                    $remittanceID,
                ],
                'ssi'
            );

            // 📝 Log the metadata update for audit trail
            ActivityLogger::log(
                $processedBy,
                'remittance_metadata_updated',
                'payment',
                'info',
                'Remittance #' . $remittanceID . ' metadata updated'
                    . ($transactionReference !== '' ? ' (ref: ' . $transactionReference . ')' : ''),
                [
                    'remittanceID'         => $remittanceID,
                    'transactionReference' => $transactionReference,
                    'hasNotes'             => ($notes !== ''),
                ]
            );
        }
    }

    // 📤 Return JSON response
    echo json_encode([
        'success' => $result['success'],
        'message' => $result['message'] ?? ($result['success'] ? 'Remittance processed successfully' : 'Processing failed'),
    ]);
}


/**
 * ❌ Reject a Remittance
 *
 * Rejects a pending remittance by updating its status to 'rejected' and
 * storing the rejection reason. Also unlinks the fee transactions that
 * were tied to this remittance so they can be included in a future payout.
 *
 * POST Body (JSON):
 *   - remittanceID (int, required): The remittance ID to reject
 *   - reason       (string, required): Reason for rejection
 *   - csrf_token   (string, required): CSRF protection token
 *
 * @param array $input Parsed JSON request body
 *
 * @return void Outputs JSON response directly
 *
 * @see tblRemittances — Remittance records table
 * @see tblServiceFeeTransactions — Fee transactions to unlink
 */
function handleRejectRemittance(array $input): void
{
    global $sessionManager;

    // ═══════════════════════════════════════════════════════════════
    // 🔍 INPUT VALIDATION
    // ═══════════════════════════════════════════════════════════════

    // 🔢 Validate remittance ID
    $remittanceID = (int) ($input['remittanceID'] ?? 0);
    if ($remittanceID <= 0) {
        throw new Exception('Valid remittance ID is required');
    }

    // 📝 Validate rejection reason — required field
    $reason = trim($input['reason'] ?? '');
    if (empty($reason)) {
        throw new Exception('A reason for rejection is required');
    }

    // 👤 Get admin user info
    $userInfo = $sessionManager->getUserInfo();
    $processedBy = (int) ($userInfo['userID'] ?? 0);

    // ═══════════════════════════════════════════════════════════════
    // 🔍 VERIFY REMITTANCE EXISTS AND IS PENDING
    // ═══════════════════════════════════════════════════════════════

    $remittance = Database::fetchOne(
        "SELECT r.remittanceID, r.status, r.amount, r.currency, r.partnerID,
                p.partnerName
         FROM tblRemittances r
         JOIN tblPartners p ON r.partnerID = p.partnerID
         WHERE r.remittanceID = ?",
        [$remittanceID],
        'i'
    );

    // 🚫 Remittance not found
    if (!$remittance) {
        throw new Exception('Remittance not found');
    }

    // 🚫 Can only reject pending remittances
    if ($remittance['status'] !== 'pending') {
        throw new Exception(
            'Cannot reject a remittance that is not in pending status (current: '
            . $remittance['status'] . ')'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ❌ UPDATE REMITTANCE STATUS TO REJECTED
    // Stores the reason in metadata and marks the rejection
    // ═══════════════════════════════════════════════════════════════

    // 📝 Build metadata with rejection details
    $metadata = json_encode([
        'rejectedReason' => $reason,
        'rejectedBy'     => $processedBy,
        'rejectedAt'     => date('Y-m-d H:i:s'),
    ]);

    // 💾 Update the remittance record
    Database::query(
        "UPDATE tblRemittances
         SET status = 'cancelled',
             processedBy = ?,
             processedAt = NOW(),
             failureReason = ?,
             metadata = ?,
             updatedAt = NOW()
         WHERE remittanceID = ?",
        [$processedBy, $reason, $metadata, $remittanceID],
        'issi'
    );

    // ═══════════════════════════════════════════════════════════════
    // 🔗 UNLINK FEE TRANSACTIONS — Allow re-inclusion in future payout
    // Reset the remittanceReference on linked fee transactions so they
    // are available for the next remittance batch.
    // ═══════════════════════════════════════════════════════════════

    Database::query(
        "UPDATE tblServiceFeeTransactions
         SET remittanceReference = NULL,
             status = 'pending'
         WHERE remittanceReference = ?",
        [(string) $remittanceID],
        's'
    );

    // ═══════════════════════════════════════════════════════════════
    // 📝 ACTIVITY LOGGING — Record the rejection for audit trail
    // ═══════════════════════════════════════════════════════════════

    ActivityLogger::log(
        $processedBy,
        'remittance_rejected',
        'payment',
        'warning',
        'Remittance #' . $remittanceID . ' rejected for partner "'
            . ($remittance['partnerName'] ?? 'Unknown') . '": '
            . ($remittance['currency'] ?? 'GBP') . ' '
            . number_format((float) ($remittance['amount'] ?? 0), 2)
            . ' — Reason: ' . $reason,
        [
            'remittanceID' => $remittanceID,
            'partnerID'    => (int) ($remittance['partnerID'] ?? 0),
            'partnerName'  => $remittance['partnerName'] ?? 'Unknown',
            'amount'       => (float) ($remittance['amount'] ?? 0),
            'reason'       => $reason,
        ]
    );

    // 📤 Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Remittance rejected successfully. Fee transactions have been unlinked for future payout.',
    ]);
}


/**
 * 🔄 Batch Process Multiple Remittances
 *
 * Processes multiple pending remittances in a single request. Iterates through
 * the provided remittance IDs and calls ServiceFeeManager::processRemittance()
 * for each one. Tracks successes and failures and returns a summary.
 *
 * POST Body (JSON):
 *   - remittanceIDs (array of int, required): Array of remittance IDs to process
 *   - csrf_token    (string, required): CSRF protection token
 *
 * @param array $input Parsed JSON request body
 *
 * @return void Outputs JSON response directly
 *
 * @see ServiceFeeManager::processRemittance() — Backend processing logic
 */
function handleBatchProcess(array $input): void
{
    global $sessionManager;

    // ═══════════════════════════════════════════════════════════════
    // 🔍 INPUT VALIDATION
    // ═══════════════════════════════════════════════════════════════

    // 📋 Validate remittance IDs array — must be a non-empty array
    $remittanceIDs = $input['remittanceIDs'] ?? [];
    if (!is_array($remittanceIDs) || empty($remittanceIDs)) {
        throw new Exception('At least one remittance ID is required for batch processing');
    }

    // 🔢 Sanitise all IDs to integers and filter out invalid values
    // @see https://www.php.net/manual/en/function.array-filter.php
    $remittanceIDs = array_filter(
        array_map('intval', $remittanceIDs),
        function ($id) {
            return $id > 0;
        }
    );

    if (empty($remittanceIDs)) {
        throw new Exception('No valid remittance IDs provided');
    }

    // 🚫 Limit batch size to prevent abuse / timeouts
    $maxBatchSize = 50;
    if (count($remittanceIDs) > $maxBatchSize) {
        throw new Exception('Batch size limited to ' . $maxBatchSize . ' remittances at a time');
    }

    // 👤 Get admin user info for processedBy
    $userInfo = $sessionManager->getUserInfo();
    $processedBy = (int) ($userInfo['userID'] ?? 0);

    if ($processedBy <= 0) {
        throw new Exception('Unable to determine admin user ID');
    }

    // ═══════════════════════════════════════════════════════════════
    // 🔄 PROCESS EACH REMITTANCE — Track successes and failures
    // ═══════════════════════════════════════════════════════════════

    $successCount = 0;
    $failCount = 0;
    $failedIDs = [];

    foreach ($remittanceIDs as $rid) {
        try {
            // ✅ Process this remittance via ServiceFeeManager
            $result = ServiceFeeManager::processRemittance($rid, $processedBy);

            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
                $failedIDs[] = $rid;
            }
        } catch (\Exception $e) {
            // ❌ Individual processing failed — log and continue with next
            $failCount++;
            $failedIDs[] = $rid;

            // 📝 Log the individual failure
            ActivityLogger::log(
                $processedBy,
                'batch_remittance_error',
                'payment',
                'error',
                'Batch process: Failed to process remittance #' . $rid . ': ' . $e->getMessage(),
                ['remittanceID' => $rid, 'error' => $e->getMessage()]
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 📝 ACTIVITY LOGGING — Record the batch operation
    // ═══════════════════════════════════════════════════════════════

    ActivityLogger::log(
        $processedBy,
        'batch_remittance_processed',
        'payment',
        $failCount > 0 ? 'warning' : 'info',
        'Batch remittance processing: ' . $successCount . ' succeeded, '
            . $failCount . ' failed (out of ' . count($remittanceIDs) . ')',
        [
            'totalRequested' => count($remittanceIDs),
            'successCount'   => $successCount,
            'failCount'      => $failCount,
            'failedIDs'      => $failedIDs,
        ]
    );

    // ═══════════════════════════════════════════════════════════════
    // 📤 RESPONSE — Summary of batch processing results
    // ═══════════════════════════════════════════════════════════════

    // 🏷️ Determine overall success (at least one processed successfully)
    $overallSuccess = $successCount > 0;

    // 📝 Build human-readable message
    $message = $successCount . ' remittance(s) processed successfully';
    if ($failCount > 0) {
        $message .= ', ' . $failCount . ' failed';
    }

    echo json_encode([
        'success'      => $overallSuccess,
        'message'      => $message,
        'successCount' => $successCount,
        'failCount'    => $failCount,
        'failedIDs'    => $failedIDs,
    ]);
}
