# Security Enhancements Deployment Guide

**Version:** 2.2.0-beta
**Date:** February 4, 2026
**Status:** Ready for deployment

---

## 📋 Overview

This guide covers the deployment of Phase A security enhancements:
- ✅ Rate Limiting System
- ✅ API Key Management System

### What's Included

1. **Backend Classes**
   - `RateLimiter.php` - Token bucket rate limiting with progressive blocking
   - `APIKeyManager.php` - Secure API key generation and management

2. **Middleware**
   - `RateLimitMiddleware.php` - Automatic rate limiting for all API requests
   - `APIKeyMiddleware.php` - API key authentication and usage tracking

3. **Database Migrations**
   - `007_rate_limiting.sql` - Rate limit tables and configuration
   - `008_partner_api_keys.sql` - Partner and API key tables

4. **Integration**
   - API router updated with middleware integration
   - Automatic usage logging and analytics

---

## 🚀 Deployment Steps

### Step 1: Backup Database

**CRITICAL:** Always backup your database before running migrations.

```bash
# Create backup
mysqldump -u your_username -p signula > backup_before_security_$(date +%Y%m%d_%H%M%S).sql
```

### Step 2: Deploy Database Migrations

Run migrations **in order**:

#### Migration 007: Rate Limiting

```bash
mysql -u your_username -p signula < _database/migrations/007_rate_limiting.sql
```

**Expected output:**
```
✅ Migration 007_rate_limiting.sql completed successfully
```

**Verify:**
```sql
USE signula;

-- Check tables created
SHOW TABLES LIKE 'tblRateLimit%';
-- Should show: tblRateLimits, tblRateLimitConfig

-- Check default configurations
SELECT COUNT(*) FROM tblRateLimitConfig;
-- Should show: 13 rows (IP, User, and API Key tiers)

-- Check settings
SELECT * FROM tblSettings WHERE settingKey LIKE 'rate_limiting.%';
-- Should show: 5 settings
```

#### Migration 008: Partner API Keys

```bash
mysql -u your_username -p signula < _database/migrations/008_partner_api_keys.sql
```

**Expected output:**
```
✅ Migration 008_partner_api_keys.sql completed successfully
```

**Verify:**
```sql
USE signula;

-- Check tables created
SHOW TABLES LIKE 'tblAPI%';
-- Should show: tblAPIKeys, tblAPIKeyUsage, tblAPIKeyAudit

SHOW TABLES LIKE 'tblPartners';
-- Should show: tblPartners

-- Check test partner created
SELECT * FROM tblPartners WHERE partnerEmail = 'dev@example.com';
-- Should show: 1 test partner

-- Check settings
SELECT * FROM tblSettings WHERE settingKey LIKE 'api_keys.%';
-- Should show: 11 settings
```

### Step 3: Verify File Deployment

Ensure all files are in correct locations:

```
✅ private_html/security/RateLimiter.php
✅ private_html/api/RateLimitMiddleware.php
✅ private_html/api/APIKeyManager.php
✅ private_html/api/APIKeyMiddleware.php
✅ public_html/api/v1/index.php (updated with middleware)
```

### Step 4: Test Rate Limiting

#### Test 1: Verify Rate Limiting is Active

```bash
# Make multiple rapid requests
for i in {1..25}; do
  curl -i http://localhost/api/v1/health
  sleep 0.1
done
```

**Expected:** After ~20 requests, you should receive HTTP 429:
```json
{
  "success": false,
  "error": {
    "code": "RATE_LIMIT_EXCEEDED",
    "message": "Too many requests. Please try again later.",
    "retry_after": 60
  }
}
```

#### Test 2: Check Rate Limit Headers

```bash
curl -i http://localhost/api/v1/health
```

**Expected headers:**
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 99
X-RateLimit-Reset: 1738761600
```

#### Test 3: Verify Database Logging

```sql
-- Check rate limit tracking
SELECT * FROM tblRateLimits ORDER BY createdAt DESC LIMIT 10;

-- Should show your IP address and request counts
```

### Step 5: Test API Key System

#### Test 1: Generate Test API Key

**Option A: Direct Database Insert (Development Only)**

```sql
USE signula;

-- Get test partner ID
SET @partnerID = (SELECT partnerID FROM tblPartners WHERE partnerEmail = 'dev@example.com');

-- Generate test key manually
SET @testKey = 'sk_test_abc123def456ghi789jkl012';
SET @keyHash = SHA2(@testKey, 256);

INSERT INTO tblAPIKeys (
    partnerID, keyName, keyHash, keyPrefix, environment, isActive
) VALUES (
    @partnerID, 'Development Test Key', @keyHash, 'sk_test_abc123def45', 'test', 1
);

-- Show the test key
SELECT @testKey as 'Test API Key';
```

**Option B: Use APIKeyManager (Recommended)**

Create a PHP script `_scripts/generate_test_key.php`:

```php
<?php
define('SIGNULA_INIT', true);
require_once __DIR__ . '/../_config/config.php';
require_once PRIVATE_DIR . '/api/APIKeyManager.php';

$apiKeyManager = new APIKeyManager($db);

