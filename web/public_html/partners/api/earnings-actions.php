<?php
/**
 * ============================================================================
 * 💰 SIGNula - Earnings & Payouts API Endpoint
 * ============================================================================
 *
 * Purpose: Partner API for viewing earnings summaries, fee transactions,
 *          remittance history, monthly revenue data, and managing payout
 *          preferences. Serves as the backend for the earnings.php admin UI.
 *
 * PHP Version: 8.3+ (targeting 8.4 for longevity)
 *
 * Supported Actions:
 * ─────────────────────────────────────────────────────────────────────────────
 *   GET  get_earnings_summary  — Aggregated earnings stats for a partner
 *   GET  get_fee_transactions  — Paginated fee transaction records
 *   GET  get_remittances       — Paginated remittance (payout) history
 *   GET  get_monthly_revenue   — Monthly revenue data for chart rendering
 *   POST save_payout_preferences — Update partner payout email, method, threshold
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Access Control:
 *   - All actions require authentication (valid session)
 *   - All actions require 'finance' role or higher for the target partner
 *   - POST actions require a valid CSRF token
 *
 * Backend Dependencies:
 *   - PartnerPaymentService (getPartnerEarningsSummary)
 *   - ServiceFeeManager (getPartnerFeeTransactions, getPartnerRemittances, getEarningsReport)
 *   - Database (direct queries for monthly revenue and payout preference updates)
 *   - AccessControl (hasPartnerRole for finance permission checks)
 *   - ActivityLogger (static log method for audit trail)
 *
 * @see /web/public_html/partners/admin/earnings.php   Frontend UI consumer
 * @see /web/private_html/payments/PartnerPaymentService.php
 * @see /web/private_html/payments/ServiceFeeManager.php
 * @see /web/_backend/AccessControl.php
 *
 * @package    SIGNula
 * @subpackage Partners\API
 * @version    1.0.0
 * @since      2.4.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 🔧 BOOTSTRAP: Load core SIGNula config, database, session & access control
// ============================================================================

// ⚙️ Load the main SIGNula configuration (defines SIGNULA_INIT, constants, autoloader)
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton for MySQLi prepared statement queries
// @see https://www.php.net/manual/en/book.mysqli.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🔐 Session management for authentication state tracking
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🛡️ Role-based access control for partner permission checks
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 💰 Partner payment service for earnings summary data
// @see /web/private_html/payments/PartnerPaymentService.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'PartnerPaymentService.php';

// 💸 Service fee manager for fee transactions, remittances, and earnings reports
// @see /web/private_html/payments/ServiceFeeManager.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'ServiceFeeManager.php';

// ============================================================================
// 📡 SET JSON RESPONSE HEADERS
// ============================================================================

// 📋 All responses from this endpoint are JSON
// @see https://www.php.net/manual/en/function.header.php
header('Content-Type: application/json');

// 🔒 Security headers to prevent MIME type sniffing and clickjacking
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Content-Type-Options
header('X-Content-Type-Options: nosniff');

// ============================================================================
// 🏗️ INITIALISE CORE SERVICES
// ============================================================================

// 🗄️ Get the Database singleton instance (MySQLi connection)
$db = Database::getInstance();

// 🔐 Create session manager for login state checks
$sessionManager = new SessionManager($db);

// 🛡️ Create access control instance for role/permission verification
$accessControl = new AccessControl($db, $sessionManager);

// ============================================================================
// 🔒 AUTHENTICATION CHECK
// ============================================================================

// 🚫 Reject unauthenticated requests with 401 Unauthorized
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
// 📥 PARSE REQUEST DATA
// ============================================================================

// 📥 Determine the action based on request method
// GET requests: action comes from query string parameters
// POST requests: action comes from the JSON request body
// @see https://www.php.net/manual/en/reserved.variables.server.php
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 📥 For GET requests, read action and parameters from $_GET
    $input  = $_GET;
    $action = $_GET['action'] ?? '';
} else {
    // 📥 For POST requests, parse the JSON body from php://input
    // @see https://www.php.net/manual/en/wrappers.php.php#wrappers.php.input
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
}

// ============================================================================
// 🛡️ CSRF TOKEN VERIFICATION (POST requests only)
// ============================================================================

// 🔐 Verify CSRF token for state-changing POST requests to prevent CSRF attacks
// @see https://owasp.org/www-community/attacks/csrf
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 📋 Accept CSRF token from either the JSON body or the X-CSRF-TOKEN header
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
// 🔀 ACTION ROUTER: Dispatch to the appropriate handler function
// ============================================================================

try {
    switch ($action) {

        // 📊 GET: Retrieve aggregated earnings summary for a partner
        case 'get_earnings_summary':
            handleGetEarningsSummary($input, $accessControl);
            break;

        // 💸 GET: Retrieve paginated fee transaction records
        case 'get_fee_transactions':
            handleGetFeeTransactions($input, $accessControl);
            break;

        // 🏦 GET: Retrieve paginated remittance (payout) history
        case 'get_remittances':
            handleGetRemittances($input, $accessControl);
            break;

        // 📈 GET: Retrieve monthly revenue data for chart rendering
        case 'get_monthly_revenue':
            handleGetMonthlyRevenue($input, $db, $accessControl);
            break;

        // 💾 POST: Update partner payout preferences
        case 'save_payout_preferences':
            handleSavePayoutPreferences($input, $db, $accessControl, $sessionManager);
            break;

        // ❌ Unknown action: Return an error
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action specified. Valid actions: get_earnings_summary, '
                           . 'get_fee_transactions, get_remittances, get_monthly_revenue, '
                           . 'save_payout_preferences'
            ]);
            break;
    }
} catch (Exception $e) {
    // 🚨 Catch-all exception handler for unexpected errors
    // Log the full error internally but return a sanitised message to the client
    // to prevent information disclosure
    // @see https://owasp.org/www-project-web-security-testing-guide/latest/4-Web_Application_Security_Testing/08-Testing_for_Error_Handling/
    error_log('SIGNula Earnings API Error: ' . $e->getMessage());

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while processing your request. Please try again.'
    ]);
}


// ============================================================================
// ============================================================================
// 🔧 HANDLER FUNCTIONS
// ============================================================================
// ============================================================================


/**
 * ============================================================================
 * 📊 handleGetEarningsSummary() — Retrieve aggregated earnings statistics
 * ============================================================================
 *
 * Returns the overall earnings summary for a partner, including:
 *   - Total gross revenue
 *   - Total service fees deducted
 *   - Total net earnings (after fees)
 *   - Transaction count
 *   - Pending remittance amount
 *   - Completed remittance amount
 *
 * Query Parameters:
 *   - partnerID (int, required): The partner to query
 *
 * @param array         $input         Request parameters (from GET)
 * @param AccessControl $accessControl Access control instance for permission checks
 *
 * @return void Outputs JSON response
 *
 * @see PartnerPaymentService::getPartnerEarningsSummary()
 */
