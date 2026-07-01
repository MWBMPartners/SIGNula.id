-- ============================================================================
-- SIGNula Database Migration 019
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- 📋 Migration: Tier Expansion & Custom Tiers
-- 📅 Date:      2026-03-08
-- 🔖 Version:   2.7.0-beta
-- 📝 Description:
--   - Adds Pro and Platinum default subscription tiers
--   - Updates Enterprise tier display order (4 → 6)
--   - Adds support for custom (client-created) subscription tiers
--   - Adds isCustom and createdByPartnerID columns to tblSubscriptionTiers
--   - Adds usage export settings
--
-- 🔗 Related: GitHub Issue #48
-- ============================================================================

-- ============================================================================
-- 🔄 UPDATE ENTERPRISE TIER DISPLAY ORDER
-- ============================================================================
-- Move Enterprise from position 4 to position 6 to make room for Pro (4)
-- and Platinum (5)
-- ============================================================================

UPDATE `tblSubscriptionTiers`
SET `displayOrder` = 6
WHERE `tierSlug` = 'enterprise';

-- ============================================================================
-- ➕ ADD PRO TIER (Position 4)
-- ============================================================================
-- Pro sits between Premium and Platinum, targeting growing businesses
-- that need more capacity than Premium but don't need Enterprise
-- ============================================================================

-- [mig-fix] INSERT IGNORE: the Pro/Platinum tiers are already seeded by the
-- consolidated installer. IGNORE keeps this migration idempotent (no duplicate
-- key error when replayed on an installer-based DB) while still seeding them on
-- a DB that lacks them. Non-destructive.
INSERT IGNORE INTO `tblSubscriptionTiers` (
    `tierName`, `tierSlug`, `tierDescription`,
    `monthlyPrice`, `yearlyPrice`, `currency`,
    `features`, `featureLimits`,
    `teamMemberLimit`, `apiCallsPerMonth`, `storageGB`,
    `displayOrder`, `isActive`, `isDefault`, `trialDays`,
    `badge`, `badgeColor`
) VALUES (
    'Pro',
    'pro',
    'Professional-grade features for growing businesses needing higher capacity and advanced tools',
    49.99,
    499.99,
    'GBP',
    '["Everything in Premium","50 team members","200,000 API calls/month","Phone & email support","Custom webhooks","Advanced security policies","Role-based access control","API rate limit customisation","Usage analytics dashboard","Bulk user management"]',
    '{"api_calls": 200000, "team_members": 50, "storage_gb": 100}',
    50,
    200000,
    100.00,
    4,
    1,
    0,
    14,
    NULL,
    NULL
);

-- ============================================================================
-- ➕ ADD PLATINUM TIER (Position 5)
-- ============================================================================
-- Platinum sits between Pro and Enterprise, offering near-unlimited
-- capacity with premium support, but without needing a custom contract
-- ============================================================================

-- [mig-fix] INSERT IGNORE for idempotency (see Pro tier note above).
INSERT IGNORE INTO `tblSubscriptionTiers` (
    `tierName`, `tierSlug`, `tierDescription`,
    `monthlyPrice`, `yearlyPrice`, `currency`,
    `features`, `featureLimits`,
    `teamMemberLimit`, `apiCallsPerMonth`, `storageGB`,
    `displayOrder`, `isActive`, `isDefault`, `trialDays`,
    `badge`, `badgeColor`
) VALUES (
    'Platinum',
    'platinum',
    'Near-unlimited capacity with premium support and advanced compliance features',
    79.99,
    799.99,
    'GBP',
    '["Everything in Pro","200 team members","500,000 API calls/month","Dedicated account manager","Priority phone support","SAML SSO","SCIM provisioning","Advanced audit logging","Compliance reports","Custom branding & white-label","Data residency options"]',
    '{"api_calls": 500000, "team_members": 200, "storage_gb": 500}',
    200,
    500000,
    500.00,
    5,
    1,
    0,
    30,
    'Best Value',
    'success'
);

-- ============================================================================
-- 🔄 UPDATE ENTERPRISE BADGE
-- ============================================================================
-- Since Platinum now has "Best Value", Enterprise gets "Custom" badge
-- ============================================================================

