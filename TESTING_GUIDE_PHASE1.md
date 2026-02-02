# 🧪 SIGNula Phase 1 - Testing Guide

**Version:** 1.4.0
**Date:** 2026-02-02
**Status:** Ready for Testing

---

## 📋 Overview

This guide will help you thoroughly test all Phase 1 authentication features:
- ✅ WebAuthn/PassKey Registration
- ✅ WebAuthn/PassKey Authentication
- ✅ Passwordless Email Login
- ✅ PassKey Management
- ✅ Security Features

---

## 🚀 Pre-Testing Setup

### Step 1: Database Migration

Run the migration script to create necessary tables:

```bash
# Via command line
mysql -u [username] -p [database_name] < _database/migrations/005_webauthn_passkeys.sql

# Example:
mysql -u root -p signula < _database/migrations/005_webauthn_passkeys.sql
```

**Verify migration:**
```sql
-- Check tables were created
SHOW TABLES LIKE 'tblWebAuthn%';
SHOW TABLES LIKE 'tblPasswordless%';

-- Check settings were added
SELECT * FROM tblSettings WHERE settingKey LIKE 'auth.%';
```

**Expected output:**
- `tblWebAuthnCredentials`
- `tblWebAuthnChallenges`
- `tblPasswordlessTokens`
- Several auth settings in tblSettings

### Step 2: Configure Settings

Ensure these settings are configured:

```sql
-- WebAuthn settings
INSERT INTO tblSettings (settingKey, settingValue, category) VALUES
('auth.webauthn.enabled', '1', 'authentication'),
('auth.webauthn.rp_name', 'SIGNula', 'authentication'),
('auth.webauthn.rp_id', 'localhost', 'authentication'),  -- Change for production
('auth.webauthn.challenge_validity', '5', 'authentication')
ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- Passwordless settings
INSERT INTO tblSettings (settingKey, settingValue, category) VALUES
('auth.passwordless.enabled', '1', 'authentication'),
('auth.passwordless.token_validity', '15', 'authentication')
ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- Site URL (for email links)
INSERT INTO tblSettings (settingKey, settingValue, category) VALUES
('site.url', 'http://localhost', 'general')  -- Update for your environment
ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);
```

### Step 3: Email System Check

Verify email system is configured (required for passwordless login):

```sql
-- Check email provider is set
SELECT * FROM tblSettings WHERE settingKey = 'email.provider';

-- Should return: 'smtp', 'sendgrid', 'mailgun', etc.
```

If not configured, see [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md) for setup.

### Step 4: HTTPS/Localhost Setup

**For Development:**
- WebAuthn works on `http://localhost` without SSL
- Or use `https://127.0.0.1` with self-signed cert

**For Production:**
- MUST use valid HTTPS certificate
- Update `auth.webauthn.rp_id` to match your domain

### Step 5: Browser Requirements

Ensure you're using a compatible browser:
- ✅ Chrome/Edge 67+
- ✅ Safari 13+
- ✅ Firefox 60+
- ✅ Opera 54+

---

## 🧪 Testing Checklist

### ✅ Test 1: Passwordless Email Login (Easiest to Test First)

**Prerequisites:** Email system configured, valid test email

#### 1.1 Request Login Link

**Steps:**
1. Navigate to `/auth/passwordless-request`
2. Enter your email address
3. Click "Send Login Link"

**Expected Results:**
- ✅ Success message appears: "Login link sent! Please check your email."
- ✅ Email received within 1-2 minutes
- ✅ Email contains clickable button and fallback link

**Database Check:**
```sql
-- Verify token was created
SELECT * FROM tblPasswordlessTokens
WHERE email = 'your-test-email@example.com'
ORDER BY createdAt DESC LIMIT 1;
```

**Expected Fields:**
- `token`: 64-character hex string
- `tokenHash`: SHA-256 hash
- `expiresAt`: 15 minutes in future
- `isUsed`: 0

#### 1.2 Verify Login Link

**Steps:**
1. Open the email
2. Click the login link
3. Observe the verification page

