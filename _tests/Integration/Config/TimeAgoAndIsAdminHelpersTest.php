<?php
/**
 * ============================================================================
 * 🧪 timeAgo() + isAdmin() Global Helper Tests (B-066)
 * ============================================================================
 *
 * B-066 (HIGH): two more global functions referenced by multiple
 * web/public_html pages were never defined anywhere in the codebase (same
 * class of gap B-060's SignulaRootAndAuthHelpersTest.php covers for
 * SIGNULA_ROOT/requireLogin()/isLoggedIn()/getCurrentUser()):
 *
 *   - timeAgo($datetime)  — called by settings/index.php, activity.php,
 *     privacy.php, connected-accounts.php. Fatals with "Call to undefined
 *     function timeAgo()" the moment any of those pages has at least one row
 *     to render a relative timestamp for (e.g. the very first "login_success"
 *     activity row a real Auth::login() creates for the user rendering the
 *     page) — i.e. it blocks a REALISTIC logged-in user, not just an empty
 *     fixture.
 *   - isAdmin()  — called by admin/email-webhooks.php, email-config.php,
 *     email-dashboard.php. Fatals the same way; only a same-named LOCAL
 *     function inside admin/api/deploy-migration.php (a different, never-
 *     loaded script) previously existed under this name.
 *
 * Both are now defined in web/_config/config.php. This suite proves they
 * exist as callables and behave correctly against real inputs (timeAgo:
 * relative-time math; isAdmin: delegates to the SAME Auth::getCurrentUser()
 * `isAdmin` flag GetCurrentUserAdminFlagsTest.php already covers in depth —
 * this suite only proves the NEW global wrapper reads that flag correctly
 * for a super-admin vs a plain user, real logged-in sessions in both cases).
 *
 * Mirrors _tests/Integration/Config/SignulaRootAndAuthHelpersTest.php's
 * isolated-subprocess pattern exactly (see that file's own header note for
 * the full "why a subprocess" rationale — config.php unconditionally
 * redefines several names bootstrap.php already stubs process-wide).
 *
 * @package    SIGNula\Tests\Integration\Config
 * @version    2.9.0-beta
 * @see        web/_config/config.php (timeAgo(), isAdmin())
 * @see        web/private_html/auth/Auth.php (Auth::getCurrentUser()'s `isAdmin` key isAdmin() delegates to)
 * @see        _tests/Integration/Config/SignulaRootAndAuthHelpersTest.php (the subprocess pattern this mirrors)
 * @see        _tests/Integration/Auth/GetCurrentUserAdminFlagsTest.php (the isAdmin-flag-derivation coverage this builds on, not duplicates)
 */

namespace SIGNula\Tests\Integration\Config;

use SIGNula\Tests\DatabaseTestCase;
use RuntimeException;

/**
 * timeAgo() + isAdmin() global-helper test suite.
 */
class TimeAgoAndIsAdminHelpersTest extends DatabaseTestCase
{
    /**
     * ⚠️ No transaction rollback — the subprocess spawned below opens its OWN
     * mysqli connection (see runConfigInSubprocess()) and cannot see rows
     * inserted-but-not-committed on $this->db's connection. Mirrors
     * SignulaRootAndAuthHelpersTest.php's identical note.
     */
    protected bool $useTransactions = false;

