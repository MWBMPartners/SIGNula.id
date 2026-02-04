# ⚡ Quick Test Reference - Phase 2
## Account Management UI - 20-Minute Test

**Version:** 1.0.0
**Last Updated:** February 2, 2026
**Purpose:** Rapid validation of Phase 2 core functionality

---

## 🎯 Overview

This guide provides a streamlined testing process for Phase 2 (Account Management UI) that can be completed in **20 minutes**. It covers critical functionality without exhaustive edge case testing.

**For comprehensive testing, see:** [TESTING_GUIDE_PHASE2.md](TESTING_GUIDE_PHASE2.md)

---

## ✅ Prerequisites (2 minutes)

### Quick Setup

1. **Create Test Account:**
   ```
   Email: quicktest@example.com
   Password: QuickTest123!
   ```

2. **Login and verify:**
   - ✅ Login successful
   - ✅ Session active

---

## 🏃 Quick Test Sequence

### 1️⃣ Settings Dashboard (2 minutes)

**URL:** `/settings/` or `/settings/index.php`

**Quick Checks:**
- ✅ Page loads without errors
- ✅ Welcome banner shows your name
- ✅ 4 stats cards display:
  - PassKeys: 0
  - MFA: Disabled
  - Connected Accounts: 0
  - Activity: [some number]
- ✅ Security recommendations show
- ✅ Recent activity displays

**Quick Test:**
1. Navigate to `/settings/`
2. Verify all sections visible
3. Click one recommendation link - does it work?

**Expected Time:** 2 minutes
**Critical Issues:** None found ✅ / Issues: ___________

---

### 2️⃣ Profile Management (3 minutes)

**URL:** `/settings/profile`

**Quick Checks:**
- ✅ Profile form displays with your data
- ✅ Update display name
- ✅ Change timezone
- ✅ Success message appears

**Quick Test:**

1. **Update Profile:**
   - Change display name to "Quick Test User"
   - Change timezone to "America/New_York"
   - Click "Save Profile Changes"
   - ✅ Success message appears
   - ✅ Dashboard updates with new name

2. **Email Change (Optional):**
   - Change email to "quicktest2@example.com"
   - Enter password: QuickTest123!
   - Click "Change Email"
   - ✅ Email updates
   - ✅ Activity logged

**Expected Time:** 3 minutes
**Critical Issues:** None found ✅ / Issues: ___________

---

### 3️⃣ Security Settings (3 minutes)

**URL:** `/settings/security`

**Quick Checks:**
- ✅ Security score displays (should be 25-50%)
- ✅ Password change form works
- ✅ Authentication methods section shows

**Quick Test:**

1. **Check Security Score:**
   - Note current score: ____%
   - Score breakdown makes sense? ✅

2. **Change Password:**
   - Current: QuickTest123!
   - New: NewQuickTest456!
   - Confirm: NewQuickTest456!
   - Click "Change Password"
   - ✅ Success message
   - ✅ Activity logged

3. **Verify New Password:**
   - Logout
   - Login with new password
   - ✅ Login successful

**Expected Time:** 3 minutes
**Critical Issues:** None found ✅ / Issues: ___________

---

### 4️⃣ Connected Accounts (2 minutes)

**URL:** `/settings/connected-accounts`

**Quick Checks:**
- ✅ 6 OAuth providers displayed:
  - Google, Microsoft, Apple, Facebook, LinkedIn, GitHub
- ✅ Each shows "Connect" button
- ✅ Icons display correctly

**Quick Test:**

1. **View Providers:**
   - All 6 providers visible? ✅
   - Icons and colors correct? ✅

2. **Link Account (if OAuth configured):**
   - Click "Connect" for Google
   - Complete OAuth flow
   - ✅ Returns to page
   - ✅ Shows as "Connected"
   - ✅ Can unlink

**Note:** Skip OAuth test if not configured

**Expected Time:** 2 minutes
**Critical Issues:** None found ✅ / Issues: ___________

---

### 5️⃣ MFA Management (3 minutes)

**URL:** `/settings/mfa`

**Quick Checks:**
- ✅ MFA status displays (currently disabled)
- ✅ Enable/disable functionality works
- ✅ Backup codes generate

**Quick Test:**

1. **Enable MFA:**
   - Click "Enable Two-Factor Authentication"
   - Enter password in modal
   - ✅ QR code displays
   - ✅ Setup secret shows
   - ✅ Instructions clear

2. **Generate Backup Codes:**
   - Click "Generate Backup Codes"
   - ✅ 10 codes display
   - ✅ "Copy to Clipboard" works
   - ✅ Modal closable

3. **Disable MFA:**
   - Click "Disable MFA"
   - Confirm with password
   - ✅ MFA disabled
   - ✅ Success message

**Expected Time:** 3 minutes
**Critical Issues:** None found ✅ / Issues: ___________

---

### 6️⃣ Activity Log (3 minutes)

**URL:** `/settings/activity`

**Quick Checks:**
- ✅ Activity log displays
- ✅ Shows recent actions (login, profile_update, password_change)
- ✅ Filtering works
- ✅ Export works

**Quick Test:**

1. **View Activity:**
   - Activities display in table? ✅
   - Sorted newest first? ✅
   - Shows IP address and details? ✅

