<?php
/**
 * ============================================================================
 * 💰 SIGNula - Service Fee Management (Super Admin)
 * ============================================================================
 *
 * Purpose: Super Admin dashboard for managing service fee schedules applied to
 *          partner API usage. Partners using their own API keys pay a different
 *          rate than those using SIGNula-provided keys. This page allows admins
 *          to view, create, and manage fee schedules with mandatory 30-day
 *          advance notice periods before changes take effect.
 *
 * Features:
 *   - 📊 Stats cards: Current own_keys rate, signula_keys rate, total revenue, pending remittance
 *   - 📋 DataTable listing all fee schedules from tblServiceFees
 *   - ➕ Create new fee schedule with 30-day minimum notice enforcement
 *   - 📧 Send change notification emails to affected partners
 *   - 🏷️ Status badge colouring: active=green, pending=yellow, expired=grey
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
 * @subpackage Admin\Payments\ServiceFees
 * @version    1.0.0
 * @since      2.3.0-beta
 * @link       https://SIGNula.id
 * ============================================================================
 */

// ============================================================================
// 🚀 BOOTSTRAP — Load core system files
// ============================================================================

// ⚙️ Main configuration (defines constants, loads settings, initializes session)
// @see web/_config/config.php
require_once dirname(__DIR__, 3) . '/_config/config.php';

// 🗄️ Database singleton (MySQLi prepared statements)
// @see web/_backend/Database.php
require_once dirname(__DIR__, 3) . '/_backend/Database.php';

// 🎫 Session manager (authentication state)
// @see web/_backend/SessionManager.php
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';

// 🔐 Access control (role-based permissions)
// @see web/_backend/AccessControl.php
require_once dirname(__DIR__, 3) . '/_backend/AccessControl.php';

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

