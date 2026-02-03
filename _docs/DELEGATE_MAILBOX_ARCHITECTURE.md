# 📧 Delegate Mailbox Sending - Architecture Design

## Overview

This document outlines the architecture for enabling **delegate mailbox sending** in SIGNula's email system, allowing emails to be sent from different mailboxes in Microsoft 365 and Google Workspace organizations.

## Current Limitations

### Microsoft 365 (Microsoft Graph API)
- **Problem**: Uses client credentials flow with application permissions
- **Result**: All emails sent from a single service mailbox (e.g., `noreply@company.com`)
- **Missing**: No per-user OAuth tokens or delegate mailbox support

### Google Workspace (Gmail API)
- **Status**: Already supports delegation via service account impersonation
- **Problem**: Not exposed via API - mailbox is hardcoded in configuration
- **Missing**: Dynamic mailbox selection parameter

### EmailService API
- **Problem**: No parameter to specify "send as" mailbox
- **Result**: Cannot send from user-specific mailboxes

---

## Solution Architecture

### 1. Two Approaches for Microsoft 365

#### **Approach A: Delegated Permissions (User-Level OAuth)**

**Best for**: User-initiated emails, personalized sending

**How it works**:
1. Users grant OAuth consent to SIGNula
2. Store per-user OAuth tokens (access + refresh)
3. Send emails using user's own OAuth token
4. Emails appear to come from the user's mailbox

**Required Azure AD Permissions**:
- `Mail.Send` (Delegated permission, NOT application)
- Requires user consent or admin pre-consent

**Pros**:
- ✅ Most secure - each user controls their own access
- ✅ Supports "send as" and "send on behalf of" mailbox permissions
- ✅ Audit trail shows which user sent each email
- ✅ Respects mailbox delegation settings in Exchange

**Cons**:
- ❌ Requires OAuth consent flow per user
- ❌ Must manage token refresh (tokens expire)
- ❌ More complex implementation

**Implementation Requirements**:
- New table: `tblUserOAuthTokens`
- OAuth authorization code flow
- Token refresh mechanism
- User consent UI

---

#### **Approach B: Application Permissions with "Send As"**

**Best for**: Service/transactional emails, simple implementation

**How it works**:
1. Use existing client credentials flow
2. Add `Mail.Send.Shared` application permission
3. Specify different `from` mailbox in API call
4. Requires explicit "send as" permission granted in Exchange

**Required Azure AD Permissions**:
- `Mail.Send` (already configured)
- `Mail.Send.Shared` (NEW - allows sending from any mailbox)

**Pros**:
- ✅ Simpler - no per-user OAuth needed
- ✅ No token refresh management
- ✅ Can use existing authentication

**Cons**:
- ❌ Requires Exchange "send as" permission for each mailbox
- ❌ Less granular audit trail
- ❌ Admin must pre-configure mailbox permissions

**Implementation Requirements**:
- Add `Mail.Send.Shared` permission to Azure AD app
- Grant admin consent
- Add mailbox selection parameter to API
- Configure Exchange "send as" permissions

---

### 2. Google Workspace Enhancement (Simple)

**Current State**: Already supports delegation via service account

**What's needed**:
1. Make JWT `sub` claim dynamic (currently hardcoded)
2. Add `sendAsEmail` parameter to `GmailAPIEmailProvider::send()`
3. Generate JWT with dynamic subject per email
4. No database changes needed!

**Code Changes** (minimal):
```php
// In GmailAPIEmailProvider.php

// Current (line 216):
'sub' => $this->config['send_from_email'],  // Hardcoded

// New (dynamic):
'sub' => $sendAsEmail ?? $this->config['send_from_email'],  // Dynamic with fallback
```

**Requirements**:
- Service account must have domain-wide delegation (already configured)
- Can send from ANY mailbox in the Google Workspace domain
- No additional Google permissions needed

---

### 3. Unified EmailService API