function handleGetEarningsSummary(array $input, AccessControl $accessControl): void
{
    // 📋 Extract and validate the partner ID parameter
    $partnerID = (int) ($input['partnerID'] ?? 0);

    // 🔒 Validate that a partner ID was provided
    if ($partnerID <= 0) {
        throw new Exception('Partner ID is required');
    }

    // 🛡️ Verify the user has finance role or higher for this partner
    // @see AccessControl::hasPartnerRole()
    if (!$accessControl->hasPartnerRole($partnerID, 'finance')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Finance access or higher required to view earnings data.'
        ]);
        return;
    }

    // 💰 Fetch the aggregated earnings summary from PartnerPaymentService
    // @see PartnerPaymentService::getPartnerEarningsSummary()
    $summary = PartnerPaymentService::getPartnerEarningsSummary($partnerID);

    // ✅ Return the earnings summary data
    echo json_encode([
        'success' => true,
        'data'    => $summary
    ]);
}


/**
 * ============================================================================
 * 💸 handleGetFeeTransactions() — Retrieve paginated fee transaction records
 * ============================================================================
 *
 * Returns a paginated list of service fee transactions for a partner,
 * with optional date range filtering. Each transaction includes:
 *   - Transaction ID, payment ID, gross amount, fee percentage
 *   - Fee amount, net amount to partner, currency, status
 *   - Creation timestamp, fee schedule details
 *
 * Query Parameters:
 *   - partnerID (int, required): The partner to query
 *   - limit     (int, optional): Records per page (default: 15, max: 100)
 *   - offset    (int, optional): Pagination offset (default: 0)
 *   - dateFrom  (string, optional): Start date filter (Y-m-d format)
 *   - dateTo    (string, optional): End date filter (Y-m-d format)
 *
 * @param array         $input         Request parameters (from GET)
 * @param AccessControl $accessControl Access control instance for permission checks
 *
 * @return void Outputs JSON response
 *
 * @see ServiceFeeManager::getPartnerFeeTransactions()
 */
