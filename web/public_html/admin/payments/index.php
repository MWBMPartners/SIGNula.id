<?php
/**
 * 💳 Payment & Subscription Management Dashboard (Super Admin)
 *
 * Comprehensive dashboard for managing payments, subscriptions, tiers, and discount codes.
 * Features include revenue stats, payment history with search/filter, subscription management,
 * tier configuration, and discount code creation.
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons — FontAwesome 6.4 Icons
 * @see https://developer.paypal.com/docs/api/ — PayPal API Reference
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.3.0-beta
 */

require_once dirname(__DIR__, 3) . '/_config/config.php';
require_once dirname(__DIR__, 3) . '/_backend/Database.php';
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';
require_once dirname(__DIR__, 3) . '/_backend/AccessControl.php';

// 🔌 Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin check
$accessControl->requireSuperAdmin();

// 📊 Quick stats for initial page render (server-side)
$revenueStmt = $db->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN status = 'completed' AND createdAt >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN amount ELSE 0 END), 0) AS revenue30d,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS revenueTotal,
        COUNT(*) AS totalPayments,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completedCount,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pendingCount,
        SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) AS refundedCount,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failedCount
     FROM tblPayments"
);
$revenueStmt->execute();
$paymentStats = $revenueStmt->get_result()->fetch_assoc();

// 📦 Subscription stats
$subStmt = $db->prepare(
    "SELECT
        COUNT(*) AS totalSubscriptions,
        SUM(CASE WHEN subscriptionStatus = 'active' THEN 1 ELSE 0 END) AS activeCount,
        SUM(CASE WHEN subscriptionStatus = 'trial' THEN 1 ELSE 0 END) AS trialCount,
        SUM(CASE WHEN subscriptionStatus = 'cancelled' THEN 1 ELSE 0 END) AS cancelledCount,
        SUM(CASE WHEN subscriptionStatus = 'paused' THEN 1 ELSE 0 END) AS pausedCount
     FROM tblSubscriptions"
);
$subStmt->execute();
$subStats = $subStmt->get_result()->fetch_assoc();

// 🏷️ Tier count
$tierStmt = $db->prepare("SELECT COUNT(*) AS count FROM tblSubscriptionTiers WHERE isActive = 1");
$tierStmt->execute();
$tierCount = $tierStmt->get_result()->fetch_assoc()['count'];

// 🎟️ Active discount count
$discountStmt = $db->prepare("SELECT COUNT(*) AS count FROM tblDiscountCodes WHERE isActive = 1 AND (validUntil IS NULL OR validUntil > NOW())");
$discountStmt->execute();
$activeDiscounts = $discountStmt->get_result()->fetch_assoc()['count'];

// 💱 Default currency from settings
$currencyStmt = $db->prepare("SELECT settingValue FROM tblSettings WHERE settingKey = 'payment_default_currency' LIMIT 1");
$currencyStmt->execute();
$currencyResult = $currencyStmt->get_result()->fetch_assoc();
$currency = $currencyResult ? $currencyResult['settingValue'] : 'GBP';

