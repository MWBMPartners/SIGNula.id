# 🤖 Context for Codex / OpenAI tools — SIGNula.id

You are helping on **SIGNula.id**, a universal single-sign-on account system
(PHP 8.4, MySQLi prepared statements, MySQL/MariaDB, Dreamhost shared hosting).

**Read these before doing anything** (they are shared with Claude Code and are the
source of truth):
1. `.claude/STANDING_RULES.md` — how we work (plain English, GIRFT, review loop,
   no PR stacking, AI-tool fallback, after-each-task steps).
2. `HANDOFF.md` (top of the project) — current position, next-steps queue, open questions.
3. `.claude/CLAUDE.md` — the full project brief (tech stack, coding style, security rules).
4. `.OpenAI/MEMORY.md` — lasting facts.

## Your usual role
- **Reviewer**: review the changes on the working branch (currently `alpha`), report
  problems, have them fixed, and review again until nothing is found.
- **Fallback builder**: if Claude Code is unavailable, pick up the queue in
  `HANDOFF.md` (top of the project) and keep that file up to date as you go, so Claude can take over again.

## After each piece of work
Commit + push to the working branch · update the GitHub issue · update `.claude/` and
`.OpenAI/` memory/context · update `HANDOFF.md` (top of the project).
