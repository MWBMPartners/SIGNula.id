# 🔒 SIGNula Security Enhancements Roadmap

**Version:** 2.1.0-beta
**Date:** February 4, 2026
**Priority:** HIGH (Required before production launch)

---

## 🎯 Overview

This document outlines the security enhancements needed to bring SIGNula from 80% security score to 95%+ before public production launch.

**Current Security Status:** 80% (B+ - Excellent foundation)
**Target Security Status:** 95%+ (A - Production-grade)

---

## 🔴 HIGH PRIORITY (Implement Immediately)

### 1. Rate Limiting System

**Status:** ❌ Not Implemented
**Risk Level:** CRITICAL
**Effort:** ~8 hours
**Impact:** Prevents API abuse, brute force attacks, DDoS

#### 1.1 Architecture Design

**Strategy:** Token bucket algorithm with Redis/Database storage

**Rate Limit Tiers:**
```
Unauthenticated Requests:
- 100 requests/hour per IP
- 10 requests/minute per endpoint per IP

Authenticated Requests:
- 1000 requests/hour per user
- 100 requests/minute per endpoint per user

Partner API Keys:
- 10,000 requests/hour per key (configurable per tier)
- 500 requests/minute per endpoint per key
```

**Burst Allowance:**
- Allow burst of 20 requests in 10 seconds
- Then enforce rate limit

#### 1.2 Database Schema

```sql
-- _database/migrations/007_rate_limiting.sql

-- Rate limit tracking table
CREATE TABLE IF NOT EXISTS tblRateLimits (
    limitID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL COMMENT 'IP address, userID, or API key',
    identifierType ENUM('ip', 'user', 'api_key') NOT NULL,
    endpoint VARCHAR(255) NOT NULL COMMENT 'API endpoint or "global"',
    requestCount INT UNSIGNED NOT NULL DEFAULT 0,
    windowStart DATETIME NOT NULL,
    windowEnd DATETIME NOT NULL,
    isBlocked BOOLEAN DEFAULT 0,
    blockedUntil DATETIME NULL,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_limit (identifier, identifierType, endpoint, windowStart),
    INDEX idx_window (windowEnd),
    INDEX idx_blocked (isBlocked, blockedUntil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limit configuration
CREATE TABLE IF NOT EXISTS tblRateLimitConfig (
    configID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    identifierType ENUM('ip', 'user', 'api_key') NOT NULL,
    endpoint VARCHAR(255) NOT NULL COMMENT '"global" or specific endpoint',
    tier VARCHAR(50) DEFAULT 'default' COMMENT 'free, basic, premium, enterprise',
    requestsPerHour INT UNSIGNED NOT NULL,
    requestsPerMinute INT UNSIGNED NOT NULL,
    burstLimit INT UNSIGNED NOT NULL,
    burstWindow INT UNSIGNED NOT NULL COMMENT 'Seconds',
    isActive BOOLEAN DEFAULT 1,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_config (identifierType, endpoint, tier),
    INDEX idx_active (isActive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default rate limit configurations
INSERT INTO tblRateLimitConfig (identifierType, endpoint, tier, requestsPerHour, requestsPerMinute, burstLimit, burstWindow) VALUES
-- IP-based (unauthenticated)
('ip', 'global', 'default', 100, 10, 20, 10),
('ip', '/api/v1/auth/login', 'default', 20, 5, 10, 60),
('ip', '/api/v1/auth/register', 'default', 10, 2, 5, 60),
('ip', '/api/v1/auth/forgot-password', 'default', 5, 1, 3, 300),

-- User-based (authenticated)
('user', 'global', 'default', 1000, 100, 50, 10),
('user', 'global', 'premium', 5000, 500, 100, 10),
('user', 'global', 'enterprise', 50000, 5000, 500, 10),

-- API Key-based (partners)
('api_key', 'global', 'free', 1000, 100, 50, 10),
('api_key', 'global', 'basic', 10000, 500, 200, 10),
('api_key', 'global', 'premium', 50000, 2000, 500, 10),
('api_key', 'global', 'enterprise', 100000, 5000, 1000, 10);

-- Cleanup expired rate limit records (run via cron)
CREATE EVENT IF NOT EXISTS cleanup_rate_limits
ON SCHEDULE EVERY 1 HOUR
DO
DELETE FROM tblRateLimits
WHERE windowEnd < DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

#### 1.3 RateLimiter Class

**File:** `private_html/security/RateLimiter.php`

```php
<?php
/**
 * Rate Limiter
 *
 * Implements token bucket algorithm for API rate limiting
 * Prevents abuse, brute force, and DDoS attacks
 *
 * @version 2.1.0
 */

