<?php
/**
 * ============================================================================
 * 🔔 SIGNula — Notification Service
 * ============================================================================
 *
 * Purpose: Static service class providing all notification center operations
 *          including creation, retrieval, read/archive state management,
 *          and automated cleanup of expired notifications.
 *
 * Architecture: Static method class following the same pattern as Auth,
 *               SecurityUtils, and AvatarService. All database interactions
 *               use MySQLi prepared statements via the Database singleton.
 *
 * Usage:
 *   NotificationService::create($userID, 'security', 'New Login', 'Login from new device');
 *   NotificationService::createSecurityNotification($userID, 'Alert', 'Suspicious activity');
 *   $count = NotificationService::getUnreadCount($userID);
 *   $data  = NotificationService::getNotifications($userID, 50, 0, true);
 *
 * PHP Version: 8.3+
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php — Prepared statements
 * @see https://dev.mysql.com/doc/refman/8.0/en/json.html — JSON column operations
 * @see https://www.php.net/manual/en/class.datetimeimmutable.php — Date/time handling
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🛡️ Prevent direct access — must be loaded via the application bootstrap
// @see https://www.php.net/manual/en/language.constants.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

/**
 * 🔔 NotificationService — Static notification management class
 *
 * Provides a complete API for managing user notifications within the SIGNula
 * notification center. All methods are static and use the Database singleton
 * for data access.
 *
 * Supported notification types:
 *   - security: Login alerts, password changes, MFA events, suspicious activity
 *   - account:  Profile updates, email changes, linked account modifications
 *   - system:   Platform announcements, maintenance notices, feature updates
 *   - billing:  Payment confirmations, subscription changes, invoice reminders
 *   - social:   Team invitations, shared access notifications
 *
 * Supported priority levels:
 *   - low:    Informational, no urgency (e.g., feature announcements)
 *   - normal: Standard notifications (default)
 *   - high:   Important, should be seen soon (e.g., payment due)
 *   - urgent: Critical, requires immediate attention (e.g., security breach)
 *
 * @package SIGNula
 */
class NotificationService
{
    // ========================================================================
    // 📋 Constants — Valid ENUM values for validation
    // ========================================================================

    /**
     * @var array<string> VALID_TYPES — Allowed notification type values
     * Must match the ENUM definition in tblNotifications.type
     */
    private const VALID_TYPES = ['security', 'account', 'system', 'billing', 'social'];

    /**
     * @var array<string> VALID_PRIORITIES — Allowed priority level values
     * Must match the ENUM definition in tblNotifications.priority
     */
    private const VALID_PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /**
     * @var array<string, string> DEFAULT_ICONS — Default FontAwesome icon per notification type
     * Used when no custom icon is specified during notification creation
     * @see https://fontawesome.com/icons — FontAwesome icon reference
     */
    private const DEFAULT_ICONS = [
        'security' => 'shield-alt',
        'account'  => 'user-cog',
        'system'   => 'info-circle',
        'billing'  => 'credit-card',
        'social'   => 'share-alt',
    ];

    // ========================================================================
    // 🔔 Core CRUD Operations
    // ========================================================================