function handleGetFeeTransactions(array $input, AccessControl $accessControl): void
{
    // 📋 Extract and validate the partner ID parameter
    $partnerID = (int) ($input['partnerID'] ?? 0);

    // 🔒 Validate that a partner ID was provided
    if ($partnerID <= 0) {
        throw new Exception('Partner ID is required');
    }

    // 🛡️ Verify the user has finance role or higher for this partner
    if (!$accessControl->hasPartnerRole($partnerID, 'finance')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Finance access or higher required to view fee transactions.'
        ]);
        return;
    }

    // 📄 Parse pagination parameters with sensible defaults and limits
    // @see https://www.php.net/manual/en/function.min.php
    $limit  = min(100, max(1, (int) ($input['limit'] ?? 15)));  // 📋 Clamp between 1-100
    $offset = max(0, (int) ($input['offset'] ?? 0));             // 📋 Minimum 0

    // 📅 Build filter array for date range filtering
    $filters = [];

    // 📅 Validate and add start date filter if provided
    if (!empty($input['dateFrom']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['dateFrom'])) {
        $filters['dateFrom'] = $input['dateFrom'];
    }

    // 📅 Validate and add end date filter if provided
    if (!empty($input['dateTo']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['dateTo'])) {
        $filters['dateTo'] = $input['dateTo'];
    }

    // 💸 Fetch paginated fee transactions from ServiceFeeManager
    // @see ServiceFeeManager::getPartnerFeeTransactions()
    $result = ServiceFeeManager::getPartnerFeeTransactions(
        $partnerID,
        $limit,
        $offset,
        $filters
    );

    // ✅ Return the fee transactions with pagination metadata
    echo json_encode([
        'success' => true,
        'data'    => [
            'transactions' => $result['transactions'] ?? [],
            'total'        => $result['total'] ?? 0,
            'limit'        => $result['limit'] ?? $limit,
            'offset'       => $result['offset'] ?? $offset,
            'pages'        => max(1, (int) ceil(($result['total'] ?? 0) / $limit)),
        ]
    ]);
}


/**
 * ============================================================================
 * 🏦 handleGetRemittances() — Retrieve paginated remittance (payout) history
 * ============================================================================
 *
 * Returns a paginated list of remittance records for a partner.
 * Each remittance includes:
 *   - Remittance ID, amount, currency, status
 *   - Payout method, transaction reference
 *   - Creation and processing timestamps
 *   - Period start/end dates
 *
 * Query Parameters:
 *   - partnerID (int, required): The partner to query
 *   - limit     (int, optional): Records per page (default: 10, max: 50)
 *   - offset    (int, optional): Pagination offset (default: 0)
 *
 * @param array         $input         Request parameters (from GET)
 * @param AccessControl $accessControl Access control instance for permission checks
 *
 * @return void Outputs JSON response
 *
 * @see ServiceFeeManager::getPartnerRemittances()
 */
function handleGetRemittances(array $input, AccessControl $accessControl): void
{
    // 📋 Extract and validate the partner ID parameter
    $partnerID = (int) ($input['partnerID'] ?? 0);

    // 🔒 Validate that a partner ID was provided
    if ($partnerID <= 0) {
        throw new Exception('Partner ID is required');
    }

    // 🛡️ Verify the user has finance role or higher for this partner
    if (!$accessControl->hasPartnerRole($partnerID, 'finance')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Finance access or higher required to view remittance history.'
        ]);
        return;
    }

    // 📄 Parse pagination parameters with sensible defaults and limits
    $limit  = min(50, max(1, (int) ($input['limit'] ?? 10)));   // 📋 Clamp between 1-50
    $offset = max(0, (int) ($input['offset'] ?? 0));             // 📋 Minimum 0

    // 🏦 Fetch paginated remittance records from ServiceFeeManager
    // @see ServiceFeeManager::getPartnerRemittances()
    $result = ServiceFeeManager::getPartnerRemittances(
        $partnerID,
        $limit,
        $offset
    );

    // ✅ Return the remittance records with pagination metadata
    echo json_encode([
        'success' => true,
        'data'    => [
            'remittances' => $result['remittances'] ?? [],
            'total'       => $result['total'] ?? 0,
            'limit'       => $result['limit'] ?? $limit,
            'offset'      => $result['offset'] ?? $offset,
            'pages'       => max(1, (int) ceil(($result['total'] ?? 0) / $limit)),
        ]
    ]);
}


