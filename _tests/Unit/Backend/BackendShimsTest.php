<?php
/**
 * ============================================================================
 * 🧪 _backend/*.php Bootstrap Shim Unit Tests (B-054 follow-up)
 * ============================================================================
 *
 * Covers the FOUR additional never-committed shims created alongside the
 * B-054 Database/getInstance fix, each of the exact same shape as
 * web/_backend/Database.php:
 *
 *   web/_backend/ActivityLogger.php    -> web/private_html/utils/ActivityLogger.php   (10 dependents)
 *   web/_backend/APIKeyManager.php     -> web/private_html/api/APIKeyManager.php       (1)
 *   web/_backend/RateLimiter.php       -> web/private_html/security/RateLimiter.php    (1)
 *   web/_backend/ServiceFeeManager.php -> web/private_html/payments/ServiceFeeManager.php (1)
 *
 * Each shim: declare(strict_types=1) + SIGNULA_INIT guard + a guarded
 * require_once of the REAL class from its canonical private_html/ location,
 * declaring NO class of its own. Pages under web/public_html historically
 * require the `_backend/<Class>.php` path (which never existed → fatal).
 *
 * ⚠️ Why every "class available" check runs in a disposable `php -r`
 * SUBPROCESS, NOT via an in-process require:
 *   The Unit suite runs every test file in ONE shared PHP process. Several
 *   existing Unit tests deliberately depend on these very classes NOT being
 *   loaded in that shared process, and assert the graceful-degradation
 *   behaviour that follows:
 *     - _tests/Unit/Security/SecurityMiddlewareTest.php
 *       ::testHandleFormSubmissionSkipsRateLimitingGracefully explicitly
 *       relies on `RateLimiter` being ABSENT ("RateLimiter is not loaded in
 *       unit test context").
 *     - _tests/Unit/Security/SecurityAlertManagerTest.php similarly relies on
 *       `Database` being absent (the sibling gotcha the Database shim test
 *       already documents).
 *   `require`-ing any of these classes here — even only to reflect on them —
 *   would define the symbol process-wide for the rest of the run and
 *   silently break those other suites' assumptions. Loading them in a
 *   throwaway child process keeps the parent Unit process pristine. The
 *   "declares no class of its own" check, by contrast, only tokenizes the
 *   shim's OWN source TEXT (loads nothing), so it is safe in-process.
 *
 * See _tests/Integration/Backend/BackendShimsTest.php for the DB-backed
 * proof that each shim's class is genuinely usable (instantiable) once loaded.
 *
 * @package    SIGNula\Tests\Unit\Backend
 * @version    2.7.0-beta
 * @see        web/_backend/ActivityLogger.php
 * @see        web/_backend/APIKeyManager.php
 * @see        web/_backend/RateLimiter.php
 * @see        web/_backend/ServiceFeeManager.php
 * @see        _tests/Unit/Backend/DatabaseTest.php (same subprocess pattern)
 */

namespace SIGNula\Tests\Unit\Backend;

use SIGNula\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * _backend/*.php Shim Unit Test Suite
 */
