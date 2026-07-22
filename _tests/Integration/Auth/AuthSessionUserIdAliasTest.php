<?php
/**
 * ============================================================================
 * 🧪 $_SESSION['userID'] Camel-Case Alias Integration Tests (B-065)
 * ============================================================================
 *
 * B-065 (HIGH, "hidden broken", folded into the B-060 cycle): every one of
 * the 13 settings/*.php pages (profile, security, mfa, passkeys, privacy, …)
 * reads the current user id straight off the session as
 * `$userID = $_SESSION['userID'];` (camelCase), then immediately passes it
 * into typed APIs — most visibly `AvatarService::resolve(int $userID, …)`.
 *
 * But every BROWSER login flow funnels through Auth::completeLogin()
 * (Auth::login() password path, Auth::loginOAuth() OAuth-callback +
 * browser-MFA-completion path) and the remember-me cookie-restore branch in
 * Auth::getCurrentUserID() — and BOTH of those only ever wrote the
 * snake_case `$_SESSION['user_id']`. The camelCase `$_SESSION['userID']` key
 * the settings pages read was therefore NEVER set for a normally-logged-in
 * user, so on those pages `$userID` came out null →
 * `AvatarService::resolve(null)` threw a TypeError → the page fataled BEFORE
 * it ever reached its layout. (Only the two WebAuthn/passwordless endpoints
 * happened to set the camelCase key directly, which is why the mismatch hid
 * for so long.)
 *
 * The fix is minimal + additive: at each of the 4 `$_SESSION['user_id'] = …`
 * session-establishment sites (Auth::completeLogin(), the remember-me
 * restore in Auth::getCurrentUserID(), and the two API controllers'
 * establishSession()), ALSO write `$_SESSION['userID'] = …` — never REMOVING
 * `user_id`, so every existing snake_case reader (Auth, SessionGuard,
 * BaseController, Auth/MFA API controllers) keeps working unchanged.
 *
 * This suite proves the alias end to end against a REAL database, driving the
 * REAL login path (Auth::login()) — NOT by hand-writing $_SESSION — and then
 * reproduces the EXACT settings-page prologue expression
 * (`$userID = $_SESSION['userID']; AvatarService::resolve($userID, …)`) that
 * used to fatal, asserting it now succeeds.
 *
 * Mirrors AuthCompleteLoginSessionTest.php (B-055) for its requireSource list,
 * createUserWithPassword() fixture, truncate set, and Auth-static-cache reset.
 *
 * @package    SIGNula\Tests\Integration\Auth
 * @version    2.9.0-beta
 * @see        web/private_html/auth/Auth.php (completeLogin() ~L523/536, remember-me restore ~L743/748)
 * @see        web/private_html/api/controllers/AuthController.php (establishSession())
 * @see        web/private_html/api/controllers/MFAController.php (establishSession())
 * @see        web/private_html/utils/AvatarService.php (resolve(int $userID) — the typed API that fataled on null)
 * @see        _tests/Integration/Auth/AuthCompleteLoginSessionTest.php (the fixture/harness pattern this mirrors)
 */

namespace SIGNula\Tests\Integration\Auth;

use SIGNula\Tests\DatabaseTestCase;
use ReflectionClass;

// 📦 Load required source classes (mirrors AuthCompleteLoginSessionTest.php's
//    list; adds AvatarService — the typed consumer the B-065 fatal occurred in).
requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/security/TOTP.php');
requireSource('private_html/security/MFA.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/utils/AvatarService.php');
requireSource('private_html/auth/Organization.php');
requireSource('private_html/auth/Auth.php');

/**
 * $_SESSION['userID'] camelCase-alias integration test suite.
 */
class AuthSessionUserIdAliasTest extends DatabaseTestCase
{
    /**
     * Tables to truncate before each test — same rationale as
     * AuthCompleteLoginSessionTest.php (tblRateLimits/tblUserMFA/tblErrorLog
     * are all written through the autocommitting Database singleton and are
     * cross-test leftover-prone).
     */
    protected array $truncateTables = [
        'tblUsers', 'tblActivityLog', 'tblUserSessions', 'tblRateLimits',
        'tblUserMFA', 'tblErrorLog',
    ];

    /**
     * Default test password (mirrors AuthCompleteLoginSessionTest.php).
     */
    private string $testPassword = 'TestPassword123!';

