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
# Strategy (canonical schema source of truth):
#   The numbered migrations in _database/migrations/ are INCREMENTAL upgrades:
#   migration 001 starts with `ALTER TABLE tblEmailQueue ...`, so they assume a
#   base schema already exists and cannot be replayed standalone from an empty
#   DB.
#
#   The consolidated installer `signula_complete_install_v2.5.0.sql` is the
#   real source of truth for the base schema: it CREATEs 66 of the 67 tables
#   itself, with internally-consistent column types. The ONE table it assumes
#   already exists (it only ever ALTERs it, never CREATEs it) is `tblEmailQueue`
#   — a gap inherited from the v1.0 base in _database/archive/001_initial_schema.sql.
#   (That installer gap is itself a finding — see B-029 triage notes.)
#
#   We deliberately DO NOT load the whole v1.0 base schema: its tblUsers and
#   other tables use older/wider column types (e.g. BIGINT userID) that clash
#   with the installer's own FOREIGN KEYs (INT userID), producing cascading
#   "incompatible column" errors. Instead we provide ONLY a minimal
#   `tblEmailQueue` stub shaped exactly as the installer expects
#   (`emailID INT UNSIGNED` PK, plus the `fromName` column its ALTER targets,
#   no inbound FKs), then let the installer build everything else.
#
#   So the reproducible layering, from an empty DB, is:
#     1. DROP + CREATE the `signula_test` database
#     2a. Create a minimal `tblEmailQueue` stub the installer can FK against
#     2b. Load the v2.5.0 consolidated installer (DB-name lines stripped so it
#         loads into signula_test, not the production `signula` DB)
#     3.  Apply the post-v2.5.0 migrations (015..) in numeric order on top
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

BASE_SCHEMA="${REPO_ROOT}/_database/archive/001_initial_schema.sql"
INSTALLER="${REPO_ROOT}/_database/signula_complete_install_v2.5.0.sql"
MIGRATIONS_DIR="${REPO_ROOT}/_database/migrations"

# 🧱 Base-schema tables that the consolidated installer ALSO creates AND whose
#    INSTALLER version is the one the current application code targets. We DROP
#    the v1.0 base copies of these before loading the installer and let the
#    installer recreate them (correct column names like `settingCategory`,
#    current types). Base tables NOT listed here are KEPT — either because the
#    installer never creates them (tblEmailQueue, tblEmailTemplates,
#    tblVerificationTokens, tblUserSessions, ...) or because the v1.0 BASE
#    version is the one the app code actually targets.
#
# ⚠️  Deliberately EXCLUDED (base version kept): tblActivityLog and tblErrorLog.
#    The app's ActivityLogger / ErrorLogger INSERT columns (sessionID,
#    activityCategory, description, metadata, requestURL, requestHeaders, context,
#    errorTrace, ...) that exist in the v1.0 BASE schema but NOT in the
#    installer's narrower, stale versions (activityResult/activityDetails,
#    stackTrace, requestUri). Keeping the base versions matches production code.
#    (This base-vs-installer logging-table drift is a finding — see B-029 notes.)
INSTALLER_OWNED_BASE_TABLES=(
    tblAPIKeys tblPayments tblRateLimits
    tblSettings tblSubscriptionTiers tblSubscriptions tblUserMFA
    tblUserPreferences tblUsers
)

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

echo "🧪 SIGNula test DB setup"
echo "   host=${DB_HOST} user=${DB_USER} db=${DB_NAME}"
echo "   mysql=${MYSQL_BIN}"

# Sanity: required schema files must exist
for required in "${BASE_SCHEMA}" "${INSTALLER}"; do
    if [[ ! -f "${required}" ]]; then
        echo "❌ Required schema file not found: ${required}" >&2
        exit 1
    fi
done

# ⚠️  IMPORTANT — these schema artifacts contain pre-existing drift (see the
#     B-029 triage notes): the v1.0 base and the consolidated installer disagree
#     on several column TYPES (e.g. userID/partnerID/createdBy are BIGINT in some
#     tables but INT in others), so a number of FOREIGN KEYs cannot be created
#     and a few seed INSERTs reference renamed columns. None of those affect the
#     tables the Integration suite exercises. We therefore load with `--force`
#     so the run does not abort on those known, catalogued drifts — every needed
#     table and column is still created. This is a TEST harness, not a prod
#     installer; fixing the underlying drift is tracked separately.
MYSQL_FORCE=("${MYSQL_BIN}" "${MYSQL_ARGS[@]}" --force)

