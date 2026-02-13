<?php
/**
 * ============================================================================
 * 💰 SIGNula - Partner Subscription Tier Management API
 * ============================================================================
 *
 * JSON API endpoint for managing partner subscription tiers. Handles all
 * CRUD operations for the tiers that partners offer to their own customers.
 * Called via AJAX from the admin UI at /partners/admin/tiers.php.
 *
 * Supported Actions:
 * ──────────────────────────────────────────────────────────────────────────
 * GET  get_tiers     → Retrieve all tiers for a partner (active + inactive)
 * GET  get_tier      → Retrieve a single tier by tierID (with ownership check)
 * POST create_tier   → Create a new subscription tier
 * POST update_tier   → Update an existing subscription tier
 * POST delete_tier   → Deactivate (soft-delete) a subscription tier
 * POST activate_tier → Reactivate a previously deactivated tier
 * POST set_default   → Set a tier as the default for new subscribers
 * ──────────────────────────────────────────────────────────────────────────
 *
 * Security:
 * - All actions require authenticated session (SessionManager::isLoggedIn())
 * - All actions require partner admin role (AccessControl::isPartnerAdmin())
 * - POST actions require valid CSRF token (SecurityUtils::verifyCSRFToken())
 * - Tier ownership is verified before any read/write operation
 *
 * Backend Class: PartnerPaymentService (web/private_html/payments/)
 * Database Table: tblPartnerSubscriptionTiers
 * Admin UI: /partners/admin/tiers.php
 *
 * @see /web/private_html/payments/PartnerPaymentService.php
 * @see /_database/migrations/012_payment_expansion.sql
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Partners\API
 * @version    1.0.0
 * @since      2.4.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 📦 DEPENDENCY LOADING
// ============================================================================

// 🔧 Load core configuration (defines SIGNULA_INIT constant, DB credentials, etc.)
// dirname(__DIR__, 3) resolves to /web/ from /web/public_html/partners/api/
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 3) . '/_config/config.php';

// 🗄️ Database singleton — provides MySQLi prepared statement interface
// @see https://www.php.net/manual/en/book.mysqli.php
require_once dirname(__DIR__, 3) . '/_backend/Database.php';

// 🔐 Session management — handles login state, session tokens, regeneration
// @see https://www.php.net/manual/en/book.session.php
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';

// 🛡️ Access control — role-based permission checks for partner admin actions
require_once dirname(__DIR__, 3) . '/_backend/AccessControl.php';

// 📝 Activity logger — logs all admin actions for audit trail
require_once dirname(__DIR__, 3) . '/_backend/ActivityLogger.php';

// 💰 Partner payment service — subscription tier CRUD operations
// @see /web/private_html/payments/PartnerPaymentService.php
require_once dirname(__DIR__, 3) . '/private_html/payments/PartnerPaymentService.php';

// ============================================================================
// 📡 RESPONSE CONFIGURATION
// ============================================================================

// 📋 Set Content-Type header to JSON for all responses from this endpoint
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
header('Content-Type: application/json');

// ============================================================================
// 🚀 SERVICE INITIALISATION
// ============================================================================

// 📡 Instantiate core services via singleton/DI pattern
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);
$activityLogger = new ActivityLogger($db, $sessionManager);

// ============================================================================
// 🔒 AUTHENTICATION CHECK
// ============================================================================

// 🚫 Reject unauthenticated requests with HTTP 401 Unauthorized
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/401
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized — please log in to continue'
    ]);
    exit;
}

// ============================================================================
// 📥 REQUEST PARSING
// ============================================================================

// 📋 Determine the action based on request method
// GET requests: action from URL parameter
// POST requests: action from JSON body
// @see https://www.php.net/manual/en/reserved.variables.server.php
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 🔍 GET requests — read-only operations (get_tiers, get_tier)
    $action = $_GET['action'] ?? '';
    $input = $_GET; // Use URL parameters as input data
} else {
    // 📝 POST requests — state-changing operations (create, update, delete, etc.)
    // Read JSON body from php://input stream
    // @see https://www.php.net/manual/en/wrappers.php.php#wrappers.php.input
    $input = json_decode(file_get_contents('php://input'), true);

    // 🛡️ Validate that the JSON body was parsed successfully
    if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON in request body'
        ]);
        exit;
    }

    $action = $input['action'] ?? '';
}

// ============================================================================
// 🛡️ CSRF VERIFICATION (POST REQUESTS ONLY)
// ============================================================================

// 🔐 Verify CSRF token for all state-changing (POST) requests to prevent
// cross-site request forgery attacks.
// The token is sent either in the JSON body or as an X-CSRF-TOKEN header.
// @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired CSRF token. Please refresh the page and try again.'
        ]);
        exit;
    }
}

// ============================================================================
// 🎯 ACTION ROUTING
// ============================================================================

// 📋 Route the request to the appropriate handler function based on action
// Each handler is responsible for its own access control and validation
try {
    switch ($action) {
        // 🔍 GET: Retrieve all tiers for a partner
        case 'get_tiers':
            handleGetTiers($input, $db, $accessControl);
            break;

        // 🔍 GET: Retrieve a single tier by ID
        case 'get_tier':
            handleGetTier($input, $db, $accessControl);
            break;

        // ➕ POST: Create a new subscription tier
        case 'create_tier':
            handleCreateTier($input, $db, $accessControl, $activityLogger);
            break;

        // ✏️ POST: Update an existing subscription tier
        case 'update_tier':
            handleUpdateTier($input, $db, $accessControl, $activityLogger);
            break;

        // 🗑️ POST: Deactivate (soft-delete) a subscription tier
        case 'delete_tier':
            handleDeleteTier($input, $db, $accessControl, $activityLogger);
            break;

        // ✅ POST: Reactivate a previously deactivated tier
        case 'activate_tier':
            handleActivateTier($input, $db, $accessControl, $activityLogger);
            break;

        // ⭐ POST: Set a tier as the default for new subscribers
        case 'set_default':
            handleSetDefault($input, $db, $accessControl, $activityLogger);
            break;

        // ❌ Unknown action — return a 400 Bad Request error
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action specified: "' . htmlspecialchars($action) . '"'
            ]);
            break;
    }
} catch (Exception $e) {
    // 🚨 Catch-all for unhandled exceptions — return a generic error
    // Never expose internal error details to the client (security best practice)
    // @see https://cheatsheetseries.owasp.org/cheatsheets/Error_Handling_Cheat_Sheet.html
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again later.'
    ]);

    // 📝 Log the full error details internally for debugging
    error_log('[SIGNula] tier-actions.php unhandled exception: ' . $e->getMessage());
}

// ============================================================================
// ============================================================================
// 🔧 HANDLER FUNCTIONS
// ============================================================================
// ============================================================================

/**
 * ============================================================================
 * 🔍 GET ALL TIERS — Retrieve all subscription tiers for a partner
 * ============================================================================
 *
 * Returns all tiers (including inactive ones, since this is the admin view)
 * for the specified partner. Requires admin-level access.
 *
 * Request Parameters:
 * - partnerID (int, required) — The partner to fetch tiers for
 *
 * Response:
 * - success (bool)   — Whether the operation succeeded
 * - data    (array)  — Array of tier objects with decoded JSON fields
 * - count   (int)    — Total number of tiers returned
 *
 * @param array         $input         Request parameters (from GET or JSON body)
 * @param Database      $db            Database singleton instance
 * @param AccessControl $accessControl Access control service for permission checks
 *
 * @return void Outputs JSON response directly
 *
 * @throws Exception If partner ID is missing or access is denied
 */
