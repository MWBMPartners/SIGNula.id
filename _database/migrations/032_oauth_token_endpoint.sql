-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 🪪 SIGNula - OAuth2/OIDC Provider: Token Endpoint replay-revoke linkage (G-001 Stage A3)
-- ============================================================================
--
-- Migration: 032_oauth_token_endpoint.sql
-- Feature:   G-001 (#87 / FG-001) — SIGNula as an OAuth2/OIDC PROVIDER
--            ("Sign in with SIGNula.id"). Stage A3: the /oauth/token
--            authorization_code exchange (OAuthTokenService.php,
--            web/public_html/oauth/token.php).
-- Version:   2.9.0-beta
-- Date:      2026-07-10
--
-- 📝 What this migration adds:
--
--   tblOAuthAuthCodes.issuedFamilyID — a single nullable column that lets a
--   REPLAYED authorization code (RFC 6749 §4.1.2/§10.5: a code MUST be single-
--   use; presenting an already-consumed code again is a signal the code or its
--   resulting tokens may have been intercepted) be traced back to the EXACT
--   refresh-token rotation family the FIRST (legitimate) exchange minted, so
--   that family can be revoked — killing the access+refresh pair the possible
--   attacker (or the confused legitimate client double-submitting) is holding.
--
-- 🔗 Why a column and not a lookup-by-(clientID,userID) revoke:
--   The task's build contract offered two options: (a) store the issued
--   family on the code row, or (b) revoke ALL of a (clientID,userID) pair's
--   tokens on replay. (b) is TOO BROAD — a user can have several legitimate,
--   independent authorization grants to the SAME client over time (e.g. two
--   different devices each completing their own consent flow); revoking every
--   token for that (clientID,userID) pair on a single replayed code would log
--   out sessions that were never touched by the replay. (a) is the TIGHTEST
--   correct scope: it revokes EXACTLY the one rotation family the specific
--   replayed code produced, and nothing else — mirroring how G-003's own
--   refresh-token reuse detection (TokenService::handleReuse) revokes only the
--   ONE family a specific spent token belonged to, not every token the user
--   holds. See OAuthTokenService::handlePossibleReplay().
--
-- 🔗 Design source: .dev-team/specs/G-001.md §5, §4 (tblOAuthAuthCodes) and
--    this task's explicit build contract (G-001 Stage A3, "REPLAY" handling).
--
-- ⚙️ Applies via the CLI runner (_scripts/setup_test_db.sh loops 014.. and does
--    `mysql DB < file`) and the admin migration UI. It uses NO `DELIMITER`
--    directive and NO `USE <db>;` (mirrors 030/031's header notes — a
--    hardcoded USE would mis-target the throwaway signula_test database).
--
-- 🛡️ Idempotent + additive + non-destructive: the ALTER is guarded by an
--    information_schema check (mirrors migration 030's
--    tblUsers.tokensInvalidBefore guard — MariaDB's `ADD COLUMN IF NOT EXISTS`
--    is NOT portable to plain MySQL, so this project's convention is the
--    guarded dynamic-SQL PREPARE/EXECUTE pattern instead). Safe to re-run.
--    Does NOT self-write to tblMigrations (the migration runner owns that
--    bookkeeping — mirrors the 008/030/031 note).
--
-- @see .dev-team/specs/G-001.md §4, §5
-- @see web/private_html/auth/OAuthTokenService.php (Stage A3 — populates/reads this column)
-- @see web/public_html/oauth/token.php (the thin controller that consumes it)
-- @see _database/migrations/031_oauth_provider_clients.sql (tblOAuthAuthCodes — the table this ALTERs)
-- @see _database/migrations/030_jwt_authentication.sql (the guarded-ALTER precedent this mirrors)
-- ============================================================================

-- (No `USE <db>;` — see header note above.)

-- ============================================================================
-- 1️⃣ tblOAuthAuthCodes.issuedFamilyID — replay-revoke linkage
-- ============================================================================
-- NULL until the code's first (and only ever legitimate) successful exchange
-- mints a token family; OAuthTokenService writes the family_id here
-- immediately after minting. A SECOND exchange attempt of the SAME code (the
-- atomic `UPDATE ... SET consumedAt = NOW() WHERE consumedAt IS NULL` in
-- OAuthTokenService::exchangeAuthorizationCode() finds 0 affected rows because
-- consumedAt is already set) re-reads this column to find — and revoke — the
-- exact family the first exchange produced.
SET @issued_family_id_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblOAuthAuthCodes'
    AND COLUMN_NAME = 'issuedFamilyID'
);

-- 🔧 Note the doubled single-quote (`''`) below — the WHOLE ALTER statement is
--    itself a single-quoted string literal (assigned to @sql for PREPARE), so
--    the apostrophe in "code''s" must be escaped the standard SQL way (two
--    consecutive single quotes), not shell-style quoting.
SET @sql = IF(
    @issued_family_id_exists = 0,
    'ALTER TABLE `tblOAuthAuthCodes`
        ADD COLUMN `issuedFamilyID` CHAR(32) NULL
        COMMENT "Rotation family minted by this code''s first (only legitimate) exchange - set post-mint, read on replay to revoke (G-001 Stage A3)"
        AFTER `consumedAt`',
    'SELECT "Column tblOAuthAuthCodes.issuedFamilyID already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 2️⃣ Verification queries
-- ============================================================================
SELECT 'tblOAuthAuthCodes.issuedFamilyID column' AS Status,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblOAuthAuthCodes'
        AND COLUMN_NAME = 'issuedFamilyID') AS column_exists;

-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 032_oauth_token_endpoint.sql completed successfully' AS Result;
