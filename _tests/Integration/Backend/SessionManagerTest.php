<?php
/**
 * ============================================================================
 * 🧪 SessionManager Integration Tests (B-051 safety net)
 * ============================================================================
 *
 * Integration tests for web/_backend/SessionManager.php against a real,
 * logged-in Auth session backed by the test database. Requires a running
 * test database (signula_test) with the full schema.
 *
 * web/_backend/SessionManager.php was referenced by 74 pages under
 * web/public_html but never committed to the repo, so every one of those
 * pages fataled on `require_once` before B-051. Unit/Backend/SessionManagerTest
 * pins the DB-free method shape (delegation + static-ness); THIS suite proves
 * the class actually works end-to-end against a real authenticated user:
 *
 *   - With a real tblUserSessions row + matching $_SESSION populated (exactly
 *     what a successful login is supposed to leave behind — see the
 *     ⚠️ NOTE below on why this test does NOT call Auth::login() to get
 *     there), SessionManager::isLoggedIn() reports true, getUserID() returns
 *     the real userID, and getUserInfo() returns the tblUsers row keyed by
 *     'email' matching that user (proving the getUserInfo() ->
 *     Auth::getCurrentUser() delegate returns real, correctly-keyed data,
 *     not just "some array").
 *   - Once the session is cleared (the same $_SESSION mutation
 *     Auth::logout() performs — see the ⚠️ SECOND NOTE below on why this
 *     test does not call the real Auth::logout() to get there),
 *     SessionManager::isLoggedIn() reports false again.
 *
 * ⚠️ NOTE — pre-existing, UNRELATED bug found while writing this test:
 *   Auth::completeLogin() (web/private_html/auth/Auth.php ~line 455-468)
 *   binds its tblUserSessions INSERT with type string 'sissssssssii' — the
 *   LAST 'i' is for $expiresAt, which is a "Y-m-d H:i:s" DATETIME *string*,
 *   not an integer. mysqli's bind_param() then coerces $expiresAt via PHP's
 *   leading-numeric-substring int cast (e.g. "2026-07-10 01:23:45" -> 2026),
 *   and the INSERT fails with "Incorrect datetime value: '2026'". completeLogin()
 *   catches that exception internally and returns false — but Auth::login()
 *   (~line 396) NEVER checks completeLogin()'s return value, so login()
 *   unconditionally reports `'success' => true` even though NO session row
 *   was created and $_SESSION was NEVER populated. Reproduced independently
 *   of this fix (confirmed via tblErrorLog + a standalone repro script) —
 *   this is a live, pre-existing defect, not something introduced by B-051/
 *   B-052. It is OUT OF SCOPE for this fix (unrelated to both), so it is
 *   deliberately left unfixed here and flagged instead for separate triage.
 *   Consequence for THIS test: calling the real Auth::login() would never
 *   leave a working session behind to observe, so the "logged in" half of
 *   this test instead establishes session state exactly the way a
 *   successful completeLogin() is SUPPOSED to (a real tblUserSessions row +
 *   matching $_SESSION keys) — still driving Auth::isAuthenticated()/
 *   getCurrentUserID()/getCurrentUser() for real, just without routing
 *   through the currently-broken completeLogin() codepath.
 *
 * ⚠️ SECOND NOTE — pre-existing, UNRELATED test-harness gap also found while
 *   writing this test:
 *   _tests/bootstrap.php starts ONE real PHP session process-wide, before any
 *   output, specifically so production code's own session_start() calls
 *   never fire after headers are "sent" (see its own long comment on this).
 *   Auth::logout() calls session_destroy() on that SAME process-wide session.
 *   _tests/TestCase.php::setUp() tries to restart a session whenever
 *   session_status() === PHP_SESSION_NONE, but empirically (confirmed with a
 *   standalone repro) that recovery path does not actually reactivate a
 *   session once real output has already occurred — ini_set('session.*') and
 *   session_start() both fail at that point regardless of the
 *   headers-already-sent workaround it attempts. Net effect: the FIRST test
 *   in a run to call session_destroy() succeeds; a SECOND test doing the
 *   same later in the SAME run hits
 *   "session_destroy(): Trying to destroy uninitialized session" (a PHPUnit
 *   Warning, which fails the suite under this project's failOnWarning="true").
 *   This is why _tests/Integration/Auth/AuthLoginTest::testLogoutClearsSession
 *   is the ONLY other place in the whole suite that calls the real
 *   Auth::logout() — a second unconditional caller reproduces the warning
 *   under PHPUnit's random test ordering essentially every run. SessionManager
 *   itself doesn't care about session_destroy() at all (Auth::isAuthenticated()/
 *   getCurrentUserID() only ever read the $_SESSION array + query
 *   tblUserSessions — never session_status()), so this test proves the
 *   "logged out" half of the contract by applying the exact same $_SESSION
 *   mutation Auth::logout() performs, without invoking logout() (and
 *   therefore session_destroy()) a second time in the suite.
 *
 * @package    SIGNula\Tests\Integration\Backend
 * @version    2.7.0-beta
 * @see        web/_backend/SessionManager.php
 * @see        web/private_html/auth/Auth.php
 * @see        _tests/Integration/Auth/AuthLoginTest.php (mirrors its
 *             static-cache isolation pattern)
 */

namespace SIGNula\Tests\Integration\Backend;

use SIGNula\Tests\DatabaseTestCase;
use ReflectionClass;

