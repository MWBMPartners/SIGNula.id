<?php
/**
 * ============================================================================
 * 🔗 SIGNula - Webhook Service
 * ============================================================================
 *
 * Purpose: Manage webhook subscriptions and deliver signed event payloads
 *          to registered endpoints with retry support and audit logging.
 * PHP Version: 8.3+
 *
 * Features:
 * - CRUD operations for webhook subscriptions (tblWebhooks)
 * - Event dispatching to all matching active webhooks
 * - HMAC-SHA256 payload signing for authenticity verification
 * - Exponential backoff retry for failed deliveries
 * - Auto-disable webhooks after consecutive failures
 * - Delivery log with full request/response audit trail
 * - Cleanup of old delivery records for storage management
 *
 * Signature Scheme:
 *   Header:   X-SIGNula-Signature: sha256=<hex_hmac>
 *   Computed:  hash_hmac('sha256', $timestamp . '.' . $jsonPayload, $secret)
 *   Headers:   X-SIGNula-Event, X-SIGNula-Delivery, X-SIGNula-Timestamp
 *   Verify:    Recipient computes HMAC and compares with header value
 *
 * This service uses the tblWebhooks and tblWebhookDeliveryLog tables
 * created by migration 024_webhook_system.sql. It is SEPARATE from the
 * existing WebhookManager.php (which manages tblWebhookEndpoints for
 * the partner API webhook system from migration 010).
 *
 * @see https://docs.signula.id/webhooks - Partner webhook documentation
 * @see https://www.php.net/manual/en/function.hash-hmac.php
 * @see https://www.php.net/manual/en/function.file-get-contents.php
 * @see https://en.wikipedia.org/wiki/Exponential_backoff
 *
 * @package    SIGNula
 * @subpackage Webhooks
 * @version    1.0.0
 * @since      2.7.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🔗 Webhook Service
 *
 * Static utility class for managing webhook subscriptions and delivering
 * signed event payloads to registered endpoints. All database operations
 * use MySQLi prepared statements via the Database singleton.
 *
 * Usage:
 * ```php
 * // 📝 Register a new webhook
 * $result = WebhookService::register([
 *     'name'      => 'My Webhook',
 *     'url'       => 'https://example.com/webhook',
 *     'events'    => ['user.login', 'user.registered'],
 *     'partnerID' => 42,
 * ]);
 *
 * // 📡 Dispatch an event to all matching webhooks
 * WebhookService::dispatch('user.login', ['userID' => 123, 'email' => 'user@example.com']);
 *
 * // 🔄 Process pending retries (call from cron or scheduler)
 * $processed = WebhookService::processRetries();
 *
 * // 🧹 Cleanup old delivery records
 * $deleted = WebhookService::cleanupOldDeliveries();
 * ```
 */
class WebhookService
{
    // ========================================================================
    // 📋 CONSTANTS — Supported Event Types
    // ========================================================================

    /**
     * @var array<string> Complete list of supported webhook event types.
     *
     * Events are grouped by category:
     * - user.*     : User account lifecycle events
     * - security.* : Security-related events
     * - webhook.*  : Webhook system meta-events
     *
     * @see https://docs.signula.id/webhooks/events - Event type documentation
     */
    public const EVENTS = [
        // 👤 User Account Events
        'user.login',               // User successfully logged in
        'user.logout',              // User logged out
        'user.registered',          // New user account created
        'user.password_changed',    // User changed their password
        'user.password_reset',      // Password was reset (by user or admin)
        'user.mfa_enabled',         // Multi-factor authentication enabled
        'user.mfa_disabled',        // Multi-factor authentication disabled
        'user.account_deleted',     // User account was deleted
        'user.account_locked',      // Account locked due to security policy
        'user.email_verified',      // Email address verified
        'user.profile_updated',     // User profile information updated

        // 🔒 Security Events
        'security.suspicious_activity',  // Suspicious activity detected
        'security.ip_blocked',           // IP address was blocked

        // 🔗 Webhook Meta Events
        'webhook.test',            // Test event for verifying webhook setup
    ];

    /** @var string HMAC algorithm used for payload signing */
    private const SIGNATURE_ALGORITHM = 'sha256';

    /** @var string User-Agent header sent with webhook deliveries */
    private const USER_AGENT = 'SIGNula-Webhook/2.0';

    /** @var int Auto-disable webhook after this many consecutive failures */
    private const AUTO_DISABLE_THRESHOLD = 10;

    /** @var int Maximum response body length to store in delivery log (chars) */
    private const MAX_RESPONSE_BODY_LENGTH = 1000;


    // ========================================================================
    // 🏗️ WEBHOOK MANAGEMENT — Create, Update, Delete, Get
    // ========================================================================

