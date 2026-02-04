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
define('SIGNULA_INIT', true);
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'bootstrap.php';

// 📋 Set JSON response header
header('Content-Type: application/json');

try {
    // ✅ Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed'
        ]);
        exit;
    }

    // 🔐 Require authentication
    if (!isUserLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Authentication required'
        ]);
        exit;
    }

    $userID = getCurrentUserID();

    // 📥 Parse JSON request body
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON in request body'
        ]);
        exit;
    }

    // ✅ Validate required parameters
    if (empty($data['provider'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing required parameter: provider'
        ]);
        exit;
    }

    if (empty($data['mailboxEmail'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing required parameter: mailboxEmail'
        ]);
        exit;
    }

    $provider = $data['provider'];
    $mailboxEmail = $data['mailboxEmail'];

    // 🔍 Validate provider
    $allowedProviders = ['microsoft_graph', 'google_workspace', 'gmail_api'];
    if (!in_array($provider, $allowedProviders)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid provider'
        ]);
        exit;
    }

    // 📧 Validate email format
    if (!filter_var($mailboxEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid email address format'
        ]);
        exit;
    }

    // 🔍 Load OAuth Token Manager
    require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'OAuthTokenManager.php';

    // 🔍 Verify token belongs to current user (security check)
    $existingToken = OAuthTokenManager::getToken($userID, $provider, $mailboxEmail);

    if (!$existingToken) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Connected account not found'
        ]);
        exit;
    }

    // 🗑️ Revoke token
    $result = OAuthTokenManager::revokeToken($userID, $provider, $mailboxEmail);

    if ($result) {
        // 📝 Log activity
        ActivityLogger::log(
            userID: $userID,
            activityType: 'oauth_email_disconnected',
            activityResult: 'success',
            activityDetails: "Disconnected {$provider} email account: {$mailboxEmail}"
        );

        // ✅ Success response
        echo json_encode([
            'success' => true,
            'message' => 'Email account disconnected successfully'
        ]);
    } else {
        // ❌ Revocation failed
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to disconnect account'
        ]);

        // 📝 Log error
        ActivityLogger::log(
            userID: $userID,
            activityType: 'oauth_email_disconnect_failed',
            activityResult: 'failure',
            activityDetails: "Failed to disconnect {$provider} email account: {$mailboxEmail}"
        );
    }

} catch (Exception $e) {
    // ❌ Error handling
    error_log('OAuth disconnect API error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'debug' => ENVIRONMENT === 'development' ? $e->getMessage() : null
    ]);

    // 📝 Log error
    if (isset($userID)) {
        ActivityLogger::log(
            userID: $userID,
            activityType: 'oauth_email_disconnect_error',
            activityResult: 'error',
            activityDetails: 'Exception: ' . $e->getMessage()
        );
    }
}
