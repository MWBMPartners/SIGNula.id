<?php
/**
 * ============================================================================
 * 📤 SIGNula - Data Export API Endpoint (Excel / PDF)
 * ============================================================================
 *
 * Server-side export endpoint that receives table data as JSON and returns
 * it as a downloadable Excel (XLSX) or PDF file. CSV exports are handled
 * entirely on the client side and do not use this endpoint.
 *
 * Accepts POST requests with:
 *   - format      (string)  "excel" or "pdf"
 *   - type        (string)  Export type label (e.g. "users", "activity-log")
 *   - title       (string)  Human-readable title for the document header
 *   - data        (object)  { headers: string[], rows: string[][] }
 *   - csrf_token  (string)  CSRF token for request verification
 *
 * Authentication:
 *   - Session-based (user must be logged in), OR
 *   - API key via X-API-Key header / ?api_key= parameter
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage API\Export
 * @version    1.0.0
 * @link       https://SIGNula.id/api/docs
 * @see        web/private_html/utils/ExportService.php — Excel & PDF generation
 * ============================================================================
 */

// ============================================================================
// 🛡️ SIGNULA INIT GUARD
// ============================================================================
// Prevent direct access to this file outside the SIGNula application context.
// Every SIGNula source file checks for this constant.
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    define('SIGNULA_INIT', true);
}

// ============================================================================
// 🔧 LOAD APPLICATION CONFIGURATION
// ============================================================================
// Navigate up from web/public_html/api/v1/export/ → web/ then into _config/
// Uses DIRECTORY_SEPARATOR for cross-platform path compatibility.
// @see https://www.php.net/manual/en/dir.constants.php
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 📋 SET JSON RESPONSE HEADER (default — overridden on successful export)
// ============================================================================
// Error responses will be JSON; successful exports override Content-Type
// with the appropriate binary MIME type.
header('Content-Type: application/json; charset=utf-8');

// ============================================================================
// 🗄️ DATABASE HANDLE (CAP-API Bucket B prerequisite fix)
// ============================================================================
// 🐛 This file's own API-key branch below (`isset($db)` at the original line
// 112) has ALWAYS been dead code: nothing in this file ever defined `$db`,
// so the isset() guard silently skipped API-key validation entirely on
// every request — an X-API-Key caller with no session would always fall
// through to the generic 401 further down, even with a perfectly valid
// key. Defining it here the SAME way dozens of other standalone/AJAX
// handlers in this codebase do (`Database::getInstance()` — a
// DatabaseConnection proxy with the same prepare()/query() surface as a
// raw mysqli link) makes the existing APIKeyMiddleware call actually run,
// AND supplies the DB handle RateLimitMiddleware needs below.
// @see web/_config/database.php — Database::getInstance()/DatabaseConnection
$db = Database::getInstance();

// ============================================================================
// 🛡️ RATE LIMITING (CAP-API Bucket B, item #3)
// ============================================================================
// Apply the SAME central rate limiter the /api/v1 router enforces to this
// standalone endpoint (previously bypassed entirely). Fails OPEN on any
// internal limiter error; a genuine over-limit request still gets HTTP 429
// exactly like a router-handled endpoint.
// @see web/private_html/api/RateLimitMiddleware.php::enforceStandalone()
if (!class_exists('RateLimitMiddleware')) {
    require_once ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'api'
        . DIRECTORY_SEPARATOR . 'RateLimitMiddleware.php';
}
RateLimitMiddleware::enforceStandalone($db);

/**
 * 🔀 Emit a JSON error response in the canonical superset shape (CAP-API
 * Bucket B, item #1) — keeps the pre-existing `error` string key AND adds
 * `message`/`errors`/`meta`, then exits.
 *
 * @param int         $statusCode HTTP status code
 * @param string      $message    Human-readable error message
 * @param string|null $allowHeader Optional `Allow:` header value (405 responses)
 * @return never
 */
