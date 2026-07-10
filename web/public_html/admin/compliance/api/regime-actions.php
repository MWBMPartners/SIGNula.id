<?php
/**
 * ============================================================================
 * 📋 SIGNula - Compliance Regime Admin Actions API (G-004 Layer 3 §4.3)
 * ============================================================================
 *
 * Purpose: AJAX endpoint backing web/public_html/admin/compliance/regimes/
 *          index.php. Every mutation is POST-only + CSRF-checked; every
 *          action (mutating or not) requires an authenticated Super Admin
 *          session. Responses always use the {success, data|error} shape
 *          (house rule B-011). CRUD is over `tblComplianceRegimes` +
 *          `tblRegimeRights` + `tblRegimeDisclosures` + `tblRegimeResolution`
 *          (migration 042) — the tables `RegimeResolver` reads (active-only).
 *          This endpoint, unlike RegimeResolver, sees/writes draft+inactive
 *          rows too — that is its whole job (an operator fills the legal
 *          values BEFORE a regime can go live).
 *
 * Actions:
 *   - list               (GET)  — every regime row (incl draft/inactive)
 *   - get_detail         (GET)  — one regime + its rights/disclosures/resolution rules
 *   - create_regime      (POST) — insert a new draft regime (isActive=0, isDraft=1)
 *   - update_regime      (POST) — update the allowlisted editable columns
 *   - set_rights         (POST) — replace a regime's tblRegimeRights set
 *   - save_disclosure    (POST) — upsert a tblRegimeDisclosures row
 *   - save_resolution    (POST) — create/update a tblRegimeResolution rule
 *   - delete_resolution  (POST) — remove a tblRegimeResolution rule
 *   - activate_regime    (POST) — THE CRUX (spec §4.4 AC) — see below
 *   - deactivate_regime  (POST) — always allowed; isActive=0 only
 *
 * ----------------------------------------------------------------------------
 * 🔒 THE ACTIVATION GUARD — why handleActivateRegime() re-reads the row
 * ----------------------------------------------------------------------------
 * A regime cannot go live (`isActive=1, isDraft=0`) until an operator has
 * confirmed its THREE legal-judgement values: dsarSlaDays, minAge, and
 * breachNotifyHours. This is enforced by regimeCanActivate() below — a pure,
 * dependency-free helper that takes a regime ROW and returns whether it may
 * activate + which fields are still missing. handleActivateRegime() ALWAYS
 * re-SELECTs the row from the database immediately before checking it —
 * NEVER trusts any value the client sent in the request body — so a caller
 * cannot bypass the gate by POSTing fabricated SLA/age/breach numbers
 * alongside the activate_regime action; only the row actually PERSISTED by a
 * prior update_regime call is ever consulted.
 *
 * regimeCanActivate() is declared FIRST in this file, before any of the
 * bootstrap/session/dispatch code that follows, and is entirely free of
 * DB/session/global state — this is what lets it be integration-tested
 * directly (see _tests/Integration/Compliance/RegimeAdminActionsTest.php)
 * without standing up a `php -S` subprocess harness for this whole script:
 * the test defines SIGNULA_REGIME_ACTIONS_TEST_ONLY before require()-ing
 * this exact file, which trips the early-return guard immediately below the
 * function and skips straight past the live bootstrap/auth/dispatch flow
 * that a real HTTP request runs. Production requests NEVER define that
 * constant (it is not derived from any request input), so this test seam is
 * inert outside the test process. There is only ONE copy of the activation
 * rule — handleActivateRegime() below calls this exact function.
 *
 * @see web/private_html/compliance/RegimeResolver.php — the ACTIVE-only reader this table feeds
 * @see web/public_html/admin/compliance/regimes/index.php — the page this backs
 * @see web/public_html/admin/compliance/api/dsar-actions.php — auth/CSRF/response-shape template this mirrors
 * @see _database/migrations/042_compliance_regimes.sql
 * @see .dev-team/specs/G-004.md §4.3, §4.4
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\Compliance\Regimes\API
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// ============================================================================
// 🧮 PURE VALIDATION HELPER — regimeCanActivate() (THE ACTIVATION GUARD)
// ============================================================================

/**
 * 🔒 Can This Regime Row Be Activated?
 *
 * The single source of truth for the "draft -> active" gate (spec §4.4 AC):
 * a regime may only activate once `dsarSlaDays`, `minAge`, AND
 * `breachNotifyHours` are ALL non-NULL. Pure — takes whatever associative
 * array is handed to it (a real DB row, or a hand-built test fixture) and
 * returns a decision; it never queries the database itself. The CALLER
 * (handleActivateRegime(), below) is responsible for making sure the row it
 * passes in was just read fresh from tblComplianceRegimes.
 *
 * @param array<string,mixed> $row A tblComplianceRegimes row (or a
 *                                 sufficiently-shaped test fixture) —
 *                                 must contain the three keys checked below
 * @return array{0:bool,1:array<int,string>} [canActivate, missingFieldNames]
 */
