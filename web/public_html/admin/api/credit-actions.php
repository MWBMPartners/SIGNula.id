<?php
/**
 * 💰 Credit Balance Management API (Super Admin)
 *
 * RESTful API endpoint for managing credit balances and transactions.
 * Handles GET requests for data retrieval (balances, transactions, stats)
 * and POST requests for mutations (adjustments, deposits, withdrawals).
 *
 * Actions (GET):
 *   - get_balances      — Paginated list of all credit balances with owner details
 *   - get_transactions  — Paginated credit transaction history with filters
 *   - get_credit_stats  — Summary statistics for the credit system
 *
 * Actions (POST):
 *   - adjust_balance    — Manual admin credit adjustment (+/-)
 *   - deposit_credits   — Add credits to an account
 *   - withdraw_credits  — Remove credits from an account
 *
 * @see https://www.php.net/manual/en/book.mysqli.php — PHP MySQLi Reference
 * @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Prevention
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status — HTTP Status Codes
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.3.0-beta
 */

// ═══════════════════════════════════════════════════════════════════════════════
// 🔌 BACKEND INCLUDES — Load core configuration, database, session & access
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 📂 Load the main application configuration.
 * dirname(__DIR__, 3) navigates from /web/public_html/admin/api/ up to the project root.
 * @see https://www.php.net/manual/en/function.dirname.php — PHP dirname()
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

/**
 * 🗄️ Database singleton — provides MySQLi prepared statement wrappers.
 * @see /_backend/Database.php — Database class documentation
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

/**
 * 🔑 Session management — handles login state and session tokens.
 * @see /_backend/SessionManager.php — SessionManager class documentation
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

/**
 * 🛡️ Access control — role-based permission checks.
 * @see /_backend/AccessControl.php — AccessControl class documentation
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

/**
 * 💰 CreditManager — backend class for credit balance operations.
 * Located in the private_html directory (outside web root) for security.
 * @see /private_html/payments/CreditManager.php — CreditManager class documentation
 */
$creditManagerPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'CreditManager.php';
if (file_exists($creditManagerPath)) {
    require_once $creditManagerPath;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 📤 RESPONSE HEADERS — Set JSON content type for all API responses
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
// ═══════════════════════════════════════════════════════════════════════════════

header('Content-Type: application/json; charset=utf-8');

/**
 * 🛡️ Security headers — prevent MIME sniffing and embedding.
 * @see https://owasp.org/www-project-secure-headers/ — OWASP Secure Headers
 */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ═══════════════════════════════════════════════════════════════════════════════
// 🔌 INITIALISE CORE SERVICES
// ═══════════════════════════════════════════════════════════════════════════════

/** @var Database $db — Database singleton instance for all queries */
$db = Database::getInstance();

/** @var SessionManager $sessionManager — Manages user session state */
$sessionManager = new SessionManager($db);

/** @var AccessControl $accessControl — Enforces role-based access control */
$accessControl = new AccessControl($db, $sessionManager);

// ═══════════════════════════════════════════════════════════════════════════════
// 🔒 AUTHENTICATION CHECK — Reject unauthenticated requests with 401
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/401
// ═══════════════════════════════════════════════════════════════════════════════

if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Please log in to access this resource.'
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🛡️ SUPER ADMIN CHECK — Only super admins can access credit management
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/403
// ═══════════════════════════════════════════════════════════════════════════════

$userInfo = $sessionManager->getUserInfo();
if (!isset($userInfo['isSuperAdmin']) || $userInfo['isSuperAdmin'] != 1) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Super admin access required to manage credit balances.'
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 📥 REQUEST PARSING — Determine action from GET or POST data
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 📥 Parse the incoming request data.
 * GET requests use URL parameters.
 * POST requests use JSON body (php://input).
 * @see https://www.php.net/manual/en/wrappers.php.php — PHP I/O Streams
 */
$input = json_decode(file_get_contents('php://input'), true);
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// ═══════════════════════════════════════════════════════════════════════════════
// 🛡️ CSRF VERIFICATION — Required for all POST requests
// @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Reference
// ═══════════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /**
     * 🔐 Extract the CSRF token from either the JSON body or the X-CSRF-TOKEN header.
     * This dual approach supports both $.ajax() with JSON body and form submissions.
     */
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

// ═══════════════════════════════════════════════════════════════════════════════
// 🔀 ACTION ROUTER — Route to the appropriate handler function
// ═══════════════════════════════════════════════════════════════════════════════

try {
    switch ($action) {
        // ═══════════════════════════════════════════════════════════════
        // 📡 GET ACTIONS — Data retrieval endpoints
        // ═══════════════════════════════════════════════════════════════

        case 'get_balances':
            /**
             * 💰 Retrieve paginated credit balances with optional filters.
             * Supports: ownerType, search, limit, offset
             */
            handleGetBalances();
            break;

        case 'get_transactions':
            /**
             * 📋 Retrieve paginated credit transactions with optional filters.
             * Supports: ownerType, ownerID, transactionType, dateFrom, dateTo, limit, offset
             */
            handleGetTransactions();
            break;

        case 'get_credit_stats':
            /**
             * 📊 Retrieve summary statistics for the credit system.
             * Returns: totalBalance, totalDeposits, totalWithdrawals, activeAccounts
             */
            handleGetCreditStats();
            break;

        // ═══════════════════════════════════════════════════════════════
        // 📡 POST ACTIONS — Mutation endpoints (require CSRF)
        // ═══════════════════════════════════════════════════════════════

        case 'adjust_balance':
            /**
             * ⚖️ Manual admin credit adjustment — can add or subtract credits.
             * Requires: ownerType, ownerID, amount, currency, description
             */
            handleAdjustBalance($input);
            break;

        case 'deposit_credits':
            /**
             * 📈 Deposit (add) credits to an account.
             * Requires: ownerType, ownerID, amount, currency, description
             */
            handleDepositCredits($input);
            break;

        case 'withdraw_credits':
            /**
             * 📉 Withdraw (remove) credits from an account.
             * Requires: ownerType, ownerID, amount, currency, description
             */
            handleWithdrawCredits($input);
            break;

        default:
            /**
             * ❌ Unknown action — return a 400 Bad Request response.
             * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/400
             */
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')
            ]);
            break;
    }
} catch (Exception $e) {
    /**
     * ❌ Global exception handler — catches any unhandled exceptions.
     * Logs the error to the activity log and returns a safe error message.
     * @see https://www.php.net/manual/en/language.exceptions.php — PHP Exceptions
     */
    http_response_code(500);

    // 📝 Log the error to the activity log for debugging
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            isset($userInfo['userID']) ? (int)$userInfo['userID'] : null,
            'credit_api_error',
            'payment',
            'error',
            'Credit API error in action [' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . ']: ' . $e->getMessage(),
            [
                'action'    => $action,
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine()
            ]
        );
    }

    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while processing your request. Please try again.'
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════════════════════
//
// 📡 HANDLER FUNCTIONS
//
// Each function handles a specific API action. They follow a consistent pattern:
//   1. Parse and validate input parameters
//   2. Build the SQL query with prepared statements
//   3. Execute the query and format the response
//   4. Return a JSON response
//
// ═══════════════════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════════════════════


