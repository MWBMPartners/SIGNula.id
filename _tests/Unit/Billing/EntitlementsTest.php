<?php
/**
 * ============================================================================
 * 🧪 Entitlements Unit Tests (FG-011 — the flexible pricing catalog's
 *    FAIL-OPEN entitlement resolver)
 * ============================================================================
 *
 * Covers the pure gate-decision logic — whether Entitlements considers
 * itself "actively enforcing" at all — WITHOUT a database connection. This
 * mirrors the established BillingModeTest pattern exactly (see
 * _tests/Unit/Payments/BillingModeTest.php): when the enforcement gate is
 * OFF (the default seeded by migration 046_flexible_pricing_catalog.sql),
 * every public method short-circuits to its fail-open answer BEFORE ever
 * touching the database, so these assertions hold true even though the
 * `Database` class is never loaded in this Unit-test context.
 *
 * A further group of tests flips `entitlements.enforcement_enabled` ON
 * (without a database connection available) to prove the SECOND half of
 * the fail-open contract: any internal failure — here, "Class Database
 * not found" is thrown as a \Error the moment resolution tries to query
 * tblFeatureToggles/tblFeatureCatalog — is caught and still answers
 * fail-open, never fatals, and never denies. The row-actually-enforced
 * assertions (once a real catalog + subscription exist) belong in the
 * Integration suite, per the same BillingModeTest / BillingModeGuardTest
 * split this mirrors.
 *
 * @package    SIGNula\Tests\Unit\Billing
 * @version    2.9.0-beta
 * @see        web/private_html/billing/Entitlements.php
 * @see        _tests/Unit/Payments/BillingModeTest.php (the pattern this mirrors)
 * @see        pricing_schema_design.md §8 (the dormant-shipping / fail-open contract)
 */

namespace SIGNula\Tests\Unit\Billing;

use SIGNula\Tests\TestCase;
use Entitlements;

requireSource('private_html/billing/EntitlementDeniedException.php');
requireSource('private_html/billing/Entitlements.php');

/**
 * 🔓 Entitlements test suite — fail-open gate.
 */
class EntitlementsTest extends TestCase
{
    // ========================================================================
    // 🔒 Gate OFF (the default) — every public method fails open, no DB needed
    // ========================================================================

    public function testHasReturnsTrueWhenEnforcementSettingIsMissingEntirely(): void
    {
        // Deliberately NOT setting entitlements.enforcement_enabled at all —
        // mirrors the state of a brand-new install that has just run
        // migration 046 (the setting IS seeded 'false', but this proves the
        // class is safe even if a settings row were somehow absent).
        $this->assertTrue(Entitlements::has(['accountType' => 'user', 'accountID' => 1], 'sso.enterprise_connections'));
    }

    public function testHasReturnsTrueWhenEnforcementExplicitlyFalse(): void
    {
        setTestSetting('entitlements.enforcement_enabled', false);
        $this->assertTrue(Entitlements::has(['accountType' => 'user', 'accountID' => 1], 'branding.custom'));
    }

    public function testHasReturnsTrueWhenEnforcementSettingIsTheStringFalse(): void
    {
        // Production reads this setting back out of tblSettings already
        // cast to a real bool via settingType='boolean' (see
        // web/_config/config.php's loadSettings()), but the gate must also
        // fail open if a raw string ever reaches it (e.g. a settingType
        // mismatch on an older install).
        setTestSetting('entitlements.enforcement_enabled', 'false');
        $this->assertTrue(Entitlements::has(['accountType' => 'user', 'accountID' => 1], 'branding.custom'));
    }

    public function testLimitReturnsNullWhenEnforcementInactive(): void
    {
        setTestSetting('entitlements.enforcement_enabled', false);
        $this->assertNull(Entitlements::limit(['accountType' => 'user', 'accountID' => 1], 'mau.included'));
    }

    public function testWithinLimitReturnsTrueWhenEnforcementInactive(): void
    {
        setTestSetting('entitlements.enforcement_enabled', false);
        $this->assertTrue(
            Entitlements::withinLimit(['accountType' => 'user', 'accountID' => 1], 'mau.included', 999999.0)
        );
    }

    public function testRequireDoesNotThrowWhenEnforcementInactive(): void
    {
        setTestSetting('entitlements.enforcement_enabled', false);

        Entitlements::require(['accountType' => 'user', 'accountID' => 1], 'sso.enterprise_connections');
        $this->assertTrue(true, 'require() must not throw while the enforcement gate is off');
    }

    public function testResolveAllReturnsTheAllowAllSentinelWhenEnforcementInactive(): void
    {
        setTestSetting('entitlements.enforcement_enabled', false);

        $resolved = Entitlements::resolveAll(['accountType' => 'user', 'accountID' => 1]);

        $this->assertFalse($resolved['gateActive']);
        $this->assertFalse($resolved['__enforced']);
        $this->assertSame([], $resolved['values']);
    }

    // ========================================================================
    // 🌐 Missing/malformed $ctx never breaks the fail-open contract
    // ========================================================================

    public function testHasFailsOpenForAnEmptyContextArray(): void
    {
        setTestSetting('entitlements.enforcement_enabled', false);
        $this->assertTrue(Entitlements::has([], 'mfa.all'));
    }

    public function testHasFailsOpenForAnUnrecognisedAccountType(): void
    {
        setTestSetting('entitlements.enforcement_enabled', false);
        $this->assertTrue(
            Entitlements::has(['accountType' => 'not_a_real_scope', 'accountID' => 1], 'mfa.all')
        );
    }

    // ========================================================================
    // 🚨 Gate ON but the database is unavailable → still fails open (never fatals)
    // ========================================================================
    // No `Database` class exists in this pure Unit-test context (matches
    // BillingModeTest's own documented reasoning for exercising a guard's
    // DB-touching code paths without a DB — see this file's own header
    // comment). The moment resolveAll() reaches isOpsToggleActive()'s
    // Database::fetchOne() call, PHP throws a \Error ("Class Database not
    // found"), which Entitlements::resolveAll() catches via `catch
    // (\Throwable $e)` and converts into the fail-open sentinel — exactly
    // the "any exception anywhere in the resolution path" branch of the
    // fail-open contract (see this class's own doc-comment, point 5).
    // ========================================================================

    public function testHasStillFailsOpenWhenEnforcementEnabledButDatabaseIsUnavailable(): void
    {
        setTestSetting('entitlements.enforcement_enabled', true);

        $this->assertTrue(
            Entitlements::has(['accountType' => 'user', 'accountID' => 1], 'sso.enterprise_connections'),
            'A resolution-path failure (no DB available) must still answer allow, never deny and never fatal'
        );
    }

    public function testRequireDoesNotThrowWhenEnforcementEnabledButDatabaseIsUnavailable(): void
    {
        setTestSetting('entitlements.enforcement_enabled', true);

        Entitlements::require(['accountType' => 'user', 'accountID' => 1], 'sso.enterprise_connections');
        $this->assertTrue(true, 'require() must swallow an internal resolution failure and allow, never fatal');
    }

    public function testResolveAllReturnsTheAllowAllSentinelWhenDatabaseIsUnavailable(): void
    {
        setTestSetting('entitlements.enforcement_enabled', true);

        $resolved = Entitlements::resolveAll(['accountType' => 'user', 'accountID' => 1]);

        $this->assertFalse($resolved['gateActive']);
        $this->assertFalse($resolved['__enforced']);
        $this->assertSame([], $resolved['values']);
    }
}
