-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 🛡️ SIGNula - Trusted Reverse-Proxy Allowlist Setting (B-061)
-- ============================================================================
--
-- Migration: 034_trusted_proxies_setting.sql
-- Feature:   B-061 (MED, HIGH-impact for rate-limit + audit integrity) —
--            getClientIP() (web/_config/config.php) used to walk
--            HTTP_CF_CONNECTING_IP -> HTTP_X_FORWARDED_FOR -> HTTP_X_REAL_IP
--            -> HTTP_CLIENT_IP -> REMOTE_ADDR and trust the first valid
--            PUBLIC address it found. On this project's real deployment
--            target (Dreamhost shared hosting — direct Apache, no reverse
--            proxy in front) every one of those headers is fully
--            attacker-controlled: any client could send
--            `X-Forwarded-For: <any public IP>` and getClientIP() returned
--            exactly that, defeating every per-IP rate limiter (Auth::login,
--            JWT issuance/revoke, OAuth token endpoints) and poisoning
--            IP-reputation / impossible-travel detection / tblActivityLog's
--            IP attribution.
--
--            The fix (see getClientIP()/getTrustedProxyCidrs()/
--            resolveForwardedClientIP()/ipInCidr()/ipInAnyCidr() in
--            web/_config/config.php) now DEFAULTS to trusting REMOTE_ADDR
--            only, and honours forwarded headers ONLY when the immediate
--            TCP peer (REMOTE_ADDR) is itself listed in this setting — the
--            standard "trusted proxy chain" model.
-- Version:   2.9.0-beta
-- Date:      2026-07-10
--
-- 📝 What this migration adds:
--
--   A single tblSettings row, `security.trusted_proxies`, seeded EMPTY.
--   Empty means "trust nothing but the direct peer (REMOTE_ADDR)" — the
--   correct default for a direct-Apache deployment with no reverse proxy in
--   front. An admin who DOES front SIGNula with a reverse proxy (e.g.
--   Cloudflare, or a self-hosted nginx/HAProxy) sets this to a
--   comma/whitespace-separated list of CIDR ranges and/or bare IPs
--   (IPv4 AND IPv6) identifying that proxy's own address(es) — e.g.
--   Cloudflare's published IP ranges — at which point getClientIP() will
--   walk X-Forwarded-For (or fall back to CF-Connecting-IP) to resolve the
--   real client behind that trusted hop.
--
-- 🔗 Design source: this task's explicit build contract (B-061 remediation)
--    — settingKey, semantics, and empty-by-default behaviour specified
--    verbatim.
--
-- ⚙️ Applies via the CLI runner (_scripts/setup_test_db.sh loops 014.. and
--    does `mysql DB < file`) and the admin migration UI. It uses NO
--    `DELIMITER` directive and NO `USE <db>;` (mirrors 030/031/032/033's
--    header notes — a hardcoded USE would mis-target the throwaway
--    signula_test database).
--
-- 🛡️ Idempotent + additive + non-destructive: `INSERT IGNORE` against
--    tblSettings' UNIQUE settingKey column (mirrors 030_jwt_authentication's
--    settings-seeding pattern) — re-running this migration is a silent
--    no-op once the row exists, and never clobbers a value an admin has
--    since edited via the Settings UI. Does NOT self-write to
--    tblMigrations (the migration runner owns that bookkeeping — mirrors
--    the 008/030/031/032/033 note).
--
-- 📋 Column list matches the REAL tblSettings schema (verified against
--    _database/signula_complete_install_v2.5.0.sql): settingKey,
--    settingValue, settingType, settingCategory, isSensitive, isEditable,
--    description, defaultValue. There is NO createdAt column — `updatedAt`
--    is DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE
--    CURRENT_TIMESTAMP, so it self-populates without being listed here.
--
-- @see web/_config/config.php (getClientIP(), getTrustedProxyCidrs(), resolveForwardedClientIP(), ipInCidr(), ipInAnyCidr())
-- @see _database/migrations/030_jwt_authentication.sql (the INSERT IGNORE settings-seeding pattern this mirrors)
-- ============================================================================

-- (No `USE <db>;` — see header note above.)

-- ============================================================================
-- 1️⃣ Seed the trusted-proxy allowlist setting (empty by default)
-- ============================================================================
INSERT IGNORE INTO tblSettings
    (settingKey, settingValue, settingType, settingCategory, isSensitive, isEditable, description, defaultValue)
VALUES
    (
        'security.trusted_proxies',
        '',
        'string',
        'security',
        FALSE,
        TRUE,
        'Comma- and/or whitespace-separated allowlist of CIDR ranges and/or bare IPs (IPv4 AND IPv6) for front-end reverse proxies this server sits behind (e.g. Cloudflare edge ranges, or a self-hosted nginx/HAProxy). getClientIP() only trusts X-Forwarded-For / CF-Connecting-IP when the immediate connecting peer (REMOTE_ADDR) matches an entry here. EMPTY (the default) means: trust only the direct peer (REMOTE_ADDR) — correct for a direct-Apache deployment (e.g. Dreamhost shared hosting) with no reverse proxy in front.',
        ''
    );

-- ============================================================================
-- 2️⃣ Verification query
-- ============================================================================
SELECT 'security.trusted_proxies setting' AS Status,
    (SELECT COUNT(*) FROM tblSettings WHERE settingKey = 'security.trusted_proxies') AS row_exists;

-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 034_trusted_proxies_setting.sql completed successfully' AS Result;
