<?php
/**
 * ============================================================================
 * 📋 SIGNula - Admin Audit Log Viewer
 * ============================================================================
 *
 * Purpose: Provides a rich, interactive UI for super administrators to browse,
 *          search, filter, and export activity log entries from tblActivityLog.
 *
 * Features:
 * - Summary statistics cards (total, today, active users, unique IPs)
 * - Collapsible filter panel with date range, user, type, result, IP, text search
 * - Server-side paginated DataTable via AJAX
 * - Detail modal for full log entry inspection (metadata, user agent, etc.)
 * - CSV export with current filters applied
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Admin\AuditLog
 * @version    1.0.0
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons — FontAwesome 6.4 Icons
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API — Fetch API
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 🚫 SIGNULA_INIT Guard — Define for entry-point files
// ============================================================================
if (!defined('SIGNULA_INIT')) {
    define('SIGNULA_INIT', true);
}

// ============================================================================
// 📦 Dependencies — Load core configuration and services
// ============================================================================

/**
 * 🔧 Load the main configuration file which bootstraps the application
 * @see web/_config/config.php — Application bootstrap
 */
$configPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    die('Configuration file not found.');
}
require_once $configPath;

/**
 * 🗄️ Database singleton for MySQLi prepared statements
 * @see web/_backend/Database.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

/**
 * 🔐 Session management for authentication checks
 * @see web/_backend/SessionManager.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

/**
 * 🛡️ Access control for role-based permission enforcement
 * @see web/_backend/AccessControl.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// ============================================================================
// 🔌 Initialise Core Services
// ============================================================================
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// ============================================================================
// 🔒 Authentication Check — Redirect unauthenticated users to login
// ============================================================================
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// ============================================================================
// 🛡️ Super Admin Check — Only super admins can view the audit log
// ============================================================================
$accessControl->requireSuperAdmin();

// ============================================================================
// 📊 Fetch distinct activity types for the filter dropdown
// ============================================================================
$typesStmt = $db->prepare("SELECT DISTINCT activityType FROM tblActivityLog ORDER BY activityType ASC");
$typesStmt->execute();
$typesResult = $typesStmt->get_result();
$activityTypes = [];
while ($row = $typesResult->fetch_assoc()) {
    $activityTypes[] = $row['activityType'];
}
$typesStmt->close();

// ============================================================================
// 🔐 Generate CSRF Token for any POST operations
// ============================================================================
$csrfToken = SecurityUtils::generateCSRFToken();

// ============================================================================
// 📋 Page configuration
// ============================================================================
$pageTitle = 'Audit Log Viewer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ================================================================== -->
    <!-- 📄 Meta Tags -->
    <!-- ================================================================== -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> - SIGNula Admin</title>

    <!-- ================================================================== -->
    <!-- 📦 External CSS — Bootstrap 5.3.2 + FontAwesome 6.4.2 -->
    <!-- @see https://getbootstrap.com/docs/5.3/ -->
    <!-- @see https://fontawesome.com/icons -->
    <!-- ================================================================== -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <!-- ================================================================== -->
    <!-- 🎨 Custom Styles -->
    <!-- ================================================================== -->
    <style>
        /* 🎨 Base page background */
        body {
            background-color: #f8f9fa;
        }

        /* 🎨 Page header with gradient — blue-purple theme for audit/log context */
        .page-header {
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        .page-header h1 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
        }
        .page-header p {
            margin: 0.5rem 0 0 0;
            opacity: 0.85;
        }

        /* 📊 Summary stat cards */
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
        }
        .stat-card .stat-icon {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        .stat-card .stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-card .stat-label {
            color: #6c757d;
            font-size: 0.8rem;
            margin-top: 0.4rem;
        }

        /* 🔍 Filter panel styling */
        .filter-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
        }
        .filter-card .card-header {
            background: transparent;
            border-bottom: 1px solid #e9ecef;
            padding: 0.75rem 1.25rem;
            cursor: pointer;
        }
        .filter-card .card-header:hover {
            background: #f8f9fa;
        }

        /* 📋 Log table container */
        .log-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        /* 📊 Table styles */
        .table-logs th {
            background: #f8f9fa;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6c757d;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }
        .table-logs td {
            font-size: 0.875rem;
            vertical-align: middle;
        }
        .table-logs tbody tr {
            transition: background-color 0.15s ease;
        }
        .table-logs tbody tr:hover {
            background-color: #f0f4ff;
        }

        /* 🏷️ Result badges */
        .badge-success { background-color: #22c55e; }
        .badge-failure { background-color: #ef4444; }
        .badge-warning { background-color: #f59e0b; color: #1f2937; }
        .badge-info { background-color: #3b82f6; }

        /* 📐 Pagination controls */
        .pagination-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.25rem;
            border-top: 1px solid #e9ecef;
        }
        .pagination-info {
            color: #6c757d;
            font-size: 0.85rem;
        }

        /* 🔍 Detail modal metadata */
        .detail-label {
            font-weight: 600;
            color: #374151;
            font-size: 0.85rem;
        }
        .detail-value {
            color: #1f2937;
            font-size: 0.875rem;
            word-break: break-all;
        }
        .detail-json {
            background: #1e293b;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 6px;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 0.8rem;
            max-height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }

        /* 📱 Responsive adjustments */
        @media (max-width: 768px) {
            .page-header {
                padding: 1.25rem;
            }
            .page-header h1 {
                font-size: 1.25rem;
            }
            .stat-card .stat-number {
                font-size: 1.25rem;
            }
            .table-responsive {
                font-size: 0.8rem;
            }
        }

        /* ⏳ Loading spinner overlay */
        .loading-overlay {
            position: relative;
        }
        .loading-overlay::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }
        .loading-overlay.active::after {
            display: flex;
        }

        /* 🎨 Top stats bar items */
        .top-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.35rem 0;
            border-bottom: 1px solid #f1f3f5;
        }
        .top-item:last-child {
            border-bottom: none;
        }
        .top-item-label {
            font-size: 0.8rem;
            color: #495057;
            max-width: 70%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .top-item-count {
            font-weight: 600;
            font-size: 0.8rem;
            color: #2563eb;
        }
    </style>
</head>
<body>
    <!-- ================================================================== -->
    <!-- 🧭 Admin Breadcrumb Navigation -->
    <!-- ================================================================== -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: #1e293b;">
        <div class="container-fluid">
            <a class="navbar-brand" href="/admin/">
                <i class="fas fa-shield-halved me-2"></i>SIGNula Admin
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text text-light">
                    <i class="fas fa-user-shield me-1"></i>
                    <?php echo htmlspecialchars($_SESSION['displayName'] ?? $_SESSION['username'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        </div>
    </nav>

    <!-- ================================================================== -->
    <!-- 📄 Main Content Container -->
    <!-- ================================================================== -->
    <div class="container-fluid mt-4 px-lg-4">

        <!-- ==============================================================  -->
        <!-- 🏷️ Breadcrumb -->
        <!-- ============================================================== -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/admin/"><i class="fas fa-home"></i> Admin</a></li>
                <li class="breadcrumb-item"><a href="/admin/logs/">Logs</a></li>
                <li class="breadcrumb-item active" aria-current="page">Audit Log Viewer</li>
            </ol>
        </nav>

        <!-- ==============================================================  -->
        <!-- 🎨 Page Header -->
        <!-- ============================================================== -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h1><i class="fas fa-clipboard-list me-2"></i><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p>Browse, filter, and export system activity logs for security auditing and debugging.</p>
                </div>
                <div class="mt-2 mt-md-0">
                    <button class="btn btn-light btn-sm" id="btnRefresh" title="Refresh data">
                        <i class="fas fa-sync-alt me-1"></i>Refresh
                    </button>
                </div>
            </div>
        </div>

        <!-- ==============================================================  -->
        <!-- 📊 Summary Statistics Cards -->
        <!-- ============================================================== -->
        <div class="row g-3 mb-4" id="statsRow">
            <!-- 📋 Total Logs -->
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon text-primary"><i class="fas fa-list"></i></div>
                    <div class="stat-number" id="statTotal">
                        <span class="spinner-border spinner-border-sm" role="status"></span>
                    </div>
                    <div class="stat-label">Total Logs</div>
                </div>
            </div>
            <!-- 🕐 Today's Activity -->
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon text-success"><i class="fas fa-clock"></i></div>
                    <div class="stat-number" id="statToday">
                        <span class="spinner-border spinner-border-sm" role="status"></span>
                    </div>
                    <div class="stat-label">Today's Activity</div>
                </div>
            </div>
            <!-- 👥 Active Users -->
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon text-info"><i class="fas fa-users"></i></div>
                    <div class="stat-number" id="statUsers">
                        <span class="spinner-border spinner-border-sm" role="status"></span>
                    </div>
                    <div class="stat-label">Active Users</div>
                </div>
            </div>
            <!-- 🌐 Unique IPs -->
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon text-warning"><i class="fas fa-globe"></i></div>
                    <div class="stat-number" id="statIPs">
                        <span class="spinner-border spinner-border-sm" role="status"></span>
                    </div>
                    <div class="stat-label">Unique IPs</div>
                </div>
            </div>
        </div>

        <!-- ==============================================================  -->
        <!-- 📊 Additional Stats: Top Types, Top Users, Failed Today -->
        <!-- ============================================================== -->
        <div class="row g-3 mb-4">
            <!-- 🏷️ Top Activity Types -->
            <div class="col-md-4">
                <div class="stat-card p-3">
                    <h6 class="text-muted mb-2"><i class="fas fa-chart-bar me-1"></i>Top Activity Types</h6>
                    <div id="topTypesContainer">
                        <span class="spinner-border spinner-border-sm" role="status"></span>
                    </div>
                </div>
            </div>
            <!-- 👤 Most Active Users -->
            <div class="col-md-4">
                <div class="stat-card p-3">
                    <h6 class="text-muted mb-2"><i class="fas fa-user-clock me-1"></i>Most Active Users</h6>
                    <div id="topUsersContainer">
                        <span class="spinner-border spinner-border-sm" role="status"></span>
                    </div>
                </div>
            </div>
            <!-- ❌ Failed Activities Today -->
            <div class="col-md-4">
                <div class="stat-card p-3">
                    <h6 class="text-muted mb-2"><i class="fas fa-exclamation-triangle me-1"></i>Failed Today</h6>
                    <div id="failedTodayContainer">
                        <span class="spinner-border spinner-border-sm" role="status"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ==============================================================  -->
        <!-- 🔍 Filter Panel (Collapsible) -->
        <!-- ============================================================== -->
        <div class="filter-card card mb-4">
            <div class="card-header" data-bs-toggle="collapse" data-bs-target="#filterPanel" aria-expanded="true" aria-controls="filterPanel">
                <div class="d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-filter me-2"></i>Filters</span>
                    <i class="fas fa-chevron-down" id="filterChevron"></i>
                </div>
            </div>
            <div class="collapse show" id="filterPanel">
                <div class="card-body">
                    <form id="filterForm" onsubmit="return false;">
                        <div class="row g-3">
                            <!-- 📅 Date From -->
                            <div class="col-md-3 col-sm-6">
                                <label for="filterDateFrom" class="form-label form-label-sm">
                                    <i class="fas fa-calendar me-1"></i>Date From
                                </label>
                                <input type="date" class="form-control form-control-sm" id="filterDateFrom" name="date_from">
                            </div>
                            <!-- 📅 Date To -->
                            <div class="col-md-3 col-sm-6">
                                <label for="filterDateTo" class="form-label form-label-sm">
                                    <i class="fas fa-calendar me-1"></i>Date To
                                </label>
                                <input type="date" class="form-control form-control-sm" id="filterDateTo" name="date_to">
                            </div>
                            <!-- 👤 User Search -->
                            <div class="col-md-3 col-sm-6">
                                <label for="filterUserId" class="form-label form-label-sm">
                                    <i class="fas fa-user me-1"></i>User ID
                                </label>
                                <input type="number" class="form-control form-control-sm" id="filterUserId" name="user_id"
                                       placeholder="Enter user ID" min="1">
                            </div>
                            <!-- 🏷️ Activity Type -->
                            <div class="col-md-3 col-sm-6">
                                <label for="filterActivityType" class="form-label form-label-sm">
                                    <i class="fas fa-tag me-1"></i>Activity Type
                                </label>
                                <select class="form-select form-select-sm" id="filterActivityType" name="activity_type">
                                    <option value="">All Types</option>
                                    <?php foreach ($activityTypes as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- 📂 Category (Severity) -->
                            <div class="col-md-3 col-sm-6">
                                <label for="filterCategory" class="form-label form-label-sm">
                                    <i class="fas fa-folder me-1"></i>Category
                                </label>
                                <select class="form-select form-select-sm" id="filterCategory" name="category">
                                    <option value="">All Categories</option>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                            <!-- ✅ Result -->
                            <div class="col-md-3 col-sm-6">
                                <label for="filterResult" class="form-label form-label-sm">
                                    <i class="fas fa-check-circle me-1"></i>Result
                                </label>
                                <select class="form-select form-select-sm" id="filterResult" name="result">
                                    <option value="">All Results</option>
                                    <option value="success">Success</option>
                                    <option value="failure">Failure</option>
                                    <option value="warning">Warning</option>
                                    <option value="info">Info</option>
                                </select>
                            </div>
                            <!-- 🌐 IP Address -->
                            <div class="col-md-3 col-sm-6">
                                <label for="filterIpAddress" class="form-label form-label-sm">
                                    <i class="fas fa-network-wired me-1"></i>IP Address
                                </label>
                                <input type="text" class="form-control form-control-sm" id="filterIpAddress" name="ip_address"
                                       placeholder="e.g. 192.168.1.1">
                            </div>
                            <!-- 🔎 Search -->
                            <div class="col-md-3 col-sm-6">
                                <label for="filterSearch" class="form-label form-label-sm">
                                    <i class="fas fa-search me-1"></i>Search Details
                                </label>
                                <input type="text" class="form-control form-control-sm" id="filterSearch" name="search"
                                       placeholder="Search activity details...">
                            </div>
                        </div>
                        <!-- 🎛️ Filter Action Buttons -->
                        <div class="d-flex justify-content-between mt-3">
                            <div>
                                <button type="button" class="btn btn-primary btn-sm" id="btnApplyFilters">
                                    <i class="fas fa-search me-1"></i>Apply Filters
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm ms-2" id="btnResetFilters">
                                    <i class="fas fa-times me-1"></i>Reset
                                </button>
                            </div>
                            <button type="button" class="btn btn-outline-success btn-sm" id="btnExportCSV">
                                <i class="fas fa-file-csv me-1"></i>Export CSV
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ==============================================================  -->
        <!-- 📋 Log Data Table -->
        <!-- ============================================================== -->
        <div class="log-card">
            <div class="p-3 d-flex justify-content-between align-items-center border-bottom">
                <h6 class="mb-0"><i class="fas fa-table me-2"></i>Activity Log Entries</h6>
                <div>
                    <label class="form-label-sm me-2 text-muted" for="perPageSelect">Per page:</label>
                    <select class="form-select form-select-sm d-inline-block" style="width: auto;" id="perPageSelect">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                    </select>
                </div>
            </div>

            <!-- 📊 Table -->
            <div class="table-responsive" id="logTableContainer">
                <table class="table table-logs table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>User</th>
                            <th>Activity</th>
                            <th>Severity</th>
                            <th>Result</th>
                            <th>IP Address</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="logTableBody">
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                                Loading audit log entries...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 📐 Pagination Controls -->
            <div class="pagination-controls" id="paginationControls">
                <div class="pagination-info" id="paginationInfo">
                    Showing 0 entries
                </div>
                <nav aria-label="Log pagination">
                    <ul class="pagination pagination-sm mb-0" id="paginationNav">
                        <!-- Pagination buttons rendered by JavaScript -->
                    </ul>
                </nav>
            </div>
        </div>

    </div><!-- /.container-fluid -->

    <!-- ================================================================== -->
    <!-- 🔍 Log Detail Modal -->
    <!-- @see https://getbootstrap.com/docs/5.3/components/modal/ -->
    <!-- ================================================================== -->
    <div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #2563eb, #7c3aed); color: white;">
                    <h5 class="modal-title" id="detailModalLabel">
                        <i class="fas fa-info-circle me-2"></i>Log Entry Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="detailModalBody">
                    <!-- Populated dynamically by viewDetail() -->
                    <div class="text-center py-4">
                        <span class="spinner-border" role="status"></span>
                        <p class="mt-2 text-muted">Loading details...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================== -->
    <!-- 📦 External JavaScript — Bootstrap 5.3.2 Bundle -->
    <!-- ================================================================== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- ================================================================== -->
    <!-- 🎯 Page JavaScript — Audit Log Viewer Logic -->
    <!-- ================================================================== -->
    <script>
    (function() {
        'use strict';

        // ====================================================================
        // 🔧 Configuration Constants
        // ====================================================================

        /**
         * 📡 API endpoint URL for audit log actions
         * All AJAX requests are routed through this single endpoint
         */
        const API_URL = '/admin/api/audit-log-actions.php';

        /**
         * 🔐 CSRF token for POST requests (generated server-side)
         */
        const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

        /**
         * 📐 Current pagination state
         */
        let currentPage = 1;
        let currentPerPage = 50;
        let totalPages = 1;

        // ====================================================================
        // 🏁 Initialisation — Load data when DOM is ready
        // ====================================================================
        document.addEventListener('DOMContentLoaded', function() {
            // 📊 Load summary statistics
            loadStats();

            // 📋 Load initial log entries
            loadLogs(1);

            // 🎛️ Bind event listeners
            bindEventListeners();
        });

        // ====================================================================
        // 🎛️ Event Listener Bindings
        // ====================================================================

        /**
         * 🎛️ Bind all UI event listeners for filters, pagination, and actions
         */
        function bindEventListeners() {
            // 🔍 Apply Filters button
            document.getElementById('btnApplyFilters').addEventListener('click', function() {
                currentPage = 1;
                loadLogs(1);
            });

            // 🔄 Reset Filters button
            document.getElementById('btnResetFilters').addEventListener('click', resetFilters);

            // 📥 Export CSV button
            document.getElementById('btnExportCSV').addEventListener('click', exportCSV);

            // 🔄 Refresh button
            document.getElementById('btnRefresh').addEventListener('click', function() {
                loadStats();
                loadLogs(currentPage);
            });

            // 📐 Per-page selector
            document.getElementById('perPageSelect').addEventListener('change', function() {
                currentPerPage = parseInt(this.value, 10);
                currentPage = 1;
                loadLogs(1);
            });

            // ⌨️ Enter key in filter inputs triggers search
            document.querySelectorAll('#filterForm input, #filterForm select').forEach(function(el) {
                el.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        currentPage = 1;
                        loadLogs(1);
                    }
                });
            });

            // 🔽 Toggle chevron icon on filter panel collapse
            var filterPanel = document.getElementById('filterPanel');
            if (filterPanel) {
                filterPanel.addEventListener('shown.bs.collapse', function() {
                    document.getElementById('filterChevron').classList.replace('fa-chevron-down', 'fa-chevron-up');
                });
                filterPanel.addEventListener('hidden.bs.collapse', function() {
                    document.getElementById('filterChevron').classList.replace('fa-chevron-up', 'fa-chevron-down');
                });
            }
        }

        // ====================================================================
        // 📊 loadStats() — Fetch and render summary statistics
        // ====================================================================

        /**
         * 📊 Load summary statistics from the API and populate the stat cards
         *
         * Fetches: totals, top_types, top_users, failed_today
         *
         * @see handleGetStats() in audit-log-actions.php
         */
        function loadStats() {
            fetch(API_URL + '?action=get_stats')
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (!data.success) {
                        console.error('Stats error:', data.error);
                        return;
                    }

                    var stats = data.data;

                    // 📊 Update stat cards with totals
                    document.getElementById('statTotal').textContent = formatNumber(stats.totals.total);
                    document.getElementById('statToday').textContent = formatNumber(stats.totals.today);
                    document.getElementById('statUsers').textContent = formatNumber(stats.totals.unique_users);
                    document.getElementById('statIPs').textContent = formatNumber(stats.totals.unique_ips);

                    // 🏷️ Render top activity types
                    var topTypesHtml = '';
                    if (stats.top_types && stats.top_types.length > 0) {
                        stats.top_types.forEach(function(item) {
                            topTypesHtml += '<div class="top-item">' +
                                '<span class="top-item-label" title="' + escapeHtml(item.type) + '">' + escapeHtml(item.type) + '</span>' +
                                '<span class="top-item-count">' + formatNumber(item.count) + '</span>' +
                                '</div>';
                        });
                    } else {
                        topTypesHtml = '<p class="text-muted small mb-0">No data available</p>';
                    }
                    document.getElementById('topTypesContainer').innerHTML = topTypesHtml;

                    // 👤 Render top users
                    var topUsersHtml = '';
                    if (stats.top_users && stats.top_users.length > 0) {
                        stats.top_users.forEach(function(item) {
                            topUsersHtml += '<div class="top-item">' +
                                '<span class="top-item-label" title="' + escapeHtml(item.name) + '">' +
                                '<i class="fas fa-user-circle me-1 text-muted"></i>' + escapeHtml(item.name) +
                                '</span>' +
                                '<span class="top-item-count">' + formatNumber(item.count) + '</span>' +
                                '</div>';
                        });
                    } else {
                        topUsersHtml = '<p class="text-muted small mb-0">No data available</p>';
                    }
                    document.getElementById('topUsersContainer').innerHTML = topUsersHtml;

                    // ❌ Render failed today count
                    var failedCount = stats.failed_today || 0;
                    var failedClass = failedCount > 0 ? 'text-danger' : 'text-success';
                    var failedIcon = failedCount > 0 ? 'fa-exclamation-circle' : 'fa-check-circle';
                    document.getElementById('failedTodayContainer').innerHTML =
                        '<div class="text-center py-2">' +
                        '<div class="' + failedClass + '" style="font-size: 2rem;">' +
                        '<i class="fas ' + failedIcon + '"></i>' +
                        '</div>' +
                        '<div class="stat-number ' + failedClass + '">' + formatNumber(failedCount) + '</div>' +
                        '<div class="stat-label">failed activities today</div>' +
                        '</div>';
                })
                .catch(function(error) {
                    console.error('Failed to load stats:', error);
                    // 🔇 Gracefully handle — show dashes for unavailable data
                    ['statTotal', 'statToday', 'statUsers', 'statIPs'].forEach(function(id) {
                        document.getElementById(id).textContent = '—';
                    });
                    document.getElementById('topTypesContainer').innerHTML = '<p class="text-danger small mb-0">Failed to load</p>';
                    document.getElementById('topUsersContainer').innerHTML = '<p class="text-danger small mb-0">Failed to load</p>';
                    document.getElementById('failedTodayContainer').innerHTML = '<p class="text-danger small mb-0">Failed to load</p>';
                });
        }

        // ====================================================================
        // 📋 loadLogs(page) — Fetch and render paginated log entries
        // ====================================================================

        /**
         * 📋 Load paginated log entries from the API with current filters
         *
         * Builds query parameters from the filter form inputs and requests
         * the specified page of results. Updates the table body and
         * pagination controls.
         *
         * @param {number} page — Page number to fetch (1-based)
         *
         * @see handleGetLogs() in audit-log-actions.php
         */
        function loadLogs(page) {
            currentPage = page;

            // 🏗️ Build query parameters from filter form
            var params = new URLSearchParams();
            params.set('action', 'get_logs');
            params.set('page', page.toString());
            params.set('per_page', currentPerPage.toString());

            // 📥 Collect filter values
            var filters = {
                'date_from': document.getElementById('filterDateFrom').value,
                'date_to': document.getElementById('filterDateTo').value,
                'user_id': document.getElementById('filterUserId').value,
                'activity_type': document.getElementById('filterActivityType').value,
                'category': document.getElementById('filterCategory').value,
                'result': document.getElementById('filterResult').value,
                'ip_address': document.getElementById('filterIpAddress').value,
                'search': document.getElementById('filterSearch').value,
            };

            // 🔍 Only add non-empty filters to the query string
            for (var key in filters) {
                if (filters[key] !== '') {
                    params.set(key, filters[key]);
                }
            }

            // ⏳ Show loading state in table
            document.getElementById('logTableBody').innerHTML =
                '<tr><td colspan="7" class="text-center py-5">' +
                '<span class="spinner-border spinner-border-sm me-2" role="status"></span>' +
                'Loading audit log entries...</td></tr>';

            // 📡 Fetch data from API
            fetch(API_URL + '?' + params.toString())
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (!data.success) {
                        document.getElementById('logTableBody').innerHTML =
                            '<tr><td colspan="7" class="text-center py-4 text-danger">' +
                            '<i class="fas fa-exclamation-circle me-1"></i>' +
                            escapeHtml(data.error || 'Failed to load logs') +
                            '</td></tr>';
                        return;
                    }

                    totalPages = data.total_pages;
                    renderLogTable(data.data);
                    renderPagination(data.page, data.total_pages, data.total, data.per_page);
                })
                .catch(function(error) {
                    console.error('Failed to load logs:', error);
                    document.getElementById('logTableBody').innerHTML =
                        '<tr><td colspan="7" class="text-center py-4 text-danger">' +
                        '<i class="fas fa-exclamation-circle me-1"></i>' +
                        'Failed to load audit log entries. Please try again.' +
                        '</td></tr>';
                });
        }

        // ====================================================================
        // 📊 renderLogTable(logs) — Build table rows from log data
        // ====================================================================

        /**
         * 📊 Render the log entries into the table body
         *
         * @param {Array} logs — Array of log entry objects from the API
         */
        function renderLogTable(logs) {
            var tbody = document.getElementById('logTableBody');

            // 📭 Empty state
            if (!logs || logs.length === 0) {
                tbody.innerHTML =
                    '<tr><td colspan="7" class="text-center py-5 text-muted">' +
                    '<i class="fas fa-inbox fa-2x mb-2 d-block"></i>' +
                    'No log entries found matching your filters.' +
                    '</td></tr>';
                return;
            }

            // 🏗️ Build table rows
            var html = '';
            logs.forEach(function(log) {
                // 🏷️ Result badge colour mapping
                var resultBadgeClass = 'badge-info';
                switch (log.activityResult) {
                    case 'success': resultBadgeClass = 'badge-success'; break;
                    case 'failure': resultBadgeClass = 'badge-failure'; break;
                    case 'warning': resultBadgeClass = 'badge-warning'; break;
                    case 'info':    resultBadgeClass = 'badge-info';    break;
                }

                // 📅 Format date for display
                var dateStr = formatDate(log.createdAt);

                // 👤 User display (name or "System" for null userID)
                var userDisplay = log.userID
                    ? '<span title="User ID: ' + log.userID + '">' + escapeHtml(log.displayName) + '</span>'
                    : '<span class="text-muted"><i class="fas fa-robot me-1"></i>System</span>';

                html += '<tr>' +
                    '<td class="text-nowrap"><small>' + escapeHtml(dateStr) + '</small></td>' +
                    '<td>' + userDisplay + '</td>' +
                    '<td><code class="text-dark">' + escapeHtml(log.activityType) + '</code></td>' +
                    '<td><small class="text-muted">' + escapeHtml(log.severity || 'low') + '</small></td>' +
                    '<td><span class="badge ' + resultBadgeClass + '">' + escapeHtml(log.activityResult) + '</span></td>' +
                    '<td><small class="font-monospace">' + escapeHtml(log.ipAddress || '—') + '</small></td>' +
                    '<td class="text-center">' +
                    '<button class="btn btn-outline-primary btn-sm" onclick="viewDetail(' + log.activityID + ')" title="View full details">' +
                    '<i class="fas fa-eye"></i>' +
                    '</button>' +
                    '</td>' +
                    '</tr>';
            });

            tbody.innerHTML = html;
        }

        // ====================================================================
        // 📐 renderPagination() — Build pagination controls
        // ====================================================================

        /**
         * 📐 Render pagination controls based on current page state
         *
         * @param {number} page       — Current page number
         * @param {number} totalPages — Total number of pages
         * @param {number} total      — Total number of matching records
         * @param {number} perPage    — Number of records per page
         */
        function renderPagination(page, totalPages, total, perPage) {
            // 📊 Update pagination info text
            var start = total > 0 ? ((page - 1) * perPage) + 1 : 0;
            var end = Math.min(page * perPage, total);
            document.getElementById('paginationInfo').textContent =
                'Showing ' + formatNumber(start) + '–' + formatNumber(end) + ' of ' + formatNumber(total) + ' entries';

            // 🏗️ Build pagination nav buttons
            var nav = document.getElementById('paginationNav');
            var html = '';

            // ⬅️ Previous button
            html += '<li class="page-item ' + (page <= 1 ? 'disabled' : '') + '">' +
                '<a class="page-link" href="#" onclick="goToPage(' + (page - 1) + '); return false;">' +
                '<i class="fas fa-chevron-left"></i></a></li>';

            // 📄 Page number buttons (show max 7 pages with ellipsis)
            var startPage = Math.max(1, page - 3);
            var endPage = Math.min(totalPages, page + 3);

            if (startPage > 1) {
                html += '<li class="page-item"><a class="page-link" href="#" onclick="goToPage(1); return false;">1</a></li>';
                if (startPage > 2) {
                    html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
            }

            for (var i = startPage; i <= endPage; i++) {
                html += '<li class="page-item ' + (i === page ? 'active' : '') + '">' +
                    '<a class="page-link" href="#" onclick="goToPage(' + i + '); return false;">' + i + '</a></li>';
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
                html += '<li class="page-item"><a class="page-link" href="#" onclick="goToPage(' + totalPages + '); return false;">' + totalPages + '</a></li>';
            }

            // ➡️ Next button
            html += '<li class="page-item ' + (page >= totalPages ? 'disabled' : '') + '">' +
                '<a class="page-link" href="#" onclick="goToPage(' + (page + 1) + '); return false;">' +
                '<i class="fas fa-chevron-right"></i></a></li>';

            nav.innerHTML = html;
        }

        // ====================================================================
        // 🔍 viewDetail(logId) — Open detail modal for a log entry
        // ====================================================================

        /**
         * 🔍 Fetch and display the full details of a single log entry
         * in the Bootstrap modal dialog.
         *
         * @param {number} logId — The activityID of the log entry to view
         *
         * @see handleGetLogDetail() in audit-log-actions.php
         */
        window.viewDetail = function(logId) {
            var modalBody = document.getElementById('detailModalBody');

            // ⏳ Show loading spinner in modal
            modalBody.innerHTML =
                '<div class="text-center py-4">' +
                '<span class="spinner-border" role="status"></span>' +
                '<p class="mt-2 text-muted">Loading details...</p></div>';

            // 🔓 Open the modal
            var modal = new bootstrap.Modal(document.getElementById('detailModal'));
            modal.show();

            // 📡 Fetch log detail from API
            fetch(API_URL + '?action=get_log_detail&log_id=' + logId)
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (!data.success) {
                        modalBody.innerHTML =
                            '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i>' +
                            escapeHtml(data.error || 'Failed to load details') + '</div>';
                        return;
                    }

                    var log = data.data;

                    // 🏷️ Result badge
                    var resultBadgeClass = 'badge-info';
                    switch (log.activityResult) {
                        case 'success': resultBadgeClass = 'badge-success'; break;
                        case 'failure': resultBadgeClass = 'badge-failure'; break;
                        case 'warning': resultBadgeClass = 'badge-warning'; break;
                        case 'info':    resultBadgeClass = 'badge-info';    break;
                    }

                    // 🏗️ Build detail view HTML
                    var html = '<div class="row g-3">';

                    // 📋 Log Entry Info
                    html += '<div class="col-12"><h6 class="text-muted border-bottom pb-2"><i class="fas fa-info-circle me-1"></i>Log Entry Information</h6></div>';
                    html += detailRow('Activity ID', '#' + log.activityID);
                    html += detailRow('Date/Time', formatDate(log.createdAt));
                    html += detailRow('Activity Type', '<code>' + escapeHtml(log.activityType) + '</code>');
                    html += detailRow('Severity', escapeHtml(log.severity || 'low'));
                    html += detailRow('Result', '<span class="badge ' + resultBadgeClass + '">' + escapeHtml(log.activityResult) + '</span>');

                    // 👤 User Info
                    html += '<div class="col-12 mt-3"><h6 class="text-muted border-bottom pb-2"><i class="fas fa-user me-1"></i>User Information</h6></div>';
                    if (log.userID) {
                        html += detailRow('User ID', log.userID);
                        html += detailRow('Display Name', escapeHtml(log.displayName));
                        html += detailRow('Email', escapeHtml(log.email));
                        html += detailRow('Name', escapeHtml((log.firstName || '') + ' ' + (log.lastName || '')).trim() || '—');
                        html += detailRow('Account Status', escapeHtml(log.accountStatus || '—'));
                    } else {
                        html += detailRow('User', '<span class="text-muted"><i class="fas fa-robot me-1"></i>System Event</span>');
                    }

                    // 🌐 Request Info
                    html += '<div class="col-12 mt-3"><h6 class="text-muted border-bottom pb-2"><i class="fas fa-globe me-1"></i>Request Information</h6></div>';
                    html += detailRow('IP Address', '<span class="font-monospace">' + escapeHtml(log.ipAddress || '—') + '</span>');
                    html += detailRow('Request URI', '<small class="font-monospace">' + escapeHtml(log.requestUri || '—') + '</small>');

                    // 📝 Activity Details
                    html += '<div class="col-12 mt-3"><h6 class="text-muted border-bottom pb-2"><i class="fas fa-file-alt me-1"></i>Activity Details</h6></div>';
                    html += '<div class="col-12">';
                    if (log.activityDetails) {
                        // 🔍 Try to parse as JSON for formatted display
                        try {
                            var parsed = JSON.parse(log.activityDetails);
                            html += '<div class="detail-json">' + escapeHtml(JSON.stringify(parsed, null, 2)) + '</div>';
                        } catch (e) {
                            // 📄 Plain text display
                            html += '<div class="detail-json">' + escapeHtml(log.activityDetails) + '</div>';
                        }
                    } else {
                        html += '<p class="text-muted">No additional details recorded.</p>';
                    }
                    html += '</div>';

                    // 🖥️ User Agent
                    html += '<div class="col-12 mt-3"><h6 class="text-muted border-bottom pb-2"><i class="fas fa-desktop me-1"></i>User Agent</h6></div>';
                    html += '<div class="col-12">';
                    if (log.userAgent) {
                        html += '<div class="detail-json" style="max-height: 100px;">' + escapeHtml(log.userAgent) + '</div>';
                    } else {
                        html += '<p class="text-muted">No user agent recorded.</p>';
                    }
                    html += '</div>';

                    html += '</div>'; // .row

                    modalBody.innerHTML = html;
                })
                .catch(function(error) {
                    console.error('Failed to load log detail:', error);
                    modalBody.innerHTML =
                        '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i>' +
                        'Failed to load log entry details. Please try again.</div>';
                });
        };

        // ====================================================================
        // 📥 exportCSV() — Trigger CSV download with current filters
        // ====================================================================

        /**
         * 📥 Build the export URL with current filter parameters and
         * trigger a file download by opening in a new window.
         *
         * @see handleExportLogs() in audit-log-actions.php
         */
        function exportCSV() {
            // 🏗️ Build query parameters matching current filters
            var params = new URLSearchParams();
            params.set('action', 'export_logs');

            var filters = {
                'date_from': document.getElementById('filterDateFrom').value,
                'date_to': document.getElementById('filterDateTo').value,
                'user_id': document.getElementById('filterUserId').value,
                'activity_type': document.getElementById('filterActivityType').value,
                'category': document.getElementById('filterCategory').value,
                'result': document.getElementById('filterResult').value,
                'ip_address': document.getElementById('filterIpAddress').value,
                'search': document.getElementById('filterSearch').value,
            };

            for (var key in filters) {
                if (filters[key] !== '') {
                    params.set(key, filters[key]);
                }
            }

            // 📥 Trigger download by navigating to the CSV endpoint
            window.location.href = API_URL + '?' + params.toString();
        }

        // ====================================================================
        // 🔄 resetFilters() — Clear all filter inputs and reload
        // ====================================================================

        /**
         * 🔄 Clear all filter form inputs and reload the first page of logs
         */
        function resetFilters() {
            // 🧹 Clear all filter inputs
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterUserId').value = '';
            document.getElementById('filterActivityType').value = '';
            document.getElementById('filterCategory').value = '';
            document.getElementById('filterResult').value = '';
            document.getElementById('filterIpAddress').value = '';
            document.getElementById('filterSearch').value = '';

            // 📋 Reload from page 1
            currentPage = 1;
            loadLogs(1);
        }

        // ====================================================================
        // 📐 goToPage(page) — Navigate to a specific page
        // ====================================================================

        /**
         * 📐 Navigate to a specific page number (called from pagination buttons)
         *
         * @param {number} page — The page number to navigate to
         */
        window.goToPage = function(page) {
            if (page < 1 || page > totalPages) {
                return;
            }
            loadLogs(page);

            // 📜 Scroll to top of table for better UX
            document.getElementById('logTableContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
        };

        // ====================================================================
        // 🔧 Utility Functions
        // ====================================================================

        /**
         * 🛡️ Escape HTML entities to prevent XSS in dynamic content
         *
         * @param {string} text — Raw text to escape
         * @returns {string} — HTML-safe text
         *
         * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
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
         * 📅 Format a datetime string for display
         *
         * @param {string} dateStr — ISO/MySQL datetime string (e.g. "2026-03-08 14:30:00")
         * @returns {string} — Formatted date string
         */
        function formatDate(dateStr) {
            if (!dateStr) {
                return '—';
            }
            try {
                var d = new Date(dateStr);
                // 📅 Format: DD/MM/YYYY HH:MM:SS
                return d.toLocaleDateString('en-GB', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                }) + ' ' + d.toLocaleTimeString('en-GB', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
            } catch (e) {
                return dateStr;
            }
        }

        /**
         * 🔢 Format a number with locale-appropriate thousand separators
         *
         * @param {number} num — Number to format
         * @returns {string} — Formatted number string
         */
        function formatNumber(num) {
            if (num === null || num === undefined) {
                return '0';
            }
            return Number(num).toLocaleString();
        }

        /**
         * 📋 Build a detail row for the modal (label + value)
         *
         * @param {string} label — Field label
         * @param {string} value — Field value (can contain HTML)
         * @returns {string} — HTML string for the detail row
         */
        function detailRow(label, value) {
            return '<div class="col-sm-4"><span class="detail-label">' + escapeHtml(label) + '</span></div>' +
                   '<div class="col-sm-8"><span class="detail-value">' + value + '</span></div>';
        }
    })();
    </script>
</body>
</html>
<?php
// ✅ Audit Log Viewer page loaded successfully
