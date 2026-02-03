# Claude Development Notes

**Project:** SIGNula - Universal Login System
**Last Updated:** 2026-02-02
**Claude Model:** Sonnet 4.5

---

## 📋 Overview

This document contains key development information, commands, prompts, and patterns used during the development of SIGNula with Claude Code (CLI). It serves as a reference for:

- Development patterns and conventions
- Key prompts and commands used
- Technical decisions and rationale
- Troubleshooting notes
- Best practices identified

---

## 🔧 Development Environment

### Tools & Setup
- **IDE:** Visual Studio Code
- **FTP Sync:** ftp-sync extension for Dreamhost deployment
- **Version Control:** GitHub repository
- **Platform:** Mac OS (primary), Windows (occasional)
- **Hosting:** Dreamhost Shared Hosting (no CLI/Composer access)

### Key Constraints
- No CLI access on hosting platform
- No Composer available
- Third-party libraries must be self-hosted or CDN-linked
- PHP 8.3+ required
- MySQLi only (no PDO)

---

## 🎯 Development Phases

### Phase 1: Core Foundation (Complete - Oct 2024)
**Key Prompts Used:**
- "Create database schema for universal authentication system with MFA, OAuth, subscriptions"
- "Implement SecurityUtils class with AES-256-CBC encryption and Argon2id password hashing"
- "Create Auth class with login, registration, session management, device detection"
- "Build comprehensive logging system with ActivityLogger and ErrorLogger"

**Key Files Created:**
- `_database/migrations/001_initial_schema.sql` - Core database schema (24 tables)
- `_config/config.php` - Application bootstrap and configuration loader
- `_config/database.php` - MySQLi connection handler with prepared statements
- `_includes/security/SecurityUtils.php` - Encryption, hashing, CSRF, rate limiting
- `_includes/auth/Auth.php` - Authentication and session management
- `_includes/utils/ActivityLogger.php` - Activity logging system
- `_includes/utils/ErrorLogger.php` - Error logging system

**Technical Decisions:**
- Argon2id for password hashing (OWASP recommended)
- AES-256-CBC for sensitive data encryption
- MySQLi over PDO (better MySQL-specific features)
- Database-driven configuration (tblSettings)
- Session-based authentication (with remember me support)

---

### Phase 1.5: MFA Implementation (Complete - Nov 2024)
**Key Prompts Used:**
- "Implement TOTP-based MFA with QR code generation and backup codes"
- "Create MFA management interface with enable/disable, backup codes, device management"
- "Add email-based OTP as alternative MFA method"

**Key Files Created:**
- `_database/migrations/002_mfa_system.sql` - MFA tables (tblUserMFA, tblMFABackupCodes)
- `_includes/auth/MFAHandler.php` - MFA implementation with TOTP
- `public_html/auth/mfa-setup.php` - MFA enrollment page
- `public_html/auth/mfa-verify.php` - MFA verification page
- `public_html/settings/mfa.php` - MFA management interface

**Libraries Used:**
- RobThree/TwoFactorAuth (self-hosted) - TOTP generation and verification
- PHPQRCode (self-hosted) - QR code generation

---

### Phase 1.6: OAuth Integration (Complete - Nov 2024)
**Key Prompts Used:**
- "Implement OAuth 2.0 integration for Google and Microsoft accounts"
- "Create account linking UI to connect/disconnect third-party accounts"
- "Add avatar sync from OAuth providers (Google, Microsoft)"

**Key Files Created:**
- `_database/migrations/003_oauth_integration.sql` - OAuth tables (tblOAuthTokens, tblOAuthProviders)
- `_includes/auth/OAuthHandler.php` - OAuth flow management
- `public_html/auth/oauth-callback.php` - OAuth callback handler
- `public_html/settings/connected-accounts.php` - Account linking management

**OAuth Providers Implemented:**
- Google (Personal & Workspace)
- Microsoft (Personal & Microsoft 365)

**Technical Decisions:**
- Store encrypted OAuth tokens in database
- Sync user avatar from OAuth provider on first link
- Allow multiple OAuth accounts per user
- Primary account selection for avatar priority

---

### Phase 1.7: Enterprise Email System (Complete - Nov 2024)
**Key Prompts Used:**
- "Enhance email system with template support and queue management"
- "Create beautiful HTML email templates with variable replacement"
- "Implement email queue with retry logic and failure tracking"

