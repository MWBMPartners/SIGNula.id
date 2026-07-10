<?php
/**
 * ============================================================================
 * 🧪 DSARManager Integration Tests (G-004 Layer 1 — Cycle B)
 * ============================================================================
 *
 * DB-bound proof that DSARManager's guarded DSAR lifecycle, identity
 * verification, and (critically) its FULFILMENT DELEGATORS work against a
 * REAL MySQL server (signula_test), backed by migration 040
 * (tblDataSubjectRequests / tblDataSubjectRequestEvents). Covers the G-004
 * Layer 1 Cycle B acceptance criteria (.dev-team/specs/G-004.md §2.7):
 *
 *   3. A user's submitted DSAR gets a correct dueDate.
 *   4. Portability/access fulfilment produces a download via the EXISTING
 *      AccountManager::exportUserData() (linkedExportID populated); erasure
 *      routes through the EXISTING requestAccountDeletion() — no second
 *      export/delete engine exists (grep-style source sentinel below, PLUS
 *      the actual round-trip proof that AccountManager's OWN tables get
 *      written to).
 *   5. Permanent erasure anonymises (not deletes) consent + DSAR rows.
 *   6. Every state change lands in tblDataSubjectRequestEvents.
 *   7. Queue pagination is bounded (no unbounded LIMIT/OFFSET).
 *
 * @package    SIGNula\Tests\Integration\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/DSARManager.php
 * @see        web/private_html/auth/AccountManager.php
 * @see        _database/migrations/040_consent_and_dsar.sql
 * @see        .dev-team/specs/G-004.md §2.3, §2.4, §2.5, §2.7
 */

namespace SIGNula\Tests\Integration\Compliance;

use SIGNula\Tests\DatabaseTestCase;
use DSARManager;
use AccountManager;
use ConsentManager;
use InvalidDSARTransitionException;

requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/compliance/InvalidDSARTransitionException.php');
requireSource('private_html/compliance/DSARManager.php');
requireSource('private_html/compliance/ConsentManager.php');
requireSource('private_html/auth/AccountManager.php');

/**
 * 🛡️ DSARManager round-trip test suite.
 */
class DSARManagerTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test (non-empty is
     *  load-bearing — see truncateTables()'s implicit-COMMIT note in
     *  DatabaseTestCase, which is what makes writes via $this->db visible to
     *  the separate Database:: connection DSARManager/AccountManager use). */
    protected array $truncateTables = [
        'tblDataSubjectRequests',
        'tblDataSubjectRequestEvents',
        'tblConsentRecords',
        'tblDataExportRequests',
        'tblActivityLog',
        'tblUsers',
    ];

    /** @var array<int,string> Export files written to disk by exportUserData()
     *  during a test — cleaned up in tearDown() (no built-in filesystem
     *  cleanup convention exists elsewhere in this suite). */
    private array $exportFilesToCleanUp = [];

    protected function tearDown(): void
    {
        $exportDir = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_private'
            . DIRECTORY_SEPARATOR . 'exports';
        foreach ($this->exportFilesToCleanUp as $fileName) {
            $path = $exportDir . DIRECTORY_SEPARATOR . $fileName;
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    // ========================================================================
    // 🔍 ANTI-DUPLICATION SOURCE SENTINEL (G-004 §2.7 item 4)
    // ========================================================================

    /**
     * 🔍 The anti-duplication invariant, embedded as a self-verifying test:
     * DSARManager must contain NO file-writing / export-generation logic and
     * NO direct `DELETE FROM tblUsers` — it must delegate exclusively to
     * AccountManager's existing engine.
     */
    public function testDsarManagerSourceContainsNoFileWritingOrDirectUserDeletionLogic(): void
    {
        $source = file_get_contents(
            \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'private_html'
            . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'DSARManager.php'
        );
        $this->assertNotFalse($source, 'DSARManager.php must be readable for this sentinel test');

        foreach (['file_put_contents(', 'fopen(', 'fwrite(', 'mkdir('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "DSARManager.php must never write files itself ('{$forbidden}' found) — "
                . "the anti-duplication seam requires it to delegate exclusively to AccountManager"
            );
        }

        $this->assertStringNotContainsString(
            'DELETE FROM tblUsers',
            $source,
            'DSARManager.php must never delete a user row itself — erasure must delegate '
            . 'exclusively to AccountManager::requestAccountDeletion()/processScheduledDeletions()'
        );

        // ✅ Positive proof of delegation — the class DOES call into AccountManager.
        $this->assertStringContainsString('AccountManager::exportUserData(', $source);
        $this->assertStringContainsString('AccountManager::requestAccountDeletion(', $source);
    }

    // ========================================================================
    // 📝 submit()
    // ========================================================================

    public function testSubmitCreatesARequestWithASubmittedEventAndCorrectDueDate(): void
    {
        $user = $this->createTestUser();

        $requestID = DSARManager::submit([
            'userID'      => $user['userID'],
            'requestType' => 'access',
            'details'     => 'Please tell me what you hold about me.',
            'actorType'   => 'user',
        ]);

        $this->assertGreaterThan(0, $requestID);

        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertNotNull($row);
        $this->assertSame((string) $user['userID'], (string) $row['userID']);
        $this->assertSame('access', $row['requestType']);
        $this->assertSame('submitted', $row['status']);
        $this->assertNull($row['regimeCode'], 'regimeCode must stay NULL — L3 regime resolver does not exist yet');

        // ⏰ dueDate = submittedAt + dsar.default_sla_days (default 30, since
        // no test setting override is configured — see getSetting() stub).
        $expectedDueDate = date('Y-m-d', strtotime('+30 days'));
        $this->assertSame($expectedDueDate, $row['dueDate']);

        // 📝 The genesis 'submitted' event row.
        $event = $this->getRecord('tblDataSubjectRequestEvents', ['requestID' => $requestID, 'toStatus' => 'submitted']);
        $this->assertNotNull($event, 'submit() must append the initial submitted event row');
        $this->assertNull($event['fromStatus'], 'The genesis event has no fromStatus');
        $this->assertSame('user', $event['actorType']);

        $this->assertDatabaseHas('tblActivityLog', [
            'userID'       => $user['userID'],
            'activityType' => 'dsar_submitted',
        ]);
    }

    public function testSubmitThrowsForAnUnrecognisedRequestTypeWithoutWritingAnything(): void
    {
        $user = $this->createTestUser();

        try {
            DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'not_a_real_type']);
            $this->fail('submit() must throw InvalidArgumentException for an unrecognised requestType');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, $this->countRecords('tblDataSubjectRequests'), 'No row must be written when validation fails');
        }
    }

    // ========================================================================
    // 🔄 Guarded state machine — full legal chain + illegal rejection
    // ========================================================================

    public function testFullLegalTransitionChainAppendsAnEventRowAtEachStep(): void
    {
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);

        DSARManager::transition($requestID, 'identity_pending', 'system', null, 'step 1');
        DSARManager::transition($requestID, 'verified', 'user', $user['userID'], 'step 2');
        DSARManager::transition($requestID, 'in_progress', 'admin', null, 'step 3');
        DSARManager::transition($requestID, 'fulfilled', 'admin', null, 'step 4');

        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertSame('fulfilled', $row['status']);
        $this->assertNotNull($row['fulfilledAt'], 'fulfilledAt must be stamped on reaching a fulfilment status');

        // 🔢 1 genesis event (submitted) + 4 transitions = 5 rows total.
        $this->assertSame(5, $this->countRecords('tblDataSubjectRequestEvents', ['requestID' => $requestID]));

        $lastEvent = $this->getRecord('tblDataSubjectRequestEvents', ['requestID' => $requestID, 'toStatus' => 'fulfilled']);
        $this->assertSame('in_progress', $lastEvent['fromStatus']);
        $this->assertSame('admin', $lastEvent['actorType']);
        $this->assertSame('step 4', $lastEvent['note']);
    }

    public function testIllegalTransitionThrowsAndIsLoggedToActivityLog(): void
    {
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);

        // 🚫 submitted -> fulfilled is NOT a legal edge (must pass through
        // identity verification and in_progress first).
        $this->expectException(InvalidDSARTransitionException::class);

        try {
            DSARManager::transition($requestID, 'fulfilled', 'admin', null, 'skip the queue');
        } finally {
            // 🔍 Even though the exception propagates, the illegal attempt
            // must be audited BEFORE the throw (see transition()'s docblock).
            $this->assertDatabaseHas('tblActivityLog', [
                'activityType' => 'dsar_illegal_transition',
            ]);

            // 🔒 And status must be UNCHANGED — an illegal transition must
            // never partially apply.
            $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
            $this->assertSame('submitted', $row['status']);
        }
    }

    public function testTerminalStatusRejectsAnyOnwardTransition(): void
    {
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);
        DSARManager::transition($requestID, 'withdrawn', 'user', $user['userID'], 'changed my mind');

        $this->expectException(InvalidDSARTransitionException::class);
        DSARManager::transition($requestID, 'in_progress', 'admin', null, 'reopen attempt');
    }

    // ========================================================================
    // 🔐 Identity verification round trip
    // ========================================================================

    public function testStartThenVerifyIdentitySucceedsAndTransitionsToVerified(): void
    {
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);

        $rawToken = DSARManager::startIdentityVerification($requestID);
        $this->assertNotEmpty($rawToken);

        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertSame('identity_pending', $row['status']);
        $this->assertNotSame($rawToken, $row['verificationToken'], 'The RAW token must never be stored as-is');
        $this->assertSame(hash('sha256', $rawToken), $row['verificationToken']);

        $verified = DSARManager::verifyIdentity($requestID, $rawToken);
        $this->assertTrue($verified);

        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertSame('verified', $row['status']);
        $this->assertNotNull($row['verifiedAt']);
    }

    public function testVerifyIdentityFailsClosedForAWrongToken(): void
    {
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);
        DSARManager::startIdentityVerification($requestID);

        $this->assertFalse(DSARManager::verifyIdentity($requestID, 'totally-the-wrong-token'));

        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertSame('identity_pending', $row['status'], 'A failed verification must not change status');
    }

    public function testVerifyIdentityFailsClosedWhenTheTokenHasExpired(): void
    {
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);
        $rawToken = DSARManager::startIdentityVerification($requestID);

        // ⏰ Simulate the token having been issued 31 minutes ago (TTL default
        // is 30 minutes) by rewriting the identity_pending event's createdAt —
        // this is the TTL anchor verifyIdentity() reads (see its docblock).
        // 🌐 UTC_TIMESTAMP() (not NOW(), which uses this raw mysqli
        // connection's SESSION/SYSTEM time_zone) — DSARManager compares
        // against PHP's date() clock (bootstrap.php pins PHP to UTC), so the
        // simulated timestamp must be computed in UTC too, or a MySQL
        // server whose local time_zone differs from UTC would silently
        // produce a timestamp that looks like it's in the FUTURE relative
        // to PHP's clock instead of 31 minutes in the past.
        $this->db->query(
            "UPDATE tblDataSubjectRequestEvents SET createdAt = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 MINUTE)
             WHERE requestID = " . (int) $requestID . " AND toStatus = 'identity_pending'"
        );

        $this->assertFalse(DSARManager::verifyIdentity($requestID, $rawToken), 'An expired token must fail closed');
    }

    // ========================================================================
    // 📦 FULFILMENT DELEGATION — the anti-duplication seam, round-tripped
    // ========================================================================

    public function testFulfilPortabilityDelegatesToAccountManagerExportUserData(): void
    {
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'portability']);

        $result = DSARManager::fulfilPortability($requestID, $user['userID'], 'password123');

        $this->assertTrue($result['success'] ?? false, 'Export must succeed for a fresh user + correct password: ' . ($result['message'] ?? ''));
        $this->assertNotNull($result['export_id']);

        // 🔗 linkedExportID recorded on the DSAR, and the DSAR is now fulfilled.
        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertSame((string) $result['export_id'], (string) $row['linkedExportID']);
        $this->assertSame('fulfilled', $row['status']);

        // 🔍 THE anti-duplication proof: the export row lives in
        // AccountManager's OWN table (tblDataExportRequests) — DSARManager
        // never wrote it directly, it only recorded the ID AccountManager
        // returned.
        $exportRow = $this->getRecord('tblDataExportRequests', ['exportID' => $result['export_id']]);
        $this->assertNotNull($exportRow, 'The export row must exist in tblDataExportRequests — AccountManager\'s own table');
        $this->assertSame((string) $user['userID'], (string) $exportRow['userID']);
        $this->assertSame('completed', $exportRow['status']);

        // 🧹 Track the real file AccountManager wrote for cleanup.
        if (!empty($exportRow['filePath'])) {
            $this->exportFilesToCleanUp[] = $exportRow['filePath'];
        }

        // 🌉 Bridging proof: request started 'submitted', fulfilPortability()
        // auto-advanced it through 'in_progress' before 'fulfilled'.
        $this->assertDatabaseHas('tblDataSubjectRequestEvents', ['requestID' => $requestID, 'toStatus' => 'in_progress']);
        $this->assertDatabaseHas('tblDataSubjectRequestEvents', ['requestID' => $requestID, 'toStatus' => 'fulfilled']);
    }

    public function testFulfilAccessUsesTheIdenticalExportEngineAsPortability(): void
    {
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);

        $result = DSARManager::fulfilAccess($requestID, $user['userID'], 'password123');

        $this->assertTrue($result['success'] ?? false, (string) ($result['message'] ?? ''));
        $exportRow = $this->getRecord('tblDataExportRequests', ['exportID' => $result['export_id']]);
        $this->assertNotNull($exportRow);

        if (!empty($exportRow['filePath'])) {
            $this->exportFilesToCleanUp[] = $exportRow['filePath'];
        }
    }

    public function testFulfilPortabilityFailureDoesNotCrashAndMovesToAwaitingUser(): void
    {
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'portability']);

        // ❌ Wrong password — AccountManager::exportUserData() returns a
        // failure array; DSARManager must not crash and must surface it.
        $result = DSARManager::fulfilPortability($requestID, $user['userID'], 'totally-the-wrong-password');

        $this->assertFalse($result['success']);
        $this->assertNull($result['export_id']);

        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertSame('awaiting_user', $row['status']);
    }

    public function testFulfilErasureDelegatesToAccountManagerRequestAccountDeletionAndStaysInProgress(): void
    {
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'erasure']);

        $result = DSARManager::fulfilErasure($requestID, $user['userID'], 'password123');

        $this->assertTrue($result['success'] ?? false, (string) ($result['message'] ?? ''));
        $this->assertNotNull($result['scheduled_for']);

        // 🔒 §2.3: erasure stays 'in_progress', NOT 'fulfilled' — the actual
        // erasure is performed later by AccountManager::processScheduledDeletions().
        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertSame('in_progress', $row['status']);
        $this->assertStringContainsString('scheduled_for=', (string) $row['resolutionNote']);

        // 🔍 Proof of delegation: AccountManager's OWN column got set.
        $userRow = $this->getRecord('tblUsers', ['userID' => $user['userID']]);
        $this->assertNotNull($userRow['deletionRequestedAt']);
        $this->assertNotNull($userRow['deletionScheduledFor']);
    }

    public function testFulfilRectificationAppliesOnlyAllowlistedColumns(): void
    {
        $user = $this->createTestUser(['firstName' => 'Original', 'isSuperAdmin' => 0]);
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'rectification']);

        $result = DSARManager::fulfilRectification($requestID, [
            'firstName'    => 'Corrected',
            'isSuperAdmin' => 1, // 🚫 must be rejected — not on the allowlist
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(['firstName'], $result['applied']);
        $this->assertSame(['isSuperAdmin'], $result['rejected']);

        $userRow = $this->getRecord('tblUsers', ['userID' => $user['userID']]);
        $this->assertSame('Corrected', $userRow['firstName']);
        $this->assertSame('0', (string) $userRow['isSuperAdmin'], 'isSuperAdmin must NEVER be writable via rectification');

        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertSame('fulfilled', $row['status']);
        $this->assertNotNull($row['rectifyPayload']);
        $this->assertStringContainsString('Corrected', $row['rectifyPayload']);
    }

    // ========================================================================
    // 🛡️ §2.4 Erasure-safety — anonymise, never delete
    // ========================================================================

    public function testPermanentDeletionAnonymisesConsentAndDsarRowsInsteadOfDeletingThem(): void
    {
        $user = $this->createTestUser();

        $consentID = ConsentManager::record($user['userID'], null, 'cookies.analytics', true, ['mechanism' => 'preference_center']);
        $requestID = DSARManager::submit([
            'userID'         => $user['userID'],
            'requestType'    => 'access',
            'requesterEmail' => 'requester@example.com',
        ]);

        AccountManager::permanentlyDeleteAccount($user['userID']);

        // 🔍 Consent row must SURVIVE, anonymised.
        $consentRow = $this->getRecord('tblConsentRecords', ['consentID' => $consentID]);
        $this->assertNotNull($consentRow, 'The consent row must NOT be deleted by permanentlyDeleteAccount()');
        $this->assertNull($consentRow['userID']);
        $this->assertNull($consentRow['ipAddress']);
        $this->assertSame('REDACTED', $consentRow['userAgent']);

        // 🔍 DSAR row must SURVIVE, anonymised.
        $dsarRow = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertNotNull($dsarRow, 'The DSAR row must NOT be deleted by permanentlyDeleteAccount()');
        $this->assertNull($dsarRow['userID']);
        $this->assertNull($dsarRow['requesterEmail']);
        $this->assertNull($dsarRow['ipAddress']);

        // 🔍 requestType/status (non-PII facts) must be UNCHANGED — only PII
        // columns are nulled; the fact of the request survives for audit.
        $this->assertSame('access', $dsarRow['requestType']);
        $this->assertSame('submitted', $dsarRow['status']);
    }

    // ========================================================================
    // 📋 getQueue() — bounded pagination
    // ========================================================================

    public function testGetQueueNeverErrorsOnAHugeRequestedLimitOrNegativeOffset(): void
    {
        $user = $this->createTestUser();
        DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);
        DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'portability']);
        DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'erasure']);

        // 🔒 A huge requested limit and a negative offset must never error
        // and must never be used to build an unbounded query.
        $queue = DSARManager::getQueue([], 999999, -50);
        $this->assertCount(3, $queue);
    }

    public function testGetQueueFiltersByStatusAndRequestType(): void
    {
        $user = $this->createTestUser();
        $accessID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);
        DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'erasure']);
        DSARManager::transition($accessID, 'withdrawn', 'user', $user['userID'], 'no longer needed');

        $withdrawn = DSARManager::getQueue(['status' => 'withdrawn']);
        $this->assertCount(1, $withdrawn);
        $this->assertSame($accessID, (int) $withdrawn[0]['requestID']);

        $erasureOnly = DSARManager::getQueue(['requestType' => 'erasure']);
        $this->assertCount(1, $erasureOnly);
        $this->assertSame('erasure', $erasureOnly[0]['requestType']);
    }

    public function testGetQueueOverdueFilterExcludesTerminalStatuses(): void
    {
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);

        // ⏰ Force the dueDate into the past directly (submit() always computes
        // a future date from the SLA setting).
        $this->db->query("UPDATE tblDataSubjectRequests SET dueDate = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE requestID = " . (int) $requestID);

        $overdue = DSARManager::getQueue(['overdue' => true]);
        $this->assertCount(1, $overdue);

        // 🏁 Once terminal (withdrawn), it must drop out of the overdue view
        // even though its dueDate is still in the past.
        DSARManager::transition($requestID, 'withdrawn', 'user', $user['userID'], 'closing it out');
        $overdueAfterClose = DSARManager::getQueue(['overdue' => true]);
        $this->assertCount(0, $overdueAfterClose);
    }

    // ========================================================================
    // 📊 getComplianceReport()
    // ========================================================================

    public function testGetComplianceReportCountsByTypeAndStatusWithinRange(): void
    {
        $user = $this->createTestUser();
        DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);
        DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'erasure']);

        $today = date('Y-m-d');
        $report = DSARManager::getComplianceReport($today, $today);

        $this->assertSame($today, $report['from']);
        $this->assertSame($today, $report['to']);
        $this->assertGreaterThanOrEqual(2, count($report['countsByTypeAndStatus']));
        $this->assertArrayHasKey('overdue', $report);
        $this->assertIsArray($report['overdue']);
    }
}