    /**
     * 📝 Register a new webhook subscription
     *
     * Creates a new webhook with a randomly generated HMAC signing secret.
     * The secret is encrypted before storage and returned in plaintext
     * exactly once at creation time — it cannot be retrieved again.
     *
     * @param array $data {
     *     @type string      $name      Human-readable webhook name (required, max 100 chars)
     *     @type string      $url       HTTPS endpoint URL (required, max 500 chars)
     *     @type array       $events    Array of event type strings to subscribe to (required)
     *     @type int|null    $partnerID Partner ID who owns this webhook (optional)
     *     @type int|null    $userID    User ID who created this webhook (optional)
     *     @type array|null  $headers   Custom HTTP headers as key-value pairs (optional)
     *     @type int         $retryCount Maximum retry attempts (optional, default from settings)
     *     @type int         $timeoutSeconds HTTP timeout in seconds (optional, default from settings)
     * }
     *
     * @return array{success: bool, webhookID?: int, secret?: string, message?: string}
     *
     * @see https://www.php.net/manual/en/function.random-bytes.php
     * @see https://www.php.net/manual/en/function.filter-var.php
     */
    public static function register(array $data): array
    {
        try {
            // ✅ Validate required fields
            $name = trim($data['name'] ?? '');
            $url  = trim($data['url'] ?? '');
            $events = $data['events'] ?? [];

            if ($name === '') {
                return ['success' => false, 'message' => 'Webhook name is required'];
            }

            if (mb_strlen($name) > 100) {
                return ['success' => false, 'message' => 'Webhook name must be 100 characters or fewer'];
            }

            // 🔍 Validate URL format
            // @see https://www.php.net/manual/en/function.filter-var.php
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return ['success' => false, 'message' => 'Invalid URL format'];
            }

            // 🔒 Check HTTPS requirement
            $requireHttps = (bool)getSetting('webhooks.require_https', '1');
            if ($requireHttps) {
                $parsed = parse_url($url);
                if (($parsed['scheme'] ?? '') !== 'https') {
                    return ['success' => false, 'message' => 'Webhook URLs must use HTTPS'];
                }
            }

            if (mb_strlen($url) > 500) {
                return ['success' => false, 'message' => 'URL must be 500 characters or fewer'];
            }

            // 📋 Validate events array
            if (empty($events) || !is_array($events)) {
                return ['success' => false, 'message' => 'At least one event type must be selected'];
            }

            // 🔍 Check that all events are valid
            $invalidEvents = array_diff($events, self::EVENTS);
            if (!empty($invalidEvents)) {
                return [
                    'success' => false,
                    'message' => 'Invalid event type(s): ' . implode(', ', $invalidEvents)
                ];
            }

            // 📊 Check partner webhook limit
            $partnerID = isset($data['partnerID']) ? (int)$data['partnerID'] : null;
            if ($partnerID !== null) {
                $maxPerPartner = (int)getSetting('webhooks.max_per_partner', '10');
                $currentCount = Database::fetchOne(
                    "SELECT COUNT(*) AS cnt FROM tblWebhooks WHERE partnerID = ?",
                    [$partnerID],
                    'i'
                );

                if ($currentCount && (int)$currentCount['cnt'] >= $maxPerPartner) {
                    return [
                        'success' => false,
                        'message' => 'Maximum webhook limit reached (' . $maxPerPartner . ' per partner)'
                    ];
                }
            }

            // 🔐 Generate HMAC signing secret
            // 32 random bytes = 64 hex characters for strong entropy
            // @see https://www.php.net/manual/en/function.random-bytes.php
            $secret = self::generateSecret();

            // 🔒 Encrypt secret before storage using AES-256-CBC
            // @see SecurityUtils::encrypt()
            $encryptedSecret = SecurityUtils::encrypt($secret);

            // 📦 Prepare optional fields
            $userID    = isset($data['userID']) ? (int)$data['userID'] : null;
            $headers   = isset($data['headers']) && is_array($data['headers']) ? json_encode($data['headers']) : null;
            $retryCount     = (int)($data['retryCount'] ?? getSetting('webhooks.max_retries', '3'));
            $timeoutSeconds = (int)($data['timeoutSeconds'] ?? getSetting('webhooks.default_timeout', '10'));
            $eventsJson     = json_encode(array_values($events));

            // 💾 Insert webhook record into tblWebhooks
            Database::query(
                "INSERT INTO tblWebhooks
                    (partnerID, userID, name, url, secret, events, isActive, headers, retryCount, timeoutSeconds)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)",
                [$partnerID, $userID, $name, $url, $encryptedSecret, $eventsJson, $headers, $retryCount, $timeoutSeconds],
                'iisssssis'
            );

            $webhookID = Database::getLastInsertId();

            // 📝 Log activity
            ActivityLogger::log(
                $userID,
                'webhook_created',
                'webhooks',
                'info',
                'Webhook "' . $name . '" created (ID: ' . $webhookID . ')',
                [
                    'webhookID' => $webhookID,
                    'partnerID' => $partnerID,
                    'url'       => $url,
                    'events'    => $events,
                ]
            );

