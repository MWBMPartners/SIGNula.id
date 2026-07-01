-- ============================================================================
-- SIGNula Database Migration 014
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- Migration: 014_security_enhancements.sql
-- Version: 2.6.0-beta
-- Date: 2026-02-19
-- Description: CAPTCHA integration, IP reputation checking, bot detection,
--              session fingerprinting, and security alerting system
--
-- Features Added:
--   - tblIPReputationCache for caching AbuseIPDB/proxycheck.io API results
--   - tblBlockedIPs for permanent/temporary IP blocking with audit trail
--   - tblSecurityAlerts for security event tracking and incident management
--   - tblSessionFingerprints for persistent session fingerprint audit records
--   - tblCircuitBreaker for tracking external API failure states
--   - ~30 new tblSettings entries for CAPTCHA, IP reputation, bot detection,
--     session fingerprinting, and security alerting configuration
--   - 4 new tblRateLimitConfig entries for web form rate limiting
--   - 4 MySQL scheduled events for automated cache/block/fingerprint cleanup
--
-- Dependencies:
--   - Migration 007 (tblRateLimits, tblRateLimitConfig must exist)
--   - Core tables (tblUsers, tblSettings, tblMigrations must exist)
--
-- Third-Party API Integration:
--   - AbuseIPDB (free 4,999 checks/day) — IP abuse scoring
--   - proxycheck.io (free 1,000 checks/day) — proxy/VPN/Tor detection
--   - CloudFlare Turnstile (free, unlimited) — CAPTCHA verification
--   - Google reCAPTCHA v3 (free 10K/month) — CAPTCHA verification (fallback)
--   - ip-api.com (free 45 req/min) — GeoIP for impossible travel detection
--
-- @see https://www.abuseipdb.com/api
-- @see https://proxycheck.io/api
-- @see https://developers.cloudflare.com/turnstile/
-- @see https://developers.google.com/recaptcha/docs/v3
-- ============================================================================

-- ============================================================================
-- 🗄️ TABLE 1: tblIPReputationCache
-- Purpose: Cache API results from AbuseIPDB and proxycheck.io to reduce
--          API calls and provide offline/fallback reputation data.
--          Default TTL: 24 hours (configurable via tblSettings)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblIPReputationCache` (
    `cacheID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
        COMMENT 'Unique cache entry identifier',
    `ipAddress` VARCHAR(45) NOT NULL
        COMMENT 'IPv4 or IPv6 address being checked',
    `abuseConfidenceScore` TINYINT UNSIGNED NULL
        COMMENT 'AbuseIPDB confidence score (0-100, higher = more abusive)',
    `totalReports` INT UNSIGNED NULL DEFAULT 0
        COMMENT 'Total number of abuse reports from AbuseIPDB',
    `isProxy` BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Whether IP is detected as a proxy server',
    `isVPN` BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Whether IP is detected as a VPN endpoint',
    `isTor` BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Whether IP is detected as a Tor exit node',
    `proxyType` VARCHAR(50) NULL
        COMMENT 'Proxy type classification: VPN, TOR, PROXY, SOCKS, etc.',
    `provider` VARCHAR(255) NULL
        COMMENT 'ISP or hosting provider name',
    `countryCode` CHAR(2) NULL
        COMMENT 'ISO 3166-1 alpha-2 country code (e.g. US, GB, DE)',
    `usageType` VARCHAR(100) NULL
        COMMENT 'Usage type from AbuseIPDB (e.g. Data Center/Web Hosting/Transit)',
    `apiSource` ENUM('abuseipdb', 'proxycheck', 'local', 'manual') NOT NULL
        COMMENT 'Which API or method provided this data',
    `rawResponse` JSON NULL
        COMMENT 'Full API response stored for debugging and audit purposes',
    `cachedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When this cache entry was created',
    `expiresAt` DATETIME NOT NULL
        COMMENT 'When this cache entry expires and should be refreshed',

    -- 🔑 Indexes
    UNIQUE KEY `idx_ip_source` (`ipAddress`, `apiSource`),
    INDEX `idx_expiresAt` (`expiresAt`),
    INDEX `idx_abuseScore` (`abuseConfidenceScore`),
    INDEX `idx_proxy_flags` (`isProxy`, `isVPN`, `isTor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Cached IP reputation results from external APIs (AbuseIPDB, proxycheck.io)';


