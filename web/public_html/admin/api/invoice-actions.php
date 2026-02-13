<?php
/**
 * ============================================================================
 * 🧾 Invoice Management API Endpoint (Super Admin)
 * ============================================================================
 *
 * Purpose: RESTful-style JSON API for the invoice management dashboard.
 * Handles all CRUD operations and state transitions for system invoices.
 *
 * Supported Actions (GET):
 * - get_invoices      — Paginated invoice list with filters (status, search, date range, partnerID)
 * - get_invoice       — Single invoice detail by invoiceID
 * - get_invoice_html  — Rendered HTML invoice for preview (calls InvoiceManager::renderHTML)
 * - get_invoice_stats — Aggregate invoice statistics for dashboards
 * - download_pdf      — Generate and stream PDF download (via InvoiceManager::generatePDF)
 *
 * Supported Actions (POST — requires CSRF token):
 * - mark_paid          — Mark an invoice as paid (invoiceID, transactionReference)
 * - mark_void          — Mark an invoice as void (invoiceID, reason)
 * - reissue_invoice    — Reissue a voided/cancelled invoice (originalInvoiceID)
 * - send_invoice_email — Send or resend an invoice email (invoiceID)
 *
 * Security:
 * - All requests require an authenticated session (SessionManager::isLoggedIn)
 * - All requests require Super Admin role
 * - All POST requests validate a CSRF token (SecurityUtils::verifyCSRFToken)
 * - All user inputs are sanitised and parameterised via MySQLi prepared statements
 *
 * @see https://www.php.net/manual/en/book.mysqli.php     — MySQLi Reference
 * @see https://www.php.net/manual/en/function.json-encode.php — JSON encoding
 * @see https://owasp.org/www-community/attacks/csrf       — CSRF Protection
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Admin\API
 * @version    1.0.0
 * @since      2.4.0-beta
 * ============================================================================
 */

// ============================================================================
// 🔌 BOOTSTRAP — Load core configuration and backend classes
// ============================================================================

/**
 * 📂 Load central configuration (defines SIGNULA_INIT, path constants, etc.)
 * dirname(__DIR__, 3) navigates: api -> admin -> public_html -> web root
 * @see https://www.php.net/manual/en/function.dirname.php
 */
require_once dirname(__DIR__, 3) . '/_config/config.php';

/**
 * 🗄️ Database singleton — MySQLi prepared-statement wrapper
 * @see _backend/Database.php
 */
require_once dirname(__DIR__, 3) . '/_backend/Database.php';

/**
 * 🔐 Session management — login state, CSRF tokens, user info
 * @see _backend/SessionManager.php
 */
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';

/**
 * 🛡️ Access control — role-based guards (requireSuperAdmin, etc.)
 * @see _backend/AccessControl.php
 */
require_once dirname(__DIR__, 3) . '/_backend/AccessControl.php';

/**
 * 🧾 Invoice Manager — Full invoice lifecycle management
 * Located in private_html/payments/ (outside public web root for security)
 * @see private_html/payments/InvoiceManager.php
 */
require_once dirname(__DIR__, 3) . '/private_html/payments/InvoiceManager.php';

// ============================================================================
// 📤 RESPONSE HEADERS — Set JSON content type for all responses
// ============================================================================

/**
 * 📤 All responses from this endpoint are JSON (except PDF downloads)
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
 */
header('Content-Type: application/json');

// ============================================================================
// 🔌 INITIALISE CORE SERVICES
// ============================================================================

/** @var Database $db — Database singleton instance */
$db = Database::getInstance();

/** @var SessionManager $sessionManager — Session handler instance */
$sessionManager = new SessionManager($db);

/** @var AccessControl $accessControl — RBAC guard instance */
$accessControl = new AccessControl($db, $sessionManager);

// ============================================================================
// 🔒 AUTHENTICATION CHECK
// ============================================================================

/**
 * 🔒 Verify the user has an active, authenticated session
 * Returns HTTP 401 Unauthorized if not logged in
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/401
 */
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized — please log in to access this endpoint'
    ]);
    exit;
}

// ============================================================================
// 🛡️ SUPER ADMIN AUTHORISATION CHECK
// ============================================================================

/**
 * 🛡️ Verify the authenticated user has Super Admin privileges
 * Returns HTTP 403 Forbidden if the user is not a Super Admin
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/403
 */