    /**
     * 📝 Create a new notification for a user
     *
     * Inserts a new notification record into tblNotifications with the specified
     * type, title, message, and optional configuration. The notification will
     * appear as unread in the user's notification center.
     *
     * @param int    $userID  The target user's ID (must exist in tblUsers)
     * @param string $type    Notification type: security|account|system|billing|social
     * @param string $title   Short title displayed in the notification list (max 255 chars)
     * @param string $message Full notification body/description
     * @param array  $options Optional configuration:
     *                        - 'icon'      (string) FontAwesome icon name without 'fa-' prefix
     *                        - 'actionUrl' (string) Click-through URL for the notification
     *                        - 'priority'  (string) low|normal|high|urgent
     *                        - 'metadata'  (array)  Arbitrary JSON-serialisable data
     *                        - 'expiresAt' (string) Expiry datetime in 'Y-m-d H:i:s' format
     *
     * @return int The newly created notificationID, or 0 on failure
     *
     * @throws \InvalidArgumentException If type or priority is invalid (caught internally)
     *
     * @see https://www.php.net/manual/en/mysqli-stmt.get-result.php
     */
    public static function create(int $userID, string $type, string $title, string $message, array $options = []): int
    {
        try {
            // ✅ Validate notification type against allowed ENUM values
            if (!in_array($type, self::VALID_TYPES, true)) {
                ErrorLogger::logError(
                    'InvalidNotificationType',
                    'Attempted to create notification with invalid type: ' . $type,
                    __FILE__,
                    __LINE__
                );
                return 0;
            }

            // 🎨 Extract and validate options with sensible defaults
            $icon     = $options['icon'] ?? self::getDefaultIcon($type);
            $actionUrl = $options['actionUrl'] ?? null;
            $priority  = $options['priority'] ?? 'normal';
            $metadata  = isset($options['metadata']) ? json_encode($options['metadata']) : null;
            $expiresAt = $options['expiresAt'] ?? null;

            // ✅ Validate priority against allowed ENUM values
            if (!in_array($priority, self::VALID_PRIORITIES, true)) {
                ErrorLogger::logError(
                    'InvalidNotificationPriority',
                    'Invalid priority "' . $priority . '" — defaulting to "normal"',
                    __FILE__,
                    __LINE__
                );
                $priority = 'normal';
            }

            // 📝 Truncate title to 255 characters to match VARCHAR(255) column
            $title = mb_substr($title, 0, 255);

            // 💾 Insert the notification using a prepared statement
            // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
            $sql = "INSERT INTO tblNotifications
                        (userID, type, title, message, icon, actionUrl, priority, metadata, expiresAt)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $result = Database::query(
                $sql,
                [
                    $userID,
                    $type,
                    $title,
                    $message,
                    $icon,
                    $actionUrl,
                    $priority,
                    $metadata,
                    $expiresAt,
                ],
                'issssssss'
            );

            // 📊 Get the auto-generated notificationID
            if ($result) {
                $notificationID = Database::getLastInsertId();

                // 📋 Log the notification creation to the activity log
                ActivityLogger::log(
                    $userID,
                    'notification_created',
                    'Notification created: [' . $type . '] ' . $title,
                    'success'
                );

                return (int) $notificationID;
            }

            return 0;

        } catch (\Exception $e) {
            // 🚨 Log the error but don't let notification failures break the application
            ErrorLogger::logError(
                'NotificationCreateError',
                'Failed to create notification: ' . $e->getMessage(),
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );
            return 0;
        }
    }

    /**
     * 🔢 Get the count of unread notifications for a user
     *
     * Used by the notification bell badge in the header to display the
     * number of unread notifications. Only counts non-archived, non-expired
     * notifications.
     *
     * @param int $userID The user's ID
     *
     * @return int Number of unread notifications (0 if none or on error)
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html#function_count
     */
    public static function getUnreadCount(int $userID): int
    {
        try {
            $sql = "SELECT COUNT(*) AS unreadCount
                    FROM tblNotifications
                    WHERE userID = ?
                      AND isRead = 0
                      AND isArchived = 0
                      AND (expiresAt IS NULL OR expiresAt > NOW())";

            $row = Database::fetchOne($sql, [$userID], 'i');

            return (int) ($row['unreadCount'] ?? 0);

        } catch (\Exception $e) {
            ErrorLogger::logError(
                'NotificationCountError',
                'Failed to get unread count for user ' . $userID . ': ' . $e->getMessage(),
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );
            return 0;
        }
    }

