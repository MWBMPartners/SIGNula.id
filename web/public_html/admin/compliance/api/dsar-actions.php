<?php
/**
 * ============================================================================
 * 📋 SIGNula - Data Subject Request (DSAR) Admin Actions API (G-004 Layer 1 / Cycle C)
 * ============================================================================
 *
 * Purpose: AJAX endpoint backing web/public_html/admin/compliance/dsar/index.php.
 *          Every mutation is POST-only + CSRF-checked; every action (mutating
 *          or not) requires an authenticated Super Admin session. Responses
 *          always use the {success, data|error} shape (house rule B-011).
 *
 * Actions:
 *   - get_detail                  (GET)  — request row + event timeline
 *   - transition                  (POST) — guarded status change via DSARManager::transition()
 *   - fulfil_rectification        (POST) — admin-approved field corrections via DSARManager::fulfilRectification()
 *   - fulfil_portability          (POST) — see "PASSWORD-GATED FULFILMENT" below — NEVER fabricates a password
 *   - fulfil_access               (POST) — see "PASSWORD-GATED FULFILMENT" below — NEVER fabricates a password
 *   - fulfil_erasure              (POST) — see "PASSWORD-GATED FULFILMENT" below — NEVER fabricates a password
 *   - start_identity_verification (POST) — issues + emails a fresh verification token; NEVER returns the raw token
 *
 * ----------------------------------------------------------------------------
 * 🔒 PASSWORD-GATED FULFILMENT — why fulfil_portability/access/erasure do NOT
 * call DSARManager::fulfilPortability()/fulfilAccess()/fulfilErasure()
 * ----------------------------------------------------------------------------
 * Those three DSARManager methods delegate to AccountManager::exportUserData()
 * / AccountManager::requestAccountDeletion() — both of which VERIFY THE DATA
 * SUBJECT'S OWN CURRENT PASSWORD before doing anything (that verification is
 * the entire point: it proves the person acting really is the account owner).
 * An admin operating this queue does not know — and must never be given a way
 * to substitute — that password. Any code path that invented a password,
 * bypassed the check, or reused a stored hash to satisfy it would silently
 * defeat the exact control that makes those two AccountManager methods safe
 * to expose at all (mass impersonation of any account from the admin panel).
 *
 * So, for these three request types, this endpoint does NOT drive
 * AccountManager AT ALL. It only records the admin's decision by transitioning
 * the DSAR to 'awaiting_user' (bridging through 'in_progress' first if
 * needed — DSARManager's transition map only allows 'in_progress' ->
 * 'awaiting_user') with a note explaining fulfilment must be completed by the
 * subject themselves from Settings → Privacy, where their OWN "Export My
 * Data" / "Delete Your Account" buttons already call exactly those same
 * AccountManager methods with their OWN password. The queue tracks the
 * request; the subject remains the only party who can complete it. This is
 * the deliberate, correct security posture for a password-gated action — see
 * the build report for this cycle for the full justification.
 *
 * @see web/private_html/compliance/DSARManager.php
 * @see web/private_html/auth/AccountManager.php — the password-gated engine deliberately NOT driven here
 * @see web/public_html/admin/api/payment-actions.php — auth/CSRF/response-shape template this mirrors
 * @see .dev-team/specs/G-004.md §2.6, §6
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\Compliance\DSAR\API
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// ============================================================================
// 🛡️ SIGNULA_INIT GUARD + BOOTSTRAP
// ============================================================================

// ⚠️ PATH DEPTH: __DIR__ here is web/public_html/admin/compliance/api — FOUR
// levels below web/ (public_html -> admin -> compliance -> api), the SAME
// depth as the sibling dsar/index.php (compliance/dsar and compliance/api are
// both two levels below admin/) — dirname(__DIR__, 4) reaches web/. Verified
// at build time — see the build report for the literal resolved path proof.
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

header('Content-Type: application/json');

// 🛡️ Clickjacking defence on this compliance-data admin surface (spec §6 /
// house lesson #29).
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

// 🔐 Require super admin — compliance data is higher-sensitivity than
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

// 🛡️ CSRF for POST requests — every mutation on this endpoint is POST-only
// (heeds spec §6's "fix the #28 GET-action gap by making all compliance
// admin mutations POST-only").
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
        exit;
    }
}

// 📚 DSARManager lives outside config.php's autoload dirs — guarded require
// mirrors DSARManager's own idiom for its sibling dependency.
if (!class_exists('DSARManager')) {
    require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'DSARManager.php';
}

/**
 * ✅ Request types that DELEGATE to AccountManager's password-gated engine
 * when fulfilled the "normal" (subject-initiated) way — see the
 * "PASSWORD-GATED FULFILMENT" docblock above for why the admin-side
 * fulfil_* actions for these three NEVER call DSARManager::fulfilPortability()/
 * fulfilAccess()/fulfilErasure() directly.
 *
 * @var array<int,string>
 */