function handleGetTiers(array $input, Database $db, AccessControl $accessControl): void
{
    // 📋 Extract and validate the partner ID parameter
    $partnerID = (int)($input['partnerID'] ?? 0);

    if ($partnerID <= 0) {
        throw new Exception('Partner ID is required');
    }

    // 🛡️ Verify the current user has admin access for this partner
    // Non-admin roles (developer, support, finance) cannot manage tiers
    if (!$accessControl->isPartnerAdmin($partnerID)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required to view subscription tiers'
        ]);
        return;
    }

    // 💰 Retrieve all tiers from the PartnerPaymentService
    // Pass includeInactive=true since admins need to see all tiers
    // @see PartnerPaymentService::getPartnerTiers()
    $tiers = PartnerPaymentService::getPartnerTiers($partnerID, true);

    // ✅ Return the tier data
    echo json_encode([
        'success' => true,
        'data'    => $tiers,
        'count'   => count($tiers)
    ]);
}

/**
 * ============================================================================
 * 🔍 GET SINGLE TIER — Retrieve a single subscription tier by ID
 * ============================================================================
 *
 * Fetches a single tier record and verifies that it belongs to the
 * requesting partner (ownership check). Returns the full tier data
 * with decoded JSON fields.
 *
 * Request Parameters:
 * - tierID    (int, required) — The tier to retrieve
 * - partnerID (int, required) — The partner making the request (for ownership check)
 *
 * Response:
 * - success (bool)       — Whether the operation succeeded
 * - data    (object|null) — Tier object or null if not found
 *
 * @param array         $input         Request parameters (from GET or JSON body)
 * @param Database      $db            Database singleton instance
 * @param AccessControl $accessControl Access control service for permission checks
 *
 * @return void Outputs JSON response directly
 *
 * @throws Exception If tier ID or partner ID is missing
 */
