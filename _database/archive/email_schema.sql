-- ============================================================================
-- 📧 SIGNula - Email System Database Schema
-- ============================================================================
--
-- Purpose: Complete email system database schema
-- Version: 2.0.0
-- Date: 2026-02-02
--
-- Features:
-- - Email queue with priority and scheduling
-- - Email templates with versioning
-- - Email tracking (opens, clicks)
-- - Provider configuration and health monitoring
-- - Bounce and complaint handling
-- - Unsubscribe management
--
-- ============================================================================

-- ============================================================================
-- 📬 Email Queue Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailQueue` (
    `emailID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` INT UNSIGNED NULL,
    `templateID` INT UNSIGNED NULL,

    -- 📧 Recipient Information
    `recipientEmail` VARCHAR(255) NOT NULL,
    `recipientName` VARCHAR(255) NULL,

    -- 📝 Email Content
    `subject` VARCHAR(500) NOT NULL,
    `bodyHTML` LONGTEXT NULL,
    `bodyText` LONGTEXT NOT NULL,

    -- 👤 Sender Information
    `fromEmail` VARCHAR(255) NOT NULL,
    `fromName` VARCHAR(255) NULL,
    `replyToEmail` VARCHAR(255) NULL,

    -- 📋 Additional Recipients
    `ccRecipients` JSON NULL COMMENT 'Array of CC email addresses',
    `bccRecipients` JSON NULL COMMENT 'Array of BCC email addresses',

    -- 📎 Attachments
    `attachments` JSON NULL COMMENT 'Array of attachment data',

    -- ⚙️ Queue Management
    `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1=highest, 10=lowest',
    `status` ENUM('pending', 'sending', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    `attemptCount` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `maxAttempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,

    -- 🔌 Provider Information
    `provider` VARCHAR(50) NULL COMMENT 'Provider used to send (microsoft_graph, gmail_api, smtp)',
    `providerMessageID` VARCHAR(255) NULL COMMENT 'Message ID from provider',

    -- ⏰ Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `scheduledAt` DATETIME NULL COMMENT 'When to send the email',
    `lastAttemptAt` DATETIME NULL,
    `sentAt` DATETIME NULL,

    -- 📊 Tracking
    `trackingEnabled` BOOLEAN NOT NULL DEFAULT FALSE,
    `trackingToken` VARCHAR(64) NULL COMMENT 'Unique token for tracking opens/clicks',
    `openedAt` DATETIME NULL,
    `openCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `clickCount` INT UNSIGNED NOT NULL DEFAULT 0,

    -- ❌ Error Handling
    `lastError` TEXT NULL,

    -- 🗂️ Metadata
    `metadata` JSON NULL COMMENT 'Additional metadata',

    PRIMARY KEY (`emailID`),
    INDEX `idx_status` (`status`),
    INDEX `idx_scheduled` (`scheduledAt`),
    INDEX `idx_priority_created` (`priority`, `createdAt`),
    INDEX `idx_recipient` (`recipientEmail`),
    INDEX `idx_user` (`userID`),
    INDEX `idx_template` (`templateID`),
    INDEX `idx_provider` (`provider`),
    INDEX `idx_tracking_token` (`trackingToken`),
    INDEX `idx_status_attempts` (`status`, `attemptCount`),

    CONSTRAINT `fk_email_queue_user`
        FOREIGN KEY (`userID`) REFERENCES `tblUsers` (`userID`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `fk_email_queue_template`
        FOREIGN KEY (`templateID`) REFERENCES `tblEmailTemplates` (`templateID`)
        ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Email queue for async email delivery';

-- ============================================================================
-- 📋 Email Templates Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailTemplates` (
    `templateID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `templateKey` VARCHAR(100) NOT NULL COMMENT 'Unique identifier (e.g., welcome_email)',
    `templateName` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,

    -- 📝 Content
    `subject` VARCHAR(500) NOT NULL,
    `bodyHTML` LONGTEXT NULL,
    `bodyText` LONGTEXT NOT NULL,

    -- 👤 Default Sender
    `fromEmail` VARCHAR(255) NULL,
    `fromName` VARCHAR(255) NULL,
    `replyTo` VARCHAR(255) NULL,

    -- 📎 Default Attachments
    `defaultAttachments` JSON NULL COMMENT 'Array of default attachment data',

    -- 🎨 Template Settings
    `category` VARCHAR(50) NULL COMMENT 'Template category (transactional, marketing, system)',
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE,
    `trackingEnabled` BOOLEAN NOT NULL DEFAULT FALSE,

    -- 📋 Variables
    `requiredVariables` JSON NULL COMMENT 'Array of required variable names',
    `optionalVariables` JSON NULL COMMENT 'Array of optional variable names',
    `variableDescriptions` JSON NULL COMMENT 'Object mapping variable names to descriptions',

    -- 📊 Version Control
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `previousVersionID` INT UNSIGNED NULL,

    -- 🗂️ Metadata
    `metadata` JSON NULL,

    -- ⏰ Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `createdBy` INT UNSIGNED NULL,
    `updatedBy` INT UNSIGNED NULL,

    PRIMARY KEY (`templateID`),
    UNIQUE KEY `uk_template_key` (`templateKey`),
    INDEX `idx_active` (`isActive`),
    INDEX `idx_category` (`category`),
    INDEX `idx_version` (`version`),

    CONSTRAINT `fk_email_template_created_by`
        FOREIGN KEY (`createdBy`) REFERENCES `tblUsers` (`userID`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `fk_email_template_updated_by`
        FOREIGN KEY (`updatedBy`) REFERENCES `tblUsers` (`userID`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `fk_email_template_previous_version`
        FOREIGN KEY (`previousVersionID`) REFERENCES `tblEmailTemplates` (`templateID`)
        ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Email templates with variable support';

-- ============================================================================
-- 📊 Email Tracking Events Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailTrackingEvents` (
    `eventID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `emailID` INT UNSIGNED NOT NULL,
    `eventType` ENUM('open', 'click', 'bounce', 'complaint', 'unsubscribe') NOT NULL,

    -- 🔗 Click Tracking
    `clickedUrl` VARCHAR(1000) NULL COMMENT 'URL that was clicked',

    -- 🌐 Request Information
    `ipAddress` VARCHAR(45) NULL,
    `userAgent` VARCHAR(500) NULL,
    `referer` VARCHAR(1000) NULL,

    -- 📧 Bounce Information
    `bounceType` ENUM('hard', 'soft', 'transient') NULL,
    `bounceReason` TEXT NULL,

    -- 🗂️ Metadata
    `metadata` JSON NULL,

    -- ⏰ Timestamp
    `eventAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`eventID`),
    INDEX `idx_email` (`emailID`),
    INDEX `idx_event_type` (`eventType`),
    INDEX `idx_event_at` (`eventAt`),

    CONSTRAINT `fk_tracking_email`
        FOREIGN KEY (`emailID`) REFERENCES `tblEmailQueue` (`emailID`)
        ON DELETE CASCADE ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Email tracking events (opens, clicks, bounces)';

-- ============================================================================
-- 🚫 Unsubscribe List Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailUnsubscribes` (
    `unsubscribeID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL,
    `userID` INT UNSIGNED NULL,

    -- 📋 Unsubscribe Details
    `reason` ENUM('user_request', 'bounce', 'complaint', 'manual', 'system') NOT NULL DEFAULT 'user_request',
    `reasonDetails` TEXT NULL,
    `category` VARCHAR(50) NULL COMMENT 'Category to unsubscribe from (null = all)',

    -- 🗂️ Metadata
    `metadata` JSON NULL,

    -- ⏰ Timestamps
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

-- ============================================================================
-- 🔌 Email Provider Health Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailProviderHealth` (
    `healthID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider` VARCHAR(50) NOT NULL COMMENT 'Provider name (microsoft_graph, gmail_api, smtp)',

    -- 📊 Health Metrics
    `isHealthy` BOOLEAN NOT NULL DEFAULT TRUE,
    `successCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `failureCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `totalAttempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `successRate` DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT 'Percentage',
    `averageResponseTime` INT UNSIGNED NULL COMMENT 'Milliseconds',

    -- ❌ Last Error
    `lastError` TEXT NULL,
    `lastErrorAt` DATETIME NULL,

    -- ✅ Last Success
    `lastSuccessAt` DATETIME NULL,

    -- ⏰ Check Information
    `lastCheckedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `checkIntervalMinutes` INT UNSIGNED NOT NULL DEFAULT 5,

    -- 🗂️ Metadata
    `metadata` JSON NULL,

    PRIMARY KEY (`healthID`),
    UNIQUE KEY `uk_provider` (`provider`),
    INDEX `idx_is_healthy` (`isHealthy`),
    INDEX `idx_last_checked` (`lastCheckedAt`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Email provider health monitoring';

-- ============================================================================
-- 📧 Email Campaigns Table (Optional - for bulk email management)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailCampaigns` (
    `campaignID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaignName` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,

    -- 📋 Campaign Details
    `templateID` INT UNSIGNED NULL,
    `category` VARCHAR(50) NULL,
    `status` ENUM('draft', 'scheduled', 'sending', 'sent', 'cancelled') NOT NULL DEFAULT 'draft',

    -- 📊 Statistics
    `totalRecipients` INT UNSIGNED NOT NULL DEFAULT 0,
    `sentCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `failedCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `openCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `clickCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `bounceCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `unsubscribeCount` INT UNSIGNED NOT NULL DEFAULT 0,

    -- ⏰ Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `scheduledAt` DATETIME NULL,
    `startedAt` DATETIME NULL,
    `completedAt` DATETIME NULL,
    `createdBy` INT UNSIGNED NULL,

    -- 🗂️ Metadata
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
-- 🔗 Initial Data - Email Templates
-- ============================================================================

-- Insert default email templates if they don't exist
INSERT IGNORE INTO `tblEmailTemplates`
(`templateKey`, `templateName`, `description`, `subject`, `bodyHTML`, `bodyText`, `category`, `trackingEnabled`, `requiredVariables`)
VALUES
(
    'email_verification',
    'Email Verification',
    'Email address verification for new accounts',
    'Verify Your Email Address - SIGNula',
    '<h1>Welcome to SIGNula!</h1><p>Hi {{displayName}},</p><p>Please verify your email address by clicking the link below:</p><p><a href="{{verificationUrl}}" style="background-color:#007bff;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Verify Email Address</a></p><p>Or enter this code: <strong>{{verificationCode}}</strong></p><p>This link will expire in {{expiryMinutes}}.</p><p>If you did not create a SIGNula account, please ignore this email.</p>',
    'Welcome to SIGNula! Please verify your email address by visiting: {{verificationUrl}} or entering this code: {{verificationCode}}. This link will expire in {{expiryMinutes}}.',
    'transactional',
    FALSE,
    '["displayName", "email", "verificationUrl", "verificationCode", "expiryMinutes"]'
),
(
    'password_reset',
    'Password Reset',
    'Password reset request',
    'Reset Your Password - SIGNula',
    '<h1>Password Reset Request</h1><p>Hi {{displayName}},</p><p>We received a request to reset your password. Click the link below to reset it:</p><p><a href="{{resetUrl}}" style="background-color:#007bff;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Reset Password</a></p><p>Or enter this code: <strong>{{resetCode}}</strong></p><p>This link will expire in {{expiryMinutes}} minutes.</p><p>If you did not request a password reset, please ignore this email or contact support if you have concerns.</p>',
    'Hi {{displayName}}, You requested a password reset. Visit: {{resetUrl}} or enter this code: {{resetCode}}. This link expires in {{expiryMinutes}} minutes.',
    'transactional',
    FALSE,
    '["displayName", "email", "resetUrl", "resetCode", "expiryMinutes"]'
),
(
    'passwordless_login',
    'Passwordless Login',
    'Magic link for passwordless login',
    'Your Login Link - SIGNula',
    '<h1>Login to SIGNula</h1><p>Hi {{displayName}},</p><p>Click the link below to securely log in to your account:</p><p><a href="{{loginUrl}}" style="background-color:#28a745;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Login to SIGNula</a></p><p>Or enter this code: <strong>{{loginCode}}</strong></p><p>This link will expire in {{expiryMinutes}} minutes.</p><p>For your security, do not share this link with anyone.</p>',
    'Hi {{displayName}}, Click here to login: {{loginUrl}} or enter this code: {{loginCode}}. Expires in {{expiryMinutes}} minutes.',
    'transactional',
    FALSE,
    '["displayName", "email", "loginUrl", "loginCode", "expiryMinutes"]'
),
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

-- ============================================================================
-- 🔌 Initial Data - Provider Health Records
-- ============================================================================

-- Insert provider health records if they don't exist
INSERT IGNORE INTO `tblEmailProviderHealth` (`provider`, `checkIntervalMinutes`)
VALUES
('microsoft_graph', 5),
('gmail_api', 5),
('smtp', 5);

-- ============================================================================
-- ✅ Schema Complete
-- ============================================================================
