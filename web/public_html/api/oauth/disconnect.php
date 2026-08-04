<?php
/**
 * ============================================================================
 * 🔌 SIGNula - OAuth Account Disconnect API
 * ============================================================================
 *
 * Purpose: API endpoint to disconnect OAuth-connected email accounts
 * PHP Version: 8.3+
 *
 * Handles:
 * - Token revocation
 * - Account disconnection
 * - Security validation
 * - Activity logging
 *
 * @package    SIGNula
 * @subpackage API
 * @version    2.0.0
 * ============================================================================
 */

// 🚀 Initialize SIGNula
//
// 🐛 CAP-API Bucket B prerequisite fix: this file previously required
// `private_html/bootstrap.php`, which does not exist anywhere in this
// repository — EVERY request to this endpoint fataled with "Failed opening
// required ... bootstrap.php" before a single line of business logic ran.
// Corrected to load `config.php` the SAME way every sibling standalone
// endpoint does (webauthn/*.php, v1/export/index.php, ...); config.php
// itself defines SIGNULA_INIT (see web/_config/config.php line ~25), so the
// explicit define() below is now redundant but harmless (guarded define).
if (!defined('SIGNULA_INIT')) {
    define('SIGNULA_INIT', true);
}
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📋 Set JSON response header
header('Content-Type: application/json');

// 🛡️ CAP-API Bucket B (#3): apply the SAME central rate limiter the
// /api/v1 router enforces to this standalone endpoint (previously bypassed
// entirely). Fails OPEN on any internal limiter error; a genuine
// over-limit request still gets HTTP 429 exactly like a router-handled
// endpoint.
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'RateLimitMiddleware.php';
RateLimitMiddleware::enforceStandalone();

/**
 * 🔀 Emit a JSON error response in the canonical superset shape (CAP-API
 * Bucket B, item #1) — keeps the pre-existing `error` string key AND adds
 * `message`/`errors`/`meta`, then exits.
 *
 * @param int    $statusCode HTTP status code
 * @param string $message    Human-readable error message
 * @return never
 */
function emitDisconnectError(int $statusCode, string $message): never
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'message' => $message,
        'errors' => [$message],
        'meta' => [
            'timestamp' => gmdate('c'),
            'version' => 'v1',
            'request_id' => bin2hex(random_bytes(16)),
        ],
    ]);
    exit;
}

try {
    // ✅ Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        emitDisconnectError(405, 'Method not allowed');
    }

    // 🔐 Require authentication
    //
    // 🐛 CAP-API Bucket B prerequisite fix: `isUserLoggedIn()` and
    // `getCurrentUserID()` are NOT defined anywhere in this codebase as
    // global functions (only `Auth::isAuthenticated()` /
    // `Auth::getCurrentUserID()` exist) — every call here previously
    // fataled with "Call to undefined function". Corrected to the real,
    // working Auth:: methods, matching the identical pattern already used
    // successfully in web/public_html/api/v1/export/index.php.
    if (!Auth::isAuthenticated()) {
        emitDisconnectError(401, 'Authentication required');
    }

    $userID = Auth::getCurrentUserID();

    // 📥 Parse JSON request body
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        emitDisconnectError(400, 'Invalid JSON in request body');
    }

    // ✅ Validate required parameters
    if (empty($data['provider'])) {
        emitDisconnectError(400, 'Missing required parameter: provider');
    }

    if (empty($data['mailboxEmail'])) {
        emitDisconnectError(400, 'Missing required parameter: mailboxEmail');
    }

    $provider = $data['provider'];
    $mailboxEmail = $data['mailboxEmail'];

    // 🔍 Validate provider
    $allowedProviders = ['microsoft_graph', 'google_workspace', 'gmail_api'];
    if (!in_array($provider, $allowedProviders)) {
        emitDisconnectError(400, 'Invalid provider');
    }

    // 📧 Validate email format
    if (!filter_var($mailboxEmail, FILTER_VALIDATE_EMAIL)) {
        emitDisconnectError(400, 'Invalid email address format');
    }

    // 🔍 Load OAuth Token Manager
    require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'OAuthTokenManager.php';

    // 🔍 Verify token belongs to current user (security check)
    $existingToken = OAuthTokenManager::getToken($userID, $provider, $mailboxEmail);

    if (!$existingToken) {
        emitDisconnectError(404, 'Connected account not found');
    }

    // 🗑️ Revoke token
    $result = OAuthTokenManager::revokeToken($userID, $provider, $mailboxEmail);

    if ($result) {
        // 📝 Log activity
        //
        // 🐛 CAP-API Bucket B prerequisite fix: ActivityLogger::log()'s real
        // signature is (?int $userID, string $activityType, string
        // $category = 'other', string $severity = 'info', string
        // $description = '', ...) — it has NO `$activityResult`/
        // `$activityDetails` parameters. The named-argument calls below
        // previously referenced those non-existent names, which PHP 8
        // rejects with a fatal "Unknown named parameter" error — so this
        // log call (and therefore the ENTIRE success/failure/error branch
        // that reached it) always fataled instead of completing. Corrected
        // to the real parameter names.
        ActivityLogger::log(
            userID: $userID,
            activityType: 'oauth_email_disconnected',
            category: 'security',
            severity: 'info',
            description: "Disconnected {$provider} email account: {$mailboxEmail}"
        );

        // ✅ Success response
        echo json_encode([
            'success' => true,
            'message' => 'Email account disconnected successfully'
        ]);
    } else {
        // 📝 Log error (see prerequisite-fix note above)
        ActivityLogger::log(
            userID: $userID,
            activityType: 'oauth_email_disconnect_failed',
            category: 'security',
            severity: 'warning',
            description: "Failed to disconnect {$provider} email account: {$mailboxEmail}"
        );

        // ❌ Revocation failed
        emitDisconnectError(500, 'Failed to disconnect account');
    }

} catch (Exception $e) {
    // ❌ Error handling
    error_log('OAuth disconnect API error: ' . $e->getMessage());

    // 📝 Log error (see prerequisite-fix note above)
    if (isset($userID)) {
        ActivityLogger::log(
            userID: $userID,
            activityType: 'oauth_email_disconnect_error',
            category: 'security',
            severity: 'error',
            description: 'Exception: ' . $e->getMessage()
        );
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'debug' => (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? $e->getMessage() : null,
        // 🛡️ CAP-API Bucket B (#1): canonical keys ADDED alongside the
        // pre-existing `error` string.
        'message' => 'Internal server error',
        'errors' => ['Internal server error'],
        'meta' => [
            'timestamp' => gmdate('c'),
            'version' => 'v1',
            'request_id' => bin2hex(random_bytes(16)),
        ],
    ]);
}
