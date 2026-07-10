<?php
/**
 * ============================================================================
 * 🧪 sanitizeRedirectUrl() Wiring Tests (B-057 — MFA return-redirect open-redirect fix)
 * ============================================================================
 *
 * Proves TWO things about the B-057 fix:
 *
 *   1. sanitizeRedirectUrl() (web/_config/config.php) itself rejects
 *      absolute URLs, protocol-relative URLs, and non-HTTP schemes, falling
 *      back to a safe default — while still passing a genuinely safe
 *      relative path through unchanged.
 *   2. The EXACT wiring expressions now used at the two call sites this task
 *      fixed — Auth::login()'s SET-time line (web/private_html/auth/Auth.php
 *      ~L377, `$_SESSION['redirect_after_login'] = sanitizeRedirectUrl(...)`)
 *      and mfa/verify.php's CONSUME-time line (~L99-102, re-sanitizing the
 *      stored session value before redirect()) — actually neutralise a
 *      malicious `?redirect=`/session value end to end, and still pass a
 *      SAFE relative path through untouched.
 *
 * Why an isolated `php` SUBPROCESS rather than requiring config.php
 * in-process (mirrors _tests/Integration/Config/LoadSettingsResilienceTest.php
 * exactly, see its own header note for the full rationale):
 *   - config.php unconditionally `define('SIGNULA_INIT', true)` and
 *     unconditionally defines the global functions getSetting()/
 *     setSetting()/initializeSession()/sanitizeRedirectUrl() — bootstrap.php
 *     already defines a getSetting() stub process-wide, so requiring
 *     config.php in-process would fatal with "Cannot redeclare getSetting()".
 *   A short-lived, isolated subprocess sidesteps this while still exercising
 *   the REAL, unmodified sanitizeRedirectUrl() (and the real call-site
 *   expressions, reproduced verbatim from Auth.php/mfa/verify.php) against a
 *   real bootstrap — no reimplementation/duplication of the logic under test.
 *
 * @package    SIGNula\Tests\Integration\Config
 * @version    2.9.0-beta
 * @see        web/_config/config.php (sanitizeRedirectUrl())
 * @see        web/private_html/auth/Auth.php (SET-time wiring, ~L377)
 * @see        web/public_html/mfa/verify.php (CONSUME-time wiring, ~L99-102)
 * @see        _tests/Integration/Config/LoadSettingsResilienceTest.php (the subprocess pattern this mirrors)
 */

namespace SIGNula\Tests\Integration\Config;

use SIGNula\Tests\DatabaseTestCase;
use RuntimeException;

/**
 * sanitizeRedirectUrl() wiring test suite.
 */
