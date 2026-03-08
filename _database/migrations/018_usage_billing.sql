-- ============================================================================
-- SIGNula Database Migration 018
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- Migration: 018_usage_billing.sql
-- Version: 2.7.0-beta
-- Date: 2026-03-08
-- Description: Hybrid pay-as-you-go / usage-based billing system with tier cap
--
-- Features Added:
--   - Usage metrics definition (global + partner-specific)
--   - Per-tier usage rates with free allowance and overage pricing
--   - Real-time usage event tracking with billing period assignment
--   - Usage billing summaries with cap logic (hybrid mode)
--   - Three billing modes: fixed, usage, hybrid
--   - Itemised usage breakdown for invoice generation
--   - Usage data retention and archival support
--   - 6 new settings for usage billing configuration
--   - 3 new billing scheduler task types for usage processing
--   - 2 new email templates for usage billing notifications
--
-- Dependencies:
--   - Migration 010 (tblSubscriptions, tblSubscriptionTiers must exist)
--   - Migration 012 (tblPartnerSubscriptionTiers, tblBillingSchedule,
--                     tblInvoices must exist)
--
-- GitHub Issue: #47 — Hybrid Pay-As-You-Go / Usage-Based Billing with Tier Cap
--
-- Supports: MySQL 8.0+, MariaDB 10.5+
-- ============================================================================


-- ============================================================================
-- 📊 USAGE METRICS DEFINITION
-- ============================================================================
-- Defines what measurable quantities can be tracked and billed.
-- Examples: API calls, storage GB, emails sent, active users, transactions.
-- Metrics can be global (partnerID IS NULL) or partner-specific.
--
-- @see https://dev.mysql.com/doc/refman/8.0/en/create-table.html
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblUsageMetrics` (
    `metricID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT '🔑 Unique metric identifier (PK)',

    `metricCode` VARCHAR(50) NOT NULL
        COMMENT '🏷️ Machine-readable code (e.g. api_calls, storage_gb, emails_sent)',

    `metricName` VARCHAR(100) NOT NULL
        COMMENT '📋 Human-readable display name',

    `metricDescription` TEXT DEFAULT NULL
        COMMENT '📝 Detailed description of what this metric measures',

    `unitLabel` VARCHAR(30) NOT NULL DEFAULT 'units'
        COMMENT '📏 Display unit label (e.g. "calls", "GB", "emails", "users")',

    `billingGranularity` INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT '🔢 Bill per N units (1 = per unit, 1000 = per thousand, etc.)',

    `aggregationType` ENUM('sum', 'max', 'avg') NOT NULL DEFAULT 'sum'
        COMMENT '📈 How to aggregate usage within a billing cycle — sum (total), max (peak), avg (average)',

    `partnerID` INT UNSIGNED DEFAULT NULL
        COMMENT '🏢 FK to tblPartners — NULL = global metric, set = partner-specific',

    `isActive` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '✅ Whether this metric is currently active and available for tracking',

    `sortOrder` INT NOT NULL DEFAULT 0
        COMMENT '🔢 Display ordering for UI listings',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT '📅 When this metric was created',

    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        COMMENT '📅 When this metric was last modified',

    PRIMARY KEY (`metricID`),

    -- 🔍 Unique constraint: metricCode must be unique per scope (global or partner)
    UNIQUE KEY `uq_metric_code_scope` (`metricCode`, `partnerID`),

    -- 🔍 Index for looking up partner-specific metrics
    KEY `idx_partner` (`partnerID`),

    -- 🔍 Index for active metrics listing
    KEY `idx_active_sort` (`isActive`, `sortOrder`),

    -- 🔗 Foreign key to tblPartners (NULL = global metric)
    CONSTRAINT `fk_usage_metrics_partner`
        FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='📊 Usage metric definitions — what can be metered and billed';


