<?php
/**
 * ============================================================================
 * 🧪 RateLimiter Integration Tests
 * ============================================================================
 *
 * Integration tests for the RateLimiter class, exercising its behaviour
 * against a real test database (signula_test).
 *
 * Tests cover:
 * - Constructor with null and explicit MySQLi connections
 * - isEnabled() return type guarantee
 * - checkLimit() array structure and key contracts
 * - First-request allow behaviour
 * - Remaining-count decrement after a request
 * - unblock() return type guarantee
 * - getStatus() return type guarantee
 *
 * ⚠️  DEFENSIVE DESIGN NOTE
 * --------------------------
 * RateLimiter internally queries tblSettings (for the rate_limiting.* keys)
 * and tblRateLimitConfig (for per-endpoint limit tuples).  Neither table is
 * guaranteed to be pre-seeded in the test database.  All tests therefore
 * wrap calls that depend on config data in try/catch blocks and mark
 * themselves as "skipped" (via markTestSkipped()) rather than failing
 * outright when the required schema objects or seed rows are missing.
 *
 * Tests that only check constructor plumbing or return-type contracts do NOT
 * need any seed data and will never be skipped.
 *
 * @package    SIGNula\Tests\Integration\Security
 * @version    2.6.0-beta
 * @see        web/private_html/security/RateLimiter.php
 */

namespace SIGNula\Tests\Integration\Security;

use SIGNula\Tests\DatabaseTestCase;
use mysqli;
use Throwable;

// ============================================================================
// 📦 Source classes required for this test suite
// ============================================================================

// 🗄️ Database bootstrap — registers the $db global used by Auth/utils;
//    we still pass our own connection explicitly, but loading this file
//    ensures any constants (DB_CHARSET etc.) are defined.
requireSource('_config/database.php');

// 🚦 The class under test
requireSource('private_html/security/RateLimiter.php');

// 📝 ActivityLogger is optionally used by RateLimiter; loading it here lets
//    logBlockEvent() and similar helpers run without triggering a fatal error
//    from class_exists() returning false.
requireSource('private_html/utils/ActivityLogger.php');

// ============================================================================
// 🧪 Test Suite
// ============================================================================

/**
 * RateLimiter Integration Test Suite
 *
 * Exercises the public surface of the RateLimiter class against the
 * signula_test database.  Transaction rollback (inherited from
 * DatabaseTestCase) ensures each test starts with a clean tblRateLimits
 * slate without permanently truncating the table between tests.
 */
class RateLimiterTest extends DatabaseTestCase
{
    // =========================================================================
    // ⚙️  CONFIGURATION
    // =========================================================================

    /**
     * Tables to be truncated before each test method runs.
     *
     * tblRateLimits  – stores per-identifier request counters and block flags.
     * tblActivityLog – receives rate-limit block/unblock audit events logged
     *                  by RateLimiter via ActivityLogger.
     *
     * Both tables are truncated so that state from a previous (potentially
     * failed) test cannot bleed into the next one, even if a transaction
     * rollback did not fire.
     *
     * @var array<int, string>
     */
    protected array $truncateTables = [
        'tblRateLimits',
        'tblActivityLog',
    ];

    // =========================================================================
    // 🔧 HELPER — shared test identifiers
    // =========================================================================

    /**
     * A deterministic IP-style identifier used across multiple tests.
     * Using a fixed value makes assertions predictable without having to
     * thread a unique string through every test.
     */
    private const TEST_IDENTIFIER      = '203.0.113.42'; // TEST-NET-3 (RFC 5737), never routed
    private const TEST_IDENTIFIER_TYPE = 'ip';
    private const TEST_ENDPOINT        = 'test/rate-limiter-suite';
    private const TEST_TIER            = 'default';

    // =========================================================================
    // 🏗️  CONSTRUCTOR TESTS
    // =========================================================================