$userInfo = $sessionManager->getUserInfo();
if (!isset($userInfo['isSuperAdmin']) || $userInfo['isSuperAdmin'] != 1) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Super admin access required'
    ]);
    exit;
}

// ============================================================================
// 📥 REQUEST PARSING — Determine action from GET or POST
// ============================================================================

/**
 * 📥 Parse the request body for POST requests (JSON payload)
 * For GET requests, the action is read from the query string
 * @see https://www.php.net/manual/en/wrappers.php.php#wrappers.php.input
 */
$input = json_decode(file_get_contents('php://input'), true);

/**
 * 🎯 Determine the requested action based on HTTP method
 * GET  → action from $_GET['action']
 * POST → action from the JSON request body
 */
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// ============================================================================
// 🛡️ CSRF VALIDATION — Required for all POST requests
// ============================================================================

/**
 * 🛡️ Validate the CSRF token for all POST (state-changing) requests
 * The token can be in the JSON body (csrf_token) or in a custom header (X-CSRF-TOKEN)
 * @see https://owasp.org/www-community/attacks/csrf
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired CSRF token. Please refresh the page.'
        ]);
        exit;
    }
}

// ============================================================================
// 🎯 ACTION ROUTER — Route to the appropriate handler function
// ============================================================================

/**
 * 🎯 Main action router — maps action strings to handler functions
 * Wrapped in a try/catch to ensure all exceptions return clean JSON errors
 * @see https://www.php.net/manual/en/language.exceptions.php
 */
