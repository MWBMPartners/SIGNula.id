<?php
/**
 * ============================================================================
 * 🧪 ConsentManager Unit Tests (G-004 Layer 1 / FG-002a — Cycle A)
 * ============================================================================
 *
 * Covers the PURE, DB-free decision logic inside ConsentManager: mechanism
 * resolution/fallback, proofHash determinism, the userID/visitorID identity
 * WHERE-fragment builder, and the truthy-setting parser. These are exercised
 * via Reflection because they are private static helpers — deliberately kept
 * private (see ConsentManager's own docblocks) since they are internal
 * implementation details, not part of the class's public contract.
 *
 * record()/getCurrent()/getEffectiveConsents()/reconcileVisitorToUser()/
 * hasGranted() are NOT exercised here: every one of them calls into
 * Database::query()/fetchOne()/fetchAll()/insert(), and `Database` is never
 * loaded in the Unit suite (matches the established BillingModeTest /
 * SecurityAlertManagerTest pattern of not touching DB-bound code paths
 * without a DB). Those round-trips live in the Integration suite
 * (_tests/Integration/Compliance/ConsentManagerTest.php), which has a real
 * MySQL connection.
 *
 * @package    SIGNula\Tests\Unit\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/ConsentManager.php
 * @see        _database/migrations/040_consent_and_dsar.sql
 * @see        .dev-team/specs/G-004.md §2.2, §8
 */

namespace SIGNula\Tests\Unit\Compliance;

use SIGNula\Tests\TestCase;
use ReflectionMethod;

requireSource('private_html/compliance/ConsentManager.php');

/**
 * 🛡️ ConsentManager pure-logic test suite.
 */
class ConsentManagerTest extends TestCase
{
    // ========================================================================
    // 🔧 Reflection helper — invoke a private static method by name.
    // ========================================================================

    /**
     * 🔧 Invoke a private static method on ConsentManager via Reflection.
     *
     * @param string $method Method name
     * @param array  $args   Positional arguments
     * @return mixed
     */
    private function callPrivateStatic(string $method, array $args)
    {
        $reflection = new ReflectionMethod('ConsentManager', $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $args);
    }

    // ========================================================================
    // 🎯 resolveMechanism()
    // ========================================================================

    public function testResolveMechanismAcceptsEveryValueInTheEnumAllowlist(): void
    {
        // 🔒 Mirrors tblConsentRecords.mechanism ENUM exactly (migration 040) —
        // if this list ever drifts from the SQL ENUM, this test documents it.
        $enumValues = ['banner', 'preference_center', 'signup', 'api', 'gpc_signal', 'admin', 'import'];

        foreach ($enumValues as $value) {
            $this->assertSame(
                $value,
                $this->callPrivateStatic('resolveMechanism', [$value]),
                "resolveMechanism() must pass through the valid ENUM value '{$value}' unchanged"
            );
        }
    }

    public function testResolveMechanismFallsBackToBannerForNull(): void
    {
        $this->assertSame('banner', $this->callPrivateStatic('resolveMechanism', [null]));
    }

    public function testResolveMechanismFallsBackToBannerForAnUnrecognisedValue(): void
    {
        // 🛡️ An unrecognised value must NEVER reach the database — a raw
        // ENUM-truncation error under strict mode is worse than a silent,
        // safe fallback to the default capture mechanism.
        $this->assertSame('banner', $this->callPrivateStatic('resolveMechanism', ['not_a_real_mechanism']));
    }

    public function testResolveMechanismIsCaseSensitiveNotACaseFold(): void
    {
        // 🔎 Documents current behaviour: the ENUM values are lowercase, and
        // resolveMechanism() does an exact (case-sensitive) membership check
        // — 'Banner' is NOT silently folded to 'banner', it is treated as
        // unrecognised and falls back to the literal default.
        $this->assertSame('banner', $this->callPrivateStatic('resolveMechanism', ['Banner']));
    }

    public function testResolveMechanismFallsBackToBannerForEmptyString(): void
    {
        $this->assertSame('banner', $this->callPrivateStatic('resolveMechanism', ['']));
    }

