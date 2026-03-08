<?php
/**
 * ============================================================================
 * 📊 SIGNula - Microsoft Excel Online OAuth2 Callback Handler
 * ============================================================================
 *
 * Handles the OAuth2 callback from Microsoft after the user authorises (or
 * denies) the application to create workbooks in their OneDrive/Excel Online.
 *
 * OAuth2 Flow (Microsoft Identity Platform):
 *   1. User clicks "Export to Excel Online" → /api/v1/export-cloud/?format=excel_online
 *   2. Endpoint stores metadata in session, redirects to Microsoft consent screen
 *   3. User authorises → Microsoft redirects here with ?code=AUTH_CODE&state=STATE
 *   4. This handler exchanges the code for an access token
 *   5. Calls ExportService::exportToExcelOnline() to upload a workbook
 *   6. Redirects user to success page with workbook URL
 *
 * Query Parameters (from Microsoft):
 *   - code   (string)  Authorization code to exchange for access token
 *   - state  (string)  CSRF protection token (must match session value)
 *   - error  (string)  Error code if user denied or something went wrong
 *   - error_description (string)  Human-readable error description
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage API\Export\Callback
 * @version    1.0.0
 * @link       https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow#request-an-access-token
 * @see        web/private_html/utils/ExportService.php — exportToExcelOnline()
 * @see        web/public_html/api/v1/export-cloud/index.php — OAuth redirect initiator
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 🛡️ SIGNULA INIT GUARD
// ============================================================================
if (!defined('SIGNULA_INIT')) {
    define('SIGNULA_INIT', true);
}

// ============================================================================
// 🔧 LOAD APPLICATION CONFIGURATION
// ============================================================================
// Navigate up from web/public_html/api/v1/export-cloud/callback/microsoft/ → web/_config/
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 6) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 🔒 METHOD VALIDATION — GET only
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    header('Allow: GET');
    echo json_encode([
        'success' => false,
        'error'   => 'Method not allowed.'
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================================
// 🔐 AUTHENTICATION CHECK
// ============================================================================
if (!Auth::isAuthenticated()) {
    $returnUrl = '/settings/usage';
    redirect('/login?return=' . urlencode($returnUrl) . '&error=session_expired');
    exit;
}

$userID = Auth::getCurrentUserID();

// ============================================================================
// ❌ CHECK FOR OAUTH ERROR RESPONSE
// ============================================================================
// Microsoft returns errors in both 'error' and 'error_description' parameters.
// @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow#error-response
if (!empty($_GET['error'])) {
    $errorCode = SecurityUtils::sanitizeString($_GET['error']);
    $errorDesc = SecurityUtils::sanitizeString($_GET['error_description'] ?? '');

    // 📝 Log the denial/error
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            userID: $userID,
            activityType: 'cloud_export_failed',
            activityResult: 'failure',
            activityDetails: 'Excel Online OAuth denied or errored: ' . $errorCode . ' — ' . $errorDesc
        );
    }

    // 🔄 Map error codes to user-friendly messages
    $errorMessage = match ($errorCode) {
        'access_denied'              => 'You denied access to Excel Online. The export was cancelled.',
        'consent_required'           => 'Admin consent is required for this application. Please contact your Microsoft 365 administrator.',
        'interaction_required'       => 'Additional interaction is required. Please try again.',
        'invalid_grant'              => 'The authorisation has expired. Please try the export again.',
        'temporarily_unavailable'    => 'Microsoft services are temporarily unavailable. Please try again later.',
        'server_error'               => 'Microsoft encountered an internal error. Please try again later.',
        default                      => 'An error occurred during Excel Online authorisation: ' . $errorCode,
    };

    redirect('/api/v1/export-cloud/success/?status=error&message=' . urlencode($errorMessage));
    exit;
}

// ============================================================================
// 🔐 VALIDATE OAUTH STATE TOKEN (CSRF Protection)
// ============================================================================
// @see https://datatracker.ietf.org/doc/html/rfc6749#section-10.12
$receivedState = $_GET['state'] ?? '';
$expectedState = $_SESSION['oauth_export_state'] ?? '';

if (empty($receivedState) || empty($expectedState)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid OAuth state. Please try the export again.'
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// 🛡️ Timing-safe comparison
if (!hash_equals($expectedState, $receivedState)) {
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            userID: $userID,
            activityType: 'cloud_export_failed',
            activityResult: 'failure',
            activityDetails: 'OAuth state mismatch — possible CSRF attempt (Excel Online)',
            severity: 'high'
        );
    }

    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Security validation failed. Please try the export again.'
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================================
// ⏰ CHECK STATE TOKEN TTL (max 10 minutes)
// ============================================================================
$exportMeta = $_SESSION['cloud_export'] ?? null;

if (!$exportMeta || !isset($exportMeta['timestamp'])) {
    redirect('/api/v1/export-cloud/success/?status=error&message=' . urlencode('Export session expired. Please try again.'));
    exit;
}

$maxAge = 600; // ⏱️ 10 minutes
if ((time() - $exportMeta['timestamp']) > $maxAge) {
    unset($_SESSION['cloud_export'], $_SESSION['oauth_export_state']);

    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            userID: $userID,
            activityType: 'cloud_export_failed',
            activityResult: 'failure',
            activityDetails: 'OAuth state token expired (>10 min) for Excel Online export'
        );
    }

    redirect('/api/v1/export-cloud/success/?status=error&message=' . urlencode('The export session expired (>10 minutes). Please try again.'));
    exit;
}

// ============================================================================
// ✅ VALIDATE AUTHORIZATION CODE
// ============================================================================
$authCode = $_GET['code'] ?? '';

if (empty($authCode)) {
    redirect('/api/v1/export-cloud/success/?status=error&message=' . urlencode('No authorisation code received from Microsoft.'));
    exit;
}

// ============================================================================
// 🔄 EXCHANGE AUTHORIZATION CODE FOR ACCESS TOKEN
// ============================================================================
// POST to Microsoft's token endpoint with the auth code.
// @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow#request-an-access-token
try {
    // 🔑 Retrieve OAuth credentials from database settings
    $clientId     = getSetting('export.excel_online.client_id');
    $clientSecret = getSetting('export.excel_online.client_secret');
    $tenantId     = getSetting('export.excel_online.tenant_id') ?: 'common';

    if (empty($clientId) || empty($clientSecret)) {
        throw new RuntimeException('Excel Online OAuth credentials not configured in database settings.');
    }

    // 🔗 Build the same redirect URI that was used in the initial request
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https://'
        : 'http://';

    $siteUrl = getSetting('site.url');
    if (!empty($siteUrl)) {
        $callbackBase = rtrim($siteUrl, '/');
    } else {
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $callbackBase = $protocol . $host;
    }
    $redirectUri = $callbackBase . '/api/v1/export-cloud/callback/microsoft';

    // 📤 Token exchange request
    // @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow#request-an-access-token
    $tokenUrl = 'https://login.microsoftonline.com/' . urlencode($tenantId) . '/oauth2/v2.0/token';
    $tokenData = http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'code'          => $authCode,
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => $redirectUri,
        'scope'         => 'Files.ReadWrite openid profile offline_access',
    ]);

    // 📡 Send POST request using stream context (no cURL dependency)
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded' . "\r\n"
                       . 'Content-Length: ' . strlen($tokenData) . "\r\n",
            'content' => $tokenData,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $tokenResponse = @file_get_contents($tokenUrl, false, $context);

    if ($tokenResponse === false) {
        throw new RuntimeException('Failed to connect to Microsoft OAuth token endpoint.');
    }

    $tokenResult = json_decode($tokenResponse, true);

    // ❌ Check for token exchange errors
    if (isset($tokenResult['error'])) {
        $errorDesc = $tokenResult['error_description'] ?? $tokenResult['error'];
        throw new RuntimeException('Microsoft token exchange failed: ' . $errorDesc);
    }

    if (empty($tokenResult['access_token'])) {
        throw new RuntimeException('Microsoft token response missing access_token.');
    }

    $accessToken = $tokenResult['access_token'];

    // ============================================================================
    // 📂 LOAD EXPORT SERVICE
    // ============================================================================
    if (!class_exists('ExportService')) {
        $exportServicePath = dirname(__DIR__, 6)
            . DIRECTORY_SEPARATOR . 'private_html'
            . DIRECTORY_SEPARATOR . 'utils'
            . DIRECTORY_SEPARATOR . 'ExportService.php';

        if (!file_exists($exportServicePath)) {
            throw new RuntimeException('ExportService not found.');
        }

        require_once $exportServicePath;
    }

    // ============================================================================
    // 📊 CREATE THE EXCEL ONLINE WORKBOOK
    // ============================================================================
    $title = $exportMeta['title'] ?? 'SIGNula Data Export';
    $type  = $exportMeta['type'] ?? 'export';

    // 📋 Retrieve cached export data from session
    $exportData = $_SESSION['export_data'] ?? null;

    if (empty($exportData) || !is_array($exportData)) {
        $exportData = [
            ['Column' => 'No data available', 'Info' => 'The export data was not found in your session. Please try the export again from the original page.']
        ];
    }

    // 🚀 Call ExportService to upload a workbook to OneDrive/Excel Online
    // @see web/private_html/utils/ExportService.php — exportToExcelOnline()
    $workbookUrl = ExportService::exportToExcelOnline(
        $accessToken,
        $exportData,
        $title
    );

    // ============================================================================
    // ✅ SUCCESS — LOG AND REDIRECT
    // ============================================================================
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            userID: $userID,
            activityType: 'cloud_export_completed',
            activityResult: 'success',
            activityDetails: 'Excel Online export completed: "' . $title . '" → ' . $workbookUrl
        );
    }

    // 🧹 Clean up session data
    unset($_SESSION['cloud_export'], $_SESSION['oauth_export_state'], $_SESSION['export_data']);

    // 🔄 Redirect to success page
    redirect('/api/v1/export-cloud/success/?status=success&provider=excel_online&url=' . urlencode($workbookUrl) . '&title=' . urlencode($title));
    exit;

} catch (Exception $e) {
    // ============================================================================
    // ❌ ERROR HANDLER
    // ============================================================================
    if (class_exists('ErrorLogger')) {
        ErrorLogger::log($e);
    } else {
        error_log('SIGNula Excel Online callback error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }

    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            userID: $userID ?? null,
            activityType: 'cloud_export_failed',
            activityResult: 'failure',
            activityDetails: 'Excel Online export failed: ' . $e->getMessage()
        );
    }

    // 🧹 Clean up session
    unset($_SESSION['cloud_export'], $_SESSION['oauth_export_state'], $_SESSION['export_data']);

    redirect('/api/v1/export-cloud/success/?status=error&message=' . urlencode('Failed to create Excel Online workbook. Please try again.'));
    exit;
}