/**
 * 💰 Handle GET get_balances — Retrieve paginated credit balances
 *
 * Fetches all credit balance records from tblCreditBalances, joined with
 * tblUsers and tblPartners to include owner display names and emails.
 * Supports filtering by ownerType and search text, with pagination.
 *
 * Query Parameters:
 *   - ownerType (string)  — Filter by 'user' or 'partner' (default: 'all')
 *   - search    (string)  — Search across owner names and emails
 *   - limit     (int)     — Records per page (default: 25, max: 100)
 *   - offset    (int)     — Number of records to skip (default: 0)
 *
 * Response:
 *   - success     (bool)   — Whether the request succeeded
 *   - data        (array)  — Array of balance records
 *   - total       (int)    — Total matching records (for pagination)
 *   - pages       (int)    — Total number of pages
 *   - currentPage (int)    — Current page number
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/join.html — MySQL JOIN Syntax
 * @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php — MySQLi Bind Param
 */
function handleGetBalances(): void
{
    global $db;

    // 📥 Parse and sanitise input parameters
    $ownerType = trim($_GET['ownerType'] ?? 'all');
    $search    = trim($_GET['search'] ?? '');
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $limit     = min(100, max(1, (int)($_GET['limit'] ?? 25)));
    $offset    = max(0, (int)($_GET['offset'] ?? ($page - 1) * $limit));

    // 🔧 Build dynamic WHERE conditions using prepared statement parameters
    $conditions = [];
    $params     = [];
    $types      = '';

    /**
     * 🏷️ Owner type filter — restrict results to 'user' or 'partner'.
     * Only apply if a specific type is requested (not 'all').
     */
    if ($ownerType !== '' && $ownerType !== 'all') {
        $conditions[] = 'cb.ownerType = ?';
        $params[]     = $ownerType;
        $types       .= 's';
    }

    /**
     * 🔍 Search filter — match against user display name, email, or partner name.
     * Uses LIKE with wildcards for partial matching.
     * @see https://dev.mysql.com/doc/refman/8.0/en/string-comparison-functions.html — MySQL LIKE
     */
    if ($search !== '') {
        $searchLike   = '%' . $search . '%';
        $conditions[] = '(u.displayName LIKE ? OR u.email LIKE ? OR p.partnerName LIKE ?)';
        $params[]     = $searchLike;
        $params[]     = $searchLike;
        $params[]     = $searchLike;
        $types       .= 'sss';
    }

    // 📋 Assemble the WHERE clause (empty string if no conditions)
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // ═══════════════════════════════════════════════════════════════════
    // 📊 COUNT QUERY — Get total matching records for pagination
    // ═══════════════════════════════════════════════════════════════════

    $countSql = "SELECT COUNT(*) AS total
                 FROM tblCreditBalances cb
                 LEFT JOIN tblUsers u ON cb.ownerType = 'user' AND cb.ownerID = u.userID
                 LEFT JOIN tblPartners p ON cb.ownerType = 'partner' AND cb.ownerID = p.partnerID
                 {$whereClause}";

    $countStmt = $db->prepare($countSql);

    /**
     * 🔐 Bind parameters dynamically if there are any conditions.
     * @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
     */
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }

    $countStmt->execute();
    $countResult = $countStmt->get_result()->fetch_assoc();
    $total       = (int)($countResult['total'] ?? 0);
    $totalPages  = max(1, (int)ceil($total / $limit));

    // ═══════════════════════════════════════════════════════════════════
    // 📋 DATA QUERY — Fetch paginated balance records with owner details
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 📋 Main SELECT query joining credit balances with users and partners.
     * Uses LEFT JOINs so we get results even if the owner table doesn't match
     * (e.g., a partner balance won't have a tblUsers match).
     *
     * COALESCE is used to pick the correct name/email based on owner type:
     *   - For users:    displayName and email from tblUsers
     *   - For partners: partnerName and contactEmail from tblPartners
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/comparison-operators.html#function_coalesce
     */
    $dataSql = "SELECT
                    cb.balanceID,
                    cb.ownerType,
                    cb.ownerID,
                    cb.balance,
                    cb.currency,
                    cb.updatedAt AS lastActivity,
                    COALESCE(
                        CASE WHEN cb.ownerType = 'user' THEN u.displayName ELSE NULL END,
                        CASE WHEN cb.ownerType = 'partner' THEN p.partnerName ELSE NULL END,
                        'Unknown'
                    ) AS ownerName,
                    COALESCE(
                        CASE WHEN cb.ownerType = 'user' THEN u.email ELSE NULL END,
                        CASE WHEN cb.ownerType = 'partner' THEN p.contactEmail ELSE NULL END,
                        ''
                    ) AS ownerEmail
                FROM tblCreditBalances cb
                LEFT JOIN tblUsers u ON cb.ownerType = 'user' AND cb.ownerID = u.userID
                LEFT JOIN tblPartners p ON cb.ownerType = 'partner' AND cb.ownerID = p.partnerID
                {$whereClause}
                ORDER BY cb.balance DESC, cb.updatedAt DESC
                LIMIT ? OFFSET ?";

    // 📦 Add pagination parameters to the existing params array
    $dataParams = array_merge($params, [$limit, $offset]);
    $dataTypes  = $types . 'ii';

    $dataStmt = $db->prepare($dataSql);
    $dataStmt->bind_param($dataTypes, ...$dataParams);
    $dataStmt->execute();
    $result = $dataStmt->get_result();

    // 📋 Fetch all rows into an associative array
    $balances = [];
    while ($row = $result->fetch_assoc()) {
        $balances[] = $row;
    }

    // 📤 Return the JSON response with pagination metadata
    echo json_encode([
        'success'     => true,
        'data'        => $balances,
        'total'       => $total,
        'pages'       => $totalPages,
        'currentPage' => $page
    ]);
}


