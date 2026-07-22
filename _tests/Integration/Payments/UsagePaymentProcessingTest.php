<?php
/**
 * ============================================================================
 * 🧪 Usage Payment Processing Regression Tests (F-B03 + F-B02 chargeViaProvider)
 * ============================================================================
 *
 * BLUE-TEAM regression coverage for two related billing-safety findings from
 * the G-002 red-team follow-up:
 *
 *   F-B03 (MEDIUM): PaymentManager::processUsagePayment() called
 *   self::recordPayment() with 7 positional arguments in the WRONG order
 *   against the real 5-param signature
 *   (int $userID, float $amount, string $paymentMethod, string
 *   $paymentProvider, array $options = []) — a bare string landed where the
 *   $options ARRAY parameter is declared, which PHP's weak typing cannot
 *   coerce. Every usage-billing charge attempt threw an uncaught TypeError
 *   (an \Error, not an \Exception) before ever reaching a payment provider.
 *
 *   F-B02 (MEDIUM, chargeViaProvider() half): PaymentManager::chargeViaProvider()
 *   unconditionally called {Stripe,PayPal}Provider::chargeStoredMethod(),
 *   which does not exist on either class, AND called
 *   CoinbaseProvider::createCharge() with an array where the real signature
 *   declares `int $userID` — both guaranteed to throw an uncaught \Error.
 *
 * FIX: processUsagePayment()'s recordPayment() call now matches the real
 * signature; chargeViaProvider() detects the missing chargeStoredMethod()
 * via method_exists() and fails closed with an informative message instead
 * of calling it, fixes the Coinbase argument order, and its try/catch now
 * catches \Throwable (not just \Exception) as defence-in-depth.
 *
 * No real/sandbox payment endpoint is ever reached by this suite: the
 * "no payment method on file" case never calls a provider at all, and the
 * "PayPal on file" case is refused by chargeViaProvider()'s method_exists()
 * guard before any HTTP call.
 *
 * @package    SIGNula\Tests\Integration\Payments
 * @version    2.9.0-beta
 * @see        web/private_html/payments/PaymentManager.php (processUsagePayment/chargeViaProvider/recordPayment)
 * @see        .dev-team/specs/G-002.md
 */

namespace SIGNula\Tests\Integration\Payments;

use SIGNula\Tests\DatabaseTestCase;
use PaymentManager;

// 📦 Dependency order — mirrors BillingModeGuardTest.php's established order.
// PayPalProvider.php pulls in PaymentManager.php/BillingMode.php itself, but
// PaymentManager.php is required explicitly first anyway since it is the
// primary class under test here.
requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/payments/BillingModeException.php');
requireSource('private_html/payments/BillingMode.php');
requireSource('private_html/payments/PaymentManager.php');
requireSource('private_html/payments/PayPalProvider.php');

/**
 * 💳 Usage-billing payment processing regression suite.
 */
class UsagePaymentProcessingTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblBillingAttempts',
        'tblPayments',
        'tblPaymentMethods',
        'tblSubscriptions',
        'tblSubscriptionTiers',
        'tblUsers',
    ];

    // ========================================================================
    // 🔧 FIXTURE HELPERS
    // ========================================================================

    private function createTestTier(array $overrides = []): int
    {
        $defaults = [
            'tierName'     => 'FB03 Tier ' . bin2hex(random_bytes(3)),
            'tierSlug'     => 'fb03-tier-' . bin2hex(random_bytes(4)),
            'monthlyPrice' => 9.99,
            'yearlyPrice'  => 99.99,
            'currency'     => 'GBP',
            'features'     => '[]',
            'isActive'     => 1,
        ];

        return $this->insertRecord('tblSubscriptionTiers', array_merge($defaults, $overrides));
    }

    private function createTestSubscription(int $tierID, int $userID, array $overrides = []): int
    {
        $defaults = [
            'userID'             => $userID,
            'tierID'             => $tierID,
            'subscriptionStatus' => 'active',
            'billingCycle'       => 'monthly',
            'amount'             => 9.99,
            'currency'           => 'GBP',
            'paymentMethod'      => 'paypal',
            'startDate'          => date('Y-m-d', strtotime('-1 month')),
            'currentPeriodStart' => date('Y-m-d', strtotime('-1 month')),
            'currentPeriodEnd'   => date('Y-m-d', strtotime('+1 day')),
            'nextBillingDate'    => date('Y-m-d', strtotime('+1 day')),
        ];

        return $this->insertRecord('tblSubscriptions', array_merge($defaults, $overrides));
    }

    // ========================================================================
    // 1️⃣ F-B03 — recordPayment() call no longer TypeErrors
    // ========================================================================

    /**
     * ✅ FIX VERIFIED: with NO stored payment method on file,
     * processUsagePayment() only exercises its recordPayment() call (the
     * exact call site F-B03 fixed) — never reaching chargeViaProvider() at
     * all. Before the fix this threw an uncaught TypeError on the
     * $options parameter; it must now return a clean, successful "recorded
     * as pending" result with a real tblPayments row.
     */
    public function testProcessUsagePaymentRecordsPendingPaymentWithoutPaymentMethod(): void
    {
        $user = $this->createTestUser();
        $tierID = $this->createTestTier();
        $subscriptionID = $this->createTestSubscription($tierID, $user['userID']);

        $result = PaymentManager::processUsagePayment(
            $user['userID'],
            $subscriptionID,
            4.50,
            'GBP',
            999001, // invoiceID — not FK-constrained, carried via metadata only
            999002  // summaryID — likewise
        );

        $this->assertTrue(
            $result['success'] ?? false,
            'F-B03 FIX: processUsagePayment() must succeed (no TypeError) even with no payment method on file: '
            . json_encode($result)
        );
        $this->assertGreaterThan(0, $result['paymentID'] ?? 0);

        // 🔎 The payment row genuinely landed correctly — subscriptionID and
        // currency were routed through $options as recordPayment() expects
        // (the exact mapping F-B03 fixed), not lost/misrouted.
        $row = $this->getRecord('tblPayments', ['paymentID' => (int) $result['paymentID']]);
        $this->assertNotNull($row);
        $this->assertSame($subscriptionID, (int) $row['subscriptionID']);
        $this->assertSame('GBP', $row['currency']);
        $this->assertSame('4.50', number_format((float) $row['amount'], 2, '.', ''));
        $this->assertSame('pending', $row['status']);

        $metadata = json_decode((string) $row['metadata'], true);
        $this->assertSame(999001, $metadata['invoiceID'] ?? null, 'invoiceID must be preserved via metadata');
        $this->assertSame(999002, $metadata['summaryID'] ?? null, 'summaryID must be preserved via metadata');
    }

    // ========================================================================
    // 2️⃣ F-B02 (chargeViaProvider half) — phantom chargeStoredMethod() fails
    //     closed instead of fataling
    // ========================================================================

    /**
     * ✅ FIX VERIFIED: WITH a stored PayPal payment method on file,
     * processUsagePayment() proceeds past recordPayment() into
     * chargeViaProvider() -> the now-guarded PayPal branch. Before the fix
     * this called PayPalProvider::chargeStoredMethod() (undefined) and
     * threw an uncaught \Error; it must now fail cleanly with the new
     * informative "unsupported" message, and the payment row must be
     * correctly marked 'failed' (not left dangling or crash the caller).
     */
    public function testProcessUsagePaymentFailsGracefullyWithStoredPaypalMethod(): void
    {
        $user = $this->createTestUser();
        $tierID = $this->createTestTier();
        $subscriptionID = $this->createTestSubscription($tierID, $user['userID']);

        $this->insertRecord('tblPaymentMethods', [
            'userID'        => $user['userID'],
            'paymentMethod' => 'paypal',
            'provider'      => 'paypal',
            'displayName'   => 'FB03 Test PayPal',
            'isDefault'     => 1,
            'isVerified'    => 1,
        ]);

        $result = PaymentManager::processUsagePayment(
            $user['userID'],
            $subscriptionID,
            4.50,
            'GBP',
            999003,
            999004
        );

        $this->assertFalse(
            $result['success'] ?? true,
            'F-B02 FIX: a stored PayPal method has no working self-charge path yet — must fail closed, not crash: '
            . json_encode($result)
        );
        $this->assertStringContainsString(
            'unsupported',
            $result['message'] ?? '',
            'F-B02 FIX: the failure message must carry the new method_exists()-guard wording, proving the '
            . 'phantom chargeStoredMethod() call was never attempted.'
        );

        $row = $this->getRecord('tblPayments', ['paymentID' => (int) $result['paymentID']]);
        $this->assertNotNull($row);
        $this->assertSame('failed', $row['status'], 'the payment record must be marked failed, not left pending/processing');
    }
}
