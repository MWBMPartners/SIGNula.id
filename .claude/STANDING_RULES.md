# 📜 SIGNula.id — Standing Rules & Standing Tasks

> **Read this first in every new session** (then `HANDOFF.md` at the top of the project).
> These rules apply to every AI helper working on this repo (Claude Code, Codex, or any other).
> Last revised: **2026-09-24** (owner instruction — added rule 11 "Watchdog"). Older rules
> that the owner has not replaced are kept in section C below.

---

## A. Standing rules (how we work)

### 1. 🗣️ Plain English
When reporting back or explaining things, **do not use technical jargon**. Explain in
simple, everyday English, even for technical readers. If a technical term can't be
avoided, explain it in a few plain words straight away.

### 2. 🤝 Keep the handoff up to date — all the time
Update `HANDOFF.md` (kept at the top of the project so any AI tool can find it) **as you go** (not just at the end), so work can be picked up
at any moment if a session stops, runs out of credit, or is restarted. This matters even
more because of rule 9 (switching between AI tools).

### 3. 🧠 Thinking, planning and model choice
- Think hard about the work before starting ("ultrathink"), and use **workflows** to plan
  and carry out the work.
- **Deep analysis and deep planning → Opus agents, one after another (not in parallel).**
  Reason: the newest Opus (Opus 5.5) is cheaper and at least as good as the newest Fable
  (as at 2026-09-23). *This replaces the old "Fable for planning" rule.*
- **Building the code → Sonnet or Haiku**, whichever fits. Use **Opus** for building only
  when the work is complex.
- Aim: use tokens/credit efficiently but still produce correct, top-quality code.
  **GIRFT — Get It Right First Time.**

### 4. 🧩 Use plugins to help
Use the **dev-team** plugins for any of this work, and for suggesting fixes, tweaks,
improvements and new features. Use a **different AI to check the work** than the one that
did it: plan and build with Claude Code → check with Codex (and the other way round).

### 5. 🔍 Code review loop
All code goes through review with **Codex**: any problems it finds get fixed
automatically, then Codex reviews again — **repeat until no problems are found.**
If Codex isn't available (see rule 9), use the best other reviewer available (for example
Claude's own `/code-review`), note that in the handoff, and run a full Codex review once
it is back.

### 6. 🚫 No PR stacking — one working branch, one PR later
Don't open lots of pull requests (they can trip over each other when merged). Commit
everything to **one working branch that will later go into `alpha`** through a single PR,
which is opened later (only when the owner asks). → See open question Q1 in the handoff
about which branch name to use.

### 7. 🤖 Work on your own
Do everything without stopping, unless an **explicit decision or approval from the owner**
is needed. If so: say clearly and simply what is needed and why. **Ask all questions
up front**, not one at a time as they come up. While waiting, carry on with everything
else in the queue, and continue the whole queue once answered.

### 8. 📊 Progress updates
Give frequent updates showing the queue of tasks **as a table**, with the status of each.

### 9. 🔁 AI-tool fallback (also applies to every project on the owner's devices)
If one AI service (Claude Code, Codex, or any other) or its helper agents becomes
unavailable or runs out of credit, **hand the work to another suitable AI tool** when
that can be done without losing context or progress. Switch back to the main tool as
soon as it's available again, and then run a **full review** of what was done meanwhile.
This is safe because every piece of work is cross-checked by a different AI anyway — but
it makes an up-to-the-minute handoff essential (rule 2). No specific tools are required;
use whatever is suitable and available.

### 10. ⚙️ Be efficient
Re-order or bundle the tasks in section B however makes the work most efficient.

### 11. 🐕 Watchdog — never lose track of a job that is still running *(added 2026-09-24)*
Whenever work is handed to something that runs **in the background** — a helper agent,
the automatic checks (CI) on a pull request, a merge, an automatic backport, or a long
command — **set up a "watchdog" that waits for it to finish**, and **do not move on to the
next step in the queue until its result has been confirmed** (passed / failed / done).
Jobs that don't depend on each other may run side by side, but each one gets its own watchdog.

How to do it:
- **Log it when it starts:** add a row to the **"⏱️ Jobs in flight"** table in `HANDOFF.md`
  (what it is, when it started, where to check the result).
