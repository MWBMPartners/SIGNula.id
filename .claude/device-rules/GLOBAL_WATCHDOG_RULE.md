# Device-wide rule: Watchdog (copy onto every machine you use)

> Added 2026-09-24 (owner request, issue #129). This cloud session runs on a temporary
> computer, so a device-wide setting written here would vanish. Copy it onto your Mac yourself.
> Inside SIGNula.id this rule is already active: standing rule 11 plus the automatic
> start-of-session check in `.claude/hooks/session-watchdog.sh`.

## Step 1 — the rule (copy the block below into **both** files)
- `~/.claude/CLAUDE.md`  (Claude Code, all projects on this device)
- `~/.codex/AGENTS.md`   (Codex, all projects on this device)

---

## Watchdog — never lose track of a running job (all projects)
Whenever work runs in the background (a helper agent, the automatic checks on a pull
request, a merge, a backport, a long command), set up a watchdog that waits for it to
finish, and do not move on to the next step in the queue until its real result has been
confirmed. Jobs that don't depend on each other may run side by side, each with its own
watchdog.
- When a job starts, note it in the project's handoff document (a "Jobs in flight" list).
- Watch it until it ends. Run it in the background so the session is woken when it
  finishes. If nothing will wake the session (e.g. checks on a pull request), use a timer or
  check-loop that re-checks until it is done.
- When it ends, read the actual result, fix anything that failed (and watch the re-run),
  then remove it from the list.
- At the start of every session, and after any pause or restart, first confirm everything
  in that list and compare the project's branches and open pull requests with the handoff,
  before starting anything new. Write down any surprises.

---

## Step 2 (optional) — an automatic reminder at the start of every session, in every project

Claude Code only. Add this to `~/.claude/settings.json` (merge it with anything already
there; don't replace the file):

```json
{
  "hooks": {
    "SessionStart": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "echo '🐕 WATCHDOG: before starting new work, confirm that every background job from earlier (helper agents, PR checks, merges, long commands) really finished (check the project handoff Jobs-in-flight list), and put a watchdog on every new one.'"
          }
        ]
      }
    ]
  }
}
```

Afterwards, open `/hooks` in Claude Code once (or restart it) so it picks up the change.
