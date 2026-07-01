<?php
/**
 * ============================================================================
 * 🧪 ActivityLogger Integration Tests
 * ============================================================================
 *
 * Integration tests for the ActivityLogger utility class.
 * Verifies that activity records are correctly written to tblActivityLog,
 * that request context (IP, user-agent) is captured from superglobals,
 * and that arbitrary metadata is persisted as a JSON-encoded column.
 *
 * All tests run inside a transaction that is rolled back in tearDown(),
 * so the tblActivityLog table remains clean between test runs.
 *
 * Dependencies loaded via requireSource():
 *   - _config/database.php      → Database class (MySQLi singleton)
 *   - private_html/utils/ActivityLogger.php → ActivityLogger class
 *
 * @package    SIGNula\Tests\Integration\Utils
 * @version    2.6.0-beta
 * @see        web/private_html/utils/ActivityLogger.php
 * @see        web/_config/database.php
 */

namespace SIGNula\Tests\Integration\Utils;

use SIGNula\Tests\DatabaseTestCase;

// ============================================================================
// 📦 LOAD SOURCE CLASSES
// ============================================================================

// 🗄️ Database singleton – must be loaded before any class that calls Database::query()
requireSource('_config/database.php');

// 📝 ActivityLogger – the class under test
requireSource('private_html/utils/ActivityLogger.php');

/**
 * ActivityLogger Integration Test Suite
 *
 * Exercises ActivityLogger::log() against a live test database, confirming
 * that each call persists the expected columns to tblActivityLog.
 *
 * Extends DatabaseTestCase which:
 *   - Opens a MySQLi connection via mock_database()
 *   - Wraps every test in a transaction (auto-rolled-back in tearDown)
 *   - Exposes assertDatabaseHas(), assertDatabaseMissing(), getRecord(),
 *     countRecords() and insertRecord() helpers
 */
class ActivityLoggerTest extends DatabaseTestCase
{
    // ========================================================================
    // ⚙️ TEST CONFIGURATION
    // ========================================================================

    /**
     * 🗑️ Tables to truncate before each test
     *
     * Ensures tblActivityLog starts empty so assertions only see records
     * created within the current test, even when transactions are in use.
     *
     * @var array<int, string>
     */
    protected array $truncateTables = ['tblActivityLog'];

    /**
     * 🆔 ID of the committed test user this suite logs against.
     *
     * 🔧 B-043 (hermeticity): ActivityLogger::log() writes via the Database
     *    SINGLETON connection, NOT the connection DatabaseTestCase wraps in a
     *    rolled-back transaction — and tblActivityLog.userID has a FOREIGN KEY
     *    to tblUsers.userID. The tests that pass a non-NULL userID previously
     *    hard-coded '1', assuming a user #1 always exists. Under PHPUnit random
     *    order, a prior test (e.g. AuthLoginTest, which truncates tblUsers)
     *    could leave tblUsers without id 1, so the singleton's INSERT hit the FK
     *    constraint → log() returned false → these tests failed order-dependently
     *    (deterministically reproducible with --random-order-seed=1782905012).
     *
     *    Fix: create a real user ON THE SINGLETON CONNECTION in setUp (so it is
     *    committed and therefore visible to the singleton's FK check — a user
     *    inserted on the test's uncommitted transaction connection would NOT be
     *    visible cross-connection) and log against that guaranteed-present ID.
     *    tearDown removes it (CASCADE also clears any rows it authored).
     *
     * @var int
     */
    protected int $testUserID = 0;

    // ========================================================================
    // 🔧 TEST LIFECYCLE
    // ========================================================================

