-- ============================================================================
-- SIGNula Database Migration 010
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- Migration: 010_webhooks_and_payments.sql
-- Version: 2.3.0-beta
-- Date: 2026-02-11
-- Description: Adds outbound webhook signature system and payment/subscription tables
--
-- Features Added:
--   - Outbound webhook delivery with HMAC-SHA256 signatures
--   - Webhook endpoint configuration per partner
--   - Webhook delivery log with retry tracking
--   - Subscription tier definitions
--   - User subscriptions with billing cycles
--   - Payment transaction records
--   - Stored payment methods (tokenised)
--   - Payment discount rules
--
-- Supports: MySQL 8.0+, MariaDB 10.5+
-- ============================================================================

-- ============================================================================
-- WEBHOOK SIGNATURE SYSTEM
-- ============================================================================

-- Webhook endpoint configuration per partner
-- Partners register URLs to receive event notifications
-- Each endpoint has its own HMAC secret for signature verification
CREATE TABLE IF NOT EXISTS `tblWebhookEndpoints` (
    `endpointID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `partnerID` BIGINT UNSIGNED NOT NULL,
    `url` VARCHAR(2048) NOT NULL COMMENT 'HTTPS URL to deliver webhooks to',
    `description` VARCHAR(255) DEFAULT NULL COMMENT 'Human-readable description of this endpoint',
    `secret` VARCHAR(128) NOT NULL COMMENT 'HMAC-SHA256 signing secret (encrypted at rest)',
    `status` ENUM('active', 'paused', 'disabled', 'failed') NOT NULL DEFAULT 'active',
    `events` JSON NOT NULL COMMENT 'Array of subscribed event types',
    `headers` JSON DEFAULT NULL COMMENT 'Custom headers to include in deliveries',
    `retryPolicy` JSON DEFAULT NULL COMMENT 'Custom retry config (maxRetries, backoffSeconds)',
    `rateLimitPerMinute` INT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Max deliveries per minute',
    `timeoutSeconds` INT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'HTTP request timeout',
    `lastDeliveryAt` DATETIME DEFAULT NULL,
    `lastSuccessAt` DATETIME DEFAULT NULL,
    `lastFailureAt` DATETIME DEFAULT NULL,
    `failureCount` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Consecutive failures (resets on success)',
    `totalDeliveries` INT UNSIGNED NOT NULL DEFAULT 0,
    `totalSuccesses` INT UNSIGNED NOT NULL DEFAULT 0,
    `totalFailures` INT UNSIGNED NOT NULL DEFAULT 0,
    `disabledReason` VARCHAR(255) DEFAULT NULL COMMENT 'Reason endpoint was auto-disabled',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`endpointID`),
    INDEX `idx_webhook_partner` (`partnerID`),
    INDEX `idx_webhook_status` (`status`),
    INDEX `idx_webhook_last_delivery` (`lastDeliveryAt`),
    CONSTRAINT `fk_webhook_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner webhook endpoint configuration with HMAC-SHA256 signing';

-- Webhook delivery log
-- Tracks every outbound webhook attempt for auditing and debugging
CREATE TABLE IF NOT EXISTS `tblWebhookDeliveries` (
    `deliveryID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `endpointID` BIGINT UNSIGNED NOT NULL,
    `partnerID` BIGINT UNSIGNED NOT NULL,
    `eventType` VARCHAR(100) NOT NULL COMMENT 'Event type (e.g. user.created, payment.completed)',
    `eventID` VARCHAR(64) NOT NULL COMMENT 'Unique event identifier for idempotency',
    `payload` JSON NOT NULL COMMENT 'JSON payload delivered',
    `signatureHeader` VARCHAR(255) NOT NULL COMMENT 'X-SIGNula-Signature header value sent',
    `httpStatusCode` SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Response HTTP status code',
    `responseBody` TEXT DEFAULT NULL COMMENT 'Response body (truncated to 4KB)',
    `responseTimeMs` INT UNSIGNED DEFAULT NULL COMMENT 'Response time in milliseconds',
    `status` ENUM('pending', 'delivered', 'failed', 'retrying') NOT NULL DEFAULT 'pending',
    `attemptNumber` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `maxAttempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `nextRetryAt` DATETIME DEFAULT NULL COMMENT 'When to retry (exponential backoff)',
    `errorMessage` VARCHAR(500) DEFAULT NULL COMMENT 'Error description on failure',
    `deliveredAt` DATETIME DEFAULT NULL,
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`deliveryID`),
    INDEX `idx_delivery_endpoint` (`endpointID`),
    INDEX `idx_delivery_partner` (`partnerID`),
    INDEX `idx_delivery_event_type` (`eventType`),
    INDEX `idx_delivery_event_id` (`eventID`),
    INDEX `idx_delivery_status` (`status`),
    INDEX `idx_delivery_retry` (`status`, `nextRetryAt`),
    INDEX `idx_delivery_created` (`createdAt`),
    CONSTRAINT `fk_delivery_endpoint` FOREIGN KEY (`endpointID`)
        REFERENCES `tblWebhookEndpoints` (`endpointID`) ON DELETE CASCADE,
    CONSTRAINT `fk_delivery_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Outbound webhook delivery log with retry tracking';

-- Default webhook event types in tblSettings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `description`, `isSensitive`)
VALUES
    ('webhook.enabled', '1', 'webhooks', 'Enable outbound webhook system', 0),
    ('webhook.max_endpoints_per_partner', '10', 'webhooks', 'Maximum webhook endpoints per partner', 0),
    ('webhook.default_max_retries', '5', 'webhooks', 'Default maximum delivery retries', 0),
    ('webhook.retry_backoff_base', '60', 'webhooks', 'Base retry backoff in seconds (exponential)', 0),
    ('webhook.auto_disable_threshold', '50', 'webhooks', 'Consecutive failures before auto-disabling endpoint', 0),
    ('webhook.delivery_retention_days', '30', 'webhooks', 'Days to retain delivery logs', 0),
    ('webhook.request_timeout', '30', 'webhooks', 'Default HTTP request timeout in seconds', 0),
    ('webhook.supported_events', '["user.created","user.updated","user.deleted","user.login","user.logout","user.mfa_enabled","user.mfa_disabled","subscription.created","subscription.updated","subscription.cancelled","subscription.renewed","payment.completed","payment.failed","payment.refunded","partner.api_key_created","partner.api_key_revoked"]', 'webhooks', 'List of supported webhook event types (JSON)', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);

-- Scheduled event to clean up old delivery logs
-- https://dev.mysql.com/doc/refman/8.0/en/create-event.html
CREATE EVENT IF NOT EXISTS `evt_cleanup_webhook_deliveries`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
    DELETE FROM `tblWebhookDeliveries`
    WHERE `createdAt` < DATE_SUB(NOW(), INTERVAL 30 DAY)
    AND `status` IN ('delivered', 'failed');


-- ============================================================================
-- SUBSCRIPTION TIERS
-- ============================================================================

-- Subscription tier definitions
-- Defines available pricing tiers for services
CREATE TABLE IF NOT EXISTS `tblSubscriptionTiers` (
    `tierID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tierName` VARCHAR(100) NOT NULL COMMENT 'Display name (e.g. Free, Basic, Premium, Enterprise)',
    `tierSlug` VARCHAR(50) NOT NULL COMMENT 'URL-safe identifier (e.g. free, basic, premium)',
    `tierDescription` TEXT DEFAULT NULL COMMENT 'Description of tier benefits',
    `monthlyPrice` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Monthly price in default currency',
    `yearlyPrice` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Yearly price (discounted)',
    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP' COMMENT 'ISO 4217 currency code',
    `features` JSON NOT NULL COMMENT 'Array of included feature strings',
    `featureLimits` JSON DEFAULT NULL COMMENT 'Object of numeric limits (e.g. {"api_calls": 1000})',
    `teamMemberLimit` INT UNSIGNED DEFAULT NULL COMMENT 'Max team members (NULL = unlimited)',
    `apiCallsPerMonth` INT UNSIGNED DEFAULT NULL COMMENT 'Monthly API call limit (NULL = unlimited)',
    `storageGB` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Storage allocation in GB',
    `displayOrder` INT NOT NULL DEFAULT 0 COMMENT 'Sort order for pricing page',
    `isActive` TINYINT(1) NOT NULL DEFAULT 1,
    `isDefault` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Default tier for new users',
    `trialDays` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Free trial period in days (0 = no trial)',
    `badge` VARCHAR(50) DEFAULT NULL COMMENT 'Badge text (e.g. Popular, Best Value)',
    `badgeColor` VARCHAR(20) DEFAULT NULL COMMENT 'Badge CSS colour class',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`tierID`),
    UNIQUE KEY `uk_tier_slug` (`tierSlug`),
    INDEX `idx_tier_active` (`isActive`, `displayOrder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Subscription tier definitions with pricing and feature limits';

-- Default subscription tiers
INSERT INTO `tblSubscriptionTiers` (`tierName`, `tierSlug`, `tierDescription`, `monthlyPrice`, `yearlyPrice`, `currency`, `features`, `featureLimits`, `teamMemberLimit`, `apiCallsPerMonth`, `displayOrder`, `isActive`, `isDefault`, `trialDays`, `badge`, `badgeColor`)
VALUES
    ('Free', 'free', 'Get started with essential features at no cost', 0.00, 0.00, 'GBP', '["Basic authentication","Single sign-on","5 team members","1,000 API calls/month","Community support"]', '{"api_calls": 1000, "team_members": 5, "storage_gb": 1}', 5, 1000, 1, 1, 1, 0, NULL, NULL),
    ('Basic', 'basic', 'Perfect for small teams and growing businesses', 9.99, 99.99, 'GBP', '["Everything in Free","MFA enforcement","10 team members","10,000 API calls/month","Email support","Custom branding","Webhook integrations"]', '{"api_calls": 10000, "team_members": 10, "storage_gb": 10}', 10, 10000, 2, 1, 0, 14, NULL, NULL),
    ('Premium', 'premium', 'Advanced features for scaling organisations', 29.99, 299.99, 'GBP', '["Everything in Basic","25 team members","50,000 API calls/month","Priority support","Advanced analytics","SSO enforcement","Custom OAuth providers","Audit log export"]', '{"api_calls": 50000, "team_members": 25, "storage_gb": 50}', 25, 50000, 3, 1, 0, 14, 'Popular', 'primary'),
    ('Enterprise', 'enterprise', 'Unlimited access with dedicated support and SLAs', 99.99, 999.99, 'GBP', '["Everything in Premium","Unlimited team members","Unlimited API calls","Dedicated support","Custom SLAs","On-premise deployment option","SAML/SCIM integration","Custom contracts"]', '{"api_calls": null, "team_members": null, "storage_gb": null}', NULL, NULL, 4, 1, 0, 30, 'Best Value', 'success');


-- ============================================================================
-- SUBSCRIPTIONS
-- ============================================================================

-- User/partner subscriptions
CREATE TABLE IF NOT EXISTS `tblSubscriptions` (
    `subscriptionID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Account owner',
    `partnerID` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Partner organisation (if applicable)',
    `tierID` BIGINT UNSIGNED NOT NULL,
    `subscriptionStatus` ENUM('active', 'cancelled', 'expired', 'paused', 'trial', 'pending', 'past_due') NOT NULL DEFAULT 'pending',
    `billingCycle` ENUM('monthly', 'quarterly', 'yearly', 'lifetime', 'one_time') NOT NULL DEFAULT 'monthly',
    `amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Current billing amount',
    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP',
    `paymentMethod` ENUM('paypal', 'apple_pay', 'google_pay', 'crypto', 'stripe', 'manual', 'free') NOT NULL DEFAULT 'free',
    `paymentProviderSubscriptionID` VARCHAR(255) DEFAULT NULL COMMENT 'External subscription ID from payment provider',
    `startDate` DATE NOT NULL,
    `endDate` DATE DEFAULT NULL COMMENT 'NULL for active recurring subscriptions',
    `currentPeriodStart` DATE NOT NULL,
    `currentPeriodEnd` DATE NOT NULL,
    `nextBillingDate` DATE DEFAULT NULL,
    `trialEndsAt` DATETIME DEFAULT NULL,
    `autoRenew` TINYINT(1) NOT NULL DEFAULT 1,
    `cancelledAt` DATETIME DEFAULT NULL,
    `cancellationReason` TEXT DEFAULT NULL,
    `pausedAt` DATETIME DEFAULT NULL,
    `resumesAt` DATETIME DEFAULT NULL,
    `metadata` JSON DEFAULT NULL COMMENT 'Additional subscription metadata',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`subscriptionID`),
    INDEX `idx_sub_user` (`userID`),
    INDEX `idx_sub_partner` (`partnerID`),
    INDEX `idx_sub_tier` (`tierID`),
    INDEX `idx_sub_status` (`subscriptionStatus`),
    INDEX `idx_sub_billing_date` (`nextBillingDate`),
    INDEX `idx_sub_trial_end` (`trialEndsAt`),
    INDEX `idx_sub_provider_id` (`paymentProviderSubscriptionID`),
    CONSTRAINT `fk_sub_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_sub_tier` FOREIGN KEY (`tierID`)
        REFERENCES `tblSubscriptionTiers` (`tierID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User and partner subscriptions with billing cycle management';


-- ============================================================================
-- PAYMENTS
-- ============================================================================

-- Payment transaction records
CREATE TABLE IF NOT EXISTS `tblPayments` (
    `paymentID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` BIGINT UNSIGNED NOT NULL,
    `subscriptionID` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Linked subscription (NULL for one-off payments)',
    `transactionID` VARCHAR(255) DEFAULT NULL COMMENT 'External transaction ID from payment provider',
    `paymentMethod` ENUM('paypal', 'apple_pay', 'google_pay', 'crypto_btc', 'crypto_eth', 'crypto_usdt', 'stripe', 'manual') NOT NULL,
    `paymentProvider` VARCHAR(100) NOT NULL COMMENT 'Provider name (PayPal, Stripe, Coinbase, etc.)',
    `amount` DECIMAL(10, 2) NOT NULL,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP',
    `exchangeRate` DECIMAL(16, 8) DEFAULT NULL COMMENT 'Exchange rate if paid in different currency',
    `originalAmount` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Amount in original currency if converted',
    `originalCurrency` VARCHAR(3) DEFAULT NULL,
    `discountAmount` DECIMAL(10, 2) DEFAULT 0.00 COMMENT 'Discount applied',
    `discountCode` VARCHAR(50) DEFAULT NULL COMMENT 'Discount/promo code used',
    `taxAmount` DECIMAL(10, 2) DEFAULT 0.00 COMMENT 'Tax/VAT amount',
    `taxRate` DECIMAL(5, 2) DEFAULT 0.00 COMMENT 'Tax rate percentage',
    `netAmount` DECIMAL(10, 2) NOT NULL COMMENT 'Amount after tax and discounts',
    `status` ENUM('pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled', 'disputed') NOT NULL DEFAULT 'pending',
    `description` VARCHAR(500) DEFAULT NULL,
    `paymentData` JSON DEFAULT NULL COMMENT 'Provider-specific payment data (encrypted sensitive fields)',
    `billingAddress` JSON DEFAULT NULL COMMENT 'Billing address snapshot',
    `ipAddress` VARCHAR(45) NOT NULL COMMENT 'IPv4 or IPv6 address',
    `userAgent` VARCHAR(500) DEFAULT NULL,
    `paidAt` DATETIME DEFAULT NULL,
    `refundedAt` DATETIME DEFAULT NULL,
    `refundAmount` DECIMAL(10, 2) DEFAULT NULL,
    `refundReason` TEXT DEFAULT NULL,
    `failureReason` VARCHAR(500) DEFAULT NULL COMMENT 'Reason for payment failure',
    `receiptURL` VARCHAR(2048) DEFAULT NULL COMMENT 'URL to payment receipt',
    `invoiceNumber` VARCHAR(50) DEFAULT NULL COMMENT 'Internal invoice number',
    `metadata` JSON DEFAULT NULL,
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`paymentID`),
    UNIQUE KEY `uk_transaction_id` (`transactionID`),
    INDEX `idx_payment_user` (`userID`),
    INDEX `idx_payment_subscription` (`subscriptionID`),
    INDEX `idx_payment_status` (`status`),
    INDEX `idx_payment_method` (`paymentMethod`),
    INDEX `idx_payment_provider` (`paymentProvider`),
    INDEX `idx_payment_date` (`paidAt`),
    INDEX `idx_payment_invoice` (`invoiceNumber`),
    CONSTRAINT `fk_payment_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_payment_subscription` FOREIGN KEY (`subscriptionID`)
        REFERENCES `tblSubscriptions` (`subscriptionID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Payment transaction records with multi-provider support';

-- Stored payment methods (tokenised - never stores raw card numbers)
CREATE TABLE IF NOT EXISTS `tblPaymentMethods` (
    `methodID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID` BIGINT UNSIGNED NOT NULL,
    `paymentMethod` ENUM('paypal', 'apple_pay', 'google_pay', 'crypto_btc', 'crypto_eth', 'crypto_usdt', 'stripe') NOT NULL,
    `provider` VARCHAR(100) NOT NULL COMMENT 'Payment provider name',
    `providerMethodID` VARCHAR(255) DEFAULT NULL COMMENT 'Provider token/method ID',
    `displayName` VARCHAR(100) NOT NULL COMMENT 'User-friendly name (e.g. PayPal - john@example.com)',
    `lastFour` VARCHAR(4) DEFAULT NULL COMMENT 'Last 4 digits (if applicable)',
    `expiryMonth` TINYINT UNSIGNED DEFAULT NULL,
    `expiryYear` SMALLINT UNSIGNED DEFAULT NULL,
    `brand` VARCHAR(50) DEFAULT NULL COMMENT 'Card brand or wallet type',
    `billingEmail` VARCHAR(320) DEFAULT NULL COMMENT 'Billing email for this method',
    `isDefault` TINYINT(1) NOT NULL DEFAULT 0,
    `isVerified` TINYINT(1) NOT NULL DEFAULT 0,
    `metadata` JSON DEFAULT NULL COMMENT 'Additional provider-specific data (encrypted)',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`methodID`),
    INDEX `idx_pm_user` (`userID`),
    INDEX `idx_pm_provider` (`provider`),
    INDEX `idx_pm_default` (`userID`, `isDefault`),
    CONSTRAINT `fk_pm_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stored payment methods (tokenised, never stores raw credentials)';

-- Discount/promo codes
CREATE TABLE IF NOT EXISTS `tblDiscountCodes` (
    `discountID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL COMMENT 'Unique promo code',
    `description` VARCHAR(255) DEFAULT NULL,
    `discountType` ENUM('percentage', 'fixed_amount', 'free_trial_days') NOT NULL DEFAULT 'percentage',
    `discountValue` DECIMAL(10, 2) NOT NULL COMMENT 'Percentage (0-100) or fixed amount',
    `currency` VARCHAR(3) DEFAULT 'GBP' COMMENT 'Currency for fixed amount discounts',
    `applicableTiers` JSON DEFAULT NULL COMMENT 'Array of tier slugs (NULL = all tiers)',
    `applicablePaymentMethods` JSON DEFAULT NULL COMMENT 'Array of payment methods (NULL = all)',
    `minAmount` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Minimum purchase amount to apply',
    `maxUses` INT UNSIGNED DEFAULT NULL COMMENT 'Total redemption limit (NULL = unlimited)',
    `maxUsesPerUser` INT UNSIGNED DEFAULT 1 COMMENT 'Per-user redemption limit',
    `currentUses` INT UNSIGNED NOT NULL DEFAULT 0,
    `validFrom` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `validUntil` DATETIME DEFAULT NULL COMMENT 'NULL = no expiry',
    `isActive` TINYINT(1) NOT NULL DEFAULT 1,
    `createdBy` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Admin who created this code',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`discountID`),
    UNIQUE KEY `uk_discount_code` (`code`),
    INDEX `idx_discount_active` (`isActive`, `validFrom`, `validUntil`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Discount and promotional codes for payment discounts';

-- Payment settings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `description`, `isSensitive`)
VALUES
    ('payment.enabled', '0', 'payment', 'Enable payment processing (set to 1 when ready)', 0),
    ('payment.default_currency', 'GBP', 'payment', 'Default currency (ISO 4217)', 0),
    ('payment.tax_rate', '20.00', 'payment', 'Default VAT/tax rate percentage', 0),
    ('payment.tax_enabled', '1', 'payment', 'Enable tax calculation on payments', 0),
    ('payment.paypal.enabled', '0', 'payment', 'Enable PayPal payments', 0),
    ('payment.paypal.client_id', '', 'payment', 'PayPal REST API Client ID', 1),
    ('payment.paypal.client_secret', '', 'payment', 'PayPal REST API Client Secret', 1),
    ('payment.paypal.mode', 'sandbox', 'payment', 'PayPal mode: sandbox or live', 0),
    ('payment.paypal.webhook_id', '', 'payment', 'PayPal Webhook ID for event verification', 1),
    ('payment.apple_pay.enabled', '0', 'payment', 'Enable Apple Pay', 0),
    ('payment.apple_pay.merchant_id', '', 'payment', 'Apple Pay Merchant ID', 1),
    ('payment.google_pay.enabled', '0', 'payment', 'Enable Google Pay', 0),
    ('payment.google_pay.merchant_id', '', 'payment', 'Google Pay Merchant ID', 1),
    ('payment.crypto.enabled', '0', 'payment', 'Enable cryptocurrency payments', 0),
    ('payment.crypto.discount_percent', '5.00', 'payment', 'Discount percentage for crypto payments', 0),
    ('payment.crypto.provider', 'coinbase', 'payment', 'Crypto payment provider (coinbase, btcpay)', 0),
    ('payment.crypto.api_key', '', 'payment', 'Crypto provider API key', 1),
    ('payment.invoice_prefix', 'SIG', 'payment', 'Invoice number prefix', 0),
    ('payment.receipt_email', '1', 'payment', 'Send receipt emails after successful payment', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);

-- ============================================================================
-- MIGRATION TRACKING
-- ============================================================================

-- [mig-fix] removed incompatible tblMigrations self-bookkeeping (the migration runner records migrations)

-- ============================================================================
