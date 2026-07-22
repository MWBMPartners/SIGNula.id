<?php
/**
 * ============================================================================
 * 🧪 Auth::login() / completeLogin() Session-Creation Integration Tests (B-055)
 * ============================================================================
 *
 * B-055 (HIGH, core-auth): Auth::completeLogin() (web/private_html/auth/Auth.php)
 * bound its `tblUserSessions` INSERT with the type string 'sissssssssii' — the
 * LAST character was 'i' (integer) for $expiresAt, which is actually a
 * "Y-m-d H:i:s" DATETIME *string*. mysqli's bind_param() then coerced
 * $expiresAt to a PHP int via its leading-numeric-substring cast (e.g.
 * "2026-07-10 01:23:45" -> 2026), and the INSERT was rejected by MySQL as an
 * out-of-range/invalid DATETIME. completeLogin() caught that failure and
 * returned false — but Auth::login() NEVER checked completeLogin()'s return
 * value, so it unconditionally reported `'success' => true` even though NO
 * `tblUserSessions` row was ever created and $_SESSION was never populated.
 * Net effect: a user "logs in" successfully, but has no working session.
 *
 * The fix changed the last type char to 's' and made login() (and every
 * other completeLogin()/loginOAuth() caller) surface a real failure instead
 * of a phantom success when completeLogin() returns false.
 *
 * ⚠️ IMPORTANT — why a DB-stubbed unit test would never have caught this:
 *   The bug lives entirely in how a *real* mysqli prepared statement
 *   interprets a type-string mismatch against a *real* DATETIME column under
 *   a *real* MySQL server's SQL mode. A mocked/stubbed Database layer (as
 *   Unit tests use) simply never exercises bind_param() or MySQL's own type
 *   coercion rules, so the bug is invisible there by construction. Only a
 *   genuine integration test against a live database can prove it.
 *
 * ⚠️ NOTE on local strict-mode / why this test still catches the bug either
 *   way: web/_config/database.php::connect() unconditionally runs
 *   `SET sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE'` on the
 *   Database singleton's OWN connection every time it connects — regardless
 *   of the MySQL server's global default sql_mode. That means the singleton
 *   connection Auth::completeLogin() writes through is ALWAYS strict on this
 *   codebase, on every environment, so the pre-fix bug reliably threw a real
 *   "Incorrect datetime value" exception here (confirmed independently by
 *   _tests/Integration/Backend/SessionManagerTest.php's header comment, and
 *   reproduced manually via `mysql` CLI while diagnosing this fix — see the
 *   commit history around this file). Because we cannot fully rely on every
 *   future environment enforcing strict mode identically, this suite does
 *   NOT merely assert "a session row exists" — it asserts the stored
 *   `expiresAt` is a real, correctly-computed FUTURE datetime close to
 *   `NOW() + security.session.lifetime`. That catches BOTH failure modes:
 *     - strict mode: the INSERT throws, completeLogin() returns false, and
 *       (pre-fix) login() lies about success while leaving no session row
 *       at all;
 *     - non-strict mode: MySQL might silently truncate/coerce the bad value
 *       into a garbage stored datetime without throwing, in which case a
 *       weaker "row exists" check would pass even though the session is
 *       still broken (an already-expired/garbage expiresAt).
 *
 * @package    SIGNula\Tests\Integration\Auth
 * @version    2.7.0-beta
 * @see        web/private_html/auth/Auth.php (login(), completeLogin())
 * @see        _tests/Integration/Auth/AuthLoginTest.php (mirrors its
 *             createUserWithPassword()/testPassword fixture pattern)
 * @see        _tests/Integration/Backend/SessionManagerTest.php (independently
 *             documented this exact defect while testing SessionManager)
 */

namespace SIGNula\Tests\Integration\Auth;

use SIGNula\Tests\DatabaseTestCase;
use ReflectionClass;

// 📦 Load required source classes (mirrors AuthLoginTest.php's list, plus MFA.php
//    — Auth::login() unconditionally calls MFA::isEnabled(), and this file should
//    not rely on some OTHER Integration test file having already loaded it first
//    via PHPUnit's test-discovery `require`).
requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/security/TOTP.php');
requireSource('private_html/security/MFA.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/auth/Organization.php');
requireSource('private_html/auth/Auth.php');

