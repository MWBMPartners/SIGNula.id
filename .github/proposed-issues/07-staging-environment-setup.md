---
title: "🟡 HIGH: Deploy to Staging Environment"
labels: ["priority: high", "type: deployment", "status: ready"]
assignees: []
---

## 🎯 Description

Set up staging environment with v2.7.0 complete install. Run installation wizard and deploy all migrations.

## 📋 Tasks

### 1. Staging Server Setup
- [ ] Create subdomain: `staging.signula.id`
- [ ] Configure DNS A record
- [ ] Set up SSL certificate (Let's Encrypt)
- [ ] Create staging database: `signula_staging`
- [ ] Create database user with appropriate grants

### 2. Database Installation
- [ ] Upload `_database/signula_complete_install_v2.7.0.sql`
- [ ] Import schema:
  ```bash
  mysql -u signula_staging -p signula_staging < signula_complete_install_v2.7.0.sql
  ```
- [ ] Verify table count (should be 49 tables)
- [ ] Check `tblMigrations` for migration tracking table

### 3. File Deployment
- [ ] Upload codebase to staging server
- [ ] Set directory permissions:
  ```bash
  chmod 755 web/public_html web/_private
  chmod 600 web/_private/auth.php
  chmod 777 web/_logs web/_uploads
  ```
- [ ] Configure `web/_private/auth.php` with staging credentials
- [ ] Update `.htaccess` with staging domain

### 4. Installation Wizard
- [ ] Access: `https://staging.signula.id/install/`
- [ ] Complete wizard steps:
  - [ ] Database connection test
  - [ ] Admin account creation
  - [ ] Email provider configuration
  - [ ] OAuth providers (optional for staging)
  - [ ] Payment providers (sandbox mode)
  - [ ] System settings (timezone, locale)
- [ ] Verify installation success

### 5. Configuration Verification
- [ ] Test database connection
- [ ] Verify encryption key working
- [ ] Check `tblSettings` populated correctly
- [ ] Test email delivery (staging mailbox)
- [ ] Verify cron jobs accessible (if possible)

### 6. OAuth Provider Testing (11 providers)
- [ ] Google (Workspace + Personal)
- [ ] Microsoft (365 + Personal)
- [ ] Apple ID
- [ ] Facebook/Instagram
- [ ] LinkedIn
- [ ] GitHub
- [ ] Yahoo
- [ ] Amazon
- [ ] PayPal
- [ ] WordPress.com
- [ ] LastPass

### 7. Smoke Testing
- [ ] User registration
- [ ] Email verification
- [ ] Login (local + OAuth)
- [ ] MFA enrollment
- [ ] Password reset
- [ ] Profile updates
- [ ] Organization creation
- [ ] API key generation
- [ ] Webhook creation

## ✅ Acceptance Criteria

- [ ] Staging environment fully deployed
- [ ] All 24 migrations applied (`SELECT * FROM tblMigrations`)
- [ ] Installation wizard completed successfully
- [ ] Database connectivity verified
- [ ] Email delivery working
- [ ] OAuth flows functional (at least 2 providers)
- [ ] No PHP errors in logs
- [ ] Clean URLs working
- [ ] HTTPS enabled with valid certificate

## 🔗 Deployment Checklist

- [ ] Backup staging database before changes
- [ ] Document staging credentials securely
- [ ] Set up staging-specific error logging
- [ ] Configure staging banner/notice in UI
- [ ] Disable production integrations (use sandbox)

## 📊 Priority

**High** - Must test in staging before production.

## ⏱️ Estimated Effort

4-6 hours (deployment + wizard + verification)
