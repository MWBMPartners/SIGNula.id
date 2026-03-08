<?php
/**
 * 🛡️ Rate Limit Administration API (Super Admin)
 *
 * AJAX endpoint for the Rate Limiting Dashboard. Handles all read and write
 * operations for IP blocks, rate limit entries, and related settings.
 *
 * GET Actions:
 *   - get_stats          — Summary statistics (blocks, events, top IPs, settings)
 *   - get_blocked_ips    — Paginated list of actively blocked IPs from tblBlockedIPs
 *   - get_rate_limits    — Paginated + filterable list from tblRateLimits
 *   - get_whitelist      — Whitelisted IPs from tblSettings (security.ip_whitelist)
 *
 * POST Actions (CSRF required):
 *   - block_ip           — Manually block an IP address via IPReputationChecker
 *   - unblock_ip         — Deactivate an IP block in tblBlockedIPs
 *   - clear_rate_limits  — Remove rate limit entries for a specific IP
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 * @see https://cheatsheetseries.owasp.org/cheatsheets/REST_Security_Cheat_Sheet.html
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Admin\API
 * @version    1.0.0
 * @since      2026-03-08
 */

// 🔒 SIGNULA_INIT guard — prevent direct access outside the application
if (!defined('SIGNULA_INIT')) {
    define('SIGNULA_INIT', true);
}

// 📦 Load core configuration and dependencies
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 🔧 Conditionally load security utilities (they may already be autoloaded)
$securityPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR;
if (!class_exists('IPReputationChecker') && file_exists($securityPath . 'IPReputationChecker.php')) {
    require_once $securityPath . 'IPReputationChecker.php';
}

// 📋 Set JSON response header for all responses
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
header('Content-Type: application/json');

// 🔌 Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check — must be logged in
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized — please log in.']);
    exit;
}

// 🛡️ Permission check — Super Admin only
// AccessControl::requireSuperAdmin() will die() with 403 if not a super admin
$accessControl->requireSuperAdmin();

// 📥 Determine action based on HTTP method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 📖 Read operations — action comes from query string
    $action = $_GET['action'] ?? '';
} else {
    // ✏️ Write operations — action comes from JSON body
    // @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        http_response_code(415);
        echo json_encode(['success' => false, 'error' => 'Content-Type must be application/json']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if ($input === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON request body.']);
        exit;
    }
    $action = $input['action'] ?? '';
}

// 🛡️ Verify CSRF token for state-changing (POST) requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
        exit;
    }
}

