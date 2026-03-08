<?php
/**
 * ============================================================================
 * 🔗 Webhook Management Dashboard (Super Admin)
 * ============================================================================
 *
 * Purpose: Self-contained admin interface for managing webhook subscriptions,
 *          viewing delivery logs, and testing webhook endpoints.
 * PHP Version: 8.3+
 *
 * Features:
 * - Dashboard summary cards (total, active, delivered today, failed today)
 * - Webhook CRUD with create/edit modal
 * - Event type checkboxes grouped by category
 * - Custom header management (add/remove key-value pairs)
 * - Delivery log viewer with expandable payload/response details
 * - One-click test delivery and retry for failed deliveries
 * - Toggle webhook active status via switch
 *
 * @see https://getbootstrap.com/docs/5.3/ - Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/v6/search - FontAwesome 6.4.2 Icons
 *
 * @package    SIGNula
 * @subpackage Admin
 * @version    1.0.0
 * @since      2.7.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🔌 Load core configuration and dependencies
// dirname(__DIR__, 3) navigates: webhooks/ -> admin/ -> public_html/ -> web/
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 🔌 Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check — must be logged in
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin check — only Global Admins can manage webhooks
$accessControl->requireSuperAdmin();

// 🔐 Generate CSRF token for AJAX POST requests
$csrfToken = SecurityUtils::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webhook Management - SIGNula Admin</title>

    <!-- 🎨 Bootstrap 5.3.2 CSS -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <!-- 🎨 FontAwesome 6.4.2 Icons -->
    <!-- @see https://fontawesome.com/v6/search -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">

    <style>
        /* 🎨 Page background */
        body {
            background-color: #f8f9fa;
        }

        /* 🎨 Page header with brand gradient */
        .page-header {
            background: linear-gradient(135deg, #4f46e5, #3730a3);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        /* 📊 Summary stat cards */
        .stat-card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
        }
        .stat-card .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        .stat-card .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1;
        }

        /* 📋 Table styling */
        .table-webhooks th {
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
        }

        /* 🏷️ Event badges */
        .badge-event {
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* 📊 Delivery status badges */
        .badge-delivered { background-color: #059669 !important; }
        .badge-failed    { background-color: #dc2626 !important; }
        .badge-retrying  { background-color: #d97706 !important; }
        .badge-pending   { background-color: #2563eb !important; }

        /* 🔗 URL truncation */
        .url-cell {
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* 📋 Delivery log panel */
        .delivery-panel {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

        /* 📋 Expandable payload/response */
        .payload-preview {
            max-height: 200px;
            overflow-y: auto;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 0.8rem;
            background: #1e293b;
            color: #e2e8f0;
            padding: 0.75rem;
            border-radius: 6px;
            white-space: pre-wrap;
            word-break: break-all;
        }

        /* 🔘 Custom header rows */
        .header-row {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            align-items: center;
        }
        .header-row input {
            flex: 1;
        }

        /* ✅ Event checkbox grid */
        .event-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 0.25rem;
        }

        /* 🔐 Secret display */
        .secret-display {
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 0.85rem;
            background: #f1f5f9;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            word-break: break-all;
        }

        /* 📋 Toggle switch styling */
        .form-switch .form-check-input {
            cursor: pointer;
        }

        /* 🔄 Loading spinner */
        .spinner-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            z-index: 10;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4 px-md-5">

        <!-- ================================================================ -->
        <!-- 🎨 Page Header -->
        <!-- ================================================================ -->
        <div class="page-header mb-4">
            <div class="d-flex align-items-center justify-content-between flex-wrap">
                <div>
                    <h1 class="h3 mb-1">
                        <i class="fas fa-plug me-2"></i>Webhook Management
                    </h1>
                    <p class="mb-0 opacity-75">
                        Manage event subscriptions and monitor webhook deliveries
                    </p>
                </div>
                <a href="/admin/" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to Admin
                </a>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- 📊 Summary Cards (AJAX loaded) -->
        <!-- ================================================================ -->
        <div class="row g-3 mb-4" id="stats-row">
            <!-- 🔗 Total Webhooks -->
            <div class="col-sm-6 col-xl-3">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                            <i class="fas fa-plug"></i>
                        </div>
                        <div>
                            <div class="stat-value" id="stat-total">--</div>
                            <div class="text-muted small">Total Webhooks</div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- ✅ Active Webhooks -->
            <div class="col-sm-6 col-xl-3">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-success bg-opacity-10 text-success me-3">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value" id="stat-active">--</div>
                            <div class="text-muted small">Active</div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 📬 Deliveries Today -->
            <div class="col-sm-6 col-xl-3">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-info bg-opacity-10 text-info me-3">
                            <i class="fas fa-paper-plane"></i>
                        </div>
                        <div>
                            <div class="stat-value" id="stat-delivered">--</div>
                            <div class="text-muted small">Delivered Today</div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- ❌ Failed Today -->
            <div class="col-sm-6 col-xl-3">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-danger bg-opacity-10 text-danger me-3">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div>
                            <div class="stat-value" id="stat-failed">--</div>
                            <div class="text-muted small">Failed Today</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- 📋 Webhook List Card -->
        <!-- ================================================================ -->
        <div class="card mb-4" style="border-radius: 12px; border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3"
                 style="border-radius: 12px 12px 0 0;">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2 text-primary"></i>Webhook Subscriptions
                </h5>
                <button class="btn btn-primary btn-sm" onclick="showCreateModal()">
                    <i class="fas fa-plus me-1"></i>Create Webhook
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-webhooks mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3">Name</th>
                                <th>URL</th>
                                <th>Events</th>
                                <th class="text-center">Status</th>
                                <th>Last Triggered</th>
                                <th class="text-center">Failures</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="webhooks-table-body">
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="fas fa-spinner fa-spin me-2"></i>Loading webhooks...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- 📋 Delivery Log Panel (shown when viewing a webhook) -->
        <!-- ================================================================ -->
        <div class="card mb-4 d-none" id="delivery-panel"
             style="border-radius: 12px; border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3"
                 style="border-radius: 12px 12px 0 0;">
                <h5 class="mb-0">
                    <i class="fas fa-history me-2 text-info"></i>Delivery Log:
                    <span id="delivery-webhook-name" class="text-primary"></span>
                </h5>
                <button class="btn btn-outline-secondary btn-sm" onclick="hideDeliveryPanel()">
                    <i class="fas fa-times me-1"></i>Close
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3">Event</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Response</th>
                                <th class="text-center">Duration</th>
                                <th class="text-center">Attempts</th>
                                <th>Delivered At</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="deliveries-table-body">
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    Select a webhook to view its delivery log
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div><!-- /container-fluid -->

    <!-- ================================================================ -->
    <!-- 📝 Create / Edit Webhook Modal -->
    <!-- ================================================================ -->
    <div class="modal fade" id="webhookModal" tabindex="-1" aria-labelledby="webhookModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius: 12px;">
                <div class="modal-header">
                    <h5 class="modal-title" id="webhookModalLabel">
                        <i class="fas fa-plug me-2"></i>Create Webhook
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- 🔑 Hidden webhook ID for edits -->
                    <input type="hidden" id="modal-webhookID" value="">

                    <!-- 📝 Name -->
                    <div class="mb-3">
                        <label for="modal-name" class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="modal-name" maxlength="100"
                               placeholder="e.g. Production Event Handler">
                    </div>

                    <!-- 🌐 URL -->
                    <div class="mb-3">
                        <label for="modal-url" class="form-label fw-semibold">Endpoint URL <span class="text-danger">*</span></label>
                        <input type="url" class="form-control" id="modal-url" maxlength="500"
                               placeholder="https://example.com/webhook">
                        <div class="form-text">Must use HTTPS when the require_https setting is enabled.</div>
                    </div>

                    <!-- 📋 Event Selection -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Events <span class="text-danger">*</span></label>
                        <div class="mb-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm me-1" onclick="selectAllEvents()">
                                Select All
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="deselectAllEvents()">
                                Deselect All
                            </button>
                        </div>
                        <div id="event-checkboxes">
                            <!-- 🔄 Populated dynamically by loadEvents() -->
                        </div>
                    </div>

                    <!-- 📋 Custom Headers -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Custom Headers</label>
                        <div id="custom-headers-container">
                            <!-- 🔄 Dynamic header rows added here -->
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-1" onclick="addHeaderRow()">
                            <i class="fas fa-plus me-1"></i>Add Header
                        </button>
                    </div>

                    <!-- ⚙️ Settings Row -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="modal-retryCount" class="form-label fw-semibold">Retry Count</label>
                            <input type="number" class="form-control" id="modal-retryCount"
                                   min="0" max="10" value="3">
                            <div class="form-text">Maximum delivery retry attempts (0-10).</div>
                        </div>
                        <div class="col-md-6">
                            <label for="modal-timeout" class="form-label fw-semibold">Timeout (seconds)</label>
                            <input type="number" class="form-control" id="modal-timeout"
                                   min="1" max="60" value="10">
                            <div class="form-text">HTTP request timeout (1-60 seconds).</div>
                        </div>
                    </div>

                    <!-- 🔐 Secret (only shown on edit) -->
                    <div class="mb-3 d-none" id="secret-section">
                        <label class="form-label fw-semibold">HMAC Signing Secret</label>
                        <div class="d-flex align-items-start gap-2">
                            <div class="secret-display flex-grow-1" id="modal-secret">
                                <!-- 🔒 Decrypted secret shown here -->
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="copySecret()"
                                    title="Copy secret to clipboard">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                        <div class="form-text text-warning">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            Keep this secret secure. It is used to verify webhook signatures.
                        </div>
                    </div>

                    <!-- 🔘 Active Toggle -->
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="modal-isActive" checked>
                        <label class="form-check-label" for="modal-isActive">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="modal-save-btn" onclick="saveWebhook()">
                        <i class="fas fa-save me-1"></i>Create Webhook
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================ -->
    <!-- 📦 Delivery Detail Modal -->
    <!-- ================================================================ -->
    <div class="modal fade" id="deliveryDetailModal" tabindex="-1" aria-labelledby="deliveryDetailLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius: 12px;">
                <div class="modal-header">
                    <h5 class="modal-title" id="deliveryDetailLabel">
                        <i class="fas fa-paper-plane me-2"></i>Delivery Detail
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Delivery UUID:</strong>
                            <div class="text-muted small" id="detail-uuid">--</div>
                        </div>
                        <div class="col-md-3">
                            <strong>Status:</strong>
                            <div id="detail-status">--</div>
                        </div>
                        <div class="col-md-3">
                            <strong>Attempts:</strong>
                            <div id="detail-attempts">--</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <strong>Payload:</strong>
                        <div class="payload-preview mt-1" id="detail-payload">--</div>
                    </div>
                    <div class="mb-3">
                        <strong>Response Body:</strong>
                        <div class="payload-preview mt-1" id="detail-response">--</div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <strong>Response Code:</strong>
                            <div id="detail-code">--</div>
                        </div>
                        <div class="col-md-4">
                            <strong>Duration:</strong>
                            <div id="detail-duration">--</div>
                        </div>
                        <div class="col-md-4">
                            <strong>Error:</strong>
                            <div id="detail-error" class="text-danger">--</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================ -->
    <!-- 📦 JavaScript Dependencies -->
    <!-- ================================================================ -->

    <!-- Bootstrap 5.3.2 JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

    <script>
    /**
     * ============================================================================
     * 🔗 Webhook Management JavaScript
     * ============================================================================
     *
     * Client-side logic for the webhook admin dashboard. All data operations
     * are performed via AJAX calls to /admin/api/webhook-actions.php.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API
     * ============================================================================
     */

    // 🔐 CSRF token for POST requests (generated server-side)
    const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

    // 🌐 API endpoint for webhook actions
    const API_URL = '/admin/api/webhook-actions.php';

    // 📋 Cached event types (loaded once)
    let cachedEvents = { events: [], grouped: {} };

    // 📋 Currently viewing delivery log for this webhook ID
    let currentDeliveryWebhookID = null;

    // ========================================================================
    // 🔧 UTILITY FUNCTIONS
    // ========================================================================

    /**
     * 🛡️ Escape HTML entities to prevent XSS
     *
     * @param {string} str Raw string to escape
     * @returns {string} HTML-safe string
     *
     * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) {
            return '';
        }
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    /**
     * 📡 Make a GET request to the webhook API
     *
     * @param {string} action Action name
     * @param {Object} params Additional query parameters
     * @returns {Promise<Object>} JSON response
     */
    async function apiGet(action, params = {}) {
        const url = new URL(API_URL, window.location.origin);
        url.searchParams.set('action', action);
        for (const [key, value] of Object.entries(params)) {
            url.searchParams.set(key, value);
        }

        const response = await fetch(url.toString());
        return response.json();
    }

    /**
     * 📡 Make a POST request to the webhook API
     *
     * @param {string} action Action name
     * @param {Object} data Request body data
     * @returns {Promise<Object>} JSON response
     */
    async function apiPost(action, data = {}) {
        data.action = action;
        data.csrf_token = CSRF_TOKEN;

        const response = await fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN
            },
            body: JSON.stringify(data)
        });

        return response.json();
    }

    /**
     * 🔔 Show a toast notification
     *
     * @param {string} message Message to display
     * @param {string} type Bootstrap alert type (success, danger, warning, info)
     */
    function showToast(message, type = 'info') {
        // 📦 Create toast container if it doesn't exist
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1080';
            document.body.appendChild(container);
        }

        // 📝 Create toast element
        const toastId = 'toast-' + Date.now();
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-bg-${type} border-0" role="alert">
                <div class="d-flex">
                    <div class="toast-body">${escapeHtml(message)}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;

        container.insertAdjacentHTML('beforeend', toastHtml);
        const toastEl = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastEl, { delay: 4000 });
        toast.show();

        // 🧹 Remove from DOM after hidden
        toastEl.addEventListener('hidden.bs.toast', function () {
            toastEl.remove();
        });
    }


    // ========================================================================
    // 📊 DASHBOARD — Load Stats and Webhook List
    // ========================================================================

    /**
     * 📊 Load webhook system statistics into summary cards
     */
    async function loadStats() {
        try {
            const data = await apiGet('get_stats');

            if (data.success) {
                document.getElementById('stat-total').textContent     = data.stats.totalWebhooks;
                document.getElementById('stat-active').textContent    = data.stats.activeWebhooks;
                document.getElementById('stat-delivered').textContent = data.stats.deliveredToday;
                document.getElementById('stat-failed').textContent    = data.stats.failedToday;
            }
        } catch (error) {
            console.error('Failed to load stats:', error);
        }
    }

    /**
     * 📋 Load webhook list into the table
     */
    async function loadWebhooks() {
        const tbody = document.getElementById('webhooks-table-body');

        try {
            const data = await apiGet('get_webhooks');

            if (!data.success || !data.webhooks || data.webhooks.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="fas fa-plug fa-2x mb-2 d-block opacity-50"></i>
                            No webhooks configured yet.
                            <br><button class="btn btn-primary btn-sm mt-2" onclick="showCreateModal()">
                                <i class="fas fa-plus me-1"></i>Create your first webhook
                            </button>
                        </td>
                    </tr>
                `;
                return;
            }

            let rows = '';
            for (const wh of data.webhooks) {
                const eventCount = Array.isArray(wh.events) ? wh.events.length : 0;
                const isActive   = parseInt(wh.isActive) === 1;
                const failures   = parseInt(wh.failureCount) || 0;
                const lastTriggered = wh.lastTriggeredAt || 'Never';

                rows += `
                    <tr>
                        <td class="ps-3 fw-semibold">${escapeHtml(wh.name)}</td>
                        <td class="url-cell" title="${escapeHtml(wh.url)}">
                            <code class="small">${escapeHtml(wh.url)}</code>
                        </td>
                        <td>
                            <span class="badge bg-primary badge-event">${eventCount} event${eventCount !== 1 ? 's' : ''}</span>
                        </td>
                        <td class="text-center">
                            <div class="form-check form-switch d-inline-block mb-0">
                                <input class="form-check-input" type="checkbox"
                                       ${isActive ? 'checked' : ''}
                                       onchange="toggleWebhook(${wh.webhookID})"
                                       title="${isActive ? 'Active — click to disable' : 'Inactive — click to enable'}">
                            </div>
                        </td>
                        <td class="small text-muted">${escapeHtml(lastTriggered)}</td>
                        <td class="text-center">
                            ${failures > 0
                                ? '<span class="badge bg-danger">' + failures + '</span>'
                                : '<span class="text-muted">0</span>'}
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" onclick="showEditModal(${wh.webhookID})"
                                        title="Edit webhook">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-outline-info" onclick="loadDeliveries(${wh.webhookID}, '${escapeHtml(wh.name)}')"
                                        title="View delivery log">
                                    <i class="fas fa-history"></i>
                                </button>
                                <button class="btn btn-outline-success" onclick="testWebhook(${wh.webhookID})"
                                        title="Send test delivery">
                                    <i class="fas fa-vial"></i>
                                </button>
                                <button class="btn btn-outline-danger" onclick="deleteWebhook(${wh.webhookID}, '${escapeHtml(wh.name)}')"
                                        title="Delete webhook">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }

            tbody.innerHTML = rows;
        } catch (error) {
            console.error('Failed to load webhooks:', error);
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-4 text-danger">
                        <i class="fas fa-exclamation-circle me-1"></i>Failed to load webhooks
                    </td>
                </tr>
            `;
        }
    }

    /**
     * 📋 Load available event types for checkboxes
     */
    async function loadEvents() {
        try {
            const data = await apiGet('get_events');

            if (data.success) {
                cachedEvents = data;
                renderEventCheckboxes();
            }
        } catch (error) {
            console.error('Failed to load events:', error);
        }
    }

    /**
     * 📋 Render event type checkboxes grouped by category
     */
    function renderEventCheckboxes(selectedEvents = []) {
        const container = document.getElementById('event-checkboxes');
        let html = '';

        // 📦 Category display names and icons
        const categoryMeta = {
            user:     { label: 'User Events',     icon: 'fas fa-user' },
            security: { label: 'Security Events',  icon: 'fas fa-shield-alt' },
            webhook:  { label: 'Webhook Events',   icon: 'fas fa-plug' }
        };

        for (const [category, events] of Object.entries(cachedEvents.grouped || {})) {
            const meta = categoryMeta[category] || { label: category, icon: 'fas fa-tag' };

            html += `
                <div class="mb-3">
                    <h6 class="text-muted small text-uppercase mb-2">
                        <i class="${meta.icon} me-1"></i>${escapeHtml(meta.label)}
                    </h6>
                    <div class="event-grid">
            `;

            for (const event of events) {
                const checked = selectedEvents.includes(event) ? 'checked' : '';
                const eventLabel = event.split('.').pop().replace(/_/g, ' ');

                html += `
                    <div class="form-check">
                        <input class="form-check-input event-checkbox" type="checkbox"
                               value="${escapeHtml(event)}" id="evt-${escapeHtml(event)}" ${checked}>
                        <label class="form-check-label small" for="evt-${escapeHtml(event)}">
                            ${escapeHtml(eventLabel)}
                        </label>
                    </div>
                `;
            }

            html += '</div></div>';
        }

        container.innerHTML = html;
    }


    // ========================================================================
    // 📝 MODAL — Create / Edit Webhook
    // ========================================================================

    /**
     * 📝 Show the create webhook modal
     */
    function showCreateModal() {
        // 🧹 Reset form fields
        document.getElementById('modal-webhookID').value = '';
        document.getElementById('modal-name').value = '';
        document.getElementById('modal-url').value = '';
        document.getElementById('modal-retryCount').value = '3';
        document.getElementById('modal-timeout').value = '10';
        document.getElementById('modal-isActive').checked = true;

        // 🔐 Hide secret section (only shown on edit)
        document.getElementById('secret-section').classList.add('d-none');

        // 📋 Reset event checkboxes (none selected)
        renderEventCheckboxes([]);

        // 🧹 Clear custom headers
        document.getElementById('custom-headers-container').innerHTML = '';

        // 📝 Update modal title and button text
        document.getElementById('webhookModalLabel').innerHTML =
            '<i class="fas fa-plus me-2"></i>Create Webhook';
        document.getElementById('modal-save-btn').innerHTML =
            '<i class="fas fa-save me-1"></i>Create Webhook';

        // 📦 Show modal
        const modal = new bootstrap.Modal(document.getElementById('webhookModal'));
        modal.show();
    }

    /**
     * ✏️ Show the edit webhook modal with pre-populated data
     *
     * @param {number} webhookID Webhook ID to edit
     */
    async function showEditModal(webhookID) {
        try {
            const data = await apiGet('get_webhook', { webhookID: webhookID });

            if (!data.success || !data.webhook) {
                showToast('Failed to load webhook details', 'danger');
                return;
            }

            const wh = data.webhook;

            // 📝 Populate form fields
            document.getElementById('modal-webhookID').value = wh.webhookID;
            document.getElementById('modal-name').value = wh.name || '';
            document.getElementById('modal-url').value = wh.url || '';
            document.getElementById('modal-retryCount').value = wh.retryCount || 3;
            document.getElementById('modal-timeout').value = wh.timeoutSeconds || 10;
            document.getElementById('modal-isActive').checked = parseInt(wh.isActive) === 1;

            // 🔐 Show secret section
            document.getElementById('secret-section').classList.remove('d-none');
            document.getElementById('modal-secret').textContent = wh.decryptedSecret || '(unavailable)';

            // 📋 Set event checkboxes
            renderEventCheckboxes(wh.events || []);

            // 📋 Populate custom headers
            const headersContainer = document.getElementById('custom-headers-container');
            headersContainer.innerHTML = '';
            if (wh.headers && typeof wh.headers === 'object') {
                for (const [key, value] of Object.entries(wh.headers)) {
                    addHeaderRow(key, value);
                }
            }

            // 📝 Update modal title and button text
            document.getElementById('webhookModalLabel').innerHTML =
                '<i class="fas fa-edit me-2"></i>Edit Webhook';
            document.getElementById('modal-save-btn').innerHTML =
                '<i class="fas fa-save me-1"></i>Update Webhook';

            // 📦 Show modal
            const modal = new bootstrap.Modal(document.getElementById('webhookModal'));
            modal.show();
        } catch (error) {
            console.error('Failed to load webhook for editing:', error);
            showToast('Failed to load webhook details', 'danger');
        }
    }

    /**
     * 💾 Save webhook (create or update)
     */
    async function saveWebhook() {
        const webhookID = document.getElementById('modal-webhookID').value;
        const isEdit    = webhookID !== '';

        // 📦 Collect form data
        const name           = document.getElementById('modal-name').value.trim();
        const url            = document.getElementById('modal-url').value.trim();
        const retryCount     = parseInt(document.getElementById('modal-retryCount').value) || 3;
        const timeoutSeconds = parseInt(document.getElementById('modal-timeout').value) || 10;
        const isActive       = document.getElementById('modal-isActive').checked;

        // 📋 Collect selected events
        const events = [];
        document.querySelectorAll('.event-checkbox:checked').forEach(function (cb) {
            events.push(cb.value);
        });

        // 📋 Collect custom headers
        const headers = {};
        document.querySelectorAll('.header-row').forEach(function (row) {
            const keyInput   = row.querySelector('.header-key');
            const valueInput = row.querySelector('.header-value');
            if (keyInput && valueInput && keyInput.value.trim() !== '') {
                headers[keyInput.value.trim()] = valueInput.value.trim();
            }
        });

        // ✅ Basic client-side validation
        if (name === '') {
            showToast('Webhook name is required', 'warning');
            return;
        }

        if (url === '') {
            showToast('Endpoint URL is required', 'warning');
            return;
        }

        if (events.length === 0) {
            showToast('At least one event must be selected', 'warning');
            return;
        }

        // 📡 Send request
        const action = isEdit ? 'update_webhook' : 'create_webhook';
        const payload = {
            name: name,
            url: url,
            events: events,
            headers: Object.keys(headers).length > 0 ? headers : null,
            retryCount: retryCount,
            timeoutSeconds: timeoutSeconds,
            isActive: isActive
        };

        if (isEdit) {
            payload.webhookID = parseInt(webhookID);
        }

        try {
            const data = await apiPost(action, payload);

            if (data.success) {
                // ✅ Close modal and refresh data
                bootstrap.Modal.getInstance(document.getElementById('webhookModal')).hide();

                // 🔐 Show secret for new webhooks
                if (!isEdit && data.secret) {
                    showToast('Webhook created! Secret: ' + data.secret, 'success');
                } else {
                    showToast(isEdit ? 'Webhook updated' : 'Webhook created', 'success');
                }

                loadWebhooks();
                loadStats();
            } else {
                showToast(data.message || data.error || 'Operation failed', 'danger');
            }
        } catch (error) {
            console.error('Failed to save webhook:', error);
            showToast('Failed to save webhook', 'danger');
        }
    }


    // ========================================================================
    // 📋 CUSTOM HEADER MANAGEMENT
    // ========================================================================

    /**
     * 📋 Add a custom header key-value row to the modal
     *
     * @param {string} key   Pre-filled header name (optional)
     * @param {string} value Pre-filled header value (optional)
     */
    function addHeaderRow(key = '', value = '') {
        const container = document.getElementById('custom-headers-container');
        const row       = document.createElement('div');
        row.className   = 'header-row';
        row.innerHTML   = `
            <input type="text" class="form-control form-control-sm header-key"
                   placeholder="Header-Name" value="${escapeHtml(key)}">
            <input type="text" class="form-control form-control-sm header-value"
                   placeholder="Header value" value="${escapeHtml(value)}">
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.parentElement.remove()"
                    title="Remove header">
                <i class="fas fa-times"></i>
            </button>
        `;
        container.appendChild(row);
    }


    // ========================================================================
    // 📋 EVENT CHECKBOX HELPERS
    // ========================================================================

    /**
     * ✅ Select all event checkboxes
     */
    function selectAllEvents() {
        document.querySelectorAll('.event-checkbox').forEach(function (cb) {
            cb.checked = true;
        });
    }

    /**
     * ⬜ Deselect all event checkboxes
     */
    function deselectAllEvents() {
        document.querySelectorAll('.event-checkbox').forEach(function (cb) {
            cb.checked = false;
        });
    }


    // ========================================================================
    // 🔧 WEBHOOK ACTIONS — Toggle, Test, Delete
    // ========================================================================

    /**
     * 🔘 Toggle a webhook's active status
     *
     * @param {number} webhookID Webhook ID
     */
    async function toggleWebhook(webhookID) {
        try {
            const data = await apiPost('toggle_webhook', { webhookID: webhookID });

            if (data.success) {
                showToast(data.message, 'success');
                loadStats();
            } else {
                showToast(data.error || 'Failed to toggle webhook', 'danger');
                loadWebhooks(); // 🔄 Revert checkbox state
            }
        } catch (error) {
            console.error('Failed to toggle webhook:', error);
            showToast('Failed to toggle webhook', 'danger');
            loadWebhooks();
        }
    }

    /**
     * 🧪 Send a test delivery to a webhook
     *
     * @param {number} webhookID Webhook ID
     */
    async function testWebhook(webhookID) {
        try {
            showToast('Sending test delivery...', 'info');
            const data = await apiPost('test_webhook', { webhookID: webhookID });

            if (data.success) {
                showToast('Test delivered successfully (HTTP ' + (data.responseCode || '?') + ')', 'success');
            } else {
                showToast('Test delivery failed: ' + (data.message || 'Unknown error'), 'danger');
            }

            // 🔄 Refresh delivery log if viewing this webhook
            if (currentDeliveryWebhookID === webhookID) {
                loadDeliveries(webhookID);
            }

            loadStats();
        } catch (error) {
            console.error('Failed to send test:', error);
            showToast('Failed to send test delivery', 'danger');
        }
    }

    /**
     * 🗑️ Delete a webhook after confirmation
     *
     * @param {number} webhookID Webhook ID
     * @param {string} name      Webhook name for confirmation dialog
     */
    async function deleteWebhook(webhookID, name) {
        if (!confirm('Delete webhook "' + name + '"?\n\nThis will also delete all delivery history. This action cannot be undone.')) {
            return;
        }

        try {
            const data = await apiPost('delete_webhook', { webhookID: webhookID });

            if (data.success) {
                showToast('Webhook deleted', 'success');

                // 🧹 Hide delivery panel if viewing this webhook
                if (currentDeliveryWebhookID === webhookID) {
                    hideDeliveryPanel();
                }

                loadWebhooks();
                loadStats();
            } else {
                showToast(data.error || 'Failed to delete webhook', 'danger');
            }
        } catch (error) {
            console.error('Failed to delete webhook:', error);
            showToast('Failed to delete webhook', 'danger');
        }
    }


    // ========================================================================
    // 📋 DELIVERY LOG — View and Retry
    // ========================================================================

    /**
     * 📋 Load and display delivery log for a webhook
     *
     * @param {number} webhookID Webhook ID
     * @param {string} name      Webhook name for panel header (optional)
     */
    async function loadDeliveries(webhookID, name = null) {
        currentDeliveryWebhookID = webhookID;

        // 📋 Show delivery panel
        const panel = document.getElementById('delivery-panel');
        panel.classList.remove('d-none');

        if (name) {
            document.getElementById('delivery-webhook-name').textContent = name;
        }

        const tbody = document.getElementById('deliveries-table-body');
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="text-center py-4 text-muted">
                    <i class="fas fa-spinner fa-spin me-2"></i>Loading deliveries...
                </td>
            </tr>
        `;

        // 🔄 Scroll to delivery panel
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

        try {
            const data = await apiGet('get_deliveries', {
                webhookID: webhookID,
                limit: 50,
                offset: 0
            });

            if (!data.success || !data.deliveries || data.deliveries.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                            No deliveries found for this webhook
                        </td>
                    </tr>
                `;
                return;
            }

            let rows = '';
            for (const del of data.deliveries) {
                const statusBadge = getStatusBadge(del.status);
                const duration    = del.duration ? del.duration + 's' : '--';
                const code        = del.responseCode || '--';
                const deliveredAt = del.deliveredAt || '--';

                rows += `
                    <tr>
                        <td class="ps-3">
                            <code class="small">${escapeHtml(del.event)}</code>
                        </td>
                        <td class="text-center">${statusBadge}</td>
                        <td class="text-center">
                            <code>${escapeHtml(String(code))}</code>
                        </td>
                        <td class="text-center small">${escapeHtml(duration)}</td>
                        <td class="text-center">${del.attempts}</td>
                        <td class="small text-muted">${escapeHtml(deliveredAt)}</td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-info btn-sm" onclick='showDeliveryDetail(${JSON.stringify(del).replace(/'/g, "\\'")})'
                                        title="View detail">
                                    <i class="fas fa-eye"></i>
                                </button>
                                ${del.status === 'failed' ? `
                                    <button class="btn btn-outline-warning btn-sm" onclick="retryDelivery(${del.deliveryID})"
                                            title="Retry delivery">
                                        <i class="fas fa-redo"></i>
                                    </button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            }

            tbody.innerHTML = rows;
        } catch (error) {
            console.error('Failed to load deliveries:', error);
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-4 text-danger">
                        <i class="fas fa-exclamation-circle me-1"></i>Failed to load delivery log
                    </td>
                </tr>
            `;
        }
    }

    /**
     * 📋 Get a status badge HTML string
     *
     * @param {string} status Delivery status (delivered, failed, retrying, pending)
     * @returns {string} HTML badge element
     */
    function getStatusBadge(status) {
        const classes = {
            delivered: 'badge-delivered',
            failed:    'badge-failed',
            retrying:  'badge-retrying',
            pending:   'badge-pending'
        };
        const badgeClass = classes[status] || 'bg-secondary';
        return `<span class="badge ${badgeClass}">${escapeHtml(status)}</span>`;
    }

    /**
     * 📋 Show delivery detail in a modal
     *
     * @param {Object} delivery Delivery record object
     */
    function showDeliveryDetail(delivery) {
        document.getElementById('detail-uuid').textContent     = delivery.deliveryUUID || '--';
        document.getElementById('detail-status').innerHTML     = getStatusBadge(delivery.status);
        document.getElementById('detail-attempts').textContent = delivery.attempts || '0';
        document.getElementById('detail-code').textContent     = delivery.responseCode || '--';
        document.getElementById('detail-duration').textContent = delivery.duration ? delivery.duration + 's' : '--';
        document.getElementById('detail-error').textContent    = delivery.errorMessage || 'None';

        // 📦 Format payload JSON for display
        const payloadStr = typeof delivery.payload === 'object'
            ? JSON.stringify(delivery.payload, null, 2)
            : delivery.payload || '--';
        document.getElementById('detail-payload').textContent = payloadStr;

        // 📦 Format response body
        document.getElementById('detail-response').textContent = delivery.responseBody || '(no response body)';

        // 📦 Show modal
        const modal = new bootstrap.Modal(document.getElementById('deliveryDetailModal'));
        modal.show();
    }

    /**
     * 🔄 Retry a failed delivery
     *
     * @param {number} deliveryID Delivery ID
     */
    async function retryDelivery(deliveryID) {
        try {
            showToast('Retrying delivery...', 'info');
            const data = await apiPost('retry_delivery', { deliveryID: deliveryID });

            if (data.success) {
                showToast('Delivery succeeded (HTTP ' + (data.responseCode || '?') + ')', 'success');
            } else {
                showToast('Retry failed: ' + (data.message || 'Unknown error'), 'danger');
            }

            // 🔄 Refresh delivery log
            if (currentDeliveryWebhookID) {
                loadDeliveries(currentDeliveryWebhookID);
            }
            loadStats();
        } catch (error) {
            console.error('Failed to retry delivery:', error);
            showToast('Failed to retry delivery', 'danger');
        }
    }

    /**
     * 🔐 Copy webhook secret to clipboard
     */
    function copySecret() {
        const secretText = document.getElementById('modal-secret').textContent;
        navigator.clipboard.writeText(secretText).then(function () {
            showToast('Secret copied to clipboard', 'success');
        }).catch(function () {
            // 🔧 Fallback for older browsers
            const tempInput = document.createElement('textarea');
            tempInput.value = secretText;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            showToast('Secret copied to clipboard', 'success');
        });
    }

    /**
     * ❌ Hide the delivery log panel
     */
    function hideDeliveryPanel() {
        document.getElementById('delivery-panel').classList.add('d-none');
        currentDeliveryWebhookID = null;
    }


    // ========================================================================
    // 🚀 INITIALISATION — Load data on page load
    // ========================================================================

    document.addEventListener('DOMContentLoaded', function () {
        // 📊 Load dashboard data in parallel
        loadStats();
        loadWebhooks();
        loadEvents();
    });
    </script>
</body>
</html>
