# 📧 Microsoft 365 Delegate Mailbox Sending - Setup Guide

## Overview

This guide explains how to enable **delegate mailbox sending** in SIGNula for Microsoft 365, allowing the email system to send from different mailboxes in your organization.

## Two Approaches

### Approach 1: Application Permissions (Recommended for MVP)
**Best for**: Transactional emails, service accounts, simple setup
- Uses existing client credentials flow
- No per-user OAuth needed
- Requires Exchange mailbox permissions

### Approach 2: Delegated Permissions (Advanced)
**Best for**: User-initiated emails, fine-grained control
- Per-user OAuth consent
- More complex implementation
- Covered in Phase 3

---

## Phase 2: Application Permissions Setup

### Required Azure AD Permissions

You need to add **two application permissions** to your Azure AD app:

1. **Mail.Send** (already configured)
   - Allows sending from the configured service mailbox

2. **Mail.Send.Shared** (NEW)
   - Allows sending from ANY mailbox in the organization
   - Must be granted admin consent

---

## Step-by-Step Configuration

### Step 1: Update Azure AD App Permissions

1. **Sign in to Azure Portal**
   - Go to [https://portal.azure.com](https://portal.azure.com)
   - Navigate to **Azure Active Directory** > **App registrations**

2. **Find Your SIGNula App**
   - Search for "SIGNula Email Service" (or your app name)
   - Click on the app registration

3. **Add Mail.Send.Shared Permission**
   - Go to **API permissions** in the left sidebar
   - Click **Add a permission**
   - Select **Microsoft Graph** > **Application permissions**
   - Search for "Mail.Send.Shared"
   - Check the box next to **Mail.Send.Shared**
   - Click **Add permissions**

4. **Grant Admin Consent**
   - Click the **Grant admin consent for [Your Organization]** button
   - Confirm the action
   - ✅ You should see a green checkmark next to both permissions:
     - `Mail.Send` ✅
     - `Mail.Send.Shared` ✅

---

### Step 2: Configure Exchange Online Mailbox Permissions

The `Mail.Send.Shared` permission allows your app to **attempt** to send from any mailbox, but Exchange Online still enforces "Send As" permissions.

You must **explicitly grant "Send As" permission** for each mailbox you want to send from.

#### Option A: Using Exchange Admin Center (GUI)

1. **Sign in to Exchange Admin Center**
   - Go to [https://admin.exchange.microsoft.com](https://admin.exchange.microsoft.com)

2. **Navigate to Mailboxes**
   - Go to **Recipients** > **Mailboxes**

3. **Configure Delegate Mailbox**
   - Find the mailbox you want to send from (e.g., `sales@company.com`)
   - Click on the mailbox to open settings
   - Go to **Delegation** tab
   - Under **Send As**, click **Edit**
   - Add your service account mailbox (e.g., `noreply@company.com`)
   - Save changes

4. **Repeat for Each Delegate Mailbox**
   - You need to do this for every mailbox you want to send from

#### Option B: Using PowerShell (Recommended for Bulk)

```powershell
# Connect to Exchange Online
Install-Module -Name ExchangeOnlineManagement
Connect-ExchangeOnline -UserPrincipalName admin@company.com

# Grant "Send As" permission
# Syntax: Add-RecipientPermission -Identity <delegate_mailbox> -Trustee <service_mailbox> -AccessRights SendAs -Confirm:$false

# Example: Allow noreply@company.com to send as sales@company.com
Add-RecipientPermission -Identity "sales@company.com" -Trustee "noreply@company.com" -AccessRights SendAs -Confirm:$false

# Example: Allow noreply@company.com to send as support@company.com
Add-RecipientPermission -Identity "support@company.com" -Trustee "noreply@company.com" -AccessRights SendAs -Confirm:$false

# Verify permissions
Get-RecipientPermission -Identity "sales@company.com" | Where-Object {$_.AccessRights -like 'SendAs'}

# Disconnect
Disconnect-ExchangeOnline -Confirm:$false
```

#### Option C: Using Microsoft Graph API (Programmatic)

Unfortunately, Microsoft Graph API does not currently support managing "Send As" permissions. You must use Exchange Admin Center or PowerShell.

---

### Step 3: Test Delegate Sending

Once permissions are configured, test sending from a delegate mailbox:

```php
// Send email from sales mailbox
EmailService::sendTemplateEmail(
    recipientEmail: 'customer@example.com',
    templateKey: 'welcome_email',
    variables: ['name' => 'John Doe'],
    userID: null,
    priority: 5,
    sendAsEmail: 'sales@company.com'  // Delegate mailbox
);
```

The email will:
- ✅ Come from `sales@company.com`
- ✅ Show in the recipient's inbox as from Sales
- ✅ Be sent via Microsoft Graph API
- ✅ Use your organization's SPF/DKIM/DMARC

---

## How It Works

### Architecture

1. **Email Service** receives request with `sendAsEmail` parameter
2. **EmailQueueProcessor** passes `sendAsEmail` to Microsoft Graph provider
3. **Microsoft Graph Provider** uses `/users/{sendAsEmail}/sendMail` endpoint
4. **Azure AD** validates app has `Mail.Send.Shared` permission
5. **Exchange Online** validates service account has "Send As" permission on the mailbox
6. **Email is sent** from the delegate mailbox

### API Endpoint

The Microsoft Graph provider uses this endpoint:

```
POST https://graph.microsoft.com/v1.0/users/{sendAsEmail}/sendMail
```

Where `{sendAsEmail}` is the delegate mailbox address (e.g., `sales@company.com`).

---

## Permissions Matrix

| Scenario | Mail.Send | Mail.Send.Shared | Exchange "Send As" | Result |
|----------|-----------|------------------|-------------------|--------|
| Send from service mailbox | ✅ | ❌ | N/A | ✅ Works |
| Send from delegate mailbox | ✅ | ❌ | ✅ | ❌ Fails (no API permission) |
| Send from delegate mailbox | ✅ | ✅ | ❌ | ❌ Fails (no Exchange permission) |
| Send from delegate mailbox | ✅ | ✅ | ✅ | ✅ Works |

**Summary**: You need **both** `Mail.Send.Shared` (Azure AD) AND "Send As" (Exchange) permissions.

---

## Security Considerations

### Why Two Layers of Permissions?

1. **Azure AD Permission** (`Mail.Send.Shared`)
   - Controls which apps can **attempt** to send from shared mailboxes
   - Organization-wide setting
   - Requires Global Admin consent

2. **Exchange Permission** ("Send As")
   - Controls which accounts can **actually send** from specific mailboxes
   - Mailbox-specific setting
   - Requires Exchange Admin access
   - More granular control

This two-layer approach provides **defense in depth**:
- Even if an app has `Mail.Send.Shared`, it can't send from a mailbox without explicit Exchange permission
- You can revoke access at either layer

### Best Practices

1. **Use a dedicated service account**
   - Create `noreply@company.com` or `service@company.com`
   - Grant "Send As" only to mailboxes that need it
   - Don't use a user's personal mailbox

2. **Audit delegate sending**
   - Enable activity logging (already built into SIGNula)
   - Monitor `tblActivityLog` for delegate sends
   - Set up alerts for unusual patterns

3. **Document which mailboxes are delegated**
   - Keep a list of mailboxes with "Send As" permissions
   - Review regularly
   - Remove when no longer needed

4. **Use groups for bulk permissions** (optional)
   - Create a mail-enabled security group (e.g., "Email Service Delegates")
   - Add mailboxes to the group
   - Grant "Send As" to the group
   - Easier to manage many mailboxes

---

## Troubleshooting

### Error: "Access Denied" or "403 Forbidden"

**Cause**: Missing `Mail.Send.Shared` permission or admin consent not granted

**Solution**:
1. Verify `Mail.Send.Shared` is added in Azure AD app
2. Check admin consent is granted (green checkmark)
3. Wait 5-10 minutes for permissions to propagate

### Error: "ErrorSendAsDenied"

**Cause**: Service account doesn't have "Send As" permission on the mailbox

**Solution**:
```powershell
# Grant permission
Add-RecipientPermission -Identity "delegate@company.com" -Trustee "service@company.com" -AccessRights SendAs -Confirm:$false

# Verify
Get-RecipientPermission -Identity "delegate@company.com" | Where-Object {$_.AccessRights -like 'SendAs'}
```

### Email Shows Wrong Sender

**Cause**: Exchange needs time to process "Send As" permission

**Solution**:
- Wait 15-30 minutes after granting permission
- Clear any cached tokens
- Try again

### "Send As" Permission Not Working

**Cause**: Using "Send on Behalf" instead of "Send As"

**Solution**:
- "Send on Behalf" shows as "Sender on behalf of Delegate"
- Use "Send As" for emails to appear from the delegate mailbox
- Make sure you're using `Add-RecipientPermission` with `SendAs`, not `GrantSendOnBehalfTo`

---

## Differences: Send As vs. Send on Behalf

| Feature | Send As | Send on Behalf |
|---------|---------|----------------|
| Appears from | Delegate mailbox | "Service on behalf of Delegate" |
| Recipient sees | Delegate only | Both service and delegate |
| Best for | Transactional emails, system emails | Personal emails, team mailboxes |
| PowerShell | `Add-RecipientPermission` with `SendAs` | `Set-Mailbox` with `GrantSendOnBehalfTo` |

**SIGNula uses "Send As"** for cleaner, more professional appearance.

---

## Migration from Single Mailbox

If you're currently using a single service mailbox:

1. **No changes needed** - existing functionality still works
2. **Add delegate sending** when you want different sender addresses
3. **Backward compatible** - `sendAsEmail = null` uses default mailbox

Example:

```php
// Before (still works)
EmailService::sendTemplateEmail(
    'customer@example.com',
    'welcome_email',
    ['name' => 'John']
);  // Sends from noreply@company.com

// After (new functionality)
EmailService::sendTemplateEmail(
    'customer@example.com',
    'welcome_email',
    ['name' => 'John'],
    null,
    5,
    'sales@company.com'  // Sends from sales@company.com
);
```

---

## PowerShell Cheat Sheet

```powershell
# ============================================================================
# Connect to Exchange Online
# ============================================================================
Connect-ExchangeOnline -UserPrincipalName admin@company.com

# ============================================================================
# Grant "Send As" Permission
# ============================================================================
Add-RecipientPermission -Identity "delegate@company.com" -Trustee "service@company.com" -AccessRights SendAs -Confirm:$false

# ============================================================================
# Check Existing "Send As" Permissions
# ============================================================================
Get-RecipientPermission -Identity "delegate@company.com" | Where-Object {$_.AccessRights -like 'SendAs'} | Format-Table Trustee, AccessRights

# ============================================================================
# Remove "Send As" Permission
# ============================================================================
Remove-RecipientPermission -Identity "delegate@company.com" -Trustee "service@company.com" -AccessRights SendAs -Confirm:$false

# ============================================================================
# Grant to Multiple Mailboxes (Bulk)
# ============================================================================
$delegateMailboxes = @('sales@company.com', 'support@company.com', 'info@company.com')
$serviceMailbox = 'noreply@company.com'

foreach ($mailbox in $delegateMailboxes) {
    Add-RecipientPermission -Identity $mailbox -Trustee $serviceMailbox -AccessRights SendAs -Confirm:$false
    Write-Host "✅ Granted Send As permission to $mailbox"
}

# ============================================================================
# Audit All "Send As" Permissions in Organization
# ============================================================================
Get-Mailbox -ResultSize Unlimited | ForEach-Object {
    $mailbox = $_.UserPrincipalName
    $permissions = Get-RecipientPermission -Identity $mailbox | Where-Object {$_.AccessRights -like 'SendAs' -and $_.Trustee -ne 'NT AUTHORITY\SELF'}
    if ($permissions) {
        $permissions | Format-Table @{Label='Mailbox'; Expression={$mailbox}}, Trustee, AccessRights
    }
}

# ============================================================================
# Disconnect
# ============================================================================
Disconnect-ExchangeOnline -Confirm:$false
```

---

## References

- [Microsoft Graph Mail.Send.Shared Permission](https://docs.microsoft.com/en-us/graph/permissions-reference#mailsendshared)
- [Exchange Online Send As Permission](https://docs.microsoft.com/en-us/powershell/module/exchange/add-recipientpermission)
- [Microsoft Graph Send Mail API](https://docs.microsoft.com/en-us/graph/api/user-sendmail)
- [Exchange Online PowerShell](https://docs.microsoft.com/en-us/powershell/exchange/exchange-online-powershell)

---

## Next Steps

After completing Phase 2, you can:

1. **Test delegate sending** with different mailboxes
2. **Monitor activity logs** for delegate sends
3. **Document delegate mailboxes** for your team
4. **Plan Phase 3** (delegated permissions) for user-level OAuth

---

## Support

For issues or questions:
- Check `tblActivityLog` for detailed error messages
- Review Azure AD app permissions
- Verify Exchange "Send As" permissions
- Contact your Microsoft 365 admin

---

**Last Updated**: 2026-02-03
**Version**: 2.0.0
**Phase**: 2

---

**Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
