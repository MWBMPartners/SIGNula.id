# Directory Reorganization Guide

**Version:** 2.2.0-beta
**Date:** February 4, 2026
**Purpose:** Reorganize project structure for easier SFTP deployment

---

## 📋 Overview

This guide reorganizes the SIGNula project to place all server-uploadable files in a single `web/` directory, making deployment via SFTP (Visual Studio Code SFTP plugin) much simpler.

---

## 🎯 Goals

1. ✅ All server files in one `web/` directory
2. ✅ Cleaner project root (docs, tests, git files separate)
3. ✅ Easier SFTP syncing (just sync `web/` folder)
4. ✅ Better security (clear separation of upload vs local files)
5. ✅ Simpler .gitignore for sftp.json

---

## 📁 Current vs New Structure

### **BEFORE (Current Structure)**
```
SIGNula/
├── .claude/                    # Claude project files
├── .git/                       # Git repository
├── .github/                    # GitHub workflows
├── _api/                       # API files (?)
├── _config/                    # ⬆️ UPLOAD - Server config
├── _database/                  # ⬆️ UPLOAD - DB migrations
├── _docs/                      # Documentation (local only)
├── _migrations/                # ⬆️ UPLOAD - Migrations
├── _private/                   # ⬆️ UPLOAD - Private server files
├── _scripts/                   # ⬆️ UPLOAD - Server scripts
├── _sql/                       # ⬆️ UPLOAD - SQL files
├── _tests/                     # Tests (local only)
├── private_html/               # ⬆️ UPLOAD - Private application
├── public_html/                # ⬆️ UPLOAD - Public web root
│   └── _includes/              # ⚠️ Should NOT be in public root!
├── public_html_dev/            # ⬆️ UPLOAD - Dev version
├── public_html_landing/        # ⬆️ UPLOAD - Landing page
├── README.md, etc.             # Documentation (local only)
├── CHANGELOG.md                # Documentation (local only)
├── composer.json               # Dependency management (local only)
└── phpunit.xml                 # Testing config (local only)
```

### **AFTER (New Structure)**
```
SIGNula/
├── .claude/                    # Claude project files (NOT uploaded)
├── .git/                       # Git repository (NOT uploaded)
├── .github/                    # GitHub workflows (NOT uploaded)
├── _docs/                      # Documentation (NOT uploaded)
├── _tests/                     # Tests (NOT uploaded)
├── README.md                   # Project docs (NOT uploaded)
├── CHANGELOG.md                # Project docs (NOT uploaded)
├── PROJECT_PROGRESS.md         # Project docs (NOT uploaded)
├── composer.json               # Dependency mgmt (NOT uploaded)
├── phpunit.xml                 # Testing config (NOT uploaded)
├── phpcs.xml                   # Code standards (NOT uploaded)
├── phpstan.neon                # Static analysis (NOT uploaded)
├── .gitignore                  # Git config (NOT uploaded)
└── web/                        # 🎯 SYNC THIS DIRECTORY TO SERVER
    ├── _config/                # Server configuration
    ├── _includes/              # PHP includes (moved from public_html)
    ├── _lib/                   # Third-party libraries
    ├── _private/               # Private files (outside web root)
    ├── _database/              # Database migrations
    ├── _scripts/               # Server-side scripts
    ├── private_html/           # Private application files
    ├── public_html/            # Public web root
    ├── public_html_dev/        # Development version
    └── public_html_landing/    # Landing page version
```

---

## 🚀 Migration Steps

### Step 1: Backup Everything

```bash
# Create a complete backup
cd "/Users/lance.manasse/Projects/Coding and Development/MWBM Partners Ltd/GitHub/Web"
tar -czf "SIGNula-backup-$(date +%Y%m%d_%H%M%S).tar.gz" SIGNula/
```

### Step 2: Create New Directory Structure

```bash
cd SIGNula

# Create web directory
mkdir -p web

# Create subdirectories in web/
mkdir -p web/_includes
mkdir -p web/_lib
```

