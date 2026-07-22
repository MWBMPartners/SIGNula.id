<?php
/**
 * ============================================================================
 * 🔔 SIGNula — Notification Center AJAX API Endpoint
 * ============================================================================
 *
 * Purpose: Handles all user-facing notification AJAX operations including
 *          fetching unread counts, listing notifications, marking as read,
 *          archiving, and deleting notifications.
 *
 * Endpoint: /api/notification-actions.php
 *
 * Supported Actions:
 *   GET:
 *     - get_unread_count  — Returns the unread notification count (no CSRF needed)
 *
 *   POST (requires CSRF token):
 *     - get_notifications — Paginated notification list with optional filters
 *     - mark_read         — Mark a single notification as read
 *     - mark_all_read     — Mark all notifications as read
 *     - archive           — Archive a notification
 *     - delete            — Delete a notification
 *
 * Authentication: All actions require an active user session (not admin-only).
 * CSRF: Required for all POST (state-changing) actions via csrf_token or X-CSRF-TOKEN header.
 *
 * PHP Version: 8.3+
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/REST_Security_Cheat_Sheet.html
 * @see https://www.php.net/manual/en/function.json-encode.php
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application — loads config, database, session, and all core classes
// @see web/_config/config.php — Application bootstrap
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔔 Load the NotificationService class
// @see web/private_html/notifications/NotificationService.php
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'notifications' . DIRECTORY_SEPARATOR . 'NotificationService.php';

// 📋 Set JSON response header for all responses
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
header('Content-Type: application/json');

// ========================================================================
// 🔒 Authentication Check — User must be logged in
// ========================================================================
// All notification actions require an active session. This is a user-facing
// endpoint, not admin-only — any authenticated user can manage their own
// notifications.
if (!isset($_SESSION['userID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required. Please log in.']);
    exit;
}

$userID = (int) $_SESSION['userID'];

// ========================================================================
// 📥 Route by HTTP Method
// ========================================================================
// GET requests are used for safe, read-only operations (unread count).
// POST requests are used for all state-changing operations.
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // ========================================================================
    // 📊 GET — Handle safe read-only actions
    // ========================================================================
    $action = $_GET['action'] ?? '';

    switch ($action) {
        // 🔢 Get Unread Count
        // Returns the number of unread, non-archived, non-expired notifications.
        // Used by the notification bell badge in the header for real-time updates.
        // No CSRF needed — this is a safe, idempotent read operation.
        case 'get_unread_count':
            $count = NotificationService::getUnreadCount($userID);
            echo json_encode([
                'success' => true,
                'count'   => $count,
            ]);
            break;

        default:
            // ❌ Unknown GET action
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Unknown action. Supported GET actions: get_unread_count',
            ]);
            break;
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ========================================================================
    // 📝 POST — Handle state-changing actions
    // ========================================================================

    // 📥 Parse the request body
    // Supports both JSON body (application/json) and form-encoded (application/x-www-form-urlencoded)
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        // 📦 JSON body — parse from php://input
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid JSON in request body.']);
            exit;
        }
    } else {
        // 📝 Form-encoded body — use $_POST
        $input = $_POST;
    }

    $action = $input['action'] ?? '';

    // 🛡️ CSRF Token Verification for all POST actions
    // Accepts token via request body (csrf_token) or custom header (X-CSRF-TOKEN)
    // @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Invalid or expired CSRF token. Please refresh the page and try again.',
        ]);
        exit;
    }

    // 🎯 Route to the appropriate action handler
    switch ($action) {

        // ====================================================================
        // 📋 Get Notifications List
        // ====================================================================
        // Returns a paginated list of notifications with optional type filter.
        //
        // Parameters:
        //   - limit  (int, optional)    — Max results, default 50
        //   - offset (int, optional)    — Pagination offset, default 0
        //   - type   (string, optional) — Filter by type: security|account|system|billing|social
        //
        // Response:
        //   { success: true, notifications: [...], total: N, hasMore: bool }
        case 'get_notifications':
            $limit  = max(1, min(100, (int) ($input['limit'] ?? 50)));   // Clamp to 1-100
            $offset = max(0, (int) ($input['offset'] ?? 0));            // Non-negative
            $type   = isset($input['type']) && $input['type'] !== 'all' ? $input['type'] : null;

            $result = NotificationService::getNotifications(
                $userID,
                $limit,
                $offset,
                true,  // Include read notifications
                $type
            );

            echo json_encode([
                'success'       => true,
                'notifications' => $result['notifications'],
                'total'         => $result['total'],
                'hasMore'       => ($offset + $limit) < $result['total'],
            ]);
            break;

        // ====================================================================
        // ✅ Mark Single Notification as Read
        // ====================================================================
        // Parameters:
        //   - notificationID (int, required) — The notification to mark
        //
        // Security: Only marks notifications owned by the current user.
        case 'mark_read':
            $notificationID = (int) ($input['notificationID'] ?? 0);

            if ($notificationID <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Valid notificationID is required.']);
                exit;
            }

            $marked = NotificationService::markAsRead($notificationID, $userID);
            echo json_encode([
                'success' => $marked,
                'message' => $marked ? 'Notification marked as read.' : 'Failed to mark notification.',
            ]);
            break;

        // ====================================================================
        // ✅ Mark All Notifications as Read
        // ====================================================================
        // Marks all unread, non-archived notifications for the current user.
        //
        // Response:
        //   { success: true, count: N } — Number of notifications marked
        case 'mark_all_read':
            $count = NotificationService::markAllAsRead($userID);
            echo json_encode([
                'success' => true,
                'count'   => $count,
                'message' => $count > 0
                    ? $count . ' notification(s) marked as read.'
                    : 'No unread notifications to mark.',
            ]);
            break;

        // ====================================================================
        // 🗄️ Archive Notification
        // ====================================================================
        // Moves a notification to archived state (hidden from main list).
        //
        // Parameters:
        //   - notificationID (int, required) — The notification to archive
        case 'archive':
            $notificationID = (int) ($input['notificationID'] ?? 0);

            if ($notificationID <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Valid notificationID is required.']);
                exit;
            }

            $archived = NotificationService::archive($notificationID, $userID);
            echo json_encode([
                'success' => $archived,
                'message' => $archived ? 'Notification archived.' : 'Failed to archive notification.',
            ]);
            break;

        // ====================================================================
        // 🗑️ Delete Notification
        // ====================================================================
        // Permanently removes a notification from the database.
        //
        // Parameters:
        //   - notificationID (int, required) — The notification to delete
        case 'delete':
            $notificationID = (int) ($input['notificationID'] ?? 0);

            if ($notificationID <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Valid notificationID is required.']);
                exit;
            }

            $deleted = NotificationService::delete($notificationID, $userID);
            echo json_encode([
                'success' => $deleted,
                'message' => $deleted ? 'Notification deleted.' : 'Failed to delete notification.',
            ]);
            break;

        // ====================================================================
        // ❌ Unknown Action
        // ====================================================================
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Unknown action. Supported POST actions: get_notifications, mark_read, mark_all_read, archive, delete',
            ]);
            break;
    }

} else {
    // ========================================================================
    // ❌ Unsupported HTTP Method
    // ========================================================================
    // Only GET and POST are supported by this endpoint.
    // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/405
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode([
        'success' => false,
        'error'   => 'Method not allowed. Use GET or POST.',
    ]);
}
