# 📬 Shared Mailboxes & Dual-Mode Authentication

**Last Updated**: 2026-02-03
**Version**: 2.0.0

---

## Overview

SIGNula supports **dual-mode authentication** for email sending, allowing you to optimize costs and flexibility:

- **Application-level auth**: For shared/unlicensed mailboxes (system emails)
- **User-level auth**: For individual user mailboxes (personalized emails)
- **Auto mode**: Intelligent fallback between both

This guide explains how to use **shared mailboxes** (unlicensed) for cost-effective email sending without consuming paid user licenses.

---

## 🎯 Use Cases

### Use Application-Level Auth (Shared Mailboxes) For:

✅ **System/Transactional Emails**:
- Account verification
- Password resets
- Login confirmations
- Notifications
- Invoices/receipts

✅ **Department/Team Emails**:
- `support@company.com`
- `sales@company.com`
- `info@company.com`
- `noreply@company.com`
- `billing@company.com`

✅ **Cost Savings**:
- **Shared mailboxes are FREE** (up to 50GB)
- No user license required
- Perfect for high-volume system emails

### Use User-Level Auth (Personal Mailboxes) For:

✅ **Personal/User-Initiated Emails**:
- User sending emails from their own mailbox
- Personal correspondence
- When email must appear from specific person
- Audit trail requirements

✅ **Licensed User Mailboxes**:
- Regular user accounts with Microsoft 365 licenses
- Requires user OAuth consent

---

## 💰 Cost Comparison

| Mailbox Type | License Required | Monthly Cost | Best For |
|--------------|------------------|--------------|----------|
| **Shared Mailbox** | ❌ No | $0 | System emails, team addresses |
| **User Mailbox** (E1) | ✅ Yes | ~$8-10/user | Personal use, individual users |
| **User Mailbox** (E3) | ✅ Yes | ~$20-23/user | Personal use + advanced features |

**Example Savings**:
- 5 system mailboxes (support, sales, info, noreply, billing)
- Using shared mailboxes: **$0/month**
- Using user mailboxes: **$40-50/month** (5 × $8-10)
- **Annual savings: $480-600**

---

## 🏢 Microsoft 365: Shared Mailboxes

### What is a Shared Mailbox?

A **shared mailbox** is a special type of mailbox in Microsoft 365 that:
- ❌ **Does NOT require a license** (free!)
- ❌ **Cannot sign in directly** (no user login)
- ✅ **Can send and receive emails** (via application auth)
- ✅ **Up to 50GB storage** (free tier)
- ✅ **Multiple users can access** (with permissions)
- ✅ **Perfect for team/department addresses**

### Creating a Shared Mailbox

#### Option A: Microsoft 365 Admin Center