2. **Filter Activity:**
   - Select Activity Type: "Login"
   - Click "Apply Filters"
   - ✅ Shows only login activities
   - Click "Clear Filters"
   - ✅ Shows all activities again

3. **Export Activity:**
   - Click "Export CSV"
   - ✅ File downloads
   - ✅ Open in Excel/Sheets - data correct

**Expected Time:** 3 minutes
**Critical Issues:** None found ✅ / Issues: ___________

---

### 7️⃣ Privacy Settings (2 minutes)

**URL:** `/settings/privacy`

**Quick Checks:**
- ✅ Privacy controls display
- ✅ Profile visibility options work
- ✅ Settings save

**Quick Test:**

1. **Change Privacy Settings:**
   - Select "Private" profile visibility
   - Toggle "Share activity status" OFF
   - Toggle "Analytics tracking" OFF
   - Click "Save Privacy Settings"
   - ✅ Success message
   - ✅ Settings persist on reload

2. **Verify Options:**
   - 3 visibility levels available? ✅
   - GDPR info section displays? ✅

**Expected Time:** 2 minutes
**Critical Issues:** None found ✅ / Issues: ___________

---

### 8️⃣ Notification Preferences (2 minutes)

**URL:** `/settings/notifications`

**Quick Checks:**
- ✅ Notification preferences display
- ✅ Email, Push, SMS sections present
- ✅ Quick actions work

**Quick Test:**

1. **Quick Actions:**
   - Click "Disable All"
   - ✅ All checkboxes unchecked
   - Click "Security Only"
   - ✅ Only security checkboxes checked
   - Click "Enable All"
   - ✅ All checkboxes checked

2. **Save Preferences:**
   - Toggle a few options
   - Click "Save Preferences"
   - ✅ Success message
   - Reload page
   - ✅ Settings persist

**Expected Time:** 2 minutes
**Critical Issues:** None found ✅ / Issues: ___________

---

## 🎯 Critical Path Validation

### Integration Test (Bonus - 2 minutes)

Verify data consistency across pages:

1. **Update profile name** → Check dashboard welcomes with new name ✅
2. **Enable MFA** → Check security score increases ✅
3. **Check activity log** → All actions logged ✅

---

## ✅ Quick Test Checklist

**Before marking Phase 2 as functional:**

### Page Access
- [ ] All 8 pages load without errors
- [ ] Navigation sidebar works on all pages
- [ ] Active page highlighted correctly

### Core Functionality
- [ ] Profile updates work
- [ ] Password change works
- [ ] MFA enable/disable works
- [ ] Activity log displays and filters
- [ ] Privacy settings save
- [ ] Notification preferences save

### Security Basics
- [ ] Login required for all pages
- [ ] Password verification for sensitive actions
- [ ] Activity logged for all actions

### UI/UX
- [ ] Success messages display
- [ ] Error handling works
- [ ] Forms validate input
- [ ] Responsive on mobile (quick check)

---

## 🚨 Critical Issues to Watch For

**Stop testing and report if you find:**

1. **Database Errors:**
   - "Connection failed"
   - "Table doesn't exist"
   - Any SQL errors

2. **Security Issues:**
   - Can access pages without login
   - Can edit another user's data
   - Passwords not hashing

3. **Data Loss:**
   - Updates don't save
   - Settings revert on reload
   - Activity not logging

4. **Critical Bugs:**
   - Cannot login after password change
   - Account locked out
   - MFA breaks login

---

## 📊 Quick Test Results

**Test Date:** _______________
**Tester:** _______________
**Total Time:** _______ minutes

### Results Summary

| Test Section | Status | Time | Notes |
|--------------|--------|------|-------|
| 1. Dashboard | ✅ / ❌ | __min | _________ |
| 2. Profile | ✅ / ❌ | __min | _________ |
| 3. Security | ✅ / ❌ | __min | _________ |
| 4. Connected Accounts | ✅ / ❌ | __min | _________ |
| 5. MFA | ✅ / ❌ | __min | _________ |
| 6. Activity Log | ✅ / ❌ | __min | _________ |
| 7. Privacy | ✅ / ❌ | __min | _________ |
| 8. Notifications | ✅ / ❌ | __min | _________ |

**Overall Status:** ✅ All Pass / ❌ Issues Found

### Issues Found

1. _______________________________________________
2. _______________________________________________
3. _______________________________________________

### Next Steps

- [ ] Fix critical issues
- [ ] Re-run quick test
- [ ] Proceed to comprehensive testing (TESTING_GUIDE_PHASE2.md)
- [ ] Security testing
- [ ] Performance testing

---

## 📝 Notes

**Browser Tested:** _________________
**Screen Size:** _________________
**Environment:** Development / Staging / Production

**Additional Comments:**
_____________________________________________________
_____________________________________________________
_____________________________________________________

---

## 🎉 Test Complete!

If all tests pass:
1. ✅ Mark Phase 2 as **functionally complete**
2. 📋 Proceed to comprehensive testing for edge cases
3. 🔒 Conduct security penetration testing
4. 🚀 Prepare for user acceptance testing

**For detailed testing:** See [TESTING_GUIDE_PHASE2.md](TESTING_GUIDE_PHASE2.md)

---

**Next Phase:** Phase 3 - RESTful API Enhancement

**Questions or Issues?**
- Check logs: `_private/logs/error.log`
- Review documentation: [README.md](README.md)
- Report bugs: GitHub Issues
