<?php
/**
 * ============================================================================
 * 🔗 Webhook Management API (Super Admin)
 * ============================================================================
 *
 * Purpose: AJAX endpoint for managing webhook subscriptions, deliveries,
 *          and system statistics from the admin webhook dashboard.
 * PHP Version: 8.3+
 *
 * GET Actions:
 *   - get_webhooks   : List all webhook subscriptions with delivery stats
 *   - get_webhook    : Single webhook detail with decrypted secret
 *   - get_deliveries : Paginated delivery log for a specific webhook
 *   - get_stats      : Webhook system statistics for dashboard cards
 *   - get_events     : List all available event types
 *
 * POST Actions (CSRF token required):
 *   - create_webhook  : Register a new webhook subscription
 *   - update_webhook  : Update webhook configuration
 *   - delete_webhook  : Delete a webhook and its delivery history
 *   - toggle_webhook  : Enable or disable a webhook
 *   - test_webhook    : Send a test payload to a webhook endpoint
 *   - retry_delivery  : Retry a failed delivery
 *
 * All actions require Super Admin authentication. State-changing POST
 * actions require a valid CSRF token.
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
 *
 * @package    SIGNula
 * @subpackage Admin\API
 * @version    1.0.0
 * @since      2.7.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🔌 Load core configuration and dependencies
// dirname(__DIR__, 3) navigates: api/ -> admin/ -> public_html/ -> web/
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 🔗 Load WebhookService
// dirname(__DIR__, 3) navigates to web/, then into private_html/webhooks/
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'webhooks' . DIRECTORY_SEPARATOR . 'WebhookService.php';

// 📋 Set JSON response header for all responses
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

// 📥 Get request data based on HTTP method
// GET for read operations, POST for write operations
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    // 🔐 Validate Content-Type for POST requests
    // Prevents CSRF via form submissions (forms can't send application/json)
    // @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        http_response_code(415);
        echo json_encode(['success' => false, 'error' => 'Content-Type must be application/json']);
        exit;
    }

    $input  = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

// 🛡️ Verify CSRF token for state-changing POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
        exit;
    }
}

// ========================================================================
// 🎯 ACTION ROUTER — Dispatch to appropriate handler
// ========================================================================

try {
    switch ($action) {

        // ====================================================================
        // 📖 GET Actions — Read-only operations
        // ====================================================================

        case 'get_webhooks':
            handleGetWebhooks();
            break;

        case 'get_webhook':
            handleGetWebhook((int)($_GET['webhookID'] ?? 0));
            break;

        case 'get_deliveries':
            handleGetDeliveries(
                (int)($_GET['webhookID'] ?? 0),
                (int)($_GET['limit'] ?? 50),
                (int)($_GET['offset'] ?? 0)
            );
            break;

        case 'get_stats':
            handleGetStats();
            break;

        case 'get_events':
            handleGetEvents();
            break;

        // ====================================================================
        // ✏️ POST Actions — State-changing operations
        // ====================================================================

        case 'create_webhook':
            handleCreateWebhook($input);
            break;

        case 'update_webhook':
            handleUpdateWebhook($input);
            break;

        case 'delete_webhook':
            handleDeleteWebhook($input);
            break;

        case 'toggle_webhook':
            handleToggleWebhook($input);
            break;

        case 'test_webhook':
            handleTestWebhook($input);
            break;

        case 'retry_delivery':
            handleRetryDelivery($input);
            break;

        default:
            // 🚫 Unknown action
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
            break;
    }
} catch (\Exception $e) {
    // 🚨 Catch-all error handler
    ErrorLogger::logError(
        'webhook-actions.php',
        $e->getMessage(),
        'error',
        __FILE__,
        __LINE__,
        $e->getTraceAsString()
    );

    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An internal error occurred']);
}


// ========================================================================
// 📖 GET HANDLERS — Read-only operations
// ========================================================================

/**
 * 📋 List all webhooks
 *
 * Returns all webhook subscriptions (active and inactive) with their
 * event counts and last triggered timestamp.
 *
 * @return void Outputs JSON response
 */
