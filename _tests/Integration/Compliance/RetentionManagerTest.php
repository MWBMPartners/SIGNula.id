<?php
/**
 * ============================================================================
 * 🧪 RetentionManager Integration Tests (G-004 Layer 4a §5.3 — retention + auto-purge)
 * ============================================================================
 *
 * DB-bound proof that RetentionManager::runDuePolicies() actually behaves
 * against REAL rows in tblActivityLog / tblRetentionPolicies (migration 043)
 * on a real MySQL connection (signula_test):
 *
 *   1. THE CRUX — dry-run/disabled safety: a due row is correctly counted
 *      (wouldPurge) but is left COMPLETELY UNCHANGED whenever
 *      retention.purge.enabled=false OR retention.purge.dry_run=true. This
 *      is the shipped default state and must never silently purge anything.
 *   2. Opt-in live purge: with BOTH switches flipped (enabled=true,
 *      dry_run=false) and the policy isActive=1, only rows PAST the
 *      retention window are anonymised (within the configured batch); fresh
 *      rows are left untouched.
 *   3. The allowlist rejects an injection-shaped / un-allowlisted
 *      targetTable outright — the policy is skipped and NO query ever
 *      touches the (fictitious/foreign) target.
 *
 * ⚠️ Test-fixture hygiene (mirrors RegimeResolverTest's convention exactly):
 * this class deliberately does NOT put tblRetentionPolicies in
 * $truncateTables — that table carries the REAL migration-043 seed rows
 * (isActive=0 drafts an operator may already be reviewing). Every
 * test-fixture policy this class creates uses a `zztest_`-prefixed
 * policyKey and is explicitly DELETEd in tearDown() via
 * $testPolicyIDsToCleanUp, leaving the real seeds completely undisturbed.
 *
 * tblActivityLog IS truncated before each test — this is load-bearing (see
 * DatabaseTestCase's implicit-COMMIT note, replicated across every existing
 * Compliance Integration test file): TRUNCATE causes an implicit COMMIT,
 * which is what makes rows inserted via $this->insertRecord() (a SEPARATE
 * mysqli session) visible to the shared Database:: connection
 * RetentionManager actually reads/writes through.
 *
 * @package    SIGNula\Tests\Integration\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/RetentionManager.php
 * @see        _database/migrations/043_retention_policies.sql
 * @see        _tests/Integration/Compliance/RegimeResolverTest.php — the ZZTEST-prefix precedent this mirrors
 * @see        .dev-team/specs/G-004.md §5.3
 */

namespace SIGNula\Tests\Integration\Compliance;

use SIGNula\Tests\DatabaseTestCase;
use RetentionManager;

requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/compliance/RetentionManager.php');

/**
 * 🛡️ RetentionManager round-trip test suite.
 */
class RetentionManagerTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test — deliberately
     *  EXCLUDES tblRetentionPolicies (see class docblock). */
    protected array $truncateTables = [
        'tblActivityLog',
    ];

    /** @var array<int,int> tblRetentionPolicies.policyID rows this test created. */
    private array $testPolicyIDsToCleanUp = [];

    protected function tearDown(): void
    {
        // 🧹 Explicit cleanup (NOT truncateTables — see class docblock): only
        // ever removes the rows THIS test created, by exact ID, leaving the
        // real migration-043 seed data completely undisturbed.
        foreach ($this->testPolicyIDsToCleanUp as $policyID) {
            \Database::query("DELETE FROM tblRetentionPolicies WHERE policyID = ?", [$policyID], 'i');
        }

        parent::tearDown();
    }

    // ========================================================================
    // 🔧 Test-fixture helpers
    // ========================================================================

    /**
     * 🔧 Insert a test-only retention policy row (via $this->insertRecord(),
     * which — combined with the non-empty $truncateTables implicit-COMMIT —
     * is immediately visible to the shared Database:: connection
     * RetentionManager reads from).
     *
     * @param array<string,mixed> $overrides
     * @return int New policyID
     */
    private function insertTestPolicy(array $overrides = []): int
    {
        $defaults = [
            'policyKey'        => 'zztest_' . bin2hex(random_bytes(4)),
            'targetTable'      => 'tblActivityLog',
            'dateColumn'       => 'createdAt',
            'retentionDays'    => 365,
            'action'           => 'anonymise',
            'anonymiseColumns' => json_encode(['ipAddress', 'userAgent']),
            'regimeCode'       => null,
            'isActive'         => 1,
            'notes'            => 'zztest fixture row',
        ];

        $data = array_merge($defaults, $overrides);
        $policyID = $this->insertRecord('tblRetentionPolicies', $data);
        $this->testPolicyIDsToCleanUp[] = $policyID;

        return $policyID;
    }

    /**
     * 🔧 Insert a test-only tblActivityLog row with an explicit createdAt
     * timestamp (days in the past) and known ipAddress/userAgent, so tests
     * can assert exactly which rows a policy run did/did not touch.
     *
     * @param int    $daysOld
     * @param string $ipAddress
     * @param string $userAgent
     * @return int New logID
     */
    private function insertTestActivityLogRow(int $daysOld, string $ipAddress = '203.0.113.5', string $userAgent = 'ZZTestAgent/1.0'): int
    {
        $createdAt = (new \DateTime())->modify("-{$daysOld} days")->format('Y-m-d H:i:s');

        return $this->insertRecord('tblActivityLog', [
            'userID'           => null,
            'activityType'     => 'zztest_event',
            'activityCategory' => 'other',
            'severity'         => 'info',
            'description'      => 'zztest fixture row',
            'ipAddress'        => $ipAddress,
            'userAgent'        => $userAgent,
            'success'          => 1,
            'createdAt'        => $createdAt,
        ]);
    }

    /**
     * 🔧 Find this test's result row out of runDuePolicies()'s per-policy array.
     *
     * @param array<int,array<string,mixed>> $results
     * @param string $policyKey
     * @return array<string,mixed>|null
     */
    private function findResultFor(array $results, string $policyKey): ?array
    {
        foreach ($results as $result) {
            if (($result['policyKey'] ?? null) === $policyKey) {
                return $result;
            }
        }

        return null;
    }

    // ========================================================================
    // 🛡️ THE CRUX — dry-run / disabled safety
    // ========================================================================

    public function testDryRunLeavesDueRowsCompletelyUnchanged(): void
    {
        // 🛡️ Shipped default state — neither setting explicitly overridden
        // here means the fixture defaults apply: retention.purge.enabled is
        // absent from the settings fixture (see class docblock's grep proof),
        // so getSetting() falls back to '0' inside RetentionManager. Set
        // dry_run explicitly true too, to prove BOTH defaults independently.
        setTestSetting('retention.purge.enabled', false);
        setTestSetting('retention.purge.dry_run', true);
        setTestSetting('retention.purge.batch_size', 500);

        $policyKey = 'zztest_dry_run_' . bin2hex(random_bytes(3));
        $this->insertTestPolicy([
            'policyKey'        => $policyKey,
            'retentionDays'    => 365,
            'anonymiseColumns' => json_encode(['ipAddress', 'userAgent']),
        ]);

        // 📅 A row well past the 365-day retention window.
        $logID = $this->insertTestActivityLogRow(400, '198.51.100.9', 'DryRunOldAgent/1.0');
        // 📅 A fresh row, well within the window — must ALSO be untouched.
        $freshLogID = $this->insertTestActivityLogRow(1, '198.51.100.10', 'DryRunFreshAgent/1.0');

        $results = RetentionManager::runDuePolicies();
        $result = $this->findResultFor($results, $policyKey);

        $this->assertNotNull($result, 'runDuePolicies() must return a summary for the test policy.');
        $this->assertSame(0, $result['executed'], 'Dry-run/disabled must never mark a policy as executed.');
        $this->assertContains($result['mode'], ['dry_run', 'disabled'], 'Mode must reflect the safe non-live state.');
        $this->assertGreaterThanOrEqual(1, $result['wouldPurge'], 'The old row must be COUNTED as due...');

        // 🔒 ...but the row itself must be COMPLETELY UNCHANGED.
        $row = $this->getRecord('tblActivityLog', ['logID' => $logID]);
        $this->assertSame('198.51.100.9', $row['ipAddress'], 'Dry-run must not touch ipAddress.');
        $this->assertSame('DryRunOldAgent/1.0', $row['userAgent'], 'Dry-run must not touch userAgent.');

        $freshRow = $this->getRecord('tblActivityLog', ['logID' => $freshLogID]);
        $this->assertSame('198.51.100.10', $freshRow['ipAddress']);

        // 🔒 lastRunAt must also remain untouched by a dry-run/disabled pass.
        $policyRow = $this->getRecord('tblRetentionPolicies', ['policyKey' => $policyKey]);
        $this->assertNull($policyRow['lastRunAt'], 'A dry-run/disabled pass must never stamp lastRunAt.');
    }

    public function testEnabledButStillDryRunAlsoLeavesRowsUntouched(): void
    {
        // 🛡️ Proves the "BOTH switches" contract — enabled alone is not
        // enough; dry_run=true must still block execution.
        setTestSetting('retention.purge.enabled', true);
        setTestSetting('retention.purge.dry_run', true);
        setTestSetting('retention.purge.batch_size', 500);

        $policyKey = 'zztest_enabled_dryrun_' . bin2hex(random_bytes(3));
        $this->insertTestPolicy([
            'policyKey'        => $policyKey,
            'retentionDays'    => 30,
            'anonymiseColumns' => json_encode(['ipAddress', 'userAgent']),
        ]);

        $logID = $this->insertTestActivityLogRow(60, '198.51.100.20', 'StillDryRunAgent/1.0');

        $results = RetentionManager::runDuePolicies();
        $result = $this->findResultFor($results, $policyKey);

        $this->assertSame('dry_run', $result['mode']);
        $this->assertSame(0, $result['executed']);
        $this->assertGreaterThanOrEqual(1, $result['wouldPurge']);

        $row = $this->getRecord('tblActivityLog', ['logID' => $logID]);
        $this->assertSame('198.51.100.20', $row['ipAddress']);
        $this->assertSame('StillDryRunAgent/1.0', $row['userAgent']);
    }

    // ========================================================================
    // ✅ Opt-in LIVE purge
    // ========================================================================

    public function testLivePurgeAnonymisesOnlyDueRowsWithinBatch(): void
    {
        setTestSetting('retention.purge.enabled', true);
        setTestSetting('retention.purge.dry_run', false);
        setTestSetting('retention.purge.batch_size', 500);

        $policyKey = 'zztest_live_' . bin2hex(random_bytes(3));
        $this->insertTestPolicy([
            'policyKey'        => $policyKey,
            'retentionDays'    => 90,
            'anonymiseColumns' => json_encode(['ipAddress', 'userAgent']),
        ]);

        // 📅 Due (past 90 days).
        $dueLogID = $this->insertTestActivityLogRow(100, '203.0.113.50', 'LiveOldAgent/1.0');
        // 📅 NOT due (well within 90 days) — must be left untouched.
        $freshLogID = $this->insertTestActivityLogRow(5, '203.0.113.51', 'LiveFreshAgent/1.0');

        $results = RetentionManager::runDuePolicies();
        $result = $this->findResultFor($results, $policyKey);

        $this->assertNotNull($result);
        $this->assertSame('live', $result['mode']);
        $this->assertSame(1, $result['executed']);
        $this->assertGreaterThanOrEqual(1, $result['purged']);

        // 🔒 The due row's PII columns are anonymised (nulled)...
        $dueRow = $this->getRecord('tblActivityLog', ['logID' => $dueLogID]);
        $this->assertNull($dueRow['ipAddress'], 'A live-purged row must have ipAddress nulled.');
        $this->assertNull($dueRow['userAgent'], 'A live-purged row must have userAgent nulled.');

        // 🔒 ...but the fresh row is completely untouched.
        $freshRow = $this->getRecord('tblActivityLog', ['logID' => $freshLogID]);
        $this->assertSame('203.0.113.51', $freshRow['ipAddress']);
        $this->assertSame('LiveFreshAgent/1.0', $freshRow['userAgent']);

        // ✅ lastRunAt IS stamped on a live execution.
        $policyRow = $this->getRecord('tblRetentionPolicies', ['policyKey' => $policyKey]);
        $this->assertNotNull($policyRow['lastRunAt']);
    }

    public function testLivePurgeRespectsTheConfiguredBatchCeiling(): void
    {
        setTestSetting('retention.purge.enabled', true);
        setTestSetting('retention.purge.dry_run', false);
        setTestSetting('retention.purge.batch_size', 2); // tiny batch

        $policyKey = 'zztest_batch_' . bin2hex(random_bytes(3));
        $this->insertTestPolicy([
            'policyKey'        => $policyKey,
            'retentionDays'    => 10,
            'anonymiseColumns' => json_encode(['ipAddress', 'userAgent']),
        ]);

        // 5 due rows, but batch_size=2 must cap a single run at 2.
        for ($i = 0; $i < 5; $i++) {
            $this->insertTestActivityLogRow(20, "192.0.2.{$i}", "BatchAgent{$i}/1.0");
        }

        $results = RetentionManager::runDuePolicies();
        $result = $this->findResultFor($results, $policyKey);

        $this->assertSame('live', $result['mode']);
        $this->assertSame(2, $result['purged'], 'A batch_size of 2 must cap a single run at exactly 2 affected rows.');
    }

    // ========================================================================
    // 🔒 THE allowlist rejects injection / un-allowlisted targets
    // ========================================================================

    public function testUnallowlistedTargetTableIsSkippedAndNeverQueried(): void
    {
        setTestSetting('retention.purge.enabled', true);
        setTestSetting('retention.purge.dry_run', false);
        setTestSetting('retention.purge.batch_size', 500);

        $policyKey = 'zztest_injection_' . bin2hex(random_bytes(3));
        $this->insertTestPolicy([
            'policyKey'        => $policyKey,
            'targetTable'      => 'tblUsers; DROP TABLE tblUsers;--',
            'dateColumn'       => 'createdAt',
            'action'           => 'delete',
            'anonymiseColumns' => null,
        ]);

        $usersCountBefore = $this->countRecords('tblUsers');

        $results = RetentionManager::runDuePolicies();
        $result = $this->findResultFor($results, $policyKey);

        $this->assertNotNull($result);
        $this->assertTrue($result['skipped'] ?? false, 'An un-allowlisted targetTable must be SKIPPED, not executed.');
        $this->assertSame('not_allowlisted', $result['reason']);
        $this->assertSame(0, $result['executed']);

        // 🔒 No query was ever run against tblUsers because of this policy.
        $this->assertSame($usersCountBefore, $this->countRecords('tblUsers'), 'tblUsers row count must be completely unaffected.');
    }

    public function testUnallowlistedTargetTableThatIsARealTableIsAlsoSkipped(): void
    {
        // 🔒 Not just injection-shaped strings — ANY table not in
        // RetentionManager::ALLOWED_TARGETS is rejected, even a perfectly
        // valid, real table name.
        setTestSetting('retention.purge.enabled', true);
        setTestSetting('retention.purge.dry_run', false);
        setTestSetting('retention.purge.batch_size', 500);

        $policyKey = 'zztest_realtable_' . bin2hex(random_bytes(3));
        $this->insertTestPolicy([
            'policyKey'        => $policyKey,
            'targetTable'      => 'tblUsers',
            'dateColumn'       => 'createdAt',
            'action'           => 'delete',
            'anonymiseColumns' => null,
        ]);

        $usersCountBefore = $this->countRecords('tblUsers');

        $results = RetentionManager::runDuePolicies();
        $result = $this->findResultFor($results, $policyKey);

        $this->assertTrue($result['skipped'] ?? false);
        $this->assertSame($usersCountBefore, $this->countRecords('tblUsers'));
    }

    public function testUnallowlistedAnonymiseColumnSkipsTheWholePolicyLive(): void
    {
        setTestSetting('retention.purge.enabled', true);
        setTestSetting('retention.purge.dry_run', false);
        setTestSetting('retention.purge.batch_size', 500);

        $policyKey = 'zztest_badcolumn_' . bin2hex(random_bytes(3));
        $this->insertTestPolicy([
            'policyKey'        => $policyKey,
            'retentionDays'    => 10,
            'anonymiseColumns' => json_encode(['ipAddress', 'activityType']), // activityType is NOT allowlisted
        ]);

        $logID = $this->insertTestActivityLogRow(20, '192.0.2.99', 'BadColumnAgent/1.0');

        $results = RetentionManager::runDuePolicies();
        $result = $this->findResultFor($results, $policyKey);

        $this->assertTrue($result['skipped'] ?? false);

        // 🔒 The row (including its otherwise-anonymisable ipAddress) is untouched.
        $row = $this->getRecord('tblActivityLog', ['logID' => $logID]);
        $this->assertSame('192.0.2.99', $row['ipAddress']);
    }

    // ========================================================================
    // 🛡️ Real (isActive=0) seed policies never run
    // ========================================================================

    public function testRealSeedPoliciesShipInactiveAndAreNeverProcessed(): void
    {
        setTestSetting('retention.purge.enabled', true);
        setTestSetting('retention.purge.dry_run', false);
        setTestSetting('retention.purge.batch_size', 500);

        $seedRow = $this->getRecord('tblRetentionPolicies', ['policyKey' => 'activity_log_anonymise']);
        $this->assertNotNull($seedRow, 'Migration 043 must have seeded activity_log_anonymise.');
        $this->assertSame('0', (string) $seedRow['isActive'], 'The seeded policy must ship isActive=0.');

        $results = RetentionManager::runDuePolicies();
        $result = $this->findResultFor($results, 'activity_log_anonymise');

        $this->assertNull($result, 'An isActive=0 policy must not even appear in runDuePolicies()\'s result set.');
    }
}
