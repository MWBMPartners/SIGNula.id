# SIGNula — Autopilot Run (`autopilot/2026-06-30`)

> Machine state lives in `.dev-team/autopilot.json` (authoritative for the loop). This file is the human-readable narrative + trajectory.
> Mission: **production-ready + feature-complete (in scope)**. Gate policy: **hybrid**. Convergence cap: **60 cycles**.

## Goal
Drive SIGNula — a "universal" all-in-one CIAM / single-sign-on identity platform (PHP 8.4, MySQLi + prepared statements, MariaDB; Dreamhost shared hosting, no Composer in prod) — to production-ready and as feature-complete as its scope allows. Reconcile the codebase against the project brief (`.claude/CLAUDE.md`) and the GitHub issue tracker (40 open / 41 closed), close in-scope feature gaps, harden security, and fix correctness/syntax/lint defects. Auto-commit per cycle on the run branch; **never push** (user pushes manually).

## Definition of done
- [ ] **Builds & runs from a clean checkout** (install wizard completes) — *VERIFY-established*
- [ ] **Core auth flow works end-to-end** (register → login → MFA → session → logout) — *VERIFY-established*
- [ ] **Tests cover the core flows and pass** (current: 342 unit green; add coverage for Auth/Database/EmailService/payment providers/WebAuthn — B-020)
- [ ] **No unresolved Critical/High security finding** (SECURE reached dry; the 11 deferred issues #22–#34/#73 + FG-013 WebAuthn signature verification resolved)
- [ ] **No High-impact correctness/UX backlog item remains** (11 High B-items resolved or explicitly gated)
- [ ] **Docs enough to run it** (README/install present; PROJECT_PROGRESS/CHANGELOG/SECURITY_SETUP kept current)
- [ ] **Feature-complete in scope** — every High in-scope feature gap built or queued (FG-002a + FG-009 built; FG-001/FG-003/FG-004 queued for approval; FG-013 → SECURE)

**Out of code-scope (surfaced to user, NOT counted against done):** operational deployment needing the user's prod environment/secrets — #83 prod DB creds, #84 email-provider creds, #85 cron, #70 live-payment creds, #71 staging deploy, #79 HTTPS/.htaccess finalize, #77 monitoring, #78 backups; plus manual QA (#72 E2E, #75 cross-device, #74 load) and legal review (#81).

## Constraints
PHP 8.3+ (8.4 target); MySQLi + prepared statements only; all config in `tblSettings` (encrypted when `isSensitive`); AES-256 + Argon2id; no Composer in prod (self-host libs); `DIRECTORY_SEPARATOR`/predefined constants; `SIGNULA_INIT` guard on all source; clean URLs (no visible `.php`); private dirs `_`-prefixed outside web root.
**Hands-off (confirmed at bootstrap):** DB connection credentials & secrets; git push / remote / `.git/config`. **Gate-protected by default (Bucket-3, never autonomous):** destructive/data-dropping migrations; live-payment credential wiring. `.claude/` tooling files left untouched.

## Skills in play
dev-team-autopilot (conductor) · iterate / security / featurefind / review (modes) · dev-team-stripe (reserved for the gated billing epic FG-003/G-002 once approved).

## House style
Emoji-annotated docblocks; prepared statements via the `Database` wrapper; modular `require_once` with guard checks; `DIRECTORY_SEPARATOR` everywhere (0 literal-`/` requires); PSR-12 + project `phpcs.xml`; PHPUnit 10 tests in `_tests/`. Adopt-later: `declare(strict_types=1)` (0/271 today — POLISH).

## Stage plan
1. **DISCOVER** ✅ (bootstrap) — Codebase Map + scored backlog (23 B-) + feature-gap ledger (17 FG-) + issue reconciliation.
2. **STABILIZE** ⏳ — fix High correctness/robustness/quality B-items + establish safety-net tests. *Exit: no High correctness/test gap.*
3. **SECURE** — Phase-0 (threat model/scanners/fixtures), then one purple-team cycle per iteration over the 11 deferred issues + FG-013 + new findings. *Exit: no unresolved Critical/High; rounds dry.*
4. **COMPLETE** — build autonomy-eligible gaps (FG-009, FG-002a, FG-011, FG-005, FG-008, + B-019, B-022); queue FG-001/FG-003/FG-004 etc. *Exit: every High in-scope gap built-or-queued.*
5. **POLISH** — phpcbf whitespace, strict comparisons, strict_types, complexity, a11y (#76). *Exit: no High UX/perf item.*
6. **VERIFY** — independent review (report-only) at the shipped commit. *Exit: complete pass overall PASS.*

## Current status
Bootstrap + spec-authoring done. **G-001..G-004 user-approved** (specs in `.dev-team/specs/`). Phase: **STABILIZE** (safety floor leads before the security-critical feature builds). Build sequence:
1. ✅ **STABILIZE-1 DONE** (cycle 3): phpstan+phpcs now run; unknown-class FPs 647→74; baseline ≈3632 real phpstan errors; 5 SIGNULA_INIT guards; 342 tests green. **NEXT → STABILIZE-2.**
2. ✅ **STABILIZE-2 DONE** (cycle 4): +48 safety-net tests (342→390 green). Surfaced 2 HIGH — **B-027** (`Database::insert()` missing → WebAuthn registration broken) + **FG-013** (WebAuthn accepts unsigned assertions — auth-bypass, PoC-confirmed). **SAFETY FLOOR → next cycles (5–6) fix WebAuthn BEFORE the feature campaign:** ✅ **cycle 5 DONE**: B-027 (added `Database::insert()`/`insertId()` — unbroke 7 callers incl. PasswordlessLogin + 4 email + AccountManager) + B-028 (fixtures→canonical lowercase `active`; BaseController already correct). ✅ **cycle 6 DONE**: FG-013 closed (real WebAuthn verification — CBOR/COSE→PEM, openssl_verify, sign-count clone-detect; **red-team HOLDS** across 7 vectors; +2 pre-existing bug fixes). ✅ **cycle 7 DONE**: Integration suite runnable + green-on-rerun; fixed 3 broken core flows (error-logging, email-queue, backup-MFA) + user registration. **The "100% complete beta" had significant hidden breakage — fixing the foundation before features is correct.** NEXT: **cycle 8** SECURE cleanup (B-024 CORS High-sec + B-030/B-031 WebAuthn residuals + begin the 11 deferred #22–#34/#73); **cycle 9** B-033 broken-installer + B-038/B-034 (clean-install / migration reconciliation — HIGH, blocks clean-checkout done criterion); B-037 flake hardening. THEN feature campaign G-003 → G-001 → G-002 → G-004. THEN CAP-API capstone (#86).
**CAPSTONE (user directive 2026-06-30):** once all queued cycles + the feature campaign are complete, run a thorough **API completeness + security pass** ensuring every feature (auth, MFA, OAuth, WebAuthn, IdP/G-001, billing/G-002, compliance/G-004, partners, usage…) has secure, consistent, authz-enforced, rate-limited REST endpoints, AND **complete the OpenAPI/Swagger spec** to document them all. Tracked by issue #80 (OpenAPI) + a new API-completeness/security issue.
3. **G-003** JWT auth (S1–S5), then **G-001** IdP Phase A (A1–A5), then **G-002** billing (test-mode, fix B-025 first), then **G-004** compliance (L1 first).
4. Interleave **SECURE** fixes for any High finding (incl. B-024 CORS, the 11 deferred email/admin/api issues, FG-013 WebAuthn).
New findings this cycle: **B-024** (CORS `*`+credentials), **B-025** (billing schema drift), suggested **FG-018** (SCIM provisioning, Phase C of G-001).

## Decision log
- **2026-06-30 — Bootstrap.** User-confirmed: hybrid gate · in-scope feature ambition · hands-off = DB creds + git push · cap = 60. Dev-team artifacts under `.dev-team/` (root `SECURITY.md` is the GitHub disclosure policy; `PROJECT_PROGRESS.md` is the user's own doc — neither clobbered).
- **2026-06-30 — JWT API auth (B-006/FG-004 → G-003) gated**, not autonomous: vendoring a JWT lib is a dependency decision and the auth path is security-critical → user controls the *how*.
- **2026-06-30 — Recurring billing (#67/FG-003 → G-002) gated** as payment-touching (Bucket-3); route via dev-team-stripe when approved. B-007 (placeholder payment redirects) tied to it — verify wiring-only before any autonomous touch.
- **2026-06-30 — SIGNula-as-IdP (FG-001 → G-001) gated** as a large-architectural epic (today SIGNula only *consumes* OAuth; issuing SAML/OIDC/OAuth2 tokens to relying parties is the single biggest capability gap).

## Checkpoint log
- **cycle 0 (DISCOVER)** — docs-only: PROJECT.md + FEATURES.md written; 0 PHP parse errors; 342 unit tests green; tooling config bug found (B-001). Commit: bootstrap.
- **cycle 3 (STABILIZE-1)** — B-001/B-002/B-005 PASS: `composer analyze`/`check-style` run; phpstan unknown-class FPs 647→74 (74 genuine); 5 guards added; 342 tests green, no regression. EmailDripProcessor guard placed after its config-require (CLI entrypoint) — lead ACCEPTED.
- **cycle 4 (STABILIZE-2)** — B-020 safety net PASS: +48 tests (342→390 green, stable across 3 seeds), no behaviour change. Net surfaced 2 HIGH (B-027 WebAuthn registration broken via missing `Database::insert()`; FG-013 WebAuthn auth-bypass — unsigned assertions accepted, PoC test) + pinned B-024/B-012/B-011/B-028. Per safety floor, next cycles fix WebAuthn before the feature campaign.
- **cycle 7 (B-029 triage)** — MAJOR foundation find. Made the Integration suite runnable (reproducible `_scripts/setup_test_db.sh`) and took it 39 errors/14 failures → green-on-rerun (B-037 flake remains). Fixed 3 genuinely-broken **core flows** hidden from the DB-stubbed unit suite: error logging (`ErrorLogger::log` undefined, 61 call sites), email queue (`EmailService` bind-count → *every* `queueEmail()` failed), backup-code MFA (ENUM mismatch); + additive migrations 025/026 fixed broken user registration (missing `organizationID`) + missing MFA columns. **Reality check: the "100% complete beta" had significant hidden breakage.** Residuals → B-033 (HIGH: broken installer, blocks clean install), B-034 (multi-org tables missing), B-035/B-036/B-037/B-038.

## Feature Specs

Full build-ready specs authored 2026-06-30 (user-approved G-001..G-004); build cycles cite these verbatim. **Migration numbers are conductor-assigned sequentially AT BUILD TIME** (existing run 001–024; several specs tentatively claimed 025/026 — resolve collisions when each builds).

### #spec-G-003 — JWT API Authentication (build FIRST) → `.dev-team/specs/G-003.md`
Vendor `firebase/php-jwt` v6.x into `web/_lib/jwt/` (self-host pattern, MIT, ext-openssl). RS256 + public JWKS; access JWT (15-min, `jti`, `scope`) + opaque SHA-256 refresh token with family rotation + reuse-detection; `jti` denylist (`tblRevokedTokens`); signing key encrypted in tblSettings (`_private` PEM fallback), `kid`-rotated. Endpoints `/api/v1/auth/{token,refresh,revoke}` + `/.well-known/jwks.json`; wire `BaseController::getUserByToken()`/`requireAuth()` to accept Bearer alongside X-API-Key. Stages: S1 crypto foundation → S2 issuance+rotation → S3 endpoints/wiring → S4 JWKS+hardening → S5 docs.

### #spec-G-001 — SIGNula as IdP (OAuth2/OIDC provider; SAML later) → `.dev-team/specs/G-001.md`
Phase A (buildable, reuses G-003 signing): `/oauth/authorize`+consent, `/oauth/token` (auth_code + PKCE-required + refresh), `/oauth/userinfo`, OIDC discovery, `id_token`. RP clients owned by `tblPartners`; new `tblOAuthClients/tblOAuthAuthCodes/tblOAuthConsents/tblOAuthAccessGrants` (secrets hashed like `tblAPIKeys`). Phase B SAML IdP (needs vendored `xmlseclibs`, separately gated). Phase C SCIM → suggested **FG-018**. **Depends on G-003 (ships first).**

### #spec-G-002 — Recurring Billing Engine (⚠️ TEST MODE ONLY) → `.dev-team/specs/G-002.md`
Provider-managed subscriptions (PayPal/Stripe own the recurring clock; SIGNula reacts to webhooks). Lifecycle: pending/trial/active/past_due/**grace**/paused/cancelled/expired. Reuses BillingScheduler/PaymentManager/providers/InvoiceManager. New: grace state, dunning columns, `tblBillingAttempts` (UNIQUE `idempotencyKey`), `changeTier()` bcmath proration. `assertTestMode()` fail-closed; **live activation = user op #70**. BUILD-FIRST FIXES: B-025 schema drift + missing `chargeStoredMethod()` (pivot resolves it).

### #spec-G-004 — Multi-jurisdiction Compliance (data-driven) → `.dev-team/specs/G-004.md`
L1 (build first, autonomy-eligible): `tblConsentRecords`+`tblDataSubjectRequests`+events, DSAR tracker, admin queue, user form — `DSARManager` **delegates** to existing `AccountManager` export/delete (no dup; one additive edit to `permanentlyDeleteAccount()` to anonymise). L2 consent banner/preference-center + GPC. L3 `tblComplianceRegimes` data-driven model (`RegimeResolver` — adding a regime = row inserts, 0 PHP edits). L4 breach log/RoPA/retention/COPPA age-gate. **Anonymise-don't-delete** on erasure. Legal values ship draft/empty (user fills).

## Codebase Map

SIGNula is a "universal" CIAM / single-sign-on identity platform. **PHP 8.4.17** local (min 8.3), **MySQLi + prepared statements**, MariaDB/MySQL. Self-hosted libs (no Composer in prod — Dreamhost shared). Reported version: PROJECT_PROGRESS.md says `2.7.0-beta`, but the `VERSION` file says `2.6.0-beta` (doc inconsistency — see B-021). **271 PHP files** under `web/` (brief said ~295), **~84 DB tables** across 24 migrations (brief/docs said ~49; docs undercount), **24 migrations** (001–024, not 19). PROJECT_PROGRESS.md marks nearly every component "✅ 100% Complete" — but several flows are TODO stubs (JWT, contact-form persistence, multiple email notifications). **Trust code over docs.**

### Directory layout
- `web/public_html/` — web-served document root. Subdirs: `auth/`, `mfa/`, `settings/`, `admin/` (+ `admin/api/`, `admin/security`, `admin/payments`, `admin/rate-limiting`, `admin/webhooks`…), `api/` (`api/v1`, `api/oauth`, `api/webauthn`, `api/docs`), `oauth/`, `partners/` (+ `partners/api`, `partners/admin`), `organization/`, `checkout/`, `pricing/`, `invoices/`, `avatar/`, `cron/`, `email/`, `webhooks/`, `install/`, `error/`, and branded dirs `SIGNula.com/` (marketing "shop window": about/contact/features/pricing/blog/docs/legal/support) + `SIGNula.id/` (hub: admin/support).
- `web/private_html/` — non-served business logic (the class library). Subdirs: `auth/` (+ `auth/providers/`), `security/`, `email/` (+ `email/providers/`), `payments/`, `api/` (+ `api/controllers/`), `utils/`, `notifications/`, `webhooks/`, `i18n/` (+ `i18n/lang/en/`), `admin/`, `layout/`.
- `web/_config/` — `config.php` (bootstrap) + `database.php` (Database singleton). `web/_includes/`, `web/_lib/` (self-hosted `crawlerdetect/`, `tcpdf/`), `web/_private/` (templates/uploads, outside web root, `_`-prefixed per house rule), `web/_backend/`, `web/_scripts/`.
- `web/public_html_dev/` and `web/public_html_landing/` — near-empty stubs (favicons + one index). **No `alpha_html`/`beta_html` dirs exist** despite `ALPHA_DIR`/`BETA_DIR` constants in config.php (brief gap — B-019).
- `_database/migrations/` (001–024 SQL) + consolidated installers `signula_complete_install_v2.2.3.sql` / `_v2.5.0.sql` + `archive/`.
- `_tests/` (PHPUnit: `Unit/`, `Integration/`, `Fixtures/`, `bootstrap.php`, `TestCase.php`, `DatabaseTestCase.php`), `_docs/` (24 docs dirs), `_scripts/` (+ `git-hooks/`). Root: `phpcs.xml`, `phpstan.neon`, `phpunit.xml`, `composer.json/.lock`, bundled `composer` phar, `README.md`, `PROJECT_PROGRESS.md`, `CHANGELOG.md`, `SECURITY.md`, `VERSION`.

### Entry points & URL routing (clean URLs — brief forbids visible `.php`)
Achieved via **Apache `mod_rewrite` in `.htaccess`** (NOT a DB router table). Main `web/public_html/.htaccess`: strips/adds `.php` (`RewriteRule ^(.+?)/?$ $1.php [L]`), exempts real dirs/assets/`_includes`, 301-redirects trailing slashes, blocks dotfiles/`.git`/`composer.json`/`_config`/`_private`, and has query-string SQLi pattern blocks. `web/public_html/api/.htaccess` is a **front-controller**: all `/api/*` → `api/v1/index.php [QSA,L]`. So routing is a hybrid: directory/`.htaccess` rewrite for pages, single front-controller + `Router` class for the REST API. HTTPS-force and www-canonical rules are present but **commented out** (production hardening pending — issue #79). Per-area `.htaccess` also in `SIGNula.com/`, `install/` (locks after `.installed`), `avatar/`, `api/`.

### Bootstrap / config chain (`web/_config/config.php`)
Defines `SIGNULA_INIT` guard, path constants (`ROOT_DIR`, `PRIVATE_DIR`, `LOGS_DIR`, `ALPHA_DIR`, `BETA_DIR`…) using `DIRECTORY_SEPARATOR`, env/`DEBUG_MODE`/`DISPLAY_ERRORS` (the `?debug=true` switch the brief asked for), `require_once` of `database.php`, `loadSettings()` → `$GLOBALS['settings']` from `tblSettings` with `SecurityUtils::decrypt()` for `isSensitive` rows, session init (secure cookie flags, name `SIGNULA_SESSION`, ID regen every 5 min, `SessionGuard`), a directory-scanning autoloader over `_includes/` + `private_html/` subdirs, `SecurityMiddleware::handle('web')` on every request, and global helpers `getSetting`, `getClientIP` (CF/XFF aware), `getUserAgent`, `redirect`, `sanitizeRedirectUrl` (open-redirect guard), `jsonResponse`, `sanitizeInput`, `isValidEmail`.

### Data model (~84 tables)
**Identity/Auth:** tblUsers (core accounts), tblSessions (DB-backed sessions w/ device/MFA flags), tblSessionFingerprints (hijack detection), tblPasswordResetTokens, tblPasswordlessTokens (email-link login), tblEmailVerificationTokens, tblPasswordHistory (reuse prevention). **MFA/Passkeys:** tblUserMFA (TOTP/SMS + backup codes), tblWebAuthnCredentials (FIDO2, signature counter), tblWebAuthnChallenges. **OAuth:** tblOAuthAccounts (linked third-party accounts), tblUserOAuthTokens (delegate-mailbox tokens for Graph/Gmail). **Profile:** tblUserPreferences, tblUserAvatars (per-partner, upload/oauth/url). **Logging:** tblActivityLog (full audit trail incl. IPv4/IPv6 + UA), tblErrorLog (exceptions+stack), tblAdminAuditLog. **Config:** tblSettings (KV, isSensitive encryption). **Email system:** tblEmailTemplates (versioned), tblEmailCampaigns, tblEmailABTests/Variants, tblEmailDripCampaigns/Steps/Subscribers/Progress, tblEmailRecurringSchedules, tblEmailTrackingEvents, tblEmailUnsubscribes, tblEmailProviderHealth. **Contact:** tblContactSubmissions. **Blog:** tblBlogPosts/Categories/Tags/PostTags/Comments. **Support:** tblSupportTickets/Replies/Attachments/Categories. **Security:** tblIPReputationCache (AbuseIPDB/proxycheck, TTL), tblBlockedIPs, tblSecurityAlerts (brute force/impossible travel/hijack), tblCircuitBreaker. **Rate limiting:** tblRateLimits, tblRateLimitConfig (per-tier/endpoint). **Partners/Multi-tenant:** tblPartners, tblPartnerTeamMembers, tblTeamInvitations, tblPartnerFeatures, tblFeatureToggles, tblAPIKeys (test/live, scoped, IP-whitelist), tblAPIKeyUsage, tblAPIKeyAudit. **Webhooks:** tblWebhookEndpoints + tblWebhookDeliveries (partner outbound, HMAC), tblWebhooks + tblWebhookDeliveryLog (self-service outbound), tblInboundWebhooks (payment providers). **Payments/Billing:** tblSubscriptionTiers, tblSubscriptions, tblPayments (multi-provider), tblPaymentMethods (tokenised only), tblDiscountCodes/Assignments, tblProviderDiscounts (incl. crypto discount), tblPartnerPaymentConfig (own-keys vs SIGNula-keys), tblPartnerSubscriptionTiers, tblServiceFees/ServiceFeeTransactions, tblRemittances (partner payouts), tblBillingSchedule, tblInvoices (PDF/line items), tblCreditBalances/CreditTransactions. **Usage:** tblUsageMetrics, tblUsageRates (fixed/usage/hybrid), tblUsageRecords, tblUsageBillingSummary. **Credential reset:** tblCredentialResets, tblCredentialResetUsers, tblSaltRotationHistory. **Notifications/GDPR:** tblNotifications (in-app center), tblDataExportRequests (GDPR portability/erasure). **Meta:** tblMigrations.

### Key classes (`web/private_html/`)
- **Database** (`_config/database.php`) — instantiated singleton; MySQLi + prepared statements w/ auto type-detection (`prepare`/`bind_param`); `query/fetchOne/fetchAll/transaction/escape`. Has SIGNULA_INIT guard; **no** `declare(strict_types=1)`.
- **Auth** (`auth/Auth.php`, static) — register/login/logout/changePassword/isAuthenticated/getCurrentUser/requireAuth. Account lockout after failed attempts (lockout email = TODO L573).
- **AccountManager** (`auth/AccountManager.php`, static) — GDPR deletion + data export (`requestAccountDeletion/exportUserData/serveExportFile/processScheduledDeletion`).
- **OAuth stack:** `OAuth.php` (abstract base), `OAuthFlowHandler.php` (authz code flow, provider switch incl. microsoft_graph/google_workspace), `OAuthTokenManager.php`, plus `auth/providers/`: **GoogleOAuth, MicrosoftOAuth, AppleOAuth, FacebookOAuth, LinkedInOAuth, PayPalOAuth, YahooOAuth, AmazonOAuth, WordPressOAuth (9 wired)**. callback.php loads `{$providerClass}.php` dynamically, so all 9 dispatch generically.
- **Organization** (`auth/Organization.php`) — org accounts, member mgmt, domain verify (invite email = TODO L300).
- **PasswordlessLoginHandler**, **WebAuthnHandler** (FIDO2 register/authenticate ceremonies).
- **security/** (mostly static): **MFA** (TOTP/email-OTP/backup-codes/trusted-device), **TOTP** (RFC 6238), **SecurityUtils** (AES encrypt/decrypt, Argon2id hash, CSRF token gen/verify, sanitizeString/Email, validatePassword), **RateLimiter** (token bucket — *missing SIGNULA_INIT guard*), **CaptchaVerifier** (Turnstile primary + reCAPTCHA v3 fallback, fail-open), **IPReputationChecker** (AbuseIPDB+proxycheck, circuit breaker), **BotDetector** (CrawlerDetect lib + regex), **SessionGuard** (composite fingerprint), **SecurityAlertManager**, **SecurityMiddleware** (`handle('web')` / `handleFormSubmission`), **FormProtection** (honeypot + HMAC timing + JS challenge), **QRCode**.
- **email/** (static utilities): EmailService (`sendTemplateEmail/sendDirectEmail/queueEmail/bulkQueue`), EmailTemplateBuilder (HTML/AMP/dark-mode/MIME assembly), EmailTemplateManager (template CRUD + versioning + preview), EmailQueueProcessor, EmailScheduler, EmailDripCampaign, EmailDripProcessor (*missing guard*), EmailPersonalization, EmailTracker, EmailABTesting, EmailSecurityManager (DKIM/SPF/DMARC), EmailProviderHealthMonitor, EmailWebhookHandler. `email/providers/`: **SMTP, SendGrid, Mailgun, Postmark, AmazonSES, GmailAPI, MicrosoftGraph** (+ `EmailProvider` base) = 7 wired.
- **payments/** (static): PaymentManager (subscription lifecycle, discount validation), **StripeProvider, PayPalProvider, CoinbaseProvider (crypto), KofiProvider, PatreonProvider** (raw cURL, no SDK; webhook signature verify), InvoiceManager (TCPDF), CreditManager (row-lock ledger), UsageTracker, UsageBillingService, BillingScheduler, BillingLazyCheck, ServiceFeeManager, PartnerPaymentService (provider discount incl. crypto).
- **api/** : Router, BaseController (input/validate/requireAuth/paginate/success/error; **getUserByToken = JWT stub returns null**), Response (success/error/validationError/…), Validator, APIKeyManager + APIKeyMiddleware (X-API-Key, IP whitelist, scoped permissions; *both missing guard*), RateLimitMiddleware (*missing guard*), WebhookManager. `api/controllers/`: AuthController, UserController, MFAController, OAuthController, UsageController.
- **utils/**: AvatarService (priority chain upload→oauth→gravatar→generated), ExportService (CSV/XLSX/PDF/Google Sheets/Excel Online), ActivityLogger, ErrorLogger. **notifications/**: NotificationService. **webhooks/**: WebhookService. **i18n/**: I18n (file-based, `lang/en/*`). **admin/**: CredentialResetService (mass reset/salt rotation).

### Integrations actually wired
- **Email/SMTP:** 7 providers (above). **Captcha:** Turnstile + reCAPTCHA v3 (CaptchaVerifier). **Payments:** Stripe, PayPal, Coinbase(crypto), Ko-fi, Patreon — each with a `web/public_html/webhooks/{provider}.php` inbound handler (+ `webhooks/email.php`). Apple Pay / Google Pay = settings + routed through Stripe/PayPal, **no standalone provider class**. **OAuth callbacks:** `oauth/authorize.php` + `oauth/callback.php` (+ `api/oauth/disconnect.php`), generic over the 9 provider classes. **WebAuthn:** `api/webauthn/{register,auth}-{options,verify}.php`.

### Session / auth model
DB-backed sessions (`tblSessions`: hashed token, encrypted data, device/IP/UA, `isMFAVerified`, expiry). Cookies: httponly, `use_only_cookies`, secure-in-prod, SameSite=Lax, name `SIGNULA_SESSION`, ID regen 5-min. SessionGuard fingerprint (IP+UA+Accept-Language+Accept-Encoding+salt) validated each request. CSRF: `$_SESSION['csrf_token']` + FormProtection HMAC-timing/honeypot/JS-challenge. **API auth = API keys** (`X-API-Key` header preferred, `?api_key=` fallback; `sk_live_…`); **JWT path is a non-functional stub** (issue #41). Browser session auth via `BaseController::requireAuth()` → 401.

### Test framework + run commands
PHPUnit 10.5.63. `phpunit.xml` two suites: **Unit Tests** (`_tests/Unit`, no DB) and **Integration Tests** (`_tests/Integration`, needs MySQL `signula_test`). 14 unit test files / **342 tests, 1105 assertions — all green** (2 benign warnings; "Decryption error" lines are negative-path test output, not failures). 6 integration test files (DB-gated). Commands: `php vendor/bin/phpunit --testsuite="Unit Tests"` (verified passing), `php vendor/bin/phpunit` (all), `composer test` / `test:unit` / `test:integration` / `test:coverage`.

### Lint / static-analysis config
- `phpstan.neon` — **level 6**, `bootstrapFiles: _tests/bootstrap.php`, **paths `private_html`/`public_html` (resolve from repo root → DO NOT EXIST; actual dirs are under `web/`)**. So `composer analyze` as documented **fails** (`Path …/private_html does not exist`). Uses deprecated `checkGenericClassInNonGenericObjectType`. See B-002.
- `phpcs.xml` — base **PSR-12** + many Generic/Squiz sniffs, 4-space exact indent, `DisallowTabIndent`, forbidden-functions (eval/var_dump/print_r/die/sizeof), cyclomatic complexity 15/30, nesting 5/10. Same path problem (`<file>private_html</file>`) → `composer check-style` from repo root fails.
- `composer.json` PSR-4 maps `SIGNula\\` → `private_html/` (also wrong relative to repo root; classes are require-based, not namespaced — phpstan can't discover them, see below).

### House-style conventions actually in force
- **DIRECTORY_SEPARATOR:** strong — 208/271 files use it; **0** `require`/`include` lines with a literal `/` path. ✅ compliant with brief.
- **SIGNULA_INIT guard:** 80/93 `private_html` files have it. The 13 without: the 4 layout templates + 4 i18n `lang/en/*` data files (low risk), but **5 real class files lack it**: `security/RateLimiter.php`, `api/APIKeyManager.php`, `api/APIKeyMiddleware.php`, `api/RateLimitMiddleware.php`, `email/EmailDripProcessor.php` (B-005).
- **`declare(strict_types=1)`:** **0/271 files** — the brief's spirit (PHP 8.4, strictness) and phpstan-strict-rules point to strict types, but none are declared. Large but mechanical gap (B-008).
- **Comments/emoji:** heavy emoji-annotated docblocks throughout (matches house style). Prepared statements everywhere via Database wrapper. Modular `require_once` includes with guard checks.

### Brief coverage
Brief-mandated capabilities that are MISSING or PARTIAL in the actual code (grep/file evidence):
1. **alpha_html / beta_html + access control — MISSING.** Brief explicitly asks for `beta_html`/`alpha_html` app folders with access control. `ALPHA_DIR`/`BETA_DIR` constants exist in `config.php` but **the directories do not exist**; only empty `public_html_dev/` (favicons) + `public_html_landing/` stubs. No gating code. (B-019 / FEATURES)
2. **JWT API authentication — PARTIAL/STUB.** Brief mandates a "FULLY SECURE" JSON API. `BaseController::getUserByToken()` is `// TODO: Implement JWT validation; return null;` and **no JWT library is vendored** (`vendor/firebase` absent). Bearer-token auth is non-functional; only API-key auth works. (issue #41 → B-006)
3. **OAuth providers — PARTIAL vs brief list.** 9 of 11 wired (Google, Microsoft, Apple, Facebook/Meta, LinkedIn, Yahoo, Amazon, WordPress, PayPal). **Missing: LastPass and a generic OpenID Connect provider** (brief listed both). Instagram is folded into Facebook/Meta, not separate. (FEATURES)
4. **Email-template *designer* (user/admin-designable) — PARTIAL.** EmailTemplateManager gives full server-side CRUD + versioning + preview over `tblEmailTemplates`, and an admin `email-dashboard.php` exists, but there is **no visual/WYSIWYG/drag-drop designer UI** (no grapesjs/unlayer/tinymce/quill found) — brief wanted users/admins to "design their own templates." (FEATURES)
5. **Crypto-payment support — PRESENT** (Coinbase + `tblProviderDiscounts` with crypto discount, `PartnerPaymentService::getProviderDiscount('crypto',…)`), and **GDPR tooling — PRESENT** (AccountManager export/erasure, tblDataExportRequests, settings/privacy.php). **Apple Pay / Google Pay — PARTIAL** (settings + Stripe/PayPal routing only, no native sheets). Contact form, support/lockout/invitation **email notifications — PARTIAL** (TODO stubs; see Backlog). Biometrics/WebAuthn/passkeys — PRESENT.

### Issue reconciliation
All 40 open GitHub issues confirmed via `gh issue list` (numbers/titles match exactly).

| #NN | title | class | maps-to | note |
|-----|-------|-------|---------|------|
| #85 | Set Up Production Cron Jobs | OPERATIONAL | surface-to-user | needs prod host/crontab; `web/public_html/cron/` scripts exist |
| #84 | Configure Email Service Provider | OPERATIONAL | surface-to-user | 7 providers coded; needs prod creds in tblSettings |
| #83 | Configure Production Database Credentials | OPERATIONAL | surface-to-user | prod secret; user-only |
| #81 | Legal & Compliance Documentation Review | MANUAL-QA | surface-to-user | legal pages exist under SIGNula.com/legal; needs human/legal review |
| #80 | Complete API Documentation (OpenAPI/Swagger) | FEATURE | FEATURES | `api/docs/` present but no OpenAPI spec found |
| #79 | Finalize Production .htaccess Configuration | OPERATIONAL | surface-to-user | HTTPS/www rules commented out in `.htaccess` |
| #78 | Automated Backups & Disaster Recovery | OPERATIONAL | surface-to-user | host-level |
| #77 | Monitoring & Observability | OPERATIONAL | surface-to-user | host-level |
| #76 | Accessibility WCAG AA Audit | MANUAL-QA | surface-to-user | needs assistive-tech testing |
| #75 | Browser & Device Compatibility Testing | MANUAL-QA | surface-to-user | human cross-device |
| #74 | Performance & Load Testing | MANUAL-QA | surface-to-user | needs env + load tooling |
| #73 | Security Penetration Testing & OWASP Audit | SECURITY-DEFERRED | SECURE | full pentest = SECURE phase |
| #72 | E2E Manual Testing (300+ cases) | MANUAL-QA | surface-to-user | human QA |
| #71 | Deploy to Staging Environment | OPERATIONAL | surface-to-user | needs staging host |
| #70 | Configure Live Payment Provider Credentials | OPERATIONAL | surface-to-user | prod secrets |
| #69 | Execute Full Automated Test Suite (342 tests) | MANUAL-QA | surface-to-user | **already runs green locally (unit)**; integration needs DB |
| #67 | Build subscription & recurring billing module | FEATURE | FEATURES | much exists (PaymentManager/BillingScheduler/UsageBilling) — verify scope vs gap |
| #66 | iLyricsDB: checkout redirect & subscription portal | FEATURE | FEATURES | external-integration feature |
| #65 | iLyricsDB: product & tier configuration | FEATURE | FEATURES | external-integration feature |
| #64 | iLyricsDB: subscription & billing webhook events | FEATURE | FEATURES | external-integration feature |
| #41 | TODO: JWT validation in API BaseController | code | **B-006** | stub returns null; no JWT lib vendored |
| #40 | TODO: Replace placeholder payment redirect URLs | code | **B-007** | `partners/api/credit-actions.php:784` |
| #39 | TODO: Authenticated header for support ticket pages | code | **B-009** | `SIGNula.id/support/ticket.php:140` |
| #38 | TODO: Save contact form submissions to DB | code | **B-004** | `SIGNula.com/contact.php:81` — **tblContactSubmissions already exists** (mig 004); just wire it |
| #37 | TODO: Email verification for email changes | code | **B-003** | `account.php:83`, `settings/profile.php:232` |
| #36 | TODO: Email notifications (invite/lockout/support) | code | **B-010** | Auth.php:573, Organization.php:300, ticket.php:106, team-actions.php:173, members.php:188 |
| #35 | Inconsistent error response keys across API | code | **B-011** | mixed `'error'`(63)/`'message'`(30)/`'errors'`(3) keys |
| #34 | SMTP AUTH without encryption check | SECURITY-DEFERRED | SECURE | SMTPEmailProvider |
| #33 | Information disclosure via X-Mailer header | SECURITY-DEFERRED | SECURE | EmailTemplateBuilder.php:563 sets it |
| #32 | AMP component names not validated vs allowlist | SECURITY-DEFERRED | SECURE | EmailTemplateBuilder AMP path |
| #31 | $customCSS injection risk in AMP templates | SECURITY-DEFERRED | SECURE | EmailTemplateBuilder |
| #30 | Debug mode logs SMTP credentials plaintext | SECURITY-DEFERRED | SECURE | debug logging |
| #29 | Missing X-Frame-Options: DENY on API responses | SECURITY-DEFERRED | SECURE | confirmed absent in Response/api index |
| #28 | CSRF not checked on GET-based admin actions | SECURITY-DEFERRED | SECURE | `admin/api/*-actions.php` |
| #27 | URL reset tokens not urlencode()'d in email links | SECURITY-DEFERRED | SECURE | token in mail links |
| #26 | No email validation in queueEmail() | SECURITY-DEFERRED | SECURE/B-012 | EmailService.php:112 |
| #25 | Missing composite indexes on credential reset tables | code/perf | **B-013** | only single-col indexes in mig 017 |
| #24 | No pagination bounds on credential reset history | code/robustness | **B-014** | CredentialResetService LIMIT/OFFSET unbounded |
| #23 | Unsanitized reason text in credential reset ops | SECURITY-DEFERRED | SECURE | free-text reason field |
| #22 | Missing adminUserID validation in credential reset API | SECURITY-DEFERRED | SECURE | user-actions.php reset endpoints |

**Reconciliation counts:** code-actionable B- = **13** (#41,#40,#39,#38,#37,#36,#35,#26[also security],#25,#24) covering B-003,004,006,007,009,010,011,012,013,014; OPERATIONAL = **11** (#85,84,83,79,78,77,71,70 + 83 host); MANUAL-QA = **7** (#81,76,75,74,72,69); FEATURE = **6** (#80,67,66,65,64 + OpenAPI); SECURITY-DEFERRED = **11** (#73,34,33,32,31,30,29,28,27,23,22).

## Backlog

Syntax sweep result: **`php -l` over all 271 `web/` PHP files + `_scripts`/`_tests`/root → ZERO parse errors.** No B-items for syntax. PHPStan level-6 = 4695 errors but the majority are **symbol-discovery artifacts** (1133 "static method on unknown class Database", 415 method-on-unknown-class, 137 instantiated-class-not-found, 124 constant-not-found, 68 function-not-found) caused by phpstan not loading the require-based classes — config issue (B-002), not real bugs. Genuine-signal categories: `empty()` not allowed (1148) + short-ternary (116) + "only booleans allowed" (~400) = strict-rules style (B-016); **"Function invoked with N parameters, N required" (322)** + **"Variable might not be defined" (48)** = real correctness risks (B-015); 151 params with no type (B-008). PHPCS = **4884 errors + 281 warnings / 258 files, 4649 auto-fixable** — top sniffs: scope-indent (1901), space-after-cast (832), control-structure spacing (520/383/168), file-header spacing (257) — almost all whitespace/auto-fixable (B-017); real-signal: cyclomatic complexity >15 (85/17), forbidden functions (113), side-effects-in-class-file (134).

`B-NNN | title | type | impact | effort | blast | gh`

- B-001 | Fix phpstan/phpcs runnability — `composer analyze`/`check-style` error out (paths `private_html` unresolved from repo root) | code-quality | High | S | (phpstan.neon, phpcs.xml; behaviour-touching? no) | —
- B-002 | phpstan can't discover require-based classes (4150+ "unknown class/function" false errors) — needs bootstrap/scan config so real bugs aren't drowned | code-quality | High | M | (phpstan.neon, _tests/bootstrap.php; no) | —
- B-003 | Email verification not sent/enforced on email-address change (account.php:83, settings/profile.php:232) | correctness | High | M | (account.php, profile.php, EmailService, tblEmailVerificationTokens; yes) | gh:#37
- B-004 | Contact form silently discards submissions — not persisted/notified despite tblContactSubmissions existing | correctness | High | S | (SIGNula.com/contact.php; yes) | gh:#38
- B-006 | JWT bearer-auth is a stub returning null — API "FULLY SECURE" brief unmet; no JWT lib vendored | correctness | High | M | (BaseController.php; self-hosted JWT lib; yes) | gh:#41
- B-007 | Placeholder payment redirect URLs in partner credit top-up (credit-actions.php:784) — checkout dead-ends | correctness | High | M | (partners/api/credit-actions.php, payment providers; yes) | gh:#40
- B-010 | Missing email notifications: lockout (Auth.php:573), org invite (Organization.php:300), support ticket (ticket.php:106), team invite (team-actions.php:173), member resend (members.php:188) | correctness | High | M | (5 files + EmailService; yes) | gh:#36
- B-015 | phpstan "function invoked with wrong arg count" (322) + "variable might not be defined" (48) — real call-site/uninitialised-var bugs to triage | correctness | High | L | (many files; yes) | —
- B-005 | 5 real class files missing SIGNULA_INIT guard (RateLimiter, APIKeyManager, APIKeyMiddleware, RateLimitMiddleware, EmailDripProcessor) — direct-access exposure | security | High | S | (5 files; no) | —
- B-009 | Support ticket pages use unauthenticated header (ticket.php:140) — wrong/leaky chrome for logged-in users | robustness | Med | S | (SIGNula.id/support/ticket.php; yes) | gh:#39
- B-011 | Inconsistent API error response keys (`error` vs `message` vs `errors`) — breaks client error handling | robustness | High | M | (api/controllers/*, Response.php, admin/api/*; yes) | gh:#35
- B-012 | queueEmail() does not validate recipient email — malformed addresses enter queue | robustness | Med | S | (EmailService.php:112; yes) | gh:#26
- B-013 | Missing composite indexes on credential-reset tables — slow scans at scale | perf | Med | S | (mig 017 / new migration; no) | gh:#25
- B-014 | Unbounded pagination on credential-reset history queries — potential large result/DoS | robustness | Med | S | (admin/CredentialResetService.php; yes) | gh:#24
- B-016 | ~1660 strict-rules style hits (`empty()` 1148, short-ternary 116, non-bool conditions ~400) — adopt strict comparisons | code-quality | Med | L | (many; mostly no) | —
- B-017 | 4649 auto-fixable PHPCS whitespace violations (scope indent 1901, space-after-cast 832, control-spacing) — run phpcbf | code-quality | Med | M | (258 files; no) | —
- B-008 | No `declare(strict_types=1)` in any of 271 files; 151 params untyped — house-style/strictness gap | code-quality | Med | L | (all files; no) | —
- B-018 | Cyclomatic complexity >15 in 85 functions (17 over absolute 30) — refactor hotspots | code-quality | Low | L | (85 functions; no) | —
- B-019 | alpha_html/beta_html dirs + access control absent (constants defined, no dirs/gating) — brief-mandated staging gating missing | ux | Med | M | (config.php, new dirs/middleware; yes) | —
- B-020 | Test coverage gaps for core flows: no tests for Auth, Database, all payment providers (Stripe/PayPal/Coinbase), EmailService, WebAuthnHandler, AccountManager, Organization, UsageTracker/UsageBillingService, ExportService | test | High | L | (new _tests/*; no) | —
- B-021 | VERSION (2.6.0-beta) disagrees with PROJECT_PROGRESS/docs (2.7.0-beta); progress doc over-claims "100% complete" vs TODO stubs | code-quality | Low | S | (VERSION, PROJECT_PROGRESS.md; no) | —
- B-022 | OpenAPI/Swagger spec missing despite `api/docs/` — API not machine-documented | code-quality | Med | M | (public_html/api/docs; no) | gh:#80
- B-023 | Side-effects in 134 class files & 113 forbidden-function uses flagged by PHPCS (likely die()/print_r in scripts) — triage non-test ones | code-quality | Low | M | (flagged files; no) | —
- B-024 | CORS misconfig: `Response::setCorsHeaders()` sends `Access-Control-Allow-Origin: *` WITH `Allow-Credentials: true` — invalid + insecure for credentialed API/JWT requests; tighten to a configurable origin allowlist | security | High | S | (api/Response.php; yes) | — (found in G-003 spec)
- B-025 | `tblBillingSchedule` schema↔code drift: mig 012 PK/columns/ENUM (`scheduleID`,`completedAt`,`errorMessage`) don't match scheduler reads/writes (`taskID`,`result`,`nextRetryAt` + task types `suspend_account`/`calculate_usage`/`charge_usage`/`archive_usage`) — errors on clean install | correctness | High | S | (mig 012 + new mig, BillingScheduler.php; yes) | gh:#67 (found in G-002 spec; fix as G-002 build-stage 0)
- B-026 | Stale `SessionManager::isLoggedIn` references in 3 API docblock comments (no such class; sessions handled by Auth/SessionGuard) — cosmetic comment cleanup, NOT a runtime bug (verified: 0 executable usages) | code-quality | Low | S | (invoice-actions.php, usage-actions.php, tier-actions.php docblocks; no) | — (phpstan-surfaced cycle 3)
- ✅ **B-027 DONE (c5)** — broader than scoped: `Database::insert()`/`insertId()` were called by **7 files** (WebAuthnHandler, PasswordlessLoginHandler, AccountManager, EmailScheduler/ABTesting/DripCampaign/TemplateManager) and never existed → all fataled. Added the 2 helper methods to database.php; all unbroken. ORIG: `Database::insert()` called by `WebAuthnHandler::storeChallenge()`/`storeCredential()` (lines 420,538) but NO such method exists on Database (no `insert()`, no `__callStatic`) → WebAuthn registration + challenge storage THROW `Error` and are non-functional | correctness | High | S | (WebAuthnHandler.php; maybe add Database::insert helper; yes) | — (PoC-pinned cycle 4; SAFETY FLOOR) |
- ✅ **B-028 DONE (c5)** — premise INVERTED: canonical is lowercase `'active'` (the ENUM + all app code); BaseController was already correct. Real drift was in test fixtures → aligned to lowercase. ORIG (as mis-stated): `BaseController::getUserById()/getUserByApiKey()` filter `accountStatus='active'` (lowercase)
- B-029 | Integration suite ~63 errors when run against a real MySQL DB (unit suite stubs DB so they were invisible): AuthLogin, MFA, RateLimiter, SecurityMiddleware incl. a `RateLimiter::checkLimit()` static-call mismatch (same B-027 "missing/mismatched method" class). Needs: stand up signula_test DB, run integration suite, TRIAGE real-bugs vs schema/harness drift, fix the real ones. BLOCKS "core task works e2e" | correctness | High | L | (many; yes) | — (surfaced cycle 6) |
- B-030 | WebAuthn challenge-consumption TOCTOU race: `verifyChallenge` (SELECT isUsed=0) and `markChallengeUsed` (UPDATE) are non-atomic with the sig-check between → a genuine freshly-signed assertion can authenticate twice under concurrency (PoC). Fix: atomic `UPDATE…SET isUsed=1 WHERE challenge=? AND isUsed=0` + affected_rows===1 before granting; same for sign-count `WHERE signCount<?` | security | Med | S | (WebAuthnHandler.php; yes) | — (red-team cycle 6) |
- B-031 | `WebAuthnHandler::verifyChallenge` uses `if ($userID)` (falsy for userID=0) — harden to `!== null`. Not reachable today (userID≥1) | security | Low | S | (WebAuthnHandler.php; yes) | — (red-team cycle 6) |
- B-032 | Usernameless/discoverable-credential WebAuthn login fails closed: `generateAuthenticationOptions(null)` stores userID=NULL challenge but verify requires the credential owner's userID → valid usernameless assertion rejected (availability, fail-safe) | correctness | Med | M | (WebAuthnHandler.php; yes) | — (red-team cycle 6) |
- B-033 | Consolidated installer `signula_complete_install_v2.5.0.sql` FAILS a fresh install (~71 errors): pervasive INT vs BIGINT FK type drift (userID/partnerID/createdBy), two conflicting tblSettings/tblMigrations schemas, double-defined WebAuthn tables, FK to never-created tblEmailQueue. **Blocks "builds & runs from clean checkout".** Needs regenerated/validated installer (reconcile source migrations first) | correctness | High | L | (installer + many migrations; yes) | gh:#83-adjacent (found cycle 7) |
- B-034 | Multi-org feature half-present: Auth.php/Organization.php reference tblOrganizations/tblOrganizationDomains/tblOrganizationMembers but NO migration creates them → runtime errors on org flows. (mig 025 added only tblUsers.organizationID to unblock registration) | correctness | High | M | (new migration + verify Organization.php; yes) | — (found cycle 7) |
- B-035 | tblUserMFA legacy (`isEnabled`/`totpSecret`) vs new (`mfaEnabled`/`mfaSecret`/`isVerified`) columns coexist after mig 026 (additive) — consolidation (rename+data copy) is a data-migration decision | code-quality | Low | M | (migration; yes-data) | — (cycle 7) |
- B-036 | Source-migration FK type drift INT vs BIGINT across files (e.g. mig 001 tblEmailUnsubscribes.userID INT → tblUsers BIGINT; mig 005 WebAuthn FKs) — widen to BIGINT (non-destructive) in a focused pass | code-quality | Med | M | (many migrations; no) | — (cycle 7; relates B-033) |
- B-037 | `ActivityLoggerTest` intermittent failure (~1 in 3 full Integration runs; green on re-run/isolation) — test-isolation / transaction / order dependency in ActivityLogger::log path; undermines Integration suite as a reliable gate | test | Med | M | (ActivityLoggerTest, DatabaseTestCase, maybe ActivityLogger; yes) | — (confirmed cycle 7) |
- B-038 | Duplicate migration numbers 003–006 (003_email_drip vs 003_oauth, etc.) — ambiguous apply order; non-standalone (001 starts with ALTER). Renumber/reconcile for deterministic fresh installs | code-quality | Low | M | (migrations dir; no) | — (cycle 7; relates B-033) | while app/fixtures use `'Active'` — API auth path would fail to resolve users on a case-sensitive (`_bin`) collation | robustness | Low | S | (BaseController.php; yes) | — (found cycle 4) |

## Run record
Bootstrap DISCOVER — codebase audit complete 2026-06-30
- cycle 3 STABILIZE-1 (B-001/B-002/B-005): `composer analyze`/`check-style` now RUN (paths fixed, `scanDirectories` added, `--memory-limit=1G`); unknown-class false-positives 647→74 (74 genuine: SessionManager comment-refs, uninstalled TCPDF); **real phpstan baseline ≈3632 errors** (322 wrong-arg-count + ~1148 strict `empty()` + missing helpers `requireLogin`/`timeAgo`/`isLoggedIn` — fodder for B-015/B-016); 5 SIGNULA_INIT guards added; 342 tests still green.

## Proposed-Features ledger
see FEATURES.md

## Trajectory ledger
| cycle | phase | move | result | commit |
|-------|-------|------|--------|--------|
| 1 | DISCOVER | bootstrap: codebase map + 23-item backlog + 17 feature gaps + 40-issue reconciliation | docs written; 0 parse errors, 342 tests green; tooling-config bug found (B-001) | b6128b4 |
| 2 | COMPLETE-prep | author full specs for user-approved G-001/G-003/G-002/G-004 | 4 build-ready specs written; surfaced B-024 (CORS) + B-025 (billing schema drift) + suggested FG-018 (SCIM) | 39b0bcd |
| 3 | STABILIZE | B-001/B-002/B-005: fix phpstan/phpcs runnability + class discovery + 5 SIGNULA_INIT guards | tools run; unknown-class FPs 647→74; phpstan baseline ≈3632 real errors; 342 tests green; no behaviour change | e5376fd |
| 4 | STABILIZE | B-020 safety-net: +48 characterization tests for API-auth/Auth/WebAuthn/Email seams | 342→390 tests green; surfaced HIGH B-027 (WebAuthn registration broken) + FG-013 (WebAuthn auth-bypass, PoC) + pinned B-024/B-012/B-011/B-028 | 850a9b8 |
| 5 | STABILIZE | B-027 + B-028: add `Database::insert()`/`insertId()` (missing, fataling 7 callers); align fixtures to canonical lowercase accountStatus | 390 green; UNBROKE PasswordlessLogin + 4 email features + AccountManager + WebAuthn storage with one helper; BaseController already correct (premise inverted) | 4387288 |
| 6 | SECURE(safety-floor) | FG-013: implement real WebAuthn verification (minimal CBOR/COSE→PEM, openssl_verify, sign-count clone-detect) + 2 pre-existing bug fixes (storeChallenge bind-type, ActivityLogger::log signature) | 401 green (+11 crypto tests); RED-TEAM verdict HOLDS across 7 vectors; auth-bypass closed. Residuals → B-030/B-031/B-032. Builder's DB run exposed ~63 integration errors → B-029 | a54aa03 |
| 7 | STABILIZE | B-029: stand up signula_test DB (new `_scripts/setup_test_db.sh`) + triage Integration suite | Integration 39err/14fail → green on re-run (B-037 flake remains); FIXED 3 systemic prod bugs — ErrorLogger::log (61 sites), EmailService bind→queueEmail() failing every call, MFA `backup_code` ENUM; +2 additive migrations (025/026) unblock registration (missing organizationID) + MFA columns. Residuals B-033..B-038 | cycle-7 commit |
