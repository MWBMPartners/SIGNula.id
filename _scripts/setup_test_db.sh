#!/usr/bin/env bash
# ============================================================================
# SIGNula — Idempotent test-database setup
# ============================================================================
# Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
# ============================================================================
#
# 🧪 Stands up a reproducible `signula_test` database for the Integration test
# suite (and CI). Safe to run repeatedly — it DROPs and recreates the throwaway
# test database every time.
#
# Canonical bootstrap (B-033, cycle 9):
#   As of the B-033 fresh-install fixes, this is now a CLEAN canonical bootstrap
#   with NO `--force` and NO base-schema patching. The two source artefacts both
#   apply with ZERO SQL errors on MySQL 8/9:
#
#     1. DROP + CREATE the throwaway `signula_test` database.
#     2. Load the consolidated installer
#        `_database/signula_complete_install_v2.5.0.sql` (DB-name lines stripped
#        so it targets signula_test). It CREATEs every core table — including
#        tblEmailQueue, tblEmailTemplates, tblUserSessions and
#        tblVerificationTokens (restored during B-033) — with internally
#        consistent BIGINT identity columns and valid MySQL 8/9 syntax.
#     3. Apply the post-installer migrations (014..) in numeric order. Migrations
#        001..013 are baked into the installer; 014_security_enhancements.sql and
#        015+ add the newer feature tables (IP reputation / blocked IPs / security
#        alerts, usage billing, notifications, i18n, webhooks, organizations, etc.)
#        and apply cleanly on top.
#
#   ⚠️ B-041 (cycle 11): the loop below starts at 014, NOT 015. Only 001..013 are
#      actually baked into the consolidated installer — 014's five security tables
#      (tblIPReputationCache, tblBlockedIPs, tblSecurityAlerts,
#      tblSessionFingerprints, tblCircuitBreaker) are NOT baked, so starting the
#      loop at 015 previously left them out of the test DB. This mirrors the
#      install-wizard path, which records 001..013 as completed (skipping them) and
#      applies 014+ on top.
#
#   The previous incarnation of this script loaded the v1.0 archive base schema,
#   dropped installer-owned tables, hot-patched the WebAuthn + email tables and
#   ran everything with `--force` to paper over ~70 catalogued SQL errors. Those
#   errors are now fixed at source (see B-033), so all of that scaffolding is
#   gone. We deliberately run WITHOUT `--force`: any SQL error now fails the
#   setup loudly, which is the point.
#
# Environment overrides (defaults shown):
#   DB_HOST=localhost  DB_USER=root  DB_PASS=''  DB_NAME=signula_test
#
# Usage:
#   _scripts/setup_test_db.sh
#   DB_USER=ci DB_PASS=secret _scripts/setup_test_db.sh
# ============================================================================

set -euo pipefail

# 📍 Resolve repo root from this script's location (works from any CWD)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

DB_HOST="${DB_HOST:-localhost}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-signula_test}"

INSTALLER="${REPO_ROOT}/_database/signula_complete_install_v2.5.0.sql"
MIGRATIONS_DIR="${REPO_ROOT}/_database/migrations"

# 🔎 Locate the mysql client (prefer Homebrew on macOS, fall back to PATH)
if [[ -x /opt/homebrew/bin/mysql ]]; then
    MYSQL_BIN="/opt/homebrew/bin/mysql"
else
    MYSQL_BIN="$(command -v mysql)"
fi

# 🔐 Assemble connection args. Only pass -p when a password is set, so an
#    empty-password root login (the local dev default) works cleanly.
MYSQL_ARGS=(-h "${DB_HOST}" -u "${DB_USER}")
if [[ -n "${DB_PASS}" ]]; then
    MYSQL_ARGS+=("-p${DB_PASS}")
fi

echo "🧪 SIGNula test DB setup (clean canonical bootstrap)"
echo "   host=${DB_HOST} user=${DB_USER} db=${DB_NAME}"
echo "   mysql=${MYSQL_BIN}"

# Sanity: required schema file must exist
if [[ ! -f "${INSTALLER}" ]]; then
    echo "❌ Required installer not found: ${INSTALLER}" >&2
    exit 1