**New Method Signature**:
```php
public static function sendTemplateEmail(
    string $recipientEmail,
    string $templateKey,
    array $variables = [],
    ?int $userID = null,
    int $priority = 5,
    ?string $sendAsEmail = null  // NEW: Delegate mailbox
): bool
```

**New Method Signature for queueEmail()**:
```php
public static function queueEmail(
    string $recipientEmail,
    string $subject,
    ?string $bodyHTML,
    string $bodyText,
    ?string $fromEmail = null,
    ?string $fromName = null,
    ?string $replyTo = null,
    ?int $userID = null,
    ?int $templateID = null,
    int $priority = 5,
    ?\DateTime $scheduledFor = null,
    array $cc = [],
    array $bcc = [],
    array $attachments = [],
    bool $trackingEnabled = true,
    ?string $sendAsEmail = null  // NEW: Delegate mailbox
): bool
```

**Behavior**:
- If `$sendAsEmail` is null → use default service mailbox
- If `$sendAsEmail` is provided → send from that mailbox
- Providers decide how to implement delegate sending

---

## Database Schema Changes

### New Table: `tblUserOAuthTokens`

**Purpose**: Store per-user OAuth tokens for delegated permissions

```sql
CREATE TABLE IF NOT EXISTS tblUserOAuthTokens (
    tokenID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    userID INT UNSIGNED NOT NULL,
    provider VARCHAR(50) NOT NULL,  -- 'microsoft_graph', 'gmail_api'

    -- OAuth Token Data
    accessToken TEXT NOT NULL,  -- Encrypted
    refreshToken TEXT,  -- Encrypted (if available)
    tokenType VARCHAR(20) DEFAULT 'Bearer',
    expiresAt DATETIME,
    scope TEXT,

    -- Mailbox Info
    mailboxEmail VARCHAR(255) NOT NULL,  -- The mailbox this token can send from
    mailboxName VARCHAR(255),

    -- Status
    isActive BOOLEAN DEFAULT TRUE,
    lastUsedAt DATETIME,

    -- Error Handling
    errorCount INT DEFAULT 0,
    lastError TEXT,
    lastErrorAt DATETIME,

    -- Timestamps
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    UNIQUE KEY unique_user_provider_mailbox (userID, provider, mailboxEmail),
    KEY idx_user_provider (userID, provider),
    KEY idx_mailbox (mailboxEmail),
    KEY idx_expires (expiresAt),

    -- Foreign Key
    FOREIGN KEY (userID) REFERENCES tblUsers(userID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Update Table: `tblEmailQueue`

**Add new column** for delegate mailbox:

```sql
ALTER TABLE tblEmailQueue
ADD COLUMN sendAsEmail VARCHAR(255) AFTER fromName COMMENT 'Delegate mailbox to send from (if different from fromEmail)';

