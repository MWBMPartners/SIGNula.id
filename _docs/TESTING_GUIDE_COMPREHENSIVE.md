# 🧪 SIGNula - Comprehensive Testing Guide

**Version:** 2.1.0-beta
**Last Updated:** February 4, 2026
**Status:** Production Testing

---

## 📋 Overview

This comprehensive testing guide covers all implemented features of SIGNula across all development phases. Use this as your primary testing reference.

**What's Covered:**
- ✅ Phase 1: Core Authentication (Login, Registration, MFA, OAuth)
- ✅ Phase 1.5: WebAuthn/PassKeys and Passwordless Login
- ✅ Phase 2: Account Management UI (8 Settings Pages)
- ✅ Phase 3: RESTful API (31 Endpoints)
- ✅ Phase 3.2: Delegate Email Sending
- ✅ Phase 3.3: API Documentation

**Total Test Cases:** 300+

---

## 🚀 Quick Start Testing (30 Minutes)

### Essential Tests

1. **User Registration & Login** (5 min)
   - Register new account
   - Verify email
   - Login with password
   - Logout

2. **MFA Setup** (5 min)
   - Enable TOTP MFA
   - Verify MFA code
   - Generate backup codes

3. **PassKey Registration** (5 min)
   - Register biometric PassKey
   - Login with PassKey

4. **OAuth Account Linking** (5 min)
   - Link Google or Microsoft account
   - Test sign-in with OAuth

5. **Settings Pages** (5 min)
   - Navigate through all 8 settings pages
   - Update profile
   - View activity log

6. **API Health Check** (2 min)
   ```bash
   curl https://signulo.id/api/v1/health
   ```

7. **Delegate Email Setup** (3 min)
   - Navigate to `/settings/email-accounts.php`
   - Connect Microsoft 365 or Google Workspace
   - Verify token storage

---

## 📚 Detailed Testing by Phase

### Phase 1: Core Authentication

**Test Cases:** 60+

#### 1.1 User Registration
- [ ] Register with valid email
- [ ] Register with invalid email format (should fail)
- [ ] Register with existing email (should fail)
- [ ] Register with weak password (should fail)
- [ ] Verify email verification token sent
- [ ] Click verification link
- [ ] Attempt login before email verified (should fail)
- [ ] Login after email verified (should succeed)

#### 1.2 User Login
- [ ] Login with correct credentials
- [ ] Login with incorrect password (should fail)
- [ ] Login with non-existent email (should fail)
- [ ] Test "Remember Me" functionality
- [ ] Test session persistence
- [ ] Test logout functionality

#### 1.3 Password Reset
- [ ] Request password reset
- [ ] Receive reset email
- [ ] Click reset link (should be valid)
- [ ] Set new password
- [ ] Login with new password
- [ ] Test expired reset token (should fail)
- [ ] Test already-used reset token (should fail)

#### 1.4 Multi-Factor Authentication (MFA)
- [ ] Enable TOTP MFA
- [ ] Scan QR code with authenticator app
- [ ] Verify TOTP code
- [ ] Login with MFA (password + TOTP)
- [ ] Test incorrect MFA code (should fail)
- [ ] Generate backup recovery codes
- [ ] Print/download backup codes
- [ ] Login with backup code
- [ ] Verify backup code is marked as used
- [ ] Regenerate backup codes
- [ ] Disable MFA

**Detailed Test Cases:** See [TESTING_GUIDE_PHASE1.md](../TESTING_GUIDE_PHASE1.md) (archived reference)

---

### Phase 1.5: WebAuthn & Passwordless

**Test Cases:** 60+

#### 1.5.1 PassKey Registration
- [ ] Navigate to `/auth/passkey-register.php`
- [ ] Click "Register PassKey"
- [ ] Complete browser/device biometric prompt
- [ ] Verify success message
- [ ] Check `tblWebAuthnCredentials` for new credential
- [ ] Verify credential details (credentialID, publicKey, signCount)

#### 1.5.2 PassKey Authentication
- [ ] Navigate to `/auth/passkey-login.php`
- [ ] Enter email address
- [ ] Click "Login with PassKey"
- [ ] Complete biometric authentication
- [ ] Verify successful login
- [ ] Check session created in `tblSessions`
- [ ] Verify activity logged in `tblActivityLog`

#### 1.5.3 PassKey Management
- [ ] Navigate to `/settings/passkeys.php`
- [ ] View list of registered PassKeys
- [ ] Rename a PassKey
- [ ] View last used timestamp
- [ ] Revoke/delete a PassKey
- [ ] Verify deletion confirmation prompt
- [ ] Confirm credential removed from database

