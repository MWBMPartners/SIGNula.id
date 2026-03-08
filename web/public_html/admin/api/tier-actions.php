<?php
/**
 * ============================================================================
 * 🏷️ SIGNula - Subscription Tier Management API (Admin)
 * ============================================================================
 *
 * Purpose: Admin API endpoint for managing subscription tiers (both global
 *          and custom/partner-created). Provides full CRUD operations on
 *          tblSubscriptionTiers with support for custom tier creation,
 *          duplication, reordering, and soft-deletion.
 *
 * Actions (GET):
 *   - get_tiers          — List all tiers (with optional filters: isCustom, isActive, createdByPartnerID)
 *   - get_tier           — Get a single tier by tierID
 *
 * Actions (POST):
 *   - create_tier        — Create a new tier (global or custom)
 *   - update_tier        — Update an existing tier
 *   - delete_tier        — Soft-delete a tier (set isActive=0)
 *   - activate_tier      — Reactivate a soft-deleted tier (set isActive=1)
 *   - set_default        — Set a tier as the default (isDefault=1), unset others
 *   - reorder_tiers      — Update displayOrder for multiple tiers
 *   - duplicate_tier     — Duplicate an existing tier as a new custom tier
 *
 * Authentication: Super Admin or Partner Admin
 * CSRF Protection: Required for all POST requests
 *
 * PHP Version: 8.3+
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\API
 * @version    1.0.0
 * @since      2.7.0-beta
 * @link       https://SIGNula.id
 * @see        https://www.php.net/manual/en/book.mysqli.php (MySQLi Reference)
 * @see        https://owasp.org/www-community/attacks/csrf (OWASP CSRF Prevention)
 * ============================================================================
 */

// ============================================================================
// 🚀 BOOTSTRAP — Load core system files
// ============================================================================

// ⚙️ Main configuration (defines SIGNULA_INIT, path constants, loads settings, initializes session)
// @see web/_config/config.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton (MySQLi prepared statements)
// @see web/_backend/Database.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🎫 Session manager (authentication state)
// @see web/_backend/SessionManager.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🔐 Access control (role-based permissions)
// @see web/_backend/AccessControl.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// ============================================================================
// 📤 RESPONSE HEADERS
// ============================================================================

// 📤 Set JSON response content type header
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
header('Content-Type: application/json');

// 🛡️ Security headers to prevent caching of sensitive data
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ============================================================================
// 🔐 AUTHENTICATION & AUTHORIZATION
// ============================================================================

// 🗄️ Initialize core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔐 Check authentication — user must be logged in
// @see https://owasp.org/www-project-web-security-testing-guide/ (OWASP Testing Guide)
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    jsonResponse(false, 'Unauthorized', null, 401);
    exit;
}

// 🔐 Require Super Admin or Partner Admin — both can manage tiers
// Super Admins can manage ALL tiers (global + custom)
// Partner Admins can only manage their own custom tiers
// @see AccessControl — Role-based permission checks
$userInfo = $sessionManager->getUserInfo();
$isSuperAdmin = isset($userInfo['isSuperAdmin']) && $userInfo['isSuperAdmin'] == 1;
$isPartnerAdmin = isset($userInfo['isPartnerAdmin']) && $userInfo['isPartnerAdmin'] == 1;

// 🚫 Reject users who are neither Super Admin nor Partner Admin
if (!$isSuperAdmin && !$isPartnerAdmin) {
    http_response_code(403);
    jsonResponse(false, 'Admin access required. You must be a Super Admin or Partner Admin.', null, 403);
    exit;
}

// ============================================================================
// 📥 REQUEST PARSING
// ============================================================================

// 📥 Get request data — supports both GET params and POST JSON body
// GET requests use URL query parameters; POST requests use JSON body
// @see https://www.php.net/manual/en/wrappers.php.php (PHP Input/Output Streams)
$input = json_decode(file_get_contents('php://input'), true);

// 🔀 Determine action based on request method
// GET requests use query parameters, POST requests use JSON body
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// 🛡️ CSRF validation for POST requests — prevents cross-site request forgery
// The token is sent either in the JSON body or as a custom HTTP header
// @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Guide
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        jsonResponse(false, 'Invalid or expired CSRF token. Please refresh the page.');
        exit;
    }
}

// ============================================================================
// 🗺️ ACTION ROUTER — Dispatch to appropriate handler function
// ============================================================================

