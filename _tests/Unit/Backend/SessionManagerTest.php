<?php
/**
 * ============================================================================
 * 🧪 SessionManager Unit Tests (B-051 safety net)
 * ============================================================================
 *
 * DB-FREE. web/_backend/SessionManager.php was referenced by 74 pages under
 * web/public_html but never committed to the repo — every one of those pages
 * fataled on `require_once` before B-051. This suite pins the class SHAPE
 * that fix depends on, without touching the database:
 *
 *   - The 3 public methods (isLoggedIn/getUserID/getUserInfo) delegate to the
 *     matching Auth:: method, in a state (no session/cookie set) that Auth
 *     can resolve WITHOUT a database call — Auth::getCurrentUserID() returns
 *     null immediately when $_SESSION['user_id'] / $_SESSION['session_token']
 *     are both empty, so this is safely DB-free.
 *   - Instance-style calls ($sessionManager->isLoggedIn()) return exactly
 *     what the static-style calls (SessionManager::isLoggedIn()) return —
 *     the two calling conventions used across the 74 dependent pages.
 *   - All 3 public methods remain STATIC. This is the load-bearing design
 *     invariant of the whole fix: PHP allows calling a static method through
 *     an instance, but NOT the reverse, and 3 call sites use the bare
 *     SessionManager::isLoggedIn() static form with no instance at all.
 *   - The constructor accepts (and ignores) a legacy $db argument, because
 *     every one of the ~72 existing call sites does `new SessionManager($db)`.
 *
 * The full authenticated-session lifecycle (a real tblUserSessions row,
 * getUserInfo() key-shape against a real tblUsers row, logout) requires a
 * database and is covered by
 * _tests/Integration/Backend/SessionManagerTest.php instead.
 *
 * @package    SIGNula\Tests\Unit\Backend
 * @version    2.7.0-beta
 * @see        web/_backend/SessionManager.php
 * @see        web/private_html/auth/Auth.php
 */

namespace SIGNula\Tests\Unit\Backend;

use SIGNula\Tests\TestCase;
use ReflectionClass;

// 📦 Load source classes. SessionManager defensively requires Auth.php itself
// if it isn't already loaded, but we load it explicitly first here so the
// test can also reflect on/reset Auth's own static cache.
requireSource('private_html/auth/Auth.php');
requireSource('_backend/SessionManager.php');

/**
 * SessionManager Unit Test Suite
 */
class SessionManagerTest extends TestCase
{
    /**
     * 🧹 Reset Auth's internal static cache before each test.
     *
     * Auth::getCurrentUserID() memoizes its result on the private static
     * $currentUserID property (and getCurrentUser() on the public static
     * $currentUser property) the first time it resolves a user. Since
     * PHPUnit runs every test in the same PHP process, a value cached by an
     * earlier test (in this file or another) would otherwise leak into this
     * one. Mirrors the isolation pattern already used by
     * _tests/Integration/Auth/AuthLoginTest.php.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new ReflectionClass(\Auth::class);
        $prop = $reflection->getProperty('currentUserID');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        \Auth::$currentUser = null;
    }

    // ========================================================================
    // 🔌 STATIC METHODS DELEGATE TO Auth
    // ========================================================================

    /**
     * isLoggedIn() delegates to Auth::isAuthenticated() — both report false
     * when $_SESSION/$_COOKIE carry no credentials.
     */
    public function testIsLoggedInDelegatesToAuthIsAuthenticated(): void
    {
        $this->assertFalse(\SessionManager::isLoggedIn());
        $this->assertSame(\Auth::isAuthenticated(), \SessionManager::isLoggedIn());
    }

    /**
     * getUserID() delegates to Auth::getCurrentUserID() — both null when
     * nothing is authenticated.
     */
    public function testGetUserIDDelegatesToAuthGetCurrentUserID(): void
    {
        $this->assertNull(\SessionManager::getUserID());
        $this->assertSame(\Auth::getCurrentUserID(), \SessionManager::getUserID());
    }

    /**
     * getUserInfo() delegates to Auth::getCurrentUser() — both null when
     * nothing is authenticated.
     */
    public function testGetUserInfoDelegatesToAuthGetCurrentUser(): void
    {
        $this->assertNull(\SessionManager::getUserInfo());
        $this->assertSame(\Auth::getCurrentUser(), \SessionManager::getUserInfo());
    }

    // ========================================================================
    // 🔁 INSTANCE-STYLE CALLS MATCH STATIC-STYLE CALLS
    // ========================================================================

    /**
     * $sessionManager->isLoggedIn() / ->getUserID() / ->getUserInfo() must
     * report exactly what the static-style calls report — 73 of the 74
     * dependent pages call through an instance.
     */
    public function testInstanceStyleCallsMatchStaticStyleCalls(): void
    {
        $sessionManager = new \SessionManager();

        $this->assertSame(\SessionManager::isLoggedIn(), $sessionManager->isLoggedIn());
        $this->assertSame(\SessionManager::getUserID(), $sessionManager->getUserID());
        $this->assertSame(\SessionManager::getUserInfo(), $sessionManager->getUserInfo());
    }

    /**
     * The constructor must accept the legacy $db argument every one of the
     * ~72 existing call sites passes (`new SessionManager($db)`, a
     * Database::getInstance() handle) without fataling — this class needs no
     * DB handle of its own, but the call sites are unmodified (ADDITIVE fix).
     */
    public function testConstructorAcceptsAndIgnoresLegacyDbArgument(): void
    {
        $fakeDb = new \stdClass();
        $sessionManager = new \SessionManager($fakeDb);

        $this->assertInstanceOf(\SessionManager::class, $sessionManager);
        $this->assertFalse($sessionManager->isLoggedIn());
    }

    /**
     * The constructor must also work with zero arguments (the design the
     * class was specified against).
     */
    public function testConstructorAcceptsNoArguments(): void
    {
        $sessionManager = new \SessionManager();

        $this->assertInstanceOf(\SessionManager::class, $sessionManager);
    }

    // ========================================================================
    // 🧱 STATIC-METHOD SHAPE INVARIANT
    // ========================================================================

    /**
     * All 3 public methods must remain STATIC.
     *
     * PHP permits $instance->staticMethod() but NOT the reverse — an
     * instance method cannot be called with SessionManager::method(). Three
     * dependent call sites use exactly that bare static form
     * (SessionManager::isLoggedIn()), so making any of these methods an
     * instance method would silently break them. This test guards against
     * that regression.
     */
    public function testAllPublicMethodsRemainStatic(): void
    {
        $reflection = new ReflectionClass(\SessionManager::class);

        foreach (['isLoggedIn', 'getUserID', 'getUserInfo'] as $methodName) {
            $this->assertTrue(
                $reflection->getMethod($methodName)->isStatic(),
                "SessionManager::{$methodName}() must remain static — instance-only would break " .
                "the 3 call sites that use the bare SessionManager::{$methodName}() form."
            );
        }
    }
}
