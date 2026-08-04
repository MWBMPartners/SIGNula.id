<?php
/**
 * ============================================================================
 * ☁️ SIGNula - Cloud Export API Endpoint (Google Sheets / Excel Online)
 * ============================================================================
 *
 * OAuth redirect endpoint for cloud-based export services. Initiates the
 * OAuth2 authorization flow to allow SIGNula to create spreadsheets in
 * the user's Google Sheets or Microsoft Excel Online account.
 *
 * Accepts GET requests with:
 *   - format  (string)  "google_sheets" or "excel_online"
 *   - type    (string)  Export type label (e.g. "users", "activity-log")
 *   - title   (string)  Human-readable title for the spreadsheet
 *
 * The type and title are stored in the user's session so the OAuth callback
 * handler can retrieve them after the user authorises the application.
 *
 * Authentication:
 *   - Session-based only (user must be logged in — OAuth flow requires a session)
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage API\Export
 * @version    1.0.0
 * @link       https://SIGNula.id/api/docs
 * @see        web/private_html/utils/ExportService.php — OAuth URL builders
 * @see        https://developers.google.com/identity/protocols/oauth2/web-server
 * @see        https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow
 * ============================================================================
 */

// ============================================================================
// 🛡️ SIGNULA INIT GUARD
// ============================================================================
// Prevent direct access outside the SIGNula application context.
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    define('SIGNULA_INIT', true);
}

// ============================================================================
// 🔧 LOAD APPLICATION CONFIGURATION
// ============================================================================
// Navigate up from web/public_html/api/v1/export-cloud/ → web/ then into _config/
// Uses DIRECTORY_SEPARATOR for cross-platform path compatibility.
// @see https://www.php.net/manual/en/dir.constants.php
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 📋 SET JSON RESPONSE HEADER (default — overridden by redirect on success)
// ============================================================================
// Error responses are JSON; successful requests redirect to OAuth provider.
header('Content-Type: application/json; charset=utf-8');

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
RateLimitMiddleware::enforceStandalone();

/**
 * 🔀 Emit a JSON error response in the canonical superset shape (CAP-API
 * Bucket B, item #1) — keeps the pre-existing `error` string key AND adds
 * `message`/`errors`/`meta`, then exits.
 *
 * @param int         $statusCode  HTTP status code
 * @param string      $message     Human-readable error message
 * @param string|null $allowHeader Optional `Allow:` header value (405 responses)
 * @return never
 */
function emitCloudExportError(int $statusCode, string $message, ?string $allowHeader = null): never
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
// 🔒 METHOD VALIDATION
// ============================================================================