#### 1.5.4 Passwordless Email Login
- [ ] Navigate to `/auth/passwordless-request.php`
- [ ] Enter email address
- [ ] Submit request
- [ ] Check email for magic link
- [ ] Click magic link
- [ ] Verify successful login
- [ ] Test expired token (after 15 min) - should fail
- [ ] Test rate limiting (6 requests) - should block

**Detailed Test Cases:** See [TESTING_GUIDE_PHASE1.md](../TESTING_GUIDE_PHASE1.md) (archived reference)

---

### Phase 2: Account Management UI

**Test Cases:** 100+

#### 2.1 Settings Dashboard (`/settings/`)
- [ ] View user statistics (PassKeys, OAuth accounts, sessions)
- [ ] Check security recommendations
- [ ] View recent activity preview (last 5)
- [ ] Test quick action links
- [ ] Verify responsive design (mobile/tablet/desktop)

#### 2.2 Profile Management (`/settings/profile.php`)
- [ ] Update display name
- [ ] Update username (check uniqueness validation)
- [ ] Change timezone
- [ ] Change email address (requires password)
- [ ] Verify new email verification required
- [ ] View account creation date
- [ ] View last login timestamp

#### 2.3 Security Settings (`/settings/security.php`)
- [ ] View security score (0-100%)
- [ ] Change password
- [ ] Test password strength meter
- [ ] View authentication methods overview
- [ ] Check recent login history (last 10)
- [ ] Test responsive layout

#### 2.4 Connected Accounts (`/settings/connected-accounts.php`)
- [ ] View linked OAuth accounts
- [ ] Link Google account
- [ ] Link Microsoft account
- [ ] Link Apple ID
- [ ] Link Facebook account
- [ ] Link LinkedIn account
- [ ] Link GitHub account
- [ ] Set primary account for avatar
- [ ] View granted permissions
- [ ] Unlink an account
- [ ] Verify unlinking confirmation

#### 2.5 MFA Settings (`/settings/mfa.php`)
- [ ] Enable MFA (see Phase 1.4 tests)
- [ ] View QR code
- [ ] Test manual entry code
- [ ] Generate backup codes
- [ ] Copy backup codes to clipboard
- [ ] Print backup codes
- [ ] Regenerate backup codes
- [ ] Disable MFA

#### 2.6 Activity Log (`/settings/activity.php`)
- [ ] View all activity (paginated, 25 per page)
- [ ] Filter by activity type (login, logout, mfa_enable, etc.)
- [ ] Filter by result (success, failure)
- [ ] Filter by date range
- [ ] Search activity (keyword search)
- [ ] View activity statistics
- [ ] Export to CSV
- [ ] Export to JSON
- [ ] Test pagination (next/prev)

#### 2.7 Privacy Settings (`/settings/privacy.php`)
- [ ] Set profile visibility (Private/Friends/Public)
- [ ] View connected third-party apps
- [ ] Revoke app access
- [ ] Toggle analytics tracking
- [ ] Toggle marketing emails
- [ ] View GDPR data rights information
- [ ] Request data export (if implemented)

#### 2.8 Notification Preferences (`/settings/notifications.php`)
- [ ] Toggle email notifications (security, account, marketing)
- [ ] Toggle push notifications (security, login)
- [ ] Toggle SMS notifications (security)
- [ ] Use "Enable All" quick action
- [ ] Use "Disable All" quick action
- [ ] Use "Security Only" quick action
- [ ] Verify preferences saved

**Detailed Test Cases:** See [TESTING_GUIDE_PHASE2.md](../TESTING_GUIDE_PHASE2.md) (archived reference)

---

### Phase 3: RESTful API

**Test Cases:** 50+

**Testing Tools:** Postman, Insomnia, or cURL

#### 3.1 Authentication Endpoints

**POST /api/v1/auth/register**
```bash
curl -X POST https://signulo.id/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "password": "SecurePass123!",
    "displayName": "Test User"
  }'
```
- [ ] Register with valid data
- [ ] Test validation errors (weak password, invalid email)
- [ ] Test duplicate email registration (should fail)

**POST /api/v1/auth/login**
```bash
curl -X POST https://signulo.id/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "password": "SecurePass123!"
  }'
```
- [ ] Login with correct credentials
- [ ] Login with incorrect password (should fail)
- [ ] Test MFA required response
- [ ] Verify session token returned

**POST /api/v1/auth/logout**
- [ ] Logout with valid session
- [ ] Verify session invalidated