/**
 * Auth::login() / completeLogin() Session-Creation Integration Test Suite
 */
class AuthCompleteLoginSessionTest extends DatabaseTestCase
{
    /**
     * Tables to truncate before each test.
     *
     * 🔧 tblRateLimits: same B-043 hermeticity reason documented in
     *    AuthLoginTest.php (Auth::login() rate-limits via the Database
     *    SINGLETON connection, which autocommits independently of this
     *    test's transaction).
     * 🔧 tblUserMFA: NOT truncated by AuthLoginTest.php, which leaves
     *    orphaned rows (from ITS testLoginWithMFAEnabledReturnsMFAFlag)
     *    referencing recycled auto-increment userIDs after a TRUNCATE
     *    resets tblUsers' AUTO_INCREMENT counter to 1. Truncating it here
     *    too keeps this suite's users guaranteed MFA-free so the "complete
     *    login with no MFA" path under test is never accidentally diverted
     *    into the MFA-challenge branch by unrelated leftover data.
     * 🔧 tblErrorLog: this suite asserts on rows ErrorLogger::logError()
     *    writes via the singleton (also autocommitted, also cross-test
     *    leftover-prone without an explicit truncate).
     */
    protected array $truncateTables = [
        'tblUsers', 'tblActivityLog', 'tblUserSessions', 'tblRateLimits',
        'tblUserMFA', 'tblErrorLog',
    ];

    /**
     * Default test password used across tests (mirrors AuthLoginTest.php).
     */
    private string $testPassword = 'TestPassword123!';

    /**
     * 🔧 Set up before each test — also resets Auth's memoized static state.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetAuthStaticCache();
    }

    /**
     * 🧹 Reset Auth's internal static cache.
     *
     * Auth::getCurrentUserID()/getCurrentUser() memoize their result on
     * private/public static properties once resolved, and PHPUnit runs every
     * test in the SAME PHP process — without this reset, a cached user ID
     * resolved by an EARLIER test (in this file or another) would leak into
     * this suite's assertions regardless of what $_SESSION actually holds.
     * Mirrors the identical pattern in
     * _tests/Integration/Backend/SessionManagerTest.php.
     */
    private function resetAuthStaticCache(): void
    {
        $reflection = new ReflectionClass(\Auth::class);
        $prop = $reflection->getProperty('currentUserID');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        \Auth::$currentUser = null;
    }

    /**
     * Create a test user with a known password via direct DB insert.
     *
     * Mirrors AuthLoginTest::createUserWithPassword() exactly so both suites'
     * fixtures behave identically under the harness's cross-connection
     * visibility rules (see this file's header note on strict mode / the
     * Database singleton).
     *
     * @param array $overrides Additional/override fields
     * @return array User data including userID
     */
    private function createUserWithPassword(array $overrides = []): array
    {
        $defaults = [
            'userUUID' => self::generateTestUuid(),
            'email' => random_email('complete_login_test'),
            'username' => 'completelogin_' . uniqid(),
            'passwordHash' => \SecurityUtils::hashPassword($this->testPassword),
            'displayName' => 'Complete Login Test User',
            'firstName' => 'Complete',
            'lastName' => 'Login',
            'accountStatus' => 'active',
            'emailVerified' => 1,
            'mfaEnabled' => 0,
            'failedLoginAttempts' => 0,
            'createdAt' => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $overrides);
        $userID = $this->insertRecord('tblUsers', $data);
        $data['userID'] = $userID;

        return $data;
    }

    // ========================================================================
    // ✅ VERIFY CONDITION #1 — A SUCCESSFUL LOGIN ACTUALLY CREATES A SESSION
    // ========================================================================

