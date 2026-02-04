-- ============================================================================
-- SIGNula Database Schema
-- 
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
-- 
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 📧 SIGNula - Delegate Mailbox Support Migration
-- ============================================================================
--
-- Migration: 006
-- Purpose: Add support for sending emails from delegate mailboxes
-- Date: 2026-02-03
--
-- Changes:
-- 1. Add sendAsEmail column to tblEmailQueue
-- 2. Create tblUserOAuthTokens for per-user OAuth tokens (Phase 3)
--
-- Phase Implementation:
-- - Phase 1: Gmail API dynamic mailbox (requires column only)
-- - Phase 2: Microsoft Graph application permissions (uses column)
-- - Phase 3: Microsoft Graph delegated permissions (requires OAuth tokens table)
--
-- ============================================================================

-- ============================================================================
-- 📬 Phase 1 & 2: Update tblEmailQueue
-- ============================================================================

-- Add sendAsEmail column to support delegate mailbox sending
ALTER TABLE `tblEmailQueue`
ADD COLUMN `sendAsEmail` VARCHAR(255) NULL
COMMENT 'Delegate mailbox to send from (if different from fromEmail)'
AFTER `fromName`;

-- Add index for efficient lookups
ALTER TABLE `tblEmailQueue`
ADD INDEX `idx_send_as` (`sendAsEmail`);

-- ============================================================================
-- 📋 Phase 3: User OAuth Tokens Table
-- ============================================================================

-- This table stores per-user OAuth tokens for delegated permissions
-- (Microsoft Graph, Gmail API with user-level access)
CREATE TABLE IF NOT EXISTS `tblUserOAuthTokens` (
    `tokenID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` INT UNSIGNED NOT NULL,
    `provider` VARCHAR(50) NOT NULL COMMENT 'Provider: microsoft_graph, gmail_api',

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
-- ⚙️ Configuration Settings
-- ============================================================================

-- Insert default settings for delegate mailbox features
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `description`, `category`)
VALUES
    -- Microsoft Graph Delegated Permissions
    (
        'email.microsoft.use_delegated_permissions',
        'false',
        FALSE,
        'Use delegated permissions (per-user OAuth) instead of application permissions',
        'email'
    ),
    (
        'email.microsoft.delegated_redirect_uri',
        'https://signulo.id/oauth/callback',
        FALSE,
        'OAuth redirect URI for Microsoft Graph delegated permissions',
        'email'
    ),

    -- Gmail API Domain Restrictions
    (
        'email.gmail.allowed_delegate_domains',
        '',
        FALSE,
        'Comma-separated list of allowed domains for delegate sending (empty = allow all)',
        'email'
    ),

    -- General Delegate Sending Settings
    (
        'email.delegate.require_verification',
        'true',
        FALSE,
        'Require domain verification before allowing delegate sending',
        'email'
    ),
    (
        'email.delegate.log_all_sends',
        'true',
        FALSE,
        'Log all delegate mailbox sends to activity log for audit trail',
        'email'
    ),
    (
        'email.delegate.token_refresh_threshold',
        '300',
        FALSE,
        'Refresh OAuth tokens if expiring within this many seconds (default: 5 minutes)',
        'email'
    )
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `category` = VALUES(`category`);

-- ============================================================================
-- 📊 Update Activity Log for Delegate Sending
-- ============================================================================

-- No schema changes needed - tblActivityLog already supports metadata JSON
-- Delegate sends will be logged with metadata containing:
-- - sendAsEmail: delegate mailbox used
-- - authMethod: 'client_credentials' or 'delegated'
-- - provider: 'microsoft_graph' or 'gmail_api'

-- ============================================================================
-- 🔍 Verification Queries
-- ============================================================================

-- Verify sendAsEmail column was added
-- SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_COMMENT
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND TABLE_NAME = 'tblEmailQueue'
--   AND COLUMN_NAME = 'sendAsEmail';

-- Verify tblUserOAuthTokens was created
-- SELECT TABLE_NAME, TABLE_COMMENT
-- FROM INFORMATION_SCHEMA.TABLES
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND TABLE_NAME = 'tblUserOAuthTokens';

-- Verify new settings were inserted
-- SELECT settingKey, settingValue, description
-- FROM tblSettings
-- WHERE settingKey LIKE 'email.delegate.%'
--    OR settingKey LIKE 'email.microsoft.delegated%'
--    OR settingKey LIKE 'email.gmail.allowed_delegate%';

-- ============================================================================
-- 📝 Rollback Script (if needed)
-- ============================================================================

-- To rollback this migration, run:
--
-- ALTER TABLE `tblEmailQueue` DROP COLUMN `sendAsEmail`;
-- ALTER TABLE `tblEmailQueue` DROP INDEX `idx_send_as`;
-- DROP TABLE IF EXISTS `tblUserOAuthTokens`;
-- DELETE FROM `tblSettings` WHERE settingKey LIKE 'email.delegate.%';
-- DELETE FROM `tblSettings` WHERE settingKey LIKE 'email.microsoft.delegated%';
-- DELETE FROM `tblSettings` WHERE settingKey LIKE 'email.gmail.allowed_delegate%';

-- ============================================================================
-- ✅ Migration Complete
-- ============================================================================