function handleGetTier(array $input, Database $db, AccessControl $accessControl): void
{
    // 📋 Extract and validate required parameters
    $tierID = (int)($input['tierID'] ?? 0);
    $partnerID = (int)($input['partnerID'] ?? 0);

    if ($tierID <= 0) {
        throw new Exception('Tier ID is required');
    }

    if ($partnerID <= 0) {
        throw new Exception('Partner ID is required');
    }

    // 🛡️ Verify admin access for this partner
    if (!$accessControl->isPartnerAdmin($partnerID)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required to view tier details'
        ]);
        return;
    }

    // 🔍 Fetch the tier from the PartnerPaymentService
    // @see PartnerPaymentService::getPartnerTier()
    $tier = PartnerPaymentService::getPartnerTier($tierID);

    // 🚫 Check if the tier exists
    if (!$tier) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Subscription tier not found'
        ]);
        return;
    }

    // 🛡️ OWNERSHIP CHECK — verify the tier belongs to the requesting partner
    // This prevents a partner from reading another partner's tier data
    // by guessing or enumerating tier IDs
    if ((int)$tier['partnerID'] !== $partnerID) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'This tier does not belong to your organisation'
        ]);
        return;
    }

    // ✅ Return the tier data
    echo json_encode([
        'success' => true,
        'data'    => $tier
    ]);
}

/**
 * ============================================================================
 * ➕ CREATE TIER — Create a new subscription tier for a partner
 * ============================================================================
 *
 * Creates a new subscription tier in tblPartnerSubscriptionTiers. The
 * tierSlug must be unique per partner (enforced by uk_partner_tier_slug).
 * If the isDefault flag is set, any existing default tier is unset first.
 *
 * Request Body (JSON):
 * - partnerID (int, required)    — The partner creating the tier
 * - tierData  (object, required) — Tier attributes (see below)
 *   - tierName        (string, required) Display name
 *   - tierSlug        (string, required) URL-safe identifier
 *   - tierDescription (string)  Description of tier benefits
 *   - monthlyPrice    (float)   Monthly price (default: 0.00)
 *   - yearlyPrice     (float)   Yearly price (default: 0.00)
 *   - currency        (string)  ISO 4217 code (default: 'GBP')
 *   - features        (array)   Feature strings
 *   - featureLimits   (object)  Numeric limits (key-value pairs)
 *   - displayOrder    (int)     Sort order (default: 0)
 *   - trialDays       (int)     Free trial period (default: 0)
 *   - badge           (string)  Badge text
 *   - badgeColor      (string)  Badge CSS colour class
 *   - isDefault       (bool)    Set as default tier
 *
 * Response:
 * - success        (bool)     — Whether the operation succeeded
 * - partnerTierID  (int|null) — The newly created tier's ID
 * - message        (string)   — Success or error message
 *
 * @param array          $input          Parsed JSON request body
 * @param Database       $db             Database singleton instance
 * @param AccessControl  $accessControl  Access control service
 * @param ActivityLogger $activityLogger Activity logging service
 *
 * @return void Outputs JSON response directly
 *
 * @throws Exception If required fields are missing or validation fails
 */
