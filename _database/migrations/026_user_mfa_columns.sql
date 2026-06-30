-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 🔐 SIGNula - tblUserMFA Column Alignment Migration
-- ============================================================================
--
-- Migration: 026 - Add tblUserMFA columns the MFA class depends on
-- Date: 2026-06-30
--
-- 🐛 Fixes a schema-drift bug (B-029):
--
--   The MFA class (web/private_html/security/MFA.php) reads and writes three
--   tblUserMFA columns that the canonical schema NEVER created:
--     • mfaEnabled  — per-method enabled flag      (enableTOTP / verify / disable)
--     • mfaSecret   — encrypted TOTP secret         (enableTOTP / getTOTPSecret)
--     • isVerified  — whether the user confirmed it  (verifyAndActivateTOTP)
--
--   The schema instead has the legacy names `isEnabled` and `totpSecret` and no
--   `isVerified`. As a result enableTOTP(), verifyAndActivateTOTP(),
--   generateBackupCodes() and isEnabled() all throw at runtime
--   ("Unknown column 'mfaEnabled' / 'mfaSecret' / 'isVerified'"), so TOTP and
--   backup-code MFA are entirely non-functional.
--
--   Both the application code AND the MFA test suite agree on the
--   mfaEnabled / mfaSecret / isVerified names, so those are the intended
--   columns. This migration ADDS them. It is purely additive and
--   non-destructive: the legacy `isEnabled` / `totpSecret` columns are left in
--   place (unused), and each new column is added only if missing.
--
-- ⚠️  NOTE for lead review: ideally the legacy `isEnabled` / `totpSecret`
--     columns would be consolidated with the new ones (a rename + data copy),
--     but that is a data-migration decision; this forward-only additive
--     migration unblocks the feature without touching existing data.
--
-- Safe to run multiple times (guarded by information_schema existence checks).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1️⃣ tblUserMFA.mfaEnabled
-- ----------------------------------------------------------------------------
SET @c = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUserMFA' AND COLUMN_NAME = 'mfaEnabled'
);
SET @sql = IF(
    @c = 0,
    'ALTER TABLE `tblUserMFA`
        ADD COLUMN `mfaEnabled` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT "Whether this MFA method is enabled/active" AFTER `mfaType`',
    'SELECT "Column tblUserMFA.mfaEnabled already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2️⃣ tblUserMFA.mfaSecret
-- ----------------------------------------------------------------------------
SET @c = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUserMFA' AND COLUMN_NAME = 'mfaSecret'
);
SET @sql = IF(
    @c = 0,
    'ALTER TABLE `tblUserMFA`
        ADD COLUMN `mfaSecret` VARCHAR(255) NULL
        COMMENT "Encrypted TOTP/MFA secret" AFTER `mfaEnabled`',
    'SELECT "Column tblUserMFA.mfaSecret already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 3️⃣ tblUserMFA.isVerified
-- ----------------------------------------------------------------------------
SET @c = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUserMFA' AND COLUMN_NAME = 'isVerified'
);
SET @sql = IF(
    @c = 0,
    'ALTER TABLE `tblUserMFA`
        ADD COLUMN `isVerified` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT "Whether the user has confirmed/activated this method" AFTER `mfaSecret`',
    'SELECT "Column tblUserMFA.isVerified already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================
-- Post-migration verification:
--   SHOW COLUMNS FROM tblUserMFA LIKE 'mfaEnabled';
--   SHOW COLUMNS FROM tblUserMFA LIKE 'mfaSecret';
--   SHOW COLUMNS FROM tblUserMFA LIKE 'isVerified';
-- ============================================================================