// Generate test key for test partner
$result = $apiKeyManager->generateKey(
    1,  // Test partner ID
    'Development Test Key',
    'test',
    [
        'permissions' => ['*'], // Full access
        'expiresAt' => null     // Never expires
    ]
);

if ($result) {
    echo "✅ Test API Key Generated!\n\n";
    echo "Key: " . $result['key'] . "\n";
    echo "Prefix: " . $result['keyData']['keyPrefix'] . "\n";
    echo "\nIMPORTANT: Save this key! It won't be shown again.\n";
} else {
    echo "❌ Failed to generate key\n";
}
```

Run:
```bash
php _scripts/generate_test_key.php
```

#### Test 2: Authenticate with API Key

```bash
# Using X-API-Key header (preferred)
curl -H "X-API-Key: sk_test_abc123def456ghi789jkl012" \
     http://localhost/api/v1/health

# Using Bearer token format
curl -H "Authorization: Bearer sk_test_abc123def456ghi789jkl012" \
     http://localhost/api/v1/health

# Using query parameter (less secure)
curl http://localhost/api/v1/health?api_key=sk_test_abc123def456ghi789jkl012
```

**Expected:** Normal response with successful authentication

#### Test 3: Verify Usage Logging

```sql
-- Check API key usage logs
SELECT
    k.keyName,
    u.endpoint,
    u.httpMethod,
    u.statusCode,
    u.responseTime,
    u.requestedAt
FROM tblAPIKeyUsage u
JOIN tblAPIKeys k ON u.keyID = k.keyID
ORDER BY u.requestedAt DESC
LIMIT 10;

-- Should show your API requests
```

#### Test 4: Test Invalid API Key

```bash
curl -i -H "X-API-Key: sk_test_invalid_key_12345" \
     http://localhost/api/v1/health
```

**Expected:** HTTP 401 Unauthorized
```json
{
  "success": false,
  "error": {
    "code": "UNAUTHORIZED",
    "message": "Invalid or expired API key"
  }
}
```

---

## 🔧 Configuration

### Rate Limiting Settings

Adjust via `tblSettings`:

```sql
-- Enable/disable rate limiting
UPDATE tblSettings
SET settingValue = '1'
WHERE settingKey = 'rate_limiting.enabled';

-- Adjust cleanup interval
UPDATE tblSettings
SET settingValue = '24'
WHERE settingKey = 'rate_limiting.cleanup_interval_hours';
```

### API Key Settings

```sql
-- Enable/disable API key authentication
UPDATE tblSettings
SET settingValue = '1'
WHERE settingKey = 'api_keys.enabled';

-- Adjust max keys per partner
UPDATE tblSettings
SET settingValue = '10'
WHERE settingKey = 'api_keys.max_keys_per_partner';

-- Enable/disable usage logging
UPDATE tblSettings
SET settingValue = '1'
WHERE settingKey = 'api_keys.log_usage';
```

### Rate Limit Tiers

Adjust tier limits in `tblRateLimitConfig`:

```sql
-- Update premium tier limits
UPDATE tblRateLimitConfig
SET requestsPerHour = 10000,
    requestsPerMinute = 1000
WHERE tier = 'premium' AND identifierType = 'user';
```

---

## 📊 Monitoring

### Rate Limit Analytics

```sql
-- Most rate-limited identifiers
SELECT
    identifier,
    identifierType,
    COUNT(*) as violations
FROM tblRateLimits
WHERE isBlocked = 1
GROUP BY identifier, identifierType
ORDER BY violations DESC
LIMIT 10;

-- Current blocks
SELECT
    identifier,
    identifierType,
    endpoint,
    blockedUntil,
    blockReason
FROM tblRateLimits
WHERE isBlocked = 1 AND blockedUntil > NOW();
```

### API Key Analytics

```sql
-- Most active API keys
SELECT
    k.keyPrefix,
    k.keyName,
    p.partnerName,
    COUNT(u.usageID) as requests,
    AVG(u.responseTime) as avg_response_time_ms
FROM tblAPIKeys k
JOIN tblPartners p ON k.partnerID = p.partnerID
LEFT JOIN tblAPIKeyUsage u ON k.keyID = u.keyID
WHERE u.requestedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY k.keyID
ORDER BY requests DESC
LIMIT 10;