**Key Files Created:**
- `_database/migrations/004_email_system.sql` - Email tables (tblEmailTemplates, tblEmailQueue)
- `_includes/email/EmailService.php` - Enhanced email service
- `_private/templates/email/*.html` - HTML email templates

**Email Templates Created:**
- Welcome email
- Email verification
- Password reset
- MFA enrollment
- Security alert
- Account changes

---

### Phase 1.8: WebAuthn/PassKeys (Complete - Feb 2, 2026)
**Key Prompts Used:**
- "Implement WebAuthn/FIDO2 standard for biometric authentication"
- "Create PassKey registration and authentication flows"
- "Build PassKey management interface with rename, revoke, usage tracking"

**Key Files Created:**
- `_database/migrations/005_webauthn_passkeys.sql` - WebAuthn tables
- `_includes/auth/WebAuthnHandler.php` (730+ lines) - Complete WebAuthn implementation
- `public_html/api/webauthn/register-options.php` - Registration challenge endpoint
- `public_html/api/webauthn/register-verify.php` - Registration verification endpoint
- `public_html/api/webauthn/auth-options.php` - Authentication challenge endpoint
- `public_html/api/webauthn/auth-verify.php` - Authentication verification endpoint
- `public_html/auth/passkey-register.php` - Registration UI
- `public_html/auth/passkey-login.php` - Authentication UI
- `public_html/settings/passkeys.php` - Management UI

**Technical Implementation:**
```php
// WebAuthn Registration Flow
1. User clicks "Create PassKey"
2. PHP generates challenge via WebAuthnHandler::generateRegistrationOptions()
3. Challenge stored in tblWebAuthnChallenges with 5-minute expiry
4. JavaScript calls navigator.credentials.create() with publicKey options
5. Browser prompts for biometric/security key
6. JavaScript sends credential to register-verify.php
7. PHP verifies attestation and stores credential in tblWebAuthnCredentials
8. Success response redirects to management page

// WebAuthn Authentication Flow
1. User clicks "Login with PassKey"
2. PHP generates challenge via WebAuthnHandler::generateAuthenticationOptions()
3. Challenge stored in tblWebAuthnChallenges
4. JavaScript calls navigator.credentials.get() with publicKey options
5. Browser prompts for biometric/security key
6. JavaScript sends assertion to auth-verify.php
7. PHP verifies signature using stored public key
8. Signature counter checked for cloning detection
9. Session created on successful verification
```

**Key Patterns:**
- Base64 encoding for binary credential data
- Challenge-response protocol (5-minute validity)
- Public key cryptography (P-256 curve)
- Signature counter for cloned authenticator detection
- User verification preference: "preferred"
- Attestation conveyance: "none" (privacy-preserving)

**Browser Compatibility Checks:**
```javascript
// Check WebAuthn support
if (!window.PublicKeyCredential) {
    alert('WebAuthn not supported');
    return;
}

// Check conditional mediation (autofill)
if (window.PublicKeyCredential.isConditionalMediationAvailable) {
    const available = await PublicKeyCredential.isConditionalMediationAvailable();
    // Use conditional UI if available
}
```

---

### Phase 1.9: Passwordless Email Login (Complete - Feb 2, 2026)
**Key Prompts Used:**
- "Implement secure magic link authentication via email"
- "Add rate limiting for passwordless login requests"
- "Create beautiful HTML email template for magic links"

**Key Files Created:**
- `_includes/auth/PasswordlessLoginHandler.php` (650+ lines)
- `public_html/auth/passwordless-request.php` - Request magic link
- `public_html/auth/passwordless-login.php` - Verify token and login

**Technical Implementation:**
```php
// Passwordless Login Flow
1. User enters email address
2. System checks rate limits (5/hour per email, 10/hour per IP)
3. Generate 64-character cryptographically secure token
4. Hash token with SHA-256 before database storage
5. Send beautiful HTML email with magic link
6. Token expires in 15 minutes (configurable)
7. User clicks link in email
8. System verifies token hash matches, not expired, not used
9. Mark token as used (single-use)
10. Create user session and redirect to dashboard
```

