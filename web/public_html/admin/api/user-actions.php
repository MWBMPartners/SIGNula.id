<?php
/**
 * 👤 User Management API (Super Admin)
 *
 * Handles all user administration actions including listing, status changes,
 * tier updates, password resets, and super admin toggling.
 *
 * All actions require Super Admin authentication and are logged to the audit trail.
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 📋 Set JSON response header
header('Content-Type: application/json');

// 🔌 Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check — must be logged in
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 🛡️ Permission check — Super Admin only
$accessControl->requireSuperAdmin();

// 📖 Allowlist of READ-ONLY actions that may be served over GET.
// Everything NOT in this list mutates state and MUST arrive via POST so it is
// forced through the Content-Type guard + CSRF verification below. Without this
// allowlist, a state-changing action reachable via GET (e.g.
// ?action=reset_password&userID=5) would skip both checks entirely (issue #28).
// @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
$readOnlyGetActions = [
    'list_users',
    'get_user',
    'get_stats',
    'get_reset_status',
    'list_reset_operations',
    'get_compliance_report',
    'get_salt_history',
];

// 📥 Get request data based on method (GET for reads, POST for writes)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    // 🛡️ Reject state-changing actions smuggled in over GET — these bypass the
    // CSRF/Content-Type gate and must use POST instead.
    if (!in_array($action, $readOnlyGetActions, true)) {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode([
            'success' => false,
            'error'   => 'This action requires a POST request.',
        ]);
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔐 Validate Content-Type for POST requests to prevent CSRF via form submissions
    // @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        http_response_code(415);
        echo json_encode(['success' => false, 'error' => 'Content-Type must be application/json']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
} else {
    // ❌ Any other method (PUT/DELETE/PATCH/…) is not supported here.
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET or POST.']);
    exit;
}

// 🛡️ Verify CSRF token for state-changing requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
        exit;
    }
}

// 🎯 Route to appropriate handler
try {
    switch ($action) {
        case 'list_users':
            handleListUsers($db);
            break;

        case 'get_user':
            handleGetUser($_GET['userID'] ?? 0, $db);
            break;

        case 'update_status':
            handleUpdateStatus($input, $db, $accessControl, $sessionManager);
            break;

        case 'update_tier':
            handleUpdateTier($input, $db, $accessControl, $sessionManager);
            break;

        case 'reset_password':
            handleResetPassword($input, $db, $accessControl, $sessionManager);
            break;

        case 'toggle_super_admin':
            handleToggleSuperAdmin($input, $db, $accessControl, $sessionManager);
            break;

        case 'unlock_user':
            handleUnlockUser($input, $db, $accessControl, $sessionManager);
            break;

        case 'get_stats':
            handleGetStats($db);
            break;

        // 🔐 Mass Credential Reset Operations
        case 'initiate_mass_reset':
            handleInitiateMassReset($input, $db, $accessControl, $sessionManager);
            break;

        case 'process_reset_batch':
            handleProcessResetBatch($input, $db);
            break;

        case 'get_reset_status':
            handleGetResetStatus($db);
            break;

        case 'list_reset_operations':
            handleListResetOperations($db);
            break;

        case 'cancel_reset':
            handleCancelReset($input, $db, $accessControl, $sessionManager);
            break;

        case 'get_compliance_report':
            handleGetComplianceReport($db);
            break;

        case 'get_salt_history':
            handleGetSaltHistory($db);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    // 🔐 Never expose raw exception details in API responses (CWE-209)
    ErrorLogger::logError('API_ERROR', $e->getMessage(), $e->getFile(), $e->getLine());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'An error occurred processing your request.']);
}

/**
 * 📋 List users with pagination, search, and filtering
 *
 * GET parameters:
 * - search: Search username, email, or displayName
 * - status: Filter by accountStatus
 * - tier: Filter by accountTier
 * - page: Page number (default: 1)
 * - perPage: Results per page (default: 25, max: 100)
 */
