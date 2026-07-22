<?php
/**
 * ============================================================================
 * 🩻 SIGNula G-002 — TEST-MODE GUARD REGRESSION (one-time checkout) — F-B01
 * ============================================================================
 *
 * BLUE-TEAM FOLLOW-UP to the independent adversarial PoC that found F-B01
 * (CRITICAL): the one-time Checkout entry points that the PUBLIC,
 * authenticated checkout flow (web/public_html/checkout/process.php +
 * success.php) actually calls were NOT wired to the fail-closed
 * BillingMode::assertTestMode($provider) guard:
 *   - PayPalProvider::createOrder()   (process.php → PayPal live order)
 *   - PayPalProvider::captureOrder()  (success.php → captures REAL money)
 *   - CoinbaseProvider::createCharge() (process.php → live crypto charge)
 *   - StripeProvider::createPaymentIntent() (latent — no caller yet)
 * Only the SUBSCRIPTION/refund entry points called the guard, so with the
 * guard ON but a provider set to 'live', a normal user completing a
 * PayPal/Coinbase checkout could have been charged for real.
 *
 * FIX: `BillingMode::assertTestMode(<provider>)` is now the FIRST line of
 * each of the four methods above (mirroring the pre-existing
 * createSubscription()/refund() pattern exactly).
 *
 * This test now asserts the SECURE outcome: the bypass is CLOSED. Every
 * assertion below FAILS if the guard is ever removed from one of these
 * methods again — flipping this back into a live red-team signal.
 *
 * ⚠️ SAFETY: assertTestMode() throws BEFORE any HTTP call/getBaseURL() is
 * reached (guard-on + live is asserted in setUp()), so the behavioural
 * assertions below invoke the REAL provider methods with ZERO network risk —
 * no live/sandbox endpoint is ever hit and no provider credentials are
 * required.
 *
 * @package SIGNula\Tests\Security
 */

namespace SIGNula\Tests\Security;

use SIGNula\Tests\DatabaseTestCase;

requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/payments/PaymentManager.php');
requireSource('private_html/payments/BillingModeException.php');
requireSource('private_html/payments/BillingMode.php');
requireSource('private_html/payments/PayPalProvider.php');
requireSource('private_html/payments/StripeProvider.php');
requireSource('private_html/payments/CoinbaseProvider.php');

class BillingTestModeBypassTest extends DatabaseTestCase
{
    /** @var bool No per-test transaction — BillingMode writes via the Database singleton. */
    protected bool $useTransactions = false;

    protected function setUp(): void
    {
        parent::setUp();
        // 🛡️ Guard ON + every provider set to LIVE — the "safe by default" posture
        // the guard is meant to enforce.
        setTestSetting('billing.test_mode_guard', '1');
        setTestSetting('payment.paypal.mode', 'live');
        setTestSetting('payment.stripe.mode', 'live');
        setTestSetting('payment.coinbase.mode', 'live');
    }

    /**
     * Read the exact source lines of a method (no execution → zero network risk).
     */
    private function methodSource(string $class, string $method): string
    {
        $ref = new \ReflectionMethod($class, $method);
        $lines = file($ref->getFileName());
        return implode('', array_slice(
            $lines,
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1
        ));
    }

    /**
     * ✅ Sanity: the guard itself DOES work when a method actually calls it.
     * assertTestMode('paypal') under guard-on + live must throw AND write a
     * 'blocked' tblBillingAttempts audit row. This proves the guard is
     * functional — so the ONLY reason the one-time paths are unsafe is that
     * they never call it (asserted below).
     */
    public function testGuardFiresWhenActuallyInvoked(): void
    {
        $before = (int) (\Database::fetchOne(
            "SELECT COUNT(*) AS c FROM tblBillingAttempts WHERE status = 'blocked'"
        )['c'] ?? 0);

        $threw = false;
        try {
            \BillingMode::assertTestMode('paypal');
        } catch (\BillingModeException $e) {
            $threw = true;
        }

        $after = (int) (\Database::fetchOne(
            "SELECT COUNT(*) AS c FROM tblBillingAttempts WHERE status = 'blocked'"
        )['c'] ?? 0);

        $this->assertTrue($threw, 'assertTestMode() must throw for a live provider while the guard is ON');
        $this->assertSame($before + 1, $after, 'A blocked attempt must be audited to tblBillingAttempts');
    }

