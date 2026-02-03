# Changelog

All notable changes to the SIGNula project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Planned
- Payment system integration (PayPal, Apple Pay, Google Pay, Crypto)
- Admin dashboard
- Public web interface (marketing pages)
- Mobile apps (iOS, Android)
- Advanced analytics and reporting
- Multi-tenant organization support

---

## [2.0.1-beta] - 2026-02-03

### Added
- **OAuth Multi-Account Support**: Users can now link multiple accounts from the same provider
  - `accountType` field to classify accounts (personal, work, school)
  - `emailDomain` field for domain-based filtering
  - Automatic account type detection based on email patterns
  - Unique constraint on (provider, providerUserID) to prevent duplicate external accounts
- Database migration `003_oauth_multi_account_support.sql`
- Comprehensive OAuth integration examples documentation (`_docs/OAUTH_INTEGRATION_EXAMPLES.md`, 450+ lines)
- Complete installation SQL file (`_sql/signula_complete_install_v2.0.1.sql`, 1,100+ lines)
- Installation documentation (`_sql/README.md`)
- Version management system (VERSION file, CHANGELOG.md)

### Changed
- **OAuthController.php**: Enhanced to support multiple accounts per provider
  - Removed provider uniqueness restriction
  - Added duplicate external account prevention
  - Updated `linkAccount()` method with account type and domain support
  - Updated `getLinkedAccounts()` to return new fields
- **README.md**: Updated database setup section with comprehensive SQL installation options
- **PROJECT_PROGRESS.md**: Added Phase 3.1 section and updated version to 2.0.1-beta

### Security
- Prevents same external OAuth account from being linked to multiple SIGNula accounts
- Maintains encryption for OAuth tokens (AES-256-CBC)

---

## [2.0.0-beta] - 2026-02-02

### Added - Phase 3: RESTful API Enhancement
- **Core API Framework** (~1,700 lines)
  - `Response.php`: Standardized JSON response formatter with 13 HTTP status helpers
  - `Router.php`: RESTful request router with URL parameter extraction
  - `Validator.php`: Input validation system with 20+ rules
  - `BaseController.php`: Base API controller with authentication and pagination
- **API Controllers** (4 controllers, ~2,650 lines, 30+ endpoints)
  - `AuthController.php`: Authentication endpoints (register, login, logout, verify email, password reset)
  - `UserController.php`: User management (profile, sessions, activity, preferences, password/email changes)
  - `MFAController.php`: MFA management (enable/disable, verify, backup codes)
  - `OAuthController.php`: OAuth account linking (providers, link/unlink, set primary)
- API entry point: `public_html/api/v1/index.php`
- URL rewrite rules: `public_html/api/.htaccess`
- Utility endpoints: Health check, API info
- Comprehensive API documentation in README.md

### Added - Phase 2: Account Management UI
- **Settings Dashboard** (`/settings/`) with 8 comprehensive pages:
  - Dashboard with statistics and security score
  - Profile management with email change
  - Security settings with password strength meter
  - OAuth account linking interface
  - PassKey management
  - MFA configuration
  - Activity log viewer with filtering and export (CSV/JSON)
  - Privacy settings with GDPR compliance
  - Notification preferences
- Reusable components:
  - `_includes/layout/settings-sidebar.php`
  - `_includes/layout/settings-header.php`
- Testing documentation: `TESTING_GUIDE_PHASE2.md`, `QUICK_TEST_REFERENCE_PHASE2.md`

### Added - Phase 1.5: Advanced Authentication
- **WebAuthn/PassKey Support**
  - Database tables: `tblWebAuthnCredentials`, `tblWebAuthnChallenges`
  - Backend handler: `WebAuthnHandler.php` (730+ lines)
  - API endpoints for registration and authentication
  - User pages: PassKey register, login, management
- **Passwordless Login**
  - Database table: `tblPasswordlessTokens`
  - Backend handler: `PasswordlessLoginHandler.php` (650+ lines)
  - Magic link generation with secure tokens
  - User pages: Request link, verify and login
- Database migration: `005_webauthn_passkeys.sql`
- Stored procedure: `cleanupExpiredAuthTokens()`
- Comprehensive testing documentation with 60+ test cases

### Features
- ✅ CORS support with configurable origins
- ✅ Pagination (25-100 items per page)
- ✅ Request ID tracking for logging correlation
- ✅ Activity logging for all API actions
- ✅ Comprehensive error handling
- ✅ FIDO2/WebAuthn compliance
- ✅ Time-limited magic links (15-30 minutes configurable)
- ✅ Session management across multiple devices
- ✅ Real-time security score calculation
- ✅ Activity log export functionality

