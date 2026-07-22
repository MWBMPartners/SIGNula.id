<?php
/**
 * ============================================================================
 * 🧪 Database::getInstance() / DatabaseConnection Integration Tests (B-054)
 * ============================================================================
 *
 * Integration tests for the B-054 fix against a real, live mysqli connection
 * backed by the test database (signula_test). Requires a running test
 * database — see _scripts/setup_test_db.sh.
 *
 * Background: web/_backend/Database.php was referenced by 74 pages under
 * web/public_html (require_once immediately after config.php, immediately
 * before _backend/SessionManager.php) but was NEVER committed to the repo —
 * every one of those pages fataled on that require. Separately, all 74 pages
 * then call `Database::getInstance()` — but the canonical `Database` class
 * (web/_config/database.php) is 100% STATIC and had no `getInstance()` at
 * all, so even once the missing file was created, those pages would have
 * gone on to fatal with "Call to undefined method Database::getInstance()".
 *
 * The fix is TWO additive pieces, both proven live here:
 *   1. web/_config/database.php gained `Database::getInstance(): DatabaseConnection`
 *      plus a new `DatabaseConnection` adapter class that proxies calls/property
 *      reads to the shared mysqli connection self::getConnection() already
 *      manages (no second connection pool).
 *   2. web/_backend/Database.php is a NEW shim file (mirrors the B-051
 *      SessionManager.php shim) whose only job is to guarantee `class Database`
 *      is loaded — guarded by `class_exists('Database')` so it can NEVER
 *      redeclare the class config.php already loaded.
 *
 * This suite proves:
 *   - Loading both files back-to-back (the exact real-world order: the
 *     canonical impl first via config.php, then the shim afterward, just
 *     like every one of the 74 pages does) does NOT redeclare/fatal.
 *   - Database::getInstance() returns a DatabaseConnection.
 *   - DatabaseConnection::getConnection() returns the SAME underlying mysqli
 *     link the static Database:: API itself uses (Database::getConnection())
 *     — not a second, disconnected connection.
 *   - $db->prepare()->execute()->get_result() round-trips a real row (the
 *     __call() magic proxy works for the #1 most-used call shape — 262 call
 *     sites across web/public_html use exactly this pattern).
 *   - $db->insert_id (the __get() magic proxy) reflects the real
 *     auto-generated id after a real INSERT on the SAME connection.
 *
 * @package    SIGNula\Tests\Integration\Backend
 * @version    2.7.0-beta
 * @see        web/_config/database.php   (Database::getInstance() + DatabaseConnection)
 * @see        web/_backend/Database.php  (B-054 shim)
 * @see        _tests/Unit/Backend/DatabaseTest.php (DB-free method-shape pins)
 */

namespace SIGNula\Tests\Integration\Backend;

use SIGNula\Tests\DatabaseTestCase;

// 📦 Load source files in the EXACT real-world order every one of the 74
// dependent pages loads them: web/_config/config.php (which this harness
// cannot require in-process — see LoadSettingsResilienceTest.php's header
// comment for why) require_once's _config/database.php FIRST, and only
// THEN does the page itself require_once _backend/Database.php. Mirroring
// that order here is what proves the shim's `class_exists('Database')`
// guard is a genuine no-op in the case that matters (Database already
// loaded), not just in isolation.
requireSource('_config/database.php');
requireSource('_backend/Database.php');

/**
 * Database::getInstance() / DatabaseConnection Integration Test Suite
 */
class DatabaseTest extends DatabaseTestCase
{
    /**
     * ✅ Loading web/_config/database.php then web/_backend/Database.php
     * back-to-back (the real bootstrap order) must not redeclare `Database`
     * and must leave both `Database` and `DatabaseConnection` defined.
     *
     * If either file declared a second `class Database`, PHP would have
     * fataled with a redeclaration error before this test method (or any
     * other in this file) ever ran — so simply reaching an assertion here
     * is itself part of the proof. The explicit class_exists() checks pin
     * the expected end state.
     */
    public function testBothFilesLoadTogetherWithoutRedeclaring(): void
    {
        $this->assertTrue(class_exists('Database'), 'Database class must be defined.');
        $this->assertTrue(class_exists('DatabaseConnection'), 'DatabaseConnection class must be defined.');
    }

