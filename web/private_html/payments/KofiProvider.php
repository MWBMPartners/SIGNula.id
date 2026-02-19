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
 * ☕ SIGNula - Ko-fi Payment Provider
 * ============================================================================
 *
 * Purpose: Integrates Ko-fi webhook-based payment processing within the
 *          SIGNula Universal SSO platform. Ko-fi is a donation/support
 *          platform that uses a WEBHOOK-ONLY flow — there is no charge
 *          creation API. Users pay on the Ko-fi hosted page and Ko-fi
 *          sends a POST webhook to SIGNula upon payment completion.
 *
 * PHP Version: 8.3+
 *
 * Payment Flow:
 * 1. User clicks a Ko-fi link (configured page URL) on the SIGNula site
 * 2. User completes payment on Ko-fi's hosted page (donation, subscription, or shop order)
 * 3. Ko-fi POSTs a webhook to the configured SIGNula webhook endpoint
 * 4. SIGNula verifies the webhook using the verification_token field
 * 5. SIGNula processes the event (records payment, activates features, etc.)
 *
 * Features:
 * - Webhook verification via verification_token (NOT HMAC signature)
 * - Handles Donation, Subscription, and Shop Order event types
 * - Optional user matching by email (Ko-fi donors may not have SIGNula accounts)
 * - Full activity logging for all webhook events and payment processing
 * - Secure token handling via encrypted database storage
 * - Connection/configuration testing for admin verification
 *
 * Ko-fi Webhook Details:
 * - Ko-fi POSTs with a `data` field containing a JSON string
 * - The JSON string must be decoded to access event details
 * - A `verification_token` field in the POST body is used for authenticity verification
 * - Event types: Donation, Subscription, Shop Order
 *
 * @see https://ko-fi.com/manage/webhooks
 * @see https://help.ko-fi.com/hc/en-us/articles/15423608449425-Webhooks-FAQ
 * @see https://help.ko-fi.com/hc/en-us/articles/16282397585553-How-to-Set-Up-Webhooks
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.0.0
 * @since      2.4.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access - this file must be included via the SIGNula bootstrap
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 📦 Require the core PaymentManager class for payment recording and management
// PaymentManager handles tblPayments CRUD, subscription management, and invoice generation
$paymentManagerPath = __DIR__ . DIRECTORY_SEPARATOR . 'PaymentManager.php';
if (file_exists($paymentManagerPath)) {
    require_once $paymentManagerPath;
} else {
    // ⚠️ PaymentManager is a critical dependency - log error if missing
    error_log('[SIGNula] KofiProvider: PaymentManager.php not found at: ' . $paymentManagerPath);
}

/**
 * ☕ Ko-fi Payment Provider
 *
 * Provides static methods for processing Ko-fi webhook events and managing
 * Ko-fi payment integration. All methods are static following the SIGNula
 * project pattern (no instantiation required).
 *
 * Ko-fi uses a webhook-only flow — there is no API to create charges.
 * Instead, users are directed to a Ko-fi page URL where they complete
 * payment, and Ko-fi sends a webhook POST to notify SIGNula.
 *
 * Settings are stored in tblSettings and retrieved via getSetting().
 * Sensitive values (verification tokens) are encrypted in the database
 * and decrypted at runtime via SecurityUtils::decrypt().
 *
 * @see https://ko-fi.com/manage/webhooks
 * @see https://help.ko-fi.com/hc/en-us/articles/15423608449425-Webhooks-FAQ
 */