try {
    switch ($action) {

        // ═══════════════════════════════════════════════════════════════
        // 📋 GET ACTIONS — Read-only data retrieval
        // ═══════════════════════════════════════════════════════════════

        case 'get_invoices':
            handleGetInvoices();
            break;

        case 'get_invoice':
            handleGetInvoice();
            break;

        case 'get_invoice_html':
            handleGetInvoiceHtml();
            break;

        case 'get_invoice_stats':
            handleGetInvoiceStats();
            break;

        case 'download_pdf':
            handleDownloadPdf();
            break;

        // ═══════════════════════════════════════════════════════════════
        // 💾 POST ACTIONS — State-changing operations (require CSRF)
        // ═══════════════════════════════════════════════════════════════

        case 'mark_paid':
            handleMarkPaid($input);
            break;

        case 'mark_void':
            handleMarkVoid($input);
            break;

        case 'reissue_invoice':
            handleReissueInvoice($input);
            break;

        case 'send_invoice_email':
            handleSendInvoiceEmail($input);
            break;

        // ═══════════════════════════════════════════════════════════════
        // ❌ UNKNOWN ACTION
        // ═══════════════════════════════════════════════════════════════

        default:
            throw new Exception('Invalid action: ' . ($action ?: '(empty)'));
    }
} catch (Exception $e) {
    /**
     * ❌ Global exception handler — returns a clean JSON error response
     * Uses HTTP 400 Bad Request for all caught exceptions
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/400
     */
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// ============================================================================
// ═══════════════════════════════════════════════════════════════════════════
// 📋 HANDLER FUNCTIONS — Each handles one API action
// ═══════════════════════════════════════════════════════════════════════════
// ============================================================================


/**
 * ============================================================================
 * 📋 GET INVOICES — Paginated list with filters
 * ============================================================================
 *
 * Returns a paginated list of invoices from tblInvoices with optional
 * filtering by status, search keyword, date range, and partner ID.
 *
 * Joins to tblUsers to include user display name and email.
 *
 * Query Parameters:
 * - status    (string)  Filter by invoice status ('all' or specific status)
 * - search    (string)  Search in invoice number, user email, display name, username
 * - dateFrom  (string)  Filter invoices issued on or after this date (YYYY-MM-DD)
 * - dateTo    (string)  Filter invoices issued on or before this date (YYYY-MM-DD)
 * - partnerID (int)     Filter by partner ID (optional)
 * - limit     (int)     Number of results per page (default: 25, max: 100)
 * - offset    (int)     Offset for pagination (default: 0)
 *
 * Response:
 * {
 *   "success": true,
 *   "data": [{ invoiceID, invoiceNumber, status, totalAmount, ... }],
 *   "total": 150,
 *   "pages": 6,
 *   "currentPage": 1
 * }
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/select.html
 */
function handleGetInvoices(): void
{
    // 📥 Read and sanitise filter parameters from the query string
    $status   = trim($_GET['status'] ?? '');
    $search   = trim($_GET['search'] ?? '');
    $dateFrom = trim($_GET['dateFrom'] ?? '');
    $dateTo   = trim($_GET['dateTo'] ?? '');
    $partnerID = isset($_GET['partnerID']) ? (int)$_GET['partnerID'] : 0;

    // 📊 Pagination parameters with sensible defaults and bounds
    $limit  = max(1, min(100, (int)($_GET['limit'] ?? 25)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    // 🧮 Calculate the current page number from offset and limit
    $currentPage = (int)floor($offset / $limit) + 1;

    // ═══ Build the WHERE clause dynamically ═══
    $conditions = [];
    $params     = [];
    $types      = '';

    // 🏷️ Status filter — skip if 'all' or empty
    if ($status !== '' && $status !== 'all') {
        $conditions[] = 'i.status = ?';
        $params[]     = $status;
        $types       .= 's';
    }

    // 🔍 Search filter — matches across invoice number, email, display name, username
    if ($search !== '') {
        $searchLike   = '%' . $search . '%';
        $conditions[] = '(i.invoiceNumber LIKE ? OR u.email LIKE ? OR u.displayName LIKE ? OR u.username LIKE ?)';
        $params[]     = $searchLike;
        $params[]     = $searchLike;
        $params[]     = $searchLike;
        $params[]     = $searchLike;
        $types       .= 'ssss';
    }

    // 📅 Date range: From (inclusive) — filter on issuedAt or createdAt
    if ($dateFrom !== '') {
        $conditions[] = 'DATE(COALESCE(i.issuedAt, i.createdAt)) >= ?';
        $params[]     = $dateFrom;
        $types       .= 's';
    }

    // 📅 Date range: To (inclusive)
    if ($dateTo !== '') {
        $conditions[] = 'DATE(COALESCE(i.issuedAt, i.createdAt)) <= ?';
        $params[]     = $dateTo;
        $types       .= 's';
    }

    // 🏢 Partner filter — optional, for partner-scoped views
    if ($partnerID > 0) {
        $conditions[] = 'i.partnerID = ?';
        $params[]     = $partnerID;
        $types       .= 'i';
    }

    // 📝 Assemble the WHERE clause
    $whereClause = !empty($conditions)
        ? 'WHERE ' . implode(' AND ', $conditions)
        : '';

    // ═══ COUNT QUERY — Get total matching records for pagination ═══

    /**
     * 📊 Count total matching invoices (without LIMIT/OFFSET)
     * Used to calculate total pages for pagination controls
     */
    $countSql = "SELECT COUNT(*) AS total
                 FROM tblInvoices i
                 LEFT JOIN tblUsers u ON i.userID = u.userID
                 {$whereClause}";

    $countResult = Database::fetchOne($countSql, $params, $types);
    $total = $countResult ? (int)$countResult['total'] : 0;

    // 📄 Calculate total pages
    $totalPages = (int)ceil($total / $limit);

    // ═══ DATA QUERY — Get the paginated result set ═══

    /**
     * 📋 Fetch the invoice rows with user info via LEFT JOIN
     * Orders by most recently created first (createdAt DESC)
     * Applies LIMIT and OFFSET for pagination
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/limit-optimization.html
     */
    $dataSql = "SELECT
                    i.invoiceID,
                    i.invoiceNumber,
                    i.userID,
                    i.partnerID,
                    i.invoiceType,
                    i.status,
                    i.currency,
                    i.subtotal,
                    i.discountAmount,
                    i.taxAmount,
                    i.totalAmount,
                    i.dueDate,
                    i.issuedAt,
                    i.paidAt,
                    i.transactionReference,
                    i.notes,
                    i.createdAt,
                    u.email,
                    u.displayName,
                    u.username
                FROM tblInvoices i
                LEFT JOIN tblUsers u ON i.userID = u.userID
                {$whereClause}
                ORDER BY i.createdAt DESC
                LIMIT ?, ?";

    // 📌 Append the LIMIT/OFFSET parameters
    $dataParams = array_merge($params, [$offset, $limit]);
    $dataTypes  = $types . 'ii';

    $invoices = Database::fetchAll($dataSql, $dataParams, $dataTypes);

    // 📤 Return the JSON response
    echo json_encode([
        'success'     => true,
        'data'        => $invoices ?: [],
        'total'       => $total,
        'pages'       => $totalPages,
        'currentPage' => $currentPage,
    ]);
}


/**
 * ============================================================================
 * 📋 GET SINGLE INVOICE — Detailed invoice record by ID
 * ============================================================================
 *
 * Returns a single invoice with all fields, including line items and billing
 * address (JSON fields), plus the associated user's display name and email.
 *
 * Query Parameters:
 * - invoiceID (int) — Required. The ID of the invoice to retrieve.
 *
 * Response:
 * {
 *   "success": true,
 *   "data": { invoiceID, invoiceNumber, lineItems: [...], billingAddress: {...}, ... }
 * }
 *
 * @see InvoiceManager::getInvoice()
 */
function handleGetInvoice(): void
{
    // 📥 Read and validate the invoice ID
    $invoiceID = (int)($_GET['invoiceID'] ?? 0);

    if ($invoiceID <= 0) {
        throw new Exception('A valid invoiceID parameter is required');
    }

    /**
     * 🧾 Fetch the invoice using InvoiceManager
     * The second parameter (userID) is null because Super Admins can view any invoice
     * @see InvoiceManager::getInvoice()
     */
    $invoice = InvoiceManager::getInvoice($invoiceID, null);

    // 🚫 Invoice not found
    if (!$invoice) {
        throw new Exception('Invoice not found (ID: ' . $invoiceID . ')');
    }

    /**
     * 📊 Supplement with user info via a JOIN query
     * InvoiceManager::getInvoice returns raw invoice data; we add user context
     */
    $userInfo = Database::fetchOne(
        "SELECT u.email, u.displayName, u.username
         FROM tblUsers u
         WHERE u.userID = ?",
        [$invoice['userID']],
        'i'
    );

    // 📎 Merge user info into the invoice data
    if ($userInfo) {
        $invoice['email']       = $userInfo['email'];
        $invoice['displayName'] = $userInfo['displayName'];
        $invoice['username']    = $userInfo['username'];
    }

    // 📤 Return the JSON response
    echo json_encode([
        'success' => true,
        'data'    => $invoice,
    ]);
}


/**
 * ============================================================================
 * 🧾 GET INVOICE HTML — Rendered HTML invoice for preview
 * ============================================================================
 *
 * Returns the rendered HTML representation of an invoice, suitable for
 * display in a modal preview or for printing.
 *
 * Query Parameters:
 * - invoiceID (int) — Required. The ID of the invoice to render.
 *
 * Response:
 * {
 *   "success": true,
 *   "html": "<div class='invoice'>...</div>"
 * }
 *
 * @see InvoiceManager::renderHTML()
 */
function handleGetInvoiceHtml(): void
{
    // 📥 Read and validate the invoice ID
    $invoiceID = (int)($_GET['invoiceID'] ?? 0);

    if ($invoiceID <= 0) {
        throw new Exception('A valid invoiceID parameter is required');
    }

    /**
     * 🧾 Render the HTML invoice using InvoiceManager
     * Returns a complete HTML string with Bootstrap-styled invoice layout
     * @see InvoiceManager::renderHTML()
     */
    $html = InvoiceManager::renderHTML($invoiceID);

    // 📤 Return the rendered HTML
    echo json_encode([
        'success' => true,
        'html'    => $html,
    ]);
}


/**
 * ============================================================================
 * 📊 GET INVOICE STATS — Aggregate statistics for dashboard cards
 * ============================================================================
 *
 * Returns aggregated invoice statistics including total counts by status,
 * revenue totals, and period-based metrics.
 *
 * Query Parameters:
 * - days      (int) — Number of days to look back for period stats (default: 30)
 * - partnerID (int) — Optional partner filter
 *
 * Response:
 * {
 *   "success": true,
 *   "data": { totalInvoices, totalPaid, totalOutstanding, overdueCount, ... }
 * }
 *
 * @see InvoiceManager::getInvoiceStats()
 */
function handleGetInvoiceStats(): void
{
    // 📥 Read optional parameters
    $days      = max(1, (int)($_GET['days'] ?? 30));
    $partnerID = isset($_GET['partnerID']) ? (int)$_GET['partnerID'] : null;

    /**
     * 📊 Fetch stats from InvoiceManager
     * Returns an associative array with aggregate totals
     * @see InvoiceManager::getInvoiceStats()
     */
    $stats = InvoiceManager::getInvoiceStats($days, $partnerID);

    // 📤 Return the JSON response
    echo json_encode([
        'success' => true,
        'data'    => $stats,
    ]);
}


/**
 * ============================================================================
 * 📥 DOWNLOAD PDF — Generate and stream a PDF invoice download
 * ============================================================================
 *
 * Generates a PDF of the requested invoice using InvoiceManager::generatePDF
 * (which internally uses TCPDF) and sends it to the browser as a file download.
 *
 * If PDF generation fails (e.g., TCPDF not available), returns a JSON error.
 *
 * Query Parameters:
 * - invoiceID (int) — Required. The ID of the invoice to download.
 *
 * @see InvoiceManager::generatePDF()
 * @see https://tcpdf.org/docs/
 */
function handleDownloadPdf(): void
{
    // 📥 Read and validate the invoice ID
    $invoiceID = (int)($_GET['invoiceID'] ?? 0);

    if ($invoiceID <= 0) {
        throw new Exception('A valid invoiceID parameter is required');
    }

    /**
     * 📄 Generate the PDF using InvoiceManager
     * Returns an array with 'success', 'filePath' or 'pdfContent', and 'filename'
     * @see InvoiceManager::generatePDF()
     */
    $result = InvoiceManager::generatePDF($invoiceID);

    if (!$result['success']) {
        throw new Exception($result['message'] ?? 'Failed to generate PDF');
    }

    /**
     * 📤 Send the PDF as a file download
     * Override the Content-Type header from application/json to application/pdf
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Disposition
     */
    $filename = $result['filename'] ?? ('invoice-' . $invoiceID . '.pdf');

    // 🔄 Override the JSON content type set earlier
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    // 📄 Output the PDF content
    if (isset($result['filePath']) && file_exists($result['filePath'])) {
        // 📂 Stream from file path
        readfile($result['filePath']);
    } elseif (isset($result['pdfContent'])) {
        // 📝 Output from memory
        echo $result['pdfContent'];
    } else {
        // ❌ No content available — this should not happen if generatePDF succeeded
        throw new Exception('PDF content not available');
    }

    exit;
}


/**
 * ============================================================================
 * 💰 MARK PAID — Transition an invoice to "paid" status
 * ============================================================================
 *
 * Marks a specified invoice as paid with an optional transaction reference.
 * The invoice must be in 'issued' or 'overdue' status to be marked as paid.
 *
 * Request Body (JSON):
 * {
 *   "action": "mark_paid",
 *   "invoiceID": 123,
 *   "transactionReference": "PAYPAL-TXN-ABC123" (optional),
 *   "csrf_token": "..."
 * }
 *
 * @param array $input — Decoded JSON request body
 * @see InvoiceManager::markPaid()
 */
function handleMarkPaid(array $input): void
{
    // 📥 Read and validate the invoice ID
    $invoiceID = (int)($input['invoiceID'] ?? 0);

    if ($invoiceID <= 0) {
        throw new Exception('A valid invoiceID is required');
    }

    // 📥 Read the optional transaction reference
    $transactionReference = isset($input['transactionReference'])
        ? trim($input['transactionReference'])
        : null;

    /**
     * 💰 Mark the invoice as paid using InvoiceManager
     * This will:
     * 1. Validate the invoice exists and is in a payable state
     * 2. Update the status to 'paid' and set paidAt timestamp
     * 3. Store the transaction reference if provided
     * 4. Log the activity
     * 5. Dispatch webhook events if configured
     *
     * @see InvoiceManager::markPaid()
     */
    $result = InvoiceManager::markPaid($invoiceID, $transactionReference);

    // 📤 Return the result from InvoiceManager
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => $result['message'] ?? 'Invoice marked as paid',
        ]);
    } else {
        throw new Exception($result['message'] ?? 'Failed to mark invoice as paid');
    }
}


