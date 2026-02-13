<?php
/**
 * 💰 Partner Credit Balance Management Page
 *
 * Displays the partner's current credit balance, provides a top-up mechanism,
 * and shows a paginated transaction history. Credits can be used for paying
 * SIGNula service fees, subscriptions, and other platform charges.
 *
 * Features:
 *   - Current balance display card with currency
 *   - "Top Up Credits" modal with amount input and payment method selection
 *   - Transaction history table with type badges and running balance
 *   - Pagination for transaction history (loaded via AJAX)
 *   - Transaction type badges: deposit=success, withdrawal=warning,
 *     payment=primary, refund=info, adjustment=secondary,
 *     remittance=dark, promotional=purple
 *
 * 🔒 Access: Finance role or higher (finance, support, developer, admin, root-admin, super-admin)
 * @see AccessControl::canViewPartnerFinancials() for role hierarchy
 * @see _database/migrations/012_payment_expansion.sql (tblCreditBalances, tblCreditTransactions)
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
 */
if (empty($memberships)) {
    header('Location: /partners/register.php');
    exit;
}

/**
 * 🎯 Determine which partner is currently selected.
 * Priority: GET parameter > first available membership.
 */
$selectedPartnerID = (int)($_GET['partner'] ?? array_key_first($memberships));

/**
 * 📦 Get the membership record for the selected partner.
 */
$partner = $memberships[$selectedPartnerID] ?? reset($memberships);


// ============================================================================
// 💰 FINANCE ACCESS VERIFICATION
// ============================================================================

/**
 * 🔒 Verify that the current user has finance role or higher for this partner.
 * Credits and balance data are financial records requiring elevated access.
 *
 * @see AccessControl::canViewPartnerFinancials()
 * @see AccessControl::ROLE_HIERARCHY
 */
if (!$accessControl->canViewPartnerFinancials($selectedPartnerID)) {
    http_response_code(403);
    die('Finance access or higher is required to view credit balance for this partner.');
}


// ============================================================================
// 🗄️ FETCH PARTNER DETAILS
// ============================================================================

/**
 * 📊 Retrieve full partner record from tblPartners.
 * Includes the quick-access creditBalance field added by migration 012.
 *
 * @see _database/migrations/012_payment_expansion.sql (ALTER TABLE tblPartners)
 */
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE partnerID = ?");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerDetails = $stmt->get_result()->fetch_assoc();


// ============================================================================
// 💰 FETCH CREDIT BALANCE
// ============================================================================

/**
 * 📊 Retrieve the partner's credit balance from tblCreditBalances.
 *
 * Partner-level SIGNula balances use:
 *   ownerType = 'partner'
 *   ownerID   = partnerID
 *   partnerID = NULL (SIGNula-level balance, not scoped to another partner)
 *
 * If no balance record exists yet, the balance is considered 0.00.
 *
 * @see _database/migrations/012_payment_expansion.sql (tblCreditBalances definition)
 */