-- ============================================================================
-- 💰 USAGE RATES — Per-Tier Pricing for Each Metric
-- ============================================================================
-- Each subscription tier (global or partner-defined) can specify usage rates
-- for each metric. Supports free allowance, standard rate, overage rate,
-- and optional unit caps. Rates can be scheduled with effectiveFrom/Until.
--
-- @see https://dev.mysql.com/doc/refman/8.0/en/fixed-point-types.html (DECIMAL precision)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblUsageRates` (
    `rateID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT '🔑 Unique rate record identifier (PK)',

    `metricID` INT UNSIGNED NOT NULL
        COMMENT '📊 FK to tblUsageMetrics — which metric this rate applies to',

    `tierID` INT UNSIGNED NOT NULL
        COMMENT '🏷️ FK to tblSubscriptionTiers or tblPartnerSubscriptionTiers',

    `tierType` ENUM('global', 'partner') NOT NULL DEFAULT 'global'
        COMMENT '🔀 Whether tierID references tblSubscriptionTiers (global) or tblPartnerSubscriptionTiers (partner)',

    `ratePerUnit` DECIMAL(12,6) NOT NULL DEFAULT 0.000000
        COMMENT '💰 Cost per billing granularity unit (e.g. £0.002 per API call)',

    `freeAllowance` DECIMAL(12,4) NOT NULL DEFAULT 0.0000
        COMMENT '🎁 Included units per billing cycle before charges apply (e.g. 1000 free API calls)',

    `overageRate` DECIMAL(12,6) DEFAULT NULL
        COMMENT '💰 Override rate after free allowance exhausted (NULL = same as ratePerUnit)',

    `overageCapUnits` DECIMAL(12,4) DEFAULT NULL
        COMMENT '🔒 Maximum billable units per cycle (NULL = unlimited). Usage beyond this is free',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT '💱 ISO 4217 currency code for this rate',

    `isActive` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '✅ Whether this rate is currently in effect',

    `effectiveFrom` DATE NOT NULL
        COMMENT '📅 Date this rate takes effect (inclusive)',

    `effectiveUntil` DATE DEFAULT NULL
        COMMENT '📅 Date this rate expires (NULL = indefinite). Allows scheduling rate changes',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT '📅 When this rate was created',

    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        COMMENT '📅 When this rate was last modified',

    PRIMARY KEY (`rateID`),

    -- 🔍 Index for looking up rates by metric + tier combination
    KEY `idx_metric_tier` (`metricID`, `tierID`, `tierType`),

    -- 🔍 Index for finding active rates within a date range
    KEY `idx_active_dates` (`isActive`, `effectiveFrom`, `effectiveUntil`),

    -- 🔗 Foreign key to tblUsageMetrics
    CONSTRAINT `fk_usage_rates_metric`
        FOREIGN KEY (`metricID`)
        REFERENCES `tblUsageMetrics` (`metricID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='💰 Per-tier usage rates — pricing for each metric at each subscription tier';