**Expected Results:**
- ✅ "Logging you in..." spinner appears briefly
- ✅ Redirects to `/dashboard`
- ✅ User is now logged in
- ✅ Session created

**Database Check:**
```sql
-- Verify token was marked as used
SELECT isUsed, usedAt FROM tblPasswordlessTokens
WHERE email = 'your-test-email@example.com'
ORDER BY usedAt DESC LIMIT 1;

-- Check activity log
SELECT * FROM tblActivityLog
WHERE activityType = 'login'
AND activityDetails LIKE '%Passwordless%'
ORDER BY loggedAt DESC LIMIT 1;
```

**Expected:**
- `isUsed`: 1
- `usedAt`: Current timestamp
- Activity log entry created

#### 1.3 Test Token Expiration

**Steps:**
1. Request another login link
2. Wait 16 minutes (or manually update DB to expire it)
3. Try to use the expired link

**Database Manipulation (optional):**
```sql
-- Force token expiration for testing
UPDATE tblPasswordlessTokens
SET expiresAt = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
WHERE email = 'your-test-email@example.com'
AND isUsed = 0
ORDER BY createdAt DESC LIMIT 1;
```

**Expected Results:**
- ✅ Error message: "This login link has expired. Please request a new one."
- ✅ Redirect options displayed
- ✅ User NOT logged in

#### 1.4 Test One-Time Use

**Steps:**
1. Request login link
2. Click link (logs in successfully)
3. Try to use the same link again

**Expected Results:**
- ✅ Error: "Invalid or expired login link"
- ✅ Token cannot be reused

#### 1.5 Test Rate Limiting

**Steps:**
1. Request login link for same email 6 times rapidly

**Expected Results:**
- ✅ First 5 requests succeed
- ✅ 6th request fails: "Too many requests for this email"

**Database Check:**
```sql
-- Count tokens in last hour
SELECT COUNT(*) FROM tblPasswordlessTokens
WHERE email = 'your-test-email@example.com'
AND createdAt >= DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

---

### ✅ Test 2: PassKey Registration

**Prerequisites:**
- User must be logged in first
- Device with biometric capability (or security key)

#### 2.1 Check Browser Compatibility

**Steps:**
1. Navigate to `/auth/passkey-register`
2. Wait for compatibility check

**Expected Results:**
- ✅ "Checking device compatibility..." appears briefly
- ✅ Registration form appears (if compatible)
- ✅ OR "Not Supported" message (if incompatible)

**Browser Console Check:**
```javascript
// Open browser console and run:
if (window.PublicKeyCredential) {
    console.log('✅ WebAuthn supported');
    PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
        .then(available => console.log('Platform authenticator:', available));
} else {
    console.log('❌ WebAuthn not supported');
}
```

#### 2.2 Register PassKey

**Steps:**
1. On `/auth/passkey-register`
2. (Optional) Enter device name: "Test MacBook"
3. Click "Create PassKey"
4. Respond to browser/device prompt (TouchID, FaceID, etc.)

**Expected Results:**
- ✅ Browser shows authentication prompt
- ✅ After authentication: "Creating PassKey..." message
- ✅ Success message: "PassKey created successfully!"
- ✅ Redirects to `/settings/security` or passkeys page

**Browser Console Check:**
```javascript
// Should see:
// - "Contacting server..."
// - "Authenticating with your device..."
// - "Verifying credential..."
// - No JavaScript errors
```

**Database Check:**
```sql
-- Verify credential was stored
SELECT * FROM tblWebAuthnCredentials
WHERE userID = [YOUR_USER_ID]
ORDER BY createdAt DESC LIMIT 1;