function handleListUsers($db) {
    // 📥 Parse filter parameters
    $search = trim($_GET['search'] ?? '');
    $status = $_GET['status'] ?? '';
    $tier = $_GET['tier'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['perPage'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    // 🔧 Build dynamic WHERE clause using prepared statement parameters
    $conditions = [];
    $params = [];
    $types = '';

    // 🔍 Search filter — matches username, email, or displayName
    if ($search !== '') {
        $searchLike = '%' . $search . '%';
        $conditions[] = '(u.username LIKE ? OR u.email LIKE ? OR u.displayName LIKE ?)';
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $types .= 'sss';
    }

    // 📊 Status filter — validate against allowed ENUM values
    $allowedStatuses = ['active', 'suspended', 'locked', 'pending', 'deleted'];
    if ($status !== '' && in_array($status, $allowedStatuses, true)) {
        $conditions[] = 'u.accountStatus = ?';
        $params[] = $status;
        $types .= 's';
    }

    // 🏷️ Tier filter — validate against allowed ENUM values
    $allowedTiers = ['free', 'basic', 'premium', 'enterprise', 'admin'];
    if ($tier !== '' && in_array($tier, $allowedTiers, true)) {
        $conditions[] = 'u.accountTier = ?';
        $params[] = $tier;
        $types .= 's';
    }

    // 🏗️ Assemble WHERE clause
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // 📊 Get total count for pagination
    $countSQL = "SELECT COUNT(*) as total FROM tblUsers u {$whereClause}";
    $countStmt = $db->prepare($countSQL);
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $totalUsers = $countStmt->get_result()->fetch_assoc()['total'];

    // 📋 Get paginated user list
    $listSQL = "
        SELECT
            u.userID,
            u.userUUID,
            u.username,
            u.email,
            u.emailVerified,
            u.firstName,
            u.lastName,
            u.displayName,
            u.profilePicture,
            u.accountStatus,
            u.accountTier,
            u.isSuperAdmin,
            u.failedLoginAttempts,
            u.lastLoginAt,
            u.lastLoginIP,
            u.createdAt
        FROM tblUsers u
        {$whereClause}
        ORDER BY u.createdAt DESC
        LIMIT ? OFFSET ?
    ";

    $listStmt = $db->prepare($listSQL);

    // 📎 Bind parameters — add pagination params to the end
    $listParams = $params;
    $listParams[] = $perPage;
    $listParams[] = $offset;
    $listTypes = $types . 'ii';

    $listStmt->bind_param($listTypes, ...$listParams);
    $listStmt->execute();
    $users = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // 🔒 Sanitise output — never expose password hashes
    $users = array_map(function($user) {
        return [
            'userID' => (int)$user['userID'],
            'userUUID' => $user['userUUID'],
            'username' => $user['username'],
            'email' => $user['email'],
            'emailVerified' => (bool)$user['emailVerified'],
            'firstName' => $user['firstName'],
            'lastName' => $user['lastName'],
            'displayName' => $user['displayName'],
            'profilePicture' => $user['profilePicture'],
            'accountStatus' => $user['accountStatus'],
            'accountTier' => $user['accountTier'],
            'isSuperAdmin' => (bool)$user['isSuperAdmin'],
            'failedLoginAttempts' => (int)$user['failedLoginAttempts'],
            'lastLoginAt' => $user['lastLoginAt'],
            'lastLoginIP' => $user['lastLoginIP'],
            'createdAt' => $user['createdAt']
        ];
    }, $users);

    // 📤 Return paginated response
    echo json_encode([
        'success' => true,
        'users' => $users,
        'pagination' => [
            'total' => (int)$totalUsers,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int)ceil($totalUsers / $perPage)
        ]
    ]);
}

/**
 * 👁️ Get single user details
 *
 * Returns comprehensive user data including linked OAuth accounts
 * and recent activity for the user detail modal.
 */
function handleGetUser($userID, $db) {
    $userID = (int)$userID;

    if ($userID <= 0) {
        throw new Exception('Invalid user ID');
    }

    // 👤 Get user details (excluding password hash for security)
    $stmt = $db->prepare("
        SELECT
            userID, userUUID, username, email, emailVerified, emailVerifiedAt,
            firstName, lastName, displayName, profilePicture,
            phoneNumber, phoneVerified, dateOfBirth, locale, timezone,
            accountStatus, accountTier, isSuperAdmin,
            requiresPasswordChange, failedLoginAttempts, lastFailedLogin,
            lastLoginAt, lastLoginIP, lastLoginUserAgent,
            createdAt, updatedAt
        FROM tblUsers
        WHERE userID = ?
    ");
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        throw new Exception('User not found');
    }

    // 🔗 Get linked OAuth accounts
    $stmt = $db->prepare("
        SELECT provider, email, displayName, accountType, isPrimary, linkedAt, lastUsedAt
        FROM tblOAuthAccounts
        WHERE userID = ?
        ORDER BY linkedAt DESC
    ");
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $oauthAccounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // 🏢 Get partner memberships
    $stmt = $db->prepare("
        SELECT tm.role, tm.isRootAdmin, tm.status, tm.joinedAt,
               p.partnerID, p.companyName
        FROM tblPartnerTeamMembers tm
        JOIN tblPartners p ON tm.partnerID = p.partnerID
        WHERE tm.userID = ?
        ORDER BY tm.joinedAt DESC
    ");
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $memberships = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // 📜 Get recent activity (last 10 entries)
    $stmt = $db->prepare("
        SELECT activityType, activityResult, activityDetails, ipAddress, createdAt
        FROM tblActivityLog
        WHERE userID = ?
        ORDER BY createdAt DESC
        LIMIT 10
    ");
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $recentActivity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // 📤 Return comprehensive user data
    echo json_encode([
        'success' => true,
        'user' => $user,
        'oauthAccounts' => $oauthAccounts,
        'memberships' => $memberships,
        'recentActivity' => $recentActivity
    ]);
}

/**
 * 🔄 Update user account status
 *
 * Changes accountStatus to one of: active, suspended, locked, pending, deleted
 * All status changes are logged in the admin audit trail.
 */
function handleUpdateStatus($input, $db, $accessControl, $sessionManager) {
    $userID = (int)($input['userID'] ?? 0);
    $newStatus = $input['status'] ?? '';

    // ✅ Validate inputs
    if ($userID <= 0) {
        throw new Exception('Invalid user ID');
    }

    $allowedStatuses = ['active', 'suspended', 'locked', 'pending', 'deleted'];
    if (!in_array($newStatus, $allowedStatuses, true)) {
        throw new Exception('Invalid status value');
    }

    // 🚫 Prevent modifying own account status
    if ($userID === (int)$sessionManager->getUserID()) {
        throw new Exception('Cannot change your own account status');
    }

    // 📋 Get current status for audit log
    $stmt = $db->prepare("SELECT username, accountStatus FROM tblUsers WHERE userID = ?");
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        throw new Exception('User not found');
    }

    // 🔄 Update status
    $stmt = $db->prepare("UPDATE tblUsers SET accountStatus = ? WHERE userID = ?");
    $stmt->bind_param('si', $newStatus, $userID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to update user status');
    }

    // 📝 Log the action with before/after state
    $accessControl->logAdminAction('update_user_status', 'user', $userID, [
        'username' => $user['username'],
        'previousStatus' => $user['accountStatus'],
        'newStatus' => $newStatus
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'User status updated to ' . $newStatus
    ]);
}

/**
 * 🏷️ Update user account tier
 *
 * Changes accountTier to one of: free, basic, premium, enterprise, admin
 */
function handleUpdateTier($input, $db, $accessControl, $sessionManager) {
    $userID = (int)($input['userID'] ?? 0);
    $newTier = $input['tier'] ?? '';

    // ✅ Validate inputs
    if ($userID <= 0) {
        throw new Exception('Invalid user ID');
    }

    $allowedTiers = ['free', 'basic', 'premium', 'enterprise', 'admin'];
    if (!in_array($newTier, $allowedTiers, true)) {
        throw new Exception('Invalid tier value');
    }

    // 📋 Get current tier for audit log
    $stmt = $db->prepare("SELECT username, accountTier FROM tblUsers WHERE userID = ?");
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        throw new Exception('User not found');
    }

    // 🔄 Update tier
    $stmt = $db->prepare("UPDATE tblUsers SET accountTier = ? WHERE userID = ?");
    $stmt->bind_param('si', $newTier, $userID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to update user tier');
    }

    // 📝 Log the action
    $accessControl->logAdminAction('update_user_tier', 'user', $userID, [
        'username' => $user['username'],
        'previousTier' => $user['accountTier'],
        'newTier' => $newTier
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'User tier updated to ' . $newTier
    ]);
}

/**
 * 🔑 Force password reset for a user
 *
 * Sets requiresPasswordChange flag to TRUE so the user must
 * change their password on next login.
 */
function handleResetPassword($input, $db, $accessControl, $sessionManager) {
    $userID = (int)($input['userID'] ?? 0);

    if ($userID <= 0) {
        throw new Exception('Invalid user ID');
    }

    // 📋 Get username for audit log
    $stmt = $db->prepare("SELECT username FROM tblUsers WHERE userID = ?");
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        throw new Exception('User not found');
    }

    // 🔄 Set password reset flag
    $stmt = $db->prepare("UPDATE tblUsers SET requiresPasswordChange = TRUE WHERE userID = ?");
    $stmt->bind_param('i', $userID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to trigger password reset');
    }

    // 📝 Log the action
    $accessControl->logAdminAction('force_password_reset', 'user', $userID, [
        'username' => $user['username']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Password reset required for ' . $user['username'] . ' on next login'
    ]);
}

/**
 * 🛡️ Toggle super admin status for a user
 *
 * This is a critical action — grants or revokes platform-wide administrative access.
 * Extra validation is performed to prevent accidentally removing the last super admin.
 */
function handleToggleSuperAdmin($input, $db, $accessControl, $sessionManager) {
    $userID = (int)($input['userID'] ?? 0);
    $enabled = (bool)($input['enabled'] ?? false);

    if ($userID <= 0) {
        throw new Exception('Invalid user ID');
    }

    // 🚫 Prevent modifying own super admin status
    if ($userID === (int)$sessionManager->getUserID()) {
        throw new Exception('Cannot modify your own super admin status');
    }

    // 📋 Get user details
    $stmt = $db->prepare("SELECT username, isSuperAdmin FROM tblUsers WHERE userID = ?");
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        throw new Exception('User not found');
    }

    // ⚠️ If revoking, ensure at least one super admin remains
    if (!$enabled) {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM tblUsers WHERE isSuperAdmin = TRUE AND accountStatus = 'active'");
        $stmt->execute();
        $superAdminCount = $stmt->get_result()->fetch_assoc()['count'];

        if ($superAdminCount <= 1 && $user['isSuperAdmin']) {
            throw new Exception('Cannot remove the last super admin. Promote another user first.');
        }
    }

    // 🔄 Update super admin flag
    $stmt = $db->prepare("UPDATE tblUsers SET isSuperAdmin = ? WHERE userID = ?");
    $isSuperAdmin = $enabled ? 1 : 0;
    $stmt->bind_param('ii', $isSuperAdmin, $userID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to update super admin status');
    }

    // 📝 Log this critical action
    $accessControl->logAdminAction('toggle_super_admin', 'user', $userID, [
        'username' => $user['username'],
        'previousState' => (bool)$user['isSuperAdmin'],
        'newState' => $enabled
    ]);

    echo json_encode([
        'success' => true,
        'message' => $enabled
            ? $user['username'] . ' is now a Super Admin'
            : 'Super Admin access revoked for ' . $user['username']
    ]);
}

