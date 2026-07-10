<?php
/**
 * ============================================================================
 * 🧪 PaymentManager Webhook-Lifecycle Integration Tests (G-002 Stage S3)
 * ============================================================================
 *
 * DB-bound proof of the engine behind PayPalProvider::handleWebhookEvent() /
 * StripeProvider::handleWebhookEvent() — PaymentManager::
 * recordSubscriptionRenewal(), ::applyPendingTierChange(), and
 * ::applyWebhookTransition() — exercised DIRECTLY (bypassing the provider
 * classes entirely) so failures here point straight at the shared engine
 * rather than at event-payload parsing. The provider-class end-to-end proof
 * (constructing real PayPal/Stripe `$event` payloads and calling
 * handleWebhookEvent()) lives in WebhookSubscriptionLifecycleTest.php.
 *
 * No real or fake HTTP transport is configured anywhere in this suite —
 * these methods are pure DB + state-machine orchestration; they never call
 * a provider network endpoint.
 *
 * @package    SIGNula\Tests\Integration\Payments
 * @version    2.9.0-beta
 * @see        web/private_html/payments/PaymentManager.php (recordSubscriptionRenewal/applyPendingTierChange/applyWebhookTransition)
 * @see        .dev-team/specs/G-002.md §3.1/§4.3/§5.1/§5.3/§7
 */

namespace SIGNula\Tests\Integration\Payments;

use SIGNula\Tests\DatabaseTestCase;
use PaymentManager;

// 📦 Dependency order — mirrors PaymentManagerTierChangeTest.php's established
// order. PaymentManager.php itself only requires SubscriptionStateMachine.php/
// BillingMode.php/InvoiceManager.php/BillingScheduler.php JUST-IN-TIME (inside
// the relevant method, file_exists-guarded — house convention); the explicit
// pre-loads below just make first-use resolution deterministic in this suite.
requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/payments/InvalidSubscriptionTransitionException.php');
requireSource('private_html/payments/SubscriptionStateMachine.php');
requireSource('private_html/payments/InvoiceManager.php');
requireSource('private_html/payments/PaymentManager.php');

/**
 * 🔄 PaymentManager::recordSubscriptionRenewal() / ::applyPendingTierChange()
 * / ::applyWebhookTransition() test suite.
 */
