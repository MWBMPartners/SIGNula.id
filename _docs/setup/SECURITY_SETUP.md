# Security Features Setup Guide

**Version:** 2.6.0-beta
**Date:** February 19, 2026
**Applies to:** SIGNula v2.6.0-beta and later

---

## 📋 Overview

SIGNula v2.6.0-beta introduces six independently toggleable security layers:

| Feature | Default | External APIs | Files |
|---------|---------|---------------|-------|
| CAPTCHA Verification | Disabled | CloudFlare Turnstile, Google reCAPTCHA v3 | `CaptchaVerifier.php` |
| IP Reputation Checking | Disabled | AbuseIPDB, proxycheck.io | `IPReputationChecker.php` |
| Bot Detection | Enabled | None (local library) | `BotDetector.php` |
| Session Fingerprinting | Enabled | None (local) | `SessionGuard.php` |
| Security Alerting | Enabled | ip-api.com (geo only) | `SecurityAlertManager.php` |
| Local Form Protection | Enabled | None (local) | `FormProtection.php` |

All features are orchestrated by `SecurityMiddleware.php` and configured via `tblSettings` in the database.

**Design Principles:**
- Every feature is independently toggleable — no feature depends on another
- All external API calls use **fail-open** — if an API is unreachable, legitimate users are never blocked
- Circuit breaker pattern prevents repeated calls to failing APIs (5-minute cooldown)
- All sensitive keys are stored encrypted (AES-256-CBC) in the database

---

## 🚀 Prerequisites

### Database Migration

Run migrations `014_security_enhancements.sql` and `015_form_protection_settings.sql` before configuring any features:

```bash
mysql -u your_username -p signula < _database/migrations/014_security_enhancements.sql
mysql -u your_username -p signula < _database/migrations/015_form_protection_settings.sql
```

**Verify migration succeeded:**

```sql
-- Check new tables
SHOW TABLES LIKE 'tblIPReputation%';
SHOW TABLES LIKE 'tblBlocked%';
SHOW TABLES LIKE 'tblSecurity%';
SHOW TABLES LIKE 'tblSession%';
SHOW TABLES LIKE 'tblCircuit%';

-- Check new settings (~30 rows)
SELECT settingKey, settingValue FROM tblSettings
WHERE settingKey LIKE 'captcha.%'
   OR settingKey LIKE 'security.%'
ORDER BY settingKey;
```

### CrawlerDetect Library (Bot Detection)

Bot detection uses the CrawlerDetect library. If not already installed:

1. Download from https://github.com/JayBizzle/Crawler-Detect/releases
2. Place files in `web/_lib/crawlerdetect/`:
   ```
   web/_lib/crawlerdetect/
   ├── crawlerdetect_loader.php     (included — auto-generated loader)
   ├── CrawlerDetect.php            (from release)
   └── Fixtures/
       ├── Crawlers.php
       ├── Exclusions.php
       └── Headers.php
   ```
3. If the library is missing, bot detection falls back to built-in regex patterns automatically.

---

## 🛡️ Feature 1: CAPTCHA Verification

### How It Works

CAPTCHA protects form submissions (login, register, forgot-password, contact) against automated abuse. Two providers are supported:

1. **CloudFlare Turnstile** (recommended) — free, privacy-friendly, no puzzle challenges
2. **Google reCAPTCHA v3** — free up to 10,000 assessments/month, score-based

The system automatically falls back from Turnstile to reCAPTCHA if the primary provider's keys are missing.

### Step 1: Get API Keys

#### CloudFlare Turnstile (Recommended)

