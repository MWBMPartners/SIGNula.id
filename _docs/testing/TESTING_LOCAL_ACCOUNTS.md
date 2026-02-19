# Testing Local Accounts -- SIGNula

**Version:** 2.4.0-beta
**Last Updated:** February 18, 2026
**Status:** Active Testing Guide

> Comprehensive testing guide for SIGNula's local account system including registration, login, MFA, passkeys, and passwordless authentication.

---

## Prerequisites & Setup

Before running these tests, ensure the following environment is ready:

| Requirement | Details |
|-------------|---------|
| PHP | 8.3+ (target 8.4) |
| MySQL/MariaDB | 8.0+ / 10.6+ |
| Web Server | Apache with mod_rewrite or Nginx |
| Migrations | All migrations applied (001-013) |
| SMTP | Configured for email testing (or use Mailtrap / MailHog for local capture) |
| CAPTCHA | CloudFlare Turnstile or reCAPTCHA keys in `tblSettings`, or disabled for testing |
| Browser | Chrome/Edge (latest) with DevTools available |
| Authenticator App | Google Authenticator, Microsoft Authenticator, or similar TOTP app |

**Database Verification:**

```sql
-- Confirm core tables exist
SHOW TABLES LIKE 'tblUsers';
SHOW TABLES LIKE 'tblSessions';
SHOW TABLES LIKE 'tblActivityLog';
SHOW TABLES LIKE 'tblWebAuthnCredentials';
SHOW TABLES LIKE 'tblMFABackupCodes';
SHOW TABLES LIKE 'tblSettings';
SHOW TABLES LIKE 'tblErrorLog';
```

**Test Account Conventions:**

Throughout this guide, use the following test data unless otherwise noted:

| Field | Value |
|-------|-------|
| Email | `testuser@example.com` |
| Password | `SecurePass123!` |
| Display Name | `Test User` |
| Username | `testuser_001` |

---

## 1. Account Registration

### 1.1 Form Validation

Navigate to the registration page (`/register` or `/auth/register`).

**Positive Tests (Happy Path):**

- [ ] Submit with all required fields (name, email, password) filled correctly
  - **Expected:** Account created, verification email sent, success message displayed
- [ ] Submit with optional fields (first name, last name) populated
  - **Expected:** All fields stored in `tblUsers`
- [ ] Submit with a strong password meeting all requirements (8+ chars, uppercase, lowercase, number, special char)
  - **Expected:** Password accepted, account created
- [ ] Confirm the `accept_terms` checkbox is required
  - **Expected:** Form will not submit without terms acceptance

**Negative Tests (Validation Failures):**

- [ ] Submit with empty email field
  - **Expected:** Validation error "Email is required"
- [ ] Submit with invalid email format (e.g., `notanemail`, `user@`, `@domain.com`)
  - **Expected:** Validation error "Please enter a valid email address"
- [ ] Submit with empty password field
  - **Expected:** Validation error "Password is required"
- [ ] Submit with weak password (e.g., `12345`, `password`, `abc`)
  - **Expected:** Validation error indicating password strength requirements
- [ ] Submit with password missing uppercase character
  - **Expected:** Validation error for password complexity
- [ ] Submit with password missing special character
  - **Expected:** Validation error for password complexity
- [ ] Submit with password shorter than 8 characters
  - **Expected:** Validation error for minimum length
- [ ] Submit with an email already registered in the system
  - **Expected:** Error "An account with this email already exists" (or generic message for security)
- [ ] Submit with `accept_terms` unchecked
  - **Expected:** Validation error "You must accept the Terms of Use"

**CAPTCHA Integration:**

- [ ] Verify CAPTCHA widget renders on the registration form (if API keys are configured)
  - **Expected:** CloudFlare Turnstile or reCAPTCHA widget visible
- [ ] Submit form without completing CAPTCHA
  - **Expected:** Form submission blocked or validation error