function regimeCanActivate(array $row): array
{
    // ✅ The three legal-judgement columns migration 042 ships NULL for every
    // seeded regime (see that migration's header) — an operator/counsel must
    // fill each one before this tool will ever flip isActive on.
    $requiredFields = ['dsarSlaDays', 'minAge', 'breachNotifyHours'];

    $missing = [];
    foreach ($requiredFields as $field) {
        // 🔍 Treat an absent key the SAME as an explicit NULL — a caller that
        // forgets to SELECT a column must fail closed, not silently pass.
        if (!array_key_exists($field, $row) || $row[$field] === null) {
            $missing[] = $field;
        }
    }

    return [empty($missing), $missing];
}

// ============================================================================
// ✅ ALLOWLISTS — column NAMES are NEVER taken from client input; every
// dynamic SET clause below iterates one of these fixed, hardcoded arrays.
// ============================================================================
// Deliberately declared HERE — BEFORE the test seam below — even though they
// are only actually consulted by the dispatch/handler code further down.
// Unlike function declarations (which PHP hoists at compile time regardless
// of surrounding control flow), top-level `const` statements are ordinary
// statements executed in sequence: a `const` placed AFTER the test-seam's
// `return;` would simply never run when SIGNULA_REGIME_ACTIONS_TEST_ONLY is
// set, leaving handleUpdateRegime()/handleSetRights()/etc. unable to resolve
// these names ("Undefined constant") the moment a test calls them. Keeping
// every allowlist constant above the guard means the test seam exposes the
// COMPLETE, unmodified handler functions — not just regimeCanActivate() —
// for direct, genuine functional testing.
// @see https://www.php.net/manual/en/language.constants.syntax.php
// ============================================================================

/** @var array<int,string> Mutating actions — POST + CSRF required (enforced below). */
const REGIME_MUTATING_ACTIONS = [
    'create_regime', 'update_regime', 'set_rights', 'save_disclosure',
    'save_resolution', 'delete_resolution', 'activate_regime', 'deactivate_regime',
];

/**
 * ✅ Request types a regime may grant — MUST mirror
 * DSARManager::VALID_REQUEST_TYPES / tblDataSubjectRequests.requestType
 * (migration 040), which is what tblRegimeRights.right is documented (in
 * migration 042) to be drawn from.
 *
 * @var array<int,string>
 */
const REGIME_VALID_RIGHTS = [
    'access', 'portability', 'erasure', 'rectification', 'restriction',
    'object', 'do_not_sell', 'withdraw_consent', 'automated_decision_optout',
];

/** @var array<int,string> tblRegimeResolution.matchType ENUM (migration 042). */
const REGIME_VALID_MATCH_TYPES = ['country', 'region', 'partner', 'default'];

/**
 * ✅ Nullable free-text columns update_regime/create_regime may write, and
 * their maximum stored length (matches the migration-042 column widths).
 * A blank/whitespace-only submission CLEARS the column back to NULL.
 *
 * @var array<string,int>
 */
const REGIME_NULLABLE_STRING_FIELDS = [
    'jurisdiction'          => 64,
    'breachNotifyAuthority' => 255,
    'notes'                 => 65000, // TEXT column — generous cap, well under the 65535-byte ceiling
];

/**
 * ✅ Nullable numeric (legal-value) columns update_regime may write, and
 * their maximum permitted value (matches the migration-042 SMALLINT
 * UNSIGNED / TINYINT UNSIGNED column widths). A blank submission CLEARS
 * the column back to NULL — this is how an operator can "un-confirm" a
 * value (which also auto-blocks re-activation via regimeCanActivate()).
 *
 * @var array<string,int>
 */
