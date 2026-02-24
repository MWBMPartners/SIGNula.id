<?php
/**
 * ============================================================================
 * 🧪 ErrorLogger Integration Tests
 * ============================================================================
 *
 * Integration tests for the ErrorLogger utility class.
 * Verifies that error records are correctly written to tblErrorLog,
 * that the debug backtrace is captured and persisted, that sensitive
 * POST fields are automatically redacted before storage, and that the
 * method returns the expected boolean result.
 *
 * All tests run inside a transaction that is rolled back in tearDown(),
 * so tblErrorLog remains clean between test runs.
 *
 * Dependencies loaded via requireSource():
 *   - _config/database.php                  → Database class (MySQLi singleton)
 *   - private_html/security/SecurityUtils.php → SecurityUtils (used by Auth)
 *   - private_html/utils/ActivityLogger.php   → ActivityLogger (used by Auth)
 *   - private_html/utils/ErrorLogger.php      → ErrorLogger (class under test)
 *   - private_html/auth/Auth.php              → Auth (ErrorLogger calls Auth::getCurrentUserID)
 *
 * @package    SIGNula\Tests\Integration\Utils
 * @version    2.6.0-beta
 * @see        web/private_html/utils/ErrorLogger.php
 * @see        web/_config/database.php
 * @see        web/private_html/auth/Auth.php
 */

namespace SIGNula\Tests\Integration\Utils;

use SIGNula\Tests\DatabaseTestCase;

// ============================================================================
// 📦 LOAD SOURCE CLASSES
// ============================================================================

// 🗄️ Database singleton – required by every class that calls Database::query()
requireSource('_config/database.php');

// 🔐 SecurityUtils – required by Auth (password hashing / sanitisation)
requireSource('private_html/security/SecurityUtils.php');

// 📝 ActivityLogger – required by Auth (logs login/logout activity)
requireSource('private_html/utils/ActivityLogger.php');

// ❌ ErrorLogger – the primary class under test
requireSource('private_html/utils/ErrorLogger.php');

// 🔑 Auth – ErrorLogger calls Auth::getCurrentUserID() internally to associate
//            the logged error with the current session's user (may be null)
requireSource('private_html/auth/Auth.php');

/**
 * ErrorLogger Integration Test Suite
 *
 * Exercises ErrorLogger::logError() against a live test database, confirming
 * that each call persists the expected columns to tblErrorLog and that
 * optional request-context data (backtrace, sensitive fields, nullable file)
 * is handled correctly.
 *
 * Extends DatabaseTestCase which:
 *   - Opens a MySQLi connection via mock_database()
 *   - Wraps every test in a transaction (auto-rolled-back in tearDown)
 *   - Exposes assertDatabaseHas(), assertDatabaseMissing(), getRecord(),
 *     countRecords() and insertRecord() helpers
 */
class ErrorLoggerTest extends DatabaseTestCase
{
    // ========================================================================
    // ⚙️ TEST CONFIGURATION
    // ========================================================================

    /**
     * 🗑️ Tables to truncate before each test
     *
     * Ensures tblErrorLog starts empty so assertions only see records
     * created within the current test, even when transactions are in use.
     *
     * @var array<int, string>
     */
    protected array $truncateTables = ['tblErrorLog'];

    // ========================================================================
    // 🔧 TEST LIFECYCLE
    // ========================================================================