**POST /api/v1/auth/refresh**
- [ ] Refresh expired session
- [ ] Test with invalid token (should fail)

#### 3.2 User Management Endpoints

**GET /api/v1/user/profile**
```bash
curl https://signulo.id/api/v1/user/profile \
  -H "Authorization: Bearer {token}"
```
- [ ] Get profile with valid token
- [ ] Verify all fields returned
- [ ] Test without authentication (should fail 401)

**PUT /api/v1/user/profile**
- [ ] Update display name
- [ ] Update username
- [ ] Update timezone
- [ ] Test validation errors

**GET /api/v1/user/sessions**
- [ ] List all active sessions
- [ ] Verify current session marked
- [ ] Check device information

**DELETE /api/v1/user/session/{id}**
- [ ] Terminate specific session
- [ ] Verify session removed

**GET /api/v1/user/activity**
- [ ] Get activity with default pagination
- [ ] Test filtering (type, result, date)
- [ ] Test pagination parameters
- [ ] Verify activity data format

#### 3.3 MFA Endpoints

**POST /api/v1/mfa/enable**
- [ ] Enable MFA
- [ ] Verify QR code and secret returned

**POST /api/v1/mfa/verify**
- [ ] Verify valid TOTP code
- [ ] Test invalid code (should fail)

**GET /api/v1/mfa/backup-codes**
- [ ] Get backup codes count
- [ ] Verify unused count

**POST /api/v1/mfa/backup-codes/regenerate**
- [ ] Regenerate backup codes
- [ ] Verify old codes invalidated

#### 3.4 OAuth Endpoints

**GET /api/v1/oauth/providers**
- [ ] List all available providers
- [ ] Verify provider details

**GET /api/v1/oauth/linked**
- [ ] List linked accounts
- [ ] Verify account details returned

**POST /api/v1/oauth/link**
- [ ] Initiate OAuth linking
- [ ] Complete OAuth flow
- [ ] Verify account linked

**DELETE /api/v1/oauth/unlink/{provider}**
- [ ] Unlink OAuth account
- [ ] Verify account removed

#### 3.5 Utility Endpoints

**GET /api/v1/health**
```bash
curl https://signulo.id/api/v1/health
```
- [ ] Verify 200 OK response
- [ ] Check database connectivity
- [ ] Verify uptime information

**GET /api/v1/info**
- [ ] Get API version information
- [ ] Verify endpoint counts

**See full API documentation:** [public_html/api/docs/index.html](../public_html/api/docs/index.html)

---

### Phase 3.2: Delegate Email Sending

**Test Cases:** 30+

#### 3.2.1 OAuth Token Management

**Database Setup:**
- [ ] Verify migration 006 applied
- [ ] Check `tblUserOAuthTokens` exists
- [ ] Verify `sendAsEmail` column in `tblEmailQueue`

**UI Testing (`/settings/email-accounts.php`):**
- [ ] Navigate to email accounts page
- [ ] View Microsoft 365 card
- [ ] View Google Workspace card
- [ ] Click "Connect Microsoft 365"
- [ ] Complete OAuth authorization
- [ ] Verify redirect back to page
- [ ] Check success message displayed
- [ ] Verify token stored in database (encrypted)
- [ ] View token expiration date
- [ ] View last used timestamp
- [ ] Click "Disconnect" button
- [ ] Confirm disconnection
- [ ] Verify token removed from database

#### 3.2.2 Microsoft Graph Delegate Sending

**Application Auth (FREE Shared Mailboxes):**
```php
// Send from shared mailbox (no userID)
EmailService::sendTemplateEmail(
    'customer@example.com',
    'welcome_email',
    ['name' => 'John Doe'],
    null,  // No userID = application auth
    5,
    'support@signulo.id'
);
```
- [ ] Send email without userID
- [ ] Verify email sent from shared mailbox
- [ ] Check activity log shows "application" auth mode
- [ ] Verify no OAuth token lookup

**Delegated Auth (User Mailboxes):**
```php
// Send from user's mailbox
EmailService::sendTemplateEmail(
    'prospect@example.com',
    'sales_email',
    ['amount' => '$10,000'],
    $userID,  // User's ID = delegated auth
    5,
    'sales@company.com'
);
```
- [ ] Send email with valid userID
- [ ] Verify OAuth token retrieved
- [ ] Verify email sent from user's mailbox
- [ ] Check activity log shows "delegated" auth mode
- [ ] Test with expired token (should auto-refresh)