class RateLimiter {
    private $db;
    private $config;

    /**
     * Check if request is allowed under rate limit
     *
     * @param string $identifier IP address, userID, or API key
     * @param string $identifierType 'ip', 'user', or 'api_key'
     * @param string $endpoint Endpoint being accessed
     * @param string $tier Rate limit tier (default, premium, enterprise)
     * @return array ['allowed' => bool, 'limit' => int, 'remaining' => int, 'reset' => timestamp]
     */
    public function checkLimit(string $identifier, string $identifierType, string $endpoint = 'global', string $tier = 'default'): array {
        // Get rate limit config
        $config = $this->getConfig($identifierType, $endpoint, $tier);

        if (!$config) {
            // No config = allow (fail open)
            return [
                'allowed' => true,
                'limit' => PHP_INT_MAX,
                'remaining' => PHP_INT_MAX,
                'reset' => time() + 3600
            ];
        }

        // Calculate time windows
        $now = new DateTime();
        $hourStart = (clone $now)->modify('-1 hour');
        $minuteStart = (clone $now)->modify('-1 minute');
        $burstStart = (clone $now)->modify("-{$config['burstWindow']} seconds");

        // Get current counts
        $hourCount = $this->getRequestCount($identifier, $identifierType, $endpoint, $hourStart, $now);
        $minuteCount = $this->getRequestCount($identifier, $identifierType, $endpoint, $minuteStart, $now);
        $burstCount = $this->getRequestCount($identifier, $identifierType, $endpoint, $burstStart, $now);

        // Check if blocked
        if ($this->isBlocked($identifier, $identifierType, $endpoint)) {
            return [
                'allowed' => false,
                'limit' => $config['requestsPerHour'],
                'remaining' => 0,
                'reset' => $this->getBlockedUntil($identifier, $identifierType, $endpoint),
                'error' => 'Rate limit exceeded. Try again later.'
            ];
        }

        // Check limits
        $hourlyExceeded = $hourCount >= $config['requestsPerHour'];
        $minuteExceeded = $minuteCount >= $config['requestsPerMinute'];
        $burstExceeded = $burstCount >= $config['burstLimit'];

        if ($hourlyExceeded || $minuteExceeded || $burstExceeded) {
            // Block for increasing durations on repeated violations
            $blockDuration = $this->calculateBlockDuration($identifier, $identifierType);
            $this->blockIdentifier($identifier, $identifierType, $endpoint, $blockDuration);

            return [
                'allowed' => false,
                'limit' => $config['requestsPerHour'],
                'remaining' => 0,
                'reset' => time() + $blockDuration,
                'error' => 'Rate limit exceeded. Blocked for ' . ($blockDuration / 60) . ' minutes.'
            ];
        }

        // Record request
        $this->recordRequest($identifier, $identifierType, $endpoint);

        return [
            'allowed' => true,
            'limit' => $config['requestsPerHour'],
            'remaining' => $config['requestsPerHour'] - $hourCount - 1,
            'reset' => strtotime('+1 hour', strtotime($hourStart->format('Y-m-d H:i:s')))
        ];
    }

    /**
     * Record a request for rate limiting
     */
    private function recordRequest(string $identifier, string $identifierType, string $endpoint): void {
        $stmt = $this->db->prepare("
            INSERT INTO tblRateLimits (identifier, identifierType, endpoint, requestCount, windowStart, windowEnd)
            VALUES (?, ?, ?, 1, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))
            ON DUPLICATE KEY UPDATE
                requestCount = requestCount + 1,
                updatedAt = NOW()
        ");
        $stmt->bind_param('sss', $identifier, $identifierType, $endpoint);
        $stmt->execute();
    }

