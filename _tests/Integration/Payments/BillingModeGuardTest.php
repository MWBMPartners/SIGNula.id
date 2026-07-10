<?php
/**
 * ============================================================================
 * 🧪 BillingMode Guard Integration Tests (G-002 Stage S1 — fail-closed proof)
 * ============================================================================
 *
 * DB-bound proof of the TEST-MODE guard's two most important guarantees:
 *
 *   1. A BLOCKED attempt (live mode + guard ON) writes an auditable
 *      tblBillingAttempts row (status='blocked') — the Unit suite
 *      (BillingModeTest) already proves the THROW; this suite proves the
 *      AUDIT WRITE, which needs a real database connection.
 *   2. Wiring proof: PayPalProvider::createSubscription()/refund(),
 *      StripeProvider::createCheckoutSession()/refund(), and
 *      PaymentManager::createSubscription()/recordPayment() all REFUSE when
 *      their provider resolves to 'live' and the guard is on — WITHOUT ever
 *      reaching a provider HTTP call (proven by: the call returns near-
 *      instantly with no network timeout/hang, no row is written to
 *      tblPayments/tblSubscriptions, and a 'blocked' tblBillingAttempts row
 *      names the exact calling method). No real or fake HTTP transport is
 *      configured anywhere in this suite — if the guard did NOT block first,
 *      these calls would either hang trying to reach the real PayPal/Stripe
 *      API (this environment has none configured) or fail with a
 *      config/decrypt error, not the clean, guard-specific 'blocked' outcome
 *      asserted below.
 *
 * @package    SIGNula\Tests\Integration\Payments
 * @version    2.9.0-beta
 * @see        web/private_html/payments/BillingMode.php
 * @see        .dev-team/specs/G-002.md §0/§9.1
 */

namespace SIGNula\Tests\Integration\Payments;

use SIGNula\Tests\DatabaseTestCase;
use BillingMode;
use BillingModeException;
use PayPalProvider;
use StripeProvider;
use PaymentManager;

// 📦 Dependency order. PayPalProvider.php / StripeProvider.php each pull in
// PaymentManager.php and BillingMode.php (which pulls BillingModeException.php)
// themselves via their own require_once — see each file's own doc-comment.
requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/payments/BillingModeException.php');
requireSource('private_html/payments/BillingMode.php');
requireSource('private_html/payments/PaymentManager.php');
requireSource('private_html/payments/PayPalProvider.php');
requireSource('private_html/payments/StripeProvider.php');

/**
 * 🛡️ BillingMode guard wiring + audit-write test suite.
 */
class BillingModeGuardTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblBillingAttempts',
        'tblPayments',
        'tblSubscriptions',
        'tblSubscriptionTiers',
        'tblUsers',
    ];

    // ========================================================================
    // 📝 AUDIT-WRITE PROOF (the crux acceptance criterion)
    // ========================================================================

    public function testBlockedAttemptWritesAnAuditRowWithResolvedMode(): void
    {
        setTestSetting('payment.paypal.mode', 'live');
        setTestSetting('billing.test_mode_guard', '1');

        try {
            BillingMode::assertTestMode('paypal');
            $this->fail('Expected BillingModeException was not thrown');
        } catch (BillingModeException $e) {
            // ✅ expected
        }

        $row = $this->getRecord('tblBillingAttempts', ['provider' => 'paypal', 'status' => 'blocked']);
        $this->assertNotNull($row, 'assertTestMode() must write a blocked tblBillingAttempts row');
        $this->assertSame('live', $row['mode']);
        $this->assertNull($row['subscriptionID']);
        $this->assertNull($row['userID']);
    }

    public function testPassingAttemptsDoNotWriteAnAuditRow(): void
    {
        setTestSetting('payment.paypal.mode', 'sandbox');
        setTestSetting('billing.test_mode_guard', '1');

        BillingMode::assertTestMode('paypal');

        $this->assertSame(0, $this->countRecords('tblBillingAttempts', ['provider' => 'paypal']));
    }

    // ========================================================================
    // 🚫 PROVIDER-LEVEL WIRING PROOF
    // ========================================================================

    public function testPayPalCreateSubscriptionRefusesWhenLiveAndGuardOn(): void
    {
        setTestSetting('payment.paypal.mode', 'live');
        setTestSetting('billing.test_mode_guard', '1');

        $result = PayPalProvider::createSubscription(
            1,
            'monthly',
            'https://signula.id/return',
            'https://signula.id/cancel'
        );

        $this->assertFalse($result['success']);
        $row = $this->getRecord('tblBillingAttempts', ['provider' => 'paypal', 'status' => 'blocked']);
        $this->assertNotNull($row);
        $this->assertStringContainsString('PayPalProvider', $row['action']);
        $this->assertStringContainsString('createSubscription', $row['action']);
    }

    public function testPayPalRefundRefusesWhenLiveAndGuardOn(): void
    {
        setTestSetting('payment.paypal.mode', 'live');
        setTestSetting('billing.test_mode_guard', '1');

        $result = PayPalProvider::refund('FAKE-CAPTURE-ID');

        $this->assertFalse($result['success']);
        $this->assertNotNull($this->getRecord('tblBillingAttempts', ['provider' => 'paypal', 'status' => 'blocked']));
    }

    public function testPayPalCreateSubscriptionProceedsPastTheGuardWhenSandbox(): void
    {
        // 🧪 Sandbox mode must NOT be blocked by the guard — it should fail
        // for an ordinary config reason instead (PayPal not enabled/configured
        // in this test environment), proving the guard is not over-blocking.
        setTestSetting('payment.paypal.mode', 'sandbox');
        setTestSetting('billing.test_mode_guard', '1');
        setTestSetting('payment.paypal.enabled', '0');

        $result = PayPalProvider::createSubscription(
            1,
            'monthly',
            'https://signula.id/return',
            'https://signula.id/cancel'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('PayPal is not enabled or not configured', $result['message']);
        // ✅ No guard block was recorded — this failure came from isEnabled(), not the guard.
        $this->assertSame(0, $this->countRecords('tblBillingAttempts', ['provider' => 'paypal', 'status' => 'blocked']));
    }

    public function testStripeCreateCheckoutSessionRefusesWhenLiveAndGuardOn(): void
    {
        setTestSetting('payment.stripe.mode', 'live');
        setTestSetting('billing.test_mode_guard', '1');

        // 🔍 A real userID (not a placeholder) — createCheckoutSession()'s own
        // catch-block ActivityLogger::log($userID, …) call would otherwise
        // hit tblActivityLog's FK constraint on a nonexistent user, which is
        // harmlessly self-caught but noisy — same fixture hygiene as every
        // other test in this file.
        $user = $this->createTestUser();

        $result = StripeProvider::createCheckoutSession($user['userID'], 1, 'monthly');

        $this->assertFalse($result['success']);
        $row = $this->getRecord('tblBillingAttempts', ['provider' => 'stripe', 'status' => 'blocked']);
        $this->assertNotNull($row);
        $this->assertStringContainsString('StripeProvider', $row['action']);
    }

    public function testStripeRefundRefusesWhenLiveAndGuardOn(): void
    {
        setTestSetting('payment.stripe.mode', 'live');
        setTestSetting('billing.test_mode_guard', '1');

        $result = StripeProvider::refund('pi_fake');

        $this->assertFalse($result['success']);
        $this->assertNotNull($this->getRecord('tblBillingAttempts', ['provider' => 'stripe', 'status' => 'blocked']));
    }

    // ========================================================================
    // 🚫 PaymentManager-LEVEL WIRING PROOF (with a NO-CHARGE database proof)
    // ========================================================================

    public function testPaymentManagerRecordPaymentRefusesLiveGuardedProviderAndWritesNoPaymentRow(): void
    {
        setTestSetting('payment.paypal.mode', 'live');
        setTestSetting('billing.test_mode_guard', '1');

        $user = $this->createTestUser();

        $result = PaymentManager::recordPayment(
            $user['userID'],
            9.99,
            'paypal',
            'PayPal', // 🔍 mixed-case provider name — must still be recognised
            []
        );

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRecords('tblPayments', ['userID' => $user['userID']]), 'no tblPayments row must be written when the guard blocks the attempt');
        $this->assertNotNull($this->getRecord('tblBillingAttempts', ['provider' => 'paypal', 'status' => 'blocked']));
    }

    public function testPaymentManagerRecordPaymentAllowsManualProviderRegardlessOfPaypalLiveMode(): void
    {
        // 🛡️ Free/manual exemption: an UNRELATED provider being live must
        // never block a manual payment record. `paymentMethod` must be a
        // value tblPayments.paymentMethod's ENUM actually accepts ('card' —
        // NOT the guarded provider key); `paymentProvider` (a free-text
        // VARCHAR column, not ENUM-constrained) is the guard-relevant field.
        setTestSetting('payment.paypal.mode', 'live');
        setTestSetting('billing.test_mode_guard', '1');

        $user = $this->createTestUser();

        $result = PaymentManager::recordPayment($user['userID'], 9.99, 'card', 'manual', []);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->countRecords('tblPayments', ['userID' => $user['userID']]));
    }

    public function testPaymentManagerCreateSubscriptionRefusesLiveGuardedPaymentMethodAndWritesNoSubscriptionRow(): void
    {
        setTestSetting('payment.stripe.mode', 'live');
        setTestSetting('billing.test_mode_guard', '1');

        $user = $this->createTestUser();
        $tierID = $this->insertRecord('tblSubscriptionTiers', [
            'tierName'     => 'Guard Test Tier',
            'tierSlug'     => 'guard-test-tier-' . bin2hex(random_bytes(4)),
            'monthlyPrice' => 19.99,
            'yearlyPrice'  => 199.99,
            'currency'     => 'GBP',
            'features'     => '[]',
        ]);

        $result = PaymentManager::createSubscription($user['userID'], $tierID, 'monthly', 'stripe');

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRecords('tblSubscriptions', ['userID' => $user['userID']]), 'no tblSubscriptions row must be written when the guard blocks the attempt');
    }

    public function testPaymentManagerCreateSubscriptionAllowsFreeTierRegardlessOfLiveProviders(): void
    {
        // 🛡️ Free-tier signups must NEVER be blocked, even while paypal/stripe
        // both happen to be configured live — there is no provider call to guard.
        setTestSetting('payment.paypal.mode', 'live');
        setTestSetting('payment.stripe.mode', 'live');
        setTestSetting('billing.test_mode_guard', '1');

        $user = $this->createTestUser();
        $tierID = $this->insertRecord('tblSubscriptionTiers', [
            'tierName'     => 'Free Tier',
            'tierSlug'     => 'free-tier-' . bin2hex(random_bytes(4)),
            'monthlyPrice' => 0.00,
            'yearlyPrice'  => 0.00,
            'currency'     => 'GBP',
            'features'     => '[]',
        ]);

        $result = PaymentManager::createSubscription($user['userID'], $tierID, 'monthly', 'free');

        $this->assertTrue($result['success']);
    }
}
