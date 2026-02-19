## Summary of Changes

<!-- Provide a clear, concise description of what this PR does and why. -->



## Type of Change

<!-- Check all that apply. -->

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] Refactoring (no functional changes, no API changes)
- [ ] Documentation update
- [ ] Security patch
- [ ] Performance improvement
- [ ] Database migration
- [ ] Configuration change
- [ ] CI/CD or build process change

## Component(s) Affected

<!-- Check all that apply. -->

- [ ] Authentication (login/register/MFA)
- [ ] API (RESTful endpoints)
- [ ] Payments (PayPal/Stripe/Coinbase/Ko-fi/Patreon)
- [ ] OAuth (third-party account linking)
- [ ] Admin Panel
- [ ] Partner Dashboard
- [ ] Email System
- [ ] UI/Frontend
- [ ] Database / Migrations
- [ ] Configuration / Settings

## Testing

<!-- Describe the tests you ran and how to reproduce them. -->

### Test Steps

1.
2.
3.

### Test Environment

- PHP version:
- MySQL version:
- Browser(s):
- OS:

## Related Issues

<!-- Link any related issues. Use "Closes #123" to auto-close issues when merged. -->

- Closes #
- Related to #

## Screenshots

<!-- If applicable, add screenshots to demonstrate the changes. -->

| Before | After |
|--------|-------|
|        |       |

## Database Changes

<!-- If this PR includes database changes, describe them here. -->

- [ ] New migration file(s) included
- [ ] Migration tested (up and down)
- [ ] No database changes

## API Changes

<!-- If this PR modifies API endpoints, describe the changes. -->

- [ ] New endpoint(s) added
- [ ] Existing endpoint(s) modified
- [ ] API documentation updated
- [ ] No API changes

## Checklist

<!-- Ensure all items are checked before requesting review. -->

### Code Quality
- [ ] My code follows the project's PHP coding standards (full notation, detailed comments, proper indentation)
- [ ] I have used MySQLi prepared statements for all database queries
- [ ] I have added detailed inline comments and annotations
- [ ] I have not introduced any hardcoded credentials or sensitive data
- [ ] Settings are stored in the database (tblSettings), not in code

### Security
- [ ] Sensitive values are encrypted before database storage
- [ ] Input validation and sanitisation is implemented
- [ ] No PHP file extensions are exposed in URLs
- [ ] CSRF protection is maintained
- [ ] Rate limiting is respected

### Testing
- [ ] I have tested this change locally
- [ ] Existing tests still pass
- [ ] New tests have been added for new functionality (where applicable)

### Documentation
- [ ] I have updated relevant documentation
- [ ] CHANGELOG.md has been updated (if applicable)
- [ ] API documentation has been updated (if API changes)

### Accessibility & Responsiveness
- [ ] UI changes are responsive across screen sizes
- [ ] WCAG accessibility standards are maintained
- [ ] Changes work without JavaScript enabled (graceful degradation)

---

**Reviewer Notes:**
<!-- Any specific areas you'd like reviewers to focus on? -->


