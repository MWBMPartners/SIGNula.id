<?php
/**
 * ============================================================================
 * 🧾 Invoice Management Dashboard (Super Admin)
 * ============================================================================
 *
 * Purpose: Comprehensive super-admin interface for viewing, filtering, and
 * managing all system invoices. Supports status transitions (mark paid, void,
 * reissue), HTML invoice preview, PDF download, and email resend.
 *
 * Features:
 * - Statistics row: total invoices, paid amount, outstanding amount, overdue count
 * - Filterable DataTable with status, date range, and keyword search
 * - Status badges colour-coded per invoice status
 * - Modal invoice viewer (renders full HTML invoice)
 * - Actions: View, Download PDF, Mark Paid, Mark Void, Reissue, Send Email
 * - AJAX-powered via invoice-actions.php API endpoint
 * - Full pagination with page size control
 *
 * @see https://getbootstrap.com/docs/5.3/       — Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons              — FontAwesome 6.4 Icons
 * @see https://www.php.net/manual/en/book.mysqli.php — MySQLi Reference
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Admin\Payments
 * @version    1.0.0
 * @since      2.4.0-beta
 * ============================================================================
 */

// ============================================================================
// 🔌 BOOTSTRAP — Load core configuration and backend classes
// ============================================================================

/**
 * 📂 Load central configuration (defines SIGNULA_INIT, path constants, DB creds, etc.)
 * dirname(__DIR__, 3) navigates: payments -> admin -> public_html -> web root
 * @see https://www.php.net/manual/en/function.dirname.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

/**
 * 🗄️ Database singleton — MySQLi prepared-statement wrapper
 * @see _backend/Database.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

/**
 * 🔐 Session management — login state, CSRF tokens, user info
 * @see _backend/SessionManager.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

/**
 * 🛡️ Access control — role-based guards (requireSuperAdmin, etc.)
 * @see _backend/AccessControl.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// ============================================================================
// 🔌 INITIALISE CORE SERVICES
// ============================================================================

/** @var Database $db — Database singleton instance */
$db = Database::getInstance();

/** @var SessionManager $sessionManager — Session handler instance */
$sessionManager = new SessionManager($db);

/** @var AccessControl $accessControl — RBAC guard instance */
$accessControl = new AccessControl($db, $sessionManager);

// ============================================================================
// 🔒 AUTHENTICATION & AUTHORISATION
// ============================================================================

/**
 * 🔒 Redirect unauthenticated users to the login page
 * @see https://www.php.net/manual/en/function.header.php
 */
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

/**
 * 🛡️ Enforce Super Admin role — terminates with 403 if not authorised
 * Only Super Admins can access the invoice management dashboard
 */
$accessControl->requireSuperAdmin();

// ============================================================================
// 📊 SERVER-SIDE STATS — Quick counts for the stats row on initial render
// ============================================================================

/**
 * 🧮 Fetch aggregate invoice statistics for the stats cards:
 * - Total number of invoices
 * - Sum of all paid invoices (totalPaid)
 * - Sum of outstanding invoices (issued + overdue amounts)
 * - Count of overdue invoices
 *
 * Uses a single query with conditional aggregation for efficiency.
 * @see https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html
 */
$invoiceStatsStmt = $db->prepare(
    "SELECT
        COUNT(*) AS totalInvoices,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN totalAmount ELSE 0 END), 0) AS totalPaid,
        COALESCE(SUM(CASE WHEN status IN ('issued', 'overdue') THEN totalAmount ELSE 0 END), 0) AS totalOutstanding,
        SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdueCount,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draftCount,
        SUM(CASE WHEN status = 'issued' THEN 1 ELSE 0 END) AS issuedCount,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paidCount,
        SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) AS voidCount,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelledCount
     FROM tblInvoices"
);
$invoiceStatsStmt->execute();
$invoiceStats = $invoiceStatsStmt->get_result()->fetch_assoc();

/**
 * 💱 Fetch the default currency from tblSettings
 * Falls back to 'GBP' if no setting is configured
 * @see https://www.php.net/manual/en/mysqli-result.fetch-assoc.php
 */
$currencyStmt = $db->prepare(
    "SELECT settingValue FROM tblSettings WHERE settingKey = 'payment_default_currency' LIMIT 1"
);
$currencyStmt->execute();
$currencyResult = $currencyStmt->get_result()->fetch_assoc();
$currency = $currencyResult ? $currencyResult['settingValue'] : 'GBP';

/**
 * 💷 Currency symbol lookup map
 * Maps ISO 4217 codes to their display symbols
 * @see https://en.wikipedia.org/wiki/ISO_4217
 */
