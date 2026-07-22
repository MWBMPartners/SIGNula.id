<?php
/**
 * ============================================================================
 * 🧪 SecurityAlertManager::create() → notify() Pipeline Regression Test (B-068b)
 * ============================================================================
 *
 * B-068b (HIGH): SecurityAlertManager::notify() (called internally by
 * create() whenever an alert's severity meets the configured notify
 * threshold) unconditionally called
 * `json_decode(getSetting('security.alerts.admin_emails', '[]'), true)`.
 * That setting is seeded with settingType='json'
 * (migration 014_security_enhancements.sql), so production's
 * config.php::loadSettings() ALREADY json_decode()s it before caching it —
 * getSetting() hands back a native PHP array, and json_decode(<array>, true)
 * throws a PHP 8 TypeError (arg #1 must be of type string, array given).
 * Because TypeError extends \Error (not \Exception), it escaped BOTH
 * notify()'s own catch(\Exception) AND create()'s outer catch(\Exception) —
 * a session-hijack, brute-force, or any other alert creation with
 * admin_emails configured would fatally crash the request instead of
 * degrading gracefully, exactly when the security team most needed the
 * alert pipeline to be reliable.
 *
 * This suite drives the REAL public entry point — SecurityAlertManager::
 * create() with a severity that clears the notify threshold — against a live
 * signula_test database, so notify() is exercised exactly as production
 * calls it (not via reflection), proving the whole
 * create-alert → log-activity → notify-admins pipeline completes without
 * throwing, for both an empty and a non-empty array-typed admin_emails
 * setting.
 *
 * @package    SIGNula\Tests\Integration\Security
 * @version    2.7.0-beta
 * @see        web/private_html/security/SecurityAlertManager.php (create(), notify())
 * @see        _tests/Unit/Security/SecurityAlertManagerTest.php (the
 *             reflection-based, DB-independent companion unit test for
 *             notify() in isolation)
 */

namespace SIGNula\Tests\Integration\Security;

use SIGNula\Tests\DatabaseTestCase;

// ============================================================================
// 📦 LOAD SOURCE CLASSES (dependency order)
// ============================================================================

// 🗄️ Database singleton — create()/notify() both write through this.
requireSource('_config/database.php');

// 📝 ActivityLogger — create() logs the alert creation if this class exists.
requireSource('private_html/utils/ActivityLogger.php');

// 🚨 SecurityAlertManager — the class under test.
requireSource('private_html/security/SecurityAlertManager.php');

/**
 * SecurityAlertManager create() → notify() Pipeline Test Suite
 */
class SecurityAlertManagerNotifyTest extends DatabaseTestCase
{
    /**
     * Tables reset before each test.
     *
     * @var array<int,string>
     */
    protected array $truncateTables = ['tblSecurityAlerts', 'tblActivityLog'];

    protected function setUp(): void
    {
        parent::setUp();

        setTestSetting('security.alerts.enabled', true);
        // 🎯 'low' guarantees shouldNotify() fires for every severity used
        //    below, so notify() is always exercised.
        setTestSetting('security.alerts.notify_threshold', 'low');
    }

    // ========================================================================
    // ✅ TEST METHODS
    // ========================================================================

    /**
     * B-068b core regression: with admin_emails set to an EMPTY, already-
     * decoded PHP array (exactly what production's getSetting() returns for
     * a settingType='json' row), create() → notify() must complete without a
     * fatal TypeError. The old json_decode(<array>, true) call threw
     * regardless of whether the array was empty — the emptiness check ran
     * AFTER the decode — so this is a faithful reproduction of the bug in
     * its simplest form.
     *
     * @return void
     */
    public function testCreateWithEmptyArrayAdminEmailsDoesNotThrow(): void
    {
        // 🎯 Array, not JSON string — simulates getSetting() for a json-typed row.
        setTestSetting('security.alerts.admin_emails', []);

        // 🚀 Act — if the TypeError regression were still present, this call
        //    would fatally crash (TypeError escapes both catch(\Exception)
        //    blocks) rather than returning a value.
        $alertID = \SecurityAlertManager::create(
            \SecurityAlertManager::TYPE_SESSION_HIJACK,
            \SecurityAlertManager::SEVERITY_HIGH,
            'B-068b regression: session fingerprint mismatch',
            ['test' => true],
            null,
            '198.51.100.7'
        );

        // ✅ Assert — a real alertID (int), proving the full pipeline
        //    (insert alert → log activity → notify) ran to completion.
        $this->assertIsInt($alertID, 'create() must return a real alertID (int), not false — a TypeError inside notify() would have fatally crashed the request instead');

        $this->assertDatabaseHas(
            'tblSecurityAlerts',
            [
                'alertID'   => (string) $alertID,
                'alertType' => \SecurityAlertManager::TYPE_SESSION_HIJACK,
            ],
            'The alert row must exist — create() reaching this point proves notify() did not fatally crash the request'
        );
    }

    /**
     * B-068b non-empty-array proof: a populated array-typed admin_emails
     * must also decode and iterate cleanly — proving the fix isn't merely
     * "an empty array happens to short-circuit before the old json_decode()
     * call" (it does NOT: the old code decoded unconditionally, before the
     * emptiness check, so it threw regardless of array contents).
     *
     * Uses a deliberately INVALID email address so the per-recipient
     * `filter_var(..., FILTER_VALIDATE_EMAIL)` check causes notify() to
     * `continue` past the mail()/EmailService send for that entry — this
     * keeps the test hermetic (no real mail() call, no network I/O, no risk
     * of a PHP warning tripping phpunit.xml's failOnWarning="true") while
     * still exercising the array-decode fix and the full method body,
     * including the final `notificationSent` UPDATE.
     *
     * @return void
     */
    public function testCreateWithNonEmptyArrayAdminEmailsDoesNotThrowAndMarksNotified(): void
    {
        // 🎯 Non-empty array with an address that will fail FILTER_VALIDATE_EMAIL,
        //    so notify() exercises its full body (decode → iterate → skip
        //    invalid recipient → mark notificationSent) with zero mail I/O.
        setTestSetting('security.alerts.admin_emails', ['not-a-valid-email']);

        // 🚀 Act
        $alertID = \SecurityAlertManager::create(
            \SecurityAlertManager::TYPE_BRUTE_FORCE,
            \SecurityAlertManager::SEVERITY_CRITICAL,
            'B-068b regression: brute force with a populated admin_emails array',
            [],
            null,
            '198.51.100.8'
        );

        // ✅ Assert — no fatal, real alertID returned.
        $this->assertIsInt($alertID, 'create() must not fatally crash even with a non-empty array-typed admin_emails setting');

        // ✅ notify() must have run its FULL body (past the empty-array
        //    early-return) and reached the final UPDATE that marks the alert
        //    as notified — proving the array was genuinely iterated, not
        //    just trivially accepted.
        $this->assertDatabaseHas(
            'tblSecurityAlerts',
            [
                'alertID'          => (string) $alertID,
                'notificationSent' => '1',
            ],
            'notify() must run to completion (past the array-decode fix) and mark notificationSent=1, even though the one configured recipient was an invalid address'
        );
    }
}
