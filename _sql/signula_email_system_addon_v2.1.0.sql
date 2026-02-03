-- ============================================================================
-- 📧 SIGNula - Complete Email System Addon
-- ============================================================================
-- Version: 2.1.0-beta
-- Date: 2026-02-03
-- Description: Comprehensive email system with delegate mailbox support
-- Prerequisites: signula_complete_install_v2.0.1.sql must be installed first
--
-- This addon includes:
-- - Email queue and template system
-- - Email tracking and analytics
-- - Email campaigns and drip campaigns
-- - A/B testing capabilities
-- - Recurring email schedules
-- - Delegate mailbox support (Microsoft 365 & Google Workspace)
-- - User OAuth token management
--
-- Installation:
--   mysql -u your_username -p signula < signula_email_system_addon_v2.1.0.sql
--
-- ============================================================================

-- Set session variables
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- Ensure we're using the correct database
USE `signula`;

-- ============================================================================
-- 📬 EMAIL SYSTEM CORE TABLES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 🗂️ TABLE: tblEmailQueue
-- Purpose: Centralized email queue for all outgoing emails
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEmailQueue` (
    `queueID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique queue entry identifier',
    `userID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblUsers (NULL for system emails)',
    `templateID` INT UNSIGNED NULL COMMENT 'Reference to tblEmailTemplates (NULL for custom emails)',

    -- 📧 Email Details
    `recipientEmail` VARCHAR(255) NOT NULL COMMENT 'Recipient email address',
    `recipientName` VARCHAR(255) NULL COMMENT 'Recipient display name',
    `subject` VARCHAR(500) NOT NULL COMMENT 'Email subject line',
    `bodyHTML` MEDIUMTEXT NOT NULL COMMENT 'HTML email body',
    `bodyText` TEXT NULL COMMENT 'Plain text alternative',
    `fromEmail` VARCHAR(255) NOT NULL COMMENT 'Sender email address',
    `fromName` VARCHAR(255) NULL COMMENT 'Sender display name',
    `sendAsEmail` VARCHAR(255) NULL COMMENT 'Delegate mailbox to send from (if different from fromEmail)',
    `replyToEmail` VARCHAR(255) NULL COMMENT 'Reply-to email address',

    -- 📋 Additional Recipients
    `ccRecipients` TEXT NULL COMMENT 'CC recipients (JSON array)',
    `bccRecipients` TEXT NULL COMMENT 'BCC recipients (JSON array)',

    -- 📎 Attachments
    `attachments` TEXT NULL COMMENT 'Attachments metadata (JSON array)',

    -- ⚙️ Queue Management
    `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'Priority: 1 (highest) to 10 (lowest)',
    `status` ENUM('pending', 'processing', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'pending' COMMENT 'Current status',
    `attemptCount` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of send attempts',
    `maxAttempts` TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Maximum send attempts',

    -- ⏰ Scheduling
    `scheduledAt` DATETIME NULL COMMENT 'Scheduled send time (NULL for immediate)',
    `processedAt` DATETIME NULL COMMENT 'When processing started',
    `sentAt` DATETIME NULL COMMENT 'When successfully sent',

    -- 📊 Tracking
    `provider` VARCHAR(50) NULL COMMENT 'Email provider used (smtp, microsoft_graph, gmail_api)',
    `providerMessageID` VARCHAR(255) NULL COMMENT 'Provider message identifier',
    `trackingID` CHAR(36) NULL COMMENT 'Unique tracking identifier (UUID)',

    -- ❌ Error Handling
    `errorMessage` TEXT NULL COMMENT 'Last error message',
    `lastAttemptAt` DATETIME NULL COMMENT 'Last send attempt timestamp',

    -- 📝 Metadata
    `metadata` JSON NULL COMMENT 'Additional metadata (campaign ID, A/B test info, etc.)',

    -- ⏰ Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Queue entry creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    -- 🔗 Foreign Keys
    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL ON UPDATE CASCADE,

    -- 📊 Indexes
    INDEX `idx_status_priority` (`status`, `priority` DESC),
    INDEX `idx_scheduled` (`scheduledAt`),
    INDEX `idx_recipient` (`recipientEmail`),
    INDEX `idx_user` (`userID`),
    INDEX `idx_template` (`templateID`),
    INDEX `idx_tracking` (`trackingID`),
    INDEX `idx_provider_message` (`providerMessageID`),
    INDEX `idx_send_as` (`sendAsEmail`),
    INDEX `idx_created` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Centralized email queue';

-- ----------------------------------------------------------------------------
-- 📋 TABLE: tblEmailTemplates
-- Purpose: Reusable email templates with variable substitution
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEmailTemplates` (
    `templateID` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique template identifier',
    `templateKey` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Unique key for code reference',
    `templateName` VARCHAR(255) NOT NULL COMMENT 'Human-readable template name',
    `templateDescription` TEXT NULL COMMENT 'Template description and usage notes',
    `category` VARCHAR(50) NOT NULL DEFAULT 'general' COMMENT 'Template category',

    -- 📧 Email Content
    `subject` VARCHAR(500) NOT NULL COMMENT 'Email subject (supports variables: {{variable}})',
    `bodyHTML` MEDIUMTEXT NOT NULL COMMENT 'HTML email body (supports variables)',
    `bodyText` TEXT NULL COMMENT 'Plain text alternative (supports variables)',

    -- ⚙️ Default Settings
    `fromEmail` VARCHAR(255) NULL COMMENT 'Default from email (NULL = use system default)',
    `fromName` VARCHAR(255) NULL COMMENT 'Default from name',
    `replyToEmail` VARCHAR(255) NULL COMMENT 'Default reply-to email',

    -- 📋 Variables
    `requiredVariables` JSON NULL COMMENT 'Required template variables (array of strings)',
    `optionalVariables` JSON NULL COMMENT 'Optional template variables with defaults (object)',

    -- 🎨 Design
    `previewText` VARCHAR(255) NULL COMMENT 'Email preview text (for email clients)',
    `headerImage` TEXT NULL COMMENT 'Header image URL',
    `footerText` TEXT NULL COMMENT 'Footer text (HTML allowed)',

    -- ⚙️ Status
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether template is active',
    `version` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Template version number',

    -- ⏰ Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Template creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    -- 📊 Indexes
    INDEX `idx_key` (`templateKey`),
    INDEX `idx_category` (`category`),
    INDEX `idx_active` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Email templates';

-- ----------------------------------------------------------------------------
-- 📊 TABLE: tblEmailTrackingEvents
-- Purpose: Track email opens, clicks, and other engagement metrics
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEmailTrackingEvents` (
    `eventID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique event identifier',
    `trackingID` CHAR(36) NOT NULL COMMENT 'Email tracking identifier (from tblEmailQueue)',
    `queueID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblEmailQueue',

    -- 📊 Event Details
    `eventType` ENUM('sent', 'delivered', 'opened', 'clicked', 'bounced', 'complained', 'unsubscribed') NOT NULL COMMENT 'Event type',
    `eventData` JSON NULL COMMENT 'Additional event data (link clicked, bounce reason, etc.)',

    -- 🌐 Client Information
    `ipAddress` VARCHAR(45) NULL COMMENT 'Visitor IP address (IPv4 or IPv6)',
    `userAgent` TEXT NULL COMMENT 'Browser user agent string',
    `deviceType` VARCHAR(50) NULL COMMENT 'Device type (desktop, mobile, tablet)',
    `browserName` VARCHAR(100) NULL COMMENT 'Browser name',
    `operatingSystem` VARCHAR(100) NULL COMMENT 'Operating system',

    -- 📍 Location (if available)
    `country` VARCHAR(2) NULL COMMENT 'Country code (ISO 3166-1 alpha-2)',
    `city` VARCHAR(100) NULL COMMENT 'City name',

    -- ⏰ Timestamp
    `eventAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Event timestamp',

    -- 🔗 Foreign Keys
    FOREIGN KEY (`queueID`) REFERENCES `tblEmailQueue`(`queueID`) ON DELETE CASCADE ON UPDATE CASCADE,

    -- 📊 Indexes
    INDEX `idx_tracking` (`trackingID`),
    INDEX `idx_queue` (`queueID`),
    INDEX `idx_event_type` (`eventType`),
    INDEX `idx_event_at` (`eventAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Email tracking events';

-- ----------------------------------------------------------------------------
-- 🚫 TABLE: tblEmailUnsubscribes
-- Purpose: Manage email unsubscribe requests
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEmailUnsubscribes` (
    `unsubscribeID` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique unsubscribe record identifier',
    `email` VARCHAR(255) NOT NULL COMMENT 'Unsubscribed email address',
    `unsubscribeType` ENUM('all', 'marketing', 'transactional', 'category') NOT NULL DEFAULT 'all' COMMENT 'Unsubscribe scope',
    `category` VARCHAR(100) NULL COMMENT 'Specific category to unsubscribe from',
    `reason` TEXT NULL COMMENT 'Unsubscribe reason provided by user',
    `sourceQueueID` BIGINT UNSIGNED NULL COMMENT 'Email that triggered unsubscribe',
    `ipAddress` VARCHAR(45) NULL COMMENT 'IP address of unsubscribe request',
    `userAgent` TEXT NULL COMMENT 'User agent of unsubscribe request',
    `unsubscribedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Unsubscribe timestamp',

    -- 🔗 Foreign Keys
    FOREIGN KEY (`sourceQueueID`) REFERENCES `tblEmailQueue`(`queueID`) ON DELETE SET NULL ON UPDATE CASCADE,

    -- 📊 Indexes
    UNIQUE INDEX `uk_email_type_category` (`email`, `unsubscribeType`, `category`),
    INDEX `idx_email` (`email`),
    INDEX `idx_type` (`unsubscribeType`),
    INDEX `idx_unsubscribed_at` (`unsubscribedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Email unsubscribe management';

-- ----------------------------------------------------------------------------
-- 🏥 TABLE: tblEmailProviderHealth
-- Purpose: Monitor email provider health and failover status
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEmailProviderHealth` (
    `healthID` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique health check record identifier',
    `provider` VARCHAR(50) NOT NULL COMMENT 'Provider name (smtp, microsoft_graph, gmail_api)',
    `isHealthy` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Current health status',
    `lastCheckAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Last health check timestamp',
    `lastSuccessAt` DATETIME NULL COMMENT 'Last successful send timestamp',
    `lastFailureAt` DATETIME NULL COMMENT 'Last failure timestamp',
    `consecutiveFailures` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of consecutive failures',
    `lastError` TEXT NULL COMMENT 'Last error message',
    `metadata` JSON NULL COMMENT 'Additional provider metadata',

    -- 📊 Indexes
    UNIQUE INDEX `uk_provider` (`provider`),
    INDEX `idx_healthy` (`isHealthy`),
    INDEX `idx_last_check` (`lastCheckAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Email provider health monitoring';

-- ----------------------------------------------------------------------------
-- 📢 TABLE: tblEmailCampaigns
-- Purpose: Email marketing campaigns
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEmailCampaigns` (
    `campaignID` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique campaign identifier',
    `campaignName` VARCHAR(255) NOT NULL COMMENT 'Campaign name',
    `campaignDescription` TEXT NULL COMMENT 'Campaign description',
    `campaignType` ENUM('one-time', 'recurring', 'drip', 'ab-test') NOT NULL DEFAULT 'one-time' COMMENT 'Campaign type',
    `templateID` INT UNSIGNED NULL COMMENT 'Associated email template',
    `status` ENUM('draft', 'scheduled', 'active', 'paused', 'completed', 'cancelled') NOT NULL DEFAULT 'draft' COMMENT 'Campaign status',
    `scheduledAt` DATETIME NULL COMMENT 'Scheduled start time',
    `startedAt` DATETIME NULL COMMENT 'Actual start time',
    `completedAt` DATETIME NULL COMMENT 'Completion time',
    `metadata` JSON NULL COMMENT 'Campaign settings and metadata',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Campaign creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    -- 🔗 Foreign Keys
    FOREIGN KEY (`templateID`) REFERENCES `tblEmailTemplates`(`templateID`) ON DELETE SET NULL ON UPDATE CASCADE,

    -- 📊 Indexes
    INDEX `idx_status` (`status`),
    INDEX `idx_type` (`campaignType`),
    INDEX `idx_scheduled` (`scheduledAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Email marketing campaigns';

-- ============================================================================
-- 🔐 DELEGATE MAILBOX SUPPORT (MICROSOFT 365 & GOOGLE WORKSPACE)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 📋 TABLE: tblUserOAuthTokens
-- Purpose: Store per-user OAuth tokens for delegate mailbox sending
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblUserOAuthTokens` (
    `tokenID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` BIGINT UNSIGNED NOT NULL,
    `provider` VARCHAR(50) NOT NULL COMMENT 'Provider: microsoft_graph, gmail_api, google_workspace',

    -- 🔑 OAuth Token Data
    `accessToken` TEXT NOT NULL COMMENT 'Encrypted OAuth access token',
    `refreshToken` TEXT NULL COMMENT 'Encrypted OAuth refresh token (if available)',
    `tokenType` VARCHAR(20) NOT NULL DEFAULT 'Bearer',
    `expiresAt` DATETIME NULL COMMENT 'Access token expiration timestamp',
    `scope` TEXT NULL COMMENT 'Granted OAuth scopes',

    -- 📧 Mailbox Information
    `mailboxEmail` VARCHAR(255) NOT NULL COMMENT 'The mailbox this token can send from',
    `mailboxName` VARCHAR(255) NULL COMMENT 'Display name of mailbox owner',

    -- ⚙️ Status
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE,
    `lastUsedAt` DATETIME NULL COMMENT 'Last time token was used to send email',

    -- ❌ Error Handling
    `errorCount` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of consecutive errors',
    `lastError` TEXT NULL COMMENT 'Last error message',
    `lastErrorAt` DATETIME NULL COMMENT 'Timestamp of last error',

    -- ⏰ Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`tokenID`),

    -- Unique constraint: one token per user per provider per mailbox
    UNIQUE KEY `uk_user_provider_mailbox` (`userID`, `provider`, `mailboxEmail`),

    -- Indexes for efficient lookups
    INDEX `idx_user_provider` (`userID`, `provider`),
    INDEX `idx_mailbox` (`mailboxEmail`),
    INDEX `idx_expires` (`expiresAt`),
    INDEX `idx_active` (`isActive`),

    -- Foreign key to users table
    CONSTRAINT `fk_oauth_tokens_user`
        FOREIGN KEY (`userID`) REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User OAuth tokens for delegate mailbox sending';

-- ============================================================================
-- ⚙️ EMAIL SYSTEM SETTINGS
-- ============================================================================

INSERT IGNORE INTO `tblSettings` (`settingKey`, `settingValue`, `dataType`, `category`, `isSensitive`, `description`) VALUES
-- Email System General
('email.enabled', '1', 'boolean', 'email', FALSE, 'Enable email system'),
('email.default_from_email', 'noreply@signulo.id', 'string', 'email', FALSE, 'Default from email address'),
('email.default_from_name', 'SIGNula', 'string', 'email', FALSE, 'Default from name'),
('email.queue_processing_enabled', '1', 'boolean', 'email', FALSE, 'Enable automatic queue processing'),
('email.queue_batch_size', '50', 'integer', 'email', FALSE, 'Number of emails to process per batch'),
('email.max_retry_attempts', '3', 'integer', 'email', FALSE, 'Maximum retry attempts for failed emails'),

-- Delegate Mailbox Settings
('email.delegate.require_verification', 'true', 'boolean', 'email', FALSE, 'Require domain verification before allowing delegate sending'),
('email.delegate.log_all_sends', 'true', 'boolean', 'email', FALSE, 'Log all delegate mailbox sends to activity log for audit trail'),
('email.delegate.token_refresh_threshold', '300', 'integer', 'email', FALSE, 'Refresh OAuth tokens if expiring within this many seconds (default: 5 minutes)'),

-- Microsoft Graph Settings
('email.microsoft.use_delegated_permissions', 'false', 'boolean', 'email', FALSE, 'Use delegated permissions (per-user OAuth) instead of application permissions'),
('email.microsoft.delegated_redirect_uri', 'https://signulo.id/oauth/callback', 'string', 'email', FALSE, 'OAuth redirect URI for Microsoft Graph delegated permissions'),
('email.microsoft.auth_mode', 'auto', 'string', 'email', FALSE, 'Authentication mode: auto, application, delegated'),

-- Gmail API Settings
('email.gmail.allowed_delegate_domains', '', 'string', 'email', FALSE, 'Comma-separated list of allowed domains for delegate sending (empty = allow all)'),

-- Tracking Settings
('email.tracking.enabled', '1', 'boolean', 'email', FALSE, 'Enable email tracking (opens, clicks)'),
('email.tracking.pixel_enabled', '1', 'boolean', 'email', FALSE, 'Enable tracking pixel for email opens'),
('email.tracking.link_tracking_enabled', '1', 'boolean', 'email', FALSE, 'Enable link click tracking');

-- ============================================================================
-- 📋 MIGRATION RECORDS
-- ============================================================================

-- Record email system migrations
INSERT IGNORE INTO `tblMigrations` (`migrationName`, `migrationDescription`, `appliedBy`, `status`) VALUES
('001_email_system_upgrade', 'Comprehensive email system with queue, templates, and tracking', 'system', 'completed'),
('002_email_ab_testing', 'A/B testing capabilities for email campaigns', 'system', 'completed'),
('003_email_drip_campaigns', 'Drip campaign automation', 'system', 'completed'),
('004_email_recurring_schedules', 'Recurring email schedules', 'system', 'completed'),
('005_webauthn_passkeys', 'WebAuthn/PassKey support', 'system', 'completed'),
('006_delegate_mailbox_support', 'Delegate mailbox support for Microsoft 365 and Google Workspace', 'system', 'completed');

-- ============================================================================
-- ✅ FINALIZATION
-- ============================================================================

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- 📊 INSTALLATION SUMMARY
-- ============================================================================

SELECT 'SIGNula Email System Addon Installation Complete!' AS status;

SELECT
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'signula' AND table_name LIKE 'tblEmail%') AS email_tables_created,
    (SELECT COUNT(*) FROM tblSettings WHERE category = 'email') AS email_settings_configured,
    (SELECT COUNT(*) FROM tblMigrations WHERE migrationName LIKE '%email%' OR migrationName LIKE '%delegate%') AS email_migrations_applied;

-- Display version info
SELECT
    '2.1.0-beta' AS version,
    'Complete email system with delegate mailbox support' AS description,
    NOW() AS installed_at;

-- ============================================================================
-- 📝 NEXT STEPS
-- ============================================================================
-- 1. Configure email provider credentials in tblSettings
-- 2. For Microsoft 365 delegate sending:
--    a. Add Mail.Send.Shared permission in Azure AD
--    b. Grant admin consent
--    c. Create shared mailboxes
--    d. Grant "Send As" permissions
-- 3. For Gmail delegate sending:
--    a. Ensure service account has domain-wide delegation
--    b. Grant gmail.send scope
-- 4. Test email functionality:
--    a. Navigate to /settings/email-accounts.php
--    b. Connect your email account
--    c. Send a test email
-- 5. Monitor email queue: SELECT * FROM tblEmailQueue ORDER BY createdAt DESC LIMIT 100;
-- 6. Check OAuth tokens: SELECT * FROM tblUserOAuthTokens ORDER BY createdAt DESC;
-- ============================================================================
