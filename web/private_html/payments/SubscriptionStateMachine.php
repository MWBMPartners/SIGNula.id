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
 * @version 2.9.0-beta
 */

/**
 * ============================================================================
 * 🔄 SIGNula - Subscription Lifecycle State Machine (G-002 Stage S1)
 * ============================================================================
 *
 * Purpose: A documented, ENFORCED state machine over
 *          `tblSubscriptions.subscriptionStatus`, giving later G-002 stages
 *          (S2 proration, S3 webhook mapping, S4 dunning ladder) a safe,
 *          tested state-persistence layer to build on.
 * PHP Version: 8.3+ (developed/tested on 8.4)
 *
 * ── Scope (G-002 spec §5 / this build's S1 slice) ─────────────────────────
 * This class is PURE STATE + PERSISTENCE. It does NOT:
 *   - drive real charges, refunds, or provider API calls (that is
 *     BillingMode/PayPalProvider/StripeProvider's job — see BillingMode.php);
 *   - implement proration/upgrade/downgrade (G-002 Stage S2);
 *   - map inbound webhook events onto transitions (G-002 Stage S3);
 *   - run the dunning retry ladder (G-002 Stage S4).
 * It ONLY validates and persists a legal subscriptionStatus change, so those
 * later stages have one, correct, idempotent place to call into.
 *
 * ── States (tblSubscriptions.subscriptionStatus ENUM, migration 010 + 036) ─
 *   pending   — created, awaiting first successful payment / provider activation
 *   trial     — in free trial (trialEndsAt in future)
 *   active    — paid & current
 *   past_due  — a renewal payment failed; dunning retries in progress
 *   grace     — retries exhausted; grace window before suspension (NEW — migration 036)
 *   paused    — user-paused, resumable
 *   cancelled — cancelled (immediate); endDate set (existing spelling — do NOT rename)
 *   expired   — period ended with no renewal / grace lapsed (TERMINAL)
 *
 * ── Transition table (G-002 spec §4.2, derived 1:1 from the spec's arrows) ─
 * See self::VALID_TRANSITIONS for the authoritative adjacency map. Every edge
 * below is named after the spec's driver so future stages know which layer
 * (provider webhook / scheduler tick / user action) is expected to call it:
 *
 *   pending   -> active     (provider activation webhook)                    [S3]
 *   pending   -> trial      (trialEndsAt set at creation)
 *   pending   -> cancelled  (user abandons before first payment — sensible
 *                            extension beyond the spec's explicit table; a
 *                            pending subscription must be cancellable)
 *   trial     -> active     (expire_trial tick: paid plan charge OK, or free plan) [S4]
 *   trial     -> past_due   (expire_trial tick: first charge failed)          [S4]
 *   trial     -> paused     (user pauseSubscription())
 *   trial     -> cancelled  (user cancelSubscription(immediate))
 *   active    -> past_due   (renewal webhook: payment failed)                 [S3]
 *   active    -> paused     (user pauseSubscription())
 *   active    -> cancelled  (user cancelSubscription(immediate))
 *   active    -> expired    (autoRenew=0 end-of-period lapse — scheduler tick) [S4/S6]
 *   past_due  -> active     (dunning retry webhook: payment OK — auto-restore) [S3]
 *   past_due  -> grace      (retry ladder exhausted — scheduler tick)         [S4]
 *   past_due  -> cancelled  (user cancelSubscription(immediate))
 *   grace     -> active     (payment OK / reactivateSubscription() before grace end) [S4]
 *   grace     -> expired    (grace window lapses — scheduler tick, + provider cancel) [S4]
 *   grace     -> cancelled  (user cancelSubscription(immediate))
 *   paused    -> active     (user resumeSubscription())
 *   paused    -> cancelled  (user cancelSubscription(immediate))
 *   cancelled -> active     (reactivateSubscription() before currentPeriodEnd — spec §5.4)
 *   expired   -> (none)     (TERMINAL — no outgoing transitions)
 *
 * Deliberately NOT modelled here: the spec's "[any non-terminal] —
 * upgrade/downgrade→ [active]" edge. Upgrade/downgrade (changeTier()) is
 * G-002 Stage S2 — encoding it now, before that method exists, would be
 * speculative and untestable. S2 should extend VALID_TRANSITIONS when it
 * lands, not work around this class.
 *
 * ── Idempotency ────────────────────────────────────────────────────────────
 * transition() re-applying the CURRENT status is always a no-op success (no
 * re-validation, no duplicate persistence, no duplicate ActivityLogger entry)
 * — this is what lets a replayed webhook or a double cron tick call
 * transition() safely without special-casing "did this already happen?" at
 * every call site (G-002 spec §4.3).
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/enum.html (MySQL ENUM)
 * @see .dev-team/specs/G-002.md §4/§5 (the authoritative lifecycle design this implements)
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.0.0
 * @since      2.9.0-beta (G-002 Stage S1)
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

// 📚 Require the dedicated exception type this class throws. web/private_html/payments/
// is NOT covered by config.php's spl_autoload_register() directory list, so — mirroring
// every other payments/ class (PayPalProvider.php requiring PaymentManager.php, etc.) —
// dependencies here are always explicitly required with an existence check.
$transitionExceptionPath = __DIR__ . DIRECTORY_SEPARATOR . 'InvalidSubscriptionTransitionException.php';
if (file_exists($transitionExceptionPath)) {
    require_once $transitionExceptionPath;
} else {
    error_log('🚨 [SubscriptionStateMachine] InvalidSubscriptionTransitionException.php not found at: ' . $transitionExceptionPath);
    throw new RuntimeException('Required dependency InvalidSubscriptionTransitionException.php is not available');
}

/**
 * 🔄 SubscriptionStateMachine
 *
 * Static utility class — no instances, following the project-wide convention.
 */
