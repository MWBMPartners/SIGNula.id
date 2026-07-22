-- ============================================================================
-- 🔗 Migration 024: Webhook System
-- ============================================================================
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- Migration: 024_webhook_system.sql
-- Version: 2.7.0-beta
-- Date: 2026-03-08
-- Description: Creates the user/partner webhook subscription system with
--              delivery tracking, retry management, and HMAC-SHA256 signing.
--
-- This is SEPARATE from the existing partner-facing webhook system in
-- migration 010 (tblWebhookEndpoints / tblWebhookDeliveries used by
-- WebhookManager.php). This new system provides a self-service webhook
-- UI for admins and partners to subscribe to SIGNula events.
--
-- Tables Added:
--   - tblWebhooks          : Webhook subscription configuration
--   - tblWebhookDeliveryLog : Delivery attempt tracking with retry support
--
-- Settings Added:
--   - webhooks.enabled                : Master toggle for webhook dispatching
--   - webhooks.max_per_partner        : Maximum webhooks per partner
--   - webhooks.default_timeout        : Default HTTP timeout in seconds
--   - webhooks.max_retries            : Maximum delivery retry attempts
--   - webhooks.delivery_retention_days: Days to retain delivery log entries
--   - webhooks.require_https          : Require HTTPS for webhook URLs
--
-- Supports: MySQL 8.0+, MariaDB 10.5+
-- ============================================================================

-- 🔒 Record migration start
-- [mig-fix] removed incompatible tblMigrations self-bookkeeping (the migration runner records migrations)

-- ============================================================================
-- 📋 Table: tblWebhooks
-- ============================================================================
-- Stores webhook subscription configurations. Each webhook subscribes to one
-- or more event types and receives signed HTTP POST deliveries to its URL.
--
-- The `secret` column stores an AES-256-CBC encrypted HMAC signing key.
-- Recipients verify authenticity by computing HMAC-SHA256 over the payload
-- using the shared secret and comparing with the X-SIGNula-Signature header.
--
-- @see https://docs.signula.id/webhooks - Partner webhook documentation
-- @see https://www.php.net/manual/en/function.hash-hmac.php