-- Check user flag
SELECT webauthnEnabled FROM tblUsers WHERE userID = [YOUR_USER_ID];
```

**Expected:**
- New credential record with your device name
- `credentialPublicKeyID`: Base64 string
- `webauthnEnabled`: 1
- `signCount`: 0
- `isActive`: 1

#### 2.3 Test Duplicate Registration

**Steps:**
1. Try to register the same device again

**Expected Results:**
- ✅ Browser may prevent (shows "already registered")
- ✅ OR server rejects duplicate credential

#### 2.4 Register Multiple PassKeys

**Steps:**
1. Register PassKey from different device (e.g., phone)
2. Or use different authentication method on same device

**Expected Results:**
- ✅ Multiple credentials can be registered
- ✅ Each has unique credentialID

**Database Check:**
```sql
-- Count credentials for user
SELECT COUNT(*) as credential_count
FROM tblWebAuthnCredentials
WHERE userID = [YOUR_USER_ID] AND isActive = 1;
```

---

### ✅ Test 3: PassKey Authentication

**Prerequisites:** At least one PassKey registered

#### 3.1 Basic PassKey Login

**Steps:**
1. Log out completely
2. Navigate to `/auth/passkey-login`
3. (Optional) Enter your email
4. Click "Login with PassKey"
5. Respond to authentication prompt

**Expected Results:**
- ✅ Browser shows authentication prompt
- ✅ After authentication: "Login successful!"
- ✅ Redirects to `/dashboard`
- ✅ User is logged in

**Database Check:**
```sql
-- Verify credential usage was tracked
SELECT lastUsedAt, usageCount
FROM tblWebAuthnCredentials
WHERE userID = [YOUR_USER_ID]
ORDER BY lastUsedAt DESC;

-- Check activity log
SELECT * FROM tblActivityLog
WHERE activityType = 'login'
AND activityDetails LIKE '%WebAuthn%'
ORDER BY loggedAt DESC LIMIT 1;
```

**Expected:**
- `lastUsedAt`: Current timestamp
- `usageCount`: Incremented
- Activity log entry created

#### 3.2 Login Without Email (Usernameless)

**Steps:**
1. Navigate to `/auth/passkey-login`
2. Leave email field BLANK
3. Click "Login with PassKey"
4. Authenticate

**Expected Results:**
- ✅ Works if browser has discoverable credential
- ✅ May show list of available credentials
- ✅ Successfully logs in

#### 3.3 Test Wrong Device

**Steps:**
1. Try to log in from device without registered PassKey

**Expected Results:**
- ✅ Error: "No matching credentials found" or "Authentication failed"
- ✅ User NOT logged in

#### 3.4 Test Cancelled Authentication

**Steps:**
1. Start PassKey login
2. Cancel the browser prompt (press Cancel/ESC)

**Expected Results:**
- ✅ Error: "Authentication was cancelled or timed out"
- ✅ Button re-enabled to try again

---

### ✅ Test 4: PassKey Management

**Prerequisites:** At least one PassKey registered

#### 4.1 View PassKeys

**Steps:**
1. Navigate to `/settings/passkeys`

**Expected Results:**
- ✅ All registered PassKeys displayed
- ✅ Shows device name, added date, last used date
- ✅ Shows usage count
- ✅ Shows authenticator type badge
- ✅ Shows rename and remove buttons

#### 4.2 Rename PassKey

**Steps:**
1. Click "Rename" button on a PassKey
2. Enter new name: "My Updated Device Name"
3. Click "Save Changes"

**Expected Results:**
- ✅ Modal closes
- ✅ Success message appears
- ✅ PassKey name updated in list

**Database Check:**
```sql
SELECT deviceName FROM tblWebAuthnCredentials
WHERE credentialID = [CREDENTIAL_ID];
```

#### 4.3 Remove PassKey

**Steps:**
1. Click "Remove" button on a PassKey
2. Read warning message
3. Click "Remove PassKey" to confirm

**Expected Results:**
- ✅ Warning about consequences displayed
- ✅ If last PassKey: Extra warning shown
- ✅ PassKey removed from list
- ✅ Success message appears

**Database Check:**
```sql
SELECT isActive, revokedAt, revokeReason
FROM tblWebAuthnCredentials
WHERE credentialID = [CREDENTIAL_ID];
```

**Expected:**
- `isActive`: 0
- `revokedAt`: Current timestamp
- `revokeReason`: 'user_request'

#### 4.4 Test Revoked Credential

**Steps:**
1. After removing PassKey, try to use it for login

**Expected Results:**
- ✅ Login fails: "Credential not found or inactive"
- ✅ Cannot authenticate with revoked PassKey

---

## 🔒 Security Testing

### Test 5: Security Features

#### 5.1 Challenge Replay Attack

**Steps:**
1. Start PassKey registration
2. Capture the challenge from browser DevTools (Network tab)
3. Try to replay the same challenge

**Expected Results:**
- ✅ Challenge marked as used after first verification
- ✅ Replay attempt fails

**Database Check:**
```sql
SELECT isUsed FROM tblWebAuthnChallenges
ORDER BY createdAt DESC LIMIT 1;
```

#### 5.2 Expired Challenge

**Steps:**
1. Start registration
2. Wait 6 minutes without completing
3. Try to complete registration

**Expected Results:**
- ✅ Error: "Invalid or expired challenge"

**Force expiration (optional):**
```sql
UPDATE tblWebAuthnChallenges
SET expiresAt = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
WHERE isUsed = 0
ORDER BY createdAt DESC LIMIT 1;
```

#### 5.3 Cross-Origin Attack Simulation

**Steps:**
1. Change `auth.webauthn.rp_id` to different domain
2. Try to register/authenticate

**Expected Results:**
- ✅ Origin verification fails
- ✅ Error: "Origin mismatch"

#### 5.4 Token Attempt Limit

**Steps:**
1. Request passwordless login link
2. Try to verify with wrong token 3 times

**Expected Results:**
- ✅ After 3 attempts: "Too many attempts"
- ✅ Must request new link

**Simulate (optional):**
```sql
UPDATE tblPasswordlessTokens
SET attemptCount = 2
WHERE isUsed = 0
ORDER BY createdAt DESC LIMIT 1;
```

---

## 📊 Data Verification Tests

### Test 6: Database Integrity

#### 6.1 Check Relationships

```sql
-- Verify all credentials belong to valid users
SELECT COUNT(*) as orphaned_credentials
FROM tblWebAuthnCredentials wac
LEFT JOIN tblUsers u ON wac.userID = u.userID
WHERE u.userID IS NULL;
```

**Expected:** 0 orphaned credentials

#### 6.2 Check Token Cleanup

```sql
-- Count expired but not cleaned tokens
SELECT COUNT(*) as expired_tokens
FROM tblPasswordlessTokens
WHERE expiresAt < DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