/**
 * ============================================================================
 * ❌ MARK VOID — Transition an invoice to "void" status
 * ============================================================================
 *
 * Marks a specified invoice as void with a mandatory reason. The invoice
 * must be in 'draft', 'issued', or 'overdue' status to be voided.
 *
 * Request Body (JSON):
 * {
 *   "action": "mark_void",
 *   "invoiceID": 123,
 *   "reason": "Duplicate invoice issued in error",
 *   "csrf_token": "..."
 * }
 *
 * @param array $input — Decoded JSON request body
 * @see InvoiceManager::markVoid()
 */
function handleMarkVoid(array $input): void
{
    // 📥 Read and validate the invoice ID
    $invoiceID = (int)($input['invoiceID'] ?? 0);

    if ($invoiceID <= 0) {
        throw new Exception('A valid invoiceID is required');
    }

    // 📥 Read and validate the void reason
    $reason = trim($input['reason'] ?? '');

    if (empty($reason)) {
        throw new Exception('A reason is required when voiding an invoice');
    }

    /**
     * 🔐 Get the current admin's user ID for audit trail
     * @var SessionManager $sessionManager
     */
    $adminUserID = (int)($GLOBALS['sessionManager']->getUserID());

    /**
     * ❌ Void the invoice using InvoiceManager
     * This will:
     * 1. Validate the invoice exists and can be voided
     * 2. Update the status to 'void' and record the reason
     * 3. Log the activity with the admin user ID
     * 4. Dispatch webhook events if configured
     *
     * @see InvoiceManager::markVoid()
     */
    $result = InvoiceManager::markVoid($invoiceID, $adminUserID, $reason);

    // 📤 Return the result
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => $result['message'] ?? 'Invoice voided successfully',
        ]);
    } else {
        throw new Exception($result['message'] ?? 'Failed to void invoice');
    }
}


