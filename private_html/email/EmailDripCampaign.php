<?php
/**
 * ============================================================================
 * 💧 SIGNula - Email Drip Campaign Automation
 * ============================================================================
 *
 * Purpose: Automated email sequences triggered by events or time delays
 * PHP Version: 8.3+
 *
 * Features:
 * - Event-triggered campaigns (signup, purchase, etc.)
 * - Time-based sequences with delays
 * - Conditional logic and branching
 * - Subscriber management
 * - Performance tracking
 * - Pause/resume functionality
 * - Goal tracking and conversion measurement
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

/**
 * 💧 Email Drip Campaign
 *
 * Manages automated email sequences.
 */
class EmailDripCampaign
{
    // ========================================================================
    // 🎯 CAMPAIGN MANAGEMENT
    // ========================================================================

    /**
     * ➕ Create Campaign
     *
     * Creates a new drip campaign.
     *
     * @param array $campaignData Campaign configuration
     * @return int|null Campaign ID
     */
    public static function createCampaign(array $campaignData): ?int
    {
        try {
            // ✅ Validate required fields
            $required = ['campaignName', 'triggerType'];
            foreach ($required as $field) {
                if (empty($campaignData[$field])) {
                    throw new RuntimeException("Missing required field: {$field}");
                }
            }

            $query = "
                INSERT INTO tblEmailDripCampaigns (
                    campaignName, description, triggerType,
                    triggerEvent, isActive, goalType,
                    goalMetric, createdBy, createdAt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";

            $campaignID = Database::insert($query, [
                $campaignData['campaignName'],
                $campaignData['description'] ?? null,
                $campaignData['triggerType'], // 'event', 'manual', 'date'
                $campaignData['triggerEvent'] ?? null, // e.g., 'user_signup', 'purchase_complete'
                $campaignData['isActive'] ?? true,
                $campaignData['goalType'] ?? null, // 'open', 'click', 'conversion'
                $campaignData['goalMetric'] ?? null,
                $campaignData['createdBy'] ?? null
            ], 'ssssiissi');

            return $campaignID;

        } catch (Exception $e) {
            error_log("Failed to create drip campaign: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ➕ Add Campaign Step
     *
     * Adds a step (email) to a drip campaign.
     *
     * @param int $campaignID Campaign ID
     * @param array $stepData Step configuration
     * @return int|null Step ID
     */
    public static function addCampaignStep(int $campaignID, array $stepData): ?int
    {
        try {
            // ✅ Validate campaign exists
            $campaign = self::getCampaign($campaignID);
            if (!$campaign) {
                throw new RuntimeException("Campaign not found");
            }

            $query = "
                INSERT INTO tblEmailDripCampaignSteps (
                    campaignID, stepOrder, stepName,
                    delayValue, delayUnit, delayFromPrevious,
                    templateID, subject, bodyHTML, bodyText,
                    conditionType, conditionValue, isActive
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stepID = Database::insert($query, [
                $campaignID,
                $stepData['stepOrder'] ?? self::getNextStepOrder($campaignID),
                $stepData['stepName'] ?? 'Email Step',
                $stepData['delayValue'] ?? 0,
                $stepData['delayUnit'] ?? 'days', // 'minutes', 'hours', 'days', 'weeks'
                $stepData['delayFromPrevious'] ?? true,
                $stepData['templateID'] ?? null,
                $stepData['subject'] ?? null,
                $stepData['bodyHTML'] ?? null,
                $stepData['bodyText'] ?? null,
                $stepData['conditionType'] ?? null, // 'always', 'opened_previous', 'clicked_previous'
                $stepData['conditionValue'] ?? null,
                $stepData['isActive'] ?? true
            ], 'iisissiisssssi');

            return $stepID;

        } catch (Exception $e) {
            error_log("Failed to add campaign step: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 📊 Get Next Step Order
     *
     * Gets the next step order number for a campaign.
     *
     * @param int $campaignID Campaign ID
     * @return int Next step order
     */
    private static function getNextStepOrder(int $campaignID): int
    {
        $query = "
            SELECT COALESCE(MAX(stepOrder), 0) + 1 as nextOrder
            FROM tblEmailDripCampaignSteps
            WHERE campaignID = ?
        ";

        $result = Database::fetchOne($query, [$campaignID], 'i');
        return $result['nextOrder'] ?? 1;
    }

    // ========================================================================
    // 👥 SUBSCRIBER MANAGEMENT
    // ========================================================================

    /**
     * ➕ Add Subscriber
     *
     * Adds a subscriber to a drip campaign.
     *
     * @param int $campaignID Campaign ID
     * @param string $email Subscriber email
     * @param int|null $userID User ID (optional)
     * @param array $customData Custom subscriber data
     * @return int|null Subscriber ID
     */
    public static function addSubscriber(
        int $campaignID,
        string $email,
        ?int $userID = null,
        array $customData = []
    ): ?int {
        try {
            // 🔍 Check if already subscribed
            $existing = self::getSubscriber($campaignID, $email);
            if ($existing && $existing['status'] === 'active') {
                return $existing['subscriberID'];
            }

            $query = "
                INSERT INTO tblEmailDripSubscribers (
                    campaignID, userID, email,
                    status, customData, subscribedAt,
                    currentStepID, nextEmailDue
                ) VALUES (?, ?, ?, 'active', ?, NOW(), NULL, NOW())
            ";

            $subscriberID = Database::insert($query, [
                $campaignID,
                $userID,
                $email,
                json_encode($customData)
            ], 'iiss');

            // 📨 Queue first email if campaign is active
            if ($subscriberID) {
                self::queueNextEmail($subscriberID);
            }

            return $subscriberID;

        } catch (Exception $e) {
            error_log("Failed to add drip subscriber: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 🔍 Get Subscriber
     *
     * Gets a subscriber by campaign and email.
     *
     * @param int $campaignID Campaign ID
     * @param string $email Email address
     * @return array|null Subscriber data
     */
    public static function getSubscriber(int $campaignID, string $email): ?array
    {
        $query = "
            SELECT * FROM tblEmailDripSubscribers
            WHERE campaignID = ? AND email = ?
            LIMIT 1
        ";

        return Database::fetchOne($query, [$campaignID, $email], 'is');
    }

    /**
     * ⏸️ Pause Subscriber
     *
     * Pauses a subscriber's campaign progression.
     *
     * @param int $subscriberID Subscriber ID
     * @return bool Success status
     */
    public static function pauseSubscriber(int $subscriberID): bool
    {
        $query = "
            UPDATE tblEmailDripSubscribers
            SET status = 'paused'
            WHERE subscriberID = ?
        ";

        return (bool)Database::query($query, [$subscriberID], 'i');
    }

    /**
     * ▶️ Resume Subscriber
     *
     * Resumes a paused subscriber.
     *
     * @param int $subscriberID Subscriber ID
     * @return bool Success status
     */
    public static function resumeSubscriber(int $subscriberID): bool
    {
        $query = "
            UPDATE tblEmailDripSubscribers
            SET status = 'active'
            WHERE subscriberID = ?
        ";

        $result = Database::query($query, [$subscriberID], 'i');

        // 📨 Queue next email
        if ($result) {
            self::queueNextEmail($subscriberID);
        }

        return (bool)$result;
    }

    /**
     * 🛑 Unsubscribe
     *
     * Unsubscribes a subscriber from campaign.
     *
     * @param int $subscriberID Subscriber ID
     * @param string $reason Unsubscribe reason
     * @return bool Success status
     */
    public static function unsubscribe(int $subscriberID, string $reason = 'user_request'): bool
    {
        $query = "
            UPDATE tblEmailDripSubscribers
            SET status = 'unsubscribed',
                unsubscribedAt = NOW(),
                unsubscribeReason = ?
            WHERE subscriberID = ?
        ";

        return (bool)Database::query($query, [$reason, $subscriberID], 'si');
    }

    // ========================================================================
    // 📧 EMAIL PROCESSING
    // ========================================================================

    /**
     * 📨 Queue Next Email
     *
     * Queues the next email for a subscriber.
     *
     * @param int $subscriberID Subscriber ID
     * @return bool Success status
     */
    public static function queueNextEmail(int $subscriberID): bool
    {
        try {
            // 🔍 Get subscriber details
            $subscriber = Database::fetchOne(
                "SELECT * FROM tblEmailDripSubscribers WHERE subscriberID = ?",
                [$subscriberID],
                'i'
            );

            if (!$subscriber || $subscriber['status'] !== 'active') {
                return false;
            }

            // 🔍 Get next step
            $nextStep = self::getNextStep($subscriber);
            if (!$nextStep) {
                // 🏁 Campaign completed
                self::markCompleted($subscriberID);
                return false;
            }

            // ✅ Check condition
            if (!self::checkStepCondition($subscriber, $nextStep)) {
                // Skip to next step
                self::recordProgress($subscriberID, $nextStep['stepID'], 'skipped');
                return self::queueNextEmail($subscriberID); // Recursive call for next step
            }

            // 📅 Calculate send time
            $sendTime = self::calculateSendTime($subscriber, $nextStep);

            // 📧 Get email content
            $emailData = self::prepareEmailContent($subscriber, $nextStep);

            // 📬 Queue email
            $emailID = EmailService::queueEmail(
                recipientEmail: $subscriber['email'],
                subject: $emailData['subject'],
                bodyHTML: $emailData['bodyHTML'],
                bodyText: $emailData['bodyText'],
                userID: $subscriber['userID'],
                scheduledFor: $sendTime,
                metadata: json_encode([
                    'drip_campaign_id' => $subscriber['campaignID'],
                    'drip_subscriber_id' => $subscriberID,
                    'drip_step_id' => $nextStep['stepID']
                ])
            );

            if ($emailID) {
                // 📊 Update subscriber progress
                Database::query(
                    "UPDATE tblEmailDripSubscribers
                     SET currentStepID = ?, nextEmailDue = ?
                     WHERE subscriberID = ?",
                    [$nextStep['stepID'], $sendTime->format('Y-m-d H:i:s'), $subscriberID],
                    'isi'
                );

                return true;
            }

            return false;

        } catch (Exception $e) {
            error_log("Failed to queue next drip email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🔍 Get Next Step
     *
     * Gets the next step for a subscriber.
     *
     * @param array $subscriber Subscriber data
     * @return array|null Next step data
     */
    private static function getNextStep(array $subscriber): ?array
    {
        // 🔍 Get current step order
        $currentOrder = 0;
        if ($subscriber['currentStepID']) {
            $current = Database::fetchOne(
                "SELECT stepOrder FROM tblEmailDripCampaignSteps WHERE stepID = ?",
                [$subscriber['currentStepID']],
                'i'
            );
            $currentOrder = $current['stepOrder'] ?? 0;
        }

        // 🔍 Get next step
        $query = "
            SELECT * FROM tblEmailDripCampaignSteps
            WHERE campaignID = ? AND stepOrder > ? AND isActive = 1
            ORDER BY stepOrder ASC
            LIMIT 1
        ";

        return Database::fetchOne($query, [$subscriber['campaignID'], $currentOrder], 'ii');
    }

    /**
     * ✅ Check Step Condition
     *
     * Checks if step condition is met.
     *
     * @param array $subscriber Subscriber data
     * @param array $step Step data
     * @return bool Condition met
     */
    private static function checkStepCondition(array $subscriber, array $step): bool
    {
        $conditionType = $step['conditionType'] ?? 'always';

        switch ($conditionType) {
            case 'always':
                return true;

            case 'opened_previous':
                // Check if previous email was opened
                return self::checkPreviousEmailOpened($subscriber);

            case 'clicked_previous':
                // Check if previous email was clicked
                return self::checkPreviousEmailClicked($subscriber);

            case 'not_opened_previous':
                // Check if previous email was NOT opened
                return !self::checkPreviousEmailOpened($subscriber);

            default:
                return true;
        }
    }

    /**
     * 📧 Check Previous Email Opened
     *
     * Checks if subscriber opened previous email.
     *
     * @param array $subscriber Subscriber data
     * @return bool Email was opened
     */
    private static function checkPreviousEmailOpened(array $subscriber): bool
    {
        if (!$subscriber['currentStepID']) {
            return false;
        }

        $query = "
            SELECT COUNT(*) as opened
            FROM tblEmailQueue eq
            WHERE JSON_EXTRACT(eq.metadata, '$.drip_subscriber_id') = ?
            AND JSON_EXTRACT(eq.metadata, '$.drip_step_id') = ?
            AND eq.openCount > 0
        ";

        $result = Database::fetchOne($query, [
            $subscriber['subscriberID'],
            $subscriber['currentStepID']
        ], 'ii');

        return ($result['opened'] ?? 0) > 0;
    }

    /**
     * 🖱️ Check Previous Email Clicked
     *
     * Checks if subscriber clicked link in previous email.
     *
     * @param array $subscriber Subscriber data
     * @return bool Email was clicked
     */
    private static function checkPreviousEmailClicked(array $subscriber): bool
    {
        if (!$subscriber['currentStepID']) {
            return false;
        }

        $query = "
            SELECT COUNT(*) as clicked
            FROM tblEmailQueue eq
            WHERE JSON_EXTRACT(eq.metadata, '$.drip_subscriber_id') = ?
            AND JSON_EXTRACT(eq.metadata, '$.drip_step_id') = ?
            AND eq.clickCount > 0
        ";

        $result = Database::fetchOne($query, [
            $subscriber['subscriberID'],
            $subscriber['currentStepID']
        ], 'ii');

        return ($result['clicked'] ?? 0) > 0;
    }

    /**
     * 📅 Calculate Send Time
     *
     * Calculates when email should be sent based on delay.
     *
     * @param array $subscriber Subscriber data
     * @param array $step Step data
     * @return DateTime Send time
     */
    private static function calculateSendTime(array $subscriber, array $step): DateTime
    {
        $sendTime = new DateTime();

        // 🕐 Calculate delay
        $delayValue = $step['delayValue'] ?? 0;
        $delayUnit = $step['delayUnit'] ?? 'days';
        $delayFromPrevious = $step['delayFromPrevious'] ?? true;

        if ($delayValue > 0) {
            // 📍 Starting point
            if ($delayFromPrevious && $subscriber['nextEmailDue']) {
                $sendTime = new DateTime($subscriber['nextEmailDue']);
            } elseif (!$delayFromPrevious && $subscriber['subscribedAt']) {
                $sendTime = new DateTime($subscriber['subscribedAt']);
            }

            // ➕ Add delay
            $interval = match($delayUnit) {
                'minutes' => "PT{$delayValue}M",
                'hours' => "PT{$delayValue}H",
                'days' => "P{$delayValue}D",
                'weeks' => "P" . ($delayValue * 7) . "D",
                default => "P{$delayValue}D"
            };

            $sendTime->add(new DateInterval($interval));
        }

        return $sendTime;
    }

    /**
     * 📝 Prepare Email Content
     *
     * Prepares email content for sending.
     *
     * @param array $subscriber Subscriber data
     * @param array $step Step data
     * @return array Email content
     */
    private static function prepareEmailContent(array $subscriber, array $step): array
    {
        // 🔍 Use template if specified
        if ($step['templateID']) {
            $customData = json_decode($subscriber['customData'] ?? '{}', true);

            $rendered = EmailTemplateManager::renderTemplate(
                $step['templateID'],
                $customData
            );

            if ($rendered) {
                return $rendered;
            }
        }

        // 📝 Use step content
        return [
            'subject' => $step['subject'] ?? 'Email from ' . getSetting('site.name', 'SIGNula'),
            'bodyHTML' => $step['bodyHTML'] ?? '',
            'bodyText' => $step['bodyText'] ?? ''
        ];
    }

    /**
     * 📊 Record Progress
     *
     * Records subscriber progress through campaign.
     *
     * @param int $subscriberID Subscriber ID
     * @param int $stepID Step ID
     * @param string $status Status (sent, skipped, failed)
     */
    private static function recordProgress(
        int $subscriberID,
        int $stepID,
        string $status
    ): void {
        $query = "
            INSERT INTO tblEmailDripProgress (
                subscriberID, stepID, status, processedAt
            ) VALUES (?, ?, ?, NOW())
        ";

        Database::insert($query, [$subscriberID, $stepID, $status], 'iis');
    }

    /**
     * 🏁 Mark Completed
     *
     * Marks subscriber campaign as completed.
     *
     * @param int $subscriberID Subscriber ID
     */
    private static function markCompleted(int $subscriberID): void
    {
        $query = "
            UPDATE tblEmailDripSubscribers
            SET status = 'completed', completedAt = NOW()
            WHERE subscriberID = ?
        ";

        Database::query($query, [$subscriberID], 'i');
    }

    // ========================================================================
    // 📊 ANALYTICS
    // ========================================================================

    /**
     * 📈 Get Campaign Statistics
     *
     * Gets performance statistics for a campaign.
     *
     * @param int $campaignID Campaign ID
     * @return array Campaign statistics
     */
    public static function getCampaignStatistics(int $campaignID): array
    {
        // 👥 Subscriber stats
        $subscriberStats = Database::fetchOne("
            SELECT
                COUNT(*) as total_subscribers,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) as paused,
                SUM(CASE WHEN status = 'unsubscribed' THEN 1 ELSE 0 END) as unsubscribed
            FROM tblEmailDripSubscribers
            WHERE campaignID = ?
        ", [$campaignID], 'i') ?: [];

        // 📧 Email performance
        $emailStats = Database::fetchOne("
            SELECT
                COUNT(*) as emails_sent,
                SUM(eq.openCount) as total_opens,
                SUM(eq.clickCount) as total_clicks,
                SUM(CASE WHEN eq.openCount > 0 THEN 1 ELSE 0 END) as unique_opens,
                SUM(CASE WHEN eq.clickCount > 0 THEN 1 ELSE 0 END) as unique_clicks
            FROM tblEmailQueue eq
            WHERE JSON_EXTRACT(eq.metadata, '$.drip_campaign_id') = ?
            AND eq.status = 'sent'
        ", [$campaignID], 'i') ?: [];

        // 📊 Calculate rates
        $emailsSent = $emailStats['emails_sent'] ?? 0;
        $openRate = $emailsSent > 0 ? ($emailStats['unique_opens'] / $emailsSent) * 100 : 0;
        $clickRate = $emailsSent > 0 ? ($emailStats['unique_clicks'] / $emailsSent) * 100 : 0;

        return [
            'subscribers' => $subscriberStats,
            'emails' => $emailStats,
            'open_rate' => round($openRate, 2),
            'click_rate' => round($clickRate, 2)
        ];
    }

    // ========================================================================
    // 🔧 HELPER METHODS
    // ========================================================================

    /**
     * 🔍 Get Campaign
     *
     * Retrieves campaign by ID.
     *
     * @param int $campaignID Campaign ID
     * @return array|null Campaign data
     */
    public static function getCampaign(int $campaignID): ?array
    {
        $query = "SELECT * FROM tblEmailDripCampaigns WHERE campaignID = ?";
        return Database::fetchOne($query, [$campaignID], 'i');
    }

    /**
     * 📋 Get Campaign Steps
     *
     * Gets all steps for a campaign.
     *
     * @param int $campaignID Campaign ID
     * @return array Campaign steps
     */
    public static function getCampaignSteps(int $campaignID): array
    {
        $query = "
            SELECT * FROM tblEmailDripCampaignSteps
            WHERE campaignID = ?
            ORDER BY stepOrder ASC
        ";

        return Database::fetchAll($query, [$campaignID], 'i') ?: [];
    }

    /**
     * ⚙️ Process Due Emails
     *
     * Processes all due drip emails (called by cron).
     *
     * @param int $limit Maximum emails to process
     * @return array Processing statistics
     */
    public static function processDueEmails(int $limit = 100): array
    {
        $processed = 0;
        $errors = 0;

        // 🔍 Get subscribers with due emails
        $query = "
            SELECT subscriberID FROM tblEmailDripSubscribers
            WHERE status = 'active'
            AND nextEmailDue <= NOW()
            LIMIT ?
        ";

        $subscribers = Database::fetchAll($query, [$limit], 'i');

        foreach ($subscribers as $subscriber) {
            if (self::queueNextEmail($subscriber['subscriberID'])) {
                $processed++;
            } else {
                $errors++;
            }
        }

        return [
            'processed' => $processed,
            'errors' => $errors
        ];
    }
}

// ✅ EmailDripCampaign class loaded successfully
