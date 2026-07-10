<?php
/**
 * ============================================================================
 * 🧪 ConsentCategoryService Integration Tests (G-004 Layer 2 — Consent-Management Surface)
 * ============================================================================
 *
 * DB-bound proof that ConsentCategoryService's Layer-2 surface works against
 * a REAL MySQL server (signula_test), backed by migration 041
 * (tblConsentCategories, tblPolicyVersions):
 *
 *   1. getActiveCategories() returns the four seeded categories, ordered,
 *      with 'necessary' correctly flagged isStrictlyNecessary.
 *   2. getCurrentPolicyVersion() resolves the seeded draft anchor rows.
 *   3. recordBannerChoices() forces 'necessary' granted=true EVEN when the
 *      caller's input tries to opt it out — proven by writing a real
 *      tblConsentRecords row via the SAME ConsentManager seam Layer 1 uses.
 *   4. userNeedsReconsent() true/false/never-consented-yet tolerance.
 *   5. maybeReconsentRedirect() end-to-end with enforcement ON.
 *   6. honorGpcSignalIfPresent() records a real do_not_sell opt-out row and
 *      is idempotent both via the session guard AND via the DB-level
 *      getCurrent() check (two independent proofs, per the class's own
 *      documented two-layer guard).
 *   7. The settings/privacy.php `set_do_not_sell` action's EXACT call shape
 *      against a real ConsentManager/Database round-trip.
 *
 * @package    SIGNula\Tests\Integration\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/ConsentCategoryService.php
 * @see        web/private_html/compliance/ConsentManager.php
 * @see        _database/migrations/041_consent_categories_and_policy_versions.sql
 * @see        .dev-team/specs/G-004.md §3
 */

namespace SIGNula\Tests\Integration\Compliance;

use SIGNula\Tests\DatabaseTestCase;
use ConsentCategoryService;
use ConsentManager;

requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/compliance/ConsentManager.php');
requireSource('private_html/compliance/ConsentCategoryService.php');

/**
 * 🍪 ConsentCategoryService round-trip test suite.
 */
class ConsentCategoryServiceTest extends DatabaseTestCase
{
    /** @var array<int,string> Tables reset before each test. */
    protected array $truncateTables = [
        'tblConsentRecords',
        'tblActivityLog',
        'tblUsers',
    ];

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_SEC_GPC']);
        unset($_COOKIE['SIGNULA_VID']);