const DSAR_PASSWORD_GATED_TYPES = ['portability', 'access', 'erasure'];

try {
    switch ($action) {
        case 'get_detail':
            handleGetDetail();
            break;

        case 'transition':
            handleTransition($input, $accessControl, $adminUserID);
            break;

        case 'fulfil_rectification':
            handleFulfilRectification($input, $accessControl, $adminUserID);
            break;

        case 'fulfil_portability':
            handlePasswordGatedFulfilTracking($input, $accessControl, $adminUserID, 'portability');
            break;

        case 'fulfil_access':
            handlePasswordGatedFulfilTracking($input, $accessControl, $adminUserID, 'access');
            break;

        case 'fulfil_erasure':
            handlePasswordGatedFulfilTracking($input, $accessControl, $adminUserID, 'erasure');
            break;

        case 'start_identity_verification':
            handleStartIdentityVerification($input, $accessControl, $adminUserID);
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
// 🔍 GET DETAIL
// ============================================================================

/**
 * 🔍 Return A Single Request Plus Its Event Timeline
 *
 * @return void Echoes JSON
 */
function handleGetDetail(): void
{
    $requestID = (int) ($_GET['requestID'] ?? 0);

    if ($requestID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid requestID required']);
        return;
    }

    $request = Database::fetchOne(
        "SELECT * FROM tblDataSubjectRequests WHERE requestID = ?",
        [$requestID],
        'i'
    );

    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'Request not found']);
        return;
    }

    // 🔐 Never echo the raw packed IP bytes or the hashed verification token
    // to the client — neither is useful to the admin UI and both are
    // sensitive columns (spec §6 "PII minimisation").
    unset($request['ipAddress'], $request['verificationToken']);

    $events = Database::fetchAll(
        "SELECT eventID, fromStatus, toStatus, actorType, actorID, note, createdAt
         FROM tblDataSubjectRequestEvents WHERE requestID = ? ORDER BY createdAt ASC, eventID ASC",
        [$requestID],
        'i'
    );

    echo json_encode(['success' => true, 'data' => ['request' => $request, 'events' => $events]]);
}

// ============================================================================
// 🔄 TRANSITION
// ============================================================================

/**
 * 🔄 Apply A Guarded State Transition
 *
 * @param array         $input
 * @param AccessControl $accessControl
 * @param int           $adminUserID
 * @return void Echoes JSON
 */
function handleTransition(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $requestID = (int) ($input['requestID'] ?? 0);
    $toStatus = SecurityUtils::sanitizeString((string) ($input['toStatus'] ?? ''));
    $note = isset($input['note']) ? SecurityUtils::sanitizeString((string) $input['note']) : null;
    $note = $note !== '' ? $note : null;

    if ($requestID <= 0 || $toStatus === '') {
        echo json_encode(['success' => false, 'error' => 'Valid requestID and toStatus are required']);
        return;
    }

    try {
        DSARManager::transition($requestID, $toStatus, 'admin', $adminUserID, $note);
    } catch (\InvalidDSARTransitionException $e) {
        // 🔒 The guarded state machine is the source of truth — surface its
        // rejection message rather than pretending the mutation happened.
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    }

    // 🔍 Audit-of-the-auditors — DSARManager::transition() already writes
    // tblDataSubjectRequestEvents + tblActivityLog itself; this ALSO records
    // the action in tblAdminAuditLog (spec §6: "every admin action on
    // compliance data writes AccessControl::logAdminAction() + tblActivityLog").
    $accessControl->logAdminAction('dsar_transition', 'dsar_request', $requestID, ['toStatus' => $toStatus, 'note' => $note]);

    echo json_encode(['success' => true, 'data' => ['requestID' => $requestID, 'status' => $toStatus]]);
}

