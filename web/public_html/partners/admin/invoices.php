<?php
/**
 * 🧾 Partner Invoice Management Page
 *
 * Displays invoices for the selected partner organization with filtering,
 * search, status badges, and modal invoice preview. Supports two invoice
 * types (levels):
 *   - Level 1 ("SIGNula Bill")   — Invoices FROM SIGNula TO the partner
 *   - Level 2 ("Customer Payment") — Invoices from the partner's customers
 *
 * Fetches data via AJAX from the super admin invoice-actions API
 * (/admin/api/invoice-actions.php) with partnerID filter. Server-side
 * access control validates that the requesting user has finance role
 * or higher for the specified partner.
 *
 * 🔒 Access: Finance role or higher (finance, support, developer, admin, root-admin, super-admin)
 * @see AccessControl::canViewPartnerFinancials() for role hierarchy
 * @see https://getbootstrap.com/docs/5.3/components/modal/ (Bootstrap 5 modals)
 * @see https://datatables.net/ (DataTables for enhanced table functionality)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.4.0-beta
 */

// ============================================================================
// 🔧 BOOTSTRAP: Load core configuration and backend classes
// ============================================================================

/**
 * 📦 Load the global configuration file which defines constants,
 * initialises error handling, and loads SecurityUtils.
 * @see /web/_config/config.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

/**
 * 🗄️ Database singleton for MySQLi prepared statement queries.
 * @see /web/_backend/Database.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

/**
 * 🔐 Session management — handles login state, user info, session tokens.
 * @see /web/_backend/SessionManager.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

/**
 * 🛡️ Role-based access control with multi-tier partner permissions.
 * @see /web/_backend/AccessControl.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';


// ============================================================================
// 🏗️ INITIALISE CORE SERVICES
// ============================================================================

/** @var Database $db Database singleton instance */
$db = Database::getInstance();

/** @var SessionManager $sessionManager Manages user sessions and auth state */
$sessionManager = new SessionManager($db);

/** @var AccessControl $accessControl Multi-tier RBAC for partner permissions */
$accessControl = new AccessControl($db, $sessionManager);


// ============================================================================
// 🔐 AUTHENTICATION CHECK
// ============================================================================

/**
 * 🚪 Redirect unauthenticated users to the login page.
 * All partner admin pages require an active session.
 */
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}


// ============================================================================
// 🏢 PARTNER MEMBERSHIP & SELECTION
// ============================================================================

/**
 * 📋 Retrieve all partner organizations the current user belongs to.
 * Returns an associative array keyed by partnerID with membership details
 * including role, companyName, tier, and isRootAdmin flag.
 */
$memberships = $accessControl->getPartnerMemberships();

/**
 * 🚫 If the user has no partner memberships, redirect to partner registration.
 * Users must belong to at least one partner organization to access admin pages.
 */
if (empty($memberships)) {
    header('Location: /partners/register.php');
    exit;
}

/**
 * 🎯 Determine which partner is currently selected.
 * Priority: GET parameter > first available membership.
 * Cast to int for type safety in prepared statements.
 */
$selectedPartnerID = (int)($_GET['partner'] ?? array_key_first($memberships));

/**
 * 📦 Get the membership record for the selected partner.
 * Falls back to the first membership if the requested partner is not found.
 */
$partner = $memberships[$selectedPartnerID] ?? reset($memberships);


// ============================================================================
// 💰 FINANCE ACCESS VERIFICATION
// ============================================================================

/**
 * 🔒 Verify that the current user has finance role or higher for this partner.
 * The canViewPartnerFinancials() method checks the role hierarchy:
 *   finance (20) <= support (30) <= developer (40) <= admin (60) <= root-admin (80)
 * Super admins bypass all checks.
 *
 * @see AccessControl::canViewPartnerFinancials()
 * @see AccessControl::ROLE_HIERARCHY
 */
if (!$accessControl->canViewPartnerFinancials($selectedPartnerID)) {
    http_response_code(403);
    die('Finance access or higher is required to view invoices for this partner.');
}


// ============================================================================
// 🗄️ FETCH PARTNER DETAILS
// ============================================================================

/**
 * 📊 Retrieve full partner record from tblPartners.
 * Used for displaying the partner name in the header, determining the
 * current tier, and populating partner-specific context in the UI.
 *
 * @see _database/migrations/008_partner_api_keys.sql (tblPartners definition)
 */
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE partnerID = ?");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerDetails = $stmt->get_result()->fetch_assoc();


// ============================================================================
// 📊 FETCH INVOICE STATISTICS (Server-Side for Initial Load)
// ============================================================================

/**
 * 📈 Get invoice count statistics for the stats cards.
 * Queries tblInvoices with partnerID filter to retrieve:
 *   - Total invoice count
 *   - Paid invoices count
 *   - Outstanding invoices count (draft + issued + sent)
 *   - Overdue invoices count
 *
 * Uses a single query with conditional aggregation for efficiency.
 * @see _database/migrations/012_payment_expansion.sql (tblInvoices definition)
 */
$stmt = $db->prepare("
    SELECT
        COUNT(*) AS totalCount,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paidCount,
        SUM(CASE WHEN status IN ('draft', 'issued', 'sent') THEN 1 ELSE 0 END) AS outstandingCount,
        SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdueCount,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END), 0.00) AS totalPaid,
        COALESCE(SUM(CASE WHEN status IN ('draft', 'issued', 'sent', 'overdue') THEN total ELSE 0 END), 0.00) AS totalOutstanding
    FROM tblInvoices
    WHERE partnerID = ?
");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

/**
 * 🛡️ Default stats values to prevent null display issues.
 * Ensures all stat values are numeric even if the query returns no rows.
 */
$totalCount       = (int)($stats['totalCount'] ?? 0);
$paidCount        = (int)($stats['paidCount'] ?? 0);
$outstandingCount = (int)($stats['outstandingCount'] ?? 0);
$overdueCount     = (int)($stats['overdueCount'] ?? 0);
$totalPaid        = number_format((float)($stats['totalPaid'] ?? 0), 2);
$totalOutstanding = number_format((float)($stats['totalOutstanding'] ?? 0), 2);


