<?php
/**
 * ============================================================================
 * 🧪 BillingMode Unit Tests (G-002 Stage S1 — the TEST-MODE fail-closed guard)
 * ============================================================================
 *
 * Covers the pure decision logic of the guard — provider mode resolution,
 * the test/live predicate, and assertTestMode()'s pass-vs-throw behaviour —
 * WITHOUT a database connection. This is safe even though assertTestMode()
 * attempts a tblBillingAttempts audit write on the BLOCKED path: that write
 * is wrapped in `catch (\Throwable $e)` inside BillingMode::recordBlockedAttempt(),
 * so the "Class Database not found" \Error thrown in a Unit-test context
 * (Database is never loaded here — matches the established
 * SecurityAlertManagerTest pattern of exercising DB-touching code paths
 * without a DB, per its "…HandlesNoDatabaseGracefully" tests) is caught and
 * logged, and the guard's own BillingModeException still propagates exactly
 * as it would with a real connection. The row-actually-written assertion
 * lives in the Integration suite (BillingModeGuardTest), which DOES have a DB.
 *
 * @package    SIGNula\Tests\Unit\Payments
 * @version    2.9.0-beta
 * @see        web/private_html/payments/BillingMode.php
 * @see        .dev-team/specs/G-002.md §0/§9.1
 */

namespace SIGNula\Tests\Unit\Payments;

use SIGNula\Tests\TestCase;
use BillingMode;
use BillingModeException;

requireSource('private_html/payments/BillingModeException.php');
requireSource('private_html/payments/BillingMode.php');

/**
 * 🛡️ BillingMode test suite.
 */
class BillingModeTest extends TestCase
{
    // ========================================================================
    // 🔍 providerMode() / isTestMode()
    // ========================================================================

    public function testProviderModeDefaultsToSandboxForPaypalWhenUnset(): void
    {
        $this->assertSame('sandbox', BillingMode::providerMode('paypal'));
    }

    public function testProviderModeDefaultsToTestForStripeWhenUnset(): void
    {
        $this->assertSame('test', BillingMode::providerMode('stripe'));
    }

    public function testProviderModeDefaultsToSandboxForCoinbaseWhenUnset(): void
    {
        $this->assertSame('sandbox', BillingMode::providerMode('coinbase'));
    }

    public function testProviderModeFallsBackToSandboxForAnUnknownProvider(): void
    {
        // 🔮 Forward-compatible: a provider this class has never heard of
        // still resolves to a safe non-live default rather than erroring.
        $this->assertSame('sandbox', BillingMode::providerMode('some_future_provider'));
    }

    public function testProviderModeReadsTheConfiguredSetting(): void
    {
        setTestSetting('payment.paypal.mode', 'live');
        $this->assertSame('live', BillingMode::providerMode('paypal'));
    }

    public function testProviderModeIsCaseInsensitiveToTheProviderKey(): void
    {
        setTestSetting('payment.paypal.mode', 'live');
        $this->assertSame('live', BillingMode::providerMode('PayPal'));
        $this->assertSame('live', BillingMode::providerMode('PAYPAL'));
    }

    public function testIsTestModeTrueForSandboxAndTestValues(): void
    {
        setTestSetting('payment.paypal.mode', 'sandbox');
        setTestSetting('payment.stripe.mode', 'test');
        $this->assertTrue(BillingMode::isTestMode('paypal'));
        $this->assertTrue(BillingMode::isTestMode('stripe'));
    }

    public function testIsTestModeFalseForLiveValue(): void
    {
        setTestSetting('payment.stripe.mode', 'live');
        $this->assertFalse(BillingMode::isTestMode('stripe'));
    }

    public function testIsTestModeTreatsAnUnrecognisedModeValueAsNotLive(): void
    {
        // 🛡️ Fail-safe direction: a typo'd/garbage mode value must never be
        // silently treated as "live" — the dangerous direction is
        // "unexpectedly live", so anything other than the exact literal
        // 'live' counts as test mode.
        setTestSetting('payment.paypal.mode', 'sandboxx');
        $this->assertTrue(BillingMode::isTestMode('paypal'));
    }

