<?php
/**
 * ============================================================================
 * 📊 SIGNula - Google Sheets OAuth2 Callback Handler
 * ============================================================================
 *
 * Handles the OAuth2 callback from Google after the user authorises (or denies)
 * the application to create spreadsheets in their Google Sheets account.
 *
 * OAuth2 Flow (RFC 6749 §4.1):
 *   1. User clicks "Export to Google Sheets" → /api/v1/export-cloud/?format=google_sheets
 *   2. Endpoint stores metadata in session, redirects to Google consent screen
 *   3. User authorises → Google redirects here with ?code=AUTH_CODE&state=STATE
 *   4. This handler exchanges the code for an access token
 *   5. Calls ExportService::exportToGoogleSheets() to create the spreadsheet
 *   6. Redirects user to success page with spreadsheet URL
 *
 * Query Parameters (from Google):
 *   - code   (string)  Authorization code to exchange for access token
 *   - state  (string)  CSRF protection token (must match session value)
 *   - error  (string)  Error code if user denied or something went wrong
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage API\Export\Callback
 * @version    1.0.0
 * @link       https://developers.google.com/identity/protocols/oauth2/web-server#handlingresponse
 * @see        web/private_html/utils/ExportService.php — exportToGoogleSheets()
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
// Navigate up from web/public_html/api/v1/export-cloud/callback/google/ → web/_config/
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 6) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 🔒 METHOD VALIDATION — GET only (OAuth callbacks are always GET redirects)
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
// The user must still have an active session (they were logged in when they
// initiated the export). If the session expired during the OAuth flow, we
// cannot proceed.
// @see web/private_html/auth/Auth.php — Auth::isAuthenticated()
if (!Auth::isAuthenticated()) {
    // 🔄 Redirect to login with a return URL so they can retry after logging in
    $returnUrl = '/settings/usage';
    redirect('/login?return=' . urlencode($returnUrl) . '&error=session_expired');
    exit;
}

$userID = Auth::getCurrentUserID();

// ============================================================================
// ❌ CHECK FOR OAUTH ERROR RESPONSE
// ============================================================================
// If the user denied the application or Google returned an error, handle it
// gracefully instead of trying to exchange a non-existent code.
// @see https://developers.google.com/identity/protocols/oauth2/web-server#handlingresponse
if (!empty($_GET['error'])) {
    $errorCode = SecurityUtils::sanitizeString($_GET['error']);

    // 📝 Log the denial/error
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            userID: $userID,
            activityType: 'cloud_export_failed',
            activityResult: 'failure',
            activityDetails: 'Google Sheets OAuth denied or errored: ' . $errorCode
        );
    }

    // 🔄 Redirect to success page with error info
    $errorMessage = match ($errorCode) {
        'access_denied'           => 'You denied access to Google Sheets. The export was cancelled.',
        'invalid_scope'           => 'The requested permissions are not available. Please contact the administrator.',
        'server_error'            => 'Google encountered an internal error. Please try again later.',
        'temporarily_unavailable' => 'Google Sheets is temporarily unavailable. Please try again later.',
        default                   => 'An error occurred during Google Sheets authorisation: ' . $errorCode,
    };

    redirect('/api/v1/export-cloud/success/?status=error&message=' . urlencode($errorMessage));
    exit;
}

// ============================================================================
// 🔐 VALIDATE OAUTH STATE TOKEN (CSRF Protection)
// ============================================================================
// The state parameter prevents cross-site request forgery attacks in the
// OAuth flow. We must verify it matches what we stored in the session.
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

// 🛡️ Timing-safe comparison to prevent timing attacks on the state token
// @see https://www.php.net/manual/en/function.hash-equals.php
if (!hash_equals($expectedState, $receivedState)) {
    // 📝 Log potential CSRF attempt
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            userID: $userID,
            activityType: 'cloud_export_failed',
            activityResult: 'failure',
            activityDetails: 'OAuth state mismatch — possible CSRF attempt (Google Sheets)',
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
// Reject stale OAuth callbacks to prevent replay attacks.
$exportMeta = $_SESSION['cloud_export'] ?? null;

if (!$exportMeta || !isset($exportMeta['timestamp'])) {
    redirect('/api/v1/export-cloud/success/?status=error&message=' . urlencode('Export session expired. Please try again.'));
    exit;
}

$maxAge = 600; // ⏱️ 10 minutes
if ((time() - $exportMeta['timestamp']) > $maxAge) {
    // 🧹 Clean up expired session data
    unset($_SESSION['cloud_export'], $_SESSION['oauth_export_state']);

    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            userID: $userID,
            activityType: 'cloud_export_failed',
            activityResult: 'failure',
            activityDetails: 'OAuth state token expired (>10 min) for Google Sheets export'
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
    redirect('/api/v1/export-cloud/success/?status=error&message=' . urlencode('No authorisation code received from Google.'));
    exit;
}

// ============================================================================
// 🔄 EXCHANGE AUTHORIZATION CODE FOR ACCESS TOKEN
// ============================================================================
// POST to Google's token endpoint with the auth code to get an access token.
// @see https://developers.google.com/identity/protocols/oauth2/web-server#exchange-authorization-code
try {
    // 🔑 Retrieve OAuth credentials from database settings
    $clientId     = getSetting('export.google_sheets.client_id');
    $clientSecret = getSetting('export.google_sheets.client_secret');

    if (empty($clientId) || empty($clientSecret)) {
        throw new RuntimeException('Google Sheets OAuth credentials not configured in database settings.');
    }

    // 🔗 Build the same redirect URI that was used in the initial request
    // This MUST match exactly or Google will reject the token exchange.
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
    $redirectUri = $callbackBase . '/api/v1/export-cloud/callback/google';

    // 📤 Token exchange request
    // @see https://developers.google.com/identity/protocols/oauth2/web-server#exchange-authorization-code
    $tokenUrl = 'https://oauth2.googleapis.com/token';
    $tokenData = http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'code'          => $authCode,
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => $redirectUri,
    ]);

    // 📡 Send POST request using stream context (no cURL dependency)
    // @see https://www.php.net/manual/en/function.file-get-contents.php
    // @see https://www.php.net/manual/en/function.stream-context-create.php
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded' . "\r\n"
                       . 'Content-Length: ' . strlen($tokenData) . "\r\n",
            'content' => $tokenData,
            'timeout' => 30,
            'ignore_errors' => true, // 🔍 Get response body even on HTTP errors
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $tokenResponse = @file_get_contents($tokenUrl, false, $context);

    if ($tokenResponse === false) {
        throw new RuntimeException('Failed to connect to Google OAuth token endpoint.');
    }

    $tokenResult = json_decode($tokenResponse, true);

    // ❌ Check for token exchange errors
    if (isset($tokenResult['error'])) {
        $errorDesc = $tokenResult['error_description'] ?? $tokenResult['error'];
        throw new RuntimeException('Google token exchange failed: ' . $errorDesc);
    }

    if (empty($tokenResult['access_token'])) {
        throw new RuntimeException('Google token response missing access_token.');
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
    // 📊 CREATE THE GOOGLE SPREADSHEET
    // ============================================================================
    // Retrieve the export title from session metadata.
    // For the data, we use a placeholder dataset — in production the data would
    // come from a cached export or re-queried from the database based on the
    // export type stored in the session.
    $title = $exportMeta['title'] ?? 'SIGNula Data Export';
    $type  = $exportMeta['type'] ?? 'export';

    // 📋 Retrieve cached export data from session (stored by the initiating page)
    // Pages that trigger cloud export should store their data in $_SESSION['export_data']
    $exportData = $_SESSION['export_data'] ?? null;

    if (empty($exportData) || !is_array($exportData)) {
        // 🔄 If no cached data, create a placeholder to confirm the flow works
        // In production, this would re-query based on $type and user context
        $exportData = [
            ['Column' => 'No data available', 'Info' => 'The export data was not found in your session. Please try the export again from the original page.']
        ];
    }

    // 🚀 Call ExportService to create the spreadsheet in Google Sheets
    // @see web/private_html/utils/ExportService.php — exportToGoogleSheets()
    $spreadsheetUrl = ExportService::exportToGoogleSheets(
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
            activityDetails: 'Google Sheets export completed: "' . $title . '" → ' . $spreadsheetUrl
        );
    }

    // 🧹 Clean up session data (prevent replay)
    unset($_SESSION['cloud_export'], $_SESSION['oauth_export_state'], $_SESSION['export_data']);

    // 🔄 Redirect to success page with the spreadsheet URL
    redirect('/api/v1/export-cloud/success/?status=success&provider=google_sheets&url=' . urlencode($spreadsheetUrl) . '&title=' . urlencode($title));
    exit;

} catch (Exception $e) {
    // ============================================================================
    // ❌ ERROR HANDLER
    // ============================================================================
    // Log the full error details server-side; show a generic message to the user.
    if (class_exists('ErrorLogger')) {
        ErrorLogger::log($e);
    } else {
        error_log('SIGNula Google Sheets callback error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }

    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            userID: $userID ?? null,
            activityType: 'cloud_export_failed',
            activityResult: 'failure',
            activityDetails: 'Google Sheets export failed: ' . $e->getMessage()
        );
    }

    // 🧹 Clean up session
    unset($_SESSION['cloud_export'], $_SESSION['oauth_export_state'], $_SESSION['export_data']);

    redirect('/api/v1/export-cloud/success/?status=error&message=' . urlencode('Failed to create Google Sheets spreadsheet. Please try again.'));
    exit;
}
