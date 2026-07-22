# Security Features Setup Guide

**Version:** 2.6.0-beta
**Date:** February 24, 2026
**Applies to:** SIGNula v2.6.0-beta and later

---

## 📋 Overview

SIGNula v2.6.0-beta introduces seven independently toggleable security layers:

| Feature | Default | External APIs | Files |
|---------|---------|---------------|-------|
| CAPTCHA Verification | Disabled | CloudFlare Turnstile, Google reCAPTCHA v3 | `CaptchaVerifier.php` |
| IP Reputation Checking | Disabled | AbuseIPDB, proxycheck.io | `IPReputationChecker.php` |
| Bot Detection | Enabled | None (local library) | `BotDetector.php` |
| Session Fingerprinting | Enabled | None (local) | `SessionGuard.php` |
| Security Alerting | Enabled | ip-api.com (geo only) | `SecurityAlertManager.php` |
| Local Form Protection | Enabled | None (local) | `FormProtection.php` |
| Mass Credential Reset | Admin-triggered | None (local) | `CredentialResetService.php` |

All features are orchestrated by `SecurityMiddleware.php` and configured via `tblSettings` in the database.

**Design Principles:**
- Every feature is independently toggleable — no feature depends on another
- All external API calls use **fail-open** — if an API is unreachable, legitimate users are never blocked
- Circuit breaker pattern prevents repeated calls to failing APIs (5-minute cooldown)
- All sensitive keys are stored encrypted (AES-256-CBC) in the database

---

## 🚀 Prerequisites

### Database Migration

Run the following migrations before configuring security features:

```bash
mysql -u your_username -p signula < _database/migrations/014_security_enhancements.sql
mysql -u your_username -p signula < _database/migrations/015_form_protection_settings.sql
mysql -u your_username -p signula < _database/migrations/017_credential_reset_system.sql
```

**Verify migration succeeded:**

```sql
-- Check new tables (migrations 014, 015)
SHOW TABLES LIKE 'tblIPReputation%';
SHOW TABLES LIKE 'tblBlocked%';
SHOW TABLES LIKE 'tblSecurity%';
SHOW TABLES LIKE 'tblSession%';
SHOW TABLES LIKE 'tblCircuit%';

-- Check credential reset tables (migration 017)
SHOW TABLES LIKE 'tblCredentialReset%';
SHOW TABLES LIKE 'tblSaltRotation%';

-- Check new settings (~45 rows)
SELECT settingKey, settingValue FROM tblSettings
WHERE settingKey LIKE 'captcha.%'
   OR settingKey LIKE 'security.%'
   OR settingKey LIKE 'email.%'
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

## 🔐 Feature 7: Mass Credential Reset

### How It Works

Mass Credential Reset allows administrators to trigger bulk security operations across the entire user base or targeted subsets. This is critical for incident response scenarios (security breaches, salt compromise, compliance mandates).

Three reset types are supported:

| Reset Type | Constant | Effect |
|------------|----------|--------|
| **Mass Password Reset** | `TYPE_MASS_PASSWORD_RESET` | Invalidates all affected user passwords, sends reset emails, optionally terminates active sessions |
| **Salt Rotation** | `TYPE_SALT_ROTATION` | Rotates the global encryption salt and re-hashes affected credentials. Creates audit trail in `tblSaltRotationHistory` |
| **Full Credential Reset** | `TYPE_FULL_CREDENTIAL_RESET` | Combines password reset + salt rotation + MFA invalidation. Use for confirmed security breaches |

Three scope modes control which users are affected:

| Scope | Constant | Description |
|-------|----------|-------------|
| **All Users** | `SCOPE_ALL_USERS` | Every active user account |
| **Filtered** | `SCOPE_FILTERED` | Users matching filter criteria (e.g., last login before date, specific role) |
| **Specific Users** | `SCOPE_SPECIFIC` | Manually selected user IDs |

### Prerequisites

Run migration `017_credential_reset_system.sql`:

```bash
mysql -u your_username -p signula < _database/migrations/017_credential_reset_system.sql
```

This creates:
- `tblCredentialResets` — Master table tracking each reset operation
- `tblCredentialResetUsers` — Per-user status for each operation (pending/processing/completed/failed/skipped)
- `tblSaltRotationHistory` — Encrypted audit trail for salt rotation events
- 15 new settings in `tblSettings` (6 credential reset + 9 email enhancement)
- 3 HTML email templates with dark mode support

### Configuration

```sql
-- Batch size: number of users processed per AJAX polling cycle
UPDATE tblSettings SET settingValue = '100'
WHERE settingKey = 'security.credential_reset.batch_size';

-- Email priority (1 = highest, 10 = lowest)
UPDATE tblSettings SET settingValue = '1'
WHERE settingKey = 'security.credential_reset.email_priority';

-- Token expiry: hours before password reset links expire
UPDATE tblSettings SET settingValue = '72'
WHERE settingKey = 'security.credential_reset.token_expiry_hours';

-- Require typed confirmation ("CONFIRM RESET") before initiating
UPDATE tblSettings SET settingValue = '1'
WHERE settingKey = 'security.credential_reset.require_confirmation';

-- Invalidate all active sessions when resetting credentials
UPDATE tblSettings SET settingValue = '1'
WHERE settingKey = 'security.credential_reset.invalidate_sessions';
```

### Admin UI

Access the admin interface at:

```
/admin/security/credential-reset/
```

**Requirements:** Super Admin access (enforced by `requireSuperAdmin()`)

The UI provides a 3-step wizard:

1. **Select Reset Type** — Choose from the 3 reset types with colour-coded descriptions
2. **Configure Scope & Reason** — Select scope (all/filtered/specific), enter mandatory reason for audit trail
3. **Progress Tracking** — Real-time progress bar with AJAX batch processing (500ms polling intervals)

Additional UI features:
- **Typed confirmation dialog** — Must type "CONFIRM RESET" to proceed (prevents accidental execution)
- **Operation history table** — View all past reset operations with status, affected count, and timestamps
- **Compliance reports** — View non-compliant users and generate compliance summaries

### API Endpoints

All credential reset API calls go through `/admin/api/user-actions/` with the following action values:

| Action | Method | Description |
|--------|--------|-------------|
| `initiate_mass_reset` | POST | Start a new credential reset operation |
| `process_reset_batch` | POST | Process the next batch of users |
| `get_reset_status` | GET | Get current status/progress of a reset |
| `list_reset_operations` | GET | List all past reset operations |
| `cancel_reset` | POST | Cancel an in-progress reset |
| `get_compliance_report` | GET | Generate compliance summary |
| `get_salt_history` | GET | View salt rotation audit trail |

### Email Notifications

Three HTML email templates are included (stored in `tblEmailTemplates`):

| Template | Sent When | Contains |
|----------|-----------|----------|
| `security_breach_alert` | Full credential reset initiated | Urgency notice, reset link, security tips |
| `mass_password_reset` | Password reset initiated | Reset link, expiry time, reason |
| `credential_reset_complete` | User completes their reset | Confirmation, security recommendations |

All templates feature:
- Responsive HTML layout with dark mode (`prefers-color-scheme: dark`)
- AMP for Email support (Gmail, Yahoo, Mail.ru, AOL) via `text/x-amp-html` MIME part
- Automatic plain-text fallback generation
- Customisable via `EmailTemplateBuilder` utility class

### Settings Reference

| Setting Key | Type | Default | Description |
|-------------|------|---------|-------------|
| `security.credential_reset.batch_size` | integer | `100` | Users processed per batch cycle |
| `security.credential_reset.email_priority` | integer | `1` | Email send priority (1-10) |
| `security.credential_reset.token_expiry_hours` | integer | `72` | Reset token lifetime (hours) |
| `security.credential_reset.require_confirmation` | boolean | `1` | Require typed confirmation |
| `security.credential_reset.invalidate_sessions` | boolean | `1` | Kill active sessions on reset |
| `security.global_salt` | encrypted | (auto) | Global encryption salt |
| `email.html.enabled` | boolean | `1` | Enable HTML email sending |
| `email.amp.enabled` | boolean | `0` | Enable AMP for Email support |
| `email.dkim.enabled` | boolean | `0` | Enable DKIM signing |
| `email.dkim.selector` | string | (empty) | DKIM DNS selector |
| `email.dkim.private_key` | encrypted | (empty) | DKIM private key |

### Programmatic Usage

```php
// Initiate a mass password reset for all users
$resetId = CredentialResetService::initiateMassReset(
    adminUserID: $adminId,
    resetType: CredentialResetService::TYPE_MASS_PASSWORD_RESET,
    reason: 'Quarterly security rotation',
    scope: CredentialResetService::SCOPE_ALL_USERS
);

