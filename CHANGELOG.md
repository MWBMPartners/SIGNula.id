# Changelog

All notable changes to the SIGNula project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added

- **Multi-jurisdiction compliance — Layer 1a: consent foundation (G-004, in progress).**
  - New migration `040_consent_and_dsar.sql` creates the append-only consent
    audit trail (`tblConsentRecords`), the data-subject-request tracker
    (`tblDataSubjectRequests`), and its status-change timeline
    (`tblDataSubjectRequestEvents`), plus six `privacy`-category settings
    (DSAR SLA / identity-token / notify defaults, consent proof-hash toggle).
    Also widens `tblActivityLog.activityCategory` to accept the `privacy` category.
  - New `ConsentManager` (`web/private_html/compliance/`) — records every
    grant/withdraw as an immutable new row (never an update), with packed-IP
    minimisation (`VARBINARY(16)` via `inet_pton`), a tamper-evidence SHA-256
    proof hash, and full activity-log auditing; resolves the latest decision
    per consent type and reconciles anonymous-visitor consent to a user on login.
  - `settings/privacy.php` gains a server-persisted consent toggle and a
    read-only "My Consent History" section (CSRF-protected, server-authoritative
    allowlist, works without JavaScript).
  - Data-driven by design: no jurisdiction value is hardcoded — the regime
    model, DSAR fulfilment engine, and admin queue land in later G-004 cycles.
- **Multi-jurisdiction compliance — Layer 1b: DSAR engine (G-004, in progress).**
  - New `DSARManager` (`web/private_html/compliance/`) tracks a data-subject
    request (access / portability / erasure / rectification / …) through a
    guarded state machine, with SHA-256-hashed identity-verification tokens
    (raw token never stored), an audited status timeline, and bounded admin
    queue / compliance-report queries.
  - Fulfilment **delegates** to the existing GDPR engine rather than
    duplicating it: portability/access call `AccountManager::exportUserData()`,
    erasure calls `AccountManager::requestAccountDeletion()` (the existing
    grace-period cron performs the actual erasure). There is exactly one
    export/delete implementation.
  - Permanent erasure now **anonymises** (never deletes) the consent and DSAR
    records — the *fact* of consent and of a fulfilled request survives for
    regulator proof while the PII does not.
  - `settings/privacy.php` gains a "Submit a Data Subject Request" form
    (CSRF-protected, server-authoritative type allowlist, works without JS).
- **Multi-jurisdiction compliance — Layer 1c: admin queue + public form (G-004). This completes Layer 1.**
  - New admin DSAR queue at `/admin/compliance/dsar` (super-admin only) —
    filter/sort/paginate requests, overdue highlighting, a detail view with the
    full audit timeline, and fulfilment actions; every mutation is CSRF-checked,
    POST-only, `X-Frame-Options: DENY`, and recorded in the admin audit log.
  - New public "I can't log in" data-request form at `/legal/data-request`
    (CAPTCHA + anti-bot + rate-limited) that emails an identity-verification
    link. It **never reveals whether an email matches an account** — the
    response is identical in either case (user-enumeration protection).
  - Admin fulfilment of export/erasure never bypasses the account owner's
    password — those actions remain completable only by the data subject
    themselves; the queue tracks and routes them.
  - **Layer 1 (consent trail + DSAR engine + admin queue + public form) is now
    complete and usable.**
- **Multi-jurisdiction compliance — Layer 2: consent-management surface (G-004).**
  - The cookie banner is now **server-persisted and granular** — visitors accept
    or decline per category (necessary / functional / analytics / marketing;
    "necessary" can't be switched off), and the choice is stored server-side (the
    source of truth), not just in the browser. Categories are data-driven from a
    new table, and the banner **works with JavaScript disabled** (native form
    submit + a full-page fallback at `/legal/consent`).
  - **"Do Not Sell or Share My Personal Information"** — a new toggle on the
    privacy page plus a public `/legal/do-not-sell` page, and automatic honoring
    of the browser **Global Privacy Control (`Sec-GPC`)** signal (opt-out recorded
    once per session, never duplicated).
  - **Versioned re-consent** — when the current terms/privacy policy version
    changes, users can be prompted to re-accept on next login. This is
    **off by default** and enabled via a setting when the operator is ready.
  - No policy text is shipped — the new policy-version table carries version
    anchors only; wording remains the operator's/counsel's to provide.
- **Multi-jurisdiction compliance — Layer 3 core: data-driven regime model (G-004).**
  - New regime tables let every jurisdiction-specific value — a right, an SLA in
    days, an age threshold, a breach-notification window — live in **configuration,
    not code**. Adding a new jurisdiction is a data change; no PHP edits.
  - **19 jurisdictions are pre-seeded as drafts** (GDPR, UK GDPR, CCPA/CPRA, VCDPA,
    CPA, CTDPA, UCPA, HIPAA, COPPA, LGPD, PIPEDA, APPI, PIPA, POPIA, DPDP, PIPL, and
    the Australia/New Zealand Privacy Acts) — **inactive, with their legal values
    left blank** for the operator/counsel to fill and activate. Well-known public
    reference numbers are included only as clearly-labelled "confirm with legal"
    notes, never as live values.
  - A new resolver stamps each data-subject request and consent record with its
    governing regime and SLA, always falling back to the **most-protective default**
    when nothing is configured (it never fails open). With the shipped (all-draft)
    seed this is a no-op — existing behaviour is unchanged until a regime is
    activated.
  - A **super-admin regime-management console** (`/admin/compliance/regimes`) lets
    the operator fill each regime's legal values, rights, disclosures and
    resolution rules and then activate it. A regime **cannot be activated until its
    SLA, minimum age and breach-notification window are all filled in** (enforced
    server-side, re-reading the stored values), so a jurisdiction can never go live
    with blank legal values. Every change is CSRF-protected and audit-logged.
- **Multi-jurisdiction compliance — Layer 4a: retention + the compliance cron (G-004).**
  - A new **token-secured compliance cron** (`/cron/compliance.php`, run by your
    external scheduler) now runs the previously-dormant scheduled work: it executes
    due account deletions, cleans up expired data-export files, expires stale
    data-request verification tokens, and applies retention policies. Enable it by
    setting `compliance.cron.secret_token` (a strong random value) and pointing your
    cron at the endpoint.
  - **Data-retention policies** (`tblRetentionPolicies`) let you age out old data by
    table on a schedule (anonymise or delete). This ships **completely inert and
    safe**: retention purging is **disabled** and in **dry-run** mode by default, and
    the seeded policies are inactive — nothing is ever purged until you explicitly
    fill in a policy, activate it, and turn purging on. Purges are batch-capped and
    restricted to a hardcoded table/column allowlist.
- **Multi-jurisdiction compliance — Layer 4b: breach notifications, RoPA register,
  COPPA age-gate (G-004). Completes the epic — all four layers are now BUILT.**
  - A new **breach-notification log and admin workflow** (`/admin/compliance/breaches`,
    `tblBreachIncidents` + `tblBreachNotifications`) lets an operator open an
    incident and **compute** its per-regime notification deadlines — every deadline
    is `detectedAt + RegimeResolver::breachWindowHours(regime)`, never a hardcoded
    window. A regime with no configured window degrades gracefully to a `NULL` due
    date and an explanatory note rather than guessing or crashing. This tool
    **computes deadlines and tracks status only** — it never auto-files with a
    regulator and never writes notification copy for you.
  - A new **Records of Processing Activities (RoPA) register**
    (`/admin/compliance/ropa`, `tblProcessingActivities`) provides pure CRUD
    structure for your processing-activity inventory. It ships with **zero seeded
    rows** — every purpose, lawful basis, recipient list and retention period is
    entered by you or your DPO; nothing is fabricated or pre-filled.
  - A new **COPPA-style signup age gate** (`AgeGateService`), **OFF by default**
    (`compliance.age_gate.enabled = '0'`) — with the setting off, registration is
    byte-for-byte unchanged from before this release. When an operator enables it,
    the minimum age comes from `RegimeResolver::minAgeFor()` (never a hardcoded
    number), and an under-age signup routes to a **parental-consent email-link
    scaffold** (`tblParentalConsents`) rather than being silently allowed or
    silently blocked. The verification token is stored **only as its SHA-256
    hash** — the same pattern already used for DSAR identity verification —
    never the raw value.

### Fixed

- **Scheduled account deletions and export cleanup now actually run.** The
  grace-period account-erasure and expired-export-cleanup routines existed but
  nothing ever invoked them, so a confirmed account-deletion request was never
  carried out and old export files were never removed. The new compliance cron
  wires both in (you must set its secret token and schedule it).