- [ ] Complete CAPTCHA and submit valid registration
  - **Expected:** CAPTCHA passes, account created
- [ ] Verify no CAPTCHA rendered when API keys are not configured in `tblSettings`
  - **Expected:** Form functions without CAPTCHA, no errors

### 1.2 Email Verification

After successful registration:

- [ ] Check that a verification email is sent to the registered address
  - **Expected:** Email appears in Mailtrap/MailHog or real inbox within 30 seconds
- [ ] Verify the email contains a tokenised verification link
  - **Expected:** Link format similar to `https://signula.id/auth/verify-email?token=<token>`
- [ ] Click the verification link
  - **Expected:** Account marked as verified in `tblUsers` (`emailVerified = 1`), success message shown
- [ ] Click the verification link a second time (already used)
  - **Expected:** Informational message "Email already verified" or "Link expired"
- [ ] Wait for the token to expire (default: 15-30 minutes), then click the link
  - **Expected:** Error "Verification link has expired. Please request a new one."
- [ ] Request a new verification email via the "Resend Verification" mechanism
  - **Expected:** New email sent with a fresh token, old token invalidated
- [ ] Attempt to access a protected resource (e.g., `/settings/profile`) before verifying email
  - **Expected:** Redirect to verification prompt page, or error "Please verify your email first"

**Database Verification:**

```sql
-- Check verification status after clicking link
SELECT userID, email, emailVerified, emailVerifiedAt
FROM tblUsers
WHERE email = 'testuser@example.com';
```

### 1.3 Edge Cases

- [ ] Submit a very long name (255+ characters)
  - **Expected:** Graceful handling -- either truncated to max field length or validation error
- [ ] Submit a very long email address (254 characters, the RFC 5321 maximum)
  - **Expected:** Accepted if valid, rejected if exceeding database field length
- [ ] Submit a name with Unicode/international characters (e.g., `Jose Garcia`, `Takeshi Tanaka`, `Olafur Bjornsson`)
  - **Expected:** Characters stored and displayed correctly (UTF-8 support)
- [ ] Submit a name with emoji characters
  - **Expected:** Graceful handling (stored if utf8mb4 supported, or validation error)
- [ ] Attempt multiple rapid registrations with the same email in quick succession
  - **Expected:** Only one account created, subsequent attempts show duplicate error
- [ ] Attempt SQL injection in email field (e.g., `' OR '1'='1`)
  - **Expected:** Treated as literal string, prepared statement prevents injection
- [ ] Attempt SQL injection in name field (e.g., `'; DROP TABLE tblUsers; --`)
  - **Expected:** Treated as literal string, no SQL execution
- [ ] Attempt XSS in name field (e.g., `<script>alert('XSS')</script>`)
  - **Expected:** Stored safely, rendered as escaped text on display

**Activity Log Verification:**

```sql
-- Verify registration events are logged
SELECT activityType, activityResult, ipAddress, userAgent, createdAt
FROM tblActivityLog
WHERE activityType = 'account_created'
ORDER BY createdAt DESC LIMIT 5;
```

---

## 2. Login Flow

### 2.1 Email/Password Login

Navigate to the login page (`/login` or `/auth/login`).

**Positive Tests:**

- [ ] Login with correct email and password (verified account)
  - **Expected:** Redirected to dashboard/home page, session created
- [ ] Verify session record created in `tblSessions`
  - **Expected:** New row with session token, user agent, IP address, expiry
- [ ] Verify activity logged in `tblActivityLog` with type `login` and result `success`

**Negative Tests:**

- [ ] Login with correct email but incorrect password
  - **Expected:** Error "Invalid email or password" (generic message for security)
- [ ] Login with non-existent email address
  - **Expected:** Same generic error "Invalid email or password"
- [ ] Login with empty email field
  - **Expected:** Client-side validation prevents submission
- [ ] Login with empty password field
  - **Expected:** Client-side validation prevents submission
- [ ] Login with unverified email account
  - **Expected:** Error "Please verify your email address first" with option to resend

