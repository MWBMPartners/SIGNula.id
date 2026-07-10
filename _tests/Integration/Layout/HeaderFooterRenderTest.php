<?php
/**
 * ============================================================================
 * 🧪 header.php + footer.php Render Tests (B-060)
 * ============================================================================
 *
 * B-060 (HIGH, "hidden broken"): web/private_html/layout/header.php and
 * footer.php did not exist at all — 17 pages under web/public_html/
 * (13 settings/*.php + 4 auth/passkey-*.php|passwordless-*.php)
 * `include SIGNULA_ROOT . '.../private_html/layout/header.php'`
 * unconditionally, fataling with "Failed opening required" on every load.
 *
 * This suite proves the two new files actually render — for BOTH the
 * authenticated case (settings/* pages: top-right avatar + display name +
 * Profile/Settings/Logout dropdown, per this project's house UI convention)
 * and the guest case (auth/passwordless-*.php reach this file before any
 * session exists: Login/Sign Up buttons instead) — with balanced,
 * well-formed HTML and NO PHP warnings/notices (this project's phpunit.xml
 * has failOnWarning="true", so an unguarded `$var` access in either file
 * would fail this suite on its own).
 *
 * Runs IN-PROCESS (unlike the config.php-level B-060 tests in
 * _tests/Integration/Config/SignulaRootAndAuthHelpersTest.php) — header.php/
 * footer.php declare no global functions/constants that collide with
 * bootstrap.php's stubs, so there is no "Cannot redeclare" risk here.
 *
 * @package    SIGNula\Tests\Integration\Layout
 * @version    2.9.0-beta
 * @see        web/private_html/layout/header.php (nesting contract documented in its own header doc-block)
 * @see        web/private_html/layout/footer.php
 * @see        _tests/Integration/Auth/GetCurrentUserAdminFlagsTest.php (the real-session + Auth-static-cache-reset pattern this mirrors)
 */

namespace SIGNula\Tests\Integration\Layout;

use SIGNula\Tests\DatabaseTestCase;
use ReflectionClass;

// 📦 Load required source classes. Auth (session resolution) + AvatarService
// (top-right avatar image) are both exercised for real by header.php.
requireSource('_config/database.php');
requireSource('private_html/auth/Auth.php');
requireSource('private_html/utils/AvatarService.php');

/**
 * header.php + footer.php render test suite.
 */
class HeaderFooterRenderTest extends DatabaseTestCase
{
    /**
     * @var array<int, string> Tables to truncate before each test — mirrors
     *      GetCurrentUserAdminFlagsTest.php's own list (this suite creates
     *      the same shape of fixture: a user + a resolvable session).
     */
    protected array $truncateTables = ['tblUserSessions', 'tblUsers'];

