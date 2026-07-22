<?php
/**
 * ============================================================================
 * 🧪 SubscriptionStateMachine Integration Tests (G-002 Stage S1 — transition())
 * ============================================================================
 *
 * DB-bound tests for SubscriptionStateMachine::transition() against the real
 * tblSubscriptions / tblActivityLog tables in signula_test. The pure
 * validation logic (canTransition()/assertTransition()) is covered by the
 * Unit suite (SubscriptionStateMachineTest) — this suite proves the
 * persistence + idempotency + timestamp-column behaviour that requires a
 * real row.
 *
 * @package    SIGNula\Tests\Integration\Payments
 * @version    2.9.0-beta
 * @see        web/private_html/payments/SubscriptionStateMachine.php
 * @see        .dev-team/specs/G-002.md §4.2/§4.3/§5
 */

namespace SIGNula\Tests\Integration\Payments;

use SIGNula\Tests\DatabaseTestCase;
use SubscriptionStateMachine;
use InvalidSubscriptionTransitionException;

// 📦 Dependency order: database.php (Database::query/fetchOne) ->
// ActivityLogger.php (transition() logs every real change) ->
// the exception type -> the class under test.
requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/payments/InvalidSubscriptionTransitionException.php');
requireSource('private_html/payments/SubscriptionStateMachine.php');

/**
 * 🔄 SubscriptionStateMachine::transition() test suite.
 */
class SubscriptionStateMachineTransitionTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblActivityLog',
        'tblSubscriptions',
        'tblSubscriptionTiers',
        'tblUsers',
    ];

    private int $tierID;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tierID = $this->createTestTier();
    }

    // ========================================================================
    // 🔧 FIXTURE HELPERS
    // ========================================================================

    private function createTestTier(): int
    {
        return $this->insertRecord('tblSubscriptionTiers', [
            'tierName'     => 'Test Tier',
            'tierSlug'     => 'test-tier-' . bin2hex(random_bytes(4)),
            'monthlyPrice' => 9.99,
            'yearlyPrice'  => 99.99,
            'currency'     => 'GBP',
            'features'     => '[]',
        ]);
    }

    private function createTestSubscription(string $status, array $overrides = []): int
    {
        $user = $this->createTestUser();

        $defaults = [
            'userID'             => $user['userID'],
            'tierID'             => $this->tierID,
            'subscriptionStatus' => $status,
            'billingCycle'       => 'monthly',
            'amount'             => 9.99,
            'currency'           => 'GBP',
            'paymentMethod'      => 'paypal',
            'startDate'          => date('Y-m-d'),
            'currentPeriodStart' => date('Y-m-d'),
            'currentPeriodEnd'   => date('Y-m-d', strtotime('+1 month')),
            'autoRenew'          => 1,
        ];

        return $this->insertRecord('tblSubscriptions', array_merge($defaults, $overrides));
    }

    private function countActivityLogRows(string $activityType): int
    {
        return $this->countRecords('tblActivityLog', ['activityType' => $activityType]);
    }

    // ========================================================================
    // ✅ PERSISTENCE + LOGGING
    // ========================================================================

    public function testTransitionPersistsALegalChangeAndLogsActivity(): void
    {
        $subscriptionID = $this->createTestSubscription('pending');

        $result = SubscriptionStateMachine::transition($subscriptionID, 'active', 'provider activated');

        $this->assertTrue($result);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $subscriptionID]);
        $this->assertSame('active', $row['subscriptionStatus']);
        $this->assertGreaterThan(0, $this->countActivityLogRows('subscription_state_transition'));
    }

    // ========================================================================
    // ♻️ IDEMPOTENCY
    // ========================================================================

    public function testTransitionReApplyingTheSameStatusIsANoOpSuccess(): void
    {
        $subscriptionID = $this->createTestSubscription('active');

        $first = SubscriptionStateMachine::transition($subscriptionID, 'active', 'first call');
        $countAfterFirst = $this->countActivityLogRows('subscription_state_transition');

        $second = SubscriptionStateMachine::transition($subscriptionID, 'active', 'second call');
        $countAfterSecond = $this->countActivityLogRows('subscription_state_transition');

        $this->assertTrue($first);
        $this->assertTrue($second);
        // 🔁 Re-applying an ALREADY-current status must not write a duplicate
        // audit entry — this is what makes it safe for a replayed webhook or
        // a double cron tick to call transition() without special-casing.
        $this->assertSame($countAfterFirst, $countAfterSecond, 'idempotent re-apply must not add a new ActivityLogger entry');
    }

    public function testTransitionNoOpEvenWhenTheCurrentStatusHasNoOutgoingEdges(): void
    {
        // 'expired' has zero outgoing edges in VALID_TRANSITIONS — but
        // re-applying 'expired' -> 'expired' must still succeed as a no-op
        // (idempotency is checked BEFORE the transition table).
        $subscriptionID = $this->createTestSubscription('expired');

        $result = SubscriptionStateMachine::transition($subscriptionID, 'expired', 'noop');

        $this->assertTrue($result);
    }

    // ========================================================================
    // 🚫 ILLEGAL TRANSITIONS
    // ========================================================================

    public function testTransitionThrowsOnAnIllegalTransitionAndLeavesStatusUnchanged(): void
    {
        $subscriptionID = $this->createTestSubscription('pending');

        try {
            SubscriptionStateMachine::transition($subscriptionID, 'grace', 'not allowed');
            $this->fail('Expected InvalidSubscriptionTransitionException was not thrown');
        } catch (InvalidSubscriptionTransitionException $e) {
            // ✅ expected
        }

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $subscriptionID]);
        $this->assertSame('pending', $row['subscriptionStatus'], 'status must be UNCHANGED after a rejected transition');
    }

    public function testTransitionThrowsWhenTheSubscriptionDoesNotExist(): void
    {
        $this->expectException(InvalidSubscriptionTransitionException::class);
        SubscriptionStateMachine::transition(999999999, 'active', 'no such subscription');
    }

    // ========================================================================
    // 🕐 TIMESTAMP-COLUMN BEHAVIOUR
    // ========================================================================

    public function testCancelledTransitionSetsCancelledAtReasonAndEndDate(): void
    {
        $subscriptionID = $this->createTestSubscription('active', ['endDate' => null]);

        SubscriptionStateMachine::transition($subscriptionID, 'cancelled', 'user requested cancellation');

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $subscriptionID]);
        $this->assertSame('cancelled', $row['subscriptionStatus']);
        $this->assertNotNull($row['cancelledAt']);
        $this->assertSame('user requested cancellation', $row['cancellationReason']);
        $this->assertSame(date('Y-m-d'), $row['endDate']);
    }

    public function testPausedTransitionSetsPausedAt(): void
    {
        $subscriptionID = $this->createTestSubscription('active');

        SubscriptionStateMachine::transition($subscriptionID, 'paused', 'user paused');

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $subscriptionID]);
        $this->assertSame('paused', $row['subscriptionStatus']);
        $this->assertNotNull($row['pausedAt']);
    }

    public function testExpiredTransitionSetsEndDateWhenPreviouslyNull(): void
    {
        $subscriptionID = $this->createTestSubscription('grace', ['endDate' => null]);

        SubscriptionStateMachine::transition($subscriptionID, 'expired', 'grace window lapsed');

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $subscriptionID]);
        $this->assertSame('expired', $row['subscriptionStatus']);
        $this->assertSame(date('Y-m-d'), $row['endDate']);
    }

    public function testExpiredTransitionDoesNotOverwriteAnExistingEndDate(): void
    {
        $subscriptionID = $this->createTestSubscription('grace', ['endDate' => '2099-01-01']);

        SubscriptionStateMachine::transition($subscriptionID, 'expired', 'grace window lapsed');

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $subscriptionID]);
        $this->assertSame('2099-01-01', $row['endDate'], 'COALESCE must preserve a pre-existing endDate, not overwrite it');
    }

    // ========================================================================
    // 🌊 FULL LIFECYCLE ROUND-TRIPS (G-002 spec §4.2)
    // ========================================================================

    public function testFullDunningRoundTripThroughGraceBackToActive(): void
    {
        $subscriptionID = $this->createTestSubscription('pending');

        SubscriptionStateMachine::transition($subscriptionID, 'trial', 'trial started');
        SubscriptionStateMachine::transition($subscriptionID, 'active', 'trial converted');
        SubscriptionStateMachine::transition($subscriptionID, 'past_due', 'renewal payment failed');
        SubscriptionStateMachine::transition($subscriptionID, 'grace', 'retry ladder exhausted');
        $result = SubscriptionStateMachine::transition($subscriptionID, 'active', 'reactivated mid-grace');

        $this->assertTrue($result);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $subscriptionID]);
        $this->assertSame('active', $row['subscriptionStatus']);
    }

    public function testFullDunningRoundTripThroughGraceToExpired(): void
    {
        $subscriptionID = $this->createTestSubscription('active');

        SubscriptionStateMachine::transition($subscriptionID, 'past_due', 'renewal payment failed');
        SubscriptionStateMachine::transition($subscriptionID, 'grace', 'retry ladder exhausted');
        SubscriptionStateMachine::transition($subscriptionID, 'expired', 'grace window lapsed');

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $subscriptionID]);
        $this->assertSame('expired', $row['subscriptionStatus']);
    }

    public function testCancelledThenReactivatedBeforePeriodEnd(): void
    {
        $subscriptionID = $this->createTestSubscription('active');

        SubscriptionStateMachine::transition($subscriptionID, 'cancelled', 'user cancelled');
        $result = SubscriptionStateMachine::transition($subscriptionID, 'active', 'reactivateSubscription() before period end');

        $this->assertTrue($result);
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $subscriptionID]);
        $this->assertSame('active', $row['subscriptionStatus']);
    }
}
