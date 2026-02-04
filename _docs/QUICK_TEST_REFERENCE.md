# ⚡ SIGNula - Quick Test Reference

**Version:** 2.1.0-beta
**Test Duration:** 30-45 minutes
**Purpose:** Rapid validation of core functionality

---

## 🎯 Overview

This quick test covers essential functionality across all phases. Use this for:
- Rapid smoke testing after deployments
- Validation of new installations
- Quick regression testing
- Demo preparation

**Coverage:**
- ✅ Core Auth (Login, Registration, MFA)
- ✅ WebAuthn/PassKeys
- ✅ OAuth Account Linking
- ✅ Settings Pages
- ✅ RESTful API
- ✅ Delegate Email

---

## 🚀 Quick Test Checklist (30 Minutes)

### 1. User Registration & Login (5 min)

**Steps:**
1. Navigate to `/auth/register.php`
2. Register with:
   - Email: `quicktest@example.com`
   - Password: `QuickTest123!`
   - Display Name: `Quick Test User`
3. Check email for verification link
4. Click verification link
5. Login at `/auth/login.php`
6. Verify dashboard loads
7. Logout

**Expected Results:**
- ✅ Registration succeeds
- ✅ Verification email received
- ✅ Email verified successfully
- ✅ Login successful
- ✅ Session created

---

### 2. Multi-Factor Authentication (5 min)

**Steps:**
1. Login to account
2. Navigate to `/settings/mfa.php`
3. Click "Enable Two-Factor Authentication"
4. Scan QR code with Google Authenticator
5. Enter 6-digit TOTP code
6. Verify "MFA Enabled" confirmation
7. Generate backup codes
8. Download/copy backup codes
9. Logout
10. Login again (should require TOTP code)
11. Enter valid TOTP code
12. Verify login succeeds with MFA

**Expected Results:**
- ✅ MFA enabled successfully
- ✅ Backup codes generated (10 codes)
- ✅ Login requires TOTP code
- ✅ Valid TOTP code accepted

---

### 3. PassKey Registration & Login (5 min)

**Steps:**
1. Navigate to `/auth/passkey-register.php`
2. Click "Register PassKey"
3. Complete device biometric prompt (TouchID/FaceID/Windows Hello)
4. Verify "PassKey registered successfully"
5. Navigate to `/settings/passkeys.php`
6. Verify PassKey listed
7. Logout
8. Navigate to `/auth/passkey-login.php`
9. Enter email
10. Click "Login with PassKey"
11. Complete biometric authentication
12. Verify login successful

**Expected Results:**
- ✅ PassKey registered
- ✅ PassKey visible in management page
- ✅ PassKey login successful
- ✅ No password required

---

### 4. OAuth Account Linking (5 min)

**Steps:**
1. Login to account
2. Navigate to `/settings/connected-accounts.php`
3. Click "Connect" on Google or Microsoft
4. Complete OAuth authorization flow
5. Verify redirect back to SIGNula
6. Verify account listed as connected
7. View account details (email, permissions)
8. Set as primary account (optional)
9. Test "Disconnect" button (optional)

**Expected Results:**
- ✅ OAuth flow completes successfully
- ✅ Account appears in connected list
- ✅ Account details displayed
- ✅ Can disconnect account

---

### 5. Settings Pages Navigation (5 min)

**Navigate through all settings pages and verify no errors:**

1. **Dashboard** (`/settings/`)
   - ✅ Statistics display
   - ✅ Security recommendations show
   - ✅ Recent activity preview

2. **Profile** (`/settings/profile.php`)
   - ✅ Can view profile info
   - ✅ Update display name works

3. **Security** (`/settings/security.php`)
   - ✅ Security score displays (0-100%)
   - ✅ Can view login history

4. **Connected Accounts** (`/settings/connected-accounts.php`)
   - ✅ Linked accounts display

5. **MFA** (`/settings/mfa.php`)
   - ✅ MFA status shows correctly

6. **PassKeys** (`/settings/passkeys.php`)
   - ✅ PassKey list displays

7. **Activity** (`/settings/activity.php`)
   - ✅ Activity log loads
   - ✅ Can filter by type
   - ✅ Export to CSV works

8. **Privacy** (`/settings/privacy.php`)
   - ✅ Privacy settings display

9. **Notifications** (`/settings/notifications.php`)
   - ✅ Notification preferences load

**Expected Results:**
- ✅ All pages load without errors
- ✅ All forms functional
- ✅ Responsive design works

---

### 6. RESTful API Testing (3 min)

**Using cURL or Postman:**

**Health Check:**
```bash
curl https://signulo.id/api/v1/health
```
Expected: 200 OK with {"status": "healthy"}

**Login:**
```bash
curl -X POST https://signulo.id/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "quicktest@example.com",
    "password": "QuickTest123!"
  }'
```
Expected: 200 OK with access_token

**Get Profile:**
```bash
curl https://signulo.id/api/v1/user/profile \
  -H "Authorization: Bearer {token_from_login}"
```
Expected: 200 OK with user profile data

**Expected Results:**
- ✅ API health check responds
- ✅ API login returns token
- ✅ Profile endpoint returns data

