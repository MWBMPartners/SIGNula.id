-- ============================================================================
-- SIGNula Database Schema
-- 
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
-- 
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 📊 SIGNula - Email A/B Testing Schema Migration
-- ============================================================================
--
-- Purpose: Add A/B testing tables for email campaigns
-- Version: 2.1.0
-- PHP Version: 8.3+
--
-- Features:
-- - Multi-variant testing (A/B/C/D/etc.)
-- - Statistical significance tracking
-- - Automatic winner selection
-- - Performance analytics
--
-- ============================================================================

-- 🔍 Check if migration already applied
-- ============================================================================

SET @migration_exists = (
    SELECT COUNT(*)
    FROM tblSettings
    WHERE settingKey = 'email.ab_testing.migration_version'
);

-- ============================================================================
-- 📊 A/B TESTS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailABTests` (
    `testID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `testName` VARCHAR(255) NOT NULL COMMENT 'Descriptive test name',
    `testType` ENUM('subject', 'content', 'from_name', 'send_time', 'multi') NOT NULL DEFAULT 'subject' COMMENT 'Type of A/B test',
    `description` TEXT NULL COMMENT 'Test description',

    -- 🎯 Test Configuration
    `sampleSizePercentage` DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT 'Percentage of recipients to include',
    `winnerSelectionMetric` ENUM('open_rate', 'click_rate', 'conversion_rate') NOT NULL DEFAULT 'open_rate' COMMENT 'Metric to determine winner',
    `autoSelectWinner` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Auto-select winner when statistically significant',

    -- 📊 Test Status
    `status` ENUM('draft', 'running', 'completed', 'cancelled') NOT NULL DEFAULT 'draft' COMMENT 'Current test status',
    `winnerVariantID` BIGINT UNSIGNED NULL COMMENT 'ID of winning variant (when completed)',

    -- 📅 Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Test created timestamp',
    `createdBy` BIGINT UNSIGNED NULL COMMENT 'User ID who created test',
    `startedAt` DATETIME NULL COMMENT 'When test was started',
    `completedAt` DATETIME NULL COMMENT 'When test was completed',

    PRIMARY KEY (`testID`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created` (`createdAt` DESC),
    INDEX `idx_created_by` (`createdBy`),

    CONSTRAINT `fk_ab_test_created_by`
        FOREIGN KEY (`createdBy`)
        REFERENCES `tblUsers`(`userID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Email A/B test definitions';

-- ============================================================================
-- 🎨 A/B TEST VARIANTS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailABTestVariants` (
    `variantID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `testID` BIGINT UNSIGNED NOT NULL COMMENT 'Parent test ID',
    `variantName` VARCHAR(50) NOT NULL COMMENT 'Variant identifier (A, B, C, etc.)',
    `variantLabel` VARCHAR(255) NOT NULL COMMENT 'Human-readable variant label',

    -- 📧 Email Content
    `subject` VARCHAR(500) NULL COMMENT 'Email subject line',
    `bodyHTML` LONGTEXT NULL COMMENT 'HTML email body',
    `bodyText` TEXT NULL COMMENT 'Plain text email body',
    `fromName` VARCHAR(255) NULL COMMENT 'Sender name',
    `metadata` JSON NULL COMMENT 'Additional variant metadata',

    -- 📊 Traffic & Performance
    `trafficPercentage` DECIMAL(5,2) NOT NULL DEFAULT 50.00 COMMENT 'Percentage of traffic assigned to variant',
    `sentCount` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of emails sent',

    -- 📅 Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Variant created timestamp',

    PRIMARY KEY (`variantID`),
    UNIQUE KEY `uk_test_variant` (`testID`, `variantName`),
    INDEX `idx_test_id` (`testID`),

    CONSTRAINT `fk_ab_variant_test`
        FOREIGN KEY (`testID`)
        REFERENCES `tblEmailABTests`(`testID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='A/B test variant definitions';

-- ============================================================================
-- 🔧 UPDATE EXISTING TABLES
-- ============================================================================

-- Add A/B test reference to email queue
SET @ab_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblEmailQueue'
    AND COLUMN_NAME = 'abTestID'
);

SET @sql = IF(
    @ab_column_exists = 0,
    'ALTER TABLE tblEmailQueue
     ADD COLUMN abTestID INT UNSIGNED NULL COMMENT "A/B test ID if part of test" AFTER metadata,
     ADD COLUMN abVariantID INT UNSIGNED NULL COMMENT "A/B test variant ID" AFTER abTestID,
     ADD INDEX idx_ab_test (abTestID),
     ADD INDEX idx_ab_variant (abVariantID)',
    'SELECT "Column abTestID already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 📊 SAMPLE DATA (Optional - commented out for production)
-- ============================================================================

/*
-- Sample A/B test for subject line testing
INSERT INTO tblEmailABTests (
    testName, testType, description,
    sampleSizePercentage, winnerSelectionMetric,
    autoSelectWinner, status
) VALUES (
    'Welcome Email Subject Test',
    'subject',
    'Testing different subject lines for welcome email',
    100.00,
    'open_rate',
    1,
    'draft'
);

SET @test_id = LAST_INSERT_ID();

-- Variant A: Short subject
INSERT INTO tblEmailABTestVariants (
    testID, variantName, variantLabel,
    subject, trafficPercentage
) VALUES (
    @test_id,
    'variant_A',
    'Short Subject',
    'Welcome to SIGNula!',
    50.00
);

-- Variant B: Longer, more descriptive subject
INSERT INTO tblEmailABTestVariants (
    testID, variantName, variantLabel,
    subject, trafficPercentage
) VALUES (
    @test_id,
    'variant_B',
    'Descriptive Subject',
    'Welcome to SIGNula - Your Universal Account System',
    50.00
);
*/

-- ============================================================================
-- 📝 UPDATE SETTINGS
-- ============================================================================

-- Mark migration as complete
INSERT INTO tblSettings (settingKey, settingValue, description, settingCategory)
VALUES (
    'email.ab_testing.migration_version',
    '2.1.0',
    'Email A/B testing migration version',
    'email'
) ON DUPLICATE KEY UPDATE settingValue = '2.1.0';

-- Enable A/B testing feature
INSERT INTO tblSettings (settingKey, settingValue, description, settingCategory)
VALUES (
    'email.ab_testing.enabled',
    '1',
    'Enable email A/B testing features',
    'email'
) ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- Minimum sample size for statistical significance
INSERT INTO tblSettings (settingKey, settingValue, description, settingCategory)
VALUES (
    'email.ab_testing.min_sample_size',
    '100',
    'Minimum sample size per variant for valid results',
    'email'
) ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- Confidence level threshold
INSERT INTO tblSettings (settingKey, settingValue, description, settingCategory)
VALUES (
    'email.ab_testing.confidence_level',
    '95',
    'Confidence level percentage for statistical significance',
    'email'
) ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================

SELECT
    '✅ Email A/B Testing Migration Complete' AS Status,
    '2.1.0' AS Version,
    NOW() AS AppliedAt;
