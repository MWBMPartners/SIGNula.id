<?php
/**
 * ============================================================================
 * 🔗 SIGNula - Outbound Webhook Manager
 * ============================================================================
 *
 * Purpose: Deliver signed webhooks to partner endpoints with HMAC-SHA256
 * PHP Version: 8.3+
 *
 * Features:
 * - HMAC-SHA256 signature generation for every delivery
 * - Exponential backoff retry with configurable policy
 * - Per-partner endpoint management (create, update, disable)
 * - Delivery logging with full request/response audit trail
 * - Auto-disable endpoints after consecutive failures
 * - Rate limiting per endpoint
 * - Idempotent event IDs to prevent duplicate processing
 *
 * Signature Scheme:
 *   Header: X-SIGNula-Signature: sha256=<hex_hmac>
 *   Computed: hash_hmac('sha256', $timestamp . '.' . $payload, $secret)
 *   Additional: X-SIGNula-Timestamp: <unix_timestamp>
 *   Verify: Compare within 5-minute tolerance window
 *
 * @see https://docs.signula.id/webhooks (partner documentation)
 * @see https://www.php.net/manual/en/function.hash-hmac.php
 *
 * @package    SIGNula
 * @subpackage API
 * @version    1.0.0
 * @since      2.3.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class WebhookManager
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /** @var string HMAC algorithm used for signing */
    const SIGNATURE_ALGORITHM = 'sha256';

    /** @var int Maximum allowed clock skew in seconds (5 minutes) */
    const TIMESTAMP_TOLERANCE = 300;

    /** @var int Default maximum retry attempts */
    const DEFAULT_MAX_RETRIES = 5;

    /** @var int Base backoff in seconds for retry (exponential) */
    const DEFAULT_BACKOFF_BASE = 60;

    /** @var int Auto-disable endpoint after this many consecutive failures */
    const AUTO_DISABLE_THRESHOLD = 50;

    /** @var int Maximum response body to store in delivery log (4KB) */
    const MAX_RESPONSE_BODY_LENGTH = 4096;

    /** @var string User-Agent header sent with webhook deliveries */
    const USER_AGENT = 'SIGNula-Webhook/1.0';

    // ========================================================================
    // 🏗️ ENDPOINT MANAGEMENT
    // ========================================================================

    /**
     * 📝 Create a new webhook endpoint for a partner
     *
     * @param int $partnerID Partner ID
     * @param string $url HTTPS endpoint URL
     * @param array $events Array of event types to subscribe to
     * @param array $options Optional: description, headers, retryPolicy, rateLimitPerMinute, timeoutSeconds
     * @return array ['success' => bool, 'endpointID' => int, 'secret' => string]
     */
    public static function createEndpoint(int $partnerID, string $url, array $events, array $options = []): array
    {
        try {
            // 🔍 Validate URL is HTTPS
            // https://www.php.net/manual/en/function.filter-var.php
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return ['success' => false, 'message' => 'Invalid URL format'];
            }

            $parsed = parse_url($url);
            if (($parsed['scheme'] ?? '') !== 'https') {
                return ['success' => false, 'message' => 'Webhook endpoints must use HTTPS'];
            }

            // 🔍 Check endpoint limit per partner
            $maxEndpoints = self::getSetting('webhook.max_endpoints_per_partner', 10);
            $currentCount = Database::fetchOne(
                "SELECT COUNT(*) as cnt FROM tblWebhookEndpoints WHERE partnerID = ?",
                [$partnerID],
                'i'
            );

            if ($currentCount && (int)$currentCount['cnt'] >= (int)$maxEndpoints) {
                return ['success' => false, 'message' => 'Maximum endpoint limit reached (' . $maxEndpoints . ')'];
            }

            // 🔐 Generate HMAC signing secret (64 hex chars = 32 bytes)
            // https://www.php.net/manual/en/function.random-bytes.php
            $secret = 'whsec_' . bin2hex(random_bytes(32));

            // 🔒 Encrypt secret before storing
            $encryptedSecret = SecurityUtils::encrypt($secret);

            // 📦 Prepare events and optional fields
            $eventsJson = json_encode(array_values($events));
            $headersJson = isset($options['headers']) ? json_encode($options['headers']) : null;
            $retryJson = isset($options['retryPolicy']) ? json_encode($options['retryPolicy']) : null;
            $rateLimit = (int)($options['rateLimitPerMinute'] ?? 60);
            $timeout = (int)($options['timeoutSeconds'] ?? 30);
            $description = $options['description'] ?? null;

            // 💾 Insert endpoint
            $result = Database::query(
                "INSERT INTO tblWebhookEndpoints
                    (partnerID, url, description, secret, events, headers, retryPolicy, rateLimitPerMinute, timeoutSeconds)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$partnerID, $url, $description, $encryptedSecret, $eventsJson, $headersJson, $retryJson, $rateLimit, $timeout],
                'issssssii'
            );

            $endpointID = Database::getLastInsertId();

            // 📝 Log activity
            ActivityLogger::log(
                null,
                'webhook_endpoint_created',
                'api',
                'info',
                'Webhook endpoint created for partner #' . $partnerID,
                ['endpointID' => $endpointID, 'partnerID' => $partnerID, 'url' => $url, 'events' => $events]
            );

            return [
                'success' => true,
                'endpointID' => $endpointID,
                'secret' => $secret  // ⚠️ Only returned once at creation time
            ];
        } catch (\Exception $e) {
            ActivityLogger::log(null, 'webhook_endpoint_error', 'api', 'error', 'Failed to create webhook endpoint: ' . $e->getMessage(), ['partnerID' => $partnerID]);
            return ['success' => false, 'message' => 'Failed to create endpoint'];
        }
    }

    /**
     * ✏️ Update an existing webhook endpoint
     *
     * @param int $endpointID Endpoint ID
     * @param int $partnerID Partner ID (for ownership verification)
     * @param array $updates Fields to update (url, events, status, description, headers, retryPolicy, rateLimitPerMinute, timeoutSeconds)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function updateEndpoint(int $endpointID, int $partnerID, array $updates): array
    {
        try {
            // 🔍 Verify ownership
            $endpoint = Database::fetchOne(
                "SELECT endpointID FROM tblWebhookEndpoints WHERE endpointID = ? AND partnerID = ?",
                [$endpointID, $partnerID],
                'ii'
            );

            if (!$endpoint) {
                return ['success' => false, 'message' => 'Endpoint not found'];
            }

            // 🔧 Build dynamic UPDATE query
            $setClauses = [];
            $params = [];
            $types = '';

            if (isset($updates['url'])) {
                if (!filter_var($updates['url'], FILTER_VALIDATE_URL) || parse_url($updates['url'], PHP_URL_SCHEME) !== 'https') {
                    return ['success' => false, 'message' => 'Webhook endpoints must use HTTPS'];
                }
                $setClauses[] = 'url = ?';
                $params[] = $updates['url'];
                $types .= 's';
            }

            if (isset($updates['events'])) {
                $setClauses[] = 'events = ?';
                $params[] = json_encode(array_values($updates['events']));
                $types .= 's';
            }

            if (isset($updates['status'])) {
                $validStatuses = ['active', 'paused', 'disabled'];
                if (!in_array($updates['status'], $validStatuses, true)) {
                    return ['success' => false, 'message' => 'Invalid status'];
                }
                $setClauses[] = 'status = ?';
                $params[] = $updates['status'];
                $types .= 's';

                // 🔄 Reset failure count when re-enabling
                if ($updates['status'] === 'active') {
                    $setClauses[] = 'failureCount = 0';
                    $setClauses[] = 'disabledReason = NULL';
                }
            }

            if (isset($updates['description'])) {
                $setClauses[] = 'description = ?';
                $params[] = $updates['description'];
                $types .= 's';
            }

            if (isset($updates['headers'])) {
                $setClauses[] = 'headers = ?';
                $params[] = json_encode($updates['headers']);
                $types .= 's';
            }

            if (isset($updates['rateLimitPerMinute'])) {
                $setClauses[] = 'rateLimitPerMinute = ?';
                $params[] = (int)$updates['rateLimitPerMinute'];
                $types .= 'i';
            }

            if (isset($updates['timeoutSeconds'])) {
                $setClauses[] = 'timeoutSeconds = ?';
                $params[] = max(5, min(60, (int)$updates['timeoutSeconds']));
                $types .= 'i';
            }

            if (empty($setClauses)) {
                return ['success' => false, 'message' => 'No valid fields to update'];
            }

            $params[] = $endpointID;
            $types .= 'i';

            Database::query(
                "UPDATE tblWebhookEndpoints SET " . implode(', ', $setClauses) . " WHERE endpointID = ?",
                $params,
                $types
            );

            ActivityLogger::log(null, 'webhook_endpoint_updated', 'api', 'info', 'Webhook endpoint #' . $endpointID . ' updated', ['endpointID' => $endpointID, 'updates' => array_keys($updates)]);

            return ['success' => true, 'message' => 'Endpoint updated'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to update endpoint'];
        }
    }

    /**
     * 🔑 Regenerate the signing secret for an endpoint
     *
     * @param int $endpointID Endpoint ID
     * @param int $partnerID Partner ID (ownership check)
     * @return array ['success' => bool, 'secret' => string]
     */
    public static function regenerateSecret(int $endpointID, int $partnerID): array
    {
        try {
            $endpoint = Database::fetchOne(
                "SELECT endpointID FROM tblWebhookEndpoints WHERE endpointID = ? AND partnerID = ?",
                [$endpointID, $partnerID],
                'ii'
            );

            if (!$endpoint) {
                return ['success' => false, 'message' => 'Endpoint not found'];
            }

            $newSecret = 'whsec_' . bin2hex(random_bytes(32));
            $encryptedSecret = SecurityUtils::encrypt($newSecret);

            Database::query(
                "UPDATE tblWebhookEndpoints SET secret = ? WHERE endpointID = ?",
                [$encryptedSecret, $endpointID],
                'si'
            );

            ActivityLogger::log(null, 'webhook_secret_regenerated', 'api', 'warning', 'Webhook signing secret regenerated for endpoint #' . $endpointID, ['endpointID' => $endpointID, 'partnerID' => $partnerID]);

            return ['success' => true, 'secret' => $newSecret];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to regenerate secret'];
        }
    }

    /**
     * 🗑️ Delete a webhook endpoint and its delivery history
     *
     * @param int $endpointID Endpoint ID
     * @param int $partnerID Partner ID (ownership check)
     * @return array ['success' => bool]
     */
    public static function deleteEndpoint(int $endpointID, int $partnerID): array
    {
        try {
            $result = Database::query(
                "DELETE FROM tblWebhookEndpoints WHERE endpointID = ? AND partnerID = ?",
                [$endpointID, $partnerID],
                'ii'
            );

            ActivityLogger::log(null, 'webhook_endpoint_deleted', 'api', 'warning', 'Webhook endpoint #' . $endpointID . ' deleted', ['endpointID' => $endpointID, 'partnerID' => $partnerID]);

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to delete endpoint'];
        }
    }

    /**
     * 📋 Get all endpoints for a partner
     *
     * @param int $partnerID Partner ID
     * @return array List of endpoints (secrets are NOT returned)
     */
    public static function getEndpoints(int $partnerID): array
    {
        return Database::fetchAll(
            "SELECT endpointID, url, description, status, events, headers, retryPolicy,
                    rateLimitPerMinute, timeoutSeconds, lastDeliveryAt, lastSuccessAt,
                    lastFailureAt, failureCount, totalDeliveries, totalSuccesses,
                    totalFailures, disabledReason, createdAt, updatedAt
             FROM tblWebhookEndpoints
             WHERE partnerID = ?
             ORDER BY createdAt DESC",
            [$partnerID],
            'i'
        );
    }

    // ========================================================================
    // 🚀 WEBHOOK DELIVERY
    // ========================================================================

    /**
     * 📤 Dispatch an event to all subscribed partner endpoints
     *
     * This is the primary method to trigger webhook delivery. Call it when
     * a significant event occurs (e.g. user.created, payment.completed).
     *
     * @param string $eventType Event type identifier (e.g. 'user.created')
     * @param array $data Event payload data
     * @param int|null $specificPartnerID Only deliver to this partner (NULL = all subscribed)
     * @return array ['dispatched' => int, 'errors' => int]
     */
    public static function dispatch(string $eventType, array $data, ?int $specificPartnerID = null): array
    {
        $dispatched = 0;
        $errors = 0;

        // 🔍 Check if webhooks are enabled globally
        if (!self::getSetting('webhook.enabled', '1')) {
            return ['dispatched' => 0, 'errors' => 0, 'message' => 'Webhooks disabled'];
        }

        try {
            // 🔍 Find all active endpoints subscribed to this event type
            $query = "SELECT e.*, p.companyName, p.status as partnerStatus
                      FROM tblWebhookEndpoints e
                      JOIN tblPartners p ON e.partnerID = p.partnerID
                      WHERE e.status = 'active'
                        AND p.status = 'active'
                        AND JSON_CONTAINS(e.events, ?)";
            $params = [json_encode($eventType)];
            $types = 's';

            if ($specificPartnerID !== null) {
                $query .= " AND e.partnerID = ?";
                $params[] = $specificPartnerID;
                $types .= 'i';
            }

            $endpoints = Database::fetchAll($query, $params, $types);

            // 📤 Deliver to each endpoint
            foreach ($endpoints as $endpoint) {
                $result = self::deliverToEndpoint($endpoint, $eventType, $data);
                if ($result['success']) {
                    $dispatched++;
                } else {
                    $errors++;
                }
            }
        } catch (\Exception $e) {
            ActivityLogger::log(null, 'webhook_dispatch_error', 'api', 'error', 'Webhook dispatch failed: ' . $e->getMessage(), ['eventType' => $eventType]);
        }

        return ['dispatched' => $dispatched, 'errors' => $errors];
    }

    /**
     * 📬 Deliver a webhook to a specific endpoint
     *
     * @param array $endpoint Endpoint record from database
     * @param string $eventType Event type
     * @param array $data Event payload
     * @return array ['success' => bool, 'deliveryID' => int]
     */
    private static function deliverToEndpoint(array $endpoint, string $eventType, array $data): array
    {
        $endpointID = (int)$endpoint['endpointID'];
        $partnerID = (int)$endpoint['partnerID'];

        // 🆔 Generate unique event ID for idempotency
        // https://www.php.net/manual/en/function.bin2hex.php
        $eventID = bin2hex(random_bytes(16)) . '_' . time();

        // 🕐 Current timestamp for signature
        $timestamp = time();

        // 📦 Build payload
        $payload = json_encode([
            'event' => $eventType,
            'event_id' => $eventID,
            'timestamp' => $timestamp,
            'data' => $data
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 🔐 Decrypt the endpoint secret and compute HMAC signature
        $secret = SecurityUtils::decrypt($endpoint['secret']);
        $signaturePayload = $timestamp . '.' . $payload;
        $signature = 'sha256=' . hash_hmac(self::SIGNATURE_ALGORITHM, $signaturePayload, $secret);

        // 📝 Create delivery record (status: pending)
        $maxAttempts = self::DEFAULT_MAX_RETRIES;
        $retryPolicy = json_decode($endpoint['retryPolicy'] ?? '{}', true);
        if (isset($retryPolicy['maxRetries'])) {
            $maxAttempts = (int)$retryPolicy['maxRetries'];
        }

        Database::query(
            "INSERT INTO tblWebhookDeliveries
                (endpointID, partnerID, eventType, eventID, payload, signatureHeader, status, maxAttempts)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)",
            [$endpointID, $partnerID, $eventType, $eventID, $payload, $signature, $maxAttempts],
            'iissssi'
        );
        $deliveryID = Database::getLastInsertId();

        // 🚀 Attempt HTTP delivery via cURL
        // https://www.php.net/manual/en/book.curl.php
        $result = self::sendHTTPRequest(
            $endpoint['url'],
            $payload,
            $signature,
            $timestamp,
            $eventType,
            $eventID,
            (int)$endpoint['timeoutSeconds'],
            json_decode($endpoint['headers'] ?? '{}', true) ?: []
        );

        // 📊 Update delivery record and endpoint stats
        if ($result['success']) {
            // ✅ Successful delivery
            Database::query(
                "UPDATE tblWebhookDeliveries
                 SET status = 'delivered', httpStatusCode = ?, responseBody = ?, responseTimeMs = ?, deliveredAt = NOW()
                 WHERE deliveryID = ?",
                [$result['httpStatusCode'], mb_substr($result['responseBody'] ?? '', 0, self::MAX_RESPONSE_BODY_LENGTH), $result['responseTimeMs'], $deliveryID],
                'isii'
            );

            // 📈 Update endpoint success stats
            Database::query(
                "UPDATE tblWebhookEndpoints
                 SET lastDeliveryAt = NOW(), lastSuccessAt = NOW(), failureCount = 0,
                     totalDeliveries = totalDeliveries + 1, totalSuccesses = totalSuccesses + 1
                 WHERE endpointID = ?",
                [$endpointID],
                'i'
            );

            return ['success' => true, 'deliveryID' => $deliveryID];
        } else {
            // ❌ Failed delivery - schedule retry
            $nextRetryAt = self::calculateNextRetry(1, $retryPolicy);

            Database::query(
                "UPDATE tblWebhookDeliveries
                 SET status = 'retrying', httpStatusCode = ?, responseBody = ?, responseTimeMs = ?,
                     errorMessage = ?, nextRetryAt = ?
                 WHERE deliveryID = ?",
                [
                    $result['httpStatusCode'],
                    mb_substr($result['responseBody'] ?? '', 0, self::MAX_RESPONSE_BODY_LENGTH),
                    $result['responseTimeMs'],
                    mb_substr($result['error'] ?? 'Unknown error', 0, 500),
                    $nextRetryAt,
                    $deliveryID
                ],
                'isisi'  // Note: NULL httpStatusCode handled by MySQL
            );

            // 📉 Update endpoint failure stats
            Database::query(
                "UPDATE tblWebhookEndpoints
                 SET lastDeliveryAt = NOW(), lastFailureAt = NOW(), failureCount = failureCount + 1,
                     totalDeliveries = totalDeliveries + 1, totalFailures = totalFailures + 1
                 WHERE endpointID = ?",
                [$endpointID],
                'i'
            );

            // 🚫 Auto-disable if too many consecutive failures
            self::checkAutoDisable($endpointID);

            return ['success' => false, 'deliveryID' => $deliveryID, 'error' => $result['error']];
        }
    }

    /**
     * 🔄 Process pending retries
     *
     * Should be called periodically (e.g. via cron every minute or via admin UI trigger).
     *
     * @return array ['processed' => int, 'succeeded' => int, 'failed' => int]
     */
    public static function processRetries(): array
    {
        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        // 🔍 Find deliveries that are due for retry
        $pendingRetries = Database::fetchAll(
            "SELECT d.*, e.url, e.secret, e.timeoutSeconds, e.headers as endpointHeaders, e.retryPolicy, e.status as endpointStatus
             FROM tblWebhookDeliveries d
             JOIN tblWebhookEndpoints e ON d.endpointID = e.endpointID
             WHERE d.status = 'retrying'
               AND d.nextRetryAt <= NOW()
               AND d.attemptNumber < d.maxAttempts
               AND e.status = 'active'
             ORDER BY d.nextRetryAt ASC
             LIMIT 100",
            [],
            ''
        );

        foreach ($pendingRetries as $delivery) {
            $processed++;
            $attemptNumber = (int)$delivery['attemptNumber'] + 1;
            $timestamp = time();

            // 🔐 Recompute signature with fresh timestamp
            $secret = SecurityUtils::decrypt($delivery['secret']);
            $signaturePayload = $timestamp . '.' . $delivery['payload'];
            $signature = 'sha256=' . hash_hmac(self::SIGNATURE_ALGORITHM, $signaturePayload, $secret);

            // 🚀 Retry HTTP delivery
            $result = self::sendHTTPRequest(
                $delivery['url'],
                $delivery['payload'],
                $signature,
                $timestamp,
                $delivery['eventType'],
                $delivery['eventID'],
                (int)$delivery['timeoutSeconds'],
                json_decode($delivery['endpointHeaders'] ?? '{}', true) ?: []
            );

            if ($result['success']) {
                $succeeded++;
                Database::query(
                    "UPDATE tblWebhookDeliveries
                     SET status = 'delivered', attemptNumber = ?, httpStatusCode = ?, responseBody = ?,
                         responseTimeMs = ?, deliveredAt = NOW(), signatureHeader = ?, nextRetryAt = NULL
                     WHERE deliveryID = ?",
                    [$attemptNumber, $result['httpStatusCode'], mb_substr($result['responseBody'] ?? '', 0, self::MAX_RESPONSE_BODY_LENGTH), $result['responseTimeMs'], $signature, (int)$delivery['deliveryID']],
                    'iisisi'
                );

                // 📈 Reset failure count on success
                Database::query(
                    "UPDATE tblWebhookEndpoints SET lastSuccessAt = NOW(), failureCount = 0, totalSuccesses = totalSuccesses + 1 WHERE endpointID = ?",
                    [(int)$delivery['endpointID']],
                    'i'
                );
            } else {
                $failed++;
                $retryPolicy = json_decode($delivery['retryPolicy'] ?? '{}', true);

                if ($attemptNumber >= (int)$delivery['maxAttempts']) {
                    // ❌ Max retries exhausted
                    Database::query(
                        "UPDATE tblWebhookDeliveries
                         SET status = 'failed', attemptNumber = ?, httpStatusCode = ?, responseBody = ?,
                             responseTimeMs = ?, errorMessage = ?, nextRetryAt = NULL
                         WHERE deliveryID = ?",
                        [$attemptNumber, $result['httpStatusCode'], mb_substr($result['responseBody'] ?? '', 0, self::MAX_RESPONSE_BODY_LENGTH), $result['responseTimeMs'], mb_substr($result['error'] ?? 'Max retries exhausted', 0, 500), (int)$delivery['deliveryID']],
                        'iisisi'
                    );
                } else {
                    // 🔄 Schedule next retry
                    $nextRetryAt = self::calculateNextRetry($attemptNumber, $retryPolicy);
                    Database::query(
                        "UPDATE tblWebhookDeliveries
                         SET attemptNumber = ?, httpStatusCode = ?, responseBody = ?,
                             responseTimeMs = ?, errorMessage = ?, nextRetryAt = ?, signatureHeader = ?
                         WHERE deliveryID = ?",
                        [$attemptNumber, $result['httpStatusCode'], mb_substr($result['responseBody'] ?? '', 0, self::MAX_RESPONSE_BODY_LENGTH), $result['responseTimeMs'], mb_substr($result['error'] ?? '', 0, 500), $nextRetryAt, $signature, (int)$delivery['deliveryID']],
                        'iisissi'
                    );
                }

                // 📉 Increment failure count
                Database::query(
                    "UPDATE tblWebhookEndpoints SET lastFailureAt = NOW(), failureCount = failureCount + 1, totalFailures = totalFailures + 1 WHERE endpointID = ?",
                    [(int)$delivery['endpointID']],
                    'i'
                );

                self::checkAutoDisable((int)$delivery['endpointID']);
            }
        }

        return ['processed' => $processed, 'succeeded' => $succeeded, 'failed' => $failed];
    }

    // ========================================================================
    // 🔐 SIGNATURE GENERATION & VERIFICATION
    // ========================================================================

    /**
     * 🔏 Generate HMAC-SHA256 signature for a payload
     *
     * Partners use this method (or equivalent in their language) to verify
     * incoming webhook signatures.
     *
     * @param string $payload JSON payload string
     * @param int $timestamp Unix timestamp
     * @param string $secret Signing secret (whsec_...)
     * @return string Signature string (sha256=<hex>)
     */
    public static function generateSignature(string $payload, int $timestamp, string $secret): string
    {
        $signaturePayload = $timestamp . '.' . $payload;
        return 'sha256=' . hash_hmac(self::SIGNATURE_ALGORITHM, $signaturePayload, $secret);
    }

    /**
     * ✅ Verify a webhook signature (for partners to use in their code)
     *
     * This static method can also be used by SIGNula itself when receiving
     * webhook callbacks from external services.
     *
     * @param string $payload Raw request body
     * @param string $signature Signature from X-SIGNula-Signature header
     * @param string $timestamp Timestamp from X-SIGNula-Timestamp header
     * @param string $secret Signing secret
     * @return bool True if signature is valid and within time tolerance
     */
    public static function verifySignature(string $payload, string $signature, string $timestamp, string $secret): bool
    {
        // 🕐 Check timestamp tolerance (prevent replay attacks)
        // https://cheatsheetseries.owasp.org/cheatsheets/Webhook_Security_Cheat_Sheet.html
        $currentTime = time();
        $webhookTime = (int)$timestamp;
        if (abs($currentTime - $webhookTime) > self::TIMESTAMP_TOLERANCE) {
            return false;
        }

        // 🔐 Compute expected signature
        $expected = self::generateSignature($payload, $webhookTime, $secret);

        // ✅ Constant-time comparison to prevent timing attacks
        // https://www.php.net/manual/en/function.hash-equals.php
        return hash_equals($expected, $signature);
    }

    // ========================================================================
    // 🌐 HTTP DELIVERY
    // ========================================================================

    /**
     * 📡 Send HTTP POST request with signed payload
     *
     * @param string $url Target URL
     * @param string $payload JSON payload
     * @param string $signature HMAC signature
     * @param int $timestamp Unix timestamp
     * @param string $eventType Event type for header
     * @param string $eventID Event ID for header
     * @param int $timeout Request timeout in seconds
     * @param array $customHeaders Additional headers to include
     * @return array ['success' => bool, 'httpStatusCode' => int|null, 'responseBody' => string, 'responseTimeMs' => int, 'error' => string|null]
     */
    private static function sendHTTPRequest(
        string $url,
        string $payload,
        string $signature,
        int $timestamp,
        string $eventType,
        string $eventID,
        int $timeout,
        array $customHeaders = []
    ): array {
        $startTime = microtime(true);

        // 📋 Build headers
        $headers = [
            'Content-Type: application/json',
            'User-Agent: ' . self::USER_AGENT,
            'X-SIGNula-Signature: ' . $signature,
            'X-SIGNula-Timestamp: ' . $timestamp,
            'X-SIGNula-Event: ' . $eventType,
            'X-SIGNula-Event-ID: ' . $eventID,
            'X-SIGNula-Delivery: ' . bin2hex(random_bytes(16)),
        ];

        // 📋 Add custom headers (partner-configured)
        foreach ($customHeaders as $name => $value) {
            // 🛡️ Prevent overriding signature headers
            if (stripos($name, 'x-signula-') === 0) {
                continue;
            }
            $headers[] = $name . ': ' . $value;
        }

        // 🌐 Execute cURL request
        // https://www.php.net/manual/en/function.curl-init.php
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => false,  // 🛡️ Do not follow redirects
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);

        $responseBody = curl_exec($ch);
        $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        $responseTimeMs = (int)((microtime(true) - $startTime) * 1000);

        // ✅ Consider 2xx status codes as success
        if ($curlErrno === 0 && $httpStatusCode >= 200 && $httpStatusCode < 300) {
            return [
                'success' => true,
                'httpStatusCode' => $httpStatusCode,
                'responseBody' => $responseBody,
                'responseTimeMs' => $responseTimeMs,
                'error' => null,
            ];
        }

        // ❌ Delivery failed
        $error = $curlErrno > 0
            ? 'cURL error ' . $curlErrno . ': ' . $curlError
            : 'HTTP ' . $httpStatusCode;

        return [
            'success' => false,
            'httpStatusCode' => $httpStatusCode ?: null,
            'responseBody' => $responseBody ?: null,
            'responseTimeMs' => $responseTimeMs,
            'error' => $error,
        ];
    }

    // ========================================================================
    // 🔧 HELPERS
    // ========================================================================

    /**
     * ⏱️ Calculate next retry time using exponential backoff
     *
     * Formula: baseSeconds * (2 ^ (attemptNumber - 1)) + jitter
     *
     * @param int $attemptNumber Current attempt number (1-based)
     * @param array $retryPolicy Custom retry policy from endpoint config
     * @return string MySQL DATETIME for next retry
     */
    private static function calculateNextRetry(int $attemptNumber, array $retryPolicy = []): string
    {
        $baseSeconds = (int)($retryPolicy['backoffSeconds'] ?? self::DEFAULT_BACKOFF_BASE);

        // 📐 Exponential backoff: 60s, 120s, 240s, 480s, 960s
        $backoff = $baseSeconds * pow(2, $attemptNumber - 1);

        // 🎲 Add jitter (0-30 seconds) to prevent thundering herd
        $jitter = random_int(0, 30);

        // ⏱️ Cap at 24 hours
        $totalSeconds = min($backoff + $jitter, 86400);

        return date('Y-m-d H:i:s', time() + $totalSeconds);
    }

    /**
     * 🚫 Check if endpoint should be auto-disabled
     *
     * @param int $endpointID Endpoint ID
     */
    private static function checkAutoDisable(int $endpointID): void
    {
        $threshold = (int)self::getSetting('webhook.auto_disable_threshold', self::AUTO_DISABLE_THRESHOLD);

        $endpoint = Database::fetchOne(
            "SELECT failureCount, url FROM tblWebhookEndpoints WHERE endpointID = ? AND status = 'active'",
            [$endpointID],
            'i'
        );

        if ($endpoint && (int)$endpoint['failureCount'] >= $threshold) {
            Database::query(
                "UPDATE tblWebhookEndpoints SET status = 'failed', disabledReason = ? WHERE endpointID = ?",
                ['Auto-disabled after ' . $threshold . ' consecutive failures', $endpointID],
                'si'
            );

            ActivityLogger::log(
                null,
                'webhook_endpoint_auto_disabled',
                'api',
                'warning',
                'Webhook endpoint #' . $endpointID . ' auto-disabled after ' . $threshold . ' failures',
                ['endpointID' => $endpointID, 'url' => $endpoint['url'], 'failureCount' => $endpoint['failureCount']]
            );
        }
    }

    /**
     * ⚙️ Get a setting value from tblSettings
     *
     * @param string $key Setting key
     * @param mixed $default Default value if not found
     * @return string Setting value
     */
    private static function getSetting(string $key, mixed $default = ''): string
    {
        $setting = Database::fetchOne(
            "SELECT settingValue FROM tblSettings WHERE settingKey = ?",
            [$key],
            's'
        );

        return $setting ? $setting['settingValue'] : (string)$default;
    }

    /**
     * 📊 Get delivery statistics for a partner
     *
     * @param int $partnerID Partner ID
     * @param int $days Number of days to look back
     * @return array Statistics array
     */
    public static function getDeliveryStats(int $partnerID, int $days = 30): array
    {
        $stats = Database::fetchOne(
            "SELECT
                COUNT(*) as totalDeliveries,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as successCount,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failedCount,
                SUM(CASE WHEN status = 'retrying' THEN 1 ELSE 0 END) as retryingCount,
                AVG(CASE WHEN status = 'delivered' THEN responseTimeMs ELSE NULL END) as avgResponseMs,
                COUNT(DISTINCT eventType) as uniqueEventTypes,
                COUNT(DISTINCT endpointID) as activeEndpoints
             FROM tblWebhookDeliveries
             WHERE partnerID = ?
               AND createdAt >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$partnerID, $days],
            'ii'
        );

        return $stats ?: [
            'totalDeliveries' => 0,
            'successCount' => 0,
            'failedCount' => 0,
            'retryingCount' => 0,
            'avgResponseMs' => 0,
            'uniqueEventTypes' => 0,
            'activeEndpoints' => 0,
        ];
    }

    /**
     * 📋 Get recent deliveries for an endpoint
     *
     * @param int $endpointID Endpoint ID
     * @param int $partnerID Partner ID (ownership check)
     * @param int $limit Max records to return
     * @return array List of delivery records
     */
    public static function getDeliveries(int $endpointID, int $partnerID, int $limit = 50): array
    {
        return Database::fetchAll(
            "SELECT deliveryID, eventType, eventID, httpStatusCode, responseTimeMs,
                    status, attemptNumber, maxAttempts, errorMessage, deliveredAt, createdAt
             FROM tblWebhookDeliveries
             WHERE endpointID = ? AND partnerID = ?
             ORDER BY createdAt DESC
             LIMIT ?",
            [$endpointID, $partnerID, $limit],
            'iii'
        );
    }
}
