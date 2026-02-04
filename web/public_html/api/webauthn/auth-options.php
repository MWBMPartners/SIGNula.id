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
require_once SIGNULA_ROOT . '/private_html/auth/WebAuthnHandler.php';

// 🔒 Set JSON header
header('Content-Type: application/json');

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
    echo json_encode([
        'success' => false,
        'error' => 'Failed to generate authentication options'
    ]);
}
