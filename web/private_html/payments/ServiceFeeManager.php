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
 * 💰 SIGNula - Service Fee Manager
 * ============================================================================
 *
 * Purpose: Manage the two-tier service fee system for partner payment processing,
 *          including fee calculation, fee transaction recording, remittance (partner
 *          payout) lifecycle, earnings reporting, and administrative statistics.
 *
 * PHP Version: 8.3+ (targeting 8.4 for longevity)
 *
 * Two-Tier Payment Model:
 *   Level 1: Partners/customers pay SIGNula for premium tiers (SIGNula's own keys)
 *   Level 2: Partners collect payments from THEIR customers through SIGNula
 *     - Option 2a (partner_own_keys):    Partner uses their own payment provider
 *                                        API keys -> SIGNula charges ~10% service fee
 *     - Option 2b (partner_signula_keys): Partner uses SIGNula's keys -> SIGNula
 *                                        charges ~30%, remits remainder to partner
 *
 * Fee Change Policy:
 *   - All fee schedule changes require a minimum 30 calendar days' notice
 *   - The notice period is configurable via tblSettings key:
 *     payment.service_fee.minimum_notice_days
 *   - Partners are notified via 'service_fee_change' email template
 *
 * Database Tables Used:
 *   - tblServiceFees             : Fee schedule definitions (rates, caps, dates)
 *   - tblServiceFeeTransactions  : Per-transaction fee records and remittance links
 *   - tblRemittances             : Batch payout records for partner payouts
 *   - tblPartnerPaymentConfig    : Partner-specific payment config (own vs SIGNula keys)
 *   - tblPartners                : Partner master records (payoutEmail, payoutMethod)
 *   - tblSettings                : System-wide configuration values
 *
 * @see /web/private_html/payments/PaymentManager.php   Core payment processing
 * @see /_database/migrations/012_payment_expansion.sql  Schema definitions
 * @see https://developer.paypal.com/docs/payouts/       PayPal Payouts API
 * @see https://docs.stripe.com/connect/payouts           Stripe Connect Payouts
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.0.0
 * @since      2.4.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access — must be loaded via SIGNula bootstrap
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class ServiceFeeManager
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /** @var string Default currency for fee calculations (ISO 4217) */
    const DEFAULT_CURRENCY = 'GBP';

    /**
     * @var string Fee type for partners using their own payment provider keys (Option 2a)
     * Maps to tblServiceFees.feeType ENUM value
     */
    const FEE_TYPE_OWN_KEYS = 'partner_own_keys';

    /**
     * @var string Fee type for partners using SIGNula's payment keys (Option 2b)
     * Maps to tblServiceFees.feeType ENUM value
     */
    const FEE_TYPE_SIGNULA_KEYS = 'partner_signula_keys';

    /**
     * @var int Default minimum notice period (calendar days) before a fee change takes effect
     * Can be overridden by tblSettings key: payment.service_fee.minimum_notice_days
     */
    const DEFAULT_MINIMUM_NOTICE_DAYS = 30;

    /** @var array Valid fee transaction statuses (mirrors tblServiceFeeTransactions.status ENUM) */
    const FEE_TRANSACTION_STATUSES = [
        'pending',    // Fee recorded but not yet collected from partner
        'collected',  // Fee amount deducted/collected from partner
        'remitted',   // Net amount paid out to partner
        'disputed',   // Partner has disputed this fee
        'refunded',   // Fee refunded due to payment reversal
    ];

    /** @var array Valid remittance statuses (mirrors tblRemittances.status ENUM) */
    const REMITTANCE_STATUSES = [
        'pending',     // Remittance created, awaiting processing
        'processing',  // Payout initiated, awaiting confirmation
        'completed',   // Payout successfully delivered to partner
        'failed',      // Payout failed (see failureReason)
        'cancelled',   // Remittance cancelled by admin
    ];

    /** @var array Valid payout methods (mirrors tblRemittances.method ENUM) */
    const PAYOUT_METHODS = [
        'paypal_payout',      // PayPal Payouts API
        'bank_transfer',      // Manual bank transfer / wire
        'credit_to_balance',  // Credit partner's SIGNula balance
        'manual',             // Manual processing by admin
    ];


    // ========================================================================
    // 💰 FEE CALCULATION
    // ========================================================================

    /**
     * 🧮 Calculate the service fee for a given gross payment amount
     *
     * Determines the applicable fee schedule based on the feeType and optionally
     * checks for a partner-specific fee override in tblPartnerPaymentConfig.
     * Applies percentage rate + fixed fee, then enforces min/max fee caps.
     *
     * Fee Calculation Formula:
     *   calculatedFee = (grossAmount * feePercentage / 100) + fixedFeeAmount
     *   feeAmount     = max(minFee, min(maxFee, calculatedFee))
     *   netAmount     = grossAmount - feeAmount
     *
     * @param float    $grossAmount The original payment amount before any fee deduction
     * @param string   $feeType     Fee type: 'partner_own_keys' or 'partner_signula_keys'
     * @param int|null $partnerID   Optional partner ID to check for custom fee overrides
     *
     * @return array Associative array containing:
     *   - 'feeAmount'      (float) The calculated fee to charge the partner
     *   - 'netAmount'       (float) The net amount after fee deduction (grossAmount - feeAmount)
     *   - 'feePercentage'   (float) The percentage rate that was applied
     *   - 'fixedFee'        (float) The fixed fee component that was applied
     *   - 'feeID'           (int)   The fee schedule ID from tblServiceFees
     *   - 'currency'        (string) Currency of the fee schedule (ISO 4217)
     *   - 'isCustomRate'    (bool)  Whether a partner-specific override was used
     *
     * @throws \RuntimeException If no active fee schedule is found for the given feeType
     *
     * @see self::getCurrentFeeSchedule()  Retrieves the active fee schedule
     * @see tblServiceFees                  Fee schedule definitions
     * @see tblPartnerPaymentConfig         Partner-specific payment configuration
     */
    public static function calculateFee(float $grossAmount, string $feeType, ?int $partnerID = null): array
    {
        // 🔒 Validate fee type against allowed ENUM values
        $normalizedFeeType = self::normalizeFeeType($feeType);

        // 📊 Get the current active fee schedule for this fee type
        $feeSchedule = self::getCurrentFeeSchedule($normalizedFeeType);

        if (!$feeSchedule) {
            // ⚠️ No active fee schedule found — this is a configuration error
            // Log and throw so callers know something is misconfigured
            ActivityLogger::log(
                null,
                'service_fee_error',
                'payment',
                'error',
                'No active fee schedule found for fee type: ' . $normalizedFeeType,
                ['feeType' => $normalizedFeeType, 'grossAmount' => $grossAmount]
            );

            throw new \RuntimeException('No active fee schedule found for fee type: ' . $normalizedFeeType);
        }

        // 📋 Extract fee schedule values
        $feePercentage = (float) $feeSchedule['feePercentage'];
        $fixedFeeAmount = (float) $feeSchedule['fixedFeeAmount'];
        $minFee = $feeSchedule['minFee'] !== null ? (float) $feeSchedule['minFee'] : null;
        $maxFee = $feeSchedule['maxFee'] !== null ? (float) $feeSchedule['maxFee'] : null;
        $feeID = (int) $feeSchedule['feeID'];
        $currency = $feeSchedule['currency'] ?? self::DEFAULT_CURRENCY;
        $isCustomRate = false;

        // 🔍 Check for partner-specific fee override
        // Partners may negotiate custom fee rates stored in tblPartnerPaymentConfig metadata
        if ($partnerID !== null) {
            $partnerOverride = self::getPartnerFeeOverride($partnerID, $normalizedFeeType);

            if ($partnerOverride !== null) {
                // 🏷️ Partner has a custom negotiated rate — use it instead
                $feePercentage = (float) $partnerOverride['feePercentage'];
                $fixedFeeAmount = (float) ($partnerOverride['fixedFeeAmount'] ?? $fixedFeeAmount);
                $isCustomRate = true;
            }
        }

        // 🧮 Calculate the fee amount
        // Step 1: Apply percentage to gross amount
        $percentageFee = round($grossAmount * ($feePercentage / 100), 2);

        // Step 2: Add the fixed fee component
        $calculatedFee = round($percentageFee + $fixedFeeAmount, 2);

        // Step 3: Apply minimum fee cap (if configured)
        // @see https://www.php.net/manual/en/function.max.php
        if ($minFee !== null && $calculatedFee < $minFee) {
            $calculatedFee = $minFee;
        }

        // Step 4: Apply maximum fee cap (if configured)
        // @see https://www.php.net/manual/en/function.min.php
        if ($maxFee !== null && $calculatedFee > $maxFee) {
            $calculatedFee = $maxFee;
        }

        // Step 5: Ensure fee does not exceed gross amount
        // (edge case: small transactions with high fixed fees)
        $feeAmount = min($calculatedFee, $grossAmount);

        // 💵 Calculate net amount payable to partner
        $netAmount = round($grossAmount - $feeAmount, 2);

        return [
            'feeAmount'     => $feeAmount,
            'netAmount'     => $netAmount,
            'feePercentage' => $feePercentage,
            'fixedFee'      => $fixedFeeAmount,
            'feeID'         => $feeID,
            'currency'      => $currency,
            'isCustomRate'  => $isCustomRate,
        ];
    }


    // ========================================================================
    // 📝 FEE TRANSACTION RECORDING
    // ========================================================================

    /**
     * 💳 Record a service fee transaction for a partner payment
     *
     * Calculates the fee using calculateFee(), then inserts a record into
     * tblServiceFeeTransactions for reconciliation and later remittance.
     * This should be called whenever a Level 2 payment is completed.
     *
     * @param int   $paymentID   The payment ID from tblPayments that triggered this fee
     * @param int   $partnerID   The partner ID being charged the fee
     * @param float $grossAmount The original gross payment amount
     * @param string $feeType    Fee type: 'partner_own_keys' or 'partner_signula_keys'
     *                           (also accepts shorthand 'own_keys' / 'signula_keys')
     * @param array $options     Optional overrides:
     *   - 'currency' (string): Override currency (default: fee schedule currency)
     *
     * @return array Associative array:
     *   - 'success'          (bool)  Whether the transaction was recorded
     *   - 'feeTransactionID' (int)   The new fee transaction ID
     *   - 'feeAmount'        (float) The fee amount charged
     *   - 'netAmount'        (float) The net amount owed to partner
     *   - 'message'          (string) Human-readable status message (on failure)
     *
     * @see self::calculateFee()            Fee calculation logic
     * @see tblServiceFeeTransactions       Fee transaction records
     * @see PaymentManager::completePayment()  Typical caller after payment success
     */
    public static function recordFeeTransaction(
        int $paymentID,
        int $partnerID,
        float $grossAmount,
        string $feeType,
        array $options = []
    ): array {
        try {
            // 🧮 Calculate the fee for this transaction
            $feeResult = self::calculateFee($grossAmount, $feeType, $partnerID);

            // 💰 Determine the currency to record
            $currency = $options['currency'] ?? $feeResult['currency'] ?? self::DEFAULT_CURRENCY;

            // 💾 Insert fee transaction record into tblServiceFeeTransactions
            // Column names match migration 012 schema exactly:
            //   feePercentageApplied = snapshot of rate at time of charge
            //   netToPartner         = amount owed to partner after fee deduction
            Database::query(
                "INSERT INTO tblServiceFeeTransactions
                    (paymentID, partnerID, feeID, grossAmount, feePercentageApplied,
                     feeAmount, netToPartner, currency, status, createdAt)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())",
                [
                    $paymentID,
                    $partnerID,
                    $feeResult['feeID'],
                    $grossAmount,
                    $feeResult['feePercentage'],
                    $feeResult['feeAmount'],
                    $feeResult['netAmount'],
                    $currency,
                ],
                'iiidddds'
            );

            // 🔢 Get the newly inserted fee transaction ID
            // @see https://www.php.net/manual/en/mysqli.insert-id.php
            $feeTransactionID = Database::getLastInsertId();

            // 📝 Log activity for audit trail
            ActivityLogger::log(
                null,
                'service_fee_recorded',
                'payment',
                'info',
                'Service fee recorded: ' . $currency . ' ' . number_format($feeResult['feeAmount'], 2)
                    . ' (' . $feeResult['feePercentage'] . '%) on payment #' . $paymentID,
                [
                    'feeTransactionID' => $feeTransactionID,
                    'paymentID'        => $paymentID,
                    'partnerID'        => $partnerID,
                    'grossAmount'      => $grossAmount,
                    'feeAmount'        => $feeResult['feeAmount'],
                    'netToPartner'     => $feeResult['netAmount'],
                    'feePercentage'    => $feeResult['feePercentage'],
                    'feeID'            => $feeResult['feeID'],
                    'currency'         => $currency,
                    'isCustomRate'     => $feeResult['isCustomRate'],
                ]
            );

            // 🔗 Dispatch webhook event if WebhookManager is available
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('service_fee.recorded', [
                    'feeTransactionID' => $feeTransactionID,
                    'paymentID'        => $paymentID,
                    'partnerID'        => $partnerID,
                    'feeAmount'        => $feeResult['feeAmount'],
                    'netToPartner'     => $feeResult['netAmount'],
                    'currency'         => $currency,
                ]);
            }

            return [
                'success'          => true,
                'feeTransactionID' => $feeTransactionID,
                'feeAmount'        => $feeResult['feeAmount'],
                'netAmount'        => $feeResult['netAmount'],
            ];
        } catch (\Exception $e) {
            // ❌ Fee recording failed — log the error but don't crash the payment flow
            ActivityLogger::log(
                null,
                'service_fee_error',
                'payment',
                'error',
                'Failed to record service fee: ' . $e->getMessage(),
                [
                    'paymentID'   => $paymentID,
                    'partnerID'   => $partnerID,
                    'grossAmount' => $grossAmount,
                    'feeType'     => $feeType,
                ]
            );

            return [
                'success' => false,
                'message' => 'Failed to record service fee transaction',
            ];
        }
    }


    // ========================================================================
    // 📋 FEE SCHEDULE MANAGEMENT
    // ========================================================================

    /**
     * 📊 Get the currently active fee schedule for a given fee type
     *
     * Returns the fee schedule record where:
     *   - effectiveFrom <= CURDATE()
     *   - effectiveUntil IS NULL or effectiveUntil >= CURDATE()
     *   - isActive = 1
     * Ordered by effectiveFrom DESC to get the most recently activated schedule.
     *
     * @param string $feeType Fee type: 'partner_own_keys' or 'partner_signula_keys'
     *
     * @return array|null Fee schedule record or null if none active
     *
     * @see tblServiceFees Schema in migration 012
     */
    public static function getCurrentFeeSchedule(string $feeType): ?array
    {
        // 🔒 Normalize fee type for consistent lookup
        $normalizedFeeType = self::normalizeFeeType($feeType);

        // 📊 Query for the most recently effective, active fee schedule
        // Uses CURDATE() for date-only comparison (effectiveFrom is a DATE column)
        // @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html#function_curdate
        $schedule = Database::fetchOne(
            "SELECT *
             FROM tblServiceFees
             WHERE feeType = ?
               AND isActive = 1
               AND effectiveFrom <= CURDATE()
               AND (effectiveUntil IS NULL OR effectiveUntil >= CURDATE())
             ORDER BY effectiveFrom DESC
             LIMIT 1",
            [$normalizedFeeType],
            's'
        );

        return $schedule ?: null;
    }

    /**
     * ➕ Create a new fee schedule with enforced minimum notice period
     *
     * Creates a new fee schedule entry in tblServiceFees. The effectiveFrom date
     * MUST be at least 30 calendar days (configurable) from today to comply with
     * the service fee change notice policy.
     *
     * Optionally deactivates the existing active schedule by setting its
     * effectiveUntil to one day before the new schedule's effectiveFrom.
     *
     * @param string $feeName       Human-readable name for this fee schedule
     * @param string $feeType       Fee type: 'partner_own_keys' or 'partner_signula_keys'
     * @param float  $percentageRate Percentage fee rate (e.g. 10.00 = 10%)
     * @param float  $fixedFee      Fixed fee per transaction (default: 0.00)
     * @param string $effectiveFrom Date the schedule becomes active (Y-m-d format)
     * @param int    $createdBy     Admin userID creating this schedule
     * @param array  $options       Optional parameters:
     *   - 'currency'     (string): Currency for fixed fees (default: GBP)
     *   - 'minFee'       (float):  Minimum fee per transaction (default: null)
     *   - 'maxFee'       (float):  Maximum fee cap per transaction (default: null)
     *   - 'effectiveTo'  (string): Expiry date in Y-m-d format (default: null = no expiry)
     *
     * @return array Associative array:
     *   - 'success' (bool)   Whether the schedule was created
     *   - 'feeID'   (int)    The new fee schedule ID (on success)
     *   - 'message'  (string) Status message
     *
     * @see tblSettings key: payment.service_fee.minimum_notice_days
     */
    public static function createFeeSchedule(
        string $feeName,
        string $feeType,
        float $percentageRate,
        float $fixedFee,
        string $effectiveFrom,
        int $createdBy,
        array $options = []
    ): array {
        try {
            // 🔒 Normalize fee type
            $normalizedFeeType = self::normalizeFeeType($feeType);

            // ⏰ Retrieve minimum notice days from settings
            // Falls back to the class constant if the setting is not configured
            $minimumNoticeDays = (int) self::getSettingValue(
                'payment.service_fee.minimum_notice_days',
                (string) self::DEFAULT_MINIMUM_NOTICE_DAYS
            );

            // 📅 Calculate the earliest allowed effective date
            // The new schedule must be at least $minimumNoticeDays in the future
            // @see https://www.php.net/manual/en/function.strtotime.php
            $earliestAllowedDate = date('Y-m-d', strtotime('+' . $minimumNoticeDays . ' days'));

            // 🚫 ENFORCE the minimum notice period
            if ($effectiveFrom < $earliestAllowedDate) {
                return [
                    'success' => false,
                    'message' => 'Fee schedule effective date must be at least '
                        . $minimumNoticeDays . ' days from today. '
                        . 'Earliest allowed date: ' . $earliestAllowedDate,
                ];
            }

            // 📋 Extract optional parameters with defaults
            $currency = $options['currency'] ?? self::DEFAULT_CURRENCY;
            $minFee = $options['minFee'] ?? null;
            $maxFee = $options['maxFee'] ?? null;
            $effectiveTo = $options['effectiveTo'] ?? null;

            // 🔄 Deactivate the current active schedule for this fee type
            // Set its effectiveUntil to one day before the new schedule starts
            $currentSchedule = self::getCurrentFeeSchedule($normalizedFeeType);
            if ($currentSchedule) {
                $deactivateDate = date('Y-m-d', strtotime($effectiveFrom . ' -1 day'));
                Database::query(
                    "UPDATE tblServiceFees
                     SET effectiveUntil = ?
                     WHERE feeID = ?
                       AND effectiveUntil IS NULL",
                    [$deactivateDate, (int) $currentSchedule['feeID']],
                    'si'
                );
            }

            // 💾 Insert the new fee schedule
            Database::query(
                "INSERT INTO tblServiceFees
                    (feeType, feePercentage, fixedFeeAmount, currency,
                     minFee, maxFee, effectiveFrom, effectiveUntil,
                     minimumNoticeDays, isActive, createdBy, createdAt)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())",
                [
                    $normalizedFeeType,
                    $percentageRate,
                    $fixedFee,
                    $currency,
                    $minFee,
                    $maxFee,
                    $effectiveFrom,
                    $effectiveTo,
                    $minimumNoticeDays,
                    $createdBy,
                ],
                'sddsddssii'
            );

            // 🔢 Get the newly inserted fee schedule ID
            $feeID = Database::getLastInsertId();

            // 📝 Log activity
            ActivityLogger::log(
                $createdBy,
                'service_fee_schedule_created',
                'payment',
                'info',
                'New service fee schedule created: ' . $feeName
                    . ' (' . $percentageRate . '% + ' . $currency . ' ' . number_format($fixedFee, 2)
                    . ', effective ' . $effectiveFrom . ')',
                [
                    'feeID'          => $feeID,
                    'feeName'        => $feeName,
                    'feeType'        => $normalizedFeeType,
                    'percentageRate' => $percentageRate,
                    'fixedFee'       => $fixedFee,
                    'currency'       => $currency,
                    'effectiveFrom'  => $effectiveFrom,
                    'effectiveTo'    => $effectiveTo,
                    'minFee'         => $minFee,
                    'maxFee'         => $maxFee,
                    'minimumNotice'  => $minimumNoticeDays,
                ]
            );

            return [
                'success' => true,
                'feeID'   => $feeID,
                'message' => 'Fee schedule created successfully (effective ' . $effectiveFrom . ')',
            ];
        } catch (\Exception $e) {
            // ❌ Creation failed
            ActivityLogger::log(
                $createdBy,
                'service_fee_error',
                'payment',
                'error',
                'Failed to create fee schedule: ' . $e->getMessage(),
                [
                    'feeName'        => $feeName,
                    'feeType'        => $feeType,
                    'percentageRate' => $percentageRate,
                ]
            );

            return [
                'success' => false,
                'message' => 'Failed to create fee schedule',
            ];
        }
    }


    // ========================================================================
    // 📢 FEE CHANGE NOTIFICATIONS
    // ========================================================================

    /**
     * 📧 Notify all active partners about a fee schedule change
     *
     * Sends the 'service_fee_change' email template to every active partner,
     * including the old rate, new rate, and effective date. Also updates the
     * noticeGivenAt timestamp on the fee schedule record.
     *
     * The email template variables match the template defined in migration 012:
     *   {{partnerName}}, {{feeTypeName}}, {{currentFee}}, {{newFee}},
     *   {{effectiveDate}}, {{noticeDays}}, {{minimumNoticeDays}}
     *
     * @param int $feeID The fee schedule ID from tblServiceFees to notify about
     *
     * @return array Associative array:
     *   - 'success'       (bool) Whether notifications were sent
     *   - 'notifiedCount' (int)  Number of partners notified
     *   - 'message'       (string) Status message
     *
     * @see tblEmailTemplates key: 'service_fee_change'
     * @see EmailService::sendTemplateEmail()
     */
    public static function notifyFeeChange(int $feeID): array
    {
        try {
            // 📊 Fetch the new fee schedule
            $newSchedule = Database::fetchOne(
                "SELECT * FROM tblServiceFees WHERE feeID = ?",
                [$feeID],
                'i'
            );

            if (!$newSchedule) {
                return [
                    'success' => false,
                    'notifiedCount' => 0,
                    'message' => 'Fee schedule not found',
                ];
            }

            // 📊 Fetch the currently active (old) fee schedule for comparison
            // We want the schedule that is currently effective (before the new one kicks in)
            $currentSchedule = Database::fetchOne(
                "SELECT * FROM tblServiceFees
                 WHERE feeType = ?
                   AND isActive = 1
                   AND effectiveFrom <= CURDATE()
                   AND (effectiveUntil IS NULL OR effectiveUntil >= CURDATE())
                   AND feeID != ?
                 ORDER BY effectiveFrom DESC
                 LIMIT 1",
                [$newSchedule['feeType'], $feeID],
                'si'
            );

            // 📋 Determine old rate (if no current schedule, treat as 0%)
            $currentFeePercentage = $currentSchedule
                ? (float) $currentSchedule['feePercentage']
                : 0.00;

            // 📝 Prepare human-readable fee type name for the email
            $feeTypeName = match ($newSchedule['feeType']) {
                'partner_own_keys'    => 'Partner Own Keys (Option 2a)',
                'partner_signula_keys' => 'SIGNula Managed Keys (Option 2b)',
                default               => $newSchedule['feeType'],
            };

            // 📅 Calculate days until the change takes effect
            $noticeDays = max(0, (int) round(
                (strtotime($newSchedule['effectiveFrom']) - time()) / 86400
            ));

            // 📋 Fetch all active partners to notify
            // Only partners with status = 'active' receive fee change notifications
            $partners = Database::fetchAll(
                "SELECT partnerID, partnerName, billingEmail
                 FROM tblPartners
                 WHERE status = 'active'
                   AND billingEmail IS NOT NULL
                   AND billingEmail != ''",
                [],
                ''
            );

            // 📧 Send notification email to each active partner
            $notifiedCount = 0;
            $minimumNoticeDays = (int) ($newSchedule['minimumNoticeDays'] ?? self::DEFAULT_MINIMUM_NOTICE_DAYS);

            foreach ($partners as $partner) {
                // 📨 Send the 'service_fee_change' template email
                // @see EmailService::sendTemplateEmail()
                $sent = EmailService::sendTemplateEmail(
                    $partner['billingEmail'],
                    'service_fee_change',
                    [
                        'partnerName'       => $partner['partnerName'],
                        'feeTypeName'       => $feeTypeName,
                        'currentFee'        => number_format($currentFeePercentage, 2),
                        'newFee'            => number_format((float) $newSchedule['feePercentage'], 2),
                        'effectiveDate'     => date('j F Y', strtotime($newSchedule['effectiveFrom'])),
                        'noticeDays'        => (string) $noticeDays,
                        'minimumNoticeDays' => (string) $minimumNoticeDays,
                    ],
                    null,  // userID (null — this is a partner-level notification)
                    3      // priority (high — fee changes are important)
                );

                if ($sent) {
                    $notifiedCount++;
                }
            }

            // 🕐 Update the noticeGivenAt timestamp on the fee schedule
            Database::query(
                "UPDATE tblServiceFees SET noticeGivenAt = NOW() WHERE feeID = ?",
                [$feeID],
                'i'
            );

            // 📝 Log the notification activity
            ActivityLogger::log(
                null,
                'service_fee_notification_sent',
                'payment',
                'info',
                'Fee change notification sent to ' . $notifiedCount . ' partners for fee schedule #' . $feeID,
                [
                    'feeID'           => $feeID,
                    'feeType'         => $newSchedule['feeType'],
                    'oldRate'         => $currentFeePercentage,
                    'newRate'         => (float) $newSchedule['feePercentage'],
                    'effectiveFrom'   => $newSchedule['effectiveFrom'],
                    'notifiedCount'   => $notifiedCount,
                    'totalPartners'   => count($partners),
                ]
            );

            return [
                'success'       => true,
                'notifiedCount' => $notifiedCount,
                'message'       => 'Notifications sent to ' . $notifiedCount . ' of ' . count($partners) . ' active partners',
            ];
        } catch (\Exception $e) {
            // ❌ Notification sending failed
            ActivityLogger::log(
                null,
                'service_fee_error',
                'payment',
                'error',
                'Failed to send fee change notifications: ' . $e->getMessage(),
                ['feeID' => $feeID]
            );

            return [
                'success'       => false,
                'notifiedCount' => 0,
                'message'       => 'Failed to send fee change notifications',
            ];
        }
    }


    // ========================================================================
    // 💸 REMITTANCE (PARTNER PAYOUT) MANAGEMENT
    // ========================================================================

    /**
     * 📦 Create a remittance (payout batch) for a partner
     *
     * Aggregates all unremitted (status = 'pending' or 'collected') fee transactions
     * for the specified partner within the given period, calculates the total net
     * amount to remit, creates a remittance record in tblRemittances, and links
     * the fee transactions to it.
     *
     * @param int    $partnerID   The partner to create a remittance for
     * @param string $periodStart Start of the earnings period (Y-m-d)
     * @param string $periodEnd   End of the earnings period (Y-m-d)
     * @param array  $options     Optional parameters:
     *   - 'payoutMethod' (string): Payout method override (default: partner's preference)
     *   - 'payoutEmail'  (string): Payout email override (default: partner's payoutEmail)
     *   - 'notes'        (string): Admin notes for this remittance
     *
     * @return array Associative array:
     *   - 'success'          (bool)  Whether the remittance was created
     *   - 'remittanceID'     (int)   The new remittance ID
     *   - 'amount'           (float) The net amount to remit
     *   - 'transactionCount' (int)   Number of fee transactions included
     *   - 'message'          (string) Status message (on failure)
     *
     * @see tblRemittances          Remittance records
     * @see tblServiceFeeTransactions Fee transactions linked to remittances
     */
    public static function createRemittance(
        int $partnerID,
        string $periodStart,
        string $periodEnd,
        array $options = []
    ): array {
        try {
            // 🔍 Fetch partner details for payout preferences
            $partner = Database::fetchOne(
                "SELECT partnerID, partnerName, payoutEmail, payoutMethod, billingEmail
                 FROM tblPartners
                 WHERE partnerID = ?",
                [$partnerID],
                'i'
            );

            if (!$partner) {
                return [
                    'success' => false,
                    'message' => 'Partner not found',
                ];
            }

            // 📊 Aggregate unremitted fee transactions for the period
            // Only include transactions that haven't been assigned to a remittance yet
            // and are in 'pending' or 'collected' status
            $aggregation = Database::fetchOne(
                "SELECT
                    COUNT(*) AS transactionCount,
                    COALESCE(SUM(grossAmount), 0) AS grossTotal,
                    COALESCE(SUM(feeAmount), 0) AS feesTotal,
                    COALESCE(SUM(netToPartner), 0) AS netTotal,
                    MIN(currency) AS currency
                 FROM tblServiceFeeTransactions
                 WHERE partnerID = ?
                   AND status IN ('pending', 'collected')
                   AND remittanceReference IS NULL
                   AND createdAt >= ?
                   AND createdAt <= CONCAT(?, ' 23:59:59')",
                [$partnerID, $periodStart, $periodEnd],
                'iss'
            );

            // 🔍 Check if there are any transactions to remit
            $transactionCount = (int) ($aggregation['transactionCount'] ?? 0);

            if ($transactionCount === 0) {
                return [
                    'success' => false,
                    'message' => 'No unremitted transactions found for this partner in the specified period',
                ];
            }

            $netTotal = round((float) ($aggregation['netTotal'] ?? 0), 2);
            $grossTotal = round((float) ($aggregation['grossTotal'] ?? 0), 2);
            $feesTotal = round((float) ($aggregation['feesTotal'] ?? 0), 2);
            $currency = $aggregation['currency'] ?? self::DEFAULT_CURRENCY;

            // 💰 Check minimum remittance amount
            $minRemittanceAmount = (float) self::getSettingValue('payment.remittance.min_amount', '50.00');
            if ($netTotal < $minRemittanceAmount) {
                return [
                    'success' => false,
                    'message' => 'Net amount (' . $currency . ' ' . number_format($netTotal, 2)
                        . ') is below the minimum remittance threshold of '
                        . $currency . ' ' . number_format($minRemittanceAmount, 2),
                ];
            }

            // 📋 Determine payout method and email
            $payoutMethod = $options['payoutMethod'] ?? $partner['payoutMethod'] ?? 'manual';
            $payoutEmail = $options['payoutEmail'] ?? $partner['payoutEmail'] ?? $partner['billingEmail'] ?? null;
            $notes = $options['notes'] ?? null;

            // 💾 Insert remittance record into tblRemittances
            // Column names match migration 012 schema:
            //   method         = payout delivery method ENUM
            //   paypalEmail    = recipient email for PayPal payouts
            //   grossTotal     = total gross before fees
            //   feesTotal      = total fees deducted
            Database::query(
                "INSERT INTO tblRemittances
                    (partnerID, amount, currency, method, paypalEmail,
                     status, periodStart, periodEnd, transactionCount,
                     grossTotal, feesTotal, metadata, createdAt)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $partnerID,
                    $netTotal,
                    $currency,
                    $payoutMethod,
                    ($payoutMethod === 'paypal_payout') ? $payoutEmail : null,
                    $periodStart,
                    $periodEnd,
                    $transactionCount,
                    $grossTotal,
                    $feesTotal,
                    $notes ? json_encode(['notes' => $notes]) : null,
                ],
                'idsssssidds'
            );

            $remittanceID = Database::getLastInsertId();

            // 🔗 Link all eligible fee transactions to this remittance
            // Update their remittanceReference to track which remittance covers them
            Database::query(
                "UPDATE tblServiceFeeTransactions
                 SET remittanceReference = ?
                 WHERE partnerID = ?
                   AND status IN ('pending', 'collected')
                   AND remittanceReference IS NULL
                   AND createdAt >= ?
                   AND createdAt <= CONCAT(?, ' 23:59:59')",
                [(string) $remittanceID, $partnerID, $periodStart, $periodEnd],
                'siss'
            );

            // 📝 Log activity
            ActivityLogger::log(
                null,
                'remittance_created',
                'payment',
                'info',
                'Remittance created for partner "' . $partner['partnerName'] . '": '
                    . $currency . ' ' . number_format($netTotal, 2)
                    . ' (' . $transactionCount . ' transactions, '
                    . $periodStart . ' to ' . $periodEnd . ')',
                [
                    'remittanceID'     => $remittanceID,
                    'partnerID'        => $partnerID,
                    'partnerName'      => $partner['partnerName'],
                    'amount'           => $netTotal,
                    'grossTotal'       => $grossTotal,
                    'feesTotal'        => $feesTotal,
                    'currency'         => $currency,
                    'transactionCount' => $transactionCount,
                    'periodStart'      => $periodStart,
                    'periodEnd'        => $periodEnd,
                    'payoutMethod'     => $payoutMethod,
                ]
            );

            return [
                'success'          => true,
                'remittanceID'     => $remittanceID,
                'amount'           => $netTotal,
                'transactionCount' => $transactionCount,
            ];
        } catch (\Exception $e) {
            // ❌ Remittance creation failed
            ActivityLogger::log(
                null,
                'remittance_error',
                'payment',
                'error',
                'Failed to create remittance: ' . $e->getMessage(),
                [
                    'partnerID'   => $partnerID,
                    'periodStart' => $periodStart,
                    'periodEnd'   => $periodEnd,
                ]
            );

            return [
                'success' => false,
                'message' => 'Failed to create remittance',
            ];
        }
    }

    /**
     * ✅ Process a pending remittance (initiate partner payout)
     *
     * Transitions the remittance from 'pending' to 'processing', then based on
     * the payout method:
     *   - credit_to_balance: Credits the partner's SIGNula credit balance via CreditManager
     *   - paypal_payout / bank_transfer / manual: Marks as 'processing' (actual payout
     *     handled externally by finance team or automated webhook)
     *
     * After processing, updates all linked fee transactions to 'remitted' status
     * and sends a 'remittance_processed' notification email to the partner.
     *
     * @param int $remittanceID The remittance ID to process
     * @param int $processedBy  The admin userID processing this remittance
     *
     * @return array Associative array:
     *   - 'success' (bool)   Whether processing was initiated
     *   - 'message' (string) Status message
     *
     * @see CreditManager::deposit() For credit_to_balance payouts
     * @see tblEmailTemplates key: 'remittance_processed'
     */
    public static function processRemittance(int $remittanceID, int $processedBy): array
    {
        try {
            // 🔍 Fetch the remittance record
            $remittance = Database::fetchOne(
                "SELECT r.*, p.partnerName, p.billingEmail, p.payoutEmail
                 FROM tblRemittances r
                 JOIN tblPartners p ON r.partnerID = p.partnerID
                 WHERE r.remittanceID = ?",
                [$remittanceID],
                'i'
            );

            if (!$remittance) {
                return ['success' => false, 'message' => 'Remittance not found'];
            }

            // 🚫 Only process remittances that are in 'pending' status
            if ($remittance['status'] !== 'pending') {
                return [
                    'success' => false,
                    'message' => 'Remittance is not in pending status (current: ' . $remittance['status'] . ')',
                ];
            }

            // 🔄 Transition remittance to 'processing'
            Database::query(
                "UPDATE tblRemittances
                 SET status = 'processing', processedBy = ?, updatedAt = NOW()
                 WHERE remittanceID = ?",
                [$processedBy, $remittanceID],
                'ii'
            );

            $payoutSuccess = false;

            // 💰 Handle payout based on the method
            switch ($remittance['method']) {
                case 'credit_to_balance':
                    // 💳 Credit the partner's SIGNula balance directly
                    // CreditManager handles the balance update and audit trail
                    if (class_exists('CreditManager')) {
                        $creditResult = CreditManager::deposit(
                            'partner',
                            (int) $remittance['partnerID'],
                            (float) $remittance['amount'],
                            'Remittance payout #' . $remittanceID
                                . ' (period: ' . $remittance['periodStart']
                                . ' to ' . $remittance['periodEnd'] . ')',
                            'remittance',
                            $remittanceID,
                            $processedBy
                        );
                        $payoutSuccess = $creditResult['success'] ?? false;
                    } else {
                        // ⚠️ CreditManager not available — mark as failed
                        Database::query(
                            "UPDATE tblRemittances
                             SET status = 'failed', failureReason = 'CreditManager class not available', updatedAt = NOW()
                             WHERE remittanceID = ?",
                            [$remittanceID],
                            'i'
                        );

                        return [
                            'success' => false,
                            'message' => 'Credit balance payout failed: CreditManager not available',
                        ];
                    }
                    break;

                case 'paypal_payout':
                case 'bank_transfer':
                case 'manual':
                default:
                    // 📤 External payout methods — mark as processing
                    // Actual payout is handled externally by finance team or via
                    // PayPal Payouts API / bank transfer initiated outside this system.
                    // The admin will manually mark as 'completed' after confirming payout.
                    $payoutSuccess = true;
                    break;
            }

            if ($payoutSuccess) {
                // ✅ Mark remittance as completed (for credit_to_balance)
                // For external methods, it stays as 'processing' until manually confirmed
                $newStatus = ($remittance['method'] === 'credit_to_balance') ? 'completed' : 'processing';

                Database::query(
                    "UPDATE tblRemittances
                     SET status = ?, processedAt = NOW(), processedBy = ?, updatedAt = NOW()
                     WHERE remittanceID = ?",
                    [$newStatus, $processedBy, $remittanceID],
                    'sii'
                );

                // 🔄 Update all linked fee transactions to 'remitted' status
                Database::query(
                    "UPDATE tblServiceFeeTransactions
                     SET status = 'remitted', remittedAt = NOW(), remittanceMethod = ?
                     WHERE remittanceReference = ?",
                    [$remittance['method'], (string) $remittanceID],
                    'ss'
                );

                // 📧 Send remittance processed notification to the partner
                $partnerEmail = $remittance['payoutEmail'] ?? $remittance['billingEmail'];
                if ($partnerEmail) {
                    EmailService::sendTemplateEmail(
                        $partnerEmail,
                        'remittance_processed',
                        [
                            'partnerName'      => $remittance['partnerName'],
                            'currency'         => $remittance['currency'],
                            'amount'           => number_format((float) $remittance['amount'], 2),
                            'periodStart'      => date('j F Y', strtotime($remittance['periodStart'])),
                            'periodEnd'        => date('j F Y', strtotime($remittance['periodEnd'])),
                            'transactionCount' => (string) $remittance['transactionCount'],
                            'grossTotal'       => number_format((float) $remittance['grossTotal'], 2),
                            'feesTotal'        => number_format((float) $remittance['feesTotal'], 2),
                            'earningsURL'      => getSetting('app.base_url', 'https://signula.id')
                                . '/partners/admin/earnings',
                        ],
                        null, // userID
                        3     // priority (high)
                    );
                }

                // 📝 Log activity
                ActivityLogger::log(
                    $processedBy,
                    'remittance_processed',
                    'payment',
                    'info',
                    'Remittance #' . $remittanceID . ' processed for partner "'
                        . $remittance['partnerName'] . '": '
                        . $remittance['currency'] . ' ' . number_format((float) $remittance['amount'], 2)
                        . ' via ' . $remittance['method'],
                    [
                        'remittanceID' => $remittanceID,
                        'partnerID'    => (int) $remittance['partnerID'],
                        'amount'       => (float) $remittance['amount'],
                        'method'       => $remittance['method'],
                        'status'       => $newStatus,
                    ]
                );

                // 🔗 Dispatch webhook event
                if (class_exists('WebhookManager')) {
                    WebhookManager::dispatch('remittance.processed', [
                        'remittanceID' => $remittanceID,
                        'partnerID'    => (int) $remittance['partnerID'],
                        'amount'       => (float) $remittance['amount'],
                        'currency'     => $remittance['currency'],
                        'method'       => $remittance['method'],
                        'status'       => $newStatus,
                    ]);
                }

                return ['success' => true, 'message' => 'Remittance processed successfully'];
            }

            return ['success' => false, 'message' => 'Payout processing failed'];
        } catch (\Exception $e) {
            // ❌ Processing failed — mark remittance as failed
            Database::query(
                "UPDATE tblRemittances
                 SET status = 'failed', failureReason = ?, updatedAt = NOW()
                 WHERE remittanceID = ?",
                [substr($e->getMessage(), 0, 500), $remittanceID],
                'si'
            );

            ActivityLogger::log(
                $processedBy,
                'remittance_error',
                'payment',
                'error',
                'Failed to process remittance #' . $remittanceID . ': ' . $e->getMessage(),
                ['remittanceID' => $remittanceID]
            );

            return ['success' => false, 'message' => 'Failed to process remittance'];
        }
    }


    // ========================================================================
    // 📊 PARTNER TRANSACTION & REMITTANCE HISTORY
    // ========================================================================

    /**
     * 📋 Get paginated fee transaction history for a partner
     *
     * Returns fee transaction records with optional filtering by status,
     * date range, and remittance reference.
     *
     * @param int   $partnerID The partner ID to query
     * @param int   $limit     Maximum records per page (default: 25)
     * @param int   $offset    Pagination offset (default: 0)
     * @param array $filters   Optional filters:
     *   - 'status'        (string): Filter by transaction status
     *   - 'dateFrom'      (string): Start date filter (Y-m-d)
     *   - 'dateTo'        (string): End date filter (Y-m-d)
     *   - 'remittanceID'  (int):    Filter by remittance reference
     *
     * @return array Associative array:
     *   - 'transactions' (array) List of fee transaction records
     *   - 'total'        (int)   Total matching records (for pagination)
     *   - 'limit'        (int)   Records per page
     *   - 'offset'       (int)   Current offset
     */
    public static function getPartnerFeeTransactions(
        int $partnerID,
        int $limit = 25,
        int $offset = 0,
        array $filters = []
    ): array {
        // 🏗️ Build dynamic WHERE clause based on provided filters
        $conditions = ['sft.partnerID = ?'];
        $params = [$partnerID];
        $types = 'i';

        // 🔍 Filter by transaction status
        if (!empty($filters['status'])) {
            $conditions[] = 'sft.status = ?';
            $params[] = $filters['status'];
            $types .= 's';
        }

        // 📅 Filter by date range (start)
        if (!empty($filters['dateFrom'])) {
            $conditions[] = 'sft.createdAt >= ?';
            $params[] = $filters['dateFrom'];
            $types .= 's';
        }

        // 📅 Filter by date range (end)
        if (!empty($filters['dateTo'])) {
            $conditions[] = "sft.createdAt <= CONCAT(?, ' 23:59:59')";
            $params[] = $filters['dateTo'];
            $types .= 's';
        }

        // 🔗 Filter by remittance reference
        if (!empty($filters['remittanceID'])) {
            $conditions[] = 'sft.remittanceReference = ?';
            $params[] = (string) $filters['remittanceID'];
            $types .= 's';
        }

        $whereClause = implode(' AND ', $conditions);

        // 📊 Get total count for pagination
        $countResult = Database::fetchOne(
            "SELECT COUNT(*) AS total
             FROM tblServiceFeeTransactions sft
             WHERE " . $whereClause,
            $params,
            $types
        );
        $total = (int) ($countResult['total'] ?? 0);

        // 📋 Fetch paginated fee transactions with fee schedule details
        $paginatedParams = array_merge($params, [$limit, $offset]);
        $paginatedTypes = $types . 'ii';

        $transactions = Database::fetchAll(
            "SELECT sft.*, sf.feeType, sf.feePercentage AS schedulePercentage
             FROM tblServiceFeeTransactions sft
             LEFT JOIN tblServiceFees sf ON sft.feeID = sf.feeID
             WHERE " . $whereClause . "
             ORDER BY sft.createdAt DESC
             LIMIT ? OFFSET ?",
            $paginatedParams,
            $paginatedTypes
        );

        return [
            'transactions' => $transactions,
            'total'        => $total,
            'limit'        => $limit,
            'offset'       => $offset,
        ];
    }

    /**
     * 💸 Get paginated remittance history for a partner
     *
     * Returns remittance records ordered by creation date descending.
     *
     * @param int $partnerID The partner ID to query
     * @param int $limit     Maximum records per page (default: 25)
     * @param int $offset    Pagination offset (default: 0)
     *
     * @return array Associative array:
     *   - 'remittances' (array) List of remittance records
     *   - 'total'       (int)   Total remittance records (for pagination)
     *   - 'limit'       (int)   Records per page
     *   - 'offset'      (int)   Current offset
     */
    public static function getPartnerRemittances(
        int $partnerID,
        int $limit = 25,
        int $offset = 0
    ): array {
        // 📊 Get total count for pagination
        $countResult = Database::fetchOne(
            "SELECT COUNT(*) AS total
             FROM tblRemittances
             WHERE partnerID = ?",
            [$partnerID],
            'i'
        );
        $total = (int) ($countResult['total'] ?? 0);

        // 📋 Fetch paginated remittance records
        $remittances = Database::fetchAll(
            "SELECT *
             FROM tblRemittances
             WHERE partnerID = ?
             ORDER BY createdAt DESC
             LIMIT ? OFFSET ?",
            [$partnerID, $limit, $offset],
            'iii'
        );

        return [
            'remittances' => $remittances,
            'total'       => $total,
            'limit'       => $limit,
            'offset'      => $offset,
        ];
    }

    /**
     * ⏳ Get all pending remittances, optionally filtered by partner
     *
     * Useful for the admin dashboard to show remittances awaiting processing.
     *
     * @param int|null $partnerID Optional: filter to a specific partner
     *
     * @return array List of pending remittance records with partner details
     */
    public static function getPendingRemittances(?int $partnerID = null): array
    {
        // 🏗️ Build query with optional partner filter
        if ($partnerID !== null) {
            return Database::fetchAll(
                "SELECT r.*, p.partnerName, p.billingEmail, p.payoutEmail, p.payoutMethod AS partnerPayoutMethod
                 FROM tblRemittances r
                 JOIN tblPartners p ON r.partnerID = p.partnerID
                 WHERE r.status = 'pending'
                   AND r.partnerID = ?
                 ORDER BY r.createdAt ASC",
                [$partnerID],
                'i'
            );
        }

        // 📋 No partner filter — return all pending remittances
        return Database::fetchAll(
            "SELECT r.*, p.partnerName, p.billingEmail, p.payoutEmail, p.payoutMethod AS partnerPayoutMethod
             FROM tblRemittances r
             JOIN tblPartners p ON r.partnerID = p.partnerID
             WHERE r.status = 'pending'
             ORDER BY r.createdAt ASC",
            [],
            ''
        );
    }


    // ========================================================================
    // 📈 REPORTING & ANALYTICS
    // ========================================================================

    /**
     * 📊 Generate an earnings report for a partner over a specified period
     *
     * Aggregates gross amounts, fees, and net amounts for the partner with
     * a month-by-month breakdown. Useful for partner earnings dashboards
     * and financial reconciliation.
     *
     * @param int    $partnerID   The partner ID to report on
     * @param string $periodStart Start of the reporting period (Y-m-d)
     * @param string $periodEnd   End of the reporting period (Y-m-d)
     *
     * @return array Associative array:
     *   - 'totalGross'       (float) Total gross payment amount
     *   - 'totalFees'        (float) Total fees deducted
     *   - 'totalNet'         (float) Total net amount (gross - fees)
     *   - 'transactionCount' (int)   Total number of fee transactions
     *   - 'averageFeeRate'   (float) Weighted average fee percentage
     *   - 'currency'         (string) Currency of the report
     *   - 'periodStart'      (string) Report period start date
     *   - 'periodEnd'        (string) Report period end date
     *   - 'monthlyBreakdown' (array)  Per-month aggregation with keys:
     *       - 'month'            (string) Month in Y-m format
     *       - 'grossAmount'      (float)  Gross for the month
     *       - 'feeAmount'        (float)  Fees for the month
     *       - 'netAmount'        (float)  Net for the month
     *       - 'transactionCount' (int)    Transactions in the month
     */
    public static function getEarningsReport(
        int $partnerID,
        string $periodStart,
        string $periodEnd
    ): array {
        // 📊 Fetch overall totals for the period
        $totals = Database::fetchOne(
            "SELECT
                COUNT(*) AS transactionCount,
                COALESCE(SUM(grossAmount), 0) AS totalGross,
                COALESCE(SUM(feeAmount), 0) AS totalFees,
                COALESCE(SUM(netToPartner), 0) AS totalNet,
                MIN(currency) AS currency
             FROM tblServiceFeeTransactions
             WHERE partnerID = ?
               AND createdAt >= ?
               AND createdAt <= CONCAT(?, ' 23:59:59')",
            [$partnerID, $periodStart, $periodEnd],
            'iss'
        );

        $totalGross = round((float) ($totals['totalGross'] ?? 0), 2);
        $totalFees = round((float) ($totals['totalFees'] ?? 0), 2);
        $totalNet = round((float) ($totals['totalNet'] ?? 0), 2);
        $transactionCount = (int) ($totals['transactionCount'] ?? 0);
        $currency = $totals['currency'] ?? self::DEFAULT_CURRENCY;

        // 🧮 Calculate weighted average fee rate
        // Avoids division by zero when there are no transactions
        $averageFeeRate = ($totalGross > 0)
            ? round(($totalFees / $totalGross) * 100, 2)
            : 0.00;

        // 📅 Fetch monthly breakdown using DATE_FORMAT for grouping
        // @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html#function_date-format
        $monthlyBreakdown = Database::fetchAll(
            "SELECT
                DATE_FORMAT(createdAt, '%Y-%m') AS month,
                COUNT(*) AS transactionCount,
                COALESCE(SUM(grossAmount), 0) AS grossAmount,
                COALESCE(SUM(feeAmount), 0) AS feeAmount,
                COALESCE(SUM(netToPartner), 0) AS netAmount
             FROM tblServiceFeeTransactions
             WHERE partnerID = ?
               AND createdAt >= ?
               AND createdAt <= CONCAT(?, ' 23:59:59')
             GROUP BY DATE_FORMAT(createdAt, '%Y-%m')
             ORDER BY month ASC",
            [$partnerID, $periodStart, $periodEnd],
            'iss'
        );

        // 🔢 Ensure numeric types in the monthly breakdown
        foreach ($monthlyBreakdown as &$month) {
            $month['grossAmount'] = round((float) $month['grossAmount'], 2);
            $month['feeAmount'] = round((float) $month['feeAmount'], 2);
            $month['netAmount'] = round((float) $month['netAmount'], 2);
            $month['transactionCount'] = (int) $month['transactionCount'];
        }
        unset($month); // Break the reference after foreach

        return [
            'totalGross'       => $totalGross,
            'totalFees'        => $totalFees,
            'totalNet'         => $totalNet,
            'transactionCount' => $transactionCount,
            'averageFeeRate'   => $averageFeeRate,
            'currency'         => $currency,
            'periodStart'      => $periodStart,
            'periodEnd'        => $periodEnd,
            'monthlyBreakdown' => $monthlyBreakdown,
        ];
    }

    /**
     * 📊 Get administrative statistics for the service fee system
     *
     * Provides a high-level overview of fee collection and remittance activity
     * for the admin dashboard. Covers the last N days (default: 30).
     *
     * @param int $days Number of days to look back (default: 30)
     *
     * @return array Associative array:
     *   - 'totalFeesCollected'    (float) Total fee amount in pending/collected transactions
     *   - 'totalFeesRemitted'     (float) Total fee amount in remitted transactions
     *   - 'totalNetToPartners'    (float) Total net amount paid/owed to partners
     *   - 'feeTransactionCount'   (int)   Number of fee transactions
     *   - 'pendingRemittanceCount' (int)  Number of pending remittances
     *   - 'pendingRemittanceTotal' (float) Total amount in pending remittances
     *   - 'completedRemittanceCount' (int) Number of completed remittances
     *   - 'completedRemittanceTotal' (float) Total amount of completed remittances
     *   - 'activePartnerCount'    (int)   Number of partners with fee transactions
     *   - 'averageFeeRate'        (float) Weighted average fee percentage
     *   - 'periodDays'            (int)   The lookback period in days
     */
    public static function getAdminStats(int $days = 30): array
    {
        // 📊 Fee transaction statistics
        $feeStats = Database::fetchOne(
            "SELECT
                COUNT(*) AS feeTransactionCount,
                COALESCE(SUM(feeAmount), 0) AS totalFeesCollected,
                COALESCE(SUM(CASE WHEN status = 'remitted' THEN feeAmount ELSE 0 END), 0) AS totalFeesRemitted,
                COALESCE(SUM(netToPartner), 0) AS totalNetToPartners,
                COALESCE(SUM(grossAmount), 0) AS totalGross,
                COUNT(DISTINCT partnerID) AS activePartnerCount
             FROM tblServiceFeeTransactions
             WHERE createdAt >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days],
            'i'
        );

        // 💸 Pending remittance statistics
        $pendingRemittances = Database::fetchOne(
            "SELECT
                COUNT(*) AS pendingCount,
                COALESCE(SUM(amount), 0) AS pendingTotal
             FROM tblRemittances
             WHERE status = 'pending'
               AND createdAt >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days],
            'i'
        );

        // ✅ Completed remittance statistics
        $completedRemittances = Database::fetchOne(
            "SELECT
                COUNT(*) AS completedCount,
                COALESCE(SUM(amount), 0) AS completedTotal
             FROM tblRemittances
             WHERE status = 'completed'
               AND processedAt >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days],
            'i'
        );

        // 🧮 Calculate weighted average fee rate
        $totalGross = (float) ($feeStats['totalGross'] ?? 0);
        $totalFeesCollected = (float) ($feeStats['totalFeesCollected'] ?? 0);
        $averageFeeRate = ($totalGross > 0)
            ? round(($totalFeesCollected / $totalGross) * 100, 2)
            : 0.00;

        return [
            'totalFeesCollected'       => round($totalFeesCollected, 2),
            'totalFeesRemitted'        => round((float) ($feeStats['totalFeesRemitted'] ?? 0), 2),
            'totalNetToPartners'       => round((float) ($feeStats['totalNetToPartners'] ?? 0), 2),
            'feeTransactionCount'      => (int) ($feeStats['feeTransactionCount'] ?? 0),
            'pendingRemittanceCount'   => (int) ($pendingRemittances['pendingCount'] ?? 0),
            'pendingRemittanceTotal'   => round((float) ($pendingRemittances['pendingTotal'] ?? 0), 2),
            'completedRemittanceCount' => (int) ($completedRemittances['completedCount'] ?? 0),
            'completedRemittanceTotal' => round((float) ($completedRemittances['completedTotal'] ?? 0), 2),
            'activePartnerCount'       => (int) ($feeStats['activePartnerCount'] ?? 0),
            'averageFeeRate'           => $averageFeeRate,
            'periodDays'               => $days,
        ];
    }


    // ========================================================================
    // 🔧 PRIVATE HELPER METHODS
    // ========================================================================

    /**
     * 🔄 Normalize fee type string to match tblServiceFees ENUM values
     *
     * Accepts both the full ENUM values ('partner_own_keys', 'partner_signula_keys')
     * and shorthand forms ('own_keys', 'signula_keys') from the user's specification.
     *
     * @param string $feeType The fee type string to normalize
     *
     * @return string Normalized fee type matching the ENUM values in tblServiceFees
     *
     * @throws \InvalidArgumentException If the fee type is not recognized
     */
    private static function normalizeFeeType(string $feeType): string
    {
        // 📋 Map of accepted inputs to canonical ENUM values
        $typeMap = [
            // Full ENUM values (already normalized)
            'partner_own_keys'     => self::FEE_TYPE_OWN_KEYS,
            'partner_signula_keys' => self::FEE_TYPE_SIGNULA_KEYS,
            // Shorthand forms (from user specification)
            'own_keys'             => self::FEE_TYPE_OWN_KEYS,
            'signula_keys'         => self::FEE_TYPE_SIGNULA_KEYS,
        ];

        $normalized = $typeMap[strtolower(trim($feeType))] ?? null;

        if ($normalized === null) {
            throw new \InvalidArgumentException(
                'Invalid fee type: "' . $feeType . '". '
                . 'Expected one of: partner_own_keys, partner_signula_keys, own_keys, signula_keys'
            );
        }

        return $normalized;
    }

    /**
     * 🔍 Check for a partner-specific fee override
     *
     * Partners may have custom/negotiated fee rates stored in the metadata
     * JSON column of tblPartnerPaymentConfig. This method checks if such
     * an override exists for the given partner and fee type.
     *
     * Expected metadata JSON structure:
     *   {"customFeePercentage": 8.50, "customFixedFee": 0.25}
     *
     * @param int    $partnerID The partner ID to check
     * @param string $feeType   Normalized fee type
     *
     * @return array|null Associative array with 'feePercentage' and optional 'fixedFeeAmount',
     *                    or null if no override exists
     *
     * @see tblPartnerPaymentConfig.metadata JSON column
     */
    private static function getPartnerFeeOverride(int $partnerID, string $feeType): ?array
    {
        // 🔍 Determine the provider filter based on fee type
        // partner_own_keys implies the partner has their own provider config
        // partner_signula_keys implies they use SIGNula's keys (usesOwnKeys = 0)
        $usesOwnKeys = ($feeType === self::FEE_TYPE_OWN_KEYS) ? 1 : 0;

        // 📊 Query tblPartnerPaymentConfig for custom fee metadata
        $config = Database::fetchOne(
            "SELECT metadata
             FROM tblPartnerPaymentConfig
             WHERE partnerID = ?
               AND usesOwnKeys = ?
               AND isEnabled = 1
             LIMIT 1",
            [$partnerID, $usesOwnKeys],
            'ii'
        );

        if (!$config || empty($config['metadata'])) {
            return null;
        }

        // 📋 Parse the metadata JSON
        // @see https://www.php.net/manual/en/function.json-decode.php
        $metadata = json_decode($config['metadata'], true);

        if (!$metadata || !isset($metadata['customFeePercentage'])) {
            return null;
        }

        // ✅ Return the override values
        return [
            'feePercentage'  => (float) $metadata['customFeePercentage'],
            'fixedFeeAmount' => (float) ($metadata['customFixedFee'] ?? 0),
        ];
    }

    /**
     * ⚙️ Get a setting value from tblSettings
     *
     * Queries the database directly for a specific setting key. This is a private
     * helper to avoid dependency on the global getSetting() function being available
     * in all contexts (e.g. when called from CLI or cron).
     *
     * @param string $key     The setting key to look up (e.g. 'payment.remittance.min_amount')
     * @param mixed  $default Default value if setting not found
     *
     * @return string The setting value, or the default cast to string
     *
     * @see tblSettings table for available keys
     * @see https://www.php.net/manual/en/class.mysqli.php
     */
    private static function getSettingValue(string $key, mixed $default = ''): string
    {
        $setting = Database::fetchOne(
            "SELECT settingValue FROM tblSettings WHERE settingKey = ?",
            [$key],
            's'
        );

        return $setting ? $setting['settingValue'] : (string) $default;
    }
}

// ✅ ServiceFeeManager loaded successfully