/**
 * ============================================================================
 * 🔄 REISSUE INVOICE — Create a new invoice from a voided/cancelled original
 * ============================================================================
 *
 * Creates a new invoice based on the original (clones line items, billing
 * address, etc.) and optionally voids the original if it is not already void.
 *
 * Request Body (JSON):
 * {
 *   "action": "reissue_invoice",
 *   "originalInvoiceID": 123,
 *   "csrf_token": "..."
 * }
 *
 * @param array $input — Decoded JSON request body
 * @see InvoiceManager::reissueInvoice()
 */
function handleReissueInvoice(array $input): void
{
    // 📥 Read and validate the original invoice ID
    $originalInvoiceID = (int)($input['originalInvoiceID'] ?? 0);

    if ($originalInvoiceID <= 0) {
        throw new Exception('A valid originalInvoiceID is required');
    }

    /**
     * 🔄 Reissue the invoice using InvoiceManager
     * This will:
     * 1. Validate the original invoice exists
     * 2. Void the original if not already void/cancelled
     * 3. Create a new invoice with the same line items and details
     * 4. Assign a new unique invoice number
     * 5. Set the new invoice status to 'issued'
     * 6. Log the activity and dispatch webhooks
     *
     * @see InvoiceManager::reissueInvoice()
     */
    $result = InvoiceManager::reissueInvoice($originalInvoiceID);

    // 📤 Return the result with the new invoice details
    if ($result['success']) {
        echo json_encode([
            'success'          => true,
            'message'          => $result['message'] ?? 'Invoice reissued successfully',
            'newInvoiceID'     => $result['invoiceID'] ?? null,
            'newInvoiceNumber' => $result['invoiceNumber'] ?? null,
        ]);
    } else {
        throw new Exception($result['message'] ?? 'Failed to reissue invoice');
    }
}


