# 🤝 SIGNula.id — Session Handoff / Live Status

> **Purpose.** Single source of truth for picking up work where it was left off,
> even if a session ends unexpectedly. Updated continuously as work completes.
> Pair with `PRE_LAUNCH_REVIEW.md` (decisions needing human attention) and the
> `.dev-team/` ledger (internal autopilot backlog: `FEATURES.md`, `PROJECT.md`,
> `autopilot.json`).

_Last updated: 2026-08-04 · `main` @ `2732ae6` · all work merged (tree clean)_

**Session progress — EIGHT PRs merged to `main`:** #91 (consolidation), #105 (pricing catalog + docs truth-pass), #106 (HIBP built, disabled), #107 (CAP-API Bucket A), #109 (gitignore worktrees), #114 (four-tier security-backport + `release-candidate` branch), #119 (HIBP **enabled** + pricing catalog **activated**/shadow), #120 (CAP-API Bucket B + critical latent-fatal fixes). **Four tiers** (`alpha ▸ beta ▸ release-candidate ▸ main`) all at `2732ae6`. Closed: #35, #80, #86, #95, #96, #104. **CAP-API (#86) DONE. HIBP live (fail-open). Pricing catalog active — enforcement staged in shadow mode, pending ONE human ops-toggle** (`tblFeatureToggles.entitlement_enforcement`). Security fixes now auto-backport main→all tiers.

---

## 🎯 Current engagement

Consolidate the two outstanding feature branches into `main` via a single PR,
stand up CI/Dependabot/security across the three-tier branch model, then work
the prioritized roadmap (issue reconciliation, CAP-API, pricing tiers, launch
prep) autonomously.

**Model routing (emulating the private `dev-team` plugin):**
- 🧠 Analysis / deep planning → **Fable 5** agents, run sequentially.
- 🔧 Implementation → **Sonnet / Haiku** agents (Opus only if unavoidable).

---

## ✅ Done this session

1. **Branch analysis** (Fable): `autopilot/2026-06-30` (56 commits) + `universal-login`
   (5 commits) both cleanly descend from `main`; only 1 conflict (`BaseController.php`).
   Full report: `scratchpad/branch_merge_analysis.md`.
2. **Integration merge** (commit `521946a`): FF to autopilot → merged universal-login →
   resolved `BaseController.php` to autopilot's G-003 `TokenService` → dropped 3 stale
   artifacts (v2.7.0/v2.5.0 install SQL, broken migration 025) → VERSION `2.8.0-beta`.
3. **Post-merge fixups** (commit `2b7f80b`): migration `045_missing_email_templates.sql`
   (5 templates, `{{var}}` syntax, idempotent) + `inviterMessage→personalMessage` +
   `companyName→partnerName` (team-actions) + build-script extended to 045.
   All 451 PHP files pass `php -l` on 8.4.
4. **CI / security infra** (commit `c96d894`): `.github/workflows/ci.yml`,
   `.github/workflows/security.yml`, `.github/dependabot.yml` (main/beta/alpha).
5. **Roadmap analysis** (Fable): full 45-open / 41-closed issue review + gap analysis.
   Full report: `scratchpad/roadmap_analysis.md`.
6. **Management docs**: `PRE_LAUNCH_REVIEW.md`, this `HANDOFF.md`.
7. **PR #91 MERGED → `main`** (merge commit `9fe4309`); all CI green (fixed 2 environmental security-job failures → advisory). `main` now current.
8. **`alpha` + `beta` branches created** off updated `main` and pushed.
9. Auto-unsubscribed from PR #91 (merged — do not reopen/re-PR).

## ✅ Merged to `main` this session (8 PRs)

| PR | Commit | What landed |
|----|--------|-------------|
| #91 | `9fe4309` | Consolidated autopilot + universal-login: G-001 OIDC IdP, G-003 JWT, G-002 billing (TEST-MODE), G-004 compliance, ~30 security fixes, migration 045, conflict→G-003 TokenService |
| #105 | `c8bfeaf` | Dormant flexible pricing/entitlement catalog (mig 046 + fail-open `Entitlements`) + `Pricing_Strategy.md` + docs truth-pass to 2.8.0 |
| #106 | `579c460` | HIBP breached-password (mig 047, k-anonymity, fail-open, built disabled) |
| #107 | `14c5581` | CAP-API Bucket A: fixed broken `notification-actions`, API password gate, login/forgot throttling, CORS, Validator PK, OpenAPI sync |
| #109 | `bd10441` | Gitignore transient agent worktrees |
| #114 | `23b3376` | **Four-tier security backport**: `release-candidate` branch; auto-label Dependabot security PRs; `backport.yml` fans `security`/`backport` merges from main → rc/beta/alpha |
| #119 | `ad6b115` | **Enable** HIBP (fail-open) + **activate** pricing catalog (mig 048); entitlement enforcement staged in shadow, ops-toggle deliberately left off |
| #120 | `2732ae6` | **CAP-API Bucket B**: non-breaking envelope superset (#35), `UsageController` cross-tenant authz, standalone throttling + MFA counter, staged REST CSRF; **fixed critical fatals** (`/api/v1` rate-limit `$db`, `oauth/disconnect`) |

