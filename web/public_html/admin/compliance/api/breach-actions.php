<?php
/**
 * ============================================================================
 * 🚨 SIGNula - Breach Notification Admin Actions API (G-004 Layer 4b §5.1)
 * ============================================================================
 * (the FINAL compliance slice, completes G-004)
 *
 * Purpose: AJAX endpoint backing web/public_html/admin/compliance/breaches/
 *          index.php. Every mutation is POST-only + CSRF-checked; every
 *          action requires an authenticated Super Admin session. Responses
 *          always use the {success, data|error} shape (house rule B-011).
 *          Thin wrapper over `BreachManager` — this file contains NO breach
 *          logic itself (no window computation, no severity/status
 *          validation) beyond request-shape checks; every real rule lives in
 *          BreachManager (web/private_html/compliance/BreachManager.php).
 *
 * Actions:
 *   - list               (GET)  — every incident (optionally filtered by ?status=)
 *   - get_detail         (GET)  — one incident + its notification rows
 *   - list_overdue       (GET)  — BreachManager::getOverdueNotifications()
 *   - open               (POST) — BreachManager::open()
 *   - assess             (POST) — BreachManager::assess()
 *   - compute_deadlines  (POST) — BreachManager::computeNotificationDeadlines()
 *   - mark_notified      (POST) — BreachManager::markNotified()
 *
 * 🔒 This endpoint NEVER files anything with a regulator and NEVER writes
 * breach notification copy itself — `summary`/`remediation`/`note` are
 * always exactly what the operator submitted (see BreachManager's own
 * "machinery only" contract).
 *
 * @see web/private_html/compliance/BreachManager.php — the ONLY place any breach rule/logic lives
 * @see web/public_html/admin/compliance/breaches/index.php — the page this backs
 * @see web/public_html/admin/compliance/api/regime-actions.php — auth/CSRF/response-shape/test-seam template this mirrors
 * @see web/public_html/admin/compliance/api/dsar-actions.php — the "guarded require of a compliance/ manager class" idiom this mirrors
 * @see _database/migrations/044_breach_ropa_agegate.sql
 * @see .dev-team/specs/G-004.md §5.1
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\Compliance\Breaches\API
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// ============================================================================
// ✅ ALLOWLIST — declared BEFORE the test seam below (same reasoning as
// regime-actions.php / ropa-actions.php: a top-level `const` placed after
// the seam's `return;` would never run under the test process).
// ============================================================================

/** @var array<int,string> Mutating actions — POST + CSRF required (enforced below). */
const BREACH_MUTATING_ACTIONS = ['open', 'assess', 'compute_deadlines', 'mark_notified'];

/** @var array<int,string> tblBreachIncidents.status ENUM — MUST mirror migration 044 exactly. */
const BREACH_VALID_STATUSES = ['detected', 'assessing', 'contained', 'notifying', 'closed'];

// ============================================================================
// 🧪 TEST SEAM — mirrors regime-actions.php's SIGNULA_REGIME_ACTIONS_TEST_ONLY
// idiom exactly. Production requests never define this constant.
// ============================================================================
if (defined('SIGNULA_BREACH_ACTIONS_TEST_ONLY') && SIGNULA_BREACH_ACTIONS_TEST_ONLY === true) {
    return;
}

// ============================================================================
// 🛡️ SIGNULA_INIT GUARD + BOOTSTRAP
// ============================================================================

// ⚠️ PATH DEPTH: __DIR__ here is web/public_html/admin/compliance/api — FOUR
// levels below web/ (public_html -> admin -> compliance -> api), the SAME
// depth as every sibling *-actions.php in this exact directory —
// dirname(__DIR__, 4) reaches web/.
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

header('Content-Type: application/json');