    /**
     * 📋 Get a paginated list of notifications for a user
     *
     * Retrieves notifications with optional filtering by read status. Results
     * are ordered by priority (urgent first) then by creation date (newest first).
     * Each notification includes a pre-formatted relative time string for display.
     *
     * @param int  $userID      The user's ID
     * @param int  $limit       Maximum number of notifications to return (default: 50)
     * @param int  $offset      Number of notifications to skip for pagination (default: 0)
     * @param bool $includeRead Whether to include already-read notifications (default: true)
     * @param string|null $type Optional type filter (security|account|system|billing|social)
     *
     * @return array{notifications: array, total: int} Associative array with:
     *         - 'notifications': Array of notification records, each augmented with
     *                            'relativeTime' (e.g., "5 minutes ago")
     *         - 'total': Total count of matching notifications (for pagination)
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/select.html — SELECT with LIMIT/OFFSET
     */
    public static function getNotifications(
        int $userID,
        int $limit = 50,
        int $offset = 0,
        bool $includeRead = true,
        ?string $type = null
    ): array {
        try {
            // 🏗️ Build WHERE clause dynamically based on filters
            $conditions = ['n.userID = ?', 'n.isArchived = 0', '(n.expiresAt IS NULL OR n.expiresAt > NOW())'];
            $params     = [$userID];
            $types      = 'i';

            // 📖 Optionally filter by read status
            if (!$includeRead) {
                $conditions[] = 'n.isRead = 0';
            }

            // 📂 Optionally filter by notification type
            if ($type !== null && in_array($type, self::VALID_TYPES, true)) {
                $conditions[] = 'n.type = ?';
                $params[]     = $type;
                $types       .= 's';
            }

            $whereClause = implode(' AND ', $conditions);

            // 📊 Get total count for pagination metadata
            $countSql = "SELECT COUNT(*) AS total FROM tblNotifications n WHERE {$whereClause}";
            $countRow = Database::fetchOne($countSql, $params, $types);
            $total    = (int) ($countRow['total'] ?? 0);

            // 📋 Fetch the paginated notification list
            // Ordered by: urgent/high priority first, then newest first
            $sql = "SELECT n.*
                    FROM tblNotifications n
                    WHERE {$whereClause}
                    ORDER BY
                        FIELD(n.priority, 'urgent', 'high', 'normal', 'low'),
                        n.createdAt DESC
                    LIMIT ? OFFSET ?";

            // 📦 Append limit and offset parameters
            $params[] = $limit;
            $params[] = $offset;
            $types   .= 'ii';

            $notifications = Database::fetchAll($sql, $params, $types);

            // ⏰ Augment each notification with a human-readable relative time
            if (is_array($notifications)) {
                foreach ($notifications as &$notification) {
                    $notification['relativeTime'] = self::formatRelativeTime($notification['createdAt']);

                    // 📦 Decode JSON metadata for frontend consumption
                    if (!empty($notification['metadata'])) {
                        $notification['metadata'] = json_decode($notification['metadata'], true);
                    }
                }
                unset($notification); // Break the reference to prevent side-effects
            } else {
                $notifications = [];
            }

            return [
                'notifications' => $notifications,
                'total'         => $total,
            ];

        } catch (\Exception $e) {
            ErrorLogger::logError(
                'NotificationListError',
                'Failed to get notifications for user ' . $userID . ': ' . $e->getMessage(),
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );
            return [
                'notifications' => [],
                'total'         => 0,
            ];
        }
    }

    // ========================================================================
    // 📖 State Management — Read / Archive / Delete
    // ========================================================================

    /**
     * ✅ Mark a single notification as read
     *
     * Updates the isRead flag and sets the readAt timestamp. Only affects
     * notifications belonging to the specified user (security check).
     *
     * @param int $notificationID The notification to mark as read
     * @param int $userID         The owning user's ID (prevents cross-user manipulation)
     *
     * @return bool True if the notification was successfully marked, false otherwise
     *
     * @see https://www.php.net/manual/en/mysqli-stmt.affected-rows.php
     */
    public static function markAsRead(int $notificationID, int $userID): bool
    {
        try {
            $sql = "UPDATE tblNotifications
                    SET isRead = 1, readAt = NOW()
                    WHERE notificationID = ?
                      AND userID = ?
                      AND isRead = 0";

            Database::query($sql, [$notificationID, $userID], 'ii');

            return true;

        } catch (\Exception $e) {
            ErrorLogger::logError(
                'NotificationMarkReadError',
                'Failed to mark notification ' . $notificationID . ' as read: ' . $e->getMessage(),
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );
            return false;
        }
    }

    /**
     * ✅ Mark all unread notifications as read for a user
     *
     * Bulk operation that marks every unread, non-archived notification as read.
     * Useful for the "Mark All as Read" button in the notification center.
     *
     * @param int $userID The user whose notifications should be marked
     *
     * @return int Number of notifications that were marked as read
     */
    public static function markAllAsRead(int $userID): int
    {
        try {
            $sql = "UPDATE tblNotifications
                    SET isRead = 1, readAt = NOW()
                    WHERE userID = ?
                      AND isRead = 0
                      AND isArchived = 0";

            Database::query($sql, [$userID], 'i');

            // 📊 Get the number of affected rows
            $affectedRows = Database::getAffectedRows();

            // 📋 Log the bulk action
            if ($affectedRows > 0) {
                ActivityLogger::log(
                    $userID,
                    'notifications_marked_read',
                    'Marked ' . $affectedRows . ' notification(s) as read',
                    'success'
                );
            }

            return (int) $affectedRows;

        } catch (\Exception $e) {
            ErrorLogger::logError(
                'NotificationMarkAllReadError',
                'Failed to mark all notifications as read for user ' . $userID . ': ' . $e->getMessage(),
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );
            return 0;
        }
    }