const REGIME_NUMERIC_FIELDS = [
    'dsarSlaDays'       => 65535,
    'dsarExtensionDays' => 65535,
    'breachNotifyHours' => 65535,
    'minAge'            => 255,
];

/** @var array<int,string> NOT NULL boolean characteristic flags update_regime may write. */
const REGIME_BOOLEAN_FIELDS = ['honorsGPC', 'requiresDoNotSell', 'lawfulBasisRequired'];

// ============================================================================
// 🧪 TEST SEAM — see this file's "THE ACTIVATION GUARD" docblock above.
// ============================================================================
// Production requests never define this constant, so this early-return NEVER
// triggers outside a PHPUnit process that deliberately defines it before
// require()-ing this file. Everything below this point is the REAL,
// unmodified production execution path (bootstrap, session gate, CSRF,
// action dispatch) — identical to what a live HTTP request runs.
// ============================================================================
if (defined('SIGNULA_REGIME_ACTIONS_TEST_ONLY') && SIGNULA_REGIME_ACTIONS_TEST_ONLY === true) {
    return;
}

// ============================================================================
// 🛡️ SIGNULA_INIT GUARD + BOOTSTRAP
// ============================================================================

// ⚠️ PATH DEPTH: __DIR__ here is web/public_html/admin/compliance/api — FOUR
// levels below web/ (public_html -> admin -> compliance -> api), the SAME
// depth as the sibling regimes/index.php (compliance/regimes and
// compliance/api are both two levels below admin/) — dirname(__DIR__, 4)
// reaches web/. Verified at build time — see the build report for the
// literal resolved path proof.
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

// 🔐 Require super admin — compliance regime configuration is
// higher-sensitivity than ordinary app data (spec §6).
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

// 🛡️ Defence in depth: a mutating action MUST be POST — without this, a GET
// request naming a mutating action would skip the CSRF check above entirely
// (that check only runs when REQUEST_METHOD === 'POST') and reach the
// dispatch switch() unauthenticated-by-CSRF.
if (in_array($action, REGIME_MUTATING_ACTIONS, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'This action requires POST']);
    exit;
}

