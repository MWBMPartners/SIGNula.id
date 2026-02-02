<?php
/**
 * ============================================================================
 * 🔗 SIGNula - Email Webhook Handler
 * ============================================================================
 *
 * Purpose: Process webhook callbacks from email providers
 * PHP Version: 8.3+
 *
 * Features:
 * - SendGrid webhook processing
 * - Mailgun webhook processing
 * - Postmark webhook processing
 * - Amazon SES SNS notifications
 * - Event processing (delivered, bounced, opened, clicked, complained)
 * - Signature verification
 * - Automatic database updates
 *
 * @package    SIGNula
 * @subpackage Email
 * @version    1.0.0
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 📚 Require dependencies
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EmailTracker.php';

/**
 * 🔗 Email Webhook Handler
 *
 * Processes webhook callbacks from email service providers.
 */
class EmailWebhookHandler
{
    /**
     * @var string Provider name
     */
    private string $provider;

    /**
     * @var array Webhook data
     */
    private array $data;

    /**
     * @var bool Verbose logging
     */
    private bool $verbose = false;

    /**
     * 🏗️ Constructor
     *
     * @param string $provider Provider name
     * @param array $data Webhook data
     * @param bool $verbose Enable verbose logging
     */
    public function __construct(string $provider, array $data, bool $verbose = false)
    {
        $this->provider = $provider;
        $this->data = $data;
        $this->verbose = $verbose;
    }

    // ========================================================================
    // 🔐 WEBHOOK VERIFICATION
    // ========================================================================

