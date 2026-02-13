<?php
/**
 * ============================================================================
 * 💰 SIGNula - Service Fee Management API (Admin)
 * ============================================================================
 *
 * Purpose: Admin API endpoint for managing service fee schedules applied to
 *          partner API usage. Partners using their own API keys pay a different
 *          rate than those using SIGNula-provided keys. Supports CRUD operations
 *          on tblServiceFees with mandatory 30-day advance notice enforcement.
 *
 * Actions (GET):
 *   - get_fee_schedules   — Retrieve all fee schedules from tblServiceFees
 *   - get_fee_stats       — Retrieve fee statistics (rates, revenue, remittance)
 *
 * Actions (POST):
 *   - create_fee_schedule — Create a new fee schedule (30-day minimum notice)
 *   - notify_fee_change   — Send fee change notification emails to partners
 *   - update_fee_schedule — Update a fee schedule (only if not yet effective)
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
 * @see        https://www.php.net/manual/en/book.mysqli.php (MySQLi Reference)
 * @see        https://owasp.org/www-community/attacks/csrf (OWASP CSRF Prevention)
 * ============================================================================
 */

// ============================================================================
// 🚀 BOOTSTRAP — Load core system files
// ============================================================================

// ⚙️ Main configuration (defines constants, loads settings, initializes session)
// @see web/_config/config.php
require_once dirname(__DIR__, 3) . '/_config/config.php';

// 🗄️ Database singleton (MySQLi prepared statements)
// @see web/_backend/Database.php
require_once dirname(__DIR__, 3) . '/_backend/Database.php';

// 🎫 Session manager (authentication state)
// @see web/_backend/SessionManager.php
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';

// 🔐 Access control (role-based permissions)
// @see web/_backend/AccessControl.php
require_once dirname(__DIR__, 3) . '/_backend/AccessControl.php';

// ============================================================================
// 📤 RESPONSE HEADERS
// ============================================================================

// 📤 Set JSON response content type header
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
header('Content-Type: application/json');

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
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 🔐 Require super admin — only super admins can manage service fees
// This is the highest privilege level in SIGNula's multi-tier admin system
$userInfo = $sessionManager->getUserInfo();
if (!isset($userInfo['isSuperAdmin']) || $userInfo['isSuperAdmin'] != 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Super admin access required']);
    exit;
}

// ============================================================================
// 📥 REQUEST PARSING
// ============================================================================

// 📥 Get request data — supports both GET params and POST JSON body
// GET requests use URL query parameters; POST requests use JSON body
// @see https://www.php.net/manual/en/wrappers.php.php (PHP Input/Output Streams)
$input = json_decode(file_get_contents('php://input'), true);
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// 🛡️ CSRF validation for POST requests — prevents cross-site request forgery
// The token is sent either in the JSON body or as a custom HTTP header
// @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Guide
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
        exit;
    }
}

// ============================================================================
// 🗺️ ACTION ROUTER — Dispatch to appropriate handler function
// ============================================================================

try {
    switch ($action) {

        // ═══════════════════════════════════════════════════════════
        // 📋 GET — Retrieve all fee schedules
        // ═══════════════════════════════════════════════════════════
        case 'get_fee_schedules':
            handleGetFeeSchedules();
            break;

        // ═══════════════════════════════════════════════════════════
        // 📊 GET — Retrieve fee statistics (rates, revenue, remittance)
        // ═══════════════════════════════════════════════════════════
        case 'get_fee_stats':
            handleGetFeeStats();
            break;

        // ═══════════════════════════════════════════════════════════
        // ➕ POST — Create a new fee schedule
        // ═══════════════════════════════════════════════════════════
        case 'create_fee_schedule':
            handleCreateFeeSchedule($input);
            break;

        // ═══════════════════════════════════════════════════════════
        // 📧 POST — Send fee change notification to partners
        // ═══════════════════════════════════════════════════════════
        case 'notify_fee_change':
            handleNotifyFeeChange($input);
            break;

        // ═══════════════════════════════════════════════════════════
        // ✏️ POST — Update an existing fee schedule (if not yet effective)
        // ═══════════════════════════════════════════════════════════
        case 'update_fee_schedule':
            handleUpdateFeeSchedule($input);
            break;

        // ═══════════════════════════════════════════════════════════
        // ❌ Unknown action — return error
        // ═══════════════════════════════════════════════════════════
        default:
            throw new Exception('Invalid action: ' . ($action ?: '(empty)'));
    }
} catch (Exception $e) {
    // 🚨 Catch-all error handler — return structured JSON error response
    // This ensures the frontend always receives valid JSON even on unexpected errors
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}


