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
-- Version: 2.2.0-beta
-- Date: 2026-02-04
-- Description: Complete database schema for SIGNula universal authentication
-- Includes: All features through v2.2.0-beta (RESTful API, rate limiting, partner keys)
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
--
-- Installation:
--   mysql -u your_username -p your_database < signula_complete_install_v2.2.0.sql
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
-- NOTE: Incomplete INSERT stubs for tblEmailABTests, tblEmailABTestVariants
-- and tblSettings were removed (they had no VALUES clauses).
-- Add proper seed data here if needed.

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

-- Insert rate limiting settings if they don't exist
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
-- ✅ INSTALLATION COMPLETE
-- ============================================================================
--
-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Verify installation
SELECT
    'SIGNula v2.2.0-beta database installation complete!' AS status,
    DATABASE() AS database_name,
    COUNT(*) AS table_count
FROM information_schema.tables
WHERE table_schema = DATABASE();

-- ============================================================================
