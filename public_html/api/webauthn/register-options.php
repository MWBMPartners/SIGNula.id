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
require_once SIGNULA_ROOT . '/_includes/auth/WebAuthnHandler.php';

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
    echo json_encode([
        'success' => false,
        'error' => 'Failed to generate registration options'
    ]);
}