- **Watch it until it finishes:**
  - *Helper agents and long commands* → run them in the background so the session is woken
    automatically when they end.
  - *Anything that does not wake the session by itself* (e.g. GitHub checks on a pull request)
    → run a background timer or check-loop inside the session that re-checks until it is
    finished (short gaps for quick jobs; about 10–15 minutes for CI).
  - *Pull requests* → subscribe to the PR's activity **and** re-check its checks and whether it
    can be merged yourself, because "all checks passed" is not always announced.
  - Use timers that run inside the session. Don't rely on scheduled "remind me later"
    messages unless the owner has approved them.
- **When it finishes:** read the **actual** result (never assume it worked), act on it (fix a
  failure, then watch the re-run the same way), and only then remove its row and continue.
- **After a pause or restart:** background jobs may have died or finished unnoticed. The
  first thing any new session does is the **watchdog check**: fetch the latest from GitHub,
  compare branches and open PRs with the handoff, and confirm (or re-run) every row in the
  Jobs-in-flight table. The **start-of-session hook** does this reminder automatically
  (`.claude/hooks/session-watchdog.sh`, registered in `.claude/settings.json`); Codex and
  other tools run the same script by hand: `bash .claude/hooks/session-watchdog.sh`.
- **Record surprises:** anything found that the handoff didn't mention (e.g. merges done
  outside the session) is written into the handoff straight away.

---

## B. Standing tasks

### At the start of every session (and after any pause, restart or context compaction)
0. **Watchdog check** (rule 11): read what the start-of-session hook printed (or run
   `bash .claude/hooks/session-watchdog.sh`), fetch the latest from GitHub, and confirm every
   row in the "⏱️ Jobs in flight" table in `HANDOFF.md` **before** starting anything new.

### After each piece of work
1. **Confirm it really finished** (rule 11): every background job for this task has ended
   and its result has been checked; its row is cleared from the Jobs-in-flight table.
2. **Commit and push** to the working branch (rule 6).
3. **Update the matching GitHub issue(s)** — one update per task.
4. **Update Claude's memory/context** in `.claude/` (`MEMORY.md`, this file
   if rules change, `PROJECT_STATUS.md` when status changes).
5. **Update Codex/OpenAI's memory/context** in `.OpenAI/` (`MEMORY.md`, `CONTEXT.md`).
6. **Update the handoff** (`HANDOFF.md`, top of the project) so we can pick up exactly where we left off.

### Thorough documentation update (do regularly, and whenever features change)
- Update **all `.md` documentation** in the repo (README, PROJECT_PROGRESS, CHANGELOG,
  SECURITY, `_docs/`, etc.).
- Update the **in-app help pages and guides**.
- Update everything in `.claude/` (memory, context, status).
- Update the **OpenAPI/Swagger description of the API**
  (`web/public_html/api/docs/openapi.yaml`).
- Make sure the **browsable Swagger UI** works on plain shared hosting (no Docker, no
  command line): it exists at `web/public_html/api/docs/swagger/`, but its
  "if the CDN is down" backup copy (`/assets/lib/swagger-ui/`) is **missing** — see handoff queue.

---

## C. Earlier rules still in force (not replaced)

- The project brief in `.claude/CLAUDE.md` (tech stack, coding style, security, hosting on
  Dreamhost shared hosting, no Composer in production, `_`-prefixed private folders, etc.)
  still applies in full.
- **All GitHub Actions workflows use `runs-on: ubuntu-latest`**, including any future SFTP
  deploy workflow (PR #128).
- **Four-tier branches:** `alpha ▸ beta ▸ release-candidate ▸ main`. Security fixes merged
  to `main` are automatically copied down to the other tiers (`backport.yml`).
- **Never** put live payment keys in, or run data-deleting database migrations, without
  the owner's explicit say-so.
- Never commit secrets, database credentials or `.git/config` changes.
- Keep dormant/risky features switched **off** by default (SAML, age-gate, retention purge,
  entitlement enforcement) until the owner turns them on.
- Don't put AI model names in commit messages, code comments or PR text.
