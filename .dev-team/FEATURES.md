# Feature Gap Ledger

> Produced by the FEATUREFIND discovery pass (dev-team-featurefind). This file PROPOSES and SCORES gaps.
> Nothing here is approved to build. Building is gated — under autopilot, the conductor's autonomy test;
> standalone, the user's Feature Spec Gate. Evidence discipline: grep proof for "already implemented";
> a competitor citation per claimed table-stakes gap.

## Aim  (the yardstick every gap is measured against)
SIGNula is a self-hosted "universal" all-in-one SSO / Customer Identity & Access Management (CIAM)
platform (PHP 8.4 + MySQLi + MariaDB, Dreamhost shared hosting, no CLI/Composer in prod). It lets
end-users create one "SIGNula ID" and use it across many first- and third-party web/mobile services,
with MFA, passwordless, social account-linking, passkeys, a secure partner REST API, white-label
multi-tenancy, multi-tier paid subscriptions, an account-management hub, activity logging, and
data-protection/compliance tooling.  ·  **category:** CIAM / universal SSO / identity provider.

## Run record
- **last-run:** 2026-06-30   ·   **scope-setting:** balanced   ·   **depth:** standard   ·   **version:** v2.7.0-beta
- **scout method:** 3 read-only codebase scouts (auth/MFA/OAuth/passkey · API/partner/payments/billing ·
  account-hub/email/compliance/admin) + direct grep verification; 1 web-enabled competitor researcher.
- **No prior PROJECT.md / Codebase Map existed** → full cold scout (not a delta refresh).

## Comparables researched
- **Auth0 / Okta Customer Identity** — leader — full-stack CIAM (IdP, enterprise SSO, adaptive MFA, FGA) — auth0.com/docs
- **Clerk** — direct (B2C/B2B SaaS) — drop-in components, orgs, passkeys — clerk.com/docs
- **WorkOS** — direct (B2B) — enterprise SSO, Directory Sync/SCIM, Audit Logs, AuthKit — workos.com/docs
- **Supabase Auth** — adjacent (self-host-friendly) — magic link, MFA, passkeys, leaked-password protection — supabase.com/docs/guides/auth
- **Firebase / Identity Platform** — adjacent (B2C) — MFA, blocking functions, multi-tenancy, reCAPTCHA — firebase.google.com/docs/auth
- **Stytch** — direct — passwordless, B2B IdP, device fingerprinting — stytch.com/docs
- **FusionAuth** — direct (self-hosted, closest analog) — SAML/OIDC IdP, lambdas, breached-password, SCIM — fusionauth.io/docs
- **Keycloak** — direct (self-hosted, closest analog) — SAML/OIDC IdP, identity brokering, LDAP/AD federation, impersonation — keycloak.org/docs
- **Frontegg** — direct (B2B) — self-serve admin portal, entitlements engine, token templates — developers.frontegg.com
- **Descope** — direct — no-code flow builder, ReBAC/ABAC, identity federation — descope.com
- **Coverage:** domains swept — SSO-protocols (acting-as-IdP), enterprise-SSO/directory-sync, embeddable
  UI/SDKs, MFA breadth, passwordless, account-security, B2B/orgs/authz, user-management, extensibility/hooks,
  compliance, branding/i18n, logs/SIEM, developer-experience, token/session.
  **Not covered / not public** — closed enterprise pricing internals; exact SaaS compliance-cert scopes
  (vendor marketing pages only, tagged low-confidence); native mobile SDK internals.

---

