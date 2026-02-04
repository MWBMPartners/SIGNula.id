# 📚 SIGNula Documentation Index

**Version:** 2.1.0-beta
**Last Updated:** February 4, 2026

---

## 🎯 Quick Navigation

### 🚀 Getting Started
- [README.md](../README.md) - Project overview and installation
- [QUICK_TEST_REFERENCE.md](QUICK_TEST_REFERENCE.md) - 30-min quick test guide

### 📖 User Guides
- **Delegate Email Sending:**
  - [SHARED_MAILBOXES_AND_AUTH_MODES.md](SHARED_MAILBOXES_AND_AUTH_MODES.md) ⭐ **START HERE** - Complete feature guide
  - [MICROSOFT_DELEGATE_MAILBOX_SETUP.md](MICROSOFT_DELEGATE_MAILBOX_SETUP.md) - Azure AD setup guide (step-by-step)

### 🔧 Technical Documentation
- **Architecture:**
  - [DELEGATE_MAILBOX_ARCHITECTURE.md](DELEGATE_MAILBOX_ARCHITECTURE.md) - Delegate email technical architecture
  - [DIRECTORY_STRUCTURE.md](DIRECTORY_STRUCTURE.md) - Project directory structure

- **Development:**
  - [BUILD_CHECKLIST.md](BUILD_CHECKLIST.md) - Pre-deployment checklist
  - [VERSION_MANAGEMENT.md](VERSION_MANAGEMENT.md) - Version management guide

### 🧪 Testing
- [TESTING_GUIDE_COMPREHENSIVE.md](TESTING_GUIDE_COMPREHENSIVE.md) - Complete test suite (300+ tests)
- [QUICK_TEST_REFERENCE.md](QUICK_TEST_REFERENCE.md) - 30-minute rapid validation

### 🌐 API Documentation
- **For Partners:**
  - [../public_html/docs/api/index.html](../public_html/docs/api/index.html) ⭐ **Interactive HTML docs** (recommended)
  - [../public_html/docs/api/API_DOCUMENTATION.md](../public_html/docs/api/API_DOCUMENTATION.md) - Markdown reference
- **For Developers:**
  - [../.claude/API_ANALYSIS.md](../.claude/API_ANALYSIS.md) - Security audit & gap analysis

### 🔗 Integration
- [OAUTH_INTEGRATION_EXAMPLES.md](OAUTH_INTEGRATION_EXAMPLES.md) - OAuth integration for third-party services

### 📊 Project Management
- [../PROJECT_PROGRESS.md](../PROJECT_PROGRESS.md) - Development roadmap & progress
- [../CHANGELOG.md](../CHANGELOG.md) - Version history (Keep a Changelog format)
- [../.claude/REQUIREMENTS_STATUS.md](../.claude/REQUIREMENTS_STATUS.md) - Requirements completion status

### 🗄️ Database
- [../_sql/README.md](../_sql/README.md) - Database installation guide
- [../.claude/DATABASE_SCHEMA_STATUS.md](../.claude/DATABASE_SCHEMA_STATUS.md) - Schema documentation

---

## 📂 Documentation by Audience

### For End Users
- Account setup and management guides (coming soon)
- FAQ (coming soon)
- Troubleshooting guides (coming soon)