function handleGetWebhooks(): void
{
    // 🔍 Get all webhooks (including inactive for admin view)
    $webhooks = WebhookService::getWebhooks(null, false);

    echo json_encode([
        'success'  => true,
        'webhooks' => $webhooks,
        'count'    => count($webhooks),
    ]);
}

/**
 * 🔍 Get a single webhook with full details
 *
 * Returns webhook details including the decrypted HMAC signing secret
 * for display in the admin edit modal.
 *
 * @param int $webhookID Webhook ID to retrieve
 *
 * @return void Outputs JSON response
 */
function handleGetWebhook(int $webhookID): void
{
    if ($webhookID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid webhook ID']);
        return;
    }

    $webhook = WebhookService::getWebhook($webhookID);

    if (!$webhook) {
        echo json_encode(['success' => false, 'error' => 'Webhook not found']);
        return;
    }

    echo json_encode([
        'success' => true,
        'webhook' => $webhook,
    ]);
}

/**
 * 📋 Get paginated delivery log for a webhook
 *
 * Returns delivery records with full payload and response details
 * for debugging and audit purposes.
 *
 * @param int $webhookID Webhook ID
 * @param int $limit     Records per page (max 200)
 * @param int $offset    Pagination offset
 *
 * @return void Outputs JSON response
 */
function handleGetDeliveries(int $webhookID, int $limit, int $offset): void
{
    if ($webhookID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid webhook ID']);
        return;
    }

    $deliveries = WebhookService::getDeliveries($webhookID, $limit, $offset);

    echo json_encode([
        'success'    => true,
        'deliveries' => $deliveries,
        'count'      => count($deliveries),
    ]);
}

/**
 * 📊 Get webhook system statistics
 *
 * Returns aggregate counts for the admin dashboard summary cards.
 *
 * @return void Outputs JSON response
 */
function handleGetStats(): void
{
    $stats = WebhookService::getStats();

    echo json_encode([
        'success' => true,
        'stats'   => $stats,
    ]);
}

/**
 * 📋 Get list of available event types
 *
 * Returns all supported webhook event types grouped by category
 * for the event checkbox UI.
 *
 * @return void Outputs JSON response
 */
function handleGetEvents(): void
{
    // 📦 Group events by category prefix
    $grouped = [];
    foreach (WebhookService::EVENTS as $event) {
        // 🔍 Extract category from event name (e.g. "user" from "user.login")
        $parts    = explode('.', $event, 2);
        $category = $parts[0] ?? 'other';
        $grouped[$category][] = $event;
    }

    echo json_encode([
        'success' => true,
        'events'  => WebhookService::EVENTS,
        'grouped' => $grouped,
    ]);
}


// ========================================================================
// ✏️ POST HANDLERS — State-changing operations
// ========================================================================

/**
 * 📝 Create a new webhook subscription
 *
 * @param array $input Request body with webhook configuration
 *
 * @return void Outputs JSON response
 */
function handleCreateWebhook(array $input): void
{
    // ✅ Validate required fields
    $name   = SecurityUtils::sanitizeString($input['name'] ?? '');
    $url    = SecurityUtils::sanitizeString($input['url'] ?? '');
    $events = $input['events'] ?? [];

    if (empty($name) || empty($url) || empty($events)) {
        echo json_encode(['success' => false, 'error' => 'Name, URL, and at least one event are required']);
        return;
    }

    // 📦 Build registration data
    $data = [
        'name'           => $name,
        'url'            => $url,
        'events'         => $events,
        'partnerID'      => isset($input['partnerID']) ? (int)$input['partnerID'] : null,
        'headers'        => $input['headers'] ?? null,
        'retryCount'     => isset($input['retryCount']) ? (int)$input['retryCount'] : null,
        'timeoutSeconds' => isset($input['timeoutSeconds']) ? (int)$input['timeoutSeconds'] : null,
    ];

    // 🔗 Register webhook via service
    $result = WebhookService::register($data);

    echo json_encode($result);
}