### 2.2 Session Management

After successful login:

- [ ] Verify session cookie is set with `HttpOnly` flag
  - **Expected:** Cookie visible in DevTools > Application > Cookies with HttpOnly checked
- [ ] Verify session cookie has `Secure` flag (when on HTTPS)
  - **Expected:** Secure flag set
- [ ] Verify session cookie has `SameSite` attribute set
  - **Expected:** `SameSite=Lax` or `SameSite=Strict`
- [ ] Navigate to multiple protected pages while logged in
  - **Expected:** All pages accessible, no re-authentication required
- [ ] Check that the user's name and avatar appear in the top-right corner
  - **Expected:** Display name shown, avatar loaded (from linked account, local upload, Gravatar, or placeholder)

### 2.3 Remember Me

- [ ] Login with "Remember Me" checked
  - **Expected:** Session cookie has extended expiry (e.g., 30 days instead of session-only)
- [ ] Close browser entirely, re-open and navigate to the site
  - **Expected:** Still logged in (session persisted)
- [ ] Login without "Remember Me"
  - **Expected:** Session cookie is a session cookie (expires when browser closes)
- [ ] Close browser, re-open, navigate to site
  - **Expected:** Logged out, must re-authenticate

### 2.4 Failed Login Attempts & Lockout

- [ ] Attempt 3 consecutive failed logins with wrong password
  - **Expected:** Warning message about remaining attempts
- [ ] Attempt 5+ consecutive failed logins (configurable threshold)
  - **Expected:** Account temporarily locked, message "Account locked due to too many failed attempts. Try again in X minutes."
- [ ] Verify lockout event logged in `tblActivityLog` with type `account_locked`
- [ ] Wait for lockout period to expire, then login with correct credentials
  - **Expected:** Login succeeds after lockout period
- [ ] Attempt login during lockout with correct credentials
  - **Expected:** Still locked, error message with time remaining
- [ ] Verify rate limiting on login endpoint prevents brute force
  - **Expected:** HTTP 429 after excessive attempts from same IP

---

## 3. Password Reset

### 3.1 Forgot Password Request

Navigate to the "Forgot Password" page (`/auth/forgot-password`).

- [ ] Submit with a registered email address
  - **Expected:** Success message "If an account exists with that email, a reset link has been sent"
- [ ] Submit with an unregistered email address
  - **Expected:** Same success message (to prevent email enumeration)
- [ ] Submit with empty email field
  - **Expected:** Client-side validation error
- [ ] Submit with invalid email format
  - **Expected:** Validation error "Please enter a valid email address"

### 3.2 Reset Email & Link

- [ ] Verify reset email is received in inbox/Mailtrap
  - **Expected:** Email contains a tokenised reset link
- [ ] Verify the link format includes a secure, unguessable token
  - **Expected:** URL like `https://signula.id/auth/reset-password?token=<64-char-hex-token>`
- [ ] Verify the reset token has an expiry time stored in the database
  - **Expected:** Token row has an `expiresAt` value (e.g., 1 hour from creation)

### 3.3 New Password Form

- [ ] Click the valid reset link and verify the reset form loads
  - **Expected:** Form displayed with fields for new password and confirmation
- [ ] Enter a new strong password and confirm it
  - **Expected:** Password updated, success message, old password no longer works
- [ ] Login with the new password
  - **Expected:** Login succeeds
- [ ] Login with the old password
  - **Expected:** Login fails

### 3.4 Edge Cases

- [ ] Click the reset link after the token has expired
  - **Expected:** Error "This reset link has expired. Please request a new one."
- [ ] Click the reset link after it has already been used
  - **Expected:** Error "This reset link has already been used."
- [ ] Request multiple password resets in quick succession
  - **Expected:** Only the most recent token is valid (previous tokens invalidated)
- [ ] Attempt to reuse a reset token after password change
  - **Expected:** Token marked as used, cannot be reused
