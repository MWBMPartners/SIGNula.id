<?php
/**
 * 🛡️ Rate Limiting Administration Dashboard
 *
 * Comprehensive admin interface for monitoring and managing rate limiting,
 * IP blocks, and security-related traffic controls. Provides real-time
 * statistics, IP block management, rate limit entry browsing, and
 * read-only display of current rate limiting configuration.
 *
 * Sections:
 *   1. Summary Cards   — Active blocks, rate limited today, total entries, whitelist size
 *   2. Settings Card   — Read-only display of current rate limit configuration
 *   3. Blocked IPs     — Paginated table with unblock + manual block modal
 *   4. Rate Limits     — Paginated + filterable table of tblRateLimits entries
 *   5. Top IPs         — Top 10 most rate-limited IP addresses
 *
 * All data is loaded via AJAX from /admin/api/rate-limit-actions.php.
 *
 * @see https://getbootstrap.com/docs/5.3/ - Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons - FontAwesome Icon Reference
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Admin\RateLimiting
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

// 🔌 Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check — redirect to login if not authenticated
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin check — only super admins can access this dashboard
$accessControl->requireSuperAdmin();

// 🔐 Generate CSRF token for POST operations (block/unblock/clear)
$csrfToken = SecurityUtils::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Limiting Dashboard - SIGNula Admin</title>

    <!-- 🎨 Bootstrap 5.3.2 CSS (CDN with SRI) -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/download/ -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <!-- 🎨 FontAwesome 6.4.2 CSS (CDN with SRI) -->
    <!-- @see https://fontawesome.com/docs/web/setup/host-yourself/webfonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">

    <style>
        /* 🎨 Base body styling with light background */
        body {
            background-color: #f8f9fa;
        }

        /* 🎨 Gradient page header — danger-themed for security context */
        .page-header {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        /* 📊 Summary statistic cards with subtle shadow and hover effect */
        .stat-card {
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .stat-card .stat-icon {
            font-size: 2rem;
            opacity: 0.8;
        }
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .stat-card .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 0;
        }

        /* 📋 Table refinements */
        .table th {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #495057;
            border-bottom-width: 2px;
        }
        .table td {
            vertical-align: middle;
        }

        /* 🔘 Pagination controls */
        .pagination-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
        }

        /* 🏷️ IP address code styling */
        code.ip-address {
            font-size: 0.9rem;
            padding: 0.15rem 0.4rem;
            background-color: #e9ecef;
            border-radius: 4px;
            color: #212529;
        }

        /* 📊 Settings key-value table */
        .settings-table td:first-child {
            font-weight: 600;
            width: 50%;
            color: #495057;
        }
        .settings-table td:last-child {
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            color: #0d6efd;
        }

        /* ⏳ Loading spinner overlay */
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
            border-radius: 10px;
        }

        /* 🎨 Card position for loading overlay */
        .card {
            position: relative;
        }

        /* 📱 Responsive adjustments */
        @media (max-width: 768px) {
            .stat-card .stat-value {
                font-size: 1.5rem;
            }
            .page-header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>

<!-- 🔝 Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="/admin/">
            <i class="fas fa-shield-alt me-2"></i>SIGNula Admin
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav"
                aria-controls="adminNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="/admin/">
                        <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/admin/users/">
                        <i class="fas fa-users me-1"></i>Users
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="/admin/rate-limiting/">
                        <i class="fas fa-shield-alt me-1"></i>Rate Limiting
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/admin/security/credential-reset.php">
                        <i class="fas fa-key me-1"></i>Credential Reset
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid py-4">

    <!-- ====================================================================
         🔝 Page Header
         ==================================================================== -->
    <div class="page-header">
        <a href="/admin/" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
            <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
        </a>
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1><i class="fas fa-shield-alt me-2"></i>Rate Limiting Dashboard</h1>
                <p class="mb-0 opacity-75">Monitor IP blocks, rate limit events, and manage traffic controls</p>
            </div>
            <div class="col-md-4 text-end">
                <button class="btn btn-light me-2" onclick="refreshAll()" id="btnRefresh">
                    <i class="fas fa-sync-alt me-2"></i>Refresh
                </button>
                <button class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#blockIPModal">
                    <i class="fas fa-ban me-2"></i>Block IP
                </button>
            </div>
        </div>
    </div>

    <!-- 🔔 Alert container for success/error messages -->
    <div id="alertContainer"></div>

    <!-- ====================================================================
         📊 Section 1: Summary Statistics Cards
         ==================================================================== -->
    <div class="row mb-4" id="statsCards">
        <!-- 🚫 Active IP Blocks -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card stat-card border-start border-danger border-4">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon text-danger me-3">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div>
                        <div class="stat-value text-danger" id="statActiveBlocks">
                            <div class="spinner-border spinner-border-sm" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                        <p class="stat-label">Active IP Blocks</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⚠️ Rate Limited Today -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card stat-card border-start border-warning border-4">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon text-warning me-3">
                        <i class="fas fa-tachometer-alt"></i>
                    </div>
                    <div>
                        <div class="stat-value text-warning" id="statRateLimitedToday">
                            <div class="spinner-border spinner-border-sm" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                        <p class="stat-label">Rate Limited Today</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 📊 Total Rate Entries -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card stat-card border-start border-primary border-4">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon text-primary me-3">
                        <i class="fas fa-database"></i>
                    </div>
                    <div>
                        <div class="stat-value text-primary" id="statTotalEntries">
                            <div class="spinner-border spinner-border-sm" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                        <p class="stat-label">Total Rate Entries</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 🛡️ Whitelist Size -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card stat-card border-start border-success border-4">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon text-success me-3">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div>
                        <div class="stat-value text-success" id="statWhitelistSize">
                            <div class="spinner-border spinner-border-sm" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                        <p class="stat-label">Whitelisted IPs</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ====================================================================
         ⚙️ Section 2: Rate Limit Settings (Read-Only)
         ==================================================================== -->
    <div class="card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-cog me-2 text-secondary"></i>Rate Limit Configuration</h5>
            <a href="/admin/settings/" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-edit me-1"></i>Modify Settings
            </a>
        </div>
        <div class="card-body" id="settingsContainer">
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading settings...</span>
                </div>
                <p class="text-muted mt-2">Loading rate limit configuration...</p>
            </div>
        </div>
    </div>

    <div class="row">

        <!-- ====================================================================
             🚫 Section 3: Blocked IPs
             ==================================================================== -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-ban me-2"></i>Blocked IP Addresses</h5>
                    <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#blockIPModal">
                        <i class="fas fa-plus me-1"></i>Block IP
                    </button>
                </div>
                <div class="card-body p-0" id="blockedIPsContainer">
                    <div class="text-center py-5">
                        <div class="spinner-border text-danger" role="status">
                            <span class="visually-hidden">Loading blocked IPs...</span>
                        </div>
                    </div>
                </div>
                <!-- 📄 Pagination for blocked IPs -->
                <div class="card-footer bg-white" id="blockedIPsPagination" style="display: none;"></div>
            </div>
        </div>

        <!-- ====================================================================
             📋 Section 4: Recent Rate Limit Events
             ==================================================================== -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Recent Rate Limit Events</h5>
                </div>
                <!-- 🔍 Filter controls -->
                <div class="card-body border-bottom pb-3">
                    <div class="row g-2">
                        <div class="col-md-5">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" id="filterIP"
                                       placeholder="Filter by IP address..." aria-label="Filter by IP">
                            </div>
                        </div>
                        <div class="col-md-5">
                            <select class="form-select form-select-sm" id="filterAction" aria-label="Filter by action">
                                <option value="">All Actions/Endpoints</option>
                                <!-- 🔧 Populated dynamically via AJAX -->
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-sm btn-outline-primary w-100" onclick="loadRateLimits(1)">
                                <i class="fas fa-filter me-1"></i>Filter
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0" id="rateLimitsContainer">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading rate limits...</span>
                        </div>
                    </div>
                </div>
                <!-- 📄 Pagination for rate limits -->
                <div class="card-footer bg-white" id="rateLimitsPagination" style="display: none;"></div>
            </div>
        </div>
    </div>

    <!-- ====================================================================
         🏆 Section 5: Top Rate-Limited IPs
         ==================================================================== -->
    <div class="card mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-trophy me-2 text-warning"></i>Top 10 Rate-Limited IPs</h5>
        </div>
        <div class="card-body p-0" id="topIPsContainer">
            <div class="text-center py-5">
                <div class="spinner-border text-warning" role="status">
                    <span class="visually-hidden">Loading top IPs...</span>
                </div>
            </div>
        </div>
    </div>

</div><!-- /.container-fluid -->


<!-- ====================================================================
     🔲 Modal: Block IP Address
     ==================================================================== -->
<div class="modal fade" id="blockIPModal" tabindex="-1" aria-labelledby="blockIPModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="blockIPModalLabel">
                    <i class="fas fa-ban me-2"></i>Block IP Address
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="blockIPForm" onsubmit="return false;">
                    <!-- 📝 IP Address input -->
                    <div class="mb-3">
                        <label for="blockIPAddress" class="form-label fw-bold">
                            <i class="fas fa-network-wired me-1"></i>IP Address <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="blockIPAddress"
                               placeholder="e.g. 192.168.1.100 or 2001:db8::1"
                               required pattern="[0-9a-fA-F.:]+">
                        <div class="form-text">Enter a valid IPv4 or IPv6 address.</div>
                    </div>

                    <!-- 📝 Reason textarea -->
                    <div class="mb-3">
                        <label for="blockIPReason" class="form-label fw-bold">
                            <i class="fas fa-comment me-1"></i>Reason <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="blockIPReason" rows="3"
                                  placeholder="Enter the reason for blocking this IP..."
                                  required maxlength="500"></textarea>
                        <div class="form-text">Max 500 characters.</div>
                    </div>

                    <!-- 📝 Expiry datetime (optional) -->
                    <div class="mb-3">
                        <label for="blockIPExpiry" class="form-label fw-bold">
                            <i class="fas fa-clock me-1"></i>Expires At <span class="text-muted">(optional)</span>
                        </label>
                        <input type="datetime-local" class="form-control" id="blockIPExpiry">
                        <div class="form-text">Leave blank for a permanent block.</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-danger" onclick="blockIP()" id="btnBlockIP">
                    <i class="fas fa-ban me-1"></i>Block IP
                </button>
            </div>
        </div>
    </div>
</div>


<!-- ====================================================================
     📦 JavaScript Dependencies
     ==================================================================== -->

<!-- Bootstrap 5.3.2 JS Bundle (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

<script>
/**
 * 🛡️ Rate Limiting Dashboard — Client-Side Logic
 *
 * Manages AJAX data loading, pagination, filtering, and user interactions
 * for the rate limiting administration interface.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API
 */

// =====================================================================
// 🔧 Configuration
// =====================================================================

/** @type {string} Base API endpoint for all rate limit actions */
const API_URL = '/admin/api/rate-limit-actions.php';

/** @type {string} CSRF token for POST requests — generated server-side */
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

/** @type {number} Auto-refresh interval in milliseconds (60 seconds) */
const AUTO_REFRESH_INTERVAL = 60000;

/** @type {number|null} Auto-refresh timer reference */
let autoRefreshTimer = null;


// =====================================================================
// 🔒 XSS Prevention
// =====================================================================

/**
 * 🔒 Escape HTML entities to prevent XSS when injecting dynamic content
 *
 * Replaces &, <, >, ", and ' with their HTML entity equivalents.
 *
 * @param {string} text - The raw text to escape
 * @returns {string} HTML-safe string
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
 */
function escapeHtml(text) {
    if (text === null || text === undefined) {
        return '';
    }
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(text)));
    return div.innerHTML;
}


