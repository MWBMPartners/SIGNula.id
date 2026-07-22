<?php
/**
 * ============================================================================
 * 🧪 RetentionManager Unit Tests (G-004 Layer 4a §5.3 — retention + auto-purge)
 * ============================================================================
 *
 * Covers the PURE, DB-free logic inside RetentionManager:
 *   - isPolicyAllowlisted() — the allowlist gate that prevents a DB-stored
 *     (or injection-shaped) targetTable/dateColumn/anonymiseColumns from
 *     ever reaching SQL. This is the crux security property of the whole
 *     class, and it is exercised here without touching a database at all.
 *   - resolvePurgeMode() — the "change nothing unless BOTH switches are
 *     flipped" safety contract (disabled/dry_run/live).
 *
 * Everything DB-bound (runDuePolicies()'s round trip: dry-run leaves rows
 * untouched, a live run actually anonymises due rows, an allowlist-rejected
 * policy never issues a query against its target table) lives in the
 * Integration suite (_tests/Integration/Compliance/RetentionManagerTest.php),
 * matching the established ConsentManagerTest/DSARManagerTest/
 * RegimeResolverTest Unit/Integration split.
 *
 * @package    SIGNula\Tests\Unit\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/RetentionManager.php
 * @see        _database/migrations/043_retention_policies.sql
 * @see        .dev-team/specs/G-004.md §5.3
 */

namespace SIGNula\Tests\Unit\Compliance;

use SIGNula\Tests\TestCase;
use RetentionManager;

requireSource('private_html/compliance/RetentionManager.php');

/**
 * 🛡️ RetentionManager pure-logic test suite.
 */
class RetentionManagerTest extends TestCase
{
    // ========================================================================
    // 🔒 isPolicyAllowlisted() — the crux security property
    // ========================================================================

    public function testAllowlistedAnonymisePolicyPassesWithRecognisedColumns(): void
    {
        $policy = [
            'targetTable'      => 'tblActivityLog',
            'dateColumn'       => 'createdAt',
            'action'           => 'anonymise',
            'anonymiseColumns' => ['ipAddress', 'userAgent'],
        ];

        $this->assertTrue(RetentionManager::isPolicyAllowlisted($policy));
    }

    public function testAllowlistedDeletePolicyNeedsNoAnonymiseColumns(): void
    {
        $policy = [
            'targetTable' => 'tblActivityLog',
            'dateColumn'  => 'createdAt',
            'action'      => 'delete',
        ];

        $this->assertTrue(RetentionManager::isPolicyAllowlisted($policy));
    }

    public function testUnknownTargetTableIsRejected(): void
    {
        $policy = [
            'targetTable'      => 'tblUsers',
            'dateColumn'       => 'createdAt',
            'action'           => 'anonymise',
            'anonymiseColumns' => ['email'],
        ];

        $this->assertFalse(RetentionManager::isPolicyAllowlisted($policy), 'tblUsers is not in ALLOWED_TARGETS — must be rejected.');
    }

    /**
     * 🔒 THE headline security proof: an injection-shaped targetTable string
     * fails the EXACT string-equality check against ALLOWED_TARGETS — it is
     * never treated as "close enough" to a real table name.
     */
    public function testInjectionShapedTargetTableIsRejected(): void
    {
        $policy = [
            'targetTable'      => 'tblUsers; DROP TABLE tblUsers;--',
            'dateColumn'       => 'createdAt',
            'action'           => 'delete',
        ];

        $this->assertFalse(RetentionManager::isPolicyAllowlisted($policy));
    }

    public function testInjectionShapedTargetTableThatPrefixMatchesARealTableIsStillRejected(): void
    {
        // 🔒 Even a string that STARTS WITH a real allowlisted table name
        // must fail — proves the check is exact equality, not a prefix/
        // substring match (which would be exploitable).
        $policy = [
            'targetTable' => 'tblActivityLog; DROP TABLE tblUsers;--',
            'dateColumn'  => 'createdAt',
            'action'      => 'delete',
        ];

        $this->assertFalse(RetentionManager::isPolicyAllowlisted($policy));
    }

    public function testUnallowlistedDateColumnIsRejectedEvenForAnAllowlistedTable(): void
    {
        $policy = [
            'targetTable'      => 'tblActivityLog',
            'dateColumn'       => 'updatedAt', // not an allowlisted dateColumn for this table
            'action'           => 'anonymise',
            'anonymiseColumns' => ['ipAddress'],
        ];

        $this->assertFalse(RetentionManager::isPolicyAllowlisted($policy));
    }

    public function testUnallowlistedAnonymiseColumnRejectsTheWholePolicy(): void
    {
        $policy = [
            'targetTable'      => 'tblActivityLog',
            'dateColumn'       => 'createdAt',
            'action'           => 'anonymise',
            // 'passwordHash' is not a column on tblActivityLog at all, and
            // certainly not an allowlisted one — this must reject the WHOLE
            // policy, not just silently drop the bad column.
            'anonymiseColumns' => ['ipAddress', 'passwordHash'],
        ];

        $this->assertFalse(RetentionManager::isPolicyAllowlisted($policy));
    }

    public function testEmptyAnonymiseColumnsListIsRejected(): void
    {
        $policy = [
            'targetTable'      => 'tblActivityLog',
            'dateColumn'       => 'createdAt',
            'action'           => 'anonymise',
            'anonymiseColumns' => [],
        ];

        $this->assertFalse(RetentionManager::isPolicyAllowlisted($policy), 'An anonymise policy naming zero columns has nothing safe to do.');
    }

    public function testUnknownActionIsRejected(): void
    {
        $policy = [
            'targetTable' => 'tblActivityLog',
            'dateColumn'  => 'createdAt',
            'action'      => 'truncate', // not 'anonymise' or 'delete'
        ];

        $this->assertFalse(RetentionManager::isPolicyAllowlisted($policy));
    }

    public function testMissingKeysDegradeToRejectionRatherThanAWarningOrCrash(): void
    {
        $this->assertFalse(RetentionManager::isPolicyAllowlisted([]));
    }

    // ========================================================================
    // 🚦 resolvePurgeMode() — the "both switches must flip" safety contract
    // ========================================================================

    public function testDisabledAndDryRunResolvesToDisabled(): void
    {
        $this->assertSame('disabled', RetentionManager::resolvePurgeMode(false, true));
    }

    public function testDisabledAndNotDryRunStillResolvesToDisabled(): void
    {
        // 🛡️ Disabled always wins — a mistakenly-flipped dry_run=false must
        // NOT produce a 'live' mode while the master switch is off.
        $this->assertSame('disabled', RetentionManager::resolvePurgeMode(false, false));
    }

    public function testEnabledAndDryRunResolvesToDryRun(): void
    {
        $this->assertSame('dry_run', RetentionManager::resolvePurgeMode(true, true));
    }

    public function testEnabledAndNotDryRunResolvesToLive(): void
    {
        $this->assertSame('live', RetentionManager::resolvePurgeMode(true, false));
    }
}
