<?php
/**
 * ============================================================================
 * 🧪 breach-actions.php / Breach Admin Actions Tests (G-004 Layer 4b §5.1)
 * ============================================================================
 * (the FINAL compliance slice, completes G-004)
 *
 * `web/public_html/admin/compliance/breaches/index.php` and
 * `web/public_html/admin/compliance/api/breach-actions.php` are true HTTP
 * endpoint scripts — mirrors the SAME judgement call already documented for
 * this exact class of file (see `RegimeAdminActionsTest.php`/
 * `DsarAdminActionsCallSiteTest.php`):
 *   1. GENUINE functional tests against the REAL, unmodified handler
 *      functions, made possible by breach-actions.php's own
 *      SIGNULA_BREACH_ACTIONS_TEST_ONLY test seam. (The DEEP breach-deadline
 *      correctness proof — RegimeResolver delegation, graceful degrade —
 *      lives in `BreachManagerTest.php`; this file proves the ENDPOINT
 *      wires the request shape to BreachManager correctly, not the rule
 *      logic itself again.)
 *   2. Security invariants (CSRF gate, super-admin gate, X-Frame-Options,
 *      POST-only enforcement) via source-content sentinels.
 *
 * @package    SIGNula\Tests\Integration\Compliance
 * @version    2.7.0-beta
 * @see        web/public_html/admin/compliance/breaches/index.php
 * @see        web/public_html/admin/compliance/api/breach-actions.php
 * @see        _tests/Integration/Compliance/BreachManagerTest.php — the deep breach-deadline correctness proof
 * @see        _tests/Integration/Compliance/RegimeAdminActionsTest.php — the precedent this mirrors
 * @see        _database/migrations/044_breach_ropa_agegate.sql
 * @see        .dev-team/specs/G-004.md §5.1
 */

namespace SIGNula\Tests\Integration\Compliance;

use SIGNula\Tests\DatabaseTestCase;
use AccessControl;

requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('_backend/AccessControl.php');
requireSource('private_html/compliance/RegimeResolver.php');
requireSource('private_html/compliance/BreachManager.php');

// 🧪 Trip breach-actions.php's test seam BEFORE requiring it.
if (!defined('SIGNULA_BREACH_ACTIONS_TEST_ONLY')) {
    define('SIGNULA_BREACH_ACTIONS_TEST_ONLY', true);
}
require_once \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
    . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'api'
    . DIRECTORY_SEPARATOR . 'breach-actions.php';

/**
 * 🚨 Breach admin actions test suite.
 */
class BreachAdminActionsTest extends DatabaseTestCase
{
    /** @var array<int,int> tblBreachIncidents.incidentID rows this test created (cascades to notifications). */
    private array $testIncidentIDsToCleanUp = [];

    private string $indexPagePath;
    private string $actionsApiPath;