### Security
- Input validation on all API endpoints
- SQL injection protection via prepared statements
- Argon2id password hashing
- AES-256-CBC token encryption
- Rate limiting framework
- CSRF protection
- Secure session handling
- Challenge-response authentication for PassKeys
- SHA-256 token hashing for magic links

---

## [1.0.0] - 2024-11-15

### Added - Phase 1: Core Foundation
- **Database Schema** (27 tables, 2 views, 4 stored procedures)
  - Core user accounts with UUID support
  - Multi-factor authentication (TOTP, Email OTP, SMS, Push, Backup Codes)
  - OAuth integration (Google, Microsoft, Apple, Facebook, Instagram, LinkedIn, LastPass, Yahoo, WordPress, Amazon, PayPal, OpenID, GitHub, Twitter)
  - Session management with device tracking
  - Email verification and password reset systems
  - Activity and error logging
  - System settings with encryption support
- **MySQLi Connection Handler**
  - Prepared statements for all queries
  - Transaction support
  - Connection pooling
- **Core Configuration System**
  - Database-driven settings (`tblSettings`)
  - Encryption key management
  - Environment-aware configuration
- **Security Utilities**
  - AES-256-CBC encryption with salt
  - Argon2id password hashing (PHP 8.3+)
  - CSRF token generation and validation
  - Rate limiting framework
  - IP address validation (IPv4/IPv6)
  - Secure random token generation
- **Authentication System**
  - User registration with email verification
  - Login with password
  - Session management
  - Remember me functionality
  - Password reset via email
  - Account lockout after failed attempts
- **Logging Systems**
  - Activity logging (`tblActivityLog`) with severity levels
  - Error logging (`tblErrorLog`) with stack traces
  - Audit trail for security events
- **Email Service**
  - Template system with variable substitution
  - Email queue for asynchronous sending
  - SMTP support with fallback to PHP mail()
  - HTML and plain text support
  - Email verification
  - Password reset emails
- **MFA Implementation**
  - TOTP (Time-based One-Time Password) support
  - Email OTP delivery
  - SMS OTP framework (provider integration needed)
  - Push notification framework
  - Backup recovery codes (10 per user, Argon2id hashed)
  - QR code generation for TOTP setup
- **OAuth Integration Framework**
  - OAuth 2.0 flow implementation
  - Support for 14 providers
  - Token encryption and refresh
  - Account linking and unlinking
  - Primary account selection

### Documentation
- Project README with installation guide
- Database schema documentation
- API endpoint structure
- Security best practices
- Development roadmap (PROJECT_PROGRESS.md)

---

## Version Number Format

This project uses [Semantic Versioning](https://semver.org/):

```
MAJOR.MINOR.PATCH-prerelease+build
```

- **MAJOR**: Incompatible API changes
- **MINOR**: New features (backward compatible)
- **PATCH**: Bug fixes (backward compatible)
- **prerelease**: alpha, beta, rc (release candidate)
- **build**: Build metadata (optional)

### Examples
- `1.0.0` - First stable release
- `1.1.0` - Minor feature addition
- `1.1.1` - Bug fix
- `2.0.0-beta` - Major version beta
- `2.0.0-rc.1` - Release candidate 1
- `2.0.0` - Major stable release

---

## Release Process

1. Update `VERSION` file with new version number
2. Update `CHANGELOG.md` with changes for the release
3. Update version references in:
   - `PROJECT_PROGRESS.md` (header)
   - `README.md` (if needed)
   - SQL installation files (if database changes)
4. Commit changes: `git commit -am "chore: Release v2.0.1-beta"`
5. Create Git tag: `git tag -a v2.0.1-beta -m "Release v2.0.1-beta"`
6. Push changes and tags: `git push origin main --tags`
7. Create GitHub Release with changelog notes

---

## Categories for Changes

- **Added**: New features
- **Changed**: Changes to existing functionality
- **Deprecated**: Soon-to-be removed features
- **Removed**: Removed features
- **Fixed**: Bug fixes
- **Security**: Security fixes or improvements

---

[Unreleased]: https://github.com/MWBMPartners/SIGNula.id/compare/v2.0.1-beta...HEAD
[2.0.1-beta]: https://github.com/MWBMPartners/SIGNula.id/compare/v2.0.0-beta...v2.0.1-beta
[2.0.0-beta]: https://github.com/MWBMPartners/SIGNula.id/compare/v1.0.0...v2.0.0-beta
[1.0.0]: https://github.com/MWBMPartners/SIGNula.id/releases/tag/v1.0.0
