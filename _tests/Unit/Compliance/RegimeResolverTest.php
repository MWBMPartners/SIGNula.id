<?php
/**
 * ============================================================================
 * 🧪 RegimeResolver Unit Tests (G-004 Layer 3 §4 — the data-driven core)
 * ============================================================================
 *
 * Covers the PURE, DB-free logic inside RegimeResolver: the resolution-rule
 * matching predicate (ruleApplies()), the memo-key builder, row
 * normalisation, the synthetic most-protective default's shape, the
 * settings-fallback clamps (never 0/negative), and the public per-code
 * accessors' "no code supplied" fast path (which never touches the
 * database — see each accessor's own docblock).
 *
 * Everything that requires an ACTIVE regime row to actually exist (resolve()
 * matching a real tblRegimeResolution rule, slaDaysFor()/rightsFor() reading
 * a real tblComplianceRegimes row, the DSARManager/ConsentManager retro-wire
 * round trip) is DB-bound and lives in the Integration suite
 * (_tests/Integration/Compliance/RegimeResolverTest.php), matching the
 * established ConsentManagerTest/DSARManagerTest Unit/Integration split.
 *
 * @package    SIGNula\Tests\Unit\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/RegimeResolver.php
 * @see        _database/migrations/042_compliance_regimes.sql
 * @see        .dev-team/specs/G-004.md §4.1-§4.4, §8
 */

namespace SIGNula\Tests\Unit\Compliance;

use SIGNula\Tests\TestCase;
use RegimeResolver;
use ReflectionMethod;

requireSource('private_html/compliance/RegimeResolver.php');

/**
 * 🛡️ RegimeResolver pure-logic test suite.
 */
class RegimeResolverTest extends TestCase
{
    // ========================================================================
    // 🔧 Reflection helper — invoke a private static method by name.
    // ========================================================================

    /**
     * @param string $method Method name
     * @param array  $args   Positional arguments
     * @return mixed
     */
    private function callPrivateStatic(string $method, array $args)
    {
        $reflection = new ReflectionMethod(RegimeResolver::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $args);
    }

    // ========================================================================
    // ✅ ruleApplies() — the resolution-rule matching predicate
    // ========================================================================

    public function testRuleAppliesMatchesAPartnerRuleWhenPartnerIdEqualsMatchValue(): void
    {
        $rule = ['matchType' => 'partner', 'matchValue' => '42'];
        $this->assertTrue($this->callPrivateStatic('ruleApplies', [$rule, null, 42, null]));
    }

    public function testRuleAppliesRejectsAPartnerRuleWhenPartnerIdDiffers(): void
    {
        $rule = ['matchType' => 'partner', 'matchValue' => '42'];
        $this->assertFalse($this->callPrivateStatic('ruleApplies', [$rule, null, 43, null]));
    }

    public function testRuleAppliesRejectsAPartnerRuleWhenNoPartnerIdSupplied(): void
    {
        $rule = ['matchType' => 'partner', 'matchValue' => '42'];
        $this->assertFalse($this->callPrivateStatic('ruleApplies', [$rule, null, null, null]));
    }

    public function testRuleAppliesMatchesACountryRuleCaseInsensitively(): void
    {
        $rule = ['matchType' => 'country', 'matchValue' => 'de'];
        // 🔎 resolve() upper-cases the caller's countryHint BEFORE calling
        // ruleApplies() — this test feeds the already-normalised form, and
        // proves ruleApplies() ALSO upper-cases the rule's own matchValue,
        // so an admin typing a lowercase country code in the future CRUD
        // still matches.
        $this->assertTrue($this->callPrivateStatic('ruleApplies', [$rule, null, null, 'DE']));
    }

    public function testRuleAppliesRejectsACountryRuleWhenNoCountryHintSupplied(): void
    {
        $rule = ['matchType' => 'country', 'matchValue' => 'DE'];
        $this->assertFalse($this->callPrivateStatic('ruleApplies', [$rule, null, null, null]));
    }

    public function testRuleAppliesRejectsACountryRuleOnMismatch(): void
    {
        $rule = ['matchType' => 'country', 'matchValue' => 'DE'];
        $this->assertFalse($this->callPrivateStatic('ruleApplies', [$rule, null, null, 'FR']));
    }

    public function testRuleAppliesRegionNeverMatchesThisCycle(): void
    {
        // 🌐 No region hint exists in resolve()'s signature this cycle — see
        // RegimeResolver::ruleApplies()'s own docblock. Proven across every
        // combination of inputs, including ones that would satisfy a
        // partner/country rule.
        $rule = ['matchType' => 'region', 'matchValue' => 'EU'];
        $this->assertFalse($this->callPrivateStatic('ruleApplies', [$rule, 1, 42, 'DE']));
        $this->assertFalse($this->callPrivateStatic('ruleApplies', [$rule, null, null, null]));
    }