function handleCreateTier(array $input, Database $db, AccessControl $accessControl, ActivityLogger $activityLogger): void
{
    // 📋 Extract and validate required parameters
    $partnerID = (int)($input['partnerID'] ?? 0);
    $tierData = $input['tierData'] ?? [];

    if ($partnerID <= 0) {
        throw new Exception('Partner ID is required');
    }

    if (empty($tierData) || !is_array($tierData)) {
        throw new Exception('Tier data is required');
    }

    // 🛡️ Verify admin access for this partner
    if (!$accessControl->isPartnerAdmin($partnerID)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required to create subscription tiers'
        ]);
        return;
    }

    // 🛡️ Server-side validation of required fields
    if (empty($tierData['tierName'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Tier name is required'
        ]);
        return;
    }

    if (empty($tierData['tierSlug'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Tier slug is required'
        ]);
        return;
    }

    // 🔗 Sanitise the tier slug (lowercase, alphanumeric, hyphens only)
    // @see https://www.php.net/manual/en/function.preg-replace.php
    $tierData['tierSlug'] = preg_replace('/[^a-z0-9\-]/', '', strtolower($tierData['tierSlug']));

    if (empty($tierData['tierSlug'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid tier slug — must contain at least one alphanumeric character'
        ]);
        return;
    }

    // ⭐ Handle isDefault flag — if set, unset existing default tier first
    // This ensures only ONE tier per partner can be the default
    $isDefault = !empty($tierData['isDefault']);
    unset($tierData['isDefault']); // Remove from tierData (not a DB column in createPartnerTier)

    // ➕ Create the tier via the PartnerPaymentService
    // @see PartnerPaymentService::createPartnerTier()
    $result = PartnerPaymentService::createPartnerTier($partnerID, $tierData);

    // ⭐ If creation succeeded and isDefault was requested, set the default flag
    if ($result['success'] && $isDefault && !empty($result['partnerTierID'])) {
        // 🔄 Unset all existing defaults for this partner first
        $stmt = $db->prepare(
            "UPDATE tblPartnerSubscriptionTiers SET isDefault = 0 WHERE partnerID = ?"
        );
        $stmt->bind_param('i', $partnerID);
        $stmt->execute();

        // ⭐ Set the new tier as default
        $stmt = $db->prepare(
            "UPDATE tblPartnerSubscriptionTiers SET isDefault = 1 WHERE tierID = ?"
        );
        $stmt->bind_param('i', $result['partnerTierID']);
        $stmt->execute();
    }

    // 🏷️ Handle badge fields (not handled by createPartnerTier natively)
    if ($result['success'] && !empty($result['partnerTierID'])) {
        $badgeText = $tierData['badge'] ?? null;
        $badgeColor = $tierData['badgeColor'] ?? null;

        if ($badgeText !== null || $badgeColor !== null) {
            $stmt = $db->prepare(
                "UPDATE tblPartnerSubscriptionTiers SET badge = ?, badgeColor = ? WHERE tierID = ?"
            );
            $stmt->bind_param('ssi', $badgeText, $badgeColor, $result['partnerTierID']);
            $stmt->execute();
        }
    }

    // 📝 Log the admin action for audit trail
    if ($result['success']) {
        $accessControl->logAdminAction('create_partner_tier', 'partner', $partnerID, [
            'tierName'       => $tierData['tierName'],
            'tierSlug'       => $tierData['tierSlug'],
            'partnerTierID'  => $result['partnerTierID'] ?? null,
            'monthlyPrice'   => $tierData['monthlyPrice'] ?? 0,
            'yearlyPrice'    => $tierData['yearlyPrice'] ?? 0,
            'currency'       => $tierData['currency'] ?? 'GBP',
            'isDefault'      => $isDefault,
        ]);

        $activityLogger->logActivity(
            'partner_tier_created',
            'Subscription tier "' . $tierData['tierName'] . '" created for partner ' . $partnerID,
            [
                'partnerID'      => $partnerID,
                'partnerTierID'  => $result['partnerTierID'] ?? null,
                'tierName'       => $tierData['tierName'],
            ]
        );
    }

    // ✅ Return the result from PartnerPaymentService
    echo json_encode($result);
}