1. **Sign in** to [Microsoft 365 Admin Center](https://admin.microsoft.com)
2. **Navigate** to **Teams & groups** > **Shared mailboxes**
3. **Click** "+ Add a shared mailbox"
4. **Enter details**:
   - Name: `Support`
   - Email address: `support@company.com`
   - Description: `Customer support mailbox`
5. **Click** "Add"
6. **Grant access** to users who need to manage it (optional)

#### Option B: PowerShell

```powershell
# Connect to Exchange Online
Connect-ExchangeOnline -UserPrincipalName admin@company.com

# Create shared mailbox
New-Mailbox -Shared -Name "Support" -DisplayName "Support Team" -Alias "support" -PrimarySmtpAddress "support@company.com"

# Grant "Send As" permission to service account
Add-RecipientPermission -Identity "support@company.com" -Trustee "noreply@company.com" -AccessRights SendAs -Confirm:$false

# Verify
Get-Mailbox "support@company.com" | Format-List Name, RecipientTypeDetails, PrimarySmtpAddress
```

### Shared Mailbox Limits

| Feature | Free Tier | With License |
|---------|-----------|--------------|
| Storage | 50 GB | Unlimited |
| Send/Receive | ✅ Yes | ✅ Yes |
| Archive | ❌ No | ✅ Yes (100 GB) |
| Litigation Hold | ❌ No | ✅ Yes |
| In-Place eDiscovery | ❌ No | ✅ Yes |

**Note**: If you need >50GB storage, you can assign an Exchange Online Plan 1 license ($4/month) - still cheaper than a user license!

---

## 🔧 Configuration

### Authentication Modes

SIGNula supports **three authentication modes** for Microsoft Graph:

#### 1. AUTO Mode (Recommended)

**Behavior**: Intelligent fallback
- ✅ Try user OAuth token first (if available)
- ✅ Fallback to application auth (if no user token)
- ✅ Best of both worlds

**Configuration**:
```sql
-- Enable auto mode (default)
UPDATE tblSettings
SET settingValue = 'auto'
WHERE settingKey = 'email.microsoft.auth_mode';
```

**Use Case**: Most scenarios - automatically uses the best auth method available

#### 2. APPLICATION Mode

**Behavior**: Always use application credentials
- ✅ Uses client credentials flow
- ✅ Perfect for shared mailboxes
- ✅ No user OAuth needed
- ✅ Requires Mail.Send.Shared + Exchange "Send As"

**Configuration**:
```sql
-- Force application mode
UPDATE tblSettings
SET settingValue = 'application'
WHERE settingKey = 'email.microsoft.auth_mode';

-- Disable delegated permissions
UPDATE tblSettings
SET settingValue = 'false'
WHERE settingKey = 'email.microsoft.use_delegated_permissions';
```

**Use Case**: System emails, shared mailboxes, cost optimization

#### 3. DELEGATED Mode

**Behavior**: Only use user OAuth tokens
- ✅ Per-user authentication
- ✅ Most secure/granular
- ❌ Requires user consent
- ❌ Won't work without user token

**Configuration**:
```sql
-- Force delegated mode
UPDATE tblSettings
SET settingValue = 'delegated'
WHERE settingKey = 'email.microsoft.auth_mode';

-- Enable delegated permissions
UPDATE tblSettings
SET settingValue = 'true'
WHERE settingKey = 'email.microsoft.use_delegated_permissions';
```

**Use Case**: User-initiated emails, strict audit requirements

---

## 📧 Usage Examples

### Example 1: System Email from Shared Mailbox (Application Auth)

```php
// Send password reset from unlicensed shared mailbox
// Uses application auth automatically (no userID provided)
EmailService::sendTemplateEmail(
    recipientEmail: 'customer@example.com',
    templateKey: 'password_reset',
    variables: ['token' => 'abc123'],
    userID: null,  // No user - uses application auth
    priority: 5,
    sendAsEmail: 'support@company.com'  // Shared mailbox (unlicensed!)
);
```

**Authentication Used**: Application (client credentials)
**Cost**: $0 (shared mailbox)
**Appears From**: support@company.com

---

### Example 2: User Email from Personal Mailbox (Delegated Auth)

```php
// User John sends email from his personal mailbox
// Uses user OAuth token if available, falls back to application auth
EmailService::sendTemplateEmail(
    recipientEmail: 'client@example.com',
    templateKey: 'meeting_invite',
    variables: ['meeting_time' => '2 PM'],
    userID: 123,  // John's user ID - tries delegated auth first
    priority: 5,
    sendAsEmail: 'john.doe@company.com'  // John's licensed mailbox
);
```

**Authentication Used**: Delegated (if John authorized) OR Application (fallback)
**Cost**: $0 (already has license)
**Appears From**: john.doe@company.com

---

### Example 3: Bulk Notifications from Unlicensed Mailbox

```php
// Send 1000 notifications from free shared mailbox
foreach ($customers as $customer) {
    EmailService::sendTemplateEmail(
        recipientEmail: $customer['email'],
        templateKey: 'monthly_newsletter',
        variables: ['name' => $customer['name']],
        userID: null,  // System email - no user auth needed
        priority: 7,   // Lower priority (bulk)
        sendAsEmail: 'newsletter@company.com'  // Shared mailbox
    );
}
```

**Authentication Used**: Application
**Cost**: $0 (shared mailbox)
**Volume**: High-volume emails without license costs

---

## 🔐 Permissions Required

### For Application Auth (Shared Mailboxes)

#### Azure AD Permissions:
- ✅ `Mail.Send` (application permission)
- ✅ `Mail.Send.Shared` (application permission)
- ✅ Admin consent granted

#### Exchange Permissions:
```powershell
# Grant "Send As" permission for each shared mailbox
Add-RecipientPermission -Identity "support@company.com" `
    -Trustee "noreply@company.com" `
    -AccessRights SendAs `
    -Confirm:$false
```

### For Delegated Auth (User Mailboxes)

#### Azure AD Permissions:
- ✅ `Mail.Send` (delegated permission)
- ✅ `offline_access` (for refresh tokens)
- ✅ User consent (per user)

#### No Exchange Permissions Needed:
- ❌ No "Send As" required
- ✅ User sends from their own mailbox
- ✅ OAuth token authorizes the user

---

## 🌐 Google Workspace: Shared Mailboxes

### Google Workspace Equivalent: Group Email Addresses

Google Workspace doesn't have "shared mailboxes" per se, but you can use:

1. **Google Groups** (with email capability)
2. **Email aliases** (on service account)
3. **Collaborative Inboxes**

### Creating a Group Email Address

1. **Sign in** to [Google Admin Console](https://admin.google.com)
2. **Navigate** to **Groups**
3. **Click** "Create group"
4. **Enter details**:
   - Name: `Support Team`
   - Email: `support@company.com`
   - Group type: `Email list` or `Collaborative inbox`
5. **Grant access** to service account for sending

### Using with SIGNula

```php
// Send from Google Group address (no additional license needed)
EmailService::sendTemplateEmail(
    recipientEmail: 'customer@example.com',
    templateKey: 'support_reply',
    variables: ['ticket' => '12345'],
    userID: null,  // Uses service account
    priority: 5,
    sendAsEmail: 'support@company.com'  // Google Group
);
```

**Note**: Service account must have domain-wide delegation configured.

---

## 📊 Decision Matrix

### When to Use Each Auth Mode

| Scenario | Recommended Mode | Mailbox Type | License Cost |
|----------|------------------|--------------|--------------|
| System emails (verification, reset) | Application | Shared | $0 |
| Support/Sales team emails | Application | Shared | $0 |
| High-volume notifications | Application | Shared | $0 |
| User sending from own mailbox | Delegated (or Auto) | User | License cost |
| Personal correspondence | Delegated | User | License cost |
| Mixed (system + user emails) | **Auto** (recommended) | Mixed | Varies |

---

## 🛠️ Setup Checklist

### For Shared Mailboxes (Application Auth)

- [ ] Create shared mailboxes in Microsoft 365
- [ ] Configure Azure AD app with `Mail.Send.Shared` permission
- [ ] Grant admin consent
- [ ] Configure Exchange "Send As" permissions
- [ ] Set auth mode to `application` or `auto`
- [ ] Test sending from shared mailbox
- [ ] Monitor `tblActivityLog` for auth mode used

### For User Mailboxes (Delegated Auth)

- [ ] Configure Azure AD app with delegated `Mail.Send` permission
- [ ] Set redirect URI for OAuth callback
- [ ] Implement OAuth authorization flow (Phase 3)
- [ ] Create user consent UI
- [ ] Set auth mode to `delegated` or `auto`
- [ ] Test user authorization flow
- [ ] Verify token refresh mechanism

---

## 🔍 Monitoring & Logging

### Check Which Auth Mode Was Used

```sql
-- View recent email sends with auth context
SELECT
    emailID,
    recipientEmail,
    sendAsEmail,
    provider,
    metadata
FROM tblEmailQueue
WHERE provider = 'microsoft_graph'
  AND sentAt > DATE_SUB(NOW(), INTERVAL 1 DAY)
ORDER BY sentAt DESC;
```

The `metadata` field contains:
```json
{
  "authMode": "application",
  "useUserAuth": false,
  "sendAsEmail": "support@company.com",
  "context": "application credentials (mailbox: support@company.com)"
}
```

### Activity Log Entries

```sql
-- Check auth mode distribution
SELECT
    JSON_EXTRACT(metadata, '$.authMode') as authMode,
    COUNT(*) as emailCount
FROM tblActivityLog
WHERE activityType = 'EMAIL_SENT'
  AND activityTimestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY authMode;
```

---

## 💡 Best Practices

### 1. Use Shared Mailboxes for System Emails
✅ **DO**: Use unlicensed shared mailboxes for:
- Transactional emails (confirmations, resets)
- System notifications
- Department/team addresses

❌ **DON'T**: Waste user licenses on system mailboxes

### 2. Set Auth Mode to AUTO
✅ **DO**: Use `auto` mode for flexibility
- Tries user OAuth when available
- Falls back to application auth seamlessly
- Best of both worlds

❌ **DON'T**: Force `delegated` mode unless required

### 3. Name Shared Mailboxes Appropriately
✅ **GOOD** naming:
- `noreply@company.com`
- `support@company.com`
- `notifications@company.com`

❌ **BAD** naming:
- `john.shared@company.com` (confusing)
- `license-saver@company.com` (unprofessional)

### 4. Monitor Storage on Free Tier
✅ **DO**: Keep shared mailboxes under 50GB
- Auto-delete old sent items after 90 days
- Use email archiving if needed

❌ **DON'T**: Let shared mailboxes exceed 50GB without a license

### 5. Document Your Mailbox Strategy
✅ **DO**: Maintain a list of:
- Which mailboxes are shared vs. user
- Purpose of each mailbox
- "Send As" permissions granted
- Expected email volume

---

## 🆘 Troubleshooting

### Error: "Access Denied" with Shared Mailbox

**Cause**: Missing "Send As" permission

**Solution**:
```powershell
Add-RecipientPermission -Identity "shared@company.com" `
    -Trustee "service@company.com" `
    -AccessRights SendAs `
    -Confirm:$false
```

### Shared Mailbox Storage Full

**Symptoms**: Emails bouncing, storage warnings

**Solutions**:
1. **Delete old emails** (auto-deletion policy)
2. **Enable archiving** (requires license)
3. **Add Exchange Online Plan 1** ($4/month)

### User Auth Falls Back to Application Auth

**Behavior**: Expected in auto mode when user hasn't authorized

**Check**:
```sql
-- Check if user has OAuth token
SELECT * FROM tblUserOAuthTokens
WHERE userID = 123 AND provider = 'microsoft_graph';
```

**Solution**: User needs to authorize via OAuth consent flow (Phase 3)

---

## 📚 Related Documentation

- [DELEGATE_MAILBOX_ARCHITECTURE.md](DELEGATE_MAILBOX_ARCHITECTURE.md) - Complete architecture
- [MICROSOFT_DELEGATE_MAILBOX_SETUP.md](MICROSOFT_DELEGATE_MAILBOX_SETUP.md) - Microsoft 365 setup
- [DELEGATE_MAILBOX_IMPLEMENTATION_STATUS.md](DELEGATE_MAILBOX_IMPLEMENTATION_STATUS.md) - Implementation status

---

## 📞 Support

For questions about shared mailboxes or auth modes:
- Check `tblActivityLog` for auth mode used
- Review `metadata` field in `tblEmailQueue`
- Verify Exchange "Send As" permissions
- Test with a single email first

---

**Recommendation**: Use **AUTO mode** with **shared mailboxes** for system emails to maximize cost savings while maintaining flexibility for user-level auth when needed!

---

*Generated: 2026-02-03 by Claude Code*