    private const USERNAME_PREFIX = 'b066_timeagoisadmin_';

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
     * Create a real, committed user (+ optional isSuperAdmin) with a
     * resolvable cookie session — mirrors
     * SignulaRootAndAuthHelpersTest::createRealUserWithCookieSession().
     *
     * @return array{userID: int, token: string}
     */
    private function createRealUserWithCookieSession(bool $isSuperAdmin = false): array
    {
        $suffix = bin2hex(random_bytes(4));
        $username = self::USERNAME_PREFIX . $suffix;

        $userID = $this->insertRecord('tblUsers', [
            'userUUID'      => self::generateTestUuid(),
            'username'      => $username,
            'email'         => $username . '@example.com',
            'passwordHash'  => password_hash('password123', PASSWORD_ARGON2ID),
            'displayName'   => 'B066 TimeAgo/IsAdmin ' . $suffix,
            'accountStatus' => 'active',
            'emailVerified' => 1,
            'isSuperAdmin'  => $isSuperAdmin ? 1 : 0,
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

        return ['userID' => $userID, 'token' => $rawToken];
    }

    /**
     * Run a small harness script in an isolated `php` subprocess that
     * requires the REAL config.php, optionally with $_COOKIE['signula_session']
     * pre-set, and returns the results via a dedicated result file. Verbatim
     * structure of SignulaRootAndAuthHelpersTest::runConfigInSubprocess(),
     * extended with the timeAgo()/isAdmin() probes this suite needs.
     *
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

        $resultPath = tempnam(sys_get_temp_dir(), 'signula_b066_helpers_result_');
        if ($resultPath === false) {
            throw new RuntimeException('Could not create temp file for B-066 subprocess result');
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
\$_SERVER['REQUEST_URI']     = '/b066-helpers-subprocess-test';
\$_SERVER['HTTP_HOST']       = 'localhost';
\$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
\$_SERVER['HTTP_USER_AGENT'] = 'SIGNula/B-066-Test';

\$cookieToken = {$cookieTokenPhp};
if (\$cookieToken !== '') {
    \$_COOKIE['signula_session'] = \$cookieToken;
}

require '{$configPath}';

\$out = [];
\$out['timeAgo_exists'] = function_exists('timeAgo');
\$out['isAdmin_exists']  = function_exists('isAdmin');

// ⏱️ timeAgo() — pure function, no session/DB dependency for its own logic.
\$out['timeAgo_null']        = timeAgo(null);
\$out['timeAgo_empty']       = timeAgo('');
\$out['timeAgo_just_now']    = timeAgo(date('Y-m-d H:i:s', time() - 5));
\$out['timeAgo_5_minutes']   = timeAgo(date('Y-m-d H:i:s', time() - 300));
\$out['timeAgo_2_hours']     = timeAgo(date('Y-m-d H:i:s', time() - 7200));
\$out['timeAgo_3_days']      = timeAgo(date('Y-m-d H:i:s', time() - (3 * 86400)));
\$out['timeAgo_unparseable'] = timeAgo('not-a-real-date');

// 🛡️ isAdmin() — delegates to getCurrentUser()['isAdmin'].
\$out['isAdmin_result'] = isAdmin();

file_put_contents({$resultPathPhp}, json_encode(\$out));
PHP;

        $scriptPath = tempnam(sys_get_temp_dir(), 'signula_b066_helpers_harness_');
        if ($scriptPath === false) {
            @unlink($resultPath);
            throw new RuntimeException('Could not create temp file for B-066 subprocess harness');
        }
        file_put_contents($scriptPath, $harnessScript);

        try {
            $phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            // 🛡️ display_errors=0 — without it, PHP CLI's default
            // display_errors=On emits stray text (e.g. the E_STRICT-constant
            // deprecation notice PHP 8.4 raises at config.php's
            // `error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT)` line)
            // BEFORE session_start() runs, which then fails with "headers
            // already sent" — matching a real production php.ini's
            // display_errors=Off, not working around a bug.
            shell_exec($phpBinary . ' -d display_errors=0 ' . escapeshellarg($scriptPath) . ' > /dev/null 2>&1');

            $this->assertFileExists(
                $resultPath,
                'B-066 subprocess harness did not produce a result file — it likely fataled before file_put_contents(). Re-run the harness script manually (without redirecting output) to see why.'
            );

            $rawResult = file_get_contents($resultPath);
        } finally {
            @unlink($scriptPath);
            @unlink($resultPath);
        }

        $decoded = json_decode((string) $rawResult, true);
        $this->assertIsArray(
            $decoded,
            'B-066 subprocess harness did not write valid JSON. Raw result file contents: ' . var_export($rawResult, true)
        );

        return $decoded;
    }

    // ========================================================================
    // ✅ BOTH FUNCTIONS ARE DEFINED
    // ========================================================================

    public function testTimeAgoAndIsAdminAreDefinedCallables(): void
    {
        $r = $this->runConfigInSubprocess();

        $this->assertTrue($r['timeAgo_exists'] ?? false, 'timeAgo() must be defined');
        $this->assertTrue($r['isAdmin_exists'] ?? false, 'isAdmin() must be defined');
    }

    // ========================================================================
    // ✅ timeAgo() — relative-time math
    // ========================================================================

    public function testTimeAgoDegradesGracefullyOnNullEmptyAndUnparseableInput(): void
    {
        $r = $this->runConfigInSubprocess();

        $this->assertSame('just now', $r['timeAgo_null']);
        $this->assertSame('just now', $r['timeAgo_empty']);
        $this->assertSame('unknown', $r['timeAgo_unparseable']);
    }

    public function testTimeAgoFormatsRelativeDurationsCorrectly(): void
    {
        $r = $this->runConfigInSubprocess();

        $this->assertSame('just now', $r['timeAgo_just_now'], 'a 5-second-old timestamp must read as "just now"');
        $this->assertSame('5 minutes ago', $r['timeAgo_5_minutes']);
        $this->assertSame('2 hours ago', $r['timeAgo_2_hours']);
        $this->assertSame('3 days ago', $r['timeAgo_3_days']);
    }

    // ========================================================================
    // ✅ isAdmin() — delegates to Auth::getCurrentUser()['isAdmin']
    // ========================================================================

    public function testIsAdminIsFalseForGuestAndPlainUser(): void
    {
        $guest = $this->runConfigInSubprocess();
        $this->assertFalse($guest['isAdmin_result'], 'isAdmin() must be FALSE with no session at all');

        $plainUser = $this->createRealUserWithCookieSession(isSuperAdmin: false);
        $r = $this->runConfigInSubprocess($plainUser['token']);
        $this->assertFalse($r['isAdmin_result'], 'isAdmin() must be FALSE for a real, logged-in, non-admin user');
    }

    public function testIsAdminIsTrueForRealSuperAdminSession(): void
    {
        $admin = $this->createRealUserWithCookieSession(isSuperAdmin: true);
        $r = $this->runConfigInSubprocess($admin['token']);

        $this->assertTrue($r['isAdmin_result'], 'isAdmin() must be TRUE for a real, logged-in super-admin session — this is exactly the gate admin/email-webhooks.php relies on');
    }
}
