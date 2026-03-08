<?php
/**
 * ============================================================================
 * 📊 SIGNula - Usage Metrics & Rates Management (Super Admin)
 * ============================================================================
 *
 * Purpose: Super Admin dashboard for managing usage metrics, usage rates per
 *          subscription tier, browsing raw usage records, and reviewing billing
 *          summaries. Provides full CRUD for metrics and rates, with filterable
 *          DataTables, lazy-loaded tabs, and AJAX-driven batch billing.
 *
 * Features:
 *   - 📊 Stats cards: Total Metrics, Total Rates, Unprocessed Records, Pending Summaries
 *   - 📋 Tab 1 — Usage Metrics: DataTable with scope/status filters, Add/Edit/Delete
 *   - 💰 Tab 2 — Usage Rates: Tier selector, DataTable per tier, Add/Edit/Delete
 *   - 📝 Tab 3 — Usage Records Browser: Date/user/metric/status filters, paginated
 *   - 🧾 Tab 4 — Billing Summaries: Status/user/date filters, detail modal with line items
 *   - ⚙️ Process Billing modal for batch processing trigger
 *
 * Authentication: Super Admin only (isSuperAdmin = 1)
 *
 * PHP Version: 8.3+
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons — FontAwesome 6.4 Icon Library
 * @see https://datatables.net/ — DataTables jQuery Plugin
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\Payments\UsageMetrics
 * @version    1.0.0
 * @since      2.6.0-beta
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

// ============================================================================
// 🔐 AUTHENTICATION & AUTHORIZATION
// ============================================================================

// 🗄️ Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check — user must be logged in
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin check — only super admins can manage usage metrics
$accessControl->requireSuperAdmin();

// ============================================================================
// 📊 SERVER-SIDE STATISTICS — Quick stats for initial page render
// These are rendered server-side so the page displays data immediately,
// without waiting for the first AJAX call to complete.
// ============================================================================

/**
 * 📊 Total active usage metrics count
 * @see https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html
 */
$metricsStmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM tblUsageMetrics WHERE isActive = 1"
);
$metricsStmt->execute();
$totalMetrics = (int)($metricsStmt->get_result()->fetch_assoc()['cnt'] ?? 0);

/**
 * 💰 Total active usage rates count
 */
$ratesStmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM tblUsageRates WHERE isActive = 1"
);
$ratesStmt->execute();
$totalRates = (int)($ratesStmt->get_result()->fetch_assoc()['cnt'] ?? 0);

/**
 * 📝 Unprocessed usage records count
 */
$unprocessedStmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM tblUsageRecords WHERE isProcessed = 0"
);
$unprocessedStmt->execute();
$unprocessedRecords = (int)($unprocessedStmt->get_result()->fetch_assoc()['cnt'] ?? 0);

/**
 * 🧾 Pending billing summaries count
 */
$pendingStmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM tblBillingSummaries WHERE status = 'pending'"
);
$pendingStmt->execute();
$pendingSummaries = (int)($pendingStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Usage Metrics &amp; Rates - SIGNula Admin</title>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- 🎨 Bootstrap 5.3.2 CSS (CDN with SRI)                         -->
    <!-- @see https://getbootstrap.com/docs/5.3/ — Bootstrap Docs       -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- 🎯 FontAwesome 6.4.2 CSS (CDN with SRI)                       -->
    <!-- @see https://fontawesome.com/icons — FontAwesome Icon Library  -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- 📋 DataTables CSS (CDN) — for sortable, searchable tables      -->
    <!-- @see https://datatables.net/ — DataTables Documentation        -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <link rel="stylesheet"
          href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css"
          crossorigin="anonymous">

    <style>
        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Base body styling — light grey background matching admin    */
        /* ═══════════════════════════════════════════════════════════════ */
        body { background-color: #f8f9fa; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Page header — green/teal gradient matching payments theme   */
        /* Identical to service-fees.php and payments/index.php headers   */
        /* ═══════════════════════════════════════════════════════════════ */
        .page-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📊 Stat cards — summary statistics at top of page              */
        /* Replicates .stat-card pattern from payments/index.php          */
        /* ═══════════════════════════════════════════════════════════════ */
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
            text-align: center;
            cursor: default;
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

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📋 Table card — white container with gradient header           */
        /* Matches .table-card from payments dashboard and providers page */
        /* ═══════════════════════════════════════════════════════════════ */
        .table-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .table-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            padding: 1rem 1.5rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔍 Filter bar — search & filter controls above tables          */
        /* ═══════════════════════════════════════════════════════════════ */
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🏷️ Status badges — colour-coded for quick scanning             */
        /* ═══════════════════════════════════════════════════════════════ */
        .badge-active   { background-color: #28a745; }
        .badge-inactive { background-color: #6c757d; }
        .badge-pending  { background-color: #ffc107; color: #333; }
        .badge-processed { background-color: #0d6efd; }
        .badge-invoiced { background-color: #6f42c1; }
        .badge-paid     { background-color: #28a745; }
        .badge-disputed { background-color: #dc3545; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔄 Loading overlay — spinner shown during AJAX operations      */
        /* ═══════════════════════════════════════════════════════════════ */
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

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📱 Modal header gradient — consistent with other admin modals  */
        /* ═══════════════════════════════════════════════════════════════ */
        .modal-header-gradient {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎴 Tab navigation — custom styling with teal active indicator  */
        /* @see https://getbootstrap.com/docs/5.3/components/navs-tabs/  */
        /* ═══════════════════════════════════════════════════════════════ */
        .nav-tabs .nav-link {
            color: #495057;
            font-weight: 500;
            border: none;
            padding: 0.75rem 1.25rem;
        }
        .nav-tabs .nav-link.active {
            color: #11998e;
            border-bottom: 3px solid #11998e;
            background: transparent;
        }
        .nav-tabs .nav-link:hover:not(.active) {
            color: #11998e;
            border-color: transparent;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📱 Modal detail sections — teal left border accent             */
        /* ═══════════════════════════════════════════════════════════════ */
        .detail-section {
            border-left: 3px solid #11998e;
            padding-left: 1rem;
            margin-bottom: 1rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📋 DataTables overrides — match admin theme styling            */
        /* ═══════════════════════════════════════════════════════════════ */
        .dataTables_wrapper .dataTables_filter input {
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
        .dataTables_wrapper .dataTables_length select {
            border-radius: 6px;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🍞 Toast container — fixed position for notifications          */
        /* ═══════════════════════════════════════════════════════════════ */
        .toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 9999;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📱 Responsive adjustments for smaller screens                  */
        /* ═══════════════════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .stat-card .stat-number { font-size: 1.5rem; }
            .stat-card { padding: 1rem; }
            .page-header { padding: 1.5rem; }
            .nav-tabs .nav-link { padding: 0.5rem 0.75rem; font-size: 0.9rem; }
        }
    </style>
</head>
<body>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- 🍞 TOAST NOTIFICATION CONTAINER                                -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="toast-container" id="toastContainer"></div>

    <div class="container-fluid py-4">

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🎨 PAGE HEADER — Green gradient matching payments dashboard   -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="page-header">
            <!-- 🔙 Back navigation link to payments dashboard -->
            <a href="index.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Payments Dashboard
            </a>
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">
                        <i class="fas fa-chart-bar me-2"></i>Usage Metrics &amp; Rates
                    </h1>
                    <p class="mb-0 opacity-75">
                        Manage usage metrics, configure per-tier rates, browse usage records,
                        and review billing summaries across all partners and users.
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <!-- 🔄 Refresh button — reloads all data via AJAX -->
                    <button class="btn btn-outline-light btn-lg" onclick="refreshAll()">
                        <i class="fas fa-sync me-2"></i>Refresh
                    </button>
                </div>
            </div>
        </div>

        <!-- 💬 Alert notification container — dynamically populated by showAlert() -->
        <div id="alertContainer"></div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📊 STATS CARDS — Summary statistics row                       -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="row g-3 mb-4">
            <!-- 📊 Total active metrics -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-success" id="stat-total-metrics">
                        <?php echo (int)$totalMetrics; ?>
                    </div>
                    <div class="stat-label">
                        <i class="fas fa-chart-line me-1"></i>Active Metrics
                    </div>
                </div>
            </div>

            <!-- 💰 Total active rates -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-primary" id="stat-total-rates">
                        <?php echo (int)$totalRates; ?>
                    </div>
                    <div class="stat-label">
                        <i class="fas fa-money-bill-wave me-1"></i>Active Rates
                    </div>
                </div>
            </div>

            <!-- 📝 Unprocessed usage records -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-warning" id="stat-unprocessed-records">
                        <?php echo (int)$unprocessedRecords; ?>
                    </div>
                    <div class="stat-label">
                        <i class="fas fa-clock me-1"></i>Unprocessed Records
                    </div>
                </div>
            </div>

            <!-- 🧾 Pending billing summaries -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-info" id="stat-pending-summaries">
                        <?php echo (int)$pendingSummaries; ?>
                    </div>
                    <div class="stat-label">
                        <i class="fas fa-file-invoice me-1"></i>Pending Summaries
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🎴 TABBED INTERFACE — Four main content tabs                   -->
        <!-- @see https://getbootstrap.com/docs/5.3/components/navs-tabs/  -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <ul class="nav nav-tabs mb-4" id="mainTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-metrics" data-bs-toggle="tab"
                        data-bs-target="#pane-metrics" type="button" role="tab"
                        aria-controls="pane-metrics" aria-selected="true">
                    <i class="fas fa-chart-line me-1"></i>Usage Metrics
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-rates" data-bs-toggle="tab"
                        data-bs-target="#pane-rates" type="button" role="tab"
                        aria-controls="pane-rates" aria-selected="false">
                    <i class="fas fa-money-bill-wave me-1"></i>Usage Rates
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-records" data-bs-toggle="tab"
                        data-bs-target="#pane-records" type="button" role="tab"
                        aria-controls="pane-records" aria-selected="false">
                    <i class="fas fa-list-ol me-1"></i>Usage Records
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-summaries" data-bs-toggle="tab"
                        data-bs-target="#pane-summaries" type="button" role="tab"
                        aria-controls="pane-summaries" aria-selected="false">
                    <i class="fas fa-file-invoice-dollar me-1"></i>Billing Summaries
                </button>
            </li>
        </ul>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📋 TAB CONTENT PANES                                          -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="tab-content" id="mainTabContent">

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 📊 TAB 1 — USAGE METRICS                               -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade show active" id="pane-metrics" role="tabpanel" aria-labelledby="tab-metrics">

                <!-- 🔍 Metrics filter bar -->
                <div class="filter-bar">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label for="filterMetricScope" class="form-label fw-semibold">
                                <i class="fas fa-globe me-1"></i>Scope
                            </label>
                            <select id="filterMetricScope" class="form-select" onchange="loadMetrics()">
                                <option value="">All Scopes</option>
                                <option value="global">Global</option>
                                <option value="partner">Partner</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="filterMetricStatus" class="form-label fw-semibold">
                                <i class="fas fa-toggle-on me-1"></i>Status
                            </label>
                            <select id="filterMetricStatus" class="form-select" onchange="loadMetrics()">
                                <option value="">All Statuses</option>
                                <option value="1" selected>Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6 text-end">
                            <button class="btn btn-success" onclick="showMetricModal()">
                                <i class="fas fa-plus me-2"></i>Add Metric
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 📋 Metrics DataTable -->
                <div class="card table-card position-relative">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Usage Metrics</h5>
                        <div class="d-flex align-items-center">
                            <span id="metricsCount" class="badge bg-light text-dark"></span>
                            <!-- 📥 Export dropdown for Usage Metrics table -->
                            <div class="dropdown d-inline-block ms-2">
                                <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-download me-1"></i> Export
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="#" onclick="exportData('metricsTable', 'csv', 'Usage Metrics'); return false;"><i class="fas fa-file-csv me-2"></i>CSV</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="exportData('metricsTable', 'excel', 'Usage Metrics'); return false;"><i class="fas fa-file-excel me-2"></i>Excel</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="exportData('metricsTable', 'pdf', 'Usage Metrics'); return false;"><i class="fas fa-file-pdf me-2"></i>PDF</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div id="metricsLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading metrics...</span>
                        </div>
                    </div>
                    <div class="table-responsive p-3">
                        <table class="table table-hover mb-0" id="metricsTable" style="width: 100%;">
                            <thead class="table-light">
                                <tr>
                                    <th><i class="fas fa-code me-1"></i>Code</th>
                                    <th><i class="fas fa-tag me-1"></i>Name</th>
                                    <th><i class="fas fa-ruler me-1"></i>Unit</th>
                                    <th><i class="fas fa-clock me-1"></i>Granularity</th>
                                    <th><i class="fas fa-calculator me-1"></i>Aggregation</th>
                                    <th><i class="fas fa-globe me-1"></i>Scope</th>
                                    <th><i class="fas fa-circle-info me-1"></i>Status</th>
                                    <th class="text-center"><i class="fas fa-cogs me-1"></i>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="metricsTableBody">
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>
                                        Loading usage metrics...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 💰 TAB 2 — USAGE RATES                                 -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pane-rates" role="tabpanel" aria-labelledby="tab-rates">

                <!-- 🔍 Rates filter bar with tier selector -->
                <div class="filter-bar">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="filterRateTier" class="form-label fw-semibold">
                                <i class="fas fa-layer-group me-1"></i>Subscription Tier
                            </label>
                            <select id="filterRateTier" class="form-select" onchange="loadRates()">
                                <option value="">— Select Tier —</option>
                                <!-- 🔄 Populated dynamically via AJAX -->
                            </select>
                        </div>
                        <div class="col-md-8 text-end">
                            <button class="btn btn-success" onclick="showRateModal()">
                                <i class="fas fa-plus me-2"></i>Add Rate
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 📋 Rates DataTable -->
                <div class="card table-card position-relative">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Usage Rates</h5>
                        <div class="d-flex align-items-center">
                            <span id="ratesCount" class="badge bg-light text-dark"></span>
                            <!-- 📥 Export dropdown for Usage Rates table -->
                            <div class="dropdown d-inline-block ms-2">
                                <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-download me-1"></i> Export
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="#" onclick="exportData('ratesTable', 'csv', 'Usage Rates'); return false;"><i class="fas fa-file-csv me-2"></i>CSV</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="exportData('ratesTable', 'excel', 'Usage Rates'); return false;"><i class="fas fa-file-excel me-2"></i>Excel</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="exportData('ratesTable', 'pdf', 'Usage Rates'); return false;"><i class="fas fa-file-pdf me-2"></i>PDF</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div id="ratesLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading rates...</span>
                        </div>
                    </div>
                    <div class="table-responsive p-3">
                        <table class="table table-hover mb-0" id="ratesTable" style="width: 100%;">
                            <thead class="table-light">
                                <tr>
                                    <th><i class="fas fa-chart-line me-1"></i>Metric</th>
                                    <th><i class="fas fa-coins me-1"></i>Rate</th>
                                    <th><i class="fas fa-gift me-1"></i>Free Allowance</th>
                                    <th><i class="fas fa-arrow-trend-up me-1"></i>Overage Rate</th>
                                    <th><i class="fas fa-ban me-1"></i>Cap</th>
                                    <th><i class="fas fa-money-bill me-1"></i>Currency</th>
                                    <th><i class="fas fa-calendar me-1"></i>Effective</th>
                                    <th><i class="fas fa-circle-info me-1"></i>Status</th>
                                    <th class="text-center"><i class="fas fa-cogs me-1"></i>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="ratesTableBody">
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="fas fa-hand-pointer fa-2x mb-3 d-block"></i>
                                        Select a subscription tier to view rates.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 📝 TAB 3 — USAGE RECORDS BROWSER                       -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pane-records" role="tabpanel" aria-labelledby="tab-records">

                <!-- 🔍 Records filter bar -->
                <div class="filter-bar">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label for="filterRecordDateFrom" class="form-label fw-semibold">
                                <i class="fas fa-calendar me-1"></i>From
                            </label>
                            <input type="date" id="filterRecordDateFrom" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label for="filterRecordDateTo" class="form-label fw-semibold">
                                <i class="fas fa-calendar me-1"></i>To
                            </label>
                            <input type="date" id="filterRecordDateTo" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label for="filterRecordUser" class="form-label fw-semibold">
                                <i class="fas fa-user me-1"></i>User ID
                            </label>
                            <input type="number" id="filterRecordUser" class="form-control" placeholder="User ID">
                        </div>
                        <div class="col-md-2">
                            <label for="filterRecordMetric" class="form-label fw-semibold">
                                <i class="fas fa-chart-line me-1"></i>Metric
                            </label>
                            <select id="filterRecordMetric" class="form-select">
                                <option value="">All Metrics</option>
                                <!-- 🔄 Populated dynamically -->
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="filterRecordProcessed" class="form-label fw-semibold">
                                <i class="fas fa-check-circle me-1"></i>Processed
                            </label>
                            <select id="filterRecordProcessed" class="form-select">
                                <option value="">All</option>
                                <option value="0" selected>Unprocessed</option>
                                <option value="1">Processed</option>
                            </select>
                        </div>
                        <div class="col-md-2 text-end">
                            <button class="btn btn-primary" onclick="loadUsageRecords()">
                                <i class="fas fa-search me-2"></i>Search
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 📋 Records DataTable -->
                <div class="card table-card position-relative">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list-ol me-2"></i>Usage Records</h5>
                        <div class="d-flex align-items-center">
                            <span id="recordsCount" class="badge bg-light text-dark"></span>
                            <!-- 📥 Export dropdown for Usage Records table -->
                            <div class="dropdown d-inline-block ms-2">
                                <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-download me-1"></i> Export
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="#" onclick="exportData('recordsTable', 'csv', 'Usage Records'); return false;"><i class="fas fa-file-csv me-2"></i>CSV</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="exportData('recordsTable', 'excel', 'Usage Records'); return false;"><i class="fas fa-file-excel me-2"></i>Excel</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="exportData('recordsTable', 'pdf', 'Usage Records'); return false;"><i class="fas fa-file-pdf me-2"></i>PDF</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div id="recordsLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading records...</span>
                        </div>
                    </div>
                    <div class="table-responsive p-3">
                        <table class="table table-hover mb-0" id="recordsTable" style="width: 100%;">
                            <thead class="table-light">
                                <tr>
                                    <th><i class="fas fa-user me-1"></i>User</th>
                                    <th><i class="fas fa-chart-line me-1"></i>Metric</th>
                                    <th><i class="fas fa-hashtag me-1"></i>Quantity</th>
                                    <th><i class="fas fa-calendar-day me-1"></i>Recorded</th>
                                    <th><i class="fas fa-calendar-week me-1"></i>Billing Period</th>
                                    <th><i class="fas fa-check me-1"></i>Processed</th>
                                    <th><i class="fas fa-code me-1"></i>Metadata</th>
                                </tr>
                            </thead>
                            <tbody id="recordsTableBody">
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-search fa-2x mb-3 d-block"></i>
                                        Use filters above and click Search to load records.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 🧾 TAB 4 — BILLING SUMMARIES                           -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pane-summaries" role="tabpanel" aria-labelledby="tab-summaries">

                <!-- 🔍 Summaries filter bar -->
                <div class="filter-bar">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label for="filterSummaryStatus" class="form-label fw-semibold">
                                <i class="fas fa-filter me-1"></i>Status
                            </label>
                            <select id="filterSummaryStatus" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="pending" selected>Pending</option>
                                <option value="processed">Processed</option>
                                <option value="invoiced">Invoiced</option>
                                <option value="paid">Paid</option>
                                <option value="disputed">Disputed</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="filterSummaryUser" class="form-label fw-semibold">
                                <i class="fas fa-user me-1"></i>User ID
                            </label>
                            <input type="number" id="filterSummaryUser" class="form-control" placeholder="User ID">
                        </div>
                        <div class="col-md-2">
                            <label for="filterSummaryDateFrom" class="form-label fw-semibold">
                                <i class="fas fa-calendar me-1"></i>From
                            </label>
                            <input type="date" id="filterSummaryDateFrom" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label for="filterSummaryDateTo" class="form-label fw-semibold">
                                <i class="fas fa-calendar me-1"></i>To
                            </label>
                            <input type="date" id="filterSummaryDateTo" class="form-control">
                        </div>
                        <div class="col-md-4 text-end">
                            <button class="btn btn-primary me-2" onclick="loadSummaries()">
                                <i class="fas fa-search me-2"></i>Search
                            </button>
                            <button class="btn btn-warning" onclick="showProcessBillingModal()">
                                <i class="fas fa-cogs me-2"></i>Process Billing
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 📋 Summaries DataTable -->
                <div class="card table-card position-relative">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>Billing Summaries</h5>
                        <div class="d-flex align-items-center">
                            <span id="summariesCount" class="badge bg-light text-dark"></span>
                            <!-- 📥 Export dropdown for Billing Summaries table -->
                            <div class="dropdown d-inline-block ms-2">
                                <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-download me-1"></i> Export
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="#" onclick="exportData('summariesTable', 'csv', 'Billing Summaries'); return false;"><i class="fas fa-file-csv me-2"></i>CSV</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="exportData('summariesTable', 'excel', 'Billing Summaries'); return false;"><i class="fas fa-file-excel me-2"></i>Excel</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="exportData('summariesTable', 'pdf', 'Billing Summaries'); return false;"><i class="fas fa-file-pdf me-2"></i>PDF</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div id="summariesLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading summaries...</span>
                        </div>
                    </div>
                    <div class="table-responsive p-3">
                        <table class="table table-hover mb-0" id="summariesTable" style="width: 100%;">
                            <thead class="table-light">
                                <tr>
                                    <th><i class="fas fa-user me-1"></i>User</th>
                                    <th><i class="fas fa-calendar-week me-1"></i>Period</th>
                                    <th><i class="fas fa-cog me-1"></i>Billing Mode</th>
                                    <th><i class="fas fa-coins me-1"></i>Usage Cost</th>
                                    <th><i class="fas fa-ban me-1"></i>Tier Cap</th>
                                    <th><i class="fas fa-credit-card me-1"></i>Charged</th>
                                    <th><i class="fas fa-shield me-1"></i>Cap Applied</th>
                                    <th><i class="fas fa-circle-info me-1"></i>Status</th>
                                    <th class="text-center"><i class="fas fa-cogs me-1"></i>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="summariesTableBody">
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="fas fa-search fa-2x mb-3 d-block"></i>
                                        Use filters above and click Search to load summaries.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div><!-- /.tab-content -->

    </div><!-- /.container-fluid -->

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- ➕ CREATE / EDIT METRIC MODAL                                      -->
    <!-- @see https://getbootstrap.com/docs/5.3/components/modal/           -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="metricModal" tabindex="-1" aria-labelledby="metricModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-gradient">
                    <h5 class="modal-title" id="metricModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>Add Usage Metric
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="metricForm" novalidate>
                        <!-- 🔐 CSRF Token -->
                        <input type="hidden" name="csrf_token" id="metricCsrfToken"
                               value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <!-- 🆔 Hidden ID for edit mode -->
                        <input type="hidden" id="metricID" value="">

                        <div class="row g-3">
                            <!-- 🏷️ Metric Code -->
                            <div class="col-md-6">
                                <label for="metricCode" class="form-label fw-semibold">
                                    <i class="fas fa-code me-1"></i>Metric Code <span class="text-danger">*</span>
                                </label>
                                <input type="text" id="metricCode" class="form-control"
                                       placeholder="e.g. api_calls" required maxlength="100"
                                       pattern="[a-z0-9_]+" title="Lowercase alphanumeric and underscores only">
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Unique identifier (lowercase, underscores). Used in API calls.
                                </div>
                            </div>

                            <!-- 📝 Metric Name -->
                            <div class="col-md-6">
                                <label for="metricName" class="form-label fw-semibold">
                                    <i class="fas fa-tag me-1"></i>Metric Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" id="metricName" class="form-control"
                                       placeholder="e.g. API Calls" required maxlength="255">
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Human-readable display name for this metric.
                                </div>
                            </div>

                            <!-- 📏 Unit Label -->
                            <div class="col-md-4">
                                <label for="metricUnitLabel" class="form-label fw-semibold">
                                    <i class="fas fa-ruler me-1"></i>Unit Label <span class="text-danger">*</span>
                                </label>
                                <input type="text" id="metricUnitLabel" class="form-control"
                                       placeholder="e.g. calls, MB, requests" required maxlength="50">
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Unit of measurement displayed to users.
                                </div>
                            </div>

                            <!-- ⏰ Billing Granularity -->
                            <div class="col-md-4">
                                <label for="metricGranularity" class="form-label fw-semibold">
                                    <i class="fas fa-clock me-1"></i>Billing Granularity <span class="text-danger">*</span>
                                </label>
                                <select id="metricGranularity" class="form-select" required>
                                    <option value="">— Select —</option>
                                    <option value="per_unit">Per Unit</option>
                                    <option value="per_hour">Per Hour</option>
                                    <option value="per_day">Per Day</option>
                                    <option value="per_month">Per Month</option>
                                    <option value="per_request">Per Request</option>
                                </select>
                            </div>

                            <!-- 📊 Aggregation Type -->
                            <div class="col-md-4">
                                <label for="metricAggregation" class="form-label fw-semibold">
                                    <i class="fas fa-calculator me-1"></i>Aggregation <span class="text-danger">*</span>
                                </label>
                                <select id="metricAggregation" class="form-select" required>
                                    <option value="">— Select —</option>
                                    <option value="sum">Sum</option>
                                    <option value="max">Max</option>
                                    <option value="avg">Average</option>
                                    <option value="count">Count</option>
                                    <option value="last">Last Value</option>
                                </select>
                            </div>

                            <!-- 🌐 Scope -->
                            <div class="col-md-6">
                                <label for="metricScope" class="form-label fw-semibold">
                                    <i class="fas fa-globe me-1"></i>Scope <span class="text-danger">*</span>
                                </label>
                                <select id="metricScope" class="form-select" required>
                                    <option value="">— Select —</option>
                                    <option value="global">Global (all partners)</option>
                                    <option value="partner">Partner-specific</option>
                                </select>
                            </div>

                            <!-- ✅ Active Status -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-toggle-on me-1"></i>Status
                                </label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="metricIsActive" checked>
                                    <label class="form-check-label" for="metricIsActive">Active</label>
                                </div>
                            </div>

                            <!-- 📝 Description -->
                            <div class="col-12">
                                <label for="metricDescription" class="form-label fw-semibold">
                                    <i class="fas fa-align-left me-1"></i>Description
                                </label>
                                <textarea id="metricDescription" class="form-control" rows="3"
                                          placeholder="Optional description of what this metric tracks..."></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-success" id="saveMetricBtn" onclick="saveMetric()">
                        <i class="fas fa-save me-1"></i>Save Metric
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- ➕ CREATE / EDIT RATE MODAL                                        -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="rateModal" tabindex="-1" aria-labelledby="rateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-gradient">
                    <h5 class="modal-title" id="rateModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>Add Usage Rate
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="rateForm" novalidate>
                        <!-- 🔐 CSRF Token -->
                        <input type="hidden" name="csrf_token" id="rateCsrfToken"
                               value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <!-- 🆔 Hidden ID for edit mode -->
                        <input type="hidden" id="rateID" value="">

                        <div class="row g-3">
                            <!-- 🏷️ Tier Selector -->
                            <div class="col-md-6">
                                <label for="rateTierID" class="form-label fw-semibold">
                                    <i class="fas fa-layer-group me-1"></i>Subscription Tier <span class="text-danger">*</span>
                                </label>
                                <select id="rateTierID" class="form-select" required>
                                    <option value="">— Select Tier —</option>
                                    <!-- 🔄 Populated dynamically -->
                                </select>
                            </div>

                            <!-- 📊 Metric Selector -->
                            <div class="col-md-6">
                                <label for="rateMetricID" class="form-label fw-semibold">
                                    <i class="fas fa-chart-line me-1"></i>Metric <span class="text-danger">*</span>
                                </label>
                                <select id="rateMetricID" class="form-select" required>
                                    <option value="">— Select Metric —</option>
                                    <!-- 🔄 Populated dynamically -->
                                </select>
                            </div>

                            <!-- 💰 Rate per Unit -->
                            <div class="col-md-4">
                                <label for="ratePerUnit" class="form-label fw-semibold">
                                    <i class="fas fa-coins me-1"></i>Rate per Unit <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">&pound;</span>
                                    <input type="number" id="ratePerUnit" class="form-control"
                                           step="0.0001" min="0" placeholder="0.0050" required>
                                </div>
                            </div>

                            <!-- 🎁 Free Allowance -->
                            <div class="col-md-4">
                                <label for="rateFreeAllowance" class="form-label fw-semibold">
                                    <i class="fas fa-gift me-1"></i>Free Allowance
                                </label>
                                <input type="number" id="rateFreeAllowance" class="form-control"
                                       step="1" min="0" placeholder="e.g. 1000">
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>Units included free per billing period.
                                </div>
                            </div>

                            <!-- 📈 Overage Rate -->
                            <div class="col-md-4">
                                <label for="rateOverage" class="form-label fw-semibold">
                                    <i class="fas fa-arrow-trend-up me-1"></i>Overage Rate
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">&pound;</span>
                                    <input type="number" id="rateOverage" class="form-control"
                                           step="0.0001" min="0" placeholder="0.0100">
                                </div>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>Rate for usage exceeding free allowance.
                                </div>
                            </div>

                            <!-- 🚫 Usage Cap -->
                            <div class="col-md-4">
                                <label for="rateCap" class="form-label fw-semibold">
                                    <i class="fas fa-ban me-1"></i>Usage Cap
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">&pound;</span>
                                    <input type="number" id="rateCap" class="form-control"
                                           step="0.01" min="0" placeholder="e.g. 100.00">
                                </div>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>Maximum billable amount per period.
                                </div>
                            </div>

                            <!-- 💱 Currency -->
                            <div class="col-md-4">
                                <label for="rateCurrency" class="form-label fw-semibold">
                                    <i class="fas fa-money-bill me-1"></i>Currency <span class="text-danger">*</span>
                                </label>
                                <select id="rateCurrency" class="form-select" required>
                                    <option value="GBP" selected>GBP (£)</option>
                                    <option value="USD">USD ($)</option>
                                    <option value="EUR">EUR (&euro;)</option>
                                    <option value="AUD">AUD (A$)</option>
                                    <option value="CAD">CAD (C$)</option>
                                </select>
                            </div>

                            <!-- ✅ Active Status -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-toggle-on me-1"></i>Status
                                </label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="rateIsActive" checked>
                                    <label class="form-check-label" for="rateIsActive">Active</label>
                                </div>
                            </div>

                            <!-- 📅 Effective Date -->
                            <div class="col-md-6">
                                <label for="rateEffectiveFrom" class="form-label fw-semibold">
                                    <i class="fas fa-calendar-check me-1"></i>Effective From <span class="text-danger">*</span>
                                </label>
                                <input type="date" id="rateEffectiveFrom" class="form-control" required>
                            </div>

                            <!-- 📅 Expiry Date -->
                            <div class="col-md-6">
                                <label for="rateEffectiveTo" class="form-label fw-semibold">
                                    <i class="fas fa-calendar-xmark me-1"></i>Effective To
                                </label>
                                <input type="date" id="rateEffectiveTo" class="form-control">
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>Leave blank for no expiry.
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-success" id="saveRateBtn" onclick="saveRate()">
                        <i class="fas fa-save me-1"></i>Save Rate
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🔍 SUMMARY DETAIL MODAL — Itemised usage breakdown                 -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="summaryDetailModal" tabindex="-1" aria-labelledby="summaryDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header modal-header-gradient">
                    <h5 class="modal-title" id="summaryDetailModalLabel">
                        <i class="fas fa-file-invoice me-2"></i>Billing Summary Detail
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- 📊 Summary overview -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="detail-section">
                                <small class="text-muted d-block">User</small>
                                <strong id="detailUser">—</strong>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="detail-section">
                                <small class="text-muted d-block">Billing Period</small>
                                <strong id="detailPeriod">—</strong>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="detail-section">
                                <small class="text-muted d-block">Status</small>
                                <strong id="detailStatus">—</strong>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="detail-section">
                                <small class="text-muted d-block">Charged Amount</small>
                                <strong id="detailCharged" class="text-success fs-5">—</strong>
                            </div>
                        </div>
                    </div>

                    <!-- 📋 Line items table -->
                    <h6 class="fw-bold mb-3"><i class="fas fa-list me-2"></i>Itemised Usage Breakdown</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped" id="lineItemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Metric</th>
                                    <th>Quantity Used</th>
                                    <th>Free Allowance</th>
                                    <th>Billable Qty</th>
                                    <th>Rate</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody id="lineItemsBody">
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">
                                        Loading line items...
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">Total Usage Cost:</td>
                                    <td id="detailTotalCost">—</td>
                                </tr>
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">Tier Cap:</td>
                                    <td id="detailTierCap">—</td>
                                </tr>
                                <tr class="fw-bold table-success">
                                    <td colspan="5" class="text-end">Amount Charged:</td>
                                    <td id="detailAmountCharged">—</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- ⚙️ PROCESS BILLING MODAL — Confirm batch processing trigger        -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="processBillingModal" tabindex="-1" aria-labelledby="processBillingModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-gradient">
                    <h5 class="modal-title" id="processBillingModalLabel">
                        <i class="fas fa-cogs me-2"></i>Process Billing Batch
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Confirm Batch Processing</strong>
                    </div>
                    <p>
                        This will process all unprocessed usage records and generate billing
                        summaries for the current billing period. This action:
                    </p>
                    <ul>
                        <li>Aggregates unprocessed usage records per user</li>
                        <li>Applies tier rates and free allowances</li>
                        <li>Generates billing summaries with line item breakdowns</li>
                        <li>Marks processed records as completed</li>
                    </ul>
                    <p class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong id="processBillingRecordCount">0</strong> unprocessed records will be included.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-warning" id="confirmProcessBtn" onclick="processBilling()">
                        <i class="fas fa-play me-1"></i>Start Processing
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📜 JAVASCRIPT DEPENDENCIES                                         -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->

    <!-- 🔐 Bootstrap 5.3.2 JS Bundle (CDN with SRI) -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- 📋 jQuery 3.7.1 (required by DataTables) -->
    <!-- @see https://jquery.com/ — jQuery Documentation -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
            crossorigin="anonymous"></script>

    <!-- 📋 DataTables 1.13.7 (CDN) — Bootstrap 5 integration -->
    <!-- @see https://datatables.net/ — DataTables Documentation -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"
            crossorigin="anonymous"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"
            crossorigin="anonymous"></script>

    <!-- 🔐 CSRF Token — accessible to JavaScript for AJAX POST requests -->
    <script>const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';</script>

    <script>
    /**
     * ============================================================================
     * 📊 Usage Metrics & Rates Management — Client-Side Logic
     * ============================================================================
     *
     * Handles AJAX loading for all four tabs, CRUD operations for metrics and
     * rates, usage records browsing, billing summary review and batch processing.
     *
     * Communicates with the backend API at:
     *   ../api/usage-actions.php
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API — Fetch API
     * @see https://datatables.net/reference/api/ — DataTables API Reference
     * ============================================================================
     */

    // ═══════════════════════════════════════════════════════════════
    // 🔧 STATE TRACKING — DataTable instances & tab load flags
    // ═══════════════════════════════════════════════════════════════

    /** @type {Object|null} DataTables instances for each tab */
    let metricsDataTable  = null;
    let ratesDataTable    = null;
    let recordsDataTable  = null;
    let summariesDataTable = null;

    /** @type {Object} Track which tabs have been loaded (lazy loading) */
    const tabLoaded = {
        metrics: false,
        rates: false,
        records: false,
        summaries: false
    };

    /** @type {string} API endpoint base path */
    const API_URL = '../api/usage-actions.php';

    // ═══════════════════════════════════════════════════════════════
    // 🛠️ UTILITY FUNCTIONS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔒 Escape HTML entities to prevent XSS attacks.
     * Creates a temporary text node to safely encode user-supplied content.
     *
     * @param {string} text — Raw text to escape
     * @returns {string} HTML-safe escaped string
     * @see https://owasp.org/www-community/attacks/xss/ — OWASP XSS Prevention
     */
    function escapeHtml(text) {
        if (text === null || text === undefined) { return ''; }
        if (typeof text === 'number') { return String(text); }
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    /**
     * 💬 Show a Bootstrap alert notification in the alert container.
     * Alerts auto-dismiss after 5 seconds.
     *
     * @param {string} message — Alert message content (can include pre-escaped HTML icons)
     * @param {string} type — Bootstrap alert type: success, danger, warning, info
     * @see https://getbootstrap.com/docs/5.3/components/alerts/ — Bootstrap Alerts
     */
    function showAlert(message, type = 'info') {
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.getElementById('alertContainer').appendChild(alert);

        // ⏰ Auto-dismiss after 5 seconds
        setTimeout(() => {
            if (alert.parentNode) {
                alert.classList.remove('show');
                setTimeout(() => alert.remove(), 300);
            }
        }, 5000);
    }

    /**
     * 🍞 Show a Bootstrap toast notification.
     * Toasts auto-hide after 4 seconds.
     *
     * @param {string} title — Toast header title
     * @param {string} message — Toast body text (pre-escaped)
     * @param {string} bgClass — Bootstrap background class: bg-success, bg-danger, etc.
     */
    function showToast(title, message, bgClass = 'bg-success') {
        const id = 'toast_' + Date.now();
        const html = `
            <div class="toast" id="${id}" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header ${bgClass} text-white">
                    <strong class="me-auto">${escapeHtml(title)}</strong>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                </div>
                <div class="toast-body">${message}</div>
            </div>
        `;
        document.getElementById('toastContainer').insertAdjacentHTML('beforeend', html);
        const toastEl = document.getElementById(id);
        const bsToast = new bootstrap.Toast(toastEl, { delay: 4000 });
        bsToast.show();
        // 🧹 Clean up DOM after hidden
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }

    /**
     * 🏷️ Generate a status badge HTML span.
     *
     * @param {string} status — Status string (active, inactive, pending, etc.)
     * @returns {string} HTML badge span
     */
    function statusBadge(status) {
        const s = String(status).toLowerCase();
        const map = {
            'active':    'badge-active',
            '1':         'badge-active',
            'inactive':  'badge-inactive',
            '0':         'badge-inactive',
            'pending':   'badge-pending',
            'processed': 'badge-processed',
            'invoiced':  'badge-invoiced',
            'paid':      'badge-paid',
            'disputed':  'badge-disputed'
        };
        const cls = map[s] || 'bg-secondary';
        const label = s === '1' ? 'Active' : s === '0' ? 'Inactive' : s.charAt(0).toUpperCase() + s.slice(1);
        return `<span class="badge ${cls}">${escapeHtml(label)}</span>`;
    }

    /**
     * 📡 Generic AJAX POST helper using fetch().
     * Automatically includes CSRF token and parses JSON response.
     *
     * @param {Object} data — Request payload (action + parameters)
     * @returns {Promise<Object>} Parsed JSON response
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API/Using_Fetch
     */
    async function apiPost(data) {
        data.csrf_token = csrfToken;
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    }

    /**
     * 📡 Generic AJAX GET helper using fetch().
     * Builds URL query string from parameters.
     *
     * @param {Object} params — Query parameters (action + filters)
     * @returns {Promise<Object>} Parsed JSON response
     */
    async function apiGet(params) {
        const queryString = new URLSearchParams(params).toString();
        const response = await fetch(`${API_URL}?${queryString}`);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    }

    /**
     * 🔄 Set loading state on a button.
     *
     * @param {HTMLElement} btn — Button element
     * @param {boolean} loading — True to show spinner, false to restore
     * @param {string} originalHtml — Original innerHTML to restore
     */
    function setButtonLoading(btn, loading, originalHtml = '') {
        if (loading) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
        } else {
            btn.disabled = false;
            btn.innerHTML = originalHtml || btn.dataset.originalHtml || 'Save';
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 📊 TAB 1 — USAGE METRICS (CRUD)
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📊 Load usage metrics from the API and populate the DataTable.
     * Applies scope and status filters from the filter bar.
     */
    async function loadMetrics() {
        const loading = document.getElementById('metricsLoading');
        loading.style.display = 'flex';

        try {
            const params = { action: 'get_metrics' };
            const scope = document.getElementById('filterMetricScope').value;
            const status = document.getElementById('filterMetricStatus').value;
            if (scope)  { params.scope  = scope; }
            if (status !== '') { params.isActive = status; }

            const result = await apiGet(params);

            if (!result.success) {
                showAlert('<i class="fas fa-times-circle me-1"></i> ' + escapeHtml(result.message || 'Failed to load metrics.'), 'danger');
                return;
            }

            const metrics = result.data || [];
            document.getElementById('metricsCount').textContent = metrics.length + ' metric(s)';

            // 🧹 Destroy existing DataTable before rebuilding
            if (metricsDataTable) {
                metricsDataTable.destroy();
                metricsDataTable = null;
            }

            // 🏗️ Build table rows
            const tbody = document.getElementById('metricsTableBody');
            if (metrics.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No metrics found.
                        </td>
                    </tr>`;
            } else {
                tbody.innerHTML = metrics.map(m => `
                    <tr>
                        <td><code>${escapeHtml(m.metricCode)}</code></td>
                        <td>${escapeHtml(m.metricName)}</td>
                        <td>${escapeHtml(m.unitLabel)}</td>
                        <td>${escapeHtml(m.billingGranularity)}</td>
                        <td>${escapeHtml(m.aggregationType)}</td>
                        <td><span class="badge ${m.scope === 'global' ? 'bg-primary' : 'bg-info'}">${escapeHtml(m.scope)}</span></td>
                        <td>${statusBadge(m.isActive)}</td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary me-1" onclick="editMetric(${parseInt(m.metricID)})"
                                    title="Edit metric" aria-label="Edit ${escapeHtml(m.metricName)}">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteMetric(${parseInt(m.metricID)}, '${escapeHtml(m.metricName)}')"
                                    title="Delete metric" aria-label="Delete ${escapeHtml(m.metricName)}">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `).join('');
            }

            // 📋 Initialise DataTable if we have data
            if (metrics.length > 0) {
                metricsDataTable = $('#metricsTable').DataTable({
                    pageLength: 25,
                    order: [[0, 'asc']],
                    language: { search: '<i class="fas fa-search me-1"></i>' }
                });
            }

            tabLoaded.metrics = true;

        } catch (error) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Error loading metrics: ' + escapeHtml(error.message), 'danger');
        } finally {
            loading.style.display = 'none';
        }
    }

    /**
     * ➕ Show the metric modal in create mode — resets form fields.
     */
    function showMetricModal() {
        document.getElementById('metricID').value = '';
        document.getElementById('metricForm').reset();
        document.getElementById('metricIsActive').checked = true;
        document.getElementById('metricModalLabel').innerHTML = '<i class="fas fa-plus-circle me-2"></i>Add Usage Metric';
        document.getElementById('saveMetricBtn').innerHTML = '<i class="fas fa-save me-1"></i>Save Metric';
        new bootstrap.Modal(document.getElementById('metricModal')).show();
    }

    /**
     * ✏️ Show the metric modal in edit mode — loads existing data.
     *
     * @param {number} metricID — The metric ID to edit
     */
    async function editMetric(metricID) {
        try {
            const result = await apiGet({ action: 'get_metric', metricID: metricID });
            if (!result.success) {
                showAlert('<i class="fas fa-times-circle me-1"></i> ' + escapeHtml(result.message), 'danger');
                return;
            }
            const m = result.data;
            document.getElementById('metricID').value          = m.metricID;
            document.getElementById('metricCode').value        = m.metricCode || '';
            document.getElementById('metricName').value        = m.metricName || '';
            document.getElementById('metricUnitLabel').value   = m.unitLabel || '';
            document.getElementById('metricGranularity').value = m.billingGranularity || '';
            document.getElementById('metricAggregation').value = m.aggregationType || '';
            document.getElementById('metricScope').value       = m.scope || '';
            document.getElementById('metricIsActive').checked  = parseInt(m.isActive) === 1;
            document.getElementById('metricDescription').value = m.description || '';
            document.getElementById('metricModalLabel').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Usage Metric';
            document.getElementById('saveMetricBtn').innerHTML = '<i class="fas fa-save me-1"></i>Update Metric';
            new bootstrap.Modal(document.getElementById('metricModal')).show();
        } catch (error) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Error: ' + escapeHtml(error.message), 'danger');
        }
    }

    /**
     * 💾 Save metric — create or update based on metricID presence.
     */
    async function saveMetric() {
        // 🔍 Basic validation
        const form = document.getElementById('metricForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const btn = document.getElementById('saveMetricBtn');
        setButtonLoading(btn, true);

        const metricID = document.getElementById('metricID').value;
        const isEdit = metricID !== '';

        try {
            const payload = {
                action:             isEdit ? 'update_metric' : 'create_metric',
                metricCode:         document.getElementById('metricCode').value.trim(),
                metricName:         document.getElementById('metricName').value.trim(),
                unitLabel:          document.getElementById('metricUnitLabel').value.trim(),
                billingGranularity: document.getElementById('metricGranularity').value,
                aggregationType:    document.getElementById('metricAggregation').value,
                scope:              document.getElementById('metricScope').value,
                isActive:           document.getElementById('metricIsActive').checked ? 1 : 0,
                description:        document.getElementById('metricDescription').value.trim()
            };
            if (isEdit) { payload.metricID = parseInt(metricID); }

            const result = await apiPost(payload);

            if (result.success) {
                showToast('Success', '<i class="fas fa-check-circle me-1"></i> Metric ' + (isEdit ? 'updated' : 'created') + ' successfully.', 'bg-success');
                bootstrap.Modal.getInstance(document.getElementById('metricModal')).hide();
                loadMetrics();
                loadStats();
            } else {
                showAlert('<i class="fas fa-times-circle me-1"></i> ' + escapeHtml(result.message || 'Failed to save metric.'), 'danger');
            }
        } catch (error) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Error: ' + escapeHtml(error.message), 'danger');
        } finally {
            setButtonLoading(btn, false);
        }
    }

    /**
     * 🗑️ Delete a metric after confirmation.
     *
     * @param {number} metricID — Metric ID to delete
     * @param {string} metricName — Metric name for confirmation dialog
     */
    async function deleteMetric(metricID, metricName) {
        if (!confirm(`Are you sure you want to delete the metric "${metricName}"?\n\nThis action cannot be undone.`)) {
            return;
        }

        try {
            const result = await apiPost({ action: 'delete_metric', metricID: parseInt(metricID) });
            if (result.success) {
                showToast('Deleted', '<i class="fas fa-trash me-1"></i> Metric deleted successfully.', 'bg-warning');
                loadMetrics();
                loadStats();
            } else {
                showAlert('<i class="fas fa-times-circle me-1"></i> ' + escapeHtml(result.message || 'Failed to delete metric.'), 'danger');
            }
        } catch (error) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Error: ' + escapeHtml(error.message), 'danger');
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 💰 TAB 2 — USAGE RATES (CRUD)
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🏷️ Load subscription tiers into tier selector dropdowns.
     * Called once on page init to populate both the filter and form selectors.
     */
    async function loadTiers() {
        try {
            const result = await apiGet({ action: 'get_tiers' });
            if (!result.success) { return; }

            const tiers = result.data || [];
            const options = tiers.map(t =>
                `<option value="${parseInt(t.tierID)}">${escapeHtml(t.tierName)}</option>`
            ).join('');

            // 🔄 Populate both the filter dropdown and the form dropdown
            document.getElementById('filterRateTier').innerHTML = '<option value="">— Select Tier —</option>' + options;
            document.getElementById('rateTierID').innerHTML     = '<option value="">— Select Tier —</option>' + options;
        } catch (error) {
            console.error('Failed to load tiers:', error);
        }
    }

    /**
     * 📊 Load metric options into the rate form metric selector.
     */
    async function loadMetricOptions() {
        try {
            const result = await apiGet({ action: 'get_metrics', isActive: '1' });
            if (!result.success) { return; }

            const metrics = result.data || [];
            const options = metrics.map(m =>
                `<option value="${parseInt(m.metricID)}">${escapeHtml(m.metricName)} (${escapeHtml(m.metricCode)})</option>`
            ).join('');

            document.getElementById('rateMetricID').innerHTML = '<option value="">— Select Metric —</option>' + options;

            // 🔄 Also populate the records filter metric dropdown
            document.getElementById('filterRecordMetric').innerHTML = '<option value="">All Metrics</option>' + options;
        } catch (error) {
            console.error('Failed to load metric options:', error);
        }
    }

    /**
     * 💰 Load usage rates for the selected tier and populate the DataTable.
     */
    async function loadRates() {
        const tierID = document.getElementById('filterRateTier').value;
        if (!tierID) {
            // 🔄 Reset table to placeholder if no tier selected
            if (ratesDataTable) { ratesDataTable.destroy(); ratesDataTable = null; }
            document.getElementById('ratesTableBody').innerHTML = `
                <tr><td colspan="9" class="text-center py-5 text-muted">
                    <i class="fas fa-hand-pointer fa-2x mb-3 d-block"></i>
                    Select a subscription tier to view rates.
                </td></tr>`;
            document.getElementById('ratesCount').textContent = '';
            return;
        }

        const loading = document.getElementById('ratesLoading');
        loading.style.display = 'flex';

        try {
            const result = await apiGet({ action: 'get_rates', tierID: tierID });

            if (!result.success) {
                showAlert('<i class="fas fa-times-circle me-1"></i> ' + escapeHtml(result.message || 'Failed to load rates.'), 'danger');
                return;
            }

            const rates = result.data || [];
            document.getElementById('ratesCount').textContent = rates.length + ' rate(s)';

            if (ratesDataTable) { ratesDataTable.destroy(); ratesDataTable = null; }

            const tbody = document.getElementById('ratesTableBody');
            if (rates.length === 0) {
                tbody.innerHTML = `
                    <tr><td colspan="9" class="text-center py-4 text-muted">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No rates configured for this tier.
                    </td></tr>`;
            } else {
                tbody.innerHTML = rates.map(r => `
                    <tr>
                        <td>${escapeHtml(r.metricName || r.metricCode || '—')}</td>
                        <td>&pound;${escapeHtml(parseFloat(r.ratePerUnit || 0).toFixed(4))}</td>
                        <td>${escapeHtml(r.freeAllowance || '0')}</td>
                        <td>${r.overageRate ? '&pound;' + escapeHtml(parseFloat(r.overageRate).toFixed(4)) : '—'}</td>
                        <td>${r.usageCap ? '&pound;' + escapeHtml(parseFloat(r.usageCap).toFixed(2)) : 'No cap'}</td>
                        <td>${escapeHtml(r.currency || 'GBP')}</td>
                        <td>${escapeHtml(r.effectiveFrom || '—')}${r.effectiveTo ? ' to ' + escapeHtml(r.effectiveTo) : ''}</td>
                        <td>${statusBadge(r.isActive)}</td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary me-1" onclick="editRate(${parseInt(r.rateID)})"
                                    title="Edit rate" aria-label="Edit rate">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteRate(${parseInt(r.rateID)})"
                                    title="Delete rate" aria-label="Delete rate">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `).join('');
            }

            if (rates.length > 0) {
                ratesDataTable = $('#ratesTable').DataTable({
                    pageLength: 25,
                    order: [[0, 'asc']],
                    language: { search: '<i class="fas fa-search me-1"></i>' }
                });
            }

            tabLoaded.rates = true;

        } catch (error) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Error loading rates: ' + escapeHtml(error.message), 'danger');
        } finally {
            loading.style.display = 'none';
        }
    }

    /**
     * ➕ Show the rate modal in create mode.
     */
    function showRateModal() {
        document.getElementById('rateID').value = '';
        document.getElementById('rateForm').reset();
        document.getElementById('rateIsActive').checked = true;
        document.getElementById('rateModalLabel').innerHTML = '<i class="fas fa-plus-circle me-2"></i>Add Usage Rate';
        document.getElementById('saveRateBtn').innerHTML = '<i class="fas fa-save me-1"></i>Save Rate';
        // 🔄 Pre-select tier if one is already chosen in the filter
        const filterTier = document.getElementById('filterRateTier').value;
        if (filterTier) { document.getElementById('rateTierID').value = filterTier; }
        new bootstrap.Modal(document.getElementById('rateModal')).show();
    }

    /**
     * ✏️ Show the rate modal in edit mode — loads existing data.
     *
     * @param {number} rateID — The rate ID to edit
     */
    async function editRate(rateID) {
        try {
            const result = await apiGet({ action: 'get_rate', rateID: rateID });
            if (!result.success) {
                showAlert('<i class="fas fa-times-circle me-1"></i> ' + escapeHtml(result.message), 'danger');
                return;
            }
            const r = result.data;
            document.getElementById('rateID').value            = r.rateID;
            document.getElementById('rateTierID').value        = r.tierID || '';
            document.getElementById('rateMetricID').value      = r.metricID || '';
            document.getElementById('ratePerUnit').value       = r.ratePerUnit || '';
            document.getElementById('rateFreeAllowance').value = r.freeAllowance || '';
            document.getElementById('rateOverage').value       = r.overageRate || '';
            document.getElementById('rateCap').value           = r.usageCap || '';
            document.getElementById('rateCurrency').value      = r.currency || 'GBP';
            document.getElementById('rateIsActive').checked    = parseInt(r.isActive) === 1;
            document.getElementById('rateEffectiveFrom').value = r.effectiveFrom || '';
            document.getElementById('rateEffectiveTo').value   = r.effectiveTo || '';
            document.getElementById('rateModalLabel').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Usage Rate';
            document.getElementById('saveRateBtn').innerHTML = '<i class="fas fa-save me-1"></i>Update Rate';
            new bootstrap.Modal(document.getElementById('rateModal')).show();
        } catch (error) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Error: ' + escapeHtml(error.message), 'danger');
        }
    }

    /**
     * 💾 Save rate — create or update based on rateID presence.
     */
    async function saveRate() {
        const form = document.getElementById('rateForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const btn = document.getElementById('saveRateBtn');
        setButtonLoading(btn, true);

        const rateID = document.getElementById('rateID').value;
        const isEdit = rateID !== '';

        try {
            const payload = {
                action:         isEdit ? 'update_rate' : 'create_rate',
                tierID:         parseInt(document.getElementById('rateTierID').value),
                metricID:       parseInt(document.getElementById('rateMetricID').value),
                ratePerUnit:    parseFloat(document.getElementById('ratePerUnit').value) || 0,
                freeAllowance:  parseInt(document.getElementById('rateFreeAllowance').value) || 0,
                overageRate:    parseFloat(document.getElementById('rateOverage').value) || null,
                usageCap:       parseFloat(document.getElementById('rateCap').value) || null,
                currency:       document.getElementById('rateCurrency').value,
                isActive:       document.getElementById('rateIsActive').checked ? 1 : 0,
                effectiveFrom:  document.getElementById('rateEffectiveFrom').value,
                effectiveTo:    document.getElementById('rateEffectiveTo').value || null
            };
            if (isEdit) { payload.rateID = parseInt(rateID); }

            const result = await apiPost(payload);

            if (result.success) {
                showToast('Success', '<i class="fas fa-check-circle me-1"></i> Rate ' + (isEdit ? 'updated' : 'created') + ' successfully.', 'bg-success');
                bootstrap.Modal.getInstance(document.getElementById('rateModal')).hide();
                loadRates();
                loadStats();
            } else {
                showAlert('<i class="fas fa-times-circle me-1"></i> ' + escapeHtml(result.message || 'Failed to save rate.'), 'danger');
            }
        } catch (error) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Error: ' + escapeHtml(error.message), 'danger');
        } finally {
            setButtonLoading(btn, false);
        }
    }

    /**
     * 🗑️ Delete a rate after confirmation.
     *
     * @param {number} rateID — Rate ID to delete
     */
    async function deleteRate(rateID) {
        if (!confirm('Are you sure you want to delete this usage rate?\n\nThis action cannot be undone.')) {
            return;
        }

        try {
            const result = await apiPost({ action: 'delete_rate', rateID: parseInt(rateID) });
            if (result.success) {
                showToast('Deleted', '<i class="fas fa-trash me-1"></i> Rate deleted successfully.', 'bg-warning');
                loadRates();
                loadStats();
            } else {
                showAlert('<i class="fas fa-times-circle me-1"></i> ' + escapeHtml(result.message || 'Failed to delete rate.'), 'danger');
            }
        } catch (error) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Error: ' + escapeHtml(error.message), 'danger');
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 📝 TAB 3 — USAGE RECORDS BROWSER
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📝 Load usage records from the API with applied filters.
     */
    async function loadUsageRecords() {
        const loading = document.getElementById('recordsLoading');
        loading.style.display = 'flex';

        try {
            const params = { action: 'get_usage_records' };
            const dateFrom  = document.getElementById('filterRecordDateFrom').value;
            const dateTo    = document.getElementById('filterRecordDateTo').value;
            const userID    = document.getElementById('filterRecordUser').value;
            const metricID  = document.getElementById('filterRecordMetric').value;
            const processed = document.getElementById('filterRecordProcessed').value;

            if (dateFrom)       { params.dateFrom   = dateFrom; }
            if (dateTo)         { params.dateTo     = dateTo; }
            if (userID)         { params.userID     = userID; }
            if (metricID)       { params.metricID   = metricID; }
            if (processed !== '') { params.isProcessed = processed; }

            const result = await apiGet(params);

            if (!result.success) {
                showAlert('<i class="fas fa-times-circle me-1"></i> ' + escapeHtml(result.message || 'Failed to load records.'), 'danger');
                return;
            }

            const records = result.data || [];
            document.getElementById('recordsCount').textContent = records.length + ' record(s)';

            if (recordsDataTable) { recordsDataTable.destroy(); recordsDataTable = null; }

            const tbody = document.getElementById('recordsTableBody');
            if (records.length === 0) {
                tbody.innerHTML = `
                    <tr><td colspan="7" class="text-center py-4 text-muted">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No usage records found.
                    </td></tr>`;
            } else {
                tbody.innerHTML = records.map(r => {
                    // 📋 Format metadata JSON for display
                    let metadataDisplay = '—';
                    if (r.metadata) {
                        try {
                            const meta = typeof r.metadata === 'string' ? JSON.parse(r.metadata) : r.metadata;
                            metadataDisplay = '<code class="small">' + escapeHtml(JSON.stringify(meta).substring(0, 80)) + '</code>';
                        } catch (e) {
                            metadataDisplay = '<code class="small">' + escapeHtml(String(r.metadata).substring(0, 80)) + '</code>';
                        }
                    }

                    return `
                        <tr>
                            <td>${escapeHtml(r.userName || 'User #' + r.userID)}</td>
                            <td>${escapeHtml(r.metricName || r.metricCode || '—')}</td>
                            <td class="fw-bold">${escapeHtml(r.quantity)}</td>
                            <td>${escapeHtml(r.recordedAt || '—')}</td>
                            <td>${escapeHtml(r.billingPeriod || '—')}</td>
                            <td>${parseInt(r.isProcessed) === 1
                                ? '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Yes</span>'
                                : '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>No</span>'}</td>
                            <td>${metadataDisplay}</td>
                        </tr>
                    `;
                }).join('');
            }

            if (records.length > 0) {
                recordsDataTable = $('#recordsTable').DataTable({
                    pageLength: 50,
                    order: [[3, 'desc']],
                    language: { search: '<i class="fas fa-search me-1"></i>' }
                });
            }

            tabLoaded.records = true;

        } catch (error) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Error loading records: ' + escapeHtml(error.message), 'danger');
        } finally {
            loading.style.display = 'none';
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 🧾 TAB 4 — BILLING SUMMARIES
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🧾 Load billing summaries from the API with applied filters.
     */
    async function loadSummaries() {
        const loading = document.getElementById('summariesLoading');
        loading.style.display = 'flex';

        try {
            const params = { action: 'get_summaries' };
            const status   = document.getElementById('filterSummaryStatus').value;
            const userID   = document.getElementById('filterSummaryUser').value;
            const dateFrom = document.getElementById('filterSummaryDateFrom').value;
            const dateTo   = document.getElementById('filterSummaryDateTo').value;

            if (status)   { params.status   = status; }
            if (userID)   { params.userID   = userID; }
            if (dateFrom) { params.dateFrom = dateFrom; }
            if (dateTo)   { params.dateTo   = dateTo; }

            const result = await apiGet(params);

            if (!result.success) {
                showAlert('<i class="fas fa-times-circle me-1"></i> ' + escapeHtml(result.message || 'Failed to load summaries.'), 'danger');
                return;
            }

            const summaries = result.data || [];
            document.getElementById('summariesCount').textContent = summaries.length + ' summary(ies)';

            if (summariesDataTable) { summariesDataTable.destroy(); summariesDataTable = null; }

            const tbody = document.getElementById('summariesTableBody');
            if (summaries.length === 0) {
                tbody.innerHTML = `
                    <tr><td colspan="9" class="text-center py-4 text-muted">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No billing summaries found.
                    </td></tr>`;
            } else {
                tbody.innerHTML = summaries.map(s => `
                    <tr>
                        <td>${escapeHtml(s.userName || 'User #' + s.userID)}</td>
                        <td>${escapeHtml(s.billingPeriodStart || '—')} to ${escapeHtml(s.billingPeriodEnd || '—')}</td>
                        <td>${escapeHtml(s.billingMode || '—')}</td>
                        <td>&pound;${escapeHtml(parseFloat(s.totalUsageCost || 0).toFixed(2))}</td>
                        <td>${s.tierCap ? '&pound;' + escapeHtml(parseFloat(s.tierCap).toFixed(2)) : 'No cap'}</td>
                        <td class="fw-bold text-success">&pound;${escapeHtml(parseFloat(s.chargedAmount || 0).toFixed(2))}</td>
                        <td>${parseInt(s.capApplied) === 1
                            ? '<span class="badge bg-info"><i class="fas fa-shield me-1"></i>Yes</span>'
                            : '<span class="badge bg-secondary">No</span>'}</td>
                        <td>${statusBadge(s.status)}</td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-info" onclick="viewSummaryDetail(${parseInt(s.summaryID)})"
                                    title="View detail" aria-label="View billing detail">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                `).join('');
            }

            if (summaries.length > 0) {
                summariesDataTable = $('#summariesTable').DataTable({
                    pageLength: 25,
                    order: [[1, 'desc']],
                    language: { search: '<i class="fas fa-search me-1"></i>' }
                });
            }

            tabLoaded.summaries = true;

        } catch (error) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Error loading summaries: ' + escapeHtml(error.message), 'danger');
        } finally {
            loading.style.display = 'none';
        }
    }

    /**
     * 🔍 View billing summary detail — loads line items into modal.
     *
     * @param {number} summaryID — The billing summary ID
     */
    async function viewSummaryDetail(summaryID) {
        // 🔄 Reset modal content
        document.getElementById('detailUser').textContent    = '—';
        document.getElementById('detailPeriod').textContent   = '—';
        document.getElementById('detailStatus').innerHTML     = '—';
        document.getElementById('detailCharged').textContent   = '—';
        document.getElementById('detailTotalCost').textContent = '—';
        document.getElementById('detailTierCap').textContent   = '—';
        document.getElementById('detailAmountCharged').textContent = '—';
        document.getElementById('lineItemsBody').innerHTML = `
            <tr><td colspan="6" class="text-center text-muted py-3">
                <i class="fas fa-spinner fa-spin me-1"></i>Loading...
            </td></tr>`;

        new bootstrap.Modal(document.getElementById('summaryDetailModal')).show();

        try {
            const result = await apiGet({ action: 'get_summary_detail', summaryID: summaryID });
            if (!result.success) {
                document.getElementById('lineItemsBody').innerHTML = `
                    <tr><td colspan="6" class="text-center text-danger py-3">
                        <i class="fas fa-times-circle me-1"></i>${escapeHtml(result.message || 'Failed to load detail.')}
                    </td></tr>`;
                return;
            }

            const s = result.data;

            // 📊 Populate summary overview
            document.getElementById('detailUser').textContent    = s.userName || 'User #' + s.userID;
            document.getElementById('detailPeriod').textContent   = (s.billingPeriodStart || '—') + ' to ' + (s.billingPeriodEnd || '—');
            document.getElementById('detailStatus').innerHTML     = statusBadge(s.status);
            document.getElementById('detailCharged').textContent   = '\u00A3' + parseFloat(s.chargedAmount || 0).toFixed(2);
            document.getElementById('detailTotalCost').textContent = '\u00A3' + parseFloat(s.totalUsageCost || 0).toFixed(2);
            document.getElementById('detailTierCap').textContent   = s.tierCap ? '\u00A3' + parseFloat(s.tierCap).toFixed(2) : 'No cap';
            document.getElementById('detailAmountCharged').textContent = '\u00A3' + parseFloat(s.chargedAmount || 0).toFixed(2);

            // 📋 Populate line items
            const lineItems = s.lineItems || [];
            if (lineItems.length === 0) {
                document.getElementById('lineItemsBody').innerHTML = `
                    <tr><td colspan="6" class="text-center text-muted py-3">No line items available.</td></tr>`;
            } else {
                document.getElementById('lineItemsBody').innerHTML = lineItems.map(li => `
                    <tr>
                        <td>${escapeHtml(li.metricName || '—')}</td>
                        <td>${escapeHtml(li.quantityUsed)}</td>
                        <td>${escapeHtml(li.freeAllowance || '0')}</td>
                        <td>${escapeHtml(li.billableQuantity || '0')}</td>
                        <td>&pound;${escapeHtml(parseFloat(li.rate || 0).toFixed(4))}</td>
                        <td>&pound;${escapeHtml(parseFloat(li.subtotal || 0).toFixed(2))}</td>
                    </tr>
                `).join('');
            }

        } catch (error) {
            document.getElementById('lineItemsBody').innerHTML = `
                <tr><td colspan="6" class="text-center text-danger py-3">
                    <i class="fas fa-exclamation-triangle me-1"></i>Error: ${escapeHtml(error.message)}
                </td></tr>`;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ⚙️ PROCESS BILLING — Batch processing trigger
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⚙️ Show the process billing confirmation modal.
     */
    function showProcessBillingModal() {
        // 📊 Display current unprocessed count
        const countEl = document.getElementById('stat-unprocessed-records');
        const count = countEl ? countEl.textContent.trim() : '0';
        document.getElementById('processBillingRecordCount').textContent = count;
        new bootstrap.Modal(document.getElementById('processBillingModal')).show();
    }

    /**
     * ⚙️ Trigger batch billing processing via the API.
     */
    async function processBilling() {
        const btn = document.getElementById('confirmProcessBtn');
        setButtonLoading(btn, true);

        try {
            const result = await apiPost({ action: 'process_billing' });

            if (result.success) {
                showToast('Billing Processed',
                    '<i class="fas fa-check-circle me-1"></i> ' + escapeHtml(result.message || 'Batch processing completed.'),
                    'bg-success');
                bootstrap.Modal.getInstance(document.getElementById('processBillingModal')).hide();
                // 🔄 Refresh all stats and summaries
                loadStats();
                if (tabLoaded.summaries) { loadSummaries(); }
                if (tabLoaded.records)   { loadUsageRecords(); }
            } else {
                showAlert('<i class="fas fa-times-circle me-1"></i> ' + escapeHtml(result.message || 'Billing processing failed.'), 'danger');
            }
        } catch (error) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Error: ' + escapeHtml(error.message), 'danger');
        } finally {
            setButtonLoading(btn, false);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 📊 STATS — Reload stat card values via AJAX
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📊 Load updated statistics into the stat cards.
     */
    async function loadStats() {
        try {
            const result = await apiGet({ action: 'get_stats' });
            if (!result.success) { return; }

            const s = result.data;
            document.getElementById('stat-total-metrics').textContent      = s.totalMetrics ?? '—';
            document.getElementById('stat-total-rates').textContent        = s.totalRates ?? '—';
            document.getElementById('stat-unprocessed-records').textContent = s.unprocessedRecords ?? '—';
            document.getElementById('stat-pending-summaries').textContent   = s.pendingSummaries ?? '—';
        } catch (error) {
            console.error('Failed to refresh stats:', error);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 🔄 REFRESH ALL — Reload currently active tab and stats
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔄 Refresh all page data — stats and the currently visible tab.
     * Called on initial load and when the user clicks the Refresh button.
     */
    function refreshAll() {
        loadStats();
        loadMetrics();
        // 🔄 Also refresh whichever other tabs have been loaded
        if (tabLoaded.rates)     { loadRates(); }
        if (tabLoaded.records)   { loadUsageRecords(); }
        if (tabLoaded.summaries) { loadSummaries(); }
    }

    // ═══════════════════════════════════════════════════════════════
    // 🎴 TAB SWITCHING — Lazy load tab content on first activation
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🎴 Handle tab shown event — lazy-load content on first view.
     * @see https://getbootstrap.com/docs/5.3/components/navs-tabs/#events
     */
    document.querySelectorAll('#mainTabs button[data-bs-toggle="tab"]').forEach(tab => {
        tab.addEventListener('shown.bs.tab', function(event) {
            const target = event.target.getAttribute('data-bs-target');

            switch (target) {
                case '#pane-rates':
                    if (!tabLoaded.rates) { loadRates(); }
                    break;
                case '#pane-records':
                    // 📝 Records tab requires explicit search, no auto-load
                    break;
                case '#pane-summaries':
                    // 🧾 Summaries tab requires explicit search, no auto-load
                    break;
                case '#pane-metrics':
                    if (!tabLoaded.metrics) { loadMetrics(); }
                    break;
            }
        });
    });

    // ═══════════════════════════════════════════════════════════════
    // 📥 EXPORT FUNCTIONS — CSV client-side, Excel/PDF server-side
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Extract all visible data from a table element.
     * Reads headers from <thead> and rows from <tbody>, skipping any
     * detail/expansion rows.
     *
     * @param {string} tableId - The DOM id of the table to extract data from
     * @returns {Object} { headers: string[], rows: string[][] }
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Element/querySelectorAll
     */
    function extractTableData(tableId) {
        'use strict';
        var table = document.getElementById(tableId);
        if (!table) {
            return { headers: [], rows: [] };
        }

        // 🏷️ Collect header text from <thead> cells
        var headers = [];
        var headerCells = table.querySelectorAll('thead th');
        headerCells.forEach(function(th) {
            headers.push(th.textContent.trim());
        });

        // 📝 Collect row data from <tbody>, skipping detail/expansion rows
        var rows = [];
        var bodyRows = table.querySelectorAll('tbody > tr:not(.billing-detail-row)');
        bodyRows.forEach(function(tr) {
            var row = [];
            tr.querySelectorAll('td').forEach(function(td) {
                row.push(td.textContent.trim());
            });
            if (row.length > 0) {
                rows.push(row);
            }
        });

        return { headers: headers, rows: rows };
    }

    /**
     * 📋 Convert table data to CSV string and trigger browser download.
     * Includes UTF-8 BOM for Excel compatibility.
     *
     * @param {Object} data - { headers: string[], rows: string[][] }
     * @param {string} filename - Download filename (including .csv extension)
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Blob
     */
    function downloadCSV(data, filename) {
        'use strict';
        // 📌 UTF-8 BOM ensures Excel opens the file with correct encoding
        var csv = '\uFEFF';
        csv += data.headers.map(function(h) {
            return '"' + h.replace(/"/g, '""') + '"';
        }).join(',') + '\n';

        data.rows.forEach(function(row) {
            csv += row.map(function(cell) {
                return '"' + String(cell).replace(/"/g, '""') + '"';
            }).join(',') + '\n';
        });

        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
        URL.revokeObjectURL(link.href);
    }

    /**
     * 📊 Export data from a specified table in the chosen format.
     * CSV exports are handled client-side; Excel and PDF are POSTed to the
     * server-side export API endpoint for generation.
     *
     * @param {string} tableId - The DOM id of the table to export
     * @param {string} format  - Export format: 'csv', 'excel', or 'pdf'
     * @param {string} title   - Human-readable title for the export
     * @see /api/v1/export/ — Server-side export endpoint
     */
    function exportData(tableId, format, title) {
        'use strict';
        var data = extractTableData(tableId);

        // ⚠️ Guard: nothing to export if the table body is empty
        if (data.rows.length === 0) {
            showToast('No data to export.', 'warning');
            return;
        }

        // 📁 Build a descriptive filename with ISO date suffix
        var filename = 'signula_' + tableId + '_' + new Date().toISOString().slice(0, 10);

        if (format === 'csv') {
            // 📥 Client-side CSV download
            downloadCSV(data, filename + '.csv');
            showToast('CSV exported successfully.', 'success');
        } else {
            // 📡 Server-side export (Excel / PDF) via hidden form POST
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '/api/v1/export/';
            form.target = '_blank';

            // 🔧 Build hidden fields for the export request
            var fields = {
                'format': format,
                'type': tableId,
                'title': title,
                'data': JSON.stringify(data),
                'csrf_token': csrfToken
            };

            Object.keys(fields).forEach(function(key) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = fields[key];
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 🚀 INITIAL LOAD — Load data when the page is ready
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🎯 Initialise the page by loading metrics, tier options, and metric options.
     * Uses DOMContentLoaded to ensure the DOM is fully parsed before executing.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Document/DOMContentLoaded_event
     */
    document.addEventListener('DOMContentLoaded', function() {
        // 📊 Load metrics for the first (active) tab
        loadMetrics();

        // 🏷️ Load tier options for rate selectors
        loadTiers();

        // 📊 Load metric options for rate form & records filter
        loadMetricOptions();
    });
    </script>
</body>
</html>