// =====================================================================
// 🔔 Alert Notifications
// =====================================================================

/**
 * 🔔 Display a dismissible Bootstrap alert notification
 *
 * @param {string} message - The alert message text
 * @param {string} type    - Bootstrap alert type: 'success', 'danger', 'warning', 'info'
 */
function showAlert(message, type) {
    const container = document.getElementById('alertContainer');
    const alertId = 'alert-' + Date.now();
    container.innerHTML =
        '<div class="alert alert-' + escapeHtml(type) + ' alert-dismissible fade show" id="' + alertId + '" role="alert">' +
            '<i class="fas fa-' + (type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle') + ' me-2"></i>' +
            escapeHtml(message) +
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
        '</div>';

    // ⏱️ Auto-dismiss after 6 seconds
    setTimeout(function() {
        const alertEl = document.getElementById(alertId);
        if (alertEl) {
            alertEl.classList.remove('show');
            setTimeout(function() { alertEl.remove(); }, 150);
        }
    }, 6000);
}


// =====================================================================
// 📊 Data Loading Functions
// =====================================================================

/**
 * 📊 Load all dashboard data in parallel
 *
 * Called on page load and when the refresh button is clicked.
 * Resets the auto-refresh timer after each manual refresh.
 */
function refreshAll() {
    // 🔄 Visual feedback on the refresh button
    const btn = document.getElementById('btnRefresh');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Refreshing...';
    }

    // 🚀 Fire all data loads in parallel
    Promise.all([
        loadStats(),
        loadBlockedIPs(1),
        loadRateLimits(1)
    ]).finally(function() {
        // ✅ Re-enable refresh button
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt me-2"></i>Refresh';
        }

        // ⏱️ Reset auto-refresh timer
        if (autoRefreshTimer) {
            clearInterval(autoRefreshTimer);
        }
        autoRefreshTimer = setInterval(refreshAll, AUTO_REFRESH_INTERVAL);
    });
}