## Summary of what SIGNula ALREADY ships (so these are NOT gaps)
Confirmed IMPLEMENTED by grep (do not re-propose): internal username/password (Argon2id) + password
history/reuse-prevention; TOTP authenticator MFA + QR; MFA backup/recovery codes (single-use); passwordless
email magic-link AND email OTP code; WebAuthn/passkey registration & assertion (with a verification caveat —
see FG-013); 9 social OAuth providers wired (Google personal+Workspace, Microsoft personal+365, Apple,
Facebook+Instagram-via-FB, LinkedIn, Yahoo, Amazon, WordPress, PayPal); OAuth account-LINKING to one SIGNula
ID; session management + SessionGuard fingerprinting + remember-me + **"terminate all other sessions"**
(`web/public_html/security.php` action `terminate_all_sessions`); impossible-travel detection
(`SecurityAlertManager`); forgot-password flow; account-management hub (profile, connected-accounts, mfa,
passkeys, security, activity, privacy, notification-center pages under `web/public_html/settings/`); REST API
(Router/Response/Validator, `/api/v1`, API-key auth + per-key scopes/rate-limits/audit); partner/white-label
multi-tenancy (`tblPartners`, partner admin portal, partner tiers/features/team); **outbound webhooks with
HMAC-SHA256 signing + retries + delivery log** (two systems); 5 real payment providers (Stripe incl.
subscription mode + trials, PayPal, Coinbase crypto, Ko-fi, Patreon); Apple Pay/Google Pay (via Stripe
Payment Element); usage/metered billing (bcmath); invoicing/credits/discounts/service-fees/remittances;
**OpenAPI spec + Swagger UI** (`web/public_html/api/docs/openapi.yaml`, 43 paths) — so issue #80 is PARTIAL
not absent; activity logging (`tblActivityLog`, IPv4+IPv6, UA, event types) + user & admin viewers; bot
protection (Turnstile + reCAPTCHA + honeypot/FormProtection); in-app notification center; i18n (file-based,
locale detection, RTL); PWA (manifest + service worker + offline); dark mode; rate limiting + admin dashboard;
GDPR account-deletion + data-export (`AccountManager::permanentlyDeleteAccount` / `exportUserData`).

---

## (A) BRIEF-MANDATED but UNBUILT / PARTIAL  (highest priority — the product promised these)

### FG-001 — SIGNula as an IdP / token issuer for relying-party apps (SAML 2.0 + OIDC provider + OAuth2 authorization-server)
- **class:** table-stakes  **importance:** High  **prevalence:** 6/10 (Auth0, Keycloak, FusionAuth, Stytch, Descope, Frontegg)
- **effort:** L  **risk:** High  **confidence:** high
- **seen-in:** Auth0 (auth0.com/docs/authenticate/login/oidc-conformant-authentication, docs), Keycloak (keycloak.org/docs server-admin, docs), FusionAuth (fusionauth.io/docs/apis, docs), Stytch B2B (stytch.com/docs, docs), Descope (descope.com, docs)
- **purpose-fit:** THE defining CIAM capability and the brief's core promise ("universal SSO… used across a variety of services… integration via JSON-based API to exchange authentication"). Today SIGNula only *consumes* OAuth (Google/MS) and exposes a bespoke API-key REST API; it does NOT issue standard OIDC ID-tokens / SAML assertions / OAuth2 tokens to partner apps. **Skeptic-confirmed absent:** the only `grant_type` hits are SIGNula consuming Google/MS for cloud-export; no SAML library/assertion builder anywhere (grep for `SAMLResponse|xmlseclibs|onelogin|id_token.*generate` empty). `web/public_html/oauth/authorize.php` is for email-delegation/sign-in CONSUMPTION, not RP token issuance.
- **autonomy-eligible:** NO — large architectural, security-critical (token signing, JWKS, key rotation), external-touching. NEED-USER-APPROVAL.
- **spec-seed:** Add an OAuth2/OIDC authorization-server: `/oauth/authorize` + `/oauth/token` + `/.well-known/openid-configuration` + JWKS endpoint; register partner apps as OAuth clients (extend `tblPartners`/`tblAPIKeys`); issue signed JWT id_tokens/access_tokens with custom claims; later add a SAML 2.0 IdP profile. Self-hosted PHP libs (no Composer in prod) must be vendored. This is the single biggest gap — likely a multi-phase epic.

