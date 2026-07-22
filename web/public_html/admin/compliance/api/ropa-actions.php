<?php
/**
 * ============================================================================
 * 📋 SIGNula - RoPA (Records of Processing Activities) Admin Actions API
 * ============================================================================
 * (G-004 Layer 4b §5.2 — the FINAL compliance slice, completes G-004)
 *
 * Purpose: AJAX endpoint backing web/public_html/admin/compliance/ropa/
 *          index.php. Every mutation is POST-only + CSRF-checked; every
 *          action requires an authenticated Super Admin session. Responses
 *          always use the {success, data|error} shape (house rule B-011).
 *          Plain CRUD over `tblProcessingActivities` (migration 044) — this
 *          register ships with ZERO seeded rows; every purpose/lawfulBasis/
 *          recipients/retentionPeriod value is operator/DPO-authored. This
 *          endpoint provides STRUCTURE + CRUD only — it never fabricates a
 *          value for any of those columns.
 *
 * Actions:
 *   - list        (GET)  — every processing-activity row
 *   - get_detail  (GET)  — one activity row
 *   - create      (POST) — insert a new activity (activityKey + name required)
 *   - update      (POST) — update the allowlisted editable columns
 *   - delete      (POST) — remove an activity
 *
 * @see web/public_html/admin/compliance/ropa/index.php — the page this backs
 * @see web/public_html/admin/compliance/api/regime-actions.php — auth/CSRF/response-shape/test-seam template this mirrors
 * @see _database/migrations/044_breach_ropa_agegate.sql
 * @see .dev-team/specs/G-004.md §5.2
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\Compliance\RoPA\API
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// ============================================================================
// ✅ ALLOWLISTS — column NAMES are NEVER taken from client input; every
// dynamic SET clause below iterates one of these fixed, hardcoded arrays.
// ============================================================================
// Declared BEFORE the test seam below (same reasoning as regime-actions.php:
// top-level `const` statements execute in sequence, so a const placed after
// the test seam's `return;` would never run under the test process — see
// that file's identical comment for the full rationale).
// ============================================================================

/** @var array<int,string> Mutating actions — POST + CSRF required (enforced below). */
const ROPA_MUTATING_ACTIONS = ['create', 'update', 'delete'];

/**
 * ✅ Nullable free-text columns create/update may write, and their maximum
 * stored length (matches the migration-044 column widths). A blank/
 * whitespace-only submission CLEARS the column back to NULL.
 *
 * @var array<string,int>
 */
const ROPA_NULLABLE_STRING_FIELDS = [
    'purpose'         => 65000, // TEXT column
    'lawfulBasis'     => 128,
    'retentionPeriod' => 128,
];

/**
 * ✅ Nullable JSON-array columns create/update may write. Caller may submit
 * either a JSON-decoded PHP array or a JSON string; both are normalised to
 * a stored JSON string (or NULL for an empty submission) — see
 * ropaEncodeJsonArray() below.
 *
 * @var array<int,string>
 */
const ROPA_JSON_FIELDS = ['dataCategories', 'dataSubjectCategories', 'recipients', 'transfersOutsideRegion', 'regimeCodes'];

// ============================================================================
// 🧮 PURE HELPERS — declared before the test seam so they are exercised by
// the SAME production code a live request runs (mirrors regime-actions.php).
// ============================================================================

/**
 * 🔧 Encode A Caller-Supplied Array (or JSON string) Into A JSON TEXT Value — pure.
 *
 * @param mixed $value array<int,mixed>|string|null
 * @return string|null
 */
function ropaEncodeJsonArray(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_string($value)) {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? json_encode(array_values($decoded), JSON_UNESCAPED_UNICODE) : null;
    }

    if (is_array($value)) {
        $filtered = array_values(array_filter($value, static fn($v): bool => $v !== null && $v !== ''));
        return !empty($filtered) ? json_encode($filtered, JSON_UNESCAPED_UNICODE) : null;
    }

    return null;
}

// ============================================================================
// 🧪 TEST SEAM — mirrors regime-actions.php's SIGNULA_REGIME_ACTIONS_TEST_ONLY
// idiom exactly. Production requests never define this constant.
// ============================================================================
if (defined('SIGNULA_ROPA_ACTIONS_TEST_ONLY') && SIGNULA_ROPA_ACTIONS_TEST_ONLY === true) {
    return;
}

// ============================================================================
// 🛡️ SIGNULA_INIT GUARD + BOOTSTRAP
// ============================================================================

