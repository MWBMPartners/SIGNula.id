-- ============================================================================
-- SIGNula Database Schema
-- 
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
-- 
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 🔐 SIGNula - WebAuthn/PassKey Schema Migration
-- ============================================================================
--
-- Purpose: Add PassKey/WebAuthn support for passwordless authentication
-- Version: 1.4.0
-- PHP Version: 8.3+
--
-- Features:
-- - WebAuthn credential storage
-- - PassKey registration and authentication
-- - Biometric authentication support
-- - Hardware security key support
-- - Device management
--
-- Standards:
-- - W3C Web Authentication API (WebAuthn Level 2)
-- - FIDO2 specification
--
-- ============================================================================

-- ============================================================================
-- 🔑 WEBAUTHN CREDENTIALS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblWebAuthnCredentials` (
    `credentialID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'User who owns this credential',

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
    `tokenID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` BIGINT UNSIGNED NULL COMMENT 'User ID (null if email not yet registered)',
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
    `challengeID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` BIGINT UNSIGNED NULL COMMENT 'User ID (null for registration)',
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

SET @sql = IF(
    @webauthn_enabled_exists = 0,
    'ALTER TABLE tblUsers
     ADD COLUMN webauthnEnabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT "User has WebAuthn enabled" AFTER mfaEnabled',
    'SELECT "Column webauthnEnabled already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add password-less login enabled flag
SET @passwordless_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblUsers'
    AND COLUMN_NAME = 'passwordlessEnabled'
);

SET @sql = IF(
    @passwordless_exists = 0,
    'ALTER TABLE tblUsers
     ADD COLUMN passwordlessEnabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT "User can login without password" AFTER webauthnEnabled',
    'SELECT "Column passwordlessEnabled already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 📝 UPDATE SETTINGS
-- ============================================================================

-- Mark migration as complete
INSERT INTO tblSettings (settingKey, settingValue, description, settingCategory)
VALUES (
    'auth.webauthn.migration_version',
    '1.4.0',
    'WebAuthn/PassKey migration version',
    'authentication'
) ON DUPLICATE KEY UPDATE settingValue = '1.4.0';

-- Enable WebAuthn feature
INSERT INTO tblSettings (settingKey, settingValue, description, settingCategory)
VALUES (
    'auth.webauthn.enabled',
    '1',
    'Enable WebAuthn/PassKey authentication',
    'authentication'
) ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- Enable password-less login
INSERT INTO tblSettings (settingKey, settingValue, description, settingCategory)
VALUES (
    'auth.passwordless.enabled',
    '1',
    'Enable password-less email login',
    'authentication'
) ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- Password-less token validity (minutes)
INSERT INTO tblSettings (settingKey, settingValue, description, settingCategory)
VALUES (
    'auth.passwordless.token_validity',
    '15',
    'Password-less login token validity in minutes',
    'authentication'
) ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- WebAuthn challenge validity (minutes)
INSERT INTO tblSettings (settingKey, settingValue, description, settingCategory)
VALUES (
    'auth.webauthn.challenge_validity',
    '5',
    'WebAuthn challenge validity in minutes',
    'authentication'
) ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- Relying Party (RP) name
INSERT INTO tblSettings (settingKey, settingValue, description, settingCategory)
VALUES (
    'auth.webauthn.rp_name',
    'SIGNula',
    'WebAuthn Relying Party name',
    'authentication'
) ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- Relying Party ID (domain)
INSERT INTO tblSettings (settingKey, settingValue, description, settingCategory)
VALUES (
    'auth.webauthn.rp_id',
    'signula.id',
    'WebAuthn Relying Party ID (domain)',
    'authentication'
) ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- ============================================================================
-- 🧹 CLEANUP OLD TOKENS (STORED PROCEDURE)
-- ============================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS cleanupExpiredAuthTokens$$

CREATE PROCEDURE cleanupExpiredAuthTokens()
BEGIN
    -- Clean up expired passwordless tokens (older than 24 hours)
    DELETE FROM tblPasswordlessTokens
    WHERE expiresAt < DATE_SUB(NOW(), INTERVAL 24 HOUR);

    -- Clean up expired WebAuthn challenges (older than 1 hour)
    DELETE FROM tblWebAuthnChallenges
    WHERE expiresAt < DATE_SUB(NOW(), INTERVAL 1 HOUR);

    SELECT
        ROW_COUNT() AS tokens_cleaned,
        NOW() AS cleaned_at;
END$$

DELIMITER ;

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================

SELECT
    '✅ WebAuthn/PassKey Migration Complete' AS Status,
    '1.4.0' AS Version,
    NOW() AS AppliedAt;