class SanitizeRedirectUrlTest extends DatabaseTestCase
{
    /**
     * Run a small harness script in an isolated `php` subprocess that
     * requires the REAL config.php (so sanitizeRedirectUrl() is the genuine,
     * unmodified production function), exercises it both directly AND via
     * the exact Auth.php/mfa-verify.php call-site expressions, and returns
     * every result as a JSON map via a dedicated result file (never stdout —
     * config.php's own top-level code can print PHP 8.4 deprecation notices
     * on stdout before display_errors is lowered, which would otherwise
     * pollute a stdout-parsing contract).
     *
     * @return array<string, mixed>
     */
    private function runSanitizeRedirectUrlInSubprocess(): array
    {
        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbUser = getenv('DB_USER') ?: 'root';
        $dbPass = getenv('DB_PASS') ?: '';
        $dbName = getenv('DB_NAME') ?: 'signula_test';
        $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'test-encryption-key-32-chars-xx';

        $configPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

        $resultPath = tempnam(sys_get_temp_dir(), 'signula_sanitize_redirect_result_');
        if ($resultPath === false) {
            throw new RuntimeException('Could not create temp file for sanitizeRedirectUrl() subprocess result');
        }
        $resultPathPhp = var_export($resultPath, true);

        // 🧪 Harness script — boots the REAL config.php, then:
        //   (a) calls sanitizeRedirectUrl() directly with malicious/benign inputs;
        //   (b) reproduces Auth.php's SET-time expression VERBATIM against a
        //       crafted $_GET['redirect'];
        //   (c) reproduces mfa/verify.php's CONSUME-time expression VERBATIM
        //       against a crafted $_SESSION['redirect_after_login'].
        $harnessScript = <<<PHP
<?php
putenv('DB_HOST={$dbHost}');
putenv('DB_USER={$dbUser}');
putenv('DB_PASS={$dbPass}');
putenv('DB_NAME={$dbName}');
putenv('ENCRYPTION_KEY={$encryptionKey}');

require '{$configPath}';

\$out = [];

// (a) sanitizeRedirectUrl() itself — direct calls.
\$out['absolute_https']    = sanitizeRedirectUrl('https://evil.com');
\$out['protocol_relative'] = sanitizeRedirectUrl('//evil.com');
\$out['js_scheme']         = sanitizeRedirectUrl('javascript:alert(1)');
\$out['empty_input']       = sanitizeRedirectUrl('');
\$out['safe_relative']     = sanitizeRedirectUrl('/dashboard/settings');
\$out['custom_fallback']   = sanitizeRedirectUrl('https://evil.com', '/safe-fallback');

// (b) Auth.php ~L377 SET-time wiring, reproduced verbatim:
//     \$_SESSION['redirect_after_login'] = sanitizeRedirectUrl(\$requestedRedirect);
//     where \$requestedRedirect guards a non-scalar \$_GET['redirect'].
\$_GET['redirect'] = 'https://evil.com/phish';
\$requestedRedirect = isset(\$_GET['redirect']) && is_scalar(\$_GET['redirect']) ? (string) \$_GET['redirect'] : '/dashboard';
\$out['auth_set_time_malicious'] = sanitizeRedirectUrl(\$requestedRedirect);

unset(\$_GET['redirect']);
\$requestedRedirectDefault = isset(\$_GET['redirect']) && is_scalar(\$_GET['redirect']) ? (string) \$_GET['redirect'] : '/dashboard';
\$out['auth_set_time_default'] = sanitizeRedirectUrl(\$requestedRedirectDefault);

\$_GET['redirect'] = '/settings/profile';
\$requestedRedirectSafe = isset(\$_GET['redirect']) && is_scalar(\$_GET['redirect']) ? (string) \$_GET['redirect'] : '/dashboard';
\$out['auth_set_time_safe'] = sanitizeRedirectUrl(\$requestedRedirectSafe);

// (c) mfa/verify.php ~L99-102 CONSUME-time wiring, reproduced verbatim:
//     \$storedRedirect = \$_SESSION['redirect_after_login'] ?? '/dashboard';
//     \$redirect = sanitizeRedirectUrl(is_scalar(\$storedRedirect) ? (string) \$storedRedirect : '/dashboard');
\$_SESSION['redirect_after_login'] = 'https://evil.com/phish';
\$storedRedirect = \$_SESSION['redirect_after_login'] ?? '/dashboard';
\$out['mfa_consume_malicious'] = sanitizeRedirectUrl(is_scalar(\$storedRedirect) ? (string) \$storedRedirect : '/dashboard');

unset(\$_SESSION['redirect_after_login']);
\$storedRedirectDefault = \$_SESSION['redirect_after_login'] ?? '/dashboard';
\$out['mfa_consume_default'] = sanitizeRedirectUrl(is_scalar(\$storedRedirectDefault) ? (string) \$storedRedirectDefault : '/dashboard');

\$_SESSION['redirect_after_login'] = '/settings/profile';
\$storedRedirectSafe = \$_SESSION['redirect_after_login'] ?? '/dashboard';
\$out['mfa_consume_safe'] = sanitizeRedirectUrl(is_scalar(\$storedRedirectSafe) ? (string) \$storedRedirectSafe : '/dashboard');

// (d) Non-scalar `redirect[]=` array param must NOT crash (TypeError) —
//     the is_scalar() guard added alongside the B-057 fix.
\$_GET['redirect'] = ['evil' => 'array'];
\$requestedRedirectArray = isset(\$_GET['redirect']) && is_scalar(\$_GET['redirect']) ? (string) \$_GET['redirect'] : '/dashboard';
\$out['auth_set_time_array_param'] = sanitizeRedirectUrl(\$requestedRedirectArray);

file_put_contents({$resultPathPhp}, json_encode(\$out));
PHP;

        $scriptPath = tempnam(sys_get_temp_dir(), 'signula_sanitize_redirect_');
        if ($scriptPath === false) {
            @unlink($resultPath);
            throw new RuntimeException('Could not create temp file for sanitizeRedirectUrl() subprocess harness');
        }
        file_put_contents($scriptPath, $harnessScript);

        try {
            $phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            shell_exec($phpBinary . ' ' . escapeshellarg($scriptPath) . ' > /dev/null 2>&1');

            $this->assertFileExists(
                $resultPath,
                'sanitizeRedirectUrl() subprocess harness did not produce a result file — it likely fataled before file_put_contents(). Re-run the harness script manually (without redirecting output) to see why.'
            );

            $rawResult = file_get_contents($resultPath);
        } finally {
            @unlink($scriptPath);
            @unlink($resultPath);
        }

        $decoded = json_decode((string) $rawResult, true);
        $this->assertIsArray(
            $decoded,
            'sanitizeRedirectUrl() subprocess harness did not write valid JSON. Raw result file contents: ' . var_export($rawResult, true)
        );

        return $decoded;
    }

