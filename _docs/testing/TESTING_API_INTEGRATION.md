# Testing API Integration -- SIGNula

**Version:** 2.4.0-beta
**Last Updated:** February 18, 2026
**Status:** Active Testing Guide

> Guide for setting up and testing integration with the SIGNula RESTful API v1, including authentication, endpoint testing, webhooks, and code examples.

---

## Overview

| Property | Value |
|----------|-------|
| Base URL | `https://account.signula.id/api/v1` |
| Response Format | JSON (`application/json`) |
| Authentication | Session, Bearer Token, API Key (`X-API-Key` header) |
| Rate Limits | 1000 req/hour (authenticated), 100 req/hour (unauthenticated) |
| API Documentation | [Interactive Swagger UI](https://signula.id/api/docs/swagger/) |
| Markdown Docs | [API_DOCUMENTATION.md](../../web/public_html/api/docs/API_DOCUMENTATION.md) |

### Standard Response Envelope

All API responses follow a consistent structure:

```json
{
  "success": true,
  "message": "Human-readable description",
  "data": { },
  "meta": {
    "timestamp": "2026-02-18T12:00:00Z",
    "version": "v1",
    "request_id": "550e8400-e29b-41d4-a716-446655440000"
  }
}
```

---

## 1. Obtaining API Credentials

### 1.1 Partner Registration

Partners are organizations that integrate with SIGNula's API.

- [ ] Navigate to the Partner registration page or contact the SIGNula admin team
  - **Expected:** Partner registration form or onboarding process available
- [ ] Submit partner application with organization name, contact email, and intended use case
  - **Expected:** Application submitted, pending admin approval
- [ ] Verify partner record created in `tblPartners` with `status = 'pending'`

### 1.2 Admin Approval

- [ ] Login as a Super Admin or Admin user
- [ ] Navigate to Admin panel > Partner Management
- [ ] Approve the pending partner application
  - **Expected:** Partner status changes to `active`
- [ ] Verify partner can now access the Partner Dashboard (`/partners/`)

### 1.3 API Key Generation

- [ ] Login as the partner's Root Admin
- [ ] Navigate to Partner Dashboard > API Keys (`/partners/api-keys.php`)
- [ ] Click "Generate New API Key"
  - **Expected:** API key displayed **once** (e.g., `sk_live_a1b2c3d4e5f6g7h8i9j0...`)
- [ ] Copy the API key immediately (it will not be shown again)
  - **Expected:** Only the prefix and last 4 characters are stored for identification
- [ ] Verify the key is stored as a hash in the database (not plaintext)

```sql
-- Verify API key storage (hashed, not plaintext)
SELECT apiKeyID, keyPrefix, keyLastFour, keyHash, partnerID, status, createdAt
FROM tblAPIKeys
WHERE partnerID = ?;
```

**Negative Tests:**

- [ ] Attempt to view the full API key after generation
  - **Expected:** Only prefix and last 4 characters visible
- [ ] Attempt to generate an API key without partner approval
  - **Expected:** Error or access denied

---

## 2. Authentication Methods

### 2.1 API Key (X-API-Key Header)

Recommended for server-to-server communication.

```bash
# Test API key authentication
curl -X GET https://account.signula.id/api/v1/health \
  -H "X-API-Key: sk_live_your_api_key_here"
```

- [ ] Send request with valid API key in `X-API-Key` header
  - **Expected:** 200 OK with health check response
- [ ] Send request with invalid API key
  - **Expected:** 401 Unauthorized with `"message": "Invalid API key"`
- [ ] Send request with revoked API key
  - **Expected:** 401 Unauthorized with `"message": "API key has been revoked"`
- [ ] Send request with API key as query parameter (`?api_key=...`)
  - **Expected:** 200 OK (less secure, but supported)
- [ ] Send request with no API key and no other auth
  - **Expected:** 401 Unauthorized for protected endpoints; 200 OK for public endpoints (health, info)

### 2.2 Bearer Token

Recommended for user-facing applications.

```bash
# Step 1: Obtain a token via login
curl -X POST https://account.signula.id/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "testuser@example.com",
    "password": "SecurePass123!"
  }'

# Response includes access_token and refresh_token
# {
#   "success": true,
#   "data": {
#     "access_token": "eyJhbGciOiJIUzI1NiIs...",
#     "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
#     "expires_in": 3600,
#     "token_type": "Bearer"
#   }
# }

# Step 2: Use the token in subsequent requests
curl -X GET https://account.signula.id/api/v1/user/profile \
  -H "Authorization: Bearer eyJhbGciOiJIUzI1NiIs..."
```

- [ ] Login via API and obtain access token and refresh token
  - **Expected:** Both tokens returned with `expires_in` value
- [ ] Use access token in `Authorization: Bearer` header for a protected endpoint
  - **Expected:** 200 OK with user data
- [ ] Use access token after it has expired (past `expires_in`)
  - **Expected:** 401 Unauthorized with `"message": "Token expired"`
- [ ] Send request with malformed Bearer token
  - **Expected:** 401 Unauthorized with `"message": "Invalid token"`
- [ ] Send request with `Authorization: Bearer` but empty token
  - **Expected:** 401 Unauthorized

### 2.3 Session-Based

For browser-based integrations (uses HTTP-only cookies).

```javascript
// JavaScript fetch with credentials (cookies)
const response = await fetch('https://account.signula.id/api/v1/user/profile', {
  credentials: 'include'
});
const data = await response.json();
```

- [ ] Login via the web form (creates session cookie)
- [ ] Make API request with `credentials: 'include'` (or equivalent)
  - **Expected:** 200 OK, session cookie sent automatically
- [ ] Make API request without cookies
  - **Expected:** 401 Unauthorized

---

## 3. First API Call

### 3.1 Health Check (GET /api/v1/health)

This is a public endpoint that does not require authentication.

```bash
curl -s https://account.signula.id/api/v1/health | python3 -m json.tool
```

- [ ] Send GET request to `/api/v1/health`
  - **Expected:** 200 OK response
- [ ] Verify response body structure:
  ```json
  {
    "success": true,
    "message": "API is operational",
    "data": {
      "status": "healthy",
      "timestamp": "2026-02-18T12:00:00Z",
      "version": "v1"
    },
    "meta": {
      "timestamp": "2026-02-18T12:00:00Z",
      "version": "v1",
      "request_id": "..."
    }
  }
  ```
- [ ] Verify `Content-Type: application/json` header in response
- [ ] Verify `X-RateLimit-Limit` header present
- [ ] Verify `X-RateLimit-Remaining` header present
- [ ] Verify `X-RateLimit-Reset` header present

### 3.2 API Info (GET /api/v1/info)

```bash
curl -s https://account.signula.id/api/v1/info | python3 -m json.tool
```

- [ ] Send GET request to `/api/v1/info`
  - **Expected:** 200 OK with API name, version, documentation URL
- [ ] Verify response includes available endpoint count
- [ ] Verify documentation link is correct and reachable

---

## 4. Authentication Endpoints

### 4.1 Register (POST /api/v1/auth/register)

```bash
curl -X POST https://account.signula.id/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "username": "apitestuser",
    "email": "apitest@example.com",
    "password": "SecurePass123!",
    "first_name": "API",
    "last_name": "Tester",
    "accept_terms": true
  }'
```

- [ ] Register with all valid fields
  - **Expected:** 201 Created, `user_id` returned, verification email sent
- [ ] Register with missing required fields
  - **Expected:** 422 Unprocessable Entity with `errors` object detailing each missing field
- [ ] Register with invalid email format
  - **Expected:** 422 with `"email": ["Please enter a valid email address"]`
- [ ] Register with weak password
  - **Expected:** 422 with password strength error
- [ ] Register with duplicate email
  - **Expected:** 422 with `"email": ["An account with this email already exists"]`
- [ ] Register without `accept_terms: true`
  - **Expected:** 422 with terms acceptance error
- [ ] Register with `Content-Type: text/plain` (wrong content type)
  - **Expected:** 400 Bad Request or 415 Unsupported Media Type

### 4.2 Login (POST /api/v1/auth/login)

```bash
curl -X POST https://account.signula.id/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "apitest@example.com",
    "password": "SecurePass123!"
  }'
```

- [ ] Login with correct credentials (verified account, no MFA)
  - **Expected:** 200 OK with `access_token`, `refresh_token`, `expires_in`, `token_type`
- [ ] Login with incorrect password
  - **Expected:** 401 with `"message": "Invalid credentials"`
- [ ] Login with non-existent email
  - **Expected:** 401 with same generic `"Invalid credentials"` message
- [ ] Login with unverified email
  - **Expected:** 403 with `"message": "Email not verified"`, error code `email_not_verified`
- [ ] Login with MFA-enabled account
  - **Expected:** 202 Accepted with `mfa_token`, `mfa_methods`, `expires_in`
- [ ] Login with locked account (too many failed attempts)
  - **Expected:** 403 with `"message": "Account locked"`, error code `account_locked`
- [ ] Login with `remember_me: true`
  - **Expected:** Extended token lifetime
- [ ] Verify activity logged for both successful and failed login attempts

### 4.3 Logout (POST /api/v1/auth/logout)

```bash
curl -X POST https://account.signula.id/api/v1/auth/logout \
  -H "Authorization: Bearer {access_token}" \
  -H "Content-Type: application/json" \
  -d '{"all_devices": false}'
```

- [ ] Logout with valid token (`all_devices: false`)
  - **Expected:** 200 OK, current session invalidated
- [ ] Use the invalidated token for a subsequent request
  - **Expected:** 401 Unauthorized
- [ ] Logout with `all_devices: true`
  - **Expected:** 200 OK, all sessions for this user invalidated
- [ ] Logout without authentication
  - **Expected:** 401 Unauthorized

### 4.4 Token Refresh (POST /api/v1/auth/refresh)

```bash
curl -X POST https://account.signula.id/api/v1/auth/refresh \
  -H "Content-Type: application/json" \
  -d '{
    "refresh_token": "eyJhbGciOiJIUzI1NiIs..."
  }'
```

- [ ] Refresh with valid refresh token
  - **Expected:** 200 OK with new `access_token` and updated `expires_in`
- [ ] Refresh with expired refresh token
  - **Expected:** 401 Unauthorized
- [ ] Refresh with invalid/malformed token
  - **Expected:** 401 Unauthorized
- [ ] Refresh after logging out (refresh token should be invalidated)
  - **Expected:** 401 Unauthorized

---

## 5. User Management

### 5.1 Get Profile (GET /api/v1/user/profile)

```bash
curl -X GET https://account.signula.id/api/v1/user/profile \
  -H "Authorization: Bearer {access_token}"
```

- [ ] Get profile with valid token
  - **Expected:** 200 OK with user data (user_id, username, email, display_name, etc.)
- [ ] Verify sensitive fields are NOT returned (password hash, MFA secret)
- [ ] Get profile without authentication
  - **Expected:** 401 Unauthorized
- [ ] Verify all expected fields present in response

### 5.2 Update Profile (PUT /api/v1/user/profile)

```bash
curl -X PUT https://account.signula.id/api/v1/user/profile \
  -H "Authorization: Bearer {access_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "display_name": "Updated Name",
    "timezone": "Europe/London"
  }'
```

- [ ] Update display name
  - **Expected:** 200 OK, name updated
- [ ] Update timezone
  - **Expected:** 200 OK, timezone updated
- [ ] Send update with invalid timezone value
  - **Expected:** 422 validation error
- [ ] Send update with empty body
  - **Expected:** 400 or 422 (nothing to update)
- [ ] Verify `updated_at` timestamp changes

### 5.3 Sessions & Activity

```bash
# List sessions
curl -X GET https://account.signula.id/api/v1/user/sessions \
  -H "Authorization: Bearer {access_token}"

# Get activity log
curl -X GET "https://account.signula.id/api/v1/user/activity?page=1&limit=10&type=login" \
  -H "Authorization: Bearer {access_token}"

# Terminate a session
curl -X DELETE https://account.signula.id/api/v1/user/session/ses_xyz789 \
  -H "Authorization: Bearer {access_token}"
```

- [ ] List active sessions
  - **Expected:** Array of session objects with device info, IP, timestamps
- [ ] Verify current session is marked as `"current": true`
- [ ] Terminate a non-current session
  - **Expected:** Session removed, 200 OK
- [ ] Attempt to terminate the current session via this endpoint
  - **Expected:** Error or logged out (implementation-dependent)
- [ ] Get activity with default pagination
  - **Expected:** 20 most recent activities
- [ ] Get activity with `type` filter
  - **Expected:** Only matching activity types returned
- [ ] Get activity with `from` and `to` date filters
  - **Expected:** Only activities within date range returned
- [ ] Get activity with `page=2&limit=5`
  - **Expected:** Second page of 5 results, pagination metadata correct

---

## 6. MFA via API

### 6.1 MFA Setup

```bash
# Get MFA setup info
curl -X GET https://account.signula.id/api/v1/mfa/setup \
  -H "Authorization: Bearer {access_token}"

# Enable MFA
curl -X POST https://account.signula.id/api/v1/mfa/enable \
  -H "Authorization: Bearer {access_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "method": "totp",
    "password": "SecurePass123!"
  }'
```

- [ ] Get MFA setup information
  - **Expected:** Available methods, setup instructions, current MFA status
- [ ] Enable MFA via API with valid password
  - **Expected:** 200 OK with `secret`, `qr_code` (base64), `backup_codes`
- [ ] Enable MFA without providing password
  - **Expected:** 401 or 422 error
- [ ] Enable MFA when already enabled
  - **Expected:** Error "MFA is already enabled"

### 6.2 MFA Verification During Login

```bash
# After receiving mfa_token from login:
curl -X POST https://account.signula.id/api/v1/mfa/verify \
  -H "Content-Type: application/json" \
  -d '{
    "mfa_token": "mfa_temp_token_xyz",
    "mfa_code": "123456",
    "method": "totp"
  }'
```

- [ ] Verify with correct TOTP code
  - **Expected:** 200 OK with `access_token` and `refresh_token`
- [ ] Verify with incorrect code
  - **Expected:** 401 with `"message": "Invalid MFA code"`
- [ ] Verify with expired `mfa_token` (after 5 minutes)
  - **Expected:** 401 with `"message": "MFA token expired"`
- [ ] Verify with backup code
  - **Expected:** 200 OK, backup code marked as used

### 6.3 Backup Codes via API

```bash
# Get backup codes
curl -X GET https://account.signula.id/api/v1/mfa/backup-codes \
  -H "Authorization: Bearer {access_token}"

# Regenerate backup codes
curl -X POST https://account.signula.id/api/v1/mfa/backup-codes/regenerate \
  -H "Authorization: Bearer {access_token}" \
  -H "Content-Type: application/json" \
  -d '{"password": "SecurePass123!"}'
```

- [ ] Get backup codes status (remaining, total, used status)
  - **Expected:** List of codes with `used` boolean and `used_at` timestamps
- [ ] Regenerate backup codes with valid password
  - **Expected:** New set of codes, previous codes invalidated
- [ ] Regenerate without password
  - **Expected:** 401 or 422 error

---

## 7. OAuth Linking via API

```bash
# List available providers
curl -X GET https://account.signula.id/api/v1/oauth/providers

# List user's linked accounts
curl -X GET https://account.signula.id/api/v1/oauth/linked \
  -H "Authorization: Bearer {access_token}"

# Initiate linking
curl -X POST https://account.signula.id/api/v1/oauth/link \
  -H "Authorization: Bearer {access_token}" \
  -H "Content-Type: application/json" \
  -d '{"provider": "google", "redirect_uri": "https://yourapp.com/oauth/callback"}'

# Unlink a provider
curl -X DELETE https://account.signula.id/api/v1/oauth/unlink/google \
  -H "Authorization: Bearer {access_token}"

# Set primary provider
curl -X POST https://account.signula.id/api/v1/oauth/set-primary \
  -H "Authorization: Bearer {access_token}" \
  -H "Content-Type: application/json" \
  -d '{"provider": "google"}'
```

- [ ] List available OAuth providers (public endpoint)
  - **Expected:** Array of provider objects with name, enabled status, capabilities
- [ ] List linked accounts for authenticated user
  - **Expected:** Array of linked accounts with provider, email, linked date
- [ ] Initiate OAuth linking flow
  - **Expected:** `authorization_url` and `state` token returned
- [ ] Unlink a provider
  - **Expected:** 200 OK, account unlinked
- [ ] Unlink the last authentication method
  - **Expected:** Error "Cannot unlink your only authentication method"
- [ ] Set primary provider
  - **Expected:** 200 OK, provider set as primary

---

## 8. Webhook Setup

### 8.1 Registering Webhook Endpoints

```bash
# Register a webhook (via partner dashboard or API)
curl -X POST https://account.signula.id/api/v1/webhooks \
  -H "X-API-Key: sk_live_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://yourapp.com/webhooks/signula",
    "events": ["user.created", "user.updated", "session.created"],
    "secret": "your_webhook_secret"
  }'
```

- [ ] Register a webhook endpoint with valid URL and events
  - **Expected:** Webhook created, webhook ID returned
- [ ] Register with invalid URL (not HTTPS)
  - **Expected:** Error "Webhook URL must use HTTPS"
- [ ] Register with unsupported event types
  - **Expected:** Error listing valid event types
- [ ] List registered webhooks
  - **Expected:** Array of webhook configurations

### 8.2 Webhook Event Types

| Event | Trigger |
|-------|---------|
| `user.created` | New user registration |
| `user.updated` | Profile update |
| `user.deleted` | Account deletion |
| `user.email.verified` | Email verification completed |
| `user.mfa.enabled` | MFA enabled |
| `user.mfa.disabled` | MFA disabled |
| `oauth.linked` | Third-party account linked |
| `oauth.unlinked` | Third-party account unlinked |
| `session.created` | User login (new session) |
| `session.ended` | User logout (session terminated) |

- [ ] Trigger each event type and verify webhook is sent
  - **Expected:** POST request sent to webhook URL with event payload
- [ ] Verify payload structure matches documentation:

```json
{
  "event": "user.created",
  "timestamp": "2026-02-18T12:00:00Z",
  "data": {
    "user_id": "usr_abc123",
    "email": "john@example.com",
    "created_at": "2026-02-18T12:00:00Z"
  },
  "signature": "sha256=abc123..."
}
```

### 8.3 Signature Verification

```php
<?php
/**
 * Verify SIGNula webhook signature
 * @see https://signula.id/api/docs/#webhooks
 */
$payload = file_get_contents('php://input');
$receivedSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$webhookSecret = 'your_webhook_secret';

// Calculate expected signature using HMAC-SHA256
$expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $webhookSecret);

// Use timing-safe comparison to prevent timing attacks
if (hash_equals($expectedSignature, $receivedSignature)) {
    // Webhook is valid -- process the event
    $event = json_decode($payload, true);
    // ... handle event
    http_response_code(200);
} else {
    // Invalid signature -- reject the webhook
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
}
?>
```

- [ ] Verify the `X-Signature` header is present on all webhook deliveries
- [ ] Verify the HMAC-SHA256 signature matches the payload
- [ ] Verify modified payload results in signature mismatch
- [ ] Verify wrong secret results in signature mismatch

### 8.4 Retry Logic

- [ ] Return HTTP 500 from webhook endpoint
  - **Expected:** SIGNula retries delivery (check retry schedule)
- [ ] Return HTTP 200 from webhook endpoint
  - **Expected:** No retry, delivery marked as successful
- [ ] Return HTTP 410 (Gone) from webhook endpoint
  - **Expected:** Webhook automatically disabled
- [ ] Verify retry intervals (e.g., 1 min, 5 min, 30 min, 1 hour)
- [ ] Verify maximum retry count (e.g., 5 attempts)

---

## 9. Rate Limiting & Errors

### 9.1 Rate Limit Headers

Every API response includes rate limit information:

```http
HTTP/1.1 200 OK
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 987
X-RateLimit-Reset: 1708300800
```

- [ ] Verify `X-RateLimit-Limit` reflects the correct tier limit
- [ ] Verify `X-RateLimit-Remaining` decrements with each request
- [ ] Verify `X-RateLimit-Reset` is a valid Unix timestamp
- [ ] Verify rate limit resets at the specified time

### 9.2 Error Response Format

All errors follow the standard envelope:

```json
{
  "success": false,
  "message": "Human-readable error description",
  "errors": {
    "field_name": ["Field-specific error message"]
  },
  "meta": {
    "timestamp": "2026-02-18T12:00:00Z",
    "version": "v1",
    "request_id": "550e8400-e29b-41d4-a716-446655440000"
  }
}
```

- [ ] Verify 400 errors include descriptive `message`
- [ ] Verify 422 errors include field-level errors in `errors` object
- [ ] Verify `request_id` is always present (useful for support tickets)
- [ ] Verify error responses do not expose stack traces or internal paths

### 9.3 Common Error Codes

Test each error scenario:

- [ ] `invalid_credentials` -- wrong email/password at login
  - **Expected:** 401 status
- [ ] `email_not_verified` -- login with unverified account
  - **Expected:** 403 status
- [ ] `account_locked` -- login after too many failures
  - **Expected:** 403 status
- [ ] `mfa_required` -- login when MFA is enabled
  - **Expected:** 202 status
- [ ] `invalid_mfa_code` -- wrong TOTP code
  - **Expected:** 401 status
- [ ] `invalid_token` -- expired or malformed bearer token
  - **Expected:** 401 status
- [ ] `rate_limit_exceeded` -- too many requests
  - **Expected:** 429 status with `retry_after` in response body
- [ ] `resource_not_found` -- invalid resource ID
  - **Expected:** 404 status
- [ ] `validation_error` -- invalid input data
  - **Expected:** 422 status with field-level errors
- [ ] `insufficient_permissions` -- accessing admin endpoint as regular user
  - **Expected:** 403 status

### 9.4 HTTP Method Handling

- [ ] Send GET to a POST-only endpoint
  - **Expected:** 405 Method Not Allowed
- [ ] Send DELETE to a GET-only endpoint
  - **Expected:** 405 Method Not Allowed
- [ ] Send OPTIONS to any endpoint (CORS preflight)
  - **Expected:** 200 OK with appropriate CORS headers

---

## 10. Code Examples

### 10.1 PHP

```php
<?php
/**
 * SIGNula API Integration Example -- PHP
 *
 * Demonstrates authentication, profile retrieval, and error handling.
 * Uses cURL (no external dependencies required).
 *
 * @see https://signula.id/api/docs/ -- Full API documentation
 */

// -- Configuration --
$apiBaseUrl = 'https://account.signula.id/api/v1';
$apiKey = 'sk_live_your_api_key_here';  // Store in environment variable in production

/**
 * Make an authenticated API request to SIGNula
 *
 * @param string $method    HTTP method (GET, POST, PUT, DELETE)
 * @param string $endpoint  API endpoint path (e.g., '/user/profile')
 * @param array  $data      Request body data (for POST/PUT)
 * @param string $token     Bearer token (optional, uses API key if omitted)
 * @return array            Decoded JSON response
 */
function signulaRequest(string $method, string $endpoint, array $data = [], string $token = ''): array
{
    global $apiBaseUrl, $apiKey;

    $ch = curl_init();

    // Build the full URL
    $url = $apiBaseUrl . $endpoint;

    // Set common cURL options
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    // Set headers
    $headers = ['Content-Type: application/json'];

    if (!empty($token)) {
        $headers[] = 'Authorization: Bearer ' . $token;
    } else {
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Set method and body
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if ($httpCode >= 400) {
        throw new Exception('API Error (' . $httpCode . '): ' . ($decoded['message'] ?? 'Unknown error'));
    }

    return $decoded;
}

// -- Example Usage --

// 1. Login and obtain token
$loginResponse = signulaRequest('POST', '/auth/login', [
    'email'    => 'user@example.com',
    'password' => 'SecurePass123!',
]);
$accessToken = $loginResponse['data']['access_token'];

// 2. Get user profile
$profile = signulaRequest('GET', '/user/profile', [], $accessToken);
echo 'Hello, ' . $profile['data']['display_name'] . PHP_EOL;

// 3. Update profile
$updated = signulaRequest('PUT', '/user/profile', [
    'display_name' => 'New Display Name',
], $accessToken);
echo $updated['message'] . PHP_EOL;
```

### 10.2 JavaScript/Node.js

```javascript
/**
 * SIGNula API Integration Example -- JavaScript/Node.js
 *
 * Uses the Fetch API (built into Node.js 18+ and all modern browsers).
 *
 * @see https://signula.id/api/docs/ -- Full API documentation
 */

const API_BASE_URL = 'https://account.signula.id/api/v1';
const API_KEY = process.env.SIGNULA_API_KEY || 'sk_live_your_api_key_here';

/**
 * Make an authenticated request to the SIGNula API
 * @param {string} method - HTTP method
 * @param {string} endpoint - API endpoint path
 * @param {object} [data] - Request body
 * @param {string} [token] - Bearer token (optional)
 * @returns {Promise<object>} Parsed JSON response
 */
async function signulaRequest(method, endpoint, data = null, token = null) {
  const headers = {
    'Content-Type': 'application/json',
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  } else {
    headers['X-API-Key'] = API_KEY;
  }

  const options = { method, headers };

  if (data && (method === 'POST' || method === 'PUT')) {
    options.body = JSON.stringify(data);
  }

  const response = await fetch(`${API_BASE_URL}${endpoint}`, options);
  const json = await response.json();

  if (!response.ok) {
    throw new Error(`API Error (${response.status}): ${json.message || 'Unknown error'}`);
  }

  return json;
}

// -- Example Usage --
async function main() {
  try {
    // 1. Health check
    const health = await signulaRequest('GET', '/health');
    console.log('API Status:', health.data.status);

    // 2. Login
    const login = await signulaRequest('POST', '/auth/login', {
      email: 'user@example.com',
      password: 'SecurePass123!',
    });
    const token = login.data.access_token;

    // 3. Get profile
    const profile = await signulaRequest('GET', '/user/profile', null, token);
    console.log('User:', profile.data.display_name);

    // 4. Logout
    await signulaRequest('POST', '/auth/logout', {}, token);
    console.log('Logged out successfully');
  } catch (error) {
    console.error('Error:', error.message);
  }
}

main();
```

### 10.3 Python

```python
"""
SIGNula API Integration Example -- Python

Uses the 'requests' library (pip install requests).

@see https://signula.id/api/docs/ -- Full API documentation
"""

import os
import requests

API_BASE_URL = "https://account.signula.id/api/v1"
API_KEY = os.environ.get("SIGNULA_API_KEY", "sk_live_your_api_key_here")


def signula_request(method: str, endpoint: str, data: dict = None, token: str = None) -> dict:
    """
    Make an authenticated request to the SIGNula API.

    Args:
        method:   HTTP method (GET, POST, PUT, DELETE)
        endpoint: API endpoint path (e.g., '/user/profile')
        data:     Request body data (for POST/PUT)
        token:    Bearer token (optional, uses API key if omitted)

    Returns:
        Parsed JSON response as a dictionary

    Raises:
        requests.HTTPError: If the API returns an error status code
    """
    url = f"{API_BASE_URL}{endpoint}"

    headers = {"Content-Type": "application/json"}

    if token:
        headers["Authorization"] = f"Bearer {token}"
    else:
        headers["X-API-Key"] = API_KEY

    response = requests.request(
        method=method,
        url=url,
        json=data,
        headers=headers,
        timeout=30,
    )

    response.raise_for_status()
    return response.json()


# -- Example Usage --
if __name__ == "__main__":
    # 1. Health check
    health = signula_request("GET", "/health")
    print(f"API Status: {health['data']['status']}")

    # 2. Login
    login = signula_request("POST", "/auth/login", {
        "email": "user@example.com",
        "password": "SecurePass123!",
    })
    access_token = login["data"]["access_token"]

    # 3. Get profile
    profile = signula_request("GET", "/user/profile", token=access_token)
    print(f"User: {profile['data']['display_name']}")

    # 4. List sessions
    sessions = signula_request("GET", "/user/sessions", token=access_token)
    print(f"Active sessions: {sessions['data']['total']}")

    # 5. Logout
    signula_request("POST", "/auth/logout", {"all_devices": False}, token=access_token)
    print("Logged out successfully")
```

### 10.4 cURL

```bash
#!/usr/bin/env bash
# ============================================================
# SIGNula API Integration Examples -- cURL
#
# Quick reference for testing SIGNula API endpoints from the
# command line using cURL.
#
# @see https://signula.id/api/docs/ -- Full API documentation
# ============================================================

API_BASE="https://account.signula.id/api/v1"
API_KEY="sk_live_your_api_key_here"

# ---- Health Check (Public, no auth required) ----
echo "=== Health Check ==="
curl -s "${API_BASE}/health" | python3 -m json.tool

# ---- Register a new user ----
echo "=== Register ==="
curl -s -X POST "${API_BASE}/auth/register" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "curltest",
    "email": "curltest@example.com",
    "password": "SecurePass123!",
    "first_name": "cURL",
    "last_name": "Tester",
    "accept_terms": true
  }' | python3 -m json.tool

# ---- Login and capture token ----
echo "=== Login ==="
LOGIN_RESPONSE=$(curl -s -X POST "${API_BASE}/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "curltest@example.com",
    "password": "SecurePass123!"
  }')
echo "${LOGIN_RESPONSE}" | python3 -m json.tool

# Extract access token (requires jq)
TOKEN=$(echo "${LOGIN_RESPONSE}" | jq -r '.data.access_token')
echo "Token: ${TOKEN:0:20}..."

# ---- Get Profile (Bearer Token auth) ----
echo "=== Profile ==="
curl -s -X GET "${API_BASE}/user/profile" \
  -H "Authorization: Bearer ${TOKEN}" | python3 -m json.tool

# ---- Get Profile (API Key auth) ----
echo "=== Profile via API Key ==="
curl -s -X GET "${API_BASE}/user/profile" \
  -H "X-API-Key: ${API_KEY}" | python3 -m json.tool

# ---- List Sessions ----
echo "=== Sessions ==="
curl -s -X GET "${API_BASE}/user/sessions" \
  -H "Authorization: Bearer ${TOKEN}" | python3 -m json.tool

# ---- Activity Log (with filters) ----
echo "=== Activity ==="
curl -s -X GET "${API_BASE}/user/activity?page=1&limit=5&type=login" \
  -H "Authorization: Bearer ${TOKEN}" | python3 -m json.tool

# ---- Logout ----
echo "=== Logout ==="
curl -s -X POST "${API_BASE}/auth/logout" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"all_devices": false}' | python3 -m json.tool
```

---

## 11. Production Checklist

Before going live with your SIGNula API integration, verify the following:

### Security

- [ ] API keys are stored in environment variables or secure vault (not in source code)
- [ ] All API communication uses HTTPS (never HTTP)
- [ ] Bearer tokens are stored securely (not in localStorage for web apps -- use httpOnly cookies)
- [ ] Webhook signatures are verified using HMAC-SHA256 with timing-safe comparison
- [ ] Error messages from the API are not exposed directly to end users
- [ ] API keys are rotated on a regular schedule (e.g., every 90 days)
- [ ] Revoked/expired keys are handled gracefully with appropriate error messages

### Reliability

- [ ] Token refresh logic is implemented (refresh before access token expires)
- [ ] Retry logic with exponential backoff for transient errors (5xx, network errors)
- [ ] Rate limit handling -- respect `X-RateLimit-Remaining` and `Retry-After` headers
- [ ] Timeout handling -- set reasonable timeouts (e.g., 10-30 seconds)
- [ ] Circuit breaker pattern for API dependencies (fail gracefully if SIGNula is down)

### Monitoring

- [ ] Log all API errors with `request_id` for troubleshooting with SIGNula support
- [ ] Monitor rate limit usage (`X-RateLimit-Remaining`)
- [ ] Alert on authentication failures (invalid API key, expired tokens)
- [ ] Track webhook delivery success/failure rates
- [ ] Monitor token refresh frequency

### Testing

- [ ] All endpoints tested with valid input (happy path)
- [ ] All endpoints tested with invalid input (error paths)
- [ ] All endpoints tested without authentication (expect 401)
- [ ] Rate limiting tested and handled
- [ ] Webhook delivery and signature verification tested
- [ ] Token refresh flow tested end-to-end
- [ ] MFA flow tested end-to-end (if applicable)
- [ ] OAuth linking flow tested end-to-end (if applicable)
- [ ] Staging/test environment used before production deployment

### Documentation

- [ ] Integration documentation written for your development team
- [ ] API key management procedures documented
- [ ] Incident response plan includes SIGNula API failure scenarios
- [ ] Contact information for SIGNula support: `api@signula.id`

---

## Test Execution Tracking

| Section | Total Tests | Passed | Failed | Blocked | Notes |
|---------|-------------|--------|--------|---------|-------|
| 1. Credentials | 6 | -- | -- | -- | |
| 2. Auth Methods | 14 | -- | -- | -- | |
| 3. First API Call | 8 | -- | -- | -- | |
| 4. Auth Endpoints | 22 | -- | -- | -- | |
| 5. User Management | 14 | -- | -- | -- | |
| 6. MFA via API | 10 | -- | -- | -- | |
| 7. OAuth via API | 8 | -- | -- | -- | |
| 8. Webhooks | 12 | -- | -- | -- | |
| 9. Rate Limits & Errors | 18 | -- | -- | -- | |
| 10. Code Examples | -- | -- | -- | -- | Reference only |
| 11. Production Checklist | 20 | -- | -- | -- | |
| **Total** | **132** | **--** | **--** | **--** | |

---

## Related Documentation

- [TESTING_LOCAL_ACCOUNTS.md](TESTING_LOCAL_ACCOUNTS.md) -- Local account tests (registration, login, MFA)
- [TESTING_THIRD_PARTY_LINKING.md](TESTING_THIRD_PARTY_LINKING.md) -- OAuth provider linking tests
- [../TESTING_GUIDE_COMPREHENSIVE.md](../TESTING_GUIDE_COMPREHENSIVE.md) -- Overall testing guide
- [../SECURITY_TESTING_GUIDE.md](../SECURITY_TESTING_GUIDE.md) -- Security-specific testing
- [../../web/public_html/api/docs/API_DOCUMENTATION.md](../../web/public_html/api/docs/API_DOCUMENTATION.md) -- Full API reference
- [../../web/public_html/api/docs/swagger/](../../web/public_html/api/docs/swagger/) -- Interactive Swagger UI

---

**Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
