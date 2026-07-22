<?php
/**
 * ============================================================================
 * 🧪 PwnedPasswords Unit Tests
 * ============================================================================
 *
 * Tests for the PwnedPasswords (HIBP breached-password) class, covering:
 * - isEnabled() setting toggle (disabled by default)
 * - isBreached() no-op behaviour while disabled (no network call, fail-open shape)
 * - isBreached() no-op behaviour for an empty password
 * - isBreached() result-shape contract (breached/count/checked keys)
 *
 * These tests do NOT require a database connection or external network
 * access — the disabled-by-default path is verified purely by asserting the
 * result shape/timing without ever reaching curl_init(), matching the
 * conventions of the sibling IPReputationCheckerTest.
 *
 * @package    SIGNula\Tests\Unit\Security
 * @version    2.9.0-beta
 * @see        web/private_html/security/PwnedPasswords.php
 * @see        _tests/Unit/Security/IPReputationCheckerTest.php (sibling pattern this mirrors)
 */

namespace SIGNula\Tests\Unit\Security;

use SIGNula\Tests\TestCase;

// 📦 Load source class
requireSource('private_html/security/PwnedPasswords.php');

/**
 * PwnedPasswords Test Suite
 */
class PwnedPasswordsTest extends TestCase
{
    // ========================================================================
    // 🔧 SETUP
    // ========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        // 🔧 Default breached-password-check settings — mirrors the
        // defaults seeded by _database/migrations/047_breached_password_check.sql.
        // The master switch is OFF, matching production's shipped default.
        setTestSetting('security.breached_password_check.enabled', false);
        setTestSetting('security.breached_password_check.mode', 'block');
        setTestSetting('security.breached_password_check.min_count', 1);
        setTestSetting('security.breached_password_check.timeout_seconds', 3);
    }

    // ========================================================================
    // ✅ isEnabled() TESTS
    // ========================================================================

    /**
     * Test isEnabled returns false by default (disabled in settings) —
     * this is the shipped, safe default (FG-009 / Issue #96 contract).
     */
    public function testIsEnabledReturnsFalseByDefault(): void
    {
        $this->assertFalse(\PwnedPasswords::isEnabled());
    }

    /**
     * Test isEnabled returns true when explicitly enabled via setting.
     */
    public function testIsEnabledReturnsTrueWhenEnabled(): void
    {
        setTestSetting('security.breached_password_check.enabled', true);

        $this->assertTrue(\PwnedPasswords::isEnabled());
    }

    // ========================================================================
    // 🔒 isBreached() TESTS — Disabled (default) behaviour
    // ========================================================================

    /**
     * 🎯 Core FG-009 contract test: with the setting at its shipped default
     * (disabled), isBreached() must no-op — returning breached=false and
     * checked=false — WITHOUT making any network call.
     *
     * We assert this by timing the call: any real HIBP HTTP round-trip
     * (even a fast one) takes measurably longer than a pure in-process
     * early-return. A generous 250ms ceiling comfortably separates "we
     * never touched the network" from "we made a real HTTPS request",
     * while remaining safe against normal CI/test-runner jitter.
     */
    public function testIsBreachedNoOpsWhenDisabled(): void
    {
        $start = microtime(true);

        $result = \PwnedPasswords::isBreached('correct-horse-battery-staple');

        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertFalse($result['breached']);
        $this->assertSame(0, $result['count']);
        $this->assertFalse($result['checked']);

        // ⏱️ No network call should have been attempted — this must return
        // near-instantly (well under any realistic network round-trip).
        $this->assertLessThan(
            250,
            $elapsedMs,
            'isBreached() took too long while disabled — a network call may have been made despite the master switch being off'
        );
    }

    /**
     * Test isBreached() returns the full expected result shape while disabled.
     */
    public function testIsBreachedResultHasExpectedKeysWhenDisabled(): void
    {
        $result = \PwnedPasswords::isBreached('any-password-value');

        $this->assertArrayHasKey('breached', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('checked', $result);
        $this->assertIsBool($result['breached']);
        $this->assertIsInt($result['count']);
        $this->assertIsBool($result['checked']);
    }

    /**
     * Test isBreached() no-ops for a well-known previously-breached password
     * (e.g. "password") while disabled — proving the master switch, not the
     * password's own obscurity, is what suppresses the check.
     */
    public function testIsBreachedNoOpsForKnownBreachedPasswordWhenDisabled(): void
    {
        $result = \PwnedPasswords::isBreached('password');

        $this->assertFalse($result['breached']);
        $this->assertFalse($result['checked']);
    }

    // ========================================================================
    // 🧹 isBreached() TESTS — Empty password
    // ========================================================================

    /**
     * Test isBreached() no-ops for an empty password even if enabled —
     * there is nothing meaningful to check, and no network call should occur.
     */
    public function testIsBreachedNoOpsForEmptyPasswordEvenWhenEnabled(): void
    {
        setTestSetting('security.breached_password_check.enabled', true);

        $result = \PwnedPasswords::isBreached('');

        $this->assertFalse($result['breached']);
        $this->assertSame(0, $result['count']);
        $this->assertFalse($result['checked']);
    }
}
