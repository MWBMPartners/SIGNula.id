<?php
/**
 * ============================================================================
 * ⏰ SIGNula - Advanced Email Scheduler
 * ============================================================================
 *
 * Purpose: Timezone-aware email scheduling with optimal send time calculation
 * PHP Version: 8.3+
 *
 * Features:
 * - Timezone-aware scheduling
 * - Optimal send time calculation
 * - Recipient timezone detection
 * - Business hours scheduling
 * - Send time optimization based on engagement data
 * - Batch scheduling
 * - Recurring schedules
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
 * ⏰ Email Scheduler
 *
 * Advanced email scheduling with timezone support.
 */
class EmailScheduler
{
    /**
     * @var array Default business hours (24-hour format)
     */
    private const DEFAULT_BUSINESS_HOURS = [
        'start' => 9,   // 9 AM
        'end' => 17     // 5 PM
    ];

    /**
     * @var array Default business days (0 = Sunday, 6 = Saturday)
     */
    private const DEFAULT_BUSINESS_DAYS = [1, 2, 3, 4, 5]; // Monday - Friday

    // ========================================================================
    // ⏰ SCHEDULING
    // ========================================================================

    /**
     * 📅 Schedule Email
     *
     * Schedules an email with timezone awareness.
     *
     * @param array $emailData Email data
     * @param DateTime $scheduledFor Scheduled send time
     * @param string|null $timezone Timezone (null = use user's timezone)
     * @return int|null Queue ID
     */
    public static function scheduleEmail(
        array $emailData,
        DateTime $scheduledFor,
        ?string $timezone = null
    ): ?int {
        try {
            // 🕐 Convert to UTC for storage
            $utcTime = self::convertToUTC($scheduledFor, $timezone);

            // 📧 Queue with scheduled time
            return EmailService::queueEmail(
                recipientEmail: $emailData['recipientEmail'],
                subject: $emailData['subject'],
                bodyHTML: $emailData['bodyHTML'] ?? null,
                bodyText: $emailData['bodyText'],
                fromEmail: $emailData['fromEmail'] ?? null,
                fromName: $emailData['fromName'] ?? null,
                replyTo: $emailData['replyTo'] ?? null,
                userID: $emailData['userID'] ?? null,
                templateID: $emailData['templateID'] ?? null,
                priority: $emailData['priority'] ?? 5,
                scheduledFor: $utcTime,
                cc: $emailData['cc'] ?? null,
                bcc: $emailData['bcc'] ?? null,
                attachments: $emailData['attachments'] ?? null
            );

        } catch (Exception $e) {
            error_log("Failed to schedule email: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 🎯 Schedule at Optimal Time
     *
     * Schedules email at the optimal time for recipient.
     *
     * @param array $emailData Email data
     * @param int|null $userID User ID
     * @param string|null $email Email address
     * @return int|null Queue ID
     */
    public static function scheduleAtOptimalTime(
        array $emailData,
        ?int $userID = null,
        ?string $email = null
    ): ?int {
        // 📊 Calculate optimal send time
        $optimalTime = self::calculateOptimalSendTime($userID, $email);

        // 📅 Schedule at optimal time
        return self::scheduleEmail($emailData, $optimalTime);
    }

    /**
     * 📊 Calculate Optimal Send Time
     *
     * Calculates the best time to send email based on engagement data.
     *
     * @param int|null $userID User ID
     * @param string|null $email Email address
     * @return DateTime Optimal send time
     */
    public static function calculateOptimalSendTime(
        ?int $userID = null,
        ?string $email = null
    ): DateTime {
        try {
            // 🔍 Get user timezone
            $timezone = self::getUserTimezone($userID, $email);

            // 📊 Analyze engagement patterns
            $bestHour = self::analyzeBestSendHour($userID, $email);

            // 📅 Get next occurrence of that hour
            $now = new DateTime('now', new DateTimeZone($timezone));
            $optimalTime = clone $now;

            // 🕐 Set to best hour
            $optimalTime->setTime($bestHour, 0, 0);

            // ➡️ If time has passed today, schedule for tomorrow
            if ($optimalTime <= $now) {
                $optimalTime->modify('+1 day');
            }

            // 📅 Skip weekends if business days only
            $optimalTime = self::adjustToBusinessDay($optimalTime);

            return $optimalTime;

        } catch (Exception $e) {
            error_log("Failed to calculate optimal send time: " . $e->getMessage());

            // 🔙 Fallback: tomorrow at 10 AM
            $fallback = new DateTime('+1 day 10:00:00');
            return $fallback;
        }
    }

    /**
     * 📈 Analyze Best Send Hour
     *
     * Analyzes user's email engagement to find best send hour.
     *
     * @param int|null $userID User ID
     * @param string|null $email Email address
     * @return int Best hour (0-23)
     */
    private static function analyzeBestSendHour(?int $userID, ?string $email): int
    {
        if (!$userID && !$email) {
            return 10; // Default to 10 AM
        }

        // 📊 Get engagement by hour
        $whereClause = $userID ? "userID = ?" : "recipientEmail = ?";
        $param = $userID ?? $email;
        $paramType = $userID ? 'i' : 's';

        $query = "
            SELECT
                HOUR(sentAt) as send_hour,
                COUNT(*) as sent_count,
                SUM(CASE WHEN openCount > 0 THEN 1 ELSE 0 END) as opens,
                SUM(CASE WHEN clickCount > 0 THEN 1 ELSE 0 END) as clicks
            FROM tblEmailQueue
            WHERE {$whereClause}
            AND status = 'sent'
            AND sentAt >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            GROUP BY HOUR(sentAt)
            HAVING sent_count >= 3
            ORDER BY (opens + clicks * 2) DESC
            LIMIT 1
        ";

        $result = Database::fetchOne($query, [$param], $paramType);

        if ($result && isset($result['send_hour'])) {
            return (int)$result['send_hour'];
        }

        // 🔙 Fallback to general best practice (10 AM)
        return 10;
    }

    /**
     * 🕐 Get User Timezone
     *
     * Gets user's timezone.
     *
     * @param int|null $userID User ID
     * @param string|null $email Email address
     * @return string Timezone identifier
     */
    private static function getUserTimezone(?int $userID, ?string $email): string
    {
        if ($userID) {
            $user = Database::fetchOne(
                "SELECT timezone FROM tblUsers WHERE userID = ?",
                [$userID],
                'i'
            );

            if ($user && !empty($user['timezone'])) {
                return $user['timezone'];
            }
        } elseif ($email) {
            $user = Database::fetchOne(
                "SELECT timezone FROM tblUsers WHERE email = ?",
                [$email],
                's'
            );

            if ($user && !empty($user['timezone'])) {
                return $user['timezone'];
            }
        }

        // 🔙 Fallback to system default
        return date_default_timezone_get();
    }

    // ========================================================================
    // 🌍 TIMEZONE HANDLING
    // ========================================================================

    /**
     * 🌍 Convert to UTC
     *
     * Converts datetime to UTC for storage.
     *
     * @param DateTime $dateTime DateTime object
     * @param string|null $fromTimezone Source timezone
     * @return DateTime UTC DateTime
     */
    public static function convertToUTC(DateTime $dateTime, ?string $fromTimezone = null): DateTime
    {
        $utcTime = clone $dateTime;

        if ($fromTimezone) {
            try {
                $utcTime->setTimezone(new DateTimeZone($fromTimezone));
            } catch (Exception $e) {
                error_log("Invalid timezone: {$fromTimezone}");
            }
        }

        $utcTime->setTimezone(new DateTimeZone('UTC'));

        return $utcTime;
    }

    /**
     * 🏠 Convert from UTC
     *
     * Converts UTC datetime to user's timezone.
     *
     * @param DateTime $utcDateTime UTC DateTime
     * @param string $toTimezone Target timezone
     * @return DateTime Converted DateTime
     */
    public static function convertFromUTC(DateTime $utcDateTime, string $toTimezone): DateTime
    {
        $localTime = clone $utcDateTime;

        try {
            $localTime->setTimezone(new DateTimeZone($toTimezone));
        } catch (Exception $e) {
            error_log("Invalid timezone: {$toTimezone}");
        }

        return $localTime;
    }

    /**
     * 🔍 Detect Timezone from IP
     *
     * Attempts to detect timezone from IP address.
     *
     * @param string $ipAddress IP address
     * @return string|null Timezone identifier
     */
    public static function detectTimezoneFromIP(string $ipAddress): ?string
    {
        // 🔍 This is a placeholder for IP-based timezone detection
        // In production, you would use a geolocation service like:
        // - MaxMind GeoIP2
        // - IP2Location
        // - ipapi.co
        // - ipstack.com

        // Example implementation:
        /*
        try {
            $response = file_get_contents("http://ip-api.com/json/{$ipAddress}");
            $data = json_decode($response, true);

            if ($data && $data['status'] === 'success') {
                return $data['timezone'] ?? null;
            }
        } catch (Exception $e) {
            error_log("Failed to detect timezone from IP: " . $e->getMessage());
        }
        */

        return null;
    }

    // ========================================================================
    // 📅 BUSINESS HOURS & DAYS
    // ========================================================================

    /**
     * 📅 Adjust to Business Day
     *
     * Adjusts datetime to next business day if on weekend.
     *
     * @param DateTime $dateTime DateTime to adjust
     * @return DateTime Adjusted DateTime
     */
    private static function adjustToBusinessDay(DateTime $dateTime): DateTime
    {
        $adjusted = clone $dateTime;
        $dayOfWeek = (int)$adjusted->format('w'); // 0 = Sunday, 6 = Saturday

        // 🔄 Skip to Monday if weekend
        if ($dayOfWeek === 0) {
            // Sunday -> Monday
            $adjusted->modify('+1 day');
        } elseif ($dayOfWeek === 6) {
            // Saturday -> Monday
            $adjusted->modify('+2 days');
        }

        return $adjusted;
    }

    /**
     * ⏰ Adjust to Business Hours
     *
     * Adjusts datetime to within business hours.
     *
     * @param DateTime $dateTime DateTime to adjust
     * @param int|null $startHour Business start hour (default: 9)
     * @param int|null $endHour Business end hour (default: 17)
     * @return DateTime Adjusted DateTime
     */
    public static function adjustToBusinessHours(
        DateTime $dateTime,
        ?int $startHour = null,
        ?int $endHour = null
    ): DateTime {
        $adjusted = clone $dateTime;
        $hour = (int)$adjusted->format('H');

        $startHour = $startHour ?? self::DEFAULT_BUSINESS_HOURS['start'];
        $endHour = $endHour ?? self::DEFAULT_BUSINESS_HOURS['end'];

        // ⏰ Before business hours -> set to start hour
        if ($hour < $startHour) {
            $adjusted->setTime($startHour, 0, 0);
        }

        // ⏰ After business hours -> next day at start hour
        if ($hour >= $endHour) {
            $adjusted->modify('+1 day');
            $adjusted->setTime($startHour, 0, 0);
            $adjusted = self::adjustToBusinessDay($adjusted);
        }

        return $adjusted;
    }

    /**
     * ✅ Is Business Hours
     *
     * Checks if datetime is within business hours.
     *
     * @param DateTime $dateTime DateTime to check
     * @param int|null $startHour Business start hour
     * @param int|null $endHour Business end hour
     * @return bool Is within business hours
     */
    public static function isBusinessHours(
        DateTime $dateTime,
        ?int $startHour = null,
        ?int $endHour = null
    ): bool {
        $hour = (int)$dateTime->format('H');
        $dayOfWeek = (int)$dateTime->format('w');

        $startHour = $startHour ?? self::DEFAULT_BUSINESS_HOURS['start'];
        $endHour = $endHour ?? self::DEFAULT_BUSINESS_HOURS['end'];

        // Check if business day
        if (!in_array($dayOfWeek, self::DEFAULT_BUSINESS_DAYS)) {
            return false;
        }

        // Check if business hours
        return $hour >= $startHour && $hour < $endHour;
    }

    // ========================================================================
    // 📊 BATCH SCHEDULING
    // ========================================================================

    /**
     * 📬 Schedule Batch
     *
     * Schedules multiple emails with staggered send times.
     *
     * @param array $emails Array of email data
     * @param DateTime $startTime Start time
     * @param int $intervalMinutes Interval between emails
     * @return array Queue IDs
     */
    public static function scheduleBatch(
        array $emails,
        DateTime $startTime,
        int $intervalMinutes = 5
    ): array {
        $queueIDs = [];
        $currentTime = clone $startTime;

        foreach ($emails as $emailData) {
            $queueID = self::scheduleEmail($emailData, $currentTime);

            if ($queueID) {
                $queueIDs[] = $queueID;
            }

            // ➡️ Increment time
            $currentTime->modify("+{$intervalMinutes} minutes");

            // 📅 Skip weekends and adjust to business hours
            $currentTime = self::adjustToBusinessDay($currentTime);
            $currentTime = self::adjustToBusinessHours($currentTime);
        }

        return $queueIDs;
    }

    /**
     * 🌍 Schedule for Multiple Timezones
     *
     * Schedules same email for recipients in different timezones.
     *
     * @param array $recipients Array of [email, timezone]
     * @param array $emailData Email data
     * @param int $localHour Hour in recipient's local time (0-23)
     * @return array Queue IDs
     */
    public static function scheduleForMultipleTimezones(
        array $recipients,
        array $emailData,
        int $localHour = 10
    ): array {
        $queueIDs = [];

        foreach ($recipients as $recipient) {
            $email = $recipient['email'];
            $timezone = $recipient['timezone'] ?? 'UTC';

            try {
                // 📅 Calculate send time in recipient's timezone
                $localTime = new DateTime('now', new DateTimeZone($timezone));
                $localTime->setTime($localHour, 0, 0);

                // ➡️ If time has passed, schedule for tomorrow
                $now = new DateTime('now', new DateTimeZone($timezone));
                if ($localTime <= $now) {
                    $localTime->modify('+1 day');
                }

                // 📧 Schedule email
                $recipientEmailData = array_merge($emailData, [
                    'recipientEmail' => $email
                ]);

                $queueID = self::scheduleEmail($recipientEmailData, $localTime, $timezone);

                if ($queueID) {
                    $queueIDs[] = $queueID;
                }

            } catch (Exception $e) {
                error_log("Failed to schedule for {$email}: " . $e->getMessage());
            }
        }

        return $queueIDs;
    }

    // ========================================================================
    // 🔁 RECURRING SCHEDULES
    // ========================================================================

    /**
     * 🔁 Create Recurring Schedule
     *
     * Creates a recurring email schedule.
     *
     * @param array $scheduleData Schedule configuration
     * @return int|null Schedule ID
     */
    public static function createRecurringSchedule(array $scheduleData): ?int
    {
        try {
            $query = "
                INSERT INTO tblEmailRecurringSchedules (
                    scheduleName, emailData, frequency,
                    frequencyValue, dayOfWeek, dayOfMonth,
                    timeOfDay, timezone, isActive,
                    startDate, endDate, createdBy
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            return Database::insert($query, [
                $scheduleData['scheduleName'],
                json_encode($scheduleData['emailData']),
                $scheduleData['frequency'], // 'daily', 'weekly', 'monthly'
                $scheduleData['frequencyValue'] ?? 1,
                $scheduleData['dayOfWeek'] ?? null,
                $scheduleData['dayOfMonth'] ?? null,
                $scheduleData['timeOfDay'] ?? '10:00:00',
                $scheduleData['timezone'] ?? 'UTC',
                $scheduleData['isActive'] ?? true,
                $scheduleData['startDate'] ?? date('Y-m-d'),
                $scheduleData['endDate'] ?? null,
                $scheduleData['createdBy'] ?? null
            ], 'sssiiisssssi');

        } catch (Exception $e) {
            error_log("Failed to create recurring schedule: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ⚙️ Process Recurring Schedules
     *
     * Processes all due recurring schedules (called by cron).
     *
     * @return array Processing statistics
     */
    public static function processRecurringSchedules(): array
    {
        $processed = 0;
        $queued = 0;
        $errors = 0;

        // 🔍 Get due schedules
        $query = "
            SELECT * FROM tblEmailRecurringSchedules
            WHERE isActive = 1
            AND (nextRun IS NULL OR nextRun <= NOW())
            AND (startDate IS NULL OR startDate <= CURDATE())
            AND (endDate IS NULL OR endDate >= CURDATE())
        ";

        $schedules = Database::fetchAll($query);

        foreach ($schedules as $schedule) {
            $processed++;

            try {
                $emailData = json_decode($schedule['emailData'], true);

                // 📧 Queue email
                $queueID = EmailService::queueEmail(
                    recipientEmail: $emailData['recipientEmail'],
                    subject: $emailData['subject'],
                    bodyHTML: $emailData['bodyHTML'] ?? null,
                    bodyText: $emailData['bodyText'],
                    priority: 5
                );

                if ($queueID) {
                    $queued++;

                    // 📅 Calculate next run
                    $nextRun = self::calculateNextRun($schedule);

                    // 📊 Update schedule
                    Database::query(
                        "UPDATE tblEmailRecurringSchedules
                         SET lastRun = NOW(), nextRun = ?
                         WHERE scheduleID = ?",
                        [$nextRun->format('Y-m-d H:i:s'), $schedule['scheduleID']],
                        'si'
                    );
                } else {
                    $errors++;
                }

            } catch (Exception $e) {
                error_log("Failed to process recurring schedule #{$schedule['scheduleID']}: " . $e->getMessage());
                $errors++;
            }
        }

        return [
            'processed' => $processed,
            'queued' => $queued,
            'errors' => $errors
        ];
    }

    /**
     * 📅 Calculate Next Run
     *
     * Calculates next run time for recurring schedule.
     *
     * @param array $schedule Schedule data
     * @return DateTime Next run time
     */
    private static function calculateNextRun(array $schedule): DateTime
    {
        $timezone = new DateTimeZone($schedule['timezone'] ?? 'UTC');
        $now = new DateTime('now', $timezone);
        $nextRun = clone $now;

        $frequency = $schedule['frequency'];
        $frequencyValue = $schedule['frequencyValue'] ?? 1;

        switch ($frequency) {
            case 'daily':
                $nextRun->modify("+{$frequencyValue} days");
                break;

            case 'weekly':
                $nextRun->modify("+{$frequencyValue} weeks");
                break;

            case 'monthly':
                $nextRun->modify("+{$frequencyValue} months");
                break;
        }

        // 🕐 Set time of day
        if ($schedule['timeOfDay']) {
            list($hour, $minute, $second) = explode(':', $schedule['timeOfDay']);
            $nextRun->setTime((int)$hour, (int)$minute, (int)$second);
        }

        return $nextRun;
    }
}

// ✅ EmailScheduler class loaded successfully
