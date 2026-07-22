# 🤝 SIGNula.id — Session Handoff / Live Status

> **Purpose.** Single source of truth for picking up work where it was left off,
> even if a session ends unexpectedly. Updated continuously as work completes.
> Pair with `PRE_LAUNCH_REVIEW.md` (decisions needing human attention) and the
> `.dev-team/` ledger (internal autopilot backlog: `FEATURES.md`, `PROJECT.md`,
> `autopilot.json`).

_Last updated: 2026-07-22 · Working branch: `claude/post-merge-launch-prep` (off updated `main`)_

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

## 🔄 In progress (parallel agents, this session)

- ⏳ **Fable**: pricing strategy + flexible tier/entitlement schema design → `scratchpad/Pricing_Strategy.md` + `scratchpad/pricing_schema_design.md`.
- ⏳ **Sonnet**: issue-tracker reconciliation (verify+close ~20 done issues; re-scope #40/#88; refresh #69).
- ⏳ **Sonnet**: create ~11 detailed roadmap/follow-up issues.
- ⏳ **Sonnet**: docs truth-pass (README/PROJECT_STATUS/PROJECT_PROGRESS/SECURITY/CHANGELOG → 2.8.0).

## ⏭️ Next (ordered)

- [ ] Commit docs truth-pass; commit Pricing_Strategy.md + dormant pricing/entitlements schema (Sonnet impl from Fable design).
- [ ] Open a "launch-prep" PR off `claude/post-merge-launch-prep` → `main`; drive CI green; merge.
- [ ] **CAP-API** capstone (#86, absorbs #35/#80) — Fable design + Sonnet impl.
- [ ] Entitlements runtime gating (FG-011), HIBP breached-password (FG-009).
- [ ] Cross-project integration issues (iHymns, CueRCode #88, iLyricsDB #64–66, intAppsAPI) — file in those repos; write code where warranted, documented in-issue.
- [ ] Improvement-loop items (see `scratchpad/roadmap_analysis.md` §5).

## 🧭 How to resume

1. Read this file + `PRE_LAUNCH_REVIEW.md` + `scratchpad/roadmap_analysis.md`.
2. Check open PR state + CI on branch `claude/review-merge-branches-main-ckh2sr`.
3. Continue the ordered checklist above. New code work → branch off the latest `main`.
4. For each work item: ensure a GitHub issue exists (reopen if wrongly closed); on
   completion, update the issue, commit, and update this HANDOFF.

## 🔑 Key facts

- `main` was **62 commits behind** the integration branch — this PR closes that gap.
- 17 `.github/proposed-issues/*.md` are **already** issues **#69–#85 + #82** (no re-import).
- `B-0xx` / `G-00x` are **internal** `.dev-team/` ledger IDs, not GitHub issues.
- `main` is **protected** — merge may require owner approval (see `PRE_LAUNCH_REVIEW.md` Q-2).
- Billing (G-002) is **test-mode only**; compliance (G-004) fully built; JWT (G-003) + OIDC IdP (G-001) built + red-teamed.
