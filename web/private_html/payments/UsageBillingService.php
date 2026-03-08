<?php
/**
 * SIGNula - Universal Single Sign-On Authentication System
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package SIGNula
 * @version 2.7.0-beta
 */

/**
 * ============================================================================
 * 📊 SIGNula - Usage-Based Billing Service
 * ============================================================================
 *
 * Purpose: Metered / usage-based billing engine that calculates, persists, and
 *          manages per-subscription usage costs, supporting three billing modes:
 *
 *          - **fixed**   — Traditional flat-rate subscription (no usage charges)
 *          - **usage**   — Pure pay-as-you-go, no fixed monthly component
 *          - **hybrid**  — Fixed monthly price acts as a spending cap; actual
 *                          usage is tracked and billed up to the cap amount
 *
 * PHP Version: 8.3+ (targeting 8.4 for longevity)
 *
 * Monetary Precision:
 *   ALL monetary arithmetic uses PHP's BCMath arbitrary-precision library to
 *   avoid IEEE 754 floating-point rounding errors. Every bcmath call explicitly
 *   specifies a scale parameter (typically 4 or 6) to guarantee deterministic,
 *   auditable results.
 *   @see https://www.php.net/manual/en/book.bc.php
 *
 * Architecture:
 *   - Static utility class — no instantiation required
 *   - Reads metric definitions from tblUsageMetrics (what to measure)
 *   - Reads per-tier pricing from tblUsageRates (how much to charge)
 *   - Aggregates raw usage events from tblUsageRecords (what was consumed)
 *   - Persists billing summaries to tblUsageBillingSummary (the bill)
 *   - Integrates with tblSubscriptions for billing mode and cycle
 *   - Supports both global tiers (tblSubscriptionTiers) and partner-specific
 *     tiers (tblPartnerSubscriptionTiers)
 *
 * Cost Calculation Formula (per metric):
 *   1. totalUsage       = SUM/MAX/AVG of quantity from tblUsageRecords
 *   2. billableQty      = max(0, totalUsage - freeAllowance)
 *   3. if overageCapUnits:  billableQty = min(billableQty, overageCapUnits)
 *   4. effectiveRate     = (totalUsage > freeAllowance && overageRate IS NOT NULL)
 *                          ? overageRate : ratePerUnit
 *   5. metricCost        = (billableQty / billingGranularity) * effectiveRate
 *   6. totalUsageCost    = SUM of all metricCost values
 *   7. (hybrid only)     cappedAmount = min(totalUsageCost, tierFixedPrice)
 *
 * Database Tables:
 *   - tblUsageMetrics          — Metric definitions (what to measure)
 *   - tblUsageRates            — Per-tier pricing schedules
 *   - tblUsageRecords          — Raw usage events (quantities)
 *   - tblUsageBillingSummary   — Persisted billing summaries
 *   - tblSubscriptions         — Subscription master records
 *   - tblSubscriptionTiers     — Global tier definitions
 *   - tblPartnerSubscriptionTiers — Partner-specific tier definitions
 *
 * Settings (from tblSettings via getSetting()):
 *   - billing.usage.enabled        — Master switch for usage billing (bool)
 *   - billing.usage.cap_comparison — Cap comparison mode: 'monthly' (default)
 *
 * @see /web/private_html/payments/PaymentManager.php      Subscription management
 * @see /web/private_html/payments/InvoiceManager.php      Invoice generation
 * @see /web/private_html/payments/BillingScheduler.php    Automated billing tasks
 * @see /web/private_html/payments/ServiceFeeManager.php   Service fee calculations
 * @see https://www.php.net/manual/en/book.bc.php          BCMath functions
 * @see https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.0.0
 * @since      2.7.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access — all SIGNula files must be loaded via the bootstrap
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 📊 Usage-Based Billing Service
 *
 * Static utility class for metered/usage-based billing calculations and
 * management. Handles cost calculation, billing summary generation, batch
 * processing, rate management, projected cost estimation, and cap proximity
 * alerting.
 *
 * All methods are public static — no instantiation required.
 *
 * Usage:
 * ```php
 * // Calculate usage cost for a billing period
 * $result = UsageBillingService::calculateUsageCost($subscriptionID, '2026-03-01', '2026-03-31');
 *
 * // Generate and persist a billing summary
 * $summary = UsageBillingService::generateBillingSummary($subscriptionID, '2026-03-01', '2026-03-31');
 *
 * // Check if approaching tier cap
 * $proximity = UsageBillingService::checkCapProximity($subscriptionID, 0.80);
 * ```
 */