- [ ] Verify that all existing sessions are invalidated after password reset
  - **Expected:** User logged out from all devices, must re-authenticate
- [ ] Verify activity logged: `password_reset_requested` and `password_reset_completed`

---

## 4. Multi-Factor Authentication (MFA)

### 4.1 TOTP Setup (Authenticator Apps)

Navigate to MFA settings (`/settings/mfa` or `/settings/security`).

- [ ] Click "Enable MFA" or "Set Up Two-Factor Authentication"
  - **Expected:** QR code displayed along with a text-based secret key
- [ ] Verify the QR code is scannable by Google Authenticator
  - **Expected:** App adds the SIGNula account entry
- [ ] Verify the QR code is scannable by Microsoft Authenticator
  - **Expected:** App adds the SIGNula account entry
- [ ] Use the text-based secret to manually add the account in an authenticator app
  - **Expected:** App generates valid 6-digit codes
- [ ] Enter a valid TOTP code from the app to confirm setup
  - **Expected:** MFA enabled successfully, confirmation message shown
- [ ] Verify `tblUsers` updated with MFA enabled status
  - **Expected:** `mfaEnabled = 1`, `mfaSecret` stored (encrypted)

### 4.2 TOTP Verification

After enabling MFA, log out and attempt login:

- [ ] Login with correct email and password
  - **Expected:** Redirected to MFA verification page (not dashboard)
- [ ] Enter a valid TOTP code from the authenticator app
  - **Expected:** Login completes, redirected to dashboard
- [ ] Enter an incorrect TOTP code
  - **Expected:** Error "Invalid verification code. Please try again."
- [ ] Enter an expired TOTP code (previous 30-second window, depending on time tolerance)
  - **Expected:** May be accepted if within the allowed time skew window (typically +/- 1 window)
- [ ] Enter the same TOTP code twice (replay attack)
  - **Expected:** Second use rejected (if replay protection is implemented)
- [ ] Leave MFA verification page idle past the MFA token expiry (5 minutes)
  - **Expected:** MFA token expires, must restart login

### 4.3 Backup Codes

During or after MFA setup:

- [ ] Verify backup codes are generated and displayed (typically 5-10 codes)
  - **Expected:** Codes displayed with option to copy/print/download
- [ ] Copy backup codes to clipboard
  - **Expected:** All codes copied, confirmation toast shown
- [ ] Print backup codes
  - **Expected:** Print-friendly format generated
- [ ] Use a backup code to complete MFA verification during login
  - **Expected:** Login succeeds, backup code accepted
- [ ] Verify the used backup code is marked as consumed in `tblMFABackupCodes`
  - **Expected:** `usedAt` timestamp populated for that code
- [ ] Attempt to reuse a consumed backup code
  - **Expected:** Error "This backup code has already been used"
- [ ] Regenerate backup codes from the MFA settings page
  - **Expected:** New set of codes generated, all previous codes invalidated
- [ ] Verify old backup codes no longer work after regeneration
  - **Expected:** Old codes rejected

**Database Verification:**

```sql
-- Check backup code status
SELECT codeHash, usedAt, createdAt
FROM tblMFABackupCodes
WHERE userID = (SELECT userID FROM tblUsers WHERE email = 'testuser@example.com');
```

### 4.4 MFA Disable Flow

- [ ] Navigate to MFA settings and click "Disable MFA"
  - **Expected:** Confirmation prompt requiring current password and/or TOTP code
- [ ] Enter current password and valid TOTP code to confirm disable
  - **Expected:** MFA disabled, confirmation message
- [ ] Login again without MFA
  - **Expected:** No MFA verification step, direct login
- [ ] Verify `tblUsers` updated: `mfaEnabled = 0`
- [ ] Verify activity logged: `mfa_disabled`

### 4.5 Recovery Scenarios

- [ ] User has lost access to their authenticator app and has backup codes
  - **Steps:** Login, enter backup code on MFA page, access account, re-setup MFA
  - **Expected:** Can recover access and reconfigure MFA
