<?php
/**
 * ============================================================================
 * 🔐 SIGNula - WebAuthn Authentication Options API
 * ============================================================================
 *
 * Purpose: Generate WebAuthn authentication options
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

// 🛡️ CAP-API Bucket B (#3): this is the UNAUTHENTICATED login surface — it
// previously bypassed the central rate limiter entirely, allowing
// credential-enumeration probing of the `email` parameter at unlimited
// volume. Apply the SAME central limiter the /api/v1 router enforces.
// Fails OPEN on any internal limiter error; a genuine over-limit request
// still gets HTTP 429 exactly like a router-handled endpoint.
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'RateLimitMiddleware.php';
RateLimitMiddleware::enforceStandalone();

try {
    // 📝 Get request data
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    $email = $input['email'] ?? null;

    // 🎯 Generate authentication options
    $handler = new WebAuthnHandler();
    $options = $handler->generateAuthenticationOptions($email);

    echo json_encode([
        'success' => true,
        'options' => $options
    ]);

} catch (Exception $e) {
    error_log("WebAuthn authentication options error: " . $e->getMessage());
    http_response_code(500);
    // 🛡️ CAP-API Bucket B (#1): canonical `message`/`errors`/`meta` keys
    // ADDED alongside the pre-existing `error` string — see
    // web/private_html/api/Response.php for the standard envelope.
    echo json_encode([
        'success' => false,
        'error' => 'Failed to generate authentication options',
        'message' => 'Failed to generate authentication options',
        'errors' => ['Failed to generate authentication options'],
        'meta' => [
            'timestamp' => gmdate('c'),
            'version' => 'v1',
            'request_id' => bin2hex(random_bytes(16)),
        ],
    ]);
}
