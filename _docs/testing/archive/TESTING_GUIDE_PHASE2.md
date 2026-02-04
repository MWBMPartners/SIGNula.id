# 🧪 SIGNula Phase 2 Testing Guide
## Account Management UI - Comprehensive Testing Documentation

**Version:** 1.0.0
**Last Updated:** February 2, 2026
**Phase:** 2 - Account Management UI
**Status:** Ready for Testing

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Prerequisites](#prerequisites)
3. [Testing Environment Setup](#testing-environment-setup)
4. [Test Case Structure](#test-case-structure)
5. [Settings Dashboard Tests](#settings-dashboard-tests)
6. [Profile Management Tests](#profile-management-tests)
7. [Security Settings Tests](#security-settings-tests)
8. [Connected Accounts Tests](#connected-accounts-tests)
9. [MFA Management Tests](#mfa-management-tests)
10. [Activity Log Tests](#activity-log-tests)
11. [Privacy Settings Tests](#privacy-settings-tests)
12. [Notification Preferences Tests](#notification-preferences-tests)
13. [Cross-Page Integration Tests](#cross-page-integration-tests)
14. [Security Testing](#security-testing)
15. [UI/UX Testing](#uiux-testing)
16. [Performance Testing](#performance-testing)
17. [Test Results Template](#test-results-template)

---

## 🎯 Overview

This document provides comprehensive testing procedures for Phase 2 of SIGNula: Account Management UI. Phase 2 includes 8 settings pages that allow users to manage their accounts, security settings, privacy preferences, and notifications.

### What's Being Tested

**Phase 2 Components:**
- Settings Dashboard (index.php)
- Profile Management (profile.php)
- Security Settings (security.php)
- OAuth Connected Accounts (connected-accounts.php)
- MFA Management (mfa.php)
- Activity Log Viewer (activity.php)
- Privacy Settings (privacy.php)
- Notification Preferences (notifications.php)

**Key Features:**
- Account information display and updates
- Security score calculation
- OAuth account linking/unlinking
- MFA enable/disable with backup codes
- Activity log filtering and export
- Privacy controls
- Notification preferences

---

## ✅ Prerequisites

### 1. Completed Phase 1 Setup

Ensure Phase 1 (WebAuthn/PassKeys & Passwordless Login) is fully implemented and functional:

```bash
php _tests/verify-phase1-setup.php
```

Expected output: All checks should pass ✅

### 2. Test User Accounts

Create the following test accounts:

**Account 1: Full Feature User**
- Email: test-full@example.com
- Password: TestPass123!
- MFA: Enabled
- PassKeys: 2 registered
- OAuth: Google + Microsoft linked
- Purpose: Test all features

**Account 2: Minimal User**
- Email: test-minimal@example.com
- Password: TestPass123!
- MFA: Disabled
- PassKeys: None
- OAuth: None linked
- Purpose: Test feature enabling

**Account 3: Security Focused**
- Email: test-security@example.com
- Password: TestPass123!
- MFA: Enabled
- PassKeys: 3 registered
- OAuth: All providers linked
- Purpose: Test security features

### 3. Browser Setup

Test on multiple browsers:
- ✅ Chrome/Edge (Chromium) - Latest
- ✅ Firefox - Latest
- ✅ Safari - Latest (macOS/iOS)

Screen sizes to test:
- 📱 Mobile (375px - 767px)
- 📱 Tablet (768px - 1023px)
- 💻 Desktop (1024px+)

### 4. Database State

Ensure clean database with:
- All migrations applied (001-005)
- tblSettings populated with default values
- No orphaned records

---

## 🧪 Test Case Structure

Each test case follows this format:

**Test ID:** PHASE2-[PAGE]-[NUMBER]
**Priority:** Critical/High/Medium/Low
**Type:** Functional/Security/UI/Performance

**Steps:**
1. Action to perform
2. Expected result

**Pass Criteria:** What must happen for test to pass
**Notes:** Additional information

---

## 🏠 Settings Dashboard Tests

### Test Group: Dashboard Display

#### PHASE2-DASH-001: Dashboard Load
**Priority:** Critical
**Type:** Functional

**Steps:**
1. Login with test-full@example.com
2. Navigate to `/settings/`
3. Verify page loads without errors

**Pass Criteria:**
- ✅ Page loads in < 2 seconds
- ✅ Welcome banner displays user's name
- ✅ Avatar displays correctly (from OAuth or default)
- ✅ All 4 quick stats cards display
- ✅ Security recommendations section appears
- ✅ Recent activity preview shows last 5 activities
- ✅ No PHP errors in console/logs

---

#### PHASE2-DASH-002: Quick Stats Display
**Priority:** High
**Type:** Functional

**Steps:**
1. On settings dashboard
2. Verify all 4 stats cards display correct information

**Pass Criteria:**
- ✅ PassKeys count matches tblWebAuthnCredentials count
- ✅ MFA status shows "Enabled" or "Disabled" correctly
- ✅ Connected Accounts shows count from tblOAuthAccounts
- ✅ Activity count shows total from tblActivityLog
- ✅ Numbers are accurate and not hardcoded

---

#### PHASE2-DASH-003: Security Recommendations
**Priority:** High
**Type:** Functional

**Steps:**
1. Login with test-minimal@example.com (no MFA, no PassKeys)
2. Navigate to `/settings/`
3. Check security recommendations

**Pass Criteria:**
- ✅ "Enable Two-Factor Authentication" recommendation shows
- ✅ "Register a PassKey" recommendation shows
- ✅ Recommendations link to correct pages
- ✅ With test-full@example.com, recommendations change appropriately

---

#### PHASE2-DASH-004: Recent Activity Preview
**Priority:** Medium
**Type:** Functional

**Steps:**
1. Perform 10+ different activities (login, profile update, etc.)
2. Navigate to `/settings/`
3. Check recent activity section

**Pass Criteria:**
- ✅ Shows last 5 activities
- ✅ Activities are sorted by date (newest first)
- ✅ Activity types display correctly
- ✅ "View All Activity" link works
- ✅ Timestamps are formatted correctly

---

### Test Group: Dashboard Navigation

#### PHASE2-DASH-005: Settings Sidebar Navigation
**Priority:** Critical
**Type:** UI

**Steps:**
1. On settings dashboard
2. Click each navigation item in sidebar
3. Verify navigation works

**Pass Criteria:**
- ✅ All 9 settings links work
- ✅ Active page is highlighted
- ✅ Sidebar remains visible on all pages
- ✅ Danger zone section is visually distinct (red text)

---

## 👤 Profile Management Tests

### Test Group: Profile Display

#### PHASE2-PROF-001: Profile Information Display
**Priority:** High
**Type:** Functional

**Steps:**
1. Login and navigate to `/settings/profile`
2. Verify profile information displays

**Pass Criteria:**
- ✅ Display name field shows current value
- ✅ Username field shows current value
- ✅ Email address displays (read-only in account info section)
- ✅ Timezone dropdown shows current selection
- ✅ Account created date displays correctly
- ✅ Last login time displays correctly

---

### Test Group: Profile Updates

#### PHASE2-PROF-002: Update Display Name
**Priority:** High
**Type:** Functional

**Steps:**
1. Navigate to `/settings/profile`
2. Change display name to "Test User Updated"
3. Click "Save Profile Changes"

**Pass Criteria:**
- ✅ Success message displays
- ✅ Name updates in database (tblUsers.displayName)
- ✅ Activity logged in tblActivityLog (type: profile_update)
- ✅ Header updates with new name (if displayed)
- ✅ Settings dashboard welcome banner updates

**Test Data:**
- Valid: "John Doe", "Jane Smith-Jones", "José García"
- Invalid: "" (empty), "A" (too short), 150+ chars

---

#### PHASE2-PROF-003: Update Username
**Priority:** High
**Type:** Functional

**Steps:**
1. Navigate to `/settings/profile`
2. Change username to "testuser_new"
3. Click "Save Profile Changes"

**Pass Criteria:**
- ✅ Success message displays
- ✅ Username updates in database (tblUsers.username)
- ✅ Activity logged
- ✅ Username is unique (check duplicate handling)

**Test Data:**
- Valid: "testuser123", "user_name", "john.doe"
- Invalid: "ab" (too short), "user name" (space), "user@name" (special chars)

---

#### PHASE2-PROF-004: Update Timezone
**Priority:** Medium
**Type:** Functional

**Steps:**
1. Navigate to `/settings/profile`
2. Select different timezone (e.g., "America/New_York")
3. Click "Save Profile Changes"

**Pass Criteria:**
- ✅ Timezone updates in database
- ✅ Success message displays
- ✅ Timestamps elsewhere reflect new timezone
- ✅ Activity logged

---

#### PHASE2-PROF-005: Change Email Address
**Priority:** Critical
**Type:** Functional + Security

**Steps:**
1. Navigate to `/settings/profile`
2. In "Change Email Address" section, enter new email
3. Enter current password
4. Click "Change Email"

**Pass Criteria:**
- ✅ Password verification required
- ✅ Email updates in database (tblUsers.email)
- ✅ emailVerified set to 0 (unverified)
- ✅ Verification email sent to new address
- ✅ Activity logged (email_change)
- ✅ User can still login with new email
- ✅ Old email no longer works for login

**Security Tests:**
- ❌ Wrong password = Error message, no update
- ❌ Email already in use = Error message
- ✅ SQL injection attempts blocked

---

### Test Group: Profile Validation

#### PHASE2-PROF-006: Field Validation
**Priority:** High
**Type:** Functional

**Test Cases:**

**Display Name:**
- ❌ Empty → "Display name is required"
- ❌ < 2 chars → "Display name must be at least 2 characters"
- ❌ > 100 chars → "Display name must not exceed 100 characters"
- ✅ Valid name → Saves successfully

**Username:**
- ❌ Empty → "Username is required"
- ❌ < 3 chars → "Username must be at least 3 characters"
- ❌ Contains spaces → "Username cannot contain spaces"
- ❌ Invalid chars → "Username can only contain letters, numbers, dots, and underscores"
- ❌ Already taken → "Username already in use"
- ✅ Valid username → Saves successfully

**Email:**
- ❌ Invalid format → "Please enter a valid email address"
- ❌ Already exists → "Email address already in use"
- ❌ Empty → "Email address is required"
- ✅ Valid email → Saves successfully

---

## 🔒 Security Settings Tests

### Test Group: Security Score

#### PHASE2-SEC-001: Security Score Calculation
**Priority:** High
**Type:** Functional

**Steps:**
1. Login with different test accounts
2. Navigate to `/settings/security`
3. Verify security score calculation

**Pass Criteria:**

**Test Account: test-minimal@example.com**
- ✅ Score = 25% (password only)
- ✅ Shows as "Weak" in red

**Test Account: test-full@example.com**
- Password: +25% ✅
- MFA enabled: +25% ✅
- PassKey registered: +25% ✅
- Email verified: +25% ✅
- **Total: 100% "Excellent" in green** ✅

**Partial Security:**
- Password + Email verified = 50% "Fair" (yellow)
- Password + MFA = 50% "Fair" (yellow)
- Password + MFA + Email = 75% "Good" (blue)

---

#### PHASE2-SEC-002: Security Score Visual Display
**Priority:** Medium
**Type:** UI

**Steps:**
1. Check security score display with different scores
2. Verify visual representation

**Pass Criteria:**
- ✅ Progress bar width matches percentage
- ✅ Colors match score:
  - 0-24%: Red (danger)
  - 25-49%: Orange (warning)
  - 50-74%: Yellow (fair) or Blue (good)
  - 75-100%: Green (excellent)
- ✅ Percentage displays correctly

---

### Test Group: Password Change

#### PHASE2-SEC-003: Change Password - Success
**Priority:** Critical
**Type:** Functional + Security

**Steps:**
1. Navigate to `/settings/security`
2. Enter current password: "TestPass123!"
3. Enter new password: "NewSecurePass456!"
4. Confirm new password: "NewSecurePass456!"
5. Click "Change Password"

**Pass Criteria:**
- ✅ Success message displays
- ✅ Password hash updated in database (tblUsers.passwordHash)
- ✅ New password uses Argon2id hashing
- ✅ Activity logged (password_change)
- ✅ Can login with new password
- ✅ Cannot login with old password

---

#### PHASE2-SEC-004: Change Password - Validation
**Priority:** High
**Type:** Security

**Test Cases:**

❌ **Wrong Current Password:**
- Input: Wrong current + valid new
- Result: "Current password is incorrect"

❌ **Passwords Don't Match:**
- Input: Valid current + new ≠ confirm
- Result: "New passwords do not match"

❌ **Weak New Password:**
- Input: "pass" (too short)
- Result: "Password must be at least 8 characters"

❌ **Same as Current:**
- Input: Current password as new password
- Result: "New password must be different from current password"

✅ **Valid Change:**
- All validations pass → Password updated

---

#### PHASE2-SEC-005: Password Strength Indicator
**Priority:** Low
**Type:** UI

**Steps:**
1. Type password in new password field
2. Observe strength indicator

**Pass Criteria:**
- ✅ Shows strength as user types
- ✅ Colors: Red (weak), Yellow (medium), Green (strong)
- ✅ Updates in real-time
- ✅ Criteria list shows what's missing:
  - Minimum length
  - Uppercase letter
  - Lowercase letter
  - Number
  - Special character

---

### Test Group: Authentication Methods

#### PHASE2-SEC-006: Authentication Methods Display
**Priority:** Medium
**Type:** Functional

**Steps:**
1. Navigate to `/settings/security`
2. Check "Your Authentication Methods" section

**Pass Criteria:**
- ✅ Password: Shows "Enabled" if passwordHash exists
- ✅ MFA: Shows correct status from tblUsers.mfaEnabled
- ✅ PassKeys: Shows count from tblWebAuthnCredentials
- ✅ OAuth: Shows count from tblOAuthAccounts
- ✅ "Manage" links work for each method

---

### Test Group: Recent Login Activity

#### PHASE2-SEC-007: Recent Logins Display
**Priority:** Medium
**Type:** Functional

**Steps:**
1. Perform 15+ logins from different IPs/devices
2. Navigate to `/settings/security`
3. Check "Recent Login Activity" section

**Pass Criteria:**
- ✅ Shows last 10 logins only
- ✅ Displays: Date/Time, IP Address, Location, Device
- ✅ Current session marked as "Current Session"
- ✅ Sorted by date (newest first)
- ✅ "View All Activity" link works

---

## 🔗 Connected Accounts Tests

### Test Group: OAuth Account Linking

#### PHASE2-OAUTH-001: View Available Providers
**Priority:** High
**Type:** Functional

**Steps:**
1. Navigate to `/settings/connected-accounts`
2. View available OAuth providers

**Pass Criteria:**
- ✅ Shows all 6 providers:
  - Google
  - Microsoft
  - Apple
  - Facebook
  - LinkedIn
  - GitHub
- ✅ Each shows correct icon (Font Awesome)
- ✅ Each shows correct brand color
- ✅ Connected accounts show "Connected" badge
- ✅ Unconnected accounts show "Connect" button

---

#### PHASE2-OAUTH-002: Link OAuth Account
**Priority:** Critical
**Type:** Functional

**Steps:**
1. Click "Connect" button for Google
2. Complete OAuth flow (redirects to Google)
3. Authorize and return to SIGNula

**Pass Criteria:**
- ✅ Redirects to Google OAuth page
- ✅ Returns to connected-accounts page
- ✅ Success message displays
- ✅ Account shows as "Connected"
- ✅ Account details display (email, name)
- ✅ Record created in tblOAuthAccounts
- ✅ Activity logged (oauth_link)

**Test for each provider:**
- Google ✅
- Microsoft ✅
- Apple ✅
- Facebook ✅
- LinkedIn ✅
- GitHub ✅

---

#### PHASE2-OAUTH-003: Unlink OAuth Account
**Priority:** Critical
**Type:** Functional + Security

**Steps:**
1. On connected account, click "Unlink"
2. Confirm in modal dialog
3. Verify unlinking

**Pass Criteria:**
- ✅ Confirmation modal appears
- ✅ Modal explains consequences
- ✅ "Cancel" button closes modal, no change
- ✅ "Unlink" button removes connection
- ✅ Success message displays
- ✅ Account shows as disconnected
- ✅ Record removed from tblOAuthAccounts (or marked as deleted)
- ✅ Activity logged (oauth_unlink)
- ✅ Cannot unlink if it's the only login method

---

#### PHASE2-OAUTH-004: Set Primary Account
**Priority:** Medium
**Type:** Functional

**Steps:**
1. Link 2+ OAuth accounts
2. Click "Set as Primary" on one account
3. Verify primary status

**Pass Criteria:**
- ✅ Account marked as primary in database
- ✅ "Primary" badge displays
- ✅ Avatar updates to use primary account profile picture
- ✅ Only one account can be primary at a time
- ✅ Setting new primary removes previous primary status
- ✅ Activity logged

---

#### PHASE2-OAUTH-005: OAuth Account Permissions
**Priority:** Medium
**Type:** Functional

**Steps:**
1. View connected account details
2. Check permissions display

**Pass Criteria:**
- ✅ Shows scopes/permissions granted
- ✅ Shows account email
- ✅ Shows account name
- ✅ Shows connection date
- ✅ Shows last used date

---

### Test Group: OAuth Security

#### PHASE2-OAUTH-006: Prevent Account Lockout
**Priority:** Critical
**Type:** Security

**Steps:**
1. Create account with ONLY OAuth login (no password)
2. Try to unlink the OAuth account
3. Verify prevention

**Pass Criteria:**
- ✅ Unlink button disabled or warning shown
- ✅ Error message: "Cannot unlink your only login method. Set a password first."
- ✅ User cannot lock themselves out
- ✅ Must have password OR OAuth OR PassKey

---

#### PHASE2-OAUTH-007: OAuth Token Security
**Priority:** Critical
**Type:** Security

**Steps:**
1. Link OAuth account
2. Check database storage

**Pass Criteria:**
- ✅ Access tokens encrypted in database
- ✅ Refresh tokens encrypted in database
- ✅ Uses AES-256-CBC encryption
- ✅ Tokens not visible in page source
- ✅ Tokens not sent to client

---

## 🔐 MFA Management Tests

### Test Group: MFA Enable/Disable

#### PHASE2-MFA-001: Enable MFA
**Priority:** Critical
**Type:** Functional

**Steps:**
1. Login with account without MFA
2. Navigate to `/settings/mfa`
3. Enter password in modal
4. Click "Enable MFA"

**Pass Criteria:**
- ✅ Password confirmation modal appears
- ✅ Wrong password = Error, MFA not enabled
- ✅ Correct password = MFA enabled
- ✅ QR code displays for authenticator app
- ✅ Setup secret displays
- ✅ Instructions for setup clear
- ✅ Database updated (tblUsers.mfaEnabled = 1)
- ✅ Activity logged (mfa_enabled)

---

#### PHASE2-MFA-002: Disable MFA
**Priority:** Critical
**Type:** Functional

**Steps:**
1. Login with account with MFA enabled
2. Navigate to `/settings/mfa`
3. Click "Disable MFA"
4. Enter password
5. Confirm

**Pass Criteria:**
- ✅ Password confirmation required
- ✅ Additional warning about security impact
- ✅ Wrong password = No change
- ✅ Correct password = MFA disabled
- ✅ Database updated (mfaEnabled = 0)
- ✅ MFA secret cleared from database
- ✅ Activity logged (mfa_disabled)

---

### Test Group: Backup Codes

#### PHASE2-MFA-003: Generate Backup Codes
**Priority:** High
**Type:** Functional

**Steps:**
1. Enable MFA
2. Click "Generate Backup Codes"
3. View codes

**Pass Criteria:**
- ✅ 10 codes generated
- ✅ Codes are 8 characters each
- ✅ Codes are random and unique
- ✅ Codes stored as hashes in tblMFABackupCodes
- ✅ Codes displayed in modal
- ✅ Print functionality works
- ✅ Copy to clipboard works
- ✅ Warning about storing codes safely
- ✅ Activity logged (backup_codes_generated)

---

#### PHASE2-MFA-004: Regenerate Backup Codes
**Priority:** High
**Type:** Functional

**Steps:**
1. With existing backup codes
2. Click "Regenerate Codes"
3. Confirm regeneration

**Pass Criteria:**
- ✅ Confirmation warning appears
- ✅ Explains old codes will be invalidated
- ✅ Old codes marked as used/deleted in database
- ✅ New codes generated
- ✅ Activity logged (backup_codes_regenerated)

---

#### PHASE2-MFA-005: Print Backup Codes
**Priority:** Low
**Type:** UI

**Steps:**
1. Generate backup codes
2. Click "Print Codes"
3. Check print preview

**Pass Criteria:**
- ✅ Print dialog opens
- ✅ Codes formatted for printing
- ✅ Header/footer with instructions
- ✅ No navigation/sidebar in print view

---

#### PHASE2-MFA-006: Copy Backup Codes
**Priority:** Low
**Type:** UI

**Steps:**
1. Generate backup codes
2. Click "Copy to Clipboard"
3. Paste in text editor

**Pass Criteria:**
- ✅ Codes copied to clipboard
- ✅ Format: One code per line
- ✅ Success message displays
- ✅ Works in all browsers

---

### Test Group: MFA Usage Tracking

#### PHASE2-MFA-007: MFA Usage Statistics
**Priority:** Low
**Type:** Functional

**Steps:**
1. Enable MFA
2. Login 5+ times using MFA
3. Check MFA settings page

**Pass Criteria:**
- ✅ Shows "Last Used" timestamp
- ✅ Shows "Times Used" counter
- ✅ Displays recent MFA login history

---

## 📊 Activity Log Tests

### Test Group: Activity Display

#### PHASE2-ACT-001: View Activity Log
**Priority:** High
**Type:** Functional

**Steps:**
1. Navigate to `/settings/activity`
2. View activity log

**Pass Criteria:**
- ✅ Page loads without errors
- ✅ Shows activities from tblActivityLog
- ✅ Displays: Date/Time, Activity Type, Result, Details, IP Address
- ✅ Sorted by date (newest first)
- ✅ Pagination works (25 per page)
- ✅ User agent displays on hover/expansion

---

#### PHASE2-ACT-002: Activity Statistics Cards
**Priority:** Medium
**Type:** Functional

**Steps:**
1. Check statistics at top of page

**Pass Criteria:**
- ✅ Total activities count matches database
- ✅ Last 7 days count accurate
- ✅ Last 30 days count accurate
- ✅ Failed logins count accurate
- ✅ Counts update when new activity occurs

---

### Test Group: Filtering

#### PHASE2-ACT-003: Filter by Activity Type
**Priority:** High
**Type:** Functional

**Steps:**
1. Select "Login" from Activity Type dropdown
2. Click "Apply Filters"

**Pass Criteria:**
- ✅ Shows only login activities
- ✅ Other types hidden
- ✅ Count updates to match filtered results
- ✅ "Clear Filters" button appears
- ✅ URL parameters updated with filter

**Test all types:**
- login ✅
- logout ✅
- profile_update ✅
- password_change ✅
- mfa_enabled ✅
- passkey_registered ✅
- oauth_link ✅

---

#### PHASE2-ACT-004: Filter by Result
**Priority:** High
**Type:** Functional

**Steps:**
1. Select "Failed" from Result dropdown
2. Click "Apply Filters"

**Pass Criteria:**
- ✅ Shows only failed activities
- ✅ Success activities hidden
- ✅ Useful for security review

---

#### PHASE2-ACT-005: Filter by Date Range
**Priority:** High
**Type:** Functional

**Steps:**
1. Select "From" date: 2026-01-01
2. Select "To" date: 2026-01-31
3. Click "Apply Filters"

**Pass Criteria:**
- ✅ Shows only activities within date range
- ✅ Activities outside range hidden
- ✅ Date pickers work correctly
- ✅ Validates from < to

---

#### PHASE2-ACT-006: Search Activity Details
**Priority:** Medium
**Type:** Functional

**Steps:**
1. Enter search term (e.g., IP address, activity detail)
2. Click "Apply Filters"

**Pass Criteria:**
- ✅ Searches activityType, details, ipAddress
- ✅ Shows matching results only
- ✅ Case-insensitive search
- ✅ Partial matches work

---

#### PHASE2-ACT-007: Combined Filters
**Priority:** High
**Type:** Functional

**Steps:**
1. Set Activity Type = "login"
2. Set Result = "failed"
3. Set Date Range = Last 30 days
4. Enter search term
5. Apply filters

**Pass Criteria:**
- ✅ All filters apply simultaneously
- ✅ Results match ALL criteria (AND logic)
- ✅ Count accurate for filtered results

---

#### PHASE2-ACT-008: Clear Filters
**Priority:** Medium
**Type:** Functional

**Steps:**
1. Apply multiple filters
2. Click "Clear Filters"

**Pass Criteria:**
- ✅ All filters reset
- ✅ Shows all activities again
- ✅ Dropdowns return to "All"
- ✅ Search box cleared
- ✅ Date range cleared

---

### Test Group: Export Functionality

#### PHASE2-ACT-009: Export to CSV
**Priority:** High
**Type:** Functional

**Steps:**
1. Apply filters (optional)
2. Click "Export CSV"
3. Check downloaded file

**Pass Criteria:**
- ✅ File downloads successfully
- ✅ Filename: activity-log-YYYY-MM-DD.csv
- ✅ Headers: Date/Time, Activity Type, Result, Details, IP Address, User Agent
- ✅ Data matches filtered results (if filters applied)
- ✅ CSV format valid (opens in Excel/Sheets)
- ✅ Special characters escaped correctly

---

#### PHASE2-ACT-010: Export to JSON
**Priority:** High
**Type:** Functional

**Steps:**
1. Apply filters (optional)
2. Click "Export JSON"
3. Check downloaded file

**Pass Criteria:**
- ✅ File downloads successfully
- ✅ Filename: activity-log-YYYY-MM-DD.json
- ✅ Valid JSON format
- ✅ Data matches filtered results
- ✅ All fields included
- ✅ Properly formatted and indented

---

#### PHASE2-ACT-011: Export with Filters
**Priority:** High
**Type:** Functional

**Steps:**
1. Filter to show only "failed" logins from last 7 days
2. Export to CSV
3. Verify export

**Pass Criteria:**
- ✅ Exported file contains only filtered results
- ✅ Count matches visible results
- ✅ Filters preserved in export

---

### Test Group: Pagination

#### PHASE2-ACT-012: Pagination Navigation
**Priority:** Medium
**Type:** UI

**Steps:**
1. Generate 100+ activity records
2. Navigate through pages

**Pass Criteria:**
- ✅ Shows 25 items per page
- ✅ "Next" and "Previous" buttons work
- ✅ Page numbers clickable
- ✅ Current page highlighted
- ✅ First page: "Previous" disabled
- ✅ Last page: "Next" disabled
- ✅ Page count accurate

---

## 🔒 Privacy Settings Tests

### Test Group: Profile Visibility

#### PHASE2-PRIV-001: Change Profile Visibility
**Priority:** High
**Type:** Functional

**Steps:**
1. Navigate to `/settings/privacy`
2. Select "Private" visibility
3. Click "Save Privacy Settings"

**Pass Criteria:**
- ✅ Success message displays
- ✅ Setting saved to database (JSON in tblUserPreferences)
- ✅ Activity logged
- ✅ Profile hidden from public view

**Test all options:**
- Private (Only me) ✅
- Friends Only ✅
- Public (Everyone) ✅

---

#### PHASE2-PRIV-002: Activity Status Sharing
**Priority:** Medium
**Type:** Functional

**Steps:**
1. Toggle "Share my activity status" switch
2. Save settings

**Pass Criteria:**
- ✅ Checkbox toggles on/off
- ✅ Setting saved correctly
- ✅ Activity status hidden/shown based on setting

---

### Test Group: Third-Party Access

#### PHASE2-PRIV-003: View Connected Apps
**Priority:** High
**Type:** Functional

**Steps:**
1. Connect to 3+ third-party apps via API
2. Navigate to `/settings/privacy`
3. Check "Third-Party App Access" section

**Pass Criteria:**
- ✅ Shows all connected apps
- ✅ Displays app name, permissions, last access
- ✅ "Revoke Access" button for each app

---

#### PHASE2-PRIV-004: Revoke App Access
**Priority:** Critical
**Type:** Functional + Security

**Steps:**
1. Click "Revoke Access" for an app
2. Confirm revocation

**Pass Criteria:**
- ✅ Confirmation modal appears
- ✅ App removed from list
- ✅ API tokens invalidated
- ✅ App can no longer access user data
- ✅ Activity logged (api_access_revoked)

---

### Test Group: Data Preferences

#### PHASE2-PRIV-005: Analytics Tracking Toggle
**Priority:** Low
**Type:** Functional

**Steps:**
1. Toggle "Allow analytics tracking"
2. Save settings

**Pass Criteria:**
- ✅ Setting saves to database
- ✅ Analytics scripts load/don't load based on preference
- ✅ Respects user choice

---

#### PHASE2-PRIV-006: Marketing Emails Toggle
**Priority:** Medium
**Type:** Functional

**Steps:**
1. Toggle "Receive marketing emails"
2. Save settings

**Pass Criteria:**
- ✅ Setting saves correctly
- ✅ User excluded/included from marketing email lists
- ✅ Still receives transactional emails (login alerts, etc.)

---

### Test Group: Data Rights

#### PHASE2-PRIV-007: GDPR Compliance Display
**Priority:** High
**Type:** Compliance

**Steps:**
1. Check "Your Data Rights" section
2. Verify information displayed

**Pass Criteria:**
- ✅ Explains right to access data
- ✅ Explains right to delete data
- ✅ Explains right to portability
- ✅ Links to data export page
- ✅ Links to account deletion page
- ✅ Privacy policy link present

---

## 🔔 Notification Preferences Tests

### Test Group: Email Notifications

#### PHASE2-NOTIF-001: Email Security Alerts
**Priority:** Critical
**Type:** Functional

**Steps:**
1. Navigate to `/settings/notifications`
2. Toggle email notification checkboxes
3. Save preferences

**Pass Criteria:**

**Security Alerts:**
- ✅ "Security alerts" checkbox toggles
- ✅ "Password changes" checkbox toggles
- ✅ "New device logins" checkbox toggles
- ✅ "MFA changes" checkbox toggles
- ✅ Settings save to database
- ✅ Emails sent/not sent based on preferences

---

#### PHASE2-NOTIF-002: Email Account Updates
**Priority:** Medium
**Type:** Functional

**Steps:**
1. Toggle account notification preferences
2. Save settings

**Pass Criteria:**
- ✅ "Account updates" toggle works
- ✅ "New features" toggle works
- ✅ "Product updates" toggle works
- ✅ Settings persist

---

#### PHASE2-NOTIF-003: Email Marketing
**Priority:** Low
**Type:** Functional

**Steps:**
1. Toggle "Marketing communications"
2. Save settings

**Pass Criteria:**
- ✅ Setting saves
- ✅ User added/removed from marketing list
- ✅ Can be toggled independently of other emails

---

### Test Group: Push Notifications

#### PHASE2-NOTIF-004: Push Security Alerts
**Priority:** High
**Type:** Functional

**Steps:**
1. Toggle push notification preferences
2. Save settings

**Pass Criteria:**
- ✅ "Security alerts" toggle works
- ✅ "Login from new device" toggle works
- ✅ Settings save correctly
- ✅ Push notifications sent based on preferences (requires browser permission)

---

### Test Group: SMS Notifications

#### PHASE2-NOTIF-005: SMS Security Alerts
**Priority:** Medium
**Type:** Functional

**Steps:**
1. Toggle SMS notification preferences
2. Save settings

**Pass Criteria:**
- ✅ "Security alerts" toggle works
- ✅ "Login from new device" toggle works
- ✅ Settings save correctly
- ✅ Note: Requires phone number to be set

---

### Test Group: Quick Actions

#### PHASE2-NOTIF-006: Enable All Notifications
**Priority:** Medium
**Type:** UI

**Steps:**
1. Disable all notifications
2. Click "Enable All" button
3. Check checkboxes

**Pass Criteria:**
- ✅ All checkboxes toggle to checked
- ✅ Settings save automatically or on submit
- ✅ Visual feedback of change

---

#### PHASE2-NOTIF-007: Disable All Notifications
**Priority:** Medium
**Type:** UI

**Steps:**
1. Enable all notifications
2. Click "Disable All" button
3. Check checkboxes

**Pass Criteria:**
- ✅ All checkboxes toggle to unchecked
- ✅ Settings save
- ✅ Warning that this disables critical security alerts

---

#### PHASE2-NOTIF-008: Security Only Mode
**Priority:** High
**Type:** UI

**Steps:**
1. Click "Security Only" button
2. Check which notifications remain

**Pass Criteria:**
- ✅ All notifications disabled EXCEPT:
  - Email security alerts ✅
  - Email password changes ✅
  - Email new device logins ✅
  - Email MFA changes ✅
  - Push security alerts ✅
  - SMS security alerts ✅
- ✅ All marketing/product updates disabled

---

### Test Group: Notification Delivery

#### PHASE2-NOTIF-009: Test Notification Delivery
**Priority:** High
**Type:** Integration

**Steps:**
1. Enable specific notification type
2. Trigger event (e.g., password change)
3. Check email/push/SMS delivery

**Pass Criteria:**
- ✅ Notification sent within 1 minute
- ✅ Content accurate and relevant
- ✅ Formatting correct
- ✅ Links work (if applicable)
- ✅ Unsubscribe link works (for marketing)

**Test for each notification type**

---

## 🔗 Cross-Page Integration Tests

### Test Group: Data Consistency

#### PHASE2-INT-001: Profile Updates Reflect Everywhere
**Priority:** High
**Type:** Integration

**Steps:**
1. Update display name in `/settings/profile`
2. Check other pages

**Pass Criteria:**
- ✅ Settings dashboard welcome banner updates
- ✅ Header/navigation updates (if name shown)
- ✅ Activity log shows updated name in entries
- ✅ All pages show consistent data

---

#### PHASE2-INT-002: MFA Status Consistency
**Priority:** High
**Type:** Integration

**Steps:**
1. Enable MFA in `/settings/mfa`
2. Check other pages

**Pass Criteria:**
- ✅ Settings dashboard shows "MFA: Enabled"
- ✅ Security page security score increases
- ✅ Activity log shows mfa_enabled entry
- ✅ Login process now requires MFA

---

#### PHASE2-INT-003: PassKey Count Updates
**Priority:** Medium
**Type:** Integration

**Steps:**
1. Register new PassKey
2. Check settings dashboard and security page

**Pass Criteria:**
- ✅ Dashboard shows updated PassKey count
- ✅ Security page shows updated count
- ✅ Security score updates accordingly

---

### Test Group: Navigation Flow

#### PHASE2-INT-004: Settings Navigation Loop
**Priority:** Medium
**Type:** UI

**Steps:**
1. Navigate through all 8 settings pages in order
2. Use sidebar navigation
3. Return to dashboard

**Pass Criteria:**
- ✅ All pages accessible from sidebar
- ✅ Active page highlighted correctly
- ✅ No broken links
- ✅ Breadcrumbs work (if implemented)

---

#### PHASE2-INT-005: Action Links Work
**Priority:** High
**Type:** Integration

**Steps:**
1. From dashboard "Security Recommendations", click "Enable MFA"
2. From security page, click "Manage PassKeys"
3. From activity log, click "View All"

**Pass Criteria:**
- ✅ Links navigate to correct pages
- ✅ Appropriate sections/forms highlighted or expanded
- ✅ Return links work

---

## 🔐 Security Testing

### Test Group: Authentication & Authorization

#### PHASE2-SEC-101: Require Login
**Priority:** Critical
**Type:** Security

**Steps:**
1. Logout
2. Try to access `/settings/profile` directly

**Pass Criteria:**
- ✅ Redirects to login page
- ✅ After login, redirects back to intended page
- ✅ Session required for all settings pages

---

#### PHASE2-SEC-102: Session Validation
**Priority:** Critical
**Type:** Security

**Steps:**
1. Login and get session
2. Manually delete session cookie
3. Try to access settings page

**Pass Criteria:**
- ✅ Denied access
- ✅ Redirects to login
- ✅ Error message displayed

---

#### PHASE2-SEC-103: CSRF Protection
**Priority:** Critical
**Type:** Security

**Steps:**
1. Inspect form HTML
2. Check for CSRF tokens
3. Try to submit form with invalid token

**Pass Criteria:**
- ✅ All forms have CSRF token field
- ✅ Token validated on submission
- ✅ Invalid token = Request rejected
- ✅ Error message: "Invalid security token"

---

#### PHASE2-SEC-104: Password Verification for Sensitive Actions
**Priority:** Critical
**Type:** Security

**Steps:**
1. Try to change email without password
2. Try to enable/disable MFA without password

**Pass Criteria:**
- ✅ Password modal appears
- ✅ Cannot proceed without password
- ✅ Wrong password = Error, action blocked
- ✅ Correct password = Action allowed

**Requires password verification:**
- Email change ✅
- MFA enable/disable ✅
- Account deletion ✅

---

### Test Group: Input Validation

#### PHASE2-SEC-105: SQL Injection Prevention
**Priority:** Critical
**Type:** Security

**Test Inputs:**
```sql
' OR '1'='1
'; DROP TABLE tblUsers; --
1' UNION SELECT * FROM tblUsers --
```

**Steps:**
1. Enter SQL injection strings in all input fields
2. Submit forms
3. Check database and response

**Pass Criteria:**
- ✅ Input sanitized/escaped
- ✅ Prepared statements protect against injection
- ✅ No database errors
- ✅ No data leaked
- ✅ No tables dropped

**Test in:**
- Profile update fields ✅
- Email change ✅
- Activity log search ✅
- All text inputs ✅

---

#### PHASE2-SEC-106: XSS Prevention
**Priority:** Critical
**Type:** Security

**Test Inputs:**
```html
<script>alert('XSS')</script>
<img src=x onerror=alert('XSS')>
javascript:alert('XSS')
<svg onload=alert('XSS')>
```

**Steps:**
1. Enter XSS payloads in input fields
2. Save and view output
3. Check if script executes

**Pass Criteria:**
- ✅ Script does not execute
- ✅ Output HTML-encoded
- ✅ Content Security Policy blocks inline scripts
- ✅ No XSS alerts appear

**Test in:**
- Display name ✅
- Username ✅
- Activity search ✅
- Any user-editable field ✅

---

#### PHASE2-SEC-107: File Upload Security (Profile Picture)
**Priority:** High
**Type:** Security

**Note:** If profile picture upload implemented

**Test Files:**
- PHP file disguised as image
- SVG with embedded JavaScript
- File with double extension (.jpg.php)
- Oversized file (> 10MB)

**Pass Criteria:**
- ✅ Only image types allowed
- ✅ File type validation (MIME + extension)
- ✅ File size limits enforced
- ✅ Files stored outside web root or unexecutable
- ✅ Filename sanitized

---

### Test Group: Rate Limiting

#### PHASE2-SEC-108: Rate Limit Profile Updates
**Priority:** Medium
**Type:** Security

**Steps:**
1. Update profile 20 times rapidly
2. Check for rate limiting

**Pass Criteria:**
- ✅ After X attempts, temporary block
- ✅ Error message: "Too many requests. Please try again later."
- ✅ Block expires after timeout
- ✅ Prevents abuse

---

#### PHASE2-SEC-109: Rate Limit Password Changes
**Priority:** High
**Type:** Security

**Steps:**
1. Attempt password change 10 times in 1 minute
2. Check for rate limiting

**Pass Criteria:**
- ✅ Rate limit after 5-10 attempts
- ✅ Prevents brute force password attempts
- ✅ Activity logged

---

### Test Group: Data Privacy

#### PHASE2-SEC-110: User Data Isolation
**Priority:** Critical
**Type:** Security

**Steps:**
1. Login as User A
2. Try to access User B's data via URL manipulation

**Pass Criteria:**
- ✅ Can only view own activity log
- ✅ Can only edit own profile
- ✅ Cannot access other users' settings
- ✅ Authorization checks in place

**Test by:**
- Changing userID in URL parameters
- Changing session data
- Attempting to view other user profiles

---

#### PHASE2-SEC-111: Sensitive Data Encryption
**Priority:** Critical
**Type:** Security

**Steps:**
1. Check database for sensitive data
2. Verify encryption

**Pass Criteria:**
- ✅ OAuth tokens encrypted
- ✅ MFA secrets encrypted
- ✅ Backup codes hashed
- ✅ Passwords use Argon2id
- ✅ Encryption keys not in database

---

## 🎨 UI/UX Testing

### Test Group: Responsive Design

#### PHASE2-UI-001: Mobile View (375px)
**Priority:** High
**Type:** UI

**Steps:**
1. View all 8 settings pages on mobile (375px width)
2. Check responsiveness

**Pass Criteria:**
- ✅ Sidebar collapses to hamburger menu or stacks
- ✅ Forms stack vertically
- ✅ Buttons full-width or appropriate size
- ✅ Tables scroll horizontally or reformat
- ✅ Text readable (no horizontal scroll for text)
- ✅ Touch targets ≥ 44px

---

#### PHASE2-UI-002: Tablet View (768px)
**Priority:** Medium
**Type:** UI

**Steps:**
1. View pages on tablet (768px width)
2. Check layout

**Pass Criteria:**
- ✅ Sidebar visible or toggle
- ✅ Two-column layouts work
- ✅ Forms utilize space well
- ✅ Navigation accessible

---

#### PHASE2-UI-003: Desktop View (1920px)
**Priority:** Medium
**Type:** UI

**Steps:**
1. View pages on large desktop (1920px width)
2. Check layout

**Pass Criteria:**
- ✅ Content doesn't stretch too wide
- ✅ Max-width containers used
- ✅ Sidebar remains fixed or accessible
- ✅ Whitespace utilized appropriately

---

### Test Group: Accessibility

#### PHASE2-UI-004: Keyboard Navigation
**Priority:** High
**Type:** Accessibility

**Steps:**
1. Use only keyboard (Tab, Enter, Space) to navigate
2. Complete profile update

**Pass Criteria:**
- ✅ All interactive elements reachable by Tab
- ✅ Focus indicators visible
- ✅ Logical tab order
- ✅ Forms submittable with Enter
- ✅ Modals closable with Escape
- ✅ No keyboard traps

---

#### PHASE2-UI-005: Screen Reader Support
**Priority:** High
**Type:** Accessibility

**Steps:**
1. Use screen reader (NVDA/JAWS/VoiceOver)
2. Navigate settings pages

**Pass Criteria:**
- ✅ Form labels announced correctly
- ✅ Error messages announced
- ✅ Success messages announced
- ✅ ARIA labels present where needed
- ✅ Alt text for images/icons
- ✅ Semantic HTML used

---

#### PHASE2-UI-006: Color Contrast
**Priority:** High
**Type:** Accessibility

**Steps:**
1. Check color contrast ratios
2. Use browser dev tools or WAVE

**Pass Criteria:**
- ✅ Text contrast ≥ 4.5:1 (WCAG AA)
- ✅ Large text ≥ 3:1
- ✅ Interactive elements distinguishable
- ✅ Error messages high contrast

---

#### PHASE2-UI-007: Color Blind Mode
**Priority:** Medium
**Type:** Accessibility

**Steps:**
1. Enable color blind mode (if implemented)
2. View all pages

**Pass Criteria:**
- ✅ Information not conveyed by color alone
- ✅ Icons/text accompany color indicators
- ✅ Success/error distinguishable without color
- ✅ Security score readable in all modes

---

### Test Group: User Feedback

#### PHASE2-UI-008: Success Messages
**Priority:** High
**Type:** UX

**Steps:**
1. Perform successful actions (profile update, password change, etc.)
2. Check for feedback

**Pass Criteria:**
- ✅ Success message displays
- ✅ Message is clear and specific
- ✅ Message auto-dismisses after 5 seconds OR has close button
- ✅ Message visually distinct (green background/icon)

---

#### PHASE2-UI-009: Error Messages
**Priority:** High
**Type:** UX

**Steps:**
1. Trigger errors (invalid input, failed actions)
2. Check error messaging

**Pass Criteria:**
- ✅ Error message displays
- ✅ Message explains what went wrong
- ✅ Message suggests how to fix
- ✅ Visually distinct (red background/icon)
- ✅ Field-level errors highlight specific field

---

#### PHASE2-UI-010: Loading States
**Priority:** Medium
**Type:** UX

**Steps:**
1. Perform actions that take time (export, save)
2. Observe loading indicators

**Pass Criteria:**
- ✅ Loading spinner/indicator displays
- ✅ Button shows "Saving..." or disabled state
- ✅ Prevents double-submission
- ✅ Clear when action completes

---

#### PHASE2-UI-011: Confirmation Dialogs
**Priority:** High
**Type:** UX

**Steps:**
1. Perform destructive actions (unlink OAuth, disable MFA)
2. Check for confirmations

**Pass Criteria:**
- ✅ Confirmation modal appears
- ✅ Explains consequences clearly
- ✅ "Cancel" button prominent and safe
- ✅ Destructive action requires deliberate click
- ✅ Modal closable without action

---

### Test Group: Consistency

#### PHASE2-UI-012: Design Consistency
**Priority:** Medium
**Type:** UI

**Steps:**
1. Review all 8 pages
2. Check for consistency

**Pass Criteria:**
- ✅ Color scheme consistent
- ✅ Typography consistent (headings, body text)
- ✅ Button styles consistent
- ✅ Form styles consistent
- ✅ Icon usage consistent
- ✅ Spacing/padding consistent

---

#### PHASE2-UI-013: Terminology Consistency
**Priority:** Low
**Type:** UX

**Steps:**
1. Review all page copy
2. Check terminology

**Pass Criteria:**
- ✅ "PassKeys" vs "Passkeys" - Pick one
- ✅ "Two-Factor Authentication" vs "MFA" - Consistent
- ✅ "Connected Accounts" vs "Linked Accounts" - Consistent
- ✅ Error message wording consistent

---

## ⚡ Performance Testing

### Test Group: Page Load Performance

#### PHASE2-PERF-001: Page Load Time
**Priority:** High
**Type:** Performance

**Steps:**
1. Clear cache
2. Load each settings page
3. Measure load time

**Pass Criteria:**
- ✅ All pages load in < 2 seconds
- ✅ Time to First Contentful Paint < 1 second
- ✅ Time to Interactive < 2 seconds
- ✅ No unnecessary database queries

**Test for each page:**
- Dashboard ✅
- Profile ✅
- Security ✅
- Connected Accounts ✅
- MFA ✅
- Activity Log ✅
- Privacy ✅
- Notifications ✅

---

#### PHASE2-PERF-002: Database Query Optimization
**Priority:** High
**Type:** Performance

**Steps:**
1. Enable query logging
2. Load activity log page with 10,000+ records
3. Check query count and time

**Pass Criteria:**
- ✅ Uses pagination (not loading all records)
- ✅ Queries use indexes
- ✅ No N+1 query problems
- ✅ Total queries < 10 per page load
- ✅ Query time < 100ms

---

#### PHASE2-PERF-003: Export Performance
**Priority:** Medium
**Type:** Performance

**Steps:**
1. Export 10,000 activity records to CSV
2. Measure time

**Pass Criteria:**
- ✅ Completes in < 5 seconds
- ✅ No memory errors
- ✅ File size appropriate
- ✅ Does not timeout

---

### Test Group: Asset Optimization

#### PHASE2-PERF-004: CSS/JS Minification
**Priority:** Low
**Type:** Performance

**Steps:**
1. Check CSS and JS files
2. Verify minification

**Pass Criteria:**
- ✅ CSS minified in production
- ✅ JS minified in production
- ✅ Combined where possible
- ✅ Gzip compression enabled

---

#### PHASE2-PERF-005: Image Optimization
**Priority:** Low
**Type:** Performance

**Steps:**
1. Check image file sizes
2. Verify optimization

**Pass Criteria:**
- ✅ Icons use SVG or icon fonts
- ✅ Avatar images optimized
- ✅ Appropriate image formats used
- ✅ Lazy loading for images

---

## 📝 Test Results Template

Use this template to document test results:

```markdown
# Phase 2 Test Results

**Test Date:** YYYY-MM-DD
**Tester:** [Name]
**Environment:** [Development/Staging/Production]
**Browser:** [Chrome/Firefox/Safari] [Version]

## Summary

- **Total Tests:** [Number]
- **Passed:** [Number] ✅
- **Failed:** [Number] ❌
- **Skipped:** [Number] ⏭️
- **Pass Rate:** [Percentage]%

## Failed Tests

### PHASE2-PROF-003: Update Username
**Status:** ❌ FAILED
**Expected:** Username updates in database
**Actual:** Error: "Database connection failed"
**Steps to Reproduce:**
1. Navigate to /settings/profile
2. Change username to "newusername"
3. Click Save

**Error Details:**
```
MySQLi Error: Connection timeout
File: _config/database.php:45
```

**Priority:** Critical
**Assigned To:** [Developer]
**Target Fix:** [Date]

---

### PHASE2-ACT-009: Export to CSV
**Status:** ❌ FAILED
**Expected:** CSV file downloads
**Actual:** 500 Internal Server Error
**Notes:** Works in development, fails in staging

---

## Passed Tests Summary

✅ All dashboard tests passed (PHASE2-DASH-001 to PHASE2-DASH-005)
✅ Profile display tests passed (PHASE2-PROF-001)
✅ Security score calculation accurate (PHASE2-SEC-001)
... [continue for all passed tests]

## Issues Found

1. **Username validation** - Allows special characters it shouldn't
2. **Mobile view** - Sidebar overlaps content on 375px
3. **Export timeout** - Large exports (>5000 records) timeout

## Recommendations

1. Implement rate limiting for profile updates
2. Add loading states for all async actions
3. Optimize activity log queries with indexes
4. Add client-side validation before server submission

## Next Steps

1. Fix critical bugs (PHASE2-PROF-003, PHASE2-ACT-009)
2. Re-test failed cases
3. Conduct security penetration testing
4. User acceptance testing with beta users
```

---

## 🎯 Test Completion Checklist

Before marking Phase 2 as "Tested and Complete", ensure:

### Functional Testing
- [ ] All 8 pages load without errors
- [ ] All forms submit successfully
- [ ] All validations work correctly
- [ ] All integrations function properly

### Security Testing
- [ ] Authentication required for all pages
- [ ] CSRF protection verified
- [ ] SQL injection prevention confirmed
- [ ] XSS prevention confirmed
- [ ] Password verification works for sensitive actions

### UI/UX Testing
- [ ] Responsive on mobile, tablet, desktop
- [ ] Keyboard navigation works
- [ ] Screen reader compatible
- [ ] Color contrast meets WCAG AA
- [ ] Success/error messages display correctly

### Performance Testing
- [ ] All pages load in < 2 seconds
- [ ] Database queries optimized
- [ ] Export functions work with large datasets

### Integration Testing
- [ ] Data consistency across pages
- [ ] Navigation flows work
- [ ] Activity logging works for all actions

### Documentation
- [ ] All features documented
- [ ] Test results recorded
- [ ] Known issues logged
- [ ] User guide updated

---

## 📞 Support & Questions

If you encounter issues during testing:

1. **Check Logs:**
   - PHP error log: `_private/logs/error.log`
   - Activity log: `tblActivityLog` in database
   - Browser console for JavaScript errors

2. **Common Issues:**
   - Database connection: Check `_private/auth.php`
   - Session issues: Clear cookies and retry
   - CSRF errors: Ensure form tokens present

3. **Report Bugs:**
   - Use GitHub Issues
   - Include: Steps to reproduce, expected vs actual, error messages
   - Attach screenshots if applicable

---

**Happy Testing! 🧪**

*Remember: Thorough testing now prevents critical bugs in production.*
