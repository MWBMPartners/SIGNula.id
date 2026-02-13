<?php
/**
 * 💸 Partner Remittances & Payouts Management (Super Admin)
 *
 * Comprehensive dashboard for managing partner remittances (payouts), including
 * pending/processed remittance tables with filtering, batch processing, earnings
 * reports, and remittance statistics. Communicates with the backend API at
 * ../api/remittance-actions.php via AJAX.
 *
 * Features:
 *   - Statistics cards: pending total, processed this month, total remitted, partners awaiting
 *   - Two tabs: Pending Payouts & Processed Payouts (with DataTables)
 *   - Process / Reject individual remittances with confirmation modals
 *   - Batch process multiple pending remittances at once
 *   - Earnings report section with per-partner summary
 *   - Status badges: pending=warning, processing=info, completed=success,
 *     failed=danger, cancelled/rejected=dark
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons — FontAwesome 6.4 Icons
 * @see /web/private_html/payments/ServiceFeeManager.php — Backend service fee logic
 * @see /web/public_html/admin/api/remittance-actions.php — API endpoint
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.4.0-beta
 */

// ═══════════════════════════════════════════════════════════════════════════════
// 🔌 BOOTSTRAP — Load core configuration and backend services
// ═══════════════════════════════════════════════════════════════════════════════

// 📦 Load SIGNula core configuration (defines SIGNULA_INIT, DB credentials, etc.)
// @see https://www.php.net/manual/en/function.require-once.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📦 Load backend service classes for database, session, and access control
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 🔌 Initialise core services — Database singleton, session handler, access control
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// ═══════════════════════════════════════════════════════════════════════════════
// 🔒 AUTHENTICATION & AUTHORISATION CHECKS
// ═══════════════════════════════════════════════════════════════════════════════

// 🔒 Require authenticated user — redirect to login if not signed in
// @see https://www.php.net/manual/en/function.header.php
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Require Super Admin role — blocks non-super-admin users
// @see AccessControl::requireSuperAdmin()
$accessControl->requireSuperAdmin();

// ═══════════════════════════════════════════════════════════════════════════════
// 📊 SERVER-SIDE STATISTICS — Quick stats for initial page render
// ═══════════════════════════════════════════════════════════════════════════════

// 💰 Pending remittance total — sum of all pending remittances
// Uses COALESCE to return 0 if no pending remittances exist
// @see https://dev.mysql.com/doc/refman/8.0/en/comparison-operators.html#function_coalesce
$pendingStmt = $db->prepare(
    "SELECT
        COALESCE(SUM(amount), 0) AS pendingTotal,
        COUNT(*) AS pendingCount,
        COUNT(DISTINCT partnerID) AS partnersAwaiting
     FROM tblRemittances
     WHERE status = 'pending'"
);
$pendingStmt->execute();
$pendingStats = $pendingStmt->get_result()->fetch_assoc();

// 📅 Processed this month — remittances completed in the current calendar month
// Uses YEAR() and MONTH() functions for month-based filtering
// @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html#function_year
$processedMonthStmt = $db->prepare(
    "SELECT
        COALESCE(SUM(amount), 0) AS processedThisMonth,
        COUNT(*) AS processedCount
     FROM tblRemittances
     WHERE status = 'completed'
       AND YEAR(processedAt) = YEAR(CURDATE())
       AND MONTH(processedAt) = MONTH(CURDATE())"
);
$processedMonthStmt->execute();
$processedMonthStats = $processedMonthStmt->get_result()->fetch_assoc();

// 💷 Total remitted all time — lifetime total of completed remittances
$totalRemittedStmt = $db->prepare(
    "SELECT
        COALESCE(SUM(amount), 0) AS totalRemitted,
        COUNT(*) AS totalRemittedCount
     FROM tblRemittances
     WHERE status = 'completed'"
);
$totalRemittedStmt->execute();
$totalRemittedStats = $totalRemittedStmt->get_result()->fetch_assoc();

// 💱 Default currency from settings — used for formatting stat card values
// @see tblSettings key: payment_default_currency
$currencyStmt = $db->prepare(
    "SELECT settingValue FROM tblSettings WHERE settingKey = 'payment_default_currency' LIMIT 1"
);
$currencyStmt->execute();
$currencyResult = $currencyStmt->get_result()->fetch_assoc();
$currency = $currencyResult ? $currencyResult['settingValue'] : 'GBP';

