# 🧠 SIGNula.id — Lasting memory (facts & decisions)

> Short, durable facts that don't change often. Live progress is in `HANDOFF.md`;
> rules are in `STANDING_RULES.md`. Update this when a decision is made.

## Project
- Universal single-sign-on account system ("SIGNula ID"). PHP 8.4 (min 8.3), MySQL/MariaDB via
  MySQLi prepared statements, hosted on Dreamhost shared hosting (no command line / Composer in
  production — libraries are copied into the repo). Full brief: `.claude/CLAUDE.md`.
- Version `2.8.0-beta` (see `VERSION`).
- API description: `web/public_html/api/docs/openapi.yaml`; browsable page:
  `web/public_html/api/docs/swagger/` (loads from CDN; self-hosted backup copy still missing).

## Branches & process
- Four tiers: `alpha ▸ beta ▸ release-candidate ▸ main`. `main` is protected.
- Security fixes on `main` are auto-copied to the other tiers (`.github/workflows/backport.yml`).
- All GitHub Actions jobs use `runs-on: ubuntu-latest` (PR #128).
- From 2026-09-23: **no PR stacking** — one working branch, one PR into `alpha` later.
- Cloud sessions can only push to their own `claude/...` branch. Current working branch:
  `claude/review-merge-branches-main-ckh2sr` (built on `alpha`, 24 Sep). Q1 in the handoff is still open.
- 23 Sep: Dependabot library updates were merged by the owner's account (Salem874) into
  **all three** lower tiers (`alpha` #110/#116, `beta` #111/#126, `release-candidate` #117/#127).
  `main`'s copies (#118/#93) are still open.

## 🐕 Watchdog (owner rule 11, 2026-09-24 — issue #129)
- Every background job (helper agent, PR checks/CI, merge, backport, long command) gets a
  watchdog: log it in HANDOFF.md "⏱️ Jobs in flight", wait until it finishes, check the
  **real** result, and only then continue the queue.
- Start of every session: `.claude/hooks/session-watchdog.sh` runs automatically
  (SessionStart hook in `.claude/settings.json`; offline, never fails, bash 3.2-safe).
  Other AI tools run it by hand.
- Use in-session background timers/loops. The owner declined scheduled "remind me later"
  messages in an earlier session, so don't rely on them without approval.

## Model / tool routing (owner decision 2026-09-23)
- Deep analysis + planning: **Opus, one agent at a time** (replaces the old "Fable" rule).
- Building: Sonnet or Haiku; Opus only for complex builds.
- Review: Codex, loop until clean. Cross-check with a different AI than the one that built it.
- If a tool is unavailable/out of credit → switch to another, switch back later + full review.

## Switched-off-by-default features (owner must turn on)
- SAML (`saml.enabled=0`), age-gate, retention purge, entitlement enforcement (shadow mode),
  billing (test mode only; live keys = owner, issue #70).

## Environment notes
- Cloud sessions: Codex and dev-team plugins are **not installed** in the cloud container (owner OK with this, 2026-09-23);
  Codex reviews run from the owner's Mac.
- The live handoff is `HANDOFF.md` at the **top of the project** (owner decision 2026-09-23) so any
  AI tool can find it. Old versions go in `.claude/archive/`.
