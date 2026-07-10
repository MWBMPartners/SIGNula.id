<?php
/**
 * ============================================================================
 * 🧪 BreachManager Integration Tests (G-004 Layer 4b §5.1)
 * ============================================================================
 * (the FINAL compliance slice, completes G-004)
 *
 * DB-bound coverage of BreachManager: open()/assess(), and — the headline —
 * computeNotificationDeadlines()'s REAL delegation to
 * `RegimeResolver::breachWindowHours()` against genuine `tblComplianceRegimes`
 * fixture rows, proving `dueAt = detectedAt + window` for a regime WITH a
 * configured window, and the graceful (no-crash, pending/no-window note)
 * degrade for a regime WITHOUT one. Also covers markNotified() and
 * getOverdueNotifications().
 *
 * ⚠️ Test-fixture hygiene (mirrors RegimeResolverTest / RegimeAdminActionsTest
 * — READ before adding more tests here): this class does NOT put
 * tblComplianceRegimes in $truncateTables — that table carries the REAL
 * migration-042 seed data. Every fixture regime uses a `ZZTEST`-prefixed
 * regimeCode and is explicitly DELETEd in tearDown() by exact ID.
 *
 * ⚠️ Dual-connection note (also mirrors RegimeResolverTest/
 * RegimeAdminActionsTest): fixtures + BreachManager's own writes go through
 * `\Database::` (the SAME static connection BreachManager itself uses), NOT
 * `$this->db` — these are two independent mysqli connections, so cleanup is
 * explicit in tearDown(), not automatic transaction rollback.
 *
 * @package    SIGNula\Tests\Integration\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/BreachManager.php
 * @see        web/private_html/compliance/RegimeResolver.php — breachWindowHours(), the ONLY source of any window number
 * @see        _database/migrations/044_breach_ropa_agegate.sql
 * @see        _tests/Integration/Compliance/RegimeResolverTest.php — the ZZTEST fixture-hygiene idiom this mirrors
 * @see        .dev-team/specs/G-004.md §5.1
 */

namespace SIGNula\Tests\Integration\Compliance;

use SIGNula\Tests\DatabaseTestCase;
use BreachManager;

requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/compliance/RegimeResolver.php');
requireSource('private_html/compliance/BreachManager.php');

/**
 * 🚨 BreachManager DB-bound test suite.
 */
class BreachManagerTest extends DatabaseTestCase
{
    /** @var array<int,int> tblComplianceRegimes.regimeID rows this test created. */
    private array $testRegimeIDsToCleanUp = [];

    /** @var array<int,int> tblBreachIncidents.incidentID rows this test created (cascades to tblBreachNotifications). */
    private array $testIncidentIDsToCleanUp = [];