class SubscriptionStateMachine
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /**
     * @var array Every valid tblSubscriptions.subscriptionStatus value,
     * including 'grace' (added by migration 036). Used both to validate the
     * $to argument and to detect an unrecognised current status defensively.
     */
    public const VALID_STATES = [
        'pending', 'trial', 'active', 'past_due', 'grace', 'paused', 'cancelled', 'expired',
    ];

    /**
     * @var array<string,array<int,string>> The authoritative adjacency map —
     * see the class doc-comment above for the derivation of every edge.
     * Keys are the FROM status; values are the list of legal TO statuses.
     * 'expired' has no outgoing edges — it is terminal.
     */
    private const VALID_TRANSITIONS = [
        'pending'   => ['active', 'trial', 'cancelled'],
        'trial'     => ['active', 'past_due', 'paused', 'cancelled'],
        'active'    => ['past_due', 'paused', 'cancelled', 'expired'],
        'past_due'  => ['active', 'grace', 'cancelled'],
        'grace'     => ['active', 'expired', 'cancelled'],
        'paused'    => ['active', 'cancelled'],
        'cancelled' => ['active'],
        'expired'   => [],
    ];

    // ========================================================================
    // ✅ TRANSITION VALIDATION
    // ========================================================================

    /**
     * ✅ Can This Transition Happen?
     *
     * Pure predicate — no DB access, no side effects. Re-applying the same
     * state (from === to) is intentionally NOT considered a "transition" by
     * this method (it returns false for that pair); callers that want the
     * idempotent no-op behaviour should use transition(), which handles that
     * case explicitly before ever consulting this table.
     *
     * @param string $from Current subscriptionStatus
     * @param string $to   Proposed subscriptionStatus
     *
     * @return bool True if the adjacency map permits from -> to
     */
    public static function canTransition(string $from, string $to): bool
    {
        if (!array_key_exists($from, self::VALID_TRANSITIONS)) {
            return false;
        }

        return in_array($to, self::VALID_TRANSITIONS[$from], true);
    }

    /**
     * 🚫 Assert a Transition Is Legal (Throws on Illegal)
     *
     * @param string $from Current subscriptionStatus
     * @param string $to   Proposed subscriptionStatus
     *
     * @return void
     *
     * @throws InvalidSubscriptionTransitionException When the transition is not permitted,
     *                                                 or either status string is unrecognised
     */
    public static function assertTransition(string $from, string $to): void
    {
        if (!in_array($from, self::VALID_STATES, true)) {
            throw new InvalidSubscriptionTransitionException(
                "Unknown current subscription status: '{$from}'."
            );
        }

        if (!in_array($to, self::VALID_STATES, true)) {
            throw new InvalidSubscriptionTransitionException(
                "Unknown target subscription status: '{$to}'."
            );
        }

        if (!self::canTransition($from, $to)) {
            throw new InvalidSubscriptionTransitionException(
                "Illegal subscription state transition: '{$from}' -> '{$to}'. " .
                "See SubscriptionStateMachine::VALID_TRANSITIONS (G-002 spec §4.2)."
            );
        }
    }

    // ========================================================================
    // 💾 THE TRANSITION (VALIDATE + PERSIST + LOG)
    // ========================================================================

    /**
     * 🔄 Transition a Subscription to a New Status
     *
     * Loads the subscription's CURRENT status, validates the requested
     * transition against VALID_TRANSITIONS, persists the new status (plus any
     * lifecycle timestamp columns that already exist on tblSubscriptions and
     * are semantically relevant to the target state — cancelledAt/
     * cancellationReason/pausedAt/endDate; migration 010), logs the change via
     * ActivityLogger, and returns true.
     *
     * IDEMPOTENT: if the subscription is already in the requested $to status,
     * this is a no-op success — no re-validation, no duplicate write, no
     * duplicate ActivityLogger entry — so a replayed webhook or a double
     * cron/lazy-batch tick can call this safely (G-002 spec §4.3).
     *
     * Does NOT drive any charge/refund/provider call — pure state + persistence
     * (see the class doc-comment). Later stages (S2-S4) that DO need to touch a
     * provider must call BillingMode::assertTestMode() themselves before doing
     * so; that is a separate, orthogonal concern from this class.
     *
     * @param int         $subscriptionID The tblSubscriptions.subscriptionID to transition
     * @param string      $to             The target subscriptionStatus (see VALID_STATES)
     * @param string      $reason         Human-readable reason, logged via ActivityLogger and,
     *                                    for a 'cancelled' target, persisted to
     *                                    tblSubscriptions.cancellationReason
     * @param string|null $idempotencyKey Optional caller-supplied idempotency key, logged only
     *                                    (no uniqueness enforcement at this layer in S1 — that
     *                                    lands with the richer per-charge ledger in S2+)
     *
     * @return bool True on success (including the idempotent no-op case)
     *
     * @throws InvalidSubscriptionTransitionException When the subscription does not exist,
     *                                                 or the transition is illegal
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/update.html
     */
    public static function transition(
        int $subscriptionID,
        string $to,
        string $reason = '',
        ?string $idempotencyKey = null
    ): bool {
        if (!in_array($to, self::VALID_STATES, true)) {
            throw new InvalidSubscriptionTransitionException(
                "Unknown target subscription status: '{$to}'."
            );
        }

        // 🔍 Load the current status. No explicit row lock here — this is a
        // pure status read/write; callers orchestrating concurrent
        // money-moving work (e.g. BillingScheduler) are responsible for their
        // OWN claim/lock around the wider operation (see the scheduler's
        // status='processing' claim pattern) before calling into this class.
        $subscription = Database::fetchOne(
            "SELECT subscriptionStatus FROM tblSubscriptions WHERE subscriptionID = ?",
            [$subscriptionID],
            'i'
        );

        if (!$subscription) {
            throw new InvalidSubscriptionTransitionException(
                "Subscription #{$subscriptionID} not found."
            );
        }

        $from = (string) $subscription['subscriptionStatus'];

        // ✅ Idempotent no-op — re-applying the same state always succeeds
        // without touching the transition table or writing a duplicate log
        // entry. This is deliberate: it is what makes this method safe to
        // call from a replayed webhook or an overlapping scheduler tick.
        if ($from === $to) {
            return true;
        }

        self::assertTransition($from, $to);

        self::persistTransition($subscriptionID, $to, $reason);

        // 📝 Log the transition for audit/debugging (house convention: category
        // 'payment' — matches every existing BillingScheduler/PaymentManager call).
        ActivityLogger::log(
            null,
            'subscription_state_transition',
            'payment',
            'info',
            "Subscription #{$subscriptionID} transitioned: '{$from}' -> '{$to}'" . ($reason !== '' ? " ({$reason})" : ''),
            [
                'subscriptionID' => $subscriptionID,
                'from'           => $from,
                'to'             => $to,
                'reason'         => $reason,
                'idempotencyKey' => $idempotencyKey,
            ]
        );

        return true;
    }

    // ========================================================================
    // 🔒 PRIVATE HELPERS
    // ========================================================================

    /**
     * 💾 Persist the New Status (+ Relevant Existing Timestamp Columns)
     *
     * Only touches columns that already exist on tblSubscriptions as of
     * migration 010 (cancelledAt, cancellationReason, pausedAt, endDate,
     * updatedAt) — the richer dunning columns from the G-002 spec's fuller
     * §6.2 (dunningAttempts/dunningStartedAt/graceEndsAt/lastRenewalInvoiceID)
     * are explicitly OUT OF SCOPE for this migration/stage and are NOT
     * referenced here, so this class never breaks against the S1 schema.
     *
     * @param int    $subscriptionID The subscription to update
     * @param string $to             The validated target status
     * @param string $reason         Cancellation reason (only used when $to === 'cancelled')
     *
     * @return void
     */
    private static function persistTransition(int $subscriptionID, string $to, string $reason): void
    {
        switch ($to) {
            case 'cancelled':
                // 🚫 'cancelled' in this lifecycle always means the IMMEDIATE
                // cancel path (end-of-period cancel keeps status 'active' with
                // autoRenew=0 until it lapses straight to 'expired' — see the
                // class doc-comment) — so endDate = today is always correct here.
                Database::query(
                    "UPDATE tblSubscriptions
                        SET subscriptionStatus = 'cancelled',
                            cancelledAt = NOW(),
                            cancellationReason = ?,
                            endDate = COALESCE(endDate, CURDATE()),
                            updatedAt = NOW()
                      WHERE subscriptionID = ?",
                    [$reason, $subscriptionID],
                    'si'
                );
                break;

            case 'paused':
                Database::query(
                    "UPDATE tblSubscriptions
                        SET subscriptionStatus = 'paused',
                            pausedAt = NOW(),
                            updatedAt = NOW()
                      WHERE subscriptionID = ?",
                    [$subscriptionID],
                    'i'
                );
                break;

            case 'expired':
                Database::query(
                    "UPDATE tblSubscriptions
                        SET subscriptionStatus = 'expired',
                            endDate = COALESCE(endDate, CURDATE()),
                            updatedAt = NOW()
                      WHERE subscriptionID = ?",
                    [$subscriptionID],
                    'i'
                );
                break;

            default:
                // pending / trial / active / past_due / grace — plain status swap.
                Database::query(
                    "UPDATE tblSubscriptions
                        SET subscriptionStatus = ?,
                            updatedAt = NOW()
                      WHERE subscriptionID = ?",
                    [$to, $subscriptionID],
                    'si'
                );
                break;
        }
    }
}

// ✅ SubscriptionStateMachine loaded successfully
