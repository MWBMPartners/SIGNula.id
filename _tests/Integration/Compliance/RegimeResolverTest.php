<?php
/**
 * ============================================================================
 * 🧪 RegimeResolver Integration Tests (G-004 Layer 3 §4 — the data-driven core)
 * ============================================================================
 *
 * DB-bound proof that RegimeResolver actually resolves regimes out of
 * REAL rows in tblComplianceRegimes / tblRegimeRights / tblRegimeResolution
 * (migration 042) against a real MySQL server (signula_test), AND — the
 * headline proof for this cycle — that retro-wiring DSARManager::submit()
 * and ConsentManager::record() through it is a BEHAVIOURAL NO-OP against
 * the shipped state (every migration-042 seed regime is `isActive = 0`).
 *
 * ⚠️ Test-fixture hygiene (IMPORTANT — read before adding more tests here):
 * this class deliberately does NOT put tblComplianceRegimes / tblRegimeRights
 * / tblRegimeResolution in $truncateTables. Those tables carry the REAL
 * migration-042 seed data (19 regimes an operator may already be reviewing)
 * — truncating them as a side effect of "just running the test suite" would
 * be a nasty surprise. Instead, every test-fixture regime/rule this class
 * creates uses a `ZZTEST`-prefixed regimeCode (guaranteed to never collide
 * with a real seeded code — none of the 19 real codes start with `ZZ`) and
 * is explicitly DELETEd in tearDown() via insertTestRegime()/
 * insertTestResolutionRule()'s tracking arrays. tblRegimeRights rows are
 * cleaned up automatically (ON DELETE CASCADE from tblComplianceRegimes).
 *
 * @package    SIGNula\Tests\Integration\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/RegimeResolver.php
 * @see        web/private_html/compliance/DSARManager.php   — retro-wired call site
 * @see        web/private_html/compliance/ConsentManager.php — retro-wired call site
 * @see        _database/migrations/042_compliance_regimes.sql
 * @see        .dev-team/specs/G-004.md §4.1, §4.2, §4.4
 */

namespace SIGNula\Tests\Integration\Compliance;

use SIGNula\Tests\DatabaseTestCase;
use RegimeResolver;
use DSARManager;
use ConsentManager;

requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/compliance/RegimeResolver.php');
requireSource('private_html/compliance/ConsentManager.php');
requireSource('private_html/compliance/InvalidDSARTransitionException.php');
requireSource('private_html/compliance/DSARManager.php');

/**
 * 🛡️ RegimeResolver round-trip test suite + the L3 retro-wire regression proof.
 */
class RegimeResolverTest extends DatabaseTestCase
{
    /**
     * @var array<int,string> Tables reset before each test — deliberately
     *  EXCLUDES tblComplianceRegimes/tblRegimeRights/tblRegimeResolution
     *  (see class docblock).
     */
    protected array $truncateTables = [
        'tblDataSubjectRequests',
        'tblDataSubjectRequestEvents',
        'tblConsentRecords',
        'tblActivityLog',
        'tblUsers',
    ];

    /** @var array<int,int> tblComplianceRegimes.regimeID rows this test created. */
    private array $testRegimeIDsToCleanUp = [];

    /** @var array<int,int> tblRegimeResolution.ruleID rows this test created. */
    private array $testRuleIDsToCleanUp = [];

    protected function tearDown(): void
    {
        // 🧹 Explicit cleanup (NOT truncateTables — see class docblock): only
        // ever removes the rows THIS test created, by exact ID, leaving the
        // real migration-042 seed data completely undisturbed.
        foreach ($this->testRuleIDsToCleanUp as $ruleID) {
            \Database::query("DELETE FROM tblRegimeResolution WHERE ruleID = ?", [$ruleID], 'i');
        }
        foreach ($this->testRegimeIDsToCleanUp as $regimeID) {
            // tblRegimeRights cascades on regimeID delete (migration 042 FK).
            \Database::query("DELETE FROM tblComplianceRegimes WHERE regimeID = ?", [$regimeID], 'i');
        }

        parent::tearDown();
    }