/**
 * 📋 Handle GET get_transactions — Retrieve paginated credit transactions
 *
 * Fetches credit transaction records from tblCreditTransactions, joined with
 * tblUsers and tblPartners for owner names. Supports multiple filters:
 * owner type, specific owner ID, transaction type, and date range.
 *
 * Query Parameters:
 *   - ownerType       (string) — Filter by 'user' or 'partner' (default: 'all')
 *   - ownerID         (int)    — Filter by specific owner ID (optional)
 *   - transactionType (string) — Filter by type: deposit/withdrawal/payment/refund/adjustment/transfer
 *   - dateFrom        (string) — Start date filter (YYYY-MM-DD format)
 *   - dateTo          (string) — End date filter (YYYY-MM-DD format)
 *   - limit           (int)    — Records per page (default: 25, max: 100)
 *   - offset          (int)    — Number of records to skip (default: 0)
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html — MySQL Date Functions
 */
function handleGetTransactions(): void
{
    global $db;

    // 📥 Parse and sanitise input parameters
    $ownerType       = trim($_GET['ownerType'] ?? 'all');
    $ownerID         = isset($_GET['ownerID']) ? (int)$_GET['ownerID'] : 0;
    $transactionType = trim($_GET['transactionType'] ?? 'all');
    $dateFrom        = trim($_GET['dateFrom'] ?? '');
    $dateTo          = trim($_GET['dateTo'] ?? '');
    $page            = max(1, (int)($_GET['page'] ?? 1));
    $limit           = min(100, max(1, (int)($_GET['limit'] ?? 25)));
    $offset          = max(0, (int)($_GET['offset'] ?? ($page - 1) * $limit));

    // 🔧 Build dynamic WHERE conditions
    $conditions = [];
    $params     = [];
    $types      = '';

    /**
     * 🏷️ Owner type filter — restrict to 'user' or 'partner' transactions.
     */
    if ($ownerType !== '' && $ownerType !== 'all') {
        $conditions[] = 'ct.ownerType = ?';
        $params[]     = $ownerType;
        $types       .= 's';
    }

    /**
     * 🆔 Specific owner ID filter — show transactions for a single account.
     * Used by the "View Transaction History" modal.
     */
    if ($ownerID > 0) {
        $conditions[] = 'ct.ownerID = ?';
        $params[]     = $ownerID;
        $types       .= 'i';
    }

    /**
     * 🏷️ Transaction type filter — restrict to a specific transaction type.
     * Valid types: deposit, withdrawal, payment, refund, adjustment, transfer
     */
    if ($transactionType !== '' && $transactionType !== 'all') {
        $conditions[] = 'ct.transactionType = ?';
        $params[]     = $transactionType;
        $types       .= 's';
    }

    /**
     * 📅 Date range filter — FROM date.
     * Uses >= to include the entire start date.
     * @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-type-syntax.html
     */
    if ($dateFrom !== '') {
        $conditions[] = 'ct.createdAt >= ?';
        $params[]     = $dateFrom . ' 00:00:00';
        $types       .= 's';
    }

    /**
     * 📅 Date range filter — TO date.
     * Uses <= with 23:59:59 to include the entire end date.
     */
    if ($dateTo !== '') {
        $conditions[] = 'ct.createdAt <= ?';
        $params[]     = $dateTo . ' 23:59:59';
        $types       .= 's';
    }

    // 📋 Assemble the WHERE clause
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // ═══════════════════════════════════════════════════════════════════
    // 📊 COUNT QUERY — Total matching records for pagination
    // ═══════════════════════════════════════════════════════════════════

    $countSql = "SELECT COUNT(*) AS total
                 FROM tblCreditTransactions ct
                 {$whereClause}";

    $countStmt = $db->prepare($countSql);
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result()->fetch_assoc();
    $total       = (int)($countResult['total'] ?? 0);
    $totalPages  = max(1, (int)ceil($total / $limit));

    // ═══════════════════════════════════════════════════════════════════
    // 📋 DATA QUERY — Fetch paginated transaction records with owner names
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 📋 Main SELECT query with LEFT JOINs to tblUsers and tblPartners.
     * COALESCE picks the correct owner name based on ownerType.
     * Ordered by createdAt DESC so the most recent transactions appear first.
     */
    $dataSql = "SELECT
                    ct.transactionID,
                    ct.ownerType,
                    ct.ownerID,
                    ct.transactionType,
                    ct.amount,
                    ct.balanceAfter,
                    ct.currency,
                    ct.description,
                    ct.reference,
                    ct.createdAt,
                    COALESCE(
                        CASE WHEN ct.ownerType = 'user' THEN u.displayName ELSE NULL END,
                        CASE WHEN ct.ownerType = 'partner' THEN p.partnerName ELSE NULL END,
                        'Unknown'
                    ) AS ownerName,
                    COALESCE(
                        CASE WHEN ct.ownerType = 'user' THEN u.email ELSE NULL END,
                        CASE WHEN ct.ownerType = 'partner' THEN p.contactEmail ELSE NULL END,
                        ''
                    ) AS ownerEmail
                FROM tblCreditTransactions ct
                LEFT JOIN tblUsers u ON ct.ownerType = 'user' AND ct.ownerID = u.userID
                LEFT JOIN tblPartners p ON ct.ownerType = 'partner' AND ct.ownerID = p.partnerID
                {$whereClause}
                ORDER BY ct.createdAt DESC
                LIMIT ? OFFSET ?";

    // 📦 Merge pagination params with filter params
    $dataParams = array_merge($params, [$limit, $offset]);
    $dataTypes  = $types . 'ii';

    $dataStmt = $db->prepare($dataSql);
    $dataStmt->bind_param($dataTypes, ...$dataParams);
    $dataStmt->execute();
    $result = $dataStmt->get_result();

    // 📋 Fetch all rows into an array
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }

    // 📤 Return JSON response
    echo json_encode([
        'success'     => true,
        'data'        => $transactions,
        'total'       => $total,
        'pages'       => $totalPages,
        'currentPage' => $page
    ]);
}