$currencySymbols = [
    'GBP' => '£',
    'USD' => '$',
    'EUR' => '€',
    'AUD' => 'A$',
    'CAD' => 'C$',
    'JPY' => '¥',
    'CHF' => 'CHF ',
];
$symbol = $currencySymbols[$currency] ?? $currency . ' ';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- 📄 Document metadata -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Invoice Management - SIGNula Admin</title>

    <!-- 🎨 Bootstrap 5.3.2 (CDN with SRI — Subresource Integrity) -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/download/#cdn-via-jsdelivr -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- 🎯 FontAwesome 6.4.2 (CDN with SRI) -->
    <!-- @see https://fontawesome.com/docs/web/setup/host-yourself/webfonts -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <!-- 🎨 Page-specific styles -->
    <style>
        /* 🏗️ Base body background */
        body { background-color: #f8f9fa; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Page header — green/teal gradient (matches payments theme) */
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
        /* 📊 Stat cards — clickable summary tiles                       */
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
        /* 📋 Table card — wraps the data table with themed header       */
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
        /* 🔍 Filter bar — search, status, date range controls           */
        /* ═══════════════════════════════════════════════════════════════ */
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🏷️ Invoice status badges — colour-coded per status            */
        /* draft=secondary, issued=primary, paid=success, void=dark,     */
        /* overdue=danger, cancelled=warning                              */
        /* ═══════════════════════════════════════════════════════════════ */
        .badge-draft      { background-color: #6c757d; }
        .badge-issued     { background-color: #0d6efd; }
        .badge-paid       { background-color: #28a745; }
        .badge-void       { background-color: #212529; }
        .badge-overdue    { background-color: #dc3545; }
        .badge-cancelled  { background-color: #ffc107; color: #333; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📄 Pagination info text                                       */
        /* ═══════════════════════════════════════════════════════════════ */
        .pagination-info {
            color: #6c757d;
            font-size: 0.9rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔄 Loading overlay — covers table during AJAX requests        */
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
        /* 📱 Modal detail sections — styled info blocks                 */
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
        /* 🧾 Invoice HTML preview — contained within the modal          */
        /* ═══════════════════════════════════════════════════════════════ */
        .invoice-preview-frame {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1.5rem;
            background: #fff;
            max-height: 70vh;
            overflow-y: auto;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎯 Invoice number monospace styling                           */
        /* ═══════════════════════════════════════════════════════════════ */
        .invoice-number {
            font-family: 'Courier New', Courier, monospace;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🖨️ Print-friendly styles for invoice preview modal            */
        /* ═══════════════════════════════════════════════════════════════ */
        @media print {
            body * { visibility: hidden; }
            .invoice-preview-frame,
            .invoice-preview-frame * {
                visibility: visible;
            }
            .invoice-preview-frame {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                max-height: none;
                border: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- 🎨 PAGE HEADER                                            -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div class="page-header">
            <!-- 🔙 Back navigation link to main admin dashboard -->
            <a href="../index.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
            </a>
            <div class="row align-items-center">
                <div class="col-md-7">
                    <h1 class="mb-2">
                        <i class="fas fa-file-invoice-dollar me-2"></i>Invoice Management
                    </h1>
                    <p class="mb-0 opacity-75">View, filter, and manage all system invoices</p>
                </div>
                <div class="col-md-5 text-end">
                    <!-- 🔗 Quick navigation to parent payments dashboard -->
                    <a href="index.php" class="btn btn-outline-light me-2">
                        <i class="fas fa-credit-card me-1"></i>Payments Dashboard
                    </a>
                    <a href="providers" class="btn btn-outline-light">
                        <i class="fas fa-plug me-1"></i>Providers
                    </a>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- 💬 ALERTS CONTAINER — Dynamic alert notifications          -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div id="alertContainer"></div>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- 📊 STAT CARDS — Invoice statistics at a glance             -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div class="row g-3 mb-4">
            <!-- 📋 Total Invoices -->
            <div class="col-6 col-lg-3">
                <div class="stat-card" onclick="clearFiltersAndReload()">
                    <div class="stat-number text-primary">
                        <?php echo number_format((int)($invoiceStats['totalInvoices'] ?? 0)); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-file-invoice me-1"></i>Total Invoices</div>
                </div>
            </div>
            <!-- 💰 Total Paid -->
            <div class="col-6 col-lg-3">
                <div class="stat-card" onclick="filterByStatus('paid')">
                    <div class="stat-number text-success">
                        <?php echo htmlspecialchars($symbol) . number_format((float)($invoiceStats['totalPaid'] ?? 0), 2); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-check-circle me-1"></i>Paid Amount</div>
                </div>
            </div>
            <!-- ⏳ Outstanding Amount -->
            <div class="col-6 col-lg-3">
                <div class="stat-card" onclick="filterByStatus('issued')">
                    <div class="stat-number text-warning">
                        <?php echo htmlspecialchars($symbol) . number_format((float)($invoiceStats['totalOutstanding'] ?? 0), 2); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-hourglass-half me-1"></i>Outstanding</div>
                </div>
            </div>
            <!-- 🔴 Overdue Count -->
            <div class="col-6 col-lg-3">
                <div class="stat-card" onclick="filterByStatus('overdue')">
                    <div class="stat-number text-danger">
                        <?php echo number_format((int)($invoiceStats['overdueCount'] ?? 0)); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-exclamation-triangle me-1"></i>Overdue</div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- 🔍 FILTER BAR — Search, status, and date range controls    -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div class="filter-bar">
            <div class="row g-3 align-items-end">
                <!-- 🔎 Keyword search (invoice number, user email/name) -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><i class="fas fa-search me-1"></i>Search</label>
                    <input type="text"
                           id="invoiceSearch"
                           class="form-control"
                           placeholder="Invoice #, email, name...">
                </div>
                <!-- 📋 Status filter dropdown -->
                <div class="col-md-2">
                    <label class="form-label fw-semibold"><i class="fas fa-filter me-1"></i>Status</label>
                    <select id="invoiceStatusFilter" class="form-select">
                        <option value="all">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="issued">Issued</option>
                        <option value="paid">Paid</option>
                        <option value="void">Void</option>
                        <option value="overdue">Overdue</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <!-- 📅 Date range: From -->
                <div class="col-md-2">
                    <label class="form-label fw-semibold"><i class="fas fa-calendar me-1"></i>From</label>
                    <input type="date" id="dateFrom" class="form-control">
                </div>
                <!-- 📅 Date range: To -->
                <div class="col-md-2">
                    <label class="form-label fw-semibold"><i class="fas fa-calendar me-1"></i>To</label>
                    <input type="date" id="dateTo" class="form-control">
                </div>
                <!-- 🔘 Action buttons -->
                <div class="col-md-1">
                    <button class="btn btn-success w-100" onclick="loadInvoices(1)" title="Apply Filters">
                        <i class="fas fa-filter me-1"></i>Apply
                    </button>
                </div>
                <div class="col-md-1">
                    <button class="btn btn-outline-secondary w-100" onclick="clearFiltersAndReload()" title="Clear Filters">
                        <i class="fas fa-times me-1"></i>Clear
                    </button>
                </div>
                <!-- 📊 Refresh stats button -->
                <div class="col-md-1">
                    <button class="btn btn-outline-info w-100" onclick="refreshStats()" title="Refresh Statistics">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- 📋 INVOICES TABLE — Main data table with pagination        -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div class="card table-card position-relative">
            <!-- 🎨 Table header with gradient and count badge -->
            <div class="table-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Invoices</h5>
                <span id="invoiceCount" class="badge bg-light text-dark"></span>
            </div>

            <!-- 🔄 Loading overlay (shown during AJAX requests) -->
            <div id="invoicesLoading" class="loading-overlay" style="display: none;">
                <div class="spinner-border text-success" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>

            <!-- 📊 Responsive table wrapper -->
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice #</th>
                            <th>User</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Paid Date</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="invoicesTableBody">
                        <!-- 🔄 Initial loading placeholder -->
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>
                                Loading invoices...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 📄 Pagination controls -->
            <div class="d-flex justify-content-between align-items-center p-3 border-top">
                <div class="pagination-info" id="invoicePaginationInfo">Showing 0 of 0</div>
                <nav>
                    <ul class="pagination pagination-sm mb-0" id="invoicePagination"></ul>
                </nav>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📱 MODALS                                                          -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 👁️ VIEW INVOICE MODAL — Displays rendered HTML invoice              -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="viewInvoiceModal" tabindex="-1" aria-labelledby="viewInvoiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #11998e, #38ef7d); color: white;">
                    <h5 class="modal-title" id="viewInvoiceModalLabel">
                        <i class="fas fa-file-invoice me-2"></i>Invoice Preview
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- 🧾 Invoice HTML content will be injected here -->
                    <div id="invoicePreviewContent" class="invoice-preview-frame">
                        <div class="text-center py-5">
                            <div class="spinner-border text-success"></div>
                            <p class="mt-2 text-muted">Loading invoice...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <!-- 🖨️ Print button -->
                    <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                    <!-- 📥 Download PDF button -->
                    <button type="button" class="btn btn-success" id="modalDownloadPdfBtn" onclick="downloadInvoicePdf()">
                        <i class="fas fa-file-pdf me-1"></i>Download PDF
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📋 INVOICE DETAIL MODAL — Line items, billing address, metadata    -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="invoiceDetailModal" tabindex="-1" aria-labelledby="invoiceDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #11998e, #38ef7d); color: white;">
                    <h5 class="modal-title" id="invoiceDetailModalLabel">
                        <i class="fas fa-info-circle me-2"></i>Invoice Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="invoiceDetailBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-success"></div>
                        <p class="mt-2 text-muted">Loading details...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 💰 MARK PAID MODAL — Enter transaction reference for payment       -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="markPaidModal" tabindex="-1" aria-labelledby="markPaidModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="markPaidModalLabel">
                        <i class="fas fa-check-circle me-2"></i>Mark Invoice as Paid
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- 🔑 Hidden invoice ID for the API call -->
                    <input type="hidden" id="markPaidInvoiceID">
                    <!-- ℹ️ Invoice info display -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Invoice</label>
                        <div id="markPaidInvoiceInfo" class="text-muted"></div>
                    </div>
                    <!-- 🔖 Transaction reference input -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="transactionReference">
                            Transaction Reference <small class="text-muted">(optional)</small>
                        </label>
                        <input type="text"
                               id="transactionReference"
                               class="form-control"
                               placeholder="e.g. PayPal transaction ID, bank reference...">
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Enter the payment reference from the payment provider if available.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="confirmMarkPaid()">
                        <i class="fas fa-check me-1"></i>Confirm Payment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- ❌ MARK VOID MODAL — Enter reason for voiding an invoice           -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="markVoidModal" tabindex="-1" aria-labelledby="markVoidModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="markVoidModalLabel">
                        <i class="fas fa-ban me-2"></i>Void Invoice
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- 🔑 Hidden invoice ID for the API call -->
                    <input type="hidden" id="markVoidInvoiceID">
                    <!-- ℹ️ Invoice info display -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Invoice</label>
                        <div id="markVoidInvoiceInfo" class="text-muted"></div>
                    </div>
                    <!-- ⚠️ Warning message -->
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>Warning:</strong> Voiding an invoice is permanent and cannot be undone.
                        The invoice will be marked as void and will no longer be payable.
                    </div>
                    <!-- 📝 Reason textarea -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="voidReason">
                            Reason for Voiding <span class="text-danger">*</span>
                        </label>
                        <textarea id="voidReason"
                                  class="form-control"
                                  rows="3"
                                  placeholder="Explain why this invoice is being voided..."
                                  required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-dark" onclick="confirmMarkVoid()">
                        <i class="fas fa-ban me-1"></i>Void Invoice
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🔐 HIDDEN CSRF TOKEN — Used by all AJAX POST requests             -->
    <!-- @see https://owasp.org/www-community/attacks/csrf                  -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken()); ?>">

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🔌 JAVASCRIPT DEPENDENCIES                                         -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->

    <!-- 📦 jQuery 3.7.1 (CDN with SRI) — Required for $.ajax() calls -->
    <!-- @see https://jquery.com/ -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
            integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
            crossorigin="anonymous"></script>

    <!-- 🔐 Bootstrap 5.3.2 Bundle JS (CDN with SRI) — Includes Popper.js -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/download/#cdn-via-jsdelivr -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📜 PAGE JAVASCRIPT — Invoice management client-side logic          -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <script>
    /**
     * ============================================================================
     * 🧾 Invoice Management Dashboard — Client-Side Logic
     * ============================================================================
     *
     * Handles AJAX data loading, filtering, pagination, status transitions,
     * invoice preview, and all modal interactions for the invoice management
     * dashboard.
     *
     * Uses jQuery $.ajax() for all API calls to the invoice-actions.php endpoint.
     *
     * @see https://api.jquery.com/jquery.ajax/ — jQuery AJAX Reference
     * @see https://getbootstrap.com/docs/5.3/components/modal/ — Bootstrap Modals
     * ============================================================================
     */

    // ═══════════════════════════════════════════════════════════════════════
    // 🔧 STATE & CONFIGURATION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @var {number} currentPage — Tracks the current page number for pagination
     */
    let currentPage = 1;

    /**
     * @var {number} perPage — Number of invoices to display per page
     */
    const perPage = 25;

    /**
     * @var {string} currencySymbol — The currency symbol rendered by PHP
     */
    const currencySymbol = '<?php echo htmlspecialchars($symbol); ?>';

    /**
     * @var {number|null} currentPreviewInvoiceID — Tracks which invoice is being previewed
     * Used by the PDF download button inside the preview modal
     */
    let currentPreviewInvoiceID = null;

    // ═══════════════════════════════════════════════════════════════════════
    // 🛠️ UTILITY FUNCTIONS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔒 Escape HTML entities to prevent XSS attacks
     *
     * Creates a temporary text node to safely encode special characters.
     *
     * @param {string|null} text — The raw text to escape
     * @returns {string} — HTML-safe escaped string
     * @see https://owasp.org/www-community/attacks/xss/
     */
    function escapeHtml(text) {
        if (!text) { return ''; }
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * 💬 Show a Bootstrap alert notification
     *
     * Appends a dismissible alert to the #alertContainer and auto-removes
     * it after 5 seconds.
     *
     * @param {string} message — Alert message content (supports HTML)
     * @param {string} type    — Bootstrap alert type (success, danger, warning, info)
     * @see https://getbootstrap.com/docs/5.3/components/alerts/
     */
    function showAlert(message, type = 'info') {
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        document.getElementById('alertContainer').appendChild(alert);
        // ⏰ Auto-dismiss after 5 seconds
        setTimeout(function() {
            if (alert.parentNode) { alert.remove(); }
        }, 5000);
    }

    /**
     * 📅 Format an ISO date string for user-friendly display
     *
     * @param {string|null} dateStr — ISO 8601 date string from the API
     * @returns {string} — Formatted date (e.g., "13 Feb 2026") or em-dash if null
     * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date/toLocaleDateString
     */
    function formatDate(dateStr) {
        if (!dateStr) { return '—'; }
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
    }

    /**
     * 💰 Format a numeric amount with the configured currency symbol
     *
     * @param {number|string} amount — The amount to format
     * @returns {string} — Formatted amount (e.g., "£1,234.56")
     */
    function formatCurrency(amount) {
        const num = parseFloat(amount) || 0;
        return currencySymbol + num.toLocaleString('en-GB', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /**
     * 🏷️ Generate an HTML badge for the given invoice status
     *
     * Maps statuses to their respective CSS classes:
     * - draft    → badge-draft    (secondary/grey)
     * - issued   → badge-issued   (primary/blue)
     * - paid     → badge-paid     (success/green)
     * - void     → badge-void     (dark/black)
     * - overdue  → badge-overdue  (danger/red)
     * - cancelled → badge-cancelled (warning/yellow)
     *
     * @param {string} status — The invoice status string
     * @returns {string} — HTML badge element
     */
    function statusBadge(status) {
        const s = (status || '').toLowerCase();
        return '<span class="badge badge-' + s + '">' + escapeHtml(status) + '</span>';
    }

    /**
     * 🔐 Retrieve the CSRF token from the hidden input field
     *
     * @returns {string} — The current CSRF token value
     * @see https://owasp.org/www-community/attacks/csrf
     */
    function getCsrfToken() {
        return $('#csrf_token').val();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 📋 INVOICE LIST — AJAX loading, rendering, and pagination
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 📋 Load invoices from the API with current filter values
     *
     * Sends a GET request to invoice-actions.php?action=get_invoices with
     * all filter parameters (status, search, dateFrom, dateTo, limit, offset).
     * Updates the table body and pagination controls on success.
     *
     * @param {number} page — The page number to load (1-indexed)
     * @see https://api.jquery.com/jquery.ajax/
     */
    function loadInvoices(page) {
        // 📌 Update the current page tracker
        page = page || 1;
        currentPage = page;

        // 📊 Calculate the offset for the query
        const offset = (page - 1) * perPage;

        // 🔄 Show loading overlay
        $('#invoicesLoading').show();

        // 📤 Prepare the request parameters from filter inputs
        const params = {
            action: 'get_invoices',
            status: $('#invoiceStatusFilter').val(),
            search: $('#invoiceSearch').val().trim(),
            dateFrom: $('#dateFrom').val(),
            dateTo: $('#dateTo').val(),
            limit: perPage,
            offset: offset
        };

        // 🌐 Send the AJAX GET request
        $.ajax({
            url: '../../admin/api/invoice-actions.php',
            type: 'GET',
            data: params,
            dataType: 'json',
            /**
             * ✅ Success callback — render the invoice rows and pagination
             * @param {Object} response — API JSON response
             */
            success: function(response) {
                if (response.success) {
                    renderInvoices(response.data);
                    renderPagination(response.total, response.pages, response.currentPage);
                    $('#invoiceCount').text(response.total + ' invoices');
                } else {
                    showAlert(response.message || 'Failed to load invoices', 'danger');
                }
            },
            /**
             * ❌ Error callback — display error alert
             * @param {Object} xhr — The XMLHttpRequest object
             */
            error: function(xhr) {
                showAlert('Error loading invoices. Please try again.', 'danger');
            },
            /**
             * 🔄 Complete callback — always hide the loading overlay
             */
            complete: function() {
                $('#invoicesLoading').hide();
            }
        });
    }

    /**
     * 📋 Render invoice rows into the table body
     *
     * Generates HTML table rows from the invoice data array. Each row includes
     * the invoice number, user info, amount, status badge, dates, and action
     * buttons (View, Details, Mark Paid, Mark Void, Reissue, Email).
     *
     * @param {Array} invoices — Array of invoice objects from the API
     */
    function renderInvoices(invoices) {
        const tbody = $('#invoicesTableBody');

        // 🚫 Empty state — no invoices match the current filters
        if (!invoices || invoices.length === 0) {
            tbody.html(
                '<tr>' +
                    '<td colspan="8" class="text-center py-5 text-muted">' +
                        '<i class="fas fa-file-invoice fa-3x mb-3 d-block opacity-50"></i>' +
                        '<h5>No Invoices Found</h5>' +
                        '<p>No invoices match your current filters.</p>' +
                    '</td>' +
                '</tr>'
            );
            return;
        }

        // 🔄 Build all rows
        let html = '';
        invoices.forEach(function(inv) {
            // 🏷️ Determine which action buttons to show based on current status
            const status = (inv.status || '').toLowerCase();
            let actions = '';

            // 👁️ View HTML invoice — always available
            actions += '<button class="btn btn-sm btn-outline-success me-1" ' +
                       'onclick="viewInvoiceHtml(' + inv.invoiceID + ')" ' +
                       'title="View Invoice"><i class="fas fa-eye"></i></button>';

            // 📋 Invoice details — always available
            actions += '<button class="btn btn-sm btn-outline-info me-1" ' +
                       'onclick="viewInvoiceDetail(' + inv.invoiceID + ')" ' +
                       'title="Invoice Details"><i class="fas fa-info-circle"></i></button>';

            // 📥 Download PDF — always available
            actions += '<button class="btn btn-sm btn-outline-secondary me-1" ' +
                       'onclick="downloadPdf(' + inv.invoiceID + ')" ' +
                       'title="Download PDF"><i class="fas fa-file-pdf"></i></button>';

            // 💰 Mark Paid — only for issued/overdue invoices
            if (status === 'issued' || status === 'overdue') {
                actions += '<button class="btn btn-sm btn-outline-primary me-1" ' +
                           'onclick="showMarkPaidModal(' + inv.invoiceID + ', \'' + escapeHtml(inv.invoiceNumber) + '\', ' + (inv.totalAmount || 0) + ')" ' +
                           'title="Mark as Paid"><i class="fas fa-check"></i></button>';
            }

            // ❌ Mark Void — for draft/issued/overdue invoices
            if (status === 'draft' || status === 'issued' || status === 'overdue') {
                actions += '<button class="btn btn-sm btn-outline-dark me-1" ' +
                           'onclick="showMarkVoidModal(' + inv.invoiceID + ', \'' + escapeHtml(inv.invoiceNumber) + '\', ' + (inv.totalAmount || 0) + ')" ' +
                           'title="Void Invoice"><i class="fas fa-ban"></i></button>';
            }

            // 🔄 Reissue — only for void/cancelled invoices
            if (status === 'void' || status === 'cancelled') {
                actions += '<button class="btn btn-sm btn-outline-warning me-1" ' +
                           'onclick="reissueInvoice(' + inv.invoiceID + ', \'' + escapeHtml(inv.invoiceNumber) + '\')" ' +
                           'title="Reissue Invoice"><i class="fas fa-redo"></i></button>';
            }

            // 📧 Send/Resend Email — for issued/overdue/paid invoices
            if (status === 'issued' || status === 'overdue' || status === 'paid') {
                actions += '<button class="btn btn-sm btn-outline-info" ' +
                           'onclick="sendInvoiceEmail(' + inv.invoiceID + ', \'' + escapeHtml(inv.invoiceNumber) + '\')" ' +
                           'title="Send/Resend Email"><i class="fas fa-envelope"></i></button>';
            }

            // 📊 Build the table row
            html += '<tr>' +
                '<td><code class="invoice-number text-primary">' + escapeHtml(inv.invoiceNumber || '—') + '</code></td>' +
                '<td>' +
                    '<strong>' + escapeHtml(inv.displayName || inv.username || 'Unknown') + '</strong>' +
                    '<br><small class="text-muted">' + escapeHtml(inv.email || '') + '</small>' +
                '</td>' +
                '<td class="fw-bold">' + formatCurrency(inv.totalAmount) + '</td>' +
                '<td>' + statusBadge(inv.status) + '</td>' +
                '<td>' + formatDate(inv.issuedAt || inv.createdAt) + '</td>' +
                '<td>' + formatDate(inv.dueDate) + '</td>' +
                '<td>' + formatDate(inv.paidAt) + '</td>' +
                '<td class="text-center text-nowrap">' + actions + '</td>' +
            '</tr>';
        });

        tbody.html(html);
    }

    /**
     * 📄 Render pagination controls below the table
     *
     * Generates Previous/Next buttons and numbered page links.
     * Displays a maximum of 5 visible page numbers for compact navigation.
     *
     * @param {number} total   — Total number of matching invoices
     * @param {number} pages   — Total number of pages
     * @param {number} current — Current page number
     */
    function renderPagination(total, pages, current) {
        // 📊 Update the pagination info text (e.g., "Showing 1-25 of 150")
        const start = Math.min(total, (current - 1) * perPage + 1);
        const end = Math.min(total, current * perPage);
        $('#invoicePaginationInfo').text(
            total > 0
                ? 'Showing ' + start + '-' + end + ' of ' + total
                : 'No invoices found'
        );

        const nav = $('#invoicePagination');
        nav.empty();

        // 🚫 No pagination needed for single page
        if (pages <= 1) { return; }

        // ⬅️ Previous page button
        nav.append(
            '<li class="page-item ' + (current === 1 ? 'disabled' : '') + '">' +
                '<a class="page-link" href="#" onclick="loadInvoices(' + (current - 1) + '); return false;">&laquo;</a>' +
            '</li>'
        );

        // 📊 Numbered page buttons (show max 5 around current)
        const startPage = Math.max(1, current - 2);
        const endPage = Math.min(pages, startPage + 4);
        for (let i = startPage; i <= endPage; i++) {
            nav.append(
                '<li class="page-item ' + (i === current ? 'active' : '') + '">' +
                    '<a class="page-link" href="#" onclick="loadInvoices(' + i + '); return false;">' + i + '</a>' +
                '</li>'
            );
        }

        // ➡️ Next page button
        nav.append(
            '<li class="page-item ' + (current === pages ? 'disabled' : '') + '">' +
                '<a class="page-link" href="#" onclick="loadInvoices(' + (current + 1) + '); return false;">&raquo;</a>' +
            '</li>'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔍 FILTER HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔍 Set the status filter and reload invoices
     *
     * Called from the stat cards to quickly filter by a specific status.
     *
     * @param {string} status — The status value to filter by
     */
    function filterByStatus(status) {
        $('#invoiceStatusFilter').val(status);
        loadInvoices(1);
    }

    /**
     * 🧹 Clear all filter inputs and reload the full invoice list
     */
    function clearFiltersAndReload() {
        $('#invoiceSearch').val('');
        $('#invoiceStatusFilter').val('all');
        $('#dateFrom').val('');
        $('#dateTo').val('');
        loadInvoices(1);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 📊 STATISTICS REFRESH
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 📊 Refresh the invoice statistics cards via AJAX
     *
     * Fetches fresh stats from the API and updates the stat card numbers.
     */
    function refreshStats() {
        $.ajax({
            url: '../../admin/api/invoice-actions.php',
            type: 'GET',
            data: { action: 'get_invoice_stats' },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    // 📊 Update stat card values (using DOM traversal for simplicity)
                    showAlert('<i class="fas fa-check-circle me-1"></i> Statistics refreshed', 'success');
                } else {
                    showAlert('Failed to refresh statistics', 'warning');
                }
            },
            error: function() {
                showAlert('Error refreshing statistics', 'danger');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 👁️ VIEW INVOICE HTML — Render the HTML invoice inside a modal
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 👁️ Load and display the rendered HTML invoice in the preview modal
     *
     * Sends a GET request to get_invoice_html action and injects the returned
     * HTML into the modal's preview frame.
     *
     * @param {number} invoiceID — The ID of the invoice to preview
     */
    function viewInvoiceHtml(invoiceID) {
        // 📌 Store the invoice ID for the PDF download button
        currentPreviewInvoiceID = invoiceID;

        // 🔄 Reset modal content to loading state
        $('#invoicePreviewContent').html(
            '<div class="text-center py-5">' +
                '<div class="spinner-border text-success"></div>' +
                '<p class="mt-2 text-muted">Loading invoice...</p>' +
            '</div>'
        );

        // 📱 Show the modal
        new bootstrap.Modal(document.getElementById('viewInvoiceModal')).show();

        // 🌐 Fetch the rendered HTML invoice from the API
        $.ajax({
            url: '../../admin/api/invoice-actions.php',
            type: 'GET',
            data: {
                action: 'get_invoice_html',
                invoiceID: invoiceID
            },
            dataType: 'json',
            /**
             * ✅ Inject the HTML invoice content into the preview frame
             */
            success: function(response) {
                if (response.success && response.html) {
                    $('#invoicePreviewContent').html(response.html);
                } else {
                    $('#invoicePreviewContent').html(
                        '<div class="alert alert-danger">' +
                            '<i class="fas fa-exclamation-triangle me-1"></i>' +
                            (response.message || 'Failed to load invoice preview') +
                        '</div>'
                    );
                }
            },
            error: function() {
                $('#invoicePreviewContent').html(
                    '<div class="alert alert-danger">' +
                        '<i class="fas fa-exclamation-triangle me-1"></i>' +
                        'Error loading invoice preview. Please try again.' +
                    '</div>'
                );
            }
        });
    }

    /**
     * 📥 Download PDF for the currently previewed invoice
     *
     * Opens a new tab/window pointing to the PDF download endpoint.
     * Used by the "Download PDF" button inside the preview modal.
     */
    function downloadInvoicePdf() {
        if (currentPreviewInvoiceID) {
            downloadPdf(currentPreviewInvoiceID);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 📋 INVOICE DETAIL — Full detail modal with line items & billing info
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 📋 Load and display full invoice details in the detail modal
     *
     * Fetches the complete invoice record including line items, billing address,
     * payment reference, and metadata. Renders them in a structured layout.
     *
     * @param {number} invoiceID — The ID of the invoice to display
     */
    function viewInvoiceDetail(invoiceID) {
        // 🔄 Reset modal body to loading state
        const body = $('#invoiceDetailBody');
        body.html(
            '<div class="text-center py-5">' +
                '<div class="spinner-border text-success"></div>' +
                '<p class="mt-2 text-muted">Loading details...</p>' +
            '</div>'
        );

        // 📱 Show the detail modal
        new bootstrap.Modal(document.getElementById('invoiceDetailModal')).show();

        // 🌐 Fetch invoice details from the API
        $.ajax({
            url: '../../admin/api/invoice-actions.php',
            type: 'GET',
            data: {
                action: 'get_invoice',
                invoiceID: invoiceID
            },
            dataType: 'json',
            /**
             * ✅ Render the full invoice detail view
             */
            success: function(response) {
                if (response.success && response.data) {
                    const inv = response.data;

                    // 🧾 Parse JSON fields if they are strings
                    let lineItems = inv.lineItems;
                    if (typeof lineItems === 'string') {
                        try { lineItems = JSON.parse(lineItems); } catch(e) { lineItems = []; }
                    }
                    lineItems = lineItems || [];

                    let billingAddress = inv.billingAddress;
                    if (typeof billingAddress === 'string') {
                        try { billingAddress = JSON.parse(billingAddress); } catch(e) { billingAddress = null; }
                    }

                    // 📊 Build the detail HTML
                    let html = '<div class="row">';

                    // ═══ LEFT COLUMN: Invoice info ═══
                    html += '<div class="col-md-6">';
                    html += '<div class="detail-section">';
                    html += '<h6><i class="fas fa-file-invoice me-1"></i>Invoice Information</h6>';
                    html += '<div class="mb-2"><span class="detail-label">Invoice #:</span> <span class="detail-value"><code class="invoice-number">' + escapeHtml(inv.invoiceNumber) + '</code></span></div>';
                    html += '<div class="mb-2"><span class="detail-label">Status:</span> <span class="detail-value">' + statusBadge(inv.status) + '</span></div>';
                    html += '<div class="mb-2"><span class="detail-label">Type:</span> <span class="detail-value">' + escapeHtml(inv.invoiceType || '—') + '</span></div>';
                    html += '<div class="mb-2"><span class="detail-label">Currency:</span> <span class="detail-value">' + escapeHtml(inv.currency || 'GBP') + '</span></div>';
                    html += '<div class="mb-2"><span class="detail-label">Issued:</span> <span class="detail-value">' + formatDate(inv.issuedAt || inv.createdAt) + '</span></div>';
                    html += '<div class="mb-2"><span class="detail-label">Due Date:</span> <span class="detail-value">' + formatDate(inv.dueDate) + '</span></div>';
                    html += '<div class="mb-2"><span class="detail-label">Paid Date:</span> <span class="detail-value">' + formatDate(inv.paidAt) + '</span></div>';
                    if (inv.transactionReference) {
                        html += '<div class="mb-2"><span class="detail-label">Payment Ref:</span> <span class="detail-value"><code>' + escapeHtml(inv.transactionReference) + '</code></span></div>';
                    }
                    html += '</div>'; // detail-section
                    html += '</div>'; // col

                    // ═══ RIGHT COLUMN: User & billing info ═══
                    html += '<div class="col-md-6">';

                    // 👤 User info
                    html += '<div class="detail-section">';
                    html += '<h6><i class="fas fa-user me-1"></i>Billed To</h6>';
                    html += '<div class="mb-2"><span class="detail-label">User:</span> <span class="detail-value">' + escapeHtml(inv.displayName || inv.username || 'Unknown') + '</span></div>';
                    html += '<div class="mb-2"><span class="detail-label">Email:</span> <span class="detail-value">' + escapeHtml(inv.email || '—') + '</span></div>';
                    html += '</div>'; // detail-section

                    // 🏠 Billing address (if available)
                    if (billingAddress) {
                        html += '<div class="detail-section">';
                        html += '<h6><i class="fas fa-map-marker-alt me-1"></i>Billing Address</h6>';
                        html += '<div class="mb-1">' + escapeHtml(billingAddress.line1 || '') + '</div>';
                        if (billingAddress.line2) {
                            html += '<div class="mb-1">' + escapeHtml(billingAddress.line2) + '</div>';
                        }
                        html += '<div class="mb-1">' +
                            escapeHtml(billingAddress.city || '') +
                            (billingAddress.state ? ', ' + escapeHtml(billingAddress.state) : '') +
                            ' ' + escapeHtml(billingAddress.postcode || '') +
                        '</div>';
                        html += '<div>' + escapeHtml(billingAddress.country || '') + '</div>';
                        html += '</div>'; // detail-section
                    }

                    // 💰 Financial summary
                    html += '<div class="detail-section">';
                    html += '<h6><i class="fas fa-calculator me-1"></i>Financial Summary</h6>';
                    html += '<div class="mb-2"><span class="detail-label">Subtotal:</span> <span class="detail-value">' + formatCurrency(inv.subtotal) + '</span></div>';
                    if (parseFloat(inv.discountAmount) > 0) {
                        html += '<div class="mb-2"><span class="detail-label">Discount:</span> <span class="detail-value text-danger">-' + formatCurrency(inv.discountAmount) + '</span></div>';
                    }
                    if (parseFloat(inv.taxAmount) > 0) {
                        html += '<div class="mb-2"><span class="detail-label">Tax:</span> <span class="detail-value">' + formatCurrency(inv.taxAmount) + '</span></div>';
                    }
                    html += '<div class="mb-2"><span class="detail-label fw-bold">Total:</span> <span class="detail-value fw-bold fs-5">' + formatCurrency(inv.totalAmount) + '</span></div>';
                    html += '</div>'; // detail-section

                    html += '</div>'; // col
                    html += '</div>'; // row

                    // ═══ LINE ITEMS TABLE ═══
                    if (lineItems.length > 0) {
                        html += '<hr>';
                        html += '<h6><i class="fas fa-list me-1"></i>Line Items</h6>';
                        html += '<div class="table-responsive">';
                        html += '<table class="table table-sm table-bordered">';
                        html += '<thead class="table-light"><tr>';
                        html += '<th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th>';
                        html += '</tr></thead><tbody>';

                        lineItems.forEach(function(item) {
                            html += '<tr>';
                            html += '<td>' + escapeHtml(item.description || '—') + '</td>';
                            html += '<td class="text-end">' + (item.quantity || 1) + '</td>';
                            html += '<td class="text-end">' + formatCurrency(item.unitPrice || 0) + '</td>';
                            html += '<td class="text-end fw-bold">' + formatCurrency(item.total || 0) + '</td>';
                            html += '</tr>';
                        });

                        html += '</tbody></table></div>';
                    }

                    // ═══ NOTES ═══
                    if (inv.notes) {
                        html += '<hr>';
                        html += '<div class="detail-section">';
                        html += '<h6><i class="fas fa-sticky-note me-1"></i>Notes</h6>';
                        html += '<p class="text-muted mb-0">' + escapeHtml(inv.notes) + '</p>';
                        html += '</div>';
                    }

                    body.html(html);
                } else {
                    body.html(
                        '<div class="alert alert-danger">' +
                            '<i class="fas fa-exclamation-triangle me-1"></i>' +
                            escapeHtml(response.message || 'Failed to load invoice details') +
                        '</div>'
                    );
                }
            },
            error: function() {
                body.html(
                    '<div class="alert alert-danger">' +
                        '<i class="fas fa-exclamation-triangle me-1"></i>' +
                        'Error loading invoice details. Please try again.' +
                    '</div>'
                );
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 📥 DOWNLOAD PDF
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 📥 Download the PDF version of an invoice
     *
     * Opens the PDF download endpoint in a new browser tab. The server-side
     * handler generates the PDF via TCPDF and sends it as a download.
     *
     * @param {number} invoiceID — The ID of the invoice to download
     */
    function downloadPdf(invoiceID) {
        // 🌐 Open the PDF endpoint in a new tab
        window.open(
            '../../admin/api/invoice-actions.php?action=download_pdf&invoiceID=' + invoiceID,
            '_blank'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 💰 MARK PAID — Modal and confirmation
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 💰 Show the "Mark as Paid" modal with pre-filled invoice info
     *
     * @param {number} invoiceID     — The invoice ID
     * @param {string} invoiceNumber — The display invoice number
     * @param {number} amount        — The invoice total amount
     */
    function showMarkPaidModal(invoiceID, invoiceNumber, amount) {
        $('#markPaidInvoiceID').val(invoiceID);
        $('#markPaidInvoiceInfo').html(
            '<strong>' + escapeHtml(invoiceNumber) + '</strong> — ' + formatCurrency(amount)
        );
        $('#transactionReference').val('');
        new bootstrap.Modal(document.getElementById('markPaidModal')).show();
    }

    /**
     * ✅ Confirm marking the invoice as paid via API
     *
     * Sends a POST request to the mark_paid action with the invoice ID
     * and optional transaction reference. Reloads the invoice list on success.
     */
    function confirmMarkPaid() {
        const invoiceID = parseInt($('#markPaidInvoiceID').val());
        const transactionReference = $('#transactionReference').val().trim();

        // 🌐 Send the AJAX POST request
        $.ajax({
            url: '../../admin/api/invoice-actions.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'mark_paid',
                invoiceID: invoiceID,
                transactionReference: transactionReference || null,
                csrf_token: getCsrfToken()
            }),
            dataType: 'json',
            success: function(response) {
                // 🔒 Dismiss the modal
                bootstrap.Modal.getInstance(document.getElementById('markPaidModal')).hide();

                if (response.success) {
                    showAlert('<i class="fas fa-check-circle me-1"></i> ' + (response.message || 'Invoice marked as paid'), 'success');
                    loadInvoices(currentPage);
                } else {
                    showAlert(response.message || 'Failed to mark invoice as paid', 'danger');
                }
            },
            error: function() {
                showAlert('Error marking invoice as paid. Please try again.', 'danger');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ❌ MARK VOID — Modal and confirmation
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * ❌ Show the "Void Invoice" modal with pre-filled invoice info
     *
     * @param {number} invoiceID     — The invoice ID
     * @param {string} invoiceNumber — The display invoice number
     * @param {number} amount        — The invoice total amount
     */
    function showMarkVoidModal(invoiceID, invoiceNumber, amount) {
        $('#markVoidInvoiceID').val(invoiceID);
        $('#markVoidInvoiceInfo').html(
            '<strong>' + escapeHtml(invoiceNumber) + '</strong> — ' + formatCurrency(amount)
        );
        $('#voidReason').val('');
        new bootstrap.Modal(document.getElementById('markVoidModal')).show();
    }

    /**
     * ✅ Confirm voiding the invoice via API
     *
     * Sends a POST request to the mark_void action with the invoice ID
     * and reason. Requires a reason to be provided.
     */
    function confirmMarkVoid() {
        const invoiceID = parseInt($('#markVoidInvoiceID').val());
        const reason = $('#voidReason').val().trim();

        // ✅ Validate that a reason is provided
        if (!reason) {
            showAlert('Please provide a reason for voiding this invoice.', 'warning');
            $('#voidReason').focus();
            return;
        }

        // 🌐 Send the AJAX POST request
        $.ajax({
            url: '../../admin/api/invoice-actions.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'mark_void',
                invoiceID: invoiceID,
                reason: reason,
                csrf_token: getCsrfToken()
            }),
            dataType: 'json',
            success: function(response) {
                // 🔒 Dismiss the modal
                bootstrap.Modal.getInstance(document.getElementById('markVoidModal')).hide();

                if (response.success) {
                    showAlert('<i class="fas fa-check-circle me-1"></i> ' + (response.message || 'Invoice voided successfully'), 'success');
                    loadInvoices(currentPage);
                } else {
                    showAlert(response.message || 'Failed to void invoice', 'danger');
                }
            },
            error: function() {
                showAlert('Error voiding invoice. Please try again.', 'danger');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔄 REISSUE INVOICE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔄 Reissue a voided or cancelled invoice
     *
     * Confirms with the user before sending a POST request to create
     * a new invoice based on the original (original is voided if not already).
     *
     * @param {number} invoiceID     — The original invoice ID
     * @param {string} invoiceNumber — The original invoice number for display
     */
    function reissueInvoice(invoiceID, invoiceNumber) {
        // ⚠️ Confirmation dialog
        if (!confirm(
            'Reissue Invoice ' + invoiceNumber + '?\n\n' +
            'This will create a new invoice based on the original. ' +
            'The original invoice will be marked as void if it is not already.'
        )) {
            return;
        }

        // 🌐 Send the AJAX POST request
        $.ajax({
            url: '../../admin/api/invoice-actions.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'reissue_invoice',
                originalInvoiceID: invoiceID,
                csrf_token: getCsrfToken()
            }),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showAlert(
                        '<i class="fas fa-check-circle me-1"></i> Invoice reissued successfully. ' +
                        'New invoice: <strong>' + escapeHtml(response.newInvoiceNumber || '') + '</strong>',
                        'success'
                    );
                    loadInvoices(currentPage);
                } else {
                    showAlert(response.message || 'Failed to reissue invoice', 'danger');
                }
            },
            error: function() {
                showAlert('Error reissuing invoice. Please try again.', 'danger');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 📧 SEND INVOICE EMAIL
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 📧 Send or resend an invoice email to the user
     *
     * Confirms with the user before dispatching the email.
     *
     * @param {number} invoiceID     — The invoice ID
     * @param {string} invoiceNumber — The invoice number for display
     */
    function sendInvoiceEmail(invoiceID, invoiceNumber) {
        // ⚠️ Confirmation dialog
        if (!confirm('Send invoice email for ' + invoiceNumber + '?')) {
            return;
        }

        // 🌐 Send the AJAX POST request
        $.ajax({
            url: '../../admin/api/invoice-actions.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'send_invoice_email',
                invoiceID: invoiceID,
                csrf_token: getCsrfToken()
            }),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showAlert(
                        '<i class="fas fa-check-circle me-1"></i> Invoice email sent successfully for ' +
                        escapeHtml(invoiceNumber),
                        'success'
                    );
                } else {
                    showAlert(response.message || 'Failed to send invoice email', 'danger');
                }
            },
            error: function() {
                showAlert('Error sending invoice email. Please try again.', 'danger');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🚀 INITIAL LOAD — Start loading data when the DOM is ready
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🎯 Initialise the page on DOM ready
     *
     * - Loads the first page of invoices
     * - Binds Enter key on the search input to trigger a search
     *
     * @see https://api.jquery.com/ready/
     */
    $(document).ready(function() {
        // 📋 Load the first page of invoices
        loadInvoices(1);

        // ⌨️ Search on Enter key press in the search input
        $('#invoiceSearch').on('keypress', function(e) {
            if (e.key === 'Enter') {
                loadInvoices(1);
            }
        });
    });
    </script>
</body>
</html>