try {
    // ✅ Only GET requests are accepted for OAuth redirect initiation
    // The user clicks an "Export to Google Sheets" / "Export to Excel Online" link
    // which triggers a GET request to this endpoint.
    // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/GET
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        emitCloudExportError(405, 'Method not allowed. Use GET to initiate cloud export.', 'GET');
    }

    // ============================================================================
    // 🔐 AUTHENTICATION CHECK (SESSION ONLY)
    // ============================================================================
    // Cloud exports require a session because:
    // 1. We store export metadata (type, title) in the session for the OAuth callback
    // 2. The OAuth flow is user-interactive and session-bound
    // API key auth is NOT supported for this endpoint.
    // @see web/private_html/auth/Auth.php — Auth::isAuthenticated()

    if (!Auth::isAuthenticated()) {
        emitCloudExportError(401, 'Authentication required. Please log in to use cloud export.');
    }

    $userID = Auth::getCurrentUserID();

    // ============================================================================
    // ✅ VALIDATE & SANITIZE QUERY PARAMETERS
    // ============================================================================
    // All string inputs are sanitized via SecurityUtils to prevent XSS/injection.
    // @see web/private_html/security/SecurityUtils.php — SecurityUtils::sanitizeString()

    // ☁️ Format: must be "google_sheets" or "excel_online"
    $format = SecurityUtils::sanitizeString($_GET['format'] ?? '');
    $allowedFormats = ['google_sheets', 'excel_online'];

    if (empty($format) || !in_array($format, $allowedFormats, true)) {
        emitCloudExportError(400, 'Invalid or missing "format" parameter. Allowed values: google_sheets, excel_online.');
    }

    // 🏷️ Type: export type label (e.g. "users", "activity-log")
    $type = SecurityUtils::sanitizeString($_GET['type'] ?? 'export');

    if (empty($type)) {
        $type = 'export';
    }

    // 📝 Title: human-readable title for the spreadsheet
    $title = SecurityUtils::sanitizeString($_GET['title'] ?? 'Data Export');

    if (empty($title)) {
        $title = 'Data Export';
    }

    // ============================================================================
    // 💾 STORE EXPORT METADATA IN SESSION
    // ============================================================================
    // The OAuth callback handler needs to know what type of export was requested
    // and what title to give the spreadsheet. We store this in the session so it
    // persists across the OAuth redirect flow.
    // @see https://www.php.net/manual/en/book.session.php

    $_SESSION['cloud_export'] = [
        'format'    => $format,       // 📄 "google_sheets" or "excel_online"
        'type'      => $type,         // 🏷️ Export type label
        'title'     => $title,        // 📝 Spreadsheet title
        'user_id'   => $userID,       // 👤 Authenticated user ID (for callback verification)
        'timestamp' => time(),        // ⏰ When the export was initiated (for expiry checks)
    ];

    // ============================================================================
    // 📂 LOAD EXPORT SERVICE
    // ============================================================================
    // ExportService provides the OAuth URL builders for Google Sheets and Excel Online.
    // Auto-loaded via spl_autoload_register in config.php from
    // web/private_html/utils/ExportService.php
    // @see web/private_html/utils/ExportService.php

    if (!class_exists('ExportService')) {
        // 🔄 Manual fallback load if autoloader didn't find it
        $exportServicePath = dirname(__DIR__, 4)
            . DIRECTORY_SEPARATOR . 'private_html'
            . DIRECTORY_SEPARATOR . 'utils'
            . DIRECTORY_SEPARATOR . 'ExportService.php';

        if (!file_exists($exportServicePath)) {
            emitCloudExportError(500, 'Export service is not available. Please contact the administrator.');
        }

        require_once $exportServicePath;
    }

    // ============================================================================
    // 🔗 BUILD REDIRECT URI FOR OAUTH CALLBACK
    // ============================================================================
    // The redirect URI tells the OAuth provider where to send the user after
    // they authorise (or deny) the application. This must match a URI registered
    // in the provider's developer console.
    // @see https://datatracker.ietf.org/doc/html/rfc6749#section-3.1.2

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https://'
        : 'http://';

    // 🛡️ Use configured site URL if available, falling back to SERVER_NAME (safer than HTTP_HOST)
    // HTTP_HOST can be spoofed by clients; SERVER_NAME is set by the server configuration.
    // @see https://owasp.org/www-community/attacks/Host_Header_Injection
    $siteUrl = getSetting('site.url');
    if (!empty($siteUrl)) {
        $callbackBase = rtrim($siteUrl, '/');
    } else {
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $callbackBase = $protocol . $host;
    }

    // 🔐 Generate and store OAuth state parameter for CSRF protection
    // The state parameter prevents cross-site request forgery in the OAuth flow.
    // @see https://datatracker.ietf.org/doc/html/rfc6749#section-10.12
    $oauthState = bin2hex(random_bytes(16));
    $_SESSION['oauth_export_state'] = $oauthState;

    // ============================================================================
    // 🚀 INITIATE OAUTH REDIRECT
    // ============================================================================
    // Build the provider-specific OAuth authorization URL and redirect the user.
    // ExportService::getGoogleSheetsAuthUrl() and getExcelOnlineAuthUrl() build
    // the full OAuth2 authorization URL with required scopes and state parameter.

    $authUrl = '';

    if ($format === 'google_sheets') {
        // 📊 Google Sheets OAuth2 Authorization
        // Redirects to Google's consent screen where the user grants spreadsheet access.
        // @see https://developers.google.com/identity/protocols/oauth2/web-server
        // @see web/private_html/utils/ExportService.php — getGoogleSheetsAuthUrl()

        $redirectUri = $callbackBase . '/api/v1/export-cloud/callback/google';

        try {
            $authUrl = ExportService::getGoogleSheetsAuthUrl($redirectUri, $oauthState);
        } catch (\RuntimeException $e) {
            // ⚠️ Google Sheets client_id not configured in database settings
            http_response_code(503);
            $cloudExportDebug = (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? $e->getMessage() : null;
            $cloudExportMsg = 'Google Sheets export is not configured. Please contact the administrator.';
            echo json_encode([
                'success' => false,
                'error'   => $cloudExportMsg,
                'debug'   => $cloudExportDebug,
                // 🛡️ CAP-API Bucket B (#1): canonical keys ADDED alongside
                // the pre-existing `error`/`debug` keys.
                'message' => $cloudExportMsg,
                'errors'  => [$cloudExportMsg],
                'meta'    => [
                    'timestamp'  => gmdate('c'),
                    'version'    => 'v1',
                    'request_id' => bin2hex(random_bytes(16)),
                ],
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

    } elseif ($format === 'excel_online') {
        // 📊 Excel Online (Microsoft Graph) OAuth2 Authorization
        // Redirects to Microsoft's consent screen where the user grants OneDrive/Excel access.
        // @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow
        // @see web/private_html/utils/ExportService.php — getExcelOnlineAuthUrl()

        $redirectUri = $callbackBase . '/api/v1/export-cloud/callback/microsoft';

        try {
            $authUrl = ExportService::getExcelOnlineAuthUrl($redirectUri, $oauthState);
        } catch (\RuntimeException $e) {
            // ⚠️ Excel Online client_id/tenant_id not configured in database settings
            http_response_code(503);
            $cloudExportDebug = (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? $e->getMessage() : null;
            $cloudExportMsg = 'Excel Online export is not configured. Please contact the administrator.';
            echo json_encode([
                'success' => false,
                'error'   => $cloudExportMsg,
                'debug'   => $cloudExportDebug,
                // 🛡️ CAP-API Bucket B (#1): canonical keys ADDED alongside
                // the pre-existing `error`/`debug` keys.
                'message' => $cloudExportMsg,
                'errors'  => [$cloudExportMsg],
                'meta'    => [
                    'timestamp'  => gmdate('c'),
                    'version'    => 'v1',
                    'request_id' => bin2hex(random_bytes(16)),
                ],
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    // ✅ Validate we got a non-empty auth URL before redirecting
    if (empty($authUrl)) {
        emitCloudExportError(500, 'Failed to generate authorization URL for ' . $format . '.');
    }

    // 📝 Log the cloud export initiation for audit trail
    // @see web/private_html/utils/ActivityLogger.php
    //
    // 🐛 CAP-API Bucket B prerequisite fix: ActivityLogger::log()'s real
    // signature has NO `$activityResult`/`$activityDetails` named
    // parameters (only `$category`/`$severity`/`$description`) — the
    // previous named-argument call here would fatal with "Unknown named
    // parameter" on EVERY successful cloud-export initiation, immediately
    // after the auth URL was built but before the redirect. Corrected to
    // the real parameter names (mirrors the identical fix applied to
    // web/public_html/api/oauth/disconnect.php in the same pass).
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            userID: $userID,
            activityType: 'cloud_export_initiated',
            category: 'other',
            severity: 'info',
            description: 'Initiated ' . $format . ' export for "' . $title . '" (type: ' . $type . ')'
        );
    }

    // 🚀 Redirect the user to the OAuth provider's authorization page
    // Using 302 (Found) for temporary redirect — standard for OAuth flows
    // @see https://datatracker.ietf.org/doc/html/rfc6749#section-4.1.1
    header('Location: ' . $authUrl, true, 302);
    exit;

} catch (Exception $e) {
    // ============================================================================
    // ❌ GLOBAL ERROR HANDLER
    // ============================================================================
    // Catch any unexpected exceptions and return a safe JSON error response.
    // @see https://www.php.net/manual/en/language.exceptions.php

    // 📝 Log the error for debugging
    if (class_exists('ErrorLogger')) {
        ErrorLogger::log($e);
    } else {
        error_log('SIGNula Cloud Export API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }

    http_response_code(500);
    $cloudExportUnexpectedMsg = 'An unexpected error occurred while initiating cloud export.';
    echo json_encode([
        'success' => false,
        'error'   => $cloudExportUnexpectedMsg,
        // 🐛 Include debug info only in development environment
        'debug'   => (defined('ENVIRONMENT') && ENVIRONMENT === 'development')
            ? $e->getMessage()
            : null,
        // 🛡️ CAP-API Bucket B (#1): canonical keys ADDED alongside the
        // pre-existing `error`/`debug` keys.
        'message' => $cloudExportUnexpectedMsg,
        'errors'  => [$cloudExportUnexpectedMsg],
        'meta'    => [
            'timestamp'  => gmdate('c'),
            'version'    => 'v1',
            'request_id' => bin2hex(random_bytes(16)),
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
