-- ============================================================================
-- SIGNula Database Migration 046
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- 💳 Migration: Flexible Pricing / Entitlement Catalog (FG-011 — the runtime
--               layer + typed catalog the pricing engine has been missing)
-- 📅 Date:      2026-07-22
-- 🔖 Version:   2.9.0-beta
-- 📝 Description:
--   Adds a typed, queryable feature/plan/price/add-on/discount/override
--   catalog that sits ALONGSIDE the existing tblSubscriptionTiers /
--   tblSubscriptions / tblUsageMetrics-Rates-Records-BillingSummary /
--   tblDiscountCodes machinery (migrations 010/012/018/019/036) — it does
--   NOT replace any of it. The existing tables remain the source of truth
--   for billing/PAYG/dunning; this migration only adds:
--
--     1️⃣ tblFeatureCatalog          — canonical registry of gateable features
--     2️⃣ tblPlanFeatures            — plan ↔ feature assignment (typed
--                                      replacement for the `features`/
--                                      `featureLimits` JSON blobs — those
--                                      JSON columns are KEPT as a fallback)
--     3️⃣ tblCatalogPrices           — unlimited prices per plan AND per
--                                      add-on (currency × interval × model),
--                                      superseding (without dropping)
--                                      monthlyPrice/yearlyPrice
--     4️⃣ tblAddOns                  — à-la-carte product definitions
--     5️⃣ tblAccountAddOns           — an account's purchased add-on instances
--     6️⃣ tblAppliedDiscounts        — per-subscription discount lifecycle
--                                      state (duration countdown / lock),
--                                      layered on top of tblDiscountCodes
--     7️⃣ tblEntitlementOverrides    — per-account bespoke grants/denials
--     8️⃣ tblEntitlementCache        — OPTIONAL resolved-set cache row
--                                      (APCu is the primary cache on shared
--                                      hosting — see Entitlements.php; this
--                                      table is the DB fallback)
--     9️⃣ Guarded ALTER tblDiscountCodes  — +10 columns: duration semantics,
--        lifetime account-locking, categories, volume tiers, stacking
--     🔟 Guarded ALTER tblSubscriptions  — +4 columns: priceID (catalog
--        price link), organizationID (org-owned subscriptions),
--        isGrandfathered + entitlementSnapshot (price/feature freeze)
--
-- 🔗 Design source (READ FIRST — this migration is a literal implementation
--    of it): pricing_schema_design.md (companion doc, §0-§10) and
--    Pricing_Strategy.md (repo root, launch-ready pricing proposal, §3-§6
--    for the seeded tier ladder / feature matrix / price points).
--
-- 🛡️ SAFETY — this migration is PURELY ADDITIVE and ships DORMANT:
--    - Every new table uses `CREATE TABLE IF NOT EXISTS` (idempotent).
--    - Every ALTER on an existing table is information_schema-guarded via
--      the PREPARE/EXECUTE idiom this repo already uses in migrations
--      019_tier_expansion.sql and 036_recurring_billing_foundation.sql
--      (grep-verified: `grep -rl information_schema _database/migrations`).
--    - NO existing row in tblSubscriptionTiers, tblDiscountCodes, or
--      tblSubscriptions is ever UPDATEd or deactivated by this migration —
--      only NEW columns (NULL/DEFAULT-safe for every existing row) and NEW
--      rows are added. The two brand-new tblSubscriptionTiers rows this
--      migration seeds (Starter, Business — the two rungs of the
--      Pricing_Strategy.md §3 ladder that do not already exist under the
--      repo's current Free/Basic/Premium/Pro/Platinum/Enterprise ladder)
--      are seeded `isActive = 0` (built-but-disabled) via `INSERT IGNORE`.
--    - EVERY row seeded by this migration into the NEW catalog tables
--      (tblPlanFeatures, tblCatalogPrices) is `isActive = 0` — including
--      the rows that reference the pre-existing, already-`isActive = 1`
--      Free/Pro/Enterprise tiers. Attaching a dormant, unread row to a
--      live tierID changes nothing: nothing in this codebase queries
--      tblPlanFeatures/tblCatalogPrices yet except the new (also dormant)
--      Entitlements resolver, which is itself gated OFF by
--      `entitlements.enforcement_enabled = 'false'` below and fails OPEN
--      even if that gate is somehow bypassed (see Entitlements.php).
--    - The master switch seeds OFF: `entitlements.enforcement_enabled` =
--      'false', `entitlements.log_only` = 'true', PLUS the
--      `tblFeatureToggles` row `entitlement_enforcement` seeded
--      `isEnabledGlobally = 0`, `canPartnersToggle = 0` (belt-and-braces —
--      §8 of the design doc: BOTH the setting AND the toggle must be on
--      before Entitlements ever enforces anything).
--    - No `USE <db>;`, no stored-procedure delimiter switching (multi_query-safe — mirrors
--      030/031/032/033/034/035/036's header notes), no self-write to
--      tblMigrations (the migration runner owns that bookkeeping).
--
-- 🧭 Schema verification notes (adjustments made vs. the design doc after
--    grepping the REAL schema in `_database/migrations/*.sql` — the design
--    doc's FK targets were correct in every case EXCEPT one discriminator
--    column, noted below):
--
--    - tblSubscriptionTiers.tierID / tblPartnerSubscriptionTiers.tierID
--      (migrations 010/012) — confirmed separate PK namespaces, exactly the
--      "no hard FK, code-enforced scope discriminator" pattern the design
--      doc cites via tblUsageRates.tierType. tblPlanFeatures.planID and
--      tblCatalogPrices.productID (productType='plan') follow the same
--      pattern here — no hard FK, `planScope` selects the target table.
--    - 🔑 tblSubscriptions ALREADY HAS a partner-tier discriminator column
--      — `partnerTierID` (BIGINT UNSIGNED NULL, added by migration
--      012_payment_expansion.sql, no FK — same soft-link precedent as
--      above). The design doc's resolver algorithm (§9) describes deriving
--      a subscription's plan scope but does not name a concrete column;
--      rather than invent a new one, Entitlements.php REUSES this existing
--      column: `partnerTierID IS NOT NULL` ⇒ planScope='partner'/planID=
--      partnerTierID, else planScope='global'/planID=tierID. This is the
--      one adjustment made to match reality — it needed no new column.
--    - tblUsageMetrics/tblUsageRates/tblUsageRecords/tblUsageBillingSummary
--      (migration 018) — column names verified exactly as the design doc
--      assumed (metricID, metricCode, active_users/api_calls seeded rows,
--      tblUsageRecords.userID/metricID/quantity/billingPeriodStart/End).
--    - tblOrganizations (migration 028) — PK `organizationID` confirmed.
--    - tblFeatureToggles.featureID / tblPartnerFeatures — confirmed exact
--      column names (isEnabledGlobally, canPartnersToggle, tblPartnerFeatures.
--      isEnabled/expiresAt). tblFeatureCatalog deliberately does NOT FK to
--      tblFeatureToggles — `featureToggleKey` is a soft VARCHAR link to
--      tblFeatureToggles.featureKey (both tables independently use a PK
--      column literally named `featureID`, so a hard FK here would be
--      pointing at the wrong table by name collision alone; the soft link
--      avoids that trap entirely, per the design doc's own §1 wording).
--    - tblDiscountCodes / tblDiscountCodeAssignments / tblProviderDiscounts
--      (migration 012) — confirmed real column set (partnerID, codeType,
--      allowedCountries, blockedCountries already added by 012's own
--      unguarded ALTER; this migration's guarded ALTER chains its 10 new
--      columns starting `AFTER blockedCountries`).
--    - tblPartners.partnerID / tblUsers.userID — confirmed BIGINT UNSIGNED
--      AUTO_INCREMENT PRIMARY KEY, exactly as assumed.
--    - ⚠️ tblPlanFeatures.effectiveFrom: the design doc's natural unique key
--      is (planScope, planID, featureID, effectiveFrom) with effectiveFrom
--      NULLable ("NULL = immediately"). MySQL/MariaDB unique indexes treat
--      every NULL as DISTINCT, so `INSERT IGNORE` against a NULL
--      effectiveFrom would NOT be idempotent (a re-run would insert
--      duplicate rows). The column itself is left fully NULLable exactly
--      per the design (future application/admin-created rows may still use
--      NULL for "immediately"); this migration's SEEDED rows use a fixed
--      anchor date (`2026-01-01`, safely before this migration's date)
--      instead of NULL purely so `INSERT IGNORE` is genuinely idempotent on
--      replay. Entitlements.php's window check treats
--      `effectiveFrom <= CURDATE()` identically whether NULL or a concrete
--      past date, so this has no behavioural effect.
--
-- @see pricing_schema_design.md (design spec this migration implements)
-- @see Pricing_Strategy.md (repo root — tier ladder / feature matrix / prices)
-- @see web/private_html/billing/Entitlements.php (the fail-open resolver)
-- @see _database/migrations/019_tier_expansion.sql (guarded-ALTER idiom source)
-- @see _database/migrations/036_recurring_billing_foundation.sql (guarded-ALTER idiom source)
-- @see _database/migrations/018_usage_billing.sql (the metering engine this reuses)
-- @see https://dev.mysql.com/doc/refman/8.0/en/alter-table.html
-- @see https://dev.mysql.com/doc/refman/8.0/en/create-table.html
-- ============================================================================

-- (No `USE <db>;` — see header note above.)


-- ============================================================================
-- 1️⃣ tblFeatureCatalog — canonical registry of every gateable capability
-- ============================================================================
-- One row per feature key (unlimited rows). Defines the *type* of value a
-- plan/add-on/override can assign for it. isActive=1 here just means "this
-- is a recognised feature key" — it carries NO enforcement weight by itself;
-- only tblPlanFeatures rows (§2) + the entitlements.enforcement_enabled
-- switch (§11) can ever change observable behaviour.
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblFeatureCatalog` (
    `featureID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT '🔑 Unique feature identifier (PK)',

    `featureKey` VARCHAR(100) NOT NULL
        COMMENT '🏷️ Machine key, dot-notation (e.g. mfa.adaptive, mau.included, sso.enterprise_connections)',

    `featureName` VARCHAR(150) NOT NULL
        COMMENT '📋 Human-readable display name',

    `featureDescription` TEXT DEFAULT NULL
        COMMENT '📝 Longer description for admin/marketing UIs',

    `valueType` ENUM('boolean', 'limit', 'enum', 'json') NOT NULL DEFAULT 'boolean'
        COMMENT '🔀 boolean=on/off; limit=numeric quota (NULL value + isUnlimited=1 ⇒ unlimited); enum=one of allowedValues; json=free-form config',

    `unitLabel` VARCHAR(30) DEFAULT NULL
        COMMENT '📏 For limit types: display unit (e.g. MAU, calls, days, connections)',

    `allowedValues` JSON DEFAULT NULL
        COMMENT '📋 For enum types: JSON array of permitted string values',

    `defaultValue` VARCHAR(255) DEFAULT NULL
        COMMENT '🎯 Value applied when NO plan/override mentions this feature at all (the absolute floor); stringified per valueType',

    `usageMetricID` BIGINT UNSIGNED DEFAULT NULL
        COMMENT '📊 Optional FK → tblUsageMetrics.metricID: links a limit-feature to the metric that consumes it (e.g. mau.included → active_users), enabling Entitlements::withinLimit()',

    `featureToggleKey` VARCHAR(100) DEFAULT NULL
        COMMENT '🔧 Optional SOFT link (by key, not FK) → tblFeatureToggles.featureKey — the ops kill-switch that must ALSO be on. Deliberately not a hard FK: both tables use a PK column literally named featureID, so an FK here would point at the wrong table by name collision alone.',

    `category` VARCHAR(50) DEFAULT NULL
        COMMENT '🗂️ Marketing/admin grouping: auth, mfa, orgs, api, compliance, branding, support, billing, security',

    `tierClass` ENUM('table_stakes', 'premium', 'enterprise') NOT NULL DEFAULT 'premium'
        COMMENT '🎖️ Informational classification only (matches Pricing_Strategy.md §4 table headings) — carries no enforcement meaning',

    `isActive` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '✅ Whether this feature key is currently recognised (soft-delete flag, not an enforcement switch)',

    `sortOrder` INT NOT NULL DEFAULT 0
        COMMENT '🔢 Display ordering for admin/marketing UIs',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`featureID`),
    UNIQUE KEY `uk_feature_key` (`featureKey`),
    INDEX `idx_fc_active` (`isActive`, `sortOrder`),
    INDEX `idx_fc_category` (`category`),

    CONSTRAINT `fk_fc_metric` FOREIGN KEY (`usageMetricID`)
        REFERENCES `tblUsageMetrics` (`metricID`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='FG-011: canonical registry of every gateable SIGNula feature/capability';


-- ============================================================================
-- 2️⃣ tblPlanFeatures — plan ↔ feature assignment with per-assignment value
-- ============================================================================
-- Typed replacement for tblSubscriptionTiers.features/featureLimits JSON
-- blobs. `planID` has NO hard FK — `planScope` selects whether it points at
-- tblSubscriptionTiers.tierID ('global') or tblPartnerSubscriptionTiers.tierID
-- ('partner'), exactly mirroring the existing tblUsageRates.tierType pattern
-- (migration 018) — enforced in code (Entitlements.php / a later CatalogManager),
-- not by the database.
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblPlanFeatures` (
    `planFeatureID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT '🔑 Unique assignment identifier (PK)',

    `planScope` ENUM('global', 'partner') NOT NULL DEFAULT 'global'
        COMMENT '🔀 Discriminator: global → tblSubscriptionTiers.tierID, partner → tblPartnerSubscriptionTiers.tierID',

    `planID` BIGINT UNSIGNED NOT NULL
        COMMENT '🏷️ Tier identifier in the table selected by planScope (no hard FK — see header note)',

    `featureID` BIGINT UNSIGNED NOT NULL
        COMMENT '📊 FK → tblFeatureCatalog — which feature this assignment sets',

    `valueBool` TINYINT(1) DEFAULT NULL
        COMMENT '☑️ Populated when the catalog feature''s valueType = boolean',

    `valueLimit` DECIMAL(20, 4) DEFAULT NULL
        COMMENT '🔢 Populated when valueType = limit. NULL here + isUnlimited=1 ⇒ unlimited',

    `isUnlimited` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '♾️ Explicit unlimited marker (disambiguates a NULL valueLimit from "not set")',

    `valueText` VARCHAR(255) DEFAULT NULL
        COMMENT '🔤 Populated when valueType = enum (must be one of tblFeatureCatalog.allowedValues)',

    `valueJSON` JSON DEFAULT NULL
        COMMENT '📦 Populated when valueType = json (free-form config value)',

    `effectiveFrom` DATE DEFAULT NULL
        COMMENT '📅 NULL = immediately effective; enables scheduling future catalog changes (mirrors tblUsageRates)',

    `effectiveUntil` DATE DEFAULT NULL
        COMMENT '📅 NULL = indefinite',

    `isActive` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '✅ Soft-disable an assignment without deleting its history',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`planFeatureID`),
    UNIQUE KEY `uq_plan_feature_window` (`planScope`, `planID`, `featureID`, `effectiveFrom`),
    INDEX `idx_pf_feature` (`featureID`),
    INDEX `idx_pf_plan` (`planScope`, `planID`, `isActive`),

    CONSTRAINT `fk_pf2_feature` FOREIGN KEY (`featureID`)
        REFERENCES `tblFeatureCatalog` (`featureID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='FG-011: plan/tier ↔ feature assignment (typed replacement for tblSubscriptionTiers JSON blobs — those columns are kept as a fallback)';


-- ============================================================================
-- 3️⃣ tblCatalogPrices — unlimited prices per plan AND per add-on
-- ============================================================================
-- N price rows per sellable thing (currency × interval × pricing model ×
-- active window). Supersedes — WITHOUT DROPPING — tblSubscriptionTiers'
-- monthlyPrice/yearlyPrice columns, which remain a fallback for any plan
-- that has no rows here yet (zero back-fill required).
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblCatalogPrices` (
    `priceID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT '🔑 Unique price identifier (PK)',

    `productType` ENUM('plan', 'addon') NOT NULL DEFAULT 'plan'
        COMMENT '🔀 What is being priced',

    `planScope` ENUM('global', 'partner') DEFAULT NULL
        COMMENT '🔀 Required when productType=plan (selects tblSubscriptionTiers vs tblPartnerSubscriptionTiers); NULL when productType=addon',

    `productID` BIGINT UNSIGNED NOT NULL
        COMMENT '🏷️ Tier table PK (per planScope) when productType=plan, or tblAddOns.addOnID when productType=addon (no hard FK — split target, code-enforced)',

    `priceKey` VARCHAR(100) NOT NULL
        COMMENT '🔖 Stable handle, e.g. pro-gbp-month, pro-gbp-year, mau10k-gbp-month',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT '💱 ISO 4217 currency code',

    `billingInterval` ENUM('month', 'quarter', 'year', 'one_off', 'lifetime') NOT NULL DEFAULT 'month'
        COMMENT '🔁 Maps onto existing tblSubscriptions.billingCycle values',

    `intervalCount` TINYINT UNSIGNED NOT NULL DEFAULT 1
        COMMENT '🔢 e.g. 3 = "every 3 months"',

    `pricingModel` ENUM('flat', 'metered', 'metered_capped') NOT NULL DEFAULT 'flat'
        COMMENT '🔀 flat=fixed unitAmount/interval; metered=PAYG via tblUsageRates (+optional unitAmount base fee); metered_capped=PAYG capped at capAmount (executes via the EXISTING migration-018 hybrid engine)',

    `unitAmount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT '💰 Flat price, or base fee for metered',

    `capAmount` DECIMAL(10, 2) DEFAULT NULL
        COMMENT '🔒 metered_capped only; NULL ⇒ fall back to the plan''s flat price row for the same currency/interval (matches tblUsageBillingSummary.tierFixedPrice semantics)',

    `termDays` INT UNSIGNED DEFAULT NULL
        COMMENT '📅 one_off only: entitlement duration in days; NULL = perpetual',

    `trialDaysOverride` INT UNSIGNED DEFAULT NULL
        COMMENT '🎁 NULL ⇒ inherit the tier''s own trialDays column',

    `providerPriceRefs` JSON DEFAULT NULL
        COMMENT '🔗 External provider price IDs, e.g. {"stripe":"price_x","paypal":"P-x"}',

    `activeFrom` DATETIME DEFAULT NULL
        COMMENT '📅 Availability window start for NEW purchases (existing subs keep their priceID — grandfathering hook)',

    `activeUntil` DATETIME DEFAULT NULL
        COMMENT '📅 Availability window end for NEW purchases',

    `isActive` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '✅ Whether this price is currently sellable',

    `isDefault` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '⭐ The price pre-selected in checkout UI per product+currency (uniqueness enforced in code — MySQL cannot express a partial-unique constraint)',

    `sortOrder` INT NOT NULL DEFAULT 0,

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`priceID`),
    UNIQUE KEY `uk_price_key` (`priceKey`),
    INDEX `idx_cp_product` (`productType`, `planScope`, `productID`, `isActive`),
    INDEX `idx_cp_window` (`isActive`, `activeFrom`, `activeUntil`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='FG-011: unlimited prices per plan/add-on (currency x interval x pricing model), supersedes tblSubscriptionTiers.monthlyPrice/yearlyPrice without dropping them';


-- ============================================================================
-- 4️⃣ tblAddOns — à-la-carte add-on product definitions (unlimited)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblAddOns` (
    `addOnID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT '🔑 Unique add-on identifier (PK)',

    `addOnKey` VARCHAR(100) NOT NULL
        COMMENT '🏷️ Machine key, e.g. addon.mau_10k, addon.sso_connection, addon.compliance_pack',

    `addOnName` VARCHAR(150) NOT NULL
        COMMENT '📋 Display name',

    `addOnDescription` TEXT DEFAULT NULL,

    `addOnType` ENUM('quota_pack', 'feature_unlock', 'connection', 'bundle') NOT NULL
        COMMENT '🔀 quota_pack raises a numeric entitlement; feature_unlock turns a boolean on; connection=countable enterprise connection; bundle=grants several features via grantsJSON',

    `featureID` BIGINT UNSIGNED DEFAULT NULL
        COMMENT '📊 FK → tblFeatureCatalog: which entitlement this add-on augments (quota_pack/feature_unlock/connection)',

    `quantityPerUnit` DECIMAL(20, 4) DEFAULT NULL
        COMMENT '🔢 quota_pack: how much ONE purchased unit adds (e.g. 10000 MAU)',

    `grantsJSON` JSON DEFAULT NULL
        COMMENT '📦 bundle: [{"featureKey":"compliance.pack","valueBool":1}, ...]',

    `isStackable` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '📚 May quantity > 1 be held simultaneously',

    `maxQuantity` INT UNSIGNED DEFAULT NULL
        COMMENT '🔒 NULL = unlimited',

    `eligiblePlansJSON` JSON DEFAULT NULL
        COMMENT '📋 Array of tier slugs allowed to purchase this add-on; NULL = all plans',

    `partnerID` BIGINT UNSIGNED DEFAULT NULL
        COMMENT '🏢 FK → tblPartners: NULL = global catalog add-on, set = partner-private add-on (scope pattern of tblUsageMetrics)',

    `isActive` TINYINT(1) NOT NULL DEFAULT 1,
    `sortOrder` INT NOT NULL DEFAULT 0,

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`addOnID`),
    UNIQUE KEY `uq_addon_key_scope` (`addOnKey`, `partnerID`),
    INDEX `idx_ao_active` (`isActive`, `sortOrder`),

    CONSTRAINT `fk_ao_feature` FOREIGN KEY (`featureID`)
        REFERENCES `tblFeatureCatalog` (`featureID`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_ao_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='FG-011: à-la-carte add-on product definitions, independent of base plan';


-- ============================================================================
-- 5️⃣ tblAccountAddOns — an account's purchased add-on instances
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblAccountAddOns` (
    `accountAddOnID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT '🔑 Unique purchased-instance identifier (PK)',

    `subscriptionID` BIGINT UNSIGNED NOT NULL
        COMMENT '📋 FK → tblSubscriptions — add-ons ride the base subscription''s account, billing anchor, and dunning',

    `addOnID` BIGINT UNSIGNED NOT NULL
        COMMENT '🏷️ FK → tblAddOns',

    `priceID` BIGINT UNSIGNED DEFAULT NULL
        COMMENT '💰 FK → tblCatalogPrices — the price this instance was purchased at',

    `quantity` INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT '📚 Stack count (see tblAddOns.isStackable/maxQuantity)',

    `status` ENUM('active', 'cancelled', 'expired', 'pending') NOT NULL DEFAULT 'pending',

    `startDate` DATE DEFAULT NULL,
    `endDate` DATE DEFAULT NULL
        COMMENT '📅 NULL = recurring until cancelled',

    `cancelledAt` DATETIME DEFAULT NULL,

    `metadata` JSON DEFAULT NULL
        COMMENT '📦 e.g. which SAML connection this instance backs',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`accountAddOnID`),
    INDEX `idx_aao_sub` (`subscriptionID`, `status`),
    INDEX `idx_aao_addon` (`addOnID`),

    CONSTRAINT `fk_aao_subscription` FOREIGN KEY (`subscriptionID`)
        REFERENCES `tblSubscriptions` (`subscriptionID`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_aao_addon` FOREIGN KEY (`addOnID`)
        REFERENCES `tblAddOns` (`addOnID`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_aao_price` FOREIGN KEY (`priceID`)
        REFERENCES `tblCatalogPrices` (`priceID`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='FG-011: an account''s purchased add-on instances (quantity/status/lifecycle)';


-- ============================================================================
-- 6️⃣ tblAppliedDiscounts — per-subscription applied-discount lifecycle state
-- ============================================================================
-- tblDiscountCodes (extended below, §9) says what a discount *is*; this says
-- it is *attached to subscription X* and tracks countdown/lock — today only
-- tblPayments.discountCode (a bare string) records usage, which cannot
-- express "repeating 3 periods, 2 remaining".
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblAppliedDiscounts` (
    `appliedDiscountID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT '🔑 Unique applied-discount identifier (PK)',

    `subscriptionID` BIGINT UNSIGNED NOT NULL
        COMMENT '📋 FK → tblSubscriptions',

    `discountID` BIGINT UNSIGNED NOT NULL
        COMMENT '🎁 FK → tblDiscountCodes',

    `lockedUserID` BIGINT UNSIGNED DEFAULT NULL
        COMMENT '🔒 FK → tblUsers — set when the discount''s isAccountLocked=1 (survives plan changes, dies with the account)',

    `status` ENUM('active', 'exhausted', 'revoked', 'pending_verification') NOT NULL DEFAULT 'active',

    `remainingPeriods` INT UNSIGNED DEFAULT NULL
        COMMENT '⏳ Countdown for duration=repeating; NULL for once/forever',

    `appliedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `exhaustedAt` DATETIME DEFAULT NULL,
    `revokedAt` DATETIME DEFAULT NULL,

    `snapshotJSON` JSON DEFAULT NULL
        COMMENT '📸 Frozen copy of the discount terms at apply time — later admin edits to the code never mutate a live grant',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`appliedDiscountID`),
    UNIQUE KEY `uq_sub_discount` (`subscriptionID`, `discountID`),
    INDEX `idx_ad_status` (`status`),
    INDEX `idx_ad_locked_user` (`lockedUserID`),

    CONSTRAINT `fk_ad_subscription` FOREIGN KEY (`subscriptionID`)
        REFERENCES `tblSubscriptions` (`subscriptionID`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ad_discount` FOREIGN KEY (`discountID`)
        REFERENCES `tblDiscountCodes` (`discountID`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ad_locked_user` FOREIGN KEY (`lockedUserID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='FG-011: per-subscription applied-discount lifecycle (duration countdown / lifetime account-lock)';


-- ============================================================================
-- 7️⃣ tblEntitlementOverrides — per-account bespoke grants/denials
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblEntitlementOverrides` (
    `overrideID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT '🔑 Unique override identifier (PK)',

    `accountType` ENUM('user', 'org', 'partner') NOT NULL
        COMMENT '🔀 Which account-scope table accountID resolves against',

    `accountID` BIGINT UNSIGNED NOT NULL
        COMMENT '🆔 ID in the table matching accountType (no hard FK — polymorphic, tblUsageRates precedent)',

    `featureID` BIGINT UNSIGNED NOT NULL
        COMMENT '📊 FK → tblFeatureCatalog',

    `overrideMode` ENUM('grant', 'deny') NOT NULL DEFAULT 'grant'
        COMMENT '🚦 deny = hard OFF regardless of plan/add-ons (abuse handling)',

    `valueBool` TINYINT(1) DEFAULT NULL,
    `valueLimit` DECIMAL(20, 4) DEFAULT NULL,
    `isUnlimited` TINYINT(1) NOT NULL DEFAULT 0,
    `valueText` VARCHAR(255) DEFAULT NULL,
    `valueJSON` JSON DEFAULT NULL,

    `reason` VARCHAR(255) DEFAULT NULL
        COMMENT '📝 Audit: why this override exists (comp, trial extension, abuse incident, ...)',

    `grantedBy` BIGINT UNSIGNED DEFAULT NULL
        COMMENT '👤 FK → tblUsers — admin who created this override',

    `expiresAt` DATETIME DEFAULT NULL
        COMMENT '📅 NULL = until removed (mirrors tblPartnerFeatures.expiresAt)',

    `isActive` TINYINT(1) NOT NULL DEFAULT 1,

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`overrideID`),
    UNIQUE KEY `uq_override` (`accountType`, `accountID`, `featureID`),
    INDEX `idx_eo_account` (`accountType`, `accountID`, `isActive`),
    INDEX `idx_eo_expires` (`expiresAt`),

    CONSTRAINT `fk_eo_feature` FOREIGN KEY (`featureID`)
        REFERENCES `tblFeatureCatalog` (`featureID`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_eo_granted_by` FOREIGN KEY (`grantedBy`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='FG-011: per-account bespoke entitlement grants/denials (support comps, abuse handling)';


-- ============================================================================
-- 8️⃣ tblEntitlementCache — OPTIONAL resolved-set cache (DB fallback for
--    shared hosting when APCu is unavailable; APCu is the primary cache —
--    see Entitlements.php)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblEntitlementCache` (
    `cacheID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT '🔑 Unique cache row identifier (PK)',

    `accountType` ENUM('user', 'org', 'partner') NOT NULL,
    `accountID` BIGINT UNSIGNED NOT NULL,

    `resolvedJSON` JSON NOT NULL
        COMMENT '📦 {featureKey: value} resolved map at computedAt',

    `cacheEpoch` INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT '🔢 Must equal the entitlements.cache_epoch setting to be considered valid — bump the setting to invalidate every cache row at once (shared-hosting-safe, no cache-server purge needed)',

    `computedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `staleAfter` DATETIME NOT NULL
        COMMENT '⏳ TTL — row is ignored (and eligible for opportunistic deletion) once NOW() passes this',

    PRIMARY KEY (`cacheID`),
    UNIQUE KEY `uq_cache_account` (`accountType`, `accountID`),
    INDEX `idx_ec_stale` (`staleAfter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='FG-011: optional resolved-entitlement-set cache row (DB fallback when APCu is unavailable)';


-- ============================================================================
-- 9️⃣ Guarded ALTER TABLE tblDiscountCodes — +10 columns (discount semantics)
-- ============================================================================
-- Idempotency idiom mirrors 019_tier_expansion.sql /
-- 036_recurring_billing_foundation.sql exactly: an information_schema
-- existence check feeds a PREPARE/EXECUTE conditional ALTER, one column at
-- a time, so a second run of this migration is a silent no-op.
-- Current real column order (verified against 010_webhooks_and_payments.sql
-- + 012_payment_expansion.sql's own ALTER): ... applicablePaymentMethods,
-- allowedCountries, blockedCountries, minAmount, ... — the 10 new columns
-- below are chained AFTER `blockedCountries` (the last column 012 added).
-- ============================================================================
SET @__tbl := 'tblDiscountCodes';

-- 1/10 duration — once (first invoice only) / repeating (first N invoices) / forever
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='duration');
SET @__s := IF(@__c=0, "ALTER TABLE `tblDiscountCodes` ADD COLUMN `duration` ENUM('once','repeating','forever') NOT NULL DEFAULT 'once' COMMENT 'once=first invoice only; repeating=first durationPeriods invoices; forever=every invoice while subscribed' AFTER `blockedCountries`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 2/10 durationPeriods — N for "50% off first 3 months"
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='durationPeriods');
SET @__s := IF(@__c=0, "ALTER TABLE `tblDiscountCodes` ADD COLUMN `durationPeriods` INT UNSIGNED DEFAULT NULL COMMENT 'N billing periods for duration=repeating' AFTER `duration`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 3/10 discountCategory — reporting + policy hooks
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='discountCategory');
SET @__s := IF(@__c=0, "ALTER TABLE `tblDiscountCodes` ADD COLUMN `discountCategory` ENUM('promo','intro','loyalty','volume','nonprofit','education','grandfather','partner','other') NOT NULL DEFAULT 'promo' COMMENT 'Reporting + policy classification' AFTER `durationPeriods`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 4/10 isAccountLocked — lifetime-locked-to-account, non-transferable
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='isAccountLocked');
SET @__s := IF(@__c=0, "ALTER TABLE `tblDiscountCodes` ADD COLUMN `isAccountLocked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Redemption binds to one userID via tblDiscountCodeAssignments, survives plan changes, non-transferable' AFTER `discountCategory`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 5/10 requiresVerification — nonprofit/education gating
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='requiresVerification');
SET @__s := IF(@__c=0, "ALTER TABLE `tblDiscountCodes` ADD COLUMN `requiresVerification` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Admin must verify (e.g. nonprofit/education status) before this code applies' AFTER `isAccountLocked`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 6/10 isStackable — may combine with other CODE discounts
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='isStackable');
SET @__s := IF(@__c=0, "ALTER TABLE `tblDiscountCodes` ADD COLUMN `isStackable` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'May combine with other code discounts (provider discounts governed separately by billing.discounts.stacking_policy)' AFTER `requiresVerification`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 7/10 autoApply — applies without a typed code when conditions match
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='autoApply');
SET @__s := IF(@__c=0, "ALTER TABLE `tblDiscountCodes` ADD COLUMN `autoApply` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Applies automatically (no typed code) when eligibility conditions match — intro campaigns, grandfather migrations' AFTER `isStackable`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 8/10 volumeTiers — [{"minQty":10,"percentOff":5}, ...]
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='volumeTiers');
SET @__s := IF(@__c=0, "ALTER TABLE `tblDiscountCodes` ADD COLUMN `volumeTiers` JSON DEFAULT NULL COMMENT 'Volume discount shape: an array of objects with minQty and percentOff keys, e.g. minQty=10/percentOff=5; quantity basis set by volumeBasis' AFTER `autoApply`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 9/10 volumeBasis — which quantity drives volumeTiers
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='volumeBasis');
SET @__s := IF(@__c=0, "ALTER TABLE `tblDiscountCodes` ADD COLUMN `volumeBasis` VARCHAR(50) DEFAULT NULL COMMENT 'Which quantity drives volumeTiers — a featureKey, or the literal word quantity (seats/add-on count)' AFTER `volumeTiers`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 10/10 applicableAddOns — NULL = plans only
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='applicableAddOns');
SET @__s := IF(@__c=0, "ALTER TABLE `tblDiscountCodes` ADD COLUMN `applicableAddOns` JSON DEFAULT NULL COMMENT 'Add-on keys this code may discount (NULL = plans only, matches applicableTiers convention)' AFTER `volumeBasis`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 📇 Supporting indexes (guarded) for the new reporting/policy columns
SET @__c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND INDEX_NAME='idx_dc_category');
SET @__s := IF(@__c=0, "ALTER TABLE `tblDiscountCodes` ADD INDEX `idx_dc_category` (`discountCategory`)", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

SET @__c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND INDEX_NAME='idx_dc_auto_apply');
SET @__s := IF(@__c=0, "ALTER TABLE `tblDiscountCodes` ADD INDEX `idx_dc_auto_apply` (`autoApply`, `isActive`)", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;


-- ============================================================================
-- 🔟 Guarded ALTER TABLE tblSubscriptions — +4 columns (catalog price link,
--     org ownership, grandfathering)
-- ============================================================================
-- NOTE: this migration does NOT add a new plan-scope discriminator column —
-- tblSubscriptions ALREADY has `partnerTierID` (migration 012), which
-- Entitlements.php reuses for exactly that purpose (see header note above).
-- ============================================================================
SET @__tbl := 'tblSubscriptions';

-- 1/4 priceID — which tblCatalogPrices row this subscription is on
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='priceID');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptions` ADD COLUMN `priceID` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblCatalogPrices. NULL = legacy subscription (billing falls back to the tier monthlyPrice/yearlyPrice + this row''s own amount snapshot) — zero back-fill required' AFTER `updatedAt`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 2/4 organizationID — lets a subscription belong to an org (not just a user/partner)
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='organizationID');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptions` ADD COLUMN `organizationID` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblOrganizations. userID remains the payer/owner of record; this optionally attributes the subscription to an org for org-scoped entitlement resolution' AFTER `priceID`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 3/4 isGrandfathered — entitlement/price freeze marker
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='isGrandfathered');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptions` ADD COLUMN `isGrandfathered` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'When 1, Entitlements resolves entitlementSnapshot instead of live tblPlanFeatures — freezes this subscriber''s feature set across future catalog changes' AFTER `organizationID`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 4/4 entitlementSnapshot — the frozen feature set
SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='entitlementSnapshot');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptions` ADD COLUMN `entitlementSnapshot` JSON DEFAULT NULL COMMENT 'Frozen {featureKey: value} map used when isGrandfathered=1' AFTER `isGrandfathered`", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 🔗 Foreign keys for the two new FK-bearing columns (guarded)
SET @__c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND CONSTRAINT_NAME='fk_sub_price');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptions` ADD CONSTRAINT `fk_sub_price` FOREIGN KEY (`priceID`) REFERENCES `tblCatalogPrices` (`priceID`) ON DELETE SET NULL ON UPDATE CASCADE", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

SET @__c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND CONSTRAINT_NAME='fk_sub_organization');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptions` ADD CONSTRAINT `fk_sub_organization` FOREIGN KEY (`organizationID`) REFERENCES `tblOrganizations` (`organizationID`) ON DELETE SET NULL ON UPDATE CASCADE", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 📇 Supporting indexes (guarded)
SET @__c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND INDEX_NAME='idx_sub_price');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptions` ADD INDEX `idx_sub_price` (`priceID`)", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

SET @__c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND INDEX_NAME='idx_sub_org');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptions` ADD INDEX `idx_sub_org` (`organizationID`)", 'DO 0');
PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;


-- ============================================================================
-- 1️⃣1️⃣ tblSettings — the dormant-shipping master switches (§8 of the design)
-- ============================================================================
-- entitlements.enforcement_enabled=false + the tblFeatureToggles row below
-- (BOTH must be true) is the only way Entitlements ever enforces anything.
-- `INSERT IGNORE` against tblSettings' UNIQUE settingKey column — mirrors
-- 034_trusted_proxies_setting.sql's seeding pattern; never clobbers a value
-- an admin has since edited via the Settings UI.
-- ============================================================================
INSERT IGNORE INTO `tblSettings`
    (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `isEditable`, `description`, `defaultValue`)
VALUES
    (
        'entitlements.enforcement_enabled',
        'false',
        'boolean',
        'entitlements',
        0,
        1,
        'FG-011 master switch. When false (the default — DO NOT flip this in this migration or any automated process), Entitlements::resolveAll()/has()/limit()/withinLimit() ALL fail OPEN (allow everything) without even querying the catalog tables. Flipping to true additionally requires the tblFeatureToggles row entitlement_enforcement to also be isEnabledGlobally=1 (belt-and-braces) before any check can actually deny. See web/private_html/billing/Entitlements.php.',
        'false'
    ),
    (
        'entitlements.log_only',
        'true',
        'boolean',
        'entitlements',
        0,
        1,
        'When enforcement is first switched on, keep this true for a "shadow week": would-be denials are logged to tblActivityLog (activityType=entitlement_would_deny) but NOT actually enforced (has()/withinLimit() still return true). Set to false only after reviewing the shadow-week log.',
        'true'
    ),
    (
        'entitlements.cache_ttl',
        '300',
        'integer',
        'entitlements',
        0,
        1,
        'TTL in seconds for a resolved entitlement set (APCu primary cache, tblEntitlementCache DB fallback on shared hosting without APCu).',
        '300'
    ),
    (
        'entitlements.cache_epoch',
        '1',
        'integer',
        'entitlements',
        0,
        1,
        'Bump this integer to invalidate every cached resolved-entitlement-set at once (both APCu-key-prefixed and tblEntitlementCache rows) after any catalog write — shared-hosting-safe, no cache-server purge command needed.',
        '1'
    ),
    (
        'billing.discounts.stacking_policy',
        'single_code_plus_provider',
        'string',
        'billing',
        0,
        1,
        'Discount stacking policy: at most one CODE discount + one PROVIDER discount (tblProviderDiscounts, e.g. crypto %) per invoice by default. Account-locked lifetime discounts stack with provider discounts but not with other codes. Best-for-customer wins on conflict. Purely advisory until the invoicing engine reads it.',
        'single_code_plus_provider'
    );


-- ============================================================================
-- 1️⃣2️⃣ tblFeatureToggles — the ops kill-switch companion to the setting above
-- ============================================================================
-- `ON DUPLICATE KEY UPDATE featureKey = VALUES(featureKey)` is the exact
-- idempotency idiom already used for tblFeatureToggles seeds in migrations
-- 012_payment_expansion.sql / 013_kofi_patreon_providers.sql.
-- ============================================================================
INSERT INTO `tblFeatureToggles`
    (`featureKey`, `featureName`, `featureDescription`, `isEnabledGlobally`, `category`, `requiresMigration`, `affectsAPI`, `canPartnersToggle`)
VALUES
    (
        'entitlement_enforcement',
        'Flexible Pricing — Entitlement Enforcement',
        'FG-011 ops kill-switch. Must be TRUE (globally, or per-partner via tblPartnerFeatures once canPartnersToggle changes) AND entitlements.enforcement_enabled must be true before Entitlements ever denies anything. Seeded OFF — this migration changes zero current behaviour.',
        0,
        'billing',
        '046',
        1,
        0
    )
ON DUPLICATE KEY UPDATE `featureKey` = VALUES(`featureKey`);


-- ============================================================================
-- 1️⃣3️⃣ SEED — tblFeatureCatalog (representative feature set, Pricing_Strategy.md §4)
-- ============================================================================
-- These are just TYPE DEFINITIONS — inert until a tblPlanFeatures row
-- references them AND entitlements.enforcement_enabled is flipped on. Not an
-- exhaustive transcription of every §4 row (that is future admin-UI/catalog-
-- management work per the design doc's §10 "NOT in scope" list) — a
-- representative set spanning every valueType and every tierClass.
-- ============================================================================
INSERT INTO `tblFeatureCatalog`
    (`featureKey`, `featureName`, `featureDescription`, `valueType`, `unitLabel`, `allowedValues`, `defaultValue`, `usageMetricID`, `featureToggleKey`, `category`, `tierClass`, `isActive`, `sortOrder`)
VALUES
    -- 🔐 Table stakes — NEVER paywalled at any tier (Pricing_Strategy.md §1 point 3 / §4)
    ('auth.core', 'Email/password + passwordless email link/OTP', 'Core authentication methods available to every account regardless of plan', 'boolean', NULL, NULL, '1', NULL, NULL, 'auth', 'table_stakes', 1, 10),
    ('mfa.all', 'All MFA methods (TOTP, backup codes, email OTP)', 'Multi-factor authentication is never a paid feature — see Auth0''s widely-criticised "security tax"', 'boolean', NULL, NULL, '1', NULL, 'mfa', 'mfa', 'table_stakes', 1, 20),
    ('auth.passkeys', 'Passkeys / WebAuthn', 'FIDO2/WebAuthn passwordless login, free at every tier', 'boolean', NULL, NULL, '1', NULL, 'webauthn', 'auth', 'table_stakes', 1, 30),
    ('security.hibp', 'Breached-password protection', 'Have-I-Been-Pwned-style breached credential checking', 'boolean', NULL, NULL, '1', NULL, NULL, 'security', 'table_stakes', 1, 40),
    ('oauth.link_providers', 'Linkable social/OAuth providers', 'How many third-party account systems (Google/Microsoft/Apple/...) a user may link', 'limit', 'providers', NULL, '3', NULL, 'oauth_integration', 'auth', 'table_stakes', 1, 50),
    ('apps.max', 'Registered apps / OAuth clients', 'Maximum number of first/third-party apps this account may register against SIGNula', 'limit', 'apps', NULL, '2', NULL, NULL, 'api', 'table_stakes', 1, 60),
    ('mau.included', 'Included Monthly Active Users', 'MAU allowance included in the plan before overage/hard-stop applies', 'limit', 'MAU', NULL, '5000', (SELECT metricID FROM tblUsageMetrics WHERE metricCode = 'active_users' AND partnerID IS NULL LIMIT 1), NULL, 'billing', 'table_stakes', 1, 70),
    ('api.rate_limit', 'API rate limit', 'Requests-per-minute ceiling', 'limit', 'req/min', NULL, '60', NULL, 'rate_limiting', 'api', 'table_stakes', 1, 80),
    ('api.calls_month', 'API calls per month', 'Monthly API call allowance', 'limit', 'calls', NULL, '50000', (SELECT metricID FROM tblUsageMetrics WHERE metricCode = 'api_calls' AND partnerID IS NULL LIMIT 1), NULL, 'api', 'table_stakes', 1, 90),
    ('logs.retention_days', 'Activity-log retention', 'How many days of tblActivityLog history are retained/exposed to the account', 'limit', 'days', NULL, '30', NULL, NULL, 'compliance', 'table_stakes', 1, 100),
    ('webhooks.max_endpoints', 'Webhook endpoints', 'Maximum tblWebhookEndpoints rows this account may register', 'limit', 'endpoints', NULL, '1', NULL, NULL, 'api', 'table_stakes', 1, 110),
    ('support.level', 'Support level', 'Which support channel this account is entitled to', 'enum', NULL, '["community","email","priority_email","priority_phone","dedicated"]', 'community', NULL, NULL, 'support', 'table_stakes', 1, 120),

    -- 💎 Premium — the Starter/Pro upsell
    ('branding.custom', 'Custom branding on hosted login/emails', 'Replace SIGNula default styling with the account''s own branding', 'boolean', NULL, NULL, '0', NULL, NULL, 'branding', 'premium', 1, 200),
    ('branding.remove_badge', 'Remove "Secured by SIGNula" badge', 'Hide the default attribution badge on hosted pages', 'boolean', NULL, NULL, '0', NULL, NULL, 'branding', 'premium', 1, 210),
    ('orgs.enabled', 'Organizations / teams', 'Whether the account may create tblOrganizations', 'boolean', NULL, NULL, '0', NULL, NULL, 'orgs', 'premium', 1, 220),
    ('orgs.max', 'Maximum organizations', 'How many organizations this account may own', 'limit', 'organizations', NULL, '0', NULL, NULL, 'orgs', 'premium', 1, 230),
    ('orgs.seats', 'Seats per organization', 'Maximum members per organization', 'limit', 'seats', NULL, '0', NULL, NULL, 'orgs', 'premium', 1, 240),
    ('authz.rbac', 'Role-based access control', 'Fine-grained permission roles within an organization', 'boolean', NULL, NULL, '0', NULL, NULL, 'auth', 'premium', 1, 250),

    -- 🏢 Enterprise-grade
    ('sso.enterprise_connections', 'Enterprise SSO connections', 'Inbound SAML/OIDC connections for the customer''s own IdP', 'limit', 'connections', NULL, '0', NULL, NULL, 'auth', 'enterprise', 1, 300),
    ('scim.enabled', 'SCIM provisioning', 'Directory-sync user provisioning/deprovisioning', 'boolean', NULL, NULL, '0', NULL, NULL, 'auth', 'enterprise', 1, 310),
    ('compliance.pack', 'Compliance pack', 'DSAR queue, consent tooling, RoPA, regime resolver (beyond the self-serve baseline every account gets)', 'boolean', NULL, NULL, '0', NULL, NULL, 'compliance', 'enterprise', 1, 320),
    ('logs.siem_stream', 'SIEM / audit-log streaming', 'Streams tblActivityLog / tblErrorLog to an external SIEM', 'boolean', NULL, NULL, '0', NULL, NULL, 'compliance', 'enterprise', 1, 330),
    ('mfa.adaptive', 'Adaptive / risk-based MFA', 'Step-up authentication triggered by risk signals', 'boolean', NULL, NULL, '0', NULL, 'mfa', 'mfa', 'enterprise', 1, 340),
    ('sla.level', 'Service level agreement', 'Contracted uptime commitment', 'enum', NULL, '["none","99.9","99.95","custom"]', 'none', NULL, NULL, 'support', 'enterprise', 1, 350),
    ('branding.whitelabel', 'White-label / full custom domain', 'Remove all SIGNula branding and serve from the account''s own domain', 'boolean', NULL, NULL, '0', NULL, NULL, 'branding', 'enterprise', 1, 360),
    ('data.residency', 'Data residency options', 'Contracted data-storage-region guarantees', 'boolean', NULL, NULL, '0', NULL, NULL, 'compliance', 'enterprise', 1, 370),
    ('legal.custom', 'Custom contracts / DPA / security review', 'Bespoke legal paperwork rather than the standard ToS/DPA', 'boolean', NULL, NULL, '0', NULL, NULL, 'compliance', 'enterprise', 1, 380),
    ('support.dedicated', 'Dedicated account manager', 'A named support contact rather than a shared queue', 'boolean', NULL, NULL, '0', NULL, NULL, 'support', 'enterprise', 1, 390)
ON DUPLICATE KEY UPDATE `featureKey` = VALUES(`featureKey`);


-- ============================================================================
-- 1️⃣4️⃣ SEED — new tblSubscriptionTiers rows: Starter + Business
-- ============================================================================
-- The repo's existing ladder (Free/Basic/Premium/Pro/Platinum/Enterprise,
-- migrations 010+019) is NOT touched — no existing row is updated,
-- deactivated, or renamed. Pricing_Strategy.md §3's rationalised 5-tier
-- ladder (Free/Starter/Pro/Business/Enterprise) reuses the EXISTING Free,
-- Pro, and Enterprise slugs by reference (see §15/§16 below) and only
-- ADDS the two rungs that do not already exist under those slugs: Starter
-- and Business. Both seeded isActive=0 (built-but-disabled) per the safety
-- constraint. displayOrder uses 10/20 (outside the existing 1-6 range) so
-- activating them later needs no renumbering of the live ladder.
-- `INSERT IGNORE` on the existing `uk_tier_slug` unique key — idempotent.
-- ============================================================================
INSERT IGNORE INTO `tblSubscriptionTiers`
    (`tierName`, `tierSlug`, `tierDescription`, `monthlyPrice`, `yearlyPrice`, `currency`, `features`, `featureLimits`, `teamMemberLimit`, `apiCallsPerMonth`, `storageGB`, `displayOrder`, `isActive`, `isDefault`, `trialDays`, `badge`, `badgeColor`)
VALUES
    (
        'Starter',
        'starter',
        'Production polish for indie developers and small apps going live: custom branding, webhooks, email support',
        19.00,
        190.00,
        'GBP',
        '["Everything in Free","Custom branding","Webhook integrations","Email support","10,000 MAU included"]',
        '{"api_calls": 250000, "team_members": null, "storage_gb": null}',
        NULL,
        250000,
        NULL,
        10,
        0,
        0,
        14,
        NULL,
        NULL
    ),
    (
        'Business',
        'business',
        'Scale-ups with compliance/enterprise-customer needs: enterprise SSO, SCIM, compliance pack, SIEM streaming, 99.9% SLA',
        149.00,
        1490.00,
        'GBP',
        '["Everything in Pro","Enterprise SSO (5 connections included)","SCIM provisioning","Compliance pack","SIEM/audit-log streaming","99.9% SLA","100,000 MAU included"]',
        '{"api_calls": 5000000, "team_members": 200, "storage_gb": 500}',
        200,
        5000000,
        500.00,
        20,
        0,
        0,
        30,
        'Best Value',
        'success'
    );


-- ============================================================================
-- 1️⃣5️⃣ SEED — tblPlanFeatures (Free / Starter / Pro / Business / Enterprise)
-- ============================================================================
-- Free/Pro/Enterprise resolve to the REPO'S EXISTING tierIDs (tierSlug
-- lookups — never hardcoded IDs); Starter/Business resolve to the rows just
-- seeded above. EVERY row here is isActive=0 (dormant) regardless of
-- whether the underlying tier itself is already isActive=1 — see the
-- SAFETY note at the top of this file. effectiveFrom uses the fixed
-- 2026-01-01 anchor (see the "schema verification notes" header section)
-- instead of NULL so `INSERT IGNORE` is genuinely idempotent on replay.
--
-- One INSERT...SELECT per tier: a literal derived table of
-- (featureKey, valueBool, valueLimit, isUnlimited, valueText) tuples is
-- joined against tblFeatureCatalog by key and cross-joined against the
-- resolved tierID — far more maintainable than one subquery-laden VALUES
-- row per feature.
-- ============================================================================

-- 🆓 FREE — table-stakes only; every premium/enterprise cell is explicitly OFF
INSERT IGNORE INTO `tblPlanFeatures`
    (`planScope`, `planID`, `featureID`, `valueBool`, `valueLimit`, `isUnlimited`, `valueText`, `valueJSON`, `effectiveFrom`, `effectiveUntil`, `isActive`)
SELECT 'global', t.tierID, fc.featureID, x.valueBool, x.valueLimit, x.isUnlimited, x.valueText, NULL, '2026-01-01', NULL, 0
FROM (
    SELECT 'auth.core' AS featureKey, 1 AS valueBool, NULL AS valueLimit, 0 AS isUnlimited, NULL AS valueText
    UNION ALL SELECT 'mfa.all',               1,    NULL,  0, NULL
    UNION ALL SELECT 'auth.passkeys',         1,    NULL,  0, NULL
    UNION ALL SELECT 'security.hibp',         1,    NULL,  0, NULL
    UNION ALL SELECT 'oauth.link_providers',  NULL, 3,     0, NULL
    UNION ALL SELECT 'apps.max',              NULL, 2,     0, NULL
    UNION ALL SELECT 'mau.included',          NULL, 5000,  0, NULL
    UNION ALL SELECT 'api.rate_limit',        NULL, 60,    0, NULL
    UNION ALL SELECT 'api.calls_month',       NULL, 50000, 0, NULL
    UNION ALL SELECT 'logs.retention_days',   NULL, 30,    0, NULL
    UNION ALL SELECT 'webhooks.max_endpoints',NULL, 1,     0, NULL
    UNION ALL SELECT 'support.level',         NULL, NULL,  0, 'community'
    UNION ALL SELECT 'branding.custom',       0,    NULL,  0, NULL
    UNION ALL SELECT 'orgs.enabled',          0,    NULL,  0, NULL
    UNION ALL SELECT 'authz.rbac',            0,    NULL,  0, NULL
) AS x
JOIN `tblFeatureCatalog` fc ON fc.featureKey = x.featureKey
CROSS JOIN (SELECT tierID FROM `tblSubscriptionTiers` WHERE tierSlug = 'free' LIMIT 1) AS t;

-- 🚀 STARTER
INSERT IGNORE INTO `tblPlanFeatures`
    (`planScope`, `planID`, `featureID`, `valueBool`, `valueLimit`, `isUnlimited`, `valueText`, `valueJSON`, `effectiveFrom`, `effectiveUntil`, `isActive`)
SELECT 'global', t.tierID, fc.featureID, x.valueBool, x.valueLimit, x.isUnlimited, x.valueText, NULL, '2026-01-01', NULL, 0
FROM (
    SELECT 'auth.core' AS featureKey, 1 AS valueBool, NULL AS valueLimit, 0 AS isUnlimited, NULL AS valueText
    UNION ALL SELECT 'mfa.all',               1,    NULL,   0, NULL
    UNION ALL SELECT 'auth.passkeys',         1,    NULL,   0, NULL
    UNION ALL SELECT 'security.hibp',         1,    NULL,   0, NULL
    UNION ALL SELECT 'oauth.link_providers',  NULL, NULL,   1, NULL
    UNION ALL SELECT 'apps.max',              NULL, 5,      0, NULL
    UNION ALL SELECT 'mau.included',          NULL, 10000,  0, NULL
    UNION ALL SELECT 'api.rate_limit',        NULL, 300,    0, NULL
    UNION ALL SELECT 'api.calls_month',       NULL, 250000, 0, NULL
    UNION ALL SELECT 'logs.retention_days',   NULL, 90,     0, NULL
    UNION ALL SELECT 'webhooks.max_endpoints',NULL, 5,      0, NULL
    UNION ALL SELECT 'support.level',         NULL, NULL,   0, 'email'
    UNION ALL SELECT 'branding.custom',       1,    NULL,   0, NULL
    UNION ALL SELECT 'branding.remove_badge', 0,    NULL,   0, NULL
    UNION ALL SELECT 'orgs.enabled',          1,    NULL,   0, NULL
    UNION ALL SELECT 'orgs.max',              NULL, 1,      0, NULL
    UNION ALL SELECT 'orgs.seats',            NULL, 5,      0, NULL
    UNION ALL SELECT 'authz.rbac',            0,    NULL,   0, NULL
) AS x
JOIN `tblFeatureCatalog` fc ON fc.featureKey = x.featureKey
CROSS JOIN (SELECT tierID FROM `tblSubscriptionTiers` WHERE tierSlug = 'starter' LIMIT 1) AS t;

-- ⭐ PRO — resolves to the repo's EXISTING 'pro' tier (migration 019), already isActive=1
INSERT IGNORE INTO `tblPlanFeatures`
    (`planScope`, `planID`, `featureID`, `valueBool`, `valueLimit`, `isUnlimited`, `valueText`, `valueJSON`, `effectiveFrom`, `effectiveUntil`, `isActive`)
SELECT 'global', t.tierID, fc.featureID, x.valueBool, x.valueLimit, x.isUnlimited, x.valueText, NULL, '2026-01-01', NULL, 0
FROM (
    SELECT 'auth.core' AS featureKey, 1 AS valueBool, NULL AS valueLimit, 0 AS isUnlimited, NULL AS valueText
    UNION ALL SELECT 'mfa.all',                    1,    NULL,    0, NULL
    UNION ALL SELECT 'auth.passkeys',              1,    NULL,    0, NULL
    UNION ALL SELECT 'security.hibp',              1,    NULL,    0, NULL
    UNION ALL SELECT 'oauth.link_providers',       NULL, NULL,    1, NULL
    UNION ALL SELECT 'apps.max',                   NULL, 20,      0, NULL
    UNION ALL SELECT 'mau.included',               NULL, 25000,   0, NULL
    UNION ALL SELECT 'api.rate_limit',             NULL, 1000,    0, NULL
    UNION ALL SELECT 'api.calls_month',            NULL, 1000000, 0, NULL
    UNION ALL SELECT 'logs.retention_days',        NULL, 365,     0, NULL
    UNION ALL SELECT 'webhooks.max_endpoints',     NULL, 20,      0, NULL
    UNION ALL SELECT 'support.level',              NULL, NULL,    0, 'priority_email'
    UNION ALL SELECT 'branding.custom',            1,    NULL,    0, NULL
    UNION ALL SELECT 'branding.remove_badge',      1,    NULL,    0, NULL
    UNION ALL SELECT 'orgs.enabled',               1,    NULL,    0, NULL
    UNION ALL SELECT 'orgs.max',                   NULL, 10,      0, NULL
    UNION ALL SELECT 'orgs.seats',                 NULL, 25,      0, NULL
    UNION ALL SELECT 'authz.rbac',                 1,    NULL,    0, NULL
    UNION ALL SELECT 'mau.overage',                1,    NULL,    0, NULL
    UNION ALL SELECT 'sso.enterprise_connections', NULL, 0,       0, NULL
    UNION ALL SELECT 'scim.enabled',               0,    NULL,    0, NULL
    UNION ALL SELECT 'compliance.pack',            0,    NULL,    0, NULL
    UNION ALL SELECT 'mfa.adaptive',               1,    NULL,    0, NULL
) AS x
JOIN `tblFeatureCatalog` fc ON fc.featureKey = x.featureKey
CROSS JOIN (SELECT tierID FROM `tblSubscriptionTiers` WHERE tierSlug = 'pro' LIMIT 1) AS t;

-- 🏢 BUSINESS
INSERT IGNORE INTO `tblPlanFeatures`
    (`planScope`, `planID`, `featureID`, `valueBool`, `valueLimit`, `isUnlimited`, `valueText`, `valueJSON`, `effectiveFrom`, `effectiveUntil`, `isActive`)
SELECT 'global', t.tierID, fc.featureID, x.valueBool, x.valueLimit, x.isUnlimited, x.valueText, NULL, '2026-01-01', NULL, 0
FROM (
    SELECT 'auth.core' AS featureKey, 1 AS valueBool, NULL AS valueLimit, 0 AS isUnlimited, NULL AS valueText
    UNION ALL SELECT 'mfa.all',                    1,    NULL,     0, NULL
    UNION ALL SELECT 'auth.passkeys',              1,    NULL,     0, NULL
    UNION ALL SELECT 'security.hibp',              1,    NULL,     0, NULL
    UNION ALL SELECT 'oauth.link_providers',       NULL, NULL,     1, NULL
    UNION ALL SELECT 'apps.max',                   NULL, 100,      0, NULL
    UNION ALL SELECT 'mau.included',               NULL, 100000,   0, NULL
    UNION ALL SELECT 'api.rate_limit',             NULL, 5000,     0, NULL
    UNION ALL SELECT 'api.calls_month',            NULL, 5000000, 0, NULL
    UNION ALL SELECT 'logs.retention_days',        NULL, 730,      0, NULL
    UNION ALL SELECT 'webhooks.max_endpoints',     NULL, 100,      0, NULL
    UNION ALL SELECT 'support.level',              NULL, NULL,     0, 'priority_phone'
    UNION ALL SELECT 'branding.custom',            1,    NULL,     0, NULL
    UNION ALL SELECT 'branding.remove_badge',      1,    NULL,     0, NULL
    UNION ALL SELECT 'orgs.enabled',               1,    NULL,     0, NULL
    UNION ALL SELECT 'orgs.max',                   NULL, 100,      0, NULL
    UNION ALL SELECT 'orgs.seats',                 NULL, 200,      0, NULL
    UNION ALL SELECT 'authz.rbac',                 1,    NULL,     0, NULL
    UNION ALL SELECT 'mau.overage',                1,    NULL,     0, NULL
    UNION ALL SELECT 'sso.enterprise_connections', NULL, 5,        0, NULL
    UNION ALL SELECT 'scim.enabled',               1,    NULL,     0, NULL
    UNION ALL SELECT 'compliance.pack',            1,    NULL,     0, NULL
    UNION ALL SELECT 'logs.siem_stream',           1,    NULL,     0, NULL
    UNION ALL SELECT 'mfa.adaptive',               1,    NULL,     0, NULL
    UNION ALL SELECT 'sla.level',                  NULL, NULL,     0, '99.9'
    UNION ALL SELECT 'branding.whitelabel',        0,    NULL,     0, NULL
) AS x
JOIN `tblFeatureCatalog` fc ON fc.featureKey = x.featureKey
CROSS JOIN (SELECT tierID FROM `tblSubscriptionTiers` WHERE tierSlug = 'business' LIMIT 1) AS t;

-- 🏛️ ENTERPRISE — resolves to the repo's EXISTING 'enterprise' tier (migration 010)
INSERT IGNORE INTO `tblPlanFeatures`
    (`planScope`, `planID`, `featureID`, `valueBool`, `valueLimit`, `isUnlimited`, `valueText`, `valueJSON`, `effectiveFrom`, `effectiveUntil`, `isActive`)
SELECT 'global', t.tierID, fc.featureID, x.valueBool, x.valueLimit, x.isUnlimited, x.valueText, NULL, '2026-01-01', NULL, 0
FROM (
    SELECT 'auth.core' AS featureKey, 1 AS valueBool, NULL AS valueLimit, 1 AS isUnlimited, NULL AS valueText
    UNION ALL SELECT 'mfa.all',                    1,    NULL, 0, NULL
    UNION ALL SELECT 'auth.passkeys',              1,    NULL, 0, NULL
    UNION ALL SELECT 'security.hibp',              1,    NULL, 0, NULL
    UNION ALL SELECT 'oauth.link_providers',       NULL, NULL, 1, NULL
    UNION ALL SELECT 'apps.max',                   NULL, NULL, 1, NULL
    UNION ALL SELECT 'mau.included',               NULL, NULL, 1, NULL
    -- ℹ️ api.rate_limit / logs.retention_days are "Custom" (negotiated) at
    -- Enterprise per Pricing_Strategy.md §4 — represented here as
    -- isUnlimited=1 (no fixed platform cap), the pragmatic fit for a
    -- limit-type feature; see migration header note.
    UNION ALL SELECT 'api.rate_limit',             NULL, NULL, 1, NULL
    UNION ALL SELECT 'api.calls_month',            NULL, NULL, 1, NULL
    UNION ALL SELECT 'logs.retention_days',        NULL, NULL, 1, NULL
    UNION ALL SELECT 'webhooks.max_endpoints',     NULL, NULL, 1, NULL
    UNION ALL SELECT 'support.level',              NULL, NULL, 0, 'dedicated'
    UNION ALL SELECT 'branding.custom',            1,    NULL, 0, NULL
    UNION ALL SELECT 'branding.remove_badge',      1,    NULL, 0, NULL
    UNION ALL SELECT 'orgs.enabled',               1,    NULL, 0, NULL
    UNION ALL SELECT 'orgs.max',                   NULL, NULL, 1, NULL
    UNION ALL SELECT 'orgs.seats',                 NULL, NULL, 1, NULL
    UNION ALL SELECT 'authz.rbac',                 1,    NULL, 0, NULL
    UNION ALL SELECT 'mau.overage',                1,    NULL, 0, NULL
    UNION ALL SELECT 'sso.enterprise_connections', NULL, NULL, 1, NULL
    UNION ALL SELECT 'scim.enabled',               1,    NULL, 0, NULL
    UNION ALL SELECT 'compliance.pack',            1,    NULL, 0, NULL
    UNION ALL SELECT 'logs.siem_stream',           1,    NULL, 0, NULL
    UNION ALL SELECT 'mfa.adaptive',               1,    NULL, 0, NULL
    UNION ALL SELECT 'sla.level',                  NULL, NULL, 0, 'custom'
    UNION ALL SELECT 'branding.whitelabel',        1,    NULL, 0, NULL
    UNION ALL SELECT 'data.residency',             1,    NULL, 0, NULL
    UNION ALL SELECT 'legal.custom',               1,    NULL, 0, NULL
    UNION ALL SELECT 'support.dedicated',          1,    NULL, 0, NULL
) AS x
JOIN `tblFeatureCatalog` fc ON fc.featureKey = x.featureKey
CROSS JOIN (SELECT tierID FROM `tblSubscriptionTiers` WHERE tierSlug = 'enterprise' LIMIT 1) AS t;


-- ============================================================================
-- 1️⃣6️⃣ SEED — tblCatalogPrices (Free / Starter / Pro / Business, GBP)
-- ============================================================================
-- Enterprise is deliberately NOT priced here — Pricing_Strategy.md §3 lists
-- it as "Contact us" (sales-led, custom contract), not a fixed catalog
-- price. All rows isActive=0 (dormant). `INSERT IGNORE` on `uk_price_key`
-- (a plain NOT NULL VARCHAR — no NULL-uniqueness caveat here).
-- ============================================================================
INSERT IGNORE INTO `tblCatalogPrices`
    (`productType`, `planScope`, `productID`, `priceKey`, `currency`, `billingInterval`, `intervalCount`, `pricingModel`, `unitAmount`, `capAmount`, `termDays`, `trialDaysOverride`, `providerPriceRefs`, `activeFrom`, `activeUntil`, `isActive`, `isDefault`, `sortOrder`)
SELECT 'plan', 'global', tierID, 'free-gbp-month', 'GBP', 'month', 1, 'flat', 0.00, NULL, NULL, NULL, NULL, NULL, NULL, 0, 1, 10
FROM `tblSubscriptionTiers` WHERE tierSlug = 'free' LIMIT 1;

INSERT IGNORE INTO `tblCatalogPrices`
    (`productType`, `planScope`, `productID`, `priceKey`, `currency`, `billingInterval`, `intervalCount`, `pricingModel`, `unitAmount`, `capAmount`, `termDays`, `trialDaysOverride`, `providerPriceRefs`, `activeFrom`, `activeUntil`, `isActive`, `isDefault`, `sortOrder`)
SELECT 'plan', 'global', tierID, 'free-gbp-year', 'GBP', 'year', 1, 'flat', 0.00, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 11
FROM `tblSubscriptionTiers` WHERE tierSlug = 'free' LIMIT 1;

INSERT IGNORE INTO `tblCatalogPrices`
    (`productType`, `planScope`, `productID`, `priceKey`, `currency`, `billingInterval`, `intervalCount`, `pricingModel`, `unitAmount`, `capAmount`, `termDays`, `trialDaysOverride`, `providerPriceRefs`, `activeFrom`, `activeUntil`, `isActive`, `isDefault`, `sortOrder`)
SELECT 'plan', 'global', tierID, 'starter-gbp-month', 'GBP', 'month', 1, 'flat', 19.00, NULL, NULL, NULL, NULL, NULL, NULL, 0, 1, 20
FROM `tblSubscriptionTiers` WHERE tierSlug = 'starter' LIMIT 1;

INSERT IGNORE INTO `tblCatalogPrices`
    (`productType`, `planScope`, `productID`, `priceKey`, `currency`, `billingInterval`, `intervalCount`, `pricingModel`, `unitAmount`, `capAmount`, `termDays`, `trialDaysOverride`, `providerPriceRefs`, `activeFrom`, `activeUntil`, `isActive`, `isDefault`, `sortOrder`)
SELECT 'plan', 'global', tierID, 'starter-gbp-year', 'GBP', 'year', 1, 'flat', 190.00, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 21
FROM `tblSubscriptionTiers` WHERE tierSlug = 'starter' LIMIT 1;

INSERT IGNORE INTO `tblCatalogPrices`
    (`productType`, `planScope`, `productID`, `priceKey`, `currency`, `billingInterval`, `intervalCount`, `pricingModel`, `unitAmount`, `capAmount`, `termDays`, `trialDaysOverride`, `providerPriceRefs`, `activeFrom`, `activeUntil`, `isActive`, `isDefault`, `sortOrder`)
SELECT 'plan', 'global', tierID, 'pro-gbp-month', 'GBP', 'month', 1, 'metered_capped', 49.00, 49.00, NULL, NULL, NULL, NULL, NULL, 0, 1, 30
FROM `tblSubscriptionTiers` WHERE tierSlug = 'pro' LIMIT 1;

INSERT IGNORE INTO `tblCatalogPrices`
    (`productType`, `planScope`, `productID`, `priceKey`, `currency`, `billingInterval`, `intervalCount`, `pricingModel`, `unitAmount`, `capAmount`, `termDays`, `trialDaysOverride`, `providerPriceRefs`, `activeFrom`, `activeUntil`, `isActive`, `isDefault`, `sortOrder`)
SELECT 'plan', 'global', tierID, 'pro-gbp-year', 'GBP', 'year', 1, 'flat', 490.00, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 31
FROM `tblSubscriptionTiers` WHERE tierSlug = 'pro' LIMIT 1;

INSERT IGNORE INTO `tblCatalogPrices`
    (`productType`, `planScope`, `productID`, `priceKey`, `currency`, `billingInterval`, `intervalCount`, `pricingModel`, `unitAmount`, `capAmount`, `termDays`, `trialDaysOverride`, `providerPriceRefs`, `activeFrom`, `activeUntil`, `isActive`, `isDefault`, `sortOrder`)
SELECT 'plan', 'global', tierID, 'business-gbp-month', 'GBP', 'month', 1, 'metered_capped', 149.00, 149.00, NULL, NULL, NULL, NULL, NULL, 0, 1, 40
FROM `tblSubscriptionTiers` WHERE tierSlug = 'business' LIMIT 1;

INSERT IGNORE INTO `tblCatalogPrices`
    (`productType`, `planScope`, `productID`, `priceKey`, `currency`, `billingInterval`, `intervalCount`, `pricingModel`, `unitAmount`, `capAmount`, `termDays`, `trialDaysOverride`, `providerPriceRefs`, `activeFrom`, `activeUntil`, `isActive`, `isDefault`, `sortOrder`)
SELECT 'plan', 'global', tierID, 'business-gbp-year', 'GBP', 'year', 1, 'flat', 1490.00, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 41
FROM `tblSubscriptionTiers` WHERE tierSlug = 'business' LIMIT 1;


-- ============================================================================
-- ✅ VERIFICATION QUERIES
-- ============================================================================
SELECT 'New catalog tables' AS Status,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tblFeatureCatalog') AS tblFeatureCatalog_exists,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tblPlanFeatures') AS tblPlanFeatures_exists,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tblCatalogPrices') AS tblCatalogPrices_exists,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tblAddOns') AS tblAddOns_exists,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tblAccountAddOns') AS tblAccountAddOns_exists,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tblAppliedDiscounts') AS tblAppliedDiscounts_exists,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tblEntitlementOverrides') AS tblEntitlementOverrides_exists,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tblEntitlementCache') AS tblEntitlementCache_exists;

