-- ============================================================================
-- 📁 SIGNula Universal Login System - Database Schema
-- ============================================================================
-- Version: 1.0.0
-- Description: Complete database schema for SIGNula universal authentication
-- Supports: MySQL 8.0+, MariaDB 10.5+
-- Character Set: utf8mb4 (full Unicode support including emojis)
-- Collation: utf8mb4_unicode_ci (case-insensitive Unicode)
-- ============================================================================

-- Set character set and collation for this session
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 🏗️ TABLE: tblUsers
-- Purpose: Core user accounts with internal authentication
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblUsers` (
    `userID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique user identifier',
    `userUUID` CHAR(36) NOT NULL UNIQUE COMMENT 'Universal unique identifier (RFC 4122)',
    `username` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Unique username for login',
    `email` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Primary email address',
    `emailVerified` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Email verification status',
    `emailVerifiedAt` DATETIME NULL COMMENT 'Timestamp of email verification',
    `passwordHash` VARCHAR(255) NULL COMMENT 'Bcrypt/Argon2 password hash (NULL for passwordless accounts)',
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

-- ============================================================================
-- 🔐 TABLE: tblUserMFA
-- Purpose: Multi-factor authentication settings per user
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblUserMFA` (
    `mfaID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique MFA record identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `mfaType` ENUM('totp', 'email', 'sms', 'push', 'backup_codes', 'passkey') NOT NULL COMMENT 'MFA method type',
    `isEnabled` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether this MFA method is active',
    `isPrimary` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Primary MFA method for this user',
    `totpSecret` VARCHAR(255) NULL COMMENT 'Encrypted TOTP secret key (Base32)',
    `backupCodes` TEXT NULL COMMENT 'Encrypted JSON array of backup recovery codes',
    `usedBackupCodes` TEXT NULL COMMENT 'JSON array of used backup codes',
    `pushToken` VARCHAR(255) NULL COMMENT 'Encrypted push notification token',
    `passkeyCredentialID` TEXT NULL COMMENT 'WebAuthn credential ID (base64)',
    `passkeyPublicKey` TEXT NULL COMMENT 'WebAuthn public key (base64)',
    `passkeyCounter` BIGINT UNSIGNED DEFAULT 0 COMMENT 'WebAuthn signature counter',
    `lastUsedAt` DATETIME NULL COMMENT 'Last time this MFA method was used',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'MFA method creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_userID` (`userID`),
    INDEX `idx_mfaType` (`mfaType`),
    INDEX `idx_isEnabled` (`isEnabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Multi-factor authentication settings';

-- ============================================================================
-- 🔗 TABLE: tblUserLinkedAccounts
-- Purpose: Third-party OAuth account linkages
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblUserLinkedAccounts` (
    `linkID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique link record identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `provider` ENUM('google', 'microsoft', 'apple', 'facebook', 'instagram', 'linkedin', 'lastpass', 'yahoo', 'wordpress', 'amazon', 'paypal', 'openid', 'github', 'twitter') NOT NULL COMMENT 'OAuth provider',
    `providerUserID` VARCHAR(255) NOT NULL COMMENT 'User ID from the provider',
    `providerEmail` VARCHAR(255) NULL COMMENT 'Email from provider account',
    `providerUsername` VARCHAR(255) NULL COMMENT 'Username from provider',
    `providerDisplayName` VARCHAR(255) NULL COMMENT 'Display name from provider',
    `providerProfilePicture` TEXT NULL COMMENT 'Profile picture URL from provider',
    `accessToken` TEXT NULL COMMENT 'Encrypted OAuth access token',
    `refreshToken` TEXT NULL COMMENT 'Encrypted OAuth refresh token',
    `tokenExpiresAt` DATETIME NULL COMMENT 'Access token expiration',
    `scopes` TEXT NULL COMMENT 'JSON array of granted OAuth scopes',
    `providerData` TEXT NULL COMMENT 'Additional provider-specific data (JSON)',
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether this link is active',
    `linkedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Account linkage timestamp',
    `lastSyncAt` DATETIME NULL COMMENT 'Last synchronization with provider',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `unique_provider_user` (`provider`, `providerUserID`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_provider` (`provider`),
    INDEX `idx_isActive` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Third-party account linkages';

-- ============================================================================
-- 🎫 TABLE: tblUserSessions
-- Purpose: Active user sessions across platforms
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblUserSessions` (
    `sessionID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique session identifier',
    `sessionToken` VARCHAR(128) NOT NULL UNIQUE COMMENT 'Secure session token (SHA-256)',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `deviceID` VARCHAR(128) NULL COMMENT 'Unique device identifier',
    `deviceType` ENUM('web', 'mobile_ios', 'mobile_android', 'desktop', 'tablet', 'api', 'other') NOT NULL DEFAULT 'web' COMMENT 'Device type',
    `deviceName` VARCHAR(255) NULL COMMENT 'User-friendly device name',
    `ipAddress` VARCHAR(45) NOT NULL COMMENT 'IP address (IPv4 or IPv6)',
    `userAgent` TEXT NULL COMMENT 'User agent string',
    `browserName` VARCHAR(100) NULL COMMENT 'Detected browser name',
    `browserVersion` VARCHAR(50) NULL COMMENT 'Detected browser version',
    `osName` VARCHAR(100) NULL COMMENT 'Detected operating system',
    `osVersion` VARCHAR(50) NULL COMMENT 'Detected OS version',
    `isMobile` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Is mobile device',
    `sessionData` TEXT NULL COMMENT 'Additional session data (JSON)',
    `mfaVerified` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'MFA verified for this session',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Session start timestamp',
    `lastActivityAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last activity timestamp',
    `expiresAt` DATETIME NOT NULL COMMENT 'Session expiration timestamp',
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Session active status',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_sessionToken` (`sessionToken`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_expiresAt` (`expiresAt`),
    INDEX `idx_isActive` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Active user sessions';

-- ============================================================================
-- 📧 TABLE: tblVerificationTokens
-- Purpose: Email verification, password reset, and passwordless login tokens
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblVerificationTokens` (
    `tokenID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique token identifier',
    `userID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblUsers (NULL for registration)',
    `token` VARCHAR(128) NOT NULL UNIQUE COMMENT 'Secure verification token',
    `tokenType` ENUM('email_verification', 'password_reset', 'passwordless_login', 'email_change', 'phone_verification', 'mfa_setup') NOT NULL COMMENT 'Token purpose',
    `email` VARCHAR(255) NULL COMMENT 'Email address for this token',
    `code` VARCHAR(10) NULL COMMENT 'Short numeric/alphanumeric code for OTP',
    `metadata` TEXT NULL COMMENT 'Additional token metadata (JSON)',
    `ipAddress` VARCHAR(45) NULL COMMENT 'IP address that requested token',
    `userAgent` TEXT NULL COMMENT 'User agent that requested token',
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of verification attempts',
    `maxAttempts` SMALLINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Maximum allowed attempts',
    `isUsed` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether token has been used',
    `usedAt` DATETIME NULL COMMENT 'Timestamp when token was used',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Token creation timestamp',
    `expiresAt` DATETIME NOT NULL COMMENT 'Token expiration timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_token` (`token`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_tokenType` (`tokenType`),
    INDEX `idx_expiresAt` (`expiresAt`),
    INDEX `idx_isUsed` (`isUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Verification and authentication tokens';

-- ============================================================================
-- 🔑 TABLE: tblAPIKeys
-- Purpose: API keys for third-party service integration
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblAPIKeys` (
    `apiKeyID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique API key identifier',
    `userID` BIGINT UNSIGNED NULL COMMENT 'User who owns this key (NULL for system keys)',
    `serviceID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblIntegratedServices',
    `keyName` VARCHAR(255) NOT NULL COMMENT 'Descriptive name for this API key',
    `apiKey` VARCHAR(128) NOT NULL UNIQUE COMMENT 'Public API key',
    `apiSecret` VARCHAR(255) NULL COMMENT 'Encrypted API secret',
    `keyType` ENUM('system', 'user', 'service') NOT NULL DEFAULT 'user' COMMENT 'Type of API key',
    `permissions` TEXT NULL COMMENT 'JSON array of granted permissions',
    `ipWhitelist` TEXT NULL COMMENT 'JSON array of allowed IP addresses/ranges',
    `rateLimit` INT UNSIGNED NULL COMMENT 'Requests per hour limit',
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Key active status',
    `lastUsedAt` DATETIME NULL COMMENT 'Last time key was used',
    `usageCount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total number of API calls',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Key creation timestamp',
    `expiresAt` DATETIME NULL COMMENT 'Key expiration (NULL = never)',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_apiKey` (`apiKey`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_serviceID` (`serviceID`),
    INDEX `idx_isActive` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API keys for service integration';

-- ============================================================================
-- 🏢 TABLE: tblIntegratedServices
-- Purpose: Third-party services that integrate with SIGNula
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblIntegratedServices` (
    `serviceID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique service identifier',
    `serviceName` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Service name',
    `serviceSlug` VARCHAR(100) NOT NULL UNIQUE COMMENT 'URL-friendly slug',
    `serviceDescription` TEXT NULL COMMENT 'Service description',
    `serviceLogo` TEXT NULL COMMENT 'Service logo URL or base64',
    `serviceURL` VARCHAR(500) NULL COMMENT 'Service website URL',
    `callbackURL` VARCHAR(500) NOT NULL COMMENT 'OAuth callback URL',
    `clientID` VARCHAR(255) NULL COMMENT 'OAuth client ID',
    `clientSecret` TEXT NULL COMMENT 'Encrypted OAuth client secret',
    `webhookURL` VARCHAR(500) NULL COMMENT 'Webhook endpoint URL',
    `webhookSecret` VARCHAR(255) NULL COMMENT 'Encrypted webhook signature secret',
    `allowedScopes` TEXT NULL COMMENT 'JSON array of allowed OAuth scopes',
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Service active status',
    `requiresApproval` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Requires admin approval for new integrations',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Service registration timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    INDEX `idx_serviceSlug` (`serviceSlug`),
    INDEX `idx_isActive` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Integrated third-party services';

-- ============================================================================
-- 💳 TABLE: tblSubscriptions
-- Purpose: User subscription and payment tier management
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblSubscriptions` (
    `subscriptionID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique subscription identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `serviceID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblIntegratedServices (NULL for SIGNula itself)',
    `tierID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblSubscriptionTiers',
    `subscriptionStatus` ENUM('active', 'cancelled', 'expired', 'paused', 'trial', 'pending') NOT NULL DEFAULT 'pending' COMMENT 'Subscription status',
    `billingCycle` ENUM('monthly', 'quarterly', 'yearly', 'lifetime', 'one_time') NOT NULL COMMENT 'Billing frequency',
    `amount` DECIMAL(10,2) NOT NULL COMMENT 'Subscription amount',
    `currency` CHAR(3) NOT NULL DEFAULT 'USD' COMMENT 'Currency code (ISO 4217)',
    `paymentMethod` ENUM('paypal', 'apple_pay', 'google_pay', 'crypto', 'stripe', 'manual') NULL COMMENT 'Payment method used',
    `paymentProvider` VARCHAR(100) NULL COMMENT 'Payment provider name',
    `paymentProviderSubscriptionID` VARCHAR(255) NULL COMMENT 'External subscription ID',
    `startDate` DATE NOT NULL COMMENT 'Subscription start date',
    `endDate` DATE NULL COMMENT 'Subscription end date',
    `nextBillingDate` DATE NULL COMMENT 'Next billing date',
    `cancelledAt` DATETIME NULL COMMENT 'Cancellation timestamp',
    `cancellationReason` TEXT NULL COMMENT 'Reason for cancellation',
    `trialEndsAt` DATETIME NULL COMMENT 'Trial period end date',
    `autoRenew` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Auto-renewal status',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Subscription creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (`serviceID`) REFERENCES `tblIntegratedServices`(`serviceID`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_userID` (`userID`),
    INDEX `idx_serviceID` (`serviceID`),
    INDEX `idx_tierID` (`tierID`),
    INDEX `idx_subscriptionStatus` (`subscriptionStatus`),
    INDEX `idx_nextBillingDate` (`nextBillingDate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User subscriptions';

-- ============================================================================
-- 🎯 TABLE: tblSubscriptionTiers
-- Purpose: Available subscription tiers and features
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblSubscriptionTiers` (
    `tierID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique tier identifier',
    `serviceID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblIntegratedServices (NULL for SIGNula)',
    `tierName` VARCHAR(100) NOT NULL COMMENT 'Tier name (e.g., Free, Basic, Premium)',
    `tierSlug` VARCHAR(50) NOT NULL COMMENT 'URL-friendly slug',
    `tierDescription` TEXT NULL COMMENT 'Tier description',
    `monthlyPrice` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Monthly price',
    `yearlyPrice` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Yearly price',
    `features` TEXT NULL COMMENT 'JSON array of included features',
    `featureLimits` TEXT NULL COMMENT 'JSON object of feature limits',
    `displayOrder` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Display order',
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Tier availability',
    `isDefault` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Default tier for new users',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Tier creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`serviceID`) REFERENCES `tblIntegratedServices`(`serviceID`) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `unique_service_slug` (`serviceID`, `tierSlug`),
    INDEX `idx_serviceID` (`serviceID`),
    INDEX `idx_isActive` (`isActive`),
    INDEX `idx_displayOrder` (`displayOrder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Subscription tiers and features';

-- ============================================================================
-- 💰 TABLE: tblPayments
-- Purpose: Payment transaction history
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblPayments` (
    `paymentID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique payment identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `subscriptionID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblSubscriptions',
    `transactionID` VARCHAR(255) NOT NULL UNIQUE COMMENT 'External transaction ID',
    `paymentMethod` ENUM('paypal', 'apple_pay', 'google_pay', 'crypto', 'stripe', 'manual') NOT NULL COMMENT 'Payment method',
    `paymentProvider` VARCHAR(100) NOT NULL COMMENT 'Payment provider name',
    `amount` DECIMAL(10,2) NOT NULL COMMENT 'Payment amount',
    `currency` CHAR(3) NOT NULL DEFAULT 'USD' COMMENT 'Currency code (ISO 4217)',
    `status` ENUM('pending', 'completed', 'failed', 'refunded', 'cancelled') NOT NULL DEFAULT 'pending' COMMENT 'Payment status',
    `description` TEXT NULL COMMENT 'Payment description',
    `paymentData` TEXT NULL COMMENT 'Additional payment data (JSON)',
    `ipAddress` VARCHAR(45) NULL COMMENT 'IP address of payment',
    `paidAt` DATETIME NULL COMMENT 'Payment completion timestamp',
    `refundedAt` DATETIME NULL COMMENT 'Refund timestamp',
    `refundReason` TEXT NULL COMMENT 'Reason for refund',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Payment initiation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (`subscriptionID`) REFERENCES `tblSubscriptions`(`subscriptionID`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_userID` (`userID`),
    INDEX `idx_subscriptionID` (`subscriptionID`),
    INDEX `idx_transactionID` (`transactionID`),
    INDEX `idx_status` (`status`),
    INDEX `idx_paidAt` (`paidAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment transactions';

-- ============================================================================
-- 📝 TABLE: tblActivityLog
-- Purpose: Comprehensive activity and audit logging
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblActivityLog` (
    `logID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique log entry identifier',
    `userID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblUsers (NULL for system events)',
    `sessionID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblUserSessions',
    `activityType` VARCHAR(100) NOT NULL COMMENT 'Activity type (e.g., login, logout, registration, password_change)',
    `activityCategory` ENUM('auth', 'account', 'security', 'payment', 'api', 'admin', 'system', 'other') NOT NULL DEFAULT 'other' COMMENT 'Activity category',
    `severity` ENUM('info', 'notice', 'warning', 'error', 'critical') NOT NULL DEFAULT 'info' COMMENT 'Log severity level',
    `description` TEXT NULL COMMENT 'Activity description',
    `metadata` TEXT NULL COMMENT 'Additional activity data (JSON)',
    `ipAddress` VARCHAR(45) NULL COMMENT 'IP address (IPv4 or IPv6)',
    `userAgent` TEXT NULL COMMENT 'User agent string',
    `requestMethod` VARCHAR(10) NULL COMMENT 'HTTP request method',
    `requestURL` TEXT NULL COMMENT 'Request URL',
    `requestHeaders` TEXT NULL COMMENT 'Request headers (JSON)',
    `statusCode` SMALLINT UNSIGNED NULL COMMENT 'HTTP status code',
    `success` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether activity was successful',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Activity timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (`sessionID`) REFERENCES `tblUserSessions`(`sessionID`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_userID` (`userID`),
    INDEX `idx_sessionID` (`sessionID`),
    INDEX `idx_activityType` (`activityType`),
    INDEX `idx_activityCategory` (`activityCategory`),
    INDEX `idx_severity` (`severity`),
    INDEX `idx_createdAt` (`createdAt`),
    INDEX `idx_ipAddress` (`ipAddress`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Activity and audit log';

-- ============================================================================
-- ❌ TABLE: tblErrorLog
-- Purpose: Application and PHP error logging
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblErrorLog` (
    `errorID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique error identifier',
    `userID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblUsers',
    `sessionID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblUserSessions',
    `errorType` VARCHAR(50) NOT NULL COMMENT 'Error type (e.g., E_ERROR, E_WARNING, Exception)',
    `errorCode` VARCHAR(50) NULL COMMENT 'Error code',
    `errorMessage` TEXT NOT NULL COMMENT 'Error message',
    `errorFile` VARCHAR(500) NULL COMMENT 'File where error occurred',
    `errorLine` INT UNSIGNED NULL COMMENT 'Line number where error occurred',
    `errorTrace` TEXT NULL COMMENT 'Error backtrace',
    `severity` ENUM('info', 'notice', 'warning', 'error', 'critical', 'fatal') NOT NULL DEFAULT 'error' COMMENT 'Error severity',
    `context` TEXT NULL COMMENT 'Error context data (JSON)',
    `requestURL` TEXT NULL COMMENT 'Request URL when error occurred',
    `requestMethod` VARCHAR(10) NULL COMMENT 'HTTP request method',
    `requestHeaders` TEXT NULL COMMENT 'Request headers (JSON)',
    `requestData` TEXT NULL COMMENT 'Request data (JSON)',
    `ipAddress` VARCHAR(45) NULL COMMENT 'IP address',
    `userAgent` TEXT NULL COMMENT 'User agent string',
    `resolved` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether error has been resolved',
    `resolvedAt` DATETIME NULL COMMENT 'Resolution timestamp',
    `resolvedBy` BIGINT UNSIGNED NULL COMMENT 'User who resolved the error',
    `notes` TEXT NULL COMMENT 'Resolution notes',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Error timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (`sessionID`) REFERENCES `tblUserSessions`(`sessionID`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_userID` (`userID`),
    INDEX `idx_errorType` (`errorType`),
    INDEX `idx_severity` (`severity`),
    INDEX `idx_resolved` (`resolved`),
    INDEX `idx_createdAt` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Error and exception log';

-- ============================================================================
-- ⚙️ TABLE: tblSettings
-- Purpose: Application configuration and settings
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblSettings` (
    `settingID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique setting identifier',
    `settingKey` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Setting key (dot notation supported)',
    `settingValue` TEXT NULL COMMENT 'Setting value (encrypted if isSensitive=true)',
    `settingType` ENUM('string', 'integer', 'float', 'boolean', 'json', 'array', 'email', 'url', 'password') NOT NULL DEFAULT 'string' COMMENT 'Data type of setting value',
    `settingGroup` VARCHAR(100) NOT NULL DEFAULT 'general' COMMENT 'Setting group/category',
    `description` TEXT NULL COMMENT 'Setting description',
    `isSensitive` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether value should be encrypted',
    `isEditable` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether admins can edit via UI',
    `validationRules` TEXT NULL COMMENT 'Validation rules (JSON)',
    `defaultValue` TEXT NULL COMMENT 'Default value',
    `displayOrder` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Display order in UI',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Setting creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    `updatedBy` BIGINT UNSIGNED NULL COMMENT 'User who last updated',

    INDEX `idx_settingKey` (`settingKey`),
    INDEX `idx_settingGroup` (`settingGroup`),
    INDEX `idx_isSensitive` (`isSensitive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Application settings';

-- ============================================================================
-- 📧 TABLE: tblEmailTemplates
-- Purpose: Email templates for notifications
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblEmailTemplates` (
    `templateID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique template identifier',
    `templateKey` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Template identifier key',
    `templateName` VARCHAR(255) NOT NULL COMMENT 'Human-readable template name',
    `subject` VARCHAR(500) NOT NULL COMMENT 'Email subject line',
    `bodyHTML` TEXT NOT NULL COMMENT 'HTML email body',
    `bodyText` TEXT NOT NULL COMMENT 'Plain text email body',
    `fromName` VARCHAR(255) NULL COMMENT 'From name (NULL = use default)',
    `fromEmail` VARCHAR(255) NULL COMMENT 'From email (NULL = use default)',
    `replyTo` VARCHAR(255) NULL COMMENT 'Reply-to email',
    `variables` TEXT NULL COMMENT 'Available template variables (JSON)',
    `description` TEXT NULL COMMENT 'Template description',
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Template active status',
    `locale` VARCHAR(10) DEFAULT 'en_US' COMMENT 'Template locale',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Template creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    `createdBy` BIGINT UNSIGNED NULL COMMENT 'User who created template',

    INDEX `idx_templateKey` (`templateKey`),
    INDEX `idx_locale` (`locale`),
    INDEX `idx_isActive` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Email templates';

-- ============================================================================
-- 📨 TABLE: tblEmailQueue
-- Purpose: Queued emails for sending
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblEmailQueue` (
    `queueID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique queue entry identifier',
    `userID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblUsers',
    `templateID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblEmailTemplates',
    `recipientEmail` VARCHAR(255) NOT NULL COMMENT 'Recipient email address',
    `recipientName` VARCHAR(255) NULL COMMENT 'Recipient name',
    `subject` VARCHAR(500) NOT NULL COMMENT 'Email subject',
    `bodyHTML` TEXT NULL COMMENT 'HTML email body',
    `bodyText` TEXT NOT NULL COMMENT 'Plain text email body',
    `fromEmail` VARCHAR(255) NOT NULL COMMENT 'From email address',
    `fromName` VARCHAR(255) NULL COMMENT 'From name',
    `replyTo` VARCHAR(255) NULL COMMENT 'Reply-to email',
    `headers` TEXT NULL COMMENT 'Additional email headers (JSON)',
    `attachments` TEXT NULL COMMENT 'Attachments data (JSON)',
    `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'Email priority (1=highest, 10=lowest)',
    `status` ENUM('pending', 'sending', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'pending' COMMENT 'Queue status',
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Send attempts',
    `maxAttempts` SMALLINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Maximum send attempts',
    `lastAttemptAt` DATETIME NULL COMMENT 'Last send attempt timestamp',
    `errorMessage` TEXT NULL COMMENT 'Error message if failed',
    `sentAt` DATETIME NULL COMMENT 'Successfully sent timestamp',
    `scheduledFor` DATETIME NULL COMMENT 'Scheduled send time (NULL = send ASAP)',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Queue entry creation timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (`templateID`) REFERENCES `tblEmailTemplates`(`templateID`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_userID` (`userID`),
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_scheduledFor` (`scheduledFor`),
    INDEX `idx_createdAt` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Email queue';

-- ============================================================================
-- 🚦 TABLE: tblRateLimits
-- Purpose: API rate limiting tracking
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblRateLimits` (
    `limitID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique rate limit entry identifier',
    `identifier` VARCHAR(255) NOT NULL COMMENT 'Identifier (API key, IP address, user ID)',
    `identifierType` ENUM('api_key', 'ip_address', 'user_id', 'session_id') NOT NULL COMMENT 'Type of identifier',
    `endpoint` VARCHAR(255) NOT NULL COMMENT 'API endpoint or action',
    `requestCount` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Number of requests',
    `windowStart` DATETIME NOT NULL COMMENT 'Rate limit window start',
    `windowEnd` DATETIME NOT NULL COMMENT 'Rate limit window end',
    `isBlocked` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether identifier is blocked',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'First request timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last request timestamp',

    UNIQUE KEY `unique_rate_limit` (`identifier`, `identifierType`, `endpoint`, `windowStart`),
    INDEX `idx_identifier` (`identifier`),
    INDEX `idx_endpoint` (`endpoint`),
    INDEX `idx_windowEnd` (`windowEnd`),
    INDEX `idx_isBlocked` (`isBlocked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Rate limiting tracking';

-- ============================================================================
-- 🔒 TABLE: tblSecurityEvents
-- Purpose: Security-related events and incidents
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblSecurityEvents` (
    `eventID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique event identifier',
    `userID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblUsers',
    `eventType` VARCHAR(100) NOT NULL COMMENT 'Event type (e.g., brute_force, suspicious_activity, account_takeover)',
    `severity` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium' COMMENT 'Event severity',
    `description` TEXT NOT NULL COMMENT 'Event description',
    `detailsJSON` TEXT NULL COMMENT 'Detailed event data (JSON)',
    `ipAddress` VARCHAR(45) NOT NULL COMMENT 'IP address involved',
    `userAgent` TEXT NULL COMMENT 'User agent string',
    `geoLocation` VARCHAR(255) NULL COMMENT 'Geographical location',
    `isResolved` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether event has been investigated',
    `resolvedAt` DATETIME NULL COMMENT 'Resolution timestamp',
    `resolvedBy` BIGINT UNSIGNED NULL COMMENT 'Admin who resolved',
    `actionTaken` TEXT NULL COMMENT 'Action taken to resolve',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Event timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_userID` (`userID`),
    INDEX `idx_eventType` (`eventType`),
    INDEX `idx_severity` (`severity`),
    INDEX `idx_ipAddress` (`ipAddress`),
    INDEX `idx_isResolved` (`isResolved`),
    INDEX `idx_createdAt` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Security events and incidents';

-- ============================================================================
-- 🌐 TABLE: tblURLRouter
-- Purpose: Custom URL routing for clean URLs
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblURLRouter` (
    `routeID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique route identifier',
    `routePath` VARCHAR(500) NOT NULL UNIQUE COMMENT 'Clean URL path (e.g., /login, /account/settings)',
    `targetFile` VARCHAR(500) NOT NULL COMMENT 'Target PHP file path',
    `httpMethod` VARCHAR(10) DEFAULT 'GET' COMMENT 'HTTP method (GET, POST, PUT, DELETE, etc.)',
    `requiresAuth` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Requires authentication',
    `allowedRoles` TEXT NULL COMMENT 'JSON array of allowed roles',
    `description` TEXT NULL COMMENT 'Route description',
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Route active status',
    `displayOrder` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Display order',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Route creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    INDEX `idx_routePath` (`routePath`),
    INDEX `idx_isActive` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='URL routing table';

-- ============================================================================
-- 📊 TABLE: tblUserPreferences
-- Purpose: User-specific preferences and customization
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblUserPreferences` (
    `preferenceID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique preference identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `preferenceKey` VARCHAR(255) NOT NULL COMMENT 'Preference key',
    `preferenceValue` TEXT NULL COMMENT 'Preference value',
    `preferenceType` ENUM('string', 'integer', 'float', 'boolean', 'json', 'array') NOT NULL DEFAULT 'string' COMMENT 'Data type',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Preference creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `unique_user_preference` (`userID`, `preferenceKey`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_preferenceKey` (`preferenceKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User preferences';

-- ============================================================================
-- 🎨 TABLE: tblCustomTemplates
-- Purpose: User-created custom email/notification templates
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblCustomTemplates` (
    `customTemplateID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique custom template identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers (template creator)',
    `serviceID` BIGINT UNSIGNED NULL COMMENT 'Reference to tblIntegratedServices',
    `templateType` ENUM('email', 'sms', 'push', 'webhook') NOT NULL DEFAULT 'email' COMMENT 'Template type',
    `templateName` VARCHAR(255) NOT NULL COMMENT 'Template name',
    `subject` VARCHAR(500) NULL COMMENT 'Email subject (for email templates)',
    `bodyHTML` TEXT NULL COMMENT 'HTML body',
    `bodyText` TEXT NOT NULL COMMENT 'Plain text body',
    `styles` TEXT NULL COMMENT 'Custom CSS styles (JSON)',
    `variables` TEXT NULL COMMENT 'Available variables (JSON)',
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Template active status',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Template creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (`serviceID`) REFERENCES `tblIntegratedServices`(`serviceID`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_userID` (`userID`),
    INDEX `idx_serviceID` (`serviceID`),
    INDEX `idx_templateType` (`templateType`),
    INDEX `idx_isActive` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User-created custom templates';

-- ============================================================================
-- 📱 TABLE: tblUserDevices
-- Purpose: Registered user devices for push notifications and tracking
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblUserDevices` (
    `deviceID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique device identifier',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `deviceUUID` VARCHAR(128) NOT NULL COMMENT 'Device unique identifier',
    `deviceName` VARCHAR(255) NULL COMMENT 'User-friendly device name',
    `deviceType` ENUM('web', 'mobile_ios', 'mobile_android', 'desktop', 'tablet', 'other') NOT NULL COMMENT 'Device type',
    `deviceOS` VARCHAR(100) NULL COMMENT 'Operating system',
    `deviceOSVersion` VARCHAR(50) NULL COMMENT 'OS version',
    `deviceModel` VARCHAR(100) NULL COMMENT 'Device model',
    `pushToken` VARCHAR(500) NULL COMMENT 'Push notification token',
    `pushProvider` ENUM('fcm', 'apns', 'web_push', 'none') DEFAULT 'none' COMMENT 'Push notification provider',
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Device active status',
    `isTrusted` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Trusted device status',
    `lastSeenAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Last activity timestamp',
    `registeredAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Device registration timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `unique_device_uuid` (`userID`, `deviceUUID`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_deviceUUID` (`deviceUUID`),
    INDEX `idx_isActive` (`isActive`),
    INDEX `idx_isTrusted` (`isTrusted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User registered devices';

-- ============================================================================
-- 🔍 Insert Default Settings
-- ============================================================================
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingGroup`, `description`, `isSensitive`, `isEditable`, `displayOrder`) VALUES
-- System Settings
('system.name', 'SIGNula', 'string', 'system', 'Application name', FALSE, TRUE, 1),
('system.version', '1.0.0', 'string', 'system', 'Application version', FALSE, FALSE, 2),
('system.environment', 'production', 'string', 'system', 'Environment (development, staging, production)', FALSE, TRUE, 3),
('system.debug.enabled', 'false', 'boolean', 'system', 'Enable debug mode', FALSE, TRUE, 4),
('system.maintenance.enabled', 'false', 'boolean', 'system', 'Enable maintenance mode', FALSE, TRUE, 5),

-- Security Settings
('security.session.lifetime', '86400', 'integer', 'security', 'Session lifetime in seconds (default: 24 hours)', FALSE, TRUE, 10),
('security.session.remember_lifetime', '2592000', 'integer', 'security', 'Remember me session lifetime (default: 30 days)', FALSE, TRUE, 11),
('security.password.min_length', '12', 'integer', 'security', 'Minimum password length', FALSE, TRUE, 12),
('security.password.require_uppercase', 'true', 'boolean', 'security', 'Require uppercase letters in password', FALSE, TRUE, 13),
('security.password.require_lowercase', 'true', 'boolean', 'security', 'Require lowercase letters in password', FALSE, TRUE, 14),
('security.password.require_numbers', 'true', 'boolean', 'security', 'Require numbers in password', FALSE, TRUE, 15),
('security.password.require_symbols', 'true', 'boolean', 'security', 'Require special characters in password', FALSE, TRUE, 16),
('security.login.max_attempts', '5', 'integer', 'security', 'Maximum failed login attempts before lockout', FALSE, TRUE, 17),
('security.login.lockout_duration', '1800', 'integer', 'security', 'Account lockout duration in seconds (default: 30 minutes)', FALSE, TRUE, 18),
('security.encryption.algorithm', 'AES-256-CBC', 'string', 'security', 'Encryption algorithm for sensitive data', FALSE, FALSE, 19),

-- MFA Settings
('mfa.totp.enabled', 'true', 'boolean', 'mfa', 'Enable TOTP authentication', FALSE, TRUE, 20),
('mfa.email.enabled', 'true', 'boolean', 'mfa', 'Enable email OTP authentication', FALSE, TRUE, 21),
('mfa.sms.enabled', 'false', 'boolean', 'mfa', 'Enable SMS OTP authentication', FALSE, TRUE, 22),
('mfa.backup_codes.count', '10', 'integer', 'mfa', 'Number of backup codes to generate', FALSE, TRUE, 23),
('mfa.otp.lifetime', '900', 'integer', 'mfa', 'OTP lifetime in seconds (default: 15 minutes)', FALSE, TRUE, 24),
('mfa.totp.issuer', 'SIGNula', 'string', 'mfa', 'TOTP issuer name', FALSE, TRUE, 25),

-- Email Settings
('email.from.address', 'noreply@signulo.id', 'email', 'email', 'Default from email address', FALSE, TRUE, 30),
('email.from.name', 'SIGNula', 'string', 'email', 'Default from name', FALSE, TRUE, 31),
('email.smtp.enabled', 'false', 'boolean', 'email', 'Use SMTP for sending emails', FALSE, TRUE, 32),
('email.smtp.host', '', 'string', 'email', 'SMTP host', FALSE, TRUE, 33),
('email.smtp.port', '587', 'integer', 'email', 'SMTP port', FALSE, TRUE, 34),
('email.smtp.encryption', 'tls', 'string', 'email', 'SMTP encryption (tls or ssl)', FALSE, TRUE, 35),
('email.smtp.username', '', 'string', 'email', 'SMTP username', FALSE, TRUE, 36),
('email.smtp.password', '', 'password', 'email', 'SMTP password', TRUE, TRUE, 37),

-- API Settings
('api.enabled', 'true', 'boolean', 'api', 'Enable API access', FALSE, TRUE, 40),
('api.version', 'v1', 'string', 'api', 'Current API version', FALSE, FALSE, 41),
('api.rate_limit.enabled', 'true', 'boolean', 'api', 'Enable API rate limiting', FALSE, TRUE, 42),
('api.rate_limit.requests_per_hour', '1000', 'integer', 'api', 'API requests per hour limit', FALSE, TRUE, 43),
('api.rate_limit.burst', '100', 'integer', 'api', 'API burst limit', FALSE, TRUE, 44),

-- OAuth Settings (Third-party integrations)
('oauth.google.enabled', 'false', 'boolean', 'oauth', 'Enable Google OAuth', FALSE, TRUE, 50),
('oauth.google.client_id', '', 'string', 'oauth', 'Google OAuth client ID', FALSE, TRUE, 51),
('oauth.google.client_secret', '', 'password', 'oauth', 'Google OAuth client secret', TRUE, TRUE, 52),
('oauth.microsoft.enabled', 'false', 'boolean', 'oauth', 'Enable Microsoft OAuth', FALSE, TRUE, 53),
('oauth.microsoft.client_id', '', 'string', 'oauth', 'Microsoft OAuth client ID', FALSE, TRUE, 54),
('oauth.microsoft.client_secret', '', 'password', 'oauth', 'Microsoft OAuth client secret', TRUE, TRUE, 55),
('oauth.apple.enabled', 'false', 'boolean', 'oauth', 'Enable Apple OAuth', FALSE, TRUE, 56),
('oauth.apple.client_id', '', 'string', 'oauth', 'Apple OAuth client ID', FALSE, TRUE, 57),
('oauth.apple.client_secret', '', 'password', 'oauth', 'Apple OAuth client secret', TRUE, TRUE, 58),

-- Bot Protection Settings
('captcha.provider', 'none', 'string', 'captcha', 'Captcha provider (none, recaptcha, turnstile)', FALSE, TRUE, 60),
('captcha.recaptcha.site_key', '', 'string', 'captcha', 'reCAPTCHA site key', FALSE, TRUE, 61),
('captcha.recaptcha.secret_key', '', 'password', 'captcha', 'reCAPTCHA secret key', TRUE, TRUE, 62),
('captcha.turnstile.site_key', '', 'string', 'captcha', 'Cloudflare Turnstile site key', FALSE, TRUE, 63),
('captcha.turnstile.secret_key', '', 'password', 'captcha', 'Cloudflare Turnstile secret key', TRUE, TRUE, 64),

-- Payment Settings
('payment.paypal.enabled', 'false', 'boolean', 'payment', 'Enable PayPal payments', FALSE, TRUE, 70),
('payment.paypal.mode', 'sandbox', 'string', 'payment', 'PayPal mode (sandbox or live)', FALSE, TRUE, 71),
('payment.paypal.client_id', '', 'string', 'payment', 'PayPal client ID', FALSE, TRUE, 72),
('payment.paypal.client_secret', '', 'password', 'payment', 'PayPal client secret', TRUE, TRUE, 73),

-- URL Settings
('url.base', 'https://signulo.id', 'url', 'url', 'Base URL for the application', FALSE, TRUE, 80),
('url.public_site', 'https://signulo.com', 'url', 'url', 'Public-facing website URL', FALSE, TRUE, 81),
('url.api', 'https://signulo.id/api', 'url', 'url', 'API base URL', FALSE, TRUE, 82);

-- ============================================================================
-- 📧 Insert Default Email Templates
-- ============================================================================
INSERT INTO `tblEmailTemplates` (`templateKey`, `templateName`, `subject`, `bodyHTML`, `bodyText`, `variables`, `description`, `locale`) VALUES
('welcome', 'Welcome Email', 'Welcome to SIGNula!',
'<h1>Welcome {{displayName}}!</h1><p>Thank you for creating your SIGNula account. Your universal login system is ready to use.</p><p>Username: {{username}}</p><p>Start using SIGNula: <a href="{{loginUrl}}">Login Now</a></p>',
'Welcome {{displayName}}! Thank you for creating your SIGNula account. Username: {{username}}. Login at: {{loginUrl}}',
'["displayName", "username", "email", "loginUrl"]', 'Welcome email for new users', 'en_US'),

('email_verification', 'Email Verification', 'Verify Your Email Address',
'<h1>Verify Your Email</h1><p>Hello {{displayName}},</p><p>Please verify your email address by clicking the link below:</p><p><a href="{{verificationUrl}}">Verify Email</a></p><p>Or enter this code: <strong>{{verificationCode}}</strong></p><p>This link expires in {{expiryMinutes}} minutes.</p>',
'Hello {{displayName}}, Please verify your email address: {{verificationUrl}} Or enter this code: {{verificationCode}}. This link expires in {{expiryMinutes}} minutes.',
'["displayName", "email", "verificationUrl", "verificationCode", "expiryMinutes"]', 'Email verification template', 'en_US'),

('password_reset', 'Password Reset Request', 'Reset Your Password',
'<h1>Reset Your Password</h1><p>Hello {{displayName}},</p><p>We received a request to reset your password. Click the link below to create a new password:</p><p><a href="{{resetUrl}}">Reset Password</a></p><p>Or enter this code: <strong>{{resetCode}}</strong></p><p>This link expires in {{expiryMinutes}} minutes.</p><p>If you did not request this, please ignore this email.</p>',
'Hello {{displayName}}, Click here to reset your password: {{resetUrl}} Or enter this code: {{resetCode}}. This link expires in {{expiryMinutes}} minutes. If you did not request this, please ignore this email.',
'["displayName", "email", "resetUrl", "resetCode", "expiryMinutes"]', 'Password reset email', 'en_US'),

('passwordless_login', 'Your Login Link', 'Your Passwordless Login Link',
'<h1>Login to SIGNula</h1><p>Hello {{displayName}},</p><p>Click the link below to login to your account:</p><p><a href="{{loginUrl}}">Login Now</a></p><p>Or enter this code: <strong>{{loginCode}}</strong></p><p>This link expires in {{expiryMinutes}} minutes.</p>',
'Hello {{displayName}}, Click here to login: {{loginUrl}} Or enter this code: {{loginCode}}. This link expires in {{expiryMinutes}} minutes.',
'["displayName", "email", "loginUrl", "loginCode", "expiryMinutes"]', 'Passwordless login link', 'en_US'),

('mfa_code', 'Your Verification Code', 'Your SIGNula Verification Code',
'<h1>Verification Code</h1><p>Hello {{displayName}},</p><p>Your verification code is: <strong>{{mfaCode}}</strong></p><p>This code expires in {{expiryMinutes}} minutes.</p><p>If you did not request this code, please secure your account immediately.</p>',
'Hello {{displayName}}, Your verification code is: {{mfaCode}}. This code expires in {{expiryMinutes}} minutes.',
'["displayName", "mfaCode", "expiryMinutes"]', 'MFA verification code', 'en_US'),

('account_locked', 'Account Security Alert', 'Your Account Has Been Locked',
'<h1>Account Locked</h1><p>Hello {{displayName}},</p><p>Your account has been temporarily locked due to multiple failed login attempts.</p><p>Unlock your account: <a href="{{unlockUrl}}">Unlock Now</a></p><p>If this was not you, please contact support immediately.</p>',
'Hello {{displayName}}, Your account has been locked due to multiple failed login attempts. Unlock at: {{unlockUrl}}',
'["displayName", "email", "unlockUrl", "lockoutMinutes"]', 'Account lockout notification', 'en_US'),

('new_device_login', 'New Device Login', 'New Login from Unknown Device',
'<h1>New Device Login Detected</h1><p>Hello {{displayName}},</p><p>We detected a login to your account from a new device:</p><ul><li>Device: {{deviceName}}</li><li>Location: {{location}}</li><li>IP Address: {{ipAddress}}</li><li>Time: {{loginTime}}</li></ul><p>If this was you, you can ignore this email. If not, please secure your account immediately.</p>',
'Hello {{displayName}}, New login detected. Device: {{deviceName}}, Location: {{location}}, IP: {{ipAddress}}, Time: {{loginTime}}. If this was not you, secure your account immediately.',
'["displayName", "deviceName", "location", "ipAddress", "loginTime"]', 'New device login alert', 'en_US');

-- ============================================================================
-- 🎯 Insert Default Subscription Tiers
-- ============================================================================
INSERT INTO `tblSubscriptionTiers` (`serviceID`, `tierName`, `tierSlug`, `tierDescription`, `monthlyPrice`, `yearlyPrice`, `features`, `featureLimits`, `displayOrder`, `isDefault`) VALUES
(NULL, 'Free', 'free', 'Basic access to SIGNula authentication', 0.00, 0.00,
'["Basic authentication", "Email support", "TOTP MFA", "3 linked accounts", "Standard security"]',
'{"linked_accounts": 3, "api_calls_per_hour": 100, "sessions": 3}', 1, TRUE),

(NULL, 'Basic', 'basic', 'Enhanced features for personal use', 4.99, 49.99,
'["All Free features", "10 linked accounts", "Priority email support", "Passwordless login", "Email MFA"]',
'{"linked_accounts": 10, "api_calls_per_hour": 500, "sessions": 5}', 2, FALSE),

(NULL, 'Premium', 'premium', 'Advanced features for power users', 9.99, 99.99,
'["All Basic features", "Unlimited linked accounts", "Priority support", "Passkey support", "Advanced security", "Custom templates"]',
'{"linked_accounts": -1, "api_calls_per_hour": 2000, "sessions": 10}', 3, FALSE),

(NULL, 'Enterprise', 'enterprise', 'Full-featured solution for organizations', 29.99, 299.99,
'["All Premium features", "Dedicated support", "Custom integrations", "SLA guarantee", "Advanced analytics", "White-label options"]',
'{"linked_accounts": -1, "api_calls_per_hour": 10000, "sessions": -1}', 4, FALSE);

-- ============================================================================
-- 🔐 Create Views for Common Queries
-- ============================================================================

-- View: Active Users with Current Tier
CREATE OR REPLACE VIEW `vw_ActiveUsers` AS
SELECT
    u.userID,
    u.userUUID,
    u.username,
    u.email,
    u.displayName,
    u.accountStatus,
    u.accountTier,
    u.lastLoginAt,
    COUNT(DISTINCT ul.linkID) AS linkedAccountsCount,
    COUNT(DISTINCT us.sessionID) AS activeSessionsCount
FROM tblUsers u
LEFT JOIN tblUserLinkedAccounts ul ON u.userID = ul.userID AND ul.isActive = TRUE
LEFT JOIN tblUserSessions us ON u.userID = us.userID AND us.isActive = TRUE AND us.expiresAt > NOW()
WHERE u.accountStatus = 'active' AND u.deletedAt IS NULL
GROUP BY u.userID;

-- View: User Security Summary
CREATE OR REPLACE VIEW `vw_UserSecurity` AS
SELECT
    u.userID,
    u.username,
    u.email,
    u.accountStatus,
    u.failedLoginAttempts,
    u.lastFailedLogin,
    u.lastLoginAt,
    u.lastLoginIP,
    COUNT(DISTINCT mfa.mfaID) AS mfaMethodsEnabled,
    MAX(CASE WHEN mfa.mfaType = 'totp' AND mfa.isEnabled = TRUE THEN 1 ELSE 0 END) AS hasTOTP,
    MAX(CASE WHEN mfa.mfaType = 'passkey' AND mfa.isEnabled = TRUE THEN 1 ELSE 0 END) AS hasPasskey
FROM tblUsers u
LEFT JOIN tblUserMFA mfa ON u.userID = mfa.userID AND mfa.isEnabled = TRUE
WHERE u.deletedAt IS NULL
GROUP BY u.userID;

-- ============================================================================
-- 🗑️ Cleanup Procedures
-- ============================================================================

-- Stored Procedure: Clean Expired Sessions
DELIMITER //
CREATE PROCEDURE sp_CleanExpiredSessions()
BEGIN
    DELETE FROM tblUserSessions
    WHERE expiresAt < NOW() AND isActive = FALSE;
END//
DELIMITER ;

-- Stored Procedure: Clean Expired Tokens
DELIMITER //
CREATE PROCEDURE sp_CleanExpiredTokens()
BEGIN
    DELETE FROM tblVerificationTokens
    WHERE expiresAt < NOW() AND isUsed = TRUE;
END//
DELIMITER ;

-- Stored Procedure: Clean Old Activity Logs (keep 1 year)
DELIMITER //
CREATE PROCEDURE sp_CleanOldActivityLogs()
BEGIN
    DELETE FROM tblActivityLog
    WHERE createdAt < DATE_SUB(NOW(), INTERVAL 1 YEAR);
END//
DELIMITER ;

-- ============================================================================
-- 📅 Create Events for Automated Cleanup (requires event scheduler)
-- ============================================================================
SET GLOBAL event_scheduler = ON;

-- Event: Clean expired sessions every hour
CREATE EVENT IF NOT EXISTS evt_CleanExpiredSessions
ON SCHEDULE EVERY 1 HOUR
DO CALL sp_CleanExpiredSessions();

-- Event: Clean expired tokens every day
CREATE EVENT IF NOT EXISTS evt_CleanExpiredTokens
ON SCHEDULE EVERY 1 DAY
DO CALL sp_CleanExpiredTokens();

-- Event: Clean old activity logs every week
CREATE EVENT IF NOT EXISTS evt_CleanOldActivityLogs
ON SCHEDULE EVERY 1 WEEK
DO CALL sp_CleanOldActivityLogs();

-- ============================================================================
-- ✅ Schema Creation Complete
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 1;

-- Display success message
SELECT 'SIGNula database schema created successfully!' AS Status,
       (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE') AS TablesCreated,
       (SELECT COUNT(*) FROM information_schema.views WHERE table_schema = DATABASE()) AS ViewsCreated;