/**
 * ============================================================================
 * ✏️ UPDATE TIER — Update an existing subscription tier
 * ============================================================================
 *
 * Updates the specified fields of an existing tier. Only provided fields
 * are updated; omitted fields retain their current values. Verifies tier
 * ownership before allowing the update.
 *
 * Request Body (JSON):
 * - tierID    (int, required)    — The tier to update
 * - partnerID (int, required)    — The partner making the request
 * - tierData  (object, required) — Fields to update (same keys as create)
 *
 * Response:
 * - success (bool)   — Whether the operation succeeded
 * - message (string) — Success or error message
 *
 * @param array          $input          Parsed JSON request body
 * @param Database       $db             Database singleton instance
 * @param AccessControl  $accessControl  Access control service
 * @param ActivityLogger $activityLogger Activity logging service
 *
 * @return void Outputs JSON response directly
 *
 * @throws Exception If required fields are missing or ownership check fails
 */
function handleUpdateTier(array $input, Database $db, AccessControl $accessControl, ActivityLogger $activityLogger): void
{
    // 📋 Extract and validate required parameters
    $tierID = (int)($input['tierID'] ?? 0);
    $partnerID = (int)($input['partnerID'] ?? 0);
    $tierData = $input['tierData'] ?? [];

    if ($tierID <= 0) {
        throw new Exception('Tier ID is required');
    }

    if ($partnerID <= 0) {
        throw new Exception('Partner ID is required');
    }

    if (empty($tierData) || !is_array($tierData)) {
        throw new Exception('Tier data is required');
    }

    // 🛡️ Verify admin access for this partner
    if (!$accessControl->isPartnerAdmin($partnerID)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required to update subscription tiers'
        ]);
        return;
    }

    // 🔍 Fetch the existing tier to verify ownership
    // @see PartnerPaymentService::getPartnerTier()
    $existingTier = PartnerPaymentService::getPartnerTier($tierID);

    if (!$existingTier) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Subscription tier not found'
        ]);
        return;
    }

    // 🛡️ OWNERSHIP CHECK — verify the tier belongs to the requesting partner
    if ((int)$existingTier['partnerID'] !== $partnerID) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'This tier does not belong to your organisation'
        ]);
        return;
    }

    // ⭐ Handle isDefault flag separately (not part of updatePartnerTier)
    $isDefault = isset($tierData['isDefault']) ? (bool)$tierData['isDefault'] : null;
    unset($tierData['isDefault']); // Remove from tierData before passing to service

    // 🏷️ Handle badge fields separately
    $badgeText = array_key_exists('badge', $tierData) ? $tierData['badge'] : null;
    $badgeColor = array_key_exists('badgeColor', $tierData) ? $tierData['badgeColor'] : null;
    $hasBadgeUpdate = array_key_exists('badge', $tierData) || array_key_exists('badgeColor', $tierData);
    unset($tierData['badge'], $tierData['badgeColor']); // Remove before passing to service

    // ✏️ Update the tier via the PartnerPaymentService
    // @see PartnerPaymentService::updatePartnerTier()
    $result = PartnerPaymentService::updatePartnerTier($tierID, $tierData);

    // ⭐ Handle isDefault flag update if the main update succeeded
    if ($result['success'] && $isDefault !== null) {
        if ($isDefault) {
            // 🔄 Unset all existing defaults for this partner first
            $stmt = $db->prepare(
                "UPDATE tblPartnerSubscriptionTiers SET isDefault = 0 WHERE partnerID = ?"
            );
            $stmt->bind_param('i', $partnerID);
            $stmt->execute();

            // ⭐ Set this tier as default
            $stmt = $db->prepare(
                "UPDATE tblPartnerSubscriptionTiers SET isDefault = 1 WHERE tierID = ?"
            );
            $stmt->bind_param('i', $tierID);
            $stmt->execute();
        } else {
            // 🔄 Remove default flag from this tier only
            $stmt = $db->prepare(
                "UPDATE tblPartnerSubscriptionTiers SET isDefault = 0 WHERE tierID = ?"
            );
            $stmt->bind_param('i', $tierID);
            $stmt->execute();
        }
    }

    // 🏷️ Handle badge field updates (not handled by updatePartnerTier natively)
    if ($result['success'] && $hasBadgeUpdate) {
        $stmt = $db->prepare(
            "UPDATE tblPartnerSubscriptionTiers SET badge = ?, badgeColor = ? WHERE tierID = ?"
        );
        $stmt->bind_param('ssi', $badgeText, $badgeColor, $tierID);
        $stmt->execute();
    }

    // 📝 Log the admin action for audit trail
    if ($result['success']) {
        $accessControl->logAdminAction('update_partner_tier', 'partner', $partnerID, [
            'tierID'        => $tierID,
            'tierName'      => $existingTier['tierName'],
            'updatedFields' => array_keys($input['tierData'] ?? []),
        ]);

        $activityLogger->logActivity(
            'partner_tier_updated',
            'Subscription tier "' . $existingTier['tierName'] . '" updated (ID: ' . $tierID . ')',
            [
                'partnerID'      => $partnerID,
                'partnerTierID'  => $tierID,
                'updatedFields'  => array_keys($input['tierData'] ?? []),
            ]
        );
    }

    // ✅ Return the result
    echo json_encode($result);
}

