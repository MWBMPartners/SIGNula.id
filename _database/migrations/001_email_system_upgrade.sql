-- ============================================================================
-- SIGNula Database Schema
-- 
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
-- 
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 📧 SIGNula - Email System Migration Script
-- ============================================================================
--
-- Migration: 001 - Email System Upgrade
-- Date: 2026-02-02
-- Description: Migrates existing email system to enhanced version 2.0
--
-- This script safely upgrades the email system with:
-- - New tblEmailQueue columns for tracking, scheduling, and provider management
-- - Enhanced tblEmailTemplates with versioning and metadata
-- - New tables for tracking, unsubscribes, provider health, and campaigns
-- - Proper indexes for performance
--
-- Safe to run multiple times (uses IF NOT EXISTS checks)
--
-- ============================================================================

-- Start transaction for atomic migration
START TRANSACTION;

-- ============================================================================
-- 📬 Upgrade tblEmailQueue
-- ============================================================================

-- Add new columns to tblEmailQueue if they don't exist

-- CC/BCC/Attachments support
ALTER TABLE `tblEmailQueue`
    ADD COLUMN IF NOT EXISTS `ccRecipients` JSON NULL COMMENT 'Array of CC email addresses' AFTER `replyToEmail`,
    ADD COLUMN IF NOT EXISTS `bccRecipients` JSON NULL COMMENT 'Array of BCC email addresses' AFTER `ccRecipients`,
    ADD COLUMN IF NOT EXISTS `attachments` JSON NULL COMMENT 'Array of attachment data' AFTER `bccRecipients`;

-- Rename replyTo to replyToEmail for consistency (if old column exists)
SET @dbname = DATABASE();
SET @tablename = 'tblEmailQueue';
SET @columnname = 'replyTo';
SET @newcolumnname = 'replyToEmail';

SET @query = CONCAT('ALTER TABLE `', @tablename, '` CHANGE COLUMN `', @columnname, '` `', @newcolumnname, '` VARCHAR(255) NULL');
SET @query_check = CONCAT('SELECT COUNT(*) INTO @colexists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = "', @dbname, '" AND TABLE_NAME = "', @tablename, '" AND COLUMN_NAME = "', @columnname, '"');

PREPARE stmt_check FROM @query_check;
EXECUTE stmt_check;
DEALLOCATE PREPARE stmt_check;

SET @query_final = IF(@colexists > 0, @query, 'SELECT "Column already renamed" AS message');
PREPARE stmt FROM @query_final;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Rename scheduledFor to scheduledAt for consistency
SET @columnname = 'scheduledFor';
SET @newcolumnname = 'scheduledAt';

SET @query = CONCAT('ALTER TABLE `', @tablename, '` CHANGE COLUMN `', @columnname, '` `', @newcolumnname, '` DATETIME NULL COMMENT "When to send the email"');
SET @query_check = CONCAT('SELECT COUNT(*) INTO @colexists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = "', @dbname, '" AND TABLE_NAME = "', @tablename, '" AND COLUMN_NAME = "', @columnname, '"');

PREPARE stmt_check FROM @query_check;
EXECUTE stmt_check;
DEALLOCATE PREPARE stmt_check;

SET @query_final = IF(@colexists > 0, @query, 'SELECT "Column already renamed" AS message');
PREPARE stmt FROM @query_final;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add provider-related columns
ALTER TABLE `tblEmailQueue`
    ADD COLUMN IF NOT EXISTS `provider` VARCHAR(50) NULL COMMENT 'Provider used to send' AFTER `maxAttempts`,
    ADD COLUMN IF NOT EXISTS `providerMessageID` VARCHAR(255) NULL COMMENT 'Message ID from provider' AFTER `provider`;

-- Add tracking columns
ALTER TABLE `tblEmailQueue`
    ADD COLUMN IF NOT EXISTS `trackingEnabled` BOOLEAN NOT NULL DEFAULT FALSE AFTER `sentAt`,
    ADD COLUMN IF NOT EXISTS `trackingToken` VARCHAR(64) NULL COMMENT 'Unique token for tracking' AFTER `trackingEnabled`,
    ADD COLUMN IF NOT EXISTS `openedAt` DATETIME NULL AFTER `trackingToken`,
    ADD COLUMN IF NOT EXISTS `openCount` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `openedAt`,
    ADD COLUMN IF NOT EXISTS `clickCount` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `openCount`;

-- Add metadata column
ALTER TABLE `tblEmailQueue`
    ADD COLUMN IF NOT EXISTS `metadata` JSON NULL COMMENT 'Additional metadata' AFTER `lastError`;

