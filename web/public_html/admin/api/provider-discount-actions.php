<?php
/**
 * ============================================================================
 * 🏷️ SIGNula - Provider Discount Management API (Admin)
 * ============================================================================
 *
 * Purpose: Admin API endpoint for managing payment provider-specific discounts.
 *          Allows super admins to create, update, retrieve, and deactivate
 *          discounts tied to specific payment providers (Stripe, PayPal,
 *          Coinbase Commerce). These discounts incentivize use of preferred
 *          payment methods — e.g., X% discount when paying with cryptocurrency.
 *
 * Actions (GET):
 *   - get_provider_discounts  — Retrieve all discounts from tblProviderDiscounts
 *                                grouped by provider, with partner names via JOIN
 *
 * Actions (POST):
 *   - save_provider_discount  — Create or update a provider discount (global or
 *                                partner-specific override)
 *   - delete_provider_discount — Soft-delete (deactivate) a provider discount by
 *                                setting isActive = 0
 *
 * Database Table: tblProviderDiscounts
 *   - discountID INT UNSIGNED AUTO_INCREMENT (PK)
 *   - provider ENUM('stripe','paypal','coinbase')
 *   - partnerID INT UNSIGNED NULL (NULL = global, non-NULL = partner override)
 *   - discountPercent DECIMAL(5,2)
 *   - description TEXT
 *   - effectiveDate DATE
 *   - expiresAt DATE NULL
 *   - isActive TINYINT(1) DEFAULT 1
 *   - createdAt DATETIME
 *   - updatedAt DATETIME
 *
 * Authentication: Super Admin only (isSuperAdmin = 1)
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
 * @subpackage Admin\API\Payments
 * @version    1.0.0
 * @since      2.3.0-beta
 * @link       https://SIGNula.id
 * @see        https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php (MySQLi Prepared Statements)
 * @see        https://owasp.org/www-community/attacks/csrf (OWASP CSRF Prevention)
 * ============================================================================
 */

// ============================================================================
// 🚀 BOOTSTRAP — Load core system files
// ============================================================================

// ⚙️ Main configuration (defines constants, loads settings, initializes session)
// @see web/_config/config.php — Central configuration loader
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton (MySQLi prepared statements)
// @see web/_backend/Database.php — Database abstraction layer
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🎫 Session manager (authentication state management)
// @see web/_backend/SessionManager.php — Handles session lifecycle
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🔐 Access control (role-based permissions enforcement)
// @see web/_backend/AccessControl.php — RBAC implementation
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 📤 Set JSON response content type header
// All responses from this API endpoint are JSON-encoded
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
header('Content-Type: application/json');

// ============================================================================
// 🔐 AUTHENTICATION & AUTHORIZATION
// ============================================================================

// 🗄️ Initialize core services — database, session, and access control
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔐 Check authentication — user must be logged in
// Unauthenticated requests receive HTTP 401 Unauthorized
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized — please log in to access this resource.'
    ]);
    exit;
}

// 🔐 Require super admin — only super admins can manage provider discounts
// Non-super-admin users receive HTTP 403 Forbidden
$userInfo = $sessionManager->getUserInfo();
if (!isset($userInfo['isSuperAdmin']) || $userInfo['isSuperAdmin'] != 1) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Super admin access required to manage provider discounts.'
    ]);
    exit;
}

// ============================================================================
// 📥 REQUEST PARSING
// ============================================================================

// 📥 Get request data — supports both GET query params and POST JSON body
// GET requests use query string parameters (?action=xxx)
// POST requests use JSON-encoded request body
// @see https://www.php.net/manual/en/wrappers.php.php — php://input stream
$input = json_decode(file_get_contents('php://input'), true);
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// 🛡️ CSRF validation for POST requests — prevents cross-site request forgery
// The CSRF token is sent either in the JSON body or as an HTTP header
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
// 🗺️ ACTION ROUTER — Dispatch to appropriate handler based on action param
// ============================================================================

