-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 🔐 SIGNula - User Authentication Flag Columns Migration
-- ============================================================================
--
-- Migration: 025 - Missing tblUsers columns the application depends on
--            (mfaEnabled / webauthnEnabled / passwordlessEnabled / organizationID)
-- Date: 2026-06-30
--
-- 🐛 Fixes a long-standing schema-drift bug (B-029):
--
--   The application code reads and writes three boolean flag columns on
--   `tblUsers` that NO prior migration ever created:
--     • mfaEnabled          (web/private_html/api/controllers/MFAController.php
--                            and UserController.php — UPDATE / SELECT)
--     • webauthnEnabled      (UserController.php SELECT)
--     • passwordlessEnabled  (UserController.php SELECT)
--
--   Migration 005_webauthn_passkeys.sql tried to add `webauthnEnabled`
--   "AFTER mfaEnabled" — but `mfaEnabled` itself was never added to tblUsers,
--   so that ALTER fails on a clean install ("Unknown column 'mfaEnabled'") and
--   none of the three columns end up existing. As a result:
--     • UserController::getUserById()'s SELECT (mfaEnabled, webauthnEnabled,
--       passwordlessEnabled FROM tblUsers) errors at runtime, and
--     • MFAController's "UPDATE tblUsers SET mfaEnabled = 1" errors.
--
--   This migration adds the missing columns. It is purely ADDITIVE and
--   NON-DESTRUCTIVE: each column is a new TINYINT(1) NOT NULL DEFAULT 0, added
--   only if it does not already exist (so it is safe on installs where 005 did
--   manage to add webauthnEnabled / passwordlessEnabled, and safe to re-run).
--
-- Order matters: mfaEnabled is added first so webauthnEnabled / passwordlessEnabled
-- can sit after it, matching the layout migration 005 intended.
--
-- Safe to run multiple times (guarded by information_schema existence checks).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1️⃣ tblUsers.mfaEnabled — the column 005 assumed already existed
-- ----------------------------------------------------------------------------
SET @mfa_enabled_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblUsers'
    AND COLUMN_NAME = 'mfaEnabled'
);

SET @sql = IF(
    @mfa_enabled_exists = 0,
    'ALTER TABLE `tblUsers`
        ADD COLUMN `mfaEnabled` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT "User has multi-factor authentication enabled" AFTER `emailVerifiedAt`',
    'SELECT "Column tblUsers.mfaEnabled already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2️⃣ tblUsers.webauthnEnabled — added by 005 only if mfaEnabled existed first
-- ----------------------------------------------------------------------------
SET @webauthn_enabled_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblUsers'
    AND COLUMN_NAME = 'webauthnEnabled'
);

SET @sql = IF(
    @webauthn_enabled_exists = 0,
    'ALTER TABLE `tblUsers`
        ADD COLUMN `webauthnEnabled` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT "User has WebAuthn/passkeys enabled" AFTER `mfaEnabled`',
    'SELECT "Column tblUsers.webauthnEnabled already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 3️⃣ tblUsers.passwordlessEnabled — added by 005 only if mfaEnabled existed first
-- ----------------------------------------------------------------------------
SET @passwordless_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblUsers'
    AND COLUMN_NAME = 'passwordlessEnabled'
);

SET @sql = IF(
    @passwordless_exists = 0,
    'ALTER TABLE `tblUsers`
        ADD COLUMN `passwordlessEnabled` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT "User can login without a password" AFTER `webauthnEnabled`',
    'SELECT "Column tblUsers.passwordlessEnabled already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 4️⃣ tblUsers.organizationID — required by Auth::register()
-- ----------------------------------------------------------------------------
-- 🐛 Auth::register() INSERTs `organizationID` into tblUsers, but no prior
--    migration ever added the column, so EVERY registration fails with
--    "Unknown column 'organizationID'" → "Registration failed. Please try
--    again later." The multi-organization feature's own tables
--    (tblOrganizations / tblOrganizationDomains / tblOrganizationMembers) are
--    likewise not yet provisioned, so we add ONLY the nullable column here
--    (no FOREIGN KEY) to unblock registration. The org auto-enrolment path in
--    register() is guarded by `if ($organizationID && $autoEnrolled)`, both of
--    which stay false until the org tables exist, so this is safe and additive.
SET @org_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblUsers'
    AND COLUMN_NAME = 'organizationID'
);

SET @sql = IF(
    @org_exists = 0,
    'ALTER TABLE `tblUsers`
        ADD COLUMN `organizationID` BIGINT UNSIGNED NULL
        COMMENT "Owning organization (multi-org feature; NULL = personal account)" AFTER `accountTier`',
    'SELECT "Column tblUsers.organizationID already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================
-- Post-migration verification:
--   SHOW COLUMNS FROM tblUsers LIKE 'mfaEnabled';
--   SHOW COLUMNS FROM tblUsers LIKE 'webauthnEnabled';
--   SHOW COLUMNS FROM tblUsers LIKE 'passwordlessEnabled';
--   SHOW COLUMNS FROM tblUsers LIKE 'organizationID';
-- ============================================================================