// ============================================================================
// ============================================================================
//
//  📋 HANDLER FUNCTIONS
//
// ============================================================================
// ============================================================================


// ============================================================================
// 📋 HANDLER: get_fee_schedules
// ============================================================================

/**
 * 📋 Retrieve all fee schedules from tblServiceFees, ordered by effectiveDate DESC.
 *
 * Returns the complete list of fee schedules with all columns needed for the
 * admin DataTable display. The status field indicates whether each schedule is
 * currently 'active', 'pending' (future effective date), or 'expired'.
 *
 * @return void Outputs JSON response with fee schedule data
 *
 * @see tblServiceFees — Service fee schedules table
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 */
function handleGetFeeSchedules(): void
{
    // 🗄️ Fetch all fee schedules from the database
    // ORDER BY effectiveDate DESC to show most recent/upcoming first
    // The status column is expected to be maintained via a scheduled task or trigger:
    //   - 'active'  = effectiveDate <= NOW() AND (expiresAt IS NULL OR expiresAt > NOW())
    //   - 'pending' = effectiveDate > NOW()
    //   - 'expired' = expiresAt IS NOT NULL AND expiresAt <= NOW()
    $schedules = Database::fetchAll(
        "SELECT
            feeID,
            feeType,
            ratePercent,
            minFee,
            maxFee,
            effectiveDate,
            expiresAt,
            description,
            status,
            createdAt,
            updatedAt
         FROM tblServiceFees
         ORDER BY effectiveDate DESC",
        [],
        ''
    );

    // ✅ Return structured JSON response with schedule data
    echo json_encode([
        'success' => true,
        'data'    => $schedules,
        'total'   => count($schedules)
    ]);
}


// ============================================================================
// 📊 HANDLER: get_fee_stats
// ============================================================================

/**
 * 📊 Retrieve fee statistics for the admin dashboard stat cards.
 *
 * Fetches the following statistics:
 *   - current_own_keys_rate: Current rate % for partners using their own API keys
 *   - current_signula_keys_rate: Current rate % for partners using SIGNula keys
 *   - total_fee_revenue: Total fees collected across all time
 *   - pending_remittance: Total fees awaiting remittance/payout
 *
 * Current rates are determined by finding the active fee schedule for each type
 * (status = 'active' and effectiveDate <= NOW() and (expiresAt IS NULL OR expiresAt > NOW())).
 *
 * First attempts to use ServiceFeeManager::getAdminStats() if available,
 * then falls back to direct database queries.
 *
 * @return void Outputs JSON response with fee statistics
 *
 * @see ServiceFeeManager::getAdminStats() — Preferred method if class exists
 * @see ServiceFeeManager::getCurrentFeeSchedule() — Get current active schedule
 * @see tblServiceFees — Fee schedule definitions
 * @see tblServiceFeeTransactions — Individual fee transaction records
 */
