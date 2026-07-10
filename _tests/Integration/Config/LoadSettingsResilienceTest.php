<?php
/**
 * ============================================================================
 * 🧪 loadSettings() Resilience Integration Test (B-052 safety net)
 * ============================================================================
 *
 * Proves the resilience contract fixed in web/_config/config.php::loadSettings():
 * a single blank/undecryptable `isSensitive` row must NOT abort the whole
 * settings load. Before B-052, SecurityUtils::decrypt() threw a
 * RuntimeException for a blank/invalid sensitive value, and that throw sat
 * INSIDE loadSettings()'s single outer try/catch with no per-row handling —
 * so the exception propagated straight out of the `foreach`, and every
 * setting after the first bad row silently vanished from $GLOBALS['settings'].
 * Fresh installs seed ~40+ blank placeholder isSensitive rows (unconfigured
 * OAuth/payment/CAPTCHA credentials), so this starved nearly every page of
 * settings.
 *
 * Why this runs loadSettings() in an ISOLATED SUBPROCESS rather than
 * requiring web/_config/config.php in-process:
 *   - config.php unconditionally `define('SIGNULA_INIT', true)` (no
 *     `!defined()` guard) — bootstrap.php already defines that same constant,
 *     so a second, in-process define() would emit an
 *     "already defined" E_WARNING, which fails the suite under this project's
 *     failOnWarning="true" (see phpunit.xml).
 *   - config.php also unconditionally defines the global functions
 *     getSetting()/setSetting()/initializeSession() — bootstrap.php's stub
 *     getSetting() is already loaded process-wide (used by every other test),
 *     so requiring config.php in-process would fatal with
 *     "Cannot redeclare getSetting()".
 *   A short-lived, isolated `php` subprocess sidesteps both problems entirely
 *   while still exercising the REAL, unmodified loadSettings() function
 *   against the real test database — no reimplementation/duplication of the
 *   logic under test.
 *
 * @package    SIGNula\Tests\Integration\Config
 * @version    2.7.0-beta
 * @see        web/_config/config.php (loadSettings())
 * @see        web/private_html/security/SecurityUtils.php (decrypt())
 */

namespace SIGNula\Tests\Integration\Config;

use SIGNula\Tests\DatabaseTestCase;
use RuntimeException;

/**
 * loadSettings() Resilience Integration Test Suite
 */
class LoadSettingsResilienceTest extends DatabaseTestCase
{
    /**
     * ⚠️ Do NOT use transaction rollback here.
     *
     * The subprocess spawned in the test below opens its OWN, SEPARATE mysqli
     * connection to signula_test — it cannot see rows inserted-but-not-yet-
     * committed on $this->db's connection. Rows are inserted as real,
     * committed writes instead, and explicitly cleaned up in tearDown() by
     * settingKey (mirrors the pattern already used by
     * _tests/Integration/API/JwtAuthEndpointsTest.php for its seeded
     * jwt.signing_key.* rows).
     *
     * tblSettings itself is deliberately NEVER truncated by this suite — it
     * holds real, shared seed data (e.g. the RS256 signing key and
     * oauth.pairwise_salt) that OTHER integration tests depend on staying
     * present across the whole (randomly-ordered) test run — see
     * _tests/Integration/Auth/OAuthClientTest.php's own comment to the same
     * effect.
     */
    protected bool $useTransactions = false;

    /**
     * 🏷️ Distinctive prefix for every settingKey this test inserts, so
     * tearDown() can find-and-delete precisely (and only) its own rows.
     */
    private const KEY_PREFIX = 'test.b052_resilience.';

    /**
     * 🧹 Delete only the rows this test created — tblSettings is shared with
     * other integration test suites and must be left otherwise untouched.
     */
    protected function tearDown(): void
    {
        $this->db->query(
            "DELETE FROM tblSettings WHERE settingKey LIKE '" . self::KEY_PREFIX . "%'"
        );

        parent::tearDown();
    }

