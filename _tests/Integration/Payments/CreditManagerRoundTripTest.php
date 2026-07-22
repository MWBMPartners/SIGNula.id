<?php
/**
 * ============================================================================
 * 🧪 CreditManager Round-Trip Integration Tests (B-071 fix proof)
 * ============================================================================
 *
 * DB-bound proof that every CreditManager balance-mutating method now works
 * against a REAL MySQL server. Before the B-071 fix, every one of
 * deposit()/withdraw()/payFromCredits()/refundToCredits()/adjustBalance()/
 * transfer() failed for two independent reasons:
 *
 *   1. Each method opened its "transaction" via
 *      `Database::query("START TRANSACTION", [], '')` and closed it via
 *      `Database::query("COMMIT"/"ROLLBACK", [], '')` — but Database::query()
 *      ALWAYS prepares its statement, and mysqli rejects those three
 *      statements via the prepared-statement protocol (MySQL error 1295,
 *      "This command is not supported in the prepared statement protocol
 *      yet"). Every call threw before touching a single row.
 *   2. Even past that, the SQL referenced columns that do not exist on the
 *      REAL schema: `tblCreditBalances.totalDeposited`/`totalWithdrawn`
 *      (migration 012 has no such columns — only `balance`), and
 *      `tblCreditTransactions.transactionType` (the real column is `type`,
 *      and its ENUM has no 'transfer' member, which transfer() relied on).
 *
 * This suite proves the round-trip for all 7 public balance-facing methods
 * (getBalance/getBalanceRecord implicitly exercised by every other method)
 * against `signula_test`, asserting BOTH the balance after each operation
 * AND that a matching tblCreditTransactions ledger row was written.
 *
 * @package    SIGNula\Tests\Integration\Payments
 * @version    2.9.0-beta
 * @see        web/private_html/payments/CreditManager.php
 * @see        _database/migrations/012_payment_expansion.sql (the real tblCreditBalances/tblCreditTransactions schema)
 */

namespace SIGNula\Tests\Integration\Payments;

use SIGNula\Tests\DatabaseTestCase;
use CreditManager;

requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/payments/CreditManager.php');

/**
 * 💰 CreditManager round-trip test suite.
 */
class CreditManagerRoundTripTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblCreditTransactions',
        'tblCreditBalances',
        'tblActivityLog',
        'tblUsers',
    ];

    // ========================================================================
    // 💵 DEPOSIT
    // ========================================================================

    public function testDepositRoundTripsAgainstRealMysql(): void
    {
        $user = $this->createTestUser();

        $result = CreditManager::deposit('user', $user['userID'], 25.50, 'Top-up', [
            'referenceType' => 'manual',
        ]);

        $this->assertTrue($result['success'], $result['message'] ?? 'deposit() must succeed against real MySQL');
        $this->assertSame(25.5, $result['newBalance']);

        $balance = CreditManager::getBalance('user', $user['userID']);
        $this->assertSame(25.5, $balance);

        $ledgerRow = $this->getRecord('tblCreditTransactions', ['creditTransactionID' => $result['transactionID']]);
        $this->assertNotNull($ledgerRow, 'deposit() must write a tblCreditTransactions ledger row');
        $this->assertSame('deposit', $ledgerRow['type']);
        $this->assertSame('0.00', $ledgerRow['balanceBefore']);
        $this->assertSame('25.50', $ledgerRow['balanceAfter']);
    }

    // ========================================================================
    // 💸 WITHDRAW
    // ========================================================================

    public function testWithdrawRoundTripsAgainstRealMysql(): void
    {
        $user = $this->createTestUser();
        CreditManager::deposit('user', $user['userID'], 50.00, 'Seed balance');

        $result = CreditManager::withdraw('user', $user['userID'], 15.25, 'Manual debit');

        $this->assertTrue($result['success'], $result['message'] ?? 'withdraw() must succeed against real MySQL');
        $this->assertSame(34.75, $result['newBalance']);
        $this->assertSame(34.75, CreditManager::getBalance('user', $user['userID']));

        $ledgerRow = $this->getRecord('tblCreditTransactions', ['creditTransactionID' => $result['transactionID']]);
        $this->assertSame('withdrawal', $ledgerRow['type']);
    }

    public function testWithdrawRefusesInsufficientFundsWithoutCorruptingBalance(): void
    {
        $user = $this->createTestUser();
        CreditManager::deposit('user', $user['userID'], 10.00, 'Seed balance');

        $result = CreditManager::withdraw('user', $user['userID'], 10.01, 'Over-limit debit');

        $this->assertFalse($result['success']);
        // 🔒 The balance must be UNCHANGED — this also proves ROLLBACK works,
        // i.e. Database::rollback() actually reached mysqli (B-071).
        $this->assertSame(10.0, CreditManager::getBalance('user', $user['userID']));
    }

    // ========================================================================
    // 🛒 PAY FROM CREDITS
    // ========================================================================

    public function testPayFromCreditsRoundTripsAgainstRealMysql(): void
    {
        $user = $this->createTestUser();
        CreditManager::deposit('user', $user['userID'], 40.00, 'Seed balance');

        $result = CreditManager::payFromCredits('user', $user['userID'], 12.34, 'Subscription renewal', 999);

        $this->assertTrue($result['success'], $result['message'] ?? 'payFromCredits() must succeed against real MySQL');
        $this->assertSame(27.66, $result['newBalance']);

        $ledgerRow = $this->getRecord('tblCreditTransactions', ['creditTransactionID' => $result['transactionID']]);
        $this->assertSame('payment', $ledgerRow['type']);
        $this->assertSame('payment', $ledgerRow['referenceType']);
        $this->assertSame('999', (string)$ledgerRow['referenceID']);
    }

    // ========================================================================
    // 🔄 REFUND TO CREDITS
    // ========================================================================

    public function testRefundToCreditsRoundTripsAgainstRealMysql(): void
    {
        $user = $this->createTestUser();
        CreditManager::deposit('user', $user['userID'], 10.00, 'Seed balance');

        $result = CreditManager::refundToCredits('user', $user['userID'], 5.55, 'Partial refund', 1001);

        $this->assertTrue($result['success'], $result['message'] ?? 'refundToCredits() must succeed against real MySQL');
        $this->assertSame(15.55, $result['newBalance']);

        $ledgerRow = $this->getRecord('tblCreditTransactions', ['creditTransactionID' => $result['transactionID']]);
        $this->assertSame('refund', $ledgerRow['type']);
    }

    // ========================================================================
    // ⚖️ ADJUST BALANCE
    // ========================================================================

    public function testAdjustBalancePositiveAndNegativeRoundTripAgainstRealMysql(): void
    {
        $user = $this->createTestUser();
        $admin = $this->createTestUser();
        CreditManager::deposit('user', $user['userID'], 20.00, 'Seed balance');

        $up = CreditManager::adjustBalance('user', $user['userID'], 5.00, 'Goodwill credit', $admin['userID']);
        $this->assertTrue($up['success'], $up['message'] ?? 'adjustBalance() (credit) must succeed against real MySQL');
        $this->assertSame(25.0, $up['newBalance']);

        $down = CreditManager::adjustBalance('user', $user['userID'], -7.50, 'Correction', $admin['userID']);
        $this->assertTrue($down['success'], $down['message'] ?? 'adjustBalance() (debit) must succeed against real MySQL');
        $this->assertSame(17.5, $down['newBalance']);

        $this->assertSame(17.5, CreditManager::getBalance('user', $user['userID']));
    }

    public function testAdjustBalanceRefusesGoingNegative(): void
    {
        $user = $this->createTestUser();
        $admin = $this->createTestUser();
        CreditManager::deposit('user', $user['userID'], 5.00, 'Seed balance');

        $result = CreditManager::adjustBalance('user', $user['userID'], -5.01, 'Bad correction', $admin['userID']);

        $this->assertFalse($result['success']);
        $this->assertSame(5.0, CreditManager::getBalance('user', $user['userID']));
    }

    // ========================================================================
    // 🔀 TRANSFER
    // ========================================================================

    public function testTransferRoundTripsAgainstRealMysqlAndWritesBothLedgerLegs(): void
    {
        $fromUser = $this->createTestUser();
        $toUser = $this->createTestUser();
        $admin = $this->createTestUser();
        CreditManager::deposit('user', $fromUser['userID'], 30.00, 'Seed balance');

        $result = CreditManager::transfer(
            'user', $fromUser['userID'],
            'user', $toUser['userID'],
            10.00,
            'Gift credits',
            $admin['userID']
        );

        $this->assertTrue($result['success'], $result['message'] ?? 'transfer() must succeed against real MySQL');
        $this->assertSame(20.0, $result['fromNewBalance']);
        $this->assertSame(10.0, $result['toNewBalance']);

        $this->assertSame(20.0, CreditManager::getBalance('user', $fromUser['userID']));
        $this->assertSame(10.0, CreditManager::getBalance('user', $toUser['userID']));

        // 🩹 B-071 fix proof: the real `type` ENUM has no 'transfer' member —
        // the two ledger rows must be an ordinary 'withdrawal' + 'deposit',
        // tagged referenceType='transfer' and cross-linked.
        $withdrawalRow = $this->getRecord('tblCreditTransactions', ['creditTransactionID' => $result['withdrawalTransactionID']]);
        $depositRow    = $this->getRecord('tblCreditTransactions', ['creditTransactionID' => $result['depositTransactionID']]);

        $this->assertSame('withdrawal', $withdrawalRow['type']);
        $this->assertSame('transfer', $withdrawalRow['referenceType']);
        $this->assertSame('deposit', $depositRow['type']);
        $this->assertSame('transfer', $depositRow['referenceType']);

        $depositMeta = json_decode((string)$depositRow['metadata'], true);
        $this->assertSame($result['withdrawalTransactionID'], $depositMeta['linkedTransactionID']);
    }

    public function testTransferRefusesInsufficientSourceFundsAndMutatesNeitherBalance(): void
    {
        $fromUser = $this->createTestUser();
        $toUser = $this->createTestUser();
        $admin = $this->createTestUser();
        CreditManager::deposit('user', $fromUser['userID'], 3.00, 'Seed balance');

        $result = CreditManager::transfer(
            'user', $fromUser['userID'],
            'user', $toUser['userID'],
            10.00,
            'Overdraw attempt',
            $admin['userID']
        );

        $this->assertFalse($result['success']);
        $this->assertSame(3.0, CreditManager::getBalance('user', $fromUser['userID']));
        $this->assertSame(0.0, CreditManager::getBalance('user', $toUser['userID']));
    }

    // ========================================================================
    // 🧮 bcmath precision — repeated additions never drift (float-drift proof)
    // ========================================================================

    public function testRepeatedSmallDepositsNeverDriftUnderBcmath(): void
    {
        $user = $this->createTestUser();

        // 0.10 + 0.20 is the textbook float-drift example (0.30000000000000004
        // in native float arithmetic). Ten repeats stresses accumulation.
        for ($i = 0; $i < 10; $i++) {
            CreditManager::deposit('user', $user['userID'], 0.10, 'Micro top-up #' . $i);
        }

        $this->assertSame(1.0, CreditManager::getBalance('user', $user['userID']));

        $record = CreditManager::getBalanceRecord('user', $user['userID']);
        $this->assertSame('1.00', $record['balance']);
    }
}