/**
 * ============================================================================
 * 📈 handleGetMonthlyRevenue() — Retrieve monthly revenue data for charts
 * ============================================================================
 *
 * Queries tblPayments grouped by YEAR(createdAt) and MONTH(createdAt) to
 * provide monthly revenue aggregation for bar chart rendering. Returns
 * data for the last N months (default: 6).
 *
 * This uses a direct database query against tblPayments rather than
 * ServiceFeeManager, providing raw payment-level revenue data grouped
 * by month for chart visualisation.
 *
 * Query Parameters:
 *   - partnerID (int, required): The partner to query
 *   - months    (int, optional): Number of months to look back (default: 6, max: 24)
 *
 * @param array         $input         Request parameters (from GET)
 * @param Database      $db            Database instance for direct queries
 * @param AccessControl $accessControl Access control instance for permission checks
 *
 * @return void Outputs JSON response
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html#function_year
 * @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html#function_month
 */
function handleGetMonthlyRevenue(array $input, Database $db, AccessControl $accessControl): void
{
    // 📋 Extract and validate the partner ID parameter
    $partnerID = (int) ($input['partnerID'] ?? 0);

    // 🔒 Validate that a partner ID was provided
    if ($partnerID <= 0) {
        throw new Exception('Partner ID is required');
    }

    // 🛡️ Verify the user has finance role or higher for this partner
    if (!$accessControl->hasPartnerRole($partnerID, 'finance')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Finance access or higher required to view revenue data.'
        ]);
        return;
    }

    // 📅 Parse the number of months to look back (default: 6, clamped to 1-24)
    $months = min(24, max(1, (int) ($input['months'] ?? 6)));

    // 📅 Calculate the start date based on the number of months requested
    // @see https://www.php.net/manual/en/function.date.php
    $startDate = date('Y-m-01', strtotime("-{$months} months"));

    // 📊 Query tblPayments for monthly revenue aggregation
    // Groups by YEAR and MONTH of the createdAt column
    // Uses COALESCE to return 0 instead of NULL for months with no payments
    // @see https://dev.mysql.com/doc/refman/8.0/en/group-by-functions.html
    $stmt = $db->prepare("
        SELECT
            YEAR(createdAt) AS year,
            MONTH(createdAt) AS month,
            COUNT(*) AS transactionCount,
            COALESCE(SUM(amount), 0) AS totalRevenue,
            COALESCE(AVG(amount), 0) AS averagePayment,
            MIN(currency) AS currency
        FROM tblPayments
        WHERE partnerID = ?
          AND createdAt >= ?
          AND status IN ('completed', 'succeeded')
        GROUP BY YEAR(createdAt), MONTH(createdAt)
        ORDER BY year ASC, month ASC
    ");

    // 🔒 Bind parameters using MySQLi prepared statement (prevents SQL injection)
    // @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
    $stmt->bind_param('is', $partnerID, $startDate);
    $stmt->execute();
    $result = $stmt->get_result();

    // 📋 Build the monthly revenue array from query results
    $monthlyRevenue = [];
    while ($row = $result->fetch_assoc()) {
        // 📅 Format the month key as "YYYY-MM" for consistent ordering
        $monthKey = sprintf('%04d-%02d', (int) $row['year'], (int) $row['month']);

        // 📅 Create a human-readable month label (e.g., "Jan 2026")
        // @see https://www.php.net/manual/en/function.date.php
        $monthLabel = date('M Y', mktime(0, 0, 0, (int) $row['month'], 1, (int) $row['year']));

        $monthlyRevenue[] = [
            'month'            => $monthKey,
            'monthLabel'       => $monthLabel,
            'year'             => (int) $row['year'],
            'monthNumber'      => (int) $row['month'],
            'transactionCount' => (int) $row['transactionCount'],
            'totalRevenue'     => round((float) $row['totalRevenue'], 2),
            'averagePayment'   => round((float) $row['averagePayment'], 2),
            'currency'         => $row['currency'] ?? 'GBP',
        ];
    }

    // ✅ Return the monthly revenue data
    echo json_encode([
        'success' => true,
        'data'    => [
            'months'         => $monthlyRevenue,
            'periodMonths'   => $months,
            'startDate'      => $startDate,
            'endDate'        => date('Y-m-d'),
            'totalMonths'    => count($monthlyRevenue),
        ]
    ]);
}


