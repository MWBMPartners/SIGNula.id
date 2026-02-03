# SIGNula Database Installation Scripts

This directory contains SQL scripts for installing and upgrading the SIGNula database.

---

## 📋 Table of Contents

- [Quick Start](#quick-start)
- [Installation Files](#installation-files)
- [Installation Methods](#installation-methods)
- [Post-Installation](#post-installation)
- [Upgrading](#upgrading)
- [Troubleshooting](#troubleshooting)

---

## 🚀 Quick Start

### New Installation

For a fresh SIGNula installation, use the complete installation script:

```bash
# Method 1: Direct MySQL command
mysql -u your_username -p < signula_complete_install_v2.0.1.sql

# Method 2: Import after connecting
mysql -u your_username -p
source /path/to/signula_complete_install_v2.0.1.sql
```

### Upgrading from Previous Version

If you already have SIGNula installed and need to upgrade, see the [Upgrading](#upgrading) section.

---

## 📁 Installation Files

### Current Version Files

| File | Version | Purpose | Tables | Views | Procedures |
|------|---------|---------|--------|-------|------------|
| `signula_complete_install_v2.0.1.sql` | 2.0.1-beta | Complete fresh installation | 16 | 2 | 3 |

### Legacy Files

| File | Version | Purpose |
|------|---------|---------|
| `001_initial_schema.sql` | 1.0.0 | Initial schema (Phase 1) |
| `002_organizations_migration.sql` | 1.5.0 | Organization support (future feature) |

---

## 🛠️ Installation Methods

### Method 1: Command Line (Recommended)

**Prerequisites:**
- MySQL 8.0+ or MariaDB 10.5+
- Database user with CREATE, DROP, INSERT privileges

**Steps:**

```bash
# 1. Download the SQL file
cd /path/to/signula/_sql

# 2. Run the installation
mysql -u your_username -p < signula_complete_install_v2.0.1.sql

# 3. Enter your MySQL password when prompted
```

**What this creates:**
- Database: `signula`
- 16 tables (users, sessions, OAuth, MFA, PassKeys, etc.)
- 2 views (user sessions, security overview)
- 3 stored procedures (cleanup procedures)
- 50+ default settings
- Migration tracking records

### Method 2: MySQL Interactive

```bash
# 1. Connect to MySQL
mysql -u your_username -p

# 2. Import the file
mysql> source /path/to/signula/_sql/signula_complete_install_v2.0.1.sql

# 3. Verify installation
mysql> USE signula;
mysql> SHOW TABLES;
mysql> SELECT * FROM tblMigrations;
```

### Method 3: PHPMyAdmin

1. Log into PHPMyAdmin
2. Create a new database named `signula` (or use existing)
3. Click **Import** tab
4. Choose file: `signula_complete_install_v2.0.1.sql`
5. Click **Go**

### Method 4: MySQL Workbench

1. Open MySQL Workbench
2. Connect to your MySQL server
3. Click **Server** > **Data Import**
4. Select **Import from Self-Contained File**
5. Choose `signula_complete_install_v2.0.1.sql`
6. Click **Start Import**

---

## 🔧 Post-Installation

After running the installation script, complete these essential configuration steps:

### 1. Update Encryption Keys

**CRITICAL:** Generate and update encryption keys before creating any users.

```bash
# Generate encryption key (32 bytes, base64)
openssl rand -base64 32

# Generate salt (16 bytes, hex)
openssl rand -hex 16
```

**Update in database:**

```sql
USE signula;

UPDATE tblSettings
SET settingValue = 'YOUR_BASE64_KEY_HERE'
WHERE settingKey = 'security.encryption.key';

UPDATE tblSettings
SET settingValue = 'YOUR_HEX_SALT_HERE'
WHERE settingKey = 'security.encryption.salt';
```

### 2. Configure Application URL

Update the application URL to match your domain:

```sql
UPDATE tblSettings
SET settingValue = 'https://yourdomain.com'
WHERE settingKey = 'app.url';

UPDATE tblSettings
SET settingValue = 'yourdomain.com'
WHERE settingKey = 'auth.webauthn.rp_id';
```

### 3. Configure Email Settings (Optional)

If using SMTP for emails:

```sql
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'email.smtp.enabled';
UPDATE tblSettings SET settingValue = 'smtp.example.com' WHERE settingKey = 'email.smtp.host';
UPDATE tblSettings SET settingValue = '587' WHERE settingKey = 'email.smtp.port';
UPDATE tblSettings SET settingValue = 'your-smtp-username' WHERE settingKey = 'email.smtp.username';
UPDATE tblSettings SET settingValue = 'your-smtp-password' WHERE settingKey = 'email.smtp.password';
UPDATE tblSettings SET settingValue = 'noreply@yourdomain.com' WHERE settingKey = 'email.from.address';
```

### 4. Configure OAuth Providers (Optional)

Enable and configure OAuth providers as needed:

```sql
-- Example: Enable Google OAuth
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'oauth.google.enabled';
UPDATE tblSettings SET settingValue = 'your-client-id' WHERE settingKey = 'oauth.google.client_id';
UPDATE tblSettings SET settingValue = 'your-client-secret' WHERE settingKey = 'oauth.google.client_secret';
```

Supported providers:
- Google
- Microsoft (Personal & 365)
- Apple
- Facebook
- LinkedIn
- GitHub

### 5. Update PHP Configuration

Edit `_private/auth.php` with your database credentials:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_database_username');
define('DB_PASS', 'your_database_password');
define('DB_NAME', 'signula');
```

### 6. Set File Permissions

```bash
chmod 600 _private/auth.php
chmod 755 _private/logs
chmod 755 _private/backups
chmod 755 public_html
```

### 7. Verify Installation

Run the verification script:

```bash
php _tests/verify-phase1-setup.php
```

---

## ⬆️ Upgrading

### From Version 1.x to 2.0.1

If you have an existing SIGNula installation (v1.x), run the migration scripts in order:

```bash
# 1. WebAuthn/PassKeys support
mysql -u your_username -p signula < ../database/migrations/005_webauthn_passkeys.sql

# 2. OAuth multi-account support
mysql -u your_username -p signula < ../_migrations/003_oauth_multi_account_support.sql
```

### From Version 2.0.0 to 2.0.1

If upgrading from version 2.0.0-beta, only the OAuth enhancement is needed:

```bash
mysql -u your_username -p signula < ../_migrations/003_oauth_multi_account_support.sql
```

### Verify Upgrade

```sql
USE signula;

-- Check applied migrations
SELECT * FROM tblMigrations ORDER BY appliedAt DESC;

-- Check for new OAuth fields
DESCRIBE tblOAuthAccounts;

-- Should show: accountType and emailDomain columns
```

---

## 🗄️ Database Structure Overview

### Core Tables (16)

| Table | Purpose | Records |
|-------|---------|---------|
| `tblUsers` | User accounts | Variable |
| `tblUserMFA` | MFA settings | Variable |
| `tblOAuthAccounts` | OAuth linked accounts | Variable |
| `tblWebAuthnCredentials` | PassKey credentials | Variable |
| `tblWebAuthnChallenges` | WebAuthn challenges | Auto-cleanup |
| `tblPasswordlessTokens` | Magic link tokens | Auto-cleanup |
| `tblSessions` | User sessions | Auto-cleanup |
| `tblEmailVerificationTokens` | Email verification | Auto-cleanup |
| `tblPasswordResetTokens` | Password reset | Auto-cleanup |
| `tblActivityLog` | Activity audit trail | Grows over time |
| `tblErrorLog` | Error logs | Grows over time |
| `tblSettings` | System configuration | ~50 |
| `tblUserPreferences` | User preferences | Variable |
| `tblMigrations` | Migration tracking | ~3+ |

### Views (2)

| View | Purpose |
|------|---------|
| `vwUserSessions` | Active user sessions with details |
| `vwUserSecurityOverview` | Security status per user |

### Stored Procedures (3)

| Procedure | Purpose | Schedule |
|-----------|---------|----------|
| `cleanupExpiredSessions()` | Remove expired sessions | Daily |
| `cleanupExpiredTokens()` | Remove expired tokens | Daily |
| `cleanupExpiredAuthTokens()` | Comprehensive cleanup | Daily |

---

## 🔍 Troubleshooting

### Issue: "Database already exists"

**Solution:** The script handles existing databases. If you want a fresh install:

```sql
DROP DATABASE IF EXISTS signula;
```

Then re-run the installation script.

### Issue: "Table already exists"

**Solution:** The script uses `CREATE TABLE IF NOT EXISTS`, so this shouldn't occur. If it does:

```sql
USE signula;
DROP TABLE tblUsers; -- Repeat for all tables
```

Then re-run the installation script.

### Issue: "Access denied"

**Error:** `ERROR 1044 (42000): Access denied for user`

**Solution:** Ensure your MySQL user has appropriate privileges:

```sql
GRANT ALL PRIVILEGES ON signula.* TO 'your_username'@'localhost';
FLUSH PRIVILEGES;
```

### Issue: "Unknown database"

**Solution:** Create the database first:

```sql
CREATE DATABASE signula CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Issue: Character encoding errors

**Solution:** Ensure your MySQL connection uses UTF-8:

```bash
mysql -u your_username -p --default-character-set=utf8mb4 < installation_file.sql
```

### Issue: Slow installation

**Cause:** Large initial settings insert.

**Solution:** This is normal. The script inserts 50+ default settings. Should complete in under 30 seconds.

---

## 📊 What Gets Installed

### Version 2.0.1-beta Includes:

✅ **Core Authentication**
- User accounts with Argon2id password hashing
- Email verification system
- Password reset functionality
- Session management

✅ **Multi-Factor Authentication**
- TOTP (Authenticator apps)
- Email OTP
- SMS OTP (framework ready)
- Backup codes (10 per user)

✅ **WebAuthn/PassKeys (Phase 1.5)**
- FIDO2/WebAuthn credential storage
- Platform authenticators (FaceID, TouchID, Windows Hello)
- Cross-platform authenticators (YubiKey, etc.)
- Challenge/response authentication

✅ **Passwordless Login (Phase 1.5)**
- Magic link via email
- Secure tokenized URLs
- Time-limited access (15-30 minutes)

✅ **OAuth Integration (Phase 3.1)**
- Multi-account support per provider
- Account type classification (personal/work/school)
- Email domain filtering
- Supports: Google, Microsoft, Apple, Facebook, LinkedIn, GitHub

✅ **Security Features**
- AES-256-CBC encryption for sensitive data
- Argon2id password hashing
- Rate limiting framework
- Activity audit logging
- Error tracking

✅ **RESTful API (Phase 3)**
- 30+ API endpoints
- Authentication, User, MFA, OAuth controllers
- Standardized JSON responses
- Input validation
- Pagination support

---

## 🔐 Security Recommendations

1. **Never commit** `_private/auth.php` to version control
2. **Use strong encryption keys** - Generate new ones, don't use defaults
3. **Enable HTTPS** - Required for PassKeys and secure cookies
4. **Regular backups** - Backup the `signula` database regularly
5. **Monitor logs** - Check `tblActivityLog` and `tblErrorLog` regularly
6. **Update regularly** - Apply security patches and updates
7. **Limit database access** - Use least-privilege principle for DB users
8. **Enable MFA** - Require MFA for admin accounts

---

## 📚 Additional Resources

- **Project Documentation:** [README.md](../README.md)
- **Progress Tracking:** [PROJECT_PROGRESS.md](../PROJECT_PROGRESS.md)
- **OAuth Integration:** [_docs/OAUTH_INTEGRATION_EXAMPLES.md](../_docs/OAUTH_INTEGRATION_EXAMPLES.md)
- **Testing Guide:** [TESTING_GUIDE_PHASE1.md](../TESTING_GUIDE_PHASE1.md)
- **API Documentation:** See README.md Phase 3 section

---

## 🆘 Support

For issues or questions:

1. Check the [Troubleshooting](#troubleshooting) section
2. Review [PROJECT_PROGRESS.md](../PROJECT_PROGRESS.md) for known issues
3. Check error logs: `SELECT * FROM tblErrorLog ORDER BY createdAt DESC LIMIT 10;`
4. Review activity logs: `SELECT * FROM tblActivityLog WHERE activityResult = 'failure' ORDER BY createdAt DESC LIMIT 20;`

---

## 📝 Version History

| Version | Date | Changes |
|---------|------|---------|
| 2.0.1-beta | 2026-02-03 | OAuth multi-account support, accountType, emailDomain fields |
| 2.0.0-beta | 2026-02-02 | RESTful API (30+ endpoints), comprehensive controllers |
| 1.5.0 | 2026-02-02 | WebAuthn/PassKeys, Passwordless login, Account management UI |
| 1.0.0 | 2024-11-15 | Initial release with core authentication and MFA |

---

**Last Updated:** 2026-02-03
**Maintainer:** SIGNula Development Team
**License:** Proprietary
