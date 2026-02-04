-- ============================================================================
-- 💧 SIGNula - Email Drip Campaign Schema Migration
-- ============================================================================
--
-- Purpose: Add drip campaign tables for automated email sequences
-- Version: 2.2.0
-- PHP Version: 8.3+
--
-- Features:
-- - Event-triggered campaigns
-- - Time-delayed sequences
-- - Conditional logic
-- - Subscriber tracking
-- - Performance analytics
--
-- ============================================================================

-- ============================================================================
-- 💧 DRIP CAMPAIGNS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailDripCampaigns` (
    `campaignID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaignName` VARCHAR(255) NOT NULL COMMENT 'Campaign name',
    `description` TEXT NULL COMMENT 'Campaign description',

    -- 🎯 Trigger Configuration
    `triggerType` ENUM('event', 'manual', 'date') NOT NULL DEFAULT 'manual' COMMENT 'How campaign is triggered',
    `triggerEvent` VARCHAR(100) NULL COMMENT 'Event name (e.g., user_signup, purchase_complete)',

    -- 📊 Campaign Status
    `isActive` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Campaign is active',
    `goalType` VARCHAR(50) NULL COMMENT 'Goal type (open, click, conversion)',
    `goalMetric` VARCHAR(255) NULL COMMENT 'Goal tracking metric',

    -- 📅 Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Campaign created',
    `createdBy` INT UNSIGNED NULL COMMENT 'User who created campaign',
    `updatedAt` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last updated',

    PRIMARY KEY (`campaignID`),
    INDEX `idx_trigger_type` (`triggerType`),
    INDEX `idx_trigger_event` (`triggerEvent`),
    INDEX `idx_active` (`isActive`),
    INDEX `idx_created` (`createdAt` DESC),

    CONSTRAINT `fk_drip_campaign_created_by`
        FOREIGN KEY (`createdBy`)
        REFERENCES `tblUsers`(`userID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Drip campaign definitions';