// ============================================================================
// ✏️ FULFIL RECTIFICATION (no password needed — admin-approved correction)
// ============================================================================

/**
 * ✏️ Apply An Admin-Approved Rectification
 *
 * @param array         $input
 * @param AccessControl $accessControl
 * @param int           $adminUserID
 * @return void Echoes JSON
 */
function handleFulfilRectification(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $requestID = (int) ($input['requestID'] ?? 0);
    $approvedChanges = is_array($input['approvedChanges'] ?? null) ? $input['approvedChanges'] : [];

    if ($requestID <= 0 || empty($approvedChanges)) {
        echo json_encode(['success' => false, 'error' => 'Valid requestID and at least one approved change are required']);
        return;
    }

    try {
        $result = DSARManager::fulfilRectification($requestID, $approvedChanges);
    } catch (\InvalidDSARTransitionException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    }

    if (empty($result['success'])) {
        echo json_encode(['success' => false, 'error' => $result['message'] ?? 'Rectification failed', 'data' => $result]);
        return;
    }

    $accessControl->logAdminAction('dsar_rectification_applied', 'dsar_request', $requestID, ['applied' => $result['applied'], 'rejected' => $result['rejected']]);

    echo json_encode(['success' => true, 'data' => $result]);
}

// ============================================================================
// 🔒 PASSWORD-GATED FULFILMENT — track only, NEVER fabricate a password
// ============================================================================

/**
 * 🔒 Mark A Password-Gated Request As Awaiting The Subject
 *
 * See the "PASSWORD-GATED FULFILMENT" docblock at the top of this file for
 * the full rationale. This function performs ONLY a guarded status
 * transition (bridging through 'in_progress' first if required — the
 * transition map only allows 'in_progress' -> 'awaiting_user') with an
 * explanatory note. It NEVER calls AccountManager, NEVER touches a password,
 * and NEVER performs the actual export/erasure — that remains exclusively
 * the data subject's own action from Settings -> Privacy.
 *
 * @param array         $input
 * @param AccessControl $accessControl
 * @param int           $adminUserID
 * @param string        $requestType 'portability'|'access'|'erasure' — used only for the note text
 * @return void Echoes JSON
 */
function handlePasswordGatedFulfilTracking(array $input, AccessControl $accessControl, int $adminUserID, string $requestType): void
{
    if (!in_array($requestType, DSAR_PASSWORD_GATED_TYPES, true)) {
        // 🛡️ Defensive — this function is only ever called with a literal
        // from the switch() above, but fail closed regardless.
        echo json_encode(['success' => false, 'error' => 'Unsupported request type for this action']);
        return;
    }

    $requestID = (int) ($input['requestID'] ?? 0);
    if ($requestID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid requestID required']);
        return;
    }

    $note = "Fulfilment of '{$requestType}' requests requires the data subject's OWN password "
        . "(AccountManager verifies it before export/erasure proceeds) and cannot be completed by an "
        . "admin. This request has been marked as awaiting the subject to complete it themselves via "
        . "Settings \xE2\x86\x92 Privacy.";

    $current = Database::fetchOne("SELECT status FROM tblDataSubjectRequests WHERE requestID = ?", [$requestID], 'i');
    if (!$current) {
        echo json_encode(['success' => false, 'error' => 'Request not found']);
        return;
    }

    try {
        // 🌉 Bridge to 'in_progress' first if not already there — the
        // transition map only allows 'in_progress' -> 'awaiting_user'
        // (mirrors DSARManager's own private ensureInProgress() helper,
        // which this endpoint cannot call directly as it is private).
        if ($current['status'] !== 'in_progress') {
            DSARManager::transition($requestID, 'in_progress', 'admin', $adminUserID, 'Auto-advanced to in_progress for fulfilment tracking');
        }

        DSARManager::transition($requestID, 'awaiting_user', 'admin', $adminUserID, $note);
    } catch (\InvalidDSARTransitionException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    }

    $accessControl->logAdminAction('dsar_marked_awaiting_subject', 'dsar_request', $requestID, ['requestType' => $requestType]);

    echo json_encode(['success' => true, 'data' => ['requestID' => $requestID, 'status' => 'awaiting_user']]);
}

