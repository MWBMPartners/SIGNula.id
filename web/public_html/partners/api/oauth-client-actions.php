<?php
/**
 * ============================================================================
 * 🪪 SIGNula - Partner OAuth Client Management API (G-001 Stage A1)
 * ============================================================================
 *
 * JSON API endpoint for a partner to manage its own OAuth2/OIDC clients
 * ("Sign in with SIGNula.id" app registrations). Mirrors the action-routing
 * style of partners/api/tier-actions.php. Called via fetch()/AJAX from
 * /partners/admin/oauth-clients.php — that page ALSO works standalone via
 * plain form POSTs (see its own header note), so this endpoint is an
 * additive, optional AJAX surface, not the only way to manage a client.
 *
 * Supported Actions:
 * ──────────────────────────────────────────────────────────────────────────
 * GET  list_clients     → List all OAuth clients owned by a partner
 * GET  get_client       → Retrieve a single client by clientID (ownership-checked)
 * POST register_client  → Register a new OAuth2/OIDC client
 * POST rotate_secret    → Rotate a confidential client's secret
 * POST deactivate_client→ Deactivate an OAuth client
 * ──────────────────────────────────────────────────────────────────────────
 *
 * Security:
 * - Every action requires an authenticated session (Auth::isAuthenticated()).
 * - Every action requires partner-admin role for the target partnerID (see
 *   userIsPartnerAdmin() below) — a partner can NEVER see or modify another
 *   partner's clients (IDOR defence: ownership is re-checked server-side on
 *   every request, never trusted from the client).
 * - POST actions require a valid CSRF token (SecurityUtils::verifyCSRFToken()).
 * - Backend class: OAuthClientManager (web/private_html/auth/).
 *
 * ⚠️ NEEDS-LEAD-REVIEW: this file deliberately does NOT reuse
 * web/_backend/AccessControl.php / a SessionManager class the way the OTHER
 * partners/admin + partners/api files do. Verified while building this stage:
 *   • web/_backend/SessionManager.php does not exist anywhere in this repo
 *     (confirmed via `git log --all` — it has never been committed), so every
 *     file requiring it (~74 files under web/public_html/, including
 *     partners/admin/*.php, partners/api/*-actions.php, and several
 *     admin/*.php files) fatals with "Failed opening required" the moment it
 *     is requested.
 *   • Even bridging that gap, web/_backend/AccessControl.php's
 *     loadPartnerMemberships() selects `p.companyName`/`p.tier` from
 *     tblPartners — columns that do not exist in the current schema (the real
 *     columns are `partnerName`/`accountTier`, per
 *     _database/migrations/008_partner_api_keys.sql). Under this project's
 *     STRICT_ALL_TABLES + mysqli_report(MYSQLI_REPORT_ERROR) mode that throws
 *     an uncaught mysqli_sql_exception. Reproduced directly against the real
 *     signula_test database while building this stage.
 * This endpoint instead authenticates via the CONFIRMED WORKING
 * web/private_html/auth/Auth.php static API (already used by the shipped
 * oauth/authorize.php consumer endpoint) and does its own small, direct
 * tblPartnerTeamMembers ownership check (see userIsPartnerAdmin() below) —
 * it does not touch or fix the broader broken AccessControl/SessionManager
 * chain, which is out of scope for G-001 Stage A1.
 *
 * @see web/private_html/auth/OAuthClientManager.php
 * @see web/public_html/partners/admin/oauth-clients.php
 * @see web/public_html/partners/api/tier-actions.php (the action-routing style mirrored here)
 * @see _database/migrations/031_oauth_provider_clients.sql
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Partners\API
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A1)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 📦 DEPENDENCY LOADING
// ============================================================================

// 🔧 Load core configuration (defines SIGNULA_INIT, DB credentials, the
//    private_html/ class autoloader, redirect()/jsonResponse() helpers, …).
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🪪 OAuth client manager (also auto-loadable via config.php's spl_autoload
//    hook — explicit require kept for clarity/defence-in-depth).
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'OAuthClientManager.php';

// ============================================================================
// 🛡️ SHARED PARTNER-OWNERSHIP HELPERS
// ============================================================================
// Small, self-contained stand-ins for the broken AccessControl/SessionManager
// chain (see the header note above) — query tblPartnerTeamMembers directly
// via the working static Database:: class.

if (!function_exists('oauthClientsUserIsSuperAdmin')) {
    /**
     * 👑 Is this user a GLOBAL super-admin? (may manage any partner's clients)
     *
     * @param int $userID
     * @return bool
     */
    function oauthClientsUserIsSuperAdmin(int $userID): bool
    {
        $row = Database::fetchOne(
            "SELECT isSuperAdmin FROM tblUsers WHERE userID = ?",
            [$userID],
            'i'
        );

        return $row !== null && (bool) ($row['isSuperAdmin'] ?? false);
    }
}

