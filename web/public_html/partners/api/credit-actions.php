<?php
/**
 * 💰 Partner Credit Balance API
 *
 * RESTful JSON API endpoint for managing partner credit balances.
 * Provides three actions:
 *
 *   GET  get_balance      — Retrieve partner's current credit balance
 *   GET  get_transactions — Retrieve paginated credit transaction history
 *   POST topup_credits    — Initiate a credit top-up via payment provider
 *
 * All actions require finance role or higher for the specified partner.
 * CSRF token verification is enforced on all POST (state-changing) requests.
 *
 * 🔒 Access: Finance role or higher (finance, support, developer, admin, root-admin, super-admin)
 * @see AccessControl::canViewPartnerFinancials() for GET operations
 * @see AccessControl::canManagePartnerPayments() for POST operations
 *
 * Database tables:
 * @see _database/migrations/012_payment_expansion.sql (tblCreditBalances, tblCreditTransactions)
 *
 * CreditManager dependency:
 * @see /web/private_html/payments/CreditManager.php
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.4.0-beta
 */

// ============================================================================
// 🔧 BOOTSTRAP: Load core configuration and backend classes
// ============================================================================

/**
 * 📦 Load the global configuration file which defines constants,
 * initialises error handling, and loads SecurityUtils.
 * @see /web/_config/config.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

/**
 * 🗄️ Database singleton for MySQLi prepared statement queries.
 * @see /web/_backend/Database.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

/**
 * 🔐 Session management — handles login state, user info, session tokens.
 * @see /web/_backend/SessionManager.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

/**
 * 🛡️ Role-based access control with multi-tier partner permissions.
 * @see /web/_backend/AccessControl.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

/**
 * 📊 Activity logger for audit trail and debugging.
 * @see /web/private_html/utils/ActivityLogger.php
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'ActivityLogger.php';

/**
 * 💳 CreditManager — business logic for credit balance operations.
 * Handles balance lookups, deposits, withdrawals, and payment initiation.
 * Located in private_html (outside web root) for security.
 *
 * @see /web/private_html/payments/CreditManager.php
 */
$creditManagerPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'CreditManager.php';
if (file_exists($creditManagerPath)) {
    require_once $creditManagerPath;
}


// ============================================================================
// 📡 SET RESPONSE HEADERS
// ============================================================================

/**
 * 📋 Set JSON content type for all API responses.
 * Prevents browser MIME sniffing and ensures consistent parsing.
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
 */
header('Content-Type: application/json');

/**
 * 🔒 Security headers for API responses.
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Content-Type-Options
 */
header('X-Content-Type-Options: nosniff');


// ============================================================================
// 🏗️ INITIALISE CORE SERVICES
// ============================================================================

/** @var Database $db Database singleton instance */
$db = Database::getInstance();

/** @var SessionManager $sessionManager Manages user sessions and auth state */
$sessionManager = new SessionManager($db);

/** @var AccessControl $accessControl Multi-tier RBAC for partner permissions */
$accessControl = new AccessControl($db, $sessionManager);

/** @var ActivityLogger $activityLogger Audit trail for credit operations */
$activityLogger = new ActivityLogger($db, $sessionManager);


// ============================================================================
// 🔐 AUTHENTICATION CHECK
// ============================================================================

/**
 * 🚪 Return 401 Unauthorized for unauthenticated API requests.
 * All credit operations require an active user session.
 */
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Please log in to access this resource.'
    ]);
    exit;
}


// ============================================================================
// 📥 REQUEST PARSING
// ============================================================================

/**
 * 📥 Parse the incoming request data.
 *
 * For GET requests: action and parameters come from query string ($_GET).
 * For POST requests: action and parameters come from JSON request body.
 *
 * This dual-mode parsing matches the pattern used by other partner APIs.
 * @see /web/public_html/partners/api/webhook-actions.php (same pattern)
 */
