-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- 📁 SIGNula Universal Login System - Complete Installation Script
-- ============================================================================
-- Version: 2.5.0-beta
-- Date: 2026-02-19
-- Description: Complete database schema for SIGNula universal authentication
-- Includes: All features through v2.5.0-beta (all 13 migrations consolidated)
--
-- Supports: MySQL 8.0+, MariaDB 10.5+
-- Character Set: utf8mb4 (full Unicode support including emojis)
-- Collation: utf8mb4_unicode_ci (case-insensitive Unicode)
--
-- Features Included:
--   ✅ Core authentication system
--   ✅ Multi-factor authentication (TOTP, WebAuthn/Passkeys)
--   ✅ OAuth 2.0 integration (Google, Microsoft, Apple, etc.)
--   ✅ Multi-account OAuth support
--   ✅ Email system with templates, tracking, A/B testing
--   ✅ Drip campaigns and recurring schedules
--   ✅ Contact form submissions
--   ✅ Blog/news system
--   ✅ Support ticket system
--   ✅ Delegate mailbox support
--   ✅ WebAuthn passkeys
--   ✅ RESTful API with rate limiting
--   ✅ Partner API key management
--   ✅ Multi-tier admin system (RBAC, feature toggles, triggers)
--   ✅ Webhooks & payment system (Stripe, PayPal, Coinbase Commerce)
--   ✅ Two-tier payment expansion (invoices, credits, service fees)
--   ✅ Ko-fi & Patreon integration
--
-- Installation:
--   mysql -u your_username -p your_database < signula_complete_install_v2.5.0.sql
--
-- ============================================================================

-- Set session variables
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================================
-- 🗄️ DATABASE CREATION
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `signula`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `signula`;


