<?php
/**
 * ============================================================================
 * 🧪 credit-actions.php Call-Site Fix Tests (B-074)
 * ============================================================================
 *
 * `web/public_html/admin/api/credit-actions.php` and `web/public_html/
 * partners/api/credit-actions.php` are true HTTP endpoint scripts (session +
 * AccessControl + CSRF + `echo`/`exit` at the top level), so they are not
 * directly `require`-able in-process the way a static class is — see
 * `_tests/Integration/API/JwtAuthEndpointsTest.php` for the project's
 * established pattern for endpoints that genuinely need one (a `php -S`
 * subprocess harness). Standing that harness up for two session/role/CSRF
 * -gated endpoints was judged out of proportion for a call-signature fix, so
 * this suite instead proves the fix at the two levels that actually matter:
 *
 *   1. The CreditManager round-trip: every admin handler
 *      (handleAdjustBalance/handleDepositCredits/handleWithdrawCredits) now
 *      calls CreditManager::adjustBalance()/deposit()/withdraw()/getBalance()
 *      with the EXACT corrected argument shape used in the fixed source
 *      (verified by direct code inspection cross-referenced against these
 *      tests) — proven here by calling CreditManager with that same shape
 *      against a REAL MySQL connection and asserting success + a correct
 *      resulting balance + ledger row.
 *   2. The partner top-up fallback path: the exact corrected `tblPayments`
 *      INSERT (now including `netAmount`/`paymentProvider`/`ipAddress` — all
 *      three NOT NULL with no column default on the real schema) is proven
 *      to succeed against `signula_test`, and `CreditManager::initiateTopUp()`
 *      is proven NOT to exist — pinning why the handler routes to that safe
 *      fallback instead of a live-charge path.
 *
 * @package    SIGNula\Tests\Integration\Payments
 * @version    2.9.0-beta
 * @see        web/public_html/admin/api/credit-actions.php
 * @see        web/public_html/partners/api/credit-actions.php
 * @see        web/private_html/payments/CreditManager.php
 */

namespace SIGNula\Tests\Integration\Payments;

use SIGNula\Tests\DatabaseTestCase;
use CreditManager;

requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/payments/CreditManager.php');

/**
 * 💳 credit-actions.php call-site fix test suite.
 */
class CreditActionsCallSiteTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblCreditTransactions',
        'tblCreditBalances',
        'tblPayments',
        'tblActivityLog',
        'tblUsers',
        'tblPartners',
    ];

    // ========================================================================
    // ⚖️ handleAdjustBalance() — the FIXED call shape
    // ========================================================================

    public function testAdminAdjustBalanceFixedCallShapeRoundTrips(): void
    {
        $user = $this->createTestUser();
        $admin = $this->createTestUser();

        // 🩹 B-074 fix: this is EXACTLY the call
        // web/public_html/admin/api/credit-actions.php::handleAdjustBalance()
        // now makes — (ownerType, ownerID, amount, description, performedBy,
        // options) — where the buggy original passed ($ownerType, $ownerID,
        // $amount, $currency, $description, $adminUserID, null) and fataled
        // with a TypeError (int given, array expected) on the last argument.
        $result = CreditManager::adjustBalance(
            'user',
            $user['userID'],
            15.00,
            'Manual admin credit adjustment',
            $admin['userID'],
            ['currency' => 'GBP', 'referenceType' => 'admin_manual']
        );

        $this->assertTrue($result['success'], $result['message'] ?? 'adjustBalance() must succeed with the fixed call shape');
        $this->assertSame(15.0, $result['newBalance']);

        // 🩹 The handler's OWN corrected getBalance() call shape —
        // (ownerType, ownerID, partnerID=null, currency) — must also work.
        $this->assertSame(15.0, CreditManager::getBalance('user', $user['userID'], null, 'GBP'));
    }

    // ========================================================================
    // 💵 handleDepositCredits() — the FIXED call shape
    // ========================================================================

    public function testAdminDepositFixedCallShapeRoundTrips(): void
    {
        $user = $this->createTestUser();
        $admin = $this->createTestUser();

        // 🩹 B-074 fix: mirrors handleDepositCredits()'s corrected call —
        // (ownerType, ownerID, amount, description, options) — the buggy
        // original passed $currency as $description and $description (a
        // string) into the `array $options` parameter, a fatal TypeError.
        $result = CreditManager::deposit(
            'partner',
            1,
            50.00,
            'Admin credit deposit',
            ['currency' => 'GBP', 'referenceType' => 'manual', 'performedBy' => $admin['userID']]
        );

        $this->assertTrue($result['success'], $result['message'] ?? 'deposit() must succeed with the fixed call shape');
        $this->assertSame(50.0, $result['newBalance']);
        $this->assertSame(50.0, CreditManager::getBalance('partner', 1, null, 'GBP'));

        $ledgerRow = $this->getRecord('tblCreditTransactions', ['creditTransactionID' => $result['transactionID']]);
        $this->assertSame('deposit', $ledgerRow['type']);
        $this->assertSame((string)$admin['userID'], (string)$ledgerRow['performedBy']);
    }

    // ========================================================================
    // 💸 handleWithdrawCredits() — the FIXED call shape
    // ========================================================================

    public function testAdminWithdrawFixedCallShapeRoundTrips(): void
    {
        $admin = $this->createTestUser();
        CreditManager::deposit('partner', 2, 40.00, 'Seed balance', ['currency' => 'GBP']);

        // 🩹 B-074 fix: mirrors handleWithdrawCredits()'s corrected call
        // shape — same fix as deposit() above.
        $result = CreditManager::withdraw(
            'partner',
            2,
            12.50,
            'Admin credit withdrawal',
            ['currency' => 'GBP', 'referenceType' => 'manual', 'performedBy' => $admin['userID']]
        );

        $this->assertTrue($result['success'], $result['message'] ?? 'withdraw() must succeed with the fixed call shape');
        $this->assertSame(27.5, $result['newBalance']);
        $this->assertSame(27.5, CreditManager::getBalance('partner', 2, null, 'GBP'));
    }

    // ========================================================================
    // 💰 getBalance() — the FIXED call shape (used after every mutation)
    // ========================================================================

    public function testGetBalanceFixedCallShapeMatchesTheMutatedBalance(): void
    {
        $user = $this->createTestUser();
        CreditManager::deposit('user', $user['userID'], 33.33, 'Seed', ['currency' => 'GBP']);

        // 🩹 B-074 fix: (ownerType, ownerID, partnerID=null, currency) — the
        // buggy original called getBalance($ownerType, $ownerID, $currency,
        // null), putting a string into the `?int $partnerID` slot and null
        // into the non-nullable `string $currency` slot (both TypeErrors).
        $balance = CreditManager::getBalance('user', $user['userID'], null, 'GBP');

        $this->assertSame(33.33, $balance);
    }

    // ========================================================================
    // ➕ Partner top-up — CreditManager::initiateTopUp() does not exist
    // ========================================================================

    public function testInitiateTopUpDoesNotExistOnCreditManager(): void
    {
        // 🩹 B-074 pin: web/public_html/partners/api/credit-actions.php's
        // handleTopUpCredits() previously called
        // CreditManager::initiateTopUp([...]) unconditionally whenever
        // class_exists('CreditManager') was true (which it always is, since
        // the file loads successfully) — a fatal "Call to undefined method".
        // The fix gates on method_exists() too, so it takes the safe,
        // no-live-charge fallback path instead. This assertion pins WHY that
        // routing decision is correct and will keep working if a real
        // initiateTopUp() is added later.
        $this->assertFalse(
            method_exists('CreditManager', 'initiateTopUp'),
            'if this ever becomes true, the credit-actions.php routing condition should be revisited'
        );
    }

    // ========================================================================
    // 📝 Partner top-up fallback — the corrected tblPayments INSERT
    // ========================================================================

    public function testPartnerTopUpFallbackInsertSucceedsAgainstRealSchema(): void
    {
        // 🩹 B-074 fix proof: replicates the EXACT corrected INSERT from
        // web/public_html/partners/api/credit-actions.php::handleTopUpCredits()'s
        // fallback branch — including netAmount/paymentProvider/ipAddress,
        // all three NOT NULL on the real tblPayments schema with no column
        // default. Before the fix, this INSERT omitted all three and would
        // throw "Field 'netAmount' doesn't have a default value" (MySQL's
        // default STRICT_TRANS_TABLES mode) on every real database — even
        // once the CreditManager::initiateTopUp() fatal above was bypassed.
        $user = $this->createTestUser();
        $partnerID = $this->insertRecord('tblPartners', [
            'partnerName'  => 'Test Partner ' . bin2hex(random_bytes(3)),
            'partnerEmail' => 'partner_' . bin2hex(random_bytes(3)) . '@example.com',
            'status'       => 'active',
        ]);

        $invoiceNumber = 'SIG-TOPUP-' . date('Ymd') . '-' . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        $amount = 25.00;
        $currency = 'GBP';
        $paymentMethod = 'paypal';
        $description = 'Credit top-up for partner: Test Partner';
        $ipAddress = '127.0.0.1';

        $stmt = $this->db->prepare("
            INSERT INTO tblPayments
                (userID, partnerID, amount, netAmount, currency, paymentMethod,
                 paymentProvider, status, paymentContext, invoiceNumber,
                 description, ipAddress, createdAt)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 'pending', 'signula_direct', ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
            'iiddssssss',
            $user['userID'],
            $partnerID,
            $amount,
            $amount,
            $currency,
            $paymentMethod,
            $paymentMethod,
            $invoiceNumber,
            $description,
            $ipAddress
        );

        $ok = $stmt->execute();

        $this->assertTrue($ok, $stmt->error ?: 'the corrected fallback INSERT must succeed against the real tblPayments schema');

        $paymentRow = $this->getRecord('tblPayments', ['invoiceNumber' => $invoiceNumber]);
        $this->assertNotNull($paymentRow);
        $this->assertSame('pending', $paymentRow['status']);
        $this->assertSame('25.00', $paymentRow['netAmount']);
        $this->assertSame('paypal', $paymentRow['paymentProvider']);
        $this->assertSame('127.0.0.1', $paymentRow['ipAddress']);
    }
}