-- ============================================================================
-- 🚫 TABLE 2: tblBlockedIPs
-- Purpose: Permanent and temporary IP blocks with full audit trail.
--          Supports individual IPs and optional CIDR range blocks.
--          Auto-blocks from rate limiting, IP reputation, and bot detection
--          are tracked separately from manual admin blocks.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblBlockedIPs` (
    `blockID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
        COMMENT 'Unique block entry identifier',
    `ipAddress` VARCHAR(45) NOT NULL
        COMMENT 'IPv4 or IPv6 address to block',
    `ipRangeStart` VARCHAR(45) NULL
        COMMENT 'Start of CIDR range for range blocks (NULL for single IP)',
    `ipRangeEnd` VARCHAR(45) NULL
        COMMENT 'End of CIDR range for range blocks (NULL for single IP)',
    `blockType` ENUM('permanent', 'temporary', 'auto') NOT NULL DEFAULT 'temporary'
        COMMENT 'permanent = manual forever, temporary = expires, auto = system-generated',
    `reason` VARCHAR(500) NOT NULL
        COMMENT 'Human-readable reason for the block',
    `source` ENUM('manual', 'rate_limit', 'ip_reputation', 'bot_detection', 'brute_force', 'security_alert') NOT NULL DEFAULT 'manual'
        COMMENT 'What triggered the block',
    `blockedByUserID` BIGINT UNSIGNED NULL
        COMMENT 'Admin user ID who created the block (NULL for auto-blocks)',
    `blockedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When the block was created',
    `expiresAt` DATETIME NULL
        COMMENT 'When the block expires (NULL for permanent blocks)',
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE
        COMMENT 'Whether this block is currently active',
    `unblockedAt` DATETIME NULL
        COMMENT 'When the block was manually lifted',
    `unblockedByUserID` BIGINT UNSIGNED NULL
        COMMENT 'Admin user ID who lifted the block',
    `unblockedReason` VARCHAR(500) NULL
        COMMENT 'Reason for lifting the block',

    -- 🔑 Foreign keys
    FOREIGN KEY (`blockedByUserID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (`unblockedByUserID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL ON UPDATE CASCADE,

    -- 🔑 Indexes
    UNIQUE KEY `idx_active_ip` (`ipAddress`, `isActive`),
    INDEX `idx_expiresAt` (`expiresAt`),
    INDEX `idx_blockType` (`blockType`),
    INDEX `idx_source` (`source`),
    INDEX `idx_isActive` (`isActive`),
    INDEX `idx_blockedAt` (`blockedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Permanently and temporarily blocked IP addresses with audit trail';


-- ============================================================================
-- 🚨 TABLE 3: tblSecurityAlerts
-- Purpose: Track security events and incidents with full lifecycle management.
--          Supports alert creation, acknowledgement, investigation, and resolution.
--          Email notifications sent for alerts above configurable severity threshold.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblSecurityAlerts` (
    `alertID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
        COMMENT 'Unique alert identifier',
    `alertType` VARCHAR(50) NOT NULL
        COMMENT 'Alert type: brute_force, impossible_travel, session_hijack, ip_blocked, bot_detected, rate_limit_exceeded, password_spray, suspicious_registration, captcha_failure',
    `severity` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium'
        COMMENT 'Alert severity level',
    `description` TEXT NOT NULL
        COMMENT 'Human-readable description of the security event',
    `metadata` JSON NULL
        COMMENT 'Additional context: failed attempts count, geo data, detected patterns, etc.',
    `userID` BIGINT UNSIGNED NULL
        COMMENT 'Affected user ID (if the alert relates to a specific user)',
    `ipAddress` VARCHAR(45) NULL
        COMMENT 'Source IP address of the suspicious activity',
    `userAgent` TEXT NULL
        COMMENT 'User-Agent string from the suspicious request',
    `requestURL` TEXT NULL
        COMMENT 'URL of the request that triggered the alert',
    `status` ENUM('new', 'acknowledged', 'investigating', 'resolved', 'false_positive') NOT NULL DEFAULT 'new'
        COMMENT 'Current alert lifecycle status',
    `acknowledgedAt` DATETIME NULL
        COMMENT 'When an admin first acknowledged the alert',
    `acknowledgedByUserID` BIGINT UNSIGNED NULL
        COMMENT 'Admin who acknowledged the alert',
    `resolvedAt` DATETIME NULL
        COMMENT 'When the alert was resolved',
    `resolvedByUserID` BIGINT UNSIGNED NULL
        COMMENT 'Admin who resolved the alert',
    `resolution` TEXT NULL
        COMMENT 'Resolution notes explaining what action was taken',
    `notificationSent` BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Whether email notification was sent for this alert',
    `notificationSentAt` DATETIME NULL
        COMMENT 'When the email notification was sent',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When the alert was created',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        COMMENT 'When the alert was last modified',

    -- 🔑 Foreign keys
    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (`acknowledgedByUserID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (`resolvedByUserID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL ON UPDATE CASCADE,

    -- 🔑 Indexes
    INDEX `idx_alertType` (`alertType`),
    INDEX `idx_severity` (`severity`),
    INDEX `idx_status` (`status`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_ipAddress` (`ipAddress`),
    INDEX `idx_createdAt` (`createdAt`),
    INDEX `idx_type_severity` (`alertType`, `severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Security alerts and incident tracking with full lifecycle management';


-- ============================================================================
-- 🔑 TABLE 4: tblSessionFingerprints
-- Purpose: Persistent record of session fingerprints for audit and analysis.
--          Primary fingerprint validation uses $_SESSION (in-memory), this table
--          provides historical tracking and mismatch auditing.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblSessionFingerprints` (
    `fingerprintID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
        COMMENT 'Unique fingerprint record identifier',
    `sessionID` VARCHAR(128) NOT NULL
        COMMENT 'PHP session ID (from session_id())',
    `userID` BIGINT UNSIGNED NULL
        COMMENT 'User ID associated with this session (NULL for unauthenticated)',
    `fingerprintHash` VARCHAR(64) NOT NULL
        COMMENT 'SHA-256 hash of the session fingerprint components',
    `ipAddress` VARCHAR(45) NOT NULL
        COMMENT 'Client IP address at fingerprint creation',
    `userAgent` TEXT NULL
        COMMENT 'User-Agent string at fingerprint creation',
    `acceptLanguage` VARCHAR(255) NULL
        COMMENT 'Accept-Language header at fingerprint creation',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When the fingerprint was first created',
    `lastValidatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        COMMENT 'When the fingerprint was last successfully validated',
    `mismatchCount` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of times fingerprint validation failed for this session',

    -- 🔑 Foreign keys
    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,

    -- 🔑 Indexes
    UNIQUE KEY `idx_session` (`sessionID`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_fingerprintHash` (`fingerprintHash`),
    INDEX `idx_createdAt` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Session fingerprints for hijacking detection and audit';


-- ============================================================================
-- ⚡ TABLE 5: tblCircuitBreaker
-- Purpose: Track external API failure states for the circuit breaker pattern.
--          When an API fails repeatedly, the circuit "opens" to skip calls for
--          a cooldown period, preventing cascading failures and wasted API quota.
--
-- Circuit states:
--   closed    = Normal operation, API calls proceed
--   open      = API calls skipped (too many recent failures)
--   half_open = Cooldown elapsed, next request will test the API
--
-- @see https://martinfowler.com/bliki/CircuitBreaker.html
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblCircuitBreaker` (
    `circuitID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
        COMMENT 'Unique circuit identifier',
    `serviceName` VARCHAR(100) NOT NULL UNIQUE
        COMMENT 'External service name: abuseipdb, proxycheck, geoip, etc.',
    `failureCount` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Consecutive failure count since last success',
    `lastFailureAt` DATETIME NULL
        COMMENT 'Timestamp of the most recent API failure',
    `lastSuccessAt` DATETIME NULL
        COMMENT 'Timestamp of the most recent API success',
    `circuitState` ENUM('closed', 'open', 'half_open') NOT NULL DEFAULT 'closed'
        COMMENT 'Current circuit breaker state',
    `stateChangedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When the circuit state last changed',

    -- 🔑 Indexes
    INDEX `idx_state` (`circuitState`),
    INDEX `idx_serviceName` (`serviceName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Circuit breaker state tracking for external API calls';

-- 📌 Initialize circuit breaker entries for known external services
INSERT INTO `tblCircuitBreaker` (`serviceName`, `circuitState`) VALUES
    ('abuseipdb', 'closed'),
    ('proxycheck', 'closed'),
    ('geoip', 'closed')
ON DUPLICATE KEY UPDATE `serviceName` = VALUES(`serviceName`);


-- ============================================================================
-- ⚙️ SETTINGS: CAPTCHA Configuration
-- ============================================================================

-- 🔒 CloudFlare Turnstile (primary CAPTCHA provider — completely free, no limits)
-- @see https://developers.cloudflare.com/turnstile/get-started/
-- @see https://dash.cloudflare.com/?to=/:account/turnstile

-- 🔒 Google reCAPTCHA v3 (fallback CAPTCHA provider — free up to 10K/month)
-- @see https://developers.google.com/recaptcha/docs/v3
-- @see https://www.google.com/recaptcha/admin

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    -- 🛡️ CAPTCHA — Global Toggle
    ('captcha.enabled', '0', 'boolean', 'security', FALSE,
        'Enable CAPTCHA verification on forms. Requires at least one provider to be configured with valid keys.'),
    ('captcha.provider', 'turnstile', 'string', 'security', FALSE,
        'Primary CAPTCHA provider: turnstile (recommended, free), recaptcha (Google reCAPTCHA v3), or none. Falls back automatically if primary provider keys are missing.'),
    ('captcha.required_forms', '["login","register","forgot-password","contact"]', 'json', 'security', FALSE,
        'JSON array of form names that require CAPTCHA verification when CAPTCHA is enabled.'),

    -- ☁️ CloudFlare Turnstile Keys
    ('captcha.turnstile.site_key', '', 'string', 'security', FALSE,
        'CloudFlare Turnstile site key (public, used in HTML widget). Get from Cloudflare dashboard > Turnstile.'),
    ('captcha.turnstile.secret_key', '', 'encrypted', 'security', TRUE,
        'CloudFlare Turnstile secret key (private, used for server-side verification). Keep confidential.'),

    -- 🔵 Google reCAPTCHA v3 Keys
    ('captcha.recaptcha.site_key', '', 'string', 'security', FALSE,
        'Google reCAPTCHA v3 site key (public). Get from https://www.google.com/recaptcha/admin'),
    ('captcha.recaptcha.secret_key', '', 'encrypted', 'security', TRUE,
        'Google reCAPTCHA v3 secret key (private, used for server-side verification). Keep confidential.'),
    ('captcha.recaptcha.min_score', '0.5', 'string', 'security', FALSE,
        'Minimum reCAPTCHA v3 score to pass verification (0.0 = likely bot, 1.0 = likely human). Default 0.5.')
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);


-- ============================================================================
-- ⚙️ SETTINGS: IP Reputation Configuration
-- ============================================================================

-- 🔍 AbuseIPDB (free 4,999 checks/day)
-- @see https://www.abuseipdb.com/account/api
-- @see https://docs.abuseipdb.com/#check-endpoint

-- 🔍 proxycheck.io (free 1,000 checks/day)
-- @see https://proxycheck.io/api/
-- @see https://proxycheck.io/dashboard

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    -- 🛡️ IP Reputation — Global Toggle
    ('security.ip_reputation.enabled', '0', 'boolean', 'security', FALSE,
        'Enable IP reputation checking using AbuseIPDB and proxycheck.io. Requires at least one API key to be configured.'),

    -- 🔑 API Keys
    ('security.ip_reputation.abuseipdb_api_key', '', 'encrypted', 'security', TRUE,
        'AbuseIPDB API key for IP abuse checking (free tier: 4,999 checks/day). Get from https://www.abuseipdb.com/account/api'),
    ('security.ip_reputation.proxycheck_api_key', '', 'encrypted', 'security', TRUE,
        'proxycheck.io API key for proxy/VPN/Tor detection (free tier: 1,000 checks/day). Get from https://proxycheck.io/dashboard'),

    -- 📊 Thresholds and Blocking Rules
    ('security.ip_reputation.abuse_score_threshold', '50', 'integer', 'security', FALSE,
        'AbuseIPDB confidence score threshold (0-100) above which an IP is blocked. Lower = stricter. Recommended: 50.'),
    ('security.ip_reputation.block_proxy', '1', 'boolean', 'security', FALSE,
        'Block IPs detected as open proxies. Recommended: enabled.'),
    ('security.ip_reputation.block_vpn', '0', 'boolean', 'security', FALSE,
        'Block IPs detected as VPN endpoints. Warning: may block legitimate users on VPNs. Default: disabled.'),
    ('security.ip_reputation.block_tor', '1', 'boolean', 'security', FALSE,
        'Block IPs detected as Tor exit nodes. Recommended: enabled for authentication systems.'),

    -- ⏱️ Cache and Performance
    ('security.ip_reputation.cache_ttl_hours', '24', 'integer', 'security', FALSE,
        'Hours to cache IP reputation results before refreshing. Higher = fewer API calls but staler data. Default: 24.'),

    -- 📤 Abuse Reporting
    ('security.ip_reputation.report_enabled', '0', 'boolean', 'security', FALSE,
        'Enable automatic reporting of abusive IPs back to AbuseIPDB. Requires AbuseIPDB API key with report permission.'),

    -- ✅ Whitelist
    ('security.ip_reputation.whitelist', '[]', 'json', 'security', FALSE,
        'JSON array of IP addresses to never block regardless of reputation score (e.g. ["203.0.113.1","198.51.100.0/24"]). Use for known-good IPs.')
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);


-- ============================================================================
-- ⚙️ SETTINGS: Bot Detection Configuration
-- ============================================================================

-- 🤖 Bot detection uses the CrawlerDetect library (local, no API calls) with
--    regex pattern matching as a fallback. Good bots (Google, Bing) are allowed.
-- @see https://github.com/JayBizzle/Crawler-Detect

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('security.bot_detection.enabled', '1', 'boolean', 'security', FALSE,
        'Enable bot detection. Uses CrawlerDetect library (local) with regex fallback. No API calls required.'),
    ('security.bot_detection.block_bad_bots', '1', 'boolean', 'security', FALSE,
        'Block detected bad/malicious bots. Good bots (Googlebot, Bingbot) are always allowed.'),
    ('security.bot_detection.allow_good_bots', '1', 'boolean', 'security', FALSE,
        'Allow known good search engine crawlers (Googlebot, Bingbot, DuckDuckBot, Yandex, etc.) to pass through.'),
    ('security.bot_detection.dns_verify', '0', 'boolean', 'security', FALSE,
        'Verify good bot identity via reverse DNS lookup. More secure but adds latency. Catches fake Googlebots.'),
    ('security.bot_detection.block_empty_ua', '0', 'boolean', 'security', FALSE,
        'Block requests with empty or missing User-Agent header. Warning: may block some legitimate automated tools.')
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);


