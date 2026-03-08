<?php
/**
 * ============================================================================
 * 📋 SIGNula - Admin Audit Log API Endpoint
 * ============================================================================
 *
 * Purpose: AJAX endpoint for the admin audit log viewer. Provides paginated
 *          log retrieval, single-entry detail, CSV export, and summary stats
 *          from tblActivityLog with tblUsers JOIN.
 *
 * Requires: Super Admin authentication for all actions.
 * Methods:  GET (read-only actions), POST (state-changing actions with CSRF).
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Admin\API
 * @version    1.0.0
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php — MySQLi Prepared Statements
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Query_Parameterization_Cheat_Sheet.html — Query Parameterisation
 * @see https://www.php.net/manual/en/function.fputcsv.php — CSV Output
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 🚫 SIGNULA_INIT Guard — Prevent direct access outside the application
// ============================================================================
if (!defined('SIGNULA_INIT')) {
    // Define it here since this is an entry-point file loaded via HTTP request
    define('SIGNULA_INIT', true);
}

// ============================================================================
// 📦 Dependencies — Load core configuration and services
// ============================================================================

/**
 * 🔧 Load the main configuration file which bootstraps the application,
 * sets up autoloading, database connection, session handling, and defines
 * global helper functions (getSetting, getClientIP, getUserAgent, etc.).
 *
 * @see web/_config/config.php — Application bootstrap
 */
$configPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'Configuration file not found']));
}
require_once $configPath;

/**
 * 🗄️ Database singleton for MySQLi prepared statements
 * @see web/_backend/Database.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

/**
 * 🔐 Session management for authentication checks
 * @see web/_backend/SessionManager.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

/**
 * 🛡️ Access control for role-based permission enforcement
 * @see web/_backend/AccessControl.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// ============================================================================
// 📋 Set JSON Response Header
// ============================================================================
// All responses from this endpoint are JSON (except CSV export)
header('Content-Type: application/json');

// ============================================================================
// 🔌 Initialise Core Services
// ============================================================================
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// ============================================================================
// 🔒 Authentication Check — Must be logged in
// ============================================================================
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized — you must be logged in to access this resource.']);
    exit;
}

// ============================================================================
// 🛡️ Permission Check — Super Admin Only
// ============================================================================
// The audit log contains sensitive information about all user activities,
// so access is restricted to super administrators only.
$accessControl->requireSuperAdmin();

// ============================================================================
// 📥 Determine Request Method and Extract Action
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 📖 GET requests are read-only (no CSRF needed)
    $action = $_GET['action'] ?? '';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔐 Validate Content-Type for POST requests to prevent CSRF via form submissions
    // @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        http_response_code(415);
        echo json_encode(['success' => false, 'error' => 'Content-Type must be application/json']);
        exit;
    }

    // 📥 Parse JSON request body
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON in request body']);
        exit;
    }

    $action = $input['action'] ?? '';

    // 🛡️ Verify CSRF token for state-changing requests
    // @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
        exit;
    }
} else {
    // ❌ Method not allowed
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET or POST.']);
    exit;
}

// ============================================================================
// 🎯 Route to Appropriate Handler
// ============================================================================
try {
    switch ($action) {

        // ====================================================================
        // 📋 get_logs — Paginated log retrieval with filters
        // ====================================================================
        case 'get_logs':
            handleGetLogs($db);
            break;

        // ====================================================================
        // 🔍 get_log_detail — Single log entry with full details
        // ====================================================================
        case 'get_log_detail':
            handleGetLogDetail($db);
            break;

        // ====================================================================
        // 📥 export_logs — CSV download with same filters as get_logs
        // ====================================================================
        case 'export_logs':
            handleExportLogs($db);
            break;

        // ====================================================================
        // 📊 get_stats — Summary statistics for dashboard cards
        // ====================================================================
        case 'get_stats':
            handleGetStats($db);
            break;

        // ====================================================================
        // ❌ Unknown action
        // ====================================================================
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Unknown action: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')
            ]);
            break;
    }
} catch (Exception $e) {
    // ============================================================================
    // ⚠️ Global Exception Handler — Log and return generic error
    // ============================================================================
    // Log the full error for debugging but only expose a safe message to the client
    if (class_exists('ErrorLogger')) {
        ErrorLogger::logError(
            'AuditLogAPI',
            $e->getCode(),
            'Audit log API error',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An internal error occurred. Please try again later.']);
}

exit;

// ============================================================================
// ============================================================================
// 🔧 HANDLER FUNCTIONS
// ============================================================================
// ============================================================================

/**
 * 📋 Handle Get Logs — Paginated log retrieval with dynamic filtering
 *
 * Builds a WHERE clause dynamically based on provided filter parameters
 * and returns a paginated result set with user information from tblUsers.
 *
 * Supported filters:
 * - user_id:       Exact match on tblActivityLog.userID
 * - activity_type: Exact match on activityType
 * - category:      Mapped to severity for backward compatibility
 * - result:        Exact match on activityResult (success/failure/warning/info)
 * - ip_address:    Exact match on ipAddress
 * - date_from:     Logs created on or after this date (YYYY-MM-DD)
 * - date_to:       Logs created on or before this date (YYYY-MM-DD 23:59:59)
 * - search:        LIKE search across activityDetails and activityType
 *
 * @param object $db Database instance (MySQLi wrapper)
 *
 * @return void Outputs JSON response directly
 *
 * @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php — bind_param types
 */