    /**
     * A non-MFA login must leave behind a REAL, resolvable session — not just
     * report `'success' => true`.
     *
     * Proves, end to end:
     *   1. Auth::login() reports success.
     *   2. A tblUserSessions row now exists for that user.
     *   3. That row's expiresAt is a genuine future DATETIME within a couple
     *      of seconds of NOW() + security.session.lifetime (not a garbage
     *      value produced by the old int-coercion bug — see this file's
     *      header note on why a bare "row exists" check is insufficient).
     *   4. Auth::getCurrentUserID() — which resolves a session purely by
     *      reading the $_SESSION keys completeLogin() populates and
     *      cross-checking them against a live, unexpired tblUserSessions row
     *      — actually resolves back to the same user. This is the "does the
     *      session really work" proof: it exercises the exact lookup path a
     *      real subsequent page request would use.
     */
    public function testLoginCreatesResolvableSessionWithCorrectExpiresAt(): void
    {
        $user = $this->createUserWithPassword();

        // 🕐 Capture the lifetime setting the SAME way Auth::completeLogin()
        //    does, and bracket the call with before/after timestamps so the
        //    expected expiresAt window is computed independently of any
        //    hardcoded assumption about the configured lifetime value.
        $lifetime = (int) getSetting('security.session.lifetime', 86400);
        $before = time();

        $result = \Auth::login($user['email'], $this->testPassword);

        $after = time();

        // --- (1) Auth::login() reports success ---------------------------------
        $this->assertTrue(
            $result['success'] ?? false,
            'Login should succeed with valid credentials for a non-MFA user: ' . ($result['message'] ?? '')
        );
        $this->assertSame($user['userID'], $result['userID'] ?? null);
        $this->assertFalse($result['requiresMFA'] ?? true, 'This user has no MFA enrolled, so requiresMFA must be false');

        // --- (2) A real tblUserSessions row now exists --------------------------
        $sessionRow = $this->getRecord('tblUserSessions', ['userID' => $user['userID']]);
        $this->assertIsArray(
            $sessionRow,
            'B-055: Auth::login() reported success but NO tblUserSessions row was created for the user — ' .
            'this is exactly the phantom-success bug (completeLogin() failed silently and login() ignored it).'
        );
        $this->assertSame(1, (int) $sessionRow['isActive'], 'The new session should be active');

        // --- (3) expiresAt is a real, correctly-computed FUTURE datetime --------
        $expiresAtTimestamp = strtotime($sessionRow['expiresAt']);
        $this->assertNotFalse(
            $expiresAtTimestamp,
            "expiresAt ('{$sessionRow['expiresAt']}') should be a parseable datetime string, " .
            "not a truncated/garbage value like '0000-00-00 00:00:00' produced by the old int-type-coercion bug"
        );
        $this->assertGreaterThan(
            time(),
            $expiresAtTimestamp,
            'expiresAt must be in the future relative to right now'
        );
        // 🎯 Tight window: expiresAt must land within [before+lifetime, after+lifetime]
        //    (inclusive, ± the handful of seconds the request itself took), which is
        //    only possible if $expiresAt was correctly bound as the DATETIME STRING
        //    "Y-m-d H:i:s" computed from time()+$lifetime — NOT a coerced/truncated
        //    integer that would produce a wildly different (or rejected) value.
        $this->assertGreaterThanOrEqual(
            $before + $lifetime,
            $expiresAtTimestamp,
            'expiresAt should be at least NOW()+lifetime as measured before the login() call'
        );
        $this->assertLessThanOrEqual(
            $after + $lifetime + 2, // 🕑 small slack for test execution time
            $expiresAtTimestamp,
            'expiresAt should be at most NOW()+lifetime (+slack) as measured after the login() call'
        );

        // --- (4) The session actually resolves back to the user ----------------
        // completeLogin() populates $_SESSION['user_id'] / ['session_token'] for
        // real (this is an in-process integration test, not a simulated session),
        // so Auth::getCurrentUserID() must independently re-derive the same user
        // by hashing the session token and matching it against the live,
        // unexpired tblUserSessions row above.
        $this->assertSame(
            $user['userID'],
            \Auth::getCurrentUserID(),
            'Auth::getCurrentUserID() should resolve the just-created session back to the logged-in user'
        );
    }