/**
 * ============================================================================
 * 💾 handleSavePayoutPreferences() — Update partner payout settings
 * ============================================================================
 *
 * Updates the partner's payout preferences in tblPartners:
 *   - payoutEmail: Email address for PayPal payouts and notifications
 *   - payoutMethod: Preferred payout method (paypal_payout, bank_transfer, credit_to_balance)
 *   - minimumPayoutThreshold: Minimum earnings required before a payout is triggered
 *
 * Validates:
 *   - Email format (using PHP's FILTER_VALIDATE_EMAIL)
 *   - Payout method (must be one of the allowed ENUM values)
 *   - Minimum threshold (must be >= 10.00 and <= 10000.00)
 *
 * Logs:
 *   - Activity log entry for audit trail
 *   - Admin action log for partner-level tracking
 *
 * JSON Body Parameters:
 *   - partnerID (int, required): The partner to update
 *   - payoutEmail (string, required): Valid email address
 *   - payoutMethod (string, required): One of paypal_payout, bank_transfer, credit_to_balance
 *   - minimumPayoutThreshold (float, optional): Min payout amount (default: 50.00)
 *   - csrf_token (string, required): CSRF protection token
 *
 * @param array          $input          Request body parameters (from JSON)
 * @param Database       $db             Database instance for direct queries
 * @param AccessControl  $accessControl  Access control instance for permission checks
 * @param SessionManager $sessionManager Session manager for getting the current user ID
 *
 * @return void Outputs JSON response
 *
 * @see https://www.php.net/manual/en/filter.filters.validate.php
 * @see https://dev.mysql.com/doc/refman/8.0/en/update.html
 */