function handleGetLogs(object $db): void
{
    // 📐 Pagination parameters with sensible defaults and bounds
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 50)));
    $offset  = ($page - 1) * $perPage;

    // 🔍 Filter parameters — sanitise inputs
    $userId       = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
    $activityType = isset($_GET['activity_type']) && $_GET['activity_type'] !== '' ? trim($_GET['activity_type']) : null;
    $category     = isset($_GET['category']) && $_GET['category'] !== '' ? trim($_GET['category']) : null;
    $result       = isset($_GET['result']) && $_GET['result'] !== '' ? trim($_GET['result']) : null;
    $ipAddress    = isset($_GET['ip_address']) && $_GET['ip_address'] !== '' ? trim($_GET['ip_address']) : null;
    $dateFrom     = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? trim($_GET['date_from']) : null;
    $dateTo       = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? trim($_GET['date_to']) : null;
    $search       = isset($_GET['search']) && $_GET['search'] !== '' ? trim($_GET['search']) : null;

    // 🏗️ Build dynamic WHERE clause using prepared statement placeholders
    $conditions = [];
    $params     = [];
    $types      = '';

    // 👤 Filter by user ID
    if ($userId !== null) {
        $conditions[] = 'a.userID = ?';
        $params[]     = $userId;
        $types       .= 'i';
    }

    // 🏷️ Filter by activity type (exact match)
    if ($activityType !== null) {
        $conditions[] = 'a.activityType = ?';
        $params[]     = $activityType;
        $types       .= 's';
    }

    // 📂 Filter by category (maps to severity field in tblActivityLog)
    // The tblActivityLog uses 'severity' as a categorisation field
    if ($category !== null) {
        $conditions[] = 'a.severity = ?';
        $params[]     = $category;
        $types       .= 's';
    }

    // ✅ Filter by result (success/failure/warning/info)
    if ($result !== null) {
        $conditions[] = 'a.activityResult = ?';
        $params[]     = $result;
        $types       .= 's';
    }

    // 🌐 Filter by IP address (exact match for security auditing)
    if ($ipAddress !== null) {
        $conditions[] = 'a.ipAddress = ?';
        $params[]     = $ipAddress;
        $types       .= 's';
    }

    // 📅 Filter by date range — from date (inclusive)
    if ($dateFrom !== null) {
        $conditions[] = 'a.createdAt >= ?';
        $params[]     = $dateFrom . ' 00:00:00';
        $types       .= 's';
    }

    // 📅 Filter by date range — to date (inclusive, end of day)
    if ($dateTo !== null) {
        $conditions[] = 'a.createdAt <= ?';
        $params[]     = $dateTo . ' 23:59:59';
        $types       .= 's';
    }

    // 🔎 Free-text search across activity details and type
    if ($search !== null) {
        $conditions[] = '(a.activityDetails LIKE ? OR a.activityType LIKE ?)';
        $searchTerm   = '%' . $search . '%';
        $params[]     = $searchTerm;
        $params[]     = $searchTerm;
        $types       .= 'ss';
    }

    // 🏗️ Assemble WHERE clause
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // ============================================================================
    // 📊 Count total matching records (for pagination metadata)
    // ============================================================================
    $countQuery = "
        SELECT COUNT(*) AS total
        FROM tblActivityLog a
        LEFT JOIN tblUsers u ON a.userID = u.userID
        {$whereClause}
    ";

    $countStmt = $db->prepare($countQuery);
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $totalRows = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // ============================================================================
    // 📖 Fetch paginated log entries with user details
    // ============================================================================
    $dataQuery = "
        SELECT
            a.activityID,
            a.userID,
            a.activityType,
            a.activityResult,
            a.activityDetails,
            a.ipAddress,
            a.userAgent,
            a.requestUri,
            a.severity,
            a.createdAt,
            u.displayName,
            u.email,
            u.username
        FROM tblActivityLog a
        LEFT JOIN tblUsers u ON a.userID = u.userID
        {$whereClause}
        ORDER BY a.createdAt DESC
        LIMIT ? OFFSET ?
    ";

    // Append pagination params to the prepared statement
    $dataTypes  = $types . 'ii';
    $dataParams = array_merge($params, [$perPage, $offset]);

    $dataStmt = $db->prepare($dataQuery);
    if (!empty($dataParams)) {
        $dataStmt->bind_param($dataTypes, ...$dataParams);
    }
    $dataStmt->execute();
    $resultSet = $dataStmt->get_result();

    // 📦 Build response data array
    $logs = [];
    while ($row = $resultSet->fetch_assoc()) {
        $logs[] = [
            'activityID'      => (int)$row['activityID'],
            'userID'          => $row['userID'] !== null ? (int)$row['userID'] : null,
            'displayName'     => $row['displayName'] ?? $row['username'] ?? 'System',
            'email'           => $row['email'] ?? '',
            'activityType'    => $row['activityType'],
            'activityResult'  => $row['activityResult'],
            'activityDetails' => $row['activityDetails'],
            'ipAddress'       => $row['ipAddress'],
            'userAgent'       => $row['userAgent'],
            'requestUri'      => $row['requestUri'],
            'severity'        => $row['severity'],
            'createdAt'       => $row['createdAt'],
        ];
    }
    $dataStmt->close();

    // 📤 Return paginated response
    $totalPages = (int)ceil($totalRows / $perPage);
    echo json_encode([
        'success'     => true,
        'data'        => $logs,
        'total'       => $totalRows,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $totalPages,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 🔍 Handle Get Log Detail — Retrieve a single log entry with full information
 *
 * Returns the complete row from tblActivityLog with joined user data
 * for display in the detail modal.
 *
 * @param object $db Database instance (MySQLi wrapper)
 *
 * @return void Outputs JSON response directly
 *
 * @see https://www.php.net/manual/en/mysqli-stmt.get-result.php — get_result()
 */
function handleGetLogDetail(object $db): void
{
    // 📋 Validate log_id parameter
    $logId = isset($_GET['log_id']) ? (int)$_GET['log_id'] : 0;
    if ($logId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid or missing log_id parameter.']);
        return;
    }

    // 📖 Fetch the full log entry with user details
    $query = "
        SELECT
            a.activityID,
            a.userID,
            a.activityType,
            a.activityResult,
            a.activityDetails,
            a.ipAddress,
            a.userAgent,
            a.requestUri,
            a.severity,
            a.createdAt,
            u.displayName,
            u.email,
            u.username,
            u.firstName,
            u.lastName,
            u.accountStatus
        FROM tblActivityLog a
        LEFT JOIN tblUsers u ON a.userID = u.userID
        WHERE a.activityID = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $logId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    // ❌ Not found
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Log entry not found.']);
        return;
    }

    // 📤 Return full detail
    echo json_encode([
        'success' => true,
        'data'    => [
            'activityID'      => (int)$row['activityID'],
            'userID'          => $row['userID'] !== null ? (int)$row['userID'] : null,
            'displayName'     => $row['displayName'] ?? $row['username'] ?? 'System',
            'email'           => $row['email'] ?? '',
            'firstName'       => $row['firstName'] ?? '',
            'lastName'        => $row['lastName'] ?? '',
            'accountStatus'   => $row['accountStatus'] ?? '',
            'activityType'    => $row['activityType'],
            'activityResult'  => $row['activityResult'],
            'activityDetails' => $row['activityDetails'],
            'ipAddress'       => $row['ipAddress'],
            'userAgent'       => $row['userAgent'],
            'requestUri'      => $row['requestUri'],
            'severity'        => $row['severity'],
            'createdAt'       => $row['createdAt'],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 📥 Handle Export Logs — CSV download with the same filters as get_logs
 *
 * Generates a CSV file from tblActivityLog matching the current filter
 * criteria. No pagination is applied — all matching rows are exported.
 *
 * Sets appropriate HTTP headers for a file download attachment.
 *
 * @param object $db Database instance (MySQLi wrapper)
 *
 * @return void Outputs CSV directly to the response stream
 *
 * @see https://www.php.net/manual/en/function.fputcsv.php — fputcsv()
 * @see https://tools.ietf.org/html/rfc4180 — RFC 4180 (CSV format)
 */
function handleExportLogs(object $db): void
{
    // 🔍 Reuse the same filter logic as get_logs
    $userId       = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
    $activityType = isset($_GET['activity_type']) && $_GET['activity_type'] !== '' ? trim($_GET['activity_type']) : null;
    $category     = isset($_GET['category']) && $_GET['category'] !== '' ? trim($_GET['category']) : null;
    $result       = isset($_GET['result']) && $_GET['result'] !== '' ? trim($_GET['result']) : null;
    $ipAddress    = isset($_GET['ip_address']) && $_GET['ip_address'] !== '' ? trim($_GET['ip_address']) : null;
    $dateFrom     = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? trim($_GET['date_from']) : null;
    $dateTo       = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? trim($_GET['date_to']) : null;
    $search       = isset($_GET['search']) && $_GET['search'] !== '' ? trim($_GET['search']) : null;

    // 🏗️ Build dynamic WHERE clause (same logic as handleGetLogs)
    $conditions = [];
    $params     = [];
    $types      = '';

    if ($userId !== null) {
        $conditions[] = 'a.userID = ?';
        $params[]     = $userId;
        $types       .= 'i';
    }
    if ($activityType !== null) {
        $conditions[] = 'a.activityType = ?';
        $params[]     = $activityType;
        $types       .= 's';
    }
    if ($category !== null) {
        $conditions[] = 'a.severity = ?';
        $params[]     = $category;
        $types       .= 's';
    }
    if ($result !== null) {
        $conditions[] = 'a.activityResult = ?';
        $params[]     = $result;
        $types       .= 's';
    }
    if ($ipAddress !== null) {
        $conditions[] = 'a.ipAddress = ?';
        $params[]     = $ipAddress;
        $types       .= 's';
    }
    if ($dateFrom !== null) {
        $conditions[] = 'a.createdAt >= ?';
        $params[]     = $dateFrom . ' 00:00:00';
        $types       .= 's';
    }
    if ($dateTo !== null) {
        $conditions[] = 'a.createdAt <= ?';
        $params[]     = $dateTo . ' 23:59:59';
        $types       .= 's';
    }
    if ($search !== null) {
        $conditions[] = '(a.activityDetails LIKE ? OR a.activityType LIKE ?)';
        $searchTerm   = '%' . $search . '%';
        $params[]     = $searchTerm;
        $params[]     = $searchTerm;
        $types       .= 'ss';
    }

    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // 📖 Fetch all matching rows (no pagination for export)
    $query = "
        SELECT
            a.createdAt,
            COALESCE(u.displayName, u.username, 'System') AS userName,
            u.email,
            a.activityType,
            a.severity,
            a.activityResult,
            a.activityDetails,
            a.ipAddress,
            a.userAgent
        FROM tblActivityLog a
        LEFT JOIN tblUsers u ON a.userID = u.userID
        {$whereClause}
        ORDER BY a.createdAt DESC
    ";

    $stmt = $db->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $resultSet = $stmt->get_result();

    // 📄 Set CSV download headers
    // Override the previously set application/json content type
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="signula_audit_log_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // 📝 Open output stream and write CSV
    $output = fopen('php://output', 'w');

    // 🔤 Write UTF-8 BOM for Excel compatibility
    // @see https://en.wikipedia.org/wiki/Byte_order_mark — BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");

    // 📋 CSV header row
    fputcsv($output, [
        'Date',
        'User',
        'Email',
        'Activity Type',
        'Category',
        'Result',
        'Details',
        'IP Address',
        'User Agent',
    ]);

    // 📊 Write data rows
    while ($row = $resultSet->fetch_assoc()) {
        fputcsv($output, [
            $row['createdAt'],
            $row['userName'],
            $row['email'] ?? '',
            $row['activityType'],
            $row['severity'],
            $row['activityResult'],
            $row['activityDetails'] ?? '',
            $row['ipAddress'] ?? '',
            $row['userAgent'] ?? '',
        ]);
    }

    fclose($output);
    $stmt->close();

    // 🛑 Exit immediately — no further JSON output
    exit;
}

/**
 * 📊 Handle Get Stats — Summary statistics for the dashboard cards
 *
 * Returns aggregate counts and top-N breakdowns for the audit log summary
 * section. All queries use COUNT/GROUP BY for efficient aggregation.
 *
 * Response structure:
 * - totals:       Overall counts (total, today, this_week, unique_users, unique_ips)
 * - top_types:    Top 5 activity types by count
 * - top_users:    Top 5 most active users by count (with displayName)
 * - failed_today: Count of failed activities today
 *
 * @param object $db Database instance (MySQLi wrapper)
 *
 * @return void Outputs JSON response directly
 */
function handleGetStats(object $db): void
{
    $stats = [];

    // ============================================================================
    // 📈 Totals — Overall, today, this week, unique users, unique IPs
    // ============================================================================

    // 📊 Total log entries
    $totalStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM tblActivityLog");
    $totalStmt->execute();
    $stats['totals']['total'] = (int)$totalStmt->get_result()->fetch_assoc()['cnt'];
    $totalStmt->close();

    // 📅 Logs created today
    $todayStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM tblActivityLog WHERE DATE(createdAt) = CURDATE()");
    $todayStmt->execute();
    $stats['totals']['today'] = (int)$todayStmt->get_result()->fetch_assoc()['cnt'];
    $todayStmt->close();

    // 📅 Logs created this week (Monday-based ISO week)
    $weekStmt = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM tblActivityLog
        WHERE createdAt >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
    ");
    $weekStmt->execute();
    $stats['totals']['this_week'] = (int)$weekStmt->get_result()->fetch_assoc()['cnt'];
    $weekStmt->close();

    // 👥 Unique users who have generated log entries
    $usersStmt = $db->prepare("SELECT COUNT(DISTINCT userID) AS cnt FROM tblActivityLog WHERE userID IS NOT NULL");
    $usersStmt->execute();
    $stats['totals']['unique_users'] = (int)$usersStmt->get_result()->fetch_assoc()['cnt'];
    $usersStmt->close();

    // 🌐 Unique IP addresses in the log
    $ipsStmt = $db->prepare("SELECT COUNT(DISTINCT ipAddress) AS cnt FROM tblActivityLog WHERE ipAddress IS NOT NULL");
    $ipsStmt->execute();
    $stats['totals']['unique_ips'] = (int)$ipsStmt->get_result()->fetch_assoc()['cnt'];
    $ipsStmt->close();

    // ============================================================================
    // 🏷️ Top 5 Activity Types — Most common activities
    // ============================================================================
    $typesStmt = $db->prepare("
        SELECT activityType, COUNT(*) AS cnt
        FROM tblActivityLog
        GROUP BY activityType
        ORDER BY cnt DESC
        LIMIT 5
    ");
    $typesStmt->execute();
    $typesResult = $typesStmt->get_result();
    $stats['top_types'] = [];
    while ($row = $typesResult->fetch_assoc()) {
        $stats['top_types'][] = [
            'type'  => $row['activityType'],
            'count' => (int)$row['cnt'],
        ];
    }
    $typesStmt->close();

    // ============================================================================
    // 👤 Top 5 Most Active Users — Users generating the most log entries
    // ============================================================================
    $topUsersStmt = $db->prepare("
        SELECT
            a.userID,
            COALESCE(u.displayName, u.username, 'Unknown') AS userName,
            COUNT(*) AS cnt
        FROM tblActivityLog a
        LEFT JOIN tblUsers u ON a.userID = u.userID
        WHERE a.userID IS NOT NULL
        GROUP BY a.userID, u.displayName, u.username
        ORDER BY cnt DESC
        LIMIT 5
    ");
    $topUsersStmt->execute();
    $topUsersResult = $topUsersStmt->get_result();
    $stats['top_users'] = [];
    while ($row = $topUsersResult->fetch_assoc()) {
        $stats['top_users'][] = [
            'userID' => (int)$row['userID'],
            'name'   => $row['userName'],
            'count'  => (int)$row['cnt'],
        ];
    }
    $topUsersStmt->close();

    // ============================================================================
    // ❌ Failed Activities Today — Count of failures for alerting
    // ============================================================================
    $failedStmt = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM tblActivityLog
        WHERE activityResult = 'failure'
          AND DATE(createdAt) = CURDATE()
    ");
    $failedStmt->execute();
    $stats['failed_today'] = (int)$failedStmt->get_result()->fetch_assoc()['cnt'];
    $failedStmt->close();

    // 📤 Return stats
    echo json_encode([
        'success' => true,
        'data'    => $stats,
    ], JSON_UNESCAPED_UNICODE);
}

// ✅ Audit Log Actions endpoint loaded successfully