    // ========================================================================
    // ✅ THE FIX
    // ========================================================================

    /**
     * sanitizeRedirectUrl() itself rejects absolute/protocol-relative/
     * non-HTTP-scheme URLs (falling back to the default, or a custom
     * fallback when one is supplied), while a genuinely safe relative path
     * passes through unchanged.
     */
    public function testSanitizeRedirectUrlRejectsUnsafeUrls(): void
    {
        $r = $this->runSanitizeRedirectUrlInSubprocess();

        $this->assertSame('/dashboard', $r['absolute_https'] ?? null, 'an absolute https:// URL must fall back');
        $this->assertSame('/dashboard', $r['protocol_relative'] ?? null, 'a protocol-relative //evil.com URL must fall back');
        $this->assertSame('/dashboard', $r['js_scheme'] ?? null, 'a javascript: URL must fall back');
        $this->assertSame('/dashboard', $r['empty_input'] ?? null, 'an empty string must fall back to the default');
        $this->assertSame('/dashboard/settings', $r['safe_relative'] ?? null, 'a genuinely safe relative path must pass through unchanged');
        $this->assertSame('/safe-fallback', $r['custom_fallback'] ?? null, 'a custom fallback argument must be honoured');
    }

    /**
     * 🔒 B-057 — Auth::login()'s SET-time wiring
     * (`$_SESSION['redirect_after_login'] = sanitizeRedirectUrl(...)`)
     * neutralises a malicious `?redirect=` value BEFORE it is ever stored in
     * the session, while a safe relative path is preserved.
     */
    public function testAuthSetTimeWiringSanitizesMaliciousRedirectParam(): void
    {
        $r = $this->runSanitizeRedirectUrlInSubprocess();

        $this->assertSame(
            '/dashboard',
            $r['auth_set_time_malicious'] ?? null,
            'a malicious ?redirect= must be sanitized BEFORE being stored in the session (B-057)'
        );
        $this->assertSame('/dashboard', $r['auth_set_time_default'] ?? null, 'no ?redirect= at all must fall back to /dashboard');
        $this->assertSame('/settings/profile', $r['auth_set_time_safe'] ?? null, 'a safe ?redirect= must be preserved');
    }

    /**
     * 🔒 B-057 — mfa/verify.php's CONSUME-time re-sanitize is a genuine
     * defensive SECOND check: even if something else ever wrote an unsafe
     * value into the session, the redirect actually issued is still safe.
     */
    public function testMfaConsumeTimeWiringSanitizesMaliciousStoredRedirect(): void
    {
        $r = $this->runSanitizeRedirectUrlInSubprocess();

        $this->assertSame(
            '/dashboard',
            $r['mfa_consume_malicious'] ?? null,
            'a malicious value in $_SESSION[redirect_after_login] must still be sanitized at consume time (B-057 defence-in-depth)'
        );
        $this->assertSame('/dashboard', $r['mfa_consume_default'] ?? null, 'no stored redirect at all must fall back to /dashboard');
        $this->assertSame('/settings/profile', $r['mfa_consume_safe'] ?? null, 'a safe stored redirect must be preserved');
    }

    /**
     * A crafted non-scalar `redirect[]=` query param must NOT crash the
     * request (a bare sanitizeRedirectUrl($_GET['redirect']) would TypeError
     * on an array under a `string $url` parameter) — the is_scalar() guard
     * added alongside the B-057 fix coerces it safely to the fallback instead.
     */
    public function testAuthSetTimeWiringGuardsAgainstNonScalarRedirectParam(): void
    {
        $r = $this->runSanitizeRedirectUrlInSubprocess();

        $this->assertSame(
            '/dashboard',
            $r['auth_set_time_array_param'] ?? null,
            'a crafted redirect[]= array param must be coerced to the fallback, never crash'
        );
    }
}
