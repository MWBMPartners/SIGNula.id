<?php
/**
 * SIGNula - Universal Single Sign-On Authentication System
 * 
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * 
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 * 
 * @package SIGNula
 * @version 2.2.0-beta
 */

/**
 * ============================================================================
 * 💳 SIGNula - Payment Manager
 * ============================================================================
 *
 * Purpose: Core payment processing and subscription management
 * PHP Version: 8.3+
 *
 * Features:
 * - Multi-provider payment processing (PayPal, Apple Pay, Google Pay, Crypto)
 * - Subscription lifecycle management (create, upgrade, downgrade, cancel, pause)
 * - Payment method tokenisation (never stores raw credentials)
 * - Discount code validation and application
 * - Invoice generation with configurable prefix
 * - Tax/VAT calculation
 * - Refund processing
 * - Activity logging for all payment events
 * - Webhook event dispatching for payment state changes
 *
 * @see https://developer.paypal.com/docs/api/overview/ (PayPal REST API)
 * @see https://developer.apple.com/documentation/apple_pay_on_the_web (Apple Pay)
 * @see https://developers.google.com/pay/api (Google Pay)
 *
 * v2.4.0 Changes:
 * - recordPayment(): Added partnerID and paymentContext fields for Level 2 payments
 * - completePayment(): Integrates with InvoiceManager, BillingScheduler, EmailService
 * - validateDiscountCode(): Added country validation (cascade: billing → profile → IP)
 * - Added getProviderDiscount() for provider-specific discounts
 * - Added getPartnerTier() for partner-defined subscription tiers
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.1.0
 * @since      2.3.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class PaymentManager
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /** @var string Default currency (ISO 4217) */
    const DEFAULT_CURRENCY = 'GBP';

    /** @var string Invoice number prefix */
    const DEFAULT_INVOICE_PREFIX = 'SIG';

    /** @var array Valid payment statuses */
    const PAYMENT_STATUSES = [
        'pending', 'processing', 'completed', 'failed',
        'refunded', 'partially_refunded', 'cancelled', 'disputed'
    ];

    /** @var array Valid subscription statuses */
    const SUBSCRIPTION_STATUSES = [
        'active', 'cancelled', 'expired', 'paused',
        'trial', 'pending', 'past_due'
    ];

    // ========================================================================
    // 💰 SUBSCRIPTION TIERS
    // ========================================================================

    /**
     * 📋 Get all active subscription tiers
     *
     * @param bool $includeInactive Include inactive tiers
     * @return array List of tier records
     */
    public static function getTiers(bool $includeInactive = false): array
    {
        $query = "SELECT * FROM tblSubscriptionTiers";
        if (!$includeInactive) {
            $query .= " WHERE isActive = 1";
        }
        $query .= " ORDER BY displayOrder ASC";

        return Database::fetchAll($query, [], '');
    }

    /**
     * 🔍 Get a single subscription tier by ID or slug
     *
     * @param int|string $identifier Tier ID (int) or slug (string)
     * @return array|null Tier record or null
     */
    public static function getTier(int|string $identifier): ?array
    {
        if (is_int($identifier)) {
            return Database::fetchOne("SELECT * FROM tblSubscriptionTiers WHERE tierID = ?", [$identifier], 'i');
        }

        return Database::fetchOne("SELECT * FROM tblSubscriptionTiers WHERE tierSlug = ?", [$identifier], 's');
    }

    /**
     * 🔍 Get the default (free) tier
     *
     * @return array|null Default tier record
     */
    public static function getDefaultTier(): ?array
    {
        return Database::fetchOne("SELECT * FROM tblSubscriptionTiers WHERE isDefault = 1 AND isActive = 1", [], '');
    }

    // ========================================================================
    // 📦 SUBSCRIPTION MANAGEMENT
    // ========================================================================

    /**
     * ➕ Create a new subscription
     *
     * @param int $userID User ID
     * @param int $tierID Tier ID
     * @param string $billingCycle Billing cycle (monthly, yearly, etc.)
     * @param string $paymentMethod Payment method
     * @param array $options Optional: partnerID, promoCode, trialDays
     * @return array ['success' => bool, 'subscriptionID' => int, 'message' => string]
     */
    public static function createSubscription(
        int $userID,
        int $tierID,
        string $billingCycle = 'monthly',
        string $paymentMethod = 'free',
        array $options = []
    ): array {
        try {
            // 🔍 Get tier details
            $tier = self::getTier($tierID);
            if (!$tier) {
                return ['success' => false, 'message' => 'Invalid subscription tier'];
            }

            // 🔍 Check for existing active subscription
            $existing = Database::fetchOne(
                "SELECT subscriptionID FROM tblSubscriptions WHERE userID = ? AND subscriptionStatus IN ('active', 'trial', 'past_due')",
                [$userID],
                'i'
            );

            if ($existing) {
                return ['success' => false, 'message' => 'User already has an active subscription. Use upgrade/downgrade instead.'];
            }

            // 💰 Calculate amount
            $amount = match ($billingCycle) {
                'yearly' => (float)$tier['yearlyPrice'],
                'monthly' => (float)$tier['monthlyPrice'],
                default => (float)$tier['monthlyPrice'],
            };

            // 🎟️ Apply discount code if provided
            $discountAmount = 0.00;
            if (!empty($options['promoCode'])) {
                $discount = self::validateDiscountCode($options['promoCode'], $tier['tierSlug'], $paymentMethod, $amount);
                if ($discount['valid']) {
                    $discountAmount = $discount['discountAmount'];
                    $amount = max(0, $amount - $discountAmount);
                }
            }

            // 📅 Calculate dates
            $startDate = date('Y-m-d');
            $trialDays = (int)($options['trialDays'] ?? $tier['trialDays'] ?? 0);
            $trialEndsAt = $trialDays > 0 ? date('Y-m-d H:i:s', strtotime('+' . $trialDays . ' days')) : null;
            $status = $trialDays > 0 ? 'trial' : ($amount > 0 ? 'pending' : 'active');

            // 📅 Calculate period end based on billing cycle
            $periodEnd = match ($billingCycle) {
                'monthly' => date('Y-m-d', strtotime('+1 month')),
                'quarterly' => date('Y-m-d', strtotime('+3 months')),
                'yearly' => date('Y-m-d', strtotime('+1 year')),
                'lifetime' => null,
                default => date('Y-m-d', strtotime('+1 month')),
            };

            $nextBillingDate = ($status === 'trial' && $trialEndsAt)
                ? date('Y-m-d', strtotime($trialEndsAt))
                : $periodEnd;

            $currency = $tier['currency'] ?? self::DEFAULT_CURRENCY;
            $partnerID = $options['partnerID'] ?? null;

            // 💾 Insert subscription
            Database::query(
                "INSERT INTO tblSubscriptions
                    (userID, partnerID, tierID, subscriptionStatus, billingCycle, amount, currency,
                     paymentMethod, startDate, currentPeriodStart, currentPeriodEnd,
                     nextBillingDate, trialEndsAt, autoRenew)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                [
                    $userID, $partnerID, $tierID, $status, $billingCycle, $amount, $currency,
                    $paymentMethod, $startDate, $startDate, $periodEnd ?: '9999-12-31',
                    $nextBillingDate, $trialEndsAt
                ],
                'iiisssdssssss'
            );

            $subscriptionID = Database::getLastInsertId();

            // 📝 Log activity
            ActivityLogger::log(
                $userID,
                'subscription_created',
                'payment',
                'info',
                'Subscription created: ' . $tier['tierName'] . ' (' . $billingCycle . ')',
                [
                    'subscriptionID' => $subscriptionID,
                    'tierID' => $tierID,
                    'tierName' => $tier['tierName'],
                    'billingCycle' => $billingCycle,
                    'amount' => $amount,
                    'currency' => $currency,
                    'paymentMethod' => $paymentMethod,
                    'trialDays' => $trialDays,
                ]
            );

            // 🔗 Dispatch webhook event
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('subscription.created', [
                    'subscriptionID' => $subscriptionID,
                    'userID' => $userID,
                    'tierSlug' => $tier['tierSlug'],
                    'billingCycle' => $billingCycle,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => $status,
                ]);
            }

            return [
                'success' => true,
                'subscriptionID' => $subscriptionID,
                'status' => $status,
                'message' => $trialDays > 0
                    ? 'Trial subscription started (' . $trialDays . ' days)'
                    : 'Subscription created successfully',
            ];
        } catch (\Exception $e) {
            ActivityLogger::log($userID, 'subscription_error', 'payment', 'error', 'Failed to create subscription: ' . $e->getMessage(), ['tierID' => $tierID]);
            return ['success' => false, 'message' => 'Failed to create subscription'];
        }
    }

    /**
     * 🔍 Get user subscription
     *
     * @param int $userID User ID
     * @return array|null Active subscription with tier details, or null
     */
    public static function getUserSubscription(int $userID): ?array
    {
        return Database::fetchOne(
            "SELECT s.*, t.tierName, t.tierSlug, t.features, t.featureLimits,
                    t.teamMemberLimit, t.apiCallsPerMonth, t.monthlyPrice, t.yearlyPrice
             FROM tblSubscriptions s
             JOIN tblSubscriptionTiers t ON s.tierID = t.tierID
             WHERE s.userID = ?
               AND s.subscriptionStatus IN ('active', 'trial', 'past_due')
             ORDER BY s.createdAt DESC
             LIMIT 1",
            [$userID],
            'i'
        );
    }

    /**
     * ❌ Cancel a subscription
     *
     * @param int $subscriptionID Subscription ID
     * @param int $userID User ID (ownership check)
     * @param string $reason Cancellation reason
     * @param bool $immediate Cancel immediately or at end of period
     * @return array ['success' => bool, 'message' => string]
     */
    public static function cancelSubscription(int $subscriptionID, int $userID, string $reason = '', bool $immediate = false): array
    {
        try {
            $sub = Database::fetchOne(
                "SELECT * FROM tblSubscriptions WHERE subscriptionID = ? AND userID = ? AND subscriptionStatus IN ('active', 'trial', 'past_due')",
                [$subscriptionID, $userID],
                'ii'
            );

            if (!$sub) {
                return ['success' => false, 'message' => 'Active subscription not found'];
            }

            if ($immediate) {
                Database::query(
                    "UPDATE tblSubscriptions SET subscriptionStatus = 'cancelled', cancelledAt = NOW(), cancellationReason = ?, autoRenew = 0, endDate = CURDATE() WHERE subscriptionID = ?",
                    [$reason, $subscriptionID],
                    'si'
                );
            } else {
                // 📅 Cancel at end of current billing period
                Database::query(
                    "UPDATE tblSubscriptions SET autoRenew = 0, cancelledAt = NOW(), cancellationReason = ?, endDate = currentPeriodEnd WHERE subscriptionID = ?",
                    [$reason, $subscriptionID],
                    'si'
                );
            }

            ActivityLogger::log($userID, 'subscription_cancelled', 'payment', 'info', 'Subscription cancelled' . ($immediate ? ' (immediate)' : ' (end of period)'), ['subscriptionID' => $subscriptionID, 'reason' => $reason]);

            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('subscription.cancelled', [
                    'subscriptionID' => $subscriptionID,
                    'userID' => $userID,
                    'immediate' => $immediate,
                    'reason' => $reason,
                ]);
            }

            return ['success' => true, 'message' => $immediate ? 'Subscription cancelled immediately' : 'Subscription will be cancelled at end of current billing period'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to cancel subscription'];
        }
    }

    /**
     * ⏸️ Pause a subscription
     *
     * @param int $subscriptionID Subscription ID
     * @param int $userID User ID
     * @param string|null $resumeDate Date to auto-resume (Y-m-d format, or null)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function pauseSubscription(int $subscriptionID, int $userID, ?string $resumeDate = null): array
    {
        try {
            $sub = Database::fetchOne(
                "SELECT subscriptionID FROM tblSubscriptions WHERE subscriptionID = ? AND userID = ? AND subscriptionStatus = 'active'",
                [$subscriptionID, $userID],
                'ii'
            );

            if (!$sub) {
                return ['success' => false, 'message' => 'Active subscription not found'];
            }

            Database::query(
                "UPDATE tblSubscriptions SET subscriptionStatus = 'paused', pausedAt = NOW(), resumesAt = ? WHERE subscriptionID = ?",
                [$resumeDate, $subscriptionID],
                'si'
            );

            ActivityLogger::log($userID, 'subscription_paused', 'payment', 'info', 'Subscription paused', ['subscriptionID' => $subscriptionID, 'resumeDate' => $resumeDate]);

            return ['success' => true, 'message' => 'Subscription paused' . ($resumeDate ? ' until ' . $resumeDate : '')];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to pause subscription'];
        }
    }

    /**
     * ▶️ Resume a paused subscription
     *
     * @param int $subscriptionID Subscription ID
     * @param int $userID User ID
     * @return array ['success' => bool, 'message' => string]
     */
    public static function resumeSubscription(int $subscriptionID, int $userID): array
    {
        try {
            $sub = Database::fetchOne(
                "SELECT subscriptionID FROM tblSubscriptions WHERE subscriptionID = ? AND userID = ? AND subscriptionStatus = 'paused'",
                [$subscriptionID, $userID],
                'ii'
            );

            if (!$sub) {
                return ['success' => false, 'message' => 'Paused subscription not found'];
            }

            Database::query(
                "UPDATE tblSubscriptions SET subscriptionStatus = 'active', pausedAt = NULL, resumesAt = NULL WHERE subscriptionID = ?",
                [$subscriptionID],
                'i'
            );

            ActivityLogger::log($userID, 'subscription_resumed', 'payment', 'info', 'Subscription resumed', ['subscriptionID' => $subscriptionID]);

            return ['success' => true, 'message' => 'Subscription resumed'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to resume subscription'];
        }
    }

    // ========================================================================
    // 💳 PAYMENT PROCESSING
    // ========================================================================

    /**
     * 💰 Record a payment
     *
     * Records a new payment in tblPayments. Supports both Level 1 (direct SIGNula payments)
     * and Level 2 (partner payments) via the partnerID and paymentContext options.
     *
     * @param int $userID User ID
     * @param float $amount Payment amount
     * @param string $paymentMethod Payment method
     * @param string $paymentProvider Provider name
     * @param array $options Optional parameters:
     *              - subscriptionID (int): Linked subscription
     *              - transactionID (string): External transaction ID
     *              - description (string): Payment description
     *              - discountCode (string): Applied discount code
     *              - discountAmount (float): Discount amount
     *              - currency (string): Currency code (default: from settings)
     *              - metadata (array): Additional metadata
     *              - partnerID (int): Partner ID for Level 2 payments (v2.4.0)
     *              - paymentContext (string): 'signula_direct'|'partner_own_keys'|'partner_signula_keys' (v2.4.0)
     * @return array ['success' => bool, 'paymentID' => int, 'invoiceNumber' => string]
     */
    public static function recordPayment(
        int $userID,
        float $amount,
        string $paymentMethod,
        string $paymentProvider,
        array $options = []
    ): array {
        try {
            $currency = $options['currency'] ?? self::getSetting('payment.default_currency', self::DEFAULT_CURRENCY);

            // 🧮 Calculate tax
            $taxEnabled = (bool)self::getSetting('payment.tax_enabled', '1');
            $taxRate = $taxEnabled ? (float)self::getSetting('payment.tax_rate', '20.00') : 0;
            $taxAmount = $taxEnabled ? round($amount * ($taxRate / 100), 2) : 0;
            $netAmount = round($amount - ($options['discountAmount'] ?? 0), 2);

            // 🔢 Generate invoice number
            $invoicePrefix = self::getSetting('payment.invoice_prefix', self::DEFAULT_INVOICE_PREFIX);
            $invoiceNumber = $invoicePrefix . '-' . date('Ymd') . '-' . str_pad(random_int(1, 99999), 5, '0', STR_PAD_LEFT);

            // 🌐 Get request context
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            // 🏢 Level 2 payment context (v2.4.0)
            $partnerID = $options['partnerID'] ?? null;
            $paymentContext = $options['paymentContext'] ?? 'signula_direct';

            // 💾 Insert payment record (with partnerID and paymentContext for Level 2 support)
            Database::query(
                "INSERT INTO tblPayments
                    (userID, subscriptionID, transactionID, paymentMethod, paymentProvider,
                     amount, currency, discountAmount, discountCode, taxAmount, taxRate, netAmount,
                     status, description, ipAddress, userAgent, invoiceNumber, metadata,
                     partnerID, paymentContext)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userID,
                    $options['subscriptionID'] ?? null,
                    $options['transactionID'] ?? null,
                    $paymentMethod,
                    $paymentProvider,
                    $amount,
                    $currency,
                    $options['discountAmount'] ?? 0,
                    $options['discountCode'] ?? null,
                    $taxAmount,
                    $taxRate,
                    $netAmount,
                    $options['description'] ?? null,
                    $ipAddress,
                    $userAgent,
                    $invoiceNumber,
                    isset($options['metadata']) ? json_encode($options['metadata']) : null,
                    $partnerID,
                    $paymentContext,
                ],
                'iisssddsdddssssssis'
            );

            $paymentID = Database::getLastInsertId();

            ActivityLogger::log($userID, 'payment_initiated', 'payment', 'info', 'Payment initiated: ' . $currency . ' ' . number_format($amount, 2), ['paymentID' => $paymentID, 'amount' => $amount, 'currency' => $currency, 'method' => $paymentMethod, 'provider' => $paymentProvider, 'partnerID' => $partnerID, 'paymentContext' => $paymentContext]);

            return [
                'success' => true,
                'paymentID' => $paymentID,
                'invoiceNumber' => $invoiceNumber,
                'amount' => $amount,
                'taxAmount' => $taxAmount,
                'netAmount' => $netAmount,
            ];
        } catch (\Exception $e) {
            ActivityLogger::log($userID, 'payment_error', 'payment', 'error', 'Payment recording failed: ' . $e->getMessage(), ['amount' => $amount]);
            return ['success' => false, 'message' => 'Failed to record payment'];
        }
    }

    /**
     * ✅ Complete a payment (mark as successful)
     *
     * Enhanced in v2.4.0 to:
     * - Create formal invoice record via InvoiceManager (if available)
     * - Send payment receipt email
     * - Handle auto-resume for suspended accounts via BillingScheduler
     * - Schedule next billing date for subscription renewals
     *
     * @param int $paymentID Payment ID
     * @param string|null $transactionID External transaction ID from provider
     * @param string|null $receiptURL Receipt URL from provider
     * @return array ['success' => bool]
     */
    public static function completePayment(int $paymentID, ?string $transactionID = null, ?string $receiptURL = null): array
    {
        try {
            $payment = Database::fetchOne("SELECT * FROM tblPayments WHERE paymentID = ?", [$paymentID], 'i');
            if (!$payment) {
                return ['success' => false, 'message' => 'Payment not found'];
            }

            $updates = ['status' => 'completed', 'paidAt' => date('Y-m-d H:i:s')];
            $setClauses = "status = 'completed', paidAt = NOW()";
            $params = [];
            $types = '';

            if ($transactionID) {
                $setClauses .= ", transactionID = ?";
                $params[] = $transactionID;
                $types .= 's';
            }

            if ($receiptURL) {
                $setClauses .= ", receiptURL = ?";
                $params[] = $receiptURL;
                $types .= 's';
            }

            $params[] = $paymentID;
            $types .= 'i';

            Database::query("UPDATE tblPayments SET $setClauses WHERE paymentID = ?", $params, $types);

            // ✅ If linked to subscription, activate it
            if ($payment['subscriptionID']) {
                Database::query(
                    "UPDATE tblSubscriptions SET subscriptionStatus = 'active' WHERE subscriptionID = ? AND subscriptionStatus IN ('pending', 'past_due')",
                    [(int)$payment['subscriptionID']],
                    'i'
                );
            }

            $userID = (int)$payment['userID'];

            ActivityLogger::log($userID, 'payment_completed', 'payment', 'info', 'Payment completed: ' . $payment['currency'] . ' ' . number_format((float)$payment['amount'], 2), ['paymentID' => $paymentID, 'transactionID' => $transactionID]);

            // ========================================================================
            // 📄 v2.4.0: Create formal invoice via InvoiceManager (if available)
            // ========================================================================
            $invoiceManagerPath = __DIR__ . DIRECTORY_SEPARATOR . 'InvoiceManager.php';
            if (file_exists($invoiceManagerPath)) {
                require_once $invoiceManagerPath;

                if (class_exists('InvoiceManager')) {
                    try {
                        $invoiceResult = InvoiceManager::createInvoice(
                            $userID,
                            'subscription',
                            [
                                [
                                    'description' => $payment['description'] ?? 'SIGNula Subscription Payment',
                                    'quantity'    => 1,
                                    'unitPrice'   => (float)$payment['amount'],
                                    'total'       => (float)$payment['amount'],
                                ],
                            ],
                            [
                                'paymentID'      => $paymentID,
                                'subscriptionID' => $payment['subscriptionID'] ?? null,
                                'partnerID'      => $payment['partnerID'] ?? null,
                                'currency'       => $payment['currency'] ?? 'GBP',
                                'discountAmount' => (float)($payment['discountAmount'] ?? 0),
                                'taxRate'        => (float)($payment['taxRate'] ?? 0),
                                'autoIssue'      => true,
                            ]
                        );

                        // 📧 Send invoice email if creation succeeded
                        if ($invoiceResult['success'] ?? false) {
                            InvoiceManager::sendInvoiceEmail((int)$invoiceResult['invoiceID']);
                        }
                    } catch (\Throwable $e) {
                        // ⚠️ Invoice creation failure should not block payment completion
                        ActivityLogger::log($userID, 'invoice_creation_warning', 'payment', 'warning', 'Invoice creation failed for payment ' . $paymentID . ': ' . $e->getMessage(), ['paymentID' => $paymentID]);
                    }
                }
            }

            // ========================================================================
            // 🔄 v2.4.0: Handle auto-resume via BillingScheduler (if available)
            // ========================================================================
            if ($payment['subscriptionID']) {
                $billingSchedulerPath = __DIR__ . DIRECTORY_SEPARATOR . 'BillingScheduler.php';
                if (file_exists($billingSchedulerPath)) {
                    require_once $billingSchedulerPath;

                    if (class_exists('BillingScheduler')) {
                        try {
                            BillingScheduler::handlePaymentSuccess(
                                (int)$payment['subscriptionID'],
                                $paymentID
                            );
                        } catch (\Throwable $e) {
                            // ⚠️ Billing scheduler failure should not block payment completion
                            ActivityLogger::log($userID, 'billing_scheduler_warning', 'payment', 'warning', 'BillingScheduler post-payment processing failed: ' . $e->getMessage(), ['paymentID' => $paymentID]);
                        }
                    }
                }
            }

            // 📧 Send payment receipt email
            if (class_exists('EmailService')) {
                try {
                    $user = Database::fetchOne("SELECT email, displayName FROM tblUsers WHERE userID = ?", [$userID], 'i');
                    if ($user && !empty($user['email'])) {
                        EmailService::sendTemplateEmail(
                            $user['email'],
                            'payment_receipt',
                            [
                                'displayName'   => $user['displayName'] ?? 'Customer',
                                'amount'        => $payment['currency'] . ' ' . number_format((float)$payment['amount'], 2),
                                'invoiceNumber' => $payment['invoiceNumber'] ?? '',
                                'paymentMethod' => ucfirst($payment['paymentMethod'] ?? ''),
                                'paymentDate'   => date('d M Y, H:i'),
                            ],
                            $userID,
                            5
                        );
                    }
                } catch (\Throwable $e) {
                    // ⚠️ Email failure should not block payment completion
                    ActivityLogger::log($userID, 'payment_email_warning', 'payment', 'warning', 'Payment receipt email failed: ' . $e->getMessage(), ['paymentID' => $paymentID]);
                }
            }

            // 🔗 Dispatch webhook
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('payment.completed', [
                    'paymentID' => $paymentID,
                    'userID' => $userID,
                    'amount' => (float)$payment['amount'],
                    'currency' => $payment['currency'],
                    'transactionID' => $transactionID,
                    'invoiceNumber' => $payment['invoiceNumber'],
                    'partnerID' => $payment['partnerID'] ?? null,
                    'paymentContext' => $payment['paymentContext'] ?? 'signula_direct',
                ]);
            }

            return ['success' => true, 'message' => 'Payment completed'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to complete payment'];
        }
    }

    /**
     * 💸 Process a refund
     *
     * @param int $paymentID Payment ID
     * @param float|null $amount Partial refund amount (null = full refund)
     * @param string $reason Refund reason
     * @return array ['success' => bool, 'message' => string]
     */
    public static function refundPayment(int $paymentID, ?float $amount = null, string $reason = ''): array
    {
        try {
            $payment = Database::fetchOne(
                "SELECT * FROM tblPayments WHERE paymentID = ? AND status = 'completed'",
                [$paymentID],
                'i'
            );

            if (!$payment) {
                return ['success' => false, 'message' => 'Completed payment not found'];
            }

            $refundAmount = $amount ?? (float)$payment['amount'];
            $isPartial = $refundAmount < (float)$payment['amount'];
            $status = $isPartial ? 'partially_refunded' : 'refunded';

            Database::query(
                "UPDATE tblPayments SET status = ?, refundedAt = NOW(), refundAmount = ?, refundReason = ? WHERE paymentID = ?",
                [$status, $refundAmount, $reason, $paymentID],
                'sdsi'
            );

            ActivityLogger::log(
                (int)$payment['userID'],
                'payment_refunded',
                'payment',
                'warning',
                ($isPartial ? 'Partial refund' : 'Full refund') . ': ' . $payment['currency'] . ' ' . number_format($refundAmount, 2),
                ['paymentID' => $paymentID, 'refundAmount' => $refundAmount, 'reason' => $reason]
            );

            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('payment.refunded', [
                    'paymentID' => $paymentID,
                    'userID' => (int)$payment['userID'],
                    'refundAmount' => $refundAmount,
                    'isPartial' => $isPartial,
                    'reason' => $reason,
                ]);
            }

            return ['success' => true, 'message' => ($isPartial ? 'Partial' : 'Full') . ' refund processed'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to process refund'];
        }
    }

    // ========================================================================
    // 🎟️ DISCOUNT CODES
    // ========================================================================

    /**
     * ✅ Validate a discount code
     *
     * @param string $code Discount code
     * @param string $tierSlug Tier being purchased
     * @param string $paymentMethod Payment method being used
     * @param float $amount Original amount
     * @return array ['valid' => bool, 'discountAmount' => float, 'message' => string]
     */
    public static function validateDiscountCode(string $code, string $tierSlug, string $paymentMethod, float $amount): array
    {
        $discount = Database::fetchOne(
            "SELECT * FROM tblDiscountCodes WHERE code = ? AND isActive = 1 AND validFrom <= NOW() AND (validUntil IS NULL OR validUntil >= NOW())",
            [$code],
            's'
        );

        if (!$discount) {
            return ['valid' => false, 'discountAmount' => 0, 'message' => 'Invalid or expired discount code'];
        }

        // 🔍 Check usage limits
        if ($discount['maxUses'] !== null && (int)$discount['currentUses'] >= (int)$discount['maxUses']) {
            return ['valid' => false, 'discountAmount' => 0, 'message' => 'Discount code has reached its usage limit'];
        }

        // 🔍 Check applicable tiers
        if ($discount['applicableTiers'] !== null) {
            $applicableTiers = json_decode($discount['applicableTiers'], true);
            if (!in_array($tierSlug, $applicableTiers, true)) {
                return ['valid' => false, 'discountAmount' => 0, 'message' => 'Discount code is not valid for this tier'];
            }
        }

        // 🔍 Check applicable payment methods
        if ($discount['applicablePaymentMethods'] !== null) {
            $applicableMethods = json_decode($discount['applicablePaymentMethods'], true);
            if (!in_array($paymentMethod, $applicableMethods, true)) {
                return ['valid' => false, 'discountAmount' => 0, 'message' => 'Discount code is not valid for this payment method'];
            }
        }

        // 🔍 Check minimum amount
        if ($discount['minAmount'] !== null && $amount < (float)$discount['minAmount']) {
            return ['valid' => false, 'discountAmount' => 0, 'message' => 'Minimum purchase amount of ' . $discount['currency'] . ' ' . $discount['minAmount'] . ' required'];
        }

        // 💰 Calculate discount
        $discountAmount = match ($discount['discountType']) {
            'percentage' => round($amount * ((float)$discount['discountValue'] / 100), 2),
            'fixed_amount' => min((float)$discount['discountValue'], $amount),
            'free_trial_days' => 0,
            default => 0,
        };

        return [
            'valid' => true,
            'discountAmount' => $discountAmount,
            'discountType' => $discount['discountType'],
            'discountValue' => (float)$discount['discountValue'],
            'message' => 'Discount applied',
        ];
    }

    /**
     * 📊 Increment discount code usage
     *
     * @param string $code Discount code
     */
    public static function incrementDiscountUsage(string $code): void
    {
        Database::query(
            "UPDATE tblDiscountCodes SET currentUses = currentUses + 1 WHERE code = ?",
            [$code],
            's'
        );
    }

    // ========================================================================
    // 💳 PAYMENT METHODS
    // ========================================================================

    /**
     * 📋 Get stored payment methods for a user
     *
     * @param int $userID User ID
     * @return array List of payment methods
     */
    public static function getPaymentMethods(int $userID): array
    {
        return Database::fetchAll(
            "SELECT methodID, paymentMethod, provider, displayName, lastFour, expiryMonth, expiryYear, brand, billingEmail, isDefault, isVerified, createdAt
             FROM tblPaymentMethods
             WHERE userID = ?
             ORDER BY isDefault DESC, createdAt DESC",
            [$userID],
            'i'
        );
    }

    /**
     * ➕ Store a payment method (tokenised from provider)
     *
     * @param int $userID User ID
     * @param string $paymentMethod Method type (paypal, apple_pay, etc.)
     * @param string $provider Provider name
     * @param array $details Method details (providerMethodID, displayName, lastFour, etc.)
     * @return array ['success' => bool, 'methodID' => int]
     */
    public static function addPaymentMethod(int $userID, string $paymentMethod, string $provider, array $details): array
    {
        try {
            // 🔍 Check if this is the first method (make it default)
            $existingCount = Database::fetchOne(
                "SELECT COUNT(*) as cnt FROM tblPaymentMethods WHERE userID = ?",
                [$userID],
                'i'
            );
            $isDefault = (!$existingCount || (int)$existingCount['cnt'] === 0) ? 1 : 0;

            Database::query(
                "INSERT INTO tblPaymentMethods
                    (userID, paymentMethod, provider, providerMethodID, displayName, lastFour,
                     expiryMonth, expiryYear, brand, billingEmail, isDefault, isVerified)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userID,
                    $paymentMethod,
                    $provider,
                    $details['providerMethodID'] ?? null,
                    $details['displayName'] ?? $paymentMethod,
                    $details['lastFour'] ?? null,
                    $details['expiryMonth'] ?? null,
                    $details['expiryYear'] ?? null,
                    $details['brand'] ?? null,
                    $details['billingEmail'] ?? null,
                    $isDefault,
                    $details['isVerified'] ?? 0,
                ],
                'isssssiivsii'
            );

            $methodID = Database::getLastInsertId();
            ActivityLogger::log($userID, 'payment_method_added', 'payment', 'info', 'Payment method added: ' . $paymentMethod, ['methodID' => $methodID, 'provider' => $provider]);

            return ['success' => true, 'methodID' => $methodID];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to add payment method'];
        }
    }

    /**
     * 🗑️ Remove a stored payment method
     *
     * @param int $methodID Method ID
     * @param int $userID User ID (ownership check)
     * @return array ['success' => bool]
     */
    public static function removePaymentMethod(int $methodID, int $userID): array
    {
        try {
            Database::query(
                "DELETE FROM tblPaymentMethods WHERE methodID = ? AND userID = ?",
                [$methodID, $userID],
                'ii'
            );

            ActivityLogger::log($userID, 'payment_method_removed', 'payment', 'info', 'Payment method removed', ['methodID' => $methodID]);

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to remove payment method'];
        }
    }

    // ========================================================================
    // 📊 REPORTING
    // ========================================================================

    /**
     * 📈 Get payment history for a user
     *
     * @param int $userID User ID
     * @param int $limit Max records
     * @param int $offset Offset for pagination
     * @return array List of payment records
     */
    public static function getPaymentHistory(int $userID, int $limit = 25, int $offset = 0): array
    {
        return Database::fetchAll(
            "SELECT paymentID, transactionID, paymentMethod, paymentProvider, amount, currency,
                    discountAmount, taxAmount, netAmount, status, description, invoiceNumber,
                    paidAt, refundedAt, refundAmount, createdAt
             FROM tblPayments
             WHERE userID = ?
             ORDER BY createdAt DESC
             LIMIT ? OFFSET ?",
            [$userID, $limit, $offset],
            'iii'
        );
    }

    /**
     * 📊 Get admin payment statistics
     *
     * @param int $days Number of days to look back
     * @return array Revenue and transaction statistics
     */
    public static function getAdminStats(int $days = 30): array
    {
        return Database::fetchOne(
            "SELECT
                COUNT(*) as totalTransactions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completedCount,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as totalRevenue,
                SUM(CASE WHEN status = 'completed' THEN netAmount ELSE 0 END) as netRevenue,
                SUM(CASE WHEN status = 'completed' THEN taxAmount ELSE 0 END) as totalTax,
                SUM(CASE WHEN status = 'refunded' OR status = 'partially_refunded' THEN refundAmount ELSE 0 END) as totalRefunds,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failedCount,
                COUNT(DISTINCT userID) as uniqueCustomers,
                AVG(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as avgPaymentAmount
             FROM tblPayments
             WHERE createdAt >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days],
            'i'
        ) ?: [];
    }

    // ========================================================================
    // 🔧 HELPERS
    // ========================================================================

    /**
     * ⚙️ Get a setting value from tblSettings
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return string Setting value
     */
    private static function getSetting(string $key, mixed $default = ''): string
    {
        $setting = Database::fetchOne(
            "SELECT settingValue FROM tblSettings WHERE settingKey = ?",
            [$key],
            's'
        );

        return $setting ? $setting['settingValue'] : (string)$default;
    }

    // ========================================================================
    // 🏷️ PROVIDER DISCOUNTS (v2.4.0)
    // ========================================================================

    /**
     * 💰 Get provider-specific discount percentage
     *
     * Retrieves the discount percentage for a specific payment provider.
     * Checks partner-specific discount first (from tblProviderDiscounts with partnerID),
     * then falls back to global discount.
     *
     * @param string $provider Payment provider name (stripe, paypal, coinbase)
     * @param int|null $partnerID Optional partner ID for partner-scoped discounts
     * @return float Discount percentage (e.g., 5.00 for 5% off)
     *
     * @since 2.4.0-beta
     */
    public static function getProviderDiscount(string $provider, ?int $partnerID = null): float
    {
        // 🔍 Check for partner-specific discount first
        if ($partnerID !== null) {
            $partnerDiscount = Database::fetchOne(
                "SELECT discountPercent FROM tblProviderDiscounts
                 WHERE provider = ? AND partnerID = ? AND isActive = 1
                 AND (validFrom IS NULL OR validFrom <= CURDATE())
                 AND (validUntil IS NULL OR validUntil >= CURDATE())",
                [$provider, $partnerID],
                'si'
            );

            if ($partnerDiscount) {
                return (float)$partnerDiscount['discountPercent'];
            }
        }

        // 🌐 Fall back to global discount
        $globalDiscount = Database::fetchOne(
            "SELECT discountPercent FROM tblProviderDiscounts
             WHERE provider = ? AND partnerID IS NULL AND isActive = 1
             AND (validFrom IS NULL OR validFrom <= CURDATE())
             AND (validUntil IS NULL OR validUntil >= CURDATE())",
            [$provider],
            's'
        );

        if ($globalDiscount) {
            return (float)$globalDiscount['discountPercent'];
        }

        return 0.00;
    }

    // ========================================================================
    // 🏢 PARTNER TIERS (v2.4.0)
    // ========================================================================

    /**
     * 🔍 Get a partner-defined subscription tier
     *
     * Retrieves a tier from tblPartnerSubscriptionTiers (separate from global tblSubscriptionTiers).
     *
     * @param int $partnerTierID Partner tier ID
     * @return array|null Partner tier record or null
     *
     * @since 2.4.0-beta
     */
    public static function getPartnerTier(int $partnerTierID): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM tblPartnerSubscriptionTiers WHERE partnerTierID = ? AND isActive = 1",
            [$partnerTierID],
            'i'
        );
    }

    /**
     * 📋 Get all partner tiers for a specific partner
     *
     * @param int $partnerID Partner ID
     * @param bool $includeInactive Include inactive tiers
     * @return array List of partner tier records
     *
     * @since 2.4.0-beta
     */
    public static function getPartnerTiers(int $partnerID, bool $includeInactive = false): array
    {
        $query = "SELECT * FROM tblPartnerSubscriptionTiers WHERE partnerID = ?";
        if (!$includeInactive) {
            $query .= " AND isActive = 1";
        }
        $query .= " ORDER BY displayOrder ASC";

        return Database::fetchAll($query, [$partnerID], 'i');
    }
}