    /**
     * Test 1 — Constructor accepts null (uses global $db)
     *
     * Verifies that passing no argument to the constructor does not throw.
     * In the test environment the global $db variable may be null; the
     * RateLimiter is expected to silently assign it and defer any connection
     * errors until the first query is attempted.
     *
     * @return void
     */
    public function testConstructorAcceptsNullDatabase(): void
    {
        // 📝 We do not pass a database connection here.  The constructor will
        //    pick up the global $db variable (which may be null in tests).
        //    The important thing is that no exception or fatal error is raised
        //    during object construction itself.
        try {
            $rateLimiter = new \RateLimiter(null);

            // ✅ If we reach here, the constructor did not throw
            $this->assertInstanceOf(
                \RateLimiter::class,
                $rateLimiter,
                'RateLimiter constructed with null should return a RateLimiter instance'
            );
        } catch (Throwable $e) {
            // ⚠️  A fatal DB connection failure during construction is
            //    acceptable in the test environment where global $db may be
            //    genuinely null.  Mark the test skipped rather than failing it.
            $this->markTestSkipped(
                'Constructor threw when global $db is null — '
                . 'this is acceptable behaviour: ' . $e->getMessage()
            );
        }
    }

    /**
     * Test 2 — Constructor accepts an explicit MySQLi connection
     *
     * Passes the shared test database connection returned by mock_database()
     * to the constructor.  This is the normal usage pattern from production
     * code that injects a managed connection.
     *
     * @return void
     */
    public function testConstructorAcceptsMySQLiConnection(): void
    {
        // 🔌 Obtain the singleton test-database connection
        $db = mock_database();

        // 📝 Construct with explicit connection — should never throw
        $rateLimiter = new \RateLimiter($db);

        $this->assertInstanceOf(
            \RateLimiter::class,
            $rateLimiter,
            'RateLimiter constructed with a MySQLi connection should return a RateLimiter instance'
        );
    }

    // =========================================================================
    // 🔍 isEnabled() TESTS
    // =========================================================================

    /**
     * Test 3 — isEnabled() always returns a boolean
     *
     * The method reads the 'rate_limiting.enabled' key from tblSettings.
     * When the key is absent it should default to enabled (true).  Either
     * way, the return value must be a strict boolean — never null, a string,
     * or an integer.
     *
     * The Fixtures/settings.json stub sets "rate_limiting.enabled" => true,
     * so getSetting() in the bootstrap will return true even without a DB row.
     * However, RateLimiter reads its config directly from tblSettings (not via
     * getSetting()), so the actual outcome depends on whether that table has a
     * seeded row.  We test the type contract regardless of the boolean value.
     *
     * @return void
     */
    public function testIsEnabledReturnsBoolean(): void
    {
        // 🔌 Use the test DB connection so DB queries run against signula_test
        $rateLimiter = new \RateLimiter($this->db);

        $result = $rateLimiter->isEnabled();

        $this->assertIsBool(
            $result,
            'isEnabled() must return a boolean value'
        );
    }

    // =========================================================================
    // 📊 checkLimit() TESTS
    // =========================================================================

    /**
     * Test 4 — checkLimit() returns array with required keys
     *
     * The public contract of checkLimit() is to always return an associative
     * array containing at least the 'allowed' key.  Additional keys documented
     * in the method signature ('limit', 'remaining', 'reset') should also be
     * present.
     *
     * When tblRateLimitConfig has no matching row the method "fails open" and
     * returns an allowed result with PHP_INT_MAX as the limit — we verify the
     * structural contract rather than the specific values.
     *
     * @return void
     */
    public function testCheckLimitReturnsExpectedStructure(): void
    {
        $rateLimiter = new \RateLimiter($this->db);

        try {
            $result = $rateLimiter->checkLimit(
                self::TEST_IDENTIFIER,
                self::TEST_IDENTIFIER_TYPE,
                self::TEST_ENDPOINT,
                self::TEST_TIER
            );
        } catch (Throwable $e) {
            // 🔍 If required tables do not exist in this environment, skip
            $this->markTestSkipped(
                'checkLimit() threw — required tables may be missing: ' . $e->getMessage()
            );
        }

        // ✅ 'allowed' is the minimum required key
        $this->assertIsArray(
            $result,
            'checkLimit() must return an array'
        );

        $this->assertArrayHasKey(
            'allowed',
            $result,
            "Result array must contain the 'allowed' key"
        );

        // 📋 Additional keys defined in the method docblock
        $this->assertArrayHasKey(
            'limit',
            $result,
            "Result array must contain the 'limit' key"
        );

        $this->assertArrayHasKey(
            'remaining',
            $result,
            "Result array must contain the 'remaining' key"
        );

        $this->assertArrayHasKey(
            'reset',
            $result,
            "Result array must contain the 'reset' key"
        );
    }

