-- ============================================================================
-- SIGNula Database Migration 011
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- Migration: 011_payment_providers.sql
-- Version: 2.3.0-beta
-- Date: 2026-02-11
-- Description: Payment provider integration — Stripe (with Link), PayPal, Coinbase
--
-- Features Added:
--   - Stripe payment provider settings (publishable key, secret key, webhook secret)
--   - Stripe Link accelerated checkout support
--   - 'link' added to paymentMethod ENUMs across payment tables
--   - Coinbase Commerce webhook secret and supported currencies settings
--   - Inbound webhook logging table (tblInboundWebhooks) for all providers
--
-- Dependencies:
--   - Migration 010 (tblPayments, tblSubscriptions, tblPaymentMethods must exist)
--
-- Supports: MySQL 8.0+, MariaDB 10.5+
-- ============================================================================


-- ============================================================================
-- ENUM UPDATES — Add 'link' to paymentMethod columns
-- ============================================================================

-- 💳 tblPayments — Add 'link' after 'stripe'
-- @see https://dev.mysql.com/doc/refman/8.0/en/alter-table.html
ALTER TABLE `tblPayments`
    MODIFY COLUMN `paymentMethod`
        ENUM('paypal', 'apple_pay', 'google_pay', 'crypto_btc', 'crypto_eth', 'crypto_usdt', 'stripe', 'link', 'manual')
        NOT NULL
        COMMENT 'Payment method used (link = Stripe Link accelerated checkout)';

-- 💳 tblSubscriptions — Add 'link' after 'stripe'
ALTER TABLE `tblSubscriptions`
    MODIFY COLUMN `paymentMethod`
        ENUM('paypal', 'apple_pay', 'google_pay', 'crypto', 'stripe', 'link', 'manual', 'free')
        NOT NULL DEFAULT 'free'
        COMMENT 'Payment method for subscription billing (link = Stripe Link)';

-- 💳 tblPaymentMethods — Add 'link' after 'stripe'
ALTER TABLE `tblPaymentMethods`
    MODIFY COLUMN `paymentMethod`
        ENUM('paypal', 'apple_pay', 'google_pay', 'crypto_btc', 'crypto_eth', 'crypto_usdt', 'stripe', 'link')
        NOT NULL
        COMMENT 'Stored payment method type (link = Stripe Link)';


-- ============================================================================
-- STRIPE SETTINGS
-- ============================================================================

-- 🔵 Stripe payment provider configuration
-- @see https://docs.stripe.com/api (Stripe API Reference)
-- @see https://docs.stripe.com/payments/link (Stripe Link Documentation)
-- @see https://docs.stripe.com/payments/payment-element (Payment Element — unified UI)
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `description`, `isSensitive`)
VALUES
    -- 🔵 Stripe Core Settings
    ('payment.stripe.enabled', '0', 'payment', 'Enable Stripe payment processing', 0),
    ('payment.stripe.mode', 'test', 'payment', 'Stripe mode: test or live', 0),
    ('payment.stripe.publishable_key', '', 'payment', 'Stripe publishable API key (pk_test_xxx or pk_live_xxx)', 1),
    ('payment.stripe.secret_key', '', 'payment', 'Stripe secret API key (sk_test_xxx or sk_live_xxx)', 1),
    ('payment.stripe.webhook_secret', '', 'payment', 'Stripe webhook endpoint signing secret (whsec_xxx)', 1),

    -- 🔗 Stripe Link — Accelerated Checkout
    -- Link saves payment details across Stripe merchants for faster checkout
    -- @see https://docs.stripe.com/payments/link
    ('payment.stripe.link_enabled', '1', 'payment', 'Enable Stripe Link accelerated checkout (auto-fill payment details)', 0),

    -- 💳 Stripe Payment Methods
    -- JSON array of enabled payment method types for Payment Element
    -- @see https://docs.stripe.com/payments/payment-methods/overview
    ('payment.stripe.payment_methods', '["card","link"]', 'payment', 'Enabled Stripe payment method types (JSON array: card, link, apple_pay, google_pay)', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);


-- ============================================================================
-- COINBASE COMMERCE ADDITIONAL SETTINGS
-- ============================================================================

