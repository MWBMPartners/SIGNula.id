<?php
/**
 * 💰 Credit Balance Management Dashboard (Super Admin)
 *
 * Comprehensive dashboard for managing credit balances, manual adjustments,
 * deposits, withdrawals, and viewing transaction history across all users
 * and partners. Supports multi-currency credit accounts with full audit
 * trail and paginated AJAX-driven data tables.
 *
 * Features:
 *   - Real-time stats cards (total balance, deposits, withdrawals, active accounts)
 *   - Tabbed interface: Balances + Transactions
 *   - Filterable, paginated DataTables for both tabs
 *   - Manual adjustment modal with reason/description fields
 *   - Per-owner transaction history modal
 *   - Transaction type colour-coded badges
 *   - AJAX loading via jQuery $.ajax() with CSRF protection
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons — FontAwesome 6.4 Icons
 * @see https://api.jquery.com/jquery.ajax/ — jQuery AJAX Reference
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.3.0-beta
 */

// ═══════════════════════════════════════════════════════════════════════════════
// 🔌 BACKEND INCLUDES — Load core configuration, database, session & access
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 📂 Load the main application configuration.
 * dirname(__DIR__, 3) navigates from /web/public_html/admin/payments/ up to the project root.
 * @see https://www.php.net/manual/en/function.dirname.php — PHP dirname()
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

/**
 * 🗄️ Database singleton — provides MySQLi prepared statement wrappers.
 * @see /_backend/Database.php — Database class documentation
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

/**
 * 🔑 Session management — handles login state, session tokens, CSRF generation.
 * @see /_backend/SessionManager.php — SessionManager class documentation
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

/**
 * 🛡️ Access control — role-based permission checks (Super Admin, Root Admin, etc.).
 * @see /_backend/AccessControl.php — AccessControl class documentation
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// ═══════════════════════════════════════════════════════════════════════════════
// 🔌 INITIALISE CORE SERVICES
// ═══════════════════════════════════════════════════════════════════════════════

/** @var Database $db — Database singleton instance for all queries */
$db = Database::getInstance();

/** @var SessionManager $sessionManager — Manages user session state */
$sessionManager = new SessionManager($db);

/** @var AccessControl $accessControl — Enforces role-based access control */
$accessControl = new AccessControl($db, $sessionManager);

// ═══════════════════════════════════════════════════════════════════════════════
// 🔒 AUTHENTICATION CHECK — Redirect unauthenticated users to login
// ═══════════════════════════════════════════════════════════════════════════════

if (!$sessionManager->isLoggedIn()) {
    /**
     * 🔒 User is not logged in — redirect to the authentication login page.
     * @see https://www.php.net/manual/en/function.header.php — PHP header()
     */
    header('Location: /auth/login.php');
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🛡️ SUPER ADMIN GUARD — Only super admins can access this page
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 🛡️ requireSuperAdmin() throws an exception or redirects if the current user
 * does not have Super Admin privileges. This prevents any lower-tier admin
 * from accessing the credit balance management dashboard.
 * @see /_backend/AccessControl.php — AccessControl::requireSuperAdmin()
 */
$accessControl->requireSuperAdmin();

// ═══════════════════════════════════════════════════════════════════════════════
// 📊 SERVER-SIDE STATISTICS — Quick stats for initial page render
// These are rendered server-side so the page displays data immediately,
// without waiting for the first AJAX call to complete.
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 💰 Total credit balance across all accounts
 * Aggregates the current balance from tblCreditBalances.
 * @see https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html — MySQL Aggregate Functions
 */
$totalBalanceStmt = $db->prepare(
    "SELECT
        COALESCE(SUM(balance), 0) AS totalBalance
     FROM tblCreditBalances"
);
$totalBalanceStmt->execute();
$totalBalanceResult = $totalBalanceStmt->get_result()->fetch_assoc();
$totalBalance = (float)($totalBalanceResult['totalBalance'] ?? 0);

/**
 * 📈 Total deposits — sum of all 'deposit' transactions (last 30 days + all time)
 * @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html — MySQL Date Functions
 */
$depositStmt = $db->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN transactionType = 'deposit' THEN amount ELSE 0 END), 0) AS totalDeposits,
        COALESCE(SUM(CASE WHEN transactionType = 'withdrawal' THEN ABS(amount) ELSE 0 END), 0) AS totalWithdrawals,
        COUNT(DISTINCT CONCAT(ownerType, '-', ownerID)) AS activeAccounts
     FROM tblCreditTransactions"
);
$depositStmt->execute();
$txStats = $depositStmt->get_result()->fetch_assoc();
$totalDeposits = (float)($txStats['totalDeposits'] ?? 0);
$totalWithdrawals = (float)($txStats['totalWithdrawals'] ?? 0);
$activeAccounts = (int)($txStats['activeAccounts'] ?? 0);

/**
 * 💱 Default currency from tblSettings — used for display formatting.
 * Falls back to 'GBP' if the setting is not found in the database.
 * @see /_config/config.php — Application configuration
 */
$currencyStmt = $db->prepare(
    "SELECT settingValue FROM tblSettings WHERE settingKey = 'payment_default_currency' LIMIT 1"
);
$currencyStmt->execute();
$currencyResult = $currencyStmt->get_result()->fetch_assoc();
$currency = $currencyResult ? $currencyResult['settingValue'] : 'GBP';

