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
| Q-1 | **`alpha` and `beta` branches did not exist.** | The Dependabot request assumed a three-tier `alpha ▸ beta ▸ main` model, but only `main` existed on GitHub. Plan: **cut `alpha` and `beta` from the post-merge `main`.** Confirm this is the intended model, and confirm which environments each branch deploys to. |
| Q-2 | **`main` is a protected branch.** | If branch protection requires an approving review from a non-author, the automation cannot self-merge the PR. May need the owner to approve/merge, or to grant the automation bypass. Will surface the exact blocker at merge time. |
| Q-3 | **Self-hosted frontend libraries are unmonitored.** | Bootstrap / jQuery / FontAwesome are self-hosted (no build step on Dreamhost) and tracked by no manifest, so Dependabot cannot watch them. Options: add a `package.json` (dev-only, for version tracking) or a lightweight manifest + a checker. Tracked as a follow-up issue. |
| Q-4 | **Enable the repository Dependency graph + Dependabot alerts/security updates** (owner-only repo setting). | At `Settings ▸ Code security` (https://github.com/MWBMPartners/SIGNula.id/settings/security_analysis). This is REQUIRED for: (a) the `Dependency Review` PR check to actually run (it's currently advisory and no-ops without it), and (b) Dependabot **security** update PRs on `main`. The committed `dependabot.yml` handles scheduled **version** updates regardless, but alerts/security-updates need this toggle. |

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

_Append new decisions and open items above their section footer as work proceeds._