**AUTO Mode:**
- [ ] Send with AUTO mode configured
- [ ] Verify falls back to application if user token unavailable
- [ ] Test user token available (uses delegated)

#### 3.2.3 Gmail API Delegate Sending

- [ ] Send email from Gmail service account
- [ ] Verify dynamic JWT impersonation
- [ ] Test per-mailbox token caching
- [ ] Send multiple emails (check cache hits)
- [ ] Verify sendAsEmail parameter passed
- [ ] Check activity logging

#### 3.2.4 Token Refresh Mechanism

- [ ] Manually expire token in database
- [ ] Send email using that mailbox
- [ ] Verify automatic token refresh
- [ ] Check new expiration date
- [ ] Verify activity log shows refresh event

#### 3.2.5 Error Handling

- [ ] Test with revoked OAuth token
- [ ] Test with invalid mailbox
- [ ] Test with missing permissions
- [ ] Verify error messages logged
- [ ] Check fallback behavior

**Documentation:**
- [_docs/SHARED_MAILBOXES_AND_AUTH_MODES.md](../SHARED_MAILBOXES_AND_AUTH_MODES.md)
- [_docs/MICROSOFT_DELEGATE_MAILBOX_SETUP.md](../MICROSOFT_DELEGATE_MAILBOX_SETUP.md)
- [.claude/IMPLEMENTATION_COMPLETE.md](../.claude/IMPLEMENTATION_COMPLETE.md)

---

### Phase 3.3: API Documentation

**Test Cases:** 20+

#### 3.3.1 HTML Documentation (`/api/docs/`)

**Navigation:**
- [ ] Open `https://signulo.id/api/docs/`
- [ ] Verify page loads successfully
- [ ] Check sidebar visible
- [ ] Test collapsible sidebar (mobile)
- [ ] Verify search functionality
- [ ] Search for "authentication" - verify results
- [ ] Click section links - verify smooth scrolling

**Content:**
- [ ] Verify all 31 endpoints documented
- [ ] Check syntax highlighting works
- [ ] Test copy-to-clipboard buttons
- [ ] Verify code examples are correct
- [ ] Check HTTP method badges display (GET, POST, PUT, DELETE)
- [ ] Verify request/response examples formatted
- [ ] Check webhook section complete

**Responsive Design:**
- [ ] Test on desktop (1920x1080)
- [ ] Test on tablet (768x1024)
- [ ] Test on mobile (375x667)
- [ ] Verify sidebar collapses on mobile
- [ ] Check all content readable

#### 3.3.2 Markdown Documentation

- [ ] Open `public_html/api/docs/API_DOCUMENTATION.md`
- [ ] Verify table of contents complete
- [ ] Check all sections present
- [ ] Verify code examples render correctly
- [ ] Test internal links work

#### 3.3.3 API Analysis

- [ ] Review `.claude/API_ANALYSIS.md`
- [ ] Verify security recommendations
- [ ] Check endpoint inventory accurate
- [ ] Review identified gaps

---

## 🔒 Security Testing

### SQL Injection Testing
- [ ] Test all form inputs with SQL injection payloads
- [ ] Verify prepared statements prevent injection
- [ ] Test API endpoints with injection attempts

### XSS Testing
- [ ] Test form inputs with XSS payloads
- [ ] Verify output escaping works
- [ ] Test reflected XSS scenarios
- [ ] Test stored XSS scenarios

### CSRF Testing
- [ ] Verify CSRF tokens on all forms
- [ ] Test form submission without CSRF token (should fail)
- [ ] Test with expired CSRF token (should fail)
- [ ] Verify API endpoints protected

### Authentication Testing
- [ ] Test session fixation attack prevention
- [ ] Test session hijacking prevention
- [ ] Verify secure cookie flags (HttpOnly, Secure)
- [ ] Test password reset token security
- [ ] Verify rate limiting on login attempts

### Authorization Testing
- [ ] Test accessing other users' data (should fail)
- [ ] Verify user can only manage own PassKeys
- [ ] Test privilege escalation attempts
- [ ] Verify OAuth token ownership validation

---

## 🎨 UI/UX Testing

### Responsive Design
- [ ] Test all pages on desktop (1920x1080, 1366x768)
- [ ] Test on tablet (iPad: 768x1024)
- [ ] Test on mobile (iPhone: 375x667, 414x896)
- [ ] Verify navigation works on all devices
- [ ] Check form usability on mobile

