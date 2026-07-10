<?php
/**
 * ============================================================================
 * 🧪 SIGNULA_ROOT + requireLogin()/isLoggedIn()/getCurrentUser() Tests (B-060)
 * ============================================================================
 *
 * B-060 (HIGH, "hidden broken"): 37 files under web/public_html/ referenced
 * the constant SIGNULA_ROOT as a path prefix, and 15/5/13 more called the
 * bare global functions requireLogin()/isLoggedIn()/getCurrentUser() — NONE
 * of the four symbols were ever defined anywhere in the codebase, so every
 * dependent page fataled on load with either "Undefined constant SIGNULA_ROOT"
 * or "Call to undefined function ...()". This suite proves web/_config/
 * config.php now defines all four, that SIGNULA_ROOT resolves to the exact
 * same directory as ROOT_DIR, and that the three functions behave correctly
 * against a REAL authenticated session (via Auth — the same class every
 * consumer page already gates on successfully elsewhere, e.g. settings/
 * connected-apps.php).
 *
 * Why every test here runs config.php in an ISOLATED SUBPROCESS rather than
 * requiring it in-process (mirrors _tests/Integration/Config/
 * LoadSettingsResilienceTest.php / SanitizeRedirectUrlTest.php exactly — see
 * either file's own header note for the full rationale):
 *   - config.php unconditionally `define('SIGNULA_INIT', true)` and
 *     unconditionally defines getSetting()/setSetting()/redirect()/
 *     jsonResponse()/requireLogin()/isLoggedIn()/getCurrentUser() — bootstrap.
 *     php already defines several of those same names as test stubs
 *     process-wide, so requiring config.php in-process would fatal with
 *     "Cannot redeclare getSetting()".
 *   A short-lived, isolated `php` subprocess sidesteps this while still
 *   exercising the REAL, unmodified config.php against the real test
 *   database — no reimplementation/duplication of the logic under test.
 *
 * @package    SIGNula\Tests\Integration\Config
 * @version    2.9.0-beta
 * @see        web/_config/config.php (SIGNULA_ROOT, requireLogin(), isLoggedIn(), getCurrentUser())
 * @see        web/_config/database.php (leading-whitespace headers_sent() fix this suite also depends on — see that file's own header note)
 * @see        web/private_html/auth/Auth.php (the class all three functions delegate to)
 * @see        _tests/Integration/Config/LoadSettingsResilienceTest.php (the subprocess pattern this mirrors)
 * @see        _tests/Integration/Auth/GetCurrentUserAdminFlagsTest.php (the real-session fixture pattern this mirrors)
 */

namespace SIGNula\Tests\Integration\Config;

use SIGNula\Tests\DatabaseTestCase;
use RuntimeException;

/**
 * SIGNULA_ROOT + auth-helper wiring test suite.
 */
class SignulaRootAndAuthHelpersTest extends DatabaseTestCase
{
    /**
     * ⚠️ Do NOT use transaction rollback here — see LoadSettingsResilienceTest.
     * php's identical note: the subprocess spawned below opens its OWN mysqli
     * connection and cannot see rows inserted-but-not-committed on $this->db's
     * connection. Rows are inserted as real, committed writes and explicitly
     * cleaned up in tearDown().
     */
    protected bool $useTransactions = false;

    /**
     * 🏷️ Distinctive username prefix so tearDown() can find-and-delete
     * precisely (and only) the rows this suite created.
     */
    private const USERNAME_PREFIX = 'b060_authhelpers_';

    /**
     * 🧹 Delete only the rows this test created.
     */
    protected function tearDown(): void
    {
        $this->db->query(
            "DELETE s FROM tblUserSessions s
             JOIN tblUsers u ON u.userID = s.userID
             WHERE u.username LIKE '" . self::USERNAME_PREFIX . "%'"
        );
        $this->db->query(
            "DELETE FROM tblUsers WHERE username LIKE '" . self::USERNAME_PREFIX . "%'"
        );

        parent::tearDown();
    }