try {
    switch ($action) {

        // ============================================================
        // 📊 GET — Retrieve all provider discounts grouped by provider
        // ============================================================
        case 'get_provider_discounts':
            handleGetProviderDiscounts();
            break;

        // ============================================================
        // 💾 POST — Create or update a provider discount
        // ============================================================
        case 'save_provider_discount':
            handleSaveProviderDiscount($input);
            break;

        // ============================================================
        // 🗑️ POST — Soft-delete (deactivate) a provider discount
        // ============================================================
        case 'delete_provider_discount':
            handleDeleteProviderDiscount($input);
            break;

        // ============================================================
        // 📋 GET — Retrieve list of partners for dropdown population
        // ============================================================
        case 'get_partners_list':
            handleGetPartnersList();
            break;

        // ============================================================
        // ❌ Unknown action — return a structured error response
        // ============================================================
        default:
            throw new Exception('Invalid action: "' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '". Valid actions are: get_provider_discounts, save_provider_discount, delete_provider_discount, get_partners_list.');
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
// 📊 HANDLER: get_provider_discounts
// ============================================================================

/**
 * 📊 Retrieve all provider discounts from tblProviderDiscounts
 *
 * Fetches all discount records (active and inactive) and groups them by
 * provider (stripe, paypal, coinbase). For partner-specific overrides,
 * the partner's organization name is included via a LEFT JOIN on tblPartners.
 *
 * Each discount also receives a computed 'status' field based on:
 *   - isActive flag (0 = deactivated/deleted)
 *   - effectiveDate vs current date (future = upcoming)
 *   - expiresAt vs current date (past = expired)
 *   - Otherwise = active
 *
 * Response format:
 * {
 *   "success": true,
 *   "discounts": {
 *     "stripe":   [ { discountID, provider, partnerID, partnerName, discountPercent, ... }, ... ],
 *     "paypal":   [ ... ],
 *     "coinbase": [ ... ]
 *   }
 * }
 *
 * @return void Outputs JSON response with discounts grouped by provider
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/join.html (MySQL JOIN syntax)
 * @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html (CURDATE())
 */
function handleGetProviderDiscounts(): void
{
    // 🗄️ Fetch all provider discounts with partner names via LEFT JOIN
    // LEFT JOIN ensures global discounts (partnerID IS NULL) are included
    // Results are ordered by: provider ASC, then global first (partnerID IS NULL),
    // then by effectiveDate DESC for consistent display ordering
    $discounts = Database::fetchAll(
        "SELECT
            pd.discountID,
            pd.provider,
            pd.partnerID,
            pd.discountPercent,
            pd.description,
            pd.effectiveDate,
            pd.expiresAt,
            pd.isActive,
            pd.createdAt,
            pd.updatedAt,
            p.organizationName AS partnerName
         FROM tblProviderDiscounts pd
         LEFT JOIN tblPartners p ON pd.partnerID = p.partnerID
         ORDER BY pd.provider ASC, pd.partnerID IS NOT NULL ASC, pd.effectiveDate DESC",
        [],
        ''
    );

    // 🏗️ Initialize the grouped structure with empty arrays for each provider
    // This ensures all three providers appear in the response even if they
    // have no discounts configured yet
    $grouped = [
        'stripe'   => [],
        'paypal'   => [],
        'coinbase' => [],
    ];

    // 📅 Get today's date for status computation
    // @see https://www.php.net/manual/en/function.date.php
    $today = date('Y-m-d');

    // 🔄 Process each discount record and compute its display status
    foreach ($discounts as $row) {
        // 🏷️ Compute the display status based on dates and isActive flag
        // Priority: deactivated > expired > upcoming > active
        $computedStatus = 'active'; // Default status

        if ((int) $row['isActive'] === 0) {
            // 🗑️ Discount has been soft-deleted / deactivated
            $computedStatus = 'inactive';
        } elseif (!empty($row['expiresAt']) && $row['expiresAt'] < $today) {
            // ⏰ Discount has passed its expiration date
            $computedStatus = 'expired';
        } elseif (!empty($row['effectiveDate']) && $row['effectiveDate'] > $today) {
            // 📅 Discount hasn't started yet (scheduled for the future)
            $computedStatus = 'upcoming';
        }

        // 📦 Add the computed status to the row data
        $row['computedStatus'] = $computedStatus;

        // 📂 Group the discount into its provider bucket
        $provider = $row['provider'] ?? 'stripe';
        if (isset($grouped[$provider])) {
            $grouped[$provider][] = $row;
        }
    }

    // ✅ Return the grouped discounts as a JSON response
    echo json_encode([
        'success'   => true,
        'discounts' => $grouped,
    ]);
}


// ============================================================================
// 💾 HANDLER: save_provider_discount
// ============================================================================

/**
 * 💾 Create or update a provider discount in tblProviderDiscounts
 *
 * If a discountID is provided in the input, the existing record is updated.
 * Otherwise, a new record is inserted. Supports both global discounts
 * (partnerID = NULL) and partner-specific overrides (partnerID = integer).
 *
 * Required POST fields:
 *   - provider (string): "stripe", "paypal", or "coinbase"
 *   - discountPercent (numeric): Discount percentage (0.00 – 100.00)
 *   - effectiveDate (string): Start date in YYYY-MM-DD format
 *
 * Optional POST fields:
 *   - discountID (int): If provided, updates existing record
 *   - partnerID (int|null): NULL for global, integer for partner override
 *   - description (string): Human-readable description of the discount
 *   - expiresAt (string|null): Expiry date in YYYY-MM-DD format (NULL = never)
 *   - isActive (int): 1 = active, 0 = inactive (default: 1)
 *   - csrf_token (string): CSRF token for validation
 *
 * @param array|null $input JSON-decoded request body from php://input
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If validation fails (invalid provider, out-of-range percent, etc.)
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php (Prepared Statements)
 * @see https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html (INSERT ... ON DUPLICATE)
 */
function handleSaveProviderDiscount(?array $input): void
{
    // ════════════════════════════════════════════════════════════════
    // ✅ INPUT VALIDATION
    // ════════════════════════════════════════════════════════════════

    // 🔌 Validate provider — must be one of the three supported payment providers
    $provider = trim($input['provider'] ?? '');
    $validProviders = ['stripe', 'paypal', 'coinbase'];
    if (!in_array($provider, $validProviders, true)) {
        throw new Exception(
            'Invalid provider. Must be one of: ' . implode(', ', $validProviders) . '.'
        );
    }

    // 💰 Validate discount percentage — must be a number between 0 and 100
    $discountPercent = $input['discountPercent'] ?? null;
    if ($discountPercent === null || !is_numeric($discountPercent)) {
        throw new Exception('Discount percentage is required and must be a valid number.');
    }
    $discountPercent = (float) $discountPercent;
    if ($discountPercent < 0 || $discountPercent > 100) {
        throw new Exception('Discount percentage must be between 0.00 and 100.00.');
    }

    // 📅 Validate effective date — must be a valid YYYY-MM-DD date
    $effectiveDate = trim($input['effectiveDate'] ?? '');
    if (empty($effectiveDate)) {
        throw new Exception('Effective date is required.');
    }
    // 🔍 Verify the date format using DateTime::createFromFormat
    // @see https://www.php.net/manual/en/datetime.createfromformat.php
    $dateObj = DateTime::createFromFormat('Y-m-d', $effectiveDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $effectiveDate) {
        throw new Exception('Effective date must be in YYYY-MM-DD format.');
    }

    // 📅 Validate expiry date (optional) — if provided, must be valid and after effective date
    $expiresAt = trim($input['expiresAt'] ?? '');
    if (!empty($expiresAt)) {
        $expiresObj = DateTime::createFromFormat('Y-m-d', $expiresAt);
        if (!$expiresObj || $expiresObj->format('Y-m-d') !== $expiresAt) {
            throw new Exception('Expiry date must be in YYYY-MM-DD format.');
        }
        // ⚠️ Expiry date must be on or after the effective date
        if ($expiresAt < $effectiveDate) {
            throw new Exception('Expiry date cannot be before the effective date.');
        }
    } else {
        // 🔄 Normalize empty string to NULL for database storage
        $expiresAt = null;
    }

    // 🏢 Validate partner ID (optional) — NULL for global, integer for partner override
    $partnerID = $input['partnerID'] ?? null;
    if ($partnerID !== null && $partnerID !== '' && $partnerID !== 0) {
        $partnerID = (int) $partnerID;
        // 🔍 Verify the partner actually exists in tblPartners
        $partnerExists = Database::fetchOne(
            "SELECT partnerID FROM tblPartners WHERE partnerID = ?",
            [$partnerID],
            'i'
        );
        if (!$partnerExists) {
            throw new Exception('Partner with ID ' . $partnerID . ' does not exist.');
        }
    } else {
        // 🔄 Normalize empty/zero/null to actual NULL for global discounts
        $partnerID = null;
    }

    // 📝 Sanitize the description text (optional field)
    $description = trim($input['description'] ?? '');

    // 🔘 Parse the isActive flag — defaults to 1 (active)
    $isActive = isset($input['isActive']) ? ((int) $input['isActive'] ? 1 : 0) : 1;

    // 🔑 Check if this is an update (discountID provided) or a new record
    $discountID = isset($input['discountID']) ? (int) $input['discountID'] : null;

    // ════════════════════════════════════════════════════════════════
    // 🗄️ DATABASE OPERATION — INSERT or UPDATE
    // ════════════════════════════════════════════════════════════════

    if ($discountID !== null && $discountID > 0) {
        // ════════════════════════════════════════════════════════════
        // ✏️ UPDATE — Modify an existing discount record
        // ════════════════════════════════════════════════════════════

        // 🔍 First verify the discount record exists before attempting update
        $existing = Database::fetchOne(
            "SELECT discountID FROM tblProviderDiscounts WHERE discountID = ?",
            [$discountID],
            'i'
        );
        if (!$existing) {
            throw new Exception('Discount record with ID ' . $discountID . ' not found.');
        }

        // 🗄️ Execute the UPDATE query with prepared statement
        // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
        Database::query(
            "UPDATE tblProviderDiscounts
             SET provider = ?,
                 partnerID = ?,
                 discountPercent = ?,
                 description = ?,
                 effectiveDate = ?,
                 expiresAt = ?,
                 isActive = ?,
                 updatedAt = NOW()
             WHERE discountID = ?",
            [
                $provider,
                $partnerID,
                $discountPercent,
                $description,
                $effectiveDate,
                $expiresAt,
                $isActive,
                $discountID
            ],
            // 📌 MySQLi type string:
            // s = provider (string)
            // i = partnerID (integer, NULL handled by MySQLi)
            // d = discountPercent (double/decimal)
            // s = description (string)
            // s = effectiveDate (string)
            // s = expiresAt (string, NULL handled by MySQLi)
            // i = isActive (integer)
            // i = discountID (integer)
            'sidsssis'
        );

        // 📝 Log the update action in the activity log
        // @see ActivityLogger::log() — requires 6 parameters
        $userInfo = $GLOBALS['sessionManager']->getUserInfo();
        ActivityLogger::log(
            (int) $userInfo['userID'],                                  // 👤 User who made the change
            'provider_discount_updated',                                 // 📌 Activity type
            'payment',                                                   // 📂 Category
            'info',                                                      // ℹ️ Severity level
            'Provider discount updated: ' . $provider . ' (ID: ' . $discountID . ')', // 📝 Description
            [                                                            // 📊 Metadata
                'discountID'      => $discountID,
                'provider'        => $provider,
                'partnerID'       => $partnerID,
                'discountPercent' => $discountPercent,
            ]
        );

        // ✅ Return success response for update operation
        echo json_encode([
            'success'    => true,
            'message'    => ucfirst($provider) . ' discount updated successfully.',
            'discountID' => $discountID,
        ]);

    } else {
        // ════════════════════════════════════════════════════════════
        // ➕ INSERT — Create a new discount record
        // ════════════════════════════════════════════════════════════

        // 🗄️ Execute the INSERT query with prepared statement
        // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
        Database::query(
            "INSERT INTO tblProviderDiscounts
                (provider, partnerID, discountPercent, description, effectiveDate, expiresAt, isActive, createdAt, updatedAt)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $provider,
                $partnerID,
                $discountPercent,
                $description,
                $effectiveDate,
                $expiresAt,
                $isActive
            ],
            // 📌 MySQLi type string:
            // s = provider, i = partnerID, d = discountPercent,
            // s = description, s = effectiveDate, s = expiresAt, i = isActive
            'sidsssi'
        );

        // 🔑 Get the auto-generated discountID for the newly inserted record
        // @see https://www.php.net/manual/en/mysqli.insert-id.php
        $newDiscountID = Database::getLastInsertId();

        // 📝 Log the creation in the activity log
        $userInfo = $GLOBALS['sessionManager']->getUserInfo();
        ActivityLogger::log(
            (int) $userInfo['userID'],                                  // 👤 User who created the record
            'provider_discount_created',                                 // 📌 Activity type
            'payment',                                                   // 📂 Category
            'info',                                                      // ℹ️ Severity level
            'Provider discount created: ' . $provider . ' (' . $discountPercent . '%' . ($partnerID ? ' for partner ' . $partnerID : ' global') . ')', // 📝 Description
            [                                                            // 📊 Metadata
                'discountID'      => $newDiscountID,
                'provider'        => $provider,
                'partnerID'       => $partnerID,
                'discountPercent' => $discountPercent,
            ]
        );

        // ✅ Return success response for insert operation
        echo json_encode([
            'success'    => true,
            'message'    => ucfirst($provider) . ' discount created successfully.',
            'discountID' => $newDiscountID,
        ]);
    }
}


// ============================================================================
// 🗑️ HANDLER: delete_provider_discount
// ============================================================================

/**
 * 🗑️ Soft-delete (deactivate) a provider discount
 *
 * Rather than permanently removing the record from tblProviderDiscounts,
 * this handler sets isActive = 0 and updates the updatedAt timestamp.
 * This preserves the audit trail and allows for potential reactivation.
 *
 * Required POST fields:
 *   - discountID (int): The ID of the discount to deactivate
 *   - csrf_token (string): CSRF token for validation
 *
 * @param array|null $input JSON-decoded request body from php://input
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If discountID is missing or record not found
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php (Prepared Statements)
 */
function handleDeleteProviderDiscount(?array $input): void
{
    // ✅ Validate discountID — required and must be a positive integer
    $discountID = isset($input['discountID']) ? (int) $input['discountID'] : 0;
    if ($discountID <= 0) {
        throw new Exception('A valid discount ID is required for deactivation.');
    }

    // 🔍 Verify the discount record exists before attempting to deactivate
    // This provides a more informative error message than a silent no-op
    $existing = Database::fetchOne(
        "SELECT discountID, provider, partnerID, discountPercent
         FROM tblProviderDiscounts
         WHERE discountID = ?",
        [$discountID],
        'i'
    );
    if (!$existing) {
        throw new Exception('Discount record with ID ' . $discountID . ' not found.');
    }

    // 🗑️ Soft-delete: Set isActive = 0 instead of DELETE
    // This preserves the record for audit trail and potential reactivation
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    Database::query(
        "UPDATE tblProviderDiscounts
         SET isActive = 0, updatedAt = NOW()
         WHERE discountID = ?",
        [$discountID],
        'i'
    );

    // 📝 Log the deactivation in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],                                      // 👤 User who deactivated the record
        'provider_discount_deleted',                                     // 📌 Activity type
        'payment',                                                       // 📂 Category
        'warning',                                                       // ⚠️ Severity level (destructive action)
        'Provider discount deactivated: ' . ($existing['provider'] ?? 'unknown') . ' (ID: ' . $discountID . ')', // 📝 Description
        [                                                                // 📊 Metadata
            'discountID'      => $discountID,
            'provider'        => $existing['provider'] ?? '',
            'partnerID'       => $existing['partnerID'] ?? null,
            'discountPercent' => $existing['discountPercent'] ?? 0,
        ]
    );

    // ✅ Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Discount (ID: ' . $discountID . ') has been deactivated successfully.',
    ]);
}


// ============================================================================
// 📋 HANDLER: get_partners_list
// ============================================================================

/**
 * 📋 Retrieve a lightweight list of partners for dropdown population
 *
 * Returns an array of partner objects containing only the partnerID and
 * organizationName. This is used to populate the partner selection dropdown
 * in the "Add Partner Override" modal without loading full partner records.
 *
 * Only active partners (isActive = 1) are returned, sorted alphabetically
 * by organization name for user-friendly dropdown display.
 *
 * @return void Outputs JSON response with partner list
 *
 * @see tblPartners — Partner organizations table
 */
function handleGetPartnersList(): void
{
    // 🗄️ Fetch active partners — only ID and name needed for dropdown
    // Sorted alphabetically for user-friendly display
    $partners = Database::fetchAll(
        "SELECT partnerID, organizationName
         FROM tblPartners
         WHERE isActive = 1
         ORDER BY organizationName ASC",
        [],
        ''
    );

    // ✅ Return the partner list
    echo json_encode([
        'success'  => true,
        'partners' => $partners,
    ]);
}