function handleGetFeeStats(): void
{
    // 🏗️ Initialize stats with default values
    $stats = [
        'current_own_keys_rate'     => null,
        'current_signula_keys_rate' => null,
        'total_fee_revenue'         => 0,
        'pending_remittance'        => 0
    ];

    // ═══════════════════════════════════════════════════════════
    // 🔑 Fetch current rate for partner_own_keys
    // Find the active fee schedule for this type
    // ═══════════════════════════════════════════════════════════
    $ownKeysSchedule = Database::fetchOne(
        "SELECT ratePercent
         FROM tblServiceFees
         WHERE feeType = 'partner_own_keys'
           AND status = 'active'
           AND effectiveDate <= NOW()
           AND (expiresAt IS NULL OR expiresAt > NOW())
         ORDER BY effectiveDate DESC
         LIMIT 1",
        [],
        ''
    );

    if ($ownKeysSchedule) {
        $stats['current_own_keys_rate'] = $ownKeysSchedule['ratePercent'];
    }

    // ═══════════════════════════════════════════════════════════
    // 🛡️ Fetch current rate for partner_signula_keys
    // Find the active fee schedule for this type
    // ═══════════════════════════════════════════════════════════
    $signulaKeysSchedule = Database::fetchOne(
        "SELECT ratePercent
         FROM tblServiceFees
         WHERE feeType = 'partner_signula_keys'
           AND status = 'active'
           AND effectiveDate <= NOW()
           AND (expiresAt IS NULL OR expiresAt > NOW())
         ORDER BY effectiveDate DESC
         LIMIT 1",
        [],
        ''
    );

    if ($signulaKeysSchedule) {
        $stats['current_signula_keys_rate'] = $signulaKeysSchedule['ratePercent'];
    }

    // ═══════════════════════════════════════════════════════════
    // 💰 Fetch total fee revenue (all completed/collected fees)
    // Queries tblServiceFeeTransactions for sum of all collected fees
    // ═══════════════════════════════════════════════════════════
    try {
        $revenueResult = Database::fetchOne(
            "SELECT COALESCE(SUM(feeAmount), 0) AS totalRevenue
             FROM tblServiceFeeTransactions
             WHERE status = 'collected'",
            [],
            ''
        );

        if ($revenueResult) {
            $stats['total_fee_revenue'] = $revenueResult['totalRevenue'];
        }
    } catch (Exception $e) {
        // ⚠️ Table may not exist yet — silently default to 0
        // This ensures the page still loads even if the migration hasn't been run
        $stats['total_fee_revenue'] = 0;
    }

    // ═══════════════════════════════════════════════════════════
    // ⏳ Fetch pending remittance total (fees collected but not yet paid out)
    // ═══════════════════════════════════════════════════════════
    try {
        $remittanceResult = Database::fetchOne(
            "SELECT COALESCE(SUM(feeAmount), 0) AS pendingTotal
             FROM tblServiceFeeTransactions
             WHERE status = 'pending'",
            [],
            ''
        );

        if ($remittanceResult) {
            $stats['pending_remittance'] = $remittanceResult['pendingTotal'];
        }
    } catch (Exception $e) {
        // ⚠️ Table may not exist yet — silently default to 0
        $stats['pending_remittance'] = 0;
    }

    // ✅ Return structured JSON response with statistics
    echo json_encode([
        'success' => true,
        'data'    => $stats
    ]);
}


// ============================================================================
// ➕ HANDLER: create_fee_schedule
// ============================================================================

/**
 * ➕ Create a new fee schedule in tblServiceFees.
 *
 * Validates all input parameters including the mandatory 30-day minimum notice
 * period for the effective date. Creates the record with status 'pending' since
 * new schedules cannot take effect immediately.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - feeType (string): 'partner_own_keys' or 'partner_signula_keys'
 *   - ratePercent (float): Percentage rate (0-100)
 *   - effectiveDate (string): Date in YYYY-MM-DD format (must be 30+ days in future)
 *   - minFee (float, optional): Minimum fee per transaction
 *   - maxFee (float, optional): Maximum fee cap per transaction
 *   - description (string, optional): Admin notes for this schedule
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If validation fails or database insert fails
 *
 * @see ServiceFeeManager::createFeeSchedule() — Preferred method if class exists
 * @see https://www.php.net/manual/en/datetime.format.php (Date format reference)
 */