/**
 * ============================================================================
 * 🗑️ DELETE (DEACTIVATE) TIER — Soft-delete a subscription tier
 * ============================================================================
 *
 * Deactivates a tier by setting isActive=0. Does NOT perform a hard DELETE
 * because existing subscriptions may reference this tier. Deactivated tiers
 * are hidden from new customers but can be reactivated by admins.
 *
 * Request Body (JSON):
 * - tierID    (int, required) — The tier to deactivate
 * - partnerID (int, required) — The partner making the request
 *
 * Response:
 * - success (bool)   — Whether the operation succeeded
 * - message (string) — Success or error message
 *
 * @param array          $input          Parsed JSON request body
 * @param Database       $db             Database singleton instance
 * @param AccessControl  $accessControl  Access control service
 * @param ActivityLogger $activityLogger Activity logging service
 *
 * @return void Outputs JSON response directly
 *
 * @throws Exception If required fields are missing or ownership check fails
 */
function handleDeleteTier(array $input, Database $db, AccessControl $accessControl, ActivityLogger $activityLogger): void
{
    // 📋 Extract and validate required parameters
    $tierID = (int)($input['tierID'] ?? 0);
    $partnerID = (int)($input['partnerID'] ?? 0);

    if ($tierID <= 0) {
        throw new Exception('Tier ID is required');
    }

    if ($partnerID <= 0) {
        throw new Exception('Partner ID is required');
    }

    // 🛡️ Verify admin access for this partner
    if (!$accessControl->isPartnerAdmin($partnerID)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required to deactivate subscription tiers'
        ]);
        return;
    }

    // 🔍 Fetch the existing tier to verify ownership
    $existingTier = PartnerPaymentService::getPartnerTier($tierID);

    if (!$existingTier) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Subscription tier not found'
        ]);
        return;
    }

    // 🛡️ OWNERSHIP CHECK — verify the tier belongs to the requesting partner
    if ((int)$existingTier['partnerID'] !== $partnerID) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'This tier does not belong to your organisation'
        ]);
        return;
    }

    // ⚠️ Prevent deactivation of the default tier
    // Admins must set a different default before deactivating the current one
    if (!empty($existingTier['isDefault'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot deactivate the default tier. Please set a different tier as default first.'
        ]);
        return;
    }

    // 🗑️ Perform the soft-delete via PartnerPaymentService
    // @see PartnerPaymentService::deletePartnerTier()
    $result = PartnerPaymentService::deletePartnerTier($tierID);

    // 📝 Log the admin action for audit trail
    if ($result['success']) {
        $accessControl->logAdminAction('deactivate_partner_tier', 'partner', $partnerID, [
            'tierID'   => $tierID,
            'tierName' => $existingTier['tierName'],
        ]);

        $activityLogger->logActivity(
            'partner_tier_deactivated',
            'Subscription tier "' . $existingTier['tierName'] . '" deactivated (ID: ' . $tierID . ')',
            [
                'partnerID'      => $partnerID,
                'partnerTierID'  => $tierID,
                'tierName'       => $existingTier['tierName'],
            ]
        );
    }

    // ✅ Return the result
    echo json_encode($result);
}