// ⚠️ PATH DEPTH: __DIR__ here is web/public_html/admin/compliance/api — FOUR
// levels below web/ (public_html -> admin -> compliance -> api), the SAME
// depth as the sibling regime-actions.php in this exact directory —
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

// 🔐 Require super admin — the RoPA register is higher-sensitivity than
// ordinary app data (spec §6).
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
if (in_array($action, ROPA_MUTATING_ACTIONS, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'This action requires POST']);
    exit;
}

try {
    switch ($action) {
        case 'list':
            handleRopaList();
            break;

        case 'get_detail':
            handleRopaGetDetail();
            break;

        case 'create':
            handleRopaCreate($input, $accessControl, $adminUserID);
            break;

        case 'update':
            handleRopaUpdate($input, $accessControl, $adminUserID);
            break;

        case 'delete':
            handleRopaDelete($input, $accessControl, $adminUserID);
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
// 📋 LIST
// ============================================================================

/**
 * 📋 Return Every Processing-Activity Row
 *
 * @return void Echoes JSON
 */
function handleRopaList(): void
{
    $activities = Database::fetchAll("SELECT * FROM tblProcessingActivities ORDER BY name ASC", [], '');
    echo json_encode(['success' => true, 'data' => ['activities' => $activities]]);
}

// ============================================================================
// 🔍 GET DETAIL
// ============================================================================

/**
 * 🔍 Return A Single Processing Activity
 *
 * @return void Echoes JSON
 */
function handleRopaGetDetail(): void
{
    $activityID = (int) ($_GET['activityID'] ?? 0);

    if ($activityID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid activityID required']);
        return;
    }

    $activity = Database::fetchOne("SELECT * FROM tblProcessingActivities WHERE activityID = ?", [$activityID], 'i');
    if (!$activity) {
        echo json_encode(['success' => false, 'error' => 'Processing activity not found']);
        return;
    }

    echo json_encode(['success' => true, 'data' => ['activity' => $activity]]);
}

// ============================================================================
// ➕ CREATE
// ============================================================================

/**
 * ➕ Insert A New Processing Activity
 *
 * NEVER fabricates purpose/lawfulBasis/recipients — those columns are only
 * ever written from what the operator actually submitted (blank stays
 * NULL), and this handler only writes activityKey/name/isActive itself on
 * a bare create; every other field goes through the SAME allowlisted
 * writer applyRopaFields() that update() uses, so create+update share one
 * validation path.
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleRopaCreate(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $activityKey = trim((string) ($input['activityKey'] ?? ''));
    $name = trim((string) ($input['name'] ?? ''));

    if ($activityKey === '' || mb_strlen($activityKey) > 64 || !preg_match('/^[A-Za-z0-9_\-.]+$/', $activityKey)) {
        echo json_encode(['success' => false, 'error' => 'activityKey is required, must be 64 characters or fewer, and may only contain letters, digits, underscore, hyphen, or dot']);
        return;
    }
    if ($name === '' || mb_strlen($name) > 255) {
        echo json_encode(['success' => false, 'error' => 'name is required and must be 255 characters or fewer']);
        return;
    }

    $existing = Database::fetchOne("SELECT activityID FROM tblProcessingActivities WHERE activityKey = ?", [$activityKey], 's');
    if ($existing) {
        echo json_encode(['success' => false, 'error' => "A processing activity with key '{$activityKey}' already exists"]);
        return;
    }

    $isActive = array_key_exists('isActive', $input) ? (!empty($input['isActive']) ? 1 : 0) : 1;

    $activityID = Database::insert(
        "INSERT INTO tblProcessingActivities (activityKey, name, isActive) VALUES (?, ?, ?)",
        [$activityKey, $name, $isActive],
        'ssi'
    );

    // ✏️ Re-use the SAME allowlisted field writer update() uses for every
    // other (optional, operator-authored) column supplied alongside create.
    applyRopaFields($activityID, $input, ['name', 'isActive']);

    $accessControl->logAdminAction('ropa_create', 'processing_activity', $activityID, ['activityKey' => $activityKey, 'name' => $name]);

    echo json_encode(['success' => true, 'data' => ['activityID' => $activityID, 'activityKey' => $activityKey]]);
}

// ============================================================================
// ✏️ UPDATE — allowlisted columns only; activityKey is immutable
// ============================================================================

/**
 * ✏️ Update A Processing Activity's Editable Columns
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleRopaUpdate(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $activityID = (int) ($input['activityID'] ?? 0);
    if ($activityID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid activityID required']);
        return;
    }

    $existing = Database::fetchOne("SELECT activityID FROM tblProcessingActivities WHERE activityID = ?", [$activityID], 'i');
    if (!$existing) {
        echo json_encode(['success' => false, 'error' => 'Processing activity not found']);
        return;
    }

    $result = applyRopaFields($activityID, $input, []);
    if ($result === null) {
        echo json_encode(['success' => false, 'error' => 'No editable fields supplied']);
        return;
    }
    if (isset($result['error'])) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
        return;
    }

    $accessControl->logAdminAction('ropa_update', 'processing_activity', $activityID, ['fieldsUpdated' => array_keys($input)]);

    echo json_encode(['success' => true, 'data' => ['activityID' => $activityID]]);
}

/**
 * 🔧 Shared Allowlisted Field Writer — used by both create() (for the
 * OPTIONAL fields beyond activityKey/name/isActive) and update().
 *
 * @param int                  $activityID
 * @param array<string,mixed>  $input
 * @param array<int,string>    $alreadyHandled Field names the caller already wrote itself — skipped here
 * @return array<string,mixed>|null Null if nothing new to write; ['error'=>string] on validation failure; [] on success
 */
function applyRopaFields(int $activityID, array $input, array $alreadyHandled): ?array
{
    $setClauses = [];
    $params = [];
    $types = '';

    if (array_key_exists('name', $input) && !in_array('name', $alreadyHandled, true)) {
        $name = trim((string) $input['name']);
        if ($name === '') {
            return ['error' => 'name cannot be empty'];
        }
        if (mb_strlen($name) > 255) {
            return ['error' => 'name must be 255 characters or fewer'];
        }
        $setClauses[] = '`name` = ?';
        $params[] = $name;
        $types .= 's';
    }

    // 📝 Nullable free-text columns — blank/whitespace-only clears to NULL.
    // Trimmed VERBATIM (not htmlspecialchars-mangled at storage time) —
    // same "escape at output, not at storage" convention as regime-actions.php's
    // handleSaveDisclosure(), so legitimate legal copy survives intact.
    foreach (ROPA_NULLABLE_STRING_FIELDS as $field => $maxLength) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $raw = $input[$field];
        if ($raw === null) {
            $value = null;
        } else {
            $value = trim((string) $raw);
            if ($value === '') {
                $value = null;
            } elseif (mb_strlen($value) > $maxLength) {
                return ['error' => "{$field} must be {$maxLength} characters or fewer"];
            }
        }
        $setClauses[] = "`{$field}` = ?";
        $params[] = $value;
        $types .= 's';
    }

    // 🗂️ Nullable JSON-array columns.
    foreach (ROPA_JSON_FIELDS as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $setClauses[] = "`{$field}` = ?";
        $params[] = ropaEncodeJsonArray($input[$field]);
        $types .= 's';
    }

    if (array_key_exists('ownerAdminID', $input)) {
        $raw = $input['ownerAdminID'];
        $setClauses[] = '`ownerAdminID` = ?';
        $params[] = ($raw === null || $raw === '') ? null : (int) $raw;
        $types .= 'i';
    }

    if (array_key_exists('isActive', $input) && !in_array('isActive', $alreadyHandled, true)) {
        $setClauses[] = '`isActive` = ?';
        $params[] = !empty($input['isActive']) ? 1 : 0;
        $types .= 'i';
    }

    if (empty($setClauses)) {
        return null;
    }

    $params[] = $activityID;
    $types .= 'i';

    Database::query(
        "UPDATE tblProcessingActivities SET " . implode(', ', $setClauses) . " WHERE activityID = ?",
        $params,
        $types
    );

    return [];
}

// ============================================================================
// 🗑️ DELETE
// ============================================================================

/**
 * 🗑️ Delete A Processing Activity
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleRopaDelete(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $activityID = (int) ($input['activityID'] ?? 0);
    if ($activityID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid activityID required']);
        return;
    }

    $existing = Database::fetchOne("SELECT activityID, activityKey FROM tblProcessingActivities WHERE activityID = ?", [$activityID], 'i');
    if (!$existing) {
        echo json_encode(['success' => false, 'error' => 'Processing activity not found']);
        return;
    }

    Database::query("DELETE FROM tblProcessingActivities WHERE activityID = ?", [$activityID], 'i');

    $accessControl->logAdminAction('ropa_delete', 'processing_activity', $activityID, ['activityKey' => $existing['activityKey']]);

    echo json_encode(['success' => true, 'data' => ['activityID' => $activityID]]);
}