if (!function_exists('oauthClientsUserIsPartnerAdmin')) {
    /**
     * 🛡️ Does this user have admin-level access (root-admin|admin, active
     * status) for the given partner — OR is a global super-admin?
     *
     * @param int $userID
     * @param int $partnerID
     * @return bool
     */
    function oauthClientsUserIsPartnerAdmin(int $userID, int $partnerID): bool
    {
        if (oauthClientsUserIsSuperAdmin($userID)) {
            return true;
        }

        $membership = Database::fetchOne(
            "SELECT role FROM tblPartnerTeamMembers
             WHERE userID = ? AND partnerID = ? AND status = 'active' AND role IN ('root-admin', 'admin')",
            [$userID, $partnerID],
            'ii'
        );

        return $membership !== null;
    }
}

// ============================================================================
// 📡 RESPONSE CONFIGURATION
// ============================================================================

header('Content-Type: application/json; charset=utf-8');

// ============================================================================
// 🔒 AUTHENTICATION CHECK
// ============================================================================

if (!Auth::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized — please log in to continue']);
    exit;
}

$currentUserID = Auth::getCurrentUserID();

// ============================================================================
// 📥 REQUEST PARSING
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $input  = $_GET;
} else {
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode($rawBody, true);

    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        // 🧾 Fall back to a regular urlencoded form POST (progressive
        //    enhancement — the admin page also works with plain <form> POSTs).
        $input = $_POST;
    } else {
        $input = is_array($decoded) ? $decoded : [];
    }

    $action = $input['action'] ?? '';
}

// ============================================================================
// 🛡️ CSRF VERIFICATION (POST REQUESTS ONLY)
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired security token. Please refresh and try again.']);
        exit;
    }
}

// ============================================================================
// 🎯 ACTION ROUTING
// ============================================================================

try {
    switch ($action) {
        case 'list_clients':
            handleListClients($input, $currentUserID);
            break;

        case 'get_client':
            handleGetClient($input, $currentUserID);
            break;

        case 'register_client':
            handleRegisterClient($input, $currentUserID);
            break;

        case 'rotate_secret':
            handleRotateSecret($input, $currentUserID);
            break;

        case 'deactivate_client':
            handleDeactivateClient($input, $currentUserID);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action specified: "' . htmlspecialchars((string) $action, ENT_QUOTES, 'UTF-8') . '"']);
            break;
    }
} catch (\Throwable $e) {
    // 🚨 Never leak internal error details to the client.
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again later.']);
    error_log('[SIGNula] oauth-client-actions.php unhandled exception: ' . $e->getMessage());
}

// ============================================================================
// 🔧 HANDLER FUNCTIONS
// ============================================================================

/**
 * 📋 List all OAuth clients for a partner (ownership-checked).
 *
 * @param array $input
 * @param int   $userID
 * @return void
 */
function handleListClients(array $input, int $userID): void
{
    $partnerID = (int) ($input['partnerID'] ?? 0);
    if ($partnerID <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'partnerID is required']);
        return;
    }

    if (!oauthClientsUserIsPartnerAdmin($userID, $partnerID)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required for this partner']);
        return;
    }

    $clients = OAuthClientManager::listClients($partnerID);

    // 🔒 Never include clientSecretHash in the API response, even hashed.
    foreach ($clients as &$client) {
        unset($client['clientSecretHash']);
    }
    unset($client);

    echo json_encode(['success' => true, 'data' => $clients, 'count' => count($clients)]);
}

/**
 * 🔍 Retrieve a single client (ownership-checked).
 *
 * @param array $input
 * @param int   $userID
 * @return void
 */
function handleGetClient(array $input, int $userID): void
{
    $clientID = (int) ($input['clientID'] ?? 0);
    if ($clientID <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'clientID is required']);
        return;
    }

    $client = OAuthClientManager::getClientByID($clientID);
    if ($client === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'OAuth client not found']);
        return;
    }

    // 🛡️ OWNERSHIP CHECK — a partner may only read ITS OWN clients. A
    //    first-party client (partnerID NULL) is only visible to super-admins.
    $ownerPartnerID = $client['partnerID'] !== null ? (int) $client['partnerID'] : null;
    if ($ownerPartnerID === null) {
        if (!oauthClientsUserIsSuperAdmin($userID)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'This client does not belong to your organisation']);
            return;
        }
    } elseif (!oauthClientsUserIsPartnerAdmin($userID, $ownerPartnerID)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This client does not belong to your organisation']);
        return;
    }

    unset($client['clientSecretHash']);
    echo json_encode(['success' => true, 'data' => $client]);
}

