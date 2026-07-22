-- ============================================================================
-- SIGNula Database Migration 043
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- 📋 Migration: Retention Policies + Auto-Purge Cron Foundation (G-004 Layer 4a §5.3)
-- 📅 Date:      2026-07-10
-- 🔖 Version:   2.7.0-beta
-- 📝 Description:
--   Data-driven retention/auto-purge configuration, PLUS the settings that
--   gate the new `web/public_html/cron/compliance.php` endpoint. Creates:
--     - tblRetentionPolicies : one row per retention rule (which table, which
--                              date column, how many days, anonymise-or-delete).
--                              RetentionManager.php is the ONLY PHP that reads
--                              this table, and it NEVER trusts a row's
--                              targetTable/dateColumn/anonymiseColumns blindly
--                              — every one is re-validated against a hardcoded
--                              PHP allowlist (RetentionManager::ALLOWED_TARGETS)
--                              before touching SQL. See that class for the
--                              full contract.
--   Plus 4 tblSettings rows that gate purge behaviour and cron auth:
--     - compliance.cron.secret_token : isSensitive=1 (encrypted at rest),
--                                      ships EMPTY. The owner MUST set a
--                                      strong random value via the Settings
--                                      UI before the cron endpoint will run
--                                      anything — see that row's own comment.
--     - retention.purge.enabled      : '0' (boolean) — OFF by default.
--     - retention.purge.batch_size   : '500' (integer) — bounded execution.
--     - retention.purge.dry_run      : '1' (boolean) — ON by default.
--
--   ⚠️ SAFE-BY-DEFAULT, BELT AND BRACES: retention.purge.enabled ships FALSE
--   AND retention.purge.dry_run ships TRUE simultaneously. RetentionManager
--   treats "either one says don't touch data" as authoritative — an operator
--   must explicitly flip BOTH before a single row is ever anonymised/deleted.
--   Every seeded tblRetentionPolicies row below ALSO ships isActive = 0, a
--   THIRD independent gate. Nothing in this migration, on its own, purges
--   anything, ever.
--
-- 🔗 Related: .dev-team/specs/G-004.md §5.3 (Layer 4a — retention + auto-purge),
--    web/private_html/compliance/RetentionManager.php (the ONLY PHP that reads
--    tblRetentionPolicies), web/public_html/cron/compliance.php (the cron
--    endpoint this migration's settings gate — ALSO the first caller of the
--    pre-existing but never-wired AccountManager::processScheduledDeletions()
--    / AccountManager::cleanupExpiredExports() GDPR engines),
--    web/public_html/cron/billing.php (the sibling cron endpoint this mirrors
--    for token-auth structure), _database/migrations/042_compliance_regimes.sql
--    (the immediately-preceding G-004 Layer 3 migration this follows in the
--    same "additive, no auto-activation" spirit).
-- ============================================================================

