<?php
/**
 * ============================================================================
 * 🧪 ropa-actions.php / RoPA Admin CRUD Tests (G-004 Layer 4b §5.2)
 * ============================================================================
 * (the FINAL compliance slice, completes G-004)
 *
 * `web/public_html/admin/compliance/ropa/index.php` and
 * `web/public_html/admin/compliance/api/ropa-actions.php` are true HTTP
 * endpoint scripts — mirrors the SAME judgement call already documented for
 * this exact class of file (see
 * `_tests/Integration/Compliance/RegimeAdminActionsTest.php` /
 * `DsarAdminActionsCallSiteTest.php`): no `php -S` subprocess harness; instead
 *   1. GENUINE functional tests against the REAL, unmodified handler
 *      functions, made possible by ropa-actions.php's own
 *      SIGNULA_ROPA_ACTIONS_TEST_ONLY test seam.
 *   2. Security invariants (CSRF gate, super-admin gate, X-Frame-Options,
 *      POST-only enforcement) via source-content sentinels.
 *
 * @package    SIGNula\Tests\Integration\Compliance
 * @version    2.7.0-beta
 * @see        web/public_html/admin/compliance/ropa/index.php
 * @see        web/public_html/admin/compliance/api/ropa-actions.php
 * @see        _tests/Integration/Compliance/RegimeAdminActionsTest.php — the precedent this mirrors
 * @see        _database/migrations/044_breach_ropa_agegate.sql
 * @see        .dev-team/specs/G-004.md §5.2
 */

namespace SIGNula\Tests\Integration\Compliance;

use SIGNula\Tests\DatabaseTestCase;
use AccessControl;

requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('_backend/AccessControl.php');

// 🧪 Trip ropa-actions.php's test seam BEFORE requiring it — see
// regime-actions.php's own docblock (mirrored here) for the full rationale.
if (!defined('SIGNULA_ROPA_ACTIONS_TEST_ONLY')) {
    define('SIGNULA_ROPA_ACTIONS_TEST_ONLY', true);
}
require_once \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
    . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'api'
    . DIRECTORY_SEPARATOR . 'ropa-actions.php';

/**
 * 📋 RoPA admin CRUD test suite.
 */
class RopaAdminActionsTest extends DatabaseTestCase
{
    /** @var array<int,int> tblProcessingActivities.activityID rows this test created. */
    private array $testActivityIDsToCleanUp = [];

    private string $indexPagePath;
    private string $actionsApiPath;

    private AccessControl $accessControl;
    private int $adminUserID;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexPagePath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'ropa'
            . DIRECTORY_SEPARATOR . 'index.php';
        $this->actionsApiPath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'api'
            . DIRECTORY_SEPARATOR . 'ropa-actions.php';

        $admin = $this->createTestUser(['isSuperAdmin' => 1]);
        $this->adminUserID = (int) $admin['userID'];

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