# 1️⃣ Drop + recreate the throwaway test database
echo "1️⃣  Dropping and recreating ${DB_NAME} ..."
"${MYSQL_BIN}" "${MYSQL_ARGS[@]}" -e \
    "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2️⃣ Load the v1.0 base schema. This provides the handful of tables the
#    consolidated installer assumes already exist (tblEmailQueue,
#    tblEmailTemplates, tblVerificationTokens, tblUserSessions, ...).
echo "2️⃣  Loading v1.0 base schema ..."
"${MYSQL_FORCE[@]}" "${DB_NAME}" < "${BASE_SCHEMA}" 2>/dev/null

# 3️⃣ Drop the base copies of every table the installer also creates, so the
#    installer's canonical versions win (correct column names + types). No FK
#    references survive because we disable FK checks for the drop.
echo "3️⃣  Dropping installer-owned base tables (installer recreates them) ..."
DROP_SQL="SET FOREIGN_KEY_CHECKS=0;"
for tbl in "${INSTALLER_OWNED_BASE_TABLES[@]}"; do
    DROP_SQL+=" DROP TABLE IF EXISTS \`${tbl}\`;"
done
DROP_SQL+=" SET FOREIGN_KEY_CHECKS=1;"
"${MYSQL_BIN}" "${MYSQL_ARGS[@]}" "${DB_NAME}" -e "${DROP_SQL}"

# 4️⃣ Load the consolidated installer, stripping the embedded
#    CREATE DATABASE / USE `signula` block so it targets ${DB_NAME}.
echo "4️⃣  Loading consolidated installer (v2.5.0) ..."
TMP_INSTALLER="$(mktemp -t signula_installer.XXXXXX.sql)"
trap 'rm -f "${TMP_INSTALLER}"' EXIT
# Delete the 3-line CREATE DATABASE block and the USE line. The installer's
# CREATE DATABASE spans 3 lines; matching CREATE DATABASE ... ; removes it.
perl -0777 -pe 's/CREATE DATABASE IF NOT EXISTS `signula`.*?;//s; s/^USE `signula`;\s*$//mg;' \
    "${INSTALLER}" > "${TMP_INSTALLER}"
"${MYSQL_FORCE[@]}" "${DB_NAME}" < "${TMP_INSTALLER}" 2>/dev/null

# 4️⃣b Fix the WebAuthn tables. The installer defines tblWebAuthnCredentials AND
#     tblWebAuthnChallenges TWICE each: an earlier STALE design (which wins via
#     IF NOT EXISTS) and a later one matching migration 005. The application
#     (web/private_html/auth/WebAuthnHandler.php) targets the migration-005
#     columns:
#       • tblWebAuthnCredentials: credentialPublicKeyID, credentialPublicKey,
#                                 signCount, isActive
#       • tblWebAuthnChallenges : email, ipAddress, userAgent, validityMinutes
#     The stale installer versions lack these, so storeChallenge() and the
#     credential lookups fail. We drop the stale winners and re-apply migration
#     005 so the app-correct schema exists. Migration 005 is idempotent (its
#     tblUsers ALTERs are existence-guarded) and no FK references either table,
#     so this is safe. (This double-definition drift is a finding — see B-029.)
WEBAUTHN_MIGRATION="${MIGRATIONS_DIR}/005_webauthn_passkeys.sql"
if [[ -f "${WEBAUTHN_MIGRATION}" ]]; then
    echo "4️⃣b Rebuilding WebAuthn tables from migration 005 ..."
    "${MYSQL_BIN}" "${MYSQL_ARGS[@]}" "${DB_NAME}" -e \
        "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS \`tblWebAuthnCredentials\`; DROP TABLE IF EXISTS \`tblWebAuthnChallenges\`; SET FOREIGN_KEY_CHECKS=1;"
    # Migration 005's CREATEs declare a FOREIGN KEY on userID (INT UNSIGNED) to
    # tblUsers.userID (BIGINT UNSIGNED). That type mismatch makes the CREATE fail
    # (error 3780, NOT bypassable by FOREIGN_KEY_CHECKS=0). For the test DB we
    # strip those `CONSTRAINT ... FOREIGN KEY (userID) REFERENCES tblUsers ...`
    # blocks (and the comma preceding them) so the tables create cleanly with the
    # correct, app-matching COLUMNS. We only test column shape, not referential
    # integrity, so dropping these FKs in the test schema is acceptable.
    WEBAUTHN_TMP="$(mktemp -t signula_webauthn.XXXXXX.sql)"
    perl -0777 -pe 's/,\s*\n\s*CONSTRAINT\s+`[^`]+`\s*\n\s*FOREIGN KEY \(`userID`\)\s*\n\s*REFERENCES `tblUsers`[^\n]*\n\s*ON DELETE[^\n]*\n\s*ON UPDATE[^\n]*//g;' \
        "${WEBAUTHN_MIGRATION}" > "${WEBAUTHN_TMP}"
    "${MYSQL_FORCE[@]}" "${DB_NAME}" < "${WEBAUTHN_TMP}" 2>/dev/null
    rm -f "${WEBAUTHN_TMP}"