-- ============================================================================
-- 📊 CORE TABLES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 🏗️ TABLE: tblUsers
-- Purpose: Core user accounts with internal authentication
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblUsers` (
    `userID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique user identifier',
    `userUUID` CHAR(36) NOT NULL UNIQUE COMMENT 'Universal unique identifier (RFC 4122)',
    `username` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Unique username for login',
    `email` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Primary email address',
    `emailVerified` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Email verification status',
    `emailVerifiedAt` DATETIME NULL COMMENT 'Timestamp of email verification',
    `passwordHash` VARCHAR(255) NULL COMMENT 'Argon2id password hash (NULL for passwordless accounts)',
    `passwordSalt` VARCHAR(64) NULL COMMENT 'Additional salt for password hashing',
    `firstName` VARCHAR(100) NULL COMMENT 'User first name',
    `lastName` VARCHAR(100) NULL COMMENT 'User last name',
    `displayName` VARCHAR(200) NULL COMMENT 'Preferred display name',
    `profilePicture` TEXT NULL COMMENT 'Path to profile picture or base64 encoded image',
    `phoneNumber` VARCHAR(20) NULL COMMENT 'Phone number (E.164 format preferred)',
    `phoneVerified` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Phone verification status',
    `dateOfBirth` DATE NULL COMMENT 'Date of birth for age verification',
    `locale` VARCHAR(10) DEFAULT 'en_US' COMMENT 'User locale preference (ISO 639-1 + ISO 3166-1)',
    `timezone` VARCHAR(50) DEFAULT 'UTC' COMMENT 'User timezone (IANA timezone database)',
    `accountStatus` ENUM('active', 'suspended', 'locked', 'pending', 'deleted') NOT NULL DEFAULT 'pending' COMMENT 'Current account status',
    `accountTier` ENUM('free', 'basic', 'premium', 'enterprise', 'admin') NOT NULL DEFAULT 'free' COMMENT 'Subscription tier',
    `requiresPasswordChange` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Force password change on next login',
    `failedLoginAttempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Consecutive failed login attempts',
    `lastFailedLogin` DATETIME NULL COMMENT 'Timestamp of last failed login',
    `lastLoginAt` DATETIME NULL COMMENT 'Timestamp of last successful login',
    `lastLoginIP` VARCHAR(45) NULL COMMENT 'IP address of last login (IPv4 or IPv6)',
    `lastLoginUserAgent` TEXT NULL COMMENT 'User agent string of last login',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Account creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    `deletedAt` DATETIME NULL COMMENT 'Soft delete timestamp',

    INDEX `idx_email` (`email`),
    INDEX `idx_username` (`username`),
    INDEX `idx_userUUID` (`userUUID`),
    INDEX `idx_accountStatus` (`accountStatus`),
    INDEX `idx_createdAt` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Core user accounts';

-- ----------------------------------------------------------------------------
-- 🔐 TABLE: tblUserMFA
-- Purpose: Multi-factor authentication settings per user
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblUserMFA` (
    `mfaID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique MFA record identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `mfaType` ENUM('totp', 'email', 'sms', 'push', 'backup_codes') NOT NULL COMMENT 'MFA method type',
    `isEnabled` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether this MFA method is active',
    `isPrimary` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Primary MFA method for this user',
    `totpSecret` VARCHAR(255) NULL COMMENT 'Encrypted TOTP secret key (Base32)',
    `backupCodes` TEXT NULL COMMENT 'Encrypted JSON array of backup recovery codes',
    `usedBackupCodes` TEXT NULL COMMENT 'JSON array of used backup codes',
    `pushToken` VARCHAR(255) NULL COMMENT 'Encrypted push notification token',
    `lastUsedAt` DATETIME NULL COMMENT 'Last time this MFA method was used',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'MFA method creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_userID` (`userID`),
    INDEX `idx_mfaType` (`mfaType`),
    INDEX `idx_isEnabled` (`isEnabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Multi-factor authentication settings';

-- ----------------------------------------------------------------------------
-- 🔗 TABLE: tblOAuthAccounts (Phase 3.1: Multi-Account Support)
-- Purpose: Third-party OAuth account linkages
-- Supports: Multiple accounts per provider with domain filtering
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblOAuthAccounts` (
    `oauthAccountID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique OAuth link identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `provider` VARCHAR(50) NOT NULL COMMENT 'OAuth provider (google, microsoft, apple, etc.)',
    `providerUserID` VARCHAR(255) NOT NULL COMMENT 'User ID from the provider',
    `email` VARCHAR(255) NULL COMMENT 'Email from provider account',
    `displayName` VARCHAR(255) NULL COMMENT 'Display name from provider',
    `accountType` VARCHAR(20) DEFAULT 'personal' COMMENT 'Account type: personal, work, school',
    `emailDomain` VARCHAR(255) NULL COMMENT 'Email domain for filtering (e.g., company.com)',
    `profilePicture` TEXT NULL COMMENT 'Profile picture URL from provider',
    `accessToken` TEXT NULL COMMENT 'Encrypted OAuth access token',
    `refreshToken` TEXT NULL COMMENT 'Encrypted OAuth refresh token',
    `tokenExpiresAt` DATETIME NULL COMMENT 'Access token expiration',
    `scopes` TEXT NULL COMMENT 'JSON array of granted OAuth scopes',
    `isPrimary` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Primary OAuth account for this user',
    `linkedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When account was linked',
    `lastUsedAt` DATETIME NULL COMMENT 'Last time this account was used for login',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `uq_provider_external_account` (`provider`, `providerUserID`) COMMENT 'Prevent same external account on multiple SIGNula accounts',
    INDEX `idx_userID` (`userID`),
    INDEX `idx_provider` (`provider`),
    INDEX `idx_accountType` (`accountType`),
    INDEX `idx_emailDomain` (`emailDomain`),
    INDEX `idx_isPrimary` (`isPrimary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='OAuth account linkages with multi-account support';

-- ----------------------------------------------------------------------------
-- 🔑 TABLE: tblWebAuthnCredentials (Phase 1.5: PassKeys)
-- Purpose: WebAuthn/FIDO2 PassKey credential storage
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblWebAuthnCredentials` (
    `credentialID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Internal credential identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `credentialPublicKey` TEXT NOT NULL COMMENT 'Base64-encoded COSE public key',
    `credentialID_base64` TEXT NOT NULL COMMENT 'Base64-encoded credential ID',
    `credentialType` VARCHAR(50) DEFAULT 'public-key' COMMENT 'Credential type',
    `transports` TEXT NULL COMMENT 'JSON array of supported transports',
    `attestationType` VARCHAR(50) NULL COMMENT 'Attestation type (none, indirect, direct)',
    `aaguid` VARCHAR(36) NULL COMMENT 'Authenticator AAGUID',
    `signCounter` BIGINT UNSIGNED DEFAULT 0 COMMENT 'Signature counter for replay protection',
    `deviceName` VARCHAR(255) NULL COMMENT 'User-friendly device name',
    `deviceType` VARCHAR(50) NULL COMMENT 'Device type (platform, cross-platform)',
    `lastUsedAt` DATETIME NULL COMMENT 'Last successful authentication',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Credential creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `uq_credentialID` (`credentialID_base64`(255)),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_lastUsedAt` (`lastUsedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='WebAuthn/PassKey credentials';

-- ----------------------------------------------------------------------------
-- 🎯 TABLE: tblWebAuthnChallenges (Phase 1.5: PassKeys)
-- Purpose: Temporary challenge storage for WebAuthn authentication
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblWebAuthnChallenges` (
    `challengeID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Challenge identifier',
    `userID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblUsers (NULL for registration)',
    `challenge` VARCHAR(255) NOT NULL COMMENT 'Base64-encoded challenge string',
    `challengeType` ENUM('registration', 'authentication') NOT NULL COMMENT 'Challenge purpose',
    `expiresAt` DATETIME NOT NULL COMMENT 'Challenge expiration (typically 5 minutes)',
    `used` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether challenge has been used',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Challenge creation timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_challenge` (`challenge`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_expiresAt` (`expiresAt`),
    INDEX `idx_used` (`used`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='WebAuthn authentication challenges';

-- ----------------------------------------------------------------------------
-- 🔗 TABLE: tblPasswordlessTokens (Phase 1.5: Passwordless Login)
-- Purpose: Magic link token storage for passwordless authentication
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblPasswordlessTokens` (
    `tokenID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Token identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `token` VARCHAR(64) NOT NULL COMMENT 'SHA-256 hashed token',
    `selector` VARCHAR(32) NOT NULL COMMENT 'Token selector for lookup',
    `expiresAt` DATETIME NOT NULL COMMENT 'Token expiration (typically 15-30 minutes)',
    `used` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether token has been used',
    `ipAddress` VARCHAR(45) NULL COMMENT 'IP address of request',
    `userAgent` TEXT NULL COMMENT 'User agent of request',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Token creation timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `uq_selector` (`selector`),
    INDEX `idx_token` (`token`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_expiresAt` (`expiresAt`),
    INDEX `idx_used` (`used`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Passwordless magic link tokens';

-- ----------------------------------------------------------------------------
-- 🎫 TABLE: tblSessions
-- Purpose: User session management
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblSessions` (
    `sessionID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Session record identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `sessionToken` VARCHAR(128) NOT NULL UNIQUE COMMENT 'Unique session token (hashed)',
    `sessionData` TEXT NULL COMMENT 'Encrypted session data (JSON)',
    `ipAddress` VARCHAR(45) NULL COMMENT 'IP address (IPv4 or IPv6)',
    `userAgent` TEXT NULL COMMENT 'User agent string',
    `device` VARCHAR(100) NULL COMMENT 'Device type (desktop, mobile, tablet)',
    `browser` VARCHAR(100) NULL COMMENT 'Browser name and version',
    `os` VARCHAR(100) NULL COMMENT 'Operating system',
    `location` VARCHAR(255) NULL COMMENT 'Approximate location (city, country)',
    `isMFAVerified` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether MFA was completed',
    `expiresAt` DATETIME NOT NULL COMMENT 'Session expiration timestamp',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Session creation timestamp',
    `lastActivityAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last activity timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_userID` (`userID`),
    INDEX `idx_sessionToken` (`sessionToken`),
    INDEX `idx_expiresAt` (`expiresAt`),
    INDEX `idx_lastActivityAt` (`lastActivityAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User sessions';

-- ----------------------------------------------------------------------------
-- 🔐 TABLE: tblEmailVerificationTokens
-- Purpose: Email verification tokens
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEmailVerificationTokens` (
    `tokenID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Token identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `email` VARCHAR(255) NOT NULL COMMENT 'Email being verified',
    `token` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Verification token (hashed)',
    `expiresAt` DATETIME NOT NULL COMMENT 'Token expiration',
    `used` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether token has been used',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Token creation timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_userID` (`userID`),
    INDEX `idx_token` (`token`),
    INDEX `idx_expiresAt` (`expiresAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Email verification tokens';

-- ----------------------------------------------------------------------------
-- 🔑 TABLE: tblPasswordResetTokens
-- Purpose: Password reset tokens
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblPasswordResetTokens` (
    `tokenID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Token identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `token` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Reset token (hashed)',
    `expiresAt` DATETIME NOT NULL COMMENT 'Token expiration',
    `used` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether token has been used',
    `ipAddress` VARCHAR(45) NULL COMMENT 'IP address of request',
    `userAgent` TEXT NULL COMMENT 'User agent of request',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Token creation timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_userID` (`userID`),
    INDEX `idx_token` (`token`),
    INDEX `idx_expiresAt` (`expiresAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Password reset tokens';

-- ============================================================================
-- 📋 LOGGING & ACTIVITY TABLES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 📊 TABLE: tblActivityLog
-- Purpose: User activity and audit trail
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblActivityLog` (
    `activityID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Activity log entry identifier',
    `userID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblUsers (NULL for anonymous)',
    `activityType` VARCHAR(100) NOT NULL COMMENT 'Type of activity (login, logout, profile_update, etc.)',
    `activityResult` ENUM('success', 'failure', 'warning', 'info') NOT NULL DEFAULT 'success' COMMENT 'Activity result',
    `activityDetails` TEXT NULL COMMENT 'Detailed description or JSON data',
    `ipAddress` VARCHAR(45) NULL COMMENT 'IP address',
    `userAgent` TEXT NULL COMMENT 'User agent string',
    `requestUri` TEXT NULL COMMENT 'Request URI',
    `severity` ENUM('low', 'medium', 'high', 'critical') DEFAULT 'low' COMMENT 'Activity severity level',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Activity timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_userID` (`userID`),
    INDEX `idx_activityType` (`activityType`),
    INDEX `idx_activityResult` (`activityResult`),
    INDEX `idx_severity` (`severity`),
    INDEX `idx_createdAt` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Activity and audit log';

-- ----------------------------------------------------------------------------
-- ⚠️ TABLE: tblErrorLog
-- Purpose: System error logging
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblErrorLog` (
    `errorID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Error log entry identifier',
    `errorType` VARCHAR(100) NOT NULL COMMENT 'Error type (exception class or category)',
    `errorCode` VARCHAR(50) NULL COMMENT 'Error code',
    `errorMessage` TEXT NOT NULL COMMENT 'Error message',
    `errorFile` VARCHAR(255) NULL COMMENT 'File where error occurred',
    `errorLine` INT NULL COMMENT 'Line number where error occurred',
    `stackTrace` TEXT NULL COMMENT 'Full stack trace',
    `severity` ENUM('debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency') NOT NULL DEFAULT 'error' COMMENT 'Error severity (PSR-3)',
    `userID` BIGINT UNSIGNED NULL COMMENT 'User ID if applicable',
    `ipAddress` VARCHAR(45) NULL COMMENT 'IP address',
    `userAgent` TEXT NULL COMMENT 'User agent',
    `requestUri` TEXT NULL COMMENT 'Request URI',
    `requestMethod` VARCHAR(10) NULL COMMENT 'HTTP method',
    `requestData` TEXT NULL COMMENT 'Request data (sanitized)',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Error timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_errorType` (`errorType`),
    INDEX `idx_severity` (`severity`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_createdAt` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System error log';

-- ============================================================================
-- ⚙️ CONFIGURATION TABLES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 🔧 TABLE: tblSettings
-- Purpose: Global system settings and configuration
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblSettings` (
    `settingID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Setting identifier',
    `settingKey` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Setting key (dot notation)',
    `settingValue` TEXT NULL COMMENT 'Setting value (encrypted if sensitive)',
    `settingType` ENUM('string', 'integer', 'boolean', 'json', 'encrypted') NOT NULL DEFAULT 'string' COMMENT 'Value data type',
    `settingCategory` VARCHAR(100) NOT NULL COMMENT 'Setting category (security, email, oauth, etc.)',
    `isSensitive` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether value should be encrypted',
    `isEditable` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether setting can be modified',
    `description` TEXT NULL COMMENT 'Setting description',
    `defaultValue` TEXT NULL COMMENT 'Default value',
    `validationRules` TEXT NULL COMMENT 'JSON validation rules',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    `updatedBy` BIGINT UNSIGNED NULL COMMENT 'User who last updated',

    INDEX `idx_settingKey` (`settingKey`),
    INDEX `idx_settingCategory` (`settingCategory`),
    INDEX `idx_isSensitive` (`isSensitive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System settings';

-- ----------------------------------------------------------------------------
-- 👤 TABLE: tblUserPreferences
-- Purpose: User-specific preferences and settings
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblUserPreferences` (
    `preferenceID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Preference identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `preferenceKey` VARCHAR(255) NOT NULL COMMENT 'Preference key',
    `preferenceValue` TEXT NULL COMMENT 'Preference value (JSON)',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Preference creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `uq_user_preference` (`userID`, `preferenceKey`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_preferenceKey` (`preferenceKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User preferences';

-- ============================================================================
-- 🗄️ MIGRATION TRACKING TABLE
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 📋 TABLE: tblMigrations
-- Purpose: Track applied database migrations
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblMigrations` (
    `migrationID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Migration identifier',
    `migrationName` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Migration file name',
    `migrationDescription` TEXT NULL COMMENT 'Migration description',
    `appliedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When migration was applied',
    `appliedBy` VARCHAR(100) DEFAULT 'system' COMMENT 'Who applied the migration',
    `status` ENUM('completed', 'failed', 'rolled_back') NOT NULL DEFAULT 'completed' COMMENT 'Migration status',

    INDEX `idx_migrationName` (`migrationName`),
    INDEX `idx_appliedAt` (`appliedAt`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Database migration tracking';

-- ============================================================================
-- 👁️ DATABASE VIEWS
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 📊 VIEW: vwUserSessions
-- Purpose: Comprehensive view of active user sessions
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vwUserSessions` AS
SELECT
    s.sessionID,
    s.userID,
    u.email,
    u.username,
    u.displayName,
    s.device,
    s.browser,
    s.os,
    s.location,
    s.ipAddress,
    s.isMFAVerified,
    s.createdAt,
    s.lastActivityAt,
    s.expiresAt,
    CASE WHEN s.expiresAt > NOW() THEN 'active' ELSE 'expired' END AS status
FROM tblSessions s
JOIN tblUsers u ON s.userID = u.userID
WHERE s.expiresAt > NOW();

-- ----------------------------------------------------------------------------
-- 📊 VIEW: vwUserSecurityOverview
-- Purpose: Security status overview per user
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vwUserSecurityOverview` AS
SELECT
    u.userID,
    u.email,
    u.username,
    u.displayName,
    u.emailVerified,
    u.accountStatus,
    (SELECT COUNT(*) FROM tblUserMFA WHERE userID = u.userID AND isEnabled = TRUE) AS mfa_methods_count,
    (SELECT COUNT(*) FROM tblWebAuthnCredentials WHERE userID = u.userID) AS passkeys_count,
    (SELECT COUNT(*) FROM tblOAuthAccounts WHERE userID = u.userID) AS oauth_accounts_count,
    (SELECT COUNT(*) FROM tblSessions WHERE userID = u.userID AND expiresAt > NOW()) AS active_sessions_count,
    u.lastLoginAt,
    u.lastLoginIP,
    u.createdAt
FROM tblUsers u;

-- ============================================================================
-- 🔧 STORED PROCEDURES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 🧹 PROCEDURE: cleanupExpiredSessions
-- Purpose: Remove expired sessions
-- ----------------------------------------------------------------------------
DELIMITER $$

CREATE PROCEDURE IF NOT EXISTS `cleanupExpiredSessions`()
BEGIN
    DELETE FROM tblSessions WHERE expiresAt < NOW();

    SELECT ROW_COUNT() AS sessions_deleted;
END$$

DELIMITER ;

-- ----------------------------------------------------------------------------
-- 🧹 PROCEDURE: cleanupExpiredTokens
-- Purpose: Remove expired authentication tokens
-- ----------------------------------------------------------------------------
DELIMITER $$

CREATE PROCEDURE IF NOT EXISTS `cleanupExpiredTokens`()
BEGIN
    DECLARE deleted_verification INT DEFAULT 0;
    DECLARE deleted_password_reset INT DEFAULT 0;
    DECLARE deleted_passwordless INT DEFAULT 0;
    DECLARE deleted_challenges INT DEFAULT 0;

    -- Clean email verification tokens
    DELETE FROM tblEmailVerificationTokens WHERE expiresAt < NOW();
    SET deleted_verification = ROW_COUNT();

    -- Clean password reset tokens
    DELETE FROM tblPasswordResetTokens WHERE expiresAt < NOW();
    SET deleted_password_reset = ROW_COUNT();

    -- Clean passwordless tokens
    DELETE FROM tblPasswordlessTokens WHERE expiresAt < NOW();
    SET deleted_passwordless = ROW_COUNT();

    -- Clean WebAuthn challenges
    DELETE FROM tblWebAuthnChallenges WHERE expiresAt < NOW();
    SET deleted_challenges = ROW_COUNT();

    SELECT
        deleted_verification AS verification_tokens_deleted,
        deleted_password_reset AS password_reset_tokens_deleted,
        deleted_passwordless AS passwordless_tokens_deleted,
        deleted_challenges AS webauthn_challenges_deleted,
        (deleted_verification + deleted_password_reset + deleted_passwordless + deleted_challenges) AS total_deleted;
END$$

DELIMITER ;

-- ----------------------------------------------------------------------------
-- 🧹 PROCEDURE: cleanupExpiredAuthTokens
-- Purpose: Comprehensive cleanup of all expired tokens
-- ----------------------------------------------------------------------------
DELIMITER $$

CREATE PROCEDURE IF NOT EXISTS `cleanupExpiredAuthTokens`()
BEGIN
    CALL cleanupExpiredSessions();
    CALL cleanupExpiredTokens();
END$$

DELIMITER ;

-- ============================================================================
-- 📝 INITIAL SETTINGS DATA
-- ============================================================================

-- Insert default system settings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`) VALUES
-- Security Settings
('security.session.lifetime', '3600', 'integer', 'security', FALSE, 'Session lifetime in seconds (1 hour)'),
('security.session.remember_lifetime', '2592000', 'integer', 'security', FALSE, 'Remember me session lifetime (30 days)'),
('security.password.min_length', '8', 'integer', 'security', FALSE, 'Minimum password length'),
('security.password.require_uppercase', '1', 'boolean', 'security', FALSE, 'Require uppercase letters'),
('security.password.require_lowercase', '1', 'boolean', 'security', FALSE, 'Require lowercase letters'),
('security.password.require_numbers', '1', 'boolean', 'security', FALSE, 'Require numbers'),
('security.password.require_special', '1', 'boolean', 'security', FALSE, 'Require special characters'),
('security.rate_limit.login_attempts', '5', 'integer', 'security', FALSE, 'Max login attempts before lockout'),
('security.rate_limit.lockout_duration', '900', 'integer', 'security', FALSE, 'Lockout duration in seconds (15 minutes)'),
('security.encryption.key', '', 'encrypted', 'security', TRUE, 'Primary encryption key (AES-256)'),
('security.encryption.salt', '', 'encrypted', 'security', TRUE, 'Encryption salt'),

-- Authentication Settings
('auth.email_verification.required', '1', 'boolean', 'authentication', FALSE, 'Require email verification'),
('auth.email_verification.token_lifetime', '86400', 'integer', 'authentication', FALSE, 'Email verification token lifetime (24 hours)'),
('auth.password_reset.token_lifetime', '3600', 'integer', 'authentication', FALSE, 'Password reset token lifetime (1 hour)'),
('auth.passwordless.enabled', '1', 'boolean', 'authentication', FALSE, 'Enable passwordless authentication'),
('auth.passwordless.token_lifetime', '1800', 'integer', 'authentication', FALSE, 'Passwordless token lifetime (30 minutes)'),

-- MFA Settings
('auth.mfa.enabled', '1', 'boolean', 'authentication', FALSE, 'Enable multi-factor authentication'),
('auth.mfa.backup_codes_count', '10', 'integer', 'authentication', FALSE, 'Number of backup codes to generate'),
('auth.mfa.totp_window', '1', 'integer', 'authentication', FALSE, 'TOTP time window (±periods)'),

-- WebAuthn Settings
('auth.webauthn.enabled', '1', 'boolean', 'authentication', FALSE, 'Enable WebAuthn/PassKeys'),
('auth.webauthn.rp_id', 'signula.id', 'string', 'authentication', FALSE, 'Relying Party ID'),
('auth.webauthn.rp_name', 'SIGNula', 'string', 'authentication', FALSE, 'Relying Party Name'),
('auth.webauthn.timeout', '60000', 'integer', 'authentication', FALSE, 'WebAuthn timeout in milliseconds'),

-- OAuth Settings (Placeholders - Add your own credentials)
('oauth.google.enabled', '0', 'boolean', 'oauth', FALSE, 'Enable Google OAuth'),
('oauth.google.client_id', '', 'encrypted', 'oauth', TRUE, 'Google OAuth Client ID'),
('oauth.google.client_secret', '', 'encrypted', 'oauth', TRUE, 'Google OAuth Client Secret'),
('oauth.microsoft.enabled', '0', 'boolean', 'oauth', FALSE, 'Enable Microsoft OAuth'),
('oauth.microsoft.client_id', '', 'encrypted', 'oauth', TRUE, 'Microsoft OAuth Client ID'),
('oauth.microsoft.client_secret', '', 'encrypted', 'oauth', TRUE, 'Microsoft OAuth Client Secret'),
('oauth.apple.enabled', '0', 'boolean', 'oauth', FALSE, 'Enable Apple OAuth'),
('oauth.apple.client_id', '', 'encrypted', 'oauth', TRUE, 'Apple OAuth Client ID'),
('oauth.apple.client_secret', '', 'encrypted', 'oauth', TRUE, 'Apple OAuth Client Secret'),
('oauth.facebook.enabled', '0', 'boolean', 'oauth', FALSE, 'Enable Facebook OAuth'),
('oauth.facebook.client_id', '', 'encrypted', 'oauth', TRUE, 'Facebook OAuth Client ID'),
('oauth.facebook.client_secret', '', 'encrypted', 'oauth', TRUE, 'Facebook OAuth Client Secret'),
('oauth.linkedin.enabled', '0', 'boolean', 'oauth', FALSE, 'Enable LinkedIn OAuth'),
('oauth.linkedin.client_id', '', 'encrypted', 'oauth', TRUE, 'LinkedIn OAuth Client ID'),
('oauth.linkedin.client_secret', '', 'encrypted', 'oauth', TRUE, 'LinkedIn OAuth Client Secret'),
('oauth.github.enabled', '0', 'boolean', 'oauth', FALSE, 'Enable GitHub OAuth'),
('oauth.github.client_id', '', 'encrypted', 'oauth', TRUE, 'GitHub OAuth Client ID'),
('oauth.github.client_secret', '', 'encrypted', 'oauth', TRUE, 'GitHub OAuth Client Secret'),

-- Email Settings
('email.smtp.enabled', '0', 'boolean', 'email', FALSE, 'Use SMTP for email'),
('email.smtp.host', '', 'string', 'email', FALSE, 'SMTP host'),
('email.smtp.port', '587', 'integer', 'email', FALSE, 'SMTP port'),
('email.smtp.encryption', 'tls', 'string', 'email', FALSE, 'SMTP encryption (tls/ssl)'),
('email.smtp.username', '', 'encrypted', 'email', TRUE, 'SMTP username'),
('email.smtp.password', '', 'encrypted', 'email', TRUE, 'SMTP password'),
('email.from.address', 'noreply@signula.id', 'string', 'email', FALSE, 'From email address'),
('email.from.name', 'SIGNula', 'string', 'email', FALSE, 'From name'),

-- Application Settings
('app.name', 'SIGNula', 'string', 'application', FALSE, 'Application name'),
('app.url', 'https://signula.id', 'string', 'application', FALSE, 'Application URL'),
('app.timezone', 'UTC', 'string', 'application', FALSE, 'Default timezone'),
('app.locale', 'en_US', 'string', 'application', FALSE, 'Default locale'),
('app.debug', '0', 'boolean', 'application', FALSE, 'Debug mode'),
('app.maintenance_mode', '0', 'boolean', 'application', FALSE, 'Maintenance mode');

-- ============================================================================
-- 📋 MIGRATION RECORDS
-- ============================================================================

-- Record migrations included in this install
INSERT INTO `tblMigrations` (`migrationName`, `migrationDescription`, `appliedBy`, `status`) VALUES
('001_initial_schema', 'Initial database schema with core tables', 'system', 'completed'),
('002_webauthn_passkeys', 'WebAuthn/PassKey support (Phase 1.5)', 'system', 'completed'),
('003_oauth_multi_account_support', 'OAuth multi-account enhancement with accountType and emailDomain', 'system', 'completed');

-- ============================================================================
-- ✅ FINALIZATION
-- ============================================================================

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- 📊 INSTALLATION SUMMARY
-- ============================================================================

SELECT 'SIGNula Database Installation Complete!' AS status;

SELECT
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'signula') AS tables_created,
    (SELECT COUNT(*) FROM information_schema.views WHERE table_schema = 'signula') AS views_created,
    (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = 'signula') AS procedures_created,
    (SELECT COUNT(*) FROM tblSettings) AS settings_configured,
    (SELECT COUNT(*) FROM tblMigrations) AS migrations_applied;

-- Display version info
SELECT
    '2.0.1-beta' AS version,
    'Complete installation with OAuth multi-account support' AS description,
    NOW() AS installed_at;

-- ============================================================================
-- 📝 NEXT STEPS
-- ============================================================================
-- 1. Update security.encryption.key and security.encryption.salt in tblSettings
-- 2. Configure OAuth provider credentials in tblSettings (if using OAuth)
-- 3. Configure SMTP settings in tblSettings (if using email)
-- 4. Update app.url and auth.webauthn.rp_id to match your domain
-- 5. Set app.debug to 0 in production
-- 6. Set appropriate file permissions on _private/ directory
-- 7. Create your first user account via the registration page
-- ============================================================================

-- ============================================================================
-- 📧 EMAIL SYSTEM ENHANCEMENTS
-- ============================================================================

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

-- Insert default email templates (only if they do not exist)
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

CREATE TABLE IF NOT EXISTS `tblEmailABTests` (
    `testID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `testName` VARCHAR(255) NOT NULL COMMENT 'Descriptive test name',
    `testType` ENUM('subject', 'content', 'from_name', 'send_time', 'multi') NOT NULL DEFAULT 'subject' COMMENT 'Type of A/B test',
    `description` TEXT NULL COMMENT 'Test description',

    -- 🎯 Test Configuration
    `sampleSizePercentage` DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT 'Percentage of recipients to include',
    `winnerSelectionMetric` ENUM('open_rate', 'click_rate', 'conversion_rate') NOT NULL DEFAULT 'open_rate' COMMENT 'Metric to determine winner',
    `autoSelectWinner` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Auto-select winner when statistically significant',

    -- 📊 Test Status
    `status` ENUM('draft', 'running', 'completed', 'cancelled') NOT NULL DEFAULT 'draft' COMMENT 'Current test status',
    `winnerVariantID` INT UNSIGNED NULL COMMENT 'ID of winning variant (when completed)',

    -- 📅 Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Test created timestamp',
    `createdBy` INT UNSIGNED NULL COMMENT 'User ID who created test',
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
    `variantID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `testID` INT UNSIGNED NOT NULL COMMENT 'Parent test ID',
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
INSERT INTO tblEmailABTests (
INSERT INTO tblEmailABTestVariants (
INSERT INTO tblEmailABTestVariants (
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)

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
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
VALUES (
    'email.drip_campaigns.migration_version',
    '2.2.0',
    'Email drip campaigns migration version',
    'email'
) ON DUPLICATE KEY UPDATE settingValue = '2.2.0';

-- Enable drip campaigns feature
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
VALUES (
    'email.drip_campaigns.enabled',
    '1',
    'Enable email drip campaign features',
    'email'
) ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- Processing batch size
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
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
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
VALUES (
    'email.recurring_schedules.migration_version',
    '2.3.0',
    'Email recurring schedules migration version',
    'email'
) ON DUPLICATE KEY UPDATE settingValue = '2.3.0';

-- Enable recurring schedules feature
INSERT INTO tblSettings (settingKey, settingValue, settingDescription, category)
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


-- ============================================================================
-- 🔐 OAUTH MULTI-ACCOUNT SUPPORT
-- ============================================================================

ALTER TABLE tblOAuthAccounts
ADD COLUMN accountType VARCHAR(20) DEFAULT 'personal' AFTER displayName,
ADD INDEX idx_accountType (accountType);
ALTER TABLE tblOAuthAccounts
ADD COLUMN emailDomain VARCHAR(255) NULL AFTER accountType,
ADD INDEX idx_emailDomain (emailDomain);
ALTER TABLE tblOAuthAccounts
ADD CONSTRAINT uq_provider_external_account UNIQUE (provider, providerUserID);


-- ============================================================================
-- 📬 CONTACT FORM SYSTEM
-- ============================================================================

CREATE TABLE IF NOT EXISTS tblContactSubmissions (
    -- Primary Key
    submissionID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    COMMENT 'Unique identifier for each contact submission',

    -- Contact Information
    name VARCHAR(100) NOT NULL,
    COMMENT 'Full name of person submitting',

    email VARCHAR(255) NOT NULL,
    COMMENT 'Email address for response',

    -- Inquiry Details
    inquiryType ENUM('general', 'support', 'sales', 'partnership', 'feedback') NOT NULL DEFAULT 'general',
    COMMENT 'Type of inquiry to route to appropriate team',

    subject VARCHAR(200) NOT NULL,
    COMMENT 'Subject line of the inquiry',

    message TEXT NOT NULL,
    COMMENT 'Full message content (up to 2000 characters in form)',

    -- Status Tracking
    status ENUM('new', 'in_progress', 'responded', 'resolved', 'spam') NOT NULL DEFAULT 'new',
    COMMENT 'Current status of the submission',

    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    COMMENT 'Priority level assigned by staff',

    -- Assignment & Follow-up
    assignedTo BIGINT UNSIGNED NULL DEFAULT NULL,
    COMMENT 'userID of staff member assigned to this inquiry',

    responseNotes TEXT NULL,
    COMMENT 'Internal notes about the response',

    respondedAt DATETIME NULL DEFAULT NULL,
    COMMENT 'When response was sent',

    respondedBy BIGINT UNSIGNED NULL DEFAULT NULL,
    COMMENT 'userID of staff member who responded',

    -- Security & Tracking
    ipAddress VARCHAR(45) NOT NULL,
    COMMENT 'IPv4 or IPv6 address of submitter',

    userAgent VARCHAR(500) NULL,
    COMMENT 'Browser user agent string',

    referrer VARCHAR(500) NULL,
    COMMENT 'Page that referred to contact form',

    -- Spam Detection
    spamScore DECIMAL(3, 2) NULL DEFAULT NULL,
    COMMENT 'Spam probability score (0.00 to 1.00)',

    isSpam BOOLEAN NOT NULL DEFAULT FALSE,
    COMMENT 'Flagged as spam',

    -- Timestamps
    submittedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    COMMENT 'When form was submitted',

    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    COMMENT 'Last update timestamp',

    -- Indexes for performance
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_inquiry_type (inquiryType),
    INDEX idx_submitted_at (submittedAt),
    INDEX idx_assigned_to (assignedTo),
    INDEX idx_is_spam (isSpam),

    -- Foreign key constraints (if staff management exists)
    CONSTRAINT fk_contact_assigned_to FOREIGN KEY (assignedTo)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_contact_responded_by FOREIGN KEY (respondedBy)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Contact form submissions from SIGNula.com marketing website';

-- ============================================================================
-- Initial Data / Settings
-- ============================================================================

-- Add setting for contact form email notifications
INSERT INTO tblSettings (settingKey, settingValue, settingType, isSensitive, description, category)
VALUES
    ('contact.notification.enabled', '1', 'boolean', 0, 'Enable email notifications for new contact submissions', 'Contact Form'),
    ('contact.notification.email', 'support@signula.com', 'string', 0, 'Email address to receive contact form notifications', 'Contact Form'),
    ('contact.spam.threshold', '0.70', 'decimal', 0, 'Spam score threshold (0.00-1.00) to auto-flag as spam', 'Contact Form'),
    ('contact.rate_limit.per_email', '5', 'integer', 0, 'Maximum submissions per email per hour', 'Contact Form'),
    ('contact.rate_limit.per_ip', '10', 'integer', 0, 'Maximum submissions per IP per hour', 'Contact Form')
ON DUPLICATE KEY UPDATE
    settingValue = VALUES(settingValue),
    description = VALUES(description);

-- ============================================================================
-- Migration Complete
-- ============================================================================

-- Verify table creation
SELECT
    'Migration 004 Complete' AS status,
    COUNT(*) AS table_exists
FROM information_schema.tables
WHERE table_schema = 'signula'
    AND table_name = 'tblContactSubmissions';

-- Show table structure
DESCRIBE tblContactSubmissions;


-- ============================================================================
-- 📰 BLOG/NEWS SYSTEM
-- ============================================================================

CREATE TABLE IF NOT EXISTS tblBlogPosts (
    postID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Post Content
    title VARCHAR(255) NOT NULL COMMENT 'Post title',
    slug VARCHAR(255) NOT NULL COMMENT 'URL-friendly slug',
    excerpt TEXT DEFAULT NULL COMMENT 'Short preview text (auto-generated if null)',
    content LONGTEXT NOT NULL COMMENT 'Full post content (HTML)',
    featuredImage VARCHAR(500) DEFAULT NULL COMMENT 'Path to featured image',

    -- Author Information
    authorID BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblUsers.userID',

    -- Categorization
    category VARCHAR(100) DEFAULT 'Uncategorized' COMMENT 'Post category',
    tags TEXT DEFAULT NULL COMMENT 'Comma-separated tags',

    -- Publishing
    status ENUM('draft', 'scheduled', 'published', 'archived') NOT NULL DEFAULT 'draft' COMMENT 'Publication status',
    publishedAt DATETIME DEFAULT NULL COMMENT 'Publication date/time',
    scheduledFor DATETIME DEFAULT NULL COMMENT 'Scheduled publication date',

    -- SEO
    metaTitle VARCHAR(70) DEFAULT NULL COMMENT 'SEO title (defaults to title if null)',
    metaDescription VARCHAR(160) DEFAULT NULL COMMENT 'SEO meta description',
    metaKeywords VARCHAR(255) DEFAULT NULL COMMENT 'SEO keywords',

    -- Engagement
    viewCount BIGINT UNSIGNED DEFAULT 0 COMMENT 'Number of views',
    allowComments BOOLEAN DEFAULT TRUE COMMENT 'Enable/disable comments',

    -- Versioning
    version INT UNSIGNED DEFAULT 1 COMMENT 'Content version number',
    lastEditedBy BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID',
    lastEditedAt DATETIME DEFAULT NULL COMMENT 'Last edit timestamp',

    -- Audit Fields
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp',
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    deletedAt DATETIME DEFAULT NULL COMMENT 'Soft delete timestamp',
    isDeleted BOOLEAN DEFAULT FALSE COMMENT 'Soft delete flag',

    PRIMARY KEY (postID),
    UNIQUE KEY idx_slug (slug),
    KEY idx_author (authorID),
    KEY idx_status (status),
    KEY idx_published (publishedAt),
    KEY idx_category (category),
    KEY idx_created (createdAt),
    KEY idx_deleted (isDeleted, deletedAt),
    FULLTEXT KEY idx_search (title, content, excerpt),

    CONSTRAINT fk_blogposts_author FOREIGN KEY (authorID)
        REFERENCES tblUsers(userID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_blogposts_editor FOREIGN KEY (lastEditedBy)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Blog posts and announcements';

-- ============================================
-- Table: tblBlogCategories
-- Purpose: Manage blog categories
-- ============================================
CREATE TABLE IF NOT EXISTS tblBlogCategories (
    categoryID INT UNSIGNED NOT NULL AUTO_INCREMENT,
    categoryName VARCHAR(100) NOT NULL COMMENT 'Category name',
    categorySlug VARCHAR(100) NOT NULL COMMENT 'URL-friendly slug',
    description TEXT DEFAULT NULL COMMENT 'Category description',
    parentID INT UNSIGNED DEFAULT NULL COMMENT 'Parent category (for nested categories)',
    displayOrder INT DEFAULT 0 COMMENT 'Sort order',
    isActive BOOLEAN DEFAULT TRUE COMMENT 'Active status',
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (categoryID),
    UNIQUE KEY idx_slug (categorySlug),
    KEY idx_parent (parentID),
    KEY idx_active (isActive),

    CONSTRAINT fk_category_parent FOREIGN KEY (parentID)
        REFERENCES tblBlogCategories(categoryID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Blog post categories';

-- ============================================
-- Table: tblBlogComments
-- Purpose: User comments on blog posts
-- ============================================
CREATE TABLE IF NOT EXISTS tblBlogComments (
    commentID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    postID BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblBlogPosts.postID',
    userID BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID (null for guests)',
    parentCommentID BIGINT UNSIGNED DEFAULT NULL COMMENT 'Parent comment for nested replies',

    -- Comment Content
    authorName VARCHAR(100) NOT NULL COMMENT 'Commenter name',
    authorEmail VARCHAR(255) DEFAULT NULL COMMENT 'Commenter email (for guests)',
    content TEXT NOT NULL COMMENT 'Comment text',

    -- Moderation
    status ENUM('pending', 'approved', 'spam', 'rejected') NOT NULL DEFAULT 'pending' COMMENT 'Moderation status',
    moderatedBy BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID',
    moderatedAt DATETIME DEFAULT NULL COMMENT 'Moderation timestamp',
    moderationNote TEXT DEFAULT NULL COMMENT 'Internal moderation note',

    -- Metadata
    ipAddress VARCHAR(45) NOT NULL COMMENT 'Commenter IP (IPv4/IPv6)',
    userAgent TEXT DEFAULT NULL COMMENT 'Browser user agent',

    -- Audit Fields
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deletedAt DATETIME DEFAULT NULL,
    isDeleted BOOLEAN DEFAULT FALSE,

    PRIMARY KEY (commentID),
    KEY idx_post (postID),
    KEY idx_user (userID),
    KEY idx_parent (parentCommentID),
    KEY idx_status (status),
    KEY idx_created (createdAt),
    KEY idx_deleted (isDeleted),

    CONSTRAINT fk_comments_post FOREIGN KEY (postID)
        REFERENCES tblBlogPosts(postID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_comments_user FOREIGN KEY (userID)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_comments_parent FOREIGN KEY (parentCommentID)
        REFERENCES tblBlogComments(commentID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_comments_moderator FOREIGN KEY (moderatedBy)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Blog post comments';

-- ============================================
-- Table: tblBlogTags
-- Purpose: Manage blog tags
-- ============================================
CREATE TABLE IF NOT EXISTS tblBlogTags (
    tagID INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tagName VARCHAR(50) NOT NULL COMMENT 'Tag name',
    tagSlug VARCHAR(50) NOT NULL COMMENT 'URL-friendly slug',
    usageCount INT UNSIGNED DEFAULT 0 COMMENT 'Number of posts using this tag',
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (tagID),
    UNIQUE KEY idx_slug (tagSlug),
    KEY idx_usage (usageCount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Blog post tags';

-- ============================================
-- Table: tblBlogPostTags (Junction Table)
-- Purpose: Many-to-many relationship between posts and tags
-- ============================================
CREATE TABLE IF NOT EXISTS tblBlogPostTags (
    postID BIGINT UNSIGNED NOT NULL,
    tagID INT UNSIGNED NOT NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (postID, tagID),
    KEY idx_tag (tagID),

    CONSTRAINT fk_posttags_post FOREIGN KEY (postID)
        REFERENCES tblBlogPosts(postID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_posttags_tag FOREIGN KEY (tagID)
        REFERENCES tblBlogTags(tagID) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Post-Tag relationships';

-- ============================================
-- Insert Default Categories
-- ============================================
INSERT INTO tblBlogCategories (categoryName, categorySlug, description, displayOrder) VALUES
('Announcements', 'announcements', 'Product announcements and company news', 1),
('Updates', 'updates', 'Feature updates and improvements', 2),
('Security', 'security', 'Security updates and best practices', 3),
('Tutorials', 'tutorials', 'How-to guides and tutorials', 4),
('Engineering', 'engineering', 'Technical deep dives and engineering posts', 5),
('Company', 'company', 'Company culture and team updates', 6);

-- ============================================
-- Insert Blog Settings
-- ============================================
INSERT INTO tblSettings (settingKey, settingValue, category, dataType, isEditable, isSensitive, description) VALUES
('blog.postsPerPage', '10', 'Blog', 'integer', TRUE, FALSE, 'Number of posts per page'),
('blog.enableComments', 'true', 'Blog', 'boolean', TRUE, FALSE, 'Enable comments on blog posts'),
('blog.moderateComments', 'true', 'Blog', 'boolean', TRUE, FALSE, 'Require comment moderation'),
('blog.allowGuestComments', 'false', 'Blog', 'boolean', TRUE, FALSE, 'Allow non-authenticated users to comment'),
('blog.showAuthorInfo', 'true', 'Blog', 'boolean', TRUE, FALSE, 'Show author information on posts'),
('blog.enableSocialShare', 'true', 'Blog', 'boolean', TRUE, FALSE, 'Enable social media sharing buttons'),
('blog.rssEnabled', 'true', 'Blog', 'boolean', TRUE, FALSE, 'Enable RSS feed'),
('blog.excerptLength', '200', 'Blog', 'integer', TRUE, FALSE, 'Auto-generated excerpt character limit')
ON DUPLICATE KEY UPDATE updatedAt = CURRENT_TIMESTAMP;

-- ============================================
-- Sample Blog Post (Welcome Post)
-- ============================================
INSERT INTO tblBlogPosts (
    title,
    slug,
    excerpt,
    content,
    authorID,
    category,
    status,
    publishedAt,
    metaTitle,
    metaDescription,
    allowComments
) VALUES (
    'Welcome to SIGNula Blog',
    'welcome-to-signula-blog',
    'We''re excited to launch the official SIGNula blog where we''ll share product updates, security insights, and engineering deep dives.',
    '<h2>Welcome to the SIGNula Blog!</h2>
    <p>We''re thrilled to launch the official SIGNula blog. This is where we''ll share:</p>
    <ul>
        <li><strong>Product Announcements:</strong> New features and updates</li>
        <li><strong>Security Insights:</strong> Best practices and security updates</li>
        <li><strong>Engineering Deep Dives:</strong> Technical articles from our team</li>
        <li><strong>Company News:</strong> What''s happening at SIGNula</li>
    </ul>
    <p>Stay tuned for regular updates as we continue to build the future of universal authentication.</p>
    <p>Have questions or topics you''d like us to cover? <a href="/contact">Let us know!</a></p>',
    1,
    'Announcements',
    'published',
    CURRENT_TIMESTAMP,
    'Welcome to SIGNula Blog',
    'Discover product updates, security insights, and engineering deep dives from the SIGNula team.',
    TRUE
);


-- ============================================================================
-- 🔑 WEBAUTHN PASSKEYS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblWebAuthnCredentials` (
    `credentialID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` INT UNSIGNED NOT NULL COMMENT 'User who owns this credential',

    -- 🔐 WebAuthn Credential Data
    `credentialPublicKeyID` VARCHAR(255) NOT NULL COMMENT 'Base64 encoded credential ID',
    `credentialPublicKey` TEXT NOT NULL COMMENT 'Base64 encoded public key',
    `attestationType` VARCHAR(50) NULL COMMENT 'Attestation type (none, indirect, direct)',
    `aaguid` VARCHAR(36) NULL COMMENT 'Authenticator AAGUID',

    -- 📊 Authenticator Info
    `authenticatorType` ENUM('platform', 'cross-platform', 'unknown') NOT NULL DEFAULT 'unknown' COMMENT 'Authenticator type',
    `transports` JSON NULL COMMENT 'Supported transports (usb, nfc, ble, internal)',

    -- 📱 Device Info
    `deviceName` VARCHAR(255) NULL COMMENT 'User-friendly device name',
    `deviceType` VARCHAR(50) NULL COMMENT 'Device type (phone, tablet, laptop, security-key)',
    `userAgent` TEXT NULL COMMENT 'User agent at registration',

    -- 🔢 Usage Tracking
    `signCount` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Signature counter (for cloning detection)',
    `lastUsedAt` DATETIME NULL COMMENT 'Last authentication time',
    `usageCount` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of times used',

    -- 📅 Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Credential registered',
    `createdIP` VARCHAR(45) NULL COMMENT 'IP address at registration',
    `expiresAt` DATETIME NULL COMMENT 'Optional expiration date',

    -- 📊 Status
    `isActive` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Credential is active',
    `revokedAt` DATETIME NULL COMMENT 'When credential was revoked',
    `revokeReason` VARCHAR(255) NULL COMMENT 'Reason for revocation',

    PRIMARY KEY (`credentialID`),
    UNIQUE KEY `uk_credential_public_key_id` (`credentialPublicKeyID`),
    INDEX `idx_user` (`userID`),
    INDEX `idx_active` (`isActive`),
    INDEX `idx_last_used` (`lastUsedAt` DESC),

    CONSTRAINT `fk_webauthn_credential_user`
        FOREIGN KEY (`userID`)
        REFERENCES `tblUsers`(`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='WebAuthn/PassKey credentials';

-- ============================================================================
-- 🎫 PASSWORDLESS LOGIN TOKENS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblPasswordlessTokens` (
    `tokenID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` INT UNSIGNED NULL COMMENT 'User ID (null if email not yet registered)',
    `email` VARCHAR(255) NOT NULL COMMENT 'Email address',

    -- 🔐 Token Data
    `token` VARCHAR(64) NOT NULL COMMENT 'Secure random token',
    `tokenHash` VARCHAR(255) NOT NULL COMMENT 'Hashed token for verification',

    -- 📊 Token Info
    `purpose` ENUM('login', 'register', 'verify') NOT NULL DEFAULT 'login' COMMENT 'Token purpose',
    `ipAddress` VARCHAR(45) NULL COMMENT 'Requesting IP address',
    `userAgent` TEXT NULL COMMENT 'Requesting user agent',

    -- ⏰ Expiration
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Token created',
    `expiresAt` DATETIME NOT NULL COMMENT 'Token expiration',
    `validityMinutes` INT UNSIGNED NOT NULL DEFAULT 15 COMMENT 'Validity period in minutes',

    -- 📊 Usage
    `usedAt` DATETIME NULL COMMENT 'When token was used',
    `isUsed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Token has been used',
    `attemptCount` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of verification attempts',

    PRIMARY KEY (`tokenID`),
    UNIQUE KEY `uk_token` (`token`),
    INDEX `idx_token_hash` (`tokenHash`),
    INDEX `idx_user` (`userID`),
    INDEX `idx_email` (`email`),
    INDEX `idx_expires` (`expiresAt`),
    INDEX `idx_used` (`isUsed`),

    CONSTRAINT `fk_passwordless_token_user`
        FOREIGN KEY (`userID`)
        REFERENCES `tblUsers`(`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Passwordless login tokens';

-- ============================================================================
-- 🔐 WEBAUTHN CHALLENGES TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblWebAuthnChallenges` (
    `challengeID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` INT UNSIGNED NULL COMMENT 'User ID (null for registration)',
    `email` VARCHAR(255) NULL COMMENT 'Email for registration',

    -- 🎯 Challenge Data
    `challenge` VARCHAR(255) NOT NULL COMMENT 'Base64 encoded challenge',
    `challengeType` ENUM('registration', 'authentication') NOT NULL COMMENT 'Challenge type',

    -- 📊 Request Info
    `ipAddress` VARCHAR(45) NULL COMMENT 'Requesting IP address',
    `userAgent` TEXT NULL COMMENT 'Requesting user agent',

    -- ⏰ Expiration
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Challenge created',
    `expiresAt` DATETIME NOT NULL COMMENT 'Challenge expiration',
    `validityMinutes` INT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'Validity period in minutes',

    -- 📊 Usage
    `usedAt` DATETIME NULL COMMENT 'When challenge was used',
    `isUsed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Challenge has been used',

    PRIMARY KEY (`challengeID`),
    UNIQUE KEY `uk_challenge` (`challenge`),
    INDEX `idx_user` (`userID`),
    INDEX `idx_email` (`email`),
    INDEX `idx_expires` (`expiresAt`),
    INDEX `idx_used` (`isUsed`),

    CONSTRAINT `fk_webauthn_challenge_user`
        FOREIGN KEY (`userID`)
        REFERENCES `tblUsers`(`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='WebAuthn challenges for registration/authentication';

-- ============================================================================
-- 🔧 UPDATE EXISTING TABLES
-- ============================================================================

-- Add WebAuthn enabled flag to users table
SET @webauthn_enabled_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblUsers'
    AND COLUMN_NAME = 'webauthnEnabled'
);


-- ============================================================================
-- 📮 DELEGATE MAILBOX SUPPORT
-- ============================================================================

ALTER TABLE `tblEmailQueue`
ADD COLUMN `sendAsEmail` VARCHAR(255) NULL
COMMENT 'Delegate mailbox to send from (if different from fromEmail)'
AFTER `fromName`;
ALTER TABLE `tblEmailQueue`
ADD INDEX `idx_send_as` (`sendAsEmail`);
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


-- ============================================================================
-- 🎫 SUPPORT TICKET SYSTEM
-- ============================================================================

CREATE TABLE IF NOT EXISTS tblSupportTickets (
    ticketID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Ticket Identification
    ticketNumber VARCHAR(20) NOT NULL COMMENT 'Human-readable ticket number (e.g., SIGN-2024-00001)',

    -- User Information
    userID BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID (null for guest submissions)',
    email VARCHAR(255) NOT NULL COMMENT 'Contact email',
    name VARCHAR(100) NOT NULL COMMENT 'Contact name',

    -- Ticket Details
    subject VARCHAR(255) NOT NULL COMMENT 'Ticket subject/title',
    description TEXT NOT NULL COMMENT 'Detailed description of issue',
    category ENUM(
        'Technical Support',
        'Billing',
        'Account',
        'API Integration',
        'Security',
        'Feature Request',
        'Bug Report',
        'General Inquiry',
        'Other'
    ) NOT NULL DEFAULT 'General Inquiry' COMMENT 'Support category',

    priority ENUM('Low', 'Normal', 'High', 'Urgent') NOT NULL DEFAULT 'Normal' COMMENT 'Ticket priority',

    status ENUM(
        'New',
        'Open',
        'In Progress',
        'Waiting on Customer',
        'Waiting on Internal',
        'Resolved',
        'Closed',
        'Cancelled'
    ) NOT NULL DEFAULT 'New' COMMENT 'Ticket status',

    -- Assignment
    assignedTo BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID (assigned staff member)',
    assignedAt DATETIME DEFAULT NULL COMMENT 'Assignment timestamp',

    -- Product/Service Context
    relatedProduct VARCHAR(100) DEFAULT NULL COMMENT 'Related product or service',
    relatedURL VARCHAR(500) DEFAULT NULL COMMENT 'URL where issue occurred',
    browserInfo TEXT DEFAULT NULL COMMENT 'Browser/device information',
    errorMessage TEXT DEFAULT NULL COMMENT 'Any error messages',

    -- Resolution
    resolution TEXT DEFAULT NULL COMMENT 'Resolution details',
    resolvedBy BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID (staff who resolved)',
    resolvedAt DATETIME DEFAULT NULL COMMENT 'Resolution timestamp',
    closedAt DATETIME DEFAULT NULL COMMENT 'Closure timestamp',

    -- Satisfaction
    satisfactionRating TINYINT UNSIGNED DEFAULT NULL COMMENT 'Customer satisfaction (1-5 stars)',
    satisfactionComment TEXT DEFAULT NULL COMMENT 'Customer feedback',

    -- Metadata
    ipAddress VARCHAR(45) NOT NULL COMMENT 'Submitter IP address (IPv4/IPv6)',
    userAgent TEXT DEFAULT NULL COMMENT 'Browser user agent',
    referrer VARCHAR(500) DEFAULT NULL COMMENT 'Referrer URL',

    -- SLA Tracking
    firstResponseAt DATETIME DEFAULT NULL COMMENT 'Timestamp of first staff response',
    slaDeadline DATETIME DEFAULT NULL COMMENT 'SLA deadline based on priority',
    slaViolated BOOLEAN DEFAULT FALSE COMMENT 'Whether SLA was violated',

    -- Audit Fields
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Ticket creation timestamp',
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    deletedAt DATETIME DEFAULT NULL COMMENT 'Soft delete timestamp',
    isDeleted BOOLEAN DEFAULT FALSE COMMENT 'Soft delete flag',

    PRIMARY KEY (ticketID),
    UNIQUE KEY idx_ticket_number (ticketNumber),
    KEY idx_user (userID),
    KEY idx_email (email),
    KEY idx_status (status),
    KEY idx_priority (priority),
    KEY idx_category (category),
    KEY idx_assigned (assignedTo),
    KEY idx_created (createdAt),
    KEY idx_sla (slaDeadline, slaViolated),
    KEY idx_deleted (isDeleted, deletedAt),
    FULLTEXT KEY idx_search (subject, description),

    CONSTRAINT fk_tickets_user FOREIGN KEY (userID)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_tickets_assigned FOREIGN KEY (assignedTo)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_tickets_resolved FOREIGN KEY (resolvedBy)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customer support tickets';

-- ============================================
-- Table: tblSupportReplies
-- Purpose: Ticket replies/updates
-- ============================================
CREATE TABLE IF NOT EXISTS tblSupportReplies (
    replyID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticketID BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblSupportTickets.ticketID',

    -- Author Information
    userID BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID (null for system messages)',
    authorName VARCHAR(100) NOT NULL COMMENT 'Author name',
    authorEmail VARCHAR(255) DEFAULT NULL COMMENT 'Author email',
    isStaff BOOLEAN DEFAULT FALSE COMMENT 'Whether reply is from staff',
    isInternal BOOLEAN DEFAULT FALSE COMMENT 'Internal note (not visible to customer)',

    -- Reply Content
    message TEXT NOT NULL COMMENT 'Reply message',
    messageHTML TEXT DEFAULT NULL COMMENT 'HTML version of message',

    -- Attachments
    hasAttachments BOOLEAN DEFAULT FALSE COMMENT 'Whether reply has attachments',
    attachmentCount INT UNSIGNED DEFAULT 0 COMMENT 'Number of attachments',

    -- Status Changes
    statusChanged BOOLEAN DEFAULT FALSE COMMENT 'Whether this reply changed ticket status',
    oldStatus VARCHAR(50) DEFAULT NULL COMMENT 'Previous status if changed',
    newStatus VARCHAR(50) DEFAULT NULL COMMENT 'New status if changed',

    -- Metadata
    ipAddress VARCHAR(45) DEFAULT NULL COMMENT 'Author IP address',
    userAgent TEXT DEFAULT NULL COMMENT 'Browser user agent',

    -- Audit Fields
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Reply timestamp',
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deletedAt DATETIME DEFAULT NULL,
    isDeleted BOOLEAN DEFAULT FALSE,

    PRIMARY KEY (replyID),
    KEY idx_ticket (ticketID),
    KEY idx_user (userID),
    KEY idx_staff (isStaff),
    KEY idx_created (createdAt),
    KEY idx_deleted (isDeleted),

    CONSTRAINT fk_replies_ticket FOREIGN KEY (ticketID)
        REFERENCES tblSupportTickets(ticketID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_replies_user FOREIGN KEY (userID)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Support ticket replies';

-- ============================================
-- Table: tblSupportAttachments
-- Purpose: File attachments for tickets
-- ============================================
CREATE TABLE IF NOT EXISTS tblSupportAttachments (
    attachmentID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticketID BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblSupportTickets.ticketID',
    replyID BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblSupportReplies.replyID',

    -- File Information
    fileName VARCHAR(255) NOT NULL COMMENT 'Original filename',
    storedFileName VARCHAR(255) NOT NULL COMMENT 'Stored filename (hashed)',
    filePath VARCHAR(500) NOT NULL COMMENT 'Storage path',
    fileSize BIGINT UNSIGNED NOT NULL COMMENT 'File size in bytes',
    mimeType VARCHAR(100) NOT NULL COMMENT 'MIME type',

    -- Upload Info
    uploadedBy BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID',
    uploadedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Upload timestamp',

    -- Security
    scanStatus ENUM('Pending', 'Clean', 'Infected', 'Failed') DEFAULT 'Pending' COMMENT 'Virus scan status',
    scanDetails TEXT DEFAULT NULL COMMENT 'Scan details if infected',

    -- Audit Fields
    deletedAt DATETIME DEFAULT NULL,
    isDeleted BOOLEAN DEFAULT FALSE,

    PRIMARY KEY (attachmentID),
    KEY idx_ticket (ticketID),
    KEY idx_reply (replyID),
    KEY idx_uploaded_by (uploadedBy),
    KEY idx_deleted (isDeleted),

    CONSTRAINT fk_attachments_ticket FOREIGN KEY (ticketID)
        REFERENCES tblSupportTickets(ticketID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attachments_reply FOREIGN KEY (replyID)
        REFERENCES tblSupportReplies(replyID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attachments_user FOREIGN KEY (uploadedBy)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Support ticket attachments';

-- ============================================
-- Table: tblSupportCategories
-- Purpose: Support knowledge base categories
-- ============================================
CREATE TABLE IF NOT EXISTS tblSupportCategories (
    categoryID INT UNSIGNED NOT NULL AUTO_INCREMENT,
    categoryName VARCHAR(100) NOT NULL COMMENT 'Category name',
    categorySlug VARCHAR(100) NOT NULL COMMENT 'URL-friendly slug',
    description TEXT DEFAULT NULL COMMENT 'Category description',
    icon VARCHAR(50) DEFAULT NULL COMMENT 'Font Awesome icon class',
    displayOrder INT DEFAULT 0 COMMENT 'Sort order',
    isActive BOOLEAN DEFAULT TRUE COMMENT 'Active status',
    articleCount INT UNSIGNED DEFAULT 0 COMMENT 'Number of articles in category',

    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (categoryID),
    UNIQUE KEY idx_slug (categorySlug),
    KEY idx_active (isActive),
    KEY idx_order (displayOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Support knowledge base categories';

-- ============================================
-- Insert Default Support Categories
-- ============================================
INSERT INTO tblSupportCategories (categoryName, categorySlug, description, icon, displayOrder) VALUES
('Getting Started', 'getting-started', 'New to SIGNula? Start here!', 'fa-rocket', 1),
('Account & Billing', 'account-billing', 'Manage your account and billing', 'fa-user-circle', 2),
('API Integration', 'api-integration', 'Integrate SIGNula with your app', 'fa-code', 3),
('Security & Privacy', 'security-privacy', 'Security features and privacy settings', 'fa-shield-alt', 4),
('Troubleshooting', 'troubleshooting', 'Common issues and solutions', 'fa-wrench', 5),
('Mobile Apps', 'mobile-apps', 'iOS and Android app support', 'fa-mobile-alt', 6);

-- ============================================
-- Insert Support Settings
-- ============================================
INSERT INTO tblSettings (settingKey, settingValue, category, dataType, isEditable, isSensitive, description) VALUES
('support.enableGuestTickets', 'false', 'Support', 'boolean', TRUE, FALSE, 'Allow non-authenticated users to submit tickets'),
('support.requireEmailVerification', 'true', 'Support', 'boolean', TRUE, FALSE, 'Require email verification for guest tickets'),
('support.autoAssignTickets', 'true', 'Support', 'boolean', TRUE, FALSE, 'Automatically assign tickets to available staff'),
('support.slaHoursLow', '72', 'Support', 'integer', TRUE, FALSE, 'SLA hours for Low priority tickets'),
('support.slaHoursNormal', '48', 'Support', 'integer', TRUE, FALSE, 'SLA hours for Normal priority tickets'),
('support.slaHoursHigh', '24', 'Support', 'integer', TRUE, FALSE, 'SLA hours for High priority tickets'),
('support.slaHoursUrgent', '4', 'Support', 'integer', TRUE, FALSE, 'SLA hours for Urgent priority tickets'),
('support.maxAttachmentSize', '10485760', 'Support', 'integer', TRUE, FALSE, 'Max attachment size in bytes (10MB default)'),
('support.allowedAttachmentTypes', 'jpg,jpeg,png,gif,pdf,doc,docx,txt,zip', 'Support', 'string', TRUE, FALSE, 'Allowed file extensions'),
('support.notifyStaffEmail', 'support@signula.com', 'Support', 'string', TRUE, FALSE, 'Email to notify for new tickets'),
('support.autoCloseAfterDays', '30', 'Support', 'integer', TRUE, FALSE, 'Auto-close resolved tickets after N days')
ON DUPLICATE KEY UPDATE updatedAt = CURRENT_TIMESTAMP;

-- ============================================
-- Stored Procedure: Generate Ticket Number
-- ============================================
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_GenerateTicketNumber(OUT newTicketNumber VARCHAR(20))
BEGIN
    DECLARE nextNumber INT;
    DECLARE yearPart VARCHAR(4);

    SET yearPart = YEAR(NOW());

    -- Get the next ticket number for current year
    SELECT COALESCE(MAX(CAST(SUBSTRING(ticketNumber, -5) AS UNSIGNED)), 0) + 1
    INTO nextNumber
    FROM tblSupportTickets
    WHERE ticketNumber LIKE CONCAT('SIGN-', yearPart, '-%');

    -- Format: SIGN-2026-00001
    SET newTicketNumber = CONCAT('SIGN-', yearPart, '-', LPAD(nextNumber, 5, '0'));
END //

DELIMITER ;

-- ============================================
-- Trigger: Auto-generate ticket number
-- ============================================
DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_tickets_before_insert
BEFORE INSERT ON tblSupportTickets
FOR EACH ROW
BEGIN
    IF NEW.ticketNumber IS NULL OR NEW.ticketNumber = '' THEN
        CALL sp_GenerateTicketNumber(@ticketNum);
        SET NEW.ticketNumber = @ticketNum;
    END IF;

    -- Set SLA deadline based on priority
    IF NEW.slaDeadline IS NULL THEN
        SET NEW.slaDeadline = CASE NEW.priority
            WHEN 'Urgent' THEN DATE_ADD(NOW(), INTERVAL 4 HOUR)
            WHEN 'High' THEN DATE_ADD(NOW(), INTERVAL 24 HOUR)
            WHEN 'Normal' THEN DATE_ADD(NOW(), INTERVAL 48 HOUR)
            WHEN 'Low' THEN DATE_ADD(NOW(), INTERVAL 72 HOUR)
            ELSE DATE_ADD(NOW(), INTERVAL 48 HOUR)
        END;
    END IF;
END //

DELIMITER ;

-- ============================================
-- View: Open Tickets Summary
-- ============================================
CREATE OR REPLACE VIEW vw_OpenTickets AS
SELECT
    t.ticketID,
    t.ticketNumber,
    t.subject,
    t.category,
    t.priority,
    t.status,
    t.userID,
    t.email,
    t.name,
    t.assignedTo,
    u.displayName as assignedToName,
    t.createdAt,
    t.slaDeadline,
    t.slaViolated,
    TIMESTAMPDIFF(HOUR, t.createdAt, NOW()) as ageInHours,
    (SELECT COUNT(*) FROM tblSupportReplies WHERE ticketID = t.ticketID AND isDeleted = FALSE) as replyCount
FROM tblSupportTickets t
LEFT JOIN tblUsers u ON t.assignedTo = u.userID
WHERE t.status NOT IN ('Resolved', 'Closed', 'Cancelled')
AND t.isDeleted = FALSE
ORDER BY
    FIELD(t.priority, 'Urgent', 'High', 'Normal', 'Low'),
    t.createdAt ASC;

-- ============================================
-- Migration Complete
-- ============================================


-- ============================================================================
-- ⏱️  RATE LIMITING SYSTEM
-- ============================================================================

CREATE TABLE IF NOT EXISTS tblRateLimits (
    limitID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL COMMENT 'IP address, userID, or API key hash',
    identifierType ENUM('ip', 'user', 'api_key') NOT NULL,
    endpoint VARCHAR(255) NOT NULL COMMENT 'API endpoint or "global"',
    requestCount INT UNSIGNED NOT NULL DEFAULT 0,
    windowStart DATETIME NOT NULL,
    windowEnd DATETIME NOT NULL,
    isBlocked BOOLEAN DEFAULT 0,
    blockedUntil DATETIME NULL,
    blockReason VARCHAR(255) NULL,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_limit (identifier, identifierType, endpoint, windowStart),
    INDEX idx_window (windowEnd),
    INDEX idx_blocked (isBlocked, blockedUntil),
    INDEX idx_identifier (identifier, identifierType)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks rate limit usage and violations';

-- =====================================================
-- 2. Rate Limit Configuration Table
-- =====================================================

CREATE TABLE IF NOT EXISTS tblRateLimitConfig (
    configID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    identifierType ENUM('ip', 'user', 'api_key') NOT NULL,
    endpoint VARCHAR(255) NOT NULL COMMENT '"global" or specific endpoint like /api/v1/auth/login',
    tier VARCHAR(50) DEFAULT 'default' COMMENT 'Rate limit tier: default, free, basic, premium, enterprise',

    -- Rate limits
    requestsPerHour INT UNSIGNED NOT NULL,
    requestsPerMinute INT UNSIGNED NOT NULL,

    -- Burst protection
    burstLimit INT UNSIGNED NOT NULL COMMENT 'Max requests in burst window',
    burstWindow INT UNSIGNED NOT NULL COMMENT 'Burst window in seconds',

    -- Status
    isActive BOOLEAN DEFAULT 1,
    description TEXT NULL,

    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_config (identifierType, endpoint, tier),
    INDEX idx_active (isActive),
    INDEX idx_tier (tier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Rate limit configuration for different tiers';

-- =====================================================
-- 3. Insert Default Rate Limit Configurations
-- =====================================================

-- IP-based limits (unauthenticated requests)
INSERT INTO tblRateLimitConfig (identifierType, endpoint, tier, requestsPerHour, requestsPerMinute, burstLimit, burstWindow, description) VALUES
INSERT INTO tblRateLimitConfig (identifierType, endpoint, tier, requestsPerHour, requestsPerMinute, burstLimit, burstWindow, description) VALUES
('ip', 'global', 'default', 100, 10, 20, 10, 'Default rate limit for unauthenticated requests'),
('ip', '/api/v1/auth/login', 'default', 20, 5, 10, 60, 'Login endpoint - stricter to prevent brute force'),
('ip', '/api/v1/auth/register', 'default', 10, 2, 5, 60, 'Registration endpoint - prevent spam'),
('ip', '/api/v1/auth/forgot-password', 'default', 5, 1, 3, 300, 'Password reset - very strict to prevent abuse'),
('ip', '/api/v1/auth/reset-password', 'default', 10, 2, 5, 60, 'Password reset confirmation');

-- User-based limits (authenticated users)
INSERT INTO tblRateLimitConfig (identifierType, endpoint, tier, requestsPerHour, requestsPerMinute, burstLimit, burstWindow, description) VALUES
INSERT INTO tblRateLimitConfig (identifierType, endpoint, tier, requestsPerHour, requestsPerMinute, burstLimit, burstWindow, description) VALUES
('user', 'global', 'free', 500, 50, 30, 10, 'Free tier user rate limit'),
('user', 'global', 'basic', 1000, 100, 50, 10, 'Basic tier user rate limit'),
('user', 'global', 'premium', 5000, 500, 100, 10, 'Premium tier user rate limit'),
('user', 'global', 'enterprise', 50000, 5000, 500, 10, 'Enterprise tier user rate limit');

-- API Key-based limits (partner integrations)
INSERT INTO tblRateLimitConfig (identifierType, endpoint, tier, requestsPerHour, requestsPerMinute, burstLimit, burstWindow, description) VALUES
INSERT INTO tblRateLimitConfig (identifierType, endpoint, tier, requestsPerHour, requestsPerMinute, burstLimit, burstWindow, description) VALUES
('api_key', 'global', 'free', 1000, 100, 50, 10, 'Free tier API keys'),
('api_key', 'global', 'basic', 10000, 500, 200, 10, 'Basic tier API keys'),
('api_key', 'global', 'premium', 50000, 2000, 500, 10, 'Premium tier API keys'),
('api_key', 'global', 'enterprise', 100000, 5000, 1000, 10, 'Enterprise tier API keys - highest limits');

-- =====================================================
-- 4. Settings for Rate Limiting
-- =====================================================

-- Insert rate limiting settings if they do not exist
INSERT IGNORE INTO tblSettings (settingKey, settingValue, settingType, isSensitive, category, description) VALUES
('rate_limiting.enabled', '1', 'boolean', 0, 'security', 'Enable/disable rate limiting globally'),
('rate_limiting.cleanup_interval_hours', '24', 'integer', 0, 'security', 'How often to clean up old rate limit records (hours)'),
('rate_limiting.progressive_blocking', '1', 'boolean', 0, 'security', 'Enable progressive blocking (increasing ban duration on repeated violations)'),
('rate_limiting.max_block_duration_hours', '24', 'integer', 0, 'security', 'Maximum block duration in hours'),
('rate_limiting.notify_on_block', '1', 'boolean', 0, 'security', 'Send notification when IP/user is blocked for rate limiting');

-- =====================================================
-- 5. Scheduled Event for Cleanup
-- =====================================================

-- Drop event if it exists
DROP EVENT IF EXISTS cleanup_rate_limits;

-- Create event to clean up old rate limit records
CREATE EVENT IF NOT EXISTS cleanup_rate_limits
ON SCHEDULE EVERY 1 HOUR
COMMENT 'Clean up rate limit records older than 24 hours'
DO
DELETE FROM tblRateLimits
WHERE windowEnd < DATE_SUB(NOW(), INTERVAL 24 HOUR)
AND isBlocked = 0;

-- Also clean up old blocks that have expired
DELETE FROM tblRateLimits
WHERE isBlocked = 1
AND blockedUntil < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- =====================================================
-- 6. Record Migration
-- =====================================================

INSERT INTO tblMigrations (migrationFile, migrationDescription, appliedAt) VALUES
INSERT INTO tblMigrations (migrationFile, migrationDescription, appliedAt) VALUES
('007_rate_limiting.sql', 'Rate limiting system for API abuse prevention', NOW());

-- =====================================================
-- 7. Verification Queries
-- =====================================================

-- Verify tables created
SELECT 'Rate limit tables created successfully' as Status,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'signula' AND table_name = 'tblRateLimits') as tblRateLimits_exists,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'signula' AND table_name = 'tblRateLimitConfig') as tblRateLimitConfig_exists;

-- Verify default configurations inserted
SELECT 'Default rate limit configs' as Status, COUNT(*) as ConfigCount FROM tblRateLimitConfig;

-- Verify settings added
SELECT 'Rate limiting settings' as Status, COUNT(*) as SettingsCount FROM tblSettings WHERE settingKey LIKE 'rate_limiting.%';

-- =====================================================
-- Migration Complete
-- =====================================================

SELECT '✅ Migration 007_rate_limiting.sql completed successfully' as Result;


-- ============================================================================
-- 🔐 PARTNER API KEY MANAGEMENT
-- ============================================================================

CREATE TABLE IF NOT EXISTS tblPartners (
    partnerID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    -- Organization details
    partnerName VARCHAR(255) NOT NULL,
    partnerEmail VARCHAR(255) NOT NULL,
    contactName VARCHAR(255) NULL,
    contactPhone VARCHAR(50) NULL,
    companyWebsite VARCHAR(255) NULL,
    companyAddress TEXT NULL,

    -- Account tier
    accountTier ENUM('free', 'basic', 'premium', 'enterprise') DEFAULT 'free',

    -- Status
    isActive BOOLEAN DEFAULT 1,
    isVerified BOOLEAN DEFAULT 0,
    verifiedAt DATETIME NULL,

    -- Rate limiting
    customRateLimit INT UNSIGNED NULL COMMENT 'Custom rate limit override (requests per hour)',

    -- Security
    ipWhitelist TEXT NULL COMMENT 'JSON array of allowed IP addresses',
    allowedDomains TEXT NULL COMMENT 'JSON array of allowed domains for webhooks/callbacks',

    -- Webhooks
    webhookURL VARCHAR(500) NULL COMMENT 'URL for webhook notifications',
    webhookSecret VARCHAR(255) NULL COMMENT 'Secret for webhook signature verification',

    -- Billing (for future use)
    billingEmail VARCHAR(255) NULL,
    paymentStatus ENUM('active', 'past_due', 'cancelled', 'trial') DEFAULT 'trial',
    subscriptionStartDate DATE NULL,
    subscriptionEndDate DATE NULL,

    -- Metadata
    metadata JSON NULL COMMENT 'Additional partner metadata',

    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_email (partnerEmail),
    INDEX idx_active (isActive),
    INDEX idx_tier (accountTier),
    INDEX idx_verified (isVerified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner organizations using SIGNula API';

-- =====================================================
-- 2. API Keys Table
-- =====================================================

CREATE TABLE IF NOT EXISTS tblAPIKeys (
    keyID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    partnerID INT UNSIGNED NOT NULL,

    -- Key identification
    keyName VARCHAR(100) NOT NULL COMMENT 'Human-readable name (e.g., "Production Key", "Development Key")',
    keyHash VARCHAR(255) NOT NULL COMMENT 'SHA-256 hash of the full API key',
    keyPrefix VARCHAR(20) NOT NULL COMMENT 'Visible prefix (e.g., sk_live_abc12345, sk_test_def67890)',

    -- Environment
    environment ENUM('test', 'live') DEFAULT 'test',

    -- Permissions & Scopes
    permissions JSON NULL COMMENT 'Array of allowed endpoints/scopes (e.g., ["users:read", "users:write"])',
    allowedEndpoints JSON NULL COMMENT 'Specific endpoints this key can access',

    -- Security
    ipWhitelist TEXT NULL COMMENT 'JSON array of allowed IPs for this specific key',
    requiresIPWhitelist BOOLEAN DEFAULT 0 COMMENT 'If true, must match IP whitelist',

    -- Usage tracking
    lastUsedAt DATETIME NULL,
    usageCount BIGINT UNSIGNED DEFAULT 0,
    lastUsedIP VARCHAR(45) NULL,
    lastUsedUserAgent TEXT NULL,

    -- Rate limiting
    customRateLimit INT UNSIGNED NULL COMMENT 'Custom rate limit for this specific key',

    -- Expiration
    expiresAt DATETIME NULL COMMENT 'Optional expiration date',

    -- Status
    isActive BOOLEAN DEFAULT 1,
    revokedAt DATETIME NULL,
    revokedBy INT UNSIGNED NULL COMMENT 'UserID who revoked the key',
    revokedReason VARCHAR(255) NULL,

    -- Metadata
    notes TEXT NULL COMMENT 'Internal notes about this key',
    metadata JSON NULL COMMENT 'Additional key metadata',

    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (partnerID) REFERENCES tblPartners(partnerID) ON DELETE CASCADE,
    UNIQUE KEY idx_hash (keyHash),
    INDEX idx_prefix (keyPrefix),
    INDEX idx_partner (partnerID),
    INDEX idx_active (isActive, expiresAt),
    INDEX idx_last_used (lastUsedAt),
    INDEX idx_environment (environment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner API keys for authentication';

-- =====================================================
-- 3. API Key Usage Logs
-- =====================================================

CREATE TABLE IF NOT EXISTS tblAPIKeyUsage (
    usageID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    keyID INT UNSIGNED NOT NULL,

    -- Request details
    endpoint VARCHAR(255) NOT NULL,
    httpMethod VARCHAR(10) NOT NULL,
    statusCode INT UNSIGNED NULL,
    responseTime INT UNSIGNED NULL COMMENT 'Response time in milliseconds',

    -- Request metadata
    ipAddress VARCHAR(45) NULL,
    userAgent TEXT NULL,
    requestSize INT UNSIGNED NULL COMMENT 'Request size in bytes',
    responseSize INT UNSIGNED NULL COMMENT 'Response size in bytes',

    -- Errors
    errorMessage TEXT NULL,
    errorCode VARCHAR(50) NULL,

    -- Rate limiting
    rateLimitHit BOOLEAN DEFAULT 0 COMMENT 'Was this request rate limited?',

    -- Timestamp
    requestedAt DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (keyID) REFERENCES tblAPIKeys(keyID) ON DELETE CASCADE,
    INDEX idx_key_time (keyID, requestedAt),
    INDEX idx_endpoint (endpoint),
    INDEX idx_timestamp (requestedAt),
    INDEX idx_status (statusCode),
    INDEX idx_errors (errorCode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='API key usage logs for analytics and debugging';

-- =====================================================
-- 4. API Key Audit Log
-- =====================================================

CREATE TABLE IF NOT EXISTS tblAPIKeyAudit (
    auditID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    keyID INT UNSIGNED NULL,
    partnerID INT UNSIGNED NULL,

    -- Action details
    action ENUM('key_generated', 'key_revoked', 'key_regenerated', 'key_updated', 'permissions_changed', 'whitelist_updated') NOT NULL,
    actionDetails TEXT NULL COMMENT 'JSON with action details',

    -- Who performed the action
    performedBy INT UNSIGNED NULL COMMENT 'UserID who performed the action',
    performedByIP VARCHAR(45) NULL,

    performedAt DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (keyID) REFERENCES tblAPIKeys(keyID) ON DELETE SET NULL,
    FOREIGN KEY (partnerID) REFERENCES tblPartners(partnerID) ON DELETE CASCADE,
    INDEX idx_key (keyID),
    INDEX idx_partner (partnerID),
    INDEX idx_action (action),
    INDEX idx_timestamp (performedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit trail for API key management actions';

-- =====================================================
-- 5. Settings for API Key Management
-- =====================================================

INSERT IGNORE INTO tblSettings (settingKey, settingValue, settingType, isSensitive, category, description) VALUES
('api_keys.enabled', '1', 'boolean', 0, 'api', 'Enable/disable API key authentication'),
('api_keys.default_tier', 'free', 'string', 0, 'api', 'Default tier for new partners'),
('api_keys.key_prefix_live', 'sk_live_', 'string', 0, 'api', 'Prefix for live API keys'),
('api_keys.key_prefix_test', 'sk_test_', 'string', 0, 'api', 'Prefix for test API keys'),
('api_keys.max_keys_per_partner', '10', 'integer', 0, 'api', 'Maximum number of API keys per partner'),
('api_keys.require_ip_whitelist', '0', 'boolean', 0, 'api', 'Require IP whitelisting for all API keys'),
('api_keys.log_usage', '1', 'boolean', 0, 'api', 'Log all API key usage'),
('api_keys.usage_log_retention_days', '90', 'integer', 0, 'api', 'How long to keep usage logs (days)'),
('api_keys.notify_on_revocation', '1', 'boolean', 0, 'api', 'Email partner when their API key is revoked'),
('api_keys.auto_rotate_enabled', '0', 'boolean', 0, 'api', 'Enable automatic key rotation'),
('api_keys.auto_rotate_days', '365', 'integer', 0, 'api', 'Days before automatic key rotation');

-- =====================================================
-- 6. Scheduled Events
-- =====================================================

-- Cleanup old usage logs (keep 90 days by default)
DROP EVENT IF EXISTS cleanup_api_key_usage;

CREATE EVENT IF NOT EXISTS cleanup_api_key_usage
ON SCHEDULE EVERY 1 DAY
COMMENT 'Clean up old API key usage logs'
DO
DELETE FROM tblAPIKeyUsage
WHERE requestedAt < DATE_SUB(NOW(), INTERVAL (
    SELECT settingValue FROM tblSettings WHERE settingKey = 'api_keys.usage_log_retention_days'
) DAY);

-- Auto-expire API keys
DROP EVENT IF EXISTS expire_api_keys;

CREATE EVENT IF NOT EXISTS expire_api_keys
ON SCHEDULE EVERY 1 HOUR
COMMENT 'Automatically expire API keys that have passed their expiration date'
DO
UPDATE tblAPIKeys
SET isActive = 0,
    revokedAt = NOW(),
    revokedReason = 'Automatically expired'
WHERE expiresAt IS NOT NULL
AND expiresAt < NOW()
AND isActive = 1;

-- =====================================================
-- 7. Sample Test Partner (Development Only)
-- =====================================================

-- Insert a test partner for development (REMOVE IN PRODUCTION)
INSERT INTO tblPartners (partnerName, partnerEmail, contactName, accountTier, isActive, isVerified, verifiedAt)
VALUES ('Test Partner (Development)', 'dev@example.com', 'Developer', 'premium', 1, 1, NOW())
ON DUPLICATE KEY UPDATE partnerName = partnerName;

-- =====================================================
-- 8. Record Migration
-- =====================================================

INSERT INTO tblMigrations (migrationFile, migrationDescription, appliedAt) VALUES
('008_partner_api_keys.sql', 'Partner organization and API key management system', NOW());

-- =====================================================
-- 9. Verification Queries
-- =====================================================

-- Verify tables created
SELECT 'API key tables created successfully' as Status,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'signula' AND table_name = 'tblPartners') as tblPartners_exists,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'signula' AND table_name = 'tblAPIKeys') as tblAPIKeys_exists,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'signula' AND table_name = 'tblAPIKeyUsage') as tblAPIKeyUsage_exists,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'signula' AND table_name = 'tblAPIKeyAudit') as tblAPIKeyAudit_exists;

-- Verify settings added
SELECT 'API key settings' as Status, COUNT(*) as SettingsCount FROM tblSettings WHERE settingKey LIKE 'api_keys.%';

-- Show test partner
SELECT 'Test partner created' as Status, partnerID, partnerName, partnerEmail, accountTier
FROM tblPartners
WHERE partnerEmail = 'dev@example.com';

-- =====================================================
-- Migration Complete
-- =====================================================

SELECT '✅ Migration 008_partner_api_keys.sql completed successfully' as Result;


-- ============================================================================
-- 🏢 MULTI-TIER ADMIN SYSTEM
-- ============================================================================

ALTER TABLE tblUsers
ADD COLUMN `isSuperAdmin` BOOLEAN NOT NULL DEFAULT FALSE
    COMMENT 'Super admin flag for SIGNula system owners (global control)'
    AFTER isAdmin;
CREATE INDEX idx_super_admin ON tblUsers(isSuperAdmin);
CREATE TABLE IF NOT EXISTS `tblPartnerTeamMembers` (
    `memberID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `partnerID` BIGINT UNSIGNED NOT NULL,
    `userID` BIGINT UNSIGNED NOT NULL,
    `role` ENUM('root-admin', 'admin', 'developer', 'support', 'finance') NOT NULL DEFAULT 'developer'
        COMMENT 'Team member role within organization',
    `isRootAdmin` BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Root admin flag - only ONE per partner (organization owner)',
    `invitedBy` BIGINT UNSIGNED NULL
        COMMENT 'User ID who invited this team member',
    `invitedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `joinedAt` DATETIME NULL
        COMMENT 'When member accepted invitation',
    `status` ENUM('pending', 'active', 'suspended', 'removed') NOT NULL DEFAULT 'active',
    `permissions` JSON NULL
        COMMENT 'Additional granular permissions (future use)',
    `notes` TEXT NULL,
    `createdAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Keys
    CONSTRAINT fk_team_partner FOREIGN KEY (partnerID) REFERENCES tblPartners(partnerID) ON DELETE CASCADE,
    CONSTRAINT fk_team_user FOREIGN KEY (userID) REFERENCES tblUsers(userID) ON DELETE CASCADE,
    CONSTRAINT fk_team_invited_by FOREIGN KEY (invitedBy) REFERENCES tblUsers(userID) ON DELETE SET NULL,

    -- Unique constraint: user can only have one role per partner
    UNIQUE KEY uk_partner_user (partnerID, userID),

    -- Indexes
    INDEX idx_partner (partnerID),
    INDEX idx_user (userID),
    INDEX idx_role (role),
    INDEX idx_status (status),
    INDEX idx_root_admin (isRootAdmin)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner organization team members with roles';

-- ============================================================================
-- 3. FEATURE TOGGLES TABLE (GLOBAL)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblFeatureToggles` (
    `featureID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `featureKey` VARCHAR(100) NOT NULL UNIQUE
        COMMENT 'Unique feature identifier (e.g., rate_limiting, api_keys)',
    `featureName` VARCHAR(255) NOT NULL
        COMMENT 'Human-readable feature name',
    `featureDescription` TEXT NULL
        COMMENT 'Description of what this feature does',
    `isEnabledGlobally` BOOLEAN NOT NULL DEFAULT TRUE
        COMMENT 'Global enable/disable for ALL partners',
    `category` VARCHAR(50) NULL
        COMMENT 'Feature category (security, analytics, billing, etc.)',
    `requiresMigration` VARCHAR(50) NULL
        COMMENT 'Migration number required (e.g., 007, 008)',
    `affectsAPI` BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Whether this feature affects API functionality',
    `canPartnersToggle` BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Whether partners can toggle this for themselves',
    `modifiedBy` BIGINT UNSIGNED NULL
        COMMENT 'Last super admin who modified this',
    `createdAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Key
    CONSTRAINT fk_feature_modified_by FOREIGN KEY (modifiedBy) REFERENCES tblUsers(userID) ON DELETE SET NULL,

    -- Indexes
    INDEX idx_enabled (isEnabledGlobally),
    INDEX idx_category (category),
    INDEX idx_can_toggle (canPartnersToggle)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Global feature toggle system for SIGNula';

-- ============================================================================
-- 4. PARTNER-SPECIFIC FEATURE OVERRIDES
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblPartnerFeatures` (
    `partnerFeatureID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `partnerID` BIGINT UNSIGNED NOT NULL,
    `featureID` BIGINT UNSIGNED NOT NULL,
    `isEnabled` BOOLEAN NOT NULL DEFAULT TRUE
        COMMENT 'Partner-specific override of global setting',
    `enabledBy` BIGINT UNSIGNED NULL
        COMMENT 'User who enabled/disabled (partner admin or super admin)',
    `reason` TEXT NULL
        COMMENT 'Reason for enabling/disabling',
    `expiresAt` DATETIME NULL
        COMMENT 'Optional expiration for temporary feature access',
    `createdAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Keys
    CONSTRAINT fk_pf_partner FOREIGN KEY (partnerID) REFERENCES tblPartners(partnerID) ON DELETE CASCADE,
    CONSTRAINT fk_pf_feature FOREIGN KEY (featureID) REFERENCES tblFeatureToggles(featureID) ON DELETE CASCADE,
    CONSTRAINT fk_pf_enabled_by FOREIGN KEY (enabledBy) REFERENCES tblUsers(userID) ON DELETE SET NULL,

    -- Unique constraint: one override per partner per feature
    UNIQUE KEY uk_partner_feature (partnerID, featureID),

    -- Indexes
    INDEX idx_partner (partnerID),
    INDEX idx_feature (featureID),
    INDEX idx_enabled (isEnabled),
    INDEX idx_expires (expiresAt)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner-specific feature toggle overrides';

-- ============================================================================
-- 5. TEAM MEMBER INVITATIONS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblTeamInvitations` (
    `invitationID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `partnerID` BIGINT UNSIGNED NOT NULL,
    `email` VARCHAR(255) NOT NULL
        COMMENT 'Email address of invitee',
    `role` ENUM('admin', 'developer', 'support', 'finance') NOT NULL DEFAULT 'developer'
        COMMENT 'Role to assign when invitation is accepted',
    `invitedBy` BIGINT UNSIGNED NOT NULL
        COMMENT 'Partner admin who sent invitation',
    `invitationToken` VARCHAR(64) NOT NULL UNIQUE
        COMMENT 'Secure token for accepting invitation',
    `status` ENUM('pending', 'accepted', 'expired', 'revoked') NOT NULL DEFAULT 'pending',
    `expiresAt` DATETIME NOT NULL
        COMMENT 'Invitation expiration (default 7 days)',
    `acceptedAt` DATETIME NULL,
    `acceptedBy` BIGINT UNSIGNED NULL
        COMMENT 'User ID who accepted (links to tblUsers)',
    `createdAt` DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Foreign Keys
    CONSTRAINT fk_inv_partner FOREIGN KEY (partnerID) REFERENCES tblPartners(partnerID) ON DELETE CASCADE,
    CONSTRAINT fk_inv_invited_by FOREIGN KEY (invitedBy) REFERENCES tblUsers(userID) ON DELETE CASCADE,
    CONSTRAINT fk_inv_accepted_by FOREIGN KEY (acceptedBy) REFERENCES tblUsers(userID) ON DELETE SET NULL,

    -- Indexes
    INDEX idx_partner (partnerID),
    INDEX idx_email (email),
    INDEX idx_token (invitationToken),
    INDEX idx_status (status),
    INDEX idx_expires (expiresAt)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Team member invitation system';

-- ============================================================================
-- 6. ADMIN ACTION AUDIT LOG
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblAdminAuditLog` (
    `auditID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `userID` BIGINT UNSIGNED NOT NULL
        COMMENT 'Admin user who performed action',
    `adminLevel` ENUM('super-admin', 'partner-admin') NOT NULL
        COMMENT 'Level of admin performing action',
    `action` VARCHAR(100) NOT NULL
        COMMENT 'Action performed (e.g., toggle_feature, invite_member)',
    `targetType` VARCHAR(50) NULL
        COMMENT 'Type of target (partner, user, feature, etc.)',
    `targetID` BIGINT UNSIGNED NULL
        COMMENT 'ID of affected entity',
    `changes` JSON NULL
        COMMENT 'Details of changes made',
    `ipAddress` VARCHAR(45) NULL,
    `userAgent` TEXT NULL,
    `createdAt` DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Foreign Key
    CONSTRAINT fk_audit_user FOREIGN KEY (userID) REFERENCES tblUsers(userID) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_user (userID),
    INDEX idx_action (action),
    INDEX idx_created (createdAt),
    INDEX idx_target (targetType, targetID)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit log for all admin actions';

-- ============================================================================
-- 7. INSERT DEFAULT FEATURES
-- ============================================================================

INSERT INTO tblFeatureToggles (featureKey, featureName, featureDescription, isEnabledGlobally, category, requiresMigration, affectsAPI, canPartnersToggle) VALUES
INSERT INTO tblFeatureToggles (featureKey, featureName, featureDescription, isEnabledGlobally, category, requiresMigration, affectsAPI, canPartnersToggle) VALUES
-- Security Features
-- Security Features
('rate_limiting', 'Rate Limiting', 'API rate limiting and protection against abuse', TRUE, 'security', '007', TRUE, FALSE),
('rate_limiting', 'Rate Limiting', 'API rate limiting and protection against abuse', TRUE, 'security', '007', TRUE, FALSE),
('api_keys', 'API Key Management', 'Partner API key generation and management', TRUE, 'security', '008', TRUE, FALSE),
('api_keys', 'API Key Management', 'Partner API key generation and management', TRUE, 'security', '008', TRUE, FALSE),
('ip_whitelisting', 'IP Whitelisting', 'Restrict API access to specific IP addresses', TRUE, 'security', '008', TRUE, TRUE),
('ip_whitelisting', 'IP Whitelisting', 'Restrict API access to specific IP addresses', TRUE, 'security', '008', TRUE, TRUE),
('progressive_blocking', 'Progressive Blocking', 'Automatic escalating blocks for violations', TRUE, 'security', '007', TRUE, FALSE),
('progressive_blocking', 'Progressive Blocking', 'Automatic escalating blocks for violations', TRUE, 'security', '007', TRUE, FALSE),


-- Authentication Features
-- Authentication Features
('oauth_integration', 'OAuth Integration', 'Third-party OAuth provider support', TRUE, 'authentication', '003', FALSE, FALSE),
('oauth_integration', 'OAuth Integration', 'Third-party OAuth provider support', TRUE, 'authentication', '003', FALSE, FALSE),
('webauthn', 'WebAuthn/PassKeys', 'Passwordless authentication with FIDO2', TRUE, 'authentication', '005', FALSE, FALSE),
('webauthn', 'WebAuthn/PassKeys', 'Passwordless authentication with FIDO2', TRUE, 'authentication', '005', FALSE, FALSE),
('mfa', 'Multi-Factor Authentication', 'TOTP and email-based MFA', TRUE, 'authentication', NULL, FALSE, FALSE),
('mfa', 'Multi-Factor Authentication', 'TOTP and email-based MFA', TRUE, 'authentication', NULL, FALSE, FALSE),


-- Email Features
-- Email Features
('email_system', 'Email System', 'Transactional email with templates and tracking', TRUE, 'email', '001', FALSE, TRUE),
('email_system', 'Email System', 'Transactional email with templates and tracking', TRUE, 'email', '001', FALSE, TRUE),
('email_campaigns', 'Email Campaigns', 'Marketing campaigns and drip sequences', TRUE, 'email', '003', FALSE, TRUE),
('email_campaigns', 'Email Campaigns', 'Marketing campaigns and drip sequences', TRUE, 'email', '003', FALSE, TRUE),
('delegate_mailbox', 'Delegate Mailbox', 'Send emails via OAuth shared mailboxes', TRUE, 'email', '006', FALSE, TRUE),
('delegate_mailbox', 'Delegate Mailbox', 'Send emails via OAuth shared mailboxes', TRUE, 'email', '006', FALSE, TRUE),


-- Analytics & Reporting
-- Analytics & Reporting
('usage_analytics', 'Usage Analytics', 'Detailed API usage statistics and reporting', TRUE, 'analytics', NULL, FALSE, TRUE),
('usage_analytics', 'Usage Analytics', 'Detailed API usage statistics and reporting', TRUE, 'analytics', NULL, FALSE, TRUE),
('audit_logs', 'Audit Logs', 'Complete audit trail of all actions', TRUE, 'analytics', NULL, FALSE, FALSE),
('audit_logs', 'Audit Logs', 'Complete audit trail of all actions', TRUE, 'analytics', NULL, FALSE, FALSE),


-- Content Features
-- Content Features
('blog_system', 'Blog System', 'News and blog post management', TRUE, 'content', '005', FALSE, TRUE),
('blog_system', 'Blog System', 'News and blog post management', TRUE, 'content', '005', FALSE, TRUE),
('support_system', 'Support System', 'Ticket-based customer support', TRUE, 'content', '006', FALSE, TRUE);
('support_system', 'Support System', 'Ticket-based customer support', TRUE, 'content', '006', FALSE, TRUE);

-- ============================================================================
-- 8. UPDATE EXISTING PARTNERS TO HAVE ROOT ADMIN
-- ============================================================================

-- For each existing partner, create a root admin entry
-- This assumes the partner email matches a user in tblUsers
INSERT INTO tblPartnerTeamMembers (partnerID, userID, role, isRootAdmin, status)
INSERT INTO tblPartnerTeamMembers (partnerID, userID, role, isRootAdmin, status)
SELECT p.partnerID, u.userID, 'root-admin', TRUE, 'active'
SELECT p.partnerID, u.userID, 'root-admin', TRUE, 'active'
FROM tblPartners p
FROM tblPartners p
JOIN tblUsers u ON p.email = u.email
JOIN tblUsers u ON p.email = u.email
WHERE NOT EXISTS (
WHERE NOT EXISTS (
    SELECT 1 FROM tblPartnerTeamMembers tm
    SELECT 1 FROM tblPartnerTeamMembers tm
    WHERE tm.partnerID = p.partnerID AND tm.isRootAdmin = TRUE
    WHERE tm.partnerID = p.partnerID AND tm.isRootAdmin = TRUE
);
);
INSERT INTO tblSettings (settingCategory, settingKey, settingValue, dataType, isPublic, isSensitive, description, modifiedBy)
VALUES
('partners', 'enable_public_directory', '0', 'boolean', 0, 0, 'Enable public partner directory on website', NULL),
('partners', 'directory_approval_required', '1', 'boolean', 0, 0, 'Require partners to approve before appearing in directory', NULL),
('partners', 'max_team_members_free', '5', 'integer', 0, 0, 'Maximum team members for free tier partners', NULL),
('partners', 'max_team_members_basic', '10', 'integer', 0, 0, 'Maximum team members for basic tier partners', NULL),
('partners', 'max_team_members_premium', '25', 'integer', 0, 0, 'Maximum team members for premium tier partners', NULL),
('partners', 'max_team_members_enterprise', '0', 'integer', 0, 0, 'Maximum team members for enterprise tier (0 = unlimited)', NULL),
('invitations', 'invitation_expiry_days', '7', 'integer', 0, 0, 'Days until team invitations expire', NULL);
ALTER TABLE tblPartners
ADD COLUMN `showInDirectory` BOOLEAN NOT NULL DEFAULT FALSE
    COMMENT 'Whether partner appears in public directory (if enabled)'
    AFTER status,
ADD COLUMN `directoryLogo` VARCHAR(255) NULL
    COMMENT 'URL to partner logo for directory'
    AFTER showInDirectory,
ADD COLUMN `directoryDescription` TEXT NULL
    COMMENT 'Public description for directory listing'
    AFTER directoryLogo;
DELIMITER //

CREATE TRIGGER before_team_member_insert
BEFORE INSERT ON tblPartnerTeamMembers
FOR EACH ROW
BEGIN
    -- Ensure only ONE root admin per partner
    IF NEW.isRootAdmin = TRUE THEN
        -- Check if root admin already exists
        IF EXISTS (
            SELECT 1 FROM tblPartnerTeamMembers
            WHERE partnerID = NEW.partnerID
            AND isRootAdmin = TRUE
            AND memberID != NEW.memberID
        ) THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Partner already has a root admin. Transfer ownership first.';
        END IF;
        -- Root admin must have root-admin role
        SET NEW.role = 'root-admin';
    END IF;
END//

CREATE TRIGGER before_team_member_update
BEFORE UPDATE ON tblPartnerTeamMembers
FOR EACH ROW
BEGIN
    -- Ensure only ONE root admin per partner
    IF NEW.isRootAdmin = TRUE AND OLD.isRootAdmin = FALSE THEN
        -- Transferring root admin
        IF EXISTS (
            SELECT 1 FROM tblPartnerTeamMembers
            WHERE partnerID = NEW.partnerID
            AND isRootAdmin = TRUE
            AND memberID != NEW.memberID
        ) THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Partner already has a root admin. Remove existing root admin first.';
        END IF;
        -- Root admin must have root-admin role
        SET NEW.role = 'root-admin';
    END IF;

    -- Cannot remove last root admin
    IF OLD.isRootAdmin = TRUE AND NEW.isRootAdmin = FALSE THEN
        IF NOT EXISTS (
            SELECT 1 FROM tblPartnerTeamMembers
            WHERE partnerID = NEW.partnerID
            AND isRootAdmin = TRUE
            AND memberID != NEW.memberID
        ) THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot remove last root admin. Transfer ownership first.';
        END IF;
    END IF;
END//

DELIMITER ;
CREATE OR REPLACE VIEW vwPartnerAdmins AS
SELECT
    tm.memberID,
    tm.partnerID,
    p.companyName,
    tm.userID,
    u.username,
    u.email,
    tm.role,
    tm.isRootAdmin,
    tm.status,
    tm.createdAt
FROM tblPartnerTeamMembers tm
JOIN tblPartners p ON tm.partnerID = p.partnerID
JOIN tblUsers u ON tm.userID = u.userID
WHERE tm.role IN ('root-admin', 'admin')
AND tm.status = 'active';
CREATE OR REPLACE VIEW vwActiveFeatures AS
SELECT
    f.featureID,
    f.featureKey,
    f.featureName,
    f.isEnabledGlobally,
    f.category,
    COUNT(pf.partnerFeatureID) as partnersWithOverride,
    SUM(CASE WHEN pf.isEnabled = TRUE THEN 1 ELSE 0 END) as partnersEnabled
FROM tblFeatureToggles f
LEFT JOIN tblPartnerFeatures pf ON f.featureID = pf.featureID
GROUP BY f.featureID;


-- ============================================================================
-- 💰 WEBHOOKS & PAYMENT SYSTEM
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblWebhookEndpoints` (
    `endpointID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `partnerID` INT UNSIGNED NOT NULL,
    `url` VARCHAR(2048) NOT NULL COMMENT 'HTTPS URL to deliver webhooks to',
    `description` VARCHAR(255) DEFAULT NULL COMMENT 'Human-readable description of this endpoint',
    `secret` VARCHAR(128) NOT NULL COMMENT 'HMAC-SHA256 signing secret (encrypted at rest)',
    `status` ENUM('active', 'paused', 'disabled', 'failed') NOT NULL DEFAULT 'active',
    `events` JSON NOT NULL COMMENT 'Array of subscribed event types',
    `headers` JSON DEFAULT NULL COMMENT 'Custom headers to include in deliveries',
    `retryPolicy` JSON DEFAULT NULL COMMENT 'Custom retry config (maxRetries, backoffSeconds)',
    `rateLimitPerMinute` INT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Max deliveries per minute',
    `timeoutSeconds` INT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'HTTP request timeout',
    `lastDeliveryAt` DATETIME DEFAULT NULL,
    `lastSuccessAt` DATETIME DEFAULT NULL,
    `lastFailureAt` DATETIME DEFAULT NULL,
    `failureCount` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Consecutive failures (resets on success)',
    `totalDeliveries` INT UNSIGNED NOT NULL DEFAULT 0,
    `totalSuccesses` INT UNSIGNED NOT NULL DEFAULT 0,
    `totalFailures` INT UNSIGNED NOT NULL DEFAULT 0,
    `disabledReason` VARCHAR(255) DEFAULT NULL COMMENT 'Reason endpoint was auto-disabled',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`endpointID`),
    INDEX `idx_webhook_partner` (`partnerID`),
    INDEX `idx_webhook_status` (`status`),
    INDEX `idx_webhook_last_delivery` (`lastDeliveryAt`),
    CONSTRAINT `fk_webhook_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner webhook endpoint configuration with HMAC-SHA256 signing';

-- Webhook delivery log
-- Tracks every outbound webhook attempt for auditing and debugging
CREATE TABLE IF NOT EXISTS `tblWebhookDeliveries` (
    `deliveryID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `endpointID` INT UNSIGNED NOT NULL,
    `partnerID` INT UNSIGNED NOT NULL,
    `eventType` VARCHAR(100) NOT NULL COMMENT 'Event type (e.g. user.created, payment.completed)',
    `eventID` VARCHAR(64) NOT NULL COMMENT 'Unique event identifier for idempotency',
    `payload` JSON NOT NULL COMMENT 'JSON payload delivered',
    `signatureHeader` VARCHAR(255) NOT NULL COMMENT 'X-SIGNula-Signature header value sent',
    `httpStatusCode` SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Response HTTP status code',
    `responseBody` TEXT DEFAULT NULL COMMENT 'Response body (truncated to 4KB)',
    `responseTimeMs` INT UNSIGNED DEFAULT NULL COMMENT 'Response time in milliseconds',
    `status` ENUM('pending', 'delivered', 'failed', 'retrying') NOT NULL DEFAULT 'pending',
    `attemptNumber` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `maxAttempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `nextRetryAt` DATETIME DEFAULT NULL COMMENT 'When to retry (exponential backoff)',
    `errorMessage` VARCHAR(500) DEFAULT NULL COMMENT 'Error description on failure',
    `deliveredAt` DATETIME DEFAULT NULL,
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`deliveryID`),
    INDEX `idx_delivery_endpoint` (`endpointID`),
    INDEX `idx_delivery_partner` (`partnerID`),
    INDEX `idx_delivery_event_type` (`eventType`),
    INDEX `idx_delivery_event_id` (`eventID`),
    INDEX `idx_delivery_status` (`status`),
    INDEX `idx_delivery_retry` (`status`, `nextRetryAt`),
    INDEX `idx_delivery_created` (`createdAt`),
    CONSTRAINT `fk_delivery_endpoint` FOREIGN KEY (`endpointID`)
        REFERENCES `tblWebhookEndpoints` (`endpointID`) ON DELETE CASCADE,
    CONSTRAINT `fk_delivery_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Outbound webhook delivery log with retry tracking';

-- Default webhook event types in tblSettings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `settingDescription`, `isSensitive`)
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `settingDescription`, `isSensitive`)
VALUES
VALUES
    ('webhook.enabled', '1', 'webhooks', 'Enable outbound webhook system', 0),
    ('webhook.enabled', '1', 'webhooks', 'Enable outbound webhook system', 0),
    ('webhook.max_endpoints_per_partner', '10', 'webhooks', 'Maximum webhook endpoints per partner', 0),
    ('webhook.max_endpoints_per_partner', '10', 'webhooks', 'Maximum webhook endpoints per partner', 0),
    ('webhook.default_max_retries', '5', 'webhooks', 'Default maximum delivery retries', 0),
    ('webhook.default_max_retries', '5', 'webhooks', 'Default maximum delivery retries', 0),
    ('webhook.retry_backoff_base', '60', 'webhooks', 'Base retry backoff in seconds (exponential)', 0),
    ('webhook.retry_backoff_base', '60', 'webhooks', 'Base retry backoff in seconds (exponential)', 0),
    ('webhook.auto_disable_threshold', '50', 'webhooks', 'Consecutive failures before auto-disabling endpoint', 0),
    ('webhook.auto_disable_threshold', '50', 'webhooks', 'Consecutive failures before auto-disabling endpoint', 0),
    ('webhook.delivery_retention_days', '30', 'webhooks', 'Days to retain delivery logs', 0),
    ('webhook.delivery_retention_days', '30', 'webhooks', 'Days to retain delivery logs', 0),
    ('webhook.request_timeout', '30', 'webhooks', 'Default HTTP request timeout in seconds', 0),
    ('webhook.request_timeout', '30', 'webhooks', 'Default HTTP request timeout in seconds', 0),
    ('webhook.supported_events', '["user.created","user.updated","user.deleted","user.login","user.logout","user.mfa_enabled","user.mfa_disabled","subscription.created","subscription.updated","subscription.cancelled","subscription.renewed","payment.completed","payment.failed","payment.refunded","partner.api_key_created","partner.api_key_revoked"]', 'webhooks', 'List of supported webhook event types (JSON)', 0)
    ('webhook.supported_events', '["user.created","user.updated","user.deleted","user.login","user.logout","user.mfa_enabled","user.mfa_disabled","subscription.created","subscription.updated","subscription.cancelled","subscription.renewed","payment.completed","payment.failed","payment.refunded","partner.api_key_created","partner.api_key_revoked"]', 'webhooks', 'List of supported webhook event types (JSON)', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);

-- Scheduled event to clean up old delivery logs
-- https://dev.mysql.com/doc/refman/8.0/en/create-event.html
CREATE EVENT IF NOT EXISTS `evt_cleanup_webhook_deliveries`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
    DELETE FROM `tblWebhookDeliveries`
    WHERE `createdAt` < DATE_SUB(NOW(), INTERVAL 30 DAY)
    AND `status` IN ('delivered', 'failed');


-- ============================================================================
-- SUBSCRIPTION TIERS
-- ============================================================================

-- Subscription tier definitions
-- Defines available pricing tiers for services
CREATE TABLE IF NOT EXISTS `tblSubscriptionTiers` (
    `tierID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tierName` VARCHAR(100) NOT NULL COMMENT 'Display name (e.g. Free, Basic, Premium, Enterprise)',
    `tierSlug` VARCHAR(50) NOT NULL COMMENT 'URL-safe identifier (e.g. free, basic, premium)',
    `tierDescription` TEXT DEFAULT NULL COMMENT 'Description of tier benefits',
    `monthlyPrice` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Monthly price in default currency',
    `yearlyPrice` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Yearly price (discounted)',
    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP' COMMENT 'ISO 4217 currency code',
    `features` JSON NOT NULL COMMENT 'Array of included feature strings',
    `featureLimits` JSON DEFAULT NULL COMMENT 'Object of numeric limits (e.g. {"api_calls": 1000})',
    `teamMemberLimit` INT UNSIGNED DEFAULT NULL COMMENT 'Max team members (NULL = unlimited)',
    `apiCallsPerMonth` INT UNSIGNED DEFAULT NULL COMMENT 'Monthly API call limit (NULL = unlimited)',
    `storageGB` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Storage allocation in GB',
    `displayOrder` INT NOT NULL DEFAULT 0 COMMENT 'Sort order for pricing page',
    `isActive` TINYINT(1) NOT NULL DEFAULT 1,
    `isDefault` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Default tier for new users',
    `trialDays` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Free trial period in days (0 = no trial)',
    `badge` VARCHAR(50) DEFAULT NULL COMMENT 'Badge text (e.g. Popular, Best Value)',
    `badgeColor` VARCHAR(20) DEFAULT NULL COMMENT 'Badge CSS colour class',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`tierID`),
    UNIQUE KEY `uk_tier_slug` (`tierSlug`),
    INDEX `idx_tier_active` (`isActive`, `displayOrder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Subscription tier definitions with pricing and feature limits';

-- Default subscription tiers
INSERT INTO `tblSubscriptionTiers` (`tierName`, `tierSlug`, `tierDescription`, `monthlyPrice`, `yearlyPrice`, `currency`, `features`, `featureLimits`, `teamMemberLimit`, `apiCallsPerMonth`, `displayOrder`, `isActive`, `isDefault`, `trialDays`, `badge`, `badgeColor`)
INSERT INTO `tblSubscriptionTiers` (`tierName`, `tierSlug`, `tierDescription`, `monthlyPrice`, `yearlyPrice`, `currency`, `features`, `featureLimits`, `teamMemberLimit`, `apiCallsPerMonth`, `displayOrder`, `isActive`, `isDefault`, `trialDays`, `badge`, `badgeColor`)
VALUES
VALUES
    ('Free', 'free', 'Get started with essential features at no cost', 0.00, 0.00, 'GBP', '["Basic authentication","Single sign-on","5 team members","1,000 API calls/month","Community support"]', '{"api_calls": 1000, "team_members": 5, "storage_gb": 1}', 5, 1000, 1, 1, 1, 0, NULL, NULL),
    ('Free', 'free', 'Get started with essential features at no cost', 0.00, 0.00, 'GBP', '["Basic authentication","Single sign-on","5 team members","1,000 API calls/month","Community support"]', '{"api_calls": 1000, "team_members": 5, "storage_gb": 1}', 5, 1000, 1, 1, 1, 0, NULL, NULL),
    ('Basic', 'basic', 'Perfect for small teams and growing businesses', 9.99, 99.99, 'GBP', '["Everything in Free","MFA enforcement","10 team members","10,000 API calls/month","Email support","Custom branding","Webhook integrations"]', '{"api_calls": 10000, "team_members": 10, "storage_gb": 10}', 10, 10000, 2, 1, 0, 14, NULL, NULL),
    ('Basic', 'basic', 'Perfect for small teams and growing businesses', 9.99, 99.99, 'GBP', '["Everything in Free","MFA enforcement","10 team members","10,000 API calls/month","Email support","Custom branding","Webhook integrations"]', '{"api_calls": 10000, "team_members": 10, "storage_gb": 10}', 10, 10000, 2, 1, 0, 14, NULL, NULL),
    ('Premium', 'premium', 'Advanced features for scaling organisations', 29.99, 299.99, 'GBP', '["Everything in Basic","25 team members","50,000 API calls/month","Priority support","Advanced analytics","SSO enforcement","Custom OAuth providers","Audit log export"]', '{"api_calls": 50000, "team_members": 25, "storage_gb": 50}', 25, 50000, 3, 1, 0, 14, 'Popular', 'primary'),
    ('Premium', 'premium', 'Advanced features for scaling organisations', 29.99, 299.99, 'GBP', '["Everything in Basic","25 team members","50,000 API calls/month","Priority support","Advanced analytics","SSO enforcement","Custom OAuth providers","Audit log export"]', '{"api_calls": 50000, "team_members": 25, "storage_gb": 50}', 25, 50000, 3, 1, 0, 14, 'Popular', 'primary'),
    ('Enterprise', 'enterprise', 'Unlimited access with dedicated support and SLAs', 99.99, 999.99, 'GBP', '["Everything in Premium","Unlimited team members","Unlimited API calls","Dedicated support","Custom SLAs","On-premise deployment option","SAML/SCIM integration","Custom contracts"]', '{"api_calls": null, "team_members": null, "storage_gb": null}', NULL, NULL, 4, 1, 0, 30, 'Best Value', 'success');
    ('Enterprise', 'enterprise', 'Unlimited access with dedicated support and SLAs', 99.99, 999.99, 'GBP', '["Everything in Premium","Unlimited team members","Unlimited API calls","Dedicated support","Custom SLAs","On-premise deployment option","SAML/SCIM integration","Custom contracts"]', '{"api_calls": null, "team_members": null, "storage_gb": null}', NULL, NULL, 4, 1, 0, 30, 'Best Value', 'success');


-- ============================================================================
-- SUBSCRIPTIONS
-- ============================================================================

-- User/partner subscriptions
CREATE TABLE IF NOT EXISTS `tblSubscriptions` (
    `subscriptionID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` INT UNSIGNED NOT NULL COMMENT 'Account owner',
    `partnerID` INT UNSIGNED DEFAULT NULL COMMENT 'Partner organisation (if applicable)',
    `tierID` INT UNSIGNED NOT NULL,
    `subscriptionStatus` ENUM('active', 'cancelled', 'expired', 'paused', 'trial', 'pending', 'past_due') NOT NULL DEFAULT 'pending',
    `billingCycle` ENUM('monthly', 'quarterly', 'yearly', 'lifetime', 'one_time') NOT NULL DEFAULT 'monthly',
    `amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Current billing amount',
    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP',
    `paymentMethod` ENUM('paypal', 'apple_pay', 'google_pay', 'crypto', 'stripe', 'manual', 'free') NOT NULL DEFAULT 'free',
    `paymentProviderSubscriptionID` VARCHAR(255) DEFAULT NULL COMMENT 'External subscription ID from payment provider',
    `startDate` DATE NOT NULL,
    `endDate` DATE DEFAULT NULL COMMENT 'NULL for active recurring subscriptions',
    `currentPeriodStart` DATE NOT NULL,
    `currentPeriodEnd` DATE NOT NULL,
    `nextBillingDate` DATE DEFAULT NULL,
    `trialEndsAt` DATETIME DEFAULT NULL,
    `autoRenew` TINYINT(1) NOT NULL DEFAULT 1,
    `cancelledAt` DATETIME DEFAULT NULL,
    `cancellationReason` TEXT DEFAULT NULL,
    `pausedAt` DATETIME DEFAULT NULL,
    `resumesAt` DATETIME DEFAULT NULL,
    `metadata` JSON DEFAULT NULL COMMENT 'Additional subscription metadata',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`subscriptionID`),
    INDEX `idx_sub_user` (`userID`),
    INDEX `idx_sub_partner` (`partnerID`),
    INDEX `idx_sub_tier` (`tierID`),
    INDEX `idx_sub_status` (`subscriptionStatus`),
    INDEX `idx_sub_billing_date` (`nextBillingDate`),
    INDEX `idx_sub_trial_end` (`trialEndsAt`),
    INDEX `idx_sub_provider_id` (`paymentProviderSubscriptionID`),
    CONSTRAINT `fk_sub_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_sub_tier` FOREIGN KEY (`tierID`)
        REFERENCES `tblSubscriptionTiers` (`tierID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User and partner subscriptions with billing cycle management';


-- ============================================================================
-- PAYMENTS
-- ============================================================================

-- Payment transaction records
CREATE TABLE IF NOT EXISTS `tblPayments` (
    `paymentID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` INT UNSIGNED NOT NULL,
    `subscriptionID` INT UNSIGNED DEFAULT NULL COMMENT 'Linked subscription (NULL for one-off payments)',
    `transactionID` VARCHAR(255) DEFAULT NULL COMMENT 'External transaction ID from payment provider',
    `paymentMethod` ENUM('paypal', 'apple_pay', 'google_pay', 'crypto_btc', 'crypto_eth', 'crypto_usdt', 'stripe', 'manual') NOT NULL,
    `paymentProvider` VARCHAR(100) NOT NULL COMMENT 'Provider name (PayPal, Stripe, Coinbase, etc.)',
    `amount` DECIMAL(10, 2) NOT NULL,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP',
    `exchangeRate` DECIMAL(16, 8) DEFAULT NULL COMMENT 'Exchange rate if paid in different currency',
    `originalAmount` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Amount in original currency if converted',
    `originalCurrency` VARCHAR(3) DEFAULT NULL,
    `discountAmount` DECIMAL(10, 2) DEFAULT 0.00 COMMENT 'Discount applied',
    `discountCode` VARCHAR(50) DEFAULT NULL COMMENT 'Discount/promo code used',
    `taxAmount` DECIMAL(10, 2) DEFAULT 0.00 COMMENT 'Tax/VAT amount',
    `taxRate` DECIMAL(5, 2) DEFAULT 0.00 COMMENT 'Tax rate percentage',
    `netAmount` DECIMAL(10, 2) NOT NULL COMMENT 'Amount after tax and discounts',
    `status` ENUM('pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled', 'disputed') NOT NULL DEFAULT 'pending',
    `description` VARCHAR(500) DEFAULT NULL,
    `paymentData` JSON DEFAULT NULL COMMENT 'Provider-specific payment data (encrypted sensitive fields)',
    `billingAddress` JSON DEFAULT NULL COMMENT 'Billing address snapshot',
    `ipAddress` VARCHAR(45) NOT NULL COMMENT 'IPv4 or IPv6 address',
    `userAgent` VARCHAR(500) DEFAULT NULL,
    `paidAt` DATETIME DEFAULT NULL,
    `refundedAt` DATETIME DEFAULT NULL,
    `refundAmount` DECIMAL(10, 2) DEFAULT NULL,
    `refundReason` TEXT DEFAULT NULL,
    `failureReason` VARCHAR(500) DEFAULT NULL COMMENT 'Reason for payment failure',
    `receiptURL` VARCHAR(2048) DEFAULT NULL COMMENT 'URL to payment receipt',
    `invoiceNumber` VARCHAR(50) DEFAULT NULL COMMENT 'Internal invoice number',
    `metadata` JSON DEFAULT NULL,
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`paymentID`),
    UNIQUE KEY `uk_transaction_id` (`transactionID`),
    INDEX `idx_payment_user` (`userID`),
    INDEX `idx_payment_subscription` (`subscriptionID`),
    INDEX `idx_payment_status` (`status`),
    INDEX `idx_payment_method` (`paymentMethod`),
    INDEX `idx_payment_provider` (`paymentProvider`),
    INDEX `idx_payment_date` (`paidAt`),
    INDEX `idx_payment_invoice` (`invoiceNumber`),
    CONSTRAINT `fk_payment_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_payment_subscription` FOREIGN KEY (`subscriptionID`)
        REFERENCES `tblSubscriptions` (`subscriptionID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Payment transaction records with multi-provider support';

-- Stored payment methods (tokenised - never stores raw card numbers)
CREATE TABLE IF NOT EXISTS `tblPaymentMethods` (
    `methodID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` INT UNSIGNED NOT NULL,
    `paymentMethod` ENUM('paypal', 'apple_pay', 'google_pay', 'crypto_btc', 'crypto_eth', 'crypto_usdt', 'stripe') NOT NULL,
    `provider` VARCHAR(100) NOT NULL COMMENT 'Payment provider name',
    `providerMethodID` VARCHAR(255) DEFAULT NULL COMMENT 'Provider token/method ID',
    `displayName` VARCHAR(100) NOT NULL COMMENT 'User-friendly name (e.g. PayPal - john@example.com)',
    `lastFour` VARCHAR(4) DEFAULT NULL COMMENT 'Last 4 digits (if applicable)',
    `expiryMonth` TINYINT UNSIGNED DEFAULT NULL,
    `expiryYear` SMALLINT UNSIGNED DEFAULT NULL,
    `brand` VARCHAR(50) DEFAULT NULL COMMENT 'Card brand or wallet type',
    `billingEmail` VARCHAR(320) DEFAULT NULL COMMENT 'Billing email for this method',
    `isDefault` TINYINT(1) NOT NULL DEFAULT 0,
    `isVerified` TINYINT(1) NOT NULL DEFAULT 0,
    `metadata` JSON DEFAULT NULL COMMENT 'Additional provider-specific data (encrypted)',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`methodID`),
    INDEX `idx_pm_user` (`userID`),
    INDEX `idx_pm_provider` (`provider`),
    INDEX `idx_pm_default` (`userID`, `isDefault`),
    CONSTRAINT `fk_pm_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stored payment methods (tokenised, never stores raw credentials)';

-- Discount/promo codes
CREATE TABLE IF NOT EXISTS `tblDiscountCodes` (
    `discountID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL COMMENT 'Unique promo code',
    `description` VARCHAR(255) DEFAULT NULL,
    `discountType` ENUM('percentage', 'fixed_amount', 'free_trial_days') NOT NULL DEFAULT 'percentage',
    `discountValue` DECIMAL(10, 2) NOT NULL COMMENT 'Percentage (0-100) or fixed amount',
    `currency` VARCHAR(3) DEFAULT 'GBP' COMMENT 'Currency for fixed amount discounts',
    `applicableTiers` JSON DEFAULT NULL COMMENT 'Array of tier slugs (NULL = all tiers)',
    `applicablePaymentMethods` JSON DEFAULT NULL COMMENT 'Array of payment methods (NULL = all)',
    `minAmount` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Minimum purchase amount to apply',
    `maxUses` INT UNSIGNED DEFAULT NULL COMMENT 'Total redemption limit (NULL = unlimited)',
    `maxUsesPerUser` INT UNSIGNED DEFAULT 1 COMMENT 'Per-user redemption limit',
    `currentUses` INT UNSIGNED NOT NULL DEFAULT 0,
    `validFrom` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `validUntil` DATETIME DEFAULT NULL COMMENT 'NULL = no expiry',
    `isActive` TINYINT(1) NOT NULL DEFAULT 1,
    `createdBy` INT UNSIGNED DEFAULT NULL COMMENT 'Admin who created this code',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`discountID`),
    UNIQUE KEY `uk_discount_code` (`code`),
    INDEX `idx_discount_active` (`isActive`, `validFrom`, `validUntil`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Discount and promotional codes for payment discounts';

-- Payment settings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `settingDescription`, `isSensitive`)
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `settingDescription`, `isSensitive`)
VALUES
VALUES
    ('payment.enabled', '0', 'payment', 'Enable payment processing (set to 1 when ready)', 0),
    ('payment.enabled', '0', 'payment', 'Enable payment processing (set to 1 when ready)', 0),
    ('payment.default_currency', 'GBP', 'payment', 'Default currency (ISO 4217)', 0),
    ('payment.default_currency', 'GBP', 'payment', 'Default currency (ISO 4217)', 0),
    ('payment.tax_rate', '20.00', 'payment', 'Default VAT/tax rate percentage', 0),
    ('payment.tax_rate', '20.00', 'payment', 'Default VAT/tax rate percentage', 0),
    ('payment.tax_enabled', '1', 'payment', 'Enable tax calculation on payments', 0),
    ('payment.tax_enabled', '1', 'payment', 'Enable tax calculation on payments', 0),
    ('payment.paypal.enabled', '0', 'payment', 'Enable PayPal payments', 0),
    ('payment.paypal.enabled', '0', 'payment', 'Enable PayPal payments', 0),
    ('payment.paypal.client_id', '', 'payment', 'PayPal REST API Client ID', 1),
    ('payment.paypal.client_id', '', 'payment', 'PayPal REST API Client ID', 1),
    ('payment.paypal.client_secret', '', 'payment', 'PayPal REST API Client Secret', 1),
    ('payment.paypal.client_secret', '', 'payment', 'PayPal REST API Client Secret', 1),
    ('payment.paypal.mode', 'sandbox', 'payment', 'PayPal mode: sandbox or live', 0),
    ('payment.paypal.mode', 'sandbox', 'payment', 'PayPal mode: sandbox or live', 0),
    ('payment.paypal.webhook_id', '', 'payment', 'PayPal Webhook ID for event verification', 1),
    ('payment.paypal.webhook_id', '', 'payment', 'PayPal Webhook ID for event verification', 1),
    ('payment.apple_pay.enabled', '0', 'payment', 'Enable Apple Pay', 0),
    ('payment.apple_pay.enabled', '0', 'payment', 'Enable Apple Pay', 0),
    ('payment.apple_pay.merchant_id', '', 'payment', 'Apple Pay Merchant ID', 1),
    ('payment.apple_pay.merchant_id', '', 'payment', 'Apple Pay Merchant ID', 1),
    ('payment.google_pay.enabled', '0', 'payment', 'Enable Google Pay', 0),
    ('payment.google_pay.enabled', '0', 'payment', 'Enable Google Pay', 0),
    ('payment.google_pay.merchant_id', '', 'payment', 'Google Pay Merchant ID', 1),
    ('payment.google_pay.merchant_id', '', 'payment', 'Google Pay Merchant ID', 1),
    ('payment.crypto.enabled', '0', 'payment', 'Enable cryptocurrency payments', 0),
    ('payment.crypto.enabled', '0', 'payment', 'Enable cryptocurrency payments', 0),
    ('payment.crypto.discount_percent', '5.00', 'payment', 'Discount percentage for crypto payments', 0),
    ('payment.crypto.discount_percent', '5.00', 'payment', 'Discount percentage for crypto payments', 0),
    ('payment.crypto.provider', 'coinbase', 'payment', 'Crypto payment provider (coinbase, btcpay)', 0),
    ('payment.crypto.provider', 'coinbase', 'payment', 'Crypto payment provider (coinbase, btcpay)', 0),
    ('payment.crypto.api_key', '', 'payment', 'Crypto provider API key', 1),
    ('payment.crypto.api_key', '', 'payment', 'Crypto provider API key', 1),
    ('payment.invoice_prefix', 'SIG', 'payment', 'Invoice number prefix', 0),
    ('payment.invoice_prefix', 'SIG', 'payment', 'Invoice number prefix', 0),
    ('payment.receipt_email', '1', 'payment', 'Send receipt emails after successful payment', 0)
    ('payment.receipt_email', '1', 'payment', 'Send receipt emails after successful payment', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);

-- ============================================================================
-- MIGRATION TRACKING
-- ============================================================================

INSERT INTO `tblMigrations` (`migrationName`, `migrationVersion`, `description`)
INSERT INTO `tblMigrations` (`migrationName`, `migrationVersion`, `description`)
VALUES ('010_webhooks_and_payments', '2.3.0-beta', 'Outbound webhook signatures and payment/subscription system')
VALUES ('010_webhooks_and_payments', '2.3.0-beta', 'Outbound webhook signatures and payment/subscription system')
ON DUPLICATE KEY UPDATE `migrationName` = VALUES(`migrationName`);
ON DUPLICATE KEY UPDATE `migrationName` = VALUES(`migrationName`);

-- ============================================================================


-- ============================================================================
-- 💳 PAYMENT PROVIDER INTEGRATION
-- ============================================================================

ALTER TABLE `tblPayments`
    MODIFY COLUMN `paymentMethod`
        ENUM('paypal', 'apple_pay', 'google_pay', 'crypto_btc', 'crypto_eth', 'crypto_usdt', 'stripe', 'link', 'manual')
        NOT NULL
        COMMENT 'Payment method used (link = Stripe Link accelerated checkout)';
ALTER TABLE `tblSubscriptions`
    MODIFY COLUMN `paymentMethod`
        ENUM('paypal', 'apple_pay', 'google_pay', 'crypto', 'stripe', 'link', 'manual', 'free')
        NOT NULL DEFAULT 'free'
        COMMENT 'Payment method for subscription billing (link = Stripe Link)';
ALTER TABLE `tblPaymentMethods`
    MODIFY COLUMN `paymentMethod`
        ENUM('paypal', 'apple_pay', 'google_pay', 'crypto_btc', 'crypto_eth', 'crypto_usdt', 'stripe', 'link')
        NOT NULL
        COMMENT 'Stored payment method type (link = Stripe Link)';
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `settingDescription`, `isSensitive`)
VALUES
    -- 🔵 Stripe Core Settings
    ('payment.stripe.enabled', '0', 'payment', 'Enable Stripe payment processing', 0),
    ('payment.stripe.mode', 'test', 'payment', 'Stripe mode: test or live', 0),
    ('payment.stripe.publishable_key', '', 'payment', 'Stripe publishable API key (pk_test_xxx or pk_live_xxx)', 1),
    ('payment.stripe.secret_key', '', 'payment', 'Stripe secret API key (sk_test_xxx or sk_live_xxx)', 1),
    ('payment.stripe.webhook_secret', '', 'payment', 'Stripe webhook endpoint signing secret (whsec_xxx)', 1),

    -- 🔗 Stripe Link — Accelerated Checkout
    -- Link saves payment details across Stripe merchants for faster checkout
    -- @see https://docs.stripe.com/payments/link
    ('payment.stripe.link_enabled', '1', 'payment', 'Enable Stripe Link accelerated checkout (auto-fill payment details)', 0),

    -- 💳 Stripe Payment Methods
    -- JSON array of enabled payment method types for Payment Element
    -- @see https://docs.stripe.com/payments/payment-methods/overview
    ('payment.stripe.payment_methods', '["card","link"]', 'payment', 'Enabled Stripe payment method types (JSON array: card, link, apple_pay, google_pay)', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `settingDescription`, `isSensitive`)
VALUES
    ('payment.crypto.webhook_secret', '', 'payment', 'Coinbase Commerce webhook shared secret for signature verification', 1),
    ('payment.crypto.supported_currencies', '["BTC","ETH","USDT","USDC"]', 'payment', 'Supported cryptocurrency types (JSON array)', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);
CREATE TABLE IF NOT EXISTS `tblInboundWebhooks` (
    `webhookID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique webhook log entry ID',

    `provider` VARCHAR(50) NOT NULL
        COMMENT 'Payment provider name (stripe, paypal, coinbase)',

    `eventType` VARCHAR(100) NOT NULL
        COMMENT 'Provider event type (e.g. checkout.session.completed, PAYMENT.CAPTURE.COMPLETED)',

    `eventID` VARCHAR(255) NOT NULL
        COMMENT 'Provider-assigned unique event identifier for idempotency checks',

    `payload` JSON NOT NULL
        COMMENT 'Full JSON payload received from provider',

    `signature` VARCHAR(512) DEFAULT NULL
        COMMENT 'Signature header value received (for audit trail)',

    `verified` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Whether signature verification passed (1=verified, 0=failed/skipped)',

    `status` ENUM('pending', 'processed', 'failed', 'ignored', 'duplicate') NOT NULL DEFAULT 'pending'
        COMMENT 'Processing status of this webhook event',

    `processedAt` DATETIME DEFAULT NULL
        COMMENT 'When the webhook was successfully processed',

    `errorMessage` VARCHAR(1000) DEFAULT NULL
        COMMENT 'Error description if processing failed',

    `httpStatusReturned` SMALLINT UNSIGNED DEFAULT NULL
        COMMENT 'HTTP status code we returned to the provider',

    `processingTimeMs` INT UNSIGNED DEFAULT NULL
        COMMENT 'Time taken to process the webhook in milliseconds',

    `ipAddress` VARCHAR(45) NOT NULL
        COMMENT 'IP address of the webhook sender (IPv4 or IPv6)',

    `userAgent` VARCHAR(500) DEFAULT NULL
        COMMENT 'User-Agent header from the webhook request',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When the webhook was received',

    PRIMARY KEY (`webhookID`),
    INDEX `idx_inbound_wh_provider` (`provider`),
    INDEX `idx_inbound_wh_event_type` (`eventType`),
    INDEX `idx_inbound_wh_event_id` (`eventID`),
    INDEX `idx_inbound_wh_status` (`status`),
    INDEX `idx_inbound_wh_verified` (`verified`),
    INDEX `idx_inbound_wh_created` (`createdAt`),
    INDEX `idx_inbound_wh_provider_event` (`provider`, `eventID`)
        COMMENT 'Composite index for duplicate detection'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Inbound webhook event log from payment providers (Stripe, PayPal, Coinbase)';

-- 🧹 Scheduled cleanup for old processed webhooks (retain 90 days)
-- @see https://dev.mysql.com/doc/refman/8.0/en/create-event.html
CREATE EVENT IF NOT EXISTS `evt_cleanup_inbound_webhooks`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
    DELETE FROM `tblInboundWebhooks`
    WHERE `createdAt` < DATE_SUB(NOW(), INTERVAL 90 DAY)
    AND `status` IN ('processed', 'ignored', 'duplicate');


-- ============================================================================
-- MIGRATION TRACKING
-- ============================================================================

INSERT INTO `tblMigrations` (`migrationName`, `migrationVersion`, `description`)
INSERT INTO `tblMigrations` (`migrationName`, `migrationVersion`, `description`)
VALUES ('011_payment_providers', '2.3.0-beta', 'Payment provider integration: Stripe (with Link), PayPal enhancements, Coinbase Commerce, inbound webhook logging')
VALUES ('011_payment_providers', '2.3.0-beta', 'Payment provider integration: Stripe (with Link), PayPal enhancements, Coinbase Commerce, inbound webhook logging')
ON DUPLICATE KEY UPDATE `migrationName` = VALUES(`migrationName`);
ON DUPLICATE KEY UPDATE `migrationName` = VALUES(`migrationName`);

-- ============================================================================
-- END OF MIGRATION 011
-- ============================================================================


-- ============================================================================
-- 🏦 TWO-TIER PAYMENT EXPANSION
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblPartnerPaymentConfig` (
    `configID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique configuration record ID',

    `partnerID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblPartners — the partner this config belongs to',

    `provider` ENUM('stripe', 'paypal', 'coinbase') NOT NULL
        COMMENT 'Payment provider this config applies to',

    `usesOwnKeys` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = Option 2a (partner uses own API keys), 0 = Option 2b (uses SIGNula keys)',

    -- 🔐 Encrypted partner API credentials (NULL when usesOwnKeys = 0)
    -- All values encrypted via SecurityUtils::encrypt() before storage
    `apiKey` TEXT DEFAULT NULL
        COMMENT 'Encrypted: Partner API key / Client ID',

    `apiSecret` TEXT DEFAULT NULL
        COMMENT 'Encrypted: Partner API secret / Client Secret',

    `webhookSecret` TEXT DEFAULT NULL
        COMMENT 'Encrypted: Partner webhook signing secret',

    `publishableKey` TEXT DEFAULT NULL
        COMMENT 'Encrypted: Partner publishable key (Stripe only)',

    `mode` ENUM('sandbox', 'test', 'live') NOT NULL DEFAULT 'sandbox'
        COMMENT 'API environment mode',

    -- ✅ Verification status
    `isEnabled` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Whether this provider is enabled for the partner',

    `isVerified` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Whether the credentials have been tested and verified',

    `verifiedAt` DATETIME DEFAULT NULL
        COMMENT 'When credentials were last verified',

    `metadata` JSON DEFAULT NULL
        COMMENT 'Additional provider-specific metadata',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`configID`),
    UNIQUE KEY `uk_partner_provider` (`partnerID`, `provider`),
    INDEX `idx_ppc_partner` (`partnerID`),
    INDEX `idx_ppc_provider` (`provider`),
    CONSTRAINT `fk_ppc_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner-specific payment provider credentials for Level 2 payments';


-- ============================================================================
-- 💰 SERVICE FEE MANAGEMENT
-- ============================================================================

-- 📊 Service fee schedule configuration
-- Tracks current and historical fee rates for both payment options
-- Supports the 30-day minimum notice requirement for fee changes
-- @see User requirement: "service fees for 2a & 2b can be set within settings"
CREATE TABLE IF NOT EXISTS `tblServiceFees` (
    `feeID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique fee schedule ID',

    `feeType` ENUM('partner_own_keys', 'partner_signula_keys') NOT NULL
        COMMENT 'partner_own_keys = Option 2a (~10%), partner_signula_keys = Option 2b (~30%)',

    `feePercentage` DECIMAL(5, 2) NOT NULL
        COMMENT 'Percentage fee charged by SIGNula (e.g. 10.00 = 10%)',

    `fixedFeeAmount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Optional fixed fee per transaction (in default currency)',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Currency for fixed fee amounts (ISO 4217)',

    `minFee` DECIMAL(10, 2) DEFAULT NULL
        COMMENT 'Minimum fee amount per transaction (NULL = no minimum)',

    `maxFee` DECIMAL(10, 2) DEFAULT NULL
        COMMENT 'Maximum fee cap per transaction (NULL = no cap)',

    `effectiveFrom` DATE NOT NULL
        COMMENT 'Date this fee schedule becomes active',

    `effectiveUntil` DATE DEFAULT NULL
        COMMENT 'Date this fee schedule expires (NULL = no expiry, superseded by newer)',

    `noticeGivenAt` DATETIME DEFAULT NULL
        COMMENT 'When partners were notified of this fee schedule/change',

    `minimumNoticeDays` INT NOT NULL DEFAULT 30
        COMMENT 'Minimum notice period in calendar days before activation',

    `isActive` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Whether this fee schedule is currently active',

    `createdBy` INT UNSIGNED DEFAULT NULL
        COMMENT 'Admin userID who created this fee schedule',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`feeID`),
    INDEX `idx_sf_fee_type` (`feeType`, `isActive`),
    INDEX `idx_sf_effective` (`effectiveFrom`, `effectiveUntil`),
    INDEX `idx_sf_active` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='SIGNula service fee rates for partner payment processing';


-- 💳 Individual service fee transaction records
-- Tracks the exact fee charged on each partner payment for reconciliation
CREATE TABLE IF NOT EXISTS `tblServiceFeeTransactions` (
    `feeTransactionID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique fee transaction ID',

    `paymentID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblPayments — the payment that triggered this fee',

    `partnerID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblPartners — the partner charged this fee',

    `feeID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblServiceFees — the fee schedule applied',

    `grossAmount` DECIMAL(10, 2) NOT NULL
        COMMENT 'Original payment amount before fee deduction',

    `feePercentageApplied` DECIMAL(5, 2) NOT NULL
        COMMENT 'Actual percentage applied (snapshot at time of charge)',

    `feeAmount` DECIMAL(10, 2) NOT NULL
        COMMENT 'Fee amount charged to partner',

    `netToPartner` DECIMAL(10, 2) NOT NULL
        COMMENT 'Amount owed to partner after fee deduction',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Currency (ISO 4217)',

    `status` ENUM('pending', 'collected', 'remitted', 'disputed', 'refunded') NOT NULL DEFAULT 'pending'
        COMMENT 'Fee collection and remittance status',

    `remittedAt` DATETIME DEFAULT NULL
        COMMENT 'When the net amount was remitted to the partner',

    `remittanceMethod` VARCHAR(50) DEFAULT NULL
        COMMENT 'How the partner was paid (paypal_payout, bank_transfer, credit_to_balance, manual)',

    `remittanceReference` VARCHAR(255) DEFAULT NULL
        COMMENT 'External reference for the remittance transaction',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`feeTransactionID`),
    INDEX `idx_sft_payment` (`paymentID`),
    INDEX `idx_sft_partner` (`partnerID`),
    INDEX `idx_sft_status` (`status`),
    INDEX `idx_sft_remitted` (`remittedAt`),
    INDEX `idx_sft_created` (`createdAt`),
    CONSTRAINT `fk_sft_payment` FOREIGN KEY (`paymentID`)
        REFERENCES `tblPayments` (`paymentID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sft_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE,
    CONSTRAINT `fk_sft_fee` FOREIGN KEY (`feeID`)
        REFERENCES `tblServiceFees` (`feeID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Individual service fee records for each partner transaction';


-- 💸 Partner payout/remittance tracking
-- Records batch payouts to partners after deducting service fees
CREATE TABLE IF NOT EXISTS `tblRemittances` (
    `remittanceID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique remittance ID',

    `partnerID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblPartners — the partner receiving this payout',

    `amount` DECIMAL(10, 2) NOT NULL
        COMMENT 'Net payout amount',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Payout currency (ISO 4217)',

    `method` ENUM('paypal_payout', 'bank_transfer', 'credit_to_balance', 'manual') NOT NULL
        COMMENT 'Payout delivery method',

    `paypalEmail` VARCHAR(320) DEFAULT NULL
        COMMENT 'PayPal payout recipient email (if method = paypal_payout)',

    `paypalBatchID` VARCHAR(255) DEFAULT NULL
        COMMENT 'PayPal Payout batch ID for tracking',

    `bankReference` VARCHAR(255) DEFAULT NULL
        COMMENT 'Bank transfer reference (if method = bank_transfer)',

    `status` ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending'
        COMMENT 'Payout processing status',

    `periodStart` DATE NOT NULL
        COMMENT 'Start of the earnings period covered by this payout',

    `periodEnd` DATE NOT NULL
        COMMENT 'End of the earnings period covered by this payout',

    `transactionCount` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of transactions in this payout batch',

    `grossTotal` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Total gross amount before fees',

    `feesTotal` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Total fees deducted',

    `failureReason` VARCHAR(500) DEFAULT NULL
        COMMENT 'Reason for payout failure',

    `processedAt` DATETIME DEFAULT NULL
        COMMENT 'When the payout was processed',

    `processedBy` INT UNSIGNED DEFAULT NULL
        COMMENT 'Admin userID who processed the payout',

    `metadata` JSON DEFAULT NULL
        COMMENT 'Additional payout metadata',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`remittanceID`),
    INDEX `idx_remit_partner` (`partnerID`),
    INDEX `idx_remit_status` (`status`),
    INDEX `idx_remit_period` (`periodStart`, `periodEnd`),
    INDEX `idx_remit_created` (`createdAt`),
    CONSTRAINT `fk_remit_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner payout/remittance tracking';


-- ============================================================================
-- 💳 CREDIT / BALANCE SYSTEM
-- ============================================================================

-- 💰 Credit balances for users and partners
-- Supports pre-loading credits at both SIGNula level and per-partner context
-- ownerType + ownerID + partnerID triple determines scope:
--   - partner balance at SIGNula: ownerType='partner', ownerID=<partnerID>, partnerID=NULL
--   - user credits within partner: ownerType='user', ownerID=<userID>, partnerID=<partnerID>
--   - user credits at SIGNula:    ownerType='user', ownerID=<userID>, partnerID=NULL
CREATE TABLE IF NOT EXISTS `tblCreditBalances` (
    `balanceID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique balance record ID',

    `ownerType` ENUM('user', 'partner') NOT NULL
        COMMENT 'Type of balance owner',

    `ownerID` INT UNSIGNED NOT NULL
        COMMENT 'userID or partnerID depending on ownerType',

    `partnerID` INT UNSIGNED DEFAULT NULL
        COMMENT 'Partner context (NULL = SIGNula-level balance)',

    `balance` DECIMAL(12, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Current credit balance',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Balance currency (ISO 4217)',

    `lastTransactionAt` DATETIME DEFAULT NULL
        COMMENT 'Timestamp of the most recent transaction on this balance',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`balanceID`),
    UNIQUE KEY `uk_owner_partner_currency` (`ownerType`, `ownerID`, COALESCE(`partnerID`, 0), `currency`),
    INDEX `idx_cb_owner` (`ownerType`, `ownerID`),
    INDEX `idx_cb_partner` (`partnerID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Credit balances for users and partners (preloading support)';


-- 📝 Credit transaction audit trail
-- Every credit change is logged with before/after balances for full accountability
-- CRITICAL: deposit() and withdraw() methods MUST use SELECT ... FOR UPDATE
-- to prevent race conditions on concurrent balance updates
CREATE TABLE IF NOT EXISTS `tblCreditTransactions` (
    `creditTransactionID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique credit transaction ID',

    `balanceID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblCreditBalances',

    `type` ENUM('deposit', 'withdrawal', 'payment', 'refund', 'adjustment', 'remittance', 'promotional') NOT NULL
        COMMENT 'Transaction type',

    `amount` DECIMAL(12, 2) NOT NULL
        COMMENT 'Transaction amount (positive for deposits, negative for withdrawals)',

    `balanceBefore` DECIMAL(12, 2) NOT NULL
        COMMENT 'Balance before this transaction',

    `balanceAfter` DECIMAL(12, 2) NOT NULL
        COMMENT 'Balance after this transaction',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Transaction currency (ISO 4217)',

    `referenceType` VARCHAR(50) DEFAULT NULL
        COMMENT 'Type of linked record (payment, subscription, remittance, manual, topup)',

    `referenceID` INT UNSIGNED DEFAULT NULL
        COMMENT 'ID in the referenced table',

    `description` VARCHAR(500) DEFAULT NULL
        COMMENT 'Human-readable transaction description',

    `performedBy` INT UNSIGNED DEFAULT NULL
        COMMENT 'userID who triggered this transaction',

    `ipAddress` VARCHAR(45) DEFAULT NULL
        COMMENT 'IP address of the requester (IPv4 or IPv6)',

    `metadata` JSON DEFAULT NULL
        COMMENT 'Additional transaction metadata',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`creditTransactionID`),
    INDEX `idx_ct_balance` (`balanceID`),
    INDEX `idx_ct_type` (`type`),
    INDEX `idx_ct_reference` (`referenceType`, `referenceID`),
    INDEX `idx_ct_created` (`createdAt`),
    INDEX `idx_ct_performed_by` (`performedBy`),
    CONSTRAINT `fk_ct_balance` FOREIGN KEY (`balanceID`)
        REFERENCES `tblCreditBalances` (`balanceID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Credit balance transaction audit trail';


-- ============================================================================
-- 🧾 INVOICE SYSTEM
-- ============================================================================

-- 📄 Proper invoice records with PDF support
-- Generates formatted invoices for all payments with line items and tax breakdown
-- PDF generated via TCPDF library stored at web/private_html/invoices/
-- @see TCPDF: https://tcpdf.org/ (Pure PHP PDF generation, no Composer needed)
CREATE TABLE IF NOT EXISTS `tblInvoices` (
    `invoiceID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique invoice record ID',

    `invoiceNumber` VARCHAR(50) NOT NULL
        COMMENT 'Formatted invoice number (e.g. SIG-20260213-00001)',

    `paymentID` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to tblPayments — linked payment (NULL for proforma invoices)',

    `userID` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to tblUsers — the user being invoiced',

    `partnerID` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to tblPartners — the partner being invoiced (or partner context)',

    -- 📋 Invoice parties
    `billedToName` VARCHAR(255) NOT NULL
        COMMENT 'Name of the person/org being billed',

    `billedToEmail` VARCHAR(320) NOT NULL
        COMMENT 'Email of the person/org being billed',

    `billedToAddress` JSON DEFAULT NULL
        COMMENT 'Billing address (line1, line2, city, state, postcode, country)',

    `billedToVATNumber` VARCHAR(50) DEFAULT NULL
        COMMENT 'Customer VAT/tax registration number',

    `billedByName` VARCHAR(255) NOT NULL DEFAULT 'MWBM Partners Ltd (t/a MWservices)'
        COMMENT 'Name of the invoicing entity',

    `billedByAddress` JSON DEFAULT NULL
        COMMENT 'Invoicing entity address',

    `billedByVATNumber` VARCHAR(50) DEFAULT NULL
        COMMENT 'Invoicing entity VAT/tax registration number',

    -- 💰 Amounts
    `subtotal` DECIMAL(10, 2) NOT NULL
        COMMENT 'Subtotal before discounts and tax',

    `discountAmount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Total discount applied',

    `taxRate` DECIMAL(5, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Tax/VAT rate applied (%)',

    `taxAmount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Tax/VAT amount',

    `total` DECIMAL(10, 2) NOT NULL
        COMMENT 'Grand total (subtotal - discount + tax)',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Invoice currency (ISO 4217)',

    -- 📋 Line items
    `lineItems` JSON NOT NULL
        COMMENT 'Array of [{description, quantity, unitPrice, amount}]',

    -- 📊 Status
    `status` ENUM('draft', 'issued', 'sent', 'paid', 'overdue', 'cancelled', 'void') NOT NULL DEFAULT 'draft'
        COMMENT 'Invoice lifecycle status',

    `issuedAt` DATETIME DEFAULT NULL
        COMMENT 'When invoice was officially issued',

    `dueDate` DATE DEFAULT NULL
        COMMENT 'Payment due date',

    `paidAt` DATETIME DEFAULT NULL
        COMMENT 'When invoice was paid',

    -- 📄 PDF
    `pdfPath` VARCHAR(500) DEFAULT NULL
        COMMENT 'Relative path to generated PDF file',

    `pdfGeneratedAt` DATETIME DEFAULT NULL
        COMMENT 'When PDF was last generated',

    -- 📝 Notes
    `notes` TEXT DEFAULT NULL
        COMMENT 'Customer-visible invoice notes',

    `internalNotes` TEXT DEFAULT NULL
        COMMENT 'Internal notes (not shown to customer)',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`invoiceID`),
    UNIQUE KEY `uk_invoice_number` (`invoiceNumber`),
    INDEX `idx_inv_payment` (`paymentID`),
    INDEX `idx_inv_user` (`userID`),
    INDEX `idx_inv_partner` (`partnerID`),
    INDEX `idx_inv_status` (`status`),
    INDEX `idx_inv_due_date` (`dueDate`),
    INDEX `idx_inv_issued` (`issuedAt`),
    INDEX `idx_inv_created` (`createdAt`),
    CONSTRAINT `fk_inv_payment` FOREIGN KEY (`paymentID`)
        REFERENCES `tblPayments` (`paymentID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Invoice records with PDF generation support';


-- ============================================================================
-- 🎁 PROVIDER-SPECIFIC DISCOUNTS
-- ============================================================================

-- 💸 Payment method-specific discount rates
-- Supports discounts at SIGNula level (global) and per-partner level
-- Example: 10% off crypto payments, 3% off PayPal, 1% off Stripe Link
CREATE TABLE IF NOT EXISTS `tblProviderDiscounts` (
    `discountID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique provider discount ID',

    `scope` ENUM('global', 'partner') NOT NULL DEFAULT 'global'
        COMMENT 'global = SIGNula-level discount, partner = per-partner discount for their customers',

    `partnerID` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to tblPartners (NULL for global discounts)',

    `paymentMethod` VARCHAR(50) NOT NULL
        COMMENT 'Payment method this discount applies to (crypto, crypto_btc, crypto_eth, paypal, stripe, link, apple_pay, google_pay)',

    `discountPercentage` DECIMAL(5, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Discount percentage (e.g. 10.00 = 10% off)',

    `description` VARCHAR(255) DEFAULT NULL
        COMMENT 'Human-readable description of this discount',

    `isActive` TINYINT(1) NOT NULL DEFAULT 1,

    `createdBy` INT UNSIGNED DEFAULT NULL
        COMMENT 'Admin/partner admin userID who created this',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`discountID`),
    UNIQUE KEY `uk_scope_partner_method` (`scope`, `partnerID`, `paymentMethod`),
    INDEX `idx_pd_active` (`isActive`),
    INDEX `idx_pd_partner` (`partnerID`),
    INDEX `idx_pd_method` (`paymentMethod`),
    CONSTRAINT `fk_pd_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Payment method-specific discounts (e.g. 10% off crypto, 3% off PayPal)';


-- ============================================================================
-- 🏷️ PARTNER-DEFINED SUBSCRIPTION TIERS
-- ============================================================================

-- 📊 Partner-defined subscription tiers for THEIR customers
-- Structurally mirrors tblSubscriptionTiers but scoped to individual partners
-- Allows partners to define custom pricing, features, and limits
CREATE TABLE IF NOT EXISTS `tblPartnerSubscriptionTiers` (
    `tierID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique partner tier ID',

    `partnerID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblPartners — the partner who owns this tier',

    `tierName` VARCHAR(100) NOT NULL
        COMMENT 'Display name (e.g. Free, Starter, Professional)',

    `tierSlug` VARCHAR(50) NOT NULL
        COMMENT 'URL-safe identifier (e.g. free, starter, professional)',

    `tierDescription` TEXT DEFAULT NULL
        COMMENT 'Description of tier benefits',

    `monthlyPrice` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Monthly subscription price',

    `yearlyPrice` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Annual subscription price (typically discounted)',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Pricing currency (ISO 4217)',

    `features` JSON NOT NULL
        COMMENT 'Array of feature strings included in this tier',

    `featureLimits` JSON DEFAULT NULL
        COMMENT 'Object of numeric limits (e.g. {"api_calls": 1000, "storage_gb": 5})',

    `displayOrder` INT NOT NULL DEFAULT 0
        COMMENT 'Sort order for pricing page display',

    `isActive` TINYINT(1) NOT NULL DEFAULT 1,

    `isDefault` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Default tier for new users of this partner (typically free tier)',

    `trialDays` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Free trial period in days (0 = no trial)',

    `badge` VARCHAR(50) DEFAULT NULL
        COMMENT 'Badge text (e.g. Popular, Best Value)',

    `badgeColor` VARCHAR(20) DEFAULT NULL
        COMMENT 'Badge CSS color class',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`tierID`),
    UNIQUE KEY `uk_partner_tier_slug` (`partnerID`, `tierSlug`),
    INDEX `idx_pst_partner` (`partnerID`),
    INDEX `idx_pst_active` (`partnerID`, `isActive`),
    INDEX `idx_pst_default` (`partnerID`, `isDefault`),
    CONSTRAINT `fk_pst_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner-defined subscription tiers for their own customers';


-- ============================================================================
-- 🔔 DISCOUNT CODE ASSIGNMENTS
-- ============================================================================

-- 🎯 Per-account discount code assignments
-- Links specific discount codes to individual users/emails
-- Used when codeType = 'assigned' in tblDiscountCodes
CREATE TABLE IF NOT EXISTS `tblDiscountCodeAssignments` (
    `assignmentID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique assignment ID',

    `discountID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblDiscountCodes — the discount code assigned',

    `assignedToEmail` VARCHAR(320) DEFAULT NULL
        COMMENT 'Specific email address this code is assigned to',

    `assignedToUserID` INT UNSIGNED DEFAULT NULL
        COMMENT 'Specific userID this code is assigned to (NULL if email-only)',

    `assignedBy` INT UNSIGNED DEFAULT NULL
        COMMENT 'Admin/partner admin userID who made this assignment',

    `usedAt` DATETIME DEFAULT NULL
        COMMENT 'When the assigned code was redeemed (NULL if unused)',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`assignmentID`),
    INDEX `idx_dca_discount` (`discountID`),
    INDEX `idx_dca_email` (`assignedToEmail`),
    INDEX `idx_dca_user` (`assignedToUserID`),
    CONSTRAINT `fk_dca_discount` FOREIGN KEY (`discountID`)
        REFERENCES `tblDiscountCodes` (`discountID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Per-account discount code assignments (assigned codes)';


-- ============================================================================
-- ⏰ BILLING SCHEDULER
-- ============================================================================

-- 📋 Scheduled billing tasks
-- Replaces CLI cron jobs for automated billing actions
-- Processed via web-accessible cron endpoint (/cron/billing.php?token=<secret>)
-- and lazy-check safety net on authenticated page loads
-- Three-layer redundancy: MySQL events + web cron + lazy checks
CREATE TABLE IF NOT EXISTS `tblBillingSchedule` (
    `scheduleID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique schedule entry ID',

    `taskType` ENUM(
        'charge_subscription',
        'suspend_subscription',
        'resume_subscription',
        'send_reminder',
        'expire_trial',
        'generate_invoice',
        'process_remittance',
        'fee_change_notification'
    ) NOT NULL
        COMMENT 'Type of billing task to execute',

    `targetType` ENUM('subscription', 'partner', 'user', 'remittance', 'fee') NOT NULL
        COMMENT 'Type of target entity',

    `targetID` INT UNSIGNED NOT NULL
        COMMENT 'ID of the target entity (subscriptionID, partnerID, userID, etc.)',

    `scheduledFor` DATETIME NOT NULL
        COMMENT 'When this task should be executed',

    `status` ENUM('pending', 'processing', 'completed', 'failed', 'skipped') NOT NULL DEFAULT 'pending'
        COMMENT 'Task execution status',

    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of execution attempts',

    `maxAttempts` TINYINT UNSIGNED NOT NULL DEFAULT 3
        COMMENT 'Maximum retry attempts before marking as failed',

    `lastAttemptAt` DATETIME DEFAULT NULL
        COMMENT 'When the last execution attempt was made',

    `completedAt` DATETIME DEFAULT NULL
        COMMENT 'When the task completed successfully',

    `errorMessage` TEXT DEFAULT NULL
        COMMENT 'Error details from the last failed attempt',

    `metadata` JSON DEFAULT NULL
        COMMENT 'Task-specific data (e.g. reminder days, subscription details)',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`scheduleID`),
    INDEX `idx_bs_scheduled` (`status`, `scheduledFor`),
    INDEX `idx_bs_task_type` (`taskType`),
    INDEX `idx_bs_target` (`targetType`, `targetID`),
    INDEX `idx_bs_status` (`status`),
    INDEX `idx_bs_created` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Scheduled billing tasks (web-triggered cron replacement)';


-- ============================================================================
-- 🔧 ALTER EXISTING TABLES
-- ============================================================================

-- 💳 tblPayments — Add partner context for Level 2 payments
-- @see Migration 010 for original tblPayments definition
ALTER TABLE `tblPayments`
ALTER TABLE `tblPayments`
    ADD COLUMN `partnerID` INT UNSIGNED DEFAULT NULL
    ADD COLUMN `partnerID` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to tblPartners — partner who owns this payment (Level 2)'
        COMMENT 'FK to tblPartners — partner who owns this payment (Level 2)'
        AFTER `userID`,
        AFTER `userID`,
    ADD COLUMN `paymentContext` ENUM('signula_direct', 'partner_own_keys', 'partner_signula_keys') NOT NULL DEFAULT 'signula_direct'
    ADD COLUMN `paymentContext` ENUM('signula_direct', 'partner_own_keys', 'partner_signula_keys') NOT NULL DEFAULT 'signula_direct'
        COMMENT 'Which payment flow: signula_direct (Level 1), partner_own_keys (2a), partner_signula_keys (2b)'
        COMMENT 'Which payment flow: signula_direct (Level 1), partner_own_keys (2a), partner_signula_keys (2b)'
        AFTER `subscriptionID`,
        AFTER `subscriptionID`,
    ADD INDEX `idx_payment_partner` (`partnerID`),
    ADD INDEX `idx_payment_partner` (`partnerID`),
    ADD INDEX `idx_payment_context` (`paymentContext`);
    ADD INDEX `idx_payment_context` (`paymentContext`);


-- 🎁 tblDiscountCodes — Add partner ownership, country restrictions, code type
-- @see Migration 010 for original tblDiscountCodes definition
ALTER TABLE `tblDiscountCodes`
ALTER TABLE `tblDiscountCodes`
    ADD COLUMN `partnerID` INT UNSIGNED DEFAULT NULL
    ADD COLUMN `partnerID` INT UNSIGNED DEFAULT NULL
        COMMENT 'NULL = SIGNula global code, non-NULL = partner-specific code'
        COMMENT 'NULL = SIGNula global code, non-NULL = partner-specific code'
        AFTER `discountID`,
        AFTER `discountID`,
    ADD COLUMN `codeType` ENUM('universal', 'assigned') NOT NULL DEFAULT 'universal'
    ADD COLUMN `codeType` ENUM('universal', 'assigned') NOT NULL DEFAULT 'universal'
        COMMENT 'universal = anyone can use, assigned = specific accounts only (see tblDiscountCodeAssignments)'
        COMMENT 'universal = anyone can use, assigned = specific accounts only (see tblDiscountCodeAssignments)'
        AFTER `code`,
        AFTER `code`,
    ADD COLUMN `allowedCountries` JSON DEFAULT NULL
    ADD COLUMN `allowedCountries` JSON DEFAULT NULL
        COMMENT 'JSON array of ISO 3166-1 alpha-2 country codes allowed to use this code (NULL = all countries)'
        COMMENT 'JSON array of ISO 3166-1 alpha-2 country codes allowed to use this code (NULL = all countries)'
        AFTER `applicablePaymentMethods`,
        AFTER `applicablePaymentMethods`,
    ADD COLUMN `blockedCountries` JSON DEFAULT NULL
    ADD COLUMN `blockedCountries` JSON DEFAULT NULL
        COMMENT 'JSON array of ISO 3166-1 alpha-2 country codes blocked from using this code'
        COMMENT 'JSON array of ISO 3166-1 alpha-2 country codes blocked from using this code'
        AFTER `allowedCountries`,
        AFTER `allowedCountries`,
    ADD INDEX `idx_discount_partner` (`partnerID`),
    ADD INDEX `idx_discount_partner` (`partnerID`),
    ADD INDEX `idx_discount_code_type` (`codeType`);
    ADD INDEX `idx_discount_code_type` (`codeType`);


-- 🏢 tblPartners — Add suspension tracking, credit balance, payout preferences
-- @see Migration 008 for original tblPartners definition
ALTER TABLE `tblPartners`
ALTER TABLE `tblPartners`
    ADD COLUMN `creditBalance` DECIMAL(12, 2) NOT NULL DEFAULT 0.00
    ADD COLUMN `creditBalance` DECIMAL(12, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Quick-access credit balance (authoritative: tblCreditBalances)'
        COMMENT 'Quick-access credit balance (authoritative: tblCreditBalances)'
        AFTER `paymentStatus`,
        AFTER `paymentStatus`,
    ADD COLUMN `suspendedAt` DATETIME DEFAULT NULL
    ADD COLUMN `suspendedAt` DATETIME DEFAULT NULL
        COMMENT 'When partner was auto-suspended for non-payment'
        COMMENT 'When partner was auto-suspended for non-payment'
        AFTER `creditBalance`,
        AFTER `creditBalance`,
    ADD COLUMN `suspendedTier` ENUM('free', 'basic', 'premium', 'enterprise') DEFAULT NULL
    ADD COLUMN `suspendedTier` ENUM('free', 'basic', 'premium', 'enterprise') DEFAULT NULL
        COMMENT 'Original tier before suspension (to restore on payment)'
        COMMENT 'Original tier before suspension (to restore on payment)'
        AFTER `suspendedAt`,
        AFTER `suspendedAt`,
    ADD COLUMN `lastPaymentAt` DATETIME DEFAULT NULL
    ADD COLUMN `lastPaymentAt` DATETIME DEFAULT NULL
        COMMENT 'When the partner last made a payment'
        COMMENT 'When the partner last made a payment'
        AFTER `suspendedTier`,
        AFTER `suspendedTier`,
    ADD COLUMN `nextPaymentDue` DATE DEFAULT NULL
    ADD COLUMN `nextPaymentDue` DATE DEFAULT NULL
        COMMENT 'Next payment due date (payments made 1 month in advance)'
        COMMENT 'Next payment due date (payments made 1 month in advance)'
        AFTER `lastPaymentAt`,
        AFTER `lastPaymentAt`,
    ADD COLUMN `payoutEmail` VARCHAR(320) DEFAULT NULL
    ADD COLUMN `payoutEmail` VARCHAR(320) DEFAULT NULL
        COMMENT 'Email for receiving partner payouts (PayPal, etc.)'
        COMMENT 'Email for receiving partner payouts (PayPal, etc.)'
        AFTER `billingEmail`,
        AFTER `billingEmail`,
    ADD COLUMN `payoutMethod` ENUM('paypal_payout', 'bank_transfer', 'credit_to_balance', 'manual') DEFAULT 'manual'
    ADD COLUMN `payoutMethod` ENUM('paypal_payout', 'bank_transfer', 'credit_to_balance', 'manual') DEFAULT 'manual'
        COMMENT 'Preferred payout method for partner remittances'
        COMMENT 'Preferred payout method for partner remittances'
        AFTER `payoutEmail`;
        AFTER `payoutEmail`;


-- 📋 tblSubscriptions — Add partner-defined tier reference
-- Allows subscriptions to reference partner-defined tiers instead of global tiers
ALTER TABLE `tblSubscriptions`
ALTER TABLE `tblSubscriptions`
    ADD COLUMN `partnerTierID` INT UNSIGNED DEFAULT NULL
    ADD COLUMN `partnerTierID` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to tblPartnerSubscriptionTiers (NULL = uses global tblSubscriptionTiers.tierID)'
        COMMENT 'FK to tblPartnerSubscriptionTiers (NULL = uses global tblSubscriptionTiers.tierID)'
        AFTER `tierID`;
        AFTER `tierID`;


-- ============================================================================
-- ⚙️ SETTINGS
-- ============================================================================

-- 💰 Service fee settings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `settingDescription`, `isSensitive`)
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `settingDescription`, `isSensitive`)
VALUES
VALUES
    -- 💰 Service Fees
    -- 💰 Service Fees
    ('payment.service_fee.own_keys_percent', '10.00', 'payment', 'Service fee (%) charged when partner uses their own payment keys (Option 2a)', 0),
    ('payment.service_fee.own_keys_percent', '10.00', 'payment', 'Service fee (%) charged when partner uses their own payment keys (Option 2a)', 0),
    ('payment.service_fee.signula_keys_percent', '30.00', 'payment', 'Service fee (%) charged when partner uses SIGNula payment keys (Option 2b)', 0),
    ('payment.service_fee.signula_keys_percent', '30.00', 'payment', 'Service fee (%) charged when partner uses SIGNula payment keys (Option 2b)', 0),
    ('payment.service_fee.minimum_notice_days', '30', 'payment', 'Minimum calendar days notice before fee changes take effect', 0),
    ('payment.service_fee.minimum_notice_days', '30', 'payment', 'Minimum calendar days notice before fee changes take effect', 0),


    -- 💳 Credit System
    -- 💳 Credit System
    ('payment.credits.enabled', '1', 'payment', 'Enable credit/balance pre-loading system', 0),
    ('payment.credits.enabled', '1', 'payment', 'Enable credit/balance pre-loading system', 0),
    ('payment.credits.min_topup', '10.00', 'payment', 'Minimum credit top-up amount', 0),
    ('payment.credits.min_topup', '10.00', 'payment', 'Minimum credit top-up amount', 0),
    ('payment.credits.max_balance', '50000.00', 'payment', 'Maximum credit balance allowed per account', 0),
    ('payment.credits.max_balance', '50000.00', 'payment', 'Maximum credit balance allowed per account', 0),


    -- 🧾 Invoice Settings
    -- 🧾 Invoice Settings
    ('payment.invoice.company_name', 'MWBM Partners Ltd (t/a MWservices)', 'payment', 'Company name displayed on invoices', 0),
    ('payment.invoice.company_name', 'MWBM Partners Ltd (t/a MWservices)', 'payment', 'Company name displayed on invoices', 0),
    ('payment.invoice.company_address', '{}', 'payment', 'Company address for invoices (JSON: line1, line2, city, state, postcode, country)', 0),
    ('payment.invoice.company_address', '{}', 'payment', 'Company address for invoices (JSON: line1, line2, city, state, postcode, country)', 0),
    ('payment.invoice.vat_number', '', 'payment', 'Company VAT registration number for invoices', 0),
    ('payment.invoice.vat_number', '', 'payment', 'Company VAT registration number for invoices', 0),
    ('payment.invoice.payment_terms_days', '30', 'payment', 'Default payment terms in days from invoice date', 0),
    ('payment.invoice.payment_terms_days', '30', 'payment', 'Default payment terms in days from invoice date', 0),
    ('payment.invoice.footer_text', 'Thank you for your business.', 'payment', 'Footer text displayed at bottom of invoices', 0),
    ('payment.invoice.footer_text', 'Thank you for your business.', 'payment', 'Footer text displayed at bottom of invoices', 0),
    ('payment.invoice.logo_path', '', 'payment', 'Relative path to company logo for PDF invoices', 0),
    ('payment.invoice.logo_path', '', 'payment', 'Relative path to company logo for PDF invoices', 0),


    -- ⏸️ Auto-Suspension Settings
    -- ⏸️ Auto-Suspension Settings
    ('payment.auto_suspend.enabled', '1', 'payment', 'Auto-suspend partner accounts on non-payment', 0),
    ('payment.auto_suspend.enabled', '1', 'payment', 'Auto-suspend partner accounts on non-payment', 0),
    ('payment.auto_suspend.grace_period_days', '7', 'payment', 'Grace period (days) after missed payment before suspension', 0),
    ('payment.auto_suspend.grace_period_days', '7', 'payment', 'Grace period (days) after missed payment before suspension', 0),
    ('payment.auto_suspend.reminder_days', '7,3,1', 'payment', 'Days before due date to send payment reminders (comma-separated)', 0),
    ('payment.auto_suspend.reminder_days', '7,3,1', 'payment', 'Days before due date to send payment reminders (comma-separated)', 0),


    -- 💸 Remittance Settings
    -- 💸 Remittance Settings
    ('payment.remittance.auto_enabled', '0', 'payment', 'Auto-process partner payouts (0 = manual approval required)', 0),
    ('payment.remittance.auto_enabled', '0', 'payment', 'Auto-process partner payouts (0 = manual approval required)', 0),
    ('payment.remittance.min_amount', '50.00', 'payment', 'Minimum accumulated amount before partner payout', 0),
    ('payment.remittance.min_amount', '50.00', 'payment', 'Minimum accumulated amount before partner payout', 0),
    ('payment.remittance.schedule', 'monthly', 'payment', 'Payout schedule frequency: weekly, biweekly, monthly', 0),
    ('payment.remittance.schedule', 'monthly', 'payment', 'Payout schedule frequency: weekly, biweekly, monthly', 0),


    -- 🎁 Provider Discount Defaults
    -- 🎁 Provider Discount Defaults
    ('payment.provider_discount.crypto_percent', '5.00', 'payment', 'Default discount for cryptocurrency payments (%)', 0),
    ('payment.provider_discount.crypto_percent', '5.00', 'payment', 'Default discount for cryptocurrency payments (%)', 0),
    ('payment.provider_discount.paypal_percent', '0.00', 'payment', 'Default discount for PayPal payments (%)', 0),
    ('payment.provider_discount.paypal_percent', '0.00', 'payment', 'Default discount for PayPal payments (%)', 0),
    ('payment.provider_discount.stripe_percent', '0.00', 'payment', 'Default discount for Stripe card payments (%)', 0),
    ('payment.provider_discount.stripe_percent', '0.00', 'payment', 'Default discount for Stripe card payments (%)', 0),
    ('payment.provider_discount.link_percent', '0.00', 'payment', 'Default discount for Stripe Link payments (%)', 0),
    ('payment.provider_discount.link_percent', '0.00', 'payment', 'Default discount for Stripe Link payments (%)', 0),


    -- ⏰ Billing Cron Settings
    -- ⏰ Billing Cron Settings
    ('payment.cron.secret_token', '', 'payment', 'Secret token for authenticating cron endpoint requests (/cron/billing.php?token=xxx)', 1),
    ('payment.cron.secret_token', '', 'payment', 'Secret token for authenticating cron endpoint requests (/cron/billing.php?token=xxx)', 1),
    ('payment.cron.last_run', '', 'payment', 'Timestamp of last successful billing cron execution', 0),
    ('payment.cron.last_run', '', 'payment', 'Timestamp of last successful billing cron execution', 0),
    ('payment.advance_billing_months', '1', 'payment', 'How many months in advance to bill subscriptions', 0),
    ('payment.advance_billing_months', '1', 'payment', 'How many months in advance to bill subscriptions', 0),


    -- 🔗 Stripe Connect (for Option 2b ToS compliance)
    -- 🔗 Stripe Connect (for Option 2b ToS compliance)
    -- @see https://docs.stripe.com/connect
    -- @see https://docs.stripe.com/connect
    ('payment.stripe.connect_enabled', '0', 'payment', 'Enable Stripe Connect for marketplace/platform payments (required for Option 2b)', 0),
    ('payment.stripe.connect_enabled', '0', 'payment', 'Enable Stripe Connect for marketplace/platform payments (required for Option 2b)', 0),
    ('payment.stripe.connect_account_id', '', 'payment', 'SIGNula platform Stripe Connect account ID', 1),
    ('payment.stripe.connect_account_id', '', 'payment', 'SIGNula platform Stripe Connect account ID', 1),


    -- 🔗 PayPal Commerce Platform (for Option 2b ToS compliance)
    -- 🔗 PayPal Commerce Platform (for Option 2b ToS compliance)
    -- @see https://developer.paypal.com/docs/platforms/
    -- @see https://developer.paypal.com/docs/platforms/
    ('payment.paypal.commerce_platform_enabled', '0', 'payment', 'Enable PayPal Commerce Platform for marketplace payments (required for Option 2b)', 0),
    ('payment.paypal.commerce_platform_enabled', '0', 'payment', 'Enable PayPal Commerce Platform for marketplace payments (required for Option 2b)', 0),
    ('payment.paypal.partner_id', '', 'payment', 'PayPal Partner/Platform Merchant ID', 1)
    ('payment.paypal.partner_id', '', 'payment', 'PayPal Partner/Platform Merchant ID', 1)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);


-- ============================================================================
-- 🔀 FEATURE TOGGLES
-- ============================================================================

-- 🏷️ New feature toggles for billing capabilities
INSERT INTO `tblFeatureToggles` (`featureKey`, `featureName`, `featureDescription`, `isEnabledGlobally`, `category`, `requiresMigration`, `affectsAPI`, `canPartnersToggle`)
INSERT INTO `tblFeatureToggles` (`featureKey`, `featureName`, `featureDescription`, `isEnabledGlobally`, `category`, `requiresMigration`, `affectsAPI`, `canPartnersToggle`)
VALUES
VALUES
    ('partner_payments', 'Partner Payment Collection', 'Allow partners to collect payments from their customers via SIGNula', 1, 'billing', '012', 1, 0),
    ('partner_payments', 'Partner Payment Collection', 'Allow partners to collect payments from their customers via SIGNula', 1, 'billing', '012', 1, 0),
    ('credit_system', 'Credit/Balance System', 'Allow users and partners to preload and use account credits', 1, 'billing', '012', 1, 1),
    ('credit_system', 'Credit/Balance System', 'Allow users and partners to preload and use account credits', 1, 'billing', '012', 1, 1),
    ('partner_custom_tiers', 'Partner Custom Tiers', 'Allow partners to define their own subscription tiers for their customers', 1, 'billing', '012', 1, 0),
    ('partner_custom_tiers', 'Partner Custom Tiers', 'Allow partners to define their own subscription tiers for their customers', 1, 'billing', '012', 1, 0),
    ('pdf_invoices', 'PDF Invoice Generation', 'Generate PDF invoices for payments and send via email', 1, 'billing', '012', 0, 0)
    ('pdf_invoices', 'PDF Invoice Generation', 'Generate PDF invoices for payments and send via email', 1, 'billing', '012', 0, 0)
ON DUPLICATE KEY UPDATE `featureKey` = VALUES(`featureKey`);
ON DUPLICATE KEY UPDATE `featureKey` = VALUES(`featureKey`);


-- ============================================================================
-- 📧 EMAIL TEMPLATES
-- ============================================================================

-- 📨 Payment lifecycle email templates
-- Uses {{variableName}} syntax for variable substitution via EmailService
INSERT IGNORE INTO `tblEmailTemplates`
    (`templateKey`, `templateName`, `subject`, `bodyHTML`, `bodyText`, `category`, `trackingEnabled`, `requiredVariables`, `isActive`)
VALUES

-- ✅ Payment receipt (sent after successful payment)
('payment_receipt', 'Payment Receipt',
'Payment Receipt #{{invoiceNumber}} - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#198754;">✅ Payment Receipt</h1><p>Hi {{displayName}},</p><p>We have received your payment. Thank you!</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Invoice #:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{invoiceNumber}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Amount:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{amount}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Date:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{paymentDate}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Description:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{description}}</td></tr></table><p><a href="{{invoiceURL}}" style="display:inline-block;padding:10px 24px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px;">View Invoice</a></p><p style="color:#6c757d;font-size:0.9em;">If you have any questions about this payment, please contact our support team.</p></div>',
'Hi {{displayName}}, Payment of {{currency}} {{amount}} received. Invoice #{{invoiceNumber}}. View at: {{invoiceURL}}',
'transactional', 1,
'["displayName","currency","amount","invoiceNumber","paymentDate","description","invoiceURL"]',
1),

-- ❌ Payment failed notification
('payment_failed', 'Payment Failed',
'Payment Failed — Action Required - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#dc3545;">❌ Payment Failed</h1><p>Hi {{displayName}},</p><p>We were unable to process your payment of <strong>{{currency}} {{amount}}</strong>.</p><p><strong>Reason:</strong> {{failureReason}}</p><p>Please update your payment method to avoid service interruption:</p><p><a href="{{updateURL}}" style="display:inline-block;padding:10px 24px;background:#dc3545;color:#fff;text-decoration:none;border-radius:6px;">Update Payment Method</a></p></div>',
'Hi {{displayName}}, Payment of {{currency}} {{amount}} failed. Reason: {{failureReason}}. Update at: {{updateURL}}',
'transactional', 0,
'["displayName","currency","amount","failureReason","updateURL"]',
1),

-- ⏰ Payment reminder (sent before subscription renewal)
('payment_reminder', 'Payment Reminder',
'Payment Reminder — {{daysUntilDue}} Days Until Renewal - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#0dcaf0;">⏰ Payment Reminder</h1><p>Hi {{displayName}},</p><p>Your <strong>{{tierName}}</strong> subscription will renew in <strong>{{daysUntilDue}} days</strong>.</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Plan:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{tierName}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Amount:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{amount}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Renewal Date:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{renewalDate}}</td></tr></table><p>No action is required if your payment method is up to date.</p></div>',
'Hi {{displayName}}, Your {{tierName}} subscription renews in {{daysUntilDue}} days for {{currency}} {{amount}} on {{renewalDate}}.',
'transactional', 0,
'["displayName","tierName","daysUntilDue","currency","amount","renewalDate"]',
1),

-- ⏸️ Subscription suspended (auto-suspend on non-payment)
('subscription_suspended', 'Subscription Suspended',
'Account Suspended — Payment Required - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#ffc107;">⏸️ Account Suspended</h1><p>Hi {{displayName}},</p><p>Your <strong>{{tierName}}</strong> subscription has been suspended due to non-payment.</p><p>Your account has been downgraded to the <strong>Free</strong> tier. To restore your {{tierName}} features, please make a payment:</p><p><a href="{{paymentURL}}" style="display:inline-block;padding:10px 24px;background:#ffc107;color:#000;text-decoration:none;border-radius:6px;">Make Payment Now</a></p><p style="color:#6c757d;font-size:0.9em;">Your {{tierName}} features will be restored immediately upon payment.</p></div>',
'Hi {{displayName}}, Your {{tierName}} subscription is suspended due to non-payment. Downgraded to Free tier. Pay at: {{paymentURL}}',
'transactional', 0,
'["displayName","tierName","paymentURL"]',
1),

-- ✅ Subscription restored (auto-resume on payment)
('subscription_restored', 'Subscription Restored',
'Account Restored — {{tierName}} Plan Active - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#198754;">✅ Account Restored</h1><p>Hi {{displayName}},</p><p>Thank you for your payment! Your <strong>{{tierName}}</strong> subscription has been restored and all features are active again.</p><p><a href="{{dashboardURL}}" style="display:inline-block;padding:10px 24px;background:#198754;color:#fff;text-decoration:none;border-radius:6px;">Go to Dashboard</a></p></div>',
'Hi {{displayName}}, Your {{tierName}} subscription has been restored. All features are active.',
'transactional', 0,
'["displayName","tierName","dashboardURL"]',
1),

-- 📢 Service fee change notification (sent to all partners)
('service_fee_change', 'Service Fee Change Notice',
'Important: Service Fee Update — SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#0d6efd;">📢 Service Fee Update</h1><p>Hi {{partnerName}},</p><p>We are writing to inform you of an upcoming change to our service fees for the <strong>{{feeTypeName}}</strong> payment option.</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Current fee:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currentFee}}%</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>New fee:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{newFee}}%</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Effective date:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{effectiveDate}}</td></tr></table><p>This change will take effect in <strong>{{noticeDays}} days</strong>, in compliance with our minimum {{minimumNoticeDays}}-day notice period.</p><p style="color:#6c757d;font-size:0.9em;">If you have any questions, please contact our support team.</p></div>',
'Hi {{partnerName}}, Service fee changing from {{currentFee}}% to {{newFee}}% effective {{effectiveDate}} ({{noticeDays}} days notice).',
'transactional', 1,
'["partnerName","feeTypeName","currentFee","newFee","effectiveDate","noticeDays","minimumNoticeDays"]',
1),

-- 💸 Remittance processed (partner payout notification)
('remittance_processed', 'Remittance Processed',
'Payout Processed — {{currency}} {{amount}} - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#198754;">💸 Payout Processed</h1><p>Hi {{partnerName}},</p><p>Your payout has been processed successfully.</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Period:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{periodStart}} — {{periodEnd}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Transactions:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{transactionCount}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Gross total:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{grossTotal}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Service fees:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{feesTotal}}</td></tr><tr><td style="padding:8px;border-bottom:2px solid #198754;"><strong>Net payout:</strong></td><td style="padding:8px;border-bottom:2px solid #198754;"><strong>{{currency}} {{amount}}</strong></td></tr></table><p><a href="{{earningsURL}}" style="display:inline-block;padding:10px 24px;background:#198754;color:#fff;text-decoration:none;border-radius:6px;">View Earnings Dashboard</a></p></div>',
'Hi {{partnerName}}, Payout of {{currency}} {{amount}} processed for {{periodStart}}-{{periodEnd}}. Gross: {{currency}} {{grossTotal}}, Fees: {{currency}} {{feesTotal}}.',
'transactional', 1,
'["partnerName","currency","amount","periodStart","periodEnd","transactionCount","grossTotal","feesTotal","earningsURL"]',
1),

-- 💰 Credit balance top-up confirmation
('credit_topup', 'Credit Balance Top-Up',
'Credits Added — {{currency}} {{amount}} - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#198754;">💰 Credits Added</h1><p>Hi {{displayName}},</p><p><strong>{{currency}} {{amount}}</strong> has been added to your credit balance.</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Amount added:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{amount}}</td></tr><tr><td style="padding:8px;border-bottom:2px solid #198754;"><strong>New balance:</strong></td><td style="padding:8px;border-bottom:2px solid #198754;"><strong>{{currency}} {{newBalance}}</strong></td></tr></table></div>',
'Hi {{displayName}}, {{currency}} {{amount}} credits added. New balance: {{currency}} {{newBalance}}.',
'transactional', 0,
'["displayName","currency","amount","newBalance"]',
1),

-- 🧾 Invoice issued notification
('invoice_issued', 'Invoice Issued',
'Invoice #{{invoiceNumber}} — {{currency}} {{total}} - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#0d6efd;">🧾 Invoice</h1><p>Hi {{displayName}},</p><p>A new invoice has been generated for your account.</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Invoice #:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{invoiceNumber}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Amount:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{total}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Due date:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{dueDate}}</td></tr></table><p><a href="{{invoiceURL}}" style="display:inline-block;padding:10px 24px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px;">View Invoice Online</a></p><p style="color:#6c757d;font-size:0.9em;">Payment is due within {{paymentTermsDays}} days of the invoice date.</p></div>',
'Hi {{displayName}}, Invoice #{{invoiceNumber}} for {{currency}} {{total}} due {{dueDate}}. View at: {{invoiceURL}}',
'transactional', 1,
'["displayName","invoiceNumber","currency","total","dueDate","invoiceURL","paymentTermsDays"]',
1);


-- ============================================================================
-- ⏰ MYSQL SCHEDULED EVENTS
-- ============================================================================

-- 🔴 Auto-mark subscriptions as past_due when nextBillingDate passes
-- Runs every hour to catch overdue subscriptions promptly
-- The BillingScheduler then handles reminders and suspension logic
CREATE EVENT IF NOT EXISTS `evt_mark_subscriptions_past_due`
CREATE EVENT IF NOT EXISTS `evt_mark_subscriptions_past_due`
ON SCHEDULE EVERY 1 HOUR
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
STARTS CURRENT_TIMESTAMP
DO
DO
    UPDATE `tblSubscriptions`
    UPDATE `tblSubscriptions`
    SET `subscriptionStatus` = 'past_due',
    SET `subscriptionStatus` = 'past_due',
        `updatedAt` = NOW()
        `updatedAt` = NOW()
    WHERE `subscriptionStatus` = 'active'
    WHERE `subscriptionStatus` = 'active'
      AND `nextBillingDate` IS NOT NULL
      AND `nextBillingDate` IS NOT NULL
      AND `nextBillingDate` < CURDATE()
      AND `nextBillingDate` < CURDATE()
      AND `autoRenew` = 1;
      AND `autoRenew` = 1;


-- ⏳ Auto-expire trial subscriptions when trial period ends
-- Runs every hour to catch expired trials
CREATE EVENT IF NOT EXISTS `evt_expire_trial_subscriptions`
CREATE EVENT IF NOT EXISTS `evt_expire_trial_subscriptions`
ON SCHEDULE EVERY 1 HOUR
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
STARTS CURRENT_TIMESTAMP
DO
DO
    UPDATE `tblSubscriptions`
    UPDATE `tblSubscriptions`
    SET `subscriptionStatus` = 'expired',
    SET `subscriptionStatus` = 'expired',
        `updatedAt` = NOW()
        `updatedAt` = NOW()
    WHERE `subscriptionStatus` = 'trial'
    WHERE `subscriptionStatus` = 'trial'
      AND `trialEndsAt` IS NOT NULL
      AND `trialEndsAt` IS NOT NULL
      AND `trialEndsAt` < NOW();
      AND `trialEndsAt` < NOW();


-- 📋 Auto-mark invoices as overdue when due date passes
-- Runs daily to update invoice statuses
CREATE EVENT IF NOT EXISTS `evt_mark_invoices_overdue`
CREATE EVENT IF NOT EXISTS `evt_mark_invoices_overdue`
ON SCHEDULE EVERY 1 DAY
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
STARTS CURRENT_TIMESTAMP
DO
DO
    UPDATE `tblInvoices`
    UPDATE `tblInvoices`
    SET `status` = 'overdue',
    SET `status` = 'overdue',
        `updatedAt` = NOW()
        `updatedAt` = NOW()
    WHERE `status` IN ('issued', 'sent')
    WHERE `status` IN ('issued', 'sent')
      AND `dueDate` IS NOT NULL
      AND `dueDate` IS NOT NULL
      AND `dueDate` < CURDATE();
      AND `dueDate` < CURDATE();


-- 🧹 Cleanup completed billing schedule tasks older than 90 days
CREATE EVENT IF NOT EXISTS `evt_cleanup_billing_schedule`
CREATE EVENT IF NOT EXISTS `evt_cleanup_billing_schedule`
ON SCHEDULE EVERY 1 DAY
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
STARTS CURRENT_TIMESTAMP
DO
DO
    DELETE FROM `tblBillingSchedule`
    DELETE FROM `tblBillingSchedule`
    WHERE `createdAt` < DATE_SUB(NOW(), INTERVAL 90 DAY)
    WHERE `createdAt` < DATE_SUB(NOW(), INTERVAL 90 DAY)
    AND `status` IN ('completed', 'skipped');
    AND `status` IN ('completed', 'skipped');


-- ============================================================================
-- 📊 DEFAULT SERVICE FEE SCHEDULES
-- ============================================================================

-- Insert default fee schedules (active immediately)
INSERT INTO `tblServiceFees` (`feeType`, `feePercentage`, `effectiveFrom`, `isActive`)
INSERT INTO `tblServiceFees` (`feeType`, `feePercentage`, `effectiveFrom`, `isActive`)
VALUES
VALUES
    ('partner_own_keys', 10.00, CURDATE(), 1),
    ('partner_own_keys', 10.00, CURDATE(), 1),
    ('partner_signula_keys', 30.00, CURDATE(), 1);
    ('partner_signula_keys', 30.00, CURDATE(), 1);


-- ============================================================================
-- 📊 DEFAULT PROVIDER DISCOUNTS
-- ============================================================================

-- Insert default global provider discounts
INSERT INTO `tblProviderDiscounts` (`scope`, `partnerID`, `paymentMethod`, `discountPercentage`, `description`, `isActive`)
INSERT INTO `tblProviderDiscounts` (`scope`, `partnerID`, `paymentMethod`, `discountPercentage`, `description`, `isActive`)
VALUES
VALUES
    ('global', NULL, 'crypto', 5.00, 'Default 5% discount for cryptocurrency payments', 1),
    ('global', NULL, 'crypto', 5.00, 'Default 5% discount for cryptocurrency payments', 1),
    ('global', NULL, 'crypto_btc', 5.00, 'Default 5% discount for Bitcoin payments', 1),
    ('global', NULL, 'crypto_btc', 5.00, 'Default 5% discount for Bitcoin payments', 1),
    ('global', NULL, 'crypto_eth', 5.00, 'Default 5% discount for Ethereum payments', 1),
    ('global', NULL, 'crypto_eth', 5.00, 'Default 5% discount for Ethereum payments', 1),
    ('global', NULL, 'crypto_usdt', 5.00, 'Default 5% discount for USDT payments', 1),
    ('global', NULL, 'crypto_usdt', 5.00, 'Default 5% discount for USDT payments', 1),
    ('global', NULL, 'crypto_usdc', 5.00, 'Default 5% discount for USDC payments', 1),
    ('global', NULL, 'crypto_usdc', 5.00, 'Default 5% discount for USDC payments', 1),
    ('global', NULL, 'paypal', 0.00, 'PayPal payment discount (disabled by default)', 0),
    ('global', NULL, 'paypal', 0.00, 'PayPal payment discount (disabled by default)', 0),
    ('global', NULL, 'stripe', 0.00, 'Stripe card payment discount (disabled by default)', 0),
    ('global', NULL, 'stripe', 0.00, 'Stripe card payment discount (disabled by default)', 0),
    ('global', NULL, 'link', 0.00, 'Stripe Link payment discount (disabled by default)', 0),
    ('global', NULL, 'link', 0.00, 'Stripe Link payment discount (disabled by default)', 0),
    ('global', NULL, 'apple_pay', 0.00, 'Apple Pay payment discount (disabled by default)', 0),
    ('global', NULL, 'apple_pay', 0.00, 'Apple Pay payment discount (disabled by default)', 0),
    ('global', NULL, 'google_pay', 0.00, 'Google Pay payment discount (disabled by default)', 0);
    ('global', NULL, 'google_pay', 0.00, 'Google Pay payment discount (disabled by default)', 0);


-- ============================================================================
-- 📋 MIGRATION TRACKING
-- ============================================================================

INSERT INTO `tblMigrations` (`migrationName`, `migrationVersion`, `description`)
INSERT INTO `tblMigrations` (`migrationName`, `migrationVersion`, `description`)
VALUES ('012_payment_expansion', '2.4.0-beta',
VALUES ('012_payment_expansion', '2.4.0-beta',
    'Two-tier payment expansion: partner payment config, service fees, remittances, '
    'Two-tier payment expansion: partner payment config, service fees, remittances, '
    'credit/balance system, invoices, provider discounts, partner tiers, billing scheduler, '
    'credit/balance system, invoices, provider discounts, partner tiers, billing scheduler, '
    'discount code enhancements (country restrictions, assigned codes), '
    'discount code enhancements (country restrictions, assigned codes), '
    '9 email templates, 4 feature toggles, 3 MySQL scheduled events')
    '9 email templates, 4 feature toggles, 3 MySQL scheduled events')
ON DUPLICATE KEY UPDATE `migrationName` = VALUES(`migrationName`);
ON DUPLICATE KEY UPDATE `migrationName` = VALUES(`migrationName`);


-- ============================================================================
-- END OF MIGRATION 012
-- ============================================================================


-- ============================================================================
-- 🎨 KO-FI & PATREON INTEGRATION
-- ============================================================================

ALTER TABLE `tblPartnerPaymentConfig`
    MODIFY COLUMN `provider` ENUM('stripe', 'paypal', 'coinbase', 'kofi', 'patreon') NOT NULL
        COMMENT 'Payment provider this config applies to';
ALTER TABLE `tblPayments`
    MODIFY COLUMN `paymentMethod` ENUM('card', 'paypal', 'crypto', 'crypto_btc', 'crypto_eth', 'crypto_usdt', 'crypto_usdc', 'apple_pay', 'google_pay', 'link', 'kofi', 'patreon') NOT NULL
        COMMENT 'Payment method used for this transaction';
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `settingDescription`, `isSensitive`)
VALUES
    -- ☕ Ko-fi Provider — Core Settings
    -- These control whether Ko-fi is available as a payment/donation method
    ('payment.kofi.enabled', '0', 'payment', 'Enable Ko-fi as a payment/donation provider', 0),
    ('payment.kofi.verification_token', '', 'payment', 'Ko-fi webhook verification token (from Ko-fi dashboard > API > Webhooks)', 1),
    ('payment.kofi.page_url', '', 'payment', 'Ko-fi page URL for payment links (e.g. https://ko-fi.com/yourusername)', 0),
    ('payment.kofi.page_slug', '', 'payment', 'Ko-fi page slug / username for generating payment links', 0),
    ('payment.kofi.gold_enabled', '0', 'payment', 'Enable Ko-fi Gold membership/subscription features (requires Ko-fi Gold account)', 0),

    -- ☕ Ko-fi Provider — Webhook Configuration
    -- The webhook_url is auto-populated by the system when Ko-fi is configured
    -- log_all_events enables full event logging to tblInboundWebhooks for debugging
    ('payment.kofi.webhook_url', '', 'payment', 'Auto-populated: Full webhook URL for Ko-fi to POST events to', 0),
    ('payment.kofi.log_all_events', '1', 'payment', 'Log all Ko-fi webhook events to tblInboundWebhooks (recommended for debugging)', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `settingDescription`, `isSensitive`)
VALUES
    -- 🎨 Patreon Provider — Core Settings
    -- These control whether Patreon is available as a subscription/pledge provider
    ('payment.patreon.enabled', '0', 'payment', 'Enable Patreon as a subscription/pledge provider', 0),
    ('payment.patreon.client_id', '', 'payment', 'Patreon OAuth 2.0 Client ID (from Patreon Platform > Clients)', 1),
    ('payment.patreon.client_secret', '', 'payment', 'Patreon OAuth 2.0 Client Secret', 1),
    ('payment.patreon.webhook_secret', '', 'payment', 'Patreon webhook signing secret for HMAC-SHA256 verification', 1),
    ('payment.patreon.campaign_id', '', 'payment', 'Patreon Campaign ID (found in campaign URL or via API)', 0),
    ('payment.patreon.creator_access_token', '', 'payment', 'Patreon Creator Access Token for API calls (from Patreon Platform)', 1),
    ('payment.patreon.creator_refresh_token', '', 'payment', 'Patreon Creator Refresh Token for refreshing access tokens', 1),

    -- 🎨 Patreon Provider — Tier Mapping
    -- Maps Patreon tier IDs to SIGNula subscription tier slugs for automatic tier assignment
    -- Example: {"12345": "basic", "67890": "premium"}
    ('payment.patreon.tier_mapping', '{}', 'payment', 'JSON mapping of Patreon tier IDs to SIGNula subscription tier slugs', 0),

    -- 🎨 Patreon Provider — Configuration
    -- The webhook_url is auto-populated by the system when Patreon is configured
    -- log_all_events enables full event logging to tblInboundWebhooks for debugging
    -- sync_members enables automatic patron-to-SIGNula user synchronisation
    ('payment.patreon.webhook_url', '', 'payment', 'Auto-populated: Full webhook URL for Patreon to POST events to', 0),
    ('payment.patreon.log_all_events', '1', 'payment', 'Log all Patreon webhook events to tblInboundWebhooks', 0),
    ('payment.patreon.sync_members', '1', 'payment', 'Automatically sync Patreon members/patrons with SIGNula users', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);
INSERT INTO `tblProviderDiscounts` (`scope`, `partnerID`, `paymentMethod`, `discountPercentage`, `description`, `isActive`)
VALUES
    ('global', NULL, 'kofi', 0.00, 'Ko-fi payment discount (disabled by default)', 0),
    ('global', NULL, 'patreon', 0.00, 'Patreon payment discount (disabled by default)', 0)
ON DUPLICATE KEY UPDATE `paymentMethod` = VALUES(`paymentMethod`);
INSERT INTO `tblFeatureToggles` (`featureKey`, `featureName`, `featureDescription`, `isEnabledGlobally`, `category`, `requiresMigration`, `affectsAPI`, `canPartnersToggle`)
VALUES
    ('kofi_payments', 'Ko-fi Payments', 'Accept payments, donations, and subscriptions via Ko-fi', 0, 'billing', '013', 1, 1),
    ('patreon_payments', 'Patreon Subscriptions', 'Accept subscriptions and pledges via Patreon', 0, 'billing', '013', 1, 1)
ON DUPLICATE KEY UPDATE `featureKey` = VALUES(`featureKey`);
INSERT INTO `tblMigrations` (`migrationName`, `migrationVersion`, `description`)
VALUES ('013_kofi_patreon_providers', '2.5.0-beta',
    'Ko-fi and Patreon provider integration: expanded provider/payment ENUMs, '
    'settings for both providers, provider discounts, 2 feature toggles, '
    '4 email templates (donation received, subscription started, pledge created, pledge cancelled)')
ON DUPLICATE KEY UPDATE `migrationName` = VALUES(`migrationName`);


-- ============================================================================
-- ✅ INSTALLATION COMPLETE
-- ============================================================================
--
-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Verify installation
SELECT
    'SIGNula v2.5.0-beta database installation complete!' AS status,
    DATABASE() AS database_name,
    COUNT(*) AS table_count
FROM information_schema.tables
WHERE table_schema = DATABASE();

-- ============================================================================