/**
 * ============================================================================
 * ✅ ACTIVATE TIER — Reactivate a previously deactivated tier
 * ============================================================================
 *
 * Reactivates a tier by setting isActive=1. This makes the tier visible
 * to new customers again on the pricing page.
 *
 * Request Body (JSON):
 * - tierID    (int, required) — The tier to reactivate
 * - partnerID (int, required) — The partner making the request
 *
 * Response:
 * - success (bool)   — Whether the operation succeeded
 * - message (string) — Success or error message
 *
 * @param array          $input          Parsed JSON request body
 * @param Database       $db             Database singleton instance
 * @param AccessControl  $accessControl  Access control service
 * @param ActivityLogger $activityLogger Activity logging service
 *
 * @return void Outputs JSON response directly
 *
 * @throws Exception If required fields are missing or ownership check fails
 */
function handleActivateTier(array $input, Database $db, AccessControl $accessControl, ActivityLogger $activityLogger): void
{
    // 📋 Extract and validate required parameters
    $tierID = (int)($input['tierID'] ?? 0);
    $partnerID = (int)($input['partnerID'] ?? 0);

    if ($tierID <= 0) {
        throw new Exception('Tier ID is required');
    }

    if ($partnerID <= 0) {
        throw new Exception('Partner ID is required');
    }

    // 🛡️ Verify admin access for this partner
    if (!$accessControl->isPartnerAdmin($partnerID)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required to activate subscription tiers'
        ]);
        return;
    }

    // 🔍 Fetch the existing tier to verify ownership
    $existingTier = PartnerPaymentService::getPartnerTier($tierID);

    if (!$existingTier) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Subscription tier not found'
        ]);
        return;
    }

    // 🛡️ OWNERSHIP CHECK — verify the tier belongs to the requesting partner
    if ((int)$existingTier['partnerID'] !== $partnerID) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'This tier does not belong to your organisation'
        ]);
        return;
    }

    // 🔍 Check if the tier is already active
    if (!empty($existingTier['isActive'])) {
        echo json_encode([
            'success' => true,
            'message' => 'Tier is already active'
        ]);
        return;
    }

    // ✅ Reactivate the tier by setting isActive = 1
    // Using a direct query since PartnerPaymentService does not have an activate method
    // @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
    $stmt = $db->prepare(
        "UPDATE tblPartnerSubscriptionTiers SET isActive = 1 WHERE tierID = ?"
    );
    $stmt->bind_param('i', $tierID);

    if (!$stmt->execute()) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to reactivate subscription tier'
        ]);
        return;
    }

    // 📝 Log the admin action for audit trail
    $accessControl->logAdminAction('activate_partner_tier', 'partner', $partnerID, [
        'tierID'   => $tierID,
        'tierName' => $existingTier['tierName'],
    ]);

    $activityLogger->logActivity(
        'partner_tier_activated',
        'Subscription tier "' . $existingTier['tierName'] . '" reactivated (ID: ' . $tierID . ')',
        [
            'partnerID'      => $partnerID,
            'partnerTierID'  => $tierID,
            'tierName'       => $existingTier['tierName'],
        ]
    );

    // 📝 Also log via ActivityLogger::log() for consistency with PartnerPaymentService
    ActivityLogger::log(
        null,
        'partner_tier_activated',
        'payment',
        'info',
        'Partner subscription tier reactivated: "' . $existingTier['tierName'] . '" (tierID=' . $tierID . ')',
        [
            'partnerTierID' => $tierID,
            'partnerID'     => $partnerID,
            'tierName'      => $existingTier['tierName'],
        ]
    );

    // ✅ Return success
    echo json_encode([
        'success' => true,
        'message' => 'Subscription tier reactivated successfully'
    ]);
}

