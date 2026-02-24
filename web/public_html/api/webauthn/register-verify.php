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

try {
    // 🔐 Check if user is logged in
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Authentication required'
        ]);
        exit;
    }

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
        echo json_encode($result);
    }

} catch (Exception $e) {
    error_log("WebAuthn registration verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Registration verification failed'
    ]);
}
