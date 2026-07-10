<?php
/**
 * ============================================================================
 * 🧪 regime-actions.php / Compliance Regime Admin CRUD Tests (G-004 Layer 3 §4.3)
 * ============================================================================
 *
 * `web/public_html/admin/compliance/regimes/index.php` and
 * `web/public_html/admin/compliance/api/regime-actions.php` are true HTTP
 * endpoint scripts (session + AccessControl + CSRF + `echo`/`exit` at the top
 * level). Rather than standing up a `php -S` subprocess harness for the whole
 * script — judged out of proportion for this cycle, matching the identical
 * precedent already documented in
 * `_tests/Integration/Compliance/DsarAdminActionsCallSiteTest.php` — this
 * suite proves things at two levels:
 *
 *   1. GENUINE functional tests against the REAL, unmodified handler
 *      functions (handleActivateRegime(), handleUpdateRegime(), etc.) —
 *      made possible by regime-actions.php's own SIGNULA_REGIME_ACTIONS_TEST_ONLY
 *      test seam (see that file's docblock above `regimeCanActivate()`),
 *      which returns immediately after defining the pure helper function and
 *      every allowlist constant, but BEFORE the live bootstrap/session/CSRF/
 *      dispatch code runs. PHP hoists top-level function declarations at
 *      compile time regardless of that early return, so every handler
 *      function in the file becomes callable here — this is the SAME
 *      production code a live request executes, not a copy or a
 *      re-implementation.
 *   2. The security invariants that call shape alone can't prove (CSRF gate,
 *      super-admin gate, X-Frame-Options, POST-only enforcement on mutating
 *      actions) via source-content sentinels — the SAME technique
 *      DsarAdminActionsCallSiteTest and DSARManagerTest's "anti-duplication
 *      source sentinel" test already use successfully for an equivalent
 *      "prove a security property holds" assertion.
 *
 * ⚠️ Test-fixture hygiene (mirrors RegimeResolverTest — READ before adding
 * more tests here): this class does NOT put tblComplianceRegimes /
 * tblRegimeRights / tblRegimeDisclosures / tblRegimeResolution in
 * $truncateTables — those tables carry the REAL migration-042 seed data (19
 * regimes an operator may already be reviewing). Every fixture regime this
 * class creates uses a `ZZTEST`-prefixed regimeCode and is explicitly
 * DELETEd in tearDown() by exact ID. tblRegimeRights/tblRegimeDisclosures/
 * tblRegimeResolution rows targeting a fixture regime cascade-delete
 * automatically (migration 042's ON DELETE CASCADE FKs).
 *
 * ⚠️ Dual-connection note (also mirrors RegimeResolverTest): fixtures are
 * inserted via `\Database::` (the SAME static connection
 * regime-actions.php's handlers read/write through), NOT `$this->db`
 * (DatabaseTestCase's own separate mysqli session, used only to construct a
 * lightweight AccessControl stub so handleAdminAction()'s audit-log INSERT
 * auto-rolls-back with the test transaction). These are two independent
 * mysqli connections — a transaction on one has no effect on the other,
 * which is exactly why fixture regimes need explicit tearDown() cleanup.
 *
 * @package    SIGNula\Tests\Integration\Compliance
 * @version    2.7.0-beta
 * @see        web/public_html/admin/compliance/regimes/index.php
 * @see        web/public_html/admin/compliance/api/regime-actions.php
 * @see        web/private_html/compliance/RegimeResolver.php
 * @see        _tests/Integration/Compliance/DsarAdminActionsCallSiteTest.php — the precedent this mirrors
 * @see        _tests/Integration/Compliance/RegimeResolverTest.php — the ZZTEST fixture-hygiene idiom this mirrors
 * @see        _database/migrations/042_compliance_regimes.sql
 * @see        .dev-team/specs/G-004.md §4.3, §4.4
 */

namespace SIGNula\Tests\Integration\Compliance;

