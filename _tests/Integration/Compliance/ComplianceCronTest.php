<?php
/**
 * ============================================================================
 * 🧪 cron/compliance.php Tests (G-004 Layer 4a §5.3 — retention + auto-purge cron)
 * ============================================================================
 *
 * `web/public_html/cron/compliance.php` is a true HTTP endpoint script
 * (header()/echo/exit at the top level, no session/CSRF gate but a
 * token-auth gate instead). Rather than standing up a `php -S` subprocess
 * harness — judged out of proportion for this cycle, matching the identical
 * precedent already used for regime-actions.php/dsar-actions.php/
 * credit-actions.php — this suite proves things at the levels that actually
 * matter:
 *
 *   1. GENUINE functional tests against the REAL, unmodified token-auth
 *      helper functions (complianceCronExtractProvidedToken() /
 *      complianceCronTokenIsValid()) — made possible by compliance.php's own
 *      SIGNULA_CRON_COMPLIANCE_TEST_ONLY test seam (see that file's docblock
 *      above the helpers), which returns immediately after declaring the two
 *      pure functions but BEFORE the live bootstrap/DB/settings-load code
 *      runs. PHP hoists top-level function declarations at compile time
 *      regardless of that early return, so both functions become callable
 *      here — this is the SAME production code a live request executes, not
 *      a copy or a re-implementation. This is what makes "a missing/wrong
 *      token is rejected; a correct token passes" a REAL test rather than a
 *      re-implementation of the logic under test.
 *   2. THE GAP-CLOSER wiring: a source-content sentinel (mirrors
 *      DsarAdminActionsCallSiteTest's/DSARManagerTest's technique) proving
 *      compliance.php's executable code actually CALLS
 *      AccountManager::processScheduledDeletions() and
 *      AccountManager::cleanupExpiredExports() — the two methods that have
 *      existed since the GDPR erasure work but were never wired to anything
 *      until this cycle.
 *   3. The EXACT call shape compliance.php's gap-closer step uses
 *      (`AccountManager::processScheduledDeletions()`, no arguments) is
 *      exercised directly against a REAL scheduled-for-deletion test user on
 *      a real MySQL connection, proving that call shape actually processes
 *      the account — the concrete "did the wiring do something" proof to
 *      pair with the source sentinel above.
 *   4. The 401/503 gate logic (getSetting()-based decrypt-then-compare, fail
 *      closed on an unconfigured token) is proven via the same pure helpers
 *      plus a docblock/source sentinel confirming compliance.php reads the
 *      token via getSetting() (decrypting) and NEVER via a raw
 *      `SELECT settingValue` (which would compare against ciphertext).
 *
 * @package    SIGNula\Tests\Integration\Compliance
 * @version    2.7.0-beta
 * @see        web/public_html/cron/compliance.php
 * @see        web/private_html/auth/AccountManager.php
 * @see        web/private_html/compliance/RetentionManager.php
 * @see        web/public_html/cron/billing.php — the sibling cron endpoint compliance.php mirrors
 * @see        _tests/Integration/Compliance/DsarAdminActionsCallSiteTest.php — the call-site-sentinel precedent this mirrors
 * @see        _tests/Integration/Compliance/RegimeAdminActionsTest.php — the test-seam precedent this mirrors
 * @see        _database/migrations/043_retention_policies.sql
 * @see        .dev-team/specs/G-004.md §5.3
 */

namespace SIGNula\Tests\Integration\Compliance;

use SIGNula\Tests\DatabaseTestCase;
use AccountManager;

requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/auth/AccountManager.php');

// 🧪 Trip compliance.php's test seam BEFORE requiring it — this defines
// complianceCronExtractProvidedToken()/complianceCronTokenIsValid() as REAL,
// callable global symbols, while skipping the live bootstrap/DB/settings-load/
// exit() code a real HTTP request would run (which would abort the entire
// PHPUnit process if it ran here). See compliance.php's own docblock above
// the two functions for the full rationale — production requests never
// define this constant, so it is inert outside this exact test process.
if (!defined('SIGNULA_CRON_COMPLIANCE_TEST_ONLY')) {
    define('SIGNULA_CRON_COMPLIANCE_TEST_ONLY', true);
}
require_once \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
    . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'compliance.php';

/**
 * 🛡️ Compliance cron test suite.
 */
class ComplianceCronTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblActivityLog',
        'tblUsers',
    ];

    /** @var string Absolute path to the endpoint script under test. */
    private string $cronScriptPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cronScriptPath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'compliance.php';
    }

    // ========================================================================
    // 🔐 Token-extraction helper — the REAL function, loaded via the test seam
    // ========================================================================

    public function testExtractProvidedTokenPrefersGetParameterOverHeader(): void
    {
        $token = \complianceCronExtractProvidedToken(
            ['token' => 'from-get-param'],
            ['HTTP_AUTHORIZATION' => 'Bearer from-header']
        );

        $this->assertSame('from-get-param', $token);
    }

    public function testExtractProvidedTokenFallsBackToAuthorizationBearerHeader(): void
    {
        $token = \complianceCronExtractProvidedToken(
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer my-secret-bearer-token']
        );

        $this->assertSame('my-secret-bearer-token', $token);
    }

    public function testExtractProvidedTokenFallsBackToRedirectAuthorizationHeader(): void
    {
        // 🔎 mod_php/CGI environments sometimes only expose this variant.
        $token = \complianceCronExtractProvidedToken(
            [],
            ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirected-token']
        );

        $this->assertSame('redirected-token', $token);
    }

    public function testExtractProvidedTokenReturnsEmptyStringWhenNoneSupplied(): void
    {
        $this->assertSame('', \complianceCronExtractProvidedToken([], []));
    }

    public function testExtractProvidedTokenIgnoresANonBearerAuthorizationHeader(): void
    {
        $token = \complianceCronExtractProvidedToken([], ['HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz']);

        $this->assertSame('', $token);
    }

    // ========================================================================
    // 🔒 Token gate — a missing/wrong token is rejected; a correct token passes
    // ========================================================================

    public function testTokenIsValidRejectsAnUnconfiguredExpectedToken(): void
    {
        // 🛡️ Fail CLOSED — an empty expected token (the shipped default,
        // migration 043) must never "match" anything, including an equally
        // empty provided token.
        $this->assertFalse(\complianceCronTokenIsValid('', ''));
        $this->assertFalse(\complianceCronTokenIsValid('', 'anything'));
    }

    public function testTokenIsValidRejectsAWrongToken(): void
    {
        $this->assertFalse(\complianceCronTokenIsValid('correct-token-abc123', 'wrong-token'));
    }

    public function testTokenIsValidRejectsAnEmptyProvidedTokenAgainstARealExpectedToken(): void
    {
        $this->assertFalse(\complianceCronTokenIsValid('correct-token-abc123', ''));
    }

    public function testTokenIsValidAcceptsTheExactCorrectToken(): void
    {
        $this->assertTrue(\complianceCronTokenIsValid('correct-token-abc123', 'correct-token-abc123'));
    }

    public function testTokenIsValidIsCaseSensitive(): void
    {
        $this->assertFalse(\complianceCronTokenIsValid('CorrectToken', 'correcttoken'));
    }

    // ========================================================================
    // 🔍 Source sentinels — the getSetting()-decrypt (never raw SELECT) contract
    // ========================================================================

    public function testCronReadsTheTokenViaGetSettingNeverARawSelect(): void
    {
        $codeOnly = php_strip_whitespace($this->cronScriptPath);
        $this->assertNotSame('', $codeOnly, 'compliance.php must be readable for this sentinel test.');

        $this->assertStringContainsString(
            "getSetting('compliance.cron.secret_token'",
            $codeOnly,
            'The expected token MUST be read via getSetting() so the isSensitive value is decrypted.'
        );

        // 🔒 It must NEVER read the token via a raw SELECT settingValue —
        // that would compare a caller's plaintext token against AES
        // ciphertext and always fail. (cron/billing.php uses this raw-SELECT
        // pattern for its NON-sensitive token; compliance.php's token IS
        // sensitive and must NOT copy that pattern.)
        $this->assertStringNotContainsString(
            'SELECT settingValue',
            $codeOnly,
            'compliance.php must never read its (isSensitive/encrypted) token via a raw SELECT settingValue.'
        );
    }

    public function testCronUsesHashEqualsForTheTokenComparison(): void
    {
        $codeOnly = php_strip_whitespace($this->cronScriptPath);
        $this->assertStringContainsString('hash_equals(', $codeOnly);
    }

    public function testCronRespondsWith401OnAnInvalidToken(): void
    {
        $codeOnly = php_strip_whitespace($this->cronScriptPath);
        $this->assertStringContainsString('http_response_code(401)', $codeOnly);
    }

    public function testCronRespondsWith503WhenTokenIsUnconfigured(): void
    {
        $codeOnly = php_strip_whitespace($this->cronScriptPath);
        $this->assertStringContainsString('http_response_code(503)', $codeOnly);
    }

    // ========================================================================
    // 🔍 THE GAP-CLOSER wiring — source sentinel
    // ========================================================================

    public function testCronSourceActuallyCallsProcessScheduledDeletions(): void
    {
        $codeOnly = php_strip_whitespace($this->cronScriptPath);
        $this->assertStringContainsString(
            'AccountManager::processScheduledDeletions(',
            $codeOnly,
            'compliance.php must call the pre-existing but previously-never-wired AccountManager::processScheduledDeletions().'
        );
    }

    public function testCronSourceActuallyCallsCleanupExpiredExports(): void
    {
        $codeOnly = php_strip_whitespace($this->cronScriptPath);
        $this->assertStringContainsString(
            'AccountManager::cleanupExpiredExports(',
            $codeOnly,
            'compliance.php must call the pre-existing but previously-never-wired AccountManager::cleanupExpiredExports().'
        );
    }

    public function testCronSourceCallsRetentionManagerRunDuePolicies(): void
    {
        $codeOnly = php_strip_whitespace($this->cronScriptPath);
        $this->assertStringContainsString('RetentionManager::runDuePolicies(', $codeOnly);
    }

    public function testCronSourceExpiresDsarTokensViaTheGuardedStateMachineNotARawUpdate(): void
    {
        $codeOnly = php_strip_whitespace($this->cronScriptPath);

        // 🔒 Must delegate to DSARManager::transition() (validated, logged,
        // audit-trailed) rather than a raw duplicate UPDATE against
        // tblDataSubjectRequests.
        $this->assertStringContainsString("DSARManager::transition(", $codeOnly);
        $this->assertStringNotContainsString(
            "UPDATE tblDataSubjectRequests SET status",
            $codeOnly,
            'compliance.php must never raw-UPDATE DSAR status directly — it must delegate to DSARManager::transition().'
        );
    }

    // ========================================================================
    // ✅ THE EXACT call shape — a scheduled-for-deletion user is actually processed
    // ========================================================================

    public function testProcessScheduledDeletionsCallShapeActuallyProcessesAnOverdueUser(): void
    {
        // 🩹 EXACT shape compliance.php's gap-closer step uses:
        // `AccountManager::processScheduledDeletions()` — no arguments.
        $user = $this->createTestUser();

        // ⏰ Schedule this user's deletion for the past — i.e. overdue,
        // exactly what processScheduledDeletions() looks for.
        \Database::query(
            "UPDATE tblUsers SET deletionRequestedAt = ?, deletionScheduledFor = ? WHERE userID = ?",
            [
                (new \DateTime('-31 days'))->format('Y-m-d H:i:s'),
                (new \DateTime('-1 day'))->format('Y-m-d H:i:s'),
                $user['userID'],
            ],
            'ssi'
        );

        $result = AccountManager::processScheduledDeletions();

        $this->assertGreaterThanOrEqual(1, $result['processed'], 'The overdue-scheduled user must have been picked up.');
        $this->assertGreaterThanOrEqual(1, $result['deleted']);
        $this->assertSame(0, $result['errors']);

        // 🔒 Confirms permanentlyDeleteAccount()'s soft-delete actually ran —
        // this is the concrete "the wiring did something real" proof.
        $row = $this->getRecord('tblUsers', ['userID' => $user['userID']]);
        $this->assertNotNull($row['deletedAt'], 'The user must be soft-deleted after processing.');
        $this->assertSame('deleted', $row['accountStatus']);
        $this->assertNull($row['deletionScheduledFor'], 'deletionScheduledFor must be cleared once processed.');
    }

    public function testProcessScheduledDeletionsCallShapeLeavesAFutureScheduledUserUntouched(): void
    {
        $user = $this->createTestUser();

        // ⏰ Scheduled for the FUTURE — must NOT be processed yet.
        \Database::query(
            "UPDATE tblUsers SET deletionRequestedAt = NOW(), deletionScheduledFor = ? WHERE userID = ?",
            [(new \DateTime('+10 days'))->format('Y-m-d H:i:s'), $user['userID']],
            'si'
        );

        AccountManager::processScheduledDeletions();

        $row = $this->getRecord('tblUsers', ['userID' => $user['userID']]);
        $this->assertNull($row['deletedAt'], 'A user scheduled for a FUTURE date must not be deleted yet.');
        $this->assertNotSame('deleted', $row['accountStatus']);
    }
}
