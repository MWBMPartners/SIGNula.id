# SIGNula Scripts Directory

This directory contains utility scripts for managing the SIGNula project.

---

## 📋 Available Scripts

### Database Management

#### **signula_complete_install_v2.2.0.sql**
**Purpose:** Complete database installation for fresh deployments
**Usage:**
```bash
mysql -u your_username -p < _database/signula_complete_install_v2.2.0.sql
```
**Features:**
- Creates complete database schema with all features through v2.2.0-beta
- Includes all migrations (core auth, OAuth, email, blog, support, rate limiting, API keys)
- Recommended for new installations

#### **build-complete-install.sh**
**Purpose:** Generate the complete installation SQL file
**Usage:**
```bash
bash _scripts/build-complete-install.sh
```
**What it does:**
- Combines base schema from v2.0.1 with all subsequent migrations
- Generates `_database/signula_complete_install_v2.2.0.sql`
- Organized by feature area with proper documentation
- Run this after adding new migrations to update the complete install file

#### **reorganize-database-files.sh**
**Purpose:** Database file organization utility (one-time use)
**Status:** ✅ Already executed
**What it did:**
- Moved database files from `web/_database/` to `_database/`
- Consolidated duplicate migration directories
- Created backup before changes
- Updated .gitignore

---

### Security & Deployment

#### **deploy-security-migrations.sh** ⭐ NEW
**Purpose:** Deploy rate limiting and API key management migrations
**Usage:**
```bash
bash _scripts/deploy-security-migrations.sh
```
**Features:**
- Interactive deployment wizard
- Checks if migrations already applied
- Creates database backup (optional)
- Deploys migrations 007 & 008
- Verifies deployment success
- Provides next steps

**Deploys:**
- Migration 007: Rate Limiting System
- Migration 008: Partner API Key Management

#### **test-security-features.sh** ⭐ NEW
**Purpose:** Test rate limiting and API key authentication
**Usage:**
```bash
bash _scripts/test-security-features.sh [api_base_url]

# Example:
bash _scripts/test-security-features.sh http://localhost/signula/public_html/api/v1
```
**Tests:**
1. Default IP-based rate limiting (100/hour, 10/min, 20/burst)
2. API key authentication (valid/invalid keys)
3. API key rate limiting
4. Progressive blocking (optional)
5. Endpoint-specific limits (login protection)

**Requirements:**
- curl installed
- API accessible at specified URL
- Optional: Test API key for authentication tests

#### **generate_test_api_key.php**
**Purpose:** Generate test and live API keys for partners
**Usage:**
```bash
php _scripts/generate_test_api_key.php [options]

# Interactive mode (recommended):
php _scripts/generate_test_api_key.php

# With options:
php _scripts/generate_test_api_key.php --live --partner-id=1 --name="Production Key" --expires=365
```
**Options:**
- `--live` - Generate live key (sk_live_xxx) instead of test key
- `--partner-id=N` - Partner ID to generate key for
- `--name="Key Name"` - Name for the API key
- `--expires=N` - Days until expiration (default: 365)

**Outputs:**
- Generated API key (show only once!)
- Usage examples in multiple languages (cURL, JavaScript, PHP)
- Quick validation commands
- Security notes and monitoring queries

---

### Copyright Management

#### **add-copyright.sh**
**Purpose:** Add or update copyright headers in project files
**Usage:**
```bash
# Add copyright to all files
bash _scripts/add-copyright.sh

# Update copyright years
bash _scripts/add-copyright.sh --update-year

# Dry run (preview changes)
bash _scripts/add-copyright.sh --dry-run
```
**Processes:**
- PHP files (header comment block)
- SQL files (header comment block)
- Markdown files (footer)
- JavaScript files (header comment block)
- Shell scripts (header comment block)

**Note:** Automatically runs via Git pre-commit hook - manual execution rarely needed

#### **setup-copyright-hooks.sh**
**Purpose:** Install Git hooks for automatic copyright management
**Status:** ✅ Already executed
**What it did:**
- Installed pre-commit hook in `.git/hooks/`
- Configured automatic copyright year updates
- Enabled automatic copyright addition to new files

#### **git-hooks/pre-commit**
**Purpose:** Git pre-commit hook (automatically executed)
**Auto-runs on:** Every `git commit`
**Functions:**
1. Adds copyright headers to new files (PHP, JS, SQL, Markdown, Shell)
2. Updates copyright years in existing files (YYYY-2026)
3. Automatically stages modified files

**You don't need to run this manually - it's automatic!**

---

## 🚀 Quick Start Guide

### For Fresh Installation:
```bash
# 1. Install complete database
mysql -u your_username -p < _database/signula_complete_install_v2.2.0.sql

# 2. Generate test API key
php _scripts/generate_test_api_key.php

# 3. Test security features
bash _scripts/test-security-features.sh http://your-domain.com/api/v1
```

### For Existing Installation (Upgrade to v2.2.0):
```bash
# 1. Deploy security migrations
bash _scripts/deploy-security-migrations.sh

# 2. Generate test API key
php _scripts/generate_test_api_key.php

# 3. Test security features
bash _scripts/test-security-features.sh http://your-domain.com/api/v1
```

### For Development:
```bash
# After adding new migrations, rebuild complete install file:
bash _scripts/build-complete-install.sh

# Copyright is managed automatically via Git hooks - no action needed!
```

---

## 📚 Documentation

- **Security Deployment:** [_docs/SECURITY_DEPLOYMENT_GUIDE.md](../_docs/SECURITY_DEPLOYMENT_GUIDE.md)
- **Copyright Management:** [_docs/COPYRIGHT_MANAGEMENT.md](../_docs/COPYRIGHT_MANAGEMENT.md)
- **Database Structure:** [_database/README.md](../_database/README.md)
- **Project Progress:** [PROJECT_PROGRESS.md](../PROJECT_PROGRESS.md)

---

## 🔧 Script Maintenance

### Adding New Scripts

When adding new scripts to this directory:

1. **Add copyright header:**
   - Automatically added by pre-commit hook
   - Or run: `bash _scripts/add-copyright.sh`

2. **Make executable:**
   ```bash
   chmod +x _scripts/your-new-script.sh
   ```

3. **Update this README:**
   - Add script description
   - Document usage and options
   - Update Quick Start Guide if needed

4. **Test thoroughly:**
   - Test in development environment first
   - Document any prerequisites
   - Include error handling

---

## ⚠️ Important Notes

### Database Scripts
- **Always backup** before running database migrations
- Test migrations in development environment first
- Migrations should be idempotent (safe to run multiple times)

### Security Scripts
- Never commit API keys or database credentials
- Test security features in staging before production
- Monitor rate limit logs after deployment

### Copyright Scripts
- Copyright management is automatic via Git hooks
- Manual execution only needed for batch updates
- Pre-commit hook ensures all new files have copyright

---

## 🆘 Troubleshooting

### "Permission denied" Error
```bash
chmod +x _scripts/script-name.sh
```

### "Command not found: php"
- Ensure PHP is installed and in PATH
- On Mac: Use MAMP/XAMPP or install via Homebrew
- Check: `php --version`

### "Command not found: mysql"
- Ensure MySQL/MariaDB client is installed
- Add to PATH or use full path: `/usr/local/mysql/bin/mysql`
- Check: `mysql --version`

### Database Connection Errors
- Verify credentials
- Check database server is running
- Confirm database name exists
- Test connection: `mysql -u user -p -e "SELECT 1;"`

---

**Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