-- Error rates by key
SELECT
    k.keyPrefix,
    k.keyName,
    COUNT(*) as total_requests,
    SUM(CASE WHEN u.statusCode >= 400 THEN 1 ELSE 0 END) as errors,
    ROUND(SUM(CASE WHEN u.statusCode >= 400 THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as error_rate_percent
FROM tblAPIKeys k
JOIN tblAPIKeyUsage u ON k.keyID = u.keyID
WHERE u.requestedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY k.keyID
HAVING total_requests > 100
ORDER BY error_rate_percent DESC;
```

---

## 🚨 Troubleshooting

### Rate Limiting Not Working

1. **Check if enabled:**
   ```sql
   SELECT settingValue FROM tblSettings WHERE settingKey = 'rate_limiting.enabled';
   ```

2. **Check for errors:**
   ```sql
   SELECT * FROM tblErrorLog
   WHERE errorMessage LIKE '%RateLimit%'
   ORDER BY errorDate DESC LIMIT 10;
   ```

3. **Verify middleware is loaded:**
   ```bash
   grep -n "RateLimitMiddleware" public_html/api/v1/index.php
   ```

### API Key Authentication Failing

1. **Verify key exists:**
   ```sql
   SELECT keyID, keyPrefix, isActive, expiresAt
   FROM tblAPIKeys
   WHERE keyHash = SHA2('your_api_key_here', 256);
   ```

2. **Check partner is active:**
   ```sql
   SELECT p.isActive, p.partnerName, k.keyPrefix
   FROM tblAPIKeys k
   JOIN tblPartners p ON k.partnerID = p.partnerID
   WHERE k.keyHash = SHA2('your_api_key_here', 256);
   ```

3. **Check IP whitelist:**
   ```sql
   SELECT keyPrefix, ipWhitelist, requiresIPWhitelist
   FROM tblAPIKeys
   WHERE keyHash = SHA2('your_api_key_here', 256);
   ```

### Unblock an Identifier

```sql
-- Unblock a specific IP
UPDATE tblRateLimits
SET isBlocked = 0, blockedUntil = NULL
WHERE identifier = 'xxx.xxx.xxx.xxx' AND identifierType = 'ip';

-- Or use RateLimiter class method
```

---

## 🔐 Security Checklist

Before going to production:

- [ ] Remove test partner from `tblPartners` (dev@example.com)
- [ ] Regenerate all test API keys with live keys
- [ ] Configure rate limits appropriate for your traffic
- [ ] Set up monitoring alerts for excessive rate limiting
- [ ] Document API key distribution process for partners
- [ ] Set up automatic backup of `tblAPIKeys` and `tblPartners`
- [ ] Configure IP whitelisting for sensitive endpoints
- [ ] Review and adjust usage log retention (default: 90 days)
- [ ] Test rate limiting from multiple IPs and user accounts
- [ ] Verify progressive blocking is working correctly

---

## 📈 Next Steps

After successful deployment:

1. **Create Partner UI** (Phase B continuation)
   - Partner registration page
   - API key management dashboard
   - Usage analytics for partners

2. **Create Admin UI** (Phase B continuation)
   - Partner management dashboard
   - Rate limit monitoring
   - API key revocation tools

3. **Implement Phase C: Webhook Signatures**
   - HMAC-SHA256 webhook signatures
   - Webhook testing tools

4. **Implement Phase D: IP Whitelisting Enhancement**
   - CIDR range support (already in code)
   - Geo-blocking capabilities

5. **Implement Phase E: Request Logging Enhancement**
   - Detailed request/response logging
   - Audit trail for sensitive operations

---

## 📚 Documentation

- [Security Enhancements Roadmap](_docs/SECURITY_ENHANCEMENTS_ROADMAP.md)
- [API Documentation](public_html/docs/api/API_DOCUMENTATION.md)
- [Database Schema](_database/README.md)

---

## ✅ Deployment Verification

Run this verification script after deployment:

```sql
-- Verification Script
SELECT '=== RATE LIMITING TABLES ===' as Check_Type;
SELECT COUNT(*) as Tables_Created FROM information_schema.tables
WHERE table_schema = 'signula'
AND table_name IN ('tblRateLimits', 'tblRateLimitConfig');
-- Should show: 2

SELECT '=== API KEY TABLES ===' as Check_Type;
SELECT COUNT(*) as Tables_Created FROM information_schema.tables
WHERE table_schema = 'signula'
AND table_name IN ('tblPartners', 'tblAPIKeys', 'tblAPIKeyUsage', 'tblAPIKeyAudit');
-- Should show: 4

SELECT '=== RATE LIMIT CONFIGS ===' as Check_Type;
SELECT COUNT(*) as Config_Count FROM tblRateLimitConfig;
-- Should show: 13

SELECT '=== SETTINGS ===' as Check_Type;
SELECT COUNT(*) as Settings_Count FROM tblSettings
WHERE settingKey LIKE 'rate_limiting.%' OR settingKey LIKE 'api_keys.%';
-- Should show: 16 (5 rate limiting + 11 API keys)

SELECT '=== SCHEDULED EVENTS ===' as Check_Type;
SELECT COUNT(*) as Events_Count FROM information_schema.events
WHERE event_schema = 'signula'
AND event_name IN ('cleanup_rate_limits', 'expire_api_keys', 'cleanup_api_key_usage');
-- Should show: 3

SELECT '✅ All security enhancements deployed successfully!' as Status;
```

**Expected result:** All checks pass with expected counts.

---

## 🆘 Support

If you encounter issues during deployment:

1. Check error logs: `tblErrorLog`
2. Review migration output for SQL errors
3. Verify file permissions (private_html should NOT be web-accessible)
4. Check PHP error logs
5. Consult [SECURITY_ENHANCEMENTS_ROADMAP.md](_docs/SECURITY_ENHANCEMENTS_ROADMAP.md)

---

**Deployment Complete!** 🎉

Your SIGNula API now has enterprise-grade rate limiting and API key management.

---

**Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