### For System Administrators
- [Installation](../README.md#installation--setup)
- [Configuration](../README.md#configuration)
- [MICROSOFT_DELEGATE_MAILBOX_SETUP.md](MICROSOFT_DELEGATE_MAILBOX_SETUP.md) - Azure AD setup
- [BUILD_CHECKLIST.md](BUILD_CHECKLIST.md) - Pre-deployment checklist
- [TESTING_GUIDE_COMPREHENSIVE.md](TESTING_GUIDE_COMPREHENSIVE.md) - Testing procedures

### For Developers
- [DELEGATE_MAILBOX_ARCHITECTURE.md](DELEGATE_MAILBOX_ARCHITECTURE.md) - System architecture
- [DIRECTORY_STRUCTURE.md](DIRECTORY_STRUCTURE.md) - Project structure
- [VERSION_MANAGEMENT.md](VERSION_MANAGEMENT.md) - Version management
- [../.claude/CLAUDE_NOTES.md](../.claude/CLAUDE_NOTES.md) - Development patterns & conventions
- [OAUTH_INTEGRATION_EXAMPLES.md](OAUTH_INTEGRATION_EXAMPLES.md) - Integration examples

### For Partners/Third-Party Developers
- [../public_html/docs/api/index.html](../public_html/docs/api/index.html) - Interactive API documentation
- [../public_html/docs/api/API_DOCUMENTATION.md](../public_html/docs/api/API_DOCUMENTATION.md) - API reference
- [OAUTH_INTEGRATION_EXAMPLES.md](OAUTH_INTEGRATION_EXAMPLES.md) - Integration guide
- [../.claude/API_ANALYSIS.md](../.claude/API_ANALYSIS.md) - API security & gaps

---

## 📋 Documentation by Feature

### Core Authentication
- [../README.md](../README.md#-installation--setup) - Overview
- [TESTING_GUIDE_COMPREHENSIVE.md](TESTING_GUIDE_COMPREHENSIVE.md#phase-1-core-authentication) - Testing

### WebAuthn/PassKeys
- [../README.md](../README.md#-phase-1-features-webauthn--passwordless-auth) - Features overview
- [TESTING_GUIDE_COMPREHENSIVE.md](TESTING_GUIDE_COMPREHENSIVE.md#phase-15-webauthn--passwordless) - Testing
- Phase 1 detailed docs (archived):
  - [../AUTH_PHASE1_DOCUMENTATION.md](../AUTH_PHASE1_DOCUMENTATION.md)
  - [../TESTING_GUIDE_PHASE1.md](../TESTING_GUIDE_PHASE1.md)

### Account Management UI
- [../README.md](../README.md#-phase-2-features-account-management-ui) - Features overview
- [TESTING_GUIDE_COMPREHENSIVE.md](TESTING_GUIDE_COMPREHENSIVE.md#phase-2-account-management-ui) - Testing
- Phase 2 detailed docs (archived):
  - [../TESTING_GUIDE_PHASE2.md](../TESTING_GUIDE_PHASE2.md)

### RESTful API
- [../public_html/docs/api/index.html](../public_html/docs/api/index.html) - Complete documentation
- [../README.md](../README.md#-phase-3-features-restful-api) - Features overview
- [../.claude/API_ANALYSIS.md](../.claude/API_ANALYSIS.md) - Analysis & security audit
- [TESTING_GUIDE_COMPREHENSIVE.md](TESTING_GUIDE_COMPREHENSIVE.md#phase-3-restful-api) - Testing

### Delegate Email Sending
- [SHARED_MAILBOXES_AND_AUTH_MODES.md](SHARED_MAILBOXES_AND_AUTH_MODES.md) ⭐ **User guide**
- [MICROSOFT_DELEGATE_MAILBOX_SETUP.md](MICROSOFT_DELEGATE_MAILBOX_SETUP.md) - Azure AD setup
- [DELEGATE_MAILBOX_ARCHITECTURE.md](DELEGATE_MAILBOX_ARCHITECTURE.md) - Technical architecture
- [DUAL_MODE_IMPLEMENTATION_SUMMARY.md](DUAL_MODE_IMPLEMENTATION_SUMMARY.md) - Implementation details
- [../README.md](../README.md#-delegate-email-sending) - Features overview
- [TESTING_GUIDE_COMPREHENSIVE.md](TESTING_GUIDE_COMPREHENSIVE.md#phase-32-delegate-email-sending) - Testing
- [../.claude/IMPLEMENTATION_COMPLETE.md](../.claude/IMPLEMENTATION_COMPLETE.md) - Setup & testing

### OAuth Multi-Account Support
- [OAUTH_INTEGRATION_EXAMPLES.md](OAUTH_INTEGRATION_EXAMPLES.md) - Integration guide
- [../README.md](../README.md#-phase-3-features-restful-api) - Features overview

---

## 🔄 Document Status

| Document | Status | Last Updated | Audience |
|----------|--------|--------------|----------|
| README.md | ✅ Current | 2026-02-04 | All |
| PROJECT_PROGRESS.md | ✅ Current | 2026-02-04 | Developers |
| CHANGELOG.md | ✅ Current | 2026-02-04 | All |
| TESTING_GUIDE_COMPREHENSIVE.md | ✅ Current | 2026-02-04 | QA/Admins |
| QUICK_TEST_REFERENCE.md | ✅ Current | 2026-02-04 | QA/Admins |
| API Documentation | ✅ Current | 2026-02-04 | Partners |
| Delegate Email Docs | ✅ Current | 2026-02-03 | Admins/Devs |
| OAuth Integration Examples | ✅ Current | 2026-02-03 | Partners |
| Version Management | ✅ Current | 2026-02-03 | Developers |

### Archived (Still Valid but Superseded)
| Document | Superseded By | Notes |
|----------|---------------|-------|
| TESTING_GUIDE_PHASE1.md | TESTING_GUIDE_COMPREHENSIVE.md | Phase 1 details preserved |
| TESTING_GUIDE_PHASE2.md | TESTING_GUIDE_COMPREHENSIVE.md | Phase 2 details preserved |
| QUICK_TEST_REFERENCE_PHASE2.md | QUICK_TEST_REFERENCE.md | Consolidated |
| AUTH_PHASE1_DOCUMENTATION.md | README.md + TESTING_GUIDE | Preserved for reference |

---

## 🔍 Finding Documentation

### "How do I...?"

**...install SIGNula?**
→ [README.md - Installation & Setup](../README.md#-installation--setup)

**...setup delegate email sending?**
→ [SHARED_MAILBOXES_AND_AUTH_MODES.md](SHARED_MAILBOXES_AND_AUTH_MODES.md)

**...configure Azure AD for Microsoft 365?**
→ [MICROSOFT_DELEGATE_MAILBOX_SETUP.md](MICROSOFT_DELEGATE_MAILBOX_SETUP.md)

**...test the system?**
→ [QUICK_TEST_REFERENCE.md](QUICK_TEST_REFERENCE.md) (30 min) or [TESTING_GUIDE_COMPREHENSIVE.md](TESTING_GUIDE_COMPREHENSIVE.md) (full suite)

**...integrate with the API?**
→ [public_html/docs/api/index.html](../public_html/docs/api/index.html)

**...integrate OAuth with my service?**
→ [OAUTH_INTEGRATION_EXAMPLES.md](OAUTH_INTEGRATION_EXAMPLES.md)

**...understand the architecture?**
→ [DELEGATE_MAILBOX_ARCHITECTURE.md](DELEGATE_MAILBOX_ARCHITECTURE.md)

**...check project status?**
→ [PROJECT_PROGRESS.md](../PROJECT_PROGRESS.md)

**...see what's changed?**
→ [CHANGELOG.md](../CHANGELOG.md)

---

## 📞 Support & Contribution

**Found an issue in documentation?**
- Check if your SIGNula version matches the documentation version
- Report documentation issues to the development team

**Want to contribute?**
- Follow documentation standards in [CLAUDE_NOTES.md](../.claude/CLAUDE_NOTES.md)
- Keep documentation in sync with code changes
- Update CHANGELOG.md with all changes

---

## 📄 Documentation Standards

### File Naming
- Use UPPERCASE_WITH_UNDERSCORES.md for main documentation
- Use lowercase-with-hyphens.md for supporting files
- Prefix with feature name for feature-specific docs

### Content Structure
- Always include version and last updated date
- Use clear section headers with emoji icons
- Include table of contents for documents > 200 lines
- Provide examples and code snippets
- Link to related documentation

### Maintenance
- Update documentation with every feature change
- Keep README.md, CHANGELOG.md, and PROJECT_PROGRESS.md in sync
- Archive outdated docs instead of deleting
- Update this index when adding new documentation

---

**Last Updated:** February 4, 2026
**Documentation Version:** 2.1.0-beta
**Maintained by:** SIGNula Development Team

---

**Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
