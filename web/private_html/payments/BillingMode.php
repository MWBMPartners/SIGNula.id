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
 * 🛡️ SIGNula - Billing TEST-MODE Guard (G-002 Stage S1)
 * ============================================================================
 *
 * Purpose: A single, central, FAIL-CLOSED guard that stops any money-moving
 *          payment-provider call from ever reaching a LIVE endpoint while the
 *          recurring-billing engine (G-002) is still being built out.
 * PHP Version: 8.3+ (developed/tested on 8.4)
 *
 * ── Why this class exists (G-002 spec §0 / §9.1) ──────────────────────────
 * G-002 ("Recurring Billing / Subscription Lifecycle Engine") is explicitly
 * TEST-MODE ONLY for its entire build: "No real money moves. Going live is a
 * separate, USER-GATED operational step — GitHub issue #70 ('Configure Live
 * Payment Provider Credentials')." This class is the CODE-LEVEL enforcement of
 * that constraint — a static gate every provider entry point calls before it
 * is allowed to touch the network:
 *
 *   1. BillingMode::providerMode('paypal'|'stripe'|'coinbase'|…) resolves the
 *      provider's configured mode from tblSettings (payment.{provider}.mode).
 *   2. BillingMode::isTestMode($provider) is true iff that mode is anything
 *      OTHER than the literal string 'live' (so an unrecognised/misspelled
 *      mode value fails safely towards "treat as test", never towards "treat
 *      as live").
 *   3. BillingMode::assertTestMode($provider) is the actual gate: if the
 *      `billing.test_mode_guard` setting (tblSettings, default '1'/ON — see
 *      migration 036) is ON AND the provider is resolved as 'live', it writes
 *      an auditable 'blocked' row to tblBillingAttempts and THROWS
 *      BillingModeException — fail closed, no charge, no provider API call.
 *
 * Wired into (see each file's own doc-comment for the exact call site):
 *   - PayPalProvider::createSubscription() / ::refund()
 *   - StripeProvider::createCheckoutSession() / ::refund()
 *   - PaymentManager::createSubscription() / ::recordPayment()
 *   (PayPalProvider/StripeProvider ::chargeStoredMethod() do NOT exist in this
 *   codebase — grep-confirmed zero hits — so there is nothing to wire there;
 *   see G-002 spec §1.1 point 2 and §3, which explains that recurring billing
 *   is provider-managed, not a self-driven "pull a stored card" engine.)
 *
 * ── Turning the guard OFF is the user's deliberate act (#70) ─────────────
 * This class NEVER flips `billing.test_mode_guard` itself. The only way a
 * live charge can ever flow through these entry points again is for a human
 * to explicitly set `billing.test_mode_guard = '0'` via the admin Settings UI
 * (or DB) AFTER they have set real live provider credentials — a conscious,
 * auditable, two-step act. That step is entirely OUT OF SCOPE for G-002.
 *
 * @see https://developer.paypal.com/docs/api/overview/ (PayPal REST API — mode is sandbox|live)
 * @see https://docs.stripe.com/keys#test-live-modes (Stripe test vs live mode keys)
 * @see _database/migrations/036_recurring_billing_foundation.sql (tblBillingAttempts + billing.test_mode_guard seed)
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

// 📚 Require the dedicated exception type this class throws. Note:
// web/private_html/payments/ is NOT covered by the spl_autoload_register()
// directory list in web/_config/config.php (only security/api/auth/utils/email
// subdirectories of private_html are autoloaded — payments/ classes are always
// explicitly required, mirroring PayPalProvider.php's/StripeProvider.php's own
// require of PaymentManager.php immediately below their SIGNULA_INIT guard).
$billingModeExceptionPath = __DIR__ . DIRECTORY_SEPARATOR . 'BillingModeException.php';
if (file_exists($billingModeExceptionPath)) {
    require_once $billingModeExceptionPath;
} else {
    // ⚠️ Critical dependency — log and halt if the exception type is missing
    error_log('🚨 [BillingMode] BillingModeException.php not found at: ' . $billingModeExceptionPath);
    throw new RuntimeException('Required dependency BillingModeException.php is not available');
}

/**
 * 🛡️ BillingMode
 *
 * Static utility class — no instances, following the project-wide convention
 * (Auth, MFA, SecurityUtils, TOTP, RateLimiter, PaymentManager, …).
 *
 * Configuration is read from tblSettings via getSetting():
 *   - payment.paypal.mode      ('sandbox' or 'live', default 'sandbox')
 *   - payment.stripe.mode      ('test' or 'live', default 'test')
 *   - payment.coinbase.mode    ('sandbox' or 'live', default 'sandbox')
 *   - billing.test_mode_guard  ('1' = ON/enforced, '0' = OFF; default '1' — see migration 036)
 */
class BillingMode
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /** @var string The single mode value that means "this provider will move real money". */
    private const LIVE_MODE = 'live';

    /**
     * @var array<string,string> Provider => the tblSettings default mode value used when
     * the provider has never been configured. Different providers historically use
     * different vocabulary for "not live" ('sandbox' vs 'test') — see PayPalProvider.php
     * / StripeProvider.php doc-comments — so the safe default is provider-specific.
     */
    private const DEFAULT_MODE_BY_PROVIDER = [
        'paypal'   => 'sandbox',
        'stripe'   => 'test',
        'coinbase' => 'sandbox',
    ];

    /** @var string Fallback default mode for any provider not listed above (forward-compatible). */
    private const DEFAULT_MODE_FALLBACK = 'sandbox';

    // ========================================================================
    // 🔍 MODE RESOLUTION
    // ========================================================================

    /**
     * 🔍 Resolve a Provider's Configured Mode
     *
     * Reads `payment.{provider}.mode` from tblSettings (via getSetting()).
     * Generic by design so new providers (e.g. a future PayPal-alternative)
     * work without a code change here — only their own tblSettings row needs
     * to exist.
     *
     * @param string $provider Provider key, e.g. 'paypal', 'stripe', 'coinbase'
     *                         (case-insensitive; internally lower-cased).
     *
     * @return string The resolved mode string exactly as stored (e.g. 'sandbox',
     *                'test', or 'live'). Never assumes a specific vocabulary
     *                beyond the literal 'live' sentinel checked by isTestMode().
     *
     * @see https://www.php.net/manual/en/function.strtolower.php
     */
    public static function providerMode(string $provider): string
    {
        $providerKey = strtolower(trim($provider));
        $default = self::DEFAULT_MODE_BY_PROVIDER[$providerKey] ?? self::DEFAULT_MODE_FALLBACK;

        // 🔍 tblSettings key convention (see PayPalProvider/StripeProvider doc-comments):
        // payment.paypal.mode, payment.stripe.mode, payment.coinbase.mode, …
        return (string) getSetting('payment.' . $providerKey . '.mode', $default);
    }

    /**
     * ✅ Is a Provider Currently in TEST/SANDBOX Mode?
     *
     * True for ANY resolved mode value other than the literal string 'live'.
     * This is deliberately fail-safe: an empty, missing, or misspelled mode
     * setting is treated as "not live" (i.e. blocked from nothing) rather than
     * silently treated as live — the DANGEROUS direction is "unexpectedly
     * live", so only an exact 'live' match ever reduces safety.
     *
     * @param string $provider Provider key, e.g. 'paypal', 'stripe', 'coinbase'
     *
     * @return bool True if the provider is NOT in live mode
     */
    public static function isTestMode(string $provider): bool
    {
        return self::providerMode($provider) !== self::LIVE_MODE;
    }

    // ========================================================================
    // 🚫 THE FAIL-CLOSED GATE
    // ========================================================================

    /**
     * 🚫 Assert TEST Mode — Fail Closed on Live
     *
     * The single call every money-moving provider entry point makes BEFORE
     * touching the network (see the class doc-comment for the full wired-in
     * list). If the `billing.test_mode_guard` setting is ON (the default —
     * see migration 036) and the provider resolves to 'live' mode, this
     * method:
     *   1. Writes an auditable 'blocked' row to tblBillingAttempts (so a
     *      would-be-live attempt is never silent — G-002 spec §9.1), and
     *   2. Throws BillingModeException — the caller's own try/catch (every
     *      wired entry point already wraps its logic in try/catch per house
     *      convention) converts this into that method's normal
     *      `['success' => false, ...]` failure response. No provider HTTP
     *      call is ever reached.
     *
     * When the guard is OFF, or the provider is not in live mode, this method
     * returns silently (no DB write) — the normal, expected, sandbox-testing
     * path incurs zero extra overhead.
     *
     * @param string $provider Provider key, e.g. 'paypal', 'stripe', 'coinbase'
     *
     * @return void
     *
     * @throws BillingModeException When the guard is ON and the provider is 'live'
     *
     * @see https://www.php.net/manual/en/function.debug-backtrace.php (used only for the audit 'action' label)
     */
    public static function assertTestMode(string $provider): void
    {
        $providerKey = strtolower(trim($provider));
        $mode = self::providerMode($providerKey);

        // ✅ Fast, silent path — this is what every sandbox-testing call takes.
        if (!self::isGuardEnabled() || $mode !== self::LIVE_MODE) {
            return;
        }

        // 🚫 Guard ON + provider resolved LIVE — fail closed.
        // 📝 Record the blocked attempt BEFORE throwing, so the audit trail
        // exists even though the caller's try/catch will swallow the
        // exception into its own failure response.
        self::recordBlockedAttempt($providerKey, $mode);

        throw new BillingModeException(
            "Refused to execute a LIVE-mode billing operation for provider '{$providerKey}' " .
            "because billing.test_mode_guard is ON (G-002 is TEST-MODE ONLY — see spec §0). " .
            "Going live requires a deliberate, user-gated step: set real live credentials AND " .
            "explicitly turn billing.test_mode_guard OFF via the admin Settings UI (GitHub issue #70)."
        );
    }

    // ========================================================================
    // 🔒 PRIVATE HELPERS
    // ========================================================================

    /**
     * 🔒 Is the TEST-MODE Guard Currently Enabled?
     *
     * Reads `billing.test_mode_guard` from tblSettings. Defaults to ENABLED
     * ('1') if the setting is somehow missing (e.g. migration 036 has not
     * run yet on an older install) — the guard fails closed by default, not
     * open, matching the "safe by construction" principle in G-002 §0.
     *
     * @return bool True if the guard is ON (should enforce test-mode)
     */
    private static function isGuardEnabled(): bool
    {
        $raw = getSetting('billing.test_mode_guard', '1');

        // 🔍 Accept the common "off" spellings a settings UI checkbox/select
        // might produce; anything else (including an unexpected value) is
        // treated as ON — fail closed, never fail open.
        return !in_array(strtolower(trim((string) $raw)), ['0', '', 'false', 'off', 'no'], true);
    }

    /**
     * 📝 Record a Blocked Live-Mode Attempt
     *
     * Writes a single audit row to tblBillingAttempts with status='blocked'
     * so a would-be-live charge is always traceable, even though it never
     * reached the provider. Best-effort: if the INSERT itself fails (e.g. a
     * transient DB issue), the failure is logged via ActivityLogger/error_log
     * but does NOT prevent the BillingModeException from still being thrown —
     * an audit-logging hiccup must never weaken the fail-closed guarantee.
     *
     * @param string $provider The lower-cased provider key
     * @param string $mode     The resolved mode at the time of the attempt (always 'live' here)
     *
     * @return void
     */
    private static function recordBlockedAttempt(string $provider, string $mode): void
    {
        try {
            // 🔍 Best-effort caller identification for the audit 'action' column —
            // e.g. 'PayPalProvider::createSubscription'. Never throws; falls back
            // to a generic label if the backtrace is unavailable for any reason.
            // Defensively truncated to the column width (VARCHAR(191)) — a
            // deeply-namespaced caller (e.g. in test code) could otherwise
            // exceed it and turn an audit WRITE into a second failure.
            $action = mb_substr(self::resolveCallerLabel(), 0, 191);

            $ipAddress = null;
            if (function_exists('getClientIP')) {
                $ipAddress = getClientIP();
            } elseif (isset($_SERVER['REMOTE_ADDR'])) {
                $ipAddress = (string) $_SERVER['REMOTE_ADDR'];
            }

            Database::query(
                "INSERT INTO tblBillingAttempts
                    (provider, action, mode, subscriptionID, userID, idempotencyKey, status, detail, ipAddress, createdAt)
                 VALUES (?, ?, ?, NULL, NULL, NULL, 'blocked', ?, ?, NOW())",
                [
                    $provider,
                    $action,
                    $mode,
                    'Blocked by billing.test_mode_guard: provider resolved to live mode.',
                    $ipAddress,
                ],
                'sssss'
            );
        } catch (\Throwable $e) {
            // 🛑 Never let audit-logging failure mask or prevent the guard itself.
            error_log('🚨 [BillingMode] Failed to record blocked billing attempt: ' . $e->getMessage());

            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    null,
                    'billing_guard_audit_failed',
                    'payment',
                    'error',
                    'Failed to write blocked tblBillingAttempts row: ' . $e->getMessage(),
                    ['provider' => $provider, 'mode' => $mode]
                );
            }
        }
    }

    /**
     * 🔍 Resolve a Short "Caller" Label for the Audit Row
     *
     * Walks the call stack to find the first frame outside this class (i.e.
     * the provider method that called assertTestMode()) and formats it as
     * 'Class::method'. Purely diagnostic — never throws, always returns a
     * usable string even if the backtrace is empty or unusual.
     *
     * @return string e.g. 'PayPalProvider::createSubscription', or 'unknown'
     *
     * @see https://www.php.net/manual/en/function.debug-backtrace.php
     */
    private static function resolveCallerLabel(): string
    {
        // 🔍 Frame depth from HERE: [0]=resolveCallerLabel, [1]=recordBlockedAttempt,
        // [2]=assertTestMode (all BillingMode) — the first frame OUTSIDE this
        // class (the actual provider entry point, e.g. PayPalProvider::
        // createSubscription) is [3]. Request a few extra frames of margin
        // in case this method is ever called one level deeper.
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

        foreach ($trace as $frame) {
            $class = $frame['class'] ?? null;
            if ($class !== null && $class !== self::class) {
                return $class . ($frame['type'] ?? '::') . ($frame['function'] ?? 'unknown');
            }
        }

        return 'unknown';
    }
}

// ✅ BillingMode loaded successfully
