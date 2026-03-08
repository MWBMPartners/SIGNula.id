<?php
/**
 * ============================================================================
 * 📊 SIGNula - Partner Usage Metrics & Billing Management
 * ============================================================================
 *
 * Admin UI for partners to manage their own usage metrics, configure per-tier
 * usage rates, view customer usage records, and review billing summaries.
 * All CRUD operations are performed via AJAX calls to usage-actions.php.
 *
 * Features:
 * - 📊 Stats cards: metrics count, active rates, usage records, billing summaries
 * - 📋 Tab 1: Partner Metrics — create/edit partner-specific usage metrics
 * - 💰 Tab 2: Partner Rates — configure per-tier rates for partner tiers
 * - 📝 Tab 3: Customer Usage — view usage records for partner's customers
 * - 🧾 Tab 4: Customer Billing — view billing summaries with line item detail
 * - 🔍 Modals: create/edit metric, create/edit rate, summary detail
 * - 🔒 CSRF-protected AJAX form submissions
 * - 📱 Fully responsive Bootstrap 5.3 layout
 *
 * Database Tables:
 *   - tblUsageMetrics            (metric definitions — global + partner-specific)
 *   - tblUsageRates              (per-tier pricing for each metric)
 *   - tblUsageRecords            (individual usage events)
 *   - tblUsageBillingSummary     (aggregated billing summaries per cycle)
 *   - tblPartnerSubscriptionTiers (partner's own subscription tiers)
 *
 * API Endpoint: /partners/api/usage-actions.php
 *
 * @see /web/public_html/partners/api/usage-actions.php      API endpoint
 * @see /_database/migrations/018_usage_billing.sql           Schema definition
 * @see https://getbootstrap.com/docs/5.3/                    Bootstrap 5.3
 * @see https://fontawesome.com/icons                          FontAwesome 6.4
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Partners\Admin
 * @version    1.0.0
 * @since      2.7.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 📦 DEPENDENCY LOADING
// ============================================================================

// 🔧 Load core configuration (defines SIGNULA_INIT constant, DB credentials, etc.)
// dirname(__DIR__, 3) resolves to /web/ from /web/public_html/partners/admin/
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton — provides MySQLi prepared statement interface
// @see https://www.php.net/manual/en/book.mysqli.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🔐 Session management — handles login state, session tokens, regeneration
// @see https://www.php.net/manual/en/book.session.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🛡️ Access control — role-based permission checks for partner admin actions
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// ============================================================================
// 🚀 INITIALISATION
// ============================================================================

// 📡 Instantiate core services via singleton/DI pattern
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// ============================================================================
// 🔒 AUTHENTICATION CHECK
// ============================================================================

// 🚫 Redirect unauthenticated users to the login page
// @see https://www.php.net/manual/en/function.header.php
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// ============================================================================
// 🏢 PARTNER SELECTION & VALIDATION
// ============================================================================

// 📋 Get all partner organisations the current user belongs to
// Returns associative array keyed by partnerID with membership details
$memberships = $accessControl->getPartnerMemberships();

// 🚫 If user has no partner memberships, redirect to registration
if (empty($memberships)) {
    header('Location: /partners/register.php');
    exit;
}

// 🎯 Determine which partner is currently selected
// Priority: URL parameter > first available membership
// @see https://www.php.net/manual/en/function.array-key-first.php
$selectedPartnerID = $_GET['partner'] ?? array_key_first($memberships);
$partner = $memberships[$selectedPartnerID] ?? reset($memberships);

// 🛡️ Verify the current user has admin-level access for this partner
// Non-admins (developer, support, finance roles) cannot manage usage metrics
if (!$accessControl->isPartnerAdmin($selectedPartnerID)) {
    http_response_code(403);
    die('Admin access required for this partner');
}

// ============================================================================
// 📊 DATA LOADING
// ============================================================================

// 🏢 Fetch full partner details from tblPartners
// Used for displaying partner name and configuration
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE partnerID = ?");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerDetails = $stmt->get_result()->fetch_assoc();

// 📊 Fetch usage stats for the dashboard cards (server-side initial load)
// 📋 Count of partner-specific metrics
$stmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM tblUsageMetrics WHERE partnerID = ? AND isActive = 1"
);
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerMetricsCount = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

// 📋 Count of global metrics (available to all partners)
$stmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM tblUsageMetrics WHERE partnerID IS NULL AND isActive = 1"
);
$stmt->execute();
$globalMetricsCount = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

// 💰 Count of active rates for partner tiers
$stmt = $db->prepare("
    SELECT COUNT(*) AS cnt
    FROM tblUsageRates ur
    INNER JOIN tblPartnerSubscriptionTiers pst ON ur.tierID = pst.tierID AND ur.tierType = 'partner'
    WHERE pst.partnerID = ? AND ur.isActive = 1
");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$activeRatesCount = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

// 📝 Count of usage records for partner's customers
$stmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM tblUsageRecords WHERE partnerID = ?"
);
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$usageRecordsCount = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

// 🧾 Count of billing summaries for partner's customers
$stmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM tblUsageBillingSummary WHERE partnerID = ?"
);
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$billingSummaryCount = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

// 🏷️ Fetch partner's subscription tiers for rate configuration dropdowns
$stmt = $db->prepare(
    "SELECT tierID, tierName, tierSlug, isActive FROM tblPartnerSubscriptionTiers WHERE partnerID = ? ORDER BY displayOrder ASC, tierName ASC"
);
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerTiers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 🔐 Generate CSRF token for form submissions
// @see SecurityUtils::generateCSRFToken()
$csrfToken = SecurityUtils::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- 🔤 Character encoding and viewport for responsive design -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usage Metrics &amp; Billing - <?php echo htmlspecialchars($partner['companyName']); ?></title>

    <!-- 🎨 Bootstrap 5.3.2 CSS (CDN with SRI integrity hash) -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- 🎨 Font Awesome 6.4.2 Icons (CDN with SRI integrity hash) -->
    <!-- @see https://fontawesome.com/docs/web/setup/get-started -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          rel="stylesheet"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous"
          referrerpolicy="no-referrer">

    <!-- 🎨 Custom page styles -->
    <style>
        /* 📊 Stats cards styling */
        .stats-card {
            border-left: 4px solid;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .stats-card.metrics   { border-left-color: #0d6efd; }
        .stats-card.rates     { border-left-color: #198754; }
        .stats-card.records   { border-left-color: #ffc107; }
        .stats-card.billing   { border-left-color: #6f42c1; }
        .stats-card .stats-icon {
            font-size: 2rem;
            opacity: 0.2;
        }

        /* 📋 Table styling */
        .table-responsive { max-height: 600px; overflow-y: auto; }
        .badge-active   { background-color: #198754; }
        .badge-inactive { background-color: #dc3545; }

        /* 🔄 Loading spinner */
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 2rem;
        }
        .loading-spinner.active { display: block; }

        /* 📊 Tab content padding */
        .tab-pane { padding-top: 1.5rem; }

        /* 🏷️ Aggregation type badge */
        .agg-badge { font-size: 0.75rem; }

        /* 💰 Currency formatting */
        .currency-value { font-family: 'Courier New', monospace; }

        /* 📱 Responsive adjustments for smaller screens */
        @media (max-width: 768px) {
            .stats-card .stats-icon { font-size: 1.5rem; }
            .btn-group-sm .btn { font-size: 0.75rem; }
        }
    </style>
</head>
<body class="bg-light">

    <!-- ====================================================================== -->
    <!-- 📌 NAVIGATION BREADCRUMB                                               -->
    <!-- ====================================================================== -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="/partners/admin/">
                <i class="fas fa-shield-alt me-2"></i>SIGNula Partner Admin
            </a>
        </div>
    </nav>

    <div class="container-fluid py-4">

        <!-- 🔗 Breadcrumb navigation -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/partners/admin/">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Usage Metrics &amp; Billing</li>
            </ol>
        </nav>

        <!-- 📋 Page header with partner name -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1">
                    <i class="fas fa-chart-bar text-primary me-2"></i>Usage Metrics &amp; Billing
                </h1>
                <p class="text-muted mb-0">
                    Manage usage metrics, rates, and view customer billing for
                    <strong><?php echo htmlspecialchars($partnerDetails['companyName'] ?? 'Partner'); ?></strong>
                </p>
            </div>
            <!-- 🏢 Partner selector (if user has multiple partners) -->
            <?php if (count($memberships) > 1): ?>
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle" type="button"
                        id="partnerSelector" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-building me-1"></i><?php echo htmlspecialchars($partner['companyName']); ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="partnerSelector">
                    <?php foreach ($memberships as $pid => $mem): ?>
                    <li>
                        <a class="dropdown-item <?php echo ($pid == $selectedPartnerID) ? 'active' : ''; ?>"
                           href="?partner=<?php echo (int) $pid; ?>">
                            <?php echo htmlspecialchars($mem['companyName']); ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <!-- ================================================================== -->
        <!-- 📊 STATS CARDS ROW                                                 -->
        <!-- ================================================================== -->
        <div class="row g-3 mb-4">
            <!-- 📊 Partner Metrics Count -->
            <div class="col-sm-6 col-xl-3">
                <div class="card stats-card metrics h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Partner Metrics</h6>
                            <h3 class="mb-0" id="statMetrics"><?php echo $partnerMetricsCount; ?></h3>
                            <small class="text-muted">+ <?php echo $globalMetricsCount; ?> global</small>
                        </div>
                        <i class="fas fa-chart-line stats-icon text-primary"></i>
                    </div>
                </div>
            </div>
            <!-- 💰 Active Rates Count -->
            <div class="col-sm-6 col-xl-3">
                <div class="card stats-card rates h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Active Rates</h6>
                            <h3 class="mb-0" id="statRates"><?php echo $activeRatesCount; ?></h3>
                            <small class="text-muted">across partner tiers</small>
                        </div>
                        <i class="fas fa-tags stats-icon text-success"></i>
                    </div>
                </div>
            </div>
            <!-- 📝 Usage Records Count -->
            <div class="col-sm-6 col-xl-3">
                <div class="card stats-card records h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Usage Records</h6>
                            <h3 class="mb-0" id="statRecords"><?php echo number_format($usageRecordsCount); ?></h3>
                            <small class="text-muted">customer events</small>
                        </div>
                        <i class="fas fa-database stats-icon text-warning"></i>
                    </div>
                </div>
            </div>
            <!-- 🧾 Billing Summaries Count -->
            <div class="col-sm-6 col-xl-3">
                <div class="card stats-card billing h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Billing Summaries</h6>
                            <h3 class="mb-0" id="statBilling"><?php echo number_format($billingSummaryCount); ?></h3>
                            <small class="text-muted">generated invoices</small>
                        </div>
                        <i class="fas fa-file-invoice-dollar stats-icon text-purple" style="color: #6f42c1;"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================== -->
        <!-- 📑 TABBED INTERFACE                                                -->
        <!-- ================================================================== -->
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <ul class="nav nav-tabs card-header-tabs" id="usageTabs" role="tablist">
                    <!-- 📊 Tab 1: Partner Metrics -->
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="metrics-tab" data-bs-toggle="tab"
                                data-bs-target="#metricsPane" type="button" role="tab"
                                aria-controls="metricsPane" aria-selected="true">
                            <i class="fas fa-chart-line me-1"></i>Partner Metrics
                        </button>
                    </li>
                    <!-- 💰 Tab 2: Partner Rates -->
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="rates-tab" data-bs-toggle="tab"
                                data-bs-target="#ratesPane" type="button" role="tab"
                                aria-controls="ratesPane" aria-selected="false">
                            <i class="fas fa-tags me-1"></i>Partner Rates
                        </button>
                    </li>
                    <!-- 📝 Tab 3: Customer Usage -->
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="usage-tab" data-bs-toggle="tab"
                                data-bs-target="#usagePane" type="button" role="tab"
                                aria-controls="usagePane" aria-selected="false">
                            <i class="fas fa-list-alt me-1"></i>Customer Usage
                        </button>
                    </li>
                    <!-- 🧾 Tab 4: Customer Billing -->
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="billing-tab" data-bs-toggle="tab"
                                data-bs-target="#billingPane" type="button" role="tab"
                                aria-controls="billingPane" aria-selected="false">
                            <i class="fas fa-file-invoice-dollar me-1"></i>Customer Billing
                        </button>
                    </li>
                </ul>
            </div>

            <div class="card-body">
                <div class="tab-content" id="usageTabContent">

                    <!-- ====================================================== -->
                    <!-- 📊 TAB 1: PARTNER METRICS                              -->
                    <!-- ====================================================== -->
                    <div class="tab-pane fade show active" id="metricsPane" role="tabpanel"
                         aria-labelledby="metrics-tab">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-line text-primary me-2"></i>Partner Usage Metrics
                            </h5>
                            <button class="btn btn-primary btn-sm" id="btnCreateMetric"
                                    data-bs-toggle="modal" data-bs-target="#metricModal">
                                <i class="fas fa-plus me-1"></i>Create Metric
                            </button>
                        </div>
                        <p class="text-muted small mb-3">
                            Define custom usage metrics specific to your service. Global metrics (shared
                            across all partners) are shown for reference but cannot be edited here.
                        </p>

                        <!-- 🔄 Loading spinner -->
                        <div class="loading-spinner" id="metricsLoading">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading metrics...</span>
                            </div>
                            <p class="mt-2 text-muted">Loading metrics...</p>
                        </div>

                        <!-- 📋 Metrics table -->
                        <div class="table-responsive" id="metricsTableContainer">
                            <table class="table table-hover table-striped align-middle" id="metricsTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th scope="col">Code</th>
                                        <th scope="col">Name</th>
                                        <th scope="col">Unit</th>
                                        <th scope="col">Granularity</th>
                                        <th scope="col">Aggregation</th>
                                        <th scope="col">Scope</th>
                                        <th scope="col">Status</th>
                                        <th scope="col" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="metricsTableBody">
                                    <!-- 🔄 Populated via AJAX -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ====================================================== -->
                    <!-- 💰 TAB 2: PARTNER RATES                                -->
                    <!-- ====================================================== -->
                    <div class="tab-pane fade" id="ratesPane" role="tabpanel"
                         aria-labelledby="rates-tab">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0">
                                <i class="fas fa-tags text-success me-2"></i>Usage Rates by Tier
                            </h5>
                            <button class="btn btn-success btn-sm" id="btnCreateRate"
                                    data-bs-toggle="modal" data-bs-target="#rateModal">
                                <i class="fas fa-plus me-1"></i>Create Rate
                            </button>
                        </div>
                        <p class="text-muted small mb-3">
                            Configure per-tier pricing for each usage metric. Set free allowances,
                            unit rates, overage pricing, and optional caps.
                        </p>

                        <!-- 🔄 Loading spinner -->
                        <div class="loading-spinner" id="ratesLoading">
                            <div class="spinner-border text-success" role="status">
                                <span class="visually-hidden">Loading rates...</span>
                            </div>
                            <p class="mt-2 text-muted">Loading rates...</p>
                        </div>

                        <!-- 📋 Rates table -->
                        <div class="table-responsive" id="ratesTableContainer">
                            <table class="table table-hover table-striped align-middle" id="ratesTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th scope="col">Metric</th>
                                        <th scope="col">Tier</th>
                                        <th scope="col">Rate/Unit</th>
                                        <th scope="col">Free Allowance</th>
                                        <th scope="col">Overage Rate</th>
                                        <th scope="col">Cap</th>
                                        <th scope="col">Effective</th>
                                        <th scope="col">Status</th>
                                        <th scope="col" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="ratesTableBody">
                                    <!-- 🔄 Populated via AJAX -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ====================================================== -->
                    <!-- 📝 TAB 3: CUSTOMER USAGE                               -->
                    <!-- ====================================================== -->
                    <div class="tab-pane fade" id="usagePane" role="tabpanel"
                         aria-labelledby="usage-tab">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0">
                                <i class="fas fa-list-alt text-warning me-2"></i>Customer Usage Records
                            </h5>
                            <!-- 📅 Date range filter -->
                            <div class="d-flex gap-2 align-items-center">
                                <label for="usageDateFrom" class="form-label mb-0 small">From:</label>
                                <input type="date" class="form-control form-control-sm" id="usageDateFrom"
                                       value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
                                <label for="usageDateTo" class="form-label mb-0 small">To:</label>
                                <input type="date" class="form-control form-control-sm" id="usageDateTo"
                                       value="<?php echo date('Y-m-d'); ?>">
                                <button class="btn btn-outline-warning btn-sm" id="btnFilterUsage">
                                    <i class="fas fa-filter me-1"></i>Filter
                                </button>
                            </div>
                        </div>

                        <!-- 🔄 Loading spinner -->
                        <div class="loading-spinner" id="usageLoading">
                            <div class="spinner-border text-warning" role="status">
                                <span class="visually-hidden">Loading usage records...</span>
                            </div>
                            <p class="mt-2 text-muted">Loading usage records...</p>
                        </div>

                        <!-- 📋 Usage records table -->
                        <div class="table-responsive" id="usageTableContainer">
                            <table class="table table-hover table-striped align-middle" id="usageTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th scope="col">Record ID</th>
                                        <th scope="col">User</th>
                                        <th scope="col">Metric</th>
                                        <th scope="col">Quantity</th>
                                        <th scope="col">Recorded At</th>
                                        <th scope="col">Billing Period</th>
                                        <th scope="col">Processed</th>
                                    </tr>
                                </thead>
                                <tbody id="usageTableBody">
                                    <!-- 🔄 Populated via AJAX -->
                                </tbody>
                            </table>
                        </div>

                        <!-- 📄 Pagination -->
                        <nav aria-label="Usage records pagination" id="usagePagination" class="mt-3">
                            <ul class="pagination pagination-sm justify-content-center">
                                <!-- 🔄 Populated via JavaScript -->
                            </ul>
                        </nav>
                    </div>

                    <!-- ====================================================== -->
                    <!-- 🧾 TAB 4: CUSTOMER BILLING                             -->
                    <!-- ====================================================== -->
                    <div class="tab-pane fade" id="billingPane" role="tabpanel"
                         aria-labelledby="billing-tab">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0">
                                <i class="fas fa-file-invoice-dollar me-2" style="color: #6f42c1;"></i>
                                Customer Billing Summaries
                            </h5>
                            <!-- 📊 Status filter -->
                            <div class="d-flex gap-2 align-items-center">
                                <label for="billingStatus" class="form-label mb-0 small">Status:</label>
                                <select class="form-select form-select-sm" id="billingStatus" style="width: auto;">
                                    <option value="">All</option>
                                    <option value="pending">Pending</option>
                                    <option value="invoiced">Invoiced</option>
                                    <option value="paid">Paid</option>
                                    <option value="void">Void</option>
                                    <option value="failed">Failed</option>
                                </select>
                                <button class="btn btn-outline-secondary btn-sm" id="btnFilterBilling">
                                    <i class="fas fa-filter me-1"></i>Filter
                                </button>
                            </div>
                        </div>

                        <!-- 🔄 Loading spinner -->
                        <div class="loading-spinner" id="billingLoading">
                            <div class="spinner-border" role="status" style="color: #6f42c1;">
                                <span class="visually-hidden">Loading billing summaries...</span>
                            </div>
                            <p class="mt-2 text-muted">Loading billing summaries...</p>
                        </div>

                        <!-- 📋 Billing summaries table -->
                        <div class="table-responsive" id="billingTableContainer">
                            <table class="table table-hover table-striped align-middle" id="billingTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th scope="col">ID</th>
                                        <th scope="col">User</th>
                                        <th scope="col">Period</th>
                                        <th scope="col">Mode</th>
                                        <th scope="col">Usage Cost</th>
                                        <th scope="col">Capped</th>
                                        <th scope="col">Final Amount</th>
                                        <th scope="col">Status</th>
                                        <th scope="col" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="billingTableBody">
                                    <!-- 🔄 Populated via AJAX -->
                                </tbody>
                            </table>
                        </div>

                        <!-- 📄 Pagination -->
                        <nav aria-label="Billing summaries pagination" id="billingPagination" class="mt-3">
                            <ul class="pagination pagination-sm justify-content-center">
                                <!-- 🔄 Populated via JavaScript -->
                            </ul>
                        </nav>
                    </div>

                </div><!-- /.tab-content -->
            </div><!-- /.card-body -->
        </div><!-- /.card -->

    </div><!-- /.container-fluid -->

    <!-- ====================================================================== -->
    <!-- 📊 METRIC CREATE/EDIT MODAL                                            -->
    <!-- ====================================================================== -->
    <div class="modal fade" id="metricModal" tabindex="-1" aria-labelledby="metricModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="metricModalLabel">
                        <i class="fas fa-chart-line me-2"></i>Create Metric
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                </div>
                <form id="metricForm" novalidate>
                    <!-- 🔒 Hidden fields for CSRF and edit mode -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="metricID" id="metricFormID" value="">

                    <div class="modal-body">
                        <div class="row g-3">
                            <!-- 🏷️ Metric Code -->
                            <div class="col-md-6">
                                <label for="metricCode" class="form-label">
                                    Metric Code <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="metricCode" name="metricCode"
                                       required maxlength="50" pattern="[a-z0-9_]+"
                                       placeholder="e.g. api_calls, storage_gb">
                                <div class="form-text">Lowercase letters, numbers, and underscores only.</div>
                            </div>
                            <!-- 📋 Metric Name -->
                            <div class="col-md-6">
                                <label for="metricName" class="form-label">
                                    Display Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="metricName" name="metricName"
                                       required maxlength="100" placeholder="e.g. API Calls">
                            </div>
                            <!-- 📝 Description -->
                            <div class="col-12">
                                <label for="metricDescription" class="form-label">Description</label>
                                <textarea class="form-control" id="metricDescription" name="metricDescription"
                                          rows="2" maxlength="500"
                                          placeholder="Detailed description of what this metric measures"></textarea>
                            </div>
                            <!-- 📏 Unit Label -->
                            <div class="col-md-4">
                                <label for="unitLabel" class="form-label">Unit Label</label>
                                <input type="text" class="form-control" id="unitLabel" name="unitLabel"
                                       maxlength="30" value="units" placeholder="e.g. calls, GB, emails">
                            </div>
                            <!-- 🔢 Billing Granularity -->
                            <div class="col-md-4">
                                <label for="billingGranularity" class="form-label">Billing Granularity</label>
                                <input type="number" class="form-control" id="billingGranularity"
                                       name="billingGranularity" value="1" min="1">
                                <div class="form-text">Bill per N units (1 = per unit, 1000 = per thousand)</div>
                            </div>
                            <!-- 📈 Aggregation Type -->
                            <div class="col-md-4">
                                <label for="aggregationType" class="form-label">Aggregation</label>
                                <select class="form-select" id="aggregationType" name="aggregationType">
                                    <option value="sum">Sum (total)</option>
                                    <option value="max">Max (peak)</option>
                                    <option value="avg">Average</option>
                                </select>
                            </div>
                            <!-- 🔢 Sort Order -->
                            <div class="col-md-4">
                                <label for="metricSortOrder" class="form-label">Sort Order</label>
                                <input type="number" class="form-control" id="metricSortOrder"
                                       name="sortOrder" value="0" min="0">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" id="btnSaveMetric">
                            <i class="fas fa-save me-1"></i>Save Metric
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- 💰 RATE CREATE/EDIT MODAL                                              -->
    <!-- ====================================================================== -->
    <div class="modal fade" id="rateModal" tabindex="-1" aria-labelledby="rateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="rateModalLabel">
                        <i class="fas fa-tags me-2"></i>Create Rate
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                </div>
                <form id="rateForm" novalidate>
                    <!-- 🔒 Hidden fields for CSRF and edit mode -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="rateID" id="rateFormID" value="">

                    <div class="modal-body">
                        <div class="row g-3">
                            <!-- 📊 Metric Selection -->
                            <div class="col-md-6">
                                <label for="rateMetricID" class="form-label">
                                    Metric <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="rateMetricID" name="metricID" required>
                                    <option value="">-- Select Metric --</option>
                                    <!-- 🔄 Populated via AJAX when modal opens -->
                                </select>
                            </div>
                            <!-- 🏷️ Tier Selection -->
                            <div class="col-md-6">
                                <label for="rateTierID" class="form-label">
                                    Tier <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="rateTierID" name="tierID" required>
                                    <option value="">-- Select Tier --</option>
                                    <?php foreach ($partnerTiers as $tier): ?>
                                    <option value="<?php echo (int) $tier['tierID']; ?>">
                                        <?php echo htmlspecialchars($tier['tierName']); ?>
                                        <?php if (!$tier['isActive']): ?>(inactive)<?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- 💰 Rate Per Unit -->
                            <div class="col-md-4">
                                <label for="ratePerUnit" class="form-label">
                                    Rate Per Unit <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">&pound;</span>
                                    <input type="number" class="form-control" id="ratePerUnit" name="ratePerUnit"
                                           step="0.000001" min="0" value="0.000000" required>
                                </div>
                            </div>
                            <!-- 🎁 Free Allowance -->
                            <div class="col-md-4">
                                <label for="freeAllowance" class="form-label">Free Allowance</label>
                                <input type="number" class="form-control" id="freeAllowance" name="freeAllowance"
                                       step="0.0001" min="0" value="0">
                                <div class="form-text">Included free units per billing cycle</div>
                            </div>
                            <!-- 💰 Overage Rate -->
                            <div class="col-md-4">
                                <label for="overageRate" class="form-label">Overage Rate</label>
                                <div class="input-group">
                                    <span class="input-group-text">&pound;</span>
                                    <input type="number" class="form-control" id="overageRate" name="overageRate"
                                           step="0.000001" min="0" placeholder="Same as rate">
                                </div>
                                <div class="form-text">Leave empty to use standard rate</div>
                            </div>
                            <!-- 🔒 Overage Cap -->
                            <div class="col-md-4">
                                <label for="overageCapUnits" class="form-label">Cap (units)</label>
                                <input type="number" class="form-control" id="overageCapUnits" name="overageCapUnits"
                                       step="0.0001" min="0" placeholder="Unlimited">
                                <div class="form-text">Max billable units per cycle</div>
                            </div>
                            <!-- 💱 Currency -->
                            <div class="col-md-4">
                                <label for="rateCurrency" class="form-label">Currency</label>
                                <select class="form-select" id="rateCurrency" name="currency">
                                    <option value="GBP" selected>GBP (&pound;)</option>
                                    <option value="USD">USD ($)</option>
                                    <option value="EUR">EUR (&euro;)</option>
                                </select>
                            </div>
                            <!-- 📅 Effective From -->
                            <div class="col-md-4">
                                <label for="effectiveFrom" class="form-label">
                                    Effective From <span class="text-danger">*</span>
                                </label>
                                <input type="date" class="form-control" id="effectiveFrom" name="effectiveFrom"
                                       required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <!-- 📅 Effective Until -->
                            <div class="col-md-6">
                                <label for="effectiveUntil" class="form-label">Effective Until</label>
                                <input type="date" class="form-control" id="effectiveUntil" name="effectiveUntil"
                                       placeholder="Indefinite">
                                <div class="form-text">Leave empty for indefinite</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-success" id="btnSaveRate">
                            <i class="fas fa-save me-1"></i>Save Rate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- 🧾 BILLING SUMMARY DETAIL MODAL                                       -->
    <!-- ====================================================================== -->
    <div class="modal fade" id="summaryDetailModal" tabindex="-1"
         aria-labelledby="summaryDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #6f42c1; color: white;">
                    <h5 class="modal-title" id="summaryDetailModalLabel">
                        <i class="fas fa-file-invoice-dollar me-2"></i>Billing Summary Detail
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                </div>
                <div class="modal-body" id="summaryDetailBody">
                    <!-- 🔄 Populated via AJAX -->
                    <div class="text-center py-4">
                        <div class="spinner-border" role="status" style="color: #6f42c1;">
                            <span class="visually-hidden">Loading...</span>
                        </div>
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

    <!-- ====================================================================== -->
    <!-- 📄 FOOTER                                                              -->
    <!-- ====================================================================== -->
    <footer class="text-center text-muted py-4 mt-4">
        <small>&copy; 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.</small>
    </footer>

    <!-- ====================================================================== -->
    <!-- 📦 JAVASCRIPT DEPENDENCIES                                             -->
    <!-- ====================================================================== -->

    <!-- 🎨 Bootstrap 5.3.2 JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- ====================================================================== -->
    <!-- 🔧 PAGE JAVASCRIPT — AJAX Operations & DOM Manipulation                -->
    <!-- ====================================================================== -->
    <script>
    /**
     * ========================================================================
     * 📊 SIGNula Usage Metrics & Billing — Client-Side Controller
     * ========================================================================
     *
     * Handles all AJAX interactions for the usage metrics admin page,
     * including CRUD for metrics, rates, and read-only views for usage
     * records and billing summaries.
     *
     * @see /partners/api/usage-actions.php  Backend API endpoint
     * ========================================================================
     */

    // 🔧 Configuration constants
    const API_URL     = '/partners/api/usage-actions.php';
    const PARTNER_ID  = <?php echo (int) $selectedPartnerID; ?>;
    const CSRF_TOKEN  = '<?php echo htmlspecialchars($csrfToken); ?>';

    // 📄 Pagination state
    let usagePage   = 1;
    let billingPage = 1;
    const PAGE_SIZE = 20;

    // ========================================================================
    // 🚀 INITIALISATION — Load data when page is ready
    // ========================================================================
    document.addEventListener('DOMContentLoaded', function() {
        // 📊 Load metrics on initial page load
        loadMetrics();

        // 📑 Load tab data when tabs are shown (lazy loading)
        document.getElementById('rates-tab').addEventListener('shown.bs.tab', function() {
            loadRates();
        });
        document.getElementById('usage-tab').addEventListener('shown.bs.tab', function() {
            loadCustomerUsage();
        });
        document.getElementById('billing-tab').addEventListener('shown.bs.tab', function() {
            loadCustomerBilling();
        });

        // 📝 Form submission handlers
        document.getElementById('metricForm').addEventListener('submit', handleMetricSubmit);
        document.getElementById('rateForm').addEventListener('submit', handleRateSubmit);

        // 🔘 Filter button handlers
        document.getElementById('btnFilterUsage').addEventListener('click', function() {
            usagePage = 1;
            loadCustomerUsage();
        });
        document.getElementById('btnFilterBilling').addEventListener('click', function() {
            billingPage = 1;
            loadCustomerBilling();
        });

        // ➕ Create button resets forms for new entries
        document.getElementById('btnCreateMetric').addEventListener('click', function() {
            resetMetricForm();
        });
        document.getElementById('btnCreateRate').addEventListener('click', function() {
            resetRateForm();
            loadMetricOptions();
        });

        // 📊 Load stats
        loadStats();
    });

    // ========================================================================
    // 🛡️ UTILITY — XSS-safe HTML escaping
    // ========================================================================

    /**
     * 🔒 Escapes HTML special characters to prevent XSS when inserting user
     * data into the DOM via innerHTML.
     *
     * @param {string} str — The string to escape
     * @returns {string} HTML-escaped string
     *
     * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) { return ''; }
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    /**
     * 📡 Generic AJAX helper — sends a request to the usage API endpoint.
     *
     * @param {string} action   — The action name (e.g. 'get_metrics')
     * @param {string} method   — HTTP method ('GET' or 'POST')
     * @param {Object} data     — Request payload (query params for GET, JSON body for POST)
     * @returns {Promise<Object>} Parsed JSON response
     */
    async function apiCall(action, method, data = {}) {
        data.partnerID = PARTNER_ID;

        if (method === 'GET') {
            // 🔍 Build query string for GET requests
            const params = new URLSearchParams({action, ...data});
            const response = await fetch(API_URL + '?' + params.toString(), {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            });
            return await response.json();
        } else {
            // 📝 Send JSON body for POST requests
            data.action     = action;
            data.csrf_token = CSRF_TOKEN;
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN
                },
                body: JSON.stringify(data)
            });
            return await response.json();
        }
    }

    /**
     * 🔔 Show a Bootstrap toast notification.
     *
     * @param {string} message — The notification message
     * @param {string} type    — Bootstrap colour class (success, danger, warning, info)
     */
    function showToast(message, type = 'success') {
        // 📋 Create a toast container if it doesn't exist
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
        }

        const toastId = 'toast_' + Date.now();
        const iconMap = { success: 'check-circle', danger: 'exclamation-triangle', warning: 'exclamation-circle', info: 'info-circle' };
        const icon = iconMap[type] || 'info-circle';

        container.innerHTML += `
            <div id="${toastId}" class="toast align-items-center text-bg-${escapeHtml(type)} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-${escapeHtml(icon)} me-2"></i>${escapeHtml(message)}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>`;

        const toastEl = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastEl, { autohide: true, delay: 4000 });
        toast.show();

        // 🗑️ Remove toast element after it hides
        toastEl.addEventListener('hidden.bs.toast', function() { toastEl.remove(); });
    }

    // ========================================================================
    // 📊 STATS — Load dashboard statistics
    // ========================================================================

    async function loadStats() {
        try {
            const result = await apiCall('get_stats', 'GET');
            if (result.success && result.data) {
                document.getElementById('statMetrics').textContent = result.data.partnerMetrics || 0;
                document.getElementById('statRates').textContent = result.data.activeRates || 0;
                document.getElementById('statRecords').textContent = Number(result.data.usageRecords || 0).toLocaleString();
                document.getElementById('statBilling').textContent = Number(result.data.billingSummaries || 0).toLocaleString();
            }
        } catch (err) {
            console.error('Failed to load stats:', err);
        }
    }

    // ========================================================================
    // 📊 METRICS — List, Create, Edit, Delete
    // ========================================================================

    /**
     * 📋 Load all metrics (global + partner-specific) and render the table.
     */
    async function loadMetrics() {
        const loading = document.getElementById('metricsLoading');
        loading.classList.add('active');

        try {
            const result = await apiCall('get_metrics', 'GET');
            const tbody = document.getElementById('metricsTableBody');

            if (!result.success || !result.data || result.data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-4">
                    <i class="fas fa-chart-line fa-2x mb-2 d-block opacity-50"></i>
                    No metrics found. Create your first partner metric to get started.
                </td></tr>`;
                return;
            }

            tbody.innerHTML = result.data.map(function(m) {
                const isGlobal = !m.partnerID;
                const scopeBadge = isGlobal
                    ? '<span class="badge bg-secondary">Global</span>'
                    : '<span class="badge bg-primary">Partner</span>';
                const statusBadge = m.isActive == 1
                    ? '<span class="badge badge-active bg-success">Active</span>'
                    : '<span class="badge badge-inactive bg-danger">Inactive</span>';
                const aggBadge = '<span class="badge bg-info agg-badge">' + escapeHtml(m.aggregationType) + '</span>';

                // 🔒 Only allow editing/deleting partner-specific metrics
                let actions = '';
                if (!isGlobal) {
                    actions = `
                        <button class="btn btn-outline-primary btn-sm me-1" onclick="editMetric(${m.metricID})" title="Edit metric">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-outline-danger btn-sm" onclick="deleteMetric(${m.metricID}, '${escapeHtml(m.metricName)}')" title="Delete metric">
                            <i class="fas fa-trash-alt"></i>
                        </button>`;
                } else {
                    actions = '<span class="text-muted small">Read-only</span>';
                }

                return `<tr>
                    <td><code>${escapeHtml(m.metricCode)}</code></td>
                    <td>${escapeHtml(m.metricName)}</td>
                    <td>${escapeHtml(m.unitLabel)}</td>
                    <td>${escapeHtml(String(m.billingGranularity))}</td>
                    <td>${aggBadge}</td>
                    <td>${scopeBadge}</td>
                    <td>${statusBadge}</td>
                    <td class="text-end">${actions}</td>
                </tr>`;
            }).join('');
        } catch (err) {
            showToast('Failed to load metrics: ' + err.message, 'danger');
        } finally {
            loading.classList.remove('active');
        }
    }

    /**
     * ✏️ Open the metric modal in edit mode with existing data.
     */
    async function editMetric(metricID) {
        try {
            const result = await apiCall('get_metrics', 'GET');
            if (!result.success) { showToast('Failed to load metric data', 'danger'); return; }

            const metric = result.data.find(function(m) { return m.metricID == metricID; });
            if (!metric) { showToast('Metric not found', 'danger'); return; }
            if (!metric.partnerID) { showToast('Cannot edit global metrics', 'warning'); return; }

            // 📝 Populate the form with existing data
            document.getElementById('metricFormID').value = metric.metricID;
            document.getElementById('metricCode').value = metric.metricCode || '';
            document.getElementById('metricName').value = metric.metricName || '';
            document.getElementById('metricDescription').value = metric.metricDescription || '';
            document.getElementById('unitLabel').value = metric.unitLabel || 'units';
            document.getElementById('billingGranularity').value = metric.billingGranularity || 1;
            document.getElementById('aggregationType').value = metric.aggregationType || 'sum';
            document.getElementById('metricSortOrder').value = metric.sortOrder || 0;

            // 🏷️ Update modal title for edit mode
            document.getElementById('metricModalLabel').innerHTML =
                '<i class="fas fa-edit me-2"></i>Edit Metric';

            // 📋 Show the modal
            new bootstrap.Modal(document.getElementById('metricModal')).show();
        } catch (err) {
            showToast('Error loading metric: ' + err.message, 'danger');
        }
    }

    /**
     * 🗑️ Soft-delete a partner metric after confirmation.
     */
    async function deleteMetric(metricID, metricName) {
        if (!confirm('Are you sure you want to deactivate the metric "' + metricName + '"?\n\nExisting usage records will not be affected.')) {
            return;
        }

        try {
            const result = await apiCall('delete_metric', 'POST', { metricID: metricID });
            if (result.success) {
                showToast('Metric "' + metricName + '" deactivated successfully', 'success');
                loadMetrics();
                loadStats();
            } else {
                showToast(result.message || 'Failed to deactivate metric', 'danger');
            }
        } catch (err) {
            showToast('Error deleting metric: ' + err.message, 'danger');
        }
    }

    /**
     * 📝 Handle metric form submission (create or update).
     */
    async function handleMetricSubmit(event) {
        event.preventDefault();

        const form = event.target;
        if (!form.checkValidity()) { form.classList.add('was-validated'); return; }

        const metricID = document.getElementById('metricFormID').value;
        const action = metricID ? 'update_metric' : 'create_metric';

        const data = {
            metricCode:         document.getElementById('metricCode').value.trim(),
            metricName:         document.getElementById('metricName').value.trim(),
            metricDescription:  document.getElementById('metricDescription').value.trim(),
            unitLabel:          document.getElementById('unitLabel').value.trim() || 'units',
            billingGranularity: parseInt(document.getElementById('billingGranularity').value) || 1,
            aggregationType:    document.getElementById('aggregationType').value,
            sortOrder:          parseInt(document.getElementById('metricSortOrder').value) || 0
        };

        if (metricID) { data.metricID = parseInt(metricID); }

        try {
            const result = await apiCall(action, 'POST', data);
            if (result.success) {
                showToast(metricID ? 'Metric updated successfully' : 'Metric created successfully', 'success');
                bootstrap.Modal.getInstance(document.getElementById('metricModal')).hide();
                loadMetrics();
                loadStats();
            } else {
                showToast(result.message || 'Failed to save metric', 'danger');
            }
        } catch (err) {
            showToast('Error saving metric: ' + err.message, 'danger');
        }
    }

    /**
     * 🔄 Reset the metric form for creating a new metric.
     */
    function resetMetricForm() {
        document.getElementById('metricForm').reset();
        document.getElementById('metricForm').classList.remove('was-validated');
        document.getElementById('metricFormID').value = '';
        document.getElementById('metricModalLabel').innerHTML =
            '<i class="fas fa-chart-line me-2"></i>Create Metric';
    }

    // ========================================================================
    // 💰 RATES — List, Create, Edit, Delete
    // ========================================================================

    /**
     * 📋 Load all rates for this partner's tiers and render the table.
     */
    async function loadRates() {
        const loading = document.getElementById('ratesLoading');
        loading.classList.add('active');

        try {
            const result = await apiCall('get_rates', 'GET');
            const tbody = document.getElementById('ratesTableBody');

            if (!result.success || !result.data || result.data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="9" class="text-center text-muted py-4">
                    <i class="fas fa-tags fa-2x mb-2 d-block opacity-50"></i>
                    No rates configured. Create your first usage rate to define pricing for your tiers.
                </td></tr>`;
                return;
            }

            tbody.innerHTML = result.data.map(function(r) {
                const statusBadge = r.isActive == 1
                    ? '<span class="badge bg-success">Active</span>'
                    : '<span class="badge bg-danger">Inactive</span>';
                const overageRate = r.overageRate !== null ? '&pound;' + escapeHtml(parseFloat(r.overageRate).toFixed(6)) : '<span class="text-muted">Same</span>';
                const cap = r.overageCapUnits !== null ? escapeHtml(parseFloat(r.overageCapUnits).toFixed(0)) : '<span class="text-muted">None</span>';
                const effectiveStr = escapeHtml(r.effectiveFrom || '') + (r.effectiveUntil ? ' &rarr; ' + escapeHtml(r.effectiveUntil) : ' &rarr; <span class="text-muted">Indefinite</span>');

                return `<tr>
                    <td>${escapeHtml(r.metricName || r.metricCode || '-')}</td>
                    <td>${escapeHtml(r.tierName || '-')}</td>
                    <td class="currency-value">&pound;${escapeHtml(parseFloat(r.ratePerUnit).toFixed(6))}</td>
                    <td>${escapeHtml(parseFloat(r.freeAllowance).toFixed(0))}</td>
                    <td class="currency-value">${overageRate}</td>
                    <td>${cap}</td>
                    <td><small>${effectiveStr}</small></td>
                    <td>${statusBadge}</td>
                    <td class="text-end">
                        <button class="btn btn-outline-success btn-sm me-1" onclick="editRate(${r.rateID})" title="Edit rate">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-outline-danger btn-sm" onclick="deleteRate(${r.rateID})" title="Delete rate">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        } catch (err) {
            showToast('Failed to load rates: ' + err.message, 'danger');
        } finally {
            loading.classList.remove('active');
        }
    }

    /**
     * 📋 Load available metrics into the rate form's metric dropdown.
     */
    async function loadMetricOptions() {
        try {
            const result = await apiCall('get_metrics', 'GET');
            const select = document.getElementById('rateMetricID');
            select.innerHTML = '<option value="">-- Select Metric --</option>';

            if (result.success && result.data) {
                result.data.forEach(function(m) {
                    if (m.isActive != 1) { return; }
                    const scope = m.partnerID ? ' (Partner)' : ' (Global)';
                    const option = document.createElement('option');
                    option.value = m.metricID;
                    option.textContent = m.metricName + scope;
                    select.appendChild(option);
                });
            }
        } catch (err) {
            console.error('Failed to load metric options:', err);
        }
    }

    /**
     * ✏️ Open the rate modal in edit mode with existing data.
     */
    async function editRate(rateID) {
        try {
            await loadMetricOptions();
            const result = await apiCall('get_rates', 'GET');
            if (!result.success) { showToast('Failed to load rate data', 'danger'); return; }

            const rate = result.data.find(function(r) { return r.rateID == rateID; });
            if (!rate) { showToast('Rate not found', 'danger'); return; }

            // 📝 Populate the form
            document.getElementById('rateFormID').value = rate.rateID;
            document.getElementById('rateMetricID').value = rate.metricID;
            document.getElementById('rateTierID').value = rate.tierID;
            document.getElementById('ratePerUnit').value = rate.ratePerUnit || 0;
            document.getElementById('freeAllowance').value = rate.freeAllowance || 0;
            document.getElementById('overageRate').value = rate.overageRate || '';
            document.getElementById('overageCapUnits').value = rate.overageCapUnits || '';
            document.getElementById('rateCurrency').value = rate.currency || 'GBP';
            document.getElementById('effectiveFrom').value = rate.effectiveFrom || '';
            document.getElementById('effectiveUntil').value = rate.effectiveUntil || '';

            document.getElementById('rateModalLabel').innerHTML =
                '<i class="fas fa-edit me-2"></i>Edit Rate';

            new bootstrap.Modal(document.getElementById('rateModal')).show();
        } catch (err) {
            showToast('Error loading rate: ' + err.message, 'danger');
        }
    }

    /**
     * 🗑️ Soft-delete a usage rate after confirmation.
     */
    async function deleteRate(rateID) {
        if (!confirm('Are you sure you want to deactivate this usage rate?')) { return; }

        try {
            const result = await apiCall('delete_rate', 'POST', { rateID: rateID });
            if (result.success) {
                showToast('Rate deactivated successfully', 'success');
                loadRates();
                loadStats();
            } else {
                showToast(result.message || 'Failed to deactivate rate', 'danger');
            }
        } catch (err) {
            showToast('Error deleting rate: ' + err.message, 'danger');
        }
    }

    /**
     * 📝 Handle rate form submission (create or update).
     */
    async function handleRateSubmit(event) {
        event.preventDefault();

        const form = event.target;
        if (!form.checkValidity()) { form.classList.add('was-validated'); return; }

        const rateID = document.getElementById('rateFormID').value;
        const action = rateID ? 'update_rate' : 'create_rate';

        const data = {
            metricID:       parseInt(document.getElementById('rateMetricID').value),
            tierID:         parseInt(document.getElementById('rateTierID').value),
            ratePerUnit:    parseFloat(document.getElementById('ratePerUnit').value) || 0,
            freeAllowance:  parseFloat(document.getElementById('freeAllowance').value) || 0,
            overageRate:    document.getElementById('overageRate').value ? parseFloat(document.getElementById('overageRate').value) : null,
            overageCapUnits: document.getElementById('overageCapUnits').value ? parseFloat(document.getElementById('overageCapUnits').value) : null,
            currency:       document.getElementById('rateCurrency').value,
            effectiveFrom:  document.getElementById('effectiveFrom').value,
            effectiveUntil: document.getElementById('effectiveUntil').value || null
        };

        if (rateID) { data.rateID = parseInt(rateID); }

        try {
            const result = await apiCall(action, 'POST', data);
            if (result.success) {
                showToast(rateID ? 'Rate updated successfully' : 'Rate created successfully', 'success');
                bootstrap.Modal.getInstance(document.getElementById('rateModal')).hide();
                loadRates();
                loadStats();
            } else {
                showToast(result.message || 'Failed to save rate', 'danger');
            }
        } catch (err) {
            showToast('Error saving rate: ' + err.message, 'danger');
        }
    }

    /**
     * 🔄 Reset the rate form for creating a new rate.
     */
    function resetRateForm() {
        document.getElementById('rateForm').reset();
        document.getElementById('rateForm').classList.remove('was-validated');
        document.getElementById('rateFormID').value = '';
        document.getElementById('effectiveFrom').value = new Date().toISOString().split('T')[0];
        document.getElementById('rateModalLabel').innerHTML =
            '<i class="fas fa-tags me-2"></i>Create Rate';
    }

    // ========================================================================
    // 📝 CUSTOMER USAGE — Read-only listing with pagination
    // ========================================================================

    /**
     * 📋 Load customer usage records with date filtering and pagination.
     */
    async function loadCustomerUsage() {
        const loading = document.getElementById('usageLoading');
        loading.classList.add('active');

        const dateFrom = document.getElementById('usageDateFrom').value;
        const dateTo   = document.getElementById('usageDateTo').value;

        try {
            const result = await apiCall('get_customer_usage', 'GET', {
                dateFrom: dateFrom,
                dateTo:   dateTo,
                limit:    PAGE_SIZE,
                offset:   (usagePage - 1) * PAGE_SIZE
            });

            const tbody = document.getElementById('usageTableBody');

            if (!result.success || !result.data || !result.data.records || result.data.records.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">
                    <i class="fas fa-database fa-2x mb-2 d-block opacity-50"></i>
                    No usage records found for the selected period.
                </td></tr>`;
                document.getElementById('usagePagination').querySelector('ul').innerHTML = '';
                return;
            }

            tbody.innerHTML = result.data.records.map(function(r) {
                const processedBadge = r.isProcessed == 1
                    ? '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Yes</span>'
                    : '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>No</span>';

                return `<tr>
                    <td><code>${escapeHtml(String(r.recordID))}</code></td>
                    <td>${escapeHtml(r.userName || 'User #' + r.userID)}</td>
                    <td>${escapeHtml(r.metricName || r.metricCode || '-')}</td>
                    <td>${escapeHtml(parseFloat(r.quantity).toFixed(4))}</td>
                    <td><small>${escapeHtml(r.recordedAt)}</small></td>
                    <td><small>${escapeHtml(r.billingPeriodStart)} &mdash; ${escapeHtml(r.billingPeriodEnd)}</small></td>
                    <td>${processedBadge}</td>
                </tr>`;
            }).join('');

            // 📄 Render pagination
            renderPagination('usagePagination', result.data.total, PAGE_SIZE, usagePage, function(page) {
                usagePage = page;
                loadCustomerUsage();
            });
        } catch (err) {
            showToast('Failed to load usage records: ' + err.message, 'danger');
        } finally {
            loading.classList.remove('active');
        }
    }

    // ========================================================================
    // 🧾 CUSTOMER BILLING — Summaries with detail modal
    // ========================================================================

    /**
     * 📋 Load customer billing summaries with status filtering and pagination.
     */
    async function loadCustomerBilling() {
        const loading = document.getElementById('billingLoading');
        loading.classList.add('active');

        const status = document.getElementById('billingStatus').value;

        try {
            const result = await apiCall('get_customer_summaries', 'GET', {
                status: status,
                limit:  PAGE_SIZE,
                offset: (billingPage - 1) * PAGE_SIZE
            });

            const tbody = document.getElementById('billingTableBody');

            if (!result.success || !result.data || !result.data.summaries || result.data.summaries.length === 0) {
                tbody.innerHTML = `<tr><td colspan="9" class="text-center text-muted py-4">
                    <i class="fas fa-file-invoice-dollar fa-2x mb-2 d-block opacity-50"></i>
                    No billing summaries found.
                </td></tr>`;
                document.getElementById('billingPagination').querySelector('ul').innerHTML = '';
                return;
            }

            tbody.innerHTML = result.data.summaries.map(function(s) {
                const statusColors = { pending: 'warning', invoiced: 'info', paid: 'success', void: 'secondary', failed: 'danger' };
                const statusBadge = '<span class="badge bg-' + (statusColors[s.status] || 'secondary') + '">' + escapeHtml(s.status) + '</span>';
                const capBadge = s.capApplied == 1
                    ? '<span class="badge bg-info"><i class="fas fa-shield-alt me-1"></i>Capped</span>'
                    : '<span class="text-muted">No</span>';

                return `<tr>
                    <td><code>${escapeHtml(String(s.summaryID))}</code></td>
                    <td>${escapeHtml(s.userName || 'User #' + s.userID)}</td>
                    <td><small>${escapeHtml(s.billingPeriodStart)} &mdash; ${escapeHtml(s.billingPeriodEnd)}</small></td>
                    <td><span class="badge bg-secondary">${escapeHtml(s.billingMode)}</span></td>
                    <td class="currency-value">&pound;${escapeHtml(parseFloat(s.totalUsageCost).toFixed(2))}</td>
                    <td>${capBadge}</td>
                    <td class="currency-value fw-bold">&pound;${escapeHtml(parseFloat(s.cappedAmount).toFixed(2))}</td>
                    <td>${statusBadge}</td>
                    <td class="text-end">
                        <button class="btn btn-outline-primary btn-sm" onclick="viewSummaryDetail(${s.summaryID})" title="View detail">
                            <i class="fas fa-eye"></i>
                        </button>
                    </td>
                </tr>`;
            }).join('');

            // 📄 Render pagination
            renderPagination('billingPagination', result.data.total, PAGE_SIZE, billingPage, function(page) {
                billingPage = page;
                loadCustomerBilling();
            });
        } catch (err) {
            showToast('Failed to load billing summaries: ' + err.message, 'danger');
        } finally {
            loading.classList.remove('active');
        }
    }

    /**
     * 👁️ Load and display billing summary detail in a modal.
     */
    async function viewSummaryDetail(summaryID) {
        const modalBody = document.getElementById('summaryDetailBody');
        modalBody.innerHTML = `<div class="text-center py-4">
            <div class="spinner-border" role="status" style="color: #6f42c1;">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>`;

        new bootstrap.Modal(document.getElementById('summaryDetailModal')).show();

        try {
            const result = await apiCall('get_summary_detail', 'GET', { summaryID: summaryID });

            if (!result.success || !result.data) {
                modalBody.innerHTML = '<div class="alert alert-danger">Failed to load summary detail.</div>';
                return;
            }

            const s = result.data;
            let lineItemsHtml = '';

            // 📋 Parse line items from JSON
            let lineItems = [];
            if (typeof s.lineItems === 'string') {
                try { lineItems = JSON.parse(s.lineItems); } catch(e) { lineItems = []; }
            } else if (Array.isArray(s.lineItems)) {
                lineItems = s.lineItems;
            }

            if (lineItems.length > 0) {
                lineItemsHtml = `
                    <h6 class="mt-4 mb-2"><i class="fas fa-list me-2"></i>Line Items</h6>
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Metric</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Free</th>
                                <th class="text-end">Billable</th>
                                <th class="text-end">Rate</th>
                                <th class="text-end">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${lineItems.map(function(li) {
                                return `<tr>
                                    <td>${escapeHtml(li.metricName || li.metricCode || '-')}</td>
                                    <td class="text-end">${escapeHtml(String(li.quantity || 0))}</td>
                                    <td class="text-end">${escapeHtml(String(li.freeAllowance || 0))}</td>
                                    <td class="text-end">${escapeHtml(String(li.billableQuantity || 0))}</td>
                                    <td class="text-end currency-value">&pound;${escapeHtml(parseFloat(li.rate || 0).toFixed(6))}</td>
                                    <td class="text-end currency-value">&pound;${escapeHtml(parseFloat(li.subtotal || 0).toFixed(2))}</td>
                                </tr>`;
                            }).join('')}
                        </tbody>
                    </table>`;
            }

            const capNotice = s.capApplied == 1
                ? `<div class="alert alert-info mt-3">
                       <i class="fas fa-shield-alt me-2"></i>
                       <strong>Cap Applied!</strong> Usage cost of &pound;${escapeHtml(parseFloat(s.totalUsageCost).toFixed(2))} was capped at the tier price of &pound;${escapeHtml(parseFloat(s.tierFixedPrice).toFixed(2))}.
                       Savings: &pound;${escapeHtml(parseFloat(s.savingsAmount).toFixed(2))}
                   </div>`
                : '';

            modalBody.innerHTML = `
                <div class="row g-3">
                    <div class="col-md-6">
                        <strong>Summary ID:</strong> #${escapeHtml(String(s.summaryID))}<br>
                        <strong>User:</strong> ${escapeHtml(s.userName || 'User #' + s.userID)}<br>
                        <strong>Billing Mode:</strong> <span class="badge bg-secondary">${escapeHtml(s.billingMode)}</span>
                    </div>
                    <div class="col-md-6">
                        <strong>Period:</strong> ${escapeHtml(s.billingPeriodStart)} &mdash; ${escapeHtml(s.billingPeriodEnd)}<br>
                        <strong>Status:</strong> <span class="badge bg-${s.status === 'paid' ? 'success' : (s.status === 'pending' ? 'warning' : 'secondary')}">${escapeHtml(s.status)}</span><br>
                        <strong>Calculated:</strong> ${escapeHtml(s.calculatedAt || '-')}
                    </div>
                </div>
                <hr>
                <div class="row g-3">
                    <div class="col-md-4 text-center">
                        <div class="h6 text-muted">Total Usage Cost</div>
                        <div class="h4 currency-value">&pound;${escapeHtml(parseFloat(s.totalUsageCost).toFixed(2))}</div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="h6 text-muted">Tier Fixed Price</div>
                        <div class="h4 currency-value">&pound;${escapeHtml(parseFloat(s.tierFixedPrice).toFixed(2))}</div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="h6 text-muted">Final Amount</div>
                        <div class="h4 currency-value fw-bold text-success">&pound;${escapeHtml(parseFloat(s.cappedAmount).toFixed(2))}</div>
                    </div>
                </div>
                ${capNotice}
                ${lineItemsHtml}
                ${s.notes ? '<div class="mt-3"><strong>Notes:</strong> ' + escapeHtml(s.notes) + '</div>' : ''}
            `;
        } catch (err) {
            modalBody.innerHTML = '<div class="alert alert-danger">Error: ' + escapeHtml(err.message) + '</div>';
        }
    }

    // ========================================================================
    // 📄 PAGINATION — Reusable pagination renderer
    // ========================================================================

    /**
     * 📄 Render pagination controls for a table.
     *
     * @param {string}   containerId — ID of the nav element containing the UL
     * @param {number}   total       — Total number of records
     * @param {number}   pageSize    — Records per page
     * @param {number}   currentPage — Current active page (1-based)
     * @param {Function} onPageClick — Callback when a page is clicked
     */
    function renderPagination(containerId, total, pageSize, currentPage, onPageClick) {
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        const ul = document.getElementById(containerId).querySelector('ul');

        if (totalPages <= 1) { ul.innerHTML = ''; return; }

        let html = '';

        // ◀️ Previous button
        html += `<li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                     <a class="page-link" href="#" data-page="${currentPage - 1}" aria-label="Previous">
                         <span aria-hidden="true">&laquo;</span>
                     </a>
                 </li>`;

        // 📄 Page numbers (show max 7 pages with ellipsis)
        const startPage = Math.max(1, currentPage - 3);
        const endPage   = Math.min(totalPages, currentPage + 3);

        if (startPage > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`;
            if (startPage > 2) { html += '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>'; }
        }

        for (let p = startPage; p <= endPage; p++) {
            html += `<li class="page-item ${p === currentPage ? 'active' : ''}">
                         <a class="page-link" href="#" data-page="${p}">${p}</a>
                     </li>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) { html += '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>'; }
            html += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}">${totalPages}</a></li>`;
        }

        // ▶️ Next button
        html += `<li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}">
                     <a class="page-link" href="#" data-page="${currentPage + 1}" aria-label="Next">
                         <span aria-hidden="true">&raquo;</span>
                     </a>
                 </li>`;

        ul.innerHTML = html;

        // 🔘 Attach click handlers
        ul.querySelectorAll('a.page-link[data-page]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const page = parseInt(this.getAttribute('data-page'));
                if (page >= 1 && page <= totalPages && page !== currentPage) {
                    onPageClick(page);
                }
            });
        });
    }
    </script>

</body>
</html>
