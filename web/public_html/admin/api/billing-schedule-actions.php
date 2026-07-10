<?php
/**
 * ============================================================================
 * ⏰ SIGNula - Billing Scheduler Management API (Admin)
 * ============================================================================
 *
 * Purpose: Admin API endpoint for managing the automated billing scheduler.
 *          Provides read access to task queues and health status, and write
 *          access for manual task management (retry, cancel, create, batch).
 *
 * Actions (GET):
 *   - get_pending_tasks     — List pending tasks from tblBillingSchedule
 *   - get_failed_tasks      — List failed tasks with error details
 *   - get_task_history      — Paginated completed/cancelled task history
 *   - get_scheduler_health  — Scheduler health check (last run, overdue count)
 *   - get_billing_stats     — Billing statistics (counts by status, renewals)
 *
 * Actions (POST):
 *   - process_batch         — Manually trigger batch processing of due tasks
 *   - retry_task            — Retry a failed task (reset to pending)
 *   - cancel_task           — Cancel a pending or failed task
 *   - create_task           — Create a new manual billing task
 *
 * Authentication: Super Admin only (isSuperAdmin = 1)
 * CSRF Protection: Required for all POST requests
 *
 * PHP Version: 8.3+
 *
 * @see https://www.php.net/manual/en/book.mysqli.php — MySQLi Extension
 * @see https://www.php.net/manual/en/function.json-encode.php — JSON Encoding
 * @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Prevention
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\API\Payments
 * @version    1.0.0
 * @since      2.4.0-beta
 * @link       https://SIGNula.id
 * ============================================================================
 */

// ============================================================================
// 🚀 BOOTSTRAP — Load core system files
// ============================================================================

// ⚙️ Main configuration (defines constants, loads settings, initializes session)
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

// ⏰ Billing Scheduler class (task management, health checks)
// @see web/private_html/payments/BillingScheduler.php
$billingSchedulerPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'BillingScheduler.php';

// 📂 Verify the BillingScheduler class file exists before loading
// @see https://www.php.net/manual/en/function.file-exists.php
if (file_exists($billingSchedulerPath)) {
    require_once $billingSchedulerPath;
} else {
    // 🚨 BillingScheduler class not found — cannot proceed
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'BillingScheduler class not found. Ensure BillingScheduler.php exists in the payments directory.'
    ]);
    exit;
}

// ============================================================================
// 📤 Response Headers
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
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 🔐 Require super admin — only super admins can manage the billing scheduler
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
// @see https://www.php.net/manual/en/wrappers.php.php (php://input)
$input = json_decode(file_get_contents('php://input'), true);

// 🔀 Determine action based on request method
// GET requests use query parameters, POST requests use JSON body
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// 🛡️ CSRF validation for POST requests — prevents cross-site request forgery
// @see https://owasp.org/www-community/attacks/csrf
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
// 🗺️ ACTION ROUTER — Dispatch to appropriate handler
// ============================================================================