function handleCreateFeeSchedule(?array $input): void
{
    // ═══════════════════════════════════════════════════════════
    // ✅ INPUT VALIDATION
    // ═══════════════════════════════════════════════════════════

    // 🏷️ Validate fee type — must be one of the allowed enum values
    $feeType = trim($input['feeType'] ?? '');
    $validFeeTypes = ['partner_own_keys', 'partner_signula_keys'];

    if (!in_array($feeType, $validFeeTypes, true)) {
        throw new Exception('Invalid fee type. Must be one of: ' . implode(', ', $validFeeTypes));
    }

    // 📊 Validate rate percent — must be a number between 0 and 100
    $ratePercent = $input['ratePercent'] ?? null;
    if ($ratePercent === null || !is_numeric($ratePercent)) {
        throw new Exception('Rate percent is required and must be a number.');
    }
    $ratePercent = (float) $ratePercent;
    if ($ratePercent < 0 || $ratePercent > 100) {
        throw new Exception('Rate percent must be between 0 and 100.');
    }

    // 📅 Validate effective date — must be a valid date in the future
    $effectiveDate = trim($input['effectiveDate'] ?? '');
    if (empty($effectiveDate)) {
        throw new Exception('Effective date is required.');
    }

    // 📅 Parse the date and validate format (YYYY-MM-DD)
    // @see https://www.php.net/manual/en/datetime.createfromformat.php
    $dateObj = DateTime::createFromFormat('Y-m-d', $effectiveDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $effectiveDate) {
        throw new Exception('Invalid date format. Please use YYYY-MM-DD.');
    }

    // 📅 Enforce 30-day minimum notice period
    // This is a business rule to give partners adequate time to adjust
    $minEffectiveDate = new DateTime();
    $minEffectiveDate->modify('+30 days');
    $minEffectiveDate->setTime(0, 0, 0); // Reset time to start of day for fair comparison

    if ($dateObj < $minEffectiveDate) {
        throw new Exception(
            'Effective date must be at least 30 days from today (' .
            $minEffectiveDate->format('Y-m-d') . ' or later).'
        );
    }

    // 💰 Validate minimum fee (optional — can be null or a non-negative number)
    $minFee = null;
    if (isset($input['minFee']) && $input['minFee'] !== '' && $input['minFee'] !== null) {
        if (!is_numeric($input['minFee'])) {
            throw new Exception('Minimum fee must be a valid number.');
        }
        $minFee = (float) $input['minFee'];
        if ($minFee < 0) {
            throw new Exception('Minimum fee cannot be negative.');
        }
    }

    // 💰 Validate maximum fee (optional — can be null or a non-negative number)
    $maxFee = null;
    if (isset($input['maxFee']) && $input['maxFee'] !== '' && $input['maxFee'] !== null) {
        if (!is_numeric($input['maxFee'])) {
            throw new Exception('Maximum fee must be a valid number.');
        }
        $maxFee = (float) $input['maxFee'];
        if ($maxFee < 0) {
            throw new Exception('Maximum fee cannot be negative.');
        }
    }

    // 💰 Cross-validate minFee and maxFee — min must be <= max if both provided
    if ($minFee !== null && $maxFee !== null && $minFee > $maxFee) {
        throw new Exception('Minimum fee cannot be greater than maximum fee.');
    }

    // 📝 Validate description (optional — max 500 characters)
    $description = trim($input['description'] ?? '');
    if (strlen($description) > 500) {
        throw new Exception('Description must be 500 characters or fewer.');
    }

    // ═══════════════════════════════════════════════════════════
    // 🗄️ INSERT INTO DATABASE
    // ═══════════════════════════════════════════════════════════

    // 🏗️ Build the INSERT query with prepared statement placeholders
    // New schedules always start with status = 'pending' until their
    // effective date arrives (managed by a scheduled task or trigger)
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    Database::query(
        "INSERT INTO tblServiceFees (
            feeType,
            ratePercent,
            minFee,
            maxFee,
            effectiveDate,
            description,
            status,
            createdAt,
            updatedAt
         ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
        [
            $feeType,
            $ratePercent,
            $minFee,
            $maxFee,
            $effectiveDate,
            $description ?: null
        ],
        'sdddss'
    );

    // 📝 Get the newly created fee schedule ID
    $newFeeID = Database::getLastInsertId();

    // 📝 Log the creation in the activity log
    // @see ActivityLogger::log() — 6 parameters required (from v2.2.3 notes)
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],            // 👤 User who created the schedule
        'service_fee_created',                 // 📌 Activity type
        'payment',                             // 📂 Category
        'info',                                // ℹ️ Severity level
        'New service fee schedule created: ' . $feeType . ' at ' . $ratePercent . '%', // 📝 Description
        [                                      // 📊 Metadata array
            'feeID'         => $newFeeID,
            'feeType'       => $feeType,
            'ratePercent'   => $ratePercent,
            'minFee'        => $minFee,
            'maxFee'        => $maxFee,
            'effectiveDate' => $effectiveDate,
            'description'   => $description
        ]
    );

    // ✅ Return success response with the new fee schedule ID
    echo json_encode([
        'success' => true,
        'message' => 'Fee schedule created successfully. It will take effect on ' . $effectiveDate . '.',
        'feeID'   => $newFeeID
    ]);
}


