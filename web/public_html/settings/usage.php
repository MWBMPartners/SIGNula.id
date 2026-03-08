<?php
/**
 * ============================================================================
 * 📊 SIGNula - Usage & Billing Dashboard
 * ============================================================================
 *
 * Purpose: User-facing usage dashboard showing current usage, projected costs,
 *          billing mode management, and historical usage/billing summaries.
 *
 * Tables Used:
 *   - tblSubscriptions: User's active subscription (billingMode, tierID, etc.)
 *   - tblSubscriptionTiers / tblPartnerSubscriptionTiers: Tier pricing details
 *   - tblUsageBillingSummary: Historical billing period summaries
 *   - tblUsageRecords: Per-metric usage records for current period
 *   - tblSettings: Global billing configuration (billing.usage.enabled, etc.)
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * @copyright  MWBM Partners Ltd, 2025-2026
 * @see        https://www.php.net/manual/en/book.mysqli.php MySQLi Reference
 * @see        https://www.chartjs.org/docs/latest/ Chart.js 4.x Documentation
 * ============================================================================
 */

// 🚀 Bootstrap the application
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔒 Require login — redirects to login page if not authenticated
requireLogin();

$pageTitle = 'Usage & Billing';
$user = getCurrentUser();
$userID = $_SESSION['userID'];

// ============================================================================
// 📋 DATA RETRIEVAL — Subscription, Tier, Usage, and Billing History
// ============================================================================

// 🔧 Check if usage billing is globally enabled via tblSettings
$usageBillingEnabled = getSetting('billing.usage.enabled') === '1';

// 💳 Fetch the user's active subscription (if any)
// Expected columns: subscriptionID, userID, tierID, partnerID, billingMode,
//                   billingCycle, nextBillingDate, currentPeriodStart, currentPeriodEnd, status
$subscription = Database::fetchOne(
    "SELECT * FROM tblSubscriptions
     WHERE userID = ? AND status = 'active'
     ORDER BY createdAt DESC
     LIMIT 1",
    [$userID],
    'i'
);

// 📦 Initialise display variables with safe defaults
$tier = null;
$billingMode = 'fixed';           // Default billing mode
$currentPeriodStart = null;
$currentPeriodEnd = null;
$nextBillingDate = null;
$daysRemaining = 0;
$monthlyPrice = 0.00;
$supportsUsageBilling = false;
$currencySymbol = getSetting('billing.currency.symbol') ?? '$';
$currencyCode = getSetting('billing.currency.code') ?? 'USD';

if ($subscription) {
    $billingMode = $subscription['billingMode'] ?? 'fixed';
    $currentPeriodStart = $subscription['currentPeriodStart'] ?? null;
    $currentPeriodEnd = $subscription['currentPeriodEnd'] ?? null;
    $nextBillingDate = $subscription['nextBillingDate'] ?? null;

    // 📅 Calculate days remaining in the current billing period
    if ($currentPeriodEnd) {
        $endDate = new DateTime($currentPeriodEnd);
        $now = new DateTime();
        $daysRemaining = max(0, (int) $now->diff($endDate)->format('%r%a'));
    }

    // 🏷️ Fetch tier details — check partner-specific tiers first, then global tiers
    // Partner tiers override global tiers when a partnerID is present on the subscription
    if (!empty($subscription['partnerID'])) {
        $tier = Database::fetchOne(
            "SELECT * FROM tblPartnerSubscriptionTiers
             WHERE tierID = ? AND partnerID = ?",
            [$subscription['tierID'], $subscription['partnerID']],
            'ii'
        );
    }

    // Fallback to global tier table if no partner-specific tier found
    if (!$tier) {
        $tier = Database::fetchOne(
            "SELECT * FROM tblSubscriptionTiers WHERE tierID = ?",
            [$subscription['tierID']],
            'i'
        );
    }

    if ($tier) {
        $monthlyPrice = (float) ($tier['monthlyPrice'] ?? 0.00);
        $supportsUsageBilling = !empty($tier['supportsUsageBilling']) && $tier['supportsUsageBilling'] == 1;
    }
}

// ============================================================================
// 📊 CURRENT PERIOD USAGE — Aggregated usage records for the active period
// ============================================================================

$currentUsage = [];
$currentPeriodCost = 0.00;

if ($subscription && $currentPeriodStart && $currentPeriodEnd) {
    // 📈 Fetch aggregated usage per metric for the current billing period
    // Groups by metricName, sums quantity, calculates billable quantity after free allowance
    $currentUsage = Database::fetchAll(
        "SELECT
            metricName,
            SUM(quantity) AS totalUsage,
            COALESCE(MAX(freeAllowance), 0) AS freeAllowance,
            GREATEST(SUM(quantity) - COALESCE(MAX(freeAllowance), 0), 0) AS billableQty,
            COALESCE(MAX(unitRate), 0) AS unitRate,
            GREATEST(SUM(quantity) - COALESCE(MAX(freeAllowance), 0), 0) * COALESCE(MAX(unitRate), 0) AS subtotal,
            MAX(unitLabel) AS unitLabel
         FROM tblUsageRecords
         WHERE userID = ?
           AND recordedAt >= ?
           AND recordedAt <= ?
         GROUP BY metricName
         ORDER BY metricName ASC",
        [$userID, $currentPeriodStart, $currentPeriodEnd],
        'iss'
    ) ?? [];

    // 💰 Sum up current period cost from all metrics
    foreach ($currentUsage as $metric) {
        $currentPeriodCost += (float) ($metric['subtotal'] ?? 0.00);
    }
}