**Run cleanup:**
```sql
CALL cleanupExpiredAuthTokens();
```

#### 6.3 Verify Encryption

```sql
-- Check sensitive data is not plaintext
SELECT token FROM tblPasswordlessTokens LIMIT 1;
SELECT credentialPublicKey FROM tblWebAuthnCredentials LIMIT 1;
```

**Expected:**
- Tokens are 64-char hex (looks random)
- Public keys are Base64 encoded

---

## 🌐 Cross-Browser Testing

### Test 7: Browser Compatibility

Test on multiple browsers:

| Browser | Version | PassKey Registration | PassKey Login | Passwordless Email |
|---------|---------|---------------------|---------------|-------------------|
| Chrome | Latest | ☐ | ☐ | ☐ |
| Safari | Latest | ☐ | ☐ | ☐ |
| Firefox | Latest | ☐ | ☐ | ☐ |
| Edge | Latest | ☐ | ☐ | ☐ |

**Notes for each browser:**
- Chrome/Edge: Should work identically
- Safari: Check iOS TouchID/FaceID support
- Firefox: May have different UI for prompts

---

## 📱 Device Testing

### Test 8: Multi-Device Support

Test PassKey registration/authentication on:

| Device | OS | Browser | Registration | Authentication |
|--------|----|---------|--------------| --------------|
| iPhone | iOS | Safari | ☐ | ☐ |
| Android | Android | Chrome | ☐ | ☐ |
| Mac | macOS | Safari/Chrome | ☐ | ☐ |
| Windows | Win 11 | Edge/Chrome | ☐ | ☐ |
| Linux | Ubuntu | Firefox/Chrome | ☐ | ☐ |

---

## ⚠️ Error Scenarios

### Test 9: Error Handling

#### 9.1 Network Interruption

