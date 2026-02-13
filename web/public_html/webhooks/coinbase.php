<?php
/**
 * ============================================================================
 * 🟠 SIGNula - Coinbase Commerce Inbound Webhook Receiver
 * ============================================================================
 *
 * Purpose: Receive and process webhook events from Coinbase Commerce
 * PHP Version: 8.3+
 *
 * This endpoint is publicly accessible (no auth required) — Coinbase Commerce
 * sends POST requests here when cryptocurrency payment events occur. All
 * requests are verified using HMAC-SHA256 signature before processing.
 *
 * Webhook URL: https://account.signula.id/webhooks/coinbase
 *
 * Handled Events:
 * - charge:confirmed → Payment confirmed on blockchain
 * - charge:failed → Payment failed or expired
 * - charge:delayed → Underpaid or delayed confirmation
 * - charge:pending → Payment detected, awaiting confirmations
 * - charge:resolved → Previously unresolved charge resolved
 *
 * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/webhooks (Coinbase Webhooks)
 * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/webhooks-security (Security)
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.0.0
 * @since      2.3.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ⏱️ Track processing time for performance monitoring
$webhookStartTime = hrtime(true);

// 🚀 Bootstrap the application
$configPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Configuration unavailable']);
    exit;
}
require_once $configPath;

// 📚 Load required classes
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'CoinbaseProvider.php';

// 🔒 Only accept POST requests (Coinbase always sends POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// 📦 Read raw request body
$rawPayload = file_get_contents('php://input');

// 🔍 Get the Coinbase signature header
// @see https://docs.cdp.coinbase.com/commerce-onchain/docs/webhooks-security
$signatureHeader = $_SERVER['HTTP_X_CC_WEBHOOK_SIGNATURE'] ?? '';

// 🌐 Capture request metadata for logging
$requestIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$requestUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Coinbase-Webhook';

$db = Database::getInstance();

try {
    // ========================================================================
    // 🔐 STEP 1: Validate request has required data
    // ========================================================================

    if (empty($rawPayload)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Empty request body']);
        exit;
    }

    if (empty($signatureHeader)) {
        logInboundWebhook($db, 'coinbase', 'unknown', 'missing_signature_' . time(), $rawPayload, '', false, 'failed', 'Missing X-CC-Webhook-Signature header', 400, $requestIP, $requestUserAgent, $webhookStartTime);

        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing signature header']);
        exit;
    }

    // ========================================================================
    // 🔐 STEP 2: Verify webhook signature (HMAC-SHA256)
    // ========================================================================

    $signatureValid = CoinbaseProvider::verifyWebhookSignature($rawPayload, $signatureHeader);

    if (!$signatureValid) {
        logInboundWebhook($db, 'coinbase', 'unknown', 'invalid_signature_' . time(), $rawPayload, $signatureHeader, false, 'failed', 'Coinbase webhook signature verification failed', 401, $requestIP, $requestUserAgent, $webhookStartTime);

        ActivityLogger::log(
            null,
            'webhook_signature_failed',
            'security',
            'warning',
            'Coinbase Commerce webhook signature verification failed from IP: ' . $requestIP,
            [
                'ip_address' => $requestIP,
                'user_agent' => $requestUserAgent,
                'provider' => 'coinbase'
            ]
        );

        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }

    // ========================================================================
    // 📦 STEP 3: Parse the event payload
    // ========================================================================

    $event = json_decode($rawPayload, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        logInboundWebhook($db, 'coinbase', 'unknown', 'invalid_json_' . time(), $rawPayload, $signatureHeader, true, 'failed', 'Invalid JSON: ' . json_last_error_msg(), 400, $requestIP, $requestUserAgent, $webhookStartTime);

        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }

    // 🔍 Extract event metadata
    // Coinbase Commerce uses: { "id": "...", "type": "charge:confirmed", "data": {...} }
    $eventType = $event['event']['type'] ?? ($event['type'] ?? 'unknown');
    $eventID = $event['event']['id'] ?? ($event['id'] ?? ('coinbase_' . time()));

    // ========================================================================
    // 🔄 STEP 4: Check for duplicate events (idempotency)
    // ========================================================================

    $existingWebhook = Database::fetchOne(
        "SELECT webhookID, status FROM tblInboundWebhooks WHERE provider = ? AND eventID = ? AND status = 'processed'",
        ['coinbase', $eventID],
        'ss'
    );

    if ($existingWebhook !== null) {
        logInboundWebhook($db, 'coinbase', $eventType, $eventID, $rawPayload, $signatureHeader, true, 'duplicate', 'Event already processed (webhookID: ' . $existingWebhook['webhookID'] . ')', 200, $requestIP, $requestUserAgent, $webhookStartTime);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'already_processed']);
        exit;
    }

    // ========================================================================
    // 📥 STEP 5: Log the webhook before processing
    // ========================================================================

    $webhookID = logInboundWebhook($db, 'coinbase', $eventType, $eventID, $rawPayload, $signatureHeader, true, 'pending', null, null, $requestIP, $requestUserAgent, $webhookStartTime);

    // ========================================================================
    // ⚙️ STEP 6: Process the event
    // ========================================================================

    // 📦 Coinbase nests event data under 'event' key in newer API versions
    $eventData = $event['event'] ?? $event;
    $result = CoinbaseProvider::handleWebhookEvent($eventData);

    // ========================================================================
    // ✅ STEP 7: Update webhook log with result
    // ========================================================================

    $processingTimeMs = (int) ((hrtime(true) - $webhookStartTime) / 1_000_000);

    if ($result['success'] ?? false) {
        Database::query(
            "UPDATE tblInboundWebhooks SET status = 'processed', processedAt = NOW(), httpStatusReturned = 200, processingTimeMs = ? WHERE webhookID = ?",
            [$processingTimeMs, $webhookID],
            'ii'
        );

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'processed', 'action' => $result['action'] ?? 'handled']);

    } else {
        $errorMsg = $result['error'] ?? 'Unknown processing error';
        $httpStatus = ($result['retry'] ?? false) ? 500 : 200;

        Database::query(
            "UPDATE tblInboundWebhooks SET status = 'failed', errorMessage = ?, httpStatusReturned = ?, processingTimeMs = ? WHERE webhookID = ?",
            [substr($errorMsg, 0, 1000), $httpStatus, $processingTimeMs, $webhookID],
            'siii'
        );

        ActivityLogger::log(
            null,
            'webhook_processing_failed',
            'payment',
            'error',
            'Coinbase webhook processing failed: ' . $eventType . ' — ' . $errorMsg,
            [
                'event_type' => $eventType,
                'event_id' => $eventID,
                'error' => $errorMsg,
                'provider' => 'coinbase'
            ]
        );

        http_response_code($httpStatus);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Processing failed']);
    }

} catch (\Throwable $e) {
    // ========================================================================
    // 🚨 STEP 8: Handle unexpected errors
    // ========================================================================

    $processingTimeMs = (int) ((hrtime(true) - $webhookStartTime) / 1_000_000);

    ActivityLogger::log(
        null,
        'webhook_exception',
        'payment',
        'critical',
        'Coinbase webhook endpoint exception: ' . $e->getMessage(),
        [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'provider' => 'coinbase'
        ]
    );

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal server error']);
}


// ============================================================================
// 🛠️ HELPER FUNCTIONS
// ============================================================================

/**
 * 📥 Log an inbound webhook event to tblInboundWebhooks
 *
 * @param Database $db Database instance
 * @param string $provider Provider name
 * @param string $eventType Event type string
 * @param string $eventID Unique event identifier
 * @param string $payload Raw JSON payload
 * @param string $signature Signature header value
 * @param bool $verified Whether signature verification passed
 * @param string $status Processing status
 * @param string|null $errorMessage Error description (if any)
 * @param int|null $httpStatus HTTP status code returned
 * @param string $ipAddress Sender IP address
 * @param string $userAgent Sender User-Agent
 * @param int|float $startTime hrtime start for processing time calculation
 * @return int Inserted webhook ID
 */
function logInboundWebhook(
    Database $db,
    string $provider,
    string $eventType,
    string $eventID,
    string $payload,
    string $signature,
    bool $verified,
    string $status,
    ?string $errorMessage,
    ?int $httpStatus,
    string $ipAddress,
    string $userAgent,
    int|float $startTime
): int {
    $processingTimeMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

    Database::query(
        "INSERT INTO tblInboundWebhooks
            (provider, eventType, eventID, payload, signature, verified, status, errorMessage, httpStatusReturned, processingTimeMs, ipAddress, userAgent, processedAt)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($status === 'processed' ? 'NOW()' : 'NULL') . ")",
        [
            $provider,
            substr($eventType, 0, 100),
            substr($eventID, 0, 255),
            $payload,
            substr($signature, 0, 512),
            $verified ? 1 : 0,
            $status,
            $errorMessage !== null ? substr($errorMessage, 0, 1000) : null,
            $httpStatus,
            $processingTimeMs,
            substr($ipAddress, 0, 45),
            substr($userAgent, 0, 500)
        ],
        'sssssississs'
    );

    return Database::getInstance()->getLastInsertId();
}