-- ============================================================================
-- 🏗️ CREATE RETENTION POLICIES TABLE
-- ============================================================================
-- 🗂️ One row per retention/auto-purge rule. RetentionManager::runDuePolicies()
-- walks only the isActive=1 rows, and even then re-validates targetTable/
-- dateColumn/anonymiseColumns against its own PHP allowlist before ever
-- building a query — this table's contents can NEVER inject an arbitrary
-- table/column name into SQL (see RetentionManager::isPolicyAllowlisted()).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblRetentionPolicies` (
    `policyID`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `policyKey`        VARCHAR(64) NOT NULL
        COMMENT 'Stable short key identifying this policy, e.g. activity_log_anonymise',
    `targetTable`      VARCHAR(64) NOT NULL
        COMMENT 'Table this policy governs — MUST exactly match a key in RetentionManager::ALLOWED_TARGETS or the policy is skipped, never executed',
    `dateColumn`       VARCHAR(64) NOT NULL
        COMMENT 'Column the retention window is measured against — MUST exactly match an allowlisted dateColumn for targetTable',
    `retentionDays`    INT UNSIGNED NOT NULL
        COMMENT 'Rows older than this many days (measured on dateColumn) become due for anonymise/delete',
    `action`           ENUM('anonymise','delete') NOT NULL DEFAULT 'anonymise'
        COMMENT 'anonymise is PREFERRED — nulls/redacts allowlisted PII columns in place; delete removes the row entirely',
    `anonymiseColumns` TEXT NULL DEFAULT NULL
        COMMENT 'JSON array of columns to null/redact when action=anonymise — every entry MUST exactly match an allowlisted anonymiseColumns key for targetTable or the WHOLE policy is skipped',
    `regimeCode`       VARCHAR(16) NULL DEFAULT NULL
        COMMENT 'Optional soft link to tblComplianceRegimes.regimeCode (L3) — documents WHICH regime motivates this retention window; no FK (regime config may change independently)',
    `isActive`         TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'OFF by default for every seeded policy — an operator activates only after reviewing the retention period + columns with counsel; RetentionManager::runDuePolicies() only ever reads isActive=1 rows',
    `lastRunAt`        DATETIME NULL DEFAULT NULL
        COMMENT 'Stamped ONLY on a live (non-dry-run, enabled) execution — never touched by a dry-run/disabled pass, so this column doubles as "has this policy ever actually purged anything"',
    `notes`            TEXT NULL DEFAULT NULL
        COMMENT 'Free-text operator notes — seeded rows carry a mandatory "review before activating" warning',
    `createdAt`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`policyID`),
    UNIQUE KEY `uq_retention_policy_key` (`policyKey`),
    INDEX `idx_retention_active` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Retention/auto-purge policy definitions (G-004 Layer 4a) — read exclusively by RetentionManager, always re-validated against a PHP allowlist';

-- ============================================================================
-- 🌱 SEED SAFE DRAFT POLICIES — ALL isActive = 0
-- ============================================================================
-- Idempotent via ON DUPLICATE KEY UPDATE against UNIQUE(policyKey) — never
-- clobbers an admin's later edits to any column (mirrors migration 042's
-- regime-seed idiom exactly).
--
-- ⚠️ These are DRAFTS. isActive=0 means RetentionManager::runDuePolicies()
-- will NEVER select these rows at all — activation is a deliberate future
-- admin action, not something this migration performs.
-- ============================================================================

INSERT INTO `tblRetentionPolicies`
    (`policyKey`, `targetTable`, `dateColumn`, `retentionDays`, `action`, `anonymiseColumns`, `regimeCode`, `isActive`, `notes`)
VALUES
    ('activity_log_anonymise', 'tblActivityLog', 'createdAt', 365, 'anonymise',
     '["ipAddress","userAgent"]', NULL, 0,
     'DRAFT — review retention period + columns with owner/counsel before activating. Suggested starting point: 365 days is a common general-purpose audit-log retention window, but confirm against any applicable regime SLA/statute before enabling. Anonymises (nulls) ipAddress + userAgent only; userID/activityType/description are left intact so the audit trail remains analytically useful after PII is stripped.')
    ON DUPLICATE KEY UPDATE `policyKey` = `policyKey`;

INSERT INTO `tblRetentionPolicies`
    (`policyKey`, `targetTable`, `dateColumn`, `retentionDays`, `action`, `anonymiseColumns`, `regimeCode`, `isActive`, `notes`)
VALUES
    ('activity_log_anonymise_extended', 'tblActivityLog', 'createdAt', 730, 'anonymise',
     '["ipAddress","userAgent","userID"]', NULL, 0,
     'DRAFT — review retention period + columns with owner/counsel before activating. Alternative to activity_log_anonymise with a longer 2-year window and a stricter anonymisation set that also nulls userID (fully severing the link to the account, at the cost of losing per-user audit continuity past this window). Only ONE of these two draft policies should typically be activated for tblActivityLog to avoid double-processing the same rows — an operator choosing between them is exactly the kind of judgement call this migration declines to make.')
    ON DUPLICATE KEY UPDATE `policyKey` = `policyKey`;

-- ============================================================================
-- ⚙️ SETTINGS (G-004 Layer 4a)
-- ============================================================================
-- Real tblSettings columns (verified against migrations 040/041/042):
--   settingKey, settingValue, settingType, settingCategory, isSensitive, description
-- ============================================================================

-- 🔐 Cron secret token — mirrors payment.cron.secret_token's (migration 012)
-- isSensitive=1/settingType='encrypted' pattern exactly. Ships EMPTY: no
-- real/guessable token value is ever placed in code or a migration. Until an
-- operator sets a strong random value, cron/compliance.php refuses to run
-- anything (see that file's "cron not configured" branch).
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('compliance.cron.secret_token', '', 'encrypted', 'privacy', 1,
     'Secret token for authenticating the compliance cron endpoint (/cron/compliance.php?token=xxx). MUST be set to a long, cryptographically random value (e.g. 32+ bytes, hex-encoded) via the Settings UI before this cron will run — ships empty and the endpoint refuses to execute until configured. Never commit a real value to source control.')
    ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 🚫 Purge master switch — OFF by default. Both this AND dry_run below must
-- be explicitly changed by an operator before RetentionManager ever mutates
-- a row (see RetentionManager::resolvePurgeMode()).
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('retention.purge.enabled', '0', 'boolean', 'privacy', 0,
     'Master switch for the retention auto-purge cron. OFF (0/false) by default — while OFF, RetentionManager::runDuePolicies() only ever REPORTS how many rows would be affected per active policy; it changes nothing. An operator must explicitly enable this (and disable retention.purge.dry_run) before any anonymise/delete actually runs.')
    ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📦 Batch size — bounds how many rows a single cron invocation may
-- anonymise/delete per policy, even once live purging is enabled.
-- RetentionManager clamps this to [1, 5000] regardless of the stored value.
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('retention.purge.batch_size', '500', 'integer', 'privacy', 0,
     'Maximum rows a single retention-policy execution may anonymise/delete per cron run (LIMIT clause). Clamped server-side to the range 1-5000 regardless of the value stored here.')
    ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 🧪 Dry-run switch — ON by default. Even if an operator flips
-- retention.purge.enabled to true, dry_run staying true keeps the cron in
-- report-only mode. Both switches must be flipped together to go live.
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('retention.purge.dry_run', '1', 'boolean', 'privacy', 0,
     'When true (default), the retention auto-purge cron computes and reports how many rows PER ACTIVE POLICY would be anonymised/deleted, but changes nothing. Must be explicitly set to false, together with retention.purge.enabled=true, before any row is actually mutated.')
    ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================