-- ============================================================================
-- ⚙️ SETTINGS: Session Fingerprinting Configuration
-- ============================================================================

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('security.session_fingerprinting.enabled', '1', 'boolean', 'security', FALSE,
        'Enable session fingerprinting to detect session hijacking. Compares request characteristics against stored fingerprint.'),
    ('security.session_fingerprinting.ip_match', 'subnet', 'string', 'security', FALSE,
        'IP matching mode for fingerprints: exact (strictest, may cause issues with mobile/dynamic IPs), subnet (matches /24 for IPv4 — recommended), none (ignores IP, least strict).'),
    ('security.session_fingerprinting.salt', '', 'encrypted', 'security', TRUE,
        'Server-side salt for fingerprint hashing. Auto-generated on first use if empty. Changing this invalidates all existing sessions.'),
    ('security.session_fingerprinting.on_mismatch', 'invalidate', 'string', 'security', FALSE,
        'Action when fingerprint mismatch detected: invalidate (destroy session + force re-login), warn (log alert but allow), log (only log, no action).')
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);


-- ============================================================================
-- ⚙️ SETTINGS: Security Alerts Configuration
-- ============================================================================

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('security.alerts.enabled', '1', 'boolean', 'security', FALSE,
        'Enable the security alerting system. Creates alerts in tblSecurityAlerts for suspicious events.'),
    ('security.alerts.admin_emails', '[]', 'json', 'security', FALSE,
        'JSON array of admin email addresses to receive security alert notifications (e.g. ["admin@example.com"]).'),
    ('security.alerts.notify_threshold', 'high', 'string', 'security', FALSE,
        'Minimum severity level for sending email notifications: low, medium, high, critical. Default: high.'),
    ('security.alerts.brute_force_threshold', '5', 'integer', 'security', FALSE,
        'Number of consecutive failed login attempts from the same IP before triggering a brute force alert.'),
    ('security.alerts.password_spray_threshold', '10', 'integer', 'security', FALSE,
        'Number of distinct usernames attempted from the same IP within a time window before triggering a password spray alert.'),
    ('security.alerts.password_spray_window_minutes', '30', 'integer', 'security', FALSE,
        'Time window (in minutes) for detecting password spray attacks. Counts distinct usernames per IP within this window.'),
    ('security.alerts.impossible_travel_km', '500', 'integer', 'security', FALSE,
        'Distance in kilometres between consecutive logins that triggers impossible travel alert. Default: 500km.'),
    ('security.alerts.impossible_travel_minutes', '60', 'integer', 'security', FALSE,
        'Time window (in minutes) for impossible travel detection. If two logins > distance_km apart happen within this window, alert triggers.')
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);


