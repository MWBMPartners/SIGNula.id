<?php
/**
 * ============================================================================
 * 🔗 SIGNula - Email Webhook Endpoint
 * ============================================================================
 *
 * Purpose: Receive webhook callbacks from email providers
 * PHP Version: 8.3+
 *
 * Supported Providers:
 * - SendGrid
 * - Mailgun
 * - Postmark
 * - Amazon SES (via SNS)
 *
 * URL Format:
 * - SendGrid: https://yourdomain.com/webhooks/email?provider=sendgrid
 * - Mailgun: https://yourdomain.com/webhooks/email?provider=mailgun
 * - Postmark: https://yourdomain.com/webhooks/email?provider=postmark
 * - Amazon SES: https://yourdomain.com/webhooks/email?provider=amazon_ses
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📚 Load webhook handler
require_once SIGNULA_ROOT . '/_includes/email/EmailWebhookHandler.php';

// 📝 Log incoming webhook
error_log("Webhook received: " . $_SERVER['REQUEST_URI']);

try {
    // 🔍 Get provider from query string
    $provider = $_GET['provider'] ?? '';

    if (empty($provider)) {
        http_response_code(400);
        die(json_encode(['error' => 'Provider parameter required']));
    }

    // 📦 Get request data
    $rawBody = file_get_contents('php://input');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    // 🔍 Parse body based on content type
    if (str_contains($contentType, 'application/json')) {
        $data = json_decode($rawBody, true);
    } else {
        parse_str($rawBody, $data);
        // Also check POST data
        if (empty($data)) {
            $data = $_POST;
        }
    }

    // 📋 Get headers
    $headers = getallheaders();

    // 🔧 Create webhook handler
    $handler = new EmailWebhookHandler($provider, $data, verbose: false);

    // 🔐 Verify signature
    $signatureValid = $handler->verifySignature($headers, $rawBody);

    if (!$signatureValid) {
        error_log("Webhook signature verification failed for provider: {$provider}");
        http_response_code(403);
        die(json_encode(['error' => 'Invalid signature']));
    }

    // 🔄 Process webhook
    $result = $handler->process();

    if ($result['success']) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'events_processed' => $result['events_processed']
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $result['error']
        ]);
    }

} catch (Exception $e) {
    error_log("Webhook processing error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
