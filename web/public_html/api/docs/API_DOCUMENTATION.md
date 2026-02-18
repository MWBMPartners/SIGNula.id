# 🚀 SIGNula API Documentation

**Version**: 1.0.0
**Base URL**: `https://signulo.id/api/v1`
**Last Updated**: 2026-02-18

> **Interactive Docs:** Prefer an interactive, try-it-out experience? See the [Swagger UI](swagger/) (OpenAPI 3.0).

---

## 📋 Table of Contents

1. [Introduction](#introduction)
2. [Getting Started](#getting-started)
3. [Authentication](#authentication)
4. [Rate Limiting](#rate-limiting)
5. [Error Handling](#error-handling)
6. [Endpoints](#endpoints)
   - [Authentication](#authentication-endpoints)
   - [User Management](#user-management-endpoints)
   - [Multi-Factor Authentication](#mfa-endpoints)
   - [OAuth Account Linking](#oauth-endpoints)
   - [Utility](#utility-endpoints)
7. [Webhooks](#webhooks)
8. [SDKs & Libraries](#sdks--libraries)
9. [Support](#support)

---

## 🎯 Introduction

Welcome to the SIGNula API documentation. SIGNula provides a comprehensive RESTful API for integrating universal authentication, user management, and account services into your applications.

### Key Features

- 🔐 **Universal Authentication** - OAuth 2.0, social login, passwordless auth
- 🛡️ **Multi-Factor Authentication** - TOTP, SMS, email, push notifications
- 👤 **User Management** - Profiles, preferences, activity tracking
- 🔗 **Account Linking** - Connect Google, Microsoft, Apple, and more
- 🔑 **PassKey Support** - WebAuthn/FIDO2 biometric authentication
- 📧 **Email Services** - Templated emails, tracking, campaigns
- 🌍 **Multi-Tenant** - Support for organizations and teams

---

## 🚀 Getting Started

### 1. Create an Account

Sign up for a SIGNula account at [https://signulo.id/register](https://signulo.id/register)

### 2. Generate API Credentials

Navigate to your dashboard and generate an API key:

1. Go to **Settings** > **API Keys**
2. Click **Generate New API Key**
3. Copy your API key (shown only once!)
4. Store securely in your environment variables

### 3. Make Your First Request

```bash
curl https://signulo.id/api/v1/health \
  -H "X-API-Key: your_api_key_here"
```

**Response**:
```json
{
  "success": true,
  "message": "API is operational",
  "data": {
    "status": "healthy",
    "timestamp": "2026-02-03T12:00:00Z",
    "version": "v1"
  },
  "meta": {
    "timestamp": "2026-02-03T12:00:00Z",
    "version": "v1",
    "request_id": "550e8400-e29b-41d4-a716-446655440000"
  }
}
```

---

## 🔐 Authentication

SIGNula API supports three authentication methods:

### 1. API Key Authentication (Recommended for Server-to-Server)

Include your API key in the `X-API-Key` header:

```http
GET /api/v1/user/profile HTTP/1.1
Host: signulo.id
X-API-Key: sk_live_abc123xyz789
Content-Type: application/json
```

Or as a query parameter (less secure):
```http
GET /api/v1/user/profile?api_key=sk_live_abc123xyz789
```

### 2. Bearer Token Authentication (Recommended for User Sessions)

Use OAuth 2.0 bearer tokens obtained via login:

```http
POST /api/v1/auth/login HTTP/1.1
Host: signulo.id
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "SecurePass123!"
}
```

**Response**:
```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "expires_in": 3600,
    "token_type": "Bearer"
  }
}
```

Use the access token in subsequent requests:

```http
GET /api/v1/user/profile HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Content-Type: application/json
```

### 3. Session-Based Authentication (Web Applications)

For browser-based applications, SIGNula uses secure HTTP-only cookies.

```javascript
// Login creates a session cookie automatically
fetch('https://signulo.id/api/v1/auth/login', {
  method: 'POST',
  credentials: 'include', // Include cookies
  headers: {
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    email: 'user@example.com',
    password: 'SecurePass123!'
  })
});

// Subsequent requests automatically include the session cookie
fetch('https://signulo.id/api/v1/user/profile', {
  credentials: 'include'
});
```

---

## ⏱️ Rate Limiting

To ensure fair usage and system stability, API requests are rate-limited:

| Authentication Type | Rate Limit |
|---------------------|------------|
| Authenticated (API Key/Token) | 1000 requests/hour |
| Unauthenticated | 100 requests/hour |
| Sensitive Endpoints (login, register) | 10 requests/minute |

### Rate Limit Headers

Every API response includes rate limit information:

```http
HTTP/1.1 200 OK
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 987
X-RateLimit-Reset: 1643990400
```

### Exceeding Rate Limits

When you exceed the rate limit, you'll receive a `429 Too Many Requests` response:

```json
{
  "success": false,
  "message": "Rate limit exceeded",
  "data": {
    "retry_after": 3600
  },
  "meta": {
    "timestamp": "2026-02-03T12:00:00Z",
    "version": "v1",
    "request_id": "550e8400-e29b-41d4-a716-446655440000"
  }
}
```

---

## ❌ Error Handling

### Standard Error Response

All errors follow a consistent format:

```json
{
  "success": false,
  "message": "Human-readable error message",
  "errors": {
    "field_name": [
      "Field-specific error message"
    ]
  },
  "meta": {
    "timestamp": "2026-02-03T12:00:00Z",
    "version": "v1",
    "request_id": "550e8400-e29b-41d4-a716-446655440000"
  }
}
```

### HTTP Status Codes

| Code | Status | Description |
|------|--------|-------------|
| 200 | OK | Request successful |
| 201 | Created | Resource created successfully |
| 204 | No Content | Request successful, no response body |
| 400 | Bad Request | Invalid request format or parameters |
| 401 | Unauthorized | Authentication required or invalid credentials |
| 403 | Forbidden | Authenticated but not authorized |
| 404 | Not Found | Resource not found |
| 422 | Unprocessable Entity | Validation failed |
| 429 | Too Many Requests | Rate limit exceeded |
| 500 | Internal Server Error | Server error (contact support) |
| 503 | Service Unavailable | API temporarily unavailable (maintenance) |

### Common Error Codes

| Error Code | Description | Resolution |
|------------|-------------|------------|
| `invalid_credentials` | Email/password incorrect | Check credentials |
| `email_not_verified` | Email verification required | Verify email first |
| `account_suspended` | Account suspended | Contact support |
| `account_locked` | Too many failed login attempts | Wait or reset password |
| `mfa_required` | MFA verification needed | Complete MFA challenge |
| `invalid_mfa_code` | MFA code incorrect or expired | Try again with new code |
| `invalid_token` | Access token expired or invalid | Refresh token |
| `insufficient_permissions` | Missing required permissions | Upgrade account or check scopes |
| `resource_not_found` | Requested resource doesn't exist | Check resource ID |
| `validation_error` | Input validation failed | Check `errors` field for details |
| `rate_limit_exceeded` | Too many requests | Wait for rate limit reset |

---

## 📚 Endpoints

## Authentication Endpoints

### POST /auth/register

Register a new user account.

**Request**:
```http
POST /api/v1/auth/register HTTP/1.1
Host: signulo.id
Content-Type: application/json

{
  "username": "johndoe",
  "email": "john@example.com",
  "password": "SecurePass123!",
  "first_name": "John",
  "last_name": "Doe",
  "accept_terms": true
}
```

**Response** (201 Created):
```json
{
  "success": true,
  "message": "Account created successfully. Please verify your email.",
  "data": {
    "user_id": "usr_abc123",
    "username": "johndoe",
    "email": "john@example.com",
    "email_verified": false,
    "created_at": "2026-02-03T12:00:00Z"
  }
}
```

**Validation Rules**:
- `username`: Required, 3-30 characters, alphanumeric + underscore
- `email`: Required, valid email format, unique
- `password`: Required, minimum 8 characters, must include uppercase, lowercase, number
- `first_name`: Optional, max 100 characters
- `last_name`: Optional, max 100 characters
- `accept_terms`: Required, must be `true`

---

### POST /auth/login

Authenticate a user and obtain access tokens.

**Request**:
```http
POST /api/v1/auth/login HTTP/1.1
Host: signulo.id
Content-Type: application/json

{
  "email": "john@example.com",
  "password": "SecurePass123!",
  "remember_me": false
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "expires_in": 3600,
    "token_type": "Bearer",
    "user": {
      "user_id": "usr_abc123",
      "username": "johndoe",
      "email": "john@example.com",
      "email_verified": true,
      "mfa_enabled": false
    }
  }
}
```

**MFA Required** (202 Accepted):
```json
{
  "success": false,
  "message": "MFA verification required",
  "data": {
    "mfa_token": "mfa_temp_token_xyz",
    "mfa_methods": ["totp", "email", "sms"],
    "expires_in": 300
  }
}
```

---

### POST /auth/logout

Logout user and invalidate tokens.

**Request**:
```http
POST /api/v1/auth/logout HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Content-Type: application/json

{
  "all_devices": false
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

---

### POST /auth/refresh

Refresh access token using refresh token.

**Request**:
```http
POST /api/v1/auth/refresh HTTP/1.1
Host: signulo.id
Content-Type: application/json

{
  "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "expires_in": 3600,
    "token_type": "Bearer"
  }
}
```

---

### POST /auth/verify-email

Verify user email address.

**Request** (GET or POST):
```http
GET /api/v1/auth/verify-email?token=abc123xyz789 HTTP/1.1
Host: signulo.id
```

Or:

```http
POST /api/v1/auth/verify-email HTTP/1.1
Host: signulo.id
Content-Type: application/json

{
  "token": "abc123xyz789"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Email verified successfully",
  "data": {
    "email_verified": true,
    "verified_at": "2026-02-03T12:00:00Z"
  }
}
```

---

### POST /auth/forgot-password

Request password reset email.

**Request**:
```http
POST /api/v1/auth/forgot-password HTTP/1.1
Host: signulo.id
Content-Type: application/json

{
  "email": "john@example.com"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Password reset email sent. Please check your inbox."
}
```

**Note**: For security, this always returns success even if email doesn't exist.

---

### POST /auth/reset-password

Reset password using reset token.

**Request**:
```http
POST /api/v1/auth/reset-password HTTP/1.1
Host: signulo.id
Content-Type: application/json

{
  "token": "reset_token_xyz",
  "password": "NewSecurePass123!",
  "password_confirmation": "NewSecurePass123!"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Password reset successfully. You can now login with your new password."
}
```

---

## User Management Endpoints

### GET /user/profile

Get current user's profile.

**Request**:
```http
GET /api/v1/user/profile HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "user_id": "usr_abc123",
    "username": "johndoe",
    "email": "john@example.com",
    "email_verified": true,
    "first_name": "John",
    "last_name": "Doe",
    "display_name": "John Doe",
    "profile_picture": "https://signulo.id/avatars/usr_abc123.jpg",
    "phone_number": "+1234567890",
    "phone_verified": true,
    "account_status": "active",
    "account_tier": "premium",
    "mfa_enabled": true,
    "locale": "en_US",
    "timezone": "America/New_York",
    "created_at": "2026-01-01T00:00:00Z",
    "updated_at": "2026-02-03T12:00:00Z",
    "last_login_at": "2026-02-03T11:00:00Z"
  }
}
```

---

### PUT /user/profile

Update user profile.

**Request**:
```http
PUT /api/v1/user/profile HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Content-Type: application/json

{
  "first_name": "John",
  "last_name": "Doe",
  "display_name": "Johnny",
  "phone_number": "+1234567890",
  "locale": "en_US",
  "timezone": "America/New_York"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Profile updated successfully",
  "data": {
    "user_id": "usr_abc123",
    "first_name": "John",
    "last_name": "Doe",
    "display_name": "Johnny",
    "updated_at": "2026-02-03T12:00:00Z"
  }
}
```

---

### GET /user/sessions

List active sessions.

**Request**:
```http
GET /api/v1/user/sessions HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "sessions": [
      {
        "session_id": "ses_abc123",
        "device_type": "desktop",
        "browser": "Chrome 120",
        "os": "Windows 11",
        "ip_address": "192.168.1.100",
        "location": "New York, US",
        "current": true,
        "created_at": "2026-02-03T10:00:00Z",
        "last_activity": "2026-02-03T12:00:00Z"
      },
      {
        "session_id": "ses_xyz789",
        "device_type": "mobile",
        "browser": "Safari 17",
        "os": "iOS 17",
        "ip_address": "192.168.1.101",
        "location": "New York, US",
        "current": false,
        "created_at": "2026-02-01T08:00:00Z",
        "last_activity": "2026-02-02T22:00:00Z"
      }
    ],
    "total": 2
  }
}
```

---

### DELETE /user/session/{id}

Delete a specific session (logout from device).

**Request**:
```http
DELETE /api/v1/user/session/ses_xyz789 HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Session terminated successfully"
}
```

---

### GET /user/activity

Get user activity log.

**Request**:
```http
GET /api/v1/user/activity?page=1&limit=20&type=login HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

**Query Parameters**:
- `page`: Page number (default: 1)
- `limit`: Results per page (default: 20, max: 100)
- `type`: Filter by activity type (optional)
- `from`: Start date (ISO 8601) (optional)
- `to`: End date (ISO 8601) (optional)

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "activities": [
      {
        "activity_id": "act_abc123",
        "type": "login",
        "result": "success",
        "details": "Logged in from Chrome on Windows",
        "ip_address": "192.168.1.100",
        "user_agent": "Mozilla/5.0...",
        "location": "New York, US",
        "timestamp": "2026-02-03T12:00:00Z"
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 20,
      "total": 150,
      "pages": 8
    }
  }
}
```

---

### GET /user/preferences

Get user preferences.

**Request**:
```http
GET /api/v1/user/preferences HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "email_notifications": true,
    "sms_notifications": false,
    "push_notifications": true,
    "marketing_emails": false,
    "theme": "dark",
    "language": "en",
    "date_format": "MM/DD/YYYY",
    "time_format": "12h"
  }
}
```

---

### PUT /user/preferences

Update user preferences.

**Request**:
```http
PUT /api/v1/user/preferences HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Content-Type: application/json

{
  "email_notifications": true,
  "theme": "dark",
  "language": "en"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Preferences updated successfully"
}
```

---

### POST /user/change-password

Change user password.

**Request**:
```http
POST /api/v1/user/change-password HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Content-Type: application/json

{
  "current_password": "OldPass123!",
  "new_password": "NewSecurePass456!",
  "new_password_confirmation": "NewSecurePass456!"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Password changed successfully"
}
```

---

### POST /user/change-email

Change user email address.

**Request**:
```http
POST /api/v1/user/change-email HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Content-Type: application/json

{
  "new_email": "newemail@example.com",
  "password": "CurrentPass123!"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Verification email sent to newemail@example.com. Please verify to complete email change."
}
```

---

## MFA Endpoints

### POST /mfa/enable

Enable multi-factor authentication.

**Request**:
```http
POST /api/v1/mfa/enable HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Content-Type: application/json

{
  "method": "totp",
  "password": "CurrentPass123!"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "MFA enabled successfully",
  "data": {
    "method": "totp",
    "secret": "JBSWY3DPEHPK3PXP",
    "qr_code": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...",
    "backup_codes": [
      "12345-67890",
      "23456-78901",
      "34567-89012",
      "45678-90123",
      "56789-01234"
    ]
  }
}
```

---

### POST /mfa/disable

Disable multi-factor authentication.

**Request**:
```http
POST /api/v1/mfa/disable HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Content-Type: application/json

{
  "password": "CurrentPass123!",
  "mfa_code": "123456"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "MFA disabled successfully"
}
```

---

### POST /mfa/verify

Verify MFA code during login.

**Request**:
```http
POST /api/v1/mfa/verify HTTP/1.1
Host: signulo.id
Content-Type: application/json

{
  "mfa_token": "mfa_temp_token_xyz",
  "mfa_code": "123456",
  "method": "totp",
  "remember_device": false
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "MFA verification successful",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "expires_in": 3600,
    "token_type": "Bearer"
  }
}
```

---

### GET /mfa/setup

Get MFA setup information.

**Request**:
```http
GET /api/v1/mfa/setup HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "mfa_enabled": false,
    "available_methods": ["totp", "email", "sms"],
    "setup_instructions": {
      "totp": {
        "step_1": "Install an authenticator app (Google Authenticator, Microsoft Authenticator, etc.)",
        "step_2": "Scan the QR code or enter the secret manually",
        "step_3": "Enter the 6-digit code from your app to verify"
      }
    }
  }
}
```

---

### GET /mfa/backup-codes

Get MFA backup codes.

**Request**:
```http
GET /api/v1/mfa/backup-codes HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "backup_codes": [
      {
        "code": "12345-67890",
        "used": false
      },
      {
        "code": "23456-78901",
        "used": true,
        "used_at": "2026-02-01T10:00:00Z"
      }
    ],
    "remaining": 4,
    "total": 5
  }
}
```

---

### POST /mfa/backup-codes/regenerate

Regenerate MFA backup codes.

**Request**:
```http
POST /api/v1/mfa/backup-codes/regenerate HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Content-Type: application/json

{
  "password": "CurrentPass123!"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Backup codes regenerated successfully. Previous codes are now invalid.",
  "data": {
    "backup_codes": [
      "12345-67890",
      "23456-78901",
      "34567-89012",
      "45678-90123",
      "56789-01234"
    ]
  }
}
```

---

## OAuth Endpoints

### GET /oauth/providers

List available OAuth providers.

**Request**:
```http
GET /api/v1/oauth/providers HTTP/1.1
Host: signulo.id
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "providers": [
      {
        "provider": "google",
        "name": "Google",
        "enabled": true,
        "supports_email": true,
        "supports_profile": true
      },
      {
        "provider": "microsoft",
        "name": "Microsoft",
        "enabled": true,
        "supports_email": true,
        "supports_profile": true
      },
      {
        "provider": "apple",
        "name": "Apple",
        "enabled": true,
        "supports_email": true,
        "supports_profile": true
      }
    ]
  }
}
```

---

### GET /oauth/linked

Get linked OAuth accounts.

**Request**:
```http
GET /api/v1/oauth/linked HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "linked_accounts": [
      {
        "provider": "google",
        "provider_user_id": "google_12345",
        "email": "john@gmail.com",
        "email_verified": true,
        "is_primary": true,
        "linked_at": "2026-01-01T00:00:00Z"
      },
      {
        "provider": "microsoft",
        "provider_user_id": "microsoft_67890",
        "email": "john@outlook.com",
        "email_verified": true,
        "is_primary": false,
        "linked_at": "2026-02-01T00:00:00Z"
      }
    ],
    "total": 2
  }
}
```

---

### POST /oauth/link

Link OAuth account (initiate OAuth flow).

**Request**:
```http
POST /api/v1/oauth/link HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Content-Type: application/json

