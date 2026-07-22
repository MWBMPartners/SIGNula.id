<?php
/**
 * ============================================================================
 * 🧪 Webhook Subscription-Lifecycle Integration Tests (G-002 Stage S3)
 * ============================================================================
 *
 * End-to-end proof that PayPalProvider::handleWebhookEvent() /
 * StripeProvider::handleWebhookEvent() correctly map provider subscription-
 * lifecycle events onto tblSubscriptions.subscriptionStatus via the
 * cycle-40 SubscriptionStateMachine (G-002 spec §3.1/§7).
 *
 * Every test constructs a realistic, ALREADY-VERIFIED-AND-DEDUPED `$event`
 * array (exactly what web/public_html/webhooks/{paypal,stripe}.php hands to
 * `Provider::handleWebhookEvent()` AFTER its own signature-verify +
 * tblInboundWebhooks dedup step) and calls handleWebhookEvent() DIRECTLY.
 * Signature verification and the tblInboundWebhooks dedup are NEVER invoked
 * here — that plumbing is untouched by G-002 S3 and is not this suite's
 * concern (see PayPalProvider::verifyWebhookSignature() /
 * StripeProvider::verifyWebhookSignature() for that layer's own tests).
 * No real or fake HTTP transport is configured anywhere in this suite.
 *
 * @package    SIGNula\Tests\Integration\Payments
 * @version    2.9.0-beta
 * @see        web/private_html/payments/PayPalProvider.php::handleWebhookEvent()
 * @see        web/private_html/payments/StripeProvider.php::handleWebhookEvent()
 * @see        .dev-team/specs/G-002.md §3.1/§7
 */

namespace SIGNula\Tests\Integration\Payments;

use SIGNula\Tests\DatabaseTestCase;
use PayPalProvider;
use StripeProvider;

// 📦 Dependency order — mirrors BillingModeGuardTest.php's established order.
// PayPalProvider.php / StripeProvider.php each pull in PaymentManager.php and
// BillingMode.php (which pulls BillingModeException.php) themselves via their
// own require_once; PaymentManager.php itself only requires
// SubscriptionStateMachine.php/InvoiceManager.php/BillingScheduler.php
// JUST-IN-TIME (file_exists-guarded — house convention). The explicit
// pre-loads below just make first-use resolution deterministic in this suite.
requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/payments/InvalidSubscriptionTransitionException.php');
requireSource('private_html/payments/SubscriptionStateMachine.php');
requireSource('private_html/payments/InvoiceManager.php');
requireSource('private_html/payments/PaymentManager.php');
requireSource('private_html/payments/PayPalProvider.php');
requireSource('private_html/payments/StripeProvider.php');

/**
 * 🔀 PayPalProvider::handleWebhookEvent() / StripeProvider::handleWebhookEvent()
 * subscription-lifecycle test suite.
 */
class WebhookSubscriptionLifecycleTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblBillingAttempts',
        'tblBillingSchedule',
        'tblInvoices',
        'tblPayments',
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

    private function createTestSubscription(int $tierID, string $provider, string $providerSubID, array $overrides = []): array
    {
        $user = $this->createTestUser();

        $defaults = [
            'userID'                        => $user['userID'],
            'tierID'                        => $tierID,
            'subscriptionStatus'            => 'active',
            'billingCycle'                  => 'monthly',
            'amount'                        => 10.00,
            'currency'                      => 'GBP',
            'paymentMethod'                 => $provider,
            'paymentProviderSubscriptionID' => $providerSubID,
            'startDate'                     => date('Y-m-d', strtotime('-30 days')),
            'currentPeriodStart'            => date('Y-m-d', strtotime('-30 days')),
            'currentPeriodEnd'              => date('Y-m-d'),
            'autoRenew'                     => 1,
        ];

        $data = array_merge($defaults, $overrides);
        $data['subscriptionID'] = $this->insertRecord('tblSubscriptions', $data);
        $data['userID'] = $user['userID'];

        return $data;
    }

    // ========================================================================
    // 🟡 PayPal — BILLING.SUBSCRIPTION.* lifecycle events
    // ========================================================================

    public function testPayPalActivatedTransitionsPendingToActive(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'paypal', 'I-PAYPAL-001', ['subscriptionStatus' => 'pending']);

        $event = [
            'id'         => 'WH-EVT-ACTIVATE-1',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource'   => ['id' => 'I-PAYPAL-001', 'plan_id' => 'P-PLAN-1'],
        ];

        $result = PayPalProvider::handleWebhookEvent($event);

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success']);

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('active', $row['subscriptionStatus']);
    }

    public function testPayPalPaymentFailedMarksPastDue(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'paypal', 'I-PAYPAL-002', ['subscriptionStatus' => 'active']);

        $event = [
            'id'         => 'WH-EVT-FAIL-1',
            'event_type' => 'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
            'resource'   => ['id' => 'I-PAYPAL-002'],
        ];

        $result = PayPalProvider::handleWebhookEvent($event);

        $this->assertTrue($result['success']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('past_due', $row['subscriptionStatus']);
    }

    public function testPayPalSuspendedEntersGraceFromPastDue(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'paypal', 'I-PAYPAL-003', ['subscriptionStatus' => 'past_due']);

        $event = [
            'id'         => 'WH-EVT-SUSPEND-1',
            'event_type' => 'BILLING.SUBSCRIPTION.SUSPENDED',
            'resource'   => ['id' => 'I-PAYPAL-003'],
        ];

        $result = PayPalProvider::handleWebhookEvent($event);

        $this->assertTrue($result['success']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('grace', $row['subscriptionStatus'], 'spec §3.1/§7: SUSPENDED -> grace, not paused');
    }

    public function testPayPalSuspendedSkippedWhenCurrentlyActive(): void
    {
        // 'active' -> 'grace' is NOT a legal SubscriptionStateMachine edge —
        // a SUSPENDED event that skips past_due must be safely ignored.
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'paypal', 'I-PAYPAL-004', ['subscriptionStatus' => 'active']);

        $event = [
            'id'         => 'WH-EVT-SUSPEND-2',
            'event_type' => 'BILLING.SUBSCRIPTION.SUSPENDED',
            'resource'   => ['id' => 'I-PAYPAL-004'],
        ];

        $result = PayPalProvider::handleWebhookEvent($event);

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success'], 'a safely-ignored/skipped event must still be reported handled+success — never an error');
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('active', $row['subscriptionStatus'], 'status must be unchanged — no illegal force-apply');
    }

    public function testPayPalCancelledSetsCancelled(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'paypal', 'I-PAYPAL-005', ['subscriptionStatus' => 'active']);

        $event = [
            'id'         => 'WH-EVT-CANCEL-1',
            'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED',
            'resource'   => ['id' => 'I-PAYPAL-005'],
        ];

        $result = PayPalProvider::handleWebhookEvent($event);

        $this->assertTrue($result['success']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('cancelled', $row['subscriptionStatus']);
    }

    public function testPayPalExpiredSetsExpiredFromGrace(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'paypal', 'I-PAYPAL-006', ['subscriptionStatus' => 'grace']);

        $event = [
            'id'         => 'WH-EVT-EXPIRE-1',
            'event_type' => 'BILLING.SUBSCRIPTION.EXPIRED',
            'resource'   => ['id' => 'I-PAYPAL-006'],
        ];

        $result = PayPalProvider::handleWebhookEvent($event);

        $this->assertTrue($result['success']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('expired', $row['subscriptionStatus']);
    }

    // ========================================================================
    // 🟡 PayPal — PAYMENT.SALE.COMPLETED — the RENEWAL path
    // ========================================================================

    public function testPayPalRecurringSaleCompletedRenewsAdvancesPeriodAndIssuesInvoice(): void
    {
        $tierID = $this->createTestTier(['monthlyPrice' => 10.00]);
        $sub = $this->createTestSubscription($tierID, 'paypal', 'I-PAYPAL-007', [
            'subscriptionStatus' => 'active',
            'amount'             => 10.00,
            'currentPeriodStart' => '2026-06-10',
            'currentPeriodEnd'   => '2026-07-10',
        ]);

        $event = [
            'id'         => 'WH-EVT-SALE-1',
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource'   => [
                'id'                   => 'SALE-TXN-001',
                'billing_agreement_id' => 'I-PAYPAL-007',
                'amount'               => ['total' => '10.00', 'currency' => 'GBP'],
            ],
        ];

        $result = PayPalProvider::handleWebhookEvent($event);

        $this->assertTrue($result['success']);

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('active', $row['subscriptionStatus']);
        $this->assertSame('2026-07-10', $row['currentPeriodStart']);
        $this->assertSame('2026-08-10', $row['currentPeriodEnd']);

        $this->assertSame(1, $this->countRecords('tblPayments', ['subscriptionID' => $sub['subscriptionID'], 'status' => 'completed']));
        $this->assertSame(1, $this->countRecords('tblInvoices', ['subscriptionID' => $sub['subscriptionID']]));
    }

    public function testPayPalSaleWithoutBillingAgreementIsIgnoredNotErrored(): void
    {
        // A one-off order capture has no billing_agreement_id — PAYMENT.SALE.COMPLETED
        // without one is not a subscription renewal.
        $event = [
            'id'         => 'WH-EVT-SALE-ONEOFF',
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource'   => ['id' => 'SALE-ONEOFF-1', 'amount' => ['total' => '5.00', 'currency' => 'GBP']],
        ];

        $result = PayPalProvider::handleWebhookEvent($event);

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success']);
    }

    // ========================================================================
    // 🩹 B-072 REGRESSION PROOF — the phantom externalSubscriptionID column
    // ========================================================================

    public function testB072FixSubscriptionIsNowFoundByPaymentProviderSubscriptionID(): void
    {
        // Before the B-072 fix, EVERY BILLING.SUBSCRIPTION.* lookup used
        // `WHERE externalSubscriptionID = ?` — a column that has never
        // existed on tblSubscriptions — so this call would have thrown an
        // "Unknown column" mysqli_sql_exception. It must now cleanly FIND
        // the row (by the REAL column, paymentProviderSubscriptionID) and
        // update it.
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'paypal', 'I-PAYPAL-B072', ['subscriptionStatus' => 'active']);

        $event = [
            'id'         => 'WH-EVT-B072-1',
            'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED',
            'resource'   => ['id' => 'I-PAYPAL-B072'],
        ];

        $result = PayPalProvider::handleWebhookEvent($event);

        $this->assertTrue($result['success'], 'handleWebhookEvent() must not error on the (fixed) column lookup');
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('cancelled', $row['subscriptionStatus'], 'the local subscription MUST be found and updated by paymentProviderSubscriptionID');
    }

    // ========================================================================
    // ❓ UNKNOWN / FOREIGN provider-subscription-id — safely ignored
    // ========================================================================

    public function testUnknownPayPalSubscriptionIdIsSafelyIgnored(): void
    {
        $event = [
            'id'         => 'WH-EVT-UNKNOWN-1',
            'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED',
            'resource'   => ['id' => 'I-PAYPAL-DOES-NOT-EXIST'],
        ];

        $result = PayPalProvider::handleWebhookEvent($event);

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success'], 'an unknown subscription id must be a safe no-op, not an error');
        $this->assertSame(0, $this->countRecords('tblSubscriptions'), 'no subscription row exists at all — nothing should have been mutated/created');
    }

    // ========================================================================
    // ♻️ REPLAY IDEMPOTENCY — via the full handleWebhookEvent() path
    // ========================================================================

    public function testReplayingTheSamePayPalEventIsIdempotent(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'paypal', 'I-PAYPAL-008', ['subscriptionStatus' => 'pending']);

        $event = [
            'id'         => 'WH-EVT-REPLAY-1',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource'   => ['id' => 'I-PAYPAL-008'],
        ];

        $first = PayPalProvider::handleWebhookEvent($event);
        $second = PayPalProvider::handleWebhookEvent($event);

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('active', $row['subscriptionStatus']);

        $this->assertSame(1, $this->countRecords('tblBillingAttempts', [
            'subscriptionID' => $sub['subscriptionID'], 'action' => 'webhook_activated', 'status' => 'succeeded',
        ]), 'exactly ONE net effect across the replay');
    }

    public function testReplayingTheSamePayPalRenewalEventDoesNotDoubleInvoice(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'paypal', 'I-PAYPAL-009', [
            'currentPeriodStart' => '2026-06-10',
            'currentPeriodEnd'   => '2026-07-10',
        ]);

        $event = [
            'id'         => 'WH-EVT-REPLAY-RENEWAL-1',
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource'   => [
                'id' => 'SALE-TXN-REPLAY-1', 'billing_agreement_id' => 'I-PAYPAL-009',
                'amount' => ['total' => '10.00', 'currency' => 'GBP'],
            ],
        ];

        PayPalProvider::handleWebhookEvent($event);
        PayPalProvider::handleWebhookEvent($event);

        $this->assertSame(1, $this->countRecords('tblPayments', ['subscriptionID' => $sub['subscriptionID']]));
        $this->assertSame(1, $this->countRecords('tblInvoices', ['subscriptionID' => $sub['subscriptionID']]));

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('2026-07-10', $row['currentPeriodStart'], 'period must not have advanced twice');
        $this->assertSame('2026-08-10', $row['currentPeriodEnd']);
    }

    // ========================================================================
    // 🟣 Stripe — customer.subscription.updated / .deleted / invoice.*
    // ========================================================================

    public function testStripeSubscriptionUpdatedMapsActiveStatus(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'stripe', 'sub_stripe_001', ['subscriptionStatus' => 'trial']);

        $event = [
            'id'   => 'evt_status_1',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_stripe_001', 'status' => 'active']],
        ];

        $result = StripeProvider::handleWebhookEvent($event);

        $this->assertTrue($result['success']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('active', $row['subscriptionStatus']);
    }

    public function testStripeSubscriptionUpdatedMapsPastDueForUnpaidStatus(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'stripe', 'sub_stripe_002', ['subscriptionStatus' => 'active']);

        $event = [
            'id'   => 'evt_status_2',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_stripe_002', 'status' => 'past_due']],
        ];

        $result = StripeProvider::handleWebhookEvent($event);

        $this->assertTrue($result['success']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('past_due', $row['subscriptionStatus']);
    }

    public function testStripeSubscriptionDeletedCancels(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'stripe', 'sub_stripe_003', ['subscriptionStatus' => 'active']);

        $event = [
            'id'   => 'evt_delete_1',
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => 'sub_stripe_003']],
        ];

        $result = StripeProvider::handleWebhookEvent($event);

        $this->assertTrue($result['success']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('cancelled', $row['subscriptionStatus']);
    }

    public function testStripeInvoicePaymentFailedMarksPastDue(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'stripe', 'sub_stripe_004', ['subscriptionStatus' => 'active']);

        $event = [
            'id'   => 'evt_invfail_1',
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['id' => 'in_fail_1', 'subscription' => 'sub_stripe_004']],
        ];

        $result = StripeProvider::handleWebhookEvent($event);

        $this->assertTrue($result['success']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('past_due', $row['subscriptionStatus']);
    }

    public function testStripeUnknownSubscriptionIsSafelyIgnored(): void
    {
        $event = [
            'id'   => 'evt_unknown_1',
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => 'sub_does_not_exist']],
        ];

        $result = StripeProvider::handleWebhookEvent($event);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->countRecords('tblSubscriptions'));
    }

    // ========================================================================
    // 🟣 Stripe — invoice.paid / invoice.payment_succeeded — the RENEWAL path
    // ========================================================================

    public function testStripeInvoicePaidRenewsAdvancesPeriodAndIssuesInvoice(): void
    {
        $tierID = $this->createTestTier(['monthlyPrice' => 15.00]);
        $sub = $this->createTestSubscription($tierID, 'stripe', 'sub_stripe_005', [
            'amount'             => 15.00,
            'currentPeriodStart' => '2026-06-15',
            'currentPeriodEnd'   => '2026-07-15',
        ]);

        $event = [
            'id'   => 'evt_invpaid_1',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_renewal_1', 'subscription' => 'sub_stripe_005',
                'amount_paid' => 1500, 'currency' => 'gbp', 'customer_email' => 'x@example.com',
            ]],
        ];

        $result = StripeProvider::handleWebhookEvent($event);

        $this->assertTrue($result['success']);

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('active', $row['subscriptionStatus']);
        $this->assertSame('2026-07-15', $row['currentPeriodStart']);
        $this->assertSame('2026-08-15', $row['currentPeriodEnd']);

        $this->assertSame(1, $this->countRecords('tblPayments', ['subscriptionID' => $sub['subscriptionID'], 'status' => 'completed']));
        $this->assertSame(1, $this->countRecords('tblInvoices', ['subscriptionID' => $sub['subscriptionID']]));

        $payment = $this->getRecord('tblPayments', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('15.00', $payment['amount'], 'amount_paid (cents) must be correctly converted to major units');
    }

    public function testStripeInvoicePaymentSucceededAliasAlsoRenews(): void
    {
        $tierID = $this->createTestTier(['monthlyPrice' => 10.00]);
        $sub = $this->createTestSubscription($tierID, 'stripe', 'sub_stripe_006', [
            'currentPeriodStart' => '2026-06-01',
            'currentPeriodEnd'   => '2026-07-01',
        ]);

        $event = [
            'id'   => 'evt_invsucceeded_1',
            'type' => 'invoice.payment_succeeded',
            'data' => ['object' => ['id' => 'in_renewal_2', 'subscription' => 'sub_stripe_006', 'amount_paid' => 1000, 'currency' => 'gbp']],
        ];

        $result = StripeProvider::handleWebhookEvent($event);

        $this->assertTrue($result['success']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('2026-07-01', $row['currentPeriodStart']);
        $this->assertSame('2026-08-01', $row['currentPeriodEnd']);
    }

    public function testReplayingTheSameStripeInvoicePaidEventDoesNotDoubleInvoice(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, 'stripe', 'sub_stripe_007', [
            'currentPeriodStart' => '2026-06-01',
            'currentPeriodEnd'   => '2026-07-01',
        ]);

        $event = [
            'id'   => 'evt_invpaid_replay_1',
            'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_replay_1', 'subscription' => 'sub_stripe_007', 'amount_paid' => 1000, 'currency' => 'gbp']],
        ];

        StripeProvider::handleWebhookEvent($event);
        StripeProvider::handleWebhookEvent($event);

        $this->assertSame(1, $this->countRecords('tblPayments', ['subscriptionID' => $sub['subscriptionID']]));
        $this->assertSame(1, $this->countRecords('tblInvoices', ['subscriptionID' => $sub['subscriptionID']]));

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('2026-07-01', $row['currentPeriodStart']);
        $this->assertSame('2026-08-01', $row['currentPeriodEnd']);
    }
}