/**
 * 🔓 Unlock a user account
 *
 * Resets failed login attempts to 0 and sets account status to active.
 * Used when a user has been locked out due to too many failed login attempts.
 */
function handleUnlockUser($input, $db, $accessControl, $sessionManager) {
    $userID = (int)($input['userID'] ?? 0);

    if ($userID <= 0) {
        throw new Exception('Invalid user ID');
    }

    // 📋 Get user details
    $stmt = $db->prepare("SELECT username, accountStatus, failedLoginAttempts FROM tblUsers WHERE userID = ?");
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        throw new Exception('User not found');
    }

    // 🔓 Reset failed attempts and activate account
    $stmt = $db->prepare("
        UPDATE tblUsers
        SET failedLoginAttempts = 0,
            accountStatus = 'active',
            lastFailedLogin = NULL
        WHERE userID = ?
    ");
    $stmt->bind_param('i', $userID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to unlock user');
    }

    // 📝 Log the action
    $accessControl->logAdminAction('unlock_user', 'user', $userID, [
        'username' => $user['username'],
        'previousStatus' => $user['accountStatus'],
        'previousFailedAttempts' => $user['failedLoginAttempts']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'User ' . $user['username'] . ' has been unlocked'
    ]);
}

/**
 * 📊 Get user statistics for dashboard cards
 *
 * Returns aggregate counts for total users, status breakdown, and new users this month.
 */