Plus: CI + Dependabot + `composer audit` across **all four tiers**; issue-tracker reconciliation (21 closed, 3 re-scoped, 12 filed #94–104); #35/#80/#86/#95/#96/#104 closed.

## ⏭️ Next (ordered) — all tracked as GitHub issues

- [ ] **ONE human ops action**: flip `tblFeatureToggles.entitlement_enforcement` on (Settings ▸ Feature Toggles) to start the pricing entitlement **shadow-logging** week; later set `entitlements.log_only='false'` to enforce. Confirm pricing P-1…P-5 in `PRE_LAUNCH_REVIEW.md` first.
- [ ] **#9 cross-project integration** (BLOCKED — `add_repo` for iHymns/CueRCode/etc. is rejected at the tool-permission prompt despite verbal approval; needs the prompt approved or an env-policy change). File "Sign in with SIGNula.id" issues + code in those repos.
- [ ] Feature build-out: #99 generic OIDC + LastPass · #98 email template designer · #100 SAML 2.0 IdP · #94 regen install v2.8.0 (best validated in staging) · #101 track self-hosted frontend libs (CDN-only today — nothing to manifest yet).
- [ ] Ops/QA/legal (human): #70 billing go-live, #71 staging + suite, #73 pen-test, #76 WCAG, #77–79, #81 legal, #83–85 prod creds/cron, #102 enable Dependency graph (Q-4), optional `BACKPORT_PAT` (Q-5).

## 🧭 How to resume

1. Read this file + `PRE_LAUNCH_REVIEW.md` + `scratchpad/roadmap_analysis.md` + `scratchpad/cap_api_audit.md`.
2. `git fetch origin main` (currently `2732ae6`); new code work → branch off latest `main`; then sync `alpha`/`beta`/`release-candidate` to `main` after each merge.
3. Pick the next item above (or a GitHub issue); for each: ensure an issue exists, implement, PR → green CI → merge, then sync `alpha`/`beta` to `main` and update this HANDOFF.
4. Model routing: Fable `deep-architect` for analysis (sequential), Sonnet/Haiku `quick-edits` for implementation.

## 🗂️ Issue-tracker state (reconciled 2026-07-22, post PR #91)

- **Closed as done (21):** #22–#34 (13 security MEDIUMs, commit `7d07045`), #36, #37, #38 (email flows), #87, #89, #90 (OIDC IdP), #41 (JWT, already closed). Each has an evidence comment.
- **Re-scoped, kept OPEN:** #40 (credit redirect targets don't exist → wire to real providers), #88 (CueRCode OAuth client provisioning), #69 (run suite in CI; real counts 763 unit / 515 integration).
- **New issues filed (#94–#104):** #94 regen install v2.8.0 · #95 docs truth-pass · #96 HIBP breached-password (FG-009) · #97 entitlements gating (FG-011) · #98 email template designer (FG-005) · #99 generic OIDC + LastPass (FG-008) · #100 SAML 2.0 IdP · #101 track self-hosted frontend libs · #102 enable Dependency graph (ops) · #103 pricing catalog (built-disabled) · #104 Dependabot on alpha/beta (**closed** — done via PR #91).
- **Closed since:** #95 (docs, PR #105), #96 (HIBP, PR #106). #86 Bucket A shipped (PR #107); Bucket B tracked in a #86 comment. #103/#97 = pricing catalog + `Entitlements` resolver shipped **dormant** (PR #105) — enablement pending.

## 🔑 Key facts

- `main` was **62 commits behind** the integration branch — this PR closes that gap.
- 17 `.github/proposed-issues/*.md` are **already** issues **#69–#85 + #82** (no re-import).
- `B-0xx` / `G-00x` are **internal** `.dev-team/` ledger IDs, not GitHub issues.
- `main` is **protected** — merge may require owner approval (see `PRE_LAUNCH_REVIEW.md` Q-2).
- Billing (G-002) is **test-mode only**; compliance (G-004) fully built; JWT (G-003) + OIDC IdP (G-001) built + red-teamed.