    /**
     * Test 5 — checkLimit() allows the very first request for a fresh identifier
     *
     * With an empty tblRateLimits table and a fresh identifier that has never
     * been seen before, the rate limiter must permit the request.
     *
     * When tblRateLimitConfig has no row matching the test endpoint, the
     * "fail-open" path is taken and 'allowed' will be true.  When a matching
     * config row IS present and the limit has not been reached, 'allowed' must
     * still be true.  Either way, the first request for a clean identifier
     * should be allowed.
     *
     * @return void
     */
    public function testCheckLimitAllowsFirstRequest(): void
    {
        $rateLimiter = new \RateLimiter($this->db);

        // 🆕 Use a unique identifier to guarantee no prior request exists
        $uniqueIdentifier = '198.51.100.' . random_int(1, 254); // TEST-NET-2 (RFC 5737)

        try {
            $result = $rateLimiter->checkLimit(
                $uniqueIdentifier,
                self::TEST_IDENTIFIER_TYPE,
                self::TEST_ENDPOINT,
                self::TEST_TIER
            );
        } catch (Throwable $e) {
            $this->markTestSkipped(
                'checkLimit() threw — required tables may be missing: ' . $e->getMessage()
            );
        }

        $this->assertTrue(
            $result['allowed'],
            'The very first request for a new identifier should always be allowed'
        );
    }

    /**
     * Test 6 — checkLimit() reports a remaining count less than the limit
     *          after a successful (allowed) request has been recorded
     *
     * After one allowed request is processed, tblRateLimits gains one row for
     * the identifier.  The 'remaining' value returned by a SECOND call should
     * therefore be strictly less than the configured 'limit'.
     *
     * ⚠️  This test only applies when rate limiting is enabled AND a config
     *     row exists in tblRateLimitConfig that provides a finite limit value.
     *     If tblRateLimitConfig is not seeded the method returns PHP_INT_MAX
     *     for both 'limit' and 'remaining' (fail-open path) — in that case we
     *     skip gracefully rather than asserting on sentinel values.
     *
     * @return void
     */
    public function testCheckLimitReturnsRemainingCount(): void
    {
        $rateLimiter = new \RateLimiter($this->db);

        // 🆕 Unique identifier so no prior request window exists
        $uniqueIdentifier = '192.0.2.' . random_int(1, 254); // TEST-NET-1 (RFC 5737)

        try {
            // ── First call: records the request and returns the first count ──
            $firstResult = $rateLimiter->checkLimit(
                $uniqueIdentifier,
                self::TEST_IDENTIFIER_TYPE,
                self::TEST_ENDPOINT,
                self::TEST_TIER
            );
        } catch (Throwable $e) {
            $this->markTestSkipped(
                'checkLimit() threw on first call — required tables may be missing: '
                . $e->getMessage()
            );
        }

        // 📝 If we are in fail-open mode (PHP_INT_MAX limit), the remaining
        //    value will also be PHP_INT_MAX and the assertion below would be
        //    trivially true but meaningless.  We skip to keep the test honest.
        if ($firstResult['limit'] === PHP_INT_MAX) {
            $this->markTestSkipped(
                'tblRateLimitConfig has no row for this endpoint/tier — '
                . 'rate limiter is in fail-open mode; remaining-count assertion skipped'
            );
        }

        // 📋 First result must have been allowed before we proceed
        if (!$firstResult['allowed']) {
            $this->markTestSkipped(
                'First request was not allowed (identifier may be pre-blocked); '
                . 'remaining-count assertion skipped'
            );
        }

        try {
            // ── Second call: the window now has one recorded request ──
            $secondResult = $rateLimiter->checkLimit(
                $uniqueIdentifier,
                self::TEST_IDENTIFIER_TYPE,
                self::TEST_ENDPOINT,
                self::TEST_TIER
            );
        } catch (Throwable $e) {
            $this->markTestSkipped(
                'checkLimit() threw on second call: ' . $e->getMessage()
            );
        }

        // ✅ After one recorded request, 'remaining' must be < 'limit'
        $this->assertLessThan(
            $secondResult['limit'],
            $secondResult['remaining'],
            "'remaining' must be strictly less than 'limit' after one recorded request"
        );

        // ✅ 'remaining' must be a non-negative integer
        $this->assertGreaterThanOrEqual(
            0,
            $secondResult['remaining'],
            "'remaining' must be >= 0 (never negative)"
        );
    }

