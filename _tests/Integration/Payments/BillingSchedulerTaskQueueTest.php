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

requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
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
    ];

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
}