    /**
     * 🔧 Set up — reset Auth's memoized static state and clear any session/
     * cookie state a previous test in this shared PHP process left behind.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetAuthStaticCache();

        $_SESSION = [];
        unset($_COOKIE['signula_session']);
    }

    /**
     * 🧹 Reset Auth's internal static cache (verbatim from
     * AuthCompleteLoginSessionTest::resetAuthStaticCache()).
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
     * Create a test user with a known password (verbatim from
     * AuthCompleteLoginSessionTest::createUserWithPassword()).
     *
     * @param array $overrides
     * @return array User data including userID
     */
    private function createUserWithPassword(array $overrides = []): array
    {
        $defaults = [
            'userUUID' => self::generateTestUuid(),
            'email' => random_email('b065_alias_test'),
            'username' => 'b065alias_' . uniqid(),
            'passwordHash' => \SecurityUtils::hashPassword($this->testPassword),
            'displayName' => 'B065 Alias Test User',
            'firstName' => 'B065',
            'lastName' => 'Alias',
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
    // ✅ THE FIX — completeLogin() sets BOTH session keys
    // ========================================================================

    /**
     * After a real, successful Auth::login() (the password → completeLogin()
     * path every browser login funnels through), BOTH the snake_case
     * `user_id` AND the camelCase `userID` session keys must be set to the
     * same user id — the former for Auth/SessionGuard/BaseController, the
     * latter for the 13 settings/*.php pages.
     */
    public function testLoginSetsBothSnakeCaseAndCamelCaseSessionKeys(): void
    {
        $user = $this->createUserWithPassword();

        $result = \Auth::login($user['email'], $this->testPassword);

        $this->assertTrue($result['success'] ?? false, 'Login should succeed: ' . ($result['message'] ?? ''));

        // 🐍 snake_case (pre-existing) — must NOT have been removed.
        $this->assertArrayHasKey('user_id', $_SESSION, "\$_SESSION['user_id'] must still be set (never removed)");
        $this->assertSame($user['userID'], $_SESSION['user_id'], "\$_SESSION['user_id'] must equal the logged-in userID");

        // 🐫 camelCase (the B-065 alias) — the key the settings pages read.
        $this->assertArrayHasKey('userID', $_SESSION, "B-065: \$_SESSION['userID'] must now be set by completeLogin()");
        $this->assertSame($user['userID'], $_SESSION['userID'], "\$_SESSION['userID'] must equal the logged-in userID");
    }

    // ========================================================================
    // ✅ THE FIX — the remember-me cookie-restore path sets BOTH keys
    // ========================================================================

    /**
     * On a FRESH request that carries only the remember-me `signula_session`
     * cookie (no live $_SESSION yet), Auth::getCurrentUserID()'s cookie-
     * restore branch must repopulate BOTH session keys — so a settings page
     * whose user id is resolved purely from the remember-me cookie still finds
     * `$_SESSION['userID']`.
     */
    public function testRememberMeCookieRestoreSetsBothSessionKeys(): void
    {
        $user = $this->createUserWithPassword();

        // 🍪 Create a real, active session row and drive resolution purely via
        //    the remember-me cookie (raw token; its sha256 is what's stored).
        $rawToken = bin2hex(random_bytes(32));
        $this->insertRecord('tblUserSessions', [
            'sessionToken' => hash('sha256', $rawToken),
            'userID'       => $user['userID'],
            'deviceType'   => 'web',
            'ipAddress'    => '127.0.0.1',
            'isActive'     => 1,
            'expiresAt'    => date('Y-m-d H:i:s', time() + 86400),
        ]);

        // 🧼 Genuinely fresh request: no session keys, only the cookie.
        $_SESSION = [];
        $this->resetAuthStaticCache();
        $_COOKIE['signula_session'] = $rawToken;

        $resolved = \Auth::getCurrentUserID();

        $this->assertSame($user['userID'], $resolved, 'The remember-me cookie must resolve back to the user');

        $this->assertArrayHasKey('user_id', $_SESSION, "\$_SESSION['user_id'] must be restored (never removed)");
        $this->assertSame($user['userID'], (int) $_SESSION['user_id']);

        $this->assertArrayHasKey('userID', $_SESSION, "B-065: \$_SESSION['userID'] must be restored by the cookie-restore branch");
        $this->assertSame($user['userID'], $_SESSION['userID'], "\$_SESSION['userID'] must be the (int) restored userID");
        $this->assertIsInt($_SESSION['userID'], "the restored \$_SESSION['userID'] must be a genuine int (matches how settings pages use it)");
    }

    // ========================================================================
    // ✅ END-TO-END — the exact settings-page prologue no longer fatals
    // ========================================================================

    /**
     * Reproduces the EXACT expression the 13 settings/*.php pages run right
     * after requireLogin() —
     *
     *     $userID = $_SESSION['userID'];
     *     AvatarService::resolve($userID, null, 128);
     *
     * — using a session established by the REAL login path. Before B-065,
     * $_SESSION['userID'] was null here, so AvatarService::resolve(null)
     * threw `TypeError: Argument #1 ($userID) must be of type int, null given`
     * and the page fataled before its layout. With the fix, $userID is a valid
     * int and resolve() returns a non-empty avatar URL/data-URI string.
     */
    public function testSettingsPagePrologueResolvesAvatarWithoutTypeError(): void
    {
        $user = $this->createUserWithPassword();

        $result = \Auth::login($user['email'], $this->testPassword);
        $this->assertTrue($result['success'] ?? false, 'Login should succeed: ' . ($result['message'] ?? ''));

        // 🎯 The exact settings-page prologue (verbatim shape).
        $userID = $_SESSION['userID'];

        $this->assertIsInt($userID, "B-065: \$userID read from \$_SESSION['userID'] must be an int, not null");

        // 🖼️ The call that used to TypeError on a null $userID. A regression of
        //    the B-065 fix would make $userID null and this line throw.
        $avatarUrl = \AvatarService::resolve($userID, null, 128);

        $this->assertIsString($avatarUrl, 'AvatarService::resolve() must return a string for a valid userID');
        $this->assertNotSame('', $avatarUrl, 'AvatarService::resolve() must return a non-empty avatar URL/data-URI');
    }
}