$input = json_decode(file_get_contents('php://input'), true);
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');


// ============================================================================
// 🛡️ CSRF TOKEN VERIFICATION (POST Requests Only)
// ============================================================================

/**
 * 🛡️ Verify CSRF token for all state-changing (POST) requests.
 *
 * Accepts the token from either the JSON body or the X-CSRF-TOKEN header.
 * This prevents cross-site request forgery attacks on credit operations.
 *
 * @see SecurityUtils::verifyCSRFToken()
 * @see https://owasp.org/www-community/attacks/csrf
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Invalid or expired CSRF token. Please refresh the page and try again.'
        ]);
        exit;
    }
}


// ============================================================================
// 🔀 ACTION ROUTER
// ============================================================================

/**
 * 🔀 Route the request to the appropriate handler function.
 *
 * Each handler is responsible for:
 *   1. Extracting and validating its own parameters
 *   2. Checking partner-specific access permissions
 *   3. Executing the business logic
 *   4. Returning a JSON response
 *
 * Unhandled exceptions are caught and returned as error responses.
 */
try {
    switch ($action) {
        // 💰 GET: Retrieve partner's current credit balance
        case 'get_balance':
            handleGetBalance($db, $accessControl);
            break;

        // 📜 GET: Retrieve paginated credit transaction history
        case 'get_transactions':
            handleGetTransactions($db, $accessControl);
            break;

        // ➕ POST: Initiate credit top-up via payment provider
        case 'topup_credits':
            handleTopUpCredits($input, $db, $accessControl, $sessionManager, $activityLogger);
            break;

        // ❌ Invalid action
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action. Supported actions: get_balance, get_transactions, topup_credits'
            ]);
            break;
    }
} catch (Exception $e) {
    /**
     * 🐛 Global exception handler for unhandled errors.
     * Returns a sanitised error message to the client.
     * In production, detailed error info should be logged, not exposed.
     */
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}


// ============================================================================
// 💰 HANDLER: Get Partner Credit Balance
// ============================================================================

/**
 * 💰 Retrieve the partner's current credit balance.
 *
 * Queries tblCreditBalances for the partner's SIGNula-level balance where:
 *   ownerType = 'partner'
 *   ownerID   = partnerID
 *   partnerID = NULL (SIGNula-level, not scoped to another partner)
 *
 * If no balance record exists, returns balance of 0.00 with GBP currency.
 *
 * Required GET parameters:
 *   - partnerID (int) — The partner whose balance to retrieve
 *
 * Access: canViewPartnerFinancials() — finance role or higher
 *
 * @param Database      $db            Database instance
 * @param AccessControl $accessControl Access control instance
 *
 * @return void Outputs JSON response and exits
 *
 * @see _database/migrations/012_payment_expansion.sql (tblCreditBalances definition)
 */
function handleGetBalance($db, $accessControl)
{
    // 📥 Extract and validate partner ID
    $partnerID = (int)($_GET['partnerID'] ?? 0);

    if ($partnerID <= 0) {
        throw new Exception('Valid partner ID is required.');
    }

    // 🔒 Verify the requesting user has finance-level access to this partner
    if (!$accessControl->canViewPartnerFinancials($partnerID)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Insufficient permissions to view credit balance for this partner.'
        ]);
        exit;
    }

    // 🗄️ Query the credit balance table
    // Uses prepared statement with parameter binding for SQL injection prevention
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    $stmt = $db->prepare("
        SELECT
            balanceID,
            balance,
            currency,
            lastTransactionAt,
            createdAt,
            updatedAt
        FROM tblCreditBalances
        WHERE ownerType = 'partner'
          AND ownerID = ?
          AND partnerID IS NULL
        LIMIT 1
    ");
    $stmt->bind_param('i', $partnerID);
    $stmt->execute();
    $balanceRecord = $stmt->get_result()->fetch_assoc();

    // 📦 Build response with safe defaults for missing balance records
    if ($balanceRecord) {
        echo json_encode([
            'success'  => true,
            'data'     => [
                'balanceID'         => (int)$balanceRecord['balanceID'],
                'balance'           => number_format((float)$balanceRecord['balance'], 2, '.', ''),
                'currency'          => $balanceRecord['currency'],
                'lastTransactionAt' => $balanceRecord['lastTransactionAt'],
                'createdAt'         => $balanceRecord['createdAt'],
                'updatedAt'         => $balanceRecord['updatedAt']
            ]
        ]);
    } else {
        // 💵 No balance record exists yet — return zero balance
        echo json_encode([
            'success'  => true,
            'data'     => [
                'balanceID'         => null,
                'balance'           => '0.00',
                'currency'          => 'GBP',
                'lastTransactionAt' => null,
                'createdAt'         => null,
                'updatedAt'         => null
            ]
        ]);
    }
}