### Step 3: Move Directories to web/

**⚠️ IMPORTANT: Do these moves carefully, one at a time**

```bash
# Move configuration
mv _config web/

# Move private files
mv _private web/

# Move database migrations
mv _database web/

# Move scripts
mv _scripts web/

# Move SQL files (if keeping on server)
mv _sql web/_database/sql

# Move migrations (if different from _database)
# Check what's in _migrations first
mv _migrations web/_database/migrations

# Move application directories
mv private_html web/
mv public_html web/
mv public_html_dev web/
mv public_html_landing web/

# Move _includes OUT of public_html into web root
mv web/public_html/_includes web/_includes
```

### Step 4: Update Configuration File

The main configuration file needs ONE change - update ROOT_DIR:

**File:** `web/_config/config.php`

**OLD:**
```php
define('ROOT_DIR', dirname(__DIR__));
```

**NEW:**
```php
// Root is now web/ directory
define('ROOT_DIR', dirname(__DIR__));  // This already points to web/ correctly!
```

Actually, **NO CHANGE NEEDED** because:
- Before: `_config/config.php` → `dirname(__DIR__)` = SIGNula root
- After: `web/_config/config.php` → `dirname(__DIR__)` = web/ directory

The paths automatically adjust! ✅

### Step 5: Update INCLUDES_DIR Path

Since we're moving `_includes` from `public_html/_includes` to `web/_includes`:

**File:** `web/_config/config.php`

**Find:**
```php
define('INCLUDES_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . '_includes');
```

**Verify this line exists** - if not, add it. If `_includes` was previously defined as being inside PUBLIC_DIR, update it:

```php
// OLD (if it was this):
define('INCLUDES_DIR', PUBLIC_DIR . DIRECTORY_SEPARATOR . '_includes');

// NEW (should be this):
define('INCLUDES_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . '_includes');
```

### Step 6: Update All File References

**Search for any hardcoded paths that might reference the old structure:**

```bash
# Search for potential hardcoded paths
cd web/
grep -r "dirname(__DIR__, 2)" . 2>/dev/null | grep -v ".git"
grep -r "\.\.\/\.\.\/" . 2>/dev/null | grep -v ".git"
```

**Common patterns to check:**

1. **In public_html files** that load config:
   ```php
   // OLD (if 3 levels up):
   require_once dirname(__DIR__, 3) . '/_config/config.php';

   // NEW (now 2 levels up):
   require_once dirname(__DIR__, 2) . '/_config/config.php';
   ```

2. **In private_html files:**
   ```php
   // OLD (if 2 levels up):
   require_once dirname(__DIR__, 2) . '/_config/config.php';

   // NEW (now 1 level up):
   require_once dirname(__DIR__) . '/_config/config.php';
   ```

### Step 7: Update Include Statements

Since `_includes` moved from `public_html/_includes` to `web/_includes`, any files that referenced `__DIR__ . '/_includes'` need updating.

**Search and replace:**
```bash
# Find files that include from _includes using relative paths
grep -r "__DIR__ . '/_includes" web/public_html/ 2>/dev/null
```

**These should now use the INCLUDES_DIR constant** instead of relative paths:
```php
// OLD:
require_once __DIR__ . '/_includes/layout/header.php';

// NEW:
require_once INCLUDES_DIR . '/layout/header.php';
```

---

## 🔧 VS Code SFTP Configuration

### Create sftp.json in web/ directory

**File:** `web/.vscode/sftp.json` (or `web/sftp.json` for Natizyskunk SFTP)

```json
{
    "name": "SIGNula Production",
    "host": "your-server.com",
    "protocol": "sftp",
    "port": 22,
    "username": "your-username",
    "password": "",
    "privateKeyPath": "~/.ssh/id_rsa",
    "passphrase": "",
    "remotePath": "/home/username/signulo.id",
    "uploadOnSave": false,
    "useTempFile": false,
    "openSsh": false,
    "ignore": [
        ".vscode",
        ".git",
        ".DS_Store",
        "*.log",
        "sftp.json",
        "sftp-config.json"
    ],
    "watcher": {
        "files": "**/*",
        "autoUpload": false,
        "autoDelete": false
    }
}
```

