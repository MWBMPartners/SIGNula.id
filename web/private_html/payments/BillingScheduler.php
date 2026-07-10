<?php
/**
 * SIGNula - Universal Single Sign-On Authentication System
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package SIGNula
 * @version 2.4.0-beta
 */

/**
 * ============================================================================
 * ⏰ SIGNula - Billing Scheduler
 * ============================================================================
 *
 * Purpose: Automated billing task scheduling, execution, and lifecycle management
 * PHP Version: 8.3+
 *
 * Architecture Overview:
 * Since Dreamhost shared hosting has no CLI/cron access, billing tasks are
 * executed via a three-tier fallback strategy:
 *
 *   1. MySQL Scheduled Events — mark past_due, expire trials, mark overdue
 *      invoices (runs hourly inside MySQL, already defined in migration)
 *
 *   2. Web-accessible cron endpoints — called by an external cron service
 *      (e.g. cron-job.org) hitting a secured HTTPS endpoint that invokes
 *      BillingScheduler::processDueTasks()
 *
 *   3. Lazy-check safety net — included on authenticated page loads; if
 *      the external cron hasn't run in >1 hour, a small batch of tasks
 *      is processed inline via BillingScheduler::processLazyBatch()
 *
 * Features:
 * - Subscription charge scheduling and retry with configurable attempts
 * - Payment reminder emails at configurable intervals before due date
 * - Auto-suspension after grace period with tier preservation
 * - Auto-resume on successful payment (restore original tier)
 * - Generic task queue with status tracking and metadata
 * - Scheduler health monitoring for admin dashboards
 * - Lazy batch processing as a safety net when cron is unreachable
 * - Webhook dispatching for all subscription lifecycle events
 * - Full activity logging for audit and debugging
 *
 * Database Table:
 * - tblBillingSchedule — task queue with status, retry, and metadata
 *
 * Settings (from tblSettings):
 * - billing.grace_period_days       (default: 7)
 * - billing.reminder_days           (default: '7,3,1')
 * - billing.advance_billing_days    (default: 30)
 * - billing.max_charge_attempts     (default: 3)
 * - billing.auto_suspend_enabled    (default: '1')
 * - billing.last_cron_run           (updated after each processDueTasks run)
 *
 * Auto-Suspension Flow:
 *   1. MySQL event marks subscription past_due when nextBillingDate < CURDATE()
 *   2. BillingScheduler sends reminder emails at configurable intervals
 *   3. After grace period: store accountTier -> suspendedTier, set accountTier='free'
 *   4. Send subscription_suspended email, dispatch webhook
 *
 * Auto-Resume Flow:
 *   1. PaymentManager::completePayment() calls BillingScheduler::handlePaymentSuccess()
 *   2. Check if tblPartners.suspendedAt IS NOT NULL
 *   3. Restore accountTier from suspendedTier, clear suspension fields
 *   4. Set paymentStatus='active', calculate new nextBillingDate
 *   5. Send subscription_restored email, dispatch webhook
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/events.html (MySQL Scheduled Events)
 * @see https://www.php.net/manual/en/class.datetime.php (PHP DateTime)
 * @see https://www.php.net/manual/en/function.json-encode.php (JSON metadata)
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.0.0
 * @since      2.4.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access — all SIGNula files must be loaded via the bootstrap
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class BillingScheduler
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /**
     * @var array Valid task types for tblBillingSchedule.taskType ENUM.
     *
     * 🩹 B-025: widened to match BOTH the current DB ENUM (migration
     * 012 + 018's taskType MODIFY) AND every literal this class actually
     * passes to createTask() elsewhere in this file. Before this fix,
     * 'generate_invoice', 'fee_change_notification', 'calculate_usage',
     * 'charge_usage', and 'archive_usage' were valid ENUM members that
     * self::createTask()'s own validation silently REJECTED (grep-confirmed
     * call sites: processUsageCalculation() -> createTask('charge_usage', …)
     * at ~L2400 and similar usage-billing scheduling calls) — the opposite
     * direction of the schema↔code drift from the taskID/scheduleID and
     * missing-column issues fixed elsewhere in this migration/PR.
     */
    const TASK_TYPES = [
        'charge_subscription',
        'send_reminder',
        'suspend_account',
        'expire_trial',
        'process_remittance',
        'generate_invoice',
        'fee_change_notification',
        'calculate_usage',
        'charge_usage',
        'archive_usage',
        // 🩹 G-002 S4 — dunning retry ladder (§5 of the spec). Extended via
        // migration 039's `taskType` ENUM widen (mirrors the B-025 pattern
        // migration 018 used to add the usage-billing task types above).
        'dunning_retry',
        'dunning_final_cancel',
    ];

    /** @var array Valid target types for tblBillingSchedule.targetType ENUM */
    const TARGET_TYPES = [
        'subscription',
        'partner',
        'user',
        'remittance',
    ];

    /** @var array Valid task statuses for tblBillingSchedule.status ENUM */
    const TASK_STATUSES = [
        'pending',
        'processing',
        'completed',
        'failed',
        'cancelled',
    ];

    /** @var int Default batch size for scheduled task processing */
    const DEFAULT_BATCH_SIZE = 50;

    /** @var int Small batch size for lazy (safety-net) processing */
    const LAZY_BATCH_SIZE = 5;

    /** @var int Seconds threshold before lazy processing kicks in (1 hour) */
    const LAZY_THRESHOLD_SECONDS = 3600;

    /** @var int Default retry interval in minutes when a charge fails */
    const DEFAULT_RETRY_MINUTES = 60;

    // ========================================================================
    // 🚀 PRIMARY PROCESSING — Cron Entry Point
    // ========================================================================

    /**
     * 🚀 Process Due Tasks
     *
     * Main entry point called by the external cron endpoint. Queries the
     * tblBillingSchedule table for pending tasks whose scheduledFor datetime
     * is at or before the current time, then routes each task to the
     * appropriate handler method based on taskType.
     *
     * After processing, the last-run timestamp is persisted to tblSettings
     * so that the lazy-check safety net knows when the cron last ran.
     *
     * @param int $batchSize Maximum number of tasks to process in this run
     *                       (default: 50). Keep reasonable to avoid timeouts
     *                       on shared hosting.
     *
     * @return array {
     *     @type bool   $success   Whether the processing run completed without fatal error
     *     @type int    $processed Count of tasks that completed successfully
     *     @type int    $failed    Count of tasks that failed during this run
     *     @type array  $results   Per-task result details for logging/debugging
     * }
     *
     * @see self::getTasksDue()        Fetches the pending task batch
     * @see self::markTaskProcessed()  Updates task status post-processing
     * @see self::retryTask()          Schedules a failed task for retry
     * @see https://www.php.net/manual/en/function.date.php
     */
    public static function processDueTasks(int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        $processed = 0;
        $failed = 0;
        $results = [];

        try {
            // 📋 Fetch all pending tasks that are due for processing
            $tasks = self::getTasksDue($batchSize);

            // 🔄 Iterate through each due task and route to the appropriate handler
            foreach ($tasks as $task) {
                $taskID = (int) $task['taskID'];
                $taskType = $task['taskType'];
                $targetType = $task['targetType'];
                $targetID = (int) $task['targetID'];

                // 🔒 Mark the task as 'processing' to prevent duplicate execution
                // by concurrent cron hits or lazy batch overlap
                self::markTaskProcessed($taskID, 'processing');

                try {
                    // 🔀 Route to the appropriate handler based on taskType
                    $result = match ($taskType) {
                        'charge_subscription' => self::processSubscriptionCharge($targetID),
                        'send_reminder'       => self::processSendReminder($task),
                        'suspend_account'     => self::processSuspension($targetType, $targetID),
                        'expire_trial'        => self::processTrialExpiry($targetID),
                        'process_remittance'  => self::processRemittance($targetID),
                        'calculate_usage'     => self::processUsageCalculation($targetID),
                        'charge_usage'        => self::processUsageCharge($targetID),
                        'archive_usage'       => self::processUsageArchival(),
                        'dunning_retry'       => self::processDunningRetry($task),
                        'dunning_final_cancel' => self::processDunningFinalCancel($task),
                        default               => ['success' => false, 'message' => 'Unknown task type: ' . $taskType],
                    };

                    // ✅ or ❌ Update the task status based on handler outcome
                    if ($result['success']) {
                        self::markTaskProcessed($taskID, 'completed', json_encode($result));
                        $processed++;
                    } else {
                        // 🔁 Check if we should retry or mark as permanently failed
                        $attempts = (int) $task['attempts'];
                        $maxAttempts = (int) $task['maxAttempts'];

                        if ($attempts < $maxAttempts) {
                            // 🔁 Schedule a retry — exponential backoff: retry_minutes * 2^attempt
                            $retryMinutes = self::DEFAULT_RETRY_MINUTES * pow(2, $attempts);
                            self::retryTask($taskID, $retryMinutes);
                        } else {
                            // ❌ Max attempts reached — mark as permanently failed
                            self::markTaskProcessed($taskID, 'failed', json_encode($result));
                        }

                        $failed++;
                    }

                    // 📊 Collect result for the response summary
                    $results[] = [
                        'taskID'   => $taskID,
                        'taskType' => $taskType,
                        'targetID' => $targetID,
                        'success'  => $result['success'],
                        'message'  => $result['message'] ?? '',
                    ];

                } catch (\Exception $e) {
                    // 🛑 Catch per-task exceptions so one failing task doesn't abort the batch
                    self::markTaskProcessed($taskID, 'failed', $e->getMessage());
                    $failed++;

                    $results[] = [
                        'taskID'   => $taskID,
                        'taskType' => $taskType,
                        'targetID' => $targetID,
                        'success'  => false,
                        'message'  => 'Exception: ' . $e->getMessage(),
                    ];

                    // 📝 Log the exception for debugging
                    ActivityLogger::log(
                        null,
                        'billing_task_exception',
                        'payment',
                        'error',
                        'Billing task #' . $taskID . ' threw exception: ' . $e->getMessage(),
                        [
                            'taskID'   => $taskID,
                            'taskType' => $taskType,
                            'targetID' => $targetID,
                            'trace'    => $e->getTraceAsString(),
                        ]
                    );
                }
            }

            // 📅 Persist last-run timestamp to tblSettings so the lazy safety net
            // can determine whether the external cron is still running
            // @see https://www.php.net/manual/en/function.date.php
            self::persistSetting('billing.last_cron_run', date('Y-m-d H:i:s'));

            // 📝 Log the overall cron run outcome
            ActivityLogger::log(
                null,
                'billing_cron_completed',
                'payment',
                'info',
                'Billing cron run completed: ' . $processed . ' processed, ' . $failed . ' failed out of ' . count($tasks) . ' tasks',
                [
                    'processed' => $processed,
                    'failed'    => $failed,
                    'total'     => count($tasks),
                    'batchSize' => $batchSize,
                ]
            );

            return [
                'success'   => true,
                'processed' => $processed,
                'failed'    => $failed,
                'results'   => $results,
            ];

        } catch (\Exception $e) {
            // 🛑 Fatal error during the entire cron run
            ActivityLogger::log(
                null,
                'billing_cron_fatal',
                'payment',
                'critical',
                'Billing cron run failed fatally: ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            return [
                'success'   => false,
                'processed' => $processed,
                'failed'    => $failed,
                'results'   => $results,
            ];
        }
    }

    // ========================================================================
    // 💳 SUBSCRIPTION CHARGE PROCESSING
    // ========================================================================

    /**
     * 💳 Process Subscription Charge
     *
     * Attempts to charge a subscription's linked payment method for the
     * current billing cycle amount. On success, updates the next billing date,
     * creates an invoice via PaymentManager, and sends a receipt email.
     * On failure, increments the attempt counter and, after exhausting max
     * attempts, schedules an auto-suspension task.
     *
     * @param int $subscriptionID The subscription to charge (tblSubscriptions.subscriptionID)
     *
     * @return array {
     *     @type bool   $success Whether the charge was successful
     *     @type string $message Human-readable outcome description
     * }
     *
     * @see PaymentManager::recordPayment()    Records the payment attempt
     * @see PaymentManager::completePayment()  Marks payment as completed
     * @see self::scheduleAutoSuspension()     Schedules suspension after max failures
     * @see self::scheduleSubscriptionRenewal() Schedules the next billing cycle
     * @see EmailService::sendTemplateEmail()  Sends receipt or failure notification
     * @see https://developer.paypal.com/docs/api/payments/v2/ (PayPal Payments)
     */
    public static function processSubscriptionCharge(int $subscriptionID): array
    {
        try {
            // 🔍 Fetch subscription details with tier information
            $subscription = Database::fetchOne(
                "SELECT s.*, t.tierName, t.tierSlug, t.monthlyPrice, t.yearlyPrice, t.currency
                 FROM tblSubscriptions s
                 JOIN tblSubscriptionTiers t ON s.tierID = t.tierID
                 WHERE s.subscriptionID = ?
                   AND s.subscriptionStatus IN ('active', 'past_due', 'trial')",
                [$subscriptionID],
                'i'
            );

            if (!$subscription) {
                return [
                    'success' => false,
                    'message' => 'Active subscription not found for ID: ' . $subscriptionID,
                ];
            }

            $userID = (int) $subscription['userID'];
            $amount = (float) $subscription['amount'];
            $currency = $subscription['currency'] ?? 'GBP';
            $paymentMethod = $subscription['paymentMethod'] ?? 'unknown';

            // 🆓 Skip charge for free subscriptions — just advance the billing date
            if ($amount <= 0) {
                // 📅 Advance to next billing period
                $nextBillingDate = self::calculateNextBillingDate($subscription['billingCycle']);

                Database::query(
                    "UPDATE tblSubscriptions
                     SET nextBillingDate = ?, currentPeriodStart = CURDATE(), currentPeriodEnd = ?,
                         subscriptionStatus = 'active'
                     WHERE subscriptionID = ?",
                    [$nextBillingDate, $nextBillingDate, $subscriptionID],
                    'ssi'
                );

                return [
                    'success' => true,
                    'message' => 'Free subscription renewed — no charge required',
                ];
            }

            // 💳 Look up the user's default payment method
            $paymentMethodRecord = Database::fetchOne(
                "SELECT * FROM tblPaymentMethods
                 WHERE userID = ? AND isDefault = 1 AND isVerified = 1
                 ORDER BY createdAt DESC
                 LIMIT 1",
                [$userID],
                'i'
            );

            if (!$paymentMethodRecord) {
                // ⚠️ No valid payment method on file — cannot charge
                ActivityLogger::log(
                    $userID,
                    'charge_no_payment_method',
                    'payment',
                    'warning',
                    'No verified default payment method found for subscription #' . $subscriptionID,
                    ['subscriptionID' => $subscriptionID]
                );

                return [
                    'success' => false,
                    'message' => 'No verified default payment method on file',
                ];
            }

            // 💰 Record the payment attempt via PaymentManager
            $paymentResult = PaymentManager::recordPayment(
                $userID,
                $amount,
                $paymentMethodRecord['paymentMethod'],
                $paymentMethodRecord['provider'],
                [
                    'subscriptionID' => $subscriptionID,
                    'currency'       => $currency,
                    'description'    => 'Subscription renewal: ' . $subscription['tierName'] . ' (' . $subscription['billingCycle'] . ')',
                    'metadata'       => [
                        'subscriptionID' => $subscriptionID,
                        'tierSlug'       => $subscription['tierSlug'],
                        'billingCycle'   => $subscription['billingCycle'],
                        'automated'      => true,
                    ],
                ]
            );

            if (!$paymentResult['success']) {
                // ❌ Payment recording failed
                self::handleChargeFailure($subscriptionID, $userID, 'Payment recording failed');
                return [
                    'success' => false,
                    'message' => 'Failed to record payment: ' . ($paymentResult['message'] ?? 'Unknown error'),
                ];
            }

            $paymentID = (int) $paymentResult['paymentID'];

            // 🔌 Attempt the actual charge via the appropriate payment provider
            // This delegates to PayPalProvider, StripeProvider, CoinbaseProvider etc.
            $chargeResult = self::executeProviderCharge(
                $paymentMethodRecord,
                $amount,
                $currency,
                $paymentResult['invoiceNumber'] ?? ''
            );

            if ($chargeResult['success']) {
                // ✅ Charge succeeded — complete the payment and advance billing date
                PaymentManager::completePayment(
                    $paymentID,
                    $chargeResult['transactionID'] ?? null,
                    $chargeResult['receiptURL'] ?? null
                );

                // 📅 Advance subscription to next billing period
                $nextBillingDate = self::calculateNextBillingDate($subscription['billingCycle']);

                Database::query(
                    "UPDATE tblSubscriptions
                     SET subscriptionStatus = 'active',
                         nextBillingDate = ?,
                         currentPeriodStart = CURDATE(),
                         currentPeriodEnd = ?
                     WHERE subscriptionID = ?",
                    [$nextBillingDate, $nextBillingDate, $subscriptionID],
                    'ssi'
                );

                // 📧 Send payment receipt email
                self::sendPaymentReceiptEmail($userID, $subscription, $amount, $currency, $paymentResult['invoiceNumber'] ?? '');

                // 📅 Schedule the next renewal cycle (charge + reminders)
                self::scheduleSubscriptionRenewal($subscriptionID, $nextBillingDate);

                // 📝 Log successful charge
                ActivityLogger::log(
                    $userID,
                    'subscription_charged',
                    'payment',
                    'info',
                    'Subscription #' . $subscriptionID . ' charged successfully: ' . $currency . ' ' . number_format($amount, 2),
                    [
                        'subscriptionID' => $subscriptionID,
                        'paymentID'      => $paymentID,
                        'amount'         => $amount,
                        'currency'       => $currency,
                        'nextBillingDate' => $nextBillingDate,
                    ]
                );

                return [
                    'success' => true,
                    'message' => 'Subscription charged successfully: ' . $currency . ' ' . number_format($amount, 2),
                ];

            } else {
                // ❌ Provider charge failed — handle failure (retry or suspend)
                self::handleChargeFailure($subscriptionID, $userID, $chargeResult['message'] ?? 'Provider charge failed');

                // 📧 Send payment failure notification email
                EmailService::sendTemplateEmail(
                    self::getUserEmail($userID),
                    'payment_failed',
                    [
                        '{{tierName}}'      => $subscription['tierName'],
                        '{{amount}}'        => $currency . ' ' . number_format($amount, 2),
                        '{{failureReason}}' => $chargeResult['message'] ?? 'Payment could not be processed',
                        '{{retryInfo}}'     => 'We will retry the charge automatically.',
                    ],
                    $userID,
                    2 // 📨 High priority
                );

                return [
                    'success' => false,
                    'message' => 'Charge failed: ' . ($chargeResult['message'] ?? 'Provider error'),
                ];
            }

        } catch (\Exception $e) {
            // 🛑 Unexpected exception during charge processing
            ActivityLogger::log(
                null,
                'charge_exception',
                'payment',
                'error',
                'Exception during subscription charge #' . $subscriptionID . ': ' . $e->getMessage(),
                [
                    'subscriptionID' => $subscriptionID,
                    'trace'          => $e->getTraceAsString(),
                ]
            );

            return [
                'success' => false,
                'message' => 'Charge processing exception: ' . $e->getMessage(),
            ];
        }
    }

    // ========================================================================
    // 🚫 SUSPENSION PROCESSING
    // ========================================================================

    /**
     * 🚫 Process Account Suspension
     *
     * Suspends a partner or subscription that has exceeded the grace period
     * without successful payment. For partners, the current accountTier is
     * preserved in the suspendedTier column so it can be restored upon
     * payment (auto-resume). For subscriptions, the status is set to
     * 'cancelled'.
     *
     * @param string $targetType Either 'subscription' or 'partner'
     * @param int    $targetID   The subscriptionID or partnerID to suspend
     *
     * @return array {
     *     @type bool   $success Whether the suspension was applied
     *     @type string $message Human-readable outcome description
     * }
     *
     * @see self::handlePaymentSuccess()     Auto-resume logic
     * @see WebhookManager::dispatch()       Dispatches subscription.suspended event
     * @see EmailService::sendTemplateEmail() Sends suspension notification
     */
    public static function processSuspension(string $targetType, int $targetID): array
    {
        try {
            // 🔍 Check if auto-suspension is enabled in settings
            $autoSuspendEnabled = self::getSetting('billing.auto_suspend_enabled', '1');
            if ($autoSuspendEnabled !== '1') {
                ActivityLogger::log(
                    null,
                    'suspension_skipped',
                    'payment',
                    'info',
                    'Auto-suspension is disabled in settings — skipping suspension for ' . $targetType . ' #' . $targetID,
                    ['targetType' => $targetType, 'targetID' => $targetID]
                );

                return [
                    'success' => true,
                    'message' => 'Auto-suspension is disabled — task skipped',
                ];
            }

            if ($targetType === 'partner') {
                // =========================================================
                // 🏢 PARTNER SUSPENSION
                // =========================================================
                // Store current accountTier in suspendedTier for future restore,
                // then downgrade to 'free' tier and mark as cancelled

                $partner = Database::fetchOne(
                    "SELECT partnerID, partnerName, accountTier, contactEmail, primaryUserID
                     FROM tblPartners
                     WHERE partnerID = ?",
                    [$targetID],
                    'i'
                );

                if (!$partner) {
                    return ['success' => false, 'message' => 'Partner not found: #' . $targetID];
                }

                // 💾 Preserve current tier and apply suspension
                Database::query(
                    "UPDATE tblPartners
                     SET suspendedTier = accountTier,
                         accountTier = 'free',
                         suspendedAt = NOW(),
                         paymentStatus = 'cancelled'
                     WHERE partnerID = ?",
                    [$targetID],
                    'i'
                );

                $userID = $partner['primaryUserID'] ? (int) $partner['primaryUserID'] : null;
                $contactEmail = $partner['contactEmail'] ?? '';

                // 📧 Send suspension notification email
                if (!empty($contactEmail)) {
                    EmailService::sendTemplateEmail(
                        $contactEmail,
                        'subscription_suspended',
                        [
                            '{{partnerName}}'  => $partner['partnerName'] ?? 'Partner',
                            '{{previousTier}}' => $partner['accountTier'] ?? 'unknown',
                            '{{reason}}'       => 'Payment not received within the grace period',
                            '{{supportURL}}'   => getSetting('app.url', 'https://signula.id') . '/support',
                        ],
                        $userID,
                        1 // 📨 Highest priority
                    );
                }

                // 📝 Log the suspension
                ActivityLogger::log(
                    $userID,
                    'partner_suspended',
                    'payment',
                    'warning',
                    'Partner #' . $targetID . ' (' . ($partner['partnerName'] ?? '') . ') suspended due to non-payment',
                    [
                        'partnerID'    => $targetID,
                        'previousTier' => $partner['accountTier'] ?? '',
                        'newTier'      => 'free',
                    ]
                );

                // 🔗 Dispatch webhook event to notify integrated services
                if (class_exists('WebhookManager')) {
                    WebhookManager::dispatch('subscription.suspended', [
                        'targetType'   => 'partner',
                        'partnerID'    => $targetID,
                        'partnerName'  => $partner['partnerName'] ?? '',
                        'previousTier' => $partner['accountTier'] ?? '',
                        'suspendedAt'  => date('Y-m-d H:i:s'),
                    ]);
                }

                return ['success' => true, 'message' => 'Partner #' . $targetID . ' suspended successfully'];

            } elseif ($targetType === 'subscription') {
                // =========================================================
                // 📦 SUBSCRIPTION SUSPENSION
                // =========================================================
                // Cancel the subscription directly

                $subscription = Database::fetchOne(
                    "SELECT s.subscriptionID, s.userID, s.tierID, t.tierName
                     FROM tblSubscriptions s
                     JOIN tblSubscriptionTiers t ON s.tierID = t.tierID
                     WHERE s.subscriptionID = ?
                       AND s.subscriptionStatus IN ('active', 'past_due', 'trial')",
                    [$targetID],
                    'i'
                );

                if (!$subscription) {
                    return ['success' => false, 'message' => 'Active subscription not found: #' . $targetID];
                }

                $userID = (int) $subscription['userID'];

                // 💾 Cancel the subscription
                Database::query(
                    "UPDATE tblSubscriptions
                     SET subscriptionStatus = 'cancelled',
                         cancelledAt = NOW(),
                         cancellationReason = 'Auto-cancelled due to non-payment after grace period',
                         autoRenew = 0,
                         endDate = CURDATE()
                     WHERE subscriptionID = ?",
                    [$targetID],
                    'i'
                );

                // 📧 Send suspension notification email
                $userEmail = self::getUserEmail($userID);
                if (!empty($userEmail)) {
                    EmailService::sendTemplateEmail(
                        $userEmail,
                        'subscription_suspended',
                        [
                            '{{tierName}}'   => $subscription['tierName'] ?? 'Unknown',
                            '{{reason}}'     => 'Payment not received within the grace period',
                            '{{supportURL}}' => getSetting('app.url', 'https://signula.id') . '/support',
                        ],
                        $userID,
                        1 // 📨 Highest priority
                    );
                }

                // 📝 Log the suspension
                ActivityLogger::log(
                    $userID,
                    'subscription_suspended',
                    'payment',
                    'warning',
                    'Subscription #' . $targetID . ' cancelled due to non-payment',
                    [
                        'subscriptionID' => $targetID,
                        'tierName'       => $subscription['tierName'] ?? '',
                    ]
                );

                // 🔗 Dispatch webhook event
                if (class_exists('WebhookManager')) {
                    WebhookManager::dispatch('subscription.suspended', [
                        'targetType'     => 'subscription',
                        'subscriptionID' => $targetID,
                        'userID'         => $userID,
                        'tierName'       => $subscription['tierName'] ?? '',
                        'suspendedAt'    => date('Y-m-d H:i:s'),
                    ]);
                }

                return ['success' => true, 'message' => 'Subscription #' . $targetID . ' cancelled successfully'];

            } else {
                return ['success' => false, 'message' => 'Unsupported target type for suspension: ' . $targetType];
            }

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'suspension_exception',
                'payment',
                'error',
                'Exception during suspension of ' . $targetType . ' #' . $targetID . ': ' . $e->getMessage(),
                ['targetType' => $targetType, 'targetID' => $targetID, 'trace' => $e->getTraceAsString()]
            );

            return ['success' => false, 'message' => 'Suspension failed: ' . $e->getMessage()];
        }
    }

    // ========================================================================
    // 📧 PAYMENT REMINDERS
    // ========================================================================

    /**
     * 📧 Send Payment Reminder
     *
     * Sends an email reminder to the subscription holder that payment is
     * due in a specified number of days. Uses the 'payment_reminder' email
     * template with dynamic variables for personalisation.
     *
     * @param int $subscriptionID The subscription to remind about
     * @param int $daysBeforeDue  Number of days remaining before due date
     *
     * @return array {
     *     @type bool   $success Whether the reminder was sent
     *     @type string $message Human-readable outcome description
     * }
     *
     * @see EmailService::sendTemplateEmail() Sends the templated email
     * @see https://www.php.net/manual/en/function.number-format.php
     */
    public static function sendPaymentReminder(int $subscriptionID, int $daysBeforeDue): array
    {
        try {
            // 🔍 Fetch subscription with tier details
            $subscription = Database::fetchOne(
                "SELECT s.*, t.tierName, t.tierSlug, t.currency
                 FROM tblSubscriptions s
                 JOIN tblSubscriptionTiers t ON s.tierID = t.tierID
                 WHERE s.subscriptionID = ?
                   AND s.subscriptionStatus IN ('active', 'past_due', 'trial')",
                [$subscriptionID],
                'i'
            );

            if (!$subscription) {
                return ['success' => false, 'message' => 'Active subscription not found: #' . $subscriptionID];
            }

            $userID = (int) $subscription['userID'];
            $amount = (float) $subscription['amount'];
            $currency = $subscription['currency'] ?? 'GBP';
            $dueDate = $subscription['nextBillingDate'] ?? 'Unknown';

            // 📧 Send the reminder email via the template system
            $userEmail = self::getUserEmail($userID);
            if (empty($userEmail)) {
                return ['success' => false, 'message' => 'No email address found for user #' . $userID];
            }

            $emailSent = EmailService::sendTemplateEmail(
                $userEmail,
                'payment_reminder',
                [
                    '{{tierName}}'      => $subscription['tierName'] ?? 'Your Plan',
                    '{{amount}}'        => $currency . ' ' . number_format($amount, 2),
                    '{{dueDate}}'       => $dueDate,
                    '{{daysRemaining}}' => (string) $daysBeforeDue,
                    '{{billingCycle}}'  => $subscription['billingCycle'] ?? 'monthly',
                    '{{manageURL}}'     => getSetting('app.url', 'https://signula.id') . '/account/billing',
                ],
                $userID,
                3 // 📨 Medium-high priority
            );

            // 📝 Log the reminder
            ActivityLogger::log(
                $userID,
                'payment_reminder_sent',
                'payment',
                'info',
                'Payment reminder sent for subscription #' . $subscriptionID . ' (' . $daysBeforeDue . ' days before due)',
                [
                    'subscriptionID' => $subscriptionID,
                    'daysBeforeDue'  => $daysBeforeDue,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'dueDate'        => $dueDate,
                    'emailSent'      => $emailSent,
                ]
            );

            return [
                'success' => $emailSent,
                'message' => $emailSent
                    ? 'Payment reminder sent (' . $daysBeforeDue . ' days before due)'
                    : 'Failed to send payment reminder email',
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'reminder_exception',
                'payment',
                'error',
                'Exception sending payment reminder for subscription #' . $subscriptionID . ': ' . $e->getMessage(),
                ['subscriptionID' => $subscriptionID, 'daysBeforeDue' => $daysBeforeDue]
            );

            return ['success' => false, 'message' => 'Reminder failed: ' . $e->getMessage()];
        }
    }

    // ========================================================================
    // 📅 RENEWAL SCHEDULING
    // ========================================================================

    /**
     * 📅 Schedule Subscription Renewal
     *
     * Creates all billing schedule tasks for an upcoming subscription renewal
     * cycle. This includes:
     *   - Reminder emails at configured intervals (e.g., 7, 3, 1 days before)
     *   - The actual charge task on the billing date
     *
     * Existing pending tasks for this subscription are cancelled first to
     * avoid duplicates (e.g., when a billing date is recalculated after
     * a successful payment).
     *
     * @param int    $subscriptionID  The subscription to schedule for
     * @param string $nextBillingDate The next billing date (Y-m-d format)
     *
     * @return array {
     *     @type bool  $success Whether all tasks were scheduled
     *     @type array $taskIDs List of created task IDs
     * }
     *
     * @see self::createTask()           Creates individual schedule entries
     * @see self::cancelPendingTasks()   Clears old tasks to prevent duplicates
     * @see https://www.php.net/manual/en/function.strtotime.php
     */
    public static function scheduleSubscriptionRenewal(int $subscriptionID, string $nextBillingDate): array
    {
        try {
            $taskIDs = [];

            // 🧹 Cancel any existing pending tasks for this subscription to avoid
            // duplicate charges or reminders when rescheduling
            self::cancelPendingTasks('subscription', $subscriptionID);

            // 📅 Parse the billing date for date arithmetic
            $billingTimestamp = strtotime($nextBillingDate);
            if ($billingTimestamp === false) {
                return ['success' => false, 'taskIDs' => [], 'message' => 'Invalid billing date: ' . $nextBillingDate];
            }

            // 📧 Schedule reminder emails at configured day intervals before due date
            // Setting format: comma-separated list like '7,3,1'
            $reminderDaysSetting = self::getSetting('billing.reminder_days', '7,3,1');
            $reminderDays = array_map('intval', array_filter(explode(',', $reminderDaysSetting)));

            // 🔄 Sort descending so the earliest reminder (most days before) is scheduled first
            rsort($reminderDays);

            foreach ($reminderDays as $daysBefore) {
                // 📅 Calculate the reminder date
                $reminderDate = date('Y-m-d H:i:s', strtotime('-' . $daysBefore . ' days', $billingTimestamp));

                // ⏭️ Skip if the reminder date is already in the past
                if (strtotime($reminderDate) <= time()) {
                    continue;
                }

                // 📝 Create the reminder task
                $reminderTask = self::createTask(
                    'send_reminder',
                    'subscription',
                    $subscriptionID,
                    $reminderDate,
                    [
                        'daysBeforeDue'   => $daysBefore,
                        'nextBillingDate' => $nextBillingDate,
                    ]
                );

                if ($reminderTask['success']) {
                    $taskIDs[] = $reminderTask['taskID'];
                }
            }

            // 💳 Schedule the actual charge task on the billing date
            // We schedule it at the start of the day (00:00:00) in the billing date
            $chargeDate = date('Y-m-d 00:00:00', $billingTimestamp);

            $chargeTask = self::createTask(
                'charge_subscription',
                'subscription',
                $subscriptionID,
                $chargeDate,
                [
                    'billingDate' => $nextBillingDate,
                    'automated'   => true,
                ]
            );

            if ($chargeTask['success']) {
                $taskIDs[] = $chargeTask['taskID'];
            }

            // 📝 Log the scheduling
            ActivityLogger::log(
                null,
                'renewal_scheduled',
                'payment',
                'info',
                'Subscription #' . $subscriptionID . ' renewal scheduled for ' . $nextBillingDate . ' with ' . count($taskIDs) . ' tasks',
                [
                    'subscriptionID'  => $subscriptionID,
                    'nextBillingDate' => $nextBillingDate,
                    'taskIDs'         => $taskIDs,
                    'reminderDays'    => $reminderDays,
                ]
            );

            return [
                'success' => true,
                'taskIDs' => $taskIDs,
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'renewal_schedule_exception',
                'payment',
                'error',
                'Exception scheduling renewal for subscription #' . $subscriptionID . ': ' . $e->getMessage(),
                ['subscriptionID' => $subscriptionID, 'nextBillingDate' => $nextBillingDate]
            );

            return ['success' => false, 'taskIDs' => [], 'message' => 'Scheduling failed: ' . $e->getMessage()];
        }
    }

    // ========================================================================
    // 🚫 AUTO-SUSPENSION SCHEDULING
    // ========================================================================

    /**
     * 🚫 Schedule Auto-Suspension
     *
     * Creates a suspension task that will fire after the configured grace
     * period (billing.grace_period_days). Called when a subscription charge
     * has exhausted all retry attempts.
     *
     * @param int $subscriptionID The subscription to schedule suspension for
     *
     * @return array {
     *     @type bool   $success        Whether the task was created
     *     @type int    $taskID         The created task's ID
     *     @type string $suspensionDate When suspension will occur (Y-m-d H:i:s)
     * }
     *
     * @see self::createTask()           Creates the suspension schedule entry
     * @see self::processSuspension()    Executes the actual suspension
     * @see https://www.php.net/manual/en/function.strtotime.php
     */
    public static function scheduleAutoSuspension(int $subscriptionID): array
    {
        try {
            // 🔍 Fetch subscription to determine target type context
            $subscription = Database::fetchOne(
                "SELECT subscriptionID, userID, partnerID
                 FROM tblSubscriptions
                 WHERE subscriptionID = ?",
                [$subscriptionID],
                'i'
            );

            if (!$subscription) {
                return [
                    'success'        => false,
                    'taskID'         => 0,
                    'suspensionDate' => '',
                    'message'        => 'Subscription not found: #' . $subscriptionID,
                ];
            }

            // ⏰ Calculate suspension date based on grace period setting
            $gracePeriodDays = (int) self::getSetting('billing.grace_period_days', '7');
            $suspensionDate = date('Y-m-d H:i:s', strtotime('+' . $gracePeriodDays . ' days'));

            // 🎯 Determine the target type — if the subscription is linked to a partner,
            // suspend the partner (which preserves their tier); otherwise suspend the subscription
            $targetType = !empty($subscription['partnerID']) ? 'partner' : 'subscription';
            $targetID = !empty($subscription['partnerID']) ? (int) $subscription['partnerID'] : $subscriptionID;

            // 📝 Create the suspension task
            $task = self::createTask(
                'suspend_account',
                $targetType,
                $targetID,
                $suspensionDate,
                [
                    'subscriptionID'  => $subscriptionID,
                    'gracePeriodDays' => $gracePeriodDays,
                    'reason'          => 'Auto-suspension after failed payment attempts',
                ]
            );

            if ($task['success']) {
                // 📝 Log the scheduled suspension
                ActivityLogger::log(
                    $subscription['userID'] ? (int) $subscription['userID'] : null,
                    'suspension_scheduled',
                    'payment',
                    'warning',
                    'Auto-suspension scheduled for ' . $targetType . ' #' . $targetID . ' on ' . $suspensionDate . ' (grace period: ' . $gracePeriodDays . ' days)',
                    [
                        'subscriptionID'  => $subscriptionID,
                        'targetType'      => $targetType,
                        'targetID'        => $targetID,
                        'suspensionDate'  => $suspensionDate,
                        'gracePeriodDays' => $gracePeriodDays,
                        'taskID'          => $task['taskID'],
                    ]
                );

                return [
                    'success'        => true,
                    'taskID'         => $task['taskID'],
                    'suspensionDate' => $suspensionDate,
                ];
            }

            return [
                'success'        => false,
                'taskID'         => 0,
                'suspensionDate' => $suspensionDate,
                'message'        => 'Failed to create suspension task',
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'suspension_schedule_exception',
                'payment',
                'error',
                'Exception scheduling auto-suspension for subscription #' . $subscriptionID . ': ' . $e->getMessage(),
                ['subscriptionID' => $subscriptionID]
            );

            return [
                'success'        => false,
                'taskID'         => 0,
                'suspensionDate' => '',
                'message'        => 'Scheduling failed: ' . $e->getMessage(),
            ];
        }
    }

    // ========================================================================
    // 🔔 DUNNING RETRY LADDER (G-002 Stage S4)
    // ========================================================================

    /**
     * 🔔 Schedule the Next Dunning Retry Rung
     *
     * Enqueues a `dunning_retry` task for the given subscription/attempt
     * number, timed via the CONFIGURABLE `billing.dunning.retry_days` CSV
     * ladder (default `'1,3,5,7'`) — REUSES the existing generic task queue
     * (`createTask()`/`getTasksDue()`/`processDueTasks()`) rather than a
     * bespoke dunning scheduler.
     *
     * `billing.dunning.retry_days[N-1]` is the number of days to wait
     * BEFORE rung N fires, timed from the moment it is scheduled — i.e. rung
     * 1 fires `retry_days[0]` days after the subscription enters `past_due`
     * (called from `PaymentManager::applyWebhookTransition()`), rung 2 fires
     * `retry_days[1]` days after rung 1's FAILED attempt (called from
     * `self::processDunningRetry()`), and so on — a chained-interval model
     * (mirrors Stripe Smart Retries' own "days since last attempt" spacing)
     * rather than fixed offsets from a single anchor timestamp, so no new
     * `tblSubscriptions` column is needed to remember when the episode
     * started. If `$attemptNumber` exceeds the configured ladder length, the
     * LAST configured interval is reused (defensive default rather than an
     * error).
     *
     * Idempotent: any earlier PENDING `dunning_retry` task for this
     * subscription is cancelled first, so a re-entrant call (e.g. a retried
     * webhook re-triggering rung 1) can never stack two divergent rungs.
     *
     * @param int $subscriptionID The subscription entering/continuing dunning
     * @param int $attemptNumber  1-based rung number (1 = first retry)
     *
     * @return array {@see self::createTask()}'s return shape
     *
     * @see self::processDunningRetry()      Executes the rung when due
     * @see self::getSetting()               Reads billing.dunning.retry_days
     * @see .dev-team/specs/G-002.md §5/§6.4 (dunning ladder settings)
     */
    public static function scheduleDunningRetry(int $subscriptionID, int $attemptNumber = 1): array
    {
        try {
            $attemptNumber = max(1, $attemptNumber);

            // 📋 Parse the CSV ladder — defensive against a blank/malformed
            // setting (falls back to the documented default).
            $retryDaysSetting = self::getSetting('billing.dunning.retry_days', '1,3,5,7');
            $retryDays = array_values(array_filter(
                array_map('intval', explode(',', $retryDaysSetting)),
                static fn(int $d): bool => $d > 0
            ));
            if (empty($retryDays)) {
                $retryDays = [1, 3, 5, 7];
            }

            $rungIndex = min($attemptNumber - 1, count($retryDays) - 1);
            $daysDelay = $retryDays[$rungIndex];

            $scheduledFor = date('Y-m-d H:i:s', strtotime('+' . $daysDelay . ' days'));

            // 🧹 Idempotent re-schedule (§4.3): clear any earlier pending
            // rung for this subscription before creating the new one.
            self::cancelPendingTasks('subscription', $subscriptionID, 'dunning_retry');

            $task = self::createTask(
                'dunning_retry',
                'subscription',
                $subscriptionID,
                $scheduledFor,
                ['attemptNumber' => $attemptNumber]
            );

            if ($task['success']) {
                ActivityLogger::log(
                    null,
                    'dunning_retry_scheduled',
                    'payment',
                    'info',
                    'Dunning retry #' . $attemptNumber . ' scheduled for subscription #' . $subscriptionID . ' on ' . $scheduledFor,
                    [
                        'subscriptionID' => $subscriptionID,
                        'attemptNumber'  => $attemptNumber,
                        'scheduledFor'   => $scheduledFor,
                        'daysDelay'      => $daysDelay,
                    ]
                );
            }

            return $task;

        } catch (\Throwable $e) {
            ActivityLogger::log(
                null,
                'dunning_retry_schedule_exception',
                'payment',
                'error',
                'Exception scheduling dunning retry for subscription #' . $subscriptionID . ': ' . $e->getMessage(),
                ['subscriptionID' => $subscriptionID, 'attemptNumber' => $attemptNumber]
            );

            return ['success' => false, 'taskID' => 0, 'message' => 'Scheduling failed: ' . $e->getMessage()];
        }
    }

    // ========================================================================
    // ✅ PAYMENT SUCCESS — Auto-Resume
    // ========================================================================

    /**
     * ✅ Handle Payment Success
     *
     * Called by PaymentManager when a subscription payment completes
     * successfully. This method:
     *   1. Cancels any pending suspension tasks for this subscription
     *   2. Checks if the partner/user was suspended and auto-resumes
     *   3. Calculates and schedules the next billing cycle
     *   4. Sends a 'subscription_restored' email if the account was suspended
     *
     * @param int $subscriptionID The subscription that was paid
     * @param int $paymentID      The completed payment's ID
     *
     * @return array {
     *     @type bool   $success Whether the post-payment processing succeeded
     *     @type string $message Human-readable outcome description
     * }
     *
     * @see PaymentManager::completePayment() Calls this method
     * @see self::cancelPendingTasks()        Cancels suspension tasks
     * @see self::scheduleSubscriptionRenewal() Schedules next billing cycle
     */
    public static function handlePaymentSuccess(int $subscriptionID, int $paymentID): array
    {
        try {
            // 🔍 Fetch subscription details
            $subscription = Database::fetchOne(
                "SELECT s.*, t.tierName, t.tierSlug
                 FROM tblSubscriptions s
                 JOIN tblSubscriptionTiers t ON s.tierID = t.tierID
                 WHERE s.subscriptionID = ?",
                [$subscriptionID],
                'i'
            );

            if (!$subscription) {
                return ['success' => false, 'message' => 'Subscription not found: #' . $subscriptionID];
            }

            $userID = (int) $subscription['userID'];
            $partnerID = $subscription['partnerID'] ? (int) $subscription['partnerID'] : null;
            $wasSuspended = false;

            // 🧹 Cancel any pending suspension and charge tasks for this subscription
            // to prevent a suspension from firing after a successful payment
            $cancelledCount = self::cancelPendingTasks('subscription', $subscriptionID, 'suspend_account');
            $cancelledCount += self::cancelPendingTasks('subscription', $subscriptionID, 'charge_subscription');

            // 🏢 If the subscription belongs to a partner, also cancel partner-level tasks
            if ($partnerID) {
                $cancelledCount += self::cancelPendingTasks('partner', $partnerID, 'suspend_account');
            }

            // 🔄 Check if the partner was previously suspended and needs auto-resume
            if ($partnerID) {
                $partner = Database::fetchOne(
                    "SELECT partnerID, partnerName, accountTier, suspendedTier, suspendedAt, contactEmail
                     FROM tblPartners
                     WHERE partnerID = ?",
                    [$partnerID],
                    'i'
                );

                if ($partner && !empty($partner['suspendedAt'])) {
                    $wasSuspended = true;
                    $restoredTier = $partner['suspendedTier'] ?? $partner['accountTier'];

                    // ✅ Restore the partner's original tier and clear suspension fields
                    Database::query(
                        "UPDATE tblPartners
                         SET accountTier = ?,
                             suspendedTier = NULL,
                             suspendedAt = NULL,
                             paymentStatus = 'active'
                         WHERE partnerID = ?",
                        [$restoredTier, $partnerID],
                        'si'
                    );

                    // 📝 Log the restoration
                    ActivityLogger::log(
                        $userID,
                        'partner_restored',
                        'payment',
                        'info',
                        'Partner #' . $partnerID . ' (' . ($partner['partnerName'] ?? '') . ') restored to tier: ' . $restoredTier,
                        [
                            'partnerID'    => $partnerID,
                            'restoredTier' => $restoredTier,
                            'paymentID'    => $paymentID,
                        ]
                    );

                    // 🔗 Dispatch webhook event for service restoration
                    if (class_exists('WebhookManager')) {
                        WebhookManager::dispatch('subscription.restored', [
                            'targetType'   => 'partner',
                            'partnerID'    => $partnerID,
                            'partnerName'  => $partner['partnerName'] ?? '',
                            'restoredTier' => $restoredTier,
                            'paymentID'    => $paymentID,
                            'restoredAt'   => date('Y-m-d H:i:s'),
                        ]);
                    }
                }
            }

            // 📅 Calculate and schedule the next billing date (+1 billing cycle)
            $billingCycle = $subscription['billingCycle'] ?? 'monthly';
            $nextBillingDate = self::calculateNextBillingDate($billingCycle);

            // 💾 Update subscription with new billing date and ensure active status
            Database::query(
                "UPDATE tblSubscriptions
                 SET subscriptionStatus = 'active',
                     nextBillingDate = ?,
                     currentPeriodStart = CURDATE(),
                     currentPeriodEnd = ?
                 WHERE subscriptionID = ?",
                [$nextBillingDate, $nextBillingDate, $subscriptionID],
                'ssi'
            );

            // 📅 Schedule the next renewal cycle (reminders + charge)
            self::scheduleSubscriptionRenewal($subscriptionID, $nextBillingDate);

            // 📧 Send restoration email if the account was previously suspended
            if ($wasSuspended) {
                $contactEmail = $partner['contactEmail'] ?? self::getUserEmail($userID);
                if (!empty($contactEmail)) {
                    EmailService::sendTemplateEmail(
                        $contactEmail,
                        'subscription_restored',
                        [
                            '{{partnerName}}'  => $partner['partnerName'] ?? 'Your Account',
                            '{{restoredTier}}' => $restoredTier ?? 'your previous plan',
                            '{{paymentID}}'    => (string) $paymentID,
                            '{{manageURL}}'    => getSetting('app.url', 'https://signula.id') . '/account/billing',
                        ],
                        $userID,
                        2 // 📨 High priority
                    );
                }
            }

            // 📝 Log the overall success
            ActivityLogger::log(
                $userID,
                'payment_success_handled',
                'payment',
                'info',
                'Payment success processed for subscription #' . $subscriptionID . ($wasSuspended ? ' (account restored from suspension)' : ''),
                [
                    'subscriptionID'  => $subscriptionID,
                    'paymentID'       => $paymentID,
                    'wasSuspended'    => $wasSuspended,
                    'nextBillingDate' => $nextBillingDate,
                    'cancelledTasks'  => $cancelledCount,
                ]
            );

            return [
                'success' => true,
                'message' => $wasSuspended
                    ? 'Payment processed — account restored from suspension'
                    : 'Payment processed — next billing scheduled for ' . $nextBillingDate,
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'payment_success_exception',
                'payment',
                'error',
                'Exception handling payment success for subscription #' . $subscriptionID . ': ' . $e->getMessage(),
                ['subscriptionID' => $subscriptionID, 'paymentID' => $paymentID, 'trace' => $e->getTraceAsString()]
            );

            return ['success' => false, 'message' => 'Post-payment processing failed: ' . $e->getMessage()];
        }
    }

    // ========================================================================
    // 📝 GENERIC TASK MANAGEMENT
    // ========================================================================

    /**
     * ➕ Create a Billing Schedule Task
     *
     * Inserts a new task into tblBillingSchedule with the specified type,
     * target, scheduled time, and optional metadata. This is the generic
     * task creation method used by all scheduling functions.
     *
     * @param string $taskType     One of the TASK_TYPES constants
     * @param string $targetType   One of the TARGET_TYPES constants
     * @param int    $targetID     The ID of the target entity
     * @param string $scheduledFor When the task should execute (Y-m-d H:i:s)
     * @param array  $metadata     Optional JSON-serialisable metadata
     *
     * @return array {
     *     @type bool $success Whether the task was created
     *     @type int  $taskID  The newly created task's ID
     * }
     *
     * @see https://www.php.net/manual/en/function.json-encode.php
     * @see https://dev.mysql.com/doc/refman/8.0/en/json.html (MySQL JSON type)
     */
    public static function createTask(
        string $taskType,
        string $targetType,
        int $targetID,
        string $scheduledFor,
        array $metadata = []
    ): array {
        try {
            // ✅ Validate task type against allowed ENUM values
            if (!in_array($taskType, self::TASK_TYPES, true)) {
                return ['success' => false, 'taskID' => 0, 'message' => 'Invalid task type: ' . $taskType];
            }

            // ✅ Validate target type against allowed ENUM values
            if (!in_array($targetType, self::TARGET_TYPES, true)) {
                return ['success' => false, 'taskID' => 0, 'message' => 'Invalid target type: ' . $targetType];
            }

            // 🔧 Get max attempts from settings for charge tasks, default 3 for others
            $maxAttempts = ($taskType === 'charge_subscription')
                ? (int) self::getSetting('billing.max_charge_attempts', '3')
                : 3;

            // 💾 Insert the task into the billing schedule
            // @see https://dev.mysql.com/doc/refman/8.0/en/insert.html
            Database::query(
                "INSERT INTO tblBillingSchedule
                    (taskType, targetType, targetID, scheduledFor, status, attempts, maxAttempts, metadata, createdAt)
                 VALUES (?, ?, ?, ?, 'pending', 0, ?, ?, NOW())",
                [
                    $taskType,
                    $targetType,
                    $targetID,
                    $scheduledFor,
                    $maxAttempts,
                    !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
                ],
                'ssisis'
            );

            $taskID = Database::getLastInsertId();

            return [
                'success' => true,
                'taskID'  => $taskID,
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'task_creation_failed',
                'payment',
                'error',
                'Failed to create billing task (' . $taskType . ' for ' . $targetType . ' #' . $targetID . '): ' . $e->getMessage(),
                [
                    'taskType'     => $taskType,
                    'targetType'   => $targetType,
                    'targetID'     => $targetID,
                    'scheduledFor' => $scheduledFor,
                ]
            );

            return ['success' => false, 'taskID' => 0, 'message' => 'Task creation failed: ' . $e->getMessage()];
        }
    }

    /**
     * 🧹 Cancel Pending Tasks for a Target
     *
     * Sets the status of all matching pending tasks to 'cancelled'. Used
     * when a payment succeeds (cancel suspension tasks), when rescheduling
     * (cancel old reminders/charges), or when a subscription is manually
     * cancelled.
     *
     * @param string      $targetType The target entity type (e.g., 'subscription', 'partner')
     * @param int         $targetID   The target entity's ID
     * @param string|null $taskType   Optional: only cancel tasks of this specific type
     *                                (null = cancel all task types for this target)
     *
     * @return int Number of tasks that were cancelled
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/update.html
     */
    public static function cancelPendingTasks(string $targetType, int $targetID, ?string $taskType = null): int
    {
        try {
            // 🔨 Build the UPDATE query dynamically based on whether taskType filter is provided
            $sql = "UPDATE tblBillingSchedule
                    SET status = 'cancelled', updatedAt = NOW()
                    WHERE targetType = ?
                      AND targetID = ?
                      AND status IN ('pending', 'processing')";

            $params = [$targetType, $targetID];
            $types = 'si';

            // 🔍 Optionally filter by specific task type
            if ($taskType !== null) {
                $sql .= " AND taskType = ?";
                $params[] = $taskType;
                $types .= 's';
            }

            Database::query($sql, $params, $types);

            // 📊 Count how many rows were affected
            // Note: Database::query returns the result; we use a follow-up COUNT query
            // since MySQLi affected_rows is not directly exposed via our Database abstraction
            $countResult = Database::fetchOne(
                "SELECT COUNT(*) as cnt FROM tblBillingSchedule
                 WHERE targetType = ? AND targetID = ? AND status = 'cancelled'
                   AND updatedAt >= DATE_SUB(NOW(), INTERVAL 5 SECOND)"
                 . ($taskType !== null ? " AND taskType = ?" : ""),
                $taskType !== null ? [$targetType, $targetID, $taskType] : [$targetType, $targetID],
                $taskType !== null ? 'sis' : 'si'
            );

            return $countResult ? (int) $countResult['cnt'] : 0;

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'task_cancel_failed',
                'payment',
                'error',
                'Failed to cancel pending tasks for ' . $targetType . ' #' . $targetID . ': ' . $e->getMessage(),
                ['targetType' => $targetType, 'targetID' => $targetID, 'taskType' => $taskType]
            );

            return 0;
        }
    }

    // ========================================================================
    // 🔍 TASK RETRIEVAL
    // ========================================================================

    /**
     * 📋 Get Tasks Due for Processing
     *
     * Retrieves pending tasks from tblBillingSchedule where the scheduled
     * time is at or before NOW(). Tasks are ordered by scheduledFor ascending
     * so the oldest overdue tasks are processed first.
     *
     * Also includes tasks in 'pending' status that have a nextRetryAt that
     * has passed (i.e., tasks that were scheduled for retry).
     *
     * @param int $limit Maximum number of tasks to return
     *
     * @return array List of task records from tblBillingSchedule
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/select.html
     */
    public static function getTasksDue(int $limit = self::DEFAULT_BATCH_SIZE): array
    {
        // 🩹 B-025 schema↔code reconciliation: the table's PK column is
        // `scheduleID` (migration 012), but every consumer of this method
        // (processDueTasks(), processLazyBatch(), and the admin API) reads
        // $task['taskID']. Rather than rename the PK (destructive — forbidden
        // by the migration safety rules), alias it in the SELECT so the rest
        // of this class's existing `$task['taskID']` reads keep working
        // unchanged. @see https://dev.mysql.com/doc/refman/8.0/en/select.html
        return Database::fetchAll(
            "SELECT *, scheduleID AS taskID
             FROM tblBillingSchedule
             WHERE status = 'pending'
               AND (
                   scheduledFor <= NOW()
                   OR (nextRetryAt IS NOT NULL AND nextRetryAt <= NOW())
               )
             ORDER BY scheduledFor ASC
             LIMIT ?",
            [$limit],
            'i'
        );
    }

    // ========================================================================
    // 🔄 TASK STATUS MANAGEMENT
    // ========================================================================

    /**
     * ✏️ Mark a Task as Processed
     *
     * Updates a task's status, increments the attempt counter, and records
     * the last attempt timestamp. Optionally stores a result message for
     * debugging and audit purposes.
     *
     * @param int         $taskID The task to update
     * @param string      $status New status ('processing', 'completed', 'failed', 'cancelled')
     * @param string|null $result Optional result message or JSON data
     *
     * @return void
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/update.html
     */
    public static function markTaskProcessed(int $taskID, string $status, ?string $result = null): void
    {
        try {
            // 🩹 B-025: $taskID is the PHP-side name (matches the public method
            // signature and every caller), but the actual PK column is
            // `scheduleID` — target that in the WHERE clause. @see getTasksDue()
            Database::query(
                "UPDATE tblBillingSchedule
                 SET status = ?,
                     attempts = attempts + 1,
                     lastAttemptAt = NOW(),
                     result = ?,
                     updatedAt = NOW()
                 WHERE scheduleID = ?",
                [$status, $result, $taskID],
                'ssi'
            );
        } catch (\Exception $e) {
            // 🛑 Log but don't throw — task status update failure shouldn't crash processing
            ActivityLogger::log(
                null,
                'task_status_update_failed',
                'payment',
                'error',
                'Failed to mark task #' . $taskID . ' as ' . $status . ': ' . $e->getMessage(),
                ['taskID' => $taskID, 'status' => $status]
            );
        }
    }

    /**
     * 🔁 Retry a Failed Task
     *
     * Keeps the task in 'pending' status but sets a nextRetryAt timestamp
     * so it won't be picked up again until the retry interval has elapsed.
     * The attempt counter is incremented by markTaskProcessed (which should
     * be called before this method, or this method handles its own increment).
     *
     * @param int $taskID       The task to schedule for retry
     * @param int $retryMinutes Minutes to wait before the next retry attempt
     *
     * @return void
     *
     * @see https://www.php.net/manual/en/function.date.php
     * @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html
     */
    public static function retryTask(int $taskID, int $retryMinutes = self::DEFAULT_RETRY_MINUTES): void
    {
        try {
            $nextRetryAt = date('Y-m-d H:i:s', strtotime('+' . $retryMinutes . ' minutes'));

            // 🩹 B-025: see markTaskProcessed() — WHERE targets the real PK (`scheduleID`).
            Database::query(
                "UPDATE tblBillingSchedule
                 SET status = 'pending',
                     nextRetryAt = ?,
                     attempts = attempts + 1,
                     lastAttemptAt = NOW(),
                     updatedAt = NOW()
                 WHERE scheduleID = ?",
                [$nextRetryAt, $taskID],
                'si'
            );

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'task_retry_failed',
                'payment',
                'error',
                'Failed to schedule retry for task #' . $taskID . ': ' . $e->getMessage(),
                ['taskID' => $taskID, 'retryMinutes' => $retryMinutes]
            );
        }
    }

    // ========================================================================
    // 📊 SCHEDULER HEALTH MONITORING
    // ========================================================================

    /**
     * 📊 Get Scheduler Health Status
     *
     * Provides a snapshot of the billing scheduler's operational health.
     * Used by the admin dashboard to monitor whether the external cron
     * service is running, whether tasks are piling up, and whether there
     * are systemic failures.
     *
     * @return array {
     *     @type string $lastRunTime     When the cron last ran (from tblSettings)
     *     @type int    $pendingCount    Total pending tasks
     *     @type int    $failedCount     Total permanently failed tasks
     *     @type int    $overdueCount    Pending tasks past their scheduledFor time
     *     @type int    $processingCount Tasks currently being processed
     *     @type int    $completedToday  Tasks completed in the last 24 hours
     *     @type bool   $cronHealthy     Whether the cron ran within the last hour
     *     @type int    $secondsSinceRun Seconds elapsed since last cron run
     * }
     *
     * @see https://www.php.net/manual/en/function.strtotime.php
     */
    public static function getSchedulerHealth(): array
    {
        try {
            // ⏰ Get the last cron run timestamp
            $lastRunTime = self::getSetting('billing.last_cron_run', '');
            $secondsSinceRun = 0;
            $cronHealthy = false;

            if (!empty($lastRunTime)) {
                $lastRunTimestamp = strtotime($lastRunTime);
                $secondsSinceRun = time() - $lastRunTimestamp;
                // ✅ Cron is considered healthy if it ran within the last hour
                $cronHealthy = ($secondsSinceRun <= self::LAZY_THRESHOLD_SECONDS);
            }

            // 📊 Gather aggregate counts from tblBillingSchedule
            $stats = Database::fetchOne(
                "SELECT
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pendingCount,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failedCount,
                    SUM(CASE WHEN status = 'pending' AND scheduledFor <= NOW() THEN 1 ELSE 0 END) AS overdueCount,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processingCount,
                    SUM(CASE WHEN status = 'completed' AND updatedAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS completedToday
                 FROM tblBillingSchedule",
                [],
                ''
            );

            return [
                'lastRunTime'     => $lastRunTime ?: 'Never',
                'pendingCount'    => (int) ($stats['pendingCount'] ?? 0),
                'failedCount'     => (int) ($stats['failedCount'] ?? 0),
                'overdueCount'    => (int) ($stats['overdueCount'] ?? 0),
                'processingCount' => (int) ($stats['processingCount'] ?? 0),
                'completedToday'  => (int) ($stats['completedToday'] ?? 0),
                'cronHealthy'     => $cronHealthy,
                'secondsSinceRun' => $secondsSinceRun,
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'scheduler_health_error',
                'payment',
                'error',
                'Failed to retrieve scheduler health: ' . $e->getMessage(),
                []
            );

            return [
                'lastRunTime'     => 'Error',
                'pendingCount'    => -1,
                'failedCount'     => -1,
                'overdueCount'    => -1,
                'processingCount' => -1,
                'completedToday'  => -1,
                'cronHealthy'     => false,
                'secondsSinceRun' => -1,
            ];
        }
    }

    // ========================================================================
    // 🛡️ LAZY BATCH PROCESSING — Safety Net
    // ========================================================================

    /**
     * 🛡️ Process Lazy Batch (Safety Net)
     *
     * Designed to be called on authenticated page loads as a fallback when
     * the external cron service has not run within the threshold period
     * (default: 1 hour). Processes a small batch of due tasks to prevent
     * billing operations from completely stalling if cron-job.org is down.
     *
     * This method is intentionally lightweight:
     *   - Checks the last cron run time first (fast path if cron is healthy)
     *   - Processes a very small batch (default: 5) to avoid page load delays
     *   - Does NOT update billing.last_cron_run to avoid masking cron failure
     *
     * @param int $batchSize Maximum tasks to process (keep small for page load)
     *
     * @return array {
     *     @type int  $processed Number of tasks processed (if cron was stale)
     *     @type bool $skipped   True if cron ran recently and no processing was needed
     * }
     *
     * @see self::processDueTasks()  Full cron processing (called by external cron)
     * @see self::getSchedulerHealth() Health check data
     * @see https://www.php.net/manual/en/function.strtotime.php
     */
    public static function processLazyBatch(int $batchSize = self::LAZY_BATCH_SIZE): array
    {
        try {
            // ⏰ Check when the external cron last ran
            $lastCronRun = self::getSetting('billing.last_cron_run', '');

            if (!empty($lastCronRun)) {
                $lastRunTimestamp = strtotime($lastCronRun);
                $secondsSinceRun = time() - $lastRunTimestamp;

                // ✅ If the cron ran within the threshold, skip lazy processing
                if ($secondsSinceRun <= self::LAZY_THRESHOLD_SECONDS) {
                    return ['skipped' => true, 'processed' => 0];
                }
            }

            // ⚠️ Cron hasn't run recently — process a small safety-net batch
            // We do NOT call processDueTasks() directly because that updates
            // billing.last_cron_run, which would mask the cron failure from
            // the health monitor. Instead, we process tasks manually.
            $tasks = self::getTasksDue($batchSize);
            $processed = 0;

            foreach ($tasks as $task) {
                $taskID = (int) $task['taskID'];
                $taskType = $task['taskType'];
                $targetType = $task['targetType'];
                $targetID = (int) $task['targetID'];

                // 🔒 Mark as processing
                self::markTaskProcessed($taskID, 'processing');

                try {
                    // 🔀 Route to handler
                    $result = match ($taskType) {
                        'charge_subscription' => self::processSubscriptionCharge($targetID),
                        'send_reminder'       => self::processSendReminder($task),
                        'suspend_account'     => self::processSuspension($targetType, $targetID),
                        'expire_trial'        => self::processTrialExpiry($targetID),
                        'process_remittance'  => self::processRemittance($targetID),
                        'calculate_usage'     => self::processUsageCalculation($targetID),
                        'charge_usage'        => self::processUsageCharge($targetID),
                        'archive_usage'       => self::processUsageArchival(),
                        'dunning_retry'       => self::processDunningRetry($task),
                        'dunning_final_cancel' => self::processDunningFinalCancel($task),
                        default               => ['success' => false, 'message' => 'Unknown task type: ' . $taskType],
                    };

                    if ($result['success']) {
                        self::markTaskProcessed($taskID, 'completed', json_encode($result));
                        $processed++;
                    } else {
                        $attempts = (int) $task['attempts'];
                        $maxAttempts = (int) $task['maxAttempts'];

                        if ($attempts < $maxAttempts) {
                            $retryMinutes = self::DEFAULT_RETRY_MINUTES * pow(2, $attempts);
                            self::retryTask($taskID, $retryMinutes);
                        } else {
                            self::markTaskProcessed($taskID, 'failed', json_encode($result));
                        }
                    }

                } catch (\Exception $e) {
                    self::markTaskProcessed($taskID, 'failed', $e->getMessage());
                }
            }

            // 📝 Log that lazy processing was triggered (indicates cron may be down)
            if ($processed > 0 || count($tasks) > 0) {
                ActivityLogger::log(
                    null,
                    'lazy_batch_processed',
                    'payment',
                    'warning',
                    'Lazy billing batch processed ' . $processed . ' of ' . count($tasks) . ' tasks (external cron may be down)',
                    [
                        'processed'     => $processed,
                        'total'         => count($tasks),
                        'batchSize'     => $batchSize,
                        'lastCronRun'   => $lastCronRun,
                    ]
                );
            }

            return ['skipped' => false, 'processed' => $processed];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'lazy_batch_exception',
                'payment',
                'error',
                'Lazy batch processing failed: ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            return ['skipped' => false, 'processed' => 0];
        }
    }

    // ========================================================================
    // 🔧 INTERNAL HELPER METHODS
    // ========================================================================

    /**
     * 🔁 Handle Charge Failure
     *
     * Internal helper called when a subscription charge fails. Checks
     * whether the maximum number of charge attempts has been reached.
     * If so, schedules an auto-suspension; otherwise, logs the failure
     * for retry by the task system.
     *
     * @param int    $subscriptionID The subscription that failed to charge
     * @param int    $userID         The subscription owner's user ID
     * @param string $reason         Human-readable failure reason
     *
     * @return void
     */
    private static function handleChargeFailure(int $subscriptionID, int $userID, string $reason): void
    {
        // 📊 Count how many times we've already tried to charge this subscription
        $failedAttempts = Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM tblBillingSchedule
             WHERE taskType = 'charge_subscription'
               AND targetType = 'subscription'
               AND targetID = ?
               AND status = 'failed'",
            [$subscriptionID],
            'i'
        );

        $attemptCount = $failedAttempts ? (int) $failedAttempts['cnt'] : 0;
        $maxAttempts = (int) self::getSetting('billing.max_charge_attempts', '3');

        // 📝 Log the charge failure
        ActivityLogger::log(
            $userID,
            'charge_failed',
            'payment',
            'warning',
            'Subscription #' . $subscriptionID . ' charge failed (attempt ' . ($attemptCount + 1) . '/' . $maxAttempts . '): ' . $reason,
            [
                'subscriptionID' => $subscriptionID,
                'attemptCount'   => $attemptCount + 1,
                'maxAttempts'    => $maxAttempts,
                'reason'         => $reason,
            ]
        );

        // 🚫 If max attempts reached, schedule auto-suspension
        if (($attemptCount + 1) >= $maxAttempts) {
            self::scheduleAutoSuspension($subscriptionID);

            ActivityLogger::log(
                $userID,
                'max_charge_attempts_reached',
                'payment',
                'warning',
                'Max charge attempts reached for subscription #' . $subscriptionID . ' — auto-suspension scheduled',
                ['subscriptionID' => $subscriptionID, 'maxAttempts' => $maxAttempts]
            );
        }
    }

    /**
     * 📧 Process Send Reminder Task
     *
     * Internal handler for 'send_reminder' tasks. Extracts the reminder
     * metadata and delegates to sendPaymentReminder().
     *
     * @param array $task The full task record from tblBillingSchedule
     *
     * @return array {
     *     @type bool   $success Whether the reminder was sent
     *     @type string $message Human-readable outcome
     * }
     */
    private static function processSendReminder(array $task): array
    {
        $subscriptionID = (int) $task['targetID'];

        // 📦 Extract daysBeforeDue from the task's metadata JSON
        $metadata = [];
        if (!empty($task['metadata'])) {
            $metadata = json_decode($task['metadata'], true) ?: [];
        }

        $daysBeforeDue = (int) ($metadata['daysBeforeDue'] ?? 7);

        return self::sendPaymentReminder($subscriptionID, $daysBeforeDue);
    }

    /**
     * ⏰ Process Trial Expiry Task
     *
     * Internal handler for 'expire_trial' tasks. Transitions a trial
     * subscription to 'pending' status requiring payment, or to 'active'
     * if it's a free tier. Sends a 'trial_expired' notification email.
     *
     * @param int $subscriptionID The trial subscription to expire
     *
     * @return array {
     *     @type bool   $success Whether the trial was processed
     *     @type string $message Human-readable outcome
     * }
     *
     * @see https://www.php.net/manual/en/function.date.php
     */
    private static function processTrialExpiry(int $subscriptionID): array
    {
        try {
            $subscription = Database::fetchOne(
                "SELECT s.*, t.tierName, t.monthlyPrice
                 FROM tblSubscriptions s
                 JOIN tblSubscriptionTiers t ON s.tierID = t.tierID
                 WHERE s.subscriptionID = ?
                   AND s.subscriptionStatus = 'trial'",
                [$subscriptionID],
                'i'
            );

            if (!$subscription) {
                return ['success' => false, 'message' => 'Trial subscription not found: #' . $subscriptionID];
            }

            $userID = (int) $subscription['userID'];
            $amount = (float) ($subscription['amount'] ?? $subscription['monthlyPrice'] ?? 0);

            // 🔄 Transition: if the plan costs money, set to 'pending' (needs payment);
            // if it's free, set directly to 'active'
            $newStatus = ($amount > 0) ? 'pending' : 'active';

            Database::query(
                "UPDATE tblSubscriptions
                 SET subscriptionStatus = ?,
                     trialEndsAt = NOW()
                 WHERE subscriptionID = ?",
                [$newStatus, $subscriptionID],
                'si'
            );

            // 📧 Notify the user their trial has expired
            $userEmail = self::getUserEmail($userID);
            if (!empty($userEmail)) {
                EmailService::sendTemplateEmail(
                    $userEmail,
                    'trial_expired',
                    [
                        '{{tierName}}'   => $subscription['tierName'] ?? 'Your Plan',
                        '{{upgradeURL}}' => getSetting('app.url', 'https://signula.id') . '/account/billing',
                    ],
                    $userID,
                    3 // 📨 Medium-high priority
                );
            }

            // 📝 Log the trial expiry
            ActivityLogger::log(
                $userID,
                'trial_expired',
                'payment',
                'info',
                'Trial expired for subscription #' . $subscriptionID . ' — new status: ' . $newStatus,
                ['subscriptionID' => $subscriptionID, 'newStatus' => $newStatus]
            );

            // 💳 If payment is required, schedule the first charge
            if ($newStatus === 'pending') {
                $nextBillingDate = date('Y-m-d');
                self::scheduleSubscriptionRenewal($subscriptionID, $nextBillingDate);
            }

            return [
                'success' => true,
                'message' => 'Trial expired — subscription set to ' . $newStatus,
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Trial expiry processing failed: ' . $e->getMessage()];
        }
    }

    // ========================================================================
    // 🔔 DUNNING RETRY LADDER — TASK HANDLERS (G-002 Stage S4)
    // ========================================================================

    /**
     * 🔔 Process a `dunning_retry` Task
     *
     * Executed when a scheduled dunning rung comes due (via
     * `self::scheduleDunningRetry()`). Flow (G-002 spec §5 / build order):
     *   1. Idempotent no-op if the subscription already left `past_due`
     *      (resolved by a provider webhook, a prior tick, cancellation, …)
     *      — covers "already resolved" AND a stale/duplicate task.
     *   2. Idempotent no-op if THIS exact (subscriptionID, attemptNumber)
     *      rung already has a `tblBillingAttempts` row — covers a genuine
     *      double-tick (e.g. two due tasks for the same rung processed in
     *      one batch).
     *   3. `BillingMode::assertTestMode($provider)` — BEFORE any collection
     *      attempt (§9.1). A live-mode-guarded refusal is fail-closed: no
     *      email, no next rung, no state change (BillingMode itself already
     *      wrote the audited 'blocked' row).
     *   4. Attempt re-collection (`self::attemptDunningCollection()` — see
     *      its doc-comment: NEVER a real charge; mocked in tests via
     *      `$task['metadata']['mockCollectionResult']`).
     *   5. SUCCESS → reuse `PaymentManager::recordSubscriptionRenewal()`
     *      (the SAME path a real renewal webhook takes) to record the
     *      payment/invoice, advance the period, and transition
     *      `past_due -> active` — clearing dunning by cancelling any
     *      further pending rungs / the final-cancel task.
     *   6. FAILURE → record the attempt, send the dunning email (reuses the
     *      existing `payment_failed` template), then either schedule the
     *      next rung or — if `billing.dunning.max_retries` is reached —
     *      exhaust the ladder into `grace` (`self::exhaustDunningLadder()`).
     *
     * @param array $task The full task row from `tblBillingSchedule`
     *                     (needs `targetID` and `metadata`)
     *
     * @return array{success: bool, message: string}
     *
     * @see self::scheduleDunningRetry()      Enqueues rung N+1 on failure
     * @see self::exhaustDunningLadder()      Ladder-exhausted -> grace + final-cancel task
     * @see PaymentManager::recordSubscriptionRenewal() Reused renewal path on success
     * @see .dev-team/specs/G-002.md §4.2/§5/§9.1
     */
    private static function processDunningRetry(array $task): array
    {
        try {
            $subscriptionID = (int) $task['targetID'];
            $metadata = !empty($task['metadata']) ? (json_decode((string) $task['metadata'], true) ?: []) : [];
            $attemptNumber = max(1, (int) ($metadata['attemptNumber'] ?? 1));

            $subscription = Database::fetchOne(
                "SELECT s.*, t.tierName, u.displayName, u.email AS userEmail
                 FROM tblSubscriptions s
                 JOIN tblSubscriptionTiers t ON s.tierID = t.tierID
                 JOIN tblUsers u ON s.userID = u.userID
                 WHERE s.subscriptionID = ?",
                [$subscriptionID],
                'i'
            );

            // 🔁 Idempotent no-op (§4.3): already resolved — a provider
            // webhook, a prior tick, or a manual cancel already moved the
            // subscription out of 'past_due'. Nothing left for this rung to do.
            if (!$subscription || $subscription['subscriptionStatus'] !== 'past_due') {
                return [
                    'success' => true,
                    'message' => 'Subscription #' . $subscriptionID . ' is no longer past_due — dunning retry #' . $attemptNumber . ' skipped (already resolved)',
                ];
            }

            $userID = (int) $subscription['userID'];
            $provider = strtolower((string) ($subscription['paymentMethod'] ?? ''));

            // 🔑 Stable per-rung idempotency key (§4.3) — sha256 to fit
            // tblBillingAttempts.idempotencyKey VARCHAR(64).
            $idempotencyKey = hash('sha256', 'dunning_retry:' . $subscriptionID . ':' . $attemptNumber);

            // ♻️ Idempotent double-tick guard: ANY prior row for this exact
            // rung (success OR failure) means the side effects (email/next
            // rung/renewal) already happened — never redo them.
            $prior = Database::fetchOne(
                "SELECT attemptID, status FROM tblBillingAttempts WHERE idempotencyKey = ? AND action = 'dunning_retry' LIMIT 1",
                [$idempotencyKey],
                's'
            );
            if ($prior) {
                return [
                    'success' => true,
                    'message' => 'Dunning retry #' . $attemptNumber . ' for subscription #' . $subscriptionID . ' already processed (idempotent replay, prior status: ' . $prior['status'] . ')',
                ];
            }

            // 🛡️ TEST-MODE guard (§9.1) — BEFORE any collection attempt.
            // Only meaningful for a real provider; free/manual/apple_pay/
            // google_pay/crypto methods have no provider "mode" to guard.
            if (in_array($provider, ['paypal', 'stripe', 'coinbase'], true)) {
                $billingModePath = __DIR__ . DIRECTORY_SEPARATOR . 'BillingMode.php';
                $billingModeExceptionPath = __DIR__ . DIRECTORY_SEPARATOR . 'BillingModeException.php';
                if (file_exists($billingModeExceptionPath)) {
                    require_once $billingModeExceptionPath;
                }
                if (file_exists($billingModePath)) {
                    require_once $billingModePath;
                }

                if (class_exists('BillingMode')) {
                    try {
                        BillingMode::assertTestMode($provider);
                    } catch (\Throwable $guardException) {
                        // 🚫 Fail-closed: refused BEFORE any collection
                        // attempt. BillingMode::assertTestMode() already
                        // wrote the audited 'blocked' tblBillingAttempts
                        // row itself — no email, no next rung, no state
                        // change. The generic scheduler retry/backoff
                        // (processDueTasks()) is left to retry this same
                        // task later, matching every other guard-blocked
                        // entry point's behaviour in this codebase.
                        ActivityLogger::log(
                            $userID,
                            'dunning_retry_blocked',
                            'payment',
                            'warning',
                            'Dunning retry #' . $attemptNumber . ' refused by TEST-MODE guard for subscription #' . $subscriptionID . ': ' . $guardException->getMessage(),
                            ['subscriptionID' => $subscriptionID, 'attemptNumber' => $attemptNumber, 'provider' => $provider]
                        );

                        return ['success' => false, 'message' => 'Refused by TEST-MODE guard: ' . $guardException->getMessage()];
                    }
                }
            }

            // 💳 Attempt re-collection — MOCKED in tests, never a real charge.
            $collection = self::attemptDunningCollection($subscription, $metadata);

            if ($collection['success'] ?? false) {
                // ✅ SUCCESS — reuse the SAME renewal path a real provider
                // webhook takes (records payment + invoice, advances the
                // period, transitions past_due -> active).
                //
                // 🐛 A SEPARATE sha256 digest, NOT `$idempotencyKey . ':renewal'`
                // — tblBillingAttempts.idempotencyKey is VARCHAR(64) and a
                // sha256 hex digest IS already exactly 64 characters, so
                // appending any suffix silently overflowed the column
                // (caught only by recordWebhookAuditRow()'s own non-fatal
                // try/catch — found while running this suite for the first
                // time; fixed here rather than left as dead capacity).
                $renewalIdempotencyKey = hash('sha256', 'dunning_retry_renewal:' . $subscriptionID . ':' . $attemptNumber);

                $renewal = PaymentManager::recordSubscriptionRenewal(
                    $subscriptionID,
                    $provider !== '' ? $provider : 'manual',
                    (float) $subscription['amount'],
                    (string) ($subscription['currency'] ?? 'GBP'),
                    $collection['transactionID'] ?? ('dunning-retry-' . $subscriptionID . '-' . $attemptNumber),
                    $renewalIdempotencyKey,
                    'Dunning retry #' . $attemptNumber . ' collection succeeded'
                );

                self::recordDunningAttempt(
                    $subscriptionID,
                    $userID,
                    $provider,
                    $idempotencyKey,
                    'succeeded',
                    $attemptNumber,
                    'Dunning retry #' . $attemptNumber . ' succeeded — ' . ($renewal['message'] ?? 'renewed')
                );

                // 🧹 Clear the rest of the ladder — no further rungs or a
                // final-cancel should fire now that the subscription is active.
                self::cancelPendingTasks('subscription', $subscriptionID, 'dunning_retry');
                self::cancelPendingTasks('subscription', $subscriptionID, 'dunning_final_cancel');

                ActivityLogger::log(
                    $userID,
                    'dunning_retry_succeeded',
                    'payment',
                    'info',
                    'Dunning retry #' . $attemptNumber . ' succeeded for subscription #' . $subscriptionID . ' — subscription active',
                    ['subscriptionID' => $subscriptionID, 'attemptNumber' => $attemptNumber]
                );

                return ['success' => true, 'message' => 'Dunning retry #' . $attemptNumber . ' succeeded — subscription active'];
            }

            // ❌ FAILURE — record, email, then either schedule the next rung
            // or exhaust the ladder into 'grace'.
            $failureReason = (string) ($collection['message'] ?? 'Payment could not be processed');

            self::recordDunningAttempt(
                $subscriptionID,
                $userID,
                $provider,
                $idempotencyKey,
                'failed',
                $attemptNumber,
                'Dunning retry #' . $attemptNumber . ' failed: ' . $failureReason
            );

            self::sendDunningRetryEmail($subscription, $attemptNumber, $failureReason);

            $maxRetries = (int) self::getSetting('billing.dunning.max_retries', '4');

            if ($attemptNumber >= $maxRetries) {
                self::exhaustDunningLadder($subscription);

                return ['success' => true, 'message' => 'Dunning retry #' . $attemptNumber . ' failed — retries exhausted, subscription moved to grace'];
            }

            self::scheduleDunningRetry($subscriptionID, $attemptNumber + 1);

            return ['success' => true, 'message' => 'Dunning retry #' . $attemptNumber . ' failed — next rung scheduled'];

        } catch (\Throwable $e) {
            ActivityLogger::log(
                null,
                'dunning_retry_exception',
                'payment',
                'error',
                'Exception during dunning retry processing: ' . $e->getMessage(),
                ['task' => $task, 'trace' => $e->getTraceAsString()]
            );

            return ['success' => false, 'message' => 'Dunning retry processing exception: ' . $e->getMessage()];
        }
    }

    /**
     * 💳 Attempt a Dunning Re-Collection (mocked in tests — NEVER a real charge)
     *
     * G-002 spec §14 explicitly forbids a self-driven "pull a stored card on
     * a timer" engine — recurring billing here is PROVIDER-managed, and
     * `chargeStoredMethod()` does not exist on any provider class in this
     * codebase (grep-verified — see `BillingMode.php`'s own class doc-comment
     * for the same finding). This method therefore has NO real collection
     * path of its own: for a provider-managed subscription, resolution
     * normally arrives via the PROVIDER's own retry + webhook
     * (`PAYMENT.SALE.COMPLETED` / `invoice.paid` ->
     * `PaymentManager::applyWebhookTransition()`/`recordSubscriptionRenewal()`).
     * Absent a webhook, this tick's job is the dunning BOOKKEEPING (email +
     * next rung + eventual grace/cancel) while we wait — so the default
     * result is always an honest, non-fabricated failure.
     *
     * Tests supply a mocked outcome via `$metadata['mockCollectionResult']`
     * (`'success'` or anything else = failure) — the ONLY caller that
     * creates a `dunning_retry` task (`self::scheduleDunningRetry()`) never
     * sets this key, so production tasks always take the honest-failure path.
     *
     * @param array $subscription The joined subscription/tier/user row
     * @param array $metadata     The task's decoded metadata (may carry the test mock key)
     *
     * @return array{success: bool, message?: string, transactionID?: string}
     */
    private static function attemptDunningCollection(array $subscription, array $metadata): array
    {
        // 🧪 TEST-ONLY mock hook — see doc-comment above.
        if (array_key_exists('mockCollectionResult', $metadata)) {
            if ($metadata['mockCollectionResult'] === 'success') {
                return ['success' => true, 'transactionID' => 'mock-' . bin2hex(random_bytes(4))];
            }

            return ['success' => false, 'message' => 'Mocked collection failure (test)'];
        }

        return [
            'success' => false,
            'message' => 'No local collection path for provider-managed subscriptions — awaiting provider webhook',
        ];
    }

    /**
     * 🚨 Exhaust the Dunning Ladder — past_due -> grace + schedule final-cancel
     *
     * Called when `billing.dunning.max_retries` rungs have all failed.
     * Transitions the subscription to `grace` (idempotent — a no-op if it is
     * already there), sends the "final warning" email, and schedules the
     * `dunning_final_cancel` task at `now + billing.grace_period_days`.
     *
     * @param array $subscription The joined subscription/tier/user row
     *
     * @return void
     *
     * @see self::processDunningFinalCancel() Executes when the grace window lapses
     */
    private static function exhaustDunningLadder(array $subscription): void
    {
        $subscriptionID = (int) $subscription['subscriptionID'];
        $userID = (int) $subscription['userID'];

        $stateMachinePath = __DIR__ . DIRECTORY_SEPARATOR . 'SubscriptionStateMachine.php';
        $transitionExceptionPath = __DIR__ . DIRECTORY_SEPARATOR . 'InvalidSubscriptionTransitionException.php';
        if (file_exists($transitionExceptionPath)) {
            require_once $transitionExceptionPath;
        }
        if (file_exists($stateMachinePath)) {
            require_once $stateMachinePath;
        }

        if (class_exists('SubscriptionStateMachine')) {
            try {
                SubscriptionStateMachine::transition($subscriptionID, 'grace', 'dunning_exhausted');
            } catch (\Throwable $e) {
                ActivityLogger::log(
                    $userID,
                    'dunning_exhausted_transition_failed',
                    'payment',
                    'error',
                    'Failed to transition subscription #' . $subscriptionID . ' to grace after dunning exhaustion: ' . $e->getMessage(),
                    ['subscriptionID' => $subscriptionID]
                );
            }
        }

        // 📧 Final-warning email — reuses the standard template mechanism;
        // seeded by migration 039 (`dunning_final_notice`).
        $userEmail = (string) ($subscription['userEmail'] ?? self::getUserEmail($userID));
        $gracePeriodDays = (int) self::getSetting('billing.grace_period_days', '7');

        if (!empty($userEmail)) {
            EmailService::sendTemplateEmail(
                $userEmail,
                'dunning_final_notice',
                [
                    '{{displayName}}' => $subscription['displayName'] ?? 'Valued Customer',
                    '{{tierName}}'    => $subscription['tierName'] ?? 'Your Plan',
                    '{{currency}}'    => $subscription['currency'] ?? 'GBP',
                    '{{amount}}'      => number_format((float) $subscription['amount'], 2),
                    '{{graceDays}}'   => (string) $gracePeriodDays,
                    '{{updateURL}}'   => getSetting('app.url', 'https://signula.id') . '/account/billing',
                ],
                $userID,
                1 // 📨 Highest priority
            );
        }

        ActivityLogger::log(
            $userID,
            'dunning_exhausted',
            'payment',
            'warning',
            'Dunning retries exhausted for subscription #' . $subscriptionID . ' — moved to grace (grace period: ' . $gracePeriodDays . ' days)',
            ['subscriptionID' => $subscriptionID, 'gracePeriodDays' => $gracePeriodDays]
        );

        if (class_exists('WebhookManager')) {
            WebhookManager::dispatch('subscription.grace_entered', [
                'subscriptionID' => $subscriptionID,
                'userID'         => $userID,
                'gracePeriodDays' => $gracePeriodDays,
            ]);
        }

        // ⏰ Schedule the final-cancel task at now + grace_period_days.
        // Idempotent re-schedule: clear any earlier pending final-cancel task first.
        self::cancelPendingTasks('subscription', $subscriptionID, 'dunning_final_cancel');

        $graceEndsAt = date('Y-m-d H:i:s', strtotime('+' . $gracePeriodDays . ' days'));
        self::createTask('dunning_final_cancel', 'subscription', $subscriptionID, $graceEndsAt, [
            'reason' => 'dunning_exhausted',
        ]);
    }

    /**
     * 🔔 Process a `dunning_final_cancel` Task
     *
     * Executed when a subscription's grace window (`billing.grace_period_days`
     * after `self::exhaustDunningLadder()`) lapses without a successful
     * payment. Idempotent no-op if the subscription already left `grace`
     * (reactivated, or already cancelled). Otherwise transitions
     * `grace -> cancelled` and sends the final "subscription cancelled" email.
     *
     * @param array $task The full task row from `tblBillingSchedule`
     *
     * @return array{success: bool, message: string}
     */
    private static function processDunningFinalCancel(array $task): array
    {
        try {
            $subscriptionID = (int) $task['targetID'];

            $subscription = Database::fetchOne(
                "SELECT s.*, t.tierName, u.displayName, u.email AS userEmail
                 FROM tblSubscriptions s
                 JOIN tblSubscriptionTiers t ON s.tierID = t.tierID
                 JOIN tblUsers u ON s.userID = u.userID
                 WHERE s.subscriptionID = ?",
                [$subscriptionID],
                'i'
            );

            // 🔁 Idempotent no-op: already resolved — reactivated out of
            // grace, or already terminal (cancelled/expired).
            if (!$subscription || $subscription['subscriptionStatus'] !== 'grace') {
                return [
                    'success' => true,
                    'message' => 'Subscription #' . $subscriptionID . ' is no longer in grace — final-cancel skipped (already resolved)',
                ];
            }

            $userID = (int) $subscription['userID'];

            $stateMachinePath = __DIR__ . DIRECTORY_SEPARATOR . 'SubscriptionStateMachine.php';
            $transitionExceptionPath = __DIR__ . DIRECTORY_SEPARATOR . 'InvalidSubscriptionTransitionException.php';
            if (file_exists($transitionExceptionPath)) {
                require_once $transitionExceptionPath;
            }
            if (file_exists($stateMachinePath)) {
                require_once $stateMachinePath;
            }

            if (class_exists('SubscriptionStateMachine')) {
                SubscriptionStateMachine::transition($subscriptionID, 'cancelled', 'grace_expired');
            }

            $userEmail = (string) ($subscription['userEmail'] ?? self::getUserEmail($userID));
            if (!empty($userEmail)) {
                EmailService::sendTemplateEmail(
                    $userEmail,
                    'subscription_cancelled',
                    [
                        '{{displayName}}'    => $subscription['displayName'] ?? 'Valued Customer',
                        '{{tierName}}'       => $subscription['tierName'] ?? 'Your Plan',
                        '{{reactivateURL}}'  => getSetting('app.url', 'https://signula.id') . '/account/billing',
                    ],
                    $userID,
                    2 // 📨 High priority
                );
            }

            ActivityLogger::log(
                $userID,
                'dunning_grace_expired',
                'payment',
                'warning',
                'Grace window expired for subscription #' . $subscriptionID . ' — subscription cancelled',
                ['subscriptionID' => $subscriptionID]
            );

            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('subscription.cancelled', [
                    'subscriptionID' => $subscriptionID,
                    'userID'         => $userID,
                    'reason'         => 'grace_expired',
                ]);
            }

            return ['success' => true, 'message' => 'Grace window expired — subscription #' . $subscriptionID . ' cancelled'];

        } catch (\Throwable $e) {
            ActivityLogger::log(
                null,
                'dunning_final_cancel_exception',
                'payment',
                'error',
                'Exception during dunning final-cancel processing: ' . $e->getMessage(),
                ['task' => $task, 'trace' => $e->getTraceAsString()]
            );

            return ['success' => false, 'message' => 'Final-cancel processing exception: ' . $e->getMessage()];
        }
    }

    /**
     * 📧 Send a Dunning Retry Failure Email
     *
     * Reuses the EXISTING `payment_failed` template (per the build
     * instruction — no new per-rung template) for every failed rung's
     * notification, correctly populated to match the template's real
     * placeholders (`{{displayName}}`/`{{currency}}`/`{{amount}}`/
     * `{{failureReason}}`/`{{updateURL}}` — see migration 012).
     *
     * @param array  $subscription  The joined subscription/tier/user row
     * @param int    $attemptNumber 1-based rung number, for logging only
     * @param string $failureReason Human-readable failure detail
     *
     * @return void
     */
    private static function sendDunningRetryEmail(array $subscription, int $attemptNumber, string $failureReason): void
    {
        $userID = (int) $subscription['userID'];
        $userEmail = (string) ($subscription['userEmail'] ?? self::getUserEmail($userID));

        if (empty($userEmail)) {
            return;
        }

        EmailService::sendTemplateEmail(
            $userEmail,
            'payment_failed',
            [
                '{{displayName}}'   => $subscription['displayName'] ?? 'Valued Customer',
                '{{tierName}}'      => $subscription['tierName'] ?? 'Your Plan',
                '{{currency}}'      => $subscription['currency'] ?? 'GBP',
                '{{amount}}'        => number_format((float) $subscription['amount'], 2),
                '{{failureReason}}' => $failureReason,
                '{{updateURL}}'     => getSetting('app.url', 'https://signula.id') . '/account/billing',
            ],
            $userID,
            2 // 📨 High priority
        );

        ActivityLogger::log(
            $userID,
            'dunning_retry_email_sent',
            'payment',
            'info',
            'Dunning retry #' . $attemptNumber . ' failure email sent for subscription #' . $subscription['subscriptionID'],
            ['subscriptionID' => $subscription['subscriptionID'], 'attemptNumber' => $attemptNumber]
        );
    }

    /**
     * 📝 Record a `tblBillingAttempts` Audit Row for a Dunning Retry
     *
     * Mirrors `PaymentManager::recordWebhookAuditRow()`'s established
     * one-shot-per-outcome pattern (no separate "attempted" pre-write — a
     * single row per terminal outcome). The row's `idempotencyKey` is what
     * `self::processDunningRetry()`'s own double-tick guard checks.
     *
     * @param int    $subscriptionID
     * @param int    $userID
     * @param string $provider       Lower-cased provider key (may be '')
     * @param string $idempotencyKey
     * @param string $status         'succeeded' | 'failed'
     * @param int    $attemptNumber
     * @param string $detail         Human-readable outcome detail
     *
     * @return void
     */
    private static function recordDunningAttempt(
        int $subscriptionID,
        int $userID,
        string $provider,
        string $idempotencyKey,
        string $status,
        int $attemptNumber,
        string $detail
    ): void {
        try {
            $mode = 'n/a';
            if (in_array($provider, ['paypal', 'stripe', 'coinbase'], true)) {
                $billingModePath = __DIR__ . DIRECTORY_SEPARATOR . 'BillingMode.php';
                if (file_exists($billingModePath)) {
                    require_once $billingModePath;
                    if (class_exists('BillingMode')) {
                        $mode = BillingMode::providerMode($provider);
                    }
                }
            }

            Database::query(
                "INSERT INTO tblBillingAttempts
                    (provider, action, mode, subscriptionID, userID, idempotencyKey, status, detail, ipAddress, createdAt)
                 VALUES (?, 'dunning_retry', ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $provider !== '' ? $provider : 'none',
                    $mode,
                    $subscriptionID,
                    $userID,
                    $idempotencyKey,
                    $status,
                    json_encode(['attemptNumber' => $attemptNumber, 'detail' => $detail], JSON_UNESCAPED_UNICODE),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ],
                'ssiissss'
            );
        } catch (\Throwable $e) {
            error_log('🚨 [BillingScheduler::recordDunningAttempt] Failed to write tblBillingAttempts audit row: ' . $e->getMessage());
        }
    }

    /**
     * 💸 Process Remittance Task
     *
     * Internal handler for 'process_remittance' tasks. Placeholder for
     * future partner payout/remittance processing. Currently logs and
     * returns success so tasks don't pile up.
     *
     * @param int $remittanceID The remittance record to process
     *
     * @return array {
     *     @type bool   $success Whether the remittance was processed
     *     @type string $message Human-readable outcome
     * }
     */
    private static function processRemittance(int $remittanceID): array
    {
        // 🚧 Placeholder for future remittance processing implementation
        // This will integrate with partner payout systems when developed
        ActivityLogger::log(
            null,
            'remittance_placeholder',
            'payment',
            'info',
            'Remittance processing placeholder invoked for remittance #' . $remittanceID,
            ['remittanceID' => $remittanceID]
        );

        return [
            'success' => true,
            'message' => 'Remittance processing placeholder — not yet implemented',
        ];
    }

    /**
     * 🔌 Execute Provider Charge
     *
     * Delegates the actual payment charge to the appropriate provider class
     * (PayPalProvider, StripeProvider, CoinbaseProvider) based on the stored
     * payment method's provider field. Each provider handles its own API
     * calls and returns a standardised result array.
     *
     * @param array  $paymentMethod The user's payment method record from tblPaymentMethods
     * @param float  $amount        The amount to charge
     * @param string $currency      ISO 4217 currency code
     * @param string $invoiceNumber The generated invoice number for reference
     *
     * @return array {
     *     @type bool        $success       Whether the charge succeeded
     *     @type string|null $transactionID Provider's transaction reference
     *     @type string|null $receiptURL    Provider's receipt URL
     *     @type string      $message       Outcome description
     * }
     *
     * @see PayPalProvider    PayPal payment processing
     * @see StripeProvider    Stripe payment processing
     * @see CoinbaseProvider  Cryptocurrency payment processing
     */
    private static function executeProviderCharge(
        array $paymentMethod,
        float $amount,
        string $currency,
        string $invoiceNumber
    ): array {
        $provider = strtolower($paymentMethod['provider'] ?? '');
        $providerMethodID = $paymentMethod['providerMethodID'] ?? null;

        try {
            // 🔀 Route to the appropriate payment provider
            switch ($provider) {
                case 'paypal':
                    // 💳 PayPal charge via stored billing agreement or payment token
                    if (class_exists('PayPalProvider')) {
                        return PayPalProvider::chargeStoredMethod(
                            $providerMethodID,
                            $amount,
                            $currency,
                            $invoiceNumber
                        );
                    }
                    return ['success' => false, 'message' => 'PayPalProvider class not available'];

                case 'stripe':
                    // 💳 Stripe charge via stored payment method
                    if (class_exists('StripeProvider')) {
                        return StripeProvider::chargeStoredMethod(
                            $providerMethodID,
                            $amount,
                            $currency,
                            $invoiceNumber
                        );
                    }
                    return ['success' => false, 'message' => 'StripeProvider class not available'];

                case 'coinbase':
                    // 🪙 Cryptocurrency charge via Coinbase Commerce
                    if (class_exists('CoinbaseProvider')) {
                        return CoinbaseProvider::chargeStoredMethod(
                            $providerMethodID,
                            $amount,
                            $currency,
                            $invoiceNumber
                        );
                    }
                    return ['success' => false, 'message' => 'CoinbaseProvider class not available'];

                default:
                    return [
                        'success' => false,
                        'message' => 'Unsupported payment provider: ' . $provider,
                    ];
            }

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'provider_charge_exception',
                'payment',
                'error',
                'Exception in ' . $provider . ' charge: ' . $e->getMessage(),
                [
                    'provider'         => $provider,
                    'amount'           => $amount,
                    'currency'         => $currency,
                    'providerMethodID' => $providerMethodID,
                ]
            );

            return [
                'success' => false,
                'message' => 'Provider exception: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 📧 Send Payment Receipt Email
     *
     * Sends a payment receipt confirmation email after a successful
     * subscription charge.
     *
     * @param int    $userID       The user who was charged
     * @param array  $subscription The subscription record (with tier details)
     * @param float  $amount       The amount charged
     * @param string $currency     ISO 4217 currency code
     * @param string $invoiceNumber The invoice reference number
     *
     * @return void
     *
     * @see EmailService::sendTemplateEmail()
     */
    private static function sendPaymentReceiptEmail(
        int $userID,
        array $subscription,
        float $amount,
        string $currency,
        string $invoiceNumber
    ): void {
        $userEmail = self::getUserEmail($userID);
        if (empty($userEmail)) {
            return;
        }

        EmailService::sendTemplateEmail(
            $userEmail,
            'payment_receipt',
            [
                '{{tierName}}'      => $subscription['tierName'] ?? 'Subscription',
                '{{amount}}'        => $currency . ' ' . number_format($amount, 2),
                '{{invoiceNumber}}' => $invoiceNumber,
                '{{billingCycle}}'  => $subscription['billingCycle'] ?? 'monthly',
                '{{date}}'          => date('d M Y'),
                '{{manageURL}}'     => getSetting('app.url', 'https://signula.id') . '/account/billing',
            ],
            $userID,
            5 // 📨 Normal priority
        );
    }

    /**
     * 📅 Calculate Next Billing Date
     *
     * Calculates the next billing date by advancing the current date by
     * the specified billing cycle interval.
     *
     * @param string $billingCycle The billing cycle ('monthly', 'quarterly', 'yearly', 'lifetime')
     *
     * @return string The next billing date in Y-m-d format
     *
     * @see https://www.php.net/manual/en/function.date.php
     * @see https://www.php.net/manual/en/function.strtotime.php
     */
    private static function calculateNextBillingDate(string $billingCycle): string
    {
        return match ($billingCycle) {
            'monthly'   => date('Y-m-d', strtotime('+1 month')),
            'quarterly' => date('Y-m-d', strtotime('+3 months')),
            'yearly'    => date('Y-m-d', strtotime('+1 year')),
            'lifetime'  => '9999-12-31',
            default     => date('Y-m-d', strtotime('+1 month')),
        };
    }

    /**
     * 📧 Get User Email Address
     *
     * Retrieves a user's primary email address from tblUsers.
     *
     * @param int $userID The user ID to look up
     *
     * @return string The user's email address, or empty string if not found
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/select.html
     */
    private static function getUserEmail(int $userID): string
    {
        $user = Database::fetchOne(
            "SELECT email FROM tblUsers WHERE userID = ?",
            [$userID],
            'i'
        );

        return $user ? (string) $user['email'] : '';
    }

    /**
     * ⚙️ Get a Setting Value from tblSettings
     *
     * Retrieves a configuration value from the tblSettings table. Falls
     * back to the provided default if the key does not exist.
     *
     * This is a private helper that queries the database directly rather
     * than using the global getSetting() function, to ensure we always
     * get the latest persisted value (the global function uses the
     * $GLOBALS cache which may be stale for billing-critical operations).
     *
     * @param string $key     The setting key (e.g., 'billing.grace_period_days')
     * @param mixed  $default Default value if the setting is not found
     *
     * @return string The setting value as a string
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/select.html
     */
    private static function getSetting(string $key, mixed $default = ''): string
    {
        $setting = Database::fetchOne(
            "SELECT settingValue FROM tblSettings WHERE settingKey = ?",
            [$key],
            's'
        );

        return $setting ? $setting['settingValue'] : (string) $default;
    }

    /**
     * 💾 Persist a Setting Value to tblSettings
     *
     * Inserts or updates a setting in the tblSettings table. Uses INSERT
     * ... ON DUPLICATE KEY UPDATE for upsert behaviour.
     *
     * @param string $key   The setting key
     * @param string $value The setting value to store
     *
     * @return void
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html
     */
    private static function persistSetting(string $key, string $value): void
    {
        try {
            Database::query(
                "INSERT INTO tblSettings (settingKey, settingValue, updatedAt)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), updatedAt = NOW()",
                [$key, $value],
                'ss'
            );

            // 🔄 Also update the in-memory global cache so getSetting() from config.php
            // returns the fresh value during this request
            if (function_exists('setSetting')) {
                setSetting($key, $value);
            }

        } catch (\Exception $e) {
            // 🛑 Non-fatal — log but don't throw
            ActivityLogger::log(
                null,
                'setting_persist_failed',
                'payment',
                'error',
                'Failed to persist setting "' . $key . '": ' . $e->getMessage(),
                ['key' => $key, 'value' => $value]
            );
        }
    }

    // ========================================================================
    // 📊 USAGE BILLING TASK HANDLERS
    // ========================================================================
    // These methods handle the three new task types introduced in Migration 018
    // for usage-based and hybrid billing support.
    //
    // Task flow:
    //   1. calculate_usage — Aggregate usage records into billing summaries
    //   2. charge_usage    — Generate invoices and process payments for summaries
    //   3. archive_usage   — Clean up old processed usage records
    //
    // @see UsageBillingService for cost calculation and cap logic
    // @see UsageTracker for usage data retrieval and record management
    // @since 2.7.0-beta
    // ========================================================================

    /**
     * 📊 Process Usage Calculation Task
     *
     * Calculates usage costs for a subscription's completed billing period.
     * Creates a billing summary in tblUsageBillingSummary with the cap logic
     * applied (for hybrid mode subscriptions).
     *
     * @param int $subscriptionID The subscription to calculate usage for
     *
     * @return array {
     *     @type bool   $success Whether the calculation completed
     *     @type string $message Result description
     *     @type int    $summaryID The generated summary ID (if successful)
     * }
     *
     * @see UsageBillingService::generateBillingSummary()
     * @since 2.7.0-beta
     */
    public static function processUsageCalculation(int $subscriptionID): array
    {
        try {
            // 🔍 Check if usage billing is enabled globally
            $usageEnabled = getSetting('billing.usage.enabled', 'false');
            if ($usageEnabled !== 'true' && $usageEnabled !== '1') {
                return [
                    'success' => true,
                    'message' => 'Usage billing is disabled globally — skipping calculation',
                ];
            }

            // 📋 Get subscription details to determine billing period
            $subscription = Database::fetchOne(
                "SELECT s.*, t.monthlyPrice, t.tierName
                 FROM tblSubscriptions s
                 JOIN tblSubscriptionTiers t ON s.tierID = t.tierID
                 WHERE s.subscriptionID = ?",
                [$subscriptionID],
                'i'
            );

            if (!$subscription) {
                return [
                    'success' => false,
                    'message' => 'Subscription not found: ' . $subscriptionID,
                ];
            }

            // 🔀 Only process usage/hybrid billing modes
            $billingMode = $subscription['billingMode'] ?? 'fixed';
            if ($billingMode === 'fixed') {
                return [
                    'success' => true,
                    'message' => 'Subscription uses fixed billing — no usage calculation needed',
                ];
            }

            // 📅 Determine the billing period that just ended
            // The period end is the day before the next billing date
            $nextBillingDate = $subscription['nextBillingDate'] ?? date('Y-m-d');
            $periodEnd = date('Y-m-d', strtotime($nextBillingDate . ' -1 day'));

            // Calculate period start based on billing cycle
            $billingCycle = $subscription['billingCycle'] ?? 'monthly';
            $periodStart = match ($billingCycle) {
                'monthly'   => date('Y-m-d', strtotime($periodEnd . ' -1 month +1 day')),
                'quarterly' => date('Y-m-d', strtotime($periodEnd . ' -3 months +1 day')),
                'yearly'    => date('Y-m-d', strtotime($periodEnd . ' -1 year +1 day')),
                default     => date('Y-m-d', strtotime($periodEnd . ' -1 month +1 day')),
            };

            // 📊 Generate billing summary via UsageBillingService
            $result = UsageBillingService::generateBillingSummary(
                $subscriptionID,
                $periodStart,
                $periodEnd
            );

            if ($result['success']) {
                // 📝 Log successful calculation
                ActivityLogger::log(
                    (int) $subscription['userID'],
                    'usage_calculated',
                    'billing',
                    'info',
                    'Usage billing calculated for subscription ' . $subscriptionID
                        . ' — period ' . $periodStart . ' to ' . $periodEnd
                        . ', total: ' . ($result['summary']['cappedAmount'] ?? '0.00'),
                    [
                        'subscriptionID' => $subscriptionID,
                        'periodStart'    => $periodStart,
                        'periodEnd'      => $periodEnd,
                        'summaryID'      => $result['summaryID'] ?? 0,
                        'billingMode'    => $billingMode,
                        'capApplied'     => $result['summary']['capApplied'] ?? false,
                    ]
                );

                // 📋 Schedule a charge_usage task to process the payment
                self::createTask(
                    'charge_usage',
                    'subscription',
                    $subscriptionID,
                    date('Y-m-d H:i:s'), // 🕐 Process immediately
                    ['summaryID' => $result['summaryID'] ?? 0]
                );
            }

            return $result;

        } catch (\Exception $e) {
            ErrorLogger::log($e);
            return [
                'success' => false,
                'message' => 'Usage calculation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 💳 Process Usage Charge Task
     *
     * Takes a completed usage billing summary and generates an invoice,
     * then processes the payment through the configured payment provider.
     *
     * @param int $subscriptionID The subscription to charge usage for
     *
     * @return array {
     *     @type bool   $success Whether the charge completed
     *     @type string $message Result description
     * }
     *
     * @see InvoiceManager::createUsageInvoice()
     * @see PaymentManager::processUsagePayment()
     * @since 2.7.0-beta
     */
    public static function processUsageCharge(int $subscriptionID): array
    {
        try {
            // 🔍 Find the most recent pending billing summary for this subscription
            $summary = Database::fetchOne(
                "SELECT * FROM tblUsageBillingSummary
                 WHERE subscriptionID = ? AND status = 'pending'
                 ORDER BY billingPeriodEnd DESC
                 LIMIT 1",
                [$subscriptionID],
                'i'
            );

            if (!$summary) {
                return [
                    'success' => true,
                    'message' => 'No pending usage billing summary found for subscription ' . $subscriptionID,
                ];
            }

            $summaryID = (int) $summary['summaryID'];
            $cappedAmount = (float) $summary['cappedAmount'];

            // 💰 Skip zero-cost summaries (all usage within free allowance)
            if ($cappedAmount <= 0.00) {
                // ✅ Mark as paid with zero amount — no payment needed
                UsageBillingService::updateSummaryStatus($summaryID, 'paid');

                ActivityLogger::log(
                    (int) $summary['userID'],
                    'usage_zero_cost',
                    'billing',
                    'info',
                    'Usage billing for subscription ' . $subscriptionID . ' resulted in zero cost — no charge applied',
                    ['summaryID' => $summaryID, 'billingMode' => $summary['billingMode']]
                );

                return [
                    'success' => true,
                    'message' => 'Zero usage cost — no charge needed',
                ];
            }

            // 🧾 Generate an itemised usage invoice via InvoiceManager
            $lineItems = json_decode($summary['lineItems'], true) ?? [];
            $invoiceLineItems = [];

            foreach ($lineItems as $item) {
                $invoiceLineItems[] = [
                    'description' => ($item['metricName'] ?? 'Usage') . ' (' . ($item['billableQuantity'] ?? 0) . ' ' . ($item['unitLabel'] ?? 'units') . ')',
                    'quantity'    => 1,
                    'unitPrice'   => (float) ($item['subtotal'] ?? 0),
                    'amount'      => (float) ($item['subtotal'] ?? 0),
                ];
            }

            // 📝 If cap was applied, add a line item showing the discount
            if (!empty($summary['capApplied']) && $summary['capApplied'] == 1) {
                $savings = (float) $summary['savingsAmount'];
                if ($savings > 0) {
                    $invoiceLineItems[] = [
                        'description' => 'Tier cap discount (hybrid billing)',
                        'quantity'    => 1,
                        'unitPrice'   => -$savings,
                        'amount'      => -$savings,
                    ];
                }
            }

            // 🧾 Create the invoice
            $invoiceResult = InvoiceManager::createInvoice(
                (int) $summary['userID'],
                'usage',
                $invoiceLineItems,
                [
                    'autoIssue'      => true,
                    'currency'       => $summary['currency'] ?? 'GBP',
                    'partnerID'      => $summary['partnerID'] ?? null,
                    'subscriptionID' => $subscriptionID,
                    'notes'          => 'Usage billing for period '
                        . $summary['billingPeriodStart'] . ' to ' . $summary['billingPeriodEnd']
                        . ($summary['capApplied'] ? ' (tier cap applied)' : ''),
                ]
            );

            if ($invoiceResult['success']) {
                // 🔗 Link invoice to billing summary
                UsageBillingService::updateSummaryStatus(
                    $summaryID,
                    'invoiced',
                    $invoiceResult['invoiceID']
                );

                // 💳 Attempt to process payment
                $paymentResult = PaymentManager::processUsagePayment(
                    (int) $summary['userID'],
                    $subscriptionID,
                    $cappedAmount,
                    $summary['currency'] ?? 'GBP',
                    $invoiceResult['invoiceID'],
                    $summaryID
                );

                if ($paymentResult['success']) {
                    UsageBillingService::updateSummaryStatus(
                        $summaryID,
                        'paid',
                        $invoiceResult['invoiceID'],
                        $paymentResult['paymentID'] ?? null
                    );
                }

                return $paymentResult;
            }

            return $invoiceResult;

        } catch (\Exception $e) {
            ErrorLogger::log($e);
            return [
                'success' => false,
                'message' => 'Usage charge processing failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 🗑️ Process Usage Archival Task
     *
     * Cleans up old processed usage records based on the configured retention
     * period. This is a maintenance task that runs periodically to prevent
     * tblUsageRecords from growing indefinitely.
     *
     * @return array {
     *     @type bool   $success Whether the archival completed
     *     @type string $message Result description
     *     @type int    $deleted Number of records deleted
     * }
     *
     * @see UsageTracker::purgeOldRecords()
     * @since 2.7.0-beta
     */
    public static function processUsageArchival(): array
    {
        try {
            // 📅 Get retention period from settings
            $retentionDays = (int) getSetting('billing.usage.retention_days', '365');

            // 🗑️ Purge old processed records
            $deleted = UsageTracker::purgeOldRecords($retentionDays);

            // 📝 Log the archival activity
            ActivityLogger::log(
                null,
                'usage_archival',
                'billing',
                'info',
                'Usage record archival completed: ' . $deleted . ' records deleted'
                    . ' (retention: ' . $retentionDays . ' days)',
                ['deletedCount' => $deleted, 'retentionDays' => $retentionDays]
            );

            // 📅 Schedule next archival task (daily)
            self::createTask(
                'archive_usage',
                'subscription',
                0, // No specific target — system-wide task
                date('Y-m-d H:i:s', strtotime('+1 day')),
                ['retentionDays' => $retentionDays]
            );

            return [
                'success' => true,
                'message' => 'Archival completed: ' . $deleted . ' old usage records deleted',
                'deleted' => $deleted,
            ];

        } catch (\Exception $e) {
            ErrorLogger::log($e);
            return [
                'success' => false,
                'message' => 'Usage archival failed: ' . $e->getMessage(),
                'deleted' => 0,
            ];
        }
    }

    /**
     * 📋 Schedule Usage Billing Tasks for Due Subscriptions
     *
     * Called at the end of each billing cycle to create calculate_usage tasks
     * for all subscriptions with usage/hybrid billing mode whose billing period
     * has ended. This is invoked from processDueTasks or a dedicated cron endpoint.
     *
     * @return array {
     *     @type bool   $success Whether scheduling completed
     *     @type int    $scheduled Number of tasks scheduled
     *     @type string $message Result description
     * }
     *
     * @since 2.7.0-beta
     */
    public static function scheduleUsageBillingTasks(): array
    {
        try {
            // 🔍 Check if usage billing is enabled
            $usageEnabled = getSetting('billing.usage.enabled', 'false');
            if ($usageEnabled !== 'true' && $usageEnabled !== '1') {
                return [
                    'success' => true,
                    'scheduled' => 0,
                    'message' => 'Usage billing is disabled globally',
                ];
            }

            // 📋 Find subscriptions with usage/hybrid billing that need calculation
            // These are active subscriptions whose nextBillingDate has passed
            // and don't already have a pending calculate_usage task
            $subscriptions = Database::fetchAll(
                "SELECT s.subscriptionID, s.userID, s.billingMode, s.nextBillingDate
                 FROM tblSubscriptions s
                 WHERE s.billingMode IN ('usage', 'hybrid')
                   AND s.subscriptionStatus = 'active'
                   AND s.nextBillingDate <= CURDATE()
                   AND NOT EXISTS (
                       SELECT 1 FROM tblBillingSchedule bs
                       WHERE bs.targetType = 'subscription'
                         AND bs.targetID = s.subscriptionID
                         AND bs.taskType IN ('calculate_usage', 'charge_usage')
                         AND bs.status IN ('pending', 'processing')
                   )",
                [],
                ''
            );

            $scheduled = 0;
            foreach ($subscriptions as $sub) {
                // 📊 Create a calculate_usage task for each due subscription
                self::createTask(
                    'calculate_usage',
                    'subscription',
                    (int) $sub['subscriptionID'],
                    date('Y-m-d H:i:s'), // Process now
                    [
                        'userID'      => (int) $sub['userID'],
                        'billingMode' => $sub['billingMode'],
                    ]
                );
                $scheduled++;
            }

            return [
                'success' => true,
                'scheduled' => $scheduled,
                'message' => 'Scheduled ' . $scheduled . ' usage billing calculation tasks',
            ];

        } catch (\Exception $e) {
            ErrorLogger::log($e);
            return [
                'success' => false,
                'scheduled' => 0,
                'message' => 'Failed to schedule usage billing tasks: ' . $e->getMessage(),
            ];
        }
    }
}

// ✅ BillingScheduler class loaded successfully