// 💵 Calculate savings when in hybrid mode (usage capped at tier price)
$effectiveCost = $currentPeriodCost;
$savings = 0.00;

if ($billingMode === 'hybrid' && $monthlyPrice > 0) {
    // In hybrid mode, the user pays the lesser of usage cost and tier cap
    $effectiveCost = min($currentPeriodCost, $monthlyPrice);
    $savings = max(0, $currentPeriodCost - $monthlyPrice);
} elseif ($billingMode === 'fixed') {
    // In fixed mode, the user always pays the tier price
    $effectiveCost = $monthlyPrice;
    $savings = 0.00;
}

// ============================================================================
// 📜 BILLING HISTORY — Past billing period summaries
// ============================================================================

$billingHistory = [];
if ($subscription) {
    $billingHistory = Database::fetchAll(
        "SELECT *
         FROM tblUsageBillingSummary
         WHERE userID = ?
         ORDER BY periodEnd DESC
         LIMIT 12",
        [$userID],
        'i'
    ) ?? [];
}

// ============================================================================
// 📉 USAGE HISTORY — Last 6 months of aggregated usage for trend charts
// ============================================================================

$usageHistory = [];
if ($subscription) {
    // 📆 Fetch monthly aggregated usage per metric for the last 6 months
    $usageHistory = Database::fetchAll(
        "SELECT
            metricName,
            DATE_FORMAT(recordedAt, '%Y-%m') AS monthKey,
            DATE_FORMAT(recordedAt, '%b %Y') AS monthLabel,
            SUM(quantity) AS totalUsage
         FROM tblUsageRecords
         WHERE userID = ?
           AND recordedAt >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY metricName, monthKey, monthLabel
         ORDER BY monthKey ASC, metricName ASC",
        [$userID],
        'i'
    ) ?? [];
}

// 🗂️ Reorganise usage history into a structure suitable for Chart.js
// Format: { metricName: { monthLabel: totalUsage, ... }, ... }
$usageTrendData = [];
$trendMonths = [];

foreach ($usageHistory as $row) {
    $metric = $row['metricName'];
    $month = $row['monthLabel'];
    $monthKey = $row['monthKey'];

    if (!isset($usageTrendData[$metric])) {
        $usageTrendData[$metric] = [];
    }
    $usageTrendData[$metric][$monthKey] = [
        'label' => $month,
        'value' => (float) $row['totalUsage']
    ];

    // Collect unique month keys for x-axis labels
    if (!in_array($monthKey, $trendMonths)) {
        $trendMonths[] = $monthKey;
    }
}

// Sort months chronologically
sort($trendMonths);

// Build month labels array for the chart x-axis
$trendMonthLabels = [];
foreach ($trendMonths as $mk) {
    // Find the label from any metric that has this month
    foreach ($usageTrendData as $metricData) {
        if (isset($metricData[$mk])) {
            $trendMonthLabels[] = $metricData[$mk]['label'];
            break;
        }
    }
}

// 🎨 Predefined colour palette for chart datasets (up to 10 metrics)
$chartColours = [
    'rgba(54, 162, 235, 0.8)',   // Blue
    'rgba(255, 99, 132, 0.8)',   // Red
    'rgba(75, 192, 192, 0.8)',   // Teal
    'rgba(255, 206, 86, 0.8)',   // Yellow
    'rgba(153, 102, 255, 0.8)',  // Purple
    'rgba(255, 159, 64, 0.8)',   // Orange
    'rgba(46, 204, 113, 0.8)',   // Green
    'rgba(231, 76, 60, 0.8)',    // Crimson
    'rgba(52, 73, 94, 0.8)',     // Dark Slate
    'rgba(26, 188, 156, 0.8)',   // Turquoise
];

// 🎨 Include header (provides <!DOCTYPE>, <head>, navbar, Bootstrap/FontAwesome CDN links)
include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📊 Chart.js 4.x from CDN — used for usage bar chart and trend line chart -->
<!-- @see https://www.chartjs.org/docs/latest/getting-started/installation.html -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"
        integrity="sha384-UPIssOjNMqMfON3GmhYAGBIF0lGT4W7TwwjFSl8ynNezAkMklHNDALxil5gF6M3H"
        crossorigin="anonymous"></script>

