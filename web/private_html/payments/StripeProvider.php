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
 * SIGNula - Universal Single Sign-On Authentication System
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package SIGNula
 * @version 2.3.0-beta
 */

/**
 * ============================================================================
 * 💳 SIGNula - Stripe Payment Provider
 * ============================================================================
 *
 * Purpose: Stripe payment processing integration using raw cURL (no SDK)
 * PHP Version: 8.3+
 *
 * Features:
 * - Stripe Checkout Sessions (hosted checkout page)
 * - Payment Intents for custom payment flows
 * - Subscription lifecycle management via Stripe Billing
 * - Webhook signature verification and event handling
 * - Refund processing (full and partial)
 * - Customer management (create, lookup)
 * - Stripe Link support for accelerated checkout
 * - Idempotency key support for safe retries
 * - Balance retrieval for connectivity testing
 * - Multi-currency support with smallest-unit conversion
 *
 * Integration Notes:
 * - Uses raw cURL since Composer/SDK is unavailable on Dreamhost shared hosting
 * - All Stripe API requests use form-encoded POST (not JSON)
 * - Nested parameters use bracket notation: line_items[0][price_data][currency]
 * - All amounts sent to Stripe must be in smallest currency unit (e.g., pence/cents)
 * - Settings are stored in tblSettings and retrieved via getSetting()
 * - Sensitive keys (API secrets) are encrypted in DB via SecurityUtils
 *
 * @see https://docs.stripe.com/api                          (Stripe API Reference)
 * @see https://docs.stripe.com/checkout/quickstart           (Checkout Sessions)
 * @see https://docs.stripe.com/payments/payment-intents      (Payment Intents)
 * @see https://docs.stripe.com/billing/subscriptions/overview (Subscriptions)
 * @see https://docs.stripe.com/webhooks/signatures            (Webhook Signatures)
 * @see https://docs.stripe.com/api/idempotent-requests        (Idempotency Keys)
 * @see https://docs.stripe.com/currencies#zero-decimal        (Zero-decimal currencies)
 *
 * @package    SIGNula
 * @subpackage Payments
 * v2.4.0 Changes:
 * - Added optional $credentials parameter to apiRequest() for partner API keys
 * - Supports Level 2 payments (Option 2a: partner uses own Stripe keys)
 * - Backward compatible — all existing calls continue to use global SIGNula keys
 *
 * @version    1.1.0
 * @since      2.3.0-beta
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

// 📚 Require the core PaymentManager class for tier lookups, recording payments, etc.
// Using __DIR__ with DIRECTORY_SEPARATOR for platform-neutral file paths
// @see https://www.php.net/manual/en/language.constants.predefined.php
$paymentManagerPath = __DIR__ . DIRECTORY_SEPARATOR . 'PaymentManager.php';
if (file_exists($paymentManagerPath)) {
    require_once $paymentManagerPath;
} else {
    // ⚠️ Critical dependency — log and halt if PaymentManager is missing
    error_log('🚨 [StripeProvider] PaymentManager.php not found at: ' . $paymentManagerPath);
    throw new RuntimeException('Required dependency PaymentManager.php is not available');
}

// 🛡️ Load the BillingMode TEST-MODE guard (G-002 Stage S1 — see BillingMode.php
// for the full rationale). Every money-moving method below calls
// BillingMode::assertTestMode('stripe') before making any Stripe API request.
$billingModePath = __DIR__ . DIRECTORY_SEPARATOR . 'BillingMode.php';
if (file_exists($billingModePath)) {
    require_once $billingModePath;
} else {
    // ⚠️ The guard is a hard safety requirement — refuse to load without it
    error_log('🚨 [StripeProvider] BillingMode.php not found at: ' . $billingModePath);
    throw new RuntimeException('Required dependency BillingMode.php is not available');
}

/**
 * 💳 Stripe Payment Provider
 *
 * Provides static methods for interacting with the Stripe API using raw cURL.
 * Follows the SIGNula project pattern of all-static utility classes.
 *
 * All Stripe settings are stored in tblSettings with the prefix 'payment.stripe.'
 * and retrieved via the global getSetting() function from config.php.
 *
 * Required settings in tblSettings:
 * - payment.stripe.enabled          (bool: '1' or '0')
 * - payment.stripe.mode             (string: 'test' or 'live')
 * - payment.stripe.secret_key       (string: encrypted via SecurityUtils)
 * - payment.stripe.webhook_secret   (string: encrypted via SecurityUtils)
 * - payment.stripe.payment_methods  (JSON string: e.g. '["card","paypal"]')
 * - payment.stripe.link_enabled     (bool: '1' or '0')
 * - payment.stripe.success_url      (string: URL for successful checkout redirect)
 * - payment.stripe.cancel_url       (string: URL for cancelled checkout redirect)
 */