-- ============================================================================
-- ⏱️ RATE LIMIT CONFIGS: Web Form Rate Limiting
-- Purpose: Add rate limit rules for web form pages (not just API endpoints).
--          These complement the existing API endpoint limits from migration 007.
-- ============================================================================

-- 📝 Web form rate limits (IP-based, for page POST submissions)
-- These use the same tblRateLimitConfig table and RateLimiter class as API limits
INSERT INTO `tblRateLimitConfig`
    (`identifierType`, `endpoint`, `tier`, `requestsPerHour`, `requestsPerMinute`, `burstLimit`, `burstWindow`, `description`)
VALUES
    ('ip', '/register', 'default', 10, 3, 5, 60,
        'Registration form — prevent spam registrations and account farming'),
    ('ip', '/contact', 'default', 10, 2, 3, 60,
        'Contact form — prevent spam submissions'),
    ('ip', '/login', 'default', 30, 5, 10, 60,
        'Login form — allow reasonable retries while preventing brute force'),
    ('ip', '/forgot-password', 'default', 5, 1, 3, 300,
        'Forgot password form — strict limits to prevent email enumeration and abuse')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);


-- ============================================================================
-- 🧹 SCHEDULED EVENTS: Automated Cleanup
-- Purpose: MySQL scheduled events to clean up expired data automatically.
--          No PHP cron required — important for Dreamhost shared hosting.
-- ============================================================================