        $this->accessControl = new AccessControl($this->db, $stubSessionManager);
    }

    protected function tearDown(): void
    {
        foreach ($this->testActivityIDsToCleanUp as $activityID) {
            \Database::query("DELETE FROM tblProcessingActivities WHERE activityID = ?", [$activityID], 'i');
        }

        parent::tearDown();
    }

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
    // ➕ handleRopaCreate() — never fabricates optional fields
    // ========================================================================

    public function testCreateShipsWithEveryOptionalFieldNullAndDefaultsIsActiveTrue(): void
    {
        $activityKey = 'zztest_' . bin2hex(random_bytes(4));

        $response = $this->captureJson(function () use ($activityKey): void {
            \handleRopaCreate(['activityKey' => $activityKey, 'name' => 'Test Activity'], $this->accessControl, $this->adminUserID);
        });

        $this->assertTrue($response['success']);
        $activityID = (int) $response['data']['activityID'];
        $this->testActivityIDsToCleanUp[] = $activityID;

        $row = \Database::fetchOne("SELECT * FROM tblProcessingActivities WHERE activityID = ?", [$activityID], 'i');
        $this->assertSame($activityKey, $row['activityKey']);
        $this->assertSame('Test Activity', $row['name']);
        $this->assertSame('1', (string) $row['isActive']);
        $this->assertNull($row['purpose'], 'handleRopaCreate() must never fabricate a purpose');
        $this->assertNull($row['lawfulBasis'], 'handleRopaCreate() must never fabricate a lawfulBasis');
        $this->assertNull($row['recipients']);
    }

    public function testCreateRejectsADuplicateActivityKey(): void
    {
        $activityKey = 'zztest_' . bin2hex(random_bytes(4));
        $first = $this->captureJson(function () use ($activityKey): void {
            \handleRopaCreate(['activityKey' => $activityKey, 'name' => 'First'], $this->accessControl, $this->adminUserID);
        });
        $this->testActivityIDsToCleanUp[] = (int) $first['data']['activityID'];

        $duplicate = $this->captureJson(function () use ($activityKey): void {
            \handleRopaCreate(['activityKey' => $activityKey, 'name' => 'Second'], $this->accessControl, $this->adminUserID);
        });
        $this->assertFalse($duplicate['success']);
        $this->assertStringContainsString('already exists', $duplicate['error']);
    }

    // ========================================================================
    // ✏️ handleRopaUpdate() — persists JSON-array + text fields verbatim
    // ========================================================================

    public function testUpdatePersistsTextAndJsonArrayFieldsVerbatim(): void
    {
        $activityKey = 'zztest_' . bin2hex(random_bytes(4));
        $created = $this->captureJson(function () use ($activityKey): void {
            \handleRopaCreate(['activityKey' => $activityKey, 'name' => 'Initial'], $this->accessControl, $this->adminUserID);
        });
        $activityID = (int) $created['data']['activityID'];
        $this->testActivityIDsToCleanUp[] = $activityID;

        $ownerText = 'We collect this data for "account management" & fraud prevention.';

        $response = $this->captureJson(function () use ($activityID, $ownerText): void {
            \handleRopaUpdate([
                'activityID'   => $activityID,
                'purpose'      => $ownerText,
                'lawfulBasis'  => 'Contract',
                'dataCategories' => ['email', 'name'],
                'recipients'   => ['Stripe', 'AWS'],
                'regimeCodes'  => ['GDPR'],
                'isActive'     => 0,
            ], $this->accessControl, $this->adminUserID);
        });

        $this->assertTrue($response['success']);

        $row = \Database::fetchOne("SELECT * FROM tblProcessingActivities WHERE activityID = ?", [$activityID], 'i');
        $this->assertSame($ownerText, $row['purpose'], 'purpose must be stored verbatim, not escaped at storage time');
        $this->assertSame('Contract', $row['lawfulBasis']);
        $this->assertSame(['email', 'name'], json_decode($row['dataCategories'], true));
        $this->assertSame(['Stripe', 'AWS'], json_decode($row['recipients'], true));
        $this->assertSame(['GDPR'], json_decode($row['regimeCodes'], true));
        $this->assertSame('0', (string) $row['isActive']);
    }

    public function testUpdateBlankingATextFieldClearsItBackToNull(): void
    {
        $activityKey = 'zztest_' . bin2hex(random_bytes(4));
        $created = $this->captureJson(function () use ($activityKey): void {
            \handleRopaCreate(['activityKey' => $activityKey, 'name' => 'Initial', 'purpose' => 'Something'], $this->accessControl, $this->adminUserID);
        });
        $activityID = (int) $created['data']['activityID'];
        $this->testActivityIDsToCleanUp[] = $activityID;

        $this->captureJson(function () use ($activityID): void {
            \handleRopaUpdate(['activityID' => $activityID, 'purpose' => '   '], $this->accessControl, $this->adminUserID);
        });

        $row = \Database::fetchOne("SELECT purpose FROM tblProcessingActivities WHERE activityID = ?", [$activityID], 'i');
        $this->assertNull($row['purpose']);
    }

    public function testUpdateReturnsFalseForANonexistentActivity(): void
    {
        $response = $this->captureJson(function (): void {
            \handleRopaUpdate(['activityID' => 999999999, 'name' => 'x'], $this->accessControl, $this->adminUserID);
        });
        $this->assertFalse($response['success']);
    }

    // ========================================================================
    // 🗑️ handleRopaDelete()
    // ========================================================================

    public function testDeleteRemovesTheRow(): void
    {
        $activityKey = 'zztest_' . bin2hex(random_bytes(4));
        $created = $this->captureJson(function () use ($activityKey): void {
            \handleRopaCreate(['activityKey' => $activityKey, 'name' => 'ToDelete'], $this->accessControl, $this->adminUserID);
        });
        $activityID = (int) $created['data']['activityID'];

        $response = $this->captureJson(function () use ($activityID): void {
            \handleRopaDelete(['activityID' => $activityID], $this->accessControl, $this->adminUserID);
        });
        $this->assertTrue($response['success']);
        $this->assertNull(\Database::fetchOne("SELECT * FROM tblProcessingActivities WHERE activityID = ?", [$activityID], 'i'));
    }

    // ========================================================================
    // 📋 handleRopaList() / handleRopaGetDetail()
    // ========================================================================

    public function testListAndGetDetailRoundTrip(): void
    {
        $activityKey = 'zztest_' . bin2hex(random_bytes(4));
        $created = $this->captureJson(function () use ($activityKey): void {
            \handleRopaCreate(['activityKey' => $activityKey, 'name' => 'Listable'], $this->accessControl, $this->adminUserID);
        });
        $activityID = (int) $created['data']['activityID'];
        $this->testActivityIDsToCleanUp[] = $activityID;

        $list = $this->captureJson(function (): void {
            \handleRopaList();
        });
        $this->assertTrue($list['success']);
        $keys = array_map(static fn(array $a) => $a['activityID'], $list['data']['activities']);
        $this->assertContains($activityID, array_map('intval', $keys));

        $_GET['activityID'] = (string) $activityID;
        $detail = $this->captureJson(function (): void {
            \handleRopaGetDetail();
        });
        unset($_GET['activityID']);
        $this->assertTrue($detail['success']);
        $this->assertSame($activityKey, $detail['data']['activity']['activityKey']);
    }

    // ========================================================================
    // 🛡️ CSRF / Super-admin gate / X-Frame-Options — source sentinels
    // ========================================================================

    public function testVerifyCsrfTokenRejectsAMissingOrBogusToken(): void
    {
        $this->assertFalse(\SecurityUtils::verifyCSRFToken(''));
        $this->assertFalse(\SecurityUtils::verifyCSRFToken('totally-bogus-token-value'));
    }

    public function testRopaActionsSourceGatesEveryPostRequestBehindCsrfVerificationBeforeAnyMutation(): void
    {
        $source = file_get_contents($this->actionsApiPath);
        $this->assertNotFalse($source);

        $this->assertStringContainsString("SecurityUtils::verifyCSRFToken(", $source);
        $csrfPos = strpos($source, 'SecurityUtils::verifyCSRFToken(');
        $switchPos = strpos($source, 'switch ($action)');
        $this->assertNotFalse($switchPos);
        $this->assertLessThan($switchPos, $csrfPos, 'CSRF verification must happen BEFORE the action dispatch switch()');
    }

    public function testRopaActionsSourceEnforcesPostOnlyForEveryMutatingActionBeforeDispatch(): void
    {
        $source = file_get_contents($this->actionsApiPath);
        $this->assertStringContainsString('ROPA_MUTATING_ACTIONS', $source);
        $this->assertStringContainsString("in_array(\$action, ROPA_MUTATING_ACTIONS, true) && \$_SERVER['REQUEST_METHOD'] !== 'POST'", $source);

        $postOnlyGatePos = strpos($source, "in_array(\$action, ROPA_MUTATING_ACTIONS, true) && \$_SERVER['REQUEST_METHOD'] !== 'POST'");
        $switchPos = strpos($source, 'switch ($action)');
        $this->assertLessThan($switchPos, $postOnlyGatePos);

        foreach (['create', 'update', 'delete'] as $mutatingAction) {
            $this->assertContains($mutatingAction, \ROPA_MUTATING_ACTIONS, "{$mutatingAction} must be listed in ROPA_MUTATING_ACTIONS");
        }
    }

    public function testRopaPageSourceCallsRequireSuperAdminBeforeAnyOutput(): void
    {
        $source = file_get_contents($this->indexPagePath);
        $this->assertStringContainsString('$accessControl->requireSuperAdmin();', $source);
        $this->assertStringContainsString('$sessionManager->isLoggedIn()', $source);
    }

    public function testRopaActionsSourceChecksIsSuperAdminBeforeDispatchingAnyAction(): void
    {
        $source = file_get_contents($this->actionsApiPath);
        $this->assertStringContainsString("\$userInfo['isSuperAdmin']", $source);

        $gatePos = strpos($source, 'isSuperAdmin');
        $switchPos = strpos($source, 'switch ($action)');
        $this->assertLessThan($switchPos, $gatePos);
    }

    public function testBothEndpointsSetXFrameOptionsDeny(): void
    {
        $this->assertStringContainsString("X-Frame-Options: DENY", file_get_contents($this->indexPagePath));
        $this->assertStringContainsString("X-Frame-Options: DENY", file_get_contents($this->actionsApiPath));
    }
}
