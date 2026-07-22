<?php
/**
 * ============================================================================
 * 🧪 Login-Flow Re-Consent Wiring Call-Site Tests (G-004 Layer 2)
 * ============================================================================
 *
 * web/public_html/login.php and web/public_html/mfa/verify.php are true HTTP
 * endpoint scripts (session + Auth + full config bootstrap at the top
 * level), so — following the SAME judgement call already made and
 * documented for this exact class of file — they are not directly
 * require-able in-process (see
 * _tests/Integration/Compliance/DsarAdminActionsCallSiteTest.php, which
 * chose this approach over a `php -S` subprocess harness for a comparable
 * page).
 *
 * This suite proves the re-consent wiring at the two levels that matter:
 *   1. ConsentCategoryService::maybeReconsentRedirect() (the underlying
 *      gate+check+redirect-URL logic) is proven directly and DB-bound in
 *      _tests/Integration/Compliance/ConsentCategoryServiceTest.php.
 *   2. THIS suite proves login.php and mfa/verify.php actually CALL it, and
 *      — critically for "MUST keep the login test baseline green" — that
 *      the call sits AFTER the MFA/email-verification gates (never
 *      mid-MFA), so a reviewer/future editor can't silently move it earlier
 *      without this test catching it.
 *
 * @package    SIGNula\Tests\Integration\Compliance
 * @version    2.7.0-beta
 * @see        web/public_html/login.php
 * @see        web/public_html/mfa/verify.php
 * @see        web/private_html/compliance/ConsentCategoryService.php
 * @see        _tests/Integration/Compliance/DsarAdminActionsCallSiteTest.php — the precedent this mirrors
 * @see        .dev-team/specs/G-004.md §3.2
 */

namespace SIGNula\Tests\Integration\Compliance;

use SIGNula\Tests\TestCase;

/**
 * 🔄 Re-consent gate wiring call-site test suite.
 */
class ConsentSurfaceCallSiteTest extends TestCase
{
    private function loginPhpSource(): string
    {
        $path = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'login.php';
        $source = file_get_contents($path);
        $this->assertNotFalse($source, 'login.php must be readable for this sentinel test');

        return $source;
    }

    private function mfaVerifyPhpSource(): string
    {
        $path = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'mfa' . DIRECTORY_SEPARATOR . 'verify.php';
        $source = file_get_contents($path);
        $this->assertNotFalse($source, 'mfa/verify.php must be readable for this sentinel test');

        return $source;
    }

    // ========================================================================
    // 🔐 login.php
    // ========================================================================

    public function testLoginPhpCallsMaybeReconsentRedirectAfterTheMfaAndEmailVerificationChecks(): void
    {
        $source = $this->loginPhpSource();

        $mfaCheckOffset = strpos($source, "if (\$result['requiresMFA'])");
        $emailVerificationOffset = strpos($source, 'Auth::requiresEmailVerification()');
        $reconsentCallOffset = strpos($source, 'ConsentCategoryService::maybeReconsentRedirect(');

        $this->assertNotFalse($mfaCheckOffset, 'login.php must still gate on requiresMFA');
        $this->assertNotFalse($emailVerificationOffset, 'login.php must still gate on email verification');
        $this->assertNotFalse($reconsentCallOffset, 'login.php must call ConsentCategoryService::maybeReconsentRedirect()');

        $this->assertGreaterThan($mfaCheckOffset, $reconsentCallOffset, 'The re-consent call must be positioned AFTER the requiresMFA check — never mid-MFA');
        $this->assertGreaterThan($emailVerificationOffset, $reconsentCallOffset, 'The re-consent call must be positioned AFTER the email-verification check');
    }

    public function testLoginPhpPassesTheFinalRedirectTargetAndFallsBackToItWhenNoReconsentIsNeeded(): void
    {
        $source = $this->loginPhpSource();

        // 🎯 EXACT call shape: redirect($reconsentRedirect ?? $redirectTo) —
        // proves a null return from the gate (enforcement off / user current)
        // falls straight through to the ORIGINAL intended destination, never
        // a hardcoded page.
        $this->assertStringContainsString('redirect($reconsentRedirect ?? $redirectTo);', $source);
    }

