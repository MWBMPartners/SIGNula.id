<?php
/**
 * 🔔 Webhook Endpoint Management (Partner Admin)
 *
 * Allows partner admins to manage webhook endpoints for receiving event
 * notifications from SIGNula. Features include endpoint CRUD, secret
 * regeneration, test dispatches, and delivery log viewing.
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons — FontAwesome 6.4 Icons
 * @see https://webhooks.fyi/security/hmac — Webhook Security Best Practices
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.3.0-beta
 */

require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 🔌 Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🏢 Get user's partner memberships
$memberships = $accessControl->getPartnerMemberships();

if (empty($memberships)) {
    header('Location: /partners/register.php');
    exit;
}

// 🎯 Default to first partner or use selected
$selectedPartnerID = $_GET['partner'] ?? array_key_first($memberships);
$partner = $memberships[$selectedPartnerID] ?? reset($memberships);

// 🛡️ Verify admin access for this partner
if (!$accessControl->isPartnerAdmin($selectedPartnerID)) {
    http_response_code(403);
    die('Admin access required for this partner');
}

// 📊 Get partner details
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE partnerID = ?");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerDetails = $stmt->get_result()->fetch_assoc();

// 📊 Get webhook endpoint count
$countStmt = $db->prepare("SELECT COUNT(*) AS count FROM tblWebhookEndpoints WHERE partnerID = ?");
$countStmt->bind_param('i', $selectedPartnerID);
$countStmt->execute();
$endpointCount = $countStmt->get_result()->fetch_assoc()['count'];

// 📊 Active endpoint count
$activeStmt = $db->prepare("SELECT COUNT(*) AS count FROM tblWebhookEndpoints WHERE partnerID = ? AND isActive = 1");
$activeStmt->bind_param('i', $selectedPartnerID);
$activeStmt->execute();
$activeCount = $activeStmt->get_result()->fetch_assoc()['count'];

// 📊 Recent delivery stats (last 24h)
$deliveryStmt = $db->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN statusCode BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS successful,
        SUM(CASE WHEN statusCode NOT BETWEEN 200 AND 299 OR statusCode IS NULL THEN 1 ELSE 0 END) AS failed
     FROM tblWebhookDeliveries wd
     JOIN tblWebhookEndpoints we ON wd.endpointID = we.endpointID
     WHERE we.partnerID = ? AND wd.createdAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
);
$deliveryStmt->bind_param('i', $selectedPartnerID);
$deliveryStmt->execute();
$deliveryStats = $deliveryStmt->get_result()->fetch_assoc();

