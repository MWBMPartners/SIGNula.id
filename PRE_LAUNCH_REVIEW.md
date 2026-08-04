# 🚦 SIGNula.id — Pre-Launch Review & Decision Log

> **Purpose.** A single place to review the important, human-attention-worthy
> decisions, assumptions, and open items accumulated during autonomous
> development, **to be walked through before the service launches.**
>
> This file is maintained continuously by the dev workflow. Newest section at
> the bottom of each list. Nothing here blocks day-to-day development, but every
> **🔴 Needs-Decision** and **🟠 Launch-Blocker** item should be resolved (or
> consciously accepted) before go-live.

_Last updated: 2026-07-22 (session: branch consolidation + launch prep)_

---

## 1. Autonomous decisions taken (FYI — reversible)

| # | Decision | Rationale | Reversible? |
|---|----------|-----------|-------------|
| D-1 | **Merged `autopilot/2026-06-30` + `claude/universal-login-system` into one integration branch → single PR to `main`.** | Both branches cleanly descend from `main` HEAD; a single PR avoids merge race conditions (as requested). | Yes (revert PR) |
| D-2 | **Resolved the one merge conflict (`BaseController.php`) in favour of autopilot's G-003 `TokenService` (RS256), dropping universal-login's hand-rolled HS256 JWT path.** | HS256 verifier was keyed off `ENCRYPTION_KEY`, had no revocation, and verified tokens nothing on either branch ever issued. Keeping both would reopen an alg-confusion class of bug the G-003 red-team pass closed. | Yes |
| D-3 | **Dropped 3 stale artifacts** in the merge: `signula_complete_install_v2.7.0.sql` (only migrations 001–024), archived `v2.5.0.sql` (divergent duplicate), `migration 025_missing_email_templates.sql` (broken `{var}`/`{#if}` syntax the mailer never renders). | Superseded / broken; recreated the useful parts as **migration 045** with correct `{{var}}` syntax. | Yes |
| D-4 | **Set `VERSION` to `2.8.0-beta`.** | UL's `2.7.0-beta` named the dropped snapshot. | Yes |
| D-5 | **Added CI** (`.github/workflows/ci.yml`): `php -l` lint is **blocking**; PHPStan + PHPCS are **advisory** (`continue-on-error`) for now. | The repo had `phpcs.xml`/`phpstan.neon` but no workflow. Advisory-first avoids blocking the merge on pre-existing style/analysis debt; ratchet to blocking after a baseline. | Yes |
| D-6 | **Added Dependabot on all three branches** (`.github/dependabot.yml`) for `composer` + `github-actions`. | Fulfils the alpha/beta coverage request. | Yes |
| D-7 | **Added a Security workflow** (`.github/workflows/security.yml`): PR dependency-review + scheduled `composer audit` matrixed over main/beta/alpha. | CodeQL does not support PHP (see 🔵 L-1); this is the PHP-appropriate substitute. | Yes |
| D-8 | **Emulated the private `dev-team` plugin** via model-routed agents — deep-architect→`claude-fable-5` for analysis/planning, quick-edits→`claude-haiku-4-5` + Sonnet for implementation. | The plugin (`MWBMPartners/dev-team-plugin`) is not installed in the web-session harness; the routing reproduces its subagent roster faithfully. | n/a |

---

## 2. 🔴 Needs a human decision