// Process the next batch
$result = CredentialResetService::processNextBatch($resetId);
// Returns: ['processed' => 100, 'remaining' => 450, 'status' => 'processing']

// Check status
$status = CredentialResetService::getResetStatus($resetId);

// Cancel an in-progress reset
CredentialResetService::cancelReset($resetId, $adminId);

// Get compliance report
$report = CredentialResetService::getComplianceReport($resetId);

// View non-compliant users (haven't completed their reset)
$users = CredentialResetService::getNonCompliantUsers($resetId, limit: 50);

// Salt rotation history
$history = CredentialResetService::getSaltRotationHistory(limit: 20);
```

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

### New Tables (Migration 017)

| Table | Purpose | Auto-cleanup |
|-------|---------|-------------|
| `tblCredentialResets` | Master record for each reset operation | Manual |
| `tblCredentialResetUsers` | Per-user status tracking within each operation | Manual |
| `tblSaltRotationHistory` | Encrypted audit trail for salt rotations | Manual |

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

### Credential reset stuck in "processing"

1. Check `tblCredentialResets` for the operation status — it may have been interrupted
2. Verify batch size is reasonable (`security.credential_reset.batch_size`) — too large may timeout
3. Check PHP error logs for database or email sending failures
4. Use the admin UI to cancel the stuck operation, then re-initiate

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
| `CredentialResetService.php` | `web/private_html/admin/` | Mass credential reset operations |
| `EmailTemplateBuilder.php` | `web/private_html/email/` | HTML/AMP email template builder |
| `credential-reset.php` | `web/public_html/admin/security/` | Admin UI for credential resets |
| `014_security_enhancements.sql` | `_database/migrations/` | Database tables + settings |
| `015_form_protection_settings.sql` | `_database/migrations/` | Form protection settings |
| `017_credential_reset_system.sql` | `_database/migrations/` | Credential reset tables + email settings |
| `crawlerdetect_loader.php` | `web/_lib/crawlerdetect/` | Library loader |

---

## 📚 External Documentation

- [CloudFlare Turnstile Docs](https://developers.cloudflare.com/turnstile/)
- [Google reCAPTCHA v3 Docs](https://developers.google.com/recaptcha/docs/v3)
- [AbuseIPDB API Docs](https://docs.abuseipdb.com/)
- [proxycheck.io API Docs](https://proxycheck.io/api/)
- [CrawlerDetect GitHub](https://github.com/JayBizzle/Crawler-Detect)
- [ip-api.com Docs](https://ip-api.com/docs) (used for impossible travel geo lookup)

---

## 🔐 Autopilot Hardening Pass — 2026-07-01 (cycles 3-12, branch `autopilot/2026-06-30`)

This section documents additional security fixes applied during the automated foundation-hardening run. All changes are on `autopilot/2026-06-30` and require a push + merge before they reach production.

### FG-013 — WebAuthn Authentication Bypass (CRITICAL, now closed)

**What was wrong:** The WebAuthn `auth-verify` endpoint accepted assertions without performing cryptographic signature verification. Any attacker who could observe or replay a valid challenge response could authenticate as any passkey-registered user.

**What was fixed:** Full CBOR/COSE decoding, public-key PEM extraction, and `openssl_verify()` signature check are now performed on every assertion. Sign-count clone-detection is also enforced (a credential whose sign-count does not advance is flagged).

**Red-team status:** Verified across 7 distinct attack vectors — replay, forged signature, wrong key, zero sign-count, malformed CBOR, wrong RP ID, wrong origin. All hold as of cycle 6.

**File:** `web/private_html/auth/WebAuthnHandler.php`

### CORS Allowlist (`api.cors.allowed_origins`)

**What was wrong:** API responses sent `Access-Control-Allow-Origin: *` together with `Access-Control-Allow-Credentials: true`. This combination is invalid per the CORS spec and, in some browser/proxy configurations, may allow cross-origin credentialed requests from any origin.

**What was fixed:** Origin is now validated against a configurable allowlist before being reflected. If the request origin is not in the list, no `Access-Control-Allow-Origin` header is emitted.

**Admin configuration required:**

```sql
-- Set the allowed origins (comma-separated, exact match)
UPDATE tblSettings
SET settingValue = 'https://signulo.id,https://app.signulo.id'
WHERE settingKey = 'api.cors.allowed_origins';
```

If the setting is empty or absent, same-origin only is enforced (safest default).

**File:** `web/private_html/api/BaseController.php`

### X-Frame-Options / CSP frame-ancestors / X-Content-Type-Options on API

All API responses now include:

```http
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Content-Security-Policy: frame-ancestors 'none'
```

These were missing from the API layer (though already present on page responses). No admin configuration required.

### Admin CSRF — Two Holes Closed

1. **Primary admin CSRF** — admin state-changing endpoints now verify the CSRF token before acting, consistent with the rest of the application.
2. **Rate-limits unblock endpoint** — a second CSRF hole on the rate-limit management endpoint was found and closed in the same cycle.

No admin configuration required; both fixes are code-level.

### SMTP Encryption-Before-AUTH (issue #24/#25)

**What was wrong:** The SMTP provider would attempt `AUTH LOGIN` / `AUTH PLAIN` even if the connection had not yet been upgraded to TLS, risking credential exposure on the wire.

**What was fixed:** The provider now enforces `STARTTLS` before issuing any AUTH command. If STARTTLS negotiation fails, the send is aborted and an error is logged.

**Admin note:** Ensure your SMTP server supports STARTTLS. The `email.smtp.encryption` setting must be set to `tls` or `ssl`; a value of `none` will now cause AUTH to be skipped entirely (mail sent without authentication — only appropriate for localhost relay).

### `email.xmailer` Setting

The `X-Mailer` header is now suppressed when the `email.xmailer` setting is blank or absent. Previously a default value was always emitted, exposing the mailer software version. Set it explicitly if you want a custom value, or leave it blank (recommended for production).

```sql
-- Suppress X-Mailer header entirely (recommended)
UPDATE tblSettings SET settingValue = '' WHERE settingKey = 'email.xmailer';
```

### Credential-Reset Authorisation Tightened (issues #27-#29)

- Non-super-admin paths in the credential-reset API are now blocked at the controller level before any DB work is performed.
- Result sets in the list/status endpoints are now bounded (pagination enforced) to prevent unbounded queries on large datasets.
- Composite indexes added via migration `027_credential_reset_indexes.sql` for performance on large `tblCredentialResets` / `tblCredentialResetUsers` queries.

Run migration 027 if not already applied:

```bash
mysql -u your_username -p signula < _database/migrations/027_credential_reset_indexes.sql
```

### 13 Deferred MEDIUM Issues (#22-#34) — Summary

| Issue | Fix |
| ----- | --- |
| #22 Email recipient validation | Recipients validated before send; malformed addresses rejected |
| #23 AMP sender allowlist | AMP `from` address checked against configured allowlist |
| #24/#25 SMTP encryption-before-AUTH | See above |
| #26 X-Mailer suppression | See `email.xmailer` above |
| #27 Credential-reset authz | Non-super-admin blocked before DB access |
| #28 Credential-reset pagination | Result sets bounded |
| #29 Credential-reset indexes | Migration 027 adds composite indexes |
| #30-#31 WebAuthn challenge TOCTOU | Atomic compare-and-set on challenge consumption |
| #32-#34 Token URL-encoding | `urlencode()` applied at 4 sites where hex tokens appear in URLs (defensive) |

All 13 are addressed on the `autopilot/2026-06-30` branch. They are not yet closed on GitHub (branch not yet pushed).

### New Settings Reference

| Setting key | Default | Purpose |
| ----------- | ------- | ------- |
| `api.cors.allowed_origins` | _(empty = same-origin only)_ | Comma-separated list of allowed CORS origins |
| `email.xmailer` | _(empty)_ | Value for `X-Mailer` header; blank = suppress header |
| `email.smtp.encryption` | `tls` | SMTP encryption mode (`tls`, `ssl`, `none`) |

### Migrations Added

| Migration | Purpose |
| --------- | ------- |
| `025_registration_fix.sql` | Adds missing `tblUsers.organizationID` column (fixes user registration) |
| `026_mfa_columns.sql` | Adds missing MFA columns referenced by `MFA.php` |
| `027_credential_reset_indexes.sql` | Composite indexes for credential-reset performance |
| `028_multi_org_tables.sql` | Creates 6 multi-org tables required by `Organization.php` |
| `029_enum_widening.sql` | Widens `activityCategory` ENUM to include all values written by the codebase |

Apply in sequence after migration 024:

```bash
for f in 025 026 027 028 029; do
  mysql -u your_username -p signula < _database/migrations/${f}_*.sql
