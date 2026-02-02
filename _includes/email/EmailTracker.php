<?php
/**
 * ============================================================================
 * 📊 SIGNula - Email Tracking System
 * ============================================================================
 *
 * Purpose: Track email opens, clicks, bounces, and user engagement
 * PHP Version: 8.3+
 *
 * Features:
 * - Open tracking with 1x1 pixel image
 * - Click tracking with URL rewriting
 * - Bounce handling
 * - Complaint tracking
 * - Analytics and reporting
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
 * 📊 Email Tracker
 *
 * Tracks email engagement and events.
 */
class EmailTracker
{
    /**
     * 🎫 Generate Tracking Token
     *
     * Generates a unique tracking token for an email.
     *
     * @param int $emailID Email ID
     * @return string Tracking token
     */
    public static function generateTrackingToken(int $emailID): string
    {
        // 🔐 Generate secure token
        $token = bin2hex(random_bytes(32)); // 64 character token

        // 💾 Update email with tracking token
        $query = "UPDATE tblEmailQueue SET trackingToken = ? WHERE emailID = ?";
        Database::query($query, [$token, $emailID], 'si');

        return $token;
    }

    /**
     * 🎨 Add Tracking Pixel to HTML
     *
     * Adds a 1x1 transparent tracking pixel to HTML email content.
     *
     * @param string $htmlContent HTML email content
     * @param string $trackingToken Tracking token
     * @return string HTML with tracking pixel
     */
    public static function addTrackingPixel(string $htmlContent, string $trackingToken): string
    {
        $baseURL = getSetting('url.base', 'https://signulo.id');
        $trackingPixel = '<img src="' . $baseURL . '/email/track-open?t=' . $trackingToken . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;" />';

        // 📍 Insert pixel just before closing </body> tag
        if (stripos($htmlContent, '</body>') !== false) {
            $htmlContent = str_ireplace('</body>', $trackingPixel . '</body>', $htmlContent);
        } else {
            // 📍 If no </body> tag, append to end
            $htmlContent .= $trackingPixel;
        }

        return $htmlContent;
    }

    /**
     * 🔗 Add Click Tracking to Links
     *
     * Rewrites URLs in HTML content to track clicks.
     *
     * @param string $htmlContent HTML email content
     * @param string $trackingToken Tracking token
     * @return string HTML with tracked links
     */
    public static function addClickTracking(string $htmlContent, string $trackingToken): string
    {
        $baseURL = getSetting('url.base', 'https://signulo.id');

        // 🔍 Find all links in HTML
        $pattern = '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/i';

        $htmlContent = preg_replace_callback($pattern, function($matches) use ($trackingToken, $baseURL) {
            $originalUrl = $matches[2];

            // 🚫 Skip if already a tracking URL or anchor link
            if (str_starts_with($originalUrl, $baseURL . '/email/track-click') || str_starts_with($originalUrl, '#')) {
                return $matches[0];
            }

            // 🔗 Create tracked URL
            $trackedUrl = $baseURL . '/email/track-click?t=' . urlencode($trackingToken) . '&url=' . urlencode($originalUrl);

            // 🔄 Replace original URL with tracked URL
            return '<a ' . $matches[1] . 'href="' . $trackedUrl . '"' . $matches[3] . '>';
        }, $htmlContent);

        return $htmlContent;
    }