function emitExportError(int $statusCode, string $message, ?string $allowHeader = null): never
{
    http_response_code($statusCode);
    if ($allowHeader !== null) {
        header('Allow: ' . $allowHeader);
    }
    echo json_encode([
        'success' => false,
        'error'   => $message,
        'message' => $message,
        'errors'  => [$message],
        'meta'    => [
            'timestamp'  => gmdate('c'),
            'version'    => 'v1',
            'request_id' => bin2hex(random_bytes(16)),
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================================
// 🔒 CORS & METHOD VALIDATION
// ============================================================================

try {
    // ✅ Only POST requests are accepted for data export
    // GET is not suitable because the request body contains potentially large JSON data
    // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        emitExportError(405, 'Method not allowed. Use POST to submit export data.', 'POST');
    }

    // ============================================================================
    // 🔐 AUTHENTICATION CHECK
    // ============================================================================
    // Verify the user is authenticated via session OR API key.
    // Auth::isAuthenticated() checks the current session for a valid logged-in user.
    // If not session-authenticated, we also accept an API key via header or query param.
    // @see web/private_html/auth/Auth.php — Auth::isAuthenticated()
    // @see web/private_html/api/APIKeyMiddleware.php — API key validation

    $isAuthenticated = false;
    $userID = null;

    // 🔑 Check session-based authentication first
    if (Auth::isAuthenticated()) {
        $isAuthenticated = true;
        $userID = Auth::getCurrentUserID();
    }

    // 🔑 If not session-authenticated, check for API key authentication
    // API keys are passed via X-API-Key header or ?api_key= query parameter
    // @see https://swagger.io/docs/specification/authentication/api-keys/
    if (!$isAuthenticated) {
        $apiKey = $_SERVER['HTTP_X_API_KEY']
            ?? $_GET['api_key']
            ?? null;

        if (!empty($apiKey)) {
            // 📂 Load API key middleware for validation
            $apiKeyMiddlewarePath = dirname(__DIR__, 4)
                . DIRECTORY_SEPARATOR . 'private_html'
                . DIRECTORY_SEPARATOR . 'api'
                . DIRECTORY_SEPARATOR . 'APIKeyMiddleware.php';

            if (file_exists($apiKeyMiddlewarePath)) {
                require_once $apiKeyMiddlewarePath;

                // 🔍 Validate the API key against the database
                if (class_exists('APIKeyMiddleware') && isset($db)) {
                    $apiKeyMw = new APIKeyMiddleware($db);
                    // handle(true) will exit with 401 if invalid; if we get past it, key is valid
                    $apiKeyMw->handle(true);
                    $isAuthenticated = true;
                }
            }
        }
    }

    // ❌ Neither session nor API key authentication succeeded
    if (!$isAuthenticated) {
        emitExportError(401, 'Authentication required. Please log in or provide a valid API key.');
    }

    // ============================================================================
    // 📥 PARSE & VALIDATE REQUEST BODY
    // ============================================================================
    // Read the raw POST body and decode it as JSON.
    // @see https://www.php.net/manual/en/wrappers.php.php#wrappers.php.input

    $rawInput = file_get_contents('php://input');
    $requestData = json_decode($rawInput, true);

    // 🔍 Check for JSON parse errors
    // @see https://www.php.net/manual/en/function.json-last-error.php
    if (json_last_error() !== JSON_ERROR_NONE) {
        emitExportError(400, 'Invalid JSON in request body: ' . json_last_error_msg());
    }

    // ============================================================================
    // 🛡️ CSRF TOKEN VERIFICATION
    // ============================================================================
    // Verify the CSRF token to prevent cross-site request forgery attacks.
    // Session-authenticated requests MUST include a valid CSRF token.
    // API key requests are exempt (they use their own auth mechanism).
    // @see web/private_html/security/SecurityUtils.php — SecurityUtils::verifyCSRFToken()

    if ($userID !== null) {
        // 🔍 Only enforce CSRF for session-based auth (not API key auth)
        $csrfToken = $requestData['csrf_token'] ?? '';

        if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
            emitExportError(403, 'Invalid or expired CSRF token. Please refresh the page and try again.');
        }
    }

    // ============================================================================
    // ✅ VALIDATE REQUIRED FIELDS
    // ============================================================================
    // Sanitize all string inputs using SecurityUtils to prevent XSS and injection.
    // @see web/private_html/security/SecurityUtils.php — SecurityUtils::sanitizeString()

    // 📄 Format: must be "excel" or "pdf"
    $format = SecurityUtils::sanitizeString($requestData['format'] ?? '');
    $allowedFormats = ['excel', 'pdf'];

    if (empty($format) || !in_array($format, $allowedFormats, true)) {
        emitExportError(400, 'Invalid or missing "format" parameter. Allowed values: excel, pdf.');
    }

    // 🏷️ Type: export type label (e.g. "users", "activity-log")
    $type = SecurityUtils::sanitizeString($requestData['type'] ?? 'export');

    if (empty($type)) {
        $type = 'export';
    }

    // 📝 Title: human-readable document title
    $title = SecurityUtils::sanitizeString($requestData['title'] ?? 'Data Export');

    if (empty($title)) {
        $title = 'Data Export';
    }

    // 📊 Data: must contain headers[] and rows[][]
    $exportData = $requestData['data'] ?? null;

    if (!is_array($exportData)) {
        emitExportError(400, 'Missing or invalid "data" parameter. Expected object with "headers" and "rows".');
    }

    // 🔍 Validate headers array
    $headers = $exportData['headers'] ?? [];

    if (!is_array($headers) || empty($headers)) {
        emitExportError(400, 'Missing or empty "data.headers" array.');
    }

    // 🔍 Validate rows array
    $rows = $exportData['rows'] ?? [];

    if (!is_array($rows)) {
        emitExportError(400, '"data.rows" must be an array.');
    }

    // 🧹 Sanitize all header values
    $headers = array_map(function ($header) {
        return SecurityUtils::sanitizeString((string) $header);
    }, $headers);

    // 🧹 Sanitize all row cell values
    $rows = array_map(function ($row) {
        if (!is_array($row)) {
            return [];
        }
        return array_map(function ($cell) {
            return SecurityUtils::sanitizeString((string) $cell);
        }, $row);
    }, $rows);

    // ============================================================================
    // 📊 CONVERT ROWS TO ASSOCIATIVE ARRAYS
    // ============================================================================
    // ExportService expects an array of associative arrays (header => value).
    // We map each row's indexed values to the corresponding header keys.

    $associativeRows = [];

    foreach ($rows as $row) {
        $assocRow = [];
        foreach ($headers as $index => $headerName) {
            // 📝 Map each header to its corresponding cell value
            // If the row has fewer cells than headers, default to empty string
            $assocRow[$headerName] = $row[$index] ?? '';
        }
        $associativeRows[] = $assocRow;
    }

    // ============================================================================
    // 📁 BUILD FILENAME
    // ============================================================================
    // Generate a safe, descriptive filename for the download.
    // Format: {type}-export-{YYYY-MM-DD}.{extension}
    // @see https://www.php.net/manual/en/function.date.php

    $safeType = preg_replace('/[^a-zA-Z0-9_-]/', '', $type);

    if (empty($safeType)) {
        $safeType = 'export';
    }

    $dateStamp = date('Y-m-d');
    $extension = ($format === 'excel') ? 'xlsx' : 'pdf';
    $filename  = $safeType . '-export-' . $dateStamp . '.' . $extension;

    // ============================================================================
    // 📂 LOAD EXPORT SERVICE
    // ============================================================================
    // ExportService is auto-loaded via the spl_autoload_register in config.php
    // from web/private_html/utils/ExportService.php
    // @see web/private_html/utils/ExportService.php

    if (!class_exists('ExportService')) {
        // 🔄 Manual fallback load if autoloader didn't find it
        $exportServicePath = dirname(__DIR__, 4)
            . DIRECTORY_SEPARATOR . 'private_html'
            . DIRECTORY_SEPARATOR . 'utils'
            . DIRECTORY_SEPARATOR . 'ExportService.php';

        if (!file_exists($exportServicePath)) {
            emitExportError(500, 'Export service is not available. Please contact the administrator.');
        }

        require_once $exportServicePath;
    }

    // ============================================================================
    // 🚀 PERFORM EXPORT
    // ============================================================================
    // Delegate to ExportService which sets the appropriate response headers
    // (Content-Type, Content-Disposition) and outputs the file, then exits.

    if ($format === 'excel') {
        // 📊 Excel (XLSX) export
        // ExportService::exportExcel() outputs headers + binary content and calls exit()
        // Falls back to CSV if ZipArchive extension is not available
        // @see web/private_html/utils/ExportService.php — exportExcel()
        ExportService::exportExcel($associativeRows, $filename, $headers);
    } elseif ($format === 'pdf') {
        // 📄 PDF export
        // ExportService::exportPDF() outputs headers + binary/HTML content and calls exit()
        // Uses TCPDF if available; falls back to print-optimised HTML
        // @see web/private_html/utils/ExportService.php — exportPDF()
        ExportService::exportPDF($associativeRows, $filename, $headers, $title);
    }

    // ⚠️ This point should not be reached because ExportService methods call exit()
    // If we somehow get here, return an error
    emitExportError(500, 'Export completed but no output was generated.');

} catch (Exception $e) {
    // ============================================================================
    // ❌ GLOBAL ERROR HANDLER
    // ============================================================================
    // Catch any unexpected exceptions and return a safe error response.
    // Log the full error details for debugging via ErrorLogger.
    // @see https://www.php.net/manual/en/language.exceptions.php

    // 📝 Log the error if ErrorLogger is available
    if (class_exists('ErrorLogger')) {
        ErrorLogger::log($e);
    } else {
        error_log('SIGNula Export API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }

    http_response_code(500);
    $exportErrorMessage = 'An unexpected error occurred during export.';
    echo json_encode([
        'success' => false,
        'error'   => $exportErrorMessage,
        // 🐛 Include debug info only in development environment
        // @see web/_config/config.php — ENVIRONMENT constant
        'debug'   => (defined('ENVIRONMENT') && ENVIRONMENT === 'development')
            ? $e->getMessage()
            : null,
        // 🛡️ CAP-API Bucket B (#1): canonical keys ADDED alongside the
        // pre-existing `error`/`debug` keys.
        'message' => $exportErrorMessage,
        'errors'  => [$exportErrorMessage],
        'meta'    => [
            'timestamp'  => gmdate('c'),
            'version'    => 'v1',
            'request_id' => bin2hex(random_bytes(16)),
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