    // ========================================================================
    // ✅ VERIFY CONDITION #2 — A completeLogin() FAILURE MUST NOT BE REPORTED
    //    AS A SUCCESSFUL LOGIN (NO PHANTOM SUCCESS)
    // ========================================================================

    /**
     * Forces Auth::completeLogin()'s tblUserSessions INSERT to genuinely fail
     * — via a pure DATA condition, no production code touched, no test-only
     * hook added to Auth — and proves Auth::login() now surfaces that as a
     * real failure instead of the pre-B-055 phantom `'success' => true`.
     *
     * How the failure is forced: web/_config/config.php's `getSetting()` (its
     * test-bootstrap stub lives in _tests/bootstrap.php and reads
     * $GLOBALS['test_settings']) is the SAME lookup Auth::completeLogin() uses
     * for `security.session.lifetime`. Setting that value to an enormous
     * number of seconds makes `date('Y-m-d H:i:s', time() + $sessionLifetime)`
     * compute a DATETIME string whose YEAR exceeds MySQL's DATETIME range
     * (max year 9999) — e.g. "33715-04-06 02:58:33". Because
     * web/_config/database.php::connect() forces
     * `STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE` on the Database
     * singleton's own connection, MySQL rejects that INSERT with a real
     * "Incorrect datetime value" error (confirmed manually against this
     * project's test database while diagnosing B-055), which
     * completeLogin()'s own catch(Exception $e) converts into a clean `false`
     * return — precisely the scenario Auth::login() must no longer mask.
     */
    public function testLoginReturnsFailureAndLeavesNoSessionWhenCompleteLoginFails(): void
    {
        $user = $this->createUserWithPassword();

        $originalLifetime = getSetting('security.session.lifetime', 86400);

        // 💣 ~31,709 years — pushes the computed expiresAt's year well past
        //    MySQL's DATETIME maximum (9999), guaranteeing the INSERT fails.
        $GLOBALS['test_settings']['security.session.lifetime'] = 999999999999;

        try {
            $result = \Auth::login($user['email'], $this->testPassword);
        } finally {
            // 🔁 Always restore — even if an assertion below throws — so this
            // override can never bleed into a later test in the same PHPUnit
            // process. (Belt-and-braces: TestCase::setUp() already resets
            // $GLOBALS['test_settings'] from fixtures before the NEXT test
            // runs regardless, but restoring immediately keeps this test
            // self-contained.)
            if ($originalLifetime === null) {
                unset($GLOBALS['test_settings']['security.session.lifetime']);
            } else {
                $GLOBALS['test_settings']['security.session.lifetime'] = $originalLifetime;
            }
        }

        // --- login() must report failure, not a phantom success ----------------
        $this->assertFalse(
            $result['success'] ?? true,
            'B-055: login() must return success=false when completeLogin() could not create a session ' .
            '(it must never claim success while leaving no working session behind)'
        );
        $this->assertArrayHasKey('userID', $result, "The result array should still have a 'userID' key on this failure path");
        $this->assertNull($result['userID'], 'No userID should be reported on this failure path');
        $this->assertFalse($result['requiresMFA'] ?? true, 'requiresMFA should be false on this failure path');

        // --- No phantom session: the failed INSERT must have created NOTHING ---
        $sessionRow = $this->getRecord('tblUserSessions', ['userID' => $user['userID']]);
        $this->assertNull(
            $sessionRow,
            'No tblUserSessions row should exist when completeLogin() failed to create one'
        );

        // --- The failure path also drives the guard's own logging (added in the
        //     B-055 fix in Auth.php's login() method) ---------------------------
        $activityRow = $this->getRecord('tblActivityLog', [
            'userID' => $user['userID'],
            'activityType' => 'login_failed',
        ]);
        $this->assertIsArray(
            $activityRow,
            'The new login()-level guard should log a login_failed activity entry when completeLogin() fails'
        );

        $errorLogRow = $this->getRecord('tblErrorLog', ['errorType' => 'Warning']);
        $this->assertIsArray(
            $errorLogRow,
            'The new login()-level guard should also record the failure via ErrorLogger for support/debugging'
        );
    }
}
