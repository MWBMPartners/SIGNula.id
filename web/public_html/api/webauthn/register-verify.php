<?php
/**
 * ============================================================================
 * 🔐 SIGNula - WebAuthn Registration Verification API
 * ============================================================================
 *
 * Purpose: Verify and store WebAuthn credential
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
        // 🛡️ CAP-API Bucket B (#1): canonical keys ADDED alongside the
        // pre-existing `error` string.
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

    // 📝 Get request data
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (empty($input['credential'])) {
        http_response_code(400);
        // 🛡️ CAP-API Bucket B (#1): canonical keys ADDED alongside the
        // pre-existing `error` string.
        echo json_encode([
            'success' => false,
            'error' => 'Missing credential data',
            'message' => 'Missing credential data',
            'errors' => ['Missing credential data'],
            'meta' => [
                'timestamp' => gmdate('c'),
                'version' => 'v1',
                'request_id' => bin2hex(random_bytes(16)),
            ],
        ]);
        exit;
    }

    $userID = $_SESSION['userID'];
    $credentialData = $input['credential'];
    $deviceName = $input['deviceName'] ?? null;

    // ✅ Verify and store credential
    $handler = new WebAuthnHandler();
    $result = $handler->verifyRegistration($userID, $credentialData, $deviceName);

    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(400);
        // 🛡️ CAP-API Bucket B (#1): $result is the handler's own
        // {success:false, error: "..."} shape — augment it with the
        // canonical keys as a superset without altering its existing keys.
        $failureMessage = is_array($result) && isset($result['error']) && is_string($result['error'])
            ? $result['error']
            : 'Registration verification failed';
        echo json_encode(array_merge($result, [
            'message' => $failureMessage,
            'errors' => [$failureMessage],
            'meta' => [
                'timestamp' => gmdate('c'),
                'version' => 'v1',
                'request_id' => bin2hex(random_bytes(16)),
            ],
        ]));
    }

} catch (Exception $e) {
    error_log("WebAuthn registration verification error: " . $e->getMessage());
    http_response_code(500);
    // 🛡️ CAP-API Bucket B (#1): canonical keys ADDED alongside the
    // pre-existing `error` string.
    echo json_encode([
        'success' => false,
        'error' => 'Registration verification failed',
        'message' => 'Registration verification failed',
        'errors' => ['Registration verification failed'],
        'meta' => [
            'timestamp' => gmdate('c'),
            'version' => 'v1',
            'request_id' => bin2hex(random_bytes(16)),
        ],
    ]);
}
