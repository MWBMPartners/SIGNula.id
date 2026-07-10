-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 🕵️ SIGNula - OAuth2/OIDC Provider: Pairwise-Subject Resolution Store (G-001 red-team F-01)
-- ============================================================================
--
-- Migration: 033_oauth_subject_store.sql
-- Feature:   G-001 red-team remediation — F-01 (HIGH): the RP ACCESS TOKEN's
--            `sub` claim leaked the raw internal userID (TokenService::mintPair
--            signed `'sub' => (string) $userID` unconditionally), even though
--            the id_token and /oauth/userinfo correctly returned the OPAQUE
--            pairwise subject (OAuthClientManager::computeSubject()). Any RP —
--            or two colluding RPs in different sectors — could decode the
--            (unencrypted) access-token JWT and read the stable raw userID,
--            defeating the whole point of `subject_type: pairwise`.
-- Version:   2.9.0-beta
-- Date:      2026-07-10
--
-- 📝 What this migration adds:
--
--   tblOAuthSubjects — a resolution table mapping an issued (pairwise or
--   public) subject value BACK to the (userID, clientID) it was computed for.
--   The fix makes the RP access token's `sub` the SAME pairwise value the
--   id_token/userinfo already use — but a pairwise subject is a ONE-WAY hash
--   (sha256(sector|userID|salt)), so /oauth/userinfo can no longer recover the
--   userID by simply casting the token's `sub` to an int. This table is the
--   lookup that makes that resolution possible again: TokenService::
--   issueForClient()/refresh() UPSERT the mapping at mint/rotation time;
--   OAuthUserInfoService looks it up by (subjectHash, clientID) instead of
--   parsing the sub as a raw userID.
--
-- 🔗 Design source: this task's explicit build contract (G-001 red-team F-01
--    remediation) — schema, column types, and constraints specified verbatim.
--
-- ⚙️ Applies via the CLI runner (_scripts/setup_test_db.sh loops 014.. and does
--    `mysql DB < file`) and the admin migration UI. It uses NO `DELIMITER`
--    directive and NO `USE <db>;` (mirrors 030/031/032's header notes — a
--    hardcoded USE would mis-target the throwaway signula_test database).
--
-- 🛡️ Idempotent + additive + non-destructive: CREATE TABLE IF NOT EXISTS.
--    Safe to re-run. Does NOT self-write to tblMigrations (the migration
--    runner owns that bookkeeping — mirrors the 008/030/031/032 note).
--
-- @see .dev-team/specs/G-001.md §4, §5
-- @see web/private_html/security/TokenService.php (issueForClient()/refresh() — populates this table)
-- @see web/private_html/auth/OAuthUserInfoService.php (reads this table to resolve sub -> userID)
-- @see web/private_html/auth/OAuthClientManager.php (computeSubject() — the pairwise hash this table indexes)
-- @see _database/migrations/031_oauth_provider_clients.sql (tblOAuthClients / tblUsers — the FK targets)
-- ============================================================================

-- (No `USE <db>;` — see header note above.)

-- ============================================================================
-- 1️⃣ Subject (sub claim) → (userID, clientID) resolution store
-- ============================================================================
-- One row per (user, client) subject ever issued. `subjectHash` is the exact
-- string minted into a token's `sub` claim (a SHA-256 hex digest for a
-- `pairwise` subjectType client, or the raw decimal userID string for a
-- `public` subjectType client — see OAuthClientManager::computeSubject()).
-- UNIQUE on subjectHash alone (not composite with clientID): a pairwise
-- subject is already sector-scoped so it is naturally unique per client; the
-- mint/rotation path UPSERTs so re-affirming the SAME mapping is a no-op.
CREATE TABLE IF NOT EXISTS tblOAuthSubjects (
    subjectID       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- 🔑 The exact `sub` claim value minted into the RP's access/id token.
    subjectHash     CHAR(64) NOT NULL COMMENT 'sha256(sector|userID|salt) for pairwise clients, or the raw userID string for public-subject clients',

    -- 👤 What it resolves back to.
    userID          BIGINT UNSIGNED NOT NULL,
    clientID        BIGINT UNSIGNED NOT NULL,

    createdAt       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (userID)   REFERENCES tblUsers(userID) ON DELETE CASCADE,
    FOREIGN KEY (clientID) REFERENCES tblOAuthClients(clientID) ON DELETE CASCADE,
    UNIQUE KEY uk_subject (subjectHash),
    INDEX idx_client_user (clientID, userID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='OIDC subject (sub claim) -> (userID, clientID) resolution store — G-001 red-team F-01 fix';

-- ============================================================================
-- 2️⃣ Verification query
-- ============================================================================
SELECT 'tblOAuthSubjects table' AS Status,
    (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblOAuthSubjects') AS table_exists;

-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 033_oauth_subject_store.sql completed successfully' AS Result;