- **GDPR data-export and account-deletion were broken and now work.** The
  `AccountManager` export/erasure engine had four latent defects (activity-log
  calls using parameters that don't exist, a malformed prepared-statement type
  string, and three queries referencing columns that don't exist), so the
  "Export My Data" and "Delete Account" actions on the privacy page failed on
  every use. All four are fixed and verified against the live database schema.

### Planned

- Mobile apps (iOS, Android)
- Advanced analytics and reporting dashboard
- SIGNula as IdP — SAML 2.0 / OIDC provider (G-001, **provider + iHymns integration shipped**; SAML later)
- Recurring billing engine with dunning (G-002, **built — TEST-MODE only**; go-live is the owner's #70 step)
- JWT bearer-auth for API (G-003, **shipped in v2.8.0-beta** — see below)
- Multi-jurisdiction compliance tooling — consent log, DSAR engine, regime model, breach/RoPA/retention (G-004, **BUILT — all four layers shipped**: L1 DSAR + consent, L2 consent-management surface, L3 data-driven regime model, L4a retention + compliance cron, L4b breach notifications + RoPA register + COPPA age-gate)

---

## [2.7.0-beta] — 2026-07-01

> **Version note:** The `VERSION` file currently reads `2.6.0-beta`; all feature entries since the credential-reset phase have been logged under `2.7.0-beta`. The current working version is treated as **2.7.0-beta** pending a formal tag.

### Fixed — Foundation Hardening (autopilot/2026-06-30, cycles 3-12)

The automated hardening run on branch `autopilot/2026-06-30` discovered that several core subsystems were silently non-functional despite the unit-test suite (which stubs the database) reporting green. The following fixes were applied before the feature campaign could proceed.

#### Core Flow Fixes

- **User registration broken** — `tblUsers.organizationID` column was missing; registration INSERT failed for every new user. Fixed by migration `025_registration_fix.sql`.
- **Email queue non-functional** — `EmailService::queueEmail()` had a bind-parameter count mismatch; every call silently failed. Fixed — email queuing now works end-to-end.
- **Backup-code MFA broken** — ENUM value mismatch on backup-code verification path prevented successful MFA login via recovery codes.
- **Error logging broken at 61 call sites** — `ErrorLogger::log()` was called at ~61 locations but the method was undefined; all server-side error logging was silently swallowed. Fixed.
- **`Database::getAffectedRows()` always returned -1** — the accessor read `affected_rows` after `$stmt->close()` (mysqlnd returns -1 post-close). Fixed in `database.php` by capturing the value while the statement is still open. This repaired 12 silently-broken callers across WebAuthn, UsageTracker, PasswordlessLogin, WebhookService, and NotificationService.
- **MFA and notification activity logging wrote garbage** — `MFA.php` (15 sites) and `NotificationService` (5 sites) had misordered `log()` arguments, writing the human-readable description into the `activityCategory` ENUM column. This caused data-truncation errors and corrupt audit entries. Fixed; `activityCategory` ENUM widened in migration `029_enum_widening.sql` to match all valid values written by the codebase.

#### WebAuthn

- **Registration non-functional** — `Database::insert()` method was missing entirely; WebAuthn credential saves and 6 other callers (PasswordlessLogin, AccountManager, 4 email-related) all failed silently. Added `Database::insert()` and `Database::insertId()` (cycle 5).
- **Auth-bypass closed (FG-013 / security — see Security section below)** — challenge-consumption TOCTOU hardened with atomic compare-and-set (B-030).

#### Fresh-install / Database

- **Consolidated installer had 74 SQL errors** — INT/BIGINT FK type drift across 96 columns, `tblMigrations`/`tblSettings` schema drift, duplicate WebAuthn table definitions, missing FK targets. All resolved; installer now applies with **0 errors**. Migrations 025/026 added missing registration and MFA columns (cycle 7). Migrations 028 adds the 6 multi-org tables that `Organization.php` requires (cycle 11).
- **Installer excluded from git** — `_database/*.sql` was in `.gitignore`, meaning the installer file was absent from a clean checkout. Unignored and committed (cycle 9).
- **Install-wizard idempotency** — the wizard re-applied migrations 001-014 on every re-run because `tblMigrations` was seeded with only 3 filenames. Fixed by seeding all 17 baked migration filenames as `completed` (cycle 11).
- **`setup_test_db.sh` silently skipped migration 014** — its 5 security tables were absent from the test DB; fixed so the test database now reflects the full 98-table schema (cycle 11).

#### Integration Test Gate

- **Suite was unreliable (B-037 flake)** — `ActivityLogger::log()` writes outside the test transaction on the shared DB singleton; tests used hard-coded `userID=1` which a prior test truncated, causing FK failures. Fixed with committed-test-user hermeticity and `AuthLoginTest` truncating `tblRateLimits` before each run. Integration gate is now deterministically green (5/5 independent runs + 20/20 builder runs, cycle 12).
- **`session_start` warning flake** — root-caused and fixed; suite now runs with **0 warnings** across 30+ consecutive runs (cycle 8).

#### Tooling

- Fixed `composer analyze` / `check-style` invocations — paths to `private_html` were unresolved from repo root (B-001/B-002, cycle 3).
- Added `SIGNULA_INIT` guard to 5 source files that were reachable without the bootstrap constant (cycle 3).

### Added

- Migrations `025_registration_fix.sql`, `026_mfa_columns.sql`, `027_credential_reset_indexes.sql` (composite indexes on credential-reset tables, cycle 8), `028_multi_org_tables.sql` (6 org tables, cycle 11), `029_enum_widening.sql` (activityCategory ENUM expansion, cycle 12).
- `Database::insert()` and `Database::insertId()` methods.
- `Database::getAffectedRows()` now returns the correct row count for prepared UPDATEs.

### Security

- **WebAuthn auth-bypass closed (FG-013)** — WebAuthn `auth-verify` previously accepted assertions without verifying the authenticator signature. Replaced with real CBOR/COSE decoding, PEM extraction, and `openssl_verify()`. Sign-count clone-detection added. Red-team verified across 7 attack vectors (cycle 6). Tracked by issue #73 (addressed on branch, not yet pushed).
- **CORS `*` + credentials** — API CORS headers previously sent `Access-Control-Allow-Origin: *` combined with `Access-Control-Allow-Credentials: true` (invalid per spec; browsers may allow in some modes). Fixed: origin is now checked against an allowlist (`api.cors.allowed_origins` setting). Default: same-origin only (B-024, cycle 8).
- **X-Frame-Options / CSP frame-ancestors / X-Content-Type-Options** — added to all API responses (issue #29, cycle 8).
- **Admin CSRF — two holes** — primary admin CSRF fix applied; a second CSRF hole on the rate-limits unblock endpoint was found and also closed during cycle 8.
- **13 deferred MEDIUM issues (#22-#34)** resolved (cycle 8):
  - Email recipient validation before send (#22)
  - AMP sender allowlist enforced (#23)
  - SMTP encryption-before-AUTH enforced (no plain-text credential submission) (#24/#25)
  - `email.xmailer` setting suppressed when blank (#26)
  - Credential-reset authorisation tightened (non-super-admin paths blocked) (#27)
  - Credential-reset pagination result-set bounded (#28)
  - Credential-reset DB indexes added (migration 027) (#29 duplicate numbering — see B-030 above; tracked in backlog)
  - Token URL-encoding applied at 4 sites where hex tokens appeared in URLs (defensive; B-039, cycle 12)
- **WebAuthn challenge consumption hardened** — atomic compare-and-set prevents TOCTOU race on challenge records (B-030/B-031, cycle 8).

### Tests

- +48 characterisation tests added (cycles 3-4, 342 → 390 total).
- Integration suite made reliable and runnable end-to-end (cycle 7); now 71 green / 0 failures (cycle 10).
- Unit suite: 407 green / 0 warnings (cycle 8 onwards).

---

### Added - JWT Bearer Authentication for REST API (v2.8.0-beta, G-003)

**JWT Bearer Authentication — RS256, JWKS, rotating refresh tokens, revocation (closes #41)**

- `web/private_html/security/Jwt.php` — RS256 sign/verify facade over vendored `firebase/php-jwt` v6.x. Algorithm pinned to RS256 on both sign and verify; `alg:none` and RS256→HS256 confusion attacks are rejected by design (the `Key` object ties key and algorithm together before signature verification). `kid` emitted in the JWT header; denylist checker injectable for unit-test isolation. Single swap point for the underlying library.
- `web/private_html/security/KeyManager.php` — RSA keypair lifecycle (`generateKey`, `rotateKey`, `retireKey`, `getActiveKey`, `getPublicKey`, `getJwks`). Private PEM stored AES-256-CBC encrypted (`isSensitive=1`) in `tblSettings`, with a `_private/keys/jwt/<kid>.key` (0600) file fallback. Public JWK stored unencrypted in `tblSettings` for cheap JWKS reads. `kid` validated against a strict charset before any filesystem use (path-traversal defence). In-memory storage override for unit tests (no DB required).
- `web/private_html/security/TokenService.php` — Stateful token lifecycle: `issueTokens()` (new family), `refresh()` (single-use rotation + **reuse detection** — replaying a spent or revoked refresh token revokes the entire family and raises a `TYPE_SESSION_HIJACK` HIGH security alert), `revokeAccessJti()` (self-expiring denylist entry), `revokeFamily()`, `revokeAllForUser()` (bumps `tblUsers.tokensInvalidBefore`), `verifyAccessToken()` (wires denylist + `tokensInvalidBefore` cutoff). Refresh-token plaintext is returned once and never stored — only the SHA-256 hash persists (mirrors `tblAPIKeys.keyHash` / C5).
- `web/private_html/api/controllers/JwtAuthController.php` — HTTP surface: `POST /api/v1/auth/token` (session bootstrap or email+password grant; MFA gate; per-IP + per-identifier rate limiting), `POST /api/v1/auth/refresh` (rotation; per-IP rate limiting), `POST /api/v1/auth/revoke` (RFC 7009 shape; idempotent 200). `userID` is **always** resolved from a server-verified identity — no client-supplied `sub` ever reaches token issuance. `Cache-Control: no-store` on all token responses.
- `web/public_html/.well-known/jwks.json/index.php` — JWKS public-key document. Unauthenticated; `Cache-Control: public, max-age=3600`. Strips any private JWK component (`d`, `p`, `q`, `dp`, `dq`, `qi`) as a defence-in-depth measure before output. Shared contract for G-001 relying parties.
- `web/_lib/jwt/src/` — Vendored `firebase/php-jwt` v6.x source (MIT licence, zero runtime Composer deps). Version pinned in `web/_lib/jwt/VERSION`; loaded via `require_once` with `file_exists()` guards per house style.
- `_database/migrations/030_jwt_authentication.sql` — New tables `tblRefreshTokens` (rotating, family-based; SHA-256 hashed) and `tblRevokedTokens` (self-expiring `jti` denylist). New column `tblUsers.tokensInvalidBefore` (mass-revocation lever). 14 `jwt.*` policy settings seeded via `INSERT IGNORE`. Daily `cleanup_jwt_tokens` MySQL EVENT purges expired denylist rows.
- `BaseController::getUserByToken()` stub replaced with full implementation: `Jwt::verify()` + `sub` user load + `accountStatus` + `tokensInvalidBefore` check. Sets `auth_method='jwt'`, `scope`, and `jti` on `currentUser`. API-key and session auth paths unchanged (regression-safe).
- OpenAPI spec (`web/public_html/api/docs/openapi.yaml`) updated with `POST /auth/token`, updated `POST /auth/refresh`, `POST /auth/revoke`, `GET /.well-known/jwks.json`, and new schemas (`JwtTokenRequest`, `JwtTokenResponse`, `JwtRefreshRequest`, `JwtRefreshResponse`, `JwtRevokeRequest`, `JwksResponse`, `JwksKey`).

**Security — red-team hardening (G-003 §7, closes #41)**

- Algorithm pinned (`alg:none` rejected; RS256→HS256 confusion rejected) — highest-priority defence per CVE class.
- `kid` injection blocked — `kid` from JWT header used only to select from our own JWKS set; `isValidKid()` rejects non-path-safe values before any filesystem concatenation.
- Refresh-token reuse detection — family-wide revocation on replay; raises `SecurityAlertManager::TYPE_SESSION_HIJACK` HIGH alert.
- `jti` denylist with self-expiring rows — bounded table size (access TTL ≤ 15 min).
- `tokensInvalidBefore` mass-revocation lever on `tblUsers` — "log out everywhere" without enumerating jtis.
- No token material (access tokens, refresh tokens, signing keys, `Authorization` header) in any log output.
- Windowed rate limiting on `/auth/token` (per-IP + per-identifier) and `/auth/refresh` (per-IP) using existing `SecurityUtils::checkRateLimit()`.

---

### Added - Tier Expansion, Custom Tiers, Usage Export & Installation Wizard (v2.7.0-beta)

**Subscription Tier Expansion**
- Migration `019_tier_expansion.sql`: Adds Pro and Platinum default tiers (6-tier lineup: Free, Basic, Premium, Pro, Platinum, Enterprise). Adds custom tier support columns (isCustom, createdByPartnerID, parentTierID, usageLimits, billingMode).
- Updated pricing page (`SIGNula.com/pricing.php`) with all 6 tiers, GBP pricing, expanded comparison table, usage-based billing FAQ.
- Updated `signula_complete_install_v2.5.0.sql` with new tier data and schema.

**Custom Tier Management**
- `web/public_html/admin/api/tier-actions.php` — Admin AJAX API for CRUD operations on subscription tiers. 9 actions: get_tiers, get_tier, create_tier, update_tier, delete_tier, activate_tier, set_default, reorder_tiers, duplicate_tier.
- `web/public_html/admin/payments/tiers.php` — Admin UI for managing all subscription tiers. Global and custom tier tabs, create/edit modal with live preview, settings management.

**Data Export System**
- `web/private_html/utils/ExportService.php` (~1,500 lines) — Multi-format export utility. CSV (client-side with BOM), Excel XLSX (ZipArchive-based Office Open XML), PDF (TCPDF with HTML fallback), Google Sheets (OAuth2 + Sheets API v4), Excel Online (Microsoft Graph API).
- `web/public_html/api/v1/export/index.php` — Server-side export endpoint (Excel/PDF).
- `web/public_html/api/v1/export-cloud/index.php` — Cloud export OAuth redirect (Google Sheets/Excel Online).
- Export dropdowns added to all usage dashboards (user, admin, partner).

**Installation/Upgrade Wizard**
- `web/public_html/install/index.php` (~2,000 lines) — 6-step web-based installation wizard: system requirements check, database configuration, migration runner, initial config, admin account creation, completion with lock file.
- `web/public_html/install/upgrade.php` (~900 lines) — Database upgrade wizard with migration detection, progress tracking, and rollback.
- `web/public_html/install/.htaccess` — Blocks access after installation complete.

**OpenAPI Specification v2.0**
- Added 3 new tag groups: Usage & Billing, Subscription Tiers, Data Export.
- Documented 12 new endpoints: usage recording, current/history/projected-cost, billing summaries, metrics, billing mode, tier listing, data export, cloud export.
- Added SubscriptionTier schema with all tier properties.

**Security Fixes**
- Fixed API key plaintext comparison in UsageController (now uses SHA-256 hash via keyHash column).
- Fixed OAuth state parameter not stored in session for CSRF validation (export-cloud).
- Fixed HTTP_HOST header injection risk (now uses SERVER_NAME or site.url setting).
- Fixed exception message leakage in tier-actions.php error handler.

**GitHub Issues**
- Created #48 (tier expansion) — closed after implementation.
- Created #49 (installation wizard) — closed after implementation.

### Added - Mass Credential Reset System & Email Enhancement (v2.6.0-beta)

**New Admin Service Class**
- `CredentialResetService.php` (~600 lines) — Mass credential reset operations for security breach response. Three reset types: mass password reset, salt rotation, full credential reset. Three scope modes: all users, filtered by status/tier, specific users. Batch processing with configurable batch size. Global and per-user salt rotation with encrypted audit trail. Session invalidation for affected users. Token generation via SecurityUtils for password reset links. Compliance reporting and non-compliant user tracking.

**New Email Utility Class**
- `EmailTemplateBuilder.php` (~500 lines) — Modern HTML email builder with AMP for Email support. Responsive HTML email layouts with dark mode (`prefers-color-scheme`), MSO conditional comments for Outlook, preheader text support. AMP4Email document generation with component loading. MIME multipart assembly (text/plain → text/x-amp-html → text/html per RFC 2046 §5.1.4). Intelligent HTML-to-plain-text conversion. Content helpers: buttons, alert boxes, info tables. Email headers: X-Mailer, AMP indicator, List-Unsubscribe (RFC 2369/8058).

**New Admin UI**
- `web/public_html/admin/security/credential-reset.php` — 3-step wizard for mass credential resets: select type → configure scope/reason → progress tracking. AJAX-based batch processing with real-time progress bar. Confirmation dialog requiring typed "CONFIRM RESET". Operation history table with compliance report viewing.

**Database Migration**
- `017_credential_reset_system.sql` — 3 new tables: `tblCredentialResets` (operation tracking), `tblCredentialResetUsers` (per-user status), `tblSaltRotationHistory` (salt audit trail). 15 new settings (6 credential reset + 9 email enhancement). 3 responsive HTML email templates: security_breach_alert, mass_password_reset, credential_reset_complete (all with dark mode support).

**EmailService Enhancements (2 new methods)**
- `sendRichEmail()` — Send HTML email with optional AMP body and auto-generated plain text fallback
- `sendSecurityAlertEmail()` — High-priority security notification email for breach alerts

**SMTP Provider Enhancement**
- `SMTPEmailProvider.php` — Enhanced `buildEmailMessage()` to support AMP email parts (text/x-amp-html MIME type) and extra headers. MIME ordering per RFC 2046 §5.1.4.

**Admin API Handlers (7 new actions)**
- `initiate_mass_reset` — Start a mass credential reset operation (with confirmation flow)
- `process_reset_batch` — Process next batch of users in an operation
- `get_reset_status` — Get operation progress with compliance stats
- `list_reset_operations` — Paginated list of all reset operations
- `cancel_reset` — Cancel an in-progress operation
- `get_compliance_report` — Detailed compliance reporting
- `get_salt_history` — Salt rotation audit trail

**Unit Tests (40 new tests)**
- `CredentialResetServiceTest.php` (10 tests) — Constants validation, parameter validation (invalid type/scope/reason), valid types/scopes pass validation, settings existence
- `EmailTemplateBuilderTest.php` (30 tests) — HTML-to-text conversion (9 tests), HTML layout wrapping (8 tests), AMP email generation (4 tests), multipart assembly (3 tests), content helpers (7 tests), email headers (4 tests), style constants (1 test)

**Test Fixtures**
- Updated `_tests/Fixtures/settings.json` with 14 new settings (6 credential reset + 8 email enhancement)

### Added - Avatar & Profile Picture System (v2.6.0-beta)

**New Utility Class**
- `AvatarService.php` (~750 lines) — Complete avatar resolution, upload processing, and fallback service support. Priority chain: partner-specific upload → global SIGNula upload → primary OAuth avatar → fallback services (user-configurable order) → SVG initials avatar. Five external fallback services: Gravatar, Libravatar, UI Avatars, DiceBear, RoboHash. Secure file upload with finfo MIME validation, getimagesize check, GD re-encoding (strips EXIF/payloads), multi-size generation (32/48/64/96/128/256px).

**New Endpoint**
- `web/public_html/avatar/` — Avatar serve endpoint via userUUID (prevents ID enumeration), HTTP caching with ETag/Last-Modified/304 support, .htaccess URL rewriting

**Database Migration**
- `016_avatar_support.sql` — New `tblUserAvatars` table (partner-aware, supports upload/oauth/url types), 11 new avatar settings in tblSettings

**UI Updates**
- `profile.php` — Full avatar management section: upload/remove avatar, use OAuth provider pictures, drag-and-drop fallback priority (Sortable.js), live preview of each service
- `account.php` — "Use as Avatar" button on linked OAuth accounts, navbar avatar display
- `public-header.php` — User avatar in authenticated navbar (replaces FontAwesome icon), CSS initials fallback
- `organization/members.php` — Member avatars via AvatarService::resolve()
- `blog/index.php` + `blog/post.php` — Author/commenter avatars via AvatarService (replaces hardcoded Gravatar)

**API Endpoints (5 new)**
- `POST /api/v1/user/avatar` — Upload avatar (multipart/form-data)
- `DELETE /api/v1/user/avatar` — Remove avatar
- `PUT /api/v1/user/avatar/source` — Set avatar source (OAuth)
- `GET /api/v1/user/avatar/sources` — Get available avatar sources
- `PUT /api/v1/user/avatar/fallback-priority` — Update fallback order
- `GET /api/v1/user/profile` — Now includes `avatar_url` and `avatar_urls` (small/medium/large)

**Unit Tests (40 new tests)**
- `AvatarServiceTest.php` — URL generation for all 5 services, SVG initials (extraction, unicode, deterministic colour), configuration accessors, cross-service validation

**Test Fixtures**
- Updated `_tests/Fixtures/settings.json` with all 11 avatar settings

### Added - Local Form Protection, HTML5 Validation & Security Hardening (v2.6.0-beta)

**New Security Class**
- `FormProtection.php` — Local bot protection with three always-on layers: honeypot field (CSS-hidden, aria-accessible), HMAC-signed timing validation (rejects forms submitted under configurable threshold), JavaScript challenge (graceful degradation for non-JS browsers)

**Security Fixes**
- `profile.php` — Added missing CSRF token verification on both forms (update_profile, change_email); fixed XSS vulnerability in error message output (`implode` without escaping); upgraded input sanitization from `trim()` to `SecurityUtils::sanitizeString()`
- `register.php` — Fixed unescaped `confirm_password` error output (XSS vector)
- `contact.php` — Upgraded input sanitization from `trim()` to `SecurityUtils::sanitizeString()` / `SecurityUtils::sanitizeEmail()`

**HTML5 Form Validation**
- `login.php` — Added `minlength="3"` `maxlength="255"` to identifier, `minlength="8"` to password
- `register.php` — Added `maxlength="100"` to name fields, `maxlength="255"` to email, `minlength="8"` to password fields
- `forgot-password.php` — Added `maxlength="255"` to email
- `profile.php` — Added `minlength`, `maxlength`, `autocomplete` attributes to all form fields

**SecurityMiddleware Pipeline Update**
- Added FormProtection as Step 2 in form submission pipeline: Rate Limiting → **Form Protection** → CAPTCHA → Process

**Database Migration**
- `015_form_protection_settings.sql` — 2 new settings: `security.form_protection.enabled` (boolean, default 1), `security.form_protection.min_submit_time` (integer, default 3)

**Unit Tests (22 new tests)**
- `FormProtectionTest.php` — isEnabled toggling, render output validation, honeypot/timing/JS challenge validation, HMAC tampering, graceful degradation for non-JS browsers

**Test Fixtures**
- Updated `_tests/Fixtures/settings.json` with `security.form_protection.enabled` and `security.form_protection.min_submit_time`

### Added - Security Enhancements: CAPTCHA, IP Reputation, Bot Detection, Session Fingerprinting & Alerts (v2.6.0-beta)

**New Security Classes (6 files)**
- `CaptchaVerifier.php` — CloudFlare Turnstile (primary) + Google reCAPTCHA v3 (fallback), fail-open behaviour, per-form toggling
- `IPReputationChecker.php` — AbuseIPDB + proxycheck.io integration with database caching, circuit breaker pattern, IP blocklist management
- `BotDetector.php` — CrawlerDetect library + Browscap + built-in regex patterns, good/bad bot classification, optional DNS verification
- `SessionGuard.php` — SHA-256 session fingerprinting (IP subnet/exact/none + UA + headers + salt), timing-safe validation
- `SecurityAlertManager.php` — 11 alert types, 4 severity levels, brute force/impossible travel/password spray detection, admin email notifications, Haversine distance calculation
- `SecurityMiddleware.php` — Unified pipeline orchestrating IP blocklist → bot detection → IP reputation (page requests) and rate limiting → CAPTCHA (form submissions)

**Database Migration**
- `014_security_enhancements.sql` — 5 new tables (tblIPReputationCache, tblBlockedIPs, tblSecurityAlerts, tblSessionFingerprints, tblCircuitBreaker), ~30 new settings, 4 rate limit configs, 4 MySQL scheduled events

**Third-Party Library**
- `web/_lib/crawlerdetect/crawlerdetect_loader.php` — Loader for CrawlerDetect library (MIT, no Composer required)

**Integration Changes**
- `config.php` — Updated CSP headers (Turnstile + reCAPTCHA domains), expanded autoloader paths, SessionGuard hook in session init, SecurityMiddleware bootstrap hook
- `login.php` — CAPTCHA widget + SecurityMiddleware form handling + brute force/impossible travel/password spray alerts
- `register.php` — CAPTCHA widget + SecurityMiddleware form handling
- `forgot-password.php` — Replaced manual rate limiting with SecurityMiddleware + CAPTCHA
- `contact.php` — Refactored from manual session handling to config.php bootstrap + SecurityMiddleware + CAPTCHA
- `public-header.php` — Added CAPTCHA scripts tag

**Unit Tests (104 new tests, 197 assertions)**
- `CaptchaVerifierTest.php` — isRequired toggling, getProvider fallback, renderWidget HTML, getRequiredScripts, verify with disabled CAPTCHA
- `BotDetectorTest.php` — Good/bad bot detection, empty UA handling, shouldBlock logic, pattern fallback
- `SessionGuardTest.php` — Fingerprint creation/validation, IP mode (exact/subnet/none), mismatch handling, refresh
- `SecurityAlertManagerTest.php` — Constants, isEnabled, create/acknowledge/resolve without Database, brute force threshold
- `IPReputationCheckerTest.php` — Private IP bypass, whitelist, isBlocked/blockIP/unblockIP without Database, reportAbuse prerequisites
- `SecurityMiddlewareTest.php` — handleFormSubmission return structure, CAPTCHA toggling, graceful degradation

**Test Fixtures**
- Updated `_tests/Fixtures/settings.json` with all new security settings (captcha.*, security.ip_reputation.*, security.bot_detection.*, security.session_fingerprinting.*, security.alerts.*)

### Added - Automated Test Suite & Database Consolidation (Phase 10)

**PHPUnit 10.x Test Infrastructure**
- Upgraded `phpunit.xml` from PHPUnit 9.5 to 10.5 schema
- Enhanced `_tests/bootstrap.php` with SIGNULA_INIT constant, global function stubs (getSetting, getClientIP, getUserAgent, redirect, jsonResponse, sanitizeInput), test helpers
- Updated `_tests/TestCase.php` with session guards, settings cleanup, PHPUnit 10 compatibility
- Added `_tests/Fixtures/settings.json` with default test settings

**Unit Tests (130 tests, 610+ assertions)**
- `SecurityUtilsTest.php` (48 tests) — Encryption/decryption, Argon2id hashing, token generation, CSRF, password validation, sanitization
- `TOTPTest.php` (25 tests) — Secret generation, RFC 6238 test vectors, code verification, provisioning URI, Base32 validation
- `ValidatorTest.php` (40+ tests) — Required, type, length, format rules, pipe-delimited parsing, API surface
- `PasswordValidationTest.php` (17 tests) — Data-driven tests using SecurityUtils::validatePassword(), PHPUnit 10 #[DataProvider] attributes

**Integration Tests (46 tests written)**
- `AuthLoginTest.php` (15 tests) — Login credentials, lockout, session management, registration
- `MFATest.php` (12 tests) — TOTP enable/verify/activate, backup codes (generate, verify, single-use), disable
- `ActivityLoggerTest.php` (6 tests) — Record creation, IP/UA capture, JSON metadata, null userID
- `ErrorLoggerTest.php` (5 tests) — Error records, backtrace capture, sensitive field redaction
- `RateLimiterTest.php` (8 tests) — Enabled check, rate limits, remaining count, unblock, progressive blocking

**Database Consolidation**
- New `_database/signula_complete_install_v2.5.0.sql` — Consolidated from v2.2.3 base + migrations 010-013
- Updated `_scripts/build-complete-install.sh` — Version references and migration list (001-013)

**Testing Documentation**
- `_docs/testing/TESTING_AUTOMATED.md` — PHPUnit setup, running tests, adding new tests, coverage generation

---

## [2.5.0-beta] - 2026-02-18

### Added - Ko-fi & Patreon Payment Providers + GitHub Infrastructure

**Database Migration 013** (`_database/migrations/013_kofi_patreon_providers.sql`)
- Expanded `provider` ENUM in tblPartnerPaymentConfig: added `'kofi'`, `'patreon'`
- Expanded `paymentMethod` ENUM in tblPayments: added `'kofi'`, `'patreon'`
- 7 Ko-fi settings (payment.kofi.*) including encrypted verification token
- 11 Patreon settings (payment.patreon.*) including encrypted OAuth credentials
- 2 provider discount entries (kofi, patreon — inactive by default)
- 2 feature toggles: `kofi_payments`, `patreon_payments`
- 4 email templates: kofi_donation_received, kofi_subscription_started, patreon_pledge_created, patreon_pledge_cancelled

**New Payment Provider Classes** (2,374 lines total)
- **KofiProvider.php** (1,073 lines) — Webhook-only flow, verification_token auth, handles Donation/Subscription/Shop Order
- **PatreonProvider.php** (1,301 lines) — OAuth 2.0 API v2, HMAC-MD5 webhooks, campaign tier management, member pledge tracking

**New Webhook Receivers** (653 lines)
- `web/public_html/webhooks/kofi.php` (333 lines) — Ko-fi webhook endpoint with form-encoded POST handling
- `web/public_html/webhooks/patreon.php` (320 lines) — Patreon webhook endpoint with X-Patreon-Signature verification

**Admin UI Updates**
- Updated `providers.php` — Added Ko-fi (coral #ff5e5b) and Patreon (#f96854) configuration cards
- Updated `provider-actions.php` — Added kofi/patreon to save, test, and webhook log handlers

**Testing Documentation** (3 new guides)
- `_docs/testing/TESTING_LOCAL_ACCOUNTS.md` — Account registration, login, MFA, passkeys, passwordless testing
- `_docs/testing/TESTING_THIRD_PARTY_LINKING.md` — OAuth provider linking tests (Google, Microsoft, Apple, Facebook)
- `_docs/testing/TESTING_API_INTEGRATION.md` — API setup, authentication, endpoints, webhook, code examples (PHP/JS/Python/cURL)

**GitHub Repository Infrastructure**
- Issue templates: bug report, feature request, documentation (YAML forms)
- Pull request template with checklist
- SECURITY.md — Vulnerability reporting policy
- CONTRIBUTING.md — Development setup, code style, PR process
- Custom labels: priority, type, status, component (20+ labels)

**GitHub Wiki** (9 pages)
- Home, Getting Started, Configuration Guide, API Integration Guide
- Authentication Setup, Payment Configuration, Security Best Practices, Troubleshooting
- Sidebar navigation

---

## [2.4.0-beta] - 2026-02-13

### Added - Two-Tier Payment System Expansion

This release adds a comprehensive two-tier payment system enabling partners to collect payments from THEIR customers through SIGNula, alongside the existing direct payment system.

**Database Migration 012** (`_database/migrations/012_payment_expansion.sql` — 1,072 lines)
- 11 new tables: tblPartnerPaymentConfig, tblServiceFees, tblServiceFeeTransactions, tblRemittances, tblCreditBalances, tblCreditTransactions, tblInvoices, tblProviderDiscounts, tblPartnerSubscriptionTiers, tblBillingSchedule, tblDiscountCodeAssignments
- 4 ALTER TABLE statements (tblPayments, tblDiscountCodes, tblPartners, tblSubscriptions)
- 28 new settings in tblSettings (service fees, credits, invoicing, auto-suspension, remittance, cron)
- 4 feature toggles (partner_payments, credit_system, partner_custom_tiers, pdf_invoices)
- 9 email templates (payment_receipt, payment_failed, payment_reminder, subscription_suspended, subscription_restored, service_fee_change, remittance_processed, credit_topup, invoice_issued)
- 4 MySQL scheduled events (mark past_due, expire trials, mark invoices overdue, cleanup billing schedule)

**New Backend Classes** (11,065 lines total)
- **InvoiceManager.php** (2,157 lines) — Invoice CRUD, PDF generation via TCPDF, email, HTML rendering, status transitions
- **CreditManager.php** (2,068 lines) — Credit balance management with row-level locking (SELECT ... FOR UPDATE)
- **ServiceFeeManager.php** (1,653 lines) — Fee calculation, fee schedules, remittance processing, earnings reports
- **BillingScheduler.php** (2,270 lines) — Scheduled billing task processor (subscription charges, reminders, auto-suspension, trial expiration)
- **PartnerPaymentService.php** (2,630 lines) — Level 2 payment orchestration, partner tier management, API key resolution
- **BillingLazyCheck.php** (287 lines) — Tier 3 billing safety net (processes tasks if cron stale >1 hour)

**Modified Backend Classes** (5 files)
- **StripeProvider.php** v1.1.0 — Added optional `$credentials` parameter for partner API keys
- **PayPalProvider.php** v1.1.0 — Added optional `$credentials` parameter + per-client-ID token cache
- **CoinbaseProvider.php** v1.1.0 — Added optional `$credentials` parameter for partner API keys
- **PaymentManager.php** v1.1.0 — Added `partnerID`/`paymentContext` to `recordPayment()`, invoice creation in `completePayment()`, country-based discount validation, provider discounts
- **AccessControl.php** — Added `canManagePartnerPayments()`, `canViewPartnerFinancials()`, `requirePartnerPaymentAccess()`, `requirePartnerFinancialAccess()`

**Super Admin Payment UI** (9,886 lines — 6 pages)
- `/admin/payments/service-fees.php` — Fee schedule management with 30-day minimum notice
- `/admin/payments/invoices.php` — Invoice list, view, download PDF, mark paid/void, reissue
- `/admin/payments/credits.php` — Credit balances and manual adjustments
- `/admin/payments/remittances.php` — Pending payouts queue, batch processing, earnings reports
- `/admin/payments/billing-schedule.php` — Scheduler health, pending/failed tasks, auto-refresh
- `/admin/payments/provider-discounts.php` — Per-provider discount rates (global + partner overrides)

**Super Admin Payment APIs** (5,348 lines — 6 API files)
- `service-fee-actions.php`, `invoice-actions.php`, `credit-actions.php`, `remittance-actions.php`, `billing-schedule-actions.php`, `provider-discount-actions.php`

**Partner Admin Payment UI** (8,926 lines — 6 pages)
- `/partners/admin/payment-config.php` — Choose Option 2a/2b per provider, enter API keys, test connection
- `/partners/admin/tiers.php` — Partner-defined subscription tiers CRUD
- `/partners/admin/earnings.php` — Revenue dashboard, fee breakdown, payout preferences
- `/partners/admin/invoices.php` — View/download invoices
- `/partners/admin/credits.php` — Credit balance, top-up, transaction history
- `/partners/admin/discounts.php` — Discount codes with country restrictions, per-account assignments

**Partner Admin Payment APIs** (4,738 lines — 5 API files)
- `payment-config-actions.php`, `tier-actions.php`, `earnings-actions.php`, `credit-actions.php`, `discount-actions.php`

**Web-Accessible Cron Endpoints** (425 lines)
- `/cron/billing.php` — Token-authenticated billing task processor (subscription charges, reminders, suspensions)
- `/cron/remittance.php` — Token-authenticated partner payout processor

**Invoice Routes** (340 lines)
- `/invoices/view/` — HTML invoice viewing (auth + ownership check)
- `/invoices/download/` — PDF download with HTML fallback

**TCPDF Integration**
- `web/_lib/tcpdf/tcpdf_loader.php` — Loader with graceful fallback for PDF invoice generation

### Architecture — Two-Tier Payment Model

- **Level 1**: Partners/customers pay SIGNula directly for premium tiers
- **Level 2a**: Partners use their OWN payment provider API keys (~10% service fee)
- **Level 2b**: Partners use SIGNula's keys (~30% fee, remainder remitted)
- **Three-tier billing redundancy**: MySQL events (hourly) + Web cron (every 5-15 min) + Lazy check safety net
- **Auto-suspension/auto-resume**: Configurable grace period, tier preservation, automatic restoration on payment

### Stats

- **~41,969 new lines of code** across 34 new files
- **5 modified files** (provider classes + PaymentManager + AccessControl)
- **46 database tables** total (11 new from migration 012 + 35 core from v2.2.3 complete install)
- **3 views**, **2 triggers**, **4 MySQL scheduled events**
- All files pass PHP lint validation

---

## [2.3.0-beta] - 2026-02-11

### Added - Webhook Signature System (HMAC-SHA256)

- **WebhookManager.php** (~650 lines) - Outbound webhook delivery system
  - HMAC-SHA256 signing with `whsec_` prefixed secrets
  - Signature format: `X-SIGNula-Signature: sha256=<hmac>` with timestamp replay protection
  - Exponential backoff retry (base x 2^attempt + jitter, max 24h)
  - Auto-disable endpoints after configurable consecutive failures
  - Per-endpoint event subscription filtering
  - Delivery statistics and logging
- **Partner Webhook Management UI** (`/partners/admin/webhooks/`)
  - CRUD for webhook endpoints with event subscription picker
  - Secret regeneration with one-time display and copy-to-clipboard
  - Send test webhook, view delivery log with success rate
  - Inline PHP signature verification example
- **Partner Webhook API** (`/partners/api/webhook-actions.php`)
  - Actions: list, create, update, delete, regenerate_secret, test, deliveries, stats

### Added - Payment and Subscription System

- **Database migration 010** (`_database/migrations/010_webhooks_and_payments.sql`)
  - 7 new tables: tblWebhookEndpoints, tblWebhookDeliveries, tblSubscriptionTiers, tblSubscriptions, tblPayments, tblPaymentMethods, tblDiscountCodes
  - 4 default subscription tiers (Free 0, Basic 9.99/mo, Premium 29.99/mo, Enterprise 99.99/mo GBP)
  - 27 settings entries (8 webhook + 19 payment) in tblSettings
  - Scheduled event for webhook delivery cleanup (30-day retention)
- **PaymentManager.php** (~600 lines) - Payment and subscription management
  - Tier management, subscription lifecycle (create/cancel/pause/resume)
  - Payment recording, completion, and refund processing
  - Discount code validation with percentage/fixed/trial types
  - Tokenised payment method management
  - Invoice number generation, tax/VAT calculation
  - Admin statistics with date-range filtering
- **Admin Payment Dashboard** (`/admin/payments/`)
  - Revenue stats (30-day and total), payment/subscription/tier/discount stat cards
  - 4 tabbed sections: Payments (search, filter, paginate), Subscriptions, Tiers (card grid), Discounts
  - Refund processing modal, tier editing, discount code creation with random generator
  - Subscription status management
- **Admin Payment API** (`/admin/api/payment-actions.php`)
  - Actions: stats, payments, subscriptions, tiers, update_tier, create_discount, list_discounts, refund, update_subscription_status

### Added - Payment Provider Integration (Stripe, PayPal, Coinbase Commerce)

- **Database Migration 011** (`_database/migrations/011_payment_providers.sql`)
  - Added `link` to paymentMethod ENUM in tblPayments, tblSubscriptions, tblPaymentMethods (Stripe Link support)
  - 7 Stripe settings (enabled, mode, keys, webhook secret, Link toggle, payment methods)
  - 2 Coinbase Commerce settings (webhook secret, supported currencies)
  - New `tblInboundWebhooks` table for inbound webhook event logging from all providers
  - Scheduled cleanup event (90-day retention for processed webhooks)

- **StripeProvider.php** (~2,275 lines) — Full Stripe API integration via cURL
  - Checkout Sessions with Stripe Link accelerated checkout
  - Payment Intents for one-off payments
  - HMAC-SHA256 webhook signature verification with timestamp tolerance
  - 7 webhook event handlers (checkout, payment intent, invoice, subscription, refund)
  - Customer management, balance retrieval, refund processing

- **PayPalProvider.php** (~2,017 lines) — PayPal REST API v2 integration via cURL
  - Order creation and capture
  - Subscription management with billing plans
  - PayPal webhook signature verification (API-based)
  - 7 webhook event handlers
  - OAuth 2.0 client credentials with token caching

- **CoinbaseProvider.php** (~1,649 lines) — Coinbase Commerce API integration via cURL
  - Charge creation with crypto discount support
  - HMAC-SHA256 webhook signature verification
  - 5 webhook event handlers (confirmed, failed, pending, delayed, resolved)
  - Network-to-currency mapping for blockchain detection

- **Inbound Webhook Receivers** (3 files, ~960 lines total)
  - `/webhooks/stripe.php` — Stripe webhook receiver with signature verification
  - `/webhooks/paypal.php` — PayPal webhook receiver with API verification
  - `/webhooks/coinbase.php` — Coinbase Commerce webhook receiver with HMAC verification
  - All: idempotency checking, full logging to tblInboundWebhooks, performance timing

- **Public Pricing Page** (`/pricing/`) — ~607 lines
  - Responsive tier cards from database with monthly/yearly billing toggle
  - Discount code validation via AJAX
  - Crypto discount banner, accepted payment badges, FAQ accordion

- **Checkout Flow** (4 files, ~1,272 lines total)
  - `/checkout/` — Payment method tabs (Stripe/Card/Link, PayPal, Crypto)
  - `/checkout/process` — Routes payments to correct provider
  - `/checkout/success` — Provider-specific payment confirmation
  - `/checkout/cancel` — Cancellation with activity logging

- **Admin Provider Configuration** (`/admin/payments/providers`) — ~1,423 lines
  - Card-based layout for Stripe (purple), PayPal (blue), Crypto (orange)
  - Credential fields with masked display and reveal toggle
  - Test Connection buttons with live API validation
  - Webhook log viewer with pagination and status badges

- **Admin Provider API** (`/admin/api/provider-actions.php`) — ~593 lines
  - Actions: get_providers, update_provider, test_connection, get_webhook_logs
  - Sensitive value masking, encryption on save, activity logging

### Added - Production Deployment Checklist

- **Deployment Checklist UI** (`/admin/system/deployment.php`)
  - Live server-side checks across 5 categories: Database, Settings, Security, Files, Features
  - Validates all required tables (28+), views, settings, PHP extensions, HTTPS, session security
  - Colour-coded pass/warn/fail results with overall readiness score
  - Step-by-step deployment instructions with Dreamhost guidance
  - Links to System Health page for continuous monitoring

### Changed (v2.3.0)

- **Version bump:** 2.2.3-beta to 2.3.0-beta (new features warrant minor version increment)
- **Database tables:** 35 to 43 (7 new tables from migration 010, 1 from migration 011)
- **Lines of code:** ~37,000+ to ~51,000+ (added ~14,000+ lines)
- **Admin pages:** 19+ to 23+ pages
- **Payment System:** 0% to 100% (schema, backend, admin UI, Stripe/PayPal/Coinbase providers complete)
- **Webhook System:** 0% to 100% (complete with signing, delivery, retry, UI)

---

## [2.2.3-beta] - 2026-02-11

### Added - PHP CLI Local Validation & Code Quality

- **PHP CLI (8.4.17)** installed locally via Homebrew for syntax validation
- **Full codebase validation** across all 206 files (PHP, JSON, JS, CSS, HTML, XML, SQL, Shell)
- **Consolidated database install** - `signula_complete_install_v2.2.3.sql` replaces v2.2.0, includes all migrations through 009

### Changed - Database File Cleanup

- **Archived superseded SQL files** to `_database/archive/` for a clean directory structure:
  - `signula_complete_install_v2.0.1.sql` (superseded by v2.2.3)
  - `signula_complete_install_v2.2.0.sql` (superseded by v2.2.3)
  - `signula_email_system_addon_v2.1.0.sql` (now included in v2.2.3)
  - `001_initial_schema.sql` (superseded by v2.2.3)
  - `002_organizations_migration.sql` (superseded by v2.2.3)
- **`_database/` root now contains only:** `signula_complete_install_v2.2.3.sql`, `migrations/`, and `archive/`
- **Updated `build-complete-install.sh`** to reference archived base schema path

### Fixed - Codebase Syntax Validation (12 fixes across 9 files)

**PHP (2 fixes):**

- **EmailDripProcessor.php:22**: Cron example `*/15` inside `/* */` block comment prematurely closed the comment block
- **public_html_landing/index.php:169**: Unescaped double quotes in `type="submit"` inside double-quoted `echo` string

**XML (2 fixes):**

- **phpcs.xml:12**: Double hyphens (`--`) inside XML comment (illegal per XML spec) - rewrote to avoid `--`
- **phpunit.xml:8**: Double hyphens (`--`) inside XML comment - rewrote to avoid `--`

**SQL (8 fixes):**

- **signula_complete_install_v2.2.0.sql:885-891**: Removed 7 incomplete INSERT stubs (no VALUES clauses, unbalanced parentheses)
- **002_organizations_migration.sql:335**: Escaped apostrophe `You\'re` in SQL string changed to `You are`
- **001_email_system_upgrade.sql:35,108,302**: Three `don't` apostrophes in SQL comments changed to `do not`
- **007_rate_limiting.sql:107**: `don't` in SQL comment changed to `do not`
- **009_multi_tier_admin.sql:262**: `partner's` in SQL comment changed to `partner`
- **signula_email_system_addon_v2.1.0.sql:29**: `we're` in SQL comment changed to `we are`

### Fixed - MFA Login Flow (Critical Bug)

- **mfa/verify.php**: Added missing `Auth::loginOAuth()` call after MFA verification — users with MFA enabled were never actually logged in after entering their code
- **mfa/verify.php**: Fixed `ActivityLogger::log()` calls to use correct 6-parameter signature (was using old 3-parameter format)
- **mfa/verify.php**: Cleaned up `mfa_remember_me` session variable after use
- **mfa/verify.php**: Replaced hardcoded relative URLs (`../login.php`, `../$redirect`) with clean `redirect()` calls
- **mfa/verify.php**: Standardized FontAwesome CDN to 6.4.2 (was 6.5.1)

### Security Hardening

- **Auth.php**: Added `session_regenerate_id(true)` in `completeLogin()` to prevent session fixation attacks
- **config.php**: Added `sanitizeRedirectUrl()` helper to prevent open redirect vulnerabilities (OWASP)
- **login.php**: All `$_GET['redirect']` values now validated via `sanitizeRedirectUrl()` — rejects absolute URLs, protocol-relative URLs, and javascript: URIs
- **callback.php**: Error messages no longer expose internal exception details to users — generic messages shown, details logged server-side
- **callback.php**: Moved email pre-fill from URL parameter to session variable to avoid PII in browser history/logs
- **authorize.php**: Error messages no longer expose internal exception details to users

### Fixed - OAuth Login Flows

**"Sign in with Google/Microsoft/Apple/Facebook" now functional end-to-end.**

10 critical integration bugs fixed across 6 files:

#### 🔐 Auth.php

- Added public `loginOAuth()` method to wrap private `completeLogin()` for OAuth callback use

#### 🗄️ OAuth.php

- Fixed table name: `tblUserLinkedAccounts` → `tblOAuthAccounts` (matches migration 003)
- Rewrote `linkAccount()` INSERT/UPDATE to match actual schema columns (removed non-existent `emailVerified`, `accountData` columns; added `scopes` column)
- Uses `VALUES()` syntax in ON DUPLICATE KEY UPDATE for cleaner queries

#### 🔧 authorize.php

- Replaced non-existent `bootstrap.php` require with `config.php`
- Allow unauthenticated access for `purpose=signin` (was blocking all sign-in attempts)
- Replaced undefined functions (`isUserLoggedIn()`, `getCurrentUserID()`, `getCurrentUser()`) with `Auth::` static methods
- Fixed `ActivityLogger::log()` parameter order

#### 🔧 callback.php

- Replaced non-existent `bootstrap.php` require with `config.php`
- Replaced `Auth::completeLogin()` (private) with `Auth::loginOAuth()` (public)
- Replaced non-existent `Database::insert()` with `Database::query()` + `Database::getLastInsertId()`

#### 🔗 login.php

- Added `$_GET['oauth_error']` handling to display OAuth errors from callback redirects
- Added session-based `info`/`success` message display for OAuth flows
- Added `purpose=signin` to all 4 OAuth button URLs

#### 🔗 register.php

- Added `purpose=signin` to all 4 OAuth button URLs

---

## [2.2.2-beta] - 2026-02-10

### Security - Complete Security Hardening (100%)

**Security Score:** 95% --> **100%**

#### 🔒 CSP & HSTS Security Headers Enabled

- Content-Security-Policy header now active in `/web/_config/config.php` with proper directives (default-src, script-src, style-src, font-src, img-src, connect-src)
- Strict-Transport-Security header enabled (max-age=31536000; includeSubDomains)

#### 🛡️ Subresource Integrity (SRI) on ALL CDN Resources

- 28 files updated with 75 total edits
- All CDN `<link>` and `<script>` tags now include `integrity` and `crossorigin="anonymous"` attributes
- SRI coverage: 38% --> **100%**
- Error pages, SIGNula.id pages, and API docs all updated

#### 📦 CDN Library Version Standardisation

- Bootstrap upgraded from 5.3.0 --> 5.3.2 across admin/partner pages
- FontAwesome upgraded from 6.4.0 --> 6.4.2 across admin/partner pages

#### 🔐 CSRF Token Protection for ALL Forms & AJAX Endpoints

- 5 traditional POST forms protected: email-config.php (3 forms), admin-migration.php, api-keys.php, accept-invite.php, passwordless-request.php
- 6 AJAX API endpoints protected: user-actions.php, settings-actions.php, feature-actions.php, deploy-migration.php, team-actions.php, partner-feature-actions.php
- 7 pages with AJAX updated: users/index.php, settings/index.php, settings/oauth.php, features/global.php, system/migrations.php, team.php, features.php
- 22 POST fetch() calls updated with csrf_token
- CSRF coverage: 54% --> **100%**
- Uses existing SecurityUtils::generateCSRFToken() and verifyCSRFToken()

### Changed

- **Security Score:** 95% --> **100%** (Full Security Hardening Complete)
- **CSRF Protection:** 54% --> 100% (18 files updated)
- **SRI Coverage:** 38% --> 100% (28 files updated)
- **CSP Headers:** Disabled --> Enabled
- **HSTS Headers:** Disabled --> Enabled
- **PROJECT_PROGRESS.md:** Updated security metrics to 100%
- **PROJECT_STATUS.md:** Updated security score and percentages
- **SECURITY_TESTING_GUIDE.md:** Updated coverage statistics

---

## [2.2.1-beta] - 2026-02-09

### Added - Admin Dashboard Completion

#### 🖥️ User Management Interface (`/admin/users/index.php`, ~900 lines)

- Search users by name, email, username, userID
- Filter by status (All, Active, Inactive, Locked)
- Filter by subscription tier (All, Free, Basic, Premium, Enterprise)
- Pagination (25 users per page)
- User detail modal with comprehensive information
- Quick actions: View details, change status, unlock account, reset password
- Bulk operations support framework

#### ⚙️ System Settings Management (`/admin/settings/index.php`, ~950 lines)

- Category-based organization (7 tabs):
  - General (site name, maintenance mode)
  - Security (session, password, rate limiting)
  - Email (SMTP, providers, queue)
  - Authentication (MFA, WebAuthn, passwordless)
  - API (rate limits, versioning)
  - Payment (tiers, gateways)
  - Advanced (logging, caching, debugging)
- Inline editing for all settings
- Add new settings via modal dialog
- Delete settings with confirmation
- Sensitive value masking (reveal on click)
- Real-time updates via AJAX
- Setting validation and type enforcement

#### 🔗 OAuth Provider Configuration (`/admin/settings/oauth.php`, ~850 lines)

- 9 OAuth provider cards:
  - Google (Personal & Workspace)
  - Microsoft (Personal & 365)
  - Apple ID
  - Facebook
  - LinkedIn
  - GitHub
  - Twitter
  - Yahoo
  - PayPal
- Per-provider configuration modals
- Client ID, Client Secret, Redirect URI management
- Enable/disable providers
- Test connection functionality
- Scope management
- Status indicators (enabled/disabled/unconfigured)

#### 📊 System Logs Viewer (`/admin/logs/index.php`, ~1,200 lines)

- Three-tab interface:
  - Activity Log (user activities, logins, changes)
  - Error Log (PHP errors, exceptions, warnings)
  - Audit Log (admin actions, system changes)
- Advanced filtering:
  - Date range picker
  - Severity level (Activity: All, Info, Warning, Error, Critical)
  - User filter (search by name or email)
  - Activity type filter (30+ types)
  - Search by description, IP address, user agent
- Pagination (50 entries per page)
- Auto-refresh (30-second intervals, toggleable)
- Export functionality (CSV/JSON)
- Expandable detail rows with full context
- Color-coded severity indicators
- Real-time log updates

#### 🔧 Backend APIs

- **User Management API** (`/admin/api/user-actions.php`, ~650 lines)
  - `list` - Get paginated user list with search/filters
  - `get` - Get single user details
  - `update_status` - Change user status (active/inactive)
  - `update_tier` - Change subscription tier
  - `reset_password` - Generate password reset token
  - `toggle_super_admin` - Toggle super admin status
  - `unlock_account` - Unlock locked user account
- **Settings Management API** (`/admin/api/settings-actions.php`, ~550 lines)
  - `list` - Get settings by category
  - `create` - Add new setting
  - `update` - Update setting value
  - `delete` - Remove setting
  - `reveal_sensitive` - Temporarily reveal encrypted value

#### 📝 Admin Dashboard Navigation Updates (`/admin/index.php`)

- Added "Users & Settings" section with 4 cards:
  - User Management (search, filter, manage)
  - System Settings (configuration management)
  - OAuth Providers (third-party integration)
  - System Logs (activity, error, audit)
- Added "Feature Management" section with 1 card:
  - Global Features (super admin feature toggles)

### Changed (v2.2.1)

- **Admin Dashboard Progress:** 80% → **100%**
- **PROJECT_PROGRESS.md:** Updated completion metrics and next steps
- **PROJECT_STATUS.md:** Updated overall completion from 96% to 98%
- **Lines of Code:** ~32,000+ → ~36,000+ (added ~4,000 lines)
- **Admin Pages:** 15+ → 19+ pages

### Features

- ✅ **Comprehensive User Management** - Search, filter, paginate, and manage all users
- ✅ **Flexible Settings System** - Category-based organization with inline editing
- ✅ **OAuth Provider Management** - Configure 9 providers with test connections
- ✅ **Advanced Log Viewing** - Three log types with filtering, search, and auto-refresh
- ✅ **RESTful Admin APIs** - 12 new endpoints for admin operations
- ✅ **Real-time Updates** - AJAX-powered interfaces with no page reloads
- ✅ **Responsive Design** - Mobile-friendly admin interfaces
- ✅ **Role-Based Access** - Super admin required for all pages

### Security

- ✅ Super admin authentication required for all admin endpoints
- ✅ CSRF token validation on all state-changing operations
- ✅ Input validation and sanitization
- ✅ Sensitive value encryption and masked display
- ✅ Audit logging for all admin actions
- ✅ SQL injection protection via prepared statements

---

## [2.2.0-beta] - 2026-02-09

### Added - Phase 3.5: Multi-Tier Admin System

#### 🏢 Multi-Tier Admin Architecture ✅

- **AccessControl.php** (300+ lines) - Centralised role-based permission system
  - 6-tier role hierarchy (super-admin=100, root-admin=80, admin=60, developer=40, support=30, finance=20)
  - Super admin, partner admin, root admin verification
  - Feature gate checking (global + per-partner)
  - Admin action audit logging
  - Team size limit enforcement per tier (Free: 5, Basic: 10, Premium: 25, Enterprise: unlimited)

#### 📊 Database Migration (009_multi_tier_admin.sql)

- `tblPartnerTeamMembers` - Team member roles and permissions with root admin enforcement
- `tblFeatureToggles` - Global feature management (14 default features in 4 categories)
- `tblPartnerFeatures` - Per-partner feature overrides
- `tblTeamInvitations` - Secure team invitation system (64-char crypto tokens, 7-day expiry)
- `tblAdminAuditLog` - Complete admin audit trail
- Database triggers enforcing ONE root admin per partner
- Active membership views

#### 🖥️ Admin UI Components (10 pages, ~4,500+ lines)

1. **Partner Admin Dashboard** (`/partners/admin/index.php`)
   - Multi-partner selector, role badges (👑 for Root Admin)
   - Statistics: team members, API keys, pending invites, tier
   - Navigation to all admin functions
   - Feature status indicators

2. **Team Management** (`/partners/admin/team.php`)
   - Invite members via email with role selection
   - Edit member roles, remove members with confirmation
   - View pending invitations with expiration tracking
   - Revoke pending invitations
   - Enforce team size limits per tier

3. **Super Admin Feature Toggles** (`/admin/features/global.php`)
   - Enable/disable features globally (14 features)
   - Toggle partner control permissions
   - View and manage per-partner overrides
   - Category-organised feature display

4. **Partner Feature Toggles** (`/partners/admin/features.php`)
   - Organisation-level feature control (if allowed by super admin)
   - Locked vs unlocked feature display
   - Custom setting vs global default indicators

5. **Transfer Ownership** (`/partners/admin/transfer-ownership.php`)
   - Root admin exclusive access
   - Multi-step safety confirmation (name match + JS dialog)
   - Transaction-based role updates
   - Detailed warnings and implications

6. **Accept Team Invitation** (`/partners/accept-invite.php`)
   - Token-based acceptance with email verification
   - Login/registration prompts for new users
   - Automatic team addition and redirect

7. **Admin Migration Tool** (`/admin/system/admin-migration.php`)
   - UI-based migration of existing isAdmin → isSuperAdmin
   - Selective migration with checkbox interface
   - Safe to run multiple times

#### 🔧 Backend APIs (3 endpoints)

- `/partners/api/team-actions.php` - Invite, update role, remove, revoke
- `/partners/api/partner-feature-actions.php` - Partner feature toggles
- `/admin/api/feature-actions.php` - Global feature management + per-partner overrides

#### 📚 Documentation

- `_docs/MULTI_TIER_ADMIN_IMPLEMENTATION.md` - Complete implementation guide
- `_docs/DEPLOYMENT_GUIDE.md` - Step-by-step deployment and testing guide
- `_docs/SECURITY_TESTING_GUIDE.md` - Security testing and verification guide

### Added - Phase 3.4 UI: Security Admin Interface

#### 🛡️ Security Admin UI ✅

- **Partner Registration** (`/partners/register.php`) - Self-service partner signup
- **Partner Dashboard** (`/partners/dashboard.php`) - Partner overview and management
- **API Key Management** (`/partners/api-keys.php`) - Self-service key lifecycle
- **Admin Partner Management** (`/admin/partners/list.php`) - Approve, suspend, tier changes
- **Rate Limit Monitoring** (`/admin/security/rate-limits.php`) - Real-time monitoring, one-click unblock
- **System Health Dashboard** (`/admin/system/health.php`) - Security score, feature status
- **Migration Deployment** (`/admin/system/migrations.php`) - One-click migration deployment via UI
- **Deploy Migration API** (`/admin/api/deploy-migration.php`) - Backend for UI migrations

### Changed (v2.2.0)

- **Security Score:** 80% → **95%** (Production Ready)
- **PROJECT_PROGRESS.md** - Added Phase 3.5, updated security status and metrics
- **PROJECT_STATUS.md** - Updated completion to 96%, added multi-tier admin section
- **MULTI_TIER_ADMIN_IMPLEMENTATION.md** - Marked all UI components as complete

---

## [2.2.0-beta] - 2026-02-04 (Initial)

### Added - Phase 3.4: Security Enhancements (Rate Limiting & API Keys)

#### 🔐 Rate Limiting System ✅

- **RateLimiter.php** (500+ lines) - Enterprise-grade rate limiting engine
  - Token bucket algorithm implementation
  - Progressive blocking system (1min → 5min → 15min → 1hr → 24hr)
  - Multi-window checking (hourly, per-minute, burst protection)
  - Support for IP, User, and API Key identifiers
  - Tier-based limits (default, free, basic, premium, enterprise)
  - Block/unblock management with reason tracking
  - Real-time status monitoring and analytics
- **RateLimitMiddleware.php** (300+ lines) - Automatic API protection
  - Applied to ALL API requests
  - Automatic identifier detection (IP, User, API Key)
  - HTTP 429 responses with Retry-After headers
  - Standard rate limit headers (X-RateLimit-Limit, Remaining, Reset)
  - Progressive blocking enforcement
  - Configurable tier-based limits

#### 🔑 API Key Management System ✅

- **APIKeyManager.php** (700+ lines) - Secure partner authentication
  - SHA-256 secure key hashing (never stores plaintext keys)
  - Environment separation (sk_live_xxx for production, sk_test_xxx for development)
  - 32-character cryptographically secure key generation
  - Key validation and authentication
  - IP whitelist support with CIDR notation (192.168.1.0/24)
  - Permissions and scopes management (users:read, users:write, etc.)
  - Usage tracking with 90-day retention
  - Response time analytics
  - Automatic and manual key expiration
  - Key revocation with audit trail
  - Key regeneration capability
- **APIKeyMiddleware.php** (400+ lines) - API key authentication layer
  - Multi-format key detection (X-API-Key header, Bearer token, query parameter)
  - IP whitelist enforcement
  - Permissions checking (hasPermission, requirePermission)
  - Automatic usage logging with response times
  - HTTP 401/403 responses for authentication failures
  - Partner context injection for controllers

#### 📊 Database Migrations

- **007_rate_limiting.sql** - Rate limiting infrastructure
  - `tblRateLimits` - Request tracking and violation records
  - `tblRateLimitConfig` - Multi-tier configuration (13 default configs)
  - 5 system settings for rate limiting control
  - Scheduled event for automatic cleanup (1-hour intervals)
  - Support for per-endpoint limits (global, /api/v1/auth/login, etc.)
- **008_partner_api_keys.sql** - Partner and API key management
  - `tblPartners` - Partner organization records (tier, status, webhooks)
  - `tblAPIKeys` - Secure API key storage with SHA-256 hashing
  - `tblAPIKeyUsage` - 90-day usage logs with detailed analytics
  - `tblAPIKeyAudit` - Complete audit trail for all key operations
  - 11 system settings for API key management
  - Scheduled events for automatic key expiration and log cleanup
  - Test partner record for development

#### 🛠️ Development Tools

- **_scripts/generate_test_api_key.php** (350+ lines) - CLI key generator
  - Generate test and live API keys
  - Partner validation and selection
  - Usage examples in multiple languages (cURL, JavaScript, PHP)
  - Quick validation commands
  - Security notes and monitoring queries
  - Command-line arguments (--live, --partner-id, --name, --expires)

#### 📚 Documentation (Security)

- **_docs/SECURITY_DEPLOYMENT_GUIDE.md** (600+ lines) - Complete deployment guide
  - Step-by-step migration deployment
  - Verification queries for each step
  - Testing procedures (rate limiting, API keys, IP whitelisting)
  - Configuration guide for all settings
  - Monitoring queries and analytics
  - Troubleshooting guide
  - Production security checklist
  - Unblocking procedures

### Changed (v2.2.0 Initial)

- **public_html/api/v1/index.php** - Enhanced with security middleware
  - Rate limiting applied to ALL API requests
  - API key authentication middleware initialized
  - Automatic usage logging for all requests
  - Error tracking with API key context
- **PROJECT_PROGRESS.md** - Added Phase 3.4 with comprehensive security details
- **README.md** - Updated with security enhancements and security score
- **VERSION** - Bumped to 2.2.0-beta
- **Database File Organization** - Major restructuring for security and clarity
  - Moved all database files from `web/_database/` to project root `_database/`
  - Consolidated duplicate migration directories into single `_database/migrations/`
  - Improved .gitignore configuration for database files
  - Security enhancement: Database files no longer in web-accessible directory
- **_scripts/add-copyright.sh** - Updated to reference new `_database/` location
- **_scripts/git-hooks/pre-commit** - Enhanced with automatic copyright management
  - Now automatically adds copyright headers to new files (PHP, JS, SQL, Markdown, Shell)
  - Continues to update copyright years in existing files
  - Eliminates manual copyright management overhead

### Added - Infrastructure & Organization

- **signula_complete_install_v2.2.0.sql** (121KB) - Complete database installation file
  - Consolidated all migrations through v2.2.0-beta into single file
  - Includes all features: auth, OAuth, email, blog, support, rate limiting, API keys
  - Replaces need to run individual migrations for fresh installs
  - Properly documented with feature list and installation instructions
- **_scripts/build-complete-install.sh** - Automated build script for complete installation SQL
  - Combines base schema with all migrations
  - Generates up-to-date installation file
  - Organized by feature area with clear section headers
  - Includes copyright headers and proper documentation
- **_scripts/reorganize-database-files.sh** - Database reorganization utility
  - Automated migration from `web/_database/` to `_database/`
  - Creates backup before making changes
  - Consolidates duplicate directories
  - Updates .gitignore automatically
- **_database/** directory structure created
  - `migrations/` - All individual migration files (12 files)
  - `archive/` - Deprecated/old schema files
  - Complete installation files for each major version
  - Organized, secure, and maintainable structure

### Rate Limiting Configuration

#### Default Limits by Tier

| Tier | Type | Requests/Hour | Requests/Minute | Burst (10s) |
| ------ | ------ | -------------- | ----------------- | ------------- |
| **Default** | IP (Unauthenticated) | 100 | 10 | 20 |
| **Free** | User | 500 | 50 | 30 |
| **Free** | API Key | 1,000 | 100 | 50 |
| **Basic** | User | 1,000 | 100 | 50 |
| **Basic** | API Key | 10,000 | 500 | 200 |
| **Premium** | User | 5,000 | 500 | 100 |
| **Premium** | API Key | 50,000 | 2,000 | 500 |
| **Enterprise** | User | 50,000 | 5,000 | 500 |
| **Enterprise** | API Key | 100,000 | 5,000 | 1,000 |

#### Strict Endpoint Limits (Brute Force Prevention)

- `/api/v1/auth/login` - 20/hour, 5/min, 10 burst
- `/api/v1/auth/register` - 10/hour, 2/min, 5 burst
- `/api/v1/auth/forgot-password` - 5/hour, 1/min, 3 burst (5-minute window)
- `/api/v1/auth/reset-password` - 10/hour, 2/min, 5 burst

### Security Improvements

- **Security Score:** 80% → **95%+** (when fully deployed)
- ✅ Rate limit protection on ALL API endpoints
- ✅ Partner authentication via secure API keys
- ✅ Complete usage audit trail (90-day retention)
- ✅ IP-based access control with CIDR support
- ✅ Progressive blocking prevents brute force attacks
- ✅ Per-endpoint limits prevent specific attack vectors
- ✅ Automatic token expiration and cleanup
- ✅ Comprehensive monitoring and analytics

### Benefits

- 🛡️ **Enterprise-Grade Protection** - Rate limiting prevents API abuse and DoS attacks
- 🔐 **Secure Partner Integration** - SHA-256 hashed API keys with granular permissions
- 📊 **Complete Visibility** - 90-day usage logs with response time analytics
- 💰 **Monetization Ready** - Multi-tier system supports paid API access
- ⚡ **Performance** - Token bucket algorithm ensures smooth traffic flow
- 🔍 **Compliance** - Complete audit trail for security requirements
- 🌍 **Scalable** - Supports thousands of partners and millions of requests

### Pending (UI Development)

- Partner registration page
- API key management dashboard (partner view)
- Admin dashboard for partner management
- Rate limit monitoring UI
- Usage analytics visualization

### Documentation Quality

- **Security Documentation:** 95% complete (A grade)
- **Deployment Guide:** 100% complete
- **Code Comments:** Comprehensive inline documentation
- **Testing Coverage:** Deployment testing procedures included

---

## [2.1.0-beta] - 2026-02-04

### Added - Phase 3.3: API Documentation for Partners

- **Comprehensive API Documentation** for third-party partner integration
  - Complete Markdown documentation (`public_html/api/docs/API_DOCUMENTATION.md`, 26KB)
  - Interactive HTML documentation with modern features (`public_html/api/docs/index.html`, 17KB)
  - Search functionality with collapsible sidebar navigation
  - Syntax highlighting for code examples (Bash, JavaScript, PHP, JSON, HTTP)
  - Copy-to-clipboard buttons for all code blocks
  - Mobile responsive design with professional styling
  - Smooth scrolling and active section tracking
- **API Analysis and Security Audit** (`.claude/API_ANALYSIS.md`)
  - Complete endpoint inventory (31 endpoints)
  - Security analysis (80% score - excellent foundation)
  - Gap identification with prioritized recommendations
  - Quality metrics assessment (Overall: 87%, B+ grade)
- **Documentation Summary** (`.claude/API_DOCUMENTATION_SUMMARY.md`)
  - Deployment instructions for partners
  - Coverage metrics (33 endpoints, 100% documented)
  - Success criteria checklist

### Added - Phase 3.2: Delegate Email Sending via OAuth

#### OAuth Token Management Infrastructure

- `OAuthTokenManager.php` (530 lines) - Complete token lifecycle management
  - Store, retrieve, refresh, and delete OAuth tokens
  - AES-256 encryption for token security
  - Automatic token refresh with retry logic
  - Activity logging for all operations
- `OAuthFlowHandler.php` (420 lines) - OAuth 2.0 authorization flow
  - Authorization initiation with state tokens
  - Callback processing and token exchange
  - CSRF protection with state validation
  - Multi-provider support (Microsoft, Google)

#### Database Changes

- New table: `tblUserOAuthTokens` - Encrypted OAuth token storage
- New column: `sendAsEmail` in `tblEmailQueue` - Specify delegate mailbox
- Migration: `006_delegate_mailbox_support.sql`
- Comprehensive addon SQL: `_sql/signula_email_system_addon_v2.1.0.sql` (email system + delegate support)

#### Email Provider Enhancements

- **GmailAPIEmailProvider.php** - Dynamic JWT impersonation for delegate sending
  - Per-mailbox token caching for performance
  - Works with existing service account setup
  - Supports sendAsEmail parameter
- **MicrosoftGraphEmailProvider.php** - Dual-mode authentication
  - Application auth for FREE shared mailboxes (no user license needed)
  - Delegated auth for user mailboxes via OAuth
  - Intelligent auth mode detection (AUTO/APPLICATION/DELEGATED)
  - determineAuthMode() method for smart fallback

#### User Interface

- `/settings/email-accounts.php` (280 lines) - Email account management page
  - Connect Microsoft 365 and Google Workspace accounts
  - View token status, expiration dates, and last usage
  - Disconnect accounts with confirmation
  - Responsive Bootstrap design with account cards
- `/api/oauth/disconnect.php` (150 lines) - Account disconnect API endpoint

#### Enhanced OAuth Endpoints

- Updated `/oauth/authorize.php` - Added email delegation routing
- Updated `/oauth/callback.php` - Enhanced with delegation callback handling
- Preserved existing sign-in functionality

#### Comprehensive Documentation (4 guides, ~3,000 lines)

- `_docs/SHARED_MAILBOXES_AND_AUTH_MODES.md` - Complete feature guide
- `_docs/DUAL_MODE_IMPLEMENTATION_SUMMARY.md` - Implementation overview
- `_docs/MICROSOFT_DELEGATE_MAILBOX_SETUP.md` - Azure AD setup guide
- `_docs/DELEGATE_MAILBOX_ARCHITECTURE.md` - Technical architecture
- `.claude/IMPLEMENTATION_COMPLETE.md` - Setup and testing guide

### Changed (v2.1.0)

- **EmailService.php** - Added `sendAsEmail` parameter to public API
  - Updated `queueEmail()` method signature
  - Pass delegate mailbox to email queue
- **EmailQueueProcessor.php** - Enhanced to pass sendAsEmail and userID to providers
- **PROJECT_PROGRESS.md** - Added Phase 3.2 and 3.3 sections with complete details
- **README.md** - Added comprehensive sections for delegate email and API documentation

### Benefits (v2.1.0)

- 💰 **Cost Savings**: $600+/year using FREE Microsoft 365 shared mailboxes
- 🔒 **Security**: OAuth 2.0 with encrypted token storage and automatic refresh
- ⚡ **Performance**: Token caching and intelligent auth mode selection
- 📊 **Visibility**: Complete activity logging and monitoring
- 🎯 **Flexibility**: Support for both system emails (FREE) and user emails (OAuth)
- 📚 **Documentation**: Enterprise-grade API documentation ready for partners

### Security (v2.1.0)

- AES-256 encryption for OAuth access and refresh tokens
- State token CSRF protection in OAuth flows
- Activity logging for all OAuth operations
- Token ownership validation (users can only manage their own tokens)
- Automatic token cleanup on revocation
- HTTPS enforcement for OAuth flows

### Documentation Quality (v2.1.0)

- API Documentation: 95% (A grade) - was 40%
- Overall project documentation: 95% complete
- Partner-ready with interactive web interface

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

### Changed (v2.0.1)

- **OAuthController.php**: Enhanced to support multiple accounts per provider
  - Removed provider uniqueness restriction
  - Added duplicate external account prevention
  - Updated `linkAccount()` method with account type and domain support
  - Updated `getLinkedAccounts()` to return new fields
- **README.md**: Updated database setup section with comprehensive SQL installation options
- **PROJECT_PROGRESS.md**: Added Phase 3.1 section and updated version to 2.0.1-beta

### Security (v2.0.1)

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

#### WebAuthn/PassKey Support

- Database tables: `tblWebAuthnCredentials`, `tblWebAuthnChallenges`
- Backend handler: `WebAuthnHandler.php` (730+ lines)
- API endpoints for registration and authentication
- User pages: PassKey register, login, management

#### Passwordless Login

- Database table: `tblPasswordlessTokens`
- Backend handler: `PasswordlessLoginHandler.php` (650+ lines)
- Magic link generation with secure tokens
- User pages: Request link, verify and login

- Database migration: `005_webauthn_passkeys.sql`
- Stored procedure: `cleanupExpiredAuthTokens()`
- Comprehensive testing documentation with 60+ test cases

### Features (v2.0.0)

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

### Security (v2.0.0)

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

```text
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

[Unreleased]: https://github.com/MWBMPartners/SIGNula.id/compare/v2.5.0-beta...HEAD
[2.5.0-beta]: https://github.com/MWBMPartners/SIGNula.id/compare/v2.4.0-beta...v2.5.0-beta
[2.4.0-beta]: https://github.com/MWBMPartners/SIGNula.id/compare/v2.3.0-beta...v2.4.0-beta
[2.3.0-beta]: https://github.com/MWBMPartners/SIGNula.id/compare/v2.2.3-beta...v2.3.0-beta
[2.2.3-beta]: https://github.com/MWBMPartners/SIGNula.id/compare/v2.2.2-beta...v2.2.3-beta
[2.2.2-beta]: https://github.com/MWBMPartners/SIGNula.id/compare/v2.2.1-beta...v2.2.2-beta
[2.2.1-beta]: https://github.com/MWBMPartners/SIGNula.id/compare/v2.2.0-beta...v2.2.1-beta
[2.2.0-beta]: https://github.com/MWBMPartners/SIGNula.id/compare/v2.1.0-beta...v2.2.0-beta
[2.1.0-beta]: https://github.com/MWBMPartners/SIGNula.id/compare/v2.0.1-beta...v2.1.0-beta
[2.0.1-beta]: https://github.com/MWBMPartners/SIGNula.id/compare/v2.0.0-beta...v2.0.1-beta
[2.0.0-beta]: https://github.com/MWBMPartners/SIGNula.id/compare/v1.0.0...v2.0.0-beta
[1.0.0]: https://github.com/MWBMPartners/SIGNula.id/releases/tag/v1.0.0

---

**Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