    /**
     * 🗄️ Archive a notification
     *
     * Moves a notification to the archived state. Archived notifications are
     * excluded from the main notification list but retained for audit purposes.
     *
     * @param int $notificationID The notification to archive
     * @param int $userID         The owning user's ID (security check)
     *
     * @return bool True if successfully archived, false otherwise
     */
    public static function archive(int $notificationID, int $userID): bool
    {
        try {
            $sql = "UPDATE tblNotifications
                    SET isArchived = 1, archivedAt = NOW()
                    WHERE notificationID = ?
                      AND userID = ?
                      AND isArchived = 0";

            Database::query($sql, [$notificationID, $userID], 'ii');

            // 📋 Log the archive action
            ActivityLogger::log(
                $userID,
                'notification_archived',
                'Archived notification #' . $notificationID,
                'success'
            );

            return true;

        } catch (\Exception $e) {
            ErrorLogger::logError(
                'NotificationArchiveError',
                'Failed to archive notification ' . $notificationID . ': ' . $e->getMessage(),
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );
            return false;
        }
    }

    /**
     * 🗑️ Permanently delete a notification
     *
     * Removes a notification from the database entirely. This action cannot
     * be undone. Only deletes notifications belonging to the specified user.
     *
     * @param int $notificationID The notification to delete
     * @param int $userID         The owning user's ID (security check)
     *
     * @return bool True if successfully deleted, false otherwise
     */
    public static function delete(int $notificationID, int $userID): bool
    {
        try {
            $sql = "DELETE FROM tblNotifications
                    WHERE notificationID = ?
                      AND userID = ?";

            Database::query($sql, [$notificationID, $userID], 'ii');

            // 📋 Log the deletion
            ActivityLogger::log(
                $userID,
                'notification_deleted',
                'Deleted notification #' . $notificationID,
                'success'
            );

            return true;

        } catch (\Exception $e) {
            ErrorLogger::logError(
                'NotificationDeleteError',
                'Failed to delete notification ' . $notificationID . ': ' . $e->getMessage(),
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );
            return false;
        }
    }

    // ========================================================================
    // 🧹 Maintenance — Cleanup Operations
    // ========================================================================

    /**
     * 🧹 Clean up expired and old notifications
     *
     * Removes notifications that have either:
     *   1. Passed their expiresAt timestamp, OR
     *   2. Exceeded the configured auto_cleanup_days age limit
     *
     * Intended to be called periodically (e.g., via a cron job or scheduled task).
     *
     * @return int Number of notifications removed
     *
     * @see tblSettings 'notifications.auto_cleanup_days' — Configurable retention period
     */
    public static function cleanupExpired(): int
    {
        try {
            $totalDeleted = 0;

            // 🕐 Step 1: Delete notifications past their explicit expiry date
            $sqlExpired = "DELETE FROM tblNotifications WHERE expiresAt IS NOT NULL AND expiresAt <= NOW()";
            Database::query($sqlExpired, [], '');
            $totalDeleted += Database::getAffectedRows();

            // 📅 Step 2: Delete notifications older than the configured retention period
            $cleanupDays = (int) getSetting('notifications.auto_cleanup_days', '90');

            if ($cleanupDays > 0) {
                $sqlOld = "DELETE FROM tblNotifications WHERE createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)";
                Database::query($sqlOld, [$cleanupDays], 'i');
                $totalDeleted += Database::getAffectedRows();
            }

            // 📋 Log the cleanup results if any notifications were removed
            if ($totalDeleted > 0) {
                ActivityLogger::log(
                    0, // System-level action (no specific user)
                    'notification_cleanup',
                    'Cleaned up ' . $totalDeleted . ' expired/old notification(s)',
                    'success'
                );
            }

            return $totalDeleted;

        } catch (\Exception $e) {
            ErrorLogger::logError(
                'NotificationCleanupError',
                'Failed to clean up expired notifications: ' . $e->getMessage(),
                __FILE__,
                __LINE__,
                $e->getTraceAsString()
            );
            return 0;
        }
    }

    // ========================================================================
    // 🚀 Convenience Methods — Type-specific notification creation
    // ========================================================================

    /**
     * 🔐 Create a security notification
     *
     * Convenience wrapper for creating security-type notifications with
     * appropriate defaults (shield icon, high priority).
     *
     * Common uses: login from new device, password changed, MFA enabled/disabled,
     * suspicious activity detected, account locked.
     *
     * @param int         $userID    Target user's ID
     * @param string      $title     Notification title
     * @param string      $message   Notification body
     * @param string|null $actionUrl Optional action URL
     *
     * @return int The created notificationID, or 0 on failure
     */
    public static function createSecurityNotification(
        int $userID,
        string $title,
        string $message,
        ?string $actionUrl = null
    ): int {
        return self::create($userID, 'security', $title, $message, [
            'actionUrl' => $actionUrl,
            'priority'  => 'high',
        ]);
    }