// 💷 Currency symbols map
$currencySymbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€', 'AUD' => 'A$', 'CAD' => 'C$'];
$symbol = $currencySymbols[$currency] ?? $currency . ' ';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments & Subscriptions - SIGNula Admin</title>

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

        /* 🎨 Page header — green/teal theme for payments */
        .page-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
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

        /* 📋 Table card */
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

        /* 🔍 Filter bar */
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
        }

        /* 🏷️ Payment status badges */
        .badge-completed { background-color: #28a745; }
        .badge-pending { background-color: #ffc107; color: #333; }
        .badge-processing { background-color: #17a2b8; }
        .badge-failed { background-color: #dc3545; }
        .badge-refunded { background-color: #6c757d; }
        .badge-cancelled { background-color: #6c757d; }

        /* 🏷️ Subscription status badges */
        .badge-active { background-color: #28a745; }
        .badge-trial { background-color: #17a2b8; }
        .badge-paused { background-color: #ffc107; color: #333; }
        .badge-expired { background-color: #6c757d; }

        /* 🏷️ Tier badges */
        .badge-free { background-color: #6c757d; }
        .badge-basic { background-color: #17a2b8; }
        .badge-premium { background-color: #ffc107; color: #333; }
        .badge-enterprise { background-color: #667eea; }

        /* 📄 Pagination */
        .pagination-info {
            color: #6c757d;
            font-size: 0.9rem;
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

        /* 🎴 Tab navigation styling */
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

        /* 💰 Revenue highlight card */
        .revenue-card {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 12px rgba(17, 153, 142, 0.3);
        }

        /* 📱 Modal enhancements */
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

        /* 🎟️ Discount code display */
        .discount-code {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">

        <!-- 🎨 PAGE HEADER -->
        <div class="page-header">
            <a href="../index.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
            </a>
            <div class="row align-items-center">
                <div class="col-md-7">
                    <h1 class="mb-2">
                        <i class="fas fa-credit-card me-2"></i>Payments & Subscriptions
                    </h1>
                    <p class="mb-0 opacity-75">Manage payments, subscriptions, tiers, and discount codes</p>
                </div>
                <div class="col-md-5 text-end">
                    <div class="revenue-card d-inline-block text-start p-3">
                        <small class="opacity-75 d-block">30-Day Revenue</small>
                        <div class="fs-3 fw-bold"><?php echo $symbol . number_format((float)($paymentStats['revenue30d'] ?? 0), 2); ?></div>
                        <small class="opacity-75">Total: <?php echo $symbol . number_format((float)($paymentStats['revenueTotal'] ?? 0), 2); ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 💬 Alerts container -->
        <div id="alertContainer"></div>

        <!-- 📊 STAT CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-2">
                <div class="stat-card" onclick="switchTab('payments'); filterPayments('completed')">
                    <div class="stat-number text-success"><?php echo number_format((int)($paymentStats['completedCount'] ?? 0)); ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle me-1"></i>Completed</div>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="stat-card" onclick="switchTab('payments'); filterPayments('pending')">
                    <div class="stat-number text-warning"><?php echo number_format((int)($paymentStats['pendingCount'] ?? 0)); ?></div>
                    <div class="stat-label"><i class="fas fa-clock me-1"></i>Pending</div>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="stat-card" onclick="switchTab('payments'); filterPayments('refunded')">
                    <div class="stat-number text-secondary"><?php echo number_format((int)($paymentStats['refundedCount'] ?? 0)); ?></div>
                    <div class="stat-label"><i class="fas fa-undo me-1"></i>Refunded</div>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="stat-card" onclick="switchTab('subscriptions'); filterSubscriptions('active')">
                    <div class="stat-number text-primary"><?php echo number_format((int)($subStats['activeCount'] ?? 0)); ?></div>
                    <div class="stat-label"><i class="fas fa-sync me-1"></i>Active Subs</div>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="stat-card" onclick="switchTab('tiers')">
                    <div class="stat-number text-info"><?php echo number_format((int)$tierCount); ?></div>
                    <div class="stat-label"><i class="fas fa-layer-group me-1"></i>Active Tiers</div>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="stat-card" onclick="switchTab('discounts')">
                    <div class="stat-number text-danger"><?php echo number_format((int)$activeDiscounts); ?></div>
                    <div class="stat-label"><i class="fas fa-ticket me-1"></i>Active Discounts</div>
                </div>
            </div>
        </div>

        <!-- 🗂️ TAB NAVIGATION -->
        <ul class="nav nav-tabs mb-4" id="paymentTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-payments" data-bs-toggle="tab" data-bs-target="#pane-payments" type="button" role="tab">
                    <i class="fas fa-credit-card me-1"></i>Payments
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-subscriptions" data-bs-toggle="tab" data-bs-target="#pane-subscriptions" type="button" role="tab">
                    <i class="fas fa-sync me-1"></i>Subscriptions
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-tiers" data-bs-toggle="tab" data-bs-target="#pane-tiers" type="button" role="tab">
                    <i class="fas fa-layer-group me-1"></i>Tiers
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-discounts" data-bs-toggle="tab" data-bs-target="#pane-discounts" type="button" role="tab">
                    <i class="fas fa-ticket me-1"></i>Discounts
                </button>
            </li>
        </ul>

        <!-- 📋 TAB CONTENT -->
        <div class="tab-content" id="paymentTabContent">

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 💳 PAYMENTS TAB -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade show active" id="pane-payments" role="tabpanel">
                <!-- 🔍 Filter bar -->
                <div class="filter-bar">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><i class="fas fa-search me-1"></i>Search</label>
                            <input type="text" id="paymentSearch" class="form-control" placeholder="Email, name, invoice, transaction ID...">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold"><i class="fas fa-filter me-1"></i>Status</label>
                            <select id="paymentStatusFilter" class="form-select">
                                <option value="all">All Statuses</option>
                                <option value="completed">Completed</option>
                                <option value="pending">Pending</option>
                                <option value="processing">Processing</option>
                                <option value="failed">Failed</option>
                                <option value="refunded">Refunded</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-success w-100" onclick="loadPayments(1)">
                                <i class="fas fa-filter me-1"></i>Apply
                            </button>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-outline-secondary w-100" onclick="clearPaymentFilters()">
                                <i class="fas fa-times me-1"></i>Clear
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 📋 Payments table -->
                <div class="card table-card position-relative">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Payment History</h5>
                        <span id="paymentCount" class="badge bg-light text-dark"></span>
                    </div>
                    <div id="paymentsLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Invoice</th>
                                    <th>User</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="paymentsTableBody">
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>Loading payments...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- 📄 Pagination -->
                    <div class="d-flex justify-content-between align-items-center p-3 border-top">
                        <div class="pagination-info" id="paymentPaginationInfo">Showing 0 of 0</div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0" id="paymentPagination"></ul>
                        </nav>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 📦 SUBSCRIPTIONS TAB -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pane-subscriptions" role="tabpanel">
                <div class="filter-bar">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><i class="fas fa-filter me-1"></i>Status</label>
                            <select id="subStatusFilter" class="form-select">
                                <option value="all">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="trial">Trial</option>
                                <option value="paused">Paused</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="expired">Expired</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-success w-100" onclick="loadSubscriptions(1)">
                                <i class="fas fa-filter me-1"></i>Apply
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card table-card position-relative">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-sync me-2"></i>Subscriptions</h5>
                        <span id="subCount" class="badge bg-light text-dark"></span>
                    </div>
                    <div id="subsLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>User</th>
                                    <th>Tier</th>
                                    <th>Billing</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Next Billing</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="subsTableBody">
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>Loading subscriptions...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 🏷️ TIERS TAB -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pane-tiers" role="tabpanel">
                <div class="row g-4" id="tiersGrid">
                    <div class="col-12 text-center py-5 text-muted">
                        <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>Loading tiers...
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 🎟️ DISCOUNTS TAB -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pane-discounts" role="tabpanel">
                <!-- ➕ Create discount button -->
                <div class="d-flex justify-content-end mb-3">
                    <button class="btn btn-success" onclick="showCreateDiscountModal()">
                        <i class="fas fa-plus me-1"></i>Create Discount Code
                    </button>
                </div>

                <div class="card table-card position-relative">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-ticket me-2"></i>Discount Codes</h5>
                        <span id="discountCount" class="badge bg-light text-dark"></span>
                    </div>
                    <div id="discountsLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Type</th>
                                    <th>Value</th>
                                    <th>Uses</th>
                                    <th>Valid Until</th>
                                    <th>Status</th>
                                    <th>Created By</th>
                                </tr>
                            </thead>
                            <tbody id="discountsTableBody">
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>Loading discounts...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📱 MODALS -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->

    <!-- 💸 Refund Modal -->
    <div class="modal fade" id="refundModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-undo me-2"></i>Process Refund</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="refundPaymentID">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment</label>
                        <div id="refundPaymentInfo" class="text-muted"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Refund Amount (leave blank for full refund)</label>
                        <div class="input-group">
                            <span class="input-group-text"><?php echo htmlspecialchars($symbol); ?></span>
                            <input type="number" id="refundAmount" class="form-control" step="0.01" min="0" placeholder="Full amount">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason</label>
                        <textarea id="refundReason" class="form-control" rows="3" placeholder="Reason for refund..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="processRefund()">
                        <i class="fas fa-undo me-1"></i>Process Refund
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ✏️ Edit Tier Modal -->
    <div class="modal fade" id="editTierModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #11998e, #38ef7d); color: white;">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Tier</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editTierID">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tier Name</label>
                            <input type="text" id="editTierName" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Badge</label>
                            <div class="row g-2">
                                <div class="col-8">
                                    <input type="text" id="editTierBadge" class="form-control" placeholder="e.g. PRO">
                                </div>
                                <div class="col-4">
                                    <input type="color" id="editTierBadgeColor" class="form-control form-control-color" value="#667eea">
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea id="editTierDescription" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Monthly Price (<?php echo htmlspecialchars($currency); ?>)</label>
                            <div class="input-group">
                                <span class="input-group-text"><?php echo htmlspecialchars($symbol); ?></span>
                                <input type="number" id="editMonthlyPrice" class="form-control" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Yearly Price (<?php echo htmlspecialchars($currency); ?>)</label>
                            <div class="input-group">
                                <span class="input-group-text"><?php echo htmlspecialchars($symbol); ?></span>
                                <input type="number" id="editYearlyPrice" class="form-control" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Trial Days</label>
                            <input type="number" id="editTrialDays" class="form-control" min="0" value="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Display Order</label>
                            <input type="number" id="editDisplayOrder" class="form-control" min="0">
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-4">
                                <input type="checkbox" id="editTierActive" class="form-check-input">
                                <label class="form-check-label fw-semibold" for="editTierActive">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="saveTier()">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 🎟️ Create Discount Modal -->
    <div class="modal fade" id="createDiscountModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #11998e, #38ef7d); color: white;">
                    <h5 class="modal-title"><i class="fas fa-ticket me-2"></i>Create Discount Code</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Code</label>
                            <div class="input-group">
                                <input type="text" id="newDiscountCode" class="form-control text-uppercase" placeholder="e.g. SAVE20" maxlength="50">
                                <button class="btn btn-outline-secondary" type="button" onclick="generateRandomCode()">
                                    <i class="fas fa-random"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Description</label>
                            <input type="text" id="newDiscountDescription" class="form-control" placeholder="e.g. Summer Sale 20% off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Discount Type</label>
                            <select id="newDiscountType" class="form-select">
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount</option>
                                <option value="trial">Free Trial Days</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Value</label>
                            <input type="number" id="newDiscountValue" class="form-control" step="0.01" min="0" placeholder="e.g. 20">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Min Amount (<?php echo htmlspecialchars($currency); ?>)</label>
                            <input type="number" id="newDiscountMinAmount" class="form-control" step="0.01" min="0" placeholder="Optional">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Max Uses (total)</label>
                            <input type="number" id="newDiscountMaxUses" class="form-control" min="1" placeholder="Unlimited">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Max Uses Per User</label>
                            <input type="number" id="newDiscountMaxPerUser" class="form-control" min="1" value="1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Valid Until</label>
                            <input type="datetime-local" id="newDiscountValidUntil" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="createDiscount()">
                        <i class="fas fa-plus me-1"></i>Create Discount
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 📦 Update Subscription Status Modal -->
    <div class="modal fade" id="subStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #11998e, #38ef7d); color: white;">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Update Subscription Status</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="subStatusID">
                    <div id="subStatusInfo" class="mb-3 text-muted"></div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Status</label>
                        <select id="subNewStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="paused">Paused</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="expired">Expired</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="updateSubStatus()">
                        <i class="fas fa-save me-1"></i>Update Status
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 🔐 Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- 🔐 CSRF Token -->
    <script>const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';</script>
    <script>const currencySymbol = '<?php echo htmlspecialchars($symbol); ?>';</script>

    <script>
    /**
     * 💳 Payment & Subscription Management Dashboard
     *
     * Client-side logic for AJAX data loading, filtering, pagination,
     * and management actions (refund, tier edit, discount create, sub status).
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API — Fetch API
     */

    // 🔧 State tracking
    let currentPaymentPage = 1;
    let currentSubPage = 1;
    const perPage = 25;

    // ═══════════════════════════════════════════════════════════════
    // 🛠️ UTILITY FUNCTIONS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔒 Escape HTML entities to prevent XSS
     * @see https://owasp.org/www-community/attacks/xss/
     */
    function escapeHtml(text) {
        if (!text) { return ''; }
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * 💬 Show alert notification
     * @param {string} message - Alert message content
     * @param {string} type - Bootstrap alert type (success, danger, warning, info)
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
            if (alert.parentNode) { alert.remove(); }
        }, 5000);
    }

    /**
     * 📅 Format date string for display
     * @param {string} dateStr - ISO date string from API
     * @returns {string} Formatted date (e.g., "11 Feb 2026")
     */
    function formatDate(dateStr) {
        if (!dateStr) { return '—'; }
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    /**
     * 💰 Format currency amount
     * @param {number|string} amount - Amount to format
     * @returns {string} Formatted amount with currency symbol
     */
    function formatCurrency(amount) {
        const num = parseFloat(amount) || 0;
        return currencySymbol + num.toFixed(2);
    }

    /**
     * 🏷️ Get status badge HTML
     * @param {string} status - Status value
     * @returns {string} Badge HTML
     */
    function statusBadge(status) {
        const s = (status || '').toLowerCase();
        return `<span class="badge badge-${s}">${escapeHtml(status)}</span>`;
    }

    /**
     * 🗂️ Switch to a specific tab programmatically
     * @param {string} tabName - Tab identifier (payments, subscriptions, tiers, discounts)
     */
    function switchTab(tabName) {
        const tabEl = document.getElementById('tab-' + tabName);
        if (tabEl) {
            new bootstrap.Tab(tabEl).show();
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 💳 PAYMENTS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Load payments from API with pagination and filters
     * @param {number} page - Page number to load
     */
    async function loadPayments(page = 1) {
        currentPaymentPage = page;
        document.getElementById('paymentsLoading').style.display = 'flex';

        try {
            const params = new URLSearchParams({
                action: 'payments',
                page: page,
                status: document.getElementById('paymentStatusFilter').value,
                search: document.getElementById('paymentSearch').value.trim()
            });

            const response = await fetch(`../api/payment-actions.php?${params}`);
            const result = await response.json();

            if (result.success) {
                renderPayments(result.data);
                renderPaymentPagination(result.total, result.pages, result.currentPage);
                document.getElementById('paymentCount').textContent = result.total + ' payments';
            } else {
                showAlert(result.message || 'Failed to load payments', 'danger');
            }
        } catch (error) {
            showAlert('Error loading payments: ' + error.message, 'danger');
        } finally {
            document.getElementById('paymentsLoading').style.display = 'none';
        }
    }

    /**
     * 📋 Render payment rows into the table
     * @param {Array} payments - Array of payment objects from API
     */
    function renderPayments(payments) {
        const tbody = document.getElementById('paymentsTableBody');

        if (!payments || payments.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        <i class="fas fa-receipt fa-3x mb-3 d-block opacity-50"></i>
                        <h5>No Payments Found</h5>
                        <p>No payments match your current filters.</p>
                    </td>
                </tr>`;
            return;
        }

        tbody.innerHTML = payments.map(p => `
            <tr>
                <td><code class="text-primary">${escapeHtml(p.invoiceNumber || '—')}</code></td>
                <td>
                    <strong>${escapeHtml(p.displayName || 'Unknown')}</strong>
                    <br><small class="text-muted">${escapeHtml(p.email || '')}</small>
                </td>
                <td class="fw-bold">${formatCurrency(p.amount)}</td>
                <td><i class="fas fa-${getPaymentMethodIcon(p.paymentMethod)} me-1"></i>${escapeHtml(p.paymentMethod || '—')}</td>
                <td>${statusBadge(p.status)}</td>
                <td>${formatDate(p.createdAt)}</td>
                <td class="text-center">
                    ${p.status === 'completed' ? `
                        <button class="btn btn-sm btn-outline-danger" onclick="showRefundModal(${p.paymentID}, '${escapeHtml(p.invoiceNumber)}', ${p.amount})" title="Refund">
                            <i class="fas fa-undo"></i>
                        </button>
                    ` : ''}
                </td>
            </tr>
        `).join('');
    }

    /**
     * 🎨 Get FontAwesome icon for payment method
     * @param {string} method - Payment method name
     * @returns {string} FontAwesome icon class suffix
     */
    function getPaymentMethodIcon(method) {
        const icons = {
            'paypal': 'fa-brands fa-paypal',
            'apple_pay': 'fa-brands fa-apple-pay',
            'google_pay': 'fa-brands fa-google-pay',
            'credit_card': 'credit-card',
            'crypto': 'coins'
        };
        return icons[(method || '').toLowerCase()] || 'money-bill';
    }

    /**
     * 📄 Render payment pagination
     */
    function renderPaymentPagination(total, pages, current) {
        const start = Math.min(total, (current - 1) * perPage + 1);
        const end = Math.min(total, current * perPage);
        document.getElementById('paymentPaginationInfo').textContent = `Showing ${start}-${end} of ${total}`;

        const nav = document.getElementById('paymentPagination');
        nav.innerHTML = '';

        if (pages <= 1) { return; }

        // ⬅️ Previous
        nav.innerHTML += `<li class="page-item ${current === 1 ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="loadPayments(${current - 1}); return false;">&laquo;</a>
        </li>`;

        // 📊 Page numbers (show max 5)
        const startPage = Math.max(1, current - 2);
        const endPage = Math.min(pages, startPage + 4);
        for (let i = startPage; i <= endPage; i++) {
            nav.innerHTML += `<li class="page-item ${i === current ? 'active' : ''}">
                <a class="page-link" href="#" onclick="loadPayments(${i}); return false;">${i}</a>
            </li>`;
        }

        // ➡️ Next
        nav.innerHTML += `<li class="page-item ${current === pages ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="loadPayments(${current + 1}); return false;">&raquo;</a>
        </li>`;
    }

    /**
     * 🔍 Filter payments by status (called from stat cards)
     */
    function filterPayments(status) {
        document.getElementById('paymentStatusFilter').value = status;
        loadPayments(1);
    }

    /**
     * 🧹 Clear payment filters
     */
    function clearPaymentFilters() {
        document.getElementById('paymentSearch').value = '';
        document.getElementById('paymentStatusFilter').value = 'all';
        loadPayments(1);
    }

    // ═══════════════════════════════════════════════════════════════
    // 📦 SUBSCRIPTIONS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Load subscriptions from API
     */
    async function loadSubscriptions(page = 1) {
        currentSubPage = page;
        document.getElementById('subsLoading').style.display = 'flex';

        try {
            const params = new URLSearchParams({
                action: 'subscriptions',
                page: page,
                status: document.getElementById('subStatusFilter').value
            });

            const response = await fetch(`../api/payment-actions.php?${params}`);
            const result = await response.json();

            if (result.success) {
                renderSubscriptions(result.data);
                document.getElementById('subCount').textContent = (result.data || []).length + ' subscriptions';
            } else {
                showAlert(result.message || 'Failed to load subscriptions', 'danger');
            }
        } catch (error) {
            showAlert('Error loading subscriptions: ' + error.message, 'danger');
        } finally {
            document.getElementById('subsLoading').style.display = 'none';
        }
    }

    /**
     * 📋 Render subscription rows
     */
    function renderSubscriptions(subscriptions) {
        const tbody = document.getElementById('subsTableBody');

        if (!subscriptions || subscriptions.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        <i class="fas fa-sync fa-3x mb-3 d-block opacity-50"></i>
                        <h5>No Subscriptions Found</h5>
                    </td>
                </tr>`;
            return;
        }

        tbody.innerHTML = subscriptions.map(s => `
            <tr>
                <td>
                    <strong>${escapeHtml(s.displayName || 'Unknown')}</strong>
                    <br><small class="text-muted">${escapeHtml(s.email || '')}</small>
                </td>
                <td><span class="badge badge-${(s.tierSlug || 'free').toLowerCase()}">${escapeHtml(s.tierName)}</span></td>
                <td>${escapeHtml(s.billingCycle || '—')}</td>
                <td class="fw-bold">${formatCurrency(s.amount)}</td>
                <td>${statusBadge(s.subscriptionStatus)}</td>
                <td>${formatDate(s.nextBillingDate)}</td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-primary" onclick="showSubStatusModal(${s.subscriptionID}, '${escapeHtml(s.displayName)}', '${escapeHtml(s.subscriptionStatus)}')" title="Change Status">
                        <i class="fas fa-edit"></i>
                    </button>
                </td>
            </tr>
        `).join('');
    }

    /**
     * 🔍 Filter subscriptions by status (called from stat cards)
     */
    function filterSubscriptions(status) {
        document.getElementById('subStatusFilter').value = status;
        loadSubscriptions(1);
    }

    // ═══════════════════════════════════════════════════════════════
    // 🏷️ TIERS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Load subscription tiers
     */
    async function loadTiers() {
        const grid = document.getElementById('tiersGrid');
        grid.innerHTML = '<div class="col-12 text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>Loading tiers...</div>';

        try {
            const response = await fetch('../api/payment-actions.php?action=tiers');
            const result = await response.json();

            if (result.success) {
                renderTiers(result.data);
            } else {
                showAlert(result.message || 'Failed to load tiers', 'danger');
            }
        } catch (error) {
            showAlert('Error loading tiers: ' + error.message, 'danger');
        }
    }

    /**
     * 📋 Render tier cards in a grid
     */
    function renderTiers(tiers) {
        const grid = document.getElementById('tiersGrid');

        if (!tiers || tiers.length === 0) {
            grid.innerHTML = `
                <div class="col-12 text-center py-5 text-muted">
                    <i class="fas fa-layer-group fa-3x mb-3 d-block opacity-50"></i>
                    <h5>No Tiers Configured</h5>
                    <p>Run migration 010 to create default subscription tiers.</p>
                </div>`;
            return;
        }

        grid.innerHTML = tiers.map(t => {
            const features = t.features || [];
            const featuresHtml = features.map(f => `<li class="mb-1"><i class="fas fa-check text-success me-2"></i>${escapeHtml(f)}</li>`).join('');

            return `
                <div class="col-md-6 col-xl-3">
                    <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
                        <div class="card-header text-white text-center py-3" style="background: ${t.badgeColor || '#667eea'};">
                            <h5 class="mb-1">${escapeHtml(t.tierName)}</h5>
                            ${t.badge ? `<span class="badge bg-white text-dark">${escapeHtml(t.badge)}</span>` : ''}
                        </div>
                        <div class="card-body text-center">
                            <div class="mb-3">
                                <span class="fs-2 fw-bold">${formatCurrency(t.monthlyPrice)}</span>
                                <span class="text-muted">/mo</span>
                            </div>
                            ${parseFloat(t.yearlyPrice) > 0 ? `<div class="mb-3 text-muted"><small>${formatCurrency(t.yearlyPrice)}/year</small></div>` : ''}
                            ${t.trialDays > 0 ? `<div class="mb-3"><span class="badge bg-info">${t.trialDays}-day free trial</span></div>` : ''}
                            <ul class="list-unstyled text-start small">${featuresHtml}</ul>
                        </div>
                        <div class="card-footer bg-transparent text-center py-3">
                            <span class="badge ${t.isActive == 1 ? 'bg-success' : 'bg-secondary'} me-2">
                                ${t.isActive == 1 ? 'Active' : 'Inactive'}
                            </span>
                            <button class="btn btn-sm btn-outline-primary" onclick="showEditTierModal(${JSON.stringify(t).replace(/"/g, '&quot;')})">
                                <i class="fas fa-edit me-1"></i>Edit
                            </button>
                        </div>
                    </div>
                </div>`;
        }).join('');
    }

    // ═══════════════════════════════════════════════════════════════
    // 🎟️ DISCOUNTS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Load discount codes
     */
    async function loadDiscounts() {
        document.getElementById('discountsLoading').style.display = 'flex';

        try {
            const response = await fetch('../api/payment-actions.php?action=list_discounts');
            const result = await response.json();

            if (result.success) {
                renderDiscounts(result.data);
                document.getElementById('discountCount').textContent = (result.data || []).length + ' codes';
            } else {
                showAlert(result.message || 'Failed to load discounts', 'danger');
            }
        } catch (error) {
            showAlert('Error loading discounts: ' + error.message, 'danger');
        } finally {
            document.getElementById('discountsLoading').style.display = 'none';
        }
    }

    /**
     * 📋 Render discount code rows
     */
    function renderDiscounts(discounts) {
        const tbody = document.getElementById('discountsTableBody');

        if (!discounts || discounts.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        <i class="fas fa-ticket fa-3x mb-3 d-block opacity-50"></i>
                        <h5>No Discount Codes</h5>
                        <p>Click "Create Discount Code" to add one.</p>
                    </td>
                </tr>`;
            return;
        }

        tbody.innerHTML = discounts.map(d => {
            const isExpired = d.validUntil && new Date(d.validUntil) < new Date();
            const isActiveDisplay = d.isActive == 1 && !isExpired;
            const usesDisplay = (d.currentUses || 0) + (d.maxUses ? '/' + d.maxUses : '/∞');

            let valueDisplay = '';
            if (d.discountType === 'percentage') {
                valueDisplay = d.discountValue + '%';
            } else if (d.discountType === 'fixed') {
                valueDisplay = formatCurrency(d.discountValue);
            } else {
                valueDisplay = d.discountValue + ' days';
            }

            return `
                <tr class="${isActiveDisplay ? '' : 'table-secondary'}">
                    <td><span class="discount-code">${escapeHtml(d.code)}</span></td>
                    <td><span class="badge bg-${d.discountType === 'percentage' ? 'primary' : d.discountType === 'fixed' ? 'success' : 'info'}">${escapeHtml(d.discountType)}</span></td>
                    <td class="fw-bold">${valueDisplay}</td>
                    <td>${usesDisplay}</td>
                    <td>${d.validUntil ? formatDate(d.validUntil) : '<span class="text-muted">No expiry</span>'}</td>
                    <td>${isActiveDisplay ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</td>
                    <td>${escapeHtml(d.createdByName || '—')}</td>
                </tr>`;
        }).join('');
    }

    // ═══════════════════════════════════════════════════════════════
    // 📱 MODAL ACTIONS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 💸 Show refund modal
     */
    function showRefundModal(paymentID, invoiceNumber, amount) {
        document.getElementById('refundPaymentID').value = paymentID;
        document.getElementById('refundPaymentInfo').textContent = `Invoice: ${invoiceNumber} — Amount: ${formatCurrency(amount)}`;
        document.getElementById('refundAmount').value = '';
        document.getElementById('refundAmount').placeholder = amount;
        document.getElementById('refundReason').value = '';
        new bootstrap.Modal(document.getElementById('refundModal')).show();
    }

    /**
     * 💸 Process a refund
     */
    async function processRefund() {
        const paymentID = document.getElementById('refundPaymentID').value;
        const amount = document.getElementById('refundAmount').value;
        const reason = document.getElementById('refundReason').value.trim();

        try {
            const response = await fetch('../api/payment-actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'refund',
                    paymentID: parseInt(paymentID),
                    amount: amount ? parseFloat(amount) : null,
                    reason: reason,
                    csrf_token: csrfToken
                })
            });

            const result = await response.json();

            if (result.success) {
                showAlert('<i class="fas fa-check-circle me-1"></i> Refund processed successfully', 'success');
                bootstrap.Modal.getInstance(document.getElementById('refundModal')).hide();
                loadPayments(currentPaymentPage);
            } else {
                showAlert(result.message || 'Refund failed', 'danger');
            }
        } catch (error) {
            showAlert('Error processing refund: ' + error.message, 'danger');
        }
    }

    /**
     * ✏️ Show edit tier modal
     */
    function showEditTierModal(tier) {
        document.getElementById('editTierID').value = tier.tierID;
        document.getElementById('editTierName').value = tier.tierName || '';
        document.getElementById('editTierDescription').value = tier.tierDescription || '';
        document.getElementById('editMonthlyPrice').value = tier.monthlyPrice || 0;
        document.getElementById('editYearlyPrice').value = tier.yearlyPrice || 0;
        document.getElementById('editTrialDays').value = tier.trialDays || 0;
        document.getElementById('editDisplayOrder').value = tier.displayOrder || 0;
        document.getElementById('editTierBadge').value = tier.badge || '';
        document.getElementById('editTierBadgeColor').value = tier.badgeColor || '#667eea';
        document.getElementById('editTierActive').checked = tier.isActive == 1;
        new bootstrap.Modal(document.getElementById('editTierModal')).show();
    }

    /**
     * 💾 Save tier changes
     */
    async function saveTier() {
        const tierID = document.getElementById('editTierID').value;

        try {
            const response = await fetch('../api/payment-actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_tier',
                    tierID: parseInt(tierID),
                    tierName: document.getElementById('editTierName').value,
                    tierDescription: document.getElementById('editTierDescription').value,
                    monthlyPrice: parseFloat(document.getElementById('editMonthlyPrice').value),
                    yearlyPrice: parseFloat(document.getElementById('editYearlyPrice').value),
                    trialDays: parseInt(document.getElementById('editTrialDays').value),
                    displayOrder: parseInt(document.getElementById('editDisplayOrder').value),
                    badge: document.getElementById('editTierBadge').value,
                    badgeColor: document.getElementById('editTierBadgeColor').value,
                    isActive: document.getElementById('editTierActive').checked ? 1 : 0,
                    csrf_token: csrfToken
                })
            });

            const result = await response.json();

            if (result.success) {
                showAlert('<i class="fas fa-check-circle me-1"></i> Tier updated successfully', 'success');
                bootstrap.Modal.getInstance(document.getElementById('editTierModal')).hide();
                loadTiers();
            } else {
                showAlert(result.message || 'Failed to update tier', 'danger');
            }
        } catch (error) {
            showAlert('Error saving tier: ' + error.message, 'danger');
        }
    }

    /**
     * 🎟️ Show create discount modal
     */
    function showCreateDiscountModal() {
        document.getElementById('newDiscountCode').value = '';
        document.getElementById('newDiscountDescription').value = '';
        document.getElementById('newDiscountType').value = 'percentage';
        document.getElementById('newDiscountValue').value = '';
        document.getElementById('newDiscountMinAmount').value = '';
        document.getElementById('newDiscountMaxUses').value = '';
        document.getElementById('newDiscountMaxPerUser').value = '1';
        document.getElementById('newDiscountValidUntil').value = '';
        new bootstrap.Modal(document.getElementById('createDiscountModal')).show();
    }

    /**
     * 🎲 Generate random discount code
     */
    function generateRandomCode() {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        let code = '';
        for (let i = 0; i < 8; i++) {
            code += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.getElementById('newDiscountCode').value = code;
    }

    /**
     * 🎟️ Create discount code via API
     */
    async function createDiscount() {
        const code = document.getElementById('newDiscountCode').value.trim();
        const discountValue = document.getElementById('newDiscountValue').value;

        if (!code) {
            showAlert('Please enter a discount code', 'warning');
            return;
        }
        if (!discountValue || parseFloat(discountValue) <= 0) {
            showAlert('Please enter a valid discount value', 'warning');
            return;
        }

        try {
            const payload = {
                action: 'create_discount',
                code: code,
                description: document.getElementById('newDiscountDescription').value,
                discountType: document.getElementById('newDiscountType').value,
                discountValue: parseFloat(discountValue),
                csrf_token: csrfToken
            };

            const minAmount = document.getElementById('newDiscountMinAmount').value;
            if (minAmount) { payload.minAmount = parseFloat(minAmount); }

            const maxUses = document.getElementById('newDiscountMaxUses').value;
            if (maxUses) { payload.maxUses = parseInt(maxUses); }

            const maxPerUser = document.getElementById('newDiscountMaxPerUser').value;
            if (maxPerUser) { payload.maxUsesPerUser = parseInt(maxPerUser); }

            const validUntil = document.getElementById('newDiscountValidUntil').value;
            if (validUntil) { payload.validUntil = validUntil; }

            const response = await fetch('../api/payment-actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await response.json();

            if (result.success) {
                showAlert('<i class="fas fa-check-circle me-1"></i> Discount code created: <strong>' + escapeHtml(code) + '</strong>', 'success');
                bootstrap.Modal.getInstance(document.getElementById('createDiscountModal')).hide();
                loadDiscounts();
            } else {
                showAlert(result.message || 'Failed to create discount', 'danger');
            }
        } catch (error) {
            showAlert('Error creating discount: ' + error.message, 'danger');
        }
    }

    /**
     * 📦 Show subscription status modal
     */
    function showSubStatusModal(subscriptionID, displayName, currentStatus) {
        document.getElementById('subStatusID').value = subscriptionID;
        document.getElementById('subStatusInfo').textContent = `User: ${displayName} — Current: ${currentStatus}`;
        document.getElementById('subNewStatus').value = currentStatus;
        new bootstrap.Modal(document.getElementById('subStatusModal')).show();
    }

    /**
     * 📦 Update subscription status via API
     */
    async function updateSubStatus() {
        const subscriptionID = document.getElementById('subStatusID').value;
        const newStatus = document.getElementById('subNewStatus').value;

        try {
            const response = await fetch('../api/payment-actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_subscription_status',
                    subscriptionID: parseInt(subscriptionID),
                    status: newStatus,
                    csrf_token: csrfToken
                })
            });

            const result = await response.json();

            if (result.success) {
                showAlert('<i class="fas fa-check-circle me-1"></i> Subscription status updated', 'success');
                bootstrap.Modal.getInstance(document.getElementById('subStatusModal')).hide();
                loadSubscriptions(currentSubPage);
            } else {
                showAlert(result.message || 'Failed to update subscription', 'danger');
            }
        } catch (error) {
            showAlert('Error updating subscription: ' + error.message, 'danger');
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 🚀 INITIAL LOAD
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🎯 Initialise all data on page load
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Document/DOMContentLoaded_event
     */
    document.addEventListener('DOMContentLoaded', function() {
        // 📋 Load all tabs data
        loadPayments(1);
        loadSubscriptions(1);
        loadTiers();
        loadDiscounts();

        // ⌨️ Search on Enter key
        document.getElementById('paymentSearch').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') { loadPayments(1); }
        });
    });
    </script>
</body>
</html>