    // ========================================================================
    // 🔧 Test-fixture helpers
    // ========================================================================

    /**
     * 🔧 Insert a test-only regime row (via Database::, the SAME connection
     * RegimeResolver/DSARManager/ConsentManager read from — NOT $this->db,
     * which is a separate mysqli session; see this project's
     * DatabaseTestCase "implicit-COMMIT" note replicated across every
     * existing Compliance Integration test file).
     *
     * @param array<string,mixed> $overrides
     * @return array{regimeID:int, regimeCode:string}
     */
    private function insertTestRegime(array $overrides = []): array
    {
        $defaults = [
            'regimeCode'            => 'ZZTEST' . strtoupper(bin2hex(random_bytes(3))),
            'displayName'           => 'Integration Test Regime',
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
     * 🔧 Insert a test-only tblRegimeRights row.
     */
    private function insertTestRight(int $regimeID, string $right): void
    {
        \Database::query(
            "INSERT INTO tblRegimeRights (regimeID, `right`) VALUES (?, ?)",
            [$regimeID, $right],
            'is'
        );
        // Cascades with its parent regime — no separate cleanup tracking needed.
    }

    /**
     * 🔧 Insert a test-only tblRegimeResolution rule.
     */
    private function insertTestResolutionRule(string $matchType, ?string $matchValue, ?int $regimeID, int $priority): int
    {
        $ruleID = \Database::insert(
            "INSERT INTO tblRegimeResolution (matchType, matchValue, regimeID, priority) VALUES (?, ?, ?, ?)",
            [$matchType, $matchValue, $regimeID, $priority],
            'ssii'
        );

        $this->testRuleIDsToCleanUp[] = $ruleID;

        return $ruleID;
    }

    // ========================================================================
    // 🗺️ resolve() — real rows, real matching
    // ========================================================================

    public function testResolveReturnsTheActiveRegimeMatchedByAPartnerRule(): void
    {
        $regime = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 0, 'dsarSlaDays' => 45, 'minAge' => 18]);
        $partnerID = random_int(100000, 199999);
        $this->insertTestResolutionRule('partner', (string) $partnerID, $regime['regimeID'], 10);

        $resolved = RegimeResolver::resolve(null, $partnerID, null);

        $this->assertFalse($resolved['isDefault']);
        $this->assertSame($regime['regimeCode'], $resolved['regimeCode']);
        $this->assertSame(45, $resolved['dsarSlaDays']);
        $this->assertSame(18, $resolved['minAge']);
    }