    /**
     * Database::getInstance() must return a DatabaseConnection instance —
     * the object shape all 74 dependent pages expect back from
     * `$db = Database::getInstance();`.
     */
    public function testGetInstanceReturnsDatabaseConnection(): void
    {
        $db = \Database::getInstance();

        $this->assertInstanceOf(\DatabaseConnection::class, $db);
    }

    /**
     * DatabaseConnection::getConnection() must return the SAME mysqli link
     * the static Database:: API itself manages (Database::getConnection())
     * — proving getInstance() wraps the existing shared connection rather
     * than opening a second, independent one. This is the exact call shape
     * used by the 3 verified `$db->getConnection()` call sites.
     */
    public function testGetConnectionReturnsSameUnderlyingMysqliAsStaticApi(): void
    {
        $db = \Database::getInstance();
        $wrappedConnection = $db->getConnection();

        $this->assertInstanceOf(\mysqli::class, $wrappedConnection);
        $this->assertSame(
            \Database::getConnection(),
            $wrappedConnection,
            'DatabaseConnection must proxy the SAME shared mysqli link Database::getConnection() returns, not a second connection.'
        );

        // A second getInstance() call must also proxy to that identical link
        // — every adapter instance is cheap and disposable, but they all
        // point at the one real connection.
        $db2 = \Database::getInstance();
        $this->assertSame($wrappedConnection, $db2->getConnection());
    }

    /**
     * $db->prepare()->execute()->get_result() must round-trip a real row —
     * proving the __call() magic proxy forwards prepare() (and the mysqli_stmt
     * it returns keeps working normally) exactly like a raw mysqli link would.
     * This is the #1 most-used call shape across web/public_html (262 sites).
     */
    public function testPrepareExecuteGetResultRoundTripsARealRow(): void
    {
        $db = \Database::getInstance();

        $stmt = $db->prepare('SELECT 1 AS n, ? AS echoed');
        $expected = 'b054-round-trip';
        $stmt->bind_param('s', $expected);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $this->assertSame(1, (int) $row['n']);
        $this->assertSame($expected, $row['echoed']);
    }

    /**
     * $db->insert_id (the __get() magic proxy) must reflect the real
     * auto-generated id produced by an INSERT run on that SAME connection.
     *
     * Uses a session-scoped TEMPORARY TABLE rather than a real schema table:
     * Database::getInstance() proxies the Database class's OWN singleton
     * mysqli connection, which is a DIFFERENT physical connection than
     * $this->db (mock_database()) — the connection DatabaseTestCase wraps in
     * a rollback-per-test transaction. An INSERT on the Database singleton's
     * connection would therefore NOT be rolled back by this test's tearDown.
     * A TEMPORARY TABLE is scoped to (and auto-dropped with) the connection
     * that created it, so this can never leave permanent rows behind in
     * signula_test regardless of test order.
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/create-temporary-table.html
     */
    public function testInsertIdViaMagicGetPropertyReflectsRealInsert(): void
    {
        $db = \Database::getInstance();

        // 🧹 Defensive drop first — the Database singleton connection is
        // reused across every test method in this (and other) test classes
        // within the same PHPUnit process, so a prior run of this same test
        // could have left the temp table behind.
        $db->query('DROP TEMPORARY TABLE IF EXISTS tmp_b054_insert_id_test');
        $db->query(
            'CREATE TEMPORARY TABLE tmp_b054_insert_id_test (' .
            'id INT AUTO_INCREMENT PRIMARY KEY, val VARCHAR(32))'
        );

        $insertStmt = $db->prepare('INSERT INTO tmp_b054_insert_id_test (val) VALUES (?)');
        $value = 'b054-' . bin2hex(random_bytes(4));
        $insertStmt->bind_param('s', $value);
        $insertStmt->execute();

        $insertId = $db->insert_id;
        $this->assertGreaterThan(0, $insertId, '__get() must proxy mysqli::$insert_id from the connection that ran the INSERT.');

        // 🔍 Verify the id is genuinely usable to re-fetch the exact row just
        // written — not merely a truthy-looking value.
        $verifyStmt = $db->prepare('SELECT val FROM tmp_b054_insert_id_test WHERE id = ?');
        $verifyStmt->bind_param('i', $insertId);
        $verifyStmt->execute();
        $row = $verifyStmt->get_result()->fetch_assoc();

        $this->assertSame($value, $row['val']);

        // 🧹 Clean up the temp table for this connection.
        $db->query('DROP TEMPORARY TABLE IF EXISTS tmp_b054_insert_id_test');
    }
}
