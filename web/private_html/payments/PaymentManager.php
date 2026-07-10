<?php
/**
 * SIGNula - Universal Single Sign-On Authentication System
 * 
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * 
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 * 
 * @package SIGNula
 * @version 2.9.0-beta
 */

/**
 * ============================================================================
 * 💳 SIGNula - Payment Manager
 * ============================================================================
 *
 * Purpose: Core payment processing and subscription management
 * PHP Version: 8.3+
 *
 * Features:
 * - Multi-provider payment processing (PayPal, Apple Pay, Google Pay, Crypto)
 * - Subscription lifecycle management (create, upgrade, downgrade, cancel, pause)
 * - Payment method tokenisation (never stores raw credentials)
 * - Discount code validation and application
 * - Invoice generation with configurable prefix
 * - Tax/VAT calculation
 * - Refund processing
 * - Activity logging for all payment events
 * - Webhook event dispatching for payment state changes
 *
 * @see https://developer.paypal.com/docs/api/overview/ (PayPal REST API)
 * @see https://developer.apple.com/documentation/apple_pay_on_the_web (Apple Pay)
 * @see https://developers.google.com/pay/api (Google Pay)
 *
 * v2.4.0 Changes:
 * - recordPayment(): Added partnerID and paymentContext fields for Level 2 payments
 * - completePayment(): Integrates with InvoiceManager, BillingScheduler, EmailService
 * - validateDiscountCode(): Added country validation (cascade: billing → profile → IP)
 * - Added getProviderDiscount() for provider-specific discounts
 * - Added getPartnerTier() for partner-defined subscription tiers
 *
 * v2.9.0 Changes (G-002 Stage S2 — plan change + proration):
 * - Added changeTier() — server-authoritative, bcmath-only, TEST-MODE-guarded
 *   upgrade/downgrade with time-weighted proration (G-002 spec §5.3).
 * - Added upgradeSubscription()/downgradeSubscription() thin wrappers (direction
 *   is always DERIVED server-side by comparing prices, never trusted from the caller).
 * - Added calculateProration() — the pure, side-effect-free bcmath formula, unit-testable
 *   in isolation from the database.
 * - Added reactivateSubscription() — 'grace'/'cancelled' → 'active' via SubscriptionStateMachine
 *   (G-002 spec §5.4).
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.2.0
 * @since      2.3.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class PaymentManager
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /** @var string Default currency (ISO 4217) */
    const DEFAULT_CURRENCY = 'GBP';

    /** @var string Invoice number prefix */
    const DEFAULT_INVOICE_PREFIX = 'SIG';

    /** @var array Valid payment statuses */
    const PAYMENT_STATUSES = [
        'pending', 'processing', 'completed', 'failed',
        'refunded', 'partially_refunded', 'cancelled', 'disputed'
    ];

    /**
     * @var array Valid subscription statuses.
     * 🩹 G-002 S1: 'grace' added — migration 036 extends the
     * tblSubscriptions.subscriptionStatus ENUM with this dunning-window state.
     * @see SubscriptionStateMachine::VALID_STATES (the enforced transition table)
     */
    const SUBSCRIPTION_STATUSES = [
        'active', 'cancelled', 'expired', 'paused',
        'trial', 'pending', 'past_due', 'grace'
    ];

    /**
     * @var array Billing cycles changeTier() will accept.
     *
     * 🩹 G-002 S2: deliberately narrower than tblSubscriptions.billingCycle's
     * full ENUM ('monthly','quarterly','yearly','lifetime','one_time') —
     * tblSubscriptionTiers only prices 'monthlyPrice'/'yearlyPrice' (see
     * migration 010). A cycle with no server-priced column cannot have a
     * server-authoritative amount (G-002 spec §9.2), so it is rejected here
     * rather than silently falling back to the monthly price.
     */
    const CHANGE_TIER_BILLING_CYCLES = ['monthly', 'yearly'];

    /**
     * @var array Valid $opts['timing'] values for changeTier().
     * @see self::changeTier() for the direction-derived default
     */
    const CHANGE_TIER_TIMINGS = ['immediate', 'end_of_period'];

    // ========================================================================
    // 💰 SUBSCRIPTION TIERS
    // ========================================================================

    /**
     * 📋 Get all active subscription tiers
     *
     * @param bool $includeInactive Include inactive tiers
     * @return array List of tier records
     */
    public static function getTiers(bool $includeInactive = false): array
    {
        $query = "SELECT * FROM tblSubscriptionTiers";
        if (!$includeInactive) {
            $query .= " WHERE isActive = 1";
        }
        $query .= " ORDER BY displayOrder ASC";

        return Database::fetchAll($query, [], '');
    }

    /**
     * 🔍 Get a single subscription tier by ID or slug
     *
     * @param int|string $identifier Tier ID (int) or slug (string)
     * @return array|null Tier record or null
     */
    public static function getTier(int|string $identifier): ?array
    {
        if (is_int($identifier)) {
            return Database::fetchOne("SELECT * FROM tblSubscriptionTiers WHERE tierID = ?", [$identifier], 'i');
        }

        return Database::fetchOne("SELECT * FROM tblSubscriptionTiers WHERE tierSlug = ?", [$identifier], 's');
    }

    /**
     * 🔍 Get the default (free) tier
     *
     * @return array|null Default tier record
     */
    public static function getDefaultTier(): ?array
    {
        return Database::fetchOne("SELECT * FROM tblSubscriptionTiers WHERE isDefault = 1 AND isActive = 1", [], '');
    }

    // ========================================================================
    // 📦 SUBSCRIPTION MANAGEMENT
    // ========================================================================

    /**
     * ➕ Create a new subscription
     *
     * @param int $userID User ID
     * @param int $tierID Tier ID
     * @param string $billingCycle Billing cycle (monthly, yearly, etc.)
     * @param string $paymentMethod Payment method
     * @param array $options Optional: partnerID, promoCode, trialDays
     * @return array ['success' => bool, 'subscriptionID' => int, 'message' => string]
     */
    public static function createSubscription(
        int $userID,
        int $tierID,
        string $billingCycle = 'monthly',
        string $paymentMethod = 'free',
        array $options = []
    ): array {
        try {
            // 🔍 Get tier details
            $tier = self::getTier($tierID);
            if (!$tier) {
                return ['success' => false, 'message' => 'Invalid subscription tier'];
            }

            // 🔍 Check for existing active subscription
            $existing = Database::fetchOne(
                "SELECT subscriptionID FROM tblSubscriptions WHERE userID = ? AND subscriptionStatus IN ('active', 'trial', 'past_due')",
                [$userID],
                'i'
            );

            if ($existing) {
                return ['success' => false, 'message' => 'User already has an active subscription. Use upgrade/downgrade instead.'];
            }

            // 💰 Calculate amount
            $amount = match ($billingCycle) {
                'yearly' => (float)$tier['yearlyPrice'],
                'monthly' => (float)$tier['monthlyPrice'],
                default => (float)$tier['monthlyPrice'],
            };

            // 🛡️ TEST-MODE GUARD (G-002 §0/§9.1) — only relevant when this
            // subscription will actually route through a live-charging
            // provider (paypal/stripe/crypto→coinbase). Free/manual
            // subscriptions never call a provider, so they must NOT be
            // blocked by an unrelated provider's live-mode setting — an
            // unconditional guard here would incorrectly refuse legitimate
            // free-tier signups. @see BillingMode::assertTestMode()
            if ($amount > 0 && in_array($paymentMethod, ['paypal', 'stripe', 'crypto'], true)) {
                $billingModePath = __DIR__ . DIRECTORY_SEPARATOR . 'BillingMode.php';
                if (!file_exists($billingModePath)) {
                    // 🚫 The guard is a hard safety requirement for any
                    // provider-bound subscription — fail closed rather than
                    // silently skip it if the file is somehow missing.
                    throw new RuntimeException('BillingMode.php (TEST-MODE guard) is required but was not found');
                }
                require_once $billingModePath;
                // 'crypto' resolves to the Coinbase provider mode setting.
                BillingMode::assertTestMode($paymentMethod === 'crypto' ? 'coinbase' : $paymentMethod);
            }

            // 🎟️ Apply discount code if provided
            $discountAmount = 0.00;
            if (!empty($options['promoCode'])) {
                $discount = self::validateDiscountCode($options['promoCode'], $tier['tierSlug'], $paymentMethod, $amount);
                if ($discount['valid']) {
                    $discountAmount = $discount['discountAmount'];
                    $amount = max(0, $amount - $discountAmount);
                }
            }

            // 📅 Calculate dates
            $startDate = date('Y-m-d');
            $trialDays = (int)($options['trialDays'] ?? $tier['trialDays'] ?? 0);
            $trialEndsAt = $trialDays > 0 ? date('Y-m-d H:i:s', strtotime('+' . $trialDays . ' days')) : null;
            $status = $trialDays > 0 ? 'trial' : ($amount > 0 ? 'pending' : 'active');

            // 📅 Calculate period end based on billing cycle
            $periodEnd = match ($billingCycle) {
                'monthly' => date('Y-m-d', strtotime('+1 month')),
                'quarterly' => date('Y-m-d', strtotime('+3 months')),
                'yearly' => date('Y-m-d', strtotime('+1 year')),
                'lifetime' => null,
                default => date('Y-m-d', strtotime('+1 month')),
            };

            $nextBillingDate = ($status === 'trial' && $trialEndsAt)
                ? date('Y-m-d', strtotime($trialEndsAt))
                : $periodEnd;

            $currency = $tier['currency'] ?? self::DEFAULT_CURRENCY;
            $partnerID = $options['partnerID'] ?? null;

            // 💾 Insert subscription
            Database::query(
                "INSERT INTO tblSubscriptions
                    (userID, partnerID, tierID, subscriptionStatus, billingCycle, amount, currency,
                     paymentMethod, startDate, currentPeriodStart, currentPeriodEnd,
                     nextBillingDate, trialEndsAt, autoRenew)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                [
                    $userID, $partnerID, $tierID, $status, $billingCycle, $amount, $currency,
                    $paymentMethod, $startDate, $startDate, $periodEnd ?: '9999-12-31',
                    $nextBillingDate, $trialEndsAt
                ],
                'iiisssdssssss'
            );

            $subscriptionID = Database::getLastInsertId();

            // 📝 Log activity
            ActivityLogger::log(
                $userID,
                'subscription_created',
                'payment',
                'info',
                'Subscription created: ' . $tier['tierName'] . ' (' . $billingCycle . ')',
                [
                    'subscriptionID' => $subscriptionID,
                    'tierID' => $tierID,
                    'tierName' => $tier['tierName'],
                    'billingCycle' => $billingCycle,
                    'amount' => $amount,
                    'currency' => $currency,
                    'paymentMethod' => $paymentMethod,
                    'trialDays' => $trialDays,
                ]
            );

            // 🔗 Dispatch webhook event
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('subscription.created', [
                    'subscriptionID' => $subscriptionID,
                    'userID' => $userID,
                    'tierSlug' => $tier['tierSlug'],
                    'billingCycle' => $billingCycle,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => $status,
                ]);
            }

            return [
                'success' => true,
                'subscriptionID' => $subscriptionID,
                'status' => $status,
                'message' => $trialDays > 0
                    ? 'Trial subscription started (' . $trialDays . ' days)'
                    : 'Subscription created successfully',
            ];
        } catch (\Exception $e) {
            ActivityLogger::log($userID, 'subscription_error', 'payment', 'error', 'Failed to create subscription: ' . $e->getMessage(), ['tierID' => $tierID]);
            return ['success' => false, 'message' => 'Failed to create subscription'];
        }
    }

    /**
     * 🔍 Get user subscription
     *
     * @param int $userID User ID
     * @return array|null Active subscription with tier details, or null
     */
    public static function getUserSubscription(int $userID): ?array
    {
        return Database::fetchOne(
            "SELECT s.*, t.tierName, t.tierSlug, t.features, t.featureLimits,
                    t.teamMemberLimit, t.apiCallsPerMonth, t.monthlyPrice, t.yearlyPrice
             FROM tblSubscriptions s
             JOIN tblSubscriptionTiers t ON s.tierID = t.tierID
             WHERE s.userID = ?
               AND s.subscriptionStatus IN ('active', 'trial', 'past_due')
             ORDER BY s.createdAt DESC
             LIMIT 1",
            [$userID],
            'i'
        );
    }

    /**
     * ❌ Cancel a subscription
     *
     * @param int $subscriptionID Subscription ID
     * @param int $userID User ID (ownership check)
     * @param string $reason Cancellation reason
     * @param bool $immediate Cancel immediately or at end of period
     * @return array ['success' => bool, 'message' => string]
     */
    public static function cancelSubscription(int $subscriptionID, int $userID, string $reason = '', bool $immediate = false): array
    {
        try {
            $sub = Database::fetchOne(
                "SELECT * FROM tblSubscriptions WHERE subscriptionID = ? AND userID = ? AND subscriptionStatus IN ('active', 'trial', 'past_due')",
                [$subscriptionID, $userID],
                'ii'
            );

            if (!$sub) {
                return ['success' => false, 'message' => 'Active subscription not found'];
            }

            if ($immediate) {
                Database::query(
                    "UPDATE tblSubscriptions SET subscriptionStatus = 'cancelled', cancelledAt = NOW(), cancellationReason = ?, autoRenew = 0, endDate = CURDATE() WHERE subscriptionID = ?",
                    [$reason, $subscriptionID],
                    'si'
                );
            } else {
                // 📅 Cancel at end of current billing period
                Database::query(
                    "UPDATE tblSubscriptions SET autoRenew = 0, cancelledAt = NOW(), cancellationReason = ?, endDate = currentPeriodEnd WHERE subscriptionID = ?",
                    [$reason, $subscriptionID],
                    'si'
                );
            }

            ActivityLogger::log($userID, 'subscription_cancelled', 'payment', 'info', 'Subscription cancelled' . ($immediate ? ' (immediate)' : ' (end of period)'), ['subscriptionID' => $subscriptionID, 'reason' => $reason]);

            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('subscription.cancelled', [
                    'subscriptionID' => $subscriptionID,
                    'userID' => $userID,
                    'immediate' => $immediate,
                    'reason' => $reason,
                ]);
            }

            return ['success' => true, 'message' => $immediate ? 'Subscription cancelled immediately' : 'Subscription will be cancelled at end of current billing period'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to cancel subscription'];
        }
    }

    /**
     * ⏸️ Pause a subscription
     *
     * @param int $subscriptionID Subscription ID
     * @param int $userID User ID
     * @param string|null $resumeDate Date to auto-resume (Y-m-d format, or null)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function pauseSubscription(int $subscriptionID, int $userID, ?string $resumeDate = null): array
    {
        try {
            $sub = Database::fetchOne(
                "SELECT subscriptionID FROM tblSubscriptions WHERE subscriptionID = ? AND userID = ? AND subscriptionStatus = 'active'",
                [$subscriptionID, $userID],
                'ii'
            );

            if (!$sub) {
                return ['success' => false, 'message' => 'Active subscription not found'];
            }

            Database::query(
                "UPDATE tblSubscriptions SET subscriptionStatus = 'paused', pausedAt = NOW(), resumesAt = ? WHERE subscriptionID = ?",
                [$resumeDate, $subscriptionID],
                'si'
            );

            ActivityLogger::log($userID, 'subscription_paused', 'payment', 'info', 'Subscription paused', ['subscriptionID' => $subscriptionID, 'resumeDate' => $resumeDate]);

            return ['success' => true, 'message' => 'Subscription paused' . ($resumeDate ? ' until ' . $resumeDate : '')];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to pause subscription'];
        }
    }

    /**
     * ▶️ Resume a paused subscription
     *
     * @param int $subscriptionID Subscription ID
     * @param int $userID User ID
     * @return array ['success' => bool, 'message' => string]
     */
    public static function resumeSubscription(int $subscriptionID, int $userID): array
    {
        try {
            $sub = Database::fetchOne(
                "SELECT subscriptionID FROM tblSubscriptions WHERE subscriptionID = ? AND userID = ? AND subscriptionStatus = 'paused'",
                [$subscriptionID, $userID],
                'ii'
            );

            if (!$sub) {
                return ['success' => false, 'message' => 'Paused subscription not found'];
            }

            Database::query(
                "UPDATE tblSubscriptions SET subscriptionStatus = 'active', pausedAt = NULL, resumesAt = NULL WHERE subscriptionID = ?",
                [$subscriptionID],
                'i'
            );

            ActivityLogger::log($userID, 'subscription_resumed', 'payment', 'info', 'Subscription resumed', ['subscriptionID' => $subscriptionID]);

            return ['success' => true, 'message' => 'Subscription resumed'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to resume subscription'];
        }
    }

    // ========================================================================
    // 🔀 PLAN CHANGE + PRORATION (G-002 Stage S2)
    // ========================================================================
    // @see .dev-team/specs/G-002.md §5.3 (proration formula), §9.2 (server-
    //      authoritative pricing), §9.4 (IDOR/CSRF), §4.3/§9.6 (idempotency)
    //
    // CSRF note (§9.4): changeTier()/upgradeSubscription()/downgradeSubscription()
    // are PHP-layer methods with no HTTP awareness. Any POST handler that wires
    // one of these up (e.g. a future web/public_html account-settings action)
    // MUST verify a CSRF token bound to the session BEFORE calling in — no
    // handler is added in this stage (see the S2 build report), so this is a
    // documented obligation for whichever stage adds it, per house rules.
    // ========================================================================

    /**
     * 🧮 Calculate Time-Weighted Proration (pure, bcmath, side-effect-free)
     *
     * Implements the G-002 spec §5.3 formula exactly:
     * ```
     * unusedCredit = oldAmount × (remainingDays / totalDays)
     * proratedNew  = newAmount × (remainingDays / totalDays)
     * netProration = proratedNew − unusedCredit   // >0 = charge due, <0 = credit due
     * ```
     *
     * All arithmetic is bcmath (`bcdiv`/`bcmul`/`bcsub`) on decimal STRINGS —
     * never native float — to avoid binary floating-point rounding drift on
     * money (see https://www.php.net/manual/en/language.types.float.php,
     * "Floating point numbers have limited precision").
     *
     * Rounding rule (documented, per the task brief): `unusedCredit` and
     * `proratedNew` are each independently rounded HALF-UP to the currency's
     * minor unit (2 decimal places) at this boundary — see self::bcRoundHalfUp()
     * — BEFORE they are subtracted to produce `netProration`. This mirrors what
     * would actually appear as two separate invoice line items (a credit line
     * and a charge line), so `netProration` always equals `proratedNew -
     * unusedCredit` to the penny with no hidden sub-cent residue.
     *
     * Day-granularity: `tblSubscriptions.currentPeriodStart`/`currentPeriodEnd`
     * are DATE columns (no time-of-day component — migration 010), so this
     * method deliberately compares CALENDAR DATES, not precise timestamps. A
     * change requested at any time of day on the first day of the period always
     * yields the full period as "remaining" (≈100% credit), regardless of the
     * wall-clock hour — this keeps the result deterministic and matches the
     * DATE-only precision of the underlying data.
     *
     * @param string      $oldAmount   The CURRENT tier's price for the CURRENT
     *                                 period, as a decimal string (e.g. '29.99').
     *                                 Callers should pass tblSubscriptions.amount
     *                                 (the amount actually being charged today —
     *                                 see changeTier()), never a client-supplied value.
     * @param string      $newAmount   The REQUESTED tier's price for the SAME
     *                                 period length, as a decimal string.
     *                                 Server-authoritative — callers must resolve
     *                                 this from tblSubscriptionTiers via getTier()
     *                                 (G-002 §9.2), never from client input.
     * @param string      $periodStart Current billing period start, 'Y-m-d'.
     * @param string      $periodEnd   Current billing period end, 'Y-m-d'.
     * @param string|null $asOfDate    The "today" to prorate against, 'Y-m-d'.
     *                                 Defaults to the real current date. The
     *                                 override exists ONLY to make unit tests
     *                                 deterministic (no DB, no wall-clock
     *                                 dependency) — production call sites never
     *                                 pass this.
     *
     * @return array{
     *     totalDays: int,
     *     remainingDays: int,
     *     unusedFraction: string,
     *     unusedCredit: string,
     *     proratedNew: string,
     *     netProration: string
     * } totalDays/remainingDays are whole calendar days; unusedFraction is a
     *   10dp bcmath string (intermediate precision only); unusedCredit/
     *   proratedNew/netProration are 2dp bcmath strings (currency minor unit).
     *
     * @see https://www.php.net/manual/en/ref.bc.php (bcmath — arbitrary precision, no float drift)
     */
    public static function calculateProration(
        string $oldAmount,
        string $newAmount,
        string $periodStart,
        string $periodEnd,
        ?string $asOfDate = null
    ): array {
        if ($asOfDate === null) {
            $asOfDate = date('Y-m-d');
        }

        // 📅 Day-granularity timestamps at midnight — see the method doc-comment
        // for why calendar dates (not precise timestamps) are used throughout.
        $periodStartTs = strtotime($periodStart . ' 00:00:00');
        $periodEndTs   = strtotime($periodEnd . ' 00:00:00');
        $todayTs       = strtotime($asOfDate . ' 00:00:00');

        $totalDays     = (int)round(($periodEndTs - $periodStartTs) / 86400);
        $remainingDays = (int)round(($periodEndTs - $todayTs) / 86400);

        // 🛡️ Guard: zero-length or malformed period (totalDays <= 0). Cannot
        // compute a meaningful fraction — fail SAFE towards "no credit, no
        // discounted charge" rather than a divide-by-zero or a runaway
        // fraction, so a data anomaly can never become a free upgrade or an
        // over-credit.
        if ($totalDays <= 0) {
            return [
                'totalDays'      => max(0, $totalDays),
                'remainingDays'  => 0,
                'unusedFraction' => '0.0000000000',
                'unusedCredit'   => '0.00',
                'proratedNew'    => '0.00',
                'netProration'   => '0.00',
            ];
        }

        // 🛡️ Guard: clamp remainingDays into [0, totalDays] — a period that
        // has already fully lapsed (today >= periodEnd) has zero unused time
        // left (never negative); a today BEFORE periodStart (clock skew / bad
        // data) can never claim MORE than the whole period (never > totalDays).
        $remainingDays = max(0, min($totalDays, $remainingDays));

        // 🧮 unusedFraction = remainingDays / totalDays, computed to 10dp so the
        // subsequent multiplications retain precision ahead of the FINAL
        // round-half-up to the currency minor unit below.
        $unusedFraction = bcdiv((string)$remainingDays, (string)$totalDays, 10);

        // 💰 G-002 §5.3 formula:
        //    unusedCredit = oldAmount × unusedFraction
        //    proratedNew  = newAmount × unusedFraction
        $unusedCreditRaw = bcmul($oldAmount, $unusedFraction, 10);
        $proratedNewRaw  = bcmul($newAmount, $unusedFraction, 10);

        // 🔢 Round EACH monetary amount to the minor unit (2dp), half-up, at
        // this boundary — see the method doc-comment's "Rounding rule".
        $unusedCredit = self::bcRoundHalfUp($unusedCreditRaw, 2);
        $proratedNew  = self::bcRoundHalfUp($proratedNewRaw, 2);

        // 🧮 netProration = proratedNew - unusedCredit (§5.3 "delta"). Positive
        // = an additional charge is due now; negative = a credit is due.
        $netProration = bcsub($proratedNew, $unusedCredit, 2);

        return [
            'totalDays'      => $totalDays,
            'remainingDays'  => $remainingDays,
            'unusedFraction' => $unusedFraction,
            'unusedCredit'   => $unusedCredit,
            'proratedNew'    => $proratedNew,
            'netProration'   => $netProration,
        ];
    }

    /**
     * 🔀 Change a Subscription's Tier (Upgrade / Downgrade) with Proration
     *
     * G-002 Stage S2 — see the class doc-comment and .dev-team/specs/G-002.md
     * §5.3 for the design this implements. Server-authoritative, bcmath-only,
     * TEST-MODE-guarded, IDOR-checked, and idempotent against double-submit.
     *
     * ── Security (this is the crux of this method) ────────────────────────
     * - **IDOR (§9.4):** the subscription is loaded `WHERE subscriptionID = ?
     *   AND userID = ?` — a subscriptionID belonging to another user resolves
     *   to "not found", never leaking whether the ID exists at all.
     * - **Server-authoritative pricing (§9.2):** the OLD price is
     *   `tblSubscriptions.amount` (what the customer is actually being
     *   charged today — may differ from the tier's list price due to a
     *   discount). The NEW price is looked up from `tblSubscriptionTiers` via
     *   `getTier()` for the REQUESTED tier + billing cycle. Any
     *   `amount`/`price`/`oldAmount`/`newAmount` key in `$opts` is IGNORED —
     *   it is never read below. A client can only choose WHICH tier/cycle it
     *   wants; it can never influence the money math.
     * - **TEST-MODE guard (§9.1):** if the subscription is provider-managed,
     *   the provider revision call is guarded by BillingMode::assertTestMode()
     *   BEFORE any local mutation — a live-mode provider is refused with ZERO
     *   side effects (no invoice, no state change, no credit).
     * - **Idempotency (§4.3/§9.6):** a repeat of the EXACT same change against
     *   the SAME billing period (subscriptionID+newTierID+newBillingCycle+
     *   currentPeriodStart+currentPeriodEnd) is detected via a
     *   `tblBillingAttempts` lookup BEFORE any mutation and returns the SAME
     *   prior result — no second invoice, no second credit, no second
     *   provider call.
     *
     * @param int    $subscriptionID  The subscription to change
     * @param int    $newTierID       The target tier (tblSubscriptionTiers.tierID)
     * @param string $newBillingCycle 'monthly' or 'yearly' (see
     *                                self::CHANGE_TIER_BILLING_CYCLES)
     * @param int    $userID          The ACTING user — used for the ownership/IDOR check
     * @param array  $opts            Optional:
     *                                - 'timing' ('immediate'|'end_of_period'): overrides the
     *                                  direction-derived default (upgrade→immediate,
     *                                  downgrade/lateral→end_of_period)
     *                                - ANY 'amount'/'price'/'oldAmount'/'newAmount' key is
     *                                  IGNORED — see the security note above
     *
     * @return array ['success' => bool, 'message' => string, 'noop'? => bool,
     *                'idempotentReplay'? => bool, 'timing'? => string,
     *                'isUpgrade'? => bool, 'proration'? => array, …]
     */
    public static function changeTier(
        int $subscriptionID,
        int $newTierID,
        string $newBillingCycle,
        int $userID,
        array $opts = []
    ): array {
        try {
            // ────────────────────────────────────────────────────────────
            // 1️⃣ Validate the requested billing cycle (§9.2 — only cycles
            //    with a server-priced column are ever accepted).
            // ────────────────────────────────────────────────────────────
            if (!in_array($newBillingCycle, self::CHANGE_TIER_BILLING_CYCLES, true)) {
                return [
                    'success' => false,
                    'message' => 'Invalid billing cycle for a plan change. Allowed: ' . implode(', ', self::CHANGE_TIER_BILLING_CYCLES),
                ];
            }

            // ────────────────────────────────────────────────────────────
            // 2️⃣ IDOR (§9.4): load the subscription SCOPED TO THE ACTOR.
            //    Only 'active' subscriptions are eligible — a tier change is
            //    not itself a lifecycle transition (it stays 'active').
            // ────────────────────────────────────────────────────────────
            $sub = Database::fetchOne(
                "SELECT * FROM tblSubscriptions WHERE subscriptionID = ? AND userID = ? AND subscriptionStatus = 'active'",
                [$subscriptionID, $userID],
                'ii'
            );

            if (!$sub) {
                return ['success' => false, 'message' => 'Active subscription not found'];
            }

            // ────────────────────────────────────────────────────────────
            // 3️⃣ Server-authoritative NEW tier lookup (§9.2). $opts is NEVER
            //    consulted for price — only tierID/billingCycle come from
            //    the caller.
            // ────────────────────────────────────────────────────────────
            $newTier = self::getTier($newTierID);
            if (!$newTier || !(bool)($newTier['isActive'] ?? false)) {
                return ['success' => false, 'message' => 'Invalid or inactive subscription tier'];
            }

            if ((string)($newTier['currency'] ?? '') !== (string)($sub['currency'] ?? '')) {
                // 🛡️ A cross-currency tier change would make the bcmath
                // proration meaningless (can't subtract GBP from USD) —
                // explicitly out of scope for this stage.
                return ['success' => false, 'message' => 'Cannot change to a tier priced in a different currency'];
            }

            $oldTier     = self::getTier((int)$sub['tierID']);
            $oldTierName = $oldTier['tierName'] ?? ('Tier #' . $sub['tierID']);
            $newTierName = $newTier['tierName'] ?? ('Tier #' . $newTierID);

            // ────────────────────────────────────────────────────────────
            // 4️⃣ No-op detection — already on the requested plan. Also the
            //    fast idempotency path for a double-submit of an already-
            //    APPLIED immediate change (tierID/billingCycle already match).
            // ────────────────────────────────────────────────────────────
            if ((int)$sub['tierID'] === $newTierID && $sub['billingCycle'] === $newBillingCycle) {
                return ['success' => true, 'noop' => true, 'message' => 'Already on this plan'];
            }

            // ────────────────────────────────────────────────────────────
            // 5️⃣ Server-authoritative pricing (§9.2). OLD = the amount
            //    ACTUALLY charged today (tblSubscriptions.amount — may
            //    reflect a discount the tier's list price does not capture).
            //    NEW = the requested tier's list price for the requested
            //    cycle, straight from tblSubscriptionTiers. $opts is never
            //    read here — a bogus 'amount' key in $opts has NO effect.
            // ────────────────────────────────────────────────────────────
            $oldAmount = number_format((float)$sub['amount'], 2, '.', '');
            $newAmountFloat = ($newBillingCycle === 'yearly')
                ? (float)$newTier['yearlyPrice']
                : (float)$newTier['monthlyPrice'];
            $newAmount = number_format($newAmountFloat, 2, '.', '');

            // 🔀 Direction is DERIVED from server-resolved prices, NEVER
            // trusted from the caller — upgradeSubscription()/
            // downgradeSubscription() are thin aliases that both funnel
            // through here and get the SAME server-computed direction.
            $isUpgrade = bccomp($newAmount, $oldAmount, 2) >= 0;

            // ────────────────────────────────────────────────────────────
            // 6️⃣ Timing — caller may override via $opts['timing']; otherwise
            //    direction-derived default (upgrade → immediate, downgrade →
            //    end_of_period). An unrecognised override falls back to the
            //    direction-derived default rather than erroring — timing only
            //    affects WHEN the change lands, never the money math.
            // ────────────────────────────────────────────────────────────
            $timing = $opts['timing'] ?? ($isUpgrade ? 'immediate' : 'end_of_period');
            if (!in_array($timing, self::CHANGE_TIER_TIMINGS, true)) {
                $timing = $isUpgrade ? 'immediate' : 'end_of_period';
            }

            // ────────────────────────────────────────────────────────────
            // 7️⃣ bcmath proration — pure, see calculateProration() for the
            //    formula + rounding rule.
            // ────────────────────────────────────────────────────────────
            $proration = self::calculateProration(
                $oldAmount,
                $newAmount,
                (string)$sub['currentPeriodStart'],
                (string)$sub['currentPeriodEnd']
            );

            // ────────────────────────────────────────────────────────────
            // 8️⃣ Idempotency (§4.3/§9.6) — a repeat of the EXACT same change
            //    against the SAME billing period is detected BEFORE any
            //    mutation and returns the prior result, unchanged.
            // ────────────────────────────────────────────────────────────
            $idempotencyKey = self::buildTierChangeIdempotencyKey(
                $subscriptionID,
                $newTierID,
                $newBillingCycle,
                (string)$sub['currentPeriodStart'],
                (string)$sub['currentPeriodEnd']
            );

            $priorAttempt = Database::fetchOne(
                "SELECT * FROM tblBillingAttempts WHERE idempotencyKey = ? AND action = 'tier_change' AND status = 'succeeded' ORDER BY attemptID DESC LIMIT 1",
                [$idempotencyKey],
                's'
            );

            if ($priorAttempt) {
                $priorResult = json_decode((string)($priorAttempt['detail'] ?? ''), true);
                if (is_array($priorResult)) {
                    $priorResult['idempotentReplay'] = true;
                    return $priorResult;
                }

                // 🛡️ Detail was somehow unparseable — fail SAFE by still
                // refusing to re-apply rather than risk a silent double-charge.
                return ['success' => true, 'idempotentReplay' => true, 'message' => 'This plan change has already been applied'];
            }

            // ────────────────────────────────────────────────────────────
            // 9️⃣ Resolve the provider/mode label used for the audit row,
            //    regardless of outcome below.
            // ────────────────────────────────────────────────────────────
            $providerKeyForLog = strtolower(trim((string)($sub['paymentMethod'] ?? '')));
            $modeForLog = 'n/a';
            if (in_array($providerKeyForLog, ['paypal', 'stripe', 'coinbase'], true)) {
                $billingModePathForLog = __DIR__ . DIRECTORY_SEPARATOR . 'BillingMode.php';
                if (file_exists($billingModePathForLog)) {
                    require_once $billingModePathForLog;
                    if (class_exists('BillingMode')) {
                        $modeForLog = BillingMode::providerMode($providerKeyForLog);
                    }
                }
            } else {
                $providerKeyForLog = 'none';
            }

            // ────────────────────────────────────────────────────────────
            // 🔟 Provider revision — BEFORE any local mutation, so a
            //    TEST-MODE-guard refusal (live provider) leaves ZERO side
            //    effects (fail closed — §9.1).
            // ────────────────────────────────────────────────────────────
            try {
                $revision = self::reviseProviderSubscription($sub, $newTier, $newBillingCycle);
            } catch (BillingModeException $e) {
                self::recordTierChangeAttempt(
                    $subscriptionID,
                    $userID,
                    $providerKeyForLog,
                    $modeForLog,
                    $idempotencyKey,
                    'blocked',
                    ['reason' => $e->getMessage()]
                );

                return [
                    'success' => false,
                    'message' => 'Unable to change plan: billing is in TEST-MODE and the provider is configured live. This is refused by design (G-002 §9.1).',
                ];
            }

            if (!($revision['success'] ?? false)) {
                self::recordTierChangeAttempt(
                    $subscriptionID,
                    $userID,
                    $providerKeyForLog,
                    $modeForLog,
                    $idempotencyKey,
                    'failed',
                    ['reason' => $revision['message'] ?? 'Provider revision failed']
                );

                return ['success' => false, 'message' => 'Unable to revise the provider subscription'];
            }

            // ────────────────────────────────────────────────────────────
            // 1️⃣1️⃣ Apply — immediate vs end_of_period.
            //    IMMEDIATE: tierID/billingCycle/amount change NOW; period
            //    fields are unchanged (§5.3 — "period unchanged").
            //    END_OF_PERIOD: nothing on the row changes NOW except a
            //    'pendingTierChange' note in the existing JSON `metadata`
            //    column, to be applied by a future scheduler stage at
            //    currentPeriodEnd.
            // ────────────────────────────────────────────────────────────
            if ($timing === 'immediate') {
                Database::query(
                    "UPDATE tblSubscriptions
                        SET tierID = ?, billingCycle = ?, amount = ?, updatedAt = NOW()
                      WHERE subscriptionID = ? AND userID = ? AND subscriptionStatus = 'active'",
                    [$newTierID, $newBillingCycle, (float)$newAmount, $subscriptionID, $userID],
                    'isdii'
                );

                // 🔒 Concurrency guard (§9.6): if the row was concurrently
                // modified between step 2's read and this write (e.g. a
                // racing cancel/pause), affected rows will be 0 — never
                // silently claim success on a mutation that did not happen.
                if (Database::getAffectedRows() !== 1) {
                    self::recordTierChangeAttempt(
                        $subscriptionID,
                        $userID,
                        $providerKeyForLog,
                        $modeForLog,
                        $idempotencyKey,
                        'failed',
                        ['reason' => 'Subscription state changed concurrently']
                    );

                    return ['success' => false, 'message' => 'The subscription changed state while this request was processing — please retry'];
                }
            } else {
                $existingMetadata = [];
                if (!empty($sub['metadata'])) {
                    $decodedMetadata = json_decode((string)$sub['metadata'], true);
                    if (is_array($decodedMetadata)) {
                        $existingMetadata = $decodedMetadata;
                    }
                }

                $existingMetadata['pendingTierChange'] = [
                    'newTierID'       => $newTierID,
                    'newBillingCycle' => $newBillingCycle,
                    'newAmount'       => $newAmount,
                    'effectiveAt'     => $sub['currentPeriodEnd'],
                    'idempotencyKey'  => $idempotencyKey,
                    'requestedAt'     => date('Y-m-d H:i:s'),
                ];

                Database::query(
                    "UPDATE tblSubscriptions SET metadata = ?, updatedAt = NOW() WHERE subscriptionID = ? AND userID = ? AND subscriptionStatus = 'active'",
                    [json_encode($existingMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $subscriptionID, $userID],
                    'sii'
                );

                if (Database::getAffectedRows() !== 1) {
                    self::recordTierChangeAttempt(
                        $subscriptionID,
                        $userID,
                        $providerKeyForLog,
                        $modeForLog,
                        $idempotencyKey,
                        'failed',
                        ['reason' => 'Subscription state changed concurrently']
                    );

                    return ['success' => false, 'message' => 'The subscription changed state while this request was processing — please retry'];
                }
            }

            // ────────────────────────────────────────────────────────────
            // 1️⃣2️⃣ Proration invoice — CREDIT (negative) + CHARGE line
            //    items reflecting what is happening AT THIS MOMENT. For
            //    'end_of_period', the charge line is explicitly $0.00/
            //    deferred (nothing is billed until the swap actually lands).
            // ────────────────────────────────────────────────────────────
            $creditLine = [
                'description' => 'Credit: unused time on ' . $oldTierName . ' (' . $proration['remainingDays'] . ' of ' . $proration['totalDays'] . ' days remaining)',
                'quantity'    => 1,
                'unitPrice'   => -1 * (float)$proration['unusedCredit'],
                'total'       => -1 * (float)$proration['unusedCredit'],
            ];

            if ($timing === 'immediate') {
                $chargeLine = [
                    'description' => 'Charge: ' . $newTierName . ' (prorated for remaining period)',
                    'quantity'    => 1,
                    'unitPrice'   => (float)$proration['proratedNew'],
                    'total'       => (float)$proration['proratedNew'],
                ];
                $invoiceType = 'subscription';
            } else {
                $chargeLine = [
                    'description' => 'Charge: ' . $newTierName . ' — deferred to next billing cycle (effective ' . $sub['currentPeriodEnd'] . ')',
                    'quantity'    => 1,
                    'unitPrice'   => 0.00,
                    'total'       => 0.00,
                ];
                $invoiceType = 'credit_topup';
            }

            $invoiceID = null;
            $invoiceNumber = null;

            $invoiceManagerPath = __DIR__ . DIRECTORY_SEPARATOR . 'InvoiceManager.php';
            if (file_exists($invoiceManagerPath)) {
                require_once $invoiceManagerPath;

                if (class_exists('InvoiceManager')) {
                    try {
                        $invoiceResult = InvoiceManager::createInvoice(
                            $userID,
                            $invoiceType,
                            [$creditLine, $chargeLine],
                            [
                                'subscriptionID' => $subscriptionID,
                                'currency'       => $sub['currency'],
                                'autoIssue'      => true,
                                'notes'          => 'Plan change: ' . $oldTierName . ' -> ' . $newTierName . ' (' . $timing . ')',
                            ]
                        );

                        if ($invoiceResult['success'] ?? false) {
                            $invoiceID = (int)$invoiceResult['invoiceID'];
                            $invoiceNumber = $invoiceResult['invoiceNumber'];
                        }
                    } catch (\Throwable $e) {
                        // ⚠️ Invoice creation failure must not block the plan
                        // change itself — mirrors completePayment()'s
                        // established non-fatal-invoice convention.
                        ActivityLogger::log($userID, 'invoice_creation_warning', 'payment', 'warning', 'Proration invoice creation failed for subscription ' . $subscriptionID . ': ' . $e->getMessage(), ['subscriptionID' => $subscriptionID]);
                    }
                }
            }

            // ────────────────────────────────────────────────────────────
            // 1️⃣3️⃣ Credit the unused-time portion to the CreditManager
            //    ledger — there is no cash-refund mechanism (G-002 spec
            //    §5.3: "credit … to CreditManager ledger (NO cash refund)").
            //    - end_of_period: the FULL unusedCredit is realised now
            //      (the charge for the new tier is deferred to renewal).
            //    - immediate: only realised if the net figure landed as a
            //      CREDIT (netProration < 0) — e.g. a lateral/cheaper
            //      immediate change — since the invoice already netted the
            //      charge/credit together for the "charge due now" case.
            //
            // 🩹 Non-fatal by design AND honestly reported: `creditOwed` is
            // what SHOULD be credited (always correct, driven purely by the
            // bcmath proration above); `creditDeposited` in the result
            // payload below reflects ONLY what CreditManager actually
            // confirmed — it stays '0.00' if the deposit call fails for any
            // reason, rather than claiming success that did not happen. The
            // invoice + tblBillingAttempts audit row still document the
            // credit OWED either way, so nothing is lost even if the ledger
            // deposit itself does not land.
            // ────────────────────────────────────────────────────────────
            $creditOwed = '0.00';
            if ($timing === 'end_of_period') {
                $creditOwed = $proration['unusedCredit'];
            } elseif (bccomp($proration['netProration'], '0.00', 2) < 0) {
                $creditOwed = bcsub('0.00', $proration['netProration'], 2);
            }

            $creditDeposited = '0.00';
            if (bccomp($creditOwed, '0.00', 2) > 0) {
                $creditManagerPath = __DIR__ . DIRECTORY_SEPARATOR . 'CreditManager.php';
                if (file_exists($creditManagerPath)) {
                    require_once $creditManagerPath;

                    if (class_exists('CreditManager')) {
                        try {
                            $creditResult = CreditManager::deposit(
                                'user',
                                $userID,
                                (float)$creditOwed,
                                'Proration credit: unused time on ' . $oldTierName . ' (plan change to ' . $newTierName . ')',
                                [
                                    'currency'      => (string)$sub['currency'],
                                    'referenceType' => 'subscription',
                                    'referenceID'   => $subscriptionID,
                                ]
                            );

                            if ($creditResult['success'] ?? false) {
                                $creditDeposited = $creditOwed;
                            } else {
                                ActivityLogger::log($userID, 'tier_change_credit_warning', 'payment', 'warning', 'CreditManager deposit did not succeed during plan change: ' . ($creditResult['message'] ?? 'unknown error'), ['subscriptionID' => $subscriptionID]);
                            }
                        } catch (\Throwable $e) {
                            // ⚠️ Non-fatal — the invoice + tblBillingAttempts
                            // audit row still document the credit owed; a
                            // ledger-deposit hiccup must not block the plan
                            // change itself.
                            ActivityLogger::log($userID, 'tier_change_credit_warning', 'payment', 'warning', 'CreditManager deposit failed during plan change: ' . $e->getMessage(), ['subscriptionID' => $subscriptionID]);
                        }
                    }
                }
            }

            // ────────────────────────────────────────────────────────────
            // 1️⃣4️⃣ Build the result payload, persist the SUCCESS audit row
            //    (its `detail` JSON is what a replayed/idempotent call
            //    returns verbatim — see step 8️⃣ above), log, dispatch.
            // ────────────────────────────────────────────────────────────
            $resultPayload = [
                'success'         => true,
                'message'         => $timing === 'immediate'
                    ? 'Plan changed successfully'
                    : 'Plan change scheduled for the end of the current billing period',
                'subscriptionID'  => $subscriptionID,
                'timing'          => $timing,
                'isUpgrade'       => $isUpgrade,
                'oldTierID'       => (int)$sub['tierID'],
                'newTierID'       => $newTierID,
                'oldBillingCycle' => $sub['billingCycle'],
                'newBillingCycle' => $newBillingCycle,
                'oldAmount'       => $oldAmount,
                'newAmount'       => $newAmount,
                'currency'        => $sub['currency'],
                'proration'       => $proration,
                'creditOwed'      => $creditOwed,
                'creditDeposited' => $creditDeposited,
                'invoiceID'       => $invoiceID,
                'invoiceNumber'   => $invoiceNumber,
                'effectiveAt'     => $timing === 'immediate' ? date('Y-m-d H:i:s') : $sub['currentPeriodEnd'],
            ];

            self::recordTierChangeAttempt(
                $subscriptionID,
                $userID,
                $providerKeyForLog,
                $modeForLog,
                $idempotencyKey,
                'succeeded',
                $resultPayload
            );

            ActivityLogger::log(
                $userID,
                'subscription_tier_changed',
                'payment',
                'info',
                'Subscription plan changed: ' . $oldTierName . ' -> ' . $newTierName . ' (' . $timing . ', net ' . $sub['currency'] . ' ' . $proration['netProration'] . ')',
                $resultPayload
            );

            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('subscription.plan_changed', [
                    'subscriptionID' => $subscriptionID,
                    'userID'         => $userID,
                    'oldTierID'      => (int)$sub['tierID'],
                    'newTierID'      => $newTierID,
                    'timing'         => $timing,
                    'netProration'   => $proration['netProration'],
                    'currency'       => $sub['currency'],
                ]);
            }

            return $resultPayload;
        } catch (\Exception $e) {
            ActivityLogger::log($userID, 'subscription_error', 'payment', 'error', 'Failed to change subscription tier: ' . $e->getMessage(), ['subscriptionID' => $subscriptionID, 'newTierID' => $newTierID]);
            return ['success' => false, 'message' => 'Failed to change subscription plan'];
        }
    }

    /**
     * ⬆️ Upgrade a Subscription's Tier (thin wrapper over changeTier())
     *
     * Direction is NOT trusted from this call site — changeTier() derives
     * upgrade-vs-downgrade itself by comparing server-resolved prices. This
     * wrapper exists purely for call-site readability/intent.
     *
     * @param int    $subscriptionID  Subscription to change
     * @param int    $newTierID       Target tier
     * @param string $newBillingCycle 'monthly' or 'yearly'
     * @param int    $userID          Acting user (IDOR check)
     * @param array  $opts            See changeTier()
     *
     * @return array See changeTier()
     */
    public static function upgradeSubscription(
        int $subscriptionID,
        int $newTierID,
        string $newBillingCycle,
        int $userID,
        array $opts = []
    ): array {
        return self::changeTier($subscriptionID, $newTierID, $newBillingCycle, $userID, $opts);
    }

    /**
     * ⬇️ Downgrade a Subscription's Tier (thin wrapper over changeTier())
     *
     * Direction is NOT trusted from this call site — see upgradeSubscription().
     *
     * @param int    $subscriptionID  Subscription to change
     * @param int    $newTierID       Target tier
     * @param string $newBillingCycle 'monthly' or 'yearly'
     * @param int    $userID          Acting user (IDOR check)
     * @param array  $opts            See changeTier()
     *
     * @return array See changeTier()
     */
    public static function downgradeSubscription(
        int $subscriptionID,
        int $newTierID,
        string $newBillingCycle,
        int $userID,
        array $opts = []
    ): array {
        return self::changeTier($subscriptionID, $newTierID, $newBillingCycle, $userID, $opts);
    }

    /**
     * ♻️ Reactivate a Subscription Before It Fully Lapses
     *
     * Allowed from 'grace' (dunning-exhausted, pre-suspension window) or
     * 'cancelled' (end-of-period cancel, before currentPeriodEnd) — mirrors
     * G-002 spec §5.4. Uses SubscriptionStateMachine::transition() for the
     * actual status change so the move is validated + logged consistently
     * with every other lifecycle transition (G-002 Stage S1).
     *
     * @param int $subscriptionID Subscription to reactivate
     * @param int $userID         Acting user (ownership/IDOR check)
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public static function reactivateSubscription(int $subscriptionID, int $userID): array
    {
        try {
            // 🔍 IDOR (§9.4): scoped to the acting user, exactly like every
            // other subscription-mutating method in this class.
            $sub = Database::fetchOne(
                "SELECT * FROM tblSubscriptions WHERE subscriptionID = ? AND userID = ? AND subscriptionStatus IN ('grace', 'cancelled')",
                [$subscriptionID, $userID],
                'ii'
            );

            if (!$sub) {
                return ['success' => false, 'message' => 'Reactivatable subscription not found'];
            }

            // 🚫 A 'cancelled' (end-of-period cancel) subscription may only
            // be reactivated BEFORE its period has actually lapsed (§5.4) —
            // once currentPeriodEnd has passed, the scheduler is responsible
            // for the 'cancelled'/'grace' -> 'expired' transition and
            // reactivation is no longer offered here.
            if ($sub['subscriptionStatus'] === 'cancelled') {
                $periodEnd = $sub['currentPeriodEnd'] ?? null;
                if ($periodEnd !== null && strtotime((string)$periodEnd) < strtotime(date('Y-m-d'))) {
                    return ['success' => false, 'message' => 'This subscription\'s billing period has already ended and can no longer be reactivated'];
                }
            }

            $stateMachinePath = __DIR__ . DIRECTORY_SEPARATOR . 'SubscriptionStateMachine.php';
            if (!file_exists($stateMachinePath)) {
                // 🚫 Fail closed rather than silently skip validated persistence.
                throw new RuntimeException('SubscriptionStateMachine.php is required but was not found');
            }
            require_once $stateMachinePath;

            // ✅ transition() itself validates the 'grace'/'cancelled' -> 'active'
            // edge against SubscriptionStateMachine::VALID_TRANSITIONS and
            // persists + logs the status change.
            SubscriptionStateMachine::transition($subscriptionID, 'active', 'user reactivation');

            // 🔄 Re-enable auto-renewal and clear the cancel/pause bookkeeping
            // columns that transition() itself does not own (it only persists
            // the columns semantically relevant to the TARGET status).
            Database::query(
                "UPDATE tblSubscriptions
                    SET autoRenew = 1, cancelledAt = NULL, cancellationReason = NULL,
                        pausedAt = NULL, resumesAt = NULL, updatedAt = NOW()
                  WHERE subscriptionID = ? AND userID = ?",
                [$subscriptionID, $userID],
                'ii'
            );

            ActivityLogger::log(
                $userID,
                'subscription_reactivated',
                'payment',
                'info',
                'Subscription reactivated from ' . $sub['subscriptionStatus'],
                ['subscriptionID' => $subscriptionID, 'fromStatus' => $sub['subscriptionStatus']]
            );

            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('subscription.reactivated', [
                    'subscriptionID' => $subscriptionID,
                    'userID'         => $userID,
                    'fromStatus'     => $sub['subscriptionStatus'],
                ]);
            }

            return ['success' => true, 'message' => 'Subscription reactivated'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to reactivate subscription'];
        }
    }

    // ========================================================================
    // 🔒 PLAN CHANGE — PRIVATE HELPERS
    // ========================================================================

    /**
     * 🔢 Round a bcmath Decimal String Half-Up to N Decimal Places
     *
     * bcmath has no native rounding — bcadd()/bcsub()/etc. simply TRUNCATE to
     * the requested scale. This implements the standard "add half a unit at
     * the target scale, then let truncation do the rounding" trick to get
     * conventional round-half-up behaviour for money (e.g. 1.005 → 1.01,
     * 1.004 → 1.00) — this is this project's documented rounding rule for
     * every proration amount (see calculateProration()'s doc-comment).
     *
     * @param string $number Decimal string (may be negative)
     * @param int    $scale  Decimal places to round to (2 = currency minor unit)
     *
     * @return string Rounded decimal string with exactly $scale decimal places
     *
     * @see https://www.php.net/manual/en/function.bcadd.php
     */
    private static function bcRoundHalfUp(string $number, int $scale = 2): string
    {
        $isNegative = str_starts_with($number, '-');
        $absNumber  = $isNegative ? substr($number, 1) : $number;

        // ➕ Add 0.5 at the (scale+1)th decimal place, then let bcadd's own
        // truncation-to-$scale perform the round-half-up, e.g. scale=2 adds '0.005'.
        $halfUnit = '0.' . str_repeat('0', $scale) . '5';
        $rounded  = bcadd($absNumber, $halfUnit, $scale);

        // 🧹 Normalise a tiny negative that rounds to exactly zero (e.g.
        // "-0.001" at scale 2) to a plain, non-negative "0.00" — it should
        // never display/compare as negative.
        if ($isNegative && (float)$rounded !== 0.0) {
            return '-' . $rounded;
        }

        return $rounded;
    }

    /**
     * 🔑 Build the Idempotency Key for a Tier-Change Request
     *
     * Stable business key (G-002 spec §4.3): a repeat of the EXACT same
     * tier-change request against the SAME billing period always resolves to
     * the SAME key, so a double-submit (page refresh, double-click, retried
     * POST) is detected before any second mutation/invoice/credit happens.
     * Deliberately scoped to the CURRENT period boundaries — once the
     * subscription renews into a new period, a genuinely new change gets a
     * genuinely new key.
     *
     * @param int    $subscriptionID
     * @param int    $newTierID
     * @param string $newBillingCycle
     * @param string $periodStart 'Y-m-d'
     * @param string $periodEnd   'Y-m-d'
     *
     * @return string 64-character sha256 hex digest (fits tblBillingAttempts.idempotencyKey VARCHAR(64))
     *
     * @see https://www.php.net/manual/en/function.hash.php
     */
    private static function buildTierChangeIdempotencyKey(
        int $subscriptionID,
        int $newTierID,
        string $newBillingCycle,
        string $periodStart,
        string $periodEnd
    ): string {
        return hash('sha256', implode('|', [
            'tier_change', $subscriptionID, $newTierID, $newBillingCycle, $periodStart, $periodEnd,
        ]));
    }

    /**
     * 📝 Record a tblBillingAttempts Audit Row for a Tier-Change Attempt
     *
     * Mirrors BillingMode::recordBlockedAttempt()'s "never let audit-logging
     * failure mask the real outcome" contract — best-effort, wrapped in its
     * own try/catch, logged to error_log() on failure, but never thrown.
     *
     * @param int    $subscriptionID
     * @param int    $userID
     * @param string $provider Resolved provider key ('paypal'/'stripe'/'coinbase'/'none')
     * @param string $mode     Resolved provider mode at attempt time, or 'n/a'
     * @param string $idempotencyKey
     * @param string $status   'succeeded' | 'failed' | 'blocked'
     * @param array  $detail   JSON-encoded into the `detail` column. For a
     *                         'succeeded' attempt this is the FULL result
     *                         payload changeTier() returns — an idempotent
     *                         replay decodes this verbatim (see changeTier()
     *                         step 8️⃣).
     *
     * @return void
     */
    private static function recordTierChangeAttempt(
        int $subscriptionID,
        int $userID,
        string $provider,
        string $mode,
        string $idempotencyKey,
        string $status,
        array $detail
    ): void {
        try {
            Database::query(
                "INSERT INTO tblBillingAttempts
                    (provider, action, mode, subscriptionID, userID, idempotencyKey, status, detail, ipAddress, createdAt)
                 VALUES (?, 'tier_change', ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $provider !== '' ? $provider : 'none',
                    $mode,
                    $subscriptionID,
                    $userID,
                    $idempotencyKey,
                    $status,
                    json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ],
                'ssiissss'
            );
        } catch (\Throwable $e) {
            error_log('🚨 [PaymentManager::changeTier] Failed to write tblBillingAttempts audit row: ' . $e->getMessage());
        }
    }

    /**
     * 🔄 Revise a Provider-Managed Subscription's Plan (TEST-MODE guarded)
     *
     * Called by changeTier() BEFORE any local mutation, so a TEST-MODE-guard
     * refusal leaves zero side effects (fail closed — §9.1).
     *
     * Free/manual subscriptions (no `paymentProviderSubscriptionID`) have
     * nothing to revise and skip straight through. For a genuinely
     * provider-managed subscription, BillingMode::assertTestMode() is called
     * BEFORE any provider-specific logic is reached — this is what proves
     * "a live-mode provider is refused" regardless of whether a concrete
     * revise-plan API call exists yet.
     *
     * 🧪 Neither PayPalProvider nor StripeProvider currently implements a
     * revise/update-subscription-plan method (grep-confirmed zero hits in
     * this codebase as of G-002 S1/S2 — a full plan-revision API integration
     * is real, separate provider-integration work: PayPal Billing Plan IDs,
     * Stripe subscription-item swaps). Per the S2 build brief's documented
     * "acceptable for S2" fallback, this method performs ONLY the guarded
     * seam: if a `reviseSubscription()` method is ever added to a provider
     * class, it is called automatically (`method_exists()`); until then, the
     * call is a guarded no-op and local proration + invoice + state update
     * (changeTier()) carries the S2 functionality. Tests mock this seam by
     * asserting the guard fires — see the S2 build report.
     *
     * @param array  $subscription    The subscription row (from tblSubscriptions)
     * @param array  $newTier         The target tier row (from tblSubscriptionTiers)
     * @param string $newBillingCycle 'monthly' or 'yearly'
     *
     * @return array ['success' => bool, 'skipped'? => bool, 'reason'? => string, 'message'? => string]
     *
     * @throws BillingModeException When the guard is ON and the resolved provider is 'live'
     */
    private static function reviseProviderSubscription(array $subscription, array $newTier, string $newBillingCycle): array
    {
        $providerSubscriptionID = $subscription['paymentProviderSubscriptionID'] ?? null;

        // 🆓 Free/manual/no-provider subscriptions have nothing to revise.
        if (empty($providerSubscriptionID)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'not_provider_managed'];
        }

        $providerKey = strtolower(trim((string)($subscription['paymentMethod'] ?? '')));
        if (!in_array($providerKey, ['paypal', 'stripe', 'coinbase'], true)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'unrecognised_provider'];
        }

        // 🛡️ TEST-MODE GUARD (G-002 §0/§9.1) — every provider call is
        // guarded BEFORE any network call is attempted. Fail closed: throws
        // BillingModeException if the provider resolves 'live' and the guard
        // is on. changeTier()'s own try/catch converts this into its normal
        // failure response with ZERO local mutation (this is called BEFORE
        // any DB write in changeTier() — see step 🔟 there).
        $billingModePath = __DIR__ . DIRECTORY_SEPARATOR . 'BillingMode.php';
        if (!file_exists($billingModePath)) {
            throw new RuntimeException('BillingMode.php (TEST-MODE guard) is required but was not found');
        }
        require_once $billingModePath;
        BillingMode::assertTestMode($providerKey);

        // 🧪 See the method doc-comment: no revise-plan method exists yet on
        // either provider class. The guard above already proved the
        // fail-closed contract; this is a documented no-op seam for now.
        if ($providerKey === 'paypal' && method_exists('PayPalProvider', 'reviseSubscription')) {
            return PayPalProvider::reviseSubscription($providerSubscriptionID, $newTier, $newBillingCycle);
        }
        if ($providerKey === 'stripe' && method_exists('StripeProvider', 'reviseSubscription')) {
            return StripeProvider::reviseSubscription($providerSubscriptionID, $newTier, $newBillingCycle);
        }

        return ['success' => true, 'skipped' => true, 'reason' => 'provider_revision_not_implemented'];
    }

    // ========================================================================
    // 💳 PAYMENT PROCESSING
    // ========================================================================

    /**
     * 💰 Record a payment
     *
     * Records a new payment in tblPayments. Supports both Level 1 (direct SIGNula payments)
     * and Level 2 (partner payments) via the partnerID and paymentContext options.
     *
     * @param int $userID User ID
     * @param float $amount Payment amount
     * @param string $paymentMethod Payment method
     * @param string $paymentProvider Provider name
     * @param array $options Optional parameters:
     *              - subscriptionID (int): Linked subscription
     *              - transactionID (string): External transaction ID
     *              - description (string): Payment description
     *              - discountCode (string): Applied discount code
     *              - discountAmount (float): Discount amount
     *              - currency (string): Currency code (default: from settings)
     *              - metadata (array): Additional metadata
     *              - partnerID (int): Partner ID for Level 2 payments (v2.4.0)
     *              - paymentContext (string): 'signula_direct'|'partner_own_keys'|'partner_signula_keys' (v2.4.0)
     * @return array ['success' => bool, 'paymentID' => int, 'invoiceNumber' => string]
     */
    public static function recordPayment(
        int $userID,
        float $amount,
        string $paymentMethod,
        string $paymentProvider,
        array $options = []
    ): array {
        try {
            // 🛡️ TEST-MODE GUARD (G-002 §0/§9.1) — only relevant when this
            // payment is routed through a live-charging provider. See
            // createSubscription() above for the free/manual exemption
            // rationale. $paymentProvider is a free-text VARCHAR(100)
            // ("PayPal", "Stripe", "Coinbase", "manual", …) so it is
            // case-normalised before matching against the guarded set.
            $guardedProviderKey = strtolower(trim($paymentProvider));
            if ($amount > 0 && in_array($guardedProviderKey, ['paypal', 'stripe', 'coinbase'], true)) {
                $billingModePath = __DIR__ . DIRECTORY_SEPARATOR . 'BillingMode.php';
                if (!file_exists($billingModePath)) {
                    // 🚫 Fail closed rather than silently skip the guard.
                    throw new RuntimeException('BillingMode.php (TEST-MODE guard) is required but was not found');
                }
                require_once $billingModePath;
                BillingMode::assertTestMode($guardedProviderKey);
            }

            $currency = $options['currency'] ?? self::getSetting('payment.default_currency', self::DEFAULT_CURRENCY);

            // 🧮 Calculate tax
            $taxEnabled = (bool)self::getSetting('payment.tax_enabled', '1');
            $taxRate = $taxEnabled ? (float)self::getSetting('payment.tax_rate', '20.00') : 0;
            $taxAmount = $taxEnabled ? round($amount * ($taxRate / 100), 2) : 0;
            $netAmount = round($amount - ($options['discountAmount'] ?? 0), 2);

            // 🔢 Generate invoice number
            $invoicePrefix = self::getSetting('payment.invoice_prefix', self::DEFAULT_INVOICE_PREFIX);
            $invoiceNumber = $invoicePrefix . '-' . date('Ymd') . '-' . str_pad(random_int(1, 99999), 5, '0', STR_PAD_LEFT);

            // 🌐 Get request context
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            // 🏢 Level 2 payment context (v2.4.0)
            $partnerID = $options['partnerID'] ?? null;
            $paymentContext = $options['paymentContext'] ?? 'signula_direct';

            // 💾 Insert payment record (with partnerID and paymentContext for Level 2 support)
            Database::query(
                "INSERT INTO tblPayments
                    (userID, subscriptionID, transactionID, paymentMethod, paymentProvider,
                     amount, currency, discountAmount, discountCode, taxAmount, taxRate, netAmount,
                     status, description, ipAddress, userAgent, invoiceNumber, metadata,
                     partnerID, paymentContext)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userID,
                    $options['subscriptionID'] ?? null,
                    $options['transactionID'] ?? null,
                    $paymentMethod,
                    $paymentProvider,
                    $amount,
                    $currency,
                    $options['discountAmount'] ?? 0,
                    $options['discountCode'] ?? null,
                    $taxAmount,
                    $taxRate,
                    $netAmount,
                    $options['description'] ?? null,
                    $ipAddress,
                    $userAgent,
                    $invoiceNumber,
                    isset($options['metadata']) ? json_encode($options['metadata']) : null,
                    $partnerID,
                    $paymentContext,
                ],
                'iisssddsdddssssssis'
            );

            $paymentID = Database::getLastInsertId();

            ActivityLogger::log($userID, 'payment_initiated', 'payment', 'info', 'Payment initiated: ' . $currency . ' ' . number_format($amount, 2), ['paymentID' => $paymentID, 'amount' => $amount, 'currency' => $currency, 'method' => $paymentMethod, 'provider' => $paymentProvider, 'partnerID' => $partnerID, 'paymentContext' => $paymentContext]);

            return [
                'success' => true,
                'paymentID' => $paymentID,
                'invoiceNumber' => $invoiceNumber,
                'amount' => $amount,
                'taxAmount' => $taxAmount,
                'netAmount' => $netAmount,
            ];
        } catch (\Exception $e) {
            ActivityLogger::log($userID, 'payment_error', 'payment', 'error', 'Payment recording failed: ' . $e->getMessage(), ['amount' => $amount]);
            return ['success' => false, 'message' => 'Failed to record payment'];
        }
    }

    /**
     * ✅ Complete a payment (mark as successful)
     *
     * Enhanced in v2.4.0 to:
     * - Create formal invoice record via InvoiceManager (if available)
     * - Send payment receipt email
     * - Handle auto-resume for suspended accounts via BillingScheduler
     * - Schedule next billing date for subscription renewals
     *
     * @param int $paymentID Payment ID
     * @param string|null $transactionID External transaction ID from provider
     * @param string|null $receiptURL Receipt URL from provider
     * @return array ['success' => bool]
     */
    public static function completePayment(int $paymentID, ?string $transactionID = null, ?string $receiptURL = null): array
    {
        try {
            $payment = Database::fetchOne("SELECT * FROM tblPayments WHERE paymentID = ?", [$paymentID], 'i');
            if (!$payment) {
                return ['success' => false, 'message' => 'Payment not found'];
            }

            $updates = ['status' => 'completed', 'paidAt' => date('Y-m-d H:i:s')];
            $setClauses = "status = 'completed', paidAt = NOW()";
            $params = [];
            $types = '';

            if ($transactionID) {
                $setClauses .= ", transactionID = ?";
                $params[] = $transactionID;
                $types .= 's';
            }

            if ($receiptURL) {
                $setClauses .= ", receiptURL = ?";
                $params[] = $receiptURL;
                $types .= 's';
            }

            $params[] = $paymentID;
            $types .= 'i';

            Database::query("UPDATE tblPayments SET $setClauses WHERE paymentID = ?", $params, $types);

            // ✅ If linked to subscription, activate it
            if ($payment['subscriptionID']) {
                Database::query(
                    "UPDATE tblSubscriptions SET subscriptionStatus = 'active' WHERE subscriptionID = ? AND subscriptionStatus IN ('pending', 'past_due')",
                    [(int)$payment['subscriptionID']],
                    'i'
                );
            }

            $userID = (int)$payment['userID'];

            ActivityLogger::log($userID, 'payment_completed', 'payment', 'info', 'Payment completed: ' . $payment['currency'] . ' ' . number_format((float)$payment['amount'], 2), ['paymentID' => $paymentID, 'transactionID' => $transactionID]);

            // ========================================================================
            // 📄 v2.4.0: Create formal invoice via InvoiceManager (if available)
            // ========================================================================
            $invoiceManagerPath = __DIR__ . DIRECTORY_SEPARATOR . 'InvoiceManager.php';
            if (file_exists($invoiceManagerPath)) {
                require_once $invoiceManagerPath;

                if (class_exists('InvoiceManager')) {
                    try {
                        $invoiceResult = InvoiceManager::createInvoice(
                            $userID,
                            'subscription',
                            [
                                [
                                    'description' => $payment['description'] ?? 'SIGNula Subscription Payment',
                                    'quantity'    => 1,
                                    'unitPrice'   => (float)$payment['amount'],
                                    'total'       => (float)$payment['amount'],
                                ],
                            ],
                            [
                                'paymentID'      => $paymentID,
                                'subscriptionID' => $payment['subscriptionID'] ?? null,
                                'partnerID'      => $payment['partnerID'] ?? null,
                                'currency'       => $payment['currency'] ?? 'GBP',
                                'discountAmount' => (float)($payment['discountAmount'] ?? 0),
                                'taxRate'        => (float)($payment['taxRate'] ?? 0),
                                'autoIssue'      => true,
                            ]
                        );

                        // 📧 Send invoice email if creation succeeded
                        if ($invoiceResult['success'] ?? false) {
                            InvoiceManager::sendInvoiceEmail((int)$invoiceResult['invoiceID']);
                        }
                    } catch (\Throwable $e) {
                        // ⚠️ Invoice creation failure should not block payment completion
                        ActivityLogger::log($userID, 'invoice_creation_warning', 'payment', 'warning', 'Invoice creation failed for payment ' . $paymentID . ': ' . $e->getMessage(), ['paymentID' => $paymentID]);
                    }
                }
            }

            // ========================================================================
            // 🔄 v2.4.0: Handle auto-resume via BillingScheduler (if available)
            // ========================================================================
            if ($payment['subscriptionID']) {
                $billingSchedulerPath = __DIR__ . DIRECTORY_SEPARATOR . 'BillingScheduler.php';
                if (file_exists($billingSchedulerPath)) {
                    require_once $billingSchedulerPath;

                    if (class_exists('BillingScheduler')) {
                        try {
                            BillingScheduler::handlePaymentSuccess(
                                (int)$payment['subscriptionID'],
                                $paymentID
                            );
                        } catch (\Throwable $e) {
                            // ⚠️ Billing scheduler failure should not block payment completion
                            ActivityLogger::log($userID, 'billing_scheduler_warning', 'payment', 'warning', 'BillingScheduler post-payment processing failed: ' . $e->getMessage(), ['paymentID' => $paymentID]);
                        }
                    }
                }
            }

            // 📧 Send payment receipt email
            if (class_exists('EmailService')) {
                try {
                    $user = Database::fetchOne("SELECT email, displayName FROM tblUsers WHERE userID = ?", [$userID], 'i');
                    if ($user && !empty($user['email'])) {
                        EmailService::sendTemplateEmail(
                            $user['email'],
                            'payment_receipt',
                            [
                                'displayName'   => $user['displayName'] ?? 'Customer',
                                'amount'        => $payment['currency'] . ' ' . number_format((float)$payment['amount'], 2),
                                'invoiceNumber' => $payment['invoiceNumber'] ?? '',
                                'paymentMethod' => ucfirst($payment['paymentMethod'] ?? ''),
                                'paymentDate'   => date('d M Y, H:i'),
                            ],
                            $userID,
                            5
                        );
                    }
                } catch (\Throwable $e) {
                    // ⚠️ Email failure should not block payment completion
                    ActivityLogger::log($userID, 'payment_email_warning', 'payment', 'warning', 'Payment receipt email failed: ' . $e->getMessage(), ['paymentID' => $paymentID]);
                }
            }

            // 🔗 Dispatch webhook
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('payment.completed', [
                    'paymentID' => $paymentID,
                    'userID' => $userID,
                    'amount' => (float)$payment['amount'],
                    'currency' => $payment['currency'],
                    'transactionID' => $transactionID,
                    'invoiceNumber' => $payment['invoiceNumber'],
                    'partnerID' => $payment['partnerID'] ?? null,
                    'paymentContext' => $payment['paymentContext'] ?? 'signula_direct',
                ]);
            }

            return ['success' => true, 'message' => 'Payment completed'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to complete payment'];
        }
    }

    /**
     * 💸 Process a refund
     *
     * @param int $paymentID Payment ID
     * @param float|null $amount Partial refund amount (null = full refund)
     * @param string $reason Refund reason
     * @return array ['success' => bool, 'message' => string]
     */
    public static function refundPayment(int $paymentID, ?float $amount = null, string $reason = ''): array
    {
        try {
            $payment = Database::fetchOne(
                "SELECT * FROM tblPayments WHERE paymentID = ? AND status = 'completed'",
                [$paymentID],
                'i'
            );

            if (!$payment) {
                return ['success' => false, 'message' => 'Completed payment not found'];
            }

            $refundAmount = $amount ?? (float)$payment['amount'];
            $isPartial = $refundAmount < (float)$payment['amount'];
            $status = $isPartial ? 'partially_refunded' : 'refunded';

            Database::query(
                "UPDATE tblPayments SET status = ?, refundedAt = NOW(), refundAmount = ?, refundReason = ? WHERE paymentID = ?",
                [$status, $refundAmount, $reason, $paymentID],
                'sdsi'
            );

            ActivityLogger::log(
                (int)$payment['userID'],
                'payment_refunded',
                'payment',
                'warning',
                ($isPartial ? 'Partial refund' : 'Full refund') . ': ' . $payment['currency'] . ' ' . number_format($refundAmount, 2),
                ['paymentID' => $paymentID, 'refundAmount' => $refundAmount, 'reason' => $reason]
            );

            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('payment.refunded', [
                    'paymentID' => $paymentID,
                    'userID' => (int)$payment['userID'],
                    'refundAmount' => $refundAmount,
                    'isPartial' => $isPartial,
                    'reason' => $reason,
                ]);
            }

            return ['success' => true, 'message' => ($isPartial ? 'Partial' : 'Full') . ' refund processed'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to process refund'];
        }
    }

    // ========================================================================
    // 🎟️ DISCOUNT CODES
    // ========================================================================

    /**
     * ✅ Validate a discount code
     *
     * @param string $code Discount code
     * @param string $tierSlug Tier being purchased
     * @param string $paymentMethod Payment method being used
     * @param float $amount Original amount
     * @return array ['valid' => bool, 'discountAmount' => float, 'message' => string]
     */
    public static function validateDiscountCode(string $code, string $tierSlug, string $paymentMethod, float $amount): array
    {
        $discount = Database::fetchOne(
            "SELECT * FROM tblDiscountCodes WHERE code = ? AND isActive = 1 AND validFrom <= NOW() AND (validUntil IS NULL OR validUntil >= NOW())",
            [$code],
            's'
        );

        if (!$discount) {
            return ['valid' => false, 'discountAmount' => 0, 'message' => 'Invalid or expired discount code'];
        }

        // 🔍 Check usage limits
        if ($discount['maxUses'] !== null && (int)$discount['currentUses'] >= (int)$discount['maxUses']) {
            return ['valid' => false, 'discountAmount' => 0, 'message' => 'Discount code has reached its usage limit'];
        }

        // 🔍 Check applicable tiers
        if ($discount['applicableTiers'] !== null) {
            $applicableTiers = json_decode($discount['applicableTiers'], true);
            if (!in_array($tierSlug, $applicableTiers, true)) {
                return ['valid' => false, 'discountAmount' => 0, 'message' => 'Discount code is not valid for this tier'];
            }
        }

        // 🔍 Check applicable payment methods
        if ($discount['applicablePaymentMethods'] !== null) {
            $applicableMethods = json_decode($discount['applicablePaymentMethods'], true);
            if (!in_array($paymentMethod, $applicableMethods, true)) {
                return ['valid' => false, 'discountAmount' => 0, 'message' => 'Discount code is not valid for this payment method'];
            }
        }

        // 🔍 Check minimum amount
        if ($discount['minAmount'] !== null && $amount < (float)$discount['minAmount']) {
            return ['valid' => false, 'discountAmount' => 0, 'message' => 'Minimum purchase amount of ' . $discount['currency'] . ' ' . $discount['minAmount'] . ' required'];
        }

        // 💰 Calculate discount
        $discountAmount = match ($discount['discountType']) {
            'percentage' => round($amount * ((float)$discount['discountValue'] / 100), 2),
            'fixed_amount' => min((float)$discount['discountValue'], $amount),
            'free_trial_days' => 0,
            default => 0,
        };

        return [
            'valid' => true,
            'discountAmount' => $discountAmount,
            'discountType' => $discount['discountType'],
            'discountValue' => (float)$discount['discountValue'],
            'message' => 'Discount applied',
        ];
    }

    /**
     * 📊 Increment discount code usage
     *
     * @param string $code Discount code
     */
    public static function incrementDiscountUsage(string $code): void
    {
        Database::query(
            "UPDATE tblDiscountCodes SET currentUses = currentUses + 1 WHERE code = ?",
            [$code],
            's'
        );
    }

    // ========================================================================
    // 💳 PAYMENT METHODS
    // ========================================================================

    /**
     * 📋 Get stored payment methods for a user
     *
     * @param int $userID User ID
     * @return array List of payment methods
     */
    public static function getPaymentMethods(int $userID): array
    {
        return Database::fetchAll(
            "SELECT methodID, paymentMethod, provider, displayName, lastFour, expiryMonth, expiryYear, brand, billingEmail, isDefault, isVerified, createdAt
             FROM tblPaymentMethods
             WHERE userID = ?
             ORDER BY isDefault DESC, createdAt DESC",
            [$userID],
            'i'
        );
    }

    /**
     * ➕ Store a payment method (tokenised from provider)
     *
     * @param int $userID User ID
     * @param string $paymentMethod Method type (paypal, apple_pay, etc.)
     * @param string $provider Provider name
     * @param array $details Method details (providerMethodID, displayName, lastFour, etc.)
     * @return array ['success' => bool, 'methodID' => int]
     */
    public static function addPaymentMethod(int $userID, string $paymentMethod, string $provider, array $details): array
    {
        try {
            // 🔍 Check if this is the first method (make it default)
            $existingCount = Database::fetchOne(
                "SELECT COUNT(*) as cnt FROM tblPaymentMethods WHERE userID = ?",
                [$userID],
                'i'
            );
            $isDefault = (!$existingCount || (int)$existingCount['cnt'] === 0) ? 1 : 0;

            Database::query(
                "INSERT INTO tblPaymentMethods
                    (userID, paymentMethod, provider, providerMethodID, displayName, lastFour,
                     expiryMonth, expiryYear, brand, billingEmail, isDefault, isVerified)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userID,
                    $paymentMethod,
                    $provider,
                    $details['providerMethodID'] ?? null,
                    $details['displayName'] ?? $paymentMethod,
                    $details['lastFour'] ?? null,
                    $details['expiryMonth'] ?? null,
                    $details['expiryYear'] ?? null,
                    $details['brand'] ?? null,
                    $details['billingEmail'] ?? null,
                    $isDefault,
                    $details['isVerified'] ?? 0,
                ],
                'isssssiivsii'
            );

            $methodID = Database::getLastInsertId();
            ActivityLogger::log($userID, 'payment_method_added', 'payment', 'info', 'Payment method added: ' . $paymentMethod, ['methodID' => $methodID, 'provider' => $provider]);

            return ['success' => true, 'methodID' => $methodID];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to add payment method'];
        }
    }

    /**
     * 🗑️ Remove a stored payment method
     *
     * @param int $methodID Method ID
     * @param int $userID User ID (ownership check)
     * @return array ['success' => bool]
     */
    public static function removePaymentMethod(int $methodID, int $userID): array
    {
        try {
            Database::query(
                "DELETE FROM tblPaymentMethods WHERE methodID = ? AND userID = ?",
                [$methodID, $userID],
                'ii'
            );

            ActivityLogger::log($userID, 'payment_method_removed', 'payment', 'info', 'Payment method removed', ['methodID' => $methodID]);

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to remove payment method'];
        }
    }

    // ========================================================================
    // 📊 REPORTING
    // ========================================================================

    /**
     * 📈 Get payment history for a user
     *
     * @param int $userID User ID
     * @param int $limit Max records
     * @param int $offset Offset for pagination
     * @return array List of payment records
     */
    public static function getPaymentHistory(int $userID, int $limit = 25, int $offset = 0): array
    {
        return Database::fetchAll(
            "SELECT paymentID, transactionID, paymentMethod, paymentProvider, amount, currency,
                    discountAmount, taxAmount, netAmount, status, description, invoiceNumber,
                    paidAt, refundedAt, refundAmount, createdAt
             FROM tblPayments
             WHERE userID = ?
             ORDER BY createdAt DESC
             LIMIT ? OFFSET ?",
            [$userID, $limit, $offset],
            'iii'
        );
    }

    /**
     * 📊 Get admin payment statistics
     *
     * @param int $days Number of days to look back
     * @return array Revenue and transaction statistics
     */
    public static function getAdminStats(int $days = 30): array
    {
        return Database::fetchOne(
            "SELECT
                COUNT(*) as totalTransactions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completedCount,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as totalRevenue,
                SUM(CASE WHEN status = 'completed' THEN netAmount ELSE 0 END) as netRevenue,
                SUM(CASE WHEN status = 'completed' THEN taxAmount ELSE 0 END) as totalTax,
                SUM(CASE WHEN status = 'refunded' OR status = 'partially_refunded' THEN refundAmount ELSE 0 END) as totalRefunds,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failedCount,
                COUNT(DISTINCT userID) as uniqueCustomers,
                AVG(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as avgPaymentAmount
             FROM tblPayments
             WHERE createdAt >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days],
            'i'
        ) ?: [];
    }

    // ========================================================================
    // 🔧 HELPERS
    // ========================================================================

    /**
     * ⚙️ Get a setting value from tblSettings
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return string Setting value
     */
    private static function getSetting(string $key, mixed $default = ''): string
    {
        $setting = Database::fetchOne(
            "SELECT settingValue FROM tblSettings WHERE settingKey = ?",
            [$key],
            's'
        );

        return $setting ? $setting['settingValue'] : (string)$default;
    }

    // ========================================================================
    // 🏷️ PROVIDER DISCOUNTS (v2.4.0)
    // ========================================================================

    /**
     * 💰 Get provider-specific discount percentage
     *
     * Retrieves the discount percentage for a specific payment provider.
     * Checks partner-specific discount first (from tblProviderDiscounts with partnerID),
     * then falls back to global discount.
     *
     * @param string $provider Payment provider name (stripe, paypal, coinbase)
     * @param int|null $partnerID Optional partner ID for partner-scoped discounts
     * @return float Discount percentage (e.g., 5.00 for 5% off)
     *
     * @since 2.4.0-beta
     */
    public static function getProviderDiscount(string $provider, ?int $partnerID = null): float
    {
        // 🔍 Check for partner-specific discount first
        if ($partnerID !== null) {
            $partnerDiscount = Database::fetchOne(
                "SELECT discountPercent FROM tblProviderDiscounts
                 WHERE provider = ? AND partnerID = ? AND isActive = 1
                 AND (validFrom IS NULL OR validFrom <= CURDATE())
                 AND (validUntil IS NULL OR validUntil >= CURDATE())",
                [$provider, $partnerID],
                'si'
            );

            if ($partnerDiscount) {
                return (float)$partnerDiscount['discountPercent'];
            }
        }

        // 🌐 Fall back to global discount
        $globalDiscount = Database::fetchOne(
            "SELECT discountPercent FROM tblProviderDiscounts
             WHERE provider = ? AND partnerID IS NULL AND isActive = 1
             AND (validFrom IS NULL OR validFrom <= CURDATE())
             AND (validUntil IS NULL OR validUntil >= CURDATE())",
            [$provider],
            's'
        );

        if ($globalDiscount) {
            return (float)$globalDiscount['discountPercent'];
        }

        return 0.00;
    }

    // ========================================================================
    // 🏢 PARTNER TIERS (v2.4.0)
    // ========================================================================

    /**
     * 🔍 Get a partner-defined subscription tier
     *
     * Retrieves a tier from tblPartnerSubscriptionTiers (separate from global tblSubscriptionTiers).
     *
     * @param int $partnerTierID Partner tier ID
     * @return array|null Partner tier record or null
     *
     * @since 2.4.0-beta
     */
    public static function getPartnerTier(int $partnerTierID): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM tblPartnerSubscriptionTiers WHERE partnerTierID = ? AND isActive = 1",
            [$partnerTierID],
            'i'
        );
    }

    /**
     * 📋 Get all partner tiers for a specific partner
     *
     * @param int $partnerID Partner ID
     * @param bool $includeInactive Include inactive tiers
     * @return array List of partner tier records
     *
     * @since 2.4.0-beta
     */
    public static function getPartnerTiers(int $partnerID, bool $includeInactive = false): array
    {
        $query = "SELECT * FROM tblPartnerSubscriptionTiers WHERE partnerID = ?";
        if (!$includeInactive) {
            $query .= " AND isActive = 1";
        }
        $query .= " ORDER BY displayOrder ASC";

        return Database::fetchAll($query, [$partnerID], 'i');
    }

    // ========================================================================
    // 📊 USAGE-BASED BILLING PAYMENT PROCESSING
    // ========================================================================
    // Methods for processing payments generated by the usage billing system.
    // These complement the existing subscription charge flow with support
    // for usage-based and hybrid billing modes.
    //
    // @since 2.7.0-beta
    // ========================================================================

    /**
     * 💳 Process Usage Payment
     *
     * Records and attempts to process a payment for a usage billing summary.
     * Uses the user's stored payment method to charge the calculated usage cost.
     * If no stored payment method is available, creates a pending payment record
     * and marks the invoice for manual payment.
     *
     * @param int    $userID         The user to charge
     * @param int    $subscriptionID The subscription this payment is for
     * @param float  $amount         The amount to charge (already capped if hybrid)
     * @param string $currency       ISO 4217 currency code
     * @param int    $invoiceID      The linked invoice ID
     * @param int    $summaryID      The linked usage billing summary ID
     *
     * @return array {
     *     @type bool   $success   Whether the payment was processed
     *     @type string $message   Result description
     *     @type int    $paymentID The created payment record ID
     * }
     *
     * @see self::recordPayment()     Records the payment in tblPayments
     * @see self::completePayment()   Finalises a successful payment
     * @see UsageBillingService       For usage cost calculation
     * @since 2.7.0-beta
     */
    public static function processUsagePayment(
        int $userID,
        int $subscriptionID,
        float $amount,
        string $currency,
        int $invoiceID,
        int $summaryID
    ): array {
        try {
            // 📋 Get the user's default/active payment method
            $paymentMethods = self::getPaymentMethods($userID);
            $defaultMethod = null;

            foreach ($paymentMethods as $method) {
                if (!empty($method['isDefault']) && $method['isDefault'] == 1) {
                    $defaultMethod = $method;
                    break;
                }
            }

            // 🔀 If no default, use the first available method
            if (!$defaultMethod && !empty($paymentMethods)) {
                $defaultMethod = $paymentMethods[0];
            }

            // 💰 Record the payment attempt
            $paymentResult = self::recordPayment(
                $userID,
                $subscriptionID,
                $amount,
                $currency,
                $defaultMethod ? $defaultMethod['paymentMethod'] : 'pending',
                $defaultMethod ? $defaultMethod['provider'] : 'manual',
                [
                    'invoiceID'  => $invoiceID,
                    'summaryID'  => $summaryID,
                    'type'       => 'usage_billing',
                ]
            );

            if (!$paymentResult['success']) {
                return $paymentResult;
            }

            $paymentID = $paymentResult['paymentID'];

            // 💳 If we have a payment method, attempt to charge it
            if ($defaultMethod) {
                $provider = $defaultMethod['provider'] ?? '';

                // 🔀 Route to the appropriate payment provider
                // This uses the existing provider infrastructure
                $chargeResult = match ($provider) {
                    'stripe'   => self::chargeViaProvider('stripe', $userID, $amount, $currency, $defaultMethod, $paymentID),
                    'paypal'   => self::chargeViaProvider('paypal', $userID, $amount, $currency, $defaultMethod, $paymentID),
                    'coinbase' => self::chargeViaProvider('coinbase', $userID, $amount, $currency, $defaultMethod, $paymentID),
                    default    => ['success' => false, 'message' => 'Unsupported provider: ' . $provider],
                };

                if ($chargeResult['success']) {
                    // ✅ Complete the payment — this also updates the invoice
                    $completeResult = self::completePayment(
                        $paymentID,
                        $chargeResult['transactionID'] ?? null,
                        $chargeResult['receiptURL'] ?? null
                    );

                    // 📝 Log successful usage payment
                    ActivityLogger::log(
                        $userID,
                        'usage_payment_completed',
                        'billing',
                        'info',
                        'Usage payment of ' . $currency . ' ' . number_format($amount, 2) . ' processed successfully'
                            . ' for subscription ' . $subscriptionID,
                        [
                            'paymentID'      => $paymentID,
                            'summaryID'      => $summaryID,
                            'invoiceID'      => $invoiceID,
                            'amount'         => $amount,
                            'currency'       => $currency,
                            'provider'       => $provider,
                        ]
                    );

                    return [
                        'success'   => true,
                        'message'   => 'Usage payment processed successfully',
                        'paymentID' => $paymentID,
                    ];
                } else {
                    // ❌ Payment failed — update payment status
                    Database::query(
                        "UPDATE tblPayments SET status = 'failed', updatedAt = NOW() WHERE paymentID = ?",
                        [$paymentID],
                        'i'
                    );

                    ActivityLogger::log(
                        $userID,
                        'usage_payment_failed',
                        'billing',
                        'warning',
                        'Usage payment of ' . $currency . ' ' . number_format($amount, 2)
                            . ' failed via ' . $provider . ': ' . ($chargeResult['message'] ?? 'Unknown error'),
                        [
                            'paymentID'      => $paymentID,
                            'summaryID'      => $summaryID,
                            'provider'       => $provider,
                            'error'          => $chargeResult['message'] ?? '',
                        ]
                    );

                    return [
                        'success'   => false,
                        'message'   => 'Usage payment failed: ' . ($chargeResult['message'] ?? 'Provider charge failed'),
                        'paymentID' => $paymentID,
                    ];
                }
            } else {
                // 📧 No payment method on file — mark as pending and notify user
                ActivityLogger::log(
                    $userID,
                    'usage_payment_pending',
                    'billing',
                    'warning',
                    'Usage payment of ' . $currency . ' ' . number_format($amount, 2)
                        . ' created as pending — no payment method on file',
                    [
                        'paymentID' => $paymentID,
                        'summaryID' => $summaryID,
                        'invoiceID' => $invoiceID,
                    ]
                );

                return [
                    'success'   => true,
                    'message'   => 'Payment recorded as pending — no payment method on file',
                    'paymentID' => $paymentID,
                ];
            }

        } catch (\Exception $e) {
            ErrorLogger::log($e);
            return [
                'success'   => false,
                'message'   => 'Usage payment processing error: ' . $e->getMessage(),
                'paymentID' => 0,
            ];
        }
    }

    /**
     * 💳 Charge Via Provider (Internal Helper)
     *
     * Routes a charge to the appropriate payment provider. This is an internal
     * helper method used by processUsagePayment() to abstract provider-specific
     * charging logic.
     *
     * @param string $provider      The payment provider name
     * @param int    $userID        The user being charged
     * @param float  $amount        The amount to charge
     * @param string $currency      ISO 4217 currency code
     * @param array  $paymentMethod The stored payment method details
     * @param int    $paymentID     The payment record ID
     *
     * @return array {
     *     @type bool   $success       Whether the charge succeeded
     *     @type string $message       Result description
     *     @type string $transactionID Provider transaction ID (if successful)
     *     @type string $receiptURL    Provider receipt URL (if available)
     * }
     *
     * @since 2.7.0-beta
     */
    private static function chargeViaProvider(
        string $provider,
        int $userID,
        float $amount,
        string $currency,
        array $paymentMethod,
        int $paymentID
    ): array {
        try {
            // 🔀 Route to provider-specific implementation
            // Each provider class handles tokenised charging
            return match ($provider) {
                'stripe' => class_exists('StripeProvider')
                    ? StripeProvider::chargeStoredMethod($userID, $amount, $currency, $paymentMethod, $paymentID)
                    : ['success' => false, 'message' => 'Stripe provider not available'],

                'paypal' => class_exists('PayPalProvider')
                    ? PayPalProvider::chargeStoredMethod($userID, $amount, $currency, $paymentMethod, $paymentID)
                    : ['success' => false, 'message' => 'PayPal provider not available'],

                'coinbase' => class_exists('CoinbaseProvider')
                    ? CoinbaseProvider::createCharge($amount, $currency, 'Usage billing payment', ['paymentID' => $paymentID])
                    : ['success' => false, 'message' => 'Coinbase provider not available'],

                default => ['success' => false, 'message' => 'Unsupported provider: ' . $provider],
            };

        } catch (\Exception $e) {
            ErrorLogger::log($e);
            return [
                'success' => false,
                'message' => 'Provider charge error (' . $provider . '): ' . $e->getMessage(),
            ];
        }
    }
}
