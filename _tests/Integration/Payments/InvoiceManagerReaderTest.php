<?php
/**
 * ============================================================================
 * 🧪 InvoiceManager Reader Column-Mapping Tests (B-070 fix proof)
 * ============================================================================
 *
 * DB-bound proof that InvoiceManager's READERS — generatePDF()/renderHTML()/
 * sendInvoiceEmail() and every other method that reads a raw tblInvoices
 * row — now use the REAL column names.
 *
 * `InvoiceManager::createInvoice()`'s INSERT was corrected in G-002 S2 to
 * target the real columns (billedToName/billedToEmail/billedToAddress/
 * billedToVATNumber/total — see that method's own "Resolve the billed-to
 * party" comment), but every READER still referenced the OLD, non-existent
 * keys (billingName/billingEmail/billingAddress/vatNumber/totalAmount) plus
 * a phantom `companyName` column that has never existed. Reading a missing
 * array key in PHP is not fatal (it's just always empty/null), so an invoice
 * would "render" with every billing detail and the total silently blank —
 * this suite proves the fix by asserting the rendered output actually
 * CONTAINS the billed-to name and the total.
 *
 * It also proves the SAME bug class in getUserInvoices()/getPartnerInvoices()
 * (which SELECT the phantom columns directly — a genuine SQL "Unknown
 * column" failure, not just a blank read) and getInvoiceStats() (SUM/AVG over
 * the phantom `totalAmount` column), and that reissueInvoice() carries the
 * billed-to details forward correctly without double-appending a company
 * name that no longer has its own source column.
 *
 * @package    SIGNula\Tests\Integration\Payments
 * @version    2.9.0-beta
 * @see        web/private_html/payments/InvoiceManager.php
 * @see        _database/migrations/012_payment_expansion.sql (the real tblInvoices schema)
 */

namespace SIGNula\Tests\Integration\Payments;

use SIGNula\Tests\DatabaseTestCase;
use InvoiceManager;

requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/payments/InvoiceManager.php');

