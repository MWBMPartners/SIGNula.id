<?php
/**
 * ============================================================================
 * 🔵 SIGNula - Stripe Inbound Webhook Receiver
 * ============================================================================
 *
 * Purpose: Receive and process webhook events from Stripe
 * PHP Version: 8.3+
 *
 * This endpoint is publicly accessible (no auth required) — Stripe sends
 * POST requests here when payment events occur. All requests are verified
 * using Stripe's webhook signature (HMAC-SHA256) before processing.
 *
 * Webhook URL: https://account.signula.id/webhooks/stripe
 *
 * Handled Events:
 * - checkout.session.completed → Complete payment + create subscription
 * - payment_intent.succeeded → Complete one-off payment
 * - payment_intent.payment_failed → Mark payment as failed
 * - invoice.paid → Renew subscription
 * - customer.subscription.updated → Update subscription status
 * - customer.subscription.deleted → Cancel subscription
 * - charge.refunded → Process refund
 *
 * @see https://docs.stripe.com/webhooks (Stripe Webhook Documentation)
 * @see https://docs.stripe.com/webhooks/signatures (Signature Verification)
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
// @see https://www.php.net/manual/en/function.dirname.php
$configPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Configuration unavailable']);
    exit;
}
require_once $configPath;

// 📚 Load required classes
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'StripeProvider.php';

// 🔒 Only accept POST requests (Stripe always sends POST)
// @see https://docs.stripe.com/webhooks#receive-event
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// 📦 Read raw request body (must be read before any output)
// @see https://www.php.net/manual/en/wrappers.php.php
$rawPayload = file_get_contents('php://input');

// 🔍 Get the Stripe signature header
// @see https://docs.stripe.com/webhooks/signatures
$signatureHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// 🌐 Capture request metadata for logging
$requestIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$requestUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Stripe-Webhook';

// 📝 Prepare database reference for webhook logging
$db = Database::getInstance();

try {
    // ========================================================================
    // 🔐 STEP 1: Validate request has required data
    // ========================================================================

    if (empty($rawPayload)) {
        // 🚫 Empty payload — reject immediately
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Empty request body']);
        exit;
    }

    if (empty($signatureHeader)) {
        // 🚫 No signature header — reject (possible non-Stripe request)
        // 📥 Log the unsigned request for security auditing
        logInboundWebhook($db, 'stripe', 'unknown', 'missing_signature', $rawPayload, '', false, 'failed', 'Missing Stripe-Signature header', 400, $requestIP, $requestUserAgent, $webhookStartTime);

        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing Stripe-Signature header']);
        exit;
    }

    // ========================================================================
    // 🔐 STEP 2: Verify webhook signature
    // ========================================================================

    // 🔑 Verify the webhook signature using StripeProvider
    $signatureValid = StripeProvider::verifyWebhookSignature($rawPayload, $signatureHeader);

    if (!$signatureValid) {
        // 🚫 Invalid signature — potential tampering or misconfigured secret
        logInboundWebhook($db, 'stripe', 'unknown', 'invalid_signature', $rawPayload, $signatureHeader, false, 'failed', 'Webhook signature verification failed', 401, $requestIP, $requestUserAgent, $webhookStartTime);

        // 📊 Log security event
        ActivityLogger::log(
            null,
            'webhook_signature_failed',
            'security',
            'warning',
            'Stripe webhook signature verification failed from IP: ' . $requestIP,
            [
                'ip_address' => $requestIP,
                'user_agent' => $requestUserAgent,
                'provider' => 'stripe'
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
        // 🚫 Invalid JSON payload
        logInboundWebhook($db, 'stripe', 'unknown', 'invalid_json', $rawPayload, $signatureHeader, true, 'failed', 'Invalid JSON: ' . json_last_error_msg(), 400, $requestIP, $requestUserAgent, $webhookStartTime);

        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }

    // 🔍 Extract event metadata
    $eventType = $event['type'] ?? 'unknown';
    $eventID = $event['id'] ?? 'unknown';

    // ========================================================================
    // 🔄 STEP 4: Check for duplicate events (idempotency)
    // ========================================================================

    // @see https://docs.stripe.com/webhooks#handle-duplicate-events
    $existingWebhook = Database::fetchOne(
        "SELECT webhookID, status FROM tblInboundWebhooks WHERE provider = ? AND eventID = ? AND status = 'processed'",
        ['stripe', $eventID],
        'ss'
    );

    if ($existingWebhook !== null) {
        // ✅ Already processed — return 200 to prevent Stripe retries
        logInboundWebhook($db, 'stripe', $eventType, $eventID, $rawPayload, $signatureHeader, true, 'duplicate', 'Event already processed (webhookID: ' . $existingWebhook['webhookID'] . ')', 200, $requestIP, $requestUserAgent, $webhookStartTime);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'already_processed']);
        exit;
    }

    // ========================================================================
    // 📥 STEP 5: Log the webhook before processing
    // ========================================================================

    $webhookID = logInboundWebhook($db, 'stripe', $eventType, $eventID, $rawPayload, $signatureHeader, true, 'pending', null, null, $requestIP, $requestUserAgent, $webhookStartTime);

    // ========================================================================
    // ⚙️ STEP 6: Process the event
    // ========================================================================

    $result = StripeProvider::handleWebhookEvent($event);

    // ========================================================================
    // ✅ STEP 7: Update webhook log with result
    // ========================================================================

    $processingTimeMs = (int) ((hrtime(true) - $webhookStartTime) / 1_000_000);

    if ($result['success'] ?? false) {
        // ✅ Successfully processed
        Database::query(
            "UPDATE tblInboundWebhooks SET status = 'processed', processedAt = NOW(), httpStatusReturned = 200, processingTimeMs = ? WHERE webhookID = ?",
            [$processingTimeMs, $webhookID],
            'ii'
        );

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'processed', 'action' => $result['action'] ?? 'handled']);

    } else {
        // ⚠️ Processing failed but signature was valid — log and return 200
        // (Returning 200 prevents Stripe from retrying for non-transient errors)
        $errorMsg = $result['error'] ?? 'Unknown processing error';
        $httpStatus = ($result['retry'] ?? false) ? 500 : 200;

        Database::query(
            "UPDATE tblInboundWebhooks SET status = 'failed', errorMessage = ?, httpStatusReturned = ?, processingTimeMs = ? WHERE webhookID = ?",
            [substr($errorMsg, 0, 1000), $httpStatus, $processingTimeMs, $webhookID],
            'siii'
        );

        // 📊 Log processing failure
        ActivityLogger::log(
            null,
            'webhook_processing_failed',
            'payment',
            'error',
            'Stripe webhook processing failed: ' . $eventType . ' — ' . $errorMsg,
            [
                'event_type' => $eventType,
                'event_id' => $eventID,
                'error' => $errorMsg,
                'provider' => 'stripe'
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

    // 📊 Log the exception
    ActivityLogger::log(
        null,
        'webhook_exception',
        'payment',
        'critical',
        'Stripe webhook endpoint exception: ' . $e->getMessage(),
        [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'provider' => 'stripe'
        ]
    );

    // 🔄 Return 500 so Stripe will retry
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
 * @param string $provider Provider name (stripe, paypal, coinbase)
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