done
```

---

## 🔑 JWT API Authentication (G-003)

**Introduced in:** v2.8.0-beta · **Migration:** `030_jwt_authentication.sql`
**Source files:**
- `web/private_html/security/Jwt.php` — RS256 sign/verify facade (alg-pinned)
- `web/private_html/security/KeyManager.php` — RSA keypair lifecycle, JWKS assembly
- `web/private_html/security/TokenService.php` — issuance, rotation, reuse detection, revocation
- `web/private_html/api/controllers/JwtAuthController.php` — HTTP endpoints
- `web/public_html/.well-known/jwks.json/index.php` — JWKS public-key document

---

### Overview

G-003 provides **RS256 JWT bearer authentication** for the SIGNula REST API, giving
SPAs and mobile apps a short-lived, cryptographically verifiable access token plus a
rotating opaque refresh token. It coexists with the existing API-key (`X-API-Key`) and
browser-session paths; it does not replace them.

The implementation is built on a locally-vendored `firebase/php-jwt` v6.x (placed in
`web/_lib/jwt/src/`), wrapped by a thin project-owned `Jwt` facade that enforces
SIGNula token policy. Composer is **not** required at runtime — all files are loaded
via `require_once` with `file_exists()` guards, matching the established `_lib/`
pattern.

---

### 1. Policy Settings (`jwt.*` in `tblSettings`)

All policy settings are seeded by migration 030 (`INSERT IGNORE`) and are
admin-tunable via the backend settings UI without code changes.

| `settingKey` | Default | Type | Sensitive | Notes |
|---|---|---|---|---|
| `jwt.enabled` | `1` | boolean | no | Master switch — set to `0` to disable JWT bearer auth entirely |
| `jwt.issuer` | `https://signula.id` | string | no | `iss` claim; verified on every decode |
| `jwt.audience` | `https://signula.id/api` | string | no | Default `aud` claim for first-party API tokens |
| `jwt.algorithm` | `RS256` | string | no | Signing algorithm — RS256 only; HS256/none are rejected |
| `jwt.access_ttl` | `900` | integer | no | Access-token lifetime in seconds (default 15 min) |
| `jwt.refresh_ttl` | `2592000` | integer | no | Refresh-token lifetime in seconds (default 30 days, sliding via rotation) |
| `jwt.leeway_seconds` | `60` | integer | no | Clock-skew tolerance for `exp`/`nbf`/`iat` — keep small (60 s max recommended) |
| `jwt.key_bits` | `3072` | integer | no | RSA key size (2048 min; 3072 default for longevity) |
| `jwt.signing_key.active_kid` | _(empty)_ | string | no | `kid` of the current signing key; set by `KeyManager::generateKey()` |
| `jwt.auto_rotate_days` | `90` | integer | no | Warn/auto-mint a new key when the active key is older than this |
| `jwt.rate_limit.window_seconds` | `900` | integer | no | Rate-limit window (seconds) shared by `/auth/token` and `/auth/refresh` |
| `jwt.rate_limit.token_per_ip` | `30` | integer | no | Max `/auth/token` requests per IP per window |
| `jwt.rate_limit.token_per_identifier` | `10` | integer | no | Max `/auth/token` requests per login identifier per window (credential-stuffing guard) |
| `jwt.rate_limit.refresh_per_ip` | `60` | integer | no | Max `/auth/refresh` requests per IP per window |

The two signing-key rows are **NOT seeded by the migration** — they are written at
runtime by `KeyManager::generateKey()`:

| `settingKey` pattern | Sensitive | Description |
|---|---|---|
| `jwt.signing_key.<kid>.private_pem` | **YES** (`isSensitive=1`) | AES-256-CBC-encrypted private-key PEM |
| `jwt.signing_key.<kid>.public_jwk` | no | JSON-encoded public JWK (`{kty,use,alg,kid,n,e}`) served by the JWKS endpoint |

#### Quick-reference SQL snippet (check current state)

```sql
SELECT settingKey, settingValue, isSensitive
FROM tblSettings
WHERE settingKey LIKE 'jwt.%'
ORDER BY settingKey;
```

---

### 2. RS256 Signing Keys — Generation, Storage, and Rotation

#### 2.1 Key Storage Model

Private keys are stored using a **DB-first, `_private`-file fallback** pattern that
mirrors how `ENCRYPTION_KEY` lives in `web/_private/auth.php`:

1. **Primary (tblSettings, encrypted):** `KeyManager::generateKey()` calls
   `SecurityUtils::encrypt()` (AES-256-CBC, random IV per call) and stores the
   encrypted PEM in `tblSettings` with `isSensitive=1`. The per-call random IV
   provides per-row confidentiality — no additional per-row salt is needed.
   `ENCRYPTION_KEY` (from `_private/auth.php`) encrypts the signing private key at
   rest; it does **not** sign tokens.

2. **Fallback (`_private` key files):** `KeyManager` mirrors the plaintext private and
   public PEM to `web/_private/keys/jwt/<kid>.key` and `<kid>.pub` (directory `0700`,
   files `0600`, outside the web root). This fallback is used when the DB row is absent
   or unreadable, providing operational resilience on Dreamhost shared hosting.

Public keys are **not sensitive** and are stored in `tblSettings` (`isSensitive=0`)
as JSON-encoded JWKs so the JWKS endpoint is a cheap read.

**Important:** `ENCRYPTION_KEY` and the JWT signing key are distinct secrets.
`ENCRYPTION_KEY` encrypts the signing key at rest; the signing private key signs tokens.

#### 2.2 Generating the First Key (Admin Action)

Before any tokens can be issued, an operator must generate the first signing keypair.
This is done via `KeyManager::generateKey()`, which can be called from the admin UI
or a one-time setup script:

```php
// _scripts/generate_jwt_key.php  (run once, from the web root, NOT web-accessible)
define('SIGNULA_INIT', true);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR
    . '_config' . DIRECTORY_SEPARATOR . 'config.php';

$kid = KeyManager::generateKey(makeActive: true);
echo "Generated signing key kid: {$kid}\n";
echo "Set jwt.signing_key.active_kid = {$kid} in tblSettings\n";
```

After running, verify in the database:

```sql
-- Confirm the active kid is set
SELECT settingKey, settingValue FROM tblSettings
WHERE settingKey IN ('jwt.signing_key.active_kid')
   OR settingKey LIKE 'jwt.signing_key.%.public_jwk';

-- Confirm the private key row is marked sensitive
SELECT settingKey, isSensitive FROM tblSettings
WHERE settingKey LIKE 'jwt.signing_key.%.private_pem';
-- isSensitive MUST be 1 for that row
```

Also verify the `_private` key files were mirrored:

```bash
ls -la web/_private/keys/jwt/
# Expected: <kid>.key (0600) and <kid>.pub (0600) in a 0700 directory
```

#### 2.3 Key Rotation

Routine rotation should be performed every `jwt.auto_rotate_days` days (default 90).

**Procedure:**

1. **Generate a new key** (while keeping the old one active):
   ```php
   $newKid = KeyManager::rotateKey(); // mints a new key AND promotes it to active
   ```
   `rotateKey()` is an alias for `generateKey(makeActive: true)`. The old key's
   **public JWK stays in the JWKS document** so tokens signed by it continue to verify
   until they expire (access tokens: ≤15 min; allow slightly longer for clock skew).

2. **Verify the rotation:**
   ```sql
   SELECT settingValue FROM tblSettings WHERE settingKey = 'jwt.signing_key.active_kid';
   -- Must show the new kid
   ```
   Also verify `GET /.well-known/jwks.json` returns both the old and new public keys.

3. **Retire the old key** (after all tokens signed by it have expired):
   ```php
   KeyManager::retireKey($oldKid); // removes public JWK from JWKS; deletes encrypted private PEM
   ```
   Do **not** retire a key until `now > rotation_time + jwt.access_ttl + jwt.leeway_seconds`.
   For the default 15-min access TTL + 60-s leeway, wait at least 16 minutes after
   rotation before retiring. In practice, waiting 30–60 minutes is safer.

