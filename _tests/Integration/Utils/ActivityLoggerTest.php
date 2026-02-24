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
            1,              // userID
            'test_action',  // activityType
            'auth',         // category
            'info',         // severity
            'Test description' // description
        );

        // ----------------------------------------------------------------
        // ✅ Assert – verify the row was written with the correct values
        // ----------------------------------------------------------------
        $this->assertDatabaseHas(
            'tblActivityLog',
            [
                'userID'           => '1',
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
            1,
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
            1,
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