/**
 * 📊 Load summary statistics from the API
 *
 * Updates the four summary cards and the settings + top IPs sections.
 *
 * @returns {Promise<void>}
 */
function loadStats() {
    return fetch(API_URL + '?action=get_stats')
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            if (!data.success) {
                throw new Error(data.error || data.message || 'Unknown error');
            }

            // 📊 Update summary cards with animated number display
            document.getElementById('statActiveBlocks').textContent = data.activeBlocks.toLocaleString();
            document.getElementById('statRateLimitedToday').textContent = data.rateLimitedToday.toLocaleString();
            document.getElementById('statTotalEntries').textContent = data.totalEntries.toLocaleString();
            document.getElementById('statWhitelistSize').textContent = data.whitelistCount.toLocaleString();

            // ⚙️ Render settings table
            renderSettings(data.settings);

            // 🏆 Render top IPs table
            renderTopIPs(data.topIPs);
        })
        .catch(function(error) {
            console.error('Failed to load stats:', error);
            // 🔧 Show error state in stat cards
            ['statActiveBlocks', 'statRateLimitedToday', 'statTotalEntries', 'statWhitelistSize'].forEach(function(id) {
                document.getElementById(id).innerHTML = '<i class="fas fa-exclamation-triangle text-danger"></i>';
            });
        });
}


