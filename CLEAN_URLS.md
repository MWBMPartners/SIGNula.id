# 🔗 Clean URLs Guide for SIGNula

## Overview

SIGNula uses **clean URLs** to hide `.php` file extensions from users. This provides a professional appearance and improves security by obscuring the technology stack.

## How It Works

### Before (With Extensions)
```
https://signula.id/dashboard.php
https://signula.id/login.php
https://signula.id/organization/members.php
```

### After (Clean URLs)
```
https://signula.id/dashboard
https://signula.id/login
https://signula.id/organization/members
```

## Implementation

Clean URLs are implemented using Apache's `mod_rewrite` module via the `.htaccess` file located in `/public_html/.htaccess`.

### Key Features

1. **Extension Removal**: Automatically appends `.php` to URLs when the file exists
2. **Trailing Slash Handling**: Removes trailing slashes for consistency
3. **Directory Protection**: Blocks access to sensitive directories (`_config`, `_includes`, `_private`, `_sql`, `_logs`)
4. **Security Headers**: Adds modern security headers (X-Frame-Options, CSP, etc.)
5. **Performance**: Enables compression and browser caching
6. **Error Pages**: Custom 403, 404, and 500 error pages

## URL Mapping Examples

| Clean URL | Actual File |
|-----------|-------------|
| `/dashboard` | `/dashboard.php` |
| `/login` | `/login.php` |
| `/register` | `/register.php` |
| `/account` | `/account.php` |
| `/security` | `/security.php` |
| `/organization/dashboard` | `/organization/dashboard.php` |
| `/organization/members` | `/organization/members.php` |
| `/organization/domains` | `/organization/domains.php` |

## Writing Clean URLs in Code

### ✅ Correct Way

```php
// In HTML/PHP files
<a href="/dashboard">Dashboard</a>
<a href="/organization/members">Team Members</a>

// In PHP redirects
redirect('/login');
redirect('/organization/dashboard');

// In forms
<form method="POST" action="/account">
```

### ❌ Incorrect Way

```php
// Don't include .php extension
<a href="/dashboard.php">Dashboard</a>
<a href="/organization/members.php">Team Members</a>

redirect('/login.php');
```

## Static Assets

Static assets (CSS, JS, images) are **not affected** by URL rewriting:

```html
<!-- These work as-is -->
<link rel="stylesheet" href="/assets/css/main.css">
<script src="/assets/js/main.js"></script>
<img src="/assets/images/logo.png">
```

## Testing Clean URLs

### 1. Test Basic Pages

```bash
curl -I https://yourdomain.com/dashboard
# Should return 200 OK

curl -I https://yourdomain.com/dashboard.php
# Should still work but ideally redirect to clean URL
```

### 2. Test Protected Directories

```bash
curl -I https://yourdomain.com/_config/
# Should return 403 Forbidden

curl -I https://yourdomain.com/_includes/
# Should return 403 Forbidden
```

### 3. Test Error Pages

```bash
curl -I https://yourdomain.com/nonexistent
# Should return 404 and show custom error page
```

## Enabling HTTPS (Production)

In `.htaccess`, uncomment these lines to force HTTPS:

```apache
# Uncomment for production
RewriteCond %{HTTPS} off
RewriteCond %{HTTP:X-Forwarded-Proto} !https
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
```

## WWW vs Non-WWW

Choose one redirect strategy in `.htaccess`:

### Option 1: Redirect www to non-www
```apache
RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]
RewriteRule ^(.*)$ https://%1/$1 [R=301,L]
```

### Option 2: Redirect non-www to www
```apache
RewriteCond %{HTTP_HOST} !^www\. [NC]
RewriteCond %{HTTP_HOST} !^localhost [NC]
RewriteRule ^(.*)$ https://www.%{HTTP_HOST}/$1 [R=301,L]
```

## Troubleshooting

### URLs Not Working

1. **Check mod_rewrite is enabled**
   ```bash
   # On Apache, check if module is loaded
   apache2ctl -M | grep rewrite
   ```

2. **Check .htaccess is being read**
   - Ensure `AllowOverride All` is set in Apache configuration
   - Check file permissions (644 is typical)

3. **Check for syntax errors**
   ```bash
   # Test Apache configuration
   apache2ctl configtest
   ```

### 404 Errors for Existing Pages

1. Verify the PHP file actually exists
2. Check file permissions (644 for files, 755 for directories)
3. Look at Apache error logs: `/var/log/apache2/error.log`

### Assets Not Loading

1. Verify asset paths start with `/`
2. Check file extensions are in the exclusion list in `.htaccess`
3. Clear browser cache

## Security Benefits

### 1. **Technology Obscurity**
- Hides PHP usage from potential attackers
- Reduces targeted PHP-specific attacks
- Makes fingerprinting more difficult

### 2. **Directory Protection**
- Blocks access to configuration files
- Protects sensitive directories
- Prevents directory listing

### 3. **Modern Security Headers**
- X-Frame-Options: Prevents clickjacking
- X-Content-Type-Options: Prevents MIME sniffing
- X-XSS-Protection: Enables browser XSS protection
- Referrer-Policy: Controls referrer information
- Permissions-Policy: Restricts browser features

### 4. **SQL Injection Prevention**
- Blocks common SQL injection patterns in query strings
- Prevents script tag injection attempts

## Performance Optimizations

### 1. **Compression**
- Gzip compression for text files
- Reduces bandwidth usage
- Faster page loads

### 2. **Browser Caching**
- 1 year cache for images and fonts
- 1 month cache for CSS/JS
- No cache for HTML/PHP (dynamic content)

### 3. **File Size Limits**
- Upload max: 10MB
- Post max: 10MB
- Configurable in .htaccess

## File Structure

```
public_html/
├── .htaccess              # URL rewriting rules
├── error/
│   ├── 403.php           # Access forbidden page
│   ├── 404.php           # Page not found page
│   └── 500.php           # Server error page
├── dashboard.php          # Accessed as /dashboard
├── login.php              # Accessed as /login
├── organization/
│   ├── dashboard.php      # Accessed as /organization/dashboard
│   └── members.php        # Accessed as /organization/members
└── assets/               # Static files (unchanged)
    ├── css/
    ├── js/
    └── images/
```

## Migration Checklist

When updating existing links:

- [ ] Update all `<a href="">` links to remove `.php`
- [ ] Update all `<form action="">` attributes
- [ ] Update all `redirect()` calls in PHP code
- [ ] Update any hardcoded URLs in JavaScript
- [ ] Update URLs in email templates
- [ ] Update sitemap.xml (if exists)
- [ ] Update robots.txt (if exists)
- [ ] Test all navigation paths
- [ ] Test form submissions
- [ ] Test redirects

## Best Practices

1. **Always use clean URLs** in new code
2. **Never hardcode** the `.php` extension in user-facing URLs
3. **Test locally** before deploying to production
4. **Monitor error logs** after deployment
5. **Keep .htaccess** backed up and version controlled
6. **Document any custom** rewrite rules you add
7. **Use relative paths** starting with `/` for internal links
8. **Enable HTTPS** in production environments

## Additional Resources

- [Apache mod_rewrite Documentation](https://httpd.apache.org/docs/current/mod/mod_rewrite.html)
- [htaccess Testing Tools](https://htaccess.madewithlove.com/)
- [Security Headers Best Practices](https://securityheaders.com/)

---

**Last Updated**: 2025-10-23
**Version**: 1.0