    /**
     * 🔧 Set up per-test state
     *
     * Calls the parent setUp (which opens a DB connection, begins a
     * transaction, and truncates $truncateTables), then resets the
     * $_SERVER superglobal entries used by the getClientIP() and
     * getUserAgent() bootstrap stubs to predictable defaults so each
     * test starts from a known baseline.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 🌐 Provide known default values for the context-capture stubs
        // defined in _tests/bootstrap.php.  Individual tests may override
        // these values to exercise specific capture behaviour.
        $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/TestAgent';

        // 👤 Create a committed test user on the SINGLETON connection so the
        //    tblActivityLog.userID foreign key is satisfiable for the non-NULL
        //    userID tests, regardless of what other tests did to tblUsers.
        $this->testUserID = $this->createSingletonTestUser();
    }

    /**
     * 🧹 Tear down per-test state
     *
     * Removes the committed test user created in setUp (its writes live on the
     * singleton connection outside the rolled-back transaction, so they must be
     * cleaned up explicitly). ON DELETE CASCADE on the tblActivityLog FK also
     * clears any activity rows authored by this user. Then defers to the parent
     * tearDown (transaction rollback + output-buffer housekeeping).
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->testUserID > 0) {
            // 🗑️ Delete via the singleton connection (same one that created it).
            \Database::query(
                'DELETE FROM tblUsers WHERE userID = ?',
                [$this->testUserID],
                'i'
            );
            $this->testUserID = 0;
        }

        parent::tearDown();
    }

    /**
     * 👤 Create a minimal, committed test user via the Database SINGLETON.
     *
     * ActivityLogger::log() uses the Database singleton connection, so any user
     * referenced by its INSERT must exist and be COMMITTED on a connection the
     * singleton can see. Creating the user with Database::query() (autocommit,
     * not inside DatabaseTestCase's rolled-back transaction) guarantees that.
     *
     * @return int The new user's auto-increment ID.
     */
    private function createSingletonTestUser(): int
    {
        // 🆔 tblUsers requires userUUID + username (NOT NULL, UNIQUE, no default).
        $suffix   = bin2hex(random_bytes(4));
        $uuid     = self::generateTestUuid();
        $username = 'activitylog_test_' . $suffix;
        $email    = 'activitylog_' . $suffix . '@example.test';

        // 🏠 Database::insert() is the house idiom — runs the prepared INSERT
        //    via the singleton and returns the new auto-increment id.
        return \Database::insert(
            'INSERT INTO tblUsers
                (userUUID, username, email, passwordHash, displayName,
                 accountStatus, emailVerified, mfaEnabled, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $uuid,
                $username,
                $email,
                password_hash('password123', PASSWORD_ARGON2ID),
                'ActivityLogger Test User',
                'active',
                1,
                0,
            ],
            'ssssssii'
        );
    }

    // ========================================================================
    // ✅ TEST METHODS
    // ========================================================================

    /**
     * Test that ActivityLogger::log() inserts a row into tblActivityLog
     *
     * This is the core "happy path" test.  We call log() with a fixed set
     * of arguments and then use assertDatabaseHas() to confirm that a row
     * carrying each of those values exists in the table.
     *
     * @return void
     */
    public function testLogCreatesActivityRecord(): void
    {
        // ----------------------------------------------------------------
        // 🚀 Act – call the method under test
        // ----------------------------------------------------------------
        \ActivityLogger::log(
            $this->testUserID, // userID (committed test user — FK-safe)
            'test_action',     // activityType
            'auth',            // category
            'info',            // severity
            'Test description' // description
        );

        // ----------------------------------------------------------------
        // ✅ Assert – verify the row was written with the correct values
        // ----------------------------------------------------------------
        $this->assertDatabaseHas(
            'tblActivityLog',
            [
                'userID'           => (string) $this->testUserID,
                'activityType'     => 'test_action',
                'activityCategory' => 'auth',
                'severity'         => 'info',
                'description'      => 'Test description',
            ],
            'ActivityLogger::log() should insert a row with all supplied field values'
        );
    }

    /**
     * Test that ActivityLogger::log() captures the caller's IP address
     *
     * The bootstrap stub for getClientIP() reads $_SERVER['REMOTE_ADDR'],
     * so by setting that superglobal before calling log() we can confirm
     * the IP reaches the database column.
     *
     * @return void
     */
    public function testLogCapturesIPAddress(): void
    {
        // ----------------------------------------------------------------
        // 🌐 Arrange – set a recognisable IP address in the superglobal
        // ----------------------------------------------------------------
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';

        // ----------------------------------------------------------------
        // 🚀 Act
        // ----------------------------------------------------------------
        \ActivityLogger::log(
            null,
            'ip_capture_test',
            'security',
            'info',
            'IP address capture test'
        );

        // ----------------------------------------------------------------
        // ✅ Assert – ipAddress column should hold exactly what we set
        // ----------------------------------------------------------------
        $this->assertDatabaseHas(
            'tblActivityLog',
            [
                'activityType' => 'ip_capture_test',
                'ipAddress'    => '192.168.1.1',
            ],
            'ActivityLogger should store the client IP address from $_SERVER[REMOTE_ADDR]'
        );
    }

