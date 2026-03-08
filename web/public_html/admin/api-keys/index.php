<?php
/**
 * 🔑 API Key Management Dashboard (Super Admin)
 *
 * Comprehensive admin interface for managing partner API keys.
 * Features summary statistics, searchable/filterable key listing,
 * key creation with environment selection, revocation, rotation,
 * and detailed usage statistics per key.
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons — FontAwesome 6.4 Icons
 * @see https://datatables.net/manual/ — DataTables Documentation
 * @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Prevention
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.6.0-beta
 */

// ═══════════════════════════════════════════════════════════════════════════════
// 🔌 BACKEND INCLUDES — Load core configuration, database, session & access
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 📂 Load the main application configuration.
 * dirname(__DIR__, 2) navigates from /web/public_html/admin/api-keys/ up to /web/
 * @see https://www.php.net/manual/en/function.dirname.php — PHP dirname()
 */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

/**
 * 🗄️ Database singleton — provides MySQLi prepared statement wrappers.
 * @see /_backend/Database.php — Database class documentation
 */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

/**
 * 🔑 Session management — handles login state and session tokens.
 * @see /_backend/SessionManager.php — SessionManager class documentation
 */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

/**
 * 🛡️ Access control — role-based permission checks.
 * @see /_backend/AccessControl.php — AccessControl class documentation
 */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

/**
 * 📝 ActivityLogger — logs all admin actions for audit trail.
 * @see /private_html/utils/ActivityLogger.php — ActivityLogger class documentation
 */
$activityLoggerPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'ActivityLogger.php';
if (file_exists($activityLoggerPath)) {
    require_once $activityLoggerPath;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🔌 INITIALISE CORE SERVICES
// ═══════════════════════════════════════════════════════════════════════════════

/** @var Database $db — Database singleton instance for all queries */
$db = Database::getInstance();

/** @var SessionManager $sessionManager — Manages user session state */
$sessionManager = new SessionManager($db);

/** @var AccessControl $accessControl — Enforces role-based access control */
$accessControl = new AccessControl($db, $sessionManager);

// ═══════════════════════════════════════════════════════════════════════════════
// 🔒 AUTHENTICATION CHECK — Redirect unauthenticated users to login
// ═══════════════════════════════════════════════════════════════════════════════

if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🛡️ SUPER ADMIN CHECK — Only super admins can manage API keys
// ═══════════════════════════════════════════════════════════════════════════════

$accessControl->requireSuperAdmin();

// ═══════════════════════════════════════════════════════════════════════════════
// 📊 SERVER-SIDE STATISTICS — Quick counts for summary cards
// @see https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 🔑 Fetch API key summary statistics from tblAPIKeys.
 * Used to populate the four summary cards at the top of the page.
 * - Total keys: all keys ever created
 * - Active keys: currently active (isActive = 1)
 * - Test keys: active keys with environment = 'test'
 * - Live keys: active keys with environment = 'live'
 */
$statsStmt = $db->prepare("
    SELECT
        COUNT(*) AS totalKeys,
        SUM(CASE WHEN isActive = 1 THEN 1 ELSE 0 END) AS activeKeys,
        SUM(CASE WHEN isActive = 1 AND environment = 'test' THEN 1 ELSE 0 END) AS testKeys,
        SUM(CASE WHEN isActive = 1 AND environment = 'live' THEN 1 ELSE 0 END) AS liveKeys
    FROM tblAPIKeys
");
$statsStmt->execute();
$keyStats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

/**
 * 🏢 Fetch active partners list for the "Create Key" modal dropdown.
 * Only active partners can have new API keys generated for them.
 */
$partnersStmt = $db->prepare("
    SELECT partnerID, partnerName, accountTier
    FROM tblPartners
    WHERE isActive = 1
    ORDER BY partnerName ASC
");
$partnersStmt->execute();
$partnersResult = $partnersStmt->get_result();
$partners = [];
while ($row = $partnersResult->fetch_assoc()) {
    $partners[] = $row;
}
$partnersStmt->close();

/**
 * 🔐 Generate a CSRF token for all POST requests from this page.
 * @see SecurityUtils::generateCSRFToken() — Token generation with session binding
 * @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Reference
 */
$csrfTokenValue = SecurityUtils::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Key Management - SIGNula Admin</title>

    <!-- 🎨 Bootstrap 5.3.2 (CDN with SRI) -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/download/ -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- 🎯 FontAwesome 6.4.2 (CDN with SRI) -->
    <!-- @see https://fontawesome.com/docs/web/setup/get-started -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <!-- 📊 DataTables 1.13.7 (CDN) — Enhanced table with search, sort, pagination -->
    <!-- @see https://datatables.net/manual/installation -->
    <link rel="stylesheet"
          href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">

    <style>
        /* ═══════════════════════════════════════════════════════════════
         * 🎨 BASE STYLES
         * ═══════════════════════════════════════════════════════════════ */
        body {
            background-color: #f8f9fa;
        }

        /* 🔑 Page header — blue/purple gradient theme for API keys */
        .page-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        /* 📊 Summary stat cards */
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s, box-shadow 0.2s;
            text-align: center;
            cursor: default;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
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
        .stat-card .stat-icon {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        /* 📋 Table card container */
        .table-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        .table-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 1rem 1.5rem;
        }

        /* ═══════════════════════════════════════════════════════════════
         * 🔑 API KEY DISPLAY STYLES
         * ═══════════════════════════════════════════════════════════════ */

        /* 🔑 Key prefix display — monospace with coloured background */
        .key-prefix {
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
            letter-spacing: 0.5px;
        }
        .key-prefix-test {
            background-color: #e3f2fd;
            color: #1565c0;
            border: 1px solid #90caf9;
        }
        .key-prefix-live {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #a5d6a7;
        }

        /* 🔑 Full raw key display — shown once after creation/rotation */
        .raw-key-display {
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.9rem;
            font-weight: 600;
            padding: 1rem;
            border-radius: 8px;
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            color: #1b5e20;
            border: 2px solid #66bb6a;
            word-break: break-all;
            letter-spacing: 0.5px;
            position: relative;
        }

        /* ═══════════════════════════════════════════════════════════════
         * 🏷️ STATUS BADGES
         * ═══════════════════════════════════════════════════════════════ */
        .badge-active {
            background-color: #28a745;
        }
        .badge-revoked {
            background-color: #dc3545;
        }
        .badge-expired {
            background-color: #6c757d;
        }
        .badge-test {
            background-color: #17a2b8;
        }
        .badge-live {
            background-color: #28a745;
        }

        /* ═══════════════════════════════════════════════════════════════
         * 🔄 LOADING & TRANSITIONS
         * ═══════════════════════════════════════════════════════════════ */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }

        /* 📋 Toast notification for clipboard copy */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        /* 🔐 Permission badges in the table */
        .permission-badge {
            font-size: 0.7rem;
            margin: 1px;
        }

        /* ⚠️ Warning box for key display */
        .key-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-top: 1rem;
            font-size: 0.85rem;
        }
        .key-warning i {
            color: #856404;
        }
    </style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════════════════
     🔐 CSRF TOKEN — Injected into JavaScript for use in AJAX POST requests
     @see https://owasp.org/www-community/attacks/csrf
     ═══════════════════════════════════════════════════════════════════════════ -->
<script>const csrfToken = '<?php echo htmlspecialchars($csrfTokenValue, ENT_QUOTES, 'UTF-8'); ?>';</script>

<div class="container-fluid py-4 px-4">

    <!-- ═══════════════════════════════════════════════════════════════════════
         📰 PAGE HEADER — Title, description, and Create Key button
         ═══════════════════════════════════════════════════════════════════════ -->
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h2 class="mb-1"><i class="fas fa-key me-2"></i>API Key Management</h2>
            <p class="mb-0 opacity-75">Manage partner API keys, permissions, and access controls</p>
        </div>
        <div class="mt-2 mt-md-0">
            <!-- ✨ Create Key button — opens the creation modal -->
            <button class="btn btn-light btn-lg" data-bs-toggle="modal" data-bs-target="#createKeyModal">
                <i class="fas fa-plus-circle me-2"></i>Create New Key
            </button>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         📊 SUMMARY CARDS — Quick statistics overview
         Populated server-side from tblAPIKeys aggregate query above.
         ═══════════════════════════════════════════════════════════════════════ -->
    <div class="row g-3 mb-4">
        <!-- 🔑 Total Keys card -->
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-primary"><i class="fas fa-key"></i></div>
                <div class="stat-number text-primary" id="statTotalKeys">
                    <?php echo htmlspecialchars((string)($keyStats['totalKeys'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="stat-label">Total Keys</div>
            </div>
        </div>

        <!-- ✅ Active Keys card -->
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number text-success" id="statActiveKeys">
                    <?php echo htmlspecialchars((string)($keyStats['activeKeys'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="stat-label">Active Keys</div>
            </div>
        </div>

        <!-- 🧪 Test Keys card -->
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-info"><i class="fas fa-flask"></i></div>
                <div class="stat-number text-info" id="statTestKeys">
                    <?php echo htmlspecialchars((string)($keyStats['testKeys'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="stat-label">Test Keys</div>
            </div>
        </div>

        <!-- 🚀 Live Keys card -->
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-warning"><i class="fas fa-rocket"></i></div>
                <div class="stat-number text-warning" id="statLiveKeys">
                    <?php echo htmlspecialchars((string)($keyStats['liveKeys'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="stat-label">Live Keys</div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         📋 API KEYS TABLE — DataTable with search, sort, and pagination
         Populated via AJAX from api-key-actions.php?action=get_keys
         ═══════════════════════════════════════════════════════════════════════ -->
    <div class="table-card">
        <div class="table-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>API Keys</h5>
            <div>
                <!-- 🔘 Filter toggle buttons -->
                <div class="btn-group btn-group-sm" role="group" aria-label="Key status filter">
                    <button type="button" class="btn btn-outline-light active" id="filterAll"
                            onclick="filterKeys('all')">All</button>
                    <button type="button" class="btn btn-outline-light" id="filterActive"
                            onclick="filterKeys('active')">Active</button>
                    <button type="button" class="btn btn-outline-light" id="filterRevoked"
                            onclick="filterKeys('revoked')">Revoked</button>
                </div>
            </div>
        </div>

        <div class="p-3 position-relative">
            <!-- 🔄 Loading overlay — shown while AJAX requests are in progress -->
            <div class="loading-overlay d-none" id="tableLoadingOverlay">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading API keys...</p>
                </div>
            </div>

            <!-- 📊 DataTable container -->
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="apiKeysTable" style="width:100%">
                    <thead class="table-light">
                        <tr>
                            <th>Key Prefix</th>
                            <th>Partner</th>
                            <th>Name</th>
                            <th>Environment</th>
                            <th>Status</th>
                            <th>Last Used</th>
                            <th>Created</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="apiKeysTableBody">
                        <!-- 🔄 Populated dynamically via loadKeys() AJAX call -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /.container-fluid -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     ✨ CREATE KEY MODAL — Form for generating a new API key
     @see https://getbootstrap.com/docs/5.3/components/modal/
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="createKeyModal" tabindex="-1" aria-labelledby="createKeyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <!-- 📰 Modal header -->
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea, #764ba2); color: white;">
                <h5 class="modal-title" id="createKeyModalLabel">
                    <i class="fas fa-plus-circle me-2"></i>Create New API Key
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- 📝 Modal body — key creation form -->
            <div class="modal-body">
                <!-- ✨ Step 1: Key Configuration (shown before creation) -->
                <div id="createKeyForm">
                    <!-- 🏢 Partner selection dropdown -->
                    <div class="mb-3">
                        <label for="createPartnerID" class="form-label fw-bold">
                            <i class="fas fa-building me-1"></i>Partner <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="createPartnerID" required>
                            <option value="">-- Select Partner --</option>
                            <?php foreach ($partners as $partner): ?>
                                <option value="<?php echo htmlspecialchars((string)$partner['partnerID'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($partner['partnerName'], ENT_QUOTES, 'UTF-8'); ?>
                                    (<?php echo htmlspecialchars($partner['accountTier'], ENT_QUOTES, 'UTF-8'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 🏷️ Key name input -->
                    <div class="mb-3">
                        <label for="createKeyName" class="form-label fw-bold">
                            <i class="fas fa-tag me-1"></i>Key Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="createKeyName"
                               placeholder="e.g., Production API Key, Staging Key"
                               maxlength="255" required>
                        <div class="form-text">A descriptive name to identify this key's purpose.</div>
                    </div>

                    <!-- 🌍 Environment toggle -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-globe me-1"></i>Environment <span class="text-danger">*</span>
                        </label>
                        <div class="btn-group w-100" role="group" aria-label="Environment selection">
                            <input type="radio" class="btn-check" name="createEnvironment"
                                   id="envTest" value="test" checked autocomplete="off">
                            <label class="btn btn-outline-info" for="envTest">
                                <i class="fas fa-flask me-1"></i>Test
                            </label>

                            <input type="radio" class="btn-check" name="createEnvironment"
                                   id="envLive" value="live" autocomplete="off">
                            <label class="btn btn-outline-success" for="envLive">
                                <i class="fas fa-rocket me-1"></i>Live
                            </label>
                        </div>
                        <div class="form-text">Test keys use the prefix <code>sk_test_</code>, live keys use <code>sk_live_</code>.</div>
                    </div>

                    <!-- 🔐 Permissions checkboxes -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-shield-alt me-1"></i>Permissions
                        </label>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="permUsersRead" value="users:read">
                                    <label class="form-check-label" for="permUsersRead">Users: Read</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="permUsersWrite" value="users:write">
                                    <label class="form-check-label" for="permUsersWrite">Users: Write</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="permAuthRead" value="auth:read">
                                    <label class="form-check-label" for="permAuthRead">Auth: Read</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="permAuthWrite" value="auth:write">
                                    <label class="form-check-label" for="permAuthWrite">Auth: Write</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="permPaymentsRead" value="payments:read">
                                    <label class="form-check-label" for="permPaymentsRead">Payments: Read</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="permPaymentsWrite" value="payments:write">
                                    <label class="form-check-label" for="permPaymentsWrite">Payments: Write</label>
                                </div>
                            </div>
                        </div>
                        <div class="form-text">Select the API scopes this key should have access to.</div>
                    </div>

                    <!-- 🌐 IP Whitelist textarea -->
                    <div class="mb-3">
                        <label for="createIPWhitelist" class="form-label fw-bold">
                            <i class="fas fa-network-wired me-1"></i>IP Whitelist
                        </label>
                        <textarea class="form-control" id="createIPWhitelist" rows="3"
                                  placeholder="Enter IP addresses or CIDR ranges, one per line or comma-separated&#10;e.g., 203.0.113.0/24, 198.51.100.42"></textarea>
                        <div class="form-text">Leave empty to allow access from any IP address. Supports CIDR notation.</div>
                    </div>

                    <!-- ⏰ Expiry date picker -->
                    <div class="mb-3">
                        <label for="createExpiresAt" class="form-label fw-bold">
                            <i class="fas fa-calendar-alt me-1"></i>Expiry Date
                        </label>
                        <input type="date" class="form-control" id="createExpiresAt"
                               min="<?php echo htmlspecialchars(date('Y-m-d', strtotime('+1 day')), ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-text">Leave empty for a key that never expires (until manually revoked).</div>
                    </div>
                </div>

                <!-- ✨ Step 2: Key Created Result (shown after successful creation) -->
                <div id="createKeyResult" class="d-none">
                    <div class="text-center mb-3">
                        <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                        <h4 class="mt-2 text-success">API Key Created Successfully</h4>
                    </div>

                    <!-- 🔑 Raw key display — shown ONCE after creation -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-key me-1"></i>Your New API Key
                        </label>
                        <div class="raw-key-display" id="generatedKeyDisplay">
                            <!-- 🔑 Populated dynamically after creation -->
                        </div>
                        <button class="btn btn-outline-primary btn-sm mt-2" onclick="copyToClipboard(document.getElementById('generatedKeyDisplay').textContent.trim())">
                            <i class="fas fa-copy me-1"></i>Copy to Clipboard
                        </button>
                    </div>

                    <!-- ⚠️ Warning — key is shown only once -->
                    <div class="key-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Important:</strong> This is the only time this key will be displayed.
                        Copy it now and store it securely. If lost, you will need to rotate the key
                        to generate a new one.
                    </div>
                </div>
            </div>

            <!-- 📰 Modal footer — action buttons -->
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Close
                </button>
                <button type="button" class="btn btn-primary" id="btnCreateKey" onclick="createKey()">
                    <i class="fas fa-plus-circle me-1"></i>Generate Key
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     🔍 KEY DETAILS MODAL — Shows detailed info and usage stats for a key
     @see https://getbootstrap.com/docs/5.3/components/modal/
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="keyDetailsModal" tabindex="-1" aria-labelledby="keyDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <!-- 📰 Modal header -->
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea, #764ba2); color: white;">
                <h5 class="modal-title" id="keyDetailsModalLabel">
                    <i class="fas fa-info-circle me-2"></i>API Key Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- 📝 Modal body — populated dynamically via viewKeyDetails() -->
            <div class="modal-body" id="keyDetailsBody">
                <!-- 🔄 Loading spinner shown while fetching details -->
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading key details...</p>
                </div>
            </div>

            <!-- 📰 Modal footer -->
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     📋 TOAST CONTAINER — For clipboard copy notifications
     @see https://getbootstrap.com/docs/5.3/components/toasts/
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="toast-container">
    <div id="copyToast" class="toast align-items-center text-white bg-success border-0" role="alert"
         aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body">
                <i class="fas fa-check-circle me-2"></i>Copied to clipboard!
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     📦 JAVASCRIPT DEPENDENCIES — jQuery, Bootstrap JS, DataTables
     ═══════════════════════════════════════════════════════════════════════════ -->

<!-- jQuery 3.7 (required by DataTables) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
        crossorigin="anonymous"></script>

<!-- Bootstrap 5.3.2 JS Bundle (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>

<!-- DataTables 1.13.7 -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * 🔑 API KEY MANAGEMENT — Client-side JavaScript
 *
 * Handles all AJAX interactions with api-key-actions.php for:
 * - Loading and displaying API keys in a DataTable
 * - Creating new keys with the creation modal
 * - Revoking and rotating keys with confirmation
 * - Viewing key details and usage statistics
 * - Clipboard operations with toast notifications
 *
 * @see https://api.jquery.com/jQuery.ajax/ — jQuery AJAX Reference
 * @see https://datatables.net/reference/api/ — DataTables API Reference
 * ═══════════════════════════════════════════════════════════════════════════════
 */

/**
 * 🌐 API endpoint URL for all API key management AJAX calls.
 * Relative path from /admin/api-keys/ to /admin/api/api-key-actions.php
 */
const API_ENDPOINT = '/admin/api/api-key-actions.php';

/**
 * 📊 DataTable instance reference.
 * Initialised in $(document).ready() and used for filtering/refreshing.
 * @type {DataTable|null}
 */
let keysDataTable = null;

/**
 * 🔄 Current filter state for the keys table.
 * Values: 'all', 'active', 'revoked'
 * @type {string}
 */
let currentFilter = 'all';

// ═══════════════════════════════════════════════════════════════════════════════
// 🚀 INITIALISATION — Load keys and set up DataTable on page ready
// ═══════════════════════════════════════════════════════════════════════════════

$(document).ready(function() {
    /**
     * 📊 Initialise DataTable with Bootstrap 5 styling.
     * Column definitions match the table headers in the HTML.
     * @see https://datatables.net/reference/option/ — DataTables options
     */
    keysDataTable = $('#apiKeysTable').DataTable({
        // 📄 Pagination and display settings
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        order: [[6, 'desc']], // 📅 Default sort by Created date descending

        // 🔍 Search and filter settings
        searching: true,
        info: true,

        // 🎨 Bootstrap 5 responsive styling
        responsive: true,

        // 📋 Column definitions for proper sorting/rendering
        columnDefs: [
            { orderable: false, targets: [7] }, // 🚫 Disable sorting on Actions column
            { className: 'text-end', targets: [7] } // ➡️ Right-align Actions column
        ],

        // 🏷️ Language customisation
        language: {
            emptyTable: '<div class="text-center py-3"><i class="fas fa-key text-muted" style="font-size: 2rem;"></i><p class="mt-2 text-muted">No API keys found</p></div>',
            zeroRecords: '<div class="text-center py-3"><i class="fas fa-search text-muted" style="font-size: 2rem;"></i><p class="mt-2 text-muted">No matching keys found</p></div>'
        }
    });

    // 🔑 Load all API keys on page load
    loadKeys();

    /**
     * 🔄 Reset the Create Key modal when it's closed.
     * Returns the modal to Step 1 (form) and clears all inputs.
     * @see https://getbootstrap.com/docs/5.3/components/modal/#events
     */
    document.getElementById('createKeyModal').addEventListener('hidden.bs.modal', function () {
        resetCreateKeyModal();
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// 📡 LOAD KEYS — Fetch all API keys via AJAX GET
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 🔑 Load all API keys from the backend and populate the DataTable.
 *
 * Makes a GET request to api-key-actions.php?action=get_keys.
 * Clears the DataTable, builds rows from the response data,
 * adds them to the table, and re-draws.
 *
 * @see APIKeyManager — Backend key retrieval
 * @see https://datatables.net/reference/api/rows.add() — DataTables row addition
 */
function loadKeys() {
    // 🔄 Show loading overlay while fetching
    $('#tableLoadingOverlay').removeClass('d-none');

    $.ajax({
        url: API_ENDPOINT,
        method: 'GET',
        data: { action: 'get_keys' },
        dataType: 'json',
        /**
         * ✅ Success handler — populate the DataTable with key data.
         * @param {Object} response — JSON response from api-key-actions.php
         */
        success: function(response) {
            if (response.success && response.data) {
                // 🧹 Clear existing table data
                keysDataTable.clear();

                // 📋 Build rows from the response data
                response.data.forEach(function(key) {
                    keysDataTable.row.add([
                        // 🔑 Key Prefix — monospace with environment colour coding
                        buildKeyPrefixHTML(key),
                        // 🏢 Partner name
                        escapeHtml(key.partnerName || 'Unknown'),
                        // 🏷️ Key name
                        escapeHtml(key.keyName || ''),
                        // 🌍 Environment badge
                        buildEnvironmentBadge(key.environment),
                        // 📊 Status badge
                        buildStatusBadge(key),
                        // 📅 Last Used timestamp
                        key.lastUsedAt ? formatDate(key.lastUsedAt) : '<span class="text-muted">Never</span>',
                        // 📅 Created timestamp
                        formatDate(key.createdAt),
                        // 🔧 Action buttons
                        buildActionButtons(key)
                    ]);
                });

                // 🔄 Redraw the table and apply current filter
                keysDataTable.draw();
                applyFilter();
            } else {
                console.error('🔑 Failed to load API keys:', response.message);
            }
        },
        /**
         * ❌ Error handler — log the error and show a user-friendly message.
         * @param {Object} xhr — jQuery XHR object
         */
        error: function(xhr) {
            console.error('🔑 AJAX error loading API keys:', xhr.status, xhr.statusText);
        },
        /**
         * 🔄 Complete handler — hide loading overlay regardless of success/failure.
         */
        complete: function() {
            $('#tableLoadingOverlay').addClass('d-none');
        }
    });
}

// ═══════════════════════════════════════════════════════════════════════════════
// ✨ CREATE KEY — Generate a new API key via AJAX POST
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * ✨ Create a new API key with the form data from the Create Key modal.
 *
 * Validates required fields, collects all form data including permissions,
 * sends a POST request to api-key-actions.php with action: create_key,
 * and displays the generated raw key in the modal.
 *
 * ⚠️ The raw key is shown ONCE — it is SHA-256 hashed before storage
 * and cannot be retrieved again after this point.
 *
 * @see APIKeyManager::generateKey() — Backend key generation
 */
function createKey() {
    // 📥 Collect form values
    var partnerID = document.getElementById('createPartnerID').value;
    var keyName = document.getElementById('createKeyName').value.trim();
    var environment = document.querySelector('input[name="createEnvironment"]:checked').value;
    var ipWhitelist = document.getElementById('createIPWhitelist').value.trim();
    var expiresAt = document.getElementById('createExpiresAt').value;

    // ✅ Validate required fields
    if (!partnerID || partnerID === '') {
        alert('Please select a partner.');
        return;
    }
    if (!keyName) {
        alert('Please enter a key name.');
        return;
    }

    /**
     * 🔐 Collect selected permissions from checkboxes.
     * Each checkbox value is a permission scope string (e.g., 'users:read').
     */
    var permissions = [];
    document.querySelectorAll('#createKeyForm .form-check-input:checked').forEach(function(cb) {
        permissions.push(cb.value);
    });

    // 🔄 Disable the create button and show loading state
    var btnCreate = document.getElementById('btnCreateKey');
    btnCreate.disabled = true;
    btnCreate.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';

    // 📡 Send the create request via AJAX POST
    $.ajax({
        url: API_ENDPOINT,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            action: 'create_key',
            csrf_token: csrfToken,
            partnerID: parseInt(partnerID),
            keyName: keyName,
            environment: environment,
            permissions: permissions.length > 0 ? permissions : null,
            ipWhitelist: ipWhitelist || null,
            expiresAt: expiresAt || null
        }),
        dataType: 'json',
        /**
         * ✅ Success handler — display the raw key and switch to result view.
         * @param {Object} response — JSON response with rawKey and keyData
         */
        success: function(response) {
            if (response.success && response.data) {
                /**
                 * 🔑 Display the generated raw key.
                 * This is the ONLY time the full key is available.
                 * After this, only the keyPrefix (first 20 chars) is stored.
                 */
                document.getElementById('generatedKeyDisplay').textContent = response.data.rawKey;

                // 🔄 Switch from form view to result view
                document.getElementById('createKeyForm').classList.add('d-none');
                document.getElementById('createKeyResult').classList.remove('d-none');

                // 🔘 Hide the Generate button, keep only Close
                btnCreate.classList.add('d-none');

                // 🔄 Reload the keys table to reflect the new key
                loadKeys();
            } else {
                alert('Failed to create API key: ' + (response.message || 'Unknown error'));
            }
        },
        /**
         * ❌ Error handler — show error message from the server.
         * @param {Object} xhr — jQuery XHR object
         */
        error: function(xhr) {
            var errorMsg = 'An error occurred while creating the API key.';
            try {
                var errorResponse = JSON.parse(xhr.responseText);
                if (errorResponse.message) {
                    errorMsg = errorResponse.message;
                }
            } catch (e) {
                // 🔇 Ignore JSON parse errors — use default message
            }
            alert(errorMsg);
        },
        /**
         * 🔄 Complete handler — re-enable the create button.
         */
        complete: function() {
            btnCreate.disabled = false;
            btnCreate.innerHTML = '<i class="fas fa-plus-circle me-1"></i>Generate Key';
        }
    });
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🚫 REVOKE KEY — Revoke an API key via AJAX POST with confirmation
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 🚫 Revoke an API key after user confirmation.
 *
 * Displays a confirmation dialog, then sends a POST request to
 * api-key-actions.php with action: revoke_key. On success,
 * reloads the keys table to reflect the change.
 *
 * @param {number} keyID — The ID of the key to revoke
 * @see APIKeyManager::revokeKey() — Backend revocation
 */
function revokeKey(keyID) {
    // ⚠️ Confirm the destructive action with the user
    var reason = prompt(
        'Are you sure you want to revoke this API key?\n\n' +
        'This action cannot be undone. The key will immediately stop working.\n\n' +
        'Enter a reason for revocation (optional):'
    );

    // 🚫 User cancelled the prompt
    if (reason === null) {
        return;
    }

    // 📡 Send the revoke request via AJAX POST
    $.ajax({
        url: API_ENDPOINT,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            action: 'revoke_key',
            csrf_token: csrfToken,
            keyID: keyID,
            reason: reason || null
        }),
        dataType: 'json',
        /**
         * ✅ Success handler — reload keys and show confirmation.
         * @param {Object} response — JSON response
         */
        success: function(response) {
            if (response.success) {
                // 🔄 Reload the table to show updated status
                loadKeys();
                showToast('API key has been revoked successfully.', 'warning');
            } else {
                alert('Failed to revoke key: ' + (response.message || 'Unknown error'));
            }
        },
        /**
         * ❌ Error handler
         * @param {Object} xhr — jQuery XHR object
         */
        error: function(xhr) {
            alert('An error occurred while revoking the API key.');
        }
    });
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🔄 ROTATE KEY — Regenerate key value via AJAX POST with confirmation
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 🔄 Rotate (regenerate) an API key after user confirmation.
 *
 * Revokes the old key and generates a new one with the same configuration.
 * The new raw key is displayed in an alert — it is shown ONCE only.
 *
 * @param {number} keyID — The ID of the key to rotate
 * @see APIKeyManager::regenerateKey() — Backend rotation with config preservation
 */
function rotateKey(keyID) {
    // ⚠️ Confirm the action with the user
    if (!confirm(
        'Are you sure you want to rotate this API key?\n\n' +
        'The current key will be revoked and a new key will be generated ' +
        'with the same permissions and settings.\n\n' +
        'Any systems using the old key will need to be updated immediately.'
    )) {
        return;
    }

    // 📡 Send the rotate request via AJAX POST
    $.ajax({
        url: API_ENDPOINT,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            action: 'rotate_key',
            csrf_token: csrfToken,
            keyID: keyID
        }),
        dataType: 'json',
        /**
         * ✅ Success handler — display the new raw key and reload table.
         * @param {Object} response — JSON response with new rawKey
         */
        success: function(response) {
            if (response.success && response.data) {
                /**
                 * 🔑 Display the new key in the Create Key modal's result view.
                 * Reuse the same display mechanism as key creation.
                 */
                document.getElementById('generatedKeyDisplay').textContent = response.data.rawKey;
                document.getElementById('createKeyForm').classList.add('d-none');
                document.getElementById('createKeyResult').classList.remove('d-none');
                document.getElementById('btnCreateKey').classList.add('d-none');

                // 🔄 Update the modal title to reflect rotation
                document.getElementById('createKeyModalLabel').innerHTML =
                    '<i class="fas fa-sync-alt me-2"></i>API Key Rotated';

                // 📋 Show the modal with the new key
                var modal = new bootstrap.Modal(document.getElementById('createKeyModal'));
                modal.show();

                // 🔄 Reload the keys table
                loadKeys();
            } else {
                alert('Failed to rotate key: ' + (response.message || 'Unknown error'));
            }
        },
        /**
         * ❌ Error handler
         * @param {Object} xhr — jQuery XHR object
         */
        error: function(xhr) {
            alert('An error occurred while rotating the API key.');
        }
    });
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🔍 VIEW KEY DETAILS — Fetch and display detailed key info + usage stats
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 🔍 View detailed information and usage statistics for an API key.
 *
 * Opens the Key Details modal and populates it with data from
 * api-key-actions.php?action=get_key_details&keyID=...
 *
 * @param {number} keyID — The ID of the key to view
 * @see APIKeyManager::getKeyByID() — Key data retrieval
 * @see APIKeyManager::getUsageStats() — Usage statistics (30-day window)
 */
function viewKeyDetails(keyID) {
    // 📋 Show the details modal with loading spinner
    var modal = new bootstrap.Modal(document.getElementById('keyDetailsModal'));
    modal.show();

    // 🔄 Reset body to loading state
    document.getElementById('keyDetailsBody').innerHTML =
        '<div class="text-center py-4">' +
        '<div class="spinner-border text-primary" role="status">' +
        '<span class="visually-hidden">Loading...</span></div>' +
        '<p class="mt-2 text-muted">Loading key details...</p></div>';

    // 📡 Fetch key details via AJAX GET
    $.ajax({
        url: API_ENDPOINT,
        method: 'GET',
        data: { action: 'get_key_details', keyID: keyID },
        dataType: 'json',
        /**
         * ✅ Success handler — build and display the key details HTML.
         * @param {Object} response — JSON response with key and usageStats
         */
        success: function(response) {
            if (response.success && response.data) {
                var key = response.data.key;
                var stats = response.data.usageStats || {};
                var html = buildKeyDetailsHTML(key, stats);
                document.getElementById('keyDetailsBody').innerHTML = html;
            } else {
                document.getElementById('keyDetailsBody').innerHTML =
                    '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>' +
                    escapeHtml(response.message || 'Failed to load key details.') + '</div>';
            }
        },
        /**
         * ❌ Error handler
         * @param {Object} xhr — jQuery XHR object
         */
        error: function(xhr) {
            document.getElementById('keyDetailsBody').innerHTML =
                '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>' +
                'An error occurred while loading key details.</div>';
        }
    });
}

// ═══════════════════════════════════════════════════════════════════════════════
// 📋 CLIPBOARD OPERATIONS — Copy key to clipboard with toast notification
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 📋 Copy text to the clipboard and show a toast notification.
 *
 * Uses the modern Clipboard API (navigator.clipboard.writeText) with
 * a fallback to the deprecated document.execCommand('copy') for older browsers.
 *
 * @param {string} text — The text to copy to the clipboard
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Clipboard/writeText — Clipboard API
 */
function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        /**
         * 🔒 Modern Clipboard API — available in secure contexts (HTTPS).
         * Preferred method as it's non-blocking and promise-based.
         */
        navigator.clipboard.writeText(text).then(function() {
            showToast('Copied to clipboard!', 'success');
        }).catch(function(err) {
            console.error('📋 Clipboard write failed:', err);
            fallbackCopyToClipboard(text);
        });
    } else {
        /**
         * 📋 Fallback for non-secure contexts or older browsers.
         * Creates a temporary textarea element to enable document.execCommand('copy').
         */
        fallbackCopyToClipboard(text);
    }
}

/**
 * 📋 Fallback clipboard copy using a temporary textarea element.
 * Used when the modern Clipboard API is unavailable.
 *
 * @param {string} text — The text to copy
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Document/execCommand — execCommand (deprecated)
 */
function fallbackCopyToClipboard(text) {
    var textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-9999px';
    textArea.style.top = '-9999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
        var successful = document.execCommand('copy');
        if (successful) {
            showToast('Copied to clipboard!', 'success');
        } else {
            showToast('Failed to copy. Please copy manually.', 'danger');
        }
    } catch (err) {
        console.error('📋 Fallback copy failed:', err);
        showToast('Failed to copy. Please copy manually.', 'danger');
    }

    document.body.removeChild(textArea);
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🔧 HELPER FUNCTIONS — UI builders, formatters, and utilities
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 🔑 Build the key prefix HTML with environment colour coding.
 *
 * @param {Object} key — Key data object from the API
 * @returns {string} HTML string for the key prefix display
 */
function buildKeyPrefixHTML(key) {
    var envClass = key.environment === 'live' ? 'key-prefix-live' : 'key-prefix-test';
    return '<span class="key-prefix ' + envClass + '">' +
           escapeHtml(key.keyPrefix || 'sk_***') + '...</span>';
}

/**
 * 🌍 Build an environment badge (Test or Live).
 *
 * @param {string} environment — 'test' or 'live'
 * @returns {string} HTML string for the environment badge
 */
function buildEnvironmentBadge(environment) {
    if (environment === 'live') {
        return '<span class="badge badge-live"><i class="fas fa-rocket me-1"></i>Live</span>';
    }
    return '<span class="badge badge-test"><i class="fas fa-flask me-1"></i>Test</span>';
}

/**
 * 📊 Build a status badge based on key state.
 *
 * @param {Object} key — Key data object with isActive, revokedAt, expiresAt fields
 * @returns {string} HTML string for the status badge
 */
function buildStatusBadge(key) {
    if (!key.isActive || key.isActive == 0) {
        if (key.revokedAt) {
            return '<span class="badge badge-revoked"><i class="fas fa-ban me-1"></i>Revoked</span>';
        }
        return '<span class="badge badge-expired"><i class="fas fa-clock me-1"></i>Expired</span>';
    }

    // ⏰ Check if key has expired (even if isActive hasn't been updated yet)
    if (key.expiresAt && new Date(key.expiresAt) < new Date()) {
        return '<span class="badge badge-expired"><i class="fas fa-clock me-1"></i>Expired</span>';
    }

    return '<span class="badge badge-active"><i class="fas fa-check-circle me-1"></i>Active</span>';
}

/**
 * 🔧 Build action buttons for a key row in the DataTable.
 *
 * Active keys get: View Details, Rotate, Revoke buttons.
 * Inactive keys get: View Details button only.
 *
 * @param {Object} key — Key data object
 * @returns {string} HTML string for the action buttons
 */
function buildActionButtons(key) {
    var buttons = '';

    // 🔍 View Details button — always available
    buttons += '<button class="btn btn-sm btn-outline-primary me-1" ' +
               'onclick="viewKeyDetails(' + parseInt(key.keyID) + ')" ' +
               'title="View Details"><i class="fas fa-eye"></i></button>';

    // 🔧 Active-only buttons: Rotate and Revoke
    if (key.isActive == 1) {
        // 🔄 Rotate button
        buttons += '<button class="btn btn-sm btn-outline-warning me-1" ' +
                   'onclick="rotateKey(' + parseInt(key.keyID) + ')" ' +
                   'title="Rotate Key"><i class="fas fa-sync-alt"></i></button>';

        // 🚫 Revoke button
        buttons += '<button class="btn btn-sm btn-outline-danger" ' +
                   'onclick="revokeKey(' + parseInt(key.keyID) + ')" ' +
                   'title="Revoke Key"><i class="fas fa-ban"></i></button>';
    }

    return buttons;
}

/**
 * 🔍 Build the detailed key information HTML for the Key Details modal.
 *
 * Displays: masked key prefix, partner info, environment, permissions,
 * IP whitelist, usage statistics summary, last used, and creation dates.
 *
 * @param {Object} key — Full key data from the API
 * @param {Object} stats — Usage statistics (30-day window)
 * @returns {string} HTML string for the modal body
 */
function buildKeyDetailsHTML(key, stats) {
    var html = '';

    // 🔑 Key identification section
    html += '<div class="row mb-4">';
    html += '<div class="col-md-6">';
    html += '<h6 class="text-muted mb-1"><i class="fas fa-fingerprint me-1"></i>Key Prefix</h6>';
    html += '<div class="key-prefix ' + (key.environment === 'live' ? 'key-prefix-live' : 'key-prefix-test') + '">';
    html += escapeHtml(key.keyPrefix || '') + '...</div>';
    html += '</div>';
    html += '<div class="col-md-6">';
    html += '<h6 class="text-muted mb-1"><i class="fas fa-building me-1"></i>Partner</h6>';
    html += '<p class="fw-bold mb-0">' + escapeHtml(key.partnerName || 'Unknown') + '</p>';
    html += '<small class="text-muted">Tier: ' + escapeHtml(key.accountTier || 'N/A') + '</small>';
    html += '</div>';
    html += '</div>';

    // 📋 Key metadata section
    html += '<div class="row mb-4">';
    html += '<div class="col-md-4">';
    html += '<h6 class="text-muted mb-1"><i class="fas fa-tag me-1"></i>Key Name</h6>';
    html += '<p class="mb-0">' + escapeHtml(key.keyName || '') + '</p>';
    html += '</div>';
    html += '<div class="col-md-4">';
    html += '<h6 class="text-muted mb-1"><i class="fas fa-globe me-1"></i>Environment</h6>';
    html += '<p class="mb-0">' + buildEnvironmentBadge(key.environment) + '</p>';
    html += '</div>';
    html += '<div class="col-md-4">';
    html += '<h6 class="text-muted mb-1"><i class="fas fa-info-circle me-1"></i>Status</h6>';
    html += '<p class="mb-0">' + buildStatusBadge(key) + '</p>';
    html += '</div>';
    html += '</div>';

    // 🔐 Permissions section
    html += '<div class="mb-4">';
    html += '<h6 class="text-muted mb-2"><i class="fas fa-shield-alt me-1"></i>Permissions</h6>';
    if (key.permissions) {
        var perms = typeof key.permissions === 'string' ? JSON.parse(key.permissions) : key.permissions;
        if (Array.isArray(perms) && perms.length > 0) {
            perms.forEach(function(perm) {
                html += '<span class="badge bg-primary permission-badge me-1">' + escapeHtml(perm) + '</span>';
            });
        } else {
            html += '<span class="text-muted">No specific permissions set (full access)</span>';
        }
    } else {
        html += '<span class="text-muted">No specific permissions set (full access)</span>';
    }
    html += '</div>';

    // 🌐 IP Whitelist section
    html += '<div class="mb-4">';
    html += '<h6 class="text-muted mb-2"><i class="fas fa-network-wired me-1"></i>IP Whitelist</h6>';
    if (key.ipWhitelist) {
        var ips = typeof key.ipWhitelist === 'string' ? JSON.parse(key.ipWhitelist) : key.ipWhitelist;
        if (Array.isArray(ips) && ips.length > 0) {
            html += '<div class="font-monospace small">';
            ips.forEach(function(ip) {
                html += '<span class="badge bg-secondary me-1 mb-1">' + escapeHtml(ip) + '</span>';
            });
            html += '</div>';
        } else {
            html += '<span class="text-muted">No IP whitelist (all IPs allowed)</span>';
        }
    } else {
        html += '<span class="text-muted">No IP whitelist (all IPs allowed)</span>';
    }
    html += '</div>';

    // 📊 Usage Statistics section (30-day summary)
    html += '<hr>';
    html += '<h6 class="text-muted mb-3"><i class="fas fa-chart-bar me-1"></i>Usage Statistics (Last 30 Days)</h6>';
    html += '<div class="row g-3 mb-3">';

    // 📈 Total requests
    html += '<div class="col-md-3 col-6">';
    html += '<div class="border rounded p-2 text-center">';
    html += '<div class="fw-bold text-primary" style="font-size: 1.5rem;">' + formatNumber(stats.totalRequests || 0) + '</div>';
    html += '<small class="text-muted">Total Requests</small>';
    html += '</div></div>';

    // ✅ Successful requests
    html += '<div class="col-md-3 col-6">';
    html += '<div class="border rounded p-2 text-center">';
    html += '<div class="fw-bold text-success" style="font-size: 1.5rem;">' + formatNumber(stats.successfulRequests || 0) + '</div>';
    html += '<small class="text-muted">Successful</small>';
    html += '</div></div>';

    // ❌ Error requests
    html += '<div class="col-md-3 col-6">';
    html += '<div class="border rounded p-2 text-center">';
    html += '<div class="fw-bold text-danger" style="font-size: 1.5rem;">' + formatNumber(stats.errorRequests || 0) + '</div>';
    html += '<small class="text-muted">Errors</small>';
    html += '</div></div>';

    // ⏱️ Average response time
    html += '<div class="col-md-3 col-6">';
    html += '<div class="border rounded p-2 text-center">';
    var avgTime = stats.avgResponseTime ? parseFloat(stats.avgResponseTime).toFixed(0) : '0';
    html += '<div class="fw-bold text-info" style="font-size: 1.5rem;">' + avgTime + '<small>ms</small></div>';
    html += '<small class="text-muted">Avg Response</small>';
    html += '</div></div>';

    html += '</div>';

    // 📋 Additional stats
    html += '<div class="row g-3 mb-3">';
    html += '<div class="col-md-4">';
    html += '<small class="text-muted"><i class="fas fa-globe me-1"></i>Unique IPs: <strong>' + formatNumber(stats.uniqueIPs || 0) + '</strong></small>';
    html += '</div>';
    html += '<div class="col-md-4">';
    html += '<small class="text-muted"><i class="fas fa-route me-1"></i>Unique Endpoints: <strong>' + formatNumber(stats.uniqueEndpoints || 0) + '</strong></small>';
    html += '</div>';
    html += '<div class="col-md-4">';
    html += '<small class="text-muted"><i class="fas fa-ban me-1"></i>Rate Limited: <strong>' + formatNumber(stats.rateLimitedRequests || 0) + '</strong></small>';
    html += '</div>';
    html += '</div>';

    // 📅 Dates section
    html += '<hr>';
    html += '<div class="row">';
    html += '<div class="col-md-4">';
    html += '<small class="text-muted"><i class="fas fa-calendar-plus me-1"></i>Created: ' + formatDate(key.createdAt) + '</small>';
    html += '</div>';
    html += '<div class="col-md-4">';
    html += '<small class="text-muted"><i class="fas fa-clock me-1"></i>Last Used: ' + (key.lastUsedAt ? formatDate(key.lastUsedAt) : 'Never') + '</small>';
    html += '</div>';
    html += '<div class="col-md-4">';
    html += '<small class="text-muted"><i class="fas fa-calendar-times me-1"></i>Expires: ' + (key.expiresAt ? formatDate(key.expiresAt) : 'Never') + '</small>';
    html += '</div>';
    html += '</div>';

    // 🔢 Usage count
    if (key.usageCount !== undefined) {
        html += '<div class="mt-2">';
        html += '<small class="text-muted"><i class="fas fa-tachometer-alt me-1"></i>Total Usage Count: <strong>' + formatNumber(key.usageCount) + '</strong></small>';
        html += '</div>';
    }

    return html;
}

/**
 * 🔘 Filter keys in the DataTable by status.
 *
 * Uses DataTables column search on the Status column (index 4)
 * to show only active, revoked, or all keys.
 *
 * @param {string} filter — 'all', 'active', or 'revoked'
 * @see https://datatables.net/reference/api/column().search() — Column search
 */
function filterKeys(filter) {
    currentFilter = filter;

    // 🔘 Update active button state
    document.querySelectorAll('.table-header .btn-group .btn').forEach(function(btn) {
        btn.classList.remove('active');
    });
    document.getElementById('filter' + filter.charAt(0).toUpperCase() + filter.slice(1)).classList.add('active');

    applyFilter();
}

/**
 * 🔘 Apply the current filter to the DataTable.
 * Uses regex search on the Status column content.
 */
function applyFilter() {
    if (!keysDataTable) {
        return;
    }

    switch (currentFilter) {
        case 'active':
            keysDataTable.column(4).search('Active', false, false).draw();
            break;
        case 'revoked':
            keysDataTable.column(4).search('Revoked|Expired', true, false).draw();
            break;
        default:
            keysDataTable.column(4).search('').draw();
            break;
    }
}

/**
 * 🔄 Reset the Create Key modal to its initial state.
 * Called when the modal is closed (hidden.bs.modal event).
 */
function resetCreateKeyModal() {
    // 🔄 Show form, hide result
    document.getElementById('createKeyForm').classList.remove('d-none');
    document.getElementById('createKeyResult').classList.add('d-none');

    // 🔄 Show Generate button
    var btnCreate = document.getElementById('btnCreateKey');
    btnCreate.classList.remove('d-none');
    btnCreate.disabled = false;
    btnCreate.innerHTML = '<i class="fas fa-plus-circle me-1"></i>Generate Key';

    // 🔄 Reset modal title
    document.getElementById('createKeyModalLabel').innerHTML =
        '<i class="fas fa-plus-circle me-2"></i>Create New API Key';

    // 🧹 Clear form inputs
    document.getElementById('createPartnerID').value = '';
    document.getElementById('createKeyName').value = '';
    document.getElementById('createIPWhitelist').value = '';
    document.getElementById('createExpiresAt').value = '';
    document.getElementById('generatedKeyDisplay').textContent = '';

    // 🔘 Reset environment to Test
    document.getElementById('envTest').checked = true;

    // 🧹 Uncheck all permission checkboxes
    document.querySelectorAll('#createKeyForm .form-check-input').forEach(function(cb) {
        cb.checked = false;
    });
}

/**
 * 📅 Format a date string into a human-readable format.
 *
 * @param {string} dateStr — Date string (ISO format or MySQL datetime)
 * @returns {string} Formatted date string (e.g., "08 Mar 2026 14:30")
 * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date — Date API
 */
function formatDate(dateStr) {
    if (!dateStr) {
        return '<span class="text-muted">N/A</span>';
    }

    var date = new Date(dateStr);
    if (isNaN(date.getTime())) {
        return escapeHtml(dateStr);
    }

    var day = String(date.getDate()).padStart(2, '0');
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var month = months[date.getMonth()];
    var year = date.getFullYear();
    var hours = String(date.getHours()).padStart(2, '0');
    var minutes = String(date.getMinutes()).padStart(2, '0');

    return day + ' ' + month + ' ' + year + ' ' + hours + ':' + minutes;
}

/**
 * 🔢 Format a number with comma separators for readability.
 *
 * @param {number|string} num — The number to format
 * @returns {string} Formatted number string (e.g., "1,234,567")
 * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Number/toLocaleString
 */
function formatNumber(num) {
    return parseInt(num || 0).toLocaleString();
}

/**
 * 🛡️ Escape HTML entities to prevent XSS in dynamically generated content.
 *
 * Replaces &, <, >, ", and ' with their HTML entity equivalents.
 * Must be used on ALL user-provided data before inserting into the DOM.
 *
 * @param {string} text — The text to escape
 * @returns {string} HTML-escaped text
 * @see https://owasp.org/www-community/attacks/xss/ — OWASP XSS Prevention
 */
function escapeHtml(text) {
    if (text === null || text === undefined) {
        return '';
    }
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(String(text)));
    return div.innerHTML;
}

/**
 * 📋 Show a Bootstrap toast notification.
 *
 * @param {string} message — The message to display
 * @param {string} type — Bootstrap colour class ('success', 'warning', 'danger', etc.)
 * @see https://getbootstrap.com/docs/5.3/components/toasts/ — Bootstrap Toasts
 */
function showToast(message, type) {
    var toastEl = document.getElementById('copyToast');
    var toastBody = toastEl.querySelector('.toast-body');

    // 🎨 Update toast colour and message
    toastEl.className = 'toast align-items-center text-white bg-' + (type || 'success') + ' border-0';
    toastBody.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-triangle') + ' me-2"></i>' + escapeHtml(message);

    // 📋 Show the toast with auto-hide after 3 seconds
    var toast = new bootstrap.Toast(toastEl, { delay: 3000 });
    toast.show();
}
</script>

</body>
</html>