// ============================================================================
// 🔐 START / RE-SEND IDENTITY VERIFICATION
// ============================================================================

/**
 * 🔐 Issue A Fresh Identity-Verification Token And Email It
 *
 * Never returns the raw token in the JSON response — DSARManager persists
 * only its SHA-256 hash, and this handler emails the raw value directly to
 * the request's own on-file address without ever putting it on the wire back
 * to the admin's browser.
 *
 * @param array         $input
 * @param AccessControl $accessControl
 * @param int           $adminUserID
 * @return void Echoes JSON
 */
function handleStartIdentityVerification(array $input, AccessControl $accessControl, int $adminUserID): void
{
    $requestID = (int) ($input['requestID'] ?? 0);
    if ($requestID <= 0) {
        echo json_encode(['success' => false, 'error' => 'Valid requestID required']);
        return;
    }

    $request = Database::fetchOne(
        "SELECT userID, requesterEmail, requestType FROM tblDataSubjectRequests WHERE requestID = ?",
        [$requestID],
        'i'
    );

    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'Request not found']);
        return;
    }

    // 📧 Prefer the requesterEmail captured at submission time; fall back to
    // the linked user's on-file email if the request has a userID but no
    // separately-captured requesterEmail (e.g. submitted from settings/privacy.php).
    $recipientEmail = $request['requesterEmail'] ?? null;
    if (empty($recipientEmail) && !empty($request['userID'])) {
        $userRow = Database::fetchOne("SELECT email FROM tblUsers WHERE userID = ?", [(int) $request['userID']], 'i');
        $recipientEmail = $userRow['email'] ?? null;
    }

    if (empty($recipientEmail)) {
        echo json_encode(['success' => false, 'error' => 'No email address on file for this request']);
        return;
    }

    try {
        $rawToken = DSARManager::startIdentityVerification($requestID);
    } catch (\InvalidDSARTransitionException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    }

    // 📧 Build + queue the verification email — same builder DataRequestIntake
    // uses for the public form's flow, kept consistent between both channels.
    if (!class_exists('EmailTemplateBuilder')) {
        $builderPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . 'EmailTemplateBuilder.php';
        if (file_exists($builderPath)) {
            require_once $builderPath;
        }
    }

    $baseURL = getSetting('url.base', 'https://SIGNula.id');
    $verifyURL = "{$baseURL}/legal/data-request/verify?requestID=" . (int) $requestID . '&token=' . rawurlencode($rawToken);
    $ttlMinutes = (int) getSetting('dsar.identity_token.ttl_minutes', 30);

    $bodyContent = '<h1 style="margin:0 0 16px;">Verify your data request</h1>'
        . '<p>An administrator has re-sent the identity verification link for your data-subject request '
        . '(reference #' . (int) $requestID . '). To continue, please verify it\'s really you by clicking the button below.</p>';

    if (class_exists('EmailTemplateBuilder')) {
        $bodyContent .= EmailTemplateBuilder::buildButton('Verify My Request', $verifyURL);
        $html = EmailTemplateBuilder::wrapInLayout($bodyContent, 'Verify your SIGNula data request', 'Verify Your Data Request');
    } else {
        $bodyContent .= '<p><a href="' . htmlspecialchars($verifyURL, ENT_QUOTES, 'UTF-8') . '">Verify My Request</a></p>';
        $html = $bodyContent;
    }

    $bodyContent .= '<p>This link expires in ' . $ttlMinutes . ' minutes.</p>';

    EmailService::sendRichEmail(
        (string) $recipientEmail,
        'Verify Your Data Request - SIGNula',
        $html,
        null,
        null,
        !empty($request['userID']) ? (int) $request['userID'] : null,
        1
    );

    $accessControl->logAdminAction('dsar_identity_verification_resent', 'dsar_request', $requestID, []);

    // 🔒 The response NEVER includes the raw token — only a success flag.
    echo json_encode(['success' => true, 'data' => ['requestID' => $requestID]]);
}
