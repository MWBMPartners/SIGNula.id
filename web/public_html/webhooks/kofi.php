<?php
/**
 * ============================================================================
 * ☕ SIGNula - Ko-fi Inbound Webhook Receiver
 * ============================================================================
 *
 * Purpose: Receive and process webhook events from Ko-fi
 * PHP Version: 8.3+
 *
 * This endpoint is publicly accessible (no auth required) — Ko-fi
 * sends POST requests here when donation, subscription, and shop order
 * events occur. All requests are verified using the verification_token
 * field inside the decoded JSON data payload.
 *
 * Webhook URL: https://account.signula.id/webhooks/kofi
 *
 * Ko-fi sends a POST request with Content-Type: application/x-www-form-urlencoded
 * The body contains a `data` parameter with a JSON-encoded string.
 *
 * Handled Events:
 * - Donation         → One-time donation received
 * - Subscription     → Recurring subscription payment received
 * - Shop Order       → Shop item purchased
 *
 * @see https://ko-fi.com/manage/webhooks (Ko-fi Webhooks)
 * @see https://help.ko-fi.com/hc/en-us/articles/Webhooks (Webhook Documentation)
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
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'KofiProvider.php';

// 🔒 Only accept POST requests (Ko-fi always sends POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// 📦 Read raw POST data — Ko-fi sends form-encoded data with a 'data' field
// The 'data' field contains a JSON string that needs to be decoded
$rawPayload = file_get_contents('php://input');

// 🔍 Also check $_POST['data'] as Ko-fi sends form-encoded data
// (application/x-www-form-urlencoded with a `data` parameter containing JSON)
$kofiDataJson = $_POST['data'] ?? '';

// 🌐 Capture request metadata for logging
$requestIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$requestUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Ko-fi-Webhook';

$db = Database::getInstance();

try {
    // ========================================================================
    // 🔐 STEP 1: Validate request has required data
    // ========================================================================

    if (empty($rawPayload) && empty($kofiDataJson)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Empty request body']);
        exit;
    }

    if (empty($kofiDataJson)) {
        // 📝 If $_POST['data'] is empty, attempt to parse the raw payload manually
        // This handles edge cases where PHP may not populate $_POST correctly
        parse_str($rawPayload, $parsedBody);
        $kofiDataJson = $parsedBody['data'] ?? '';
    }

    if (empty($kofiDataJson)) {
        logInboundWebhook($db, 'kofi', 'unknown', 'missing_data_' . time(), $rawPayload, '', false, 'failed', 'Missing data field in Ko-fi webhook payload', 400, $requestIP, $requestUserAgent, $webhookStartTime);

        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing data field']);
        exit;
    }

    // ========================================================================
    // 📦 STEP 2: Decode the Ko-fi data JSON string
    // ========================================================================

    $kofiData = json_decode($kofiDataJson, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        logInboundWebhook($db, 'kofi', 'unknown', 'invalid_json_' . time(), $rawPayload, '', false, 'failed', 'Invalid JSON in data field: ' . json_last_error_msg(), 400, $requestIP, $requestUserAgent, $webhookStartTime);

        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }

    // 🔍 Extract event metadata from the decoded Ko-fi data
    // Ko-fi provides: type (Donation, Subscription, Shop Order),
    // kofi_transaction_id, verification_token, and other fields
    $eventType = $kofiData['type'] ?? 'unknown';
    $eventID = $kofiData['kofi_transaction_id'] ?? ('kofi_' . time());
    $verificationToken = $kofiData['verification_token'] ?? '';

    // ========================================================================
    // 🔐 STEP 3: Verify the verification_token
    // ========================================================================
    // Ko-fi includes a verification_token in the data payload that must match
    // the token configured in your Ko-fi webhook settings. This is used instead
    // of an HMAC signature to authenticate that the request came from Ko-fi.
    // @see https://help.ko-fi.com/hc/en-us/articles/Webhooks

    if (empty($verificationToken)) {
        logInboundWebhook($db, 'kofi', $eventType, 'missing_token_' . time(), $rawPayload, '', false, 'failed', 'Missing verification_token in Ko-fi data payload', 400, $requestIP, $requestUserAgent, $webhookStartTime);

        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing verification token']);
        exit;
    }

    $tokenValid = KofiProvider::verifyWebhookToken($verificationToken);

    if (!$tokenValid) {
        logInboundWebhook($db, 'kofi', $eventType, 'invalid_token_' . time(), $rawPayload, $verificationToken, false, 'failed', 'Ko-fi webhook verification token mismatch', 401, $requestIP, $requestUserAgent, $webhookStartTime);

        ActivityLogger::log(
            null,
            'webhook_signature_failed',
            'security',
            'warning',
            'Ko-fi webhook verification token mismatch from IP: ' . $requestIP,
            [
                'ip_address' => $requestIP,
                'user_agent' => $requestUserAgent,
                'provider' => 'kofi'
            ]
        );

        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid verification token']);
        exit;
    }

    // ========================================================================
    // 🔄 STEP 4: Check for duplicate events (idempotency)
    // ========================================================================

    $existingWebhook = Database::fetchOne(
        "SELECT webhookID, status FROM tblInboundWebhooks WHERE provider = ? AND eventID = ? AND status = 'processed'",
        ['kofi', $eventID],
        'ss'
    );

    if ($existingWebhook !== null) {
        logInboundWebhook($db, 'kofi', $eventType, $eventID, $rawPayload, $verificationToken, true, 'duplicate', 'Event already processed (webhookID: ' . $existingWebhook['webhookID'] . ')', 200, $requestIP, $requestUserAgent, $webhookStartTime);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'already_processed']);
        exit;
    }

    // ========================================================================
    // 📥 STEP 5: Log the webhook before processing
    // ========================================================================

    $webhookID = logInboundWebhook($db, 'kofi', $eventType, $eventID, $rawPayload, $verificationToken, true, 'pending', null, null, $requestIP, $requestUserAgent, $webhookStartTime);

    // ========================================================================
    // ⚙️ STEP 6: Process the event
    // ========================================================================

    // ☕ Ko-fi data is already decoded — pass directly to the handler
    $result = KofiProvider::handleWebhookEvent($kofiData);

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
            'Ko-fi webhook processing failed: ' . $eventType . ' — ' . $errorMsg,
            [
                'event_type' => $eventType,
                'event_id' => $eventID,
                'error' => $errorMsg,
                'provider' => 'kofi'
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
        'Ko-fi webhook endpoint exception: ' . $e->getMessage(),
        [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'provider' => 'kofi'
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
 * @param string $payload Raw payload data
 * @param string $signature Verification token value
 * @param bool $verified Whether verification passed
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