<div class="container mt-5">
    <div class="row">

        <!-- ================================================================ -->
        <!-- 🧭 Sidebar Navigation                                           -->
        <!-- ================================================================ -->
        <div class="col-md-3 mb-4">
            <?php include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'settings-sidebar.php'; ?>
        </div>

        <!-- ================================================================ -->
        <!-- 📄 Main Content Area                                             -->
        <!-- ================================================================ -->
        <div class="col-md-9">

            <!-- 🏷️ Page Header with Breadcrumb and Billing Mode Badge -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-1">
                            <li class="breadcrumb-item"><a href="/settings">Settings</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Usage &amp; Billing</li>
                        </ol>
                    </nav>
                    <h1 class="h3 mb-0">📊 Usage &amp; Billing</h1>
                </div>
                <?php if ($subscription): ?>
                    <!-- 🏷️ Billing mode badge — colour-coded for quick identification -->
                    <span class="badge fs-6 bg-<?php
                        echo match($billingMode) {
                            'usage'  => 'info',
                            'hybrid' => 'warning text-dark',
                            default  => 'primary',
                        };
                    ?>">
                        <i class="fas fa-<?php
                            echo match($billingMode) {
                                'usage'  => 'chart-bar',
                                'hybrid' => 'balance-scale',
                                default  => 'tag',
                            };
                        ?> me-1"></i>
                        <?php echo ucfirst(htmlspecialchars($billingMode)); ?> Billing
                    </span>
                <?php endif; ?>
            </div>

            <!-- ⚠️ Global usage billing disabled notice -->
            <?php if (!$usageBillingEnabled): ?>
                <div class="alert alert-info d-flex align-items-center" role="alert">
                    <i class="fas fa-info-circle fs-4 me-3"></i>
                    <div>
                        <strong>Usage-based billing is not currently enabled.</strong>
                        Usage metrics are still tracked for informational purposes, but billing is based on your
                        subscription tier's fixed price.
                    </div>
                </div>
            <?php endif; ?>

            <!-- ============================================================ -->
            <!-- 🚫 No Active Subscription — Friendly empty state             -->
            <!-- ============================================================ -->
            <?php if (!$subscription): ?>
                <div class="card shadow">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-receipt text-muted" style="font-size: 4rem;"></i>
                        <h4 class="mt-3 mb-2">No Active Subscription</h4>
                        <p class="text-muted mb-4">
                            You don't currently have an active subscription. Subscribe to a plan to
                            start tracking your usage and billing.
                        </p>
                        <a href="/pricing" class="btn btn-primary btn-lg">
                            <i class="fas fa-shopping-cart me-2"></i> View Plans
                        </a>
                    </div>
                </div>

                <!-- Back navigation -->
                <div class="mt-4">
                    <a href="/settings" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Settings
                    </a>
                </div>

            <?php else: ?>
                <!-- ======================================================== -->
                <!-- 📊 Stats Cards Row — Key metrics at a glance             -->
                <!-- ======================================================== -->
                <div class="row mb-4">

                    <!-- 💰 Current Period Cost -->
                    <div class="col-md-6 col-lg-3 mb-3">
                        <div class="card shadow-sm h-100 border-start border-primary border-4">
                            <div class="card-body">
                                <div class="text-muted small text-uppercase mb-1">
                                    <i class="fas fa-coins me-1"></i> Current Cost
                                </div>
                                <h3 class="mb-0" id="currentCostDisplay">
                                    <?php echo htmlspecialchars($currencySymbol) . number_format($effectiveCost, 2); ?>
                                </h3>
                                <small class="text-muted">
                                    <?php if ($billingMode === 'usage' || $billingMode === 'hybrid'): ?>
                                        Usage so far this period
                                    <?php else: ?>
                                        Fixed monthly rate
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- 🏷️ Tier Cap (only shown for hybrid mode) -->
                    <?php if ($billingMode === 'hybrid'): ?>
                        <div class="col-md-6 col-lg-3 mb-3">
                            <div class="card shadow-sm h-100 border-start border-warning border-4">
                                <div class="card-body">
                                    <div class="text-muted small text-uppercase mb-1">
                                        <i class="fas fa-shield-alt me-1"></i> Tier Cap
                                    </div>
                                    <h3 class="mb-0">
                                        <?php echo htmlspecialchars($currencySymbol) . number_format($monthlyPrice, 2); ?>
                                    </h3>
                                    <small class="text-muted">Maximum monthly charge</small>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 💵 Savings This Period (shown for hybrid when cap applies) -->
                    <?php if ($billingMode === 'hybrid'): ?>
                        <div class="col-md-6 col-lg-3 mb-3">
                            <div class="card shadow-sm h-100 border-start border-success border-4">
                                <div class="card-body">
                                    <div class="text-muted small text-uppercase mb-1">
                                        <i class="fas fa-piggy-bank me-1"></i> Savings
                                    </div>
                                    <h3 class="mb-0 <?php echo $savings > 0 ? 'text-success' : ''; ?>">
                                        <?php echo htmlspecialchars($currencySymbol) . number_format($savings, 2); ?>
                                    </h3>
                                    <small class="text-muted">
                                        <?php echo $savings > 0 ? 'Saved by tier cap' : 'No cap savings yet'; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 📅 Days Remaining in Billing Period -->
                    <div class="col-md-6 col-lg-3 mb-3">
                        <div class="card shadow-sm h-100 border-start border-info border-4">
                            <div class="card-body">
                                <div class="text-muted small text-uppercase mb-1">
                                    <i class="fas fa-calendar-day me-1"></i> Days Remaining
                                </div>
                                <h3 class="mb-0"><?php echo $daysRemaining; ?></h3>
                                <small class="text-muted">
                                    <?php if ($nextBillingDate): ?>
                                        Next bill: <?php echo date('M j, Y', strtotime($nextBillingDate)); ?>
                                    <?php else: ?>
                                        In current period
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ======================================================== -->
                <!-- 📈 Current Period Usage — Bar Chart + Detail Table        -->
                <!-- ======================================================== -->
                <div class="card shadow mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-bar me-2"></i> Current Period Usage
                        </h5>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnRefreshUsage"
                                    title="Refresh current usage data">
                                <i class="fas fa-sync-alt" id="refreshIcon"></i> Refresh
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($currentUsage)): ?>
                            <!-- 📭 No usage recorded yet for this period -->
                            <div class="text-center py-4">
                                <i class="fas fa-inbox text-muted" style="font-size: 3rem;"></i>
                                <p class="text-muted mt-2 mb-0">No usage recorded for the current billing period.</p>
                            </div>
                        <?php else: ?>
                            <!-- 📊 Bar chart canvas for current period usage -->
                            <div class="mb-4" style="position: relative; height: 300px;">
                                <canvas id="currentUsageChart"></canvas>
                            </div>

                            <!-- 📋 Detailed usage breakdown table -->
                            <div class="table-responsive">
                                <table class="table table-hover table-striped mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th><i class="fas fa-tag me-1"></i> Metric</th>
                                            <th class="text-end">Usage</th>
                                            <th class="text-end">Free Allowance</th>
                                            <th class="text-end">Billable Qty</th>
                                            <th class="text-end">Rate</th>
                                            <th class="text-end">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody id="usageTableBody">
                                        <?php foreach ($currentUsage as $metric): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($metric['metricName']); ?></strong>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo number_format((float) $metric['totalUsage'], 0); ?>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($metric['unitLabel'] ?? 'units'); ?>
                                                    </small>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo number_format((float) $metric['freeAllowance'], 0); ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo number_format((float) $metric['billableQty'], 0); ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo htmlspecialchars($currencySymbol) . number_format((float) $metric['unitRate'], 4); ?>
                                                </td>
                                                <td class="text-end fw-bold">
                                                    <?php echo htmlspecialchars($currencySymbol) . number_format((float) $metric['subtotal'], 2); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="5" class="text-end fw-bold">Total Usage Cost:</td>
                                            <td class="text-end fw-bold" id="totalCostCell">
                                                <?php echo htmlspecialchars($currencySymbol) . number_format($currentPeriodCost, 2); ?>
                                            </td>
                                        </tr>
                                        <?php if ($billingMode === 'hybrid' && $savings > 0): ?>
                                            <tr class="table-success">
                                                <td colspan="5" class="text-end fw-bold">
                                                    <i class="fas fa-check-circle me-1"></i> Capped at Tier Price:
                                                </td>
                                                <td class="text-end fw-bold">
                                                    <?php echo htmlspecialchars($currencySymbol) . number_format($effectiveCost, 2); ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- 🕐 Timestamp note -->
                            <div class="text-muted small mt-2" id="usageTimestamp">
                                <i class="fas fa-clock me-1"></i>
                                As of <?php echo date('M j, Y g:i A'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ======================================================== -->
                <!-- ⚙️ Billing Mode Section                                  -->
                <!-- ======================================================== -->
                <div class="card shadow mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-sliders-h me-2"></i> Billing Mode
                        </h5>
                    </div>
                    <div class="card-body">

                        <!-- 📝 Current billing mode display with description -->
                        <div class="d-flex align-items-center mb-3">
                            <span class="badge fs-6 me-3 bg-<?php
                                echo match($billingMode) {
                                    'usage'  => 'info',
                                    'hybrid' => 'warning text-dark',
                                    default  => 'primary',
                                };
                            ?>">
                                <?php echo ucfirst(htmlspecialchars($billingMode)); ?>
                            </span>
                            <span class="text-muted">
                                <?php
                                // 📖 Describe the current billing mode for the user
                                echo match($billingMode) {
                                    'usage'  => 'You pay only for what you use each billing period.',
                                    'hybrid' => 'You pay for what you use, capped at your tier\'s monthly rate.',
                                    default  => 'You pay a flat monthly fee regardless of usage.',
                                };
                                ?>
                            </span>
                        </div>

                        <!-- 🔄 Billing mode switcher (only if tier supports usage billing) -->
                        <?php if ($supportsUsageBilling && $usageBillingEnabled): ?>
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <h6 class="mb-3">
                                        <i class="fas fa-exchange-alt me-1"></i> Change Billing Mode
                                    </h6>
                                    <div class="row align-items-end">
                                        <div class="col-md-6 mb-2 mb-md-0">
                                            <label for="billingModeSelect" class="form-label">Select Mode</label>
                                            <select class="form-select" id="billingModeSelect">
                                                <option value="fixed" <?php echo $billingMode === 'fixed' ? 'selected' : ''; ?>>
                                                    Fixed — Flat monthly fee
                                                </option>
                                                <option value="usage" <?php echo $billingMode === 'usage' ? 'selected' : ''; ?>>
                                                    Usage — Pay for what you use
                                                </option>
                                                <option value="hybrid" <?php echo $billingMode === 'hybrid' ? 'selected' : ''; ?>>
                                                    Hybrid — Usage-based, capped at tier price
                                                </option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <button type="button" class="btn btn-primary" id="btnChangeBillingMode">
                                                <i class="fas fa-save me-1"></i> Update Billing Mode
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php elseif (!$usageBillingEnabled): ?>
                            <div class="alert alert-secondary small mb-3">
                                <i class="fas fa-lock me-1"></i>
                                Billing mode changes are not available while usage-based billing is disabled globally.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-secondary small mb-3">
                                <i class="fas fa-lock me-1"></i>
                                Your current tier does not support usage-based billing. Upgrade your plan to access
                                flexible billing modes.
                            </div>
                        <?php endif; ?>

                        <!-- 📚 Billing mode explanations -->
                        <div class="accordion" id="billingModeHelp">
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingModeHelp">
                                    <button class="accordion-button collapsed" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#collapseModeHelp"
                                            aria-expanded="false" aria-controls="collapseModeHelp">
                                        <i class="fas fa-question-circle me-2"></i>
                                        Understanding Billing Modes
                                    </button>
                                </h2>
                                <div id="collapseModeHelp" class="accordion-collapse collapse"
                                     aria-labelledby="headingModeHelp" data-bs-parent="#billingModeHelp">
                                    <div class="accordion-body">
                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <h6><i class="fas fa-tag text-primary me-1"></i> Fixed</h6>
                                                <p class="small text-muted mb-0">
                                                    A flat monthly fee regardless of how much or how little you use.
                                                    Predictable billing with no surprises.
                                                </p>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <h6><i class="fas fa-chart-bar text-info me-1"></i> Usage</h6>
                                                <p class="small text-muted mb-0">
                                                    Pay only for what you consume. Ideal for variable workloads where
                                                    usage fluctuates month to month.
                                                </p>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <h6><i class="fas fa-balance-scale text-warning me-1"></i> Hybrid</h6>
                                                <p class="small text-muted mb-0">
                                                    Pay for what you use, but never more than your tier's monthly price.
                                                    The best of both worlds — flexibility with a safety net.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ======================================================== -->
                <!-- 🧾 Billing History — Past period summaries               -->
                <!-- ======================================================== -->
                <div class="card shadow mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history me-2"></i> Billing History
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($billingHistory)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-file-invoice text-muted" style="font-size: 3rem;"></i>
                                <p class="text-muted mt-2 mb-0">No billing history available yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped mb-0" id="billingHistoryTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Period</th>
                                            <th>Mode</th>
                                            <th class="text-end">Usage Cost</th>
                                            <th class="text-end">Cap</th>
                                            <th class="text-end">Charged</th>
                                            <th class="text-end">Savings</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Invoice</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($billingHistory as $entry): ?>
                                            <?php
                                            // 📅 Format period dates for display
                                            $periodStart = date('M j', strtotime($entry['periodStart'] ?? ''));
                                            $periodEnd = date('M j, Y', strtotime($entry['periodEnd'] ?? ''));
                                            $entryMode = $entry['billingMode'] ?? 'fixed';
                                            $entryUsageCost = (float) ($entry['usageCost'] ?? 0);
                                            $entryCap = (float) ($entry['tierCap'] ?? 0);
                                            $entryCharged = (float) ($entry['amountCharged'] ?? 0);
                                            $entrySavings = (float) ($entry['savings'] ?? 0);
                                            $entryStatus = $entry['status'] ?? 'unknown';
                                            $invoiceUrl = $entry['invoiceUrl'] ?? '';
                                            ?>
                                            <tr class="billing-row" data-summary-id="<?php echo (int) ($entry['summaryID'] ?? 0); ?>"
                                                style="cursor: pointer;" title="Click to view line item details">
                                                <td>
                                                    <i class="fas fa-calendar-alt text-muted me-1"></i>
                                                    <?php echo htmlspecialchars($periodStart . ' — ' . $periodEnd); ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php
                                                        echo match($entryMode) {
                                                            'usage'  => 'info',
                                                            'hybrid' => 'warning text-dark',
                                                            default  => 'primary',
                                                        };
                                                    ?>">
                                                        <?php echo ucfirst(htmlspecialchars($entryMode)); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo htmlspecialchars($currencySymbol) . number_format($entryUsageCost, 2); ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo $entryCap > 0 ? htmlspecialchars($currencySymbol) . number_format($entryCap, 2) : '—'; ?>
                                                </td>
                                                <td class="text-end fw-bold">
                                                    <?php echo htmlspecialchars($currencySymbol) . number_format($entryCharged, 2); ?>
                                                </td>
                                                <td class="text-end <?php echo $entrySavings > 0 ? 'text-success' : ''; ?>">
                                                    <?php echo $entrySavings > 0
                                                        ? htmlspecialchars($currencySymbol) . number_format($entrySavings, 2)
                                                        : '—'; ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-<?php
                                                        echo match($entryStatus) {
                                                            'paid'    => 'success',
                                                            'pending' => 'warning text-dark',
                                                            'overdue' => 'danger',
                                                            'voided'  => 'secondary',
                                                            default   => 'secondary',
                                                        };
                                                    ?>">
                                                        <?php echo ucfirst(htmlspecialchars($entryStatus)); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if (!empty($invoiceUrl)): ?>
                                                        <a href="<?php echo htmlspecialchars($invoiceUrl); ?>"
                                                           class="btn btn-sm btn-outline-primary"
                                                           target="_blank" rel="noopener noreferrer"
                                                           title="View Invoice"
                                                           onclick="event.stopPropagation();">
                                                            <i class="fas fa-file-invoice"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <!-- 📋 Expandable line-item detail row (hidden by default) -->
                                            <tr class="billing-detail-row d-none" id="detail-<?php echo (int) ($entry['summaryID'] ?? 0); ?>">
                                                <td colspan="8" class="bg-light p-3">
                                                    <div class="text-center text-muted py-2">
                                                        <i class="fas fa-spinner fa-spin me-1"></i> Loading details...
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ======================================================== -->
                <!-- 📉 Usage History — Last 6 Months Trend Chart             -->
                <!-- ======================================================== -->
                <div class="card shadow mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-line me-2"></i> Usage Trends (Last 6 Months)
                        </h5>
                        <?php if (!empty($usageTrendData)): ?>
                            <!-- 🎛️ Metric selector to toggle chart datasets -->
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                        id="metricSelectorBtn" data-bs-toggle="dropdown"
                                        data-bs-auto-close="outside" aria-expanded="false">
                                    <i class="fas fa-filter me-1"></i> Metrics
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end p-2" aria-labelledby="metricSelectorBtn"
                                    id="metricSelectorDropdown">
                                    <?php $idx = 0; foreach (array_keys($usageTrendData) as $metricName): ?>
                                        <li>
                                            <div class="form-check">
                                                <input class="form-check-input metric-toggle" type="checkbox"
                                                       value="<?php echo htmlspecialchars($metricName); ?>"
                                                       id="metric-<?php echo $idx; ?>" checked
                                                       data-dataset-index="<?php echo $idx; ?>">
                                                <label class="form-check-label" for="metric-<?php echo $idx; ?>">
                                                    <?php echo htmlspecialchars($metricName); ?>
                                                </label>
                                            </div>
                                        </li>
                                    <?php $idx++; endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($usageTrendData)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-chart-area text-muted" style="font-size: 3rem;"></i>
                                <p class="text-muted mt-2 mb-0">
                                    Not enough usage data to display trends. Check back after your first billing period.
                                </p>
                            </div>
                        <?php else: ?>
                            <div style="position: relative; height: 350px;">
                                <canvas id="usageTrendChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 🔙 Back navigation -->
                <div class="d-flex justify-content-between mt-4 mb-4">
                    <a href="/settings" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Settings
                    </a>
                </div>

            <?php endif; /* end if $subscription */ ?>

        </div><!-- /.col-md-9 -->
    </div><!-- /.row -->
</div><!-- /.container -->

<!-- ======================================================================== -->
<!-- 🔔 Toast Notification Container (for AJAX feedback)                      -->
<!-- ======================================================================== -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1100;">
    <div id="usageToast" class="toast align-items-center border-0" role="alert"
         aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="toastMessage"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto"
                    data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<?php if ($subscription): ?>
<!-- ======================================================================== -->
<!-- 🔧 JavaScript — Charts, AJAX Refresh, Billing Mode Change               -->
<!-- ======================================================================== -->
<script>
/**
 * 🛡️ escapeHtml — Prevents XSS by escaping HTML special characters
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
 *
 * @param {string} text - The raw text to escape
 * @returns {string} HTML-safe string
 */
function escapeHtml(text) {
    'use strict';
    if (typeof text !== 'string') {
        return '';
    }
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

/**
 * 🔔 showToast — Display a Bootstrap toast notification
 *
 * @param {string} message - Message text to display
 * @param {string} type    - Bootstrap colour class: 'success', 'danger', 'warning', 'info'
 */
function showToast(message, type) {
    'use strict';
    var toastEl = document.getElementById('usageToast');
    var toastBody = document.getElementById('toastMessage');
    if (!toastEl || !toastBody) {
        return;
    }

    // Reset classes and apply the correct background colour
    toastEl.className = 'toast align-items-center border-0 text-bg-' + escapeHtml(type);
    toastBody.textContent = message;

    // @see https://getbootstrap.com/docs/5.3/components/toasts/#methods
    var toast = bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 4000 });
    toast.show();
}