-- 🟠 Coinbase Commerce webhook verification and currency support
-- @see https://docs.cdp.coinbase.com/commerce-onchain/docs/webhooks (Webhook Verification)
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `description`, `isSensitive`)
VALUES
    ('payment.crypto.webhook_secret', '', 'payment', 'Coinbase Commerce webhook shared secret for signature verification', 1),
    ('payment.crypto.supported_currencies', '["BTC","ETH","USDT","USDC"]', 'payment', 'Supported cryptocurrency types (JSON array)', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);


-- ============================================================================
-- INBOUND WEBHOOK LOG TABLE
-- ============================================================================

-- 📥 Inbound webhook events from external payment providers
-- Logs every incoming webhook for auditing, debugging, and replay capability
-- All three providers (Stripe, PayPal, Coinbase) log here before processing
-- @see https://docs.stripe.com/webhooks (Stripe Webhooks)
-- @see https://developer.paypal.com/docs/api-basics/notifications/webhooks/ (PayPal Webhooks)
-- @see https://docs.cdp.coinbase.com/commerce-onchain/docs/webhooks (Coinbase Webhooks)
CREATE TABLE IF NOT EXISTS `tblInboundWebhooks` (
    `webhookID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique webhook log entry ID',

    `provider` VARCHAR(50) NOT NULL
        COMMENT 'Payment provider name (stripe, paypal, coinbase)',

    `eventType` VARCHAR(100) NOT NULL
        COMMENT 'Provider event type (e.g. checkout.session.completed, PAYMENT.CAPTURE.COMPLETED)',

    `eventID` VARCHAR(255) NOT NULL
        COMMENT 'Provider-assigned unique event identifier for idempotency checks',

    `payload` JSON NOT NULL
        COMMENT 'Full JSON payload received from provider',

    `signature` VARCHAR(512) DEFAULT NULL
        COMMENT 'Signature header value received (for audit trail)',

    `verified` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Whether signature verification passed (1=verified, 0=failed/skipped)',

    `status` ENUM('pending', 'processed', 'failed', 'ignored', 'duplicate') NOT NULL DEFAULT 'pending'
        COMMENT 'Processing status of this webhook event',

    `processedAt` DATETIME DEFAULT NULL
        COMMENT 'When the webhook was successfully processed',

    `errorMessage` VARCHAR(1000) DEFAULT NULL
        COMMENT 'Error description if processing failed',

    `httpStatusReturned` SMALLINT UNSIGNED DEFAULT NULL
        COMMENT 'HTTP status code we returned to the provider',

    `processingTimeMs` INT UNSIGNED DEFAULT NULL
        COMMENT 'Time taken to process the webhook in milliseconds',

    `ipAddress` VARCHAR(45) NOT NULL
        COMMENT 'IP address of the webhook sender (IPv4 or IPv6)',

    `userAgent` VARCHAR(500) DEFAULT NULL
        COMMENT 'User-Agent header from the webhook request',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When the webhook was received',

    PRIMARY KEY (`webhookID`),
    INDEX `idx_inbound_wh_provider` (`provider`),
    INDEX `idx_inbound_wh_event_type` (`eventType`),
    INDEX `idx_inbound_wh_event_id` (`eventID`),
    INDEX `idx_inbound_wh_status` (`status`),
    INDEX `idx_inbound_wh_verified` (`verified`),
    INDEX `idx_inbound_wh_created` (`createdAt`),
    INDEX `idx_inbound_wh_provider_event` (`provider`, `eventID`)
        COMMENT 'Composite index for duplicate detection'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Inbound webhook event log from payment providers (Stripe, PayPal, Coinbase)';

-- 🧹 Scheduled cleanup for old processed webhooks (retain 90 days)
-- @see https://dev.mysql.com/doc/refman/8.0/en/create-event.html
CREATE EVENT IF NOT EXISTS `evt_cleanup_inbound_webhooks`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
    DELETE FROM `tblInboundWebhooks`
    WHERE `createdAt` < DATE_SUB(NOW(), INTERVAL 90 DAY)
    AND `status` IN ('processed', 'ignored', 'duplicate');


-- ============================================================================
-- MIGRATION TRACKING
-- ============================================================================

-- [mig-fix] removed incompatible tblMigrations self-bookkeeping (the migration runner records migrations)

-- ============================================================================
-- END OF MIGRATION 011
-- ============================================================================
