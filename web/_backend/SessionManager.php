<?php
/**
 * ============================================================================
 * 🔑 SIGNula - Session Manager (thin Auth facade for public_html pages)
 * ============================================================================
 *
 * Purpose:
 *   🐛 B-051 fix. 74 pages under web/public_html require this exact file
 *   (dirname(__DIR__, N) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR
 *   . 'SessionManager.php') but it was NEVER committed to the repo — every one
 *   of those pages fataled on `require_once` before this fix.
 *
 *   This class does not implement any auth logic of its own. It is a thin,
 *   STATIC-backed facade over the real authentication engine, Auth
 *   (web/private_html/auth/Auth.php), which already owns session/cookie
 *   validation against tblUserSessions.
 *
 * Why the three public methods are STATIC:
 *   PHP allows calling a static method through an instance
 *   ($sessionManager->isLoggedIn()) — but NOT the reverse (calling an instance
 *   method with no instance, e.g. SessionManager::isLoggedIn(), is a fatal
 *   error). The 74 dependent pages use BOTH calling conventions:
 *     - 73× instance-style:  $sessionManager->isLoggedIn() / ->getUserInfo() / ->getUserID()
 *     - 3×  static-style:    SessionManager::isLoggedIn()
 *   Making the methods static is therefore the ONLY method shape compatible
 *   with every existing call site.
 *   @see https://www.php.net/manual/en/language.oop5.static.php
 *
 * @package    SIGNula
 * @subpackage Backend
 * @version    1.0.0
 * @link       https://SIGNula.id
 * ============================================================================
 */

// 🚫 Prevent direct access — guard idiom copied verbatim from
// web/private_html/auth/Auth.php / web/_config/database.php.
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 🔗 Defensive require: the 74 dependent pages under web/public_html require
// _config/config.php + _backend/SessionManager.php directly — they do NOT
// require web/private_html/auth/Auth.php themselves, and config.php's
// spl_autoload_register() only searches web/_includes/{auth,security,utils,
// api}/, which does NOT cover web/private_html/auth/. Without this explicit
// require, Auth::isAuthenticated() etc. below would fatal with
// "Class Auth not found". Guarded with is_file() per house style (never
// require a file blindly) and class_exists() so a caller that *has* already
// loaded Auth (e.g. a future autoloader path, or a test harness) is left
// untouched.
if (!class_exists('Auth')) {
    // web/_backend/SessionManager.php -> dirname(__DIR__) = web/
    // web/private_html/auth/Auth.php  <- + private_html/auth/Auth.php
    $sessionManagerAuthPath = dirname(__DIR__)
        . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'auth'
        . DIRECTORY_SEPARATOR . 'Auth.php';

    if (is_file($sessionManagerAuthPath)) {
        require_once $sessionManagerAuthPath;
    }
}

/**
 * 🔑 SessionManager
 *
 * Static-backed facade the 74 legacy public_html pages instantiate to check
 * the current visitor's login state. All real work is delegated to Auth.
 */
class SessionManager
{
    /**
     * 🏗️ Constructor
     *
     * Accepts (and intentionally ignores) an optional $db argument for
     * backward compatibility: every one of the ~72 existing call sites does
     * `new SessionManager($db)`, passing the result of `Database::getInstance()`.
     * This facade needs no DB handle of its own — Auth manages its own
     * connection internally via the Database static class — but the
     * parameter is accepted so those call sites keep working unchanged (PHP
     * silently ignores a declared-but-unused constructor argument type; this
     * documents the contract instead of leaving it implicit).
     *
     * Session start-up: web/_config/config.php::initializeSession() already
     * calls session_start() (guarded by a session_status() check) before any
     * public_html page reaches this constructor. The guard below is a
     * defensive safety net only — it mirrors that exact idiom, so it is a
     * genuine no-op (never double-starts) if the session is already active.
     *
     * @param mixed $db Optional legacy DB handle (unused; accepted for
     *                  backward compatibility with existing call sites)
     */
    public function __construct(mixed $db = null)
    {
        // 🎫 Defensive session guard — same idiom as
        // web/_config/config.php::initializeSession(). Safe no-op if a
        // session is already active.
        // @see https://www.php.net/manual/en/function.session-status.php
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * ✅ Is the current visitor authenticated?
     *
     * STATIC so both SessionManager::isLoggedIn() (3 call sites) and
     * $sessionManager->isLoggedIn() (73 call sites) work.
     *
     * @return bool True if a valid session/cookie resolves to a user
     * @see Auth::isAuthenticated()
     */
    public static function isLoggedIn(): bool
    {
        return Auth::isAuthenticated();
    }

    /**
     * 👤 Get the current authenticated user's numeric ID.
     *
     * @return int|null User ID, or null if not authenticated
     * @see Auth::getCurrentUserID()
     */
    public static function getUserID(): ?int
    {
        return Auth::getCurrentUserID();
    }

    /**
     * 👥 Get the current authenticated user's profile row.
     *
     * @return array|null Associative array of tblUsers columns (userID,
     *                     userUUID, username, email, firstName, lastName,
     *                     displayName, profilePicture, phoneNumber, locale,
     *                     timezone, accountStatus, accountTier, emailVerified,
     *                     lastLoginAt), or null if not authenticated.
     * @see Auth::getCurrentUser()
     */
    public static function getUserInfo(): ?array
    {
        return Auth::getCurrentUser();
    }
}