4. **Confirm retirement:**
   ```sql
   SELECT COUNT(*) FROM tblSettings
   WHERE settingKey LIKE 'jwt.signing_key.<old_kid>.%';
   -- Must return 0 rows
   ```

**Emergency rotation (suspected key compromise):**

1. Run `KeyManager::rotateKey()` immediately to generate a new active key.
2. Run `KeyManager::retireKey($compromisedKid)` to drop the compromised key from JWKS.
   Tokens signed by it will now **fail to verify immediately** (unknown `kid`), acting
   as an instant revocation for all outstanding access tokens signed by that key.
3. Run `TokenService::revokeAllForUser($userID)` for any users whose tokens are
   believed to be compromised — this also bumps `tblUsers.tokensInvalidBefore`.
4. Ensure the incident is logged in `tblAdminAuditLog`.

---

### 3. JWKS Endpoint (`GET /.well-known/jwks.json`)

`KeyManager::getJwks()` assembles `{ "keys": [ {kty, use, alg, kid, n, e}, ... ] }`
from every `jwt.signing_key.*.public_jwk` row in `tblSettings`. Only public RSA keys
are published; the handler strips any JWK carrying a private exponent (`d`, `p`, `q`,
`dp`, `dq`, `qi`) as a defence-in-depth measure before output.

The endpoint is:
- **Unauthenticated** — public verifiability is the entire point.
- **Cacheable** — `Cache-Control: public, max-age=3600`. Verifiers refetch on
  encountering an unknown `kid`.
- Served by `web/public_html/.well-known/jwks.json/index.php` (a directory literally
  named `jwks.json`; the co-located `.htaccess` permits the dot-path and disables
  the API router rewriting inside `.well-known/`).

This endpoint is the **shared contract with G-001** (SIGNula as IdP). Do not change
the JWK structure without a compatibility review.

---

### 4. Security Properties Enforced

| Property | Mechanism |
|---|---|
| **Algorithm pinned to RS256** | `Jwt::verify()` builds a `Firebase\JWT\Key($publicPem, 'RS256')` object. The library rejects any token whose header `alg` ≠ the key's algorithm **before** checking the signature. `alg:none` is rejected (the library has no `none` handler). The RS256→HS256 confusion attack (RSA public key used as HMAC secret) is rejected because the key type and algorithm are tied together in the `Key` object. The `Jwt` facade **never reads `alg` from the token header to choose the verification path.** |
| **`kid` validation** | The `kid` from the JWT header is used only to select a key from SIGNula's own JWKS. `KeyManager::isValidKid()` validates the `kid` against a strict charset (`[A-Za-z0-9._-]`, max 128 chars, no `..`) before any filesystem or DB use — prevents path traversal and SQL injection via `kid`. Unknown `kid` → reject. |
| **`iss` and `aud` validation** | Every decode verifies `iss == jwt.issuer` and `aud` contains the expected audience string. Constant-time compare (`hash_equals()`) used throughout. |
| **Clock-skew leeway** | `jwt.leeway_seconds` (default 60 s) applied to `exp`/`nbf`/`iat`. The leeway is capped at the configured value; a large leeway is a configuration problem, not a code change. |
| **`jti` denylist (access-token revocation)** | `Jwt::verify()` accepts an injectable denylist checker. `TokenService::verifyAccessToken()` wires `TokenService::isJtiRevoked()` into this checker so every verify hits `tblRevokedTokens` (indexed, self-expiring; rows purged after `expiresAt < NOW()`). |
| **Refresh-token single-use rotation** | `TokenService::refresh()` spends the presented token with an atomic `UPDATE ... SET rotatedAt=NOW() WHERE tokenID=? AND rotatedAt IS NULL AND revoked=0` and reads `getAffectedRows()`. Exactly one concurrent refresh wins; the loser is treated as reuse. |
| **Refresh-token reuse detection → family revoke** | If a rotated or revoked refresh token is replayed, `TokenService::handleReuse()` sets `revoked=1` for all rows in the family, raises a `SecurityAlertManager::TYPE_SESSION_HIJACK` HIGH alert, and logs to `tblActivityLog`. The client receives a generic 401 with no reason. |
| **`tokensInvalidBefore` mass revocation** | `tblUsers.tokensInvalidBefore` (added by migration 030) lets an admin or "log out everywhere" flow bump a user-level cutoff. `TokenService::verifyAccessToken()` rejects any access token whose `iat` is before this cutoff, without needing to enumerate jtis. |
| **No token material in logs** | Access tokens, refresh tokens, signing keys, and the `Authorization` header are **never** logged. Only `jti`, `userID`, `kid`, and outcome are recorded in `tblActivityLog`/`tblErrorLog`. |
| **`Cache-Control: no-store` on token responses** | Set by `JwtAuthController::sendNoStore()` before every token issuance/rotation response so intermediaries never cache tokens. |
| **Windowed rate limiting** | `/auth/token` is rate-limited per-IP (`jwt.rate_limit.token_per_ip`) **and** per-identifier (`jwt.rate_limit.token_per_identifier`). `/auth/refresh` is rate-limited per-IP (`jwt.rate_limit.refresh_per_ip`). Uses the existing `SecurityUtils::checkRateLimit()` infrastructure. |
| **Subject from server-verified identity only** | `JwtAuthController::resolveSubject()` obtains the `userID` exclusively from `Auth::isAuthenticated()` (session) or `Auth::login()` (password grant). No client-supplied `userID`/`sub` ever reaches token issuance. |

---

### 5. Token Client Storage Guidance (for API integrators)

Document this for integrators — not enforced server-side:

- **Access token** — store in memory (JS variable); do not persist to `localStorage` or
  `sessionStorage`.
- **Refresh token** — store in an `httpOnly; Secure; SameSite=Strict` cookie (browser)
  or in secure OS-level key storage (native apps). **Never** store in `localStorage`.
- **HTTPS** — tokens must only be transmitted over HTTPS. The production
  `.htaccess` HTTPS-force must be enabled before go-live.

---

### 6. Migration 030 — Summary

```
File: _database/migrations/030_jwt_authentication.sql
```

| Object | Type | Notes |
|---|---|---|
| `tblRefreshTokens` | New table | Rotating, family-based refresh tokens; SHA-256 hash only (plaintext never stored) |
| `tblRevokedTokens` | New table | Self-expiring `jti` denylist for revoked access tokens |
| `tblUsers.tokensInvalidBefore` | New column (nullable DATETIME) | User-level mass-revocation lever |
| `jwt.*` settings (14 rows) | `INSERT IGNORE` into `tblSettings` | Policy defaults; signing-key rows inserted at runtime by `KeyManager` |
| `cleanup_jwt_tokens` | Daily MySQL EVENT | Purges `tblRevokedTokens WHERE expiresAt < NOW()` to keep the denylist bounded |

Apply after migration 029:

```bash
mysql -u your_username -p signula < _database/migrations/030_jwt_authentication.sql
```

Verify:

```sql
-- All three should return 1
SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('tblRefreshTokens','tblRevokedTokens');

SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUsers'
  AND COLUMN_NAME = 'tokensInvalidBefore';

-- Should return 14 jwt.* rows
SELECT COUNT(*) FROM tblSettings WHERE settingKey LIKE 'jwt.%';
```

---

### 7. Vendored Library (`firebase/php-jwt`)

`web/_lib/jwt/src/` contains the vendored source of `firebase/php-jwt` v6.x (MIT
licence). The version is pinned in `web/_lib/jwt/VERSION`.

**Updating the library (dev machine, Composer available):**

```bash
php composer require firebase/php-jwt:^6.10
# Copy the updated source files:
cp vendor/firebase/php-jwt/src/*.php web/_lib/jwt/src/
# Update the version marker:
echo "6.10.x" > web/_lib/jwt/VERSION
```

**Subscribe to security advisories** for `firebase/php-jwt` at
https://github.com/firebase/php-jwt/security/advisories and the GitHub Security
Advisory Database (GHSA). Update promptly for any JWT-related CVE.

---

## 🪪 OIDC Provider (G-001)