    /**
     * 🔧 Set up per-test state
     *
     * Calls the parent setUp (which opens a DB connection, begins a
     * transaction, and truncates $truncateTables), then ensures the
     * session and Auth static state are cleared so getCurrentUserID()
     * returns null by default and individual tests can opt-in to a
     * simulated authenticated state via actingAs().
     *
     * Also resets relevant $_SERVER superglobals to known defaults so
     * context-capture stubs (getClientIP, getUserAgent, getCurrentURL)
     * behave consistently across tests.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 🔑 Reset Auth's static cache so getCurrentUserID() returns null
        // unless a test explicitly calls actingAs()
        $reflection = new \ReflectionClass(\Auth::class);
        $prop = $reflection->getProperty('currentUserID');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // 🌐 Provide known default values for the context-capture stubs
        // defined in _tests/bootstrap.php. Individual tests may override
        // these to exercise specific capture behaviour.
        $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/TestAgent';
    }

    // ========================================================================
    // ✅ TEST METHODS
    // ========================================================================

    /**
     * Test that ErrorLogger::logError() inserts a row into tblErrorLog
     *
     * This is the core "happy path" test.  We call logError() with the
     * minimum required arguments and confirm the row appears in the table
     * carrying the exact field values supplied.
     *
     * @return void
     */
    public function testLogErrorCreatesRecord(): void
    {
        // ----------------------------------------------------------------
        // 🚀 Act – call the method under test with realistic arguments
        // ----------------------------------------------------------------
        \ErrorLogger::logError(
            'RuntimeException',     // errorType
            'Test error',           // errorMessage
            __FILE__,               // errorFile  – current test file path
            __LINE__                // errorLine  – current line number
        );

        // ----------------------------------------------------------------
        // ✅ Assert – verify the row was written with the supplied values
        // ----------------------------------------------------------------
        $this->assertDatabaseHas(
            'tblErrorLog',
            [
                'errorType'    => 'RuntimeException',
                'errorMessage' => 'Test error',
            ],
            'ErrorLogger::logError() should insert a row with the provided errorType and errorMessage'
        );

        // 📝 Also confirm the file path was captured
        $record = $this->getRecord('tblErrorLog', ['errorType' => 'RuntimeException']);

        $this->assertNotNull(
            $record,
            'A tblErrorLog record should exist after calling logError()'
        );

        $this->assertStringContainsString(
            'ErrorLoggerTest.php',
            $record['errorFile'] ?? '',
            'The errorFile column should contain the path of the file passed to logError()'
        );
    }

    /**
     * Test that ErrorLogger::logError() captures and stores a backtrace
     *
     * ErrorLogger calls debug_backtrace() internally and formats the result
     * into a multi-line string stored in the errorTrace column.  The column
     * must be non-empty after a successful insert, confirming the backtrace
     * capture pipeline (debug_backtrace → formatBacktrace → INSERT) works
     * end-to-end.
     *
     * @return void
     */
    public function testLogErrorCapturesBacktrace(): void
    {
        // ----------------------------------------------------------------
        // 🚀 Act
        // ----------------------------------------------------------------
        \ErrorLogger::logError(
            'LogicException',
            'Backtrace capture test',
            __FILE__,
            __LINE__
        );

        // ----------------------------------------------------------------
        // ✅ Assert – retrieve the raw row and inspect errorTrace
        // ----------------------------------------------------------------
        $record = $this->getRecord('tblErrorLog', ['errorType' => 'LogicException']);

        $this->assertNotNull(
            $record,
            'A tblErrorLog record should exist for errorType "LogicException"'
        );

        $this->assertNotEmpty(
            $record['errorTrace'],
            'The errorTrace column should not be empty – debug_backtrace() should have been captured'
        );

        // 📝 A formatted backtrace should contain at least one "#N " frame marker
        $this->assertMatchesRegularExpression(
            '/#\d+/',
            $record['errorTrace'],
            'The stored backtrace should contain at least one numbered stack frame (e.g. "#0 ...")'
        );
    }

