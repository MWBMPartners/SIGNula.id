# 🚀 Phase 1 Quick Test Reference

**Quick guide for testing Phase 1 authentication features**

---

## 🏁 Pre-Flight Checklist

### 1. Run Setup Verification
```bash
php _tests/verify-phase1-setup.php
```
**Expected:** All tests pass ✅

### 2. Apply Database Migration
```bash
mysql -u [username] -p [database] < _database/migrations/005_webauthn_passkeys.sql
```

### 3. Quick Settings Check
```sql
SELECT settingKey, settingValue FROM tblSettings WHERE settingKey LIKE 'auth.%';
```

---

## 🧪 15-Minute Quick Test

### Test 1: Passwordless Email Login (5 min)

1. **Request Link**
   - Go to: `/auth/passwordless-request`
   - Enter email
   - Click "Send Login Link"
   - ✅ Check: Email received

2. **Use Link**
   - Click link in email
   - ✅ Check: Logged in successfully

3. **Test Expiry**
   - Request another link
   - Wait 16 minutes (or force expire in DB)
   - Click old link
   - ✅ Check: "Expired" error shown

---

### Test 2: PassKey Registration (5 min)

1. **Register PassKey**
   - Login first (with password or email)
   - Go to: `/auth/passkey-register`
   - Click "Create PassKey"
   - Follow device prompt
   - ✅ Check: Success message + redirect

2. **Verify in Database**
   ```sql
   SELECT * FROM tblWebAuthnCredentials WHERE userID = [YOUR_ID];
   ```
   ✅ Check: New credential exists

---

### Test 3: PassKey Login (5 min)

1. **Login with PassKey**
   - Logout completely
   - Go to: `/auth/passkey-login`
   - Click "Login with PassKey"
   - Follow device prompt
   - ✅ Check: Logged in successfully

2. **Verify Usage Tracking**
   ```sql
   SELECT usageCount, lastUsedAt FROM tblWebAuthnCredentials WHERE userID = [YOUR_ID];
   ```
   ✅ Check: usageCount incremented

---

## 🎯 Critical Path Tests

Test these scenarios to verify core functionality:

### ✅ Happy Path
1. ✓ Passwordless login works
2. ✓ PassKey registration works
3. ✓ PassKey login works
4. ✓ PassKey management works

### ❌ Error Path
1. ✓ Expired token rejected
2. ✓ Used token rejected
3. ✓ Revoked PassKey rejected
4. ✓ Wrong device rejected

### 🔒 Security Path
1. ✓ Rate limiting works
2. ✓ Challenge expiry works
3. ✓ HTTPS enforced (production)
4. ✓ Activity logged

---

## 📱 Device Testing Quick Matrix

Test on at least 2 devices:

| Feature | Device 1 | Device 2 |
|---------|----------|----------|
| PassKey Register | ☐ | ☐ |
| PassKey Login | ☐ | ☐ |
| Passwordless Email | ☐ | ☐ |

**Recommended combinations:**
- Mac (Safari) + iPhone (Safari)
- Windows (Chrome) + Android (Chrome)
- Mac (Chrome) + Mac (Safari)

---

## 🔍 Quick Troubleshooting

### Problem: "Browser not supported"
**Fix:** Update browser or use Chrome/Safari/Firefox latest

### Problem: "Email not received"
**Fix:** Check spam folder, verify email settings:
```sql
SELECT * FROM tblSettings WHERE settingKey = 'email.provider';
```

### Problem: "Origin mismatch"
**Fix:** Update RP ID to match domain:
```sql
UPDATE tblSettings SET settingValue = 'yourdomain.com'
WHERE settingKey = 'auth.webauthn.rp_id';
```

### Problem: "Challenge expired"
**Fix:** Complete authentication faster, or increase validity:
```sql
UPDATE tblSettings SET settingValue = '10'
WHERE settingKey = 'auth.webauthn.challenge_validity';
```

---

## 📊 Quick Health Check Queries

### Active PassKeys by User
```sql
SELECT COUNT(*) as passkey_count
FROM tblWebAuthnCredentials
WHERE isActive = 1;
```

### Recent Passwordless Logins
```sql
SELECT COUNT(*) as recent_logins
FROM tblPasswordlessTokens
WHERE isUsed = 1
AND usedAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

### Failed Authentication Attempts
```sql
SELECT COUNT(*) as failed_attempts
FROM tblActivityLog
WHERE activityType = 'login'
AND activityResult = 'failed'
AND loggedAt >= DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

---

## 🎓 URLs to Remember

### User Pages
- `/auth/passwordless-request` - Request email link
- `/auth/passwordless-login?token=...` - Verify email link
- `/auth/passkey-register` - Register new PassKey
- `/auth/passkey-login` - Login with PassKey
- `/settings/passkeys` - Manage PassKeys

### API Endpoints
- `/api/webauthn/register-options.php` - Get registration options
- `/api/webauthn/register-verify.php` - Verify registration
- `/api/webauthn/auth-options.php` - Get auth options
- `/api/webauthn/auth-verify.php` - Verify authentication

---

## ✅ Sign-Off Checklist

Before marking Phase 1 complete:

- [ ] All setup verification tests pass
- [ ] Passwordless email login works
- [ ] PassKey registration works
- [ ] PassKey authentication works
- [ ] PassKey management works
- [ ] Tested on at least 2 devices
- [ ] Tested on at least 2 browsers
- [ ] Security features verified
- [ ] Error handling works
- [ ] Documentation reviewed

**Signed:** ________________ **Date:** ________________

---

## 📞 Need Help?

1. Check browser console for errors
2. Check server error logs
3. Review [TESTING_GUIDE_PHASE1.md](TESTING_GUIDE_PHASE1.md) for detailed tests
4. Review [AUTH_PHASE1_DOCUMENTATION.md](AUTH_PHASE1_DOCUMENTATION.md) for documentation

---

**Quick Reference Version:** 1.0
**Last Updated:** 2026-02-02
