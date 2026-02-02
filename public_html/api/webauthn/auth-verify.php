<?php
/**
 * ============================================================================
 * 🔐 SIGNula - WebAuthn Authentication Verification API
 * ============================================================================
 *
 * Purpose: Verify WebAuthn authentication assertion
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📚 Load required classes
require_once SIGNULA_ROOT . '/_includes/auth/WebAuthnHandler.php';

// 🔒 Set JSON header
header('Content-Type: application/json');

try {
    // 📝 Get request data
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (empty($input['credential'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing credential data'
        ]);
        exit;
    }

    $credentialData = $input['credential'];

    // ✅ Verify authentication
    $handler = new WebAuthnHandler();
    $result = $handler->verifyAuthentication($credentialData);

    if ($result['success']) {
        // 🔐 Create session
        $_SESSION['userID'] = $result['userID'];
        $_SESSION['loginMethod'] = 'webauthn';
        $_SESSION['loginTime'] = time();

        echo json_encode([
            'success' => true,
            'message' => 'Authentication successful',
            'redirectUrl' => '/dashboard'
        ]);
    } else {
        http_response_code(401);
        echo json_encode($result);
    }

} catch (Exception $e) {
    error_log("WebAuthn authentication verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Authentication verification failed'
    ]);
}