-- ============================================================================
-- 📧 DRIP CAMPAIGN STEPS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailDripCampaignSteps` (
    `stepID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaignID` INT UNSIGNED NOT NULL COMMENT 'Parent campaign',
    `stepOrder` INT UNSIGNED NOT NULL COMMENT 'Step sequence order',
    `stepName` VARCHAR(255) NOT NULL COMMENT 'Step name',

    -- ⏱️ Timing Configuration
    `delayValue` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Delay amount',
    `delayUnit` ENUM('minutes', 'hours', 'days', 'weeks') NOT NULL DEFAULT 'days' COMMENT 'Delay unit',
    `delayFromPrevious` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = from previous step, 0 = from campaign start',

    -- 📧 Email Content
    `templateID` INT UNSIGNED NULL COMMENT 'Email template ID',
    `subject` VARCHAR(500) NULL COMMENT 'Email subject (if no template)',
    `bodyHTML` LONGTEXT NULL COMMENT 'HTML body (if no template)',
    `bodyText` TEXT NULL COMMENT 'Plain text body (if no template)',

    -- 🔀 Conditional Logic
    `conditionType` VARCHAR(50) NULL COMMENT 'Condition type (always, opened_previous, clicked_previous)',
    `conditionValue` TEXT NULL COMMENT 'Condition value/config',

    -- 📊 Status
    `isActive` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Step is active',

    -- 📅 Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Step created',
    `updatedAt` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last updated',

    PRIMARY KEY (`stepID`),
    UNIQUE KEY `uk_campaign_order` (`campaignID`, `stepOrder`),
    INDEX `idx_campaign` (`campaignID`),
    INDEX `idx_template` (`templateID`),
    INDEX `idx_active` (`isActive`),

    CONSTRAINT `fk_drip_step_campaign`
        FOREIGN KEY (`campaignID`)
        REFERENCES `tblEmailDripCampaigns`(`campaignID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT `fk_drip_step_template`
        FOREIGN KEY (`templateID`)
        REFERENCES `tblEmailTemplates`(`templateID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Drip campaign email steps';

-- ============================================================================
-- 👥 DRIP CAMPAIGN SUBSCRIBERS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailDripSubscribers` (
    `subscriberID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaignID` INT UNSIGNED NOT NULL COMMENT 'Campaign ID',
    `userID` INT UNSIGNED NULL COMMENT 'User ID (if registered user)',
    `email` VARCHAR(255) NOT NULL COMMENT 'Subscriber email',

    -- 📊 Subscriber Status
    `status` ENUM('active', 'paused', 'completed', 'unsubscribed') NOT NULL DEFAULT 'active' COMMENT 'Subscriber status',
    `customData` JSON NULL COMMENT 'Custom subscriber data for personalization',

    -- 📍 Progress Tracking
    `currentStepID` INT UNSIGNED NULL COMMENT 'Current step in sequence',
    `nextEmailDue` DATETIME NULL COMMENT 'When next email should be sent',

    -- 📅 Timestamps
    `subscribedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Subscription date',
    `completedAt` DATETIME NULL COMMENT 'Campaign completion date',
    `unsubscribedAt` DATETIME NULL COMMENT 'Unsubscribe date',
    `unsubscribeReason` VARCHAR(255) NULL COMMENT 'Reason for unsubscribing',

    PRIMARY KEY (`subscriberID`),
    UNIQUE KEY `uk_campaign_email` (`campaignID`, `email`),
    INDEX `idx_campaign` (`campaignID`),
    INDEX `idx_user` (`userID`),
    INDEX `idx_status` (`status`),
    INDEX `idx_next_due` (`nextEmailDue`),
    INDEX `idx_current_step` (`currentStepID`),

    CONSTRAINT `fk_drip_subscriber_campaign`
        FOREIGN KEY (`campaignID`)
        REFERENCES `tblEmailDripCampaigns`(`campaignID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT `fk_drip_subscriber_user`
        FOREIGN KEY (`userID`)
        REFERENCES `tblUsers`(`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT `fk_drip_subscriber_step`
        FOREIGN KEY (`currentStepID`)
        REFERENCES `tblEmailDripCampaignSteps`(`stepID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Drip campaign subscribers';

-- ============================================================================
-- 📊 DRIP CAMPAIGN PROGRESS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailDripProgress` (
    `progressID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subscriberID` INT UNSIGNED NOT NULL COMMENT 'Subscriber ID',
    `stepID` INT UNSIGNED NOT NULL COMMENT 'Step ID',

    -- 📊 Progress Status
    `status` ENUM('sent', 'skipped', 'failed') NOT NULL COMMENT 'Processing status',
    `processedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Processing timestamp',
    `errorMessage` TEXT NULL COMMENT 'Error message if failed',

    PRIMARY KEY (`progressID`),
    INDEX `idx_subscriber` (`subscriberID`),
    INDEX `idx_step` (`stepID`),
    INDEX `idx_processed` (`processedAt` DESC),

    CONSTRAINT `fk_drip_progress_subscriber`
        FOREIGN KEY (`subscriberID`)
        REFERENCES `tblEmailDripSubscribers`(`subscriberID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT `fk_drip_progress_step`
        FOREIGN KEY (`stepID`)
        REFERENCES `tblEmailDripCampaignSteps`(`stepID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Drip campaign progress tracking';

-- ============================================================================
-- 📝 UPDATE SETTINGS
-- ============================================================================

-- Mark migration as complete
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
VALUES (
    'email.drip_campaigns.migration_version',
    '2.2.0',
    'Email drip campaigns migration version',
    'email'
) ON DUPLICATE KEY UPDATE settingValue = '2.2.0';

-- Enable drip campaigns feature
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
VALUES (
    'email.drip_campaigns.enabled',
    '1',
    'Enable email drip campaign features',
    'email'
) ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- Processing batch size
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
VALUES (
    'email.drip_campaigns.batch_size',
    '100',
    'Maximum drip emails to process per batch',
    'email'
) ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================

SELECT
    '✅ Email Drip Campaigns Migration Complete' AS Status,
    '2.2.0' AS Version,
    NOW() AS AppliedAt;