            return [
                'success'   => true,
                'webhookID' => (int)$webhookID,
                'secret'    => $secret,  // ⚠️ Only returned once at creation time
            ];
        } catch (\Exception $e) {
            // 🚨 Log error and return generic failure message
            ErrorLogger::logError(
                'WebhookService::register',
                $e->getMessage(),
                'error',
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );

            return ['success' => false, 'message' => 'Failed to create webhook'];
        }
    }

    /**
     * ✏️ Update an existing webhook subscription
     *
     * Allows updating the name, URL, events, headers, retry settings,
     * and active status. The HMAC signing secret cannot be changed — delete
     * and re-create the webhook to get a new secret.
     *
     * @param int   $webhookID Webhook ID to update
     * @param array $data      Fields to update (same keys as register(), all optional)
     *
     * @return array{success: bool, message?: string}
     */
    public static function update(int $webhookID, array $data): bool
    {
        try {
            // 🔍 Verify webhook exists
            $existing = Database::fetchOne(
                "SELECT webhookID, name FROM tblWebhooks WHERE webhookID = ?",
                [$webhookID],
                'i'
            );

            if (!$existing) {
                return false;
            }

            // 📦 Build dynamic UPDATE query based on provided fields
            $setClauses = [];
            $params     = [];
            $types      = '';

            // 📝 Name
            if (isset($data['name'])) {
                $name = trim($data['name']);
                if ($name === '' || mb_strlen($name) > 100) {
                    return false;
                }
                $setClauses[] = 'name = ?';
                $params[]     = $name;
                $types       .= 's';
            }

            // 🌐 URL
            if (isset($data['url'])) {
                $url = trim($data['url']);
                if (!filter_var($url, FILTER_VALIDATE_URL) || mb_strlen($url) > 500) {
                    return false;
                }

                // 🔒 Enforce HTTPS if required
                $requireHttps = (bool)getSetting('webhooks.require_https', '1');
                if ($requireHttps) {
                    $parsed = parse_url($url);
                    if (($parsed['scheme'] ?? '') !== 'https') {
                        return false;
                    }
                }

                $setClauses[] = 'url = ?';
                $params[]     = $url;
                $types       .= 's';
            }

            // 📋 Events
            if (isset($data['events']) && is_array($data['events'])) {
                $invalidEvents = array_diff($data['events'], self::EVENTS);
                if (!empty($invalidEvents) || empty($data['events'])) {
                    return false;
                }

                $setClauses[] = 'events = ?';
                $params[]     = json_encode(array_values($data['events']));
                $types       .= 's';
            }

            // 📋 Custom headers
            if (array_key_exists('headers', $data)) {
                $setClauses[] = 'headers = ?';
                $params[]     = ($data['headers'] !== null && is_array($data['headers']))
                    ? json_encode($data['headers'])
                    : null;
                $types       .= 's';
            }

            // 🔄 Retry count
            if (isset($data['retryCount'])) {
                $setClauses[] = 'retryCount = ?';
                $params[]     = max(0, min(10, (int)$data['retryCount']));
                $types       .= 'i';
            }

            // ⏱️ Timeout
            if (isset($data['timeoutSeconds'])) {
                $setClauses[] = 'timeoutSeconds = ?';
                $params[]     = max(1, min(60, (int)$data['timeoutSeconds']));
                $types       .= 'i';
            }

            // 🔘 Active status
            if (isset($data['isActive'])) {
                $setClauses[] = 'isActive = ?';
                $params[]     = (int)(bool)$data['isActive'];
                $types       .= 'i';

                // 🔄 Reset failure count when re-enabling
                if ((bool)$data['isActive']) {
                    $setClauses[] = 'failureCount = 0';
                }
            }

            // 🚫 Nothing to update
            if (empty($setClauses)) {
                return true;
            }

            // 💾 Execute UPDATE
            $params[] = $webhookID;
            $types   .= 'i';

            Database::query(
                "UPDATE tblWebhooks SET " . implode(', ', $setClauses) . " WHERE webhookID = ?",
                $params,
                $types
            );

            // 📝 Log activity
            ActivityLogger::log(
                null,
                'webhook_updated',
                'webhooks',
                'info',
                'Webhook "' . ($data['name'] ?? $existing['name']) . '" updated (ID: ' . $webhookID . ')',
                ['webhookID' => $webhookID, 'changes' => array_keys($data)]
            );

            return true;
        } catch (\Exception $e) {
            ErrorLogger::logError(
                'WebhookService::update',
                $e->getMessage(),
                'error',
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );

            return false;
        }
    }

    /**
     * 🗑️ Delete a webhook subscription and all its delivery records
     *
     * Permanently removes the webhook and cascades to tblWebhookDeliveryLog
     * via the foreign key constraint.
     *
     * @param int $webhookID Webhook ID to delete
     *
     * @return bool True if deleted, false on failure or not found
     */
    public static function delete(int $webhookID): bool
    {
        try {
            // 🔍 Get webhook details for logging
            $webhook = Database::fetchOne(
                "SELECT webhookID, name, url FROM tblWebhooks WHERE webhookID = ?",
                [$webhookID],
                'i'
            );

            if (!$webhook) {
                return false;
            }

            // 💾 Delete webhook (deliveries cascade via FK)
            Database::query(
                "DELETE FROM tblWebhooks WHERE webhookID = ?",
                [$webhookID],
                'i'
            );

            // 📝 Log activity
            ActivityLogger::log(
                null,
                'webhook_deleted',
                'webhooks',
                'info',
                'Webhook "' . $webhook['name'] . '" deleted (ID: ' . $webhookID . ')',
                ['webhookID' => $webhookID, 'url' => $webhook['url']]
            );

            return true;
        } catch (\Exception $e) {
            ErrorLogger::logError(
                'WebhookService::delete',
                $e->getMessage(),
                'error',
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );

            return false;
        }
    }

    /**
     * 🔍 Get a single webhook by ID
     *
     * Retrieves webhook details including decrypted secret for display
     * in the admin UI. The secret is decrypted via SecurityUtils::decrypt().
     *
     * @param int $webhookID Webhook ID
     *
     * @return array|null Webhook data with decrypted secret, or null if not found
     */
    public static function getWebhook(int $webhookID): ?array
    {
        try {
            $webhook = Database::fetchOne(
                "SELECT * FROM tblWebhooks WHERE webhookID = ?",
                [$webhookID],
                'i'
            );

            if (!$webhook) {
                return null;
            }

            // 🔓 Decrypt the HMAC signing secret for admin display
            $webhook['decryptedSecret'] = SecurityUtils::decrypt($webhook['secret']);

            // 📋 Decode JSON fields for easier consumption
            $webhook['events']  = json_decode($webhook['events'], true) ?: [];
            $webhook['headers'] = $webhook['headers'] ? json_decode($webhook['headers'], true) : [];

            return $webhook;
        } catch (\Exception $e) {
            ErrorLogger::logError(
                'WebhookService::getWebhook',
                $e->getMessage(),
                'error',
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );

            return null;
        }
    }

    /**
     * 📋 Get all webhooks, optionally filtered by partner
     *
     * Returns a list of webhooks with JSON fields decoded. Secrets are
     * NOT decrypted in list view for performance and security.
     *
     * @param int|null $partnerID Filter by partner ID (null for all)
     * @param bool     $activeOnly Only return active webhooks (default: true)
     *
     * @return array<int, array> Array of webhook records
     */
    public static function getWebhooks(?int $partnerID = null, bool $activeOnly = true): array
    {
        try {
            // 📦 Build query with optional filters
            $where  = [];
            $params = [];
            $types  = '';

            if ($partnerID !== null) {
                $where[]  = 'partnerID = ?';
                $params[] = $partnerID;
                $types   .= 'i';
            }

            if ($activeOnly) {
                $where[] = 'isActive = 1';
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $webhooks = Database::fetchAll(
                "SELECT * FROM tblWebhooks {$whereClause} ORDER BY createdAt DESC",
                $params,
                $types
            );

            // 📋 Decode JSON fields for each webhook
            foreach ($webhooks as &$webhook) {
                $webhook['events']  = json_decode($webhook['events'], true) ?: [];
                $webhook['headers'] = $webhook['headers'] ? json_decode($webhook['headers'], true) : [];
            }
            unset($webhook); // 🔧 Break reference from foreach

            return $webhooks;
        } catch (\Exception $e) {
            ErrorLogger::logError(
                'WebhookService::getWebhooks',
                $e->getMessage(),
                'error',
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );

            return [];
        }
    }


    // ========================================================================
    // 📡 EVENT DISPATCHING — Trigger and Deliver Webhooks
    // ========================================================================

    /**
     * 📡 Dispatch an event to all matching active webhooks
     *
     * Finds all active webhooks subscribed to the given event type,
     * creates delivery records, and attempts immediate delivery.
     * If the webhook system is disabled via settings, this method
     * returns silently without creating any records.
     *
     * @param string   $event   Event type (must be one of self::EVENTS)
     * @param array    $payload Event data to include in the webhook body
     * @param int|null $userID  Optional user ID associated with this event
     *
     * @return void
     *
     * @see https://www.php.net/manual/en/function.json-encode.php
     */
    public static function dispatch(string $event, array $payload, ?int $userID = null): void
    {
        try {
            // 🔘 Check if webhook system is enabled globally
            if (!(bool)getSetting('webhooks.enabled', '1')) {
                return; // 🔇 Silently skip when disabled
            }

            // ✅ Validate event type
            if (!in_array($event, self::EVENTS, true)) {
                ErrorLogger::logError(
                    'WebhookService::dispatch',
                    'Unknown event type: ' . $event,
                    'warning',
                    __FILE__,
                    __LINE__
                );
                return;
            }

            // 🔍 Find all active webhooks subscribed to this event
            // Uses JSON_CONTAINS to check if the events array includes this event type
            // @see https://dev.mysql.com/doc/refman/8.0/en/json-search-functions.html#function_json-contains
            $webhooks = Database::fetchAll(
                "SELECT webhookID FROM tblWebhooks
                 WHERE isActive = 1
                   AND JSON_CONTAINS(events, ?, '$')
                 ORDER BY webhookID ASC",
                [json_encode($event)],
                's'
            );

            if (empty($webhooks)) {
                return; // 🔇 No matching webhooks
            }

            // 📨 Create delivery records and attempt delivery for each webhook
            foreach ($webhooks as $webhook) {
                $webhookID = (int)$webhook['webhookID'];

                // 🆔 Generate UUID v4 for tracking
                // @see https://www.php.net/manual/en/function.random-bytes.php
                $deliveryUUID = self::generateUUID();

                // 💾 Create delivery record with 'pending' status
                Database::query(
                    "INSERT INTO tblWebhookDeliveryLog
                        (webhookID, deliveryUUID, event, payload, status, attempts)
                     VALUES (?, ?, ?, ?, 'pending', 0)",
                    [$webhookID, $deliveryUUID, $event, json_encode($payload)],
                    'isss'
                );

                $deliveryID = Database::getLastInsertId();

                // 📡 Attempt immediate delivery
                self::deliver((int)$deliveryID);

                // 🕐 Update lastTriggeredAt on the webhook
                Database::query(
                    "UPDATE tblWebhooks SET lastTriggeredAt = NOW() WHERE webhookID = ?",
                    [$webhookID],
                    'i'
                );
            }
        } catch (\Exception $e) {
            // 🚨 Log but don't throw — webhook failures should not break the caller
            ErrorLogger::logError(
                'WebhookService::dispatch',
                'Event dispatch failed for "' . $event . '": ' . $e->getMessage(),
                'error',
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );
        }
    }

    /**
     * 📬 Deliver a single webhook payload
     *
     * Loads the delivery record and its parent webhook, builds the signed
     * JSON payload, sends an HTTP POST request via file_get_contents(),
     * and records the result in the delivery log.
     *
     * On failure, schedules a retry with exponential backoff if the maximum
     * attempt count has not been reached.
     *
     * @param int $deliveryID Delivery record ID from tblWebhookDeliveryLog
     *
     * @return array{success: bool, status: string, responseCode?: int, duration?: float, message?: string}
     *
     * @see https://www.php.net/manual/en/function.file-get-contents.php
     * @see https://www.php.net/manual/en/function.stream-context-create.php
     * @see https://www.php.net/manual/en/function.hash-hmac.php
     */
    public static function deliver(int $deliveryID): array
    {
        try {
            // 🔍 Load delivery record
            $delivery = Database::fetchOne(
                "SELECT * FROM tblWebhookDeliveryLog WHERE deliveryID = ?",
                [$deliveryID],
                'i'
            );

            if (!$delivery) {
                return ['success' => false, 'status' => 'failed', 'message' => 'Delivery record not found'];
            }

            // 🔍 Load parent webhook (with decrypted secret)
            $webhook = Database::fetchOne(
                "SELECT * FROM tblWebhooks WHERE webhookID = ?",
                [(int)$delivery['webhookID']],
                'i'
            );

            if (!$webhook) {
                // 🚫 Webhook was deleted — mark delivery as failed
                Database::query(
                    "UPDATE tblWebhookDeliveryLog SET status = 'failed', errorMessage = 'Parent webhook not found' WHERE deliveryID = ?",
                    [$deliveryID],
                    'i'
                );
                return ['success' => false, 'status' => 'failed', 'message' => 'Parent webhook not found'];
            }

            // 🔓 Decrypt the HMAC signing secret
            $secret = SecurityUtils::decrypt($webhook['secret']);

            // 📦 Build the webhook payload envelope
            $timestamp = time();
            $payloadData = [
                'event'       => $delivery['event'],
                'timestamp'   => $timestamp,
                'delivery_id' => $delivery['deliveryUUID'],
                'data'        => json_decode($delivery['payload'], true),
            ];

            $jsonPayload = json_encode($payloadData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // 🔐 Sign the payload with HMAC-SHA256
            // Signature includes timestamp to prevent replay attacks
            $signature = self::signPayload($timestamp . '.' . $jsonPayload, $secret);

            // 📋 Build HTTP headers
            $headers = [
                'Content-Type: application/json',
                'User-Agent: ' . self::USER_AGENT,
                'X-SIGNula-Signature: sha256=' . $signature,
                'X-SIGNula-Event: ' . $delivery['event'],
                'X-SIGNula-Delivery: ' . $delivery['deliveryUUID'],
                'X-SIGNula-Timestamp: ' . $timestamp,
            ];

            // 📋 Add custom headers from webhook configuration
            if ($webhook['headers']) {
                $customHeaders = json_decode($webhook['headers'], true);
                if (is_array($customHeaders)) {
                    foreach ($customHeaders as $headerName => $headerValue) {
                        // 🛡️ Sanitise header names — strip newlines to prevent CRLF injection
                        // @see https://owasp.org/www-community/attacks/HTTP_Response_Splitting
                        $cleanName  = preg_replace('/[\r\n]/', '', $headerName);
                        $cleanValue = preg_replace('/[\r\n]/', '', $headerValue);
                        $headers[]  = $cleanName . ': ' . $cleanValue;
                    }
                }
            }

            // ⏱️ Determine timeout
            $timeout = (int)($webhook['timeoutSeconds'] ?: getSetting('webhooks.default_timeout', '10'));

            // 📡 Send HTTP POST via file_get_contents + stream_context_create
            // Using file_get_contents for Dreamhost shared hosting compatibility (no cURL)
            // @see https://www.php.net/manual/en/function.stream-context-create.php
            $startTime = microtime(true);

            $context = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => implode("\r\n", $headers),
                    'content'       => $jsonPayload,
                    'timeout'       => $timeout,
                    'ignore_errors' => true,  // 🔧 Don't throw on 4xx/5xx — we want the response
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ],
            ]);

            // 📡 Execute the HTTP request
            $responseBody = @file_get_contents($webhook['url'], false, $context);
            $duration     = round(microtime(true) - $startTime, 3);

            // 📊 Extract HTTP response code from $http_response_header
            // @see https://www.php.net/manual/en/reserved.variables.httpresponseheader.php
            $responseCode = null;
            if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header)) {
                // Parse status line: "HTTP/1.1 200 OK"
                if (preg_match('/HTTP\/[\d.]+ (\d{3})/', $http_response_header[0], $matches)) {
                    $responseCode = (int)$matches[1];
                }
            }

            // 📝 Truncate response body for storage
            $storedResponseBody = ($responseBody !== false)
                ? mb_substr($responseBody, 0, self::MAX_RESPONSE_BODY_LENGTH)
                : null;

            // ✅ Determine success (2xx status codes)
            $isSuccess = ($responseCode !== null && $responseCode >= 200 && $responseCode < 300);

            // 💾 Update delivery record with result
            $newAttempts = (int)$delivery['attempts'] + 1;

            if ($isSuccess) {
                // ✅ Delivery successful
                Database::query(
                    "UPDATE tblWebhookDeliveryLog
                     SET status = 'delivered',
                         responseCode = ?,
                         responseBody = ?,
                         duration = ?,
                         attempts = ?,
                         deliveredAt = NOW(),
                         errorMessage = NULL
                     WHERE deliveryID = ?",
                    [$responseCode, $storedResponseBody, $duration, $newAttempts, $deliveryID],
                    'isdii'
                );

                // 🔄 Reset webhook failure counter on success
                Database::query(
                    "UPDATE tblWebhooks SET failureCount = 0 WHERE webhookID = ?",
                    [(int)$webhook['webhookID']],
                    'i'
                );

                return [
                    'success'      => true,
                    'status'       => 'delivered',
                    'responseCode' => $responseCode,
                    'duration'     => $duration,
                ];
            } else {
                // ❌ Delivery failed — determine if retry is possible
                $maxRetries = (int)($webhook['retryCount'] ?: getSetting('webhooks.max_retries', '3'));
                $errorMessage = ($responseBody === false)
                    ? 'Request failed — no response received (timeout or connection error)'
                    : 'HTTP ' . ($responseCode ?? 'unknown') . ' response';

                if ($newAttempts < $maxRetries) {
                    // 🔄 Schedule retry with exponential backoff
                    // Backoff: 60s, 240s, 900s (1min, 4min, 15min)
                    // @see https://en.wikipedia.org/wiki/Exponential_backoff
                    $backoffSeconds = (int)(60 * pow(2, $newAttempts));
                    $nextRetry = date('Y-m-d H:i:s', time() + $backoffSeconds);

                    Database::query(
                        "UPDATE tblWebhookDeliveryLog
                         SET status = 'retrying',
                             responseCode = ?,
                             responseBody = ?,
                             duration = ?,
                             attempts = ?,
                             nextRetry = ?,
                             errorMessage = ?
                         WHERE deliveryID = ?",
                        [$responseCode, $storedResponseBody, $duration, $newAttempts, $nextRetry, $errorMessage, $deliveryID],
                        'isdiisi'
                    );
                } else {
                    // 🚫 Max retries exceeded — mark as permanently failed
                    Database::query(
                        "UPDATE tblWebhookDeliveryLog
                         SET status = 'failed',
                             responseCode = ?,
                             responseBody = ?,
                             duration = ?,
                             attempts = ?,
                             nextRetry = NULL,
                             errorMessage = ?
                         WHERE deliveryID = ?",
                        [$responseCode, $storedResponseBody, $duration, $newAttempts, $errorMessage, $deliveryID],
                        'isdisi'
                    );

                    // 📊 Increment webhook failure counter
                    Database::query(
                        "UPDATE tblWebhooks
                         SET failureCount = failureCount + 1
                         WHERE webhookID = ?",
                        [(int)$webhook['webhookID']],
                        'i'
                    );

                    // 🚫 Auto-disable webhook if failure threshold reached
                    $updatedWebhook = Database::fetchOne(
                        "SELECT failureCount FROM tblWebhooks WHERE webhookID = ?",
                        [(int)$webhook['webhookID']],
                        'i'
                    );

                    if ($updatedWebhook && (int)$updatedWebhook['failureCount'] >= self::AUTO_DISABLE_THRESHOLD) {
                        Database::query(
                            "UPDATE tblWebhooks SET isActive = 0 WHERE webhookID = ?",
                            [(int)$webhook['webhookID']],
                            'i'
                        );

                        ActivityLogger::log(
                            null,
                            'webhook_auto_disabled',
                            'webhooks',
                            'warning',
                            'Webhook auto-disabled after ' . self::AUTO_DISABLE_THRESHOLD . ' consecutive failures (ID: ' . $webhook['webhookID'] . ')',
                            ['webhookID' => (int)$webhook['webhookID'], 'failureCount' => (int)$updatedWebhook['failureCount']]
                        );
                    }
                }

                return [
                    'success'      => false,
                    'status'       => ($newAttempts < $maxRetries) ? 'retrying' : 'failed',
                    'responseCode' => $responseCode,
                    'duration'     => $duration,
                    'message'      => $errorMessage,
                ];
            }
        } catch (\Exception $e) {
            // 🚨 Unexpected error — mark delivery as failed
            ErrorLogger::logError(
                'WebhookService::deliver',
                'Delivery #' . $deliveryID . ' failed: ' . $e->getMessage(),
                'error',
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );

            // 💾 Update delivery record with error
            try {
                Database::query(
                    "UPDATE tblWebhookDeliveryLog
                     SET status = 'failed',
                         attempts = attempts + 1,
                         errorMessage = ?
                     WHERE deliveryID = ?",
                    [mb_substr($e->getMessage(), 0, 500), $deliveryID],
                    'si'
                );
            } catch (\Exception $innerException) {
                // 🚨 Cannot even update the record — log and move on
                ErrorLogger::logError(
                    'WebhookService::deliver',
                    'Could not update failed delivery record: ' . $innerException->getMessage(),
                    'critical',
                    __FILE__,
                    __LINE__
                );
            }

            return ['success' => false, 'status' => 'failed', 'message' => $e->getMessage()];
        }
    }


    // ========================================================================
    // 🔄 RETRY PROCESSING — Handle Failed Deliveries
    // ========================================================================

    /**
     * 🔄 Process all pending webhook delivery retries
     *
     * Finds all delivery records with status 'retrying' whose nextRetry
     * timestamp has passed, and attempts re-delivery for each one.
     * Should be called periodically (e.g. every minute via cron).
     *
     * @return int Number of deliveries processed
     */
    public static function processRetries(): int
    {
        try {
            // 🔍 Find deliveries ready for retry
            $pendingRetries = Database::fetchAll(
                "SELECT deliveryID
                 FROM tblWebhookDeliveryLog
                 WHERE status = 'retrying'
                   AND nextRetry <= NOW()
                 ORDER BY nextRetry ASC
                 LIMIT 100",
                [],
                ''
            );

            $processed = 0;

            foreach ($pendingRetries as $retry) {
                self::deliver((int)$retry['deliveryID']);
                $processed++;
            }

            return $processed;
        } catch (\Exception $e) {
            ErrorLogger::logError(
                'WebhookService::processRetries',
                'Retry processing failed: ' . $e->getMessage(),
                'error',
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );

            return 0;
        }
    }


    // ========================================================================
    // 📊 DELIVERY LOG — View and Manage Delivery History
    // ========================================================================

    /**
     * 📋 Get delivery log for a specific webhook
     *
     * Returns paginated delivery records ordered by creation time (newest first).
     * Includes full payload and response details for debugging.
     *
     * @param int $webhookID Webhook ID to get deliveries for
     * @param int $limit     Maximum records to return (default: 50, max: 200)
     * @param int $offset    Number of records to skip for pagination (default: 0)
     *
     * @return array<int, array> Array of delivery records
     */
    public static function getDeliveries(int $webhookID, int $limit = 50, int $offset = 0): array
    {
        try {
            // 📊 Clamp limit to prevent excessive queries
            $limit  = max(1, min(200, $limit));
            $offset = max(0, $offset);

            $deliveries = Database::fetchAll(
                "SELECT *
                 FROM tblWebhookDeliveryLog
                 WHERE webhookID = ?
                 ORDER BY createdAt DESC
                 LIMIT ? OFFSET ?",
                [$webhookID, $limit, $offset],
                'iii'
            );

            // 📋 Decode JSON payload for display
            foreach ($deliveries as &$delivery) {
                $delivery['payload'] = json_decode($delivery['payload'], true) ?: [];
            }
            unset($delivery);

            return $deliveries;
        } catch (\Exception $e) {
            ErrorLogger::logError(
                'WebhookService::getDeliveries',
                $e->getMessage(),
                'error',
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );

            return [];
        }
    }


    // ========================================================================
    // 🧹 MAINTENANCE — Cleanup and Statistics
    // ========================================================================

    /**
     * 🧹 Delete old delivery log entries
     *
     * Removes delivery records older than the specified number of days.
     * The default retention period is controlled by the
     * 'webhooks.delivery_retention_days' setting.
     *
     * @param int $days Number of days to retain (default: from settings or 30)
     *
     * @return int Number of records deleted
     */
    public static function cleanupOldDeliveries(int $days = 0): int
    {
        try {
            // 📊 Use setting if no explicit value provided
            if ($days <= 0) {
                $days = (int)getSetting('webhooks.delivery_retention_days', '30');
            }

            // 💾 Delete old records
            Database::query(
                "DELETE FROM tblWebhookDeliveryLog
                 WHERE createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days],
                'i'
            );

            // 📊 Get affected row count
            $deleted = Database::getAffectedRows();

            if ($deleted > 0) {
                ActivityLogger::log(
                    null,
                    'webhook_deliveries_cleanup',
                    'webhooks',
                    'info',
                    'Cleaned up ' . $deleted . ' webhook delivery records older than ' . $days . ' days',
                    ['deletedCount' => $deleted, 'retentionDays' => $days]
                );
            }

            return $deleted;
        } catch (\Exception $e) {
            ErrorLogger::logError(
                'WebhookService::cleanupOldDeliveries',
                $e->getMessage(),
                'error',
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );

            return 0;
        }
    }

    /**
     * 📊 Get webhook system statistics
     *
     * Returns aggregate counts for the dashboard summary cards.
     *
     * @return array{
     *     totalWebhooks: int,
     *     activeWebhooks: int,
     *     totalDeliveries: int,
     *     deliveredToday: int,
     *     failedToday: int,
     *     pendingRetries: int
     * }
     */
    public static function getStats(): array
    {
        try {
            // 📊 Webhook counts
            $webhookStats = Database::fetchOne(
                "SELECT
                    COUNT(*) AS totalWebhooks,
                    SUM(CASE WHEN isActive = 1 THEN 1 ELSE 0 END) AS activeWebhooks
                 FROM tblWebhooks",
                [],
                ''
            );

            // 📊 Delivery counts (all time + today)
            $deliveryStats = Database::fetchOne(
                "SELECT
                    COUNT(*) AS totalDeliveries,
                    SUM(CASE WHEN status = 'delivered' AND DATE(deliveredAt) = CURDATE() THEN 1 ELSE 0 END) AS deliveredToday,
                    SUM(CASE WHEN status = 'failed' AND DATE(createdAt) = CURDATE() THEN 1 ELSE 0 END) AS failedToday,
                    SUM(CASE WHEN status = 'retrying' THEN 1 ELSE 0 END) AS pendingRetries
                 FROM tblWebhookDeliveryLog",
                [],
                ''
            );

            return [
                'totalWebhooks'   => (int)($webhookStats['totalWebhooks'] ?? 0),
                'activeWebhooks'  => (int)($webhookStats['activeWebhooks'] ?? 0),
                'totalDeliveries' => (int)($deliveryStats['totalDeliveries'] ?? 0),
                'deliveredToday'  => (int)($deliveryStats['deliveredToday'] ?? 0),
                'failedToday'     => (int)($deliveryStats['failedToday'] ?? 0),
                'pendingRetries'  => (int)($deliveryStats['pendingRetries'] ?? 0),
            ];
        } catch (\Exception $e) {
            ErrorLogger::logError(
                'WebhookService::getStats',
                $e->getMessage(),
                'error',
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );

            return [
                'totalWebhooks'   => 0,
                'activeWebhooks'  => 0,
                'totalDeliveries' => 0,
                'deliveredToday'  => 0,
                'failedToday'     => 0,
                'pendingRetries'  => 0,
            ];
        }
    }


    // ========================================================================
    // 🧪 TESTING — Send Test Webhook
    // ========================================================================

    /**
     * 🧪 Send a test webhook delivery
     *
     * Dispatches a 'webhook.test' event to a specific webhook with a
     * sample payload. Useful for verifying endpoint connectivity and
     * signature validation.
     *
     * @param int $webhookID Webhook ID to send test to
     *
     * @return array{success: bool, deliveryID?: int, status?: string, message?: string}
     */
    public static function sendTest(int $webhookID): array
    {
        try {
            // 🔍 Load webhook to verify it exists
            $webhook = Database::fetchOne(
                "SELECT webhookID, name, url FROM tblWebhooks WHERE webhookID = ?",
                [$webhookID],
                'i'
            );

            if (!$webhook) {
                return ['success' => false, 'message' => 'Webhook not found'];
            }

            // 🆔 Generate UUID for the test delivery
            $deliveryUUID = self::generateUUID();

            // 📦 Build test payload
            $testPayload = [
                'message'   => 'This is a test webhook from SIGNula',
                'webhookID' => $webhookID,
                'timestamp' => date('c'),
            ];

            // 💾 Create delivery record
            Database::query(
                "INSERT INTO tblWebhookDeliveryLog
                    (webhookID, deliveryUUID, event, payload, status, attempts)
                 VALUES (?, ?, 'webhook.test', ?, 'pending', 0)",
                [$webhookID, $deliveryUUID, json_encode($testPayload)],
                'iss'
            );

            $deliveryID = (int)Database::getLastInsertId();

            // 📡 Attempt delivery
            $result = self::deliver($deliveryID);

            // 🕐 Update lastTriggeredAt
            Database::query(
                "UPDATE tblWebhooks SET lastTriggeredAt = NOW() WHERE webhookID = ?",
                [$webhookID],
                'i'
            );

            $result['deliveryID'] = $deliveryID;
            return $result;
        } catch (\Exception $e) {
            ErrorLogger::logError(
                'WebhookService::sendTest',
                $e->getMessage(),
                'error',
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );

            return ['success' => false, 'message' => 'Test delivery failed'];
        }
    }


    // ========================================================================
    // 🔧 UTILITY — Cryptographic Helpers
    // ========================================================================

    /**
     * 🔐 Generate a cryptographically secure HMAC signing secret
     *
     * Produces 64 hex characters (32 bytes of entropy) suitable for
     * HMAC-SHA256 payload signing.
     *
     * @return string 64-character hexadecimal secret
     *
     * @see https://www.php.net/manual/en/function.random-bytes.php
     */
    public static function generateSecret(): string
    {
        // 🔐 32 random bytes = 256 bits of entropy
        return bin2hex(random_bytes(32));
    }

    /**
     * 🔏 Sign a payload string using HMAC-SHA256
     *
     * @param string $payload The data to sign (typically timestamp.jsonPayload)
     * @param string $secret  The shared secret key
     *
     * @return string Hexadecimal HMAC digest
     *
     * @see https://www.php.net/manual/en/function.hash-hmac.php
     */
    public static function signPayload(string $payload, string $secret): string
    {
        return hash_hmac(self::SIGNATURE_ALGORITHM, $payload, $secret);
    }

    /**
     * 🆔 Generate a UUID v4 string
     *
     * Creates a random UUID conforming to RFC 4122 version 4 format.
     * Used for webhook delivery tracking via the X-SIGNula-Delivery header.
     *
     * @return string UUID v4 string (e.g. "550e8400-e29b-41d4-a716-446655440000")
     *
     * @see https://www.php.net/manual/en/function.random-bytes.php
     * @see https://datatracker.ietf.org/doc/html/rfc4122#section-4.4
     */
    private static function generateUUID(): string
    {
        // 🔐 Generate 16 random bytes
        $data = random_bytes(16);

        // 📋 Set version to 0100 (UUID v4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);

        // 📋 Set variant to 10xx (RFC 4122)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // 📝 Format as UUID string
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
