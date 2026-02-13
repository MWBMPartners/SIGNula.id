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

    /** @var array Valid task types for tblBillingSchedule.taskType ENUM */
    const TASK_TYPES = [
        'charge_subscription',
        'send_reminder',
        'suspend_account',
        'expire_trial',
        'process_remittance',
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
        return Database::fetchAll(
            "SELECT *
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
            Database::query(
                "UPDATE tblBillingSchedule
                 SET status = ?,
                     attempts = attempts + 1,
                     lastAttemptAt = NOW(),
                     result = ?,
                     updatedAt = NOW()
                 WHERE taskID = ?",
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

            Database::query(
                "UPDATE tblBillingSchedule
                 SET status = 'pending',
                     nextRetryAt = ?,
                     attempts = attempts + 1,
                     lastAttemptAt = NOW(),
                     updatedAt = NOW()
                 WHERE taskID = ?",
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
}

// ✅ BillingScheduler class loaded successfully
