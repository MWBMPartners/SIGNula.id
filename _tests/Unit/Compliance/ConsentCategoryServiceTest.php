<?php
/**
 * ============================================================================
 * 🧪 ConsentCategoryService Unit Tests (G-004 Layer 2 — Consent-Management Surface)
 * ============================================================================
 *
 * Covers the PURE, DB-free logic inside ConsentCategoryService:
 * isTruthyInput()'s checkbox/JSON normalisation, issueOrGetVisitorID()'s
 * cookie-stability behaviour (no DB calls — reads/writes $_COOKIE only),
 * and the "cheap guards run BEFORE any DB touch" contract of both
 * maybeReconsentRedirect() (settings-only short-circuit when
 * consent.reconsent.enforce is off) and honorGpcSignalIfPresent() (header/
 * setting/identity guards that must all pass before ConsentManager is ever
 * required or called).
 *
 * getActiveCategories()/getCurrentPolicyVersion()/recordBannerChoices()/
 * userNeedsReconsent()'s DB-bound branch/honorGpcSignalIfPresent()'s
 * DB-bound branch are NOT exercised here — they all call into
 * Database::query()/fetchOne()/fetchAll() (directly or via ConsentManager),
 * and `Database` is never loaded in the Unit suite (matches the established
 * ConsentManagerTest/DSARManagerTest split). Those round-trips live in
 * _tests/Integration/Compliance/ConsentCategoryServiceTest.php.
 *
 * @package    SIGNula\Tests\Unit\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/ConsentCategoryService.php
 * @see        _database/migrations/041_consent_categories_and_policy_versions.sql
 * @see        .dev-team/specs/G-004.md §3, §8
 */

namespace SIGNula\Tests\Unit\Compliance;

use SIGNula\Tests\TestCase;
use ConsentCategoryService;

requireSource('private_html/compliance/ConsentCategoryService.php');

/**
 * 🍪 ConsentCategoryService pure-logic test suite.
 */
class ConsentCategoryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        // 🧹 TestCase's own tearDown() does not know about this header —
        // clear it explicitly so a GPC test never leaks into another test.
        unset($_SERVER['HTTP_SEC_GPC']);

        parent::tearDown();
    }

    // ========================================================================
    // 🔧 isTruthyInput()
    // ========================================================================

    /**
     * @dataProvider truthyInputProvider
     */
    public function testIsTruthyInputRecognisesTruthyValues(mixed $value): void
    {
        $this->assertTrue(ConsentCategoryService::isTruthyInput($value));
    }

    public static function truthyInputProvider(): array
    {
        return [
            'native true'      => [true],
            'checkbox "1"'     => ['1'],
            'checkbox "on"'    => ['on'],
            'string "true"'    => ['true'],
            'string "TRUE"'    => ['TRUE'],
            'string "yes"'     => ['yes'],
        ];
    }

    /**
     * @dataProvider falsyInputProvider
     */
    public function testIsTruthyInputRecognisesFalsyValues(mixed $value): void
    {
        $this->assertFalse(ConsentCategoryService::isTruthyInput($value));
    }

    public static function falsyInputProvider(): array
    {
        return [
            'native false'  => [false],
            'string "0"'    => ['0'],
            'string "off"'  => ['off'],
            'string "no"'   => ['no'],
            'empty string'  => [''],
            'null'          => [null],
            'junk string'   => ['not-a-boolean'],
        ];
    }

    // ========================================================================
    // 🍪 issueOrGetVisitorID()
    // ========================================================================

    public function testIssueOrGetVisitorIdReturnsAnExistingValidCookieUnchanged(): void
    {
        $existingUuid = '550e8400-e29b-41d4-a716-446655440000';
        $_COOKIE['SIGNULA_VID'] = $existingUuid;

        $this->assertSame($existingUuid, ConsentCategoryService::issueOrGetVisitorID());
    }

    public function testIssueOrGetVisitorIdHonoursACustomCookieNameSetting(): void
    {
        setTestSetting('consent.visitor_cookie.name', 'CUSTOM_VID');
        $existingUuid = '550e8400-e29b-41d4-a716-446655440001';
        $_COOKIE['CUSTOM_VID'] = $existingUuid;

        $this->assertSame($existingUuid, ConsentCategoryService::issueOrGetVisitorID());
    }

    public function testIssueOrGetVisitorIdGeneratesAFreshUuidWhenNoneIsPresent(): void
    {
        unset($_COOKIE['SIGNULA_VID']);

        $visitorID = ConsentCategoryService::issueOrGetVisitorID();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $visitorID,
            'A freshly issued visitorID must be a syntactically valid UUID v4'
        );
    }

    public function testIssueOrGetVisitorIdIsStableAcrossTwoCallsInTheSameRequest(): void
    {
        // 🔁 setcookie() only takes effect on the NEXT HTTP request — this
        // proves the in-request $_COOKIE reflection keeps a SECOND call
        // within the same request from minting a DIFFERENT UUID.
        unset($_COOKIE['SIGNULA_VID']);

        $first = ConsentCategoryService::issueOrGetVisitorID();
        $second = ConsentCategoryService::issueOrGetVisitorID();

        $this->assertSame($first, $second, 'issueOrGetVisitorID() must return a STABLE UUID across calls in the same request');
    }

    public function testIssueOrGetVisitorIdIgnoresAMalformedCookieAndIssuesAFreshOne(): void
    {
        $_COOKIE['SIGNULA_VID'] = 'not-a-valid-uuid';

        $visitorID = ConsentCategoryService::issueOrGetVisitorID();

        $this->assertNotSame('not-a-valid-uuid', $visitorID);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $visitorID
        );
    }

    // ========================================================================
    // 🚦 maybeReconsentRedirect() — the settings-only gate MUST short-circuit
    // BEFORE touching the database (proven here by the fact that Database is
    // never loaded in this suite at all — if the gate accidentally called
    // userNeedsReconsent()/ConsentManager, this test would fatal).
    // ========================================================================

    public function testMaybeReconsentRedirectReturnsNullWhenEnforcementIsOffWithoutTouchingTheDatabase(): void
    {
        setTestSetting('consent.reconsent.enforce', '0');

        $result = ConsentCategoryService::maybeReconsentRedirect(42, '/dashboard');

        $this->assertNull($result, 'With consent.reconsent.enforce=0, no redirect must ever be produced');
    }

    public function testMaybeReconsentRedirectReturnsNullWhenEnforcementSettingIsAbsentEntirely(): void
    {
        // 🔒 The shipped DEFAULT (migration 041 seeds '0') — absence of the
        // setting must behave the same as an explicit '0', never fail open.
        $result = ConsentCategoryService::maybeReconsentRedirect(42, '/dashboard');

        $this->assertNull($result);
    }

    // ========================================================================
    // 🌐 honorGpcSignalIfPresent() — cheap guards must run BEFORE $_SESSION
    // or the database are touched at all.
    // ========================================================================

    public function testHonorGpcSignalDoesNothingWhenHeaderIsAbsent(): void
    {
        unset($_SERVER['HTTP_SEC_GPC']);
        setTestSetting('consent.gpc.honor', '1');

        ConsentCategoryService::honorGpcSignalIfPresent(42, null);

        $this->assertArrayNotHasKey('gpc_honored', $_SESSION, 'No header present — the session must never be touched');
    }

    public function testHonorGpcSignalDoesNothingWhenSettingIsOff(): void
    {
        $_SERVER['HTTP_SEC_GPC'] = '1';
        setTestSetting('consent.gpc.honor', '0');

        ConsentCategoryService::honorGpcSignalIfPresent(42, null);

        $this->assertArrayNotHasKey('gpc_honored', $_SESSION, 'Honoring disabled — the session must never be touched');
    }

    public function testHonorGpcSignalDoesNotSetTheSessionFlagWhenNoIdentityIsAvailableYet(): void
    {
        // ⚠️ See ConsentCategoryService::honorGpcSignalIfPresent()'s ordering
        // note: an anonymous visitor's very first request may have neither a
        // session userID nor a visitor-cookie visitorID yet. The flag must
        // stay UNSET so a later request (once an identity exists) gets
        // another chance — this is what this test proves.
        $_SERVER['HTTP_SEC_GPC'] = '1';
        setTestSetting('consent.gpc.honor', '1');

        ConsentCategoryService::honorGpcSignalIfPresent(null, null);

        $this->assertArrayNotHasKey(
            'gpc_honored',
            $_SESSION,
            'With no userID and no visitorID, the session flag must NOT be set — this is a "try again later", not a "never try again"'
        );
    }

    public function testHonorGpcSignalHeaderMustBeExactlyTheStringOne(): void
    {
        // 🔎 Documents current behaviour: any value other than the literal
        // string '1' (e.g. the truthy-looking 'true') is NOT honoured — the
        // Sec-GPC header's only valid ON value per the GPC spec is '1'.
        $_SERVER['HTTP_SEC_GPC'] = 'true';
        setTestSetting('consent.gpc.honor', '1');

        ConsentCategoryService::honorGpcSignalIfPresent(42, null);

        $this->assertArrayNotHasKey('gpc_honored', $_SESSION);
    }
}
