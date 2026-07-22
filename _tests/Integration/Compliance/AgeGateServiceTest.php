<?php
/**
 * ============================================================================
 * 🧪 AgeGateService Integration Tests (G-004 Layer 4b §5.4)
 * ============================================================================
 *
 * DB-bound coverage: thresholdAge()'s RegimeResolver delegation, and — the
 * headline of this suite — startParentalConsent()/verifyParentalConsent()'s
 * REAL INSERT/UPDATE round trip against `tblParentalConsents`, proving the
 * SHA-256-hash-only storage contract with genuine database rows (not a
 * source sentinel). Also includes a source-sentinel proving register.php's
 * signup hook is wired to this class in the order the spec requires.
 *
 * @package    SIGNula\Tests\Integration\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/AgeGateService.php
 * @see        web/public_html/register.php
 * @see        _database/migrations/044_breach_ropa_agegate.sql
 * @see        .dev-team/specs/G-004.md §5.4
 */

namespace SIGNula\Tests\Integration\Compliance;

use SIGNula\Tests\DatabaseTestCase;
use AgeGateService;

requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/compliance/RegimeResolver.php');
requireSource('private_html/compliance/AgeGateService.php');

/**
 * 🚼 AgeGateService DB-bound test suite.
 */
class AgeGateServiceTest extends DatabaseTestCase
{
    /** @var array<int,int> tblParentalConsents.consentID rows this test created. */
    private array $consentIDsToCleanUp = [];

    protected function tearDown(): void
    {
        // 🧹 Explicit cleanup via the REAL \Database:: connection (the same
        // one AgeGateService itself writes through) — mirrors
        // RegimeAdminActionsTest's "dual-connection" cleanup discipline:
        // $this->db's transaction rollback has no effect on the separate
        // \Database:: singleton connection these rows were written through.
        foreach ($this->consentIDsToCleanUp as $consentID) {
            \Database::query("DELETE FROM tblParentalConsents WHERE consentID = ?", [$consentID], 'i');
        }

        parent::tearDown();
    }

    // ========================================================================
    // 📐 thresholdAge() — RegimeResolver delegation, shipped-state default
    // ========================================================================

    public function testThresholdAgeReturnsRegimeResolversShippedStateDefault(): void
    {
        // 🌍 Shipped state: every migration-042 regime is isActive=0, so
        // RegimeResolver::minAgeFor(null) resolves to its own configured
        // most-protective default. AgeGateService::thresholdAge() is a thin
        // pass-through — this proves it is genuinely delegating, not
        // hardcoding a number itself.
        $this->assertSame(\RegimeResolver::minAgeFor(null), AgeGateService::thresholdAge());
        $this->assertGreaterThan(0, AgeGateService::thresholdAge());
    }

    // ========================================================================
    // 🔐 THE headline test — parental token is stored ONLY as a SHA-256 hash
    // ========================================================================

    public function testStartParentalConsentStoresOnlyTheShA256HashNeverTheRawToken(): void
    {
        $child = $this->createTestUser();

        $rawToken = AgeGateService::startParentalConsent((int) $child['userID'], 'parent+test@example.com');

        $this->assertNotEmpty($rawToken);

        $row = \Database::fetchOne(
            "SELECT * FROM tblParentalConsents WHERE childUserID = ? ORDER BY consentID DESC LIMIT 1",
            [$child['userID']],
            'i'
        );
        $this->assertNotNull($row, 'startParentalConsent() must persist a tblParentalConsents row');
        $this->consentIDsToCleanUp[] = (int) $row['consentID'];

        $this->assertSame('pending', $row['status']);
        $this->assertSame('parent+test@example.com', $row['parentEmail']);
        $this->assertSame('email_link', $row['method']);

        // 🔒 THE crux assertion: the stored value is the HASH, never the raw token.
        $this->assertSame(hash('sha256', $rawToken), $row['verificationToken']);
        $this->assertNotSame($rawToken, $row['verificationToken']);
        $this->assertSame(64, strlen($row['verificationToken']), 'A SHA-256 hex digest is always 64 characters');
    }

    public function testStartParentalConsentRejectsAnInvalidParentEmail(): void
    {
        $child = $this->createTestUser();

        $this->expectException(\InvalidArgumentException::class);
        AgeGateService::startParentalConsent((int) $child['userID'], 'not-an-email');
    }

    // ========================================================================
    // ✅ verifyParentalConsent() — succeeds with the raw token, fails with a wrong one
    // ========================================================================

