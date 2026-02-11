<?php
/**
 * Webhook Management API
 *
 * Purpose: Partner API for managing webhook endpoints
 *
 * Actions: list, create, update, delete, regenerate_secret, test, deliveries
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.3.0-beta
 */

require_once dirname(__DIR__, 3) . '/_config/config.php';
require_once dirname(__DIR__, 3) . '/_backend/Database.php';
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';
require_once dirname(__DIR__, 3) . '/_backend/AccessControl.php';

header('Content-Type: application/json');

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔐 Check authentication
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 📥 Get request data
$input = json_decode(file_get_contents('php://input'), true);
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// 🛡️ Verify CSRF token for state-changing requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
        exit;
    }
}

try {
    switch ($action) {
        case 'list':
            handleList($accessControl);
            break;

        case 'create':
            handleCreate($input, $accessControl);
            break;

        case 'update':
            handleUpdate($input, $accessControl);
            break;

        case 'delete':
            handleDelete($input, $accessControl);
            break;

        case 'regenerate_secret':
            handleRegenerateSecret($input, $accessControl);
            break;

        case 'test':
            handleTest($input, $accessControl);
            break;

        case 'deliveries':
            handleDeliveries($accessControl);
            break;

        case 'stats':
            handleStats($accessControl);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * 📋 List webhook endpoints for a partner
 */
function handleList($accessControl) {
    $partnerID = (int)($_GET['partnerID'] ?? 0);

    // 🔐 Verify partner access
    if (!$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Access denied to this partner');
    }

    $endpoints = WebhookManager::getEndpoints($partnerID);

    // 📦 Decode JSON fields for response
    foreach ($endpoints as &$ep) {
        $ep['events'] = json_decode($ep['events'], true) ?: [];
        $ep['headers'] = json_decode($ep['headers'], true) ?: [];
        $ep['retryPolicy'] = json_decode($ep['retryPolicy'], true) ?: [];
    }

    echo json_encode(['success' => true, 'data' => $endpoints]);
}

/**
 * ➕ Create a new webhook endpoint
 */
function handleCreate($input, $accessControl) {
    $partnerID = (int)($input['partnerID'] ?? 0);
    $url = trim($input['url'] ?? '');
    $events = $input['events'] ?? [];

    if (!$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Access denied to this partner');
    }

    if (empty($url)) {
        throw new Exception('URL is required');
    }

    if (empty($events)) {
        throw new Exception('At least one event type must be selected');
    }

    $result = WebhookManager::createEndpoint($partnerID, $url, $events, [
        'description' => $input['description'] ?? null,
        'rateLimitPerMinute' => $input['rateLimitPerMinute'] ?? 60,
        'timeoutSeconds' => $input['timeoutSeconds'] ?? 30,
    ]);

    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'endpointID' => $result['endpointID'],
            'secret' => $result['secret'],
            'message' => 'Webhook endpoint created. Save the signing secret - it will not be shown again.',
        ]);
    } else {
        throw new Exception($result['message']);
    }
}

/**
 * ✏️ Update a webhook endpoint
 */
function handleUpdate($input, $accessControl) {
    $partnerID = (int)($input['partnerID'] ?? 0);
    $endpointID = (int)($input['endpointID'] ?? 0);

    if (!$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Access denied to this partner');
    }

    $updates = [];
    if (isset($input['url'])) {
        $updates['url'] = $input['url'];
    }
    if (isset($input['events'])) {
        $updates['events'] = $input['events'];
    }
    if (isset($input['status'])) {
        $updates['status'] = $input['status'];
    }
    if (isset($input['description'])) {
        $updates['description'] = $input['description'];
    }

    $result = WebhookManager::updateEndpoint($endpointID, $partnerID, $updates);

    if ($result['success']) {
        echo json_encode(['success' => true, 'message' => 'Endpoint updated']);
    } else {
        throw new Exception($result['message']);
    }
}

/**
 * 🗑️ Delete a webhook endpoint
 */
function handleDelete($input, $accessControl) {
    $partnerID = (int)($input['partnerID'] ?? 0);
    $endpointID = (int)($input['endpointID'] ?? 0);

    if (!$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Access denied to this partner');
    }

    $result = WebhookManager::deleteEndpoint($endpointID, $partnerID);

    if ($result['success']) {
        echo json_encode(['success' => true, 'message' => 'Endpoint deleted']);
    } else {
        throw new Exception($result['message']);
    }
}

/**
 * 🔑 Regenerate signing secret
 */
function handleRegenerateSecret($input, $accessControl) {
    $partnerID = (int)($input['partnerID'] ?? 0);
    $endpointID = (int)($input['endpointID'] ?? 0);

    if (!$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Access denied to this partner');
    }

    $result = WebhookManager::regenerateSecret($endpointID, $partnerID);

    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'secret' => $result['secret'],
            'message' => 'New signing secret generated. Save it now - it will not be shown again.',
        ]);
    } else {
        throw new Exception($result['message']);
    }
}

/**
 * 🧪 Send a test webhook
 */
function handleTest($input, $accessControl) {
    $partnerID = (int)($input['partnerID'] ?? 0);
    $endpointID = (int)($input['endpointID'] ?? 0);

    if (!$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Access denied to this partner');
    }

    // 📤 Dispatch a test event to the specific endpoint
    $result = WebhookManager::dispatch('test.ping', [
        'message' => 'This is a test webhook from SIGNula',
        'timestamp' => date('c'),
        'endpointID' => $endpointID,
    ], $partnerID);

    echo json_encode([
        'success' => true,
        'dispatched' => $result['dispatched'],
        'errors' => $result['errors'],
        'message' => $result['dispatched'] > 0 ? 'Test webhook sent' : 'No active endpoints matched',
    ]);
}

/**
 * 📋 Get delivery log for an endpoint
 */
function handleDeliveries($accessControl) {
    $partnerID = (int)($_GET['partnerID'] ?? 0);
    $endpointID = (int)($_GET['endpointID'] ?? 0);

    if (!$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Access denied to this partner');
    }

    $deliveries = WebhookManager::getDeliveries($endpointID, $partnerID, 50);

    echo json_encode(['success' => true, 'data' => $deliveries]);
}

/**
 * 📊 Get delivery statistics
 */
function handleStats($accessControl) {
    $partnerID = (int)($_GET['partnerID'] ?? 0);

    if (!$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Access denied to this partner');
    }

    $stats = WebhookManager::getDeliveryStats($partnerID);

    echo json_encode(['success' => true, 'data' => $stats]);
}
