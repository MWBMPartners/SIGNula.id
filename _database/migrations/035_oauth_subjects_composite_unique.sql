-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 🕵️ SIGNula - OAuth2/OIDC Provider: tblOAuthSubjects composite UNIQUE (B-062)
-- ============================================================================
--
-- Migration: 035_oauth_subjects_composite_unique.sql
-- Feature:   B-062 (LOW) — migration 033 created tblOAuthSubjects with
--            `UNIQUE KEY uk_subject (subjectHash)` ALONE (not composite with
--            clientID). For a PAIRWISE-subject client (the DEFAULT —
--            OAuthClientManager::computeSubject() sector-scopes the hash per
--            client: sha256(sector|userID|salt)) this is harmless: two
--            different clients for the SAME user always compute DIFFERENT
--            subjectHash values, so they never collide on the single-column
--            key. But for a PUBLIC-subject client (subjectType='public'),
--            computeSubject() returns the RAW userID string — UNSCOPED by
--            client — so TWO DIFFERENT public clients for the SAME user
--            compute the EXACT SAME subjectHash.
--
--            TokenService::resolveAndRecordSubject()'s
--            `INSERT ... ON DUPLICATE KEY UPDATE userID = VALUES(userID),
--            clientID = VALUES(clientID)` then triggers on that shared
--            subjectHash and OVERWRITES the FIRST client's row with the
--            SECOND client's clientID. Afterwards,
--            OAuthUserInfoService::handleUserInfoRequest()'s lookup
--            `WHERE subjectHash = ? AND clientID = ?` (already correctly
--            keyed on BOTH columns — see migration 033's own header) can no
--            longer find a row for the FIRST client, so its
--            `/oauth/userinfo` calls start 401ing even though its access
--            token is cryptographically perfectly valid.
--
--            (Public-subject clients are NON-DEFAULT —
--            OAuthClientManager::registerClient()'s $opts['subjectType']
--            defaults to 'pairwise' — and unused in production today, hence
--            LOW severity. Fixed anyway so public clients work correctly
--            the moment more than one is registered for the same user.)
-- Version:   2.9.0-beta
-- Date:      2026-07-10
--
-- 📝 What this migration does:
--
--   Replaces the single-column `UNIQUE KEY uk_subject (subjectHash)` on
--   tblOAuthSubjects with a COMPOSITE `UNIQUE KEY uk_subject_client
--   (clientID, subjectHash)`. The composite key is STRICTLY MORE PERMISSIVE
--   than the one it replaces — a (clientID, subjectHash) PAIR is unique
--   whenever a subjectHash-ALONE value was unique (never the reverse), so
--   this ALTER can never fail against pre-existing data: every row that was
--   legal under the old key remains legal under the new one.
--
--   After this migration, resolveAndRecordSubject()'s UPSERT triggers on the
--   (clientID, subjectHash) PAIR: re-affirming the SAME (client, subject)
--   mapping (a repeat issuance, or a rotation) still hits the
--   ON DUPLICATE KEY branch and is a cheap no-op; two DIFFERENT clients that
--   happen to mint the SAME subjectHash (the public-client collision case
--   above) now INSERT two SEPARATE rows instead of colliding — exactly the
--   semantics OAuthUserInfoService's own (subjectHash, clientID) lookup
--   already assumed.
--
-- 🔗 Design source: this task's explicit build contract (B-062 remediation)
--    — replace the UNIQUE key with a composite one, guarded + idempotent,
--    per the repo's information_schema-guard convention (mirrors migration
--    032's guarded ADD COLUMN pattern, applied here to an INDEX instead of a
--    column).
--
-- ⚙️ Applies via the CLI runner (_scripts/setup_test_db.sh loops 014.. and
--    does `mysql DB < file`) and the admin migration UI. It uses NO
--    `DELIMITER` directive and NO `USE <db>;` (mirrors 030-034's header
--    notes — a hardcoded USE would mis-target the throwaway signula_test
--    database).
--
-- 🛡️ Idempotent + additive + non-destructive: BOTH the DROP and the ADD are
--    guarded by an information_schema.STATISTICS existence check (mirrors
--    migration 032's information_schema.COLUMNS guard for
--    tblOAuthAuthCodes.issuedFamilyID — MariaDB's `DROP INDEX IF EXISTS` /
--    `ADD ... IF NOT EXISTS` syntax is NOT portable to plain MySQL 8/9, so
--    this project's convention is the guarded dynamic-SQL PREPARE/EXECUTE
--    pattern instead). Safe to re-run: a second run finds uk_subject already
--    gone and uk_subject_client already present, so both branches become
--    no-op SELECTs. Does NOT self-write to tblMigrations (the migration
--    runner owns that bookkeeping — mirrors the 008/030/031/032/033/034
--    note).
--
-- @see web/private_html/security/TokenService.php (resolveAndRecordSubject() — the UPSERT this key backs)
-- @see web/private_html/auth/OAuthUserInfoService.php ((subjectHash, clientID) lookup — already correctly keyed)
-- @see web/private_html/auth/OAuthClientManager.php (computeSubject() — pairwise vs public subject semantics)
-- @see _database/migrations/033_oauth_subject_store.sql (the table + the single-column key this replaces)
-- @see _database/migrations/032_oauth_token_endpoint.sql (the guarded PREPARE/EXECUTE pattern this mirrors)
-- ============================================================================

-- (No `USE <db>;` — see header note above.)

-- ============================================================================
-- 1️⃣ Drop the OLD single-column UNIQUE KEY uk_subject, if present
-- ============================================================================
SET @uk_subject_exists = (
    SELECT COUNT(DISTINCT INDEX_NAME)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblOAuthSubjects'
    AND INDEX_NAME = 'uk_subject'
);

SET @sql = IF(
    @uk_subject_exists > 0,
    'ALTER TABLE `tblOAuthSubjects` DROP INDEX `uk_subject`',
    'SELECT "Index tblOAuthSubjects.uk_subject already absent" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 2️⃣ Add the NEW COMPOSITE UNIQUE KEY uk_subject_client (clientID, subjectHash), if absent
-- ============================================================================
SET @uk_subject_client_exists = (
    SELECT COUNT(DISTINCT INDEX_NAME)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblOAuthSubjects'
    AND INDEX_NAME = 'uk_subject_client'
);

SET @sql = IF(
    @uk_subject_client_exists = 0,
    'ALTER TABLE `tblOAuthSubjects` ADD UNIQUE KEY `uk_subject_client` (`clientID`, `subjectHash`)',
    'SELECT "Index tblOAuthSubjects.uk_subject_client already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 3️⃣ Verification queries
-- ============================================================================
SELECT 'tblOAuthSubjects.uk_subject dropped' AS Status,
    (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblOAuthSubjects'
        AND INDEX_NAME = 'uk_subject') AS old_index_still_present;

SELECT 'tblOAuthSubjects.uk_subject_client composite index' AS Status,
    (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblOAuthSubjects'
        AND INDEX_NAME = 'uk_subject_client') AS new_index_exists;

-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 035_oauth_subjects_composite_unique.sql completed successfully' AS Result;
