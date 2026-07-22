---
title: "🟢 MEDIUM: Complete API Documentation (OpenAPI/Swagger)"
labels: ["priority: medium", "type: documentation", "status: ready"]
assignees: []
---

## 🎯 Description

Complete comprehensive API documentation using OpenAPI 3.0 specification with interactive Swagger UI.

## 📋 Tasks

### 1. OpenAPI Specification
- [ ] Create `openapi.yaml` (or `openapi.json`)
- [ ] Document API version: 2.0
- [ ] Define base URL: `https://signula.id/api`
- [ ] Document authentication:
  - [ ] API Key (Header: `X-API-Key`)
  - [ ] Bearer Token (Header: `Authorization: Bearer {token}`)
- [ ] Define common error responses (400, 401, 403, 404, 429, 500)

### 2. Authentication Endpoints
- [ ] `POST /api/auth/register` - User registration
- [ ] `POST /api/auth/login` - User login
- [ ] `POST /api/auth/logout` - User logout
- [ ] `POST /api/auth/refresh` - Refresh access token
- [ ] `POST /api/auth/verify-email` - Verify email address
- [ ] `POST /api/auth/reset-password` - Request password reset
- [ ] `POST /api/auth/confirm-reset` - Confirm password reset

### 3. User Endpoints
- [ ] `GET /api/users/me` - Get current user profile
- [ ] `PUT /api/users/me` - Update current user profile
- [ ] `DELETE /api/users/me` - Delete current user account
- [ ] `GET /api/users/me/activity` - Get user activity log
- [ ] `POST /api/users/me/export` - Export user data (GDPR)

### 4. OAuth Endpoints
- [ ] `GET /api/oauth/providers` - List available OAuth providers
- [ ] `GET /api/oauth/{provider}/authorize` - Initiate OAuth flow
- [ ] `GET /api/oauth/{provider}/callback` - OAuth callback handler
- [ ] `POST /api/oauth/{provider}/link` - Link OAuth account
- [ ] `DELETE /api/oauth/{provider}/unlink` - Unlink OAuth account

### 5. MFA Endpoints
- [ ] `POST /api/mfa/totp/setup` - Setup TOTP
- [ ] `POST /api/mfa/totp/verify` - Verify TOTP code
- [ ] `POST /api/mfa/totp/disable` - Disable TOTP
- [ ] `POST /api/mfa/webauthn/register` - Register WebAuthn credential
- [ ] `POST /api/mfa/webauthn/authenticate` - Authenticate with WebAuthn
- [ ] `GET /api/mfa/recovery-keys` - Get recovery keys
- [ ] `POST /api/mfa/recovery-keys/regenerate` - Regenerate recovery keys

### 6. Organization Endpoints
- [ ] `GET /api/organizations` - List user's organizations
- [ ] `POST /api/organizations` - Create organization
- [ ] `GET /api/organizations/{id}` - Get organization details
- [ ] `PUT /api/organizations/{id}` - Update organization
- [ ] `DELETE /api/organizations/{id}` - Delete organization
- [ ] `GET /api/organizations/{id}/members` - List organization members
- [ ] `POST /api/organizations/{id}/members` - Add member
- [ ] `DELETE /api/organizations/{id}/members/{userId}` - Remove member

### 7. API Key Endpoints
- [ ] `GET /api/keys` - List user's API keys
- [ ] `POST /api/keys` - Generate new API key
- [ ] `PUT /api/keys/{id}` - Update API key (rotate)
- [ ] `DELETE /api/keys/{id}` - Delete API key

### 8. Webhook Endpoints
- [ ] `GET /api/webhooks` - List user's webhooks
- [ ] `POST /api/webhooks` - Create webhook
- [ ] `GET /api/webhooks/{id}` - Get webhook details
- [ ] `PUT /api/webhooks/{id}` - Update webhook
- [ ] `DELETE /api/webhooks/{id}` - Delete webhook
- [ ] `GET /api/webhooks/{id}/deliveries` - List webhook deliveries
- [ ] `POST /api/webhooks/{id}/test` - Test webhook

### 9. Payment Endpoints
- [ ] `GET /api/payments` - List user's payments
- [ ] `POST /api/payments/checkout` - Create checkout session
- [ ] `GET /api/payments/{id}` - Get payment details
- [ ] `POST /api/payments/{id}/refund` - Request refund
- [ ] `GET /api/subscriptions` - List subscriptions
- [ ] `POST /api/subscriptions/{id}/cancel` - Cancel subscription

### 10. Admin Endpoints (require admin role)
- [ ] `GET /api/admin/users` - List all users
- [ ] `GET /api/admin/users/{id}` - Get user details
- [ ] `PUT /api/admin/users/{id}` - Update user
- [ ] `DELETE /api/admin/users/{id}` - Delete user
- [ ] `GET /api/admin/audit-log` - Get audit log
- [ ] `GET /api/admin/rate-limits` - Get rate limit rules
- [ ] `GET /api/admin/settings` - Get system settings
- [ ] `PUT /api/admin/settings` - Update system settings

### 11. Swagger UI Setup
- [ ] Install Swagger UI files (`/api/docs/`)
- [ ] Configure Swagger UI to load `openapi.yaml`
- [ ] Add "Try it out" functionality
- [ ] Enable authentication testing
- [ ] Deploy at `https://signula.id/api/docs/`

### 12. Additional Documentation
- [ ] Write integration guides:
  - [ ] Quick start guide
  - [ ] Authentication guide
  - [ ] Webhook integration guide
  - [ ] Error handling guide
  - [ ] Rate limiting guide
- [ ] Add code examples:
  - [ ] PHP
  - [ ] Python
  - [ ] JavaScript/Node.js
  - [ ] cURL
- [ ] Document webhook event types and payloads
- [ ] Document rate limit headers

## ✅ Acceptance Criteria

- [ ] Complete OpenAPI 3.0 specification
- [ ] All endpoints documented
- [ ] Request/response schemas defined
- [ ] Authentication documented
- [ ] Swagger UI deployed and functional
- [ ] Code examples provided
- [ ] Integration guides written
- [ ] API documentation accessible at `/api/docs/`

## ⏱️ Estimated Effort

8-12 hours