fi

# NOTE: no `--force`. A clean install must not emit SQL errors — if it does we
# want the setup to fail so the regression is caught immediately.
MYSQL_STRICT=("${MYSQL_BIN}" "${MYSQL_ARGS[@]}")

# 1️⃣ Drop + recreate the throwaway test database
echo "1️⃣  Dropping and recreating ${DB_NAME} ..."
"${MYSQL_BIN}" "${MYSQL_ARGS[@]}" -e \
    "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2️⃣ Load the consolidated installer, stripping the embedded
#    CREATE DATABASE / USE `signula` block so it targets ${DB_NAME}.
echo "2️⃣  Loading consolidated installer (v2.5.0) ..."
TMP_INSTALLER="$(mktemp -t signula_installer.XXXXXX.sql)"
trap 'rm -f "${TMP_INSTALLER}"' EXIT
perl -0777 -pe 's/CREATE DATABASE IF NOT EXISTS `signula`.*?;//s; s/^USE `signula`;\s*$//mg;' \
    "${INSTALLER}" > "${TMP_INSTALLER}"
"${MYSQL_STRICT[@]}" "${DB_NAME}" < "${TMP_INSTALLER}"

# 3️⃣ Apply post-installer migrations (014 and above) in numeric order.
#    Migrations 001..013 are already baked into the consolidated installer;
#    014_security_enhancements.sql onwards are applied on top (see header note).
#
#    ⚠️ Some migrations begin with `USE `signula`;` (e.g. 030_jwt_authentication).
#       If a REAL `signula` database co-exists on the dev machine, that directive
#       silently redirects the migration's DDL into `signula` instead of the
#       throwaway `${DB_NAME}` — the tables get created in the WRONG database and
#       the test DB is left missing them. We therefore strip the DB-selection
#       line from each migration before piping it in (exactly as step 2️⃣ already
#       does for the consolidated installer), so a migration's objects always land
#       in `${DB_NAME}` regardless of what other databases exist locally.
echo "3️⃣  Applying post-installer migrations (014+) ..."
for num in $(seq -w 14 99); do
    for migration in "${MIGRATIONS_DIR}"/0${num}_*.sql; do
        # Guard against the no-match literal glob
        [[ -f "${migration}" ]] || continue
        base="$(basename "${migration}")"
        echo "   → ${base}"
        # 🎯 Strip any `USE `signula`;` / `USE signula;` line so the migration
        #    targets ${DB_NAME} (the connection is already scoped to it).
        TMP_MIGRATION="$(mktemp -t signula_migration.XXXXXX.sql)"
        perl -pe 's/^\s*USE\s+`?signula`?\s*;\s*$//i;' "${migration}" > "${TMP_MIGRATION}"
        "${MYSQL_STRICT[@]}" "${DB_NAME}" < "${TMP_MIGRATION}"
        rm -f "${TMP_MIGRATION}"
    done
done

# 4️⃣ Summary + assert the tables the Integration suite needs all exist.
TABLE_COUNT="$("${MYSQL_BIN}" "${MYSQL_ARGS[@]}" "${DB_NAME}" -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_type='BASE TABLE';")"

REQUIRED_TABLES=(
    tblUsers tblActivityLog tblUserMFA tblErrorLog tblEmailQueue
    tblRateLimitConfig tblRateLimits tblWebAuthnCredentials tblWebAuthnChallenges
    tblSettings tblEmailTemplates tblVerificationTokens tblUserSessions tblAPIKeys
)
MISSING=()
for tbl in "${REQUIRED_TABLES[@]}"; do
    present="$("${MYSQL_BIN}" "${MYSQL_ARGS[@]}" "${DB_NAME}" -N -e \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='${tbl}';")"
    if [[ "${present}" != "1" ]]; then
        MISSING+=("${tbl}")
    fi
done

echo "✅ ${DB_NAME} now has ${TABLE_COUNT} tables."
if (( ${#MISSING[@]} > 0 )); then
    echo "❌ Missing Integration-suite tables: ${MISSING[*]}" >&2
    exit 1
fi
echo "✅ All ${#REQUIRED_TABLES[@]} Integration-suite tables present."
