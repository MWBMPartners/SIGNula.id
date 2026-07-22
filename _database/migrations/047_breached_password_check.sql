-- ============================================================================
-- SIGNula Database Migration 047
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- 📋 Migration: Breached / Leaked-Password Detection Settings (FG-009, Issue #96)
-- 📅 Date:      2026-07-22
-- 🔖 Version:   2.9.0-beta
-- 📝 Description:
--   Seeds the 4 tblSettings rows that gate PwnedPasswords::isBreached()
--   (web/private_html/security/PwnedPasswords.php), a Have I Been Pwned
--   (HIBP) k-anonymity breach check now wired into
--   SecurityUtils::validatePassword() — the single shared chokepoint used
--   by registration (Auth::register()), password change
--   (Auth::changePassword()), and password reset
--   (web/public_html/reset-password.php).
--
--   security.breached_password_check.enabled  — the master switch. Seeded
--     'false' (the default). While false, PwnedPasswords::isBreached()
--     returns immediately with NO network call whatsoever — this migration
--     changes ZERO current behaviour. An admin must explicitly flip this to
--     'true' via the Settings UI (or a follow-up UPDATE) to activate the
--     check.
--   security.breached_password_check.mode     — 'block' (reject a breached
--     password with a clear user-facing message) is the only mode consulted
--     by the current wiring; reserved for a future non-blocking 'warn' mode.
--   security.breached_password_check.min_count — minimum HIBP occurrence
--     count before a password is treated as "breached" (default 1 — any
--     occurrence counts).
--   security.breached_password_check.timeout_seconds — cURL timeout (in
--     seconds) for the outbound HIBP range API call (default 3 — short by
--     design; any timeout/network error/non-200 response fails OPEN, i.e.
--     the password is allowed, never blocked, on an HIBP outage).
--
--   🔒 Security notes:
--     - k-anonymity only: PwnedPasswords sends just the first 5 hex chars of
--       the SHA-1(password) as the HIBP request path; the full password (and
--       even its full hash) never leaves this server.
--       @see https://haveibeenpwned.com/API/v3#PwnedPasswords
--     - Fail-open by design: any HIBP outage/timeout/malformed response is
--       treated as "not breached" so this check can never itself cause a
--       registration/password-change/reset outage.
--
--   Real tblSettings columns (verified against
--   _database/signula_complete_install_v2.5.0.sql):
--     settingKey, settingValue, settingType, settingCategory, isSensitive,
--     isEditable, description, defaultValue. There is NO createdAt column —
--     `updatedAt` is DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE
--     CURRENT_TIMESTAMP, so it self-populates without being listed here.
--
--   🛡️ Idempotent + additive + non-destructive: `ON DUPLICATE KEY UPDATE
--   settingKey = settingKey` is a no-op update against settingKey's UNIQUE
--   constraint (mirrors 040_consent_and_dsar.sql's settings-seeding pattern)
--   — re-running this migration is a silent no-op once the rows exist, and
--   never clobbers a value an admin has since edited via the Settings UI.
--   Does NOT self-write to tblMigrations (the migration runner owns that
--   bookkeeping — mirrors the 034/040/046 note).
--
-- @see web/private_html/security/PwnedPasswords.php
-- @see web/private_html/security/SecurityUtils.php (validatePassword())
-- @see https://haveibeenpwned.com/API/v3#PwnedPasswords
-- @see _database/migrations/040_consent_and_dsar.sql (the settings-seeding pattern this mirrors)
-- ============================================================================

-- (No `USE <db>;` — see header note above; mirrors 030/031/032/033/034/040/046.)

-- ============================================================================
-- 1️⃣ Seed the breached-password-check settings (all default OFF/safe)
-- ============================================================================

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `isEditable`, `description`, `defaultValue`)
VALUES
    (
        'security.breached_password_check.enabled',
        'false',
        'boolean',
        'security',
        0,
        1,
        'FG-009 master switch for Have I Been Pwned (HIBP) breached-password detection. When false (the default), PwnedPasswords::isBreached() is a complete no-op — no network call is ever made and password validation behaves exactly as before. Set to true to enable a k-anonymity HIBP range-API check on registration, password change, and password reset.',
        'false'
    )
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `isEditable`, `description`, `defaultValue`)
VALUES
    (
        'security.breached_password_check.mode',
        'block',
        'string',
        'security',
        0,
        1,
        'FG-009 action taken when a candidate password is found in the HIBP breach database: ''block'' rejects the password with a clear user-facing message. Only consulted when security.breached_password_check.enabled=true. Reserved for a future non-blocking ''warn'' mode.',
        'block'
    )
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `isEditable`, `description`, `defaultValue`)
VALUES
    (
        'security.breached_password_check.min_count',
        '1',
        'integer',
        'security',
        0,
        1,
        'FG-009 minimum HIBP occurrence count before a password is treated as "breached". Default 1 (any occurrence in the HIBP corpus counts). Only consulted when security.breached_password_check.enabled=true.',
        '1'
    )
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `isEditable`, `description`, `defaultValue`)
VALUES
    (
        'security.breached_password_check.timeout_seconds',
        '3',
        'integer',
        'security',
        0,
        1,
        'FG-009 cURL timeout (seconds) for the outbound HIBP Pwned Passwords range-API call. Kept short by design — any timeout, network error, non-200 response, or unparsable response FAILS OPEN (password is allowed), so an HIBP outage can never itself block registration, password change, or password reset.',
        '3'
    )
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

-- ============================================================================
-- 2️⃣ Verification query
-- ============================================================================
SELECT 'security.breached_password_check.* settings' AS Status,
    (SELECT COUNT(*) FROM `tblSettings` WHERE `settingKey` IN (
        'security.breached_password_check.enabled',
        'security.breached_password_check.mode',
        'security.breached_password_check.min_count',
        'security.breached_password_check.timeout_seconds'
    )) AS rows_present,
    (SELECT settingValue FROM `tblSettings` WHERE settingKey = 'security.breached_password_check.enabled') AS enabled_value;

-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 047_breached_password_check.sql completed successfully — breached-password check remains OFF (fail-open, no network calls); zero existing rows modified' AS Result;