function handleSavePayoutPreferences(
    array $input,
    Database $db,
    AccessControl $accessControl,
    SessionManager $sessionManager
): void {
    // 📋 Extract and validate the partner ID parameter
    $partnerID = (int) ($input['partnerID'] ?? 0);

    // 🔒 Validate that a partner ID was provided
    if ($partnerID <= 0) {
        throw new Exception('Partner ID is required');
    }

    // 🛡️ Verify the user has finance role or higher for this partner
    // Saving payout preferences requires at least finance-level access
    if (!$accessControl->hasPartnerRole($partnerID, 'finance')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Finance access or higher required to update payout preferences.'
        ]);
        return;
    }

    // ====================================================================
    // 📧 VALIDATE PAYOUT EMAIL
    // ====================================================================

    // 📧 Extract and validate the payout email address
    // @see https://www.php.net/manual/en/function.filter-var.php
    $payoutEmail = filter_var(
        trim($input['payoutEmail'] ?? ''),
        FILTER_VALIDATE_EMAIL
    );

    // 🚫 Reject invalid email addresses
    if (!$payoutEmail) {
        echo json_encode([
            'success' => false,
            'message' => 'A valid email address is required for payout notifications.'
        ]);
        return;
    }

    // ====================================================================
    // 🏦 VALIDATE PAYOUT METHOD
    // ====================================================================

    // 🏦 Define the allowed payout methods (maps to tblPartners.payoutMethod ENUM)
    // @see /_database/migrations/012_payment_expansion.sql
    $allowedMethods = [
        'paypal_payout',      // 💳 PayPal Payout (requires payoutEmail)
        'bank_transfer',      // 🏦 Bank/wire transfer (manual processing)
        'credit_to_balance',  // 💰 Credit to partner's SIGNula account balance
    ];

    $payoutMethod = trim($input['payoutMethod'] ?? '');

    // 🚫 Reject invalid payout methods
    if (!in_array($payoutMethod, $allowedMethods, true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid payout method. Allowed: '
                       . implode(', ', $allowedMethods)
        ]);
        return;
    }

    // ====================================================================
    // 💰 VALIDATE MINIMUM PAYOUT THRESHOLD
    // ====================================================================

    // 💰 Parse and validate the minimum payout threshold
    // @see https://www.php.net/manual/en/function.floatval.php
    $minimumThreshold = round((float) ($input['minimumPayoutThreshold'] ?? 50.00), 2);

    // 🔒 Enforce minimum threshold of £10.00 (prevents micro-payouts)
    if ($minimumThreshold < 10.00) {
        echo json_encode([
            'success' => false,
            'message' => 'Minimum payout threshold must be at least £10.00.'
        ]);
        return;
    }

    // 🔒 Enforce maximum threshold of £10,000.00 (sanity check)
    if ($minimumThreshold > 10000.00) {
        echo json_encode([
            'success' => false,
            'message' => 'Minimum payout threshold cannot exceed £10,000.00.'
        ]);
        return;
    }

    // ====================================================================
    // 🗄️ UPDATE PARTNER PAYOUT PREFERENCES IN DATABASE
    // ====================================================================

    // 📝 Update tblPartners with the new payout preferences
    // Uses MySQLi prepared statement to prevent SQL injection
    // @see https://www.php.net/manual/en/mysqli.prepare.php
    // @see https://dev.mysql.com/doc/refman/8.0/en/update.html
    $stmt = $db->prepare("
        UPDATE tblPartners
        SET
            payoutEmail             = ?,
            payoutMethod            = ?,
            minimumPayoutThreshold  = ?,
            updatedAt               = NOW()
        WHERE partnerID = ?
    ");

    // 🔒 Bind parameters with type string:
    //   s = payoutEmail (string)
    //   s = payoutMethod (string/ENUM)
    //   d = minimumPayoutThreshold (double/decimal)
    //   i = partnerID (integer)
    // @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
    $stmt->bind_param('ssdi', $payoutEmail, $payoutMethod, $minimumThreshold, $partnerID);

    // 🚀 Execute the prepared statement
    if (!$stmt->execute()) {
        // 🚨 Log the database error internally
        error_log('SIGNula: Failed to update payout preferences for partner '
                  . $partnerID . ': ' . $stmt->error);

        echo json_encode([
            'success' => false,
            'message' => 'Failed to save payout preferences. Please try again.'
        ]);
        return;
    }

    // 🔍 Check that the update actually affected a row (partner exists)
    if ($stmt->affected_rows === 0) {
        // ⚠️ This could mean either no changes were made (same values) or partner not found
        // We still treat it as success since the values are as desired
        // But we'll log it for diagnostic purposes
        error_log('SIGNula: Payout preferences update affected 0 rows for partner '
                  . $partnerID . ' (may indicate no changes or missing partner)');
    }

    // ====================================================================
    // 📝 LOG THE ACTIVITY FOR AUDIT TRAIL
    // ====================================================================

    // 📝 Log the payout preferences update via ActivityLogger
    // @see ActivityLogger::log()
    // Signature: log(?int $userID, string $activityType, string $category,
    //                string $severity, string $description, array $metadata)
    $currentUserID = $sessionManager->getUserID();

    ActivityLogger::log(
        $currentUserID,                           // 👤 User who made the change
        'payout_preferences_updated',             // 📋 Activity type
        'partner',                                // 📁 Category
        'info',                                   // 📊 Severity (informational)
        'Payout preferences updated for partner #' . $partnerID,  // 📝 Description
        [
            'partnerID'              => $partnerID,
            'payoutEmail'            => $payoutEmail,
            'payoutMethod'           => $payoutMethod,
            'minimumPayoutThreshold' => $minimumThreshold,
            'updatedBy'              => $currentUserID,
        ]
    );

    // 📝 Also log the admin action via AccessControl for partner-level audit
    // @see AccessControl::logAdminAction()
    $accessControl->logAdminAction(
        'update_payout_preferences',  // 📋 Action name
        'partner',                    // 📁 Entity type
        $partnerID,                   // 🔗 Entity ID
        [
            'payoutEmail'            => $payoutEmail,
            'payoutMethod'           => $payoutMethod,
            'minimumPayoutThreshold' => $minimumThreshold,
        ]
    );

    // ====================================================================
    // ✅ RETURN SUCCESS RESPONSE
    // ====================================================================

    echo json_encode([
        'success' => true,
        'message' => 'Payout preferences saved successfully.',
        'data'    => [
            'payoutEmail'            => $payoutEmail,
            'payoutMethod'           => $payoutMethod,
            'minimumPayoutThreshold' => $minimumThreshold,
        ]
    ]);
}