class BackendShimsTest extends TestCase
{
    /**
     * 🗂️ The four shims under test: [shim basename, expected class name].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shimProvider(): array
    {
        return [
            'ActivityLogger'    => ['ActivityLogger.php', 'ActivityLogger'],
            'APIKeyManager'     => ['APIKeyManager.php', 'APIKeyManager'],
            'RateLimiter'       => ['RateLimiter.php', 'RateLimiter'],
            'ServiceFeeManager' => ['ServiceFeeManager.php', 'ServiceFeeManager'],
        ];
    }

    /**
     * 🧪 Load config.php + the given shim in a disposable child process and
     * report whether the expected class became available — WITHOUT loading
     * anything into this shared Unit-suite process.
     *
     * @param string $shimBasename e.g. 'ActivityLogger.php'
     * @param string $className    e.g. 'ActivityLogger'
     * @return string Trimmed stdout from the child ('OK' on success)
     */
    private function checkClassAvailableInChildProcess(string $shimBasename, string $className): string
    {
        $configPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR .
            '_config' . DIRECTORY_SEPARATOR . 'config.php';
        $shimPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR .
            '_backend' . DIRECTORY_SEPARATOR . $shimBasename;

        // 🔧 config.php itself defines SIGNULA_INIT (no manual define needed),
        // starts a session, loads settings + the Database class, then we load
        // the shim exactly as a public_html page does (config.php first).
        $script = 'require_once ' . var_export($configPath, true) . ';'
            . 'require_once ' . var_export($shimPath, true) . ';'
            . 'echo class_exists(' . var_export($className, true) . ') ? "OK" : "MISSING";';

        // 🌱 Pass the test DB env vars through so config.php can connect if it
        // needs to (it lazily may); the class_exists check itself does not
        // require a live connection.
        $env = 'DB_HOST=' . escapeshellarg((string) (getenv('DB_HOST') ?: 'localhost')) . ' '
            . 'DB_USER=' . escapeshellarg((string) (getenv('DB_USER') ?: 'root')) . ' '
            . 'DB_PASS=' . escapeshellarg((string) (getenv('DB_PASS') ?: '')) . ' '
            . 'DB_NAME=' . escapeshellarg((string) (getenv('DB_NAME') ?: 'signula_test'));

        $command = $env . ' ' . escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -d display_errors=0 -r ' . escapeshellarg($script) . ' 2>&1';
        $output = shell_exec($command);

        if ($output === null || $output === false) {
            throw new RuntimeException("Child process for shim '{$shimBasename}' failed to execute.");
        }

        return trim($output);
    }

    /**
     * Each shim, loaded after config.php (the real bootstrap order for the
     * dependent pages), must make its expected class available with NO fatal.
     */
    #[DataProvider('shimProvider')]
    public function testShimMakesClassAvailable(string $shimBasename, string $className): void
    {
        $result = $this->checkClassAvailableInChildProcess($shimBasename, $className);

        $this->assertSame(
            'OK',
            $result,
            "web/_backend/{$shimBasename} must make class {$className} available after config.php. Child process output:\n{$result}"
        );
    }

    /**
     * Each shim must remain a PURE require-guard shim — it must never declare
     * its own copy of the class (would fatal-redeclare against the canonical
     * private_html/ implementation once both are loaded).
     *
     * Verified via genuine token analysis of the shim's OWN source text (via
     * token_get_all(), loading nothing), so this is safe to run in-process:
     * a real `class <Name>` statement is the ONLY way T_CLASS can be
     * immediately followed by a T_STRING of that name in the token stream —
     * doc-comment prose that merely mentions "class ActivityLogger" folds
     * into a single comment token and can never false-positive.
     *
     * @see https://www.php.net/manual/en/function.token-get-all.php
     */
    #[DataProvider('shimProvider')]
    public function testShimDeclaresNoClassOfItsOwn(string $shimBasename, string $className): void
    {
        $shimPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR .
            '_backend' . DIRECTORY_SEPARATOR . $shimBasename;
        $tokens = token_get_all(file_get_contents($shimPath));

        $declaresClass = false;
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }

            // 🔎 Find the next meaningful token after `class` (skip
            // whitespace/comments) — a class declaration statement's name.
            for ($lookahead = $index + 1; $lookahead < count($tokens); $lookahead++) {
                $next = $tokens[$lookahead];
                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($next) && $next[0] === T_STRING && strcasecmp($next[1], $className) === 0) {
                    $declaresClass = true;
                }
                break;
            }
        }

        $this->assertFalse(
            $declaresClass,
            "web/_backend/{$shimBasename} must remain a pure require-guard shim — it must never declare `class {$className}` itself (would fatal-redeclare against the canonical private_html/ implementation)."
        );
    }
}
