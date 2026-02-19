-- ============================================================================
-- SIGNula Database Migration 013
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- Migration: 013_kofi_patreon_providers.sql
-- Version: 2.5.0-beta
-- Date: 2026-02-18
-- Description: Ko-fi and Patreon payment/donation provider integration
--              with full two-tier partner support
--
-- Features Added:
--   - Ko-fi payment/donation provider (webhook-only, verification_token auth)
--   - Patreon subscription/pledge provider (OAuth 2.0 + HMAC-SHA256 webhooks)
--   - Expanded provider ENUM in tblPartnerPaymentConfig for ko-fi and patreon
--   - Expanded paymentMethod ENUM in tblPayments for ko-fi and patreon
--   - tblSettings entries for both providers (credentials, configuration)
--   - Provider discount entries for ko-fi and patreon (global, inactive default)
--   - 2 new feature toggles for Ko-fi and Patreon billing
--   - 4 new email templates for Ko-fi/Patreon payment notifications
--
-- Dependencies:
--   - Migration 010 (tblPayments, tblSubscriptions must exist)
--   - Migration 011 (tblInboundWebhooks must exist)
--   - Migration 012 (tblPartnerPaymentConfig, tblProviderDiscounts must exist)
--
-- Supports: MySQL 8.0+, MariaDB 10.5+
-- ============================================================================


-- ============================================================================
-- 🔧 ALTER EXISTING TABLES
-- ============================================================================

-- 🏢 tblPartnerPaymentConfig — Add Ko-fi and Patreon as valid providers
-- Ko-fi uses a webhook-only integration model with a verification token
-- Patreon uses OAuth 2.0 for API access plus HMAC-SHA256 signed webhooks
-- @see Migration 012 for original tblPartnerPaymentConfig definition
ALTER TABLE `tblPartnerPaymentConfig`
    MODIFY COLUMN `provider` ENUM('stripe', 'paypal', 'coinbase', 'kofi', 'patreon') NOT NULL
        COMMENT 'Payment provider this config applies to';

-- 💳 tblPayments — Add Ko-fi and Patreon as valid payment methods
-- Extends the existing paymentMethod ENUM to include the two new provider types
-- @see Migration 010 for original tblPayments definition
ALTER TABLE `tblPayments`
    MODIFY COLUMN `paymentMethod` ENUM('card', 'paypal', 'crypto', 'crypto_btc', 'crypto_eth', 'crypto_usdt', 'crypto_usdc', 'apple_pay', 'google_pay', 'link', 'kofi', 'patreon') NOT NULL
        COMMENT 'Payment method used for this transaction';


-- ============================================================================
-- ⚙️ SETTINGS — Ko-fi Provider
-- ============================================================================

-- ☕ Ko-fi payment provider settings
-- Ko-fi uses a webhook-only flow: user pays on Ko-fi → webhook POSTs to SIGNula
-- There is NO client-side SDK or redirect-based checkout — all interaction happens
-- on Ko-fi's platform, and SIGNula receives a POST with a JSON-encoded `data` field
-- Verification uses a verification_token field in the POST body (NOT HMAC signature)
-- Ko-fi Gold accounts unlock recurring subscription/membership features
-- @see https://ko-fi.com/manage/webhooks — Ko-fi Webhook Configuration
-- @see https://help.ko-fi.com/hc/en-us/articles/115004002614 — Ko-fi API Overview
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `settingDescription`, `isSensitive`)
VALUES
    -- ☕ Ko-fi Provider — Core Settings
    -- These control whether Ko-fi is available as a payment/donation method
    ('payment.kofi.enabled', '0', 'payment', 'Enable Ko-fi as a payment/donation provider', 0),
    ('payment.kofi.verification_token', '', 'payment', 'Ko-fi webhook verification token (from Ko-fi dashboard > API > Webhooks)', 1),
    ('payment.kofi.page_url', '', 'payment', 'Ko-fi page URL for payment links (e.g. https://ko-fi.com/yourusername)', 0),
    ('payment.kofi.page_slug', '', 'payment', 'Ko-fi page slug / username for generating payment links', 0),
    ('payment.kofi.gold_enabled', '0', 'payment', 'Enable Ko-fi Gold membership/subscription features (requires Ko-fi Gold account)', 0),

    -- ☕ Ko-fi Provider — Webhook Configuration
    -- The webhook_url is auto-populated by the system when Ko-fi is configured
    -- log_all_events enables full event logging to tblInboundWebhooks for debugging
    ('payment.kofi.webhook_url', '', 'payment', 'Auto-populated: Full webhook URL for Ko-fi to POST events to', 0),
    ('payment.kofi.log_all_events', '1', 'payment', 'Log all Ko-fi webhook events to tblInboundWebhooks (recommended for debugging)', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);