// 🛡️ Super Admin check — only super admins can manage service fees
$accessControl->requireSuperAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Fee Management - SIGNula Admin</title>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- 🎨 Bootstrap 5.3.2 (CDN with SRI)                             -->
    <!-- @see https://getbootstrap.com/docs/5.3/ — Bootstrap Docs       -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- 🎯 FontAwesome 6.4.2 (CDN with SRI)                           -->
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
        /* 🎨 Base body styling — matches existing admin pages            */
        /* ═══════════════════════════════════════════════════════════════ */
        body { background-color: #f8f9fa; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Page header — green/teal gradient matching payments theme   */
        /* Identical to providers.php and payments/index.php headers      */
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
        /* Replicates the .stat-card pattern from payments/index.php      */
        /* ═══════════════════════════════════════════════════════════════ */
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
        /* 🏷️ Fee schedule status badges — colour-coded for quick scan   */
        /* active = green (currently in effect)                           */
        /* pending = yellow (upcoming, not yet effective)                 */
        /* expired = grey (no longer applicable)                          */
        /* ═══════════════════════════════════════════════════════════════ */
        .badge-active { background-color: #28a745; }
        .badge-pending { background-color: #ffc107; color: #333; }
        .badge-expired { background-color: #6c757d; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔄 Loading overlay — spinner shown during AJAX operations      */
        /* Identical to the loading-overlay in payments dashboard         */
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
        /* 📋 DataTables overrides — match admin theme styling            */
        /* Override default DataTables styles to blend with Bootstrap 5   */
        /* ═══════════════════════════════════════════════════════════════ */
        .dataTables_wrapper .dataTables_filter input {
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
        .dataTables_wrapper .dataTables_length select {
            border-radius: 6px;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📱 Responsive adjustments for smaller screens                  */
        /* ═══════════════════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .stat-card .stat-number {
                font-size: 1.5rem;
            }
            .stat-card {
                padding: 1rem;
            }
            .page-header {
                padding: 1.5rem;
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
                        <i class="fas fa-percentage me-2"></i>Service Fee Management
                    </h1>
                    <p class="mb-0 opacity-75">
                        Manage fee schedules for partner API usage. Partners using their own keys
                        vs. SIGNula-provided keys are charged at different rates.
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <!-- ➕ Create new fee schedule button -->
                    <button class="btn btn-light btn-lg me-2" onclick="showCreateFeeModal()">
                        <i class="fas fa-plus me-2"></i>New Fee Schedule
                    </button>
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
        <!-- Loaded via AJAX from service-fee-actions.php?action=get_fee_stats -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="row g-3 mb-4">
            <!-- 📊 Current own_keys rate -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-success" id="stat-own-keys-rate">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                    <div class="stat-label">
                        <i class="fas fa-key me-1"></i>Current Own Keys Rate
                    </div>
                </div>
            </div>

            <!-- 📊 Current signula_keys rate -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-primary" id="stat-signula-keys-rate">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                    <div class="stat-label">
                        <i class="fas fa-shield-halved me-1"></i>Current SIGNula Keys Rate
                    </div>
                </div>
            </div>

            <!-- 📊 Total fee revenue collected -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-info" id="stat-total-revenue">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                    <div class="stat-label">
                        <i class="fas fa-coins me-1"></i>Total Fee Revenue
                    </div>
                </div>
            </div>

            <!-- 📊 Pending remittance total -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-warning" id="stat-pending-remittance">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                    <div class="stat-label">
                        <i class="fas fa-clock me-1"></i>Pending Remittance
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📋 FEE SCHEDULES TABLE — DataTable with all fee schedules     -->
        <!-- Loaded via AJAX from service-fee-actions.php?action=get_fee_schedules -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="card table-card position-relative">
            <!-- 📋 Table header with gradient -->
            <div class="table-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Fee Schedules
                </h5>
                <span id="scheduleCount" class="badge bg-light text-dark"></span>
            </div>

            <!-- 🔄 Loading overlay — shown during AJAX fetch -->
            <div id="schedulesLoading" class="loading-overlay" style="display: none;">
                <div class="spinner-border text-success" role="status">
                    <span class="visually-hidden">Loading fee schedules...</span>
                </div>
            </div>

            <!-- 📋 Fee schedules responsive table -->
            <div class="table-responsive p-3">
                <table class="table table-hover mb-0" id="feeSchedulesTable" style="width: 100%;">
                    <thead class="table-light">
                        <tr>
                            <th><i class="fas fa-tag me-1"></i>Fee Type</th>
                            <th><i class="fas fa-percentage me-1"></i>Rate (%)</th>
                            <th><i class="fas fa-arrow-down me-1"></i>Min Fee</th>
                            <th><i class="fas fa-arrow-up me-1"></i>Max Fee</th>
                            <th><i class="fas fa-calendar-check me-1"></i>Effective Date</th>
                            <th><i class="fas fa-calendar-xmark me-1"></i>Expires At</th>
                            <th><i class="fas fa-circle-info me-1"></i>Status</th>
                            <th class="text-center"><i class="fas fa-cogs me-1"></i>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="feeSchedulesTableBody">
                        <!-- 📋 Initial loading placeholder — replaced by AJAX data -->
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>
                                Loading fee schedules...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- ➕ CREATE FEE SCHEDULE MODAL                                       -->
    <!-- @see https://getbootstrap.com/docs/5.3/components/modal/           -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="createFeeModal" tabindex="-1" aria-labelledby="createFeeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <!-- 🎨 Modal header with gradient matching admin theme -->
                <div class="modal-header modal-header-gradient">
                    <h5 class="modal-title" id="createFeeModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>Create Fee Schedule
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- 📝 Fee schedule creation form -->
                    <form id="createFeeForm" novalidate>

                        <!-- 🔐 CSRF Token — hidden input for form protection -->
                        <!-- @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Prevention -->
                        <input type="hidden" id="csrf_token" value="<?php echo SecurityUtils::generateCSRFToken(); ?>">

                        <div class="row g-3">
                            <!-- 🏷️ Fee Type — dropdown selection -->
                            <!-- partner_own_keys: Lower rate for partners using their own payment API keys -->
                            <!-- partner_signula_keys: Higher rate for partners using SIGNula-provided keys -->
                            <div class="col-md-6">
                                <label for="feeType" class="form-label fw-semibold">
                                    <i class="fas fa-tag me-1"></i>Fee Type <span class="text-danger">*</span>
                                </label>
                                <select id="feeType" class="form-select" required>
                                    <option value="">— Select Fee Type —</option>
                                    <option value="partner_own_keys">Partner Own Keys (lower rate)</option>
                                    <option value="partner_signula_keys">Partner SIGNula Keys (higher rate)</option>
                                </select>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    "Own Keys" = partner provides their own API keys. "SIGNula Keys" = partner uses SIGNula-managed keys.
                                </div>
                            </div>

                            <!-- 📊 Rate Percent — the percentage fee to charge -->
                            <div class="col-md-6">
                                <label for="ratePercent" class="form-label fw-semibold">
                                    <i class="fas fa-percentage me-1"></i>Rate Percent <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="number" id="ratePercent" class="form-control"
                                           step="0.01" min="0" max="100" placeholder="e.g. 2.50" required>
                                    <span class="input-group-text">%</span>
                                </div>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Percentage of transaction value charged as a service fee.
                                </div>
                            </div>

                            <!-- 💰 Minimum Fee — floor amount per transaction -->
                            <div class="col-md-6">
                                <label for="minFee" class="form-label fw-semibold">
                                    <i class="fas fa-arrow-down me-1"></i>Minimum Fee
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">&pound;</span>
                                    <input type="number" id="minFee" class="form-control"
                                           step="0.01" min="0" placeholder="e.g. 0.30">
                                </div>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Minimum fee per transaction (optional). Leave blank for no minimum.
                                </div>
                            </div>

                            <!-- 💰 Maximum Fee — ceiling amount per transaction -->
                            <div class="col-md-6">
                                <label for="maxFee" class="form-label fw-semibold">
                                    <i class="fas fa-arrow-up me-1"></i>Maximum Fee
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">&pound;</span>
                                    <input type="number" id="maxFee" class="form-control"
                                           step="0.01" min="0" placeholder="e.g. 50.00">
                                </div>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Maximum fee cap per transaction (optional). Leave blank for no cap.
                                </div>
                            </div>

                            <!-- 📅 Effective Date — when this fee schedule takes effect -->
                            <!-- Must be at least 30 days in the future for compliance -->
                            <div class="col-md-6">
                                <label for="effectiveDate" class="form-label fw-semibold">
                                    <i class="fas fa-calendar-check me-1"></i>Effective Date <span class="text-danger">*</span>
                                </label>
                                <input type="date" id="effectiveDate" class="form-control" required>
                                <div class="form-text">
                                    <i class="fas fa-exclamation-triangle me-1 text-warning"></i>
                                    <strong>Must be at least 30 days from today</strong> to give partners adequate notice.
                                </div>
                            </div>

                            <!-- 📝 Description — admin notes for this fee schedule -->
                            <div class="col-md-6">
                                <label for="feeDescription" class="form-label fw-semibold">
                                    <i class="fas fa-comment me-1"></i>Description
                                </label>
                                <input type="text" id="feeDescription" class="form-control"
                                       placeholder="e.g. Q2 2026 rate adjustment" maxlength="500">
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Brief description/reason for this fee schedule (optional, max 500 chars).
                                </div>
                            </div>
                        </div>

                        <!-- ⚠️ 30-day notice warning alert -->
                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>30-Day Notice Requirement:</strong> All fee schedule changes require a minimum
                            30-day advance notice period before they take effect. Affected partners will be notified
                            via email when you send the change notification.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <!-- 🚫 Cancel button — closes modal without saving -->
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <!-- ✅ Create button — submits the form via AJAX -->
                    <button type="button" class="btn btn-success" id="createFeeBtn" onclick="createFeeSchedule()">
                        <i class="fas fa-plus me-1"></i>Create Fee Schedule
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📧 SEND NOTIFICATION CONFIRMATION MODAL                            -->
    <!-- Confirms before sending fee change notification emails to partners  -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="notifyModal" tabindex="-1" aria-labelledby="notifyModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <!-- 🎨 Modal header with info-themed gradient -->
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="notifyModalLabel">
                        <i class="fas fa-envelope me-2"></i>Send Fee Change Notification
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- 🔐 Hidden field to store the fee schedule ID being notified -->
                    <input type="hidden" id="notifyFeeID">

                    <!-- 📝 Notification details — populated dynamically -->
                    <div id="notifyDetails" class="mb-3"></div>

                    <!-- ⚠️ Confirmation warning -->
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        This will send an email notification to <strong>all affected partners</strong> informing them
                        of the upcoming fee schedule change. This action cannot be undone.
                    </div>
                </div>
                <div class="modal-footer">
                    <!-- 🚫 Cancel button -->
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <!-- 📧 Confirm send button -->
                    <button type="button" class="btn btn-info text-white" id="confirmNotifyBtn" onclick="sendFeeNotification()">
                        <i class="fas fa-paper-plane me-1"></i>Send Notification
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
    <!-- @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Prevention -->
    <script>const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';</script>

    <script>
    /**
     * ============================================================================
     * 💰 Service Fee Management — Client-Side Logic
     * ============================================================================
     *
     * Handles AJAX loading of fee schedules and statistics, creation of new
     * fee schedules, sending notification emails, and DataTable initialisation.
     *
     * Communicates with the backend API at:
     *   ../api/service-fee-actions.php
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API — Fetch API Reference
     * @see https://datatables.net/reference/api/ — DataTables API Reference
     * ============================================================================
     */

    // ═══════════════════════════════════════════════════════════════
    // 🔧 STATE TRACKING
    // ═══════════════════════════════════════════════════════════════

    /** @type {Object|null} DataTables instance — initialised after first data load */
    let feeSchedulesDataTable = null;

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
        if (!text && text !== 0) { return ''; }
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
    function showAlert(message, type = 'info') {
        // 🏗️ Create the alert element dynamically
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        // 📌 Append to the alert container at the top of the page
        document.getElementById('alertContainer').appendChild(alert);

        // ⏰ Auto-dismiss after 5 seconds to prevent alert stacking
        setTimeout(() => {
            if (alert.parentNode) { alert.remove(); }
        }, 5000);
    }

    /**
     * 📅 Format an ISO date string for human-readable display.
     * Returns a UK-formatted date string.
     *
     * @param {string} dateStr — ISO 8601 date string from the API
     * @returns {string} Formatted date (e.g., "13 Feb 2026")
     * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date/toLocaleDateString
     */
    function formatDate(dateStr) {
        if (!dateStr) { return '—'; }
        const d = new Date(dateStr);

        // ⚠️ Check for invalid date object (e.g., from malformed input)
        if (isNaN(d.getTime())) { return '—'; }

        return d.toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
    }

    /**
     * 💰 Format a numeric value as currency (GBP assumed).
     *
     * @param {number|string|null} amount — Amount to format
     * @returns {string} Formatted currency string (e.g., "£12.50")
     */
    function formatCurrency(amount) {
        if (amount === null || amount === undefined || amount === '') { return '—'; }
        const num = parseFloat(amount);
        if (isNaN(num)) { return '—'; }
        return '£' + num.toFixed(2);
    }

    /**
     * 🏷️ Generate a status badge HTML string based on fee schedule status.
     * Uses colour-coded badges: active=green, pending=yellow, expired=grey.
     *
     * @param {string} status — Fee schedule status (active, pending, expired)
     * @returns {string} HTML badge element string
     */
    function statusBadge(status) {
        const s = (status || '').toLowerCase();
        // 🎨 Map status to badge CSS class
        const badgeClass = s === 'active' ? 'badge-active'
            : s === 'pending' ? 'badge-pending'
            : s === 'expired' ? 'badge-expired'
            : 'bg-secondary';
        return `<span class="badge ${badgeClass}">${escapeHtml(status || 'Unknown')}</span>`;
    }

    /**
     * 🏷️ Generate a human-readable label for fee type.
     * Converts internal enum values to user-friendly display names.
     *
     * @param {string} feeType — Fee type value (partner_own_keys, partner_signula_keys)
     * @returns {string} Human-readable label
     */
    function feeTypeLabel(feeType) {
        const labels = {
            'partner_own_keys': 'Partner Own Keys',
            'partner_signula_keys': 'Partner SIGNula Keys'
        };
        return labels[feeType] || escapeHtml(feeType || 'Unknown');
    }

    /**
     * 🏷️ Generate an icon for fee type.
     *
     * @param {string} feeType — Fee type value
     * @returns {string} FontAwesome icon HTML
     */
    function feeTypeIcon(feeType) {
        if (feeType === 'partner_own_keys') {
            return '<i class="fas fa-key text-success me-1" title="Partner Own Keys"></i>';
        } else if (feeType === 'partner_signula_keys') {
            return '<i class="fas fa-shield-halved text-primary me-1" title="SIGNula Keys"></i>';
        }
        return '<i class="fas fa-tag text-secondary me-1"></i>';
    }

    // ═══════════════════════════════════════════════════════════════
    // 📊 LOAD STATISTICS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📊 Load fee statistics from the backend API and populate stat cards.
     * Fetches current rates, total revenue, and pending remittance totals.
     *
     * @see ../api/service-fee-actions.php?action=get_fee_stats — Backend endpoint
     */
    async function loadFeeStats() {
        try {
            // 📡 Fetch statistics from the API
            const response = await fetch('../api/service-fee-actions.php?action=get_fee_stats');
            const result = await response.json();

            if (result.success) {
                const stats = result.data;

                // 📊 Update stat cards with received data
                // 🔑 Current own_keys rate — percentage shown with % suffix
                document.getElementById('stat-own-keys-rate').textContent =
                    (stats.current_own_keys_rate !== null && stats.current_own_keys_rate !== undefined)
                        ? parseFloat(stats.current_own_keys_rate).toFixed(2) + '%'
                        : 'N/A';

                // 🛡️ Current signula_keys rate — percentage shown with % suffix
                document.getElementById('stat-signula-keys-rate').textContent =
                    (stats.current_signula_keys_rate !== null && stats.current_signula_keys_rate !== undefined)
                        ? parseFloat(stats.current_signula_keys_rate).toFixed(2) + '%'
                        : 'N/A';

                // 💰 Total fee revenue — formatted as currency
                document.getElementById('stat-total-revenue').textContent =
                    formatCurrency(stats.total_fee_revenue || 0);

                // ⏳ Pending remittance total — formatted as currency
                document.getElementById('stat-pending-remittance').textContent =
                    formatCurrency(stats.pending_remittance || 0);

            } else {
                // ⚠️ API returned an error — show fallback values
                document.getElementById('stat-own-keys-rate').textContent = 'Error';
                document.getElementById('stat-signula-keys-rate').textContent = 'Error';
                document.getElementById('stat-total-revenue').textContent = 'Error';
                document.getElementById('stat-pending-remittance').textContent = 'Error';
                showAlert(result.message || 'Failed to load fee statistics', 'danger');
            }
        } catch (error) {
            // ⚠️ Network/parsing error — show user-friendly message
            document.getElementById('stat-own-keys-rate').textContent = 'Error';
            document.getElementById('stat-signula-keys-rate').textContent = 'Error';
            document.getElementById('stat-total-revenue').textContent = 'Error';
            document.getElementById('stat-pending-remittance').textContent = 'Error';
            showAlert('Error loading fee statistics: ' + escapeHtml(error.message), 'danger');
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 📋 LOAD FEE SCHEDULES
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Load all fee schedules from the backend API and render into the DataTable.
     * Fetches records from tblServiceFees ordered by effectiveDate DESC.
     *
     * @see ../api/service-fee-actions.php?action=get_fee_schedules — Backend endpoint
     * @see https://datatables.net/reference/api/clear() — DataTables clear method
     * @see https://datatables.net/reference/api/rows.add() — DataTables add rows
     */
    async function loadFeeSchedules() {
        // 🔄 Show loading overlay
        document.getElementById('schedulesLoading').style.display = 'flex';

        try {
            // 📡 Fetch fee schedules from the API
            const response = await fetch('../api/service-fee-actions.php?action=get_fee_schedules');
            const result = await response.json();

            if (result.success) {
                const schedules = result.data || [];

                // 📊 Update the schedule count badge
                document.getElementById('scheduleCount').textContent = schedules.length + ' schedule(s)';

                // 📋 Render the data into the DataTable
                renderFeeSchedules(schedules);

                // ✅ Show success notification on first load
                // (only if triggered by user action, not initial load)
            } else {
                showAlert(result.message || 'Failed to load fee schedules', 'danger');
            }
        } catch (error) {
            // ⚠️ Network/parsing error
            showAlert('Error loading fee schedules: ' + escapeHtml(error.message), 'danger');
        } finally {
            // 🔄 Hide loading overlay
            document.getElementById('schedulesLoading').style.display = 'none';
        }
    }

    /**
     * 📋 Render fee schedule data into the DataTable.
     * Destroys and re-initialises the DataTable if it already exists to ensure
     * clean state with fresh data.
     *
     * @param {Array} schedules — Array of fee schedule objects from the API
     * @see https://datatables.net/reference/option/ — DataTables Options
     */
    function renderFeeSchedules(schedules) {
        const tbody = document.getElementById('feeSchedulesTableBody');

        // 📭 Handle empty state — show friendly message if no schedules exist
        if (!schedules || schedules.length === 0) {
            // 🧹 Destroy existing DataTable instance if present
            if (feeSchedulesDataTable) {
                feeSchedulesDataTable.destroy();
                feeSchedulesDataTable = null;
            }

            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <i class="fas fa-percentage fa-3x mb-3 d-block opacity-50"></i>
                        <h5>No Fee Schedules</h5>
                        <p>Click "New Fee Schedule" to create your first fee schedule.</p>
                    </td>
                </tr>`;
            return;
        }

        // 📋 Build table row HTML for each fee schedule
        tbody.innerHTML = schedules.map(function(schedule) {
            // 🏷️ Generate the fee type display with icon
            const typeDisplay = feeTypeIcon(schedule.feeType) + feeTypeLabel(schedule.feeType);

            // 📊 Rate display with % suffix
            const rateDisplay = parseFloat(schedule.ratePercent).toFixed(2) + '%';

            // 💰 Min fee display — show value or dash for null/zero
            const minFeeDisplay = (schedule.minFee !== null && parseFloat(schedule.minFee) > 0)
                ? formatCurrency(schedule.minFee)
                : '<span class="text-muted">—</span>';

            // 💰 Max fee display — show value or dash for null/zero
            const maxFeeDisplay = (schedule.maxFee !== null && parseFloat(schedule.maxFee) > 0)
                ? formatCurrency(schedule.maxFee)
                : '<span class="text-muted">—</span>';

            // 📅 Date displays
            const effectiveDateDisplay = formatDate(schedule.effectiveDate);
            const expiresAtDisplay = schedule.expiresAt ? formatDate(schedule.expiresAt) : '<span class="text-muted">Never</span>';

            // 🏷️ Status badge
            const statusDisplay = statusBadge(schedule.status);

            // 🔧 Actions column — notify button + conditional edit
            // Only show "Send Notification" for pending schedules
            // Only show "Edit" for pending (not yet effective) schedules
            let actionsHtml = '';

            if (schedule.status === 'pending') {
                // 📧 Send notification button — for pending schedules only
                actionsHtml += `
                    <button class="btn btn-sm btn-outline-info me-1"
                            onclick="showNotifyModal(${parseInt(schedule.feeID)}, '${escapeHtml(feeTypeLabel(schedule.feeType))}', '${rateDisplay}', '${effectiveDateDisplay}')"
                            title="Send change notification to affected partners">
                        <i class="fas fa-envelope"></i>
                    </button>`;
            }

            // 📧 Notify button always available for active schedules too
            // (in case partners need a reminder)
            if (schedule.status === 'active') {
                actionsHtml += `
                    <button class="btn btn-sm btn-outline-info me-1"
                            onclick="showNotifyModal(${parseInt(schedule.feeID)}, '${escapeHtml(feeTypeLabel(schedule.feeType))}', '${rateDisplay}', '${effectiveDateDisplay}')"
                            title="Send reminder notification to partners">
                        <i class="fas fa-envelope"></i>
                    </button>`;
            }

            // 📋 If no actions available, show a dash
            if (!actionsHtml) {
                actionsHtml = '<span class="text-muted">—</span>';
            }

            return `
                <tr>
                    <td>${typeDisplay}</td>
                    <td><strong>${rateDisplay}</strong></td>
                    <td>${minFeeDisplay}</td>
                    <td>${maxFeeDisplay}</td>
                    <td>${effectiveDateDisplay}</td>
                    <td>${expiresAtDisplay}</td>
                    <td>${statusDisplay}</td>
                    <td class="text-center">${actionsHtml}</td>
                </tr>`;
        }).join('');

        // 📋 Initialise or re-initialise DataTables
        // @see https://datatables.net/reference/option/ — DataTables configuration options
        if (feeSchedulesDataTable) {
            // 🧹 Destroy existing instance before re-initialisation
            feeSchedulesDataTable.destroy();
        }

        // 🏗️ Initialise DataTable with Bootstrap 5 styling
        feeSchedulesDataTable = $('#feeSchedulesTable').DataTable({
            // 📄 Pagination settings
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],

            // 📊 Default sort — effective date descending (most recent first)
            order: [[4, 'desc']],

            // 🎨 Bootstrap 5 compatible DOM layout
            // @see https://datatables.net/reference/option/dom — DOM positioning
            dom: '<"row"<"col-md-6"l><"col-md-6"f>>rtip',

            // 🌐 Language customisations
            language: {
                search: '<i class="fas fa-search me-1"></i>',
                searchPlaceholder: 'Search fee schedules...',
                emptyTable: 'No fee schedules found',
                info: 'Showing _START_ to _END_ of _TOTAL_ schedules',
                infoEmpty: 'No schedules to display',
                lengthMenu: 'Show _MENU_ per page'
            },

            // ⚡ Column-specific settings
            columnDefs: [
                // 🔧 Actions column — disable sorting
                { orderable: false, targets: [7] },
                // 📊 Rate column — enable numeric sorting
                { type: 'num', targets: [1] }
            ]
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // ➕ CREATE FEE SCHEDULE
    // ═══════════════════════════════════════════════════════════════

    /**
     * ➕ Show the "Create Fee Schedule" modal.
     * Resets all form fields and sets the minimum effective date to 30 days from today.
     *
     * @see https://getbootstrap.com/docs/5.3/components/modal/ — Bootstrap Modals
     */
    function showCreateFeeModal() {
        // 🧹 Reset form fields to default values
        document.getElementById('feeType').value = '';
        document.getElementById('ratePercent').value = '';
        document.getElementById('minFee').value = '';
        document.getElementById('maxFee').value = '';
        document.getElementById('feeDescription').value = '';

        // 📅 Set minimum effective date to 30 days from today
        // This enforces the 30-day advance notice requirement on the client side
        const minDate = new Date();
        minDate.setDate(minDate.getDate() + 30);
        const minDateStr = minDate.toISOString().split('T')[0]; // Format: YYYY-MM-DD
        document.getElementById('effectiveDate').value = '';
        document.getElementById('effectiveDate').setAttribute('min', minDateStr);

        // 🔓 Ensure the create button is enabled (may have been disabled during a previous submission)
        document.getElementById('createFeeBtn').disabled = false;
        document.getElementById('createFeeBtn').innerHTML = '<i class="fas fa-plus me-1"></i>Create Fee Schedule';

        // 📱 Show the modal
        new bootstrap.Modal(document.getElementById('createFeeModal')).show();
    }

    /**
     * ➕ Create a new fee schedule by submitting form data to the API via AJAX.
     * Validates all required fields before submission. The API also validates
     * the 30-day minimum notice period server-side.
     *
     * @see ../api/service-fee-actions.php — Backend API (action: create_fee_schedule)
     */
    async function createFeeSchedule() {
        // ═══════════════════════════════════════════════════════════
        // ✅ CLIENT-SIDE VALIDATION
        // ═══════════════════════════════════════════════════════════

        // 🏷️ Validate fee type selection
        const feeType = document.getElementById('feeType').value;
        if (!feeType) {
            showAlert('<i class="fas fa-exclamation-circle me-1"></i> Please select a fee type.', 'warning');
            return;
        }

        // 📊 Validate rate percent
        const ratePercent = document.getElementById('ratePercent').value;
        if (!ratePercent || parseFloat(ratePercent) < 0 || parseFloat(ratePercent) > 100) {
            showAlert('<i class="fas fa-exclamation-circle me-1"></i> Please enter a valid rate percentage (0-100).', 'warning');
            return;
        }

        // 📅 Validate effective date
        const effectiveDate = document.getElementById('effectiveDate').value;
        if (!effectiveDate) {
            showAlert('<i class="fas fa-exclamation-circle me-1"></i> Please select an effective date.', 'warning');
            return;
        }

        // 📅 Validate 30-day minimum notice on client side
        const minDate = new Date();
        minDate.setDate(minDate.getDate() + 30);
        minDate.setHours(0, 0, 0, 0);
        const selectedDate = new Date(effectiveDate + 'T00:00:00');
        if (selectedDate < minDate) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Effective date must be at least 30 days from today.', 'danger');
            return;
        }

        // 💰 Validate minFee/maxFee logic (if both provided, min must be <= max)
        const minFee = document.getElementById('minFee').value;
        const maxFee = document.getElementById('maxFee').value;
        if (minFee && maxFee && parseFloat(minFee) > parseFloat(maxFee)) {
            showAlert('<i class="fas fa-exclamation-circle me-1"></i> Minimum fee cannot be greater than maximum fee.', 'warning');
            return;
        }

        // ═══════════════════════════════════════════════════════════
        // 📡 SUBMIT TO API
        // ═══════════════════════════════════════════════════════════

        // 🔄 Show loading state on the button
        const btn = document.getElementById('createFeeBtn');
        const originalBtnHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Creating...';

        try {
            // 📤 Build the request payload
            const payload = {
                action: 'create_fee_schedule',
                feeType: feeType,
                ratePercent: parseFloat(ratePercent),
                effectiveDate: effectiveDate,
                csrf_token: document.getElementById('csrf_token').value
            };

            // 📝 Add optional fields if provided
            if (minFee) { payload.minFee = parseFloat(minFee); }
            if (maxFee) { payload.maxFee = parseFloat(maxFee); }

            const description = document.getElementById('feeDescription').value.trim();
            if (description) { payload.description = description; }

            // 📡 Send POST request to the API
            const response = await fetch('../api/service-fee-actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await response.json();

            if (result.success) {
                // ✅ Success — close modal and refresh data
                showAlert(
                    '<i class="fas fa-check-circle me-1"></i> Fee schedule created successfully. ' +
                    'It will take effect on <strong>' + escapeHtml(effectiveDate) + '</strong>.',
                    'success'
                );

                // 📱 Close the modal
                bootstrap.Modal.getInstance(document.getElementById('createFeeModal')).hide();

                // 🔄 Refresh all data to reflect the new schedule
                refreshAll();
            } else {
                // ❌ API returned an error
                showAlert(
                    '<i class="fas fa-times-circle me-1"></i> ' + escapeHtml(result.message || 'Failed to create fee schedule.'),
                    'danger'
                );
            }
        } catch (error) {
            // ⚠️ Network/parsing error
            showAlert(
                '<i class="fas fa-exclamation-triangle me-1"></i> Error creating fee schedule: ' + escapeHtml(error.message),
                'danger'
            );
        } finally {
            // 🔄 Restore the button to its original state
            btn.disabled = false;
            btn.innerHTML = originalBtnHtml;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 📧 SEND FEE CHANGE NOTIFICATION
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📧 Show the notification confirmation modal.
     * Populates the modal with details about the fee schedule being notified.
     *
     * @param {number} feeID — The ID of the fee schedule to notify about
     * @param {string} feeTypeLabel — Human-readable fee type label
     * @param {string} rateDisplay — Formatted rate percentage string
     * @param {string} effectiveDateDisplay — Formatted effective date string
     */
    function showNotifyModal(feeID, feeTypeLabel, rateDisplay, effectiveDateDisplay) {
        // 📝 Store the fee ID in the hidden field
        document.getElementById('notifyFeeID').value = feeID;

        // 📝 Populate notification details
        document.getElementById('notifyDetails').innerHTML = `
            <div class="card bg-light border-0">
                <div class="card-body">
                    <div class="row">
                        <div class="col-sm-4 fw-semibold"><i class="fas fa-tag me-1"></i>Fee Type:</div>
                        <div class="col-sm-8">${escapeHtml(feeTypeLabel)}</div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-sm-4 fw-semibold"><i class="fas fa-percentage me-1"></i>Rate:</div>
                        <div class="col-sm-8">${escapeHtml(rateDisplay)}</div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-sm-4 fw-semibold"><i class="fas fa-calendar-check me-1"></i>Effective:</div>
                        <div class="col-sm-8">${escapeHtml(effectiveDateDisplay)}</div>
                    </div>
                </div>
            </div>`;

        // 🔓 Ensure the confirm button is enabled
        document.getElementById('confirmNotifyBtn').disabled = false;
        document.getElementById('confirmNotifyBtn').innerHTML = '<i class="fas fa-paper-plane me-1"></i>Send Notification';

        // 📱 Show the modal
        new bootstrap.Modal(document.getElementById('notifyModal')).show();
    }

    /**
     * 📧 Send fee change notification emails to affected partners via AJAX.
     * Posts the feeID to the API which handles email dispatch.
     *
     * @see ../api/service-fee-actions.php — Backend API (action: notify_fee_change)
     */
    async function sendFeeNotification() {
        // 📝 Get the fee schedule ID from the hidden field
        const feeID = document.getElementById('notifyFeeID').value;

        if (!feeID) {
            showAlert('<i class="fas fa-exclamation-circle me-1"></i> No fee schedule selected.', 'warning');
            return;
        }

        // 🔄 Show loading state on the confirm button
        const btn = document.getElementById('confirmNotifyBtn');
        const originalBtnHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Sending...';

        try {
            // 📡 Send POST request to the API
            const response = await fetch('../api/service-fee-actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'notify_fee_change',
                    feeID: parseInt(feeID),
                    csrf_token: document.getElementById('csrf_token').value
                })
            });

            const result = await response.json();

            if (result.success) {
                // ✅ Success — close modal and show notification
                showAlert(
                    '<i class="fas fa-check-circle me-1"></i> ' +
                    escapeHtml(result.message || 'Notification emails sent successfully.'),
                    'success'
                );

                // 📱 Close the modal
                bootstrap.Modal.getInstance(document.getElementById('notifyModal')).hide();
            } else {
                // ❌ API returned an error
                showAlert(
                    '<i class="fas fa-times-circle me-1"></i> ' + escapeHtml(result.message || 'Failed to send notifications.'),
                    'danger'
                );
            }
        } catch (error) {
            // ⚠️ Network/parsing error
            showAlert(
                '<i class="fas fa-exclamation-triangle me-1"></i> Error sending notifications: ' + escapeHtml(error.message),
                'danger'
            );
        } finally {
            // 🔄 Restore the button to its original state
            btn.disabled = false;
            btn.innerHTML = originalBtnHtml;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 🔄 REFRESH ALL DATA
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔄 Refresh all page data — statistics and fee schedules.
     * Called on initial page load and when the user clicks the Refresh button.
     */
    function refreshAll() {
        loadFeeStats();
        loadFeeSchedules();
    }

    // ═══════════════════════════════════════════════════════════════
    // 🚀 INITIAL LOAD — Load all data when the page is ready
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🎯 Initialise the page by loading all fee statistics and schedules.
     * Uses DOMContentLoaded to ensure the DOM is fully parsed before executing.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Document/DOMContentLoaded_event
     */
    document.addEventListener('DOMContentLoaded', function() {
        // 📊 Load fee statistics into stat cards
        loadFeeStats();

        // 📋 Load fee schedules into the DataTable
        loadFeeSchedules();
    });
    </script>
</body>
</html>