CREATE TABLE IF NOT EXISTS `tblWebhooks` (
    -- 🔑 Primary key
    `webhookID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Auto-incrementing unique webhook identifier',

    -- 🏢 Ownership (at least one of partnerID or userID should be set)
    `partnerID` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Partner who owns this webhook (NULL for admin-created)',
    `userID` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'User who created this webhook (NULL for system webhooks)',

    -- 📝 Configuration
    `name` VARCHAR(100) NOT NULL
        COMMENT 'Human-readable name for this webhook subscription',
    `url` VARCHAR(500) NOT NULL
        COMMENT 'HTTPS endpoint URL to receive webhook deliveries',
    `secret` VARCHAR(500) NOT NULL
        COMMENT 'Encrypted HMAC-SHA256 signing secret (AES-256-CBC via SecurityUtils)',
    `events` JSON NOT NULL
        COMMENT 'JSON array of subscribed event types (e.g. ["user.login","user.registered"])',
    `isActive` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Whether this webhook is currently active (1=yes, 0=no)',
    `headers` JSON NULL DEFAULT NULL
        COMMENT 'Optional custom HTTP headers to include in deliveries (key-value pairs)',

    -- ⚙️ Delivery settings
    `retryCount` INT UNSIGNED NOT NULL DEFAULT 3
        COMMENT 'Maximum number of retry attempts for failed deliveries',
    `timeoutSeconds` INT UNSIGNED NOT NULL DEFAULT 10
        COMMENT 'HTTP request timeout in seconds',

    -- 📊 Status tracking
    `lastTriggeredAt` DATETIME NULL DEFAULT NULL
        COMMENT 'Timestamp of the last event dispatch to this webhook',
    `failureCount` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Consecutive failure count (resets on successful delivery)',

    -- 🕐 Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'Timestamp when this webhook was created',
    `updatedAt` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        COMMENT 'Timestamp of the last update to this webhook configuration',

    -- 🔑 Keys and constraints
    PRIMARY KEY (`webhookID`),
    CONSTRAINT `fk_wh_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    INDEX `idx_wh_partner` (`partnerID`),
    INDEX `idx_wh_active` (`isActive`),
    INDEX `idx_wh_created` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Webhook subscription configuration for event notifications';


-- ============================================================================
-- 📋 Table: tblWebhookDeliveryLog
-- ============================================================================
-- Tracks every webhook delivery attempt including payload, response, timing,
-- and retry scheduling. Used for debugging, auditing, and retry management.
--
-- Each delivery has a UUID for external tracking via the X-SIGNula-Delivery
-- header. Failed deliveries are retried with exponential backoff until
-- max attempts are reached.
--
-- @see https://en.wikipedia.org/wiki/Exponential_backoff

CREATE TABLE IF NOT EXISTS `tblWebhookDeliveryLog` (
    -- 🔑 Primary key
    `deliveryID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Auto-incrementing unique delivery identifier',

    -- 🔗 Foreign key to parent webhook
    `webhookID` BIGINT UNSIGNED NOT NULL
        COMMENT 'Reference to tblWebhooks.webhookID',

    -- 🆔 Tracking
    `deliveryUUID` CHAR(36) NOT NULL
        COMMENT 'UUID v4 for external tracking via X-SIGNula-Delivery header (RFC 4122)',

    -- 📨 Event details
    `event` VARCHAR(100) NOT NULL
        COMMENT 'Event type that triggered this delivery (e.g. user.login)',
    `payload` JSON NOT NULL
        COMMENT 'Full JSON payload sent to the webhook endpoint',

    -- 📡 Response tracking
    `responseCode` SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'HTTP response status code (NULL if request failed before response)',
    `responseBody` TEXT NULL DEFAULT NULL
        COMMENT 'Response body from the endpoint (truncated to 1000 chars)',
    `duration` DECIMAL(8,3) NULL DEFAULT NULL
        COMMENT 'Request duration in seconds (e.g. 0.245 = 245ms)',

    -- 📊 Status and retry management
    `status` ENUM('pending', 'delivered', 'failed', 'retrying') NOT NULL DEFAULT 'pending'
        COMMENT 'Current delivery status',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of delivery attempts made so far',
    `nextRetry` DATETIME NULL DEFAULT NULL
        COMMENT 'Scheduled time for next retry attempt (NULL if not retrying)',
    `deliveredAt` DATETIME NULL DEFAULT NULL
        COMMENT 'Timestamp when delivery was successfully completed',
    `errorMessage` VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Error message from the last failed attempt',

    -- 🕐 Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'Timestamp when this delivery record was created',

    -- 🔑 Keys and constraints
    PRIMARY KEY (`deliveryID`),
    CONSTRAINT `fk_del_webhook` FOREIGN KEY (`webhookID`)
        REFERENCES `tblWebhooks` (`webhookID`) ON DELETE CASCADE,
    INDEX `idx_del_webhook_status` (`webhookID`, `status`),
    INDEX `idx_del_next_retry` (`status`, `nextRetry`),
    INDEX `idx_del_event` (`event`),
    INDEX `idx_del_created` (`createdAt`),
    UNIQUE INDEX `idx_del_uuid` (`deliveryUUID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Webhook delivery attempt log with retry tracking';


-- ============================================================================
-- ⚙️ Settings: Webhook System Configuration
-- ============================================================================
-- All webhook behaviour is controlled via tblSettings for runtime flexibility
-- without code changes. Settings can be modified via the admin UI.
--
-- @see https://docs.signula.id/admin/settings - Settings documentation

INSERT INTO tblSettings (settingKey, settingValue, settingType, settingCategory, description, isEditable)
VALUES
    -- 🔘 Master toggle — when disabled, dispatch() silently skips all deliveries
    ('webhooks.enabled', '1', 'boolean', 'webhooks', 'Enable or disable the entire webhook dispatch system', 0),

    -- 📊 Limits — prevent abuse by capping webhooks per partner
    ('webhooks.max_per_partner', '10', 'integer', 'webhooks', 'Maximum number of webhook subscriptions per partner', 0),

    -- ⏱️ Timeout — HTTP request timeout for webhook deliveries
    ('webhooks.default_timeout', '10', 'integer', 'webhooks', 'Default HTTP request timeout in seconds for webhook deliveries', 0),

    -- 🔄 Retries — maximum retry attempts before marking delivery as failed
    ('webhooks.max_retries', '3', 'integer', 'webhooks', 'Maximum number of retry attempts for failed webhook deliveries', 0),

    -- 🗑️ Retention — automatic cleanup of old delivery log entries
    ('webhooks.delivery_retention_days', '30', 'integer', 'webhooks', 'Number of days to retain webhook delivery log entries before cleanup', 0),

    -- 🔒 HTTPS requirement — enforce HTTPS for all webhook endpoint URLs
    ('webhooks.require_https', '1', 'boolean', 'webhooks', 'Require HTTPS protocol for all webhook endpoint URLs', 0)
ON DUPLICATE KEY UPDATE settingKey = settingKey;


-- ✅ Record migration completion
-- [mig-fix] removed incompatible tblMigrations self-bookkeeping (the migration runner records migrations)