### Browser Compatibility
- [ ] Chrome/Edge (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Mobile Safari (iOS)
- [ ] Chrome Mobile (Android)

### Accessibility (WCAG 2.1)
- [ ] Test with screen reader (NVDA/JAWS)
- [ ] Verify keyboard navigation works
- [ ] Check focus indicators visible
- [ ] Verify form labels present
- [ ] Test color contrast ratios
- [ ] Verify alt text on images

---

## ⚡ Performance Testing

### Page Load Times
- [ ] Homepage load < 2 seconds
- [ ] Settings pages load < 1 second
- [ ] API response < 500ms
- [ ] Email queue processing < 5 seconds per email

### Database Performance
- [ ] Query execution times < 100ms
- [ ] Index usage verification
- [ ] Connection pooling working
- [ ] No N+1 query issues

### Email System
- [ ] Email queue processes without timeout
- [ ] Token refresh completes quickly
- [ ] Delegate email sends within 3 seconds

---

## 🔍 Monitoring & Logging

### Activity Logging
- [ ] Verify all actions logged to `tblActivityLog`
- [ ] Check activity types correct
- [ ] Verify IP addresses logged (IPv4 & IPv6)
- [ ] Check user agent strings captured
- [ ] Verify activity details JSON valid

### Error Logging
- [ ] Verify errors logged to `tblErrorLog`
- [ ] Check stack traces captured
- [ ] Verify error severity levels
- [ ] Test error notification system

### OAuth Activity
```sql
-- Check OAuth events
SELECT * FROM tblActivityLog
WHERE activityType LIKE 'oauth_%'
ORDER BY createdAt DESC LIMIT 50;
```
- [ ] Verify OAuth authorization logged
- [ ] Check token refresh events
- [ ] Verify disconnect events logged

---

## 📊 Test Reporting

### Test Execution Tracking

| Phase | Total Tests | Passed | Failed | Blocked | Pass Rate |
|-------|-------------|--------|--------|---------|-----------|
| Phase 1 | 60 | — | — | — | —% |
| Phase 1.5 | 60 | — | — | — | —% |
| Phase 2 | 100 | — | — | — | —% |
| Phase 3 | 50 | — | — | — | —% |
| Phase 3.2 | 30 | — | — | — | —% |
| Phase 3.3 | 20 | — | — | — | —% |
| **Total** | **320** | **—** | **—** | **—** | **—%** |

### Critical Issues Log

| ID | Severity | Component | Issue | Status |
|----|----------|-----------|-------|--------|
| — | — | — | — | — |

---

## 🚀 Pre-Production Checklist

### Configuration
- [ ] Database credentials secure
- [ ] Encryption keys rotated for production
- [ ] SSL certificate installed
- [ ] SMTP configured and tested
- [ ] OAuth credentials for production
- [ ] Rate limiting configured
- [ ] Error reporting configured (not debug mode)

### Security
- [ ] All security tests passed
- [ ] Security audit completed
- [ ] Rate limiting active
- [ ] HTTPS enforced
- [ ] Security headers configured
- [ ] CSRF protection enabled
- [ ] Password policy enforced

### Performance
- [ ] Performance tests passed
- [ ] Database indexes optimized
- [ ] Caching configured
- [ ] CDN configured (if applicable)

### Documentation
- [ ] API documentation published
- [ ] User guides available
- [ ] Support documentation ready
- [ ] Privacy policy published
- [ ] Terms of service published

### Monitoring
- [ ] Error monitoring active
- [ ] Activity logging enabled
- [ ] Uptime monitoring configured
- [ ] Backup system tested
- [ ] Recovery procedures documented

---

## 📞 Support & Resources

**Documentation:**
- [PROJECT_PROGRESS.md](../PROJECT_PROGRESS.md) - Development roadmap
- [README.md](../README.md) - Project overview
- [CHANGELOG.md](../CHANGELOG.md) - Version history
- [API Documentation](../public_html/api/docs/index.html) - API reference

**Quick References:**
- [QUICK_TEST_REFERENCE.md](../QUICK_TEST_REFERENCE.md) - Phase 1 quick test (15 min)
- [QUICK_TEST_REFERENCE_PHASE2.md](../QUICK_TEST_REFERENCE_PHASE2.md) - Phase 2 quick test (20 min)

**Archived Detailed Guides:**
- [TESTING_GUIDE_PHASE1.md](../TESTING_GUIDE_PHASE1.md) - Phase 1 detailed tests
- [TESTING_GUIDE_PHASE2.md](../TESTING_GUIDE_PHASE2.md) - Phase 2 detailed tests

---

**Last Updated:** February 4, 2026
**Version:** 2.1.0-beta
**Maintained by:** SIGNula Development Team

---

**Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