UPDATE `tblSubscriptionTiers`
SET `badge` = 'Custom',
    `badgeColor` = 'dark'
WHERE `tierSlug` = 'enterprise';

-- ============================================================================
-- 🏗️ ADD CUSTOM TIER SUPPORT COLUMNS
-- ============================================================================
-- isCustom: Indicates whether the tier was created by a client/partner
--           (as opposed to being a default/global tier)
-- createdByPartnerID: Links custom tiers to the partner who created them
--                     NULL for global/default tiers
-- ============================================================================

-- [mig-fix] The custom-tier columns / FKs / indexes below are already present
-- in the consolidated installer's tblSubscriptionTiers definition. MySQL 8/9
-- has no "ADD COLUMN IF NOT EXISTS", so each ADD is wrapped in an
-- information_schema existence guard to make this migration idempotent and
-- additive (safe to replay on an installer-based DB or a bare one).
-- Note: createdByPartnerID / parentTierID use BIGINT UNSIGNED to match the
-- BIGINT identity columns of tblPartners / tblSubscriptionTiers.

-- helper: add a column only if missing
SET @__tbl := 'tblSubscriptionTiers';

SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='isCustom');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptionTiers` ADD COLUMN `isCustom` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether this is a custom client-created tier (1) or default global tier (0)' AFTER `isDefault`", 'DO 0'); PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='createdByPartnerID');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptionTiers` ADD COLUMN `createdByPartnerID` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Partner who created this custom tier (NULL for global tiers)' AFTER `isCustom`", 'DO 0'); PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='parentTierID');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptionTiers` ADD COLUMN `parentTierID` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Base tier this custom tier is derived from (NULL for standalone/global)' AFTER `createdByPartnerID`", 'DO 0'); PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='usageLimits');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptionTiers` ADD COLUMN `usageLimits` JSON DEFAULT NULL COMMENT 'Per-metric usage limits for this tier' AFTER `featureLimits`", 'DO 0'); PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

SET @__c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND COLUMN_NAME='billingMode');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptionTiers` ADD COLUMN `billingMode` ENUM('fixed','usage','hybrid') NOT NULL DEFAULT 'fixed' COMMENT 'Default billing mode for subscriptions on this tier' AFTER `usageLimits`", 'DO 0'); PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 🔗 Foreign key for createdByPartnerID (guarded)
SET @__c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND CONSTRAINT_NAME='fk_st_created_by_partner');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptionTiers` ADD CONSTRAINT `fk_st_created_by_partner` FOREIGN KEY (`createdByPartnerID`) REFERENCES `tblPartners` (`partnerID`) ON DELETE SET NULL ON UPDATE CASCADE", 'DO 0'); PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 🔗 Foreign key for parentTierID (self-referencing, guarded)
SET @__c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND CONSTRAINT_NAME='fk_st_parent_tier');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptionTiers` ADD CONSTRAINT `fk_st_parent_tier` FOREIGN KEY (`parentTierID`) REFERENCES `tblSubscriptionTiers` (`tierID`) ON DELETE SET NULL ON UPDATE CASCADE", 'DO 0'); PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- 📇 Indexes for efficient custom tier lookups (guarded)
SET @__c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND INDEX_NAME='idx_st_custom');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptionTiers` ADD INDEX `idx_st_custom` (`isCustom`, `createdByPartnerID`)", 'DO 0'); PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

SET @__c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@__tbl AND INDEX_NAME='idx_st_billing_mode');
SET @__s := IF(@__c=0, "ALTER TABLE `tblSubscriptionTiers` ADD INDEX `idx_st_billing_mode` (`billingMode`)", 'DO 0'); PREPARE __s FROM @__s; EXECUTE __s; DEALLOCATE PREPARE __s;

