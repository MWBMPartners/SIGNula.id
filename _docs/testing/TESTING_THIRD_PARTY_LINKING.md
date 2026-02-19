# Testing Third-Party Account Linking -- SIGNula

**Version:** 2.4.0-beta
**Last Updated:** February 18, 2026
**Status:** Active Testing Guide

> Guide for testing OAuth provider linking, unlinking, and login via third-party accounts.

---

## Prerequisites

### Required Configuration

Before testing third-party account linking, ensure the following:

| Requirement | Details |
|-------------|---------|
| OAuth Credentials | Client ID and Client Secret for each provider stored in `tblSettings` |
| Redirect URI | `https://signula.id/oauth/callback` configured in each provider's developer console |
| SSL | HTTPS required for all OAuth flows (providers reject HTTP callbacks) |
| Test Accounts | At least one test account per provider |
| SIGNula Account | A verified local SIGNula account to link providers to |

### Provider Developer Consoles

| Provider | Console URL |
|----------|-------------|
| Google | [console.cloud.google.com](https://console.cloud.google.com/) |
| Microsoft | [portal.azure.com](https://portal.azure.com/) > Azure AD > App registrations |
| Apple | [developer.apple.com](https://developer.apple.com/) > Certificates, Identifiers & Profiles |
| Facebook | [developers.facebook.com](https://developers.facebook.com/) |
| LinkedIn | [developer.linkedin.com](https://developer.linkedin.com/) |

### Database Verification

```sql
-- Verify OAuth credentials are configured
SELECT settingKey,
       CASE WHEN isSensitive = 1 THEN '[ENCRYPTED]' ELSE settingValue END AS settingValue,
       isSensitive
FROM tblSettings
WHERE settingKey LIKE 'oauth.%'
ORDER BY settingKey;

-- Verify linked accounts table exists
SHOW CREATE TABLE tblUserLinkedAccounts;
```

---

## 1. Google OAuth

### 1.1 Personal Account Linking

Navigate to Connected Accounts (`/settings/connected-accounts`).

**Linking Flow:**

- [ ] Click the "Connect Google" button
  - **Expected:** Redirected to Google's OAuth consent screen
- [ ] Verify the consent screen shows correct app name ("SIGNula")
  - **Expected:** App name, logo, and requested permissions displayed
- [ ] Verify requested scopes: `openid`, `email`, `profile`
  - **Expected:** Consent screen shows "See your email address" and "See your personal info"
- [ ] Click "Allow" / "Continue" on Google's consent screen
  - **Expected:** Redirected back to SIGNula, Google account now shown as linked
- [ ] Verify linked account data stored in `tblUserLinkedAccounts`
  - **Expected:** Row with `provider = 'google'`, email, display name, profile picture URL
- [ ] Verify access token and refresh token stored (encrypted)
  - **Expected:** `accessToken` and `refreshToken` fields populated with encrypted values
- [ ] Verify activity logged: `oauth_linked` with `provider = google`

```sql
-- Verify Google account linked
SELECT linkedAccountID, provider, email, displayName, emailVerified,
       tokenExpiresAt, createdAt
FROM tblUserLinkedAccounts
WHERE userID = ? AND provider = 'google';
```

**Negative Tests:**

- [ ] Click "Connect Google" then click "Cancel" on Google's consent screen
  - **Expected:** Redirected back to SIGNula with error message "Google account linking cancelled"
- [ ] Attempt to link a Google account already linked to another SIGNula user
  - **Expected:** Error "This Google account is already linked to another SIGNula account"

### 1.2 Google Workspace Linking

- [ ] Click "Connect Google" while signed into a Google Workspace account
  - **Expected:** Workspace account linked, domain info captured in `accountData` JSON
- [ ] Verify Workspace-specific data captured (domain, organization)
  - **Expected:** `accountData` contains `hd` (hosted domain) field
- [ ] Verify Workspace profile picture retrieved
  - **Expected:** Profile picture URL stored in `profilePicture` field

### 1.3 Login via Google

Log out of SIGNula, then navigate to the login page.

- [ ] Click "Sign in with Google"
  - **Expected:** Redirected to Google's authentication
- [ ] Select the previously linked Google account
  - **Expected:** Logged into SIGNula, redirected to dashboard
- [ ] Verify session created with correct user
  - **Expected:** Session in `tblSessions` with the SIGNula userID linked to this Google account
- [ ] Verify activity logged: `oauth_login` with `provider = google`

**Negative Tests:**

- [ ] Sign in with a Google account that is NOT linked to any SIGNula account
  - **Expected:** Option to create a new account or link to an existing account, OR error "No SIGNula account linked to this Google account"

---

## 2. Microsoft OAuth

### 2.1 Personal Account

Navigate to Connected Accounts (`/settings/connected-accounts`).

- [ ] Click "Connect Microsoft"
  - **Expected:** Redirected to Microsoft's OAuth consent screen (`login.microsoftonline.com`)
- [ ] Verify consent screen shows correct app name and requested permissions
  - **Expected:** Shows app name, requested `User.Read`, `email`, `profile`, `offline_access`
- [ ] Sign in with a personal Microsoft account (Outlook.com, Hotmail, Live)
  - **Expected:** Consent granted, redirected back to SIGNula
- [ ] Verify personal Microsoft account linked successfully
  - **Expected:** Account appears in Connected Accounts with email and name
- [ ] Verify Microsoft Graph profile data retrieved
  - **Expected:** Display name, email, and profile photo URL stored

### 2.2 Microsoft 365 Work/School

- [ ] Click "Connect Microsoft" and sign in with a Microsoft 365 Work/School account
  - **Expected:** Work account linked, organizational data captured
- [ ] Verify organizational-specific data in `accountData` (job title, office location, department)
  - **Expected:** JSON contains work-specific fields from Microsoft Graph
- [ ] Verify the account is identified as a work/school account (not personal)
  - **Expected:** `accountData` distinguishes account type

### 2.3 Login via Microsoft

- [ ] Log out and click "Sign in with Microsoft" on login page
  - **Expected:** Redirected to Microsoft authentication
- [ ] Complete Microsoft login
  - **Expected:** Logged into SIGNula with the linked account
- [ ] Verify tokens refreshed if expired
  - **Expected:** `tokenExpiresAt` updated in database

**Negative Tests:**

- [ ] Cancel Microsoft OAuth flow mid-way
  - **Expected:** Redirected back to SIGNula with cancellation message
- [ ] Revoke SIGNula's access in Microsoft account settings, then attempt login
  - **Expected:** Error indicating access was revoked, must re-link

---

## 3. Apple Sign In

Navigate to Connected Accounts (`/settings/connected-accounts`).

### 3.1 Apple ID Linking

- [ ] Click "Sign in with Apple" / "Connect Apple"
  - **Expected:** Apple's sign-in sheet appears (may be a popup or redirect)
- [ ] Enter Apple ID credentials
  - **Expected:** Apple authentication succeeds
- [ ] Choose whether to share or hide email (Apple's private relay option)
  - **Expected:** Choice respected -- either real email or `xxx@privaterelay.appleid.com` stored
- [ ] Verify Apple account linked in `tblUserLinkedAccounts`
  - **Expected:** Row with `provider = 'apple'`, name (if first sign-in), email

**Important Apple-Specific Considerations:**

- [ ] Verify that Apple only sends the user's name on the **first** sign-in
  - **Expected:** Name captured and stored on first link; subsequent authentications do not include name
- [ ] Verify private relay email addresses are handled correctly
  - **Expected:** `xxx@privaterelay.appleid.com` stored as email, communications still work
- [ ] Verify Apple uses `form_post` response mode (POST, not GET)
  - **Expected:** Callback handler processes POST data correctly

### 3.2 Login via Apple

- [ ] Log out, click "Sign in with Apple" on login page
  - **Expected:** Apple authentication flow, then logged into SIGNula
- [ ] Verify session created correctly

### 3.3 Negative Tests

- [ ] Cancel Apple sign-in flow
  - **Expected:** Redirected back with cancellation message
- [ ] Attempt login with Apple account not linked to SIGNula
  - **Expected:** Appropriate error or account creation prompt
- [ ] Note: No profile picture available from Apple -- verify placeholder is used
  - **Expected:** Default/placeholder avatar shown for Apple-linked accounts

---

## 4. Facebook/Meta

Navigate to Connected Accounts (`/settings/connected-accounts`).

### 4.1 Facebook Account Linking

- [ ] Click "Connect Facebook"
  - **Expected:** Redirected to Facebook's OAuth dialog
- [ ] Verify requested permissions: `email`, `public_profile`
  - **Expected:** Facebook dialog shows "Access your email address" and "Public profile"
- [ ] Grant permissions and allow access
  - **Expected:** Redirected back to SIGNula, Facebook account linked
- [ ] Verify linked account data (name, email, profile picture)
  - **Expected:** Data stored in `tblUserLinkedAccounts`

**Facebook-Specific Considerations:**

- [ ] Verify short-lived access token exchanged for long-lived token (60 days)
  - **Expected:** `tokenExpiresAt` is approximately 60 days from now
- [ ] Test scenario where user does not grant email permission
  - **Expected:** Graceful handling -- account linked without email, or user prompted to grant email
- [ ] Verify Facebook app is in "Live" mode for production, "Development" mode for testing
  - **Expected:** Development mode limits to test users/app roles only

### 4.2 Login via Facebook

- [ ] Log out, click "Sign in with Facebook" on login page
  - **Expected:** Facebook authentication, then SIGNula login
- [ ] Verify session and activity logging

### 4.3 Negative Tests

- [ ] Cancel Facebook OAuth dialog
  - **Expected:** Redirected back with cancellation message
- [ ] Revoke app access in Facebook Settings > Apps, then attempt login
  - **Expected:** Error, must re-link

---

## 5. LinkedIn

Navigate to Connected Accounts (`/settings/connected-accounts`).

### 5.1 LinkedIn Account Linking

- [ ] Click "Connect LinkedIn"
  - **Expected:** Redirected to LinkedIn's OAuth consent screen
- [ ] Verify requested scopes (e.g., `openid`, `profile`, `email`)
  - **Expected:** LinkedIn shows requested permissions
- [ ] Grant access
  - **Expected:** Redirected back, LinkedIn account linked
- [ ] Verify linked account data (name, email, profile picture, headline)
  - **Expected:** Professional profile data stored

### 5.2 Login via LinkedIn

- [ ] Log out, click "Sign in with LinkedIn" on login page
  - **Expected:** LinkedIn authentication, then SIGNula login

### 5.3 Negative Tests

- [ ] Cancel LinkedIn OAuth
  - **Expected:** Redirected back with message
- [ ] Test with LinkedIn account that does not have email set to public
  - **Expected:** Graceful handling

---

## 6. Multi-Provider Linking

### 6.1 Link Multiple Providers

Starting with a SIGNula account with no linked providers:

- [ ] Link Google account
  - **Expected:** Google appears in Connected Accounts list
- [ ] Link Microsoft account (same SIGNula user)
  - **Expected:** Both Google and Microsoft shown in Connected Accounts
- [ ] Link Facebook account (same SIGNula user)
  - **Expected:** Three providers shown
- [ ] Verify all three stored in `tblUserLinkedAccounts` with the same `userID`

```sql
-- Verify multi-provider linking
SELECT provider, email, displayName, createdAt
FROM tblUserLinkedAccounts
WHERE userID = ?
ORDER BY provider;
```

### 6.2 Set Primary Provider

- [ ] Navigate to Connected Accounts
- [ ] Set Google as the primary provider
  - **Expected:** Google marked as primary, avatar sourced from Google
- [ ] Change primary to Microsoft
  - **Expected:** Microsoft now primary, avatar updates to Microsoft profile picture
- [ ] Verify only one provider can be primary at a time

```bash
# Test via API
curl -X POST https://signula.id/api/v1/oauth/set-primary \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"provider": "google"}'
```

### 6.3 Login Method Priority

- [ ] Log out and login via Google (linked to account)
  - **Expected:** Logged into correct SIGNula account
- [ ] Log out and login via Microsoft (linked to same account)
  - **Expected:** Logged into the same SIGNula account
- [ ] Log out and login via email/password
  - **Expected:** Logged into the same SIGNula account
- [ ] Verify all three login methods reach the same user profile and session

---

## 7. Unlinking Providers

### 7.1 Unlink Non-Primary

Starting with multiple providers linked:

- [ ] Unlink a non-primary provider (e.g., Facebook)
  - **Expected:** Confirmation prompt shown
- [ ] Confirm unlinking
  - **Expected:** Provider removed from Connected Accounts list
- [ ] Verify database row removed or marked inactive in `tblUserLinkedAccounts`
- [ ] Verify activity logged: `oauth_unlinked` with provider name
- [ ] Attempt to login with the unlinked provider
  - **Expected:** Login fails, provider no longer associated with account

```bash
# Test via API
curl -X DELETE https://signula.id/api/v1/oauth/unlink/facebook \
  -H "Authorization: Bearer {token}"
```

### 7.2 Prevent Unlinking Last Auth Method

This is a critical safety check -- users must always retain at least one way to authenticate.

- [ ] Link only one provider (e.g., Google) with no local password set
  - **Expected:** Google is the only authentication method
- [ ] Attempt to unlink Google
  - **Expected:** Error "Cannot unlink your only authentication method. Please set a password or link another provider first."
- [ ] User has a local password AND one linked provider
  - **Expected:** Can unlink the provider (password remains as auth method)
- [ ] User has two linked providers and no local password
  - **Expected:** Can unlink one (the other remains), cannot unlink the last one

### 7.3 Re-linking After Unlink

- [ ] Unlink Google from account
- [ ] Re-link the same Google account
  - **Expected:** Google re-linked successfully, fresh tokens stored
- [ ] Verify new tokens stored (not stale tokens from previous link)
  - **Expected:** `createdAt` and `tokenExpiresAt` updated to current time
- [ ] Verify re-link works even if provider had previously been linked

---

## 8. Error Scenarios

### 8.1 Token Expiry

- [ ] Wait for an access token to expire naturally (typically 1-2 hours)
  - **Expected:** System detects expiry and uses refresh token automatically
- [ ] Verify the automatic token refresh updates `tblUserLinkedAccounts`
  - **Expected:** New `accessToken`, updated `tokenExpiresAt`
- [ ] Manually set `tokenExpiresAt` to a past date in the database, then trigger a provider action
  - **Expected:** System refreshes the token transparently

```sql
-- Manually expire a token for testing
UPDATE tblUserLinkedAccounts
SET tokenExpiresAt = DATE_SUB(NOW(), INTERVAL 1 HOUR)
WHERE userID = ? AND provider = 'google';
```

### 8.2 Revoked Access

- [ ] Go to the Google account security settings and revoke SIGNula's access
  - **Expected:** Next time SIGNula tries to use the token, it fails gracefully
- [ ] Attempt to login via the revoked Google account
  - **Expected:** Error message "Access has been revoked. Please re-link your Google account."
- [ ] Verify the error is logged in `tblActivityLog`
- [ ] Re-link the same Google account after revocation
  - **Expected:** New consent screen shown, fresh tokens issued

### 8.3 Duplicate Email Across Providers

- [ ] Link Google account with email `user@gmail.com`
- [ ] Link Microsoft account that has the SAME email `user@gmail.com`
  - **Expected:** System handles this gracefully. Possible outcomes:
    - Both linked with same email (different `providerUserID`)
    - Warning about duplicate email
    - Prompt to merge or choose which takes priority
- [ ] Verify no data corruption or conflicts in `tblUserLinkedAccounts`

### 8.4 Provider Downtime

- [ ] Simulate provider downtime (e.g., block the provider's domain in hosts file)
  - **Expected:** Timeout error with user-friendly message "Unable to connect to Google. Please try again later."
- [ ] Verify appropriate timeout handling (cURL should have reasonable timeout, e.g., 10 seconds)
- [ ] Verify error logged in `tblErrorLog`

### 8.5 Invalid/Expired State Parameter

The `state` parameter prevents CSRF attacks on the OAuth flow.

- [ ] Start an OAuth flow (generates state token in session)
- [ ] Modify the `state` parameter in the callback URL manually
  - **Expected:** Error "Invalid state parameter. Possible CSRF attack."
- [ ] Wait for the state token to expire (default: 10 minutes), then complete the callback
  - **Expected:** Error "OAuth state has expired. Please try again."
- [ ] Attempt to reuse a state token from a previous OAuth flow
  - **Expected:** Error "Invalid or expired state parameter"
- [ ] Verify CSRF violations are logged in `tblActivityLog`

### 8.6 Network Errors During Token Exchange

- [ ] Simulate a network error during the authorization code exchange step
  - **Expected:** User-friendly error "Something went wrong during authentication. Please try again."
- [ ] Verify the failed exchange does not leave partial data in the database

### 8.7 Mismatched Redirect URI

- [ ] Change the redirect URI in the provider's console (mismatch with SIGNula's configured URI)
  - **Expected:** Provider returns `redirect_uri_mismatch` error
- [ ] Verify SIGNula handles this with a clear error message to the user

---

## 9. Provider-Specific Notes

### Google

| Item | Detail |
|------|--------|
| Token Lifetime | Access: ~1 hour, Refresh: indefinite (unless revoked) |
| Scopes | `openid`, `userinfo.email`, `userinfo.profile` |
| Workspace Detection | `hd` claim in ID token identifies hosted domain |
| Profile Picture | Available via `picture` claim in userinfo |
| Email Verification | `email_verified` claim indicates if Google has verified the email |
| Offline Access | Use `access_type=offline` to get refresh tokens |

### Microsoft

| Item | Detail |
|------|--------|
| Token Lifetime | Access: ~1 hour, Refresh: 90 days (rolling) |
| Scopes | `openid`, `profile`, `email`, `User.Read`, `offline_access` |
| Multi-Tenant | Use `common` endpoint for both personal and work accounts |
| Profile Photo | Requires separate Microsoft Graph API call to `/me/photo/$value` |
| Account Types | Check `iss` claim to distinguish personal vs. organizational |
| Refresh Token | Rolling -- each use extends the 90-day window |

### Apple

| Item | Detail |
|------|--------|
| Token Lifetime | Access: ~5 minutes, Refresh: indefinite (unless revoked) |
| Authentication | Uses JWT client secret (generated from private key) |
| Name Delivery | **Only on first sign-in** -- must capture immediately |
| Email Privacy | Users can choose to hide real email via Private Relay |
| Response Mode | Uses `form_post` (POST, not GET) |
| Profile Picture | **Not available** -- Apple does not provide profile photos |
| Developer Account | Requires paid Apple Developer account ($99/year) |
| Private Key | Download once only -- store securely |

### Facebook/Meta

| Item | Detail |
|------|--------|
| Token Lifetime | Short-lived: ~1-2 hours, Long-lived: ~60 days |
| Scopes | `email`, `public_profile` |
| App Review | Email permission requires Facebook app review for public apps |
| Email Availability | User may not grant email permission -- handle gracefully |
| Token Exchange | Exchange short-lived for long-lived token after initial auth |
| Debugging | Use Facebook's [Access Token Debugger](https://developers.facebook.com/tools/debug/accesstoken/) |
| Instagram | Same OAuth infrastructure, additional scopes needed |

### LinkedIn

| Item | Detail |
|------|--------|
| Token Lifetime | Access: ~60 days |
| Scopes | `openid`, `profile`, `email` (OpenID Connect) |
| Profile Data | Name, email, profile picture, headline |
| Rate Limits | LinkedIn has strict API rate limits -- monitor usage |
| App Review | Some scopes require LinkedIn partner program membership |

---

## 10. Cross-Browser and Cross-Device OAuth Testing

### Browser Compatibility

- [ ] Test Google OAuth in Chrome
- [ ] Test Google OAuth in Firefox
- [ ] Test Google OAuth in Safari
- [ ] Test Google OAuth in Edge
- [ ] Test Microsoft OAuth in Chrome
- [ ] Test Microsoft OAuth in Firefox
- [ ] Test Apple Sign In in Safari (required for web)
- [ ] Test Apple Sign In in Chrome
- [ ] Test all providers on mobile Safari (iOS)
- [ ] Test all providers on Chrome (Android)

### Popup vs. Redirect

- [ ] Verify OAuth works when provider opens in a popup window
- [ ] Verify OAuth works when provider uses full-page redirect
- [ ] Verify OAuth works when popup blockers are enabled (should fall back to redirect)

---

## Test Execution Tracking

| Section | Total Tests | Passed | Failed | Blocked | Notes |
|---------|-------------|--------|--------|---------|-------|
| 1. Google OAuth | 12 | -- | -- | -- | |
| 2. Microsoft OAuth | 10 | -- | -- | -- | |
| 3. Apple Sign In | 10 | -- | -- | -- | |
| 4. Facebook/Meta | 10 | -- | -- | -- | |
| 5. LinkedIn | 6 | -- | -- | -- | |
| 6. Multi-Provider | 10 | -- | -- | -- | |
| 7. Unlinking | 10 | -- | -- | -- | |
| 8. Error Scenarios | 16 | -- | -- | -- | |
| 9. Provider Notes | -- | -- | -- | -- | Reference only |
| 10. Cross-Browser | 12 | -- | -- | -- | |
| **Total** | **96** | **--** | **--** | **--** | |

---

## Related Documentation

- [TESTING_LOCAL_ACCOUNTS.md](TESTING_LOCAL_ACCOUNTS.md) -- Local account tests (registration, login, MFA)
- [TESTING_API_INTEGRATION.md](TESTING_API_INTEGRATION.md) -- API integration testing
- [../authentication/OAUTH_PROVIDERS.md](../authentication/OAUTH_PROVIDERS.md) -- OAuth provider implementation details
- [../TESTING_GUIDE_COMPREHENSIVE.md](../TESTING_GUIDE_COMPREHENSIVE.md) -- Overall testing guide
- [../SECURITY_TESTING_GUIDE.md](../SECURITY_TESTING_GUIDE.md) -- Security-specific testing

---

**Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