/**
 * 💷 Currency symbols map — maps ISO 4217 currency codes to their display symbols.
 * @see https://en.wikipedia.org/wiki/ISO_4217 — ISO 4217 Currency Codes
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
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📄 META & TITLE                                                    -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Credit Balance Management - SIGNula Admin</title>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🎨 Bootstrap 5.3.2 CSS (CDN with SRI)                             -->
    <!-- @see https://getbootstrap.com/docs/5.3/ — Bootstrap Documentation  -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🎯 FontAwesome 6.4.2 CSS (CDN with SRI)                           -->
    <!-- @see https://fontawesome.com/icons — FontAwesome Icon Reference    -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🎨 CUSTOM STYLES — Matches existing payments dashboard patterns    -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <style>
        /* 🎨 Base body styling — light grey background matching all admin pages */
        body { background-color: #f8f9fa; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Page header — green/teal gradient matching payments theme   */
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
        /* 📊 Stat cards — summary metrics at the top of the page         */
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
        /* 📋 Table card — container for DataTables with gradient header  */
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
        /* 🏷️ Transaction type badges — colour-coded by transaction type  */
        /* deposit=success, withdrawal=warning, payment=primary,          */
        /* refund=info, adjustment=secondary, transfer=dark               */
        /* ═══════════════════════════════════════════════════════════════ */
        .badge-deposit { background-color: #28a745; }
        .badge-withdrawal { background-color: #ffc107; color: #333; }
        .badge-payment { background-color: #0d6efd; }
        .badge-refund { background-color: #0dcaf0; color: #333; }
        .badge-adjustment { background-color: #6c757d; }
        .badge-transfer { background-color: #212529; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📄 Pagination info text                                        */
        /* ═══════════════════════════════════════════════════════════════ */
        .pagination-info {
            color: #6c757d;
            font-size: 0.9rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔄 Loading overlay — semi-transparent spinner over table cards */
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
        /* 📱 Modal enhancements — detail sections with teal left border  */
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
        /* 💰 Balance highlight — positive vs. negative amount colours    */
        /* ═══════════════════════════════════════════════════════════════ */
        .amount-positive { color: #28a745; font-weight: 600; }
        .amount-negative { color: #dc3545; font-weight: 600; }
        .amount-neutral { color: #212529; font-weight: 600; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📱 Responsive adjustments for smaller screens                  */
        /* ═══════════════════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .stat-card .stat-number {
                font-size: 1.5rem;
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
        <!-- 🎨 PAGE HEADER — Green/teal gradient matching payments theme   -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="page-header">
            <!-- 🔙 Back navigation link to the main payments dashboard -->
            <a href="index.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Payments Dashboard
            </a>
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">
                        <i class="fas fa-coins me-2"></i>Credit Balance Management
                    </h1>
                    <p class="mb-0 opacity-75">Manage credit balances, deposits, withdrawals, and manual adjustments</p>
                </div>
                <div class="col-md-4 text-end">
                    <!-- ➕ Manual Adjustment button — opens the adjustment modal -->
                    <button class="btn btn-light btn-lg" onclick="showAdjustmentModal()">
                        <i class="fas fa-sliders-h me-2"></i>Manual Adjustment
                    </button>
                </div>
            </div>
        </div>

        <!-- 💬 Alert notification container — dynamically populated by showAlert() -->
        <div id="alertContainer"></div>

        <!-- 🔐 Hidden CSRF token field — used by all AJAX POST requests -->
        <!-- @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Prevention -->
        <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📊 STAT CARDS — Summary metrics rendered server-side           -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="row g-3 mb-4">
            <!-- 💰 Total Credit Balance across all accounts -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-success" id="statTotalBalance">
                        <?php echo htmlspecialchars($symbol . number_format($totalBalance, 2), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-wallet me-1"></i>Total Balance</div>
                </div>
            </div>

            <!-- 📈 Total Deposits — all-time sum of deposit transactions -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-primary" id="statTotalDeposits">
                        <?php echo htmlspecialchars($symbol . number_format($totalDeposits, 2), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-arrow-down me-1"></i>Total Deposits</div>
                </div>
            </div>

            <!-- 📉 Total Withdrawals — all-time sum of withdrawal transactions -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-warning" id="statTotalWithdrawals">
                        <?php echo htmlspecialchars($symbol . number_format($totalWithdrawals, 2), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-arrow-up me-1"></i>Total Withdrawals</div>
                </div>
            </div>

            <!-- 👥 Active Accounts — unique owner type + ID combinations -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-info" id="statActiveAccounts">
                        <?php echo number_format($activeAccounts); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-users me-1"></i>Active Accounts</div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🗂️ TAB NAVIGATION — Balances & Transactions tabs               -->
        <!-- @see https://getbootstrap.com/docs/5.3/components/navs-tabs/  -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <ul class="nav nav-tabs mb-4" id="creditTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-balances" data-bs-toggle="tab"
                        data-bs-target="#pane-balances" type="button" role="tab"
                        aria-controls="pane-balances" aria-selected="true">
                    <i class="fas fa-wallet me-1"></i>Balances
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-transactions" data-bs-toggle="tab"
                        data-bs-target="#pane-transactions" type="button" role="tab"
                        aria-controls="pane-transactions" aria-selected="false">
                    <i class="fas fa-exchange-alt me-1"></i>Transactions
                </button>
            </li>
        </ul>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📋 TAB CONTENT                                                 -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="tab-content" id="creditTabContent">

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 💰 BALANCES TAB — All credit balance accounts          -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade show active" id="pane-balances" role="tabpanel" aria-labelledby="tab-balances">

                <!-- 🔍 Filter bar for balances -->
                <div class="filter-bar">
                    <div class="row g-3 align-items-end">
                        <!-- 🔍 Search input — matches on owner name, email, etc. -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><i class="fas fa-search me-1"></i>Search</label>
                            <input type="text" id="balanceSearch" class="form-control"
                                   placeholder="Name, email, partner name...">
                        </div>
                        <!-- 🏷️ Owner type filter — user or partner -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><i class="fas fa-filter me-1"></i>Owner Type</label>
                            <select id="balanceOwnerType" class="form-select">
                                <option value="all">All Types</option>
                                <option value="user">Users</option>
                                <option value="partner">Partners</option>
                            </select>
                        </div>
                        <!-- 🔍 Apply filter button -->
                        <div class="col-md-2">
                            <button class="btn btn-success w-100" onclick="loadBalances(1)">
                                <i class="fas fa-filter me-1"></i>Apply
                            </button>
                        </div>
                        <!-- 🧹 Clear filters button -->
                        <div class="col-md-2">
                            <button class="btn btn-outline-secondary w-100" onclick="clearBalanceFilters()">
                                <i class="fas fa-times me-1"></i>Clear
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 📋 Balances table card -->
                <div class="card table-card position-relative">
                    <!-- 📋 Table header with gradient -->
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-wallet me-2"></i>Credit Balances</h5>
                        <span id="balanceCount" class="badge bg-light text-dark"></span>
                    </div>
                    <!-- 🔄 Loading overlay — shown during AJAX requests -->
                    <div id="balancesLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <!-- 📋 Responsive table container -->
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Owner</th>
                                    <th>Balance</th>
                                    <th>Currency</th>
                                    <th>Last Activity</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="balancesTableBody">
                                <!-- 🔄 Initial loading placeholder — replaced by AJAX -->
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>
                                        Loading credit balances...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- 📄 Pagination controls -->
                    <div class="d-flex justify-content-between align-items-center p-3 border-top">
                        <div class="pagination-info" id="balancePaginationInfo">Showing 0 of 0</div>
                        <nav aria-label="Balance pagination">
                            <ul class="pagination pagination-sm mb-0" id="balancePagination"></ul>
                        </nav>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 📋 TRANSACTIONS TAB — Credit transaction history       -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pane-transactions" role="tabpanel" aria-labelledby="tab-transactions">

                <!-- 🔍 Filter bar for transactions -->
                <div class="filter-bar">
                    <div class="row g-3 align-items-end">
                        <!-- 🏷️ Owner type filter -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold"><i class="fas fa-filter me-1"></i>Owner Type</label>
                            <select id="txOwnerType" class="form-select">
                                <option value="all">All Types</option>
                                <option value="user">Users</option>
                                <option value="partner">Partners</option>
                            </select>
                        </div>
                        <!-- 🏷️ Transaction type filter -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold"><i class="fas fa-tags me-1"></i>Type</label>
                            <select id="txTransactionType" class="form-select">
                                <option value="all">All Types</option>
                                <option value="deposit">Deposit</option>
                                <option value="withdrawal">Withdrawal</option>
                                <option value="payment">Payment</option>
                                <option value="refund">Refund</option>
                                <option value="adjustment">Adjustment</option>
                                <option value="transfer">Transfer</option>
                            </select>
                        </div>
                        <!-- 📅 Date From filter -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold"><i class="fas fa-calendar me-1"></i>From</label>
                            <input type="date" id="txDateFrom" class="form-control">
                        </div>
                        <!-- 📅 Date To filter -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold"><i class="fas fa-calendar me-1"></i>To</label>
                            <input type="date" id="txDateTo" class="form-control">
                        </div>
                        <!-- 🔍 Apply filter button -->
                        <div class="col-md-2">
                            <button class="btn btn-success w-100" onclick="loadTransactions(1)">
                                <i class="fas fa-filter me-1"></i>Apply
                            </button>
                        </div>
                        <!-- 🧹 Clear filters button -->
                        <div class="col-md-2">
                            <button class="btn btn-outline-secondary w-100" onclick="clearTransactionFilters()">
                                <i class="fas fa-times me-1"></i>Clear
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 📋 Transactions table card -->
                <div class="card table-card position-relative">
                    <!-- 📋 Table header with gradient -->
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-exchange-alt me-2"></i>Transaction History</h5>
                        <span id="transactionCount" class="badge bg-light text-dark"></span>
                    </div>
                    <!-- 🔄 Loading overlay — shown during AJAX requests -->
                    <div id="transactionsLoading" class="loading-overlay" style="display: none;">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <!-- 📋 Responsive table container -->
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Owner</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Balance After</th>
                                    <th>Description</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody id="transactionsTableBody">
                                <!-- 🔄 Initial loading placeholder — replaced by AJAX -->
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>
                                        Loading transactions...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- 📄 Pagination controls -->
                    <div class="d-flex justify-content-between align-items-center p-3 border-top">
                        <div class="pagination-info" id="txPaginationInfo">Showing 0 of 0</div>
                        <nav aria-label="Transaction pagination">
                            <ul class="pagination pagination-sm mb-0" id="txPagination"></ul>
                        </nav>
                    </div>
                </div>
            </div>

        </div><!-- /tab-content -->
    </div><!-- /container-fluid -->

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📱 MODALS                                                          -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- ⚖️ MANUAL ADJUSTMENT MODAL — Admin can add/subtract credits        -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="adjustmentModal" tabindex="-1" aria-labelledby="adjustmentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <!-- 🎨 Modal header with teal gradient -->
                <div class="modal-header" style="background: linear-gradient(135deg, #11998e, #38ef7d); color: white;">
                    <h5 class="modal-title" id="adjustmentModalLabel">
                        <i class="fas fa-sliders-h me-2"></i>Manual Credit Adjustment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- 🏷️ Owner Type select -->
                    <div class="mb-3">
                        <label for="adjOwnerType" class="form-label fw-semibold">
                            <i class="fas fa-user-tag me-1"></i>Owner Type
                        </label>
                        <select id="adjOwnerType" class="form-select" onchange="onAdjOwnerTypeChange()">
                            <option value="user">User</option>
                            <option value="partner">Partner</option>
                        </select>
                    </div>
                    <!-- 🆔 Owner ID input -->
                    <div class="mb-3">
                        <label for="adjOwnerID" class="form-label fw-semibold">
                            <i class="fas fa-id-badge me-1"></i>Owner ID
                        </label>
                        <input type="number" id="adjOwnerID" class="form-control" min="1"
                               placeholder="Enter the user or partner ID">
                        <small class="form-text text-muted" id="adjOwnerHint">
                            Enter the numeric user ID from tblUsers
                        </small>
                    </div>
                    <!-- 💰 Amount input — positive for credit, negative for debit -->
                    <div class="mb-3">
                        <label for="adjAmount" class="form-label fw-semibold">
                            <i class="fas fa-coins me-1"></i>Amount
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><?php echo htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8'); ?></span>
                            <input type="number" id="adjAmount" class="form-control" step="0.01"
                                   placeholder="Positive to add, negative to subtract">
                        </div>
                        <small class="form-text text-muted">
                            Use a positive value to add credits, negative to subtract credits.
                        </small>
                    </div>
                    <!-- 💱 Currency select -->
                    <div class="mb-3">
                        <label for="adjCurrency" class="form-label fw-semibold">
                            <i class="fas fa-money-bill me-1"></i>Currency
                        </label>
                        <select id="adjCurrency" class="form-select">
                            <option value="GBP" <?php echo $currency === 'GBP' ? 'selected' : ''; ?>>GBP (£)</option>
                            <option value="USD" <?php echo $currency === 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                            <option value="EUR" <?php echo $currency === 'EUR' ? 'selected' : ''; ?>>EUR (€)</option>
                            <option value="AUD" <?php echo $currency === 'AUD' ? 'selected' : ''; ?>>AUD (A$)</option>
                            <option value="CAD" <?php echo $currency === 'CAD' ? 'selected' : ''; ?>>CAD (C$)</option>
                        </select>
                    </div>
                    <!-- 📝 Description -->
                    <div class="mb-3">
                        <label for="adjDescription" class="form-label fw-semibold">
                            <i class="fas fa-align-left me-1"></i>Description
                        </label>
                        <textarea id="adjDescription" class="form-control" rows="2"
                                  placeholder="Describe the reason for this adjustment..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-success" id="btnSubmitAdjustment" onclick="submitAdjustment()">
                        <i class="fas fa-check me-1"></i>Submit Adjustment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📜 TRANSACTION HISTORY MODAL — Per-owner detailed history          -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <!-- 🎨 Modal header with teal gradient -->
                <div class="modal-header" style="background: linear-gradient(135deg, #11998e, #38ef7d); color: white;">
                    <h5 class="modal-title" id="historyModalLabel">
                        <i class="fas fa-history me-2"></i>Transaction History
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- 📊 Owner info section -->
                    <div class="detail-section mb-3">
                        <div class="detail-label">Account</div>
                        <div class="detail-value" id="historyOwnerInfo">Loading...</div>
                    </div>
                    <div class="detail-section mb-3">
                        <div class="detail-label">Current Balance</div>
                        <div class="detail-value fs-4 fw-bold text-success" id="historyCurrentBalance">-</div>
                    </div>

                    <!-- 📋 Transaction history table within modal -->
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Balance After</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody id="historyTableBody">
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        <i class="fas fa-spinner fa-spin me-1"></i>Loading history...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- 📄 Load more button for history pagination -->
                    <div class="text-center mt-3">
                        <button id="btnLoadMoreHistory" class="btn btn-outline-success btn-sm" onclick="loadMoreHistory()" style="display: none;">
                            <i class="fas fa-arrow-down me-1"></i>Load More
                        </button>
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
    <!-- 🔐 SCRIPTS — Bootstrap JS, jQuery, and custom credit management    -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->

    <!-- 🔐 Bootstrap 5.3.2 JS Bundle (CDN with SRI) -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- 📦 jQuery 3.7.1 (CDN with SRI) — used for $.ajax() AJAX calls -->
    <!-- @see https://api.jquery.com/ — jQuery API Documentation -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
            integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
            crossorigin="anonymous"></script>

    <script>
    /**
     * 💰 Credit Balance Management — Client-Side Logic
     *
     * Handles AJAX data loading for balances and transactions, filtering,
     * pagination, manual adjustments, and per-owner transaction history.
     * Uses jQuery $.ajax() for all API calls with CSRF token protection.
     *
     * @see https://api.jquery.com/jquery.ajax/ — jQuery AJAX Documentation
     * @see https://owasp.org/www-community/attacks/csrf — CSRF Prevention
     */

    // ═══════════════════════════════════════════════════════════════════
    // 🔧 CONFIGURATION & STATE
    // ═══════════════════════════════════════════════════════════════════

    /** @type {string} apiUrl — Base URL for the credit actions API endpoint */
    const apiUrl = '../api/credit-actions.php';

    /** @type {string} currencySymbol — Currency symbol from PHP for display formatting */
    const currencySymbol = '<?php echo htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8'); ?>';

    /** @type {number} perPage — Number of records per page for pagination */
    const perPage = 25;

    /** @type {number} currentBalancePage — Current page number for the balances tab */
    let currentBalancePage = 1;

    /** @type {number} currentTxPage — Current page number for the transactions tab */
    let currentTxPage = 1;

    /** @type {string} historyOwnerType — Owner type for the currently open history modal */
    let historyOwnerType = '';

    /** @type {number} historyOwnerID — Owner ID for the currently open history modal */
    let historyOwnerID = 0;

    /** @type {number} historyOffset — Current offset for paginated history in the modal */
    let historyOffset = 0;

    /** @type {number} historyLimit — Number of records per "Load More" request */
    const historyLimit = 50;

    // ═══════════════════════════════════════════════════════════════════
    // 🛠️ UTILITY FUNCTIONS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 🔒 Escape HTML entities to prevent XSS attacks.
     * Creates a temporary DOM text node to safely encode user-supplied content.
     *
     * @param {string} text — Raw text to escape
     * @returns {string} HTML-safe escaped string
     * @see https://owasp.org/www-community/attacks/xss/ — OWASP XSS Prevention
     */
    function escapeHtml(text) {
        if (!text && text !== 0) { return ''; }
        var div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    /**
     * 💬 Show a Bootstrap alert notification in the #alertContainer.
     * Alerts auto-dismiss after 5 seconds.
     *
     * @param {string} message — Alert message content (may include safe HTML)
     * @param {string} type — Bootstrap alert type: success, danger, warning, info
     * @see https://getbootstrap.com/docs/5.3/components/alerts/ — Bootstrap Alerts
     */
    function showAlert(message, type) {
        type = type || 'info';
        var alertHtml = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
            message +
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
            '</div>';
        $('#alertContainer').append(alertHtml);

        // ⏰ Auto-dismiss after 5 seconds
        var $alert = $('#alertContainer .alert:last');
        setTimeout(function() {
            $alert.alert('close');
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
        if (!dateStr) { return '<span class="text-muted">&mdash;</span>'; }
        var d = new Date(dateStr);
        return d.toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    /**
     * 💰 Format a numeric amount with currency symbol.
     *
     * @param {number|string} amount — Amount to format
     * @returns {string} Formatted amount with currency symbol (e.g., "£123.45")
     */
    function formatCurrency(amount) {
        var num = parseFloat(amount) || 0;
        return currencySymbol + num.toFixed(2);
    }

    /**
     * 🏷️ Get a colour-coded badge for a transaction type.
     * Uses the predefined CSS classes: badge-deposit, badge-withdrawal, etc.
     *
     * @param {string} type — Transaction type value
     * @returns {string} HTML badge string
     */
    function typeBadge(type) {
        var t = (type || '').toLowerCase();
        /**
         * 🎨 Mapping of transaction types to their CSS badge classes:
         *   deposit    → badge-deposit (green/success)
         *   withdrawal → badge-withdrawal (yellow/warning)
         *   payment    → badge-payment (blue/primary)
         *   refund     → badge-refund (cyan/info)
         *   adjustment → badge-adjustment (grey/secondary)
         *   transfer   → badge-transfer (dark)
         */
        var badgeClass = 'badge-' + t;
        // 🛡️ Fallback to a neutral badge if the type is not recognised
        var knownTypes = ['deposit', 'withdrawal', 'payment', 'refund', 'adjustment', 'transfer'];
        if (knownTypes.indexOf(t) === -1) {
            badgeClass = 'bg-secondary';
        }
        return '<span class="badge ' + badgeClass + '">' + escapeHtml(type) + '</span>';
    }

    /**
     * 💰 Get a CSS class for amount display based on sign.
     * Positive amounts are green, negative are red, zero is neutral.
     *
     * @param {number|string} amount — The amount value
     * @returns {string} CSS class name
     */
    function amountClass(amount) {
        var num = parseFloat(amount) || 0;
        if (num > 0) { return 'amount-positive'; }
        if (num < 0) { return 'amount-negative'; }
        return 'amount-neutral';
    }

    /**
     * 🔐 Get the current CSRF token from the hidden input field.
     *
     * @returns {string} The CSRF token value
     * @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Reference
     */
    function getCSRFToken() {
        return $('#csrf_token').val();
    }

    // ═══════════════════════════════════════════════════════════════════
    // 💰 BALANCES TAB — Load and render credit balance data
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 📋 Load credit balances from the API with pagination and filters.
     * Makes a GET request to credit-actions.php with action=get_balances.
     *
     * @param {number} page — Page number to load (1-based)
     * @see https://api.jquery.com/jquery.ajax/ — jQuery $.ajax()
     */
    function loadBalances(page) {
        page = page || 1;
        currentBalancePage = page;

        // 🔄 Show loading overlay
        $('#balancesLoading').show();

        // 📤 Build the request parameters
        var params = {
            action: 'get_balances',
            page: page,
            limit: perPage,
            offset: (page - 1) * perPage,
            ownerType: $('#balanceOwnerType').val(),
            search: $.trim($('#balanceSearch').val())
        };

        /**
         * 📡 AJAX GET request to fetch credit balances.
         * The API returns: { success, data[], total, pages, currentPage }
         */
        $.ajax({
            url: apiUrl,
            method: 'GET',
            data: params,
            dataType: 'json',
            /**
             * ✅ Success handler — render the balances table and pagination.
             * @param {Object} result — API response object
             */
            success: function(result) {
                if (result.success) {
                    renderBalances(result.data || []);
                    renderBalancePagination(result.total || 0, result.pages || 1, result.currentPage || page);
                    $('#balanceCount').text((result.total || 0) + ' accounts');
                } else {
                    showAlert(result.message || 'Failed to load credit balances', 'danger');
                }
            },
            /**
             * ❌ Error handler — show a user-friendly error message.
             * @param {Object} xhr — jQuery XHR object
             * @param {string} status — Error status text
             * @param {string} error — Error description
             */
            error: function(xhr, status, error) {
                showAlert('Error loading credit balances: ' + escapeHtml(error), 'danger');
            },
            /**
             * 🔄 Complete handler — always hide the loading overlay.
             */
            complete: function() {
                $('#balancesLoading').hide();
            }
        });
    }

    /**
     * 📋 Render credit balance rows into the balances table.
     *
     * @param {Array} balances — Array of credit balance objects from the API
     *   Each object should contain: ownerType, ownerID, ownerName, ownerEmail,
     *   balance, currency, lastActivity
     */
    function renderBalances(balances) {
        var $tbody = $('#balancesTableBody');

        // 📭 Handle empty state — show a friendly message
        if (!balances || balances.length === 0) {
            $tbody.html(
                '<tr>' +
                    '<td colspan="5" class="text-center py-5 text-muted">' +
                        '<i class="fas fa-wallet fa-3x mb-3 d-block opacity-50"></i>' +
                        '<h5>No Credit Balances Found</h5>' +
                        '<p>No credit accounts match your current filters.</p>' +
                    '</td>' +
                '</tr>'
            );
            return;
        }

        // 📋 Build the HTML for each balance row
        var html = '';
        $.each(balances, function(index, b) {
            /**
             * 🏷️ Determine the owner type badge:
             *   user    → blue badge with user icon
             *   partner → purple badge with handshake icon
             */
            var ownerTypeBadge = (b.ownerType === 'partner')
                ? '<span class="badge bg-info"><i class="fas fa-handshake me-1"></i>Partner</span>'
                : '<span class="badge bg-primary"><i class="fas fa-user me-1"></i>User</span>';

            /**
             * 💰 Determine the balance colour class based on positive/negative value.
             */
            var balClass = amountClass(b.balance);

            html += '<tr>' +
                '<td>' +
                    ownerTypeBadge + ' ' +
                    '<strong>' + escapeHtml(b.ownerName || 'Unknown') + '</strong>' +
                    (b.ownerEmail ? '<br><small class="text-muted">' + escapeHtml(b.ownerEmail) + '</small>' : '') +
                    '<br><small class="text-muted">ID: ' + escapeHtml(b.ownerID) + '</small>' +
                '</td>' +
                '<td class="' + balClass + '">' + formatCurrency(b.balance) + '</td>' +
                '<td>' + escapeHtml(b.currency || 'GBP') + '</td>' +
                '<td>' + formatDate(b.lastActivity) + '</td>' +
                '<td class="text-center">' +
                    '<div class="btn-group btn-group-sm" role="group">' +
                        '<button class="btn btn-outline-primary" onclick="viewHistory(\'' + escapeHtml(b.ownerType) + '\', ' + parseInt(b.ownerID) + ', \'' + escapeHtml(b.ownerName) + '\')" title="View Transaction History">' +
                            '<i class="fas fa-history"></i>' +
                        '</button>' +
                        '<button class="btn btn-outline-success" onclick="quickDeposit(\'' + escapeHtml(b.ownerType) + '\', ' + parseInt(b.ownerID) + ')" title="Quick Deposit">' +
                            '<i class="fas fa-plus"></i>' +
                        '</button>' +
                        '<button class="btn btn-outline-warning" onclick="quickWithdraw(\'' + escapeHtml(b.ownerType) + '\', ' + parseInt(b.ownerID) + ')" title="Quick Withdrawal">' +
                            '<i class="fas fa-minus"></i>' +
                        '</button>' +
                    '</div>' +
                '</td>' +
            '</tr>';
        });

        $tbody.html(html);
    }

    /**
     * 📄 Render pagination controls for the balances table.
     *
     * @param {number} total — Total number of balance records
     * @param {number} pages — Total number of pages
     * @param {number} current — Current page number
     */
    function renderBalancePagination(total, pages, current) {
        // 📊 Update the "Showing X-Y of Z" info text
        var start = Math.min(total, (current - 1) * perPage + 1);
        var end = Math.min(total, current * perPage);
        $('#balancePaginationInfo').text('Showing ' + start + '-' + end + ' of ' + total);

        var $nav = $('#balancePagination');
        $nav.empty();

        // 🚫 No pagination needed for single page
        if (pages <= 1) { return; }

        // ⬅️ Previous page button
        $nav.append(
            '<li class="page-item ' + (current === 1 ? 'disabled' : '') + '">' +
                '<a class="page-link" href="#" onclick="loadBalances(' + (current - 1) + '); return false;">&laquo;</a>' +
            '</li>'
        );

        // 📊 Page number buttons (show max 5 around current)
        var startPage = Math.max(1, current - 2);
        var endPage = Math.min(pages, startPage + 4);
        for (var i = startPage; i <= endPage; i++) {
            $nav.append(
                '<li class="page-item ' + (i === current ? 'active' : '') + '">' +
                    '<a class="page-link" href="#" onclick="loadBalances(' + i + '); return false;">' + i + '</a>' +
                '</li>'
            );
        }

        // ➡️ Next page button
        $nav.append(
            '<li class="page-item ' + (current === pages ? 'disabled' : '') + '">' +
                '<a class="page-link" href="#" onclick="loadBalances(' + (current + 1) + '); return false;">&raquo;</a>' +
            '</li>'
        );
    }

    /**
     * 🧹 Clear all balance filters and reload the first page.
     */
    function clearBalanceFilters() {
        $('#balanceSearch').val('');
        $('#balanceOwnerType').val('all');
        loadBalances(1);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 📋 TRANSACTIONS TAB — Load and render transaction history
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 📋 Load credit transactions from the API with pagination and filters.
     * Makes a GET request to credit-actions.php with action=get_transactions.
     *
     * @param {number} page — Page number to load (1-based)
     */
    function loadTransactions(page) {
        page = page || 1;
        currentTxPage = page;

        // 🔄 Show loading overlay
        $('#transactionsLoading').show();

        // 📤 Build the request parameters
        var params = {
            action: 'get_transactions',
            page: page,
            limit: perPage,
            offset: (page - 1) * perPage,
            ownerType: $('#txOwnerType').val(),
            transactionType: $('#txTransactionType').val(),
            dateFrom: $('#txDateFrom').val(),
            dateTo: $('#txDateTo').val()
        };

        /**
         * 📡 AJAX GET request to fetch credit transactions.
         * The API returns: { success, data[], total, pages, currentPage }
         */
        $.ajax({
            url: apiUrl,
            method: 'GET',
            data: params,
            dataType: 'json',
            success: function(result) {
                if (result.success) {
                    renderTransactions(result.data || []);
                    renderTransactionPagination(result.total || 0, result.pages || 1, result.currentPage || page);
                    $('#transactionCount').text((result.total || 0) + ' transactions');
                } else {
                    showAlert(result.message || 'Failed to load transactions', 'danger');
                }
            },
            error: function(xhr, status, error) {
                showAlert('Error loading transactions: ' + escapeHtml(error), 'danger');
            },
            complete: function() {
                $('#transactionsLoading').hide();
            }
        });
    }

    /**
     * 📋 Render transaction rows into the transactions table.
     *
     * @param {Array} transactions — Array of transaction objects from the API
     */
    function renderTransactions(transactions) {
        var $tbody = $('#transactionsTableBody');

        // 📭 Handle empty state
        if (!transactions || transactions.length === 0) {
            $tbody.html(
                '<tr>' +
                    '<td colspan="7" class="text-center py-5 text-muted">' +
                        '<i class="fas fa-exchange-alt fa-3x mb-3 d-block opacity-50"></i>' +
                        '<h5>No Transactions Found</h5>' +
                        '<p>No transactions match your current filters.</p>' +
                    '</td>' +
                '</tr>'
            );
            return;
        }

        // 📋 Build HTML for each transaction row
        var html = '';
        $.each(transactions, function(index, tx) {
            /**
             * 🏷️ Determine the owner type icon for display:
             *   user    → user icon
             *   partner → handshake icon
             */
            var ownerIcon = (tx.ownerType === 'partner')
                ? '<i class="fas fa-handshake text-info me-1" title="Partner"></i>'
                : '<i class="fas fa-user text-primary me-1" title="User"></i>';

            /**
             * 💰 Determine the amount colour and prefix (+ or -).
             */
            var amt = parseFloat(tx.amount) || 0;
            var amtDisplay = (amt >= 0 ? '+' : '') + formatCurrency(amt);
            var amtCls = amountClass(amt);

            html += '<tr>' +
                '<td>' + formatDate(tx.createdAt) + '</td>' +
                '<td>' +
                    ownerIcon +
                    '<strong>' + escapeHtml(tx.ownerName || 'Unknown') + '</strong>' +
                    '<br><small class="text-muted">ID: ' + escapeHtml(tx.ownerID) + '</small>' +
                '</td>' +
                '<td>' + typeBadge(tx.transactionType) + '</td>' +
                '<td class="' + amtCls + '">' + amtDisplay + '</td>' +
                '<td class="fw-bold">' + formatCurrency(tx.balanceAfter) + '</td>' +
                '<td><small>' + escapeHtml(tx.description || '') + '</small></td>' +
                '<td><code class="small text-muted">' + escapeHtml(tx.reference || '&mdash;') + '</code></td>' +
            '</tr>';
        });

        $tbody.html(html);
    }

    /**
     * 📄 Render pagination controls for the transactions table.
     *
     * @param {number} total — Total number of transaction records
     * @param {number} pages — Total number of pages
     * @param {number} current — Current page number
     */
    function renderTransactionPagination(total, pages, current) {
        var start = Math.min(total, (current - 1) * perPage + 1);
        var end = Math.min(total, current * perPage);
        $('#txPaginationInfo').text('Showing ' + start + '-' + end + ' of ' + total);

        var $nav = $('#txPagination');
        $nav.empty();

        if (pages <= 1) { return; }

        // ⬅️ Previous
        $nav.append(
            '<li class="page-item ' + (current === 1 ? 'disabled' : '') + '">' +
                '<a class="page-link" href="#" onclick="loadTransactions(' + (current - 1) + '); return false;">&laquo;</a>' +
            '</li>'
        );

        // 📊 Page numbers
        var startPage = Math.max(1, current - 2);
        var endPage = Math.min(pages, startPage + 4);
        for (var i = startPage; i <= endPage; i++) {
            $nav.append(
                '<li class="page-item ' + (i === current ? 'active' : '') + '">' +
                    '<a class="page-link" href="#" onclick="loadTransactions(' + i + '); return false;">' + i + '</a>' +
                '</li>'
            );
        }

        // ➡️ Next
        $nav.append(
            '<li class="page-item ' + (current === pages ? 'disabled' : '') + '">' +
                '<a class="page-link" href="#" onclick="loadTransactions(' + (current + 1) + '); return false;">&raquo;</a>' +
            '</li>'
        );
    }

    /**
     * 🧹 Clear all transaction filters and reload the first page.
     */
    function clearTransactionFilters() {
        $('#txOwnerType').val('all');
        $('#txTransactionType').val('all');
        $('#txDateFrom').val('');
        $('#txDateTo').val('');
        loadTransactions(1);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ⚖️ MANUAL ADJUSTMENT — Modal and submission
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚖️ Show the Manual Adjustment modal.
     * Resets all form fields to their default values.
     */
    function showAdjustmentModal() {
        // 🧹 Reset all modal form fields
        $('#adjOwnerType').val('user');
        $('#adjOwnerID').val('');
        $('#adjAmount').val('');
        $('#adjCurrency').val('<?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?>');
        $('#adjDescription').val('');
        onAdjOwnerTypeChange();

        // 📱 Show the Bootstrap modal
        var modal = new bootstrap.Modal(document.getElementById('adjustmentModal'));
        modal.show();
    }

    /**
     * 🔄 Handle owner type change in the adjustment modal.
     * Updates the hint text to indicate whether a user ID or partner ID is expected.
     */
    function onAdjOwnerTypeChange() {
        var type = $('#adjOwnerType').val();
        if (type === 'partner') {
            $('#adjOwnerHint').text('Enter the numeric partner ID from tblPartners');
        } else {
            $('#adjOwnerHint').text('Enter the numeric user ID from tblUsers');
        }
    }

    /**
     * ⚖️ Quick deposit — pre-fills the adjustment modal for a deposit (positive amount).
     *
     * @param {string} ownerType — 'user' or 'partner'
     * @param {number} ownerID — The owner's numeric ID
     */
    function quickDeposit(ownerType, ownerID) {
        showAdjustmentModal();
        $('#adjOwnerType').val(ownerType);
        $('#adjOwnerID').val(ownerID);
        onAdjOwnerTypeChange();
        // 🎯 Focus on the amount field for quick entry
        setTimeout(function() { $('#adjAmount').focus(); }, 500);
    }

    /**
     * ⚖️ Quick withdrawal — pre-fills the adjustment modal for a withdrawal.
     *
     * @param {string} ownerType — 'user' or 'partner'
     * @param {number} ownerID — The owner's numeric ID
     */
    function quickWithdraw(ownerType, ownerID) {
        showAdjustmentModal();
        $('#adjOwnerType').val(ownerType);
        $('#adjOwnerID').val(ownerID);
        onAdjOwnerTypeChange();
        setTimeout(function() { $('#adjAmount').focus(); }, 500);
    }

    /**
     * ⚖️ Submit the manual credit adjustment via AJAX POST.
     * Validates the form fields, sends the request, and refreshes the data tables.
     *
     * @see https://api.jquery.com/jquery.ajax/ — jQuery $.ajax()
     */
    function submitAdjustment() {
        // ✅ Validate required fields
        var ownerType = $('#adjOwnerType').val();
        var ownerID = parseInt($('#adjOwnerID').val());
        var amount = parseFloat($('#adjAmount').val());
        var currency = $('#adjCurrency').val();
        var description = $.trim($('#adjDescription').val());

        // 🚫 Validate owner ID
        if (!ownerID || ownerID <= 0) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Please enter a valid Owner ID.', 'warning');
            return;
        }

        // 🚫 Validate amount (must not be zero)
        if (isNaN(amount) || amount === 0) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Please enter a non-zero amount.', 'warning');
            return;
        }

        // 🚫 Validate description
        if (!description) {
            showAlert('<i class="fas fa-exclamation-triangle me-1"></i> Please provide a description for the adjustment.', 'warning');
            return;
        }

        // 🔄 Disable the submit button to prevent double-submission
        var $btn = $('#btnSubmitAdjustment');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Processing...');

        /**
         * 📡 POST request to submit the manual credit adjustment.
         * Uses JSON content type with CSRF token in the payload.
         */
        $.ajax({
            url: apiUrl,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'adjust_balance',
                ownerType: ownerType,
                ownerID: ownerID,
                amount: amount,
                currency: currency,
                description: description,
                csrf_token: getCSRFToken()
            }),
            dataType: 'json',
            success: function(result) {
                if (result.success) {
                    // ✅ Success — close modal, refresh data, show notification
                    showAlert(
                        '<i class="fas fa-check-circle me-1"></i> Credit adjustment applied successfully. ' +
                        'New balance: <strong>' + formatCurrency(result.newBalance || 0) + '</strong>',
                        'success'
                    );
                    bootstrap.Modal.getInstance(document.getElementById('adjustmentModal')).hide();

                    // 🔄 Refresh both tabs and stats
                    loadBalances(currentBalancePage);
                    loadTransactions(currentTxPage);
                    refreshStats();
                } else {
                    showAlert(result.message || 'Failed to apply adjustment', 'danger');
                }
            },
            error: function(xhr, status, error) {
                showAlert('Error submitting adjustment: ' + escapeHtml(error), 'danger');
            },
            complete: function() {
                // 🔄 Re-enable the submit button
                $btn.prop('disabled', false).html('<i class="fas fa-check me-1"></i>Submit Adjustment');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // 📜 TRANSACTION HISTORY MODAL — Per-owner detailed history
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 📜 View transaction history for a specific owner.
     * Opens the history modal and loads the first batch of transactions.
     *
     * @param {string} ownerType — 'user' or 'partner'
     * @param {number} ownerID — The owner's numeric ID
     * @param {string} ownerName — Display name for the modal header
     */
    function viewHistory(ownerType, ownerID, ownerName) {
        // 🔧 Store the current owner context for pagination
        historyOwnerType = ownerType;
        historyOwnerID = ownerID;
        historyOffset = 0;

        // 📊 Set the owner info in the modal
        var typeLabel = (ownerType === 'partner') ? 'Partner' : 'User';
        $('#historyOwnerInfo').html(
            '<span class="badge ' + (ownerType === 'partner' ? 'bg-info' : 'bg-primary') + ' me-1">' +
                typeLabel +
            '</span> ' +
            '<strong>' + escapeHtml(ownerName) + '</strong>' +
            ' <small class="text-muted">(ID: ' + escapeHtml(ownerID) + ')</small>'
        );
        $('#historyCurrentBalance').text('Loading...');

        // 🧹 Clear previous history rows
        $('#historyTableBody').html(
            '<tr><td colspan="5" class="text-center py-4 text-muted">' +
                '<i class="fas fa-spinner fa-spin me-1"></i>Loading history...' +
            '</td></tr>'
        );
        $('#btnLoadMoreHistory').hide();

        // 📱 Show the modal
        var modal = new bootstrap.Modal(document.getElementById('historyModal'));
        modal.show();

        // 📡 Load the first batch of history
        loadHistoryData(false);
    }

    /**
     * 📜 Load transaction history data for the currently selected owner.
     *
     * @param {boolean} append — If true, append to existing rows; if false, replace
     */
    function loadHistoryData(append) {
        $.ajax({
            url: apiUrl,
            method: 'GET',
            data: {
                action: 'get_transactions',
                ownerType: historyOwnerType,
                ownerID: historyOwnerID,
                limit: historyLimit,
                offset: historyOffset
            },
            dataType: 'json',
            success: function(result) {
                if (result.success) {
                    renderHistoryRows(result.data || [], append);

                    // 💰 Update the current balance display (from the first transaction's balanceAfter, or from stats)
                    if (!append && result.data && result.data.length > 0) {
                        $('#historyCurrentBalance').text(formatCurrency(result.data[0].balanceAfter));
                    }

                    // 📄 Show/hide "Load More" button
                    var totalLoaded = historyOffset + (result.data || []).length;
                    if ((result.data || []).length >= historyLimit && totalLoaded < (result.total || totalLoaded + 1)) {
                        $('#btnLoadMoreHistory').show();
                    } else {
                        $('#btnLoadMoreHistory').hide();
                    }

                    // 📊 Update offset for next request
                    historyOffset = totalLoaded;
                } else {
                    showAlert(result.message || 'Failed to load transaction history', 'danger');
                }
            },
            error: function(xhr, status, error) {
                showAlert('Error loading history: ' + escapeHtml(error), 'danger');
            }
        });
    }

    /**
     * 📋 Render transaction history rows within the modal.
     *
     * @param {Array} transactions — Array of transaction objects
     * @param {boolean} append — If true, append to existing rows
     */
    function renderHistoryRows(transactions, append) {
        var $tbody = $('#historyTableBody');

        // 📭 Handle empty state
        if (!transactions || transactions.length === 0) {
            if (!append) {
                $tbody.html(
                    '<tr><td colspan="5" class="text-center py-4 text-muted">' +
                        '<i class="fas fa-history fa-2x mb-2 d-block opacity-50"></i>' +
                        'No transaction history found for this account.' +
                    '</td></tr>'
                );
            }
            return;
        }

        // 📋 Build the HTML for each history row
        var html = '';
        $.each(transactions, function(index, tx) {
            var amt = parseFloat(tx.amount) || 0;
            var amtDisplay = (amt >= 0 ? '+' : '') + formatCurrency(amt);
            var amtCls = amountClass(amt);

            html += '<tr>' +
                '<td><small>' + formatDate(tx.createdAt) + '</small></td>' +
                '<td>' + typeBadge(tx.transactionType) + '</td>' +
                '<td class="' + amtCls + '">' + amtDisplay + '</td>' +
                '<td class="fw-bold">' + formatCurrency(tx.balanceAfter) + '</td>' +
                '<td><small>' + escapeHtml(tx.description || '') + '</small></td>' +
            '</tr>';
        });

        if (append) {
            // 📋 Append to existing rows
            $tbody.append(html);
        } else {
            // 📋 Replace all rows
            $tbody.html(html);
        }
    }

    /**
     * 📜 Load more history rows (pagination within the modal).
     */
    function loadMoreHistory() {
        loadHistoryData(true);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 📊 STATS REFRESH — Reload the summary statistics via AJAX
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 📊 Refresh the stats cards by fetching updated data from the API.
     * Called after adjustments, deposits, or withdrawals to keep stats current.
     */
    function refreshStats() {
        $.ajax({
            url: apiUrl,
            method: 'GET',
            data: { action: 'get_credit_stats' },
            dataType: 'json',
            success: function(result) {
                if (result.success && result.data) {
                    var d = result.data;
                    // 💰 Update the stat card values
                    $('#statTotalBalance').text(formatCurrency(d.totalBalance || 0));
                    $('#statTotalDeposits').text(formatCurrency(d.totalDeposits || 0));
                    $('#statTotalWithdrawals').text(formatCurrency(d.totalWithdrawals || 0));
                    $('#statActiveAccounts').text(parseInt(d.activeAccounts || 0).toLocaleString());
                }
            }
            // 🤫 Silently ignore errors for stats refresh — non-critical
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // 🚀 INITIAL LOAD — Load all data when the page is ready
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 🎯 Initialise the page by loading both data tabs.
     * @see https://api.jquery.com/ready/ — jQuery document ready
     */
    $(document).ready(function() {
        // 📋 Load the initial data for both tabs
        loadBalances(1);
        loadTransactions(1);

        // ⌨️ Search on Enter key in the balance search field
        $('#balanceSearch').on('keypress', function(e) {
            if (e.which === 13) {
                loadBalances(1);
            }
        });

        // 📋 Reload transactions when the Transactions tab is shown
        // This ensures fresh data when switching tabs
        $('button[data-bs-target="#pane-transactions"]').on('shown.bs.tab', function() {
            loadTransactions(currentTxPage);
        });
    });
    </script>
</body>
</html>