    /**
     * 👤 Create an account notification
     *
     * Convenience wrapper for creating account-type notifications.
     *
     * Common uses: profile updated, email changed, linked account added/removed,
     * account settings modified.
     *
     * @param int         $userID    Target user's ID
     * @param string      $title     Notification title
     * @param string      $message   Notification body
     * @param string|null $actionUrl Optional action URL
     *
     * @return int The created notificationID, or 0 on failure
     */
    public static function createAccountNotification(
        int $userID,
        string $title,
        string $message,
        ?string $actionUrl = null
    ): int {
        return self::create($userID, 'account', $title, $message, [
            'actionUrl' => $actionUrl,
            'priority'  => 'normal',
        ]);
    }

    /**
     * ⚙️ Create a system notification
     *
     * Convenience wrapper for creating system-type notifications.
     *
     * Common uses: platform maintenance scheduled, new feature announcement,
     * terms of service updated, system-wide notices.
     *
     * @param int         $userID    Target user's ID
     * @param string      $title     Notification title
     * @param string      $message   Notification body
     * @param string|null $actionUrl Optional action URL
     *
     * @return int The created notificationID, or 0 on failure
     */
    public static function createSystemNotification(
        int $userID,
        string $title,
        string $message,
        ?string $actionUrl = null
    ): int {
        return self::create($userID, 'system', $title, $message, [
            'actionUrl' => $actionUrl,
            'priority'  => 'low',
        ]);
    }

    // ========================================================================
    // 🛠️ Private Helper Methods
    // ========================================================================

    /**
     * ⏰ Format a datetime string as a human-readable relative time
     *
     * Converts a MySQL DATETIME string into a user-friendly relative time
     * description. Used to display "5 minutes ago", "yesterday", etc. in
     * the notification list.
     *
     * Output examples:
     *   - Less than 60 seconds:  "just now"
     *   - Less than 60 minutes:  "5 minutes ago" / "1 minute ago"
     *   - Less than 24 hours:    "3 hours ago" / "1 hour ago"
     *   - Yesterday:             "yesterday"
     *   - Less than 7 days:      "3 days ago"
     *   - Same year:             "Mar 5" (abbreviated month + day)
     *   - Different year:        "Mar 5, 2025"
     *
     * @param string $datetime MySQL DATETIME string (e.g., '2026-03-08 14:30:00')
     *
     * @return string Human-readable relative time string
     *
     * @see https://www.php.net/manual/en/class.datetimeimmutable.php
     * @see https://www.php.net/manual/en/dateinterval.format.php
     */
    private static function formatRelativeTime(string $datetime): string
    {
        try {
            $now  = new \DateTimeImmutable('now');
            $then = new \DateTimeImmutable($datetime);
            $diff = $now->getTimestamp() - $then->getTimestamp();

            // ⏱️ Less than 60 seconds ago
            if ($diff < 60) {
                return 'just now';
            }

            // ⏱️ Less than 60 minutes ago
            if ($diff < 3600) {
                $minutes = (int) floor($diff / 60);
                return $minutes === 1 ? '1 minute ago' : $minutes . ' minutes ago';
            }

            // ⏱️ Less than 24 hours ago
            if ($diff < 86400) {
                $hours = (int) floor($diff / 3600);
                return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
            }

            // 📅 Yesterday (between 24 and 48 hours)
            if ($diff < 172800) {
                return 'yesterday';
            }

            // 📅 Less than 7 days ago
            if ($diff < 604800) {
                $days = (int) floor($diff / 86400);
                return $days . ' days ago';
            }

            // 📅 Same year — show abbreviated month and day
            if ($then->format('Y') === $now->format('Y')) {
                return $then->format('M j');
            }

            // 📅 Different year — include the year
            return $then->format('M j, Y');

        } catch (\Exception $e) {
            // 🔄 Fallback: return the original datetime if parsing fails
            return $datetime;
        }
    }

    /**
     * 🎨 Get the default FontAwesome icon for a notification type
     *
     * Returns the appropriate icon name (without the 'fa-' prefix) based on
     * the notification type. Used when no custom icon is provided during
     * notification creation.
     *
     * @param string $type Notification type (security|account|system|billing|social)
     *
     * @return string FontAwesome icon name (e.g., 'shield-alt', 'user-cog')
     *
     * @see https://fontawesome.com/icons — FontAwesome icon reference
     */
    private static function getDefaultIcon(string $type): string
    {
        return self::DEFAULT_ICONS[$type] ?? 'bell';
    }
}
