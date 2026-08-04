-- ============================================================================
-- SIGNula Database Migration 048
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- 🚀 Migration: Enable Launch Features — flip the built-but-dormant
--               breached-password check ON, and activate the flexible
--               pricing catalog seeded (but disabled) by migration 046.
-- 📅 Date:      2026-08-04
-- 🔖 Version:   2.9.0-beta
-- 📝 Description:
--   Three independent, deliberately-scoped activation steps. Each is a
--   guarded UPDATE/UPSERT against rows that already exist (seeded by
--   044/046/047) — this migration creates NO new tables/columns.
--
--   1️⃣ security.breached_password_check.enabled → 'true'
--      PwnedPasswords::isBreached() (web/private_html/security/
--      PwnedPasswords.php) is fail-open by design (see its own header
--      docblock): k-anonymity-only HIBP range lookup, 3s timeout, and ANY
--      network error/timeout/non-200/unparsable response is treated as
--      "not breached" so an HIBP outage can never itself break
--      registration/password-change/reset. It is already wired into the
--      shared SecurityUtils::validatePassword() chokepoint used by
--      Auth::register(), Auth::changePassword(), and reset-password.php.
--      Flipping this setting on is therefore safe to do unconditionally.
--
--   2️⃣ Activate the flexible pricing catalog (migration 046):
--        - tblSubscriptionTiers.isActive = 1 for tierSlug IN
--          ('free','starter','pro','business','enterprise')
--          → 'free', 'pro', 'enterprise' are PRE-EXISTING rows already
--            isActive=1 since migrations 010/019 (this UPDATE is a no-op
--            for them); only 'starter' and 'business' (new rows added by
--            046, seeded isActive=0) actually change state here.
--        - tblPlanFeatures.isActive = 1 for the exact rows 046 seeded for
--          those five tiers (planScope='global', planID resolved by
--          tierSlug, effectiveFrom='2026-01-01' — the fixed anchor date
--          046 used specifically so its seeded rows are uniquely
--          identifiable/idempotent; see 046's own header note).
--        - tblCatalogPrices.isActive = 1 for the exact 8 priceKeys 046
--          seeded (free/starter/pro/business × month/year, GBP). Note:
--          046 deliberately did NOT seed an Enterprise price row
--          (Pricing_Strategy.md §3 treats Enterprise as "Contact us" /
--          sales-led, not a fixed catalog price) — there is nothing to
--          activate there, by design.
--      None of this, by itself, changes what any account is entitled to:
--      Entitlements::resolveAll() only ever reads tblPlanFeatures/
--      tblCatalogPrices at all once `entitlements.enforcement_enabled`
--      gates it open (see step 3 below) — see 046's own SAFETY note.
--
--   3️⃣ Entitlement enforcement — entitlements.enforcement_enabled → 'true',
--      entitlements.log_only LEFT UNTOUCHED (already seeded 'true' by 046).
--      🔎 VERIFIED against web/private_html/billing/Entitlements.php before
--      writing this migration: when gateActive=true (enforcement_enabled
--      AND the tblFeatureToggles 'entitlement_enforcement' row are both
--      on) AND logOnly=true, Entitlements::require() evaluates the REAL
--      resolved value, logs a would-be denial to tblActivityLog
--      (activityType='entitlement_would_deny') on a genuine denial, but
--      then explicitly `return`s WITHOUT throwing — see require()'s own
--      "Shadow week: logged, but still ALLOW (never blocks)" comment and
--      branch. has()/limit()/withinLimit() independently short-circuit to
--      "allow" whenever `__enforced` (= gateActive AND NOT logOnly) is
--      false, i.e. for the entire duration log_only stays true. This is a
--      correctly-implemented shadow-mode, so flipping
--      entitlements.enforcement_enabled to 'true' here — while log_only
--      stays 'true' — CANNOT hard-gate any current free/paid usage.
--
--      ⚠️ IMPORTANT — belt-and-braces note (NOT changed by this migration):
--      migration 046 also seeded a SEPARATE ops kill-switch row,
--      `tblFeatureToggles.entitlement_enforcement.isEnabledGlobally = 0`,
--      and Entitlements::resolveAll() requires BOTH the setting flipped
--      here AND that toggle to be on before `gateActive` is ever true (see
--      046's own §8 "belt-and-braces" header note and
--      Entitlements::isOpsToggleActive()). This migration deliberately
--      does NOT flip that toggle — the task scope here is exactly the two
--      tblSettings switches named above. Practical effect: after this
--      migration, `entitlements.enforcement_enabled='true'` but the
--      resolver STILL fully fails open (gateActive stays false, `values`
--      stays empty, nothing is logged) until a human ALSO flips
--      `tblFeatureToggles.entitlement_enforcement.isEnabledGlobally = 1`
--      (e.g. via the Settings/Feature-Toggle admin UI). That second,
--      separate flip is what actually STARTS the shadow-week logging —
--      see this migration's own trailing report / PR description.
--
--   🛡️ Idempotent + additive + non-destructive:
--     - Every tblSettings change is an INSERT ... ON DUPLICATE KEY UPDATE
--       against the settingKey UNIQUE constraint (self-healing if a fresh
--       install somehow lacks the row 044/046/047 would normally have
--       seeded), mirroring the INSERT/ON-DUPLICATE idiom already used by
--       040/046/047 — replaying this migration is a silent no-op.
--     - The tier/feature/price UPDATEs are all `SET isActive = 1` against
--       a precise, narrow WHERE clause identifying exactly the rows 046
--       seeded — no other columns are touched, no rows are deleted, and
--       replaying this migration again simply re-asserts isActive = 1
--       (already true) on the same rows.
--     - No `USE <db>;`, no stored-procedure DELIMITER switching
--       (multi_query-safe — mirrors 030/.../046/047's header notes), no
--       self-write to tblMigrations (the migration runner owns that
--       bookkeeping).
--
-- @see web/private_html/security/PwnedPasswords.php (fail-open HIBP check)
-- @see web/private_html/security/SecurityUtils.php (validatePassword() chokepoint)
-- @see web/private_html/billing/Entitlements.php (the fail-open resolver, shadow-mode logic verified above)
-- @see _database/migrations/046_flexible_pricing_catalog.sql (the dormant seed this activates)
-- @see _database/migrations/047_breached_password_check.sql (the dormant seed this activates)
-- @see PRE_LAUNCH_REVIEW.md (documents this exact rollout order: activate catalog isActive=1, then enforcement_enabled=true after a log_only shadow period)
-- @see https://haveibeenpwned.com/API/v3#PwnedPasswords
-- ============================================================================

-- (No `USE <db>;` — see header note above; mirrors 030/.../046/047.)


-- ============================================================================
-- 1️⃣ Enable the breached-password (HIBP) check
-- ============================================================================
-- Fail-open, k-anonymity-only, already wired into the shared password
-- validation chokepoint — see header note above. Row was seeded 'false' by
-- migration 047; INSERT ... ON DUPLICATE KEY UPDATE both self-heals a
-- missing row AND deliberately overwrites whatever value is currently
-- stored, because turning this check on is the explicit purpose of this
-- migration (unlike 040/046/047's passive `settingKey = settingKey` seeding
-- no-op, which exists only to avoid clobbering an admin's later edit of a
-- setting that migration was not trying to change).
-- ============================================================================
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `isEditable`, `description`, `defaultValue`)
VALUES
    (
        'security.breached_password_check.enabled',
        'true',
        'boolean',
        'security',
        0,
        1,
        'FG-009 master switch for Have I Been Pwned (HIBP) breached-password detection. Enabled by migration 048 (2026-08-04): PwnedPasswords::isBreached() now performs a k-anonymity HIBP range-API check on registration, password change, and password reset. Fail-open on any HIBP outage/timeout/error — never blocks those flows. Set to false via the Settings UI to disable again.',
        'false'
    )
    ON DUPLICATE KEY UPDATE `settingValue` = 'true';


-- ============================================================================
-- 2️⃣ Activate the flexible pricing catalog (migration 046)
-- ============================================================================

-- 2a. tblSubscriptionTiers — Free/Starter/Pro/Business/Enterprise.
--     No-op for Free/Pro/Enterprise (already isActive=1 since 010/019);
--     this is what actually switches Starter and Business live.
UPDATE `tblSubscriptionTiers`
SET `isActive` = 1
WHERE `tierSlug` IN ('free', 'starter', 'pro', 'business', 'enterprise');

-- 2b. tblPlanFeatures — the exact rows migration 046 seeded for those five
--     tiers. `effectiveFrom = '2026-01-01'` is the fixed anchor date 046
--     used precisely so its seeded rows can be targeted unambiguously on
--     a later activation pass like this one (see 046's own header note on
--     why NULL could not be used for a replay-safe INSERT IGNORE).
UPDATE `tblPlanFeatures`
SET `isActive` = 1
WHERE `planScope` = 'global'
  AND `effectiveFrom` = '2026-01-01'
  AND `planID` IN (
      SELECT `tierID` FROM `tblSubscriptionTiers`
      WHERE `tierSlug` IN ('free', 'starter', 'pro', 'business', 'enterprise')
  );

-- 2c. tblCatalogPrices — the exact 8 priceKeys migration 046 seeded
--     (Enterprise is deliberately un-priced in the catalog — see header
--     note — so it has no rows here to activate).
UPDATE `tblCatalogPrices`
SET `isActive` = 1
WHERE `priceKey` IN (
    'free-gbp-month',    'free-gbp-year',
    'starter-gbp-month', 'starter-gbp-year',
    'pro-gbp-month',     'pro-gbp-year',
    'business-gbp-month','business-gbp-year'
);


-- ============================================================================
-- 3️⃣ Entitlement enforcement — flip the master setting ON, shadow-mode
--    (log_only) stays whatever it currently is (already 'true' from 046;
--    deliberately NOT force-written here — see header note: if a human has
--    since turned log_only off on purpose, this migration must not silently
--    put it back).
-- ============================================================================
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `isEditable`, `description`, `defaultValue`)
VALUES
    (
        'entitlements.enforcement_enabled',
        'true',
        'boolean',
        'entitlements',
        0,
        1,
        'FG-011 master switch. Enabled by migration 048 (2026-08-04) — VERIFIED against Entitlements.php that entitlements.log_only correctly keeps this in non-blocking shadow mode (would-be denials logged, never enforced) for as long as log_only stays true. Still requires the tblFeatureToggles row `entitlement_enforcement` to ALSO be isEnabledGlobally=1 (belt-and-braces, deliberately NOT flipped by this migration) before Entitlements ever computes/logs anything at all. See web/private_html/billing/Entitlements.php.',
        'false'
    )
    ON DUPLICATE KEY UPDATE `settingValue` = 'true';