/**
 * 📊 Handle GET get_credit_stats — Retrieve credit system summary statistics
 *
 * Aggregates key metrics from tblCreditBalances and tblCreditTransactions:
 *   - Total credit balance across all accounts
 *   - Total deposits (all time)
 *   - Total withdrawals (all time)
 *   - Number of active accounts (unique owner type + ID combinations)
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html — MySQL Aggregates
 */
function handleGetCreditStats(): void
{
    global $db;

    /**
     * 💰 Total balance across all credit accounts.
     * Uses SUM() with COALESCE to handle NULL (no records) gracefully.
     */
    $balanceStmt = $db->prepare(
        "SELECT COALESCE(SUM(balance), 0) AS totalBalance
         FROM tblCreditBalances"
    );
    $balanceStmt->execute();
    $balanceResult = $balanceStmt->get_result()->fetch_assoc();

    /**
     * 📊 Transaction aggregates — total deposits, withdrawals, and unique account count.
     * ABS() is used for withdrawals since they may be stored as negative values.
     * COUNT(DISTINCT ...) counts unique owner combinations.
     */
    $txStmt = $db->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN transactionType = 'deposit' THEN amount ELSE 0 END), 0) AS totalDeposits,
            COALESCE(SUM(CASE WHEN transactionType = 'withdrawal' THEN ABS(amount) ELSE 0 END), 0) AS totalWithdrawals,
            COUNT(DISTINCT CONCAT(ownerType, '-', ownerID)) AS activeAccounts
         FROM tblCreditTransactions"
    );
    $txStmt->execute();
    $txResult = $txStmt->get_result()->fetch_assoc();

    // 📤 Return consolidated stats
    echo json_encode([
        'success' => true,
        'data'    => [
            'totalBalance'     => (float)($balanceResult['totalBalance'] ?? 0),
            'totalDeposits'    => (float)($txResult['totalDeposits'] ?? 0),
            'totalWithdrawals' => (float)($txResult['totalWithdrawals'] ?? 0),
            'activeAccounts'   => (int)($txResult['activeAccounts'] ?? 0)
        ]
    ]);
}


