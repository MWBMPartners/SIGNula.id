---
title: "🟢 MEDIUM: Finalize Production .htaccess Configuration"
labels: ["priority: medium", "type: deployment", "status: ready"]
assignees: []
---

## 🎯 Description

Finalize `.htaccess` configuration for production environment with HTTPS enforcement and security headers.

## 📋 Tasks

### 1. Enable HTTPS Redirect
- [ ] Uncomment HTTPS redirect in `.htaccess`:
  ```apache
  # Redirect HTTP to HTTPS (PRODUCTION)
  RewriteCond %{HTTPS} off
  RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
  ```
- [ ] Test HTTP→HTTPS redirect
- [ ] Verify no redirect loops

### 2. Configure WWW Redirect
Choose one strategy:

**Option A: Enforce WWW**
```apache
RewriteCond %{HTTP_HOST} ^signula\.id$ [NC]
RewriteRule ^(.*)$ https://www.signula.id/$1 [L,R=301]
```

**Option B: Enforce Non-WWW** (Recommended)
```apache
RewriteCond %{HTTP_HOST} ^www\.signula\.id$ [NC]
RewriteRule ^(.*)$ https://signula.id/$1 [L,R=301]
```

- [ ] Decide on WWW strategy
- [ ] Add redirect rule
- [ ] Test both `signula.id` and `www.signula.id`

### 3. Finalize Content Security Policy (CSP)

Current CSP is restrictive. Adjust for production needs:
```apache
Header set Content-Security-Policy "default-src 'self'; \
  script-src 'self' https://cdn.jsdelivr.net https://code.jquery.com https://challenges.cloudflare.com; \
  style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; \
  font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; \
  img-src 'self' data: https: blob:; \
  connect-src 'self' https://api.stripe.com https://api.paypal.com; \
  frame-src 'self' https://challenges.cloudflare.com https://www.google.com; \
  object-src 'none'; \
  base-uri 'self'; \
  form-action 'self';"
```

Tasks:
- [ ] Review CSP for all third-party integrations
- [ ] Add domains for:
  - [ ] OAuth providers (Google, Microsoft, Apple, etc.)
  - [ ] Payment providers (Stripe, PayPal, Coinbase)
  - [ ] CDN resources
  - [ ] Analytics (if applicable)
  - [ ] ReCaptcha/Turnstile
- [ ] Test CSP in browser console (check for violations)
- [ ] Use `Content-Security-Policy-Report-Only` first
- [ ] Switch to enforcing mode after validation

### 4. Verify Security Headers

Ensure all security headers present:
- [ ] `X-Frame-Options: DENY`
- [ ] `X-Content-Type-Options: nosniff`
- [ ] `X-XSS-Protection: 1; mode=block`
- [ ] `Referrer-Policy: strict-origin-when-cross-origin`
- [ ] `Permissions-Policy: geolocation=(), microphone=(), camera=()`
- [ ] `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`

### 5. Performance Optimization

- [ ] Verify Gzip compression enabled
- [ ] Verify browser caching headers:
  - Images: 1 year
  - CSS/JS: 1 month
  - HTML: no-cache
- [ ] Test with PageSpeed Insights
- [ ] Test with GTmetrix

### 6. Attack Prevention Rules

Verify attack prevention rules active:
- [ ] SQL injection patterns blocked
- [ ] Script tag injection blocked
- [ ] Path traversal blocked
- [ ] Sensitive directories blocked (`_config/`, `_includes/`, `_private/`, `_logs/`)

### 7. Custom Error Pages

- [ ] Test 404 error page (`/error/404.php`)
- [ ] Test 403 error page (`/error/403.php`)
- [ ] Test 500 error page (`/error/500.php`)
- [ ] Verify error pages styled correctly

## ✅ Acceptance Criteria

- [ ] HTTPS enforced (no HTTP access)
- [ ] WWW redirect strategy implemented
- [ ] CSP configured without violations
- [ ] All security headers present
- [ ] Performance optimizations enabled
- [ ] Attack prevention rules active
- [ ] Custom error pages working
- [ ] SecurityHeaders.com grade: A+
- [ ] SSL Labs grade: A+

## 🔗 Testing Tools

- https://securityheaders.com/
- https://www.ssllabs.com/ssltest/
- https://csp-evaluator.withgoogle.com/
- https://pagespeed.web.dev/

## ⏱️ Estimated Effort

2-3 hours