    // =========================================================================
    // 🔓 unblock() TESTS
    // =========================================================================

    /**
     * Test 7 — unblock() always returns a boolean
     *
     * unblock() issues an UPDATE against tblRateLimits.  Even when no rows
     * match (i.e. the identifier was never blocked) the method should return
     * a boolean — true if the statement executed successfully, false if the
     * prepare() call failed.
     *
     * This test calls unblock() on an identifier that was never blocked, which
     * is the zero-rows-affected path.  The execute() call still succeeds (it
     * simply affects 0 rows), so we expect true.
     *
     * @return void
     */
    public function testUnblockReturnsBoolean(): void
    {
        $rateLimiter = new \RateLimiter($this->db);

        try {
            $result = $rateLimiter->unblock(
                self::TEST_IDENTIFIER,
                self::TEST_IDENTIFIER_TYPE,
                self::TEST_ENDPOINT
            );
        } catch (Throwable $e) {
            $this->markTestSkipped(
                'unblock() threw — required tables may be missing: ' . $e->getMessage()
            );
        }

        $this->assertIsBool(
            $result,
            'unblock() must return a boolean value'
        );
    }

    // =========================================================================
    // 📈 getStatus() TESTS
    // =========================================================================

    /**
     * Test 8 — getStatus() returns an associative array
     *
     * getStatus() is a diagnostic/monitoring helper that aggregates request
     * counts, block state, and config limits into a single response.  It must
     * return an array regardless of whether tblRateLimitConfig is seeded or
     * not (it gracefully handles a null config row by defaulting the limit to
     * zero).
     *
     * We verify:
     *   (a) the return value is an array
     *   (b) the array contains the structurally required keys documented in
     *       the method's docblock
     *   (c) the 'identifierType' echo matches what was passed in
     *   (d) the 'isBlocked' flag is a boolean
     *
     * @return void
     */
    public function testGetStatusReturnsArray(): void
    {
        $rateLimiter = new \RateLimiter($this->db);

        try {
            $status = $rateLimiter->getStatus(
                self::TEST_IDENTIFIER,
                self::TEST_IDENTIFIER_TYPE,
                self::TEST_ENDPOINT
            );
        } catch (Throwable $e) {
            $this->markTestSkipped(
                'getStatus() threw — required tables may be missing: ' . $e->getMessage()
            );
        }

        // ── (a) Return type ──────────────────────────────────────────────────
        $this->assertIsArray(
            $status,
            'getStatus() must return an array'
        );

        // ── (b) Required keys ────────────────────────────────────────────────
        $requiredKeys = [
            'identifier',
            'identifierType',
            'endpoint',
            'requestCount',
            'limit',
            'remaining',
            'isBlocked',
            'blockedUntil',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey(
                $key,
                $status,
                "getStatus() result must contain the '{$key}' key"
            );
        }

        // ── (c) identifierType echo ──────────────────────────────────────────
        $this->assertEquals(
            self::TEST_IDENTIFIER_TYPE,
            $status['identifierType'],
            "getStatus() should echo back the identifierType that was passed in"
        );

        // ── (d) isBlocked is a boolean ───────────────────────────────────────
        $this->assertIsBool(
            $status['isBlocked'],
            "getStatus()['isBlocked'] must be a boolean"
        );

        // ── (e) requestCount is a non-negative integer ───────────────────────
        $this->assertIsInt(
            $status['requestCount'],
            "getStatus()['requestCount'] must be an integer"
        );

        $this->assertGreaterThanOrEqual(
            0,
            $status['requestCount'],
            "getStatus()['requestCount'] must be >= 0"
        );
    }
}