// ========================================================================
// 📊 Current Period Usage — Bar Chart
// ========================================================================
<?php if (!empty($currentUsage)): ?>
(function() {
    'use strict';

    var ctx = document.getElementById('currentUsageChart');
    if (!ctx) {
        return;
    }

    // Prepare data arrays from PHP
    var metricLabels = <?php echo json_encode(array_map(function($m) {
        return $m['metricName'];
    }, $currentUsage), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

    var usageValues = <?php echo json_encode(array_map(function($m) {
        return (float) $m['totalUsage'];
    }, $currentUsage), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

    var billableValues = <?php echo json_encode(array_map(function($m) {
        return (float) $m['billableQty'];
    }, $currentUsage), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

    // @see https://www.chartjs.org/docs/latest/charts/bar.html
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: metricLabels,
            datasets: [
                {
                    label: 'Total Usage',
                    data: usageValues,
                    backgroundColor: 'rgba(54, 162, 235, 0.6)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Billable Quantity',
                    data: billableValues,
                    backgroundColor: 'rgba(255, 99, 132, 0.6)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
})();
<?php endif; ?>

// ========================================================================
// 📉 Usage Trends — Line Chart (Last 6 Months)
// ========================================================================
<?php if (!empty($usageTrendData)): ?>
var usageTrendChart;
(function() {
    'use strict';

    var ctx = document.getElementById('usageTrendChart');
    if (!ctx) {
        return;
    }

    var monthLabels = <?php echo json_encode($trendMonthLabels, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var monthKeys = <?php echo json_encode($trendMonths, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var colours = <?php echo json_encode($chartColours, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

    // Build datasets — one per metric
    var datasets = [];
    <?php $dsIdx = 0; foreach ($usageTrendData as $metricName => $monthData): ?>
    (function() {
        var dataPoints = [];
        var metricData = <?php echo json_encode($monthData, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

        // Map each month key to the metric's value (or 0 if not present)
        for (var i = 0; i < monthKeys.length; i++) {
            var mk = monthKeys[i];
            dataPoints.push(metricData[mk] ? metricData[mk].value : 0);
        }

        datasets.push({
            label: <?php echo json_encode($metricName, JSON_HEX_TAG | JSON_HEX_AMP); ?>,
            data: dataPoints,
            borderColor: colours[<?php echo $dsIdx; ?> % colours.length],
            backgroundColor: colours[<?php echo $dsIdx; ?> % colours.length].replace('0.8', '0.2'),
            fill: false,
            tension: 0.3,
            pointRadius: 4,
            pointHoverRadius: 6
        });
    })();
    <?php $dsIdx++; endforeach; ?>

    // @see https://www.chartjs.org/docs/latest/charts/line.html
    usageTrendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: monthLabels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top'
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString();
                        }
                    }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            }
        }
    });

    // 🎛️ Metric toggle — show/hide individual datasets on the trend chart
    var toggles = document.querySelectorAll('.metric-toggle');
    toggles.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            var datasetIndex = parseInt(this.getAttribute('data-dataset-index'), 10);
            if (usageTrendChart && usageTrendChart.data.datasets[datasetIndex]) {
                // Toggle visibility via Chart.js metadata
                // @see https://www.chartjs.org/docs/latest/developers/api.html#toggledatavisibility
                var meta = usageTrendChart.getDatasetMeta(datasetIndex);
                meta.hidden = !this.checked;
                usageTrendChart.update();
            }
        });
    });
})();
<?php endif; ?>