class PaymentManagerWebhookLifecycleTest extends DatabaseTestCase
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

    private function createTestSubscription(int $tierID, array $overrides = []): array
    {
        $user = $this->createTestUser();

        $defaults = [
            'userID'                        => $user['userID'],
            'tierID'                        => $tierID,
            'subscriptionStatus'            => 'active',
            'billingCycle'                  => 'monthly',
            'amount'                        => 10.00,
            'currency'                      => 'GBP',
            'paymentMethod'                 => 'paypal',
            'paymentProviderSubscriptionID' => 'PROV-SUB-' . bin2hex(random_bytes(4)),
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
    // 🔄 recordSubscriptionRenewal() — the RENEWAL path
    // ========================================================================

    public function testRenewalAdvancesPeriodIssuesInvoiceAndKeepsStatusActive(): void
    {
        $tierID = $this->createTestTier(['monthlyPrice' => 10.00]);
        $sub = $this->createTestSubscription($tierID, [
            'subscriptionStatus' => 'active',
            'amount'             => 10.00,
            'currentPeriodStart' => '2026-06-01',
            'currentPeriodEnd'   => '2026-07-01',
        ]);

        $result = PaymentManager::recordSubscriptionRenewal(
            $sub['subscriptionID'],
            'paypal',
            10.00,
            'GBP',
            'SALE-TXN-1',
            'paypal:evt-renewal-1'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('2026-07-01', $result['currentPeriodStart'], 'new start MUST be the OLD end, not "today"');
        $this->assertSame('2026-08-01', $result['currentPeriodEnd'], 'new end MUST be old end + 1 cycle');

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('active', $row['subscriptionStatus']);
        $this->assertSame('2026-07-01', $row['currentPeriodStart']);
        $this->assertSame('2026-08-01', $row['currentPeriodEnd']);
        $this->assertSame('2026-08-01', $row['nextBillingDate']);

        $this->assertSame(1, $this->countRecords('tblPayments', ['subscriptionID' => $sub['subscriptionID'], 'status' => 'completed']));
        $this->assertSame(1, $this->countRecords('tblInvoices', ['subscriptionID' => $sub['subscriptionID']]));
        $this->assertSame(1, $this->countRecords('tblBillingAttempts', [
            'subscriptionID' => $sub['subscriptionID'], 'action' => 'webhook_renewal', 'status' => 'succeeded',
        ]));
    }

    public function testRenewalHandlesYearlyBillingCycle(): void
    {
        $tierID = $this->createTestTier(['yearlyPrice' => 100.00]);
        $sub = $this->createTestSubscription($tierID, [
            'billingCycle'       => 'yearly',
            'amount'             => 100.00,
            'currentPeriodStart' => '2025-07-10',
            'currentPeriodEnd'   => '2026-07-10',
        ]);

        $result = PaymentManager::recordSubscriptionRenewal(
            $sub['subscriptionID'], 'stripe', 100.00, 'GBP', 'in_test123', 'stripe:evt-yearly-1'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('2026-07-10', $result['currentPeriodStart']);
        $this->assertSame('2027-07-10', $result['currentPeriodEnd']);
    }

    public function testRenewalMovesPastDueBackToActive(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => 'past_due']);

        $result = PaymentManager::recordSubscriptionRenewal(
            $sub['subscriptionID'], 'paypal', 10.00, 'GBP', 'SALE-TXN-2', 'paypal:evt-renewal-2'
        );

        $this->assertTrue($result['success']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('active', $row['subscriptionStatus']);
    }

    public function testRenewalMovesGraceBackToActive(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => 'grace']);

        $result = PaymentManager::recordSubscriptionRenewal(
            $sub['subscriptionID'], 'paypal', 10.00, 'GBP', 'SALE-TXN-3', 'paypal:evt-renewal-3'
        );

        $this->assertTrue($result['success']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('active', $row['subscriptionStatus'], 'grace -> active must resolve on a successful renewal, even though completePayment()\'s own raw UPDATE only covers pending/past_due');
    }

    public function testRenewalIsIdempotentOnReplayNoDoubleInvoiceOrPeriodAdvance(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, [
            'currentPeriodStart' => '2026-06-01',
            'currentPeriodEnd'   => '2026-07-01',
        ]);

        $first = PaymentManager::recordSubscriptionRenewal($sub['subscriptionID'], 'paypal', 10.00, 'GBP', 'SALE-TXN-4', 'paypal:evt-replay-1');
        $second = PaymentManager::recordSubscriptionRenewal($sub['subscriptionID'], 'paypal', 10.00, 'GBP', 'SALE-TXN-4', 'paypal:evt-replay-1');

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertTrue($second['idempotentReplay'] ?? false);

        $this->assertSame(1, $this->countRecords('tblPayments', ['subscriptionID' => $sub['subscriptionID']]), 'exactly ONE payment after a replayed renewal');
        $this->assertSame(1, $this->countRecords('tblInvoices', ['subscriptionID' => $sub['subscriptionID']]), 'exactly ONE invoice after a replayed renewal');
        $this->assertSame(1, $this->countRecords('tblBillingAttempts', [
            'subscriptionID' => $sub['subscriptionID'], 'action' => 'webhook_renewal', 'status' => 'succeeded',
        ]));

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('2026-07-01', $row['currentPeriodStart'], 'the period must NOT have advanced a second time');
        $this->assertSame('2026-08-01', $row['currentPeriodEnd']);
    }

    public function testRenewalForAnExpiredTerminalSubscriptionIsRefusedWithZeroMutation(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, [
            'subscriptionStatus' => 'expired',
            'currentPeriodStart' => '2026-06-01',
            'currentPeriodEnd'   => '2026-07-01',
        ]);

        $result = PaymentManager::recordSubscriptionRenewal(
            $sub['subscriptionID'], 'paypal', 10.00, 'GBP', 'SALE-TXN-5', 'paypal:evt-terminal-1'
        );

        $this->assertFalse($result['success'], 'a renewal for an already-terminal (expired) subscription must be refused');

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('expired', $row['subscriptionStatus']);
        $this->assertSame('2026-06-01', $row['currentPeriodStart'], 'ZERO mutation — the period must be untouched');
        $this->assertSame('2026-07-01', $row['currentPeriodEnd']);

        $this->assertSame(0, $this->countRecords('tblPayments', ['subscriptionID' => $sub['subscriptionID']]));
        $this->assertSame(0, $this->countRecords('tblInvoices', ['subscriptionID' => $sub['subscriptionID']]));
        $this->assertSame(1, $this->countRecords('tblBillingAttempts', [
            'subscriptionID' => $sub['subscriptionID'], 'action' => 'webhook_renewal', 'status' => 'failed',
        ]), 'the refusal must still be auditable');
    }

    public function testRenewalReturnsFailureForAnUnknownSubscriptionID(): void
    {
        $result = PaymentManager::recordSubscriptionRenewal(999999999, 'paypal', 10.00, 'GBP', 'SALE-TXN-6', 'paypal:evt-unknown-1');

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRecords('tblBillingAttempts', ['idempotencyKey' => 'paypal:evt-unknown-1']));
    }

    public function testRenewalAppliesAPendingEndOfPeriodTierChange(): void
    {
        $oldTierID = $this->createTestTier(['monthlyPrice' => 10.00]);
        $newTierID = $this->createTestTier(['monthlyPrice' => 25.00]);

        $pendingMetadata = json_encode([
            'pendingTierChange' => [
                'newTierID'       => $newTierID,
                'newBillingCycle' => 'monthly',
                'newAmount'       => '25.00',
                'effectiveAt'     => '2026-07-01',
                'idempotencyKey'  => 'tier-change-key-1',
                'requestedAt'     => '2026-06-15 10:00:00',
            ],
        ]);

        $sub = $this->createTestSubscription($oldTierID, [
            'amount'             => 10.00,
            'currentPeriodStart' => '2026-06-01',
            'currentPeriodEnd'   => '2026-07-01',
            'metadata'           => $pendingMetadata,
        ]);

        $result = PaymentManager::recordSubscriptionRenewal(
            $sub['subscriptionID'], 'paypal', 10.00, 'GBP', 'SALE-TXN-7', 'paypal:evt-tierchange-1'
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['tierChangeApplied']);

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame((string)$newTierID, (string)$row['tierID'], 'the deferred tier swap must land at renewal');
        $this->assertSame('25.00', $row['amount']);

        $metadata = json_decode((string)$row['metadata'], true);
        $this->assertIsArray($metadata);
        $this->assertArrayNotHasKey('pendingTierChange', $metadata, 'the marker must be cleared once applied');
    }

    // ========================================================================
    // 🔁 applyPendingTierChange() — direct, isolated proof
    // ========================================================================

    public function testApplyPendingTierChangeIsANoOpWhenNoneIsPending(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID);

        $result = PaymentManager::applyPendingTierChange($sub['subscriptionID']);

        $this->assertFalse($result['applied']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame((string)$tierID, (string)$row['tierID']);
    }

    public function testApplyPendingTierChangeIsANoOpOnASecondCall(): void
    {
        $oldTierID = $this->createTestTier(['monthlyPrice' => 10.00]);
        $newTierID = $this->createTestTier(['monthlyPrice' => 25.00]);

        $sub = $this->createTestSubscription($oldTierID, [
            'metadata' => json_encode(['pendingTierChange' => [
                'newTierID' => $newTierID, 'newBillingCycle' => 'monthly', 'newAmount' => '25.00',
                'effectiveAt' => '2026-07-01', 'idempotencyKey' => 'k', 'requestedAt' => '2026-06-15 10:00:00',
            ]]),
        ]);

        $first = PaymentManager::applyPendingTierChange($sub['subscriptionID']);
        $second = PaymentManager::applyPendingTierChange($sub['subscriptionID']);

        $this->assertTrue($first['applied']);
        $this->assertFalse($second['applied'], 'the marker was already cleared by the first call');
    }

    // ========================================================================
    // 🔀 applyWebhookTransition() — the shared PURE-STATE path
    // ========================================================================

    public function testAppliesALegalTransitionAndAuditsSucceeded(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => 'pending']);

        $result = PaymentManager::applyWebhookTransition(
            $sub['subscriptionID'], 'active', 'paypal', 'webhook_activated', 'PayPal ACTIVATED', 'paypal:evt-activate-1'
        );

        $this->assertTrue($result['transitioned']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('active', $row['subscriptionStatus']);

        $this->assertSame(1, $this->countRecords('tblBillingAttempts', [
            'subscriptionID' => $sub['subscriptionID'], 'action' => 'webhook_activated', 'status' => 'succeeded',
        ]));
    }

    public function testSuspendedToGraceIsLegalOnlyFromPastDue(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => 'past_due']);

        $result = PaymentManager::applyWebhookTransition(
            $sub['subscriptionID'], 'grace', 'paypal', 'webhook_grace', 'PayPal SUSPENDED', 'paypal:evt-grace-1'
        );

        $this->assertTrue($result['transitioned']);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('grace', $row['subscriptionStatus']);
    }

    public function testAnIllegalTransitionIsSkippedNotForcedAndAudited(): void
    {
        // ⚠️ SubscriptionStateMachine deliberately does NOT allow
        // 'active' -> 'grace' directly (see SubscriptionStateMachineTest::
        // testCanTransitionRejectsIllegalEdges). A PayPal SUSPENDED webhook
        // that skips past_due must be safely ignored, not force-applied.
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => 'active']);

        $result = PaymentManager::applyWebhookTransition(
            $sub['subscriptionID'], 'grace', 'paypal', 'webhook_grace', 'PayPal SUSPENDED', 'paypal:evt-grace-2'
        );

        $this->assertFalse($result['transitioned']);
        $this->assertTrue($result['skipped'] ?? false);

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('active', $row['subscriptionStatus'], 'status must be UNCHANGED — no illegal force-apply');

        $this->assertSame(1, $this->countRecords('tblBillingAttempts', [
            'subscriptionID' => $sub['subscriptionID'], 'action' => 'webhook_grace', 'status' => 'skipped',
        ]), 'the skip must still be auditable');
    }

    public function testReplayOfTheSameIdempotencyKeyIsANoOp(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => 'pending']);

        $first = PaymentManager::applyWebhookTransition($sub['subscriptionID'], 'active', 'paypal', 'webhook_activated', 'r', 'paypal:evt-dup-1');
        $second = PaymentManager::applyWebhookTransition($sub['subscriptionID'], 'active', 'paypal', 'webhook_activated', 'r', 'paypal:evt-dup-1');

        $this->assertTrue($first['transitioned']);
        $this->assertTrue($second['transitioned']);
        $this->assertTrue($second['idempotentReplay'] ?? false);

        $this->assertSame(1, $this->countRecords('tblBillingAttempts', [
            'subscriptionID' => $sub['subscriptionID'], 'action' => 'webhook_activated', 'status' => 'succeeded',
        ]), 'exactly ONE succeeded audit row after a replay');
    }

    public function testReturnsSkippedForAnUnknownSubscriptionWithoutCrashing(): void
    {
        $result = PaymentManager::applyWebhookTransition(999999999, 'active', 'paypal', 'webhook_activated', 'r', 'paypal:evt-unknown-2');

        $this->assertFalse($result['transitioned']);
        $this->assertTrue($result['skipped'] ?? false);
    }

    public function testCancelledIsReachableFromEveryNonTerminalStatus(): void
    {
        $tierID = $this->createTestTier();

        foreach (['pending', 'trial', 'active', 'past_due', 'grace', 'paused'] as $i => $status) {
            $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => $status]);

            $result = PaymentManager::applyWebhookTransition(
                $sub['subscriptionID'], 'cancelled', 'paypal', 'webhook_cancelled', 'PayPal CANCELLED', 'paypal:evt-cancel-' . $i
            );

            $this->assertTrue($result['transitioned'], "'{$status}' -> 'cancelled' must be legal");
            $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
            $this->assertSame('cancelled', $row['subscriptionStatus']);
        }
    }
}