- [ ] User has lost access to authenticator app and all backup codes
  - **Steps:** Contact support or use account recovery flow
  - **Expected:** Documented recovery process available
- [ ] User's authenticator app is on a new device (transferred)
  - **Steps:** Login using backup code, disable MFA, re-enable MFA on new device
  - **Expected:** Successful migration to new device

---

## 5. Passkey Authentication (WebAuthn)

### 5.1 Passkey Registration

Navigate to PassKey registration (`/settings/passkeys` or `/auth/passkey-register`).

- [ ] Click "Register PassKey" / "Add PassKey"
  - **Expected:** Browser's WebAuthn prompt appears (biometric, PIN, or security key)
- [ ] Complete the biometric/PIN challenge
  - **Expected:** PassKey registered successfully, success message displayed
- [ ] Verify credential stored in `tblWebAuthnCredentials`
  - **Expected:** New row with `credentialID`, `publicKey`, `signCount`, `userID`
- [ ] Assign a friendly name to the PassKey (e.g., "MacBook Pro Touch ID")
  - **Expected:** Name saved and displayed in PassKey list
- [ ] Attempt to register a second PassKey (different device/method)
  - **Expected:** Second PassKey registered, both shown in list

**Database Verification:**

```sql
SELECT credentialID, credentialName, signCount, createdAt, lastUsedAt
FROM tblWebAuthnCredentials
WHERE userID = (SELECT userID FROM tblUsers WHERE email = 'testuser@example.com');
```

### 5.2 Passkey Login

Navigate to PassKey login (`/auth/passkey-login`).

- [ ] Enter email address and click "Login with PassKey"
  - **Expected:** Browser's WebAuthn authentication prompt appears
- [ ] Complete the biometric/PIN challenge
  - **Expected:** Login succeeds, redirected to dashboard
- [ ] Verify `signCount` incremented in `tblWebAuthnCredentials`
- [ ] Verify `lastUsedAt` updated
- [ ] Verify session created in `tblSessions`
- [ ] Verify activity logged with type `passkey_login`

**Negative Tests:**

- [ ] Enter email for account with no registered PassKeys
  - **Expected:** Error "No PassKeys registered for this account"
- [ ] Cancel the WebAuthn prompt
  - **Expected:** Login aborted, user returned to login page
- [ ] Attempt PassKey login with wrong biometric (if device supports)
  - **Expected:** Device-level rejection, login does not proceed

### 5.3 Passkey Management

Navigate to PassKey management (`/settings/passkeys`).

- [ ] View list of all registered PassKeys
  - **Expected:** All PassKeys shown with name, type, last used date, registration date
- [ ] Rename a PassKey
  - **Expected:** New name saved and displayed
- [ ] Delete/revoke a PassKey
  - **Expected:** Confirmation prompt shown, PassKey removed from list and database
- [ ] Attempt to login with a deleted PassKey
  - **Expected:** Authentication fails

### 5.4 Cross-Device Testing

- [ ] Register PassKey on macOS (Touch ID)
  - **Expected:** PassKey registered successfully
- [ ] Register PassKey on Windows (Windows Hello)
  - **Expected:** PassKey registered successfully
- [ ] Register PassKey on iOS (Face ID / Touch ID via Safari)
  - **Expected:** PassKey registered successfully
- [ ] Register PassKey on Android (fingerprint / PIN)
  - **Expected:** PassKey registered successfully
- [ ] Test cross-device PassKey login (register on device A, login on device B using QR code/Bluetooth)
  - **Expected:** Cross-device authentication works if supported by browser/OS

---

## 6. Passwordless Login

### 6.1 Email Magic Link

Navigate to passwordless login (`/auth/passwordless-request`).

- [ ] Enter registered email and request magic link
  - **Expected:** Email sent with a secure, tokenised login link
- [ ] Click the magic link within the validity window
  - **Expected:** Logged in automatically, redirected to dashboard