// ========================================================================
// 🔄 AJAX — Refresh Current Usage
// ========================================================================
(function() {
    'use strict';

    var btnRefresh = document.getElementById('btnRefreshUsage');
    if (!btnRefresh) {
        return;
    }

    btnRefresh.addEventListener('click', function() {
        var refreshIcon = document.getElementById('refreshIcon');
        if (refreshIcon) {
            refreshIcon.classList.add('fa-spin');
        }
        btnRefresh.disabled = true;

        // 📡 Fetch current usage data from the API
        // @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API
        fetch('/api/v1/usage/current', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Failed to fetch usage data (HTTP ' + response.status + ')');
            }
            return response.json();
        })
        .then(function(data) {
            if (data && data.success) {
                // ✅ Update the current cost display
                if (data.effectiveCost !== undefined) {
                    var costEl = document.getElementById('currentCostDisplay');
                    if (costEl) {
                        costEl.textContent = <?php echo json_encode(htmlspecialchars($currencySymbol)); ?> +
                            parseFloat(data.effectiveCost).toLocaleString(undefined, {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            });
                    }
                }

                // ✅ Update the timestamp
                var tsEl = document.getElementById('usageTimestamp');
                if (tsEl && data.timestamp) {
                    tsEl.innerHTML = '<i class="fas fa-clock me-1"></i> As of ' + escapeHtml(data.timestamp);
                }

                showToast('Usage data refreshed successfully.', 'success');
            } else {
                showToast(data.message || 'Could not refresh usage data.', 'warning');
            }
        })
        .catch(function(error) {
            console.error('Usage refresh error:', error);
            showToast('Error refreshing usage data. Please try again.', 'danger');
        })
        .finally(function() {
            if (refreshIcon) {
                refreshIcon.classList.remove('fa-spin');
            }
            btnRefresh.disabled = false;
        });
    });
})();

