-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 💳 SIGNula - tblPayments.paymentMethod — restore 'stripe' + 'manual' (G-002 S3)
-- ============================================================================
--
-- Migration: 038_payments_paymentmethod_stripe_manual_fix.sql
-- Feature:   G-002 Stage S3 — webhook subscription-lifecycle mapping.
-- Version:   2.9.0-beta
-- Date:      2026-07-10
--
-- 🐛 Bug found while building S3 (Stripe renewal path testing against a real
--    MySQL server): `013_kofi_patreon_providers.sql` (2026-02-18) intended to
--    "extend the existing paymentMethod ENUM" for tblPayments (its own header
--    comment says so — line 20: "Expanded paymentMethod ENUM in tblPayments
--    for ko-fi and patreon") but its `MODIFY COLUMN` REPLACED the whole ENUM
--    definition instead of widening it:
--
--      migration 011 (2026-02-11) left it as:
--        ('paypal','apple_pay','google_pay','crypto_btc','crypto_eth',
--         'crypto_usdt','stripe','link','manual')
--
--      migration 013 (2026-02-18) overwrote it as:
--        ('card','paypal','crypto','crypto_btc','crypto_eth','crypto_usdt',
--         'crypto_usdc','apple_pay','google_pay','link','kofi','patreon')
--
--    — silently DROPPING 'stripe' and 'manual' (while genuinely adding
--    'card'/'crypto'/'crypto_usdc'/'kofi'/'patreon', which were the change's
--    actual intent). Since migration 013, `INSERT INTO tblPayments (...,
--    paymentMethod, ...) VALUES (..., 'stripe', ...)` — the exact call
--    PaymentManager::recordPayment($userID, $amount, 'stripe', 'stripe', …)
--    makes for every Stripe-driven payment (one-off, subscription creation,
--    and now the G-002 S3 renewal path) — fails under MySQL strict mode with
--    "Data truncated for column 'paymentMethod'". `paymentMethod = 'manual'`
--    (used by admin-recorded/free-tier payments) has the same problem.
--
--    This is scoped ONLY to `tblPayments` — `tblSubscriptions.paymentMethod`
--    and `tblPaymentMethods.paymentMethod` were NOT touched by migration 013
--    (grep-confirmed: 013's only other `ALTER TABLE` targets
--    `tblPartnerPaymentConfig.provider`) and still correctly include 'stripe'/
--    'manual' today — no change needed there.
--
-- 🛡️ Idempotent + additive + non-destructive: `ALTER … MODIFY` on an ENUM to
--    WIDEN it (union of every member ANY prior migration ever set — nothing
--    removed, nothing renamed, nothing reordered away) is itself idempotent —
--    re-running it just re-asserts the same widened definition (mirrors
--    migration 029's / 036's documented reasoning for the same pattern). No
--    existing row's paymentMethod value can become invalid, because every
--    member that was ever legal remains a member.
--
-- @see _database/migrations/011_payment_providers.sql (introduced 'stripe'/'manual'/'link')
-- @see _database/migrations/013_kofi_patreon_providers.sql (the regression — dropped 'stripe'/'manual')
-- @see web/private_html/payments/PaymentManager.php::recordPayment() (the call this unblocks)
-- @see .dev-team/specs/G-002.md §3.1/§5.1/§7 (the Stripe renewal path this was found blocking)
-- @see https://dev.mysql.com/doc/refman/8.0/en/alter-table.html
-- @see https://dev.mysql.com/doc/refman/8.0/en/enum.html
-- ============================================================================

-- (No `USE <db>;` — see header note in migration 036/037.)

ALTER TABLE `tblPayments`
    MODIFY COLUMN `paymentMethod`
        ENUM(
            'paypal', 'apple_pay', 'google_pay',
            'crypto_btc', 'crypto_eth', 'crypto_usdt', 'crypto_usdc', 'crypto',
            'stripe', 'link', 'manual', 'card', 'kofi', 'patreon'
        )
        NOT NULL
        COMMENT 'Payment method used for this transaction (widened 038 — restores stripe/manual dropped by 013''s non-additive MODIFY)';

-- ============================================================================
-- Verification query
-- ============================================================================
SELECT 'tblPayments.paymentMethod ENUM (038 fix)' AS Status,
    COLUMN_TYPE AS paymentMethod_enum
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'tblPayments' AND column_name = 'paymentMethod';

-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 038_payments_paymentmethod_stripe_manual_fix.sql completed successfully' AS Result;
