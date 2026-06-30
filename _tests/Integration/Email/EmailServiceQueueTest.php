<?php
/**
 * ============================================================================
 * 🧪 EmailService Queue Characterization Tests (STABILIZE-2 safety net)
 * ============================================================================
 *
 * Pins the DB-coupled EmailService behaviour the auth/OAuth builds rely on when
 * they add new transactional emails:
 *   • queueEmail()        — what gets persisted into tblEmailQueue (incl. the
 *                           lack of recipient validation — anchors B-012)
 *   • sendTemplateEmail() — template lookup + dispatch + missing-template path
 *
 * These write to / read from tblEmailQueue + tblEmailTemplates, so they live
 * under Integration/ and follow DatabaseTestCase. Without a live signula_test
 * DB they error/skip like the other Integration suites (expected). The pure
 * variable-substitution / XSS-escaping logic is pinned separately in
 * Unit/Email/EmailServiceVariableTest.php.
 *
 * ⚠️ NEEDS-LEAD-REVIEW (documented, NOT fixed here)
 * --------------------------------------------------
 *  • NO RECIPIENT VALIDATION. queueEmail() never validates $recipientEmail — a
 *    syntactically invalid address (or a header-injection payload containing
 *    CR/LF) is inserted verbatim into tblEmailQueue. Pinned by
 *    testQueueEmailAcceptsMalformedRecipient(). This is the B-012 finding; the
 *    fix belongs in its own cycle.
 *
 * @package    SIGNula\Tests\Integration\Email
 * @version    2.7.0-beta
 * @see        web/private_html/email/EmailService.php
 */

namespace SIGNula\Tests\Integration\Email;

use SIGNula\Tests\DatabaseTestCase;

// 📦 Source classes. ErrorLogger is referenced in the catch blocks.
requireSource('_config/database.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/email/EmailService.php');

/**
 * EmailService Queue Characterization Test Suite
 */
class EmailServiceQueueTest extends DatabaseTestCase
{
    /**
     * Tables touched by this suite (rolled back per test).
     */
    protected array $truncateTables = ['tblEmailQueue', 'tblEmailTemplates'];

    // ========================================================================
    // 📬 queueEmail() — persistence + recipient handling (B-012)
    // ========================================================================

    /**
     * A well-formed email is queued with status 'pending' and stored verbatim.
     */
    public function testQueueEmailPersistsValidRecipient(): void
    {
        $to = 'valid.user@example.com';

        $ok = \EmailService::queueEmail(
            $to,
            'Subject line',
            '<p>HTML body</p>',
            'Text body'
        );

        $this->assertTrue($ok, 'queueEmail() should return true on a successful insert');

        $row = $this->getRecord('tblEmailQueue', ['recipientEmail' => $to]);
        $this->assertNotNull($row, 'A queue row should exist for the recipient');
        $this->assertSame('Subject line', $row['subject']);
        $this->assertSame('pending', $row['status']);
    }

    /**
     * ⚠️ B-012 CHARACTERIZATION: a malformed recipient is accepted and queued.
     *
     * queueEmail() does no FILTER_VALIDATE_EMAIL / sanitizeEmail check, so an
     * invalid address is inserted verbatim. The later fix will make this case
     * fail (return false / not insert); this test must then be updated.
     */
    public function testQueueEmailAcceptsMalformedRecipient(): void
    {
        $malformed = 'not-a-valid-email-address';

        $ok = \EmailService::queueEmail(
            $malformed,
            'Subject',
            '<p>x</p>',
            'x'
        );

        // CURRENT behaviour: returns true and persists the bad address.
        $this->assertTrue($ok, 'CURRENT behaviour: malformed recipient is accepted (no validation)');
        $row = $this->getRecord('tblEmailQueue', ['recipientEmail' => $malformed]);
        $this->assertNotNull($row, 'CURRENT behaviour: malformed recipient is stored verbatim in the queue');
        $this->assertSame($malformed, $row['recipientEmail']);
    }

    /**
     * CC / BCC arrays are JSON-encoded into their columns; empty stays NULL.
     */
    public function testQueueEmailEncodesCcAndBccAsJson(): void
    {
        $to = 'recipient@example.com';

        \EmailService::queueEmail(
            $to,
            'Subject',
            '<p>x</p>',
            'x',
            null,
            null,
            null,
            null,
            null,
            5,
            null,
            ['cc1@example.com', 'cc2@example.com'], // cc
            [],                                      // bcc (empty → NULL)
            []
        );

        $row = $this->getRecord('tblEmailQueue', ['recipientEmail' => $to]);
        $this->assertNotNull($row);

        $decodedCc = json_decode((string) $row['ccRecipients'], true);
        $this->assertSame(['cc1@example.com', 'cc2@example.com'], $decodedCc);
        // 📝 Empty BCC is stored as NULL, not an empty JSON array.
        $this->assertNull($row['bccRecipients']);
    }

    // ========================================================================
    // 📧 sendTemplateEmail() — lookup + dispatch
    // ========================================================================

    /**
     * Unknown template key → returns false and queues nothing.
     */
    public function testSendTemplateEmailReturnsFalseForUnknownTemplate(): void
    {
        $before = $this->countRecords('tblEmailQueue');

        $ok = \EmailService::sendTemplateEmail(
            'user@example.com',
            'this_template_does_not_exist',
            ['name' => 'X']
        );

        $this->assertFalse($ok, 'Missing template should cause sendTemplateEmail() to return false');
        $this->assertSame($before, $this->countRecords('tblEmailQueue'), 'No email should be queued for a missing template');
    }

    /**
     * Active template → variables substituted (HTML escaped) and email queued.
     */
    public function testSendTemplateEmailRendersAndQueues(): void
    {
        // Seed an active template with both HTML and text bodies.
        $this->insertRecord('tblEmailTemplates', [
            'templateKey' => 'characterization_tpl',
            'subject'     => 'Hi {{name}}',
            'bodyHTML'    => '<p>Welcome {{name}}</p>',
            'bodyText'    => 'Welcome {{name}}',
            'isActive'    => 1,
            'createdAt'   => date('Y-m-d H:i:s'),
        ]);

        $to = 'render@example.com';

        $ok = \EmailService::sendTemplateEmail(
            $to,
            'characterization_tpl',
            ['name' => '<b>Sam</b>'] // contains markup to prove HTML escaping
        );

        $this->assertTrue($ok, 'sendTemplateEmail() should queue successfully for an active template');

        $row = $this->getRecord('tblEmailQueue', ['recipientEmail' => $to]);
        $this->assertNotNull($row);

        // 📝 Subject is plain-text context → raw markup kept.
        $this->assertSame('Hi <b>Sam</b>', $row['subject']);
        // 🔐 HTML body context → markup escaped (C2 protection carried end-to-end).
        $this->assertStringContainsString('Welcome &lt;b&gt;Sam&lt;/b&gt;', $row['bodyHTML']);
        $this->assertStringNotContainsString('<b>Sam</b>', (string) $row['bodyHTML']);
        // 📝 Text body context → raw markup kept.
        $this->assertSame('Welcome <b>Sam</b>', $row['bodyText']);
    }
}
