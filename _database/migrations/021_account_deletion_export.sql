-- ============================================================================
-- SIGNula Database Migration 021
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- 📋 Migration: GDPR Account Deletion & Data Export
-- 📅 Date:      2026-03-08
-- 🔖 Version:   2.7.0-beta
-- 📝 Description:
--   - Adds deletion scheduling columns to tblUsers
--   - Creates tblDataExportRequests for tracking data portability requests
--   - Adds settings for deletion grace period and export retention
--   - Adds email templates for deletion confirmation and data export
--
-- 🔗 Related: GitHub Issue #53
-- ============================================================================

-- ============================================================================
-- 🏗️ ADD DELETION COLUMNS TO tblUsers
-- ============================================================================
-- deletionRequestedAt: when the user requested account deletion
-- deletionScheduledFor: when the deletion will actually execute (after grace period)
-- deletionReason: optional reason provided by the user
-- ============================================================================

ALTER TABLE `tblUsers`
    ADD COLUMN `deletionRequestedAt` DATETIME NULL DEFAULT NULL
        COMMENT 'When the user requested account deletion'
        AFTER `deletedAt`,
    ADD COLUMN `deletionScheduledFor` DATETIME NULL DEFAULT NULL
        COMMENT 'When the account will be permanently deleted (after grace period)'
        AFTER `deletionRequestedAt`,
    ADD COLUMN `deletionReason` TEXT NULL DEFAULT NULL
        COMMENT 'Optional reason provided by user for account deletion'
        AFTER `deletionScheduledFor`;

-- 📇 Index for efficient scheduled deletion lookups
ALTER TABLE `tblUsers`
    ADD INDEX `idx_users_deletion_scheduled` (`deletionScheduledFor`);

-- ============================================================================
-- 🏗️ CREATE DATA EXPORT REQUESTS TABLE
-- ============================================================================
-- Tracks GDPR Article 20 (Right to Data Portability) requests.
-- Each request generates a JSON file containing all user data.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblDataExportRequests` (
    `exportID`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique export request identifier',
    `userID`        BIGINT UNSIGNED NOT NULL
        COMMENT 'Reference to tblUsers.userID',
    `status`        ENUM('pending', 'processing', 'completed', 'failed', 'expired', 'downloaded')
        NOT NULL DEFAULT 'pending'
        COMMENT 'Current status of the export request',
    `filePath`      VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Relative path to the generated export file',
    `fileSize`      BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Size of the export file in bytes',
    `fileHash`      VARCHAR(64) NULL DEFAULT NULL
        COMMENT 'SHA-256 hash of the export file for integrity verification',
    `downloadToken` VARCHAR(128) NULL DEFAULT NULL
        COMMENT 'Secure token for downloading the export file',
    `downloadCount` SMALLINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of times the file has been downloaded',
    `ipAddress`     VARCHAR(45) NULL
        COMMENT 'IP address of the request',
    `errorMessage`  TEXT NULL DEFAULT NULL
        COMMENT 'Error details if the export failed',
    `requestedAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When the export was requested',
    `completedAt`   DATETIME NULL DEFAULT NULL
        COMMENT 'When the export file was generated',
    `expiresAt`     DATETIME NULL DEFAULT NULL
        COMMENT 'When the export file will be auto-deleted',
    `downloadedAt`  DATETIME NULL DEFAULT NULL
        COMMENT 'When the file was last downloaded',

    PRIMARY KEY (`exportID`),

    CONSTRAINT `fk_export_user`
        FOREIGN KEY (`userID`) REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    INDEX `idx_export_user_status` (`userID`, `status`),
    INDEX `idx_export_expires` (`expiresAt`),
    INDEX `idx_export_token` (`downloadToken`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='GDPR data export (portability) requests — Article 20';

-- ============================================================================
-- ⚙️ SETTINGS
-- ============================================================================

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingDescription`, `settingCategory`, `isSensitive`)
VALUES
    ('gdpr.deletion.grace_period_days', '30',
     'Number of days to wait before permanently deleting an account after user request',
     'privacy', 0),

    ('gdpr.deletion.notify_admin', 'true',
     'Send admin notification when a user requests account deletion',
     'privacy', 0),

    ('gdpr.export.retention_hours', '72',
     'Hours to keep data export files before auto-deleting',
     'privacy', 0),

    ('gdpr.export.rate_limit_hours', '24',
     'Minimum hours between data export requests per user',
     'privacy', 0),

    ('gdpr.export.max_file_size_mb', '100',
     'Maximum allowed export file size in megabytes',
     'privacy', 0);

-- ============================================================================
-- 📧 EMAIL TEMPLATES
-- ============================================================================

INSERT INTO `tblEmailTemplates` (`templateKey`, `templateName`, `subject`, `htmlBody`, `textBody`, `requiredVariables`, `isActive`)
VALUES
    (
        'account_deletion_requested',
        'Account Deletion Request Confirmation',
        'Account Deletion Request — {{gracePeriod}} day grace period',
        '<h2>Account Deletion Requested</h2><p>Hello {{displayName}},</p><p>We have received your request to delete your SIGNula account. Your account is scheduled for permanent deletion on <strong>{{deletionDate}}</strong>.</p><p>During the {{gracePeriod}}-day grace period, you can cancel this request by logging in and visiting your Privacy Settings.</p><p><strong>What happens on deletion:</strong></p><ul><li>All your personal data will be permanently erased</li><li>Your linked accounts will be disconnected</li><li>Your payment history will be anonymised</li><li>This action cannot be undone</li></ul><p>If you did not request this, please <a href="{{loginUrl}}">log in immediately</a> to cancel the deletion and secure your account.</p>',
        'Account Deletion Requested\n\nHello {{displayName}},\n\nWe have received your request to delete your SIGNula account. Your account is scheduled for permanent deletion on {{deletionDate}}.\n\nDuring the {{gracePeriod}}-day grace period, you can cancel this request by logging in and visiting your Privacy Settings.\n\nIf you did not request this, please log in immediately to cancel and secure your account.',
        '["displayName", "deletionDate", "gracePeriod", "loginUrl"]',
        1
    ),
    (
        'account_deletion_completed',
        'Account Deletion Complete',
        'Your SIGNula account has been deleted',
        '<h2>Account Deleted</h2><p>Hello,</p><p>Your SIGNula account and all associated personal data have been permanently deleted as requested.</p><p>If you wish to use SIGNula in the future, you will need to create a new account.</p><p>Thank you for using SIGNula.</p>',
        'Account Deleted\n\nHello,\n\nYour SIGNula account and all associated personal data have been permanently deleted as requested.\n\nIf you wish to use SIGNula in the future, you will need to create a new account.\n\nThank you for using SIGNula.',
        '[]',
        1
    ),
    (
        'data_export_ready',
        'Your Data Export is Ready',
        'Your SIGNula data export is ready for download',
        '<h2>Data Export Ready</h2><p>Hello {{displayName}},</p><p>Your personal data export is now ready for download.</p><p><a href="{{downloadUrl}}">Download Your Data</a></p><p><strong>Important:</strong> This download link will expire in {{expiryHours}} hours. The file is in JSON format and contains all your personal data stored in SIGNula.</p>',
        'Data Export Ready\n\nHello {{displayName}},\n\nYour personal data export is now ready for download.\n\nDownload at: {{downloadUrl}}\n\nThis link will expire in {{expiryHours}} hours.',
        '["displayName", "downloadUrl", "expiryHours"]',
        1
    );

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================