// 🎯 Route to the appropriate handler based on action
try {
    switch ($action) {

        // ===================================================================
        // 📊 GET: Dashboard Statistics
        // ===================================================================
        case 'get_stats':
            handleGetStats();
            break;

        // ===================================================================
        // 📋 GET: Paginated Blocked IPs
        // ===================================================================
        case 'get_blocked_ips':
            handleGetBlockedIPs();
            break;

        // ===================================================================
        // 📋 GET: Paginated Rate Limit Entries
        // ===================================================================
        case 'get_rate_limits':
            handleGetRateLimits();
            break;

        // ===================================================================
        // 📋 GET: IP Whitelist
        // ===================================================================
        case 'get_whitelist':
            handleGetWhitelist();
            break;

        // ===================================================================
        // 🚫 POST: Block an IP Address
        // ===================================================================
        case 'block_ip':
            handleBlockIP($input, $sessionManager);
            break;

        // ===================================================================
        // ✅ POST: Unblock an IP Address
        // ===================================================================
        case 'unblock_ip':
            handleUnblockIP($input, $sessionManager);
            break;

        // ===================================================================
        // 🧹 POST: Clear Rate Limit Entries for an IP
        // ===================================================================
        case 'clear_rate_limits':
            handleClearRateLimits($input, $sessionManager);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action specified.']);
            break;
    }
} catch (Exception $e) {
    // 🔐 Never expose raw exception details in API responses (CWE-209)
    // @see https://cwe.mitre.org/data/definitions/209.html
    if (class_exists('ErrorLogger')) {
        ErrorLogger::logError(
            'RATE_LIMIT_API_ERROR',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred processing your request.']);
}


// =============================================================================
// 📊 Handler: Get Dashboard Statistics
// =============================================================================

/**
 * 📊 Retrieve comprehensive dashboard statistics
 *
 * Returns:
 *   - Total active IP blocks from tblBlockedIPs
 *   - Rate limited events today (entries with isBlocked=1 and windowStart >= today)
 *   - Total entries in tblRateLimits
 *   - Top 10 rate-limited IPs by request count
 *   - Current rate limiting settings from tblSettings
 *
 * @return void Outputs JSON response
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/group-by-functions.html
 */
function handleGetStats(): void
{
    // 🔢 Count active IP blocks in tblBlockedIPs
    $activeBlocks = Database::fetchOne(
        "SELECT COUNT(*) AS `total`
         FROM `tblBlockedIPs`
         WHERE `isActive` = 1
           AND (`expiresAt` IS NULL OR `expiresAt` > NOW())"
    );

    // 🔢 Count rate limited events today (entries that triggered a block today)
    $rateLimitedToday = Database::fetchOne(
        "SELECT COUNT(*) AS `total`
         FROM `tblRateLimits`
         WHERE `isBlocked` = 1
           AND `windowStart` >= CURDATE()"
    );

    // 🔢 Total entries in tblRateLimits
    $totalEntries = Database::fetchOne(
        "SELECT COUNT(*) AS `total`
         FROM `tblRateLimits`"
    );

    // 🏆 Top 10 rate-limited IPs by total request count
    // Groups by identifier (IP) and orders by cumulative request count descending
    $topIPs = Database::fetchAll(
        "SELECT
            `identifier` AS `ip`,
            `identifierType`,
            SUM(`requestCount`) AS `totalRequests`,
            COUNT(*) AS `entryCount`,
            MAX(`windowStart`) AS `lastSeen`,
            MAX(CASE WHEN `isBlocked` = 1 THEN 1 ELSE 0 END) AS `isCurrentlyBlocked`
         FROM `tblRateLimits`
         WHERE `identifierType` = 'ip'
         GROUP BY `identifier`, `identifierType`
         ORDER BY `totalRequests` DESC
         LIMIT 10"
    );

    // ⚙️ Current rate limiting settings from tblSettings
    // Fetches all keys matching 'rate_limiting.%' pattern
    $settingsRows = Database::fetchAll(
        "SELECT `settingKey`, `settingValue`
         FROM `tblSettings`
         WHERE `settingKey` LIKE 'rate_limiting.%'
         ORDER BY `settingKey` ASC"
    );

    // 🔧 Transform settings into a key-value map for easier frontend consumption
    $settings = [];
    foreach ($settingsRows as $row) {
        // Strip the 'rate_limiting.' prefix for cleaner keys
        $key = str_replace('rate_limiting.', '', $row['settingKey']);
        $settings[$key] = $row['settingValue'];
    }

    // 🔢 Count whitelisted IPs (from settings)
    $whitelistSetting = Database::fetchOne(
        "SELECT `settingValue`
         FROM `tblSettings`
         WHERE `settingKey` = 'security.ip_whitelist'"
    );
    $whitelistCount = 0;
    if ($whitelistSetting && !empty($whitelistSetting['settingValue'])) {
        // Whitelist is stored as a comma-separated or JSON-encoded list
        $decoded = json_decode($whitelistSetting['settingValue'], true);
        if (is_array($decoded)) {
            $whitelistCount = count($decoded);
        } else {
            // Fallback: comma-separated string
            $whitelistCount = count(array_filter(explode(',', $whitelistSetting['settingValue'])));
        }
    }

    echo json_encode([
        'success'          => true,
        'activeBlocks'     => (int)($activeBlocks['total'] ?? 0),
        'rateLimitedToday' => (int)($rateLimitedToday['total'] ?? 0),
        'totalEntries'     => (int)($totalEntries['total'] ?? 0),
        'whitelistCount'   => $whitelistCount,
        'topIPs'           => $topIPs,
        'settings'         => $settings,
    ]);
}


// =============================================================================
// 📋 Handler: Get Blocked IPs (Paginated)
// =============================================================================

/**
 * 📋 Retrieve paginated list of blocked IPs from tblBlockedIPs
 *
 * Query parameters:
 *   - page (int, default 1): Page number
 *   - per_page (int, default 20, max 100): Results per page
 *
 * Includes the blocking admin's display name via LEFT JOIN on tblUsers.
 *
 * @return void Outputs JSON response
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/select.html
 */
function handleGetBlockedIPs(): void
{
    // 📄 Pagination parameters with sensible defaults and bounds
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $offset  = ($page - 1) * $perPage;

    // 🔢 Total count for pagination metadata
    $countResult = Database::fetchOne(
        "SELECT COUNT(*) AS `total`
         FROM `tblBlockedIPs`
         WHERE `isActive` = 1
           AND (`expiresAt` IS NULL OR `expiresAt` > NOW())"
    );
    $totalCount = (int)($countResult['total'] ?? 0);

    // 📋 Fetch blocked IPs with admin name via LEFT JOIN
    // LEFT JOIN ensures we still get results even if the admin user was deleted
    $blockedIPs = Database::fetchAll(
        "SELECT
            b.`blockID`,
            b.`ipAddress`,
            b.`blockType`,
            b.`reason`,
            b.`source`,
            b.`blockedAt`,
            b.`expiresAt`,
            b.`isActive`,
            b.`blockedByUserID`,
            COALESCE(
                CONCAT(u.`firstName`, ' ', u.`lastName`),
                CASE WHEN b.`blockedByUserID` IS NULL THEN 'System (Auto)' ELSE 'Unknown User' END
            ) AS `blockedByName`
         FROM `tblBlockedIPs` b
         LEFT JOIN `tblUsers` u ON b.`blockedByUserID` = u.`userID`
         WHERE b.`isActive` = 1
           AND (b.`expiresAt` IS NULL OR b.`expiresAt` > NOW())
         ORDER BY b.`blockedAt` DESC
         LIMIT ? OFFSET ?",
        [$perPage, $offset],
        'ii'
    );

    echo json_encode([
        'success'    => true,
        'data'       => $blockedIPs,
        'pagination' => [
            'page'       => $page,
            'per_page'   => $perPage,
            'total'      => $totalCount,
            'totalPages' => (int)ceil($totalCount / $perPage),
        ],
    ]);
}


// =============================================================================
// 📋 Handler: Get Rate Limit Entries (Paginated + Filtered)
// =============================================================================

/**
 * 📋 Retrieve paginated and filterable list from tblRateLimits
 *
 * Query parameters:
 *   - page (int, default 1): Page number
 *   - per_page (int, default 20, max 100): Results per page
 *   - ip (string, optional): Filter by IP address (exact match on identifier)
 *   - action (string, optional): Filter by endpoint/action
 *
 * @return void Outputs JSON response
 *
 * @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
 */
function handleGetRateLimits(): void
{
    // 📄 Pagination parameters
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $offset  = ($page - 1) * $perPage;

    // 🔍 Optional filters
    $filterIP     = isset($_GET['ip']) ? trim($_GET['ip']) : '';
    $filterAction = isset($_GET['action']) ? trim($_GET['action']) : '';

    // 🔧 Build dynamic WHERE clause based on provided filters
    $conditions = [];
    $params     = [];
    $types      = '';

    if ($filterIP !== '') {
        $conditions[] = '`identifier` = ?';
        $params[]     = $filterIP;
        $types       .= 's';
    }

    if ($filterAction !== '') {
        $conditions[] = '`endpoint` = ?';
        $params[]     = $filterAction;
        $types       .= 's';
    }

    // 📝 Construct the WHERE clause (empty = no filters applied)
    $whereClause = '';
    if (!empty($conditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $conditions);
    }

    // 🔢 Total count with filters applied
    $countResult = Database::fetchOne(
        "SELECT COUNT(*) AS `total` FROM `tblRateLimits` {$whereClause}",
        $params,
        $types
    );
    $totalCount = (int)($countResult['total'] ?? 0);

    // 📋 Fetch rate limit entries ordered by most recent first
    $paginatedParams = array_merge($params, [$perPage, $offset]);
    $paginatedTypes  = $types . 'ii';

    $rateLimits = Database::fetchAll(
        "SELECT
            `limitID`,
            `identifier` AS `ip`,
            `identifierType`,
            `endpoint`,
            `requestCount`,
            `windowStart`,
            `windowEnd`,
            `isBlocked`,
            `blockedUntil`,
            `blockReason`,
            `violationCount`
         FROM `tblRateLimits`
         {$whereClause}
         ORDER BY `windowStart` DESC
         LIMIT ? OFFSET ?",
        $paginatedParams,
        $paginatedTypes
    );

    // 📋 Also fetch distinct endpoints for the filter dropdown
    $endpoints = Database::fetchAll(
        "SELECT DISTINCT `endpoint`
         FROM `tblRateLimits`
         ORDER BY `endpoint` ASC"
    );

    echo json_encode([
        'success'    => true,
        'data'       => $rateLimits,
        'endpoints'  => array_column($endpoints, 'endpoint'),
        'pagination' => [
            'page'       => $page,
            'per_page'   => $perPage,
            'total'      => $totalCount,
            'totalPages' => (int)ceil($totalCount / $perPage),
        ],
    ]);
}


// =============================================================================
// 📋 Handler: Get IP Whitelist
// =============================================================================

/**
 * 📋 Retrieve the current IP whitelist
 *
 * Reads from tblSettings key 'security.ip_whitelist'.
 * The value may be a JSON array or a comma-separated list of IPs.
 *
 * @return void Outputs JSON response
 */
function handleGetWhitelist(): void
{
    // 📖 Fetch the whitelist setting value
    $setting = Database::fetchOne(
        "SELECT `settingValue`
         FROM `tblSettings`
         WHERE `settingKey` = 'security.ip_whitelist'"
    );

    $whitelist = [];
    if ($setting && !empty($setting['settingValue'])) {
        // 🔧 Try JSON decode first, then fall back to comma-separated parsing
        $decoded = json_decode($setting['settingValue'], true);
        if (is_array($decoded)) {
            $whitelist = $decoded;
        } else {
            // Comma-separated fallback
            $whitelist = array_map('trim', explode(',', $setting['settingValue']));
            $whitelist = array_filter($whitelist); // Remove empty entries
            $whitelist = array_values($whitelist); // Re-index
        }
    }

    echo json_encode([
        'success'   => true,
        'whitelist' => $whitelist,
        'count'     => count($whitelist),
    ]);
}


// =============================================================================
// 🚫 Handler: Block an IP Address
// =============================================================================

/**
 * 🚫 Manually block an IP address
 *
 * Validates the IP format (IPv4 or IPv6), then delegates to
 * IPReputationChecker::blockIP() for the actual insertion into tblBlockedIPs.
 *
 * Required fields:
 *   - ip_address (string): Valid IPv4 or IPv6 address
 *   - reason (string): Human-readable reason for the block
 *
 * Optional fields:
 *   - expires_at (string): ISO 8601 datetime for block expiry (null = permanent)
 *
 * @param array          $input          Parsed JSON request body
 * @param SessionManager $sessionManager Session manager for user context
 * @return void Outputs JSON response
 *
 * @see https://www.php.net/manual/en/function.filter-var.php
 * @see https://www.php.net/manual/en/filter.filters.validate.php
 */
function handleBlockIP(array $input, SessionManager $sessionManager): void
{
    // 📥 Extract and validate input fields
    $ipAddress = trim($input['ip_address'] ?? '');
    $reason    = trim($input['reason'] ?? '');
    $expiresAt = $input['expires_at'] ?? null; // ISO 8601 datetime string or null

    // ✅ Validate IP address format (supports both IPv4 and IPv6)
    // @see https://www.php.net/manual/en/filter.filters.validate.php
    if (empty($ipAddress) || !filter_var($ipAddress, FILTER_VALIDATE_IP)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'A valid IPv4 or IPv6 address is required.']);
        return;
    }

    // ✅ Validate reason is provided
    if (empty($reason)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'A reason for the block is required.']);
        return;
    }

    // ✅ Sanitise reason to prevent stored XSS
    $reason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');

    // ⏱️ Calculate duration in seconds if an expiry datetime is provided
    $durationSeconds = null;
    if ($expiresAt !== null && $expiresAt !== '') {
        $expiryTimestamp = strtotime($expiresAt);
        if ($expiryTimestamp === false || $expiryTimestamp <= time()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Expiry date must be a valid future datetime.']);
            return;
        }
        $durationSeconds = $expiryTimestamp - time();
    }

    // 🔒 Get the current admin user's ID for audit trail
    $userInfo = $sessionManager->getUserInfo();
    $adminUserID = (int)($userInfo['userID'] ?? 0);

    // 🚫 Delegate to IPReputationChecker for the actual block insertion
    if (class_exists('IPReputationChecker')) {
        $success = IPReputationChecker::blockIP(
            $ipAddress,
            $reason,
            $durationSeconds,
            $adminUserID,
            'manual' // Source: manual admin action
        );
    } else {
        // 🔧 Fallback: Direct database insertion if IPReputationChecker is unavailable
        $blockType = ($durationSeconds === null) ? 'permanent' : 'temporary';
        $expiresAtFormatted = ($durationSeconds !== null)
            ? date('Y-m-d H:i:s', time() + $durationSeconds)
            : null;

        Database::query(
            "INSERT INTO `tblBlockedIPs`
                (`ipAddress`, `blockType`, `reason`, `source`, `blockedByUserID`, `expiresAt`, `isActive`)
             VALUES (?, ?, ?, 'manual', ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                `reason` = VALUES(`reason`),
                `blockType` = VALUES(`blockType`),
                `expiresAt` = VALUES(`expiresAt`),
                `blockedByUserID` = VALUES(`blockedByUserID`),
                `isActive` = 1,
                `blockedAt` = NOW()",
            [$ipAddress, $blockType, $reason, $adminUserID, $expiresAtFormatted],
            'sssis'
        );
        $success = true;

        // 📋 Log the activity manually since IPReputationChecker wasn't available
        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                $adminUserID,
                'ip_blocked',
                'security',
                'warning',
                'IP address manually blocked: ' . $ipAddress . ' — Reason: ' . $reason,
                [
                    'ipAddress'   => $ipAddress,
                    'reason'      => $reason,
                    'source'      => 'manual',
                    'blockType'   => $blockType,
                    'expiresAt'   => $expiresAtFormatted,
                    'durationSec' => $durationSeconds,
                ]
            );
        }
    }

    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'IP address ' . htmlspecialchars($ipAddress) . ' has been blocked successfully.',
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to block the IP address. Please check logs.']);
    }
}