- [ ] Verify session created and activity logged with type `passwordless_login`
- [ ] Click the same magic link a second time
  - **Expected:** Error "This login link has already been used"
- [ ] Request magic link for an unregistered email
  - **Expected:** Same success message (prevents email enumeration), but no email sent

**Negative Tests:**

- [ ] Click the magic link after it has expired (default: 15-30 minutes)
  - **Expected:** Error "This login link has expired. Please request a new one."
- [ ] Modify the token in the magic link URL
  - **Expected:** Error "Invalid login link"
- [ ] Open the magic link in a different browser than the one that requested it
  - **Expected:** Should still work (magic links are not browser-bound)

### 6.2 One-Time Code (OTP)

Navigate to OTP login (if separate from magic link):

- [ ] Request a one-time code via email
  - **Expected:** 6-digit code sent to email
- [ ] Enter the correct OTP code
  - **Expected:** Login succeeds
- [ ] Enter an incorrect OTP code
  - **Expected:** Error "Invalid code. Please try again."
- [ ] Enter the OTP code after it has expired
  - **Expected:** Error "Code has expired. Please request a new one."
- [ ] Attempt to use the same OTP code twice
  - **Expected:** Second use rejected

### 6.3 Expiry Testing

- [ ] Verify the default expiry window for magic links (check `tblSettings` value)
  - **Expected:** Default 15-30 minutes, configurable via database
- [ ] Verify the default expiry window for OTP codes
  - **Expected:** Default 5-10 minutes, configurable
- [ ] Change the expiry setting in `tblSettings` and verify the new value is respected

```sql
-- Check passwordless token settings
SELECT settingKey, settingValue
FROM tblSettings
WHERE settingKey LIKE '%passwordless%' OR settingKey LIKE '%magic_link%' OR settingKey LIKE '%otp%';
```

### 6.4 Rate Limiting

- [ ] Request 6+ magic links in rapid succession
  - **Expected:** Rate limited after threshold (e.g., 3-5 requests per 15 minutes)
- [ ] Request 6+ OTP codes in rapid succession
  - **Expected:** Rate limited, error "Too many requests. Please try again later."

---

## 7. Profile Management

### 7.1 Update Display Name

Navigate to profile settings (`/settings/profile`).

- [ ] Change display name to a valid new name
  - **Expected:** Name updated, success message, new name shown in top-right corner
- [ ] Enter an empty display name
  - **Expected:** Validation error "Display name is required"
- [ ] Enter display name with special characters
  - **Expected:** Accepted and displayed correctly
- [ ] Verify activity logged: `profile_updated`

### 7.2 Update Email

- [ ] Click "Change Email" and enter a new valid email address
  - **Expected:** Prompt for current password, then verification email sent to new address
- [ ] Enter current password to confirm
  - **Expected:** Verification email sent to new address
- [ ] Click the verification link in the new email
  - **Expected:** Email updated in `tblUsers`, confirmation message
- [ ] Verify old email no longer works for login
  - **Expected:** Login with old email fails
- [ ] Verify new email works for login
  - **Expected:** Login with new email succeeds
- [ ] Attempt to change email to one already used by another account
  - **Expected:** Error "This email is already associated with another account"

### 7.3 Change Password

- [ ] Navigate to password change form
- [ ] Enter current password, new password, and confirmation
  - **Expected:** Password changed, success message
- [ ] Attempt with incorrect current password
  - **Expected:** Error "Current password is incorrect"
- [ ] Attempt with mismatched new password and confirmation
  - **Expected:** Error "Passwords do not match"
- [ ] Attempt with a new password that does not meet strength requirements
  - **Expected:** Validation error for password strength
- [ ] Verify all existing sessions invalidated after password change (except current)
  - **Expected:** Other sessions terminated, activity logged

### 7.4 Avatar Upload

- [ ] Upload a valid image (JPEG, PNG, GIF, WebP)
  - **Expected:** Avatar updated, shown in top-right corner and profile page