    /**
     * Get request count in time window
     */
    private function getRequestCount(string $identifier, string $identifierType, string $endpoint, DateTime $start, DateTime $end): int {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(requestCount), 0) as total
            FROM tblRateLimits
            WHERE identifier = ?
            AND identifierType = ?
            AND endpoint = ?
            AND windowStart >= ?
            AND windowStart <= ?
        ");

        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');

        $stmt->bind_param('sssss', $identifier, $identifierType, $endpoint, $startStr, $endStr);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return (int)$result['total'];
    }

    /**
     * Check if identifier is blocked
     */
    private function isBlocked(string $identifier, string $identifierType, string $endpoint): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as blocked
            FROM tblRateLimits
            WHERE identifier = ?
            AND identifierType = ?
            AND endpoint = ?
            AND isBlocked = 1
            AND (blockedUntil IS NULL OR blockedUntil > NOW())
        ");
        $stmt->bind_param('sss', $identifier, $identifierType, $endpoint);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return $result['blocked'] > 0;
    }

    /**
     * Block identifier for specified duration
     */
    private function blockIdentifier(string $identifier, string $identifierType, string $endpoint, int $duration): void {
        $stmt = $this->db->prepare("
            UPDATE tblRateLimits
            SET isBlocked = 1,
                blockedUntil = DATE_ADD(NOW(), INTERVAL ? SECOND)
            WHERE identifier = ?
            AND identifierType = ?
            AND endpoint = ?
        ");
        $stmt->bind_param('isss', $duration, $identifier, $identifierType, $endpoint);
        $stmt->execute();

        // Log blocking event
        ActivityLogger::log([
            'activityType' => 'rate_limit_block',
            'activityResult' => 'blocked',
            'activityDetails' => json_encode([
                'identifier' => $identifier,
                'identifierType' => $identifierType,
                'endpoint' => $endpoint,
                'duration' => $duration
            ]),
            'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }

    /**
     * Calculate block duration based on violation history
     * Progressive blocking: 1 min, 5 min, 15 min, 1 hour, 24 hours
     */
    private function calculateBlockDuration(string $identifier, string $identifierType): int {
        // Get recent violations count
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as violations
            FROM tblRateLimits
            WHERE identifier = ?
            AND identifierType = ?
            AND isBlocked = 1
            AND blockedUntil > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->bind_param('ss', $identifier, $identifierType);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $violations = (int)$result['violations'];

        // Progressive blocking
        if ($violations == 0) return 60;          // 1 minute
        if ($violations == 1) return 300;         // 5 minutes
        if ($violations == 2) return 900;         // 15 minutes
        if ($violations == 3) return 3600;        // 1 hour
        return 86400;                              // 24 hours
    }
}
```

#### 1.4 Middleware Integration

**File:** `private_html/api/RateLimitMiddleware.php`

```php
<?php
/**
 * Rate Limit Middleware
 *
 * Applies rate limiting to all API requests
 */

class RateLimitMiddleware {
    private $rateLimiter;

    public function handle(): void {
        $this->rateLimiter = new RateLimiter();

        // Determine identifier and type
        $identifier = $this->getIdentifier();
        $identifierType = $this->getIdentifierType();
        $endpoint = $_SERVER['REQUEST_URI'] ?? 'global';
        $tier = $this->getTier();

        // Check rate limit
        $result = $this->rateLimiter->checkLimit($identifier, $identifierType, $endpoint, $tier);

        // Add rate limit headers
        header('X-RateLimit-Limit: ' . $result['limit']);
        header('X-RateLimit-Remaining: ' . $result['remaining']);
        header('X-RateLimit-Reset: ' . $result['reset']);

        // Block if exceeded
        if (!$result['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . ($result['reset'] - time()));

            Response::error($result['error'] ?? 'Too many requests', 429, [
                'rate_limit' => $result
            ]);
            exit;
        }
    }

    private function getIdentifier(): string {
        // Check for API key
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
        if ($apiKey) {
            return hash('sha256', $apiKey); // Hash for privacy
        }

        // Check for authenticated user
        if (isset($_SESSION['userID'])) {
            return 'user_' . $_SESSION['userID'];
        }

        // Fall back to IP address
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    private function getIdentifierType(): string {
        if (isset($_SERVER['HTTP_X_API_KEY']) || isset($_GET['api_key'])) {
            return 'api_key';
        }
        if (isset($_SESSION['userID'])) {
            return 'user';
        }
        return 'ip';
    }

    private function getTier(): string {
        // Get tier from API key or user account
        if ($this->getIdentifierType() === 'api_key') {
            // Look up API key tier from database
            return 'default'; // TODO: Implement API key tier lookup
        }
        if ($this->getIdentifierType() === 'user') {
            // Look up user account tier
            return $_SESSION['accountTier'] ?? 'default';
        }
        return 'default';
    }
}
```

#### 1.5 Integration with Router

**File:** `public_html/api/v1/index.php` (modify existing)

```php
// Add at the beginning, after loading dependencies
require_once __DIR__ . '/../../../private_html/security/RateLimiter.php';
require_once __DIR__ . '/../../../private_html/api/RateLimitMiddleware.php';

// Apply rate limiting middleware
$rateLimitMiddleware = new RateLimitMiddleware();
$rateLimitMiddleware->handle();

// Continue with existing router logic...
```

---

### 2. Partner API Key Management System

**Status:** ❌ Not Implemented
**Risk Level:** HIGH
**Effort:** ~16 hours
**Impact:** Enables secure partner integration

#### 2.1 Database Schema

```sql
-- _database/migrations/008_partner_api_keys.sql

-- Partner organizations table
CREATE TABLE IF NOT EXISTS tblPartners (
    partnerID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    partnerName VARCHAR(255) NOT NULL,
    partnerEmail VARCHAR(255) NOT NULL,
    contactName VARCHAR(255),
    contactPhone VARCHAR(50),
    companyWebsite VARCHAR(255),
    accountTier ENUM('free', 'basic', 'premium', 'enterprise') DEFAULT 'free',
    isActive BOOLEAN DEFAULT 1,
    isVerified BOOLEAN DEFAULT 0,
    verifiedAt DATETIME NULL,

    -- Rate limiting
    customRateLimit INT UNSIGNED NULL COMMENT 'Custom rate limit override',

    -- Security
    ipWhitelist TEXT NULL COMMENT 'JSON array of allowed IP addresses',
    allowedDomains TEXT NULL COMMENT 'JSON array of allowed domains for webhooks',

    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_email (partnerEmail),
    INDEX idx_active (isActive),
    INDEX idx_tier (accountTier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API Keys table
CREATE TABLE IF NOT EXISTS tblAPIKeys (
    keyID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    partnerID INT UNSIGNED NOT NULL,

    -- Key details
    keyName VARCHAR(100) NOT NULL COMMENT 'Human-readable name',
    keyHash VARCHAR(255) NOT NULL COMMENT 'SHA-256 hash of the key',
    keyPrefix VARCHAR(20) NOT NULL COMMENT 'Visible prefix (sk_live_xxx, sk_test_xxx)',
    environment ENUM('test', 'live') DEFAULT 'test',

    -- Permissions
    permissions JSON NULL COMMENT 'Array of allowed endpoints/scopes',

    -- Security
    ipWhitelist TEXT NULL COMMENT 'JSON array of allowed IPs for this key',

    -- Usage tracking
    lastUsedAt DATETIME NULL,
    usageCount BIGINT UNSIGNED DEFAULT 0,

    -- Expiration
    expiresAt DATETIME NULL,

    -- Status
    isActive BOOLEAN DEFAULT 1,
    revokedAt DATETIME NULL,
    revokedReason VARCHAR(255) NULL,

    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (partnerID) REFERENCES tblPartners(partnerID) ON DELETE CASCADE,
    UNIQUE KEY idx_hash (keyHash),
    INDEX idx_prefix (keyPrefix),
    INDEX idx_partner (partnerID),
    INDEX idx_active (isActive, expiresAt),
    INDEX idx_last_used (lastUsedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API key usage logs
CREATE TABLE IF NOT EXISTS tblAPIKeyUsage (
    usageID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    keyID INT UNSIGNED NOT NULL,

    -- Request details
    endpoint VARCHAR(255) NOT NULL,
    httpMethod VARCHAR(10) NOT NULL,
    statusCode INT UNSIGNED,
    responseTime INT UNSIGNED COMMENT 'Milliseconds',

    -- Request metadata
    ipAddress VARCHAR(45),
    userAgent TEXT,
    requestSize INT UNSIGNED COMMENT 'Bytes',
    responseSize INT UNSIGNED COMMENT 'Bytes',

    -- Errors
    errorMessage TEXT NULL,

    requestedAt DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (keyID) REFERENCES tblAPIKeys(keyID) ON DELETE CASCADE,
    INDEX idx_key (keyID, requestedAt),
    INDEX idx_endpoint (endpoint),
    INDEX idx_timestamp (requestedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleanup old usage logs (keep 90 days)
CREATE EVENT IF NOT EXISTS cleanup_api_key_usage
ON SCHEDULE EVERY 1 DAY
DO
DELETE FROM tblAPIKeyUsage
WHERE requestedAt < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

#### 2.2 APIKeyManager Class

**File:** `private_html/api/APIKeyManager.php`

```php
<?php
/**
 * API Key Manager
 *
 * Manages partner API keys for secure integration
 *
 * @version 2.1.0
 */

class APIKeyManager {
    private $db;

    /**
     * Generate new API key
     *
     * @param int $partnerID Partner ID
     * @param string $keyName Human-readable name
     * @param string $environment 'test' or 'live'
     * @param array $permissions Array of allowed scopes/endpoints
     * @return array ['key' => string, 'keyID' => int, 'prefix' => string]
     */
    public function generateKey(int $partnerID, string $keyName, string $environment = 'test', array $permissions = []): array {
        // Generate secure random key
        $rawKey = bin2hex(random_bytes(32)); // 64 character key

        // Create prefix
        $prefix = ($environment === 'live') ? 'sk_live_' : 'sk_test_';
        $shortKey = substr($rawKey, 0, 8); // First 8 chars visible
        $visibleKey = $prefix . $shortKey;

        // Full key for partner (show once)
        $fullKey = $prefix . $rawKey;

        // Hash for storage
        $keyHash = hash('sha256', $fullKey);

        // Store in database
        $stmt = $this->db->prepare("
            INSERT INTO tblAPIKeys (partnerID, keyName, keyHash, keyPrefix, environment, permissions, isActive)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");

        $permissionsJson = json_encode($permissions);
        $stmt->bind_param('isssss', $partnerID, $keyName, $keyHash, $visibleKey, $environment, $permissionsJson);
        $stmt->execute();

        $keyID = $stmt->insert_id;

        // Log key generation
        ActivityLogger::log([
            'activityType' => 'api_key_generated',
            'activityResult' => 'success',
            'activityDetails' => json_encode([
                'keyID' => $keyID,
                'keyName' => $keyName,
                'environment' => $environment,
                'partnerID' => $partnerID
            ]),
            'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        return [
            'key' => $fullKey,          // Return once, never show again
            'keyID' => $keyID,
            'prefix' => $visibleKey,     // Visible identifier
            'environment' => $environment
        ];
    }

    /**
     * Validate API key
     *
     * @param string $key API key from request
     * @return array|null Partner and key info if valid, null if invalid
     */
    public function validateKey(string $key): ?array {
        $keyHash = hash('sha256', $key);

        $stmt = $this->db->prepare("
            SELECT
                k.keyID,
                k.partnerID,
                k.keyName,
                k.environment,
                k.permissions,
                k.ipWhitelist as keyIPWhitelist,
                k.expiresAt,
                p.partnerName,
                p.accountTier,
                p.isActive as partnerActive,
                p.ipWhitelist as partnerIPWhitelist,
                p.customRateLimit
            FROM tblAPIKeys k
            INNER JOIN tblPartners p ON k.partnerID = p.partnerID
            WHERE k.keyHash = ?
            AND k.isActive = 1
            AND (k.expiresAt IS NULL OR k.expiresAt > NOW())
            AND p.isActive = 1
        ");

        $stmt->bind_param('s', $keyHash);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if (!$result) {
            // Log failed validation attempt
            ActivityLogger::log([
                'activityType' => 'api_key_validation_failed',
                'activityResult' => 'failed',
                'activityDetails' => json_encode(['keyHash' => substr($keyHash, 0, 16) . '...']),
                'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            return null;
        }

        // Check IP whitelist
        if (!$this->checkIPWhitelist($result)) {
            ActivityLogger::log([
                'activityType' => 'api_key_ip_blocked',
                'activityResult' => 'blocked',
                'activityDetails' => json_encode([
                    'keyID' => $result['keyID'],
                    'ipAddress' => $_SERVER['REMOTE_ADDR']
                ]),
                'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            return null;
        }

        // Update last used timestamp and usage count
        $this->updateUsage($result['keyID']);

        return $result;
    }

    /**
     * Revoke API key
     */
    public function revokeKey(int $keyID, string $reason = null): bool {
        $stmt = $this->db->prepare("
            UPDATE tblAPIKeys
            SET isActive = 0,
                revokedAt = NOW(),
                revokedReason = ?
            WHERE keyID = ?
        ");

        $stmt->bind_param('si', $reason, $keyID);
        $success = $stmt->execute();

        if ($success) {
            ActivityLogger::log([
                'activityType' => 'api_key_revoked',
                'activityResult' => 'success',
                'activityDetails' => json_encode([
                    'keyID' => $keyID,
                    'reason' => $reason
                ]),
                'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        }

        return $success;
    }

    /**
     * Check if request IP is whitelisted
     */
    private function checkIPWhitelist(array $keyData): bool {
        $requestIP = $_SERVER['REMOTE_ADDR'] ?? null;

        if (!$requestIP) {
            return false; // No IP = block
        }

        // Check key-specific whitelist first
        if ($keyData['keyIPWhitelist']) {
            $keyIPs = json_decode($keyData['keyIPWhitelist'], true);
            if (is_array($keyIPs) && count($keyIPs) > 0) {
                return in_array($requestIP, $keyIPs);
            }
        }

        // Check partner-level whitelist
        if ($keyData['partnerIPWhitelist']) {
            $partnerIPs = json_decode($keyData['partnerIPWhitelist'], true);
            if (is_array($partnerIPs) && count($partnerIPs) > 0) {
                return in_array($requestIP, $partnerIPs);
            }
        }

        // No whitelist = allow all IPs
        return true;
    }

    /**
     * Update key usage statistics
     */
    private function updateUsage(int $keyID): void {
        $stmt = $this->db->prepare("
            UPDATE tblAPIKeys
            SET lastUsedAt = NOW(),
                usageCount = usageCount + 1
            WHERE keyID = ?
        ");
        $stmt->bind_param('i', $keyID);
        $stmt->execute();
    }

    /**
     * Log API request
     */
    public function logRequest(int $keyID, array $requestData): void {
        $stmt = $this->db->prepare("
            INSERT INTO tblAPIKeyUsage
            (keyID, endpoint, httpMethod, statusCode, responseTime, ipAddress, userAgent, requestSize, responseSize, errorMessage)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'issiississ',
            $keyID,
            $requestData['endpoint'] ?? '',
            $requestData['method'] ?? 'GET',
            $requestData['statusCode'] ?? 200,
            $requestData['responseTime'] ?? 0,
            $requestData['ipAddress'] ?? $_SERVER['REMOTE_ADDR'],
            $requestData['userAgent'] ?? $_SERVER['HTTP_USER_AGENT'],
            $requestData['requestSize'] ?? 0,
            $requestData['responseSize'] ?? 0,
            $requestData['errorMessage'] ?? null
        );

        $stmt->execute();
    }
}
```

---

## 🟡 MEDIUM PRIORITY (Next 2 Weeks)

### 3. Webhook Signature System
### 4. IP Whitelisting Enhancement
### 5. Request Logging Enhancement

(Detailed implementation plans available on request)

---

## 📊 Implementation Timeline

| Week | Focus | Deliverables |
|------|-------|--------------|
| Week 1 | Rate Limiting | Database migration, RateLimiter class, Middleware integration, Testing |
| Week 2 | API Key Management | Database migration, APIKeyManager class, Partner UI, Admin UI, Testing |
| Week 3 | Webhook Signatures | Signature generation, Verification, Documentation |
| Week 4 | IP Whitelisting & Logging | Enhanced IP management, Request logging, Analytics dashboard |

---

## ✅ Success Metrics

**Target:**
- Security score: 95%+
- Rate limit violations: < 1% of requests
- API key compromises: 0
- Successful partner integrations: 100%
- Average API response time: < 300ms (with rate limiting)

---

**Next Steps:** Implement Phase 1 (Rate Limiting) - see implementation guide above.