$stmt = $db->prepare("
    SELECT balanceID, balance, currency, lastTransactionAt
    FROM tblCreditBalances
    WHERE ownerType = 'partner'
      AND ownerID = ?
      AND partnerID IS NULL
    LIMIT 1
");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$balanceRecord = $stmt->get_result()->fetch_assoc();

/**
 * 💵 Extract balance values with safe defaults.
 * If no balance record exists, default to 0.00 GBP.
 */
$currentBalance    = (float)($balanceRecord['balance'] ?? 0.00);
$balanceCurrency   = $balanceRecord['currency'] ?? 'GBP';
$balanceID         = $balanceRecord['balanceID'] ?? null;
$lastTransactionAt = $balanceRecord['lastTransactionAt'] ?? null;


// ============================================================================
// 📜 FETCH RECENT TRANSACTION HISTORY (Server-Side for Initial Load)
// ============================================================================

/**
 * 📋 Load the most recent credit transactions for the initial page render.
 * Subsequent pages are loaded via AJAX calls to credit-actions.php API.
 *
 * Joins are not needed as tblCreditTransactions contains all required data.
 * Ordered by most recent first with a configurable per-page limit.
 *
 * @see _database/migrations/012_payment_expansion.sql (tblCreditTransactions definition)
 */
$txnPerPage = 25;
$transactions = [];
$totalTransactions = 0;

if ($balanceID) {
    // 📊 Get total transaction count for pagination
    $stmt = $db->prepare("
        SELECT COUNT(*) AS total
        FROM tblCreditTransactions
        WHERE balanceID = ?
    ");
    $stmt->bind_param('i', $balanceID);
    $stmt->execute();
    $totalTransactions = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    // 📋 Fetch the first page of transactions
    $stmt = $db->prepare("
        SELECT
            creditTransactionID,
            type,
            amount,
            balanceBefore,
            balanceAfter,
            currency,
            referenceType,
            referenceID,
            description,
            performedBy,
            ipAddress,
            createdAt
        FROM tblCreditTransactions
        WHERE balanceID = ?
        ORDER BY createdAt DESC
        LIMIT ?
    ");
    $stmt->bind_param('ii', $balanceID, $txnPerPage);
    $stmt->execute();
    $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}


// ============================================================================
// 💳 FETCH CREDIT SYSTEM SETTINGS
// ============================================================================

/**
 * ⚙️ Retrieve credit system configuration from tblSettings.
 * These settings control minimum top-up amount and maximum balance.
 *
 * @see _database/migrations/012_payment_expansion.sql (credit settings inserts)
 */
$stmt = $db->prepare("
    SELECT settingKey, settingValue
    FROM tblSettings
    WHERE settingKey IN ('payment.credits.min_topup', 'payment.credits.max_balance', 'payment.credits.enabled')
");
$stmt->execute();
$settingsResult = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/**
 * 📦 Map settings into an associative array for easy access.
 */
$creditSettings = [];
foreach ($settingsResult as $setting) {
    $creditSettings[$setting['settingKey']] = $setting['settingValue'];
}

$minTopup         = (float)($creditSettings['payment.credits.min_topup'] ?? 10.00);
$maxBalance       = (float)($creditSettings['payment.credits.max_balance'] ?? 50000.00);
$creditsEnabled   = (int)($creditSettings['payment.credits.enabled'] ?? 1);


// ============================================================================
// 🏷️ HELPER: Transaction Type Badge Mapping
// ============================================================================

/**
 * 🏷️ Map transaction type to Bootstrap badge class and icon.
 *
 * @param string $type Transaction type from tblCreditTransactions.type ENUM
 * @return array ['class' => string, 'icon' => string]
 * @see https://getbootstrap.com/docs/5.3/components/badge/
 */
function getTransactionTypeBadge(string $type): array
{
    return match ($type) {
        'deposit'      => ['class' => 'bg-success',   'icon' => 'fas fa-arrow-down'],
        'withdrawal'   => ['class' => 'bg-warning text-dark', 'icon' => 'fas fa-arrow-up'],
        'payment'      => ['class' => 'bg-primary',   'icon' => 'fas fa-credit-card'],
        'refund'       => ['class' => 'bg-info',      'icon' => 'fas fa-undo'],
        'adjustment'   => ['class' => 'bg-secondary',  'icon' => 'fas fa-sliders-h'],
        'remittance'   => ['class' => 'bg-dark',      'icon' => 'fas fa-money-bill-wave'],
        'promotional'  => ['class' => 'bg-purple text-white', 'icon' => 'fas fa-gift'],
        default        => ['class' => 'bg-secondary',  'icon' => 'fas fa-exchange-alt'],
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
    <title>Credit Balance - <?php echo htmlspecialchars($partner['companyName']); ?> - SIGNula</title>

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

        /* 🟢 Gradient header — green theme for financial/credits context */
        .credits-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        /* 💰 Balance display card with prominent styling */
        .balance-card {
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .balance-card:hover {
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.15);
        }

        /* 💵 Large balance amount display */
        .balance-amount {
            font-size: 2.5rem;
            font-weight: 700;
            font-family: 'Courier New', Courier, monospace;
            color: #198754;
        }

        /* 🏷️ Currency label next to balance */
        .balance-currency {
            font-size: 1.2rem;
            color: #6c757d;
            font-weight: 600;
        }

        /* 📊 Transaction table amount column */
        .text-amount {
            font-family: 'Courier New', Courier, monospace;
            font-weight: 600;
        }

        /* ➕ Positive amounts in green */
        .amount-positive {
            color: #198754;
        }

        /* ➖ Negative amounts in red */
        .amount-negative {
            color: #dc3545;
        }

        /* 🟣 Custom purple badge for promotional transactions */
        .bg-purple {
            background-color: #6f42c1 !important;
        }

        /* 📐 Partner selector in header */
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

        /* 🔄 Loading overlay for AJAX operations */
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

        /* 💳 Payment method radio button cards in top-up modal */
        .payment-method-option {
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .payment-method-option:hover {
            border-color: #0d6efd;
            background-color: #f0f4ff;
        }
        .payment-method-option.selected {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }
        .payment-method-option input[type="radio"] {
            display: none;
        }

        /* 🔢 Top-up amount input with large font */
        .topup-amount-input {
            font-size: 1.5rem;
            font-weight: 700;
            text-align: center;
            padding: 0.75rem;
        }

        /* 📊 Transaction history card */
        .transaction-card {
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        /* 📱 Responsive adjustments */
        @media (max-width: 768px) {
            .balance-amount {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">

        <!-- ================================================================ -->
        <!-- 📋 PAGE HEADER with gradient background and partner context      -->
        <!-- ================================================================ -->
        <div class="credits-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <!-- 🔙 Back navigation to partner admin dashboard -->
                    <a href="index.php?partner=<?php echo $selectedPartnerID; ?>"
                       class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                        <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
                    </a>
                    <h1><i class="fas fa-wallet me-2"></i>Credit Balance</h1>

                    <?php if (count($memberships) > 1): ?>
                    <!-- 🏢 Partner selector dropdown -->
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
                    <span class="badge bg-white text-success fs-6 px-3 py-2">
                        <?php echo $partner['isRootAdmin'] ? '&#x1F451; Root Admin' : strtoupper($partner['role']); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- ⚠️ ALERT CONTAINER for dynamic AJAX feedback messages           -->
        <!-- ================================================================ -->
        <div id="alertContainer" role="alert" aria-live="polite"></div>

        <?php if (!$creditsEnabled): ?>
        <!-- ⚠️ Credit system disabled warning -->
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Credit system is currently disabled.</strong>
            The credit/balance pre-loading feature has been disabled by a system administrator.
            Contact support for more information.
        </div>
        <?php endif; ?>

        <!-- ================================================================ -->
        <!-- 💰 BALANCE DISPLAY CARD                                          -->
        <!-- Shows current credit balance with currency and top-up button     -->
        <!-- ================================================================ -->
        <div class="row mb-4">
            <div class="col-lg-6 col-md-8 mx-auto">
                <div class="card balance-card">
                    <div class="card-body text-center py-5">
                        <!-- 💰 Wallet icon -->
                        <div class="mb-3">
                            <i class="fas fa-wallet fa-3x text-success"></i>
                        </div>

                        <!-- 📊 Current Balance Label -->
                        <p class="text-muted mb-2 fs-5">Current Balance</p>

                        <!-- 💵 Balance Amount -->
                        <div class="mb-2">
                            <span class="balance-currency"><?php echo htmlspecialchars($balanceCurrency); ?></span>
                            <span class="balance-amount" id="currentBalanceDisplay">
                                <?php echo number_format($currentBalance, 2); ?>
                            </span>
                        </div>

                        <!-- 📅 Last Transaction Date -->
                        <?php if ($lastTransactionAt): ?>
                        <p class="text-muted small mb-3">
                            <i class="fas fa-clock me-1"></i>
                            Last transaction: <?php echo date('M j, Y \a\t g:i A', strtotime($lastTransactionAt)); ?>
                        </p>
                        <?php else: ?>
                        <p class="text-muted small mb-3">
                            <i class="fas fa-info-circle me-1"></i>
                            No transactions yet
                        </p>
                        <?php endif; ?>

                        <!-- ➕ Top Up Button -->
                        <?php if ($creditsEnabled): ?>
                        <button class="btn btn-success btn-lg px-5"
                                data-bs-toggle="modal"
                                data-bs-target="#topUpModal"
                                <?php echo $accessControl->canManagePartnerPayments($selectedPartnerID) ? '' : 'disabled title="Payment management access required"'; ?>>
                            <i class="fas fa-plus-circle me-2"></i>Top Up Credits
                        </button>
                        <?php endif; ?>

                        <!-- ℹ️ Balance limits info -->
                        <div class="mt-3">
                            <small class="text-muted">
                                Min top-up: <?php echo htmlspecialchars($balanceCurrency); ?> <?php echo number_format($minTopup, 2); ?>
                                &middot;
                                Max balance: <?php echo htmlspecialchars($balanceCurrency); ?> <?php echo number_format($maxBalance, 2); ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- 📜 TRANSACTION HISTORY TABLE                                     -->
        <!-- Paginated list of all credit transactions for this partner       -->
        <!-- ================================================================ -->
        <div class="card transaction-card position-relative">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Transaction History</h5>
                <small class="text-muted" id="txnCountLabel">
                    Showing <?php echo count($transactions); ?> of <?php echo $totalTransactions; ?> transactions
                </small>
            </div>

            <!-- 🔄 Loading overlay (hidden by default, shown during AJAX) -->
            <div class="loading-overlay d-none" id="loadingOverlay">
                <div class="text-center">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading transactions...</p>
                </div>
            </div>

            <div class="card-body p-0">
                <?php if (empty($transactions)): ?>
                <!-- 📭 Empty state when no transactions exist -->
                <div class="text-center py-5">
                    <i class="fas fa-exchange-alt fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">No Transactions Yet</h4>
                    <p class="text-muted">
                        Credit transactions will appear here once you top up your balance
                        or credits are applied to your account.
                    </p>
                </div>
                <?php else: ?>
                <!-- 📋 Transaction data table -->
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="transactionsTable">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Type</th>
                                <th scope="col" class="text-end">Amount</th>
                                <th scope="col" class="text-end">Balance After</th>
                                <th scope="col">Description</th>
                                <th scope="col">Reference</th>
                            </tr>
                        </thead>
                        <tbody id="transactionsTableBody">
                            <?php foreach ($transactions as $txn): ?>
                            <?php
                                /**
                                 * 🏷️ Determine transaction type badge styling.
                                 */
                                $typeBadge = getTransactionTypeBadge($txn['type']);

                                /**
                                 * 💰 Determine if the amount is positive or negative.
                                 * Positive = deposit, refund, promotional
                                 * Negative = withdrawal, payment
                                 */
                                $amount = (float)$txn['amount'];
                                $isPositive = ($amount >= 0);
                                $amountClass = $isPositive ? 'amount-positive' : 'amount-negative';
                                $amountPrefix = $isPositive ? '+' : '';
                            ?>
                            <tr>
                                <!-- 📅 Transaction Date -->
                                <td>
                                    <div><?php echo date('M j, Y', strtotime($txn['createdAt'])); ?></div>
                                    <small class="text-muted"><?php echo date('g:i A', strtotime($txn['createdAt'])); ?></small>
                                </td>

                                <!-- 🏷️ Transaction Type Badge -->
                                <td>
                                    <span class="badge <?php echo $typeBadge['class']; ?>">
                                        <i class="<?php echo $typeBadge['icon']; ?> me-1"></i>
                                        <?php echo ucfirst(htmlspecialchars($txn['type'])); ?>
                                    </span>
                                </td>

                                <!-- 💰 Amount (+/-) -->
                                <td class="text-end text-amount <?php echo $amountClass; ?>">
                                    <?php echo $amountPrefix; ?><?php echo htmlspecialchars($txn['currency']); ?>
                                    <?php echo number_format(abs($amount), 2); ?>
                                </td>

                                <!-- 💵 Balance After Transaction -->
                                <td class="text-end text-amount">
                                    <?php echo htmlspecialchars($txn['currency']); ?>
                                    <?php echo number_format((float)$txn['balanceAfter'], 2); ?>
                                </td>

                                <!-- 📝 Description -->
                                <td>
                                    <?php if ($txn['description']): ?>
                                        <?php echo htmlspecialchars($txn['description']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 🔗 Reference (Type + ID) -->
                                <td>
                                    <?php if ($txn['referenceType']): ?>
                                        <small class="text-muted">
                                            <?php echo ucfirst(htmlspecialchars($txn['referenceType'])); ?>
                                            <?php if ($txn['referenceID']): ?>
                                                #<?php echo (int)$txn['referenceID']; ?>
                                            <?php endif; ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($totalTransactions > $txnPerPage): ?>
            <!-- 📖 Pagination footer -->
            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    Page <span id="currentPage">1</span> of
                    <span id="totalPages"><?php echo ceil($totalTransactions / $txnPerPage); ?></span>
                </small>
                <nav aria-label="Transaction pagination">
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
        <!-- ℹ️ INFORMATION CARD                                               -->
        <!-- ================================================================ -->
        <div class="card mt-4">
            <div class="card-body">
                <h6 class="mb-3"><i class="fas fa-info-circle me-2"></i>About Credits</h6>
                <div class="row">
                    <div class="col-md-4">
                        <h6><i class="fas fa-plus-circle text-success me-1"></i>Top Up</h6>
                        <p class="text-muted small">
                            Add funds to your credit balance using a card (via Stripe),
                            PayPal, or cryptocurrency. Credits are added instantly.
                        </p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-credit-card text-primary me-1"></i>Usage</h6>
                        <p class="text-muted small">
                            Credits are automatically applied to your SIGNula subscription
                            fees, service charges, and other platform costs.
                        </p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-money-bill-wave text-dark me-1"></i>Remittances</h6>
                        <p class="text-muted small">
                            If you collect payments through SIGNula, net earnings (after
                            service fees) can be credited to your balance or paid out.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.container-fluid -->


    <!-- ==================================================================== -->
    <!-- ➕ TOP UP CREDITS MODAL                                               -->
    <!-- Modal form for adding credits to the partner balance                  -->
    <!-- @see https://getbootstrap.com/docs/5.3/components/modal/              -->
    <!-- ==================================================================== -->
    <div class="modal fade" id="topUpModal" tabindex="-1" aria-labelledby="topUpModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="topUpModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>Top Up Credits
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="topUpForm" novalidate>
                        <!-- 💵 Amount Input -->
                        <div class="mb-4">
                            <label for="topUpAmount" class="form-label fw-semibold">Amount (<?php echo htmlspecialchars($balanceCurrency); ?>)</label>
                            <div class="input-group">
                                <span class="input-group-text fs-5"><?php echo htmlspecialchars($balanceCurrency); ?></span>
                                <input type="number"
                                       class="form-control topup-amount-input"
                                       id="topUpAmount"
                                       min="<?php echo $minTopup; ?>"
                                       max="<?php echo ($maxBalance - $currentBalance); ?>"
                                       step="0.01"
                                       placeholder="0.00"
                                       required
                                       aria-describedby="topUpAmountHelp">
                            </div>
                            <div id="topUpAmountHelp" class="form-text">
                                Minimum: <?php echo htmlspecialchars($balanceCurrency); ?> <?php echo number_format($minTopup, 2); ?>
                                &middot;
                                Maximum for this top-up: <?php echo htmlspecialchars($balanceCurrency); ?> <?php echo number_format(max(0, $maxBalance - $currentBalance), 2); ?>
                            </div>
                            <div class="invalid-feedback" id="amountError">
                                Please enter a valid amount.
                            </div>
                        </div>

                        <!-- 🏷️ Quick Amount Buttons -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Quick Select</label>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-outline-success" onclick="setTopUpAmount(25)">
                                    <?php echo htmlspecialchars($balanceCurrency); ?> 25
                                </button>
                                <button type="button" class="btn btn-outline-success" onclick="setTopUpAmount(50)">
                                    <?php echo htmlspecialchars($balanceCurrency); ?> 50
                                </button>
                                <button type="button" class="btn btn-outline-success" onclick="setTopUpAmount(100)">
                                    <?php echo htmlspecialchars($balanceCurrency); ?> 100
                                </button>
                                <button type="button" class="btn btn-outline-success" onclick="setTopUpAmount(250)">
                                    <?php echo htmlspecialchars($balanceCurrency); ?> 250
                                </button>
                                <button type="button" class="btn btn-outline-success" onclick="setTopUpAmount(500)">
                                    <?php echo htmlspecialchars($balanceCurrency); ?> 500
                                </button>
                            </div>
                        </div>

                        <!-- 💳 Payment Method Selection -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Payment Method</label>

                            <!-- 💳 Card via Stripe -->
                            <label class="payment-method-option d-flex align-items-center mb-2 selected" onclick="selectPaymentMethod(this, 'stripe')">
                                <input type="radio" name="paymentMethod" value="stripe" checked>
                                <i class="fab fa-cc-stripe fa-2x text-primary me-3"></i>
                                <div>
                                    <strong>Card (via Stripe)</strong>
                                    <br>
                                    <small class="text-muted">Credit or debit card</small>
                                </div>
                            </label>

                            <!-- 💸 PayPal -->
                            <label class="payment-method-option d-flex align-items-center mb-2" onclick="selectPaymentMethod(this, 'paypal')">
                                <input type="radio" name="paymentMethod" value="paypal">
                                <i class="fab fa-paypal fa-2x text-primary me-3"></i>
                                <div>
                                    <strong>PayPal</strong>
                                    <br>
                                    <small class="text-muted">Pay with your PayPal account</small>
                                </div>
                            </label>

                            <!-- 🪙 Cryptocurrency -->
                            <label class="payment-method-option d-flex align-items-center" onclick="selectPaymentMethod(this, 'crypto')">
                                <input type="radio" name="paymentMethod" value="crypto">
                                <i class="fab fa-bitcoin fa-2x text-warning me-3"></i>
                                <div>
                                    <strong>Cryptocurrency</strong>
                                    <br>
                                    <small class="text-muted">BTC, ETH, USDT, USDC and more</small>
                                </div>
                            </label>
                        </div>

                        <!-- ℹ️ Info notice -->
                        <div class="alert alert-info small mb-0">
                            <i class="fas fa-info-circle me-1"></i>
                            Credits are non-refundable and will be applied to your next billing cycle.
                            You will be redirected to the payment provider to complete the transaction.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-success" id="confirmTopUpBtn" onclick="initiateTopUp()">
                        <i class="fas fa-plus-circle me-1"></i>Top Up Credits
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

        /** @type {number|null} Balance record ID (null if no balance exists yet) */
        const balanceID = <?php echo $balanceID ? (int)$balanceID : 'null'; ?>;

        /** @type {string} Balance currency code */
        const currency = '<?php echo htmlspecialchars($balanceCurrency); ?>';

        /** @type {number} Minimum top-up amount */
        const minTopup = <?php echo $minTopup; ?>;

        /** @type {number} Maximum balance allowed */
        const maxBalance = <?php echo $maxBalance; ?>;

        /** @type {number} Current balance (used for max top-up calculation) */
        let currentBalance = <?php echo $currentBalance; ?>;

        /** @type {number} Current pagination page for transactions */
        let currentPage = 1;

        /** @type {number} Total transaction pages */
        let totalPages = <?php echo $totalTransactions > 0 ? ceil($totalTransactions / $txnPerPage) : 1; ?>;

        /** @type {number} Transactions per page */
        const txnPerPage = <?php echo $txnPerPage; ?>;

        /** @type {string} Currently selected payment method */
        let selectedPaymentMethod = 'stripe';

        // 📦 Bootstrap modal instance
        const topUpModal = new bootstrap.Modal(document.getElementById('topUpModal'));


        // ====================================================================
        // 🔔 ALERT SYSTEM
        // ====================================================================

        /**
         * 📢 Display a dismissible alert message.
         *
         * @param {string} message - The message text to display
         * @param {string} [type='info'] - Bootstrap alert type
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
        // ➕ TOP UP FUNCTIONS
        // ====================================================================

        /**
         * 🔢 Set the top-up amount from a quick-select button.
         *
         * @param {number} amount - The amount to set
         */
        function setTopUpAmount(amount) {
            document.getElementById('topUpAmount').value = amount.toFixed(2);

            // 🔄 Clear any previous validation errors
            document.getElementById('topUpAmount').classList.remove('is-invalid');
        }

        /**
         * 💳 Handle payment method selection in the top-up modal.
         *
         * Updates the visual state of payment method options and stores
         * the selected method for the top-up API call.
         *
         * @param {HTMLElement} element - The clicked payment method option
         * @param {string} method - The payment method identifier (stripe, paypal, crypto)
         */
        function selectPaymentMethod(element, method) {
            // 🔄 Deselect all options
            document.querySelectorAll('.payment-method-option').forEach(el => {
                el.classList.remove('selected');
            });

            // ✅ Select the clicked option
            element.classList.add('selected');
            element.querySelector('input[type="radio"]').checked = true;
            selectedPaymentMethod = method;
        }

        /**
         * 💰 Initiate the credit top-up process.
         *
         * Validates the amount, then sends a POST request to the
         * credit-actions.php API to create a pending payment.
         * On success, the user would be redirected to the payment provider.
         */
        async function initiateTopUp() {
            // 📋 Get and validate the amount
            const amountInput = document.getElementById('topUpAmount');
            const amount = parseFloat(amountInput.value);

            // 🛡️ Validate amount
            if (isNaN(amount) || amount < minTopup) {
                amountInput.classList.add('is-invalid');
                document.getElementById('amountError').textContent =
                    `Minimum top-up amount is ${currency} ${minTopup.toFixed(2)}`;
                return;
            }

            // 🛡️ Check maximum balance limit
            const maxTopUp = maxBalance - currentBalance;
            if (amount > maxTopUp) {
                amountInput.classList.add('is-invalid');
                document.getElementById('amountError').textContent =
                    `Maximum top-up is ${currency} ${maxTopUp.toFixed(2)} (balance limit: ${currency} ${maxBalance.toFixed(2)})`;
                return;
            }

            // 🔄 Clear validation errors
            amountInput.classList.remove('is-invalid');

            // 🔄 Disable button during request
            const btn = document.getElementById('confirmTopUpBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Processing...';

            try {
                // 📡 Send top-up request to the credit-actions API
                const response = await fetch('../api/credit-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'topup_credits',
                        partnerID: partnerID,
                        amount: amount,
                        paymentMethod: selectedPaymentMethod,
                        csrf_token: csrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    // ✅ Success — close modal and show confirmation
                    topUpModal.hide();

                    if (result.redirectUrl) {
                        // 🔗 Redirect to payment provider
                        showAlert(
                            '<i class="fas fa-external-link-alt me-1"></i>Redirecting to payment provider...',
                            'info'
                        );
                        setTimeout(() => {
                            window.location.href = result.redirectUrl;
                        }, 1000);
                    } else {
                        // ✅ Payment processed immediately (e.g., from existing balance)
                        showAlert(
                            `<i class="fas fa-check-circle me-1"></i>Top-up of ${currency} ${amount.toFixed(2)} initiated successfully.`,
                            'success'
                        );

                        // 🔄 Reload to show updated balance
                        setTimeout(() => { location.reload(); }, 2000);
                    }
                } else {
                    showAlert(
                        `<i class="fas fa-exclamation-circle me-1"></i>${result.message || 'Failed to process top-up.'}`,
                        'danger'
                    );
                }
            } catch (error) {
                // 🐛 Network error handling
                console.error('Error initiating top-up:', error);
                showAlert(
                    '<i class="fas fa-exclamation-circle me-1"></i>Unable to process top-up. Please check your connection and try again.',
                    'danger'
                );
            } finally {
                // 🔄 Re-enable button
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-plus-circle me-1"></i>Top Up Credits';
            }
        }


        // ====================================================================
        // 📖 PAGINATION
        // ====================================================================

        /**
         * 📖 Navigate to the next/previous page of transactions.
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
            fetchTransactions(currentPage);
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
         * 📡 Fetch transaction history data from the credit-actions API.
         *
         * @param {number} page - Page number to fetch
         */
        async function fetchTransactions(page) {
            // 🔄 Show loading overlay
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) { overlay.classList.remove('d-none'); }

            try {
                const params = new URLSearchParams({
                    action: 'get_transactions',
                    partnerID: partnerID,
                    page: page,
                    limit: txnPerPage
                });

                const response = await fetch(`../api/credit-actions.php?${params.toString()}`);
                const result = await response.json();

                if (result.success) {
                    renderTransactionTable(result.data || []);
                    updatePagination(result.currentPage || page, result.pages || 1);

                    // 📊 Update count label
                    const countLabel = document.getElementById('txnCountLabel');
                    if (countLabel) {
                        countLabel.textContent = `Showing ${(result.data || []).length} of ${result.total || 0} transactions`;
                    }
                } else {
                    showAlert(result.message || 'Failed to load transactions.', 'danger');
                }
            } catch (error) {
                console.error('Error fetching transactions:', error);
                showAlert('Unable to load transactions. Please try again.', 'danger');
            } finally {
                if (overlay) { overlay.classList.add('d-none'); }
            }
        }


        // ====================================================================
        // 🖌️ TABLE RENDERING
        // ====================================================================

        /**
         * 🖌️ Render the transaction table body with AJAX-fetched data.
         *
         * @param {Array<Object>} transactions - Array of transaction objects
         */
        function renderTransactionTable(transactions) {
            const tbody = document.getElementById('transactionsTableBody');
            if (!tbody) { return; }

            if (transactions.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" class="text-center py-4">
                            <i class="fas fa-search fa-2x text-muted mb-2"></i>
                            <p class="text-muted mb-0">No transactions found for this page.</p>
                        </td>
                    </tr>
                `;
                return;
            }

            // 🏷️ Type-to-badge mapping for JavaScript rendering
            const typeBadgeMap = {
                'deposit':     { class: 'bg-success', icon: 'fas fa-arrow-down' },
                'withdrawal':  { class: 'bg-warning text-dark', icon: 'fas fa-arrow-up' },
                'payment':     { class: 'bg-primary', icon: 'fas fa-credit-card' },
                'refund':      { class: 'bg-info', icon: 'fas fa-undo' },
                'adjustment':  { class: 'bg-secondary', icon: 'fas fa-sliders-h' },
                'remittance':  { class: 'bg-dark', icon: 'fas fa-money-bill-wave' },
                'promotional': { class: 'bg-purple text-white', icon: 'fas fa-gift' }
            };

            tbody.innerHTML = transactions.map(txn => {
                const badge = typeBadgeMap[txn.type] || { class: 'bg-secondary', icon: 'fas fa-exchange-alt' };
                const amount = parseFloat(txn.amount || 0);
                const isPositive = amount >= 0;
                const amountClass = isPositive ? 'amount-positive' : 'amount-negative';
                const amountPrefix = isPositive ? '+' : '';
                const balanceAfter = parseFloat(txn.balanceAfter || 0);

                // 📅 Format date
                const dateObj = new Date(txn.createdAt);
                const dateStr = dateObj.toLocaleDateString('en-GB', { month: 'short', day: 'numeric', year: 'numeric' });
                const timeStr = dateObj.toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true });

                // 🔗 Reference
                const refStr = txn.referenceType
                    ? `<small class="text-muted">${capitalize(txn.referenceType)}${txn.referenceID ? ' #' + txn.referenceID : ''}</small>`
                    : '<span class="text-muted">-</span>';

                return `
                    <tr>
                        <td>
                            <div>${dateStr}</div>
                            <small class="text-muted">${timeStr}</small>
                        </td>
                        <td>
                            <span class="badge ${badge.class}">
                                <i class="${badge.icon} me-1"></i>
                                ${capitalize(txn.type || 'unknown')}
                            </span>
                        </td>
                        <td class="text-end text-amount ${amountClass}">
                            ${amountPrefix}${escapeHtml(txn.currency || currency)} ${Math.abs(amount).toFixed(2)}
                        </td>
                        <td class="text-end text-amount">
                            ${escapeHtml(txn.currency || currency)} ${balanceAfter.toFixed(2)}
                        </td>
                        <td>${txn.description ? escapeHtml(txn.description) : '<span class="text-muted">-</span>'}</td>
                        <td>${refStr}</td>
                    </tr>
                `;
            }).join('');
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
    </script>
</body>
</html>
