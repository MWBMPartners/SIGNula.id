-- ============================================================================
-- 🏷️ SIGNula - Migration 029: Widen tblActivityLog.activityCategory ENUM
-- ============================================================================
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- Migration: 029_activity_category_enum_widen.sql
-- Version:   2.7.0-beta
-- Date:      2026-07-01
-- Purpose:   Extend the tblActivityLog.activityCategory ENUM so it accepts the
--            additional, semantically-valid activity categories that live,
--            in-production application code already writes via
--            ActivityLogger::log(). Before this migration those values were
--            NOT members of the ENUM, so under sql_mode=STRICT_ALL_TABLES MySQL
--            raised "Data truncated for column 'activityCategory' at row 1" and
--            stored '' instead — poisoning the audit trail and (crucially)
--            making the Integration test suite flake order-dependently:
--            a truncating write from one test would surface as a query
--            exception that, under PHPUnit's random execution order, tripped a
--            later ActivityLoggerTest assertion (see B-043 / B-037).
--
-- 🧩 Derivation — the six new members are the UNION of every category literal
--    passed as the 3rd argument to ActivityLogger::log() that was NOT already a
--    member of the original ENUM AND is a legitimate activity domain (as opposed
--    to a mis-ordered severity/status value, which is fixed in code instead):
--      • 'authentication' — web/private_html/auth/WebAuthnHandler.php (WebAuthn /
--                           PassKey sign-in + clone-detection events)
--      • 'organization'   — web/private_html/auth/Auth.php (auto-enrolment) and
--                           web/public_html/organization/*.php (domains/members)
--      • 'billing'        — web/private_html/payments/BillingScheduler.php,
--                           web/private_html/payments/PaymentManager.php
--      • 'email'          — web/private_html/email/*.php (queue/security/template)
--      • 'webhooks'       — web/private_html/webhooks/WebhookService.php
--      • 'api_keys'       — web/public_html/admin/api/api-key-actions.php
--
--    NOTE: the mis-ordered 'success' / 'failed' category literals in the API
--    controllers (AuthController/UserController/MFAController/…) and the
--    NotificationService misordered calls are genuine argument-order bugs, NOT
--    valid categories, and are corrected in the PHP source — they are
--    deliberately NOT added to this ENUM so the category taxonomy stays clean.
--
-- Safety: ADDITIVE / NON-DESTRUCTIVE. Every pre-existing ENUM member is retained
--         in its original position and the DEFAULT is unchanged, so all rows
--         already stored remain valid and no data is rewritten. ALTER … MODIFY
--         is idempotent for this purpose — re-running it simply re-asserts the
--         same widened definition.
--
-- Ref (MySQL): https://dev.mysql.com/doc/refman/8.0/en/enum.html
--              https://dev.mysql.com/doc/refman/8.0/en/alter-table.html
-- ============================================================================

-- 🏷️ Widen the ENUM. Original members first (positions preserved), new members
--    appended. utf8mb4 / collation are inherited from the table; no CHARSET
--    clause is needed on a MODIFY that only changes the ENUM value list.
ALTER TABLE `tblActivityLog`
    MODIFY `activityCategory`
        ENUM(
            -- ── original members (unchanged, order preserved) ──
            'auth',
            'account',
            'security',
            'payment',
            'api',
            'admin',
            'system',
            'other',
            -- ── new members (B-043 / B-037) ──
            'authentication',
            'organization',
            'billing',
            'email',
            'webhooks',
            'api_keys'
        )
        NOT NULL DEFAULT 'other'
        COMMENT 'Activity category';

-- ============================================================================
-- ✅ FINALIZATION
-- ============================================================================
SELECT 'Migration 029_activity_category_enum_widen.sql completed successfully' AS Result;