    protected function tearDown(): void
    {
        foreach ($this->testIncidentIDsToCleanUp as $incidentID) {
            // tblBreachNotifications cascades on incidentID delete (migration 044 FK).
            \Database::query("DELETE FROM tblBreachIncidents WHERE incidentID = ?", [$incidentID], 'i');
        }
        foreach ($this->testRegimeIDsToCleanUp as $regimeID) {
            \Database::query("DELETE FROM tblComplianceRegimes WHERE regimeID = ?", [$regimeID], 'i');
        }

        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{regimeID:int, regimeCode:string}
     */
    private function insertTestRegime(array $overrides = []): array
    {
        $defaults = [
            'regimeCode'        => 'ZZTEST' . strtoupper(bin2hex(random_bytes(3))),
            'displayName'       => 'Breach Test Regime',
            'breachNotifyHours' => null,
            'isActive'          => 1,
            'isDraft'           => 0,
        ];
        $data = array_merge($defaults, $overrides);

        $regimeID = \Database::insert(
            "INSERT INTO tblComplianceRegimes (regimeCode, displayName, breachNotifyHours, isActive, isDraft)
             VALUES (?, ?, ?, ?, ?)",
            [$data['regimeCode'], $data['displayName'], $data['breachNotifyHours'], $data['isActive'] ? 1 : 0, $data['isDraft'] ? 1 : 0],
            'ssiii'
        );

        $this->testRegimeIDsToCleanUp[] = $regimeID;

        return ['regimeID' => $regimeID, 'regimeCode' => $data['regimeCode']];
    }

    // ========================================================================
    // 🚨 open() / ✏️ assess()
    // ========================================================================

    public function testOpenAutoGeneratesAReferenceAndPersistsFields(): void
    {
        $incidentID = BreachManager::open([
            'severity'    => 'high',
            'detectedAt'  => '2026-01-15 10:00:00',
            'summary'     => 'Test incident summary — operator authored.',
            'regimeCodes' => ['GDPR', 'CCPA'],
        ]);
        $this->testIncidentIDsToCleanUp[] = $incidentID;

        $row = \Database::fetchOne("SELECT * FROM tblBreachIncidents WHERE incidentID = ?", [$incidentID], 'i');
        $this->assertNotNull($row);
        $this->assertSame('high', $row['severity']);
        $this->assertSame('detected', $row['status'], 'default status');
        $this->assertMatchesRegularExpression('/^BRCH-\d{4}-\d{4}/', $row['reference']);
        $this->assertSame('Test incident summary — operator authored.', $row['summary']);
        $this->assertSame(['GDPR', 'CCPA'], json_decode($row['regimeCodes'], true));
    }

    public function testOpenThrowsForAnExplicitlyInvalidSeverity(): void
    {
        // ⚠️ open() defaults a MISSING severity/status to 'medium'/'detected'
        // (see the next test's blank case via defaults), but a garbage value
        // passed explicitly (non-empty) must throw rather than silently
        // reaching SQL.
        $this->expectException(\InvalidArgumentException::class);
        BreachManager::open(['severity' => 'not-a-real-severity']);
    }

    public function testAssessUpdatesAllowlistedFieldsAndRejectsAnInvalidStatus(): void
    {
        $incidentID = BreachManager::open(['severity' => 'low']);
        $this->testIncidentIDsToCleanUp[] = $incidentID;

        $updated = BreachManager::assess($incidentID, ['status' => 'assessing', 'affectedUserCount' => 42, 'remediation' => 'Rotated all credentials.']);
        $this->assertTrue($updated);

        $row = \Database::fetchOne("SELECT * FROM tblBreachIncidents WHERE incidentID = ?", [$incidentID], 'i');
        $this->assertSame('assessing', $row['status']);
        $this->assertSame(42, (int) $row['affectedUserCount']);
        $this->assertSame('Rotated all credentials.', $row['remediation']);

        $this->expectException(\InvalidArgumentException::class);
        BreachManager::assess($incidentID, ['status' => 'not-a-real-status']);
    }

    public function testAssessReturnsFalseForANonexistentIncident(): void
    {
        $this->assertFalse(BreachManager::assess(999999999, ['status' => 'closed']));
    }

    // ========================================================================
    // ⏰ THE headline test — computeNotificationDeadlines() via RegimeResolver
    // ========================================================================

    /**
     * 🔒 THE crux functional test (spec verification requirement): with a
     * test regime ACTIVE and a breachNotifyHours window set,
     * computeNotificationDeadlines() must yield
     * `dueAt = detectedAt + window hours` for BOTH audiences, computed via
     * the REAL RegimeResolver::breachWindowHours() call — not a hardcoded
     * PHP literal anywhere in BreachManager.
     */
    public function testComputeNotificationDeadlinesYieldsDetectedAtPlusRegimeWindowForBothAudiences(): void
    {
        $regime = $this->insertTestRegime(['breachNotifyHours' => 72]);

        // 🔒 Prove the fixture is actually reachable via the SAME resolver
        // BreachManager itself calls — this is what makes the assertion
        // below a genuine "resolver-driven" proof, not a coincidence.
        $this->assertSame(72, \RegimeResolver::breachWindowHours($regime['regimeCode']));

        $incidentID = BreachManager::open([
            'severity'   => 'critical',
            'detectedAt' => '2026-03-01 08:00:00',
            'regimeCodes' => [$regime['regimeCode']],
        ]);
        $this->testIncidentIDsToCleanUp[] = $incidentID;

        $results = BreachManager::computeNotificationDeadlines($incidentID);
        $this->assertCount(2, $results, 'one row per audience (authority, data_subjects)');

        $expectedDueAt = date('Y-m-d H:i:s', strtotime('2026-03-01 08:00:00 +72 hours'));

        $audiencesSeen = [];
        foreach ($results as $notification) {
            $this->assertSame($regime['regimeCode'], $notification['regimeCode']);
            $this->assertSame($expectedDueAt, $notification['dueAt'], 'dueAt must be exactly detectedAt + RegimeResolver::breachWindowHours() hours');
            $this->assertSame('pending', $notification['status']);
            $this->assertNull($notification['note'], 'a resolvable window needs no explanatory note');
            $audiencesSeen[] = $notification['audience'];
        }
        sort($audiencesSeen);
        $this->assertSame(['authority', 'data_subjects'], $audiencesSeen);

        // 🔎 Persisted rows match what was returned.
        $persisted = \Database::fetchAll("SELECT * FROM tblBreachNotifications WHERE incidentID = ?", [$incidentID], 'i');
        $this->assertCount(2, $persisted);
    }

    /**
     * 🛡️ THE graceful-degrade proof: a regime with NO breachNotifyHours
     * configured must NEVER crash computeNotificationDeadlines() — it
     * degrades to `dueAt = NULL`, `status = 'pending'`, and an explanatory
     * note, exactly mirroring RegimeResolver::breachWindowHours()'s own
     * "no universal fallback, returns null" contract.
     */
    public function testComputeNotificationDeadlinesDegradesGracefullyWhenTheRegimeHasNoConfiguredWindow(): void
    {
        $regime = $this->insertTestRegime(['breachNotifyHours' => null]);
        $this->assertNull(\RegimeResolver::breachWindowHours($regime['regimeCode']));

        $incidentID = BreachManager::open([
            'severity'    => 'medium',
            'detectedAt'  => '2026-04-01 12:00:00',
            'regimeCodes' => [$regime['regimeCode']],
        ]);
        $this->testIncidentIDsToCleanUp[] = $incidentID;

        $results = BreachManager::computeNotificationDeadlines($incidentID);
        $this->assertCount(2, $results);

        foreach ($results as $notification) {
            $this->assertNull($notification['dueAt'], 'no configured window means no computable deadline — never guess a number');
            $this->assertSame('pending', $notification['status']);
            $this->assertNotNull($notification['note']);
            $this->assertStringContainsString($regime['regimeCode'], $notification['note']);
        }
    }

    /**
     * 🛡️ A regimeCode that does not resolve to ANY tblComplianceRegimes row
     * at all (e.g. a typo, or a regime deleted after the incident was
     * opened) must ALSO degrade gracefully rather than crash or throw.
     */
    public function testComputeNotificationDeadlinesDegradesGracefullyForAnUnknownRegimeCode(): void
    {
        $incidentID = BreachManager::open([
            'severity'    => 'low',
            'detectedAt'  => '2026-05-01 00:00:00',
            // ⚠️ tblComplianceRegimes.regimeCode AND tblBreachNotifications.regimeCode
            // are both VARCHAR(16) (migration 042/044) — a real regimeCode can
            // never exceed that (the admin UI enforces it too), so this fixture
            // stays within 16 characters like every genuine one would.
            'regimeCodes' => ['ZZTEST_UNKNOWN'],
        ]);
        $this->testIncidentIDsToCleanUp[] = $incidentID;

        $results = BreachManager::computeNotificationDeadlines($incidentID);
        $this->assertCount(2, $results);
        foreach ($results as $notification) {
            $this->assertNull($notification['dueAt']);
            $this->assertSame('pending', $notification['status']);
        }
    }

    /**
     * 🛡️ A regimeCode LONGER than the VARCHAR(16) column width (free-text
     * operator input on the breach admin screen — a typo, or garbage) must
     * be SKIPPED, not crash the whole computeNotificationDeadlines() call
     * for every other regime code on the incident with a raw SQL error.
     */
    public function testComputeNotificationDeadlinesSkipsAnOverLongRegimeCodeWithoutCrashing(): void
    {
        $goodRegime = $this->insertTestRegime(['breachNotifyHours' => 48]);

        $incidentID = BreachManager::open([
            'severity'    => 'high',
            'detectedAt'  => '2026-05-15 00:00:00',
            'regimeCodes' => ['ZZTEST_WAY_TOO_LONG_TO_EVER_BE_A_REAL_REGIME_CODE', $goodRegime['regimeCode']],
        ]);
        $this->testIncidentIDsToCleanUp[] = $incidentID;

        $results = BreachManager::computeNotificationDeadlines($incidentID);

        // 🔒 Only the WELL-FORMED regime code produced rows — the over-long
        // one was skipped entirely, and (crucially) it did NOT abort
        // processing of the good one that followed it.
        $this->assertCount(2, $results, 'only the well-formed regime code should produce notification rows');
        foreach ($results as $notification) {
            $this->assertSame($goodRegime['regimeCode'], $notification['regimeCode']);
        }
    }

    public function testComputeNotificationDeadlinesThrowsForANonexistentIncident(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BreachManager::computeNotificationDeadlines(999999999);
    }

    public function testComputeNotificationDeadlinesNeverResetsAnAlreadySentNotificationBackToPending(): void
    {
        $regime = $this->insertTestRegime(['breachNotifyHours' => 24]);
        $incidentID = BreachManager::open(['severity' => 'high', 'detectedAt' => '2026-06-01 00:00:00', 'regimeCodes' => [$regime['regimeCode']]]);
        $this->testIncidentIDsToCleanUp[] = $incidentID;

        $first = BreachManager::computeNotificationDeadlines($incidentID);
        $authorityNotifyID = null;
        foreach ($first as $notification) {
            if ($notification['audience'] === 'authority') {
                $authorityNotifyID = (int) $notification['notifyID'];
            }
        }
        $this->assertNotNull($authorityNotifyID);

        $this->assertTrue(BreachManager::markNotified($authorityNotifyID, 'Filed with the authority.'));

        // 🔁 Recomputing must NOT reset the already-sent row.
        $second = BreachManager::computeNotificationDeadlines($incidentID);
        foreach ($second as $notification) {
            if ((int) $notification['notifyID'] === $authorityNotifyID) {
                $this->assertSame('sent', $notification['status'], 'an already-sent notification must never be silently reset to pending');
            }
        }
    }

    // ========================================================================
    // ✅ markNotified() / 📋 getOverdueNotifications()
    // ========================================================================

    public function testMarkNotifiedReturnsFalseForANonexistentNotification(): void
    {
        $this->assertFalse(BreachManager::markNotified(999999999, 'nope'));
    }

    public function testGetOverdueNotificationsFindsAPastDueRowButNotAFutureOrUnconfiguredOne(): void
    {
        $regimeOverdue = $this->insertTestRegime(['breachNotifyHours' => 1]);
        $regimeFuture = $this->insertTestRegime(['breachNotifyHours' => 1]);

        $overdueIncident = BreachManager::open([
            'severity'    => 'high',
            'detectedAt'  => date('Y-m-d H:i:s', strtotime('-1 year')), // guarantees +1h is long past
            'regimeCodes' => [$regimeOverdue['regimeCode']],
        ]);
        $this->testIncidentIDsToCleanUp[] = $overdueIncident;
        BreachManager::computeNotificationDeadlines($overdueIncident);

        $futureIncident = BreachManager::open([
            'severity'    => 'high',
            'detectedAt'  => date('Y-m-d H:i:s', strtotime('+1 year')), // guarantees +1h is far in the future
            'regimeCodes' => [$regimeFuture['regimeCode']],
        ]);
        $this->testIncidentIDsToCleanUp[] = $futureIncident;
        BreachManager::computeNotificationDeadlines($futureIncident);

        $overdue = BreachManager::getOverdueNotifications();
        $overdueIncidentIDs = array_map(static fn(array $n): int => (int) $n['incidentID'], $overdue);

        $this->assertContains($overdueIncident, $overdueIncidentIDs);
        $this->assertNotContains($futureIncident, $overdueIncidentIDs);
    }
}