ALTER TABLE tblEmailQueue
ADD KEY idx_send_as (sendAsEmail);
```

---

## Implementation Phases

### Phase 1: Gmail API (Quick Win) ✅

**Effort**: Low (1-2 hours)
**Impact**: Immediate delegate sending support

1. ✅ Add `sendAsEmail` parameter to `GmailAPIEmailProvider::send()`
2. ✅ Make JWT `sub` claim dynamic
3. ✅ Add `sendAsEmail` to `EmailService` methods
4. ✅ Update `tblEmailQueue` schema
5. ✅ Test sending from different mailboxes

**Result**: Gmail users can send from any mailbox immediately!

---

### Phase 2: Microsoft Graph - Application Permissions (Medium)

**Effort**: Medium (4-6 hours)
**Impact**: Delegate sending without per-user OAuth

1. ✅ Add `Mail.Send.Shared` permission to Azure AD app
2. ✅ Grant admin consent
3. ✅ Update `MicrosoftGraphEmailProvider::buildGraphMessage()`
   - Allow dynamic `from` address in message
4. ✅ Test with Exchange "send as" permissions
5. ✅ Document Exchange setup requirements

**Exchange Admin Setup**:
```powershell
# Grant "send as" permission
Add-RecipientPermission -Identity "delegate@company.com" -Trustee "service-account@company.com" -AccessRights SendAs
```

**Result**: Microsoft 365 users can send from delegate mailboxes with admin-configured permissions

---

### Phase 3: Microsoft Graph - Delegated Permissions (Advanced)

**Effort**: High (12-16 hours)
**Impact**: Per-user OAuth with fine-grained control

1. ✅ Create `tblUserOAuthTokens` table
2. ✅ Build OAuth authorization code flow
   - Authorization URL generation
   - Callback handler
   - Token exchange
3. ✅ Implement token refresh mechanism
4. ✅ Create user consent UI
5. ✅ Update `MicrosoftGraphEmailProvider` to support both flows:
   - Client credentials (existing)
   - Delegated permissions (new)
6. ✅ Add token management UI for admins/users
7. ✅ Test token refresh and error handling

**Result**: Users can grant SIGNula permission to send emails on their behalf

---

## Provider-Specific Implementation

### Microsoft Graph Provider Updates

**File**: `private_html/email/providers/MicrosoftGraphEmailProvider.php`

**Changes**:

1. **Add property for authentication mode**:
```php
private string $authMode = 'client_credentials'; // or 'delegated'
```

2. **Update `send()` method**:
```php
public function send(array $emailData): array
{
    // Check if sendAsEmail is provided
    $sendAsEmail = $emailData['sendAsEmail'] ?? null;

    if ($sendAsEmail && $this->authMode === 'delegated') {
        // Use per-user OAuth token
        $accessToken = $this->getUserAccessToken($emailData['userID']);
        $sendEndpoint = self::GRAPH_API_URL . '/me/sendMail';
    } else {
        // Use client credentials
        $accessToken = $this->getAccessToken();
        $sendEndpoint = self::GRAPH_API_URL . '/users/' .
                        urlencode($sendAsEmail ?? $this->config['send_from_email']) .
                        '/sendMail';
    }

    // Rest of send logic...
}
```

3. **Add user token methods**:
```php
private function getUserAccessToken(int $userID): string
{
    // Fetch from tblUserOAuthTokens
    // Check if expired, refresh if needed
    // Return access token
}

