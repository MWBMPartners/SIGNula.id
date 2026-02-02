# 🔐 SIGNula OAuth Provider Implementation

Complete OAuth 2.0 provider integration for universal single sign-on.

## ✅ Implemented Providers

### 1. Google OAuth (Personal & Workspace)
**File:** [`_includes/auth/providers/GoogleOAuth.php`](_includes/auth/providers/GoogleOAuth.php)

**Supported Accounts:**
- Personal Google accounts (@gmail.com, etc.)
- Google Workspace accounts (formerly G Suite)

**Features:**
- ✅ Email verification status
- ✅ Profile information (name, avatar)
- ✅ Workspace domain detection
- ✅ Domain restriction capability
- ✅ Offline access (refresh tokens)
- ✅ Token revocation

**Scopes Requested:**
- `openid` - OpenID Connect
- `userinfo.email` - Email address
- `userinfo.profile` - Basic profile info

**Setup Requirements:**
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable "Google+ API"
4. Create OAuth 2.0 credentials (Web application)
5. Add authorized redirect URI: `https://signulo.id/oauth/callback`
6. Copy Client ID and Client Secret

**Database Configuration:**
```sql
INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
('oauth.google.client_id', 'YOUR_CLIENT_ID.apps.googleusercontent.com', 0),
('oauth.google.client_secret', 'ENCRYPTED_CLIENT_SECRET', 1),
('oauth.google.redirect_uri', 'https://signulo.id/oauth/callback', 0);
```

---

### 2. Microsoft OAuth (Personal & Microsoft 365)
**File:** [`_includes/auth/providers/MicrosoftOAuth.php`](_includes/auth/providers/MicrosoftOAuth.php)

**Supported Accounts:**
- Personal Microsoft accounts (Outlook.com, Hotmail, Live)
- Microsoft 365 Work/School accounts
- Azure AD organizational accounts

**Features:**
- ✅ Microsoft Graph API integration
- ✅ Profile photo support
- ✅ Organizational vs. personal account detection
- ✅ Job title and office location (for work accounts)
- ✅ Multi-tenant support
- ✅ Refresh tokens

**Scopes Requested:**
- `openid` - OpenID Connect
- `profile` - Basic profile
- `email` - Email address
- `User.Read` - Read user profile (Microsoft Graph)
- `offline_access` - Refresh token