**Security Features:**
```php
// Token Generation
$token = bin2hex(random_bytes(32)); // 64-char hex string
$tokenHash = hash('sha256', $token); // SHA-256 hash for storage

// Rate Limiting
- Per Email: 5 requests per hour
- Per IP: 10 requests per hour

// Token Validation
- Must not be expired (< 15 min old)
- Must not be used (isUsed = 0)
- Must match email address
- Must match purpose (login/verification)

// Single Use
UPDATE tblPasswordlessTokens
SET isUsed = 1, usedAt = NOW(), usedIP = ?
WHERE tokenHash = ?
```

**Email Template:**
- Responsive HTML design
- Beautiful gradient background
- Clear call-to-action button
- Expiration notice
- Security warning about sharing
- Fallback plain text version

---

### Phase 2: Account Management UI (In Progress - Feb 2026)
**Key Prompts Used:**
- "Create account settings dashboard with statistics and quick actions"
- "Build profile management page with email change functionality"
- "Implement security settings page with security score calculator"
- "Create settings sidebar navigation component"

**Key Files Created:**
- `_includes/layout/settings-sidebar.php` - Reusable sidebar navigation
- `public_html/settings/index.php` - Settings dashboard
- `public_html/settings/profile.php` - Profile management
- `public_html/settings/security.php` - Security settings

**UI Components:**

**Settings Sidebar:**
```php
// Navigation structure
Account Settings:
- Profile (displayName, username, timezone, email)
- Security (password, auth methods, sessions)
- PassKeys (manage WebAuthn credentials)
- Two-Factor Auth (manage MFA devices)
- Connected Accounts (OAuth providers)
- Activity Log (security audit trail)
- Privacy (data sharing, third-party access)
- Notifications (email, security alerts)

Danger Zone:
- Export Data (GDPR compliance)
- Delete Account (with confirmation)
```

**Dashboard Features:**
```php
// Quick Stats Cards
1. PassKeys Count
2. MFA Status (Enabled/Disabled)
3. Connected Accounts Count
4. Recent Activity (last 7 days)

// Quick Actions
- Change Password
- Enable/Manage MFA
- Add/Manage PassKeys
- Manage Connected Accounts

// Security Recommendations
if (!$mfaEnabled) {
    "Enable Two-Factor Authentication"
}
if ($passkeysCount === 0) {
    "Add a PassKey for passwordless login"
}
```

**Security Score Calculator:**
```php
$securityScore = 0;

// Has password set (25 points)
if (!empty($user['passwordHash'])) {
    $securityScore += 25;
}

// MFA enabled (25 points)
if ($mfaEnabled) {
    $securityScore += 25;
}

// Has PassKeys (25 points)
if ($passkeysCount > 0) {
    $securityScore += 25;
}

// Email verified (25 points)
if ($user['emailVerified']) {
    $securityScore += 25;
}

// Total: 0-100% score
```

---

## 🧪 Testing & Documentation

### Phase 1 Testing Documentation
**Files Created:**
- `AUTH_PHASE1_DOCUMENTATION.md` - Complete feature documentation
- `TESTING_GUIDE_PHASE1.md` - Comprehensive testing guide (60+ test cases)
- `QUICK_TEST_REFERENCE.md` - 15-minute quick test guide
- `_tests/verify-phase1-setup.php` - Automated setup verification

**Testing Approach:**
```php
// Automated Setup Verification
php _tests/verify-phase1-setup.php

Tests performed:
- Database connection
- Table existence (27 tables)
- Column existence (webauthnEnabled, passwordlessEnabled)
- Stored procedure existence
- Configuration settings
- Email provider
- Handler class files
- API endpoint files
- User page files
- PHP version (8.3+)
- Required extensions (mysqli, json, openssl, mbstring)
- HTTPS check (production only)
```

**Test Categories:**
1. **Happy Path** - Normal successful flows
2. **Error Path** - Expected error scenarios
3. **Security Path** - Security feature validation
4. **Edge Cases** - Boundary conditions
5. **Cross-Browser** - Browser compatibility
6. **Multi-Device** - Device compatibility

---

## 🎨 Code Patterns & Conventions

### PHP Coding Standards

**File Headers:**
```php
<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Component Name
 * ============================================================================
 *
 * Purpose: Brief description of component purpose
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.5.0
 * ============================================================================
 */
```