/**
 * ⚙️ Render the rate limit settings as a read-only table
 *
 * @param {Object} settings - Key-value map of rate limiting settings
 */
function renderSettings(settings) {
    var container = document.getElementById('settingsContainer');

    // 🔧 Map of setting keys to human-readable labels
    var labels = {
        'enabled':                     'Rate Limiting Enabled',
        'progressive_blocking':        'Progressive Blocking',
        'max_block_duration_hours':     'Max Block Duration (hours)',
        'requests_per_hour':           'Default Requests per Hour',
        'requests_per_minute':         'Default Requests per Minute',
        'burst_limit':                 'Default Burst Limit',
        'burst_window':                'Burst Window (seconds)',
        'cleanup_interval':            'Cleanup Interval',
        'whitelist_enabled':           'IP Whitelist Enabled'
    };

    var keys = Object.keys(settings);

    if (keys.length === 0) {
        container.innerHTML =
            '<div class="text-center py-4 text-muted">' +
                '<i class="fas fa-info-circle fa-2x mb-2"></i>' +
                '<p>No rate limiting settings found in the database.</p>' +
                '<a href="/admin/settings/" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i>Configure Settings</a>' +
            '</div>';
        return;
    }

    var html = '<div class="table-responsive"><table class="table table-sm table-hover settings-table mb-0">';
    html += '<thead><tr><th>Setting</th><th>Value</th></tr></thead><tbody>';

    keys.forEach(function(key) {
        var label = labels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
        var value = settings[key];

        // 🎨 Format boolean-like values with badges
        var displayValue = escapeHtml(value);
        if (value === '1' || value === 'true') {
            displayValue = '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Enabled</span>';
        } else if (value === '0' || value === 'false') {
            displayValue = '<span class="badge bg-secondary"><i class="fas fa-times me-1"></i>Disabled</span>';
        }

        html += '<tr><td>' + escapeHtml(label) + '</td><td>' + displayValue + '</td></tr>';
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
}


/**
 * 🏆 Render the top 10 rate-limited IPs table
 *
 * @param {Array} topIPs - Array of top IP objects from the API
 */
function renderTopIPs(topIPs) {
    var container = document.getElementById('topIPsContainer');

    if (!topIPs || topIPs.length === 0) {
        container.innerHTML =
            '<div class="text-center py-5 text-muted">' +
                '<i class="fas fa-check-circle fa-3x text-success mb-3"></i>' +
                '<p class="mb-0">No rate-limited IPs found. System is running smoothly!</p>' +
            '</div>';
        return;
    }

    var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">';
    html += '<thead><tr>';
    html += '<th class="ps-3">#</th>';
    html += '<th>IP Address</th>';
    html += '<th>Type</th>';
    html += '<th class="text-end">Total Requests</th>';
    html += '<th class="text-end">Entries</th>';
    html += '<th>Last Seen</th>';
    html += '<th>Status</th>';
    html += '<th class="text-end pe-3">Actions</th>';
    html += '</tr></thead><tbody>';

    topIPs.forEach(function(ip, index) {
        var statusBadge = ip.isCurrentlyBlocked == 1
            ? '<span class="badge bg-danger"><i class="fas fa-ban me-1"></i>Blocked</span>'
            : '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Active</span>';

        html += '<tr>';
        html += '<td class="ps-3 text-muted">' + (index + 1) + '</td>';
        html += '<td><code class="ip-address">' + escapeHtml(ip.ip) + '</code></td>';
        html += '<td><span class="badge bg-info">' + escapeHtml(ip.identifierType).toUpperCase() + '</span></td>';
        html += '<td class="text-end fw-bold">' + parseInt(ip.totalRequests).toLocaleString() + '</td>';
        html += '<td class="text-end">' + parseInt(ip.entryCount).toLocaleString() + '</td>';
        html += '<td><small class="text-muted">' + escapeHtml(ip.lastSeen) + '</small></td>';
        html += '<td>' + statusBadge + '</td>';
        html += '<td class="text-end pe-3">';
        html += '<button class="btn btn-sm btn-outline-warning" onclick="clearRateLimits(\'' + escapeHtml(ip.ip) + '\')" title="Clear rate limit entries">';
        html += '<i class="fas fa-eraser"></i>';
        html += '</button>';
        html += '</td>';
        html += '</tr>';
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
}


/**
 * 🚫 Load paginated blocked IPs from the API
 *
 * @param {number} page - Page number to load
 * @returns {Promise<void>}
 */
function loadBlockedIPs(page) {
    var container = document.getElementById('blockedIPsContainer');
    container.innerHTML =
        '<div class="text-center py-5">' +
            '<div class="spinner-border text-danger" role="status"><span class="visually-hidden">Loading...</span></div>' +
        '</div>';

    return fetch(API_URL + '?action=get_blocked_ips&page=' + page + '&per_page=10')
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (!data.success) {
                throw new Error(data.error || 'Failed to load blocked IPs');
            }

            if (data.data.length === 0) {
                container.innerHTML =
                    '<div class="text-center py-5 text-muted">' +
                        '<i class="fas fa-check-circle fa-3x text-success mb-3"></i>' +
                        '<p class="mb-0">No active IP blocks. All clear!</p>' +
                    '</div>';
                document.getElementById('blockedIPsPagination').style.display = 'none';
                return;
            }

            // 📋 Build the blocked IPs table
            var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">';
            html += '<thead><tr>';
            html += '<th class="ps-3">IP Address</th>';
            html += '<th>Type</th>';
            html += '<th>Reason</th>';
            html += '<th>Blocked By</th>';
            html += '<th>Blocked At</th>';
            html += '<th>Expires</th>';
            html += '<th class="text-end pe-3">Actions</th>';
            html += '</tr></thead><tbody>';

            data.data.forEach(function(block) {
                var expiresDisplay = block.expiresAt
                    ? '<small>' + escapeHtml(block.expiresAt) + '</small>'
                    : '<span class="badge bg-dark">Permanent</span>';

                var typeBadge = '';
                switch (block.blockType) {
                    case 'permanent': typeBadge = '<span class="badge bg-dark">Permanent</span>'; break;
                    case 'temporary': typeBadge = '<span class="badge bg-warning text-dark">Temporary</span>'; break;
                    case 'auto':      typeBadge = '<span class="badge bg-info">Auto</span>'; break;
                    default:          typeBadge = '<span class="badge bg-secondary">' + escapeHtml(block.blockType) + '</span>';
                }

                html += '<tr>';
                html += '<td class="ps-3"><code class="ip-address">' + escapeHtml(block.ipAddress) + '</code></td>';
                html += '<td>' + typeBadge + '</td>';
                html += '<td><small>' + escapeHtml(block.reason) + '</small></td>';
                html += '<td><small>' + escapeHtml(block.blockedByName) + '</small></td>';
                html += '<td><small class="text-muted">' + escapeHtml(block.blockedAt) + '</small></td>';
                html += '<td>' + expiresDisplay + '</td>';
                html += '<td class="text-end pe-3">';
                html += '<button class="btn btn-sm btn-success" onclick="unblockIP(' + parseInt(block.blockID) + ', \'' + escapeHtml(block.ipAddress) + '\')" title="Unblock this IP">';
                html += '<i class="fas fa-unlock me-1"></i>Unblock';
                html += '</button>';
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody></table></div>';
            container.innerHTML = html;

            // 📄 Render pagination
            renderPagination('blockedIPsPagination', data.pagination, 'loadBlockedIPs');
        })
        .catch(function(error) {
            console.error('Failed to load blocked IPs:', error);
            container.innerHTML =
                '<div class="text-center py-4 text-danger">' +
                    '<i class="fas fa-exclamation-triangle fa-2x mb-2"></i>' +
                    '<p>Failed to load blocked IPs. Please try refreshing.</p>' +
                '</div>';
        });
}


/**
 * 📋 Load paginated rate limit entries from the API
 *
 * Applies any active IP or action filters from the filter controls.
 *
 * @param {number} page - Page number to load
 * @returns {Promise<void>}
 */
function loadRateLimits(page) {
    var container = document.getElementById('rateLimitsContainer');
    container.innerHTML =
        '<div class="text-center py-5">' +
            '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>' +
        '</div>';

    // 🔍 Collect filter values
    var filterIP = (document.getElementById('filterIP').value || '').trim();
    var filterAction = document.getElementById('filterAction').value || '';

    var url = API_URL + '?action=get_rate_limits&page=' + page + '&per_page=15';
    if (filterIP) {
        url += '&ip=' + encodeURIComponent(filterIP);
    }
    if (filterAction) {
        url += '&action_filter=' + encodeURIComponent(filterAction);
    }

    return fetch(url)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (!data.success) {
                throw new Error(data.error || 'Failed to load rate limits');
            }

            // 🔧 Populate the endpoint filter dropdown (only on first load or refresh)
            var actionSelect = document.getElementById('filterAction');
            if (data.endpoints && actionSelect.options.length <= 1) {
                data.endpoints.forEach(function(endpoint) {
                    var opt = document.createElement('option');
                    opt.value = endpoint;
                    opt.textContent = endpoint;
                    actionSelect.appendChild(opt);
                });
            }

            if (data.data.length === 0) {
                container.innerHTML =
                    '<div class="text-center py-5 text-muted">' +
                        '<i class="fas fa-inbox fa-3x mb-3"></i>' +
                        '<p class="mb-0">No rate limit entries found' + (filterIP ? ' for the applied filters' : '') + '.</p>' +
                    '</div>';
                document.getElementById('rateLimitsPagination').style.display = 'none';
                return;
            }

            // 📋 Build the rate limits table
            var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">';
            html += '<thead><tr>';
            html += '<th class="ps-3">IP / Identifier</th>';
            html += '<th>Endpoint</th>';
            html += '<th class="text-end">Requests</th>';
            html += '<th>Window Start</th>';
            html += '<th>Blocked Until</th>';
            html += '<th class="text-end pe-3">Actions</th>';
            html += '</tr></thead><tbody>';

            data.data.forEach(function(entry) {
                var blockedBadge = '';
                if (entry.isBlocked == 1) {
                    blockedBadge = '<span class="badge bg-danger ms-1"><i class="fas fa-ban"></i></span>';
                }

                var blockedUntilDisplay = entry.blockedUntil
                    ? '<small class="text-danger fw-bold">' + escapeHtml(entry.blockedUntil) + '</small>'
                    : '<small class="text-muted">—</small>';

                html += '<tr' + (entry.isBlocked == 1 ? ' class="table-danger"' : '') + '>';
                html += '<td class="ps-3"><code class="ip-address">' + escapeHtml(entry.ip) + '</code>' + blockedBadge + '</td>';
                html += '<td><code><small>' + escapeHtml(entry.endpoint || 'global') + '</small></code></td>';
                html += '<td class="text-end">';
                html += '<span class="fw-bold' + (parseInt(entry.requestCount) > 100 ? ' text-warning' : '') + '">';
                html += parseInt(entry.requestCount).toLocaleString();
                html += '</span>';
                html += '</td>';
                html += '<td><small class="text-muted">' + escapeHtml(entry.windowStart) + '</small></td>';
                html += '<td>' + blockedUntilDisplay + '</td>';
                html += '<td class="text-end pe-3">';
                html += '<button class="btn btn-sm btn-outline-warning" onclick="clearRateLimits(\'' + escapeHtml(entry.ip) + '\')" title="Clear rate limit entries for this IP">';
                html += '<i class="fas fa-eraser"></i>';
                html += '</button>';
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody></table></div>';
            container.innerHTML = html;

            // 📄 Render pagination
            renderPagination('rateLimitsPagination', data.pagination, 'loadRateLimits');
        })
        .catch(function(error) {
            console.error('Failed to load rate limits:', error);
            container.innerHTML =
                '<div class="text-center py-4 text-danger">' +
                    '<i class="fas fa-exclamation-triangle fa-2x mb-2"></i>' +
                    '<p>Failed to load rate limit entries. Please try refreshing.</p>' +
                '</div>';
        });
}


// =====================================================================
// 📄 Pagination Renderer
// =====================================================================

/**
 * 📄 Render pagination controls for a given section
 *
 * @param {string} containerId  - ID of the pagination container element
 * @param {Object} pagination   - Pagination metadata (page, totalPages, total, per_page)
 * @param {string} callbackName - Name of the JS function to call for page navigation
 */
function renderPagination(containerId, pagination, callbackName) {
    var container = document.getElementById(containerId);

    if (pagination.totalPages <= 1) {
        container.style.display = 'none';
        return;
    }

    container.style.display = 'block';

    var html = '<div class="pagination-controls">';
    html += '<small class="text-muted">Showing page ' + pagination.page + ' of ' + pagination.totalPages + ' (' + pagination.total + ' total)</small>';
    html += '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';

    // ⬅️ Previous button
    if (pagination.page > 1) {
        html += '<li class="page-item"><a class="page-link" href="#" onclick="' + callbackName + '(' + (pagination.page - 1) + '); return false;">&laquo;</a></li>';
    } else {
        html += '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
    }

    // 📄 Page number buttons (show max 5 pages around current)
    var startPage = Math.max(1, pagination.page - 2);
    var endPage = Math.min(pagination.totalPages, pagination.page + 2);

    if (startPage > 1) {
        html += '<li class="page-item"><a class="page-link" href="#" onclick="' + callbackName + '(1); return false;">1</a></li>';
        if (startPage > 2) {
            html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    for (var i = startPage; i <= endPage; i++) {
        if (i === pagination.page) {
            html += '<li class="page-item active"><span class="page-link">' + i + '</span></li>';
        } else {
            html += '<li class="page-item"><a class="page-link" href="#" onclick="' + callbackName + '(' + i + '); return false;">' + i + '</a></li>';
        }
    }

    if (endPage < pagination.totalPages) {
        if (endPage < pagination.totalPages - 1) {
            html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        html += '<li class="page-item"><a class="page-link" href="#" onclick="' + callbackName + '(' + pagination.totalPages + '); return false;">' + pagination.totalPages + '</a></li>';
    }

    // ➡️ Next button
    if (pagination.page < pagination.totalPages) {
        html += '<li class="page-item"><a class="page-link" href="#" onclick="' + callbackName + '(' + (pagination.page + 1) + '); return false;">&raquo;</a></li>';
    } else {
        html += '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
    }

    html += '</ul></nav></div>';
    container.innerHTML = html;
}


// =====================================================================
// ✏️ Action Functions (POST Requests)
// =====================================================================

/**
 * 🚫 Block an IP address via the modal form
 *
 * Validates input, sends a POST request to the API, and refreshes the view.
 */
function blockIP() {
    var ipAddress = (document.getElementById('blockIPAddress').value || '').trim();
    var reason = (document.getElementById('blockIPReason').value || '').trim();
    var expiresAt = document.getElementById('blockIPExpiry').value || '';

    // ✅ Client-side validation
    if (!ipAddress) {
        showAlert('Please enter a valid IP address.', 'warning');
        return;
    }
    if (!reason) {
        showAlert('Please enter a reason for the block.', 'warning');
        return;
    }

    // 🔒 Disable the button to prevent double-clicks
    var btn = document.getElementById('btnBlockIP');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Blocking...';

    fetch(API_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': CSRF_TOKEN
        },
        body: JSON.stringify({
            action: 'block_ip',
            csrf_token: CSRF_TOKEN,
            ip_address: ipAddress,
            reason: reason,
            expires_at: expiresAt || null
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            showAlert(data.message || 'IP address blocked successfully.', 'success');

            // 🔧 Close the modal and reset the form
            var modal = bootstrap.Modal.getInstance(document.getElementById('blockIPModal'));
            if (modal) { modal.hide(); }
            document.getElementById('blockIPForm').reset();

            // 🔄 Refresh the blocked IPs list and stats
            loadBlockedIPs(1);
            loadStats();
        } else {
            showAlert(data.error || 'Failed to block IP address.', 'danger');
        }
    })
    .catch(function(error) {
        console.error('Block IP error:', error);
        showAlert('An unexpected error occurred. Please try again.', 'danger');
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-ban me-1"></i>Block IP';
    });
}


/**
 * ✅ Unblock an IP address
 *
 * Prompts for confirmation before sending the unblock request.
 *
 * @param {number} blockID   - The block ID from tblBlockedIPs
 * @param {string} ipAddress - The IP address (for display in confirmation)
 */
function unblockIP(blockID, ipAddress) {
    if (!confirm('Are you sure you want to unblock ' + ipAddress + '?\n\nThis will immediately allow traffic from this IP address.')) {
        return;
    }

    fetch(API_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': CSRF_TOKEN
        },
        body: JSON.stringify({
            action: 'unblock_ip',
            csrf_token: CSRF_TOKEN,
            block_id: blockID
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            showAlert(data.message || 'IP address unblocked successfully.', 'success');
            loadBlockedIPs(1);
            loadStats();
        } else {
            showAlert(data.error || 'Failed to unblock IP address.', 'danger');
        }
    })
    .catch(function(error) {
        console.error('Unblock IP error:', error);
        showAlert('An unexpected error occurred. Please try again.', 'danger');
    });
}


/**
 * 🧹 Clear all rate limit entries for a specific IP
 *
 * Prompts for confirmation before deleting entries from tblRateLimits.
 *
 * @param {string} ip - The IP address whose entries should be cleared
 */
function clearRateLimits(ip) {
    if (!confirm('Clear all rate limit entries for ' + ip + '?\n\nThis will reset the rate limit counter for this IP but will NOT remove any IP blocks from the blocklist.')) {
        return;
    }

    fetch(API_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': CSRF_TOKEN
        },
        body: JSON.stringify({
            action: 'clear_rate_limits',
            csrf_token: CSRF_TOKEN,
            ip: ip
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            showAlert(data.message || 'Rate limit entries cleared.', 'success');
            loadRateLimits(1);
            loadStats();
        } else {
            showAlert(data.error || 'Failed to clear rate limit entries.', 'danger');
        }
    })
    .catch(function(error) {
        console.error('Clear rate limits error:', error);
        showAlert('An unexpected error occurred. Please try again.', 'danger');
    });
}


// =====================================================================
// 🚀 Initialisation
// =====================================================================

/**
 * 🚀 Initialise the dashboard when the DOM is ready
 *
 * Loads all data sections and sets up the auto-refresh timer.
 */
document.addEventListener('DOMContentLoaded', function() {
    // 📊 Load all dashboard data
    refreshAll();

    // ⌨️ Allow Enter key in IP filter to trigger search
    document.getElementById('filterIP').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            loadRateLimits(1);
        }
    });

    // 🔧 Set minimum date for the block expiry datepicker to now
    var expiryInput = document.getElementById('blockIPExpiry');
    if (expiryInput) {
        var now = new Date();
        // Format: YYYY-MM-DDTHH:MM (required by datetime-local input)
        var minDate = now.getFullYear() + '-' +
            String(now.getMonth() + 1).padStart(2, '0') + '-' +
            String(now.getDate()).padStart(2, '0') + 'T' +
            String(now.getHours()).padStart(2, '0') + ':' +
            String(now.getMinutes()).padStart(2, '0');
        expiryInput.setAttribute('min', minDate);
    }
});
</script>

</body>
</html>
