# Build & Deployment Checklist

This document outlines the steps required before building and deploying SIGNula to production or staging environments.

## Pre-Build Checklist

### Code Quality
- [ ] All code passes PHPStan analysis (`composer analyze`)
- [ ] All code passes PHP_CodeSniffer style checks (`composer check-style`)
- [ ] No `var_dump()`, `print_r()`, or `die()` statements in code
- [ ] No commented-out code blocks (except for documentation purposes)
- [ ] All TODOs have been addressed or documented

### Testing
- [ ] All unit tests pass (`composer test:unit`)
- [ ] All integration tests pass (`composer test:integration`)
- [ ] Code coverage is at least 70% for critical paths
- [ ] Manual testing completed for new features
- [ ] Cross-browser testing completed (Chrome, Firefox, Safari, Edge)
- [ ] Mobile responsive testing completed

### Security
- [ ] All user inputs are sanitized and validated
- [ ] All database queries use prepared statements
- [ ] CSRF tokens implemented on all forms
- [ ] XSS protection in place for all user-generated content
- [ ] SQL injection prevention verified
- [ ] API keys and secrets are NOT hardcoded
- [ ] All sensitive data is encrypted in database
- [ ] Security headers configured (.htaccess)

### Database
- [ ] All migrations have been tested
- [ ] Database schema is up to date
- [ ] Indexes are properly configured for performance
- [ ] Foreign key constraints are in place
- [ ] Backup procedures are documented and tested

### Configuration
- [ ] Environment-specific settings configured
- [ ] Email service credentials configured (if applicable)
- [ ] OAuth provider credentials configured (if applicable)
- [ ] API rate limiting configured
- [ ] Error logging configured
- [ ] Debug mode is DISABLED for production

### Documentation
- [ ] README.md is up to date
- [ ] API documentation is current
- [ ] Inline code comments are present and helpful
- [ ] User-facing documentation updated
- [ ] CHANGELOG.md updated with version changes

## Build Process

### 1. Version Bump
```bash
# Update version in:
# - composer.json
# - README.md
# - Any version display files
```

### 2. Run Quality Checks
```bash
# Run all quality checks
composer quality

# Or individually:
composer check-style  # Code style
composer analyze      # Static analysis
composer test         # All tests
```

### 3. Generate Build Artifacts
```bash
# Create optimized autoloader
composer dump-autoload --optimize --no-dev

# Minify assets (if applicable)
# npm run build
```

### 4. Create Git Tag
```bash
# Tag the release
git tag -a v1.0.0 -m "Release version 1.0.0"
git push origin v1.0.0
```

## Deployment Checklist

### Pre-Deployment
- [ ] Backup current production database
- [ ] Backup current production files
- [ ] Notify users of scheduled maintenance (if applicable)
- [ ] Put site in maintenance mode (if applicable)

### Files to Upload
Upload ONLY these directories:
- [ ] `public_html/` → Web root
- [ ] `private_html/` → Above web root (NOT publicly accessible)

Do NOT upload:
- ❌ `_tests/` (development only)
- ❌ `_migrations/` (run manually, don't upload)
- ❌ `_docs/` (development only)
- ❌ `_config/` (development only)
- ❌ `.git/` (development only)
- ❌ `.claude/` (development only)
- ❌ `vendor/` (if using Composer, install on server)
- ❌ `node_modules/` (if using npm)
- ❌ `.env` files (configure on server directly)

### Database Deployment
```bash
# Connect to database
mysql -u username -p database_name

# Run migrations in order
source _migrations/001_initial_setup.sql
source _migrations/002_auth_enhancements.sql
source _migrations/003_oauth_providers.sql
source _migrations/004_contact_submissions.sql
source _migrations/005_blog_system.sql
source _migrations/006_support_system.sql

# Verify migrations
SHOW TABLES;
```

### Post-Deployment Verification
- [ ] Website loads correctly
- [ ] Login/registration works
- [ ] OAuth providers work
- [ ] API endpoints respond correctly
- [ ] Email sending works
- [ ] Database connections stable
- [ ] No PHP errors in logs
- [ ] SSL certificate valid and working
- [ ] Forms submit successfully
- [ ] File uploads work (if applicable)
- [ ] Payment processing works (if applicable)

### Rollback Plan
If deployment fails:
1. Restore database backup
2. Restore file backup
3. Document what went wrong
4. Fix issues in development
5. Re-test before attempting again

## Environment-Specific Notes

### Production
- Debug mode: OFF
- Error display: OFF
- Error logging: ON (to file)
- HTTPS: Required
- Cache: Enabled
- Minification: Enabled

### Staging
- Debug mode: ON
- Error display: ON
- Error logging: ON
- HTTPS: Recommended
- Cache: Optional
- Minification: Optional

### Development
- Debug mode: ON
- Error display: ON
- Error logging: ON
- HTTPS: Optional
- Cache: Disabled
- Minification: Disabled

## Monitoring Post-Deployment

### First 24 Hours
- [ ] Monitor error logs every 2 hours
- [ ] Check server resource usage
- [ ] Monitor response times
- [ ] Check for failed login attempts
- [ ] Verify backup systems running

### First Week
- [ ] Daily error log review
- [ ] Monitor database performance
- [ ] Check for security issues
- [ ] Gather user feedback
- [ ] Review analytics for anomalies

## Emergency Contacts
- Database Admin: [contact info]
- System Administrator: [contact info]
- Lead Developer: [contact info]
- Hosting Support: [Dreamhost support]

## Useful Commands

```bash
# Check PHP version
php -v

# Check MySQL version
mysql --version

# Test database connection
php -r "new mysqli('localhost', 'user', 'pass', 'db');"

# Check file permissions
ls -la

# View error logs
tail -f /path/to/error.log

# Clear PHP OpCache (if enabled)
php -r "opcache_reset();"
```

## Deployment Frequency

- **Hotfixes**: As needed (security/critical bugs)
- **Minor Updates**: Bi-weekly
- **Major Releases**: Monthly or quarterly
- **Security Patches**: Immediately upon availability

---

**Last Updated**: 2026-02-03
**Version**: 1.0.0