**Steps:**
1. Start PassKey registration
2. Disable internet during process
3. Re-enable internet

**Expected:**
- ✅ Graceful error message
- ✅ Option to retry

#### 9.2 Database Unavailable

**Steps:**
1. Temporarily stop database
2. Try to register PassKey

**Expected:**
- ✅ Error message (not database details)
- ✅ Logged to error log

#### 9.3 Email Service Down

**Steps:**
1. Misconfigure email settings
2. Request passwordless link

**Expected:**
- ✅ Generic error: "Failed to send email"
- ✅ Does NOT reveal email config details

---

## 📝 Testing Checklist Summary

Print this checklist and check off each item:

### Passwordless Email Login
- [ ] Request link successfully
- [ ] Receive email with link
- [ ] Click link and login
- [ ] Token expires after 15 minutes
- [ ] Token cannot be reused
- [ ] Rate limiting works (5 per hour)
- [ ] Wrong token shows error
- [ ] Email not found doesn't reveal info

### PassKey Registration
- [ ] Compatibility check works
- [ ] Browser shows auth prompt
- [ ] Credential stored in database
- [ ] Device name saved
- [ ] Multiple PassKeys can be registered
- [ ] User flag updated (webauthnEnabled)

### PassKey Authentication
- [ ] Can login with PassKey
- [ ] With email works
- [ ] Without email works (usernameless)
- [ ] Wrong device shows error
- [ ] Cancel prompt shows error
- [ ] Usage count increments
- [ ] Activity logged

### PassKey Management
- [ ] View all PassKeys
- [ ] Rename PassKey works
- [ ] Remove PassKey works
- [ ] Removed PassKey can't login
- [ ] Warning shown if last PassKey

### Security
- [ ] Challenge replay prevented
- [ ] Expired challenge rejected
- [ ] Origin verification works
- [ ] Attempt limits enforced
- [ ] Tokens properly hashed
- [ ] Activity logged correctly

### Cross-Browser
- [ ] Chrome/Edge works
- [ ] Safari works
- [ ] Firefox works

### Cross-Device
- [ ] iOS works
- [ ] Android works
- [ ] Windows works
- [ ] macOS works

---

## 🐛 Common Issues & Solutions

### Issue: "Browser not supported"

**Cause:** Old browser or WebAuthn API not available

**Solution:**
- Update browser to latest version
- Check if HTTPS is enabled (required except localhost)
- Try different browser

### Issue: "Failed to create credential"

**Cause:** Various - authentication cancelled, timeout, duplicate

**Solution:**
- Check browser console for specific error
- Try again
- Check if credential already exists

### Issue: "Origin mismatch"

**Cause:** `rp_id` doesn't match domain

**Solution:**
```sql
UPDATE tblSettings
SET settingValue = 'yourdomain.com'
WHERE settingKey = 'auth.webauthn.rp_id';
```

### Issue: "Email not received"

**Cause:** Email system not configured or misconfigured

**Solution:**
- Check email settings in database
- Check spam folder
- Check email service logs
- Test email system separately

### Issue: "Challenge expired"

**Cause:** Took too long to complete authentication

**Solution:**
- Complete authentication faster
- Increase challenge validity:
```sql
UPDATE tblSettings
SET settingValue = '10'
WHERE settingKey = 'auth.webauthn.challenge_validity';
```

---

## 📞 Getting Help

If you encounter issues during testing:

1. **Check browser console** for JavaScript errors
2. **Check server error logs** for PHP errors
3. **Query database** to verify data is being saved
4. **Review documentation** in AUTH_PHASE1_DOCUMENTATION.md
5. **Create detailed bug report** with:
   - Steps to reproduce
   - Expected vs actual behavior
   - Browser and OS version
   - Any error messages
   - Screenshots if applicable

---

## ✅ Sign-Off

Once all tests pass, sign off:

**Tested By:** ________________
**Date:** ________________
**Environment:** ________________
**Result:** PASS / FAIL

**Notes:**
_____________________________________
_____________________________________
_____________________________________

---

**Version:** 1.4.0
**Last Updated:** 2026-02-02
**Status:** Ready for Testing
