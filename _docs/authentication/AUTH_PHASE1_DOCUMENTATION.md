# 🔐 SIGNula - Phase 1: Authentication Enhancement

**Version:** 1.4.0
**Completed:** 2026-02-02
**Status:** ✅ Complete

---

## 📋 Overview

Phase 1 adds modern, passwordless authentication methods to SIGNula, making it easier and more secure for users to access their accounts.

### ✅ Completed Features

1. **🔐 WebAuthn/PassKey Support** - Biometric authentication using FIDO2
2. **📧 Passwordless Email Login** - Secure magic links via email
3. **📱 Biometric Authentication** - TouchID, FaceID, Windows Hello, Android biometrics

---

## 🔐 1. WebAuthn/PassKey Authentication

### What is it?

PassKeys use the WebAuthn standard (FIDO2) to allow users to log in using their device's biometric authentication (fingerprint, face recognition, etc.) or a hardware security key.

### Key Benefits

- ✅ More secure than passwords (phishing-resistant)
- 🚀 Faster login experience
- 🔒 Cryptographically secure
- 📱 Works across devices
- 🌐 Industry-standard technology

### Files Created

**Backend:**
- [_includes/auth/WebAuthnHandler.php](_includes/auth/WebAuthnHandler.php) - Core WebAuthn logic
- [_database/migrations/005_webauthn_passkeys.sql](_database/migrations/005_webauthn_passkeys.sql) - Database schema

**API Endpoints:**
- [public_html/api/webauthn/register-options.php](public_html/api/webauthn/register-options.php) - Get registration options
- [public_html/api/webauthn/register-verify.php](public_html/api/webauthn/register-verify.php) - Verify registration
- [public_html/api/webauthn/auth-options.php](public_html/api/webauthn/auth-options.php) - Get authentication options
- [public_html/api/webauthn/auth-verify.php](public_html/api/webauthn/auth-verify.php) - Verify authentication

**User Interface:**
- [public_html/auth/passkey-register.php](public_html/auth/passkey-register.php) - Register new PassKey
- [public_html/auth/passkey-login.php](public_html/auth/passkey-login.php) - Login with PassKey
- [public_html/settings/passkeys.php](public_html/settings/passkeys.php) - Manage PassKeys

### Database Tables

```sql
tblWebAuthnCredentials       -- Stores user's registered PassKeys
tblWebAuthnChallenges        -- Temporary challenges for ceremonies
```

### How to Use

**For Users:**
1. Navigate to **Settings → Security → PassKeys**
2. Click "Add PassKey"
3. Your device will prompt for biometric authentication
4. Name your PassKey (optional)
5. Use it to log in from **Login → PassKey Login**

**For Developers:**
```php
// Generate registration options
$handler = new WebAuthnHandler();
$options = $handler->generateRegistrationOptions($userID);

// Verify registration
$result = $handler->verifyRegistration($userID, $credentialData, $deviceName);

// Generate authentication options
$options = $handler->generateAuthenticationOptions($email);

// Verify authentication
$result = $handler->verifyAuthentication($credentialData);
```

### Supported Devices

- **iOS/iPadOS**: TouchID, FaceID
- **macOS**: TouchID
- **Android**: Fingerprint, Face Unlock, Pattern/PIN
- **Windows**: Windows Hello (fingerprint, face, PIN)
- **Hardware Keys**: YubiKey, Titan Security Key, etc.

### Security Features

- ✅ Challenge-response authentication
- ✅ Credential storage on device (not server)
- ✅ Sign count verification (clone detection)
- ✅ Origin verification
- ✅ Attestation validation
- ✅ Per-credential usage tracking

---

## 📧 2. Passwordless Email Login

### What is it?

Users can request a secure, time-limited login link sent to their email. Clicking the link logs them in without needing a password.

### Key Benefits

- ✅ No password to remember
- 🔒 Time-limited tokens (15 minutes default)
- 📧 Secure delivery via email
- 🚫 One-time use tokens
- 🔐 Rate limiting protection

### Files Created

**Backend:**
- [_includes/auth/PasswordlessLoginHandler.php](_includes/auth/PasswordlessLoginHandler.php) - Core logic
- Database tables added in [005_webauthn_passkeys.sql](_database/migrations/005_webauthn_passkeys.sql)

**User Interface:**
- [public_html/auth/passwordless-request.php](public_html/auth/passwordless-request.php) - Request login link
- [public_html/auth/passwordless-login.php](public_html/auth/passwordless-login.php) - Verify token and login

