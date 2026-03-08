<?php
/**
 * SIGNula - Universal Single Sign-On Authentication System
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package SIGNula
 * @version 2.4.0-beta
 */

/**
 * ============================================================================
 * 🧾 SIGNula - Invoice Manager
 * ============================================================================
 *
 * Purpose: Full invoice lifecycle management for the two-tier payment system
 * PHP Version: 8.3+
 *
 * Features:
 * - Invoice creation with automatic number generation
 * - Line item management with subtotal/discount/tax calculations
 * - PDF generation via TCPDF (graceful fallback if unavailable)
 * - HTML invoice rendering for browser display / print
 * - Invoice email dispatch with optional PDF attachment
 * - Status transitions: draft -> issued -> paid / void / overdue / cancelled
 * - Invoice reissue (clone original, void old)
 * - Paginated retrieval by user or partner
 * - Revenue/status statistics for admin dashboards
 * - Full activity logging for every state change
 * - Webhook event dispatching for third-party integrations
 *
 * Database Table: tblInvoices
 * @see _database/migrations/ for the CREATE TABLE migration
 *
 * @see https://www.php.net/manual/en/function.json-encode.php (JSON encoding for lineItems/billingAddress)
 * @see https://www.php.net/manual/en/function.random-int.php (Cryptographic random for invoice numbers)
 * @see https://tcpdf.org/docs/ (TCPDF PDF generation library)
 * @see https://getbootstrap.com/docs/5.3/ (Bootstrap 5.3 for HTML invoice styling)
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.0.0
 * @since      2.4.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access — all SIGNula private files must be loaded through the bootstrap
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🧾 Invoice Manager Class
 *
 * Static utility class responsible for the complete invoice lifecycle within
 * the SIGNula two-tier payment system. Handles creation, PDF generation,
 * email dispatch, status transitions, reissue, and reporting.
 *
 * All methods are public static — no instantiation required.
 *
 * Usage:
 * ```php
 * // Create an invoice
 * $result = InvoiceManager::createInvoice($userID, 'subscription', $lineItems, ['autoIssue' => true]);
 *
 * // Generate PDF
 * $pdf = InvoiceManager::generatePDF($result['invoiceID']);
 *
 * // Send via email
 * InvoiceManager::sendInvoiceEmail($result['invoiceID']);
 * ```
 */
