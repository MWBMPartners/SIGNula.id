-- ============================================================================
-- SIGNula Database Migration 012
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- Migration: 012_payment_expansion.sql
-- Version: 2.4.0-beta
-- Date: 2026-02-13
-- Description: Two-tier payment system expansion — partner payment collection,
--              invoices, credits, service fees, billing automation
--
-- Features Added:
--   - Partner-specific payment provider configuration (Option 2a/2b)
--   - Service fee management with configurable rates and 30-day notice
--   - Fee transaction tracking and partner remittance/payout system
--   - Credit/balance system for users and partners (preloading support)
--   - Invoice generation with PDF support (via TCPDF)
--   - Provider-specific discounts (global + per-partner scope)
--   - Partner-defined subscription tiers for their customers
--   - Billing scheduler for automated charges, reminders, suspensions
--   - Discount code enhancements: country restrictions, per-account assignment
--   - Auto-suspend on non-payment / auto-resume on payment
--   - 9 new email templates for payment lifecycle notifications
--   - 4 new feature toggles for billing features
--
-- Dependencies:
--   - Migration 008 (tblPartners must exist)
--   - Migration 009 (tblFeatureToggles, tblPartnerTeamMembers must exist)
--   - Migration 010 (tblPayments, tblSubscriptions, tblDiscountCodes must exist)
--   - Migration 011 (tblInboundWebhooks, Stripe/Coinbase settings must exist)
--
-- ToS Compliance Notes:
--   - Option 2b (SIGNula-managed payments) requires:
--     * Stripe Connect (Standard or Express) for marketplace charges
--     * PayPal Commerce Platform for marketplace fee splits
--     * Admin settings control per-provider availability
--
-- Supports: MySQL 8.0+, MariaDB 10.5+
-- ============================================================================


-- ============================================================================
-- 🏢 PARTNER PAYMENT CONFIGURATION
-- ============================================================================