    /**
     * ✅ Verify Webhook Signature
     *
     * Verifies that webhook is from the claimed provider.
     *
     * @param array $headers Request headers
     * @param string $rawBody Raw request body
     * @return bool True if signature is valid
     */
    public function verifySignature(array $headers, string $rawBody): bool
    {
        try {
            switch ($this->provider) {
                case 'sendgrid':
                    return $this->verifySendGridSignature($headers, $rawBody);

                case 'mailgun':
                    return $this->verifyMailgunSignature($headers, $this->data);

                case 'postmark':
                    return $this->verifyPostmarkSignature($headers, $rawBody);

                case 'amazon_ses':
                    return $this->verifyAmazonSNSSignature($headers, $this->data);

                default:
                    $this->log("⚠️  No signature verification for provider: {$this->provider}");
                    return true; // Allow through but log warning
            }

        } catch (Exception $e) {
            $this->log("❌ Signature verification failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🔐 Verify SendGrid Signature
     *
     * @param array $headers Headers
     * @param string $rawBody Raw body
     * @return bool Valid signature
     */
    private function verifySendGridSignature(array $headers, string $rawBody): bool
    {
        $publicKey = getSetting('email.sendgrid.webhook_public_key', '');
        if (empty($publicKey)) {
            return true; // No verification configured
        }

        $signature = $headers['X-Twilio-Email-Event-Webhook-Signature'] ?? '';
        $timestamp = $headers['X-Twilio-Email-Event-Webhook-Timestamp'] ?? '';

        if (empty($signature) || empty($timestamp)) {
            return false;
        }

        $signedPayload = $timestamp . $rawBody;
        $expectedSignature = base64_encode(hash_hmac('sha256', $signedPayload, $publicKey, true));

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * 🔐 Verify Mailgun Signature
     *
     * @param array $headers Headers
     * @param array $data Webhook data
     * @return bool Valid signature
     */
    private function verifyMailgunSignature(array $headers, array $data): bool
    {
        $signingKey = getSetting('email.mailgun.webhook_signing_key', '');
        if (empty($signingKey)) {
            return true; // No verification configured
        }

        $timestamp = $data['signature']['timestamp'] ?? '';
        $token = $data['signature']['token'] ?? '';
        $signature = $data['signature']['signature'] ?? '';

        if (empty($timestamp) || empty($token) || empty($signature)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $timestamp . $token, $signingKey);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * 🔐 Verify Postmark Signature
     *
     * @param array $headers Headers
     * @param string $rawBody Raw body
     * @return bool Valid signature
     */
    private function verifyPostmarkSignature(array $headers, string $rawBody): bool
    {
        // Postmark doesn't use signatures, but we can verify the User-Agent
        $userAgent = $headers['User-Agent'] ?? '';
        return str_contains($userAgent, 'Postmark');
    }

    /**
     * 🔐 Verify Amazon SNS Signature
     *
     * @param array $headers Headers
     * @param array $data Message data
     * @return bool Valid signature
     */
    private function verifyAmazonSNSSignature(array $headers, array $data): bool
    {
        // Amazon SNS signature verification is complex
        // For production, use AWS SDK's MessageValidator
        // For now, we'll do basic verification

        if (empty($data['SignatureVersion']) || empty($data['Signature'])) {
            return false;
        }

        // In production, implement full SNS signature verification
        return true;
    }

    // ========================================================================
    // 📨 WEBHOOK PROCESSING
    // ========================================================================

    /**
     * 🔄 Process Webhook
     *
     * Processes webhook events and updates database.
     *
     * @return array Processing result
     */
    public function process(): array
    {
        $this->log("🔗 Processing webhook from: {$this->provider}");

        try {
            $events = [];

            switch ($this->provider) {
                case 'sendgrid':
                    $events = $this->processSendGridWebhook();
                    break;

                case 'mailgun':
                    $events = $this->processMailgunWebhook();
                    break;

                case 'postmark':
                    $events = $this->processPostmarkWebhook();
                    break;

                case 'amazon_ses':
                    $events = $this->processAmazonSESWebhook();
                    break;

                default:
                    throw new RuntimeException("Unknown provider: {$this->provider}");
            }

            $this->log("✅ Processed " . count($events) . " events");

            return [
                'success' => true,
                'events_processed' => count($events),
                'events' => $events
            ];

        } catch (Exception $e) {
            $this->log("❌ Error: " . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 📧 Process SendGrid Webhook
     *
     * @return array Processed events
     */
    private function processSendGridWebhook(): array
    {
        $processedEvents = [];

        // SendGrid sends array of events
        $events = $this->data;

        foreach ($events as $event) {
            $eventType = $event['event'] ?? '';
            $email = $event['email'] ?? '';
            $messageId = $event['sg_message_id'] ?? '';

            $this->log("  📨 Event: {$eventType} for {$email}");

            // Find email in our database
            $emailRecord = $this->findEmailByProviderMessageId($messageId);
            if (!$emailRecord) {
                $this->log("  ⚠️  Email not found for message ID: {$messageId}");
                continue;
            }

            // Process event
            $this->processEvent($emailRecord['emailID'], $eventType, $event);
            $processedEvents[] = $eventType;
        }

        return $processedEvents;
    }

    /**
     * 📧 Process Mailgun Webhook
     *
     * @return array Processed events
     */
    private function processMailgunWebhook(): array
    {
        $processedEvents = [];

        $eventData = $this->data['event-data'] ?? [];
        $eventType = $eventData['event'] ?? '';
        $messageId = $eventData['message']['headers']['message-id'] ?? '';
        $recipient = $eventData['recipient'] ?? '';

        $this->log("  📨 Event: {$eventType} for {$recipient}");

        // Find email
        $emailRecord = $this->findEmailByProviderMessageId($messageId);
        if (!$emailRecord) {
            $this->log("  ⚠️  Email not found");
            return [];
        }

        // Process event
        $this->processEvent($emailRecord['emailID'], $eventType, $eventData);
        $processedEvents[] = $eventType;

        return $processedEvents;
    }

    /**
     * 📧 Process Postmark Webhook
     *
     * @return array Processed events
     */
    private function processPostmarkWebhook(): array
    {
        $processedEvents = [];

        $eventType = $this->data['RecordType'] ?? '';
        $messageId = $this->data['MessageID'] ?? '';
        $recipient = $this->data['Recipient'] ?? '';

        $this->log("  📨 Event: {$eventType} for {$recipient}");

        // Find email
        $emailRecord = $this->findEmailByProviderMessageId($messageId);
        if (!$emailRecord) {
            $this->log("  ⚠️  Email not found");
            return [];
        }

        // Process event
        $this->processEvent($emailRecord['emailID'], $eventType, $this->data);
        $processedEvents[] = $eventType;

        return $processedEvents;
    }

    /**
     * 📧 Process Amazon SES Webhook
     *
     * @return array Processed events
     */
    private function processAmazonSESWebhook(): array
    {
        $processedEvents = [];

        // SNS wraps the message
        $message = json_decode($this->data['Message'] ?? '{}', true);
        $messageType = $message['notificationType'] ?? '';
        $messageId = $message['mail']['messageId'] ?? '';

        $this->log("  📨 Event: {$messageType}");

        // Find email
        $emailRecord = $this->findEmailByProviderMessageId($messageId);
        if (!$emailRecord) {
            $this->log("  ⚠️  Email not found");
            return [];
        }

        // Process event based on type
        if ($messageType === 'Bounce') {
            $bounceType = $message['bounce']['bounceType'] ?? 'Hard';
            $this->processEvent($emailRecord['emailID'], 'bounce', ['bounce_type' => $bounceType]);
        } elseif ($messageType === 'Complaint') {
            $this->processEvent($emailRecord['emailID'], 'complaint', $message);
        } elseif ($messageType === 'Delivery') {
            $this->processEvent($emailRecord['emailID'], 'delivered', $message);
        }

        $processedEvents[] = $messageType;

        return $processedEvents;
    }

    /**
     * ⚙️ Process Event
     *
     * Processes a specific event type and updates database.
     *
     * @param int $emailID Email ID
     * @param string $eventType Event type
     * @param array $eventData Event data
     */
    private function processEvent(int $emailID, string $eventType, array $eventData): void
    {
        // Normalize event type across providers
        $normalizedEvent = $this->normalizeEventType($eventType);

        $this->log("    ➡️  Processing: {$normalizedEvent}");

        switch ($normalizedEvent) {
            case 'delivered':
                $this->handleDelivered($emailID);
                break;

            case 'bounce':
                $this->handleBounce($emailID, $eventData);
                break;

            case 'open':
                $this->handleOpen($emailID, $eventData);
                break;

            case 'click':
                $this->handleClick($emailID, $eventData);
                break;

            case 'complaint':
            case 'spam':
                $this->handleComplaint($emailID, $eventData);
                break;

            case 'unsubscribe':
                $this->handleUnsubscribe($emailID, $eventData);
                break;
        }
    }

    /**
     * 🔄 Normalize Event Type
     *
     * Normalizes event types across different providers.
     *
     * @param string $eventType Provider-specific event type
     * @return string Normalized event type
     */
    private function normalizeEventType(string $eventType): string
    {
        $eventType = strtolower($eventType);

        $mapping = [
            // SendGrid
            'processed' => 'delivered',
            'delivered' => 'delivered',
            'deferred' => 'deferred',
            'bounce' => 'bounce',
            'dropped' => 'dropped',
            'open' => 'open',
            'click' => 'click',
            'spamreport' => 'complaint',
            'unsubscribe' => 'unsubscribe',

            // Mailgun
            'accepted' => 'delivered',
            'rejected' => 'bounce',
            'failed' => 'bounce',
            'opened' => 'open',
            'clicked' => 'click',
            'complained' => 'complaint',
            'unsubscribed' => 'unsubscribe',

            // Postmark
            'delivery' => 'delivered',
            'subscriptionchange' => 'unsubscribe',

            // Amazon SES
            'delivery' => 'delivered',
            'complaint' => 'complaint'
        ];

        return $mapping[$eventType] ?? $eventType;
    }

    // ========================================================================
    // 🎯 EVENT HANDLERS
    // ========================================================================

    /**
     * ✅ Handle Delivered Event
     *
     * @param int $emailID Email ID
     */
    private function handleDelivered(int $emailID): void
    {
        Database::query(
            "UPDATE tblEmailQueue SET status = 'sent', sentAt = NOW() WHERE emailID = ?",
            [$emailID],
            'i'
        );
    }

    /**
     * ⚠️ Handle Bounce Event
     *
     * @param int $emailID Email ID
     * @param array $eventData Event data
     */
    private function handleBounce(int $emailID, array $eventData): void
    {
        // Determine bounce type
        $bounceType = $this->determineBounceType($eventData);
        $bounceReason = $this->extractBounceReason($eventData);

        // Record bounce
        EmailTracker::recordBounce($emailID, $bounceType, $bounceReason);

        $this->log("    ⚠️  Recorded {$bounceType} bounce");
    }

    /**
     * 👁️ Handle Open Event
     *
     * @param int $emailID Email ID
     * @param array $eventData Event data
     */
    private function handleOpen(int $emailID, array $eventData): void
    {
        // Get tracking token for this email
        $email = Database::fetchOne(
            "SELECT trackingToken FROM tblEmailQueue WHERE emailID = ?",
            [$emailID],
            'i'
        );

        if ($email && $email['trackingToken']) {
            $ipAddress = $eventData['ip'] ?? $eventData['user-variables']['ip'] ?? null;
            $userAgent = $eventData['useragent'] ?? $eventData['user-agent'] ?? null;

            EmailTracker::recordOpen($email['trackingToken'], $ipAddress, $userAgent);
            $this->log("    👁️  Recorded open");
        }
    }

    /**
     * 🔗 Handle Click Event
     *
     * @param int $emailID Email ID
     * @param array $eventData Event data
     */
    private function handleClick(int $emailID, array $eventData): void
    {
        $email = Database::fetchOne(
            "SELECT trackingToken FROM tblEmailQueue WHERE emailID = ?",
            [$emailID],
            'i'
        );

        if ($email && $email['trackingToken']) {
            $clickedUrl = $eventData['url'] ?? '';
            $ipAddress = $eventData['ip'] ?? null;
            $userAgent = $eventData['useragent'] ?? null;

            EmailTracker::recordClick($email['trackingToken'], $clickedUrl, $ipAddress, $userAgent, null);
            $this->log("    🔗 Recorded click on: {$clickedUrl}");
        }
    }

    /**
     * 😠 Handle Complaint Event
     *
     * @param int $emailID Email ID
     * @param array $eventData Event data
     */
    private function handleComplaint(int $emailID, array $eventData): void
    {
        $reason = $this->extractComplaintReason($eventData);

        EmailTracker::recordComplaint($emailID, $reason);
        $this->log("    😠 Recorded complaint");
    }

    /**
     * 🚫 Handle Unsubscribe Event
     *
     * @param int $emailID Email ID
     * @param array $eventData Event data
     */
    private function handleUnsubscribe(int $emailID, array $eventData): void
    {
        $email = Database::fetchOne(
            "SELECT recipientEmail, userID FROM tblEmailQueue WHERE emailID = ?",
            [$emailID],
            'i'
        );

        if ($email) {
            EmailTracker::addToUnsubscribeList(
                $email['recipientEmail'],
                $email['userID'],
                'user_request',
                'Unsubscribed via webhook'
            );
            $this->log("    🚫 Recorded unsubscribe");
        }
    }

    // ========================================================================
    // 🔧 HELPER METHODS
    // ========================================================================

    /**
     * 🔍 Find Email by Provider Message ID
     *
     * @param string $messageId Provider message ID
     * @return array|null Email record
     */
    private function findEmailByProviderMessageId(string $messageId): ?array
    {
        $query = "
            SELECT * FROM tblEmailQueue
            WHERE providerMessageID = ? OR providerMessageID LIKE ?
            LIMIT 1
        ";

        return Database::fetchOne($query, [$messageId, "%{$messageId}%"], 'ss');
    }

    /**
     * 🎯 Determine Bounce Type
     *
     * @param array $eventData Event data
     * @return string Bounce type (hard, soft, transient)
     */
    private function determineBounceType(array $eventData): string
    {
        // Check various provider-specific indicators
        $type = $eventData['type'] ?? $eventData['bounce']['bounceType'] ?? '';

        if (str_contains(strtolower($type), 'permanent') || str_contains(strtolower($type), 'hard')) {
            return 'hard';
        } elseif (str_contains(strtolower($type), 'temporary') || str_contains(strtolower($type), 'soft')) {
            return 'soft';
        }

        return 'transient';
    }

    /**
     * 📝 Extract Bounce Reason
     *
     * @param array $eventData Event data
     * @return string Bounce reason
     */
    private function extractBounceReason(array $eventData): string
    {
        return $eventData['reason'] ??
               $eventData['bounce']['bouncedRecipients'][0]['diagnosticCode'] ??
               $eventData['delivery-status']['description'] ??
               'Unknown reason';
    }

    /**
     * 📝 Extract Complaint Reason
     *
     * @param array $eventData Event data
     * @return string Complaint reason
     */
    private function extractComplaintReason(array $eventData): string
    {
        return $eventData['feedback-type'] ??
               $eventData['complaint']['complaintFeedbackType'] ??
               'Spam complaint';
    }

    /**
     * 📝 Log Message
     *
     * @param string $message Message
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo $message . PHP_EOL;
        }

        error_log("Webhook: " . $message);
    }
}

// ✅ EmailWebhookHandler class loaded successfully
