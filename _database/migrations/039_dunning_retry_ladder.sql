-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 🔔 SIGNula - Dunning Retry Ladder (G-002 Stage S4)
-- ============================================================================
--
-- Migration: 039_dunning_retry_ladder.sql
-- Feature:   G-002 Stage S4 — dunning retry ladder + grace timers, PLUS the
--            B-071 CreditManager fix (code-only — see
--            web/private_html/payments/CreditManager.php; no schema change
--            required for that half of this stage, see the note below).
-- Version:   2.9.0-beta
-- Date:      2026-07-10
--
-- 📝 What this migration adds (all additive — no DROP, no data rewrite, no
--    destructive ENUM member removal or reordering):
--
--   1️⃣ `tblBillingSchedule.taskType` ENUM — widen to add `dunning_retry` and
--      `dunning_final_cancel`, the two new task types
--      `web/private_html/payments/BillingScheduler.php` now creates/consumes
--      (`scheduleDunningRetry()` / `processDunningRetry()` /
--      `processDunningFinalCancel()`, mirroring migration 018's identical
--      widen for the usage-billing task types).
--
--   2️⃣ `tblSettings` — seed the dunning ladder's two configurable knobs:
--        - `billing.dunning.retry_days`  ('1,3,5,7' default) — the CSV
--          ladder; retry_days[N-1] is the number of days BEFORE rung N fires
--          (chained-interval model — see BillingScheduler::
--          scheduleDunningRetry()'s doc-comment for why no new
--          tblSubscriptions column is needed to track "when did dunning
--          start").
--        - `billing.dunning.max_retries` ('4' default) — rungs before the
--          ladder is EXHAUSTED (`past_due -> grace`).
--      `billing.grace_period_days` (grace -> final-cancel window) and
--      `billing.test_mode_guard` already exist (migration 036) — reused
--      unchanged, not re-seeded here.
--
--   3️⃣ Two NEW `tblEmailTemplates` rows: `dunning_final_notice` (sent when
--      the ladder is exhausted and the subscription enters `grace`) and
--      `subscription_cancelled` (sent when the grace window lapses and the
--      subscription is cancelled). The per-RUNG failure email deliberately
--      REUSES the EXISTING `payment_failed` template (migration 012) — no
--      new per-retry template — per the build instruction to reuse rather
--      than proliferate templates; these two are net-NEW terminal-state
--      notifications with no existing equivalent, so seeding them here is
--      the minimum needed for §5's dunning flow to actually notify a user at
--      those two points (the flow is otherwise silent there).
--
-- 🧭 NOT in this migration (deliberately):
--    - No `tblCreditBalances`/`tblCreditTransactions` schema change. The
--      B-071 CreditManager fix is 100% a CODE fix (Database::query("START
--      TRANSACTION"/"COMMIT"/"ROLLBACK") -> Database::beginTransaction()/
--      commit()/rollback(); `transactionType` -> the REAL `type` column; the
--      non-existent totalDeposited/totalWithdrawn columns are no longer
--      written, DERIVABLE on demand from the tblCreditTransactions ledger
--      instead of a separately-persisted (and driftable) running total; a
--      transfer is now recorded as an ordinary withdrawal+deposit pair
--      tagged `referenceType='transfer'` rather than a non-existent
--      `type='transfer'` ENUM member) — see CreditManager.php's own B-071
--      doc-comments for the full reasoning on each point. No CreditManager
--      column is "genuinely needed" per the build brief's own bar.
--    - No `tblSubscriptions.dunningAttempts`/`dunningStartedAt`/`graceEndsAt`
--      columns (present in the G-002 spec's fuller §6.2 sketch). Ladder
--      progress is tracked entirely via the EXISTING `tblBillingSchedule`
--      task chain (each rung's `scheduledFor` + `metadata.attemptNumber`)
--      and the EXISTING `tblBillingAttempts` ledger (one audited row per
--      rung outcome, keyed by a stable idempotency key) — both already
--      exist (migration 036) and are pure REUSE, not a new table/columns.
--    - No `tblBillingAttempts.idempotencyKey` UNIQUE constraint (S1 left it
--      deliberately non-unique — see migration 036's own note). Not required
--      here: `BillingScheduler::processDunningRetry()`'s own SELECT-before-
--      write guard (an existing-row-for-this-key check) is the idempotency
--      enforcement for this stage, matching S3's `applyWebhookTransition()`/
--      `recordSubscriptionRenewal()` precedent exactly.
--
-- 🛡️ Idempotent + additive + non-destructive:
--    - `ALTER … MODIFY` on an ENUM to WIDEN it (append new members, keep
--      every existing member in its original position/spelling) is itself
--      idempotent — re-running it just re-asserts the same widened
--      definition (mirrors migrations 018/029/036/038's documented reasoning).
--    - `INSERT IGNORE` against tblSettings' UNIQUE settingKey column (mirrors
--      034/036's settings-seeding pattern) — never clobbers a value an admin
--      has since edited via the Settings UI.
--    - `INSERT IGNORE` against tblEmailTemplates' UNIQUE templateKey column
--      (mirrors migration 012's own email-template seeding pattern exactly).
--    - No `USE <db>;`, no `DELIMITER`, no self-write to tblMigrations (the
--      migration runner owns that bookkeeping — mirrors every prior
--      migration's note).
--
-- @see .dev-team/specs/G-002.md §4.2/§5/§6.4/§9.1 (the authoritative design this stage implements)
-- @see web/private_html/payments/BillingScheduler.php (scheduleDunningRetry() / processDunningRetry() / processDunningFinalCancel())
-- @see web/private_html/payments/PaymentManager.php (applyWebhookTransition() — schedules the FIRST dunning rung on entering past_due)
-- @see web/private_html/payments/CreditManager.php (the B-071 code fix — no schema change)
-- @see _database/migrations/018_usage_billing.sql (the taskType ENUM-widen precedent)
-- @see _database/migrations/012_payment_expansion.sql (the payment_failed template being reused, and the email-template seed pattern)
-- @see https://dev.mysql.com/doc/refman/8.0/en/alter-table.html
-- @see https://dev.mysql.com/doc/refman/8.0/en/enum.html
-- ============================================================================

-- (No `USE <db>;` — see header note above.)

-- ============================================================================
-- 1️⃣ tblBillingSchedule.taskType — widen for the dunning ladder
-- ============================================================================
-- `ALTER … MODIFY` on an ENUM to widen it is itself idempotent (mirrors
-- migration 018's identical widen for the usage-billing task types) — no
-- information_schema guard needed for this statement.
ALTER TABLE `tblBillingSchedule`
    MODIFY COLUMN `taskType` ENUM(
        'charge_subscription',
        'send_reminder',
        'suspend_account',
        'expire_trial',
        'process_remittance',
        'generate_invoice',
        'fee_change_notification',
        'calculate_usage',
        'charge_usage',
        'archive_usage',
        'dunning_retry',
        'dunning_final_cancel'
    ) NOT NULL
        COMMENT 'Type of billing task to execute (adds dunning_retry/dunning_final_cancel — G-002 S4)';

-- ============================================================================
-- 2️⃣ tblSettings — seed the dunning ladder's configurable knobs
-- ============================================================================
INSERT IGNORE INTO tblSettings
    (settingKey, settingValue, settingType, settingCategory, isSensitive, isEditable, description, defaultValue)
VALUES
    (
        'billing.dunning.retry_days',
        '1,3,5,7',
        'string',
        'billing',
        FALSE,
        TRUE,
        'G-002 dunning retry ladder (CSV, days). retry_days[N-1] = days to wait BEFORE rung N fires, chained from the previous rung (rung 1 waits this many days after the subscription first enters past_due). Read by BillingScheduler::scheduleDunningRetry(). Change this and the ladder timing changes with no code edit.',
        '1,3,5,7'
    ),
    (
        'billing.dunning.max_retries',
        '4',
        'integer',
        'billing',
        FALSE,
        TRUE,
        'G-002 dunning retry ladder: number of retry rungs before the ladder is EXHAUSTED and the subscription moves past_due -> grace. Read by BillingScheduler::processDunningRetry(). Should normally match the number of entries in billing.dunning.retry_days.',
        '4'
    );

-- ============================================================================
-- 3️⃣ tblEmailTemplates — the two NEW terminal-state dunning notifications
-- ============================================================================
-- Uses {{variableName}} syntax for variable substitution via EmailService,
-- matching migration 012's established email-template pattern exactly.
INSERT IGNORE INTO `tblEmailTemplates`
    (`templateKey`, `templateName`, `subject`, `bodyHTML`, `bodyText`, `category`, `trackingEnabled`, `requiredVariables`, `isActive`)
VALUES

-- ⚠️ Dunning ladder exhausted -> subscription entering the grace window
('dunning_final_notice', 'Dunning Final Notice',
'Final Notice — Your Subscription Will Be Cancelled Soon - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#dc3545;">⚠️ Final Notice</h1><p>Hi {{displayName}},</p><p>We have been unable to collect payment of <strong>{{currency}} {{amount}}</strong> for your <strong>{{tierName}}</strong> subscription after several attempts.</p><p>Your account remains active for now, but if payment is not received within <strong>{{graceDays}} days</strong>, your subscription will be <strong>cancelled</strong> and access to {{tierName}} features will end.</p><p><a href="{{updateURL}}" style="display:inline-block;padding:10px 24px;background:#dc3545;color:#fff;text-decoration:none;border-radius:6px;">Update Payment Method Now</a></p></div>',
'Hi {{displayName}}, We could not collect {{currency}} {{amount}} for your {{tierName}} subscription. Your account will be cancelled in {{graceDays}} days unless payment is received. Update at: {{updateURL}}',
'transactional', 1,
'["displayName","tierName","currency","amount","graceDays","updateURL"]',
1),

-- ❌ Grace window lapsed -> subscription cancelled
('subscription_cancelled', 'Subscription Cancelled',
'Your Subscription Has Been Cancelled - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#6c757d;">❌ Subscription Cancelled</h1><p>Hi {{displayName}},</p><p>Your <strong>{{tierName}}</strong> subscription has been cancelled because payment could not be collected within the grace period.</p><p>You can reactivate at any time to restore your {{tierName}} features:</p><p><a href="{{reactivateURL}}" style="display:inline-block;padding:10px 24px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px;">Reactivate Subscription</a></p></div>',
'Hi {{displayName}}, Your {{tierName}} subscription has been cancelled due to non-payment. Reactivate at: {{reactivateURL}}',
'transactional', 0,
'["displayName","tierName","reactivateURL"]',
1);

-- ============================================================================
-- 4️⃣ Verification queries
-- ============================================================================
SELECT 'tblBillingSchedule.taskType widened (G-002 S4)' AS Status,
    COLUMN_TYPE AS taskType_enum
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'tblBillingSchedule' AND column_name = 'taskType';

SELECT 'billing.dunning.* settings seeded' AS Status,
    COUNT(*) AS row_count
    FROM tblSettings WHERE settingKey IN ('billing.dunning.retry_days', 'billing.dunning.max_retries');

SELECT 'dunning email templates seeded' AS Status,
    COUNT(*) AS row_count
    FROM tblEmailTemplates WHERE templateKey IN ('dunning_final_notice', 'subscription_cancelled');

-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 039_dunning_retry_ladder.sql completed successfully' AS Result;