### Update .gitignore

**File:** `.gitignore` (in project root)

Add:
```gitignore
# SFTP Configuration (contains credentials)
web/sftp.json
web/.vscode/sftp.json
web/sftp-config.json

# VS Code
web/.vscode/
```

---

## ✅ Verification Steps

After reorganization, verify everything works:

### 1. Check Directory Structure

```bash
cd "/Users/lance.manasse/Projects/Coding and Development/MWBM Partners Ltd/GitHub/Web/SIGNula"

# Should see this:
tree -L 2 web/
```

Expected output:
```
web/
├── _config/
│   ├── config.php
│   └── database.php
├── _includes/
│   ├── api/
│   ├── auth/
│   ├── layout/
│   ├── security/
│   └── utils/
├── _private/
│   ├── auth.php.example
│   ├── keys/
│   └── logs/
├── _database/
│   └── migrations/
├── _scripts/
│   └── generate_test_api_key.php
├── private_html/
│   ├── api/
│   ├── auth/
│   ├── email/
│   └── security/
├── public_html/
│   ├── api/
│   ├── auth/
│   ├── admin/
│   └── settings/
├── public_html_dev/
└── public_html_landing/
```

### 2. Test Configuration Loading

Create a test file:

**File:** `web/public_html/test-config.php`

```php
<?php
require_once __DIR__ . '/../../_config/config.php';

echo "✅ Configuration loaded successfully!\n\n";
echo "ROOT_DIR: " . ROOT_DIR . "\n";
echo "CONFIG_DIR: " . CONFIG_DIR . "\n";
echo "INCLUDES_DIR: " . INCLUDES_DIR . "\n";
echo "PRIVATE_DIR: " . PRIVATE_DIR . "\n";
echo "PUBLIC_DIR: " . PUBLIC_DIR . "\n";
echo "LOGS_DIR: " . LOGS_DIR . "\n";

// Test if directories exist
echo "\n--- Directory Check ---\n";
echo "CONFIG_DIR exists: " . (is_dir(CONFIG_DIR) ? "✅ YES" : "❌ NO") . "\n";
echo "INCLUDES_DIR exists: " . (is_dir(INCLUDES_DIR) ? "✅ YES" : "❌ NO") . "\n";
echo "PRIVATE_DIR exists: " . (is_dir(PRIVATE_DIR) ? "✅ YES" : "❌ NO") . "\n";
echo "PUBLIC_DIR exists: " . (is_dir(PUBLIC_DIR) ? "✅ YES" : "❌ NO") . "\n";
```

Run:
```bash
php web/public_html/test-config.php
```

Expected: All checks should show ✅ YES

### 3. Test API Endpoint

```bash
# Test the API still works
curl http://localhost/api/v1/health
```

Expected: JSON response with status "healthy"

### 4. Test Include Loading

**File:** `web/public_html/test-includes.php`

```php
<?php
require_once __DIR__ . '/../../_config/config.php';

echo "Testing include loading...\n\n";

$testFiles = [
    INCLUDES_DIR . '/security/SecurityUtils.php',
    INCLUDES_DIR . '/utils/ErrorLogger.php',
    INCLUDES_DIR . '/api/Response.php'
];

foreach ($testFiles as $file) {
    if (file_exists($file)) {
        echo "✅ Found: " . basename($file) . "\n";
        require_once $file;
        echo "   Loaded successfully\n";
    } else {
        echo "❌ Missing: " . $file . "\n";
    }
}
```

---

## 📤 Deployment Workflow

### Option 1: VS Code SFTP Extension (Natizyskunk)

1. Open `web/` folder in VS Code
2. Right-click `web/` folder
3. Select "SFTP: Sync Local → Remote"
4. Confirm sync