**Introduced in:** v2.9.0-beta · **Migration:** `031_oauth_provider_clients.sql`
(`tblOAuthClients`, `tblOAuthClientRedirectUris`, `tblOAuthAuthCodes`,
`tblOAuthConsents`, `oidc.*` settings) — extended by `032_oauth_token_endpoint.sql`
(`tblOAuthAuthCodes.issuedFamilyID`, for replay→family-revoke) and
`033_oauth_subject_store.sql` (`tblOAuthSubjects`, the pairwise-subject↔userID map).
**Source files:**
- `web/private_html/auth/OAuthClientManager.php` — client registration, redirect-URI/scope checks, pairwise `sub` computation
- `web/private_html/auth/OAuthAuthorizeService.php` — `/oauth/authorize-idp` request validation + consent + code issuance
- `web/private_html/auth/OAuthTokenService.php` — `/oauth/token` client auth + code redemption + refresh rotation + token/id_token minting
- `web/private_html/auth/OAuthUserInfoService.php` — `/oauth/userinfo` claim gating
- `web/private_html/auth/OAuthRevocationService.php` — `/oauth/revoke` (RFC 7009)
- `web/private_html/auth/OidcDiscoveryService.php` — `/.well-known/openid-configuration` document builder + the `oidc.enabled` gate every endpoint calls
- `web/public_html/oauth/authorize-idp.php`, `token.php`, `userinfo.php`, `revoke.php` — thin HTTP controllers
- `web/public_html/.well-known/openid-configuration/index.php`
- `web/public_html/partners/admin/oauth-clients.php` — partner-admin client registration UI

---

### ⚠️ Master Switch — `oidc.enabled` (read this before anything else)

**The OIDC Provider ships OFF.** Migration 031 seeds `oidc.enabled = '0'`, and
`OidcDiscoveryService::isProviderEnabled()` is the FIRST check every one of
the 5 provider endpoints (`/oauth/authorize-idp`, `/oauth/token`,
`/oauth/userinfo`, `/oauth/revoke`, `/.well-known/openid-configuration`)
makes — before any database-bound client/grant work. While the switch is
off, **all 5 endpoints refuse with `404`** (a local, non-redirectable error
page for `/oauth/authorize-idp`; `{"error":"not_found"}` for the other four)
so a disabled provider does not even confirm the endpoints exist.

**To enable the provider in production**, update `oidc.enabled` in the
backend database `tblSettings` table (via the admin Settings UI or direct
SQL — this is an application setting, not a server/CLI config value):

> **Backend DB → tblSettings → key `oidc.enabled` → value `1` → Save.**
> (Admin UI path: **Global Admin → Settings → `oidc` category →
> `oidc.enabled` → Save**, the same generic settings editor used for every
> other `tblSettings`-backed feature in this document.)

```sql
-- Turn the OIDC Provider ON (run only once you have registered at least one
-- RP client via /partners/admin/oauth-clients and are ready to accept
-- "Sign in with SIGNula.id" traffic):
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'oidc.enabled';
```

Verify:

```sql
SELECT settingValue FROM tblSettings WHERE settingKey = 'oidc.enabled';
-- Must show '1'
```

Then confirm live: `GET /.well-known/openid-configuration` should return the
discovery document (`200`) instead of `{"error":"not_found"}` (`404`).

