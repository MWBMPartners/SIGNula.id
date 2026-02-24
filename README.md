# SIGNula.ID - Universal Login System

![Version](https://img.shields.io/badge/version-2.6.0--beta-blue)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php)
![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?logo=mysql)
![License](https://img.shields.io/badge/license-Proprietary-red)
![Security](https://img.shields.io/badge/security-100%25-brightgreen)

## 📋 Overview

**SIGNula** is a comprehensive, universal single sign-on (SSO) authentication system designed to provide seamless user authentication across multiple web and mobile applications. Built with security, scalability, and user experience as top priorities, SIGNula offers a modern authentication solution for today's interconnected digital ecosystem.

**Current Status:** Core Platform Complete ✅ | Security 100% ✅ | Webhooks ✅ | Payments 100% ✅ | Two-Tier Payments ✅ | Ko-fi & Patreon ✅ | Avatar System ✅ | Credential Reset ✅

**Latest Version:** 2.6.0-beta (February 24, 2026)

**Completed Phases:**

- ✅ Phase 1-3: Core Authentication & API (100%)
- ✅ Phase 3.4: Security Enhancements - Rate Limiting & API Keys (100%)
- ✅ Phase 3.5: Multi-Tier Admin System (100%)
- ✅ Phase 3.6: Webhook Signatures, Payment System & Deployment Prep (100%)
- ✅ Phase 3.7: Payment Provider Integration — Stripe, PayPal, Coinbase Commerce (100%)
- ✅ Phase 3.8: Two-Tier Payment System Expansion — Invoices, Credits, Service Fees, Billing (100%)
- ✅ Phase 3.9: Ko-fi & Patreon Payment Providers + Testing Documentation (100%)
- ✅ Phase 4: Public Web Interface (100%)
- ✅ Phase 5: Organization Management (100%)
- ✅ Phase 6: Support Ticket System (100%)
- ✅ Phase 7: Admin Dashboard - Email (100%)
- ✅ Phase 9: Complete Admin Dashboard — 35+ pages (100%)
- ✅ Phase 10: Security Hardening — 7-Layer Protection (100%)
- ✅ Phase 11: Avatar Management System (100%)
- ✅ Phase 12: Mass Credential Reset & Email Enhancement (100%)
- ✅ Phase 13: Security Audit & Issue Tracking (100%)

**In Progress:**

- 🟡 Testing & QA (73%) — 342 unit tests, 1105 assertions
- 🟡 Deploy migrations 007-017 to production
- 🟡 Configure live payment credentials
- 🟡 DIRECTORY_SEPARATOR compliance across all PHP files

### ✨ Key Features

- **🔐 Multi-Factor Authentication (MFA)**
  - TOTP authenticator app support (Google Authenticator, Microsoft Authenticator)
  - Email-based OTP verification
  - SMS verification (configurable)
  - Passwordless push notifications
  - Backup recovery codes

- **🔗 Third-Party Account Integration**
  - Google Account (Personal & Workspace)
  - Microsoft Account (Personal & Microsoft 365)
  - Apple ID
  - Facebook/Instagram (Meta)
  - LinkedIn
  - LastPass
  - Yahoo!
  - WordPress
  - Amazon
  - PayPal
  - OpenID

- **🔑 Advanced Authentication Options** ✅
  - ✅ **WebAuthn/FIDO2 PassKeys** - Fully implemented biometric authentication
    - Platform authenticators (TouchID, FaceID, Windows Hello)
    - Cross-platform authenticators (security keys)
    - Credential management (rename, revoke, usage tracking)
  - ✅ **Passwordless Email Login** - Secure magic link authentication
    - Token-based email links (15-minute expiry)
    - Rate limiting (per email and per IP)
    - SHA-256 token hashing
  - ✅ Traditional password authentication with Argon2id hashing
  - ✅ Account recovery mechanisms
  - ✅ Challenge-response authentication (WebAuthn ceremonies)

- **🌐 RESTful API** ✅
  - Secure JSON-based API for service integration
  - OAuth 2.0 support
  - 31+ documented endpoints
  - Rate limiting and throttling
  - **Comprehensive partner documentation** (Markdown + Interactive HTML)
  - Authentication methods: API Key, Bearer Token, Session
  - Webhook support with 10 event types

- **📧 Delegate Email Sending** ✅
  - Send emails from user's Microsoft 365 or Google Workspace mailboxes
  - **FREE shared mailbox support** (saves $600+/year)
  - Dual-mode authentication (application + delegated)
  - Automatic OAuth token refresh
  - Secure encrypted token storage
  - User interface for managing email accounts

- **💳 Payment & Subscription Management** ✅
  - Multiple tier support (Free, Basic, Premium, Enterprise)
  - **Stripe** (Card, Link, Apple Pay, Google Pay) via Checkout Sessions
  - **PayPal** (REST API v2, Orders, Subscriptions)
  - **Coinbase Commerce** (BTC, ETH, USDT, USDC) with configurable crypto discount
  - **Ko-fi** (Donations, Subscriptions, Shop Orders) via webhook integration
  - **Patreon** (Pledge management, tier mapping) via OAuth 2.0 API v2
  - Public pricing page with monthly/yearly toggle and discount codes
  - Checkout flow with provider selection and order summary
  - Inbound webhook receivers with signature verification (HMAC-SHA256)
  - Admin provider configuration UI with test connection and webhook logs
  - Subscription management and billing

- **💰 Two-Tier Payment System** ✅ (v2.4.0)
  - **Level 1**: Partners/customers pay SIGNula directly for premium tiers
  - **Level 2a**: Partners use their OWN payment provider API keys (~10% service fee)
  - **Level 2b**: Partners use SIGNula's keys (~30% fee, remainder remitted)
  - Invoice generation with PDF support (TCPDF, HTML fallback)
  - Credit balance system with row-level locking
  - Service fee management with 30-day minimum notice for changes
  - Three-tier billing redundancy (MySQL events + web cron + lazy check safety net)
  - Auto-suspension/auto-resume on payment status changes
  - Partner earnings dashboard and payout management
  - 6 super admin pages + 6 partner admin pages for payment management

- **🛡️ Enterprise-Grade Security** ✅ (7-Layer Protection)
  - **Rate Limiting System**
    - Token bucket algorithm with progressive blocking
    - Multi-tier support (IP, User, API Key)
    - Per-endpoint limits with burst protection
    - Admin monitoring dashboard with one-click unblock
  - **API Key Management**
    - SHA-256 secure key hashing
    - Test/Live environment separation
    - IP whitelisting with CIDR support
    - Self-service management for partners
  - **7-Layer Security Pipeline** (v2.6.0)
    - Layer 1: Form Protection (honeypot, HMAC timing, JS challenge)
    - Layer 2: CAPTCHA (CloudFlare Turnstile, reCAPTCHA v3, fail-open)
    - Layer 3: IP Reputation (AbuseIPDB, proxycheck.io, circuit breaker)
    - Layer 4: Bot Detection (CrawlerDetect library + regex fallback)
    - Layer 5: Session Fingerprinting (SHA-256 IP/UA/header binding)
    - Layer 6: Security Alerts (brute force, impossible travel, password spray)
    - Layer 7: SecurityMiddleware pipeline orchestrator
  - **Mass Credential Reset** (v2.6.0)
    - 3 reset types: mass password reset, salt rotation, full credential reset
    - 3 scopes: all users, filtered, specific users
    - AJAX batch processing with real-time progress
    - SHA-256 hashed token storage
    - Compliance reporting dashboard
  - AES-256-CBC encryption for sensitive data
  - Argon2id password hashing
  - CSRF protection with `FormProtection` class
  - Brute force protection
  - Comprehensive activity and audit logging
  - **Security Score: 100%**

- **🖼️ Avatar Management System** ✅ (v2.6.0)
  - Priority chain: partner upload > global upload > OAuth > fallback services > SVG initials
  - 5 fallback services: Gravatar, Libravatar, UI Avatars, DiceBear, RoboHash
  - User-configurable priority via preferences
  - UUID-based serve endpoint (prevents user ID enumeration)
  - Intelligent caching with automatic invalidation

- **🏢 Multi-Tier Admin System** ✅
  - **Three Admin Levels:** Super Admin, Root Admin, Team Members
  - **6-Tier Role Hierarchy:** super-admin, root-admin, admin, developer, support, finance
  - **Feature Toggle System**
    - Global enable/disable by super admins
    - Per-partner overrides
    - Partner control permissions (can partners toggle?)
  - **Team Management**
    - Email-based invitations (secure tokens, 7-day expiry)
    - Role assignment and management
    - Ownership transfer with multi-step confirmation
  - **Complete Partner Isolation** (multi-tenancy ready)
  - **Database Triggers** enforce ONE root admin per partner
  - **Full Audit Trail** for all admin actions
  - **All UI** — zero command-line required

- **🖥️ Complete Admin Dashboard** ✅
  - **User Management Interface**
    - Search, filter, and paginate users
    - Status management (active/inactive/locked)
    - Tier changes (Free/Basic/Premium/Enterprise)
    - Password reset and account unlock
    - Detailed user information modals
  - **System Settings Management**
    - 7 category tabs (General, Security, Email, Authentication, API, Payment, Advanced)
    - Inline editing for all settings
    - Add/delete settings via UI
    - Sensitive value masking with reveal
  - **OAuth Provider Configuration**
    - 9 provider cards (Google, Microsoft, Apple, Facebook, LinkedIn, GitHub, Twitter, Yahoo, PayPal)
    - Per-provider configuration modals
    - Test connection functionality
    - Enable/disable providers
  - **System Logs Viewer**
    - 3-tab interface (Activity, Error, Audit)
    - Advanced filtering and search
    - Auto-refresh (30-second intervals)
    - Export to CSV/JSON
    - Expandable detail rows

- **📱 Responsive Design**
  - Mobile-first approach
  - PWA support
  - WCAG 2.1 accessibility compliance
  - Multi-language support (i18n ready)

## 🏗️ Technology Stack

- **Backend:** PHP 8.3+ (targeting 8.4)
- **Database:** MySQL 8.0+ / MariaDB 10.5+
- **Database Interface:** MySQLi with prepared statements
- **Frontend:** HTML5, CSS3, JavaScript
- **Frameworks:** Bootstrap 5.3.2
- **Libraries:** Font Awesome 6.4.2, jQuery
- **PDF Generation:** TCPDF (self-hosted, no Composer required)
- **Security:** OpenSSL, password_hash with Argon2id

## 📁 Project Structure

```
SIGNula.id/
├── _config/                 # Configuration files
│   ├── config.php          # Main configuration bootstrap
│   └── database.php        # Database connection handler
├── _backend/               # Backend classes (outside web root)
│   ├── AccessControl.php   # Role-based access control (RBAC)
│   ├── Database.php        # Database connection handler
│   ├── SessionManager.php  # Session management
│   ├── ActivityLogger.php  # Activity logging
│   ├── RateLimiter.php     # Rate limiting engine
│   ├── APIKeyManager.php   # API key management
│   ├── RateLimitMiddleware.php  # Rate limit middleware
│   └── APIKeyMiddleware.php     # API key auth middleware
├── _includes/              # Reusable PHP components
│   ├── auth/               # Authentication classes
│   ├── layout/             # Layout components
│   ├── security/           # Security utilities
│   ├── email/              # Email services
│   └── utils/              # Utility classes
├── _database/              # Database files (outside web root)
│   ├── migrations/         # Database migrations (001-012)
│   ├── archive/            # Deprecated schema files
│   └── signula_complete_install_v2.2.3.sql  # Complete install (migrations 001-009)
├── _docs/                  # Documentation
│   ├── MULTI_TIER_ADMIN_IMPLEMENTATION.md
│   ├── DEPLOYMENT_GUIDE.md
│   ├── SECURITY_TESTING_GUIDE.md
│   └── SECURITY_DEPLOYMENT_GUIDE.md
├── _scripts/               # Build and utility scripts
├── _tests/                 # Test files
├── _private/               # Private files (outside web root)
├── public_html/            # Public web directory
│   ├── assets/             # Static assets (css, js, images)
│   ├── api/                # RESTful API endpoints (31+)
│   ├── auth/               # Authentication pages
│   ├── settings/           # Account settings (8 pages)
│   ├── admin/              # Admin dashboard
│   │   ├── index.php       # Admin dashboard (main)
│   │   ├── users/          # User management
│   │   │   └── index.php   # User management interface
│   │   ├── settings/       # System settings
│   │   │   ├── index.php   # Settings management
│   │   │   └── oauth.php   # OAuth provider configuration
│   │   ├── logs/           # System logs
│   │   │   └── index.php   # Logs viewer (Activity/Error/Audit)
│   │   ├── features/       # Feature toggle management
│   │   │   └── global.php  # Super admin feature toggles
│   │   ├── partners/       # Partner management
│   │   │   └── list.php    # Admin partner management
│   │   ├── security/       # Security monitoring
│   │   │   └── rate-limits.php  # Rate limit dashboard
│   │   ├── system/         # System management
│   │   │   ├── health.php  # System health dashboard
│   │   │   ├── migrations.php  # Migration deployment
│   │   │   └── admin-migration.php  # Admin migration tool
│   │   └── api/            # Admin APIs
│   │       ├── deploy-migration.php
│   │       ├── feature-actions.php
│   │       ├── user-actions.php      # User management API
│   │       ├── settings-actions.php  # Settings management API
│   │       └── provider-actions.php  # Payment provider config API
│   ├── pricing/            # Public pricing page
│   │   └── index.php       # Tier comparison with monthly/yearly toggle
│   ├── checkout/            # Checkout flow
│   │   ├── index.php       # Checkout page (provider selection, order summary)
│   │   ├── process.php     # Payment processor (routes to provider)
│   │   ├── success.php     # Payment confirmation
│   │   └── cancel.php      # Payment cancellation
│   ├── webhooks/            # Inbound webhook receivers
│   │   ├── stripe.php      # Stripe webhook endpoint
│   │   ├── paypal.php      # PayPal webhook endpoint
│   │   └── coinbase.php    # Coinbase Commerce webhook endpoint
│   └── partners/           # Partner portal
│       ├── register.php    # Partner registration
│       ├── dashboard.php   # Partner dashboard
│       ├── api-keys.php    # API key management
│       ├── accept-invite.php  # Accept team invitation
│       ├── admin/          # Partner admin panel
│       │   ├── index.php   # Partner admin dashboard
│       │   ├── team.php    # Team management
│       │   ├── features.php  # Feature toggles
│       │   └── transfer-ownership.php  # Ownership transfer
│       └── api/            # Partner APIs
│           ├── team-actions.php
│           └── partner-feature-actions.php
├── alpha_html/             # Alpha version directory
├── beta_html/              # Beta version directory
├── .gitignore
├── README.md
├── CHANGELOG.md
└── PROJECT_PROGRESS.md     # Development roadmap
```

## 🚀 Installation & Setup

### Prerequisites

- PHP 8.3 or higher
- MySQL 8.0+ or MariaDB 10.5+
- Web server (Apache/Nginx) with mod_rewrite enabled
- SSL certificate (required for production)

### Step 1: Clone Repository

```bash
git clone https://github.com/MWBMPartners/SIGNula.id.git
cd SIGNula.id
```

### Step 2: Database Setup

**Option A: Complete Installation (Recommended for new installations) ⭐**

Use the comprehensive installation script that includes everything through v2.2.3-beta:

```bash
# Single command installs complete database with all features
mysql -u your_username -p < _database/signula_complete_install_v2.2.3.sql
```

This creates the `signula` database with:
- ✅ Core authentication system & MFA
- ✅ OAuth integration (Google, Microsoft, Apple, etc.)
- ✅ Email system with templates, tracking, campaigns
- ✅ WebAuthn/PassKeys & passwordless login
- ✅ Blog/news system & support tickets
- ✅ Rate limiting & API key management
- ✅ Multi-tier admin system (RBAC, feature toggles, triggers)
- ✅ All default settings and configurations

**Option B: Manual Migration (For upgrading existing installations)**

If you already have a SIGNula installation and need to apply new migrations:

```bash
# Apply individual migrations as needed (in order)
mysql -u your_username -p signula < _database/migrations/007_rate_limiting.sql
mysql -u your_username -p signula < _database/migrations/008_partner_api_keys.sql
mysql -u your_username -p signula < _database/migrations/009_multi_tier_admin.sql
mysql -u your_username -p signula < _database/migrations/010_webhooks_and_payments.sql
mysql -u your_username -p signula < _database/migrations/011_payment_providers.sql
mysql -u your_username -p signula < _database/migrations/012_payment_expansion.sql
```

**Option C: Deploy via Admin UI (Recommended for existing installations)**

If your SIGNula instance is running, deploy migrations via the admin dashboard:
1. Log in as an admin user
2. Navigate to `/admin/system/migrations.php`
3. Click "Deploy Migration" for each pending migration
4. Verify success via real-time progress tracking

See [_docs/DEPLOYMENT_GUIDE.md](_docs/DEPLOYMENT_GUIDE.md) for comprehensive deployment and testing guide.

**Documentation:** All database files are in [_database/](_database/) with migrations in [_database/migrations/](_database/migrations/).

3. Verify setup:

```bash
php _tests/verify-phase1-setup.php
```

### Step 3: WebAuthn/PassKey Configuration

Configure WebAuthn settings in `tblSettings`:

```sql
-- Set your domain for WebAuthn
UPDATE tblSettings SET settingValue = 'yourdomain.com' WHERE settingKey = 'auth.webauthn.rp_id';

-- Set relying party name
UPDATE tblSettings SET settingValue = 'SIGNula' WHERE settingKey = 'auth.webauthn.rp_name';

-- Enable WebAuthn
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'auth.webauthn.enabled';

-- Enable passwordless login
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'auth.passwordless.enabled';
```

### Step 4: Configuration

1. Copy the authentication template:

```bash
cp _private/auth.php.example _private/auth.php
```

2. Edit `_private/auth.php` with your database credentials:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_database_username');
define('DB_PASS', 'your_secure_password');
define('DB_NAME', 'signula_db');
```

3. Generate encryption keys:

```bash
# Generate encryption key
openssl rand -base64 32

# Generate salt
openssl rand -hex 16
```

4. Update `ENCRYPTION_KEY` and `ENCRYPTION_SALT` in `_private/auth.php`

### Step 5: File Permissions

```bash
chmod 600 _private/auth.php
chmod 755 _private/logs
chmod 755 _private/backups
chmod 755 public_html
```

### Step 6: Web Server Configuration

#### Apache (.htaccess)

Create `.htaccess` in `public_html/`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?route=$1 [QSA,L]

# Security headers
Header set X-Frame-Options "SAMEORIGIN"
Header set X-Content-Type-Options "nosniff"
Header set X-XSS-Protection "1; mode=block"
```

#### Nginx

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

### Step 7: Initial Settings

Update settings in the database `tblSettings` table or via the admin interface (once created):

- Email SMTP configuration
- OAuth credentials (Google, Microsoft, etc.)
- Captcha keys (reCAPTCHA or Cloudflare Turnstile)
- Payment gateway credentials
- WebAuthn/PassKey settings (RP ID, RP Name)

## ✨ Phase 1 Features (WebAuthn & Passwordless Auth)

### 🔑 WebAuthn/PassKeys

SIGNula implements the FIDO2/WebAuthn standard for passwordless authentication:

- **Registration Flow:** Users can register biometric credentials (TouchID, FaceID, Windows Hello) or security keys
- **Authentication Flow:** Login with a simple biometric gesture or security key tap
- **Credential Management:** Rename, revoke, and track usage of PassKeys
- **Multi-Device Support:** Register multiple authenticators for flexibility
- **Security:** Challenge-response protocol with public key cryptography

**User Pages:**
- [/auth/passkey-register](public_html/auth/passkey-register.php) - Register new PassKey
- [/auth/passkey-login](public_html/auth/passkey-login.php) - Login with PassKey
- [/settings/passkeys](public_html/settings/passkeys.php) - Manage PassKeys

**API Endpoints:**
- `/api/webauthn/register-options.php` - Get registration challenge
- `/api/webauthn/register-verify.php` - Verify and store credential
- `/api/webauthn/auth-options.php` - Get authentication challenge
- `/api/webauthn/auth-verify.php` - Verify authentication and login

### 📧 Passwordless Email Login

Secure magic link authentication via email:

- **Token Generation:** 64-character cryptographically secure tokens
- **SHA-256 Hashing:** Tokens hashed before database storage
- **Expiration:** 15-minute validity (configurable)
- **Rate Limiting:**
  - 5 requests per email per hour
  - 10 requests per IP per hour
- **Single Use:** Tokens invalidated after use

**User Pages:**
- [/auth/passwordless-request](public_html/auth/passwordless-request.php) - Request magic link
- [/auth/passwordless-login](public_html/auth/passwordless-login.php) - Verify and login

### 🧪 Testing

Run the Phase 1 setup verification:

```bash
php _tests/verify-phase1-setup.php
```

See [TESTING_GUIDE_PHASE1.md](TESTING_GUIDE_PHASE1.md) for detailed testing instructions and [QUICK_TEST_REFERENCE.md](QUICK_TEST_REFERENCE.md) for a 15-minute quick test guide.

## 🎛️ Phase 2 Features (Account Management UI)

### 📊 Settings Dashboard

Central hub for account management with:
- **User Statistics:** PassKey count, MFA status, connected accounts, activity summary
- **Security Recommendations:** Personalized suggestions to improve account security
- **Quick Actions:** Direct links to common settings
- **Recent Activity Preview:** Last 5 account activities

**Access:** [/settings/](public_html/settings/index.php)

### 👤 Profile Management

Manage personal information and account details:
- **Update Profile:** Display name, username, timezone
- **Email Management:** Change email address with password verification
- **Account Information:** View account creation date, last login
- **Activity Logging:** All changes tracked in activity log

**Access:** [/settings/profile](public_html/settings/profile.php)

### 🔒 Security Settings

Comprehensive security management:
- **Security Score:** 0-100% score based on enabled security features
- **Password Management:** Change password with validation
- **Authentication Overview:** View all enabled authentication methods
- **Login History:** Review recent login attempts and locations

**Access:** [/settings/security](public_html/settings/security.php)

### 🔗 Connected Accounts

OAuth account linking and management:
- **Supported Providers:** Google, Microsoft, Apple, Facebook, LinkedIn, GitHub
- **Link/Unlink:** Connect and disconnect OAuth accounts
- **Primary Account:** Set primary account for avatar display
- **Permission Review:** View granted scopes and permissions

**Access:** [/settings/connected-accounts](public_html/settings/connected-accounts.php)

### 🔐 MFA Management

Two-factor authentication control:
- **Enable/Disable MFA:** Toggle MFA with password confirmation
- **Authenticator Setup:** QR code and manual setup instructions
- **Backup Codes:** Generate, regenerate, print, and copy recovery codes
- **Usage Tracking:** Monitor MFA usage statistics

**Access:** [/settings/mfa](public_html/settings/mfa.php)

### 📊 Activity Log

Comprehensive activity tracking and export:
- **View History:** All account activities with details
- **Advanced Filtering:** By type, result, date range, search
- **Statistics:** Total, 7-day, 30-day, and failed login counts
- **Export:** Download as CSV or JSON
- **Pagination:** 25 items per page

**Access:** [/settings/activity](public_html/settings/activity.php)

### 🔒 Privacy Settings

Privacy and data control:
- **Profile Visibility:** Private, Friends Only, or Public
- **Third-Party Apps:** View and revoke API access
- **Data Preferences:** Analytics tracking, marketing emails
- **GDPR Compliance:** Data rights information and export

**Access:** [/settings/privacy](public_html/settings/privacy.php)

### 🔔 Notification Preferences

Customize notification delivery:
- **Email Notifications:** Security alerts, account updates, marketing
- **Push Notifications:** Security alerts, login notifications
- **SMS Notifications:** Security alerts (requires phone number)
- **Quick Actions:** Enable All, Disable All, Security Only

**Access:** [/settings/notifications](public_html/settings/notifications.php)

### 🧪 Testing Phase 2

Phase 2 includes comprehensive testing documentation:

**Quick Test (20 minutes):**
```bash
# Follow quick test guide
cat QUICK_TEST_REFERENCE_PHASE2.md
```

**Comprehensive Testing:**
See [TESTING_GUIDE_PHASE2.md](TESTING_GUIDE_PHASE2.md) for detailed testing with 100+ test cases covering:
- Functional testing (all 8 pages)
- Security testing (CSRF, XSS, SQL injection)
- UI/UX testing (responsive, accessibility)
- Performance testing
- Integration testing

**Quick Reference:**
[QUICK_TEST_REFERENCE_PHASE2.md](QUICK_TEST_REFERENCE_PHASE2.md) - 20-minute validation guide

## 🌐 Phase 3 Features (RESTful API)

### 📡 Comprehensive RESTful API (v1)

SIGNula provides a complete RESTful API for integration with third-party applications and services.

**Base URL:** `https://SIGNula.id/api/v1`

**Authentication Methods:**
- Session-based (cookies)
- Bearer token (Authorization: Bearer {token})
- API key (X-API-Key header or ?api_key= parameter)

**Response Format:**
All endpoints return standardized JSON responses with consistent structure.

### 🔐 Authentication Endpoints

**User Registration & Login:**
- `POST /api/v1/auth/register` - Create new user account
- `POST /api/v1/auth/login` - Login with email/password
- `POST /api/v1/auth/logout` - Logout current user
- `POST /api/v1/auth/refresh` - Refresh session/token

**Email Verification:**
- `POST /api/v1/auth/verify-email` - Verify email address
- `GET /api/v1/auth/verify-email` - Verify via email link

**Password Reset:**
- `POST /api/v1/auth/forgot-password` - Request password reset
- `POST /api/v1/auth/reset-password` - Reset password with token

### 👤 User Management Endpoints

**Profile Management:**
- `GET /api/v1/user/profile` - Get user profile with statistics
- `PUT /api/v1/user/profile` - Update display name, username, timezone

**Session Management:**
- `GET /api/v1/user/sessions` - List all active sessions
- `DELETE /api/v1/user/session/{id}` - Terminate specific session

**Activity & Preferences:**
- `GET /api/v1/user/activity` - Get activity log (filtered, paginated)
- `GET /api/v1/user/preferences` - Get user preferences
- `PUT /api/v1/user/preferences` - Update preferences

**Account Changes:**
- `POST /api/v1/user/change-password` - Change password
- `POST /api/v1/user/change-email` - Change email address

### 🔐 MFA (Multi-Factor Authentication) Endpoints

**MFA Management:**
- `POST /api/v1/mfa/enable` - Enable two-factor authentication
- `POST /api/v1/mfa/disable` - Disable two-factor authentication
- `POST /api/v1/mfa/verify` - Verify MFA code (TOTP or backup code)
- `GET /api/v1/mfa/setup` - Get MFA setup information

**Backup Codes:**
- `GET /api/v1/mfa/backup-codes` - Get backup codes count
- `POST /api/v1/mfa/backup-codes/regenerate` - Generate new backup codes

### 🔗 OAuth Account Linking Endpoints

**Provider Management:**
- `GET /api/v1/oauth/providers` - List available OAuth providers

**Account Linking:**
- `GET /api/v1/oauth/linked` - Get linked OAuth accounts
- `POST /api/v1/oauth/link` - Link OAuth account
- `DELETE /api/v1/oauth/unlink/{provider}` - Unlink OAuth account
- `POST /api/v1/oauth/set-primary` - Set primary OAuth account

**Supported Providers:**
Google, Microsoft, Apple, Facebook, LinkedIn, GitHub

**🆕 Multi-Account Support:**
SIGNula now supports linking **multiple accounts from the same provider** to a single SIGNula account. This enables:

- Linking both personal and work Google accounts
- Multiple Microsoft 365 accounts (personal, work, school)
- Separation of personal and organizational identities

Each OAuth account includes:
- `account_type` - Account classification (personal, work, school)
- `email_domain` - Email domain for filtering (e.g., company.com)

**Third-Party Integration:**
Services using SIGNula can require specific domains or account types:

```php
// Example: Require company domain
$accounts = getLinkedOAuthAccounts($token);
$companyAccounts = array_filter($accounts, function($acc) {
    return $acc['email_domain'] === 'company.com';
});
```

**Documentation:**
See [OAuth Integration Examples](_docs/OAUTH_INTEGRATION_EXAMPLES.md) for detailed implementation guidance including domain-based requirements and account filtering.

### 🔍 Utility Endpoints

- `GET /api/v1/health` - API health check
- `GET /api/v1/info` - API version and information

### 📊 API Features

✅ **30+ Endpoints** - Comprehensive API coverage
✅ **Standardized Responses** - Consistent JSON format
✅ **Input Validation** - 20+ validation rules
✅ **Pagination** - Efficient data retrieval (25-100 items per page)
✅ **Error Handling** - Detailed error messages
✅ **Activity Logging** - All API actions logged
✅ **CORS Support** - Cross-origin requests enabled
✅ **Rate Limiting** - Protection against abuse (framework ready)

### 📖 API Response Example

```json
{
  "success": true,
  "message": "User profile retrieved successfully",
  "data": {
    "profile": {
      "userID": 123,
      "email": "user@example.com",
      "displayName": "John Doe",
      "username": "johndoe",
      "emailVerified": true,
      "mfaEnabled": true
    },
    "stats": {
      "passkeys_count": 2,
      "oauth_accounts_count": 1,
      "active_sessions_count": 3
    }
  },
  "meta": {
    "timestamp": "2026-02-02T12:00:00Z",
    "version": "v1",
    "request_id": "abc123def456"
  }
}
```

### 📚 API Documentation for Partners

**Complete documentation available in two formats:**

1. **Interactive HTML Documentation** (Recommended)
   - Access: `https://SIGNula.id/api/docs/` ([/public_html/api/docs/index.html](public_html/api/docs/index.html))
   - Features: Search, syntax highlighting, copy-to-clipboard, mobile responsive
   - Comprehensive guide with examples

2. **Markdown Documentation**
   - File: [/public_html/api/docs/API_DOCUMENTATION.md](public_html/api/docs/API_DOCUMENTATION.md)
   - 26KB complete reference
   - All 31 endpoints documented
   - Request/response examples
   - Error codes and webhooks

**Documentation Includes:**
- ✅ Getting started guide (3 steps)
- ✅ Authentication methods (API Key, Bearer Token, Session)
- ✅ Rate limiting guidelines
- ✅ Error handling
- ✅ All 31 endpoints with examples
- ✅ Webhook integration (10 event types)
- ✅ SDK information (PHP, JavaScript, Python, Ruby)
- ✅ Quick start code samples

**Security Analysis:**
- See [.claude/API_ANALYSIS.md](.claude/API_ANALYSIS.md) for comprehensive security audit
- Security score: 80% (excellent foundation)
- Overall API quality: 87% (B+ grade)

### 🧪 Testing the API

**Manual Testing:**
Use tools like Postman, Insomnia, or cURL to test API endpoints.

**Health Check:**
```bash
curl https://SIGNula.id/api/v1/health
```

**Example API Call:**
```bash
curl -X POST https://SIGNula.id/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "yourpassword"
  }'
```

## 📧 Delegate Email Sending

### Overview

SIGNula can send emails through user's connected Microsoft 365 or Google Workspace mailboxes, enabling personalized communication and significant cost savings.

### Key Features

**✅ Dual-Mode Authentication:**
- **Application Auth** - Use FREE Microsoft 365 shared mailboxes (no user license required)
- **Delegated Auth** - Send from user's personal mailboxes via OAuth
- **AUTO Mode** - Intelligent fallback between modes

**✅ Supported Providers:**
- Microsoft 365 (Personal, Work, School accounts + Shared mailboxes)
- Google Workspace (Gmail API with service account delegation)

**✅ Security:**
- OAuth 2.0 token storage with AES-256 encryption
- Automatic token refresh
- Activity logging for all operations
- State token CSRF protection

### Cost Savings

**Before:**
- 5 system mailboxes = 5 Microsoft 365 licenses = **$600/year**

**After:**
- 5 FREE shared mailboxes = **$0/year** 💰

### Setup

**1. Database Migration:**

**Note:** If you used the complete installation (v2.2.0), delegate mailbox support is already included. For existing installations, apply the migration:

```bash
mysql -u username -p database < _database/migrations/006_delegate_mailbox_support.sql
```

**2. Configure Azure AD** (for Microsoft 365):
- Add `Mail.Send.Shared` permission
- Create shared mailboxes
- Grant "Send As" permissions

See [_docs/MICROSOFT_DELEGATE_MAILBOX_SETUP.md](_docs/MICROSOFT_DELEGATE_MAILBOX_SETUP.md) for detailed setup.

**3. User Interface:**
- Navigate to `/settings/email-accounts.php`
- Connect Microsoft 365 or Google Workspace
- Manage connected accounts

### Usage Examples

**System Emails (FREE Shared Mailboxes):**
```php
// Send from FREE shared mailbox (no userID)
EmailService::sendTemplateEmail(
    'customer@example.com',
    'welcome_email',
    ['name' => 'John Doe'],
    null,  // No userID = application auth = FREE
    5,
    'support@signulo.id'
);
```

**Personal Emails (User OAuth):**
```php
// Send from user's connected mailbox
EmailService::sendTemplateEmail(
    'prospect@example.com',
    'sales_proposal',
    ['amount' => '$10,000'],
    $userID,  // User's ID = delegated auth
    5,
    'sales@company.com'
);
```

### Documentation

- [_docs/SHARED_MAILBOXES_AND_AUTH_MODES.md](_docs/SHARED_MAILBOXES_AND_AUTH_MODES.md) - Complete feature guide
- [_docs/MICROSOFT_DELEGATE_MAILBOX_SETUP.md](_docs/MICROSOFT_DELEGATE_MAILBOX_SETUP.md) - Azure AD setup
- [_docs/DELEGATE_MAILBOX_ARCHITECTURE.md](_docs/DELEGATE_MAILBOX_ARCHITECTURE.md) - Technical architecture
- [.claude/IMPLEMENTATION_COMPLETE.md](.claude/IMPLEMENTATION_COMPLETE.md) - Setup and testing guide

---

## 🔧 Configuration

All configuration is managed through the database `tblSettings` table. Key settings include:

### Security Settings

- `security.session.lifetime` - Session duration (seconds)
- `security.password.min_length` - Minimum password length
- `security.login.max_attempts` - Maximum login attempts before lockout
- `security.encryption.algorithm` - Encryption algorithm

### MFA Settings

- `mfa.totp.enabled` - Enable TOTP authentication
- `mfa.email.enabled` - Enable email OTP
- `mfa.otp.lifetime` - OTP validity period

### WebAuthn/PassKey Settings

- `auth.webauthn.enabled` - Enable WebAuthn/PassKeys
- `auth.webauthn.rp_name` - Relying Party display name
- `auth.webauthn.rp_id` - Relying Party ID (your domain)
- `auth.webauthn.challenge_validity` - Challenge validity period (minutes)
- `auth.webauthn.user_verification` - User verification requirement
- `auth.passwordless.enabled` - Enable passwordless email login
- `auth.passwordless.token_validity` - Token validity period (minutes)
- `auth.passwordless.rate_limit_email` - Rate limit per email
- `auth.passwordless.rate_limit_ip` - Rate limit per IP

### Email Settings

- `email.smtp.host` - SMTP server
- `email.smtp.port` - SMTP port
- `email.from.address` - Default sender email

### API Settings

- `api.enabled` - Enable/disable API
- `api.rate_limit.requests_per_hour` - API rate limit

## 🔌 API Integration

### Authentication Endpoint

```http
POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "secure_password"
}
```

### Response

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user_id": 123,
    "session_token": "abc123...",
    "requires_mfa": false
  }
}
```

Detailed API documentation coming soon.

## 🔐 Security Best Practices

1. **Always use HTTPS in production**
2. **Keep PHP and database software updated**
3. **Use strong, unique encryption keys**
4. **Regularly backup your database**
5. **Enable rate limiting**
6. **Monitor error and activity logs**
7. **Use environment variables for sensitive data**
8. **Keep `_private` directory outside web root**

## 🧪 Development

### Debug Mode

Enable debug mode in development:

1. Set `ENVIRONMENT` to `'development'` in `_private/auth.php`
2. Access pages with `?debug=true` to see detailed errors

### Local Development

```bash
# Use PHP built-in server for local testing
php -S localhost:8000 -t public_html
```

### Version Management

SIGNula follows [Semantic Versioning](https://semver.org/) (SemVer) for all releases.

**Current Version:** See [VERSION](VERSION) file

**Version Format:** `MAJOR.MINOR.PATCH-prerelease`
- **MAJOR**: Breaking changes
- **MINOR**: New features (backward compatible)
- **PATCH**: Bug fixes (backward compatible)
- **prerelease**: alpha, beta, rc

**Quick Commands:**
```bash
# View current version info
cd _scripts && ./version-info.sh

# Bump version (patch, minor, major, beta, rc, release)
./version-bump.sh patch

# Create GitHub release
./create-release.sh
```

**Documentation:**
- [VERSION_MANAGEMENT.md](_docs/VERSION_MANAGEMENT.md) - Complete version management guide
- [CHANGELOG.md](CHANGELOG.md) - Detailed change history

## 📊 Monitoring & Logging

SIGNula provides comprehensive logging:

- **Activity Log** (`tblActivityLog`) - All user and system activities
- **Error Log** (`tblErrorLog`) - PHP errors and exceptions
- **Security Events** (`tblSecurityEvents`) - Security-related incidents
- **File Logs** (`_private/logs/`) - Fallback file-based logging

## 🤝 Contributing

This is a proprietary project. For contributions or bug reports, please contact the development team.

## 📄 License

Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.

This software is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.

## 👥 Support

For support, please contact:
- **Website:** https://SIGNula.com
- **Email:** support@signula.com

## 🗺️ Roadmap

See [PROJECT_PROGRESS.md](PROJECT_PROGRESS.md) for detailed development roadmap and progress tracking.

## 📚 Additional Resources

### Phase 1 Documentation (WebAuthn & Passwordless Login)
- [AUTH_PHASE1_DOCUMENTATION.md](AUTH_PHASE1_DOCUMENTATION.md) - Complete Phase 1 feature documentation
- [TESTING_GUIDE_PHASE1.md](TESTING_GUIDE_PHASE1.md) - Comprehensive testing guide (60+ test cases)
- [QUICK_TEST_REFERENCE.md](QUICK_TEST_REFERENCE.md) - 15-minute quick test guide

### Phase 2 Documentation (Account Management UI)
- [TESTING_GUIDE_PHASE2.md](TESTING_GUIDE_PHASE2.md) - Comprehensive testing guide (100+ test cases)
- [QUICK_TEST_REFERENCE_PHASE2.md](QUICK_TEST_REFERENCE_PHASE2.md) - 20-minute quick test guide

### Phase 3 Documentation (API, OAuth & Email)
- **API Documentation:**
  - [public_html/api/docs/index.html](public_html/api/docs/index.html) - Interactive HTML documentation
  - [public_html/api/docs/API_DOCUMENTATION.md](public_html/api/docs/API_DOCUMENTATION.md) - Complete Markdown reference
  - [.claude/API_ANALYSIS.md](.claude/API_ANALYSIS.md) - Security audit and gap analysis
  - [.claude/API_DOCUMENTATION_SUMMARY.md](.claude/API_DOCUMENTATION_SUMMARY.md) - Documentation summary
- **Delegate Email Sending:**
  - [_docs/SHARED_MAILBOXES_AND_AUTH_MODES.md](_docs/SHARED_MAILBOXES_AND_AUTH_MODES.md) - Complete feature guide ⭐ START HERE
  - [_docs/MICROSOFT_DELEGATE_MAILBOX_SETUP.md](_docs/MICROSOFT_DELEGATE_MAILBOX_SETUP.md) - Azure AD configuration
  - [_docs/DELEGATE_MAILBOX_ARCHITECTURE.md](_docs/DELEGATE_MAILBOX_ARCHITECTURE.md) - Technical architecture
  - [_docs/DUAL_MODE_IMPLEMENTATION_SUMMARY.md](_docs/DUAL_MODE_IMPLEMENTATION_SUMMARY.md) - Implementation details
  - [.claude/IMPLEMENTATION_COMPLETE.md](.claude/IMPLEMENTATION_COMPLETE.md) - Setup and testing guide
- **OAuth Integration:**
  - [_docs/OAUTH_INTEGRATION_EXAMPLES.md](_docs/OAUTH_INTEGRATION_EXAMPLES.md) - OAuth integration guide for third-party services
- **Database:**
  - [_database/](_database/) - All database files (complete installation + migrations)
  - [_database/signula_complete_install_v2.2.3.sql](_database/signula_complete_install_v2.2.3.sql) - Complete installation (migrations 001-009)
  - [_database/migrations/](_database/migrations/) - Individual migration files (001-012)
  - [_docs/SECURITY_DEPLOYMENT_GUIDE.md](_docs/SECURITY_DEPLOYMENT_GUIDE.md) - Database deployment instructions

### Development & Version Management
- [VERSION_MANAGEMENT.md](_docs/VERSION_MANAGEMENT.md) - Complete version management guide
- [CHANGELOG.md](CHANGELOG.md) - Detailed change history following Keep a Changelog format
- [CLAUDE_NOTES.md](.claude/CLAUDE_NOTES.md) - Development patterns, conventions, and troubleshooting
- [PROJECT_PROGRESS.md](PROJECT_PROGRESS.md) - Detailed development roadmap and progress tracking

### System Status
- [.claude/REQUIREMENTS_STATUS.md](.claude/REQUIREMENTS_STATUS.md) - Overall requirements completion (~99%)
- [PROJECT_PROGRESS.md](PROJECT_PROGRESS.md) - Detailed development roadmap and progress

### Coming Soon
- [User Guide](docs/USER_GUIDE.md) (Coming Soon)
- [Privacy Policy](docs/PRIVACY.md) (Coming Soon)
- [Terms of Service](docs/TERMS.md) (Coming Soon)

---

**Built with ❤️ by MWBMPartners**

---

**Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