1. Log in to the [CloudFlare Dashboard](https://dash.cloudflare.com/)
2. Navigate to **Turnstile** in the left sidebar
3. Click **Add Site**
4. Enter your domain(s): `signula.id`, `signula.com`
5. Select widget type: **Managed** (recommended) or **Invisible**
6. Copy the **Site Key** and **Secret Key**

#### Google reCAPTCHA v3 (Fallback)

1. Go to [Google reCAPTCHA Admin](https://www.google.com/recaptcha/admin)
2. Click **+** to register a new site
3. Select **reCAPTCHA v3** (score-based, no challenge)
4. Add your domain(s): `signula.id`, `signula.com`
5. Copy the **Site Key** and **Secret Key**

### Step 2: Configure Settings

Update these settings in `tblSettings` (via admin UI or direct SQL):

```sql
-- Enable CAPTCHA
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'captcha.enabled';

-- Set provider (turnstile or recaptcha)
UPDATE tblSettings SET settingValue = 'turnstile' WHERE settingKey = 'captcha.provider';

-- CloudFlare Turnstile keys
UPDATE tblSettings SET settingValue = 'YOUR_SITE_KEY' WHERE settingKey = 'captcha.turnstile.site_key';
UPDATE tblSettings SET settingValue = 'YOUR_SECRET_KEY' WHERE settingKey = 'captcha.turnstile.secret_key';
-- Note: secret_key is marked isSensitive — it will be encrypted automatically on save via admin UI.
-- If updating via SQL directly, encrypt the value using SecurityUtils::encryptSensitive() first.

-- OR Google reCAPTCHA v3 keys (used as fallback if Turnstile keys are empty)
UPDATE tblSettings SET settingValue = 'YOUR_RECAPTCHA_SITE_KEY' WHERE settingKey = 'captcha.recaptcha.site_key';
UPDATE tblSettings SET settingValue = 'YOUR_RECAPTCHA_SECRET_KEY' WHERE settingKey = 'captcha.recaptcha.secret_key';

-- Minimum reCAPTCHA v3 score (0.0 = bot, 1.0 = human; default 0.5)
UPDATE tblSettings SET settingValue = '0.5' WHERE settingKey = 'captcha.recaptcha.min_score';

-- Forms that require CAPTCHA (JSON array)
UPDATE tblSettings SET settingValue = '["login","register","forgot-password","contact"]'
WHERE settingKey = 'captcha.required_forms';
```

### Step 3: Verify

1. Navigate to the login page
2. You should see the CAPTCHA widget rendered in the form
3. Submit the form — it should pass with a valid CAPTCHA response
4. Check the browser console and server logs for any errors

### Settings Reference

| Setting Key | Type | Default | Description |
|-------------|------|---------|-------------|
| `captcha.enabled` | boolean | `0` | Master toggle |
| `captcha.provider` | string | `turnstile` | Primary provider: `turnstile`, `recaptcha`, or `none` |
| `captcha.turnstile.site_key` | string | (empty) | Turnstile public key |
| `captcha.turnstile.secret_key` | encrypted | (empty) | Turnstile secret key |
| `captcha.recaptcha.site_key` | string | (empty) | reCAPTCHA v3 public key |
| `captcha.recaptcha.secret_key` | encrypted | (empty) | reCAPTCHA v3 secret key |
| `captcha.recaptcha.min_score` | string | `0.5` | Minimum score to pass (0.0-1.0) |
| `captcha.required_forms` | json | `["login","register","forgot-password","contact"]` | Forms requiring CAPTCHA |

---

## 🔍 Feature 2: IP Reputation Checking

### How It Works

Checks visitor IPs against external threat intelligence databases before allowing access. Uses a multi-step pipeline:

1. **Blocklist check** — fast, database-only (is IP in `tblBlockedIPs`?)
2. **Cache lookup** — check `tblIPReputationCache` for recent results
3. **AbuseIPDB API** — abuse score, country, usage type (free: 4,999 checks/day)
4. **proxycheck.io API** — proxy, VPN, Tor detection (free: 1,000 checks/day)
5. **Cache result** — store for `cache_ttl_hours` to reduce API calls

Private/reserved IPs (127.0.0.1, 10.x, 192.168.x, 172.16-31.x, ::1) are always allowed without API calls.

### Step 1: Get API Keys

#### AbuseIPDB (Recommended)

1. Create a free account at [AbuseIPDB](https://www.abuseipdb.com/)
2. Go to [Account > API](https://www.abuseipdb.com/account/api)
3. Create an API key
4. Free tier: **4,999 checks per day** (resets daily at UTC midnight)

#### proxycheck.io (Optional, adds proxy/VPN/Tor detection)

1. Create a free account at [proxycheck.io](https://proxycheck.io/)
2. Go to [Dashboard](https://proxycheck.io/dashboard) > API Key
3. Free tier: **1,000 queries per day**

### Step 2: Configure Settings

```sql
-- Enable IP reputation checking
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'security.ip_reputation.enabled';

-- AbuseIPDB API key (encrypt if inserting via SQL directly)
UPDATE tblSettings SET settingValue = 'YOUR_ABUSEIPDB_API_KEY'
WHERE settingKey = 'security.ip_reputation.abuseipdb_api_key';

-- proxycheck.io API key (optional)
UPDATE tblSettings SET settingValue = 'YOUR_PROXYCHECK_API_KEY'
WHERE settingKey = 'security.ip_reputation.proxycheck_api_key';

-- Abuse score threshold (0-100; IPs scoring above this are blocked)
-- Lower = stricter. 50 is recommended starting point.
UPDATE tblSettings SET settingValue = '50'
WHERE settingKey = 'security.ip_reputation.abuse_score_threshold';

-- Block types
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'security.ip_reputation.block_proxy';
UPDATE tblSettings SET settingValue = '0' WHERE settingKey = 'security.ip_reputation.block_vpn';
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'security.ip_reputation.block_tor';

-- Cache TTL (hours) — how long to cache results before re-checking
UPDATE tblSettings SET settingValue = '24' WHERE settingKey = 'security.ip_reputation.cache_ttl_hours';

-- IP whitelist — these IPs are never blocked regardless of score
UPDATE tblSettings SET settingValue = '["203.0.113.1","198.51.100.0"]'
WHERE settingKey = 'security.ip_reputation.whitelist';

-- Enable automatic abuse reporting to AbuseIPDB (optional)
UPDATE tblSettings SET settingValue = '0' WHERE settingKey = 'security.ip_reputation.report_enabled';
```

### Step 3: Manual IP Management

Block or unblock IPs directly in the database:

```sql
-- Block an IP for 24 hours (86400 seconds)
INSERT INTO tblBlockedIPs (ip_address, reason, source, blocked_by, expires_at, is_active)
VALUES ('1.2.3.4', 'Brute force attack', 'manual', 1, DATE_ADD(NOW(), INTERVAL 24 HOUR), TRUE);

-- Block an IP permanently
INSERT INTO tblBlockedIPs (ip_address, reason, source, blocked_by, expires_at, is_active)
VALUES ('5.6.7.8', 'Known attacker', 'manual', 1, NULL, TRUE);

-- Unblock an IP
UPDATE tblBlockedIPs SET is_active = FALSE, unblocked_by = 1, unblocked_at = NOW()
WHERE ip_address = '1.2.3.4';

-- View all active blocks
SELECT * FROM tblBlockedIPs WHERE is_active = TRUE ORDER BY created_at DESC;
```

Or programmatically:

```php
// Block an IP for 24 hours
IPReputationChecker::blockIP('1.2.3.4', 'Suspicious activity', 86400, $adminUserId);

// Unblock
IPReputationChecker::unblockIP('1.2.3.4', $adminUserId);

// Quick check if an IP is currently blocked
$isBlocked = IPReputationChecker::isBlocked('1.2.3.4');
```

### Settings Reference

| Setting Key | Type | Default | Description |
|-------------|------|---------|-------------|
| `security.ip_reputation.enabled` | boolean | `0` | Master toggle |
| `security.ip_reputation.abuseipdb_api_key` | encrypted | (empty) | AbuseIPDB API key |
| `security.ip_reputation.proxycheck_api_key` | encrypted | (empty) | proxycheck.io API key |
| `security.ip_reputation.abuse_score_threshold` | integer | `50` | Score threshold (0-100) |
| `security.ip_reputation.block_proxy` | boolean | `1` | Block open proxies |
| `security.ip_reputation.block_vpn` | boolean | `0` | Block VPN endpoints |
| `security.ip_reputation.block_tor` | boolean | `1` | Block Tor exit nodes |
| `security.ip_reputation.cache_ttl_hours` | integer | `24` | Cache lifetime (hours) |
| `security.ip_reputation.report_enabled` | boolean | `0` | Report abuse to AbuseIPDB |
| `security.ip_reputation.whitelist` | json | `[]` | Always-allow IP list |

---

## 🤖 Feature 3: Bot Detection

### How It Works

Detects and optionally blocks automated bot traffic using a tiered approach:

1. **CrawlerDetect library** (preferred) — extensive database of known bot user agents
2. **`get_browser()` / Browscap** — PHP's built-in browser detection (if `browscap` configured in php.ini)
3. **Regex pattern matching** — built-in patterns for common bots (fallback)

Good bots (Googlebot, Bingbot, DuckDuckBot, Baiduspider, YandexBot, Slurp) are always allowed by default. Optional DNS verification can confirm a bot truly belongs to the claimed search engine.

### Configuration

```sql
-- Enable/disable bot detection
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'security.bot_detection.enabled';

-- Block detected bad bots
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'security.bot_detection.block_bad_bots';

-- Allow known good search engine bots
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'security.bot_detection.allow_good_bots';

-- Verify good bot identity via reverse DNS (adds latency, catches fake Googlebots)
UPDATE tblSettings SET settingValue = '0' WHERE settingKey = 'security.bot_detection.dns_verify';

-- Block requests with empty/missing User-Agent
-- WARNING: May block some legitimate automated tools
UPDATE tblSettings SET settingValue = '0' WHERE settingKey = 'security.bot_detection.block_empty_ua';
```

### Settings Reference

| Setting Key | Type | Default | Description |
|-------------|------|---------|-------------|
| `security.bot_detection.enabled` | boolean | `1` | Master toggle |
| `security.bot_detection.block_bad_bots` | boolean | `1` | Block detected bad bots |
| `security.bot_detection.allow_good_bots` | boolean | `1` | Allow search engine crawlers |
| `security.bot_detection.dns_verify` | boolean | `0` | DNS-verify good bot claims |
| `security.bot_detection.block_empty_ua` | boolean | `0` | Block empty User-Agent |

### No External Dependencies Required

Bot detection is entirely local:
- CrawlerDetect library files are included in `web/_lib/crawlerdetect/`
- Falls back to regex patterns automatically if library is missing
- Zero API calls — no rate limits, no external dependencies

---

## 🔑 Feature 4: Session Fingerprinting

### How It Works

Creates a cryptographic fingerprint of each session based on:
- Client IP address (configurable: exact match, subnet match, or ignored)
- User-Agent header
- Accept-Language header
- Accept-Encoding header
- Server-side salt (auto-generated on first use)

The fingerprint is stored in `$_SESSION` and validated on every request. If the fingerprint changes (potential session hijacking), the session is invalidated and the user must re-authenticate.

### Configuration

```sql
-- Enable/disable session fingerprinting
UPDATE tblSettings SET settingValue = '1'
WHERE settingKey = 'security.session_fingerprinting.enabled';

-- IP matching mode:
--   exact  = full IP must match (strictest — may cause issues on mobile networks)
--   subnet = match /24 network only (recommended — allows IP changes within subnet)
--   none   = ignore IP entirely (least strict, still checks UA + headers)
UPDATE tblSettings SET settingValue = 'subnet'
WHERE settingKey = 'security.session_fingerprinting.ip_match';

-- Action on fingerprint mismatch:
--   invalidate = destroy session, force re-login (recommended)
--   warn       = log alert but allow continued access
--   log        = silently log, no user-facing action
UPDATE tblSettings SET settingValue = 'invalidate'
WHERE settingKey = 'security.session_fingerprinting.on_mismatch';

-- Server-side salt (auto-generated if empty — usually leave this blank)
-- Changing this value invalidates ALL existing sessions
-- UPDATE tblSettings SET settingValue = '' WHERE settingKey = 'security.session_fingerprinting.salt';
```

### Settings Reference

| Setting Key | Type | Default | Description |
|-------------|------|---------|-------------|
| `security.session_fingerprinting.enabled` | boolean | `1` | Master toggle |
| `security.session_fingerprinting.ip_match` | string | `subnet` | IP match mode: `exact`, `subnet`, `none` |
| `security.session_fingerprinting.salt` | encrypted | (auto) | Server-side hash salt |
| `security.session_fingerprinting.on_mismatch` | string | `invalidate` | Mismatch action: `invalidate`, `warn`, `log` |

### Important Notes

- **Mobile users:** Use `subnet` or `none` IP match mode — mobile networks frequently change IP addresses
- **CDN/proxy users:** If behind CloudFlare or similar, ensure `$_SERVER['REMOTE_ADDR']` reflects the true client IP (CloudFlare sets `HTTP_CF_CONNECTING_IP`)
- **Salt rotation:** Changing the salt invalidates all active sessions. Plan for off-peak rotation.

---

## 🚨 Feature 5: Security Alerting

### How It Works

Monitors for suspicious patterns and creates alerts in `tblSecurityAlerts`:

| Alert Type | Detection Method |
|------------|-----------------|
| Brute Force | N failed logins from same IP |
| Password Spray | N distinct usernames from same IP in time window |
| Impossible Travel | Two logins from geographically distant IPs in short time |
| Session Hijack | Fingerprint mismatch detected |
| IP Blocked | IP blocked by reputation check |
| Bot Detected | Malicious bot blocked |
| Rate Limit Exceeded | Too many requests from same source |

Alerts can trigger email notifications to configured admin addresses.

### Configuration

```sql
-- Enable/disable alerting
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'security.alerts.enabled';

-- Admin email addresses for alert notifications (JSON array)
UPDATE tblSettings SET settingValue = '["admin@signula.com","security@signula.com"]'
WHERE settingKey = 'security.alerts.admin_emails';

-- Minimum severity for email notification: low, medium, high, critical
UPDATE tblSettings SET settingValue = 'high' WHERE settingKey = 'security.alerts.notify_threshold';

-- Brute force: consecutive failed logins before alerting
UPDATE tblSettings SET settingValue = '5'
WHERE settingKey = 'security.alerts.brute_force_threshold';

-- Password spray: distinct usernames from same IP before alerting
UPDATE tblSettings SET settingValue = '10'
WHERE settingKey = 'security.alerts.password_spray_threshold';

-- Password spray: time window (minutes) for counting attempts
UPDATE tblSettings SET settingValue = '30'
WHERE settingKey = 'security.alerts.password_spray_window_minutes';

-- Impossible travel: distance threshold (km)
UPDATE tblSettings SET settingValue = '500'
WHERE settingKey = 'security.alerts.impossible_travel_km';

-- Impossible travel: time window (minutes)
UPDATE tblSettings SET settingValue = '60'
WHERE settingKey = 'security.alerts.impossible_travel_minutes';
```

### Managing Alerts

```sql
-- View recent unacknowledged alerts
SELECT alertID, alertType, severity, description, ipAddress, created_at
FROM tblSecurityAlerts
WHERE status = 'new'
ORDER BY created_at DESC
LIMIT 50;

-- View critical alerts
SELECT * FROM tblSecurityAlerts
WHERE severity = 'critical' AND status IN ('new', 'acknowledged')
ORDER BY created_at DESC;
```

Or programmatically:

```php
// Get recent alerts (last 50)
$alerts = SecurityAlertManager::getRecentAlerts(50);

// Acknowledge an alert
SecurityAlertManager::acknowledge($alertId, $adminUserId, 'Investigating');

// Resolve an alert
SecurityAlertManager::resolve($alertId, $adminUserId, 'False positive — known admin IP');
```

### Settings Reference

| Setting Key | Type | Default | Description |
|-------------|------|---------|-------------|
| `security.alerts.enabled` | boolean | `1` | Master toggle |
| `security.alerts.admin_emails` | json | `[]` | Admin notification emails |
| `security.alerts.notify_threshold` | string | `high` | Min severity for email |
| `security.alerts.brute_force_threshold` | integer | `5` | Failed logins before alert |
| `security.alerts.password_spray_threshold` | integer | `10` | Distinct usernames before alert |
| `security.alerts.password_spray_window_minutes` | integer | `30` | Spray detection window |
| `security.alerts.impossible_travel_km` | integer | `500` | Distance threshold (km) |
| `security.alerts.impossible_travel_minutes` | integer | `60` | Time window (minutes) |

---

## 🛡️ Feature 6: Local Form Protection

### How It Works

Local Form Protection provides three always-on bot/abuse defences that work **independently of external CAPTCHA APIs**. Even if CAPTCHA is disabled or its API is unreachable, these protections remain active:

1. **Honeypot Field** — A CSS-hidden form field (`position:absolute; left:-9999px`) with `aria-hidden="true"` and `tabindex="-1"`. Human users never see it; bots that auto-fill all fields will populate it, causing rejection.
2. **HMAC-Signed Timing Validation** — A hidden timestamp + HMAC signature field. Forms submitted faster than the configurable threshold (default: 3 seconds) are rejected. The HMAC prevents tampering with the timestamp.
3. **JavaScript Challenge** — A hidden field populated by JavaScript on page load. Bots without JS engines fail this check. Non-JS browsers are not penalised (empty field = pass).

### Configuration

```sql
-- Enable/disable local form protection
UPDATE tblSettings SET settingValue = '1'
WHERE settingKey = 'security.form_protection.enabled';

-- Minimum form submission time in seconds (rejects faster submissions)
-- Increase for registration forms, decrease for simple login forms
UPDATE tblSettings SET settingValue = '3'
WHERE settingKey = 'security.form_protection.min_submit_time';
```

### Integration

FormProtection fields are automatically rendered on all protected forms via:

```php
<?php echo FormProtection::renderAllFields(); ?>
```

This outputs all three protection fields (honeypot, timing, JS challenge) as hidden HTML. Validation happens automatically in `SecurityMiddleware::handleFormSubmission()` (Step 2).

### Settings Reference

| Setting Key | Type | Default | Description |
|-------------|------|---------|-------------|
| `security.form_protection.enabled` | boolean | `1` | Master toggle |
| `security.form_protection.min_submit_time` | integer | `3` | Minimum seconds before form can be submitted |

### No External Dependencies Required

Local form protection is entirely self-contained:
- No API keys needed
- No external service calls
- Works alongside CAPTCHA as defence-in-depth
- Gracefully handles non-JavaScript browsers

---

## 🔄 SecurityMiddleware Pipeline

All features are orchestrated by `SecurityMiddleware`, which runs automatically via `config.php`.

### Page Request Pipeline (`SecurityMiddleware::handle()`)

Runs on every page load (called from `config.php` bootstrap):

```
Request → IP Blocklist Check → Bot Detection → IP Reputation Check → Continue
              │                      │                  │
              ├─ Blocked? → 403     ├─ Bad bot? → 403  ├─ Bad score? → 403
              └─ Not blocked → next └─ OK → next       └─ OK → next
```

Each step is independently skippable if its feature is disabled.

### Form Submission Pipeline (`SecurityMiddleware::handleFormSubmission()`)

Runs before processing POST data on protected forms:

```
POST → Rate Limit Check → Form Protection → CAPTCHA Verification → Process Form
            │                    │                    │
            ├─ Over limit? → err ├─ Bot detected → err ├─ Failed? → error
            └─ OK → next        └─ OK → next         └─ OK → process
```

**Step 1:** Rate limiting (token bucket, progressive blocking)
**Step 2:** Local Form Protection (honeypot + timing + JS challenge) — always runs if enabled
**Step 3:** CAPTCHA verification (Turnstile/reCAPTCHA) — runs if CAPTCHA enabled and keys configured

Returns `['allowed' => bool, 'error' => ?string, 'captcha_required' => bool]`.

---

## 📊 Database Tables

### New Tables (Migration 014)

| Table | Purpose | Auto-cleanup |
|-------|---------|-------------|
| `tblIPReputationCache` | Cached API results | Hourly (expired rows) |
| `tblBlockedIPs` | Manual + automatic IP blocks | Every 15 min (expired blocks) |
| `tblSecurityAlerts` | Alert tracking + resolution | Manual |
| `tblSessionFingerprints` | Fingerprint audit trail | Every 6 hours (90+ days old) |
| `tblCircuitBreaker` | External API failure tracking | Every 5 min (reset) |

### MySQL Scheduled Events

The migration creates 4 scheduled events for automatic maintenance:

| Event | Frequency | Action |
|-------|-----------|--------|
| `evt_cleanup_ip_reputation_cache` | Every 1 hour | Delete expired cache entries |
| `evt_deactivate_expired_blocks` | Every 15 minutes | Deactivate expired IP blocks |
| `evt_cleanup_old_fingerprints` | Every 6 hours | Delete fingerprints older than 90 days |
| `evt_reset_circuit_breakers` | Every 5 minutes | Reset circuit breakers after cooldown |

**Important:** MySQL Event Scheduler must be enabled:

```sql
-- Check if Event Scheduler is running
SHOW VARIABLES LIKE 'event_scheduler';

-- Enable it (requires SUPER privilege or ask your host)
SET GLOBAL event_scheduler = ON;
```

If your hosting provider does not support MySQL events, you can run cleanup manually via cron or skip it — the system will work but cache tables may grow.

---

## 🔒 Encrypting Sensitive Settings

Settings marked `isSensitive = TRUE` (API keys, secrets, salt) must be encrypted before storage. The admin UI handles this automatically, but for direct SQL inserts:

```php
// In a PHP script with config.php loaded:
$encrypted = SecurityUtils::encryptSensitive('your-api-key-here');

// Then use the encrypted value in SQL:
// UPDATE tblSettings SET settingValue = '$encrypted' WHERE settingKey = '...';
```

**Never store plaintext API keys in the database.** The `getSetting()` function automatically decrypts sensitive values when reading.

---

## ✅ Quick Start (Recommended Defaults)

For a quick setup with reasonable security defaults:

```sql
-- 1. Enable CAPTCHA (requires Turnstile keys — get from Cloudflare dashboard)
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'captcha.enabled';
UPDATE tblSettings SET settingValue = 'YOUR_TURNSTILE_SITE_KEY' WHERE settingKey = 'captcha.turnstile.site_key';
UPDATE tblSettings SET settingValue = 'YOUR_TURNSTILE_SECRET_KEY' WHERE settingKey = 'captcha.turnstile.secret_key';

-- 2. Enable IP reputation (requires AbuseIPDB key — get from abuseipdb.com)
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'security.ip_reputation.enabled';
UPDATE tblSettings SET settingValue = 'YOUR_ABUSEIPDB_KEY' WHERE settingKey = 'security.ip_reputation.abuseipdb_api_key';

-- 3. Bot detection, session fingerprinting, alerting, and local form protection are enabled by default
-- Just configure admin emails for alert notifications:
UPDATE tblSettings SET settingValue = '["admin@yourdomain.com"]' WHERE settingKey = 'security.alerts.admin_emails';

-- 4. Local form protection is enabled by default with 3-second minimum submit time
-- Adjust minimum submit time if needed (seconds):
-- UPDATE tblSettings SET settingValue = '5' WHERE settingKey = 'security.form_protection.min_submit_time';
```

That's it — three API keys and you have comprehensive security coverage. Local form protection (honeypot, timing, JS challenge) is enabled by default with no API keys needed.

---

## 🐛 Troubleshooting

### CAPTCHA widget not appearing

1. Check `captcha.enabled` is `1`
2. Verify provider keys are set (check via admin UI or `SELECT settingValue FROM tblSettings WHERE settingKey LIKE 'captcha.turnstile%'`)
3. Check browser console for JavaScript errors
4. Verify CSP header includes `challenges.cloudflare.com` (configured in `config.php`)

### IP reputation always allowing

1. Check `security.ip_reputation.enabled` is `1`
2. Verify at least one API key is set
3. Private IPs (127.0.0.1, 10.x, 192.168.x) are always allowed — test with a public IP
4. Check `tblCircuitBreaker` — if an API's circuit is open, it's being skipped

### Bot detection not blocking

1. Check `security.bot_detection.enabled` is `1`
2. Check `security.bot_detection.block_bad_bots` is `1`
3. Verify CrawlerDetect library is installed in `web/_lib/crawlerdetect/`
4. Check PHP error log for library loading errors

### Session invalidation on every request

1. Check `security.session_fingerprinting.ip_match` — try `subnet` or `none` for mobile/dynamic IPs
2. If behind a CDN/proxy, ensure true client IP is passed through
3. Check `security.session_fingerprinting.on_mismatch` — set to `log` temporarily to investigate

### Alerts not sending emails

1. Check `security.alerts.enabled` is `1`
2. Verify `security.alerts.admin_emails` contains valid JSON array with emails
3. Check `security.alerts.notify_threshold` — alerts below this severity don't trigger email
4. Verify PHP `mail()` function works on your server

### Circuit breaker tripped

If an external API is consistently failing, the circuit breaker prevents hammering it:

```sql
-- Check circuit breaker status
SELECT * FROM tblCircuitBreaker;

-- Reset a specific circuit manually
UPDATE tblCircuitBreaker SET failure_count = 0, last_failure = NULL, circuit_open = FALSE
WHERE service_name = 'abuseipdb';
```

---

## 📁 File Reference

| File | Location | Purpose |
|------|----------|---------|
| `CaptchaVerifier.php` | `web/private_html/security/` | CAPTCHA widget rendering + server verification |
| `IPReputationChecker.php` | `web/private_html/security/` | IP abuse/proxy/VPN checking |
| `BotDetector.php` | `web/private_html/security/` | Bot/crawler detection |
| `SessionGuard.php` | `web/private_html/security/` | Session fingerprinting |
| `SecurityAlertManager.php` | `web/private_html/security/` | Alert creation, tracking, notification |
| `SecurityMiddleware.php` | `web/private_html/security/` | Orchestration pipeline |
| `FormProtection.php` | `web/private_html/security/` | Honeypot, timing, JS challenge |
| `014_security_enhancements.sql` | `_database/migrations/` | Database tables + settings |
| `015_form_protection_settings.sql` | `_database/migrations/` | Form protection settings |
| `crawlerdetect_loader.php` | `web/_lib/crawlerdetect/` | Library loader |

---

## 📚 External Documentation

- [CloudFlare Turnstile Docs](https://developers.cloudflare.com/turnstile/)
- [Google reCAPTCHA v3 Docs](https://developers.google.com/recaptcha/docs/v3)
- [AbuseIPDB API Docs](https://docs.abuseipdb.com/)
- [proxycheck.io API Docs](https://proxycheck.io/api/)
- [CrawlerDetect GitHub](https://github.com/JayBizzle/Crawler-Detect)
- [ip-api.com Docs](https://ip-api.com/docs) (used for impossible travel geo lookup)