**Error Handling:**
```php
try {
    // Operation
    $result = Database::query($query, $params, $types);

    if ($result) {
        // Log success
        ActivityLogger::log(
            userID: $userID,
            activityType: 'operation_type',
            activityResult: 'success',
            activityDetails: 'Descriptive message'
        );
    }
} catch (Exception $e) {
    // Log error
    error_log("Context: " . $e->getMessage());

    ErrorLogger::log(
        errorType: 'Exception',
        errorMessage: $e->getMessage(),
        errorFile: $e->getFile(),
        errorLine: $e->getLine()
    );

    // User-friendly error
    $message = 'An error occurred. Please try again.';
    $messageType = 'danger';
}
```

**Database Queries:**
```php
// Always use prepared statements
$query = "SELECT * FROM tblUsers WHERE userID = ?";
$result = Database::fetchOne($query, [$userID], 'i');

// Multiple parameters with type string
$query = "UPDATE tblUsers SET displayName = ?, timezone = ? WHERE userID = ?";
Database::query($query, [$displayName, $timezone, $userID], 'ssi');

// Type string legend:
// i = integer
// d = double
// s = string
// b = blob
```

**HTML Output:**
```php
// Always escape output
<input type="text" value="<?php echo htmlspecialchars($user['displayName'] ?? ''); ?>">

// Bootstrap 5 alert patterns
<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
```

---

## 🔒 Security Patterns

### Encryption
```php
// Encrypt sensitive data before storage
$encrypted = SecurityUtils::encrypt($sensitiveData);
Database::query("UPDATE tblSettings SET settingValue = ? WHERE settingKey = ?",
    [$encrypted, $key], 'ss');

// Decrypt when retrieving
$decrypted = SecurityUtils::decrypt($encrypted);
```

### Password Hashing
```php
// Hash password (Argon2id)
$passwordHash = password_hash($password, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,  // 64 MB
    'time_cost' => 4,         // 4 iterations
    'threads' => 3            // 3 threads
]);

// Verify password
if (password_verify($password, $passwordHash)) {
    // Correct password
}

// Rehash if needed (algorithm updated)
if (password_needs_rehash($passwordHash, PASSWORD_ARGON2ID)) {
    $newHash = password_hash($password, PASSWORD_ARGON2ID);
    // Update database
}
```

### CSRF Protection
```php
// Generate token
$csrfToken = SecurityUtils::generateCSRFToken();

// Include in form
<input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

// Verify on submission
if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    die('CSRF token validation failed');
}
```

### Rate Limiting
```php
// Check rate limit
$identifier = $email; // or IP address
$maxAttempts = 5;
$windowMinutes = 60;

if (!SecurityUtils::checkRateLimit($identifier, $maxAttempts, $windowMinutes)) {
    $message = 'Too many attempts. Please try again later.';
    return;
}

// Record attempt
SecurityUtils::recordRateLimit($identifier);
```

---

## 🚀 Deployment Commands

### Database Migrations
```bash
# Run migrations in order
mysql -u username -p database < _database/migrations/001_initial_schema.sql
mysql -u username -p database < _database/migrations/002_mfa_system.sql
mysql -u username -p database < _database/migrations/003_oauth_integration.sql
mysql -u username -p database < _database/migrations/004_email_system.sql
mysql -u username -p database < _database/migrations/005_webauthn_passkeys.sql

# Verify setup
php _tests/verify-phase1-setup.php
```

### Configuration
```bash
# Copy auth template
cp _private/auth.php.example _private/auth.php

# Generate encryption key
openssl rand -base64 32

# Generate salt
openssl rand -hex 16

# Set permissions
chmod 600 _private/auth.php
chmod 755 _private/logs
chmod 755 _private/backups
```

### Web Server Configuration
```apache
# Apache .htaccess (public_html/)
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?route=$1 [QSA,L]

# Security headers
Header set X-Frame-Options "SAMEORIGIN"
Header set X-Content-Type-Options "nosniff"
Header set X-XSS-Protection "1; mode=block"
Header set Referrer-Policy "strict-origin-when-cross-origin"
```

---

## 📊 Database Schema Overview