private function refreshUserToken(int $tokenID): bool
{
    // Use refresh token to get new access token
    // Update tblUserOAuthTokens
}
```

---

### Gmail API Provider Updates

**File**: `private_html/email/providers/GmailAPIEmailProvider.php`

**Changes**:

1. **Update `send()` method** to accept `sendAsEmail`:
```php
public function send(array $emailData): array
{
    // Extract delegate mailbox
    $sendAsEmail = $emailData['sendAsEmail'] ?? $this->config['send_from_email'];

    // Get access token with dynamic subject
    $accessToken = $this->getAccessToken($sendAsEmail);

    // Use sendAsEmail in API endpoint
    $sendEndpoint = self::GMAIL_API_URL . '/users/' .
                    urlencode($sendAsEmail) .
                    '/messages/send';

    // Rest of send logic...
}
```

2. **Update `getAccessToken()` method**:
```php
private function getAccessToken(string $impersonateEmail = null): string
{
    $impersonateEmail = $impersonateEmail ?? $this->config['send_from_email'];

    // Check cache with email-specific key
    $cacheKey = 'gmail_token_' . md5($impersonateEmail);

    // If cached and valid, return
    // Otherwise, create JWT with dynamic subject
    // ...
}
```

3. **Update `createJWTAssertion()` method**:
```php
private function createJWTAssertion(string $impersonateEmail): string
{
    // ... existing code ...

    $payload = [
        'iss' => $this->serviceAccountCredentials['client_email'],
        'sub' => $impersonateEmail,  // DYNAMIC!
        'scope' => self::GMAIL_SEND_SCOPE,
        // ... rest of claims ...
    ];

    // ... rest of JWT creation ...
}
```

---

### EmailService API Updates

**File**: `private_html/email/EmailService.php`

**Changes**:

1. **Update `sendTemplateEmail()`**:
```php
public static function sendTemplateEmail(
    string $recipientEmail,
    string $templateKey,
    array $variables = [],
    ?int $userID = null,
    int $priority = 5,
    ?string $sendAsEmail = null  // NEW
): bool {
    // ... existing template loading ...

    return self::queueEmail(
        $recipientEmail,
        $subject,
        $bodyHTML,
        $bodyText,
        $template['fromEmail'] ?? getSetting('email.from.address'),
        $template['fromName'] ?? getSetting('email.from.name'),
        $template['replyTo'],
        $userID,
        $template['templateID'],
        $priority,
        null,  // scheduledFor
        [],    // cc
        [],    // bcc
        [],    // attachments
        true,  // trackingEnabled
        $sendAsEmail  // NEW
    );
}
```

2. **Update `queueEmail()`**:
```php
public static function queueEmail(
    string $recipientEmail,
    string $subject,
    ?string $bodyHTML,
    string $bodyText,
    ?string $fromEmail = null,
    ?string $fromName = null,
    ?string $replyTo = null,
    ?int $userID = null,
    ?int $templateID = null,
    int $priority = 5,
    ?\DateTime $scheduledFor = null,
    array $cc = [],
    array $bcc = [],
    array $attachments = [],
    bool $trackingEnabled = true,
    ?string $sendAsEmail = null  // NEW
): bool {
    // ... existing queue insertion ...

    $result = Database::execute(
        "INSERT INTO tblEmailQueue (..., sendAsEmail) VALUES (..., ?)",
        [..., $sendAsEmail],
        '...' . ($sendAsEmail ? 's' : '')
    );
}
```

---

### EmailQueueProcessor Updates

**File**: `private_html/email/EmailQueueProcessor.php`

**Changes**:

1. **Pass `sendAsEmail` to provider**:
```php
public function processEmail(array $email): bool
{
    // ... existing code ...

    $emailData = [
        'to' => $email['recipientEmail'],
        'subject' => $email['subject'],
        'bodyHTML' => $email['bodyHTML'],
        'bodyText' => $email['bodyText'],
        'from' => $email['fromEmail'],
        'fromName' => $email['fromName'],
        'replyTo' => $email['replyToEmail'],
        'cc' => $email['ccRecipients'] ? json_decode($email['ccRecipients'], true) : [],
        'bcc' => $email['bccRecipients'] ? json_decode($email['bccRecipients'], true) : [],
        'attachments' => $email['attachments'] ? json_decode($email['attachments'], true) : [],
        'sendAsEmail' => $email['sendAsEmail'],  // NEW
        'userID' => $email['userID']  // NEW (for delegated token lookup)
    ];

    // ... send via provider ...
}
```

---

## Security Considerations

### 1. OAuth Token Storage
- ✅ All tokens stored **encrypted** in database
- ✅ Use `SecurityUtils::encrypt()` / `decrypt()`
- ✅ Tokens marked as `isSensitive` in schema

### 2. Mailbox Validation
- ✅ Validate `sendAsEmail` is in same domain
- ✅ Check user has permission to use mailbox
- ✅ Log all delegate sending attempts

### 3. Token Refresh
- ✅ Automatically refresh expired tokens
- ✅ Handle refresh token expiration gracefully
- ✅ Notify user if re-consent needed

### 4. Audit Trail
- ✅ Log all delegate sends to `tblActivityLog`
- ✅ Include: who sent, from which mailbox, when
- ✅ Track token usage in `tblUserOAuthTokens.lastUsedAt`

---

## Configuration Settings

### New Database Settings

**Microsoft Graph Delegated Permissions**:
```sql
INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
('email.microsoft.use_delegated_permissions', 'false', FALSE),
('email.microsoft.delegated_redirect_uri', 'https://signulo.id/oauth/callback', FALSE);
```

**Gmail API Domain Restrictions**:
```sql
INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
('email.gmail.allowed_domains', 'company.com,subsidiary.com', FALSE);
```

---

## Testing Strategy

### Phase 1: Gmail API Testing
1. ✅ Configure service account with domain-wide delegation
2. ✅ Create test mailboxes: `test1@company.com`, `test2@company.com`
3. ✅ Send email with `sendAsEmail = 'test1@company.com'`
4. ✅ Verify email appears from `test1@company.com`
5. ✅ Test fallback to default mailbox when `sendAsEmail = null`

### Phase 2: Microsoft Graph Testing
1. ✅ Add `Mail.Send.Shared` permission
2. ✅ Grant admin consent
3. ✅ Configure Exchange "send as" permissions
4. ✅ Send email with different `sendAsEmail`
5. ✅ Verify email headers and audit logs

### Phase 3: Delegated Permissions Testing
1. ✅ Implement OAuth consent flow
2. ✅ Test user authorization
3. ✅ Test token refresh
4. ✅ Test token expiration handling
5. ✅ Test error scenarios (revoked consent, expired refresh token)

---

## Example Usage

### Sending from Delegate Mailbox

```php
// Send verification email from sales mailbox
EmailService::sendTemplateEmail(
    recipientEmail: 'customer@example.com',
    templateKey: 'welcome_email',
    variables: ['name' => 'John Doe'],
    userID: 123,
    priority: 5,
    sendAsEmail: 'sales@company.com'  // Delegate mailbox
);
```

### Sending from User's Own Mailbox (Delegated Permissions)

```php
// User wants to send email from their own mailbox
// Requires user OAuth token in tblUserOAuthTokens
EmailService::sendTemplateEmail(
    recipientEmail: 'client@example.com',
    templateKey: 'meeting_invitation',
    variables: ['meeting_time' => '2:00 PM'],
    userID: 456,
    priority: 5,
    sendAsEmail: 'john.doe@company.com'  // User's mailbox
);
```

---

## Recommended Implementation Order

### Quick Win (Recommended First)
**✅ Phase 1: Gmail API Enhancement**
- **Effort**: 1-2 hours
- **Impact**: Immediate delegate sending for Google Workspace users
- **Risk**: Low

### Medium Priority
**✅ Phase 2: Microsoft Graph - Application Permissions**
- **Effort**: 4-6 hours
- **Impact**: Delegate sending for Microsoft 365 (requires admin setup)
- **Risk**: Low-Medium

### Long-term Enhancement
**✅ Phase 3: Microsoft Graph - Delegated Permissions**
- **Effort**: 12-16 hours
- **Impact**: Full per-user OAuth with fine-grained control
- **Risk**: Medium

---

## Conclusion

**Immediate Action**: Start with **Phase 1 (Gmail API)** - it's the quickest win with minimal code changes.

**Google Workspace users** will be able to send from delegate mailboxes within hours, while you plan the more complex Microsoft 365 implementation.

The architecture supports **both simple (service account) and advanced (per-user OAuth) patterns**, giving you flexibility based on your use case and timeline.

---

## References

- [Microsoft Graph API - Send Mail](https://docs.microsoft.com/en-us/graph/api/user-sendmail)
- [Microsoft Graph - Delegated Permissions](https://docs.microsoft.com/en-us/graph/auth-v2-user)
- [Gmail API - Sending Email](https://developers.google.com/gmail/api/guides/sending)
- [Google OAuth - Service Account](https://developers.google.com/workspace/guides/create-credentials#service-account)
- [Exchange Online - Send As Permission](https://docs.microsoft.com/en-us/powershell/module/exchange/add-recipientpermission)