class StripeProvider
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /**
     * @var string Stripe API base URL (always HTTPS)
     * @see https://docs.stripe.com/api#authentication
     */
    private const STRIPE_API_BASE = 'https://api.stripe.com/v1/';

    /**
     * @var string User-Agent header sent with every API request
     * Identifies SIGNula to Stripe for support and debugging purposes
     */
    private const USER_AGENT = 'SIGNula/2.3.0 (https://signula.id)';

    /**
     * @var int cURL connection timeout in seconds
     * @see https://www.php.net/manual/en/function.curl-setopt.php (CURLOPT_CONNECTTIMEOUT)
     */
    private const CONNECT_TIMEOUT = 10;

    /**
     * @var int cURL total request timeout in seconds
     * @see https://www.php.net/manual/en/function.curl-setopt.php (CURLOPT_TIMEOUT)
     */
    private const REQUEST_TIMEOUT = 30;

    /**
     * @var int Maximum tolerance (in seconds) for webhook timestamp verification
     * Stripe recommends 300 seconds (5 minutes) to account for clock skew
     * @see https://docs.stripe.com/webhooks/signatures#verify-manually
     */
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    /**
     * @var array Currencies that use zero-decimal amounts (no multiplication needed)
     * These currencies do not use fractional units — 1 JPY = 1 smallest unit
     * @see https://docs.stripe.com/currencies#zero-decimal
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    // ========================================================================
    // 🔌 PROVIDER STATUS
    // ========================================================================

    /**
     * ✅ Check if Stripe is enabled and properly configured
     *
     * Verifies that:
     * 1. The payment.stripe.enabled setting is set to '1' in tblSettings
     * 2. A non-empty Stripe secret key is configured (encrypted in DB)
     *
     * @return bool True if Stripe is enabled and has a valid secret key
     *
     * @example
     * ```php
     * if (StripeProvider::isEnabled()) {
     *     // Show Stripe payment options to user
     * }
     * ```
     */
    public static function isEnabled(): bool
    {
        // 🔍 Check if Stripe is enabled in settings
        $enabled = getSetting('payment.stripe.enabled');

        if ($enabled !== '1' && $enabled !== 1 && $enabled !== true) {
            return false;
        }

        // 🔑 Verify that a secret key is configured (it's encrypted in DB)
        $encryptedKey = getSetting('payment.stripe.secret_key');

        if (empty($encryptedKey)) {
            return false;
        }

        // 🔓 Attempt to decrypt the key to ensure it's valid
        try {
            $decryptedKey = SecurityUtils::decrypt((string)$encryptedKey);
            return !empty(trim($decryptedKey));
        } catch (\Exception $e) {
            // ⚠️ Decryption failed — key is misconfigured
            ActivityLogger::log(
                null,
                'stripe_config_error',
                'payment',
                'error',
                'Stripe secret key decryption failed — provider disabled',
                ['error' => $e->getMessage()]
            );
            return false;
        }
    }

    // ========================================================================
    // 🛒 CHECKOUT SESSIONS
    // ========================================================================

    /**
     * 🛒 Create a Stripe Checkout Session
     *
     * Creates a hosted checkout page for the user to complete their payment.
     * Supports both one-time payments and recurring subscriptions.
     *
     * The checkout session is created with metadata containing the SIGNula userID,
     * tierID, and billingCycle so that the webhook handler can process the payment
     * and activate the subscription once payment is confirmed.
     *
     * @param int    $userID       The SIGNula user ID making the purchase
     * @param int    $tierID       The subscription tier ID from tblSubscriptionTiers
     * @param string $billingCycle Billing cycle: 'monthly', 'yearly', 'quarterly', or 'lifetime'
     * @param array  $options      Optional parameters:
     *                             - 'customerEmail' (string): Pre-fill email on checkout page
     *                             - 'customerID' (string): Existing Stripe customer ID
     *                             - 'promoCode' (string): SIGNula discount/promo code
     *                             - 'allowPromotionCodes' (bool): Allow Stripe promo codes (default: false)
     *                             - 'trialDays' (int): Number of trial days for subscriptions
     *                             - 'successURL' (string): Override default success redirect URL
     *                             - 'cancelURL' (string): Override default cancel redirect URL
     *                             - 'metadata' (array): Additional metadata for the checkout session
     *                             - 'idempotencyKey' (string): Idempotency key for safe retries
     *
     * @return array Associative array with:
     *               - 'success' (bool): Whether the session was created
     *               - 'sessionID' (string): Stripe Checkout Session ID (on success)
     *               - 'url' (string): Redirect URL for the hosted checkout page (on success)
     *               - 'paymentID' (int): Internal SIGNula payment record ID (on success)
     *               - 'error' (string): Error message (on failure)
     *
     * @see https://docs.stripe.com/api/checkout/sessions/create
     * @see https://docs.stripe.com/checkout/quickstart
     */
    public static function createCheckoutSession(
        int $userID,
        int $tierID,
        string $billingCycle,
        array $options = []
    ): array {
        try {
            // 🛡️ TEST-MODE GUARD (G-002 §0/§9.1) — checked FIRST, before any other
            // logic or Stripe API call. If billing.test_mode_guard is ON and
            // payment.stripe.mode is 'live', this throws BillingModeException,
            // which the catch(\Exception) block below converts into the usual
            // ['success' => false, 'error' => …] response — no HTTP call is
            // ever reached. @see BillingMode::assertTestMode()
            BillingMode::assertTestMode('stripe');

            // 🔍 Retrieve the subscription tier details from the database
            $tier = PaymentManager::getTier($tierID);

            if (!$tier) {
                return [
                    'success' => false,
                    'error' => 'Invalid subscription tier ID: ' . $tierID,
                ];
            }

            // 💰 Determine the price based on billing cycle
            // The tier table stores monthlyPrice and yearlyPrice as decimal values
            $amount = match ($billingCycle) {
                'yearly'    => (float)($tier['yearlyPrice'] ?? 0),
                'quarterly' => (float)(($tier['monthlyPrice'] ?? 0) * 3),
                'monthly'   => (float)($tier['monthlyPrice'] ?? 0),
                'lifetime'  => (float)($tier['yearlyPrice'] ?? 0) * 5, // 📌 Lifetime = 5x annual
                default     => (float)($tier['monthlyPrice'] ?? 0),
            };

            // ⚠️ Validate that there's actually a price to charge
            if ($amount <= 0) {
                return [
                    'success' => false,
                    'error' => 'Tier "' . ($tier['tierName'] ?? 'unknown') . '" has no price for billing cycle: ' . $billingCycle,
                ];
            }

            // 💱 Determine currency (from tier or default)
            $currency = strtolower($tier['currency'] ?? PaymentManager::DEFAULT_CURRENCY);

            // 🏷️ Get enabled payment method types from settings
            // Stored as a JSON array, e.g. '["card","paypal"]'
            $paymentMethodsJSON = getSetting('payment.stripe.payment_methods');
            $paymentMethodTypes = [];

            if (!empty($paymentMethodsJSON)) {
                $decoded = json_decode((string)$paymentMethodsJSON, true);
                if (is_array($decoded)) {
                    $paymentMethodTypes = $decoded;
                }
            }

            // 🔗 If Stripe Link is enabled, ensure 'link' is in payment_method_types
            // Stripe Link provides accelerated checkout with saved payment info
            // @see https://docs.stripe.com/payments/link
            if (getSetting('payment.stripe.link_enabled') === '1') {
                if (!in_array('link', $paymentMethodTypes, true)) {
                    $paymentMethodTypes[] = 'link';
                }
            }

            // 🌐 Determine success and cancel URLs
            $successURL = $options['successURL']
                ?? getSetting('payment.stripe.success_url')
                ?? 'https://signula.id/payments/success';
            $cancelURL = $options['cancelURL']
                ?? getSetting('payment.stripe.cancel_url')
                ?? 'https://signula.id/payments/cancel';

            // 📦 Determine checkout mode based on billing cycle
            // 'subscription' for recurring, 'payment' for one-time
            // @see https://docs.stripe.com/api/checkout/sessions/create#create_checkout_session-mode
            $isSubscription = in_array($billingCycle, ['monthly', 'quarterly', 'yearly'], true);
            $mode = $isSubscription ? 'subscription' : 'payment';

            // 🔢 Convert amount to smallest currency unit (pence, cents, etc.)
            $amountInSmallestUnit = self::toSmallestUnit($amount, $currency);

            // 📋 Build the Stripe Checkout Session parameters
            // Stripe expects form-encoded data with bracket notation for nested arrays
            $params = [];

            // 🎯 Checkout mode
            $params['mode'] = $mode;

            // 🌐 Redirect URLs — append session ID for server-side verification
            // {CHECKOUT_SESSION_ID} is a Stripe template variable replaced at redirect time
            // @see https://docs.stripe.com/api/checkout/sessions/create#create_checkout_session-success_url
            $params['success_url'] = $successURL . (str_contains($successURL, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';
            $params['cancel_url'] = $cancelURL;

            // 🛍️ Line items — single item for the subscription tier
            if ($isSubscription) {
                // 🔄 Subscription mode requires recurring price data
                // @see https://docs.stripe.com/api/checkout/sessions/create#create_checkout_session-line_items-price_data-recurring
                $recurringInterval = match ($billingCycle) {
                    'monthly'   => 'month',
                    'quarterly' => 'month',
                    'yearly'    => 'year',
                    default     => 'month',
                };
                $recurringIntervalCount = match ($billingCycle) {
                    'quarterly' => 3,
                    default     => 1,
                };

                $params['line_items[0][price_data][currency]'] = $currency;
                $params['line_items[0][price_data][product_data][name]'] = 'SIGNula ' . ($tier['tierName'] ?? 'Subscription');
                $params['line_items[0][price_data][product_data][description]'] = ($tier['tierDescription'] ?? 'SIGNula subscription tier') . ' (' . ucfirst($billingCycle) . ')';
                $params['line_items[0][price_data][unit_amount]'] = $amountInSmallestUnit;
                $params['line_items[0][price_data][recurring][interval]'] = $recurringInterval;
                $params['line_items[0][price_data][recurring][interval_count]'] = $recurringIntervalCount;
                $params['line_items[0][quantity]'] = 1;

                // ⏳ Add trial period if specified
                $trialDays = (int)($options['trialDays'] ?? $tier['trialDays'] ?? 0);
                if ($trialDays > 0) {
                    $params['subscription_data[trial_period_days]'] = $trialDays;
                }
            } else {
                // 💰 One-time payment mode (e.g., lifetime purchase)
                $params['line_items[0][price_data][currency]'] = $currency;
                $params['line_items[0][price_data][product_data][name]'] = 'SIGNula ' . ($tier['tierName'] ?? 'Plan') . ' (Lifetime)';
                $params['line_items[0][price_data][product_data][description]'] = ($tier['tierDescription'] ?? 'SIGNula lifetime plan');
                $params['line_items[0][price_data][unit_amount]'] = $amountInSmallestUnit;
                $params['line_items[0][quantity]'] = 1;
            }

            // 🏷️ Payment method types (if configured)
            if (!empty($paymentMethodTypes)) {
                foreach ($paymentMethodTypes as $index => $methodType) {
                    $params['payment_method_types[' . $index . ']'] = $methodType;
                }
            }

            // 📧 Pre-fill customer email if provided
            if (!empty($options['customerEmail'])) {
                $params['customer_email'] = $options['customerEmail'];
            }

            // 👤 Attach existing Stripe customer if provided
            if (!empty($options['customerID'])) {
                $params['customer'] = $options['customerID'];
                // ⚠️ Cannot use customer_email AND customer simultaneously
                unset($params['customer_email']);
            }

            // 🎟️ Allow Stripe promotional codes on the checkout page
            if (!empty($options['allowPromotionCodes'])) {
                $params['allow_promotion_codes'] = 'true';
            }

            // 📎 Metadata — essential for webhook processing
            // These values are returned in webhook events so we can link back to SIGNula records
            // @see https://docs.stripe.com/api/metadata
            $params['metadata[signula_user_id]'] = $userID;
            $params['metadata[signula_tier_id]'] = $tierID;
            $params['metadata[signula_billing_cycle]'] = $billingCycle;
            $params['metadata[signula_tier_name]'] = $tier['tierName'] ?? '';

            // 📎 Merge any additional custom metadata from options
            if (!empty($options['metadata']) && is_array($options['metadata'])) {
                foreach ($options['metadata'] as $metaKey => $metaValue) {
                    $params['metadata[' . $metaKey . ']'] = (string)$metaValue;
                }
            }

            // 🚀 Send the API request to Stripe
            $idempotencyKey = $options['idempotencyKey'] ?? null;
            $response = self::apiRequest('POST', 'checkout/sessions', $params, $idempotencyKey);

            // ❌ Check for API errors
            if (!empty($response['success']) && $response['success'] === false) {
                return $response;
            }

            // ⚠️ Check for Stripe error response format
            if (!empty($response['error'])) {
                $errorMessage = is_array($response['error'])
                    ? ($response['error']['message'] ?? 'Unknown Stripe error')
                    : (string)$response['error'];

                ActivityLogger::log(
                    $userID,
                    'stripe_checkout_failed',
                    'payment',
                    'error',
                    'Stripe Checkout Session creation failed: ' . $errorMessage,
                    [
                        'tierID' => $tierID,
                        'billingCycle' => $billingCycle,
                        'amount' => $amount,
                        'currency' => $currency,
                    ]
                );

                return [
                    'success' => false,
                    'error' => 'Payment session creation failed: ' . $errorMessage,
                ];
            }

            // ✅ Verify that we got a valid session ID and URL back
            if (empty($response['id']) || empty($response['url'])) {
                return [
                    'success' => false,
                    'error' => 'Invalid response from Stripe — missing session ID or checkout URL',
                ];
            }

            // 💾 Record the pending payment in our database via PaymentManager
            $paymentRecord = PaymentManager::recordPayment(
                $userID,
                $amount,
                'stripe',
                'stripe',
                [
                    'currency'      => strtoupper($currency),
                    'transactionID' => $response['id'],
                    'description'   => 'SIGNula ' . ($tier['tierName'] ?? 'Subscription') . ' (' . ucfirst($billingCycle) . ')',
                    'metadata'      => [
                        'stripe_session_id' => $response['id'],
                        'tier_id'           => $tierID,
                        'billing_cycle'     => $billingCycle,
                        'mode'              => $mode,
                    ],
                ]
            );

            // 📝 Log the successful session creation
            ActivityLogger::log(
                $userID,
                'stripe_checkout_created',
                'payment',
                'info',
                'Stripe Checkout Session created for ' . ($tier['tierName'] ?? 'tier') . ' (' . ucfirst($billingCycle) . ') — ' . strtoupper($currency) . ' ' . number_format($amount, 2),
                [
                    'sessionID'    => $response['id'],
                    'tierID'       => $tierID,
                    'tierName'     => $tier['tierName'] ?? '',
                    'billingCycle' => $billingCycle,
                    'amount'       => $amount,
                    'currency'     => strtoupper($currency),
                    'mode'         => $mode,
                    'paymentID'    => $paymentRecord['paymentID'] ?? null,
                ]
            );

            return [
                'success'   => true,
                'sessionID' => $response['id'],
                'url'       => $response['url'],
                'paymentID' => $paymentRecord['paymentID'] ?? null,
            ];

        } catch (\Exception $e) {
            // 🚨 Unexpected error — log and return failure
            ActivityLogger::log(
                $userID,
                'stripe_checkout_exception',
                'payment',
                'critical',
                'Exception during Stripe Checkout Session creation: ' . $e->getMessage(),
                [
                    'tierID'       => $tierID,
                    'billingCycle' => $billingCycle,
                    'file'         => $e->getFile(),
                    'line'         => $e->getLine(),
                ]
            );

            return [
                'success' => false,
                'error'   => 'An unexpected error occurred while creating the payment session',
            ];
        }
    }

    /**
     * 🔍 Retrieve a Stripe Checkout Session
     *
     * Fetches the full details of a Checkout Session including expanded
     * line_items and payment_intent data. Used to verify payment status
     * after the user is redirected back from the Stripe checkout page.
     *
     * @param string $sessionID The Stripe Checkout Session ID (cs_...)
     *
     * @return array The full Stripe session object or error array
     *
     * @see https://docs.stripe.com/api/checkout/sessions/retrieve
     */
    public static function retrieveCheckoutSession(string $sessionID): array
    {
        try {
            // 🔍 Validate session ID format (should start with cs_)
            if (!str_starts_with($sessionID, 'cs_')) {
                return [
                    'success' => false,
                    'error'   => 'Invalid Checkout Session ID format',
                ];
            }

            // 📡 Retrieve session with expanded objects for complete data
            // expand[] tells Stripe to include full objects instead of just IDs
            // @see https://docs.stripe.com/api/expanding_objects
            $response = self::apiRequest('GET', 'checkout/sessions/' . urlencode($sessionID), [
                'expand[0]' => 'line_items',
                'expand[1]' => 'payment_intent',
            ]);

            // ❌ Check for errors
            if (!empty($response['success']) && $response['success'] === false) {
                return $response;
            }

            if (!empty($response['error'])) {
                $errorMessage = is_array($response['error'])
                    ? ($response['error']['message'] ?? 'Unknown error')
                    : (string)$response['error'];

                return [
                    'success' => false,
                    'error'   => 'Failed to retrieve checkout session: ' . $errorMessage,
                ];
            }

            return [
                'success' => true,
                'session' => $response,
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'stripe_session_retrieve_error',
                'payment',
                'error',
                'Failed to retrieve Stripe Checkout Session: ' . $e->getMessage(),
                ['sessionID' => $sessionID]
            );

            return [
                'success' => false,
                'error'   => 'Failed to retrieve checkout session',
            ];
        }
    }

    // ========================================================================
    // 💰 PAYMENT INTENTS
    // ========================================================================

    /**
     * 💳 Create a Payment Intent
     *
     * Creates a Stripe PaymentIntent for custom payment flows where you need
     * more control than Checkout Sessions provide. The client_secret returned
     * is used with Stripe.js on the frontend to confirm the payment.
     *
     * @param float  $amount   Payment amount in major currency units (e.g., 10.50 for GBP 10.50)
     * @param string $currency ISO 4217 currency code (default: 'GBP')
     * @param array  $options  Optional parameters:
     *                         - 'customerID' (string): Existing Stripe customer ID
     *                         - 'customerEmail' (string): Customer email for receipts
     *                         - 'description' (string): Payment description
     *                         - 'metadata' (array): Additional key-value metadata
     *                         - 'receiptEmail' (string): Email to send receipt to
     *                         - 'statementDescriptor' (string): Statement descriptor (max 22 chars)
     *                         - 'idempotencyKey' (string): Idempotency key for safe retries
     *
     * @return array Associative array with:
     *               - 'success' (bool): Whether the intent was created
     *               - 'clientSecret' (string): Client secret for Stripe.js (on success)
     *               - 'paymentIntentID' (string): Payment Intent ID (on success)
     *               - 'error' (string): Error message (on failure)
     *
     * @see https://docs.stripe.com/api/payment_intents/create
     * @see https://docs.stripe.com/payments/payment-intents
     */
    public static function createPaymentIntent(
        float $amount,
        string $currency = 'GBP',
        array $options = []
    ): array {
        try {
            // ⚠️ Validate amount is positive
            if ($amount <= 0) {
                return [
                    'success' => false,
                    'error'   => 'Payment amount must be greater than zero',
                ];
            }

            // 💱 Normalise currency to lowercase (Stripe requirement)
            $currency = strtolower(trim($currency));

            // 🔢 Convert amount to smallest currency unit
            $amountInSmallestUnit = self::toSmallestUnit($amount, $currency);

            // 🏷️ Get enabled payment method types from settings
            $paymentMethodsJSON = getSetting('payment.stripe.payment_methods');
            $paymentMethodTypes = [];

            if (!empty($paymentMethodsJSON)) {
                $decoded = json_decode((string)$paymentMethodsJSON, true);
                if (is_array($decoded)) {
                    $paymentMethodTypes = $decoded;
                }
            }

            // 🔗 Include Stripe Link if enabled
            if (getSetting('payment.stripe.link_enabled') === '1') {
                if (!in_array('link', $paymentMethodTypes, true)) {
                    $paymentMethodTypes[] = 'link';
                }
            }

            // 📋 Build Payment Intent parameters
            $params = [
                'amount'   => $amountInSmallestUnit,
                'currency' => $currency,
            ];

            // 🏷️ Payment method types
            if (!empty($paymentMethodTypes)) {
                foreach ($paymentMethodTypes as $index => $methodType) {
                    $params['payment_method_types[' . $index . ']'] = $methodType;
                }
            }

            // 👤 Customer ID
            if (!empty($options['customerID'])) {
                $params['customer'] = $options['customerID'];
            }

            // 📝 Description
            if (!empty($options['description'])) {
                $params['description'] = $options['description'];
            }

            // 📧 Receipt email
            if (!empty($options['receiptEmail'])) {
                $params['receipt_email'] = $options['receiptEmail'];
            }

            // 🏷️ Statement descriptor (max 22 characters)
            // @see https://docs.stripe.com/api/payment_intents/create#create_payment_intent-statement_descriptor
            if (!empty($options['statementDescriptor'])) {
                $params['statement_descriptor'] = substr($options['statementDescriptor'], 0, 22);
            }

            // 📎 Metadata
            if (!empty($options['metadata']) && is_array($options['metadata'])) {
                foreach ($options['metadata'] as $metaKey => $metaValue) {
                    $params['metadata[' . $metaKey . ']'] = (string)$metaValue;
                }
            }

            // 🚀 Send API request
            $idempotencyKey = $options['idempotencyKey'] ?? null;
            $response = self::apiRequest('POST', 'payment_intents', $params, $idempotencyKey);

            // ❌ Check for errors
            if (!empty($response['success']) && $response['success'] === false) {
                return $response;
            }

            if (!empty($response['error'])) {
                $errorMessage = is_array($response['error'])
                    ? ($response['error']['message'] ?? 'Unknown error')
                    : (string)$response['error'];

                ActivityLogger::log(
                    null,
                    'stripe_payment_intent_failed',
                    'payment',
                    'error',
                    'Failed to create Payment Intent: ' . $errorMessage,
                    ['amount' => $amount, 'currency' => $currency]
                );

                return [
                    'success' => false,
                    'error'   => 'Failed to create payment: ' . $errorMessage,
                ];
            }

            // ✅ Validate response
            if (empty($response['client_secret']) || empty($response['id'])) {
                return [
                    'success' => false,
                    'error'   => 'Invalid response from Stripe — missing client secret or intent ID',
                ];
            }

            // 📝 Log success
            ActivityLogger::log(
                null,
                'stripe_payment_intent_created',
                'payment',
                'info',
                'Stripe Payment Intent created: ' . strtoupper($currency) . ' ' . number_format($amount, 2),
                [
                    'paymentIntentID' => $response['id'],
                    'amount'          => $amount,
                    'currency'        => strtoupper($currency),
                    'status'          => $response['status'] ?? 'unknown',
                ]
            );

            return [
                'success'         => true,
                'clientSecret'    => $response['client_secret'],
                'paymentIntentID' => $response['id'],
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'stripe_payment_intent_exception',
                'payment',
                'critical',
                'Exception during Payment Intent creation: ' . $e->getMessage(),
                [
                    'amount'   => $amount,
                    'currency' => $currency,
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine(),
                ]
            );

            return [
                'success' => false,
                'error'   => 'An unexpected error occurred while creating the payment',
            ];
        }
    }

    // ========================================================================
    // 🔐 WEBHOOK HANDLING
    // ========================================================================

    /**
     * 🔏 Verify Stripe Webhook Signature
     *
     * Validates the Stripe-Signature header on incoming webhook events to ensure
     * they are genuine and haven't been tampered with. This is CRITICAL for security
     * — never process webhook events without verifying the signature first.
     *
     * The verification process:
     * 1. Parse the Stripe-Signature header to extract timestamp (t) and signature (v1)
     * 2. Compute expected signature: HMAC-SHA256(timestamp + '.' + payload, webhookSecret)
     * 3. Compare computed signature with received signature using timing-safe comparison
     * 4. Verify the timestamp is within tolerance (default: 300 seconds / 5 minutes)
     *
     * @param string $payload         The raw request body (NOT parsed JSON)
     * @param string $signatureHeader The value of the Stripe-Signature HTTP header
     *
     * @return bool True if the signature is valid and timestamp is within tolerance
     *
     * @see https://docs.stripe.com/webhooks/signatures
     * @see https://docs.stripe.com/webhooks/signatures#verify-manually
     * @see https://www.php.net/manual/en/function.hash-hmac.php
     * @see https://www.php.net/manual/en/function.hash-equals.php
     */
    public static function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        try {
            // 🔑 Get the webhook signing secret from settings (encrypted in DB)
            $encryptedSecret = getSetting('payment.stripe.webhook_secret');

            if (empty($encryptedSecret)) {
                ActivityLogger::log(
                    null,
                    'stripe_webhook_no_secret',
                    'payment',
                    'error',
                    'Stripe webhook secret is not configured in tblSettings',
                    []
                );
                return false;
            }

            // 🔓 Decrypt the webhook secret
            $secret = SecurityUtils::decrypt((string)$encryptedSecret);

            if (empty($secret)) {
                ActivityLogger::log(
                    null,
                    'stripe_webhook_secret_empty',
                    'payment',
                    'error',
                    'Stripe webhook secret decrypted to empty string',
                    []
                );
                return false;
            }

            // 📋 Parse the Stripe-Signature header
            // Format: t=1492774577,v1=5257a869e7ecebeda32affa62cdca3fa51cad7e77a0e56ff536d0ce8e108d8bd,...
            // @see https://docs.stripe.com/webhooks/signatures#verify-manually
            $timestamp = null;
            $signatures = [];

            $elements = explode(',', $signatureHeader);
            foreach ($elements as $element) {
                $parts = explode('=', $element, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);

                    if ($key === 't') {
                        $timestamp = (int)$value;
                    } elseif ($key === 'v1') {
                        $signatures[] = $value;
                    }
                }
            }

            // ⚠️ Validate that we extracted both timestamp and at least one v1 signature
            if ($timestamp === null || empty($signatures)) {
                ActivityLogger::log(
                    null,
                    'stripe_webhook_invalid_header',
                    'payment',
                    'warning',
                    'Stripe-Signature header is malformed — missing timestamp or v1 signature',
                    ['header' => substr($signatureHeader, 0, 100)] // 📌 Truncate for log safety
                );
                return false;
            }

            // 🔐 Compute the expected signature
            // signed_payload = timestamp + "." + payload
            // expected_signature = HMAC-SHA256(signed_payload, webhook_secret)
            $signedPayload = $timestamp . '.' . $payload;
            $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

            // 🔍 Check if any of the v1 signatures match using timing-safe comparison
            // hash_equals() prevents timing attacks by comparing in constant time
            // @see https://www.php.net/manual/en/function.hash-equals.php
            $signatureValid = false;
            foreach ($signatures as $sig) {
                if (hash_equals($expectedSignature, $sig)) {
                    $signatureValid = true;
                    break;
                }
            }

            if (!$signatureValid) {
                ActivityLogger::log(
                    null,
                    'stripe_webhook_signature_mismatch',
                    'payment',
                    'warning',
                    'Stripe webhook signature verification failed — signature does not match',
                    ['timestamp' => $timestamp]
                );
                return false;
            }

            // ⏰ Check timestamp tolerance to prevent replay attacks
            // The event must have been signed within the last WEBHOOK_TOLERANCE_SECONDS
            $currentTime = time();
            $timeDifference = abs($currentTime - $timestamp);

            if ($timeDifference > self::WEBHOOK_TOLERANCE_SECONDS) {
                ActivityLogger::log(
                    null,
                    'stripe_webhook_timestamp_expired',
                    'payment',
                    'warning',
                    'Stripe webhook timestamp outside tolerance — possible replay attack',
                    [
                        'eventTimestamp'   => $timestamp,
                        'currentTimestamp' => $currentTime,
                        'differenceSeconds' => $timeDifference,
                        'toleranceSeconds'  => self::WEBHOOK_TOLERANCE_SECONDS,
                    ]
                );
                return false;
            }

            // ✅ Signature and timestamp are valid
            return true;

        } catch (\Exception $e) {
            // 🚨 Catch decryption or unexpected errors
            ActivityLogger::log(
                null,
                'stripe_webhook_verify_exception',
                'payment',
                'error',
                'Exception during Stripe webhook signature verification: ' . $e->getMessage(),
                ['file' => $e->getFile(), 'line' => $e->getLine()]
            );
            return false;
        }
    }

    /**
     * 📬 Handle a Stripe Webhook Event
     *
     * Processes incoming Stripe webhook events and performs the corresponding
     * actions in the SIGNula system (activate subscriptions, complete payments, etc.).
     *
     * IMPORTANT: Always verify the webhook signature BEFORE calling this method.
     *
     * Supported event types:
     * - checkout.session.completed    — Checkout payment successful, activate subscription
     * - payment_intent.succeeded      — Payment Intent confirmed, complete payment record
     * - payment_intent.payment_failed — Payment failed, mark payment as failed
     * - invoice.paid / invoice.payment_succeeded — Recurring invoice paid — the RENEWAL path (G-002 S3)
     * - invoice.payment_failed        — Recurring invoice payment failed -> 'past_due' (G-002 S3)
     * - customer.subscription.updated — Subscription status changed in Stripe -> mirrored via the state machine (G-002 S3)
     * - customer.subscription.deleted — Subscription cancelled/expired in Stripe -> 'cancelled' (G-002 S3)
     * - charge.refunded               — Charge refunded (full or partial)
     *
     * G-002 Stage S3: every subscription-lifecycle case below resolves the
     * local subscription by `paymentProviderSubscriptionID` (B-072 fix — the
     * real column; `externalSubscriptionID` never existed) and routes the
     * requested change through PaymentManager::applyWebhookTransition()/
     * ::recordSubscriptionRenewal(), which validate the move against
     * SubscriptionStateMachine::VALID_TRANSITIONS before persisting.
     *
     * @param array $event The parsed Stripe event object (JSON decoded)
     *
     * @return array Associative array with:
     *               - 'success' (bool): Whether the event was processed
     *               - 'action' (string): Description of what was done
     *               - 'error' (string): Error message (on failure)
     *
     * @see https://docs.stripe.com/webhooks
     * @see https://docs.stripe.com/api/events/types
     */
    public static function handleWebhookEvent(array $event): array
    {
        // 🔍 Extract event type and data object
        $eventType = $event['type'] ?? '';
        $eventID = $event['id'] ?? 'unknown';
        $data = $event['data']['object'] ?? [];

        // 📝 Log every incoming webhook event for audit trail
        ActivityLogger::log(
            null,
            'stripe_webhook_received',
            'payment',
            'info',
            'Stripe webhook event received: ' . $eventType,
            [
                'eventID'   => $eventID,
                'eventType' => $eventType,
                'objectID'  => $data['id'] ?? 'unknown',
            ]
        );

        try {
            // 🔀 Route to appropriate handler based on event type
            // @see https://docs.stripe.com/api/events/types
            switch ($eventType) {

                // ============================================================
                // ✅ checkout.session.completed
                // Fired when a Checkout Session payment is successful
                // @see https://docs.stripe.com/api/events/types#event_types-checkout.session.completed
                // ============================================================
                case 'checkout.session.completed':
                    return self::handleCheckoutSessionCompleted($data, $eventID);

                // ============================================================
                // ✅ payment_intent.succeeded
                // Fired when a Payment Intent is successfully confirmed/paid
                // @see https://docs.stripe.com/api/events/types#event_types-payment_intent.succeeded
                // ============================================================
                case 'payment_intent.succeeded':
                    return self::handlePaymentIntentSucceeded($data, $eventID);

                // ============================================================
                // ❌ payment_intent.payment_failed
                // Fired when a Payment Intent fails
                // @see https://docs.stripe.com/api/events/types#event_types-payment_intent.payment_failed
                // ============================================================
                case 'payment_intent.payment_failed':
                    return self::handlePaymentIntentFailed($data, $eventID);

                // ============================================================
                // 🔄 invoice.paid
                // Fired when a subscription invoice is successfully paid
                // @see https://docs.stripe.com/api/events/types#event_types-invoice.paid
                // ============================================================
                case 'invoice.paid':
                    return self::handleInvoicePaid($data, $eventID);

                // ============================================================
                // 🔄 invoice.payment_succeeded
                // Legacy/alias renewal-success event Stripe still emits
                // alongside invoice.paid for a subscription invoice — same
                // handler (G-002 spec §7 / task S3).
                // @see https://docs.stripe.com/api/events/types#event_types-invoice.payment_succeeded
                // ============================================================
                case 'invoice.payment_succeeded':
                    return self::handleInvoicePaid($data, $eventID);

                // ============================================================
                // ❌ invoice.payment_failed
                // Fired when a subscription's recurring invoice payment fails
                // -> dunning start ('past_due') — G-002 S3 (this case did not
                // previously exist at all).
                // @see https://docs.stripe.com/api/events/types#event_types-invoice.payment_failed
                // ============================================================
                case 'invoice.payment_failed':
                    return self::handleInvoicePaymentFailed($data, $eventID);

                // ============================================================
                // 🔄 customer.subscription.updated
                // Fired when a subscription's status or details change
                // @see https://docs.stripe.com/api/events/types#event_types-customer.subscription.updated
                // ============================================================
                case 'customer.subscription.updated':
                    return self::handleSubscriptionUpdated($data, $eventID);

                // ============================================================
                // ❌ customer.subscription.deleted
                // Fired when a subscription is cancelled or expired
                // @see https://docs.stripe.com/api/events/types#event_types-customer.subscription.deleted
                // ============================================================
                case 'customer.subscription.deleted':
                    return self::handleSubscriptionDeleted($data, $eventID);

                // ============================================================
                // 💸 charge.refunded
                // Fired when a charge is refunded (full or partial)
                // @see https://docs.stripe.com/api/events/types#event_types-charge.refunded
                // ============================================================
                case 'charge.refunded':
                    return self::handleChargeRefunded($data, $eventID);

                // ============================================================
                // ❓ Unhandled event type
                // ============================================================
                default:
                    ActivityLogger::log(
                        null,
                        'stripe_webhook_unhandled',
                        'payment',
                        'info',
                        'Unhandled Stripe webhook event type: ' . $eventType,
                        ['eventID' => $eventID, 'eventType' => $eventType]
                    );

                    return [
                        'success' => true,
                        'action'  => 'ignored',
                        'message' => 'Event type not handled: ' . $eventType,
                    ];
            }

        } catch (\Exception $e) {
            // 🚨 Catch-all for unexpected errors during event processing
            ActivityLogger::log(
                null,
                'stripe_webhook_exception',
                'payment',
                'critical',
                'Exception processing Stripe webhook event: ' . $e->getMessage(),
                [
                    'eventID'   => $eventID,
                    'eventType' => $eventType,
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]
            );

            return [
                'success' => false,
                'error'   => 'Failed to process webhook event',
            ];
        }
    }

    // ========================================================================
    // 💸 REFUNDS
    // ========================================================================

    /**
     * 💸 Create a Refund
     *
     * Refunds a payment (fully or partially) via the Stripe Refunds API.
     * If no amount is specified, the entire payment is refunded.
     *
     * @param string      $paymentIntentID The Stripe Payment Intent ID (pi_...) to refund
     * @param int|null     $amountInCents   Amount to refund in smallest currency unit (null = full refund)
     * @param string|null  $reason          Refund reason: 'duplicate', 'fraudulent', or 'requested_by_customer'
     *
     * @return array Associative array with:
     *               - 'success' (bool): Whether the refund was created
     *               - 'refundID' (string): Stripe Refund ID (on success)
     *               - 'status' (string): Refund status (on success)
     *               - 'error' (string): Error message (on failure)
     *
     * @see https://docs.stripe.com/api/refunds/create
     * @see https://docs.stripe.com/refunds
     */
    public static function refund(
        string $paymentIntentID,
        ?int $amountInCents = null,
        ?string $reason = null
    ): array {
        try {
            // 🛡️ TEST-MODE GUARD (G-002 §0/§9.1) — see createCheckoutSession()
            // above for the full rationale. Checked first, before any Stripe API call.
            BillingMode::assertTestMode('stripe');

            // 📋 Build refund parameters
            $params = [
                'payment_intent' => $paymentIntentID,
            ];

            // 💰 Partial refund amount (in smallest currency unit)
            if ($amountInCents !== null && $amountInCents > 0) {
                $params['amount'] = $amountInCents;
            }

            // 📝 Refund reason (Stripe accepts specific values)
            // @see https://docs.stripe.com/api/refunds/create#create_refund-reason
            if ($reason !== null) {
                $validReasons = ['duplicate', 'fraudulent', 'requested_by_customer'];
                if (in_array($reason, $validReasons, true)) {
                    $params['reason'] = $reason;
                }
            }

            // 🚀 Send refund request
            $response = self::apiRequest('POST', 'refunds', $params);

            // ❌ Check for errors
            if (!empty($response['success']) && $response['success'] === false) {
                return $response;
            }

            if (!empty($response['error'])) {
                $errorMessage = is_array($response['error'])
                    ? ($response['error']['message'] ?? 'Unknown error')
                    : (string)$response['error'];

                ActivityLogger::log(
                    null,
                    'stripe_refund_failed',
                    'payment',
                    'error',
                    'Stripe refund failed: ' . $errorMessage,
                    ['paymentIntentID' => $paymentIntentID, 'amountInCents' => $amountInCents]
                );

                return [
                    'success' => false,
                    'error'   => 'Refund failed: ' . $errorMessage,
                ];
            }

            // ✅ Log success
            ActivityLogger::log(
                null,
                'stripe_refund_created',
                'payment',
                'info',
                'Stripe refund processed for Payment Intent: ' . $paymentIntentID,
                [
                    'refundID'        => $response['id'] ?? 'unknown',
                    'paymentIntentID' => $paymentIntentID,
                    'amountRefunded'  => $response['amount'] ?? $amountInCents,
                    'status'          => $response['status'] ?? 'unknown',
                ]
            );

            return [
                'success'  => true,
                'refundID' => $response['id'] ?? null,
                'status'   => $response['status'] ?? 'unknown',
                'amount'   => $response['amount'] ?? $amountInCents,
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'stripe_refund_exception',
                'payment',
                'critical',
                'Exception during Stripe refund: ' . $e->getMessage(),
                ['paymentIntentID' => $paymentIntentID, 'file' => $e->getFile(), 'line' => $e->getLine()]
            );

            return [
                'success' => false,
                'error'   => 'An unexpected error occurred while processing the refund',
            ];
        }
    }

    // ========================================================================
    // 👤 CUSTOMER MANAGEMENT
    // ========================================================================

    /**
     * 🔍 Get a Stripe Customer by Email
     *
     * Searches for an existing Stripe customer record by email address.
     * Returns the first matching customer or null if none found.
     *
     * @param string $email Customer email address to search for
     *
     * @return array|null Customer data array or null if not found
     *
     * @see https://docs.stripe.com/api/customers/list
     */
    public static function getCustomerByEmail(string $email): ?array
    {
        try {
            // 🔍 Search customers by email (limit to 1 result)
            $response = self::apiRequest('GET', 'customers', [
                'email' => $email,
                'limit' => 1,
            ]);

            // ❌ Check for errors
            if (!empty($response['success']) && $response['success'] === false) {
                return null;
            }

            if (!empty($response['error'])) {
                return null;
            }

            // 🔍 Check if any customers were returned
            if (!empty($response['data']) && is_array($response['data']) && count($response['data']) > 0) {
                return $response['data'][0];
            }

            return null;

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'stripe_customer_lookup_error',
                'payment',
                'error',
                'Failed to look up Stripe customer by email: ' . $e->getMessage(),
                ['email' => $email]
            );
            return null;
        }
    }

    /**
     * ➕ Create a Stripe Customer
     *
     * Creates a new customer record in Stripe. Customer records allow you to
     * track multiple charges and subscriptions for the same customer.
     *
     * @param string $email    Customer email address
     * @param string $name     Customer full name
     * @param array  $metadata Optional additional metadata to store on the customer
     *
     * @return array Associative array with:
     *               - 'success' (bool): Whether the customer was created
     *               - 'customerID' (string): Stripe Customer ID (on success)
     *               - 'error' (string): Error message (on failure)
     *
     * @see https://docs.stripe.com/api/customers/create
     */
    public static function createCustomer(
        string $email,
        string $name,
        array $metadata = []
    ): array {
        try {
            // 📋 Build customer parameters
            $params = [
                'email' => $email,
                'name'  => $name,
            ];

            // 📎 Add metadata if provided
            if (!empty($metadata)) {
                foreach ($metadata as $metaKey => $metaValue) {
                    $params['metadata[' . $metaKey . ']'] = (string)$metaValue;
                }
            }

            // 🚀 Create customer via API
            $response = self::apiRequest('POST', 'customers', $params);

            // ❌ Check for errors
            if (!empty($response['success']) && $response['success'] === false) {
                return $response;
            }

            if (!empty($response['error'])) {
                $errorMessage = is_array($response['error'])
                    ? ($response['error']['message'] ?? 'Unknown error')
                    : (string)$response['error'];

                return [
                    'success' => false,
                    'error'   => 'Failed to create Stripe customer: ' . $errorMessage,
                ];
            }

            // ✅ Validate response
            if (empty($response['id'])) {
                return [
                    'success' => false,
                    'error'   => 'Invalid response from Stripe — missing customer ID',
                ];
            }

            // 📝 Log success
            ActivityLogger::log(
                null,
                'stripe_customer_created',
                'payment',
                'info',
                'Stripe customer created: ' . $email,
                [
                    'customerID' => $response['id'],
                    'email'      => $email,
                    'name'       => $name,
                ]
            );

            return [
                'success'    => true,
                'customerID' => $response['id'],
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'stripe_customer_create_error',
                'payment',
                'error',
                'Failed to create Stripe customer: ' . $e->getMessage(),
                ['email' => $email, 'name' => $name]
            );

            return [
                'success' => false,
                'error'   => 'An unexpected error occurred while creating the customer',
            ];
        }
    }

    // ========================================================================
    // 🏦 BALANCE & CONNECTIVITY
    // ========================================================================

    /**
     * 🏦 Get Stripe Account Balance
     *
     * Retrieves the current balance of the connected Stripe account.
     * Primarily used as a connectivity/health check to verify that
     * the API key is valid and the Stripe API is reachable.
     *
     * @return array Associative array with:
     *               - 'success' (bool): Whether the balance was retrieved
     *               - 'balance' (array): Stripe Balance object (on success)
     *               - 'error' (string): Error message (on failure)
     *
     * @see https://docs.stripe.com/api/balance/balance_retrieve
     */
    public static function getBalance(): array
    {
        try {
            // 📡 Retrieve balance (simple GET, no parameters)
            $response = self::apiRequest('GET', 'balance');

            // ❌ Check for errors
            if (!empty($response['success']) && $response['success'] === false) {
                return $response;
            }

            if (!empty($response['error'])) {
                $errorMessage = is_array($response['error'])
                    ? ($response['error']['message'] ?? 'Unknown error')
                    : (string)$response['error'];

                return [
                    'success' => false,
                    'error'   => 'Failed to retrieve Stripe balance: ' . $errorMessage,
                ];
            }

            return [
                'success' => true,
                'balance' => $response,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => 'Failed to connect to Stripe API: ' . $e->getMessage(),
            ];
        }
    }

    // ========================================================================
    // 🔧 PRIVATE HELPERS — API Communication
    // ========================================================================

    /**
     * 📡 Make an authenticated API request to Stripe
     *
     * Central method for all Stripe API communication. Handles:
     * - Authentication via Bearer token
     * - Request building (form-encoded for POST, query string for GET)
     * - Idempotency keys for safe retries
     * - cURL configuration with SSL verification
     * - Response parsing and error extraction
     * - Comprehensive error logging
     *
     * @param string      $method         HTTP method: 'GET', 'POST', 'DELETE'
     * @param string      $endpoint       Stripe API endpoint (without base URL), e.g., 'checkout/sessions'
     * @param array       $data           Request parameters (form-encoded for POST, query params for GET)
     * @param string|null $idempotencyKey Optional idempotency key for POST requests to prevent duplicate charges
     *
     * @return array Decoded JSON response from Stripe, or error array on failure
     *
     * @see https://docs.stripe.com/api#authentication
     * @see https://docs.stripe.com/api/idempotent-requests
     * @see https://www.php.net/manual/en/book.curl.php
     */
    private static function apiRequest(
        string $method,
        string $endpoint,
        array $data = [],
        ?string $idempotencyKey = null,
        ?array $credentials = null
    ): array {
        // 🔑 Resolve the Stripe secret key — either from partner credentials or global settings
        // Partner credentials are provided when processing Level 2 payments (Option 2a: own_keys)
        // @see PartnerPaymentService::resolveApiKeys() for credential resolution logic
        $secretKey = null;

        if ($credentials !== null && !empty($credentials['secret_key'])) {
            // 🏢 Using partner-provided API key (Level 2, Option 2a: own_keys)
            // The key has already been decrypted by PartnerPaymentService::resolveApiKeys()
            $secretKey = $credentials['secret_key'];
        } else {
            // 🌐 Using SIGNula's global Stripe API key (Level 1 or Level 2 Option 2b: signula_keys)
            $encryptedKey = getSetting('payment.stripe.secret_key');

            if (empty($encryptedKey)) {
                ActivityLogger::log(
                    null,
                    'stripe_api_no_key',
                    'payment',
                    'error',
                    'Stripe API request failed — no secret key configured',
                    ['endpoint' => $endpoint, 'method' => $method]
                );

                return [
                    'success' => false,
                    'error'   => 'Stripe API key is not configured',
                ];
            }

            // 🔓 Decrypt the secret key
            try {
                $secretKey = SecurityUtils::decrypt((string)$encryptedKey);
            } catch (\Exception $e) {
                ActivityLogger::log(
                    null,
                    'stripe_api_key_decrypt_failed',
                    'payment',
                    'error',
                    'Failed to decrypt Stripe secret key: ' . $e->getMessage(),
                    ['endpoint' => $endpoint]
                );

                return [
                    'success' => false,
                    'error'   => 'Failed to decrypt Stripe API credentials',
                ];
            }
        }

        if (empty(trim($secretKey))) {
            return [
                'success' => false,
                'error'   => 'Stripe API key is empty after decryption',
            ];
        }

        // 🌐 Build the full API URL
        // Remove any leading slash from endpoint to avoid double-slash in URL
        $endpoint = ltrim($endpoint, '/');
        $url = self::STRIPE_API_BASE . $endpoint;

        // 📋 Build request headers
        // @see https://docs.stripe.com/api#authentication
        $headers = [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . self::USER_AGENT,
        ];

        // 🔑 Add idempotency key header for POST requests (prevents duplicate charges)
        // @see https://docs.stripe.com/api/idempotent-requests
        if ($idempotencyKey !== null && strtoupper($method) === 'POST') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        // 📡 Initialise cURL handle
        // @see https://www.php.net/manual/en/function.curl-init.php
        $ch = curl_init();

        if ($ch === false) {
            ActivityLogger::log(
                null,
                'stripe_api_curl_init_failed',
                'payment',
                'critical',
                'Failed to initialise cURL handle for Stripe API request',
                ['endpoint' => $endpoint]
            );

            return [
                'success' => false,
                'error'   => 'Failed to initialise HTTP client',
            ];
        }

        // 🔧 Configure cURL options based on HTTP method
        $httpMethod = strtoupper($method);

        if ($httpMethod === 'GET') {
            // 📤 For GET requests, append data as query string parameters
            if (!empty($data)) {
                $queryString = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
                $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
            }
        } elseif ($httpMethod === 'POST') {
            // 📤 For POST requests, send data as form-encoded body
            // Stripe uses application/x-www-form-urlencoded, not JSON
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                // http_build_query() handles nested arrays with bracket notation
                // which is exactly what Stripe expects for nested params
                // @see https://www.php.net/manual/en/function.http-build-query.php
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&', PHP_QUERY_RFC3986));
            }
        } elseif ($httpMethod === 'DELETE') {
            // 🗑️ For DELETE requests
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&', PHP_QUERY_RFC3986));
            }
        }

        // 🔧 Set core cURL options
        curl_setopt_array($ch, [
            // 🌐 Target URL
            CURLOPT_URL            => $url,

            // 📥 Return response as string instead of outputting directly
            // @see https://www.php.net/manual/en/function.curl-setopt.php (CURLOPT_RETURNTRANSFER)
            CURLOPT_RETURNTRANSFER => true,

            // 📋 Request headers
            CURLOPT_HTTPHEADER     => $headers,

            // ⏱️ Timeouts — prevent hanging requests
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,

            // 🔐 SSL verification — MUST be enabled for payment processing
            // Never disable this in production — it protects against MITM attacks
            // @see https://curl.se/docs/sslcerts.html
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            // 🔀 Follow redirects (Stripe may redirect in some cases)
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,

            // 📊 Include response headers for debugging (we extract HTTP status from them)
            CURLOPT_HEADER         => false,
        ]);

        // 🚀 Execute the cURL request
        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        // 🔒 Close the cURL handle to free resources
        curl_close($ch);

        // ❌ Check for cURL-level errors (network issues, DNS failures, timeouts, etc.)
        if ($curlErrno !== 0) {
            ActivityLogger::log(
                null,
                'stripe_api_curl_error',
                'payment',
                'error',
                'cURL error during Stripe API request: [' . $curlErrno . '] ' . $curlError,
                [
                    'endpoint'  => $endpoint,
                    'method'    => $httpMethod,
                    'curlErrno' => $curlErrno,
                    'curlError' => $curlError,
                ]
            );

            return [
                'success' => false,
                'error'   => 'Network error communicating with payment provider (cURL error: ' . $curlErrno . ')',
            ];
        }

        // 📋 Decode the JSON response
        // @see https://www.php.net/manual/en/function.json-decode.php
        $decoded = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            ActivityLogger::log(
                null,
                'stripe_api_json_error',
                'payment',
                'error',
                'Failed to decode Stripe API response as JSON: ' . json_last_error_msg(),
                [
                    'endpoint' => $endpoint,
                    'method'   => $httpMethod,
                    'httpCode' => $httpCode,
                    'body'     => substr((string)$responseBody, 0, 500), // 📌 Truncate for safety
                ]
            );

            return [
                'success' => false,
                'error'   => 'Invalid response from payment provider',
            ];
        }

        // ❌ Check for Stripe API errors (HTTP 4xx/5xx)
        // Stripe returns errors in the format: { "error": { "type": "...", "message": "..." } }
        // @see https://docs.stripe.com/api/errors
        if ($httpCode >= 400) {
            $stripeError = $decoded['error'] ?? [];
            $errorType = $stripeError['type'] ?? 'api_error';
            $errorMessage = $stripeError['message'] ?? 'Unknown Stripe API error';
            $errorCode = $stripeError['code'] ?? null;
            $errorParam = $stripeError['param'] ?? null;

            // 🔍 Determine log severity based on HTTP status code
            $severity = match (true) {
                $httpCode === 401 => 'critical',  // 🔑 Authentication failure
                $httpCode === 429 => 'warning',   // ⏰ Rate limited
                $httpCode >= 500  => 'critical',  // 🚨 Stripe server error
                default           => 'error',
            };

            ActivityLogger::log(
                null,
                'stripe_api_error',
                'payment',
                $severity,
                'Stripe API error [' . $httpCode . ']: ' . $errorMessage,
                [
                    'endpoint'     => $endpoint,
                    'method'       => $httpMethod,
                    'httpCode'     => $httpCode,
                    'errorType'    => $errorType,
                    'errorCode'    => $errorCode,
                    'errorParam'   => $errorParam,
                    'errorMessage' => $errorMessage,
                ]
            );

            return [
                'success' => false,
                'error'   => $decoded['error'] ?? ['message' => $errorMessage],
            ];
        }

        // ✅ Return the decoded response
        return $decoded ?? [];
    }

    // ========================================================================
    // 🔧 PRIVATE HELPERS — Currency Conversion
    // ========================================================================

    /**
     * 💱 Convert Amount to Smallest Currency Unit
     *
     * Stripe requires amounts in the smallest currency unit (e.g., pence for GBP,
     * cents for USD). This method handles the conversion, including special handling
     * for zero-decimal currencies like JPY.
     *
     * @param float  $amount   Amount in major currency units (e.g., 10.50)
     * @param string $currency ISO 4217 currency code (e.g., 'gbp', 'usd', 'jpy')
     *
     * @return int Amount in smallest currency unit (e.g., 1050 for GBP 10.50)
     *
     * @see https://docs.stripe.com/currencies#zero-decimal
     * @see https://docs.stripe.com/currencies#minimum-and-maximum-charge-amounts
     */
    private static function toSmallestUnit(float $amount, string $currency = 'GBP'): int
    {
        // 🔠 Normalise currency to uppercase for comparison with zero-decimal list
        $currencyUpper = strtoupper(trim($currency));

        // 🔍 Check if this is a zero-decimal currency
        // Zero-decimal currencies don't use fractional units — 1 JPY = 1 unit
        // @see https://docs.stripe.com/currencies#zero-decimal
        if (in_array($currencyUpper, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int)round($amount);
        }

        // 💰 Standard currencies: multiply by 100 to convert to smallest unit
        // e.g., GBP 10.50 => 1050 pence, USD 25.99 => 2599 cents
        return (int)round($amount * 100);
    }

    // ========================================================================
    // 🔧 PRIVATE HELPERS — Webhook Event Handlers
    // ========================================================================

    /**
     * ✅ Handle checkout.session.completed event
     *
     * Called when a Stripe Checkout Session is successfully completed. This is
     * the primary event for activating subscriptions and completing payments
     * initiated via createCheckoutSession().
     *
     * @param array  $session The checkout session object from the event data
     * @param string $eventID The Stripe event ID for logging
     *
     * @return array Processing result
     *
     * @see https://docs.stripe.com/api/checkout/sessions/object
     */
    private static function handleCheckoutSessionCompleted(array $session, string $eventID): array
    {
        // 📎 Extract SIGNula metadata that was attached during session creation
        $metadata = $session['metadata'] ?? [];
        $userID = isset($metadata['signula_user_id']) ? (int)$metadata['signula_user_id'] : null;
        $tierID = isset($metadata['signula_tier_id']) ? (int)$metadata['signula_tier_id'] : null;
        $billingCycle = $metadata['signula_billing_cycle'] ?? 'monthly';

        // 📝 Extract payment details from the session
        $stripeSessionID = $session['id'] ?? '';
        $paymentIntentID = $session['payment_intent'] ?? null;
        $subscriptionID = $session['subscription'] ?? null;
        $customerEmail = $session['customer_details']['email'] ?? ($session['customer_email'] ?? '');
        $amountTotal = $session['amount_total'] ?? 0;
        $currency = strtoupper($session['currency'] ?? 'GBP');
        $paymentStatus = $session['payment_status'] ?? '';

        // ⚠️ Verify we have the required SIGNula metadata
        if ($userID === null || $tierID === null) {
            ActivityLogger::log(
                null,
                'stripe_checkout_missing_metadata',
                'payment',
                'warning',
                'Checkout session completed but missing SIGNula metadata — cannot process',
                [
                    'eventID'          => $eventID,
                    'stripeSessionID'  => $stripeSessionID,
                    'metadata'         => $metadata,
                ]
            );

            return [
                'success' => false,
                'error'   => 'Missing required metadata (userID/tierID) on checkout session',
            ];
        }

        // 💾 Find and complete the pending payment record in our database
        // The payment was recorded with the session ID as transactionID in createCheckoutSession()
        $pendingPayment = Database::fetchOne(
            "SELECT paymentID FROM tblPayments WHERE transactionID = ? AND status = 'pending'",
            [$stripeSessionID],
            's'
        );

        if ($pendingPayment) {
            // ✅ Complete the payment record with the actual Payment Intent ID
            PaymentManager::completePayment(
                (int)$pendingPayment['paymentID'],
                is_string($paymentIntentID) ? $paymentIntentID : $stripeSessionID,
                null // 📌 Receipt URL can be fetched later from the charge
            );
        }

        // 📦 Create or activate the subscription in SIGNula
        if ($session['mode'] === 'subscription' && $tierID) {
            // 🔄 Create subscription via PaymentManager
            $subResult = PaymentManager::createSubscription(
                $userID,
                $tierID,
                $billingCycle,
                'stripe',
                [
                    'partnerID' => null,
                    'trialDays' => 0, // 📌 Trial would have already started via Stripe
                ]
            );

            // 📝 Store the Stripe subscription ID in our database for future reference
            // 🩹 B-072: the real tblSubscriptions column is
            // `paymentProviderSubscriptionID` — `externalSubscriptionID` has
            // never existed (grep-confirmed, phantom column). Before this
            // fix, EVERY Stripe subscription created via Checkout silently
            // failed to persist its Stripe subscription id here (an "Unknown
            // column" mysqli_sql_exception, non-fatal to the caller since
            // this whole checkout-completion flow is otherwise
            // non-transactional), which in turn meant every subsequent
            // invoice.paid/customer.subscription.updated/.deleted webhook
            // lookup for that subscription could never find it either.
            if ($subResult['success'] && !empty($subResult['subscriptionID']) && $subscriptionID) {
                Database::query(
                    "UPDATE tblSubscriptions SET paymentProviderSubscriptionID = ? WHERE subscriptionID = ?",
                    [$subscriptionID, $subResult['subscriptionID']],
                    'si'
                );
            }
        }

        // 📝 Log the successful checkout completion
        ActivityLogger::log(
            $userID,
            'stripe_checkout_completed',
            'payment',
            'info',
            'Stripe Checkout completed — ' . $currency . ' ' . number_format($amountTotal / 100, 2),
            [
                'eventID'          => $eventID,
                'stripeSessionID'  => $stripeSessionID,
                'paymentIntentID'  => $paymentIntentID,
                'subscriptionID'   => $subscriptionID,
                'tierID'           => $tierID,
                'billingCycle'     => $billingCycle,
                'amountTotal'      => $amountTotal,
                'currency'         => $currency,
                'paymentStatus'    => $paymentStatus,
                'customerEmail'    => $customerEmail,
            ]
        );

        return [
            'success' => true,
            'action'  => 'checkout_completed',
            'message' => 'Checkout session processed and subscription activated for user ' . $userID,
        ];
    }

    /**
     * ✅ Handle payment_intent.succeeded event
     *
     * Called when a Payment Intent is successfully confirmed. Completes the
     * corresponding payment record in SIGNula's database.
     *
     * @param array  $paymentIntent The Payment Intent object from the event data
     * @param string $eventID       The Stripe event ID for logging
     *
     * @return array Processing result
     *
     * @see https://docs.stripe.com/api/payment_intents/object
     */
    private static function handlePaymentIntentSucceeded(array $paymentIntent, string $eventID): array
    {
        $paymentIntentID = $paymentIntent['id'] ?? '';
        $amount = $paymentIntent['amount'] ?? 0;
        $currency = strtoupper($paymentIntent['currency'] ?? 'GBP');
        $metadata = $paymentIntent['metadata'] ?? [];

        // 🔍 Find the pending payment record by transaction ID
        $pendingPayment = Database::fetchOne(
            "SELECT paymentID FROM tblPayments WHERE transactionID = ? AND status = 'pending'",
            [$paymentIntentID],
            's'
        );

        if ($pendingPayment) {
            // ✅ Mark payment as completed
            // Extract receipt URL from the latest charge if available
            $receiptURL = null;
            if (!empty($paymentIntent['latest_charge']) && is_string($paymentIntent['latest_charge'])) {
                // 📌 The latest_charge might be an ID — we'd need another API call to get receipt_url
                // For now, we'll leave it null and it can be fetched separately if needed
            }

            PaymentManager::completePayment(
                (int)$pendingPayment['paymentID'],
                $paymentIntentID,
                $receiptURL
            );
        }

        // 📝 Log the successful payment
        ActivityLogger::log(
            isset($metadata['signula_user_id']) ? (int)$metadata['signula_user_id'] : null,
            'stripe_payment_succeeded',
            'payment',
            'info',
            'Stripe Payment Intent succeeded — ' . $currency . ' ' . number_format($amount / 100, 2),
            [
                'eventID'         => $eventID,
                'paymentIntentID' => $paymentIntentID,
                'amount'          => $amount,
                'currency'        => $currency,
            ]
        );

        return [
            'success' => true,
            'action'  => 'payment_completed',
            'message' => 'Payment intent succeeded: ' . $paymentIntentID,
        ];
    }

    /**
     * ❌ Handle payment_intent.payment_failed event
     *
     * Called when a Payment Intent fails. Marks the corresponding payment
     * record in SIGNula as failed and logs the failure reason.
     *
     * @param array  $paymentIntent The Payment Intent object from the event data
     * @param string $eventID       The Stripe event ID for logging
     *
     * @return array Processing result
     *
     * @see https://docs.stripe.com/api/payment_intents/object
     */
    private static function handlePaymentIntentFailed(array $paymentIntent, string $eventID): array
    {
        $paymentIntentID = $paymentIntent['id'] ?? '';
        $amount = $paymentIntent['amount'] ?? 0;
        $currency = strtoupper($paymentIntent['currency'] ?? 'GBP');
        $metadata = $paymentIntent['metadata'] ?? [];

        // 🔍 Extract the failure reason from the last payment error
        // @see https://docs.stripe.com/api/payment_intents/object#payment_intent_object-last_payment_error
        $lastError = $paymentIntent['last_payment_error'] ?? [];
        $failureMessage = $lastError['message'] ?? 'Payment failed';
        $failureCode = $lastError['code'] ?? null;

        // 🔍 Find the pending payment record
        $pendingPayment = Database::fetchOne(
            "SELECT paymentID FROM tblPayments WHERE transactionID = ? AND status = 'pending'",
            [$paymentIntentID],
            's'
        );

        if ($pendingPayment) {
            // ❌ Mark payment as failed
            Database::query(
                "UPDATE tblPayments SET status = 'failed', metadata = JSON_SET(COALESCE(metadata, '{}'), '$.failure_reason', ?, '$.failure_code', ?) WHERE paymentID = ?",
                [$failureMessage, $failureCode ?? '', (int)$pendingPayment['paymentID']],
                'ssi'
            );
        }

        // 📝 Log the payment failure
        ActivityLogger::log(
            isset($metadata['signula_user_id']) ? (int)$metadata['signula_user_id'] : null,
            'stripe_payment_failed',
            'payment',
            'warning',
            'Stripe Payment Intent failed — ' . $currency . ' ' . number_format($amount / 100, 2) . ': ' . $failureMessage,
            [
                'eventID'         => $eventID,
                'paymentIntentID' => $paymentIntentID,
                'amount'          => $amount,
                'currency'        => $currency,
                'failureMessage'  => $failureMessage,
                'failureCode'     => $failureCode,
            ]
        );

        return [
            'success' => true,
            'action'  => 'payment_failed',
            'message' => 'Payment failure recorded for intent: ' . $paymentIntentID,
        ];
    }

    /**
     * 🔄 Handle invoice.paid / invoice.payment_succeeded event
     *
     * Called when a subscription invoice is successfully paid. This event
     * fires for recurring subscription payments — the RENEWAL path (G-002
     * spec §3.1/§5.1/§7). Delegates the actual record-payment + invoice +
     * precise period-advance + pending-tier-change + state-transition work
     * to PaymentManager::recordSubscriptionRenewal() (shared with
     * PayPalProvider's PAYMENT.SALE.COMPLETED case — G-002 §7's "shared
     * helper to keep logic in one place").
     *
     * 🩹 G-002 S3 fix: this previously looked up the local subscription by
     * the phantom `externalSubscriptionID` column (B-072) — always an
     * "Unknown column" SQL error — and, even had that resolved, only ever
     * called PaymentManager::recordPayment() (never completePayment()), so
     * the payment stayed 'pending' forever and NO renewal invoice was ever
     * actually issued. Both are fixed by routing through
     * recordSubscriptionRenewal() below.
     *
     * @param array  $invoice The Invoice object from the event data
     * @param string $eventID The Stripe event ID for logging
     *
     * @return array Processing result
     *
     * @see https://docs.stripe.com/api/invoices/object
     */
    private static function handleInvoicePaid(array $invoice, string $eventID): array
    {
        $invoiceID = $invoice['id'] ?? '';
        $stripeSubID = $invoice['subscription'] ?? null;
        $amountPaid = $invoice['amount_paid'] ?? 0;
        $currency = strtoupper($invoice['currency'] ?? 'GBP');
        $customerEmail = $invoice['customer_email'] ?? '';

        // 🔍 An invoice not tied to a subscription (a one-off Stripe
        // invoice) has nothing to renew — PAYMENT.CAPTURE/payment_intent
        // events cover one-off payments.
        if (!$stripeSubID) {
            ActivityLogger::log(
                null,
                'stripe_invoice_paid',
                'payment',
                'info',
                'Stripe invoice paid (not subscription-linked) — ' . $currency . ' ' . number_format($amountPaid / 100, 2),
                ['eventID' => $eventID, 'invoiceID' => $invoiceID]
            );

            return ['success' => true, 'action' => 'invoice_paid_no_subscription', 'message' => 'Invoice paid — not linked to a subscription'];
        }

        // 🔍 Find the SIGNula subscription linked to this Stripe subscription
        // (B-072 fix: paymentProviderSubscriptionID, not externalSubscriptionID)
        $subscription = Database::fetchOne(
            "SELECT subscriptionID, userID FROM tblSubscriptions WHERE paymentProviderSubscriptionID = ? ORDER BY createdAt DESC LIMIT 1",
            [$stripeSubID],
            's'
        );

        if (!$subscription) {
            ActivityLogger::log(
                null,
                'stripe_subscription_not_found',
                'payment',
                'warning',
                'Stripe invoice paid for an unknown subscription — ignored',
                ['eventID' => $eventID, 'invoiceID' => $invoiceID, 'stripeSubID' => $stripeSubID]
            );

            return ['success' => true, 'action' => 'ignored', 'message' => 'No matching local subscription — ignored'];
        }

        $renewalResult = PaymentManager::recordSubscriptionRenewal(
            (int)$subscription['subscriptionID'],
            'stripe',
            $amountPaid / 100, // 📌 Convert from smallest unit back to major unit
            $currency,
            $invoiceID,
            'stripe:' . $eventID,
            'Stripe invoice.paid (renewal)'
        );

        // 📝 Log the invoice payment
        ActivityLogger::log(
            (int)$subscription['userID'],
            'stripe_invoice_paid',
            'payment',
            'info',
            'Stripe invoice paid — ' . $currency . ' ' . number_format($amountPaid / 100, 2),
            [
                'eventID'        => $eventID,
                'invoiceID'      => $invoiceID,
                'subscriptionID' => $subscription['subscriptionID'],
                'amountPaid'     => $amountPaid,
                'currency'       => $currency,
                'customerEmail'  => $customerEmail,
            ]
        );

        return [
            'success' => $renewalResult['success'] ?? false,
            'action'  => 'invoice_paid',
            'message' => $renewalResult['message'] ?? 'Invoice paid and subscription renewed',
        ];
    }

    /**
     * ❌ Handle invoice.payment_failed event
     *
     * Called when a subscription's recurring invoice payment fails —
     * enters dunning ('past_due') per G-002 spec §7. (G-002 S3 — this case
     * did not previously exist at all.)
     *
     * @param array  $invoice The Invoice object from the event data
     * @param string $eventID The Stripe event ID for logging
     *
     * @return array Processing result
     *
     * @see https://docs.stripe.com/api/invoices/object
     */
    private static function handleInvoicePaymentFailed(array $invoice, string $eventID): array
    {
        $invoiceID = $invoice['id'] ?? '';
        $stripeSubID = $invoice['subscription'] ?? null;

        if (!$stripeSubID) {
            return ['success' => true, 'action' => 'ignored', 'message' => 'Invoice payment failure not linked to a subscription'];
        }

        $subscription = Database::fetchOne(
            "SELECT subscriptionID, userID FROM tblSubscriptions WHERE paymentProviderSubscriptionID = ? ORDER BY createdAt DESC LIMIT 1",
            [$stripeSubID],
            's'
        );

        if (!$subscription) {
            ActivityLogger::log(
                null,
                'stripe_subscription_not_found',
                'payment',
                'warning',
                'Stripe invoice.payment_failed for an unknown subscription — ignored',
                ['eventID' => $eventID, 'invoiceID' => $invoiceID, 'stripeSubID' => $stripeSubID]
            );

            return ['success' => true, 'action' => 'ignored', 'message' => 'No matching local subscription — ignored'];
        }

        $result = PaymentManager::applyWebhookTransition(
            (int)$subscription['subscriptionID'],
            'past_due',
            'stripe',
            'webhook_past_due',
            'Stripe invoice.payment_failed (dunning start)',
            'stripe:' . $eventID
        );

        ActivityLogger::log(
            (int)$subscription['userID'],
            'stripe_invoice_payment_failed',
            'payment',
            'warning',
            'Stripe subscription invoice payment failed',
            ['eventID' => $eventID, 'invoiceID' => $invoiceID, 'subscriptionID' => $subscription['subscriptionID'], 'transitioned' => $result['transitioned'] ?? false]
        );

        return ['success' => true, 'action' => 'payment_failed', 'message' => 'Subscription marked past_due'];
    }

    /**
     * 🔄 Handle customer.subscription.updated event
     *
     * Called when a subscription's status or details change in Stripe.
     * Updates the corresponding subscription status in SIGNula's database.
     *
     * @param array  $subscription The Subscription object from the event data
     * @param string $eventID      The Stripe event ID for logging
     *
     * @return array Processing result
     *
     * @see https://docs.stripe.com/api/subscriptions/object
     */
    private static function handleSubscriptionUpdated(array $subscription, string $eventID): array
    {
        $stripeSubID = $subscription['id'] ?? '';
        $stripeStatus = $subscription['status'] ?? '';

        // 🔀 Map Stripe subscription statuses to SIGNula statuses
        // @see https://docs.stripe.com/api/subscriptions/object#subscription_object-status
        $statusMap = [
            'active'    => 'active',
            'past_due'  => 'past_due',
            'canceled'  => 'cancelled',
            'unpaid'    => 'past_due',
            'trialing'  => 'trial',
            'paused'    => 'paused',
            'incomplete'         => 'pending',
            'incomplete_expired' => 'expired',
        ];

        $signulaStatus = $statusMap[$stripeStatus] ?? 'active';

        // 🔍 Find the SIGNula subscription (B-072 fix: paymentProviderSubscriptionID,
        // not the phantom externalSubscriptionID)
        $localSub = Database::fetchOne(
            "SELECT subscriptionID, userID, subscriptionStatus FROM tblSubscriptions WHERE paymentProviderSubscriptionID = ? ORDER BY createdAt DESC LIMIT 1",
            [$stripeSubID],
            's'
        );

        if (!$localSub) {
            // ⚠️ No matching local subscription found — safely ignored.
            ActivityLogger::log(
                null,
                'stripe_subscription_not_found',
                'payment',
                'warning',
                'Stripe subscription updated but no matching SIGNula subscription found',
                ['eventID' => $eventID, 'stripeSubID' => $stripeSubID, 'stripeStatus' => $stripeStatus]
            );

            return ['success' => true, 'action' => 'ignored', 'message' => 'No matching local subscription — ignored'];
        }

        // 🔀 G-002 S3: routed through PaymentManager::applyWebhookTransition()
        // — validated against SubscriptionStateMachine before persisting —
        // rather than the previous raw, unconditional UPDATE. An illegal
        // mirror request (e.g. Stripe reporting 'trialing' for a subscription
        // that is currently 'active' locally) is safely skipped + audited,
        // not blindly applied.
        $result = PaymentManager::applyWebhookTransition(
            (int)$localSub['subscriptionID'],
            $signulaStatus,
            'stripe',
            'webhook_status_sync',
            'Stripe customer.subscription.updated (' . $stripeStatus . ')',
            'stripe:' . $eventID
        );

        ActivityLogger::log(
            (int)$localSub['userID'],
            'stripe_subscription_status_changed',
            'payment',
            'info',
            'Subscription status sync: ' . $localSub['subscriptionStatus'] . ' -> ' . $signulaStatus . ' (Stripe status: ' . $stripeStatus . ')',
            [
                'eventID'          => $eventID,
                'stripeSubID'      => $stripeSubID,
                'previousStatus'   => $localSub['subscriptionStatus'],
                'requestedStatus'  => $signulaStatus,
                'stripeStatus'     => $stripeStatus,
                'subscriptionID'   => (int)$localSub['subscriptionID'],
                'transitioned'     => $result['transitioned'] ?? false,
            ]
        );

        return [
            'success' => true,
            'action'  => 'subscription_updated',
            'message' => 'Subscription status synced to: ' . $signulaStatus,
        ];
    }

    /**
     * ❌ Handle customer.subscription.deleted event
     *
     * Called when a subscription is cancelled or has expired in Stripe.
     * Marks the corresponding SIGNula subscription as cancelled.
     *
     * @param array  $subscription The Subscription object from the event data
     * @param string $eventID      The Stripe event ID for logging
     *
     * @return array Processing result
     *
     * @see https://docs.stripe.com/api/subscriptions/object
     */
    private static function handleSubscriptionDeleted(array $subscription, string $eventID): array
    {
        $stripeSubID = $subscription['id'] ?? '';

        // 🔍 Find the SIGNula subscription (B-072 fix: paymentProviderSubscriptionID,
        // not the phantom externalSubscriptionID)
        $localSub = Database::fetchOne(
            "SELECT subscriptionID, userID FROM tblSubscriptions WHERE paymentProviderSubscriptionID = ? ORDER BY createdAt DESC LIMIT 1",
            [$stripeSubID],
            's'
        );

        if (!$localSub) {
            ActivityLogger::log(
                null,
                'stripe_subscription_delete_not_found',
                'payment',
                'warning',
                'Stripe subscription deleted but no matching SIGNula subscription found',
                ['eventID' => $eventID, 'stripeSubID' => $stripeSubID]
            );

            return ['success' => true, 'action' => 'ignored', 'message' => 'No matching local subscription — ignored'];
        }

        // ❌ Mark subscription as cancelled — routed through the state
        // machine (G-002 S3) rather than a raw, unconditional UPDATE.
        $result = PaymentManager::applyWebhookTransition(
            (int)$localSub['subscriptionID'],
            'cancelled',
            'stripe',
            'webhook_cancelled',
            'Stripe customer.subscription.deleted',
            'stripe:' . $eventID
        );

        ActivityLogger::log(
            (int)$localSub['userID'],
            'stripe_subscription_deleted',
            'payment',
            'info',
            'Stripe subscription cancelled/deleted',
            [
                'eventID'        => $eventID,
                'stripeSubID'    => $stripeSubID,
                'subscriptionID' => (int)$localSub['subscriptionID'],
                'transitioned'   => $result['transitioned'] ?? false,
            ]
        );

        return [
            'success' => true,
            'action'  => 'subscription_cancelled',
            'message' => 'Subscription cancelled for Stripe ID: ' . $stripeSubID,
        ];
    }

    /**
     * 💸 Handle charge.refunded event
     *
     * Called when a charge is refunded (partially or fully). Processes the
     * refund in SIGNula's database via PaymentManager.
     *
     * @param array  $charge  The Charge object from the event data
     * @param string $eventID The Stripe event ID for logging
     *
     * @return array Processing result
     *
     * @see https://docs.stripe.com/api/charges/object
     */
    private static function handleChargeRefunded(array $charge, string $eventID): array
    {
        $chargeID = $charge['id'] ?? '';
        $paymentIntentID = $charge['payment_intent'] ?? null;
        $amountRefunded = $charge['amount_refunded'] ?? 0;
        $amountTotal = $charge['amount'] ?? 0;
        $currency = strtoupper($charge['currency'] ?? 'GBP');
        $isFullRefund = ($amountRefunded >= $amountTotal);

        // 🔍 Find the payment record by the Payment Intent ID
        if ($paymentIntentID) {
            $payment = Database::fetchOne(
                "SELECT paymentID, userID, amount FROM tblPayments WHERE transactionID = ? AND status = 'completed'",
                [$paymentIntentID],
                's'
            );

            if ($payment) {
                // 💸 Process the refund via PaymentManager
                // Convert from smallest unit back to major currency unit
                $refundAmountMajor = $amountRefunded / 100;

                PaymentManager::refundPayment(
                    (int)$payment['paymentID'],
                    $isFullRefund ? null : $refundAmountMajor, // 📌 null = full refund
                    'Refund processed via Stripe webhook'
                );
            }
        }

        // 📝 Log the refund
        ActivityLogger::log(
            null,
            'stripe_charge_refunded',
            'payment',
            'info',
            ($isFullRefund ? 'Full' : 'Partial') . ' refund processed — ' . $currency . ' ' . number_format($amountRefunded / 100, 2),
            [
                'eventID'         => $eventID,
                'chargeID'        => $chargeID,
                'paymentIntentID' => $paymentIntentID,
                'amountRefunded'  => $amountRefunded,
                'amountTotal'     => $amountTotal,
                'currency'        => $currency,
                'isFullRefund'    => $isFullRefund,
            ]
        );

        return [
            'success' => true,
            'action'  => $isFullRefund ? 'full_refund_processed' : 'partial_refund_processed',
            'message' => ($isFullRefund ? 'Full' : 'Partial') . ' refund processed for charge: ' . $chargeID,
        ];
    }
}