try {
    switch ($action) {
        case 'list':
            handleList();
            break;

        case 'get_detail':
            handleGetDetail();
            break;

        case 'create_regime':
            handleCreateRegime($input, $accessControl, $adminUserID);
            break;

        case 'update_regime':
            handleUpdateRegime($input, $accessControl, $adminUserID);
            break;

        case 'set_rights':
            handleSetRights($input, $accessControl, $adminUserID);
            break;

        case 'save_disclosure':
            handleSaveDisclosure($input, $accessControl, $adminUserID);
            break;

        case 'save_resolution':
            handleSaveResolution($input, $accessControl, $adminUserID);
            break;

        case 'delete_resolution':
            handleDeleteResolution($input, $accessControl, $adminUserID);
            break;

        case 'activate_regime':
            handleActivateRegime($input, $accessControl, $adminUserID);
            break;

        case 'deactivate_regime':
            handleDeactivateRegime($input, $accessControl, $adminUserID);
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
// 📋 LIST — every regime row, incl draft/inactive (admin-only view)
// ============================================================================

/**
 * 📋 Return Every Regime Row
 *
 * Unlike RegimeResolver (active-only reads), this admin endpoint deliberately
 * returns EVERY row — draft, inactive, and active alike — because filling in
 * the still-draft ones is the entire point of this screen.
 *
 * @return void Echoes JSON
 */
function handleList(): void
{
    $regimes = Database::fetchAll("SELECT * FROM tblComplianceRegimes ORDER BY regimeCode ASC", [], '');
    echo json_encode(['success' => true, 'data' => ['regimes' => $regimes]]);
}

// ============================================================================
// 🔍 GET DETAIL — one regime + its rights/disclosures/resolution rules
// ============================================================================

/**
 * 🔍 Return A Single Regime Plus Its Rights/Disclosures/Resolution Rules
 *
 * @return void Echoes JSON
 */
function handleGetDetail(): void
{
    $regimeID = (int) ($_GET['regimeID'] ?? 0);

    if ($regimeID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid regimeID required']);
        return;
    }

    $regime = Database::fetchOne("SELECT * FROM tblComplianceRegimes WHERE regimeID = ?", [$regimeID], 'i');
    if (!$regime) {
        echo json_encode(['success' => false, 'error' => 'Regime not found']);
        return;
    }

    $rightRows = Database::fetchAll(
        "SELECT `right` FROM tblRegimeRights WHERE regimeID = ? ORDER BY `right` ASC",
        [$regimeID],
        'i'
    );
    $disclosures = Database::fetchAll(
        "SELECT * FROM tblRegimeDisclosures WHERE regimeID = ? ORDER BY disclosureKey ASC",
        [$regimeID],
        'i'
    );
    $resolutionRules = Database::fetchAll(
        "SELECT * FROM tblRegimeResolution WHERE regimeID = ? ORDER BY priority ASC, ruleID ASC",
        [$regimeID],
        'i'
    );

    echo json_encode(['success' => true, 'data' => [
        'regime'          => $regime,
        'rights'          => array_map(static fn(array $r): string => (string) $r['right'], $rightRows),
        'disclosures'     => $disclosures,
        'resolutionRules' => $resolutionRules,
    ]]);
}

// ============================================================================
// ➕ CREATE REGIME — ships isDraft=1, isActive=0, EVERY legal value NULL
// ============================================================================

/**
 * ➕ Insert A New Draft Regime
 *
 * NEVER writes a legal value (SLA/age/breach) — those stay NULL until the
 * operator fills them via update_regime. This is what makes "adding a 20th
 * regime = a row insert, zero PHP edits" (spec §4.4 AC) true from the admin
 * side too.
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleCreateRegime(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $regimeCode = strtoupper(trim((string) ($input['regimeCode'] ?? '')));
    $displayName = trim((string) ($input['displayName'] ?? ''));
    $jurisdictionRaw = isset($input['jurisdiction']) ? trim((string) $input['jurisdiction']) : '';

    // 🔒 regimeCode is the ONLY immutable identity column — validate its
    // shape strictly (uppercase letters/digits/underscore, matches every
    // migration-042 seed code's own style, e.g. GDPR, UK_GDPR, AU_PA88).
    if ($regimeCode === '' || !preg_match('/^[A-Z0-9_]{1,16}$/', $regimeCode)) {
        echo json_encode(['success' => false, 'error' => 'regimeCode is required and must be 1-16 uppercase letters, digits, or underscores']);
        return;
    }
    if ($displayName === '' || mb_strlen($displayName) > 128) {
        echo json_encode(['success' => false, 'error' => 'displayName is required and must be 128 characters or fewer']);
        return;
    }
    if (mb_strlen($jurisdictionRaw) > 64) {
        echo json_encode(['success' => false, 'error' => 'jurisdiction must be 64 characters or fewer']);
        return;
    }

    // 🔎 Explicit pre-check (rather than relying on the UNIQUE KEY exception)
    // so a duplicate regimeCode gets a clean, friendly {success:false} reply
    // instead of a generic 500.
    $existing = Database::fetchOne("SELECT regimeID FROM tblComplianceRegimes WHERE regimeCode = ?", [$regimeCode], 's');
    if ($existing) {
        echo json_encode(['success' => false, 'error' => "A regime with code '{$regimeCode}' already exists"]);
        return;
    }

    $jurisdiction = $jurisdictionRaw !== '' ? $jurisdictionRaw : null;

    // 🌱 isActive=0, isDraft=1, every legal value column left at its table
    // default (NULL) — mirrors migration 042's own seed contract exactly.
    $regimeID = Database::insert(
        "INSERT INTO tblComplianceRegimes (regimeCode, displayName, jurisdiction, isActive, isDraft) VALUES (?, ?, ?, 0, 1)",
        [$regimeCode, $displayName, $jurisdiction],
        'sss'
    );

    $accessControl->logAdminAction('regime_create_regime', 'compliance_regime', $regimeID, ['regimeCode' => $regimeCode, 'displayName' => $displayName]);

    echo json_encode(['success' => true, 'data' => ['regimeID' => $regimeID, 'regimeCode' => $regimeCode]]);
}

// ============================================================================
// ✏️ UPDATE REGIME — allowlisted columns only; regimeCode/isActive/isDraft
// are NEVER writable through this action (they have their own actions)
// ============================================================================

/**
 * ✏️ Update A Regime's Editable Columns
 *
 * Builds a dynamic UPDATE ... SET clause, but ONLY from the fixed, hardcoded
 * REGIME_NULLABLE_STRING_FIELDS / REGIME_NUMERIC_FIELDS / REGIME_BOOLEAN_FIELDS
 * allowlists declared above — the client supplies VALUES keyed by these
 * known names, never a column NAME itself, so there is no SQL-injection
 * surface via a crafted key.
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleUpdateRegime(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $regimeID = (int) ($input['regimeID'] ?? 0);
    if ($regimeID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid regimeID required']);
        return;
    }

    $existing = Database::fetchOne("SELECT regimeID FROM tblComplianceRegimes WHERE regimeID = ?", [$regimeID], 'i');
    if (!$existing) {
        echo json_encode(['success' => false, 'error' => 'Regime not found']);
        return;
    }

    $setClauses = [];
    $params = [];
    $types = '';

    // 📛 displayName — required, NOT NULL column; only touched if supplied.
    if (array_key_exists('displayName', $input)) {
        $displayName = trim((string) $input['displayName']);
        if ($displayName === '') {
            echo json_encode(['success' => false, 'error' => 'displayName cannot be empty']);
            return;
        }
        if (mb_strlen($displayName) > 128) {
            echo json_encode(['success' => false, 'error' => 'displayName must be 128 characters or fewer']);
            return;
        }
        $setClauses[] = '`displayName` = ?';
        $params[] = $displayName;
        $types .= 's';
    }

    // 📝 Nullable free-text columns — blank/whitespace-only clears to NULL.
    foreach (REGIME_NULLABLE_STRING_FIELDS as $field => $maxLength) {
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
                echo json_encode(['success' => false, 'error' => "{$field} must be {$maxLength} characters or fewer"]);
                return;
            }
        }

        $setClauses[] = "`{$field}` = ?";
        $params[] = $value;
        $types .= 's';
    }

    // 🔢 Nullable legal-value numeric columns — blank clears to NULL, which
    // is exactly what re-locks activate_regime (see regimeCanActivate()).
    foreach (REGIME_NUMERIC_FIELDS as $field => $maxValue) {
        if (!array_key_exists($field, $input)) {
            continue;
        }

        $raw = $input[$field];
        if ($raw === null || $raw === '') {
            $value = null;
        } else {
            if (!is_numeric($raw)) {
                echo json_encode(['success' => false, 'error' => "{$field} must be numeric or blank"]);
                return;
            }
            $intValue = (int) $raw;
            if ($intValue < 0 || $intValue > $maxValue) {
                echo json_encode(['success' => false, 'error' => "{$field} must be between 0 and {$maxValue}"]);
                return;
            }
            $value = $intValue;
        }

        $setClauses[] = "`{$field}` = ?";
        $params[] = $value;
        $types .= 'i';
    }

    // ☑️ NOT NULL boolean characteristic flags.
    foreach (REGIME_BOOLEAN_FIELDS as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }

        $setClauses[] = "`{$field}` = ?";
        $params[] = !empty($input[$field]) ? 1 : 0;
        $types .= 'i';
    }

    if (empty($setClauses)) {
        echo json_encode(['success' => false, 'error' => 'No editable fields supplied']);
        return;
    }

    $params[] = $regimeID;
    $types .= 'i';

    Database::query(
        "UPDATE tblComplianceRegimes SET " . implode(', ', $setClauses) . " WHERE regimeID = ?",
        $params,
        $types
    );

    $accessControl->logAdminAction('regime_update_regime', 'compliance_regime', $regimeID, ['fieldsUpdated' => array_keys($input)]);

    echo json_encode(['success' => true, 'data' => ['regimeID' => $regimeID]]);
}

// ============================================================================
// ☑️ SET RIGHTS — replace a regime's tblRegimeRights set
// ============================================================================

/**
 * ☑️ Replace A Regime's Granted Rights
 *
 * Full replace (delete-then-insert inside a transaction) rather than a diff —
 * simpler and matches the checkbox-list UI (the admin page submits the
 * COMPLETE current selection every time, not a delta).
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleSetRights(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $regimeID = (int) ($input['regimeID'] ?? 0);
    $rightsInput = is_array($input['rights'] ?? null) ? $input['rights'] : [];

    if ($regimeID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid regimeID required']);
        return;
    }

    $existing = Database::fetchOne("SELECT regimeID FROM tblComplianceRegimes WHERE regimeID = ?", [$regimeID], 'i');
    if (!$existing) {
        echo json_encode(['success' => false, 'error' => 'Regime not found']);
        return;
    }

    // ✅ Validate every submitted right against the allowlist BEFORE writing
    // anything — a single invalid value rejects the whole call rather than
    // silently dropping it.
    $cleanRights = [];
    foreach ($rightsInput as $right) {
        $right = (string) $right;
        if (!in_array($right, REGIME_VALID_RIGHTS, true)) {
            echo json_encode(['success' => false, 'error' => "Invalid right: {$right}"]);
            return;
        }
        $cleanRights[$right] = true; // de-dupe
    }
    $cleanRights = array_keys($cleanRights);

    Database::beginTransaction();
    try {
        Database::query("DELETE FROM tblRegimeRights WHERE regimeID = ?", [$regimeID], 'i');
        foreach ($cleanRights as $right) {
            Database::query("INSERT INTO tblRegimeRights (regimeID, `right`) VALUES (?, ?)", [$regimeID, $right], 'is');
        }
        Database::commit();
    } catch (\Throwable $e) {
        Database::rollback();
        throw $e; // 🔁 bubbles to the top-level catch(\Throwable) — generic 500, no leakage
    }

    $accessControl->logAdminAction('regime_set_rights', 'compliance_regime', $regimeID, ['rights' => $cleanRights]);

    echo json_encode(['success' => true, 'data' => ['regimeID' => $regimeID, 'rights' => $cleanRights]]);
}

// ============================================================================
// 📜 SAVE DISCLOSURE — upsert by (regimeID, disclosureKey)
// ============================================================================

/**
 * 📜 Upsert A Regime Disclosure Section
 *
 * `title`/`body` are OWNER/LEGAL-SUPPLIED content. This handler trims them
 * but deliberately does NOT strip_tags()/htmlspecialchars() at STORAGE time
 * (unlike SecurityUtils::sanitizeString(), used elsewhere for short
 * operational notes) — legitimate legal copy (quotation marks, ampersands,
 * section symbols, etc.) must survive intact. Escaping happens ONLY at
 * OUTPUT time (see index.php's htmlspecialchars() calls on every echo of
 * these values). This handler NEVER invents or pre-fills a value itself —
 * it stores exactly what the admin submitted, verbatim (after trimming).
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleSaveDisclosure(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $regimeID = (int) ($input['regimeID'] ?? 0);
    $disclosureKey = trim((string) ($input['disclosureKey'] ?? ''));
    $title = (array_key_exists('title', $input) && $input['title'] !== null) ? trim((string) $input['title']) : null;
    $body = (array_key_exists('body', $input) && $input['body'] !== null) ? trim((string) $input['body']) : null;

    if ($title === '') {
        $title = null;
    }
    if ($body === '') {
        $body = null;
    }

    if ($regimeID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid regimeID required']);
        return;
    }
    if ($disclosureKey === '' || mb_strlen($disclosureKey) > 64 || !preg_match('/^[A-Za-z0-9_\-.]+$/', $disclosureKey)) {
        echo json_encode(['success' => false, 'error' => 'disclosureKey is required, must be 64 characters or fewer, and may only contain letters, digits, underscore, hyphen, or dot']);
        return;
    }
    if ($title !== null && mb_strlen($title) > 255) {
        echo json_encode(['success' => false, 'error' => 'title must be 255 characters or fewer']);
        return;
    }
    if ($body !== null && mb_strlen($body) > 65000) {
        echo json_encode(['success' => false, 'error' => 'body must be 65000 characters or fewer']);
        return;
    }

    $regime = Database::fetchOne("SELECT regimeID FROM tblComplianceRegimes WHERE regimeID = ?", [$regimeID], 'i');
    if (!$regime) {
        echo json_encode(['success' => false, 'error' => 'Regime not found']);
        return;
    }

    // 🔁 Upsert keyed on the natural (regimeID, disclosureKey) pair.
    $existingDisclosure = Database::fetchOne(
        "SELECT disclosureID FROM tblRegimeDisclosures WHERE regimeID = ? AND disclosureKey = ?",
        [$regimeID, $disclosureKey],
        'is'
    );

    if ($existingDisclosure) {
        $disclosureID = (int) $existingDisclosure['disclosureID'];
        Database::query(
            "UPDATE tblRegimeDisclosures SET title = ?, body = ? WHERE disclosureID = ?",
            [$title, $body, $disclosureID],
            'ssi'
        );
    } else {
        $disclosureID = Database::insert(
            "INSERT INTO tblRegimeDisclosures (regimeID, disclosureKey, title, body) VALUES (?, ?, ?, ?)",
            [$regimeID, $disclosureKey, $title, $body],
            'isss'
        );
    }

    $accessControl->logAdminAction('regime_save_disclosure', 'compliance_regime', $regimeID, ['disclosureKey' => $disclosureKey]);

    echo json_encode(['success' => true, 'data' => ['disclosureID' => $disclosureID, 'regimeID' => $regimeID, 'disclosureKey' => $disclosureKey]]);
}

// ============================================================================
// 🗺️ SAVE / DELETE RESOLUTION RULE
// ============================================================================

/**
 * 🗺️ Create Or Update A tblRegimeResolution Rule Targeting This Regime
 *
 * If `ruleID` is supplied it MUST already belong to `regimeID` (re-checked
 * against the database, not trusted from the client) — otherwise a new rule
 * is inserted targeting this regime.
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleSaveResolution(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $regimeID = (int) ($input['regimeID'] ?? 0);
    $matchType = (string) ($input['matchType'] ?? '');
    $matchValueRaw = (array_key_exists('matchValue', $input) && $input['matchValue'] !== null) ? trim((string) $input['matchValue']) : null;
    $priority = (isset($input['priority']) && $input['priority'] !== '' && is_numeric($input['priority'])) ? (int) $input['priority'] : 100;
    $ruleID = isset($input['ruleID']) ? (int) $input['ruleID'] : 0;

    if ($regimeID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid regimeID required']);
        return;
    }
    if (!in_array($matchType, REGIME_VALID_MATCH_TYPES, true)) {
        echo json_encode(['success' => false, 'error' => 'matchType must be one of: ' . implode(', ', REGIME_VALID_MATCH_TYPES)]);
        return;
    }

    $matchValue = $matchValueRaw;
    if ($matchValue === '') {
        $matchValue = null;
    } elseif ($matchValue !== null && mb_strlen($matchValue) > 64) {
        echo json_encode(['success' => false, 'error' => 'matchValue must be 64 characters or fewer']);
        return;
    }

    $regime = Database::fetchOne("SELECT regimeID FROM tblComplianceRegimes WHERE regimeID = ?", [$regimeID], 'i');
    if (!$regime) {
        echo json_encode(['success' => false, 'error' => 'Regime not found']);
        return;
    }

    if ($ruleID > 0) {
        // 🔒 Re-verify ownership against the DB — never trust that a
        // client-supplied ruleID actually belongs to the claimed regimeID.
        $existingRule = Database::fetchOne(
            "SELECT ruleID FROM tblRegimeResolution WHERE ruleID = ? AND regimeID = ?",
            [$ruleID, $regimeID],
            'ii'
        );
        if (!$existingRule) {
            echo json_encode(['success' => false, 'error' => 'Resolution rule not found for this regime']);
            return;
        }

        Database::query(
            "UPDATE tblRegimeResolution SET matchType = ?, matchValue = ?, priority = ? WHERE ruleID = ?",
            [$matchType, $matchValue, $priority, $ruleID],
            'ssii'
        );
    } else {
        $ruleID = Database::insert(
            "INSERT INTO tblRegimeResolution (matchType, matchValue, regimeID, priority) VALUES (?, ?, ?, ?)",
            [$matchType, $matchValue, $regimeID, $priority],
            'ssii'
        );
    }

    $accessControl->logAdminAction('regime_save_resolution', 'compliance_regime', $regimeID, ['ruleID' => $ruleID, 'matchType' => $matchType, 'matchValue' => $matchValue, 'priority' => $priority]);

    echo json_encode(['success' => true, 'data' => ['ruleID' => $ruleID, 'regimeID' => $regimeID]]);
}

/**
 * 🗑️ Delete A tblRegimeResolution Rule
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleDeleteResolution(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $ruleID = (int) ($input['ruleID'] ?? 0);
    $regimeID = (int) ($input['regimeID'] ?? 0);

    if ($ruleID <= 0 || $regimeID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid ruleID and regimeID required']);
        return;
    }

    // 🔒 Re-verify ownership against the DB before deleting anything.
    $existingRule = Database::fetchOne(
        "SELECT ruleID FROM tblRegimeResolution WHERE ruleID = ? AND regimeID = ?",
        [$ruleID, $regimeID],
        'ii'
    );
    if (!$existingRule) {
        echo json_encode(['success' => false, 'error' => 'Resolution rule not found for this regime']);
        return;
    }

    Database::query("DELETE FROM tblRegimeResolution WHERE ruleID = ?", [$ruleID], 'i');

    $accessControl->logAdminAction('regime_delete_resolution', 'compliance_regime', $regimeID, ['ruleID' => $ruleID]);

    echo json_encode(['success' => true, 'data' => ['ruleID' => $ruleID]]);
}

// ============================================================================
// 🟢 ACTIVATE / 🔴 DEACTIVATE
// ============================================================================

/**
 * 🟢 Activate A Regime — THE CRUX (spec §4.4 AC)
 *
 * Re-reads the row FRESH from the database and runs it through
 * regimeCanActivate() (declared at the very top of this file) — the client's
 * request body is never consulted for the legal values themselves, only for
 * which regimeID to check. A regime with any of dsarSlaDays/minAge/
 * breachNotifyHours still NULL is REJECTED and isActive is left untouched.
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleActivateRegime(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $regimeID = (int) ($input['regimeID'] ?? 0);
    if ($regimeID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid regimeID required']);
        return;
    }

    // 🔒 THE CRUX: read the STORED row — never the client's request body.
    $row = Database::fetchOne("SELECT * FROM tblComplianceRegimes WHERE regimeID = ?", [$regimeID], 'i');
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Regime not found']);
        return;
    }

    [$canActivate, $missingFields] = regimeCanActivate($row);
    if (!$canActivate) {
        echo json_encode([
            'success' => false,
            'error'   => 'Cannot activate: fill in ' . implode(', ', $missingFields) . ' first',
        ]);
        return; // 🚫 isActive/isDraft are NEVER touched on this path.
    }

    Database::query("UPDATE tblComplianceRegimes SET isActive = 1, isDraft = 0 WHERE regimeID = ?", [$regimeID], 'i');

    $accessControl->logAdminAction('regime_activate_regime', 'compliance_regime', $regimeID, ['regimeCode' => $row['regimeCode']]);

    echo json_encode(['success' => true, 'data' => ['regimeID' => $regimeID, 'isActive' => true, 'isDraft' => false]]);
}

/**
 * 🔴 Deactivate A Regime — always allowed, no gate
 *
 * Sets isActive=0 only; isDraft is left as-is (an operator may deliberately
 * deactivate a previously-confirmed, non-draft regime — see
 * RegimeResolver's own test suite, which documents that isActive=0/isDraft=0
 * is a valid, expected combination).
 *
 * @param array<string,mixed> $input
 * @param AccessControl       $accessControl
 * @param int                 $adminUserID
 * @return void Echoes JSON
 */
function handleDeactivateRegime(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $regimeID = (int) ($input['regimeID'] ?? 0);
    if ($regimeID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid regimeID required']);
        return;
    }

    $row = Database::fetchOne("SELECT regimeID, regimeCode FROM tblComplianceRegimes WHERE regimeID = ?", [$regimeID], 'i');
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Regime not found']);
        return;
    }

    Database::query("UPDATE tblComplianceRegimes SET isActive = 0 WHERE regimeID = ?", [$regimeID], 'i');

    $accessControl->logAdminAction('regime_deactivate_regime', 'compliance_regime', $regimeID, ['regimeCode' => $row['regimeCode']]);

    echo json_encode(['success' => true, 'data' => ['regimeID' => $regimeID, 'isActive' => false]]);
}