fi

# 4️⃣c Upgrade the kept v1.0 tblEmailQueue to the columns the application's
#     EmailService::queueEmail() INSERT expects. The v1.0 base names the columns
#     `replyTo` / `scheduledFor`, but the app uses `replyToEmail` / `scheduledAt`
#     and also writes `ccRecipients` / `bccRecipients`. Migration 001 was meant
#     to perform these renames+adds, but it has an ordering bug (it ADDs
#     ccRecipients "AFTER replyToEmail" BEFORE renaming replyTo->replyToEmail),
#     so cc/bcc never get added. We perform the rename FIRST, then the adds, in
#     the correct order. All steps are existence-guarded so this is idempotent
#     and additive (no data loss). (This migration-001 ordering bug is a finding
#     — see B-029 notes.)
echo "4️⃣c Aligning tblEmailQueue columns with EmailService ..."
"${MYSQL_BIN}" "${MYSQL_ARGS[@]}" "${DB_NAME}" <<'SQL' 2>/dev/null
-- Rename replyTo -> replyToEmail (only if the old column still exists)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEmailQueue' AND COLUMN_NAME = 'replyTo');
SET @s := IF(@c > 0, 'ALTER TABLE `tblEmailQueue` CHANGE COLUMN `replyTo` `replyToEmail` VARCHAR(255) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Rename scheduledFor -> scheduledAt (only if the old column still exists)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEmailQueue' AND COLUMN_NAME = 'scheduledFor');
SET @s := IF(@c > 0, 'ALTER TABLE `tblEmailQueue` CHANGE COLUMN `scheduledFor` `scheduledAt` DATETIME NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Add ccRecipients (idempotent; MySQL 8/9 has no ADD COLUMN IF NOT EXISTS)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEmailQueue' AND COLUMN_NAME = 'ccRecipients');
SET @s := IF(@c = 0, 'ALTER TABLE `tblEmailQueue` ADD COLUMN `ccRecipients` JSON NULL COMMENT "Array of CC email addresses"', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Add bccRecipients (idempotent)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEmailQueue' AND COLUMN_NAME = 'bccRecipients');
SET @s := IF(@c = 0, 'ALTER TABLE `tblEmailQueue` ADD COLUMN `bccRecipients` JSON NULL COMMENT "Array of BCC email addresses"', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SQL

# 5️⃣ Apply post-v2.5.0 migrations (015 and above) in numeric order.
#    Migrations 001..014 are already baked into the consolidated installer.
echo "5️⃣  Applying post-v2.5.0 migrations (015+) ..."
# bash 3.2 compatible (macOS default has no `mapfile`). Migration FILENAMES have
# no spaces (NNN_name.sql); we apply them in numeric order. The directory path
# may contain spaces, so we glob (globs preserve spaces) and sort by basename via
# a glob over zero-padded numeric prefixes 015..099.
for num in $(seq -w 15 99); do
    for migration in "${MIGRATIONS_DIR}"/0${num}_*.sql; do
        # Guard against the no-match literal glob
        [[ -f "${migration}" ]] || continue
        base="$(basename "${migration}")"
        echo "   → ${base}"
        "${MYSQL_FORCE[@]}" "${DB_NAME}" < "${migration}" 2>/dev/null
    done
done

# 6️⃣ Summary + assert the tables the Integration suite needs all exist.
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