    /**
     * ✅ FIX VERIFIED (structural): the four one-time money-moving entry
     * points NOW call the guard as their first line. (These are the methods
     * web/public_html/checkout/{process,success}.php actually invoke.)
     */
    public function testOneTimeChargePathsAreNowGuarded(): void
    {
        $nowGuarded = [
            ['PayPalProvider',   'createOrder'],        // process.php:197 (live PayPal order)
            ['PayPalProvider',   'captureOrder'],       // success.php:103 (captures real money)
            ['CoinbaseProvider', 'createCharge'],       // process.php:251 (live crypto charge)
            ['StripeProvider',   'createPaymentIntent'], // latent one-time charge
        ];

        foreach ($nowGuarded as [$class, $method]) {
            $src = $this->methodSource($class, $method);
            $this->assertStringContainsString(
                'assertTestMode',
                $src,
                "F-B01 FIX: {$class}::{$method}() must call BillingMode::assertTestMode() before reaching "
                . 'any live provider call — regression on the public checkout flow.'
            );
        }
    }

    /**
     * ✅ FIX VERIFIED (behavioural): actually INVOKE each of the four
     * previously-unguarded entry points with the guard ON and every provider
     * resolved to 'live' (see setUp()). Each call must fail closed — return
     * `['success' => false, ...]` — and write exactly one 'blocked' audit row
     * to tblBillingAttempts, proving BillingMode::assertTestMode() actually
     * fired and threw BillingModeException BEFORE any network call, not just
     * that the source text mentions it (belt-and-braces over the structural
     * check above).
     *
     * No live/sandbox endpoint is ever reached: assertTestMode() throws on
     * the very first line of each method, long before getBaseURL()/
     * apiRequest()/curl is touched — @see BillingMode::assertTestMode().
     */
    public function testOneTimeChargePathsNowBlockLiveModeCalls(): void
    {
        $calls = [
            ['PayPalProvider',   'createOrder',        [29.99, 'GBP', 'red-team regression test', []]],
            ['PayPalProvider',   'captureOrder',        ['RED-TEAM-FAKE-ORDER-ID']],
            ['CoinbaseProvider', 'createCharge',        [29.99, 'GBP', 'red-team regression test', 1, []]],
            ['StripeProvider',   'createPaymentIntent', [29.99, 'GBP', []]],
        ];

        foreach ($calls as [$class, $method, $args]) {
            $before = (int) (\Database::fetchOne(
                "SELECT COUNT(*) AS c FROM tblBillingAttempts WHERE status = 'blocked'"
            )['c'] ?? 0);

            $result = $class::$method(...$args);

            $after = (int) (\Database::fetchOne(
                "SELECT COUNT(*) AS c FROM tblBillingAttempts WHERE status = 'blocked'"
            )['c'] ?? 0);

            $this->assertFalse(
                $result['success'] ?? true,
                "F-B01 FIX: {$class}::{$method}() must fail closed (['success' => false]) when "
                . 'billing.test_mode_guard is ON and the provider resolves to live mode.'
            );
            $this->assertSame(
                $before + 1,
                $after,
                "F-B01 FIX: {$class}::{$method}() must write exactly one 'blocked' tblBillingAttempts "
                . 'audit row per call — proves assertTestMode() actually fired, not just returned false '
                . 'for some unrelated reason.'
            );
        }
    }

    /**
     * 🧷 Control: the SUBSCRIPTION + refund entry points ARE wired to the guard.
     * This isolates the finding to "some money paths guarded, one-time paths not"
     * (an inconsistency), not "the guard is unused everywhere".
     */
    public function testSubscriptionAndRefundPathsAreGuarded(): void
    {
        $guarded = [
            ['PayPalProvider', 'createSubscription'],
            ['PayPalProvider', 'refund'],
            ['StripeProvider', 'createCheckoutSession'],
            ['StripeProvider', 'refund'],
        ];

        foreach ($guarded as [$class, $method]) {
            $src = $this->methodSource($class, $method);
            $this->assertStringContainsString(
                'assertTestMode',
                $src,
                "{$class}::{$method}() is expected to call the guard (control assertion)"
            );
        }
    }
}