- [ ] Upload an oversized image (e.g., > 5MB)
  - **Expected:** Error "File size exceeds the maximum allowed (X MB)"
- [ ] Upload a non-image file (e.g., `.pdf`, `.exe`)
  - **Expected:** Error "Invalid file type. Please upload an image."
- [ ] Verify avatar priority order: linked account (MS365/Google) > local upload > Gravatar > placeholder

---

## 8. Session Management

### 8.1 Active Sessions List

Navigate to session management (`/settings/security` or `/settings/sessions`).

- [ ] View list of all active sessions
  - **Expected:** Shows device type, browser, OS, IP address, location, last activity
- [ ] Verify current session is clearly marked (e.g., "Current Session" badge)
- [ ] Login from a second device/browser and verify it appears in the list

### 8.2 Revoke Individual Sessions

- [ ] Click "Revoke" / "End Session" on a non-current session
  - **Expected:** Session terminated, removed from list
- [ ] Verify the revoked session's device is logged out
  - **Expected:** Next request from that device requires re-authentication
- [ ] Verify activity logged: `session_revoked`

### 8.3 Revoke All Sessions

- [ ] Click "Revoke All Other Sessions" / "Log Out Everywhere"
  - **Expected:** All sessions except current are terminated
- [ ] Verify current session remains active
- [ ] Verify all other devices are logged out
- [ ] Verify activity logged: `all_sessions_revoked`

---

## 9. Activity Log

### 9.1 Verify Events Logged

Navigate to activity log (`/settings/activity`).

Confirm the following event types appear after performing the corresponding actions:

- [ ] `account_created` -- after registration
- [ ] `email_verified` -- after clicking verification link
- [ ] `login` (success) -- after successful login
- [ ] `login` (failure) -- after failed login attempt
- [ ] `logout` -- after logging out
- [ ] `password_changed` -- after changing password
- [ ] `password_reset_requested` -- after requesting reset
- [ ] `password_reset_completed` -- after completing reset
- [ ] `mfa_enabled` -- after enabling MFA
- [ ] `mfa_disabled` -- after disabling MFA
- [ ] `passkey_registered` -- after adding a PassKey
- [ ] `passkey_login` -- after logging in with a PassKey
- [ ] `passkey_deleted` -- after removing a PassKey
- [ ] `passwordless_login` -- after magic link / OTP login
- [ ] `profile_updated` -- after updating profile fields
- [ ] `session_revoked` -- after revoking a session
- [ ] `account_locked` -- after too many failed login attempts

### 9.2 Log Entry Details

For each activity log entry, verify:

- [ ] Correct `activityType` value
- [ ] Correct `activityResult` (success/failure)
- [ ] IPv4 or IPv6 address recorded in `ipAddress`
- [ ] User agent string recorded in `userAgent`
- [ ] Timestamp in `createdAt` matches the action time
- [ ] Associated `userID` is correct
- [ ] Additional details stored in JSON format (if applicable)

### 9.3 Activity Log UI Features

- [ ] Pagination works correctly (25 items per page)
- [ ] Filter by activity type works
- [ ] Filter by result (success/failure) works
- [ ] Date range filter works
- [ ] Keyword search works
- [ ] Export to CSV produces a valid CSV file
- [ ] Export to JSON produces valid JSON

---

## 10. Error Scenarios & Security

### 10.1 Rate Limiting

- [ ] Send 30+ requests per minute to login endpoint from same IP
  - **Expected:** HTTP 429 "Too Many Requests" after threshold