-- 🔑 Partner-specific payment provider credentials
-- Stores encrypted API keys for partners who use their own payment accounts (Option 2a)
-- or flags that a partner uses SIGNula's keys (Option 2b)
-- @see https://docs.stripe.com/connect (Stripe Connect for marketplace payments)
-- @see https://developer.paypal.com/docs/platforms/ (PayPal Commerce Platform)
CREATE TABLE IF NOT EXISTS `tblPartnerPaymentConfig` (
    `configID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique configuration record ID',

    `partnerID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblPartners — the partner this config belongs to',

    `provider` ENUM('stripe', 'paypal', 'coinbase') NOT NULL
        COMMENT 'Payment provider this config applies to',

    `usesOwnKeys` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = Option 2a (partner uses own API keys), 0 = Option 2b (uses SIGNula keys)',

    -- 🔐 Encrypted partner API credentials (NULL when usesOwnKeys = 0)
    -- All values encrypted via SecurityUtils::encrypt() before storage
    `apiKey` TEXT DEFAULT NULL
        COMMENT 'Encrypted: Partner API key / Client ID',

    `apiSecret` TEXT DEFAULT NULL
        COMMENT 'Encrypted: Partner API secret / Client Secret',

    `webhookSecret` TEXT DEFAULT NULL
        COMMENT 'Encrypted: Partner webhook signing secret',

    `publishableKey` TEXT DEFAULT NULL
        COMMENT 'Encrypted: Partner publishable key (Stripe only)',

    `mode` ENUM('sandbox', 'test', 'live') NOT NULL DEFAULT 'sandbox'
        COMMENT 'API environment mode',

    -- ✅ Verification status
    `isEnabled` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Whether this provider is enabled for the partner',

    `isVerified` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Whether the credentials have been tested and verified',

    `verifiedAt` DATETIME DEFAULT NULL
        COMMENT 'When credentials were last verified',

    `metadata` JSON DEFAULT NULL
        COMMENT 'Additional provider-specific metadata',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`configID`),
    UNIQUE KEY `uk_partner_provider` (`partnerID`, `provider`),
    INDEX `idx_ppc_partner` (`partnerID`),
    INDEX `idx_ppc_provider` (`provider`),
    CONSTRAINT `fk_ppc_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner-specific payment provider credentials for Level 2 payments';


-- ============================================================================
-- 💰 SERVICE FEE MANAGEMENT
-- ============================================================================

-- 📊 Service fee schedule configuration
-- Tracks current and historical fee rates for both payment options
-- Supports the 30-day minimum notice requirement for fee changes
-- @see User requirement: "service fees for 2a & 2b can be set within settings"
CREATE TABLE IF NOT EXISTS `tblServiceFees` (
    `feeID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique fee schedule ID',

    `feeType` ENUM('partner_own_keys', 'partner_signula_keys') NOT NULL
        COMMENT 'partner_own_keys = Option 2a (~10%), partner_signula_keys = Option 2b (~30%)',

    `feePercentage` DECIMAL(5, 2) NOT NULL
        COMMENT 'Percentage fee charged by SIGNula (e.g. 10.00 = 10%)',

    `fixedFeeAmount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Optional fixed fee per transaction (in default currency)',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Currency for fixed fee amounts (ISO 4217)',

    `minFee` DECIMAL(10, 2) DEFAULT NULL
        COMMENT 'Minimum fee amount per transaction (NULL = no minimum)',

    `maxFee` DECIMAL(10, 2) DEFAULT NULL
        COMMENT 'Maximum fee cap per transaction (NULL = no cap)',

    `effectiveFrom` DATE NOT NULL
        COMMENT 'Date this fee schedule becomes active',

    `effectiveUntil` DATE DEFAULT NULL
        COMMENT 'Date this fee schedule expires (NULL = no expiry, superseded by newer)',

    `noticeGivenAt` DATETIME DEFAULT NULL
        COMMENT 'When partners were notified of this fee schedule/change',

    `minimumNoticeDays` INT NOT NULL DEFAULT 30
        COMMENT 'Minimum notice period in calendar days before activation',

    `isActive` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Whether this fee schedule is currently active',

    `createdBy` INT UNSIGNED DEFAULT NULL
        COMMENT 'Admin userID who created this fee schedule',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`feeID`),
    INDEX `idx_sf_fee_type` (`feeType`, `isActive`),
    INDEX `idx_sf_effective` (`effectiveFrom`, `effectiveUntil`),
    INDEX `idx_sf_active` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='SIGNula service fee rates for partner payment processing';


-- 💳 Individual service fee transaction records
-- Tracks the exact fee charged on each partner payment for reconciliation
CREATE TABLE IF NOT EXISTS `tblServiceFeeTransactions` (
    `feeTransactionID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique fee transaction ID',

    `paymentID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblPayments — the payment that triggered this fee',

    `partnerID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblPartners — the partner charged this fee',

    `feeID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblServiceFees — the fee schedule applied',

    `grossAmount` DECIMAL(10, 2) NOT NULL
        COMMENT 'Original payment amount before fee deduction',

    `feePercentageApplied` DECIMAL(5, 2) NOT NULL
        COMMENT 'Actual percentage applied (snapshot at time of charge)',

    `feeAmount` DECIMAL(10, 2) NOT NULL
        COMMENT 'Fee amount charged to partner',

    `netToPartner` DECIMAL(10, 2) NOT NULL
        COMMENT 'Amount owed to partner after fee deduction',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Currency (ISO 4217)',

    `status` ENUM('pending', 'collected', 'remitted', 'disputed', 'refunded') NOT NULL DEFAULT 'pending'
        COMMENT 'Fee collection and remittance status',

    `remittedAt` DATETIME DEFAULT NULL
        COMMENT 'When the net amount was remitted to the partner',

    `remittanceMethod` VARCHAR(50) DEFAULT NULL
        COMMENT 'How the partner was paid (paypal_payout, bank_transfer, credit_to_balance, manual)',

    `remittanceReference` VARCHAR(255) DEFAULT NULL
        COMMENT 'External reference for the remittance transaction',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`feeTransactionID`),
    INDEX `idx_sft_payment` (`paymentID`),
    INDEX `idx_sft_partner` (`partnerID`),
    INDEX `idx_sft_status` (`status`),
    INDEX `idx_sft_remitted` (`remittedAt`),
    INDEX `idx_sft_created` (`createdAt`),
    CONSTRAINT `fk_sft_payment` FOREIGN KEY (`paymentID`)
        REFERENCES `tblPayments` (`paymentID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sft_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE,
    CONSTRAINT `fk_sft_fee` FOREIGN KEY (`feeID`)
        REFERENCES `tblServiceFees` (`feeID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Individual service fee records for each partner transaction';


-- 💸 Partner payout/remittance tracking
-- Records batch payouts to partners after deducting service fees
CREATE TABLE IF NOT EXISTS `tblRemittances` (
    `remittanceID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique remittance ID',

    `partnerID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblPartners — the partner receiving this payout',

    `amount` DECIMAL(10, 2) NOT NULL
        COMMENT 'Net payout amount',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Payout currency (ISO 4217)',

    `method` ENUM('paypal_payout', 'bank_transfer', 'credit_to_balance', 'manual') NOT NULL
        COMMENT 'Payout delivery method',

    `paypalEmail` VARCHAR(320) DEFAULT NULL
        COMMENT 'PayPal payout recipient email (if method = paypal_payout)',

    `paypalBatchID` VARCHAR(255) DEFAULT NULL
        COMMENT 'PayPal Payout batch ID for tracking',

    `bankReference` VARCHAR(255) DEFAULT NULL
        COMMENT 'Bank transfer reference (if method = bank_transfer)',

    `status` ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending'
        COMMENT 'Payout processing status',

    `periodStart` DATE NOT NULL
        COMMENT 'Start of the earnings period covered by this payout',

    `periodEnd` DATE NOT NULL
        COMMENT 'End of the earnings period covered by this payout',

    `transactionCount` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of transactions in this payout batch',

    `grossTotal` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Total gross amount before fees',

    `feesTotal` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Total fees deducted',

    `failureReason` VARCHAR(500) DEFAULT NULL
        COMMENT 'Reason for payout failure',

    `processedAt` DATETIME DEFAULT NULL
        COMMENT 'When the payout was processed',

    `processedBy` INT UNSIGNED DEFAULT NULL
        COMMENT 'Admin userID who processed the payout',

    `metadata` JSON DEFAULT NULL
        COMMENT 'Additional payout metadata',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`remittanceID`),
    INDEX `idx_remit_partner` (`partnerID`),
    INDEX `idx_remit_status` (`status`),
    INDEX `idx_remit_period` (`periodStart`, `periodEnd`),
    INDEX `idx_remit_created` (`createdAt`),
    CONSTRAINT `fk_remit_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner payout/remittance tracking';


-- ============================================================================
-- 💳 CREDIT / BALANCE SYSTEM
-- ============================================================================

-- 💰 Credit balances for users and partners
-- Supports pre-loading credits at both SIGNula level and per-partner context
-- ownerType + ownerID + partnerID triple determines scope:
--   - partner balance at SIGNula: ownerType='partner', ownerID=<partnerID>, partnerID=NULL
--   - user credits within partner: ownerType='user', ownerID=<userID>, partnerID=<partnerID>
--   - user credits at SIGNula:    ownerType='user', ownerID=<userID>, partnerID=NULL
CREATE TABLE IF NOT EXISTS `tblCreditBalances` (
    `balanceID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique balance record ID',

    `ownerType` ENUM('user', 'partner') NOT NULL
        COMMENT 'Type of balance owner',

    `ownerID` INT UNSIGNED NOT NULL
        COMMENT 'userID or partnerID depending on ownerType',

    `partnerID` INT UNSIGNED DEFAULT NULL
        COMMENT 'Partner context (NULL = SIGNula-level balance)',

    `balance` DECIMAL(12, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Current credit balance',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Balance currency (ISO 4217)',

    `lastTransactionAt` DATETIME DEFAULT NULL
        COMMENT 'Timestamp of the most recent transaction on this balance',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`balanceID`),
    UNIQUE KEY `uk_owner_partner_currency` (`ownerType`, `ownerID`, COALESCE(`partnerID`, 0), `currency`),
    INDEX `idx_cb_owner` (`ownerType`, `ownerID`),
    INDEX `idx_cb_partner` (`partnerID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Credit balances for users and partners (preloading support)';


-- 📝 Credit transaction audit trail
-- Every credit change is logged with before/after balances for full accountability
-- CRITICAL: deposit() and withdraw() methods MUST use SELECT ... FOR UPDATE
-- to prevent race conditions on concurrent balance updates
CREATE TABLE IF NOT EXISTS `tblCreditTransactions` (
    `creditTransactionID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique credit transaction ID',

    `balanceID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblCreditBalances',

    `type` ENUM('deposit', 'withdrawal', 'payment', 'refund', 'adjustment', 'remittance', 'promotional') NOT NULL
        COMMENT 'Transaction type',

    `amount` DECIMAL(12, 2) NOT NULL
        COMMENT 'Transaction amount (positive for deposits, negative for withdrawals)',

    `balanceBefore` DECIMAL(12, 2) NOT NULL
        COMMENT 'Balance before this transaction',

    `balanceAfter` DECIMAL(12, 2) NOT NULL
        COMMENT 'Balance after this transaction',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Transaction currency (ISO 4217)',

    `referenceType` VARCHAR(50) DEFAULT NULL
        COMMENT 'Type of linked record (payment, subscription, remittance, manual, topup)',

    `referenceID` INT UNSIGNED DEFAULT NULL
        COMMENT 'ID in the referenced table',

    `description` VARCHAR(500) DEFAULT NULL
        COMMENT 'Human-readable transaction description',

    `performedBy` INT UNSIGNED DEFAULT NULL
        COMMENT 'userID who triggered this transaction',

    `ipAddress` VARCHAR(45) DEFAULT NULL
        COMMENT 'IP address of the requester (IPv4 or IPv6)',

    `metadata` JSON DEFAULT NULL
        COMMENT 'Additional transaction metadata',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`creditTransactionID`),
    INDEX `idx_ct_balance` (`balanceID`),
    INDEX `idx_ct_type` (`type`),
    INDEX `idx_ct_reference` (`referenceType`, `referenceID`),
    INDEX `idx_ct_created` (`createdAt`),
    INDEX `idx_ct_performed_by` (`performedBy`),
    CONSTRAINT `fk_ct_balance` FOREIGN KEY (`balanceID`)
        REFERENCES `tblCreditBalances` (`balanceID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Credit balance transaction audit trail';


-- ============================================================================
-- 🧾 INVOICE SYSTEM
-- ============================================================================

-- 📄 Proper invoice records with PDF support
-- Generates formatted invoices for all payments with line items and tax breakdown
-- PDF generated via TCPDF library stored at web/private_html/invoices/
-- @see TCPDF: https://tcpdf.org/ (Pure PHP PDF generation, no Composer needed)
CREATE TABLE IF NOT EXISTS `tblInvoices` (
    `invoiceID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique invoice record ID',

    `invoiceNumber` VARCHAR(50) NOT NULL
        COMMENT 'Formatted invoice number (e.g. SIG-20260213-00001)',

    `paymentID` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to tblPayments — linked payment (NULL for proforma invoices)',

    `userID` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to tblUsers — the user being invoiced',

    `partnerID` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to tblPartners — the partner being invoiced (or partner context)',

    -- 📋 Invoice parties
    `billedToName` VARCHAR(255) NOT NULL
        COMMENT 'Name of the person/org being billed',

    `billedToEmail` VARCHAR(320) NOT NULL
        COMMENT 'Email of the person/org being billed',

    `billedToAddress` JSON DEFAULT NULL
        COMMENT 'Billing address (line1, line2, city, state, postcode, country)',

    `billedToVATNumber` VARCHAR(50) DEFAULT NULL
        COMMENT 'Customer VAT/tax registration number',

    `billedByName` VARCHAR(255) NOT NULL DEFAULT 'MWBM Partners Ltd (t/a MWservices)'
        COMMENT 'Name of the invoicing entity',

    `billedByAddress` JSON DEFAULT NULL
        COMMENT 'Invoicing entity address',

    `billedByVATNumber` VARCHAR(50) DEFAULT NULL
        COMMENT 'Invoicing entity VAT/tax registration number',

    -- 💰 Amounts
    `subtotal` DECIMAL(10, 2) NOT NULL
        COMMENT 'Subtotal before discounts and tax',

    `discountAmount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Total discount applied',

    `taxRate` DECIMAL(5, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Tax/VAT rate applied (%)',

    `taxAmount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Tax/VAT amount',

    `total` DECIMAL(10, 2) NOT NULL
        COMMENT 'Grand total (subtotal - discount + tax)',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Invoice currency (ISO 4217)',

    -- 📋 Line items
    `lineItems` JSON NOT NULL
        COMMENT 'Array of [{description, quantity, unitPrice, amount}]',

    -- 📊 Status
    `status` ENUM('draft', 'issued', 'sent', 'paid', 'overdue', 'cancelled', 'void') NOT NULL DEFAULT 'draft'
        COMMENT 'Invoice lifecycle status',

    `issuedAt` DATETIME DEFAULT NULL
        COMMENT 'When invoice was officially issued',

    `dueDate` DATE DEFAULT NULL
        COMMENT 'Payment due date',

    `paidAt` DATETIME DEFAULT NULL
        COMMENT 'When invoice was paid',

    -- 📄 PDF
    `pdfPath` VARCHAR(500) DEFAULT NULL
        COMMENT 'Relative path to generated PDF file',

    `pdfGeneratedAt` DATETIME DEFAULT NULL
        COMMENT 'When PDF was last generated',

    -- 📝 Notes
    `notes` TEXT DEFAULT NULL
        COMMENT 'Customer-visible invoice notes',

    `internalNotes` TEXT DEFAULT NULL
        COMMENT 'Internal notes (not shown to customer)',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`invoiceID`),
    UNIQUE KEY `uk_invoice_number` (`invoiceNumber`),
    INDEX `idx_inv_payment` (`paymentID`),
    INDEX `idx_inv_user` (`userID`),
    INDEX `idx_inv_partner` (`partnerID`),
    INDEX `idx_inv_status` (`status`),
    INDEX `idx_inv_due_date` (`dueDate`),
    INDEX `idx_inv_issued` (`issuedAt`),
    INDEX `idx_inv_created` (`createdAt`),
    CONSTRAINT `fk_inv_payment` FOREIGN KEY (`paymentID`)
        REFERENCES `tblPayments` (`paymentID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Invoice records with PDF generation support';


-- ============================================================================
-- 🎁 PROVIDER-SPECIFIC DISCOUNTS
-- ============================================================================

-- 💸 Payment method-specific discount rates
-- Supports discounts at SIGNula level (global) and per-partner level
-- Example: 10% off crypto payments, 3% off PayPal, 1% off Stripe Link
CREATE TABLE IF NOT EXISTS `tblProviderDiscounts` (
    `discountID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique provider discount ID',

    `scope` ENUM('global', 'partner') NOT NULL DEFAULT 'global'
        COMMENT 'global = SIGNula-level discount, partner = per-partner discount for their customers',

    `partnerID` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to tblPartners (NULL for global discounts)',

    `paymentMethod` VARCHAR(50) NOT NULL
        COMMENT 'Payment method this discount applies to (crypto, crypto_btc, crypto_eth, paypal, stripe, link, apple_pay, google_pay)',

    `discountPercentage` DECIMAL(5, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Discount percentage (e.g. 10.00 = 10% off)',

    `description` VARCHAR(255) DEFAULT NULL
        COMMENT 'Human-readable description of this discount',

    `isActive` TINYINT(1) NOT NULL DEFAULT 1,

    `createdBy` INT UNSIGNED DEFAULT NULL
        COMMENT 'Admin/partner admin userID who created this',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`discountID`),
    UNIQUE KEY `uk_scope_partner_method` (`scope`, `partnerID`, `paymentMethod`),
    INDEX `idx_pd_active` (`isActive`),
    INDEX `idx_pd_partner` (`partnerID`),
    INDEX `idx_pd_method` (`paymentMethod`),
    CONSTRAINT `fk_pd_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Payment method-specific discounts (e.g. 10% off crypto, 3% off PayPal)';


-- ============================================================================
-- 🏷️ PARTNER-DEFINED SUBSCRIPTION TIERS
-- ============================================================================

-- 📊 Partner-defined subscription tiers for THEIR customers
-- Structurally mirrors tblSubscriptionTiers but scoped to individual partners
-- Allows partners to define custom pricing, features, and limits
CREATE TABLE IF NOT EXISTS `tblPartnerSubscriptionTiers` (
    `tierID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique partner tier ID',

    `partnerID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblPartners — the partner who owns this tier',

    `tierName` VARCHAR(100) NOT NULL
        COMMENT 'Display name (e.g. Free, Starter, Professional)',

    `tierSlug` VARCHAR(50) NOT NULL
        COMMENT 'URL-safe identifier (e.g. free, starter, professional)',

    `tierDescription` TEXT DEFAULT NULL
        COMMENT 'Description of tier benefits',

    `monthlyPrice` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Monthly subscription price',

    `yearlyPrice` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Annual subscription price (typically discounted)',

    `currency` VARCHAR(3) NOT NULL DEFAULT 'GBP'
        COMMENT 'Pricing currency (ISO 4217)',

    `features` JSON NOT NULL
        COMMENT 'Array of feature strings included in this tier',

    `featureLimits` JSON DEFAULT NULL
        COMMENT 'Object of numeric limits (e.g. {"api_calls": 1000, "storage_gb": 5})',

    `displayOrder` INT NOT NULL DEFAULT 0
        COMMENT 'Sort order for pricing page display',

    `isActive` TINYINT(1) NOT NULL DEFAULT 1,

    `isDefault` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Default tier for new users of this partner (typically free tier)',

    `trialDays` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Free trial period in days (0 = no trial)',

    `badge` VARCHAR(50) DEFAULT NULL
        COMMENT 'Badge text (e.g. Popular, Best Value)',

    `badgeColor` VARCHAR(20) DEFAULT NULL
        COMMENT 'Badge CSS color class',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`tierID`),
    UNIQUE KEY `uk_partner_tier_slug` (`partnerID`, `tierSlug`),
    INDEX `idx_pst_partner` (`partnerID`),
    INDEX `idx_pst_active` (`partnerID`, `isActive`),
    INDEX `idx_pst_default` (`partnerID`, `isDefault`),
    CONSTRAINT `fk_pst_partner` FOREIGN KEY (`partnerID`)
        REFERENCES `tblPartners` (`partnerID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner-defined subscription tiers for their own customers';


-- ============================================================================
-- 🔔 DISCOUNT CODE ASSIGNMENTS
-- ============================================================================

-- 🎯 Per-account discount code assignments
-- Links specific discount codes to individual users/emails
-- Used when codeType = 'assigned' in tblDiscountCodes
CREATE TABLE IF NOT EXISTS `tblDiscountCodeAssignments` (
    `assignmentID` INT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique assignment ID',

    `discountID` INT UNSIGNED NOT NULL
        COMMENT 'FK to tblDiscountCodes — the discount code assigned',

    `assignedToEmail` VARCHAR(320) DEFAULT NULL
        COMMENT 'Specific email address this code is assigned to',

    `assignedToUserID` INT UNSIGNED DEFAULT NULL
        COMMENT 'Specific userID this code is assigned to (NULL if email-only)',

    `assignedBy` INT UNSIGNED DEFAULT NULL
        COMMENT 'Admin/partner admin userID who made this assignment',

    `usedAt` DATETIME DEFAULT NULL
        COMMENT 'When the assigned code was redeemed (NULL if unused)',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`assignmentID`),
    INDEX `idx_dca_discount` (`discountID`),
    INDEX `idx_dca_email` (`assignedToEmail`),
    INDEX `idx_dca_user` (`assignedToUserID`),
    CONSTRAINT `fk_dca_discount` FOREIGN KEY (`discountID`)
        REFERENCES `tblDiscountCodes` (`discountID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Per-account discount code assignments (assigned codes)';


-- ============================================================================
-- ⏰ BILLING SCHEDULER
-- ============================================================================

-- 📋 Scheduled billing tasks
-- Replaces CLI cron jobs for automated billing actions
-- Processed via web-accessible cron endpoint (/cron/billing.php?token=<secret>)
-- and lazy-check safety net on authenticated page loads
-- Three-layer redundancy: MySQL events + web cron + lazy checks
CREATE TABLE IF NOT EXISTS `tblBillingSchedule` (
    `scheduleID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
        COMMENT 'Unique schedule entry ID',

    `taskType` ENUM(
        'charge_subscription',
        'suspend_subscription',
        'resume_subscription',
        'send_reminder',
        'expire_trial',
        'generate_invoice',
        'process_remittance',
        'fee_change_notification'
    ) NOT NULL
        COMMENT 'Type of billing task to execute',

    `targetType` ENUM('subscription', 'partner', 'user', 'remittance', 'fee') NOT NULL
        COMMENT 'Type of target entity',

    `targetID` INT UNSIGNED NOT NULL
        COMMENT 'ID of the target entity (subscriptionID, partnerID, userID, etc.)',

    `scheduledFor` DATETIME NOT NULL
        COMMENT 'When this task should be executed',

    `status` ENUM('pending', 'processing', 'completed', 'failed', 'skipped') NOT NULL DEFAULT 'pending'
        COMMENT 'Task execution status',

    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of execution attempts',

    `maxAttempts` TINYINT UNSIGNED NOT NULL DEFAULT 3
        COMMENT 'Maximum retry attempts before marking as failed',

    `lastAttemptAt` DATETIME DEFAULT NULL
        COMMENT 'When the last execution attempt was made',

    `completedAt` DATETIME DEFAULT NULL
        COMMENT 'When the task completed successfully',

    `errorMessage` TEXT DEFAULT NULL
        COMMENT 'Error details from the last failed attempt',

    `metadata` JSON DEFAULT NULL
        COMMENT 'Task-specific data (e.g. reminder days, subscription details)',

    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`scheduleID`),
    INDEX `idx_bs_scheduled` (`status`, `scheduledFor`),
    INDEX `idx_bs_task_type` (`taskType`),
    INDEX `idx_bs_target` (`targetType`, `targetID`),
    INDEX `idx_bs_status` (`status`),
    INDEX `idx_bs_created` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Scheduled billing tasks (web-triggered cron replacement)';


-- ============================================================================
-- 🔧 ALTER EXISTING TABLES
-- ============================================================================

-- 💳 tblPayments — Add partner context for Level 2 payments
-- @see Migration 010 for original tblPayments definition
ALTER TABLE `tblPayments`
    ADD COLUMN `partnerID` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to tblPartners — partner who owns this payment (Level 2)'
        AFTER `userID`,
    ADD COLUMN `paymentContext` ENUM('signula_direct', 'partner_own_keys', 'partner_signula_keys') NOT NULL DEFAULT 'signula_direct'
        COMMENT 'Which payment flow: signula_direct (Level 1), partner_own_keys (2a), partner_signula_keys (2b)'
        AFTER `subscriptionID`,
    ADD INDEX `idx_payment_partner` (`partnerID`),
    ADD INDEX `idx_payment_context` (`paymentContext`);


-- 🎁 tblDiscountCodes — Add partner ownership, country restrictions, code type
-- @see Migration 010 for original tblDiscountCodes definition
ALTER TABLE `tblDiscountCodes`
    ADD COLUMN `partnerID` INT UNSIGNED DEFAULT NULL
        COMMENT 'NULL = SIGNula global code, non-NULL = partner-specific code'
        AFTER `discountID`,
    ADD COLUMN `codeType` ENUM('universal', 'assigned') NOT NULL DEFAULT 'universal'
        COMMENT 'universal = anyone can use, assigned = specific accounts only (see tblDiscountCodeAssignments)'
        AFTER `code`,
    ADD COLUMN `allowedCountries` JSON DEFAULT NULL
        COMMENT 'JSON array of ISO 3166-1 alpha-2 country codes allowed to use this code (NULL = all countries)'
        AFTER `applicablePaymentMethods`,
    ADD COLUMN `blockedCountries` JSON DEFAULT NULL
        COMMENT 'JSON array of ISO 3166-1 alpha-2 country codes blocked from using this code'
        AFTER `allowedCountries`,
    ADD INDEX `idx_discount_partner` (`partnerID`),
    ADD INDEX `idx_discount_code_type` (`codeType`);


-- 🏢 tblPartners — Add suspension tracking, credit balance, payout preferences
-- @see Migration 008 for original tblPartners definition
ALTER TABLE `tblPartners`
    ADD COLUMN `creditBalance` DECIMAL(12, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Quick-access credit balance (authoritative: tblCreditBalances)'
        AFTER `paymentStatus`,
    ADD COLUMN `suspendedAt` DATETIME DEFAULT NULL
        COMMENT 'When partner was auto-suspended for non-payment'
        AFTER `creditBalance`,
    ADD COLUMN `suspendedTier` ENUM('free', 'basic', 'premium', 'enterprise') DEFAULT NULL
        COMMENT 'Original tier before suspension (to restore on payment)'
        AFTER `suspendedAt`,
    ADD COLUMN `lastPaymentAt` DATETIME DEFAULT NULL
        COMMENT 'When the partner last made a payment'
        AFTER `suspendedTier`,
    ADD COLUMN `nextPaymentDue` DATE DEFAULT NULL
        COMMENT 'Next payment due date (payments made 1 month in advance)'
        AFTER `lastPaymentAt`,
    ADD COLUMN `payoutEmail` VARCHAR(320) DEFAULT NULL
        COMMENT 'Email for receiving partner payouts (PayPal, etc.)'
        AFTER `billingEmail`,
    ADD COLUMN `payoutMethod` ENUM('paypal_payout', 'bank_transfer', 'credit_to_balance', 'manual') DEFAULT 'manual'
        COMMENT 'Preferred payout method for partner remittances'
        AFTER `payoutEmail`;


-- 📋 tblSubscriptions — Add partner-defined tier reference
-- Allows subscriptions to reference partner-defined tiers instead of global tiers
ALTER TABLE `tblSubscriptions`
    ADD COLUMN `partnerTierID` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to tblPartnerSubscriptionTiers (NULL = uses global tblSubscriptionTiers.tierID)'
        AFTER `tierID`;


-- ============================================================================
-- ⚙️ SETTINGS
-- ============================================================================

-- 💰 Service fee settings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingCategory`, `settingDescription`, `isSensitive`)
VALUES
    -- 💰 Service Fees
    ('payment.service_fee.own_keys_percent', '10.00', 'payment', 'Service fee (%) charged when partner uses their own payment keys (Option 2a)', 0),
    ('payment.service_fee.signula_keys_percent', '30.00', 'payment', 'Service fee (%) charged when partner uses SIGNula payment keys (Option 2b)', 0),
    ('payment.service_fee.minimum_notice_days', '30', 'payment', 'Minimum calendar days notice before fee changes take effect', 0),

    -- 💳 Credit System
    ('payment.credits.enabled', '1', 'payment', 'Enable credit/balance pre-loading system', 0),
    ('payment.credits.min_topup', '10.00', 'payment', 'Minimum credit top-up amount', 0),
    ('payment.credits.max_balance', '50000.00', 'payment', 'Maximum credit balance allowed per account', 0),

    -- 🧾 Invoice Settings
    ('payment.invoice.company_name', 'MWBM Partners Ltd (t/a MWservices)', 'payment', 'Company name displayed on invoices', 0),
    ('payment.invoice.company_address', '{}', 'payment', 'Company address for invoices (JSON: line1, line2, city, state, postcode, country)', 0),
    ('payment.invoice.vat_number', '', 'payment', 'Company VAT registration number for invoices', 0),
    ('payment.invoice.payment_terms_days', '30', 'payment', 'Default payment terms in days from invoice date', 0),
    ('payment.invoice.footer_text', 'Thank you for your business.', 'payment', 'Footer text displayed at bottom of invoices', 0),
    ('payment.invoice.logo_path', '', 'payment', 'Relative path to company logo for PDF invoices', 0),

    -- ⏸️ Auto-Suspension Settings
    ('payment.auto_suspend.enabled', '1', 'payment', 'Auto-suspend partner accounts on non-payment', 0),
    ('payment.auto_suspend.grace_period_days', '7', 'payment', 'Grace period (days) after missed payment before suspension', 0),
    ('payment.auto_suspend.reminder_days', '7,3,1', 'payment', 'Days before due date to send payment reminders (comma-separated)', 0),

    -- 💸 Remittance Settings
    ('payment.remittance.auto_enabled', '0', 'payment', 'Auto-process partner payouts (0 = manual approval required)', 0),
    ('payment.remittance.min_amount', '50.00', 'payment', 'Minimum accumulated amount before partner payout', 0),
    ('payment.remittance.schedule', 'monthly', 'payment', 'Payout schedule frequency: weekly, biweekly, monthly', 0),

    -- 🎁 Provider Discount Defaults
    ('payment.provider_discount.crypto_percent', '5.00', 'payment', 'Default discount for cryptocurrency payments (%)', 0),
    ('payment.provider_discount.paypal_percent', '0.00', 'payment', 'Default discount for PayPal payments (%)', 0),
    ('payment.provider_discount.stripe_percent', '0.00', 'payment', 'Default discount for Stripe card payments (%)', 0),
    ('payment.provider_discount.link_percent', '0.00', 'payment', 'Default discount for Stripe Link payments (%)', 0),

    -- ⏰ Billing Cron Settings
    ('payment.cron.secret_token', '', 'payment', 'Secret token for authenticating cron endpoint requests (/cron/billing.php?token=xxx)', 1),
    ('payment.cron.last_run', '', 'payment', 'Timestamp of last successful billing cron execution', 0),
    ('payment.advance_billing_months', '1', 'payment', 'How many months in advance to bill subscriptions', 0),

    -- 🔗 Stripe Connect (for Option 2b ToS compliance)
    -- @see https://docs.stripe.com/connect
    ('payment.stripe.connect_enabled', '0', 'payment', 'Enable Stripe Connect for marketplace/platform payments (required for Option 2b)', 0),
    ('payment.stripe.connect_account_id', '', 'payment', 'SIGNula platform Stripe Connect account ID', 1),

    -- 🔗 PayPal Commerce Platform (for Option 2b ToS compliance)
    -- @see https://developer.paypal.com/docs/platforms/
    ('payment.paypal.commerce_platform_enabled', '0', 'payment', 'Enable PayPal Commerce Platform for marketplace payments (required for Option 2b)', 0),
    ('payment.paypal.partner_id', '', 'payment', 'PayPal Partner/Platform Merchant ID', 1)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);


-- ============================================================================
-- 🔀 FEATURE TOGGLES
-- ============================================================================

-- 🏷️ New feature toggles for billing capabilities
INSERT INTO `tblFeatureToggles` (`featureKey`, `featureName`, `featureDescription`, `isEnabledGlobally`, `category`, `requiresMigration`, `affectsAPI`, `canPartnersToggle`)
VALUES
    ('partner_payments', 'Partner Payment Collection', 'Allow partners to collect payments from their customers via SIGNula', 1, 'billing', '012', 1, 0),
    ('credit_system', 'Credit/Balance System', 'Allow users and partners to preload and use account credits', 1, 'billing', '012', 1, 1),
    ('partner_custom_tiers', 'Partner Custom Tiers', 'Allow partners to define their own subscription tiers for their customers', 1, 'billing', '012', 1, 0),
    ('pdf_invoices', 'PDF Invoice Generation', 'Generate PDF invoices for payments and send via email', 1, 'billing', '012', 0, 0)
ON DUPLICATE KEY UPDATE `featureKey` = VALUES(`featureKey`);


-- ============================================================================
-- 📧 EMAIL TEMPLATES
-- ============================================================================

-- 📨 Payment lifecycle email templates
-- Uses {{variableName}} syntax for variable substitution via EmailService
INSERT IGNORE INTO `tblEmailTemplates`
    (`templateKey`, `templateName`, `subject`, `bodyHTML`, `bodyText`, `category`, `trackingEnabled`, `requiredVariables`, `isActive`)
VALUES

-- ✅ Payment receipt (sent after successful payment)
('payment_receipt', 'Payment Receipt',
'Payment Receipt #{{invoiceNumber}} - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#198754;">✅ Payment Receipt</h1><p>Hi {{displayName}},</p><p>We have received your payment. Thank you!</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Invoice #:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{invoiceNumber}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Amount:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{amount}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Date:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{paymentDate}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Description:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{description}}</td></tr></table><p><a href="{{invoiceURL}}" style="display:inline-block;padding:10px 24px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px;">View Invoice</a></p><p style="color:#6c757d;font-size:0.9em;">If you have any questions about this payment, please contact our support team.</p></div>',
'Hi {{displayName}}, Payment of {{currency}} {{amount}} received. Invoice #{{invoiceNumber}}. View at: {{invoiceURL}}',
'transactional', 1,
'["displayName","currency","amount","invoiceNumber","paymentDate","description","invoiceURL"]',
1),

-- ❌ Payment failed notification
('payment_failed', 'Payment Failed',
'Payment Failed — Action Required - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#dc3545;">❌ Payment Failed</h1><p>Hi {{displayName}},</p><p>We were unable to process your payment of <strong>{{currency}} {{amount}}</strong>.</p><p><strong>Reason:</strong> {{failureReason}}</p><p>Please update your payment method to avoid service interruption:</p><p><a href="{{updateURL}}" style="display:inline-block;padding:10px 24px;background:#dc3545;color:#fff;text-decoration:none;border-radius:6px;">Update Payment Method</a></p></div>',
'Hi {{displayName}}, Payment of {{currency}} {{amount}} failed. Reason: {{failureReason}}. Update at: {{updateURL}}',
'transactional', 0,
'["displayName","currency","amount","failureReason","updateURL"]',
1),

-- ⏰ Payment reminder (sent before subscription renewal)
('payment_reminder', 'Payment Reminder',
'Payment Reminder — {{daysUntilDue}} Days Until Renewal - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#0dcaf0;">⏰ Payment Reminder</h1><p>Hi {{displayName}},</p><p>Your <strong>{{tierName}}</strong> subscription will renew in <strong>{{daysUntilDue}} days</strong>.</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Plan:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{tierName}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Amount:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{amount}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Renewal Date:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{renewalDate}}</td></tr></table><p>No action is required if your payment method is up to date.</p></div>',
'Hi {{displayName}}, Your {{tierName}} subscription renews in {{daysUntilDue}} days for {{currency}} {{amount}} on {{renewalDate}}.',
'transactional', 0,
'["displayName","tierName","daysUntilDue","currency","amount","renewalDate"]',
1),

-- ⏸️ Subscription suspended (auto-suspend on non-payment)
('subscription_suspended', 'Subscription Suspended',
'Account Suspended — Payment Required - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#ffc107;">⏸️ Account Suspended</h1><p>Hi {{displayName}},</p><p>Your <strong>{{tierName}}</strong> subscription has been suspended due to non-payment.</p><p>Your account has been downgraded to the <strong>Free</strong> tier. To restore your {{tierName}} features, please make a payment:</p><p><a href="{{paymentURL}}" style="display:inline-block;padding:10px 24px;background:#ffc107;color:#000;text-decoration:none;border-radius:6px;">Make Payment Now</a></p><p style="color:#6c757d;font-size:0.9em;">Your {{tierName}} features will be restored immediately upon payment.</p></div>',
'Hi {{displayName}}, Your {{tierName}} subscription is suspended due to non-payment. Downgraded to Free tier. Pay at: {{paymentURL}}',
'transactional', 0,
'["displayName","tierName","paymentURL"]',
1),

-- ✅ Subscription restored (auto-resume on payment)
('subscription_restored', 'Subscription Restored',
'Account Restored — {{tierName}} Plan Active - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#198754;">✅ Account Restored</h1><p>Hi {{displayName}},</p><p>Thank you for your payment! Your <strong>{{tierName}}</strong> subscription has been restored and all features are active again.</p><p><a href="{{dashboardURL}}" style="display:inline-block;padding:10px 24px;background:#198754;color:#fff;text-decoration:none;border-radius:6px;">Go to Dashboard</a></p></div>',
'Hi {{displayName}}, Your {{tierName}} subscription has been restored. All features are active.',
'transactional', 0,
'["displayName","tierName","dashboardURL"]',
1),

-- 📢 Service fee change notification (sent to all partners)
('service_fee_change', 'Service Fee Change Notice',
'Important: Service Fee Update — SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#0d6efd;">📢 Service Fee Update</h1><p>Hi {{partnerName}},</p><p>We are writing to inform you of an upcoming change to our service fees for the <strong>{{feeTypeName}}</strong> payment option.</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Current fee:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currentFee}}%</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>New fee:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{newFee}}%</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Effective date:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{effectiveDate}}</td></tr></table><p>This change will take effect in <strong>{{noticeDays}} days</strong>, in compliance with our minimum {{minimumNoticeDays}}-day notice period.</p><p style="color:#6c757d;font-size:0.9em;">If you have any questions, please contact our support team.</p></div>',
'Hi {{partnerName}}, Service fee changing from {{currentFee}}% to {{newFee}}% effective {{effectiveDate}} ({{noticeDays}} days notice).',
'transactional', 1,
'["partnerName","feeTypeName","currentFee","newFee","effectiveDate","noticeDays","minimumNoticeDays"]',
1),

-- 💸 Remittance processed (partner payout notification)
('remittance_processed', 'Remittance Processed',
'Payout Processed — {{currency}} {{amount}} - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#198754;">💸 Payout Processed</h1><p>Hi {{partnerName}},</p><p>Your payout has been processed successfully.</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Period:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{periodStart}} — {{periodEnd}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Transactions:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{transactionCount}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Gross total:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{grossTotal}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Service fees:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{feesTotal}}</td></tr><tr><td style="padding:8px;border-bottom:2px solid #198754;"><strong>Net payout:</strong></td><td style="padding:8px;border-bottom:2px solid #198754;"><strong>{{currency}} {{amount}}</strong></td></tr></table><p><a href="{{earningsURL}}" style="display:inline-block;padding:10px 24px;background:#198754;color:#fff;text-decoration:none;border-radius:6px;">View Earnings Dashboard</a></p></div>',
'Hi {{partnerName}}, Payout of {{currency}} {{amount}} processed for {{periodStart}}-{{periodEnd}}. Gross: {{currency}} {{grossTotal}}, Fees: {{currency}} {{feesTotal}}.',
'transactional', 1,
'["partnerName","currency","amount","periodStart","periodEnd","transactionCount","grossTotal","feesTotal","earningsURL"]',
1),

-- 💰 Credit balance top-up confirmation
('credit_topup', 'Credit Balance Top-Up',
'Credits Added — {{currency}} {{amount}} - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#198754;">💰 Credits Added</h1><p>Hi {{displayName}},</p><p><strong>{{currency}} {{amount}}</strong> has been added to your credit balance.</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Amount added:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{amount}}</td></tr><tr><td style="padding:8px;border-bottom:2px solid #198754;"><strong>New balance:</strong></td><td style="padding:8px;border-bottom:2px solid #198754;"><strong>{{currency}} {{newBalance}}</strong></td></tr></table></div>',
'Hi {{displayName}}, {{currency}} {{amount}} credits added. New balance: {{currency}} {{newBalance}}.',
'transactional', 0,
'["displayName","currency","amount","newBalance"]',
1),

-- 🧾 Invoice issued notification
('invoice_issued', 'Invoice Issued',
'Invoice #{{invoiceNumber}} — {{currency}} {{total}} - SIGNula',
'<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h1 style="color:#0d6efd;">🧾 Invoice</h1><p>Hi {{displayName}},</p><p>A new invoice has been generated for your account.</p><table style="width:100%;border-collapse:collapse;margin:20px 0;"><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Invoice #:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{invoiceNumber}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Amount:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{currency}} {{total}}</td></tr><tr><td style="padding:8px;border-bottom:1px solid #dee2e6;"><strong>Due date:</strong></td><td style="padding:8px;border-bottom:1px solid #dee2e6;">{{dueDate}}</td></tr></table><p><a href="{{invoiceURL}}" style="display:inline-block;padding:10px 24px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px;">View Invoice Online</a></p><p style="color:#6c757d;font-size:0.9em;">Payment is due within {{paymentTermsDays}} days of the invoice date.</p></div>',
'Hi {{displayName}}, Invoice #{{invoiceNumber}} for {{currency}} {{total}} due {{dueDate}}. View at: {{invoiceURL}}',
'transactional', 1,
'["displayName","invoiceNumber","currency","total","dueDate","invoiceURL","paymentTermsDays"]',
1);


-- ============================================================================
-- ⏰ MYSQL SCHEDULED EVENTS
-- ============================================================================

-- 🔴 Auto-mark subscriptions as past_due when nextBillingDate passes
-- Runs every hour to catch overdue subscriptions promptly
-- The BillingScheduler then handles reminders and suspension logic
CREATE EVENT IF NOT EXISTS `evt_mark_subscriptions_past_due`
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
DO
    UPDATE `tblSubscriptions`
    SET `subscriptionStatus` = 'past_due',
        `updatedAt` = NOW()
    WHERE `subscriptionStatus` = 'active'
      AND `nextBillingDate` IS NOT NULL
      AND `nextBillingDate` < CURDATE()
      AND `autoRenew` = 1;


-- ⏳ Auto-expire trial subscriptions when trial period ends
-- Runs every hour to catch expired trials
CREATE EVENT IF NOT EXISTS `evt_expire_trial_subscriptions`
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
DO
    UPDATE `tblSubscriptions`
    SET `subscriptionStatus` = 'expired',
        `updatedAt` = NOW()
    WHERE `subscriptionStatus` = 'trial'
      AND `trialEndsAt` IS NOT NULL
      AND `trialEndsAt` < NOW();


-- 📋 Auto-mark invoices as overdue when due date passes
-- Runs daily to update invoice statuses
CREATE EVENT IF NOT EXISTS `evt_mark_invoices_overdue`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
    UPDATE `tblInvoices`
    SET `status` = 'overdue',
        `updatedAt` = NOW()
    WHERE `status` IN ('issued', 'sent')
      AND `dueDate` IS NOT NULL
      AND `dueDate` < CURDATE();


-- 🧹 Cleanup completed billing schedule tasks older than 90 days
CREATE EVENT IF NOT EXISTS `evt_cleanup_billing_schedule`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
    DELETE FROM `tblBillingSchedule`
    WHERE `createdAt` < DATE_SUB(NOW(), INTERVAL 90 DAY)
    AND `status` IN ('completed', 'skipped');


-- ============================================================================
-- 📊 DEFAULT SERVICE FEE SCHEDULES
-- ============================================================================

-- Insert default fee schedules (active immediately)
INSERT INTO `tblServiceFees` (`feeType`, `feePercentage`, `effectiveFrom`, `isActive`)
VALUES
    ('partner_own_keys', 10.00, CURDATE(), 1),
    ('partner_signula_keys', 30.00, CURDATE(), 1);


-- ============================================================================
-- 📊 DEFAULT PROVIDER DISCOUNTS
-- ============================================================================

-- Insert default global provider discounts
INSERT INTO `tblProviderDiscounts` (`scope`, `partnerID`, `paymentMethod`, `discountPercentage`, `description`, `isActive`)
VALUES
    ('global', NULL, 'crypto', 5.00, 'Default 5% discount for cryptocurrency payments', 1),
    ('global', NULL, 'crypto_btc', 5.00, 'Default 5% discount for Bitcoin payments', 1),
    ('global', NULL, 'crypto_eth', 5.00, 'Default 5% discount for Ethereum payments', 1),
    ('global', NULL, 'crypto_usdt', 5.00, 'Default 5% discount for USDT payments', 1),
    ('global', NULL, 'crypto_usdc', 5.00, 'Default 5% discount for USDC payments', 1),
    ('global', NULL, 'paypal', 0.00, 'PayPal payment discount (disabled by default)', 0),
    ('global', NULL, 'stripe', 0.00, 'Stripe card payment discount (disabled by default)', 0),
    ('global', NULL, 'link', 0.00, 'Stripe Link payment discount (disabled by default)', 0),
    ('global', NULL, 'apple_pay', 0.00, 'Apple Pay payment discount (disabled by default)', 0),
    ('global', NULL, 'google_pay', 0.00, 'Google Pay payment discount (disabled by default)', 0);


-- ============================================================================
-- 📋 MIGRATION TRACKING
-- ============================================================================

INSERT INTO `tblMigrations` (`migrationName`, `migrationVersion`, `description`)
VALUES ('012_payment_expansion', '2.4.0-beta',
    'Two-tier payment expansion: partner payment config, service fees, remittances, '
    'credit/balance system, invoices, provider discounts, partner tiers, billing scheduler, '
    'discount code enhancements (country restrictions, assigned codes), '
    '9 email templates, 4 feature toggles, 3 MySQL scheduled events')
ON DUPLICATE KEY UPDATE `migrationName` = VALUES(`migrationName`);


-- ============================================================================
-- END OF MIGRATION 012
-- ============================================================================
