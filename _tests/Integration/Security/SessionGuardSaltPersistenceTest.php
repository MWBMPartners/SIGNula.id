<?php
/**
 * ============================================================================
 * 🧪 SessionGuard::getSalt() Persistence Regression Test (B-068a)
 * ============================================================================
 *
 * B-068a (HIGH): SessionGuard::getSalt() (web/private_html/security/SessionGuard.php)
 * auto-generates a fingerprinting salt when none is configured and attempts
 * to persist it back to tblSettings via `Database::query()`. The original
 * INSERT referenced a `createdAt` column that does NOT exist on tblSettings
 * (real columns: settingID, settingKey, settingValue, settingType,
 * settingCategory, isSensitive, isEditable, description, defaultValue,
 * validationRules, updatedAt, updatedBy — see
 * _database/signula_complete_install_v2.5.0.sql), so the INSERT threw
 * "Unknown column 'createdAt'" on every call and the salt was NEVER
 * persisted. Because `SessionGuard::$cachedSalt` only survives for the
 * lifetime of a single request, the NEXT request generated a brand-new
 * random salt — so the fingerprint hash (which folds the salt in) changed on
 * every request for a returning user, and every legitimate returning session
 * looked like a hijack attempt. Session fingerprinting was, in effect,
 * completely non-functional.
 *
 * This suite proves, against a REAL signula_test database:
 *   1. getSalt() persists a row to tblSettings with NO SQL error.
 *   2. A second getSalt() call (simulating the NEXT request, via a static-
 *      cache reset + re-reading the persisted value through the getSetting()
 *      stub, exactly as production's loadSettings() would on the next
 *      request) returns the SAME salt — i.e. the salt is genuinely stable
 *      across requests, not just within one.
 *   3. As an end-to-end proof, SessionGuard::createFingerprint() called
 *      twice in a row (fresh static cache each time, same request data)
 *      produces the IDENTICAL fingerprint — the property session
 *      fingerprinting depends on to avoid false-positive hijack detections.
 *
 * @package    SIGNula\Tests\Integration\Security
 * @version    2.7.0-beta
 * @see        web/private_html/security/SessionGuard.php (getSalt())
 * @see        _tests/Integration/Utils/ActivityLoggerTest.php (the identical
 *             singleton-connection-vs-transaction-connection visibility
 *             pattern this suite relies on for assertDatabaseHas()/getRecord())
 */

namespace SIGNula\Tests\Integration\Security;

use SIGNula\Tests\DatabaseTestCase;
use ReflectionClass;

// ============================================================================
// 📦 LOAD SOURCE CLASSES
// ============================================================================

// 🗄️ Database singleton — SessionGuard::getSalt() writes through this,
//    NOT through DatabaseTestCase's rolled-back transaction connection.
requireSource('_config/database.php');

// 🛡️ SessionGuard — the class under test.
requireSource('private_html/security/SessionGuard.php');

/**
 * SessionGuard::getSalt() Persistence Test Suite
 */
class SessionGuardSaltPersistenceTest extends DatabaseTestCase
{
    /**
     * 🔑 The real settings key SessionGuard::getSalt() reads/writes.
     */
    private const SALT_SETTING_KEY = 'security.session_fingerprinting.salt';

    // ========================================================================
    // 🔧 SETUP / TEARDOWN
    // ========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        // 🔧 Session fingerprinting settings (read via the bootstrap
        //    getSetting()/setTestSetting() stub — these do NOT touch
        //    tblSettings; only the Database::query() branch inside
        //    getSalt() does, which is exactly the code path under test).
        setTestSetting('security.session_fingerprinting.enabled', true);
        setTestSetting('security.session_fingerprinting.ip_match', 'subnet');
        setTestSetting('security.session_fingerprinting.on_mismatch', 'invalidate');
        // 🆕 No salt configured — forces getSalt() down the "generate + persist" branch.
        setTestSetting('security.session_fingerprinting.salt', '');

        // 🌐 Deterministic request data for fingerprint tests.
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/TestAgent';
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip, deflate, br';

        $this->resetCachedSalt();

