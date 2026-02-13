<?php
/**
 * ============================================================================
 * 🏷️ SIGNula - Partner Discount Code Management API
 * ============================================================================
 *
 * Purpose: JSON API endpoint for managing partner-scoped discount/promo codes.
 *          All actions are scoped to a specific partner organization and require
 *          admin-level access (isPartnerAdmin). Supports CRUD operations on
 *          discount codes and user-to-code assignment management.
 *
 * Actions (GET):
 *   - get_discounts    — Retrieve all discount codes for a partner (with usage stats)
 *   - get_discount     — Retrieve a single discount code's full details
 *   - get_assignments  — Retrieve user assignments for a specific discount code
 *
 * Actions (POST):
 *   - create_discount   — Create a new discount code with full configuration
 *   - update_discount   — Update an existing discount code's fields
 *   - toggle_discount   — Enable or disable a discount code (isActive toggle)
 *   - assign_code       — Assign a discount code to a user by email address
 *   - remove_assignment — Remove a user assignment from a discount code
 *   - generate_code     — Generate a random 8-character alphanumeric code string
 *
 * Database Tables:
 *   - tblDiscountCodes            — Discount code definitions (per partner)
 *   - tblDiscountCodeAssignments  — Maps assigned-type codes to specific users
 *   - tblUsers                    — Referenced for user lookup during assignment
 *
 * Authentication: Partner admin role required for ALL actions
 * CSRF Protection: Required for ALL POST requests (via SecurityUtils)
 *
 * PHP Version: 8.3+
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Partners\API\Discounts
 * @version    1.0.0
 * @since      2.4.0-beta
 * @link       https://SIGNula.id
 * @see        https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php (MySQLi Prepared Statements)
 * @see        https://owasp.org/www-community/attacks/csrf (OWASP CSRF Prevention)
 * @see        https://www.php.net/manual/en/function.random-bytes.php (Secure random generation)
 * ============================================================================
 */

// ============================================================================
// 🚀 BOOTSTRAP — Load core system files
// ============================================================================

// ⚙️ Main configuration (defines SIGNULA_INIT constant, loads settings, starts session)
// @see web/_config/config.php — Central configuration loader
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton (MySQLi prepared statements)
// @see web/_config/database.php — Database abstraction layer with singleton pattern
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🎫 Session manager (authentication state management)
// @see web/_backend/SessionManager.php — Handles session lifecycle and user state
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🔐 Access control (role-based permissions enforcement)
// @see web/_backend/AccessControl.php — RBAC implementation with partner-level checks
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 📝 Activity logger (audit trail for all actions)
// @see web/private_html/utils/ActivityLogger.php — Logs to tblActivityLog
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'ActivityLogger.php';

// ============================================================================
// 📤 RESPONSE HEADERS — All responses are JSON-encoded
// ============================================================================

// 📤 Set JSON response content type header
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
header('Content-Type: application/json');

// ============================================================================
// 🔐 AUTHENTICATION — User must be logged in
// ============================================================================

// 🗄️ Initialize core services — database, session, and access control
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);
$activityLogger = new ActivityLogger($db, $sessionManager);

// 🔐 Check authentication — unauthenticated users receive HTTP 401
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized — please log in to access this resource.'
    ]);
    exit;
}

// ============================================================================
// 📥 REQUEST PARSING — Supports GET query params and POST JSON body
// ============================================================================

// 📥 Parse incoming data based on request method
// GET requests use query string parameters (?action=xxx&partnerID=yyy)
// POST requests use JSON-encoded request body
// @see https://www.php.net/manual/en/wrappers.php.php — php://input stream
$input = json_decode(file_get_contents('php://input'), true);
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// 🛡️ CSRF validation for POST requests — prevents cross-site request forgery
// The CSRF token is sent either in the JSON body or as an HTTP header (X-CSRF-TOKEN)
// @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Prevention Cheat Sheet
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        echo json_encode([
            'success' => false,
            'error'   => 'Invalid or expired CSRF token. Please refresh the page and try again.'
        ]);
        exit;
    }
}

// ============================================================================
// 🗺️ ACTION ROUTER — Dispatch to the appropriate handler function
// ============================================================================

try {
    switch ($action) {

        // ============================================================
        // 📊 GET — Retrieve all discount codes for a partner
        // ============================================================
        case 'get_discounts':
            handleGetDiscounts($db, $accessControl);
            break;

        // ============================================================
        // 📊 GET — Retrieve a single discount code's full details
        // ============================================================
        case 'get_discount':
            handleGetDiscount($db, $accessControl);
            break;

        // ============================================================
        // 📊 GET — Retrieve user assignments for a discount code
        // ============================================================
        case 'get_assignments':
            handleGetAssignments($db, $accessControl);
            break;

        // ============================================================
        // ➕ POST — Create a new discount code
        // ============================================================
        case 'create_discount':
            handleCreateDiscount($input, $db, $accessControl, $activityLogger);
            break;

        // ============================================================
        // ✏️ POST — Update an existing discount code
        // ============================================================
        case 'update_discount':
            handleUpdateDiscount($input, $db, $accessControl, $activityLogger);
            break;

        // ============================================================
        // 🔀 POST — Toggle discount code active/inactive
        // ============================================================
        case 'toggle_discount':
            handleToggleDiscount($input, $db, $accessControl, $activityLogger);
            break;

        // ============================================================
        // 👤 POST — Assign a discount code to a user by email
        // ============================================================
        case 'assign_code':
            handleAssignCode($input, $db, $accessControl, $activityLogger);
            break;

        // ============================================================
        // 🗑️ POST — Remove a user assignment from a discount code
        // ============================================================
        case 'remove_assignment':
            handleRemoveAssignment($input, $db, $accessControl, $activityLogger);
            break;

        // ============================================================
        // 🎲 POST — Generate a random discount code string
        // ============================================================
        case 'generate_code':
            handleGenerateCode($input, $db, $accessControl);
            break;

        // ============================================================
        // ❌ Unknown action — return structured error
        // ============================================================
        default:
            throw new Exception(
                'Invalid action: "' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')
                . '". Valid actions are: get_discounts, get_discount, get_assignments, '
                . 'create_discount, update_discount, toggle_discount, assign_code, '
                . 'remove_assignment, generate_code.'
            );
    }
} catch (Exception $e) {
    // 🚨 Catch-all error handler — return a structured JSON error response
    // HTTP 400 indicates a client-side error (bad request, invalid input, etc.)
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}


