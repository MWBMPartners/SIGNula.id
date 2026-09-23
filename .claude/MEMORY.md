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