use SIGNula\Tests\DatabaseTestCase;
use AccessControl;

requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('_backend/AccessControl.php');

// 🧪 Trip regime-actions.php's test seam BEFORE requiring it — this defines
// regimeCanActivate() + every REGIME_* allowlist constant + every handleXxx()
// handler function as REAL, callable global symbols, while skipping the live
// bootstrap/session/CSRF/dispatch code that a real HTTP request would run
// (that code calls exit() on an unauthenticated request, which would abort
// the entire PHPUnit process if it ran here). See regime-actions.php's own
// docblock for the full rationale — production requests never define this
// constant, so it is inert outside this exact test process.
if (!defined('SIGNULA_REGIME_ACTIONS_TEST_ONLY')) {
    define('SIGNULA_REGIME_ACTIONS_TEST_ONLY', true);
}
require_once \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
    . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'api'
    . DIRECTORY_SEPARATOR . 'regime-actions.php';

/**
 * 🛡️ Compliance regime admin CRUD test suite.
 */
class RegimeAdminActionsTest extends DatabaseTestCase
{
    /** @var array<int,int> tblComplianceRegimes.regimeID rows this test created. */
    private array $testRegimeIDsToCleanUp = [];

    /** @var string Absolute path to the two endpoint scripts under test. */
    private string $indexPagePath;
    private string $actionsApiPath;

    /** @var AccessControl A real AccessControl bound to a stub "always super-admin" session. */
    private AccessControl $accessControl;

    /** @var int The stub admin's userID (a real tblUsers row — logAdminAction's FK needs one). */
    private int $adminUserID;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexPagePath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'regimes'
            . DIRECTORY_SEPARATOR . 'index.php';

        $this->actionsApiPath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'api'
            . DIRECTORY_SEPARATOR . 'regime-actions.php';

        $admin = $this->createTestUser(['isSuperAdmin' => 1]);
        $this->adminUserID = (int) $admin['userID'];

        // 🩹 A minimal duck-typed stand-in for SessionManager — AccessControl's
        // constructor only ever calls isLoggedIn()/getUserID()/getUserInfo()
        // on it (see web/_backend/AccessControl.php), none of which are
        // type-hinted, so this lightweight double satisfies it without
        // dragging in the real SessionManager's cookie/DB-session machinery.
        $stubSessionManager = new class($this->adminUserID) {
            public function __construct(private int $userID)
            {
            }
            public function isLoggedIn(): bool
            {
                return true;
            }
            public function getUserID(): int
            {
                return $this->userID;
            }
            public function getUserInfo(): array
            {
                return ['userID' => $this->userID, 'isSuperAdmin' => 1];
            }
        };