// 📋 Available webhook event types
$availableEvents = [
    'user.created', 'user.updated', 'user.deleted', 'user.login', 'user.logout',
    'subscription.created', 'subscription.updated', 'subscription.cancelled', 'subscription.expired',
    'payment.completed', 'payment.failed', 'payment.refunded',
    'mfa.enabled', 'mfa.disabled', 'oauth.linked', 'oauth.unlinked'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webhook Management - <?php echo htmlspecialchars($partnerDetails['partnerName'] ?? 'Partner'); ?> - SIGNula</title>

    <!-- 🎨 Bootstrap 5.3.2 (CDN with SRI) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- 🎯 FontAwesome 6.4.2 (CDN with SRI) -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <style>
        body { background-color: #f8f9fa; }

        /* 🎨 Page header — orange/amber theme for webhooks */
        .page-header {
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* 📊 Stat cards */
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
            text-align: center;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        }
        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-card .stat-label {
            color: #6c757d;
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }

        /* 📋 Table card */
        .table-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .table-header {
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
            padding: 1rem 1.5rem;
        }

        /* 🏷️ Status badges */
        .badge-active { background-color: #28a745; }
        .badge-inactive { background-color: #6c757d; }
        .badge-success { background-color: #28a745; }
        .badge-failed { background-color: #dc3545; }

        /* 🔑 Secret display */
        .secret-display {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 0.75rem;
            word-break: break-all;
        }

        /* 🔄 Loading overlay */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }

        /* 🎯 Event tag checkboxes */
        .event-tag {
            display: inline-block;
            margin: 0.25rem;
        }
        .event-tag input[type="checkbox"] {
            display: none;
        }
        .event-tag label {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            border: 1px solid #dee2e6;
            background: white;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.2s;
        }
        .event-tag input[type="checkbox"]:checked + label {
            background: #f5576c;
            color: white;
            border-color: #f5576c;
        }

        /* 📋 Delivery log styling */
        .delivery-log-row {
            border-left: 3px solid transparent;
            transition: border-color 0.2s;
        }
        .delivery-log-row.status-2xx { border-left-color: #28a745; }
        .delivery-log-row.status-4xx { border-left-color: #ffc107; }
        .delivery-log-row.status-5xx { border-left-color: #dc3545; }
        .delivery-log-row.status-fail { border-left-color: #dc3545; }

        /* 📱 Modal enhancements */
        .detail-section {
            border-left: 3px solid #f5576c;
            padding-left: 1rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">

        <!-- 🎨 PAGE HEADER -->
        <div class="page-header">
            <a href="../index.php?partner=<?php echo (int)$selectedPartnerID; ?>" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Partner Dashboard
            </a>
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">
                        <i class="fas fa-satellite-dish me-2"></i>Webhook Endpoints
                    </h1>
                    <p class="mb-0 opacity-75">
                        Manage webhook endpoints for <strong><?php echo htmlspecialchars($partnerDetails['partnerName'] ?? 'Partner'); ?></strong>
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <button class="btn btn-light btn-lg" onclick="showCreateEndpointModal()">
                        <i class="fas fa-plus me-1"></i>Add Endpoint
                    </button>
                </div>
            </div>
        </div>

        <!-- 💬 Alerts container -->
        <div id="alertContainer"></div>

        <!-- 📊 STAT CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-primary"><?php echo (int)$endpointCount; ?></div>
                    <div class="stat-label"><i class="fas fa-link me-1"></i>Total Endpoints</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-success"><?php echo (int)$activeCount; ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle me-1"></i>Active</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-success"><?php echo (int)($deliveryStats['successful'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-paper-plane me-1"></i>Delivered (24h)</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-danger"><?php echo (int)($deliveryStats['failed'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-exclamation-triangle me-1"></i>Failed (24h)</div>
                </div>
            </div>
        </div>

        <!-- 📋 ENDPOINTS LIST -->
        <div class="card table-card position-relative">
            <div class="table-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Configured Endpoints</h5>
                <span id="endpointCount" class="badge bg-light text-dark"><?php echo (int)$endpointCount; ?> endpoints</span>
            </div>
            <div id="endpointsLoading" class="loading-overlay" style="display: none;">
                <div class="spinner-border text-danger" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>URL</th>
                            <th>Events</th>
                            <th>Status</th>
                            <th>Success Rate</th>
                            <th>Last Delivery</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="endpointsTableBody">
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>Loading endpoints...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 📋 DELIVERY LOG (below endpoints table) -->
        <div class="card table-card mt-4 position-relative" id="deliveryLogSection" style="display: none;">
            <div class="table-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Deliveries — <span id="deliveryEndpointName"></span></h5>
                <button class="btn btn-sm btn-light" onclick="hideDeliveryLog()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="deliveryLoading" class="loading-overlay" style="display: none;">
                <div class="spinner-border text-danger" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Event</th>
                            <th>Status</th>
                            <th>Response Time</th>
                            <th>Attempt</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody id="deliveryTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📱 MODALS -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->

    <!-- ➕ Create Endpoint Modal -->
    <div class="modal fade" id="createEndpointModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f093fb, #f5576c); color: white;">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Webhook Endpoint</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Endpoint URL</label>
                        <input type="url" id="newEndpointURL" class="form-control" placeholder="https://your-app.com/webhooks/signula">
                        <small class="form-text text-muted">Must be a publicly accessible HTTPS URL</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description (optional)</label>
                        <input type="text" id="newEndpointDescription" class="form-control" placeholder="e.g. Production webhook handler">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Events to Subscribe</label>
                        <div class="border rounded p-3">
                            <div class="mb-2">
                                <button class="btn btn-sm btn-outline-secondary me-1" onclick="toggleAllEvents(true)">Select All</button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="toggleAllEvents(false)">Deselect All</button>
                            </div>
                            <?php foreach ($availableEvents as $event): ?>
                                <span class="event-tag">
                                    <input type="checkbox" id="event-<?php echo htmlspecialchars($event); ?>" value="<?php echo htmlspecialchars($event); ?>" class="event-checkbox">
                                    <label for="event-<?php echo htmlspecialchars($event); ?>"><?php echo htmlspecialchars($event); ?></label>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="createEndpoint()">
                        <i class="fas fa-plus me-1"></i>Create Endpoint
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ✏️ Edit Endpoint Modal -->
    <div class="modal fade" id="editEndpointModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f093fb, #f5576c); color: white;">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Webhook Endpoint</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editEndpointID">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Endpoint URL</label>
                        <input type="url" id="editEndpointURL" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <input type="text" id="editEndpointDescription" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Events</label>
                        <div class="border rounded p-3" id="editEventsContainer">
                            <div class="mb-2">
                                <button class="btn btn-sm btn-outline-secondary me-1" onclick="toggleAllEditEvents(true)">Select All</button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="toggleAllEditEvents(false)">Deselect All</button>
                            </div>
                            <?php foreach ($availableEvents as $event): ?>
                                <span class="event-tag">
                                    <input type="checkbox" id="edit-event-<?php echo htmlspecialchars($event); ?>" value="<?php echo htmlspecialchars($event); ?>" class="edit-event-checkbox">
                                    <label for="edit-event-<?php echo htmlspecialchars($event); ?>"><?php echo htmlspecialchars($event); ?></label>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input type="checkbox" id="editEndpointActive" class="form-check-input">
                            <label class="form-check-label fw-semibold" for="editEndpointActive">Endpoint Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="saveEndpoint()">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 🔑 Secret Modal -->
    <div class="modal fade" id="secretModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-key me-2"></i>Webhook Signing Secret</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>Important:</strong> This secret is only shown once. Copy it now and store it securely.
                    </div>
                    <div class="secret-display" id="secretDisplay"></div>
                    <button class="btn btn-outline-primary btn-sm mt-2" onclick="copySecret()">
                        <i class="fas fa-copy me-1"></i>Copy to Clipboard
                    </button>
                    <hr>
                    <h6>How to verify webhook signatures:</h6>
                    <pre class="bg-light p-3 rounded small"><code>// PHP Example
$timestamp = $_SERVER['HTTP_X_SIGNULA_TIMESTAMP'];
$signature = $_SERVER['HTTP_X_SIGNULA_SIGNATURE'];
$payload = file_get_contents('php://input');

$expected = 'sha256=' . hash_hmac(
    'sha256',
    $timestamp . '.' . $payload,
    $secret // Your webhook signing secret
);

if (hash_equals($expected, $signature)) {
    // ✅ Signature valid — process webhook
}</code></pre>
                </div>
            </div>
        </div>
    </div>

    <!-- 🔐 Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- 🔐 CSRF Token & Partner ID -->
    <script>
        const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';
        const partnerID = <?php echo (int)$selectedPartnerID; ?>;
    </script>

    <script>
    /**
     * 🔔 Webhook Endpoint Management
     *
     * Client-side logic for AJAX-based webhook endpoint management,
     * including CRUD operations, secret management, and delivery log viewing.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API — Fetch API
     */

    // ═══════════════════════════════════════════════════════════════
    // 🛠️ UTILITY FUNCTIONS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔒 Escape HTML entities to prevent XSS
     */
    function escapeHtml(text) {
        if (!text) { return ''; }
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * 💬 Show alert notification
     */
    function showAlert(message, type = 'info') {
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.getElementById('alertContainer').appendChild(alert);
        setTimeout(() => {
            if (alert.parentNode) { alert.remove(); }
        }, 5000);
    }

    /**
     * 📅 Format date for display
     */
    function formatDate(dateStr) {
        if (!dateStr) { return '—'; }
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    /**
     * 📡 Make API call to webhook-actions.php
     * @param {string} action - API action name
     * @param {Object} data - Request payload (for POST) or query params (for GET)
     * @param {string} method - HTTP method (GET or POST)
     * @returns {Object} API response JSON
     */
    async function apiCall(action, data = {}, method = 'POST') {
        if (method === 'GET') {
            const params = new URLSearchParams({ action: action, partnerID: partnerID, ...data });
            const response = await fetch(`../api/webhook-actions.php?${params}`);
            return await response.json();
        } else {
            const response = await fetch('../api/webhook-actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: action, partnerID: partnerID, csrf_token: csrfToken, ...data })
            });
            return await response.json();
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 📋 ENDPOINT LIST
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Load all endpoints from API
     */
    async function loadEndpoints() {
        document.getElementById('endpointsLoading').style.display = 'flex';

        try {
            const result = await apiCall('list', {}, 'GET');

            if (result.success) {
                renderEndpoints(result.data);
                document.getElementById('endpointCount').textContent = (result.data || []).length + ' endpoints';
            } else {
                showAlert(result.message || 'Failed to load endpoints', 'danger');
            }
        } catch (error) {
            showAlert('Error loading endpoints: ' + error.message, 'danger');
        } finally {
            document.getElementById('endpointsLoading').style.display = 'none';
        }
    }

    /**
     * 📋 Render endpoint rows
     */
    function renderEndpoints(endpoints) {
        const tbody = document.getElementById('endpointsTableBody');

        if (!endpoints || endpoints.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                        <i class="fas fa-satellite-dish fa-3x mb-3 d-block opacity-50"></i>
                        <h5>No Webhook Endpoints</h5>
                        <p>Click "Add Endpoint" to create your first webhook endpoint.</p>
                    </td>
                </tr>`;
            return;
        }

        tbody.innerHTML = endpoints.map(ep => {
            const events = ep.events ? JSON.parse(ep.events) : [];
            const eventsDisplay = events.length > 3
                ? events.slice(0, 3).map(e => `<span class="badge bg-light text-dark me-1">${escapeHtml(e)}</span>`).join('') + `<span class="badge bg-secondary">+${events.length - 3} more</span>`
                : events.map(e => `<span class="badge bg-light text-dark me-1">${escapeHtml(e)}</span>`).join('');

            const successRate = ep.totalDeliveries > 0
                ? Math.round((ep.successfulDeliveries / ep.totalDeliveries) * 100)
                : 0;
            const rateColor = successRate >= 95 ? 'success' : successRate >= 80 ? 'warning' : 'danger';

            return `
                <tr>
                    <td>
                        <code class="text-primary small">${escapeHtml(ep.url)}</code>
                        ${ep.description ? `<br><small class="text-muted">${escapeHtml(ep.description)}</small>` : ''}
                    </td>
                    <td>${eventsDisplay}</td>
                    <td>
                        <span class="badge badge-${ep.isActive == 1 ? 'active' : 'inactive'}">
                            ${ep.isActive == 1 ? 'Active' : 'Inactive'}
                        </span>
                    </td>
                    <td>
                        ${ep.totalDeliveries > 0 ? `
                            <div class="d-flex align-items-center">
                                <div class="progress flex-grow-1 me-2" style="height: 6px;">
                                    <div class="progress-bar bg-${rateColor}" style="width: ${successRate}%"></div>
                                </div>
                                <small class="text-${rateColor} fw-bold">${successRate}%</small>
                            </div>
                            <small class="text-muted">${ep.successfulDeliveries}/${ep.totalDeliveries}</small>
                        ` : '<span class="text-muted small">No deliveries</span>'}
                    </td>
                    <td><small>${formatDate(ep.lastDeliveryAt)}</small></td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="showEditEndpointModal(${ep.endpointID})" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-outline-warning" onclick="regenerateSecret(${ep.endpointID})" title="Regenerate Secret">
                                <i class="fas fa-key"></i>
                            </button>
                            <button class="btn btn-outline-info" onclick="testEndpoint(${ep.endpointID})" title="Send Test">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                            <button class="btn btn-outline-secondary" onclick="showDeliveryLog(${ep.endpointID}, '${escapeHtml(ep.url)}')" title="Delivery Log">
                                <i class="fas fa-history"></i>
                            </button>
                            <button class="btn btn-outline-danger" onclick="deleteEndpoint(${ep.endpointID})" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>`;
        }).join('');
    }

    // ═══════════════════════════════════════════════════════════════
    // ➕ CREATE ENDPOINT
    // ═══════════════════════════════════════════════════════════════

    /**
     * ➕ Show the create endpoint modal
     */
    function showCreateEndpointModal() {
        document.getElementById('newEndpointURL').value = '';
        document.getElementById('newEndpointDescription').value = '';
        // 🔄 Reset all event checkboxes
        document.querySelectorAll('.event-checkbox').forEach(cb => { cb.checked = false; });
        new bootstrap.Modal(document.getElementById('createEndpointModal')).show();
    }

    /**
     * ✅ Toggle all event checkboxes
     */
    function toggleAllEvents(checked) {
        document.querySelectorAll('.event-checkbox').forEach(cb => { cb.checked = checked; });
    }

    function toggleAllEditEvents(checked) {
        document.querySelectorAll('.edit-event-checkbox').forEach(cb => { cb.checked = checked; });
    }

    /**
     * ➕ Create a new endpoint via API
     */
    async function createEndpoint() {
        const url = document.getElementById('newEndpointURL').value.trim();
        const description = document.getElementById('newEndpointDescription').value.trim();

        // 🔍 Validate URL
        if (!url) {
            showAlert('Please enter an endpoint URL', 'warning');
            return;
        }
        if (!url.startsWith('https://')) {
            showAlert('Endpoint URL must use HTTPS', 'warning');
            return;
        }

        // 📋 Gather selected events
        const events = [];
        document.querySelectorAll('.event-checkbox:checked').forEach(cb => {
            events.push(cb.value);
        });
        if (events.length === 0) {
            showAlert('Please select at least one event to subscribe to', 'warning');
            return;
        }

        try {
            const result = await apiCall('create', { url, description, events });

            if (result.success) {
                showAlert('<i class="fas fa-check-circle me-1"></i> Endpoint created successfully!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('createEndpointModal')).hide();
                loadEndpoints();

                // 🔑 Show the signing secret
                if (result.secret) {
                    document.getElementById('secretDisplay').textContent = result.secret;
                    new bootstrap.Modal(document.getElementById('secretModal')).show();
                }
            } else {
                showAlert(result.message || 'Failed to create endpoint', 'danger');
            }
        } catch (error) {
            showAlert('Error creating endpoint: ' + error.message, 'danger');
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ✏️ EDIT ENDPOINT
    // ═══════════════════════════════════════════════════════════════

    /**
     * ✏️ Show edit modal with endpoint data
     */
    async function showEditEndpointModal(endpointID) {
        try {
            const result = await apiCall('list', {}, 'GET');

            if (result.success) {
                const endpoint = (result.data || []).find(ep => ep.endpointID == endpointID);
                if (!endpoint) {
                    showAlert('Endpoint not found', 'danger');
                    return;
                }

                document.getElementById('editEndpointID').value = endpoint.endpointID;
                document.getElementById('editEndpointURL').value = endpoint.url;
                document.getElementById('editEndpointDescription').value = endpoint.description || '';
                document.getElementById('editEndpointActive').checked = endpoint.isActive == 1;

                // 🔄 Set event checkboxes
                const events = endpoint.events ? JSON.parse(endpoint.events) : [];
                document.querySelectorAll('.edit-event-checkbox').forEach(cb => {
                    cb.checked = events.includes(cb.value);
                });

                new bootstrap.Modal(document.getElementById('editEndpointModal')).show();
            }
        } catch (error) {
            showAlert('Error loading endpoint: ' + error.message, 'danger');
        }
    }

    /**
     * 💾 Save endpoint changes
     */
    async function saveEndpoint() {
        const endpointID = document.getElementById('editEndpointID').value;
        const url = document.getElementById('editEndpointURL').value.trim();

        if (!url || !url.startsWith('https://')) {
            showAlert('Please enter a valid HTTPS URL', 'warning');
            return;
        }

        const events = [];
        document.querySelectorAll('.edit-event-checkbox:checked').forEach(cb => {
            events.push(cb.value);
        });

        try {
            const result = await apiCall('update', {
                endpointID: parseInt(endpointID),
                url: url,
                description: document.getElementById('editEndpointDescription').value.trim(),
                events: events,
                isActive: document.getElementById('editEndpointActive').checked ? 1 : 0
            });

            if (result.success) {
                showAlert('<i class="fas fa-check-circle me-1"></i> Endpoint updated successfully', 'success');
                bootstrap.Modal.getInstance(document.getElementById('editEndpointModal')).hide();
                loadEndpoints();
            } else {
                showAlert(result.message || 'Failed to update endpoint', 'danger');
            }
        } catch (error) {
            showAlert('Error saving endpoint: ' + error.message, 'danger');
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 🔑 SECRET MANAGEMENT
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔑 Regenerate the signing secret for an endpoint
     */
    async function regenerateSecret(endpointID) {
        if (!confirm('Are you sure you want to regenerate the signing secret? The current secret will stop working immediately.')) {
            return;
        }

        try {
            const result = await apiCall('regenerate_secret', { endpointID: endpointID });

            if (result.success && result.secret) {
                document.getElementById('secretDisplay').textContent = result.secret;
                new bootstrap.Modal(document.getElementById('secretModal')).show();
            } else {
                showAlert(result.message || 'Failed to regenerate secret', 'danger');
            }
        } catch (error) {
            showAlert('Error regenerating secret: ' + error.message, 'danger');
        }
    }

    /**
     * 📋 Copy secret to clipboard
     */
    function copySecret() {
        const secret = document.getElementById('secretDisplay').textContent;
        navigator.clipboard.writeText(secret).then(() => {
            showAlert('<i class="fas fa-check me-1"></i> Secret copied to clipboard!', 'success');
        }).catch(() => {
            // 🔄 Fallback for browsers without clipboard API
            const textArea = document.createElement('textarea');
            textArea.value = secret;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            showAlert('<i class="fas fa-check me-1"></i> Secret copied to clipboard!', 'success');
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // 🧪 TEST & DELETE
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🧪 Send a test webhook to an endpoint
     */
    async function testEndpoint(endpointID) {
        showAlert('<i class="fas fa-spinner fa-spin me-1"></i> Sending test webhook...', 'info');

        try {
            const result = await apiCall('test', { endpointID: endpointID });

            if (result.success) {
                showAlert('<i class="fas fa-check-circle me-1"></i> Test webhook sent! Check your endpoint logs.', 'success');
            } else {
                showAlert(result.message || 'Test webhook failed', 'danger');
            }
        } catch (error) {
            showAlert('Error sending test: ' + error.message, 'danger');
        }
    }

    /**
     * 🗑️ Delete an endpoint
     */
    async function deleteEndpoint(endpointID) {
        if (!confirm('Are you sure you want to delete this webhook endpoint? This action cannot be undone.')) {
            return;
        }

        try {
            const result = await apiCall('delete', { endpointID: endpointID });

            if (result.success) {
                showAlert('<i class="fas fa-check-circle me-1"></i> Endpoint deleted', 'success');
                loadEndpoints();
            } else {
                showAlert(result.message || 'Failed to delete endpoint', 'danger');
            }
        } catch (error) {
            showAlert('Error deleting endpoint: ' + error.message, 'danger');
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 📋 DELIVERY LOG
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Show delivery log for a specific endpoint
     */
    async function showDeliveryLog(endpointID, endpointURL) {
        document.getElementById('deliveryLogSection').style.display = 'block';
        document.getElementById('deliveryEndpointName').textContent = endpointURL;
        document.getElementById('deliveryLoading').style.display = 'flex';

        try {
            const result = await apiCall('deliveries', { endpointID: endpointID }, 'GET');

            if (result.success) {
                renderDeliveries(result.data);
            } else {
                showAlert(result.message || 'Failed to load deliveries', 'danger');
            }
        } catch (error) {
            showAlert('Error loading deliveries: ' + error.message, 'danger');
        } finally {
            document.getElementById('deliveryLoading').style.display = 'none';
        }

        // 📌 Scroll to delivery log
        document.getElementById('deliveryLogSection').scrollIntoView({ behavior: 'smooth' });
    }

    /**
     * 📋 Render delivery log rows
     */
    function renderDeliveries(deliveries) {
        const tbody = document.getElementById('deliveryTableBody');

        if (!deliveries || deliveries.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center py-4 text-muted">
                        <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                        No deliveries yet
                    </td>
                </tr>`;
            return;
        }

        tbody.innerHTML = deliveries.map(d => {
            const statusCode = d.statusCode || 0;
            let statusClass = 'status-fail';
            if (statusCode >= 200 && statusCode < 300) { statusClass = 'status-2xx'; }
            else if (statusCode >= 400 && statusCode < 500) { statusClass = 'status-4xx'; }
            else if (statusCode >= 500) { statusClass = 'status-5xx'; }

            const responseMs = d.responseTimeMs ? d.responseTimeMs + 'ms' : '—';

            return `
                <tr class="delivery-log-row ${statusClass}">
                    <td><span class="badge bg-light text-dark">${escapeHtml(d.eventType)}</span></td>
                    <td>
                        ${statusCode > 0
                            ? `<span class="badge bg-${statusCode >= 200 && statusCode < 300 ? 'success' : 'danger'}">${statusCode}</span>`
                            : '<span class="badge bg-danger">Failed</span>'
                        }
                    </td>
                    <td>${responseMs}</td>
                    <td>${d.attemptNumber || 1}${d.nextRetryAt ? ' <small class="text-muted">(retry scheduled)</small>' : ''}</td>
                    <td><small>${formatDate(d.createdAt)}</small></td>
                </tr>`;
        }).join('');
    }

    /**
     * ❌ Hide delivery log section
     */
    function hideDeliveryLog() {
        document.getElementById('deliveryLogSection').style.display = 'none';
    }

    // ═══════════════════════════════════════════════════════════════
    // 🚀 INITIAL LOAD
    // ═══════════════════════════════════════════════════════════════

    document.addEventListener('DOMContentLoaded', function() {
        loadEndpoints();
    });
    </script>
</body>
</html>
