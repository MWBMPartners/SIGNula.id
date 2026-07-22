<?php
/**
 * ============================================================================
 * 🧪 AgeGateService Unit Tests (G-004 Layer 4b §5.4 — COPPA-style age gate)
 * ============================================================================
 *
 * Covers the PURE, DB-free logic inside AgeGateService:
 *   - isEnabled() — THE baseline-safety property. With no
 *     `compliance.age_gate.enabled` test setting configured, getSetting()'s
 *     stub falls back to the default '0' this class passes — proving the
 *     gate is INERT by default, mirroring the real migration-044 shipped
 *     value.
 *   - isUnderage() — pure age-from-DOB math against an explicit threshold
 *     (every acceptance-test call in the G-004 spec passes one explicitly),
 *     including the fail-closed contract for an invalid/unparseable DOB.
 *
 * Everything DB-bound (thresholdAge()'s RegimeResolver delegation,
 * startParentalConsent()/verifyParentalConsent()'s real INSERT/UPDATE round
 * trips) lives in the Integration suite
 * (_tests/Integration/Compliance/AgeGateServiceTest.php), matching the
 * established ConsentManagerTest/DSARManagerTest/RegimeResolverTest Unit/
 * Integration split.
 *
 * @package    SIGNula\Tests\Unit\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/AgeGateService.php
 * @see        web/public_html/register.php — the signup hook gated by isEnabled()
 * @see        _database/migrations/044_breach_ropa_agegate.sql
 * @see        .dev-team/specs/G-004.md §5.4
 */

namespace SIGNula\Tests\Unit\Compliance;

use SIGNula\Tests\TestCase;
use AgeGateService;

requireSource('private_html/compliance/AgeGateService.php');

/**
 * 🚼 AgeGateService pure-logic test suite.
 */
class AgeGateServiceTest extends TestCase
{
    // ========================================================================
    // 🔒 isEnabled() — THE baseline-safety property (shipped default = OFF)
    // ========================================================================

    /**
     * 🔒 THE headline test for this class: with NOTHING configuring
     * `compliance.age_gate.enabled` (exactly the state of a fresh test, and
     * exactly the state of a fresh production install per migration 044's
     * seeded `'0'` value), the gate is OFF. This is what keeps register.php's
     * entire age-gate block — and therefore the pre-existing register/Auth
     * test baseline — inert.
     */
    public function testIsEnabledDefaultsToFalseWhenNoSettingIsConfigured(): void
    {
        $this->assertFalse(AgeGateService::isEnabled());
    }

    public function testIsEnabledTrueWhenTheSettingIsExplicitlyTruthy(): void
    {
        setTestSetting('compliance.age_gate.enabled', '1');
        $this->assertTrue(AgeGateService::isEnabled());

        setTestSetting('compliance.age_gate.enabled', true);
        $this->assertTrue(AgeGateService::isEnabled());
    }

    public function testIsEnabledFalseWhenTheSettingIsExplicitlyFalsy(): void
    {
        setTestSetting('compliance.age_gate.enabled', '0');
        $this->assertFalse(AgeGateService::isEnabled());

        setTestSetting('compliance.age_gate.enabled', 'false');
        $this->assertFalse(AgeGateService::isEnabled());
    }

    // ========================================================================
    // 🎂 isUnderage() — pure age-from-DOB math (explicit threshold)
    // ========================================================================

    public function testIsUnderageTrueForAClearlyUnderageDob(): void
    {
        // 🩹 The EXACT acceptance-test call from the G-004 spec.
        $this->assertTrue(AgeGateService::isUnderage('2015-01-01', 13));
    }

    public function testIsUnderageFalseForAClearlyOverageDob(): void
    {
        // 🩹 The EXACT acceptance-test call from the G-004 spec.
        $this->assertFalse(AgeGateService::isUnderage('1990-01-01', 13));
    }

    public function testIsUnderageIsFalseOnTheExactThresholdBirthday(): void
    {
        $justTurned13 = (new \DateTimeImmutable('today'))->modify('-13 years')->format('Y-m-d');
        $this->assertFalse(AgeGateService::isUnderage($justTurned13, 13), 'A subject who turned 13 exactly today is not underage against a threshold of 13');
    }

    public function testIsUnderageIsTrueTheDayBeforeTheThresholdBirthday(): void
    {
        $oneDayShortOf13 = (new \DateTimeImmutable('today'))->modify('-13 years')->modify('+1 day')->format('Y-m-d');
        $this->assertTrue(AgeGateService::isUnderage($oneDayShortOf13, 13), 'A subject who turns 13 tomorrow is still underage today');
    }

    // ========================================================================
    // 🛡️ isUnderage() — fail-closed on an invalid/unparseable DOB
    // ========================================================================

    public function testIsUnderageFailsClosedOnAnUnparseableDob(): void
    {
        $this->assertTrue(AgeGateService::isUnderage('not-a-date', 13));
        $this->assertTrue(AgeGateService::isUnderage('', 13));
        $this->assertTrue(AgeGateService::isUnderage('2024-13-40', 13), 'A malformed month/day must not silently roll over to a valid date');
    }

    public function testIsUnderageFailsClosedOnAFutureDob(): void
    {
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $this->assertTrue(AgeGateService::isUnderage($tomorrow, 13));
    }
}