class KofiProvider
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /**
     * @var string Payment provider identifier used in tblPayments records
     * This value is stored in the paymentProvider column to identify
     * payments processed via Ko-fi.
     */
    private const PROVIDER_NAME = 'kofi';

    /**
     * @var string Default payment method label for Ko-fi payments
     * Ko-fi handles the payment method internally (card, PayPal, etc.),
     * so from SIGNula's perspective the method is simply 'kofi'.
     */
    private const DEFAULT_PAYMENT_METHOD = 'kofi';

    /**
     * @var array Recognised Ko-fi webhook event types
     * Ko-fi sends one of these values in the 'type' field of the decoded
     * webhook data payload.
     * @see https://ko-fi.com/manage/webhooks
     */
    private const VALID_EVENT_TYPES = [
        'Donation',
        'Subscription',
        'Shop Order',
    ];

    // ========================================================================
    // 🔒 PRIVATE HELPERS
    // ========================================================================

    /**
     * ⚙️ Get a setting value from tblSettings
     *
     * Convenience wrapper that queries tblSettings directly using the
     * Database class. This provides a consistent pattern with other
     * payment-related classes (PaymentManager, CoinbaseProvider, WebhookManager).
     *
     * @param string $key     Setting key (e.g., 'payment.kofi.enabled')
     * @param mixed  $default Default value if setting not found
     *
     * @return string Setting value from the database, or default
     *
     * @see Database::fetchOne()
     */
    private static function getSettingValue(string $key, mixed $default = ''): string
    {
        $setting = Database::fetchOne(
            "SELECT settingValue FROM tblSettings WHERE settingKey = ?",
            [$key],
            's'
        );

        return $setting ? $setting['settingValue'] : (string)$default;
    }

    // ========================================================================
    // ✅ STATUS & CONFIGURATION METHODS
    // ========================================================================

    /**
     * 🔌 Check if the Ko-fi payment provider is enabled
     *
     * Verifies two conditions:
     * 1. The 'payment.kofi.enabled' setting is set to '1' in tblSettings
     * 2. A non-empty verification token is configured in 'payment.kofi.verification_token'
     *
     * Both conditions must be true for Ko-fi payments to be available.
     * This method should be called before presenting Ko-fi payment links
     * to users or processing any Ko-fi webhook events.
     *
     * Unlike other payment providers (e.g., Coinbase, Stripe), Ko-fi does
     * not use an API key for charge creation — it only requires a
     * verification_token for webhook authenticity verification.
     *
     * @return bool True if Ko-fi payments are enabled and configured, false otherwise
     *
     * @see https://ko-fi.com/manage/webhooks
     *
     * @example
     * ```php
     * if (KofiProvider::isEnabled()) {
     *     // Show Ko-fi donation/support button to the user
     *     $kofiUrl = KofiProvider::getPageUrl();
     * }
     * ```
     */
    public static function isEnabled(): bool
    {
        // 🔍 Check the global enable/disable toggle for Ko-fi payments
        $enabled = getSetting('payment.kofi.enabled');
        if ($enabled !== '1') {
            return false;
        }

        // 🔑 Verify that a verification token is actually configured
        // Without a verification token, we cannot authenticate incoming webhooks,
        // so Ko-fi integration is effectively non-functional
        $verificationToken = getSetting('payment.kofi.verification_token');
        if (empty($verificationToken)) {
            return false;
        }

        return true;
    }

    /**
     * 🔗 Get the configured Ko-fi page URL for payment links
     *
     * Retrieves the Ko-fi page URL from tblSettings. This URL is where
     * users are directed to make donations, subscriptions, or shop purchases.
     * It is typically in the format: https://ko-fi.com/{username}
     *
     * The URL is used to generate payment links/buttons in the SIGNula UI
     * that direct users to the Ko-fi hosted payment page.
     *
     * @return string The configured Ko-fi page URL, or empty string if not configured
     *
     * @see https://ko-fi.com/manage/webhooks
     *
     * @example
     * ```php
     * $pageUrl = KofiProvider::getPageUrl();
     * if (!empty($pageUrl)) {
     *     echo '<a href="' . htmlspecialchars($pageUrl) . '" target="_blank">Support us on Ko-fi</a>';
     * }
     * ```
     */
    public static function getPageUrl(): string
    {
        // 📥 Retrieve the Ko-fi page URL from settings
        // This is the public-facing Ko-fi page where users complete payments
        // Example: https://ko-fi.com/signula or https://ko-fi.com/mwservices
        $pageUrl = getSetting('payment.kofi.page_url');

        // 🛡️ Return the URL or an empty string if not configured
        if (empty($pageUrl)) {
            return '';
        }

        return $pageUrl;
    }

    // ========================================================================
    // 🛡️ WEBHOOK VERIFICATION
    // ========================================================================

    /**
     * 🔒 Verify a Ko-fi webhook verification token
     *
     * Verifies the authenticity of an incoming Ko-fi webhook request by
     * comparing the verification_token field in the POST body against
     * the stored (encrypted) token in tblSettings.
     *
     * IMPORTANT: Ko-fi does NOT use HMAC signature verification like
     * Coinbase Commerce or Stripe. Instead, Ko-fi includes a
     * `verification_token` field directly in the POST body's JSON data.
     * This token must match the one configured in the Ko-fi webhook
     * settings dashboard.
     *
     * This MUST be called before processing any webhook event to prevent
     * forged/spoofed webhook attacks. If verification fails, the webhook
     * payload should be rejected and the event discarded.
     *
     * Verification process:
     * 1. Retrieve and decrypt the stored verification token from tblSettings
     * 2. Compare the provided token with the stored token using hash_equals()
     *    to prevent timing attacks
     *
     * @param array $data The raw POST body data (before decoding the 'data' JSON field).
     *                     Must contain a 'verification_token' key at the top level.
     *
     * @return bool True if the verification token is valid, false otherwise
     *
     * @see https://ko-fi.com/manage/webhooks
     * @see https://help.ko-fi.com/hc/en-us/articles/15423608449425-Webhooks-FAQ
     * @see https://www.php.net/manual/en/function.hash-equals.php
     *
     * @example
     * ```php
     * // Ko-fi sends POST data with 'data' as a JSON string and 'verification_token'
     * // The verification_token may be in the decoded 'data' JSON or at the top level
     * $postData = $_POST;
     *
     * if (!KofiProvider::verifyWebhookToken($postData)) {
     *     http_response_code(401);
     *     die('Invalid verification token');
     * }
     *
     * // Token verified - safe to process the event
     * $eventData = json_decode($postData['data'], true);
     * $result = KofiProvider::handleWebhookEvent($eventData);
     * ```
     */
    public static function verifyWebhookToken(array $data): bool
    {
        try {
            // 🔍 Extract the verification_token from the POST body
            // Ko-fi includes this at the top level of the POST data, and also
            // within the decoded 'data' JSON string
            // @see https://ko-fi.com/manage/webhooks
            $providedToken = $data['verification_token'] ?? '';

            // 🛡️ If the token was not found at the top level, attempt to extract
            // it from the decoded 'data' JSON field (Ko-fi may include it in either location)
            if (empty($providedToken) && !empty($data['data'])) {
                $decodedData = is_string($data['data']) ? json_decode($data['data'], true) : $data['data'];
                if (is_array($decodedData)) {
                    $providedToken = $decodedData['verification_token'] ?? '';
                }
            }

            // ❌ Reject requests with no verification token
            if (empty($providedToken)) {
                ActivityLogger::log(
                    null,
                    'kofi_webhook_invalid',
                    'payment',
                    'warning',
                    'Ko-fi webhook verification failed: no verification_token provided in POST body',
                    [
                        'hasData'    => !empty($data['data']),
                        'dataKeys'   => array_keys($data),
                        'remoteIP'   => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ]
                );
                return false;
            }

            // 🔑 Retrieve and decrypt the stored verification token from tblSettings
            // The token is stored encrypted (isSensitive=1) in tblSettings under
            // the key 'payment.kofi.verification_token'
            $encryptedStoredToken = getSetting('payment.kofi.verification_token');
            if (empty($encryptedStoredToken)) {
                // ❌ No stored token configured - cannot verify webhooks
                ActivityLogger::log(
                    null,
                    'kofi_webhook_error',
                    'payment',
                    'error',
                    'Ko-fi verification token is not configured in tblSettings (payment.kofi.verification_token)',
                    []
                );
                return false;
            }

            // 🔓 Decrypt the stored verification token
            // @see SecurityUtils::decrypt() for AES-256-CBC decryption details
            $storedToken = SecurityUtils::decrypt($encryptedStoredToken);
            if (empty($storedToken)) {
                // ❌ Decryption failed or returned empty - token may be corrupted
                ActivityLogger::log(
                    null,
                    'kofi_webhook_error',
                    'payment',
                    'error',
                    'Failed to decrypt Ko-fi verification token from tblSettings',
                    []
                );
                return false;
            }

            // 🔐 Use timing-safe comparison to prevent timing attacks
            // hash_equals() compares strings in constant time regardless of
            // where the first difference occurs, preventing attackers from
            // using response timing to guess the token character by character
            // @see https://www.php.net/manual/en/function.hash-equals.php
            $isValid = hash_equals($storedToken, $providedToken);

            // 📝 Log the verification result for auditing
            if (!$isValid) {
                ActivityLogger::log(
                    null,
                    'kofi_webhook_token_mismatch',
                    'payment',
                    'warning',
                    'Ko-fi webhook verification token mismatch - possible forged request',
                    [
                        'providedTokenPrefix' => mb_substr($providedToken, 0, 8) . '...',
                        'remoteIP'            => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'userAgent'           => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    ]
                );
            }

            return $isValid;

        } catch (\Exception $e) {
            // 🚨 Log any unexpected exceptions during verification
            ActivityLogger::log(
                null,
                'kofi_webhook_exception',
                'payment',
                'error',
                'Exception during Ko-fi webhook token verification: ' . $e->getMessage(),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );
            return false;
        }
    }

    // ========================================================================
    // 🔔 WEBHOOK EVENT HANDLING
    // ========================================================================

    /**
     * 🔔 Handle a verified Ko-fi webhook event
     *
     * Processes webhook events from Ko-fi to record payments and activate
     * features in the SIGNula database. This method should ONLY be called
     * AFTER the webhook token has been verified via verifyWebhookToken().
     *
     * Ko-fi webhook data structure (after decoding the 'data' JSON string):
     * - type: string - 'Donation', 'Subscription', or 'Shop Order'
     * - is_subscription_payment: bool - Whether this is a subscription payment
     * - is_first_subscription_payment: bool - Whether this is the first subscription payment
     * - amount: string - Payment amount (e.g., '5.00')
     * - currency: string - ISO 4217 currency code (e.g., 'USD', 'GBP')
     * - from_name: string - Donor/supporter display name on Ko-fi
     * - email: string - Donor/supporter email address
     * - message: string|null - Optional message from the supporter
     * - kofi_transaction_id: string - Unique Ko-fi transaction identifier
     * - timestamp: string - ISO 8601 timestamp of the transaction
     * - url: string - URL to the Ko-fi transaction page
     * - verification_token: string - Token for webhook authenticity (already verified)
     *
     * Supported event types:
     * - 'Donation': One-time donation/tip payment
     * - 'Subscription': Recurring subscription payment (monthly support)
     * - 'Shop Order': Ko-fi shop product purchase
     *
     * User matching is OPTIONAL — Ko-fi supporters may not have SIGNula accounts.
     * The method attempts to match by email but gracefully handles unmatched donations.
     *
     * @param array $eventData The decoded webhook event data (from the 'data' JSON field)
     *
     * @return array Result with ['success' => bool, 'message' => string, 'eventType' => string]
     *
     * @see https://ko-fi.com/manage/webhooks
     * @see https://help.ko-fi.com/hc/en-us/articles/15423608449425-Webhooks-FAQ
     *
     * @example
     * ```php
     * // In the webhook receiver endpoint:
     * $postData = $_POST;
     *
     * // Step 1: Verify the webhook token
     * if (!KofiProvider::verifyWebhookToken($postData)) {
     *     http_response_code(401);
     *     die('Invalid token');
     * }
     *
     * // Step 2: Decode the 'data' JSON field
     * $eventData = json_decode($postData['data'], true);
     *
     * // Step 3: Process the event
     * $result = KofiProvider::handleWebhookEvent($eventData);
     *
     * if ($result['success']) {
     *     http_response_code(200);
     *     echo json_encode($result);
     * } else {
     *     http_response_code(400);
     *     echo json_encode($result);
     * }
     * ```
     */
    public static function handleWebhookEvent(array $eventData): array
    {
        try {
            // 🔍 Extract core fields from the decoded Ko-fi webhook data
            // @see https://ko-fi.com/manage/webhooks for full field documentation
            $eventType              = $eventData['type'] ?? '';
            $isSubscription         = filter_var($eventData['is_subscription_payment'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $isFirstSubscription    = filter_var($eventData['is_first_subscription_payment'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $amount                 = $eventData['amount'] ?? '0.00';
            $currency               = $eventData['currency'] ?? 'USD';
            $fromName               = $eventData['from_name'] ?? 'Anonymous';
            $email                  = $eventData['email'] ?? '';
            $message                = $eventData['message'] ?? null;
            $kofiTransactionId      = $eventData['kofi_transaction_id'] ?? '';
            $timestamp              = $eventData['timestamp'] ?? '';
            $kofiUrl                = $eventData['url'] ?? '';

            // 🛡️ Validate that the event type is recognised
            if (empty($eventType) || !in_array($eventType, self::VALID_EVENT_TYPES, true)) {
                ActivityLogger::log(
                    null,
                    'kofi_webhook_unknown_type',
                    'payment',
                    'warning',
                    'Received unrecognised Ko-fi webhook event type: ' . $eventType,
                    [
                        'eventType'          => $eventType,
                        'kofiTransactionId'  => $kofiTransactionId,
                        'email'              => $email,
                    ]
                );

                return [
                    'success'   => false,
                    'message'   => 'Unrecognised Ko-fi event type: ' . $eventType,
                    'eventType' => $eventType,
                ];
            }

            // 🛡️ Validate that a transaction ID is present
            if (empty($kofiTransactionId)) {
                ActivityLogger::log(
                    null,
                    'kofi_webhook_missing_txn',
                    'payment',
                    'warning',
                    'Ko-fi webhook event missing kofi_transaction_id',
                    [
                        'eventType' => $eventType,
                        'email'     => $email,
                        'fromName'  => $fromName,
                    ]
                );

                return [
                    'success'   => false,
                    'message'   => 'Ko-fi webhook event missing transaction ID',
                    'eventType' => $eventType,
                ];
            }

            // 🔍 Check for duplicate webhook delivery (idempotency)
            // Ko-fi may retry webhooks, so we check if this transaction has already been processed
            $existingPayment = Database::fetchOne(
                "SELECT paymentID, status FROM tblPayments WHERE transactionID = ? AND paymentProvider = ? LIMIT 1",
                [$kofiTransactionId, self::PROVIDER_NAME],
                'ss'
            );

            if ($existingPayment) {
                // ℹ️ This transaction has already been recorded - return success to acknowledge
                ActivityLogger::log(
                    null,
                    'kofi_webhook_duplicate',
                    'payment',
                    'info',
                    'Ko-fi webhook duplicate delivery detected for transaction: ' . $kofiTransactionId,
                    [
                        'kofiTransactionId' => $kofiTransactionId,
                        'existingPaymentID' => $existingPayment['paymentID'],
                        'existingStatus'    => $existingPayment['status'],
                    ]
                );

                return [
                    'success'   => true,
                    'message'   => 'Transaction already processed (duplicate webhook delivery)',
                    'eventType' => $eventType,
                    'paymentID' => (int)$existingPayment['paymentID'],
                ];
            }

            // 👤 Attempt to match the Ko-fi supporter to a SIGNula user by email
            // Ko-fi donors may NOT have SIGNula accounts, so this is an optional match.
            // If no match is found, the payment is still recorded with userID = null.
            // @see https://www.php.net/manual/en/function.filter-var.php (FILTER_VALIDATE_EMAIL)
            $userID = null;
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $user = Database::fetchOne(
                    "SELECT userID FROM tblUsers WHERE email = ? LIMIT 1",
                    [$email],
                    's'
                );

                if ($user) {
                    $userID = (int)$user['userID'];
                }
            }

            // 📝 Log the incoming webhook event (all events are logged for auditing)
            ActivityLogger::log(
                $userID,
                'kofi_webhook_received',
                'payment',
                'info',
                'Ko-fi webhook event received: ' . $eventType . ' from ' . $fromName
                    . ' (' . $currency . ' ' . $amount . ')',
                [
                    'eventType'              => $eventType,
                    'kofiTransactionId'      => $kofiTransactionId,
                    'amount'                 => $amount,
                    'currency'               => $currency,
                    'fromName'               => $fromName,
                    'email'                  => $email,
                    'isSubscription'         => $isSubscription,
                    'isFirstSubscription'    => $isFirstSubscription,
                    'message'                => $message,
                    'matchedUserID'          => $userID,
                ]
            );

            // 🔀 Process the event based on its type
            // @see https://ko-fi.com/manage/webhooks
            switch ($eventType) {

                // =====================================================
                // 🎁 Donation - One-time donation/tip payment
                // =====================================================
                case 'Donation':
                    // A one-time donation from a Ko-fi supporter
                    // Record the payment and optionally grant any donation-based features

                    // 💾 Record the payment via PaymentManager
                    $paymentResult = PaymentManager::recordPayment(
                        $userID ?? 0,
                        (float)$amount,
                        self::DEFAULT_PAYMENT_METHOD,
                        self::PROVIDER_NAME,
                        [
                            'currency'      => $currency,
                            'transactionID' => $kofiTransactionId,
                            'description'   => 'Ko-fi Donation from ' . $fromName
                                . (!empty($message) ? ': ' . mb_substr($message, 0, 200) : ''),
                            'metadata'      => [
                                'kofiTransactionId'  => $kofiTransactionId,
                                'fromName'           => $fromName,
                                'email'              => $email,
                                'message'            => $message,
                                'timestamp'          => $timestamp,
                                'kofiUrl'            => $kofiUrl,
                                'eventType'          => $eventType,
                            ],
                        ]
                    );

                    $paymentID = $paymentResult['paymentID'] ?? null;

                    // ✅ Ko-fi webhooks are sent AFTER payment completion,
                    // so we can immediately mark the payment as completed
                    if ($paymentID) {
                        PaymentManager::completePayment(
                            $paymentID,
                            $kofiTransactionId,
                            $kofiUrl ?: null
                        );
                    }

                    // 📝 Log the successful donation recording
                    ActivityLogger::log(
                        $userID,
                        'kofi_donation_recorded',
                        'payment',
                        'info',
                        'Ko-fi donation recorded: ' . $currency . ' ' . $amount
                            . ' from ' . $fromName,
                        [
                            'kofiTransactionId' => $kofiTransactionId,
                            'paymentID'         => $paymentID,
                            'amount'            => $amount,
                            'currency'          => $currency,
                            'fromName'          => $fromName,
                            'matchedUserID'     => $userID,
                        ]
                    );

                    // 🔗 Dispatch webhook event for integrating services
                    if (class_exists('WebhookManager')) {
                        WebhookManager::dispatch('payment.kofi.donation', [
                            'paymentID'         => $paymentID,
                            'userID'            => $userID,
                            'kofiTransactionId' => $kofiTransactionId,
                            'amount'            => $amount,
                            'currency'          => $currency,
                            'fromName'          => $fromName,
                            'email'             => $email,
                            'message'           => $message,
                        ]);
                    }

                    return [
                        'success'   => true,
                        'message'   => 'Donation recorded and completed',
                        'eventType' => $eventType,
                        'paymentID' => $paymentID,
                    ];

                // =====================================================
                // 🔄 Subscription - Recurring monthly subscription payment
                // =====================================================
                case 'Subscription':
                    // A recurring subscription payment from a Ko-fi supporter
                    // Ko-fi subscriptions are monthly recurring donations
                    // The is_first_subscription_payment flag indicates if this is
                    // the initial subscription payment or a renewal

                    // 💾 Record the payment via PaymentManager
                    $subscriptionLabel = $isFirstSubscription
                        ? 'Ko-fi Subscription (Initial) from ' . $fromName
                        : 'Ko-fi Subscription (Renewal) from ' . $fromName;

                    $paymentResult = PaymentManager::recordPayment(
                        $userID ?? 0,
                        (float)$amount,
                        self::DEFAULT_PAYMENT_METHOD,
                        self::PROVIDER_NAME,
                        [
                            'currency'      => $currency,
                            'transactionID' => $kofiTransactionId,
                            'description'   => $subscriptionLabel
                                . (!empty($message) ? ': ' . mb_substr($message, 0, 200) : ''),
                            'metadata'      => [
                                'kofiTransactionId'         => $kofiTransactionId,
                                'fromName'                  => $fromName,
                                'email'                     => $email,
                                'message'                   => $message,
                                'timestamp'                 => $timestamp,
                                'kofiUrl'                   => $kofiUrl,
                                'eventType'                 => $eventType,
                                'isSubscription'            => $isSubscription,
                                'isFirstSubscription'       => $isFirstSubscription,
                            ],
                        ]
                    );

                    $paymentID = $paymentResult['paymentID'] ?? null;

                    // ✅ Ko-fi webhooks are sent AFTER payment completion,
                    // so we can immediately mark the payment as completed
                    if ($paymentID) {
                        PaymentManager::completePayment(
                            $paymentID,
                            $kofiTransactionId,
                            $kofiUrl ?: null
                        );
                    }

                    // 🔄 If this is the first subscription payment and the user has a
                    // SIGNula account, we may want to activate subscription-based features.
                    // For renewal payments, the subscription status is simply maintained.
                    if ($userID !== null && $isFirstSubscription) {
                        // 📝 Log the new subscription activation
                        ActivityLogger::log(
                            $userID,
                            'kofi_subscription_started',
                            'payment',
                            'info',
                            'Ko-fi subscription started for user ' . $userID . ' from ' . $fromName,
                            [
                                'kofiTransactionId' => $kofiTransactionId,
                                'paymentID'         => $paymentID,
                                'amount'            => $amount,
                                'currency'          => $currency,
                            ]
                        );
                    } elseif ($userID !== null && !$isFirstSubscription) {
                        // 📝 Log the subscription renewal
                        ActivityLogger::log(
                            $userID,
                            'kofi_subscription_renewed',
                            'payment',
                            'info',
                            'Ko-fi subscription renewed for user ' . $userID . ' from ' . $fromName,
                            [
                                'kofiTransactionId' => $kofiTransactionId,
                                'paymentID'         => $paymentID,
                                'amount'            => $amount,
                                'currency'          => $currency,
                            ]
                        );
                    } else {
                        // 📝 Log subscription payment without matched user
                        ActivityLogger::log(
                            null,
                            'kofi_subscription_recorded',
                            'payment',
                            'info',
                            'Ko-fi subscription payment recorded (no matched SIGNula user): '
                                . $currency . ' ' . $amount . ' from ' . $fromName,
                            [
                                'kofiTransactionId'     => $kofiTransactionId,
                                'paymentID'             => $paymentID,
                                'email'                 => $email,
                                'isFirstSubscription'   => $isFirstSubscription,
                            ]
                        );
                    }

                    // 🔗 Dispatch webhook event for integrating services
                    if (class_exists('WebhookManager')) {
                        WebhookManager::dispatch('payment.kofi.subscription', [
                            'paymentID'              => $paymentID,
                            'userID'                 => $userID,
                            'kofiTransactionId'      => $kofiTransactionId,
                            'amount'                 => $amount,
                            'currency'               => $currency,
                            'fromName'               => $fromName,
                            'email'                  => $email,
                            'isFirstSubscription'    => $isFirstSubscription,
                            'message'                => $message,
                        ]);
                    }

                    return [
                        'success'   => true,
                        'message'   => $isFirstSubscription
                            ? 'Subscription payment recorded and activated'
                            : 'Subscription renewal payment recorded',
                        'eventType' => $eventType,
                        'paymentID' => $paymentID,
                    ];

                // =====================================================
                // 🛒 Shop Order - Ko-fi shop product purchase
                // =====================================================
                case 'Shop Order':
                    // A purchase from the Ko-fi shop (digital or physical products)
                    // Ko-fi shop orders may include multiple items; the webhook
                    // provides the total amount and transaction details

                    // 💾 Record the payment via PaymentManager
                    $paymentResult = PaymentManager::recordPayment(
                        $userID ?? 0,
                        (float)$amount,
                        self::DEFAULT_PAYMENT_METHOD,
                        self::PROVIDER_NAME,
                        [
                            'currency'      => $currency,
                            'transactionID' => $kofiTransactionId,
                            'description'   => 'Ko-fi Shop Order from ' . $fromName
                                . (!empty($message) ? ': ' . mb_substr($message, 0, 200) : ''),
                            'metadata'      => [
                                'kofiTransactionId'  => $kofiTransactionId,
                                'fromName'           => $fromName,
                                'email'              => $email,
                                'message'            => $message,
                                'timestamp'          => $timestamp,
                                'kofiUrl'            => $kofiUrl,
                                'eventType'          => $eventType,
                                'shopItems'          => $eventData['shop_items'] ?? null,
                            ],
                        ]
                    );

                    $paymentID = $paymentResult['paymentID'] ?? null;

                    // ✅ Ko-fi webhooks are sent AFTER payment completion,
                    // so we can immediately mark the payment as completed
                    if ($paymentID) {
                        PaymentManager::completePayment(
                            $paymentID,
                            $kofiTransactionId,
                            $kofiUrl ?: null
                        );
                    }

                    // 📝 Log the shop order recording
                    ActivityLogger::log(
                        $userID,
                        'kofi_shop_order_recorded',
                        'payment',
                        'info',
                        'Ko-fi Shop Order recorded: ' . $currency . ' ' . $amount
                            . ' from ' . $fromName,
                        [
                            'kofiTransactionId' => $kofiTransactionId,
                            'paymentID'         => $paymentID,
                            'amount'            => $amount,
                            'currency'          => $currency,
                            'fromName'          => $fromName,
                            'matchedUserID'     => $userID,
                            'shopItems'         => $eventData['shop_items'] ?? null,
                        ]
                    );

                    // 🔗 Dispatch webhook event for integrating services
                    if (class_exists('WebhookManager')) {
                        WebhookManager::dispatch('payment.kofi.shop_order', [
                            'paymentID'         => $paymentID,
                            'userID'            => $userID,
                            'kofiTransactionId' => $kofiTransactionId,
                            'amount'            => $amount,
                            'currency'          => $currency,
                            'fromName'          => $fromName,
                            'email'             => $email,
                            'shopItems'         => $eventData['shop_items'] ?? null,
                            'message'           => $message,
                        ]);
                    }

                    return [
                        'success'   => true,
                        'message'   => 'Shop order recorded and completed',
                        'eventType' => $eventType,
                        'paymentID' => $paymentID,
                    ];

                // =====================================================
                // ❓ Unrecognised event type (should not reach here due to validation above)
                // =====================================================
                default:
                    // This should not be reached due to the validation check above,
                    // but is included as a defensive programming measure
                    ActivityLogger::log(
                        null,
                        'kofi_webhook_unknown',
                        'payment',
                        'warning',
                        'Received unrecognised Ko-fi webhook event type (in switch): ' . $eventType,
                        [
                            'eventType'         => $eventType,
                            'kofiTransactionId' => $kofiTransactionId,
                        ]
                    );

                    return [
                        'success'   => false,
                        'message'   => 'Unrecognised webhook event type: ' . $eventType,
                        'eventType' => $eventType,
                    ];
            }

        } catch (\Exception $e) {
            // 🚨 Catch any unexpected exceptions during webhook processing
            ActivityLogger::log(
                null,
                'kofi_webhook_exception',
                'payment',
                'error',
                'Exception processing Ko-fi webhook event: ' . $e->getMessage(),
                [
                    'eventType' => $eventData['type'] ?? 'unknown',
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]
            );

            return [
                'success'   => false,
                'message'   => 'Unexpected error processing webhook event',
                'eventType' => $eventData['type'] ?? 'unknown',
            ];
        }
    }

    // ========================================================================
    // 🧪 CONNECTION TESTING
    // ========================================================================

    /**
     * 🩺 Test the Ko-fi provider configuration
     *
     * Verifies that the Ko-fi integration is properly configured by checking
     * that all required settings are present and valid. Unlike API-based
     * providers (Coinbase, Stripe, PayPal), Ko-fi does not have a public API
     * to test connectivity against. Therefore, this method performs local
     * configuration validation only.
     *
     * Checks performed:
     * 1. verification_token is set and not empty in tblSettings
     * 2. verification_token can be successfully decrypted
     * 3. page_url is set and is a valid URL format
     *
     * @return array ['success' => bool, 'message' => string]
     *
     * @example
     * ```php
     * $test = KofiProvider::testConnection();
     * if ($test['success']) {
     *     echo 'Ko-fi configuration is valid!';
     * } else {
     *     echo 'Configuration issue: ' . $test['message'];
     * }
     * ```
     */
    public static function testConnection(): array
    {
        try {
            $issues = [];

            // ========================================================================
            // 🔑 Check 1: Verification token is configured
            // ========================================================================
            $encryptedToken = getSetting('payment.kofi.verification_token');
            if (empty($encryptedToken)) {
                $issues[] = 'Verification token (payment.kofi.verification_token) is not configured';
            } else {
                // 🔓 Check 2: Verification token can be decrypted
                // @see SecurityUtils::decrypt() for AES-256-CBC decryption details
                $decryptedToken = SecurityUtils::decrypt($encryptedToken);
                if (empty($decryptedToken)) {
                    $issues[] = 'Verification token could not be decrypted (may be corrupted or encryption key changed)';
                }
            }

            // ========================================================================
            // 🔗 Check 3: Ko-fi page URL is configured and valid
            // ========================================================================
            $pageUrl = getSetting('payment.kofi.page_url');
            if (empty($pageUrl)) {
                $issues[] = 'Ko-fi page URL (payment.kofi.page_url) is not configured';
            } else {
                // 🛡️ Validate that the URL is well-formed
                // @see https://www.php.net/manual/en/function.filter-var.php (FILTER_VALIDATE_URL)
                if (!filter_var($pageUrl, FILTER_VALIDATE_URL)) {
                    $issues[] = 'Ko-fi page URL is not a valid URL format: ' . $pageUrl;
                }
            }

            // ========================================================================
            // 📊 Compile and return results
            // ========================================================================
            if (!empty($issues)) {
                // ❌ One or more configuration issues found
                $issueMessage = implode('; ', $issues);

                ActivityLogger::log(
                    null,
                    'kofi_connection_test_failed',
                    'payment',
                    'warning',
                    'Ko-fi configuration test failed: ' . $issueMessage,
                    [
                        'issues'     => $issues,
                        'issueCount' => count($issues),
                    ]
                );

                return [
                    'success' => false,
                    'message' => 'Configuration test failed: ' . $issueMessage,
                ];
            }

            // ✅ All configuration checks passed
            ActivityLogger::log(
                null,
                'kofi_connection_test_success',
                'payment',
                'info',
                'Ko-fi configuration test passed — verification token and page URL are valid',
                [
                    'pageUrl' => $pageUrl,
                ]
            );

            return [
                'success' => true,
                'message' => 'Ko-fi configuration is valid. Verification token is set and page URL is configured.'
                    . ' Note: Ko-fi does not provide a public API to test live connectivity;'
                    . ' webhook delivery can only be verified by sending a test from the Ko-fi dashboard.',
            ];

        } catch (\Exception $e) {
            // 🚨 Unexpected exception during configuration test
            ActivityLogger::log(
                null,
                'kofi_connection_test_exception',
                'payment',
                'error',
                'Exception during Ko-fi configuration test: ' . $e->getMessage(),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            return [
                'success' => false,
                'message' => 'Configuration test failed due to an unexpected error',
            ];
        }
    }
}