// ============================================================================
// ============================================================================
//                        HANDLER FUNCTIONS
// ============================================================================
// ============================================================================


// ============================================================================
// 📊 HANDLER: get_discounts
// ============================================================================

/**
 * 📊 Retrieve all discount codes for a specific partner
 *
 * Fetches all discount code records from tblDiscountCodes for the given
 * partnerID. Results include the currentUses count and all configuration
 * fields. Ordered by creation date (newest first).
 *
 * Required GET params:
 *   - partnerID (int) — The partner organization ID
 *
 * @param mysqli  $db            Database connection instance
 * @param object  $accessControl Access control service for permission checks
 *
 * @return void Outputs JSON with discounts array
 *
 * @throws Exception If partnerID is missing or access is denied
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/select.html (MySQL SELECT syntax)
 */
function handleGetDiscounts(mysqli $db, AccessControl $accessControl): void
{
    // 📥 Get partner ID from query string
    $partnerID = (int) ($_GET['partnerID'] ?? 0);

    // 🔐 Verify admin access to this partner
    if ($partnerID <= 0 || !$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Admin access required for this partner.');
    }

    // 🗄️ Fetch all discount codes for this partner
    // Ordered by isActive DESC (active first), then createdAt DESC (newest first)
    // @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
    $stmt = $db->prepare("
        SELECT
            codeID,
            code,
            partnerID,
            codeType,
            discountType,
            discountValue,
            validFrom,
            validTo,
            maxUses,
            currentUses,
            applicableTiers,
            applicableProviders,
            allowedCountries,
            blockedCountries,
            minPurchase,
            isActive,
            createdAt,
            updatedAt
        FROM tblDiscountCodes
        WHERE partnerID = ?
        ORDER BY isActive DESC, createdAt DESC
    ");
    $stmt->bind_param('i', $partnerID);
    $stmt->execute();
    $result = $stmt->get_result();
    $discounts = $result->fetch_all(MYSQLI_ASSOC);

    // ✅ Return the discount codes
    echo json_encode([
        'success'   => true,
        'discounts' => $discounts,
        'count'     => count($discounts)
    ]);
}


// ============================================================================
// 📊 HANDLER: get_discount
// ============================================================================

/**
 * 📊 Retrieve a single discount code's full details
 *
 * Fetches one discount code record by codeID, verifying it belongs to
 * the specified partner. Used to populate the edit form.
 *
 * Required GET params:
 *   - codeID    (int) — The discount code's primary key
 *   - partnerID (int) — The partner organization ID (for ownership check)
 *
 * @param mysqli  $db            Database connection instance
 * @param object  $accessControl Access control service for permission checks
 *
 * @return void Outputs JSON with single discount object
 *
 * @throws Exception If codeID or partnerID is invalid, or code not found
 */
function handleGetDiscount(mysqli $db, AccessControl $accessControl): void
{
    // 📥 Get parameters from query string
    $codeID    = (int) ($_GET['codeID'] ?? 0);
    $partnerID = (int) ($_GET['partnerID'] ?? 0);

    // 🔐 Verify admin access
    if ($partnerID <= 0 || !$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Admin access required for this partner.');
    }

    // ✅ Validate codeID
    if ($codeID <= 0) {
        throw new Exception('A valid discount code ID is required.');
    }

    // 🗄️ Fetch the discount code — also verify it belongs to this partner
    // The partnerID check prevents one partner from viewing another's codes
    $stmt = $db->prepare("
        SELECT
            codeID,
            code,
            partnerID,
            codeType,
            discountType,
            discountValue,
            validFrom,
            validTo,
            maxUses,
            currentUses,
            applicableTiers,
            applicableProviders,
            allowedCountries,
            blockedCountries,
            minPurchase,
            isActive,
            createdAt,
            updatedAt
        FROM tblDiscountCodes
        WHERE codeID = ? AND partnerID = ?
    ");
    $stmt->bind_param('ii', $codeID, $partnerID);
    $stmt->execute();
    $discount = $stmt->get_result()->fetch_assoc();

    // 🚫 Not found or doesn't belong to this partner
    if (!$discount) {
        throw new Exception('Discount code not found or does not belong to this partner.');
    }

    // ✅ Return the discount details
    echo json_encode([
        'success'  => true,
        'discount' => $discount
    ]);
}


// ============================================================================
// 📊 HANDLER: get_assignments
// ============================================================================

/**
 * 📊 Retrieve all user assignments for a specific discount code
 *
 * Fetches records from tblDiscountCodeAssignments for the given codeID,
 * joined with tblUsers to include username and email. First verifies
 * that the code belongs to the specified partner.
 *
 * Required GET params:
 *   - codeID    (int) — The discount code's primary key
 *   - partnerID (int) — The partner organization ID
 *
 * @param mysqli  $db            Database connection instance
 * @param object  $accessControl Access control service
 *
 * @return void Outputs JSON with assignments array
 *
 * @throws Exception If parameters are invalid or access is denied
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/join.html (MySQL JOIN syntax)
 */
function handleGetAssignments(mysqli $db, AccessControl $accessControl): void
{
    // 📥 Get parameters
    $codeID    = (int) ($_GET['codeID'] ?? 0);
    $partnerID = (int) ($_GET['partnerID'] ?? 0);

    // 🔐 Verify admin access
    if ($partnerID <= 0 || !$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Admin access required for this partner.');
    }

    // ✅ Validate codeID
    if ($codeID <= 0) {
        throw new Exception('A valid discount code ID is required.');
    }

    // 🔍 First verify the code belongs to this partner (security check)
    // Prevents one partner from viewing another partner's code assignments
    $stmt = $db->prepare("
        SELECT codeID FROM tblDiscountCodes
        WHERE codeID = ? AND partnerID = ?
    ");
    $stmt->bind_param('ii', $codeID, $partnerID);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        throw new Exception('Discount code not found or does not belong to this partner.');
    }

    // 🗄️ Fetch all assignments for this code
    // LEFT JOIN tblUsers to include username — userID may be NULL if assigned by email only
    // @see https://dev.mysql.com/doc/refman/8.0/en/join.html
    $stmt = $db->prepare("
        SELECT
            a.assignmentID,
            a.codeID,
            a.userID,
            a.email,
            a.assignedAt,
            a.usedAt,
            u.username
        FROM tblDiscountCodeAssignments a
        LEFT JOIN tblUsers u ON a.userID = u.userID
        WHERE a.codeID = ?
        ORDER BY a.assignedAt DESC
    ");
    $stmt->bind_param('i', $codeID);
    $stmt->execute();
    $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // ✅ Return the assignments
    echo json_encode([
        'success'     => true,
        'assignments' => $assignments,
        'count'       => count($assignments)
    ]);
}


// ============================================================================
// ➕ HANDLER: create_discount
// ============================================================================

/**
 * ➕ Create a new discount code for a partner
 *
 * Inserts a new record into tblDiscountCodes with the provided configuration.
 * Validates all required fields, checks for code uniqueness within the partner,
 * and logs the creation in the activity log.
 *
 * Required POST fields:
 *   - partnerID           (int)    — Partner organization ID
 *   - code                (string) — The discount code string (uppercase alphanumeric)
 *   - codeType            (string) — "universal" or "assigned"
 *   - discountType        (string) — "percentage" or "fixed"
 *   - discountValue       (float)  — The discount amount
 *   - validFrom           (string) — Start date in YYYY-MM-DD format
 *
 * Optional POST fields:
 *   - validTo             (string) — End date in YYYY-MM-DD format (NULL = no expiry)
 *   - maxUses             (int)    — Maximum number of uses (0 = unlimited)
 *   - applicableTiers     (string) — Comma-separated tier IDs
 *   - applicableProviders (string) — Comma-separated providers or "all"
 *   - allowedCountries    (array)  — Array of ISO alpha-2 country codes
 *   - blockedCountries    (array)  — Array of ISO alpha-2 country codes
 *   - minPurchase         (float)  — Minimum purchase amount (0 = none)
 *   - csrf_token          (string) — CSRF token for validation
 *
 * @param array|null $input          JSON-decoded request body
 * @param mysqli     $db             Database connection
 * @param object     $accessControl  Access control service
 * @param object     $activityLogger Activity logging service
 *
 * @return void Outputs JSON success response with the new codeID
 *
 * @throws Exception On validation failure or database error
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 */
function handleCreateDiscount(
    ?array $input,
    mysqli $db,
    AccessControl $accessControl,
    ActivityLogger $activityLogger
): void {
    // ════════════════════════════════════════════════════════════════
    // 📥 EXTRACT AND VALIDATE INPUT
    // ════════════════════════════════════════════════════════════════

    $partnerID = (int) ($input['partnerID'] ?? 0);

    // 🔐 Verify admin access to this partner
    if ($partnerID <= 0 || !$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Admin access required for this partner.');
    }

    // 🏷️ Validate discount code — required, alphanumeric, uppercase
    $code = strtoupper(trim($input['code'] ?? ''));
    if (empty($code)) {
        throw new Exception('Discount code is required.');
    }
    // 🔍 Only allow alphanumeric characters and hyphens/underscores
    if (!preg_match('/^[A-Z0-9_\-]+$/', $code)) {
        throw new Exception('Discount code must contain only letters, numbers, hyphens, and underscores.');
    }
    if (strlen($code) > 50) {
        throw new Exception('Discount code must not exceed 50 characters.');
    }

    // 🔀 Validate code type
    $codeType = trim($input['codeType'] ?? '');
    if (!in_array($codeType, ['universal', 'assigned'], true)) {
        throw new Exception('Code type must be "universal" or "assigned".');
    }

    // 💰 Validate discount type
    $discountType = trim($input['discountType'] ?? '');
    if (!in_array($discountType, ['percentage', 'fixed'], true)) {
        throw new Exception('Discount type must be "percentage" or "fixed".');
    }

    // 💲 Validate discount value
    $discountValue = $input['discountValue'] ?? null;
    if ($discountValue === null || !is_numeric($discountValue) || (float) $discountValue <= 0) {
        throw new Exception('Discount value must be a positive number.');
    }
    $discountValue = (float) $discountValue;

    // ⚠️ Percentage must be between 0 and 100
    if ($discountType === 'percentage' && $discountValue > 100) {
        throw new Exception('Percentage discount cannot exceed 100%.');
    }

    // 📅 Validate validFrom date — required
    $validFrom = trim($input['validFrom'] ?? '');
    if (empty($validFrom)) {
        throw new Exception('Valid From date is required.');
    }
    // 🔍 Verify the date format using DateTime::createFromFormat
    // @see https://www.php.net/manual/en/datetime.createfromformat.php
    $dateObj = DateTime::createFromFormat('Y-m-d', $validFrom);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $validFrom) {
        throw new Exception('Valid From date must be in YYYY-MM-DD format.');
    }

    // 📅 Validate validTo date — optional
    $validTo = trim($input['validTo'] ?? '');
    if (!empty($validTo)) {
        $validToObj = DateTime::createFromFormat('Y-m-d', $validTo);
        if (!$validToObj || $validToObj->format('Y-m-d') !== $validTo) {
            throw new Exception('Valid To date must be in YYYY-MM-DD format.');
        }
        // ⚠️ validTo must be on or after validFrom
        if ($validTo < $validFrom) {
            throw new Exception('Valid To date cannot be before Valid From date.');
        }
    } else {
        // 🔄 Normalize empty string to NULL for database storage
        $validTo = null;
    }

    // 🔢 Validate maxUses — 0 means unlimited
    $maxUses = (int) ($input['maxUses'] ?? 0);
    if ($maxUses < 0) {
        throw new Exception('Maximum uses cannot be negative.');
    }

    // 📦 Applicable tiers — comma-separated tier IDs (optional)
    $applicableTiers = trim($input['applicableTiers'] ?? '');

    // 💳 Applicable providers — comma-separated or "all" (optional)
    $applicableProviders = trim($input['applicableProviders'] ?? 'all');

    // 🌍 Country restrictions — JSON arrays of ISO alpha-2 codes
    $allowedCountries = $input['allowedCountries'] ?? [];
    $blockedCountries = $input['blockedCountries'] ?? [];

    // 🔄 Encode as JSON strings for database storage
    $allowedCountriesJSON = !empty($allowedCountries) ? json_encode($allowedCountries) : null;
    $blockedCountriesJSON = !empty($blockedCountries) ? json_encode($blockedCountries) : null;

    // 💷 Minimum purchase amount
    $minPurchase = (float) ($input['minPurchase'] ?? 0);
    if ($minPurchase < 0) {
        throw new Exception('Minimum purchase amount cannot be negative.');
    }

    // ════════════════════════════════════════════════════════════════
    // 🔍 UNIQUENESS CHECK — Code must be unique within the partner
    // ════════════════════════════════════════════════════════════════

    // 🔍 Check if a code with the same name already exists for this partner
    // This prevents duplicate codes within the same partner organization
    $stmt = $db->prepare("
        SELECT codeID FROM tblDiscountCodes
        WHERE partnerID = ? AND code = ?
    ");
    $stmt->bind_param('is', $partnerID, $code);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        throw new Exception('A discount code with this name already exists for this partner.');
    }

    // ════════════════════════════════════════════════════════════════
    // 🗄️ INSERT — Create the new discount code record
    // ════════════════════════════════════════════════════════════════

    // 🗄️ Insert into tblDiscountCodes with prepared statement
    // @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
    $stmt = $db->prepare("
        INSERT INTO tblDiscountCodes
            (code, partnerID, codeType, discountType, discountValue,
             validFrom, validTo, maxUses, currentUses,
             applicableTiers, applicableProviders,
             allowedCountries, blockedCountries, minPurchase,
             isActive, createdAt, updatedAt)
        VALUES
            (?, ?, ?, ?, ?,
             ?, ?, ?, 0,
             ?, ?,
             ?, ?, ?,
             1, NOW(), NOW())
    ");

    // 📌 MySQLi type string explanation:
    // s = code (string)
    // i = partnerID (integer)
    // s = codeType (string)
    // s = discountType (string)
    // d = discountValue (double/decimal)
    // s = validFrom (string/date)
    // s = validTo (string/date, NULL allowed)
    // i = maxUses (integer)
    // s = applicableTiers (string)
    // s = applicableProviders (string)
    // s = allowedCountries (string/JSON, NULL allowed)
    // s = blockedCountries (string/JSON, NULL allowed)
    // d = minPurchase (double/decimal)
    $stmt->bind_param(
        'sissdssissssd',
        $code,
        $partnerID,
        $codeType,
        $discountType,
        $discountValue,
        $validFrom,
        $validTo,
        $maxUses,
        $applicableTiers,
        $applicableProviders,
        $allowedCountriesJSON,
        $blockedCountriesJSON,
        $minPurchase
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to create discount code. Database error.');
    }

    // 🔑 Get the auto-generated codeID
    $newCodeID = $db->insert_id;

    // ════════════════════════════════════════════════════════════════
    // 📝 ACTIVITY LOGGING — Record the creation action
    // ════════════════════════════════════════════════════════════════

    // 📝 Log the admin action for audit trail
    // @see AccessControl::logAdminAction() — Structured admin action logging
    $accessControl->logAdminAction('create_discount_code', 'discount', $newCodeID, [
        'code'          => $code,
        'codeType'      => $codeType,
        'discountType'  => $discountType,
        'discountValue' => $discountValue,
        'partnerID'     => $partnerID
    ]);

    // 📝 Log general activity via ActivityLogger
    // @see ActivityLogger::logActivity() — General activity logging
    $activityLogger->logActivity(
        'discount_code_created',
        'Discount code created: ' . $code . ' (' . $discountType . ' ' . $discountValue . ')',
        ['codeID' => $newCodeID, 'partnerID' => $partnerID, 'code' => $code]
    );

    // ✅ Return success response with the new code ID
    echo json_encode([
        'success' => true,
        'message' => 'Discount code "' . $code . '" created successfully.',
        'codeID'  => $newCodeID
    ]);
}


// ============================================================================
// ✏️ HANDLER: update_discount
// ============================================================================

/**
 * ✏️ Update an existing discount code's configuration
 *
 * Updates a record in tblDiscountCodes identified by codeID. Verifies
 * ownership by checking partnerID. All updatable fields can be changed.
 *
 * Required POST fields:
 *   - partnerID (int)    — Partner organization ID
 *   - codeID    (int)    — The discount code to update
 *   - code      (string) — Updated code string
 *   - All other fields same as create_discount
 *
 * @param array|null $input          JSON-decoded request body
 * @param mysqli     $db             Database connection
 * @param object     $accessControl  Access control service
 * @param object     $activityLogger Activity logging service
 *
 * @return void Outputs JSON success/failure response
 *
 * @throws Exception On validation failure, not found, or database error
 */
function handleUpdateDiscount(
    ?array $input,
    mysqli $db,
    AccessControl $accessControl,
    ActivityLogger $activityLogger
): void {
    // ════════════════════════════════════════════════════════════════
    // 📥 EXTRACT AND VALIDATE INPUT
    // ════════════════════════════════════════════════════════════════

    $partnerID = (int) ($input['partnerID'] ?? 0);
    $codeID    = (int) ($input['codeID'] ?? 0);

    // 🔐 Verify admin access
    if ($partnerID <= 0 || !$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Admin access required for this partner.');
    }

    // ✅ Validate codeID
    if ($codeID <= 0) {
        throw new Exception('A valid discount code ID is required.');
    }

    // 🔍 Verify the discount code exists and belongs to this partner
    $stmt = $db->prepare("
        SELECT codeID, code AS oldCode FROM tblDiscountCodes
        WHERE codeID = ? AND partnerID = ?
    ");
    $stmt->bind_param('ii', $codeID, $partnerID);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    if (!$existing) {
        throw new Exception('Discount code not found or does not belong to this partner.');
    }

    // 🏷️ Validate updated code
    $code = strtoupper(trim($input['code'] ?? ''));
    if (empty($code)) {
        throw new Exception('Discount code is required.');
    }
    if (!preg_match('/^[A-Z0-9_\-]+$/', $code)) {
        throw new Exception('Discount code must contain only letters, numbers, hyphens, and underscores.');
    }
    if (strlen($code) > 50) {
        throw new Exception('Discount code must not exceed 50 characters.');
    }

    // 🔀 Validate code type
    $codeType = trim($input['codeType'] ?? '');
    if (!in_array($codeType, ['universal', 'assigned'], true)) {
        throw new Exception('Code type must be "universal" or "assigned".');
    }

    // 💰 Validate discount type
    $discountType = trim($input['discountType'] ?? '');
    if (!in_array($discountType, ['percentage', 'fixed'], true)) {
        throw new Exception('Discount type must be "percentage" or "fixed".');
    }

    // 💲 Validate discount value
    $discountValue = $input['discountValue'] ?? null;
    if ($discountValue === null || !is_numeric($discountValue) || (float) $discountValue <= 0) {
        throw new Exception('Discount value must be a positive number.');
    }
    $discountValue = (float) $discountValue;
    if ($discountType === 'percentage' && $discountValue > 100) {
        throw new Exception('Percentage discount cannot exceed 100%.');
    }

    // 📅 Validate dates
    $validFrom = trim($input['validFrom'] ?? '');
    if (empty($validFrom)) {
        throw new Exception('Valid From date is required.');
    }
    $dateObj = DateTime::createFromFormat('Y-m-d', $validFrom);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $validFrom) {
        throw new Exception('Valid From date must be in YYYY-MM-DD format.');
    }

    $validTo = trim($input['validTo'] ?? '');
    if (!empty($validTo)) {
        $validToObj = DateTime::createFromFormat('Y-m-d', $validTo);
        if (!$validToObj || $validToObj->format('Y-m-d') !== $validTo) {
            throw new Exception('Valid To date must be in YYYY-MM-DD format.');
        }
        if ($validTo < $validFrom) {
            throw new Exception('Valid To date cannot be before Valid From date.');
        }
    } else {
        $validTo = null;
    }

    // 🔢 Other fields
    $maxUses             = (int) ($input['maxUses'] ?? 0);
    $applicableTiers     = trim($input['applicableTiers'] ?? '');
    $applicableProviders = trim($input['applicableProviders'] ?? 'all');
    $minPurchase         = (float) ($input['minPurchase'] ?? 0);

    // 🌍 Country restrictions
    $allowedCountries     = $input['allowedCountries'] ?? [];
    $blockedCountries     = $input['blockedCountries'] ?? [];
    $allowedCountriesJSON = !empty($allowedCountries) ? json_encode($allowedCountries) : null;
    $blockedCountriesJSON = !empty($blockedCountries) ? json_encode($blockedCountries) : null;

    // 🔍 Check for code name uniqueness (if the code name changed)
    if (strtoupper($existing['oldCode']) !== $code) {
        $stmt = $db->prepare("
            SELECT codeID FROM tblDiscountCodes
            WHERE partnerID = ? AND code = ? AND codeID != ?
        ");
        $stmt->bind_param('isi', $partnerID, $code, $codeID);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception('Another discount code with this name already exists for this partner.');
        }
    }

    // ════════════════════════════════════════════════════════════════
    // 🗄️ UPDATE — Modify the discount code record
    // ════════════════════════════════════════════════════════════════

    $stmt = $db->prepare("
        UPDATE tblDiscountCodes
        SET code               = ?,
            codeType           = ?,
            discountType       = ?,
            discountValue      = ?,
            validFrom          = ?,
            validTo            = ?,
            maxUses            = ?,
            applicableTiers    = ?,
            applicableProviders = ?,
            allowedCountries   = ?,
            blockedCountries   = ?,
            minPurchase        = ?,
            updatedAt          = NOW()
        WHERE codeID = ? AND partnerID = ?
    ");

    // 📌 MySQLi type string:
    // s=code, s=codeType, s=discountType, d=discountValue,
    // s=validFrom, s=validTo, i=maxUses, s=applicableTiers,
    // s=applicableProviders, s=allowedCountries, s=blockedCountries,
    // d=minPurchase, i=codeID, i=partnerID
    $stmt->bind_param(
        'sssdssissssdii',
        $code,
        $codeType,
        $discountType,
        $discountValue,
        $validFrom,
        $validTo,
        $maxUses,
        $applicableTiers,
        $applicableProviders,
        $allowedCountriesJSON,
        $blockedCountriesJSON,
        $minPurchase,
        $codeID,
        $partnerID
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to update discount code. Database error.');
    }

    // ════════════════════════════════════════════════════════════════
    // 📝 ACTIVITY LOGGING
    // ════════════════════════════════════════════════════════════════

    $accessControl->logAdminAction('update_discount_code', 'discount', $codeID, [
        'code'          => $code,
        'oldCode'       => $existing['oldCode'],
        'codeType'      => $codeType,
        'discountType'  => $discountType,
        'discountValue' => $discountValue,
        'partnerID'     => $partnerID
    ]);

    $activityLogger->logActivity(
        'discount_code_updated',
        'Discount code updated: ' . $code . ' (ID: ' . $codeID . ')',
        ['codeID' => $codeID, 'partnerID' => $partnerID, 'code' => $code]
    );

    // ✅ Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Discount code "' . $code . '" updated successfully.'
    ]);
}


// ============================================================================
// 🔀 HANDLER: toggle_discount
// ============================================================================

/**
 * 🔀 Enable or disable a discount code by toggling its isActive flag
 *
 * Soft-toggles a discount code's active state. Does NOT delete the record —
 * simply sets isActive to 0 (disabled) or 1 (enabled). This preserves the
 * audit trail and allows re-enabling codes later.
 *
 * Required POST fields:
 *   - partnerID (int) — Partner organization ID
 *   - codeID    (int) — The discount code to toggle
 *   - isActive  (int) — 1 to enable, 0 to disable
 *   - csrf_token (string) — CSRF token
 *
 * @param array|null $input          JSON-decoded request body
 * @param mysqli     $db             Database connection
 * @param object     $accessControl  Access control service
 * @param object     $activityLogger Activity logging service
 *
 * @return void Outputs JSON success/failure response
 *
 * @throws Exception On validation failure or database error
 */
function handleToggleDiscount(
    ?array $input,
    mysqli $db,
    AccessControl $accessControl,
    ActivityLogger $activityLogger
): void {
    $partnerID = (int) ($input['partnerID'] ?? 0);
    $codeID    = (int) ($input['codeID'] ?? 0);
    $isActive  = (int) ($input['isActive'] ?? 0);

    // 🔐 Verify admin access
    if ($partnerID <= 0 || !$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Admin access required for this partner.');
    }

    // ✅ Validate codeID
    if ($codeID <= 0) {
        throw new Exception('A valid discount code ID is required.');
    }

    // 🔍 Verify ownership — the code must belong to this partner
    $stmt = $db->prepare("
        SELECT code FROM tblDiscountCodes
        WHERE codeID = ? AND partnerID = ?
    ");
    $stmt->bind_param('ii', $codeID, $partnerID);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    if (!$existing) {
        throw new Exception('Discount code not found or does not belong to this partner.');
    }

    // 🔄 Normalize isActive to 0 or 1
    $isActive = ($isActive === 1) ? 1 : 0;

    // 🗄️ Update the isActive flag
    $stmt = $db->prepare("
        UPDATE tblDiscountCodes
        SET isActive = ?, updatedAt = NOW()
        WHERE codeID = ? AND partnerID = ?
    ");
    $stmt->bind_param('iii', $isActive, $codeID, $partnerID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to update discount code status.');
    }

    // 📝 Log the toggle action
    $statusText = $isActive ? 'enabled' : 'disabled';

    $accessControl->logAdminAction('toggle_discount_code', 'discount', $codeID, [
        'code'     => $existing['code'],
        'isActive' => $isActive
    ]);

    $activityLogger->logActivity(
        'discount_code_toggled',
        'Discount code ' . $statusText . ': ' . $existing['code'],
        ['codeID' => $codeID, 'partnerID' => $partnerID, 'isActive' => $isActive]
    );

    // ✅ Return success
    echo json_encode([
        'success' => true,
        'message' => 'Discount code "' . $existing['code'] . '" ' . $statusText . ' successfully.'
    ]);
}


// ============================================================================
// 👤 HANDLER: assign_code
// ============================================================================

/**
 * 👤 Assign a discount code to a specific user by email address
 *
 * Looks up the user in tblUsers by their email address, then creates a new
 * record in tblDiscountCodeAssignments linking the user to the discount code.
 * The discount code must be of type "assigned" (not "universal").
 *
 * Required POST fields:
 *   - partnerID (int)    — Partner organization ID
 *   - codeID    (int)    — The discount code to assign
 *   - email     (string) — The user's email address
 *   - csrf_token (string) — CSRF token
 *
 * @param array|null $input          JSON-decoded request body
 * @param mysqli     $db             Database connection
 * @param object     $accessControl  Access control service
 * @param object     $activityLogger Activity logging service
 *
 * @return void Outputs JSON success/failure response
 *
 * @throws Exception On validation failure, user not found, or duplicate assignment
 */
function handleAssignCode(
    ?array $input,
    mysqli $db,
    AccessControl $accessControl,
    ActivityLogger $activityLogger
): void {
    $partnerID = (int) ($input['partnerID'] ?? 0);
    $codeID    = (int) ($input['codeID'] ?? 0);
    $email     = filter_var(trim($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);

    // 🔐 Verify admin access
    if ($partnerID <= 0 || !$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Admin access required for this partner.');
    }

    // ✅ Validate inputs
    if ($codeID <= 0) {
        throw new Exception('A valid discount code ID is required.');
    }
    if (!$email) {
        throw new Exception('A valid email address is required.');
    }

    // 🔍 Verify the discount code exists, belongs to this partner, and is of type "assigned"
    $stmt = $db->prepare("
        SELECT codeID, code, codeType FROM tblDiscountCodes
        WHERE codeID = ? AND partnerID = ?
    ");
    $stmt->bind_param('ii', $codeID, $partnerID);
    $stmt->execute();
    $discountCode = $stmt->get_result()->fetch_assoc();

    if (!$discountCode) {
        throw new Exception('Discount code not found or does not belong to this partner.');
    }

    // ⚠️ Only assigned-type codes can have user assignments
    // Universal codes work for everyone — no assignment needed
    if ($discountCode['codeType'] !== 'assigned') {
        throw new Exception('Only "assigned" type codes can be assigned to specific users. Universal codes are available to everyone.');
    }

    // 🔍 Look up the user by email in tblUsers
    // @see tblUsers — Core user accounts table
    $stmt = $db->prepare("
        SELECT userID, email, username FROM tblUsers
        WHERE email = ?
    ");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        throw new Exception('No SIGNula user found with email: ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '. The user must create an account first.');
    }

    // 🔍 Check for duplicate assignment — prevent assigning the same code twice
    $stmt = $db->prepare("
        SELECT assignmentID FROM tblDiscountCodeAssignments
        WHERE codeID = ? AND userID = ?
    ");
    $stmt->bind_param('ii', $codeID, $user['userID']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        throw new Exception('This user is already assigned to this discount code.');
    }

    // ════════════════════════════════════════════════════════════════
    // 🗄️ INSERT — Create the assignment record
    // ════════════════════════════════════════════════════════════════

    $stmt = $db->prepare("
        INSERT INTO tblDiscountCodeAssignments
            (codeID, userID, email, assignedAt)
        VALUES
            (?, ?, ?, NOW())
    ");
    $stmt->bind_param('iis', $codeID, $user['userID'], $email);

    if (!$stmt->execute()) {
        throw new Exception('Failed to assign discount code to user.');
    }

    $assignmentID = $db->insert_id;

    // 📝 Log the assignment
    $accessControl->logAdminAction('assign_discount_code', 'discount_assignment', $assignmentID, [
        'codeID' => $codeID,
        'code'   => $discountCode['code'],
        'email'  => $email,
        'userID' => $user['userID']
    ]);

    $activityLogger->logActivity(
        'discount_code_assigned',
        'Discount code "' . $discountCode['code'] . '" assigned to ' . $email,
        ['codeID' => $codeID, 'partnerID' => $partnerID, 'email' => $email, 'userID' => $user['userID']]
    );

    // ✅ Return success
    echo json_encode([
        'success'      => true,
        'message'      => 'Code "' . $discountCode['code'] . '" assigned to ' . $email . ' successfully.',
        'assignmentID' => $assignmentID
    ]);
}


// ============================================================================
// 🗑️ HANDLER: remove_assignment
// ============================================================================

/**
 * 🗑️ Remove a user assignment from a discount code
 *
 * Deletes a record from tblDiscountCodeAssignments. Verifies that the
 * assignment's parent discount code belongs to the specified partner
 * before allowing deletion (prevents cross-partner manipulation).
 *
 * Required POST fields:
 *   - partnerID    (int) — Partner organization ID
 *   - assignmentID (int) — The assignment record to remove
 *   - csrf_token   (string) — CSRF token
 *
 * @param array|null $input          JSON-decoded request body
 * @param mysqli     $db             Database connection
 * @param object     $accessControl  Access control service
 * @param object     $activityLogger Activity logging service
 *
 * @return void Outputs JSON success/failure response
 *
 * @throws Exception On validation failure or if assignment not found
 */
function handleRemoveAssignment(
    ?array $input,
    mysqli $db,
    AccessControl $accessControl,
    ActivityLogger $activityLogger
): void {
    $partnerID    = (int) ($input['partnerID'] ?? 0);
    $assignmentID = (int) ($input['assignmentID'] ?? 0);

    // 🔐 Verify admin access
    if ($partnerID <= 0 || !$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Admin access required for this partner.');
    }

    // ✅ Validate assignmentID
    if ($assignmentID <= 0) {
        throw new Exception('A valid assignment ID is required.');
    }

    // 🔍 Fetch the assignment details — verify it belongs to a code owned by this partner
    // JOIN with tblDiscountCodes to check partnerID ownership
    // This prevents a partner admin from deleting assignments for another partner's codes
    $stmt = $db->prepare("
        SELECT
            a.assignmentID,
            a.codeID,
            a.userID,
            a.email,
            dc.partnerID,
            dc.code
        FROM tblDiscountCodeAssignments a
        JOIN tblDiscountCodes dc ON a.codeID = dc.codeID
        WHERE a.assignmentID = ? AND dc.partnerID = ?
    ");
    $stmt->bind_param('ii', $assignmentID, $partnerID);
    $stmt->execute();
    $assignment = $stmt->get_result()->fetch_assoc();

    if (!$assignment) {
        throw new Exception('Assignment not found or does not belong to this partner.');
    }

    // 🗑️ Delete the assignment record
    // Unlike discount codes which use soft-delete (isActive), assignments are
    // permanently removed since there is no audit benefit to keeping them
    $stmt = $db->prepare("
        DELETE FROM tblDiscountCodeAssignments
        WHERE assignmentID = ?
    ");
    $stmt->bind_param('i', $assignmentID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to remove assignment.');
    }

    // 📝 Log the removal
    $accessControl->logAdminAction('remove_discount_assignment', 'discount_assignment', $assignmentID, [
        'codeID' => $assignment['codeID'],
        'code'   => $assignment['code'],
        'email'  => $assignment['email'],
        'userID' => $assignment['userID']
    ]);

    $activityLogger->logActivity(
        'discount_assignment_removed',
        'Removed discount code assignment: ' . $assignment['code'] . ' from ' . $assignment['email'],
        ['assignmentID' => $assignmentID, 'codeID' => $assignment['codeID'], 'partnerID' => $partnerID]
    );

    // ✅ Return success
    echo json_encode([
        'success' => true,
        'message' => 'Assignment removed successfully.'
    ]);
}


// ============================================================================
// 🎲 HANDLER: generate_code
// ============================================================================

/**
 * 🎲 Generate a random 8-character alphanumeric discount code
 *
 * Uses PHP's random_bytes() for cryptographic randomness, then maps to
 * uppercase alphanumeric characters (A-Z, 0-9). The generated code is
 * checked for uniqueness within the partner's existing codes before
 * being returned.
 *
 * Required POST fields:
 *   - partnerID  (int) — Partner organization ID
 *   - csrf_token (string) — CSRF token
 *
 * @param array|null $input          JSON-decoded request body
 * @param mysqli     $db             Database connection
 * @param object     $accessControl  Access control service
 *
 * @return void Outputs JSON with generated code string
 *
 * @throws Exception If access is denied or generation fails after max attempts
 *
 * @see https://www.php.net/manual/en/function.random-bytes.php (Cryptographically secure random bytes)
 */
function handleGenerateCode(
    ?array $input,
    mysqli $db,
    AccessControl $accessControl
): void {
    $partnerID = (int) ($input['partnerID'] ?? 0);

    // 🔐 Verify admin access
    if ($partnerID <= 0 || !$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Admin access required for this partner.');
    }

    // 🔤 Character pool — uppercase letters and digits (no ambiguous chars like O/0, I/1)
    $chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $length = 8;

    // 🔄 Attempt generation with uniqueness check (max 10 attempts)
    // In extremely rare cases, a collision could happen if the partner has many codes
    $maxAttempts = 10;
    $attempt     = 0;
    $code        = '';

    while ($attempt < $maxAttempts) {
        $attempt++;
        $code = '';

        // 🎲 Generate random bytes and map to the character pool
        // @see https://www.php.net/manual/en/function.random-bytes.php
        $randomBytes = random_bytes($length);
        $charsLength = strlen($chars);

        for ($i = 0; $i < $length; $i++) {
            // Map each random byte to a character in the pool
            // Using modulo to select from the available characters
            $code .= $chars[ord($randomBytes[$i]) % $charsLength];
        }

        // 🔍 Check if this code already exists for this partner
        $stmt = $db->prepare("
            SELECT codeID FROM tblDiscountCodes
            WHERE partnerID = ? AND code = ?
        ");
        $stmt->bind_param('is', $partnerID, $code);
        $stmt->execute();

        // ✅ If no duplicate found, we have our unique code
        if ($stmt->get_result()->num_rows === 0) {
            break;
        }

        // ⚠️ Collision detected — try again
        if ($attempt >= $maxAttempts) {
            throw new Exception('Unable to generate a unique code after ' . $maxAttempts . ' attempts. Please try again.');
        }
    }

    // ✅ Return the generated code
    echo json_encode([
        'success' => true,
        'code'    => $code
    ]);
}