/**
 * ============================================================================
 * ⭐ SET DEFAULT TIER — Set a tier as the default for new subscribers
 * ============================================================================
 *
 * Marks the specified tier as the default for new subscribers of this partner.
 * Only one tier per partner can be the default. This operation:
 * 1. Unsets isDefault on ALL tiers for this partner
 * 2. Sets isDefault=1 on the specified tier
 *
 * The tier must be active (isActive=1) to be set as default.
 *
 * Request Body (JSON):
 * - tierID    (int, required) — The tier to set as default
 * - partnerID (int, required) — The partner making the request
 *
 * Response:
 * - success (bool)   — Whether the operation succeeded
 * - message (string) — Success or error message
 *
 * @param array          $input          Parsed JSON request body
 * @param Database       $db             Database singleton instance
 * @param AccessControl  $accessControl  Access control service
 * @param ActivityLogger $activityLogger Activity logging service
 *
 * @return void Outputs JSON response directly
 *
 * @throws Exception If required fields are missing or ownership check fails
 */
function handleSetDefault(array $input, Database $db, AccessControl $accessControl, ActivityLogger $activityLogger): void
{
    // 📋 Extract and validate required parameters
    $tierID = (int)($input['tierID'] ?? 0);
    $partnerID = (int)($input['partnerID'] ?? 0);

    if ($tierID <= 0) {
        throw new Exception('Tier ID is required');
    }

    if ($partnerID <= 0) {
        throw new Exception('Partner ID is required');
    }

    // 🛡️ Verify admin access for this partner
    if (!$accessControl->isPartnerAdmin($partnerID)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required to set the default subscription tier'
        ]);
        return;
    }

    // 🔍 Fetch the existing tier to verify ownership and status
    $existingTier = PartnerPaymentService::getPartnerTier($tierID);

    if (!$existingTier) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Subscription tier not found'
        ]);
        return;
    }

    // 🛡️ OWNERSHIP CHECK — verify the tier belongs to the requesting partner
    if ((int)$existingTier['partnerID'] !== $partnerID) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'This tier does not belong to your organisation'
        ]);
        return;
    }

    // 🚫 Prevent setting an inactive tier as default
    if (empty($existingTier['isActive'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot set an inactive tier as default. Please activate the tier first.'
        ]);
        return;
    }

    // 🔍 Check if this tier is already the default
    if (!empty($existingTier['isDefault'])) {
        echo json_encode([
            'success' => true,
            'message' => 'This tier is already the default'
        ]);
        return;
    }

    // 🔄 Step 1: Unset isDefault on ALL tiers for this partner
    // This ensures only one tier can be the default at any time
    $stmt = $db->prepare(
        "UPDATE tblPartnerSubscriptionTiers SET isDefault = 0 WHERE partnerID = ?"
    );
    $stmt->bind_param('i', $partnerID);

    if (!$stmt->execute()) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to reset default tiers'
        ]);
        return;
    }

    // ⭐ Step 2: Set the specified tier as the new default
    $stmt = $db->prepare(
        "UPDATE tblPartnerSubscriptionTiers SET isDefault = 1 WHERE tierID = ?"
    );
    $stmt->bind_param('i', $tierID);

    if (!$stmt->execute()) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to set new default tier'
        ]);
        return;
    }

    // 📝 Log the admin action for audit trail
    $accessControl->logAdminAction('set_default_partner_tier', 'partner', $partnerID, [
        'tierID'   => $tierID,
        'tierName' => $existingTier['tierName'],
    ]);

    $activityLogger->logActivity(
        'partner_default_tier_set',
        'Default subscription tier set to "' . $existingTier['tierName'] . '" (ID: ' . $tierID . ')',
        [
            'partnerID'      => $partnerID,
            'partnerTierID'  => $tierID,
            'tierName'       => $existingTier['tierName'],
        ]
    );

    // 📝 Also log via ActivityLogger::log() for consistency with PartnerPaymentService
    ActivityLogger::log(
        null,
        'partner_default_tier_changed',
        'payment',
        'info',
        'Partner default subscription tier changed to "' . $existingTier['tierName'] . '" for partner ' . $partnerID,
        [
            'partnerTierID' => $tierID,
            'partnerID'     => $partnerID,
            'tierName'      => $existingTier['tierName'],
        ]
    );

    // ✅ Return success
    echo json_encode([
        'success' => true,
        'message' => '"' . $existingTier['tierName'] . '" is now the default tier for new subscribers'
    ]);
}
