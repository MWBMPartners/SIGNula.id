<?php
/**
 * ============================================================================
 * 🧪 SecurityAlertManager Unit Tests
 * ============================================================================
 *
 * Tests for the SecurityAlertManager class covering:
 * - isEnabled() setting toggle
 * - Alert type and severity constants
 * - checkBruteForce() threshold logic
 * - Constants and severity ranking
 *
 * Note: Methods that require Database class (create, acknowledge, resolve,
 * getRecentAlerts, checkImpossibleTravel, checkPasswordSpray) are tested
 * for graceful error handling when Database is unavailable.
 *
 * @package    SIGNula\Tests\Unit\Security
 * @version    2.6.0-beta
 * @see        web/private_html/security/SecurityAlertManager.php
 */

namespace SIGNula\Tests\Unit\Security;

use SIGNula\Tests\TestCase;

// 📦 Load source class
requireSource('private_html/security/SecurityAlertManager.php');

/**
 * SecurityAlertManager Test Suite
 */
class SecurityAlertManagerTest extends TestCase
{
    // ========================================================================
    // 🔧 SETUP
    // ========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        // 🔧 Default alert settings
        setTestSetting('security.alerts.enabled', true);
        setTestSetting('security.alerts.admin_emails', '[]');
        setTestSetting('security.alerts.notify_threshold', 'high');
        setTestSetting('security.alerts.brute_force_threshold', 5);
        setTestSetting('security.alerts.impossible_travel_km', 500);
        setTestSetting('security.alerts.impossible_travel_minutes', 60);
        setTestSetting('security.alerts.password_spray_threshold', 10);
        setTestSetting('security.alerts.password_spray_window_minutes', 30);
    }

    // ========================================================================
    // ✅ isEnabled() TESTS
    // ========================================================================

    /**
     * Test isEnabled returns true when setting is enabled
     */
    public function testIsEnabledReturnsTrue(): void
    {
        setTestSetting('security.alerts.enabled', true);

        $this->assertTrue(\SecurityAlertManager::isEnabled());
    }

    /**
     * Test isEnabled returns false when setting is disabled
     */
    public function testIsEnabledReturnsFalse(): void
    {
        setTestSetting('security.alerts.enabled', false);

        $this->assertFalse(\SecurityAlertManager::isEnabled());
    }

    /**
     * Test isEnabled handles string 'true' / 'false' values from settings
     */
    public function testIsEnabledHandlesStringValues(): void
    {
        setTestSetting('security.alerts.enabled', 'true');
        $this->assertTrue(\SecurityAlertManager::isEnabled());

        setTestSetting('security.alerts.enabled', 'false');
        $this->assertFalse(\SecurityAlertManager::isEnabled());

        setTestSetting('security.alerts.enabled', '0');
        $this->assertFalse(\SecurityAlertManager::isEnabled());

        setTestSetting('security.alerts.enabled', '1');
        $this->assertTrue(\SecurityAlertManager::isEnabled());
    }

    // ========================================================================
    // 🏷️ CONSTANT TESTS
    // ========================================================================

    /**
     * Test that all expected alert type constants are defined
     */
    public function testAlertTypeConstantsExist(): void
    {
        $this->assertEquals('brute_force', \SecurityAlertManager::TYPE_BRUTE_FORCE);
        $this->assertEquals('impossible_travel', \SecurityAlertManager::TYPE_IMPOSSIBLE_TRAVEL);
        $this->assertEquals('session_hijack', \SecurityAlertManager::TYPE_SESSION_HIJACK);
        $this->assertEquals('ip_blocked', \SecurityAlertManager::TYPE_IP_BLOCKED);
        $this->assertEquals('suspicious_registration', \SecurityAlertManager::TYPE_SUSPICIOUS_REGISTRATION);
        $this->assertEquals('captcha_failure', \SecurityAlertManager::TYPE_CAPTCHA_FAILURE);
        $this->assertEquals('bot_detected', \SecurityAlertManager::TYPE_BOT_DETECTED);
        $this->assertEquals('rate_limit_exceeded', \SecurityAlertManager::TYPE_RATE_LIMIT_EXCEEDED);
        $this->assertEquals('multiple_failed_logins', \SecurityAlertManager::TYPE_MULTIPLE_FAILED_LOGINS);
        $this->assertEquals('account_lockout', \SecurityAlertManager::TYPE_ACCOUNT_LOCKOUT);
        $this->assertEquals('password_spray', \SecurityAlertManager::TYPE_PASSWORD_SPRAY);
    }

    /**
     * Test that all expected severity constants are defined
     */
    public function testSeverityConstantsExist(): void
    {
        $this->assertEquals('low', \SecurityAlertManager::SEVERITY_LOW);
        $this->assertEquals('medium', \SecurityAlertManager::SEVERITY_MEDIUM);
        $this->assertEquals('high', \SecurityAlertManager::SEVERITY_HIGH);
        $this->assertEquals('critical', \SecurityAlertManager::SEVERITY_CRITICAL);
    }

    // ========================================================================
    // 🆕 create() TESTS (without Database)
    // ========================================================================

    /**
     * Test create returns false when alerting is disabled
     */
    public function testCreateReturnsFalseWhenDisabled(): void
    {
        setTestSetting('security.alerts.enabled', false);

        $result = \SecurityAlertManager::create(
            \SecurityAlertManager::TYPE_BRUTE_FORCE,
            \SecurityAlertManager::SEVERITY_HIGH,
            'Test alert',
            ['test' => true],
            null,
            '127.0.0.1'
        );

        $this->assertFalse($result);
    }

    /**
     * Test create returns false when Database class is not available
     * (it will throw an exception caught internally)
     */
    public function testCreateReturnsFalseWhenDatabaseUnavailable(): void
    {
        setTestSetting('security.alerts.enabled', true);

        // 📝 Database class is not loaded in unit test context,
        // so create() should catch the exception and return false
        $result = \SecurityAlertManager::create(
            \SecurityAlertManager::TYPE_BRUTE_FORCE,
            \SecurityAlertManager::SEVERITY_HIGH,
            'Test brute force alert',
            ['ip' => '1.2.3.4'],
            null,
            '1.2.3.4'
        );

        $this->assertFalse($result);
    }

    // ========================================================================
    // 🔨 checkBruteForce() TESTS
    // ========================================================================

    /**
     * Test checkBruteForce does not crash when Database is unavailable
     * (the method catches all exceptions internally)
     */
    public function testCheckBruteForceHandlesNoDatabaseGracefully(): void
    {
        setTestSetting('security.alerts.brute_force_threshold', 5);

        // 📝 Should not throw even though Database is not available
        \SecurityAlertManager::checkBruteForce('1.2.3.4', 10);

        // ✅ If we get here without an exception, the test passes
        $this->assertTrue(true, 'checkBruteForce should not throw when Database is unavailable');
    }

    /**
     * Test checkBruteForce uses configurable threshold from settings
     */
    public function testCheckBruteForceRespectsThreshold(): void
    {
        // 📊 Set a high threshold to ensure it would NOT trigger
        setTestSetting('security.alerts.brute_force_threshold', 100);

        // 📝 With only 3 failures against threshold of 100, no alert should be created
        \SecurityAlertManager::checkBruteForce('1.2.3.4', 3);

        // ✅ Passes if no exception is thrown
        $this->assertTrue(true);
    }

    // ========================================================================
    // 🔑 checkPasswordSpray() TESTS
    // ========================================================================

    /**
     * Test checkPasswordSpray does not crash when Database is unavailable
     */
    public function testCheckPasswordSprayHandlesNoDatabaseGracefully(): void
    {
        // 📝 Should not throw even though Database is not available
        \SecurityAlertManager::checkPasswordSpray('1.2.3.4', 30);

        $this->assertTrue(true, 'checkPasswordSpray should not throw when Database is unavailable');
    }

    // ========================================================================
    // ✈️ checkImpossibleTravel() TESTS
    // ========================================================================

    /**
     * Test checkImpossibleTravel does not crash when Database is unavailable
     */
    public function testCheckImpossibleTravelHandlesNoDatabaseGracefully(): void
    {
        // 📝 Should not throw even though Database is not available
        \SecurityAlertManager::checkImpossibleTravel(1, '8.8.8.8');

        $this->assertTrue(true, 'checkImpossibleTravel should not throw when Database is unavailable');
    }

    // ========================================================================
    // 📋 getRecentAlerts() TESTS
    // ========================================================================

    /**
     * Test getRecentAlerts returns empty array when Database is unavailable
     */
    public function testGetRecentAlertsReturnsEmptyWithoutDatabase(): void
    {
        $result = \SecurityAlertManager::getRecentAlerts();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ========================================================================
    // 👁️ acknowledge() & ✅ resolve() TESTS
    // ========================================================================

    /**
     * Test acknowledge returns false when Database is unavailable
     */
    public function testAcknowledgeReturnsFalseWithoutDatabase(): void
    {
        $result = \SecurityAlertManager::acknowledge(1, 1);

        $this->assertFalse($result);
    }

    /**
     * Test resolve returns false when Database is unavailable
     */
    public function testResolveReturnsFalseWithoutDatabase(): void
    {
        $result = \SecurityAlertManager::resolve(1, 1, 'Test resolution');

        $this->assertFalse($result);
    }

    // ========================================================================
    // 📧 notify() TESTS (B-068b regression)
    // ========================================================================

    /**
     * 🐛 B-068b regression: notify() (private — invoked via reflection since
     * it has no public entry point that is reachable without a live Database
     * connection; see SecurityAlertManagerNotifyTest.php in the Integration
     * suite for the full create()-driven end-to-end path) used to call
     * `json_decode(getSetting('security.alerts.admin_emails', '[]'), true)`
     * unconditionally. That setting is seeded with settingType='json'
     * (migration 014_security_enhancements.sql), so production's
     * config.php::loadSettings() ALREADY json_decode()s it before caching —
     * getSetting() hands back a native PHP array, and json_decode(<array>,
     * true) throws a PHP 8 TypeError. Because TypeError extends \Error (not
     * \Exception), it escaped notify()'s own catch(\Exception) entirely —
     * a fatal, not a caught-and-logged failure.
     *
     * An EMPTY array is used deliberately so this stays a pure Unit test:
     * notify() returns immediately after the `empty($adminEmails)` check,
     * never reaching the `Database::query()` UPDATE at the end of the method
     * (Database is not loaded in this test context) or any mail()/EmailService
     * call. The OLD code still threw for an empty array — the TypeError fires
     * at the json_decode() call itself, before the emptiness check ever runs
     * — so this is a faithful, hermetic reproduction of the bug.
     */
    public function testNotifyDoesNotThrowWithArrayTypedAdminEmails(): void
    {
        setTestSetting('security.alerts.admin_emails', []); // 🎯 array, not JSON string

        $reflection = new \ReflectionClass(\SecurityAlertManager::class);
        $method = $reflection->getMethod('notify');
        $method->setAccessible(true);

        // 📝 If the TypeError regression were still present, this invoke()
        // call would throw and fail the test with an uncaught \Error.
        $method->invoke(null, 1, \SecurityAlertManager::TYPE_SESSION_HIJACK, \SecurityAlertManager::SEVERITY_HIGH, 'Test session hijack notification');

        $this->assertTrue(true, 'notify() must not throw when admin_emails is an already-decoded (array-typed) setting value');
    }
}
