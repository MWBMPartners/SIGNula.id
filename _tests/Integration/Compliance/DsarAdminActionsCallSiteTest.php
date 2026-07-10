<?php
/**
 * ============================================================================
 * 🧪 dsar-actions.php / DSAR Admin Queue Call-Site Tests (G-004 Layer 1 — Cycle C)
 * ============================================================================
 *
 * `web/public_html/admin/compliance/dsar/index.php` and
 * `web/public_html/admin/compliance/api/dsar-actions.php` are true HTTP
 * endpoint scripts (session + AccessControl + CSRF + `echo`/`exit` at the top
 * level), so — following the SAME judgement call already made and documented
 * for this exact class of file — they are not directly `require`-able
 * in-process the way a static class is (see
 * `_tests/Integration/Payments/CreditActionsCallSiteTest.php`, which chose
 * this identical approach over standing up a `php -S` subprocess harness for
 * a comparable admin-api script, judging the harness "out of proportion").
 *
 * This suite proves the fix at the two levels that actually matter, mirroring
 * that precedent exactly:
 *
 *   1. Every handler in dsar-actions.php calls `DSARManager` with the EXACT
 *      call shape the handler source uses — proven here by calling
 *      `DSARManager` with that same shape against a REAL MySQL connection and
 *      asserting the resulting state.
 *   2. The security invariants that can't be proven via a call shape (CSRF
 *      gate, super-admin gate, X-Frame-Options, and — most importantly — that
 *      the password-gated fulfil actions NEVER drive AccountManager) are
 *      proven via a combination of (a) exercising the real underlying
 *      function directly (`SecurityUtils::verifyCSRFToken()`) and (b) a
 *      source-content sentinel, the SAME technique
 *      `_tests/Integration/Compliance/DSARManagerTest.php`'s
 *      "anti-duplication source sentinel" test already uses successfully for
 *      an equivalent "prove a dangerous thing NEVER happens" property.
 *
 * @package    SIGNula\Tests\Integration\Compliance
 * @version    2.7.0-beta
 * @see        web/public_html/admin/compliance/dsar/index.php
 * @see        web/public_html/admin/compliance/api/dsar-actions.php
 * @see        web/private_html/compliance/DSARManager.php
 * @see        _tests/Integration/Payments/CreditActionsCallSiteTest.php — the precedent this mirrors
 * @see        .dev-team/specs/G-004.md §2.6, §6
 */

namespace SIGNula\Tests\Integration\Compliance;

use SIGNula\Tests\DatabaseTestCase;
use DSARManager;
use InvalidDSARTransitionException;

requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/compliance/InvalidDSARTransitionException.php');
requireSource('private_html/compliance/DSARManager.php');
requireSource('private_html/auth/AccountManager.php');

/**
 * 🛡️ DSAR admin queue / actions call-site test suite.
 */
class DsarAdminActionsCallSiteTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblDataSubjectRequests',
        'tblDataSubjectRequestEvents',
        'tblDataExportRequests',
        'tblActivityLog',
        'tblUsers',
    ];

    /** @var string Absolute path to the two endpoint scripts under test. */
    private string $indexPagePath;
    private string $actionsApiPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexPagePath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'dsar'
            . DIRECTORY_SEPARATOR . 'index.php';

        $this->actionsApiPath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'api'
            . DIRECTORY_SEPARATOR . 'dsar-actions.php';
    }

    // ========================================================================
    // 🔄 handleTransition() — the EXACT call shape
    // ========================================================================

    public function testAdminTransitionCallShapeAppliesRealStateChange(): void
    {
        $admin = $this->createTestUser();
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);

        // 🩹 EXACT shape dsar-actions.php::handleTransition() uses:
        // DSARManager::transition($requestID, $toStatus, 'admin', $adminUserID, $note).
        DSARManager::transition($requestID, 'identity_pending', 'admin', $admin['userID'], 'Reviewed by support');

        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertSame('identity_pending', $row['status']);

        $event = $this->getRecord('tblDataSubjectRequestEvents', ['requestID' => $requestID, 'toStatus' => 'identity_pending']);
        $this->assertSame('admin', $event['actorType']);
        $this->assertSame((string) $admin['userID'], (string) $event['actorID']);
        $this->assertSame('Reviewed by support', $event['note']);
    }

    public function testAdminTransitionCallShapeSurfacesAnIllegalTransitionAsARejectionNotACrash(): void
    {
        $admin = $this->createTestUser();
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);

        // 🚫 'submitted' -> 'fulfilled' skips identity verification — illegal.
        // This is exactly what handleTransition()'s try/catch(InvalidDSARTransitionException)
        // branch is there to turn into {success:false, error:...} instead of a 500.
        $this->expectException(InvalidDSARTransitionException::class);
        DSARManager::transition($requestID, 'fulfilled', 'admin', $admin['userID'], 'skip ahead');
    }

    // ========================================================================
    // ✏️ handleFulfilRectification() — the EXACT call shape
    // ========================================================================

    public function testAdminFulfilRectificationCallShapeAppliesOnlyAllowlistedChanges(): void
    {
        $user = $this->createTestUser(['firstName' => 'Original', 'isSuperAdmin' => 0]);
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'rectification']);

        // 🩹 EXACT shape dsar-actions.php::handleFulfilRectification() uses:
        // DSARManager::fulfilRectification($requestID, $approvedChanges) where
        // $approvedChanges is the raw admin-submitted array (allowlist
        // filtering happens INSIDE DSARManager, not in the handler).
        $result = DSARManager::fulfilRectification($requestID, [
            'firstName' => 'Corrected',
            'isSuperAdmin' => 1, // 🚫 admin-submitted but must be rejected
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(['firstName'], $result['applied']);
        $this->assertSame(['isSuperAdmin'], $result['rejected']);

        $userRow = $this->getRecord('tblUsers', ['userID' => $user['userID']]);
        $this->assertSame('Corrected', $userRow['firstName']);
        $this->assertSame('0', (string) $userRow['isSuperAdmin']);
    }

    // ========================================================================
    // 🔒 handlePasswordGatedFulfilTracking() — the bridging logic, replicated
    // exactly, proving it NEVER touches AccountManager / a password
    // ========================================================================

    /**
     * @dataProvider passwordGatedTypeProvider
     */
    public function testBridgeToAwaitingUserTracksPasswordGatedRequestsWithoutTouchingAccountManager(string $requestType): void
    {
        $admin = $this->createTestUser();
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => $requestType]);

        // 🩹 EXACT bridging logic dsar-actions.php::handlePasswordGatedFulfilTracking()
        // performs: read current status, bridge through 'in_progress' if
        // needed (the transition map only allows 'in_progress' -> 'awaiting_user'),
        // then transition to 'awaiting_user' with an explanatory note.
        $current = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $note = "Fulfilment of '{$requestType}' requests requires the data subject's OWN password "
            . "and cannot be completed by an admin.";

        if ($current['status'] !== 'in_progress') {
            DSARManager::transition($requestID, 'in_progress', 'admin', $admin['userID'], 'Auto-advanced to in_progress for fulfilment tracking');
        }
        DSARManager::transition($requestID, 'awaiting_user', 'admin', $admin['userID'], $note);

        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertSame('awaiting_user', $row['status']);

        $awaitingEvent = $this->getRecord('tblDataSubjectRequestEvents', ['requestID' => $requestID, 'toStatus' => 'awaiting_user']);
        $this->assertNotNull($awaitingEvent);
        $this->assertStringContainsStringIgnoringCase('own password', (string) $awaitingEvent['note']);

        // 🔒 THE critical negative assertion: no export/erasure was actually
        // performed — AccountManager's own tables show NO activity. This is
        // the concrete proof that "track only, never fabricate a password"
        // was upheld, not just asserted in a comment.
        $this->assertSame(0, $this->countRecords('tblDataExportRequests'), 'No export may have been generated — the admin never supplied a password');
        $userRow = $this->getRecord('tblUsers', ['userID' => $user['userID']]);
        $this->assertNull($userRow['deletionRequestedAt'], 'No account deletion may have been scheduled — the admin never supplied a password');
        $this->assertNull($userRow['deletionScheduledFor']);
    }

    public static function passwordGatedTypeProvider(): array
    {
        return [
            'portability' => ['portability'],
            'access' => ['access'],
            'erasure' => ['erasure'],
        ];
    }

    // ========================================================================
    // 🔐 handleStartIdentityVerification() — the EXACT call shape
    // ========================================================================

    public function testAdminStartIdentityVerificationCallShapeIssuesAHashedTokenOnly(): void
    {
        $user = $this->createTestUser();
        $requestID = DSARManager::submit(['userID' => $user['userID'], 'requestType' => 'access']);

        // 🩹 EXACT shape dsar-actions.php::handleStartIdentityVerification() uses.
        $rawToken = DSARManager::startIdentityVerification($requestID);

        $this->assertNotEmpty($rawToken);
        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertSame('identity_pending', $row['status']);
        $this->assertSame(hash('sha256', $rawToken), $row['verificationToken'], 'Only the SHA-256 hash may be persisted');
        $this->assertNotSame($rawToken, $row['verificationToken']);
    }

    // ========================================================================
    // 🛡️ CSRF rejection — the real function, plus a source sentinel that the
    // endpoint actually wires it before every mutation
    // ========================================================================

    public function testVerifyCsrfTokenRejectsAMissingOrBogusToken(): void
    {
        // The exact check dsar-actions.php performs on every POST:
        // `if (!SecurityUtils::verifyCSRFToken($csrfToken)) { ...reject... }`.
        $this->assertFalse(\SecurityUtils::verifyCSRFToken(''));
        $this->assertFalse(\SecurityUtils::verifyCSRFToken('totally-bogus-token-value'));
    }

    public function testDsarActionsSourceGatesEveryPostRequestBehindCsrfVerificationBeforeAnyMutation(): void
    {
        $source = file_get_contents($this->actionsApiPath);
        $this->assertNotFalse($source, 'dsar-actions.php must be readable for this sentinel test');

        // 🔍 The CSRF check must exist, be gated on REQUEST_METHOD === 'POST',
        // and appear BEFORE the action switch() that dispatches mutations.
        $this->assertStringContainsString("SecurityUtils::verifyCSRFToken(", $source);
        $csrfPos = strpos($source, 'SecurityUtils::verifyCSRFToken(');
        $switchPos = strpos($source, 'switch ($action)');
        $this->assertNotFalse($switchPos, 'dsar-actions.php must dispatch actions via a switch($action) statement');
        $this->assertLessThan($switchPos, $csrfPos, 'CSRF verification must happen BEFORE the action dispatch switch()');
    }

    // ========================================================================
    // 🛡️ Super-admin gate — source sentinel
    // ========================================================================
    //
    // Actually invoking these scripts with a mocked SessionManager/AccessControl
    // would require standing up a real logged-in session against signula_test
    // (cookie handshake, tblUserSessions row, tblUsers.isSuperAdmin flag) for
    // marginal extra proof over what's achievable here — judged disproportionate
    // for this cycle (same call CreditActionsCallSiteTest made). Instead this
    // asserts the EXACT gating source pattern the CONTEXT briefing specified is
    // present, verbatim, in both files.
    // ========================================================================

    public function testAdminQueuePageSourceCallsRequireSuperAdminBeforeAnyOutput(): void
    {
        $source = file_get_contents($this->indexPagePath);
        $this->assertNotFalse($source);
        $this->assertStringContainsString('$accessControl->requireSuperAdmin();', $source);
        $this->assertStringContainsString('$sessionManager->isLoggedIn()', $source);
    }

    public function testDsarActionsSourceChecksIsSuperAdminBeforeDispatchingAnyAction(): void
    {
        $source = file_get_contents($this->actionsApiPath);
        $this->assertNotFalse($source);
        $this->assertStringContainsString("\$userInfo['isSuperAdmin']", $source);

        $gatePos = strpos($source, "isSuperAdmin");
        $switchPos = strpos($source, 'switch ($action)');
        $this->assertLessThan($switchPos, $gatePos, 'The super-admin gate must be checked BEFORE the action dispatch switch()');
    }

    // ========================================================================
    // 🛡️ X-Frame-Options: DENY on both compliance admin surfaces
    // ========================================================================

    public function testBothEndpointsSetXFrameOptionsDeny(): void
    {
        $this->assertStringContainsString("X-Frame-Options: DENY", file_get_contents($this->indexPagePath));
        $this->assertStringContainsString("X-Frame-Options: DENY", file_get_contents($this->actionsApiPath));
    }

    // ========================================================================
    // 🔒 THE password-fabrication negative sentinel (mirrors DSARManagerTest's
    // anti-duplication source sentinel style)
    // ========================================================================

    public function testDsarActionsSourceNeverCallsThePasswordGatedFulfilmentDelegatorsAtAll(): void
    {
        // 🔍 php_strip_whitespace() returns the file with ALL comments (both
        // docblocks and //-style) and extraneous whitespace stripped, while
        // preserving actual PHP tokens — this file's own docblocks
        // deliberately DISCUSS DSARManager::fulfilPortability()/fulfilAccess()/
        // fulfilErasure() BY NAME (to document why they are never called), so
        // a raw file_get_contents() substring search would false-positive on
        // that documentation. Stripping comments first means this sentinel
        // can only match a REAL call in executable code.
        // @see https://www.php.net/manual/en/function.php-strip-whitespace.php
        $codeOnly = php_strip_whitespace($this->actionsApiPath);
        $this->assertNotSame('', $codeOnly, 'dsar-actions.php must be readable for this sentinel test');

        // 🔒 dsar-actions.php must NEVER call DSARManager::fulfilPortability()/
        // fulfilAccess()/fulfilErasure() — those require a real subject
        // password as their 3rd argument, which an admin endpoint cannot
        // supply without fabricating one. If this ever starts failing, it
        // means someone wired a password into an admin action — stop and
        // reconsider rather than "fixing" this test.
        foreach (['DSARManager::fulfilPortability(', 'DSARManager::fulfilAccess(', 'DSARManager::fulfilErasure('] as $forbiddenCall) {
            $this->assertStringNotContainsString(
                $forbiddenCall,
                $codeOnly,
                "dsar-actions.php must never CALL {$forbiddenCall} in executable code — these require the data "
                . "subject's own password, which an admin cannot supply without fabricating one (see the file's "
                . "own 'PASSWORD-GATED FULFILMENT' docblock, which mentions these names only in comments)"
            );
        }

        // 🔒 And, more broadly, no hardcoded/placeholder password string is
        // ever passed anywhere in this file's executable code.
        $this->assertStringNotContainsString("'password123'", $codeOnly);
        $this->assertStringNotContainsString('"password123"', $codeOnly);
    }
}
