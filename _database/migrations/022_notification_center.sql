-- ============================================================================
-- 🔔 Migration 022: Notification Center System
-- ============================================================================
--
-- Purpose: Creates the notification center infrastructure for in-app
--          user notifications across security, account, system, billing,
--          and social categories.
--
-- Tables created:
--   - tblNotifications — Stores all user notifications with type, priority,
--                        read/archive status, and optional metadata
--
-- Settings added:
--   - notifications.enabled          — Master toggle for in-app notifications
--   - notifications.polling_interval — AJAX polling interval in seconds
--   - notifications.max_display      — Max notifications shown in the dropdown
--   - notifications.auto_cleanup_days — Auto-purge notifications older than N days
--
-- @see https://dev.mysql.com/doc/refman/8.0/en/json.html — JSON column type
-- @see https://dev.mysql.com/doc/refman/8.0/en/innodb-foreign-key-constraints.html
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- @package    SIGNula
-- @version    1.0.0
-- ============================================================================

-- ============================================================================
-- 📋 tblNotifications — User notification storage
-- ============================================================================
-- Stores individual notifications for each user. Supports multiple types
-- (security, account, system, billing, social), priority levels, read/archive
-- state tracking, optional action URLs, and arbitrary JSON metadata.
--
-- Indexes are optimised for the most common queries:
--   - idx_user_unread:  Fetching a user's unread notifications (bell badge count)
--   - idx_user_type:    Filtering notifications by type (tab filters)
--   - idx_expires:      Cleanup cron finding expired notifications
--   - idx_created:      General chronological listing
-- ============================================================================
CREATE TABLE IF NOT EXISTS tblNotifications (
    -- 🔑 Primary key — auto-incrementing notification identifier
    notificationID INT AUTO_INCREMENT PRIMARY KEY,

    -- 👤 The user this notification belongs to (CASCADE delete when user is removed)
    userID BIGINT UNSIGNED NOT NULL,  -- [mig-fix] BIGINT UNSIGNED to match tblUsers.userID (FK)

    -- 📂 Notification category/type
    -- security: login alerts, password changes, MFA events
    -- account:  profile updates, email changes, linked accounts
    -- system:   maintenance notices, feature announcements, platform updates
    -- billing:  payment confirmations, subscription changes, invoice reminders
    -- social:   shared access, team invitations, social link events
    type ENUM('security', 'account', 'system', 'billing', 'social') NOT NULL DEFAULT 'system',

    -- 📝 Notification content
    title VARCHAR(255) NOT NULL,          -- Short summary displayed in notification list
    message TEXT NOT NULL,                 -- Full notification body/description

    -- 🎨 Display configuration
    icon VARCHAR(50) DEFAULT 'bell',       -- FontAwesome icon name (without 'fa-' prefix)
    actionUrl VARCHAR(500) NULL,           -- Optional URL for "View" / click-through action
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',

    -- 📖 Read state tracking
    isRead TINYINT(1) NOT NULL DEFAULT 0,  -- 0 = unread, 1 = read
    readAt DATETIME NULL,                  -- Timestamp when marked as read

    -- 🗄️ Archive state tracking
    isArchived TINYINT(1) NOT NULL DEFAULT 0, -- 0 = active, 1 = archived
    archivedAt DATETIME NULL,                 -- Timestamp when archived

    -- 📦 Extensible metadata (JSON)
    -- Allows storing additional context without schema changes
    -- Examples: {"source": "login", "ip": "1.2.3.4"}, {"planName": "Pro"}
    -- @see https://dev.mysql.com/doc/refman/8.0/en/json.html
    metadata JSON NULL,

    -- ⏰ Timestamps
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- When notification was created
    expiresAt DATETIME NULL,                                 -- Optional auto-expiry timestamp

    -- 🔗 Foreign key constraint — ties notification to a valid user
    -- ON DELETE CASCADE ensures notifications are removed when the user account is deleted
    CONSTRAINT fk_notification_user FOREIGN KEY (userID)
        REFERENCES tblUsers(userID) ON DELETE CASCADE,

    -- 📊 Indexes for common query patterns
    INDEX idx_user_unread (userID, isRead, createdAt DESC),   -- Bell badge count + unread list
    INDEX idx_user_type (userID, type),                        -- Type filter tabs
    INDEX idx_expires (expiresAt),                             -- Expired notification cleanup
    INDEX idx_created (createdAt)                              -- Chronological listing
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- ⚙️ Default Settings for Notification Center
-- ============================================================================
-- These settings control the behaviour of the notification system and can be
-- modified by Global Admins via the Settings UI or directly in tblSettings.
--
-- ON DUPLICATE KEY UPDATE prevents errors if settings already exist (idempotent).
-- ============================================================================
INSERT INTO tblSettings (settingKey, settingValue, settingType, settingCategory, description, isEditable) VALUES

    -- 🔔 Master toggle — enables or disables the entire notification system
    ('notifications.enabled', '1', 'boolean', 'notifications',
     'Enable in-app notification center for all users', 0),

    -- ⏱️ Polling interval — how often the frontend checks for new notifications (seconds)
    -- Lower values = more real-time but higher server load
    ('notifications.polling_interval', '30', 'integer', 'notifications',
     'AJAX polling interval in seconds for checking new notifications', 0),

    -- 📊 Max display — maximum number of notifications shown in the dropdown/list
    ('notifications.max_display', '50', 'integer', 'notifications',
     'Maximum number of notifications to display in the notification list', 0),

    -- 🧹 Auto-cleanup — automatically delete notifications older than N days
    -- Set to 0 to disable auto-cleanup
    ('notifications.auto_cleanup_days', '90', 'integer', 'notifications',
     'Automatically delete notifications older than this many days (0 = disabled)', 0)

ON DUPLICATE KEY UPDATE settingKey = settingKey;