try {
    switch ($action) {

        // ═══════════════════════════════════════════════════════
        // 📋 GET ACTIONS — Read-only data retrieval
        // ═══════════════════════════════════════════════════════

        // 🕐 List pending tasks
        case 'get_pending_tasks':
            handleGetPendingTasks();
            break;

        // ❌ List failed tasks
        case 'get_failed_tasks':
            handleGetFailedTasks();
            break;

        // 📜 List task history (paginated)
        case 'get_task_history':
            handleGetTaskHistory();
            break;

        // 🩺 Scheduler health check
        case 'get_scheduler_health':
            handleGetSchedulerHealth();
            break;

        // 📊 Billing statistics
        case 'get_billing_stats':
            handleGetBillingStats();
            break;

        // ═══════════════════════════════════════════════════════
        // ⚡ POST ACTIONS — Write operations (CSRF protected)
        // ═══════════════════════════════════════════════════════

        // ⚡ Manually trigger batch processing
        case 'process_batch':
            handleProcessBatch($input);
            break;

        // 🔄 Retry a failed task
        case 'retry_task':
            handleRetryTask($input);
            break;

        // ❌ Cancel a pending/failed task
        case 'cancel_task':
            handleCancelTask($input);
            break;

        // ➕ Create a new manual task
        case 'create_task':
            handleCreateTask($input);
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
// 🕐 HANDLER: get_pending_tasks
// ============================================================================

/**
 * 🕐 Retrieve all pending tasks from tblBillingSchedule.
 *
 * Returns tasks where status='pending', ordered by scheduledFor ASC
 * (earliest due date first) so the most urgent tasks appear at the top.
 *
 * @return void Outputs JSON response with pending tasks array
 *
 * @see tblBillingSchedule — Created by billing migrations
 * @see https://dev.mysql.com/doc/refman/8.0/en/select.html
 */
function handleGetPendingTasks(): void
{
    // 📋 Fetch all pending tasks ordered by scheduled date ascending
    // Earlier dates appear first so overdue tasks are most visible
    // 🩹 B-025: the table's PK column is `scheduleID` (migration 012), not
    // `taskID` — alias it here so the JSON response shape (and every other
    // handler in this file) keeps using the established `taskID` field name.
    $tasks = Database::fetchAll(
        "SELECT
            scheduleID AS taskID,
            taskType,
            targetType,
            targetID,
            scheduledFor,
            status,
            attempts,
            maxAttempts,
            result,
            metadata,
            createdAt,
            updatedAt
         FROM tblBillingSchedule
         WHERE status = 'pending'
         ORDER BY scheduledFor ASC",
        [],
        ''
    );

    // ✅ Return structured response
    echo json_encode([
        'success' => true,
        'data'    => $tasks,
    ]);
}


// ============================================================================
// ❌ HANDLER: get_failed_tasks
// ============================================================================

/**
 * ❌ Retrieve all failed tasks from tblBillingSchedule.
 *
 * Returns tasks where status='failed', ordered by updatedAt DESC
 * (most recently failed first) to help admins prioritise troubleshooting.
 *
 * @return void Outputs JSON response with failed tasks array
 *
 * @see tblBillingSchedule — Created by billing migrations
 */
function handleGetFailedTasks(): void
{
    // 📋 Fetch all failed tasks ordered by most recently updated
    // 🩹 B-025: alias scheduleID -> taskID (see handleGetPendingTasks() above).
    $tasks = Database::fetchAll(
        "SELECT
            scheduleID AS taskID,
            taskType,
            targetType,
            targetID,
            scheduledFor,
            status,
            attempts,
            maxAttempts,
            result,
            metadata,
            createdAt,
            updatedAt
         FROM tblBillingSchedule
         WHERE status = 'failed'
         ORDER BY updatedAt DESC",
        [],
        ''
    );

    // ✅ Return structured response
    echo json_encode([
        'success' => true,
        'data'    => $tasks,
    ]);
}


// ============================================================================
// 📜 HANDLER: get_task_history
// ============================================================================

/**
 * 📜 Retrieve paginated task history (completed/cancelled tasks).
 *
 * Returns recently processed tasks with pagination support. Only shows
 * tasks with status 'completed' or 'cancelled' — these are the "done" tasks.
 *
 * GET Parameters:
 *   - limit (int, optional): Number of records per page (default: 25, max: 100)
 *   - offset (int, optional): Record offset for pagination (default: 0)
 *
 * @return void Outputs JSON response with task history, total count, and page info
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/select.html
 */
function handleGetTaskHistory(): void
{
    // 📥 Parse pagination parameters from GET query string
    $limit  = (int) ($_GET['limit'] ?? 25);
    $offset = (int) ($_GET['offset'] ?? 0);

    // 🔒 Clamp limit to safe range (1–100) to prevent excessive queries
    $limit  = max(1, min(100, $limit));
    $offset = max(0, $offset);

    // 📊 Get total count of completed/cancelled tasks for pagination
    $countResult = Database::fetchOne(
        "SELECT COUNT(*) AS total
         FROM tblBillingSchedule
         WHERE status IN ('completed', 'cancelled')",
        [],
        ''
    );
    $total = $countResult ? (int) $countResult['total'] : 0;

    // 📋 Fetch paginated task history ordered by most recently updated
    // 🩹 B-025: alias scheduleID -> taskID (see handleGetPendingTasks() above).
    $tasks = Database::fetchAll(
        "SELECT
            scheduleID AS taskID,
            taskType,
            targetType,
            targetID,
            scheduledFor,
            status,
            attempts,
            maxAttempts,
            result,
            metadata,
            createdAt,
            updatedAt
         FROM tblBillingSchedule
         WHERE status IN ('completed', 'cancelled')
         ORDER BY updatedAt DESC
         LIMIT ?, ?",
        [$offset, $limit],
        'ii'
    );

    // 📊 Calculate total pages for pagination
    $totalPages = ($limit > 0) ? (int) ceil($total / $limit) : 1;

    // ✅ Return structured response with pagination metadata
    echo json_encode([
        'success' => true,
        'data'    => $tasks,
        'total'   => $total,
        'pages'   => $totalPages,
        'limit'   => $limit,
        'offset'  => $offset,
    ]);
}


// ============================================================================
// 🩺 HANDLER: get_scheduler_health
// ============================================================================

/**
 * 🩺 Retrieve scheduler health status.
 *
 * Delegates to BillingScheduler::getSchedulerHealth() which checks the
 * last cron run timestamp, counts pending/failed/overdue tasks, and
 * determines if the scheduler is operating normally.
 *
 * Health indicator thresholds:
 *   - Green (healthy): External cron ran within the last hour (3600s)
 *   - Yellow (warning): Cron ran 1-2 hours ago
 *   - Red (critical): Cron has not run in 2+ hours or has never run
 *
 * @return void Outputs JSON response with health data
 *
 * @see BillingScheduler::getSchedulerHealth() — Backend health check method
 * @see tblSettings key 'billing.last_cron_run' — Last successful cron timestamp
 */
function handleGetSchedulerHealth(): void
{
    // 🩺 Delegate to BillingScheduler's health check method
    $healthData = BillingScheduler::getSchedulerHealth();

    // ✅ Return structured response
    echo json_encode([
        'success' => true,
        'data'    => $healthData,
    ]);
}


// ============================================================================
// 📊 HANDLER: get_billing_stats
// ============================================================================

/**
 * 📊 Retrieve billing statistics for the dashboard stat cards.
 *
 * Gathers aggregate counts from tblBillingSchedule grouped by status,
 * plus counts upcoming subscription renewals from tblSubscriptions within
 * the next 7 days.
 *
 * @return void Outputs JSON response with billing statistics
 *
 * @see tblBillingSchedule — Task queue table
 * @see tblSubscriptions — Subscription table for upcoming renewals
 */
function handleGetBillingStats(): void
{
    // 📊 Aggregate task counts from the billing schedule table
    // Groups by status to get pending, failed, completed today, etc.
    $taskStats = Database::fetchOne(
        "SELECT
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pendingCount,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failedCount,
            SUM(CASE WHEN status = 'pending' AND scheduledFor <= NOW() THEN 1 ELSE 0 END) AS overdueCount,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processingCount,
            SUM(CASE WHEN status = 'completed' AND updatedAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS completedToday
         FROM tblBillingSchedule",
        [],
        ''
    );

    // 🔄 Count upcoming subscription renewals in the next 7 days
    // Only count active subscriptions with a valid next billing date
    $renewalResult = Database::fetchOne(
        "SELECT COUNT(*) AS upcomingRenewals
         FROM tblSubscriptions
         WHERE subscriptionStatus = 'active'
           AND nextBillingDate IS NOT NULL
           AND nextBillingDate BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)",
        [],
        ''
    );

    // 📦 Assemble the stats response object
    $stats = [
        'pendingCount'     => (int) ($taskStats['pendingCount'] ?? 0),
        'failedCount'      => (int) ($taskStats['failedCount'] ?? 0),
        'overdueCount'     => (int) ($taskStats['overdueCount'] ?? 0),
        'processingCount'  => (int) ($taskStats['processingCount'] ?? 0),
        'completedToday'   => (int) ($taskStats['completedToday'] ?? 0),
        'upcomingRenewals' => (int) ($renewalResult['upcomingRenewals'] ?? 0),
    ];

    // ✅ Return structured response
    echo json_encode([
        'success' => true,
        'data'    => $stats,
    ]);
}


// ============================================================================
// ⚡ HANDLER: process_batch
// ============================================================================

/**
 * ⚡ Manually trigger batch processing of due billing tasks.
 *
 * Delegates to BillingScheduler::processDueTasks() which fetches pending
 * tasks whose scheduledFor datetime is at or before the current time and
 * processes them in sequence. This is the same method called by the
 * external cron service, but triggered manually by a super admin.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - batchSize (int, optional): Maximum tasks to process (default: 50, max: 100)
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response with processing results
 *
 * @see BillingScheduler::processDueTasks() — Main processing entry point
 * @see https://www.php.net/manual/en/function.min.php — Clamping batch size
 */
function handleProcessBatch(?array $input): void
{
    // 📥 Parse and validate batch size parameter
    $batchSize = (int) ($input['batchSize'] ?? 50);

    // 🔒 Clamp batch size to safe range (1–100)
    // Prevents excessive processing that could timeout on shared hosting
    $batchSize = max(1, min(100, $batchSize));

    // ⚡ Delegate to BillingScheduler for actual task processing
    $result = BillingScheduler::processDueTasks($batchSize);

    // 📝 Log the manual batch processing in the activity log
    // @see ActivityLogger::log() — 6 parameters required
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],           // 👤 User who triggered the batch
        'billing_batch_manual',               // 📌 Activity type
        'payment',                            // 📂 Category
        'info',                               // ℹ️ Severity level
        'Manual billing batch processing triggered (batch size: ' . $batchSize . ', processed: ' . ($result['processed'] ?? 0) . ', failed: ' . ($result['failed'] ?? 0) . ')',
        [
            'batchSize' => $batchSize,
            'processed' => $result['processed'] ?? 0,
            'failed'    => $result['failed'] ?? 0,
        ]
    );

    // ✅ Return structured response with processing results
    echo json_encode([
        'success' => $result['success'] ?? false,
        'data'    => [
            'processed' => $result['processed'] ?? 0,
            'failed'    => $result['failed'] ?? 0,
            'results'   => $result['results'] ?? [],
        ],
        'message' => 'Batch processing complete: ' . ($result['processed'] ?? 0) . ' processed, ' . ($result['failed'] ?? 0) . ' failed.',
    ]);
}


