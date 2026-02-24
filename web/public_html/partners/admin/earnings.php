<?php
/**
 * ============================================================================
 * 💰 SIGNula - Partner Earnings & Payouts Dashboard
 * ============================================================================
 *
 * Purpose: Partner administration page for viewing earnings, fee breakdowns,
 *          remittance (payout) history, monthly revenue charts, and managing
 *          payout preferences (email, method, threshold).
 *
 * PHP Version: 8.3+ (targeting 8.4 for longevity)
 *
 * Features:
 *   - 📊 Summary cards: Total Revenue, Total Fees, Net Earnings, Pending Payout
 *   - 📈 Revenue bar chart: Last 6 months of revenue (pure HTML/CSS, no JS libs)
 *   - 💸 Fee breakdown table: Per-transaction fee details with status badges
 *   - 🏦 Remittance history: Payout records with method, status, transaction ref
 *   - ⚙️ Payout preferences: Editable payout email, method, threshold
 *   - 📅 Date range filter: Filters fee transactions and chart data
 *   - 📄 Pagination: For fee transactions and remittance history tables
 *
 * Access Control:
 *   - Requires finance role or higher (finance, admin, root_admin, super_admin)
 *   - Uses AccessControl::hasPartnerRole() for permission checks
 *
 * Backend Dependencies:
 *   - PartnerPaymentService::getPartnerEarningsSummary($partnerID)
 *   - ServiceFeeManager::getPartnerFeeTransactions($partnerID, $limit, $offset, $filters)
 *   - ServiceFeeManager::getPartnerRemittances($partnerID, $limit, $offset)
 *   - ServiceFeeManager::getEarningsReport($partnerID, $periodStart, $periodEnd)
 *
 * @see /web/private_html/payments/PartnerPaymentService.php  Earnings summary
 * @see /web/private_html/payments/ServiceFeeManager.php      Fee transactions & remittances
 * @see /web/_backend/AccessControl.php                        Role-based access control
 *
 * @package    SIGNula
 * @subpackage Partners\Admin
 * @version    1.0.0
 * @since      2.4.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 🔧 BOOTSTRAP: Load core SIGNula config, database, session & access control
// ============================================================================

// ⚙️ Load the main SIGNula configuration (defines SIGNULA_INIT, constants, autoloader)
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton for MySQLi prepared statement queries
// @see https://www.php.net/manual/en/book.mysqli.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🔐 Session management for authentication state tracking
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🛡️ Role-based access control for partner permission checks
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 💰 Partner payment service for earnings summary data
// @see /web/private_html/payments/PartnerPaymentService.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'PartnerPaymentService.php';

// 💸 Service fee manager for fee transactions, remittances, and earnings reports
// @see /web/private_html/payments/ServiceFeeManager.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'ServiceFeeManager.php';

// ============================================================================
// 🏗️ INITIALISE CORE SERVICES
// ============================================================================

// 🗄️ Get the Database singleton instance (MySQLi connection)
$db = Database::getInstance();

// 🔐 Create session manager for login state checks
$sessionManager = new SessionManager($db);

// 🛡️ Create access control instance for role/permission verification
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
// 🏢 PARTNER SELECTION & MEMBERSHIP VERIFICATION
// ============================================================================

// 📋 Get all partner organisations the current user belongs to
// Returns associative array keyed by partnerID with role, companyName, isRootAdmin
$memberships = $accessControl->getPartnerMemberships();

// 🚫 If user has no partner memberships, redirect to partner registration
if (empty($memberships)) {
    header('Location: /partners/register.php');
    exit;
}

// 🔄 Default to first partner or use the partner selected via GET parameter
// @see https://www.php.net/manual/en/function.array-key-first.php
$selectedPartnerID = $_GET['partner'] ?? array_key_first($memberships);
$partner = $memberships[$selectedPartnerID] ?? reset($memberships);

// ============================================================================
// 🛡️ ACCESS CONTROL: Finance Role or Higher Required
// ============================================================================

// 💼 Verify the user has at least 'finance' role for this partner
// Finance role hierarchy: finance(20) < support(30) < developer(40) < admin(60) < root-admin(80)
// @see /web/_backend/AccessControl.php::hasPartnerRole()
if (!$accessControl->hasPartnerRole($selectedPartnerID, 'finance')) {
    http_response_code(403);
    die('Finance access or higher required to view earnings');
}

// ============================================================================
// 🗄️ FETCH PARTNER DETAILS
// ============================================================================

// 📋 Query the tblPartners table for full partner record (includes payout preferences)
// Uses MySQLi prepared statement for SQL injection protection
// @see https://www.php.net/manual/en/mysqli.prepare.php
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE partnerID = ?");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerDetails = $stmt->get_result()->fetch_assoc();

// ============================================================================
// 📊 FETCH EARNINGS SUMMARY DATA
// ============================================================================

// 💰 Get aggregated earnings summary (total gross, fees, net, pending remittances)
// @see PartnerPaymentService::getPartnerEarningsSummary()
$earningsSummary = PartnerPaymentService::getPartnerEarningsSummary((int) $selectedPartnerID);

// ============================================================================
// 📅 DATE RANGE FILTER HANDLING
// ============================================================================

// 📆 Parse date range from GET parameters for filtering fee transactions and chart
// Default: last 6 months if no dates provided
// @see https://www.php.net/manual/en/function.date.php
$dateFrom = $_GET['dateFrom'] ?? date('Y-m-d', strtotime('-6 months'));
$dateTo   = $_GET['dateTo']   ?? date('Y-m-d');

// 🔒 Sanitise date inputs to prevent SQL injection (format validation)
// @see https://www.php.net/manual/en/function.preg-match.php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-d', strtotime('-6 months'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-d');
}

// ============================================================================
// 💸 FETCH FEE TRANSACTIONS (Paginated)
// ============================================================================

// 📄 Pagination parameters for the fee transactions table
// @see https://www.php.net/manual/en/function.max.php
$feePage  = max(1, (int) ($_GET['feePage'] ?? 1));
$feeLimit = 15; // 📋 Number of fee transactions per page
$feeOffset = ($feePage - 1) * $feeLimit;

// 📊 Fetch paginated fee transactions with date range filters
// @see ServiceFeeManager::getPartnerFeeTransactions()
$feeResult = ServiceFeeManager::getPartnerFeeTransactions(
    (int) $selectedPartnerID,
    $feeLimit,
    $feeOffset,
    [
        'dateFrom' => $dateFrom,
        'dateTo'   => $dateTo,
    ]
);
$feeTransactions = $feeResult['transactions'] ?? [];
$feeTotalRecords = $feeResult['total'] ?? 0;
$feeTotalPages   = max(1, (int) ceil($feeTotalRecords / $feeLimit));

// ============================================================================
// 🏦 FETCH REMITTANCE HISTORY (Paginated)
// ============================================================================

// 📄 Pagination parameters for the remittance history table
$remitPage  = max(1, (int) ($_GET['remitPage'] ?? 1));
$remitLimit = 10; // 📋 Number of remittance records per page
$remitOffset = ($remitPage - 1) * $remitLimit;

// 💸 Fetch paginated remittance (payout) records
// @see ServiceFeeManager::getPartnerRemittances()
$remitResult = ServiceFeeManager::getPartnerRemittances(
    (int) $selectedPartnerID,
    $remitLimit,
    $remitOffset
);
$remittances       = $remitResult['remittances'] ?? [];
$remitTotalRecords = $remitResult['total'] ?? 0;
$remitTotalPages   = max(1, (int) ceil($remitTotalRecords / $remitLimit));

// ============================================================================
// 📈 FETCH MONTHLY REVENUE DATA FOR CHART
// ============================================================================

// 📊 Get earnings report with monthly breakdown for the bar chart
// @see ServiceFeeManager::getEarningsReport()
$earningsReport = ServiceFeeManager::getEarningsReport(
    (int) $selectedPartnerID,
    $dateFrom,
    $dateTo
);
$monthlyBreakdown = $earningsReport['monthlyBreakdown'] ?? [];

// 📊 Calculate the maximum monthly gross amount for chart scaling
// @see https://www.php.net/manual/en/function.array-column.php
$maxMonthlyGross = 0;
if (!empty($monthlyBreakdown)) {
    $maxMonthlyGross = max(array_column($monthlyBreakdown, 'grossAmount'));
}
// 🛡️ Prevent division by zero if no revenue data exists
if ($maxMonthlyGross <= 0) {
    $maxMonthlyGross = 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- 🔤 Character encoding and viewport for responsive design -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Earnings &amp; Payouts - <?php echo htmlspecialchars($partner['companyName']); ?></title>

    <!-- 🎨 Bootstrap 5.3.2 CSS (CDN with SRI integrity hash) -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- 🎨 Font Awesome 6.4.2 Icons (CDN with SRI integrity hash) -->
    <!-- @see https://fontawesome.com/docs -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <!-- 🎨 Custom Styles for Earnings Dashboard -->
    <style>
        /* 🖌️ Base background colour for a clean, professional look */
        body {
            background-color: #f8f9fa;
        }

        /* 💰 Page header with green-teal gradient (finance/money theme) */
        .earnings-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        /* 📊 Summary statistic cards with subtle shadow and hover effect */
        .stat-card {
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* 📈 Revenue chart container styling */
        .chart-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        /* 📊 Bar chart layout using CSS flexbox */
        .bar-chart {
            display: flex;
            align-items: flex-end;
            justify-content: space-around;
            height: 250px;
            padding: 1rem 0;
            border-bottom: 2px solid #e0e0e0;
        }

        /* 📊 Individual bar column styling */
        .bar-column {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            max-width: 120px;
            position: relative;
        }

        /* 📊 The actual coloured bar with smooth transition animation */
        .bar {
            width: 40px;
            background: linear-gradient(180deg, #11998e, #38ef7d);
            border-radius: 6px 6px 0 0;
            transition: height 0.6s ease;
            min-height: 4px;
            position: relative;
            cursor: pointer;
        }
        .bar:hover {
            opacity: 0.85;
        }

        /* 💰 Value label shown above each bar */
        .bar-value {
            font-size: 0.75rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
            white-space: nowrap;
        }

        /* 📅 Month label shown below each bar */
        .bar-label {
            font-size: 0.8rem;
            color: #666;
            margin-top: 8px;
            text-align: center;
        }

        /* 📋 Section card styling for tables and preference forms */
        .section-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        /* 📋 Section header with subtle gradient background */
        .section-header {
            background: #f8f9fa;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 600;
        }

        /* 📋 Section body padding for table content */
        .section-body {
            padding: 1.5rem;
        }

        /* 🏷️ Status badge base styling */
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        /* ⚙️ Payout preferences form card */
        .preferences-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        /* 📅 Date filter form styling */
        .date-filter {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
        }

        /* 📄 Pagination wrapper alignment */
        .pagination-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            border-top: 1px solid #e0e0e0;
        }

        /* 📱 Responsive table wrapper for horizontal scrolling on small screens */
        .table-responsive {
            border-radius: 0;
        }

        /* 🏢 Partner selector dropdown (shown when user belongs to multiple partners) */
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

        /* 💼 Role badge styling (matches index.php pattern) */
        .role-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* 📊 Empty state styling for tables with no data */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">

        <!-- ================================================================ -->
        <!-- 💰 PAGE HEADER: Earnings & Payouts title with partner context    -->
        <!-- ================================================================ -->
        <div class="earnings-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <!-- 🔙 Back navigation link to the partner admin dashboard -->
                    <a href="index.php?partner=<?php echo (int) $selectedPartnerID; ?>"
                       class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                        <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
                    </a>
                    <h1><i class="fas fa-wallet me-2"></i>Earnings &amp; Payouts</h1>

                    <!-- 🏢 Partner Selector: Show dropdown if user has multiple partner memberships -->
                    <?php if (count($memberships) > 1): ?>
                    <div class="mt-3">
                        <label class="text-white opacity-75 mb-2">Select Organization:</label>
                        <select class="partner-selector form-select"
                                onchange="window.location.href='?partner='+this.value"
                                aria-label="Select partner organization">
                            <?php foreach ($memberships as $pid => $p): ?>
                            <option value="<?php echo (int) $pid; ?>"
                                    <?php echo ($pid == $selectedPartnerID) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['companyName']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <!-- 🏢 Single partner: Show company name as static text -->
                    <p class="mb-0 opacity-75 mt-2"><?php echo htmlspecialchars($partner['companyName']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-end">
                    <!-- 💼 Display the user's role badge for this partner -->
                    <span class="role-badge bg-white text-success">
                        <?php echo $partner['isRootAdmin'] ? '&#x1F451; Root Admin' : strtoupper($partner['role']); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- 🔔 ALERT CONTAINER: Dynamic alerts from AJAX operations          -->
        <!-- ================================================================ -->
        <div id="alertContainer"></div>

        <!-- ================================================================ -->
        <!-- 📊 SUMMARY CARDS ROW: Key financial metrics at a glance          -->
        <!-- ================================================================ -->
        <div class="row mb-4">
            <!-- 💰 Total Revenue Card -->
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="card stat-card border-primary h-100">
                    <div class="card-body text-center">
                        <div class="mb-2">
                            <i class="fas fa-chart-line fa-2x text-primary"></i>
                        </div>
                        <h3 class="text-primary mb-1">
                            &pound;<?php echo number_format($earningsSummary['totalGrossRevenue'], 2); ?>
                        </h3>
                        <p class="text-muted mb-0">Total Revenue</p>
                        <small class="text-muted">
                            <?php echo (int) $earningsSummary['transactionCount']; ?> transactions
                        </small>
                    </div>
                </div>
            </div>

            <!-- 💸 Total Fees Card -->
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="card stat-card border-danger h-100">
                    <div class="card-body text-center">
                        <div class="mb-2">
                            <i class="fas fa-receipt fa-2x text-danger"></i>
                        </div>
                        <h3 class="text-danger mb-1">
                            &pound;<?php echo number_format($earningsSummary['totalFees'], 2); ?>
                        </h3>
                        <p class="text-muted mb-0">Total Fees</p>
                        <small class="text-muted">Service charges deducted</small>
                    </div>
                </div>
            </div>

            <!-- 💵 Net Earnings Card -->
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="card stat-card border-success h-100">
                    <div class="card-body text-center">
                        <div class="mb-2">
                            <i class="fas fa-piggy-bank fa-2x text-success"></i>
                        </div>
                        <h3 class="text-success mb-1">
                            &pound;<?php echo number_format($earningsSummary['totalNetEarnings'], 2); ?>
                        </h3>
                        <p class="text-muted mb-0">Net Earnings</p>
                        <small class="text-muted">After fees</small>
                    </div>
                </div>
            </div>

            <!-- ⏳ Pending Payout Card -->
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="card stat-card border-warning h-100">
                    <div class="card-body text-center">
                        <div class="mb-2">
                            <i class="fas fa-clock fa-2x text-warning"></i>
                        </div>
                        <h3 class="text-warning mb-1">
                            &pound;<?php echo number_format($earningsSummary['pendingRemittances'], 2); ?>
                        </h3>
                        <p class="text-muted mb-0">Pending Payout</p>
                        <small class="text-muted">Awaiting processing</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- 📅 DATE RANGE FILTER: Apply to fee transactions and chart        -->
        <!-- ================================================================ -->
        <div class="date-filter">
            <form method="GET" action="" class="row align-items-end">
                <!-- 🔒 Preserve the selected partner ID across form submissions -->
                <input type="hidden" name="partner" value="<?php echo (int) $selectedPartnerID; ?>">

                <!-- 📅 Start date input -->
                <div class="col-md-4 mb-2 mb-md-0">
                    <label for="dateFrom" class="form-label fw-bold">
                        <i class="fas fa-calendar-alt me-1"></i>From
                    </label>
                    <input type="date"
                           class="form-control"
                           id="dateFrom"
                           name="dateFrom"
                           value="<?php echo htmlspecialchars($dateFrom); ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>

                <!-- 📅 End date input -->
                <div class="col-md-4 mb-2 mb-md-0">
                    <label for="dateTo" class="form-label fw-bold">
                        <i class="fas fa-calendar-alt me-1"></i>To
                    </label>
                    <input type="date"
                           class="form-control"
                           id="dateTo"
                           name="dateTo"
                           value="<?php echo htmlspecialchars($dateTo); ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>

                <!-- 🔍 Filter action buttons -->
                <div class="col-md-4 mb-2 mb-md-0">
                    <label class="form-label d-block">&nbsp;</label>
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-filter me-1"></i>Apply Filter
                    </button>
                    <a href="?partner=<?php echo (int) $selectedPartnerID; ?>"
                       class="btn btn-outline-secondary">
                        <i class="fas fa-undo me-1"></i>Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- ================================================================ -->
        <!-- 📈 REVENUE CHART SECTION: Monthly revenue bar chart (HTML/CSS)   -->
        <!-- ================================================================ -->
        <div class="chart-container">
            <h5 class="mb-3">
                <i class="fas fa-chart-bar me-2 text-success"></i>Monthly Revenue
                <small class="text-muted ms-2">
                    (<?php echo htmlspecialchars($dateFrom); ?> to <?php echo htmlspecialchars($dateTo); ?>)
                </small>
            </h5>

            <?php if (!empty($monthlyBreakdown)): ?>
            <!-- 📊 Pure HTML/CSS bar chart — no JavaScript chart libraries needed -->
            <div class="bar-chart" role="img" aria-label="Monthly revenue bar chart">
                <?php foreach ($monthlyBreakdown as $month): ?>
                <?php
                    // 📊 Calculate bar height as a percentage of the maximum monthly gross
                    // Ensures the tallest bar fills ~90% of the chart height
                    $barHeightPercent = ($month['grossAmount'] / $maxMonthlyGross) * 90;

                    // 📅 Format the month label (e.g., "2026-01" becomes "Jan 2026")
                    // @see https://www.php.net/manual/en/function.date.php
                    $monthLabel = date('M Y', strtotime($month['month'] . '-01'));

                    // 💰 Format the gross amount for the bar value label
                    $formattedGross = '£' . number_format($month['grossAmount'], 0);
                ?>
                <div class="bar-column">
                    <!-- 💰 Value label above the bar -->
                    <span class="bar-value"><?php echo $formattedGross; ?></span>

                    <!-- 📊 The coloured bar element with calculated height -->
                    <div class="bar"
                         style="height: <?php echo max(4, $barHeightPercent); ?>%;"
                         title="<?php echo htmlspecialchars($monthLabel . ': £' . number_format($month['grossAmount'], 2) . ' gross, £' . number_format($month['feeAmount'], 2) . ' fees, £' . number_format($month['netAmount'], 2) . ' net'); ?>"
                         data-bs-toggle="tooltip"
                         data-bs-placement="top">
                    </div>

                    <!-- 📅 Month label below the bar -->
                    <span class="bar-label"><?php echo htmlspecialchars($monthLabel); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- 📊 Chart legend/summary row -->
            <div class="row mt-3 text-center">
                <div class="col-md-4">
                    <small class="text-muted">
                        <i class="fas fa-square text-primary me-1"></i>
                        Total Gross: <strong>&pound;<?php echo number_format($earningsReport['totalGross'], 2); ?></strong>
                    </small>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">
                        <i class="fas fa-square text-danger me-1"></i>
                        Total Fees: <strong>&pound;<?php echo number_format($earningsReport['totalFees'], 2); ?></strong>
                    </small>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">
                        <i class="fas fa-square text-success me-1"></i>
                        Total Net: <strong>&pound;<?php echo number_format($earningsReport['totalNet'], 2); ?></strong>
                    </small>
                </div>
            </div>

            <?php else: ?>
            <!-- 📊 Empty state when no revenue data exists for the selected period -->
            <div class="empty-state">
                <i class="fas fa-chart-bar d-block"></i>
                <h6>No Revenue Data</h6>
                <p class="text-muted">No revenue recorded for the selected date range.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ================================================================ -->
        <!-- 💸 FEE BREAKDOWN SECTION: Detailed fee transaction table         -->
        <!-- ================================================================ -->
        <div class="section-card">
            <!-- 📋 Section header -->
            <div class="section-header">
                <i class="fas fa-file-invoice-dollar me-2"></i>Fee Breakdown
                <span class="badge bg-secondary ms-2"><?php echo (int) $feeTotalRecords; ?> records</span>
            </div>

            <?php if (!empty($feeTransactions)): ?>
            <!-- 📋 Responsive table wrapper for horizontal scrolling on mobile -->
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><i class="fas fa-calendar me-1"></i>Date</th>
                            <th><i class="fas fa-hashtag me-1"></i>Payment ID</th>
                            <th class="text-end"><i class="fas fa-coins me-1"></i>Gross Amount</th>
                            <th class="text-end"><i class="fas fa-percent me-1"></i>Fee Rate</th>
                            <th class="text-end"><i class="fas fa-minus-circle me-1"></i>Fee Amount</th>
                            <th class="text-end"><i class="fas fa-hand-holding-usd me-1"></i>Net Amount</th>
                            <th class="text-center"><i class="fas fa-info-circle me-1"></i>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($feeTransactions as $fee): ?>
                        <tr>
                            <!-- 📅 Transaction date formatted for readability -->
                            <td>
                                <small><?php echo date('d M Y', strtotime($fee['createdAt'])); ?></small>
                                <br>
                                <small class="text-muted"><?php echo date('H:i', strtotime($fee['createdAt'])); ?></small>
                            </td>

                            <!-- 🔗 Payment ID reference -->
                            <td>
                                <code>#<?php echo htmlspecialchars($fee['paymentID'] ?? 'N/A'); ?></code>
                            </td>

                            <!-- 💰 Gross amount (total before fees) -->
                            <td class="text-end">
                                <strong>&pound;<?php echo number_format((float) ($fee['grossAmount'] ?? 0), 2); ?></strong>
                            </td>

                            <!-- 📊 Fee rate percentage -->
                            <td class="text-end">
                                <?php echo number_format((float) ($fee['feePercentage'] ?? $fee['schedulePercentage'] ?? 0), 2); ?>%
                            </td>

                            <!-- 💸 Fee amount deducted -->
                            <td class="text-end text-danger">
                                -&pound;<?php echo number_format((float) ($fee['feeAmount'] ?? 0), 2); ?>
                            </td>

                            <!-- 💵 Net amount after fee deduction -->
                            <td class="text-end text-success">
                                &pound;<?php echo number_format((float) ($fee['netToPartner'] ?? 0), 2); ?>
                            </td>

                            <!-- 🏷️ Status badge with colour coding -->
                            <td class="text-center">
                                <?php
                                // 🎨 Map fee transaction statuses to Bootstrap badge colours
                                // @see ServiceFeeManager::FEE_TRANSACTION_STATUSES
                                $feeStatus = $fee['status'] ?? 'pending';
                                $feeStatusBadge = match ($feeStatus) {
                                    'pending'   => 'bg-warning text-dark',
                                    'collected' => 'bg-info text-white',
                                    'remitted'  => 'bg-success text-white',
                                    'disputed'  => 'bg-danger text-white',
                                    'waived'    => 'bg-secondary text-white',
                                    default     => 'bg-secondary text-white',
                                };
                                ?>
                                <span class="status-badge <?php echo $feeStatusBadge; ?>">
                                    <?php echo htmlspecialchars(ucfirst($feeStatus)); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- 📄 Pagination controls for fee transactions -->
            <?php if ($feeTotalPages > 1): ?>
            <div class="pagination-wrapper">
                <!-- 📊 Record count summary -->
                <small class="text-muted">
                    Showing <?php echo $feeOffset + 1; ?>-<?php echo min($feeOffset + $feeLimit, $feeTotalRecords); ?>
                    of <?php echo (int) $feeTotalRecords; ?> records
                </small>

                <!-- 📄 Bootstrap pagination nav -->
                <nav aria-label="Fee transactions pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <!-- ⬅️ Previous page link -->
                        <li class="page-item <?php echo ($feePage <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link"
                               href="?partner=<?php echo (int) $selectedPartnerID; ?>&dateFrom=<?php echo urlencode($dateFrom); ?>&dateTo=<?php echo urlencode($dateTo); ?>&feePage=<?php echo $feePage - 1; ?>&remitPage=<?php echo $remitPage; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>

                        <!-- 🔢 Page number links (show up to 5 pages around current) -->
                        <?php
                        $feeStartPage = max(1, $feePage - 2);
                        $feeEndPage   = min($feeTotalPages, $feePage + 2);
                        for ($i = $feeStartPage; $i <= $feeEndPage; $i++):
                        ?>
                        <li class="page-item <?php echo ($i === $feePage) ? 'active' : ''; ?>">
                            <a class="page-link"
                               href="?partner=<?php echo (int) $selectedPartnerID; ?>&dateFrom=<?php echo urlencode($dateFrom); ?>&dateTo=<?php echo urlencode($dateTo); ?>&feePage=<?php echo $i; ?>&remitPage=<?php echo $remitPage; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>

                        <!-- ➡️ Next page link -->
                        <li class="page-item <?php echo ($feePage >= $feeTotalPages) ? 'disabled' : ''; ?>">
                            <a class="page-link"
                               href="?partner=<?php echo (int) $selectedPartnerID; ?>&dateFrom=<?php echo urlencode($dateFrom); ?>&dateTo=<?php echo urlencode($dateTo); ?>&feePage=<?php echo $feePage + 1; ?>&remitPage=<?php echo $remitPage; ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- 📋 Empty state when no fee transactions exist -->
            <div class="empty-state">
                <i class="fas fa-file-invoice-dollar d-block"></i>
                <h6>No Fee Transactions</h6>
                <p class="text-muted">No fee transactions found for the selected date range.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ================================================================ -->
        <!-- 🏦 REMITTANCE HISTORY SECTION: Payout records table              -->
        <!-- ================================================================ -->
        <div class="section-card">
            <!-- 📋 Section header -->
            <div class="section-header">
                <i class="fas fa-university me-2"></i>Remittance History
                <span class="badge bg-secondary ms-2"><?php echo (int) $remitTotalRecords; ?> payouts</span>
            </div>

            <?php if (!empty($remittances)): ?>
            <!-- 📋 Responsive remittance history table -->
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><i class="fas fa-calendar me-1"></i>Date</th>
                            <th class="text-end"><i class="fas fa-money-bill-wave me-1"></i>Amount</th>
                            <th class="text-center"><i class="fas fa-info-circle me-1"></i>Status</th>
                            <th><i class="fas fa-credit-card me-1"></i>Method</th>
                            <th><i class="fas fa-hashtag me-1"></i>Transaction Ref</th>
                            <th><i class="fas fa-check-circle me-1"></i>Processed Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($remittances as $remit): ?>
                        <tr>
                            <!-- 📅 Remittance creation date -->
                            <td>
                                <small><?php echo date('d M Y', strtotime($remit['createdAt'])); ?></small>
                                <br>
                                <small class="text-muted"><?php echo date('H:i', strtotime($remit['createdAt'])); ?></small>
                            </td>

                            <!-- 💰 Payout amount -->
                            <td class="text-end">
                                <strong>&pound;<?php echo number_format((float) ($remit['amount'] ?? 0), 2); ?></strong>
                                <?php if (!empty($remit['currency']) && $remit['currency'] !== 'GBP'): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($remit['currency']); ?></small>
                                <?php endif; ?>
                            </td>

                            <!-- 🏷️ Remittance status badge with colour coding -->
                            <td class="text-center">
                                <?php
                                // 🎨 Map remittance statuses to Bootstrap badge colours
                                $remitStatus = $remit['status'] ?? 'pending';
                                $remitStatusBadge = match ($remitStatus) {
                                    'pending'    => 'bg-warning text-dark',
                                    'processing' => 'bg-info text-white',
                                    'completed'  => 'bg-success text-white',
                                    'failed'     => 'bg-danger text-white',
                                    'cancelled'  => 'bg-secondary text-white',
                                    default      => 'bg-secondary text-white',
                                };
                                ?>
                                <span class="status-badge <?php echo $remitStatusBadge; ?>">
                                    <?php echo htmlspecialchars(ucfirst($remitStatus)); ?>
                                </span>
                            </td>

                            <!-- 🏦 Payout method (human-readable label) -->
                            <td>
                                <?php
                                // 🏷️ Map payout method ENUM values to user-friendly labels
                                $methodLabel = match ($remit['payoutMethod'] ?? 'manual') {
                                    'paypal_payout'     => '<i class="fab fa-paypal me-1 text-primary"></i>PayPal',
                                    'bank_transfer'     => '<i class="fas fa-university me-1 text-info"></i>Bank Transfer',
                                    'credit_to_balance' => '<i class="fas fa-wallet me-1 text-success"></i>Credit Balance',
                                    'manual'            => '<i class="fas fa-hand-holding-usd me-1 text-secondary"></i>Manual',
                                    default             => '<i class="fas fa-question-circle me-1"></i>' . htmlspecialchars($remit['payoutMethod'] ?? 'Unknown'),
                                };
                                echo $methodLabel;
                                ?>
                            </td>

                            <!-- 🔗 Transaction reference (from payment provider) -->
                            <td>
                                <?php if (!empty($remit['transactionReference'])): ?>
                                <code><?php echo htmlspecialchars($remit['transactionReference']); ?></code>
                                <?php else: ?>
                                <span class="text-muted">--</span>
                                <?php endif; ?>
                            </td>

                            <!-- 📅 Date the remittance was processed (completed/failed) -->
                            <td>
                                <?php if (!empty($remit['processedAt'])): ?>
                                <small><?php echo date('d M Y H:i', strtotime($remit['processedAt'])); ?></small>
                                <?php else: ?>
                                <span class="text-muted">--</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- 📄 Pagination controls for remittance history -->
            <?php if ($remitTotalPages > 1): ?>
            <div class="pagination-wrapper">
                <!-- 📊 Record count summary -->
                <small class="text-muted">
                    Showing <?php echo $remitOffset + 1; ?>-<?php echo min($remitOffset + $remitLimit, $remitTotalRecords); ?>
                    of <?php echo (int) $remitTotalRecords; ?> payouts
                </small>

                <!-- 📄 Bootstrap pagination nav -->
                <nav aria-label="Remittance history pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <!-- ⬅️ Previous page link -->
                        <li class="page-item <?php echo ($remitPage <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link"
                               href="?partner=<?php echo (int) $selectedPartnerID; ?>&dateFrom=<?php echo urlencode($dateFrom); ?>&dateTo=<?php echo urlencode($dateTo); ?>&feePage=<?php echo $feePage; ?>&remitPage=<?php echo $remitPage - 1; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>

                        <!-- 🔢 Page number links -->
                        <?php
                        $remitStartPage = max(1, $remitPage - 2);
                        $remitEndPage   = min($remitTotalPages, $remitPage + 2);
                        for ($i = $remitStartPage; $i <= $remitEndPage; $i++):
                        ?>
                        <li class="page-item <?php echo ($i === $remitPage) ? 'active' : ''; ?>">
                            <a class="page-link"
                               href="?partner=<?php echo (int) $selectedPartnerID; ?>&dateFrom=<?php echo urlencode($dateFrom); ?>&dateTo=<?php echo urlencode($dateTo); ?>&feePage=<?php echo $feePage; ?>&remitPage=<?php echo $i; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>

                        <!-- ➡️ Next page link -->
                        <li class="page-item <?php echo ($remitPage >= $remitTotalPages) ? 'disabled' : ''; ?>">
                            <a class="page-link"
                               href="?partner=<?php echo (int) $selectedPartnerID; ?>&dateFrom=<?php echo urlencode($dateFrom); ?>&dateTo=<?php echo urlencode($dateTo); ?>&feePage=<?php echo $feePage; ?>&remitPage=<?php echo $remitPage + 1; ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- 🏦 Empty state when no remittance records exist -->
            <div class="empty-state">
                <i class="fas fa-university d-block"></i>
                <h6>No Remittance History</h6>
                <p class="text-muted">No payout records found. Payouts will appear here once processed.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ================================================================ -->
        <!-- ⚙️ PAYOUT PREFERENCES SECTION: Manage payout settings            -->
        <!-- ================================================================ -->
        <div class="preferences-card">
            <div class="card-header bg-white p-3 border-bottom">
                <h5 class="mb-0">
                    <i class="fas fa-cog me-2 text-success"></i>Payout Preferences
                </h5>
            </div>
            <div class="card-body p-4">
                <!-- ⚙️ Payout preferences form (submitted via AJAX to earnings-actions.php) -->
                <form id="payoutPreferencesForm" onsubmit="return savePayoutPreferences(event)">
                    <div class="row">
                        <!-- 📧 Payout email address -->
                        <div class="col-md-4 mb-3">
                            <label for="payoutEmail" class="form-label fw-bold">
                                <i class="fas fa-envelope me-1"></i>Payout Email
                            </label>
                            <input type="email"
                                   class="form-control"
                                   id="payoutEmail"
                                   name="payoutEmail"
                                   value="<?php echo htmlspecialchars($partnerDetails['payoutEmail'] ?? ''); ?>"
                                   placeholder="payouts@yourcompany.com"
                                   required>
                            <div class="form-text">
                                Email address for receiving payout notifications and PayPal payments.
                            </div>
                        </div>

                        <!-- 🏦 Payout method selection -->
                        <div class="col-md-4 mb-3">
                            <label for="payoutMethod" class="form-label fw-bold">
                                <i class="fas fa-credit-card me-1"></i>Payout Method
                            </label>
                            <select class="form-select"
                                    id="payoutMethod"
                                    name="payoutMethod"
                                    required>
                                <?php
                                // 🏷️ Available payout methods (maps to tblPartners.payoutMethod ENUM)
                                $payoutMethods = [
                                    'paypal_payout'     => 'PayPal Payout',
                                    'bank_transfer'     => 'Bank Transfer',
                                    'credit_to_balance' => 'Credit to Account Balance',
                                ];
                                $currentMethod = $partnerDetails['payoutMethod'] ?? 'manual';
                                foreach ($payoutMethods as $value => $label):
                                ?>
                                <option value="<?php echo htmlspecialchars($value); ?>"
                                        <?php echo ($currentMethod === $value) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                How you would like to receive your payouts.
                            </div>
                        </div>

                        <!-- 💰 Minimum payout threshold -->
                        <div class="col-md-4 mb-3">
                            <label for="minimumPayoutThreshold" class="form-label fw-bold">
                                <i class="fas fa-pound-sign me-1"></i>Minimum Payout Threshold
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&pound;</span>
                                <input type="number"
                                       class="form-control"
                                       id="minimumPayoutThreshold"
                                       name="minimumPayoutThreshold"
                                       value="<?php echo htmlspecialchars($partnerDetails['minimumPayoutThreshold'] ?? '50.00'); ?>"
                                       min="10"
                                       max="10000"
                                       step="0.01"
                                       required>
                            </div>
                            <div class="form-text">
                                Minimum earnings required before a payout is triggered (min &pound;10.00).
                            </div>
                        </div>
                    </div>

                    <!-- 💾 Save button and feedback area -->
                    <div class="d-flex align-items-center mt-2">
                        <button type="submit" class="btn btn-success" id="savePreferencesBtn">
                            <i class="fas fa-save me-2"></i>Save Preferences
                        </button>
                        <span id="savePreferencesStatus" class="ms-3"></span>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- ❓ HELP SECTION: Guidance for understanding earnings & payouts    -->
        <!-- ================================================================ -->
        <div class="card mb-4">
            <div class="card-body">
                <h6 class="mb-3"><i class="fas fa-question-circle me-2"></i>Understanding Your Earnings</h6>
                <div class="row">
                    <div class="col-md-4">
                        <h6><i class="fas fa-chart-line me-2 text-primary"></i>Revenue &amp; Fees</h6>
                        <p class="text-muted small">
                            Total Revenue is the gross amount collected from your customers.
                            Service fees are deducted based on your payment configuration
                            (own keys ~10%, SIGNula keys ~30%). Net Earnings is what you receive.
                        </p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-university me-2 text-info"></i>Payouts</h6>
                        <p class="text-muted small">
                            Payouts are processed automatically when your net earnings exceed the
                            minimum threshold. Processing times vary by method: PayPal (1-2 days),
                            Bank Transfer (3-5 days), Credit Balance (instant).
                        </p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-cog me-2 text-success"></i>Payout Settings</h6>
                        <p class="text-muted small">
                            Update your payout email, preferred method, and minimum threshold above.
                            Changes take effect for the next scheduled payout. For urgent requests,
                            contact support.
                        </p>
                    </div>
                </div>
                <div class="mt-3">
                    <a href="mailto:support@signulo.id" class="btn btn-outline-primary">
                        <i class="fas fa-envelope me-2"></i>Contact Support
                    </a>
                </div>
            </div>
        </div>

    </div><!-- /.container-fluid -->

    <!-- ================================================================== -->
    <!-- 📦 JAVASCRIPT: Bootstrap 5.3.2 Bundle (CDN with SRI integrity)     -->
    <!-- ================================================================== -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- 🔐 CSRF Token: Required for all POST AJAX requests to prevent CSRF attacks -->
    <!-- @see https://owasp.org/www-community/attacks/csrf -->
    <script>const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';</script>

    <script>
        // ====================================================================
        // 🏢 Partner ID: Used in all API calls to identify the active partner
        // ====================================================================
        const partnerID = <?php echo (int) $selectedPartnerID; ?>;

        // ====================================================================
        // 🔔 showAlert() — Display a Bootstrap dismissible alert message
        // ====================================================================
        /**
         * 📢 Display a temporary alert message in the alert container
         *
         * @param {string} message - The alert message text (HTML supported)
         * @param {string} type    - Bootstrap alert type (success, danger, warning, info)
         */
        function showAlert(message, type = 'info') {
            // 🏗️ Create the alert DOM element
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;

            // 📌 Append to the alert container at the top of the page
            document.getElementById('alertContainer').appendChild(alert);

            // ⏰ Auto-remove the alert after 5 seconds
            setTimeout(() => alert.remove(), 5000);
        }

        // ====================================================================
        // 💾 savePayoutPreferences() — AJAX handler for payout preferences form
        // ====================================================================
        /**
         * 💾 Submit payout preferences via AJAX POST to the earnings-actions API
         *
         * Sends: payoutEmail, payoutMethod, minimumPayoutThreshold
         * Endpoint: ../api/earnings-actions.php
         *
         * @param {Event} event - The form submit event (prevented for AJAX)
         * @returns {boolean} false to prevent default form submission
         */
        async function savePayoutPreferences(event) {
            // 🚫 Prevent the default form submission (we use AJAX instead)
            event.preventDefault();

            // 🔘 Get the submit button and status display element
            const btn = document.getElementById('savePreferencesBtn');
            const statusEl = document.getElementById('savePreferencesStatus');

            // ⏳ Show loading state on the button
            const originalBtnText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
            statusEl.innerHTML = '';

            try {
                // 📡 Send POST request to the earnings-actions API endpoint
                const response = await fetch('../api/earnings-actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'save_payout_preferences',
                        partnerID: partnerID,
                        payoutEmail: document.getElementById('payoutEmail').value.trim(),
                        payoutMethod: document.getElementById('payoutMethod').value,
                        minimumPayoutThreshold: parseFloat(document.getElementById('minimumPayoutThreshold').value),
                        csrf_token: csrfToken
                    })
                });

                // 📥 Parse the JSON response
                const result = await response.json();

                if (result.success) {
                    // ✅ Show success message
                    showAlert('<i class="fas fa-check-circle me-2"></i>Payout preferences saved successfully!', 'success');
                    statusEl.innerHTML = '<span class="text-success"><i class="fas fa-check me-1"></i>Saved</span>';

                    // ⏰ Clear the status message after 3 seconds
                    setTimeout(() => { statusEl.innerHTML = ''; }, 3000);
                } else {
                    // ❌ Show error message from the API
                    showAlert(
                        '<i class="fas fa-exclamation-triangle me-2"></i>' + (result.message || 'Failed to save preferences'),
                        'danger'
                    );
                    statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-times me-1"></i>Failed</span>';
                }
            } catch (error) {
                // 🚨 Network error or unexpected failure
                console.error('Error saving payout preferences:', error);
                showAlert(
                    '<i class="fas fa-exclamation-triangle me-2"></i>An error occurred. Please try again.',
                    'danger'
                );
                statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-times me-1"></i>Error</span>';
            } finally {
                // 🔄 Restore button to its original state
                btn.disabled = false;
                btn.innerHTML = originalBtnText;
            }

            return false;
        }

        // ====================================================================
        // 📊 Initialise Bootstrap Tooltips (for bar chart hover tooltips)
        // ====================================================================
        // @see https://getbootstrap.com/docs/5.3/components/tooltips/
        document.addEventListener('DOMContentLoaded', function() {
            // 🔍 Find all elements with data-bs-toggle="tooltip" and initialise them
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(
                tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl)
            );
        });
    </script>
</body>
</html>