**Known gap (verified in this codebase):** the generic settings admin pages
(`web/public_html/admin/settings/index.php` and `.../admin/settings/oauth.php`)
`require_once` a `web/_backend/SessionManager.php` file that does not exist
anywhere in this repository (a pre-existing issue affecting ~74 admin files,
unrelated to G-001 — see the `NEEDS-LEAD-REVIEW` note in
`web/public_html/partners/admin/oauth-clients.php`'s header comment). Until
that gap is fixed, use the **direct SQL** path above rather than the admin
Settings UI to flip `oidc.enabled`.

---

### 1. Policy Settings (`oidc.*` in `tblSettings`)

All policy settings are seeded by migration 031 (`INSERT IGNORE`,
`settingCategory = 'oidc'`) and are admin-tunable via the backend settings UI
without a code change (subject to the "Known gap" note above).

| `settingKey` | Default | Type | Notes |
|---|---|---|---|
| `oidc.enabled` | `0` | boolean | **Master switch** — see above. All 5 provider endpoints 404 while off. |
| `oidc.issuer` | `https://signula.id` | string | `iss` claim on every `id_token` + the discovery document's `issuer`. **MUST equal `jwt.issuer` (G-003)** — never let these drift apart. |
| `oidc.authcode_ttl` | `60` | integer | Authorization-code lifetime in seconds (short-lived by design — RFC 6749 §4.1.2 recommends ≤10 min; SIGNula defaults far tighter). |
| `oidc.require_pkce` | `1` | boolean | Require PKCE (S256) for **every** client, not just public ones. Keep ON in production. |
| `oidc.allow_plain_pkce` | `0` | boolean | Allow the PKCE `plain` method. Keep **OFF** in production — `plain` sends the code_verifier itself as the challenge, which defeats PKCE's protection against a leaked authorization code. |
| `oidc.subject_type` | `public` | string | **Global *advisory* default only** — see the pairwise-subject note below; does **not** override a client's own `subjectType` column. |
| `oidc.consent_remember` | `1` | boolean | Remember a user's consent per client+scope so repeat sign-ins auto-skip the consent screen (unless the RP forces `prompt=consent`). |
| `oidc.access_ttl` | `900` | integer | RP access-token lifetime in seconds (15 min) — mirrors `jwt.access_ttl`'s default. |
| `oidc.id_token_ttl` | `3600` | integer | `id_token` lifetime in seconds (1 hour). |
| `oidc.refresh_enabled` | `1` | boolean | Whether the `offline_access` scope may mint a refresh token at all. |

A further setting is **not** seeded by the migration — it is minted at
runtime, encrypted, on first use (mirrors `KeyManager::generateKey()`'s "never
hardcode a secret in a migration" pattern):

| `settingKey` | Sensitive | Description |
|---|---|---|
| `oauth.pairwise_salt` | **YES** (`isSensitive=1`, `settingType='encrypted'`) | Server-side salt used to compute every pairwise `sub` claim (see below). Auto-generated by `OAuthClientManager::getPairwiseSalt()` the first time a pairwise subject is ever computed; encrypted with the same `SecurityUtils::encrypt()`/`ENCRYPTION_KEY` mechanism the JWT signing key uses. |

```sql
-- Quick-reference: check current oidc.* policy state
SELECT settingKey, settingValue, isSensitive
FROM tblSettings
WHERE settingKey LIKE 'oidc.%' OR settingKey = 'oauth.pairwise_salt'
ORDER BY settingKey;
```

**NEEDS-LEAD-REVIEW (flagged directly in migration 031's own header
comment, reproduced here for visibility):** `tblOAuthClients.subjectType`
DEFAULTs to `'pairwise'` at the per-client column level, while the global
advisory setting `oidc.subject_type` is seeded `'public'`. These are
**deliberately not forced to match** — confirm which should actually govern
new-client-registration defaults in the partner-admin UI before relying on
`oidc.subject_type` for anything beyond documentation/reference. In
practice, `OAuthClientManager::registerClient()` defaults every new client to
`subjectType='pairwise'` regardless of the global setting's value (see
below).

---

### 2. RS256 Signing Key — Shared with G-003 (no separate OIDC key)

The OIDC Provider does **not** mint or manage its own signing key. Both the
access token (`typ: at+jwt`) and the `id_token` issued by `/oauth/token` are
signed with the **same active RS256 key** described in [§2 "RS256 Signing
Keys" of the JWT API Authentication section above](#2-rs256-signing-keys--generation-storage-and-rotation) —
`Jwt::signIdToken()` calls the identical `KeyManager::getActiveKey()` used
for first-party access tokens. There is nothing OIDC-specific to generate,
rotate, or retire: follow the G-003 key-rotation procedure above and both the
first-party API and every "Sign in with SIGNula.id" RP stay correctly
verifiable throughout.

The public half is published at the **same** `GET /.well-known/jwks.json`
(G-003) — the OIDC discovery document's `jwks_uri` simply points RP client
libraries at that existing endpoint (see §3 below). `jwks.json` is
**deliberately not gated** by `oidc.enabled` — it stays live for first-party
API verifiers regardless of whether the OIDC provider surface is on.

---

### 3. Pairwise Subject Model (`sub` claim privacy)

By default, every **newly registered** OAuth client gets
`subjectType = 'pairwise'` (the `tblOAuthClients.subjectType` column
default — see the NEEDS-LEAD-REVIEW note above for the one place this can
diverge from the global setting). A pairwise `sub` is a **per-client,
salted, one-way, opaque** identifier — never the user's raw internal `userID`
— computed as:

```
sub = SHA-256( sectorIdentifier . '|' . userID . '|' . oauth.pairwise_salt )
```

- **`sectorIdentifier`** defaults to the host of the client's first
  registered `redirect_uri` (falls back to `client:<clientIdentifier>` if no
  parseable host exists, e.g. a bare native-app custom scheme) — set at
  registration time (`OAuthClientManager::registerClient()`), stored on the
  client row.
- **`oauth.pairwise_salt`** is the single server-wide secret described in §1
  above — encrypted at rest, minted once, shared by every pairwise
  computation. Rotating it would silently change **every** existing pairwise
  `sub` for **every** client (breaking RP-side user-identity continuity) —
  there is deliberately no rotation tooling for it; treat it as a
  long-lived, back-up-worthy secret, not a routinely-rotated key.
- **Not reversible:** because SHA-256 is one-way, SIGNula itself cannot
  recompute a `userID` from a `sub` claim alone. The (`subjectHash`,
  `clientID`) → `userID` mapping needed to resolve an incoming access
  token's `sub` back to a real user (e.g. inside `/oauth/userinfo`) is
  persisted separately in `tblOAuthSubjects` (migration 033,
  `033_oauth_subject_store.sql`) at mint/rotation time — never recomputed by
  guessing the salt.
- **`subjectType = 'public'`** (settable per client at registration, or via
  the partner-admin "Advanced settings" panel) instead returns the raw
  `(string) userID` as `sub` — only choose this for a fully-trusted
  first-party-equivalent RP that has an explicit reason to need a stable,
  shared identifier; it forfeits the cross-RP-correlation protection
  pairwise subjects exist for.

---

### 4. Security Posture Summary

| Property | Mechanism |
|---|---|
| **`redirect_uri` exact match** | `OAuthClientManager::isExactRedirectMatch()` — byte-for-byte (`===`), never a substring or normalised match. Checked BEFORE any error is ever redirected anywhere; an unknown client or a failed match renders a LOCAL, non-redirectable HTML error page (open-redirect defence). |
| **PKCE mandatory (S256)** | Required for every public client, every client with `pkceRequired=1`, and globally whenever `oidc.require_pkce` is on (default). RFC 7636's implicit "assume `plain` when the method is omitted" downgrade is deliberately NOT honoured — an explicit `code_challenge_method` is always required once a `code_challenge` is present. `plain` itself is only accepted when `oidc.allow_plain_pkce` is explicitly on (default off). |
| **Single-use authorization codes** | Consumed via one atomic, guarded `UPDATE ... SET consumedAt = NOW() WHERE codeHash = ? AND consumedAt IS NULL AND clientID = ?` — exactly one concurrent redemption attempt can ever win. A REPLAY of an already-consumed code revokes the exact token family that code's first legitimate exchange minted and raises a HIGH `SecurityAlertManager` alert. |
| **Client-bound codes** | The client-binding check is baked into the atomic consume `UPDATE` itself (not a later comparison) — a code issued to Client A can never be burned or redeemed by Client B, even with B's own valid credentials. |
| **Client-bound refresh tokens** | `TokenService::refresh()` rejects (before rotating/spending) any refresh token whose owning `clientID` does not match the authenticated caller — including a first-party token or one minted for a different RP. |
| **Rate-limited endpoints (B-059)** | `/oauth/token` and `/oauth/revoke` are rate-limited per-IP AND per-client (when a `client_id` was presented), reusing the `jwt.rate_limit.*` settings (G-003) under distinct bucket names so counters never mix with `/auth/token`'s own. |
| **No cross-client revoke** | `/oauth/revoke` only ever revokes a token owned by the client that just authenticated — verified via the refresh token's stored `clientID` or the access token's cryptographically-verified `aud` claim, never merely a peeked/unverified value. |
| **No validity oracle on revoke** | `/oauth/revoke` always returns `200` with an empty body once the client itself authenticates (RFC 7009 §2.2) — an unknown, already-revoked, or foreign-owned token is indistinguishable from a successful revoke. |
| **Scope-gated claims** | Both the `id_token` and `/oauth/userinfo` return `email`/`email_verified` only with the `email` scope, and `name`/`given_name`/`family_name`/(`preferred_username`/`picture` on userinfo only) only with the `profile` scope. A token minted with `openid` alone yields `sub` and nothing else. |
| **`offline_access`-gated refresh tokens** | A refresh token is only minted on the initial code exchange when the client both requested and was granted `offline_access` — no refresh token is issued to a client that never asked for one. |

---

### NEEDS-LEAD-REVIEW

- The exact SMTP behaviour when `email.smtp.encryption = 'none'` (mail sent without AUTH) should be confirmed against your Dreamhost shared-hosting relay config before deploying to production. Dreamhost typically requires authentication; `none` may cause all outbound mail to fail silently.

---

## 💳 Billing / Payments (G-002) — TEST-MODE

**G-002 ("Recurring Billing / Subscription Lifecycle Engine") ships TEST-MODE
ONLY.** No real money can move through it until a human deliberately flips a
single database setting — described in full below — AFTER configuring real
live provider credentials. This section documents that engine's safety model
and the exact go-live step.

### ⚠️ Master Switch — `billing.test_mode_guard` (read this before anything else)

`billing.test_mode_guard` (migration 036, `tblSettings`, category `billing`)
**defaults to `'1'` (ON)**. While ON, `BillingMode::assertTestMode($provider)`
is the single, central, FAIL-CLOSED gate that **every provider HTTP
money-mover in the codebase calls before touching the network** — PayPal
order/subscription creation and refunds, Stripe checkout-session creation and
refunds, Coinbase charge creation, and `PaymentManager::createSubscription()`/
`::recordPayment()`/`::changeTier()`'s own provider-revision calls. If the
guard is ON **and** the target provider's configured mode resolves to
`'live'`, the call:

1. Writes an auditable `'blocked'` row to `tblBillingAttempts` (so a
   would-be-live attempt is never silent), then
2. Throws `BillingModeException` — every wired caller already wraps this in
   its own try/catch, so the caller gets its normal `['success' => false,
   ...]` failure response. **No provider HTTP call is ever reached.**

When the guard is OFF, or the resolved mode is anything other than the
literal string `'live'` (the normal sandbox/test-mode path), the check
returns silently with zero extra overhead.

This class **never flips the guard itself** — turning it off is a
deliberate, user-gated, two-step act (see **GitHub issue #70** — "Configure
Live Payment Provider Credentials").

### Per-Provider Mode Settings

Each payment provider resolves its own mode independently from `tblSettings`
(category `payment`) — an unrecognised/missing value fails safe towards
"treat as not-live", never towards "treat as live":

| Setting | Values | Default | Provider |
|---|---|---|---|
| `payment.paypal.mode` | `sandbox` \| `live` | `sandbox` | PayPal (Orders + Subscriptions APIs) |
| `payment.stripe.mode` | `test` \| `live` | `test` | Stripe (Checkout Sessions, Billing) |
| `payment.coinbase.mode` | `sandbox` \| `live` | `sandbox` | Coinbase Commerce (crypto charges) |

Different providers historically use different vocabulary for "not live"
(`sandbox` vs `test`) — `BillingMode` only ever treats the literal string
`live` as dangerous; anything else (including a typo) is treated as safe.

### The EXACT Go-Live Step (GitHub issue #70)

To accept **REAL payments in production**, you must do **BOTH** of the
following — setting only the credentials, or only the guard, is not enough:

1. **Set the live provider credentials** for whichever provider(s) you are
   enabling (PayPal live client ID/secret, Stripe live secret/publishable
   keys, Coinbase live API key — stored encrypted in `tblSettings`,
   `isSensitive = 1`, per this document's general credential-storage rules),
   **and set that provider's own `payment.<provider>.mode` to `live`**.
2. **Turn `billing.test_mode_guard` OFF** — via the backend database, not a
   CLI:

   > **Backend DB → tblSettings → key `billing.test_mode_guard` → value `0`
   > → Save.**
   > (Admin UI path: **Global Admin → Settings → `billing` category →
   > `billing.test_mode_guard` → Save**, the same generic settings editor
   > used for every other `tblSettings`-backed feature in this document.)

   ```sql
   -- Turn OFF the TEST-MODE guard (run ONLY after live credentials are set
   -- AND you have verified the engine end-to-end in sandbox/test mode):
   UPDATE tblSettings SET settingValue = '0' WHERE settingKey = 'billing.test_mode_guard';
   ```

   Verify:

   ```sql
   SELECT settingValue FROM tblSettings WHERE settingKey = 'billing.test_mode_guard';
   -- Must show '0' before ANY provider will accept a live-mode call
   ```

**⚠️ WARNING:** turning the guard OFF enables REAL charges, REAL
subscriptions, and REAL refunds to flow through whichever provider(s) you
have set to `live` mode. Only do this after you have exercised the full
signup → renewal → dunning → cancellation lifecycle against each provider's
own sandbox/test mode and are confident in the result. Leave the guard ON
for every provider you have not yet finished verifying — the guard is
evaluated per-call against that call's own resolved provider mode, so PayPal
can safely go live while Stripe and Coinbase remain sandboxed, for example.

### Dunning Ladder + Grace Period Settings

When a recurring charge fails, the subscription enters `past_due` and a
configurable retry ladder runs before the account is suspended:

| Setting | Meaning | Default |
|---|---|---|
| `billing.dunning.retry_days` | CSV of days between retry rungs, chained from the moment the subscription first enters `past_due` (e.g. `1,3,5,7` — rung 1 fires 1 day after `past_due`, rung 2 three days after rung 1, etc.) | `1,3,5,7` |
| `billing.dunning.max_retries` | Number of retry rungs before the ladder is EXHAUSTED and the subscription moves `past_due` → `grace`. Should normally match the entry count in `billing.dunning.retry_days`. | `4` |
| `billing.grace_period_days` | How long a subscription sits in `grace` (still nominally usable, final-warning state) before the scheduled `dunning_final_cancel` task actually cancels it. | `7` |

Every retry rung, the grace transition, and the final cancellation are all
scheduled/executed by `BillingScheduler` and audited to `tblBillingAttempts`.

**Provider-managed subscriptions renew via webhooks, not this ladder's own
collection attempt.** `BillingScheduler`'s dunning-retry task does **not**
"pull a stored card on a timer" — G-002's recurring-billing model is
explicitly provider-managed (PayPal Subscriptions / Stripe Billing own the
actual recurring charge and their own retry schedule). Each dunning tick's
job is bookkeeping — email the user, advance the ladder, eventually move to
`grace`/cancel — while the REAL resolution normally arrives independently as
a provider webhook (`PAYMENT.SALE.COMPLETED` for PayPal,
`invoice.paid`/`invoice.payment_succeeded` for Stripe) routed through
`PaymentManager::applyWebhookTransition()` / `::recordSubscriptionRenewal()`,
which resolves the local subscription via
`tblSubscriptions.paymentProviderSubscriptionID` and moves it back to
`active` — independently of, and often before, the next dunning tick fires.

---

## 🛡️ Data-Protection / Compliance (G-004) — in progress

Multi-jurisdiction compliance tooling is being built in layers. **Layer 1a
(consent foundation)** is shipped; the DSAR fulfilment engine, admin queue,
data-driven regime model, and breach/RoPA/retention machinery follow in later
layers. This section documents the security-relevant parts as they land.

### The one hard rule (design invariant)

Every jurisdiction-specific value — a right, an SLA in days, an age threshold,
a breach-notification window — lives in **DB configuration**, never in PHP.
Adding a new regime is a data change, not a code change. As of L1a there is
**zero hardcoded regime or legal value** anywhere in `web/private_html`
(grep-enforced each cycle). PHP knows only the *shape* of a regime, never the
*values* for a named country. **All legal/policy text ships empty or as an
explicitly-labelled draft — it is never fabricated by the build; the operator
and counsel supply the wording.**

### Consent audit trail (`tblConsentRecords` / `ConsentManager`)

- **Append-only.** A consent decision is *never* updated to flip it — every
  grant/withdraw is a new immutable row, so the table is its own full audit
  history. "Current state" is just "the newest row per consent type." A
  regulator asking "prove this user consented, and when" gets a complete trail.
- **PII minimisation.** The captured IP is stored **packed** as `VARBINARY(16)`
  via `inet_pton()` (no plaintext address, no derived geo); the user agent is
  truncated. A malformed IP degrades to `NULL` rather than failing the capture.
- **Tamper-evidence.** When `consent.proof_hash.enabled` is on (default), each
  row carries a SHA-256 `proofHash` over
  `consentType | granted | policyVersion | timestamp | packedIP | userAgent`,
  re-derivable by an auditor to detect a modified row.
- **Auditing.** Every decision is also written to `tblActivityLog` under the
  new `privacy` category (migration 040 widened the category ENUM to include it).
- **User surface.** `settings/privacy.php` exposes a server-persisted consent
  toggle + read-only "My Consent History". The action is CSRF-verified, validates
  the consent type against a **server-authoritative allowlist** (a form can never
  write an arbitrary consent type into the trail), scopes all rows to the
  session `userID` (no IDOR), escapes all output, and works with JavaScript off.

### DSAR engine (`tblDataSubjectRequests` / `DSARManager`) — L1b

- **Delegation, not duplication.** `DSARManager` fulfils portability/access via
  `AccountManager::exportUserData()` and erasure via
  `AccountManager::requestAccountDeletion()` (the existing grace-period cron does
  the actual erasure) — there is exactly one export/delete implementation, so
  the two can never drift. Rectification writes only an explicit column allowlist.
- **Guarded lifecycle.** A request moves through a fixed state machine; an
  illegal transition is audited (`tblActivityLog`, `privacy`, `warning`) and then
  rejected. Every status change appends an immutable `tblDataSubjectRequestEvents`
  row.
- **Identity verification.** For a request that needs it, a random token is
  issued but only its **SHA-256 hash** is stored (never the raw token, mirroring
  the data-export download-token lesson); verification is a timing-safe
  `hash_equals` with a TTL. Public/email-channel requests must never reveal
  whether an email matches an account (uniform response) — enforced in the L1c
  public form.
- **User surface.** `settings/privacy.php` exposes a submission-only DSAR form
  (CSRF-verified, server-authoritative type allowlist, `userID` from session — no
  IDOR, no JS required). Fulfilment happens from the admin queue (L1c).

### Erasure-safety contract — implemented (L1b)

Neither `tblConsentRecords` nor `tblDataSubjectRequests` uses
`ON DELETE CASCADE` to `tblUsers`. On permanent account erasure the PII in
these rows is **anonymised** (`userID`/IP/UA/`requesterEmail` nulled/redacted),
**not deleted** — the *fact* of consent and the *fact* of a fulfilled request
survive as an anonymised compliance record even after the account is gone, while
the personal data does not. This is enforced by an additive block inside
`AccountManager::permanentlyDeleteAccount()`, in the **same transaction** as the
existing `tblActivityLog` anonymisation.

### Admin DSAR queue + public request form — L1c (Layer 1 complete)

- **Admin queue** (`/admin/compliance/dsar`) and its AJAX endpoint are gated to
  **super-admin** (`AccessControl::requireSuperAdmin()` / `isSuperAdmin` check)
  before any output — compliance data is higher-sensitivity than ordinary app
  data. Every mutation is **POST-only + CSRF-verified**, sets
  `X-Frame-Options: DENY`, and is recorded via `logAdminAction()` (audit of the
  auditors). Detail responses strip the packed IP and the hashed verification
  token; all HTML output is `htmlspecialchars`-escaped and packed IPs are shown
  via `inet_ntop`.
- **No admin password bypass.** Export/erasure fulfilment verifies the *data
  subject's own* password (that check is what makes those actions safe). The
  admin queue therefore does **not** fabricate or substitute a password and does
  **not** drive those delegators — an admin can track/route a request, but only
  the subject can complete an export/erasure, from their own Settings → Privacy.
- **Public data-request form** (`/legal/data-request`) for locked-out users is
  protected by CAPTCHA + anti-bot form protection + rate-limiting, and is
  **user-enumeration-safe**: the response is byte-identical whether or not the
  submitted email matches an account (a single uniform "if an account exists,
  we've emailed you" message, enforced by construction in `DataRequestIntake`).
  Verification uses the same SHA-256-hashed-token flow as the rest of the DSAR
  engine; the raw token is emailed once and never stored, logged, or returned.
  > **Residual (low):** the anti-enumeration is content-uniform but not yet
  > *timing*-uniform (the matched-account path does more work). Tracked as B-080;
  > mitigate by moving that work async or adding compensating delay if a
  > timing-based enumeration threat is in scope for your deployment.

### Consent-management surface — L2 (`ConsentCategoryService`)

- **Server is the source of truth.** Banner/preference choices persist as
  append-only `ConsentManager` rows (the old cookie banner was `localStorage`
  only). Categories are data-driven from `tblConsentCategories`; the
  **strictly-necessary** category is forced granted **server-side** in
  `recordBannerChoices()` — a tampered request that tries to opt it out cannot.
- **Anonymous identity.** A first-party `SIGNULA_VID` UUID-v4 cookie (SameSite=Lax;
  intentionally *not* httponly so the progressive-enhancement banner JS can read
  it — it is not a security boundary) lets pre-login consent be recorded and
  later reconciled to the user on login (`reconcileVisitorToUser`).
- **Global Privacy Control.** A `Sec-GPC: 1` request auto-records a
  `do_not_sell` opt-out (`mechanism='gpc_signal'`) when `consent.gpc.honor` is on.
  The hook lives in the shared bootstrap but is **cost- and spam-guarded**: a
  request with no GPC header costs one array lookup (no class load, no DB); when
  present it records **at most once per session** (`$_SESSION['gpc_honored']`) and
  never a duplicate (a `getCurrent()` idempotency check precedes any insert).
- **Versioned re-consent** (`consent.reconsent.enforce`, **default OFF**). When
  enabled, a user whose latest `terms`/`privacy` consent version is stale is sent
  to a re-consent interstitial after full authentication (never mid-MFA). The gate
  is evaluated **before any DB access**, so with the shipped default it is inert.
- **No fabricated legal text.** `tblPolicyVersions` carries version anchors only;
  disclosure bodies ship empty. All consent UI copy is functional, not statutory.
- Every state-changing consent POST is CSRF-verified and works without JavaScript.

### Data-driven regime model — L3 (`RegimeResolver` + regime admin)

- **No jurisdiction value is hardcoded.** Every regime's SLA, age threshold,
  breach window and rights live in `tblComplianceRegimes` / child tables; PHP
  reads rows. A grep-gate enforces that no regime-code literal (`GDPR`, `CCPA`, …)
  appears in `web/private_html` PHP logic. Adding a jurisdiction is data, not code.
- **Never fails open.** `RegimeResolver` resolves the governing regime (active,
  non-draft rows only) or a synthetic **most-protective default** — an unknown or
  NULL value always yields the strictest fallback (`dsar.default_sla_days`, the
  strictest age), never a permissive one.
- **Ships inert.** All 19 seeded regimes are `isActive=0` with NULL legal values,
  so the resolver returns the default and the DSAR/consent retro-wire is a no-op
  until an operator activates a regime — existing behaviour is unchanged.
- **Regime admin** (`/admin/compliance/regimes`) is super-admin gated, CSRF +
  POST-only, `X-Frame-Options: DENY`, audit-logged. The **activation guard**
  re-reads the stored row and refuses to set a regime active until `dsarSlaDays`,
  `minAge` and `breachNotifyHours` are all non-NULL — a jurisdiction cannot go live
  with blank legal values, and a client cannot bypass the check with forged input.
- **No fabricated legal values or disclosure text** — the admin forms accept the
  operator's/counsel's input; disclosure bodies ship empty; nothing is pre-filled.

### Retention + the compliance cron — L4a (`RetentionManager` + `cron/compliance.php`)

> **Operator action required to enable:** set `compliance.cron.secret_token` to a
> strong random value (Repository → Settings, or your DB settings UI — it is stored
> **encrypted**), then point your external scheduler at `/cron/compliance.php?token=…`
> (or send it as `Authorization: Bearer …`). Until the token is set the cron refuses
> to run. This ties to the out-of-scope cron item (#85).

- **The cron is token-gated and fails closed.** The endpoint reads the
  `isSensitive` token via `getSetting()` (decrypted — never a raw `SELECT`, which
  would compare against ciphertext), and rejects the request (401) unless a provided
  `?token=`/Bearer value matches via constant-time `hash_equals`. **An empty/unset
  expected token never matches** — an unconfigured cron can't be triggered. Failed
  attempts are logged. No real or guessable token value ships in code (it's empty).
- **What it does:** runs the previously-dormant `AccountManager::processScheduledDeletions()`
  and `cleanupExpiredExports()` (closing a real gap — nothing else called them),
  expires overdue data-request verification tokens, and applies retention policies.
- **Retention purging is safe-by-default — three independent gates.** Nothing purges
  user data unless the operator (1) fills in and **activates** a policy, (2) sets
  `retention.purge.enabled=1`, AND (3) sets `retention.purge.dry_run=0`. All three
  ship in the safe position (policies inactive, purge disabled, dry-run on). In
  `RetentionManager`, **"disabled" always wins** over a mistakenly-flipped dry-run
  flag, the non-live path changes nothing, purges are **batch-capped**, and
  **anonymise is preferred over delete**.
- **No identifier injection.** The target table, date column and anonymise columns a
  policy may touch are validated by **exact match against a hardcoded allowlist
  const** (not DB-editable); only those literal identifiers are ever interpolated,
  and all values are bound parameters. A policy naming an un-allowlisted table is
  skipped, logged, and never executed.

### Settings added (all `privacy` category, non-sensitive)

`dsar.default_sla_days` (30), `dsar.identity_verification.required` (true),
`dsar.identity_token.ttl_minutes` (30), `dsar.admin.notify_on_new` (true),
`dsar.due_soon.warn_days` (7), `consent.proof_hash.enabled` (true). Per-regime
overrides of the SLA/age/breach values arrive with the regime model (a later
layer) and always fall back to the most-protective default — the resolver never
fails open.

### Breach notifications, RoPA register, COPPA age-gate — L4b (`BreachManager` /
`AgeGateService`) — **G-004 is now fully BUILT (all four layers shipped)**

- **Breach deadlines are always resolver-driven, never a hardcoded window.**
  `BreachManager::computeNotificationDeadlines()` computes
  `dueAt = detectedAt + RegimeResolver::breachWindowHours(regimeCode)` hours for
  every (regime, audience) pair on an incident. A regime with no configured window
  (the shipped default — all 19 seeded regimes are inactive) degrades gracefully:
  `dueAt` stays `NULL`, status stays `pending`, and an explanatory note is written
  — it never crashes and never guesses a number. Recomputing an incident's
  deadlines never resets an already-`sent` notification back to `pending`.
- **Machinery only — this tool never files anything with a regulator.** Breach
  `summary`/`remediation`, and every notification's filing `note`, are always
  exactly what the operator/DPO typed — nothing is templated or auto-generated.
  The tool computes deadlines and tracks status; the decision to notify, and the
  notification content, remain entirely an operator/legal judgement call.
- **Breach admin** (`/admin/compliance/breaches`) is super-admin gated, CSRF +
  POST-only, `X-Frame-Options: DENY`, audit-logged — same chrome as every other
  compliance admin surface.
- **The RoPA register ships with zero fabricated content.** `/admin/compliance/ropa`
  is pure CRUD over `tblProcessingActivities` — purpose, lawful basis, data
  categories, recipients, and retention period are entered by you or your DPO;
  the migration seeds no rows and the admin page never pre-fills a value.
- **The signup age-gate is OFF by default and inert until enabled.**
  `compliance.age_gate.enabled` ships `'0'` — with the gate off, `register.php`
  never reads or requires a date of birth, and behaves byte-for-byte as before
  this release (this is what keeps the existing registration test baseline
  green). An operator must explicitly enable the setting (after confirming the
  applicable minimum age with legal, via `/admin/compliance/regimes`) before any
  signup is age-gated. The threshold itself always comes from
  `RegimeResolver::minAgeFor()` — never a hardcoded number.
- **Parental-consent tokens follow the same hash-only pattern as DSAR identity
  verification.** `AgeGateService::startParentalConsent()` stores **only the
  SHA-256 hash** of the emailed verification token in `tblParentalConsents` — the
  raw value is never persisted or logged, only emailed once to the parent/guardian.
  Verification is a timing-safe (`hash_equals`) comparison with a TTL
  (`compliance.parental_consent.token_ttl_hours`, default 72h) and fails closed
  (a single generic "invalid or expired link" outcome) for every rejection reason.
  The verification **method's legal sufficiency under COPPA is left to the
  operator/legal team** — this scaffold provides the email-link mechanism only.

### Settings added (Layer 4b, `privacy` category, non-sensitive unless noted)

`compliance.age_gate.enabled` (`'0'`/OFF by default),
`compliance.parental_consent.token_ttl_hours` (`72`).