// =============================================================================
// ✅ Handler: Unblock an IP Address
// =============================================================================

/**
 * ✅ Unblock an IP address by setting isActive=0 in tblBlockedIPs
 *
 * Required fields:
 *   - block_id (int): The blockID from tblBlockedIPs to deactivate
 *
 * @param array          $input          Parsed JSON request body
 * @param SessionManager $sessionManager Session manager for user context
 * @return void Outputs JSON response
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/update.html
 */
function handleUnblockIP(array $input, SessionManager $sessionManager): void
{
    // 📥 Extract and validate block ID
    $blockID = (int)($input['block_id'] ?? 0);

    if ($blockID <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'A valid block ID is required.']);
        return;
    }

    // 🔍 Look up the IP address for logging before we unblock
    $existingBlock = Database::fetchOne(
        "SELECT `ipAddress` FROM `tblBlockedIPs` WHERE `blockID` = ? AND `isActive` = 1",
        [$blockID],
        'i'
    );

    if ($existingBlock === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Block not found or already inactive.']);
        return;
    }

    $ipAddress = $existingBlock['ipAddress'];

    // 🔒 Get the current admin user's ID for audit trail
    $userInfo = $sessionManager->getUserInfo();
    $adminUserID = (int)($userInfo['userID'] ?? 0);

    // ✅ Deactivate the block and record who unblocked it and when
    if (class_exists('IPReputationChecker') && method_exists('IPReputationChecker', 'unblockIP')) {
        $success = IPReputationChecker::unblockIP($ipAddress, $adminUserID, 'Unblocked via admin dashboard');
    } else {
        // 🔧 Fallback: Direct database update
        Database::query(
            "UPDATE `tblBlockedIPs`
             SET `isActive` = 0,
                 `unblockedAt` = NOW(),
                 `unblockedByUserID` = ?,
                 `unblockedReason` = 'Unblocked via admin dashboard'
             WHERE `blockID` = ? AND `isActive` = 1",
            [$adminUserID, $blockID],
            'ii'
        );
        $success = true;

        // 📋 Log the unblock action
        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                $adminUserID,
                'ip_unblocked',
                'security',
                'info',
                'IP address unblocked via admin dashboard: ' . $ipAddress,
                [
                    'ipAddress' => $ipAddress,
                    'blockID'   => $blockID,
                ]
            );
        }
    }

    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'IP address ' . htmlspecialchars($ipAddress) . ' has been unblocked.',
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to unblock the IP address.']);
    }
}