### Core Tables (24)
- tblUsers - User accounts
- tblUserPreferences - User settings
- tblUserSessions - Active sessions
- tblVerificationTokens - Email verification
- tblPasswordResets - Password reset tokens
- tblUserDevices - Trusted devices
- tblActivityLog - Activity logging
- tblErrorLog - Error logging
- tblSecurityEvents - Security incidents
- tblSettings - System configuration
- tblAPIKeys - API authentication
- tblRateLimits - Rate limiting tracking
- tblURLRouter - URL routing rules

### MFA Tables (3)
- tblUserMFA - MFA enrollment status
- tblMFABackupCodes - Backup recovery codes
- tblMFADevices - Registered authenticator apps

### OAuth Tables (3)
- tblOAuthTokens - OAuth access tokens
- tblOAuthProviders - Supported OAuth providers
- tblOAuthState - OAuth CSRF state tracking

### Email Tables (3)
- tblEmailTemplates - Email templates
- tblEmailQueue - Outbound email queue
- tblEmailLog - Email delivery tracking

### WebAuthn Tables (3)
- tblWebAuthnCredentials - PassKey credentials
- tblWebAuthnChallenges - Authentication challenges
- tblPasswordlessTokens - Magic link tokens

### Subscription Tables (4)
- tblSubscriptionTiers - Available tiers
- tblSubscriptions - User subscriptions
- tblPayments - Payment history
- tblPaymentMethods - Stored payment methods

---

## 🛠️ Troubleshooting

### Common Issues

**WebAuthn "Origin Mismatch" Error:**
```sql
-- Update RP ID to match your domain
UPDATE tblSettings
SET settingValue = 'yourdomain.com'
WHERE settingKey = 'auth.webauthn.rp_id';

-- For localhost development
UPDATE tblSettings
SET settingValue = 'localhost'
WHERE settingKey = 'auth.webauthn.rp_id';
```

**Challenge Expired Error:**
```sql
-- Increase challenge validity (default 5 minutes)
UPDATE tblSettings
SET settingValue = '10'
WHERE settingKey = 'auth.webauthn.challenge_validity';
```

**Passwordless Email Not Received:**
```sql
-- Check email provider configuration
SELECT * FROM tblSettings WHERE settingKey LIKE 'email.%';

-- Check email queue
SELECT * FROM tblEmailQueue WHERE emailStatus = 'failed';
```

**Rate Limit Too Strict:**
```sql
-- Adjust passwordless rate limits
UPDATE tblSettings
SET settingValue = '10'
WHERE settingKey = 'auth.passwordless.rate_limit_email';

UPDATE tblSettings
SET settingValue = '20'
WHERE settingKey = 'auth.passwordless.rate_limit_ip';
```

### Debug Mode
```php
// Enable in development
$_GET['debug'] = 'true';

// Or set in config
define('DEBUG_MODE', true);
define('DISPLAY_ERRORS', true);
```

---

## 📚 Key Resources

### WebAuthn/FIDO2
- [WebAuthn Specification](https://www.w3.org/TR/webauthn-2/)
- [FIDO Alliance](https://fidoalliance.org/)
- [MDN Web Authentication API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Authentication_API)

### Security Best Practices
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html)
- [PHP Security Best Practices](https://www.php.net/manual/en/security.php)

### PHP Documentation
- [PHP 8.3 Documentation](https://www.php.net/manual/en/)
- [MySQLi Documentation](https://www.php.net/manual/en/book.mysqli.php)
- [Password Hashing](https://www.php.net/manual/en/function.password-hash.php)

---

## 🎯 Next Steps

### Immediate Tasks (Phase 2 Completion)
1. Complete security settings page password change functionality
2. Implement session management (view/revoke sessions)
3. Build OAuth account linking UI
4. Create MFA management interface
5. Build activity log viewer
6. Create privacy settings page
7. Build notification preferences
8. Implement data export functionality
9. Create account deletion flow

### Future Enhancements
1. GraphQL API (in addition to REST)
2. Redis for session storage
3. WebSocket support for real-time notifications
4. Mobile app (React Native or Flutter)
5. Admin dashboard
6. Payment system integration
7. Advanced analytics

---

**Maintained by:** Claude Code (Sonnet 4.5)
**Project Lead:** MWBMPartners
**Repository:** https://github.com/MWBMPartners/SIGNula.id