    // ========================================================================
    // 🔏 computeProofHash()
    // ========================================================================

    public function testComputeProofHashIsDeterministicForIdenticalInputs(): void
    {
        $args = ['cookies.analytics', true, 'v1', '2026-07-10 12:00:00', "\x7f\x00\x00\x01", 'PHPUnit/TestAgent'];

        $hashOne = $this->callPrivateStatic('computeProofHash', $args);
        $hashTwo = $this->callPrivateStatic('computeProofHash', $args);

        $this->assertSame($hashOne, $hashTwo, 'computeProofHash() must be deterministic for identical inputs');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hashOne, 'Must be a 64-char lowercase hex SHA-256 digest');
    }

    public function testComputeProofHashChangesWhenGrantedFlips(): void
    {
        $base = ['cookies.analytics', true, 'v1', '2026-07-10 12:00:00', "\x7f\x00\x00\x01", 'PHPUnit/TestAgent'];
        $flipped = $base;
        $flipped[1] = false;

        $this->assertNotSame(
            $this->callPrivateStatic('computeProofHash', $base),
            $this->callPrivateStatic('computeProofHash', $flipped),
            'Flipping granted true<->false must change the proof hash (grant vs withdraw must be distinguishable)'
        );
    }

    public function testComputeProofHashChangesWhenConsentTypeDiffers(): void
    {
        $base = ['cookies.analytics', true, 'v1', '2026-07-10 12:00:00', "\x7f\x00\x00\x01", 'PHPUnit/TestAgent'];
        $other = $base;
        $other[0] = 'cookies.marketing';

        $this->assertNotSame(
            $this->callPrivateStatic('computeProofHash', $base),
            $this->callPrivateStatic('computeProofHash', $other)
        );
    }

    public function testComputeProofHashChangesWhenTimestampDiffers(): void
    {
        $base = ['cookies.analytics', true, 'v1', '2026-07-10 12:00:00', "\x7f\x00\x00\x01", 'PHPUnit/TestAgent'];
        $other = $base;
        $other[3] = '2026-07-10 12:00:01';

        $this->assertNotSame(
            $this->callPrivateStatic('computeProofHash', $base),
            $this->callPrivateStatic('computeProofHash', $other)
        );
    }

    public function testComputeProofHashChangesWhenIpDiffers(): void
    {
        $base = ['cookies.analytics', true, 'v1', '2026-07-10 12:00:00', "\x7f\x00\x00\x01", 'PHPUnit/TestAgent'];
        $other = $base;
        $other[4] = "\x0a\x00\x00\x01"; // different packed IPv4

        $this->assertNotSame(
            $this->callPrivateStatic('computeProofHash', $base),
            $this->callPrivateStatic('computeProofHash', $other)
        );
    }

    public function testComputeProofHashChangesWhenUserAgentDiffers(): void
    {
        $base = ['cookies.analytics', true, 'v1', '2026-07-10 12:00:00', "\x7f\x00\x00\x01", 'PHPUnit/TestAgent'];
        $other = $base;
        $other[5] = 'SomeOtherAgent/1.0';

        $this->assertNotSame(
            $this->callPrivateStatic('computeProofHash', $base),
            $this->callPrivateStatic('computeProofHash', $other)
        );
    }

    public function testComputeProofHashHandlesNullPolicyVersionAndNullIpConsistently(): void
    {
        // 🛡️ Both null-able fields collapse to '' inside the hash input —
        // must not throw a TypeError and must still be deterministic.
        $hashOne = $this->callPrivateStatic(
            'computeProofHash',
            ['terms', false, null, '2026-07-10 12:00:00', null, 'PHPUnit/TestAgent']
        );
        $hashTwo = $this->callPrivateStatic(
            'computeProofHash',
            ['terms', false, null, '2026-07-10 12:00:00', null, 'PHPUnit/TestAgent']
        );

        $this->assertSame($hashOne, $hashTwo);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hashOne);
    }

    // ========================================================================
    // 🔧 buildIdentityWhere()
    // ========================================================================

    public function testBuildIdentityWherePrioritisesUserIdWhenBothAreSupplied(): void
    {
        [$where, $params, $types] = $this->callPrivateStatic('buildIdentityWhere', [42, 'a-visitor-uuid']);

        $this->assertSame('userID = ?', $where);
        $this->assertSame([42], $params);
        $this->assertSame('i', $types);
    }

    public function testBuildIdentityWhereFallsBackToVisitorIdWhenUserIdIsNull(): void
    {
        [$where, $params, $types] = $this->callPrivateStatic('buildIdentityWhere', [null, 'a-visitor-uuid']);

        $this->assertSame('visitorID = ?', $where);
        $this->assertSame(['a-visitor-uuid'], $params);
        $this->assertSame('s', $types);
    }

    public function testBuildIdentityWhereReturnsNullWhenNeitherIdentifierIsSupplied(): void
    {
        [$where, $params, $types] = $this->callPrivateStatic('buildIdentityWhere', [null, null]);

        $this->assertNull($where);
        $this->assertSame([], $params);
        $this->assertSame('', $types);
    }

    // ========================================================================
    // 🔧 isTruthySetting()
    // ========================================================================

    /**
     * @dataProvider truthyValuesProvider
     */
    public function testIsTruthySettingRecognisesTruthyStrings(string $value): void
    {
        $this->assertTrue($this->callPrivateStatic('isTruthySetting', [$value]));
    }

    public static function truthyValuesProvider(): array
    {
        return [
            'lowercase true' => ['true'],
            'uppercase TRUE' => ['TRUE'],
            'mixed True'     => ['True'],
            'one'            => ['1'],
            'yes'            => ['yes'],
            'on'             => ['on'],
        ];
    }

    /**
     * @dataProvider falsyValuesProvider
     */
    public function testIsTruthySettingRecognisesFalsyStrings(string $value): void
    {
        $this->assertFalse($this->callPrivateStatic('isTruthySetting', [$value]));
    }

    public static function falsyValuesProvider(): array
    {
        return [
            'false' => ['false'],
            'zero'  => ['0'],
            'no'    => ['no'],
            'off'   => ['off'],
            'empty' => [''],
            'junk'  => ['not-a-boolean'],
        ];
    }

    public function testIsTruthySettingAcceptsNativeBooleans(): void
    {
        $this->assertTrue($this->callPrivateStatic('isTruthySetting', [true]));
        $this->assertFalse($this->callPrivateStatic('isTruthySetting', [false]));
    }

    // ========================================================================
    // 🍪 consentType allowlist (settings/privacy.php) — regression sentinel
    // ========================================================================

    /**
     * 🍪 The record_consent POST action in settings/privacy.php gates every
     * consentType through a server-authoritative allowlist
     * ($CONSENT_TYPE_LABELS). That page can't be exercised directly in a
     * Unit test (it calls requireLogin()/getCurrentUser() and expects a full
     * HTTP + DB-backed config bootstrap), so this is a lightweight source
     * sentinel: it fails loudly if the four Layer-1 consent types this cycle
     * ships are ever removed from the allowlist without a deliberate edit.
     */
    public function testPrivacyPageConsentAllowlistContainsTheFourLayerOneConsentTypes(): void
    {
        $privacyPagePath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'settings' . DIRECTORY_SEPARATOR . 'privacy.php';

        $source = file_get_contents($privacyPagePath);
        $this->assertNotFalse($source, 'settings/privacy.php must be readable for this sentinel test');

        $matched = preg_match('/\$CONSENT_TYPE_LABELS\s*=\s*\[(.*?)\];/s', $source, $matches);
        $this->assertSame(1, $matched, '$CONSENT_TYPE_LABELS array literal must exist in settings/privacy.php');

        $arrayBody = $matches[1];
        foreach (['cookies.analytics', 'cookies.marketing', 'cookies.functional', 'marketing.email'] as $expectedType) {
            $this->assertStringContainsString(
                "'{$expectedType}'",
                $arrayBody,
                "settings/privacy.php's consent allowlist must include '{$expectedType}'"
            );
        }
    }
}