// ============================================================================
// 🔄 HANDLER: retry_task
// ============================================================================

/**
 * 🔄 Retry a failed or pending task by resetting it to pending status.
 *
 * Delegates to BillingScheduler::retryTask() which resets the task status
 * to 'pending' and schedules it for a future time based on retryMinutes.
 * If retryMinutes is 0, the task is scheduled for immediate execution.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - taskID (int): The task ID to retry
 *   - retryMinutes (int, optional): Minutes until retry (default: 1, 0 = immediate)
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If taskID is missing or invalid
 *
 * @see BillingScheduler::retryTask() — Backend retry method
 * @see https://www.php.net/manual/en/function.date.php — Date formatting for retry scheduling
 */
function handleRetryTask(?array $input): void
{
    // ✅ Validate taskID parameter
    $taskID = (int) ($input['taskID'] ?? 0);
    if ($taskID <= 0) {
        throw new Exception('Valid task ID is required');
    }

    // 📥 Parse retry delay (default: 1 minute, 0 = immediate)
    $retryMinutes = (int) ($input['retryMinutes'] ?? 1);

    // 🔒 Clamp to safe range (0–1440 minutes = max 24 hours)
    $retryMinutes = max(0, min(1440, $retryMinutes));

    // 🔍 Verify the task exists and is in a retryable state
    // 🩹 B-025: `scheduleID` is the real PK column; WHERE must target it,
    // aliased back to `taskID` for the SELECT list (see handleGetPendingTasks()).
    $task = Database::fetchOne(
        "SELECT scheduleID AS taskID, taskType, status, attempts, maxAttempts
         FROM tblBillingSchedule
         WHERE scheduleID = ?",
        [$taskID],
        'i'
    );

    if (!$task) {
        throw new Exception('Task #' . $taskID . ' not found');
    }

    // ✅ Only allow retrying failed or pending tasks
    if (!in_array($task['status'], ['failed', 'pending'], true)) {
        throw new Exception('Task #' . $taskID . ' cannot be retried (current status: ' . $task['status'] . ')');
    }

    // 🔄 Delegate to BillingScheduler's retry method
    BillingScheduler::retryTask($taskID, $retryMinutes);

    // 📝 Log the retry action in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],           // 👤 User who triggered the retry
        'billing_task_retried',               // 📌 Activity type
        'payment',                            // 📂 Category
        'info',                               // ℹ️ Severity level
        'Billing task #' . $taskID . ' (' . $task['taskType'] . ') retried with ' . $retryMinutes . ' minute delay',
        [
            'taskID'       => $taskID,
            'taskType'     => $task['taskType'],
            'retryMinutes' => $retryMinutes,
            'previousStatus' => $task['status'],
        ]
    );

    // ✅ Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Task #' . $taskID . ' scheduled for retry' . ($retryMinutes > 0 ? ' in ' . $retryMinutes . ' minutes' : ' immediately'),
    ]);
}