- [ ] Verify rate limit headers in response: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`
- [ ] Verify rate limit violation logged in `tblActivityLog`
- [ ] Wait for rate limit window to reset, then verify requests succeed again

### 10.2 CSRF Protection

- [ ] Verify all forms include a hidden CSRF token field
  - **Expected:** `<input type="hidden" name="csrf_token" value="...">` present
- [ ] Submit a form without the CSRF token (remove it via DevTools)
  - **Expected:** Error "Invalid or missing CSRF token"
- [ ] Submit a form with an expired CSRF token
  - **Expected:** Error "CSRF token expired"
- [ ] Submit a form with a CSRF token from a different session
  - **Expected:** Error "Invalid CSRF token"
- [ ] Verify AJAX endpoints also validate CSRF tokens
  - **Expected:** AJAX requests without valid token return 403

### 10.3 XSS Prevention

- [ ] Enter `<script>alert('XSS')</script>` in the display name field and save
  - **Expected:** Script tags stored as text, rendered as escaped HTML (visible as literal text)
- [ ] Enter `<img src=x onerror=alert('XSS')>` in any user-editable field
  - **Expected:** HTML entities escaped on output, no script execution
- [ ] Verify all user-supplied data uses `htmlspecialchars()` with `ENT_QUOTES` on output
- [ ] Check Content-Security-Policy headers are present in responses
  - **Expected:** CSP header present, restricting inline scripts

### 10.4 Session Fixation

- [ ] Note the session ID before login
- [ ] Login with valid credentials
- [ ] Check that the session ID has changed after login
  - **Expected:** `session_regenerate_id()` called, new session ID issued
- [ ] Attempt to use the pre-login session ID
  - **Expected:** Session not found or invalid

### 10.5 SQL Injection Prevention

- [ ] Enter `' OR '1'='1` in the email field on the login page
  - **Expected:** Treated as literal string, login fails with "Invalid credentials"
- [ ] Enter `'; DROP TABLE tblUsers; --` in any form field
  - **Expected:** No SQL execution, prepared statement handles safely
- [ ] Enter `1 AND SLEEP(5)` in numeric input fields
  - **Expected:** No delay, input treated as invalid

### 10.6 Secure Headers

Verify the following HTTP response headers are present:

- [ ] `Strict-Transport-Security: max-age=31536000; includeSubDomains`
- [ ] `Content-Security-Policy` (with appropriate directives)
- [ ] `X-Content-Type-Options: nosniff`
- [ ] `X-Frame-Options: DENY` or `SAMEORIGIN`
- [ ] `X-XSS-Protection: 1; mode=block` (legacy browser support)
- [ ] `Referrer-Policy: strict-origin-when-cross-origin`

```bash
# Check response headers
curl -I https://signula.id/login
```

---

## Test Execution Tracking

| Section | Total Tests | Passed | Failed | Blocked | Notes |
|---------|-------------|--------|--------|---------|-------|
| 1. Registration | 22 | -- | -- | -- | |
| 2. Login Flow | 18 | -- | -- | -- | |
| 3. Password Reset | 14 | -- | -- | -- | |
| 4. MFA | 22 | -- | -- | -- | |
| 5. PassKeys | 16 | -- | -- | -- | |
| 6. Passwordless | 14 | -- | -- | -- | |
| 7. Profile Management | 14 | -- | -- | -- | |
| 8. Session Management | 8 | -- | -- | -- | |
| 9. Activity Log | 24 | -- | -- | -- | |
| 10. Security | 20 | -- | -- | -- | |
| **Total** | **172** | **--** | **--** | **--** | |

---

## Related Documentation

- [TESTING_THIRD_PARTY_LINKING.md](TESTING_THIRD_PARTY_LINKING.md) -- OAuth provider linking tests
- [TESTING_API_INTEGRATION.md](TESTING_API_INTEGRATION.md) -- API integration testing
- [../TESTING_GUIDE_COMPREHENSIVE.md](../TESTING_GUIDE_COMPREHENSIVE.md) -- Overall testing guide
- [../SECURITY_TESTING_GUIDE.md](../SECURITY_TESTING_GUIDE.md) -- Security-specific testing
- [../authentication/OAUTH_PROVIDERS.md](../authentication/OAUTH_PROVIDERS.md) -- OAuth provider setup

---

**Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
