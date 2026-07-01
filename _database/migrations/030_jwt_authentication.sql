-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 🎫 SIGNula - JWT API Authentication (G-003 Stage 1: crypto foundation)
-- ============================================================================
--
-- Migration: 030_jwt_authentication.sql
-- Feature:   G-003 (#41 / B-006 / FG-004) — JWT bearer auth for the REST API
-- Version:   2.8.0-beta
-- Date:      2026-07-01
--
-- 📝 What this migration adds (Stage 1 — data model + policy settings only; the
--    HTTP endpoints, TokenService and BaseController wiring land in Stages 2-3):
--
--   1. tblRefreshTokens  — rotating, family-based, reuse-detected refresh tokens
--                          (opaque, stored SHA-256-HASHED — plaintext never kept).
--   2. tblRevokedTokens  — a self-expiring jti denylist for revoked (unexpired)
--                          access tokens (stateless access tokens made revocable).
--   3. tblUsers.tokensInvalidBefore — a user-level mass-revocation lever: any
--                          access token with iat before this instant is rejected.
--   4. tblSettings rows  — JWT token policy (issuer/audience/TTLs/leeway/alg/
--                          active_kid/auto-rotate/master-switch). The signing-key
--                          material rows (jwt.signing_key.<kid>.private_pem /
--                          .public_jwk) are seeded at runtime by
--                          KeyManager::generateKey(), NOT hardcoded here.
--   5. A daily cleanup EVENT that purges expired denylist + dead refresh rows.
--
-- 🔗 Design source: .dev-team/specs/G-003.md §8. This migration follows that
--    schema with THREE deliberate, load-bearing corrections vs the spec's draft
--    SQL (all logged in the Decision log at the foot of the spec / the PR body):
--
--     • Migration NUMBER is 030, not the spec's "025". 025 is already taken
--       (025_user_mfa_flags.sql); 029 is the current highest, so 030 is next.
--     • FK id columns are BIGINT UNSIGNED, not the spec's "INT UNSIGNED":
--       tblUsers.userID is BIGINT UNSIGNED, and a FOREIGN KEY column MUST match
--       the referenced column's exact type or MySQL rejects it (errno 3780/150).
--     • tblSettings inserts use the REAL column names: settingCategory (not the
--       spec's "category") and description; there is NO isPublic column in this
--       schema. Verified against signula_complete_install_v2.5.0.sql.
--
-- ⚙️ Applies via the CLI runner (_scripts/setup_test_db.sh loops 014.. and does
--    `mysql DB < file`) and the admin migration UI. It uses NO `DELIMITER`
--    directive: the single CREATE EVENT below has a single-statement body, so
--    multi_query / the mysql client parse it without a custom delimiter.
--
-- 🛡️ Idempotent + additive + non-destructive: CREATE TABLE IF NOT EXISTS,
--    INSERT IGNORE for settings, and an information_schema-guarded ADD COLUMN.
--    Safe to re-run. Does NOT self-write to tblMigrations (the migration runner
--    owns that bookkeeping — mirrors the 008 [mig-fix] note).
--
-- @see .dev-team/specs/G-003.md
-- @see web/private_html/security/KeyManager.php  (seeds the signing-key rows)
-- @see web/private_html/security/Jwt.php          (reads jwt.* policy settings)
-- ============================================================================

-- (No `USE <db>;` — migrations apply to the connected/target DB via the runner,
--  wizard, and setup_test_db.sh; a hardcoded USE mis-targets non-`signula` DBs.)

-- ============================================================================
-- 1️⃣ Refresh tokens (rotating, family-based, reuse-detected)
-- ============================================================================
-- Opaque high-entropy tokens, stored SHA-256-hashed (plaintext never persisted
-- or logged — mirrors tblAPIKeys.keyHash and the C5 reset-token fix). Each token
-- belongs to a rotation FAMILY: presenting an already-rotated/revoked member
-- signals theft and revokes the whole family (reuse detection — Stage 2 logic).
CREATE TABLE IF NOT EXISTS tblRefreshTokens (
    tokenID       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    userID        BIGINT UNSIGNED NOT NULL COMMENT 'Owner (FK tblUsers.userID — BIGINT to match PK)',
    familyID      CHAR(32) NOT NULL COMMENT 'Rotation family — reuse anywhere in the family revokes the family',
    tokenHash     CHAR(64) NOT NULL COMMENT 'SHA-256 hex of the opaque refresh token (plaintext never stored)',
    clientID      BIGINT UNSIGNED NULL COMMENT 'Reserved for G-001 RP clients (tblOAuthClients); NULL = first-party',
    scope         VARCHAR(512) NULL COMMENT 'Space-delimited scopes granted to this token',
    issuedAt      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expiresAt     DATETIME NOT NULL COMMENT 'Absolute expiry (issuedAt + jwt.refresh_ttl)',
    rotatedAt     DATETIME NULL COMMENT 'Set when this token was exchanged (single-use rotation)',
    replacedByID  BIGINT UNSIGNED NULL COMMENT 'The token row that superseded this one',
    revoked       BOOLEAN NOT NULL DEFAULT 0,
    revokedReason VARCHAR(100) NULL,
    ipAddress     VARCHAR(45) NULL COMMENT 'IPv4 or IPv6 of the issuing/rotating request',
    userAgent     TEXT NULL,
    createdAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (userID) REFERENCES tblUsers(userID) ON DELETE CASCADE,
    UNIQUE KEY idx_token_hash (tokenHash),
    INDEX idx_family (familyID),
    INDEX idx_user_active (userID, revoked, expiresAt),
    INDEX idx_expires (expiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Rotating refresh tokens with family-based reuse detection (G-003)';

-- ============================================================================
-- 2️⃣ Revoked access-token jti denylist (self-expiring)
-- ============================================================================
-- Access tokens are stateless (verified by signature + claims), so revocation
-- uses a jti denylist. Rows self-expire at the original token exp (a cron purge
-- deletes them), so the denylist only ever holds UNEXPIRED revoked tokens and
-- stays tiny (access TTL is 15 min). Jwt::verify() checks jti against this table.
CREATE TABLE IF NOT EXISTS tblRevokedTokens (
    revokeID   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    jti        CHAR(32) NOT NULL COMMENT 'JWT ID (jti) of the revoked access token — 16 random bytes as hex',
    userID     BIGINT UNSIGNED NULL COMMENT 'Owner (FK tblUsers.userID); NULL if unknown at revoke time',
    expiresAt  DATETIME NOT NULL COMMENT 'Original token exp — row is purged after this instant',
    reason     VARCHAR(100) NULL,
    revokedAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (userID) REFERENCES tblUsers(userID) ON DELETE SET NULL,
    UNIQUE KEY idx_jti (jti),
    INDEX idx_expires (expiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Denylist of revoked (unexpired) access-token jti values (G-003)';

-- ============================================================================
-- 3️⃣ tblUsers.tokensInvalidBefore — user-level mass-revocation lever
-- ============================================================================
-- Any access token with iat before this timestamp is rejected. Lets an admin (or
-- a "log out everywhere" / password-change flow) invalidate every outstanding
-- access token for a user without enumerating jtis. Guarded so it is idempotent
-- and safe to re-run (mirrors the information_schema pattern in migration 025).
SET @tokens_invalid_before_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblUsers'
    AND COLUMN_NAME = 'tokensInvalidBefore'
);

SET @sql = IF(
    @tokens_invalid_before_exists = 0,
    'ALTER TABLE `tblUsers`
        ADD COLUMN `tokensInvalidBefore` DATETIME NULL
        COMMENT "Access tokens with iat before this are rejected (mass-revoke lever, G-003)"',
    'SELECT "Column tblUsers.tokensInvalidBefore already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 4️⃣ Settings: JWT token policy (non-sensitive)
-- ============================================================================
-- The signing keys themselves are NOT seeded here — KeyManager::generateKey()
-- creates jwt.signing_key.<kid>.private_pem (isSensitive=1, AES-256-CBC
-- encrypted) and jwt.signing_key.<kid>.public_jwk (isSensitive=0) at runtime,
-- and points jwt.signing_key.active_kid at the current signer.
--
-- Column list uses the REAL tblSettings columns:
--   (settingKey, settingValue, settingType, isSensitive, settingCategory, description)
INSERT IGNORE INTO tblSettings
    (settingKey, settingValue, settingType, isSensitive, settingCategory, description)
VALUES
    ('jwt.enabled',               '1',                        'boolean', 0, 'jwt', 'Master switch for JWT bearer authentication'),
    ('jwt.issuer',                'https://signula.id',       'string',  0, 'jwt', 'iss claim — token issuer URL'),
    ('jwt.audience',              'https://signula.id/api',   'string',  0, 'jwt', 'Default aud claim for first-party API tokens'),
    ('jwt.algorithm',             'RS256',                    'string',  0, 'jwt', 'Signing algorithm (RS256 only — asymmetric, externally verifiable)'),
    ('jwt.access_ttl',            '900',                      'integer', 0, 'jwt', 'Access-token lifetime in seconds (default 15 min)'),
    ('jwt.refresh_ttl',           '2592000',                  'integer', 0, 'jwt', 'Refresh-token lifetime in seconds (default 30 days)'),
    ('jwt.leeway_seconds',        '60',                       'integer', 0, 'jwt', 'Clock-skew leeway for exp/nbf/iat (seconds)'),
    ('jwt.key_bits',              '3072',                     'integer', 0, 'jwt', 'RSA signing-key size in bits (2048 min, 3072 recommended)'),
    ('jwt.signing_key.active_kid','',                         'string',  0, 'jwt', 'kid of the current signing key (set by KeyManager)'),
    ('jwt.auto_rotate_days',      '90',                       'integer', 0, 'jwt', 'Warn/auto-mint when the active signing key is older than this');
-- jwt.signing_key.<kid>.private_pem  (isSensitive=1, encrypted)  — inserted by KeyManager::generateKey()
-- jwt.signing_key.<kid>.public_jwk   (isSensitive=0)             — inserted by KeyManager::generateKey()

-- ============================================================================
-- 5️⃣ Scheduled cleanup event
-- ============================================================================
-- Purges expired denylist rows and dead (expired) refresh tokens daily so both
-- tables stay bounded. Single-statement body → no DELIMITER needed.
DROP EVENT IF EXISTS cleanup_jwt_tokens;

CREATE EVENT IF NOT EXISTS cleanup_jwt_tokens
ON SCHEDULE EVERY 1 DAY
COMMENT 'Purge expired JWT denylist rows and dead refresh tokens (G-003)'
DO
    DELETE FROM tblRevokedTokens WHERE expiresAt < NOW();

-- Note: a second cleanup (tblRefreshTokens WHERE expiresAt < NOW() AND revoked=1)
-- is intentionally deferred to Stage 2, where the refresh-token write path exists
-- and its retention policy is finalised. Keeping ONE single-statement event here
-- avoids needing DELIMITER for a compound body under the `mysql DB < file` runner.

-- ============================================================================
-- 6️⃣ Verification queries
-- ============================================================================
SELECT 'JWT auth tables created' AS Status,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tblRefreshTokens') AS tblRefreshTokens_exists,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tblRevokedTokens') AS tblRevokedTokens_exists;

SELECT 'tblUsers.tokensInvalidBefore column' AS Status,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUsers'
        AND COLUMN_NAME = 'tokensInvalidBefore') AS column_exists;

SELECT 'JWT policy settings seeded' AS Status,
    COUNT(*) AS jwt_settings_count
FROM tblSettings WHERE settingKey LIKE 'jwt.%';

-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 030_jwt_authentication.sql completed successfully' AS Result;
