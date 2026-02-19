# Contributing to SIGNula

Thank you for your interest in contributing to SIGNula! This document provides guidelines and information for contributors.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Code Style Guidelines](#code-style-guidelines)
- [Branch Naming Convention](#branch-naming-convention)
- [Commit Message Format](#commit-message-format)
- [Pull Request Process](#pull-request-process)
- [Issue Reporting](#issue-reporting)
- [Testing Requirements](#testing-requirements)
- [Security](#security)

## Code of Conduct

By participating in this project, you agree to maintain a respectful, inclusive, and collaborative environment. We expect all contributors to:

- Be respectful and constructive in all communications
- Welcome newcomers and help them get started
- Focus on what is best for the project and its users
- Accept constructive criticism gracefully

## Getting Started

1. **Fork the repository** on GitHub
2. **Clone your fork** locally
3. **Create a branch** for your changes (see [Branch Naming Convention](#branch-naming-convention))
4. **Make your changes** following our code style guidelines
5. **Test your changes** thoroughly
6. **Submit a pull request** with a clear description

## Development Setup

### Prerequisites

- **PHP 8.3+** (PHP 8.4 preferred for longevity)
- **MySQL 8.0+** or **MariaDB 10.6+**
- **Apache** or **Nginx** web server with URL rewriting enabled
- **Git** for version control
- A code editor (Visual Studio Code recommended)

### Local Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/MWBMPartners/SIGNula.id.git
   cd SIGNula.id
   ```

2. Set up the database:
   - Create a MySQL database for SIGNula
   - Run the migration scripts from `_database/migrations/` in order
   - Configure your database connection in the appropriate configuration file

3. Configure your web server:
   - Point your document root to the `web/public_html/` directory
   - Ensure URL rewriting is enabled (`.htaccess` for Apache)
   - Ensure the `_private/` directories are NOT web-accessible

4. Verify your setup:
   - Access the application in your browser
   - Check that the health endpoint responds correctly

### Important Notes on Hosting

- SIGNula is designed to run on shared hosting (initially Dreamhost)
- **Composer is not available** on the target hosting environment; third-party libraries must be manually managed
- Any new dependencies must be documented and included in the repository
- Libraries should be loaded from CDN first, with a local fallback

## Code Style Guidelines

### PHP

- **Minimum version**: PHP 8.3 (target PHP 8.4)
- **Use PHP predefined constants** for platform neutrality:
  - `DIRECTORY_SEPARATOR` instead of `/` or `\` in file paths
  - `PHP_EOL` for line endings
  - `PHP_INT_MAX`, `PHP_OS`, etc. where applicable
- **Full notation only** for all code blocks:
  ```php
  // CORRECT - Full notation
  if ($condition) {
      doSomething();
  } else {
      doSomethingElse();
  }

  // INCORRECT - Shortened notation
  if ($condition) doSomething();
  ```
- **Detailed inline comments** with references to official documentation:
  ```php
  /**
   * Validate the TOTP code provided by the user.
   *
   * Uses HMAC-based One-Time Password (HOTP) algorithm as defined in RFC 4226,
   * extended to TOTP as per RFC 6238.
   *
   * @see https://datatracker.ietf.org/doc/html/rfc6238
   * @see https://www.php.net/manual/en/function.hash-hmac.php
   *
   * @param string $secret  The shared secret key
   * @param string $code    The OTP code to validate
   * @param int    $window  The acceptable time window (default: 1)
   *
   * @return bool True if the code is valid
   */
  ```
- **Modular structure** -- shared code in reusable includes:
  - Use `require_once` and `include_once` with error handling
  - Verify file existence before including
- **Error handling** -- always check for failures:
  ```php
  $filePath = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'config.php';
  if (file_exists($filePath)) {
      require_once $filePath;
  } else {
      // Log error and handle gracefully
      error_log('Configuration file not found: ' . $filePath);
  }
  ```
- **No PHP extensions in URLs** -- use directory-based routing or `.htaccess` rewriting

### SQL / Database

- **MySQLi only** -- do not use PDO or the legacy `mysql_*` functions
- **Prepared statements for ALL queries** -- no exceptions:
  ```php
  // CORRECT
  $stmt = $conn->prepare("SELECT * FROM tblUsers WHERE email = ?");
  $stmt->bind_param("s", $email);
  $stmt->execute();

  // INCORRECT - Never do this
  $result = $conn->query("SELECT * FROM tblUsers WHERE email = '$email'");
  ```
- **Comprehensive SQL comments** explaining the purpose of each query
- **Prefix all tables** with `tbl` (e.g., `tblUsers`, `tblSettings`, `tblActivityLog`)
- **Sensitive data** marked as `isSensitive` must be encrypted with salt before storage

### HTML / CSS / JavaScript

- **HTML5 compliant** with semantic elements
- **Fully responsive** design using Bootstrap
- **WCAG compliant** for accessibility (screen readers, colour-blind mode)
- **Graceful degradation** -- all functionality must work without JavaScript
- **AJAX enhancement** -- use AJAX to improve UX but never as a hard requirement
- **SVG graphics** with Base64 fallback for browsers without SVG support
- **Bootstrap** as the primary CSS framework, with FontAwesome for icons
- **jQuery** for DOM manipulation and AJAX calls

### General

- **Use emojis in comments** to highlight important sections (as noted in project guidelines)
- **Indent consistently** -- 4 spaces for PHP, 2 spaces for HTML/CSS/JS (or tab as configured)
- **No hardcoded credentials** -- all secrets in database (`tblSettings`) or secure config files
- **Private files** outside web-accessible directories, in folders prefixed with `_` (e.g., `_private/`)

## Branch Naming Convention

Use the following prefixes for branch names:

| Prefix      | Purpose                              | Example                           |
| ----------- | ------------------------------------ | --------------------------------- |
| `feature/`  | New features                         | `feature/passkey-support`         |
| `bugfix/`   | Bug fixes                            | `bugfix/mfa-token-expiry`         |
| `hotfix/`   | Urgent production fixes              | `hotfix/sql-injection-fix`        |
| `docs/`     | Documentation changes                | `docs/api-reference-update`       |
| `refactor/` | Code refactoring (no new features)   | `refactor/auth-module-cleanup`    |
| `test/`     | Adding or updating tests             | `test/payment-integration-tests`  |
| `chore/`    | Maintenance tasks                    | `chore/update-dependencies`       |

### Rules

- Use lowercase with hyphens as separators
- Keep names descriptive but concise
- Include issue number where applicable: `bugfix/123-mfa-token-expiry`

## Commit Message Format

We follow a structured commit message format:

```
type(scope): short description

Longer description if needed. Explain the "why" behind the change,
not just the "what". Reference any related issues.

Refs: #123
```

### Types

| Type       | Description                          |
| ---------- | ------------------------------------ |
| `feat`     | A new feature                        |
| `fix`      | A bug fix                            |
| `docs`     | Documentation changes                |
| `style`    | Formatting, missing semicolons, etc. |
| `refactor` | Code refactoring                     |
| `test`     | Adding or updating tests             |
| `chore`    | Maintenance tasks                    |
| `security` | Security-related changes             |
| `perf`     | Performance improvements             |

### Scopes

Use the component name: `auth`, `api`, `payments`, `oauth`, `admin`, `partner`, `email`, `ui`, `db`, `config`

### Examples

```
feat(auth): add TOTP-based MFA support

Implement Time-based One-Time Password support using HMAC-SHA1.
Users can now link authenticator apps (Google Authenticator,
Microsoft Authenticator) for two-factor authentication.

Refs: #45
```

```
fix(api): resolve rate limiting bypass on /auth/login endpoint

The rate limiter was not correctly tracking attempts when the
X-Forwarded-For header contained multiple IPs. Now correctly
parses the first IP in the chain.

Closes: #102
```

## Pull Request Process

1. **Ensure your branch is up to date** with `main`:
   ```bash
   git fetch origin
   git rebase origin/main
   ```

2. **Run all tests** before submitting (see [Testing Requirements](#testing-requirements))

3. **Fill out the PR template** completely:
   - Summary of changes
   - Type of change
   - Testing performed
   - Related issues
   - Screenshots (for UI changes)

4. **Request review** from at least one maintainer

5. **Address review feedback** promptly and push updates to the same branch

6. **Squash or rebase** if requested by maintainers before merge

### PR Requirements

- All CI checks must pass
- At least one approving review from a maintainer
- No unresolved conversations
- PR description must be complete
- Changes must be tested locally

## Issue Reporting

### Bug Reports

Use the [Bug Report template](https://github.com/MWBMPartners/SIGNula.id/issues/new?template=bug_report.yml) and include:

- Clear description of the bug
- Steps to reproduce
- Expected vs. actual behaviour
- Environment details (PHP version, MySQL version, browser, OS)
- Relevant logs or screenshots

### Feature Requests

Use the [Feature Request template](https://github.com/MWBMPartners/SIGNula.id/issues/new?template=feature_request.yml) and include:

- Problem statement
- Proposed solution
- Alternatives considered

### Security Issues

**DO NOT** report security vulnerabilities in public issues. See [SECURITY.md](SECURITY.md) for the responsible disclosure process.

## Testing Requirements

### Before Submitting a PR

1. **Manual testing** -- verify your changes work as expected in a local environment
2. **Regression testing** -- ensure existing functionality is not broken
3. **Cross-browser testing** (for UI changes) -- test in Chrome, Firefox, Safari, and Edge
4. **Responsive testing** (for UI changes) -- test across desktop, tablet, and mobile viewports
5. **Accessibility testing** (for UI changes) -- verify WCAG compliance
6. **API testing** (for endpoint changes) -- test with valid and invalid inputs

### Test Environment

- PHP 8.3+ with all required extensions
- MySQL 8.0+ or MariaDB 10.6+
- Tests located in the `_tests/` directory

### Running Tests

```bash
# Run all tests (if PHPUnit is available)
php _tests/run_tests.php

# Run specific test suite
php _tests/run_tests.php --suite=auth
```

## Security

- **Never commit** credentials, API keys, or secrets to the repository
- **Always use** prepared statements for database queries
- **Encrypt** sensitive data before storing in the database
- **Validate and sanitise** all user input
- **Follow** the security guidelines in [SECURITY.md](SECURITY.md)
- **Report** vulnerabilities through proper channels (never in public issues)

## Questions?

If you have questions about contributing:

- Open a [Discussion](https://github.com/MWBMPartners/SIGNula.id/discussions) on GitHub
- Check existing issues and discussions for answers
- Review the project documentation in the `_docs/` directory

---

Thank you for helping make SIGNula better and more secure!
