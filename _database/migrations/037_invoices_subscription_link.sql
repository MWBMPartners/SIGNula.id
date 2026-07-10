-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 🧾 SIGNula - tblInvoices.subscriptionID (G-002 Stage S2)
-- ============================================================================
--
-- Migration: 037_invoices_subscription_link.sql
-- Feature:   G-002 Stage S2 — plan change / proration invoicing.
-- Version:   2.9.0-beta
-- Date:      2026-07-10
--
-- 🐛 Bug found while building S2 (proration invoices): `tblInvoices` as
--    created by migration 012 (`012_payment_expansion.sql` — the same
--    definition baked into `_database/signula_complete_install_v2.5.0.sql`)
--    has NO `subscriptionID` column, yet
--    `web/private_html/payments/InvoiceManager.php::createInvoice()` has
--    always accepted an `$options['subscriptionID']` and attempted to INSERT
--    it — meaning EVERY call to createInvoice() with a subscriptionID
--    (including the pre-existing `PaymentManager::completePayment()`
--    renewal-invoice path, shipped in v2.4.0) has failed with an "Unknown
--    column" mysqli_sql_exception on every real database, silently
--    swallowed by the caller's own non-fatal try/catch. This migration adds
--    the column the application code has always expected to exist — see the
--    companion PHP fix in `InvoiceManager::createInvoice()` (G-002 S2) that
--    also corrects several OTHER column-name mismatches
--    (billingName->billedToName, billingEmail->billedToEmail,
--    billingAddress->billedToAddress, vatNumber->billedToVATNumber,
--    totalAmount->total) in the SAME INSERT statement.
--
-- 🛡️ Idempotent + additive + non-destructive:
--    - New column guarded by an information_schema existence check feeding a
--      PREPARE/EXECUTE conditional ALTER — mirrors migration 036's
--      established idiom exactly (standard MySQL, this project's dev/CI
--      target, does NOT support `ADD COLUMN IF NOT EXISTS`).
--    - `ON DELETE SET NULL` FK (mirrors `fk_ba_subscription` in migration
--      036's `tblBillingAttempts`) — deleting a subscription must never
--      cascade-delete its historical invoices.
--    - No `USE <db>;`, no `DELIMITER`, no self-write to tblMigrations (the
--      migration runner owns that bookkeeping — mirrors every prior migration's note).
--
-- @see .dev-team/specs/G-002.md §5.3 (proration invoices)
-- @see web/private_html/payments/InvoiceManager.php (the class this unblocks)
-- @see _database/migrations/012_payment_expansion.sql (the original tblInvoices definition)
-- @see https://dev.mysql.com/doc/refman/8.0/en/alter-table.html
-- ============================================================================

-- (No `USE <db>;` — see header note above.)

SET @inv_subscription_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblInvoices'
    AND COLUMN_NAME = 'subscriptionID'
);

SET @sql = IF(
    @inv_subscription_exists = 0,
    'ALTER TABLE `tblInvoices`
        ADD COLUMN `subscriptionID` BIGINT UNSIGNED DEFAULT NULL
            COMMENT "FK to tblSubscriptions — linked subscription for a renewal/proration invoice (G-002 S2)"
            AFTER `paymentID`,
        ADD INDEX `idx_inv_subscription` (`subscriptionID`),
        ADD CONSTRAINT `fk_inv_subscription` FOREIGN KEY (`subscriptionID`)
            REFERENCES `tblSubscriptions` (`subscriptionID`) ON DELETE SET NULL',
    'SELECT "tblInvoices.subscriptionID already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Verification query
-- ============================================================================
SELECT 'tblInvoices.subscriptionID (G-002 S2)' AS Status,
    (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'tblInvoices' AND column_name = 'subscriptionID') AS subscriptionID_exists;

-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 037_invoices_subscription_link.sql completed successfully' AS Result;