SELECT 'tblDiscountCodes new columns' AS Status,
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tblDiscountCodes' AND column_name = 'duration') AS duration_exists,
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tblDiscountCodes' AND column_name = 'applicableAddOns') AS applicableAddOns_exists;

SELECT 'tblSubscriptions new columns' AS Status,
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tblSubscriptions' AND column_name = 'priceID') AS priceID_exists,
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tblSubscriptions' AND column_name = 'organizationID') AS organizationID_exists,
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tblSubscriptions' AND column_name = 'isGrandfathered') AS isGrandfathered_exists,
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tblSubscriptions' AND column_name = 'entitlementSnapshot') AS entitlementSnapshot_exists;

SELECT 'entitlements.enforcement_enabled seeded OFF' AS Status,
    settingValue FROM `tblSettings` WHERE settingKey = 'entitlements.enforcement_enabled';

SELECT 'entitlement_enforcement toggle seeded OFF' AS Status,
    isEnabledGlobally, canPartnersToggle FROM `tblFeatureToggles` WHERE featureKey = 'entitlement_enforcement';

SELECT 'Dormant tiers seeded' AS Status, tierSlug, isActive FROM `tblSubscriptionTiers` WHERE tierSlug IN ('starter', 'business');

SELECT 'Feature catalog rows seeded' AS Status, COUNT(*) AS featureCount FROM `tblFeatureCatalog`;

SELECT 'Plan-feature rows seeded (all must be isActive=0)' AS Status,
    COUNT(*) AS totalRows,
    SUM(CASE WHEN isActive = 1 THEN 1 ELSE 0 END) AS activeRows
FROM `tblPlanFeatures`;

SELECT 'Catalog price rows seeded (all must be isActive=0)' AS Status,
    COUNT(*) AS totalRows,
    SUM(CASE WHEN isActive = 1 THEN 1 ELSE 0 END) AS activeRows
FROM `tblCatalogPrices`;


-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================
SELECT '✅ Migration 046_flexible_pricing_catalog.sql completed successfully — entitlement enforcement remains OFF (fail-open); zero existing rows modified' AS Result;