{
  "provider": "google",
  "redirect_uri": "https://yourapp.com/oauth/callback"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "authorization_url": "https://accounts.google.com/o/oauth2/v2/auth?client_id=...&redirect_uri=...&state=...",
    "state": "random_state_token"
  }
}
```

---

### DELETE /oauth/unlink/{provider}

Unlink OAuth account.

**Request**:
```http
DELETE /api/v1/oauth/unlink/google HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Google account unlinked successfully"
}
```

---

### POST /oauth/set-primary

Set primary OAuth account.

**Request**:
```http
POST /api/v1/oauth/set-primary HTTP/1.1
Host: signulo.id
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Content-Type: application/json

{
  "provider": "google"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Google account set as primary"
}
```

---

## Utility Endpoints

### GET /health

API health check.

**Request**:
```http
GET /api/v1/health HTTP/1.1
Host: signulo.id
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "API is operational",
  "data": {
    "status": "healthy",
    "timestamp": "2026-02-03T12:00:00Z",
    "version": "v1"
  }
}
```

---

### GET /info

API information.

**Request**:
```http
GET /api/v1/info HTTP/1.1
Host: signulo.id
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "name": "SIGNula API",
    "version": "v1",
    "documentation": "https://signulo.id/api/docs",
    "status": "active"
  }
}
```

---

## 🪝 Webhooks

SIGNula can send webhooks to your application when specific events occur.

### Configuring Webhooks

1. Go to **Settings** > **Webhooks**
2. Add your webhook URL
3. Select events to receive
4. Save and test

### Webhook Events

| Event | Description |
|-------|-------------|
| `user.created` | New user registered |
| `user.updated` | User profile updated |
| `user.deleted` | User account deleted |
| `user.email.verified` | Email verified |
| `user.mfa.enabled` | MFA enabled |
| `user.mfa.disabled` | MFA disabled |
| `oauth.linked` | OAuth account linked |
| `oauth.unlinked` | OAuth account unlinked |
| `session.created` | New session created (login) |
| `session.ended` | Session ended (logout) |

### Webhook Payload

```json
{
  "event": "user.created",
  "timestamp": "2026-02-03T12:00:00Z",
  "data": {
    "user_id": "usr_abc123",
    "email": "john@example.com",
    "created_at": "2026-02-03T12:00:00Z"
  },
  "signature": "sha256=abc123..."
}
```

### Verifying Webhooks

All webhooks include an HMAC-SHA256 signature in the `X-Signature` header:

```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE'];
$secret = 'your_webhook_secret';

