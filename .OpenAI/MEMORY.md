# 🧠 Codex / OpenAI memory — SIGNula.id

This mirrors `.claude/MEMORY.md` (kept in sync; if they differ, the more recent one wins
and the other should be updated).

- Last synced: 2026-09-24 — added standing rule 11 "Watchdog" (issue #129); handoff kept at `HANDOFF.md` (top of the project) so any AI tool can use it.
- Working branch: `claude/review-merge-branches-main-ckh2sr`, built on `alpha` (cloud sessions can only push to their own branch; owner question Q1 still open).
- **Watchdog (rule 11):** at the start of every session run `bash .claude/hooks/session-watchdog.sh`
  and confirm every row in HANDOFF.md "⏱️ Jobs in flight". Log each background job there when
  it starts, and don't move on until its real result is confirmed.
- `beta` / `release-candidate` already contain all of `main` + the 23 Sep library updates (found 24 Sep).
- Open PRs: #118, #93 (automatic library updates, both already merged into `alpha`).
- Next queue: owner's Codex review → thorough docs update → self-hosted Swagger UI backup
  files → tidy PRs #118/#93 → #94, #101.
- Switched off by default: SAML, age-gate, retention purge, entitlement enforcement;
  billing is test-mode only.
