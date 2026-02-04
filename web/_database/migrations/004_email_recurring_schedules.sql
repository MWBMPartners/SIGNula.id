-- ============================================================================
-- 🔁 SIGNula - Email Recurring Schedules Schema Migration
-- ============================================================================
--
-- Purpose: Add recurring schedule table for automated email delivery
-- Version: 2.3.0
-- PHP Version: 8.3+
--
-- Features:
-- - Daily, weekly, monthly recurrence
-- - Timezone-aware scheduling
-- - Start/end date support
-- - Flexible frequency configuration
--
-- ============================================================================

-- ============================================================================
-- 🔁 RECURRING SCHEDULES TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailRecurringSchedules` (
    `scheduleID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scheduleName` VARCHAR(255) NOT NULL COMMENT 'Schedule name',
    `emailData` JSON NOT NULL COMMENT 'Email configuration',

    -- 🔁 Recurrence Configuration
    `frequency` ENUM('daily', 'weekly', 'monthly', 'yearly') NOT NULL DEFAULT 'daily' COMMENT 'Recurrence frequency',
    `frequencyValue` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Every N days/weeks/months',
    `dayOfWeek` TINYINT UNSIGNED NULL COMMENT 'Day of week (0=Sunday, 6=Saturday) for weekly',
    `dayOfMonth` TINYINT UNSIGNED NULL COMMENT 'Day of month (1-31) for monthly',
    `timeOfDay` TIME NOT NULL DEFAULT '10:00:00' COMMENT 'Time to send',
    `timezone` VARCHAR(100) NOT NULL DEFAULT 'UTC' COMMENT 'Timezone for scheduling',

    -- 📊 Schedule Status
    `isActive` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Schedule is active',
    `startDate` DATE NULL COMMENT 'Start date (null = immediate)',
    `endDate` DATE NULL COMMENT 'End date (null = no end)',

    -- 📅 Execution Tracking
    `lastRun` DATETIME NULL COMMENT 'Last execution time',
    `nextRun` DATETIME NULL COMMENT 'Next scheduled run',

    -- 📝 Metadata
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Schedule created',
    `createdBy` INT UNSIGNED NULL COMMENT 'User who created schedule',
    `updatedAt` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last updated',

    PRIMARY KEY (`scheduleID`),
    INDEX `idx_active` (`isActive`),
    INDEX `idx_next_run` (`nextRun`),
    INDEX `idx_frequency` (`frequency`),
    INDEX `idx_created_by` (`createdBy`),

    CONSTRAINT `fk_recurring_schedule_created_by`
        FOREIGN KEY (`createdBy`)
        REFERENCES `tblUsers`(`userID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Recurring email schedules';

-- ============================================================================
-- 📝 UPDATE SETTINGS
-- ============================================================================

-- Mark migration as complete
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
VALUES (
    'email.recurring_schedules.migration_version',
    '2.3.0',
    'Email recurring schedules migration version',
    'email'
) ON DUPLICATE KEY UPDATE settingValue = '2.3.0';

-- Enable recurring schedules feature
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
VALUES (
    'email.recurring_schedules.enabled',
    '1',
    'Enable email recurring schedule features',
    'email'
) ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================

SELECT
    '✅ Email Recurring Schedules Migration Complete' AS Status,
    '2.3.0' AS Version,
    NOW() AS AppliedAt;