class UsageBillingService
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /** @var string Default currency code (ISO 4217) for usage billing */
    const DEFAULT_CURRENCY = 'GBP';

    /**
     * @var int BCMath scale for monetary calculations (4 decimal places)
     * Provides sub-penny precision for unit rate * quantity products.
     * @see https://www.php.net/manual/en/function.bcmul.php
     */
    const MONETARY_SCALE = 4;

    /**
     * @var int BCMath scale for rate calculations (6 decimal places)
     * Higher precision for per-unit rates (e.g., $0.000123 per API call).
     */
    const RATE_SCALE = 6;

    /** @var string Billing mode: fixed monthly subscription (no usage component) */
    const MODE_FIXED = 'fixed';

    /** @var string Billing mode: pure usage-based, no fixed monthly component */
    const MODE_USAGE = 'usage';

    /** @var string Billing mode: fixed price acts as a cap on usage charges */
    const MODE_HYBRID = 'hybrid';

    /** @var array Valid billing modes matching tblSubscriptions.billingMode ENUM */
    const VALID_BILLING_MODES = [
        self::MODE_FIXED,
        self::MODE_USAGE,
        self::MODE_HYBRID,
    ];

    /** @var array Valid billing summary statuses matching tblUsageBillingSummary.status ENUM */
    const SUMMARY_STATUSES = [
        'pending',   // 📋 Calculated but not yet invoiced
        'invoiced',  // 🧾 Invoice generated for this summary
        'paid',      // ✅ Payment received
        'void',      // 🚫 Voided / cancelled
        'failed',    // ❌ Payment attempt failed
    ];

    /** @var array Valid aggregation types for tblUsageMetrics.aggregationType */
    const AGGREGATION_TYPES = [
        'sum',       // 📈 Sum of all usage quantities in the period
        'max',       // 📊 Maximum usage value recorded in the period
        'avg',       // 📉 Average usage across records in the period
    ];


    // ========================================================================
    // 💰 CORE COST CALCULATION
    // ========================================================================

    /**
     * 🧮 Calculate usage cost for a subscription's billing period
     *
     * This is the CORE METHOD of the usage billing engine. It performs the
     * following steps:
     *
     *   1. Retrieve subscription details (tierID, billingMode, billingCycle)
     *   2. Retrieve tier details (monthlyPrice, tierType — global vs partner)
     *   3. Fetch all active usage rates for the tier on the period end date
     *   4. For each rate, aggregate usage from tblUsageRecords using the
     *      metric's aggregationType (SUM, MAX, or AVG)
     *   5. Calculate cost per metric using the free allowance, overage cap,
     *      overage rate, billing granularity, and rate per unit
     *   6. Sum all metric costs to get totalUsageCost
     *   7. Apply cap logic based on billingMode:
     *      - hybrid: cappedAmount = min(totalUsageCost, tierFixedPrice)
     *      - usage:  cappedAmount = totalUsageCost (no cap)
     *   8. Build lineItems JSON array with per-metric breakdown
     *
     * All monetary arithmetic uses bcmath functions (bcmul, bcadd, bcsub,
     * bccomp) with explicit scale parameters. NEVER floating point for money.
     *
     * @param int    $subscriptionID   The subscription to calculate costs for
     * @param string $billingPeriodStart Start date of the billing period (YYYY-MM-DD)
     * @param string $billingPeriodEnd   End date of the billing period (YYYY-MM-DD)
     *
     * @return array Associative array containing:
     *   - 'success'        (bool)   Whether the calculation succeeded
     *   - 'totalUsageCost' (string) Total usage cost before cap (BCMath string)
     *   - 'tierFixedPrice' (string) The tier's monthly fixed price (BCMath string)
     *   - 'cappedAmount'   (string) Final amount after cap logic (BCMath string)
     *   - 'capApplied'     (bool)   Whether the cap reduced the total
     *   - 'savingsAmount'  (string) How much the cap saved (BCMath string)
     *   - 'lineItems'      (array)  Per-metric cost breakdown
     *   - 'currency'       (string) Currency code (ISO 4217)
     *   - 'billingMode'    (string) The subscription's billing mode
     *   - 'message'        (string) Error message on failure
     *
     * @see self::getActiveRatesForTier()  Retrieves applicable rates
     * @see https://www.php.net/manual/en/function.bcmul.php
     * @see https://www.php.net/manual/en/function.bcadd.php
     * @see https://www.php.net/manual/en/function.bcsub.php
     * @see https://www.php.net/manual/en/function.bccomp.php
     */
    public static function calculateUsageCost(
        int $subscriptionID,
        string $billingPeriodStart,
        string $billingPeriodEnd
    ): array {
        try {
            // 🔒 Check if usage billing is enabled globally
            // @see getSetting() in web/_config/config.php
            $usageBillingEnabled = getSetting('billing.usage.enabled');

            if ($usageBillingEnabled !== null && !$usageBillingEnabled) {
                return [
                    'success'        => false,
                    'message'        => 'Usage-based billing is disabled globally',
                    'totalUsageCost' => '0.0000',
                    'tierFixedPrice' => '0.0000',
                    'cappedAmount'   => '0.0000',
                    'capApplied'     => false,
                    'savingsAmount'  => '0.0000',
                    'lineItems'      => [],
                    'currency'       => self::DEFAULT_CURRENCY,
                    'billingMode'    => '',
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 📋 Step 1: Retrieve subscription details
            // ─────────────────────────────────────────────────────────────────
            // Fetch the subscription record to determine the tier, billing mode,
            // billing cycle, and associated user/partner.
            // @see tblSubscriptions schema in migration files
            $subscription = Database::fetchOne(
                "SELECT subscriptionID, userID, partnerID, tierID, billingMode,
                        billingCycle, status, nextBillingDate
                 FROM tblSubscriptions
                 WHERE subscriptionID = ?
                   AND status IN ('active', 'past_due', 'trialing')",
                [$subscriptionID],
                'i'
            );

            if (!$subscription) {
                // ⚠️ Subscription not found or not in a billable state
                return [
                    'success' => false,
                    'message' => 'Subscription not found or not in a billable state (ID: ' . $subscriptionID . ')',
                ];
            }

            // 📦 Extract subscription fields for clarity
            $tierID      = (int) $subscription['tierID'];
            $billingMode = $subscription['billingMode'];
            $userID      = (int) $subscription['userID'];
            $partnerID   = $subscription['partnerID'] !== null ? (int) $subscription['partnerID'] : null;

            // 🔍 Validate billing mode supports usage calculation
            if ($billingMode === self::MODE_FIXED) {
                // 💡 Fixed-mode subscriptions have no usage component — return zero cost
                return [
                    'success'        => true,
                    'totalUsageCost' => '0.0000',
                    'tierFixedPrice' => '0.0000',
                    'cappedAmount'   => '0.0000',
                    'capApplied'     => false,
                    'savingsAmount'  => '0.0000',
                    'lineItems'      => [],
                    'currency'       => self::DEFAULT_CURRENCY,
                    'billingMode'    => self::MODE_FIXED,
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 📋 Step 2: Retrieve tier details (monthlyPrice, supportsUsageBilling)
            // ─────────────────────────────────────────────────────────────────
            // Determine whether this is a global tier or a partner-specific tier.
            // Partner tiers are stored in tblPartnerSubscriptionTiers; global tiers
            // are in tblSubscriptionTiers. The tierType is inferred by checking
            // if a partner tier exists first (partner-specific takes precedence).
            $tierType       = 'global';
            $tierFixedPrice = '0.0000';
            $currency       = self::DEFAULT_CURRENCY;

            // 🔍 Check for partner-specific tier first (if subscription has a partnerID)
            if ($partnerID !== null) {
                $partnerTier = Database::fetchOne(
                    "SELECT tierID, monthlyPrice, supportsUsageBilling, currency
                     FROM tblPartnerSubscriptionTiers
                     WHERE tierID = ?
                       AND partnerID = ?
                       AND isActive = 1",
                    [$tierID, $partnerID],
                    'ii'
                );

                if ($partnerTier) {
                    $tierType       = 'partner';
                    $tierFixedPrice = (string) $partnerTier['monthlyPrice'];
                    $currency       = $partnerTier['currency'] ?? self::DEFAULT_CURRENCY;

                    // 🚫 Verify the partner tier supports usage billing
                    if (!$partnerTier['supportsUsageBilling']) {
                        return [
                            'success' => false,
                            'message' => 'Partner tier (ID: ' . $tierID . ') does not support usage billing',
                        ];
                    }
                }
            }

            // 🌐 Fall back to global tier if no partner tier was found
            if ($tierType === 'global') {
                $globalTier = Database::fetchOne(
                    "SELECT tierID, monthlyPrice, supportsUsageBilling
                     FROM tblSubscriptionTiers
                     WHERE tierID = ?
                       AND isActive = 1",
                    [$tierID],
                    'i'
                );

                if (!$globalTier) {
                    return [
                        'success' => false,
                        'message' => 'Subscription tier not found (ID: ' . $tierID . ')',
                    ];
                }

                $tierFixedPrice = (string) $globalTier['monthlyPrice'];

                // 🚫 Verify the global tier supports usage billing
                if (!$globalTier['supportsUsageBilling']) {
                    return [
                        'success' => false,
                        'message' => 'Subscription tier (ID: ' . $tierID . ') does not support usage billing',
                    ];
                }
            }

            // ─────────────────────────────────────────────────────────────────
            // 📋 Step 3: Fetch all active usage rates for this tier
            // ─────────────────────────────────────────────────────────────────
            // Rates are date-bounded — we use the billing period end date to
            // determine which rates were in effect for this billing cycle.
            $rates = self::getActiveRatesForTier($tierID, $tierType, $billingPeriodEnd);

            if (empty($rates)) {
                // 💡 No usage rates defined for this tier — usage cost is zero
                // This is valid: a tier might not have any metered components yet
                return [
                    'success'        => true,
                    'totalUsageCost' => '0.0000',
                    'tierFixedPrice' => $tierFixedPrice,
                    'cappedAmount'   => '0.0000',
                    'capApplied'     => false,
                    'savingsAmount'  => '0.0000',
                    'lineItems'      => [],
                    'currency'       => $currency,
                    'billingMode'    => $billingMode,
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 📋 Steps 4 & 5: Aggregate usage and calculate cost per metric
            // ─────────────────────────────────────────────────────────────────
            // For each active rate, we:
            //   a) Aggregate usage from tblUsageRecords for the billing period
            //   b) Subtract the free allowance
            //   c) Apply overage cap (if any)
            //   d) Determine effective rate (standard or overage)
            //   e) Calculate the cost for this metric
            $lineItems      = [];
            $totalUsageCost = '0.0000';

            foreach ($rates as $rate) {
                // 📦 Extract rate configuration values as strings for bcmath
                $metricID          = (int) $rate['metricID'];
                $metricCode        = $rate['metricCode'];
                $metricName        = $rate['metricName'];
                $unitLabel         = $rate['unitLabel'];
                $billingGranularity = (string) $rate['billingGranularity'];
                $aggregationType   = $rate['aggregationType'] ?? 'sum';
                $ratePerUnit       = (string) $rate['ratePerUnit'];
                $freeAllowance     = (string) $rate['freeAllowance'];
                $overageRate       = $rate['overageRate'] !== null ? (string) $rate['overageRate'] : null;
                $overageCapUnits   = $rate['overageCapUnits'] !== null ? (string) $rate['overageCapUnits'] : null;
                $rateCurrency      = $rate['currency'] ?? $currency;

                // 🔍 Step 4a: Aggregate usage from tblUsageRecords
                // Build the aggregation SQL based on the metric's aggregationType
                // @see https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html
                $aggregateFunction = match (strtolower($aggregationType)) {
                    'max' => 'MAX(quantity)',
                    'avg' => 'AVG(quantity)',
                    default => 'SUM(quantity)', // 📈 SUM is the default aggregation
                };

                // 📊 Query to aggregate usage for this metric within the billing period
                // Filters by userID, metricID, and the billing period date range
                $usageResult = Database::fetchOne(
                    "SELECT COALESCE(" . $aggregateFunction . ", 0) AS totalUsage,
                            COUNT(*) AS recordCount
                     FROM tblUsageRecords
                     WHERE userID = ?
                       AND metricID = ?
                       AND billingPeriodStart = ?
                       AND billingPeriodEnd = ?",
                    [$userID, $metricID, $billingPeriodStart, $billingPeriodEnd],
                    'iiss'
                );

                // 📦 Extract total usage as a bcmath-compatible string
                $totalUsage  = $usageResult ? (string) $usageResult['totalUsage'] : '0';
                $recordCount = $usageResult ? (int) $usageResult['recordCount'] : 0;

                // ─────────────────────────────────────────────────────────────
                // 🧮 Step 5: Calculate cost for this metric
                // ─────────────────────────────────────────────────────────────

                // Step 5a: Calculate billable quantity after free allowance deduction
                // billableQty = max(0, totalUsage - freeAllowance)
                // @see https://www.php.net/manual/en/function.bcsub.php
                $afterFreeAllowance = bcsub($totalUsage, $freeAllowance, self::MONETARY_SCALE);

                // 📏 Clamp to zero — if usage is within free allowance, no charge
                // bccomp returns -1 if $afterFreeAllowance < '0'
                // @see https://www.php.net/manual/en/function.bccomp.php
                if (bccomp($afterFreeAllowance, '0', self::MONETARY_SCALE) < 0) {
                    $billableQty = '0.0000';
                } else {
                    $billableQty = $afterFreeAllowance;
                }

                // Step 5b: Apply overage cap (if configured)
                // billableQty = min(billableQty, overageCapUnits)
                if ($overageCapUnits !== null && bccomp($billableQty, $overageCapUnits, self::MONETARY_SCALE) > 0) {
                    // 🔒 Cap the billable quantity at the overage limit
                    $billableQty = $overageCapUnits;
                }

                // Step 5c: Determine effective rate
                // If totalUsage exceeds freeAllowance AND an overageRate is defined,
                // use the overage rate; otherwise use the standard ratePerUnit.
                $exceedsFreeAllowance = bccomp($totalUsage, $freeAllowance, self::MONETARY_SCALE) > 0;
                $effectiveRate = ($exceedsFreeAllowance && $overageRate !== null)
                    ? $overageRate
                    : $ratePerUnit;

                // Step 5d: Calculate metric cost
                // cost = (billableQty / billingGranularity) * effectiveRate
                // We must avoid division by zero — billingGranularity should always be >= 1
                // @see https://www.php.net/manual/en/function.bcdiv.php
                if (bccomp($billingGranularity, '0', self::RATE_SCALE) <= 0) {
                    // ⚠️ Safety net: if granularity is zero or negative, treat as 1
                    $billingGranularity = '1';
                }

                $billableUnits = bcdiv($billableQty, $billingGranularity, self::RATE_SCALE);
                $metricCost    = bcmul($billableUnits, $effectiveRate, self::MONETARY_SCALE);

                // Step 6: Accumulate total usage cost across all metrics
                // @see https://www.php.net/manual/en/function.bcadd.php
                $totalUsageCost = bcadd($totalUsageCost, $metricCost, self::MONETARY_SCALE);

                // 📋 Build line item for this metric's breakdown
                $lineItems[] = [
                    'metricID'          => $metricID,
                    'metricCode'        => $metricCode,
                    'metricName'        => $metricName,
                    'unitLabel'         => $unitLabel,
                    'totalUsage'        => $totalUsage,
                    'freeAllowance'     => $freeAllowance,
                    'billableQuantity'  => $billableQty,
                    'billingGranularity' => $billingGranularity,
                    'ratePerUnit'       => $ratePerUnit,
                    'overageRate'       => $overageRate,
                    'effectiveRate'     => $effectiveRate,
                    'overageCapUnits'   => $overageCapUnits,
                    'metricCost'        => $metricCost,
                    'currency'          => $rateCurrency,
                    'recordCount'       => $recordCount,
                    'aggregationType'   => $aggregationType,
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 📋 Step 7: Apply cap logic based on billing mode
            // ─────────────────────────────────────────────────────────────────

            $cappedAmount  = $totalUsageCost;
            $capApplied    = false;
            $savingsAmount = '0.0000';

            if ($billingMode === self::MODE_HYBRID) {
                // 🔒 Hybrid mode: the tier's fixed monthly price acts as a spending cap
                // cappedAmount = min(totalUsageCost, tierFixedPrice)
                if (bccomp($totalUsageCost, $tierFixedPrice, self::MONETARY_SCALE) > 0) {
                    // 💡 Usage exceeds the cap — charge only the fixed price
                    $cappedAmount  = $tierFixedPrice;
                    $capApplied    = true;
                    $savingsAmount = bcsub($totalUsageCost, $tierFixedPrice, self::MONETARY_SCALE);
                }
                // 💡 If usage is below the cap, charge actual usage (cappedAmount = totalUsageCost)
            }
            // 📊 Usage mode: no cap — cappedAmount remains as totalUsageCost

            // ✅ Return the complete cost calculation result
            return [
                'success'        => true,
                'totalUsageCost' => $totalUsageCost,
                'tierFixedPrice' => $tierFixedPrice,
                'cappedAmount'   => $cappedAmount,
                'capApplied'     => $capApplied,
                'savingsAmount'  => $savingsAmount,
                'lineItems'      => $lineItems,
                'currency'       => $currency,
                'billingMode'    => $billingMode,
            ];

        } catch (\Exception $e) {
            // ❌ Unexpected error during cost calculation
            // Log the error for debugging and return a failure result
            ActivityLogger::log(
                null,
                'usage_billing_error',
                'payment',
                'error',
                'Usage cost calculation failed for subscription #' . $subscriptionID . ': ' . $e->getMessage(),
                [
                    'subscriptionID'   => $subscriptionID,
                    'billingPeriodStart' => $billingPeriodStart,
                    'billingPeriodEnd'   => $billingPeriodEnd,
                    'exceptionTrace'     => $e->getTraceAsString(),
                ]
            );

            return [
                'success' => false,
                'message' => 'Usage cost calculation failed: ' . $e->getMessage(),
            ];
        }
    }


    // ========================================================================
    // 📝 BILLING SUMMARY GENERATION
    // ========================================================================

    /**
     * 🧾 Generate and persist a billing summary for a subscription's billing period
     *
     * Calls calculateUsageCost() to compute the costs, then persists the result
     * to tblUsageBillingSummary and marks the associated usage records as processed.
     *
     * This method is idempotent — if a summary already exists for the same
     * subscription and billing period, it returns the existing summary rather
     * than creating a duplicate.
     *
     * @param int    $subscriptionID   The subscription to generate a summary for
     * @param string $billingPeriodStart Start date (YYYY-MM-DD)
     * @param string $billingPeriodEnd   End date (YYYY-MM-DD)
     *
     * @return array Associative array:
     *   - 'success'   (bool)  Whether the summary was generated
     *   - 'summaryID' (int)   The billing summary ID
     *   - 'summary'   (array) The complete billing summary record
     *   - 'message'   (string) Error or status message
     *
     * @see self::calculateUsageCost()  Core cost calculation method
     * @see tblUsageBillingSummary      Billing summary storage
     */
    public static function generateBillingSummary(
        int $subscriptionID,
        string $billingPeriodStart,
        string $billingPeriodEnd
    ): array {
        try {
            // 🔍 Check for existing summary to ensure idempotency
            // Prevents duplicate summaries for the same subscription/period
            $existingSummary = Database::fetchOne(
                "SELECT summaryID
                 FROM tblUsageBillingSummary
                 WHERE subscriptionID = ?
                   AND billingPeriodStart = ?
                   AND billingPeriodEnd = ?
                   AND status != 'void'",
                [$subscriptionID, $billingPeriodStart, $billingPeriodEnd],
                'iss'
            );

            if ($existingSummary) {
                // 📋 Summary already exists — return it instead of duplicating
                $summary = self::getSummary((int) $existingSummary['summaryID']);

                return [
                    'success'   => true,
                    'summaryID' => (int) $existingSummary['summaryID'],
                    'summary'   => $summary,
                    'message'   => 'Existing billing summary found — returning cached result',
                ];
            }

            // 🧮 Calculate usage cost for the billing period
            $costResult = self::calculateUsageCost($subscriptionID, $billingPeriodStart, $billingPeriodEnd);

            if (!$costResult['success']) {
                // ⚠️ Cost calculation failed — propagate the error
                return [
                    'success' => false,
                    'message' => $costResult['message'] ?? 'Usage cost calculation failed',
                ];
            }

            // 📋 Retrieve subscription details for the summary record
            $subscription = Database::fetchOne(
                "SELECT userID, partnerID
                 FROM tblSubscriptions
                 WHERE subscriptionID = ?",
                [$subscriptionID],
                'i'
            );

            if (!$subscription) {
                return [
                    'success' => false,
                    'message' => 'Subscription not found (ID: ' . $subscriptionID . ')',
                ];
            }

            $userID    = (int) $subscription['userID'];
            $partnerID = $subscription['partnerID'] !== null ? (int) $subscription['partnerID'] : null;

            // 📦 Encode line items as JSON for storage
            // @see https://www.php.net/manual/en/function.json-encode.php
            $lineItemsJSON = json_encode($costResult['lineItems'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            // 💾 Insert billing summary into tblUsageBillingSummary
            Database::query(
                "INSERT INTO tblUsageBillingSummary
                    (subscriptionID, userID, partnerID, billingPeriodStart, billingPeriodEnd,
                     billingMode, totalUsageCost, tierFixedPrice, cappedAmount, capApplied,
                     savingsAmount, currency, lineItems, calculatedAt, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')",
                [
                    $subscriptionID,
                    $userID,
                    $partnerID,
                    $billingPeriodStart,
                    $billingPeriodEnd,
                    $costResult['billingMode'],
                    $costResult['totalUsageCost'],
                    $costResult['tierFixedPrice'],
                    $costResult['cappedAmount'],
                    $costResult['capApplied'] ? 1 : 0,
                    $costResult['savingsAmount'],
                    $costResult['currency'],
                    $lineItemsJSON,
                ],
                'iiisssssssisss'
            );

            // 🔢 Get the newly inserted summary ID
            // @see https://www.php.net/manual/en/mysqli.insert-id.php
            $summaryID = Database::getLastInsertId();

            // ✅ Mark associated usage records as processed
            // This prevents the same records from being billed again
            Database::query(
                "UPDATE tblUsageRecords
                 SET isProcessed = 1
                 WHERE userID = ?
                   AND billingPeriodStart = ?
                   AND billingPeriodEnd = ?
                   AND isProcessed = 0",
                [$userID, $billingPeriodStart, $billingPeriodEnd],
                'iss'
            );

            // 📝 Log the billing summary generation for audit trail
            ActivityLogger::log(
                $userID,
                'usage_billing_summary_generated',
                'payment',
                'info',
                'Usage billing summary generated: ' . $costResult['currency'] . ' '
                    . $costResult['cappedAmount'] . ' for period '
                    . $billingPeriodStart . ' to ' . $billingPeriodEnd,
                [
                    'summaryID'        => $summaryID,
                    'subscriptionID'   => $subscriptionID,
                    'totalUsageCost'   => $costResult['totalUsageCost'],
                    'tierFixedPrice'   => $costResult['tierFixedPrice'],
                    'cappedAmount'     => $costResult['cappedAmount'],
                    'capApplied'       => $costResult['capApplied'],
                    'savingsAmount'    => $costResult['savingsAmount'],
                    'billingMode'      => $costResult['billingMode'],
                    'lineItemCount'    => count($costResult['lineItems']),
                ]
            );

            // 📋 Fetch the full persisted summary to return
            $summary = self::getSummary($summaryID);

            return [
                'success'   => true,
                'summaryID' => $summaryID,
                'summary'   => $summary,
                'message'   => 'Billing summary generated successfully',
            ];

        } catch (\Exception $e) {
            // ❌ Unexpected error during summary generation
            ActivityLogger::log(
                null,
                'usage_billing_error',
                'payment',
                'error',
                'Billing summary generation failed for subscription #' . $subscriptionID . ': ' . $e->getMessage(),
                [
                    'subscriptionID'     => $subscriptionID,
                    'billingPeriodStart' => $billingPeriodStart,
                    'billingPeriodEnd'   => $billingPeriodEnd,
                    'exceptionTrace'     => $e->getTraceAsString(),
                ]
            );

            return [
                'success' => false,
                'message' => 'Billing summary generation failed: ' . $e->getMessage(),
            ];
        }
    }


    // ========================================================================
    // ⚙️ BATCH PROCESSING
    // ========================================================================

    /**
     * 🔄 Process usage billing for all eligible subscriptions in batch
     *
     * Finds all active subscriptions with billingMode in ('usage', 'hybrid')
     * that have a billing period ending today or earlier and no existing
     * non-void billing summary. Calls generateBillingSummary() for each.
     *
     * This method is designed to be called by BillingScheduler or a cron
     * endpoint. It processes in batches to avoid memory/timeout issues on
     * shared hosting environments (Dreamhost).
     *
     * @param int $batchSize Maximum number of subscriptions to process in this
     *                       batch (default: 50). Keep low for shared hosting.
     *
     * @return array Associative array:
     *   - 'success'   (bool)  Whether the batch completed without fatal errors
     *   - 'processed' (int)   Number of summaries successfully generated
     *   - 'failed'    (int)   Number of subscriptions that failed
     *   - 'results'   (array) Per-subscription result details
     *
     * @see self::generateBillingSummary()  Per-subscription summary generation
     * @see BillingScheduler::processDueTasks()  Typical caller
     */
    public static function processUsageBilling(int $batchSize = 50): array
    {
        $processed = 0;
        $failed    = 0;
        $results   = [];

        try {
            // 📊 Find subscriptions with usage/hybrid billing that need processing
            // A subscription needs processing if:
            //   1. Its billingMode is 'usage' or 'hybrid'
            //   2. It is in an active/billable status
            //   3. Its nextBillingDate is today or in the past (period has ended)
            //   4. No non-void summary exists for the current billing period
            //
            // The billing period is inferred from billingCycle and nextBillingDate:
            //   - Monthly:  billingPeriodStart = nextBillingDate - 1 month
            //   - Yearly:   billingPeriodStart = nextBillingDate - 1 year
            //   - billingPeriodEnd = nextBillingDate - 1 day (last day of the period)
            $eligibleSubscriptions = Database::fetchAll(
                "SELECT s.subscriptionID, s.userID, s.partnerID, s.tierID,
                        s.billingMode, s.billingCycle, s.nextBillingDate,
                        CASE s.billingCycle
                            WHEN 'monthly' THEN DATE_SUB(s.nextBillingDate, INTERVAL 1 MONTH)
                            WHEN 'yearly'  THEN DATE_SUB(s.nextBillingDate, INTERVAL 1 YEAR)
                            ELSE DATE_SUB(s.nextBillingDate, INTERVAL 1 MONTH)
                        END AS billingPeriodStart,
                        DATE_SUB(s.nextBillingDate, INTERVAL 1 DAY) AS billingPeriodEnd
                 FROM tblSubscriptions s
                 WHERE s.billingMode IN ('usage', 'hybrid')
                   AND s.status IN ('active', 'past_due')
                   AND s.nextBillingDate <= CURDATE()
                   AND NOT EXISTS (
                       SELECT 1 FROM tblUsageBillingSummary bs
                       WHERE bs.subscriptionID = s.subscriptionID
                         AND bs.billingPeriodEnd = DATE_SUB(s.nextBillingDate, INTERVAL 1 DAY)
                         AND bs.status != 'void'
                   )
                 ORDER BY s.nextBillingDate ASC
                 LIMIT ?",
                [$batchSize],
                'i'
            );

            if (empty($eligibleSubscriptions)) {
                // 💡 No subscriptions need processing — this is normal
                return [
                    'success'   => true,
                    'processed' => 0,
                    'failed'    => 0,
                    'results'   => [],
                ];
            }

            // 🔄 Process each eligible subscription
            foreach ($eligibleSubscriptions as $sub) {
                $subID         = (int) $sub['subscriptionID'];
                $periodStart   = $sub['billingPeriodStart'];
                $periodEnd     = $sub['billingPeriodEnd'];

                // 🧾 Generate billing summary for this subscription
                $result = self::generateBillingSummary($subID, $periodStart, $periodEnd);

                if ($result['success']) {
                    $processed++;
                } else {
                    $failed++;
                }

                // 📋 Record the result for this subscription
                $results[] = [
                    'subscriptionID'   => $subID,
                    'billingPeriodStart' => $periodStart,
                    'billingPeriodEnd'   => $periodEnd,
                    'success'          => $result['success'],
                    'summaryID'        => $result['summaryID'] ?? null,
                    'message'          => $result['message'] ?? '',
                ];
            }

            // 📝 Log batch processing completion
            ActivityLogger::log(
                null,
                'usage_billing_batch_processed',
                'payment',
                'info',
                'Usage billing batch processed: ' . $processed . ' succeeded, ' . $failed . ' failed',
                [
                    'processed' => $processed,
                    'failed'    => $failed,
                    'batchSize' => $batchSize,
                    'total'     => count($eligibleSubscriptions),
                ]
            );

            return [
                'success'   => true,
                'processed' => $processed,
                'failed'    => $failed,
                'results'   => $results,
            ];

        } catch (\Exception $e) {
            // ❌ Fatal error during batch processing
            ActivityLogger::log(
                null,
                'usage_billing_batch_error',
                'payment',
                'error',
                'Usage billing batch processing failed: ' . $e->getMessage(),
                [
                    'processed'      => $processed,
                    'failed'         => $failed,
                    'exceptionTrace' => $e->getTraceAsString(),
                ]
            );

            return [
                'success'   => false,
                'processed' => $processed,
                'failed'    => $failed,
                'results'   => $results,
            ];
        }
    }


    // ========================================================================
    // 📊 RATE MANAGEMENT
    // ========================================================================

    /**
     * 📋 Get all active usage rates for a tier, valid on a given date
     *
     * Returns rate definitions joined with their metric definitions,
     * filtered by the effective date range. This is used by
     * calculateUsageCost() to determine which rates to apply.
     *
     * @param int     $tierID   The tier ID to fetch rates for
     * @param string  $tierType Tier type: 'global' or 'partner' (default: 'global')
     * @param ?string $date     Date to check validity against (YYYY-MM-DD).
     *                          Defaults to today if null.
     *
     * @return array Array of rate records with joined metric details, or empty
     *               array if no rates found. Each record contains:
     *   - rateID, metricID, tierID, tierType, ratePerUnit, freeAllowance,
     *     overageRate, overageCapUnits, currency, effectiveFrom, effectiveUntil,
     *     metricCode, metricName, unitLabel, billingGranularity, aggregationType
     *
     * @see tblUsageRates    Rate definitions
     * @see tblUsageMetrics  Metric definitions
     */
    public static function getActiveRatesForTier(int $tierID, string $tierType = 'global', ?string $date = null): array
    {
        // 📅 Default to today's date if no date specified
        // @see https://www.php.net/manual/en/function.date.php
        if ($date === null) {
            $date = date('Y-m-d');
        }

        // 📊 Query rates joined with their metric definitions
        // Filters:
        //   1. Rate matches the specified tier and tier type
        //   2. Rate is active (isActive = 1)
        //   3. Rate's effective date range includes the specified date
        //   4. Metric is active (m.isActive = 1)
        // @see https://dev.mysql.com/doc/refman/8.0/en/join.html
        $rates = Database::fetchAll(
            "SELECT r.rateID, r.metricID, r.tierID, r.tierType,
                    r.ratePerUnit, r.freeAllowance, r.overageRate,
                    r.overageCapUnits, r.currency,
                    r.effectiveFrom, r.effectiveUntil,
                    m.metricCode, m.metricName, m.unitLabel,
                    m.billingGranularity, m.aggregationType
             FROM tblUsageRates r
             INNER JOIN tblUsageMetrics m ON r.metricID = m.metricID
             WHERE r.tierID = ?
               AND r.tierType = ?
               AND r.isActive = 1
               AND r.effectiveFrom <= ?
               AND (r.effectiveUntil IS NULL OR r.effectiveUntil >= ?)
               AND m.isActive = 1
             ORDER BY m.metricCode ASC",
            [$tierID, $tierType, $date, $date],
            'isss'
        );

        return $rates ?: [];
    }

    /**
     * ➕ Create a new usage rate for a tier/metric combination
     *
     * Inserts a new rate record into tblUsageRates. The rate becomes effective
     * from the specified date (defaults to today) and has no end date by default.
     *
     * @param int     $metricID       The metric this rate applies to
     * @param int     $tierID         The subscription tier this rate applies to
     * @param string  $tierType       'global' or 'partner'
     * @param string  $ratePerUnit    Price per unit (BCMath string, DECIMAL(12,6))
     * @param string  $freeAllowance  Free units included (default: '0')
     * @param ?string $overageRate    Rate for units beyond free allowance (null = use ratePerUnit)
     * @param ?string $overageCapUnits Maximum billable units beyond free allowance (null = unlimited)
     * @param string  $currency       Currency code, ISO 4217 (default: 'GBP')
     * @param string  $effectiveFrom  Start date (YYYY-MM-DD or 'today')
     *
     * @return array Associative array:
     *   - 'success' (bool) Whether the rate was created
     *   - 'rateID'  (int)  The new rate ID
     *   - 'message' (string) Error message on failure
     *
     * @see tblUsageRates    Rate storage table
     * @see tblUsageMetrics  Metric definitions (for validation)
     */
    public static function createRate(
        int $metricID,
        int $tierID,
        string $tierType,
        string $ratePerUnit,
        string $freeAllowance = '0',
        ?string $overageRate = null,
        ?string $overageCapUnits = null,
        string $currency = 'GBP',
        string $effectiveFrom = 'today'
    ): array {
        try {
            // 📅 Resolve 'today' to actual date
            if ($effectiveFrom === 'today') {
                $effectiveFrom = date('Y-m-d');
            }

            // 🔒 Validate tier type
            if (!in_array($tierType, ['global', 'partner'], true)) {
                return [
                    'success' => false,
                    'message' => 'Invalid tier type: ' . $tierType . '. Must be "global" or "partner".',
                ];
            }

            // 🔍 Validate metric exists and is active
            $metric = Database::fetchOne(
                "SELECT metricID, metricName
                 FROM tblUsageMetrics
                 WHERE metricID = ?
                   AND isActive = 1",
                [$metricID],
                'i'
            );

            if (!$metric) {
                return [
                    'success' => false,
                    'message' => 'Metric not found or inactive (ID: ' . $metricID . ')',
                ];
            }

            // 🔍 Validate rate values are non-negative using bccomp
            // @see https://www.php.net/manual/en/function.bccomp.php
            if (bccomp($ratePerUnit, '0', self::RATE_SCALE) < 0) {
                return [
                    'success' => false,
                    'message' => 'Rate per unit cannot be negative',
                ];
            }

            if (bccomp($freeAllowance, '0', self::MONETARY_SCALE) < 0) {
                return [
                    'success' => false,
                    'message' => 'Free allowance cannot be negative',
                ];
            }

            if ($overageRate !== null && bccomp($overageRate, '0', self::RATE_SCALE) < 0) {
                return [
                    'success' => false,
                    'message' => 'Overage rate cannot be negative',
                ];
            }

            if ($overageCapUnits !== null && bccomp($overageCapUnits, '0', self::MONETARY_SCALE) < 0) {
                return [
                    'success' => false,
                    'message' => 'Overage cap units cannot be negative',
                ];
            }

            // 💾 Insert the new rate into tblUsageRates
            Database::query(
                "INSERT INTO tblUsageRates
                    (metricID, tierID, tierType, ratePerUnit, freeAllowance,
                     overageRate, overageCapUnits, currency, isActive,
                     effectiveFrom, effectiveUntil)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NULL)",
                [
                    $metricID,
                    $tierID,
                    $tierType,
                    $ratePerUnit,
                    $freeAllowance,
                    $overageRate,
                    $overageCapUnits,
                    $currency,
                    $effectiveFrom,
                ],
                'iisssssss'
            );

            // 🔢 Get the newly inserted rate ID
            $rateID = Database::getLastInsertId();

            // 📝 Log activity for audit trail
            ActivityLogger::log(
                null,
                'usage_rate_created',
                'payment',
                'info',
                'Usage rate created for metric "' . $metric['metricName'] . '" on tier #' . $tierID
                    . ' (' . $tierType . '): ' . $currency . ' ' . $ratePerUnit . ' per unit',
                [
                    'rateID'         => $rateID,
                    'metricID'       => $metricID,
                    'tierID'         => $tierID,
                    'tierType'       => $tierType,
                    'ratePerUnit'    => $ratePerUnit,
                    'freeAllowance'  => $freeAllowance,
                    'overageRate'    => $overageRate,
                    'overageCapUnits' => $overageCapUnits,
                    'currency'       => $currency,
                    'effectiveFrom'  => $effectiveFrom,
                ]
            );

            return [
                'success' => true,
                'rateID'  => $rateID,
            ];

        } catch (\Exception $e) {
            // ❌ Unexpected error during rate creation
            ActivityLogger::log(
                null,
                'usage_rate_error',
                'payment',
                'error',
                'Failed to create usage rate: ' . $e->getMessage(),
                [
                    'metricID'    => $metricID,
                    'tierID'      => $tierID,
                    'tierType'    => $tierType,
                    'ratePerUnit' => $ratePerUnit,
                ]
            );

            return [
                'success' => false,
                'message' => 'Failed to create usage rate: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * ✏️ Update an existing usage rate
     *
     * Allows partial updates to a rate record. Only the fields present in
     * the $data array will be updated; all others remain unchanged.
     *
     * @param int   $rateID The rate ID to update
     * @param array $data   Associative array of fields to update. Allowed keys:
     *   - 'ratePerUnit'     (string) New per-unit rate (DECIMAL(12,6))
     *   - 'freeAllowance'   (string) New free allowance (DECIMAL(12,4))
     *   - 'overageRate'     (string|null) New overage rate or null to remove
     *   - 'overageCapUnits' (string|null) New overage cap or null to remove
     *   - 'currency'        (string) New currency code (ISO 4217)
     *   - 'isActive'        (int) 1 = active, 0 = inactive
     *   - 'effectiveFrom'   (string) New effective start date (YYYY-MM-DD)
     *   - 'effectiveUntil'  (string|null) New effective end date or null
     *
     * @return array Associative array:
     *   - 'success' (bool) Whether the update succeeded
     *   - 'message' (string) Status or error message
     *
     * @see tblUsageRates Schema in migration files
     */
    public static function updateRate(int $rateID, array $data): array
    {
        try {
            // 🔍 Verify rate exists
            $existingRate = Database::fetchOne(
                "SELECT rateID, metricID, tierID, tierType
                 FROM tblUsageRates
                 WHERE rateID = ?",
                [$rateID],
                'i'
            );

            if (!$existingRate) {
                return [
                    'success' => false,
                    'message' => 'Usage rate not found (ID: ' . $rateID . ')',
                ];
            }

            // 📋 Define allowed fields and their types for the UPDATE query
            // This whitelist prevents SQL injection via field names
            $allowedFields = [
                'ratePerUnit'    => 's',
                'freeAllowance'  => 's',
                'overageRate'    => 's',
                'overageCapUnits' => 's',
                'currency'       => 's',
                'isActive'       => 'i',
                'effectiveFrom'  => 's',
                'effectiveUntil' => 's',
            ];

            // 🔨 Build dynamic SET clause from provided data
            $setClauses = [];
            $params     = [];
            $types      = '';

            foreach ($data as $field => $value) {
                // 🔒 Only allow whitelisted fields
                if (!isset($allowedFields[$field])) {
                    continue;
                }

                // 📏 Validate non-negative monetary values using bccomp
                if (in_array($field, ['ratePerUnit', 'freeAllowance', 'overageRate', 'overageCapUnits'], true)) {
                    if ($value !== null && bccomp((string) $value, '0', self::RATE_SCALE) < 0) {
                        return [
                            'success' => false,
                            'message' => $field . ' cannot be negative',
                        ];
                    }
                }

                // 📝 Handle nullable fields (overageRate, overageCapUnits, effectiveUntil)
                if ($value === null && in_array($field, ['overageRate', 'overageCapUnits', 'effectiveUntil'], true)) {
                    $setClauses[] = $field . ' = NULL';
                    // No param or type needed for literal NULL
                } else {
                    $setClauses[] = $field . ' = ?';
                    $params[]     = $value;
                    $types       .= $allowedFields[$field];
                }
            }

            if (empty($setClauses)) {
                return [
                    'success' => false,
                    'message' => 'No valid fields provided for update',
                ];
            }

            // 🔢 Add the rateID for the WHERE clause
            $params[] = $rateID;
            $types   .= 'i';

            // 💾 Execute the UPDATE query
            $sql = "UPDATE tblUsageRates SET " . implode(', ', $setClauses) . " WHERE rateID = ?";
            Database::query($sql, $params, $types);

            // 📝 Log the update for audit trail
            ActivityLogger::log(
                null,
                'usage_rate_updated',
                'payment',
                'info',
                'Usage rate #' . $rateID . ' updated (metric #' . $existingRate['metricID']
                    . ', tier #' . $existingRate['tierID'] . ')',
                [
                    'rateID'       => $rateID,
                    'metricID'     => (int) $existingRate['metricID'],
                    'tierID'       => (int) $existingRate['tierID'],
                    'updatedFields' => array_keys($data),
                    'newValues'    => $data,
                ]
            );

            return [
                'success' => true,
                'message' => 'Usage rate updated successfully',
            ];

        } catch (\Exception $e) {
            // ❌ Unexpected error
            ActivityLogger::log(
                null,
                'usage_rate_error',
                'payment',
                'error',
                'Failed to update usage rate #' . $rateID . ': ' . $e->getMessage(),
                ['rateID' => $rateID, 'data' => $data]
            );

            return [
                'success' => false,
                'message' => 'Failed to update usage rate: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 🗑️ Soft-delete a usage rate
     *
     * Does NOT physically remove the rate — instead sets isActive = 0 and
     * effectiveUntil = today. This preserves historical data for auditing
     * and prevents orphaned references in existing billing summaries.
     *
     * @param int $rateID The rate ID to soft-delete
     *
     * @return array Associative array:
     *   - 'success' (bool) Whether the deletion succeeded
     *   - 'message' (string) Status or error message
     *
     * @see self::updateRate() Used internally for the actual update
     */
    public static function deleteRate(int $rateID): array
    {
        try {
            // 🔍 Verify rate exists
            $existingRate = Database::fetchOne(
                "SELECT rateID, metricID, tierID
                 FROM tblUsageRates
                 WHERE rateID = ?",
                [$rateID],
                'i'
            );

            if (!$existingRate) {
                return [
                    'success' => false,
                    'message' => 'Usage rate not found (ID: ' . $rateID . ')',
                ];
            }

            // 🗑️ Soft-delete: deactivate and set end date to today
            Database::query(
                "UPDATE tblUsageRates
                 SET isActive = 0, effectiveUntil = CURDATE()
                 WHERE rateID = ?",
                [$rateID],
                'i'
            );

            // 📝 Log the soft-deletion
            ActivityLogger::log(
                null,
                'usage_rate_deleted',
                'payment',
                'info',
                'Usage rate #' . $rateID . ' soft-deleted (metric #' . $existingRate['metricID']
                    . ', tier #' . $existingRate['tierID'] . ')',
                [
                    'rateID'   => $rateID,
                    'metricID' => (int) $existingRate['metricID'],
                    'tierID'   => (int) $existingRate['tierID'],
                ]
            );

            return [
                'success' => true,
                'message' => 'Usage rate soft-deleted successfully',
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'usage_rate_error',
                'payment',
                'error',
                'Failed to delete usage rate #' . $rateID . ': ' . $e->getMessage(),
                ['rateID' => $rateID]
            );

            return [
                'success' => false,
                'message' => 'Failed to delete usage rate: ' . $e->getMessage(),
            ];
        }
    }


    // ========================================================================
    // 📋 SUMMARY RETRIEVAL
    // ========================================================================

    /**
     * 🔍 Get a single billing summary by its ID
     *
     * Retrieves a complete billing summary record from tblUsageBillingSummary,
     * including decoded lineItems JSON.
     *
     * @param int $summaryID The billing summary ID to retrieve
     *
     * @return ?array The billing summary record with decoded lineItems,
     *                or null if not found
     *
     * @see tblUsageBillingSummary Schema in migration files
     * @see https://www.php.net/manual/en/function.json-decode.php
     */
    public static function getSummary(int $summaryID): ?array
    {
        // 📊 Fetch the summary record
        $summary = Database::fetchOne(
            "SELECT summaryID, subscriptionID, userID, partnerID,
                    billingPeriodStart, billingPeriodEnd, billingMode,
                    totalUsageCost, tierFixedPrice, cappedAmount, capApplied,
                    savingsAmount, currency, lineItems, invoiceID, paymentID,
                    calculatedAt, status, notes
             FROM tblUsageBillingSummary
             WHERE summaryID = ?",
            [$summaryID],
            'i'
        );

        if (!$summary) {
            return null;
        }

        // 🔓 Decode the lineItems JSON string into a PHP array
        // @see https://www.php.net/manual/en/function.json-decode.php
        if ($summary['lineItems'] !== null) {
            $summary['lineItems'] = json_decode($summary['lineItems'], true) ?? [];
        } else {
            $summary['lineItems'] = [];
        }

        // 🔢 Cast boolean field from TINYINT to actual boolean
        $summary['capApplied'] = (bool) $summary['capApplied'];

        return $summary;
    }

    /**
     * 📋 Get billing summaries for a specific subscription
     *
     * Returns paginated billing summaries ordered by billing period start
     * date descending (most recent first). Useful for subscription detail
     * pages and billing history views.
     *
     * @param int $subscriptionID The subscription to fetch summaries for
     * @param int $limit          Maximum records to return (default: 12)
     * @param int $offset         Number of records to skip (default: 0)
     *
     * @return array Array of billing summary records with decoded lineItems
     *
     * @see self::getSummary()  Used to decode each summary's lineItems
     */
    public static function getSummariesForSubscription(int $subscriptionID, int $limit = 12, int $offset = 0): array
    {
        // 📊 Fetch summaries for this subscription, ordered most recent first
        $summaries = Database::fetchAll(
            "SELECT summaryID, subscriptionID, userID, partnerID,
                    billingPeriodStart, billingPeriodEnd, billingMode,
                    totalUsageCost, tierFixedPrice, cappedAmount, capApplied,
                    savingsAmount, currency, lineItems, invoiceID, paymentID,
                    calculatedAt, status, notes
             FROM tblUsageBillingSummary
             WHERE subscriptionID = ?
             ORDER BY billingPeriodStart DESC
             LIMIT ? OFFSET ?",
            [$subscriptionID, $limit, $offset],
            'iii'
        );

        if (!$summaries) {
            return [];
        }

        // 🔓 Decode lineItems JSON for each summary
        foreach ($summaries as &$summary) {
            if ($summary['lineItems'] !== null) {
                $summary['lineItems'] = json_decode($summary['lineItems'], true) ?? [];
            } else {
                $summary['lineItems'] = [];
            }
            $summary['capApplied'] = (bool) $summary['capApplied'];
        }
        unset($summary); // 🧹 Break the reference from the foreach

        return $summaries;
    }

    /**
     * 👤 Get billing summaries for a specific user across all subscriptions
     *
     * Returns paginated billing summaries for a user, spanning all of their
     * subscriptions. Useful for user account / billing history pages.
     *
     * @param int $userID The user ID to fetch summaries for
     * @param int $limit  Maximum records to return (default: 12)
     * @param int $offset Number of records to skip (default: 0)
     *
     * @return array Array of billing summary records with decoded lineItems
     */
    public static function getSummariesForUser(int $userID, int $limit = 12, int $offset = 0): array
    {
        // 📊 Fetch summaries for this user across all subscriptions
        $summaries = Database::fetchAll(
            "SELECT summaryID, subscriptionID, userID, partnerID,
                    billingPeriodStart, billingPeriodEnd, billingMode,
                    totalUsageCost, tierFixedPrice, cappedAmount, capApplied,
                    savingsAmount, currency, lineItems, invoiceID, paymentID,
                    calculatedAt, status, notes
             FROM tblUsageBillingSummary
             WHERE userID = ?
             ORDER BY billingPeriodStart DESC
             LIMIT ? OFFSET ?",
            [$userID, $limit, $offset],
            'iii'
        );

        if (!$summaries) {
            return [];
        }

        // 🔓 Decode lineItems JSON for each summary
        foreach ($summaries as &$summary) {
            if ($summary['lineItems'] !== null) {
                $summary['lineItems'] = json_decode($summary['lineItems'], true) ?? [];
            } else {
                $summary['lineItems'] = [];
            }
            $summary['capApplied'] = (bool) $summary['capApplied'];
        }
        unset($summary); // 🧹 Break the reference from the foreach

        return $summaries;
    }


    // ========================================================================
    // 🔄 STATUS MANAGEMENT
    // ========================================================================

    /**
     * 🔄 Update the status of a billing summary
     *
     * Transitions a billing summary between statuses (e.g., pending -> invoiced
     * -> paid). Optionally links an invoice and/or payment record.
     *
     * Valid status transitions:
     *   - pending  -> invoiced, void
     *   - invoiced -> paid, failed, void
     *   - failed   -> pending (retry), void
     *   - paid     -> void (refund scenario)
     *
     * @param int     $summaryID The billing summary to update
     * @param string  $status    New status (from SUMMARY_STATUSES)
     * @param ?int    $invoiceID Optional invoice ID to link
     * @param ?int    $paymentID Optional payment ID to link
     *
     * @return array Associative array:
     *   - 'success'        (bool)   Whether the update succeeded
     *   - 'previousStatus' (string) The status before the update
     *   - 'newStatus'      (string) The new status
     *   - 'message'        (string) Status or error message
     *
     * @see self::SUMMARY_STATUSES  Valid status values
     * @see tblUsageBillingSummary  Billing summary table
     */
    public static function updateSummaryStatus(
        int $summaryID,
        string $status,
        ?int $invoiceID = null,
        ?int $paymentID = null
    ): array {
        try {
            // 🔒 Validate the new status against allowed values
            if (!in_array($status, self::SUMMARY_STATUSES, true)) {
                return [
                    'success' => false,
                    'message' => 'Invalid status: ' . $status . '. Allowed: '
                        . implode(', ', self::SUMMARY_STATUSES),
                ];
            }

            // 🔍 Fetch current summary to get previous status
            $currentSummary = Database::fetchOne(
                "SELECT summaryID, status, subscriptionID, userID
                 FROM tblUsageBillingSummary
                 WHERE summaryID = ?",
                [$summaryID],
                'i'
            );

            if (!$currentSummary) {
                return [
                    'success' => false,
                    'message' => 'Billing summary not found (ID: ' . $summaryID . ')',
                ];
            }

            $previousStatus = $currentSummary['status'];

            // 🔨 Build the UPDATE query dynamically based on provided optional fields
            $setClauses = ['status = ?'];
            $params     = [$status];
            $types      = 's';

            // 📎 Optionally link an invoice
            if ($invoiceID !== null) {
                $setClauses[] = 'invoiceID = ?';
                $params[]     = $invoiceID;
                $types       .= 'i';
            }

            // 📎 Optionally link a payment
            if ($paymentID !== null) {
                $setClauses[] = 'paymentID = ?';
                $params[]     = $paymentID;
                $types       .= 'i';
            }

            // 🔢 Add summaryID for WHERE clause
            $params[] = $summaryID;
            $types   .= 'i';

            // 💾 Execute the status update
            $sql = "UPDATE tblUsageBillingSummary SET " . implode(', ', $setClauses) . " WHERE summaryID = ?";
            Database::query($sql, $params, $types);

            // 📝 Log the status transition for audit trail
            ActivityLogger::log(
                (int) $currentSummary['userID'],
                'usage_billing_status_changed',
                'payment',
                'info',
                'Billing summary #' . $summaryID . ' status changed: '
                    . $previousStatus . ' -> ' . $status,
                [
                    'summaryID'      => $summaryID,
                    'subscriptionID' => (int) $currentSummary['subscriptionID'],
                    'previousStatus' => $previousStatus,
                    'newStatus'      => $status,
                    'invoiceID'      => $invoiceID,
                    'paymentID'      => $paymentID,
                ]
            );

            return [
                'success'        => true,
                'previousStatus' => $previousStatus,
                'newStatus'      => $status,
                'message'        => 'Billing summary status updated successfully',
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'usage_billing_error',
                'payment',
                'error',
                'Failed to update billing summary #' . $summaryID . ' status: ' . $e->getMessage(),
                ['summaryID' => $summaryID, 'targetStatus' => $status]
            );

            return [
                'success' => false,
                'message' => 'Failed to update billing summary status: ' . $e->getMessage(),
            ];
        }
    }


    // ========================================================================
    // 🔮 PROJECTED COST & CAP PROXIMITY
    // ========================================================================

    /**
     * 📈 Calculate projected cost for the current billing period
     *
     * Estimates the total usage cost for the current billing period by:
     *   1. Calculating actual usage cost so far (using calculateUsageCost)
     *   2. Determining how far through the billing period we are
     *   3. Extrapolating the cost linearly to the full period length
     *
     * This is useful for dashboards and budget alerts — it shows users
     * what their bill might look like if usage continues at the current rate.
     *
     * @param int $subscriptionID The subscription to project costs for
     *
     * @return array Associative array:
     *   - 'success'          (bool)   Whether the projection succeeded
     *   - 'currentCost'      (string) Actual usage cost so far (BCMath string)
     *   - 'projectedCost'    (string) Extrapolated full-period cost (BCMath string)
     *   - 'tierCap'          (string) Tier fixed price cap (BCMath string, hybrid only)
     *   - 'daysElapsed'      (int)    Days elapsed in current period
     *   - 'daysRemaining'    (int)    Days remaining in current period
     *   - 'totalDays'        (int)    Total days in the billing period
     *   - 'capLikelyToApply' (bool)   Whether projected cost will hit the cap
     *   - 'currency'         (string) Currency code
     *   - 'message'          (string) Error message on failure
     *
     * @see self::calculateUsageCost()  Used for actual cost calculation
     * @see https://www.php.net/manual/en/class.datetime.php
     */
    public static function getProjectedCost(int $subscriptionID): array
    {
        try {
            // 📋 Retrieve subscription details to determine the current billing period
            $subscription = Database::fetchOne(
                "SELECT subscriptionID, userID, tierID, billingMode, billingCycle,
                        nextBillingDate, status
                 FROM tblSubscriptions
                 WHERE subscriptionID = ?
                   AND status IN ('active', 'past_due', 'trialing')",
                [$subscriptionID],
                'i'
            );

            if (!$subscription) {
                return [
                    'success' => false,
                    'message' => 'Subscription not found or not in a billable state (ID: ' . $subscriptionID . ')',
                ];
            }

            $billingMode = $subscription['billingMode'];

            // 🔍 Fixed-mode subscriptions have no usage component
            if ($billingMode === self::MODE_FIXED) {
                return [
                    'success'          => true,
                    'currentCost'      => '0.0000',
                    'projectedCost'    => '0.0000',
                    'tierCap'          => '0.0000',
                    'daysElapsed'      => 0,
                    'daysRemaining'    => 0,
                    'totalDays'        => 0,
                    'capLikelyToApply' => false,
                    'currency'         => self::DEFAULT_CURRENCY,
                ];
            }

            // 📅 Calculate billing period boundaries from nextBillingDate and billingCycle
            // The current period runs from (nextBillingDate - cycle) to (nextBillingDate - 1 day)
            // @see https://www.php.net/manual/en/class.datetime.php
            $nextBillingDate = new \DateTime($subscription['nextBillingDate']);
            $periodEnd       = (clone $nextBillingDate)->modify('-1 day');

            // 📅 Calculate period start based on billing cycle
            $periodStart = match ($subscription['billingCycle']) {
                'yearly'  => (clone $nextBillingDate)->modify('-1 year'),
                default   => (clone $nextBillingDate)->modify('-1 month'), // monthly default
            };

            $billingPeriodStart = $periodStart->format('Y-m-d');
            $billingPeriodEnd   = $periodEnd->format('Y-m-d');

            // 📅 Calculate elapsed and remaining days
            $today       = new \DateTime(date('Y-m-d'));
            $totalDays   = (int) $periodStart->diff($periodEnd)->days + 1; // inclusive
            $daysElapsed = max(1, (int) $periodStart->diff($today)->days + 1); // at least 1

            // 🔒 Clamp elapsed days to total days (in case we're past the period end)
            $daysElapsed   = min($daysElapsed, $totalDays);
            $daysRemaining = max(0, $totalDays - $daysElapsed);

            // 🧮 Calculate actual usage cost for the period so far
            $costResult = self::calculateUsageCost($subscriptionID, $billingPeriodStart, $billingPeriodEnd);

            if (!$costResult['success']) {
                return [
                    'success' => false,
                    'message' => $costResult['message'] ?? 'Cost calculation failed for projection',
                ];
            }

            $currentCost = $costResult['totalUsageCost'];
            $tierCap     = $costResult['tierFixedPrice'];
            $currency    = $costResult['currency'];

            // 📈 Extrapolate: projectedCost = (currentCost / daysElapsed) * totalDays
            // This assumes usage continues at the same daily rate
            // @see https://www.php.net/manual/en/function.bcdiv.php
            $dailyRate     = bcdiv($currentCost, (string) $daysElapsed, self::RATE_SCALE);
            $projectedCost = bcmul($dailyRate, (string) $totalDays, self::MONETARY_SCALE);

            // 🔮 Determine if the cap is likely to be hit (hybrid mode only)
            $capLikelyToApply = false;

            if ($billingMode === self::MODE_HYBRID && bccomp($tierCap, '0', self::MONETARY_SCALE) > 0) {
                // 📊 If projected cost exceeds the tier cap, the cap will likely apply
                $capLikelyToApply = bccomp($projectedCost, $tierCap, self::MONETARY_SCALE) > 0;
            }

            return [
                'success'          => true,
                'currentCost'      => $currentCost,
                'projectedCost'    => $projectedCost,
                'tierCap'          => $tierCap,
                'daysElapsed'      => $daysElapsed,
                'daysRemaining'    => $daysRemaining,
                'totalDays'        => $totalDays,
                'capLikelyToApply' => $capLikelyToApply,
                'currency'         => $currency,
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'usage_billing_error',
                'payment',
                'error',
                'Cost projection failed for subscription #' . $subscriptionID . ': ' . $e->getMessage(),
                ['subscriptionID' => $subscriptionID]
            );

            return [
                'success' => false,
                'message' => 'Cost projection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * ⚠️ Check if current usage cost is approaching the tier cap
     *
     * Used to trigger approaching-cap notifications for hybrid-mode
     * subscriptions. Compares current usage cost against the tier's
     * fixed monthly price and returns whether it exceeds the threshold.
     *
     * @param int   $subscriptionID The subscription to check
     * @param float $threshold      Percentage threshold (0.0 to 1.0, default: 0.80 = 80%)
     *
     * @return array Associative array:
     *   - 'success'     (bool)   Whether the check succeeded
     *   - 'approaching' (bool)   Whether usage is approaching the cap
     *   - 'percentage'  (float)  Current usage as a percentage of the cap (0.0 to 100.0+)
     *   - 'currentCost' (string) Current usage cost (BCMath string)
     *   - 'tierCap'     (string) Tier fixed price cap (BCMath string)
     *   - 'message'     (string) Error message on failure
     *
     * @see self::getProjectedCost()  Used for current cost retrieval
     */
    public static function checkCapProximity(int $subscriptionID, float $threshold = 0.80): array
    {
        try {
            // 📈 Get projected cost data (includes current cost and tier cap)
            $projection = self::getProjectedCost($subscriptionID);

            if (!$projection['success']) {
                return [
                    'success' => false,
                    'message' => $projection['message'] ?? 'Failed to get cost projection',
                ];
            }

            $currentCost = $projection['currentCost'];
            $tierCap     = $projection['tierCap'];

            // 🔍 If there's no cap (usage mode or zero cap), can't approach it
            if (bccomp($tierCap, '0', self::MONETARY_SCALE) <= 0) {
                return [
                    'success'     => true,
                    'approaching' => false,
                    'percentage'  => 0.0,
                    'currentCost' => $currentCost,
                    'tierCap'     => $tierCap,
                    'message'     => 'No cap defined for this subscription',
                ];
            }

            // 📊 Calculate current usage as a percentage of the cap
            // percentage = (currentCost / tierCap) * 100
            // @see https://www.php.net/manual/en/function.bcdiv.php
            $ratio      = bcdiv($currentCost, $tierCap, self::RATE_SCALE);
            $percentage = (float) bcmul($ratio, '100', 2);

            // ⚠️ Check if the percentage exceeds the threshold
            // Threshold is given as a float (e.g., 0.80 = 80%)
            $thresholdPercentage = $threshold * 100;
            $approaching         = $percentage >= $thresholdPercentage;

            return [
                'success'     => true,
                'approaching' => $approaching,
                'percentage'  => $percentage,
                'currentCost' => $currentCost,
                'tierCap'     => $tierCap,
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'usage_billing_error',
                'payment',
                'error',
                'Cap proximity check failed for subscription #' . $subscriptionID . ': ' . $e->getMessage(),
                ['subscriptionID' => $subscriptionID, 'threshold' => $threshold]
            );

            return [
                'success' => false,
                'message' => 'Cap proximity check failed: ' . $e->getMessage(),
            ];
        }
    }


    // ========================================================================
    // 📊 ADMIN STATISTICS
    // ========================================================================

    /**
     * 📊 Get usage billing statistics for admin dashboards
     *
     * Aggregates key metrics across all usage billing summaries, optionally
     * filtered by partner. Provides totals, averages, and distribution
     * breakdowns for monitoring and reporting.
     *
     * @param ?int $partnerID Optional partner ID to filter stats by. If null,
     *                        returns system-wide statistics.
     *
     * @return array Associative array:
     *   - 'success'                (bool)   Whether stats were retrieved
     *   - 'totalSummaries'         (int)    Total billing summaries generated
     *   - 'totalRevenue'           (string) Total usage revenue (BCMath string)
     *   - 'totalSavings'           (string) Total cap savings for users (BCMath string)
     *   - 'averageCapSavings'      (string) Average savings per capped summary (BCMath string)
     *   - 'summariesByStatus'      (array)  Count of summaries grouped by status
     *   - 'subscriptionsByMode'    (array)  Count of active subscriptions by billing mode
     *   - 'topMetrics'             (array)  Most-used metrics by record count
     *   - 'currency'               (string) Primary currency
     *
     * @see tblUsageBillingSummary  Summary storage
     * @see tblSubscriptions       Subscription records
     */
    public static function getUsageBillingStats(?int $partnerID = null): array
    {
        try {
            // ─────────────────────────────────────────────────────────────────
            // 📊 Aggregate summary-level statistics
            // ─────────────────────────────────────────────────────────────────
            $partnerFilter = '';
            $params        = [];
            $types         = '';

            if ($partnerID !== null) {
                $partnerFilter = ' AND partnerID = ?';
                $params[]      = $partnerID;
                $types        .= 'i';
            }

            // 📈 Overall totals: count, revenue, savings
            $totals = Database::fetchOne(
                "SELECT COUNT(*) AS totalSummaries,
                        COALESCE(SUM(cappedAmount), 0) AS totalRevenue,
                        COALESCE(SUM(savingsAmount), 0) AS totalSavings,
                        CASE
                            WHEN SUM(capApplied) > 0
                            THEN COALESCE(SUM(savingsAmount) / SUM(capApplied), 0)
                            ELSE 0
                        END AS averageCapSavings
                 FROM tblUsageBillingSummary
                 WHERE status != 'void'" . $partnerFilter,
                !empty($params) ? $params : null,
                !empty($types) ? $types : null
            );

            // 📊 Summaries grouped by status
            $statusBreakdown = Database::fetchAll(
                "SELECT status, COUNT(*) AS count
                 FROM tblUsageBillingSummary
                 WHERE 1=1" . $partnerFilter . "
                 GROUP BY status
                 ORDER BY count DESC",
                !empty($params) ? $params : null,
                !empty($types) ? $types : null
            );

            // 🔢 Format status breakdown as associative array
            $summariesByStatus = [];
            if ($statusBreakdown) {
                foreach ($statusBreakdown as $row) {
                    $summariesByStatus[$row['status']] = (int) $row['count'];
                }
            }

            // ─────────────────────────────────────────────────────────────────
            // 📊 Subscriptions by billing mode
            // ─────────────────────────────────────────────────────────────────
            $modeParams = [];
            $modeTypes  = '';
            $modeFilter = '';

            if ($partnerID !== null) {
                $modeFilter = ' AND partnerID = ?';
                $modeParams[] = $partnerID;
                $modeTypes   .= 'i';
            }

            $modeBreakdown = Database::fetchAll(
                "SELECT billingMode, COUNT(*) AS count
                 FROM tblSubscriptions
                 WHERE status IN ('active', 'past_due', 'trialing')" . $modeFilter . "
                 GROUP BY billingMode
                 ORDER BY count DESC",
                !empty($modeParams) ? $modeParams : null,
                !empty($modeTypes) ? $modeTypes : null
            );

            $subscriptionsByMode = [];
            if ($modeBreakdown) {
                foreach ($modeBreakdown as $row) {
                    $subscriptionsByMode[$row['billingMode']] = (int) $row['count'];
                }
            }

            // ─────────────────────────────────────────────────────────────────
            // 📊 Top metrics by usage record count
            // ─────────────────────────────────────────────────────────────────
            $metricParams = [];
            $metricTypes  = '';
            $metricFilter = '';

            if ($partnerID !== null) {
                $metricFilter = ' AND ur.partnerID = ?';
                $metricParams[] = $partnerID;
                $metricTypes   .= 'i';
            }

            $topMetrics = Database::fetchAll(
                "SELECT um.metricCode, um.metricName, um.unitLabel,
                        COUNT(ur.recordID) AS recordCount,
                        COALESCE(SUM(ur.quantity), 0) AS totalQuantity
                 FROM tblUsageRecords ur
                 INNER JOIN tblUsageMetrics um ON ur.metricID = um.metricID
                 WHERE 1=1" . $metricFilter . "
                 GROUP BY um.metricID, um.metricCode, um.metricName, um.unitLabel
                 ORDER BY recordCount DESC
                 LIMIT 10",
                !empty($metricParams) ? $metricParams : null,
                !empty($metricTypes) ? $metricTypes : null
            );

            return [
                'success'             => true,
                'totalSummaries'      => $totals ? (int) $totals['totalSummaries'] : 0,
                'totalRevenue'        => $totals ? (string) $totals['totalRevenue'] : '0.0000',
                'totalSavings'        => $totals ? (string) $totals['totalSavings'] : '0.0000',
                'averageCapSavings'   => $totals ? (string) $totals['averageCapSavings'] : '0.0000',
                'summariesByStatus'   => $summariesByStatus,
                'subscriptionsByMode' => $subscriptionsByMode,
                'topMetrics'          => $topMetrics ?: [],
                'currency'            => self::DEFAULT_CURRENCY,
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'usage_billing_error',
                'payment',
                'error',
                'Failed to retrieve usage billing statistics: ' . $e->getMessage(),
                ['partnerID' => $partnerID]
            );

            return [
                'success' => false,
                'message' => 'Failed to retrieve usage billing statistics: ' . $e->getMessage(),
            ];
        }
    }


    // ========================================================================
    // 🔀 BILLING MODE MANAGEMENT
    // ========================================================================

    /**
     * 🔀 Change a subscription's billing mode
     *
     * Transitions a subscription between billing modes (fixed, usage, hybrid).
     * Validates that the subscription's tier supports usage billing before
     * allowing a switch to usage or hybrid mode. Logs the change for auditing.
     *
     * @param int    $subscriptionID The subscription to modify
     * @param string $newMode        New billing mode: 'fixed', 'usage', or 'hybrid'
     *
     * @return array Associative array:
     *   - 'success'      (bool)   Whether the mode change succeeded
     *   - 'previousMode' (string) The billing mode before the change
     *   - 'newMode'      (string) The new billing mode
     *   - 'message'      (string) Status or error message
     *
     * @see self::VALID_BILLING_MODES  Allowed billing mode values
     * @see tblSubscriptions.billingMode  The column being updated
     */
    public static function changeBillingMode(int $subscriptionID, string $newMode): array
    {
        try {
            // 🔒 Validate the new billing mode
            if (!in_array($newMode, self::VALID_BILLING_MODES, true)) {
                return [
                    'success' => false,
                    'message' => 'Invalid billing mode: ' . $newMode . '. Allowed: '
                        . implode(', ', self::VALID_BILLING_MODES),
                ];
            }

            // 📋 Retrieve current subscription details
            $subscription = Database::fetchOne(
                "SELECT subscriptionID, userID, partnerID, tierID, billingMode, status
                 FROM tblSubscriptions
                 WHERE subscriptionID = ?",
                [$subscriptionID],
                'i'
            );

            if (!$subscription) {
                return [
                    'success' => false,
                    'message' => 'Subscription not found (ID: ' . $subscriptionID . ')',
                ];
            }

            $previousMode = $subscription['billingMode'];
            $tierID       = (int) $subscription['tierID'];
            $userID       = (int) $subscription['userID'];
            $partnerID    = $subscription['partnerID'] !== null ? (int) $subscription['partnerID'] : null;

            // 💡 If already in the requested mode, no-op success
            if ($previousMode === $newMode) {
                return [
                    'success'      => true,
                    'previousMode' => $previousMode,
                    'newMode'      => $newMode,
                    'message'      => 'Subscription is already in ' . $newMode . ' billing mode',
                ];
            }

            // 🔒 If switching TO usage or hybrid mode, validate tier support
            if ($newMode !== self::MODE_FIXED) {
                $supportsUsage = false;

                // 🔍 Check partner tier first (if applicable)
                if ($partnerID !== null) {
                    $partnerTier = Database::fetchOne(
                        "SELECT supportsUsageBilling
                         FROM tblPartnerSubscriptionTiers
                         WHERE tierID = ?
                           AND partnerID = ?
                           AND isActive = 1",
                        [$tierID, $partnerID],
                        'ii'
                    );

                    if ($partnerTier) {
                        $supportsUsage = (bool) $partnerTier['supportsUsageBilling'];
                    }
                }

                // 🌐 Fall back to global tier check
                if (!$supportsUsage) {
                    $globalTier = Database::fetchOne(
                        "SELECT supportsUsageBilling
                         FROM tblSubscriptionTiers
                         WHERE tierID = ?
                           AND isActive = 1",
                        [$tierID],
                        'i'
                    );

                    if ($globalTier) {
                        $supportsUsage = (bool) $globalTier['supportsUsageBilling'];
                    }
                }

                if (!$supportsUsage) {
                    return [
                        'success' => false,
                        'message' => 'Tier (ID: ' . $tierID . ') does not support usage billing. '
                            . 'Cannot switch to ' . $newMode . ' mode.',
                    ];
                }
            }

            // 💾 Update the billing mode on the subscription
            Database::query(
                "UPDATE tblSubscriptions
                 SET billingMode = ?
                 WHERE subscriptionID = ?",
                [$newMode, $subscriptionID],
                'si'
            );

            // 📝 Log the billing mode change for audit trail
            ActivityLogger::log(
                $userID,
                'billing_mode_changed',
                'payment',
                'info',
                'Subscription #' . $subscriptionID . ' billing mode changed: '
                    . $previousMode . ' -> ' . $newMode,
                [
                    'subscriptionID' => $subscriptionID,
                    'previousMode'   => $previousMode,
                    'newMode'        => $newMode,
                    'tierID'         => $tierID,
                    'partnerID'      => $partnerID,
                ]
            );

            return [
                'success'      => true,
                'previousMode' => $previousMode,
                'newMode'      => $newMode,
                'message'      => 'Billing mode changed successfully from ' . $previousMode . ' to ' . $newMode,
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'usage_billing_error',
                'payment',
                'error',
                'Failed to change billing mode for subscription #' . $subscriptionID . ': ' . $e->getMessage(),
                [
                    'subscriptionID' => $subscriptionID,
                    'newMode'        => $newMode,
                ]
            );

            return [
                'success' => false,
                'message' => 'Failed to change billing mode: ' . $e->getMessage(),
            ];
        }
    }
}