    /**
     * Create a real, committed user + tblUserSessions row resolvable via
     * Auth::getCurrentUserID()'s COOKIE branch (hash('sha256', $rawToken)),
     * and return the raw token to send back as $_COOKIE['signula_session'].
     *
     * @return array{userID: int, displayName: string, token: string}
     */
    private function createRealUserWithCookieSession(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $username = self::USERNAME_PREFIX . $suffix;

        $userID = $this->insertRecord('tblUsers', [
            'userUUID'      => self::generateTestUuid(),
            'username'      => $username,
            'email'         => 'b060_authhelpers_' . $suffix . '@example.com',
            'passwordHash'  => password_hash('password123', PASSWORD_ARGON2ID),
            'displayName'   => 'B060 AuthHelpers ' . $suffix,
            'accountStatus' => 'active',
            'emailVerified' => 1,
        ]);

        $rawToken = bin2hex(random_bytes(32));

        $this->insertRecord('tblUserSessions', [
            'sessionToken' => hash('sha256', $rawToken),
            'userID'       => $userID,
            'deviceType'   => 'web',
            'ipAddress'    => '127.0.0.1',
            'isActive'     => 1,
            'expiresAt'    => date('Y-m-d H:i:s', time() + 86400),
        ]);

        return [
            'userID'      => $userID,
            'displayName' => 'B060 AuthHelpers ' . $suffix,
            'token'       => $rawToken,
        ];
    }

    /**
     * Run a small harness script in an isolated `php` subprocess that
     * requires the REAL config.php, optionally with $_COOKIE['signula_session']
     * pre-set, and returns every result as a JSON map via a dedicated result
     * file (never stdout — config.php's own top-level code can print PHP 8.4
     * deprecation notices on stdout, which would otherwise pollute a
     * stdout-parsing contract; mirrors SanitizeRedirectUrlTest.php exactly).
     *
     * @param string $cookieToken Raw remember-me token to send as
     *                            $_COOKIE['signula_session'], or '' for none
     *                            (unauthenticated request).
     * @return array<string, mixed>
     */
    private function runConfigInSubprocess(string $cookieToken = ''): array
    {
        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbUser = getenv('DB_USER') ?: 'root';
        $dbPass = getenv('DB_PASS') ?: '';
        $dbName = getenv('DB_NAME') ?: 'signula_test';
        $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'test-encryption-key-32-chars-xx';

        $configPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

        $resultPath = tempnam(sys_get_temp_dir(), 'signula_b060_result_');
        if ($resultPath === false) {
            throw new RuntimeException('Could not create temp file for B-060 subprocess result');
        }
        $resultPathPhp = var_export($resultPath, true);
        $cookieTokenPhp = var_export($cookieToken, true);

        $harnessScript = <<<PHP
<?php
putenv('DB_HOST={$dbHost}');
putenv('DB_USER={$dbUser}');
putenv('DB_PASS={$dbPass}');
putenv('DB_NAME={$dbName}');
putenv('ENCRYPTION_KEY={$encryptionKey}');
putenv('ENVIRONMENT=production');

\$_SERVER['REQUEST_METHOD']  = 'GET';
\$_SERVER['REQUEST_URI']     = '/b060-subprocess-test';
\$_SERVER['HTTP_HOST']       = 'localhost';
\$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
\$_SERVER['HTTP_USER_AGENT'] = 'SIGNula/B-060-Test';

\$cookieToken = {$cookieTokenPhp};
if (\$cookieToken !== '') {
    \$_COOKIE['signula_session'] = \$cookieToken;
}

require '{$configPath}';

\$out = [];
\$out['signula_root_defined']    = defined('SIGNULA_ROOT');
\$out['signula_root_value']      = defined('SIGNULA_ROOT') ? SIGNULA_ROOT : null;
\$out['root_dir_value']          = defined('ROOT_DIR') ? ROOT_DIR : null;
\$out['requireLogin_exists']     = function_exists('requireLogin');
\$out['isLoggedIn_exists']       = function_exists('isLoggedIn');
\$out['getCurrentUser_exists']   = function_exists('getCurrentUser');
\$out['isLoggedIn_result']       = isLoggedIn();
\$out['getCurrentUser_result']   = getCurrentUser();

file_put_contents({$resultPathPhp}, json_encode(\$out));
PHP;

        $scriptPath = tempnam(sys_get_temp_dir(), 'signula_b060_harness_');
        if ($scriptPath === false) {
            @unlink($resultPath);
            throw new RuntimeException('Could not create temp file for B-060 subprocess harness');
        }
        file_put_contents($scriptPath, $harnessScript);

        try {
            $phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            shell_exec($phpBinary . ' ' . escapeshellarg($scriptPath) . ' > /dev/null 2>&1');

            $this->assertFileExists(
                $resultPath,
                'B-060 subprocess harness did not produce a result file — it likely fataled before file_put_contents(). Re-run the harness script manually (without redirecting output) to see why.'
            );

            $rawResult = file_get_contents($resultPath);
        } finally {
            @unlink($scriptPath);
            @unlink($resultPath);
        }

        $decoded = json_decode((string) $rawResult, true);
        $this->assertIsArray(
            $decoded,
            'B-060 subprocess harness did not write valid JSON. Raw result file contents: ' . var_export($rawResult, true)
        );

        return $decoded;
    }