/**
 * ➕ Register a new OAuth client for a partner.
 *
 * @param array $input
 * @param int   $userID
 * @return void
 */
function handleRegisterClient(array $input, int $userID): void
{
    $partnerID = (int) ($input['partnerID'] ?? 0);
    if ($partnerID <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'partnerID is required']);
        return;
    }

    if (!oauthClientsUserIsPartnerAdmin($userID, $partnerID)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required to register OAuth clients for this partner']);
        return;
    }

    $clientName    = (string) ($input['clientName'] ?? '');
    $clientType    = (string) ($input['clientType'] ?? 'confidential');
    $redirectUris  = is_array($input['redirectUris'] ?? null) ? $input['redirectUris'] : [];
    $allowedScopes = is_array($input['allowedScopes'] ?? null) ? $input['allowedScopes'] : [];

    if ($clientName === '') {
        echo json_encode(['success' => false, 'message' => 'Client name is required']);
        return;
    }
    if (empty($redirectUris)) {
        echo json_encode(['success' => false, 'message' => 'At least one redirect_uri is required']);
        return;
    }
    if (empty($allowedScopes)) {
        echo json_encode(['success' => false, 'message' => 'At least one scope is required']);
        return;
    }

    $result = OAuthClientManager::registerClient(
        $partnerID,
        $clientName,
        $clientType,
        $redirectUris,
        $allowedScopes,
        ['createdBy' => $userID]
    );

    if ($result === false) {
        echo json_encode(['success' => false, 'message' => 'Could not register OAuth client — check the client type, name, redirect URIs, and scopes.']);
        return;
    }

    // 🔒 clientSecret is the PLAINTEXT secret — shown ONCE in this very
    //    response, never retrievable again. The nested 'client' row's hash is
    //    stripped so it never round-trips to the browser either.
    if (isset($result['client']['clientSecretHash'])) {
        unset($result['client']['clientSecretHash']);
    }

    echo json_encode(['success' => true, 'message' => 'OAuth client registered successfully', 'data' => $result]);
}

/**
 * 🔄 Rotate a confidential client's secret (ownership-checked).
 *
 * @param array $input
 * @param int   $userID
 * @return void
 */
function handleRotateSecret(array $input, int $userID): void
{
    $clientID = (int) ($input['clientID'] ?? 0);
    if ($clientID <= 0) {
        echo json_encode(['success' => false, 'message' => 'clientID is required']);
        return;
    }

    $client = OAuthClientManager::getClientByID($clientID);
    if ($client === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'OAuth client not found']);
        return;
    }

    if ($client['partnerID'] === null || !oauthClientsUserIsPartnerAdmin($userID, (int) $client['partnerID'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This client does not belong to your organisation']);
        return;
    }

    $result = OAuthClientManager::rotateSecret($clientID);
    if ($result === false) {
        echo json_encode(['success' => false, 'message' => 'Could not rotate secret (public clients have no secret to rotate).']);
        return;
    }

    if (isset($result['client']['clientSecretHash'])) {
        unset($result['client']['clientSecretHash']);
    }

    echo json_encode(['success' => true, 'message' => 'Client secret rotated — copy it now, it will not be shown again.', 'data' => $result]);
}

/**
 * 🚫 Deactivate an OAuth client (ownership-checked).
 *
 * @param array $input
 * @param int   $userID
 * @return void
 */
function handleDeactivateClient(array $input, int $userID): void
{
    $clientID = (int) ($input['clientID'] ?? 0);
    if ($clientID <= 0) {
        echo json_encode(['success' => false, 'message' => 'clientID is required']);
        return;
    }

    $client = OAuthClientManager::getClientByID($clientID);
    if ($client === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'OAuth client not found']);
        return;
    }

    if ($client['partnerID'] === null || !oauthClientsUserIsPartnerAdmin($userID, (int) $client['partnerID'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This client does not belong to your organisation']);
        return;
    }

    $success = OAuthClientManager::deactivate($clientID);

    echo json_encode([
        'success' => $success,
        'message' => $success ? 'OAuth client deactivated' : 'Could not deactivate OAuth client',
    ]);
}