        parent::tearDown();
    }

    // ========================================================================
    // 🗂️ getActiveCategories() / getCurrentPolicyVersion()
    // ========================================================================

    public function testGetActiveCategoriesReturnsTheFourSeededCategoriesInOrder(): void
    {
        $categories = ConsentCategoryService::getActiveCategories();

        $this->assertCount(4, $categories, 'Migration 041 seeds exactly 4 active categories');

        $keys = array_map(static fn (array $row): string => $row['categoryKey'], $categories);
        $this->assertSame(['necessary', 'functional', 'analytics', 'marketing'], $keys, 'Must be ordered by displayOrder ASC');

        $this->assertSame('1', (string) $categories[0]['isStrictlyNecessary'], "'necessary' must be flagged strictly necessary");
        foreach (array_slice($categories, 1) as $nonNecessary) {
            $this->assertSame('0', (string) $nonNecessary['isStrictlyNecessary'], "'{$nonNecessary['categoryKey']}' must NOT be strictly necessary");
        }
    }

    public function testGetCurrentPolicyVersionResolvesTheSeededDraftAnchors(): void
    {
        foreach (['terms', 'privacy', 'cookies'] as $policyKey) {
            $current = ConsentCategoryService::getCurrentPolicyVersion($policyKey);
            $this->assertNotNull($current, "A current version anchor must exist for '{$policyKey}'");
            $this->assertSame('1.0', $current['version']);
        }
    }

    public function testGetCurrentPolicyVersionReturnsNullForAnUnrecognisedKey(): void
    {
        $this->assertNull(ConsentCategoryService::getCurrentPolicyVersion('not_a_real_policy'));
    }

    // ========================================================================
    // 📝 recordBannerChoices() — server-side 'necessary' enforcement
    // ========================================================================

    public function testRecordBannerChoicesForcesNecessaryGrantedEvenWhenInputTriesToOptItOut(): void
    {
        $user = $this->createTestUser();

        // 🔓 Attacker/buggy-client input trying to opt OUT of the necessary
        // category — must be ignored server-side.
        ConsentCategoryService::recordBannerChoices(
            ['necessary' => false, 'functional' => true, 'analytics' => false, 'marketing' => false],
            $user['userID'],
            null
        );

        $effective = ConsentManager::getEffectiveConsents($user['userID'], null);

        $this->assertTrue($effective['cookies.necessary'] ?? null, "'cookies.necessary' MUST be granted=true regardless of caller input");
        $this->assertTrue($effective['cookies.functional'] ?? null);
        $this->assertFalse($effective['cookies.analytics'] ?? null);
        $this->assertFalse($effective['cookies.marketing'] ?? null);
    }

    public function testRecordBannerChoicesTreatsAMissingCategoryKeyAsNotGranted(): void
    {
        $user = $this->createTestUser();

        // 🈳 No 'marketing' key at all in the input.
        ConsentCategoryService::recordBannerChoices(['functional' => true], $user['userID'], null);

        $effective = ConsentManager::getEffectiveConsents($user['userID'], null);
        $this->assertFalse($effective['cookies.marketing'] ?? null);
    }

    public function testRecordBannerChoicesStampsThePolicyVersionFromTheCurrentCookiesAnchor(): void
    {
        $user = $this->createTestUser();

        ConsentCategoryService::recordBannerChoices(['functional' => true], $user['userID'], null);

        $row = ConsentManager::getCurrent($user['userID'], null, 'cookies.functional');
        $this->assertNotNull($row);
        $this->assertSame('1.0', $row['policyVersion'], 'Must stamp the CURRENT cookies policy version anchor');
        $this->assertSame('banner', $row['mechanism']);
    }

    // ========================================================================
    // 🔄 userNeedsReconsent() / maybeReconsentRedirect()
    // ========================================================================

    public function testUserNeedsReconsentIsFalseForAUserWithNoPriorConsentAtAll(): void
    {
        $user = $this->createTestUser();

        $this->assertFalse(
            ConsentCategoryService::userNeedsReconsent($user['userID']),
            'A brand-new user with NO recorded terms/privacy consent must never be trapped — see the method docblock'
        );
    }

    public function testUserNeedsReconsentIsFalseWhenTheRecordedVersionMatchesCurrent(): void
    {
        $user = $this->createTestUser();

        ConsentManager::record($user['userID'], null, 'terms', true, ['mechanism' => 'signup', 'policyVersion' => '1.0']);
        ConsentManager::record($user['userID'], null, 'privacy', true, ['mechanism' => 'signup', 'policyVersion' => '1.0']);

        $this->assertFalse(ConsentCategoryService::userNeedsReconsent($user['userID']));
    }

    public function testUserNeedsReconsentIsTrueWhenTheRecordedVersionIsStale(): void
    {
        $user = $this->createTestUser();

        ConsentManager::record($user['userID'], null, 'terms', true, ['mechanism' => 'signup', 'policyVersion' => '0.9-old']);
        ConsentManager::record($user['userID'], null, 'privacy', true, ['mechanism' => 'signup', 'policyVersion' => '1.0']);

        $this->assertTrue(
            ConsentCategoryService::userNeedsReconsent($user['userID']),
            "A stale 'terms' policyVersion alone must trigger re-consent"
        );
    }

    public function testMaybeReconsentRedirectReturnsNullWhenEnforcementIsOffEvenIfUserIsStale(): void
    {
        $user = $this->createTestUser();
        setTestSetting('consent.reconsent.enforce', '0');

        ConsentManager::record($user['userID'], null, 'terms', true, ['mechanism' => 'signup', 'policyVersion' => 'ancient']);

        $this->assertNull(
            ConsentCategoryService::maybeReconsentRedirect($user['userID'], '/dashboard'),
            'The gate MUST stay inert (return null) while consent.reconsent.enforce=0, regardless of staleness'
        );
    }

    public function testMaybeReconsentRedirectReturnsTheRedirectUrlWhenEnforcementIsOnAndUserIsStale(): void
    {
        $user = $this->createTestUser();
        setTestSetting('consent.reconsent.enforce', '1');

        ConsentManager::record($user['userID'], null, 'terms', true, ['mechanism' => 'signup', 'policyVersion' => 'ancient']);

        $redirect = ConsentCategoryService::maybeReconsentRedirect($user['userID'], '/dashboard');

        $this->assertNotNull($redirect);
        $this->assertStringStartsWith('/legal/reconsent?redirect=', $redirect);
        $this->assertStringContainsString(urlencode('/dashboard'), $redirect);
    }

    public function testMaybeReconsentRedirectReturnsNullWhenEnforcementIsOnButUserIsCurrent(): void
    {
        $user = $this->createTestUser();
        setTestSetting('consent.reconsent.enforce', '1');

        ConsentManager::record($user['userID'], null, 'terms', true, ['mechanism' => 'signup', 'policyVersion' => '1.0']);
        ConsentManager::record($user['userID'], null, 'privacy', true, ['mechanism' => 'signup', 'policyVersion' => '1.0']);

        $this->assertNull(ConsentCategoryService::maybeReconsentRedirect($user['userID'], '/dashboard'));
    }

    // ========================================================================
    // 🌐 honorGpcSignalIfPresent() — real DB round-trip + BOTH idempotency layers
    // ========================================================================

    public function testHonorGpcSignalRecordsADoNotSellOptOutWithGpcSignalMechanism(): void
    {
        $user = $this->createTestUser();
        $_SERVER['HTTP_SEC_GPC'] = '1';
        setTestSetting('consent.gpc.honor', '1');

        ConsentCategoryService::honorGpcSignalIfPresent($user['userID'], null);

        $row = ConsentManager::getCurrent($user['userID'], null, 'do_not_sell');
        $this->assertNotNull($row, 'A do_not_sell row must have been recorded');
        $this->assertSame('0', (string) $row['granted'], 'granted=false means opted OUT');
        $this->assertSame('gpc_signal', $row['mechanism']);
    }

    public function testHonorGpcSignalIsIdempotentViaTheSessionGuardAcrossTwoCallsInOneSession(): void
    {
        $user = $this->createTestUser();
        $_SERVER['HTTP_SEC_GPC'] = '1';
        setTestSetting('consent.gpc.honor', '1');

        ConsentCategoryService::honorGpcSignalIfPresent($user['userID'], null);
        ConsentCategoryService::honorGpcSignalIfPresent($user['userID'], null); // 🔁 second call, SAME session

        $count = $this->countRecords('tblConsentRecords', ['userID' => $user['userID'], 'consentType' => 'do_not_sell']);
        $this->assertSame(1, $count, 'A second call in the same session must NOT insert a duplicate row');
    }

    public function testHonorGpcSignalIsIdempotentAtTheDatabaseLevelEvenWithoutTheSessionGuard(): void
    {
        // 🩹 Belt-and-braces proof: bypass the session guard between calls
        // (simulating two DIFFERENT sessions for the SAME user) and confirm
        // the getCurrent() pre-check still prevents a duplicate opt-out row.
        $user = $this->createTestUser();
        $_SERVER['HTTP_SEC_GPC'] = '1';
        setTestSetting('consent.gpc.honor', '1');

        ConsentCategoryService::honorGpcSignalIfPresent($user['userID'], null);
        unset($_SESSION['gpc_honored']); // simulate a fresh session

        ConsentCategoryService::honorGpcSignalIfPresent($user['userID'], null);

        $count = $this->countRecords('tblConsentRecords', ['userID' => $user['userID'], 'consentType' => 'do_not_sell']);
        $this->assertSame(1, $count, 'Even across two "sessions", the DB-level getCurrent() check must prevent a duplicate row');
    }

    public function testHonorGpcSignalDoesNotDuplicateAnAlreadyRecordedManualOptOut(): void
    {
        // 🧑‍💻 The subject already opted out manually (e.g. via
        // settings/privacy.php's set_do_not_sell action) BEFORE any GPC
        // signal arrives — GPC must not add a second row.
        $user = $this->createTestUser();
        ConsentManager::record($user['userID'], null, 'do_not_sell', false, ['mechanism' => 'preference_center']);

        $_SERVER['HTTP_SEC_GPC'] = '1';
        setTestSetting('consent.gpc.honor', '1');
        ConsentCategoryService::honorGpcSignalIfPresent($user['userID'], null);

        $count = $this->countRecords('tblConsentRecords', ['userID' => $user['userID'], 'consentType' => 'do_not_sell']);
        $this->assertSame(1, $count);
    }

    // ========================================================================
    // 🚫 settings/privacy.php `set_do_not_sell` action — EXACT call shape
    // ========================================================================

    public function testSetDoNotSellActionCallShapeRecordsAPreferenceCenterOptOut(): void
    {
        $user = $this->createTestUser();

        // 🩹 EXACT shape settings/privacy.php's set_do_not_sell branch uses:
        // ConsentManager::record($userID, null, 'do_not_sell', $granted, ['mechanism' => 'preference_center'])
        // where $granted = ($_POST['granted'] ?? '') === '1'. The toggle
        // button always posts the OPPOSITE of the current opted-out state —
        // simulate a user who is NOT currently opted out clicking "Opt Out"
        // (posts granted=0).
        $doNotSellGranted = ('0' === '1'); // mirrors the exact expression shape
        ConsentManager::record($user['userID'], null, 'do_not_sell', $doNotSellGranted, ['mechanism' => 'preference_center']);

        $current = ConsentManager::getCurrent($user['userID'], null, 'do_not_sell');
        $this->assertNotNull($current);
        $this->assertSame('0', (string) $current['granted']);
        $this->assertSame('preference_center', $current['mechanism']);
    }

    public function testSetDoNotSellActionSourceUsesTheCorrectAllowlistedActionNameAndCsrfGate(): void
    {
        // 🔎 Source sentinel — settings/privacy.php is a true HTTP endpoint
        // script (session + CSRF + full config bootstrap at the top level),
        // so it is not require-able in-process (mirrors the established
        // judgement call documented in DsarAdminActionsCallSiteTest.php).
        $privacyPagePath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'settings' . DIRECTORY_SEPARATOR . 'privacy.php';

        $source = file_get_contents($privacyPagePath);
        $this->assertNotFalse($source);

        $this->assertStringContainsString("\$action === 'set_do_not_sell'", $source);
        $this->assertStringContainsString(
            "ConsentManager::record(\$userID, null, 'do_not_sell', \$doNotSellGranted, ['mechanism' => 'preference_center']);",
            $source
        );

        // 🛡️ CSRF must be checked BEFORE the ConsentManager::record() call —
        // find both offsets and assert ordering.
        $actionOffset = strpos($source, "\$action === 'set_do_not_sell'");
        $csrfOffset = strpos($source, "verifyCSRFToken(\$_POST['csrf_token'] ?? '')", $actionOffset);
        $recordOffset = strpos($source, 'do_not_sell', $actionOffset + 30);

        $this->assertNotFalse($csrfOffset);
        $this->assertNotFalse($recordOffset);
        $this->assertLessThan($recordOffset, $csrfOffset, 'CSRF must be verified before the consent is recorded');
    }
}
