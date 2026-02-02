# SIGNula.ID - Universal Login System

![Version](https://img.shields.io/badge/version-1.5.0-blue)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php)
![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?logo=mysql)
![License](https://img.shields.io/badge/license-Proprietary-red)

## 📋 Overview

**SIGNula** is a comprehensive, universal single sign-on (SSO) authentication system designed to provide seamless user authentication across multiple web and mobile applications. Built with security, scalability, and user experience as top priorities, SIGNula offers a modern authentication solution for today's interconnected digital ecosystem.

**Current Status:** Phase 1 (WebAuthn/PassKeys & Passwordless Login) Complete ✅ | Phase 2 (Account Management) In Progress 🟡

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

- **🌐 RESTful API**
  - Secure JSON-based API for service integration
  - OAuth 2.0 support
  - Rate limiting and throttling
  - Comprehensive API documentation

- **💳 Subscription Management**
  - Multiple tier support (Free, Basic, Premium, Enterprise)
  - Payment processing (PayPal, Apple Pay, Google Pay, Crypto)
  - Subscription management and billing

- **🛡️ Enterprise-Grade Security**
  - AES-256-CBC encryption for sensitive data
  - Argon2id password hashing
  - CSRF protection
  - Rate limiting
  - Brute force protection
  - Comprehensive activity logging
  - Security event monitoring

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
- **Frameworks:** Bootstrap 5
- **Libraries:** Font Awesome, jQuery
- **Security:** OpenSSL, password_hash with Argon2id

## 📁 Project Structure

```
SIGNula.id/
├── _config/                 # Configuration files
│   ├── config.php          # Main configuration bootstrap
│   └── database.php        # Database connection handler
├── _includes/              # Reusable PHP components
│   ├── auth/               # Authentication classes
│   │   ├── Auth.php       # Core authentication system
│   │   ├── WebAuthnHandler.php  # WebAuthn/PassKey handler
│   │   └── PasswordlessLoginHandler.php  # Passwordless email auth
│   ├── layout/             # Layout components
│   │   ├── header.php     # Common header
│   │   ├── footer.php     # Common footer
│   │   └── settings-sidebar.php  # Settings navigation
│   ├── security/           # Security utilities
│   │   └── SecurityUtils.php
│   ├── email/              # Email services
│   │   └── EmailService.php
│   ├── api/                # API handlers
│   ├── database/           # Database utilities
│   └── utils/              # Utility classes
│       ├── ActivityLogger.php
│       └── ErrorLogger.php
├── _lib/                   # Third-party libraries
│   ├── vendor/            # Composer packages (if used)
│   └── oauth/             # OAuth libraries
├── _private/               # Private files (outside web root)
│   ├── auth.php.example   # Database credentials template
│   ├── keys/              # Encryption keys
│   ├── logs/              # Application logs
│   ├── backups/           # Database backups
│   └── templates/         # Email templates
│       └── email/
├── _database/              # Database files
│   └── migrations/        # Database migrations
│       ├── 001_initial_schema.sql
│       ├── 002_mfa_system.sql
│       ├── 003_oauth_integration.sql
│       ├── 004_email_system.sql
│       └── 005_webauthn_passkeys.sql
├── _tests/                # Test files
│   └── verify-phase1-setup.php
├── public_html/            # Public web directory
│   ├── assets/            # Static assets
│   │   ├── css/
│   │   ├── js/
│   │   ├── images/
│   │   └── fonts/
│   ├── api/               # API endpoints
│   │   └── webauthn/     # WebAuthn API endpoints
│   │       ├── register-options.php
│   │       ├── register-verify.php
│   │       ├── auth-options.php
│   │       └── auth-verify.php
│   ├── auth/              # Authentication pages
│   │   ├── login.php
│   │   ├── register.php
│   │   ├── passkey-register.php
│   │   ├── passkey-login.php
│   │   ├── passwordless-request.php
│   │   └── passwordless-login.php
│   └── settings/          # Account settings pages
│       ├── index.php     # Settings dashboard
│       ├── profile.php   # Profile management
│       ├── security.php  # Security settings
│       └── passkeys.php  # PassKey management
├── alpha_html/             # Alpha version directory
├── beta_html/              # Beta version directory
├── .gitignore
├── README.md
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

1. Create a new MySQL database:

```sql
CREATE DATABASE signula_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Import the database schema:

```bash
# Import initial schema
mysql -u your_username -p signula_db < _database/migrations/001_initial_schema.sql

# Import MFA system
mysql -u your_username -p signula_db < _database/migrations/002_mfa_system.sql

# Import OAuth integration
mysql -u your_username -p signula_db < _database/migrations/003_oauth_integration.sql

# Import email system
mysql -u your_username -p signula_db < _database/migrations/004_email_system.sql

# Import WebAuthn/PassKeys (Phase 1)
mysql -u your_username -p signula_db < _database/migrations/005_webauthn_passkeys.sql
```

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

## 📊 Monitoring & Logging

SIGNula provides comprehensive logging:

- **Activity Log** (`tblActivityLog`) - All user and system activities
- **Error Log** (`tblErrorLog`) - PHP errors and exceptions
- **Security Events** (`tblSecurityEvents`) - Security-related incidents
- **File Logs** (`_private/logs/`) - Fallback file-based logging

## 🤝 Contributing

This is a proprietary project. For contributions or bug reports, please contact the development team.

## 📄 License

Copyright © 2024 MWBMPartners. All rights reserved.

This software is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.

## 👥 Support

For support, please contact:
- **Website:** https://signulo.com
- **Email:** support@signulo.com

## 🗺️ Roadmap

See [PROJECT_PROGRESS.md](PROJECT_PROGRESS.md) for detailed development roadmap and progress tracking.

## 📚 Additional Resources

### Phase 1 Documentation
- [AUTH_PHASE1_DOCUMENTATION.md](AUTH_PHASE1_DOCUMENTATION.md) - Complete Phase 1 feature documentation
- [TESTING_GUIDE_PHASE1.md](TESTING_GUIDE_PHASE1.md) - Comprehensive testing guide (60+ test cases)
- [QUICK_TEST_REFERENCE.md](QUICK_TEST_REFERENCE.md) - 15-minute quick test guide

### Coming Soon
- [Technical Documentation](docs/TECHNICAL.md) (Coming Soon)
- [API Documentation](docs/API.md) (Coming Soon)
- [User Guide](docs/USER_GUIDE.md) (Coming Soon)
- [Privacy Policy](docs/PRIVACY.md) (Coming Soon)
- [Terms of Service](docs/TERMS.md) (Coming Soon)

---

**Built with ❤️ by MWBMPartners**
