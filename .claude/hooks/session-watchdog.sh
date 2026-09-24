#!/usr/bin/env bash
# =============================================================================
# 🐕 SIGNula.id — Session watchdog (Claude Code "SessionStart" hook)
# -----------------------------------------------------------------------------
# WHAT: Runs automatically whenever a Claude Code session starts, resumes, is
#       cleared or is compacted (registered in .claude/settings.json). Whatever
#       this script prints is added to the AI's context, so EVERY session begins
#       by checking for work that was started earlier but never confirmed as
#       finished — before it moves on to the next task in the queue.
#
# WHY:  Standing rule 11 ("Watchdog") in .claude/STANDING_RULES.md. When a
#       session pauses or restarts, background jobs (helper agents, GitHub
#       checks on a pull request, merges, backports) can finish or fail
#       silently. This is the safety net that makes sure none are missed.
#
# ANY AI TOOL can run it by hand too (e.g. Codex):
#       bash .claude/hooks/session-watchdog.sh
#
# DESIGN:
#   • 📴 Offline + read-only — no `git fetch`, no network, no file changes, so
#     it is fast and can never slow down or break a session start.
#   • 🍎🐧 Portable — works with macOS's bash 3.2 and Linux bash; avoids
#     GNU-only flags and does not need `jq`.
#   • 🛟 Never fails — always exits 0; every command's errors are swallowed.
#
# Docs: https://code.claude.com/docs/en/hooks#sessionstart
# =============================================================================

# -----------------------------------------------------------------------------
# 📍 Locate the project folder.
#    Claude Code sets CLAUDE_PROJECT_DIR for hooks; when run by hand, fall back
#    to "two folders up from this script" (.claude/hooks/ → project root).
# -----------------------------------------------------------------------------
PROJECT_DIR="${CLAUDE_PROJECT_DIR:-}"
if [ -z "$PROJECT_DIR" ]; then
    PROJECT_DIR="$(cd "$(dirname "$0")/../.." 2>/dev/null && pwd)"
fi
if [ -z "$PROJECT_DIR" ] || [ ! -d "$PROJECT_DIR" ]; then
    exit 0
fi

# -----------------------------------------------------------------------------
# 📨 Which session event fired us? (startup / resume / clear / compact)
#    Claude Code sends a small JSON object on stdin; pull out "source" with sed
#    so we don't depend on `jq` being installed. Skip reading when stdin is a
#    terminal (i.e. someone ran the script by hand) so it never waits for input.
# -----------------------------------------------------------------------------
EVENT="manual run"
if [ ! -t 0 ]; then
    HOOK_INPUT="$(cat 2>/dev/null)"
    PARSED="$(printf '%s' "$HOOK_INPUT" | sed -n 's/.*"source"[[:space:]]*:[[:space:]]*"\([A-Za-z_-]*\)".*/\1/p' 2>/dev/null | head -n 1)"
    if [ -n "$PARSED" ]; then
        EVENT="$PARSED"
    fi
fi

# -----------------------------------------------------------------------------
# 🧰 Small helper: run git inside the project, silencing any errors.
# -----------------------------------------------------------------------------
g() {
    git -C "$PROJECT_DIR" "$@" 2>/dev/null
}

# -----------------------------------------------------------------------------
# 📣 1) The standing reminder (short — the full rule lives in STANDING_RULES.md)
# -----------------------------------------------------------------------------
echo "🐕 WATCHDOG CHECK — standing rule 11 (.claude/STANDING_RULES.md) · session event: ${EVENT}"
echo "Before starting anything new, confirm that everything started earlier really finished:"
echo "  1. Fetch the latest from GitHub (git fetch --prune origin) and compare main / alpha / beta /"
echo "     release-candidate and any open PRs with what HANDOFF.md says. Record any surprises."
echo "  2. Go through the \"Jobs in flight\" table in HANDOFF.md (shown below): check each job's REAL"
echo "     result (PR checks, helper agents, merges, backports), act on it, then remove the row."
echo "  3. From now on, every background job gets a watchdog: log it in that table when it starts,"
echo "     wait for it to finish (background run / timer loop / PR subscription + re-checks), and"
echo "     do NOT move to the next queue step until its result is confirmed."