    /**
     * Insert one tblSettings row via a direct prepared statement (not
     * DatabaseTestCase::insertRecord(), which returns an insert_id we don't
     * need here and would otherwise tie this test to auto-increment values).
     *
     * @param string      $key         settingKey (already includes KEY_PREFIX)
     * @param string|null $value       settingValue (null/'' simulates the
     *                                 unconfigured placeholder rows fresh
     *                                 installs seed)
     * @param bool        $isSensitive Whether the row is marked isSensitive
     */
    private function insertTestSetting(string $key, ?string $value, bool $isSensitive): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO tblSettings (settingKey, settingValue, settingType, settingCategory, isSensitive)
             VALUES (?, ?, 'string', 'test', ?)"
        );
        $isSensitiveInt = $isSensitive ? 1 : 0;
        $stmt->bind_param('ssi', $key, $value, $isSensitiveInt);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Run the REAL, unmodified loadSettings() in an isolated `php` subprocess
     * against the real test database, and return the settings it loaded —
     * scoped down to just the 3 keys this test cares about (keeps the
     * harness's stdout contract small and independent of however many other
     * settings rows exist).
     *
     * @param array<string> $keysToReport settingKeys to read back out of
     *                                    $GLOBALS['settings'] after
     *                                    loadSettings() runs
     * @return array<string, mixed> Map of key => loaded value (a key missing
     *                              from $GLOBALS['settings'] altogether comes
     *                              back as the literal string '__MISSING__')
     */
    private function runLoadSettingsInSubprocess(array $keysToReport): array
    {
        // 🌍 Same DB env vars _tests/bootstrap.php's mock_database() uses,
        // read here (not relied on via inheritance) so the subprocess is
        // self-contained regardless of how the parent process received them.
        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbUser = getenv('DB_USER') ?: 'root';
        $dbPass = getenv('DB_PASS') ?: '';
        $dbName = getenv('DB_NAME') ?: 'signula_test';
        $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'test-encryption-key-32-chars-xx';

        $configPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

        $reportKeysPhp = var_export($keysToReport, true);

        // 📄 Write the result to its OWN dedicated file rather than stdout.
        // config.php's own top-level code prints a "Constant E_STRICT is
        // deprecated" notice under PHP 8.4+ (display_errors is still CLI's
        // default at the point that line runs, before config.php lowers it) —
        // that notice lands on STDOUT, not stderr, so a stdout-parsing
        // contract would be fragile/polluted. A dedicated output file has no
        // such ambiguity.
        $resultPath = tempnam(sys_get_temp_dir(), 'signula_loadsettings_result_');
        if ($resultPath === false) {
            throw new RuntimeException('Could not create temp file for loadSettings() subprocess result');
        }
        $resultPathPhp = var_export($resultPath, true);

        // 🧪 Harness script: boots the REAL config.php (which defines+calls
        // loadSettings() at the bottom of its own init sequence — see
        // "🚀 INITIALIZE SYSTEM" in config.php), then re-reads $GLOBALS['settings']
        // for just the keys we care about and writes them as JSON to
        // $resultPath.
        $harnessScript = <<<PHP
<?php
putenv('DB_HOST={$dbHost}');
putenv('DB_USER={$dbUser}');
putenv('DB_PASS={$dbPass}');
putenv('DB_NAME={$dbName}');
putenv('ENCRYPTION_KEY={$encryptionKey}');

require '{$configPath}';

// config.php's own init sequence already calls loadSettings() once; call it
// again explicitly so this harness's behaviour doesn't silently depend on
// that implementation detail persisting.
loadSettings();

\$reportKeys = {$reportKeysPhp};
\$out = [];
foreach (\$reportKeys as \$key) {
    \$out[\$key] = \$GLOBALS['settings'][\$key] ?? '__MISSING__';
}

file_put_contents({$resultPathPhp}, json_encode(\$out));
PHP;

        $scriptPath = tempnam(sys_get_temp_dir(), 'signula_loadsettings_');
        if ($scriptPath === false) {
            @unlink($resultPath);
            throw new RuntimeException('Could not create temp file for loadSettings() subprocess harness');
        }
        file_put_contents($scriptPath, $harnessScript);

        try {
            $phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            // 🔇 Discard stdout/stderr entirely — the harness communicates its
            // result exclusively via $resultPath.
            shell_exec($phpBinary . ' ' . escapeshellarg($scriptPath) . ' > /dev/null 2>&1');

            $this->assertFileExists(
                $resultPath,
                'loadSettings() subprocess harness did not produce a result file at all — it likely fataled before reaching file_put_contents(). Re-run the harness script manually (without redirecting output) to see why.'
            );

            $rawResult = file_get_contents($resultPath);
        } finally {
            @unlink($scriptPath);
            @unlink($resultPath);
        }

        $decoded = json_decode((string)$rawResult, true);
        $this->assertIsArray(
            $decoded,
            'loadSettings() subprocess harness did not write valid JSON. Raw result file contents: ' . var_export($rawResult, true)
        );

        return $decoded;
    }

    // ========================================================================
    // ✅ RESILIENCE CONTRACT
    // ========================================================================

    /**
     * A blank/undecryptable isSensitive row must not blank out the OTHER,
     * non-sensitive settings loaded around it.
     *
     * Row layout mirrors the real bug scenario: a normal setting, then a
     * blank isSensitive placeholder (exactly what fresh installs seed for
     * unconfigured OAuth/payment/CAPTCHA credentials), then ANOTHER normal
     * setting positioned after it in insertion order. Before B-052, the
     * decrypt() throw for the blank row aborted loadSettings()'s foreach
     * entirely, so the "after" setting was silently lost; after B-052 it
     * loads normally.
     */
    public function testBlankSensitiveRowDoesNotAbortLoadingOtherSettings(): void
    {
        $beforeKey = self::KEY_PREFIX . 'before';
        $badSensitiveKey = self::KEY_PREFIX . 'bad_sensitive';
        $afterKey = self::KEY_PREFIX . 'after';

        // 📥 Insertion order matters: the bad row sits BETWEEN two normal
        // ones, so a query-order-independent scan still has to pass through
        // (or past) it to reach "after".
        $this->insertTestSetting($beforeKey, 'before-value', false);
        $this->insertTestSetting($badSensitiveKey, '', true); // 🈳 blank placeholder — undecryptable
        $this->insertTestSetting($afterKey, 'after-value', false);

        $loaded = $this->runLoadSettingsInSubprocess([$beforeKey, $badSensitiveKey, $afterKey]);

        $this->assertSame(
            'before-value',
            $loaded[$beforeKey] ?? null,
            'Setting inserted BEFORE the bad sensitive row should load normally'
        );

        // 🎯 This is the assertion that would have failed before B-052: the
        // decrypt() throw for $badSensitiveKey used to propagate out of
        // loadSettings()'s foreach and abort before ever reaching this row.
        $this->assertSame(
            'after-value',
            $loaded[$afterKey] ?? null,
            'B-052 regression: a blank isSensitive row must not blank out settings loaded after it'
        );

        // 🔓 The bad row itself must resolve to an empty string (not fatal,
        // not left as the raw undecrypted placeholder).
        $this->assertSame(
            '',
            $loaded[$badSensitiveKey] ?? null,
            'A blank/undecryptable isSensitive value should resolve to an empty string, not abort or leak raw data'
        );
    }
}