    /**
     * Test that ErrorLogger::logError() redacts sensitive POST fields
     *
     * The getRequestData() helper inside ErrorLogger replaces values for
     * known sensitive keys (password, token, secret, apikey, api_key)
     * with the literal string '[REDACTED]' before the data is JSON-encoded
     * and stored.  This test verifies that the raw password value does NOT
     * appear in the stored requestData and that '[REDACTED]' is present in
     * its place.
     *
     * @return void
     */
    public function testLogErrorRedactsSensitiveFields(): void
    {
        // ----------------------------------------------------------------
        // 🔐 Arrange – inject a sensitive POST field into the superglobal
        // ----------------------------------------------------------------
        $_POST['password'] = 'secret123';
        $_POST['username'] = 'johndoe';   // 📝 non-sensitive field – should NOT be redacted

        // ----------------------------------------------------------------
        // 🚀 Act
        // ----------------------------------------------------------------
        \ErrorLogger::logError(
            'SecurityEvent',
            'Sensitive field redaction test',
            __FILE__,
            __LINE__
        );

        // ----------------------------------------------------------------
        // ✅ Assert – decode the stored requestData JSON and inspect it
        // ----------------------------------------------------------------
        $record = $this->getRecord('tblErrorLog', ['errorType' => 'SecurityEvent']);

        $this->assertNotNull(
            $record,
            'A tblErrorLog record should exist for errorType "SecurityEvent"'
        );

        $this->assertNotNull(
            $record['requestData'],
            'The requestData column should not be NULL'
        );

        // 📦 Decode the stored JSON
        $requestData = json_decode($record['requestData'], true);

        $this->assertEquals(
            JSON_ERROR_NONE,
            json_last_error(),
            'The requestData column value should be valid JSON'
        );

        // 🔐 The raw password must NOT appear anywhere in the stored data
        $this->assertStringNotContainsString(
            'secret123',
            $record['requestData'],
            'The actual password value must not appear in the stored requestData'
        );

        // ✅ '[REDACTED]' placeholder should replace the sensitive value
        $this->assertEquals(
            '[REDACTED]',
            $requestData['POST']['password'] ?? null,
            'The password POST field should be replaced with "[REDACTED]" in the stored requestData'
        );

        // 📝 The non-sensitive field should be stored as-is
        $this->assertEquals(
            'johndoe',
            $requestData['POST']['username'] ?? null,
            'Non-sensitive POST fields should be stored unchanged in requestData'
        );
    }

    /**
     * Test that ErrorLogger::logError() handles a NULL errorFile gracefully
     *
     * The errorFile parameter is declared nullable (string|null).  Passing
     * null is valid for programmatic errors or error sources that are not
     * tied to a physical file, and must not cause a query failure or
     * exception.
     *
     * @return void
     */
    public function testLogErrorHandlesNullFile(): void
    {
        // ----------------------------------------------------------------
        // 🚀 Act – pass null for both errorFile and errorLine
        // ----------------------------------------------------------------
        \ErrorLogger::logError(
            'UnknownError',
            'No file information available',
            null,   // 👈 null errorFile – the case under test
            null    // 👈 null errorLine for consistency
        );

        // ----------------------------------------------------------------
        // ✅ Assert – the row should exist and errorFile should be NULL
        // ----------------------------------------------------------------
        $this->assertDatabaseHas(
            'tblErrorLog',
            ['errorType' => 'UnknownError'],
            'ErrorLogger::logError() should succeed even when errorFile is null'
        );

        $record = $this->getRecord('tblErrorLog', ['errorType' => 'UnknownError']);

        $this->assertNull(
            $record['errorFile'],
            'The errorFile column should be NULL when null is passed to logError()'
        );

        $this->assertNull(
            $record['errorLine'],
            'The errorLine column should be NULL when null is passed to logError()'
        );
    }

    /**
     * Test that ErrorLogger::logError() returns TRUE on a successful insert
     *
     * The return value signals whether the error was successfully persisted.
     * Callers that need to fall back to file-based logging check this value,
     * so it must be the boolean true (not merely truthy) on success.
     *
     * @return void
     */
    public function testLogErrorReturnsTrueOnSuccess(): void
    {
        // ----------------------------------------------------------------
        // 🚀 Act – capture the return value
        // ----------------------------------------------------------------
        $result = \ErrorLogger::logError(
            'RuntimeException',
            'Return value assertion test',
            __FILE__,
            __LINE__
        );

        // ----------------------------------------------------------------
        // ✅ Assert – must be boolean true, not just truthy
        // ----------------------------------------------------------------
        $this->assertTrue(
            $result,
            'ErrorLogger::logError() should return (bool) true on a successful database insert'
        );
    }
}
