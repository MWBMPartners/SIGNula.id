<?php
/**
 * ============================================================================
 * 🔔 SIGNula — Notification Center (History & Management)
 * ============================================================================
 *
 * Purpose: Full notification history and management page within the user
 *          settings area. Allows users to view, filter, mark as read,
 *          archive, and delete their in-app notifications.
 *
 * Note: This is separate from notifications.php (Notification Preferences),
 *       which manages email/push/SMS notification preferences. This page
 *       displays the actual notification items received.
 *
 * Features:
 *   - Filter tabs: All, Security, Account, System, Billing, Social
 *   - Visual indicators for unread notifications (bold title, dot badge)
 *   - Priority-based visual styling (urgent = red, high = orange, etc.)
 *   - AJAX-powered interactions (no page reload for mark read/archive/delete)
 *   - "Mark All as Read" bulk action
 *   - "Load More" pagination for large notification lists
 *   - Empty state messaging when no notifications exist
 *   - Graceful degradation when JavaScript is disabled (page reload fallback)
 *
 * Layout: Uses the same settings page structure as security.php with
 *         the shared settings sidebar navigation.
 *
 * PHP Version: 8.3+
 *
 * @see web/public_html/settings/security.php — Settings page layout pattern
 * @see web/public_html/settings/notifications.php — Notification preferences page
 * @see web/private_html/notifications/NotificationService.php — Backend service
 * @see web/public_html/api/notification-actions.php — AJAX API endpoint
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
// @see web/_config/config.php — Application bootstrap (loads Database, SecurityUtils, etc.)
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔒 Require login — redirects to login page if no active session
requireLogin();

// 🔔 Load the NotificationService for server-side rendering
// @see web/private_html/notifications/NotificationService.php
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'notifications' . DIRECTORY_SEPARATOR . 'NotificationService.php';

// ========================================================================
// 📊 Page Configuration
// ========================================================================
$pageTitle = 'Notification Center';
$userID    = (int) $_SESSION['userID'];

// 🎫 Generate CSRF token for AJAX actions
// @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
$csrfToken = SecurityUtils::generateCSRFToken();

// 📋 Determine the active filter from query string (defaults to 'all')
$activeFilter = $_GET['filter'] ?? 'all';
$validFilters = ['all', 'security', 'account', 'system', 'billing', 'social'];
if (!in_array($activeFilter, $validFilters, true)) {
    $activeFilter = 'all';
}

// 📊 Fetch initial notification data for server-side rendering (SSR)
// This ensures content is visible even without JavaScript (progressive enhancement)
$typeFilter    = ($activeFilter !== 'all') ? $activeFilter : null;
$initialData   = NotificationService::getNotifications($userID, 20, 0, true, $typeFilter);
$notifications = $initialData['notifications'];
$totalCount    = $initialData['total'];
$unreadCount   = NotificationService::getUnreadCount($userID);

// 🔔 Check if notifications feature is enabled
$notificationsEnabled = (bool) getSetting('notifications.enabled', '1');

// 🎨 Include header
// @see web/private_html/layout/header.php — Shared page header with navigation
include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container mt-5">
    <div class="row">
        <!-- ============================================================ -->
        <!-- 📱 Settings Sidebar Navigation                               -->
        <!-- ============================================================ -->
        <div class="col-md-3 mb-4">
            <?php include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'settings-sidebar.php'; ?>
        </div>

        <!-- ============================================================ -->
        <!-- 📋 Main Content Area                                         -->
        <!-- ============================================================ -->
        <div class="col-md-9">

            <?php if (!$notificationsEnabled): ?>
                <!-- ⚠️ Notifications Disabled Notice -->
                <div class="alert alert-warning" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Notifications are currently disabled.</strong>
                    Contact your administrator for more information.
                </div>
            <?php endif; ?>

            <!-- ======================================================== -->
            <!-- 🔔 Notification Center Header                            -->
            <!-- ======================================================== -->
            <div class="card shadow mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title mb-1">
                                <i class="fas fa-bell me-2"></i>Notification Center
                            </h5>
                            <p class="text-muted mb-0 small" id="unread-status-text">
                                <?php if ($unreadCount > 0): ?>
                                    You have <strong id="unread-badge-count"><?php echo (int) $unreadCount; ?></strong> unread notification<?php echo $unreadCount !== 1 ? 's' : ''; ?>
                                <?php else: ?>
                                    All caught up! No unread notifications.
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if ($unreadCount > 0): ?>
                                <!-- ✅ Mark All as Read Button -->
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    id="btn-mark-all-read"
                                    title="Mark all notifications as read"
                                >
                                    <i class="fas fa-check-double me-1"></i>Mark All Read
                                </button>
                            <?php endif; ?>
                            <!-- ⚙️ Link to notification preferences -->
                            <a href="/settings/notifications" class="btn btn-sm btn-outline-secondary" title="Notification preferences">
                                <i class="fas fa-cog"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ======================================================== -->
            <!-- 📂 Filter Tabs                                           -->
            <!-- ======================================================== -->
            <ul class="nav nav-tabs mb-4" id="notification-filter-tabs" role="tablist">
                <?php
                // 🏷️ Define the filter tabs with their icons and labels
                $filterTabs = [
                    'all'      => ['icon' => 'fas fa-inbox',       'label' => 'All'],
                    'security' => ['icon' => 'fas fa-shield-alt',  'label' => 'Security'],
                    'account'  => ['icon' => 'fas fa-user-cog',    'label' => 'Account'],
                    'system'   => ['icon' => 'fas fa-info-circle', 'label' => 'System'],
                    'billing'  => ['icon' => 'fas fa-credit-card', 'label' => 'Billing'],
                    'social'   => ['icon' => 'fas fa-share-alt',   'label' => 'Social'],
                ];

                foreach ($filterTabs as $filterKey => $filterInfo):
                ?>
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link <?php echo $activeFilter === $filterKey ? 'active' : ''; ?>"
                            data-filter="<?php echo htmlspecialchars($filterKey); ?>"
                            type="button"
                            role="tab"
                            aria-selected="<?php echo $activeFilter === $filterKey ? 'true' : 'false'; ?>"
                        >
                            <i class="<?php echo htmlspecialchars($filterInfo['icon']); ?> me-1"></i>
                            <span class="d-none d-sm-inline"><?php echo htmlspecialchars($filterInfo['label']); ?></span>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>

            <!-- ======================================================== -->
            <!-- 📋 Notification List                                     -->
            <!-- ======================================================== -->
            <div id="notification-list">
                <?php if (empty($notifications)): ?>
                    <!-- 📭 Empty State -->
                    <div class="card shadow" id="empty-state">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No notifications</h5>
                            <p class="text-muted mb-0">
                                <?php if ($activeFilter !== 'all'): ?>
                                    No <?php echo htmlspecialchars($activeFilter); ?> notifications found.
                                    <a href="/settings/notification-center" class="text-decoration-none">View all notifications</a>
                                <?php else: ?>
                                    You're all caught up! New notifications will appear here.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- 📋 Notification Items -->
                    <div class="list-group shadow" id="notification-items">
                        <?php foreach ($notifications as $notification): ?>
                            <?php
                            // 🎨 Determine visual styling based on priority
                            // @see https://getbootstrap.com/docs/5.3/utilities/borders/
                            $priorityClass = match ($notification['priority']) {
                                'urgent' => 'border-start border-danger border-4',
                                'high'   => 'border-start border-warning border-4',
                                'low'    => 'border-start border-secondary border-4',
                                default  => '',
                            };

                            // 📖 Determine read state styling
                            $isUnread  = !(bool) $notification['isRead'];
                            $readClass = $isUnread ? 'bg-light' : '';

                            // 🎨 Determine icon colour based on notification type
                            // @see https://fontawesome.com/icons — Icon reference
                            $iconColor = match ($notification['type']) {
                                'security' => 'text-danger',
                                'account'  => 'text-primary',
                                'system'   => 'text-info',
                                'billing'  => 'text-success',
                                'social'   => 'text-purple',
                                default    => 'text-muted',
                            };

                            // 🏷️ Badge colour mapping for the type label
                            $badgeColor = match ($notification['type']) {
                                'security' => 'danger',
                                'account'  => 'primary',
                                'system'   => 'info',
                                'billing'  => 'success',
                                'social'   => 'secondary',
                                default    => 'secondary',
                            };
                            ?>
                            <div
                                class="list-group-item list-group-item-action <?php echo $priorityClass; ?> <?php echo $readClass; ?>"
                                id="notification-<?php echo (int) $notification['notificationID']; ?>"
                                data-notification-id="<?php echo (int) $notification['notificationID']; ?>"
                            >
                                <div class="d-flex align-items-start">
                                    <!-- 🎨 Notification Icon -->
                                    <div class="me-3 mt-1">
                                        <i class="fas fa-<?php echo htmlspecialchars($notification['icon']); ?> fa-lg <?php echo $iconColor; ?>"></i>
                                    </div>

                                    <!-- 📝 Notification Content -->
                                    <div class="flex-grow-1 me-3">
                                        <div class="d-flex align-items-center mb-1">
                                            <!-- 📌 Title (bold if unread) -->
                                            <?php if ($isUnread): ?>
                                                <strong class="me-2"><?php echo htmlspecialchars($notification['title']); ?></strong>
                                                <span class="badge rounded-pill bg-primary" style="font-size: 0.6rem;">NEW</span>
                                            <?php else: ?>
                                                <span class="me-2"><?php echo htmlspecialchars($notification['title']); ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- 📄 Message body -->
                                        <p class="mb-1 text-muted small">
                                            <?php echo htmlspecialchars($notification['message']); ?>
                                        </p>

                                        <!-- ⏰ Metadata row: type badge + relative time + priority badge -->
                                        <div class="d-flex align-items-center flex-wrap gap-1">
                                            <span class="badge bg-<?php echo $badgeColor; ?>" style="font-size: 0.65rem;">
                                                <?php echo htmlspecialchars(ucfirst($notification['type'])); ?>
                                            </span>
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i><?php echo htmlspecialchars($notification['relativeTime']); ?>
                                            </small>
                                            <?php if ($notification['priority'] === 'urgent'): ?>
                                                <span class="badge bg-danger" style="font-size: 0.65rem;">
                                                    <i class="fas fa-exclamation-triangle me-1"></i>Urgent
                                                </span>
                                            <?php elseif ($notification['priority'] === 'high'): ?>
                                                <span class="badge bg-warning text-dark" style="font-size: 0.65rem;">
                                                    <i class="fas fa-exclamation me-1"></i>High
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- 🔧 Action Buttons -->
                                    <div class="d-flex flex-column flex-sm-row gap-1">
                                        <?php if (!empty($notification['actionUrl'])): ?>
                                            <a
                                                href="<?php echo htmlspecialchars($notification['actionUrl']); ?>"
                                                class="btn btn-sm btn-outline-primary"
                                                title="View details"
                                            >
                                                <i class="fas fa-external-link-alt"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($isUnread): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-success btn-mark-read"
                                                data-id="<?php echo (int) $notification['notificationID']; ?>"
                                                title="Mark as read"
                                            >
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-secondary btn-archive"
                                            data-id="<?php echo (int) $notification['notificationID']; ?>"
                                            title="Archive"
                                        >
                                            <i class="fas fa-archive"></i>
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger btn-delete"
                                            data-id="<?php echo (int) $notification['notificationID']; ?>"
                                            title="Delete"
                                        >
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- ======================================================== -->
                    <!-- 📄 Load More / Pagination                                -->
                    <!-- ======================================================== -->
                    <?php if ($totalCount > count($notifications)): ?>
                        <div class="text-center mt-4" id="load-more-container">
                            <button
                                type="button"
                                class="btn btn-outline-primary"
                                id="btn-load-more"
                                data-offset="<?php echo count($notifications); ?>"
                                data-total="<?php echo (int) $totalCount; ?>"
                            >
                                <i class="fas fa-chevron-down me-1"></i>
                                Load More
                                <span class="text-muted ms-1">
                                    (<?php echo count($notifications); ?> of <?php echo (int) $totalCount; ?>)
                                </span>
                            </button>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================== -->
<!-- 📜 Notification Center JavaScript                                        -->
<!-- ======================================================================== -->
<!-- AJAX-powered interactions for a seamless user experience.                -->
<!-- Falls back gracefully if JavaScript is disabled — server-rendered content -->
<!-- is fully functional without JS, actions will require page reloads.       -->
<!-- @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API         -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // ========================================================================
    // ⚙️ Configuration
    // ========================================================================
    var API_URL    = '/api/notification-actions.php';
    var CSRF_TOKEN = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
    var currentFilter = '<?php echo htmlspecialchars($activeFilter, ENT_QUOTES, 'UTF-8'); ?>';
    var currentOffset = <?php echo count($notifications); ?>;

    // ========================================================================
    // 🔧 Helper: Send AJAX request to the notification API
    // ========================================================================
    /**
     * Sends a POST request to the notification API with CSRF token.
     * Uses the Fetch API with credentials for same-origin cookie handling.
     *
     * @param {Object} data - Request payload (must include 'action')
     * @returns {Promise<Object>} Parsed JSON response
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API/Using_Fetch
     */
    function apiRequest(data) {
        data.csrf_token = CSRF_TOKEN;
        return fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(data),
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        });
    }

    /**
     * 🛡️ Escape HTML entities to prevent XSS in dynamically inserted content.
     * Uses the DOM's native text node escaping for safety.
     *
     * @param {string} text - Raw text to escape
     * @returns {string} HTML-safe string
     * @see https://cheatsheetseries.owasp.org/cheatsheets/DOM_based_XSS_Prevention_Cheat_Sheet.html
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text || ''));
        return div.innerHTML;
    }

    /**
     * 🗑️ Remove a notification element from the DOM with a fade-out animation.
     *
     * @param {number} notificationID - The notification to remove
     */
    function removeNotificationElement(notificationID) {
        var element = document.getElementById('notification-' + notificationID);
        if (element) {
            // 🎬 Animate fade-out and collapse
            element.style.transition = 'opacity 0.3s ease, max-height 0.3s ease, padding 0.3s ease';
            element.style.opacity = '0';
            element.style.maxHeight = '0';
            element.style.overflow = 'hidden';
            element.style.paddingTop = '0';
            element.style.paddingBottom = '0';
            setTimeout(function() {
                element.remove();
                checkEmptyState();
            }, 300);
        }
    }

    /**
     * 📭 Check if the notification list is empty and show the empty state card.
     */
    function checkEmptyState() {
        var items = document.getElementById('notification-items');
        if (items && items.children.length === 0) {
            var list = document.getElementById('notification-list');
            if (list) {
                var filterText = currentFilter !== 'all'
                    ? 'No ' + escapeHtml(currentFilter) + ' notifications found. <a href="/settings/notification-center" class="text-decoration-none">View all</a>'
                    : 'You\'re all caught up! New notifications will appear here.';

                list.innerHTML =
                    '<div class="card shadow" id="empty-state">' +
                        '<div class="card-body text-center py-5">' +
                            '<i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>' +
                            '<h5 class="text-muted">No notifications</h5>' +
                            '<p class="text-muted mb-0">' + filterText + '</p>' +
                        '</div>' +
                    '</div>';
            }
            // 🔢 Update the unread count display
            updateUnreadDisplay(0);
        }
    }

    /**
     * 🔢 Update the unread count display in the page header area.
     *
     * @param {number} count - The new unread count to display
     */
    function updateUnreadDisplay(count) {
        var statusText = document.getElementById('unread-status-text');
        if (statusText) {
            if (count > 0) {
                statusText.innerHTML = 'You have <strong id="unread-badge-count">' + count +
                    '</strong> unread notification' + (count !== 1 ? 's' : '');
            } else {
                statusText.innerHTML = 'All caught up! No unread notifications.';
            }
        }
    }

    // ========================================================================
    // 📂 Filter Tab Clicks
    // ========================================================================
    // Handles switching between notification type filters via AJAX.
    // Updates the URL query parameter for bookmarkability without page reload.
    var filterButtons = document.querySelectorAll('#notification-filter-tabs .nav-link');
    filterButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            var filter = this.getAttribute('data-filter');
            if (filter === currentFilter) {
                return; // 🔄 Already on this filter — no action needed
            }

            // 🎨 Update active tab styling (ARIA + visual)
            filterButtons.forEach(function(btn) {
                btn.classList.remove('active');
                btn.setAttribute('aria-selected', 'false');
            });
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');

            currentFilter = filter;
            currentOffset = 0;

            // 📋 Update browser URL without page reload (for bookmarkability)
            // @see https://developer.mozilla.org/en-US/docs/Web/API/History/replaceState
            var newUrl = '/settings/notification-center' + (filter !== 'all' ? '?filter=' + encodeURIComponent(filter) : '');
            window.history.replaceState(null, '', newUrl);

            // 📋 Load notifications for the selected filter
            loadNotifications(true);
        });
    });

    /**
     * 📋 Load notifications via AJAX and render them into the DOM.
     *
     * @param {boolean} replace - If true, replace all items; if false, append (load more)
     */
    function loadNotifications(replace) {
        var data = {
            action: 'get_notifications',
            limit: 20,
            offset: replace ? 0 : currentOffset
        };

        // 📂 Apply type filter if not "all"
        if (currentFilter !== 'all') {
            data.type = currentFilter;
        }

        apiRequest(data)
            .then(function(response) {
                if (!response.success) {
                    console.error('Failed to load notifications:', response.error);
                    return;
                }

                var container = document.getElementById('notification-items');
                var list = document.getElementById('notification-list');

                // 📭 Handle empty results
                if (response.notifications.length === 0 && replace) {
                    var filterText = currentFilter !== 'all'
                        ? 'No ' + escapeHtml(currentFilter) + ' notifications found. <a href="/settings/notification-center" class="text-decoration-none">View all</a>'
                        : 'You\'re all caught up! New notifications will appear here.';

                    list.innerHTML =
                        '<div class="card shadow" id="empty-state">' +
                            '<div class="card-body text-center py-5">' +
                                '<i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>' +
                                '<h5 class="text-muted">No notifications</h5>' +
                                '<p class="text-muted mb-0">' + filterText + '</p>' +
                            '</div>' +
                        '</div>';
                    return;
                }

                // 🏗️ Build HTML for the fetched notifications
                var html = '';
                response.notifications.forEach(function(n) {
                    html += buildNotificationHtml(n);
                });

                if (replace) {
                    // 🔄 Replace entire list content
                    list.innerHTML = '<div class="list-group shadow" id="notification-items">' + html + '</div>';
                    currentOffset = response.notifications.length;
                } else {
                    // ➕ Append to existing list (load more)
                    if (container) {
                        container.insertAdjacentHTML('beforeend', html);
                    }
                    currentOffset += response.notifications.length;
                }

                // 📄 Update or remove the "Load More" button
                updateLoadMoreButton(response.total);

                // 🔗 Re-bind event listeners on newly added elements
                bindActionButtons();
            })
            .catch(function(error) {
                console.error('Failed to load notifications:', error);
            });
    }

    /**
     * 🏗️ Build HTML markup for a single notification item.
     * Mirrors the PHP-rendered structure for visual consistency.
     *
     * @param {Object} n - Notification data object from the API
     * @returns {string} HTML string for the notification list item
     */
    function buildNotificationHtml(n) {
        // 🎨 Priority-based border styling
        var priorityClass = '';
        if (n.priority === 'urgent') {
            priorityClass = 'border-start border-danger border-4';
        } else if (n.priority === 'high') {
            priorityClass = 'border-start border-warning border-4';
        } else if (n.priority === 'low') {
            priorityClass = 'border-start border-secondary border-4';
        }

        // 📖 Read/unread state
        var isUnread = !n.isRead || n.isRead === '0' || n.isRead === 0;
        var readClass = isUnread ? 'bg-light' : '';

        // 🎨 Icon colour mapping by type
        var iconColorMap = {
            security: 'text-danger',
            account: 'text-primary',
            system: 'text-info',
            billing: 'text-success',
            social: 'text-purple'
        };
        var iconColor = iconColorMap[n.type] || 'text-muted';

        // 🏷️ Badge colour mapping by type
        var badgeColorMap = {
            security: 'danger',
            account: 'primary',
            system: 'info',
            billing: 'success',
            social: 'secondary'
        };
        var badgeColor = badgeColorMap[n.type] || 'secondary';

        // 🏗️ Assemble the HTML
        var html = '';
        html += '<div class="list-group-item list-group-item-action ' + priorityClass + ' ' + readClass + '"';
        html += ' id="notification-' + parseInt(n.notificationID) + '"';
        html += ' data-notification-id="' + parseInt(n.notificationID) + '">';
        html += '<div class="d-flex align-items-start">';

        // 🎨 Icon
        html += '<div class="me-3 mt-1">';
        html += '<i class="fas fa-' + escapeHtml(n.icon || 'bell') + ' fa-lg ' + iconColor + '"></i>';
        html += '</div>';

        // 📝 Content
        html += '<div class="flex-grow-1 me-3">';
        html += '<div class="d-flex align-items-center mb-1">';
        if (isUnread) {
            html += '<strong class="me-2">' + escapeHtml(n.title) + '</strong>';
            html += '<span class="badge rounded-pill bg-primary" style="font-size: 0.6rem;">NEW</span>';
        } else {
            html += '<span class="me-2">' + escapeHtml(n.title) + '</span>';
        }
        html += '</div>';

        // 📄 Message
        html += '<p class="mb-1 text-muted small">' + escapeHtml(n.message) + '</p>';

        // ⏰ Metadata row
        html += '<div class="d-flex align-items-center flex-wrap gap-1">';
        html += '<span class="badge bg-' + badgeColor + '" style="font-size: 0.65rem;">';
        html += escapeHtml((n.type || '').charAt(0).toUpperCase() + (n.type || '').slice(1));
        html += '</span>';
        html += '<small class="text-muted"><i class="fas fa-clock me-1"></i>' + escapeHtml(n.relativeTime || '') + '</small>';

        // Priority badges for urgent/high
        if (n.priority === 'urgent') {
            html += '<span class="badge bg-danger" style="font-size: 0.65rem;">';
            html += '<i class="fas fa-exclamation-triangle me-1"></i>Urgent</span>';
        } else if (n.priority === 'high') {
            html += '<span class="badge bg-warning text-dark" style="font-size: 0.65rem;">';
            html += '<i class="fas fa-exclamation me-1"></i>High</span>';
        }
        html += '</div></div>';

        // 🔧 Action buttons
        html += '<div class="d-flex flex-column flex-sm-row gap-1">';
        if (n.actionUrl) {
            html += '<a href="' + escapeHtml(n.actionUrl) + '" class="btn btn-sm btn-outline-primary" title="View details">';
            html += '<i class="fas fa-external-link-alt"></i></a>';
        }
        if (isUnread) {
            html += '<button type="button" class="btn btn-sm btn-outline-success btn-mark-read"';
            html += ' data-id="' + parseInt(n.notificationID) + '" title="Mark as read">';
            html += '<i class="fas fa-check"></i></button>';
        }
        html += '<button type="button" class="btn btn-sm btn-outline-secondary btn-archive"';
        html += ' data-id="' + parseInt(n.notificationID) + '" title="Archive">';
        html += '<i class="fas fa-archive"></i></button>';
        html += '<button type="button" class="btn btn-sm btn-outline-danger btn-delete"';
        html += ' data-id="' + parseInt(n.notificationID) + '" title="Delete">';
        html += '<i class="fas fa-trash-alt"></i></button>';
        html += '</div></div></div>';

        return html;
    }

    /**
     * 📄 Update or remove the "Load More" button based on remaining items.
     *
     * @param {number} total - Total notification count for current filter
     */
    function updateLoadMoreButton(total) {
        // 🗑️ Remove existing button if present
        var existing = document.getElementById('load-more-container');
        if (existing) {
            existing.remove();
        }

        // ➕ Add new button if more items are available
        if (currentOffset < total) {
            var loadMoreHtml =
                '<div class="text-center mt-4" id="load-more-container">' +
                    '<button type="button" class="btn btn-outline-primary" id="btn-load-more"' +
                        ' data-offset="' + currentOffset + '"' +
                        ' data-total="' + total + '">' +
                        '<i class="fas fa-chevron-down me-1"></i>Load More' +
                        ' <span class="text-muted ms-1">(' + currentOffset + ' of ' + total + ')</span>' +
                    '</button>' +
                '</div>';

            var list = document.getElementById('notification-list');
            if (list) {
                list.insertAdjacentHTML('beforeend', loadMoreHtml);
            }
            bindLoadMore();
        }
    }

    // ========================================================================
    // 🔗 Event Binding — Action Buttons
    // ========================================================================

    /**
     * 🔗 Bind click handlers to all notification action buttons.
     * Called on initial load and after AJAX content updates.
     */
    function bindActionButtons() {
        // ✅ Mark as Read buttons
        document.querySelectorAll('.btn-mark-read').forEach(function(btn) {
            btn.onclick = function() {
                var id = parseInt(this.getAttribute('data-id'));
                var self = this;
                self.disabled = true;

                apiRequest({ action: 'mark_read', notificationID: id })
                    .then(function(response) {
                        if (response.success) {
                            // 🎨 Update visual state — remove bold/NEW badge/bg-light
                            var el = document.getElementById('notification-' + id);
                            if (el) {
                                el.classList.remove('bg-light');
                                // Replace <strong> with <span> for the title
                                var strong = el.querySelector('strong');
                                if (strong) {
                                    var span = document.createElement('span');
                                    span.className = 'me-2';
                                    span.textContent = strong.textContent;
                                    strong.replaceWith(span);
                                }
                                // Remove NEW badge
                                var newBadge = el.querySelector('.badge.bg-primary');
                                if (newBadge && newBadge.textContent.trim() === 'NEW') {
                                    newBadge.remove();
                                }
                                // Remove the mark-read button itself
                                self.remove();
                            }
                        } else {
                            self.disabled = false;
                        }
                    })
                    .catch(function(error) {
                        console.error('Failed to mark notification as read:', error);
                        self.disabled = false;
                    });
            };
        });

        // 🗄️ Archive buttons
        document.querySelectorAll('.btn-archive').forEach(function(btn) {
            btn.onclick = function() {
                var id = parseInt(this.getAttribute('data-id'));
                if (!confirm('Archive this notification?')) {
                    return;
                }
                apiRequest({ action: 'archive', notificationID: id })
                    .then(function(response) {
                        if (response.success) {
                            removeNotificationElement(id);
                        }
                    })
                    .catch(function(error) {
                        console.error('Failed to archive notification:', error);
                    });
            };
        });

        // 🗑️ Delete buttons
        document.querySelectorAll('.btn-delete').forEach(function(btn) {
            btn.onclick = function() {
                var id = parseInt(this.getAttribute('data-id'));
                if (!confirm('Permanently delete this notification? This cannot be undone.')) {
                    return;
                }
                apiRequest({ action: 'delete', notificationID: id })
                    .then(function(response) {
                        if (response.success) {
                            removeNotificationElement(id);
                        }
                    })
                    .catch(function(error) {
                        console.error('Failed to delete notification:', error);
                    });
            };
        });
    }

    /**
     * 📄 Bind the "Load More" button click handler.
     */
    function bindLoadMore() {
        var btn = document.getElementById('btn-load-more');
        if (btn) {
            btn.onclick = function() {
                var self = this;
                // 🔄 Show loading state
                self.disabled = true;
                self.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Loading...';
                loadNotifications(false);
            };
        }
    }

    // ========================================================================
    // ✅ Mark All as Read
    // ========================================================================
    var markAllBtn = document.getElementById('btn-mark-all-read');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function() {
            if (!confirm('Mark all notifications as read?')) {
                return;
            }

            var self = this;
            // 🔄 Show loading state on the button
            self.disabled = true;
            self.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Marking...';

            apiRequest({ action: 'mark_all_read' })
                .then(function(response) {
                    if (response.success) {
                        // 🎨 Update all notification items visually
                        document.querySelectorAll('.list-group-item.bg-light').forEach(function(el) {
                            el.classList.remove('bg-light');
                        });
                        // Remove all mark-read buttons
                        document.querySelectorAll('.btn-mark-read').forEach(function(btn) {
                            btn.remove();
                        });
                        // Replace all <strong> titles with <span> (remove bold)
                        document.querySelectorAll('#notification-items strong').forEach(function(strong) {
                            var span = document.createElement('span');
                            span.className = 'me-2';
                            span.textContent = strong.textContent;
                            strong.replaceWith(span);
                        });
                        // Remove all NEW badges
                        document.querySelectorAll('.badge.bg-primary').forEach(function(badge) {
                            if (badge.textContent.trim() === 'NEW') {
                                badge.remove();
                            }
                        });

                        // 🔢 Update header unread count
                        updateUnreadDisplay(0);

                        // 🙈 Hide the Mark All Read button (no longer needed)
                        self.style.display = 'none';
                    } else {
                        self.disabled = false;
                        self.innerHTML = '<i class="fas fa-check-double me-1"></i>Mark All Read';
                    }
                })
                .catch(function(error) {
                    console.error('Failed to mark all as read:', error);
                    self.disabled = false;
                    self.innerHTML = '<i class="fas fa-check-double me-1"></i>Mark All Read';
                });
        });
    }

    // ========================================================================
    // 🚀 Initial Event Binding
    // ========================================================================
    bindActionButtons();
    bindLoadMore();
});
</script>

<?php
// 🎨 Include footer
// @see web/private_html/layout/footer.php — Shared page footer with scripts
include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'footer.php';
?>
