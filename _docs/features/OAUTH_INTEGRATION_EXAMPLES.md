# OAuth Integration Examples for Third-Party Services

This document provides examples for third-party services integrating with SIGNula to demonstrate how to handle OAuth accounts, including domain-based requirements.

---

## Table of Contents

- [Overview](#overview)
- [Multiple Accounts Support](#multiple-accounts-support)
- [Domain-Based Requirements](#domain-based-requirements)
- [Example Use Cases](#example-use-cases)
- [PHP Code Examples](#php-code-examples)
- [JavaScript/API Examples](#javascriptapi-examples)

---

## Overview

SIGNula supports linking **multiple OAuth accounts from the same provider** to a single SIGNula account. This enables users to:

- Link both personal and work Google accounts
- Link multiple Microsoft 365 accounts (personal, work, school)
- Separate personal and organizational identities

Third-party services can still require specific account types or email domains for authentication.

---

## Multiple Accounts Support

### Account Types

Each linked OAuth account has an `accountType` field:

- `personal` - Personal accounts (Gmail, Outlook.com, etc.)
- `work` - Work/organizational accounts
- `school` - Educational institution accounts

### Account Attributes

When retrieving linked accounts via API:

```json
{
  "account_id": 123,
  "provider": "microsoft",
  "email": "user@company.com",
  "account_type": "work",
  "email_domain": "company.com",
  "is_primary": false
}
```

---

## Domain-Based Requirements

### Use Case Scenarios

1. **Corporate App**: Requires users to authenticate with company domain emails
2. **Educational Platform**: Requires .edu email addresses
3. **Public Service**: Accepts any OAuth account

---

## Example Use Cases

### Use Case 1: Company Internal App (Domain Restriction)

**Requirement**: Users must authenticate with an email from `@company.com`

**Implementation**:
```php
// Retrieve user's linked OAuth accounts
$accounts = getLinkedOAuthAccounts($userId);

// Filter for company domain
$companyAccounts = array_filter($accounts, function($account) {
    return $account['email_domain'] === 'company.com';
});

if (empty($companyAccounts)) {
    // No company account linked
    showError('You must link your @company.com account to access this service');
    showLinkButton('microsoft'); // Direct them to link Microsoft account
    exit;
}

// Proceed with authentication using company account
$primaryCompanyAccount = $companyAccounts[0];
authenticateWithAccount($primaryCompanyAccount);
```

---

### Use Case 2: Educational Platform (.edu Requirement)

**Requirement**: Users must authenticate with a .edu email address

**Implementation**:
```php
// Retrieve user's linked OAuth accounts
$accounts = getLinkedOAuthAccounts($userId);

// Filter for educational accounts
$eduAccounts = array_filter($accounts, function($account) {
    return $account['account_type'] === 'school' ||
           preg_match('/\\.edu$/i', $account['email_domain']) ||
           preg_match('/\\.ac\\.[a-z]{2}$/i', $account['email_domain']);
});

if (empty($eduAccounts)) {
    showError('You must link a school email account (.edu) to access this platform');
    showLinkOptions(['google', 'microsoft']); // Allow either provider
    exit;
}

// Use the educational account
authenticateWithAccount($eduAccounts[0]);
```

---

### Use Case 3: Multi-Organization User

**Scenario**: User works for multiple companies and needs to switch between them

**Implementation**:
```php
// Retrieve all work accounts
$accounts = getLinkedOAuthAccounts($userId);

$workAccounts = array_filter($accounts, function($account) {
    return $account['account_type'] === 'work';
});

// Show organization selector
echo '<h2>Select Organization</h2>';
foreach ($workAccounts as $account) {
    echo '<button onclick="selectAccount(' . $account['account_id'] . ')">';
    echo htmlspecialchars($account['email']) . ' (' . $account['email_domain'] . ')';
    echo '</button>';
}
```

---

## PHP Code Examples

### Example 1: Get User's OAuth Accounts via API

```php
<?php
/**
 * Retrieve user's linked OAuth accounts from SIGNula API
 *
 * @param string $sessionToken User's session token
 * @return array Array of OAuth accounts
 */
function getLinkedOAuthAccounts(string $sessionToken): array
{
    $apiUrl = 'https://api.signula.id/api/v1/oauth/linked';

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $sessionToken,
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Failed to retrieve OAuth accounts');
    }

    $data = json_decode($response, true);
    return $data['data']['accounts'] ?? [];
}

/**
 * Filter accounts by email domain
 *
 * @param array $accounts Array of OAuth accounts
 * @param string $domain Required email domain (e.g., 'company.com')
 * @return array Filtered accounts
 */
function filterAccountsByDomain(array $accounts, string $domain): array
{
    return array_filter($accounts, function($account) use ($domain) {
        return strcasecmp($account['email_domain'], $domain) === 0;
    });
}

/**
 * Filter accounts by account type
 *
 * @param array $accounts Array of OAuth accounts
 * @param string $type Account type ('personal', 'work', 'school')
 * @return array Filtered accounts
 */
function filterAccountsByType(array $accounts, string $type): array
{
    return array_values(array_filter($accounts, function($account) use ($type) {
        return $account['account_type'] === $type;
    }));
}

/**
 * Require specific domain for access
 *
 * @param string $sessionToken User's session token
 * @param string $requiredDomain Required email domain
 * @return array The matching account
 * @throws Exception If no matching account found
 */
function requireDomainAccount(string $sessionToken, string $requiredDomain): array
{
    $accounts = getLinkedOAuthAccounts($sessionToken);
    $matchingAccounts = filterAccountsByDomain($accounts, $requiredDomain);

    if (empty($matchingAccounts)) {
        throw new Exception("You must link an @{$requiredDomain} account to access this service");
    }

    return reset($matchingAccounts); // Return first matching account
}

// ============================================================================
// Usage Examples
// ============================================================================

// Example 1: Require company domain
try {
    $account = requireDomainAccount($_SESSION['signula_token'], 'acme.com');
    echo "Authenticated as: " . htmlspecialchars($account['email']);
} catch (Exception $e) {
    echo "Access Denied: " . $e->getMessage();
    echo '<a href="https://accounts.signula.id/link-oauth?provider=microsoft&redirect_uri=' .
         urlencode(getCurrentUrl()) . '">Link your @acme.com account</a>';
}

// Example 2: Require educational account
try {
    $accounts = getLinkedOAuthAccounts($_SESSION['signula_token']);
    $eduAccounts = filterAccountsByType($accounts, 'school');

    if (empty($eduAccounts)) {
        throw new Exception('You must link a school email account');
    }

    echo "Authenticated with school account: " . htmlspecialchars($eduAccounts[0]['email']);
} catch (Exception $e) {
    echo "Access Denied: " . $e->getMessage();
}

// Example 3: Allow work accounts only
$accounts = getLinkedOAuthAccounts($_SESSION['signula_token']);
$workAccounts = filterAccountsByType($accounts, 'work');

if (empty($workAccounts)) {
    die('This service requires a work email account. Personal accounts are not permitted.');
}

// Example 4: Check for specific provider + domain
$microsoftWorkAccounts = array_filter($accounts, function($account) {
    return $account['provider'] === 'microsoft' &&
           $account['account_type'] === 'work' &&
           $account['email_domain'] === 'company.com';
});
```

---

## JavaScript/API Examples

### Example 1: Check Domain Requirements Client-Side

```javascript
/**
 * Retrieve linked OAuth accounts from SIGNula API
 *
 * @param {string} sessionToken - User's session token
 * @returns {Promise<Array>} Array of OAuth accounts
 */
async function getLinkedOAuthAccounts(sessionToken) {
    const response = await fetch('https://api.signula.id/api/v1/oauth/linked', {
        method: 'GET',
        headers: {
            'Authorization': `Bearer ${sessionToken}`,
            'Content-Type': 'application/json'
        }
    });

    if (!response.ok) {
        throw new Error('Failed to retrieve OAuth accounts');
    }

    const data = await response.json();
    return data.data.accounts || [];
}

/**
 * Check if user has required domain account
 *
 * @param {string} sessionToken - User's session token
 * @param {string} requiredDomain - Required email domain
 * @returns {Promise<Object|null>} Matching account or null
 */
async function checkDomainRequirement(sessionToken, requiredDomain) {
    const accounts = await getLinkedOAuthAccounts(sessionToken);

    return accounts.find(account =>
        account.email_domain.toLowerCase() === requiredDomain.toLowerCase()
    ) || null;
}

/**
 * Filter accounts by type
 *
 * @param {Array} accounts - Array of OAuth accounts
 * @param {string} type - Account type ('personal', 'work', 'school')
 * @returns {Array} Filtered accounts
 */
function filterByAccountType(accounts, type) {
    return accounts.filter(account => account.account_type === type);
}

// ============================================================================
// Usage Examples
// ============================================================================

// Example 1: Enforce company domain requirement
async function checkAccess() {
    const sessionToken = localStorage.getItem('signula_token');
    const requiredDomain = 'company.com';

    try {
        const account = await checkDomainRequirement(sessionToken, requiredDomain);

        if (!account) {
            showError(`You must link your @${requiredDomain} account to access this service`);
            showLinkButton('microsoft');
            return false;
        }

        console.log(`Authenticated as: ${account.email}`);
        return true;

    } catch (error) {
        console.error('Authentication error:', error);
        return false;
    }
}

// Example 2: Show organization selector for multi-org users
async function showOrganizationSelector() {
    const sessionToken = localStorage.getItem('signula_token');
    const accounts = await getLinkedOAuthAccounts(sessionToken);
    const workAccounts = filterByAccountType(accounts, 'work');

    if (workAccounts.length === 0) {
        alert('No work accounts linked. Please link your work email.');
        return;
    }

    const selector = document.getElementById('org-selector');
    selector.innerHTML = '<h3>Select Organization:</h3>';

    workAccounts.forEach(account => {
        const button = document.createElement('button');
        button.textContent = `${account.email} (${account.email_domain})`;
        button.onclick = () => selectOrganization(account.account_id);
        selector.appendChild(button);
    });
}

// Example 3: Validate educational account
async function validateEducationalAccess() {
    const sessionToken = localStorage.getItem('signula_token');
    const accounts = await getLinkedOAuthAccounts(sessionToken);

    const eduAccounts = accounts.filter(account =>
        account.account_type === 'school' ||
        account.email_domain.endsWith('.edu') ||
        /\.ac\.[a-z]{2}$/i.test(account.email_domain)
    );

    if (eduAccounts.length === 0) {
        document.getElementById('error').textContent =
            'Educational email required. Please link your school account.';
        return false;
    }

    return true;
}
```

---

## API Integration Workflow

### Step 1: User Authentication

1. User authenticates with SIGNula
2. SIGNula returns session token
3. Your service receives the token

### Step 2: Retrieve OAuth Accounts

```bash
curl -X GET "https://api.signula.id/api/v1/oauth/linked" \
  -H "Authorization: Bearer {session_token}"
```

**Response**:
```json
{
  "success": true,
  "data": {
    "accounts": [
      {
        "account_id": 1,
        "provider": "google",
        "email": "personal@gmail.com",
        "account_type": "personal",
        "email_domain": "gmail.com",
        "is_primary": true
      },
      {
        "account_id": 2,
        "provider": "microsoft",
        "email": "work@company.com",
        "account_type": "work",
        "email_domain": "company.com",
        "is_primary": false
      }
    ],
    "total": 2
  }
}
```

### Step 3: Apply Domain/Type Requirements

Filter the accounts based on your service requirements:

- **Domain-based**: Check `email_domain` field
- **Type-based**: Check `account_type` field
- **Provider-based**: Check `provider` field

### Step 4: Handle Missing Requirements

If user doesn't have required account:

1. Show clear error message
2. Provide link to SIGNula account linking page
3. Specify which provider/domain they need to link
4. Include redirect URI to return to your service

**Example redirect URL**:
```
https://accounts.signula.id/link-oauth
  ?provider=microsoft
  &account_type=work
  &required_domain=company.com
  &redirect_uri=https://yourservice.com/auth/callback
```

---

## Best Practices

1. **Clear Communication**: Clearly explain domain/account requirements to users
2. **Helpful Error Messages**: Provide actionable guidance when requirements aren't met
3. **Flexible Matching**: Support multiple ways to identify valid accounts (domain, type, provider)
4. **Security**: Always verify accounts server-side, not just client-side
5. **User Experience**: Cache account information to minimize API calls
6. **Multiple Options**: If possible, support multiple providers for the same domain requirement

---

## Support

For questions or issues with OAuth integration:

- Documentation: https://signula.id/docs/oauth-integration
- API Reference: https://signula.id/docs/api
- Support: support@signula.id

---

## Version History

- **v1.0.0** (2026-02-03): Initial documentation with multi-account support and domain filtering examples