# -----------------------------------------------------------------------------
# 🔎 2) Local loose ends (offline snapshot — may be out of date until a fetch)
# -----------------------------------------------------------------------------
if g rev-parse --is-inside-work-tree >/dev/null; then
    echo ""
    echo "Local state (offline snapshot — may be stale until you fetch):"

    # 🌿 Current branch and how it compares with its upstream (tracking) branch.
    BRANCH="$(g rev-parse --abbrev-ref HEAD)"
    UPSTREAM="$(g rev-parse --abbrev-ref --symbolic-full-name '@{u}')"
    if [ -n "$UPSTREAM" ]; then
        COUNTS="$(g rev-list --left-right --count "HEAD...@{u}")"
        AHEAD="$(printf '%s' "$COUNTS" | awk '{print $1}')"
        BEHIND="$(printf '%s' "$COUNTS" | awk '{print $2}')"
        echo "  • Branch: ${BRANCH} (tracks ${UPSTREAM}) — ${AHEAD:-?} commit(s) not pushed, ${BEHIND:-?} behind"
    else
        echo "  • Branch: ${BRANCH} (no upstream set — nothing pushed from here yet?)"
    fi

    # ✏️ Uncommitted changes (modified + untracked files).
    DIRTY="$(g status --porcelain | wc -l | tr -d ' ')"
    if [ "${DIRTY:-0}" != "0" ]; then
        echo "  • ⚠️ Uncommitted changes: ${DIRTY} file(s) — commit + push them, or explain why not"
    else
        echo "  • Uncommitted changes: none"
    fi

    # 📦 Stashed work that may have been forgotten.
    STASHES="$(g stash list | wc -l | tr -d ' ')"
    if [ "${STASHES:-0}" != "0" ]; then
        echo "  • ⚠️ Stashed changes: ${STASHES} — check whether any are still needed"
    fi

    # 🌳 Extra git worktrees (helper agents often work in these). The first
    #    line of `git worktree list` is the main checkout, so skip it.
    WORKTREES="$(g worktree list | sed -n '2,$p')"
    if [ -n "$WORKTREES" ]; then
        echo "  • ⚠️ Extra worktrees (possibly unfinished helper-agent work — confirm result, then remove):"
        printf '%s\n' "$WORKTREES" | sed 's/^/      /'
    fi
    if [ -d "$PROJECT_DIR/.claude/worktrees" ]; then
        LEFTOVER="$(ls -1 "$PROJECT_DIR/.claude/worktrees" 2>/dev/null | wc -l | tr -d ' ')"
        if [ "${LEFTOVER:-0}" != "0" ]; then
            echo "  • ⚠️ Folders left in .claude/worktrees/: ${LEFTOVER} (check, then \`git worktree prune\`)"
        fi
    fi

    # 🏷️ Release tiers as last fetched (compared with origin/main).
    TIER_LINE=""
    for TIER in main alpha beta release-candidate; do
        SHA="$(g rev-parse --short "origin/${TIER}")"
        if [ -n "$SHA" ]; then
            TIER_LINE="${TIER_LINE}${TIER}@${SHA}  "
        fi
    done
    if [ -n "$TIER_LINE" ]; then
        echo "  • Tiers (last fetch): ${TIER_LINE}"
    fi
fi

# -----------------------------------------------------------------------------
# ⏱️ 3) The "Jobs in flight" table from HANDOFF.md (top of the project).
#    Prints from the "Jobs in flight" heading up to the next "## " heading or
#    "---" divider (skipping the "> ..." explanation note), capped at 25
#    lines so the context stays small.
# -----------------------------------------------------------------------------
HANDOFF="$PROJECT_DIR/HANDOFF.md"
echo ""
if [ -f "$HANDOFF" ]; then
    JOBS="$(awk '
        /^## .*Jobs in flight/ { grab = 1; next }
        grab && (/^## / || /^---/) { exit }
        grab && /^>/ { next }
        grab { print }
    ' "$HANDOFF" 2>/dev/null | sed '/^[[:space:]]*$/d' | head -n 25)"
    if [ -n "$JOBS" ]; then
        echo "Jobs in flight (from HANDOFF.md):"
        printf '%s\n' "$JOBS" | sed 's/^/  /'
    else
        echo "Jobs in flight: no \"Jobs in flight\" section found in HANDOFF.md — add one (see rule 11)."
    fi
else
    echo "Jobs in flight: HANDOFF.md not found at the top of the project."
fi

# ✅ Never block or fail a session start.
exit 0