    /**
     * Test that ActivityLogger::log() captures the caller's user-agent string
     *
     * The bootstrap stub for getUserAgent() reads $_SERVER['HTTP_USER_AGENT'],
     * so setting that superglobal lets us verify the value is persisted to the
     * userAgent column in tblActivityLog.
     *
     * @return void
     */
    public function testLogCapturesUserAgent(): void
    {
        // ----------------------------------------------------------------
        // 🖥️ Arrange – use a distinctive user-agent string
        // ----------------------------------------------------------------
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';

        // ----------------------------------------------------------------
        // 🚀 Act
        // ----------------------------------------------------------------
        \ActivityLogger::log(
            null,
            'ua_capture_test',
            'other',
            'info',
            'User-agent capture test'
        );

        // ----------------------------------------------------------------
        // ✅ Assert
        // ----------------------------------------------------------------
        $this->assertDatabaseHas(
            'tblActivityLog',
            [
                'activityType' => 'ua_capture_test',
                'userAgent'    => 'TestBrowser/1.0',
            ],
            'ActivityLogger should store the HTTP_USER_AGENT string in the userAgent column'
        );
    }

    /**
     * Test that ActivityLogger::log() stores arbitrary metadata as JSON
     *
     * The $metadata parameter is JSON-encoded before being inserted.
     * We retrieve the raw database record and decode the stored string
     * to confirm the round-trip is lossless.
     *
     * @return void
     */
    public function testLogStoresMetadataAsJson(): void
    {
        // ----------------------------------------------------------------
        // 📋 Arrange – a metadata array with a representative mix of types
        // ----------------------------------------------------------------
        $metadata = [
            'key'    => 'value',
            'count'  => 42,
            'nested' => ['a' => true],
        ];

        // ----------------------------------------------------------------
        // 🚀 Act
        // ----------------------------------------------------------------
        \ActivityLogger::log(
            $this->testUserID, // committed test user — FK-safe
            'metadata_test',
            'other',
            'info',
            'Metadata serialisation test',
            $metadata   // 📦 this is the $metadata argument
        );

        // ----------------------------------------------------------------
        // ✅ Assert – fetch the raw row and decode the stored JSON
        // ----------------------------------------------------------------
        $record = $this->getRecord('tblActivityLog', ['activityType' => 'metadata_test']);

        $this->assertNotNull(
            $record,
            'A row with activityType "metadata_test" should exist in tblActivityLog'
        );

        // 📝 The metadata column should be valid JSON
        $this->assertNotNull(
            $record['metadata'],
            'The metadata column should not be NULL'
        );

        $decoded = json_decode($record['metadata'], true);

        $this->assertEquals(
            JSON_ERROR_NONE,
            json_last_error(),
            'The metadata column value should be valid JSON'
        );

        $this->assertEquals(
            $metadata,
            $decoded,
            'Decoded metadata should exactly match the original array passed to log()'
        );
    }

    /**
     * Test that ActivityLogger::log() handles a NULL userID without error
     *
     * System-level events (e.g. cron jobs, unauthenticated page visits)
     * are logged without associating them with a specific user account.
     * A NULL userID is valid and must not cause a query failure.
     *
     * @return void
     */
    public function testLogHandlesNullUserID(): void
    {
        // ----------------------------------------------------------------
        // 🚀 Act – pass null explicitly as the userID argument
        // ----------------------------------------------------------------
        \ActivityLogger::log(
            null,           // 👈 null userID – the case under test
            'system_event',
            'system',
            'info',
            'System-level event with no associated user'
        );

        // ----------------------------------------------------------------
        // ✅ Assert – the row should exist regardless of userID being NULL
        // ----------------------------------------------------------------
        $this->assertDatabaseHas(
            'tblActivityLog',
            ['activityType' => 'system_event'],
            'ActivityLogger::log() should succeed and persist a row even when userID is null'
        );

        // 📝 Verify the userID column really is NULL in the stored row
        $record = $this->getRecord('tblActivityLog', ['activityType' => 'system_event']);
        $this->assertNull(
            $record['userID'],
            'The stored userID should be NULL when log() is called with null'
        );
    }

    /**
     * Test that ActivityLogger::log() returns TRUE on a successful insert
     *
     * The return value allows callers to check whether logging succeeded
     * (e.g. to conditionally log a warning if the audit trail is broken).
     *
     * @return void
     */
    public function testLogReturnsTrueOnSuccess(): void
    {
        // ----------------------------------------------------------------
        // 🚀 Act – capture the return value
        // ----------------------------------------------------------------
        $result = \ActivityLogger::log(
            $this->testUserID, // committed test user — FK-safe
            'return_value_test',
            'auth',
            'info',
            'Return value assertion test'
        );

        // ----------------------------------------------------------------
        // ✅ Assert – must be boolean true, not just truthy
        // ----------------------------------------------------------------
        $this->assertTrue(
            $result,
            'ActivityLogger::log() should return (bool) true on a successful database insert'
        );
    }
}