// ============================================================================
// ❌ HANDLER: cancel_task
// ============================================================================

/**
 * ❌ Cancel a pending or failed billing task.
 *
 * Sets the task status to 'cancelled' in tblBillingSchedule. Only tasks
 * that are currently in 'pending' or 'failed' status can be cancelled.
 * Already completed or cancelled tasks cannot be re-cancelled.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - taskID (int): The task ID to cancel
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If taskID is missing, invalid, or task is not cancellable
 *
 * @see BillingScheduler::markTaskProcessed() — Used to update task status
 * @see https://dev.mysql.com/doc/refman/8.0/en/update.html
 */
function handleCancelTask(?array $input): void
{
    // ✅ Validate taskID parameter
    $taskID = (int) ($input['taskID'] ?? 0);
    if ($taskID <= 0) {
        throw new Exception('Valid task ID is required');
    }

    // 🔍 Verify the task exists and is in a cancellable state
    // 🩹 B-025: WHERE targets the real PK column (`scheduleID`) — see handleRetryTask() above.
    $task = Database::fetchOne(
        "SELECT scheduleID AS taskID, taskType, targetType, targetID, status
         FROM tblBillingSchedule
         WHERE scheduleID = ?",
        [$taskID],
        'i'
    );

    if (!$task) {
        throw new Exception('Task #' . $taskID . ' not found');
    }

    // ✅ Only allow cancelling pending or failed tasks
    if (!in_array($task['status'], ['pending', 'failed', 'processing'], true)) {
        throw new Exception('Task #' . $taskID . ' cannot be cancelled (current status: ' . $task['status'] . ')');
    }

    // ❌ Update the task status to 'cancelled'
    // Using BillingScheduler's markTaskProcessed to ensure consistent state transitions
    BillingScheduler::markTaskProcessed($taskID, 'cancelled', 'Manually cancelled by admin');

    // 📝 Log the cancellation action in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],           // 👤 User who cancelled the task
        'billing_task_cancelled',             // 📌 Activity type
        'payment',                            // 📂 Category
        'info',                               // ℹ️ Severity level
        'Billing task #' . $taskID . ' (' . $task['taskType'] . ' for ' . $task['targetType'] . ' #' . $task['targetID'] . ') cancelled by admin',
        [
            'taskID'     => $taskID,
            'taskType'   => $task['taskType'],
            'targetType' => $task['targetType'],
            'targetID'   => (int) $task['targetID'],
            'previousStatus' => $task['status'],
        ]
    );

    // ✅ Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Task #' . $taskID . ' has been cancelled',
    ]);
}


