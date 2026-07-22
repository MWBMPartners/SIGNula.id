<?php
/**
 * ============================================================================
 * 🧪 DataRequestIntake Unit Tests (G-004 Layer 1 — Cycle C)
 * ============================================================================
 *
 * Covers the PURE, DB-free logic inside DataRequestIntake: the public
 * data-request form's requestType allowlist guard (isValidRequestType() is
 * public and pure — mirrors DSARManager::canTransition()'s identical "pure
 * predicate, no DB access" pattern), and pins the UNIFORM_SUCCESS_MESSAGE
 * constant that the calling page renders unconditionally.
 *
 * The anti-enumeration property itself (existing vs non-existing email
 * producing identical OUTWARD behaviour) is DB-bound and lives in the
 * Integration suite (_tests/Integration/Compliance/DataRequestIntakeTest.php),
 * which has a real MySQL connection to actually create/not-create a matching
 * tblUsers row.
 *
 * @package    SIGNula\Tests\Unit\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/DataRequestIntake.php
 * @see        .dev-team/specs/G-004.md §2.6, §6, risk #4
 */

namespace SIGNula\Tests\Unit\Compliance;

use SIGNula\Tests\TestCase;
use DataRequestIntake;

requireSource('private_html/compliance/DataRequestIntake.php');

/**
 * 📮 DataRequestIntake pure-logic test suite.
 */
class DataRequestIntakeTest extends TestCase
{
    // ========================================================================
    // ✅ isValidRequestType() — the public form's server-authoritative allowlist
    // ========================================================================

    /**
     * @dataProvider validRequestTypeProvider
     */
    public function testIsValidRequestTypeAcceptsEveryAllowlistedType(string $requestType): void
    {
        $this->assertTrue(DataRequestIntake::isValidRequestType($requestType));
    }

    public static function validRequestTypeProvider(): array
    {
        return [
            'access' => ['access'],
            'portability' => ['portability'],
            'erasure' => ['erasure'],
            'rectification' => ['rectification'],
        ];
    }

    /**
     * @dataProvider invalidRequestTypeProvider
     */
    public function testIsValidRequestTypeRejectsEverythingOutsideTheAllowlist(string $requestType): void
    {
        $this->assertFalse(DataRequestIntake::isValidRequestType($requestType));
    }

    public static function invalidRequestTypeProvider(): array
    {
        return [
            'empty string' => [''],
            // 🔒 These ARE valid on DSARManager's own broader
            // VALID_REQUEST_TYPES union, but must NOT be submittable via the
            // public pre-auth form — this is the narrower channel-specific
            // allowlist (mirrors settings/privacy.php's own $DSAR_TYPE_LABELS
            // being narrower than DSARManager::VALID_REQUEST_TYPES).
            'restriction is admin/other-channel only' => ['restriction'],
            'object is admin/other-channel only' => ['object'],
            'do_not_sell is admin/other-channel only' => ['do_not_sell'],
            'withdraw_consent is admin/other-channel only' => ['withdraw_consent'],
            'automated_decision_optout is admin/other-channel only' => ['automated_decision_optout'],
            'garbage value' => ['not_a_real_type'],
            'case-sensitive mismatch' => ['ACCESS'],
        ];
    }

    // ========================================================================
    // 🔒 UNIFORM_SUCCESS_MESSAGE — pinned so it can never silently drift
    // ========================================================================

    public function testUniformSuccessMessageIsANonEmptyStringConstant(): void
    {
        $this->assertIsString(DataRequestIntake::UNIFORM_SUCCESS_MESSAGE);
        $this->assertNotSame('', DataRequestIntake::UNIFORM_SUCCESS_MESSAGE);

        // 🔒 The message must never itself claim/deny account existence —
        // pin the "if an account exists" conditional framing so a future
        // edit can't accidentally turn this into an assertive "we found
        // your account" / "no account found" statement.
        $this->assertStringContainsString('if an account exists', strtolower(DataRequestIntake::UNIFORM_SUCCESS_MESSAGE));
    }

    // ========================================================================
    // 🛡️ process() defensive re-check — pure input-shape guard, no DB touch
    // ========================================================================

    public function testProcessReturnsVoidImmediatelyForAnEmptyEmailWithoutTouchingTheDatabase(): void
    {
        // 🔒 No Database class is loaded in this Unit suite at all — if
        // process() tried to query, this would fatal with "Class Database
        // not found" rather than silently return. Reaching assertNull below
        // without a fatal IS the proof that the empty-email guard short-
        // circuits before any DB access, exactly like DSARManager::submit()'s
        // "validate first, before any Database:: call" contract.
        $this->assertNull(DataRequestIntake::process('', 'access', null));
    }

    public function testProcessReturnsVoidImmediatelyForAnOutOfAllowlistTypeWithoutTouchingTheDatabase(): void
    {
        $this->assertNull(DataRequestIntake::process('someone@example.com', 'restriction', null));
    }
}
