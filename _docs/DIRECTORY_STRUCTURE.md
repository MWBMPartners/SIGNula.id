# Directory Structure Guide

This document explains the SIGNula project directory structure and the purpose of each folder.

---

## Overview

The SIGNula project uses a clear separation between web-accessible files, server-deployed includes, and development-only files.

---

## Directory Structure

```
project_root/
├── public_html/              # WEB-ACCESSIBLE CONTENT ONLY
│   ├── SIGNula.com/         # Marketing website (public-facing)
│   └── SIGNula.id/          # Application (auth, dashboard, API)
│
├── private_html/             # SERVER-DEPLOYED INCLUDES (not directly accessible)
│   ├── api/                 # API classes (Router, Controllers, Response, Validator)
│   ├── auth/                # Authentication handlers (Auth, OAuth, WebAuthn, etc.)
│   ├── email/               # Email service and providers
│   ├── layout/              # Reusable layout components (header, footer, sidebar)
│   ├── security/            # Security utilities (MFA, TOTP, QRCode, SecurityUtils)
│   └── utils/               # Utility classes (ActivityLogger, ErrorLogger)
│
├── _config/                  # DEVELOPMENT ONLY - Configuration files
├── _private/                 # DEVELOPMENT ONLY - Private keys, credentials
├── _sql/                     # DEVELOPMENT ONLY - SQL schema files
├── _migrations/              # DEVELOPMENT ONLY - Database migrations
├── _scripts/                 # DEVELOPMENT ONLY - Build/deployment scripts
└── _docs/                    # DEVELOPMENT ONLY - Project documentation
```

---

## Directory Purposes

### 1. public_html/ (Web-Accessible)

**Purpose:** Contains all files that should be directly accessible via web browser

**Upload to server:** ✅ YES

**Directly accessible:** ✅ YES

**Contents:**
- HTML/PHP pages that users navigate to
- CSS, JavaScript, images, and other assets
- Two separate domains:
  - **SIGNula.com** - Marketing/promotional website
  - **SIGNula.id** - User accounts, dashboard, API endpoints

**Examples:**
- `public_html/SIGNula.com/index.php` → https://signula.com/
- `public_html/SIGNula.id/login.php` → https://signula.id/login
- `public_html/SIGNula.com/assets/css/main.css` → https://signula.com/assets/css/main.css

---

### 2. private_html/ (Server-Deployed Includes)

**Purpose:** Contains PHP classes and components that are included/required by web pages but should NOT be directly accessible via browser

**Upload to server:** ✅ YES

**Directly accessible:** ❌ NO (place outside web root or protect with .htaccess)

**Contents:**
- PHP classes (Auth, OAuth, Email, API controllers, etc.)
- Reusable layout components (header, footer, sidebars)
- Security utilities (MFA, TOTP, WebAuthn handlers)
- Email service providers
- Utility classes (logging, validation)

**How files use it:**
```php
// Pages reference private_html files using require/include
require_once SIGNULA_ROOT . '/private_html/auth/Auth.php';
require_once SIGNULA_ROOT . '/private_html/layout/public-header.php';
```

**Server Configuration:**
- Place `private_html/` OUTSIDE the web root (e.g., `/home/user/private_html/`)
- OR place it inside web root with .htaccess protection:
  ```apache
  # In private_html/.htaccess
  Order deny,allow
  Deny from all
  ```

---

### 3. _config/ (Development Only)

**Purpose:** Configuration files for development environment

**Upload to server:** ❌ NO (or create separate production config)

**Contents:**
- `config.php` - Application configuration
- Database connection settings (dev credentials)
- Development-specific settings

**Note:** Production server should have its own config with production credentials

---

### 4. _private/ (Development Only)

**Purpose:** Private keys, credentials, and sensitive files for development

**Upload to server:** ❌ NO (server should have its own keys)

**Contents:**
- OAuth client secrets (development)
- API keys for development
- Private encryption keys

**Note:** These files should be in `.gitignore` and never committed to version control

---

### 5. _sql/ (Development Only)

**Purpose:** SQL schema files and database structure

**Upload to server:** ❌ NO

**Contents:**
- `001_initial_schema.sql` - Initial database schema
- `002_oauth_multi_account.sql` - OAuth enhancements
- `003_phase3_api_migration.sql` - API tables
- Table definitions and schema

**Note:** Use migrations (see _migrations/) to apply changes to production database

---

### 6. _migrations/ (Development Only)

**Purpose:** Database migration scripts