### FG-002 — Data-protection/compliance tooling beyond GDPR (CCPA/CPRA opt-out, consent audit trail, multi-jurisdiction DSAR, retention/erasure scheduler)
- **class:** table-stakes  **importance:** High  **prevalence:** 4/10 vendors offer consent/DSAR tooling (Auth0, Frontegg, Descope, Firebase) — low-confidence (compliance pages are marketing)
- **effort:** L  **risk:** Med  **confidence:** high (gap) / low (competitor evidence strength)
- **seen-in:** Auth0/Frontegg/Descope privacy & audit docs (vendor compliance pages, third-party/pricing strength)
- **purpose-fit:** The brief explicitly enumerates 18+ regimes (GDPR, UK-GDPR/DPA2018, CCPA/CPRA/VCDPA/CPA/CTDPA/UCPA, HIPAA, COPPA, PIPEDA, LGPD, AU/NZ Privacy Acts, DPDP, PIPL, CSL/DSL, APPI, PIPA, POPIA) and asks for "functionality to manage any data to comply." **Confirmed:** only GDPR access/erasure is functional (`AccountManager`); no `tblConsent` audit table (grep empty), no "do not sell"/CCPA opt-out flow (only static `privacy.php` text), no general PII data-retention auto-purge (only usage-records purge in `UsageTracker::purgeOldRecords`), no COPPA age-gating, no HIPAA-specific controls.
- **autonomy-eligible:** PARTIAL — a **consent-record audit table + cookie-consent persistence + DSAR request tracker** sub-slice is table-stakes, Low-risk, in-scope → could be autonomy-eligible if scoped tightly (FG-002a). The legal-judgement parts (which regimes, age-gating, "do not sell" semantics) NEED-USER-APPROVAL. Default: NEED-APPROVAL for the umbrella; see FG-002a.
- **spec-seed:** `tblConsentRecords` (userID, consentType, granted, ipAddress, userAgent, policyVersion, timestamp) for an auditable trail; persist cookie-consent server-side not just localStorage; a DSAR request queue (access/rectify/erase/port/opt-out) with status workflow reusing the `tblDataExportRequests` pattern; a per-table retention scheduler hung off `BillingScheduler`.

### FG-002a — Consent audit trail + DSAR request tracker (narrow, buildable slice of FG-002)
- **class:** table-stakes  **importance:** High  **prevalence:** 4/10 (as FG-002)
- **effort:** M  **risk:** Low  **confidence:** high
- **seen-in:** Frontegg audit/consent (developers.frontegg.com, docs), Auth0 (auth0.com/docs, docs)
- **purpose-fit:** Brief mandates auditable consent + data-subject-request handling; the codebase already has the `tblDataExportRequests` + `AccountManager` export/delete pattern to extend, and `tblActivityLog` for logging — the seams exist.
- **autonomy-eligible:** YES — table-stakes, Low-risk (additive table + read/write UI, no external/payment touch, reversible), in-scope, naturally hosted by existing data-model.
- **spec-seed:** Add `tblConsentRecords` + `tblDataSubjectRequests`; record consent on signup/policy-change with IP/UA/policyVersion; a settings page + admin queue to view/fulfil access/erase/port/opt-out requests; wire into existing export/delete methods. No legal interpretation — just the audit+workflow plumbing.

### FG-003 — Recurring billing / subscription lifecycle engine (autonomous renewals, dunning, proration, plan change, trial conversion, auto-suspend/resume)
- **class:** table-stakes  **importance:** High  **prevalence:** n/a for CIAM proper; brief-mandated; GitHub issue #67
- **effort:** L  **risk:** High  **confidence:** high
- **seen-in:** Frontegg entitlements/billing tie-in (developers.frontegg.com, docs); Stripe Billing as the underlying model (docs.stripe.com/billing, docs)
- **purpose-fit:** Brief mandates multi-tier subscriptions with paid features; **issue #67 = "Build subscription & recurring billing module."** Confirmed: schema (`tblSubscriptions`, `tblBillingSchedule`) + Stripe subscription-mode checkout + trials exist, but the **autonomous charge engine is scaffolded** — `BillingScheduler` lacks working polling/charge/dunning/proration/auto-suspend handlers; no plan upgrade/downgrade or proration; no failed-payment retry sequence.
- **autonomy-eligible:** NO — payment-touching, external (PSP charges), irreversible (real money), large. NEED-USER-APPROVAL. (Route via dev-team-stripe.)
- **spec-seed:** Implement `BillingScheduler` task handlers: poll subscriptions where `nextBillingDate <= NOW()`, call PSP recurring-charge API, update status/period, generate invoice; dunning retry ladder (n attempts + grace) with email; auto-suspend→auto-resume tier swap; proration on plan change. Lean on Stripe Billing webhooks already partly wired.

