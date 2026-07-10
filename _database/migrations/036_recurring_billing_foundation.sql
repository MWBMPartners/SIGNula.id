-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 💳 SIGNula - Recurring Billing Engine Foundation (G-002 Stage S1)
-- ============================================================================
--
-- Migration: 036_recurring_billing_foundation.sql
-- Feature:   G-002 Stage S1 — the TEST-MODE recurring-billing FOUNDATION.
--            SAFETY: this entire epic is TEST/SANDBOX ONLY. No real money
--            moves as a result of this migration or the code it supports.
--            Going LIVE is a separate, USER-GATED operational step (GitHub
--            issue #70 — "Configure Live Payment Provider Credentials") that
--            this migration does NOT perform. See billing.test_mode_guard
--            below and web/private_html/payments/BillingMode.php.
-- Version:   2.9.0-beta
-- Date:      2026-07-10
--
-- 📝 What this migration adds (all additive — no DROP, no data rewrite,
--    no destructive ENUM member removal or PK rename):
--
--   1️⃣ B-025 — reconcile `tblBillingSchedule` schema↔code drift.
--      BillingScheduler.php (grep-verified) reads/writes `nextRetryAt`,
--      `result`, and `updatedAt` columns, and writes a `'cancelled'` task
--      status — NONE of which exist in the table as created by migration 012
--      (`012_payment_expansion.sql`: scheduleID, taskType, targetType,
--      targetID, scheduledFor, status ENUM('pending','processing','completed',
--      'failed','skipped'), attempts, maxAttempts, lastAttemptAt, completedAt,
--      errorMessage, metadata, createdAt — no nextRetryAt/result/updatedAt).
--      Every one of BillingScheduler::getTasksDue() / ::markTaskProcessed() /
--      ::retryTask() / ::cancelPendingTasks() / ::getSchedulerHealth() (and
--      the admin API in web/public_html/admin/api/billing-schedule-actions.php)
--      would fail with "Unknown column" on a clean install without this fix.
--      (NOTE: earlier task framing referenced a missing `priority` column —
--      grep across the whole codebase found ZERO references to a `priority`
--      column on tblBillingSchedule; the "priority" hits in BillingScheduler.php
--      are the 5th arg to EmailService::sendTemplateEmail(), an unrelated
--      email-priority parameter, not a DB column. The REAL drift — verified
--      by grepping every SQL statement that touches tblBillingSchedule — is
--      the nextRetryAt/result/updatedAt/'cancelled'-status gap fixed below,
--      plus a `taskID` vs `scheduleID` PK-name mismatch fixed in PHP only,
--      per the note under §1️⃣ below — no schema rename needed for that part.)
--
--   2️⃣ TEST-MODE guard audit table — `tblBillingAttempts` (NEW) + the
--      `billing.test_mode_guard` setting (default '1'/ON). Written by
--      web/private_html/payments/BillingMode.php::assertTestMode() whenever
--      a money-moving call is BLOCKED because a provider resolved to 'live'
--      mode while the guard is ON — the code-level enforcement of this
--      epic's "TEST-MODE ONLY" safety constraint.
--
--   3️⃣ Subscription lifecycle — add the `grace` state to
--      `tblSubscriptions.subscriptionStatus` (dunning-exhausted / pre-
--      suspension window — see web/private_html/payments/
--      SubscriptionStateMachine.php). The existing `cancelled` spelling is
--      NOT renamed (destructive) — all 7 existing ENUM members keep their
--      original position; `grace` is appended.
--
-- 🔧 Code-alignment note (NOT SQL — informational only, done in this same
--    PR): BillingScheduler.php and billing-schedule-actions.php read/write a
--    `taskID` column that has never existed — the table's real PK is
--    `scheduleID` (migration 012). Renaming a PK is destructive and is
--    explicitly forbidden by this epic's safety rules, so the fix is a
--    PURE PHP CHANGE: every affected SELECT now aliases `scheduleID AS
--    taskID`, and every WHERE clause now targets `scheduleID` directly. No
--    SQL below performs or requires that rename.
--
-- 🛡️ Idempotent + additive + non-destructive:
--    - New tblBillingSchedule columns are guarded by an information_schema
--      existence check feeding a PREPARE/EXECUTE conditional ALTER (standard
--      MySQL — this project's dev/CI target, see _scripts/setup_test_db.sh —
--      does NOT support `ADD COLUMN IF NOT EXISTS`; that is a MariaDB-only
--      extension). Mirrors 028_organizations.sql / 032_oauth_token_endpoint.sql's
--      established idiom exactly.
--    - `ALTER … MODIFY` on an ENUM to WIDEN it (append new members, keep
--      every existing member in its original position/spelling) is itself
--      idempotent — re-running it just re-asserts the same definition
--      (mirrors migration 029's documented reasoning).
--    - `CREATE TABLE IF NOT EXISTS` for the new table.
--    - `INSERT IGNORE` against tblSettings' UNIQUE settingKey column (mirrors
--      034_trusted_proxies_setting.sql's settings-seeding pattern) — never
--      clobbers a value an admin has since edited via the Settings UI.
--    - No `USE <db>;`, no `DELIMITER`, no self-write to tblMigrations (the
--      migration runner owns that bookkeeping — mirrors 008/029/030/031/
--      032/033/034/035's note).
--
-- 📋 tblSettings column list matches the REAL schema (verified against
--    _database/signula_complete_install_v2.5.0.sql): settingKey, settingValue,
--    settingType, settingCategory, isSensitive, isEditable, description,
--    defaultValue, validationRules, updatedAt. There is NO createdAt column —
--    `updatedAt` is DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE
--    CURRENT_TIMESTAMP, so it self-populates without being listed here.
--
-- @see .dev-team/specs/G-002.md (the authoritative design this stage implements — §0/§5/§6/§9.1)
-- @see web/private_html/payments/BillingScheduler.php (the class this reconciles)
-- @see web/private_html/payments/BillingMode.php (the TEST-MODE guard that writes tblBillingAttempts)
-- @see web/private_html/payments/SubscriptionStateMachine.php (the state machine that enforces the ENUM transitions)
-- @see https://dev.mysql.com/doc/refman/8.0/en/alter-table.html
-- @see https://dev.mysql.com/doc/refman/8.0/en/enum.html
-- ============================================================================

-- (No `USE <db>;` — see header note above.)

-- ============================================================================
-- 1️⃣ tblBillingSchedule — B-025 schema↔code reconciliation
-- ============================================================================

-- ── 1a. Add the columns BillingScheduler.php already reads/writes, plus a
--        performance index for the getTasksDue() claim query (which filters
--        on status AND (scheduledFor OR nextRetryAt)). Standard MySQL (this
--        project's dev/CI target — see _scripts/setup_test_db.sh) does NOT
--        support `ADD COLUMN IF NOT EXISTS` (that is a MariaDB-only
--        extension), so — mirroring 028_organizations.sql /
--        032_oauth_token_endpoint.sql's established idiom — the idempotency
--        guard is an information_schema existence check feeding a
--        PREPARE/EXECUTE conditional ALTER. Checking `nextRetryAt` alone is
--        sufficient: this whole block is one atomic ALTER TABLE statement,
--        so it either fully applied on a prior run (all 4 clauses present)
--        or fully did not. ──────────────────────────────────────────────
SET @tbs_next_retry_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblBillingSchedule'
    AND COLUMN_NAME = 'nextRetryAt'
);

SET @sql = IF(
    @tbs_next_retry_exists = 0,
    'ALTER TABLE `tblBillingSchedule`
        ADD COLUMN `nextRetryAt` DATETIME DEFAULT NULL
            COMMENT "Next retry time for a failed task, set by BillingScheduler::retryTask() (B-025)"
            AFTER `lastAttemptAt`,
        ADD COLUMN `result` TEXT DEFAULT NULL
            COMMENT "Last result JSON/message, set by BillingScheduler::markTaskProcessed() (B-025)"
            AFTER `errorMessage`,
        ADD COLUMN `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            COMMENT "Last update timestamp (B-025)"
            AFTER `createdAt`,
        ADD INDEX `idx_bs_retry` (`status`, `nextRetryAt`)',
    'SELECT "tblBillingSchedule B-025 columns already exist" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── 1b. Widen the `status` ENUM to include 'cancelled' (BillingScheduler::
--        cancelPendingTasks() / the admin API's cancel_task handler already
--        write this value). Existing members keep their original position.
--        `ALTER … MODIFY` on an ENUM is itself idempotent — re-running it
--        just re-asserts the same widened definition (mirrors migration
--        029's documented reasoning), so no information_schema guard is
--        needed for this statement. ──────────────────────────────────────
ALTER TABLE `tblBillingSchedule`
    MODIFY COLUMN `status`
        ENUM('pending', 'processing', 'completed', 'failed', 'skipped', 'cancelled')
        NOT NULL DEFAULT 'pending'
        COMMENT 'Task execution status (adds cancelled — B-025)';

-- ============================================================================
-- 2️⃣ tblSubscriptions — add the 'grace' lifecycle state (G-002 spec §5)
-- ============================================================================
-- Existing members (active, cancelled, expired, paused, trial, pending,
-- past_due) are UNCHANGED in spelling and position; 'grace' is appended.
-- The DEFAULT ('pending') is unchanged, so every existing row remains valid.
ALTER TABLE `tblSubscriptions`
    MODIFY COLUMN `subscriptionStatus`
        ENUM('active', 'cancelled', 'expired', 'paused', 'trial', 'pending', 'past_due', 'grace')
        NOT NULL DEFAULT 'pending'
        COMMENT 'Subscription lifecycle status (adds grace — G-002 S1 dunning window before suspension)';

-- ============================================================================
-- 3️⃣ tblBillingAttempts — NEW: audit ledger for every money-moving attempt
-- ============================================================================
-- Written by BillingMode::assertTestMode() (a 'blocked' row whenever a
-- LIVE-mode call is refused by the guard) and available for later G-002
-- stages (S2-S4) to log 'attempted'/'succeeded'/'failed' rows against their
-- own richer per-charge idempotency-key ledger. UNIQUE constraint on
-- idempotencyKey is deliberately NOT added in this stage — that lands with
-- the full double-charge-prevention design in a later stage (see G-002 spec
-- §4.3/§6.3); S1 only needs a nullable, non-unique column here for the guard's
-- audit purpose.
CREATE TABLE IF NOT EXISTS `tblBillingAttempts` (
    `attemptID`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique attempt identifier',

    `provider`        VARCHAR(50) NOT NULL
        COMMENT 'Provider key, e.g. paypal, stripe, coinbase',

    `action`          VARCHAR(191) NOT NULL
        COMMENT 'What was attempted, e.g. charge, subscription, refund, renewal, or a Class::method caller label (191 = safe utf8mb4 index-able length for a fully-qualified label)',

    `mode`            VARCHAR(20) NOT NULL
        COMMENT 'Resolved provider mode at attempt time (sandbox/test/live)',

    `subscriptionID`  BIGINT UNSIGNED DEFAULT NULL
        COMMENT 'Linked subscription, if applicable',

    `userID`          BIGINT UNSIGNED DEFAULT NULL
        COMMENT 'Linked user, if applicable',

    `idempotencyKey`  VARCHAR(64) DEFAULT NULL
        COMMENT 'Caller-supplied idempotency key, for later stages'' double-charge prevention',

    `status`          VARCHAR(20) NOT NULL DEFAULT 'attempted'
        COMMENT 'attempted / blocked / succeeded / failed',

    `detail`          TEXT DEFAULT NULL
        COMMENT 'Human-readable detail (e.g. why a blocked attempt was refused)',

    `ipAddress`       VARCHAR(45) DEFAULT NULL
        COMMENT 'IPv4 or IPv6 address of the request that triggered the attempt',

    `createdAt`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`attemptID`),
    INDEX `idx_ba_provider` (`provider`),
    INDEX `idx_ba_status` (`status`),
    INDEX `idx_ba_subscription` (`subscriptionID`),
    INDEX `idx_ba_user` (`userID`),
    INDEX `idx_ba_idempotency` (`idempotencyKey`),
    INDEX `idx_ba_created` (`createdAt`),

    CONSTRAINT `fk_ba_subscription` FOREIGN KEY (`subscriptionID`)
        REFERENCES `tblSubscriptions` (`subscriptionID`) ON DELETE SET NULL,
    CONSTRAINT `fk_ba_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit ledger of every money-moving attempt (charge/subscription/refund/renewal), including TEST-MODE-guard blocks (G-002 S1)';

-- ============================================================================
-- 4️⃣ tblSettings — seed the TEST-MODE guard switch (default ON)
-- ============================================================================
-- billing.test_mode_guard = '1' means BillingMode::assertTestMode() REFUSES
-- any money-moving call to a provider resolved as 'live'. Turning this OFF
-- is the user's deliberate act (GitHub issue #70) — this migration does not,
-- and must never, set it to '0'.
INSERT IGNORE INTO tblSettings
    (settingKey, settingValue, settingType, settingCategory, isSensitive, isEditable, description, defaultValue)
VALUES
    (
        'billing.test_mode_guard',
        '1',
        'boolean',
        'billing',
        FALSE,
        TRUE,
        'G-002 recurring-billing TEST-MODE guard. When ON (the default), BillingMode::assertTestMode() refuses (fail-closed, and audits a blocked tblBillingAttempts row) any charge/subscription/refund call to a payment provider whose configured mode (payment.paypal.mode / payment.stripe.mode / payment.coinbase.mode) resolves to "live". Turning this OFF is a deliberate, user-gated step (GitHub issue #70 — set real live credentials FIRST) — never flip this automatically.',
        '1'
    );

-- ============================================================================
-- 5️⃣ Verification queries
-- ============================================================================
SELECT 'tblBillingSchedule reconciliation (B-025)' AS Status,
    (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'tblBillingSchedule' AND column_name = 'nextRetryAt') AS nextRetryAt_exists,
    (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'tblBillingSchedule' AND column_name = 'result') AS result_exists,
    (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'tblBillingSchedule' AND column_name = 'updatedAt') AS updatedAt_exists;

SELECT 'tblSubscriptions grace state (G-002 §5)' AS Status,
    COLUMN_TYPE AS subscriptionStatus_enum
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'tblSubscriptions' AND column_name = 'subscriptionStatus';

SELECT 'tblBillingAttempts table (G-002 §9.1)' AS Status,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tblBillingAttempts') AS tblBillingAttempts_exists;

SELECT 'billing.test_mode_guard setting seeded' AS Status,
    COUNT(*) AS row_exists
    FROM tblSettings WHERE settingKey = 'billing.test_mode_guard';

-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 036_recurring_billing_foundation.sql completed successfully' AS Result;
