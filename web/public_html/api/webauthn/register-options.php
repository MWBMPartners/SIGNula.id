<?php
/**
 * ============================================================================
 * 🔐 SIGNula - WebAuthn Registration Options API
 * ============================================================================
 *
 * Purpose: Generate WebAuthn registration options
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📚 Load required classes
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'WebAuthnHandler.php';

// 🔒 Set JSON header
header('Content-Type: application/json');

// 🛡️ CAP-API Bucket B (#3): apply the SAME central rate limiter the
// /api/v1 router enforces to this standalone endpoint (previously bypassed
// entirely). Fails OPEN on any internal limiter error; a genuine
// over-limit request still gets HTTP 429 exactly like a router-handled
// endpoint.
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'RateLimitMiddleware.php';
RateLimitMiddleware::enforceStandalone();

try {
    // 🔐 Check if user is logged in
    if (!isLoggedIn()) {
        http_response_code(401);
        // 🛡️ CAP-API Bucket B (#1): canonical `message`/`errors`/`meta` keys
        // ADDED alongside the pre-existing `error` string.
        echo json_encode([
            'success' => false,
            'error' => 'Authentication required',
            'message' => 'Authentication required',
            'errors' => ['Authentication required'],
            'meta' => [
                'timestamp' => gmdate('c'),
                'version' => 'v1',
                'request_id' => bin2hex(random_bytes(16)),
            ],
        ]);
        exit;
    }

    $userID = $_SESSION['userID'];

    // 🎯 Generate registration options
    $handler = new WebAuthnHandler();
    $options = $handler->generateRegistrationOptions($userID);

    echo json_encode([
        'success' => true,
        'options' => $options
    ]);

} catch (Exception $e) {
    error_log("WebAuthn registration options error: " . $e->getMessage());
    http_response_code(500);
    // 🛡️ CAP-API Bucket B (#1): canonical `message`/`errors`/`meta` keys
    // ADDED alongside the pre-existing `error` string.
    echo json_encode([
        'success' => false,
        'error' => 'Failed to generate registration options',
        'message' => 'Failed to generate registration options',
        'errors' => ['Failed to generate registration options'],
        'meta' => [
            'timestamp' => gmdate('c'),
            'version' => 'v1',
            'request_id' => bin2hex(random_bytes(16)),
        ],
    ]);
}
