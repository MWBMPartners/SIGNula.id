# SIGNula.ID Copilot Instructions

## Project Architecture

**SIGNula** is a universal SSO authentication system built with PHP 8.3+ and MySQL 8.0+. The architecture follows a clear separation pattern:

- `private_html/` - Server-deployed classes (Auth, OAuth, WebAuthn, Email, API controllers)
- `public_html/` - Web-accessible files (pages, assets, API endpoints)
- `_config/` - Development configuration
- `_database/` - Schema files and migrations

### Core Authentication Flow

All authentication flows use the `Auth` class in `private_html/auth/Auth.php` which provides:
- Traditional password auth with Argon2id hashing
- WebAuthn/FIDO2 PassKeys via `WebAuthnHandler.php`
- Passwordless email login via `PasswordlessLoginHandler.php`
- OAuth providers (Google, Microsoft, Apple, Meta, LinkedIn, GitHub)

### Security Patterns

1. **Direct access prevention**: All classes start with `if (!defined('SIGNULA_INIT'))` check
2. **MySQLi prepared statements**: All database queries use prepared statements
3. **Rate limiting**: Built into all authentication endpoints
4. **AES-256-CBC encryption**: For sensitive data storage
5. **CSRF tokens**: Required for all state-changing operations

## Development Workflows

### Testing Commands
```bash
# Run all quality checks
composer quality

# Individual commands
composer test              # PHPUnit tests
composer test:unit         # Unit tests only
composer analyze          # PHPStan static analysis
composer check-style      # PHP_CodeSniffer
```

### Database Management
- Use migrations in `_database/migrations/` for schema changes
- Complete install script: `_sql/signula_complete_install_v2.0.1.sql`
- Stored procedures for cleanup: `cleanupExpiredAuthTokens`

### Email System
Multi-provider email system with fallback (Microsoft Graph, Gmail API, SendGrid, etc.)
```php
// Email sending pattern
$emailService = new EmailService();
$emailService->sendTemplate('welcome', $userEmail, $templateVars);
```

## File Organization Conventions

### API Structure
- Controllers in `private_html/api/controllers/`
- Base classes: `BaseController.php`, `Response.php`, `Router.php`, `Validator.php`
- WebAuthn endpoints: `public_html/api/webauthn/`

### Frontend Structure
- Layout components: `private_html/layout/` (header.php, footer.php, settings-sidebar.php)
- Auth pages: `public_html/auth/` (login.php, register.php, passkey-*.php)
- Settings pages: `public_html/settings/`

### Class Organization
- All classes use PSR-4 autoloading with `SIGNula\` namespace
- Extensive PHPDoc comments with emoji headers for visual scanning
- Error handling with `ErrorLogger` and `ActivityLogger` utilities

## Integration Patterns

### WebAuthn Implementation
Complete FIDO2/WebAuthn flow with 4 API endpoints:
- `register-options.php` - Generate registration challenge
- `register-verify.php` - Verify and store credential
- `auth-options.php` - Generate authentication challenge  
- `auth-verify.php` - Verify authentication response

### OAuth Integration
Each provider has dedicated handler in `private_html/auth/providers/`
- Consistent interface pattern across all providers
- Automatic account linking based on verified email
- Support for both personal and workspace accounts

### Email Templates
Template system with variable substitution:
- Templates in `_private/templates/email/`
- Preview system for development
- A/B testing and analytics tracking

## Testing Strategy

### Test Structure
- Unit tests: `_tests/Unit/`
- Integration tests: `_tests/Integration/`
- Fixtures: `_tests/Fixtures/users.json`
- Bootstrap: `_tests/bootstrap.php`

### Quick Testing
Use `_tests/verify-phase1-setup.php` for rapid environment verification. Project includes comprehensive testing guides in `TESTING_GUIDE_PHASE1.md`.

## Key Dependencies

- **Database**: MySQLi with prepared statements (no ORM)
- **Email**: Multi-provider system with health monitoring
- **Authentication**: WebAuthn library for FIDO2 support
- **Development**: PHPStan (level 6), PHPUnit 10+, PHP_CodeSniffer