---

### 7. Delegate Email Setup (3 min)

**Steps:**
1. Login to account
2. Navigate to `/settings/email-accounts.php`
3. View Microsoft 365 and Google Workspace cards
4. Click "Connect Microsoft 365" (or Google)
5. Complete OAuth authorization
6. Verify redirect back to page
7. Check "Connected" status
8. View token expiration date
9. Check token in database:
   ```sql
   SELECT * FROM tblUserOAuthTokens WHERE userID = {your_user_id};
   ```

**Expected Results:**
- ✅ OAuth flow completes
- ✅ Token stored (encrypted)
- ✅ Expiration date shown
- ✅ Can disconnect account

---

### 8. Passwordless Email Login (2 min)

**Steps:**
1. Logout from account
2. Navigate to `/auth/passwordless-request.php`
3. Enter email: `quicktest@example.com`
4. Submit request
5. Check email for magic link
6. Click magic link
7. Verify automatic login
8. Check activity log for "passwordless_login" event

**Expected Results:**
- ✅ Magic link email received
- ✅ Link valid (within 15 min)
- ✅ Automatic login successful
- ✅ Activity logged

---

### 9. Activity Log Verification (2 min)

**Steps:**
1. Navigate to `/settings/activity.php`
2. Review recent activities from tests
3. Verify all test actions logged:
   - User registration
   - Email verification
   - Login attempts
   - MFA enable
   - PassKey registration
   - OAuth linking
   - Passwordless login
4. Test filtering by activity type
5. Test date range filter
6. Export activity to CSV

**Expected Results:**
- ✅ All activities logged
- ✅ Filtering works
- ✅ Export successful

---

### 10. API Documentation Check (2 min)

**Steps:**
1. Navigate to `https://signulo.id/docs/api/`
2. Verify page loads
3. Test search functionality (search for "login")
4. Click a navigation link
5. Verify smooth scrolling
6. Test copy button on code example
7. Check mobile responsive (resize browser)

**Expected Results:**
- ✅ Documentation loads
- ✅ Search works
- ✅ Navigation works
- ✅ Copy buttons work
- ✅ Mobile responsive

---

## 🔥 Critical Path Test (15 Minutes)

**For ultra-rapid validation, test only these critical features:**

1. **Register + Login** (3 min)
   - Register new account
   - Verify email
   - Login

2. **Enable MFA** (3 min)
   - Enable TOTP MFA
   - Login with MFA

3. **PassKey** (3 min)
   - Register PassKey
   - Login with PassKey

4. **API Health** (1 min)
   ```bash
   curl https://signulo.id/api/v1/health
   ```

5. **Settings Pages** (3 min)
   - Navigate through 3 key pages (Dashboard, Profile, Security)

6. **Activity Log** (2 min)
   - View activity log
   - Verify actions logged

---

## ✅ Pass/Fail Criteria

### All Tests Pass If:
- ✅ No 500 Internal Server Errors
- ✅ All forms submit successfully
- ✅ Authentication works (password, MFA, PassKey, OAuth, passwordless)
- ✅ All settings pages load
- ✅ API returns valid responses
- ✅ Activity logging works
- ✅ Email sending works (verify inbox)

### Immediate Investigation Required If:
- ❌ Database connection errors
- ❌ Email sending fails
- ❌ Authentication fails
- ❌ API returns 500 errors
- ❌ PassKey registration fails
- ❌ OAuth flows break

---

## 📊 Test Results Template

**Date:** _____________
**Tester:** _____________
**Version:** 2.1.0-beta
**Environment:** [ ] Local [ ] Staging [ ] Production

| Test | Status | Notes |
|------|--------|-------|
| Registration & Login | ⬜ | |
| Multi-Factor Auth | ⬜ | |
| PassKey | ⬜ | |
| OAuth Linking | ⬜ | |
| Settings Pages | ⬜ | |
| API Testing | ⬜ | |
| Delegate Email | ⬜ | |
| Passwordless Login | ⬜ | |
| Activity Log | ⬜ | |
| API Documentation | ⬜ | |

**Overall Result:** [ ] PASS [ ] FAIL

**Issues Found:**
-

**Notes:**
-

---

## 🔗 Additional Resources

**Comprehensive Testing:**
- [_docs/TESTING_GUIDE_COMPREHENSIVE.md](_docs/TESTING_GUIDE_COMPREHENSIVE.md) - Full test suite (300+ tests)

**Archived Phase-Specific Guides:**
- [TESTING_GUIDE_PHASE1.md](../TESTING_GUIDE_PHASE1.md) - WebAuthn & Passwordless (60+ tests)
- [TESTING_GUIDE_PHASE2.md](../TESTING_GUIDE_PHASE2.md) - Account Management UI (100+ tests)

**Documentation:**
- [README.md](../README.md) - Project overview
- [PROJECT_PROGRESS.md](../PROJECT_PROGRESS.md) - Development status
- [API Documentation](../public_html/docs/api/index.html) - API reference

---

**Last Updated:** February 4, 2026
**Maintained by:** SIGNula Development Team

---

**Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