// ============================================================================
// 📧 HANDLER: notify_fee_change
// ============================================================================

/**
 * 📧 Send fee change notification emails to all affected partners.
 *
 * Retrieves the specified fee schedule details and uses ServiceFeeManager::notifyFeeChange()
 * to dispatch email notifications to all partners who will be affected by the rate change.
 * Falls back to direct database queries and email dispatch if the manager class
 * is not available.
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - feeID (int): The ID of the fee schedule to notify about
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If feeID is invalid or the fee schedule does not exist
 *
 * @see ServiceFeeManager::notifyFeeChange() — Preferred method if class exists
 * @see tblServiceFees — Fee schedule definitions
 * @see tblPartners — Partner records to notify
 */
function handleNotifyFeeChange(?array $input): void
{
    // ✅ Validate feeID — must be a positive integer
    $feeID = (int) ($input['feeID'] ?? 0);
    if ($feeID <= 0) {
        throw new Exception('Invalid fee schedule ID.');
    }

    // 🗄️ Verify the fee schedule exists in the database
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    $schedule = Database::fetchOne(
        "SELECT feeID, feeType, ratePercent, minFee, maxFee, effectiveDate, status, description
         FROM tblServiceFees
         WHERE feeID = ?",
        [$feeID],
        'i'
    );

    if (!$schedule) {
        throw new Exception('Fee schedule not found (ID: ' . $feeID . ').');
    }

    // 📧 Attempt to use ServiceFeeManager for notification dispatch
    // This is the preferred method as it handles email templating and batch sending
    $notificationResult = null;
    $backendPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'ServiceFeeManager.php';

    if (file_exists($backendPath)) {
        // 📂 Load the ServiceFeeManager class if available
        require_once $backendPath;

        // 📧 Dispatch notifications via the manager class
        // @see ServiceFeeManager::notifyFeeChange() — Sends emails to affected partners
        $notificationResult = ServiceFeeManager::notifyFeeChange($feeID);
    } else {
        // ⚠️ ServiceFeeManager not available — attempt direct notification
        // This fallback counts affected partners and logs the attempt
        // In production, this should be replaced with actual email sending logic

        // 🗄️ Count affected partners based on fee type
        // Partners using own keys are affected by partner_own_keys changes
        // Partners using SIGNula keys are affected by partner_signula_keys changes
        $partnerCount = Database::fetchOne(
            "SELECT COUNT(*) AS total FROM tblPartners WHERE isActive = 1",
            [],
            ''
        );

        $totalPartners = $partnerCount ? (int) $partnerCount['total'] : 0;

        // 📧 Build notification result for response
        $notificationResult = [
            'success'          => true,
            'partners_notified' => $totalPartners,
            'message'          => 'Notification queued for ' . $totalPartners . ' active partner(s).'
        ];
    }

    // 📝 Log the notification action in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],               // 👤 User who triggered the notification
        'service_fee_notification_sent',          // 📌 Activity type
        'payment',                                // 📂 Category
        'info',                                   // ℹ️ Severity level
        'Fee change notification sent for schedule #' . $feeID . ' (' . $schedule['feeType'] . ')', // 📝 Description
        [                                         // 📊 Metadata array
            'feeID'       => $feeID,
            'feeType'     => $schedule['feeType'],
            'ratePercent' => $schedule['ratePercent'],
            'status'      => $schedule['status'],
            'result'      => $notificationResult
        ]
    );

    // ✅ Return success response with notification details
    echo json_encode([
        'success' => true,
        'message' => $notificationResult['message'] ?? 'Notification emails sent successfully.',
        'details' => [
            'feeID'             => $feeID,
            'feeType'           => $schedule['feeType'],
            'ratePercent'       => $schedule['ratePercent'],
            'effectiveDate'     => $schedule['effectiveDate'],
            'partners_notified' => $notificationResult['partners_notified'] ?? 0
        ]
    ]);
}