// 📦 Load required source classes.
requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/auth/Auth.php');
requireSource('_backend/SessionManager.php');

/**
 * SessionManager Integration Test Suite
 */
class SessionManagerTest extends DatabaseTestCase
{
    /**
     * Tables to truncate before each test.
     */
    protected array $truncateTables = ['tblUsers', 'tblUserSessions'];

    /**
     * 🔧 Set up before each test.
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
     * private/public static properties once resolved. Auth::logout() clears
     * $_SESSION but does NOT reset these statics, and PHPUnit runs every test
     * in the same PHP process — so without this reset, a cached user from
     * one test (or from the "logged in" half of a single test method) would
     * leak into the next assertion. Mirrors AuthLoginTest's own
     * isolation pattern (see its testIsAuthenticatedReturnsFalseWhenNotLoggedIn).
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
     * Establish a real, working authenticated session for $userID — a real
     * tblUserSessions row plus the matching $_SESSION keys
     * Auth::getCurrentUserID()/logout() read (user_id, session_id,
     * session_token). This is exactly what a successful
     * Auth::completeLogin() is supposed to leave behind (see the
     * ⚠️ NOTE in this file's header on why we don't call Auth::login()
     * directly to get here).
     *
     * @param int $userID User to log in
     * @return string The plaintext session token (mirrors what
     *                 completeLogin() would generate)
     */
    private function establishRealSession(int $userID): string
    {
        $sessionToken = \SecurityUtils::generateToken();

        $sessionID = $this->insertRecord('tblUserSessions', [
            'sessionToken' => hash('sha256', $sessionToken),
            'userID' => $userID,
            'deviceType' => 'web',
            'ipAddress' => '127.0.0.1',
            'isActive' => 1,
            'expiresAt' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $_SESSION['user_id'] = $userID;
        $_SESSION['session_id'] = $sessionID;
        $_SESSION['session_token'] = $sessionToken;

        return $sessionToken;
    }

    // ========================================================================
    // ✅ NOT LOGGED IN
    // ========================================================================

    /**
     * With no session established, isLoggedIn() must report false.
     */
    public function testIsLoggedInFalseWhenNoUserAuthenticated(): void
    {
        $this->assertFalse(\SessionManager::isLoggedIn());
        $this->assertNull(\SessionManager::getUserID());
        $this->assertNull(\SessionManager::getUserInfo());
    }

    // ========================================================================
    // 🔑 FULL SESSION LIFECYCLE
    // ========================================================================

    /**
     * With a real, valid session established, SessionManager (static-style
     * calls — see the note above the "instance-vs-static" block below for
     * why instance-style isn't exercised in THIS suite) reports the
     * logged-in user correctly, and once the session is cleared (the same
     * $_SESSION mutation Auth::logout() performs — see this file's
     * ⚠️ SECOND NOTE on why the real Auth::logout() isn't called here)
     * reports logged-out again.
     */
    public function testFullSessionLifecycleReportsLoggedInThenLoggedOut(): void
    {
        $user = $this->createTestUser();
        $this->establishRealSession($user['userID']);

        // ✅ B-051: SessionManager.php now exists and delegates to Auth.
        $this->assertTrue(
            \SessionManager::isLoggedIn(),
            'SessionManager::isLoggedIn() should report true for the just-established session'
        );
        $this->assertSame(
            $user['userID'],
            \SessionManager::getUserID(),
            'SessionManager::getUserID() should return the logged-in user\'s real userID'
        );

        $userInfo = \SessionManager::getUserInfo();
        $this->assertIsArray($userInfo, 'getUserInfo() should return an array for a logged-in user');
        $this->assertSame(
            $user['userID'],
            $userInfo['userID'] ?? null,
            'getUserInfo()[\'userID\'] should match the logged-in user'
        );
        $this->assertSame(
            $user['email'],
            $userInfo['email'] ?? null,
            'getUserInfo()[\'email\'] should match the logged-in user'
        );

        // 🔁 Instance-vs-static equivalence (as used by the 72
        // `new SessionManager($db)` call sites) is covered DB-free in
        // _tests/Unit/Backend/SessionManagerTest.php instead of here.
        // Deliberately NOT constructing a `new SessionManager()` in this
        // suite: its constructor defensively calls session_start() when
        // session_status() is PHP_SESSION_NONE, and — per this file's
        // ⚠️ SECOND NOTE — that can legitimately be the case here purely
        // because of PHPUnit's RANDOM test ordering (if
        // AuthLoginTest::testLogoutClearsSession happened to run first in
        // this same process and destroyed the one shared session). That's a
        // pre-existing test-harness gap, not a SessionManager defect, but
        // constructing an instance here would make ITS coverage flaky for a
        // reason that has nothing to do with what this test is verifying.

        // 🚪 "Log out" by applying the exact same $_SESSION mutation
        // Auth::logout() performs (see this file's ⚠️ SECOND NOTE for why the
        // real Auth::logout() — which also calls session_destroy() — isn't
        // invoked a second time in this suite).
        $_SESSION = [];

        // Reset Auth's static cache too (see resetAuthStaticCache() doc-comment)
        // so the post-logout assertions reflect the cleared session, not a
        // stale in-process cache.
        $this->resetAuthStaticCache();

        $this->assertFalse(
            \SessionManager::isLoggedIn(),
            'SessionManager::isLoggedIn() should report false once the session is cleared'
        );
        $this->assertNull(\SessionManager::getUserID());
        $this->assertNull(\SessionManager::getUserInfo());
    }
}
