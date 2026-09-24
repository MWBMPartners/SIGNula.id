# 🤝 SIGNula.id — Session Handoff (live status)

> **Start here in a fresh session.** Read in this order:
> 1. `.claude/STANDING_RULES.md` — how we work (rules + after-each-task steps)
> 2. this file — where we are and what's next — **start with the "⏱️ Jobs in flight" table (rule 11)**
> 3. `.claude/MEMORY.md` — lasting facts and decisions
> 4. `PRE_LAUNCH_REVIEW.md` — items waiting on the owner
>
> Older history: `.claude/archive/HANDOFF_2026-08-04.md` · internal backlog: `.dev-team/`

_Last updated: **2026-09-24 ~17:40 UK** · working branch `claude/review-merge-branches-main-ckh2sr` (built on `alpha`) · tree clean after this commit_

---

## 📍 Where we are right now (plain English)

- **All feature work up to early August is finished and merged** into `main` (14 PRs —
  see the archived handoff for the list). Main features built: sign-in/MFA/passkeys,
  "Sign in with SIGNula.id" for other apps, secure API tokens, billing (test mode only),
  privacy-law tools, email template designer, generic OpenID connector, SAML (switched off).
- **Since then (Aug–Sep):** only small housekeeping —
  - PR #128: all automated GitHub jobs must run on `ubuntu-latest` (merged to `main`).
  - Today (23 Sep) two automatic library-update PRs were merged into `alpha`
    (#110 code-style checker tool, #116 GitHub Actions updates).
- **This session (23 Sep):** refreshed the standing rules and this handoff so a brand-new
  session can pick up cleanly. **No app code was changed.** The handoff stays here at the
  top of the project (owner decision) so any AI tool can find it.
- **Found by the watchdog check (24 Sep), not in the previous handoff:** the same two
  library updates were **also merged into `beta` (#111, #126) and `release-candidate`
  (#117, #127)** on GitHub by Salem874 on 23 Sep ~21:30. So `beta` and `release-candidate` are
  **no longer behind `main`**. They contain everything on `main` plus those two updates. `main`
  is now the only tier without them (its copies, PRs #118/#93, are still open).
- **This session (24 Sep):** added **standing rule 11 "Watchdog"** (issue #129): every
  background job is logged in the "⏱️ Jobs in flight" table below, watched until it
  finishes, and its real result checked before the queue moves on. A **start-of-session
  hook** (`.claude/hooks/session-watchdog.sh`) now prints this reminder, plus any
  leftover unsaved/unpushed work, every time a Claude Code session starts, resumes or
  is compacted. **No app code was changed.**
- The owner may restart the session (to update Claude Code) **before the 00:08 Codex
  review**. That review is scheduled on the owner's side — it is *not* a scheduled job in
  this cloud account (checked: none exist).

### Branch picture
| Branch | State |
|---|---|
| `main` | `7b3d991` (PR #128) |
| `alpha` | `6d72b96` = `main` + the two library-update merges (#110, #116) + the 23 Sep docs commits |
| `beta` | `b8f5e1f` = `main` + the two library-update merges (#111, #126) |
| `release-candidate` | `1406b73` = `main` + the two library-update merges (#117, #127) |
| `claude/review-merge-branches-main-ckh2sr` | this session's working branch = `alpha` + the watchdog work (#129); goes into `alpha` in **one PR when the owner asks** (rule 6) |

### Open PRs (both automatic library updates aimed at `main`)
- #118 — GitHub Actions updates (same change already merged into `alpha` via #116)
- #93 — code-style checker v3 → v4 (same change already merged into `alpha` via #110)

---

## ⏱️ Jobs in flight (watchdog log — rule 11)

> Anything started in the background (helper agents, PR checks, merges, backports, long
> commands) is added here **when it starts** and removed only **once its real result is
> confirmed**. A new session checks every row **before** doing anything else.

| Started | Job | Where to check the result | Status |
|---|---|---|---|
| — | _Nothing in flight_ (checked 2026-09-24 ~17:40 UK) | — | — |

---

## ⏭️ Next steps queue (in order)

| # | Task | Status |
|---|---|---|
| 0 | **Watchdog rule + start-of-session hook** (#129) | ✅ done 24 Sep (on the working branch) |
| 1 | Answer the open questions below (owner) | ⏳ waiting on owner |
| 2 | Run the Codex review (owner's 00:08 slot) over `alpha`; fix → re-review until clean | ⏳ scheduled (owner side) |
| 3 | **Thorough documentation update** (all `.md`, in-app help, `.claude/`, `.OpenAI/`, OpenAPI) | 🔜 queued |
| 4 | Add the missing **self-hosted Swagger UI backup files** (`web/public_html/assets/lib/swagger-ui/`, v5.18.2) so the API docs page works if the CDN is down; check Swagger page vs `openapi.yaml` (53 paths) | 🔜 queued |
| 5 | Decide what to do with PRs #118/#93 (merge to `main`, or close). All three other tiers already have these updates, so `main` is the odd one out | 🔜 queued |
| 6 | ~~Bring `beta` / `release-candidate` up to date with `main`~~ | ✅ no longer needed. The 24 Sep watchdog check found they already contain all of `main` |
| 7 | Remaining feature tail: #94 regenerate install snapshot v2.8.0 · #101 track self-hosted libraries (incl. `web/_lib/xmlseclibs` 3.1.5) | 🔜 queued |
| 8 | Owner-only items: turn on entitlement shadow-logging; #70 billing go-live; #71 staging; #73 pen-test; #76 accessibility audit; #81 legal review; #83–85 live credentials/cron; #102 Dependency graph; SAML (#100) needs real-world testing before switching on; #9 cross-project integration (blocked on repo access approval) | 👤 owner |

---

## ❓ Open questions for the owner (answer when convenient — work continues meanwhile)

- **Q1. Which branch should the ongoing work go on?** The new rule says "one working
  branch that will later go into `alpha` via one PR". This cloud session is set up to push
  **straight to `alpha`**. Options: (a) keep pushing straight to `alpha` (simplest — current
  default), or (b) use a separate branch such as `dev/next` and open one PR into `alpha`
  later. *Until told otherwise, work goes straight to `alpha`.*
  **Update 24 Sep:** this session may only push to its own branch
  (`claude/review-merge-branches-main-ckh2sr`), so it is using option (b) with that branch
  name. It is built on `alpha` and will go into `alpha` in one PR when you ask.
- **Q2. "All projects on this device" rule (AI-tool fallback).** This session runs in a
  temporary cloud computer, so a device-wide setting written here disappears when the
  session ends. I've put a ready-to-copy version in
  `.claude/device-rules/GLOBAL_AI_FALLBACK_RULE.md` — copy it onto your Mac into
  `~/.claude/CLAUDE.md` (for Claude Code) and `~/.codex/AGENTS.md` (for Codex).
  Want me to do anything else with it?
  **Added 24 Sep:** the same applies to the new **Watchdog** rule. A ready-to-copy version
  (plus an optional automatic start-of-session reminder for every project) is in
  `.claude/device-rules/GLOBAL_WATCHDOG_RULE.md`.
- ~~Q3. Codex reviews~~ ✅ **Answered 23 Sep:** Codex reviews run from the owner's Mac
  (Codex and the dev-team plugins are not installed in the cloud computer — that's fine).

---

## 🧭 How to resume (fresh session)

1. `git fetch --prune origin`, then check out the working branch (see the branch picture above).
2. **Watchdog check (rule 11):** read what the start-of-session hook printed (or run
   `bash .claude/hooks/session-watchdog.sh`), compare branches and open PRs with this file,
   and confirm every row in "⏱️ Jobs in flight" before starting anything new.
3. Read `.claude/STANDING_RULES.md`, then this file.
4. Post the progress table (rule 8), ask any still-open questions **up front** (rule 7),
   then work the queue above top-down, **with a watchdog on every background job**.
5. After **each** task: confirm its background jobs finished, commit + push, update the GitHub issue, update `.claude/` and
   `.OpenAI/` memory/context, update this file.