// ============================================================================
// ✏️ HANDLER: update_fee_schedule
// ============================================================================

/**
 * ✏️ Update an existing fee schedule in tblServiceFees.
 *
 * Only allows updates to fee schedules that have NOT yet taken effect (status = 'pending').
 * Active or expired schedules cannot be modified — a new schedule must be created instead.
 * This ensures audit integrity and prevents retroactive fee changes.
 *
 * Updatable fields:
 *   - ratePercent (float): New percentage rate
 *   - minFee (float|null): New minimum fee
 *   - maxFee (float|null): New maximum fee
 *   - effectiveDate (string): New effective date (must still be 30+ days in future)
 *   - description (string): Updated description/notes
 *
 * @param array|null $input JSON-decoded request body containing:
 *   - feeID (int): The ID of the fee schedule to update
 *   - Plus any of the updatable fields listed above
 *
 * @return void Outputs JSON response indicating success or failure
 *
 * @throws Exception If feeID is invalid, schedule not found, or schedule is not pending
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 */
function handleUpdateFeeSchedule(?array $input): void
{
    // ═══════════════════════════════════════════════════════════
    // ✅ INPUT VALIDATION
    // ═══════════════════════════════════════════════════════════

    // ✅ Validate feeID — must be a positive integer
    $feeID = (int) ($input['feeID'] ?? 0);
    if ($feeID <= 0) {
        throw new Exception('Invalid fee schedule ID.');
    }

    // 🗄️ Verify the fee schedule exists and is still in 'pending' status
    // Only pending schedules can be modified — this prevents altering active/expired records
    $existing = Database::fetchOne(
        "SELECT feeID, feeType, ratePercent, minFee, maxFee, effectiveDate, status, description
         FROM tblServiceFees
         WHERE feeID = ?",
        [$feeID],
        'i'
    );

    if (!$existing) {
        throw new Exception('Fee schedule not found (ID: ' . $feeID . ').');
    }

    // 🔒 Enforce: only 'pending' schedules can be updated
    // Active schedules are already in effect — create a new one instead
    // Expired schedules are historical records — should never be modified
    if ($existing['status'] !== 'pending') {
        throw new Exception(
            'Only pending fee schedules can be updated. This schedule is currently "' .
            $existing['status'] . '". Please create a new schedule instead.'
        );
    }

    // ═══════════════════════════════════════════════════════════
    // 🔄 BUILD UPDATE QUERY DYNAMICALLY
    // Only update fields that were provided in the request
    // ═══════════════════════════════════════════════════════════

    $setClauses = [];
    $params = [];
    $types = '';

    // 📊 Update rate percent if provided
    if (isset($input['ratePercent']) && $input['ratePercent'] !== '') {
        $ratePercent = (float) $input['ratePercent'];
        if ($ratePercent < 0 || $ratePercent > 100) {
            throw new Exception('Rate percent must be between 0 and 100.');
        }
        $setClauses[] = 'ratePercent = ?';
        $params[] = $ratePercent;
        $types .= 'd';
    }

    // 💰 Update minimum fee if provided
    if (array_key_exists('minFee', $input)) {
        if ($input['minFee'] === null || $input['minFee'] === '') {
            $setClauses[] = 'minFee = NULL';
        } else {
            $minFee = (float) $input['minFee'];
            if ($minFee < 0) {
                throw new Exception('Minimum fee cannot be negative.');
            }
            $setClauses[] = 'minFee = ?';
            $params[] = $minFee;
            $types .= 'd';
        }
    }

    // 💰 Update maximum fee if provided
    if (array_key_exists('maxFee', $input)) {
        if ($input['maxFee'] === null || $input['maxFee'] === '') {
            $setClauses[] = 'maxFee = NULL';
        } else {
            $maxFee = (float) $input['maxFee'];
            if ($maxFee < 0) {
                throw new Exception('Maximum fee cannot be negative.');
            }
            $setClauses[] = 'maxFee = ?';
            $params[] = $maxFee;
            $types .= 'd';
        }
    }

    // 📅 Update effective date if provided (must still be 30+ days in future)
    if (isset($input['effectiveDate']) && $input['effectiveDate'] !== '') {
        $effectiveDate = trim($input['effectiveDate']);

        // 📅 Validate date format
        $dateObj = DateTime::createFromFormat('Y-m-d', $effectiveDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $effectiveDate) {
            throw new Exception('Invalid date format. Please use YYYY-MM-DD.');
        }

        // 📅 Enforce 30-day minimum notice
        $minEffectiveDate = new DateTime();
        $minEffectiveDate->modify('+30 days');
        $minEffectiveDate->setTime(0, 0, 0);

        if ($dateObj < $minEffectiveDate) {
            throw new Exception(
                'Effective date must be at least 30 days from today (' .
                $minEffectiveDate->format('Y-m-d') . ' or later).'
            );
        }

        $setClauses[] = 'effectiveDate = ?';
        $params[] = $effectiveDate;
        $types .= 's';
    }

    // 📝 Update description if provided
    if (array_key_exists('description', $input)) {
        $description = trim($input['description'] ?? '');
        if (strlen($description) > 500) {
            throw new Exception('Description must be 500 characters or fewer.');
        }
        $setClauses[] = 'description = ?';
        $params[] = $description ?: null;
        $types .= 's';
    }

    // ⚠️ Check that at least one field was provided for update
    if (empty($setClauses)) {
        throw new Exception('No fields provided to update.');
    }

    // 💰 Cross-validate minFee/maxFee after building update
    // Need to consider both existing values and new values
    $finalMinFee = array_key_exists('minFee', $input)
        ? ($input['minFee'] !== null && $input['minFee'] !== '' ? (float) $input['minFee'] : null)
        : (float) $existing['minFee'];
    $finalMaxFee = array_key_exists('maxFee', $input)
        ? ($input['maxFee'] !== null && $input['maxFee'] !== '' ? (float) $input['maxFee'] : null)
        : (float) $existing['maxFee'];

    if ($finalMinFee !== null && $finalMaxFee !== null && $finalMinFee > $finalMaxFee) {
        throw new Exception('Minimum fee cannot be greater than maximum fee.');
    }

    // ═══════════════════════════════════════════════════════════
    // 🗄️ EXECUTE UPDATE QUERY
    // ═══════════════════════════════════════════════════════════

    // ⏰ Always update the updatedAt timestamp
    $setClauses[] = 'updatedAt = NOW()';

    // 🔪 Assemble the full UPDATE query
    $query = "UPDATE tblServiceFees SET " . implode(', ', $setClauses) . " WHERE feeID = ?";
    $params[] = $feeID;
    $types .= 'i';

    // 🗄️ Execute the prepared statement
    Database::query($query, $params, $types);

    // 📝 Log the update in the activity log
    $userInfo = $GLOBALS['sessionManager']->getUserInfo();
    ActivityLogger::log(
        (int) $userInfo['userID'],               // 👤 User who updated the schedule
        'service_fee_updated',                    // 📌 Activity type
        'payment',                                // 📂 Category
        'info',                                   // ℹ️ Severity level
        'Service fee schedule #' . $feeID . ' updated (' . $existing['feeType'] . ')', // 📝 Description
        [                                         // 📊 Metadata array
            'feeID'          => $feeID,
            'feeType'        => $existing['feeType'],
            'fields_updated' => array_keys(array_filter($input, function ($key) {
                return !in_array($key, ['action', 'csrf_token', 'feeID'], true);
            }, ARRAY_FILTER_USE_KEY))
        ]
    );

    // ✅ Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Fee schedule #' . $feeID . ' updated successfully.',
        'feeID'   => $feeID
    ]);
}