class InvoiceManager
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /**
     * @var string Default currency code (ISO 4217)
     * @see https://en.wikipedia.org/wiki/ISO_4217
     */
    const DEFAULT_CURRENCY = 'GBP';

    /**
     * @var string Default invoice number prefix
     * Overridden by tblSettings key: payment.invoice_prefix
     */
    const DEFAULT_INVOICE_PREFIX = 'SIG';

    /**
     * @var array Valid invoice types matching the ENUM in tblInvoices
     */
    const INVOICE_TYPES = [
        'subscription',
        'one_time',
        'credit_topup',
        'service_fee',
        'remittance',
        'usage', // 📊 Usage-based billing invoice (added in v2.7.0-beta)
    ];

    /**
     * @var array Valid invoice statuses matching the ENUM in tblInvoices
     */
    const INVOICE_STATUSES = [
        'draft',
        'issued',
        'paid',
        'void',
        'overdue',
        'cancelled',
    ];

    /**
     * @var int Maximum number of random-suffix retries when generating a unique invoice number
     */
    const MAX_INVOICE_NUMBER_RETRIES = 10;

    // ========================================================================
    // 🆕 INVOICE CREATION
    // ========================================================================

    /**
     * 🆕 Create a New Invoice
     *
     * Generates a unique invoice number, calculates financials from line items,
     * applies optional discount and tax, then inserts into tblInvoices.
     *
     * Invoice number format: {PREFIX}-{YYYYMMDD}-{RANDOM5}
     * e.g. SIG-20260213-04821
     *
     * @param int    $userID      The owning user's ID (from tblUsers)
     * @param string $invoiceType One of the INVOICE_TYPES enum values
     * @param array  $lineItems   Array of line item arrays, each containing:
     *                            - 'description' (string) Item description
     *                            - 'quantity'    (int|float) Quantity
     *                            - 'unitPrice'   (float) Price per unit
     *                            - 'total'       (float) Line total (quantity * unitPrice)
     * @param array  $options     Optional parameters:
     *                            - 'partnerID'       (int)    Associated partner ID
     *                            - 'paymentID'       (int)    Linked payment record ID
     *                            - 'subscriptionID'  (int)    Linked subscription record ID
     *                            - 'currency'        (string) ISO 4217 currency code
     *                            - 'discountAmount'  (float)  Flat discount to subtract from subtotal
     *                            - 'taxRate'         (float)  Tax rate as percentage (e.g. 20.00)
     *                            - 'billingName'     (string) Name on the invoice
     *                            - 'billingEmail'    (string) Billing email address
     *                            - 'billingAddress'  (array)  Address array (encoded as JSON)
     *                            - 'companyName'     (string) Company / organisation name
     *                            - 'vatNumber'       (string) VAT / tax registration number
     *                            - 'notes'           (string) Free-text notes
     *                            - 'dueDate'         (string) Due date in Y-m-d format
     *                            - 'autoIssue'       (bool)   Automatically set status to 'issued'
     *
     * @return array ['success' => bool, 'invoiceID' => int, 'invoiceNumber' => string, 'message' => string]
     *
     * @example
     * ```php
     * $result = InvoiceManager::createInvoice(42, 'subscription', [
     *     ['description' => 'Pro Plan (Monthly)', 'quantity' => 1, 'unitPrice' => 29.99, 'total' => 29.99],
     * ], [
     *     'taxRate' => 20.00,
     *     'billingName' => 'Jane Doe',
     *     'billingEmail' => 'jane@example.com',
     *     'autoIssue' => true,
     * ]);
     * ```
     */
    public static function createInvoice(
        int $userID,
        string $invoiceType,
        array $lineItems,
        array $options = []
    ): array {
        try {
            // ─────────────────────────────────────────────────────────────────
            // 🔍 Validate invoice type against allowed ENUM values
            // ─────────────────────────────────────────────────────────────────
            if (!in_array($invoiceType, self::INVOICE_TYPES, true)) {
                return [
                    'success' => false,
                    'invoiceID' => 0,
                    'invoiceNumber' => '',
                    'message' => 'Invalid invoice type: ' . $invoiceType,
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 🔍 Validate line items — at least one required
            // ─────────────────────────────────────────────────────────────────
            if (empty($lineItems)) {
                return [
                    'success' => false,
                    'invoiceID' => 0,
                    'invoiceNumber' => '',
                    'message' => 'At least one line item is required',
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 🔢 Generate a unique invoice number
            // Pattern: {PREFIX}-{YYYYMMDD}-{RANDOM5}
            // Uses getSetting() global function for the configurable prefix
            // @see https://www.php.net/manual/en/function.random-int.php
            // ─────────────────────────────────────────────────────────────────
            $invoicePrefix = getSetting('payment.invoice_prefix', self::DEFAULT_INVOICE_PREFIX);
            $invoiceNumber = '';
            $isUnique = false;
            $retries = 0;

            while (!$isUnique && $retries < self::MAX_INVOICE_NUMBER_RETRIES) {
                // Build candidate number: PREFIX-YYYYMMDD-XXXXX
                $candidateNumber = $invoicePrefix
                    . '-'
                    . date('Ymd')
                    . '-'
                    . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);

                // Check uniqueness against tblInvoices
                $existing = Database::fetchOne(
                    "SELECT invoiceID FROM tblInvoices WHERE invoiceNumber = ?",
                    [$candidateNumber],
                    's'
                );

                if (!$existing) {
                    $invoiceNumber = $candidateNumber;
                    $isUnique = true;
                }

                $retries++;
            }

            // 🚨 If we exhausted retries without finding a unique number, abort
            if (!$isUnique) {
                ActivityLogger::log(
                    $userID,
                    'invoice_error',
                    'payment',
                    'error',
                    'Failed to generate unique invoice number after ' . self::MAX_INVOICE_NUMBER_RETRIES . ' attempts',
                    ['invoiceType' => $invoiceType]
                );

                return [
                    'success' => false,
                    'invoiceID' => 0,
                    'invoiceNumber' => '',
                    'message' => 'Unable to generate a unique invoice number. Please try again.',
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 🧮 Calculate financial totals from line items
            // Subtotal = sum of each line item's 'total' field
            // ─────────────────────────────────────────────────────────────────
            $subtotal = 0.00;

            foreach ($lineItems as &$item) {
                // Ensure each line item has the required fields with sensible defaults
                $item['description'] = $item['description'] ?? 'Item';
                $item['quantity']    = isset($item['quantity']) ? (float)$item['quantity'] : 1;
                $item['unitPrice']   = isset($item['unitPrice']) ? (float)$item['unitPrice'] : 0.00;

                // Calculate line total if not explicitly provided, or validate it
                $calculatedTotal = round($item['quantity'] * $item['unitPrice'], 2);
                $item['total'] = isset($item['total']) ? (float)$item['total'] : $calculatedTotal;

                $subtotal += $item['total'];
            }
            // Release reference
            unset($item);

            // Round subtotal to 2 decimal places
            $subtotal = round($subtotal, 2);

            // ─────────────────────────────────────────────────────────────────
            // 💰 Apply discount
            // ─────────────────────────────────────────────────────────────────
            $discountAmount = isset($options['discountAmount'])
                ? round((float)$options['discountAmount'], 2)
                : 0.00;

            // Discount must not exceed subtotal
            if ($discountAmount > $subtotal) {
                $discountAmount = $subtotal;
            }

            // ─────────────────────────────────────────────────────────────────
            // 🏛️ Calculate tax
            // Tax is applied to (subtotal - discount) to comply with most
            // jurisdictions' VAT/GST rules
            // ─────────────────────────────────────────────────────────────────
            $taxRate = isset($options['taxRate'])
                ? round((float)$options['taxRate'], 2)
                : 0.00;

            $taxableAmount = $subtotal - $discountAmount;
            $taxAmount = ($taxRate > 0)
                ? round($taxableAmount * ($taxRate / 100), 2)
                : 0.00;

            // ─────────────────────────────────────────────────────────────────
            // 🧾 Calculate grand total
            // totalAmount = subtotal - discount + tax
            // ─────────────────────────────────────────────────────────────────
            $totalAmount = round($taxableAmount + $taxAmount, 2);

            // ─────────────────────────────────────────────────────────────────
            // 📦 Extract optional fields with defaults
            // ─────────────────────────────────────────────────────────────────
            $partnerID      = $options['partnerID'] ?? null;
            $paymentID      = $options['paymentID'] ?? null;
            $subscriptionID = $options['subscriptionID'] ?? null;
            $currency       = $options['currency'] ?? self::DEFAULT_CURRENCY;
            $billingName    = $options['billingName'] ?? null;
            $billingEmail   = $options['billingEmail'] ?? null;
            $companyName    = $options['companyName'] ?? null;
            $vatNumber      = $options['vatNumber'] ?? null;
            $notes          = $options['notes'] ?? null;
            $dueDate        = $options['dueDate'] ?? null;
            $autoIssue      = (bool)($options['autoIssue'] ?? false);

            // Encode complex fields as JSON for storage in the database
            // @see https://www.php.net/manual/en/function.json-encode.php
            $lineItemsJson    = json_encode($lineItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $billingAddressJson = isset($options['billingAddress'])
                ? json_encode($options['billingAddress'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;

            // ─────────────────────────────────────────────────────────────────
            // 📋 Determine initial status and issuedAt timestamp
            // If autoIssue is true, mark as 'issued' immediately
            // ─────────────────────────────────────────────────────────────────
            $status   = $autoIssue ? 'issued' : 'draft';
            $issuedAt = $autoIssue ? date('Y-m-d H:i:s') : null;

            // ─────────────────────────────────────────────────────────────────
            // 💾 INSERT into tblInvoices
            // Uses Database::query() with prepared statement parameters
            // @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
            // ─────────────────────────────────────────────────────────────────
            $sql = "INSERT INTO tblInvoices (
                        invoiceNumber, userID, partnerID, paymentID, subscriptionID,
                        invoiceType, status, currency,
                        subtotal, discountAmount, taxRate, taxAmount, totalAmount,
                        lineItems, billingName, billingEmail, billingAddress,
                        companyName, vatNumber, notes,
                        issuedAt, dueDate
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?
                    )";

            $params = [
                $invoiceNumber,       // s — invoiceNumber
                $userID,              // i — userID
                $partnerID,           // i — partnerID (nullable)
                $paymentID,           // i — paymentID (nullable)
                $subscriptionID,      // i — subscriptionID (nullable)
                $invoiceType,         // s — invoiceType
                $status,              // s — status
                $currency,            // s — currency
                $subtotal,            // d — subtotal
                $discountAmount,      // d — discountAmount
                $taxRate,             // d — taxRate
                $taxAmount,           // d — taxAmount
                $totalAmount,         // d — totalAmount
                $lineItemsJson,       // s — lineItems (JSON)
                $billingName,         // s — billingName
                $billingEmail,        // s — billingEmail
                $billingAddressJson,  // s — billingAddress (JSON)
                $companyName,         // s — companyName
                $vatNumber,           // s — vatNumber
                $notes,               // s — notes
                $issuedAt,            // s — issuedAt (nullable datetime string)
                $dueDate,             // s — dueDate (nullable date string)
            ];

            $types = 'siiiisssdddddssssssssss';

            Database::query($sql, $params, $types);

            // Retrieve the auto-incremented invoiceID
            // @see https://www.php.net/manual/en/mysqli.insert-id.php
            $invoiceID = Database::getLastInsertId();

            // ─────────────────────────────────────────────────────────────────
            // 📝 Log invoice creation activity
            // ─────────────────────────────────────────────────────────────────
            ActivityLogger::log(
                $userID,
                'invoice_created',
                'payment',
                'info',
                'Invoice created: ' . $invoiceNumber . ' (' . $invoiceType . ') — '
                    . $currency . ' ' . number_format($totalAmount, 2),
                [
                    'invoiceID'     => $invoiceID,
                    'invoiceNumber' => $invoiceNumber,
                    'invoiceType'   => $invoiceType,
                    'status'        => $status,
                    'subtotal'      => $subtotal,
                    'discount'      => $discountAmount,
                    'taxRate'       => $taxRate,
                    'taxAmount'     => $taxAmount,
                    'totalAmount'   => $totalAmount,
                    'currency'      => $currency,
                    'lineItemCount' => count($lineItems),
                    'partnerID'     => $partnerID,
                    'paymentID'     => $paymentID,
                    'subscriptionID' => $subscriptionID,
                ]
            );

            // ─────────────────────────────────────────────────────────────────
            // 🔗 Dispatch webhook event for third-party integrations
            // ─────────────────────────────────────────────────────────────────
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('invoice.created', [
                    'invoiceID'     => $invoiceID,
                    'invoiceNumber' => $invoiceNumber,
                    'userID'        => $userID,
                    'invoiceType'   => $invoiceType,
                    'status'        => $status,
                    'totalAmount'   => $totalAmount,
                    'currency'      => $currency,
                ]);
            }

            // ─────────────────────────────────────────────────────────────────
            // ✅ Return success result
            // ─────────────────────────────────────────────────────────────────
            return [
                'success'       => true,
                'invoiceID'     => $invoiceID,
                'invoiceNumber' => $invoiceNumber,
                'status'        => $status,
                'totalAmount'   => $totalAmount,
                'message'       => 'Invoice created successfully',
            ];

        } catch (\Exception $e) {
            // 🚨 Log the error and return a safe failure response
            ActivityLogger::log(
                $userID,
                'invoice_error',
                'payment',
                'error',
                'Failed to create invoice: ' . $e->getMessage(),
                [
                    'invoiceType' => $invoiceType,
                    'lineItemCount' => count($lineItems),
                ]
            );

            return [
                'success'       => false,
                'invoiceID'     => 0,
                'invoiceNumber' => '',
                'message'       => 'Failed to create invoice. Please try again.',
            ];
        }
    }

    // ========================================================================
    // 📄 PDF GENERATION
    // ========================================================================

    /**
     * 📄 Generate a PDF for an Invoice
     *
     * Uses the TCPDF library (loaded via the project's TCPDFLoader wrapper)
     * to produce a professional PDF document. The PDF includes:
     * - Company header with logo and address
     * - Invoice metadata (number, date, due date, status)
     * - Billing details (name, email, address, company, VAT number)
     * - Line items table with quantities, unit prices, and totals
     * - Subtotal, discount, tax, and grand total
     * - Notes section
     *
     * The generated PDF is saved to a private directory (not web-accessible)
     * and the path is recorded in tblInvoices.pdfPath.
     *
     * @param int $invoiceID The invoice ID to generate a PDF for
     *
     * @return array ['success' => bool, 'pdfPath' => string, 'message' => string]
     *
     * @see https://tcpdf.org/docs/ (TCPDF documentation)
     * @see https://www.php.net/manual/en/function.mkdir.php (Directory creation)
     *
     * @example
     * ```php
     * $result = InvoiceManager::generatePDF(123);
     * if ($result['success']) {
     *     // PDF saved at $result['pdfPath']
     * }
     * ```
     */
    public static function generatePDF(int $invoiceID): array
    {
        try {
            // ─────────────────────────────────────────────────────────────────
            // 🔍 Fetch the invoice record
            // ─────────────────────────────────────────────────────────────────
            $invoice = self::getInvoice($invoiceID);

            if (!$invoice) {
                return [
                    'success' => false,
                    'pdfPath' => '',
                    'message' => 'Invoice not found',
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 📦 Load TCPDF library via the project's loader wrapper
            // The loader is located in the _lib directory alongside other
            // third-party libraries that are self-hosted (no Composer)
            // @see LIB_DIR constant defined in _config/config.php
            // ─────────────────────────────────────────────────────────────────
            $tcpdfLoaderPath = LIB_DIR . DIRECTORY_SEPARATOR . 'tcpdf' . DIRECTORY_SEPARATOR . 'tcpdf_loader.php';

            if (!file_exists($tcpdfLoaderPath)) {
                // 🚨 TCPDF not installed — return graceful error
                ActivityLogger::log(
                    (int)$invoice['userID'],
                    'invoice_pdf_error',
                    'payment',
                    'warning',
                    'TCPDF loader not found at: ' . $tcpdfLoaderPath,
                    ['invoiceID' => $invoiceID]
                );

                return [
                    'success' => false,
                    'pdfPath' => '',
                    'message' => 'PDF generation library (TCPDF) is not installed. '
                        . 'Please contact the administrator.',
                ];
            }

            // Load the TCPDF loader wrapper
            require_once $tcpdfLoaderPath;

            // ─────────────────────────────────────────────────────────────────
            // 🔍 Check TCPDF availability via the loader's static method
            // ─────────────────────────────────────────────────────────────────
            if (!TCPDFLoader::isAvailable()) {
                ActivityLogger::log(
                    (int)$invoice['userID'],
                    'invoice_pdf_error',
                    'payment',
                    'warning',
                    'TCPDF library is not available (TCPDFLoader::isAvailable() returned false)',
                    ['invoiceID' => $invoiceID]
                );

                return [
                    'success' => false,
                    'pdfPath' => '',
                    'message' => 'PDF generation library is not available. Please contact the administrator.',
                ];
            }

            // Load TCPDF classes
            TCPDFLoader::load();

            // ─────────────────────────────────────────────────────────────────
            // ⚙️ Retrieve company details from tblSettings
            // These are used in the PDF header
            // ─────────────────────────────────────────────────────────────────
            $companyName    = getSetting('company.name', 'MWBM Partners Ltd');
            $companyAddress = getSetting('company.address', '');
            $companyEmail   = getSetting('company.email', '');
            $companyPhone   = getSetting('company.phone', '');
            $companyVAT     = getSetting('company.vat_number', '');
            $companyWebsite = getSetting('company.website', 'https://SIGNula.id');
            $companyLogo    = getSetting('company.logo_path', '');

            // ─────────────────────────────────────────────────────────────────
            // 📐 Create TCPDF instance
            // @see https://tcpdf.org/docs/srcdoc/TCPDF/class-TCPDF.html
            // ─────────────────────────────────────────────────────────────────
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

            // 📄 Set document information
            $pdf->SetCreator('SIGNula Invoice System');
            $pdf->SetAuthor($companyName);
            $pdf->SetTitle('Invoice ' . $invoice['invoiceNumber']);
            $pdf->SetSubject('Invoice');
            $pdf->SetKeywords('invoice, SIGNula, ' . $invoice['invoiceNumber']);

            // 🚫 Remove default header/footer — we build our own
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            // 📏 Set margins (left, top, right)
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(true, 20);

            // Add the first (and typically only) page
            $pdf->AddPage();

            // ─────────────────────────────────────────────────────────────────
            // 🏢 Company Header Section
            // ─────────────────────────────────────────────────────────────────
            $pdf->SetFont('helvetica', 'B', 20);
            $pdf->SetTextColor(44, 62, 80); // Dark blue-grey

            // Company logo (if available)
            if (!empty($companyLogo) && file_exists($companyLogo)) {
                $pdf->Image($companyLogo, 15, 15, 40, 0, '', '', 'T', false, 300, '', false, false, 0);
                $pdf->SetXY(60, 15);
            }

            $pdf->Cell(0, 10, $companyName, 0, 1, 'R');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(100, 100, 100);

            if (!empty($companyAddress)) {
                // Company address may contain line breaks — replace \n with ', '
                $addressOneLine = str_replace(["\r\n", "\r", "\n"], ', ', $companyAddress);
                $pdf->Cell(0, 5, $addressOneLine, 0, 1, 'R');
            }
            if (!empty($companyEmail)) {
                $pdf->Cell(0, 5, $companyEmail, 0, 1, 'R');
            }
            if (!empty($companyPhone)) {
                $pdf->Cell(0, 5, $companyPhone, 0, 1, 'R');
            }
            if (!empty($companyVAT)) {
                $pdf->Cell(0, 5, 'VAT: ' . $companyVAT, 0, 1, 'R');
            }
            if (!empty($companyWebsite)) {
                $pdf->Cell(0, 5, $companyWebsite, 0, 1, 'R');
            }

            // Divider line
            $pdf->Ln(5);
            $pdf->SetDrawColor(200, 200, 200);
            $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
            $pdf->Ln(5);

            // ─────────────────────────────────────────────────────────────────
            // 🧾 Invoice Title and Metadata
            // ─────────────────────────────────────────────────────────────────
            $pdf->SetFont('helvetica', 'B', 24);
            $pdf->SetTextColor(44, 62, 80);
            $pdf->Cell(0, 12, 'INVOICE', 0, 1, 'L');

            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(80, 80, 80);

            // Metadata table (invoice number, date, due date, status)
            $metaY = $pdf->GetY();

            // Left column — Billing To
            $pdf->SetXY(15, $metaY);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(90, 6, 'Bill To:', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);

            if (!empty($invoice['billingName'])) {
                $pdf->Cell(90, 5, $invoice['billingName'], 0, 1, 'L');
            }
            if (!empty($invoice['companyName'])) {
                $pdf->Cell(90, 5, $invoice['companyName'], 0, 1, 'L');
            }
            if (!empty($invoice['billingEmail'])) {
                $pdf->Cell(90, 5, $invoice['billingEmail'], 0, 1, 'L');
            }
            if (!empty($invoice['vatNumber'])) {
                $pdf->Cell(90, 5, 'VAT: ' . $invoice['vatNumber'], 0, 1, 'L');
            }

            // Decode billing address JSON if present
            $billingAddress = null;
            if (!empty($invoice['billingAddress'])) {
                $billingAddress = is_string($invoice['billingAddress'])
                    ? json_decode($invoice['billingAddress'], true)
                    : $invoice['billingAddress'];
            }

            if (is_array($billingAddress)) {
                // Render address fields (line1, line2, city, state/county, postcode, country)
                $addressParts = array_filter([
                    $billingAddress['line1'] ?? '',
                    $billingAddress['line2'] ?? '',
                    trim(($billingAddress['city'] ?? '') . ' ' . ($billingAddress['state'] ?? '')),
                    $billingAddress['postcode'] ?? $billingAddress['zipCode'] ?? '',
                    $billingAddress['country'] ?? '',
                ]);
                foreach ($addressParts as $part) {
                    if (!empty($part)) {
                        $pdf->Cell(90, 5, $part, 0, 1, 'L');
                    }
                }
            }

            $billingEndY = $pdf->GetY();

            // Right column — Invoice details
            $pdf->SetXY(110, $metaY);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(40, 6, 'Invoice Number:', 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(40, 6, $invoice['invoiceNumber'], 0, 1, 'R');

            $pdf->SetX(110);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(40, 6, 'Invoice Date:', 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $issuedDate = $invoice['issuedAt']
                ? date('d M Y', strtotime($invoice['issuedAt']))
                : date('d M Y', strtotime($invoice['createdAt']));
            $pdf->Cell(40, 6, $issuedDate, 0, 1, 'R');

            if (!empty($invoice['dueDate'])) {
                $pdf->SetX(110);
                $pdf->SetFont('helvetica', 'B', 10);
                $pdf->Cell(40, 6, 'Due Date:', 0, 0, 'L');
                $pdf->SetFont('helvetica', '', 10);
                $pdf->Cell(40, 6, date('d M Y', strtotime($invoice['dueDate'])), 0, 1, 'R');
            }

            $pdf->SetX(110);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(40, 6, 'Status:', 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(40, 6, strtoupper($invoice['status']), 0, 1, 'R');

            $pdf->SetX(110);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(40, 6, 'Currency:', 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(40, 6, $invoice['currency'], 0, 1, 'R');

            // Move Y to whichever column is taller
            $detailsEndY = $pdf->GetY();
            $pdf->SetY(max($billingEndY, $detailsEndY) + 8);

            // ─────────────────────────────────────────────────────────────────
            // 📊 Line Items Table
            // ─────────────────────────────────────────────────────────────────
            // Table header
            $pdf->SetFillColor(44, 62, 80);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('helvetica', 'B', 10);

            $pdf->Cell(90, 8, 'Description', 1, 0, 'L', true);
            $pdf->Cell(25, 8, 'Qty', 1, 0, 'C', true);
            $pdf->Cell(30, 8, 'Unit Price', 1, 0, 'R', true);
            $pdf->Cell(35, 8, 'Total', 1, 1, 'R', true);

            // Table rows
            $pdf->SetTextColor(50, 50, 50);
            $pdf->SetFont('helvetica', '', 10);
            $lineItems = is_string($invoice['lineItems'])
                ? json_decode($invoice['lineItems'], true)
                : $invoice['lineItems'];

            $fillToggle = false;

            if (is_array($lineItems)) {
                foreach ($lineItems as $item) {
                    // Alternating row background for readability
                    if ($fillToggle) {
                        $pdf->SetFillColor(245, 245, 245);
                    } else {
                        $pdf->SetFillColor(255, 255, 255);
                    }

                    $description = $item['description'] ?? 'Item';
                    $quantity    = isset($item['quantity']) ? (float)$item['quantity'] : 1;
                    $unitPrice   = isset($item['unitPrice']) ? (float)$item['unitPrice'] : 0.00;
                    $total       = isset($item['total']) ? (float)$item['total'] : ($quantity * $unitPrice);

                    $pdf->Cell(90, 7, $description, 'LR', 0, 'L', true);
                    $pdf->Cell(25, 7, number_format($quantity, 0), 'LR', 0, 'C', true);
                    $pdf->Cell(30, 7, number_format($unitPrice, 2), 'LR', 0, 'R', true);
                    $pdf->Cell(35, 7, number_format($total, 2), 'LR', 1, 'R', true);

                    $fillToggle = !$fillToggle;
                }
            }

            // Close table bottom border
            $pdf->Cell(180, 0, '', 'T', 1);

            // ─────────────────────────────────────────────────────────────────
            // 💰 Totals Section (right-aligned)
            // ─────────────────────────────────────────────────────────────────
            $pdf->Ln(3);
            $pdf->SetFont('helvetica', '', 10);

            // Subtotal
            $pdf->Cell(145, 6, '', 0, 0);
            $pdf->Cell(20, 6, 'Subtotal:', 0, 0, 'R');
            $pdf->Cell(15, 6, number_format((float)$invoice['subtotal'], 2), 0, 1, 'R');

            // Discount (only show if > 0)
            if ((float)$invoice['discountAmount'] > 0) {
                $pdf->Cell(145, 6, '', 0, 0);
                $pdf->Cell(20, 6, 'Discount:', 0, 0, 'R');
                $pdf->Cell(15, 6, '-' . number_format((float)$invoice['discountAmount'], 2), 0, 1, 'R');
            }

            // Tax (only show if rate > 0)
            if ((float)$invoice['taxRate'] > 0) {
                $pdf->Cell(145, 6, '', 0, 0);
                $pdf->Cell(20, 6, 'Tax (' . number_format((float)$invoice['taxRate'], 2) . '%):', 0, 0, 'R');
                $pdf->Cell(15, 6, number_format((float)$invoice['taxAmount'], 2), 0, 1, 'R');
            }

            // Grand total (bold, larger)
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetTextColor(44, 62, 80);
            $pdf->Cell(145, 8, '', 0, 0);
            $pdf->Cell(20, 8, 'TOTAL:', 0, 0, 'R');
            $pdf->Cell(15, 8, $invoice['currency'] . ' ' . number_format((float)$invoice['totalAmount'], 2), 0, 1, 'R');

            // ─────────────────────────────────────────────────────────────────
            // 📝 Notes section (if present)
            // ─────────────────────────────────────────────────────────────────
            if (!empty($invoice['notes'])) {
                $pdf->Ln(10);
                $pdf->SetFont('helvetica', 'B', 10);
                $pdf->SetTextColor(80, 80, 80);
                $pdf->Cell(0, 6, 'Notes:', 0, 1, 'L');
                $pdf->SetFont('helvetica', '', 9);
                $pdf->MultiCell(0, 5, $invoice['notes'], 0, 'L');
            }

            // ─────────────────────────────────────────────────────────────────
            // 📄 Payment status footer
            // ─────────────────────────────────────────────────────────────────
            if ($invoice['status'] === 'paid' && !empty($invoice['paidAt'])) {
                $pdf->Ln(8);
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->SetTextColor(39, 174, 96); // Green
                $pdf->Cell(0, 8, 'PAID on ' . date('d M Y', strtotime($invoice['paidAt'])), 0, 1, 'C');
            } elseif ($invoice['status'] === 'void') {
                $pdf->Ln(8);
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->SetTextColor(192, 57, 43); // Red
                $pdf->Cell(0, 8, 'VOID', 0, 1, 'C');
            }

            // ─────────────────────────────────────────────────────────────────
            // 🔐 Footer with generation timestamp
            // ─────────────────────────────────────────────────────────────────
            $pdf->Ln(10);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(150, 150, 150);
            $pdf->Cell(0, 4, 'Generated by SIGNula Invoice System on ' . date('d M Y H:i:s T'), 0, 1, 'C');
            $pdf->Cell(0, 4, $companyWebsite, 0, 1, 'C');

            // ─────────────────────────────────────────────────────────────────
            // 💾 Save PDF to the private invoices directory
            // Path: PRIVATE_DIR/invoices/YYYY/MM/INVOICENUMBER.pdf
            // @see https://www.php.net/manual/en/function.mkdir.php
            // ─────────────────────────────────────────────────────────────────
            $invoiceDir = PRIVATE_DIR
                . DIRECTORY_SEPARATOR . 'invoices'
                . DIRECTORY_SEPARATOR . date('Y')
                . DIRECTORY_SEPARATOR . date('m');

            // Create directory recursively if it does not exist
            if (!is_dir($invoiceDir)) {
                $dirCreated = mkdir($invoiceDir, 0750, true);
                if (!$dirCreated) {
                    throw new \RuntimeException('Failed to create invoice directory: ' . $invoiceDir);
                }
            }

            $pdfFilename = $invoice['invoiceNumber'] . '.pdf';
            $pdfPath     = $invoiceDir . DIRECTORY_SEPARATOR . $pdfFilename;

            // Output PDF to file (F = save to local file)
            // @see https://tcpdf.org/docs/srcdoc/TCPDF/class-TCPDF.html#_Output
            $pdf->Output($pdfPath, 'F');

            // ─────────────────────────────────────────────────────────────────
            // 💾 Update tblInvoices with the saved PDF path
            // ─────────────────────────────────────────────────────────────────
            Database::query(
                "UPDATE tblInvoices SET pdfPath = ? WHERE invoiceID = ?",
                [$pdfPath, $invoiceID],
                'si'
            );

            // 📝 Log PDF generation
            ActivityLogger::log(
                (int)$invoice['userID'],
                'invoice_pdf_generated',
                'payment',
                'info',
                'PDF generated for invoice: ' . $invoice['invoiceNumber'],
                [
                    'invoiceID' => $invoiceID,
                    'pdfPath'   => $pdfPath,
                ]
            );

            return [
                'success' => true,
                'pdfPath' => $pdfPath,
                'message' => 'PDF generated successfully',
            ];

        } catch (\Exception $e) {
            // 🚨 Log error and return graceful failure
            $userId = isset($invoice) ? (int)($invoice['userID'] ?? 0) : null;

            if ($userId) {
                ActivityLogger::log(
                    $userId,
                    'invoice_pdf_error',
                    'payment',
                    'error',
                    'Failed to generate PDF for invoice ID ' . $invoiceID . ': ' . $e->getMessage(),
                    ['invoiceID' => $invoiceID]
                );
            }

            return [
                'success' => false,
                'pdfPath' => '',
                'message' => 'Failed to generate invoice PDF. Please try again.',
            ];
        }
    }

    // ========================================================================
    // 📧 EMAIL DISPATCH
    // ========================================================================

    /**
     * 📧 Send an Invoice via Email
     *
     * Sends the invoice to the specified recipient (or the invoice's billingEmail)
     * using the 'invoice_issued' email template. If a PDF has been generated,
     * includes it as an attachment via the EmailService queue.
     *
     * @param int         $invoiceID      The invoice ID to send
     * @param string|null $recipientEmail Override recipient email (null = use billingEmail from invoice)
     *
     * @return array ['success' => bool, 'message' => string]
     *
     * @see EmailService::sendTemplateEmail() for template-based email sending
     * @see EmailService::queueEmail() for attachment support
     *
     * @example
     * ```php
     * $result = InvoiceManager::sendInvoiceEmail(123);
     * // or with override:
     * $result = InvoiceManager::sendInvoiceEmail(123, 'override@example.com');
     * ```
     */
    public static function sendInvoiceEmail(int $invoiceID, ?string $recipientEmail = null): array
    {
        try {
            // ─────────────────────────────────────────────────────────────────
            // 🔍 Fetch the invoice record
            // ─────────────────────────────────────────────────────────────────
            $invoice = self::getInvoice($invoiceID);

            if (!$invoice) {
                return [
                    'success' => false,
                    'message' => 'Invoice not found',
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 📬 Determine recipient email address
            // Priority: explicit parameter > invoice billingEmail
            // ─────────────────────────────────────────────────────────────────
            $toEmail = $recipientEmail ?? $invoice['billingEmail'] ?? null;

            if (empty($toEmail)) {
                return [
                    'success' => false,
                    'message' => 'No recipient email address available for this invoice',
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 📦 Build template variables for the 'invoice_issued' template
            // These variables are replaced in the email template body/subject
            // ─────────────────────────────────────────────────────────────────
            $templateVariables = [
                'invoice_number'  => $invoice['invoiceNumber'],
                'invoice_date'    => $invoice['issuedAt']
                    ? date('d M Y', strtotime($invoice['issuedAt']))
                    : date('d M Y', strtotime($invoice['createdAt'])),
                'due_date'        => !empty($invoice['dueDate'])
                    ? date('d M Y', strtotime($invoice['dueDate']))
                    : 'N/A',
                'invoice_type'    => ucfirst(str_replace('_', ' ', $invoice['invoiceType'])),
                'status'          => ucfirst($invoice['status']),
                'currency'        => $invoice['currency'],
                'subtotal'        => number_format((float)$invoice['subtotal'], 2),
                'discount_amount' => number_format((float)$invoice['discountAmount'], 2),
                'tax_rate'        => number_format((float)$invoice['taxRate'], 2),
                'tax_amount'      => number_format((float)$invoice['taxAmount'], 2),
                'total_amount'    => number_format((float)$invoice['totalAmount'], 2),
                'billing_name'    => $invoice['billingName'] ?? '',
                'company_name'    => $invoice['companyName'] ?? '',
                'company_sender'  => getSetting('company.name', 'MWBM Partners Ltd'),
            ];

            // ─────────────────────────────────────────────────────────────────
            // 📎 Check for PDF attachment
            // If a PDF has been generated, we include it as an attachment
            // via the EmailService::queueEmail() attachments parameter
            // ─────────────────────────────────────────────────────────────────
            $attachments = [];

            if (!empty($invoice['pdfPath']) && file_exists($invoice['pdfPath'])) {
                $attachments[] = [
                    'path'     => $invoice['pdfPath'],
                    'name'     => $invoice['invoiceNumber'] . '.pdf',
                    'type'     => 'application/pdf',
                    'encoding' => 'base64',
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 📧 Send via EmailService
            // If we have attachments, we need to use queueEmail directly
            // (sendTemplateEmail does not pass attachments through)
            // Otherwise, use the simpler sendTemplateEmail method
            // ─────────────────────────────────────────────────────────────────
            $emailSent = false;

            if (!empty($attachments)) {
                // Use the lower-level queueEmail to include attachments
                // First, we need to fetch the template and process variables ourselves
                $template = Database::fetchOne(
                    "SELECT * FROM tblEmailTemplates WHERE templateKey = ? AND isActive = TRUE",
                    ['invoice_issued'],
                    's'
                );

                if ($template) {
                    // Replace template variables in subject and body
                    $subject  = $template['subject'];
                    $bodyHTML = $template['bodyHTML'];
                    $bodyText = $template['bodyText'];

                    foreach ($templateVariables as $key => $value) {
                        $placeholder = '{{' . $key . '}}';
                        $subject     = str_replace($placeholder, (string)$value, $subject);
                        $bodyHTML    = str_replace($placeholder, htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'), $bodyHTML);
                        $bodyText    = str_replace($placeholder, (string)$value, $bodyText);
                    }

                    $emailSent = EmailService::queueEmail(
                        $toEmail,                                              // recipientEmail
                        $subject,                                              // subject
                        $bodyHTML,                                             // bodyHTML
                        $bodyText,                                             // bodyText
                        $template['fromEmail'] ?? getSetting('email.from.address'), // fromEmail
                        $template['fromName'] ?? getSetting('email.from.name'),     // fromName
                        $template['replyTo'] ?? null,                          // replyTo
                        (int)$invoice['userID'],                               // userID
                        (int)$template['templateID'],                          // templateID
                        3,                                                     // priority (higher for invoices)
                        null,                                                  // scheduledFor
                        [],                                                    // cc
                        [],                                                    // bcc
                        $attachments                                           // attachments
                    );
                } else {
                    // Fallback: send without template if 'invoice_issued' template not found
                    ActivityLogger::log(
                        (int)$invoice['userID'],
                        'invoice_email_warning',
                        'payment',
                        'warning',
                        'Email template "invoice_issued" not found — sending basic email',
                        ['invoiceID' => $invoiceID]
                    );

                    $emailSent = EmailService::queueEmail(
                        $toEmail,
                        'Invoice ' . $invoice['invoiceNumber'],
                        '<p>Please find your invoice ' . htmlspecialchars($invoice['invoiceNumber'], ENT_QUOTES, 'UTF-8') . ' attached.</p>',
                        'Please find your invoice ' . $invoice['invoiceNumber'] . ' attached.',
                        getSetting('email.from.address', 'noreply@SIGNula.id'),
                        getSetting('email.from.name', 'SIGNula'),
                        null,
                        (int)$invoice['userID'],
                        null,
                        3,
                        null,
                        [],
                        [],
                        $attachments
                    );
                }
            } else {
                // No attachments — use the simpler sendTemplateEmail
                $emailSent = EmailService::sendTemplateEmail(
                    $toEmail,
                    'invoice_issued',
                    $templateVariables,
                    (int)$invoice['userID'],
                    3 // Higher priority for financial emails
                );
            }

            // ─────────────────────────────────────────────────────────────────
            // 💾 Update sentAt timestamp on success
            // ─────────────────────────────────────────────────────────────────
            if ($emailSent) {
                Database::query(
                    "UPDATE tblInvoices SET sentAt = NOW() WHERE invoiceID = ?",
                    [$invoiceID],
                    'i'
                );

                // 📝 Log success
                ActivityLogger::log(
                    (int)$invoice['userID'],
                    'invoice_email_sent',
                    'payment',
                    'info',
                    'Invoice email sent: ' . $invoice['invoiceNumber'] . ' to ' . $toEmail,
                    [
                        'invoiceID'     => $invoiceID,
                        'recipientEmail' => $toEmail,
                        'hasAttachment' => !empty($attachments),
                    ]
                );

                return [
                    'success' => true,
                    'message' => 'Invoice email sent successfully to ' . $toEmail,
                ];
            }

            // 🚨 Email queuing failed
            ActivityLogger::log(
                (int)$invoice['userID'],
                'invoice_email_error',
                'payment',
                'error',
                'Failed to queue invoice email for: ' . $invoice['invoiceNumber'],
                [
                    'invoiceID'      => $invoiceID,
                    'recipientEmail' => $toEmail,
                ]
            );

            return [
                'success' => false,
                'message' => 'Failed to send invoice email. Please try again.',
            ];

        } catch (\Exception $e) {
            // 🚨 Catch-all error handler
            $userId = isset($invoice) ? (int)($invoice['userID'] ?? 0) : null;

            if ($userId) {
                ActivityLogger::log(
                    $userId,
                    'invoice_email_error',
                    'payment',
                    'error',
                    'Exception sending invoice email for ID ' . $invoiceID . ': ' . $e->getMessage(),
                    ['invoiceID' => $invoiceID]
                );
            }

            return [
                'success' => false,
                'message' => 'Failed to send invoice email. Please try again.',
            ];
        }
    }

    // ========================================================================
    // 🌐 HTML RENDERING
    // ========================================================================

    /**
     * 🌐 Render an Invoice as a Standalone HTML Document
     *
     * Generates a complete, self-contained HTML page suitable for:
     * - Browser display (invoice detail view)
     * - Printing (via browser print functionality)
     * - Email body fallback
     *
     * Uses Bootstrap 5.3 classes for professional styling.
     * The HTML is returned as a string — the caller is responsible for
     * outputting it (echo, file_put_contents, etc.).
     *
     * @param int $invoiceID The invoice ID to render
     *
     * @return string Complete HTML document string. Returns empty string if invoice not found.
     *
     * @see https://getbootstrap.com/docs/5.3/ (Bootstrap 5.3 used for styling)
     * @see https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css
     *
     * @example
     * ```php
     * $html = InvoiceManager::renderHTML(123);
     * echo $html; // Output to browser
     * ```
     */
    public static function renderHTML(int $invoiceID): string
    {
        // ─────────────────────────────────────────────────────────────────
        // 🔍 Fetch the invoice record
        // ─────────────────────────────────────────────────────────────────
        $invoice = self::getInvoice($invoiceID);

        if (!$invoice) {
            return '';
        }

        // ─────────────────────────────────────────────────────────────────
        // ⚙️ Company details from settings
        // ─────────────────────────────────────────────────────────────────
        $companyName    = htmlspecialchars(getSetting('company.name', 'MWBM Partners Ltd'), ENT_QUOTES, 'UTF-8');
        $companyAddress = nl2br(htmlspecialchars(getSetting('company.address', ''), ENT_QUOTES, 'UTF-8'));
        $companyEmail   = htmlspecialchars(getSetting('company.email', ''), ENT_QUOTES, 'UTF-8');
        $companyPhone   = htmlspecialchars(getSetting('company.phone', ''), ENT_QUOTES, 'UTF-8');
        $companyVAT     = htmlspecialchars(getSetting('company.vat_number', ''), ENT_QUOTES, 'UTF-8');
        $companyWebsite = htmlspecialchars(getSetting('company.website', 'https://SIGNula.id'), ENT_QUOTES, 'UTF-8');

        // ─────────────────────────────────────────────────────────────────
        // 🎨 Determine status badge colour
        // ─────────────────────────────────────────────────────────────────
        $statusBadgeClass = match ($invoice['status']) {
            'draft'     => 'bg-secondary',
            'issued'    => 'bg-primary',
            'paid'      => 'bg-success',
            'void'      => 'bg-dark',
            'overdue'   => 'bg-danger',
            'cancelled' => 'bg-warning text-dark',
            default     => 'bg-secondary',
        };

        // ─────────────────────────────────────────────────────────────────
        // 📦 Decode line items from JSON
        // ─────────────────────────────────────────────────────────────────
        $lineItems = is_string($invoice['lineItems'])
            ? json_decode($invoice['lineItems'], true)
            : $invoice['lineItems'];

        if (!is_array($lineItems)) {
            $lineItems = [];
        }

        // ─────────────────────────────────────────────────────────────────
        // 📦 Decode billing address from JSON
        // ─────────────────────────────────────────────────────────────────
        $billingAddress = null;
        if (!empty($invoice['billingAddress'])) {
            $billingAddress = is_string($invoice['billingAddress'])
                ? json_decode($invoice['billingAddress'], true)
                : $invoice['billingAddress'];
        }

        // ─────────────────────────────────────────────────────────────────
        // 📅 Format dates for display
        // ─────────────────────────────────────────────────────────────────
        $invoiceDate = $invoice['issuedAt']
            ? date('d M Y', strtotime($invoice['issuedAt']))
            : date('d M Y', strtotime($invoice['createdAt']));

        $dueDateFormatted = !empty($invoice['dueDate'])
            ? date('d M Y', strtotime($invoice['dueDate']))
            : 'N/A';

        $paidDateFormatted = !empty($invoice['paidAt'])
            ? date('d M Y', strtotime($invoice['paidAt']))
            : '';

        // Escape invoice fields for safe HTML output
        // @see https://www.php.net/manual/en/function.htmlspecialchars.php
        $esc = function (?string $val): string {
            return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
        };

        // ─────────────────────────────────────────────────────────────────
        // 📊 Build line items HTML rows
        // ─────────────────────────────────────────────────────────────────
        $lineItemsHTML = '';
        foreach ($lineItems as $item) {
            $desc      = $esc($item['description'] ?? 'Item');
            $qty       = isset($item['quantity']) ? (float)$item['quantity'] : 1;
            $unitPrice = isset($item['unitPrice']) ? (float)$item['unitPrice'] : 0.00;
            $lineTotal = isset($item['total']) ? (float)$item['total'] : ($qty * $unitPrice);

            $lineItemsHTML .= '<tr>'
                . '<td>' . $desc . '</td>'
                . '<td class="text-center">' . number_format($qty, 0) . '</td>'
                . '<td class="text-end">' . number_format($unitPrice, 2) . '</td>'
                . '<td class="text-end">' . number_format($lineTotal, 2) . '</td>'
                . '</tr>' . PHP_EOL;
        }

        // ─────────────────────────────────────────────────────────────────
        // 🏠 Build billing address HTML
        // ─────────────────────────────────────────────────────────────────
        $billingAddressHTML = '';
        if (is_array($billingAddress)) {
            $parts = array_filter([
                $esc($billingAddress['line1'] ?? ''),
                $esc($billingAddress['line2'] ?? ''),
                $esc(trim(($billingAddress['city'] ?? '') . ' ' . ($billingAddress['state'] ?? ''))),
                $esc($billingAddress['postcode'] ?? $billingAddress['zipCode'] ?? ''),
                $esc($billingAddress['country'] ?? ''),
            ]);
            $billingAddressHTML = implode('<br>' . PHP_EOL, $parts);
        }

        // ─────────────────────────────────────────────────────────────────
        // 💰 Prepare totals section rows
        // ─────────────────────────────────────────────────────────────────
        $discountRow = '';
        if ((float)$invoice['discountAmount'] > 0) {
            $discountRow = '<tr>'
                . '<td class="text-end border-0"><strong>Discount:</strong></td>'
                . '<td class="text-end border-0">-' . number_format((float)$invoice['discountAmount'], 2) . '</td>'
                . '</tr>';
        }

        $taxRow = '';
        if ((float)$invoice['taxRate'] > 0) {
            $taxRow = '<tr>'
                . '<td class="text-end border-0"><strong>Tax (' . number_format((float)$invoice['taxRate'], 2) . '%):</strong></td>'
                . '<td class="text-end border-0">' . number_format((float)$invoice['taxAmount'], 2) . '</td>'
                . '</tr>';
        }

        // ─────────────────────────────────────────────────────────────────
        // 📋 Paid / Void status banner
        // ─────────────────────────────────────────────────────────────────
        $statusBanner = '';
        if ($invoice['status'] === 'paid' && !empty($paidDateFormatted)) {
            $statusBanner = '<div class="alert alert-success text-center mt-4" role="alert">'
                . '<strong>PAID</strong> on ' . $esc($paidDateFormatted)
                . '</div>';
        } elseif ($invoice['status'] === 'void') {
            $statusBanner = '<div class="alert alert-dark text-center mt-4" role="alert">'
                . '<strong>VOID</strong>'
                . (!empty($invoice['voidedAt']) ? ' on ' . date('d M Y', strtotime($invoice['voidedAt'])) : '')
                . '</div>';
        } elseif ($invoice['status'] === 'overdue') {
            $statusBanner = '<div class="alert alert-danger text-center mt-4" role="alert">'
                . '<strong>OVERDUE</strong> &mdash; Payment was due on ' . $esc($dueDateFormatted)
                . '</div>';
        }

        // ─────────────────────────────────────────────────────────────────
        // 📝 Notes section
        // ─────────────────────────────────────────────────────────────────
        $notesHTML = '';
        if (!empty($invoice['notes'])) {
            $notesHTML = '<div class="mt-4">'
                . '<h6 class="text-muted">Notes</h6>'
                . '<p class="small text-muted">' . nl2br($esc($invoice['notes'])) . '</p>'
                . '</div>';
        }

        // ─────────────────────────────────────────────────────────────────
        // 🖨️ Build the complete HTML document
        // Uses Bootstrap 5.3.2 from CDN for consistent styling
        // @see https://getbootstrap.com/docs/5.3/
        // ─────────────────────────────────────────────────────────────────
        $html = '<!DOCTYPE html>' . PHP_EOL
            . '<html lang="en">' . PHP_EOL
            . '<head>' . PHP_EOL
            . '    <meta charset="UTF-8">' . PHP_EOL
            . '    <meta name="viewport" content="width=device-width, initial-scale=1.0">' . PHP_EOL
            . '    <title>Invoice ' . $esc($invoice['invoiceNumber']) . ' | SIGNula</title>' . PHP_EOL
            . '    <!-- Bootstrap 5.3.2 CSS (CDN) -->' . PHP_EOL
            . '    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css"' . PHP_EOL
            . '          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"' . PHP_EOL
            . '          crossorigin="anonymous">' . PHP_EOL
            . '    <!-- FontAwesome 6.4.2 CSS (CDN) -->' . PHP_EOL
            . '    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"' . PHP_EOL
            . '          crossorigin="anonymous">' . PHP_EOL
            . '    <style>' . PHP_EOL
            . '        /* 🖨️ Print styles */' . PHP_EOL
            . '        @media print {' . PHP_EOL
            . '            .no-print { display: none !important; }' . PHP_EOL
            . '            body { font-size: 12pt; }' . PHP_EOL
            . '            .container { max-width: 100%; }' . PHP_EOL
            . '        }' . PHP_EOL
            . '        body { background-color: #f8f9fa; }' . PHP_EOL
            . '        .invoice-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }' . PHP_EOL
            . '        .invoice-header { border-bottom: 2px solid #2c3e50; padding-bottom: 1rem; }' . PHP_EOL
            . '        .table-items th { background-color: #2c3e50; color: #fff; }' . PHP_EOL
            . '    </style>' . PHP_EOL
            . '</head>' . PHP_EOL
            . '<body>' . PHP_EOL
            . '<div class="container my-4">' . PHP_EOL
            . '    <!-- 🖨️ Print button (hidden when printing) -->' . PHP_EOL
            . '    <div class="text-end mb-3 no-print">' . PHP_EOL
            . '        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">' . PHP_EOL
            . '            <i class="fa-solid fa-print me-1"></i> Print Invoice' . PHP_EOL
            . '        </button>' . PHP_EOL
            . '    </div>' . PHP_EOL
            . PHP_EOL
            . '    <div class="invoice-card p-4 p-md-5">' . PHP_EOL
            . '        <!-- 🏢 Company Header -->' . PHP_EOL
            . '        <div class="invoice-header mb-4">' . PHP_EOL
            . '            <div class="row">' . PHP_EOL
            . '                <div class="col-md-6">' . PHP_EOL
            . '                    <h2 class="mb-0 text-dark">INVOICE</h2>' . PHP_EOL
            . '                </div>' . PHP_EOL
            . '                <div class="col-md-6 text-md-end">' . PHP_EOL
            . '                    <h4 class="mb-1">' . $companyName . '</h4>' . PHP_EOL
            . (!empty($companyAddress) ? '                    <p class="mb-0 small text-muted">' . $companyAddress . '</p>' . PHP_EOL : '')
            . (!empty($companyEmail) ? '                    <p class="mb-0 small text-muted">' . $companyEmail . '</p>' . PHP_EOL : '')
            . (!empty($companyPhone) ? '                    <p class="mb-0 small text-muted">' . $companyPhone . '</p>' . PHP_EOL : '')
            . (!empty($companyVAT) ? '                    <p class="mb-0 small text-muted">VAT: ' . $companyVAT . '</p>' . PHP_EOL : '')
            . (!empty($companyWebsite) ? '                    <p class="mb-0 small text-muted">' . $companyWebsite . '</p>' . PHP_EOL : '')
            . '                </div>' . PHP_EOL
            . '            </div>' . PHP_EOL
            . '        </div>' . PHP_EOL
            . PHP_EOL
            . '        <!-- 📋 Invoice Details + Billing -->' . PHP_EOL
            . '        <div class="row mb-4">' . PHP_EOL
            . '            <div class="col-md-6">' . PHP_EOL
            . '                <h6 class="text-muted text-uppercase mb-2">Bill To</h6>' . PHP_EOL
            . (!empty($invoice['billingName']) ? '                <p class="mb-0 fw-bold">' . $esc($invoice['billingName']) . '</p>' . PHP_EOL : '')
            . (!empty($invoice['companyName']) ? '                <p class="mb-0">' . $esc($invoice['companyName']) . '</p>' . PHP_EOL : '')
            . (!empty($invoice['billingEmail']) ? '                <p class="mb-0 small">' . $esc($invoice['billingEmail']) . '</p>' . PHP_EOL : '')
            . (!empty($invoice['vatNumber']) ? '                <p class="mb-0 small">VAT: ' . $esc($invoice['vatNumber']) . '</p>' . PHP_EOL : '')
            . (!empty($billingAddressHTML) ? '                <p class="mb-0 small">' . $billingAddressHTML . '</p>' . PHP_EOL : '')
            . '            </div>' . PHP_EOL
            . '            <div class="col-md-6 text-md-end">' . PHP_EOL
            . '                <table class="table table-borderless table-sm ms-auto" style="max-width: 300px;">' . PHP_EOL
            . '                    <tr><td class="text-muted">Invoice #</td><td class="fw-bold">' . $esc($invoice['invoiceNumber']) . '</td></tr>' . PHP_EOL
            . '                    <tr><td class="text-muted">Date</td><td>' . $esc($invoiceDate) . '</td></tr>' . PHP_EOL
            . '                    <tr><td class="text-muted">Due Date</td><td>' . $esc($dueDateFormatted) . '</td></tr>' . PHP_EOL
            . '                    <tr><td class="text-muted">Status</td><td><span class="badge ' . $statusBadgeClass . '">' . $esc(strtoupper($invoice['status'])) . '</span></td></tr>' . PHP_EOL
            . '                    <tr><td class="text-muted">Currency</td><td>' . $esc($invoice['currency']) . '</td></tr>' . PHP_EOL
            . '                </table>' . PHP_EOL
            . '            </div>' . PHP_EOL
            . '        </div>' . PHP_EOL
            . PHP_EOL
            . '        <!-- 📊 Line Items Table -->' . PHP_EOL
            . '        <div class="table-responsive mb-4">' . PHP_EOL
            . '            <table class="table table-items table-striped table-hover align-middle">' . PHP_EOL
            . '                <thead>' . PHP_EOL
            . '                    <tr>' . PHP_EOL
            . '                        <th>Description</th>' . PHP_EOL
            . '                        <th class="text-center" style="width: 80px;">Qty</th>' . PHP_EOL
            . '                        <th class="text-end" style="width: 120px;">Unit Price</th>' . PHP_EOL
            . '                        <th class="text-end" style="width: 120px;">Total</th>' . PHP_EOL
            . '                    </tr>' . PHP_EOL
            . '                </thead>' . PHP_EOL
            . '                <tbody>' . PHP_EOL
            . '                    ' . $lineItemsHTML
            . '                </tbody>' . PHP_EOL
            . '            </table>' . PHP_EOL
            . '        </div>' . PHP_EOL
            . PHP_EOL
            . '        <!-- 💰 Totals -->' . PHP_EOL
            . '        <div class="row justify-content-end">' . PHP_EOL
            . '            <div class="col-md-5">' . PHP_EOL
            . '                <table class="table table-borderless">' . PHP_EOL
            . '                    <tr>' . PHP_EOL
            . '                        <td class="text-end border-0"><strong>Subtotal:</strong></td>' . PHP_EOL
            . '                        <td class="text-end border-0">' . number_format((float)$invoice['subtotal'], 2) . '</td>' . PHP_EOL
            . '                    </tr>' . PHP_EOL
            . $discountRow . PHP_EOL
            . $taxRow . PHP_EOL
            . '                    <tr class="border-top">' . PHP_EOL
            . '                        <td class="text-end border-0"><strong class="fs-5">TOTAL:</strong></td>' . PHP_EOL
            . '                        <td class="text-end border-0"><strong class="fs-5">' . $esc($invoice['currency']) . ' ' . number_format((float)$invoice['totalAmount'], 2) . '</strong></td>' . PHP_EOL
            . '                    </tr>' . PHP_EOL
            . '                </table>' . PHP_EOL
            . '            </div>' . PHP_EOL
            . '        </div>' . PHP_EOL
            . PHP_EOL
            . '        ' . $statusBanner . PHP_EOL
            . '        ' . $notesHTML . PHP_EOL
            . PHP_EOL
            . '        <!-- 🔐 Footer -->' . PHP_EOL
            . '        <div class="text-center mt-5 pt-3 border-top">' . PHP_EOL
            . '            <p class="small text-muted mb-0">Generated by SIGNula Invoice System | ' . $companyWebsite . '</p>' . PHP_EOL
            . '        </div>' . PHP_EOL
            . '    </div>' . PHP_EOL
            . '</div>' . PHP_EOL
            . '<!-- Bootstrap 5.3.2 JS Bundle (CDN) -->' . PHP_EOL
            . '<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"' . PHP_EOL
            . '        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"' . PHP_EOL
            . '        crossorigin="anonymous"></script>' . PHP_EOL
            . '</body>' . PHP_EOL
            . '</html>';

        return $html;
    }

    // ========================================================================
    // 🔍 INVOICE RETRIEVAL
    // ========================================================================

    /**
     * 🔍 Get a Single Invoice by ID
     *
     * Fetches one invoice record from tblInvoices. If a userID is provided,
     * an ownership check is enforced — the invoice must belong to that user.
     *
     * @param int      $invoiceID The invoice ID to retrieve
     * @param int|null $userID    Optional user ID for ownership verification
     *
     * @return array|null The invoice record as an associative array, or null if not found / not authorised
     *
     * @example
     * ```php
     * // Admin access (no ownership check)
     * $invoice = InvoiceManager::getInvoice(123);
     *
     * // User access (enforces ownership)
     * $invoice = InvoiceManager::getInvoice(123, $currentUserID);
     * ```
     */
    public static function getInvoice(int $invoiceID, ?int $userID = null): ?array
    {
        // ─────────────────────────────────────────────────────────────────
        // 📋 Build query — optionally enforce user ownership
        // ─────────────────────────────────────────────────────────────────
        if ($userID !== null) {
            // Ownership check: invoice must belong to the specified user
            return Database::fetchOne(
                "SELECT * FROM tblInvoices WHERE invoiceID = ? AND userID = ?",
                [$invoiceID, $userID],
                'ii'
            );
        }

        // No ownership check — admin/internal use
        return Database::fetchOne(
            "SELECT * FROM tblInvoices WHERE invoiceID = ?",
            [$invoiceID],
            'i'
        );
    }

    /**
     * 📋 Get Paginated Invoices for a User
     *
     * Retrieves a paginated list of invoices belonging to a specific user,
     * ordered by creation date (newest first).
     *
     * @param int $userID The user's ID
     * @param int $limit  Maximum number of records to return (default: 25)
     * @param int $offset Pagination offset (default: 0)
     *
     * @return array Array of invoice records (may be empty)
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/select.html (LIMIT/OFFSET)
     *
     * @example
     * ```php
     * // First page of 25 invoices
     * $invoices = InvoiceManager::getUserInvoices(42);
     *
     * // Page 3, 10 per page
     * $invoices = InvoiceManager::getUserInvoices(42, 10, 20);
     * ```
     */
    public static function getUserInvoices(int $userID, int $limit = 25, int $offset = 0): array
    {
        return Database::fetchAll(
            "SELECT invoiceID, invoiceNumber, invoiceType, status, currency,
                    subtotal, discountAmount, taxRate, taxAmount, totalAmount,
                    billingName, companyName, issuedAt, dueDate, paidAt, createdAt
             FROM tblInvoices
             WHERE userID = ?
             ORDER BY createdAt DESC
             LIMIT ? OFFSET ?",
            [$userID, $limit, $offset],
            'iii'
        );
    }

    /**
     * 🤝 Get Paginated Invoices for a Partner
     *
     * Retrieves a paginated list of invoices associated with a specific partner,
     * ordered by creation date (newest first).
     *
     * @param int $partnerID The partner's ID
     * @param int $limit     Maximum number of records to return (default: 25)
     * @param int $offset    Pagination offset (default: 0)
     *
     * @return array Array of invoice records (may be empty)
     *
     * @example
     * ```php
     * $invoices = InvoiceManager::getPartnerInvoices(5, 50, 0);
     * ```
     */
    public static function getPartnerInvoices(int $partnerID, int $limit = 25, int $offset = 0): array
    {
        return Database::fetchAll(
            "SELECT invoiceID, invoiceNumber, userID, invoiceType, status, currency,
                    subtotal, discountAmount, taxRate, taxAmount, totalAmount,
                    billingName, companyName, issuedAt, dueDate, paidAt, createdAt
             FROM tblInvoices
             WHERE partnerID = ?
             ORDER BY createdAt DESC
             LIMIT ? OFFSET ?",
            [$partnerID, $limit, $offset],
            'iii'
        );
    }

    // ========================================================================
    // ✅ STATUS TRANSITIONS
    // ========================================================================

    /**
     * ✅ Mark an Invoice as Paid
     *
     * Transitions the invoice status to 'paid', records the payment timestamp,
     * and optionally stores an external transaction reference.
     *
     * @param int         $invoiceID            The invoice ID to mark as paid
     * @param string|null $transactionReference Optional external payment transaction reference
     *
     * @return array ['success' => bool, 'message' => string]
     *
     * @example
     * ```php
     * $result = InvoiceManager::markPaid(123, 'PAYPAL-TXN-ABC123');
     * ```
     */
    public static function markPaid(int $invoiceID, ?string $transactionReference = null): array
    {
        try {
            // ─────────────────────────────────────────────────────────────────
            // 🔍 Fetch the invoice and verify it can be marked as paid
            // Only 'draft', 'issued', or 'overdue' invoices can be paid
            // ─────────────────────────────────────────────────────────────────
            $invoice = self::getInvoice($invoiceID);

            if (!$invoice) {
                return ['success' => false, 'message' => 'Invoice not found'];
            }

            $payableStatuses = ['draft', 'issued', 'overdue'];
            if (!in_array($invoice['status'], $payableStatuses, true)) {
                return [
                    'success' => false,
                    'message' => 'Invoice cannot be marked as paid (current status: ' . $invoice['status'] . ')',
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 💾 Update status to 'paid' with timestamp
            // If a transaction reference is provided, append it to notes
            // ─────────────────────────────────────────────────────────────────
            $notesAppend = '';
            if (!empty($transactionReference)) {
                $notesAppend = PHP_EOL . '[Payment Reference: ' . $transactionReference . ']';
            }

            if (!empty($notesAppend)) {
                Database::query(
                    "UPDATE tblInvoices
                     SET status = 'paid', paidAt = NOW(), notes = CONCAT(COALESCE(notes, ''), ?)
                     WHERE invoiceID = ?",
                    [$notesAppend, $invoiceID],
                    'si'
                );
            } else {
                Database::query(
                    "UPDATE tblInvoices SET status = 'paid', paidAt = NOW() WHERE invoiceID = ?",
                    [$invoiceID],
                    'i'
                );
            }

            // 📝 Log the status change
            ActivityLogger::log(
                (int)$invoice['userID'],
                'invoice_paid',
                'payment',
                'info',
                'Invoice marked as paid: ' . $invoice['invoiceNumber']
                    . ' — ' . $invoice['currency'] . ' ' . number_format((float)$invoice['totalAmount'], 2),
                [
                    'invoiceID'            => $invoiceID,
                    'invoiceNumber'        => $invoice['invoiceNumber'],
                    'totalAmount'          => (float)$invoice['totalAmount'],
                    'currency'             => $invoice['currency'],
                    'transactionReference' => $transactionReference,
                ]
            );

            // 🔗 Dispatch webhook
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('invoice.paid', [
                    'invoiceID'            => $invoiceID,
                    'invoiceNumber'        => $invoice['invoiceNumber'],
                    'userID'               => (int)$invoice['userID'],
                    'totalAmount'          => (float)$invoice['totalAmount'],
                    'currency'             => $invoice['currency'],
                    'transactionReference' => $transactionReference,
                ]);
            }

            return ['success' => true, 'message' => 'Invoice marked as paid'];

        } catch (\Exception $e) {
            ActivityLogger::log(
                isset($invoice) ? (int)$invoice['userID'] : null,
                'invoice_error',
                'payment',
                'error',
                'Failed to mark invoice as paid (ID: ' . $invoiceID . '): ' . $e->getMessage(),
                ['invoiceID' => $invoiceID]
            );

            return ['success' => false, 'message' => 'Failed to mark invoice as paid'];
        }
    }

    /**
     * 🚫 Mark an Invoice as Void
     *
     * Voids an invoice, recording the admin who performed the action and the
     * reason. Voided invoices cannot be un-voided — a reissue must be used instead.
     *
     * @param int         $invoiceID   The invoice ID to void
     * @param int|null    $adminUserID The admin user who is voiding the invoice (for audit)
     * @param string      $reason      The reason for voiding
     *
     * @return array ['success' => bool, 'message' => string]
     *
     * @example
     * ```php
     * $result = InvoiceManager::markVoid(123, 1, 'Duplicate invoice issued in error');
     * ```
     */
    public static function markVoid(int $invoiceID, ?int $adminUserID = null, string $reason = ''): array
    {
        try {
            // ─────────────────────────────────────────────────────────────────
            // 🔍 Fetch the invoice and verify it can be voided
            // Already-void and already-cancelled invoices cannot be voided again
            // ─────────────────────────────────────────────────────────────────
            $invoice = self::getInvoice($invoiceID);

            if (!$invoice) {
                return ['success' => false, 'message' => 'Invoice not found'];
            }

            $nonVoidableStatuses = ['void', 'cancelled'];
            if (in_array($invoice['status'], $nonVoidableStatuses, true)) {
                return [
                    'success' => false,
                    'message' => 'Invoice is already ' . $invoice['status'] . ' and cannot be voided',
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 💾 Update status to 'void' with timestamp and reason
            // Append void reason to existing notes for audit trail
            // ─────────────────────────────────────────────────────────────────
            $voidNote = PHP_EOL . '[VOIDED'
                . (!empty($reason) ? ' — Reason: ' . $reason : '')
                . ($adminUserID !== null ? ' — By admin user ID: ' . $adminUserID : '')
                . ' — ' . date('Y-m-d H:i:s T')
                . ']';

            Database::query(
                "UPDATE tblInvoices
                 SET status = 'void', voidedAt = NOW(), notes = CONCAT(COALESCE(notes, ''), ?)
                 WHERE invoiceID = ?",
                [$voidNote, $invoiceID],
                'si'
            );

            // 📝 Log the void action
            ActivityLogger::log(
                $adminUserID ?? (int)$invoice['userID'],
                'invoice_voided',
                'payment',
                'warning',
                'Invoice voided: ' . $invoice['invoiceNumber']
                    . (!empty($reason) ? ' — Reason: ' . $reason : ''),
                [
                    'invoiceID'     => $invoiceID,
                    'invoiceNumber' => $invoice['invoiceNumber'],
                    'previousStatus' => $invoice['status'],
                    'reason'        => $reason,
                    'adminUserID'   => $adminUserID,
                ]
            );

            // 🔗 Dispatch webhook
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('invoice.voided', [
                    'invoiceID'     => $invoiceID,
                    'invoiceNumber' => $invoice['invoiceNumber'],
                    'userID'        => (int)$invoice['userID'],
                    'reason'        => $reason,
                    'adminUserID'   => $adminUserID,
                ]);
            }

            return ['success' => true, 'message' => 'Invoice voided successfully'];

        } catch (\Exception $e) {
            ActivityLogger::log(
                $adminUserID,
                'invoice_error',
                'payment',
                'error',
                'Failed to void invoice (ID: ' . $invoiceID . '): ' . $e->getMessage(),
                ['invoiceID' => $invoiceID, 'reason' => $reason]
            );

            return ['success' => false, 'message' => 'Failed to void invoice'];
        }
    }

    // ========================================================================
    // 🔄 INVOICE REISSUE
    // ========================================================================

    /**
     * 🔄 Reissue an Invoice
     *
     * Creates a new invoice based on the original (copies line items, billing
     * details, etc.) and marks the original as void. This is useful when an
     * invoice has errors or needs to be corrected without losing the audit trail.
     *
     * @param int $originalInvoiceID The original invoice ID to reissue
     *
     * @return array ['success' => bool, 'invoiceID' => int, 'invoiceNumber' => string,
     *                'originalInvoiceID' => int, 'message' => string]
     *
     * @example
     * ```php
     * $result = InvoiceManager::reissueInvoice(123);
     * // $result['invoiceID'] = 456 (new invoice)
     * // Original 123 is now voided
     * ```
     */
    public static function reissueInvoice(int $originalInvoiceID): array
    {
        try {
            // ─────────────────────────────────────────────────────────────────
            // 🔍 Fetch the original invoice
            // ─────────────────────────────────────────────────────────────────
            $original = self::getInvoice($originalInvoiceID);

            if (!$original) {
                return [
                    'success'           => false,
                    'invoiceID'         => 0,
                    'invoiceNumber'     => '',
                    'originalInvoiceID' => $originalInvoiceID,
                    'message'           => 'Original invoice not found',
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 🔍 Cannot reissue an already-void or cancelled invoice
            // ─────────────────────────────────────────────────────────────────
            if (in_array($original['status'], ['void', 'cancelled'], true)) {
                return [
                    'success'           => false,
                    'invoiceID'         => 0,
                    'invoiceNumber'     => '',
                    'originalInvoiceID' => $originalInvoiceID,
                    'message'           => 'Cannot reissue a ' . $original['status'] . ' invoice',
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 📦 Decode line items from the original invoice
            // ─────────────────────────────────────────────────────────────────
            $lineItems = is_string($original['lineItems'])
                ? json_decode($original['lineItems'], true)
                : $original['lineItems'];

            if (!is_array($lineItems)) {
                $lineItems = [];
            }

            // ─────────────────────────────────────────────────────────────────
            // 📦 Decode billing address from the original invoice
            // ─────────────────────────────────────────────────────────────────
            $billingAddress = null;
            if (!empty($original['billingAddress'])) {
                $billingAddress = is_string($original['billingAddress'])
                    ? json_decode($original['billingAddress'], true)
                    : $original['billingAddress'];
            }

            // ─────────────────────────────────────────────────────────────────
            // 🆕 Create the new invoice with the same details
            // ─────────────────────────────────────────────────────────────────
            $options = [
                'partnerID'      => $original['partnerID'] ? (int)$original['partnerID'] : null,
                'paymentID'      => $original['paymentID'] ? (int)$original['paymentID'] : null,
                'subscriptionID' => $original['subscriptionID'] ? (int)$original['subscriptionID'] : null,
                'currency'       => $original['currency'],
                'discountAmount' => (float)$original['discountAmount'],
                'taxRate'        => (float)$original['taxRate'],
                'billingName'    => $original['billingName'],
                'billingEmail'   => $original['billingEmail'],
                'companyName'    => $original['companyName'],
                'vatNumber'      => $original['vatNumber'],
                'notes'          => 'Reissued from invoice ' . $original['invoiceNumber']
                    . (!empty($original['notes']) ? PHP_EOL . $original['notes'] : ''),
                'dueDate'        => $original['dueDate'],
                'autoIssue'      => true, // Automatically issue the replacement
            ];

            // Include billing address if available
            if (is_array($billingAddress)) {
                $options['billingAddress'] = $billingAddress;
            }

            $newInvoice = self::createInvoice(
                (int)$original['userID'],
                $original['invoiceType'],
                $lineItems,
                $options
            );

            if (!$newInvoice['success']) {
                return [
                    'success'           => false,
                    'invoiceID'         => 0,
                    'invoiceNumber'     => '',
                    'originalInvoiceID' => $originalInvoiceID,
                    'message'           => 'Failed to create replacement invoice: ' . ($newInvoice['message'] ?? 'Unknown error'),
                ];
            }

            // ─────────────────────────────────────────────────────────────────
            // 🚫 Void the original invoice
            // ─────────────────────────────────────────────────────────────────
            $voidResult = self::markVoid(
                $originalInvoiceID,
                null,
                'Reissued as invoice ' . $newInvoice['invoiceNumber']
            );

            if (!$voidResult['success']) {
                // The new invoice was created, but voiding the original failed
                // Log this anomaly but still return the new invoice
                ActivityLogger::log(
                    (int)$original['userID'],
                    'invoice_reissue_warning',
                    'payment',
                    'warning',
                    'New invoice created but failed to void original: ' . $original['invoiceNumber'],
                    [
                        'originalInvoiceID' => $originalInvoiceID,
                        'newInvoiceID'      => $newInvoice['invoiceID'],
                    ]
                );
            }

            // 📝 Log the reissue action
            ActivityLogger::log(
                (int)$original['userID'],
                'invoice_reissued',
                'payment',
                'info',
                'Invoice reissued: ' . $original['invoiceNumber'] . ' -> ' . $newInvoice['invoiceNumber'],
                [
                    'originalInvoiceID' => $originalInvoiceID,
                    'originalNumber'    => $original['invoiceNumber'],
                    'newInvoiceID'      => $newInvoice['invoiceID'],
                    'newNumber'         => $newInvoice['invoiceNumber'],
                ]
            );

            // 🔗 Dispatch webhook
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('invoice.reissued', [
                    'originalInvoiceID' => $originalInvoiceID,
                    'originalNumber'    => $original['invoiceNumber'],
                    'newInvoiceID'      => $newInvoice['invoiceID'],
                    'newNumber'         => $newInvoice['invoiceNumber'],
                    'userID'            => (int)$original['userID'],
                ]);
            }

            return [
                'success'           => true,
                'invoiceID'         => $newInvoice['invoiceID'],
                'invoiceNumber'     => $newInvoice['invoiceNumber'],
                'originalInvoiceID' => $originalInvoiceID,
                'message'           => 'Invoice reissued successfully',
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'invoice_reissue_error',
                'payment',
                'error',
                'Failed to reissue invoice (ID: ' . $originalInvoiceID . '): ' . $e->getMessage(),
                ['originalInvoiceID' => $originalInvoiceID]
            );

            return [
                'success'           => false,
                'invoiceID'         => 0,
                'invoiceNumber'     => '',
                'originalInvoiceID' => $originalInvoiceID,
                'message'           => 'Failed to reissue invoice. Please try again.',
            ];
        }
    }

    // ========================================================================
    // 📊 STATISTICS & REPORTING
    // ========================================================================

    /**
     * 📊 Get Invoice Statistics
     *
     * Returns aggregate statistics about invoices over a configurable time
     * period. Optionally filtered by partner ID for partner-specific dashboards.
     *
     * Statistics returned:
     * - totalIssued:      Count of all non-draft invoices
     * - totalPaid:        Count of 'paid' invoices
     * - totalOutstanding: Count of 'issued' + 'overdue' invoices
     * - totalVoid:        Count of 'void' invoices
     * - revenueTotal:     Sum of totalAmount for paid invoices
     * - revenueIssued:    Sum of totalAmount for issued (unpaid) invoices
     * - revenueOverdue:   Sum of totalAmount for overdue invoices
     * - averageInvoice:   Average totalAmount across paid invoices
     *
     * @param int      $days      Number of days to look back (default: 30)
     * @param int|null $partnerID Optional partner ID to filter by
     *
     * @return array Associative array of statistics (all numeric values, never null)
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html (MySQL aggregate functions)
     *
     * @example
     * ```php
     * // Last 30 days, all partners
     * $stats = InvoiceManager::getInvoiceStats();
     *
     * // Last 90 days, specific partner
     * $stats = InvoiceManager::getInvoiceStats(90, 5);
     * ```
     */
    // ========================================================================
    // 📊 USAGE BILLING INVOICE SUPPORT
    // ========================================================================
    // Methods for creating and rendering invoices from usage billing summaries.
    // These extend the existing invoice system with itemised usage breakdowns.
    //
    // @since 2.7.0-beta
    // ========================================================================

    /**
     * 📊 Create Usage Invoice from Billing Summary
     *
     * Convenience method that takes a usage billing summary and creates
     * an invoice with properly formatted usage line items. Handles the
     * conversion from billing summary format to invoice line item format,
     * including the tier cap discount line (for hybrid billing).
     *
     * @param int   $summaryID The tblUsageBillingSummary.summaryID
     * @param array $options   Additional invoice options (autoIssue, notes, etc.)
     *
     * @return array {
     *     @type bool   $success       Whether the invoice was created
     *     @type int    $invoiceID     The created invoice ID
     *     @type string $invoiceNumber The generated invoice number
     *     @type string $message       Result description
     * }
     *
     * @see self::createInvoice()         Core invoice creation method
     * @see UsageBillingService::getSummary()  Retrieves the billing summary
     * @since 2.7.0-beta
     */
    public static function createUsageInvoice(int $summaryID, array $options = []): array
    {
        try {
            // 📋 Retrieve the billing summary
            $summary = Database::fetchOne(
                "SELECT ubs.*, s.billingCycle, t.tierName
                 FROM tblUsageBillingSummary ubs
                 JOIN tblSubscriptions s ON ubs.subscriptionID = s.subscriptionID
                 JOIN tblSubscriptionTiers t ON s.tierID = t.tierID
                 WHERE ubs.summaryID = ?",
                [$summaryID],
                'i'
            );

            if (!$summary) {
                return [
                    'success'       => false,
                    'invoiceID'     => 0,
                    'invoiceNumber' => '',
                    'message'       => 'Usage billing summary not found: ' . $summaryID,
                ];
            }

            // 📋 Parse the JSON line items from the billing summary
            $usageLineItems = json_decode($summary['lineItems'] ?? '[]', true);
            $invoiceLineItems = [];

            // 🔄 Convert usage line items to invoice line item format
            foreach ($usageLineItems as $item) {
                $metricName = $item['metricName'] ?? 'Usage';
                $unitLabel = $item['unitLabel'] ?? 'units';
                $billableQty = (float) ($item['billableQuantity'] ?? 0);
                $rate = (float) ($item['rate'] ?? 0);
                $subtotal = (float) ($item['subtotal'] ?? 0);

                // 📝 Build descriptive line item
                $description = $metricName;
                if ($billableQty > 0) {
                    $description .= ' — ' . number_format($billableQty, 2) . ' ' . $unitLabel;
                    $freeAllowance = (float) ($item['freeAllowance'] ?? 0);
                    if ($freeAllowance > 0) {
                        $totalUsage = (float) ($item['totalUsage'] ?? $billableQty);
                        $description .= ' (after ' . number_format($freeAllowance, 0) . ' free)';
                    }
                }

                // Only add items with non-zero cost or quantity
                if ($billableQty > 0 || $subtotal > 0) {
                    $invoiceLineItems[] = [
                        'description' => $description,
                        'quantity'    => 1,
                        'unitPrice'   => $subtotal,
                        'amount'      => $subtotal,
                    ];
                }
            }

            // 📊 Add zero-usage items as informational lines (£0.00)
            foreach ($usageLineItems as $item) {
                $billableQty = (float) ($item['billableQuantity'] ?? 0);
                if ($billableQty <= 0 && ((float) ($item['totalUsage'] ?? 0)) > 0) {
                    $invoiceLineItems[] = [
                        'description' => ($item['metricName'] ?? 'Usage')
                            . ' — ' . number_format((float) ($item['totalUsage'] ?? 0), 2) . ' '
                            . ($item['unitLabel'] ?? 'units')
                            . ' (within free allowance)',
                        'quantity'    => 1,
                        'unitPrice'   => 0.00,
                        'amount'      => 0.00,
                    ];
                }
            }

            // 🔒 If tier cap was applied (hybrid mode), add a discount line
            if (!empty($summary['capApplied']) && $summary['capApplied'] == 1) {
                $savings = (float) ($summary['savingsAmount'] ?? 0);
                if ($savings > 0) {
                    $invoiceLineItems[] = [
                        'description' => 'Tier cap discount — ' . htmlspecialchars($summary['tierName'] ?? 'Subscription')
                            . ' monthly cap (' . $summary['currency'] . ' '
                            . number_format((float) $summary['tierFixedPrice'], 2) . ')',
                        'quantity'    => 1,
                        'unitPrice'   => -$savings,
                        'amount'      => -$savings,
                    ];
                }
            }

            // 📝 Build invoice notes
            $periodLabel = $summary['billingPeriodStart'] . ' to ' . $summary['billingPeriodEnd'];
            $modeLabel = match ($summary['billingMode'] ?? 'hybrid') {
                'usage'  => 'Pay-as-you-go',
                'hybrid' => 'Hybrid (usage with tier cap)',
                default  => 'Usage-based',
            };

            $invoiceNotes = 'Usage billing for period ' . $periodLabel
                . ' — Mode: ' . $modeLabel;

            if (!empty($summary['capApplied']) && $summary['capApplied'] == 1) {
                $invoiceNotes .= ' — Tier cap applied (saved '
                    . $summary['currency'] . ' ' . number_format((float) $summary['savingsAmount'], 2) . ')';
            }

            // 🧾 Create the invoice using the standard createInvoice method
            $invoiceOptions = array_merge([
                'autoIssue'      => true,
                'currency'       => $summary['currency'] ?? self::DEFAULT_CURRENCY,
                'partnerID'      => $summary['partnerID'],
                'subscriptionID' => (int) $summary['subscriptionID'],
                'notes'          => $invoiceNotes,
            ], $options);

            return self::createInvoice(
                (int) $summary['userID'],
                'usage',
                $invoiceLineItems,
                $invoiceOptions
            );

        } catch (\Exception $e) {
            ErrorLogger::log($e);
            return [
                'success'       => false,
                'invoiceID'     => 0,
                'invoiceNumber' => '',
                'message'       => 'Failed to create usage invoice: ' . $e->getMessage(),
            ];
        }
    }

    public static function getInvoiceStats(int $days = 30, ?int $partnerID = null): array
    {
        // ─────────────────────────────────────────────────────────────────
        // 🏗️ Build the SQL query dynamically based on optional partner filter
        // ─────────────────────────────────────────────────────────────────
        $sql = "SELECT
                    -- 📊 Invoice counts by status
                    COUNT(CASE WHEN status != 'draft' THEN 1 END) AS totalIssued,
                    COUNT(CASE WHEN status = 'paid' THEN 1 END) AS totalPaid,
                    COUNT(CASE WHEN status IN ('issued', 'overdue') THEN 1 END) AS totalOutstanding,
                    COUNT(CASE WHEN status = 'void' THEN 1 END) AS totalVoid,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS totalCancelled,
                    COUNT(CASE WHEN status = 'draft' THEN 1 END) AS totalDraft,

                    -- 💰 Revenue aggregates
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN totalAmount ELSE 0 END), 0) AS revenueTotal,
                    COALESCE(SUM(CASE WHEN status = 'issued' THEN totalAmount ELSE 0 END), 0) AS revenueIssued,
                    COALESCE(SUM(CASE WHEN status = 'overdue' THEN totalAmount ELSE 0 END), 0) AS revenueOverdue,

                    -- 📈 Averages
                    COALESCE(AVG(CASE WHEN status = 'paid' THEN totalAmount ELSE NULL END), 0) AS averageInvoice,

                    -- 🏷️ Tax and discount totals
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN taxAmount ELSE 0 END), 0) AS totalTaxCollected,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN discountAmount ELSE 0 END), 0) AS totalDiscountsGiven

                FROM tblInvoices
                WHERE createdAt >= DATE_SUB(NOW(), INTERVAL ? DAY)";

        $params = [$days];
        $types  = 'i';

        // Optional partner filter
        if ($partnerID !== null) {
            $sql .= " AND partnerID = ?";
            $params[] = $partnerID;
            $types   .= 'i';
        }

        $result = Database::fetchOne($sql, $params, $types);

        // ─────────────────────────────────────────────────────────────────
        // 🔄 Ensure all values are numeric (never null) for safe consumption
        // by frontend dashboards and API responses
        // ─────────────────────────────────────────────────────────────────
        if (!$result) {
            return [
                'totalIssued'         => 0,
                'totalPaid'           => 0,
                'totalOutstanding'    => 0,
                'totalVoid'           => 0,
                'totalCancelled'      => 0,
                'totalDraft'          => 0,
                'revenueTotal'        => 0.00,
                'revenueIssued'       => 0.00,
                'revenueOverdue'      => 0.00,
                'averageInvoice'      => 0.00,
                'totalTaxCollected'   => 0.00,
                'totalDiscountsGiven' => 0.00,
                'days'                => $days,
                'partnerID'           => $partnerID,
            ];
        }

        // Cast all values to appropriate types for consistency
        return [
            'totalIssued'         => (int)($result['totalIssued'] ?? 0),
            'totalPaid'           => (int)($result['totalPaid'] ?? 0),
            'totalOutstanding'    => (int)($result['totalOutstanding'] ?? 0),
            'totalVoid'           => (int)($result['totalVoid'] ?? 0),
            'totalCancelled'      => (int)($result['totalCancelled'] ?? 0),
            'totalDraft'          => (int)($result['totalDraft'] ?? 0),
            'revenueTotal'        => round((float)($result['revenueTotal'] ?? 0), 2),
            'revenueIssued'       => round((float)($result['revenueIssued'] ?? 0), 2),
            'revenueOverdue'      => round((float)($result['revenueOverdue'] ?? 0), 2),
            'averageInvoice'      => round((float)($result['averageInvoice'] ?? 0), 2),
            'totalTaxCollected'   => round((float)($result['totalTaxCollected'] ?? 0), 2),
            'totalDiscountsGiven' => round((float)($result['totalDiscountsGiven'] ?? 0), 2),
            'days'                => $days,
            'partnerID'           => $partnerID,
        ];
    }
}