**Setup Requirements:**
1. Go to [Azure Portal](https://portal.azure.com/)
2. Navigate to "Azure Active Directory" > "App registrations"
3. Create a new registration
4. Set redirect URI: `https://signulo.id/oauth/callback`
5. Generate a client secret under "Certificates & secrets"
6. Note Application (client) ID and client secret value

**Database Configuration:**
```sql
INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
('oauth.microsoft.client_id', 'YOUR_APPLICATION_ID', 0),
('oauth.microsoft.client_secret', 'ENCRYPTED_CLIENT_SECRET', 1),
('oauth.microsoft.redirect_uri', 'https://signulo.id/oauth/callback', 0);
```

---

### 3. Facebook OAuth (Meta)
**File:** [`_includes/auth/providers/FacebookOAuth.php`](_includes/auth/providers/FacebookOAuth.php)

**Supported Accounts:**
- Facebook personal accounts
- Instagram (same OAuth flow)

**Features:**
- ✅ Email address (with permission)
- ✅ Profile information and avatar
- ✅ Long-lived access tokens (60 days)
- ✅ Token debugging utility
- ✅ Instagram account integration
- ✅ Permission re-request capability

**Scopes Requested:**
- `email` - Email address (requires app review for public apps)
- `public_profile` - Basic public profile

**Setup Requirements:**
1. Go to [Facebook Developers](https://developers.facebook.com/)
2. Create a new app (Consumer type)
3. Add "Facebook Login" product
4. Configure OAuth redirect URI: `https://signulo.id/oauth/callback`
5. Copy App ID and App Secret
6. For production: Submit for app review to request email permission

**Database Configuration:**
```sql
INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
('oauth.facebook.client_id', 'YOUR_APP_ID', 0),
('oauth.facebook.client_secret', 'ENCRYPTED_APP_SECRET', 1),
('oauth.facebook.redirect_uri', 'https://signulo.id/oauth/callback', 0);
```

**Notes:**
- Email permission requires app review for public apps
- Access tokens are short-lived (1-2 hours) but can be exchanged for long-lived tokens (60 days)
- User might not grant email permission - handle gracefully

---

### 4. Apple Sign In
**File:** [`_includes/auth/providers/AppleOAuth.php`](_includes/auth/providers/AppleOAuth.php)

**Supported Accounts:**
- Apple ID accounts

**Features:**
- ✅ JWT-based client authentication
- ✅ Private email relay support
- ✅ Enhanced privacy features
- ✅ Email verification
- ✅ Token revocation
- ⚠️ Name only provided on first sign-in
- ⚠️ No profile pictures

**Scopes Requested:**
- `name` - User's name (only on first sign-in)
- `email` - Email address (may be private relay)

**Setup Requirements:**
1. Go to [Apple Developer Portal](https://developer.apple.com/)
2. Create an App ID with "Sign in with Apple" capability
3. Create a Services ID (this is your OAuth Client ID)
4. Configure Services ID:
   - Add domain: `signulo.id`
   - Add return URL: `https://signulo.id/oauth/callback`
5. Create a Key with "Sign in with Apple" capability
6. Download the private key (.p8 file) - **ONE TIME ONLY!**
7. Note your Team ID, Key ID, and Services ID

**Database Configuration:**
```sql
INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
('oauth.apple.client_id', 'com.example.signula', 0),
('oauth.apple.team_id', 'YOUR_TEAM_ID', 0),
('oauth.apple.key_id', 'YOUR_KEY_ID', 0),
('oauth.apple.private_key', 'ENCRYPTED_PRIVATE_KEY_PEM', 1),
('oauth.apple.redirect_uri', 'https://signulo.id/oauth/callback', 0);
```

**Special Considerations:**
- Requires paid Apple Developer account ($99/year)
- Private key can only be downloaded once - store securely!
- User's name is only sent on **first sign-in** - must capture then
- Email may be a private relay address (e.g., `abc123@privaterelay.appleid.com`)
- Uses JWT (JSON Web Token) for client authentication instead of client secret
- No profile pictures available from Apple

---

## 🏗️ Architecture

### Class Hierarchy
```
OAuth (Abstract Base Class)
├── GoogleOAuth
├── MicrosoftOAuth
├── FacebookOAuth
└── AppleOAuth
```

### Base OAuth Class Features
**File:** [`_includes/auth/OAuth.php`](_includes/auth/OAuth.php)

- **RFC 6749 Compliant** - Standard OAuth 2.0 authorization code flow
- **State Token** - CSRF protection with 10-minute expiration
- **Token Management** - Exchange, refresh, and storage
- **Account Linking** - Link OAuth accounts to SIGNula users
- **User Lookup** - Find users by provider account
- **HTTP Helper** - Secure cURL-based requests
- **Configuration** - Database-driven with encrypted credentials

### Provider-Specific Methods
Each provider must implement:
```php
abstract public function getUserInfo(string $accessToken): array;
abstract protected function normalizeUserData(array $rawData): array;
```

---

## 🔒 Security Features

### 1. CSRF Protection
- **State Token:** Cryptographically secure random token
- **Session Storage:** Token stored in session with expiration
- **Verification:** Hash-equals timing-safe comparison
- **Single Use:** Token cleared after verification

### 2. Secure Credential Storage
- **Encryption:** AES-256-CBC for client secrets and tokens
- **Salt:** Random IV for each encryption
- **Database:** Encrypted values in `tblSettings`
- **Access Control:** Marked as `isSensitive`

### 3. Token Management
- **Encrypted Storage:** All OAuth tokens encrypted in database
- **Expiration Tracking:** Token expiry stored and monitored
- **Refresh Capability:** Automatic token refresh when expired
- **Revocation:** Proper token revocation on account unlink

### 4. HTTPS Enforcement
- **SSL Verification:** cURL SSL peer verification enabled
- **Secure Cookies:** HTTPOnly and Secure flags
- **Redirect URI Validation:** Exact match required

---

## 📊 Database Schema

### tblUserLinkedAccounts
Stores OAuth provider account linkages:

```sql
CREATE TABLE tblUserLinkedAccounts (
    linkedAccountID INT PRIMARY KEY AUTO_INCREMENT,
    userID INT NOT NULL,
    provider VARCHAR(50) NOT NULL,
    providerUserID VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    displayName VARCHAR(255),
    accessToken TEXT,              -- Encrypted
    refreshToken TEXT,             -- Encrypted
    tokenExpiresAt DATETIME,
    profilePicture VARCHAR(500),
    emailVerified BOOLEAN DEFAULT FALSE,
    accountData JSON,              -- Provider-specific data
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (userID, provider),
    UNIQUE KEY (provider, providerUserID),
    FOREIGN KEY (userID) REFERENCES tblUsers(userID)
);
```

---

## 🔄 OAuth Flow

### Standard Flow
```
1. User clicks "Sign in with [Provider]"
   → GET /oauth/authorize?provider=google

2. Generate state token and redirect to provider
   → Redirect to https://accounts.google.com/o/oauth2/v2/auth?...

3. User authenticates with provider
   → Provider handles authentication

4. Provider redirects back with code
   → POST /oauth/callback
   → Params: code, state

5. Verify state token
   → Check session, validate, clear

6. Exchange code for access token
   → POST to provider's token endpoint

7. Fetch user info
   → GET provider's userinfo endpoint with access token

8. Find or create SIGNula user
   → Check tblUserLinkedAccounts
   → Create new user if needed

9. Link provider account
   → Store in tblUserLinkedAccounts
   → Encrypt and save tokens

10. Complete login
    → Create session
    → Redirect to dashboard
```

### Apple-Specific Flow
Apple uses `form_post` response mode, so step 4 becomes:
```
4. Provider POSTs back with code
   → POST /oauth/callback (not GET)
   → Form data: code, state, user (JSON, only on first sign-in)
```

---

## 📝 Implementation Checklist

### ✅ Completed
- [x] OAuth base handler class
- [x] Google OAuth provider
- [x] Microsoft OAuth provider
- [x] Facebook OAuth provider
- [x] Apple Sign In provider
- [x] State token CSRF protection
- [x] Token encryption
- [x] Account linking infrastructure
- [x] User lookup by provider

### ⏳ Remaining
- [ ] OAuth callback handler (`/oauth/callback`)
- [ ] OAuth authorization endpoint (`/oauth/authorize`)
- [ ] Login page OAuth buttons
- [ ] Registration page OAuth buttons
- [ ] Account settings - linked accounts UI
- [ ] Account linking/unlinking logic
- [ ] Admin settings - OAuth configuration UI
- [ ] Error handling and user feedback
- [ ] Testing with each provider

---

## 🚀 Next Steps

### 1. Create OAuth Callback Handler
**File:** `public_html/oauth/callback.php`

Handles OAuth responses and completes the flow:
- Verify state token
- Exchange code for token
- Fetch user info
- Create/link account
- Handle errors

### 2. Create OAuth Authorization Endpoint
**File:** `public_html/oauth/authorize.php`

Initiates OAuth flow:
- Accept provider parameter
- Instantiate provider class
- Generate authorization URL
- Redirect user to provider

### 3. Update UI
Add OAuth buttons to:
- Login page
- Registration page
- Account settings (link/unlink)

### 4. Admin Configuration
Create UI for managing OAuth credentials:
- Add/edit provider credentials
- Test provider configuration
- View OAuth statistics

---

## 🔧 Configuration Guide

### Encrypting Sensitive Values

Before storing in database, encrypt sensitive values:

```php
// Encrypt client secret
$encrypted = SecurityUtils::encrypt($clientSecret);

// Store in database
Database::query(
    "INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES (?, ?, 1)",
    ['oauth.google.client_secret', $encrypted],
    'ss'
);
```

### Testing Provider Configuration

```php
// Instantiate provider
$provider = new GoogleOAuth();

// Check if configured
if (!$provider->isConfigured()) {
    echo "Provider not configured!";
    exit;
}

// Get authorization URL
$authUrl = $provider->getAuthorizationUrl();
echo "Redirect user to: " . $authUrl;
```

---

## 📚 Resources

### Google
- [OAuth 2.0 Documentation](https://developers.google.com/identity/protocols/oauth2)
- [Google Cloud Console](https://console.cloud.google.com/)

### Microsoft
- [Microsoft Identity Platform](https://docs.microsoft.com/en-us/azure/active-directory/develop/)
- [Azure Portal](https://portal.azure.com/)

### Facebook
- [Facebook Login Documentation](https://developers.facebook.com/docs/facebook-login)
- [Facebook Developers](https://developers.facebook.com/)

### Apple
- [Sign in with Apple Documentation](https://developer.apple.com/documentation/sign_in_with_apple)
- [Apple Developer Portal](https://developer.apple.com/)

---

## 📞 Support

For issues or questions:
1. Check provider-specific setup instructions above
2. Review error logs in `_private/logs/` and `tblErrorLog`
3. Verify OAuth credentials in database settings
4. Test with provider's OAuth playground/debugger

---

**Last Updated:** 2026-02-02
**Version:** 1.0.0
**Status:** Providers Complete - Integration Pending
