-- ============================================================================
-- 📁 SIGNula Universal Login System - Complete Installation Script
-- ============================================================================
-- Version: 2.0.1-beta
-- Date: 2026-02-03
-- Description: Complete database schema for SIGNula universal authentication
-- Includes: Phase 1, 1.5, 2, 3, and 3.1 (OAuth Multi-Account Enhancement)
--
-- Supports: MySQL 8.0+, MariaDB 10.5+
-- Character Set: utf8mb4 (full Unicode support including emojis)
-- Collation: utf8mb4_unicode_ci (case-insensitive Unicode)
--
-- Installation:
--   mysql -u your_username -p < signula_complete_install_v2.0.1.sql
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