/**
 * ✏️ Update an existing webhook
 *
 * @param array $input Request body with updated fields
 *
 * @return void Outputs JSON response
 */
function handleUpdateWebhook(array $input): void
{
    $webhookID = (int)($input['webhookID'] ?? 0);

    if ($webhookID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid webhook ID']);
        return;
    }

    // 📦 Build update data (only include provided fields)
    $data = [];

    if (isset($input['name'])) {
        $data['name'] = SecurityUtils::sanitizeString($input['name']);
    }

    if (isset($input['url'])) {
        $data['url'] = SecurityUtils::sanitizeString($input['url']);
    }

    if (isset($input['events'])) {
        $data['events'] = $input['events'];
    }

    if (array_key_exists('headers', $input)) {
        $data['headers'] = $input['headers'];
    }

    if (isset($input['retryCount'])) {
        $data['retryCount'] = (int)$input['retryCount'];
    }

    if (isset($input['timeoutSeconds'])) {
        $data['timeoutSeconds'] = (int)$input['timeoutSeconds'];
    }

    if (isset($input['isActive'])) {
        $data['isActive'] = (bool)$input['isActive'];
    }

    $success = WebhookService::update($webhookID, $data);

    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Webhook updated successfully' : 'Failed to update webhook',
    ]);
}

/**
 * 🗑️ Delete a webhook subscription
 *
 * @param array $input Request body with webhookID
 *
 * @return void Outputs JSON response
 */
function handleDeleteWebhook(array $input): void
{
    $webhookID = (int)($input['webhookID'] ?? 0);

    if ($webhookID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid webhook ID']);
        return;
    }

    $success = WebhookService::delete($webhookID);

    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Webhook deleted successfully' : 'Failed to delete webhook',
    ]);
}

/**
 * 🔘 Toggle a webhook's active status
 *
 * @param array $input Request body with webhookID
 *
 * @return void Outputs JSON response
 */
function handleToggleWebhook(array $input): void
{
    $webhookID = (int)($input['webhookID'] ?? 0);

    if ($webhookID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid webhook ID']);
        return;
    }

    // 🔍 Get current status
    $webhook = WebhookService::getWebhook($webhookID);

    if (!$webhook) {
        echo json_encode(['success' => false, 'error' => 'Webhook not found']);
        return;
    }

    // 🔄 Toggle the active status
    $newStatus = !(bool)$webhook['isActive'];
    $success   = WebhookService::update($webhookID, ['isActive' => $newStatus]);

    echo json_encode([
        'success'  => $success,
        'isActive' => $newStatus,
        'message'  => $success
            ? 'Webhook ' . ($newStatus ? 'enabled' : 'disabled') . ' successfully'
            : 'Failed to toggle webhook',
    ]);
}

/**
 * 🧪 Send a test payload to a webhook endpoint
 *
 * @param array $input Request body with webhookID
 *
 * @return void Outputs JSON response
 */
function handleTestWebhook(array $input): void
{
    $webhookID = (int)($input['webhookID'] ?? 0);

    if ($webhookID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid webhook ID']);
        return;
    }

    $result = WebhookService::sendTest($webhookID);

    echo json_encode($result);
}

/**
 * 🔄 Retry a failed webhook delivery
 *
 * @param array $input Request body with deliveryID
 *
 * @return void Outputs JSON response
 */
function handleRetryDelivery(array $input): void
{
    $deliveryID = (int)($input['deliveryID'] ?? 0);

    if ($deliveryID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid delivery ID']);
        return;
    }

    $result = WebhookService::deliver($deliveryID);

    echo json_encode($result);
}