    public function testRuleAppliesDefaultAlwaysMatchesRegardlessOfInputs(): void
    {
        $rule = ['matchType' => 'default', 'matchValue' => null];
        $this->assertTrue($this->callPrivateStatic('ruleApplies', [$rule, null, null, null]));
        $this->assertTrue($this->callPrivateStatic('ruleApplies', [$rule, 1, 42, 'DE']));
    }

    public function testRuleAppliesRejectsAnUnrecognisedMatchType(): void
    {
        $rule = ['matchType' => 'not_a_real_match_type', 'matchValue' => 'anything'];
        $this->assertFalse($this->callPrivateStatic('ruleApplies', [$rule, 1, 42, 'DE']));
    }

    // ========================================================================
    // 🧠 memoKey()
    // ========================================================================

    public function testMemoKeyBuildsTheExpectedCompositeString(): void
    {
        $this->assertSame('7|42|DE', $this->callPrivateStatic('memoKey', [7, 42, 'DE']));
    }

    public function testMemoKeyUsesNullPlaceholdersForMissingParts(): void
    {
        $this->assertSame('null|null|null', $this->callPrivateStatic('memoKey', [null, null, null]));
        $this->assertSame('7|null|null', $this->callPrivateStatic('memoKey', [7, null, null]));
    }

    // ========================================================================
    // 🔧 normalizeRegimeRow() — MySQL string row -> typed PHP array
    // ========================================================================

    public function testNormalizeRegimeRowCastsEveryColumnToItsNativePhpType(): void
    {
        $raw = [
            'regimeID'              => '5',
            'regimeCode'            => 'ZZTEST',
            'displayName'           => 'Test Regime',
            'jurisdiction'          => 'Testland',
            'dsarSlaDays'           => '45',
            'dsarExtensionDays'     => '15',
            'breachNotifyHours'     => '72',
            'breachNotifyAuthority' => 'Test Authority',
            'minAge'                => '18',
            'honorsGPC'             => '1',
            'requiresDoNotSell'     => '0',
            'lawfulBasisRequired'   => '1',
            'isActive'              => '1',
            'isDraft'               => '0',
        ];

        $normalized = $this->callPrivateStatic('normalizeRegimeRow', [$raw]);

        $this->assertSame(5, $normalized['regimeID']);
        $this->assertSame('ZZTEST', $normalized['regimeCode']);
        $this->assertSame(45, $normalized['dsarSlaDays']);
        $this->assertSame(15, $normalized['dsarExtensionDays']);
        $this->assertSame(72, $normalized['breachNotifyHours']);
        $this->assertSame(18, $normalized['minAge']);
        $this->assertTrue($normalized['honorsGPC']);
        $this->assertFalse($normalized['requiresDoNotSell']);
        $this->assertTrue($normalized['lawfulBasisRequired']);
        $this->assertTrue($normalized['isActive']);
        $this->assertFalse($normalized['isDraft']);
        $this->assertFalse($normalized['isDefault'], 'A real resolved regime row is never the synthetic default');
    }

    public function testNormalizeRegimeRowPreservesNullsForUnsetOptionalColumns(): void
    {
        $raw = [
            'regimeID'              => '1',
            'regimeCode'            => 'ZZTEST',
            'displayName'           => 'Test Regime',
            'jurisdiction'          => null,
            'dsarSlaDays'           => null,
            'dsarExtensionDays'     => null,
            'breachNotifyHours'     => null,
            'breachNotifyAuthority' => null,
            'minAge'                => null,
            'honorsGPC'             => '0',
            'requiresDoNotSell'     => '0',
            'lawfulBasisRequired'   => '0',
            'isActive'              => '1',
            'isDraft'               => '0',
        ];

        $normalized = $this->callPrivateStatic('normalizeRegimeRow', [$raw]);

        $this->assertNull($normalized['jurisdiction']);
        $this->assertNull($normalized['dsarSlaDays']);
        $this->assertNull($normalized['dsarExtensionDays']);
        $this->assertNull($normalized['breachNotifyHours']);
        $this->assertNull($normalized['breachNotifyAuthority']);
        $this->assertNull($normalized['minAge']);
    }

    // ========================================================================
    // 🛡️ syntheticDefault() — the never-fail-open contract's exact shape
    // ========================================================================

    public function testSyntheticDefaultShapeMatchesTheShippedStateContract(): void
    {
        // 🔒 CRITICAL: with the test fixture settings NOT overriding
        // dsar.default_sla_days, this MUST be exactly 30 — the value that
        // keeps the DSARManager/ConsentManager retro-wire a no-op against
        // the pre-existing L1/L2 baseline (see class docblock).
        $default = $this->callPrivateStatic('syntheticDefault', []);

        $this->assertNull($default['regimeID']);
        $this->assertNull($default['regimeCode']);
        $this->assertNull($default['displayName']);
        $this->assertNull($default['jurisdiction']);
        $this->assertSame(30, $default['dsarSlaDays']);
        $this->assertNull($default['dsarExtensionDays']);
        $this->assertNull($default['breachNotifyHours']);
        $this->assertNull($default['breachNotifyAuthority']);
        $this->assertSame(16, $default['minAge']);
        $this->assertFalse($default['requiresDoNotSell']);
        $this->assertFalse($default['lawfulBasisRequired']);
        $this->assertFalse($default['isActive']);
        $this->assertFalse($default['isDraft']);
        $this->assertTrue($default['isDefault']);
    }

