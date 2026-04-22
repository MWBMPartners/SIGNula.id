---
title: "🔴 CRITICAL: Configure Email Service Provider"
labels: ["priority: critical", "type: deployment", "status: ready"]
assignees: []
---

## 🎯 Description

Configure email service provider credentials to enable transactional emails (verification, password reset, notifications, etc.).

## 📋 Provider Options

Choose **one** primary provider:

### Option A: SMTP (Generic)
- [ ] Host, Port, Username, Password
- [ ] TLS/SSL configuration
- [ ] From address/name

### Option B: SendGrid
- [ ] API Key
- [ ] From address (verified sender)
- [ ] Domain authentication (SPF/DKIM)

### Option C: Microsoft Graph API
- [ ] Client ID, Client Secret, Tenant ID
- [ ] Mailbox: `noreply@signula.id`
- [ ] OAuth 2.0 consent grant

### Option D: Gmail API
- [ ] Service account credentials (JSON)
- [ ] Domain-wide delegation
- [ ] From address

## 📋 Configuration Tasks

- [ ] Choose provider and obtain credentials
- [ ] Add credentials to `tblSettings` (encrypted):
  ```sql
  -- Example for SendGrid
  INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
  ('email_provider', 'sendgrid', 0),
  ('sendgrid_api_key', '<encrypted_key>', 1),
  ('email_from_address', 'noreply@signula.id', 0),
  ('email_from_name', 'SIGNula', 0);
  ```
- [ ] Configure SPF record: `v=spf1 include:sendgrid.net ~all`
- [ ] Configure DKIM records (provider-specific)
- [ ] Configure DMARC: `v=DMARC1; p=quarantine; rua=mailto:dmarc@signula.id`
- [ ] Test email delivery (all templates):
  - [ ] Welcome email
  - [ ] Email verification
  - [ ] Password reset
  - [ ] MFA setup
  - [ ] Account lockout
  - [ ] Organization invitations
- [ ] Monitor bounce/complaint rates
- [ ] Set up dedicated IP (optional, high-volume only)

## ✅ Acceptance Criteria

- [ ] Provider credentials configured and encrypted
- [ ] SPF/DKIM/DMARC DNS records verified
- [ ] Test emails delivered successfully to Gmail, Outlook, Yahoo
- [ ] Email queue processor functioning
- [ ] No emails landing in spam folders

## 📊 Priority

**Critical** - Required for user registration, password resets, MFA.

## ⏱️ Estimated Effort

2-3 hours (provider setup + DNS + testing)
