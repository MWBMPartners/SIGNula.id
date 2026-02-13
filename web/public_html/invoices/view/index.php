<?php
/**
 * ============================================================================
 * 🧾 SIGNula - Invoice View Route
 * ============================================================================
 *
 * Purpose: Renders an HTML invoice for viewing in the browser.
 *          Accessible at /invoices/view/?id=123
 *
 * Authentication:
 *   - User must be logged in
 *   - User must own the invoice (userID match) OR be a super admin
 *   - Partners can view invoices for their partnerID
 *
 * @see /web/private_html/payments/InvoiceManager.php — Invoice rendering
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 (used in invoice HTML)
 *
 * PHP Version: 8.3+
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Invoices
 * @version    1.0.0
 * @since      2.4.0-beta
 * ============================================================================
 */

// ============================================================================
// 🚀 BOOTSTRAP
// ============================================================================

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 🔌 Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);


// ============================================================================
// 🔐 AUTHENTICATION
// ============================================================================

// 🔒 User must be logged in
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$userID   = $sessionManager->getUserID();
$userInfo = $sessionManager->getUserInfo();


// ============================================================================
// 📋 INVOICE RETRIEVAL
// ============================================================================

// 📋 Get invoice ID from request
$invoiceID = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$invoiceID || $invoiceID <= 0) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><title>Invalid Invoice</title></head>';
    echo '<body><h1>Invalid Invoice ID</h1><p>Please provide a valid invoice ID.</p></body></html>';
    exit;
}

// 🔌 Load InvoiceManager
$invoiceManagerPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'InvoiceManager.php';

if (!file_exists($invoiceManagerPath)) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>Error</title></head>';
    echo '<body><h1>Invoice system unavailable</h1></body></html>';
    exit;
}

require_once $invoiceManagerPath;

// 📖 Get the invoice record to check ownership
$invoice = InvoiceManager::getInvoice($invoiceID);

if (!$invoice) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Invoice Not Found</title></head>';
    echo '<body><h1>Invoice Not Found</h1><p>The requested invoice does not exist.</p></body></html>';
    exit;
}


// ============================================================================
// 🔒 AUTHORIZATION — Verify ownership or admin access
// ============================================================================

$isSuperAdmin = isset($userInfo['isSuperAdmin']) && $userInfo['isSuperAdmin'] == 1;
$isOwner      = (int)$invoice['userID'] === $userID;
$isPartnerAdmin = false;

// 🏢 Check if user is a partner admin for this invoice's partner
if (!empty($invoice['partnerID'])) {
    $isPartnerAdmin = $accessControl->isPartnerAdmin((int)$invoice['partnerID']);
}

// 🚫 Deny access if not authorized
if (!$isSuperAdmin && !$isOwner && !$isPartnerAdmin) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>Access Denied</title></head>';
    echo '<body><h1>Access Denied</h1><p>You do not have permission to view this invoice.</p></body></html>';
    exit;
}


// ============================================================================
// 🧾 RENDER INVOICE HTML
// ============================================================================

try {
    // 📄 Render the full HTML invoice
    $html = InvoiceManager::renderHTML($invoiceID);

    // 📤 Output the rendered invoice
    echo $html;

} catch (\Exception $e) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>Error</title></head>';
    echo '<body><h1>Error Rendering Invoice</h1>';
    echo '<p>Unable to render the invoice. Please try again later.</p></body></html>';

    // 📝 Log the error
    if (function_exists('logActivity')) {
        logActivity($userID, 'invoice_render_error', 'payment', 'error',
            'Failed to render invoice #' . $invoiceID . ': ' . $e->getMessage(),
            ['invoiceID' => $invoiceID, 'error' => $e->getMessage()]
        );
    }
}