    public function testResolveReturnsTheActiveRegimeMatchedByACountryRuleCaseInsensitively(): void
    {
        $regime = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 0, 'dsarSlaDays' => 21]);
        $countryCode = 'Z' . strtoupper(substr(bin2hex(random_bytes(1)), 0, 1));
        $this->insertTestResolutionRule('country', $countryCode, $regime['regimeID'], 20);

        // 🔎 Caller passes lowercase — resolve() must normalise before matching.
        $resolved = RegimeResolver::resolve(null, null, strtolower($countryCode));

        $this->assertSame($regime['regimeCode'], $resolved['regimeCode']);
        $this->assertSame(21, $resolved['dsarSlaDays']);
    }

    public function testResolveNeverReturnsADraftRegimeEvenWhenItsRuleMatches(): void
    {
        // 🚫 isActive=1 but isDraft=1 — NOT confirmed yet, must never resolve.
        $draftRegime = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 1, 'dsarSlaDays' => 99]);
        $partnerID = random_int(200000, 299999);
        $this->insertTestResolutionRule('partner', (string) $partnerID, $draftRegime['regimeID'], 5);

        $resolved = RegimeResolver::resolve(null, $partnerID, null);

        $this->assertTrue($resolved['isDefault'], 'A draft regime must NEVER be returned by resolve(), even when its rule matches');
        $this->assertNull($resolved['regimeCode']);
        $this->assertSame(30, $resolved['dsarSlaDays'], 'Must fall back to the most-protective default SLA');
    }

    public function testResolveNeverReturnsAnInactiveNonDraftRegimeEvenWhenItsRuleMatches(): void
    {
        // 🚫 isActive=0, isDraft=0 — an odd but possible combination (e.g. an
        // operator who deactivated a previously-confirmed regime). Still
        // must never resolve.
        $inactiveRegime = $this->insertTestRegime(['isActive' => 0, 'isDraft' => 0, 'dsarSlaDays' => 99]);
        $partnerID = random_int(300000, 399999);
        $this->insertTestResolutionRule('partner', (string) $partnerID, $inactiveRegime['regimeID'], 5);

        $resolved = RegimeResolver::resolve(null, $partnerID, null);

        $this->assertTrue($resolved['isDefault']);
        $this->assertNull($resolved['regimeCode']);
    }

    public function testResolveSkipsAHigherPriorityRuleTargetingAnInactiveRegimeAndFallsThroughToTheNextMatch(): void
    {
        $draftRegime = $this->insertTestRegime(['isActive' => 0, 'isDraft' => 1, 'dsarSlaDays' => 99]);
        $activeRegime = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 0, 'dsarSlaDays' => 12]);

        $partnerID = random_int(400000, 499999);
        $countryCode = 'Y' . strtoupper(substr(bin2hex(random_bytes(1)), 0, 1));

        // 🔢 Lower priority number = evaluated FIRST. The draft regime's rule
        // has priority 1 (would win if regime status were ignored); the
        // active regime's rule has priority 50 (evaluated later).
        $this->insertTestResolutionRule('partner', (string) $partnerID, $draftRegime['regimeID'], 1);
        $this->insertTestResolutionRule('country', $countryCode, $activeRegime['regimeID'], 50);

        // 🎯 Both rules apply (partnerID AND countryHint both match) — proves
        // resolve() skips the higher-priority-but-inactive match and keeps
        // walking to the lower-priority ACTIVE one, never failing open.
        $resolved = RegimeResolver::resolve(null, $partnerID, $countryCode);

        $this->assertSame($activeRegime['regimeCode'], $resolved['regimeCode']);
        $this->assertSame(12, $resolved['dsarSlaDays']);
    }

    public function testResolveReturnsTheSyntheticDefaultWhenNoRuleMatches(): void
    {
        // 🌫️ No partnerID/countryHint supplied — only the seeded 'default'
        // rule (regimeID=NULL) can apply, which never yields an active
        // regime by design (see migration 042's tblRegimeResolution comment).
        $resolved = RegimeResolver::resolve(null, null, null);

        $this->assertTrue($resolved['isDefault']);
        $this->assertNull($resolved['regimeCode']);
        $this->assertSame(30, $resolved['dsarSlaDays']);
    }

    // ========================================================================
    // 📐 slaDaysFor() / minAgeFor() / breachWindowHours() / honorsGPC() —
    // per-code accessors against real rows
    // ========================================================================

    public function testSlaDaysForReturnsTheActiveRegimesConfiguredValue(): void
    {
        $regime = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 0, 'dsarSlaDays' => 45]);
        $this->assertSame(45, RegimeResolver::slaDaysFor($regime['regimeCode']));
    }

    public function testSlaDaysForFallsBackToDefaultForADraftRegimeDespiteHavingAConfiguredValue(): void
    {
        // 🔒 Proves the "never leaks an unconfirmed regime's value" contract
        // — a draft regime's dsarSlaDays=99 must NEVER surface via slaDaysFor().
        $draftRegime = $this->insertTestRegime(['isActive' => 0, 'isDraft' => 1, 'dsarSlaDays' => 99]);
        $this->assertSame(30, RegimeResolver::slaDaysFor($draftRegime['regimeCode']));
    }

    public function testSlaDaysForFallsBackToDefaultWhenTheActiveRegimesSlaIsItselfNull(): void
    {
        // 🔒 An operator could activate a regime before every legal field is
        // set (nothing in THIS cycle enforces the future admin-CRUD's
        // "cannot activate until SLA is non-NULL" workflow) — slaDaysFor()
        // must still degrade safely rather than returning/casting NULL.
        $regime = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 0, 'dsarSlaDays' => null]);
        $this->assertSame(30, RegimeResolver::slaDaysFor($regime['regimeCode']));
    }

    public function testMinAgeForReturnsTheActiveRegimesConfiguredValue(): void
    {
        $regime = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 0, 'minAge' => 21]);
        $this->assertSame(21, RegimeResolver::minAgeFor($regime['regimeCode']));
    }

    public function testBreachWindowHoursReturnsNullWhenUnsetOnAnActiveRegime(): void
    {
        $regime = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 0, 'breachNotifyHours' => null]);
        $this->assertNull(RegimeResolver::breachWindowHours($regime['regimeCode']));
    }

    public function testBreachWindowHoursReturnsTheActiveRegimesConfiguredValue(): void
    {
        $regime = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 0, 'breachNotifyHours' => 72]);
        $this->assertSame(72, RegimeResolver::breachWindowHours($regime['regimeCode']));
    }

    public function testHonorsGpcReflectsTheActiveRegimesFlag(): void
    {
        $honoring = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 0, 'honorsGPC' => 1]);
        $this->assertTrue(RegimeResolver::honorsGPC($honoring['regimeCode']));

        $notHonoring = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 0, 'honorsGPC' => 0]);
        $this->assertFalse(RegimeResolver::honorsGPC($notHonoring['regimeCode']));
    }

    public function testRightsForReturnsTheSeededRightsForAnActiveRegimeSortedAlphabetically(): void
    {
        $regime = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 0]);
        $this->insertTestRight($regime['regimeID'], 'erasure');
        $this->insertTestRight($regime['regimeID'], 'access');
        $this->insertTestRight($regime['regimeID'], 'portability');

        $this->assertSame(['access', 'erasure', 'portability'], RegimeResolver::rightsFor($regime['regimeCode']));
    }

    public function testRightsForReturnsEmptyArrayForADraftRegimeEvenIfRightsRowsExist(): void
    {
        $draftRegime = $this->insertTestRegime(['isActive' => 0, 'isDraft' => 1]);
        $this->insertTestRight($draftRegime['regimeID'], 'access');

        $this->assertSame([], RegimeResolver::rightsFor($draftRegime['regimeCode']), 'A draft regime\'s rights must never surface');
    }

    // ========================================================================
    // 🌍 THE RETRO-WIRE REGRESSION PROOF — shipped state (all-inactive seed)
    // ========================================================================

    /**
     * 🔒 THE headline regression test for this cycle. Uses the REAL,
     * migration-042-shipped tblComplianceRegimes state (19 regimes, every
     * one isActive=0) — no test fixture regime is inserted here on purpose.
     * Proves DSARManager::submit()'s retro-wire through RegimeResolver is a
     * behavioural no-op TODAY: dueDate is still submittedAt+30 and
     * regimeCode is still NULL, identical to pre-L3 behaviour.
     */
    public function testDsarSubmissionInTheShippedAllInactiveStateStillGetsDueDatePlus30AndNullRegimeCode(): void
    {
        $user = $this->createTestUser();

        $requestID = DSARManager::submit([
            'userID'      => $user['userID'],
            'requestType' => 'access',
        ]);

        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertNotNull($row);
        $this->assertNull($row['regimeCode'], 'regimeCode must stay NULL — every migration-042 seed regime ships isActive=0');

        $expectedDueDate = date('Y-m-d', strtotime('+30 days'));
        $this->assertSame($expectedDueDate, $row['dueDate'], 'dueDate must still be submittedAt + the default 30-day SLA');
    }

    /**
     * 🔒 Same regression proof for ConsentManager::record() — the OTHER
     * retro-wired call site.
     */
    public function testConsentRecordInTheShippedAllInactiveStateStillGetsNullRegimeCode(): void
    {
        $user = $this->createTestUser();

        $consentID = ConsentManager::record($user['userID'], null, 'cookies.analytics', true, ['mechanism' => 'preference_center']);

        $row = $this->getRecord('tblConsentRecords', ['consentID' => $consentID]);
        $this->assertNotNull($row);
        $this->assertNull($row['regimeCode'], 'regimeCode must stay NULL in the shipped all-inactive-regime state');
    }

    /**
     * ✅ An explicitly-passed regimeCode must ALWAYS win over the resolver,
     * regardless of what (if anything) resolves — proves record()'s
     * `$opts['regimeCode'] ?? self::resolveRegimeCode($userID)` priority.
     */
    public function testConsentRecordHonoursAnExplicitlyPassedRegimeCodeOverTheResolver(): void
    {
        $user = $this->createTestUser();

        // 🔒 tblConsentRecords.regimeCode is VARCHAR(16) (migration 040) —
        // must fit within that width.
        $consentID = ConsentManager::record(
            $user['userID'],
            null,
            'cookies.analytics',
            true,
            ['mechanism' => 'preference_center', 'regimeCode' => 'EXPLICIT_TEST']
        );

        $row = $this->getRecord('tblConsentRecords', ['consentID' => $consentID]);
        $this->assertSame('EXPLICIT_TEST', $row['regimeCode']);
    }

    // ========================================================================
    // 🌍 THE RETRO-WIRE, ACTIVATED — resolver actually drives the DSAR SLA
    // ========================================================================

    /**
     * ✅ With a fixture regime activated + a matching resolution rule, a DSAR
     * submitted with a matching partnerID gets its dueDate/regimeCode
     * computed from THAT regime — proves the retro-wire actually changes
     * behaviour once an operator activates a regime (not just that it's
     * inert today).
     */
    public function testDsarSubmissionWithAnActiveMatchingRegimeUsesItsSlaAndStampsItsRegimeCode(): void
    {
        $regime = $this->insertTestRegime(['isActive' => 1, 'isDraft' => 0, 'dsarSlaDays' => 10]);
        $partnerID = random_int(500000, 599999);
        $this->insertTestResolutionRule('partner', (string) $partnerID, $regime['regimeID'], 1);

        $user = $this->createTestUser();

        $requestID = DSARManager::submit([
            'userID'      => $user['userID'],
            'requestType' => 'access',
            'partnerID'   => $partnerID,
        ]);

        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertSame($regime['regimeCode'], $row['regimeCode']);

        $expectedDueDate = date('Y-m-d', strtotime('+10 days'));
        $this->assertSame($expectedDueDate, $row['dueDate']);
    }

    /**
     * ✅ A DSAR submitted with a partnerID that matches NO resolution rule
     * still falls back correctly (proves the retro-wire's failure path is
     * the safe default, not an error).
     */
    public function testDsarSubmissionWithANonMatchingPartnerIdStillFallsBackToTheDefaultSla(): void
    {
        $user = $this->createTestUser();

        $requestID = DSARManager::submit([
            'userID'      => $user['userID'],
            'requestType' => 'access',
            'partnerID'   => random_int(900000, 999999), // matches no seeded/fixture rule
        ]);

        $row = $this->getRecord('tblDataSubjectRequests', ['requestID' => $requestID]);
        $this->assertNull($row['regimeCode']);
        $this->assertSame(date('Y-m-d', strtotime('+30 days')), $row['dueDate']);
    }
}