        // 🧹 Defensive cleanup: remove any leftover row from a previous
        //    (e.g. interrupted) run. SessionGuard writes via the Database
        //    SINGLETON connection (autocommit), which is NOT covered by
        //    DatabaseTestCase's rolled-back transaction on a SEPARATE
        //    connection — mirrors the identical gotcha documented in
        //    ActivityLoggerTest.php.
        $this->deleteSaltRow();
    }

    protected function tearDown(): void
    {
        // 🧹 Same reasoning as setUp(): clean up the singleton-connection
        //    write so it doesn't leak into the next test run.
        $this->deleteSaltRow();
        $this->resetCachedSalt();

        parent::tearDown();
    }

    /**
     * 🗑️ Delete the salt setting row via the Database SINGLETON connection
     * (the same connection SessionGuard::getSalt() writes through).
     *
     * @return void
     */
    private function deleteSaltRow(): void
    {
        if (class_exists('Database')) {
            \Database::query(
                'DELETE FROM tblSettings WHERE settingKey = ?',
                [self::SALT_SETTING_KEY],
                's'
            );
        }
    }

    /**
     * 🧹 Reset SessionGuard's internal static salt cache.
     *
     * Mirrors the identical pattern in _tests/Unit/Security/SessionGuardTest.php
     * — SessionGuard::$cachedSalt memoizes the salt for the request lifetime,
     * so it must be cleared to simulate "the next request" within one PHPUnit
     * process.
     *
     * @return void
     */
    private function resetCachedSalt(): void
    {
        $reflection = new ReflectionClass(\SessionGuard::class);
        $prop = $reflection->getProperty('cachedSalt');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * 🔓 Invoke the private static SessionGuard::getSalt() via reflection.
     *
     * @return string The salt returned by getSalt()
     */
    private function invokeGetSalt(): string
    {
        $reflection = new ReflectionClass(\SessionGuard::class);
        $method = $reflection->getMethod('getSalt');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    // ========================================================================
    // ✅ TEST METHODS
    // ========================================================================

    /**
     * B-068a core regression: getSalt() must persist a row to tblSettings
     * with NO SQL error (the old `createdAt` column reference threw
     * "Unknown column 'createdAt'" on every call).
     *
     * @return void
     */
    public function testGetSaltPersistsRowWithoutSqlError(): void
    {
        // 🚀 Act
        $salt = $this->invokeGetSalt();

        // ✅ Assert — a real 32-byte hex salt was generated...
        $this->assertIsString($salt);
        $this->assertEquals(64, strlen($salt), 'Generated salt should be a 32-byte hex string (64 hex chars)');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $salt);

        // ✅ ...and — the actual regression proof — it was PERSISTED to
        //    tblSettings. Before the fix, the INSERT threw and this row
        //    would never exist.
        $this->assertDatabaseHas(
            'tblSettings',
            [
                'settingKey'   => self::SALT_SETTING_KEY,
                'settingValue' => $salt,
            ],
            'getSalt() must persist the generated salt to tblSettings — the pre-fix INSERT referenced a non-existent `createdAt` column and always threw'
        );

        // ✅ settingCategory is NOT NULL with no DB default — confirm it was
        //    supplied (would otherwise have failed the INSERT for a
        //    different reason).
        $record = $this->getRecord('tblSettings', ['settingKey' => self::SALT_SETTING_KEY]);
        $this->assertNotNull($record, 'The persisted salt row must be readable back');
        $this->assertSame('security', $record['settingCategory'], 'settingCategory must be populated (NOT NULL, no default)');
    }

    /**
     * B-068a stability proof: a second getSalt() call — simulating the NEXT
     * HTTP request, via a static-cache reset plus re-reading the persisted
     * value through the getSetting() stub exactly as production's
     * loadSettings() would — must return the SAME salt that was persisted by
     * the first call. Before the fix, every request generated a brand-new
     * random salt because nothing ever reached the database successfully.
     *
     * @return void
     */
    public function testGetSaltReturnsSameSaltOnSubsequentRequest(): void
    {
        // 🚀 Act — "request #1"
        $saltFromFirstCall = $this->invokeGetSalt();

        // 🔁 Simulate "the next request": reset the in-process static cache...
        $this->resetCachedSalt();

        // ...and feed getSetting() the value now sitting in tblSettings —
        // exactly what config.php's loadSettings() would do on a real next
        // request (SELECT settingValue FROM tblSettings WHERE settingKey = ...).
        $persisted = $this->getRecord('tblSettings', ['settingKey' => self::SALT_SETTING_KEY]);
        $this->assertNotNull($persisted, 'A salt row must already exist after the first getSalt() call');
        setTestSetting(self::SALT_SETTING_KEY, $persisted['settingValue']);

        // 🚀 Act — "request #2"
        $saltFromSecondCall = $this->invokeGetSalt();

        // ✅ Assert — the SAME salt, proving persistence actually round-trips.
        $this->assertSame(
            $saltFromFirstCall,
            $saltFromSecondCall,
            'getSalt() must return the SAME persisted salt on a subsequent request — a fresh random salt every request means fingerprinting never validates a real returning session'
        );

        // 🧹 No duplicate row should have been created (ON DUPLICATE KEY UPDATE).
        $this->assertEquals(
            1,
            $this->countRecords('tblSettings', ['settingKey' => self::SALT_SETTING_KEY]),
            'Exactly one settings row should exist for the salt key — no duplicate inserted on the second call'
        );
    }

    /**
     * B-068a end-to-end proof: with a persisted, stable salt, two
     * SessionGuard::createFingerprint() calls for the SAME request data
     * (fresh static cache each time, simulating two separate requests from
     * the same client) must produce the IDENTICAL fingerprint hash. This is
     * the actual security property session fingerprinting depends on — a
     * changing salt between requests would make every returning session look
     * like a hijack.
     *
     * @return void
     */
    public function testFingerprintIsStableAcrossSimulatedRequestsWithPersistedSalt(): void
    {
        // 🚀 Act — "request #1": generates + persists the salt, then hashes.
        \SessionGuard::createFingerprint();
        $hash1 = $_SESSION['session_fingerprint'];

        // 🔁 Simulate "the next request": reset the static cache and feed
        //    getSetting() the now-persisted salt (as loadSettings() would).
        $this->resetCachedSalt();
        $persisted = $this->getRecord('tblSettings', ['settingKey' => self::SALT_SETTING_KEY]);
        $this->assertNotNull($persisted, 'A salt row must exist after the first createFingerprint() call');
        setTestSetting(self::SALT_SETTING_KEY, $persisted['settingValue']);

        // 🚀 Act — "request #2": same client, same salt should be re-read.
        \SessionGuard::createFingerprint();
        $hash2 = $_SESSION['session_fingerprint'];

        // ✅ Assert — identical fingerprint for the identical client across
        //    two simulated requests.
        $this->assertSame(
            $hash1,
            $hash2,
            'The same client must produce the SAME fingerprint across requests once the salt is persisted — a changing salt breaks fingerprint validation for every returning session'
        );
    }
}
