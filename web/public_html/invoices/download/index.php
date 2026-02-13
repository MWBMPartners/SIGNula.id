<?php
/**
 * ============================================================================
 * 📥 SIGNula - Invoice PDF Download Route
 * ============================================================================
 *
 * Purpose: Generates and downloads an invoice as a PDF file.
 *          Accessible at /invoices/download/?id=123
 *
 * Authentication:
 *   - User must be logged in
 *   - User must own the invoice (userID match) OR be a super admin
 *   - Partners can download invoices for their partnerID
 *
 * PDF Generation:
 *   Uses TCPDF library (if available) via InvoiceManager::generatePDF().
 *   Falls back to HTML rendering if TCPDF is not installed.
 *
 * @see /web/private_html/payments/InvoiceManager.php — PDF generation
 * @see /web/_lib/tcpdf/tcpdf_loader.php — TCPDF library loader
 * @see https://tcpdf.org/docs/ — TCPDF documentation
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

if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$userID   = $sessionManager->getUserID();
$userInfo = $sessionManager->getUserInfo();


// ============================================================================
// 📋 INVOICE RETRIEVAL & AUTHORIZATION
// ============================================================================

$invoiceID = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$invoiceID || $invoiceID <= 0) {
    http_response_code(400);
    header('Content-Type: text/html');
    echo '<h1>Invalid Invoice ID</h1>';
    exit;
}

// 🔌 Load InvoiceManager
$invoiceManagerPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'InvoiceManager.php';

if (!file_exists($invoiceManagerPath)) {
    http_response_code(500);
    header('Content-Type: text/html');
    echo '<h1>Invoice system unavailable</h1>';
    exit;
}

require_once $invoiceManagerPath;

// 📖 Get invoice record for ownership check
$invoice = InvoiceManager::getInvoice($invoiceID);

if (!$invoice) {
    http_response_code(404);
    header('Content-Type: text/html');
    echo '<h1>Invoice Not Found</h1>';
    exit;
}

// 🔒 Authorization — verify ownership or admin access
$isSuperAdmin   = isset($userInfo['isSuperAdmin']) && $userInfo['isSuperAdmin'] == 1;
$isOwner        = (int)$invoice['userID'] === $userID;
$isPartnerAdmin = false;

if (!empty($invoice['partnerID'])) {
    $isPartnerAdmin = $accessControl->isPartnerAdmin((int)$invoice['partnerID']);
}

if (!$isSuperAdmin && !$isOwner && !$isPartnerAdmin) {
    http_response_code(403);
    header('Content-Type: text/html');
    echo '<h1>Access Denied</h1>';
    exit;
}


// ============================================================================
// 📥 PDF GENERATION & DOWNLOAD
// ============================================================================

try {
    // 📄 Generate the PDF via InvoiceManager
    $pdfResult = InvoiceManager::generatePDF($invoiceID);

    if ($pdfResult['success'] ?? false) {
        // ✅ PDF generated successfully
        $pdfPath = $pdfResult['pdfPath'] ?? '';

        if (!empty($pdfPath) && file_exists($pdfPath)) {
            // 📥 Send the PDF as a download
            // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Disposition
            $filename = 'invoice-' . ($invoice['invoiceNumber'] ?? $invoiceID) . '.pdf';

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($pdfPath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');

            // 📤 Stream the file to the browser
            readfile($pdfPath);

            // 📝 Log the download
            if (function_exists('logActivity')) {
                logActivity($userID, 'invoice_downloaded', 'payment', 'info',
                    'Invoice #' . ($invoice['invoiceNumber'] ?? $invoiceID) . ' downloaded as PDF',
                    ['invoiceID' => $invoiceID]
                );
            }

            exit;
        }

        // 📄 PDF generated but returned as content (not file)
        if (!empty($pdfResult['pdfContent'])) {
            $filename = 'invoice-' . ($invoice['invoiceNumber'] ?? $invoiceID) . '.pdf';

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdfResult['pdfContent']));
            header('Cache-Control: private, max-age=0, must-revalidate');

            echo $pdfResult['pdfContent'];
            exit;
        }
    }

    // 🔄 Fallback: TCPDF not available — offer HTML print version
    // @see https://developer.mozilla.org/en-US/docs/Web/CSS/@media/print
    header('Content-Type: text/html');

    $html = InvoiceManager::renderHTML($invoiceID);

    // 📄 Wrap with print-friendly header
    echo '<!DOCTYPE html><html><head>';
    echo '<title>Invoice ' . htmlspecialchars($invoice['invoiceNumber'] ?? $invoiceID) . '</title>';
    echo '<style>@media print { .no-print { display: none !important; } }</style>';
    echo '</head><body>';
    echo '<div class="no-print" style="padding: 15px; background: #fff3cd; border-bottom: 1px solid #ffc107; text-align: center;">';
    echo '<strong>PDF generation is not available.</strong> ';
    echo 'Use your browser\'s print function (Ctrl+P / Cmd+P) and select "Save as PDF" to download this invoice.';
    echo ' <button onclick="window.print()" style="margin-left: 10px; padding: 5px 15px; cursor: pointer;">Print / Save as PDF</button>';
    echo '</div>';
    echo $html;
    echo '</body></html>';

    exit;

} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: text/html');
    echo '<h1>Error Generating Invoice PDF</h1>';
    echo '<p>Unable to generate the invoice PDF. Please try again later.</p>';

    // 📝 Log the error
    if (function_exists('logActivity')) {
        logActivity($userID, 'invoice_download_error', 'payment', 'error',
            'Failed to download invoice #' . $invoiceID . ': ' . $e->getMessage(),
            ['invoiceID' => $invoiceID, 'error' => $e->getMessage()]
        );
    }
}
