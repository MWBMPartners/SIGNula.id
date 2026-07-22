---
title: "🔴 CRITICAL: Configure Production Database Credentials"
labels: ["priority: critical", "type: deployment", "status: ready"]
assignees: []
---

## 🎯 Description

Create production database credentials file from template. Required for deployment to staging/production environments.

## 📋 Tasks

- [ ] Copy `web/_private/auth.php.example` to `web/_private/auth.php`
- [ ] Configure production database connection:
  - [ ] `DB_HOST` (e.g., `localhost` or remote host)
  - [ ] `DB_USER` (dedicated MySQL user with appropriate grants)
  - [ ] `DB_PASS` (strong password, min 16 chars)
  - [ ] `DB_NAME` (e.g., `signula_production`)
- [ ] Generate cryptographic keys:
  - [ ] `ENCRYPTION_KEY` (32-byte random hex: `openssl rand -hex 32`)
  - [ ] `ENCRYPTION_SALT` (32-byte random hex: `openssl rand -hex 32`)
- [ ] Set file permissions: `chmod 600 web/_private/auth.php`
- [ ] Verify ownership: `chown www-data:www-data web/_private/auth.php` (or appropriate web user)
- [ ] Test database connection from PHP
- [ ] Add to server backup (but never commit to git)

## ⚠️ Security Notes

- **NEVER commit `auth.php` to version control** (already in `.gitignore`)
- Store backup copy securely (password manager, encrypted vault)
- Use different credentials for staging vs production
- Rotate credentials quarterly
- Grant minimum required database privileges

## 📊 Priority

**Critical** - Cannot deploy without database credentials.

## ⏱️ Estimated Effort

30 minutes (configuration + verification)