// ========================================================================
// ⚙️ AJAX — Change Billing Mode
// ========================================================================
(function() {
    'use strict';

    var btnChange = document.getElementById('btnChangeBillingMode');
    if (!btnChange) {
        return;
    }

    btnChange.addEventListener('click', function() {
        var selectEl = document.getElementById('billingModeSelect');
        if (!selectEl) {
            return;
        }

        var newMode = selectEl.value;
        var currentMode = <?php echo json_encode($billingMode); ?>;

        // 🚫 No change needed if same mode selected
        if (newMode === currentMode) {
            showToast('You are already on ' + escapeHtml(newMode) + ' billing.', 'info');
            return;
        }

        // ⚠️ Confirm before making changes
        if (!confirm('Are you sure you want to switch from "' + currentMode + '" to "' + newMode + '" billing?\n\nThis change will take effect from your next billing period.')) {
            return;
        }

        btnChange.disabled = true;
        btnChange.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Updating...';

        // 📡 Send billing mode change request to the API
        fetch('/api/v1/usage/billing-mode', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                billingMode: newMode,
                csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>
            })
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Failed to update billing mode (HTTP ' + response.status + ')');
            }
            return response.json();
        })
        .then(function(data) {
            if (data && data.success) {
                showToast('Billing mode updated to "' + escapeHtml(newMode) + '" successfully.', 'success');
                // 🔄 Reload the page after a short delay to reflect the new mode
                setTimeout(function() {
                    window.location.reload();
                }, 1500);
            } else {
                showToast(data.message || 'Could not update billing mode.', 'danger');
                btnChange.disabled = false;
                btnChange.innerHTML = '<i class="fas fa-save me-1"></i> Update Billing Mode';
            }
        })
        .catch(function(error) {
            console.error('Billing mode change error:', error);
            showToast('Error updating billing mode. Please try again.', 'danger');
            btnChange.disabled = false;
            btnChange.innerHTML = '<i class="fas fa-save me-1"></i> Update Billing Mode';
        });
    });
})();