    // ========================================================================
    // ✅ SIGNULA_ROOT
    // ========================================================================

    /**
     * SIGNULA_ROOT must be defined by config.php and equal ROOT_DIR exactly —
     * every one of the 37 dependent files assumes SIGNULA_ROOT points at the
     * same web/ directory ROOT_DIR resolves to.
     */
    public function testSignulaRootIsDefinedAndEqualsRootDir(): void
    {
        $r = $this->runConfigInSubprocess();

        $this->assertTrue($r['signula_root_defined'] ?? false, 'SIGNULA_ROOT must be defined by config.php');
        $this->assertNotNull($r['root_dir_value'] ?? null, 'ROOT_DIR must be defined by config.php (sanity check)');
        $this->assertSame(
            $r['root_dir_value'],
            $r['signula_root_value'],
            'SIGNULA_ROOT must resolve to the exact same directory as ROOT_DIR'
        );
    }

    // ========================================================================
    // ✅ requireLogin() / isLoggedIn() / getCurrentUser() ARE DEFINED
    // ========================================================================

    /**
     * All three previously-undefined global helpers must now be defined
     * callables — the root cause of every "Call to undefined function" fatal
     * this suite's class-doc describes.
     */
    public function testAuthHelperFunctionsAreDefinedCallables(): void
    {
        $r = $this->runConfigInSubprocess();

        $this->assertTrue($r['requireLogin_exists'] ?? false, 'requireLogin() must be defined');
        $this->assertTrue($r['isLoggedIn_exists'] ?? false, 'isLoggedIn() must be defined');
        $this->assertTrue($r['getCurrentUser_exists'] ?? false, 'getCurrentUser() must be defined');
    }

    // ========================================================================
    // ✅ BEHAVIOUR AGAINST A REAL SESSION
    // ========================================================================

    /**
     * With NO session/cookie at all, isLoggedIn() must be false and
     * getCurrentUser() must be null — the correct "guest" result, not a
     * crash.
     */
    public function testIsLoggedInAndGetCurrentUserReturnGuestValuesWithNoSession(): void
    {
        $r = $this->runConfigInSubprocess();

        $this->assertArrayHasKey('isLoggedIn_result', $r, 'harness must always report isLoggedIn_result');
        $this->assertArrayHasKey('getCurrentUser_result', $r, 'harness must always report getCurrentUser_result');
        $this->assertFalse($r['isLoggedIn_result'], 'isLoggedIn() must be FALSE with no session');
        // ⚠️ Deliberately NOT `$r['getCurrentUser_result'] ?? 'sentinel'` — the
        // expected value here IS null, and `??` treats an existing null value
        // the same as a missing array key, which would mask a real null
        // behind the sentinel and always fail this assertion.
        $this->assertNull($r['getCurrentUser_result'], 'getCurrentUser() must be NULL with no session');
    }

    /**
     * With a REAL, committed, resolvable session (via the same remember-me
     * cookie mechanism Auth::getCurrentUserID() already supports),
     * isLoggedIn() must be true and getCurrentUser() must return that exact
     * user's record.
     */
    public function testIsLoggedInAndGetCurrentUserReflectRealAuthenticatedSession(): void
    {
        $fixture = $this->createRealUserWithCookieSession();

        $r = $this->runConfigInSubprocess($fixture['token']);

        $this->assertTrue($r['isLoggedIn_result'] ?? false, 'isLoggedIn() must be TRUE for a real, active, unexpired session');
        $this->assertIsArray($r['getCurrentUser_result'] ?? null, 'getCurrentUser() must return an array for an authenticated session');
        $this->assertSame(
            $fixture['userID'],
            $r['getCurrentUser_result']['userID'] ?? null,
            'getCurrentUser() must return the SAME user the session cookie resolves to'
        );
        $this->assertSame(
            $fixture['displayName'],
            $r['getCurrentUser_result']['displayName'] ?? null
        );
    }
}