### FG-004 — JWT-based API authentication (machine-to-machine bearer tokens)
- **class:** table-stakes  **importance:** High  **prevalence:** 9/10 (refresh-token rotation/JWT near-universal)
- **effort:** M  **risk:** Med  **confidence:** high
- **seen-in:** Auth0/Keycloak/FusionAuth/Stytch/Frontegg token docs (docs); GitHub issue #41
- **purpose-fit:** Brief wants a "FULLY SECURE" JSON REST API; **issue #41 = "TODO: Implement JWT validation in API BaseController"** — confirmed stub: `BaseController::getUserByToken()` returns `null` with `// TODO: Implement JWT validation`. Currently only API-key + session auth. Needed for embedded app/mobile flows and as the substrate for FG-001.
- **autonomy-eligible:** LEANING-NO — security-critical auth path (token forgery risk if done wrong) and overlaps the FG-001 epic; better built as part of the IdP work under approval. NEED-USER-APPROVAL (Med risk).
- **spec-seed:** Issue short-lived signed JWTs (HS256/RS256) on login + refresh-token rotation with reuse detection; implement `getUserByToken()` to verify signature/exp/aud/iss against a key in encrypted settings; revocation list keyed off `tblSessions`. Vendor a self-hosted JWT lib (no Composer in prod).