// ============================================================================
// 📜 HANDLER: Get Credit Transaction History
// ============================================================================

/**
 * 📜 Retrieve the partner's credit transaction history with pagination.
 *
 * Queries tblCreditTransactions for all transactions associated with the
 * partner's balance record, ordered by most recent first.
 *
 * Supports pagination via `page` and `limit` GET parameters.
 *
 * Required GET parameters:
 *   - partnerID (int) — The partner whose transactions to retrieve
 *
 * Optional GET parameters:
 *   - page  (int) — Page number (default: 1)
 *   - limit (int) — Items per page (default: 25, max: 100)
 *
 * Access: canViewPartnerFinancials() — finance role or higher
 *
 * @param Database      $db            Database instance
 * @param AccessControl $accessControl Access control instance
 *
 * @return void Outputs JSON response and exits
 *
 * @see _database/migrations/012_payment_expansion.sql (tblCreditTransactions definition)
 */
function handleGetTransactions($db, $accessControl)
{
    // 📥 Extract and validate partner ID
    $partnerID = (int)($_GET['partnerID'] ?? 0);

    if ($partnerID <= 0) {
        throw new Exception('Valid partner ID is required.');
    }

    // 🔒 Verify the requesting user has finance-level access
    if (!$accessControl->canViewPartnerFinancials($partnerID)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Insufficient permissions to view transaction history for this partner.'
        ]);
        exit;
    }

    // 📖 Parse pagination parameters with sensible defaults and limits
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $limit   = min(100, max(1, (int)($_GET['limit'] ?? 25)));
    $offset  = ($page - 1) * $limit;

    // 🗄️ First, find the partner's balance record ID
    $stmt = $db->prepare("
        SELECT balanceID
        FROM tblCreditBalances
        WHERE ownerType = 'partner'
          AND ownerID = ?
          AND partnerID IS NULL
        LIMIT 1
    ");
    $stmt->bind_param('i', $partnerID);
    $stmt->execute();
    $balanceRecord = $stmt->get_result()->fetch_assoc();

    // 📭 If no balance record exists, return empty result set
    if (!$balanceRecord) {
        echo json_encode([
            'success'     => true,
            'data'        => [],
            'total'       => 0,
            'pages'       => 0,
            'currentPage' => 1
        ]);
        return;
    }

    $balanceID = (int)$balanceRecord['balanceID'];

    // 📊 Get total transaction count for pagination metadata
    $stmt = $db->prepare("
        SELECT COUNT(*) AS total
        FROM tblCreditTransactions
        WHERE balanceID = ?
    ");
    $stmt->bind_param('i', $balanceID);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    // 📋 Fetch the requested page of transactions
    // Ordered by createdAt DESC (most recent first) for chronological display
    $stmt = $db->prepare("
        SELECT
            creditTransactionID,
            type,
            amount,
            balanceBefore,
            balanceAfter,
            currency,
            referenceType,
            referenceID,
            description,
            performedBy,
            ipAddress,
            createdAt
        FROM tblCreditTransactions
        WHERE balanceID = ?
        ORDER BY createdAt DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param('iii', $balanceID, $limit, $offset);
    $stmt->execute();
    $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // 📊 Calculate total pages for pagination
    $totalPages = $total > 0 ? (int)ceil($total / $limit) : 0;

    // 📦 Return paginated response
    echo json_encode([
        'success'     => true,
        'data'        => $transactions,
        'total'       => $total,
        'pages'       => $totalPages,
        'currentPage' => $page
    ]);
}


// ============================================================================
// ➕ HANDLER: Top Up Credits
// ============================================================================

/**
 * ➕ Initiate a credit top-up for the partner.
 *
 * This action creates a pending payment record for the top-up amount and
 * returns either:
 *   - A redirect URL to the payment provider (Stripe, PayPal, Coinbase)
 *   - A success response if the payment was processed immediately
 *
 * The actual balance update happens when the payment webhook confirms
 * the transaction was completed (handled by CreditManager).
 *
 * Required POST parameters:
 *   - partnerID     (int)    — The partner to top up
 *   - amount        (float)  — Top-up amount in the balance currency
 *   - paymentMethod (string) — Payment method: 'stripe', 'paypal', or 'crypto'
 *   - csrf_token    (string) — CSRF token for request verification
 *
 * Access: canManagePartnerPayments() — finance role or higher
 *
 * @param array          $input          Parsed JSON request body
 * @param Database       $db             Database instance
 * @param AccessControl  $accessControl  Access control instance
 * @param SessionManager $sessionManager Session manager instance
 * @param ActivityLogger $activityLogger Activity logger instance
 *
 * @return void Outputs JSON response and exits
 *
 * @see _database/migrations/012_payment_expansion.sql (tblCreditBalances, tblCreditTransactions)
 * @see /web/private_html/payments/CreditManager.php
 */
function handleTopUpCredits($input, $db, $accessControl, $sessionManager, $activityLogger)
{
    // ====================================================================
    // 📥 EXTRACT AND VALIDATE INPUT PARAMETERS
    // ====================================================================

    $partnerID     = (int)($input['partnerID'] ?? 0);
    $amount        = (float)($input['amount'] ?? 0);
    $paymentMethod = trim($input['paymentMethod'] ?? '');

    // 🛡️ Validate partner ID
    if ($partnerID <= 0) {
        throw new Exception('Valid partner ID is required.');
    }

    // 🛡️ Validate amount is positive
    if ($amount <= 0) {
        throw new Exception('Top-up amount must be greater than zero.');
    }

    // 🛡️ Validate payment method is one of the supported options
    $validPaymentMethods = ['stripe', 'paypal', 'crypto'];
    if (!in_array($paymentMethod, $validPaymentMethods, true)) {
        throw new Exception('Invalid payment method. Supported methods: ' . implode(', ', $validPaymentMethods));
    }

    // ====================================================================
    // 🔒 ACCESS CONTROL
    // ====================================================================

    /**
     * 🔒 Verify the requesting user can manage payments for this partner.
     * Top-up is a payment operation requiring canManagePartnerPayments().
     * This is a stricter check than canViewPartnerFinancials() used by GET actions.
     */
    if (!$accessControl->canManagePartnerPayments($partnerID)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Insufficient permissions to manage payments for this partner.'
        ]);
        exit;
    }

    // ====================================================================
    // ⚙️ VALIDATE AGAINST CREDIT SYSTEM SETTINGS
    // ====================================================================

    /**
     * 📊 Check if the credit system is enabled globally.
     * @see tblSettings key: payment.credits.enabled
     */
    $stmt = $db->prepare("
        SELECT settingValue
        FROM tblSettings
        WHERE settingKey = 'payment.credits.enabled'
        LIMIT 1
    ");
    $stmt->execute();
    $creditsEnabledResult = $stmt->get_result()->fetch_assoc();
    $creditsEnabled = (int)($creditsEnabledResult['settingValue'] ?? 1);

    if (!$creditsEnabled) {
        throw new Exception('The credit system is currently disabled. Please contact support.');
    }

    /**
     * 📊 Check minimum top-up amount from settings.
     * @see tblSettings key: payment.credits.min_topup
     */
    $stmt = $db->prepare("
        SELECT settingValue
        FROM tblSettings
        WHERE settingKey = 'payment.credits.min_topup'
        LIMIT 1
    ");
    $stmt->execute();
    $minTopupResult = $stmt->get_result()->fetch_assoc();
    $minTopup = (float)($minTopupResult['settingValue'] ?? 10.00);

    if ($amount < $minTopup) {
        throw new Exception(
            'Minimum top-up amount is ' . number_format($minTopup, 2) . '. '
            . 'Please enter an amount of at least ' . number_format($minTopup, 2) . '.'
        );
    }

    /**
     * 📊 Check maximum balance limit from settings.
     * Ensures the top-up would not exceed the configured maximum balance.
     * @see tblSettings key: payment.credits.max_balance
     */
    $stmt = $db->prepare("
        SELECT settingValue
        FROM tblSettings
        WHERE settingKey = 'payment.credits.max_balance'
        LIMIT 1
    ");
    $stmt->execute();
    $maxBalanceResult = $stmt->get_result()->fetch_assoc();
    $maxBalance = (float)($maxBalanceResult['settingValue'] ?? 50000.00);

    // 💰 Get current balance to check against max limit
    $stmt = $db->prepare("
        SELECT balance, currency
        FROM tblCreditBalances
        WHERE ownerType = 'partner'
          AND ownerID = ?
          AND partnerID IS NULL
        LIMIT 1
    ");
    $stmt->bind_param('i', $partnerID);
    $stmt->execute();
    $currentBalanceRecord = $stmt->get_result()->fetch_assoc();

    $currentBalance = (float)($currentBalanceRecord['balance'] ?? 0.00);
    $currency = $currentBalanceRecord['currency'] ?? 'GBP';

    // 🛡️ Check that the top-up would not exceed the maximum balance
    if (($currentBalance + $amount) > $maxBalance) {
        $maxTopUp = $maxBalance - $currentBalance;
        throw new Exception(
            'This top-up would exceed the maximum balance of '
            . $currency . ' ' . number_format($maxBalance, 2) . '. '
            . 'Maximum top-up allowed: ' . $currency . ' ' . number_format(max(0, $maxTopUp), 2) . '.'
        );
    }

    // ====================================================================
    // 🏢 VERIFY PARTNER EXISTS
    // ====================================================================

    /**
     * 🩹 B-074 fix (found while wiring the CreditManager call below): the
     * REAL tblPartners column for the display/legal name is `partnerName`,
     * NOT `companyName` (grep + information_schema-verified against
     * migration 012 — `companyName` does not exist at all). `status`
     * (ENUM: pending/active/suspended/rejected) IS a real column and was
     * already correct — only `companyName` was the phantom column. This
     * SELECT previously threw an "Unknown column" mysqli_sql_exception on
     * every request, BEFORE the handler ever reached the
     * CreditManager::initiateTopUp() call further down — the whole top-up
     * action fataled at this line first.
     */
    $stmt = $db->prepare("SELECT partnerID, partnerName, status FROM tblPartners WHERE partnerID = ?");
    $stmt->bind_param('i', $partnerID);
    $stmt->execute();
    $partnerRecord = $stmt->get_result()->fetch_assoc();

    if (!$partnerRecord) {
        throw new Exception('Partner organization not found.');
    }

    // 🛡️ Check partner is in active status
    if ($partnerRecord['status'] !== 'active') {
        throw new Exception('Credit top-up is not available for partners with ' . $partnerRecord['status'] . ' status.');
    }

    // ====================================================================
    // 💳 DELEGATE TO CREDIT MANAGER (if available)
    // ====================================================================

    /**
     * 💳 If CreditManager class is available, delegate the payment initiation.
     *
     * CreditManager handles:
     *   - Creating the pending payment record in tblPayments
     *   - Generating the payment provider session/URL (Stripe Checkout, PayPal Order, Coinbase Charge)
     *   - Returning a redirect URL for the user to complete payment
     *
     * If CreditManager is not yet implemented, we create a pending record manually
     * as a placeholder for the payment flow.
     *
     * 🩹 B-074 fix: `CreditManager::initiateTopUp()` DOES NOT EXIST (grep +
     * reflection-verified — CreditManager only implements deposit/withdraw/
     * payFromCredits/refundToCredits/adjustBalance/transfer/getBalance).
     * Calling it fataled with "Call to undefined method" on every request —
     * and since CreditManager.php DOES exist and load successfully, the
     * `class_exists('CreditManager')` check below was ALWAYS true, so the
     * safe fallback branch (which creates a pending tblPayments row with NO
     * live provider call — exactly the "record the intended top-up without
     * a live charge" behaviour this ticket asks for) could never actually
     * run. Rather than inventing a new live-charge path on CreditManager (a
     * shared class used by every other money-moving admin/partner action —
     * more surface area, more risk), the SMALLER, SAFER fix is option (b):
     * gate on `method_exists()` too, so this naturally — and permanently,
     * without a special-case flag — takes the already-written, already-safe
     * fallback path below. The actual balance credit still only ever happens
     * later via CreditManager::deposit(), once a real payment confirmation
     * arrives (per this function's own doc-comment) — never here.
     * @see /web/private_html/payments/CreditManager.php
     */
    $userID = $sessionManager->getUserID();
    $userInfo = $sessionManager->getUserInfo();

    if (class_exists('CreditManager') && method_exists('CreditManager', 'initiateTopUp')) {
        // ✅ Use CreditManager for full payment flow (once a real
        // initiateTopUp() implementation exists on CreditManager).
        $result = CreditManager::initiateTopUp([
            'ownerType'     => 'partner',
            'ownerID'       => $partnerID,
            'amount'        => $amount,
            'currency'      => $currency,
            'paymentMethod' => $paymentMethod,
            'initiatedBy'   => $userID,
            'ipAddress'     => $_SERVER['REMOTE_ADDR'] ?? null,
            'returnUrl'     => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'signulo.id')
                             . '/partners/admin/credits.php?partner=' . $partnerID . '&topup=success',
            'cancelUrl'     => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'signulo.id')
                             . '/partners/admin/credits.php?partner=' . $partnerID . '&topup=cancelled',
        ]);

        if ($result['success']) {
            // 📊 Log the top-up initiation for audit trail
            $activityLogger->log(
                $userID,
                'credit_topup_initiated',
                'finance',
                'info',
                'Credit top-up of ' . $currency . ' ' . number_format($amount, 2) . ' initiated for partner #' . $partnerID,
                [
                    'partnerID'     => $partnerID,
                    'amount'        => $amount,
                    'currency'      => $currency,
                    'paymentMethod' => $paymentMethod,
                    'paymentID'     => $result['paymentID'] ?? null
                ]
            );

            // 📊 Log in admin audit log
            $accessControl->logAdminAction('credit_topup_initiated', 'partner', $partnerID, [
                'amount'        => $amount,
                'currency'      => $currency,
                'paymentMethod' => $paymentMethod
            ]);

            echo json_encode([
                'success'     => true,
                'message'     => 'Credit top-up initiated successfully.',
                'paymentID'   => $result['paymentID'] ?? null,
                'redirectUrl' => $result['redirectUrl'] ?? null
            ]);
        } else {
            throw new Exception($result['message'] ?? 'Failed to initiate credit top-up.');
        }
    } else {
        // ====================================================================
        // 📝 FALLBACK: Create Pending Payment Record Manually
        // ====================================================================

        /**
         * 📝 When CreditManager::initiateTopUp() is not available, create a
         * pending payment record directly — SAFELY, with NO live provider
         * charge (B-074: this is the "smaller, safer" option (b) the ticket
         * asks for, not a live-charge path).
         *
         * The payment record is created with status 'pending' and will be
         * updated to 'completed' (and the credit balance actually deposited,
         * via CreditManager::deposit()) when the payment is confirmed via
         * webhook or manual processing.
         *
         * Invoice number format: SIG-TOPUP-YYYYMMDD-XXXXX
         *
         * 🩹 B-074 fix: the real tblPartners column is `partnerName`, not
         * `companyName` (see the "VERIFY PARTNER EXISTS" fix above). The
         * INSERT below is also fixed to include `netAmount`,
         * `paymentProvider`, and `ipAddress` — all THREE are NOT NULL on the
         * real tblPayments schema with no column default (migration 010/012
         * — information_schema-verified), so this INSERT would have thrown
         * a "Field ... doesn't have a default value" error under MySQL's
         * default STRICT_TRANS_TABLES mode on every real database, even
         * once the CreditManager call above was bypassed.
         * @see _database/migrations/010_webhooks_and_payments.sql (tblPayments definition)
         */
        $invoiceNumber = 'SIG-TOPUP-' . date('Ymd') . '-' . str_pad(random_int(1, 99999), 5, '0', STR_PAD_LEFT);

        $stmt = $db->prepare("
            INSERT INTO tblPayments
                (userID, partnerID, amount, netAmount, currency, paymentMethod,
                 paymentProvider, status, paymentContext, invoiceNumber,
                 description, ipAddress, createdAt)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 'pending', 'signula_direct', ?, ?, ?, NOW())
        ");

        $description = 'Credit top-up for partner: ' . $partnerRecord['partnerName'];
        $ipAddress   = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);

        $stmt->bind_param(
            'iiddssssss',
            $userID,
            $partnerID,
            $amount,
            $amount,
            $currency,
            $paymentMethod,
            $paymentMethod,
            $invoiceNumber,
            $description,
            $ipAddress
        );

        if (!$stmt->execute()) {
            throw new Exception('Failed to create payment record. Please try again.');
        }

        $paymentID = $db->getConnection()->insert_id;

        // 📊 Log the top-up initiation for audit trail
        // ActivityLogger::log(userID, activityType, category, severity, description, metadata)
        $activityLogger->log(
            $userID,
            'credit_topup_initiated',
            'finance',
            'info',
            'Credit top-up of ' . $currency . ' ' . number_format($amount, 2) . ' initiated for partner #' . $partnerID . ' (pending payment)',
            [
                'partnerID'     => $partnerID,
                'amount'        => $amount,
                'currency'      => $currency,
                'paymentMethod' => $paymentMethod,
                'paymentID'     => $paymentID,
                'invoiceNumber' => $invoiceNumber,
                'note'          => 'CreditManager not available — manual payment record created'
            ]
        );

        // 📊 Log in admin audit log
        $accessControl->logAdminAction('credit_topup_initiated', 'partner', $partnerID, [
            'amount'        => $amount,
            'currency'      => $currency,
            'paymentMethod' => $paymentMethod,
            'paymentID'     => $paymentID,
            'invoiceNumber' => $invoiceNumber
        ]);

        // 📦 Return success with pending status
        // TODO: Replace with actual payment provider redirect URLs once
        //       CreditManager and payment provider integrations are implemented.
        echo json_encode([
            'success'     => true,
            'message'     => 'Credit top-up payment created. Awaiting payment confirmation.',
            'paymentID'   => $paymentID,
            'redirectUrl' => null // 🔗 Will be set by CreditManager when implemented
        ]);
    }
}