### Option 2: Manual SFTP

```bash
# Upload entire web directory
cd "/Users/lance.manasse/Projects/Coding and Development/MWBM Partners Ltd/GitHub/Web/SIGNula"

sftp username@server.com
> cd /path/to/website/root
> put -r web/*
```

### Option 3: rsync (Recommended for initial upload)

```bash
# More efficient, shows progress
rsync -avz --progress \
  web/ \
  username@server.com:/path/to/website/root/
```

---

## 🔒 Server Configuration

On the web server, ensure the document root points to `public_html`:

**Apache (.htaccess or vhost):**
```apache
DocumentRoot /path/to/website/root/public_html

<Directory /path/to/website/root/public_html>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

**Nginx:**
```nginx
root /path/to/website/root/public_html;
index index.php index.html;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

---

## 🎯 Benefits of New Structure

✅ **Cleaner Project Root**
- Documentation, tests, and dev tools separate from server code
- Easier to navigate project locally

✅ **Easier Deployment**
- Just sync `web/` directory
- No risk of uploading tests, docs, or .git by mistake

✅ **Better Security**
- Clear separation of public vs private files
- SFTP config in .gitignore (won't be committed)

✅ **Simpler SFTP Config**
- One sync target: `web/` directory
- No complex ignore rules needed

✅ **Path Consistency**
- All server files use same base directory
- Constants automatically correct

---

## ⚠️ Common Issues & Solutions

### Issue: "config.php not found"

**Cause:** Incorrect path levels
**Solution:** Check `dirname(__DIR__)` levels in files loading config

```php
// From public_html/somefile.php:
require_once __DIR__ . '/../../_config/config.php';  // 2 levels up

// From public_html/subfolder/somefile.php:
require_once dirname(__DIR__, 2) . '/_config/config.php';  // 3 levels up

// From private_html/somefile.php:
require_once dirname(__DIR__) . '/_config/config.php';  // 1 level up
```

### Issue: "Class not found" errors

**Cause:** Include paths changed
**Solution:** Use constants instead of relative paths

```php
// DON'T:
require_once '../_includes/security/SecurityUtils.php';

// DO:
require_once INCLUDES_DIR . '/security/SecurityUtils.php';
```

### Issue: SFTP uploading wrong files

**Cause:** Working from wrong directory
**Solution:** Open `web/` folder in VS Code, not root

```bash
# In VS Code:
File > Open Folder > Select "web/" directory
```

---

## 📝 Checklist

Use this checklist to ensure successful reorganization:

- [ ] **Backup created** (tar.gz of entire project)
- [ ] **web/ directory created**
- [ ] **All server directories moved to web/**
  - [ ] _config
  - [ ] _private
  - [ ] _database
  - [ ] _scripts
  - [ ] private_html
  - [ ] public_html
  - [ ] public_html_dev
  - [ ] public_html_landing
- [ ] **_includes moved from public_html to web/**
- [ ] **config.php paths verified**
- [ ] **All require_once paths updated**
- [ ] **Test files run successfully**
  - [ ] test-config.php passes
  - [ ] test-includes.php passes
  - [ ] API endpoint responds
- [ ] **sftp.json created in web/.vscode/**
- [ ] **.gitignore updated** (web/sftp.json added)
- [ ] **SFTP sync tested** (small test first)
- [ ] **Server document root updated** (points to web/public_html)
- [ ] **Website loads successfully**
- [ ] **All features tested** (login, register, API, etc.)

---

## 🆘 Rollback Procedure

If something goes wrong:

```bash
cd "/Users/lance.manasse/Projects/Coding and Development/MWBM Partners Ltd/GitHub/Web"

# Delete current state
rm -rf SIGNula

# Restore from backup
tar -xzf SIGNula-backup-YYYYMMDD_HHMMSS.tar.gz
```

---

**Reorganization Complete!** 🎉

The SIGNula project is now organized for optimal deployment via SFTP.

---

**Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