// 🛡️ Clickjacking defence on this compliance-data admin surface (spec §6 /
// house lesson #29) — every admin compliance page/endpoint sets this.
header('X-Frame-Options: DENY');

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔐 Check authentication
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 🔐 Require super admin — breach data is higher-sensitivity than ordinary
// app data (spec §6).
$userInfo = $sessionManager->getUserInfo();
if (!isset($userInfo['isSuperAdmin']) || $userInfo['isSuperAdmin'] != 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Super admin access required']);
    exit;
}

$adminUserID = (int) ($userInfo['userID'] ?? 0);

// 📥 Get request data
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// 🛡️ CSRF for POST requests — every mutation on this endpoint is POST-only.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
        exit;
    }
}

// 🛡️ Defence in depth: a mutating action MUST be POST.
if (in_array($action, BREACH_MUTATING_ACTIONS, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'This action requires POST']);
    exit;
}

// 📚 BreachManager lives outside config.php's autoload dirs — guarded
// require mirrors dsar-actions.php's identical idiom for DSARManager.
if (!class_exists('BreachManager')) {
    require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'BreachManager.php';
}

try {
    switch ($action) {
        case 'list':
            handleBreachList();
            break;

        case 'get_detail':
            handleBreachGetDetail();
            break;

        case 'list_overdue':
            handleBreachListOverdue();
            break;

        case 'open':
            handleBreachOpen($input, $accessControl, $adminUserID);
            break;

        case 'assess':
            handleBreachAssess($input, $accessControl, $adminUserID);
            break;

        case 'compute_deadlines':
            handleBreachComputeDeadlines($input, $accessControl, $adminUserID);
            break;

        case 'mark_notified':
            handleBreachMarkNotified($input, $accessControl, $adminUserID);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (\Throwable $e) {
    // 🛡️ Never leak exception internals to the client (spec §6 "No leakage").
    if (class_exists('ErrorLogger')) {
        ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An unexpected error occurred.']);
}

// ============================================================================
// 📋 LIST / GET DETAIL / LIST OVERDUE (read-only)
// ============================================================================

/**
 * 📋 Return Every Breach Incident (optionally filtered by ?status=)
 *
 * @return void Echoes JSON
 */
function handleBreachList(): void
{
    $status = isset($_GET['status']) ? (string) $_GET['status'] : '';

    if ($status !== '' && in_array($status, BREACH_VALID_STATUSES, true)) {
        $incidents = Database::fetchAll(
            "SELECT * FROM tblBreachIncidents WHERE status = ? ORDER BY detectedAt DESC",
            [$status],
            's'
        );
    } else {
        $incidents = Database::fetchAll("SELECT * FROM tblBreachIncidents ORDER BY detectedAt DESC", [], '');
    }

    echo json_encode(['success' => true, 'data' => ['incidents' => $incidents]]);
}

/**
 * 🔍 Return One Incident Plus Its Notification Rows
 *
 * @return void Echoes JSON
 */
function handleBreachGetDetail(): void
{
    $incidentID = (int) ($_GET['incidentID'] ?? 0);

    if ($incidentID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid incidentID required']);
        return;
    }

    $incident = Database::fetchOne("SELECT * FROM tblBreachIncidents WHERE incidentID = ?", [$incidentID], 'i');
    if (!$incident) {
        echo json_encode(['success' => false, 'error' => 'Breach incident not found']);
        return;
    }

    $notifications = Database::fetchAll(
        "SELECT * FROM tblBreachNotifications WHERE incidentID = ? ORDER BY (dueAt IS NULL) ASC, dueAt ASC",
        [$incidentID],
        'i'
    );

    echo json_encode(['success' => true, 'data' => ['incident' => $incident, 'notifications' => $notifications]]);
}

/**
 * 📋 Return Every Overdue, Still-Pending Notification (across all incidents)
 *
 * @return void Echoes JSON
 */
function handleBreachListOverdue(): void
{
    $overdue = BreachManager::getOverdueNotifications();
    echo json_encode(['success' => true, 'data' => ['overdue' => $overdue]]);
}

// ============================================================================
// 🚨 OPEN / ✏️ ASSESS
// ============================================================================

/**
 * 🚨 Open A New Breach Incident
 *
 * Every field is passed straight through to BreachManager::open() — this
 * handler performs NO validation/business logic itself, just request-shape
 * extraction; BreachManager owns every rule (severity/status allowlists,
 * reference auto-generation, etc).
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleBreachOpen(array $input, AccessControl $accessControl, int $adminUserID): void
{
    try {
        $incidentID = BreachManager::open([
            'reference'         => $input['reference'] ?? null,
            'severity'          => $input['severity'] ?? null,
            'status'            => $input['status'] ?? null,
            'detectedAt'        => $input['detectedAt'] ?? null,
            'discoveredBy'      => $input['discoveredBy'] ?? null,
            'affectedUserCount' => $input['affectedUserCount'] ?? null,
            'dataCategories'    => $input['dataCategories'] ?? null,
            'regimeCodes'       => $input['regimeCodes'] ?? null,
            'summary'           => $input['summary'] ?? null,
            'remediation'       => $input['remediation'] ?? null,
            'assignedAdminID'   => $input['assignedAdminID'] ?? $adminUserID,
        ]);
    } catch (\InvalidArgumentException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    }

    $accessControl->logAdminAction('breach_open', 'breach_incident', $incidentID, ['severity' => $input['severity'] ?? 'medium']);

    echo json_encode(['success' => true, 'data' => ['incidentID' => $incidentID]]);
}

/**
 * ✏️ Update An Incident's Assessable Fields
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleBreachAssess(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $incidentID = (int) ($input['incidentID'] ?? 0);
    if ($incidentID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid incidentID required']);
        return;
    }

    $fields = [];
    foreach (['severity', 'status', 'discoveredBy', 'affectedUserCount', 'dataCategories', 'regimeCodes', 'summary', 'remediation', 'assignedAdminID'] as $field) {
        if (array_key_exists($field, $input)) {
            $fields[$field] = $input[$field];
        }
    }

    try {
        $updated = BreachManager::assess($incidentID, $fields);
    } catch (\InvalidArgumentException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    }

    if (!$updated) {
        echo json_encode(['success' => false, 'error' => 'Incident not found, or no fields were supplied']);
        return;
    }

    $accessControl->logAdminAction('breach_assess', 'breach_incident', $incidentID, ['fieldsUpdated' => array_keys($fields)]);

    echo json_encode(['success' => true, 'data' => ['incidentID' => $incidentID]]);
}

// ============================================================================
// ⏰ COMPUTE DEADLINES / ✅ MARK NOTIFIED
// ============================================================================

/**
 * ⏰ (Re)Compute Every Notification Deadline For An Incident
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleBreachComputeDeadlines(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $incidentID = (int) ($input['incidentID'] ?? 0);
    if ($incidentID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid incidentID required']);
        return;
    }

    try {
        $notifications = BreachManager::computeNotificationDeadlines($incidentID);
    } catch (\InvalidArgumentException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    }

    $accessControl->logAdminAction('breach_compute_deadlines', 'breach_incident', $incidentID, ['notificationCount' => count($notifications)]);

    echo json_encode(['success' => true, 'data' => ['notifications' => $notifications]]);
}

/**
 * ✅ Mark A Notification As Sent
 *
 * `note` is operator-entered filing detail — this endpoint NEVER files
 * anything with a regulator itself; see BreachManager::markNotified()'s
 * own docblock.
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleBreachMarkNotified(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $notifyID = (int) ($input['notifyID'] ?? 0);
    if ($notifyID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid notifyID required']);
        return;
    }

    $note = isset($input['note']) ? (string) $input['note'] : null;

    $ok = BreachManager::markNotified($notifyID, $note);
    if (!$ok) {
        echo json_encode(['success' => false, 'error' => 'Notification not found']);
        return;
    }

    $accessControl->logAdminAction('breach_mark_notified', 'breach_notification', $notifyID, []);

    echo json_encode(['success' => true, 'data' => ['notifyID' => $notifyID]]);
}
