-- ============================================================================
-- SIGNula Database Schema
-- 
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
-- 
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- =====================================================
-- SIGNula Partner API Key Management System
-- Migration: 008_partner_api_keys.sql
-- Version: 2.2.0-beta
-- Date: 2026-02-04
-- Description: Partner organization and API key management
-- =====================================================

-- Use the signula database
USE signula;

-- =====================================================
-- 1. Partners Table
-- =====================================================

CREATE TABLE IF NOT EXISTS tblPartners (
    partnerID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

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
    keyID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    partnerID BIGINT UNSIGNED NOT NULL,

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
    keyID BIGINT UNSIGNED NOT NULL,

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
    keyID BIGINT UNSIGNED NULL,
    partnerID BIGINT UNSIGNED NULL,

    -- Action details
    action ENUM('key_generated', 'key_revoked', 'key_regenerated', 'key_updated', 'permissions_changed', 'whitelist_updated') NOT NULL,
    actionDetails TEXT NULL COMMENT 'JSON with action details',

    -- Who performed the action
    performedBy BIGINT UNSIGNED NULL COMMENT 'UserID who performed the action',
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

INSERT IGNORE INTO tblSettings (settingKey, settingValue, settingType, isSensitive, settingCategory, description) VALUES
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

-- [mig-fix] removed incompatible tblMigrations self-bookkeeping (the migration runner records migrations)

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