-- ============================================================================
-- ⚙️ SETTINGS — Patreon Provider
-- ============================================================================

-- 🎨 Patreon payment/subscription provider settings
-- Patreon uses OAuth 2.0 for API access + HMAC-SHA256 webhook signatures
-- The Creator Access Token is used for server-to-server API calls (fetching pledges,
-- member data, tier info, etc.) while the Client ID/Secret pair handles the OAuth flow
-- for patron authentication and account linking
-- Webhook signatures use HMAC-SHA256 with the webhook_secret as the signing key
-- @see https://docs.patreon.com/#apiv2-oauth — Patreon OAuth 2.0
-- @see https://docs.patreon.com/#webhooks — Patreon Webhooks
-- @see https://www.patreon.com/portal/registration/register-clients — Patreon Platform Client Registration
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `settingDescription`, `isSensitive`)
VALUES
    -- 🎨 Patreon Provider — Core Settings
    -- These control whether Patreon is available as a subscription/pledge provider
    ('payment.patreon.enabled', '0', 'payment', 'Enable Patreon as a subscription/pledge provider', 0),
    ('payment.patreon.client_id', '', 'payment', 'Patreon OAuth 2.0 Client ID (from Patreon Platform > Clients)', 1),
    ('payment.patreon.client_secret', '', 'payment', 'Patreon OAuth 2.0 Client Secret', 1),
    ('payment.patreon.webhook_secret', '', 'payment', 'Patreon webhook signing secret for HMAC-SHA256 verification', 1),
    ('payment.patreon.campaign_id', '', 'payment', 'Patreon Campaign ID (found in campaign URL or via API)', 0),
    ('payment.patreon.creator_access_token', '', 'payment', 'Patreon Creator Access Token for API calls (from Patreon Platform)', 1),
    ('payment.patreon.creator_refresh_token', '', 'payment', 'Patreon Creator Refresh Token for refreshing access tokens', 1),

    -- 🎨 Patreon Provider — Tier Mapping
    -- Maps Patreon tier IDs to SIGNula subscription tier slugs for automatic tier assignment
    -- Example: {"12345": "basic", "67890": "premium"}
    ('payment.patreon.tier_mapping', '{}', 'payment', 'JSON mapping of Patreon tier IDs to SIGNula subscription tier slugs', 0),

    -- 🎨 Patreon Provider — Configuration
    -- The webhook_url is auto-populated by the system when Patreon is configured
    -- log_all_events enables full event logging to tblInboundWebhooks for debugging
    -- sync_members enables automatic patron-to-SIGNula user synchronisation
    ('payment.patreon.webhook_url', '', 'payment', 'Auto-populated: Full webhook URL for Patreon to POST events to', 0),
    ('payment.patreon.log_all_events', '1', 'payment', 'Log all Patreon webhook events to tblInboundWebhooks', 0),
    ('payment.patreon.sync_members', '1', 'payment', 'Automatically sync Patreon members/patrons with SIGNula users', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);


-- ============================================================================
-- 🎁 PROVIDER DISCOUNTS — Ko-fi & Patreon
-- ============================================================================

-- 💸 Default global provider discounts for Ko-fi and Patreon
-- Both inactive by default — admin can enable and set discount rates via the
-- payment settings admin panel once the providers are configured
-- @see tblProviderDiscounts created in Migration 012
-- @see Migration 012 for existing global discounts (crypto, paypal, stripe, etc.)
INSERT INTO `tblProviderDiscounts` (`scope`, `partnerID`, `paymentMethod`, `discountPercentage`, `description`, `isActive`)
VALUES
    ('global', NULL, 'kofi', 0.00, 'Ko-fi payment discount (disabled by default)', 0),
    ('global', NULL, 'patreon', 0.00, 'Patreon payment discount (disabled by default)', 0)
ON DUPLICATE KEY UPDATE `paymentMethod` = VALUES(`paymentMethod`);


-- ============================================================================
-- 🔀 FEATURE TOGGLES
-- ============================================================================

