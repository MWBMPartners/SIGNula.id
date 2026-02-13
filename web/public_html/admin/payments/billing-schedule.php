<?php
/**
 * ============================================================================
 * ⏰ SIGNula - Billing Scheduler Dashboard (Super Admin)
 * ============================================================================
 *
 * Purpose: Comprehensive dashboard for monitoring and managing the automated
 *          billing scheduler. Provides real-time health monitoring, task
 *          management, and manual intervention capabilities.
 *
 * Features:
 *   - Scheduler health status card (last cron run, overdue tasks, health indicator)
 *   - Statistics cards (pending, failed, processed today, upcoming renewals)
 *   - Three tabbed views: Pending Tasks, Failed Tasks, Task History
 *   - Manual batch processing trigger for emergency intervention
 *   - Manual task creation modal for ad-hoc scheduling
 *   - Auto-refresh toggle (30-second polling interval)
 *   - Full AJAX-driven data loading via billing-schedule-actions.php API
 *
 * Authentication: Super Admin only (isSuperAdmin = 1)
 * PHP Version: 8.3+
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons — FontAwesome 6.4 Icons
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API — Fetch API
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\Payments
 * @version    1.0.0
 * @since      2.4.0-beta
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

// 🔒 Authentication check — redirect unauthenticated users to login
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin check — only super admins can access the billing scheduler
$accessControl->requireSuperAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Scheduler - SIGNula Admin</title>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🎨 Bootstrap 5.3.2 (CDN with SRI)                                 -->
    <!-- @see https://getbootstrap.com/docs/5.3/ — Bootstrap Documentation  -->
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

    <style>
        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Base body styling — consistent with existing admin pages    */
        /* ═══════════════════════════════════════════════════════════════ */
        body { background-color: #f8f9fa; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Page header — green/teal gradient matching payments pages   */
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
        /* 📊 Stat cards — clickable stats with hover effects             */
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
        /* 🩺 Health status card — scheduler health monitoring            */
        /* ═══════════════════════════════════════════════════════════════ */
        .health-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .health-indicator {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
            vertical-align: middle;
        }
        .health-indicator.healthy {
            background-color: #28a745;
            box-shadow: 0 0 8px rgba(40, 167, 69, 0.5);
            animation: pulse-green 2s infinite;
        }
        .health-indicator.warning {
            background-color: #ffc107;
            box-shadow: 0 0 8px rgba(255, 193, 7, 0.5);
            animation: pulse-yellow 2s infinite;
        }
        .health-indicator.critical {
            background-color: #dc3545;
            box-shadow: 0 0 8px rgba(220, 53, 69, 0.5);
            animation: pulse-red 1.5s infinite;
        }
        /* 🟢 Green pulsing animation for healthy status */
        @keyframes pulse-green {
            0%, 100% { box-shadow: 0 0 4px rgba(40, 167, 69, 0.4); }
            50% { box-shadow: 0 0 12px rgba(40, 167, 69, 0.7); }
        }
        /* 🟡 Yellow pulsing animation for warning status */
        @keyframes pulse-yellow {
            0%, 100% { box-shadow: 0 0 4px rgba(255, 193, 7, 0.4); }
            50% { box-shadow: 0 0 12px rgba(255, 193, 7, 0.7); }
        }
        /* 🔴 Red pulsing animation for critical status */
        @keyframes pulse-red {
            0%, 100% { box-shadow: 0 0 4px rgba(220, 53, 69, 0.4); }
            50% { box-shadow: 0 0 12px rgba(220, 53, 69, 0.7); }
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📋 Table card — matches existing admin table card pattern      */
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
        /* 🏷️ Task status badges — colour-coded for quick identification  */
        /* ═══════════════════════════════════════════════════════════════ */
        .badge-pending { background-color: #ffc107; color: #333; }
        .badge-processing { background-color: #17a2b8; }
        .badge-completed { background-color: #28a745; }
        .badge-failed { background-color: #dc3545; }
        .badge-cancelled { background-color: #6c757d; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔄 Loading overlay — translucent cover during AJAX requests    */
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
        /* 🎴 Tab navigation styling — green underline for active tab     */
        /* @see https://getbootstrap.com/docs/5.3/components/navs-tabs/   */
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
        /* 📄 Pagination info text                                        */
        /* ═══════════════════════════════════════════════════════════════ */
        .pagination-info {
            color: #6c757d;
            font-size: 0.9rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔄 Auto-refresh toggle styling                                 */
        /* ═══════════════════════════════════════════════════════════════ */
        .auto-refresh-badge {
            font-size: 0.75rem;
            vertical-align: middle;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📱 Responsive adjustments for smaller screens                  */
        /* ═══════════════════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .health-card .row .col-md-3 {
                text-align: center;
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🎨 PAGE HEADER — Green gradient matching payments dashboard    -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="page-header">
            <!-- 🔙 Back navigation link to payments dashboard -->
            <a href="index.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Payments Dashboard
            </a>
            <div class="row align-items-center">
                <div class="col-md-7">
                    <h1 class="mb-2">
                        <i class="fas fa-clock me-2"></i>Billing Scheduler Dashboard
                    </h1>
                    <p class="mb-0 opacity-75">Monitor and manage automated billing tasks, retries, and scheduled operations</p>
                </div>
                <div class="col-md-5 text-end">
                    <!-- 🔄 Auto-refresh toggle and manual actions -->
                    <div class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                        <!-- 🔄 Auto-refresh toggle switch -->
                        <div class="form-check form-switch text-white">
                            <input class="form-check-input" type="checkbox" id="autoRefreshToggle" role="switch">
                            <label class="form-check-label" for="autoRefreshToggle">
                                <i class="fas fa-sync-alt me-1"></i>Auto-refresh
                                <span id="autoRefreshBadge" class="badge bg-light text-dark auto-refresh-badge ms-1" style="display:none;">30s</span>
                            </label>
                        </div>
                        <!-- 🔄 Manual refresh button -->
                        <button class="btn btn-light" onclick="refreshAll()">
                            <i class="fas fa-sync me-1"></i>Refresh
                        </button>
                        <!-- ⚡ Manual trigger button -->
                        <button class="btn btn-warning" onclick="confirmProcessBatch()">
                            <i class="fas fa-bolt me-1"></i>Manual Trigger
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 💬 Alert notification container — dynamically populated by showAlert() -->
        <div id="alertContainer"></div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🩺 HEALTH STATUS CARD — Scheduler health at a glance          -->
        <!-- Shows last cron run, tasks processed, overdue count, health    -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="health-card" id="healthCard">
            <div class="row align-items-center">
                <!-- 🟢 Health status indicator -->
                <div class="col-md-3 text-center">
                    <div class="mb-2">
                        <span class="health-indicator" id="healthIndicator"></span>
                        <strong id="healthStatusText" class="fs-5">Loading...</strong>
                    </div>
                    <small class="text-muted" id="healthSubtext">Checking scheduler health...</small>
                </div>
                <!-- ⏰ Last cron run time -->
                <div class="col-md-3">
                    <div class="text-muted small mb-1">
                        <i class="fas fa-history me-1"></i>Last Cron Run
                    </div>
                    <strong id="lastCronRun" class="d-block">—</strong>
                    <small class="text-muted" id="timeSinceRun">—</small>
                </div>
                <!-- ✅ Tasks processed today -->
                <div class="col-md-3">
                    <div class="text-muted small mb-1">
                        <i class="fas fa-check-double me-1"></i>Processed Today
                    </div>
                    <strong id="completedTodayCount" class="d-block fs-4">—</strong>
                </div>
                <!-- ⚠️ Overdue tasks count -->
                <div class="col-md-3">
                    <div class="text-muted small mb-1">
                        <i class="fas fa-exclamation-triangle me-1"></i>Overdue Tasks
                    </div>
                    <strong id="overdueCount" class="d-block fs-4">—</strong>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📊 STAT CARDS — Quick summary counters                         -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="row g-3 mb-4">
            <!-- 🕐 Pending tasks -->
            <div class="col-6 col-lg-3">
                <div class="stat-card" onclick="switchTab('pending')">
                    <div class="stat-number text-warning" id="statPending">—</div>
                    <div class="stat-label"><i class="fas fa-clock me-1"></i>Pending Tasks</div>
                </div>
            </div>
            <!-- ❌ Failed tasks -->
            <div class="col-6 col-lg-3">
                <div class="stat-card" onclick="switchTab('failed')">
                    <div class="stat-number text-danger" id="statFailed">—</div>
                    <div class="stat-label"><i class="fas fa-times-circle me-1"></i>Failed Tasks</div>
                </div>
            </div>
            <!-- ✅ Processed today -->
            <div class="col-6 col-lg-3">
                <div class="stat-card" onclick="switchTab('history')">
                    <div class="stat-number text-success" id="statProcessedToday">—</div>
                    <div class="stat-label"><i class="fas fa-check-circle me-1"></i>Processed Today</div>
                </div>
            </div>
            <!-- 🔄 Upcoming renewals -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-info" id="statUpcoming">—</div>
                    <div class="stat-label"><i class="fas fa-calendar-alt me-1"></i>Upcoming Renewals</div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📋 ACTION BAR — Create Task button                             -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="d-flex justify-content-end mb-3">
            <button class="btn btn-success" onclick="showCreateTaskModal()">
                <i class="fas fa-plus me-1"></i>Create Task
            </button>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🗂️ TAB NAVIGATION — Pending | Failed | History                -->
        <!-- @see https://getbootstrap.com/docs/5.3/components/navs-tabs/   -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <ul class="nav nav-tabs mb-4" id="schedulerTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-pending" data-bs-toggle="tab" data-bs-target="#pane-pending" type="button" role="tab">
                    <i class="fas fa-clock me-1"></i>Pending Tasks
                    <span class="badge bg-warning text-dark ms-1" id="tabBadgePending">0</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-failed" data-bs-toggle="tab" data-bs-target="#pane-failed" type="button" role="tab">
                    <i class="fas fa-times-circle me-1"></i>Failed Tasks
                    <span class="badge bg-danger ms-1" id="tabBadgeFailed">0</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-history" data-bs-toggle="tab" data-bs-target="#pane-history" type="button" role="tab">
                    <i class="fas fa-history me-1"></i>Task History
                </button>
            </li>
        </ul>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📋 TAB CONTENT                                                 -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="tab-content" id="schedulerTabContent">

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 🕐 PENDING TASKS TAB                                    -->
            <!-- Tasks from tblBillingSchedule WHERE status='pending'    -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade show active" id="pane-pending" role="tabpanel">
                <div class="card table-card position-relative">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Pending Tasks</h5>
                        <span id="pendingCount" class="badge bg-light text-dark"></span>
                    </div>
                    <!-- 🔄 Loading overlay for pending tasks table -->
                    <div id="pendingLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Task Type</th>
                                    <th>Target</th>
                                    <th>Scheduled Date</th>
                                    <th>Attempts</th>
                                    <th>Created</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="pendingTableBody">
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>Loading pending tasks...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- ❌ FAILED TASKS TAB                                     -->
            <!-- Tasks from tblBillingSchedule WHERE status='failed'     -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pane-failed" role="tabpanel">
                <div class="card table-card position-relative">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-times-circle me-2"></i>Failed Tasks</h5>
                        <span id="failedCount" class="badge bg-light text-dark"></span>
                    </div>
                    <!-- 🔄 Loading overlay for failed tasks table -->
                    <div id="failedLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Task Type</th>
                                    <th>Target</th>
                                    <th>Scheduled Date</th>
                                    <th>Last Attempt</th>
                                    <th>Error / Result</th>
                                    <th>Attempts</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="failedTableBody">
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>Loading failed tasks...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 📜 TASK HISTORY TAB                                     -->
            <!-- Recently completed/cancelled tasks with pagination     -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pane-history" role="tabpanel">
                <div class="card table-card position-relative">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Task History</h5>
                        <span id="historyCount" class="badge bg-light text-dark"></span>
                    </div>
                    <!-- 🔄 Loading overlay for history table -->
                    <div id="historyLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Task Type</th>
                                    <th>Target</th>
                                    <th>Scheduled Date</th>
                                    <th>Processed Date</th>
                                    <th>Status</th>
                                    <th>Result</th>
                                </tr>
                            </thead>
                            <tbody id="historyTableBody">
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>Loading task history...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- 📄 Pagination for history -->
                    <div class="d-flex justify-content-between align-items-center p-3 border-top">
                        <div class="pagination-info" id="historyPaginationInfo">Showing 0 of 0</div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0" id="historyPagination"></ul>
                        </nav>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📱 MODALS                                                          -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- ➕ CREATE TASK MODAL — Manual task scheduling                       -->
    <!-- Allows super admins to manually schedule billing tasks              -->
    <!-- @see BillingScheduler::createTask() — Backend method               -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="createTaskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #11998e, #38ef7d); color: white;">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Create Billing Task</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- 🔖 Task type selector -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><i class="fas fa-tag me-1"></i>Task Type</label>
                            <select id="newTaskType" class="form-select">
                                <option value="charge_subscription">Charge Subscription</option>
                                <option value="send_reminder">Payment Reminder</option>
                                <option value="suspend_account">Auto Suspension</option>
                                <option value="expire_trial">Trial Expiration</option>
                                <option value="process_remittance">Process Remittance</option>
                            </select>
                            <small class="form-text text-muted" id="taskTypeDescription">
                                Process a subscription charge attempt
                            </small>
                        </div>
                        <!-- 🎯 Target type selector -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><i class="fas fa-crosshairs me-1"></i>Target Type</label>
                            <select id="newTargetType" class="form-select">
                                <option value="subscription">Subscription</option>
                                <option value="partner">Partner</option>
                                <option value="user">User</option>
                                <option value="remittance">Remittance</option>
                            </select>
                        </div>
                        <!-- 🔢 Target ID input -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><i class="fas fa-hashtag me-1"></i>Target ID</label>
                            <input type="number" id="newTargetID" class="form-control" min="1" placeholder="e.g. 42">
                        </div>
                        <!-- 📅 Scheduled date/time picker -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><i class="fas fa-calendar-alt me-1"></i>Scheduled Date</label>
                            <input type="datetime-local" id="newScheduledDate" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="createTask()">
                        <i class="fas fa-plus me-1"></i>Create Task
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- ⚡ PROCESS BATCH CONFIRMATION MODAL                                -->
    <!-- Confirms manual batch processing trigger                           -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="processBatchModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-bolt me-2"></i>Manual Batch Processing</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>This will manually trigger processing of due billing tasks.</p>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-1"></i>
                        Tasks that are past their scheduled date and still in <strong>pending</strong> status will be processed.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="fas fa-layer-group me-1"></i>Batch Size</label>
                        <input type="number" id="batchSize" class="form-control" min="1" max="100" value="50" placeholder="50">
                        <small class="form-text text-muted">Maximum number of tasks to process in this batch (1-100)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" onclick="processBatch()">
                        <i class="fas fa-bolt me-1"></i>Process Now
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🔐 Hidden CSRF Token input — used by all AJAX POST requests        -->
    <!-- @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF     -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken()); ?>">

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🔐 Bootstrap 5.3.2 JS Bundle (CDN with SRI)                       -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📦 jQuery 3.7 (CDN) — Used for $.ajax() calls                      -->
    <!-- @see https://api.jquery.com/jQuery.ajax/ — jQuery AJAX             -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"
            crossorigin="anonymous"></script>

    <script>
    /**
     * ============================================================================
     * ⏰ Billing Scheduler Dashboard — Client-Side Logic
     * ============================================================================
     *
     * Handles AJAX data loading, auto-refresh polling, task management actions,
     * and health status monitoring. Communicates with the backend API at:
     *   ../api/billing-schedule-actions.php
     *
     * @see https://api.jquery.com/jQuery.ajax/ — jQuery AJAX
     * @see https://developer.mozilla.org/en-US/docs/Web/API/setInterval — Auto-refresh
     * ============================================================================
     */

    // ═══════════════════════════════════════════════════════════════
    // 🔧 STATE TRACKING
    // ═══════════════════════════════════════════════════════════════

    /** @type {number} Current page number for task history pagination */
    let historyPage = 1;

    /** @type {number} Records per page for history */
    const historyPerPage = 25;

    /** @type {number|null} Auto-refresh interval ID (null when disabled) */
    let autoRefreshInterval = null;

    /** @type {number} Auto-refresh polling interval in milliseconds (30 seconds) */
    const REFRESH_INTERVAL_MS = 30000;

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
        div.textContent = String(text);
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
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.getElementById('alertContainer').appendChild(alert);

        // ⏰ Auto-dismiss after 5 seconds
        setTimeout(function() {
            if (alert.parentNode) { alert.remove(); }
        }, 5000);
    }

    /**
     * 📅 Format an ISO date string for human-readable display.
     *
     * @param {string} dateStr — ISO 8601 date string from the API
     * @returns {string} Formatted date (e.g., "13 Feb 2026, 14:30")
     * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date/toLocaleDateString
     */
    function formatDate(dateStr) {
        if (!dateStr) { return '—'; }
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    /**
     * 📅 Format a date string as a short date (no time).
     *
     * @param {string} dateStr — ISO 8601 date string
     * @returns {string} Short formatted date (e.g., "13 Feb 2026")
     */
    function formatShortDate(dateStr) {
        if (!dateStr) { return '—'; }
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    /**
     * 🏷️ Get an HTML status badge for a given task status.
     *
     * @param {string} status — Task status value
     * @returns {string} HTML badge element
     */
    function statusBadge(status) {
        const s = (status || '').toLowerCase();
        return '<span class="badge badge-' + s + '">' + escapeHtml(status) + '</span>';
    }

    /**
     * 🔖 Get a human-readable description for a task type.
     *
     * @param {string} taskType — Task type enum value
     * @returns {string} Human-readable description
     */
    function taskTypeLabel(taskType) {
        const labels = {
            'charge_subscription': '<i class="fas fa-credit-card me-1 text-primary"></i>Charge Subscription',
            'send_reminder':       '<i class="fas fa-bell me-1 text-warning"></i>Payment Reminder',
            'suspend_account':     '<i class="fas fa-ban me-1 text-danger"></i>Auto Suspension',
            'expire_trial':        '<i class="fas fa-hourglass-end me-1 text-info"></i>Trial Expiration',
            'process_remittance':  '<i class="fas fa-money-check-alt me-1 text-success"></i>Process Remittance'
        };
        return labels[taskType] || '<i class="fas fa-question-circle me-1"></i>' + escapeHtml(taskType);
    }

    /**
     * 🎯 Get a human-readable target description combining type and ID.
     *
     * @param {string} targetType — Target entity type
     * @param {number|string} targetID — Target entity ID
     * @returns {string} Formatted target description
     */
    function targetLabel(targetType, targetID) {
        const icons = {
            'subscription': '<i class="fas fa-sync me-1 text-primary"></i>',
            'partner':      '<i class="fas fa-handshake me-1 text-success"></i>',
            'user':         '<i class="fas fa-user me-1 text-info"></i>',
            'remittance':   '<i class="fas fa-money-check-alt me-1 text-warning"></i>'
        };
        const icon = icons[targetType] || '<i class="fas fa-bullseye me-1"></i>';
        return icon + escapeHtml(targetType) + ' <code>#' + escapeHtml(targetID) + '</code>';
    }

    /**
     * 🗂️ Switch to a specific tab programmatically.
     *
     * @param {string} tabName — Tab identifier (pending, failed, history)
     * @see https://getbootstrap.com/docs/5.3/components/navs-tabs/#via-javascript
     */
    function switchTab(tabName) {
        var tabEl = document.getElementById('tab-' + tabName);
        if (tabEl) {
            new bootstrap.Tab(tabEl).show();
        }
    }

    /**
     * 🔑 Get the CSRF token from the hidden input field.
     *
     * @returns {string} CSRF token value
     */
    function getCsrfToken() {
        return $('#csrf_token').val();
    }

    /**
     * ⏱️ Format seconds into a human-readable time-ago string.
     *
     * @param {number} seconds — Number of seconds elapsed
     * @returns {string} Human-readable time string (e.g., "5 minutes ago")
     */
    function formatTimeAgo(seconds) {
        if (seconds < 0) { return 'In the future'; }
        if (seconds < 60) { return seconds + ' seconds ago'; }
        if (seconds < 3600) { return Math.floor(seconds / 60) + ' minutes ago'; }
        if (seconds < 86400) { return Math.floor(seconds / 3600) + ' hours ago'; }
        return Math.floor(seconds / 86400) + ' days ago';
    }

    // ═══════════════════════════════════════════════════════════════
    // 🩺 HEALTH STATUS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🩺 Load scheduler health status from the API.
     * Updates the health card with current status, last run time,
     * and colour-coded health indicator.
     *
     * Health thresholds:
     *   - Green (healthy): Cron ran within the last hour
     *   - Yellow (warning): Cron ran 1-2 hours ago
     *   - Red (critical): Cron has not run in 2+ hours or never
     *
     * @see ../api/billing-schedule-actions.php?action=get_scheduler_health
     */
    function loadSchedulerHealth() {
        $.ajax({
            url: '../api/billing-schedule-actions.php',
            method: 'GET',
            data: { action: 'get_scheduler_health' },
            dataType: 'json',
            /**
             * ✅ Success handler — update all health indicators
             * @param {Object} response — API response with health data
             */
            success: function(response) {
                if (response.success) {
                    var h = response.data;

                    // ⏰ Update last cron run display
                    $('#lastCronRun').text(h.lastRunTime === 'Never' ? 'Never' : formatDate(h.lastRunTime));
                    $('#timeSinceRun').text(h.lastRunTime === 'Never' ? 'Cron has never run' : formatTimeAgo(h.secondsSinceRun));

                    // ✅ Update processed today count
                    $('#completedTodayCount').text(h.completedToday);

                    // ⚠️ Update overdue count with colour highlighting
                    var $overdue = $('#overdueCount');
                    $overdue.text(h.overdueCount);
                    if (h.overdueCount > 0) {
                        $overdue.addClass('text-danger');
                    } else {
                        $overdue.removeClass('text-danger');
                    }

                    // 🟢🟡🔴 Update health indicator based on cron status
                    var $indicator = $('#healthIndicator');
                    var $statusText = $('#healthStatusText');
                    var $subtext = $('#healthSubtext');

                    if (h.cronHealthy) {
                        // 🟢 Healthy — cron ran within the last hour
                        $indicator.attr('class', 'health-indicator healthy');
                        $statusText.text('Healthy').css('color', '#28a745');
                        $subtext.text('Scheduler is operating normally');
                    } else if (h.secondsSinceRun > 0 && h.secondsSinceRun <= 7200) {
                        // 🟡 Warning — cron ran 1-2 hours ago
                        $indicator.attr('class', 'health-indicator warning');
                        $statusText.text('Warning').css('color', '#ffc107');
                        $subtext.text('Cron may be delayed — last run ' + formatTimeAgo(h.secondsSinceRun));
                    } else {
                        // 🔴 Critical — cron has not run in 2+ hours or never
                        $indicator.attr('class', 'health-indicator critical');
                        $statusText.text('Critical').css('color', '#dc3545');
                        $subtext.text(h.lastRunTime === 'Never'
                            ? 'Cron has never run — configure external cron service'
                            : 'Cron has not run in ' + formatTimeAgo(h.secondsSinceRun));
                    }
                }
            },
            /**
             * ❌ Error handler — show error state in health card
             */
            error: function() {
                $('#healthStatusText').text('Error').css('color', '#dc3545');
                $('#healthIndicator').attr('class', 'health-indicator critical');
                $('#healthSubtext').text('Failed to retrieve scheduler health');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // 📊 BILLING STATS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📊 Load billing statistics (counts by status, upcoming renewals).
     * Updates the stat cards and tab badges with current counts.
     *
     * @see ../api/billing-schedule-actions.php?action=get_billing_stats
     */
    function loadBillingStats() {
        $.ajax({
            url: '../api/billing-schedule-actions.php',
            method: 'GET',
            data: { action: 'get_billing_stats' },
            dataType: 'json',
            /**
             * ✅ Success handler — update stat cards and tab badges
             * @param {Object} response — API response with stats
             */
            success: function(response) {
                if (response.success) {
                    var s = response.data;

                    // 📊 Update stat cards
                    $('#statPending').text(s.pendingCount || 0);
                    $('#statFailed').text(s.failedCount || 0);
                    $('#statProcessedToday').text(s.completedToday || 0);
                    $('#statUpcoming').text(s.upcomingRenewals || 0);

                    // 🏷️ Update tab badges
                    $('#tabBadgePending').text(s.pendingCount || 0);
                    $('#tabBadgeFailed').text(s.failedCount || 0);
                }
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // 🕐 PENDING TASKS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Load pending tasks from the API.
     * Fetches tasks with status='pending' and renders them into the table.
     *
     * @see ../api/billing-schedule-actions.php?action=get_pending_tasks
     */
    function loadPendingTasks() {
        $('#pendingLoading').show();

        $.ajax({
            url: '../api/billing-schedule-actions.php',
            method: 'GET',
            data: { action: 'get_pending_tasks' },
            dataType: 'json',
            /**
             * ✅ Success handler — render pending tasks table
             * @param {Object} response — API response with pending tasks
             */
            success: function(response) {
                if (response.success) {
                    renderPendingTasks(response.data || []);
                    $('#pendingCount').text((response.data || []).length + ' tasks');
                } else {
                    showAlert(response.message || 'Failed to load pending tasks', 'danger');
                }
            },
            /**
             * ❌ Error handler
             */
            error: function(xhr, status, error) {
                showAlert('Error loading pending tasks: ' + escapeHtml(error), 'danger');
            },
            /**
             * 🔄 Always hide loading overlay
             */
            complete: function() {
                $('#pendingLoading').hide();
            }
        });
    }

    /**
     * 📋 Render pending task rows into the table.
     *
     * @param {Array} tasks — Array of pending task objects from API
     */
    function renderPendingTasks(tasks) {
        var $tbody = $('#pendingTableBody');

        // 📭 Handle empty state
        if (!tasks || tasks.length === 0) {
            $tbody.html(
                '<tr>' +
                    '<td colspan="7" class="text-center py-5 text-muted">' +
                        '<i class="fas fa-check-circle fa-3x mb-3 d-block opacity-50"></i>' +
                        '<h5>No Pending Tasks</h5>' +
                        '<p>All billing tasks have been processed.</p>' +
                    '</td>' +
                '</tr>'
            );
            return;
        }

        // 📋 Build table rows
        var html = '';
        tasks.forEach(function(task) {
            // 📅 Check if the task is overdue (scheduled date is in the past)
            var isOverdue = new Date(task.scheduledFor) < new Date();
            var rowClass = isOverdue ? 'table-warning' : '';

            html += '<tr class="' + rowClass + '">' +
                '<td><code>#' + escapeHtml(task.taskID) + '</code></td>' +
                '<td>' + taskTypeLabel(task.taskType) + '</td>' +
                '<td>' + targetLabel(task.targetType, task.targetID) + '</td>' +
                '<td>' + formatDate(task.scheduledFor) +
                    (isOverdue ? ' <span class="badge bg-danger">Overdue</span>' : '') +
                '</td>' +
                '<td>' + escapeHtml(task.attempts) + '/' + escapeHtml(task.maxAttempts) + '</td>' +
                '<td>' + formatShortDate(task.createdAt) + '</td>' +
                '<td class="text-center">' +
                    '<button class="btn btn-sm btn-outline-success me-1" onclick="processTaskNow(' + task.taskID + ')" title="Process Now">' +
                        '<i class="fas fa-play"></i>' +
                    '</button>' +
                    '<button class="btn btn-sm btn-outline-danger" onclick="cancelTask(' + task.taskID + ')" title="Cancel">' +
                        '<i class="fas fa-times"></i>' +
                    '</button>' +
                '</td>' +
            '</tr>';
        });

        $tbody.html(html);
    }

    // ═══════════════════════════════════════════════════════════════
    // ❌ FAILED TASKS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Load failed tasks from the API.
     * Fetches tasks with status='failed' and renders them into the table.
     *
     * @see ../api/billing-schedule-actions.php?action=get_failed_tasks
     */
    function loadFailedTasks() {
        $('#failedLoading').show();

        $.ajax({
            url: '../api/billing-schedule-actions.php',
            method: 'GET',
            data: { action: 'get_failed_tasks' },
            dataType: 'json',
            /**
             * ✅ Success handler — render failed tasks table
             * @param {Object} response — API response with failed tasks
             */
            success: function(response) {
                if (response.success) {
                    renderFailedTasks(response.data || []);
                    $('#failedCount').text((response.data || []).length + ' tasks');
                } else {
                    showAlert(response.message || 'Failed to load failed tasks', 'danger');
                }
            },
            /**
             * ❌ Error handler
             */
            error: function(xhr, status, error) {
                showAlert('Error loading failed tasks: ' + escapeHtml(error), 'danger');
            },
            /**
             * 🔄 Always hide loading overlay
             */
            complete: function() {
                $('#failedLoading').hide();
            }
        });
    }

    /**
     * 📋 Render failed task rows into the table.
     *
     * @param {Array} tasks — Array of failed task objects from API
     */
    function renderFailedTasks(tasks) {
        var $tbody = $('#failedTableBody');

        // 📭 Handle empty state
        if (!tasks || tasks.length === 0) {
            $tbody.html(
                '<tr>' +
                    '<td colspan="8" class="text-center py-5 text-muted">' +
                        '<i class="fas fa-check-circle fa-3x mb-3 d-block opacity-50 text-success"></i>' +
                        '<h5>No Failed Tasks</h5>' +
                        '<p>Great news! There are no failed billing tasks.</p>' +
                    '</td>' +
                '</tr>'
            );
            return;
        }

        // 📋 Build table rows
        var html = '';
        tasks.forEach(function(task) {
            // 📊 Determine if max retries have been exhausted
            var retriesExhausted = (parseInt(task.attempts) >= parseInt(task.maxAttempts));
            var rowClass = retriesExhausted ? 'table-danger' : '';

            // 🔍 Truncate long result text
            var resultText = task.result || '—';
            if (resultText.length > 80) {
                resultText = resultText.substring(0, 77) + '...';
            }

            html += '<tr class="' + rowClass + '">' +
                '<td><code>#' + escapeHtml(task.taskID) + '</code></td>' +
                '<td>' + taskTypeLabel(task.taskType) + '</td>' +
                '<td>' + targetLabel(task.targetType, task.targetID) + '</td>' +
                '<td>' + formatDate(task.scheduledFor) + '</td>' +
                '<td>' + formatDate(task.updatedAt) + '</td>' +
                '<td><small class="text-muted" title="' + escapeHtml(task.result || '') + '">' + escapeHtml(resultText) + '</small></td>' +
                '<td>' +
                    escapeHtml(task.attempts) + '/' + escapeHtml(task.maxAttempts) +
                    (retriesExhausted ? ' <span class="badge bg-dark">Exhausted</span>' : '') +
                '</td>' +
                '<td class="text-center">' +
                    '<button class="btn btn-sm btn-outline-warning me-1" onclick="retryTask(' + task.taskID + ')" title="Retry">' +
                        '<i class="fas fa-redo"></i>' +
                    '</button>' +
                    '<button class="btn btn-sm btn-outline-danger" onclick="cancelTask(' + task.taskID + ')" title="Cancel">' +
                        '<i class="fas fa-times"></i>' +
                    '</button>' +
                '</td>' +
            '</tr>';
        });

        $tbody.html(html);
    }

    // ═══════════════════════════════════════════════════════════════
    // 📜 TASK HISTORY
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Load task history from the API with pagination.
     * Fetches completed/cancelled tasks and renders them into the table.
     *
     * @param {number} page — Page number to load (default: 1)
     * @see ../api/billing-schedule-actions.php?action=get_task_history
     */
    function loadTaskHistory(page) {
        page = page || 1;
        historyPage = page;
        var offset = (page - 1) * historyPerPage;

        $('#historyLoading').show();

        $.ajax({
            url: '../api/billing-schedule-actions.php',
            method: 'GET',
            data: {
                action: 'get_task_history',
                limit: historyPerPage,
                offset: offset
            },
            dataType: 'json',
            /**
             * ✅ Success handler — render history table and pagination
             * @param {Object} response — API response with history data
             */
            success: function(response) {
                if (response.success) {
                    renderTaskHistory(response.data || []);
                    renderHistoryPagination(response.total || 0, response.pages || 1, page);
                    $('#historyCount').text((response.total || 0) + ' records');
                } else {
                    showAlert(response.message || 'Failed to load task history', 'danger');
                }
            },
            /**
             * ❌ Error handler
             */
            error: function(xhr, status, error) {
                showAlert('Error loading task history: ' + escapeHtml(error), 'danger');
            },
            /**
             * 🔄 Always hide loading overlay
             */
            complete: function() {
                $('#historyLoading').hide();
            }
        });
    }

    /**
     * 📋 Render task history rows into the table.
     *
     * @param {Array} tasks — Array of completed/cancelled task objects
     */
    function renderTaskHistory(tasks) {
        var $tbody = $('#historyTableBody');

        // 📭 Handle empty state
        if (!tasks || tasks.length === 0) {
            $tbody.html(
                '<tr>' +
                    '<td colspan="7" class="text-center py-5 text-muted">' +
                        '<i class="fas fa-history fa-3x mb-3 d-block opacity-50"></i>' +
                        '<h5>No Task History</h5>' +
                        '<p>No completed tasks found in the billing schedule.</p>' +
                    '</td>' +
                '</tr>'
            );
            return;
        }

        // 📋 Build table rows
        var html = '';
        tasks.forEach(function(task) {
            // 🔍 Truncate long result text
            var resultText = task.result || '—';
            if (resultText.length > 100) {
                resultText = resultText.substring(0, 97) + '...';
            }

            html += '<tr>' +
                '<td><code>#' + escapeHtml(task.taskID) + '</code></td>' +
                '<td>' + taskTypeLabel(task.taskType) + '</td>' +
                '<td>' + targetLabel(task.targetType, task.targetID) + '</td>' +
                '<td>' + formatDate(task.scheduledFor) + '</td>' +
                '<td>' + formatDate(task.updatedAt) + '</td>' +
                '<td>' + statusBadge(task.status) + '</td>' +
                '<td><small class="text-muted" title="' + escapeHtml(task.result || '') + '">' + escapeHtml(resultText) + '</small></td>' +
            '</tr>';
        });

        $tbody.html(html);
    }

    /**
     * 📄 Render pagination controls for task history.
     *
     * @param {number} total — Total number of records
     * @param {number} pages — Total number of pages
     * @param {number} current — Current page number
     */
    function renderHistoryPagination(total, pages, current) {
        var start = Math.min(total, (current - 1) * historyPerPage + 1);
        var end = Math.min(total, current * historyPerPage);
        $('#historyPaginationInfo').text('Showing ' + start + '-' + end + ' of ' + total);

        var $nav = $('#historyPagination');
        $nav.empty();

        if (pages <= 1) { return; }

        // ⬅️ Previous page button
        $nav.append(
            '<li class="page-item ' + (current === 1 ? 'disabled' : '') + '">' +
                '<a class="page-link" href="#" onclick="loadTaskHistory(' + (current - 1) + '); return false;">&laquo;</a>' +
            '</li>'
        );

        // 📊 Page number buttons (show max 5)
        var startPage = Math.max(1, current - 2);
        var endPage = Math.min(pages, startPage + 4);
        for (var i = startPage; i <= endPage; i++) {
            $nav.append(
                '<li class="page-item ' + (i === current ? 'active' : '') + '">' +
                    '<a class="page-link" href="#" onclick="loadTaskHistory(' + i + '); return false;">' + i + '</a>' +
                '</li>'
            );
        }

        // ➡️ Next page button
        $nav.append(
            '<li class="page-item ' + (current === pages ? 'disabled' : '') + '">' +
                '<a class="page-link" href="#" onclick="loadTaskHistory(' + (current + 1) + '); return false;">&raquo;</a>' +
            '</li>'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⚡ TASK ACTIONS — Process, Retry, Cancel, Create, Batch
    // ═══════════════════════════════════════════════════════════════

    /**
     * ▶️ Process a single pending task immediately by re-triggering a batch
     * of size 1 for that specific task. Since we cannot target a single task
     * via process_batch, we retry it with 0-minute delay to execute now.
     *
     * @param {number} taskID — The task ID to process
     */
    function processTaskNow(taskID) {
        if (!confirm('Process task #' + taskID + ' now?')) { return; }

        $.ajax({
            url: '../api/billing-schedule-actions.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'retry_task',
                taskID: taskID,
                retryMinutes: 0,
                csrf_token: getCsrfToken()
            }),
            dataType: 'json',
            /**
             * ✅ Success — reload pending tasks and stats
             */
            success: function(response) {
                if (response.success) {
                    showAlert('<i class="fas fa-check-circle me-1"></i> Task #' + taskID + ' queued for immediate processing', 'success');
                    refreshAll();
                } else {
                    showAlert(response.message || 'Failed to process task', 'danger');
                }
            },
            /**
             * ❌ Error handler
             */
            error: function(xhr, status, error) {
                showAlert('Error processing task: ' + escapeHtml(error), 'danger');
            }
        });
    }

    /**
     * 🔄 Retry a failed task. Resets the task to pending status
     * with a new scheduled time (default: 1 minute from now).
     *
     * @param {number} taskID — The failed task ID to retry
     * @see BillingScheduler::retryTask() — Backend method
     */
    function retryTask(taskID) {
        if (!confirm('Retry failed task #' + taskID + '?')) { return; }

        $.ajax({
            url: '../api/billing-schedule-actions.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'retry_task',
                taskID: taskID,
                csrf_token: getCsrfToken()
            }),
            dataType: 'json',
            /**
             * ✅ Success — reload failed tasks and stats
             */
            success: function(response) {
                if (response.success) {
                    showAlert('<i class="fas fa-check-circle me-1"></i> Task #' + taskID + ' scheduled for retry', 'success');
                    refreshAll();
                } else {
                    showAlert(response.message || 'Failed to retry task', 'danger');
                }
            },
            /**
             * ❌ Error handler
             */
            error: function(xhr, status, error) {
                showAlert('Error retrying task: ' + escapeHtml(error), 'danger');
            }
        });
    }

    /**
     * ❌ Cancel a pending or failed task.
     *
     * @param {number} taskID — The task ID to cancel
     * @see ../api/billing-schedule-actions.php — cancel_task action
     */
    function cancelTask(taskID) {
        if (!confirm('Cancel task #' + taskID + '? This action cannot be undone.')) { return; }

        $.ajax({
            url: '../api/billing-schedule-actions.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'cancel_task',
                taskID: taskID,
                csrf_token: getCsrfToken()
            }),
            dataType: 'json',
            /**
             * ✅ Success — reload all task lists
             */
            success: function(response) {
                if (response.success) {
                    showAlert('<i class="fas fa-check-circle me-1"></i> Task #' + taskID + ' cancelled', 'success');
                    refreshAll();
                } else {
                    showAlert(response.message || 'Failed to cancel task', 'danger');
                }
            },
            /**
             * ❌ Error handler
             */
            error: function(xhr, status, error) {
                showAlert('Error cancelling task: ' + escapeHtml(error), 'danger');
            }
        });
    }

    /**
     * ⚡ Show the batch processing confirmation modal.
     */
    function confirmProcessBatch() {
        $('#batchSize').val(50);
        new bootstrap.Modal(document.getElementById('processBatchModal')).show();
    }

    /**
     * ⚡ Trigger manual batch processing of due tasks.
     *
     * @see BillingScheduler::processDueTasks() — Backend method
     * @see ../api/billing-schedule-actions.php — process_batch action
     */
    function processBatch() {
        var batchSize = parseInt($('#batchSize').val()) || 50;

        // 🔒 Clamp to safe range
        batchSize = Math.max(1, Math.min(100, batchSize));

        // 🔄 Show loading state on the button
        var $btn = $('#processBatchModal .btn-warning');
        var originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Processing...');

        $.ajax({
            url: '../api/billing-schedule-actions.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'process_batch',
                batchSize: batchSize,
                csrf_token: getCsrfToken()
            }),
            dataType: 'json',
            /**
             * ✅ Success — show results and reload all data
             */
            success: function(response) {
                if (response.success) {
                    var d = response.data || {};
                    showAlert(
                        '<i class="fas fa-check-circle me-1"></i> Batch processing complete. ' +
                        '<strong>' + (d.processed || 0) + '</strong> processed, ' +
                        '<strong>' + (d.failed || 0) + '</strong> failed.',
                        'success'
                    );
                    // 🔄 Close the modal and reload all data
                    bootstrap.Modal.getInstance(document.getElementById('processBatchModal')).hide();
                    refreshAll();
                } else {
                    showAlert(response.message || 'Batch processing failed', 'danger');
                }
            },
            /**
             * ❌ Error handler
             */
            error: function(xhr, status, error) {
                showAlert('Error during batch processing: ' + escapeHtml(error), 'danger');
            },
            /**
             * 🔄 Always restore button state
             */
            complete: function() {
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    }

    /**
     * ➕ Show the Create Task modal with default values.
     */
    function showCreateTaskModal() {
        // 🔧 Reset form to defaults
        $('#newTaskType').val('charge_subscription');
        $('#newTargetType').val('subscription');
        $('#newTargetID').val('');

        // 📅 Default scheduled date to now
        var now = new Date();
        // 🔧 Format as YYYY-MM-DDTHH:mm for datetime-local input
        var yyyy = now.getFullYear();
        var mm = String(now.getMonth() + 1).padStart(2, '0');
        var dd = String(now.getDate()).padStart(2, '0');
        var hh = String(now.getHours()).padStart(2, '0');
        var mi = String(now.getMinutes()).padStart(2, '0');
        $('#newScheduledDate').val(yyyy + '-' + mm + '-' + dd + 'T' + hh + ':' + mi);

        // 📝 Update task type description
        updateTaskTypeDescription();

        new bootstrap.Modal(document.getElementById('createTaskModal')).show();
    }

    /**
     * 📝 Update the task type description text when the select changes.
     */
    function updateTaskTypeDescription() {
        var descriptions = {
            'charge_subscription': 'Process a subscription charge attempt',
            'send_reminder':       'Send a payment reminder email to the subscriber',
            'suspend_account':     'Automatically suspend account after grace period',
            'expire_trial':        'Mark a trial subscription as expired',
            'process_remittance':  'Process a partner remittance payment'
        };
        var selected = $('#newTaskType').val();
        $('#taskTypeDescription').text(descriptions[selected] || '');
    }

    /**
     * ➕ Create a new billing task via the API.
     *
     * @see BillingScheduler::createTask() — Backend method
     * @see ../api/billing-schedule-actions.php — create_task action
     */
    function createTask() {
        var taskType = $('#newTaskType').val();
        var targetType = $('#newTargetType').val();
        var targetID = parseInt($('#newTargetID').val());
        var scheduledDate = $('#newScheduledDate').val();

        // ✅ Client-side validation
        if (!targetID || targetID <= 0) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Please enter a valid Target ID', 'warning');
            return;
        }
        if (!scheduledDate) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Please select a scheduled date', 'warning');
            return;
        }

        $.ajax({
            url: '../api/billing-schedule-actions.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'create_task',
                taskType: taskType,
                targetType: targetType,
                targetID: targetID,
                scheduledDate: scheduledDate,
                csrf_token: getCsrfToken()
            }),
            dataType: 'json',
            /**
             * ✅ Success — close modal and reload pending tasks
             */
            success: function(response) {
                if (response.success) {
                    showAlert(
                        '<i class="fas fa-check-circle me-1"></i> Task created successfully (ID: <strong>#' + (response.taskID || '?') + '</strong>)',
                        'success'
                    );
                    bootstrap.Modal.getInstance(document.getElementById('createTaskModal')).hide();
                    refreshAll();
                } else {
                    showAlert(response.message || 'Failed to create task', 'danger');
                }
            },
            /**
             * ❌ Error handler
             */
            error: function(xhr, status, error) {
                showAlert('Error creating task: ' + escapeHtml(error), 'danger');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // 🔄 AUTO-REFRESH
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔄 Toggle the auto-refresh polling on/off.
     * When enabled, refreshes all data every 30 seconds.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/setInterval
     */
    function toggleAutoRefresh() {
        var enabled = $('#autoRefreshToggle').is(':checked');

        if (enabled) {
            // ✅ Start auto-refresh — poll every 30 seconds
            $('#autoRefreshBadge').show();
            autoRefreshInterval = setInterval(function() {
                refreshAll();
            }, REFRESH_INTERVAL_MS);
        } else {
            // ❌ Stop auto-refresh
            $('#autoRefreshBadge').hide();
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
        }
    }

    /**
     * 🔄 Refresh all dashboard data — health, stats, and all task tabs.
     */
    function refreshAll() {
        loadSchedulerHealth();
        loadBillingStats();
        loadPendingTasks();
        loadFailedTasks();
        loadTaskHistory(historyPage);
    }

    // ═══════════════════════════════════════════════════════════════
    // 🚀 INITIAL LOAD — Load all data when the page is ready
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🎯 Initialise the page by loading all data and binding event listeners.
     *
     * @see https://api.jquery.com/ready/ — jQuery document ready
     */
    $(document).ready(function() {

        // 📋 Load all dashboard data
        refreshAll();

        // 🔄 Bind auto-refresh toggle
        $('#autoRefreshToggle').on('change', toggleAutoRefresh);

        // 📝 Bind task type description updater
        $('#newTaskType').on('change', updateTaskTypeDescription);
    });
    </script>
</body>
</html>
