<?php
/**
 * ============================================================================
 * 🧪 PaymentManager::createSubscription() Provider-ID Persistence Tests (B-073)
 * ============================================================================
 *
 * DB-bound proof that `PaymentManager::createSubscription()` now persists the
 * PAYMENT PROVIDER's own subscription id into
 * `tblSubscriptions.paymentProviderSubscriptionID` when supplied via
 * `$options['paymentProviderSubscriptionID']`, and that this closes the real
 * gap B-073 was filed against: before this fix, the column was never written
 * by this method AT ALL, so the entire G-002 S3 webhook lifecycle
 * (`PayPalProvider::handleWebhookEvent()` / `StripeProvider::
 * handleWebhookEvent()`, both of which resolve the local subscription via
 * `WHERE paymentProviderSubscriptionID = ?`) could never match a subscription
 * created through this path — every BILLING.SUBSCRIPTION.ACTIVATED / renewal
 * / cancelled webhook for a real provider-managed subscription silently fell
 * through to the "no matching local subscription — ignored" branch.
 *
 * This suite proves the FULL round-trip end to end:
 *   create (with a provider id) -> the row is persisted -> a REAL
 *   BILLING.SUBSCRIPTION.ACTIVATED webhook event, run through the REAL
 *   PayPalProvider::handleWebhookEvent() (the exact code path
 *   web/public_html/webhooks/paypal.php calls), FINDS that subscription and
 *   transitions it to 'active'.
 *
 * It also pins a SEPARATE, unrelated bug found while re-deriving the
 * INSERT's mysqli bind type-string for this fix: the pre-existing string had
 * $amount (a float) and $currency (a string) bound with SWAPPED types ('s'
 * for amount, 'd' for currency), which — empirically confirmed against a
 * live MySQL connection — silently stored `currency = '0'` on EVERY
 * subscription ever created through this method. That is fixed alongside
 * B-073 since it lives in the exact same type-string literal.
 *
 * @package    SIGNula\Tests\Integration\Payments
 * @version    2.9.0-beta
 * @see        web/private_html/payments/PaymentManager.php::createSubscription()
 * @see        web/private_html/payments/PayPalProvider.php::handleWebhookEvent()
 */

namespace SIGNula\Tests\Integration\Payments;

use SIGNula\Tests\DatabaseTestCase;
use PaymentManager;
use PayPalProvider;

// 📦 Dependency order — mirrors WebhookSubscriptionLifecycleTest.php's
// established order. PayPalProvider.php pulls in PaymentManager.php and
// BillingMode.php itself via its own require_once (house convention).
requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/payments/InvalidSubscriptionTransitionException.php');
requireSource('private_html/payments/SubscriptionStateMachine.php');
requireSource('private_html/payments/InvoiceManager.php');
requireSource('private_html/payments/PaymentManager.php');
requireSource('private_html/payments/PayPalProvider.php');

/**
 * 🔗 createSubscription() provider-id persistence + webhook-match test suite.
 */
class CreateSubscriptionProviderIdPersistenceTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblBillingAttempts',
        'tblActivityLog',
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
            'tierName'     => 'Tier ' . bin2hex(random_bytes(3)),
            'tierSlug'     => 'tier-' . bin2hex(random_bytes(4)),
            'monthlyPrice' => 10.00,
            'yearlyPrice'  => 100.00,
            'currency'     => 'GBP',
            'features'     => '[]',
            'isActive'     => 1,
        ];

        return $this->insertRecord('tblSubscriptionTiers', array_merge($defaults, $overrides));
    }

    // ========================================================================
    // 💾 PERSISTENCE — createSubscription() writes paymentProviderSubscriptionID
    // ========================================================================

    public function testCreateSubscriptionPersistsTheSuppliedProviderSubscriptionId(): void
    {
        $tierID = $this->createTestTier();
        $user = $this->createTestUser();

        $result = PaymentManager::createSubscription(
            $user['userID'],
            $tierID,
            'monthly',
            'paypal',
            ['paymentProviderSubscriptionID' => 'I-ROUNDTRIP-001']
        );

        $this->assertTrue($result['success'], $result['message'] ?? 'createSubscription() must succeed');

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $result['subscriptionID']]);
        $this->assertNotNull($row);
        $this->assertSame(
            'I-ROUNDTRIP-001',
            $row['paymentProviderSubscriptionID'],
            'the provider subscription id supplied via $options must be persisted'
        );

        // 🩹 Pins the separate amount/currency bind-type swap fix (see class
        // doc-comment) — both must now be stored correctly, not just the new
        // paymentProviderSubscriptionID column.
        $this->assertSame('GBP', $row['currency'], 'currency must NOT be corrupted to "0" by the bind-type swap bug');
        $this->assertSame('10.00', $row['amount']);
    }

    public function testCreateSubscriptionAcceptsProviderSubscriptionIdAliasKey(): void
    {
        $tierID = $this->createTestTier();
        $user = $this->createTestUser();

        $result = PaymentManager::createSubscription(
            $user['userID'],
            $tierID,
            'monthly',
            'stripe',
            ['providerSubscriptionID' => 'sub_ALIAS001']
        );

        $this->assertTrue($result['success']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $result['subscriptionID']]);
        $this->assertSame('sub_ALIAS001', $row['paymentProviderSubscriptionID']);
    }

    public function testCreateSubscriptionWithoutAProviderIdLeavesTheColumnNull(): void
    {
        $tierID = $this->createTestTier(['monthlyPrice' => 0.00, 'yearlyPrice' => 0.00]);
        $user = $this->createTestUser();

        // 🆓 Free tier, no provider id supplied — must remain the pre-existing,
        // unchanged behaviour (nullable/additive — B-073 must not force a
        // value onto subscriptions that were never provider-managed).
        $result = PaymentManager::createSubscription($user['userID'], $tierID, 'monthly', 'free');

        $this->assertTrue($result['success']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $result['subscriptionID']]);
        $this->assertNull($row['paymentProviderSubscriptionID']);
    }

    // ========================================================================
    // 🔀 ROUND-TRIP — the S3 webhook lookup now FINDS the subscription
    // ========================================================================

    public function testProviderManagedSubscriptionIsFoundAndActivatedByTheRealActivatedWebhook(): void
    {
        $tierID = $this->createTestTier();
        $user = $this->createTestUser();

        // 1️⃣ CREATE — via the real production entry point, exactly as
        //    StripeProvider::handleCheckoutSessionCompleted() now does (and as
        //    a future PayPal Subscriptions-API signup flow would).
        $created = PaymentManager::createSubscription(
            $user['userID'],
            $tierID,
            'monthly',
            'paypal',
            ['paymentProviderSubscriptionID' => 'I-ROUNDTRIP-002']
        );
        $this->assertTrue($created['success']);

        // 💰 A paid tier with no trial starts 'pending' (awaiting provider
        // activation) — see createSubscription()'s own status logic.
        $before = $this->getRecord('tblSubscriptions', ['subscriptionID' => $created['subscriptionID']]);
        $this->assertSame('pending', $before['subscriptionStatus']);

        // 2️⃣ WEBHOOK — a REAL BILLING.SUBSCRIPTION.ACTIVATED event, run
        //    through the REAL PayPalProvider::handleWebhookEvent() (the exact
        //    method web/public_html/webhooks/paypal.php calls after its own
        //    signature-verify + dedup step — untouched by this fix).
        $event = [
            'id'         => 'WH-EVT-ROUNDTRIP-1',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource'   => ['id' => 'I-ROUNDTRIP-002', 'plan_id' => 'P-PLAN-ROUNDTRIP'],
        ];

        $webhookResult = PayPalProvider::handleWebhookEvent($event);

        $this->assertTrue($webhookResult['handled'], 'the webhook lookup must FIND the subscription, not fall through to "unknown"');
        $this->assertTrue($webhookResult['success']);

        // 3️⃣ VERIFY — the subscription actually transitioned.
        $after = $this->getRecord('tblSubscriptions', ['subscriptionID' => $created['subscriptionID']]);
        $this->assertSame('active', $after['subscriptionStatus']);

        // 📝 And the transition was audited against the RIGHT subscription.
        $this->assertSame(1, $this->countRecords('tblBillingAttempts', [
            'subscriptionID' => $created['subscriptionID'],
            'action'         => 'webhook_activated',
            'status'         => 'succeeded',
        ]));
    }

    public function testWithoutTheFixTheWebhookWouldHaveNoMatchingSubscription(): void
    {
        // 🔬 Negative control: an ACTIVATED event for a provider subscription
        // id that was NEVER persisted (simulating the pre-B-073 world, where
        // createSubscription() never wrote the column at all) must be safely
        // ignored — proving the "found" result above is meaningful and not a
        // false positive from some other lookup path.
        $event = [
            'id'         => 'WH-EVT-ORPHAN-1',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource'   => ['id' => 'I-NEVER-PERSISTED', 'plan_id' => 'P-PLAN-X'],
        ];

        $result = PayPalProvider::handleWebhookEvent($event);

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('No matching local subscription', $result['message'] ?? '');
    }
}