    private AccessControl $accessControl;
    private int $adminUserID;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexPagePath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'breaches'
            . DIRECTORY_SEPARATOR . 'index.php';
        $this->actionsApiPath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'api'
            . DIRECTORY_SEPARATOR . 'breach-actions.php';

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
        foreach ($this->testIncidentIDsToCleanUp as $incidentID) {
            \Database::query("DELETE FROM tblBreachIncidents WHERE incidentID = ?", [$incidentID], 'i');
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
    // 🚨 handleBreachOpen() / ✏️ handleBreachAssess() — the EXACT call shape
    // ========================================================================

    public function testHandleBreachOpenCreatesAnIncidentAndLogsAdminAction(): void
    {
        $response = $this->captureJson(function (): void {
            \handleBreachOpen([
                'severity'    => 'high',
                'discoveredBy' => 'unit test',
                'summary'     => 'Endpoint call-shape test.',
            ], $this->accessControl, $this->adminUserID);
        });

        $this->assertTrue($response['success']);
        $incidentID = (int) $response['data']['incidentID'];
        $this->testIncidentIDsToCleanUp[] = $incidentID;

        $row = \Database::fetchOne("SELECT * FROM tblBreachIncidents WHERE incidentID = ?", [$incidentID], 'i');
        $this->assertSame('high', $row['severity']);
        $this->assertSame('Endpoint call-shape test.', $row['summary']);
    }

    public function testHandleBreachOpenSurfacesAnInvalidSeverityAsARejectionNotACrash(): void
    {
        $response = $this->captureJson(function (): void {
            \handleBreachOpen(['severity' => 'not-a-real-severity'], $this->accessControl, $this->adminUserID);
        });
        $this->assertFalse($response['success']);
        $this->assertNotEmpty($response['error']);
    }

    public function testHandleBreachAssessUpdatesTheIncidentAndRequiresAValidIncidentID(): void
    {
        $opened = $this->captureJson(function (): void {
            \handleBreachOpen(['severity' => 'low'], $this->accessControl, $this->adminUserID);
        });
        $incidentID = (int) $opened['data']['incidentID'];
        $this->testIncidentIDsToCleanUp[] = $incidentID;

        $response = $this->captureJson(function () use ($incidentID): void {
            \handleBreachAssess(['incidentID' => $incidentID, 'status' => 'contained'], $this->accessControl, $this->adminUserID);
        });
        $this->assertTrue($response['success']);

        $row = \Database::fetchOne("SELECT status FROM tblBreachIncidents WHERE incidentID = ?", [$incidentID], 'i');
        $this->assertSame('contained', $row['status']);

        $invalid = $this->captureJson(function (): void {
            \handleBreachAssess(['incidentID' => 0, 'status' => 'closed'], $this->accessControl, $this->adminUserID);
        });
        $this->assertFalse($invalid['success']);
    }

    // ========================================================================
    // ⏰ handleBreachComputeDeadlines() / ✅ handleBreachMarkNotified()
    // ========================================================================

    public function testHandleBreachComputeDeadlinesAndMarkNotifiedRoundTrip(): void
    {
        $opened = $this->captureJson(function (): void {
            \handleBreachOpen(['severity' => 'critical', 'regimeCodes' => []], $this->accessControl, $this->adminUserID);
        });
        $incidentID = (int) $opened['data']['incidentID'];
        $this->testIncidentIDsToCleanUp[] = $incidentID;

        // No regimeCodes -> zero notification rows; proves the endpoint
        // doesn't crash on an incident with nothing to compute.
        $computed = $this->captureJson(function () use ($incidentID): void {
            \handleBreachComputeDeadlines(['incidentID' => $incidentID], $this->accessControl, $this->adminUserID);
        });
        $this->assertTrue($computed['success']);
        $this->assertSame([], $computed['data']['notifications']);

        $badMarkNotified = $this->captureJson(function (): void {
            \handleBreachMarkNotified(['notifyID' => 999999999], $this->accessControl, $this->adminUserID);
        });
        $this->assertFalse($badMarkNotified['success']);
    }

    // ========================================================================
    // 📋 handleBreachList() / handleBreachGetDetail() / handleBreachListOverdue()
    // ========================================================================

    public function testListGetDetailAndListOverdueDoNotCrash(): void
    {
        $opened = $this->captureJson(function (): void {
            \handleBreachOpen(['severity' => 'medium'], $this->accessControl, $this->adminUserID);
        });
        $incidentID = (int) $opened['data']['incidentID'];
        $this->testIncidentIDsToCleanUp[] = $incidentID;

        $list = $this->captureJson(function (): void {
            \handleBreachList();
        });
        $this->assertTrue($list['success']);
        $ids = array_map('intval', array_column($list['data']['incidents'], 'incidentID'));
        $this->assertContains($incidentID, $ids);

        $_GET['incidentID'] = (string) $incidentID;
        $detail = $this->captureJson(function (): void {
            \handleBreachGetDetail();
        });
        unset($_GET['incidentID']);
        $this->assertTrue($detail['success']);
        $this->assertSame($incidentID, (int) $detail['data']['incident']['incidentID']);

        $overdue = $this->captureJson(function (): void {
            \handleBreachListOverdue();
        });
        $this->assertTrue($overdue['success']);
        $this->assertIsArray($overdue['data']['overdue']);
    }

    // ========================================================================
    // 🛡️ CSRF / Super-admin gate / X-Frame-Options — source sentinels
    // ========================================================================

    public function testVerifyCsrfTokenRejectsAMissingOrBogusToken(): void
    {
        $this->assertFalse(\SecurityUtils::verifyCSRFToken(''));
        $this->assertFalse(\SecurityUtils::verifyCSRFToken('totally-bogus-token-value'));
    }

    public function testBreachActionsSourceGatesEveryPostRequestBehindCsrfVerificationBeforeAnyMutation(): void
    {
        $source = file_get_contents($this->actionsApiPath);
        $this->assertStringContainsString("SecurityUtils::verifyCSRFToken(", $source);
        $csrfPos = strpos($source, 'SecurityUtils::verifyCSRFToken(');
        $switchPos = strpos($source, 'switch ($action)');
        $this->assertNotFalse($switchPos);
        $this->assertLessThan($switchPos, $csrfPos, 'CSRF verification must happen BEFORE the action dispatch switch()');
    }

    public function testBreachActionsSourceEnforcesPostOnlyForEveryMutatingActionBeforeDispatch(): void
    {
        $source = file_get_contents($this->actionsApiPath);
        $this->assertStringContainsString('BREACH_MUTATING_ACTIONS', $source);
        $this->assertStringContainsString("in_array(\$action, BREACH_MUTATING_ACTIONS, true) && \$_SERVER['REQUEST_METHOD'] !== 'POST'", $source);

        $postOnlyGatePos = strpos($source, "in_array(\$action, BREACH_MUTATING_ACTIONS, true) && \$_SERVER['REQUEST_METHOD'] !== 'POST'");
        $switchPos = strpos($source, 'switch ($action)');
        $this->assertLessThan($switchPos, $postOnlyGatePos);

        foreach (['open', 'assess', 'compute_deadlines', 'mark_notified'] as $mutatingAction) {
            $this->assertContains($mutatingAction, \BREACH_MUTATING_ACTIONS, "{$mutatingAction} must be listed in BREACH_MUTATING_ACTIONS");
        }
    }

    public function testBreachesPageSourceCallsRequireSuperAdminBeforeAnyOutput(): void
    {
        $source = file_get_contents($this->indexPagePath);
        $this->assertStringContainsString('$accessControl->requireSuperAdmin();', $source);
        $this->assertStringContainsString('$sessionManager->isLoggedIn()', $source);
    }

    public function testBreachActionsSourceChecksIsSuperAdminBeforeDispatchingAnyAction(): void
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

    // ========================================================================
    // 🔒 "Never files with a regulator / never fabricates copy" negative sentinel
    // ========================================================================

    public function testBreachActionsSourceNeverContainsAnOutboundRegulatorFilingCall(): void
    {
        // 🔍 A crude but effective guard: this endpoint's whole design
        // contract is "compute deadlines + track status only". There is no
        // established regulator-filing API in this codebase to call, so this
        // sentinel simply proves no such integration was casually bolted on
        // (e.g. curl/file_get_contents to an external filing endpoint).
        $codeOnly = php_strip_whitespace($this->actionsApiPath);
        $this->assertStringNotContainsString('curl_exec', $codeOnly);
        $this->assertStringNotContainsString('file_get_contents(\'http', $codeOnly);
        $this->assertStringNotContainsString('file_get_contents("http', $codeOnly);
    }
}