    /**
     * 🔧 Set up before each test — also resets Auth's memoized static state,
     * exactly like GetCurrentUserAdminFlagsTest::resetAuthStaticCache(), so
     * a user resolved by an EARLIER test never leaks into this suite.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetAuthStaticCache();

        // 🧹 A guest-render test must see a genuinely empty session — clear
        // any $_SESSION/$_COOKIE state a previous test (in this same PHPUnit
        // process) may have left behind.
        $_SESSION = [];
        unset($_COOKIE['signula_session']);
    }

    /**
     * 🧹 Reset Auth's internal static cache — verbatim copy of
     * GetCurrentUserAdminFlagsTest::resetAuthStaticCache().
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
     * Auth::getCurrentUserID() reads (user_id, session_id, session_token).
     * Verbatim copy of GetCurrentUserAdminFlagsTest::establishRealSession().
     *
     * @param int $userID User to log in
     * @return void
     */
    private function establishRealSession(int $userID): void
    {
        $sessionToken = bin2hex(random_bytes(32));

        $sessionID = $this->insertRecord('tblUserSessions', [
            'sessionToken' => hash('sha256', $sessionToken),
            'userID'       => $userID,
            'deviceType'   => 'web',
            'ipAddress'    => '127.0.0.1',
            'isActive'     => 1,
            'expiresAt'    => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $_SESSION['user_id']       = $userID;
        $_SESSION['session_id']    = $sessionID;
        $_SESSION['session_token'] = $sessionToken;
    }

    /**
     * Render header.php + a tiny content stand-in + footer.php with a given
     * $pageTitle, and return the captured output.
     *
     * @param string $pageTitle
     * @return string Captured HTML output
     */
    private function renderHeaderAndFooter(string $pageTitle): string
    {
        // 💡 $pageTitle (the method parameter) stays in local scope here — both
        // include()s below read it exactly like a page that set $pageTitle
        // before `include .../layout/header.php;` would.
        $headerPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'private_html'
            . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'header.php';
        $footerPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'private_html'
            . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'footer.php';

        ob_start();
        include $headerPath;
        echo '<div class="container mt-5"><p>B-060 test content</p></div>';
        include $footerPath;

        return ob_get_clean();
    }

    /**
     * Count occurrences of a needle in a haystack (simple substring count,
     * used for the open/close tag balance assertions below).
     */
    private static function countOccurrences(string $haystack, string $needle): int
    {
        return substr_count($haystack, $needle);
    }

    // ========================================================================
    // ✅ AUTHENTICATED RENDER — the "gold standard" top-right identity block
    // ========================================================================

    /**
     * For a real, authenticated user, header.php must render the top-right
     * avatar + display name + Profile/Settings/Logout dropdown (per this
     * project's house UI convention — "pages should indicate to the user
     * that they're logged in ... top-right corner"), and footer.php must
     * close out a well-formed, balanced document.
     */
    public function testHeaderAndFooterRenderForAuthenticatedUser(): void
    {
        $user = $this->createTestUser(['displayName' => 'B060 Layout Test User']);
        $this->establishRealSession($user['userID']);

        $html = $this->renderHeaderAndFooter('B-060 Authenticated Render Test');

        // 📄 Well-formed, balanced document.
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertSame(1, self::countOccurrences($html, '<html'), 'exactly one <html> open tag');
        $this->assertSame(1, self::countOccurrences($html, '</html>'), 'exactly one </html> close tag');
        $this->assertSame(1, self::countOccurrences($html, '<body'), 'exactly one <body> open tag (header.php)');
        $this->assertSame(1, self::countOccurrences($html, '</body>'), 'exactly one </body> close tag (footer.php)');
        $this->assertStringContainsString('B-060 Authenticated Render Test - SIGNula', $html, '$pageTitle must appear in <title>');

        // 👤 Top-right signed-in identity block.
        $this->assertStringContainsString('appUserDropdown', $html, 'the authenticated dropdown must render');
        $this->assertStringContainsString('B060 Layout Test User', $html, "the user's displayName must render");
        $this->assertStringContainsString('/settings/profile', $html, 'the Profile link must be present');
        $this->assertStringContainsString('/settings', $html, 'the Settings link must be present');
        $this->assertStringContainsString('/logout', $html, 'the Logout link must be present');
        $this->assertStringContainsString('rounded-circle', $html, 'an avatar (image or CSS-initials) must render');

        // 🚪 Guest-only markup must NOT be present.
        $this->assertStringNotContainsString('Sign Up', $html, 'guest-only Sign Up button must not render for an authenticated user');

        // 🦶 Footer content present.
        $this->assertStringContainsString('MWBMPartners', $html, 'the footer copyright line must render');
        $this->assertStringContainsString('bootstrap.bundle.min.js', $html, 'the Bootstrap JS bundle must be included');

        // 🧩 The test content in between must have rendered too (proves the
        // nesting contract — header.php/footer.php do not swallow output).
        $this->assertStringContainsString('B-060 test content', $html);
    }

    /**
     * Calling getCurrentUser(true)-style cache invalidation ($refresh via
     * the global helper is covered by SignulaRootAndAuthHelpersTest;
     * here we confirm header.php picks up whatever Auth::getCurrentUser()
     * currently returns — i.e. it is not itself caching a stale user across
     * two renders in the same request once Auth's cache is busted).
     */
    public function testHeaderReflectsUpdatedDisplayNameAfterAuthCacheBust(): void
    {
        $user = $this->createTestUser(['displayName' => 'Original Name']);
        $this->establishRealSession($user['userID']);

        $firstRender = $this->renderHeaderAndFooter('First Render');
        $this->assertStringContainsString('Original Name', $firstRender);

        // 🔄 Mutate the row directly and bust Auth's memoized cache — mirrors
        // exactly what the global getCurrentUser(true) helper does
        // (web/_config/config.php).
        $this->db->query(
            "UPDATE tblUsers SET displayName = 'Updated Name' WHERE userID = " . (int) $user['userID']
        );
        \Auth::$currentUser = null;

        $secondRender = $this->renderHeaderAndFooter('Second Render');
        $this->assertStringContainsString('Updated Name', $secondRender);
        $this->assertStringNotContainsString('Original Name', $secondRender);
    }

    // ========================================================================
    // ✅ GUEST RENDER — auth/passwordless-*.php reach header.php pre-login
    // ========================================================================

    /**
     * With no session at all (the state auth/passwordless-request.php,
     * auth/passwordless-login.php, and auth/passkey-login.php are in before
     * a user authenticates), header.php must degrade gracefully to a
     * Login/Sign Up guest state — never fatal, never emit a warning about an
     * undefined $headerUser.
     */
    public function testHeaderAndFooterRenderForGuestUser(): void
    {
        // ✅ No establishRealSession() call — genuinely unauthenticated.
        $html = $this->renderHeaderAndFooter('B-060 Guest Render Test');

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertSame(1, self::countOccurrences($html, '<html'));
        $this->assertSame(1, self::countOccurrences($html, '</html>'));
        $this->assertSame(1, self::countOccurrences($html, '<body'));
        $this->assertSame(1, self::countOccurrences($html, '</body>'));

        $this->assertStringContainsString('Login', $html, 'the guest Login button must render');
        $this->assertStringContainsString('Sign Up', $html, 'the guest Sign Up button must render');
        $this->assertStringNotContainsString('appUserDropdown', $html, 'the authenticated dropdown must NOT render for a guest');

        $this->assertStringContainsString('B-060 test content', $html);
    }

    // ========================================================================
    // ✅ SETTINGS-SIDEBAR NESTING — the shape every settings/* page uses
    // ========================================================================

    /**
     * settings/*.php pages nest private_html/layout/settings-sidebar.php as
     * a `col-md-3` alongside their own `col-md-9` content, all INSIDE their
     * own `.container` — between header.php and footer.php. Proves that
     * three-way nesting balances (header.php contract explicitly does NOT
     * open the wrapper the sidebar sits in; the calling page does).
     */
    public function testHeaderFooterAndSettingsSidebarNestTogetherWithoutFatal(): void
    {
        $user = $this->createTestUser(['displayName' => 'B060 Sidebar Test User']);
        $this->establishRealSession($user['userID']);

        $pageTitle = 'B-060 Sidebar Nesting Test';
        $headerPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'private_html'
            . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'header.php';
        $footerPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'private_html'
            . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'footer.php';
        $sidebarPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'private_html'
            . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'settings-sidebar.php';

        ob_start();
        include $headerPath;
        ?>
        <div class="container mt-5">
            <div class="row">
                <div class="col-md-3 mb-4">
                    <?php include $sidebarPath; ?>
                </div>
                <div class="col-md-9">
                    <p>B-060 sidebar nesting content</p>
                </div>
            </div>
        </div>
        <?php
        include $footerPath;
        $html = ob_get_clean();

        $this->assertSame(1, self::countOccurrences($html, '<html'));
        $this->assertSame(1, self::countOccurrences($html, '</html>'));
        $this->assertStringContainsString('Account Settings', $html, 'settings-sidebar.php content must render');
        $this->assertStringContainsString('/settings/passkeys', $html, 'settings-sidebar.php nav links must render');
        $this->assertStringContainsString('B060 Sidebar Test User', $html);
        $this->assertStringContainsString('B-060 sidebar nesting content', $html);
    }
}