try {
    switch ($action) {

        // ═══════════════════════════════════════════════════════
        // 📋 GET ACTIONS — Read-only data retrieval
        // ═══════════════════════════════════════════════════════

        // 📋 List all tiers (with optional filters)
        case 'get_tiers':
            handleGetTiers();
            break;

        // 🔍 Get a single tier by tierID
        case 'get_tier':
            handleGetTier();
            break;

        // ═══════════════════════════════════════════════════════
        // ⚡ POST ACTIONS — Write operations (CSRF protected)
        // ═══════════════════════════════════════════════════════

        // ➕ Create a new subscription tier
        case 'create_tier':
            handleCreateTier($input);
            break;

        // ✏️ Update an existing subscription tier
        case 'update_tier':
            handleUpdateTier($input);
            break;

        // 🗑️ Soft-delete a subscription tier (set isActive=0)
        case 'delete_tier':
            handleDeleteTier($input);
            break;

        // ✅ Reactivate a soft-deleted tier (set isActive=1)
        case 'activate_tier':
            handleActivateTier($input);
            break;

        // ⭐ Set a tier as the default for new users
        case 'set_default':
            handleSetDefault($input);
            break;

        // 🔄 Reorder tiers (update displayOrder for multiple tiers)
        case 'reorder_tiers':
            handleReorderTiers($input);
            break;

        // 📋 Duplicate an existing tier as a new custom tier
        case 'duplicate_tier':
            handleDuplicateTier($input);
            break;

        // 🚫 Unknown action — return error
        default:
            throw new Exception('Invalid action: ' . htmlspecialchars($action));
    }
} catch (Exception $e) {
    // 🚨 Catch-all error handler — return structured error response
    // ⚠️ Never expose raw exception details to the client in production
    // @see https://owasp.org/www-community/Improper_Error_Handling
    http_response_code(500);

    // 🔍 Log the full exception for debugging
    error_log('[SIGNula] tier-actions.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // 🛡️ Return a generic error message to prevent information disclosure
    jsonResponse(false, 'An error occurred while processing your request. Please try again.', null, 500);
}


// ============================================================================
// ============================================================================
// 📋 HANDLER FUNCTIONS
// ============================================================================
// ============================================================================


// ============================================================================
// 📋 HANDLER: get_tiers
// ============================================================================

/**
 * 📋 Retrieve all subscription tiers with optional filtering.
 *
 * Queries tblSubscriptionTiers directly with support for filtering by
 * isCustom, isActive, and createdByPartnerID. Partner Admins are limited
 * to seeing global tiers + their own custom tiers.
 *
 * GET Parameters:
 *   - isCustom (int, optional): Filter by custom (1) or global (0) tiers
 *   - isActive (int, optional): Filter by active (1) or inactive (0) tiers
 *   - createdByPartnerID (int, optional): Filter by creating partner ID
 *   - limit (int, optional): Number of records per page (default: 50, max: 100)
 *   - offset (int, optional): Record offset for pagination (default: 0)
 *
 * @return void Outputs JSON response with tiers array and pagination metadata
 *
 * @see tblSubscriptionTiers — Subscription tier definitions table
 */
function handleGetTiers(): void
{
    // 📥 Parse pagination parameters from GET query string
    $limit  = (int) ($_GET['limit'] ?? 50);
    $offset = (int) ($_GET['offset'] ?? 0);

    // 🔒 Clamp limit to safe range (1–100) to prevent excessive queries
    $limit  = max(1, min(100, $limit));
    $offset = max(0, $offset);

    // 🏗️ Build dynamic WHERE clause based on optional filters
    // Using parameterised queries to prevent SQL injection
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    $conditions = [];
    $params     = [];
    $types      = '';

    // 🔍 Filter by isCustom (0 = global, 1 = custom/partner-created)
    if (isset($_GET['isCustom']) && $_GET['isCustom'] !== '') {
        $conditions[] = '`isCustom` = ?';
        $params[]     = (int) $_GET['isCustom'];
        $types       .= 'i';
    }

    // 🔍 Filter by isActive status
    if (isset($_GET['isActive']) && $_GET['isActive'] !== '') {
        $conditions[] = '`isActive` = ?';
        $params[]     = (int) $_GET['isActive'];
        $types       .= 'i';
    }

    // 🔍 Filter by createdByPartnerID (which partner created the custom tier)
    if (isset($_GET['createdByPartnerID']) && $_GET['createdByPartnerID'] !== '') {
        $conditions[] = '`createdByPartnerID` = ?';
        $params[]     = (int) $_GET['createdByPartnerID'];
        $types       .= 'i';
    }

    // 🔐 Partner Admins can only see global tiers + their own custom tiers
    // Super Admins can see everything without restriction
    $userInfo = $GLOBALS['userInfo'];
    $isSuperAdmin = $GLOBALS['isSuperAdmin'];

    if (!$isSuperAdmin) {
        // 📌 Partner Admin: show global tiers (isCustom=0) OR their own custom tiers
        $partnerID = (int) ($userInfo['partnerID'] ?? 0);
        $conditions[] = '(`isCustom` = 0 OR `createdByPartnerID` = ?)';
        $params[]     = $partnerID;
        $types       .= 'i';
    }

    // 🏗️ Assemble the full SQL query with conditions
    $whereClause = '';
    if (!empty($conditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $conditions);
    }

    // 📊 Get total count for pagination metadata
    $countSql = "SELECT COUNT(*) AS total FROM `tblSubscriptionTiers` {$whereClause}";
    $countResult = Database::fetchOne($countSql, $types, ...$params);
    $total = (int) ($countResult['total'] ?? 0);

    // 📋 Fetch paginated tier records ordered by displayOrder
    $dataSql = "SELECT * FROM `tblSubscriptionTiers` {$whereClause} ORDER BY `displayOrder` ASC, `tierName` ASC LIMIT ? OFFSET ?";
    $dataParams = array_merge($params, [$limit, $offset]);
    $dataTypes  = $types . 'ii';

    $tiers = Database::fetchAll($dataSql, $dataTypes, ...$dataParams);

    // 📦 Decode JSON fields for each tier (features, featureLimits, usageLimits)
    // These are stored as JSON strings in MySQL but should be returned as arrays/objects
    // @see https://dev.mysql.com/doc/refman/8.0/en/json.html
    foreach ($tiers as &$tier) {
        $tier['features']      = json_decode($tier['features'] ?? '[]', true) ?: [];
        $tier['featureLimits'] = json_decode($tier['featureLimits'] ?? 'null', true);
        $tier['usageLimits']   = json_decode($tier['usageLimits'] ?? 'null', true);
    }
    unset($tier); // 🧹 Break reference from foreach

    // 📊 Calculate total pages for pagination
    $totalPages = ($limit > 0) ? (int) ceil($total / $limit) : 1;

    // ✅ Return structured response with pagination metadata
    jsonResponse(true, 'Tiers retrieved successfully', [
        'tiers'  => $tiers,
        'total'  => $total,
        'pages'  => $totalPages,
        'limit'  => $limit,
        'offset' => $offset,
    ]);
}


// ============================================================================
// 🔍 HANDLER: get_tier
// ============================================================================

/**
 * 🔍 Retrieve a single subscription tier by its tierID.
 *
 * Partner Admins can only retrieve global tiers or their own custom tiers.
 * Super Admins can retrieve any tier.
 *
 * GET Parameters:
 *   - tierID (int, required): The tier ID to retrieve
 *
 * @return void Outputs JSON response with tier data
 *
 * @throws Exception If tierID is missing, invalid, or not found
 *
 * @see tblSubscriptionTiers — Subscription tier definitions table
 */
function handleGetTier(): void
{
    // ✅ Validate tierID parameter — must be a positive integer
    $tierID = (int) ($_GET['tierID'] ?? 0);
    if ($tierID <= 0) {
        throw new Exception('Valid tier ID is required');
    }

    // 📋 Fetch the tier from the database
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    $tier = Database::fetchOne(
        "SELECT * FROM `tblSubscriptionTiers` WHERE `tierID` = ?",
        'i',
        $tierID
    );

    // 🚫 Check if tier exists
    if (!$tier) {
        throw new Exception('Tier not found with ID: ' . $tierID);
    }

    // 🔐 Partner Admins can only view global tiers or their own custom tiers
    $userInfo = $GLOBALS['userInfo'];
    $isSuperAdmin = $GLOBALS['isSuperAdmin'];

    if (!$isSuperAdmin && $tier['isCustom'] == 1) {
        $partnerID = (int) ($userInfo['partnerID'] ?? 0);
        if ((int) $tier['createdByPartnerID'] !== $partnerID) {
            throw new Exception('You do not have permission to view this custom tier');
        }
    }

    // 📦 Decode JSON fields for the tier
    // @see https://dev.mysql.com/doc/refman/8.0/en/json.html
    $tier['features']      = json_decode($tier['features'] ?? '[]', true) ?: [];
    $tier['featureLimits'] = json_decode($tier['featureLimits'] ?? 'null', true);
    $tier['usageLimits']   = json_decode($tier['usageLimits'] ?? 'null', true);

    // ✅ Return the tier data
    jsonResponse(true, 'Tier retrieved successfully', ['tier' => $tier]);
}


// ============================================================================
// ➕ HANDLER: create_tier
// ============================================================================

/**
 * ➕ Create a new subscription tier (global or custom).
 *
 * Creates a new row in tblSubscriptionTiers. Partner Admins can only create
 * custom tiers (isCustom=1) linked to their partnerID. Super Admins can
 * create both global and custom tiers.
 *
 * Custom tier creation is subject to the tiers.custom.max_per_partner setting
 * which limits how many custom tiers each partner can create.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - tierName (string, required): Display name for the tier
 *   - tierSlug (string, required): URL-safe identifier (must be unique)
 *   - monthlyPrice (decimal, required): Monthly price in default currency
 *   - yearlyPrice (decimal, required): Yearly price (usually discounted)
 *   - tierDescription (string, optional): Description of tier benefits
 *   - features (string|array, optional): JSON array of feature strings
 *   - featureLimits (string|array, optional): JSON object of numeric limits
 *   - usageLimits (string|array, optional): JSON object of per-metric usage limits
 *   - teamMemberLimit (int, optional): Max team members (NULL = unlimited)
 *   - apiCallsPerMonth (int, optional): Monthly API call limit (NULL = unlimited)
 *   - storageGB (decimal, optional): Storage allocation in GB
 *   - trialDays (int, optional): Free trial period in days (default: 0)
 *   - badge (string, optional): Badge text (e.g. "Popular", "Best Value")
 *   - badgeColor (string, optional): Badge CSS colour class
 *   - billingMode (string, optional): 'fixed', 'usage', or 'hybrid' (default: 'fixed')
 *   - isCustom (int, optional): Whether this is a custom tier (default: 0)
 *   - parentTierID (int, optional): Base tier this custom tier derives from
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response with created tier ID
 *
 * @throws Exception If required fields are missing, validation fails, or limits exceeded
 *
 * @see tblSubscriptionTiers — Subscription tier definitions table
 * @see getSetting() — Retrieve configuration from tblSettings
 */
function handleCreateTier(?array $input): void
{
    // 📥 Extract and sanitize required fields
    // @see SecurityUtils::sanitizeString() — XSS-safe string sanitization
    $tierName     = SecurityUtils::sanitizeString($input['tierName'] ?? '');
    $tierSlug     = SecurityUtils::sanitizeString($input['tierSlug'] ?? '');
    $monthlyPrice = $input['monthlyPrice'] ?? null;
    $yearlyPrice  = $input['yearlyPrice'] ?? null;

    // ✅ Validate tierName — must not be empty
    if (empty($tierName)) {
        throw new Exception('Tier name is required');
    }

    // ✅ Validate tierSlug — must not be empty
    if (empty($tierSlug)) {
        throw new Exception('Tier slug is required');
    }

    // ✅ Validate tierSlug format — lowercase alphanumeric, hyphens and underscores only
    // @see https://www.php.net/manual/en/function.preg-match.php
    if (!preg_match('/^[a-z0-9_-]+$/', $tierSlug)) {
        throw new Exception('Tier slug must contain only lowercase letters, numbers, hyphens, and underscores');
    }

    // ✅ Validate monthlyPrice — must be a valid non-negative number
    if ($monthlyPrice === null || !is_numeric($monthlyPrice) || (float) $monthlyPrice < 0) {
        throw new Exception('Monthly price is required and must be a non-negative number');
    }

    // ✅ Validate yearlyPrice — must be a valid non-negative number
    if ($yearlyPrice === null || !is_numeric($yearlyPrice) || (float) $yearlyPrice < 0) {
        throw new Exception('Yearly price is required and must be a non-negative number');
    }

    // 📥 Extract and sanitize optional fields
    $tierDescription  = SecurityUtils::sanitizeString($input['tierDescription'] ?? '');
    $badge            = SecurityUtils::sanitizeString($input['badge'] ?? '');
    $badgeColor       = SecurityUtils::sanitizeString($input['badgeColor'] ?? '');
    $billingMode      = SecurityUtils::sanitizeString($input['billingMode'] ?? 'fixed');
    $isCustom         = (int) ($input['isCustom'] ?? 0);
    $parentTierID     = isset($input['parentTierID']) && $input['parentTierID'] !== ''
                        ? (int) $input['parentTierID']
                        : null;
    $teamMemberLimit  = isset($input['teamMemberLimit']) && $input['teamMemberLimit'] !== ''
                        ? (int) $input['teamMemberLimit']
                        : null;
    $apiCallsPerMonth = isset($input['apiCallsPerMonth']) && $input['apiCallsPerMonth'] !== ''
                        ? (int) $input['apiCallsPerMonth']
                        : null;
    $storageGB        = isset($input['storageGB']) && $input['storageGB'] !== ''
                        ? (float) $input['storageGB']
                        : null;
    $trialDays        = (int) ($input['trialDays'] ?? 0);

    // 📦 Handle JSON fields — accept both strings and arrays
    // features, featureLimits, and usageLimits can be passed as JSON strings or PHP arrays
    // @see https://www.php.net/manual/en/function.json-encode.php
    $features      = $input['features'] ?? '[]';
    $featureLimits = $input['featureLimits'] ?? null;
    $usageLimits   = $input['usageLimits'] ?? null;

    // 🔄 Convert arrays to JSON strings for database storage
    if (is_array($features)) {
        $features = json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (is_array($featureLimits)) {
        $featureLimits = json_encode($featureLimits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (is_array($usageLimits)) {
        $usageLimits = json_encode($usageLimits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ✅ Validate JSON strings are valid JSON
    if ($features !== '[]' && json_decode($features) === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Features must be a valid JSON array');
    }
    if ($featureLimits !== null && json_decode($featureLimits) === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Feature limits must be a valid JSON object');
    }
    if ($usageLimits !== null && json_decode($usageLimits) === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Usage limits must be a valid JSON object');
    }

    // ✅ Validate billingMode — must be one of the allowed ENUM values
    $validBillingModes = ['fixed', 'usage', 'hybrid'];
    if (!in_array($billingMode, $validBillingModes, true)) {
        throw new Exception('Billing mode must be one of: ' . implode(', ', $validBillingModes));
    }

    // 🔐 Access control for custom tier creation
    $userInfo     = $GLOBALS['userInfo'];
    $isSuperAdmin = $GLOBALS['isSuperAdmin'];

    // 📌 Partner Admins can ONLY create custom tiers — not global ones
    if (!$isSuperAdmin) {
        $isCustom = 1; // 🔒 Force custom tier for Partner Admins
    }

    // 📌 Set createdByPartnerID for custom tiers
    $createdByPartnerID = null;
    if ($isCustom === 1) {
        // 🏢 For custom tiers, set the creating partner from the session
        $createdByPartnerID = (int) ($userInfo['partnerID'] ?? 0);

        // ✅ Validate partner ID is set for custom tiers
        if ($createdByPartnerID <= 0 && !$isSuperAdmin) {
            throw new Exception('Partner ID is required for custom tier creation');
        }

        // 🔒 If Super Admin creating a custom tier for a specific partner,
        // allow them to specify the partner
        if ($isSuperAdmin && isset($input['createdByPartnerID']) && $input['createdByPartnerID'] !== '') {
            $createdByPartnerID = (int) $input['createdByPartnerID'];
        }

        // 📊 Check the max custom tiers per partner limit
        // @see getSetting() — Retrieves settings from tblSettings
        $maxCustomTiers = (int) getSetting('tiers.custom.max_per_partner', '10');

        $existingCount = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM `tblSubscriptionTiers` WHERE `isCustom` = 1 AND `createdByPartnerID` = ?",
            'i',
            $createdByPartnerID
        );

        if ((int) ($existingCount['cnt'] ?? 0) >= $maxCustomTiers) {
            throw new Exception(
                'Custom tier limit reached. Maximum ' . $maxCustomTiers . ' custom tiers allowed per partner. '
                . 'Contact support to increase this limit.'
            );
        }
    }

    // 🔍 Check tierSlug uniqueness — slugs must be unique across ALL tiers
    // @see https://dev.mysql.com/doc/refman/8.0/en/constraint-unique.html
    $existingSlug = Database::fetchOne(
        "SELECT `tierID` FROM `tblSubscriptionTiers` WHERE `tierSlug` = ?",
        's',
        $tierSlug
    );

    if ($existingSlug) {
        throw new Exception('A tier with the slug "' . htmlspecialchars($tierSlug) . '" already exists. Please choose a different slug.');
    }

    // ✅ Validate parentTierID — if provided, must reference an existing tier
    if ($parentTierID !== null) {
        $parentTier = Database::fetchOne(
            "SELECT `tierID` FROM `tblSubscriptionTiers` WHERE `tierID` = ?",
            'i',
            $parentTierID
        );
        if (!$parentTier) {
            throw new Exception('Parent tier not found with ID: ' . $parentTierID);
        }
    }

    // 📊 Determine display order — place new tier at the end by default
    $maxOrder = Database::fetchOne(
        "SELECT MAX(`displayOrder`) AS maxOrder FROM `tblSubscriptionTiers`"
    );
    $displayOrder = ((int) ($maxOrder['maxOrder'] ?? 0)) + 1;

    // ➕ Insert the new tier into tblSubscriptionTiers
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    $insertSql = "INSERT INTO `tblSubscriptionTiers` (
        `tierName`, `tierSlug`, `tierDescription`,
        `monthlyPrice`, `yearlyPrice`, `currency`,
        `features`, `featureLimits`, `usageLimits`,
        `teamMemberLimit`, `apiCallsPerMonth`, `storageGB`,
        `displayOrder`, `isActive`, `isDefault`, `trialDays`,
        `badge`, `badgeColor`, `billingMode`,
        `isCustom`, `createdByPartnerID`, `parentTierID`
    ) VALUES (
        ?, ?, ?,
        ?, ?, 'GBP',
        ?, ?, ?,
        ?, ?, ?,
        ?, 1, 0, ?,
        ?, ?, ?,
        ?, ?, ?
    )";

    // 🏗️ Build parameter types string and values array
    // s = string, d = double, i = integer
    // @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
    $insertTypes = 'sss'     // tierName, tierSlug, tierDescription
                 . 'dd'      // monthlyPrice, yearlyPrice
                 . 'sss'     // features, featureLimits, usageLimits
                 . 'iid'     // teamMemberLimit, apiCallsPerMonth, storageGB
                 . 'ii'      // displayOrder, trialDays
                 . 'sss'     // badge, badgeColor, billingMode
                 . 'iii';    // isCustom, createdByPartnerID, parentTierID

    $insertParams = [
        $tierName,
        $tierSlug,
        $tierDescription ?: null,
        (float) $monthlyPrice,
        (float) $yearlyPrice,
        $features,
        $featureLimits,
        $usageLimits,
        $teamMemberLimit,
        $apiCallsPerMonth,
        $storageGB,
        $displayOrder,
        $trialDays,
        $badge ?: null,
        $badgeColor ?: null,
        $billingMode,
        $isCustom,
        $createdByPartnerID,
        $parentTierID,
    ];

    // 🚀 Execute the insert query
    $result = Database::query($insertSql, $insertTypes, ...$insertParams);

    // 📊 Get the newly created tier ID
    // @see https://www.php.net/manual/en/mysqli.insert-id.php
    $newTierID = Database::getInstance()->getConnection()->insert_id;

    if (!$newTierID) {
        throw new Exception('Failed to create subscription tier. Please try again.');
    }

    // 📝 Log the tier creation in the activity log
    // @see ActivityLogger::log() — Audit trail logging
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'tier_created',
        'admin',
        'info',
        'Subscription tier "' . $tierName . '" (' . $tierSlug . ') created'
            . ($isCustom ? ' as custom tier for partner #' . $createdByPartnerID : ' as global tier'),
        [
            'tierID'             => $newTierID,
            'tierName'           => $tierName,
            'tierSlug'           => $tierSlug,
            'monthlyPrice'       => (float) $monthlyPrice,
            'yearlyPrice'        => (float) $yearlyPrice,
            'billingMode'        => $billingMode,
            'isCustom'           => $isCustom,
            'createdByPartnerID' => $createdByPartnerID,
            'parentTierID'       => $parentTierID,
        ]
    );

    // ✅ Return success response with new tier ID
    jsonResponse(true, 'Subscription tier "' . htmlspecialchars($tierName) . '" created successfully', [
        'tierID' => $newTierID,
    ]);
}


// ============================================================================
// ✏️ HANDLER: update_tier
// ============================================================================

/**
 * ✏️ Update an existing subscription tier.
 *
 * Only the provided fields are updated; omitted fields remain unchanged.
 * Partner Admins can only update their own custom tiers.
 * Global tiers can only be modified by Super Admins.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - tierID (int, required): The tier ID to update
 *   - tierName (string, optional): Updated display name
 *   - tierSlug (string, optional): Updated URL-safe identifier (must stay unique)
 *   - tierDescription (string, optional): Updated description
 *   - monthlyPrice (decimal, optional): Updated monthly price
 *   - yearlyPrice (decimal, optional): Updated yearly price
 *   - features (string|array, optional): Updated feature list (JSON)
 *   - featureLimits (string|array, optional): Updated feature limits (JSON)
 *   - usageLimits (string|array, optional): Updated usage limits (JSON)
 *   - teamMemberLimit (int, optional): Updated team member limit
 *   - apiCallsPerMonth (int, optional): Updated API call limit
 *   - storageGB (decimal, optional): Updated storage allocation
 *   - trialDays (int, optional): Updated trial period
 *   - badge (string, optional): Updated badge text
 *   - badgeColor (string, optional): Updated badge colour class
 *   - billingMode (string, optional): Updated billing mode
 *   - parentTierID (int, optional): Updated parent tier reference
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If tierID is missing, invalid, or access denied
 *
 * @see tblSubscriptionTiers — Subscription tier definitions table
 */
function handleUpdateTier(?array $input): void
{
    // ✅ Validate tierID parameter — must be a positive integer
    $tierID = (int) ($input['tierID'] ?? 0);
    if ($tierID <= 0) {
        throw new Exception('Valid tier ID is required');
    }

    // 📋 Fetch the existing tier to verify it exists and check permissions
    $existingTier = Database::fetchOne(
        "SELECT * FROM `tblSubscriptionTiers` WHERE `tierID` = ?",
        'i',
        $tierID
    );

    if (!$existingTier) {
        throw new Exception('Tier not found with ID: ' . $tierID);
    }

    // 🔐 Access control — Partner Admins cannot modify global tiers
    $userInfo     = $GLOBALS['userInfo'];
    $isSuperAdmin = $GLOBALS['isSuperAdmin'];

    if (!$isSuperAdmin) {
        // 🚫 Partner Admins cannot modify global tiers
        if ($existingTier['isCustom'] != 1) {
            throw new Exception('Only Super Admins can modify global tiers');
        }

        // 🚫 Partner Admins can only modify their own custom tiers
        $partnerID = (int) ($userInfo['partnerID'] ?? 0);
        if ((int) $existingTier['createdByPartnerID'] !== $partnerID) {
            throw new Exception('You do not have permission to modify this custom tier');
        }
    }

    // 📥 Build dynamic SET clause — only update fields that were provided
    // This prevents accidentally clearing fields that weren't sent in the request
    $setClauses = [];
    $params     = [];
    $types      = '';

    // 📝 tierName — sanitize and validate if provided
    if (isset($input['tierName'])) {
        $tierName = SecurityUtils::sanitizeString($input['tierName']);
        if (empty($tierName)) {
            throw new Exception('Tier name cannot be empty');
        }
        $setClauses[] = '`tierName` = ?';
        $params[]     = $tierName;
        $types       .= 's';
    }

    // 📝 tierSlug — sanitize, validate format, and check uniqueness if provided
    if (isset($input['tierSlug'])) {
        $tierSlug = SecurityUtils::sanitizeString($input['tierSlug']);
        if (empty($tierSlug)) {
            throw new Exception('Tier slug cannot be empty');
        }
        if (!preg_match('/^[a-z0-9_-]+$/', $tierSlug)) {
            throw new Exception('Tier slug must contain only lowercase letters, numbers, hyphens, and underscores');
        }
        // 🔍 Check slug uniqueness — exclude the current tier
        $slugCheck = Database::fetchOne(
            "SELECT `tierID` FROM `tblSubscriptionTiers` WHERE `tierSlug` = ? AND `tierID` != ?",
            'si',
            $tierSlug,
            $tierID
        );
        if ($slugCheck) {
            throw new Exception('A tier with the slug "' . htmlspecialchars($tierSlug) . '" already exists');
        }
        $setClauses[] = '`tierSlug` = ?';
        $params[]     = $tierSlug;
        $types       .= 's';
    }

    // 📝 tierDescription — optional text description
    if (isset($input['tierDescription'])) {
        $setClauses[] = '`tierDescription` = ?';
        $params[]     = SecurityUtils::sanitizeString($input['tierDescription']);
        $types       .= 's';
    }

    // 💰 monthlyPrice — must be non-negative
    if (isset($input['monthlyPrice'])) {
        if (!is_numeric($input['monthlyPrice']) || (float) $input['monthlyPrice'] < 0) {
            throw new Exception('Monthly price must be a non-negative number');
        }
        $setClauses[] = '`monthlyPrice` = ?';
        $params[]     = (float) $input['monthlyPrice'];
        $types       .= 'd';
    }

    // 💰 yearlyPrice — must be non-negative
    if (isset($input['yearlyPrice'])) {
        if (!is_numeric($input['yearlyPrice']) || (float) $input['yearlyPrice'] < 0) {
            throw new Exception('Yearly price must be a non-negative number');
        }
        $setClauses[] = '`yearlyPrice` = ?';
        $params[]     = (float) $input['yearlyPrice'];
        $types       .= 'd';
    }

    // 📦 features — JSON array of feature strings
    if (isset($input['features'])) {
        $features = $input['features'];
        if (is_array($features)) {
            $features = json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (json_decode($features) === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Features must be a valid JSON array');
        }
        $setClauses[] = '`features` = ?';
        $params[]     = $features;
        $types       .= 's';
    }

    // 📦 featureLimits — JSON object of numeric limits
    if (isset($input['featureLimits'])) {
        $featureLimits = $input['featureLimits'];
        if (is_array($featureLimits)) {
            $featureLimits = json_encode($featureLimits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($featureLimits !== null && json_decode($featureLimits) === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Feature limits must be a valid JSON object');
        }
        $setClauses[] = '`featureLimits` = ?';
        $params[]     = $featureLimits;
        $types       .= 's';
    }

    // 📦 usageLimits — JSON object of per-metric usage limits
    if (isset($input['usageLimits'])) {
        $usageLimits = $input['usageLimits'];
        if (is_array($usageLimits)) {
            $usageLimits = json_encode($usageLimits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($usageLimits !== null && json_decode($usageLimits) === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Usage limits must be a valid JSON object');
        }
        $setClauses[] = '`usageLimits` = ?';
        $params[]     = $usageLimits;
        $types       .= 's';
    }

    // 👥 teamMemberLimit — nullable integer
    if (array_key_exists('teamMemberLimit', $input ?? [])) {
        $setClauses[] = '`teamMemberLimit` = ?';
        $params[]     = ($input['teamMemberLimit'] !== null && $input['teamMemberLimit'] !== '')
                        ? (int) $input['teamMemberLimit']
                        : null;
        $types       .= 'i';
    }

    // 📊 apiCallsPerMonth — nullable integer
    if (array_key_exists('apiCallsPerMonth', $input ?? [])) {
        $setClauses[] = '`apiCallsPerMonth` = ?';
        $params[]     = ($input['apiCallsPerMonth'] !== null && $input['apiCallsPerMonth'] !== '')
                        ? (int) $input['apiCallsPerMonth']
                        : null;
        $types       .= 'i';
    }

    // 💾 storageGB — nullable decimal
    if (array_key_exists('storageGB', $input ?? [])) {
        $setClauses[] = '`storageGB` = ?';
        $params[]     = ($input['storageGB'] !== null && $input['storageGB'] !== '')
                        ? (float) $input['storageGB']
                        : null;
        $types       .= 'd';
    }

    // 📅 trialDays — non-negative integer
    if (isset($input['trialDays'])) {
        $setClauses[] = '`trialDays` = ?';
        $params[]     = max(0, (int) $input['trialDays']);
        $types       .= 'i';
    }

    // 🏷️ badge — optional display badge text
    if (isset($input['badge'])) {
        $setClauses[] = '`badge` = ?';
        $params[]     = SecurityUtils::sanitizeString($input['badge']) ?: null;
        $types       .= 's';
    }

    // 🎨 badgeColor — optional CSS colour class for the badge
    if (isset($input['badgeColor'])) {
        $setClauses[] = '`badgeColor` = ?';
        $params[]     = SecurityUtils::sanitizeString($input['badgeColor']) ?: null;
        $types       .= 's';
    }

    // 💳 billingMode — must be one of the allowed ENUM values
    if (isset($input['billingMode'])) {
        $billingMode = SecurityUtils::sanitizeString($input['billingMode']);
        $validBillingModes = ['fixed', 'usage', 'hybrid'];
        if (!in_array($billingMode, $validBillingModes, true)) {
            throw new Exception('Billing mode must be one of: ' . implode(', ', $validBillingModes));
        }
        $setClauses[] = '`billingMode` = ?';
        $params[]     = $billingMode;
        $types       .= 's';
    }

    // 🔗 parentTierID — optional reference to parent tier
    if (array_key_exists('parentTierID', $input ?? [])) {
        $parentTierID = ($input['parentTierID'] !== null && $input['parentTierID'] !== '')
                        ? (int) $input['parentTierID']
                        : null;
        if ($parentTierID !== null) {
            // ✅ Validate parent tier exists
            $parentCheck = Database::fetchOne(
                "SELECT `tierID` FROM `tblSubscriptionTiers` WHERE `tierID` = ?",
                'i',
                $parentTierID
            );
            if (!$parentCheck) {
                throw new Exception('Parent tier not found with ID: ' . $parentTierID);
            }
            // 🚫 Prevent self-referencing
            if ($parentTierID === $tierID) {
                throw new Exception('A tier cannot be its own parent');
            }
        }
        $setClauses[] = '`parentTierID` = ?';
        $params[]     = $parentTierID;
        $types       .= 'i';
    }

    // 🚫 If no fields were provided to update, throw an error
    if (empty($setClauses)) {
        throw new Exception('No fields provided to update');
    }

    // ✏️ Execute the UPDATE query with all collected SET clauses
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    $updateSql = "UPDATE `tblSubscriptionTiers` SET " . implode(', ', $setClauses) . " WHERE `tierID` = ?";
    $params[]  = $tierID;
    $types    .= 'i';

    Database::query($updateSql, $types, ...$params);

    // 📝 Log the tier update in the activity log
    // @see ActivityLogger::log() — Audit trail logging
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'tier_updated',
        'admin',
        'info',
        'Subscription tier #' . $tierID . ' (' . ($existingTier['tierName'] ?? '') . ') updated',
        [
            'tierID'        => $tierID,
            'tierName'      => $existingTier['tierName'] ?? '',
            'updatedFields' => array_keys(array_filter($input ?? [], function ($key) {
                return !in_array($key, ['action', 'csrf_token', 'tierID'], true);
            }, ARRAY_FILTER_USE_KEY)),
        ]
    );

    // ✅ Return success response
    jsonResponse(true, 'Subscription tier updated successfully', ['tierID' => $tierID]);
}


// ============================================================================
// 🗑️ HANDLER: delete_tier
// ============================================================================

/**
 * 🗑️ Soft-delete a subscription tier by setting isActive=0.
 *
 * The tier is not physically deleted — it is deactivated so it no longer
 * appears in active tier listings but its data is preserved for historical
 * records and existing subscriptions.
 *
 * Deletion is blocked if there are active subscriptions on this tier.
 * Global tiers can only be deleted by Super Admins.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - tierID (int, required): The tier ID to soft-delete
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If tierID is invalid, has active subscriptions, or access denied
 *
 * @see tblSubscriptionTiers — Subscription tier definitions table
 * @see tblSubscriptions — User subscription records
 */
function handleDeleteTier(?array $input): void
{
    // ✅ Validate tierID parameter — must be a positive integer
    $tierID = (int) ($input['tierID'] ?? 0);
    if ($tierID <= 0) {
        throw new Exception('Valid tier ID is required');
    }

    // 📋 Fetch the existing tier to verify it exists and check permissions
    $existingTier = Database::fetchOne(
        "SELECT * FROM `tblSubscriptionTiers` WHERE `tierID` = ?",
        'i',
        $tierID
    );

    if (!$existingTier) {
        throw new Exception('Tier not found with ID: ' . $tierID);
    }

    // 🔐 Access control — check permissions based on tier type
    $userInfo     = $GLOBALS['userInfo'];
    $isSuperAdmin = $GLOBALS['isSuperAdmin'];

    if (!$isSuperAdmin) {
        // 🚫 Partner Admins cannot delete global tiers
        if ($existingTier['isCustom'] != 1) {
            throw new Exception('Only Super Admins can delete global tiers');
        }

        // 🚫 Partner Admins can only delete their own custom tiers
        $partnerID = (int) ($userInfo['partnerID'] ?? 0);
        if ((int) $existingTier['createdByPartnerID'] !== $partnerID) {
            throw new Exception('You do not have permission to delete this custom tier');
        }
    }

    // 🚫 Prevent deletion of the default tier
    if ($existingTier['isDefault'] == 1) {
        throw new Exception('Cannot delete the default tier. Set another tier as default first.');
    }

    // 🔍 Check for active subscriptions on this tier
    // Cannot deactivate a tier if users are actively subscribed to it
    // @see tblSubscriptions — User subscription records
    $activeSubscriptions = Database::fetchOne(
        "SELECT COUNT(*) AS cnt FROM `tblSubscriptions` WHERE `tierID` = ? AND `subscriptionStatus` IN ('active', 'trial', 'past_due')",
        'i',
        $tierID
    );

    if ((int) ($activeSubscriptions['cnt'] ?? 0) > 0) {
        $count = (int) $activeSubscriptions['cnt'];
        throw new Exception(
            'Cannot delete this tier — ' . $count . ' active subscription' . ($count !== 1 ? 's' : '')
            . ' exist' . ($count === 1 ? 's' : '') . ' on it. '
            . 'Please migrate subscribers to another tier first.'
        );
    }

    // 🗑️ Soft-delete: set isActive=0 instead of physically deleting the record
    // This preserves historical data and prevents referential integrity issues
    Database::query(
        "UPDATE `tblSubscriptionTiers` SET `isActive` = 0 WHERE `tierID` = ?",
        'i',
        $tierID
    );

    // 📝 Log the tier deletion in the activity log
    // @see ActivityLogger::log() — Audit trail logging
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'tier_deleted',
        'admin',
        'warning',
        'Subscription tier #' . $tierID . ' (' . ($existingTier['tierName'] ?? '') . ') soft-deleted (deactivated)',
        [
            'tierID'   => $tierID,
            'tierName' => $existingTier['tierName'] ?? '',
            'tierSlug' => $existingTier['tierSlug'] ?? '',
            'isCustom' => (int) ($existingTier['isCustom'] ?? 0),
        ]
    );

    // ✅ Return success response
    jsonResponse(true, 'Subscription tier "' . htmlspecialchars($existingTier['tierName'] ?? '') . '" has been deactivated');
}


// ============================================================================
// ✅ HANDLER: activate_tier
// ============================================================================

/**
 * ✅ Reactivate a soft-deleted subscription tier by setting isActive=1.
 *
 * Restores a previously deactivated tier so it appears in active listings again.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - tierID (int, required): The tier ID to reactivate
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If tierID is invalid or access denied
 *
 * @see tblSubscriptionTiers — Subscription tier definitions table
 */
function handleActivateTier(?array $input): void
{
    // ✅ Validate tierID parameter — must be a positive integer
    $tierID = (int) ($input['tierID'] ?? 0);
    if ($tierID <= 0) {
        throw new Exception('Valid tier ID is required');
    }

    // 📋 Fetch the existing tier to verify it exists and check permissions
    $existingTier = Database::fetchOne(
        "SELECT * FROM `tblSubscriptionTiers` WHERE `tierID` = ?",
        'i',
        $tierID
    );

    if (!$existingTier) {
        throw new Exception('Tier not found with ID: ' . $tierID);
    }

    // 🔐 Access control — check permissions based on tier type
    $userInfo     = $GLOBALS['userInfo'];
    $isSuperAdmin = $GLOBALS['isSuperAdmin'];

    if (!$isSuperAdmin) {
        // 🚫 Partner Admins cannot activate global tiers
        if ($existingTier['isCustom'] != 1) {
            throw new Exception('Only Super Admins can activate global tiers');
        }

        // 🚫 Partner Admins can only activate their own custom tiers
        $partnerID = (int) ($userInfo['partnerID'] ?? 0);
        if ((int) $existingTier['createdByPartnerID'] !== $partnerID) {
            throw new Exception('You do not have permission to activate this custom tier');
        }
    }

    // ⚠️ Check if the tier is already active
    if ($existingTier['isActive'] == 1) {
        throw new Exception('Tier is already active');
    }

    // ✅ Reactivate: set isActive=1
    Database::query(
        "UPDATE `tblSubscriptionTiers` SET `isActive` = 1 WHERE `tierID` = ?",
        'i',
        $tierID
    );

    // 📝 Log the tier activation in the activity log
    // @see ActivityLogger::log() — Audit trail logging
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'tier_activated',
        'admin',
        'info',
        'Subscription tier #' . $tierID . ' (' . ($existingTier['tierName'] ?? '') . ') reactivated',
        [
            'tierID'   => $tierID,
            'tierName' => $existingTier['tierName'] ?? '',
            'tierSlug' => $existingTier['tierSlug'] ?? '',
        ]
    );

    // ✅ Return success response
    jsonResponse(true, 'Subscription tier "' . htmlspecialchars($existingTier['tierName'] ?? '') . '" has been reactivated');
}


// ============================================================================
// ⭐ HANDLER: set_default
// ============================================================================

/**
 * ⭐ Set a tier as the default tier for new user registrations.
 *
 * Only one tier can be the default at a time. Setting a new default
 * automatically unsets the previous default (isDefault=0 for all others).
 * Only Super Admins can change the default tier.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - tierID (int, required): The tier ID to set as default
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If tierID is invalid, tier is inactive, or access denied
 *
 * @see tblSubscriptionTiers — Subscription tier definitions table
 */
function handleSetDefault(?array $input): void
{
    // ✅ Validate tierID parameter — must be a positive integer
    $tierID = (int) ($input['tierID'] ?? 0);
    if ($tierID <= 0) {
        throw new Exception('Valid tier ID is required');
    }

    // 🔐 Only Super Admins can change the default tier
    // This is a global system setting that affects all new registrations
    $userInfo     = $GLOBALS['userInfo'];
    $isSuperAdmin = $GLOBALS['isSuperAdmin'];

    if (!$isSuperAdmin) {
        throw new Exception('Only Super Admins can change the default tier');
    }

    // 📋 Fetch the target tier to verify it exists and is active
    $targetTier = Database::fetchOne(
        "SELECT * FROM `tblSubscriptionTiers` WHERE `tierID` = ?",
        'i',
        $tierID
    );

    if (!$targetTier) {
        throw new Exception('Tier not found with ID: ' . $tierID);
    }

    // 🚫 Cannot set an inactive tier as the default
    if ($targetTier['isActive'] != 1) {
        throw new Exception('Cannot set an inactive tier as the default. Activate it first.');
    }

    // 🚫 Cannot set a custom tier as the system default
    if ($targetTier['isCustom'] == 1) {
        throw new Exception('Custom tiers cannot be set as the system default. Only global tiers can be the default.');
    }

    // 🔄 Unset all current defaults — only one tier can be the default
    // @see https://dev.mysql.com/doc/refman/8.0/en/update.html
    Database::query(
        "UPDATE `tblSubscriptionTiers` SET `isDefault` = 0 WHERE `isDefault` = 1"
    );

    // ⭐ Set the target tier as the new default
    Database::query(
        "UPDATE `tblSubscriptionTiers` SET `isDefault` = 1 WHERE `tierID` = ?",
        'i',
        $tierID
    );

    // 📝 Log the default tier change in the activity log
    // @see ActivityLogger::log() — Audit trail logging
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'tier_default_changed',
        'admin',
        'info',
        'Default subscription tier changed to #' . $tierID . ' (' . ($targetTier['tierName'] ?? '') . ')',
        [
            'tierID'   => $tierID,
            'tierName' => $targetTier['tierName'] ?? '',
            'tierSlug' => $targetTier['tierSlug'] ?? '',
        ]
    );

    // ✅ Return success response
    jsonResponse(true, '"' . htmlspecialchars($targetTier['tierName'] ?? '') . '" is now the default tier for new users');
}


// ============================================================================
// 🔄 HANDLER: reorder_tiers
// ============================================================================

/**
 * 🔄 Update the display order for multiple subscription tiers.
 *
 * Accepts an array of tier ID / display order pairs and updates the
 * displayOrder column for each tier in a single transaction.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - tiers (array, required): Array of objects with { tierID: int, displayOrder: int }
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If tiers array is missing, empty, or contains invalid data
 *
 * @see tblSubscriptionTiers — Subscription tier definitions table
 */
function handleReorderTiers(?array $input): void
{
    // 📥 Extract the tiers array from the input
    $tiers = $input['tiers'] ?? [];

    // ✅ Validate tiers — must be a non-empty array
    if (!is_array($tiers) || empty($tiers)) {
        throw new Exception('A non-empty array of tiers with tierID and displayOrder is required');
    }

    // 🔐 Access control
    $userInfo     = $GLOBALS['userInfo'];
    $isSuperAdmin = $GLOBALS['isSuperAdmin'];

    // 🔄 Validate each tier entry and update displayOrder
    $updatedCount = 0;

    foreach ($tiers as $index => $tierEntry) {
        // ✅ Validate each entry has required fields
        if (!isset($tierEntry['tierID']) || !isset($tierEntry['displayOrder'])) {
            throw new Exception('Each tier entry must have tierID and displayOrder (error at index ' . $index . ')');
        }

        $entryTierID      = (int) $tierEntry['tierID'];
        $entryDisplayOrder = (int) $tierEntry['displayOrder'];

        // ✅ Validate values are positive
        if ($entryTierID <= 0) {
            throw new Exception('Invalid tier ID at index ' . $index);
        }
        if ($entryDisplayOrder < 0) {
            throw new Exception('Display order must be non-negative at index ' . $index);
        }

        // 🔐 Partner Admins can only reorder their own custom tiers
        if (!$isSuperAdmin) {
            $tierCheck = Database::fetchOne(
                "SELECT `isCustom`, `createdByPartnerID` FROM `tblSubscriptionTiers` WHERE `tierID` = ?",
                'i',
                $entryTierID
            );

            if (!$tierCheck) {
                throw new Exception('Tier not found with ID: ' . $entryTierID);
            }

            if ($tierCheck['isCustom'] != 1) {
                throw new Exception('Only Super Admins can reorder global tiers');
            }

            $partnerID = (int) ($userInfo['partnerID'] ?? 0);
            if ((int) $tierCheck['createdByPartnerID'] !== $partnerID) {
                throw new Exception('You do not have permission to reorder tier #' . $entryTierID);
            }
        }

        // 🔄 Update the displayOrder for this tier
        Database::query(
            "UPDATE `tblSubscriptionTiers` SET `displayOrder` = ? WHERE `tierID` = ?",
            'ii',
            $entryDisplayOrder,
            $entryTierID
        );

        $updatedCount++;
    }

    // 📝 Log the reorder operation in the activity log
    // @see ActivityLogger::log() — Audit trail logging
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'tiers_reordered',
        'admin',
        'info',
        'Subscription tiers reordered (' . $updatedCount . ' tier' . ($updatedCount !== 1 ? 's' : '') . ' updated)',
        [
            'updatedCount' => $updatedCount,
            'tierIDs'      => array_column($tiers, 'tierID'),
        ]
    );

    // ✅ Return success response
    jsonResponse(true, $updatedCount . ' tier' . ($updatedCount !== 1 ? 's' : '') . ' reordered successfully', [
        'updatedCount' => $updatedCount,
    ]);
}


// ============================================================================
// 📋 HANDLER: duplicate_tier
// ============================================================================

/**
 * 📋 Duplicate an existing tier as a new custom tier.
 *
 * Creates a copy of an existing tier with a new name and slug, marked
 * as a custom tier (isCustom=1) and linked to the creating partner.
 * The duplicated tier inherits all settings from the source but is
 * created as inactive (isActive=0) so it can be reviewed before activation.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - tierID (int, required): The source tier ID to duplicate
 *   - tierName (string, optional): Name for the new tier (default: "Copy of [original]")
 *   - tierSlug (string, optional): Slug for the new tier (default: "[original]-copy")
 *   - csrf_token (string): CSRF token for validation
 *
 * @return void Outputs JSON response with the new duplicated tier ID
 *
 * @throws Exception If source tier not found, slug conflict, or limits exceeded
 *
 * @see tblSubscriptionTiers — Subscription tier definitions table
 */
function handleDuplicateTier(?array $input): void
{
    // ✅ Validate tierID parameter — must be a positive integer
    $sourceTierID = (int) ($input['tierID'] ?? 0);
    if ($sourceTierID <= 0) {
        throw new Exception('Valid source tier ID is required');
    }

    // 📋 Fetch the source tier to duplicate
    $sourceTier = Database::fetchOne(
        "SELECT * FROM `tblSubscriptionTiers` WHERE `tierID` = ?",
        'i',
        $sourceTierID
    );

    if (!$sourceTier) {
        throw new Exception('Source tier not found with ID: ' . $sourceTierID);
    }

    // 🔐 Access control
    $userInfo     = $GLOBALS['userInfo'];
    $isSuperAdmin = $GLOBALS['isSuperAdmin'];

    // 📌 Determine the creating partner ID for the duplicate
    $createdByPartnerID = (int) ($userInfo['partnerID'] ?? 0);
    if ($isSuperAdmin && isset($input['createdByPartnerID']) && $input['createdByPartnerID'] !== '') {
        $createdByPartnerID = (int) $input['createdByPartnerID'];
    }

    // 📊 Check the max custom tiers per partner limit
    // @see getSetting() — Retrieves settings from tblSettings
    $maxCustomTiers = (int) getSetting('tiers.custom.max_per_partner', '10');

    $existingCount = Database::fetchOne(
        "SELECT COUNT(*) AS cnt FROM `tblSubscriptionTiers` WHERE `isCustom` = 1 AND `createdByPartnerID` = ?",
        'i',
        $createdByPartnerID
    );

    if ((int) ($existingCount['cnt'] ?? 0) >= $maxCustomTiers) {
        throw new Exception(
            'Custom tier limit reached. Maximum ' . $maxCustomTiers . ' custom tiers allowed per partner. '
            . 'Contact support to increase this limit.'
        );
    }

    // 📝 Generate name and slug for the duplicate
    $newName = SecurityUtils::sanitizeString($input['tierName'] ?? '');
    $newSlug = SecurityUtils::sanitizeString($input['tierSlug'] ?? '');

    // 🏷️ Default name: "Copy of [original name]"
    if (empty($newName)) {
        $newName = 'Copy of ' . ($sourceTier['tierName'] ?? 'Unknown');
    }

    // 🔗 Default slug: "[original-slug]-copy" (with collision avoidance)
    if (empty($newSlug)) {
        $baseSlug = ($sourceTier['tierSlug'] ?? 'tier') . '-copy';
        $newSlug  = $baseSlug;
        $counter  = 1;

        // 🔄 Handle slug collisions by appending incrementing numbers
        // e.g., "premium-copy", "premium-copy-2", "premium-copy-3", etc.
        while (true) {
            $slugExists = Database::fetchOne(
                "SELECT `tierID` FROM `tblSubscriptionTiers` WHERE `tierSlug` = ?",
                's',
                $newSlug
            );
            if (!$slugExists) {
                break; // ✅ Unique slug found
            }
            $counter++;
            $newSlug = $baseSlug . '-' . $counter;

            // 🛡️ Safety limit to prevent infinite loops
            if ($counter > 100) {
                throw new Exception('Unable to generate a unique slug. Please provide a custom slug.');
            }
        }
    } else {
        // ✅ Validate custom slug format
        if (!preg_match('/^[a-z0-9_-]+$/', $newSlug)) {
            throw new Exception('Tier slug must contain only lowercase letters, numbers, hyphens, and underscores');
        }

        // 🔍 Check custom slug uniqueness
        $slugExists = Database::fetchOne(
            "SELECT `tierID` FROM `tblSubscriptionTiers` WHERE `tierSlug` = ?",
            's',
            $newSlug
        );
        if ($slugExists) {
            throw new Exception('A tier with the slug "' . htmlspecialchars($newSlug) . '" already exists');
        }
    }

    // 📊 Determine display order — place duplicate at the end
    $maxOrder = Database::fetchOne(
        "SELECT MAX(`displayOrder`) AS maxOrder FROM `tblSubscriptionTiers`"
    );
    $displayOrder = ((int) ($maxOrder['maxOrder'] ?? 0)) + 1;

    // ➕ Insert the duplicated tier as a new custom tier
    // 📌 Key differences from source:
    //   - New name and slug
    //   - isCustom = 1 (always a custom tier)
    //   - createdByPartnerID = current partner
    //   - parentTierID = source tier ID (tracks lineage)
    //   - isActive = 0 (created as inactive for review)
    //   - isDefault = 0 (never set duplicate as default)
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    $insertSql = "INSERT INTO `tblSubscriptionTiers` (
        `tierName`, `tierSlug`, `tierDescription`,
        `monthlyPrice`, `yearlyPrice`, `currency`,
        `features`, `featureLimits`, `usageLimits`,
        `teamMemberLimit`, `apiCallsPerMonth`, `storageGB`,
        `displayOrder`, `isActive`, `isDefault`, `trialDays`,
        `badge`, `badgeColor`, `billingMode`,
        `isCustom`, `createdByPartnerID`, `parentTierID`
    ) VALUES (
        ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?,
        ?, 0, 0, ?,
        ?, ?, ?,
        1, ?, ?
    )";

    Database::query(
        $insertSql,
        'sss' . 'dds' . 'sss' . 'iid' . 'ii' . 'sss' . 'ii',
        $newName,
        $newSlug,
        $sourceTier['tierDescription'],
        (float) $sourceTier['monthlyPrice'],
        (float) $sourceTier['yearlyPrice'],
        $sourceTier['currency'] ?? 'GBP',
        $sourceTier['features'] ?? '[]',
        $sourceTier['featureLimits'],
        $sourceTier['usageLimits'],
        $sourceTier['teamMemberLimit'],
        $sourceTier['apiCallsPerMonth'],
        (float) ($sourceTier['storageGB'] ?? 0),
        $displayOrder,
        (int) ($sourceTier['trialDays'] ?? 0),
        $sourceTier['badge'],
        $sourceTier['badgeColor'],
        $sourceTier['billingMode'] ?? 'fixed',
        $createdByPartnerID,
        $sourceTierID
    );

    // 📊 Get the newly created duplicate tier ID
    // @see https://www.php.net/manual/en/mysqli.insert-id.php
    $newTierID = Database::getInstance()->getConnection()->insert_id;

    if (!$newTierID) {
        throw new Exception('Failed to duplicate subscription tier. Please try again.');
    }

    // 📝 Log the tier duplication in the activity log
    // @see ActivityLogger::log() — Audit trail logging
    ActivityLogger::log(
        (int) $userInfo['userID'],
        'tier_duplicated',
        'admin',
        'info',
        'Subscription tier #' . $sourceTierID . ' (' . ($sourceTier['tierName'] ?? '') . ') duplicated as #'
            . $newTierID . ' (' . $newName . ') for partner #' . $createdByPartnerID,
        [
            'sourceTierID'       => $sourceTierID,
            'sourceTierName'     => $sourceTier['tierName'] ?? '',
            'newTierID'          => $newTierID,
            'newTierName'        => $newName,
            'newTierSlug'        => $newSlug,
            'createdByPartnerID' => $createdByPartnerID,
        ]
    );

    // ✅ Return success response with new tier ID
    jsonResponse(true, 'Tier "' . htmlspecialchars($sourceTier['tierName'] ?? '') . '" duplicated as "' . htmlspecialchars($newName) . '" (inactive, ready for review)', [
        'tierID'       => $newTierID,
        'tierName'     => $newName,
        'tierSlug'     => $newSlug,
        'sourceTierID' => $sourceTierID,
    ]);
}