    // ========================================================================
    // 📐 resolveDefaultSlaDays() / resolveDefaultMinAge() — never 0/negative
    // ========================================================================

    public function testResolveDefaultSlaDaysUsesTheConfiguredSettingWhenPositive(): void
    {
        setTestSetting('dsar.default_sla_days', '45');
        $this->assertSame(45, $this->callPrivateStatic('resolveDefaultSlaDays', []));
    }

    public function testResolveDefaultSlaDaysClampsAZeroSettingToTheHardcodedFallback(): void
    {
        setTestSetting('dsar.default_sla_days', '0');
        $this->assertSame(30, $this->callPrivateStatic('resolveDefaultSlaDays', []));
    }

    public function testResolveDefaultSlaDaysClampsANegativeSettingToTheHardcodedFallback(): void
    {
        setTestSetting('dsar.default_sla_days', '-10');
        $this->assertSame(30, $this->callPrivateStatic('resolveDefaultSlaDays', []));
    }

    public function testResolveDefaultMinAgeUsesTheConfiguredSettingWhenPositive(): void
    {
        setTestSetting('compliance.regime.default_min_age', '18');
        $this->assertSame(18, $this->callPrivateStatic('resolveDefaultMinAge', []));
    }

    public function testResolveDefaultMinAgeClampsAZeroSettingToTheHardcodedFallback(): void
    {
        setTestSetting('compliance.regime.default_min_age', '0');
        $this->assertSame(16, $this->callPrivateStatic('resolveDefaultMinAge', []));
    }

    public function testResolveDefaultHonorsGpcRespectsAnExplicitFalseSetting(): void
    {
        setTestSetting('consent.gpc.honor', 'false');
        $this->assertFalse($this->callPrivateStatic('resolveDefaultHonorsGPC', []));
    }

    public function testResolveDefaultHonorsGpcDefaultsToTrueWhenUnset(): void
    {
        // 🛡️ Most-protective posture: honoring an opt-out signal can never
        // itself be the less-protective choice — see honorsGPC()'s docblock.
        $this->assertTrue($this->callPrivateStatic('resolveDefaultHonorsGPC', []));
    }

    // ========================================================================
    // 📐 Public accessors — the "no code supplied" fast path (DB-free)
    // ========================================================================

    public function testSlaDaysForReturnsTheDefaultWhenRegimeCodeIsNull(): void
    {
        $this->assertSame(30, RegimeResolver::slaDaysFor(null));
    }

    public function testSlaDaysForReturnsTheDefaultWhenRegimeCodeIsEmptyString(): void
    {
        $this->assertSame(30, RegimeResolver::slaDaysFor(''));
    }

    public function testMinAgeForReturnsTheDefaultWhenRegimeCodeIsNull(): void
    {
        $this->assertSame(16, RegimeResolver::minAgeFor(null));
    }

    public function testBreachWindowHoursReturnsNullWhenRegimeCodeIsNull(): void
    {
        $this->assertNull(RegimeResolver::breachWindowHours(null));
    }

    public function testRightsForReturnsAnEmptyArrayWhenRegimeCodeIsNull(): void
    {
        $this->assertSame([], RegimeResolver::rightsFor(null));
    }

    public function testHonorsGpcReturnsTheDefaultWhenRegimeCodeIsNull(): void
    {
        $this->assertTrue(RegimeResolver::honorsGPC(null));

        setTestSetting('consent.gpc.honor', 'false');
        $this->assertFalse(RegimeResolver::honorsGPC(null));
    }

    /**
     * 🛡️ Never-fail-open proof for an UNKNOWN regime code: whether the code
     * genuinely doesn't exist in tblComplianceRegimes, or the database is
     * entirely unreachable in this test environment, the outcome must be
     * IDENTICAL — the most-protective default, never 0/negative/permissive.
     * fetchActiveRegimeByCode()'s internal try/catch (see RegimeResolver.php)
     * guarantees this degrades safely either way.
     */
    public function testSlaDaysForDegradesSafelyForAnUnknownRegimeCode(): void
    {
        $this->assertSame(30, RegimeResolver::slaDaysFor('DEFINITELY_NOT_A_SEEDED_REGIME_CODE_XYZ'));
    }

    public function testMinAgeForDegradesSafelyForAnUnknownRegimeCode(): void
    {
        $this->assertSame(16, RegimeResolver::minAgeFor('DEFINITELY_NOT_A_SEEDED_REGIME_CODE_XYZ'));
    }
}