    public function testVerifyParentalConsentSucceedsWithTheRawTokenAndFailsWithAWrongOne(): void
    {
        $child = $this->createTestUser();
        $rawToken = AgeGateService::startParentalConsent((int) $child['userID'], 'parent2@example.com');

        $row = \Database::fetchOne(
            "SELECT consentID FROM tblParentalConsents WHERE childUserID = ? ORDER BY consentID DESC LIMIT 1",
            [$child['userID']],
            'i'
        );
        $consentID = (int) $row['consentID'];
        $this->consentIDsToCleanUp[] = $consentID;

        // 🚫 A wrong token must fail, and must NOT flip status.
        $this->assertFalse(AgeGateService::verifyParentalConsent($consentID, 'totally-wrong-token'));
        $stillPending = \Database::fetchOne("SELECT status FROM tblParentalConsents WHERE consentID = ?", [$consentID], 'i');
        $this->assertSame('pending', $stillPending['status']);

        // ✅ The real raw token succeeds and flips status -> verified.
        $this->assertTrue(AgeGateService::verifyParentalConsent($consentID, $rawToken));
        $verified = \Database::fetchOne("SELECT status, verifiedAt FROM tblParentalConsents WHERE consentID = ?", [$consentID], 'i');
        $this->assertSame('verified', $verified['status']);
        $this->assertNotNull($verified['verifiedAt']);

        // 🔁 Re-using the same (now non-pending) token must fail — no
        // onward transition from 'verified'.
        $this->assertFalse(AgeGateService::verifyParentalConsent($consentID, $rawToken));
    }

    public function testVerifyParentalConsentFailsClosedWhenTheTokenHasExpired(): void
    {
        // 🕐 Manually insert a row backdated well past the default 72h TTL
        // (migration 044's compliance.parental_consent.token_ttl_hours),
        // bypassing startParentalConsent() so createdAt can be controlled.
        $rawToken = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $rawToken);
        $backdatedCreatedAt = date('Y-m-d H:i:s', strtotime('-100 hours'));

        $consentID = \Database::insert(
            "INSERT INTO tblParentalConsents (childUserID, parentEmail, status, verificationToken, method, createdAt)
             VALUES (?, ?, 'pending', ?, 'email_link', ?)",
            [999999, 'expired-test@example.com', $hashedToken, $backdatedCreatedAt],
            'isss'
        );
        $this->consentIDsToCleanUp[] = $consentID;

        $this->assertFalse(AgeGateService::verifyParentalConsent($consentID, $rawToken));

        $row = \Database::fetchOne("SELECT status FROM tblParentalConsents WHERE consentID = ?", [$consentID], 'i');
        $this->assertSame('pending', $row['status'], 'An expired verification must never flip status');
    }

    public function testVerifyParentalConsentFailsForANonexistentConsentID(): void
    {
        $this->assertFalse(AgeGateService::verifyParentalConsent(999999999, 'anything'));
    }

    // ========================================================================
    // 👶 "Underage routes to the parental scaffold" — the acceptance criterion
    // ========================================================================

    /**
     * 🩹 Mirrors the EXACT call shape register.php's age-gate hook uses once
     * an underage DOB + valid parent email are submitted: create the
     * (restricted) account, then start parental consent against it. Proves
     * end-to-end that the flow lands a `tblParentalConsents` row with
     * `status = 'pending'` for the new child account.
     */
    public function testUnderageSignupFlowCreatesAPendingParentalConsentRow(): void
    {
        setTestSetting('compliance.age_gate.enabled', true);
        $this->assertTrue(AgeGateService::isEnabled());

        $dob = (new \DateTimeImmutable('today'))->modify('-10 years')->format('Y-m-d');
        $threshold = AgeGateService::thresholdAge();
        $this->assertTrue(AgeGateService::isUnderage($dob, $threshold), 'A 10-year-old must be underage against any realistic threshold');

        // 🏗️ The account register.php's hook would have already created via
        // Auth::register() — represented here by a plain fixture user (Auth's
        // own registration flow is Auth's test domain, not this class's).
        $child = $this->createTestUser();

        $rawToken = AgeGateService::startParentalConsent((int) $child['userID'], 'routed-parent@example.com');
        $this->assertNotEmpty($rawToken);

        $row = \Database::fetchOne(
            "SELECT * FROM tblParentalConsents WHERE childUserID = ? ORDER BY consentID DESC LIMIT 1",
            [$child['userID']],
            'i'
        );
        $this->assertNotNull($row);
        $this->consentIDsToCleanUp[] = (int) $row['consentID'];
        $this->assertSame('pending', $row['status']);
    }

    // ========================================================================
    // 🛡️ register.php wiring sentinel — proves the hook is actually present
    // ========================================================================

    public function testRegisterPhpSourceCallsAgeGateServiceBeforeTheNormalRegistrationPath(): void
    {
        $registerPath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'register.php';
        $source = file_get_contents($registerPath);
        $this->assertNotFalse($source, 'register.php must be readable for this sentinel test');

        $this->assertStringContainsString('AgeGateService::isEnabled()', $source);
        $this->assertStringContainsString('AgeGateService::isUnderage(', $source);
        $this->assertStringContainsString('AgeGateService::startParentalConsent(', $source);

        // 🔒 The isEnabled() gate check must appear BEFORE the first
        // Auth::register( call in the file — proving the age-gate decision
        // is made before any account is normally created.
        $isEnabledPos = strpos($source, 'AgeGateService::isEnabled()');
        $firstAuthRegisterPos = strpos($source, 'Auth::register(');
        $this->assertNotFalse($firstAuthRegisterPos, 'register.php must still call Auth::register() somewhere');
        $this->assertLessThan($firstAuthRegisterPos, $isEnabledPos, 'AgeGateService::isEnabled() must be checked before Auth::register() is ever called');
    }
}