$expected_signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (hash_equals($expected_signature, $signature)) {
    // Webhook is valid
} else {
    // Invalid signature
}
```

---

## 📚 SDKs & Libraries

### Official SDKs

- **PHP SDK**: `composer require signula/signula-php`
- **JavaScript SDK**: `npm install @signula/signula-js`
- **Python SDK**: `pip install signula`
- **Ruby SDK**: `gem install signula`

### Quick Start with PHP SDK

```php
<?php
require 'vendor/autoload.php';

use Signula\Client;

$client = new Client('your_api_key');

// Register user
$user = $client->auth->register([
    'email' => 'john@example.com',
    'password' => 'SecurePass123!',
    'username' => 'johndoe'
]);

// Login
$session = $client->auth->login([
    'email' => 'john@example.com',
    'password' => 'SecurePass123!'
]);

// Get profile
$profile = $client->user->getProfile();
```

---

## 🆘 Support

### Resources

- **API Documentation**: [https://signulo.id/api/docs](https://signulo.id/api/docs)
- **Status Page**: [https://status.signulo.id](https://status.signulo.id)
- **Support**: [support@signulo.id](mailto:support@signulo.id)
- **GitHub**: [https://github.com/signula](https://github.com/signula)

### Contact

- **Email**: api@signulo.id
- **Discord**: [https://discord.gg/signula](https://discord.gg/signula)
- **Twitter**: [@SIGNula](https://twitter.com/signula)

---

**Copyright © 2026 SIGNula. All rights reserved.**
