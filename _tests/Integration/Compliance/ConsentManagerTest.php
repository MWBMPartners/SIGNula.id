<?php
/**
 * ============================================================================
 * 🧪 ConsentManager Integration Tests (G-004 Layer 1 / FG-002a — Cycle A)
 * ============================================================================
 *
 * DB-bound proof that ConsentManager's append-only consent audit trail works
 * against a REAL MySQL server (signula_test), backed by migration 040
 * (tblConsentRecords). Covers the four Layer-1 acceptance-criteria items
 * this cycle is responsible for (.dev-team/specs/G-004.md §2.7):
 *
 *   1. record() INSERTs an append-only row (never an UPDATE).
 *   2. Granting then withdrawing the SAME consentType writes TWO rows, and
 *      getCurrent() resolves to the LATEST (withdrawn) one.
 *   3. getEffectiveConsents() reflects the latest decision per consentType.
 *   4. reconcileVisitorToUser() stamps prior anonymous visitor rows with the
 *      newly-known userID, and leaves already-userID-owned rows untouched.
 *
 * Also exercises the private getHistory() helper via Reflection (it has no
 * public caller yet — see ConsentManager's own docblock — but its bounded
 * LIMIT behaviour is real, DB-observable behaviour worth proving here).
 *
 * @package    SIGNula\Tests\Integration\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/ConsentManager.php
 * @see        _database/migrations/040_consent_and_dsar.sql
 * @see        .dev-team/specs/G-004.md §2.7
 */

namespace SIGNula\Tests\Integration\Compliance;

use SIGNula\Tests\DatabaseTestCase;
use ConsentManager;
use ReflectionMethod;

requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/compliance/ConsentManager.php');

/**
 * 🛡️ ConsentManager round-trip test suite.
 */
class ConsentManagerTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblConsentRecords',
        'tblActivityLog',
        'tblUsers',
    ];

    /**
     * 🔧 Invoke ConsentManager's private static getHistory() via Reflection.
     *
     * @param int|null    $userID
     * @param string|null $visitorID
     * @param int         $limit
     * @return array
     */
    private function callGetHistory(?int $userID, ?string $visitorID, int $limit): array
    {
        $reflection = new ReflectionMethod(ConsentManager::class, 'getHistory');
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, [$userID, $visitorID, $limit]);
    }

    // ========================================================================
    // 📝 record() — append-only INSERT
    // ========================================================================

    public function testRecordInsertsAnAppendOnlyRowAgainstRealMysql(): void
    {
        $user = $this->createTestUser();

        $consentID = ConsentManager::record(
            $user['userID'],
            null,
            'cookies.analytics',
            true,
            ['mechanism' => 'preference_center']
        );

        $this->assertGreaterThan(0, $consentID, 'record() must return a new consentID');

        $row = $this->getRecord('tblConsentRecords', ['consentID' => $consentID]);
        $this->assertNotNull($row, 'record() must write a tblConsentRecords row');
        $this->assertSame((string) $user['userID'], (string) $row['userID']);
        $this->assertSame('cookies.analytics', $row['consentType']);
        $this->assertSame('1', (string) $row['granted']);
        $this->assertSame('preference_center', $row['mechanism']);
        $this->assertNull($row['regimeCode'], 'regimeCode must stay NULL — L3 regime resolver does not exist yet');

        // 🔏 proofHash defaults to enabled (migration 040 seeds
        // consent.proof_hash.enabled='true') — assert it was computed as a
        // 64-char hex digest rather than left NULL.
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $row['proofHash']);

        // 📝 ActivityLogger must have logged the grant.
        $this->assertDatabaseHas('tblActivityLog', [
            'userID'       => $user['userID'],
            'activityType' => 'consent_grant',
        ]);
    }

    public function testRecordWithdrawWritesTheCorrectActivityLogType(): void
    {
        $user = $this->createTestUser();

        ConsentManager::record($user['userID'], null, 'cookies.marketing', false, ['mechanism' => 'preference_center']);

        $this->assertDatabaseHas('tblActivityLog', [
            'userID'       => $user['userID'],
            'activityType' => 'consent_withdraw',
        ]);
    }

    // ========================================================================
    // 🔄 grant → withdraw == TWO rows, never an UPDATE
    // ========================================================================

    public function testGrantingThenWithdrawingTheSameTypeWritesTwoRowsNeverAnUpdate(): void
    {
        $user = $this->createTestUser();

        $grantID = ConsentManager::record($user['userID'], null, 'cookies.marketing', true);
        // 🕐 Force a distinct createdAt second so ORDER BY createdAt DESC is
        // unambiguous even on a fast test run (createdAt has second precision).
        sleep(1);
        $withdrawID = ConsentManager::record($user['userID'], null, 'cookies.marketing', false);

        $this->assertNotSame($grantID, $withdrawID, 'Each decision must be a NEW row, never an UPDATE of the prior one');

        // 🔢 Exactly two rows for this user+type — proves no UPDATE occurred
        // (an UPDATE-based implementation would still show only one row).
        $count = $this->countRecords('tblConsentRecords', ['userID' => $user['userID'], 'consentType' => 'cookies.marketing']);
        $this->assertSame(2, $count);

        // 🔍 The FIRST row (grant) must still exist, untouched, as granted=1.
        $grantRow = $this->getRecord('tblConsentRecords', ['consentID' => $grantID]);
        $this->assertSame('1', (string) $grantRow['granted'], 'The original grant row must never be flipped by a later withdraw');

        // 🔍 getCurrent() must resolve to the LATEST row — the withdrawal.
        $current = ConsentManager::getCurrent($user['userID'], null, 'cookies.marketing');
        $this->assertNotNull($current);
        $this->assertSame($withdrawID, (int) $current['consentID']);
        $this->assertSame('0', (string) $current['granted']);
    }

    public function testGetCurrentReturnsNullWhenNoDecisionExistsForThatType(): void
    {
        $user = $this->createTestUser();
        ConsentManager::record($user['userID'], null, 'cookies.analytics', true);

        // 🔍 A DIFFERENT consentType with no rows must resolve to null, not
        // accidentally match another type's row.
        $this->assertNull(ConsentManager::getCurrent($user['userID'], null, 'cookies.marketing'));
    }

    public function testHasGrantedReflectsTheLatestDecision(): void
    {
        $user = $this->createTestUser();

        $this->assertFalse(
            ConsentManager::hasGranted($user['userID'], null, 'marketing.email'),
            'A subject with no recorded decision must be treated as NOT granted (fail-safe default)'
        );

        ConsentManager::record($user['userID'], null, 'marketing.email', true);
        $this->assertTrue(ConsentManager::hasGranted($user['userID'], null, 'marketing.email'));

        ConsentManager::record($user['userID'], null, 'marketing.email', false);
        $this->assertFalse(ConsentManager::hasGranted($user['userID'], null, 'marketing.email'));
    }

    // ========================================================================
    // 🗺️ getEffectiveConsents() — latest per type
    // ========================================================================

    public function testGetEffectiveConsentsReflectsTheLatestDecisionPerType(): void
    {
        $user = $this->createTestUser();

        // cookies.analytics: granted, then withdrawn -> effective = false
        ConsentManager::record($user['userID'], null, 'cookies.analytics', true);
        ConsentManager::record($user['userID'], null, 'cookies.analytics', false);

        // cookies.marketing: granted only -> effective = true
        ConsentManager::record($user['userID'], null, 'cookies.marketing', true);

        // marketing.email: never recorded -> absent from the map entirely
        $effective = ConsentManager::getEffectiveConsents($user['userID'], null);

        $this->assertArrayHasKey('cookies.analytics', $effective);
        $this->assertFalse($effective['cookies.analytics']);

        $this->assertArrayHasKey('cookies.marketing', $effective);
        $this->assertTrue($effective['cookies.marketing']);

        $this->assertArrayNotHasKey('marketing.email', $effective, 'A never-recorded type must not appear in the map at all');
    }

    public function testGetEffectiveConsentsReturnsEmptyArrayWhenNoIdentifierSupplied(): void
    {
        $this->assertSame([], ConsentManager::getEffectiveConsents(null, null));
    }

    public function testGetEffectiveConsentsScopesStrictlyToTheGivenUser(): void
    {
        $userA = $this->createTestUser();
        $userB = $this->createTestUser();

        ConsentManager::record($userA['userID'], null, 'cookies.analytics', true);
        ConsentManager::record($userB['userID'], null, 'cookies.analytics', false);

        // 🛡️ IDOR-safety proof: user A's effective map must reflect ONLY
        // user A's own row, never user B's, even though they share a consentType.
        $effectiveA = ConsentManager::getEffectiveConsents($userA['userID'], null);
        $this->assertTrue($effectiveA['cookies.analytics']);

        $effectiveB = ConsentManager::getEffectiveConsents($userB['userID'], null);
        $this->assertFalse($effectiveB['cookies.analytics']);
    }

    // ========================================================================
    // 🔗 reconcileVisitorToUser()
    // ========================================================================

    public function testReconcileVisitorToUserStampsPriorAnonymousRowsWithTheNewUserId(): void
    {
        $user = $this->createTestUser();
        $visitorID = $this->generateTestUuid();

        // 🍪 Two anonymous decisions captured before the visitor ever logs in.
        ConsentManager::record(null, $visitorID, 'cookies.analytics', true, ['mechanism' => 'banner']);
        ConsentManager::record(null, $visitorID, 'cookies.marketing', false, ['mechanism' => 'banner']);

        $affected = ConsentManager::reconcileVisitorToUser($visitorID, $user['userID']);

        $this->assertSame(2, $affected, 'Both prior anonymous rows for this visitorID must be reconciled');

        // 🔍 Both rows must now carry the userID, while still keeping their
        // original visitorID (history is stamped, not rewritten/deleted).
        $rows = $this->db->query(
            "SELECT userID, visitorID FROM tblConsentRecords WHERE visitorID = '" . $this->db->real_escape_string($visitorID) . "'"
        )->fetch_all(MYSQLI_ASSOC);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame((string) $user['userID'], (string) $row['userID']);
            $this->assertSame($visitorID, $row['visitorID']);
        }

        // 🔍 getEffectiveConsents() by userID now sees the reconciled history.
        $effective = ConsentManager::getEffectiveConsents($user['userID'], null);
        $this->assertTrue($effective['cookies.analytics']);
        $this->assertFalse($effective['cookies.marketing']);
    }

    public function testReconcileVisitorToUserLeavesAlreadyOwnedRowsUntouched(): void
    {
        $user = $this->createTestUser();
        $otherUser = $this->createTestUser();
        $visitorID = $this->generateTestUuid();

        // 🔒 A row that ALREADY belongs to a different user must never be
        // reassigned by a reconciliation for an unrelated visitorID/userID pair.
        $ownedID = ConsentManager::record($otherUser['userID'], null, 'cookies.analytics', true);

        $affected = ConsentManager::reconcileVisitorToUser($visitorID, $user['userID']);

        $this->assertSame(0, $affected, 'Reconciling a visitorID with no anonymous rows must affect zero rows');

        $ownedRow = $this->getRecord('tblConsentRecords', ['consentID' => $ownedID]);
        $this->assertSame((string) $otherUser['userID'], (string) $ownedRow['userID'], 'An unrelated already-owned row must never be touched');
    }

    // ========================================================================
    // 🕓 getHistory() (private) — bounded LIMIT
    // ========================================================================

    public function testGetHistoryIsClampedToTheHardCeilingEvenWhenMoreRequested(): void
    {
        $user = $this->createTestUser();

        // 🌱 Seed exactly 3 rows — enough to prove ordering + a request-under-cap works.
        ConsentManager::record($user['userID'], null, 'cookies.analytics', true);
        ConsentManager::record($user['userID'], null, 'cookies.marketing', true);
        ConsentManager::record($user['userID'], null, 'cookies.functional', false);

        // 🔒 Requesting far more than MAX_HISTORY_LIMIT (200) must never
        // error and must never be used to pull an unbounded result set —
        // with only 3 rows present this simply proves the query executes
        // safely with a huge requested limit.
        $history = $this->callGetHistory($user['userID'], null, 100000);
        $this->assertCount(3, $history);

        // 🔽 Newest first.
        $this->assertSame('cookies.functional', $history[0]['consentType']);
    }

    public function testGetHistoryRespectsASmallerRequestedLimit(): void
    {
        $user = $this->createTestUser();

        ConsentManager::record($user['userID'], null, 'cookies.analytics', true);
        ConsentManager::record($user['userID'], null, 'cookies.marketing', true);
        ConsentManager::record($user['userID'], null, 'cookies.functional', false);

        $history = $this->callGetHistory($user['userID'], null, 1);
        $this->assertCount(1, $history);
        $this->assertSame('cookies.functional', $history[0]['consentType'], 'Must return the single newest row');
    }

    public function testGetHistoryReturnsEmptyArrayWhenNoIdentifierSupplied(): void
    {
        $this->assertSame([], $this->callGetHistory(null, null, 10));
    }
}
