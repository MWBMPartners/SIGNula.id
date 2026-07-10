<?php
/**
 * ============================================================================
 * 🧪 BillingScheduler Task-Queue Integration Tests (B-025 reconciliation proof)
 * ============================================================================
 *
 * DB-bound proof that BillingScheduler's core tblBillingSchedule queries now
 * run CLEAN against the real schema after migration 036 + the B-025 code
 * fixes (scheduleID/taskID alias, nextRetryAt/result/updatedAt columns,
 * 'cancelled' status ENUM member, widened TASK_TYPES constant). Before this
 * fix, every one of these calls raised "Unknown column" SQL errors on a
 * clean install (grep-verified — see migration 036's header comment).
 *
 * @package    SIGNula\Tests\Integration\Payments
 * @version    2.9.0-beta
 * @see        web/private_html/payments/BillingScheduler.php
 * @see        _database/migrations/036_recurring_billing_foundation.sql
 */

namespace SIGNula\Tests\Integration\Payments;

use SIGNula\Tests\DatabaseTestCase;
use BillingScheduler;
use PayPalProvider;

requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
// 📦 F-B02 regression coverage additionally exercises the REAL
// processSubscriptionCharge() -> executeProviderCharge() -> PayPalProvider
// chain (mirrors DunningRetryLadderTest.php's established dependency
// order): PaymentManager (recordPayment), BillingModeException/BillingMode
// (PayPalProvider's own guard requires), PayPalProvider itself (so
// class_exists('PayPalProvider') is TRUE and the method_exists() check —
// not the "class not available" fallback — is what's actually exercised),
// and EmailService (the payment_failed notification on charge failure).
requireSource('private_html/payments/PaymentManager.php');
requireSource('private_html/payments/BillingModeException.php');
requireSource('private_html/payments/BillingMode.php');
requireSource('private_html/payments/PayPalProvider.php');
requireSource('private_html/email/EmailService.php');
requireSource('private_html/payments/BillingScheduler.php');

/**
 * ⏰ BillingScheduler task-queue test suite.
 */
class BillingSchedulerTaskQueueTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblBillingSchedule',
        'tblActivityLog',
        // 📦 F-B02 regression fixtures (subscription -> charge -> payment chain).
        'tblBillingAttempts',
        'tblPayments',
        'tblPaymentMethods',
        'tblSubscriptions',
        'tblSubscriptionTiers',
        'tblEmailQueue',
        'tblUsers',
    ];

    // ========================================================================
    // 🔧 F-B02 FIXTURE HELPERS
    // ========================================================================

    private function createTestTier(array $overrides = []): int
    {
        $defaults = [
            'tierName'     => 'FB02 Tier ' . bin2hex(random_bytes(3)),
            'tierSlug'     => 'fb02-tier-' . bin2hex(random_bytes(4)),
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
            'currentPeriodEnd'   => date('Y-m-d', strtotime('-1 day')),
            'nextBillingDate'    => date('Y-m-d', strtotime('-1 day')),
        ];

        return $this->insertRecord('tblSubscriptions', array_merge($defaults, $overrides));
    }

    private function createTestPaymentMethod(int $userID, string $provider = 'paypal'): int
    {
        return $this->insertRecord('tblPaymentMethods', [
            'userID'        => $userID,
            'paymentMethod' => $provider,
            'provider'      => $provider,
            'displayName'   => 'FB02 Test ' . ucfirst($provider),
            'isDefault'     => 1,
            'isVerified'    => 1,
        ]);
    }

    // ========================================================================
    // ➕ createTask() / getTasksDue() — the scheduleID<->taskID alias
    // ========================================================================

    public function testCreateTaskAndGetTasksDueRoundTripUsesTheTaskIdAlias(): void
    {
        $created = BillingScheduler::createTask(
            'send_reminder',
            'subscription',
            42,
            date('Y-m-d H:i:s', strtotime('-1 minute')) // already due
        );

        $this->assertTrue($created['success']);
        $this->assertGreaterThan(0, $created['taskID']);

        $due = BillingScheduler::getTasksDue(10);
        $this->assertCount(1, $due);
        // 🩹 B-025: the SELECT aliases the real PK (scheduleID) back to
        // 'taskID' so callers reading $task['taskID'] keep working.
        $this->assertSame($created['taskID'], (int) $due[0]['taskID']);
        $this->assertArrayHasKey('scheduleID', $due[0]);
        $this->assertSame($created['taskID'], (int) $due[0]['scheduleID']);
    }

    /**
     * 🩹 B-025: TASK_TYPES was widened to include the usage-billing task
     * types the scheduler ALREADY creates elsewhere in this file
     * (processUsageCalculation() -> createTask('charge_usage', …), etc.) but
     * previously silently rejected via its own validation.
     */
    public function testCreateTaskAcceptsEveryUsageBillingTaskType(): void
    {
        foreach (['generate_invoice', 'fee_change_notification', 'calculate_usage', 'charge_usage', 'archive_usage'] as $taskType) {
            $result = BillingScheduler::createTask($taskType, 'subscription', 1, date('Y-m-d H:i:s'));
            $this->assertTrue($result['success'], "createTask() must accept previously-widened taskType '{$taskType}'");
        }
    }

    // ========================================================================
    // ✏️ markTaskProcessed() — targets scheduleID, writes result/updatedAt
    // ========================================================================

    public function testMarkTaskProcessedUpdatesStatusResultAndUpdatedAt(): void
    {
        $created = BillingScheduler::createTask('send_reminder', 'subscription', 7, date('Y-m-d H:i:s'));
        $taskID = $created['taskID'];

        BillingScheduler::markTaskProcessed($taskID, 'completed', json_encode(['ok' => true]));

        $row = $this->getRecord('tblBillingSchedule', ['scheduleID' => $taskID]);
        $this->assertSame('completed', $row['status']);
        $this->assertSame(json_encode(['ok' => true]), $row['result']);
        $this->assertNotNull($row['updatedAt']);
        $this->assertSame(1, (int) $row['attempts']);
    }

    /**
     * 🩹 B-025: the 'cancelled' status is now a valid ENUM member — before
     * migration 036 this UPDATE would fail (or silently truncate) under
     * strict SQL mode.
     */
    public function testMarkTaskProcessedAcceptsTheCancelledStatus(): void
    {
        $created = BillingScheduler::createTask('send_reminder', 'subscription', 7, date('Y-m-d H:i:s'));

        BillingScheduler::markTaskProcessed($created['taskID'], 'cancelled', 'Manually cancelled by admin');

        $row = $this->getRecord('tblBillingSchedule', ['scheduleID' => $created['taskID']]);
        $this->assertSame('cancelled', $row['status']);
    }

    // ========================================================================
    // 🔁 retryTask() — writes nextRetryAt against the real PK
    // ========================================================================

    public function testRetryTaskSchedulesANextRetryAtAndKeepsStatusPending(): void
    {
        $created = BillingScheduler::createTask('send_reminder', 'subscription', 7, date('Y-m-d H:i:s'));

        BillingScheduler::retryTask($created['taskID'], 15);

        $row = $this->getRecord('tblBillingSchedule', ['scheduleID' => $created['taskID']]);
        $this->assertSame('pending', $row['status']);
        $this->assertNotNull($row['nextRetryAt']);
        $this->assertGreaterThan(time(), strtotime($row['nextRetryAt']));
    }

    // ========================================================================
    // 🧹 cancelPendingTasks()
    // ========================================================================

    public function testCancelPendingTasksCancelsAndCountsMatchingTasks(): void
    {
        BillingScheduler::createTask('send_reminder', 'subscription', 99, date('Y-m-d H:i:s'));
        BillingScheduler::createTask('charge_subscription', 'subscription', 99, date('Y-m-d H:i:s'));
        BillingScheduler::createTask('send_reminder', 'subscription', 100, date('Y-m-d H:i:s')); // different target — must NOT be cancelled

        $cancelledCount = BillingScheduler::cancelPendingTasks('subscription', 99);

        $this->assertSame(2, $cancelledCount);
        $this->assertSame(1, $this->countRecords('tblBillingSchedule', ['targetID' => 100, 'status' => 'pending']));
    }

    // ========================================================================
    // 📊 getSchedulerHealth() — reads updatedAt in its aggregate query
    // ========================================================================

    public function testGetSchedulerHealthRunsWithoutSqlErrors(): void
    {
        BillingScheduler::createTask('send_reminder', 'subscription', 1, date('Y-m-d H:i:s', strtotime('-1 minute')));

        $health = BillingScheduler::getSchedulerHealth();

        $this->assertArrayHasKey('pendingCount', $health);
        $this->assertGreaterThanOrEqual(1, $health['pendingCount']);
        $this->assertGreaterThanOrEqual(0, $health['overdueCount']);
    }

    // ========================================================================
    // 🚀 processDueTasks() — the full end-to-end claim -> route -> complete cycle
    // ========================================================================

    public function testProcessDueTasksRunsEndToEndWithZeroSqlErrors(): void
    {
        // process_remittance is a dependency-free placeholder handler
        // (@see BillingScheduler::processRemittance()) — perfect for proving
        // the full processDueTasks() plumbing (claim -> route -> markTaskProcessed)
        // is schema-clean without needing subscription/payment-method fixtures.
        BillingScheduler::createTask('process_remittance', 'remittance', 1, date('Y-m-d H:i:s', strtotime('-1 minute')));

        $result = BillingScheduler::processDueTasks(10);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);

        $this->assertSame(1, $this->countRecords('tblBillingSchedule', ['status' => 'completed']));
    }

    // ========================================================================
    // 🩹 F-B02 REGRESSION — phantom chargeStoredMethod() no longer crashes
    //     the whole billing cron batch
    // ========================================================================
    //
    // BEFORE the fix: BillingScheduler::executeProviderCharge() unconditionally
    // called {PayPal,Stripe,Coinbase}Provider::chargeStoredMethod(), which does
    // NOT exist on any provider class. That raised a PHP \Error ("Call to
    // undefined method"), which is NOT an \Exception — it escaped every
    // catch(\Exception) in the call chain (executeProviderCharge() ->
    // processSubscriptionCharge() -> the per-task loop in processDueTasks())
    // and fatally aborted the ENTIRE cron batch, silently skipping every
    // LATER due task (dunning, suspension, reminders, …) in that run.
    //
    // AFTER the fix: executeProviderCharge() detects the missing method via
    // method_exists() BEFORE calling it and returns a clean
    // ['success' => false, 'message' => 'unsupported: ...'] failure instead;
    // the per-task loop now catches \Throwable (not just \Exception) as
    // defence-in-depth. This test proves BOTH halves of the fix: (1) the
    // due charge_subscription task itself fails gracefully with the new
    // informative message and no fatal error, and (2) a SECOND, independent
    // due task in the SAME batch still gets processed — i.e. the batch
    // genuinely continues past the bad task.

    public function testChargeSubscriptionTaskFailsGracefullyAndBatchContinues(): void
    {
        // 🏗️ Fixture: an active £9.99/mo subscription billed via PayPal, with
        // a verified default PayPal payment method on file — exactly the
        // shape processSubscriptionCharge() needs to reach
        // executeProviderCharge(). PayPal is left in its default sandbox
        // mode so PaymentManager::recordPayment()'s own BillingMode guard
        // does not intercede — this test is proving the F-B02 fix, not
        // re-proving F-B01.
        $user = $this->createTestUser();
        $tierID = $this->createTestTier(['monthlyPrice' => 9.99]);
        $subscriptionID = $this->createTestSubscription($tierID, $user['userID']);
        $this->createTestPaymentMethod($user['userID'], 'paypal');

        // 🎯 The due charge_subscription task (the one that used to fatal).
        // Inserted directly with maxAttempts = 0 (rather than via
        // createTask(), which defaults to billing.max_charge_attempts = 3)
        // so the FIRST failed tick lands straight on markTaskProcessed(…,
        // 'failed', …) instead of a silent retry — giving this test a
        // deterministic, single-tick 'result' column to assert on without
        // mutating the shared tblSettings row (which other tests read via
        // BillingScheduler::getSetting(), a direct DB read that
        // setTestSetting()'s in-memory stub does NOT reach).
        $chargeTaskID = $this->insertRecord('tblBillingSchedule', [
            'taskType'     => 'charge_subscription',
            'targetType'   => 'subscription',
            'targetID'     => $subscriptionID,
            'scheduledFor' => date('Y-m-d H:i:s', strtotime('-2 minutes')),
            'status'       => 'pending',
            'attempts'     => 0,
            'maxAttempts'  => 0,
        ]);
        $chargeTask = ['success' => true, 'taskID' => $chargeTaskID];

        // 🧷 A SECOND, wholly independent due task in the SAME batch — a
        // dependency-free placeholder handler (mirrors
        // testProcessDueTasksRunsEndToEndWithZeroSqlErrors() above). If the
        // F-B02 bug were still present, processDueTasks() would fatal on the
        // charge task and this one would NEVER be reached.
        $remittanceTask = BillingScheduler::createTask(
            'process_remittance',
            'remittance',
            777,
            date('Y-m-d H:i:s', strtotime('-1 minute'))
        );
        $this->assertTrue($remittanceTask['success'], 'fixture: creating the second due task must succeed');

        // 🚀 Run the real, public cron entry point — no mocking of
        // PayPalProvider, no network access (chargeStoredMethod() is never
        // actually called — see the doc-comment above).
        $result = BillingScheduler::processDueTasks(10);

        // ✅ The batch itself completed (did not fatally crash the whole run).
        $this->assertTrue($result['success'], 'processDueTasks() must complete the batch without fatally erroring: ' . json_encode($result));
        $this->assertSame(1, $result['failed'], 'exactly the one charge task should have failed this tick');

        // ✅ The charge task failed CLEANLY via the new method_exists() guard
        // (not via an uncaught \Error) — the task row was updated, not left
        // stuck in 'processing', and its recorded result names the new,
        // informative "unsupported" failure rather than a raw PHP fatal.
        $chargeRow = $this->getRecord('tblBillingSchedule', ['scheduleID' => $chargeTask['taskID']]);
        $this->assertNotNull($chargeRow);
        $this->assertNotSame('processing', $chargeRow['status'], 'a crash would leave the task stuck in processing');
        $this->assertGreaterThanOrEqual(1, (int) $chargeRow['attempts']);
        $this->assertStringContainsString(
            'unsupported',
            (string) $chargeRow['result'],
            'F-B02 FIX: the task result must carry the new method_exists()-guard message, proving the '
            . 'phantom chargeStoredMethod() call was never attempted.'
        );

        // 🎯 THE core F-B02 assertion: the batch continued past the bad task
        // and completed the second, unrelated due task in the SAME run.
        $remittanceRow = $this->getRecord('tblBillingSchedule', ['scheduleID' => $remittanceTask['taskID']]);
        $this->assertSame(
            'completed',
            $remittanceRow['status'],
            'F-B02 FIX: a later due task must still be processed after an earlier task hits the phantom '
            . 'chargeStoredMethod() method — the whole cron batch must not abort.'
        );

        // 🔍 Belt-and-braces: PayPalProvider really does not declare
        // chargeStoredMethod() — pins the precondition the whole fix (and
        // this test) depends on, so a future accidental implementation
        // doesn't silently make this test meaningless.
        $this->assertFalse(
            method_exists(PayPalProvider::class, 'chargeStoredMethod'),
            'precondition: PayPalProvider::chargeStoredMethod() is expected not to exist yet (see B-075)'
        );
    }
}