### FG-005 — Email template designer (visual/WYSIWYG editor for users & admins)
- **class:** table-stakes  **importance:** Med  **prevalence:** 7/10 (editable templates near-universal; visual designer common)
- **effort:** M  **risk:** Low  **confidence:** high
- **seen-in:** Clerk/Stytch/Supabase/Frontegg/Descope/FusionAuth template-customization docs (docs)
- **purpose-fit:** Brief explicitly: "ability for a user/admin to design their own Templates… a native/fallback." Confirmed: a code-level `EmailTemplateBuilder`/`EmailTemplateManager` (CRUD, versioning, preview) exists but there is **no admin/user WYSIWYG or HTML editor UI** — templates are edited programmatically or via raw DB. The `tblEmailTemplates` model + render/preview methods already host the data; only the editor UI is missing.
- **autonomy-eligible:** YES — table-stakes, in-scope, Low-risk (additive admin UI over an existing model + existing preview), reversible, no external/payment touch. *Caveat:* must sanitise template HTML (the existing AMP/customCSS XSS issues #31/#32 show this surface needs care) — keep it server-rendered with strict escaping/allowlist.
- **spec-seed:** Admin page listing `tblEmailTemplates` with create/edit; an HTML editor (vendor a self-hosted lightweight editor, or a safe-subset block editor) + live preview via existing `previewTemplate()`; variable picker; version history via existing versioning. Enforce HTML sanitisation/allowlist on save.

### FG-006 — Push-notification MFA approval (passwordless "approve on phone")
- **class:** differentiator  **importance:** Med  **prevalence:** 3/10 (Auth0 Guardian, FusionAuth, Descope)
- **effort:** L  **risk:** Med  **confidence:** high
- **seen-in:** Auth0 Guardian push (auth0.com/docs, docs), FusionAuth (fusionauth.io/feature-list, docs)
- **purpose-fit:** Brief lists "password-free login/verifications offered by these MFA apps using their push notifications." Confirmed STUBBED: `tblUserMFA.pushToken` column + `'push'` enum exist; comment says "Push notification support (infrastructure ready)"; no FCM/APNs send/verify logic. Requires a mobile app + push provider, which SIGNula doesn't yet have.
- **autonomy-eligible:** NO — needs external push infra (FCM/APNs) + a companion mobile app; external-touching, Med risk, larger than a web feature. NEED-USER-APPROVAL.
- **spec-seed:** Integrate FCM/APNs; on login, create a pending approval, push to enrolled device, poll/await approve/deny; verify device-signed response. Gated on having a native app or PWA push.

### FG-007 — SMS / voice OTP MFA channel (real send provider)
- **class:** table-stakes  **importance:** Med  **prevalence:** 6/10 (SMS OTP common: Auth0, Firebase, Stytch, Descope, Frontegg, Supabase-phone)
- **effort:** M  **risk:** Med  **confidence:** high
- **seen-in:** Firebase (firebase.google.com/docs/auth/web/multi-factor, docs), Stytch (stytch.com/docs, docs)
- **purpose-fit:** Brief's MFA breadth + `tblUserMFA` already has `'sms'` enum and `SMS_OTP_LENGTH`/`SMS_OTP_VALIDITY` constants ("infrastructure ready"), but no actual SMS gateway (Twilio/Vonage) send is wired (grep: only an inbound Twilio *email* webhook header). Email OTP already covers the OTP UX, so this is parity-completion not net-new flow.
- **autonomy-eligible:** NO — external-touching (paid SMS gateway, credentials, deliverability), Med risk/cost. NEED-USER-APPROVAL.
- **spec-seed:** Add a pluggable SMS provider (Twilio/Vonage) behind a `SmsProvider` interface with encrypted creds in `tblSettings`; reuse existing `MFA` OTP generate/verify, swap the delivery channel; rate-limit + cost guardrails.

### FG-008 — Generic OpenID Connect connector + LastPass + standalone Instagram (brief-listed providers not wired)
- **class:** nice-to-have  **importance:** Low  **prevalence:** 6/10 (generic OIDC connector common: Auth0/Keycloak/FusionAuth/Descope)
- **effort:** M (generic OIDC) / S each (named)  **risk:** Low  **confidence:** high
- **seen-in:** Keycloak identity brokering (keycloak.org/docs, docs), Auth0 custom OIDC connections (auth0.com/docs, docs)
- **purpose-fit:** Brief's provider list names "OpenID," LastPass, and Instagram. Confirmed: 9 named providers wired in `auth/providers/`, but **no generic/custom OIDC connector** (grep empty), **no LastPass** provider, and **Instagram only via Facebook** (no standalone). A generic OIDC connector is the higher-value of the three (covers arbitrary IdPs incl. the long tail).
- **autonomy-eligible:** LEANING — the **generic OIDC connector** is in-scope, Low-risk (mirrors existing provider classes + existing OAuth flow), additive, no payment/destructive touch → autonomy-eligible. LastPass/Instagram-standalone are Low-importance; build only if asked. (Mark generic-OIDC eligible; named providers parked.)
- **spec-seed:** Add `GenericOIDCProvider` taking issuer/clientID/secret/scopes from `tblSettings`, doing discovery via `/.well-known/openid-configuration`; register in the OAuth flow handler like the 9 existing providers. LastPass/Instagram: defer (low demand, LastPass OAuth is niche).

---

## (B) COMPETITOR TABLE-STAKES not explicitly in the brief

### FG-009 — Breached / leaked-password detection (HaveIBeenPwned k-anonymity)
- **class:** table-stakes  **importance:** High  **prevalence:** 4/10 security-serious (Auth0, FusionAuth, Supabase, Firebase)
- **effort:** S  **risk:** Low  **confidence:** high
- **seen-in:** Auth0 Breached Password Detection (auth0.com/docs/secure/attack-protection/breached-password-detection, docs), FusionAuth (fusionauth.io/feature-list, docs), Supabase leaked-password protection (supabase.com/docs/guides/auth, docs)
- **purpose-fit:** The brief demands "extremely secure… current and future industry standards." Confirmed ABSENT (grep `pwned|breach|leaked.?password|k.?anonym` empty in security/auth). The password set/change path (`SecurityUtils::hashPassword`, password-history already wired) is the exact seam to add a check.
- **autonomy-eligible:** YES — table-stakes, Low-risk (one outbound HIBP range API call, fail-open, k-anonymity so no password leaves; additive at registration/change/reset), in-scope, reversible.
- **spec-seed:** On password set/change/reset, SHA-1 the password, send the 5-char prefix to HIBP range API, compare suffixes locally; if found, warn/block per a `security.password.block_breached` setting; cache + fail-open on API error. No password ever transmitted.

### FG-010 — Fine-grained authorization / permissions engine (RBAC with a permission catalogue; ABAC/ReBAC later)
- **class:** table-stakes  **importance:** Med  **prevalence:** 8/10 (RBAC near-universal; FGA/ReBAC common)
- **effort:** L  **risk:** Med  **confidence:** high
- **seen-in:** Clerk RBAC (clerk.com/docs, docs), Auth0 FGA (auth0.com/docs, docs), Keycloak Authorization Services (keycloak.org/docs, docs), Descope RBAC/ReBAC/ABAC (descope.com, docs)
- **purpose-fit:** As an SSO platform integrated into many apps, fine-grained authz is an expected capability and underpins tier feature-gating (FG-011). Confirmed PARTIAL: `Organization.php` has coarse string roles + member invitations, and there are admin tiers — but no permission catalogue, no `tblPermissions`/role-permission mapping, no runtime `hasPermission(resource, action)` policy layer that partner apps can query.
- **autonomy-eligible:** NO — cross-cutting authorization model, Med risk (mis-scoped permissions = security holes), larger design. NEED-USER-APPROVAL.
- **spec-seed:** Add `tblPermissions` + `tblRolePermissions`; define a permission catalogue; `Authz::can(userID, orgID, permission)` checked in controllers + exposed via API for partner apps; build on existing `tblOrganizationMembers.role`.

### FG-011 — Runtime tier/entitlement feature-gating enforcement
- **class:** table-stakes  **importance:** Med  **prevalence:** 2/10 explicit (Frontegg entitlements, Clerk) but core to a paid-tier product
- **effort:** M  **risk:** Low  **confidence:** high
- **seen-in:** Frontegg entitlements engine (developers.frontegg.com/guides/authorization/entitlements, docs), Clerk billing/roles (clerk.com/docs, pricing)
- **purpose-fit:** The brief's whole monetisation premise: "paid tiers and features available within those tiers." Confirmed PARTIAL: `tblSubscriptionTiers`/`tblFeatureToggles`/`tblPartnerFeatures` define features+limits, but there is **no runtime enforcement layer** ("is this user/partner entitled to feature X / under limit Y?"). The data model already carries tiers+limits — only the guard is missing.
- **autonomy-eligible:** YES — table-stakes for THIS product, Low-risk (read-only entitlement check over an existing schema; additive helper + guards), in-scope, the seams (tiers, limits, feature toggles) already exist. *Not* payment-touching (it reads subscription state, doesn't charge).
- **spec-seed:** `Entitlements::has(userID, featureKey)` + `Entitlements::withinLimit(userID, limitKey, current)` reading `tblSubscriptions` → tier → `featureLimits`; a controller/API guard returning 402/403 when over-tier; surface remaining quota in the account hub. Charging stays out of scope here.

### FG-012 — SIEM / audit-log streaming (export auth+admin events to Datadog/Splunk/S3/HTTP)
- **class:** table-stakes  **importance:** Low  **prevalence:** 3/10 enterprise (Auth0, WorkOS explicit; sold as priced add-on)
- **effort:** M  **risk:** Low  **confidence:** high
- **seen-in:** Auth0 Log Streams (auth0.com/docs/customize/log-streams, docs), WorkOS Log Streams (workos.com/docs/audit-logs/log-streams, docs/pricing)
- **purpose-fit:** SIGNula already has rich `tblActivityLog`/`tblAdminAuditLog`/`tblErrorLog`; streaming them outward is an enterprise expectation but lower importance for SIGNula's near-term first-party-app aim. Confirmed ABSENT (grep `siem|splunk|datadog|log.?stream` empty).
- **autonomy-eligible:** LEANING-NO — outbound integration to third-party log sinks (external-touching, credentials), and Low importance for the current aim. Park as NEED-APPROVAL / nice-to-have.
- **spec-seed:** A log-stream sink config (`tblLogStreams`: type=http/datadog/splunk/s3, endpoint, encrypted token, event filter) + a scheduled batch shipper hung off the existing cron/scheduler, reusing the webhook-delivery retry pattern.

### FG-013 — Complete WebAuthn assertion verification (signature + CBOR + sign-count clone detection)
- **class:** table-stakes  **importance:** High (security-correctness)  **prevalence:** 7/10 (passkeys near-universal — and they verify properly)
- **effort:** M  **risk:** Med  **confidence:** high
- **seen-in:** Supabase passkeys (supabase.com/docs/guides/auth/passkeys, docs), Stytch/Descope/FusionAuth/Keycloak WebAuthn (docs)
- **purpose-fit:** Passkeys are advertised as supported and the registration/assertion ceremony exists, BUT `WebAuthnHandler::verifyAuthentication()` validates only challenge/origin/type — its own comments state signature verification, authenticator-data parsing, and sign-count clone-detection are NOT implemented ("requires proper CBOR parsing and cryptographic verification"). This is **completing a partial feature**, and a real auth-bypass risk, more than a net-new feature — likely better routed to the security pass, but logged here as a table-stakes correctness gap.
- **autonomy-eligible:** NO — cryptographic auth-correctness, Med risk if done wrong; needs a vetted vendored WebAuthn lib. NEED-USER-APPROVAL (route to dev-team-security).
- **spec-seed:** Vendor a self-hosted WebAuthn lib (e.g. web-auth/webauthn-lib, pre-installed — no Composer in prod); verify clientDataJSON, authenticatorData, RP-ID hash, user-presence/verification flags, the COSE-key signature over (authData ‖ clientDataHash), and monotonic sign-count for clone detection; store/compare counters.

---

## (C) DIFFERENTIATORS / NICE-TO-HAVE (in-scope, lower priority)

### FG-014 — Adaptive / risk-based MFA & step-up authentication
- **class:** differentiator  **importance:** Med  **prevalence:** 4/10 (Auth0, Descope, Frontegg, Stytch)
- **effort:** M  **risk:** Med  **confidence:** high
- **seen-in:** Auth0 Adaptive MFA (auth0.com/docs, docs), Descope (descope.com/use-cases/mfa, docs)
- **purpose-fit:** SIGNula already computes risk signals (`SecurityAlertManager` impossible-travel, SessionGuard fingerprint) but only *alerts* — it doesn't *trigger* a step-up MFA challenge on risk. Wiring the existing signals into an adaptive challenge is a natural, in-scope extension. Confirmed: no step-up flow (alerts exist, challenge does not).
- **autonomy-eligible:** NO — changes the auth decision path (Med risk of lockout/bypass). NEED-USER-APPROVAL.
- **spec-seed:** Risk score from existing signals (new device/IP, impossible-travel, VPN); if over threshold, force an MFA challenge / re-auth before completing login or sensitive action; configurable thresholds in `tblSettings`.

### FG-015 — Self-serve partner "admin portal" for tenant-configured SSO/branding (B2B onboarding)
- **class:** differentiator  **importance:** Low  **prevalence:** 4/10 B2B (WorkOS, Frontegg, Descope, Clerk)
- **effort:** L  **risk:** Med  **confidence:** high
- **seen-in:** WorkOS Admin Portal (workos.com/docs, docs), Frontegg admin portal (developers.frontegg.com/guides/admin-portal, docs)
- **purpose-fit:** SIGNula has a partner admin portal already (tiers/features/team/payment/webhooks), but the B2B "let each tenant's IT self-configure their own SSO connection + branding" pattern depends on FG-001 (being an IdP) existing first. In-scope long-term, blocked on FG-001.
- **autonomy-eligible:** NO — depends on FG-001 epic, Med risk. NEED-USER-APPROVAL.
- **spec-seed:** Once SIGNula is an IdP, give partners a portal to register their app's redirect URIs/SAML metadata, theme the hosted login, and manage their own connection — extend the existing `web/public_html/partners/admin/` surface.

### FG-016 — Custom JWT claims / token templates for partner apps
- **class:** differentiator  **importance:** Low  **prevalence:** 5/10 (Auth0, Frontegg, FusionAuth, Keycloak, Supabase)
- **effort:** M  **risk:** Low  **confidence:** high
- **seen-in:** Frontegg token templates (developers.frontegg.com, docs), Auth0 Actions (auth0.com/docs, docs)
- **purpose-fit:** Lets partner apps receive app-specific claims in issued tokens. Depends on FG-001/FG-004 token issuance existing first; in-scope as a follow-on.
- **autonomy-eligible:** NO — depends on the token-issuance epic. NEED-USER-APPROVAL.
- **spec-seed:** Per-partner claim mapping config; inject custom claims into issued JWTs at token time. Build after FG-004.

### FG-017 — User impersonation ("login as user" for admin support)
- **class:** nice-to-have  **importance:** Low  **prevalence:** 4/10 (Keycloak, Auth0, FusionAuth, Frontegg)
- **effort:** S  **risk:** Med  **confidence:** high
- **seen-in:** Keycloak impersonation (keycloak.org/docs, docs), FusionAuth (fusionauth.io/docs, docs)
- **purpose-fit:** A support convenience; the session model could host it. But it is a sensitive privilege-escalation surface and not brief-mandated. Confirmed ABSENT (grep `impersonat|loginAs` empty in app code).
- **autonomy-eligible:** NO — privilege-escalation surface (Med risk: audit, consent, scope of impersonation). NEED-USER-APPROVAL.
- **spec-seed:** Admin-only, heavily audited (`tblAdminAuditLog`) impersonation token with a visible banner + auto-expiry + restricted scope (no password/MFA changes while impersonating).

---

## iLyricsDB partner-integration issues (#64/#65/#66) — tracked, OUT-OF-SCOPE for this generic ledger
- **#65 product & tier config / #66 checkout redirect + portal / #64 subscription & billing webhooks** — these are a
  **specific partner onboarding**, not a generic SIGNula capability gap. The generic capabilities they exercise are
  already covered above: checkout/redirect + portal (existing checkout + FG-003 lifecycle), product/tier config
  (existing tier system), subscription/billing webhooks (existing outbound webhook system, HMAC-signed). They are
  external/payment-touching → **NEED-USER-APPROVAL** and belong to a partner-integration build (dev-team-stripe /
  orchestrator), not autonomous featurefind work. Logged so they appear in the ledger; not scored as generic FG- gaps.

## Out-of-scope (surfaced, NOT recommended)
- **Native mobile SDKs (iOS/Android), CLI tool, no-code visual flow builder** — seen in Clerk/Supabase/Firebase/Descope.
  **Why excluded:** SIGNula's near-term aim is web + PWA + REST API for first-party apps; native SDKs and a CLI are a
  different product surface the codebase isn't oriented around (no SDK/build tooling, Dreamhost shared = no CLI). Revisit
  if/when native apps are built. The no-code flow builder is a Descope/Frontegg differentiator beyond SIGNula's aim.
- **Outbound SCIM (push users TO downstream apps)** — even Auth0 lacks native outbound SCIM. Not table-stakes; out of scope.
- **Vendor-held SOC 2 / ISO 27001 certification** — a self-hosted *software* product cannot be "certified"; only the
  operator's deployment can. Not a code feature. (Compliance *tooling* that helps the operator IS in scope — see FG-002.)
- **`alpha_html`/`beta_html` access-control gating** (brief mention) — a deployment/release-process concern, not a product
  feature gap; the repo uses `public_html_dev`/`public_html_landing` instead. Park as ops, not FG-.

## Snapshot caveat
Competitor features as of 2026-06-30, from public sources; vendor compliance/MFA-channel rows lean on marketing pages
(tagged lower-confidence). Marketing claims may overstate; SaaS-vendor certs are about their hosted service not their
software. Codebase status verified by grep against v2.7.0-beta. Re-run to refresh as both the product and the market move.