### Database Tables

```sql
tblPasswordlessTokens        -- Secure login tokens with expiration
```

### How to Use

**For Users:**
1. Navigate to **Login → Passwordless Login**
2. Enter your email address
3. Check your email for the login link
4. Click the link to log in instantly

**For Developers:**
```php
// Send login link
$handler = new PasswordlessLoginHandler();
$result = $handler->sendLoginLink($email, 'login');

// Verify token
$result = $handler->verifyLoginToken($token);
if ($result['success']) {
    $_SESSION['userID'] = $result['userID'];
    // User is now logged in
}
```

### Security Features

- ✅ Cryptographically secure random tokens (64 characters)
- ✅ Token hashing in database (SHA-256)
- ✅ Time-limited expiration (configurable, default 15 min)
- ✅ One-time use (tokens invalid after use)
- ✅ Attempt count tracking (max 3 attempts)
- ✅ Rate limiting (5 requests/hour per email, 10/hour per IP)
- ✅ IP and User Agent tracking

### Configuration

```php
// Token validity (minutes)
setSetting('auth.passwordless.token_validity', '15');

// Enable/disable feature
setSetting('auth.passwordless.enabled', '1');
```

### Email Template

The system sends beautiful HTML emails with:
- Clear call-to-action button
- Plain text alternative
- Expiration notice
- Security messaging
- Fallback link (if button doesn't work)

---

## 📱 3. Biometric Authentication Support

### What is it?

Biometric authentication is **built into the WebAuthn/PassKey implementation**. When users register a PassKey, they can choose to use their device's biometric capabilities.

### Supported Biometric Methods

| Platform | Biometric Methods |
|----------|------------------|
| **iOS/iPadOS** | TouchID (fingerprint), FaceID (facial recognition) |
| **macOS** | TouchID (fingerprint) |
| **Android** | Fingerprint, Face Unlock, Iris Scanner |
| **Windows** | Windows Hello (fingerprint, facial recognition, PIN) |
| **Linux** | FIDO2 hardware keys, fingerprint readers |

### How It Works

1. User initiates PassKey registration
2. Browser checks for available authenticators
3. User is prompted to use biometric authentication
4. Biometric verification happens **on the device** (never sent to server)
5. Cryptographic credential is created and stored on device
6. User can now log in with biometrics

### Browser Compatibility

| Browser | Support |
|---------|---------|
| **Chrome/Edge** | ✅ Full support (v67+) |
| **Safari** | ✅ Full support (v13+) |
| **Firefox** | ✅ Full support (v60+) |
| **Opera** | ✅ Full support (v54+) |

### Security Considerations

- Biometric data **never leaves the device**
- Only cryptographic proof is sent to server
- Credentials are bound to the origin (domain)
- Immune to phishing attacks
- Cannot be replicated or stolen

---

## 🚀 Installation & Setup

### 1. Database Migration

Run the migration script:

```bash
mysql -u username -p database < _database/migrations/005_webauthn_passkeys.sql
```

Or via phpMyAdmin:
```sql
SOURCE _database/migrations/005_webauthn_passkeys.sql;
```

### 2. Configure Settings

```php
// WebAuthn/PassKey settings
setSetting('auth.webauthn.enabled', '1');
setSetting('auth.webauthn.rp_name', 'SIGNula');
setSetting('auth.webauthn.rp_id', 'signula.id');  // Your domain
setSetting('auth.webauthn.challenge_validity', '5'); // minutes

// Passwordless login settings
setSetting('auth.passwordless.enabled', '1');
setSetting('auth.passwordless.token_validity', '15'); // minutes
```

### 3. Enable HTTPS

**IMPORTANT:** WebAuthn requires HTTPS (except on localhost for testing).

Ensure your site is served over HTTPS:
- Production: Use valid SSL/TLS certificate
- Development: Use `localhost` or setup self-signed cert

### 4. Configure Email System

Passwordless login requires the email system to be configured. See [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md) for setup.

### 5. Update Login Page

Add links to new authentication methods on your login page:

```php
<a href="/auth/passkey-login" class="btn btn-primary">
    🔐 Login with PassKey
</a>

<a href="/auth/passwordless-request" class="btn btn-outline-primary">
    📧 Email Me a Login Link
</a>
```

---

## 📊 Usage Analytics

### WebAuthn Statistics

```php
$stats = WebAuthnHandler::getStatistics();

echo "Users with PassKeys: " . $stats['total_users'];
echo "Total Credentials: " . $stats['total_credentials'];
echo "Recent Authentications: " . $stats['recent_authentications'];
```

### Passwordless Login Statistics

```php
$stats = PasswordlessLoginHandler::getStatistics();

echo "Tokens Issued: " . $stats['total_tokens_issued'];
echo "Success Rate: " . $stats['success_rate'] . "%";
```

---

## 🔧 Maintenance

### Cleanup Expired Tokens

Run periodically via cron:

```bash
# Every hour
0 * * * * mysql -u username -p database -e "CALL cleanupExpiredAuthTokens();"
```

Or manually:
```php
PasswordlessLoginHandler::cleanupExpiredTokens();
```

### Monitor PassKey Usage

```sql
-- Most used PassKeys
SELECT deviceName, usageCount, lastUsedAt
FROM tblWebAuthnCredentials
WHERE isActive = 1
ORDER BY usageCount DESC
LIMIT 10;

-- PassKey adoption rate
SELECT
    COUNT(DISTINCT userID) as users_with_passkeys,
    (SELECT COUNT(*) FROM tblUsers) as total_users,
    (COUNT(DISTINCT userID) / (SELECT COUNT(*) FROM tblUsers) * 100) as adoption_rate
FROM tblWebAuthnCredentials
WHERE isActive = 1;
```

---

## 🐛 Troubleshooting

### WebAuthn Issues

**Problem:** "Browser not supported" message
**Solution:** Ensure browser is updated and supports WebAuthn API

**Problem:** Registration fails with "NotAllowedError"
**Solution:** User cancelled authentication or timed out. Ask them to try again.

**Problem:** "Origin mismatch" error
**Solution:** Ensure `auth.webauthn.rp_id` matches your domain (without protocol)

**Problem:** PassKey works on one device but not another
**Solution:** PassKeys are device-specific. Register separate PassKey for each device.

### Passwordless Login Issues

**Problem:** Email not received
**Solution:** Check spam folder, verify email system is configured

**Problem:** "Token expired" error
**Solution:** Tokens expire after 15 minutes. Request a new link.

**Problem:** "Too many requests" error
**Solution:** Rate limiting triggered. Wait an hour or contact support.

### General Debugging

Enable verbose error logging:
```php
// In _config/config.php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
```

Check browser console for JavaScript errors.

---

## 🔐 Security Best Practices

1. **Always use HTTPS** in production
2. **Keep challenge validity short** (5 minutes max)
3. **Monitor for suspicious activity** (multiple failed attempts)
4. **Encourage users to register multiple PassKeys** (backup devices)
5. **Educate users** about PassKey benefits and security
6. **Regularly cleanup expired tokens** (cron job)
7. **Rate limit authentication attempts** (built-in)
8. **Log all authentication events** (built-in)

---

## 📞 User Support

### Common User Questions

**Q: What if I lose my device with my PassKey?**
A: Register PassKeys on multiple devices as backup, or use email login.

**Q: Can I use the same PassKey on multiple devices?**
A: No, each PassKey is unique to a device. Register separate PassKeys for each device.

**Q: Is my biometric data stored on your servers?**
A: No, biometric data never leaves your device. Only cryptographic proofs are sent.

**Q: What happens if someone steals my device?**
A: They still need your biometric/PIN to use the PassKey. You can also remove it from settings.

**Q: Can I use PassKeys on public computers?**
A: Not recommended. Use passwordless email login instead for public/shared computers.

---

## 🎯 Next Steps (Phase 2)

Now that Phase 1 is complete, the next phase will focus on:

1. **Account Management UI** - Comprehensive settings interface
2. **OAuth Account Linking UI** - Visual management of connected accounts
3. **Activity Log Viewer** - User-facing security audit log
4. **Profile Management** - Update user information
5. **Privacy Settings** - Control data sharing and visibility

---

## 📚 References

- [W3C WebAuthn Specification](https://www.w3.org/TR/webauthn-2/)
- [FIDO Alliance](https://fidoalliance.org/)
- [MDN WebAuthn Guide](https://developer.mozilla.org/en-US/docs/Web/API/Web_Authentication_API)
- [WebAuthn Guide](https://webauthn.guide/)

---

**Version:** 1.4.0
**Last Updated:** 2026-02-02
**License:** Proprietary - SIGNula Project
**Maintainer:** MWBM Partners Ltd