-- ============================================================================
-- 📝 USAGE RECORDS — Individual Usage Event Tracking
-- ============================================================================
-- High-volume table that tracks every usage event. Each record is assigned to
-- a billing period for aggregation. The isProcessed flag tracks whether
-- the record has been included in a billing calculation.
--
-- ⚡ Performance note: This table will grow large. Proper indexing and
-- periodic archival/partitioning is essential. Consider MySQL partitioning
-- by billingPeriodStart for large installations.
--
-- @see https://dev.mysql.com/doc/refman/8.0/en/partitioning.html (table partitioning)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblUsageRecords` (
    `recordID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT '🔑 Unique record identifier (PK) — BIGINT for high-volume data',

    `userID` INT UNSIGNED NOT NULL
        COMMENT '👤 FK to tblUsers — who generated this usage',

    `partnerID` INT UNSIGNED DEFAULT NULL
        COMMENT '🏢 FK to tblPartners — partner context (NULL = SIGNula direct)',

    `metricID` INT UNSIGNED NOT NULL
        COMMENT '📊 FK to tblUsageMetrics — which metric was consumed',

    `quantity` DECIMAL(12,4) NOT NULL DEFAULT 1.0000
        COMMENT '🔢 Amount consumed (e.g. 1 API call, 0.5 GB storage)',

    `recordedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT '⏰ When the usage event occurred',

    `billingPeriodStart` DATE NOT NULL
        COMMENT '📅 Start of the billing cycle this record belongs to',

    `billingPeriodEnd` DATE NOT NULL
        COMMENT '📅 End of the billing cycle this record belongs to',

    `metadata` JSON DEFAULT NULL
        COMMENT '📦 Additional context (endpoint path, resource ID, request IP, etc.)',

    `isProcessed` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '✅ Whether this record has been included in a billing calculation',

    `processedAt` DATETIME DEFAULT NULL
        COMMENT '📅 When this record was processed into a billing summary',

    PRIMARY KEY (`recordID`),

    -- 🔍 Critical query index: aggregate usage by user, metric, and billing period
    KEY `idx_user_metric_period` (`userID`, `metricID`, `billingPeriodStart`),

    -- 🔍 Index for partner-scoped usage queries
    KEY `idx_partner_metric_period` (`partnerID`, `metricID`, `billingPeriodStart`),

    -- 🔍 Index for processing: find unprocessed records within a billing period
    KEY `idx_unprocessed` (`isProcessed`, `billingPeriodStart`, `billingPeriodEnd`),

    -- 🔍 Index for time-based queries and archival
    KEY `idx_recorded_at` (`recordedAt`),

    -- 🔗 Foreign key to tblUsers
    CONSTRAINT `fk_usage_records_user`
        FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    -- 🔗 Foreign key to tblPartners
    CONSTRAINT `fk_usage_records_partner`
        FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    -- 🔗 Foreign key to tblUsageMetrics
    CONSTRAINT `fk_usage_records_metric`
        FOREIGN KEY (`metricID`)
        REFERENCES `tblUsageMetrics` (`metricID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='📝 Individual usage event tracking — high-volume metering data';


-- ============================================================================
-- 📊 USAGE BILLING SUMMARY — Aggregated Cost Calculations
-- ============================================================================
-- Monthly (or per-cycle) aggregation of usage records with cost calculation,
-- cap logic, and invoice linkage. This is the "bill" generated from raw usage.
--
-- Cap logic (hybrid mode):
--   totalUsageCost = SUM(metric costs)
--   cappedAmount = MIN(totalUsageCost, tierFixedPrice)  -- hybrid
--   cappedAmount = totalUsageCost                        -- usage-only
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblUsageBillingSummary` (
    `summaryID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT '🔑 Unique billing summary identifier (PK)',

    `subscriptionID` INT UNSIGNED NOT NULL
        COMMENT '📋 FK to tblSubscriptions — which subscription this summary is for',

    `userID` INT UNSIGNED NOT NULL
        COMMENT '👤 FK to tblUsers — the billed user (denormalised for query performance)',

    `partnerID` INT UNSIGNED DEFAULT NULL
        COMMENT '🏢 FK to tblPartners — partner context (NULL = SIGNula direct)',

    `billingPeriodStart` DATE NOT NULL
        COMMENT '📅 Start of the billing cycle (inclusive)',

    `billingPeriodEnd` DATE NOT NULL
        COMMENT '📅 End of the billing cycle (inclusive)',

    `billingMode` ENUM('fixed', 'usage', 'hybrid') NOT NULL DEFAULT 'hybrid'
        COMMENT '🔀 Billing mode used for this calculation',

    `totalUsageCost` DECIMAL(12,4) NOT NULL DEFAULT 0.0000
        COMMENT '💰 Raw sum of all metric usage costs (before cap)',

    `tierFixedPrice` DECIMAL(12,4) NOT NULL DEFAULT 0.0000
        COMMENT '💰 The tier monthly fixed price (used for cap comparison in hybrid mode)',

    `cappedAmount` DECIMAL(12,4) NOT NULL DEFAULT 0.0000
        COMMENT '💰 Final charge amount after cap logic — MIN(totalUsageCost, tierFixedPrice) for hybrid',

    `capApplied` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '🔒 Whether the tier price cap was triggered (1 = usage exceeded fixed price)',

    `savingsAmount` DECIMAL(12,4) NOT NULL DEFAULT 0.0000
        COMMENT '💰 Amount saved via cap = totalUsageCost - cappedAmount (for customer reporting)',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT '💱 ISO 4217 currency code',

    `lineItems` JSON NOT NULL
        COMMENT '📋 Itemised breakdown per metric: [{metricCode, metricName, quantity, freeAllowance, billableQuantity, rate, subtotal}]',

    `invoiceID` INT UNSIGNED DEFAULT NULL
        COMMENT '🧾 FK to tblInvoices — linked invoice (set when invoice is generated)',

    `paymentID` INT UNSIGNED DEFAULT NULL
        COMMENT '💳 FK to tblPayments — linked payment record',

    `calculatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT '📅 When this billing summary was computed',

    `status` ENUM('pending', 'invoiced', 'paid', 'void', 'failed') NOT NULL DEFAULT 'pending'
        COMMENT '📊 Processing status: pending → invoiced → paid / void / failed',

    `notes` TEXT DEFAULT NULL
        COMMENT '📝 Admin notes or processing remarks',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT '📅 Record creation timestamp',

    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        COMMENT '📅 Last modification timestamp',

    PRIMARY KEY (`summaryID`),

    -- 🔍 Prevent duplicate summaries for the same subscription + billing period
    UNIQUE KEY `uq_subscription_period` (`subscriptionID`, `billingPeriodStart`, `billingPeriodEnd`),

    -- 🔍 Index for user billing history
    KEY `idx_user_period` (`userID`, `billingPeriodStart`),

    -- 🔍 Index for partner billing reports
    KEY `idx_partner_period` (`partnerID`, `billingPeriodStart`),

    -- 🔍 Index for status-based queries (find pending summaries to invoice)
    KEY `idx_status` (`status`),

    -- 🔍 Index for invoice linkage
    KEY `idx_invoice` (`invoiceID`),

    -- 🔗 Foreign key to tblSubscriptions
    CONSTRAINT `fk_usage_summary_subscription`
        FOREIGN KEY (`subscriptionID`)
        REFERENCES `tblSubscriptions` (`subscriptionID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    -- 🔗 Foreign key to tblUsers
    CONSTRAINT `fk_usage_summary_user`
        FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    -- 🔗 Foreign key to tblPartners
    CONSTRAINT `fk_usage_summary_partner`
        FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    -- 🔗 Foreign key to tblInvoices
    CONSTRAINT `fk_usage_summary_invoice`
        FOREIGN KEY (`invoiceID`)
        REFERENCES `tblInvoices` (`invoiceID`)
        ON DELETE SET NULL
        ON UPDATE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='📊 Usage billing summaries — aggregated cost calculations with cap logic per billing cycle';


-- ============================================================================
-- 🔧 ALTER EXISTING TABLES — Add Usage Billing Support
-- ============================================================================

-- 📋 Add billingMode to tblSubscriptions
-- Determines how the subscription is billed: fixed, usage-based, or hybrid
ALTER TABLE `tblSubscriptions`
    ADD COLUMN `billingMode` ENUM('fixed', 'usage', 'hybrid') NOT NULL DEFAULT 'fixed'
        COMMENT '🔀 Billing mode: fixed (flat fee), usage (pay-as-you-go), hybrid (usage with cap at tier price)'
        AFTER `billingCycle`;

-- 📋 Add usage billing support flags to tblSubscriptionTiers (global tiers)
ALTER TABLE `tblSubscriptionTiers`
    ADD COLUMN `supportsUsageBilling` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '✅ Whether this tier supports usage-based / hybrid billing modes'
        AFTER `isDefault`,
    ADD COLUMN `usageBillingDescription` TEXT DEFAULT NULL
        COMMENT '📝 Customer-facing description of usage-based billing for this tier'
        AFTER `supportsUsageBilling`;

-- 📋 Add usage billing support flags to tblPartnerSubscriptionTiers (partner tiers)
ALTER TABLE `tblPartnerSubscriptionTiers`
    ADD COLUMN `supportsUsageBilling` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '✅ Whether this tier supports usage-based / hybrid billing modes'
        AFTER `isDefault`,
    ADD COLUMN `usageBillingDescription` TEXT DEFAULT NULL
        COMMENT '📝 Customer-facing description of usage-based billing for this tier'
        AFTER `supportsUsageBilling`;

-- 📋 Extend tblInvoices invoiceType ENUM to include 'usage' type
ALTER TABLE `tblInvoices`
    MODIFY COLUMN `invoiceType` ENUM(
        'subscription',
        'one_time',
        'credit_topup',
        'service_fee',
        'remittance',
        'usage'
    ) NOT NULL DEFAULT 'subscription'
        COMMENT '📋 Invoice type — includes new "usage" type for usage-based billing';

-- 📋 Extend tblBillingSchedule taskType ENUM to include usage billing tasks
-- Note: The exact ENUM values depend on the existing column definition.
-- We add three new task types for usage billing processing.
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
        'archive_usage'
    ) NOT NULL
        COMMENT '📋 Task type — includes original + 3 new usage billing tasks';


-- ============================================================================
-- ⚙️ SETTINGS — Usage Billing Configuration
-- ============================================================================
-- All settings stored in tblSettings following the existing pattern.
-- @see web/_config/config.php for getSetting() retrieval
-- ============================================================================

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `settingDescription`, `isSensitive`, `isEditable`)
VALUES
    -- 🔀 Master toggle for usage-based billing
    ('billing.usage.enabled', 'false', 'boolean', 'Billing',
     'Master toggle: enable or disable the usage-based billing system globally. When disabled, all subscriptions are billed at fixed tier rates regardless of billingMode.',
     0, 1),

    -- 📊 Toggle for usage tracking (can track without billing)
    ('billing.usage.tracking_enabled', 'true', 'boolean', 'Billing',
     'Whether to record usage events to tblUsageRecords. Can be enabled independently of billing to gather data before enabling charges.',
     0, 1),

    -- 🔀 Default billing mode for new subscriptions
    ('billing.usage.default_mode', 'fixed', 'string', 'Billing',
     'Default billing mode assigned to new subscriptions. Options: fixed, usage, hybrid. Users can change if the tier supports it.',
     0, 1),

    -- 📅 Cap comparison period
    ('billing.usage.cap_comparison', 'monthly', 'string', 'Billing',
     'Period used for cap comparison in hybrid mode. Currently only "monthly" is supported. The tier monthlyPrice is always used as the cap.',
     0, 1),

    -- 📅 Usage data retention (days)
    ('billing.usage.retention_days', '365', 'integer', 'Billing',
     'Number of days to retain raw usage records in tblUsageRecords after processing. Records older than this are archived/deleted by the archive_usage task.',
     0, 1),

    -- 🔢 Batch size for usage billing processing
    ('billing.usage.batch_size', '100', 'integer', 'Billing',
     'Maximum number of usage records to process per billing batch. Keeps processing time manageable on shared hosting.',
     0, 1)

ON DUPLICATE KEY UPDATE
    `updatedAt` = CURRENT_TIMESTAMP;


-- ============================================================================
-- 📧 EMAIL TEMPLATES — Usage Billing Notifications
-- ============================================================================

INSERT INTO `tblEmailTemplates` (`templateKey`, `templateName`, `subject`, `bodyHTML`, `bodyText`, `category`, `variables`, `isActive`)
VALUES
    -- 📧 Usage billing summary notification
    ('usage_billing_summary', 'Usage Billing Summary', 'Your usage billing summary for {{billing_period}}',
     '<h2>Usage Billing Summary</h2>'
     '<p>Hi {{first_name}},</p>'
     '<p>Here''s your usage summary for <strong>{{billing_period}}</strong>:</p>'
     '<table style="width:100%;border-collapse:collapse;">'
     '<tr style="background:#f8f9fa;"><th style="padding:8px;text-align:left;border:1px solid #dee2e6;">Metric</th>'
     '<th style="padding:8px;text-align:right;border:1px solid #dee2e6;">Usage</th>'
     '<th style="padding:8px;text-align:right;border:1px solid #dee2e6;">Free Allowance</th>'
     '<th style="padding:8px;text-align:right;border:1px solid #dee2e6;">Billable</th>'
     '<th style="padding:8px;text-align:right;border:1px solid #dee2e6;">Cost</th></tr>'
     '{{usage_line_items}}'
     '</table>'
     '<p><strong>Total Usage Cost:</strong> {{total_usage_cost}}</p>'
     '{{cap_applied_notice}}'
     '<p><strong>Amount Charged:</strong> {{charged_amount}}</p>'
     '<p>View your detailed usage breakdown in your <a href="{{usage_dashboard_url}}">usage dashboard</a>.</p>',
     'Hi {{first_name}},\n\nHere''s your usage summary for {{billing_period}}:\n\n{{usage_line_items_text}}\n\nTotal Usage Cost: {{total_usage_cost}}\n{{cap_applied_notice_text}}\nAmount Charged: {{charged_amount}}\n\nView your detailed usage breakdown at: {{usage_dashboard_url}}',
     'billing',
     '["first_name","billing_period","usage_line_items","usage_line_items_text","total_usage_cost","cap_applied_notice","cap_applied_notice_text","charged_amount","usage_dashboard_url"]',
     1),

    -- 📧 Usage approaching cap notification
    ('usage_approaching_cap', 'Usage Approaching Tier Cap', 'Your usage is approaching your tier cap for {{billing_period}}',
     '<h2>Usage Approaching Tier Cap</h2>'
     '<p>Hi {{first_name}},</p>'
     '<p>Your usage costs for <strong>{{billing_period}}</strong> are approaching your tier''s monthly cap of <strong>{{tier_cap_amount}}</strong>.</p>'
     '<p><strong>Current Usage Cost:</strong> {{current_usage_cost}}<br>'
     '<strong>Tier Cap:</strong> {{tier_cap_amount}}<br>'
     '<strong>Usage Percentage:</strong> {{usage_percentage}}%</p>'
     '<p>Once your usage cost reaches your tier cap, you won''t be charged more than {{tier_cap_amount}} — that''s the benefit of hybrid billing!</p>'
     '<p>View your usage details in your <a href="{{usage_dashboard_url}}">usage dashboard</a>.</p>',
     'Hi {{first_name}},\n\nYour usage costs for {{billing_period}} are approaching your tier''s monthly cap of {{tier_cap_amount}}.\n\nCurrent Usage Cost: {{current_usage_cost}}\nTier Cap: {{tier_cap_amount}}\nUsage Percentage: {{usage_percentage}}%\n\nOnce your usage cost reaches your tier cap, you won''t be charged more than {{tier_cap_amount}}.\n\nView your usage details at: {{usage_dashboard_url}}',
     'billing',
     '["first_name","billing_period","tier_cap_amount","current_usage_cost","usage_percentage","usage_dashboard_url"]',
     1)

ON DUPLICATE KEY UPDATE
    `updatedAt` = CURRENT_TIMESTAMP;


-- ============================================================================
-- 📊 SEED DEFAULT USAGE METRICS
-- ============================================================================
-- Pre-populate common usage metrics that most installations will use.
-- These are global metrics (partnerID IS NULL).
-- ============================================================================

INSERT INTO `tblUsageMetrics` (`metricCode`, `metricName`, `metricDescription`, `unitLabel`, `billingGranularity`, `aggregationType`, `partnerID`, `sortOrder`)
VALUES
    ('api_calls', 'API Calls', 'Number of API requests made to SIGNula endpoints', 'calls', 1000, 'sum', NULL, 10),
    ('storage_gb', 'Storage', 'Data storage consumed (avatars, uploads, attachments)', 'GB', 1, 'max', NULL, 20),
    ('emails_sent', 'Emails Sent', 'Transactional and notification emails dispatched', 'emails', 100, 'sum', NULL, 30),
    ('active_users', 'Active Users', 'Unique users who authenticated during the billing period', 'users', 1, 'max', NULL, 40),
    ('auth_events', 'Authentication Events', 'Login attempts, MFA verifications, and session operations', 'events', 1000, 'sum', NULL, 50),
    ('webhook_deliveries', 'Webhook Deliveries', 'Outbound webhook delivery attempts to partner endpoints', 'deliveries', 100, 'sum', NULL, 60)

ON DUPLICATE KEY UPDATE
    `updatedAt` = CURRENT_TIMESTAMP;


-- ============================================================================
-- 🗂️ MYSQL SCHEDULED EVENT — Archive Old Usage Records
-- ============================================================================
-- Runs daily to delete processed usage records older than the retention period.
-- @see https://dev.mysql.com/doc/refman/8.0/en/events-overview.html
-- ============================================================================

DELIMITER //

-- 🗑️ Archive/delete old processed usage records (runs daily at 03:00)
CREATE EVENT IF NOT EXISTS `evt_archive_old_usage_records`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP + INTERVAL 3 HOUR
COMMENT 'Daily cleanup: delete processed usage records older than retention period'
DO
BEGIN
    -- 📋 Get retention period from settings (default 365 days)
    DECLARE retention_days INT DEFAULT 365;

    SELECT CAST(`settingValue` AS UNSIGNED) INTO retention_days
    FROM `tblSettings`
    WHERE `settingKey` = 'billing.usage.retention_days'
    LIMIT 1;

    -- 🗑️ Delete processed records older than retention period
    -- Only deletes records that have been processed (isProcessed = 1)
    -- Unprocessed records are never auto-deleted regardless of age
    DELETE FROM `tblUsageRecords`
    WHERE `isProcessed` = 1
      AND `recordedAt` < DATE_SUB(NOW(), INTERVAL retention_days DAY);
END //

DELIMITER ;


-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================
-- New tables: tblUsageMetrics, tblUsageRates, tblUsageRecords, tblUsageBillingSummary
-- Altered: tblSubscriptions, tblSubscriptionTiers, tblPartnerSubscriptionTiers, tblBillingSchedule
-- Settings: 6 new billing.usage.* settings
-- Email templates: 2 new (usage_billing_summary, usage_approaching_cap)
-- Seed data: 6 default usage metrics
-- Scheduled events: 1 daily cleanup event
-- ============================================================================