-- 🗑️ Clean up expired IP reputation cache entries (hourly)
DROP EVENT IF EXISTS `cleanup_ip_reputation_cache`;
CREATE EVENT IF NOT EXISTS `cleanup_ip_reputation_cache`
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
COMMENT 'Remove expired IP reputation cache entries to keep the table lean'
DO
    DELETE FROM `tblIPReputationCache`
    WHERE `expiresAt` < NOW();

-- 🗑️ Deactivate expired temporary IP blocks (every 15 minutes)
DROP EVENT IF EXISTS `cleanup_expired_ip_blocks`;
CREATE EVENT IF NOT EXISTS `cleanup_expired_ip_blocks`
ON SCHEDULE EVERY 15 MINUTE
STARTS CURRENT_TIMESTAMP
COMMENT 'Deactivate temporary IP blocks that have expired'
DO
    UPDATE `tblBlockedIPs`
    SET `isActive` = FALSE
    WHERE `blockType` IN ('temporary', 'auto')
        AND `expiresAt` IS NOT NULL
        AND `expiresAt` < NOW()
        AND `isActive` = TRUE;

-- 🗑️ Clean up old session fingerprints (every 6 hours)
DROP EVENT IF EXISTS `cleanup_session_fingerprints`;
CREATE EVENT IF NOT EXISTS `cleanup_session_fingerprints`
ON SCHEDULE EVERY 6 HOUR
STARTS CURRENT_TIMESTAMP
COMMENT 'Remove session fingerprints older than 7 days'
DO
    DELETE FROM `tblSessionFingerprints`
    WHERE `lastValidatedAt` < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- 🔄 Reset circuit breakers that have cooled down (every 5 minutes)
DROP EVENT IF EXISTS `reset_circuit_breakers`;
CREATE EVENT IF NOT EXISTS `reset_circuit_breakers`
ON SCHEDULE EVERY 5 MINUTE
STARTS CURRENT_TIMESTAMP
COMMENT 'Reset circuit breakers from open to half_open after cooldown period'
DO
    UPDATE `tblCircuitBreaker`
    SET `circuitState` = 'half_open',
        `failureCount` = 0,
        `stateChangedAt` = NOW()
    WHERE `circuitState` = 'open'
        AND `lastFailureAt` < DATE_SUB(NOW(), INTERVAL 5 MINUTE);


-- ============================================================================
-- 📋 MIGRATION TRACKING
-- ============================================================================

-- [mig-fix] removed incompatible tblMigrations self-bookkeeping (the migration runner records migrations)


-- ============================================================================
-- END OF MIGRATION 014
-- ============================================================================