// 💷 Map ISO 4217 currency codes to their display symbols
// @see https://en.wikipedia.org/wiki/ISO_4217
$currencySymbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€', 'AUD' => 'A$', 'CAD' => 'C$'];
$symbol = $currencySymbols[$currency] ?? $currency . ' ';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner Remittances & Payouts - SIGNula Admin</title>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🎨 Bootstrap 5.3.2 (CDN with SRI)                                 -->
    <!-- @see https://getbootstrap.com/docs/5.3/ — Bootstrap Documentation -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🎯 FontAwesome 6.4.2 (CDN with SRI)                               -->
    <!-- @see https://fontawesome.com/icons — FontAwesome Icon Library      -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📦 jQuery 3.7.1 (CDN) — Required for AJAX calls                   -->
    <!-- @see https://api.jquery.com/jQuery.ajax/ — jQuery AJAX Reference   -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
            integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
            crossorigin="anonymous"></script>

    <style>
        /* 🎨 Base body styling — matches existing admin pages */
        body { background-color: #f8f9fa; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Page header — green/teal gradient matching payments theme  */
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
        /* 📊 Stat cards — clickable statistic panels at the top          */
        /* ═══════════════════════════════════════════════════════════════ */
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
            text-align: center;
            cursor: pointer;
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
        /* 📋 Table card — container for data tables with gradient header */
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
        /* 🔍 Filter bar — search/filter controls above tables            */
        /* ═══════════════════════════════════════════════════════════════ */
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🏷️ Remittance status badges — colour-coded status indicators   */
        /* Matches the status ENUM values from tblRemittances             */
        /* ═══════════════════════════════════════════════════════════════ */
        .badge-pending { background-color: #ffc107; color: #333; }
        .badge-processing { background-color: #17a2b8; }
        .badge-completed { background-color: #28a745; }
        .badge-failed { background-color: #dc3545; }
        .badge-rejected { background-color: #343a40; }
        .badge-cancelled { background-color: #343a40; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📄 Pagination info text — shows "Showing X-Y of Z"            */
        /* ═══════════════════════════════════════════════════════════════ */
        .pagination-info {
            color: #6c757d;
            font-size: 0.9rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔄 Loading overlay — semi-transparent spinner over content     */
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
        /* 🎴 Tab navigation — custom styling matching payments dashboard */
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
        /* 📱 Modal detail sections — left-bordered info blocks           */
        /* ═══════════════════════════════════════════════════════════════ */
        .detail-section {
            border-left: 3px solid #11998e;
            padding-left: 1rem;
            margin-bottom: 1rem;
        }
        .detail-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.85rem;
        }
        .detail-value {
            color: #212529;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📊 Earnings report cards — summary cards per partner           */
        /* ═══════════════════════════════════════════════════════════════ */
        .earnings-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: transform 0.2s;
        }
        .earnings-card:hover {
            transform: translateY(-2px);
        }
        .earnings-card .card-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            padding: 1rem 1.25rem;
            font-weight: 600;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* ☑️ Batch selection checkbox styling                             */
        /* ═══════════════════════════════════════════════════════════════ */
        .batch-checkbox {
            width: 1.2em;
            height: 1.2em;
            cursor: pointer;
        }

        /* 📱 Responsive adjustments for smaller screens */
        @media (max-width: 768px) {
            .stat-card .stat-number {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
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
                        <i class="fas fa-money-bill-transfer me-2"></i>Partner Remittances & Payouts
                    </h1>
                    <p class="mb-0 opacity-75">Manage partner payout processing, remittance tracking, and earnings reports</p>
                </div>
                <div class="col-md-4 text-end">
                    <!-- 🔄 Refresh button — reloads all data via AJAX -->
                    <button class="btn btn-light btn-lg" onclick="refreshAll()">
                        <i class="fas fa-sync me-2"></i>Refresh
                    </button>
                </div>
            </div>
        </div>

        <!-- 💬 Alert notification container — dynamically populated by showAlert() -->
        <div id="alertContainer"></div>

        <!-- 🔐 CSRF Token — hidden input for AJAX POST requests -->
        <!-- @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Prevention -->
        <input type="hidden" id="csrf_token" value="<?php echo SecurityUtils::generateCSRFToken(); ?>">

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📊 STAT CARDS — Key remittance metrics at a glance            -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="row g-3 mb-4">
            <!-- 💰 Pending Remittance Total -->
            <div class="col-6 col-lg-3">
                <div class="stat-card" onclick="switchTab('pending')">
                    <div class="stat-number text-warning" id="statPendingTotal">
                        <?php echo htmlspecialchars($symbol) . number_format((float)($pendingStats['pendingTotal'] ?? 0), 2); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-clock me-1"></i>Pending Remittance</div>
                </div>
            </div>

            <!-- 📅 Processed This Month -->
            <div class="col-6 col-lg-3">
                <div class="stat-card" onclick="switchTab('processed')">
                    <div class="stat-number text-success" id="statProcessedMonth">
                        <?php echo htmlspecialchars($symbol) . number_format((float)($processedMonthStats['processedThisMonth'] ?? 0), 2); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-calendar-check me-1"></i>Processed This Month</div>
                </div>
            </div>

            <!-- 💷 Total Remitted All Time -->
            <div class="col-6 col-lg-3">
                <div class="stat-card" onclick="switchTab('processed')">
                    <div class="stat-number text-primary" id="statTotalRemitted">
                        <?php echo htmlspecialchars($symbol) . number_format((float)($totalRemittedStats['totalRemitted'] ?? 0), 2); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-coins me-1"></i>Total Remitted All Time</div>
                </div>
            </div>

            <!-- 👥 Partners Awaiting Payout -->
            <div class="col-6 col-lg-3">
                <div class="stat-card" onclick="switchTab('pending')">
                    <div class="stat-number text-info" id="statPartnersAwaiting">
                        <?php echo number_format((int)($pendingStats['partnersAwaiting'] ?? 0)); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-users me-1"></i>Partners Awaiting Payout</div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🗂️ TAB NAVIGATION — Pending Payouts / Processed Payouts       -->
        <!-- @see https://getbootstrap.com/docs/5.3/components/navs-tabs/  -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <ul class="nav nav-tabs mb-4" id="remittanceTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-pending" data-bs-toggle="tab" data-bs-target="#pane-pending" type="button" role="tab">
                    <i class="fas fa-clock me-1"></i>Pending Payouts
                    <span class="badge bg-warning text-dark ms-1" id="pendingBadge"><?php echo (int)($pendingStats['pendingCount'] ?? 0); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-processed" data-bs-toggle="tab" data-bs-target="#pane-processed" type="button" role="tab">
                    <i class="fas fa-check-circle me-1"></i>Processed Payouts
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-earnings" data-bs-toggle="tab" data-bs-target="#pane-earnings" type="button" role="tab">
                    <i class="fas fa-chart-line me-1"></i>Earnings Report
                </button>
            </li>
        </ul>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📋 TAB CONTENT                                                -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="tab-content" id="remittanceTabContent">

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- ⏳ PENDING PAYOUTS TAB                                 -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade show active" id="pane-pending" role="tabpanel">

                <!-- 🔍 Filter bar for pending remittances -->
                <div class="filter-bar">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><i class="fas fa-building me-1"></i>Partner</label>
                            <select id="pendingPartnerFilter" class="form-select">
                                <option value="">All Partners</option>
                                <!-- 🔄 Populated dynamically via AJAX -->
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-success w-100" onclick="loadPendingRemittances()">
                                <i class="fas fa-filter me-1"></i>Apply
                            </button>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-outline-secondary w-100" onclick="clearPendingFilters()">
                                <i class="fas fa-times me-1"></i>Clear
                            </button>
                        </div>
                        <div class="col-md-4 text-end">
                            <!-- 🔄 Batch Process button — processes all selected pending remittances -->
                            <button class="btn btn-primary" id="batchProcessBtn" onclick="batchProcess()" disabled>
                                <i class="fas fa-tasks me-1"></i>Batch Process (<span id="batchCount">0</span>)
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 📋 Pending remittances table -->
                <div class="card table-card position-relative">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Pending Remittances</h5>
                        <span id="pendingCount" class="badge bg-light text-dark"></span>
                    </div>
                    <!-- 🔄 Loading overlay — shown during AJAX requests -->
                    <div id="pendingLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <!-- ☑️ Select all checkbox for batch operations -->
                                    <th class="text-center" style="width: 40px;">
                                        <input type="checkbox" class="form-check-input batch-checkbox"
                                               id="selectAllPending" onchange="toggleSelectAll(this)"
                                               title="Select all pending remittances">
                                    </th>
                                    <th>Partner</th>
                                    <th>Amount</th>
                                    <th>Currency</th>
                                    <th>Created</th>
                                    <th>Payout Method</th>
                                    <th>Payout Email</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="pendingTableBody">
                                <!-- 🔄 Initial loading state — replaced by AJAX data -->
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>Loading pending remittances...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- ✅ PROCESSED PAYOUTS TAB                               -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pane-processed" role="tabpanel">

                <!-- 🔍 Filter bar for processed remittances -->
                <div class="filter-bar">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><i class="fas fa-building me-1"></i>Partner</label>
                            <select id="processedPartnerFilter" class="form-select">
                                <option value="">All Partners</option>
                                <!-- 🔄 Populated dynamically via AJAX -->
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><i class="fas fa-filter me-1"></i>Status</label>
                            <select id="processedStatusFilter" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="processing">Processing</option>
                                <option value="completed">Completed</option>
                                <option value="failed">Failed</option>
                                <option value="rejected">Rejected</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-success w-100" onclick="loadProcessedRemittances(1)">
                                <i class="fas fa-filter me-1"></i>Apply
                            </button>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-outline-secondary w-100" onclick="clearProcessedFilters()">
                                <i class="fas fa-times me-1"></i>Clear
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 📋 Processed remittances table -->
                <div class="card table-card position-relative">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Processed Remittances</h5>
                        <span id="processedCount" class="badge bg-light text-dark"></span>
                    </div>
                    <!-- 🔄 Loading overlay -->
                    <div id="processedLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Partner</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Processed Date</th>
                                    <th>Processed By</th>
                                    <th>Transaction Ref</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody id="processedTableBody">
                                <!-- 🔄 Initial loading state -->
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>Loading processed remittances...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- 📄 Pagination controls for processed remittances -->
                    <div class="d-flex justify-content-between align-items-center p-3 border-top">
                        <div class="pagination-info" id="processedPaginationInfo">Showing 0 of 0</div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0" id="processedPagination"></ul>
                        </nav>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 📊 EARNINGS REPORT TAB                                 -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pane-earnings" role="tabpanel">

                <!-- 🔍 Filter bar for earnings report -->
                <div class="filter-bar">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><i class="fas fa-building me-1"></i>Partner</label>
                            <select id="earningsPartnerFilter" class="form-select">
                                <option value="">All Partners</option>
                                <!-- 🔄 Populated dynamically via AJAX -->
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><i class="fas fa-calendar me-1"></i>Date From</label>
                            <input type="date" id="earningsDateFrom" class="form-control"
                                   value="<?php echo date('Y-m-01'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><i class="fas fa-calendar me-1"></i>Date To</label>
                            <input type="date" id="earningsDateTo" class="form-control"
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-success w-100" onclick="loadEarningsReport()">
                                <i class="fas fa-chart-line me-1"></i>Generate Report
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 📊 Earnings report content area — populated by AJAX -->
                <div id="earningsReportContent">
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-chart-line fa-3x mb-3 d-block opacity-50"></i>
                        <h5>Select Filters and Generate Report</h5>
                        <p>Choose a partner and date range, then click "Generate Report" to view earnings data.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📱 MODALS                                                         -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- ✅ PROCESS REMITTANCE MODAL — Confirmation before processing   -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="processModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <!-- 🟢 Green gradient header matching payments theme -->
                <div class="modal-header" style="background: linear-gradient(135deg, #11998e, #38ef7d); color: white;">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Process Remittance</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- 🔒 Hidden field to store the remittance ID being processed -->
                    <input type="hidden" id="processRemittanceID">

                    <!-- 📋 Partner & amount details — populated dynamically -->
                    <div class="detail-section mb-3">
                        <div class="detail-label">Partner</div>
                        <div class="detail-value" id="processPartnerName">—</div>
                    </div>
                    <div class="detail-section mb-3">
                        <div class="detail-label">Amount</div>
                        <div class="detail-value fs-4 fw-bold text-success" id="processAmount">—</div>
                    </div>
                    <div class="detail-section mb-3">
                        <div class="detail-label">Payout Method</div>
                        <div class="detail-value" id="processPayoutMethod">—</div>
                    </div>
                    <div class="detail-section mb-4">
                        <div class="detail-label">Payout Email</div>
                        <div class="detail-value" id="processPayoutEmail">—</div>
                    </div>

                    <!-- 📝 Transaction Reference — entered by admin after external payout -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-hashtag me-1"></i>Transaction Reference
                        </label>
                        <input type="text" id="processTransactionRef" class="form-control"
                               placeholder="e.g. PP-TXN-12345, BACS-REF-67890">
                        <small class="form-text text-muted">Enter the payment provider's transaction reference after payout</small>
                    </div>

                    <!-- 📝 Notes — optional admin notes for this remittance -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-sticky-note me-1"></i>Notes (optional)
                        </label>
                        <textarea id="processNotes" class="form-control" rows="3"
                                  placeholder="Any notes about this payout..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="confirmProcessRemittance()">
                        <i class="fas fa-check me-1"></i>Confirm & Process
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- ❌ REJECT REMITTANCE MODAL — Confirmation before rejecting     -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <!-- 🔴 Danger header for rejection actions -->
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Remittance</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- 🔒 Hidden field for the remittance ID being rejected -->
                    <input type="hidden" id="rejectRemittanceID">

                    <!-- 📋 Rejection details -->
                    <div class="detail-section mb-3">
                        <div class="detail-label">Partner</div>
                        <div class="detail-value" id="rejectPartnerName">—</div>
                    </div>
                    <div class="detail-section mb-4">
                        <div class="detail-label">Amount</div>
                        <div class="detail-value fw-bold" id="rejectAmount">—</div>
                    </div>

                    <!-- 📝 Rejection reason — required field -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-comment-alt me-1"></i>Reason for Rejection <span class="text-danger">*</span>
                        </label>
                        <textarea id="rejectReason" class="form-control" rows="3"
                                  placeholder="Please provide a reason for rejecting this remittance..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmRejectRemittance()">
                        <i class="fas fa-times me-1"></i>Reject Remittance
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- 🔄 BATCH PROCESS CONFIRMATION MODAL                            -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="batchConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #11998e, #38ef7d); color: white;">
                    <h5 class="modal-title"><i class="fas fa-tasks me-2"></i>Batch Process Remittances</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        You are about to process <strong id="batchConfirmCount">0</strong> remittances.
                        This action cannot be undone.
                    </div>
                    <p>All selected pending remittances will be moved to "processing" status. You will need to complete the actual payouts externally.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="confirmBatchProcess()">
                        <i class="fas fa-check-double me-1"></i>Confirm Batch Process
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🔐 Bootstrap 5.3.2 JS Bundle (CDN with SRI)                       -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <script>
    /**
     * 💸 Partner Remittances & Payouts — Client-Side Logic
     *
     * Handles AJAX data loading, filtering, pagination, batch processing,
     * process/reject actions, and earnings report generation.
     * Communicates with the backend API at ../api/remittance-actions.php
     *
     * @see https://api.jquery.com/jQuery.ajax/ — jQuery AJAX Reference
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API — Fetch API
     */

    // ═══════════════════════════════════════════════════════════════
    // 🔧 STATE TRACKING
    // ═══════════════════════════════════════════════════════════════

    /** @type {number} Current page for processed remittances pagination */
    let currentProcessedPage = 1;

    /** @type {number} Records per page for processed remittances */
    const perPage = 25;

    /** @type {string} Currency symbol for formatting — injected from PHP */
    const currencySymbol = '<?php echo htmlspecialchars($symbol); ?>';

    /** @type {Set} Set of selected remittance IDs for batch processing */
    let selectedRemittanceIDs = new Set();

    // ═══════════════════════════════════════════════════════════════
    // 🛠️ UTILITY FUNCTIONS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔒 Escape HTML entities to prevent XSS attacks
     * Creates a temporary text node to safely encode user-supplied content.
     *
     * @param {string} text — Raw text to escape
     * @returns {string} HTML-safe escaped string
     * @see https://owasp.org/www-community/attacks/xss/ — OWASP XSS Prevention
     */
    function escapeHtml(text) {
        if (!text) { return ''; }
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * 💬 Show a Bootstrap alert notification in the alert container.
     * Alerts auto-dismiss after 5 seconds.
     *
     * @param {string} message — Alert message content (can include HTML)
     * @param {string} type — Bootstrap alert type: success, danger, warning, info
     * @see https://getbootstrap.com/docs/5.3/components/alerts/ — Bootstrap Alerts
     */
    function showAlert(message, type) {
        type = type || 'info';
        var alertEl = $('<div class="alert alert-' + type + ' alert-dismissible fade show">' +
            message +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
            '</div>');
        $('#alertContainer').append(alertEl);
        // ⏰ Auto-dismiss after 5 seconds
        setTimeout(function() {
            alertEl.alert('close');
        }, 5000);
    }

    /**
     * 📅 Format an ISO date string for human-readable display
     *
     * @param {string} dateStr — ISO 8601 date string from the API
     * @returns {string} Formatted date (e.g., "13 Feb 2026")
     * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date/toLocaleDateString
     */
    function formatDate(dateStr) {
        if (!dateStr) { return '—'; }
        var d = new Date(dateStr);
        return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    /**
     * 📅 Format date with time for more detailed display
     *
     * @param {string} dateStr — ISO 8601 date string from the API
     * @returns {string} Formatted date with time (e.g., "13 Feb 2026, 14:30")
     */
    function formatDateTime(dateStr) {
        if (!dateStr) { return '—'; }
        var d = new Date(dateStr);
        return d.toLocaleDateString('en-GB', {
            day: 'numeric', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    }

    /**
     * 💰 Format currency amount with the configured currency symbol
     *
     * @param {number|string} amount — Amount to format
     * @param {string|null} cur — Optional currency code to show instead of symbol
     * @returns {string} Formatted amount with currency symbol
     */
    function formatCurrency(amount, cur) {
        var num = parseFloat(amount) || 0;
        if (cur) {
            return cur + ' ' + num.toFixed(2);
        }
        return currencySymbol + num.toFixed(2);
    }

    /**
     * 🏷️ Get a status badge HTML string with appropriate colour coding
     *
     * @param {string} status — Remittance status value
     * @returns {string} Bootstrap badge HTML
     */
    function statusBadge(status) {
        var s = (status || '').toLowerCase();
        return '<span class="badge badge-' + s + '">' + escapeHtml(status) + '</span>';
    }

    /**
     * 🎨 Get a human-readable label for payout method
     *
     * @param {string} method — Payout method from the database
     * @returns {string} Formatted payout method label
     */
    function formatPayoutMethod(method) {
        var labels = {
            'paypal_payout': '<i class="fa-brands fa-paypal me-1"></i>PayPal Payout',
            'bank_transfer': '<i class="fas fa-university me-1"></i>Bank Transfer',
            'credit_to_balance': '<i class="fas fa-wallet me-1"></i>Credit to Balance',
            'manual': '<i class="fas fa-hand-holding-usd me-1"></i>Manual'
        };
        return labels[method] || escapeHtml(method || '—');
    }

    /**
     * 🗂️ Switch to a specific tab programmatically
     *
     * @param {string} tabName — Tab identifier: 'pending', 'processed', 'earnings'
     * @see https://getbootstrap.com/docs/5.3/components/navs-tabs/#via-javascript
     */
    function switchTab(tabName) {
        var tabEl = document.getElementById('tab-' + tabName);
        if (tabEl) {
            new bootstrap.Tab(tabEl).show();
        }
    }

    /**
     * 🔐 Get the CSRF token value from the hidden input field
     *
     * @returns {string} CSRF token value
     */
    function getCsrfToken() {
        return $('#csrf_token').val();
    }

    // ═══════════════════════════════════════════════════════════════
    // ⏳ PENDING REMITTANCES
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Load pending remittances from the API with optional partner filter
     * Calls GET ../api/remittance-actions.php?action=get_pending_remittances
     *
     * @see ServiceFeeManager::getPendingRemittances() — Backend method
     */
    function loadPendingRemittances() {
        // 🔄 Show loading overlay
        $('#pendingLoading').show();

        // 🔄 Clear batch selection state
        selectedRemittanceIDs.clear();
        updateBatchButton();
        $('#selectAllPending').prop('checked', false);

        // 📤 Build request parameters
        var params = { action: 'get_pending_remittances' };
        var partnerID = $('#pendingPartnerFilter').val();
        if (partnerID) {
            params.partnerID = partnerID;
        }

        // 📡 AJAX GET request to the remittance API
        $.ajax({
            url: '../api/remittance-actions.php',
            method: 'GET',
            data: params,
            dataType: 'json',
            success: function(result) {
                if (result.success) {
                    renderPendingRemittances(result.data || []);
                    $('#pendingCount').text((result.data || []).length + ' pending');
                    $('#pendingBadge').text((result.data || []).length);
                } else {
                    showAlert(result.message || 'Failed to load pending remittances', 'danger');
                }
            },
            error: function(xhr, status, error) {
                // ⚠️ Network/parsing error
                showAlert('Error loading pending remittances: ' + escapeHtml(error), 'danger');
            },
            complete: function() {
                // 🔄 Hide loading overlay regardless of success/failure
                $('#pendingLoading').hide();
            }
        });
    }

    /**
     * 📋 Render pending remittance rows into the pending table
     *
     * @param {Array} remittances — Array of pending remittance objects from API
     */
    function renderPendingRemittances(remittances) {
        var tbody = $('#pendingTableBody');

        // 📭 Handle empty state
        if (!remittances || remittances.length === 0) {
            tbody.html(
                '<tr>' +
                    '<td colspan="8" class="text-center py-5 text-muted">' +
                        '<i class="fas fa-check-circle fa-3x mb-3 d-block opacity-50"></i>' +
                        '<h5>No Pending Remittances</h5>' +
                        '<p>All partner payouts are up to date.</p>' +
                    '</td>' +
                '</tr>'
            );
            return;
        }

        // 📋 Build table rows for each pending remittance
        var rows = '';
        $.each(remittances, function(index, r) {
            rows += '<tr>' +
                // ☑️ Batch selection checkbox
                '<td class="text-center">' +
                    '<input type="checkbox" class="form-check-input batch-checkbox batch-item" ' +
                           'value="' + r.remittanceID + '" ' +
                           'onchange="toggleRemittanceSelection(' + r.remittanceID + ', this)" ' +
                           'title="Select for batch processing">' +
                '</td>' +
                // 👤 Partner name
                '<td>' +
                    '<strong>' + escapeHtml(r.partnerName || 'Unknown') + '</strong>' +
                    (r.billingEmail ? '<br><small class="text-muted">' + escapeHtml(r.billingEmail) + '</small>' : '') +
                '</td>' +
                // 💰 Amount
                '<td class="fw-bold text-success">' + formatCurrency(r.amount, r.currency) + '</td>' +
                // 💱 Currency
                '<td>' + escapeHtml(r.currency || '—') + '</td>' +
                // 📅 Created date
                '<td>' + formatDate(r.createdAt) + '</td>' +
                // 🔌 Payout method
                '<td>' + formatPayoutMethod(r.method || r.partnerPayoutMethod) + '</td>' +
                // 📧 Payout email
                '<td><small class="text-muted">' + escapeHtml(r.paypalEmail || r.payoutEmail || '—') + '</small></td>' +
                // 🔧 Action buttons — Process & Reject
                '<td class="text-center">' +
                    '<div class="btn-group btn-group-sm">' +
                        '<button class="btn btn-outline-success" title="Process Payout" ' +
                                'onclick="showProcessModal(' + r.remittanceID + ', ' +
                                    '\'' + escapeHtml(r.partnerName || '') + '\', ' +
                                    '\'' + formatCurrency(r.amount, r.currency) + '\', ' +
                                    '\'' + escapeHtml(r.method || r.partnerPayoutMethod || '') + '\', ' +
                                    '\'' + escapeHtml(r.paypalEmail || r.payoutEmail || '') + '\')">' +
                            '<i class="fas fa-check"></i>' +
                        '</button>' +
                        '<button class="btn btn-outline-danger" title="Reject" ' +
                                'onclick="showRejectModal(' + r.remittanceID + ', ' +
                                    '\'' + escapeHtml(r.partnerName || '') + '\', ' +
                                    '\'' + formatCurrency(r.amount, r.currency) + '\')">' +
                            '<i class="fas fa-times"></i>' +
                        '</button>' +
                    '</div>' +
                '</td>' +
            '</tr>';
        });

        tbody.html(rows);
    }

    /**
     * 🧹 Clear pending remittance filters and reload
     */
    function clearPendingFilters() {
        $('#pendingPartnerFilter').val('');
        loadPendingRemittances();
    }

    // ═══════════════════════════════════════════════════════════════
    // ✅ PROCESSED REMITTANCES
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Load processed remittances from the API with pagination and filters
     * Calls GET ../api/remittance-actions.php?action=get_processed_remittances
     *
     * @param {number} page — Page number to load (default: 1)
     */
    function loadProcessedRemittances(page) {
        page = page || 1;
        currentProcessedPage = page;

        // 🔄 Show loading overlay
        $('#processedLoading').show();

        // 📤 Build request parameters with pagination and filters
        var params = {
            action: 'get_processed_remittances',
            limit: perPage,
            offset: (page - 1) * perPage
        };

        // 🏢 Optional partner filter
        var partnerID = $('#processedPartnerFilter').val();
        if (partnerID) {
            params.partnerID = partnerID;
        }

        // 📊 Optional status filter
        var status = $('#processedStatusFilter').val();
        if (status) {
            params.status = status;
        }

        // 📡 AJAX GET request
        $.ajax({
            url: '../api/remittance-actions.php',
            method: 'GET',
            data: params,
            dataType: 'json',
            success: function(result) {
                if (result.success) {
                    renderProcessedRemittances(result.data || []);
                    renderProcessedPagination(result.total || 0, result.pages || 1, result.currentPage || page);
                    $('#processedCount').text((result.total || 0) + ' remittances');
                } else {
                    showAlert(result.message || 'Failed to load processed remittances', 'danger');
                }
            },
            error: function(xhr, status, error) {
                showAlert('Error loading processed remittances: ' + escapeHtml(error), 'danger');
            },
            complete: function() {
                $('#processedLoading').hide();
            }
        });
    }

    /**
     * 📋 Render processed remittance rows into the processed table
     *
     * @param {Array} remittances — Array of processed remittance objects from API
     */
    function renderProcessedRemittances(remittances) {
        var tbody = $('#processedTableBody');

        // 📭 Handle empty state
        if (!remittances || remittances.length === 0) {
            tbody.html(
                '<tr>' +
                    '<td colspan="7" class="text-center py-5 text-muted">' +
                        '<i class="fas fa-receipt fa-3x mb-3 d-block opacity-50"></i>' +
                        '<h5>No Processed Remittances</h5>' +
                        '<p>No remittances match your current filters.</p>' +
                    '</td>' +
                '</tr>'
            );
            return;
        }

        // 📋 Build table rows
        var rows = '';
        $.each(remittances, function(index, r) {
            // 📝 Extract notes from metadata JSON (if present)
            var notes = '';
            if (r.metadata) {
                try {
                    var meta = typeof r.metadata === 'string' ? JSON.parse(r.metadata) : r.metadata;
                    notes = meta.notes || meta.transactionReference || '';
                } catch (e) {
                    notes = '';
                }
            }

            rows += '<tr>' +
                // 👤 Partner name
                '<td>' +
                    '<strong>' + escapeHtml(r.partnerName || 'Unknown') + '</strong>' +
                '</td>' +
                // 💰 Amount
                '<td class="fw-bold">' + formatCurrency(r.amount, r.currency) + '</td>' +
                // 🏷️ Status badge
                '<td>' + statusBadge(r.status) + '</td>' +
                // 📅 Processed date
                '<td>' + formatDateTime(r.processedAt) + '</td>' +
                // 👤 Processed by (admin name)
                '<td>' + escapeHtml(r.processedByName || '—') + '</td>' +
                // 🔗 Transaction reference
                '<td><code class="small">' + escapeHtml(r.transactionReference || '—') + '</code></td>' +
                // 📝 Notes
                '<td><small class="text-muted">' + escapeHtml(notes || '—') + '</small></td>' +
            '</tr>';
        });

        tbody.html(rows);
    }

    /**
     * 📄 Render pagination controls for the processed remittances table
     *
     * @param {number} total — Total number of matching records
     * @param {number} pages — Total number of pages
     * @param {number} current — Current page number
     */
    function renderProcessedPagination(total, pages, current) {
        // 📊 Calculate display range
        var start = Math.min(total, (current - 1) * perPage + 1);
        var end = Math.min(total, current * perPage);
        $('#processedPaginationInfo').text('Showing ' + start + '-' + end + ' of ' + total);

        var nav = $('#processedPagination');
        nav.empty();

        // 📄 No pagination needed for single page
        if (pages <= 1) { return; }

        // ⬅️ Previous page button
        nav.append(
            '<li class="page-item ' + (current === 1 ? 'disabled' : '') + '">' +
                '<a class="page-link" href="#" onclick="loadProcessedRemittances(' + (current - 1) + '); return false;">&laquo;</a>' +
            '</li>'
        );

        // 📊 Page number buttons (show max 5 pages)
        var startPage = Math.max(1, current - 2);
        var endPage = Math.min(pages, startPage + 4);
        for (var i = startPage; i <= endPage; i++) {
            nav.append(
                '<li class="page-item ' + (i === current ? 'active' : '') + '">' +
                    '<a class="page-link" href="#" onclick="loadProcessedRemittances(' + i + '); return false;">' + i + '</a>' +
                '</li>'
            );
        }

        // ➡️ Next page button
        nav.append(
            '<li class="page-item ' + (current === pages ? 'disabled' : '') + '">' +
                '<a class="page-link" href="#" onclick="loadProcessedRemittances(' + (current + 1) + '); return false;">&raquo;</a>' +
            '</li>'
        );
    }

    /**
     * 🧹 Clear processed remittance filters and reload
     */
    function clearProcessedFilters() {
        $('#processedPartnerFilter').val('');
        $('#processedStatusFilter').val('');
        loadProcessedRemittances(1);
    }

    // ═══════════════════════════════════════════════════════════════
    // ☑️ BATCH SELECTION
    // ═══════════════════════════════════════════════════════════════

    /**
     * ☑️ Toggle selection of a single remittance for batch processing
     *
     * @param {number} remittanceID — The remittance ID to toggle
     * @param {HTMLElement} checkbox — The checkbox element that was clicked
     */
    function toggleRemittanceSelection(remittanceID, checkbox) {
        if (checkbox.checked) {
            selectedRemittanceIDs.add(remittanceID);
        } else {
            selectedRemittanceIDs.delete(remittanceID);
        }
        updateBatchButton();
    }

    /**
     * ☑️ Toggle select/deselect all pending remittances
     *
     * @param {HTMLElement} masterCheckbox — The "Select All" checkbox element
     */
    function toggleSelectAll(masterCheckbox) {
        var isChecked = masterCheckbox.checked;
        $('.batch-item').each(function() {
            this.checked = isChecked;
            var id = parseInt(this.value);
            if (isChecked) {
                selectedRemittanceIDs.add(id);
            } else {
                selectedRemittanceIDs.delete(id);
            }
        });
        updateBatchButton();
    }

    /**
     * 🔄 Update the batch process button state (enable/disable + count)
     */
    function updateBatchButton() {
        var count = selectedRemittanceIDs.size;
        $('#batchCount').text(count);
        $('#batchProcessBtn').prop('disabled', count === 0);
    }

    /**
     * 🔄 Show batch process confirmation modal
     */
    function batchProcess() {
        if (selectedRemittanceIDs.size === 0) {
            showAlert('Please select at least one remittance to process.', 'warning');
            return;
        }
        $('#batchConfirmCount').text(selectedRemittanceIDs.size);
        new bootstrap.Modal(document.getElementById('batchConfirmModal')).show();
    }

    /**
     * 🔄 Confirm and execute batch processing of selected remittances
     * Calls POST ../api/remittance-actions.php with action=batch_process
     */
    function confirmBatchProcess() {
        var ids = Array.from(selectedRemittanceIDs);

        // 📡 AJAX POST request for batch processing
        $.ajax({
            url: '../api/remittance-actions.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'batch_process',
                remittanceIDs: ids,
                csrf_token: getCsrfToken()
            }),
            dataType: 'json',
            success: function(result) {
                if (result.success) {
                    showAlert(
                        '<i class="fas fa-check-circle me-1"></i> ' +
                        result.message || (ids.length + ' remittances processed successfully'),
                        'success'
                    );
                    // 🔄 Close modal and refresh data
                    bootstrap.Modal.getInstance(document.getElementById('batchConfirmModal')).hide();
                    refreshAll();
                } else {
                    showAlert(result.message || 'Batch processing failed', 'danger');
                }
            },
            error: function(xhr, status, error) {
                showAlert('Error processing batch: ' + escapeHtml(error), 'danger');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // 📱 MODAL ACTIONS — Process & Reject
    // ═══════════════════════════════════════════════════════════════

    /**
     * ✅ Show the process remittance confirmation modal
     *
     * @param {number} remittanceID — The remittance ID to process
     * @param {string} partnerName — Partner display name
     * @param {string} amount — Formatted amount string
     * @param {string} payoutMethod — Payout method identifier
     * @param {string} payoutEmail — Payout email address
     */
    function showProcessModal(remittanceID, partnerName, amount, payoutMethod, payoutEmail) {
        // 📋 Populate modal fields with remittance details
        $('#processRemittanceID').val(remittanceID);
        $('#processPartnerName').text(partnerName);
        $('#processAmount').text(amount);
        $('#processPayoutMethod').html(formatPayoutMethod(payoutMethod));
        $('#processPayoutEmail').text(payoutEmail || 'Not specified');
        $('#processTransactionRef').val('');
        $('#processNotes').val('');

        // 📱 Show the modal
        new bootstrap.Modal(document.getElementById('processModal')).show();
    }

    /**
     * ✅ Confirm and execute processing of a single remittance
     * Calls POST ../api/remittance-actions.php with action=process_remittance
     *
     * @see ServiceFeeManager::processRemittance() — Backend method
     */
    function confirmProcessRemittance() {
        var remittanceID = parseInt($('#processRemittanceID').val());
        var transactionRef = $('#processTransactionRef').val().trim();
        var notes = $('#processNotes').val().trim();

        // 📡 AJAX POST request to process the remittance
        $.ajax({
            url: '../api/remittance-actions.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'process_remittance',
                remittanceID: remittanceID,
                transactionReference: transactionRef,
                notes: notes,
                csrf_token: getCsrfToken()
            }),
            dataType: 'json',
            success: function(result) {
                if (result.success) {
                    showAlert(
                        '<i class="fas fa-check-circle me-1"></i> Remittance processed successfully',
                        'success'
                    );
                    // 🔄 Close modal and refresh data
                    bootstrap.Modal.getInstance(document.getElementById('processModal')).hide();
                    refreshAll();
                } else {
                    showAlert(result.message || 'Failed to process remittance', 'danger');
                }
            },
            error: function(xhr, status, error) {
                showAlert('Error processing remittance: ' + escapeHtml(error), 'danger');
            }
        });
    }

    /**
     * ❌ Show the reject remittance confirmation modal
     *
     * @param {number} remittanceID — The remittance ID to reject
     * @param {string} partnerName — Partner display name
     * @param {string} amount — Formatted amount string
     */
    function showRejectModal(remittanceID, partnerName, amount) {
        // 📋 Populate modal fields
        $('#rejectRemittanceID').val(remittanceID);
        $('#rejectPartnerName').text(partnerName);
        $('#rejectAmount').text(amount);
        $('#rejectReason').val('');

        // 📱 Show the modal
        new bootstrap.Modal(document.getElementById('rejectModal')).show();
    }

    /**
     * ❌ Confirm and execute rejection of a remittance
     * Calls POST ../api/remittance-actions.php with action=reject_remittance
     */
    function confirmRejectRemittance() {
        var remittanceID = parseInt($('#rejectRemittanceID').val());
        var reason = $('#rejectReason').val().trim();

        // 🔍 Validate — rejection reason is required
        if (!reason) {
            showAlert('Please provide a reason for rejection.', 'warning');
            return;
        }

        // 📡 AJAX POST request to reject the remittance
        $.ajax({
            url: '../api/remittance-actions.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'reject_remittance',
                remittanceID: remittanceID,
                reason: reason,
                csrf_token: getCsrfToken()
            }),
            dataType: 'json',
            success: function(result) {
                if (result.success) {
                    showAlert(
                        '<i class="fas fa-check-circle me-1"></i> Remittance rejected successfully',
                        'success'
                    );
                    // 🔄 Close modal and refresh data
                    bootstrap.Modal.getInstance(document.getElementById('rejectModal')).hide();
                    refreshAll();
                } else {
                    showAlert(result.message || 'Failed to reject remittance', 'danger');
                }
            },
            error: function(xhr, status, error) {
                showAlert('Error rejecting remittance: ' + escapeHtml(error), 'danger');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // 📊 EARNINGS REPORT
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📊 Load earnings report from the API based on selected filters
     * Calls GET ../api/remittance-actions.php?action=get_earnings_report
     *
     * @see ServiceFeeManager::getEarningsReport() — Backend method
     */
    function loadEarningsReport() {
        var partnerID = $('#earningsPartnerFilter').val();
        var dateFrom = $('#earningsDateFrom').val();
        var dateTo = $('#earningsDateTo').val();

        // 🔍 Validate — date range is required
        if (!dateFrom || !dateTo) {
            showAlert('Please select both start and end dates for the report.', 'warning');
            return;
        }

        // 🔄 Show loading state in the report area
        $('#earningsReportContent').html(
            '<div class="text-center py-5">' +
                '<i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>' +
                '<p class="text-muted">Generating earnings report...</p>' +
            '</div>'
        );

        // 📤 Build request parameters
        var params = {
            action: 'get_earnings_report',
            dateFrom: dateFrom,
            dateTo: dateTo
        };
        if (partnerID) {
            params.partnerID = partnerID;
        }

        // 📡 AJAX GET request for the earnings report
        $.ajax({
            url: '../api/remittance-actions.php',
            method: 'GET',
            data: params,
            dataType: 'json',
            success: function(result) {
                if (result.success) {
                    renderEarningsReport(result.data || {});
                } else {
                    $('#earningsReportContent').html(
                        '<div class="alert alert-warning">' +
                            '<i class="fas fa-exclamation-triangle me-2"></i>' +
                            escapeHtml(result.message || 'Failed to generate report') +
                        '</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                $('#earningsReportContent').html(
                    '<div class="alert alert-danger">' +
                        '<i class="fas fa-times-circle me-2"></i>' +
                        'Error generating report: ' + escapeHtml(error) +
                    '</div>'
                );
            }
        });
    }

    /**
     * 📊 Render the earnings report data into the report content area
     *
     * @param {Object} report — Earnings report data from API
     */
    function renderEarningsReport(report) {
        var currency = report.currency || '<?php echo htmlspecialchars($currency); ?>';
        var html = '';

        // 📊 Summary stat cards row
        html += '<div class="row g-3 mb-4">';
        html += '<div class="col-md-3">';
        html += '  <div class="stat-card">';
        html += '    <div class="stat-number text-primary">' + formatCurrency(report.totalGross, currency) + '</div>';
        html += '    <div class="stat-label"><i class="fas fa-receipt me-1"></i>Total Revenue</div>';
        html += '  </div>';
        html += '</div>';
        html += '<div class="col-md-3">';
        html += '  <div class="stat-card">';
        html += '    <div class="stat-number text-warning">' + formatCurrency(report.totalFees, currency) + '</div>';
        html += '    <div class="stat-label"><i class="fas fa-percentage me-1"></i>Total Fees</div>';
        html += '  </div>';
        html += '</div>';
        html += '<div class="col-md-3">';
        html += '  <div class="stat-card">';
        html += '    <div class="stat-number text-success">' + formatCurrency(report.totalNet, currency) + '</div>';
        html += '    <div class="stat-label"><i class="fas fa-hand-holding-usd me-1"></i>Total Remitted / Owed</div>';
        html += '  </div>';
        html += '</div>';
        html += '<div class="col-md-3">';
        html += '  <div class="stat-card">';
        html += '    <div class="stat-number text-info">' + (report.averageFeeRate || 0).toFixed(2) + '%</div>';
        html += '    <div class="stat-label"><i class="fas fa-chart-pie me-1"></i>Avg Fee Rate</div>';
        html += '  </div>';
        html += '</div>';
        html += '</div>';

        // 📅 Monthly breakdown table
        var breakdown = report.monthlyBreakdown || [];
        if (breakdown.length > 0) {
            html += '<div class="card table-card">';
            html += '  <div class="table-header"><h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Monthly Breakdown</h5></div>';
            html += '  <div class="table-responsive">';
            html += '    <table class="table table-hover mb-0">';
            html += '      <thead class="table-light">';
            html += '        <tr><th>Month</th><th>Transactions</th><th>Gross Revenue</th><th>Fees</th><th>Net to Partner</th></tr>';
            html += '      </thead>';
            html += '      <tbody>';
            $.each(breakdown, function(i, m) {
                html += '<tr>';
                html += '  <td><strong>' + escapeHtml(m.month) + '</strong></td>';
                html += '  <td>' + (m.transactionCount || 0) + '</td>';
                html += '  <td>' + formatCurrency(m.grossAmount, currency) + '</td>';
                html += '  <td class="text-warning">' + formatCurrency(m.feeAmount, currency) + '</td>';
                html += '  <td class="text-success fw-bold">' + formatCurrency(m.netAmount, currency) + '</td>';
                html += '</tr>';
            });
            html += '      </tbody>';
            html += '    </table>';
            html += '  </div>';
            html += '</div>';
        } else {
            html += '<div class="text-center py-4 text-muted">';
            html += '  <i class="fas fa-chart-bar fa-3x mb-3 d-block opacity-50"></i>';
            html += '  <h5>No Transaction Data</h5>';
            html += '  <p>No fee transactions found for the selected period.</p>';
            html += '</div>';
        }

        // 📊 Render the report content
        $('#earningsReportContent').html(html);
    }

    // ═══════════════════════════════════════════════════════════════
    // 📋 PARTNER LIST — Populate partner dropdown filters
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Load the list of active partners into all filter dropdowns
     * Uses a simple query to tblPartners for the dropdown options
     */
    function loadPartnerList() {
        // 📡 AJAX GET request for partner list
        $.ajax({
            url: '../api/remittance-actions.php',
            method: 'GET',
            data: { action: 'get_partner_list' },
            dataType: 'json',
            success: function(result) {
                if (result.success && result.data) {
                    // 📋 Build option tags for all partner filter dropdowns
                    var options = '';
                    $.each(result.data, function(i, partner) {
                        options += '<option value="' + partner.partnerID + '">' +
                                   escapeHtml(partner.partnerName) + '</option>';
                    });
                    // 🔄 Append to all three filter dropdowns (keep the default "All Partners" option)
                    $('#pendingPartnerFilter').append(options);
                    $('#processedPartnerFilter').append(options);
                    $('#earningsPartnerFilter').append(options);
                }
            },
            error: function() {
                // ⚠️ Silently fail — partner filters are optional enhancements
                console.warn('Failed to load partner list for filters');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // 📊 STATS REFRESH
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📊 Refresh the remittance statistics from the API
     * Calls GET ../api/remittance-actions.php?action=get_remittance_stats
     *
     * @see ServiceFeeManager::getAdminStats() — Backend method
     */
    function loadRemittanceStats() {
        $.ajax({
            url: '../api/remittance-actions.php',
            method: 'GET',
            data: { action: 'get_remittance_stats' },
            dataType: 'json',
            success: function(result) {
                if (result.success && result.data) {
                    var s = result.data;
                    // 🔄 Update stat card values
                    $('#statPendingTotal').text(currencySymbol + parseFloat(s.pendingRemittanceTotal || 0).toFixed(2));
                    $('#statProcessedMonth').text(currencySymbol + parseFloat(s.completedRemittanceTotal || 0).toFixed(2));
                    $('#statTotalRemitted').text(currencySymbol + parseFloat(s.totalNetToPartners || 0).toFixed(2));
                    $('#statPartnersAwaiting').text(s.pendingRemittanceCount || 0);
                }
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // 🔄 REFRESH ALL — Reload all data sections
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔄 Refresh all data on the page — called by the Refresh button
     * and after any process/reject/batch action
     */
    function refreshAll() {
        loadPendingRemittances();
        loadProcessedRemittances(currentProcessedPage);
        loadRemittanceStats();
    }

    // ═══════════════════════════════════════════════════════════════
    // 🚀 INITIAL LOAD — Load all data when the page is ready
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🎯 Initialise all data on page load
     *
     * Loads partner list for filters, pending remittances, processed
     * remittances, and remittance statistics.
     *
     * @see https://api.jquery.com/ready/ — jQuery ready handler
     */
    $(document).ready(function() {
        // 📋 Load partner list for all filter dropdowns
        loadPartnerList();

        // 📋 Load initial tab data
        loadPendingRemittances();
        loadProcessedRemittances(1);

        // 📊 Load statistics
        loadRemittanceStats();
    });
    </script>
</body>
</html>
