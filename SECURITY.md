# Security Policy

## Supported Versions

The following versions of SIGNula are currently supported with security updates:

| Version       | Supported          | Notes                          |
| ------------- | ------------------ | ------------------------------ |
| 2.6.x-beta    | :white_check_mark: | Current development release    |
| 2.5.x-beta    | :white_check_mark: | Previous release, patch only   |
| 2.4.x-beta    | :white_check_mark: | Legacy, critical patches only  |
| < 2.4.0       | :x:                | No longer supported            |

## Reporting a Vulnerability

We take the security of SIGNula seriously. If you believe you have found a security vulnerability, please report it responsibly.

### How to Report

**DO NOT** report security vulnerabilities through public GitHub issues, discussions, or pull requests.

Instead, please use one of the following methods:

1. **GitHub Security Advisories (Preferred)**
   - Navigate to [Security Advisories](https://github.com/MWBMPartners/SIGNula.id/security/advisories/new)
   - Create a new private security advisory
   - Provide as much detail as possible

2. **Email**
   - Send details to: **security@signula.id**
   - Use our PGP key for encrypted communication (available upon request)
   - Include "[SECURITY]" in the subject line

### What to Include

When reporting a vulnerability, please include:

- **Description** of the vulnerability and its potential impact
- **Steps to reproduce** the issue (proof of concept if possible)
- **Affected component(s)** (authentication, API, payments, etc.)
- **Affected version(s)** of SIGNula
- **Any potential mitigations** you have identified
- **Your contact information** for follow-up questions

### Response Timeline

| Stage                     | Target Timeline        |
| ------------------------- | ---------------------- |
| Acknowledgement           | Within 24 hours        |
| Initial assessment        | Within 72 hours        |
| Status update             | Within 7 days          |
| Fix development           | Within 30 days*        |
| Public disclosure          | After fix is deployed  |

*Critical vulnerabilities may be addressed sooner. Complex issues may require additional time, in which case we will provide regular updates.

### What to Expect

1. **Acknowledgement** -- We will acknowledge receipt of your report within 24 hours.
2. **Assessment** -- Our security team will evaluate the report and determine its severity and scope within 72 hours.
3. **Updates** -- We will keep you informed of our progress throughout the remediation process.
4. **Resolution** -- Once a fix is developed and tested, we will deploy it and notify you.
5. **Disclosure** -- We will coordinate public disclosure with you after the fix is available.

### Severity Classification

We use the following severity levels based on [CVSS v3.1](https://www.first.org/cvss/):

| Severity   | CVSS Score | Examples                                                    |
| ---------- | ---------- | ----------------------------------------------------------- |
| Critical   | 9.0 - 10.0 | Remote code execution, authentication bypass, data breach  |
| High       | 7.0 - 8.9  | Privilege escalation, SQL injection, XSS with high impact  |
| Medium     | 4.0 - 6.9  | CSRF, information disclosure, session fixation             |
| Low        | 0.1 - 3.9  | Minor information leaks, non-exploitable issues            |

## Scope

### In Scope

The following are in scope for security reports:

- SIGNula authentication system (all MFA methods, session management, password handling)
- RESTful API endpoints and authentication
- Payment processing integration (PayPal, Stripe, Coinbase Commerce)
- OAuth provider integrations
- Admin panel and partner dashboard
- Database security (encryption, prepared statements, access control)
- Email system and template rendering (HTML, AMP for Email)
- Webhook signature verification
- Rate limiting and brute-force protection
- Mass credential reset system (password invalidation, salt rotation)
- Avatar management system (upload, OAuth, fallback services)
- Form protection (honeypot, HMAC timing, JS challenge)
- CAPTCHA integration (CloudFlare Turnstile, reCAPTCHA v3)
- IP reputation checking (AbuseIPDB, proxycheck.io)
- Bot detection (CrawlerDetect, regex fallback)
- Session fingerprinting and integrity validation

### Out of Scope

The following are out of scope:

- Third-party services and their own vulnerabilities (PayPal, Stripe, Google, Microsoft, etc.)
- Social engineering attacks against SIGNula team members
- Denial of service (DoS/DDoS) attacks
- Physical security of hosting infrastructure
- Issues in dependencies that are already publicly known and have patches available
- Vulnerabilities requiring physical access to a user's device

## Security Best Practices

SIGNula follows these security practices:

- **Encryption**: All sensitive data is encrypted at rest using industry-standard algorithms with salting
- **Prepared Statements**: All database queries use MySQLi prepared statements to prevent SQL injection
- **CSRF Protection**: All forms include CSRF token validation
- **Rate Limiting**: API endpoints and authentication attempts are rate-limited
- **Activity Logging**: All account activity is logged for security auditing
- **Session Security**: Secure session management with proper expiration and regeneration
- **Input Validation**: All user input is validated and sanitised
- **Output Encoding**: All output is properly encoded to prevent XSS
- **HTTPS**: All communications are encrypted in transit via TLS

## Recognition

We appreciate the security research community's efforts in helping keep SIGNula and its users safe. Researchers who responsibly disclose valid vulnerabilities will be:

- Credited in our security acknowledgements (unless anonymity is preferred)
- Listed in our Hall of Fame (for significant findings)
- Notified when the vulnerability is fixed and disclosed

## Contact

- **Security Reports**: security@signula.id
- **GitHub Security Advisories**: [Create Advisory](https://github.com/MWBMPartners/SIGNula.id/security/advisories/new)
- **General Security Questions**: security@signula.id

---

*This security policy is effective as of February 2026 and will be reviewed and updated periodically.*