-- Update status enum if needed
ALTER TABLE `tblEmailQueue`
    MODIFY COLUMN `status` ENUM('pending', 'sending', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'pending';

-- Add new indexes for performance
ALTER TABLE `tblEmailQueue`
    ADD INDEX IF NOT EXISTS `idx_provider` (`provider`),
    ADD INDEX IF NOT EXISTS `idx_tracking_token` (`trackingToken`),
    ADD INDEX IF NOT EXISTS `idx_status_attempts` (`status`, `attemptCount`);

-- ============================================================================
-- 📋 Upgrade tblEmailTemplates
-- ============================================================================

-- Add new columns to tblEmailTemplates if they don't exist

-- Template versioning
ALTER TABLE `tblEmailTemplates`
    ADD COLUMN IF NOT EXISTS `version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `variableDescriptions`,
    ADD COLUMN IF NOT EXISTS `previousVersionID` INT UNSIGNED NULL AFTER `version`;

-- Template settings
ALTER TABLE `tblEmailTemplates`
    ADD COLUMN IF NOT EXISTS `trackingEnabled` BOOLEAN NOT NULL DEFAULT FALSE AFTER `isActive`,
    ADD COLUMN IF NOT EXISTS `defaultAttachments` JSON NULL COMMENT 'Array of default attachments' AFTER `replyTo`;

-- Metadata
ALTER TABLE `tblEmailTemplates`
    ADD COLUMN IF NOT EXISTS `metadata` JSON NULL AFTER `previousVersionID`;

-- Creator tracking
ALTER TABLE `tblEmailTemplates`
    ADD COLUMN IF NOT EXISTS `createdBy` INT UNSIGNED NULL AFTER `updatedAt`,
    ADD COLUMN IF NOT EXISTS `updatedBy` INT UNSIGNED NULL AFTER `createdBy`;

-- Add new indexes
ALTER TABLE `tblEmailTemplates`
    ADD INDEX IF NOT EXISTS `idx_version` (`version`);

-- Add foreign key for previous version if not exists
SET @fk_check = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'tblEmailTemplates'
                 AND CONSTRAINT_NAME = 'fk_email_template_previous_version');

SET @alter_query = IF(@fk_check = 0,
    'ALTER TABLE `tblEmailTemplates` ADD CONSTRAINT `fk_email_template_previous_version` FOREIGN KEY (`previousVersionID`) REFERENCES `tblEmailTemplates` (`templateID`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT "Foreign key already exists" AS message');

PREPARE stmt FROM @alter_query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign keys for creator tracking if not exist
SET @fk_check = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'tblEmailTemplates'
                 AND CONSTRAINT_NAME = 'fk_email_template_created_by');

SET @alter_query = IF(@fk_check = 0,
    'ALTER TABLE `tblEmailTemplates` ADD CONSTRAINT `fk_email_template_created_by` FOREIGN KEY (`createdBy`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT "Foreign key already exists" AS message');

PREPARE stmt FROM @alter_query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_check = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'tblEmailTemplates'
                 AND CONSTRAINT_NAME = 'fk_email_template_updated_by');

SET @alter_query = IF(@fk_check = 0,
    'ALTER TABLE `tblEmailTemplates` ADD CONSTRAINT `fk_email_template_updated_by` FOREIGN KEY (`updatedBy`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT "Foreign key already exists" AS message');

PREPARE stmt FROM @alter_query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 📊 Create New Tables
-- ============================================================================

-- Email Tracking Events
CREATE TABLE IF NOT EXISTS `tblEmailTrackingEvents` (
    `eventID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `emailID` INT UNSIGNED NOT NULL,
    `eventType` ENUM('open', 'click', 'bounce', 'complaint', 'unsubscribe') NOT NULL,
    `clickedUrl` VARCHAR(1000) NULL,
    `ipAddress` VARCHAR(45) NULL,
    `userAgent` VARCHAR(500) NULL,
    `referer` VARCHAR(1000) NULL,
    `bounceType` ENUM('hard', 'soft', 'transient') NULL,
    `bounceReason` TEXT NULL,
    `metadata` JSON NULL,
    `eventAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`eventID`),
    INDEX `idx_email` (`emailID`),
    INDEX `idx_event_type` (`eventType`),
    INDEX `idx_event_at` (`eventAt`),

    CONSTRAINT `fk_tracking_email`
        FOREIGN KEY (`emailID`) REFERENCES `tblEmailQueue` (`emailID`)
        ON DELETE CASCADE ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Email tracking events';

-- Unsubscribe List
CREATE TABLE IF NOT EXISTS `tblEmailUnsubscribes` (
    `unsubscribeID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL,
    `userID` INT UNSIGNED NULL,
    `reason` ENUM('user_request', 'bounce', 'complaint', 'manual', 'system') NOT NULL DEFAULT 'user_request',
    `reasonDetails` TEXT NULL,
    `category` VARCHAR(50) NULL,
    `metadata` JSON NULL,
    `unsubscribedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resubscribedAt` DATETIME NULL,

    PRIMARY KEY (`unsubscribeID`),
    UNIQUE KEY `uk_email_category` (`email`, `category`),
    INDEX `idx_email` (`email`),
    INDEX `idx_user` (`userID`),

    CONSTRAINT `fk_unsubscribe_user`
        FOREIGN KEY (`userID`) REFERENCES `tblUsers` (`userID`)
        ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Email unsubscribe list';

-- Provider Health Monitoring
CREATE TABLE IF NOT EXISTS `tblEmailProviderHealth` (
    `healthID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider` VARCHAR(50) NOT NULL,
    `isHealthy` BOOLEAN NOT NULL DEFAULT TRUE,
    `successCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `failureCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `totalAttempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `successRate` DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    `averageResponseTime` INT UNSIGNED NULL,
    `lastError` TEXT NULL,
    `lastErrorAt` DATETIME NULL,
    `lastSuccessAt` DATETIME NULL,
    `lastCheckedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `checkIntervalMinutes` INT UNSIGNED NOT NULL DEFAULT 5,
    `metadata` JSON NULL,

    PRIMARY KEY (`healthID`),
    UNIQUE KEY `uk_provider` (`provider`),
    INDEX `idx_is_healthy` (`isHealthy`),
    INDEX `idx_last_checked` (`lastCheckedAt`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Email provider health monitoring';

-- Email Campaigns
CREATE TABLE IF NOT EXISTS `tblEmailCampaigns` (
    `campaignID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaignName` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `templateID` INT UNSIGNED NULL,
    `category` VARCHAR(50) NULL,
    `status` ENUM('draft', 'scheduled', 'sending', 'sent', 'cancelled') NOT NULL DEFAULT 'draft',
    `totalRecipients` INT UNSIGNED NOT NULL DEFAULT 0,
    `sentCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `failedCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `openCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `clickCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `bounceCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `unsubscribeCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `scheduledAt` DATETIME NULL,
    `startedAt` DATETIME NULL,
    `completedAt` DATETIME NULL,
    `createdBy` INT UNSIGNED NULL,
    `metadata` JSON NULL,

    PRIMARY KEY (`campaignID`),
    INDEX `idx_status` (`status`),
    INDEX `idx_scheduled` (`scheduledAt`),
    INDEX `idx_created_by` (`createdBy`),

    CONSTRAINT `fk_campaign_template`
        FOREIGN KEY (`templateID`) REFERENCES `tblEmailTemplates` (`templateID`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `fk_campaign_created_by`
        FOREIGN KEY (`createdBy`) REFERENCES `tblUsers` (`userID`)
        ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Email marketing campaigns';

-- ============================================================================
-- 🔌 Insert Initial Data
-- ============================================================================

-- Insert provider health records
INSERT IGNORE INTO `tblEmailProviderHealth` (`provider`, `checkIntervalMinutes`)
VALUES
('microsoft_graph', 5),
('gmail_api', 5),
('smtp', 5);

-- Insert default email templates (only if they don't exist)
INSERT IGNORE INTO `tblEmailTemplates`
(`templateKey`, `templateName`, `description`, `subject`, `bodyHTML`, `bodyText`, `category`, `trackingEnabled`, `requiredVariables`)
VALUES
(
    'mfa_code',
    'MFA Verification Code',
    'Multi-factor authentication code',
    'Your Verification Code - SIGNula',
    '<h1>Verification Code</h1><p>Hi {{displayName}},</p><p>Your verification code is:</p><h2 style="background-color:#f8f9fa;padding:20px;text-align:center;letter-spacing:5px;">{{mfaCode}}</h2><p>This code will expire in {{expiryMinutes}} minutes.</p><p>If you did not request this code, please secure your account immediately.</p>',
    'Hi {{displayName}}, Your verification code is: {{mfaCode}}. This code expires in {{expiryMinutes}} minutes.',
    'transactional',
    FALSE,
    '["displayName", "mfaCode", "expiryMinutes"]'
);

-- Commit the transaction
COMMIT;

-- ============================================================================
-- ✅ Migration Complete
-- ============================================================================

SELECT '✅ Email system migration completed successfully!' AS status;