    // ========================================================================
    // 🚫 assertTestMode() — the fail-closed gate
    // ========================================================================

    public function testAssertTestModePassesSilentlyWhenSandboxAndGuardOn(): void
    {
        setTestSetting('payment.paypal.mode', 'sandbox');
        setTestSetting('billing.test_mode_guard', '1');

        BillingMode::assertTestMode('paypal');
        $this->assertTrue(true, 'assertTestMode() must not throw for a sandbox-mode provider');
    }

    public function testAssertTestModePassesSilentlyWhenTestAndGuardOn(): void
    {
        setTestSetting('payment.stripe.mode', 'test');
        setTestSetting('billing.test_mode_guard', '1');

        BillingMode::assertTestMode('stripe');
        $this->assertTrue(true, 'assertTestMode() must not throw for a test-mode provider');
    }

    /**
     * 🔑 THE crux test: guard ON + provider LIVE → THROWS BillingModeException.
     * This is the code-level enforcement of G-002 §0 — "no live-charge code
     * path may be reachable" while the guard is on (the default).
     */
    public function testAssertTestModeThrowsWhenLiveAndGuardOn(): void
    {
        setTestSetting('payment.paypal.mode', 'live');
        setTestSetting('billing.test_mode_guard', '1');

        $this->expectException(BillingModeException::class);
        BillingMode::assertTestMode('paypal');
    }

    public function testAssertTestModeThrowsForStripeTooWhenLiveAndGuardOn(): void
    {
        setTestSetting('payment.stripe.mode', 'live');
        setTestSetting('billing.test_mode_guard', '1');

        $this->expectException(BillingModeException::class);
        BillingMode::assertTestMode('stripe');
    }

    public function testAssertTestModeDefaultsToGuardOnWhenSettingIsMissingEntirely(): void
    {
        // 🛡️ Fail-closed-by-default: if billing.test_mode_guard has never
        // been set (e.g. migration 036 has not run on an older install),
        // the guard must still enforce — never fail OPEN.
        setTestSetting('payment.paypal.mode', 'live');
        // Deliberately NOT setting billing.test_mode_guard at all.

        $this->expectException(BillingModeException::class);
        BillingMode::assertTestMode('paypal');
    }

    /**
     * ✅ The ONLY way a live provider call is allowed through: the guard is
     * explicitly turned OFF. This documents the deliberate, user-gated act
     * (GitHub issue #70) — this test does not, and must never, describe an
     * automatic path to this state.
     */
    public function testAssertTestModePassesWhenLiveButGuardExplicitlyOff(): void
    {
        setTestSetting('payment.paypal.mode', 'live');
        setTestSetting('billing.test_mode_guard', '0');

        BillingMode::assertTestMode('paypal');
        $this->assertTrue(true, 'assertTestMode() must not throw once the guard is deliberately OFF');
    }

    /**
     * @dataProvider guardOffSpellingsProvider
     */
    public function testAssertTestModeAcceptsCommonOffSpellings(string $offValue): void
    {
        setTestSetting('payment.stripe.mode', 'live');
        setTestSetting('billing.test_mode_guard', $offValue);

        BillingMode::assertTestMode('stripe');
        $this->assertTrue(true, "guard value '{$offValue}' must be treated as OFF");
    }

    public static function guardOffSpellingsProvider(): array
    {
        return [
            'zero'  => ['0'],
            'false' => ['false'],
            'off'   => ['off'],
            'no'    => ['no'],
            'empty' => [''],
        ];
    }

    public function testAssertTestModeExceptionMessageNamesTheProviderAndIssue70(): void
    {
        setTestSetting('payment.paypal.mode', 'live');
        setTestSetting('billing.test_mode_guard', '1');

        try {
            BillingMode::assertTestMode('paypal');
            $this->fail('Expected BillingModeException was not thrown');
        } catch (BillingModeException $e) {
            $this->assertStringContainsString('paypal', $e->getMessage());
            $this->assertStringContainsString('#70', $e->getMessage());
        }
    }
}