function handleGetStats($db) {
    // 📊 Status breakdown
    $stmt = $db->prepare("
        SELECT accountStatus, COUNT(*) as count
        FROM tblUsers
        GROUP BY accountStatus
    ");
    $stmt->execute();
    $statusCounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // 🆕 New users this month
    $stmt = $db->prepare("
        SELECT COUNT(*) as count
        FROM tblUsers
        WHERE createdAt >= DATE_FORMAT(NOW(), '%Y-%m-01')
    ");
    $stmt->execute();
    $newThisMonth = $stmt->get_result()->fetch_assoc()['count'];

    // 🏷️ Tier breakdown
    $stmt = $db->prepare("
        SELECT accountTier, COUNT(*) as count
        FROM tblUsers
        GROUP BY accountTier
    ");
    $stmt->execute();
    $tierCounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // 🛡️ Super admin count
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM tblUsers WHERE isSuperAdmin = TRUE");
    $stmt->execute();
    $superAdmins = $stmt->get_result()->fetch_assoc()['count'];

    // 🔒 Format status counts as key-value
    $statusMap = [];
    $total = 0;
    foreach ($statusCounts as $row) {
        $statusMap[$row['accountStatus']] = (int)$row['count'];
        $total += (int)$row['count'];
    }

    $tierMap = [];
    foreach ($tierCounts as $row) {
        $tierMap[$row['accountTier']] = (int)$row['count'];
    }

    echo json_encode([
        'success' => true,
        'stats' => [
            'total' => $total,
            'byStatus' => $statusMap,
            'byTier' => $tierMap,
            'newThisMonth' => (int)$newThisMonth,
            'superAdmins' => (int)$superAdmins
        ]
    ]);
}

// ============================================================================
// 🔐 MASS CREDENTIAL RESET HANDLERS
// ============================================================================

/**
 * 🚨 Initiate Mass Credential Reset
 *
 * Triggers a mass password invalidation and notification operation.
 * This is a critical security action used in breach response scenarios.
 *
 * Required input fields:
 * - resetType: mass_password_reset|salt_rotation|full_credential_reset
 * - reason: Admin-provided reason for the reset
 * - scope: all_users|filtered|specific_users
 * - scopeFilter: (optional) Filter criteria for 'filtered' scope
 *
 * @see CredentialResetService::initiateMassReset()
 */
function handleInitiateMassReset($input, $db, $accessControl, $sessionManager) {
    // 📋 Load CredentialResetService
    require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'CredentialResetService.php';

    // ✅ Validate required fields
    $resetType = $input['resetType'] ?? '';
    $reason = trim($input['reason'] ?? '');
    $scope = $input['scope'] ?? 'all_users';
    $scopeFilter = $input['scopeFilter'] ?? [];

    if (empty($resetType)) {
        throw new Exception('Reset type is required');
    }

    if (empty($reason)) {
        throw new Exception('A reason is required for credential reset operations');
    }

    // 🔒 Require confirmation if configured
    $requireConfirmation = getSetting('security.credential_reset.require_confirmation', true);
    if ($requireConfirmation && empty($input['confirmed'])) {
        // 📊 Return preview of affected users without executing
        echo json_encode([
            'success' => true,
            'requiresConfirmation' => true,
            'message' => 'Please confirm this operation',
            'preview' => [
                'resetType' => $resetType,
                'scope' => $scope,
                'reason' => $reason,
            ],
        ]);
        return;
    }

    // 🚨 Execute mass credential reset
    $adminUserID = (int)$sessionManager->getUserID();
    $result = CredentialResetService::initiateMassReset(
        $adminUserID,
        $resetType,
        $reason,
        $scope,
        $scopeFilter
    );

    // 📝 Log to admin audit trail
    $accessControl->logAdminAction('initiate_mass_credential_reset', 'system', null, [
        'resetType' => $resetType,
        'scope' => $scope,
        'reason' => $reason,
        'totalUsers' => $result['totalUsers'] ?? 0,
        'resetID' => $result['resetID'] ?? null,
    ]);

    echo json_encode($result);
}

/**
 * 🔄 Process Next Batch of Credential Reset
 *
 * Processes the next batch of users for an active reset operation.
 * Called repeatedly by the admin UI via polling/AJAX.
 *
 * Required input: resetID (int)
 */
function handleProcessResetBatch($input, $db) {
    // 📋 Load CredentialResetService
    require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'CredentialResetService.php';

    $resetID = (int)($input['resetID'] ?? 0);

    if ($resetID <= 0) {
        throw new Exception('Invalid reset ID');
    }

    $result = CredentialResetService::processNextBatch($resetID);
    echo json_encode($result);
}

/**
 * 📊 Get Reset Operation Status
 *
 * Returns current status and progress of a credential reset operation.
 *
 * GET parameter: resetID (int)
 */
function handleGetResetStatus($db) {
    // 📋 Load CredentialResetService
    require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'CredentialResetService.php';

    $resetID = (int)($_GET['resetID'] ?? 0);

    if ($resetID <= 0) {
        throw new Exception('Invalid reset ID');
    }

    $status = CredentialResetService::getResetStatus($resetID);

    if (!$status) {
        throw new Exception('Reset operation not found');
    }

    echo json_encode(['success' => true, 'reset' => $status]);
}

/**
 * 📋 List All Reset Operations
 *
 * Returns paginated list of all credential reset operations.
 *
 * GET parameters: page (int), perPage (int)
 */
function handleListResetOperations($db) {
    // 📋 Load CredentialResetService
    require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'CredentialResetService.php';

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(50, max(1, (int)($_GET['perPage'] ?? 20)));

    $result = CredentialResetService::listResetOperations($page, $perPage);
    echo json_encode(['success' => true, ...$result]);
}

/**
 * 🚫 Cancel Reset Operation
 *
 * Cancels a pending or in-progress credential reset operation.
 * Users already processed will not be reverted.
 *
 * Required input: resetID (int)
 */
function handleCancelReset($input, $db, $accessControl, $sessionManager) {
    // 📋 Load CredentialResetService
    require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'CredentialResetService.php';

    $resetID = (int)($input['resetID'] ?? 0);

    if ($resetID <= 0) {
        throw new Exception('Invalid reset ID');
    }

    $adminUserID = (int)$sessionManager->getUserID();
    $result = CredentialResetService::cancelReset($resetID, $adminUserID);

    // 📝 Log to admin audit trail
    $accessControl->logAdminAction('cancel_mass_credential_reset', 'system', null, [
        'resetID' => $resetID,
    ]);

    echo json_encode($result);
}

/**
 * 📊 Get Compliance Report
 *
 * Returns how many users have completed their password change after a mass reset.
 *
 * GET parameter: resetID (int)
 */
function handleGetComplianceReport($db) {
    // 📋 Load CredentialResetService
    require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'CredentialResetService.php';

    $resetID = (int)($_GET['resetID'] ?? 0);

    if ($resetID <= 0) {
        throw new Exception('Invalid reset ID');
    }

    $report = CredentialResetService::getComplianceReport($resetID);
    echo json_encode(['success' => true, 'report' => $report]);
}

/**
 * 📜 Get Salt Rotation History
 *
 * Returns the history of all global salt rotations.
 */
function handleGetSaltHistory($db) {
    // 📋 Load CredentialResetService
    require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'CredentialResetService.php';

    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $history = CredentialResetService::getSaltRotationHistory($limit);

    echo json_encode(['success' => true, 'history' => $history]);
}