// ============================================================================
// 🧾 FETCH INITIAL INVOICE DATA (Server-Side for First Page Load)
// ============================================================================

/**
 * 📋 Load the first page of invoices for the initial table render.
 * Subsequent pages and filtered results are loaded via AJAX.
 *
 * Joins tblUsers and tblPayments to get:
 *   - Customer name and email (from tblUsers via tblPayments.userID)
 *   - Payment context to determine invoice type (Level 1 vs Level 2)
 *
 * Ordered by most recent first with a limit for initial page load.
 */
$perPage = 25;
$stmt = $db->prepare("
    SELECT
        i.invoiceID,
        i.invoiceNumber,
        i.billedToName,
        i.billedToEmail,
        i.subtotal,
        i.discountAmount,
        i.taxAmount,
        i.total,
        i.currency,
        i.status,
        i.issuedAt,
        i.dueDate,
        i.paidAt,
        i.pdfPath,
        i.notes,
        i.lineItems,
        i.createdAt,
        i.userID,
        i.paymentID,
        COALESCE(p.paymentContext, 'signula_direct') AS paymentContext
    FROM tblInvoices i
    LEFT JOIN tblPayments p ON i.paymentID = p.paymentID
    WHERE i.partnerID = ?
    ORDER BY i.createdAt DESC
    LIMIT ?
");
$stmt->bind_param('ii', $selectedPartnerID, $perPage);
$stmt->execute();
$invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/**
 * 🏷️ Helper: Determine invoice type label and badge class from payment context.
 * Level 1 (signula_direct) = "SIGNula Bill" — charges FROM SIGNula TO the partner
 * Level 2 (partner_own_keys / partner_signula_keys) = "Customer Payment"
 *
 * @param string $paymentContext The payment context from tblPayments
 * @return array ['label' => string, 'class' => string]
 */
function getInvoiceTypeInfo(string $paymentContext): array
{
    if ($paymentContext === 'signula_direct') {
        return ['label' => 'SIGNula Bill', 'class' => 'bg-info text-dark'];
    }
    return ['label' => 'Customer Payment', 'class' => 'bg-warning text-dark'];
}

/**
 * 🏷️ Helper: Map invoice status to Bootstrap badge CSS class.
 *
 * @param string $status The invoice status from tblInvoices
 * @return string Bootstrap badge class name
 * @see https://getbootstrap.com/docs/5.3/components/badge/
 */
function getStatusBadgeClass(string $status): string
{
    return match ($status) {
        'draft'     => 'bg-secondary',
        'issued'    => 'bg-primary',
        'sent'      => 'bg-primary',
        'paid'      => 'bg-success',
        'void'      => 'bg-dark',
        'cancelled' => 'bg-dark',
        'overdue'   => 'bg-danger',
        default     => 'bg-secondary',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- 📋 Standard meta tags for responsive, UTF-8 HTML5 page -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Invoices - <?php echo htmlspecialchars($partner['companyName']); ?> - SIGNula</title>

    <!-- 🎨 Bootstrap 5.3.2 CSS from CDN with SRI integrity check -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/download/#cdn-via-jsdelivr -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- 🎯 FontAwesome 6.4.2 Icons from CDN with SRI integrity check -->
    <!-- @see https://fontawesome.com/docs/web/setup/host-yourself/webfonts -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <!-- 🎨 Page-specific styles -->
    <style>
        /* 🌐 Global background for admin pages */
        body {
            background-color: #f8f9fa;
        }

        /* 🟣 Gradient header matching partner admin design language */
        .invoices-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        /* 📊 Stats card styling with subtle shadow and hover effect */
        .stat-card {
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* 🔘 Filter section styling */
        .filter-section {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        /* 📄 Invoice table responsive container */
        .invoice-table-card {
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        /* 🖱️ Clickable table rows with hover feedback */
        .table-hover tbody tr {
            cursor: pointer;
            transition: background-color 0.15s ease;
        }

        /* 📋 Invoice modal: scrollable body for long invoices */
        .invoice-preview-body {
            max-height: 70vh;
            overflow-y: auto;
        }

        /* 🏷️ Type badge pill styling */
        .type-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
        }

        /* 📐 Partner selector in header (shared with other admin pages) */
        .partner-selector {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            border-radius: 5px;
            padding: 0.5rem 1rem;
        }
        .partner-selector option {
            color: #333;
        }

        /* 💰 Amount column right-aligned for readability */
        .text-amount {
            font-family: 'Courier New', Courier, monospace;
            font-weight: 600;
        }

        /* 🔄 Loading spinner overlay for AJAX operations */
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

        /* 📱 Responsive: collapse filter row on small screens */
        @media (max-width: 768px) {
            .filter-section .row > div {
                margin-bottom: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">

        <!-- ================================================================ -->
        <!-- 📋 PAGE HEADER with gradient background and partner context      -->
        <!-- ================================================================ -->
        <div class="invoices-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <!-- 🔙 Back navigation to partner admin dashboard -->
                    <a href="index.php?partner=<?php echo $selectedPartnerID; ?>"
                       class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                        <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
                    </a>
                    <h1><i class="fas fa-file-invoice-dollar me-2"></i>Invoices</h1>

                    <?php if (count($memberships) > 1): ?>
                    <!-- 🏢 Partner selector dropdown (shown when user belongs to multiple orgs) -->
                    <div class="mt-3">
                        <label class="text-white opacity-75 mb-2">Select Organization:</label>
                        <select class="partner-selector form-select"
                                onchange="window.location.href='?partner='+this.value"
                                aria-label="Select partner organization">
                            <?php foreach ($memberships as $pid => $p): ?>
                            <option value="<?php echo (int)$pid; ?>"
                                    <?php echo $pid == $selectedPartnerID ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['companyName']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <!-- 🏢 Single partner name display -->
                    <p class="mb-0 opacity-75 mt-2"><?php echo htmlspecialchars($partner['companyName']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-end">
                    <!-- 🏷️ Current user role badge -->
                    <span class="badge bg-white text-primary fs-6 px-3 py-2">
                        <?php echo $partner['isRootAdmin'] ? '&#x1F451; Root Admin' : strtoupper($partner['role']); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- ⚠️ ALERT CONTAINER for dynamic AJAX feedback messages           -->
        <!-- ================================================================ -->
        <div id="alertContainer" role="alert" aria-live="polite"></div>

        <!-- ================================================================ -->
        <!-- 📊 INVOICE STATISTICS ROW                                        -->
        <!-- Four cards showing key invoice metrics at a glance              -->
        <!-- ================================================================ -->
        <div class="row mb-4">
            <!-- 📦 Total Invoices -->
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card stat-card border-primary">
                    <div class="card-body text-center">
                        <h3 class="text-primary mb-1" id="statTotal"><?php echo $totalCount; ?></h3>
                        <p class="text-muted mb-0"><i class="fas fa-file-invoice me-1"></i>Total Invoices</p>
                    </div>
                </div>
            </div>

            <!-- ✅ Paid Invoices -->
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card stat-card border-success">
                    <div class="card-body text-center">
                        <h3 class="text-success mb-1" id="statPaid"><?php echo $paidCount; ?></h3>
                        <p class="text-muted mb-0"><i class="fas fa-check-circle me-1"></i>Paid</p>
                        <small class="text-success">&pound;<?php echo $totalPaid; ?></small>
                    </div>
                </div>
            </div>

            <!-- ⏳ Outstanding Invoices -->
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card stat-card border-warning">
                    <div class="card-body text-center">
                        <h3 class="text-warning mb-1" id="statOutstanding"><?php echo $outstandingCount; ?></h3>
                        <p class="text-muted mb-0"><i class="fas fa-clock me-1"></i>Outstanding</p>
                        <small class="text-warning">&pound;<?php echo $totalOutstanding; ?></small>
                    </div>
                </div>
            </div>

            <!-- 🔴 Overdue Invoices -->
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card stat-card border-danger">
                    <div class="card-body text-center">
                        <h3 class="text-danger mb-1" id="statOverdue"><?php echo $overdueCount; ?></h3>
                        <p class="text-muted mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Overdue</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- 🔍 FILTER SECTION                                                -->
        <!-- Allows filtering by status, invoice type, and date range         -->
        <!-- ================================================================ -->
        <div class="filter-section">
            <div class="row align-items-end">
                <!-- 📊 Status filter dropdown -->
                <div class="col-md-3 col-sm-6 mb-2">
                    <label for="filterStatus" class="form-label fw-semibold">
                        <i class="fas fa-filter me-1"></i>Status
                    </label>
                    <select class="form-select" id="filterStatus" onchange="applyFilters()">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="issued">Issued</option>
                        <option value="sent">Sent</option>
                        <option value="paid">Paid</option>
                        <option value="overdue">Overdue</option>
                        <option value="void">Void</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <!-- 🏷️ Type filter (Level 1 / Level 2) -->
                <div class="col-md-3 col-sm-6 mb-2">
                    <label for="filterType" class="form-label fw-semibold">
                        <i class="fas fa-tags me-1"></i>Type
                    </label>
                    <select class="form-select" id="filterType" onchange="applyFilters()">
                        <option value="">All Types</option>
                        <option value="signula_direct">SIGNula Bill (Level 1)</option>
                        <option value="partner_payments">Customer Payment (Level 2)</option>
                    </select>
                </div>

                <!-- 📅 Date range: From -->
                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="filterDateFrom" class="form-label fw-semibold">
                        <i class="fas fa-calendar me-1"></i>From
                    </label>
                    <input type="date" class="form-control" id="filterDateFrom" onchange="applyFilters()">
                </div>

                <!-- 📅 Date range: To -->
                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="filterDateTo" class="form-label fw-semibold">
                        <i class="fas fa-calendar me-1"></i>To
                    </label>
                    <input type="date" class="form-control" id="filterDateTo" onchange="applyFilters()">
                </div>

                <!-- 🔄 Reset filters button -->
                <div class="col-md-2 col-sm-12 mb-2">
                    <button class="btn btn-outline-secondary w-100" onclick="resetFilters()">
                        <i class="fas fa-undo me-1"></i>Reset
                    </button>
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- 📄 INVOICES TABLE                                                -->
        <!-- Responsive table with status badges, type labels, and actions    -->
        <!-- ================================================================ -->
        <div class="card invoice-table-card position-relative">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Invoice List</h5>
                <small class="text-muted" id="invoiceCountLabel">
                    Showing <?php echo count($invoices); ?> of <?php echo $totalCount; ?> invoices
                </small>
            </div>

            <!-- 🔄 Loading overlay (hidden by default, shown during AJAX) -->
            <div class="loading-overlay d-none" id="loadingOverlay">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading invoices...</p>
                </div>
            </div>

            <div class="card-body p-0">
                <?php if (empty($invoices)): ?>
                <!-- 📭 Empty state when no invoices exist -->
                <div class="text-center py-5">
                    <i class="fas fa-file-invoice fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">No Invoices Found</h4>
                    <p class="text-muted">
                        There are no invoices for this partner organization yet.
                    </p>
                </div>
                <?php else: ?>
                <!-- 📋 Invoice data table -->
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="invoicesTable">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Invoice #</th>
                                <th scope="col">Type</th>
                                <th scope="col">Customer / User</th>
                                <th scope="col" class="text-end">Amount</th>
                                <th scope="col">Status</th>
                                <th scope="col">Issue Date</th>
                                <th scope="col">Due Date</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="invoicesTableBody">
                            <?php foreach ($invoices as $invoice): ?>
                            <?php
                                /**
                                 * 🏷️ Determine invoice type and status badge styling.
                                 * Level 1 = SIGNula Bill, Level 2 = Customer Payment.
                                 */
                                $typeInfo = getInvoiceTypeInfo($invoice['paymentContext']);
                                $statusClass = getStatusBadgeClass($invoice['status']);
                            ?>
                            <tr data-invoice-id="<?php echo (int)$invoice['invoiceID']; ?>"
                                data-payment-context="<?php echo htmlspecialchars($invoice['paymentContext']); ?>"
                                data-status="<?php echo htmlspecialchars($invoice['status']); ?>">

                                <!-- 🔢 Invoice Number -->
                                <td>
                                    <strong><?php echo htmlspecialchars($invoice['invoiceNumber']); ?></strong>
                                </td>

                                <!-- 🏷️ Invoice Type Badge (Level 1 / Level 2) -->
                                <td>
                                    <span class="badge type-badge <?php echo $typeInfo['class']; ?>">
                                        <?php echo htmlspecialchars($typeInfo['label']); ?>
                                    </span>
                                </td>

                                <!-- 👤 Billed To (Customer Name & Email) -->
                                <td>
                                    <div><?php echo htmlspecialchars($invoice['billedToName']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($invoice['billedToEmail']); ?></small>
                                </td>

                                <!-- 💰 Total Amount -->
                                <td class="text-end text-amount">
                                    <?php echo htmlspecialchars($invoice['currency']); ?>
                                    <?php echo number_format((float)$invoice['total'], 2); ?>
                                </td>

                                <!-- 📊 Status Badge -->
                                <td>
                                    <span class="badge <?php echo $statusClass; ?>">
                                        <?php echo ucfirst(htmlspecialchars($invoice['status'])); ?>
                                    </span>
                                </td>

                                <!-- 📅 Issue Date -->
                                <td>
                                    <?php if ($invoice['issuedAt']): ?>
                                        <?php echo date('M j, Y', strtotime($invoice['issuedAt'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not issued</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 📅 Due Date -->
                                <td>
                                    <?php if ($invoice['dueDate']): ?>
                                        <?php
                                            $dueTimestamp = strtotime($invoice['dueDate']);
                                            $isOverdue = ($dueTimestamp < time() && !in_array($invoice['status'], ['paid', 'void', 'cancelled']));
                                        ?>
                                        <span class="<?php echo $isOverdue ? 'text-danger fw-bold' : ''; ?>">
                                            <?php echo date('M j, Y', $dueTimestamp); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 🎬 Action Buttons -->
                                <td>
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Invoice actions">
                                        <!-- 👁️ View Invoice (opens modal) -->
                                        <button class="btn btn-outline-primary"
                                                onclick="event.stopPropagation(); viewInvoice(<?php echo (int)$invoice['invoiceID']; ?>)"
                                                title="View Invoice"
                                                aria-label="View invoice <?php echo htmlspecialchars($invoice['invoiceNumber']); ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <!-- 📥 Download PDF -->
                                        <?php if ($invoice['pdfPath']): ?>
                                        <a class="btn btn-outline-success"
                                           href="/invoices/download/?id=<?php echo (int)$invoice['invoiceID']; ?>&partner=<?php echo $selectedPartnerID; ?>"
                                           target="_blank"
                                           onclick="event.stopPropagation()"
                                           title="Download PDF"
                                           aria-label="Download PDF for invoice <?php echo htmlspecialchars($invoice['invoiceNumber']); ?>">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                        <?php else: ?>
                                        <button class="btn btn-outline-secondary" disabled
                                                title="PDF not available"
                                                aria-label="PDF not available">
                                            <i class="fas fa-file-pdf"></i>
                                        </button>
                                        <?php endif; ?>

                                        <!-- 📧 Send / Resend Email -->
                                        <button class="btn btn-outline-info"
                                                onclick="event.stopPropagation(); sendInvoiceEmail(<?php echo (int)$invoice['invoiceID']; ?>, '<?php echo htmlspecialchars($invoice['invoiceNumber'], ENT_QUOTES); ?>')"
                                                title="Send/Resend Invoice Email"
                                                aria-label="Send invoice <?php echo htmlspecialchars($invoice['invoiceNumber']); ?> by email">
                                            <i class="fas fa-envelope"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($totalCount > $perPage): ?>
            <!-- 📖 Pagination footer (shown when there are more invoices than per-page limit) -->
            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    Page <span id="currentPage">1</span> of <span id="totalPages"><?php echo ceil($totalCount / $perPage); ?></span>
                </small>
                <nav aria-label="Invoice pagination">
                    <ul class="pagination pagination-sm mb-0" id="paginationControls">
                        <li class="page-item disabled" id="prevPage">
                            <a class="page-link" href="#" onclick="changePage(-1); return false;">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        <li class="page-item" id="nextPage">
                            <a class="page-link" href="#" onclick="changePage(1); return false;">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>

        <!-- ================================================================ -->
        <!-- ℹ️ HELP SECTION                                                  -->
        <!-- ================================================================ -->
        <div class="card mt-4">
            <div class="card-body">
                <h6 class="mb-3"><i class="fas fa-question-circle me-2"></i>Invoice Types</h6>
                <div class="row">
                    <div class="col-md-6">
                        <h6><span class="badge bg-info text-dark me-1">SIGNula Bill</span> (Level 1)</h6>
                        <p class="text-muted small">
                            Charges from SIGNula to your organization for platform usage,
                            subscription fees, and service charges.
                        </p>
                    </div>
                    <div class="col-md-6">
                        <h6><span class="badge bg-warning text-dark me-1">Customer Payment</span> (Level 2)</h6>
                        <p class="text-muted small">
                            Payments received from your customers through SIGNula's payment
                            processing. Includes service fee deductions.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.container-fluid -->


    <!-- ==================================================================== -->
    <!-- 👁️ VIEW INVOICE MODAL                                                -->
    <!-- Displays a rendered HTML preview of the selected invoice              -->
    <!-- @see https://getbootstrap.com/docs/5.3/components/modal/              -->
    <!-- ==================================================================== -->
    <div class="modal fade" id="viewInvoiceModal" tabindex="-1" aria-labelledby="viewInvoiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewInvoiceModalLabel">
                        <i class="fas fa-file-invoice me-2"></i>Invoice Preview
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body invoice-preview-body" id="invoicePreviewBody">
                    <!-- 🔄 Loading state shown while invoice is being fetched -->
                    <div class="text-center py-5" id="invoicePreviewLoading">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading invoice...</span>
                        </div>
                        <p class="mt-2 text-muted">Loading invoice details...</p>
                    </div>

                    <!-- 📄 Invoice content rendered here by JavaScript -->
                    <div id="invoicePreviewContent" class="d-none"></div>
                </div>
                <div class="modal-footer">
                    <!-- 📥 Download PDF button in modal -->
                    <a href="#" class="btn btn-success" id="modalDownloadPDF" target="_blank">
                        <i class="fas fa-file-pdf me-1"></i>Download PDF
                    </a>
                    <!-- 📧 Send Email button in modal -->
                    <button class="btn btn-info text-white" id="modalSendEmail" onclick="sendCurrentInvoiceEmail()">
                        <i class="fas fa-envelope me-1"></i>Send Email
                    </button>
                    <!-- ❌ Close button -->
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ==================================================================== -->
    <!-- 📧 CONFIRM SEND EMAIL MODAL                                          -->
    <!-- Confirmation dialog before sending/resending invoice email            -->
    <!-- ==================================================================== -->
    <div class="modal fade" id="sendEmailModal" tabindex="-1" aria-labelledby="sendEmailModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sendEmailModalLabel">
                        <i class="fas fa-envelope me-2"></i>Send Invoice Email
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>
                        Are you sure you want to send (or resend) invoice
                        <strong id="sendEmailInvoiceNumber"></strong> by email?
                    </p>
                    <p class="text-muted small">
                        <i class="fas fa-info-circle me-1"></i>
                        The invoice will be emailed to the billing contact on record.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmSendEmailBtn" onclick="confirmSendEmail()">
                        <i class="fas fa-paper-plane me-1"></i>Send Email
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ==================================================================== -->
    <!-- 📦 JAVASCRIPT: Bootstrap 5.3.2 Bundle (includes Popper.js)           -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/download/     -->
    <!-- ==================================================================== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- 🔐 CSRF Token for secure AJAX requests -->
    <!-- @see SecurityUtils::generateCSRFToken() -->
    <script>const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';</script>

    <script>
        // ====================================================================
        // 🔧 CONFIGURATION & STATE
        // ====================================================================

        /** @type {number} Currently selected partner ID */
        const partnerID = <?php echo $selectedPartnerID; ?>;

        /** @type {number} Current pagination page (1-based) */
        let currentPage = 1;

        /** @type {number} Total pages available */
        let totalPages = <?php echo ceil($totalCount / $perPage); ?>;

        /** @type {number|null} Currently viewed invoice ID (for modal actions) */
        let currentInvoiceID = null;

        /** @type {string|null} Currently viewed invoice number (for email confirm) */
        let currentInvoiceNumber = null;

        /** @type {number|null} Invoice ID pending email send */
        let pendingSendEmailInvoiceID = null;

        /** @type {string|null} Invoice number pending email send */
        let pendingSendEmailInvoiceNumber = null;

        /** @type {number} Items per page for AJAX pagination */
        const perPage = <?php echo $perPage; ?>;

        // 📦 Bootstrap modal instances
        const viewInvoiceModal = new bootstrap.Modal(document.getElementById('viewInvoiceModal'));
        const sendEmailModal = new bootstrap.Modal(document.getElementById('sendEmailModal'));


        // ====================================================================
        // 🔔 ALERT SYSTEM
        // ====================================================================

        /**
         * 📢 Display a dismissible alert message.
         *
         * Creates a Bootstrap alert element, appends it to the alert container,
         * and auto-removes it after 5 seconds.
         *
         * @param {string} message - The message text to display
         * @param {string} [type='info'] - Bootstrap alert type (success, danger, warning, info)
         * @see https://getbootstrap.com/docs/5.3/components/alerts/
         */
        function showAlert(message, type = 'info') {
            const alertEl = document.createElement('div');
            alertEl.className = `alert alert-${type} alert-dismissible fade show`;
            alertEl.setAttribute('role', 'alert');
            alertEl.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            document.getElementById('alertContainer').appendChild(alertEl);

            // ⏰ Auto-dismiss after 5 seconds
            setTimeout(() => {
                if (alertEl.parentNode) {
                    alertEl.remove();
                }
            }, 5000);
        }


        // ====================================================================
        // 🔍 FILTER FUNCTIONS
        // ====================================================================

        /**
         * 🔍 Apply filters and reload invoice data via AJAX.
         *
         * Reads filter values from the DOM, constructs query parameters,
         * and fetches filtered invoice data from the API endpoint.
         * The server-side API validates partner access.
         */
        async function applyFilters() {
            const status   = document.getElementById('filterStatus').value;
            const type     = document.getElementById('filterType').value;
            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo   = document.getElementById('filterDateTo').value;

            // 🔄 Reset to page 1 when filters change
            currentPage = 1;

            // 📡 Build query parameters for the API call
            const params = new URLSearchParams({
                action: 'partner_invoices',
                partnerID: partnerID,
                page: currentPage,
                perPage: perPage
            });

            // 📋 Add optional filter parameters
            if (status)   { params.append('status', status); }
            if (type)     { params.append('type', type); }
            if (dateFrom) { params.append('dateFrom', dateFrom); }
            if (dateTo)   { params.append('dateTo', dateTo); }

            await fetchInvoices(params);
        }

        /**
         * 🔄 Reset all filters to default state and reload unfiltered data.
         */
        function resetFilters() {
            document.getElementById('filterStatus').value   = '';
            document.getElementById('filterType').value     = '';
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value   = '';
            applyFilters();
        }


        // ====================================================================
        // 📖 PAGINATION
        // ====================================================================

        /**
         * 📖 Navigate to the next/previous page of invoices.
         *
         * @param {number} direction - +1 for next page, -1 for previous page
         */
        function changePage(direction) {
            const newPage = currentPage + direction;

            // 🛡️ Bounds checking
            if (newPage < 1 || newPage > totalPages) {
                return;
            }

            currentPage = newPage;

            // 📡 Rebuild the current filter parameters with the new page
            const status   = document.getElementById('filterStatus').value;
            const type     = document.getElementById('filterType').value;
            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo   = document.getElementById('filterDateTo').value;

            const params = new URLSearchParams({
                action: 'partner_invoices',
                partnerID: partnerID,
                page: currentPage,
                perPage: perPage
            });

            if (status)   { params.append('status', status); }
            if (type)     { params.append('type', type); }
            if (dateFrom) { params.append('dateFrom', dateFrom); }
            if (dateTo)   { params.append('dateTo', dateTo); }

            fetchInvoices(params);
        }

        /**
         * 📖 Update pagination controls to reflect current state.
         *
         * @param {number} page - Current page number
         * @param {number} pages - Total number of pages
         */
        function updatePagination(page, pages) {
            currentPage = page;
            totalPages = pages;

            const currentPageEl = document.getElementById('currentPage');
            const totalPagesEl  = document.getElementById('totalPages');
            const prevPageEl    = document.getElementById('prevPage');
            const nextPageEl    = document.getElementById('nextPage');

            if (currentPageEl) { currentPageEl.textContent = page; }
            if (totalPagesEl)  { totalPagesEl.textContent = pages; }
            if (prevPageEl)    { prevPageEl.classList.toggle('disabled', page <= 1); }
            if (nextPageEl)    { nextPageEl.classList.toggle('disabled', page >= pages); }
        }


        // ====================================================================
        // 📡 AJAX DATA FETCHING
        // ====================================================================

        /**
         * 📡 Fetch invoice data from the super admin API with partner filter.
         *
         * Makes a GET request to /admin/api/invoice-actions.php which validates
         * the partner access server-side. Updates the table body with the
         * returned invoice data.
         *
         * @param {URLSearchParams} params - Query parameters for the API request
         */
        async function fetchInvoices(params) {
            // 🔄 Show loading overlay
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) { overlay.classList.remove('d-none'); }

            try {
                const response = await fetch(`/admin/api/invoice-actions.php?${params.toString()}`);
                const result = await response.json();

                if (result.success) {
                    renderInvoiceTable(result.data || []);
                    updatePagination(result.currentPage || 1, result.pages || 1);

                    // 📊 Update count label
                    const countLabel = document.getElementById('invoiceCountLabel');
                    if (countLabel) {
                        countLabel.textContent = `Showing ${(result.data || []).length} of ${result.total || 0} invoices`;
                    }
                } else {
                    showAlert(result.message || 'Failed to load invoices.', 'danger');
                }
            } catch (error) {
                // 🐛 Network or parsing error
                console.error('Error fetching invoices:', error);
                showAlert('Unable to load invoices. Please check your connection and try again.', 'danger');
            } finally {
                // 🔄 Hide loading overlay
                if (overlay) { overlay.classList.add('d-none'); }
            }
        }


        // ====================================================================
        // 🖌️ TABLE RENDERING
        // ====================================================================

        /**
         * 🖌️ Render the invoice table body with AJAX-fetched data.
         *
         * Replaces the inner HTML of the table body with rows generated
         * from the provided invoice data array.
         *
         * @param {Array<Object>} invoices - Array of invoice objects from the API
         */
        function renderInvoiceTable(invoices) {
            const tbody = document.getElementById('invoicesTableBody');
            if (!tbody) { return; }

            if (invoices.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center py-4">
                            <i class="fas fa-search fa-2x text-muted mb-2"></i>
                            <p class="text-muted mb-0">No invoices match the selected filters.</p>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = invoices.map(inv => {
                // 🏷️ Determine invoice type label and badge class
                const isLevel1 = (inv.paymentContext === 'signula_direct' || !inv.paymentContext);
                const typeLabel = isLevel1 ? 'SIGNula Bill' : 'Customer Payment';
                const typeClass = isLevel1 ? 'bg-info text-dark' : 'bg-warning text-dark';

                // 📊 Map status to badge class
                const statusMap = {
                    'draft': 'bg-secondary', 'issued': 'bg-primary', 'sent': 'bg-primary',
                    'paid': 'bg-success', 'void': 'bg-dark', 'cancelled': 'bg-dark',
                    'overdue': 'bg-danger'
                };
                const statusClass = statusMap[inv.status] || 'bg-secondary';

                // 📅 Format dates
                const issueDateStr = inv.issuedAt
                    ? new Date(inv.issuedAt).toLocaleDateString('en-GB', { month: 'short', day: 'numeric', year: 'numeric' })
                    : '<span class="text-muted">Not issued</span>';

                const dueDateStr = inv.dueDate
                    ? new Date(inv.dueDate).toLocaleDateString('en-GB', { month: 'short', day: 'numeric', year: 'numeric' })
                    : '<span class="text-muted">N/A</span>';

                // 🔴 Check if overdue
                const isOverdue = inv.dueDate && new Date(inv.dueDate) < new Date() && !['paid', 'void', 'cancelled'].includes(inv.status);
                const dueDateClass = isOverdue ? 'text-danger fw-bold' : '';

                // 📥 PDF download button
                const pdfBtn = inv.pdfPath
                    ? `<a class="btn btn-outline-success" href="/invoices/download/?id=${inv.invoiceID}&partner=${partnerID}" target="_blank" onclick="event.stopPropagation()" title="Download PDF"><i class="fas fa-file-pdf"></i></a>`
                    : `<button class="btn btn-outline-secondary" disabled title="PDF not available"><i class="fas fa-file-pdf"></i></button>`;

                // 💰 Format amount
                const amount = parseFloat(inv.total || 0).toFixed(2);
                const escapedNumber = escapeHtml(inv.invoiceNumber || '');

                return `
                    <tr data-invoice-id="${inv.invoiceID}" data-payment-context="${escapeHtml(inv.paymentContext || '')}" data-status="${escapeHtml(inv.status || '')}">
                        <td><strong>${escapedNumber}</strong></td>
                        <td><span class="badge type-badge ${typeClass}">${typeLabel}</span></td>
                        <td>
                            <div>${escapeHtml(inv.billedToName || '')}</div>
                            <small class="text-muted">${escapeHtml(inv.billedToEmail || '')}</small>
                        </td>
                        <td class="text-end text-amount">${escapeHtml(inv.currency || 'GBP')} ${amount}</td>
                        <td><span class="badge ${statusClass}">${capitalize(inv.status || 'unknown')}</span></td>
                        <td>${issueDateStr}</td>
                        <td><span class="${dueDateClass}">${dueDateStr}</span></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" onclick="event.stopPropagation(); viewInvoice(${inv.invoiceID})" title="View Invoice"><i class="fas fa-eye"></i></button>
                                ${pdfBtn}
                                <button class="btn btn-outline-info" onclick="event.stopPropagation(); sendInvoiceEmail(${inv.invoiceID}, '${escapedNumber}')" title="Send Email"><i class="fas fa-envelope"></i></button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }


        // ====================================================================
        // 👁️ VIEW INVOICE (Modal Preview)
        // ====================================================================

        /**
         * 👁️ Open the invoice preview modal and load invoice details via AJAX.
         *
         * Fetches the full invoice data including line items from the API,
         * then renders an HTML invoice preview inside the modal body.
         *
         * @param {number} invoiceID - The ID of the invoice to preview
         */
        async function viewInvoice(invoiceID) {
            // 📋 Store current invoice ID for modal action buttons
            currentInvoiceID = invoiceID;

            // 🔄 Show loading state in modal
            document.getElementById('invoicePreviewLoading').classList.remove('d-none');
            document.getElementById('invoicePreviewContent').classList.add('d-none');

            // 📥 Update modal PDF download link
            document.getElementById('modalDownloadPDF').href =
                `/invoices/download/?id=${invoiceID}&partner=${partnerID}`;

            // 📦 Show the modal
            viewInvoiceModal.show();

            try {
                // 📡 Fetch invoice details from the API
                const params = new URLSearchParams({
                    action: 'get_invoice',
                    invoiceID: invoiceID,
                    partnerID: partnerID
                });

                const response = await fetch(`/admin/api/invoice-actions.php?${params.toString()}`);
                const result = await response.json();

                if (result.success && result.data) {
                    renderInvoicePreview(result.data);
                    currentInvoiceNumber = result.data.invoiceNumber || '';
                } else {
                    document.getElementById('invoicePreviewContent').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-1"></i>
                            ${result.message || 'Failed to load invoice details.'}
                        </div>
                    `;
                    document.getElementById('invoicePreviewContent').classList.remove('d-none');
                }
            } catch (error) {
                // 🐛 Network error handling
                console.error('Error loading invoice:', error);
                document.getElementById('invoicePreviewContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        Unable to load invoice details. Please try again.
                    </div>
                `;
                document.getElementById('invoicePreviewContent').classList.remove('d-none');
            } finally {
                // 🔄 Hide loading spinner
                document.getElementById('invoicePreviewLoading').classList.add('d-none');
            }
        }

        /**
         * 🖌️ Render the HTML invoice preview inside the modal.
         *
         * Generates a formatted invoice display with:
         *   - Invoice header (number, date, status)
         *   - Billed from / billed to addresses
         *   - Line items table
         *   - Subtotal, discount, tax, and total breakdown
         *   - Notes section
         *
         * @param {Object} invoice - Full invoice object from the API
         */
        function renderInvoicePreview(invoice) {
            const contentEl = document.getElementById('invoicePreviewContent');

            // 📊 Status badge
            const statusMap = {
                'draft': 'bg-secondary', 'issued': 'bg-primary', 'sent': 'bg-primary',
                'paid': 'bg-success', 'void': 'bg-dark', 'cancelled': 'bg-dark',
                'overdue': 'bg-danger'
            };
            const statusClass = statusMap[invoice.status] || 'bg-secondary';

            // 📋 Parse line items from JSON
            let lineItems = [];
            try {
                lineItems = typeof invoice.lineItems === 'string'
                    ? JSON.parse(invoice.lineItems)
                    : (invoice.lineItems || []);
            } catch (e) {
                lineItems = [];
            }

            // 📅 Format dates
            const issuedDate = invoice.issuedAt
                ? new Date(invoice.issuedAt).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })
                : 'Not yet issued';
            const dueDate = invoice.dueDate
                ? new Date(invoice.dueDate).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })
                : 'N/A';
            const paidDate = invoice.paidAt
                ? new Date(invoice.paidAt).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })
                : null;

            // 🏠 Parse billing addresses
            let billedByAddress = {};
            let billedToAddress = {};
            try {
                billedByAddress = typeof invoice.billedByAddress === 'string'
                    ? JSON.parse(invoice.billedByAddress)
                    : (invoice.billedByAddress || {});
            } catch (e) { billedByAddress = {}; }
            try {
                billedToAddress = typeof invoice.billedToAddress === 'string'
                    ? JSON.parse(invoice.billedToAddress)
                    : (invoice.billedToAddress || {});
            } catch (e) { billedToAddress = {}; }

            // 🏗️ Build the HTML invoice preview
            contentEl.innerHTML = `
                <!-- 🧾 Invoice Header -->
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <h3 class="mb-1">Invoice ${escapeHtml(invoice.invoiceNumber || '')}</h3>
                        <span class="badge ${statusClass} fs-6">${capitalize(invoice.status || 'unknown')}</span>
                    </div>
                    <div class="text-end">
                        <p class="mb-1"><strong>Issued:</strong> ${issuedDate}</p>
                        <p class="mb-1"><strong>Due:</strong> ${dueDate}</p>
                        ${paidDate ? `<p class="mb-0 text-success"><strong>Paid:</strong> ${paidDate}</p>` : ''}
                    </div>
                </div>

                <hr>

                <!-- 🏢 Billing Parties -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-muted">Billed By</h6>
                        <p class="mb-1"><strong>${escapeHtml(invoice.billedByName || 'MWBM Partners Ltd')}</strong></p>
                        ${formatAddress(billedByAddress)}
                        ${invoice.billedByVATNumber ? `<p class="mb-0"><small>VAT: ${escapeHtml(invoice.billedByVATNumber)}</small></p>` : ''}
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted">Billed To</h6>
                        <p class="mb-1"><strong>${escapeHtml(invoice.billedToName || '')}</strong></p>
                        <p class="mb-1">${escapeHtml(invoice.billedToEmail || '')}</p>
                        ${formatAddress(billedToAddress)}
                        ${invoice.billedToVATNumber ? `<p class="mb-0"><small>VAT: ${escapeHtml(invoice.billedToVATNumber)}</small></p>` : ''}
                    </div>
                </div>

                <!-- 📋 Line Items Table -->
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Description</th>
                            <th class="text-center" style="width: 80px;">Qty</th>
                            <th class="text-end" style="width: 120px;">Unit Price</th>
                            <th class="text-end" style="width: 120px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${lineItems.map(item => `
                            <tr>
                                <td>${escapeHtml(item.description || '')}</td>
                                <td class="text-center">${item.quantity || 1}</td>
                                <td class="text-end">${escapeHtml(invoice.currency || 'GBP')} ${parseFloat(item.unitPrice || 0).toFixed(2)}</td>
                                <td class="text-end">${escapeHtml(invoice.currency || 'GBP')} ${parseFloat(item.amount || 0).toFixed(2)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Subtotal</strong></td>
                            <td class="text-end">${escapeHtml(invoice.currency || 'GBP')} ${parseFloat(invoice.subtotal || 0).toFixed(2)}</td>
                        </tr>
                        ${parseFloat(invoice.discountAmount || 0) > 0 ? `
                        <tr>
                            <td colspan="3" class="text-end text-success">Discount</td>
                            <td class="text-end text-success">-${escapeHtml(invoice.currency || 'GBP')} ${parseFloat(invoice.discountAmount).toFixed(2)}</td>
                        </tr>
                        ` : ''}
                        ${parseFloat(invoice.taxAmount || 0) > 0 ? `
                        <tr>
                            <td colspan="3" class="text-end">Tax (${parseFloat(invoice.taxRate || 0).toFixed(1)}%)</td>
                            <td class="text-end">${escapeHtml(invoice.currency || 'GBP')} ${parseFloat(invoice.taxAmount).toFixed(2)}</td>
                        </tr>
                        ` : ''}
                        <tr class="table-primary">
                            <td colspan="3" class="text-end"><strong>Total</strong></td>
                            <td class="text-end"><strong>${escapeHtml(invoice.currency || 'GBP')} ${parseFloat(invoice.total || 0).toFixed(2)}</strong></td>
                        </tr>
                    </tfoot>
                </table>

                <!-- 📝 Notes -->
                ${invoice.notes ? `
                <div class="mt-3 p-3 bg-light rounded">
                    <h6><i class="fas fa-sticky-note me-1"></i>Notes</h6>
                    <p class="mb-0 text-muted">${escapeHtml(invoice.notes)}</p>
                </div>
                ` : ''}
            `;

            // ✅ Show the rendered content
            contentEl.classList.remove('d-none');
        }


        // ====================================================================
        // 📧 SEND / RESEND INVOICE EMAIL
        // ====================================================================

        /**
         * 📧 Open the send email confirmation modal.
         *
         * @param {number} invoiceID - The invoice to send
         * @param {string} invoiceNumber - The invoice number for display
         */
        function sendInvoiceEmail(invoiceID, invoiceNumber) {
            pendingSendEmailInvoiceID = invoiceID;
            pendingSendEmailInvoiceNumber = invoiceNumber;

            document.getElementById('sendEmailInvoiceNumber').textContent = invoiceNumber;
            sendEmailModal.show();
        }

        /**
         * 📧 Send email for the invoice currently open in the view modal.
         * Uses the currentInvoiceID and currentInvoiceNumber from the modal state.
         */
        function sendCurrentInvoiceEmail() {
            if (currentInvoiceID && currentInvoiceNumber) {
                viewInvoiceModal.hide();
                sendInvoiceEmail(currentInvoiceID, currentInvoiceNumber);
            }
        }

        /**
         * ✅ Confirm and execute the invoice email send via AJAX POST.
         *
         * Sends a POST request to the invoice-actions API with the
         * send_invoice_email action. Requires CSRF token for security.
         */
        async function confirmSendEmail() {
            if (!pendingSendEmailInvoiceID) { return; }

            // 🔄 Disable button during request to prevent double-clicks
            const btn = document.getElementById('confirmSendEmailBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Sending...';

            try {
                const response = await fetch('/admin/api/invoice-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'send_invoice_email',
                        invoiceID: pendingSendEmailInvoiceID,
                        partnerID: partnerID,
                        csrf_token: csrfToken
                    })
                });

                const result = await response.json();

                // 📦 Close the modal
                sendEmailModal.hide();

                if (result.success) {
                    showAlert(
                        `<i class="fas fa-check-circle me-1"></i>Invoice ${escapeHtml(pendingSendEmailInvoiceNumber)} email sent successfully.`,
                        'success'
                    );
                } else {
                    showAlert(
                        `<i class="fas fa-exclamation-circle me-1"></i>${result.message || 'Failed to send invoice email.'}`,
                        'danger'
                    );
                }
            } catch (error) {
                // 🐛 Network error handling
                console.error('Error sending invoice email:', error);
                sendEmailModal.hide();
                showAlert(
                    '<i class="fas fa-exclamation-circle me-1"></i>Unable to send email. Please try again.',
                    'danger'
                );
            } finally {
                // 🔄 Re-enable button
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Send Email';

                // 🧹 Clear pending state
                pendingSendEmailInvoiceID = null;
                pendingSendEmailInvoiceNumber = null;
            }
        }


        // ====================================================================
        // 🛠️ UTILITY FUNCTIONS
        // ====================================================================

        /**
         * 🛡️ Escape HTML special characters to prevent XSS.
         *
         * @param {string} str - The string to escape
         * @returns {string} HTML-safe string
         */
        function escapeHtml(str) {
            if (!str) { return ''; }
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        /**
         * 🔤 Capitalize the first letter of a string.
         *
         * @param {string} str - The string to capitalize
         * @returns {string} Capitalized string
         */
        function capitalize(str) {
            if (!str) { return ''; }
            return str.charAt(0).toUpperCase() + str.slice(1);
        }

        /**
         * 🏠 Format a billing address object into HTML lines.
         *
         * @param {Object} address - Address object with line1, line2, city, state, postcode, country
         * @returns {string} Formatted HTML address lines
         */
        function formatAddress(address) {
            if (!address || Object.keys(address).length === 0) { return ''; }

            const lines = [];
            if (address.line1)    { lines.push(escapeHtml(address.line1)); }
            if (address.line2)    { lines.push(escapeHtml(address.line2)); }
            if (address.city)     { lines.push(escapeHtml(address.city)); }
            if (address.state)    { lines.push(escapeHtml(address.state)); }
            if (address.postcode) { lines.push(escapeHtml(address.postcode)); }
            if (address.country)  { lines.push(escapeHtml(address.country)); }

            return lines.length > 0
                ? `<p class="mb-1"><small>${lines.join('<br>')}</small></p>`
                : '';
        }
    </script>
</body>
</html>