// 📦 generatePDF() references LIB_DIR (defined by web/_config/config.php in
// production — the test bootstrap does not load that file). Mirrors the
// guarded constant definitions in JwtAuthEndpointsTest.php's subprocess
// harness so generatePDF() can at least reach its TCPDFLoader existence
// check without an "Undefined constant" fatal.
if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web');
}
if (!defined('LIB_DIR')) {
    define('LIB_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . '_lib');
}

/**
 * 🧾 InvoiceManager reader test suite.
 */
class InvoiceManagerReaderTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblInvoices',
        'tblActivityLog',
        'tblUsers',
        'tblPartners',
    ];

    private function createInvoiceFixture(array $options = []): array
    {
        $user = $this->createTestUser();

        $result = InvoiceManager::createInvoice(
            $user['userID'],
            'subscription',
            [
                ['description' => 'Pro Plan (Monthly)', 'quantity' => 1, 'unitPrice' => 29.99, 'total' => 29.99],
            ],
            array_merge([
                'billingName'  => 'Jane Doe',
                'billingEmail' => 'jane@example.com',
                'taxRate'      => 20.00,
                'autoIssue'    => true,
            ], $options)
        );

        $this->assertTrue($result['success'], $result['message'] ?? 'createInvoice() must succeed');

        return ['user' => $user, 'result' => $result];
    }

    // ========================================================================
    // 🌐 renderHTML() — the primary B-070 proof
    // ========================================================================

    public function testRenderHTMLContainsBilledToNameAndTotalWithoutPhpWarnings(): void
    {
        $fixture = $this->createInvoiceFixture();
        $invoiceID = $fixture['result']['invoiceID'];

        // 🔬 Capture any PHP warning/notice (e.g. "Undefined array key") that
        // would previously have been silently swallowed by array-access —
        // beStrictAboutOutputDuringTests is off for this suite, so we
        // install our own error handler to actually fail on one.
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED);

        $html = InvoiceManager::renderHTML($invoiceID);

        restore_error_handler();

        $this->assertSame([], $warnings, 'renderHTML() must not trigger any PHP warning/notice while reading the invoice row');
        $this->assertNotSame('', $html, 'renderHTML() must return a non-empty document');

        // 🩹 B-070 fix proof: the billed-to name (folded from 'billingName')
        // and the total (real column `total`) must actually appear in the
        // rendered output — before the fix these were silently blank.
        $this->assertStringContainsString('Jane Doe', $html, 'billedToName must render (was reading the non-existent "billingName" key)');
        $this->assertStringContainsString(
            number_format((float)$fixture['result']['totalAmount'], 2),
            $html,
            'the invoice total must render (was reading the non-existent "totalAmount" key instead of "total")'
        );
        $this->assertStringContainsString($fixture['result']['invoiceNumber'], $html);
    }

    public function testRenderHTMLIncludesBilledToAddressAndVatNumber(): void
    {
        $fixture = $this->createInvoiceFixture([
            'vatNumber'      => 'GB123456789',
            'billingAddress' => ['line1' => '221B Baker Street', 'city' => 'London', 'postcode' => 'NW1 6XE', 'country' => 'UK'],
        ]);

        $html = InvoiceManager::renderHTML($fixture['result']['invoiceID']);

        $this->assertStringContainsString('GB123456789', $html, 'billedToVATNumber must render (was reading the non-existent "vatNumber" key)');
        $this->assertStringContainsString('221B Baker Street', $html, 'billedToAddress must decode/render (was reading the non-existent "billingAddress" key)');
    }

    // ========================================================================
    // 📄 generatePDF() — must not FATAL even without the TCPDF library
    // ========================================================================

    public function testGeneratePDFFailsGracefullyNotFatallyWhenTcpdfUnavailable(): void
    {
        // 🔬 This test environment does not vendor the actual TCPDF library
        // (only its loader wrapper — the library itself is a Dreamhost
        // shared-hosting manual-upload dependency per this project's own
        // CLAUDE.md). generatePDF() must still return a graceful failure
        // array, not throw/fatal, regardless of the B-070 reader fixes below
        // it in the method body.
        $fixture = $this->createInvoiceFixture();

        $result = InvoiceManager::generatePDF($fixture['result']['invoiceID']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        // Either TCPDF is available in this environment (renders) or it
        // isn't (graceful failure) — both are acceptable; a fatal error
        // would have thrown and failed this test before reaching here.
    }

    // ========================================================================
    // 📋 getUserInvoices() / getPartnerInvoices() — real SQL, real columns
    // ========================================================================

    public function testGetUserInvoicesReturnsRealColumnsWithoutSqlError(): void
    {
        $fixture = $this->createInvoiceFixture();

        // 🩹 B-070 fix proof: before the fix, this SELECT referenced
        // `totalAmount`/`billingName`/`companyName` — none of which exist —
        // and would throw an "Unknown column" mysqli_sql_exception.
        $invoices = InvoiceManager::getUserInvoices($fixture['user']['userID']);

        $this->assertCount(1, $invoices);
        $this->assertArrayHasKey('total', $invoices[0]);
        $this->assertArrayHasKey('billedToName', $invoices[0]);
        $this->assertArrayNotHasKey('totalAmount', $invoices[0]);
        $this->assertSame('Jane Doe', $invoices[0]['billedToName']);
        $this->assertSame(number_format((float)$fixture['result']['totalAmount'], 2), number_format((float)$invoices[0]['total'], 2));
    }

    public function testGetPartnerInvoicesReturnsRealColumnsWithoutSqlError(): void
    {
        $partnerID = $this->insertRecord('tblPartners', [
            'partnerName'  => 'Test Partner ' . bin2hex(random_bytes(3)),
            'partnerEmail' => 'partner_' . bin2hex(random_bytes(3)) . '@example.com',
            'isActive'     => 1,
        ]);

        $fixture = $this->createInvoiceFixture(['partnerID' => $partnerID]);

        $invoices = InvoiceManager::getPartnerInvoices($partnerID);

        $this->assertCount(1, $invoices);
        $this->assertArrayHasKey('total', $invoices[0]);
        $this->assertArrayHasKey('billedToName', $invoices[0]);
    }

    // ========================================================================
    // 📊 getInvoiceStats() — real SQL aggregates
    // ========================================================================

    public function testGetInvoiceStatsAggregatesTheRealTotalColumnWithoutSqlError(): void
    {
        $fixture = $this->createInvoiceFixture();
        InvoiceManager::markPaid($fixture['result']['invoiceID'], 'TXN-STATS-1');

        // 🩹 B-070 fix proof: before the fix, the SUM/AVG expressions
        // referenced `totalAmount`, which does not exist, and would throw.
        $stats = InvoiceManager::getInvoiceStats(30);

        $this->assertSame(1, $stats['totalPaid']);
        $this->assertEqualsWithDelta((float)$fixture['result']['totalAmount'], $stats['revenueTotal'], 0.001);
    }

    // ========================================================================
    // ✅ markPaid() — reads the real `total` column for logging/webhooks
    // ========================================================================

    public function testMarkPaidLogsTheRealTotalWithoutPhpWarnings(): void
    {
        $fixture = $this->createInvoiceFixture();

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED);

        $result = InvoiceManager::markPaid($fixture['result']['invoiceID'], 'TXN-MARKPAID-1');

        restore_error_handler();

        $this->assertTrue($result['success']);
        $this->assertSame([], $warnings, 'markPaid() must not trigger a PHP warning reading $invoice["totalAmount"] (real column is "total")');

        $logRow = $this->getRecord('tblActivityLog', [
            'userID'       => $fixture['user']['userID'],
            'activityType' => 'invoice_paid',
        ]);
        $this->assertNotNull($logRow);
        $detail = json_decode((string)$logRow['metadata'], true);
        $this->assertEqualsWithDelta((float)$fixture['result']['totalAmount'], (float)$detail['totalAmount'], 0.001);
    }

    // ========================================================================
    // 🔄 reissueInvoice() — carries billed-to details forward correctly
    // ========================================================================

    public function testReissueInvoiceCarriesBilledToDetailsForwardWithoutDoubleAppendingCompanyName(): void
    {
        $fixture = $this->createInvoiceFixture([
            'companyName' => 'Acme Ltd',
            'vatNumber'   => 'GB999888777',
        ]);

        $original = InvoiceManager::getInvoice($fixture['result']['invoiceID']);
        // Company name is folded into billedToName ONCE at creation.
        $this->assertSame('Jane Doe (Acme Ltd)', $original['billedToName']);

        $reissue = InvoiceManager::reissueInvoice($fixture['result']['invoiceID']);

        $this->assertTrue($reissue['success'], $reissue['message'] ?? 'reissueInvoice() must succeed');

        $newInvoice = InvoiceManager::getInvoice($reissue['invoiceID']);

        // 🩹 B-070 fix proof: before the fix, reissueInvoice() read
        // $original['billingName']/['companyName'] (both non-existent on a
        // raw row), so the reissued invoice's billed-to fields were blank —
        // and even if a companyName column HAD existed, folding it in again
        // here would have double-appended "(Acme Ltd) (Acme Ltd)".
        $this->assertSame('Jane Doe (Acme Ltd)', $newInvoice['billedToName']);
        $this->assertSame('jane@example.com', $newInvoice['billedToEmail']);
        $this->assertSame('GB999888777', $newInvoice['billedToVATNumber']);
    }
}
