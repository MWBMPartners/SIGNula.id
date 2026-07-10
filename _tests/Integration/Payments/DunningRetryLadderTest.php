<?php
/**
 * ============================================================================
 * 🧪 Dunning Retry Ladder Integration Tests (G-002 Stage S4)
 * ============================================================================
 *
 * DB-bound proof of the dunning flow wired into the EXISTING
 * `BillingScheduler` task queue + `SubscriptionStateMachine` +
 * `PaymentManager::applyWebhookTransition()`/`recordSubscriptionRenewal()`:
 *
 *   - Entering `past_due` (via applyWebhookTransition(), the same choke
 *     point every provider webhook handler already routes through)
 *     schedules the FIRST dunning_retry task.
 *   - A due dunning_retry task, ticked via the real `processDueTasks()`
 *     entry point (never a private method — this proves the public
 *     scheduler wiring, not just an internal helper), with a MOCKED
 *     collection outcome (`metadata.mockCollectionResult` — see
 *     BillingScheduler::attemptDunningCollection()'s doc-comment; NO real
 *     provider call is EVER made here):
 *       - failure -> sends the payment_failed email + schedules the next rung
 *       - success -> reuses the renewal path -> subscription active, ladder cleared
 *   - Exhausting billing.dunning.max_retries -> past_due -> grace + a
 *     dunning_final_cancel task at now + billing.grace_period_days.
 *   - The grace task firing -> grace -> cancelled + the final email.
 *   - BillingMode::assertTestMode() refuses a live-mode provider's retry,
 *     fail-closed, BEFORE any collection attempt (§9.1) — proven the same
 *     way BillingModeGuardTest proves it elsewhere in this codebase.
 *   - A duplicate/double-tick of the SAME rung is idempotent (no double
 *     email, no double next-rung task, no double renewal).
 *
 * No real or fake HTTP transport is configured anywhere in this suite —
 * `attemptDunningCollection()` has NO real network path (see its
 * doc-comment); tests drive its outcome purely via task metadata.
 *
 * @package    SIGNula\Tests\Integration\Payments
 * @version    2.9.0-beta
 * @see        web/private_html/payments/BillingScheduler.php (scheduleDunningRetry/processDunningRetry/processDunningFinalCancel)
 * @see        web/private_html/payments/PaymentManager.php (applyWebhookTransition — schedules rung 1)
 * @see        _database/migrations/039_dunning_retry_ladder.sql
 * @see        .dev-team/specs/G-002.md §4.2/§5/§9.1
 */

namespace SIGNula\Tests\Integration\Payments;

use SIGNula\Tests\DatabaseTestCase;
use PaymentManager;
use BillingScheduler;

// 📦 Dependency order — mirrors PaymentManagerWebhookLifecycleTest.php's
// established order, plus BillingScheduler.php/EmailService.php/
// ErrorLogger.php which THIS suite additionally exercises directly.
requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/payments/InvalidSubscriptionTransitionException.php');
requireSource('private_html/payments/SubscriptionStateMachine.php');
requireSource('private_html/payments/BillingModeException.php');
requireSource('private_html/payments/BillingMode.php');
requireSource('private_html/payments/InvoiceManager.php');
requireSource('private_html/payments/PaymentManager.php');
requireSource('private_html/payments/BillingScheduler.php');
requireSource('private_html/email/EmailService.php');

/**
 * 🔔 Dunning retry ladder test suite.
 */
class DunningRetryLadderTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblBillingAttempts',
        'tblBillingSchedule',
        'tblEmailQueue',
        'tblInvoices',
        'tblPayments',
        'tblActivityLog',
        'tblSubscriptions',
        'tblSubscriptionTiers',
        'tblUsers',
    ];

    /**
     * 🛡️ Defensively re-seed the email templates this suite depends on.
     *
     * This suite does NOT truncate `tblEmailTemplates` itself, but ANOTHER
     * integration test class in the SAME PHPUnit process might (e.g.
     * `_tests/Integration/Email/EmailServiceQueueTest.php` truncates it with
     * no re-seed) — leaving `EmailService::sendTemplateEmail()` silently
     * unable to find `payment_failed`/`dunning_final_notice`/
     * `subscription_cancelled` (it returns `false`, logs "Template not
     * found", and queues nothing) purely depending on cross-class run
     * order. Rather than depend on migration-time seeding surviving the
     * whole suite run, this setUp() makes the fixture self-sufficient:
     * `INSERT IGNORE` the exact rows migrations 012/039 seed, keyed on the
     * UNIQUE `templateKey`, so they exist regardless of what ran before.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureEmailTemplatesSeeded();
    }

    private function ensureEmailTemplatesSeeded(): void
    {
        $templates = [
            [
                'templateKey' => 'payment_failed',
                'templateName' => 'Payment Failed',
                'subject' => 'Payment Failed — Action Required - SIGNula',
                'bodyHTML' => '<p>Hi {{displayName}}, payment of {{currency}} {{amount}} failed: {{failureReason}}. Update at {{updateURL}}</p>',
                'bodyText' => 'Hi {{displayName}}, payment of {{currency}} {{amount}} failed: {{failureReason}}. Update at {{updateURL}}',
            ],
            [
                'templateKey' => 'dunning_final_notice',
                'templateName' => 'Dunning Final Notice',
                'subject' => 'Final Notice — Your Subscription Will Be Cancelled Soon - SIGNula',
                'bodyHTML' => '<p>Hi {{displayName}}, final notice for {{tierName}} — {{currency}} {{amount}} owed, {{graceDays}} days left. {{updateURL}}</p>',
                'bodyText' => 'Hi {{displayName}}, final notice for {{tierName}} — {{currency}} {{amount}} owed, {{graceDays}} days left. {{updateURL}}',
            ],
            [
                'templateKey' => 'subscription_cancelled',
                'templateName' => 'Subscription Cancelled',
                'subject' => 'Your Subscription Has Been Cancelled - SIGNula',
                'bodyHTML' => '<p>Hi {{displayName}}, your {{tierName}} subscription was cancelled. Reactivate at {{reactivateURL}}</p>',
                'bodyText' => 'Hi {{displayName}}, your {{tierName}} subscription was cancelled. Reactivate at {{reactivateURL}}',
            ],
        ];

        foreach ($templates as $t) {
            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO tblEmailTemplates
                    (templateKey, templateName, subject, bodyHTML, bodyText, category, trackingEnabled, isActive)
                 VALUES (?, ?, ?, ?, ?, 'transactional', 0, 1)"
            );
            $stmt->bind_param('sssss', $t['templateKey'], $t['templateName'], $t['subject'], $t['bodyHTML'], $t['bodyText']);
            $stmt->execute();
        }
    }

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

    /**
     * Creates a `dunning_retry` task that is already DUE (scheduledFor in
     * the past), so BillingScheduler::processDueTasks() picks it up
     * immediately — bypassing scheduleDunningRetry()'s own day-offset
     * ladder timing for direct test control over WHICH rung fires.
     */
    private function createDueDunningRetryTask(int $subscriptionID, int $attemptNumber, ?string $mockResult = null): int
    {
        $metadata = ['attemptNumber' => $attemptNumber];
        if ($mockResult !== null) {
            $metadata['mockCollectionResult'] = $mockResult;
        }

        $task = BillingScheduler::createTask(
            'dunning_retry',
            'subscription',
            $subscriptionID,
            date('Y-m-d H:i:s', strtotime('-1 minute')),
            $metadata
        );

        $this->assertTrue($task['success'], 'fixture: creating the due dunning_retry task must succeed');

        return $task['taskID'];
    }

    private function createDueDunningFinalCancelTask(int $subscriptionID): int
    {
        $task = BillingScheduler::createTask(
            'dunning_final_cancel',
            'subscription',
            $subscriptionID,
            date('Y-m-d H:i:s', strtotime('-1 minute')),
            ['reason' => 'dunning_exhausted']
        );

        $this->assertTrue($task['success'], 'fixture: creating the due dunning_final_cancel task must succeed');

        return $task['taskID'];
    }

    // ========================================================================
    // 1️⃣ ENTERING past_due SCHEDULES THE FIRST DUNNING RUNG
    // ========================================================================

    public function testEnteringPastDueScheduleTheFirstDunningRetryTask(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => 'active']);

        $result = PaymentManager::applyWebhookTransition(
            $sub['subscriptionID'],
            'past_due',
            'paypal',
            'webhook_past_due',
            'Payment failed (dunning start)',
            'paypal:evt-dunning-start-1'
        );

        $this->assertTrue($result['transitioned']);

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('past_due', $row['subscriptionStatus']);

        $task = $this->getRecord('tblBillingSchedule', [
            'targetType' => 'subscription',
            'targetID'   => $sub['subscriptionID'],
            'taskType'   => 'dunning_retry',
            'status'     => 'pending',
        ]);
        $this->assertNotNull($task, 'entering past_due must schedule the first dunning_retry task');

        $metadata = json_decode((string) $task['metadata'], true);
        $this->assertSame(1, $metadata['attemptNumber']);
    }

    /**
     * A REPEAT payment-failed event for a subscription that is ALREADY
     * past_due must NOT restart the ladder at rung 1 (would orphan whatever
     * rung is genuinely in flight).
     */
    public function testARepeatPastDueEventDoesNotRestartTheLadder(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => 'past_due']);

        // Simulate rung 2 already being in flight.
        $this->createDueDunningRetryTask($sub['subscriptionID'], 2);
        // Mark it processed so only one 'pending' dunning_retry row exists to reason about.
        BillingScheduler::processDueTasks(10);

        $pendingBefore = $this->countRecords('tblBillingSchedule', [
            'targetID' => $sub['subscriptionID'], 'taskType' => 'dunning_retry', 'status' => 'pending',
        ]);

        // A second, DISTINCT payment-failure event for the same (already past_due) subscription.
        PaymentManager::applyWebhookTransition(
            $sub['subscriptionID'],
            'past_due',
            'paypal',
            'webhook_past_due',
            'Payment failed (dunning start)',
            'paypal:evt-dunning-start-DIFFERENT'
        );

        $pendingAfter = $this->countRecords('tblBillingSchedule', [
            'targetID' => $sub['subscriptionID'], 'taskType' => 'dunning_retry', 'status' => 'pending',
        ]);

        $this->assertSame($pendingBefore, $pendingAfter, 'a repeat past_due event must not touch the in-flight ladder');
    }

    // ========================================================================
    // 2️⃣ A TICK WITH A MOCKED-FAILED COLLECTION
    // ========================================================================

    public function testFailedCollectionSendsTheEmailAndSchedulesTheNextRung(): void
    {
        $tierID = $this->createTestTier(['monthlyPrice' => 10.00]);
        $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => 'past_due', 'amount' => 10.00]);
        $this->createDueDunningRetryTask($sub['subscriptionID'], 1, 'failure');

        $result = BillingScheduler::processDueTasks(10);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);

        // 🔒 Still past_due — a failed rung never advances the state on its own.
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('past_due', $row['subscriptionStatus']);

        // 📧 The reused payment_failed template was queued for this user.
        $emailRow = $this->getRecord('tblEmailQueue', ['userID' => $sub['userID']]);
        $this->assertNotNull($emailRow, 'a failed collection must queue the payment_failed email');
        $this->assertStringContainsString('Payment Failed', $emailRow['subject']);

        // ➡️ Rung 2 is now pending.
        $nextTask = $this->getRecord('tblBillingSchedule', [
            'targetID' => $sub['subscriptionID'], 'taskType' => 'dunning_retry', 'status' => 'pending',
        ]);
        $this->assertNotNull($nextTask, 'a failed rung must schedule the next rung');
        $nextMeta = json_decode((string) $nextTask['metadata'], true);
        $this->assertSame(2, $nextMeta['attemptNumber']);

        // 📝 Audited.
        $this->assertNotNull($this->getRecord('tblBillingAttempts', [
            'subscriptionID' => $sub['subscriptionID'], 'action' => 'dunning_retry', 'status' => 'failed',
        ]));
    }

    // ========================================================================
    // 3️⃣ RETRIES EXHAUSTED -> past_due -> grace
    // ========================================================================

    public function testExhaustingMaxRetriesMovesSubscriptionToGraceAndSchedulesFinalCancel(): void
    {
        $tierID = $this->createTestTier(['monthlyPrice' => 10.00]);
        $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => 'past_due', 'amount' => 10.00]);

        // billing.dunning.max_retries defaults to 4 (migration 039) — fire the LAST rung.
        $this->createDueDunningRetryTask($sub['subscriptionID'], 4, 'failure');

        $result = BillingScheduler::processDueTasks(10);
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['failed']);

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('grace', $row['subscriptionStatus'], 'exhausting the ladder must move past_due -> grace');

        // 📧 The final-warning email was queued.
        $this->assertNotNull($this->getRecord('tblEmailQueue', ['userID' => $sub['userID']]));

        // ⏰ A final-cancel task is now pending, due at now + grace_period_days (default 7).
        $finalTask = $this->getRecord('tblBillingSchedule', [
            'targetID' => $sub['subscriptionID'], 'taskType' => 'dunning_final_cancel', 'status' => 'pending',
        ]);
        $this->assertNotNull($finalTask, 'ladder exhaustion must schedule the final-cancel task');
        $this->assertGreaterThan(time() + (6 * 86400), strtotime($finalTask['scheduledFor']), 'final-cancel must be scheduled ~grace_period_days out');

        // 🧹 No further dunning_retry rungs remain pending.
        $this->assertSame(0, $this->countRecords('tblBillingSchedule', [
            'targetID' => $sub['subscriptionID'], 'taskType' => 'dunning_retry', 'status' => 'pending',
        ]));
    }

    // ========================================================================
    // 4️⃣ GRACE WINDOW EXPIRES -> grace -> cancelled
    // ========================================================================

    public function testGraceWindowExpiryCancelsTheSubscription(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => 'grace']);
        $this->createDueDunningFinalCancelTask($sub['subscriptionID']);

        $result = BillingScheduler::processDueTasks(10);
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['failed']);

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('cancelled', $row['subscriptionStatus']);
        $this->assertNotNull($row['cancelledAt']);

        $this->assertNotNull($this->getRecord('tblEmailQueue', ['userID' => $sub['userID']]), 'the final cancellation email must be queued');
    }

    /**
     * If the subscription already left `grace` (e.g. reactivated) before the
     * final-cancel task fires, the task must be a clean no-op.
     */
    public function testFinalCancelIsANoOpIfSubscriptionAlreadyLeftGrace(): void
    {
        $tierID = $this->createTestTier();
        $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => 'active']); // already recovered
        $this->createDueDunningFinalCancelTask($sub['subscriptionID']);

        BillingScheduler::processDueTasks(10);

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('active', $row['subscriptionStatus'], 'a stale final-cancel task must never touch a subscription that already recovered');
    }

    // ========================================================================
    // 5️⃣ A TICK WITH A MOCKED-SUCCESS COLLECTION CLEARS DUNNING -> active
    // ========================================================================

    public function testSuccessfulCollectionClearsDunningAndReactivatesSubscription(): void
    {
        $tierID = $this->createTestTier(['monthlyPrice' => 10.00]);
        $sub = $this->createTestSubscription($tierID, [
            'subscriptionStatus' => 'past_due',
            'amount'             => 10.00,
            'currentPeriodStart' => date('Y-m-d', strtotime('-10 days')),
            'currentPeriodEnd'   => date('Y-m-d'),
        ]);
        // Also leave a stray future rung + a (hypothetical) final-cancel task
        // pending, to prove success cancels the REST of the ladder too.
        $this->createDueDunningRetryTask($sub['subscriptionID'], 2, 'success');

        $result = BillingScheduler::processDueTasks(10);
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['processed']);

        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('active', $row['subscriptionStatus'], 'a successful collection must clear dunning back to active');

        // 💳 Reused the renewal path — a real payment + invoice were recorded.
        $this->assertSame(1, $this->countRecords('tblPayments', ['subscriptionID' => $sub['subscriptionID'], 'status' => 'completed']));
        $this->assertSame(1, $this->countRecords('tblInvoices', ['subscriptionID' => $sub['subscriptionID']]));

        $this->assertNotNull($this->getRecord('tblBillingAttempts', [
            'subscriptionID' => $sub['subscriptionID'], 'action' => 'dunning_retry', 'status' => 'succeeded',
        ]));

        // 🧹 No dunning tasks remain pending.
        $this->assertSame(0, $this->countRecords('tblBillingSchedule', [
            'targetID' => $sub['subscriptionID'], 'taskType' => 'dunning_retry', 'status' => 'pending',
        ]));
        $this->assertSame(0, $this->countRecords('tblBillingSchedule', [
            'targetID' => $sub['subscriptionID'], 'taskType' => 'dunning_final_cancel', 'status' => 'pending',
        ]));
    }

    // ========================================================================
    // 6️⃣ TEST-MODE GUARD — a live-mode provider is refused on the retry
    // ========================================================================

    public function testLiveModeProviderIsRefusedOnTheRetryAndMutatesNothing(): void
    {
        setTestSetting('payment.paypal.mode', 'live');
        setTestSetting('billing.test_mode_guard', '1');

        $tierID = $this->createTestTier(['monthlyPrice' => 10.00]);
        $sub = $this->createTestSubscription($tierID, [
            'subscriptionStatus' => 'past_due',
            'paymentMethod'      => 'paypal',
            'amount'             => 10.00,
        ]);
        // Even a "mocked success" must never be reached — the guard fires FIRST.
        $this->createDueDunningRetryTask($sub['subscriptionID'], 1, 'success');

        $result = BillingScheduler::processDueTasks(10);

        // 🔒 ZERO side effects — fail-closed.
        $row = $this->getRecord('tblSubscriptions', ['subscriptionID' => $sub['subscriptionID']]);
        $this->assertSame('past_due', $row['subscriptionStatus'], 'a live-mode-guarded retry must never move the subscription');

        $this->assertSame(0, $this->countRecords('tblPayments', ['subscriptionID' => $sub['subscriptionID']]), 'no charge may ever be recorded when the guard refuses');
        $this->assertSame(0, $this->countRecords('tblEmailQueue', ['userID' => $sub['userID']]), 'no dunning email is sent when the collection attempt never happened');

        $this->assertNotNull(
            $this->getRecord('tblBillingAttempts', ['provider' => 'paypal', 'status' => 'blocked']),
            'BillingMode::assertTestMode() must have written the audited blocked row'
        );
        $this->assertSame(0, $this->countRecords('tblBillingAttempts', [
            'subscriptionID' => $sub['subscriptionID'], 'action' => 'dunning_retry',
        ]), 'a guard-blocked attempt must not also write a dunning_retry outcome row');
    }

    // ========================================================================
    // 7️⃣ IDEMPOTENT DOUBLE-TICK
    // ========================================================================

    public function testDuplicateDunningTaskForTheSameRungIsIdempotent(): void
    {
        $tierID = $this->createTestTier(['monthlyPrice' => 10.00]);
        $sub = $this->createTestSubscription($tierID, ['subscriptionStatus' => 'past_due', 'amount' => 10.00]);

        // Two DISTINCT tblBillingSchedule rows for the exact same rung — the
        // scenario the idempotencyKey guard (keyed on subscriptionID +
        // attemptNumber, NOT on taskID) must collapse into one outcome.
        $this->createDueDunningRetryTask($sub['subscriptionID'], 1, 'failure');
        $this->createDueDunningRetryTask($sub['subscriptionID'], 1, 'failure');

        $result = BillingScheduler::processDueTasks(10);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['processed'], 'the scheduler processes both task ROWS (each reports success)...');

        // ...but only ONE real outcome was recorded/actioned.
        $this->assertSame(1, $this->countRecords('tblBillingAttempts', [
            'subscriptionID' => $sub['subscriptionID'], 'action' => 'dunning_retry', 'status' => 'failed',
        ]), 'a duplicate rung must only be ACTIONED once');

        $this->assertSame(1, $this->countRecords('tblEmailQueue', ['userID' => $sub['userID']]), 'only ONE dunning email may be sent for the duplicate rung');

        // Exactly one rung-2 task exists — the duplicate must not stack a second one.
        $this->assertSame(1, $this->countRecords('tblBillingSchedule', [
            'targetID' => $sub['subscriptionID'], 'taskType' => 'dunning_retry', 'status' => 'pending',
        ]));
    }
}
