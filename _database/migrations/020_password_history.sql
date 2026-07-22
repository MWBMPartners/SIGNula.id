-- ============================================================================
-- SIGNula Database Migration 020
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- 📋 Migration: Password History & Enhanced Password Security
-- 📅 Date:      2026-03-08
-- 🔖 Version:   2.7.0-beta
-- 📝 Description:
--   - Creates tblPasswordHistory for tracking previous password hashes
--   - Adds settings for password history count and reset notifications
--   - Enables prevention of password reuse across reset and change flows
--
-- 🔗 Related: GitHub Issue #51
-- ============================================================================

-- ============================================================================
-- 🏗️ CREATE PASSWORD HISTORY TABLE
-- ============================================================================
-- Stores the last N password hashes per user to prevent reuse.
-- Hashes are stored using the same algorithm as tblUsers.passwordHash
-- (Argon2id by default). The table is checked during both password
-- change (authenticated) and password reset (via token) flows.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblPasswordHistory` (
    `historyID`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique history entry identifier',
    `userID`        BIGINT UNSIGNED NOT NULL
        COMMENT 'Reference to tblUsers.userID',
    `passwordHash`  VARCHAR(255) NOT NULL
        COMMENT 'Argon2id hash of the previous password',
    `changedBy`     ENUM('user', 'admin', 'system') NOT NULL DEFAULT 'user'
        COMMENT 'Who initiated the password change',
    `changedVia`    ENUM('change', 'reset', 'admin_reset', 'mass_reset') NOT NULL DEFAULT 'change'
        COMMENT 'How the password was changed',
    `ipAddress`     VARCHAR(45) NULL
        COMMENT 'IP address of the request (IPv4 or IPv6)',
    `userAgent`     TEXT NULL
        COMMENT 'User agent string of the request',
    `createdAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When this password was set (not when it was replaced)',

    PRIMARY KEY (`historyID`),

    -- 🔗 Foreign key to tblUsers — cascade delete to clean up when user is removed
    CONSTRAINT `fk_ph_user`
        FOREIGN KEY (`userID`) REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    -- 📇 Indexes for efficient lookups
    INDEX `idx_ph_user_created` (`userID`, `createdAt` DESC),
    INDEX `idx_ph_changed_by` (`changedBy`),
    INDEX `idx_ph_changed_via` (`changedVia`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Password history for reuse prevention — stores last N hashes per user';

-- ============================================================================
-- ⚙️ SETTINGS — Password History & Notification Configuration
-- ============================================================================

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `description`, `settingCategory`, `isSensitive`)
VALUES
    -- 🔐 Password history settings
    ('security.password.history_count', '5',
     'Number of previous passwords to check against when changing/resetting (0 to disable)',
     'security', 0),

    ('security.password.min_length', '12',
     'Minimum password length requirement',
     'security', 0),

    ('security.password.require_uppercase', 'true',
     'Require at least one uppercase letter in passwords',
     'security', 0),

    ('security.password.require_lowercase', 'true',
     'Require at least one lowercase letter in passwords',
     'security', 0),

    ('security.password.require_number', 'true',
     'Require at least one number in passwords',
     'security', 0),

    ('security.password.require_special', 'true',
     'Require at least one special character in passwords',
     'security', 0),

    -- 📧 Password change/reset notification settings
    ('security.password.notify_on_change', 'true',
     'Send security alert email when a user changes their password (authenticated)',
     'security', 0),

    ('security.password.notify_on_reset', 'true',
     'Send security alert email when a password is reset via token',
     'security', 0)
-- [mig-fix] idempotent: some of these keys (e.g. security.password.min_length)
-- are already seeded by the consolidated installer. ON DUPLICATE KEY UPDATE
-- keeps replay safe without overwriting an operator's tuned value.
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ============================================================================
-- 📧 EMAIL TEMPLATE — Password Changed Notification
-- ============================================================================

INSERT INTO `tblEmailTemplates` (`templateKey`, `templateName`, `subject`, `bodyHTML`, `bodyText`, `requiredVariables`, `isActive`)
VALUES
    (
        'password_changed_notification',
        'Password Changed Security Alert',
        'Security Alert: Your SIGNula password was changed',
        '<h2>Password Changed</h2><p>Hello {{displayName}},</p><p>Your SIGNula account password was changed on <strong>{{changedAt}}</strong>.</p><p><strong>Details:</strong></p><ul><li><strong>Method:</strong> {{changeMethod}}</li><li><strong>IP Address:</strong> {{ipAddress}}</li><li><strong>Device:</strong> {{userAgent}}</li></ul><p>If you did not make this change, please <a href="{{resetUrl}}">reset your password immediately</a> and contact support.</p>',
        'Password Changed\n\nHello {{displayName}},\n\nYour SIGNula account password was changed on {{changedAt}}.\n\nDetails:\n- Method: {{changeMethod}}\n- IP Address: {{ipAddress}}\n- Device: {{userAgent}}\n\nIf you did not make this change, please reset your password immediately at: {{resetUrl}}',
        '["displayName", "changedAt", "changeMethod", "ipAddress", "userAgent", "resetUrl"]',
        1
    );

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================
-- Post-migration verification:
--   SHOW TABLES LIKE 'tblPasswordHistory';
--   SELECT * FROM tblSettings WHERE settingKey LIKE 'security.password.%';
--   SELECT * FROM tblEmailTemplates WHERE templateKey = 'password_changed_notification';
-- ============================================================================