**Upload to server:** ❌ NO (or use deployment tool)

**Contents:**
- Migration scripts to update database schema
- Version tracking for database changes

**Note:** May be used by deployment tools, but not needed on production server

---

### 7. _scripts/ (Development Only)

**Purpose:** Build, deployment, and utility scripts

**Upload to server:** ❌ NO

**Contents:**
- `version-bump.sh` - Version management
- `create-release.sh` - GitHub release creation
- Build and deployment scripts

---

### 8. _docs/ (Development Only)

**Purpose:** Project documentation

**Upload to server:** ❌ NO

**Contents:**
- Technical documentation
- API documentation
- Development guides
- This file!

---

## Migration from _includes/

**Historical Note:** Previously, all server-deployed includes were in `_includes/`. This has been **moved to `private_html/`** to better indicate its purpose.

**What changed:**
- `_includes/` → `private_html/` (renamed and moved)
- All `require_once` statements updated to reference `private_html/` instead of `_includes/`
- Purpose clarified: `private_html/` = server-deployed includes

**Why the change:**
- The underscore prefix (`_includes`) suggested "development-only"
- But these files ARE deployed to the server (just not directly accessible)
- `private_html/` better communicates "server files that aren't directly accessible"
- Clearer separation between dev-only files and server-deployed files

---

## Deployment Checklist

When deploying to production server:

### Upload These:
- ✅ `public_html/` - All web-accessible files
- ✅ `private_html/` - All include files (place outside web root if possible)

### Do NOT Upload:
- ❌ `_config/` - Use production config instead
- ❌ `_private/` - Use production keys instead
- ❌ `_sql/` - Not needed on production
- ❌ `_migrations/` - Not needed (unless using deployment tool)
- ❌ `_scripts/` - Development tools only
- ❌ `_docs/` - Documentation only
- ❌ `.git/` - Version control only
- ❌ `README.md`, `CHANGELOG.md`, `VERSION` - Optional

### Create on Server:
- Production `config.php` with production database credentials
- Production API keys and OAuth secrets
- Proper file permissions (750 for directories, 640 for PHP files)
- `.htaccess` to protect `private_html/` if inside web root

---

## File Path Examples

### Development:
```php
// Root directory
/Users/lance.manasse/Projects/SIGNula/SIGNula.id/

// Web pages
/Users/lance.manasse/Projects/SIGNula/SIGNula.id/public_html/SIGNula.com/index.php
/Users/lance.manasse/Projects/SIGNula/SIGNula.id/public_html/SIGNula.id/login.php

// Server includes
/Users/lance.manasse/Projects/SIGNula/SIGNula.id/private_html/auth/Auth.php
/Users/lance.manasse/Projects/SIGNula/SIGNula.id/private_html/layout/public-header.php

// Dev-only files
/Users/lance.manasse/Projects/SIGNula/SIGNula.id/_config/config.php
/Users/lance.manasse/Projects/SIGNula/SIGNula.id/_docs/DIRECTORY_STRUCTURE.md
```

### Production Server (Dreamhost Shared Hosting):
```
// Root directory (outside web root)
/home/username/signula/

// Web root (mapped to signula.com)
/home/username/signula.com/
  → Contains public_html/SIGNula.com/ contents

// Web root (mapped to signula.id)
/home/username/signula.id/
  → Contains public_html/SIGNula.id/ contents

// Server includes (outside web root)
/home/username/signula/private_html/
  → Contains all private_html/ contents

// Production config
/home/username/signula/config.php
  → Production configuration with prod credentials
```

---

## Best Practices

1. **Never mix concerns:** Keep web-accessible files in `public_html/`, server includes in `private_html/`, dev files in `_*` folders

2. **Protect private_html:** Always place outside web root or protect with .htaccess

3. **Use SIGNULA_ROOT constant:** Always reference files using `SIGNULA_ROOT` for path portability:
   ```php
   require_once SIGNULA_ROOT . '/private_html/auth/Auth.php';
   ```

4. **Separate dev and production configs:** Never use development credentials in production

5. **Document changes:** Update this file when directory structure changes

---

## Questions?

If you're unsure where a file should go:

- **Can users navigate to it in browser?** → `public_html/`
- **Is it included by PHP pages but not directly accessed?** → `private_html/`
- **Is it only used during development?** → `_*` folders
- **Is it sensitive credentials?** → `_private/` (dev) or outside web root (prod)

---

**Last Updated:** 2026-02-03
**Version:** 1.0.0