/**
 * ============================================================================
 * 📧 SEND INVOICE EMAIL — Dispatch or re-dispatch an invoice email
 * ============================================================================
 *
 * Sends (or resends) the invoice email to the user associated with the invoice.
 * Uses InvoiceManager::sendInvoiceEmail which generates the email content
 * and optional PDF attachment.
 *
 * Request Body (JSON):
 * {
 *   "action": "send_invoice_email",
 *   "invoiceID": 123,
 *   "csrf_token": "..."
 * }
 *
 * @param array $input — Decoded JSON request body
 * @see InvoiceManager::sendInvoiceEmail()
 */
function handleSendInvoiceEmail(array $input): void
{
    // 📥 Read and validate the invoice ID
    $invoiceID = (int)($input['invoiceID'] ?? 0);

    if ($invoiceID <= 0) {
        throw new Exception('A valid invoiceID is required');
    }

    /**
     * 📧 Send the invoice email using InvoiceManager
     * This will:
     * 1. Validate the invoice exists
     * 2. Look up the user's email address
     * 3. Generate the email body with invoice details
     * 4. Optionally attach a PDF version
     * 5. Send via the configured email provider
     * 6. Log the activity
     *
     * The second parameter (recipientEmail) is null to use the invoice's user email
     * @see InvoiceManager::sendInvoiceEmail()
     */
    $result = InvoiceManager::sendInvoiceEmail($invoiceID, null);

    // 📤 Return the result
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => $result['message'] ?? 'Invoice email sent successfully',
        ]);
    } else {
        throw new Exception($result['message'] ?? 'Failed to send invoice email');
    }
}