| # | Item | Context / options |
|---|------|-------------------|
| Q-1 | **Four-tier branch model now in place** (`alpha ▸ beta ▸ release-candidate ▸ main`). | Only `main` existed originally; `alpha`, `beta`, and `release-candidate` are now cut from `main`. Dependabot version-updates target all four; security fixes fan out via the backport workflow (see Q-5). Confirm which environment each branch deploys to. |
| Q-2 | **`main` is a protected branch.** | If branch protection requires an approving review from a non-author, the automation cannot self-merge the PR. May need the owner to approve/merge, or to grant the automation bypass. Will surface the exact blocker at merge time. |
| Q-3 | **Self-hosted frontend libraries are unmonitored.** | Bootstrap / jQuery / FontAwesome are self-hosted (no build step on Dreamhost) and tracked by no manifest, so Dependabot cannot watch them. Options: add a `package.json` (dev-only, for version tracking) or a lightweight manifest + a checker. Tracked as a follow-up issue. |
| Q-4 | **Enable the repository Dependency graph + Dependabot alerts/security updates** (owner-only repo setting). | At `Settings ▸ Code security` (https://github.com/MWBMPartners/SIGNula.id/settings/security_analysis). This is REQUIRED for: (a) the `Dependency Review` PR check to actually run (it's currently advisory and no-ops without it), and (b) Dependabot **security** update PRs on `main`. The committed `dependabot.yml` handles scheduled **version** updates regardless, but alerts/security-updates need this toggle. |
| Q-5 | **(Optional) add a `BACKPORT_PAT` repo secret** to run CI on auto-backport PRs. | The security-fix backport workflow (`.github/workflows/backport.yml`) opens cherry-pick PRs onto alpha/beta/release-candidate. PRs opened with the default `GITHUB_TOKEN` do **not** trigger CI (GitHub loop-prevention), so backport PRs would merge without lint/audit unless a PAT is provided. Add a secret `BACKPORT_PAT` (classic PAT or fine-grained token with `contents:write` + `pull-requests:write`) to make backport PRs run CI. Without it, everything still works — the PRs just need a manual CI nudge. |

---

## 3. 🟠 Launch-blockers (ops / external — not code)

These come from the merged production-readiness backlog (`.github/proposed-issues/`) and remain open:

- **Production DB credentials & hardening** (#02-equivalent)
- **Email service config**: SMTP + SPF/DKIM/DMARC for deliverability (#03)
- **Payment provider go-live**: billing is built in **TEST-MODE with a fail-closed guard** (G-002). Real PayPal/Stripe/Apple Pay/Google Pay/crypto credentials + live-mode switch required (#06).
- **Staging environment** (#07)
- **External security penetration test** (OWASP) — internal red-team passes were done on OAuth/JWT/billing, but an independent pentest is still open (#09).
- **Performance/load testing** (#10), **browser/device matrix** (#11), **WCAG AA audit** (#12), **monitoring/observability** (#13), **backup & DR** (#14), **production `.htaccess`** finalization (#15), **legal/compliance human review** of the generated policy documents (#17).

---

## 4. 🔵 Platform limitations discovered (documented, worked around)

| # | Limitation | Mitigation in place |
|---|-----------|---------------------|
| L-1 | **CodeQL has no PHP support.** | Replaced with `composer audit` + `actions/dependency-review-action` in `security.yml`. |
| L-2 | **Dependabot *security* updates only run on the default branch** (`target-branch` redirects *version* updates only). | main keeps security updates (no `target-branch`); alpha/beta get scheduled version updates + the matrixed `composer audit`. Residual gap documented in `dependabot.yml`. |
| L-3 | **Dreamhost shared hosting: no CLI/Composer in production.** | CI installs dev tooling in Actions only; runtime app uses `require`/`include`, not Composer autoload. |

---

## 5. 🔴 Pricing model — decisions to confirm before enabling monetization

The flexible pricing catalog is being **built dormant** (fail-open, `entitlements.enforcement_enabled=false`) so it changes no current behavior. Full rationale in `Pricing_Strategy.md`. Before enabling, confirm:

| # | Decision | Suggested default (reconfigurable — all catalog data, nothing hard-coded) |
|---|----------|---------------------------------------------------------------------------|
| P-1 | **Primary metering axis** | MAU (monthly active users) — the SSO-industry norm — vs. per-connection / per-request. |
| P-2 | **Tier ladder + price anchors** | Free (£0, 5k MAU) · Starter (£19/mo) · Pro (£49/mo) · Business (£149/mo) · Enterprise (custom, anchor ≥£500/mo). Annual = 10× monthly (~2 months free). |
| P-3 | **Never paywall security** | Free tier includes ALL MFA methods + passkeys (deliberate anti-Auth0 stance). Confirm. |
| P-4 | **Enterprise SSO delivery** | As a stackable add-on (~£60/connection) vs. gated behind the Business tier. |
| P-5 | **Free-tier MAU ceiling** | 5,000 MAU. |

Enabling is a later, deliberate step (flip `entitlements.enforcement_enabled` after a log-only shadow period). Billing go-live itself remains gated on issue #70 (live PSP credentials) and the G-002 test-mode guard.

---

## 6. 🟢 Features shipped BUILT-BUT-DISABLED (flip on before/at launch)

These are complete and safe (fail-open / dormant) but intentionally OFF so they change no current behavior. Enable each deliberately:

| Feature | How to enable | Notes |
|---------|---------------|-------|
| **HIBP breached-password check** (#96) | ✅ **ENABLED** (PR #119) — `security.breached_password_check.enabled=true`. | Live + fail-open across web **and** API password-set flows. |
| **Flexible pricing/entitlement catalog** (#103, #97) | ⚙️ **Activated, staged** (PR #119) — catalog rows live (5 tiers/108 features/8 prices); `entitlements.enforcement_enabled=true` + `log_only=true`. **Remaining human step:** flip `tblFeatureToggles.entitlement_enforcement` ON (Settings ▸ Feature Toggles) to begin the shadow-logging week, then later set `entitlements.log_only='false'` to actually enforce. | The resolver **still fails open** until that one ops-toggle is flipped — zero behaviour change so far. Confirm pricing P-1…P-5 + billing go-live (#70) before enforcing. |

---

## 7. 🔴 SAML 2.0 Identity-Provider — dormant foundation, NOT production-ready (#100)

Migration `050_saml_idp.sql` adds the full SAML 2.0 IdP data model
(`tblSAMLServiceProviders`, `tblSAMLServiceProviderAcsUrls`,
`tblSAMLAuthnRequests`, `tblSAMLAssertions`, `tblSAMLConsents`) plus a new
manager/engine/facade stack (`SamlServiceProviderManager`, `SamlKeyManager`,
`SamlMetadataService`, `SamlAuthnRequestService`, `SamlResponseBuilder`,
`SamlLogoutService`, `SamlXmlSignature`, `SamlRedirectBinding`) and three thin
controllers (`/saml/metadata`, `/saml/sso`, `/saml/slo`). It mirrors the
already-shipped OIDC/OAuth2 provider table-for-table. **Unlike** the
BUILT-BUT-DISABLED features in §6, this is **NOT simply "flip a switch before
launch"** — it is explicitly **not production-ready** until the two human
gates below are evidenced on issue #100:

| # | Decision / gate | Status |
|---|------------------|--------|
| S-1 | **`saml.enabled` master switch** (mirrors `oidc.enabled`) | Seeded `'0'` (OFF) by migration 050. Every `/saml/*` controller checks `SamlMetadataService::isSamlEnabled()` FIRST and 404s while off. Every `tblSAMLServiceProviders` row additionally defaults `isActive=0` (double-dormant). |
| S-2 | **xmlseclibs vendored** (`web/_lib/xmlseclibs/`, pinned `robrichards/xmlseclibs` 3.1.5, BSD-3-Clause) | ✅ Vendored — 4 files, openssl-only, no phpseclib/Composer dependency (verified against upstream's `main` branch, which now requires phpseclib — 3.1.x was deliberately pinned to avoid that). All XML-DSig sign/verify goes through the single `SamlXmlSignature` facade. |
| S-3 | **Sign-side XML-DSig (C2/"B1")** — `<Assertion>`/`<Response>` signing | ✅ Built + unit-verified (real RSA-3072 keys, real xmlseclibs sign+verify round trip, including XSW-guard negative tests) — safe to ship dormant per the plan's risk-asymmetry argument: a sign-side bug is an interop failure (SP rejects login), never a forgery. |
| S-4 | **Verify-side XML-DSig of POST-bound signed requests (C3/"B2")** | 🚧 **Deliberately NOT wired.** `SamlXmlSignature::verifyEnvelopedSignature()` is implemented (with the full XSW guard battery — single-signature, duplicate-ID, algorithm allowlist, reference-URI pinning) but is **not called by any controller/engine**. `SamlAuthnRequestService` policy-limits any SP with `wantAuthnRequestsSigned=1` to the HTTP-Redirect binding (C1 — plain `openssl_verify`, not XML parsing of attacker XML) until a dedicated, red-teamed stage lands. |
| S-5 (Gate 2) | **Staging interop** — full SSO against ≥2 independent SP stacks (e.g. SimpleSAMLphp + samltest.id/mocksaml.com/an Entra ID or Okta dev tenant): signature acceptance, NameID formats, attribute mapping, SLO, key-rotation overlap. | ⏳ Not yet run — this environment cannot run a live SP interop matrix. **Required before enabling.** |
| S-6 (Gate 3) | **Red-team pass** — XSW battery (wrapped/duplicated/detached signatures), XXE/DTD, DEFLATE-bomb, replayed handles/assertions, ACS-bypass attempts, SigAlg downgrade, RelayState XSS. | ⏳ Not yet run. Unit-level XSW/XXE/bomb/downgrade tests were written and pass in isolation (see the SAML IdP PR) — that is NOT a substitute for an adversarial red-team pass against the live, wired system. **Required before enabling.** |
| S-7 | **Admin SP-registration UI** | 🚧 **Deferred as a follow-up** — `SamlServiceProviderManager` (the full CRUD/validation backend) shipped; a `partners/admin/saml-providers.php`-style page (mirroring `partners/admin/oauth-clients.php`) was intentionally left for a follow-up PR to keep this delivery's review surface manageable. SP registration is fully usable today via direct calls to the manager class (e.g. from a one-off script or the DB console) for staging-interop testing. |

**Do not flip `saml.enabled` to `'1'` (or set any `tblSAMLServiceProviders.isActive=1`) until S-5 and S-6 are both evidenced on issue #100.**

---

_Append new decisions and open items above their section footer as work proceeds._