// ========================================================================
// 🧾 Billing History — Expandable Row Details
// ========================================================================
(function() {
    'use strict';

    var billingRows = document.querySelectorAll('.billing-row');
    billingRows.forEach(function(row) {
        row.addEventListener('click', function() {
            var summaryId = this.getAttribute('data-summary-id');
            var detailRow = document.getElementById('detail-' + summaryId);
            if (!detailRow) {
                return;
            }

            // 🔄 Toggle visibility of the detail row
            if (detailRow.classList.contains('d-none')) {
                // Show the detail row
                detailRow.classList.remove('d-none');

                // 📡 Fetch line-item details if not already loaded
                if (detailRow.getAttribute('data-loaded') !== 'true') {
                    fetch('/api/v1/usage/billing-detail?summaryID=' + encodeURIComponent(summaryId), {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('HTTP ' + response.status);
                        }
                        return response.json();
                    })
                    .then(function(data) {
                        var cell = detailRow.querySelector('td');
                        if (!cell) {
                            return;
                        }

                        if (data && data.success && data.lineItems && data.lineItems.length > 0) {
                            // 📋 Build a mini-table of line items
                            var html = '<table class="table table-sm table-bordered mb-0">';
                            html += '<thead><tr>';
                            html += '<th>Metric</th>';
                            html += '<th class="text-end">Usage</th>';
                            html += '<th class="text-end">Free</th>';
                            html += '<th class="text-end">Billable</th>';
                            html += '<th class="text-end">Rate</th>';
                            html += '<th class="text-end">Subtotal</th>';
                            html += '</tr></thead><tbody>';

                            var currency = <?php echo json_encode(htmlspecialchars($currencySymbol)); ?>;
                            data.lineItems.forEach(function(item) {
                                html += '<tr>';
                                html += '<td>' + escapeHtml(item.metricName) + '</td>';
                                html += '<td class="text-end">' + Number(item.totalUsage).toLocaleString() + '</td>';
                                html += '<td class="text-end">' + Number(item.freeAllowance).toLocaleString() + '</td>';
                                html += '<td class="text-end">' + Number(item.billableQty).toLocaleString() + '</td>';
                                html += '<td class="text-end">' + currency + Number(item.unitRate).toFixed(4) + '</td>';
                                html += '<td class="text-end fw-bold">' + currency + Number(item.subtotal).toFixed(2) + '</td>';
                                html += '</tr>';
                            });

                            html += '</tbody></table>';
                            cell.innerHTML = html;
                        } else {
                            cell.innerHTML = '<div class="text-muted text-center py-2">' +
                                '<i class="fas fa-info-circle me-1"></i> No line-item details available.' +
                                '</div>';
                        }

                        detailRow.setAttribute('data-loaded', 'true');
                    })
                    .catch(function(error) {
                        console.error('Billing detail fetch error:', error);
                        var cell = detailRow.querySelector('td');
                        if (cell) {
                            cell.innerHTML = '<div class="text-danger text-center py-2">' +
                                '<i class="fas fa-exclamation-triangle me-1"></i> ' +
                                'Failed to load details. Click to retry.' +
                                '</div>';
                        }
                    });
                }
            } else {
                // Hide the detail row
                detailRow.classList.add('d-none');
            }
        });
    });
})();
</script>
<?php endif; /* end if $subscription JS block */ ?>

<?php
// 🎨 Include footer (provides closing </body>, </html>, Bootstrap JS bundle, etc.)
include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'footer.php';
?>