    /**
     * 📊 Record Email Open
     *
     * Records when an email is opened.
     *
     * @param string $trackingToken Tracking token
     * @param string|null $ipAddress IP address
     * @param string|null $userAgent User agent
     * @return bool Success status
     */
    public static function recordOpen(string $trackingToken, ?string $ipAddress = null, ?string $userAgent = null): bool
    {
        try {
            // 🔍 Find email by tracking token
            $email = Database::fetchOne(
                "SELECT emailID, openCount FROM tblEmailQueue WHERE trackingToken = ?",
                [$trackingToken],
                's'
            );

            if (!$email) {
                return false;
            }

            // 📊 Update email open count and timestamp
            if ($email['openCount'] == 0) {
                // 🎯 First open - set openedAt timestamp
                $query = "
                    UPDATE tblEmailQueue
                    SET openCount = openCount + 1,
                        openedAt = NOW()
                    WHERE emailID = ?
                ";
            } else {
                // 📈 Subsequent opens - just increment counter
                $query = "
                    UPDATE tblEmailQueue
                    SET openCount = openCount + 1
                    WHERE emailID = ?
                ";
            }

            Database::query($query, [$email['emailID']], 'i');

            // 📝 Log tracking event
            $eventQuery = "
                INSERT INTO tblEmailTrackingEvents
                (emailID, eventType, ipAddress, userAgent, eventAt)
                VALUES (?, 'open', ?, ?, NOW())
            ";

            Database::query($eventQuery, [
                $email['emailID'],
                $ipAddress,
                $userAgent
            ], 'iss');

            return true;

        } catch (Exception $e) {
            error_log("Failed to record email open: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🔗 Record Click
     *
     * Records when a link in an email is clicked.
     *
     * @param string $trackingToken Tracking token
     * @param string $clickedUrl URL that was clicked
     * @param string|null $ipAddress IP address
     * @param string|null $userAgent User agent
     * @param string|null $referer Referer
     * @return string|null Destination URL or null on error
     */
    public static function recordClick(
        string $trackingToken,
        string $clickedUrl,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $referer = null
    ): ?string {
        try {
            // 🔍 Find email by tracking token
            $email = Database::fetchOne(
                "SELECT emailID FROM tblEmailQueue WHERE trackingToken = ?",
                [$trackingToken],
                's'
            );

            if (!$email) {
                return null;
            }

            // 📊 Update email click count
            $query = "
                UPDATE tblEmailQueue
                SET clickCount = clickCount + 1
                WHERE emailID = ?
            ";

            Database::query($query, [$email['emailID']], 'i');

            // 📝 Log tracking event
            $eventQuery = "
                INSERT INTO tblEmailTrackingEvents
                (emailID, eventType, clickedUrl, ipAddress, userAgent, referer, eventAt)
                VALUES (?, 'click', ?, ?, ?, ?, NOW())
            ";

            Database::query($eventQuery, [
                $email['emailID'],
                $clickedUrl,
                $ipAddress,
                $userAgent,
                $referer
            ], 'issss');

            return $clickedUrl;

        } catch (Exception $e) {
            error_log("Failed to record email click: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ⚠️ Record Bounce
     *
     * Records when an email bounces.
     *
     * @param int $emailID Email ID
     * @param string $bounceType Bounce type (hard, soft, transient)
     * @param string|null $bounceReason Bounce reason
     * @return bool Success status
     */
    public static function recordBounce(int $emailID, string $bounceType, ?string $bounceReason = null): bool
    {
        try {
            // 📝 Log tracking event
            $eventQuery = "
                INSERT INTO tblEmailTrackingEvents
                (emailID, eventType, bounceType, bounceReason, eventAt)
                VALUES (?, 'bounce', ?, ?, NOW())
            ";

            Database::query($eventQuery, [
                $emailID,
                $bounceType,
                $bounceReason
            ], 'iss');

            // 🚫 For hard bounces, add to unsubscribe list
            if ($bounceType === 'hard') {
                // 🔍 Get recipient email
                $email = Database::fetchOne(
                    "SELECT recipientEmail, userID FROM tblEmailQueue WHERE emailID = ?",
                    [$emailID],
                    'i'
                );

                if ($email) {
                    self::addToUnsubscribeList(
                        $email['recipientEmail'],
                        $email['userID'],
                        'bounce',
                        $bounceReason ?? 'Hard bounce'
                    );
                }
            }

            return true;

        } catch (Exception $e) {
            error_log("Failed to record email bounce: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 😠 Record Complaint
     *
     * Records when a user marks an email as spam/complaint.
     *
     * @param int $emailID Email ID
     * @param string|null $reason Complaint reason
     * @return bool Success status
     */
    public static function recordComplaint(int $emailID, ?string $reason = null): bool
    {
        try {
            // 📝 Log tracking event
            $eventQuery = "
                INSERT INTO tblEmailTrackingEvents
                (emailID, eventType, metadata, eventAt)
                VALUES (?, 'complaint', ?, NOW())
            ";

            $metadata = $reason ? json_encode(['reason' => $reason]) : null;

            Database::query($eventQuery, [
                $emailID,
                $metadata
            ], 'is');

            // 🚫 Add to unsubscribe list
            $email = Database::fetchOne(
                "SELECT recipientEmail, userID FROM tblEmailQueue WHERE emailID = ?",
                [$emailID],
                'i'
            );

            if ($email) {
                self::addToUnsubscribeList(
                    $email['recipientEmail'],
                    $email['userID'],
                    'complaint',
                    $reason ?? 'Spam complaint'
                );
            }

            return true;

        } catch (Exception $e) {
            error_log("Failed to record email complaint: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🚫 Add to Unsubscribe List
     *
     * Adds an email address to the unsubscribe list.
     *
     * @param string $email Email address
     * @param int|null $userID User ID (optional)
     * @param string $reason Reason for unsubscribe
     * @param string|null $reasonDetails Additional details
     * @param string|null $category Category to unsubscribe from (null = all)
     * @return bool Success status
     */
    public static function addToUnsubscribeList(
        string $email,
        ?int $userID = null,
        string $reason = 'user_request',
        ?string $reasonDetails = null,
        ?string $category = null
    ): bool {
        try {
            // 🔍 Check if already unsubscribed
            $existing = Database::fetchOne(
                "SELECT unsubscribeID FROM tblEmailUnsubscribes WHERE email = ? AND category <=> ? AND resubscribedAt IS NULL",
                [$email, $category],
                'ss'
            );

            if ($existing) {
                return true; // Already unsubscribed
            }

            $query = "
                INSERT INTO tblEmailUnsubscribes
                (email, userID, reason, reasonDetails, category, unsubscribedAt)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    reason = VALUES(reason),
                    reasonDetails = VALUES(reasonDetails),
                    unsubscribedAt = NOW(),
                    resubscribedAt = NULL
            ";

            Database::query($query, [
                $email,
                $userID,
                $reason,
                $reasonDetails,
                $category
            ], 'sisss');

            return true;

        } catch (Exception $e) {
            error_log("Failed to add to unsubscribe list: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ✅ Check if Email is Unsubscribed
     *
     * Checks if an email address is on the unsubscribe list.
     *
     * @param string $email Email address
     * @param string|null $category Category to check (null = check all)
     * @return bool True if unsubscribed
     */
    public static function isUnsubscribed(string $email, ?string $category = null): bool
    {
        $query = "
            SELECT COUNT(*) as count FROM tblEmailUnsubscribes
            WHERE email = ?
              AND (category IS NULL OR category = ?)
              AND resubscribedAt IS NULL
        ";

        $result = Database::fetchOne($query, [$email, $category], 'ss');

        return ($result['count'] ?? 0) > 0;
    }

    /**
     * 📊 Get Email Statistics
     *
     * Retrieves statistics for an email.
     *
     * @param int $emailID Email ID
     * @return array Email statistics
     */
    public static function getEmailStats(int $emailID): array
    {
        $email = Database::fetchOne(
            "SELECT openCount, clickCount, openedAt FROM tblEmailQueue WHERE emailID = ?",
            [$emailID],
            'i'
        );

        if (!$email) {
            return [
                'opens' => 0,
                'clicks' => 0,
                'opened_at' => null,
                'events' => []
            ];
        }

        // 📊 Get all tracking events
        $events = Database::fetchAll(
            "SELECT * FROM tblEmailTrackingEvents WHERE emailID = ? ORDER BY eventAt DESC",
            [$emailID],
            'i'
        ) ?: [];

        return [
            'opens' => $email['openCount'],
            'clicks' => $email['clickCount'],
            'opened_at' => $email['openedAt'],
            'events' => $events
        ];
    }

    /**
     * 📈 Get Campaign Statistics
     *
     * Retrieves aggregated statistics for a campaign or template.
     *
     * @param int|null $templateID Template ID (optional)
     * @param int|null $campaignID Campaign ID (optional)
     * @param \DateTime|null $startDate Start date filter (optional)
     * @param \DateTime|null $endDate End date filter (optional)
     * @return array Campaign statistics
     */
    public static function getCampaignStats(
        ?int $templateID = null,
        ?int $campaignID = null,
        ?\DateTime $startDate = null,
        ?\DateTime $endDate = null
    ): array {
        $conditions = ['status IN ("sent", "failed")'];
        $params = [];
        $types = '';

        if ($templateID !== null) {
            $conditions[] = 'templateID = ?';
            $params[] = $templateID;
            $types .= 'i';
        }

        if ($campaignID !== null) {
            // Assuming you add campaignID to tblEmailQueue
            $conditions[] = 'campaignID = ?';
            $params[] = $campaignID;
            $types .= 'i';
        }

        if ($startDate !== null) {
            $conditions[] = 'createdAt >= ?';
            $params[] = $startDate->format('Y-m-d H:i:s');
            $types .= 's';
        }

        if ($endDate !== null) {
            $conditions[] = 'createdAt <= ?';
            $params[] = $endDate->format('Y-m-d H:i:s');
            $types .= 's';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $conditions);

        $query = "
            SELECT
                COUNT(*) as total_sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(openCount) as total_opens,
                SUM(CASE WHEN openCount > 0 THEN 1 ELSE 0 END) as unique_opens,
                SUM(clickCount) as total_clicks,
                SUM(CASE WHEN clickCount > 0 THEN 1 ELSE 0 END) as unique_clicks,
                ROUND(AVG(CASE WHEN openCount > 0 THEN 1 ELSE 0 END) * 100, 2) as open_rate,
                ROUND(AVG(CASE WHEN clickCount > 0 THEN 1 ELSE 0 END) * 100, 2) as click_rate
            FROM tblEmailQueue
            {$whereClause}
        ";

        $stats = Database::fetchOne($query, $params, $types);

        return $stats ?: [
            'total_sent' => 0,
            'failed_count' => 0,
            'total_opens' => 0,
            'unique_opens' => 0,
            'total_clicks' => 0,
            'unique_clicks' => 0,
            'open_rate' => 0,
            'click_rate' => 0
        ];
    }
}

// ✅ EmailTracker class loaded successfully