/**
 * ⚖️ Handle POST adjust_balance — Manual admin credit adjustment
 *
 * Allows a super admin to manually add or subtract credits from any account.
 * The amount can be positive (credit) or negative (debit). Uses the
 * CreditManager backend class if available, otherwise falls back to
 * direct database operations.
 *
 * Required Parameters (POST JSON body):
 *   - ownerType   (string) — 'user' or 'partner'
 *   - ownerID     (int)    — The owner's numeric ID
 *   - amount      (float)  — Amount to adjust (positive or negative)
 *   - currency    (string) — ISO 4217 currency code (e.g., 'GBP')
 *   - description (string) — Reason/description for the adjustment
 *
 * @param array $input — Parsed JSON input from php://input
 * @see https://owasp.org/www-community/Input_Validation_Cheat_Sheet — OWASP Input Validation
 */
function handleAdjustBalance(array $input): void
{
    global $db, $userInfo;

    // ═══════════════════════════════════════════════════════════════════
    // ✅ INPUT VALIDATION — Ensure all required fields are present and valid
    // ═══════════════════════════════════════════════════════════════════

    $ownerType   = trim($input['ownerType'] ?? '');
    $ownerID     = (int)($input['ownerID'] ?? 0);
    $amount      = (float)($input['amount'] ?? 0);
    $currency    = strtoupper(trim($input['currency'] ?? 'GBP'));
    $description = trim($input['description'] ?? '');

    /**
     * 🚫 Validate owner type — must be 'user' or 'partner'.
     */
    if (!in_array($ownerType, ['user', 'partner'], true)) {
        throw new Exception('Invalid owner type. Must be "user" or "partner".');
    }

    /**
     * 🚫 Validate owner ID — must be a positive integer.
     */
    if ($ownerID <= 0) {
        throw new Exception('Invalid owner ID. Must be a positive integer.');
    }

    /**
     * 🚫 Validate amount — must not be zero.
     */
    if ($amount == 0) {
        throw new Exception('Amount must not be zero.');
    }

    /**
     * 🚫 Validate description — required for audit trail.
     */
    if ($description === '') {
        throw new Exception('Description is required for credit adjustments.');
    }

    /**
     * 🚫 Validate currency — must be a 3-letter ISO 4217 code.
     * @see https://en.wikipedia.org/wiki/ISO_4217 — ISO 4217 Standard
     */
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new Exception('Invalid currency code. Must be a 3-letter ISO 4217 code.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 🔍 VERIFY OWNER EXISTS — Check that the target account actually exists
    // ═══════════════════════════════════════════════════════════════════

    if ($ownerType === 'user') {
        /**
         * 👤 Verify user exists in tblUsers.
         */
        $checkStmt = $db->prepare("SELECT userID FROM tblUsers WHERE userID = ? LIMIT 1");
        $checkStmt->bind_param('i', $ownerID);
    } else {
        /**
         * 🤝 Verify partner exists in tblPartners.
         */
        $checkStmt = $db->prepare("SELECT partnerID FROM tblPartners WHERE partnerID = ? LIMIT 1");
        $checkStmt->bind_param('i', $ownerID);
    }
    $checkStmt->execute();
    $ownerExists = $checkStmt->get_result()->fetch_assoc();

    if (!$ownerExists) {
        throw new Exception('Owner not found. The specified ' . $ownerType . ' ID does not exist.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 💰 ATTEMPT TO USE CreditManager — Fall back to direct DB if unavailable
    // ═══════════════════════════════════════════════════════════════════

    $adminUserID = (int)($userInfo['userID'] ?? 0);
    $newBalance  = 0;

    if (class_exists('CreditManager')) {
        /**
         * 💰 Use the CreditManager class for the adjustment.
         * This handles balance updates, transaction logging, and validation internally.
         *
         * 🩹 B-074 fix: the REAL signature is
         * `adjustBalance(string $ownerType, int $ownerID, float $amount,
         * string $description, int $performedBy, array $options = [])` —
         * this call previously passed ($ownerType, $ownerID, $amount,
         * $currency, $description, $adminUserID, null) positionally, which
         * put $currency into the $description parameter, $description (a
         * string) into the `int $performedBy` parameter, and $adminUserID
         * (an int) into the `array $options` parameter — the last of these
         * is a straight PHP TypeError (int given, array expected), so this
         * call FATALED on every real invocation. Fixed to pass $description
         * as $description, $adminUserID as $performedBy, and $currency via
         * $options.
         * @see /private_html/payments/CreditManager.php — CreditManager::adjustBalance()
         */
        $result = CreditManager::adjustBalance(
            $ownerType,
            $ownerID,
            $amount,
            $description,
            $adminUserID,
            ['currency' => $currency, 'referenceType' => 'admin_manual']
        );

        if (!($result['success'] ?? false)) {
            throw new Exception($result['message'] ?? 'Credit adjustment failed.');
        }

        // 🩹 B-074 fix: adjustBalance() already returns the post-adjustment
        // balance — use it directly rather than a SECOND (previously
        // mis-ordered) getBalance() call. getBalance()'s real signature is
        // `getBalance(string $ownerType, int $ownerID, ?int $partnerID =
        // null, string $currency = 'GBP')`; the old call passed $currency
        // into the `?int $partnerID` slot and `null` into the non-nullable
        // `string $currency` slot — both TypeErrors.
        $newBalance = (float)($result['newBalance'] ?? CreditManager::getBalance($ownerType, $ownerID, null, $currency));
    } else {
        /**
         * 🔧 FALLBACK — Direct database operations if CreditManager is not available.
         * This handles the same logic manually using MySQLi prepared statements.
         */
        $newBalance = performDirectAdjustment($db, $ownerType, $ownerID, $amount, $currency, $description, $adminUserID);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 📝 ACTIVITY LOG — Record the adjustment for audit trail
    // ═══════════════════════════════════════════════════════════════════

    if (class_exists('ActivityLogger')) {
        /**
         * 📝 Log the credit adjustment to the activity log.
         * Includes all relevant metadata for security auditing.
         * @see /_backend/ActivityLogger.php — ActivityLogger::log()
         */
        ActivityLogger::log(
            $adminUserID,
            'credit_adjustment',
            'payment',
            'info',
            'Manual credit adjustment: ' . ($amount >= 0 ? '+' : '') . $amount . ' ' . $currency .
                ' for ' . $ownerType . ' #' . $ownerID . '. Description: ' . $description,
            [
                'ownerType'   => $ownerType,
                'ownerID'     => $ownerID,
                'amount'      => $amount,
                'currency'    => $currency,
                'description' => $description,
                'adminUserID' => $adminUserID,
                'newBalance'  => $newBalance
            ]
        );
    }

    // 📤 Return success response with the new balance
    echo json_encode([
        'success'    => true,
        'message'    => 'Credit adjustment applied successfully.',
        'newBalance' => $newBalance
    ]);
}


/**
 * 📈 Handle POST deposit_credits — Add credits to an account
 *
 * Adds a positive credit amount to the specified account.
 * This is a convenience wrapper that validates the amount is positive
 * before delegating to the CreditManager or direct DB operations.
 *
 * Required Parameters (POST JSON body):
 *   - ownerType   (string) — 'user' or 'partner'
 *   - ownerID     (int)    — The owner's numeric ID
 *   - amount      (float)  — Positive amount to deposit
 *   - currency    (string) — ISO 4217 currency code
 *   - description (string) — Reason for the deposit
 *   - reference   (string) — Optional external reference ID
 *
 * @param array $input — Parsed JSON input from php://input
 */
function handleDepositCredits(array $input): void
{
    global $db, $userInfo;

    // ═══════════════════════════════════════════════════════════════════
    // ✅ INPUT VALIDATION
    // ═══════════════════════════════════════════════════════════════════

    $ownerType   = trim($input['ownerType'] ?? '');
    $ownerID     = (int)($input['ownerID'] ?? 0);
    $amount      = (float)($input['amount'] ?? 0);
    $currency    = strtoupper(trim($input['currency'] ?? 'GBP'));
    $description = trim($input['description'] ?? '');
    $reference   = trim($input['reference'] ?? '');

    /**
     * 🚫 Validate owner type.
     */
    if (!in_array($ownerType, ['user', 'partner'], true)) {
        throw new Exception('Invalid owner type. Must be "user" or "partner".');
    }

    /**
     * 🚫 Validate owner ID.
     */
    if ($ownerID <= 0) {
        throw new Exception('Invalid owner ID. Must be a positive integer.');
    }

    /**
     * 🚫 Validate amount — must be positive for deposits.
     */
    if ($amount <= 0) {
        throw new Exception('Deposit amount must be a positive value.');
    }

    /**
     * 🚫 Validate description.
     */
    if ($description === '') {
        throw new Exception('Description is required for credit deposits.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 💰 PERFORM DEPOSIT
    // ═══════════════════════════════════════════════════════════════════

    $adminUserID = (int)($userInfo['userID'] ?? 0);
    $newBalance  = 0;

    if (class_exists('CreditManager')) {
        /**
         * 💰 Use CreditManager for the deposit operation.
         *
         * 🩹 B-074 fix: the REAL signature is
         * `deposit(string $ownerType, int $ownerID, float $amount,
         * string $description, array $options = [])` — this call previously
         * passed $currency where $description belongs, and passed
         * $description (a string) into the `array $options` parameter — a
         * PHP TypeError (string given, array expected) that fataled on every
         * real invocation.
         * @see /private_html/payments/CreditManager.php — CreditManager::deposit()
         */
        $depositOptions = ['currency' => $currency, 'referenceType' => 'manual', 'performedBy' => $adminUserID];
        if ($reference !== '') {
            $depositOptions['metadata'] = ['externalReference' => $reference];
        }

        $result = CreditManager::deposit($ownerType, $ownerID, $amount, $description, $depositOptions);

        if (!($result['success'] ?? false)) {
            throw new Exception($result['message'] ?? 'Credit deposit failed.');
        }

        // 🩹 B-074 fix: deposit() already returns the post-deposit balance —
        // use it directly rather than a SECOND (previously mis-ordered)
        // getBalance() call (see handleAdjustBalance()'s own comment for the
        // exact argument-order bug this avoids).
        $newBalance = (float)($result['newBalance'] ?? CreditManager::getBalance($ownerType, $ownerID, null, $currency));
    } else {
        /**
         * 🔧 FALLBACK — Direct database deposit if CreditManager is unavailable.
         */
        $newBalance = performDirectAdjustment($db, $ownerType, $ownerID, $amount, $currency, $description, $adminUserID, 'deposit', $reference);
    }

    // 📝 Log the deposit
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            $adminUserID,
            'credit_deposit',
            'payment',
            'info',
            'Credit deposit: +' . $amount . ' ' . $currency . ' for ' . $ownerType . ' #' . $ownerID,
            [
                'ownerType'   => $ownerType,
                'ownerID'     => $ownerID,
                'amount'      => $amount,
                'currency'    => $currency,
                'description' => $description,
                'reference'   => $reference,
                'newBalance'  => $newBalance
            ]
        );
    }

    // 📤 Return success response
    echo json_encode([
        'success'    => true,
        'message'    => 'Credits deposited successfully.',
        'newBalance' => $newBalance
    ]);
}


/**
 * 📉 Handle POST withdraw_credits — Remove credits from an account
 *
 * Withdraws (removes) a positive amount of credits from the specified account.
 * Validates that the amount is positive and that the account has sufficient
 * balance for the withdrawal.
 *
 * Required Parameters (POST JSON body):
 *   - ownerType   (string) — 'user' or 'partner'
 *   - ownerID     (int)    — The owner's numeric ID
 *   - amount      (float)  — Positive amount to withdraw
 *   - currency    (string) — ISO 4217 currency code
 *   - description (string) — Reason for the withdrawal
 *   - reference   (string) — Optional external reference ID
 *
 * @param array $input — Parsed JSON input from php://input
 */
function handleWithdrawCredits(array $input): void
{
    global $db, $userInfo;

    // ═══════════════════════════════════════════════════════════════════
    // ✅ INPUT VALIDATION
    // ═══════════════════════════════════════════════════════════════════

    $ownerType   = trim($input['ownerType'] ?? '');
    $ownerID     = (int)($input['ownerID'] ?? 0);
    $amount      = (float)($input['amount'] ?? 0);
    $currency    = strtoupper(trim($input['currency'] ?? 'GBP'));
    $description = trim($input['description'] ?? '');
    $reference   = trim($input['reference'] ?? '');

    /**
     * 🚫 Validate owner type.
     */
    if (!in_array($ownerType, ['user', 'partner'], true)) {
        throw new Exception('Invalid owner type. Must be "user" or "partner".');
    }

    /**
     * 🚫 Validate owner ID.
     */
    if ($ownerID <= 0) {
        throw new Exception('Invalid owner ID. Must be a positive integer.');
    }

    /**
     * 🚫 Validate amount — must be positive (withdrawal amount).
     */
    if ($amount <= 0) {
        throw new Exception('Withdrawal amount must be a positive value.');
    }

    /**
     * 🚫 Validate description.
     */
    if ($description === '') {
        throw new Exception('Description is required for credit withdrawals.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 💰 PERFORM WITHDRAWAL
    // ═══════════════════════════════════════════════════════════════════

    $adminUserID = (int)($userInfo['userID'] ?? 0);
    $newBalance  = 0;

    if (class_exists('CreditManager')) {
        /**
         * 💰 Use CreditManager for the withdrawal operation.
         * CreditManager handles balance sufficiency checks internally.
         *
         * 🩹 B-074 fix: the REAL signature is
         * `withdraw(string $ownerType, int $ownerID, float $amount,
         * string $description, array $options = [])` — same call-shape bug
         * as handleDepositCredits() (see its comment): $currency landed in
         * $description and $description (a string) landed in the
         * `array $options` parameter, a fatal TypeError.
         * @see /private_html/payments/CreditManager.php — CreditManager::withdraw()
         */
        $withdrawOptions = ['currency' => $currency, 'referenceType' => 'manual', 'performedBy' => $adminUserID];
        if ($reference !== '') {
            $withdrawOptions['metadata'] = ['externalReference' => $reference];
        }

        $result = CreditManager::withdraw($ownerType, $ownerID, $amount, $description, $withdrawOptions);

        if (!($result['success'] ?? false)) {
            throw new Exception($result['message'] ?? 'Credit withdrawal failed.');
        }

        // 🩹 B-074 fix: withdraw() already returns the post-withdrawal
        // balance — see handleAdjustBalance()'s comment for the exact
        // getBalance() argument-order bug this avoids.
        $newBalance = (float)($result['newBalance'] ?? CreditManager::getBalance($ownerType, $ownerID, null, $currency));
    } else {
        /**
         * 🔧 FALLBACK — Direct database withdrawal with balance check.
         * The negative amount is passed to performDirectAdjustment().
         */
        $negativeAmount = -1 * abs($amount);
        $newBalance     = performDirectAdjustment($db, $ownerType, $ownerID, $negativeAmount, $currency, $description, $adminUserID, 'withdrawal', $reference);
    }

    // 📝 Log the withdrawal
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            $adminUserID,
            'credit_withdrawal',
            'payment',
            'info',
            'Credit withdrawal: -' . $amount . ' ' . $currency . ' from ' . $ownerType . ' #' . $ownerID,
            [
                'ownerType'   => $ownerType,
                'ownerID'     => $ownerID,
                'amount'      => $amount,
                'currency'    => $currency,
                'description' => $description,
                'reference'   => $reference,
                'newBalance'  => $newBalance
            ]
        );
    }

    // 📤 Return success response
    echo json_encode([
        'success'    => true,
        'message'    => 'Credits withdrawn successfully.',
        'newBalance' => $newBalance
    ]);
}


// ═══════════════════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════════════════════
//
// 🔧 UTILITY FUNCTIONS — Direct database operations (CreditManager fallback)
//
// ═══════════════════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════════════════════


/**
 * 🔧 Perform a direct credit balance adjustment using MySQLi prepared statements.
 *
 * This function is the FALLBACK used when the CreditManager class is not available
 * (e.g., if the private_html/payments/CreditManager.php file has not been deployed yet).
 * It performs the same operations that CreditManager would:
 *   1. Check if a balance record exists (INSERT or UPDATE)
 *   2. Update the balance
 *   3. Record the transaction in tblCreditTransactions
 *
 * Uses MySQL transactions (BEGIN/COMMIT/ROLLBACK) to ensure atomicity.
 *
 * @param Database $db            — Database instance
 * @param string   $ownerType     — 'user' or 'partner'
 * @param int      $ownerID       — Owner's numeric ID
 * @param float    $amount        — Amount to adjust (positive or negative)
 * @param string   $currency      — ISO 4217 currency code
 * @param string   $description   — Reason for the adjustment
 * @param int      $adminUserID   — ID of the admin performing the action
 * @param string   $txType        — Transaction type (default: 'adjustment')
 * @param string   $reference     — Optional external reference
 *
 * @return float The new balance after the adjustment
 *
 * @throws Exception If the balance would go negative on a withdrawal
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/commit.html — MySQL Transactions
 * @see https://www.php.net/manual/en/mysqli.begin-transaction.php — MySQLi Transactions
 */
function performDirectAdjustment(
    $db,
    string $ownerType,
    int $ownerID,
    float $amount,
    string $currency,
    string $description,
    int $adminUserID,
    string $txType = 'adjustment',
    string $reference = ''
): float {
    /**
     * 🔐 Get the underlying MySQLi connection for transaction control.
     * We need direct access to begin/commit/rollback transactions.
     */
    $conn = $db->getConnection();

    // 🔒 Start a database transaction for atomicity
    $conn->begin_transaction();

    try {
        // ═══════════════════════════════════════════════════════════════
        // 🔍 Step 1: Check if a balance record exists for this owner + currency
        // ═══════════════════════════════════════════════════════════════

        $checkSql  = "SELECT balanceID, balance FROM tblCreditBalances
                      WHERE ownerType = ? AND ownerID = ? AND currency = ?
                      LIMIT 1 FOR UPDATE";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param('sis', $ownerType, $ownerID, $currency);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();

        $newBalance = 0;

        if ($existing) {
            // ═══════════════════════════════════════════════════════════
            // 📝 Step 2a: Balance record EXISTS — Update the balance
            // ═══════════════════════════════════════════════════════════

            $currentBalance = (float)$existing['balance'];
            $newBalance     = $currentBalance + $amount;

            /**
             * 🚫 Check for insufficient balance on withdrawals.
             * We don't allow balances to go below zero.
             */
            if ($newBalance < 0 && $txType === 'withdrawal') {
                throw new Exception(
                    'Insufficient balance. Current balance: ' . number_format($currentBalance, 2) .
                    ' ' . $currency . '. Requested withdrawal: ' . number_format(abs($amount), 2) . ' ' . $currency
                );
            }

            /**
             * 💰 Update the existing balance record.
             */
            $updateSql  = "UPDATE tblCreditBalances
                           SET balance = ?, updatedAt = NOW()
                           WHERE balanceID = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param('di', $newBalance, $existing['balanceID']);
            $updateStmt->execute();
        } else {
            // ═══════════════════════════════════════════════════════════
            // 📝 Step 2b: Balance record DOES NOT EXIST — Insert a new one
            // ═══════════════════════════════════════════════════════════

            /**
             * 🚫 Cannot withdraw from a non-existent balance.
             */
            if ($amount < 0 && $txType === 'withdrawal') {
                throw new Exception('Cannot withdraw from a non-existent credit balance.');
            }

            $newBalance = $amount;

            /**
             * 💰 Insert a new balance record for this owner + currency.
             */
            $insertSql  = "INSERT INTO tblCreditBalances (ownerType, ownerID, balance, currency, createdAt, updatedAt)
                           VALUES (?, ?, ?, ?, NOW(), NOW())";
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->bind_param('sids', $ownerType, $ownerID, $newBalance, $currency);
            $insertStmt->execute();
        }

        // ═══════════════════════════════════════════════════════════════
        // 📋 Step 3: Record the transaction in tblCreditTransactions
        // ═══════════════════════════════════════════════════════════════

        /**
         * 📋 Insert a transaction record for the audit trail.
         * This records every credit movement with full context.
         */
        $txSql  = "INSERT INTO tblCreditTransactions
                       (ownerType, ownerID, transactionType, amount, balanceAfter, currency,
                        description, reference, adminUserID, createdAt)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $txStmt = $conn->prepare($txSql);
        $txStmt->bind_param(
            'sisddsssi',
            $ownerType,
            $ownerID,
            $txType,
            $amount,
            $newBalance,
            $currency,
            $description,
            $reference,
            $adminUserID
        );
        $txStmt->execute();

        // ✅ Commit the transaction — all operations succeeded
        $conn->commit();

        return $newBalance;

    } catch (Exception $e) {
        // ❌ Rollback on any error to maintain data integrity
        $conn->rollback();
        throw $e;
    }
}