-- ============================================================================
-- ✅ VERIFICATION QUERIES
-- ============================================================================
SELECT 'security.breached_password_check.enabled' AS Status,
    settingValue FROM `tblSettings` WHERE settingKey = 'security.breached_password_check.enabled';

SELECT 'entitlements.enforcement_enabled' AS Status,
    settingValue FROM `tblSettings` WHERE settingKey = 'entitlements.enforcement_enabled';

SELECT 'entitlements.log_only (untouched by this migration)' AS Status,
    settingValue FROM `tblSettings` WHERE settingKey = 'entitlements.log_only';

SELECT 'entitlement_enforcement ops toggle (untouched by this migration — still gates shadow logging off)' AS Status,
    isEnabledGlobally, canPartnersToggle FROM `tblFeatureToggles` WHERE featureKey = 'entitlement_enforcement';

SELECT 'Activated tiers' AS Status, tierSlug, isActive
FROM `tblSubscriptionTiers`
WHERE tierSlug IN ('free', 'starter', 'pro', 'business', 'enterprise')
ORDER BY displayOrder;

SELECT 'Activated plan-feature rows (expect > 0, 0 remaining inactive for these tiers)' AS Status,
    SUM(CASE WHEN pf.isActive = 1 THEN 1 ELSE 0 END) AS activeRows,
    SUM(CASE WHEN pf.isActive = 0 THEN 1 ELSE 0 END) AS stillInactiveRows
FROM `tblPlanFeatures` pf
JOIN `tblSubscriptionTiers` t ON t.tierID = pf.planID AND pf.planScope = 'global'
WHERE t.tierSlug IN ('free', 'starter', 'pro', 'business', 'enterprise')
  AND pf.effectiveFrom = '2026-01-01';

SELECT 'Activated catalog prices (expect 8 active, 0 inactive)' AS Status,
    SUM(CASE WHEN isActive = 1 THEN 1 ELSE 0 END) AS activeRows,
    SUM(CASE WHEN isActive = 0 THEN 1 ELSE 0 END) AS stillInactiveRows
FROM `tblCatalogPrices`
WHERE priceKey IN (
    'free-gbp-month',    'free-gbp-year',
    'starter-gbp-month', 'starter-gbp-year',
    'pro-gbp-month',     'pro-gbp-year',
    'business-gbp-month','business-gbp-year'
);


-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 048_enable_launch_features.sql completed successfully — HIBP check ON, pricing catalog ACTIVE, entitlement enforcement in SHADOW mode pending the separate entitlement_enforcement toggle flip (see header note)' AS Result;