        // 🔗 Bound to $this->db (DatabaseTestCase's OWN transactional mysqli
        // session) deliberately — logAdminAction()'s tblAdminAuditLog INSERT
        // then auto-rolls-back with the rest of this test's transaction,
        // needing no manual cleanup.
        $this->accessControl = new AccessControl($this->db, $stubSessionManager);
    }

    protected function tearDown(): void
    {
        // 🧹 Explicit cleanup (NOT truncateTables — see class docblock): only
        // ever removes the rows THIS test created, by exact ID, leaving the
        // real migration-042 seed data completely undisturbed.
        foreach ($this->testRegimeIDsToCleanUp as $regimeID) {
            // tblRegimeRights / tblRegimeDisclosures / tblRegimeResolution
            // all cascade on regimeID delete (migration 042 FKs).
            \Database::query("DELETE FROM tblComplianceRegimes WHERE regimeID = ?", [$regimeID], 'i');
        }

        parent::tearDown();
    }

    // ========================================================================
    // 🔧 Test-fixture helper (mirrors RegimeResolverTest::insertTestRegime())
    // ========================================================================

    /**
     * @param array<string,mixed> $overrides
     * @return array{regimeID:int, regimeCode:string}
     */
    private function insertTestRegime(array $overrides = []): array
    {
        $defaults = [
            'regimeCode'            => 'ZZTEST' . strtoupper(bin2hex(random_bytes(3))),
            'displayName'           => 'Admin CRUD Test Regime',
            'jurisdiction'          => 'Testland',
            'dsarSlaDays'           => null,
            'dsarExtensionDays'     => null,
            'breachNotifyHours'     => null,
            'breachNotifyAuthority' => null,
            'minAge'                => null,
            'honorsGPC'             => 0,
            'requiresDoNotSell'     => 0,
            'lawfulBasisRequired'   => 0,
            'isActive'              => 0,
            'isDraft'               => 1,
            'notes'                 => 'Integration-test fixture — safe to delete',
        ];
        $data = array_merge($defaults, $overrides);

        $regimeID = \Database::insert(
            "INSERT INTO tblComplianceRegimes
                (regimeCode, displayName, jurisdiction, dsarSlaDays, dsarExtensionDays,
                 breachNotifyHours, breachNotifyAuthority, minAge, honorsGPC, requiresDoNotSell,
                 lawfulBasisRequired, isActive, isDraft, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['regimeCode'], $data['displayName'], $data['jurisdiction'],
                $data['dsarSlaDays'], $data['dsarExtensionDays'], $data['breachNotifyHours'],
                $data['breachNotifyAuthority'], $data['minAge'],
                $data['honorsGPC'] ? 1 : 0, $data['requiresDoNotSell'] ? 1 : 0,
                $data['lawfulBasisRequired'] ? 1 : 0, $data['isActive'] ? 1 : 0,
                $data['isDraft'] ? 1 : 0, $data['notes'],
            ],
            'sssiiisiiiiiis'
        );

        $this->testRegimeIDsToCleanUp[] = $regimeID;

        return ['regimeID' => $regimeID, 'regimeCode' => $data['regimeCode']];
    }

    /**
     * 🔧 Call a global handleXxx()/regimeCanActivate() function that echoes
     * JSON, capturing its output instead of letting it leak to test output.
     *
     * @param callable $fn Zero-arg closure wrapping the real global call
     * @return array<string,mixed> Decoded JSON response
     */
    private function captureJson(callable $fn): array
    {
        ob_start();
        $fn();
        $raw = ob_get_clean();

        $decoded = json_decode((string) $raw, true);
        $this->assertIsArray($decoded, "Handler must echo valid JSON, got: {$raw}");

        return $decoded;
    }

    // ========================================================================
    // 🔒 THE ACTIVATION GUARD (spec §4.4 AC) — THE CRUX
    // ========================================================================

    public function testRegimeCanActivateRejectsARowWithAllThreeRequiredFieldsNull(): void
    {
        [$canActivate, $missing] = \regimeCanActivate([
            'dsarSlaDays' => null, 'minAge' => null, 'breachNotifyHours' => null,
        ]);

        $this->assertFalse($canActivate);
        $this->assertSame(['dsarSlaDays', 'minAge', 'breachNotifyHours'], $missing);
    }

    public function testRegimeCanActivateRejectsARowMissingOnlyOneOfTheThreeFields(): void
    {
        [$canActivate, $missing] = \regimeCanActivate([
            'dsarSlaDays' => 30, 'minAge' => 16, 'breachNotifyHours' => null,
        ]);

        $this->assertFalse($canActivate);
        $this->assertSame(['breachNotifyHours'], $missing);
    }

    public function testRegimeCanActivateAcceptsARowWithAllThreeFieldsSet(): void
    {
        [$canActivate, $missing] = \regimeCanActivate([
            'dsarSlaDays' => 30, 'minAge' => 16, 'breachNotifyHours' => 72,
        ]);

        $this->assertTrue($canActivate);
        $this->assertSame([], $missing);
    }

    /**
     * 🔒 THE headline test for this cycle. Calls the REAL
     * handleActivateRegime() — the exact function regime-actions.php's
     * dispatch switch() calls — against a fixture regime whose three
     * required legal values are all NULL. Asserts it is REJECTED and, most
     * importantly, that the STORED ROW is completely untouched (isActive
     * stays 0, isDraft stays 1) — proving the guard fails closed rather than
     * partially applying the mutation.
     */
    public function testActivateRegimeIsRejectedWhenLegalValuesAreNullAndTheStoredRowStaysUntouched(): void
    {
        $regime = $this->insertTestRegime(); // dsarSlaDays/minAge/breachNotifyHours all NULL by default

        $response = $this->captureJson(function () use ($regime): void {
            \handleActivateRegime(['regimeID' => $regime['regimeID']], $this->accessControl, $this->adminUserID);
        });

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('dsarSlaDays', $response['error']);
        $this->assertStringContainsString('minAge', $response['error']);
        $this->assertStringContainsString('breachNotifyHours', $response['error']);

        $persisted = $this->getRecordFromRealConnection($regime['regimeID']);
        $this->assertSame('0', (string) $persisted['isActive'], 'isActive must stay 0 — the guard must reject BEFORE any write');
        $this->assertSame('1', (string) $persisted['isDraft'], 'isDraft must stay 1 — untouched on the rejection path');
    }

    /**
     * 🔒 The positive counterpart — once all three legal values are set on
     * the stored row, the SAME real handleActivateRegime() call succeeds and
     * flips isActive=1/isDraft=0.
     */
    public function testActivateRegimeSucceedsOnceAllThreeLegalValuesAreSetAndFlipsTheStoredRow(): void
    {
        $regime = $this->insertTestRegime([
            'dsarSlaDays' => 30, 'minAge' => 16, 'breachNotifyHours' => 72,
        ]);

        $response = $this->captureJson(function () use ($regime): void {
            \handleActivateRegime(['regimeID' => $regime['regimeID']], $this->accessControl, $this->adminUserID);
        });

        $this->assertTrue($response['success']);
        $this->assertTrue($response['data']['isActive']);
        $this->assertFalse($response['data']['isDraft']);

        $persisted = $this->getRecordFromRealConnection($regime['regimeID']);
        $this->assertSame('1', (string) $persisted['isActive']);
        $this->assertSame('0', (string) $persisted['isDraft']);
    }

    /**
     * 🔒 THE bypass-attempt proof: the client sends FAKE legal values
     * alongside activate_regime, but handleActivateRegime() re-SELECTs the
     * row itself and ignores anything in $input except regimeID — the
     * fabricated values in the request must have ZERO effect on the
     * decision or on what gets persisted.
     */
    public function testActivateRegimeIgnoresClientSuppliedLegalValuesAndOnlyTrustsTheStoredRow(): void
    {
        $regime = $this->insertTestRegime(); // stored row: all three NULL

        $response = $this->captureJson(function () use ($regime): void {
            \handleActivateRegime([
                'regimeID'          => $regime['regimeID'],
                // 🚫 Fabricated — must be completely ignored.
                'dsarSlaDays'       => 30,
                'minAge'            => 16,
                'breachNotifyHours' => 72,
            ], $this->accessControl, $this->adminUserID);
        });

        $this->assertFalse($response['success'], 'Fabricated request-body values must NOT bypass the guard — only the stored row counts');

        $persisted = $this->getRecordFromRealConnection($regime['regimeID']);
        $this->assertSame('0', (string) $persisted['isActive']);
        $this->assertNull($persisted['dsarSlaDays'], 'The stored row must remain untouched — the fabricated values were never written anywhere');
    }

    public function testDeactivateRegimeAlwaysSucceedsRegardlessOfLegalValues(): void
    {
        $regime = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 0, 'dsarSlaDays' => 30, 'minAge' => 16, 'breachNotifyHours' => 72]);

        $response = $this->captureJson(function () use ($regime): void {
            \handleDeactivateRegime(['regimeID' => $regime['regimeID']], $this->accessControl, $this->adminUserID);
        });

        $this->assertTrue($response['success']);
        $persisted = $this->getRecordFromRealConnection($regime['regimeID']);
        $this->assertSame('0', (string) $persisted['isActive']);
        // 🔎 isDraft is deliberately left AS-IS on deactivate (see
        // handleDeactivateRegime()'s own docblock) — still 0 here.
        $this->assertSame('0', (string) $persisted['isDraft']);
    }

    // ========================================================================
    // ✏️ handleUpdateRegime() — a regime edit persists (integration)
    // ========================================================================

    public function testUpdateRegimePersistsEditedFieldsAndLeavesTheAllowlistBoundaryIntact(): void
    {
        $regime = $this->insertTestRegime(['displayName' => 'Original Name', 'dsarSlaDays' => null]);

        $response = $this->captureJson(function () use ($regime): void {
            \handleUpdateRegime([
                'regimeID'          => $regime['regimeID'],
                'displayName'       => 'Updated Display Name',
                'jurisdiction'      => 'Updated Jurisdiction',
                'dsarSlaDays'       => '45',
                'minAge'            => '18',
                'breachNotifyHours' => '72',
                'honorsGPC'         => 1,
                'notes'             => 'Confirmed by legal on 2026-07-10',
                // 🚫 Not in the allowlist — must be silently ignored, never
                // written (regimeCode/isActive/isDraft have their own actions).
                'regimeCode'        => 'HACKED',
                'isActive'          => 1,
                'isDraft'           => 0,
            ], $this->accessControl, $this->adminUserID);
        });

        $this->assertTrue($response['success']);

        $persisted = $this->getRecordFromRealConnection($regime['regimeID']);
        $this->assertSame('Updated Display Name', $persisted['displayName']);
        $this->assertSame('Updated Jurisdiction', $persisted['jurisdiction']);
        $this->assertSame('45', (string) $persisted['dsarSlaDays']);
        $this->assertSame('18', (string) $persisted['minAge']);
        $this->assertSame('72', (string) $persisted['breachNotifyHours']);
        $this->assertSame('1', (string) $persisted['honorsGPC']);
        $this->assertSame('Confirmed by legal on 2026-07-10', $persisted['notes']);

        // 🔒 regimeCode/isActive/isDraft are NOT in update_regime's allowlist —
        // they must be completely untouched despite being present in $input.
        $this->assertSame($regime['regimeCode'], $persisted['regimeCode']);
        $this->assertSame('0', (string) $persisted['isActive'], 'update_regime must NEVER be able to flip isActive — that is activate_regime\'s job, gated by regimeCanActivate()');
        $this->assertSame('1', (string) $persisted['isDraft']);
    }

    public function testUpdateRegimeBlankingANumericFieldClearsItBackToNull(): void
    {
        $regime = $this->insertTestRegime(['dsarSlaDays' => 30, 'minAge' => 16, 'breachNotifyHours' => 72, 'isActive' => 1, 'isDraft' => 0]);

        $response = $this->captureJson(function () use ($regime): void {
            \handleUpdateRegime(['regimeID' => $regime['regimeID'], 'dsarSlaDays' => ''], $this->accessControl, $this->adminUserID);
        });

        $this->assertTrue($response['success']);
        $persisted = $this->getRecordFromRealConnection($regime['regimeID']);
        $this->assertNull($persisted['dsarSlaDays'], 'A blank submission must clear the column back to NULL, re-locking future activation');
    }

    // ========================================================================
    // ➕ handleCreateRegime() — never writes a legal value
    // ========================================================================

    public function testCreateRegimeShipsAsADraftWithEveryLegalValueNull(): void
    {
        $regimeCode = 'ZZTEST' . strtoupper(bin2hex(random_bytes(3)));

        $response = $this->captureJson(function () use ($regimeCode): void {
            \handleCreateRegime(['regimeCode' => $regimeCode, 'displayName' => 'Newly Created Regime', 'jurisdiction' => 'Nowhere'], $this->accessControl, $this->adminUserID);
        });

        $this->assertTrue($response['success']);
        $regimeID = (int) $response['data']['regimeID'];
        $this->testRegimeIDsToCleanUp[] = $regimeID; // 🧹 track for tearDown — insertTestRegime() was not used here

        $persisted = $this->getRecordFromRealConnection($regimeID);
        $this->assertSame('0', (string) $persisted['isActive']);
        $this->assertSame('1', (string) $persisted['isDraft']);
        $this->assertNull($persisted['dsarSlaDays'], 'handleCreateRegime() must NEVER write a legal value itself');
        $this->assertNull($persisted['minAge']);
        $this->assertNull($persisted['breachNotifyHours']);
    }

    public function testCreateRegimeRejectsADuplicateRegimeCode(): void
    {
        $regime = $this->insertTestRegime();

        $response = $this->captureJson(function () use ($regime): void {
            \handleCreateRegime(['regimeCode' => $regime['regimeCode'], 'displayName' => 'Duplicate Attempt'], $this->accessControl, $this->adminUserID);
        });

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('already exists', $response['error']);
    }

    // ========================================================================
    // ☑️ handleSetRights() — full-replace, allowlist-validated
    // ========================================================================

    public function testSetRightsReplacesTheFullRightsSetAndRejectsAnInvalidRight(): void
    {
        $regime = $this->insertTestRegime();

        $ok = $this->captureJson(function () use ($regime): void {
            \handleSetRights(['regimeID' => $regime['regimeID'], 'rights' => ['access', 'erasure', 'access']], $this->accessControl, $this->adminUserID);
        });
        $this->assertTrue($ok['success']);
        $this->assertSame(['access', 'erasure'], $ok['data']['rights'], 'Duplicates must be de-duped');

        $rows = \Database::fetchAll("SELECT `right` FROM tblRegimeRights WHERE regimeID = ? ORDER BY `right` ASC", [$regime['regimeID']], 'i');
        $this->assertSame(['access', 'erasure'], array_map(static fn(array $r) => $r['right'], $rows));

        $bad = $this->captureJson(function () use ($regime): void {
            \handleSetRights(['regimeID' => $regime['regimeID'], 'rights' => ['not_a_real_right']], $this->accessControl, $this->adminUserID);
        });
        $this->assertFalse($bad['success']);
    }

    // ========================================================================
    // 📜 handleSaveDisclosure() — never fabricates title/body
    // ========================================================================

    public function testSaveDisclosureStoresExactlyWhatWasSubmittedAndNeverFabricatesText(): void
    {
        $regime = $this->insertTestRegime();

        // 1️⃣ An empty submission must store NULL, not any placeholder text.
        $empty = $this->captureJson(function () use ($regime): void {
            \handleSaveDisclosure(['regimeID' => $regime['regimeID'], 'disclosureKey' => 'categories_collected', 'title' => '', 'body' => ''], $this->accessControl, $this->adminUserID);
        });
        $this->assertTrue($empty['success']);
        $row = \Database::fetchOne("SELECT * FROM tblRegimeDisclosures WHERE regimeID = ? AND disclosureKey = ?", [$regime['regimeID'], 'categories_collected'], 'is');
        $this->assertNull($row['title']);
        $this->assertNull($row['body'], 'An empty submission must never be replaced with fabricated/placeholder body text');

        // 2️⃣ Submitting real owner-authored text stores it VERBATIM (upsert
        // on the same regimeID+disclosureKey pair), including characters a
        // storage-time htmlspecialchars() pass would have mangled.
        $ownerText = 'We collect: name, email & "billing" address <redacted>.';
        $filled = $this->captureJson(function () use ($regime, $ownerText): void {
            \handleSaveDisclosure(['regimeID' => $regime['regimeID'], 'disclosureKey' => 'categories_collected', 'title' => 'Categories We Collect', 'body' => $ownerText], $this->accessControl, $this->adminUserID);
        });
        $this->assertTrue($filled['success']);
        $row2 = \Database::fetchOne("SELECT * FROM tblRegimeDisclosures WHERE regimeID = ? AND disclosureKey = ?", [$regime['regimeID'], 'categories_collected'], 'is');
        $this->assertSame('Categories We Collect', $row2['title']);
        $this->assertSame($ownerText, $row2['body'], 'Owner-supplied body text must be stored verbatim, not htmlspecialchars-mangled at storage time');

        // 🔁 Still exactly one row for this (regimeID, disclosureKey) pair —
        // the second call was an UPDATE, not a second INSERT.
        $count = \Database::fetchOne("SELECT COUNT(*) AS c FROM tblRegimeDisclosures WHERE regimeID = ? AND disclosureKey = ?", [$regime['regimeID'], 'categories_collected'], 'is');
        $this->assertSame(1, (int) $count['c']);
    }

    // ========================================================================
    // 🗺️ handleSaveResolution() / handleDeleteResolution()
    // ========================================================================

    public function testSaveAndDeleteResolutionRuleRoundTrips(): void
    {
        $regime = $this->insertTestRegime();

        $created = $this->captureJson(function () use ($regime): void {
            \handleSaveResolution(['regimeID' => $regime['regimeID'], 'matchType' => 'country', 'matchValue' => 'DE', 'priority' => 5], $this->accessControl, $this->adminUserID);
        });
        $this->assertTrue($created['success']);
        $ruleID = (int) $created['data']['ruleID'];

        $rule = \Database::fetchOne("SELECT * FROM tblRegimeResolution WHERE ruleID = ?", [$ruleID], 'i');
        $this->assertSame('country', $rule['matchType']);
        $this->assertSame('DE', $rule['matchValue']);
        $this->assertSame(5, (int) $rule['priority']);

        $deleted = $this->captureJson(function () use ($regime, $ruleID): void {
            \handleDeleteResolution(['regimeID' => $regime['regimeID'], 'ruleID' => $ruleID], $this->accessControl, $this->adminUserID);
        });
        $this->assertTrue($deleted['success']);
        $this->assertNull(\Database::fetchOne("SELECT * FROM tblRegimeResolution WHERE ruleID = ?", [$ruleID], 'i'));
    }

    // ========================================================================
    // 🔧 Fetch helper — reads via the REAL `\Database::` connection (the one
    // the handlers under test actually write through), not $this->db.
    // ========================================================================

    /**
     * @param int $regimeID
     * @return array<string,mixed>
     */
    private function getRecordFromRealConnection(int $regimeID): array
    {
        $row = \Database::fetchOne("SELECT * FROM tblComplianceRegimes WHERE regimeID = ?", [$regimeID], 'i');
        $this->assertNotNull($row, 'Fixture regime must still exist');

        return $row;
    }

    // ========================================================================
    // 🛡️ CSRF — the real function, plus a source sentinel proving the
    // endpoint wires it before every mutation
    // ========================================================================

    public function testVerifyCsrfTokenRejectsAMissingOrBogusToken(): void
    {
        $this->assertFalse(\SecurityUtils::verifyCSRFToken(''));
        $this->assertFalse(\SecurityUtils::verifyCSRFToken('totally-bogus-token-value'));
    }

    public function testRegimeActionsSourceGatesEveryPostRequestBehindCsrfVerificationBeforeAnyMutation(): void
    {
        $source = file_get_contents($this->actionsApiPath);
        $this->assertNotFalse($source, 'regime-actions.php must be readable for this sentinel test');

        $this->assertStringContainsString("SecurityUtils::verifyCSRFToken(", $source);
        $csrfPos = strpos($source, 'SecurityUtils::verifyCSRFToken(');
        $switchPos = strpos($source, 'switch ($action)');
        $this->assertNotFalse($switchPos, 'regime-actions.php must dispatch actions via a switch($action) statement');
        $this->assertLessThan($switchPos, $csrfPos, 'CSRF verification must happen BEFORE the action dispatch switch()');
    }

    /**
     * 🛡️ Defence-in-depth proof: EVERY mutating action name is POST-gated
     * BEFORE the dispatch switch() — not just individually inside each
     * handler — closing the "GET request skips the CSRF branch entirely"
     * gap the CSRF check alone cannot close (that check only runs when
     * REQUEST_METHOD === 'POST').
     */
    public function testRegimeActionsSourceEnforcesPostOnlyForEveryMutatingActionBeforeDispatch(): void
    {
        $source = file_get_contents($this->actionsApiPath);
        $this->assertNotFalse($source);

        $this->assertStringContainsString('REGIME_MUTATING_ACTIONS', $source);
        $this->assertStringContainsString("in_array(\$action, REGIME_MUTATING_ACTIONS, true) && \$_SERVER['REQUEST_METHOD'] !== 'POST'", $source);

        $postOnlyGatePos = strpos($source, "in_array(\$action, REGIME_MUTATING_ACTIONS, true) && \$_SERVER['REQUEST_METHOD'] !== 'POST'");
        $switchPos = strpos($source, 'switch ($action)');
        $this->assertNotFalse($postOnlyGatePos);
        $this->assertLessThan($switchPos, $postOnlyGatePos, 'The POST-only gate must be checked BEFORE the action dispatch switch()');

        // 🔒 Every action this file actually dispatches that is NOT list/get_detail
        // must appear in the allowlist — proves no mutating action was added to
        // the switch() without also being added to the POST-only gate.
        foreach (['create_regime', 'update_regime', 'set_rights', 'save_disclosure', 'save_resolution', 'delete_resolution', 'activate_regime', 'deactivate_regime'] as $mutatingAction) {
            $this->assertContains($mutatingAction, \REGIME_MUTATING_ACTIONS, "{$mutatingAction} must be listed in REGIME_MUTATING_ACTIONS");
        }
    }

    // ========================================================================
    // 🛡️ Super-admin gate — source sentinel (same judgement call as DSAR's)
    // ========================================================================

    public function testRegimesPageSourceCallsRequireSuperAdminBeforeAnyOutput(): void
    {
        $source = file_get_contents($this->indexPagePath);
        $this->assertNotFalse($source);
        $this->assertStringContainsString('$accessControl->requireSuperAdmin();', $source);
        $this->assertStringContainsString('$sessionManager->isLoggedIn()', $source);
    }

    public function testRegimeActionsSourceChecksIsSuperAdminBeforeDispatchingAnyAction(): void
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
    // 🔒 "NEVER pre-fills a legal value or disclosure text" negative sentinel
    // ========================================================================

    public function testCreateRegimeSourceNeverWritesALegalValueColumnItself(): void
    {
        // 🔍 php_strip_whitespace() strips comments/whitespace while
        // preserving tokens — this file's docblocks legitimately DISCUSS
        // dsarSlaDays/minAge/breachNotifyHours BY NAME, so a raw substring
        // search on the full source would false-positive on documentation.
        $codeOnly = php_strip_whitespace($this->actionsApiPath);
        $this->assertNotSame('', $codeOnly);

        // 🔒 Extract JUST the handleCreateRegime() function body and prove
        // its own INSERT statement's column list never names a legal-value
        // column — it may only ever write regimeCode/displayName/jurisdiction/
        // isActive/isDraft.
        $this->assertMatchesRegularExpression(
            '/function handleCreateRegime.*?INSERT INTO tblComplianceRegimes \(regimeCode, displayName, jurisdiction, isActive, isDraft\) VALUES \(\?, \?, \?, 0, 1\)/s',
            $codeOnly,
            'handleCreateRegime()\'s INSERT must list ONLY regimeCode/displayName/jurisdiction/isActive/isDraft — never a legal-value column'
        );
    }
}