    public function testLoginPhpDoesNotCallTheReconsentGateInsideTheMfaRequiredBranch(): void
    {
        $source = $this->loginPhpSource();

        $mfaBranchStart = strpos($source, "if (\$result['requiresMFA']) {");
        $mfaBranchElseStart = strpos($source, '} else {', $mfaBranchStart);
        $this->assertNotFalse($mfaBranchStart);
        $this->assertNotFalse($mfaBranchElseStart);

        $mfaBranchBody = substr($source, $mfaBranchStart, $mfaBranchElseStart - $mfaBranchStart);
        $this->assertStringNotContainsString(
            'ConsentCategoryService',
            $mfaBranchBody,
            'The requiresMFA branch (redirect to /mfa/verify, pending 2nd factor) must NEVER touch the re-consent gate — the user is not yet fully authenticated'
        );
    }

    // ========================================================================
    // 🔑 mfa/verify.php
    // ========================================================================

    public function testMfaVerifyPhpCallsMaybeReconsentRedirectOnlyAfterLoginOAuthSucceeds(): void
    {
        $source = $this->mfaVerifyPhpSource();

        $loginOAuthOffset = strpos($source, 'Auth::loginOAuth($userID, $rememberMe)');
        $reconsentCallOffset = strpos($source, 'ConsentCategoryService::maybeReconsentRedirect(');

        $this->assertNotFalse($loginOAuthOffset, 'mfa/verify.php must still complete login via Auth::loginOAuth()');
        $this->assertNotFalse($reconsentCallOffset, 'mfa/verify.php must call ConsentCategoryService::maybeReconsentRedirect()');
        $this->assertGreaterThan($loginOAuthOffset, $reconsentCallOffset, 'The re-consent call must come AFTER Auth::loginOAuth() has succeeded — never mid-MFA');
    }

    public function testMfaVerifyPhpFallsBackToTheStoredRedirectWhenNoReconsentIsNeeded(): void
    {
        $source = $this->mfaVerifyPhpSource();

        $this->assertStringContainsString('redirect($reconsentRedirect ?? $redirect);', $source);
    }

    public function testMfaVerifyPhpDoesNotReferenceTheReconsentGateInTheVerifyCodeFailureBranch(): void
    {
        $source = $this->mfaVerifyPhpSource();

        // 🔎 The 'Invalid verification code' failure branch must never be
        // anywhere near the re-consent gate — confirms the gate is scoped
        // to the SUCCESS path only, via source proximity: the ConsentCategoryService
        // reference must appear BEFORE the failure message in the file.
        $reconsentCallOffset = strpos($source, 'ConsentCategoryService::maybeReconsentRedirect(');
        $failureMessageOffset = strpos($source, "'Invalid verification code. Please try again.'");

        $this->assertNotFalse($reconsentCallOffset);
        $this->assertNotFalse($failureMessageOffset);
        $this->assertLessThan($failureMessageOffset, $reconsentCallOffset, 'The re-consent call belongs to the SUCCESS branch, which precedes the failure branch in source order');
    }

    // ========================================================================
    // ⚙️ Migration 041 default — the gate ships OFF
    // ========================================================================

    public function testMigration041SeedsReconsentEnforceAsOff(): void
    {
        $migrationPath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . '_database' . DIRECTORY_SEPARATOR . 'migrations'
            . DIRECTORY_SEPARATOR . '041_consent_categories_and_policy_versions.sql';
        $sql = file_get_contents($migrationPath);
        $this->assertNotFalse($sql);

        $this->assertMatchesRegularExpression(
            "/consent\\.reconsent\\.enforce', '0'/",
            $sql,
            'consent.reconsent.enforce MUST seed as 0/OFF so existing login integration tests stay green'
        );
    }
}