-- 🏷️ New feature toggles for Ko-fi and Patreon billing capabilities
-- These follow the same pattern as the 4 feature toggles added in Migration 012
-- Both default to disabled (isEnabledGlobally = 0) since credentials must be
-- configured before these providers can be used
-- Partners can individually toggle these for their own customers via canPartnersToggle = 1
-- @see tblFeatureToggles created in Migration 009
INSERT INTO `tblFeatureToggles` (`featureKey`, `featureName`, `featureDescription`, `isEnabledGlobally`, `category`, `requiresMigration`, `affectsAPI`, `canPartnersToggle`)
VALUES
    ('kofi_payments', 'Ko-fi Payments', 'Accept payments, donations, and subscriptions via Ko-fi', 0, 'billing', '013', 1, 1),
    ('patreon_payments', 'Patreon Subscriptions', 'Accept subscriptions and pledges via Patreon', 0, 'billing', '013', 1, 1)
ON DUPLICATE KEY UPDATE `featureKey` = VALUES(`featureKey`);


-- ============================================================================
-- 📧 EMAIL TEMPLATES
-- ============================================================================

-- 📨 Ko-fi and Patreon payment lifecycle email templates
-- Uses {{variableName}} syntax for variable substitution via EmailService
-- HTML structure follows the same pattern established in Migration 012:
--   - Outer div with font-family:Arial, max-width:600px, margin:0 auto
--   - h1 with contextually appropriate colour and emoji
--   - Greeting paragraph with user/patron display name
--   - Details table with padding:8px, border-bottom:1px solid #dee2e6
--   - Action button as inline-block anchor with border-radius:6px
--   - Footer note in muted grey (color:#6c757d, font-size:0.9em)
INSERT IGNORE INTO `tblEmailTemplates`
    (`templateKey`, `templateName`, `subject`, `bodyHTML`, `bodyText`, `category`, `trackingEnabled`, `requiredVariables`, `isActive`)
VALUES

-- ☕ Ko-fi donation received (sent when a Ko-fi one-off donation webhook arrives)
-- Triggered by Ko-fi webhook POST with type = "Donation"
-- @see https://ko-fi.com/manage/webhooks — Ko-fi Webhook Events
('kofi_donation_received', 'Ko-fi Donation Received',
'Ko-fi Donation Received — {{currency}} {{amount}} - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#ff5e5b;">☕ Ko-fi Donation Received</h1><p>Hi {{displayName}},</p><p>Great news! You have received a donation via Ko-fi.</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>From:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{donorName}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Amount:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{amount}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Date:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{donationDate}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Message:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{donorMessage}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Ko-fi Transaction ID:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{kofiTransactionID}}</td></tr></table><p><a href="{{dashboardURL}}" style="display:inline-block;padding:10px 24px;background:#ff5e5b;color:#fff;text-decoration:none;border-radius:6px;">View Donations Dashboard</a></p><p style="color:#6c757d;font-size:0.9em;">This notification was triggered by a Ko-fi webhook event. If you have questions, please contact support.</p></div>',
'Hi {{displayName}}, You received a Ko-fi donation of {{currency}} {{amount}} from {{donorName}} on {{donationDate}}. Message: {{donorMessage}}. Transaction ID: {{kofiTransactionID}}. View at: {{dashboardURL}}',
'transactional', 1,
'["displayName","donorName","currency","amount","donationDate","donorMessage","kofiTransactionID","dashboardURL"]',
1),

-- ☕ Ko-fi subscription started (sent when a Ko-fi Gold recurring subscription begins)
-- Triggered by Ko-fi webhook POST with type = "Subscription" and is_first_subscription_payment = true
-- Requires Ko-fi Gold account to be active (payment.kofi.gold_enabled = 1)
-- @see https://help.ko-fi.com/hc/en-us/articles/115004002614 — Ko-fi Gold Subscriptions
('kofi_subscription_started', 'Ko-fi Subscription Started',
'Ko-fi Subscription Started — {{currency}} {{amount}}/month - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#ff5e5b;">☕ Ko-fi Subscription Started</h1><p>Hi {{displayName}},</p><p>A new Ko-fi Gold subscription has been created!</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Subscriber:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{subscriberName}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Amount:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{amount}}/month</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Tier:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{tierName}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Start Date:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{subscriptionDate}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Ko-fi Transaction ID:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{kofiTransactionID}}</td></tr></table><p><a href="{{dashboardURL}}" style="display:inline-block;padding:10px 24px;background:#ff5e5b;color:#fff;text-decoration:none;border-radius:6px;">View Subscriptions Dashboard</a></p><p style="color:#6c757d;font-size:0.9em;">This subscription will auto-renew monthly via Ko-fi Gold. Manage subscriptions in your Ko-fi dashboard.</p></div>',
'Hi {{displayName}}, New Ko-fi subscription from {{subscriberName}} for {{currency}} {{amount}}/month ({{tierName}}). Started: {{subscriptionDate}}. Transaction ID: {{kofiTransactionID}}. View at: {{dashboardURL}}',
'transactional', 1,
'["displayName","subscriberName","currency","amount","tierName","subscriptionDate","kofiTransactionID","dashboardURL"]',
1),

-- 🎨 Patreon pledge created (sent when a new Patreon pledge/membership webhook arrives)
-- Triggered by Patreon webhook with event type "members:pledge:create"
-- The patron has started a new pledge/membership at a specific tier
-- @see https://docs.patreon.com/#webhooks — Patreon Webhook Events
-- @see https://docs.patreon.com/#pledge — Patreon Pledge Object
('patreon_pledge_created', 'Patreon Pledge Created',
'New Patreon Pledge — {{currency}} {{amount}}/month - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#f96854;">🎨 New Patreon Pledge</h1><p>Hi {{displayName}},</p><p>A new patron has pledged to your campaign on Patreon!</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Patron:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{patronName}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Pledge Amount:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{amount}}/month</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Tier:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{tierName}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Pledge Date:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{pledgeDate}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Patreon Member ID:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{patreonMemberID}}</td></tr></table><p><a href="{{dashboardURL}}" style="display:inline-block;padding:10px 24px;background:#f96854;color:#fff;text-decoration:none;border-radius:6px;">View Patreon Dashboard</a></p><p style="color:#6c757d;font-size:0.9em;">This notification was triggered by a Patreon webhook event (members:pledge:create). The patron''s SIGNula account has been automatically updated.</p></div>',
'Hi {{displayName}}, New Patreon pledge from {{patronName}} for {{currency}} {{amount}}/month ({{tierName}}). Pledge date: {{pledgeDate}}. Member ID: {{patreonMemberID}}. View at: {{dashboardURL}}',
'transactional', 1,
'["displayName","patronName","currency","amount","tierName","pledgeDate","patreonMemberID","dashboardURL"]',
1),

-- 🎨 Patreon pledge cancelled (sent when a patron cancels their pledge/membership)
-- Triggered by Patreon webhook with event type "members:pledge:delete"
-- The patron has cancelled their pledge — their access should be downgraded accordingly
-- @see https://docs.patreon.com/#webhooks — Patreon Webhook Events
('patreon_pledge_cancelled', 'Patreon Pledge Cancelled',
'Patreon Pledge Cancelled — {{patronName}} - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#6c757d;">🎨 Patreon Pledge Cancelled</h1><p>Hi {{displayName}},</p><p>A patron has cancelled their pledge to your Patreon campaign.</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Patron:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{patronName}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Previous Pledge:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{amount}}/month</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Previous Tier:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{tierName}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Cancellation Date:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{cancellationDate}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Access Expires:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{accessExpiryDate}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Patreon Member ID:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{patreonMemberID}}</td></tr></table><p><a href="{{dashboardURL}}" style="display:inline-block;padding:10px 24px;background:#6c757d;color:#fff;text-decoration:none;border-radius:6px;">View Patreon Dashboard</a></p><p style="color:#6c757d;font-size:0.9em;">The patron''s SIGNula subscription tier will be automatically downgraded when their access expires. No action is required unless you wish to reach out to the patron directly.</p></div>',
'Hi {{displayName}}, Patreon pledge cancelled by {{patronName}}. Previous: {{currency}} {{amount}}/month ({{tierName}}). Cancelled: {{cancellationDate}}. Access expires: {{accessExpiryDate}}. Member ID: {{patreonMemberID}}. View at: {{dashboardURL}}',
'transactional', 1,
'["displayName","patronName","currency","amount","tierName","cancellationDate","accessExpiryDate","patreonMemberID","dashboardURL"]',
1);


-- ============================================================================
-- 📋 MIGRATION TRACKING
-- ============================================================================

INSERT INTO `tblMigrations` (`migrationName`, `migrationVersion`, `description`)
VALUES ('013_kofi_patreon_providers', '2.5.0-beta',
    'Ko-fi and Patreon provider integration: expanded provider/payment ENUMs, '
    'settings for both providers, provider discounts, 2 feature toggles, '
    '4 email templates (donation received, subscription started, pledge created, pledge cancelled)')
ON DUPLICATE KEY UPDATE `migrationName` = VALUES(`migrationName`);


-- ============================================================================
-- END OF MIGRATION 013
-- ============================================================================
