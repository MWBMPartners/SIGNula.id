-- ============================================================================
-- SIGNula Database Schema
-- 
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
-- 
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- =====================================================
-- SIGNula Rate Limiting System
-- Migration: 007_rate_limiting.sql
-- Version: 2.2.0-beta
-- Date: 2026-02-04
-- Description: Implements rate limiting to prevent API abuse
-- =====================================================

-- Use the signula database
USE signula;

-- =====================================================
-- 1. Rate Limits Tracking Table
-- =====================================================

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
('ip', 'global', 'default', 100, 10, 20, 10, 'Default rate limit for unauthenticated requests'),
('ip', '/api/v1/auth/login', 'default', 20, 5, 10, 60, 'Login endpoint - stricter to prevent brute force'),
('ip', '/api/v1/auth/register', 'default', 10, 2, 5, 60, 'Registration endpoint - prevent spam'),
('ip', '/api/v1/auth/forgot-password', 'default', 5, 1, 3, 300, 'Password reset - very strict to prevent abuse'),
('ip', '/api/v1/auth/reset-password', 'default', 10, 2, 5, 60, 'Password reset confirmation');

-- User-based limits (authenticated users)
INSERT INTO tblRateLimitConfig (identifierType, endpoint, tier, requestsPerHour, requestsPerMinute, burstLimit, burstWindow, description) VALUES
('user', 'global', 'free', 500, 50, 30, 10, 'Free tier user rate limit'),
('user', 'global', 'basic', 1000, 100, 50, 10, 'Basic tier user rate limit'),
('user', 'global', 'premium', 5000, 500, 100, 10, 'Premium tier user rate limit'),
('user', 'global', 'enterprise', 50000, 5000, 500, 10, 'Enterprise tier user rate limit');

-- API Key-based limits (partner integrations)
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