// =============================================================================
// 🧹 Handler: Clear Rate Limit Entries for an IP
// =============================================================================

/**
 * 🧹 Remove all rate limit entries from tblRateLimits for a specific IP
 *
 * This clears the rate limiting history for an identifier, effectively
 * resetting their request counter and removing any automated blocks
 * from the tblRateLimits table. Note: this does NOT affect entries in
 * tblBlockedIPs (use unblock_ip for that).
 *
 * Required fields:
 *   - ip (string): The IP address whose rate limit entries should be cleared
 *
 * @param array          $input          Parsed JSON request body
 * @param SessionManager $sessionManager Session manager for user context
 * @return void Outputs JSON response
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/delete.html
 */
function handleClearRateLimits(array $input, SessionManager $sessionManager): void
{
    // 📥 Extract and validate the IP address
    $ip = trim($input['ip'] ?? '');

    if (empty($ip)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'An IP address is required.']);
        return;
    }

    // 🔢 Count how many entries will be removed (for reporting)
    $countResult = Database::fetchOne(
        "SELECT COUNT(*) AS `total` FROM `tblRateLimits` WHERE `identifier` = ?",
        [$ip],
        's'
    );
    $deletedCount = (int)($countResult['total'] ?? 0);

    if ($deletedCount === 0) {
        echo json_encode([
            'success' => true,
            'message' => 'No rate limit entries found for ' . htmlspecialchars($ip) . '.',
            'deleted' => 0,
        ]);
        return;
    }

    // 🗑️ Delete all rate limit entries for this identifier
    Database::query(
        "DELETE FROM `tblRateLimits` WHERE `identifier` = ?",
        [$ip],
        's'
    );

    // 🔒 Get admin user ID for audit logging
    $userInfo = $sessionManager->getUserInfo();
    $adminUserID = (int)($userInfo['userID'] ?? 0);

    // 📋 Log the clearing action for audit trail
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            $adminUserID,
            'rate_limits_cleared',
            'security',
            'info',
            'Rate limit entries cleared for IP: ' . $ip . ' (' . $deletedCount . ' entries removed)',
            [
                'ipAddress'    => $ip,
                'deletedCount' => $deletedCount,
            ]
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Cleared ' . $deletedCount . ' rate limit entries for ' . htmlspecialchars($ip) . '.',
        'deleted' => $deletedCount,
    ]);
}