-- ============================================================================
-- ⚙️ SETTINGS — Export & Custom Tier Configuration
-- ============================================================================

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `description`, `settingCategory`, `isSensitive`)
VALUES
    -- 📊 Export settings
    ('export.csv.delimiter', ',', 'CSV export field delimiter character', 'export', 0),
    ('export.csv.encoding', 'UTF-8', 'CSV export character encoding', 'export', 0),
    ('export.pdf.orientation', 'landscape', 'PDF export page orientation (portrait/landscape)', 'export', 0),
    ('export.pdf.paper_size', 'A4', 'PDF export paper size (A4/Letter/Legal)', 'export', 0),
    ('export.max_rows', '10000', 'Maximum rows allowed in a single export', 'export', 0),

    -- 🏗️ Custom tier settings
    ('tiers.custom.enabled', 'true', 'Whether partners can create custom subscription tiers', 'billing', 0),
    ('tiers.custom.max_per_partner', '10', 'Maximum number of custom tiers per partner', 'billing', 0),
    ('tiers.custom.require_approval', 'false', 'Whether custom tiers require admin approval before activation', 'billing', 0),

    -- 📊 Google Sheets integration (OAuth credentials)
    ('export.google_sheets.client_id', '', 'Google Sheets API OAuth client ID', 'export', 1),
    ('export.google_sheets.client_secret', '', 'Google Sheets API OAuth client secret', 'export', 1),
    ('export.google_sheets.enabled', 'false', 'Whether Google Sheets export is enabled', 'export', 0),

    -- 📊 Microsoft Graph integration (Excel Online)
    ('export.excel_online.client_id', '', 'Microsoft Graph API client ID for Excel Online', 'export', 1),
    ('export.excel_online.client_secret', '', 'Microsoft Graph API client secret for Excel Online', 'export', 1),
    ('export.excel_online.tenant_id', '', 'Microsoft Azure AD tenant ID for Excel Online', 'export', 1),
    ('export.excel_online.enabled', 'false', 'Whether Excel Online export is enabled', 'export', 0);

-- ============================================================================
-- 📧 EMAIL TEMPLATES — Custom Tier Notifications
-- ============================================================================

INSERT INTO `tblEmailTemplates` (`templateKey`, `templateName`, `subject`, `bodyHTML`, `bodyText`, `requiredVariables`, `isActive`)
VALUES
    (
        'custom_tier_created',
        'Custom Tier Created Notification',
        'New Custom Tier Created: {{tierName}}',
        '<h2>New Custom Tier Created</h2><p>A new custom subscription tier <strong>{{tierName}}</strong> has been created by partner <strong>{{partnerName}}</strong>.</p><p><strong>Monthly Price:</strong> {{monthlyPrice}}<br><strong>Yearly Price:</strong> {{yearlyPrice}}<br><strong>Billing Mode:</strong> {{billingMode}}</p>',
        'New Custom Tier Created\n\nA new custom subscription tier "{{tierName}}" has been created by partner "{{partnerName}}".\n\nMonthly Price: {{monthlyPrice}}\nYearly Price: {{yearlyPrice}}\nBilling Mode: {{billingMode}}',
        '["tierName", "partnerName", "monthlyPrice", "yearlyPrice", "billingMode"]',
        1
    ),
    (
        'custom_tier_approval_required',
        'Custom Tier Pending Approval',
        'Custom Tier Pending Approval: {{tierName}}',
        '<h2>Custom Tier Pending Approval</h2><p>A new custom subscription tier <strong>{{tierName}}</strong> created by <strong>{{partnerName}}</strong> requires your approval before activation.</p><p><a href="{{approvalUrl}}">Review and Approve</a></p>',
        'Custom Tier Pending Approval\n\nA new custom subscription tier "{{tierName}}" created by "{{partnerName}}" requires your approval before activation.\n\nReview at: {{approvalUrl}}',
        '["tierName", "partnerName", "approvalUrl"]',
        1
    );

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================
-- Post-migration verification:
--   SELECT tierName, tierSlug, displayOrder FROM tblSubscriptionTiers
--   WHERE isActive = 1 ORDER BY displayOrder;
--   Expected: Free(1), Basic(2), Premium(3), Pro(4), Platinum(5), Enterprise(6)
--
--   SHOW COLUMNS FROM tblSubscriptionTiers LIKE 'isCustom';
--   SHOW COLUMNS FROM tblSubscriptionTiers LIKE 'createdByPartnerID';
-- ============================================================================