// ============================================================================
// ➕ HANDLER: create_task
// ============================================================================

/**
 * ➕ Create a new manual billing task.
 *
 * Allows super admins to manually schedule billing tasks (e.g., charge
 * subscription, send reminder, suspend account). The task is added to
 * tblBillingSchedule with status='pending' and will be picked up by the
 * next cron run or lazy batch processing.
 *
 * Validation:
 *   - taskType must be a valid BillingScheduler::TASK_TYPES value
 *   - targetType must be a valid BillingScheduler::TARGET_TYPES value
 *   - targetID must be a positive integer
 *   - scheduledDate must be a valid datetime string
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - taskType (string): Type of billing task (e.g., 'charge_subscription')
 *   - targetType (string): Target entity type (e.g., 'subscription')
 *   - targetID (int): Target entity ID
 *   - scheduledDate (string): Date/time when the task should be processed
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response with created task ID
 *
 * @throws Exception If required parameters are missing or invalid
 *
 * @see BillingScheduler::createTask() — Backend task creation method
 * @see BillingScheduler::TASK_TYPES — Valid task type values
 * @see BillingScheduler::TARGET_TYPES — Valid target type values
 */
function handleCreateTask(?array $input): void
{
    // 📥 Extract and validate required parameters
    $taskType      = trim($input['taskType'] ?? '');
    $targetType    = trim($input['targetType'] ?? '');
    $targetID      = (int) ($input['targetID'] ?? 0);
    $scheduledDate = trim($input['scheduledDate'] ?? '');

    // ✅ Validate task type — must not be empty
    if (empty($taskType)) {
        throw new Exception('Task type is required');
    }

    // ✅ Validate target type — must not be empty
    if (empty($targetType)) {
        throw new Exception('Target type is required');
    }

    // ✅ Validate target ID — must be a positive integer
    if ($targetID <= 0) {
        throw new Exception('Valid target ID is required (must be a positive integer)');
    }

    // ✅ Validate scheduled date — must be a valid datetime
    if (empty($scheduledDate)) {
        throw new Exception('Scheduled date is required');
    }

    // 📅 Parse and validate the scheduled date format
    // @see https://www.php.net/manual/en/function.strtotime.php
    $parsedDate = strtotime($scheduledDate);
    if ($parsedDate === false) {
        throw new Exception('Invalid scheduled date format. Please use a valid date/time.');
    }

    // 📅 Convert to MySQL DATETIME format (Y-m-d H:i:s)
    $scheduledFor = date('Y-m-d H:i:s', $parsedDate);

    // ➕ Delegate to BillingScheduler's createTask method
    // This validates task/target types against ENUM values and inserts the task
    $result = BillingScheduler::createTask(
        $taskType,      // 📌 Task type (e.g., 'charge_subscription')
        $targetType,    // 🎯 Target type (e.g., 'subscription')
        $targetID,      // 🔢 Target entity ID
        $scheduledFor   // 📅 MySQL-formatted scheduled datetime
    );

    // 📊 Check if task creation was successful
    if (!$result['success']) {
        throw new Exception($result['message'] ?? 'Failed to create task');
    }

    // 📝 Log the manual task creation in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],           // 👤 User who created the task
        'billing_task_created',               // 📌 Activity type
        'payment',                            // 📂 Category
        'info',                               // ℹ️ Severity level
        'Manual billing task created: ' . $taskType . ' for ' . $targetType . ' #' . $targetID . ' scheduled at ' . $scheduledFor,
        [
            'taskID'       => $result['taskID'] ?? 0,
            'taskType'     => $taskType,
            'targetType'   => $targetType,
            'targetID'     => $targetID,
            'scheduledFor' => $scheduledFor,
        ]
    );

    // ✅ Return success response with the new task ID
    echo json_encode([
        'success' => true,
        'taskID'  => $result['taskID'] ?? 0,
        'message' => 'Billing task created successfully (ID: #' . ($result['taskID'] ?? '?') . ')',
    ]);
}
