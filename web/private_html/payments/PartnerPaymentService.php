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
 * 🏢 SIGNula - Partner Payment Service (Level 2)
 * ============================================================================
 *
 * Purpose: Orchestrates Level 2 payments — where partners collect payments from
 *          THEIR customers through the SIGNula platform. Manages two payment
 *          modes for partner payment collection.
 *
 * PHP Version: 8.3+
 *
 * Two-Tier Payment Modes:
 * ─────────────────────────────────────────────────────────────────────────────
 * Option 2a (own_keys):
 *   Partner provides their own Stripe/PayPal/Coinbase API keys. Payments go
 *   directly to the partner's account. SIGNula charges a configurable service
 *   fee (~10% default) recorded as a fee transaction for later reconciliation.
 *
 * Option 2b (signula_keys):
 *   Partner uses SIGNula's payment keys. SIGNula collects the full amount,
 *   deducts a configurable service fee (~30% default), and remits the
 *   remainder to the partner on a scheduled basis (weekly/biweekly/monthly).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Features:
 * - Partner payment provider configuration management (CRUD + encryption)
 * - API key resolution (partner keys vs. SIGNula keys)
 * - Connection testing for partner-provided credentials
 * - Partner subscription tier management (CRUD + soft delete)
 * - Checkout session creation routed to the correct provider
 * - Payment completion processing with service fee recording
 * - Provider-specific discount lookups (global + per-partner)
 * - Country-based discount validation (cascade: billing → profile → IP)
 * - Partner earnings summary for dashboard reporting
 * - Full activity logging for all operations
 *
 * Database Tables Used:
 * - tblPartnerPaymentConfig  — Partner-specific payment provider credentials
 * - tblPartnerSubscriptionTiers — Partner-defined subscription tiers
 * - tblPayments              — Payment records (ALTERed with partnerID + paymentContext)
 * - tblServiceFees           — Fee schedule configuration
 * - tblServiceFeeTransactions — Individual fee transaction records
 * - tblRemittances           — Partner payout/remittance tracking
 * - tblProviderDiscounts     — Payment method-specific discounts (global + per-partner)
 * - tblDiscountCodes         — Discount codes (with country restrictions)
 * - tblUsers                 — User profiles (for country fallback)
 * - tblInvoices              — Invoice records
 * - tblBillingSchedule       — Scheduled billing tasks
 *
 * ToS Compliance Notes:
 * ─────────────────────────────────────────────────────────────────────────────
 * ⚖️ Option 2b with Stripe requires Stripe Connect (Standard or Express)
 *    to be enabled. Controlled via admin setting: payment.stripe.connect_enabled
 *    @see https://docs.stripe.com/connect
 *
 * ⚖️ Option 2b with PayPal requires PayPal Commerce Platform to be enabled.
 *    Controlled via admin setting: payment.paypal.commerce_platform_enabled
 *    @see https://developer.paypal.com/docs/platforms/
 *
 * ⚖️ Option 2b with Coinbase: the SIGNula platform operator assumes full
 *    regulatory responsibility for collecting and redistributing cryptocurrency
 *    payments. Ensure compliance with local money transmission regulations.
 *    @see https://docs.cdp.coinbase.com/commerce-onchain/docs/welcome
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @see https://docs.stripe.com/connect                            (Stripe Connect)
 * @see https://developer.paypal.com/docs/platforms/               (PayPal Commerce Platform)
 * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/welcome (Coinbase Commerce)
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

// ========================================================================
// 📦 DEPENDENCY LOADING
// ========================================================================

// 📦 Load the PaymentManager class (core payment recording, subscriptions, discounts)
// Using __DIR__ with DIRECTORY_SEPARATOR for platform-neutral file paths
// @see https://www.php.net/manual/en/language.constants.predefined.php
$paymentManagerPath = __DIR__ . DIRECTORY_SEPARATOR . 'PaymentManager.php';
if (file_exists($paymentManagerPath)) {
    require_once $paymentManagerPath;
} else {
    // ⚠️ PaymentManager is essential for recording payments and managing subscriptions
    error_log('[SIGNula] PartnerPaymentService: PaymentManager.php not found at ' . $paymentManagerPath);
    throw new RuntimeException('PaymentManager.php is required but was not found');
}

// 📦 Load provider classes for checkout session creation and connection testing
// Each provider resides in the same payments directory
$stripeProviderPath = __DIR__ . DIRECTORY_SEPARATOR . 'StripeProvider.php';
if (file_exists($stripeProviderPath)) {
    require_once $stripeProviderPath;
} else {
    // ⚠️ StripeProvider is optional — only needed if Stripe is used
    error_log('[SIGNula] PartnerPaymentService: StripeProvider.php not found at ' . $stripeProviderPath);
}

$paypalProviderPath = __DIR__ . DIRECTORY_SEPARATOR . 'PayPalProvider.php';
if (file_exists($paypalProviderPath)) {
    require_once $paypalProviderPath;
} else {
    // ⚠️ PayPalProvider is optional — only needed if PayPal is used
    error_log('[SIGNula] PartnerPaymentService: PayPalProvider.php not found at ' . $paypalProviderPath);
}

$coinbaseProviderPath = __DIR__ . DIRECTORY_SEPARATOR . 'CoinbaseProvider.php';
if (file_exists($coinbaseProviderPath)) {
    require_once $coinbaseProviderPath;
} else {
    // ⚠️ CoinbaseProvider is optional — only needed if crypto payments are used
    error_log('[SIGNula] PartnerPaymentService: CoinbaseProvider.php not found at ' . $coinbaseProviderPath);
}


/**
 * 🏢 PartnerPaymentService
 *
 * Static utility class that orchestrates Level 2 payments — where partners
 * collect payments from their own customers through the SIGNula platform.
 * All methods are public static following the project-wide convention.
 *
 * This class sits between the provider classes (StripeProvider, PayPalProvider,
 * CoinbaseProvider) and the partner admin UI, handling:
 * - Which API keys to use (partner's own vs. SIGNula's global keys)
 * - Service fee calculation and recording
 * - Partner subscription tier CRUD operations
 * - Checkout session routing to the correct payment provider
 * - Provider-specific discount lookups
 * - Country-based discount validation
 *
 * Database Schema Reference (Migration 012):
 * - tblPartnerPaymentConfig uses: usesOwnKeys (1=own_keys, 0=signula_keys)
 * - tblPartnerPaymentConfig columns: apiKey, apiSecret, webhookSecret (encrypted)
 * - tblPartnerSubscriptionTiers PK: tierID
 * - tblPayments.paymentContext: signula_direct | partner_own_keys | partner_signula_keys
 *
 * @see PaymentManager   Core payment recording and subscription management
 * @see StripeProvider    Stripe payment processing
 * @see PayPalProvider    PayPal payment processing
 * @see CoinbaseProvider  Coinbase Commerce cryptocurrency payments
 */
class PartnerPaymentService
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /** @var string Payment context when partner uses their own API keys (Option 2a) */
    const CONTEXT_PARTNER_OWN_KEYS = 'partner_own_keys';

    /** @var string Payment context when partner uses SIGNula's API keys (Option 2b) */
    const CONTEXT_PARTNER_SIGNULA_KEYS = 'partner_signula_keys';

    /** @var string Payment context for SIGNula's own direct payments (Level 1) */
    const CONTEXT_SIGNULA_DIRECT = 'signula_direct';

    /** @var string Service fee type identifier for Option 2a */
    const FEE_TYPE_OWN_KEYS = 'partner_own_keys';

    /** @var string Service fee type identifier for Option 2b */
    const FEE_TYPE_SIGNULA_KEYS = 'partner_signula_keys';

    /** @var array Valid payment providers supported for partner configurations */
    const VALID_PROVIDERS = ['stripe', 'paypal', 'coinbase'];

    /** @var array Valid billing cycles for partner tier subscriptions */
    const VALID_BILLING_CYCLES = ['monthly', 'yearly'];

    /** @var int cURL timeout in seconds for IP geolocation requests */
    const IP_GEOLOCATION_TIMEOUT = 5;

    // ========================================================================
    // 🔑 PARTNER PAYMENT CONFIGURATION
    // ========================================================================

    /**
     * 🔍 Get partner payment provider configuration
     *
     * Fetches the payment provider configuration for a specific partner from
     * tblPartnerPaymentConfig. Decrypts the API keys (apiKey, apiSecret,
     * webhookSecret, publishableKey) for runtime use.
     *
     * Returns null if no configuration exists for the given partner/provider
     * combination.
     *
     * @param int    $partnerID The partner's unique identifier (FK to tblPartners)
     * @param string $provider  Payment provider: 'stripe', 'paypal', or 'coinbase'
     *
     * @return array|null Configuration array with decrypted keys, or null if not found.
     *                    Keys: configID, partnerID, provider, usesOwnKeys, apiKey, apiSecret,
     *                    webhookSecret, publishableKey, mode, isEnabled, isVerified, verifiedAt,
     *                    metadata, createdAt, updatedAt
     *
     * @see SecurityUtils::decrypt() For AES-256-CBC decryption of stored credentials
     * @see https://www.php.net/manual/en/function.in-array.php
     *
     * @example
     * ```php
     * $config = PartnerPaymentService::getPartnerPaymentConfig(42, 'stripe');
     * if ($config && $config['usesOwnKeys']) {
     *     // Partner uses their own Stripe keys
     *     $apiKey = $config['apiKey']; // Already decrypted
     * }
     * ```
     */
    public static function getPartnerPaymentConfig(int $partnerID, string $provider): ?array
    {
        // 🛡️ Validate the provider parameter against allowed values
        // This prevents SQL injection even though we use prepared statements
        if (!in_array($provider, self::VALID_PROVIDERS, true)) {
            ActivityLogger::log(
                null,
                'partner_payment_config_invalid_provider',
                'payment',
                'warning',
                'Invalid payment provider requested: ' . $provider . ' for partner ' . $partnerID,
                ['partnerID' => $partnerID, 'provider' => $provider]
            );
            return null;
        }

        // 🔍 Fetch the configuration record from the database
        // @see Database::fetchOne() Returns a single row as an associative array or null
        $config = Database::fetchOne(
            "SELECT * FROM tblPartnerPaymentConfig WHERE partnerID = ? AND provider = ?",
            [$partnerID, $provider],
            'is'
        );

        // ❌ No configuration found for this partner/provider combination
        if (!$config) {
            return null;
        }

        // 🔓 Decrypt the stored API credentials for runtime use
        // All credential fields are stored encrypted via SecurityUtils::encrypt()
        // Decryption returns the original plaintext value, or empty string on failure
        // @see SecurityUtils::decrypt() for AES-256-CBC decryption implementation
        if (!empty($config['apiKey'])) {
            $config['apiKey'] = SecurityUtils::decrypt($config['apiKey']);
        }

        if (!empty($config['apiSecret'])) {
            $config['apiSecret'] = SecurityUtils::decrypt($config['apiSecret']);
        }

        if (!empty($config['webhookSecret'])) {
            $config['webhookSecret'] = SecurityUtils::decrypt($config['webhookSecret']);
        }

        if (!empty($config['publishableKey'])) {
            $config['publishableKey'] = SecurityUtils::decrypt($config['publishableKey']);
        }

        // 📦 Decode the JSON metadata field if present
        // @see https://www.php.net/manual/en/function.json-decode.php
        if (!empty($config['metadata']) && is_string($config['metadata'])) {
            $config['metadata'] = json_decode($config['metadata'], true) ?? [];
        }

        return $config;
    }

    /**
     * 💾 Save partner payment provider configuration
     *
     * Encrypts API keys and upserts (INSERT or UPDATE on duplicate key) into
     * tblPartnerPaymentConfig. The unique key uk_partner_provider ensures only
     * one configuration record per partner/provider combination.
     *
     * @param int    $partnerID   The partner's unique identifier
     * @param string $provider    Payment provider: 'stripe', 'paypal', or 'coinbase'
     * @param string $mode        Key mode: 'own_keys' (Option 2a) or 'signula_keys' (Option 2b)
     * @param array  $credentials API credentials to encrypt and store:
     *                            - 'apiKey'         (string) API key / Client ID
     *                            - 'apiSecret'      (string) API secret / Client Secret
     *                            - 'webhookSecret'  (string) Webhook signing secret
     *                            - 'publishableKey' (string) Publishable key (Stripe only)
     * @param array  $options     Optional settings:
     *                            - 'isTestMode'        (bool)   Whether to use sandbox/test mode (default: true)
     *                            - 'additionalConfig'  (array)  Extra provider-specific metadata (JSON stored)
     *
     * @return array ['success' => bool, 'message' => string, 'configID' => ?int]
     *
     * @see SecurityUtils::encrypt() For AES-256-CBC encryption of credentials
     * @see https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html
     *
     * @example
     * ```php
     * $result = PartnerPaymentService::savePartnerPaymentConfig(
     *     42,
     *     'stripe',
     *     'own_keys',
     *     [
     *         'apiKey'         => 'sk_test_...',
     *         'apiSecret'      => 'whsec_...',
     *         'publishableKey' => 'pk_test_...',
     *     ],
     *     ['isTestMode' => true]
     * );
     * ```
     */
    public static function savePartnerPaymentConfig(
        int $partnerID,
        string $provider,
        string $mode,
        array $credentials,
        array $options = []
    ): array {
        try {
            // 🛡️ Validate the provider parameter
            if (!in_array($provider, self::VALID_PROVIDERS, true)) {
                return [
                    'success' => false,
                    'message' => 'Invalid payment provider: ' . $provider . '. Must be one of: ' . implode(', ', self::VALID_PROVIDERS),
                ];
            }

            // 🛡️ Validate the mode parameter
            // 'own_keys' maps to usesOwnKeys=1, 'signula_keys' maps to usesOwnKeys=0
            if (!in_array($mode, ['own_keys', 'signula_keys'], true)) {
                return [
                    'success' => false,
                    'message' => 'Invalid mode: ' . $mode . '. Must be "own_keys" or "signula_keys".',
                ];
            }

            // 🔢 Determine the database flag from the mode string
            // The tblPartnerPaymentConfig.usesOwnKeys column is TINYINT(1):
            //   1 = Option 2a (partner uses own API keys)
            //   0 = Option 2b (partner uses SIGNula's keys)
            $usesOwnKeys = ($mode === 'own_keys') ? 1 : 0;

            // 🔐 Encrypt credentials before storage
            // Only encrypt non-empty values; store NULL for empty/missing credentials
            // @see SecurityUtils::encrypt() for AES-256-CBC encryption with random IV
            $encryptedApiKey = !empty($credentials['apiKey'])
                ? SecurityUtils::encrypt($credentials['apiKey'])
                : null;

            $encryptedApiSecret = !empty($credentials['apiSecret'])
                ? SecurityUtils::encrypt($credentials['apiSecret'])
                : null;

            $encryptedWebhookSecret = !empty($credentials['webhookSecret'])
                ? SecurityUtils::encrypt($credentials['webhookSecret'])
                : null;

            $encryptedPublishableKey = !empty($credentials['publishableKey'])
                ? SecurityUtils::encrypt($credentials['publishableKey'])
                : null;

            // 🔧 Determine API environment mode from options
            // Defaults to 'sandbox' for safety — prevents accidental production charges
            $isTestMode = (bool)($options['isTestMode'] ?? true);
            $apiMode = $isTestMode ? 'sandbox' : 'live';

            // 📦 Encode additional configuration as JSON metadata
            // @see https://www.php.net/manual/en/function.json-encode.php
            $additionalConfig = !empty($options['additionalConfig'])
                ? json_encode($options['additionalConfig'])
                : null;

            // 💾 Upsert into tblPartnerPaymentConfig
            // Uses INSERT ... ON DUPLICATE KEY UPDATE to handle both new and existing configs
            // The UNIQUE KEY uk_partner_provider (partnerID, provider) triggers the update path
            // @see https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html
            Database::query(
                "INSERT INTO tblPartnerPaymentConfig
                    (partnerID, provider, usesOwnKeys, apiKey, apiSecret, webhookSecret,
                     publishableKey, mode, isEnabled, metadata)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE
                    usesOwnKeys = VALUES(usesOwnKeys),
                    apiKey = VALUES(apiKey),
                    apiSecret = VALUES(apiSecret),
                    webhookSecret = VALUES(webhookSecret),
                    publishableKey = VALUES(publishableKey),
                    mode = VALUES(mode),
                    isEnabled = 1,
                    metadata = VALUES(metadata),
                    isVerified = 0,
                    verifiedAt = NULL,
                    updatedAt = NOW()",
                [
                    $partnerID,
                    $provider,
                    $usesOwnKeys,
                    $encryptedApiKey,
                    $encryptedApiSecret,
                    $encryptedWebhookSecret,
                    $encryptedPublishableKey,
                    $apiMode,
                    $additionalConfig,
                ],
                'isisssss s'
            );

            // 🔍 Retrieve the configID (may be existing or newly inserted)
            $configID = Database::getLastInsertId();

            // 📝 Log the configuration save event
            ActivityLogger::log(
                null,
                'partner_payment_config_saved',
                'payment',
                'info',
                'Partner payment config saved: partner=' . $partnerID . ', provider=' . $provider . ', mode=' . $mode,
                [
                    'partnerID'   => $partnerID,
                    'provider'    => $provider,
                    'mode'        => $mode,
                    'usesOwnKeys' => $usesOwnKeys,
                    'apiMode'     => $apiMode,
                    'hasApiKey'   => !empty($credentials['apiKey']),
                    'hasSecret'   => !empty($credentials['apiSecret']),
                    'configID'    => $configID,
                ]
            );

            return [
                'success'  => true,
                'message'  => 'Payment configuration saved successfully',
                'configID' => $configID,
            ];
        } catch (\Exception $e) {
            // ❌ Log and return a safe error message (never expose internals)
            ActivityLogger::log(
                null,
                'partner_payment_config_error',
                'payment',
                'error',
                'Failed to save partner payment config: ' . $e->getMessage(),
                [
                    'partnerID' => $partnerID,
                    'provider'  => $provider,
                    'mode'      => $mode,
                ]
            );

            return [
                'success' => false,
                'message' => 'Failed to save payment configuration',
            ];
        }
    }

    /**
     * 🧪 Test partner payment provider connection
     *
     * Verifies that the partner's stored API credentials are valid by calling
     * the appropriate provider's testConnection() method. Only meaningful when
     * the partner uses their own keys (Option 2a / usesOwnKeys=1).
     *
     * On success, updates isVerified=1 and verifiedAt=NOW() in tblPartnerPaymentConfig.
     * On failure, sets isVerified=0 and verifiedAt=NULL.
     *
     * @param int    $partnerID The partner's unique identifier
     * @param string $provider  Payment provider: 'stripe', 'paypal', or 'coinbase'
     *
     * @return array ['success' => bool, 'message' => string]
     *
     * @see StripeProvider::testConnection()   Stripe balance retrieval test
     * @see PayPalProvider::testConnection()   PayPal OAuth token test
     * @see CoinbaseProvider::testConnection() Coinbase charge list test
     *
     * @example
     * ```php
     * $result = PartnerPaymentService::testPartnerConnection(42, 'stripe');
     * if ($result['success']) {
     *     echo 'Stripe connection verified!';
     * }
     * ```
     */
    public static function testPartnerConnection(int $partnerID, string $provider): array
    {
        try {
            // 🔍 Fetch the partner's configuration (with decrypted keys)
            $config = self::getPartnerPaymentConfig($partnerID, $provider);

            if (!$config) {
                return [
                    'success' => false,
                    'message' => 'No payment configuration found for this partner and provider',
                ];
            }

            // 🔍 Only test if the partner uses their own keys (Option 2a)
            // Partners using SIGNula's keys (Option 2b) don't need their own credentials tested
            if (!(int)$config['usesOwnKeys']) {
                return [
                    'success' => true,
                    'message' => 'Partner uses SIGNula keys — no partner credentials to test',
                ];
            }

            // 🧪 Route to the appropriate provider's test method
            // Each provider has a testConnection() method that validates API credentials
            $testResult = ['success' => false, 'message' => 'Unknown provider'];

            switch ($provider) {
                case 'stripe':
                    // 🔧 Temporarily override Stripe settings with partner's keys
                    // StripeProvider reads from getSetting(), so we call its test
                    // with the partner's decrypted credentials
                    // NOTE: For a full implementation, the provider classes should accept
                    // optional credential overrides. For now, we test by attempting a
                    // balance retrieval using the partner's secret key directly via cURL.
                    if (!empty($config['apiKey'])) {
                        $testResult = self::testStripeCredentials($config['apiKey']);
                    } else {
                        $testResult = ['success' => false, 'message' => 'Stripe API key is not configured'];
                    }
                    break;

                case 'paypal':
                    // 🔧 Test PayPal credentials by attempting an OAuth token exchange
                    if (!empty($config['apiKey']) && !empty($config['apiSecret'])) {
                        $testResult = self::testPayPalCredentials($config['apiKey'], $config['apiSecret'], $config['mode'] ?? 'sandbox');
                    } else {
                        $testResult = ['success' => false, 'message' => 'PayPal Client ID and/or Secret are not configured'];
                    }
                    break;

                case 'coinbase':
                    // 🔧 Test Coinbase credentials by attempting to list charges
                    if (!empty($config['apiKey'])) {
                        $testResult = self::testCoinbaseCredentials($config['apiKey']);
                    } else {
                        $testResult = ['success' => false, 'message' => 'Coinbase Commerce API key is not configured'];
                    }
                    break;
            }

            // 📝 Update the verification status in the database
            if ($testResult['success']) {
                // ✅ Credentials verified — update isVerified and verifiedAt
                Database::query(
                    "UPDATE tblPartnerPaymentConfig SET isVerified = 1, verifiedAt = NOW() WHERE partnerID = ? AND provider = ?",
                    [$partnerID, $provider],
                    'is'
                );
            } else {
                // ❌ Credentials failed — reset verification status
                Database::query(
                    "UPDATE tblPartnerPaymentConfig SET isVerified = 0, verifiedAt = NULL WHERE partnerID = ? AND provider = ?",
                    [$partnerID, $provider],
                    'is'
                );
            }

            // 📝 Log the test result
            ActivityLogger::log(
                null,
                $testResult['success'] ? 'partner_connection_test_success' : 'partner_connection_test_failed',
                'payment',
                $testResult['success'] ? 'info' : 'warning',
                'Partner connection test for ' . $provider . ': ' . ($testResult['success'] ? 'SUCCESS' : 'FAILED')
                    . ' (partner=' . $partnerID . ')',
                [
                    'partnerID' => $partnerID,
                    'provider'  => $provider,
                    'success'   => $testResult['success'],
                    'message'   => $testResult['message'] ?? '',
                ]
            );

            return $testResult;
        } catch (\Exception $e) {
            // ❌ Catch-all error handling
            ActivityLogger::log(
                null,
                'partner_connection_test_error',
                'payment',
                'error',
                'Exception testing partner connection: ' . $e->getMessage(),
                ['partnerID' => $partnerID, 'provider' => $provider]
            );

            return [
                'success' => false,
                'message' => 'An error occurred while testing the connection',
            ];
        }
    }

    /**
     * 🔑 Resolve which API keys to use for a partner payment
     *
     * Determines the correct set of API credentials and payment context based
     * on the partner's configuration mode:
     * - usesOwnKeys=1 (own_keys): Returns the partner's decrypted API keys
     * - usesOwnKeys=0 (signula_keys): Returns null credentials (global keys used)
     * - disabled/missing: Returns an error
     *
     * This method is called by createCheckoutSession() to determine how to
     * route the payment through the correct provider with the correct credentials.
     *
     * @param int    $partnerID The partner's unique identifier
     * @param string $provider  Payment provider: 'stripe', 'paypal', or 'coinbase'
     *
     * @return array [
     *     'success'        => bool,
     *     'credentials'    => ?array  (decrypted API keys, null for signula_keys mode),
     *     'paymentContext'  => string  (partner_own_keys | partner_signula_keys),
     *     'feeType'        => string  (partner_own_keys | partner_signula_keys),
     *     'message'        => string  (error message on failure),
     * ]
     *
     * @example
     * ```php
     * $resolved = PartnerPaymentService::resolveApiKeys(42, 'stripe');
     * if ($resolved['success']) {
     *     if ($resolved['credentials']) {
     *         // Use partner's own keys
     *     } else {
     *         // Use SIGNula's global keys
     *     }
     * }
     * ```
     */
    public static function resolveApiKeys(int $partnerID, string $provider): array
    {
        // 🔍 Fetch the partner's payment configuration (with decrypted keys)
        $config = self::getPartnerPaymentConfig($partnerID, $provider);

        // ❌ No configuration found — partner hasn't set up this provider
        if (!$config) {
            return [
                'success'        => false,
                'credentials'    => null,
                'paymentContext' => '',
                'feeType'        => '',
                'message'        => 'No payment configuration found for partner ' . $partnerID . ' with provider ' . $provider,
            ];
        }

        // ❌ Configuration exists but is disabled
        if (!(int)$config['isEnabled']) {
            return [
                'success'        => false,
                'credentials'    => null,
                'paymentContext' => '',
                'feeType'        => '',
                'message'        => 'Payment provider ' . $provider . ' is disabled for this partner',
            ];
        }

        // 🔀 Determine the mode and resolve credentials accordingly
        if ((int)$config['usesOwnKeys']) {
            // ═══════════════════════════════════════════════════════════════
            // 🔑 OPTION 2a: Partner uses their own API keys
            // ═══════════════════════════════════════════════════════════════
            // Payments go directly to the partner's payment account.
            // SIGNula charges a configurable service fee (~10% default).
            return [
                'success'        => true,
                'credentials'    => [
                    'apiKey'         => $config['apiKey'] ?? '',
                    'apiSecret'      => $config['apiSecret'] ?? '',
                    'webhookSecret'  => $config['webhookSecret'] ?? '',
                    'publishableKey' => $config['publishableKey'] ?? '',
                    'mode'           => $config['mode'] ?? 'sandbox',
                ],
                'paymentContext' => self::CONTEXT_PARTNER_OWN_KEYS,
                'feeType'        => self::FEE_TYPE_OWN_KEYS,
                'message'        => '',
            ];
        } else {
            // ═══════════════════════════════════════════════════════════════
            // 🔑 OPTION 2b: Partner uses SIGNula's payment keys
            // ═══════════════════════════════════════════════════════════════
            // SIGNula collects the full amount, deducts a service fee (~30%
            // default), and remits the remainder to the partner on schedule.
            //
            // ⚖️ ToS Compliance Check:
            //   - Stripe: Requires Stripe Connect to be enabled
            //   - PayPal: Requires PayPal Commerce Platform to be enabled
            //   - Coinbase: Operator assumes regulatory responsibility

            // 🔍 Check ToS compliance for Option 2b based on provider
            $complianceCheck = self::checkOption2bCompliance($provider);
            if (!$complianceCheck['allowed']) {
                return [
                    'success'        => false,
                    'credentials'    => null,
                    'paymentContext' => '',
                    'feeType'        => '',
                    'message'        => $complianceCheck['message'],
                ];
            }

            return [
                'success'        => true,
                'credentials'    => null, // Use SIGNula's global keys
                'paymentContext' => self::CONTEXT_PARTNER_SIGNULA_KEYS,
                'feeType'        => self::FEE_TYPE_SIGNULA_KEYS,
                'message'        => '',
            ];
        }
    }

    // ========================================================================
    // 🛒 CHECKOUT SESSION CREATION
    // ========================================================================

    /**
     * 🛒 Create a checkout session for a partner-level payment
     *
     * Orchestrates the full checkout flow for a Level 2 payment:
     * 1. Validates the partner subscription tier
     * 2. Resolves which API keys to use (partner's or SIGNula's)
     * 3. Calculates the price with any provider discount
     * 4. Applies a discount code if provided (with country validation)
     * 5. Routes to the appropriate provider class for session creation
     * 6. Records the payment via PaymentManager with partner context
     * 7. Records the service fee via recordServiceFee()
     *
     * @param int    $partnerID      The partner collecting the payment
     * @param int    $userID         The end-user making the payment
     * @param int    $partnerTierID  Partner tier ID from tblPartnerSubscriptionTiers
     * @param string $provider       Payment provider: 'stripe', 'paypal', or 'coinbase'
     * @param string $billingCycle   Billing frequency: 'monthly' or 'yearly'
     * @param array  $options        Optional parameters:
     *                               - 'discountCode'    (string) Discount code to apply
     *                               - 'billingCountry'  (string) ISO 3166-1 alpha-2 country code
     *                               - 'ipAddress'       (string) User's IP for geolocation fallback
     *                               - 'successURL'      (string) Redirect URL after payment success
     *                               - 'cancelURL'       (string) Redirect URL on payment cancellation
     *                               - 'metadata'        (array)  Additional metadata for the payment
     *
     * @return array [
     *     'success'     => bool,
     *     'redirectURL' => string  (URL to redirect the user to for payment),
     *     'paymentID'   => int     (internal payment record ID),
     *     'message'     => string  (error message on failure),
     * ]
     *
     * @see self::resolveApiKeys()      Determines which API keys to use
     * @see self::getProviderDiscount() Gets provider-specific discount
     * @see self::validateCountryForDiscount() Country validation cascade
     * @see PaymentManager::recordPayment() Records the payment in tblPayments
     *
     * @example
     * ```php
     * $result = PartnerPaymentService::createCheckoutSession(
     *     partnerID: 42,
     *     userID: 100,
     *     partnerTierID: 5,
     *     provider: 'stripe',
     *     billingCycle: 'monthly',
     *     options: [
     *         'discountCode'   => 'WELCOME10',
     *         'billingCountry' => 'GB',
     *         'successURL'     => 'https://partner-app.com/payment/success',
     *         'cancelURL'      => 'https://partner-app.com/payment/cancel',
     *     ]
     * );
     * if ($result['success']) {
     *     header('Location: ' . $result['redirectURL']);
     * }
     * ```
     */
    public static function createCheckoutSession(
        int $partnerID,
        int $userID,
        int $partnerTierID,
        string $provider,
        string $billingCycle,
        array $options = []
    ): array {
        try {
            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 1: Validate the partner subscription tier
            // ═══════════════════════════════════════════════════════════════

            $tier = self::getPartnerTier($partnerTierID);
            if (!$tier) {
                return [
                    'success' => false,
                    'message' => 'Invalid partner subscription tier (ID: ' . $partnerTierID . ')',
                ];
            }

            // 🛡️ Ensure the tier belongs to the correct partner
            if ((int)$tier['partnerID'] !== $partnerID) {
                return [
                    'success' => false,
                    'message' => 'Subscription tier does not belong to this partner',
                ];
            }

            // 🛡️ Ensure the tier is active
            if (!(int)$tier['isActive']) {
                return [
                    'success' => false,
                    'message' => 'This subscription tier is no longer available',
                ];
            }

            // 🛡️ Validate the billing cycle
            if (!in_array($billingCycle, self::VALID_BILLING_CYCLES, true)) {
                return [
                    'success' => false,
                    'message' => 'Invalid billing cycle. Must be "monthly" or "yearly".',
                ];
            }

            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 2: Resolve API keys (partner's own vs. SIGNula's)
            // ═══════════════════════════════════════════════════════════════

            $resolved = self::resolveApiKeys($partnerID, $provider);
            if (!$resolved['success']) {
                return [
                    'success' => false,
                    'message' => $resolved['message'],
                ];
            }

            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 3: Calculate the price
            // ═══════════════════════════════════════════════════════════════

            // 💰 Get the base price from the tier based on billing cycle
            $basePrice = match ($billingCycle) {
                'yearly'  => (float)($tier['yearlyPrice'] ?? 0),
                'monthly' => (float)($tier['monthlyPrice'] ?? 0),
                default   => (float)($tier['monthlyPrice'] ?? 0),
            };

            if ($basePrice <= 0) {
                return [
                    'success' => false,
                    'message' => 'Cannot create a payment for a free tier',
                ];
            }

            $currency = $tier['currency'] ?? 'GBP';
            $finalPrice = $basePrice;
            $providerDiscountAmount = 0.00;
            $codeDiscountAmount = 0.00;

            // 🎁 Apply provider-specific discount (e.g., 5% off for crypto payments)
            $providerDiscountPercent = self::getProviderDiscount($provider, $partnerID);
            if ($providerDiscountPercent > 0) {
                $providerDiscountAmount = round($basePrice * ($providerDiscountPercent / 100), 2);
                $finalPrice = round($finalPrice - $providerDiscountAmount, 2);
            }

            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 4: Apply discount code if provided
            // ═══════════════════════════════════════════════════════════════

            if (!empty($options['discountCode'])) {
                // 🌍 Validate country eligibility for the discount code
                // Country detection cascade: billingCountry → profile country → IP geolocation
                $countryValidation = self::validateCountryForDiscount(
                    $options['discountCode'],
                    $options['billingCountry'] ?? null,
                    $userID,
                    $options['ipAddress'] ?? ($_SERVER['REMOTE_ADDR'] ?? null)
                );

                if ($countryValidation['allowed']) {
                    // 🎟️ Validate the discount code against the tier and payment method
                    $discountResult = PaymentManager::validateDiscountCode(
                        $options['discountCode'],
                        $tier['tierSlug'],
                        $provider,
                        $finalPrice
                    );

                    if ($discountResult['valid']) {
                        $codeDiscountAmount = $discountResult['discountAmount'];
                        $finalPrice = max(0, round($finalPrice - $codeDiscountAmount, 2));

                        // 📊 Increment the discount code usage counter
                        PaymentManager::incrementDiscountUsage($options['discountCode']);
                    }
                }
                // 🔇 If country validation fails, silently skip the discount
                // (don't block the payment — just don't apply the code)
            }

            // 🛡️ Ensure the final price is still positive after all discounts
            if ($finalPrice <= 0) {
                return [
                    'success' => false,
                    'message' => 'Payment amount is zero or negative after discounts',
                ];
            }

            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 5: Route to the appropriate payment provider
            // ═══════════════════════════════════════════════════════════════

            $providerResult = ['success' => false, 'message' => 'Unknown provider'];
            $redirectURL = '';

            // 📋 Build common options passed to provider classes
            $baseURL = getSetting('app.base_url', 'https://signula.id');
            $successURL = $options['successURL'] ?? (rtrim($baseURL, '/') . '/payments/success');
            $cancelURL  = $options['cancelURL']  ?? (rtrim($baseURL, '/') . '/payments/cancel');

            $description = ($tier['tierName'] ?? 'Subscription') . ' (' . ucfirst($billingCycle) . ')';

            switch ($provider) {
                case 'stripe':
                    // 💳 Create a Stripe Checkout Session
                    // If partner uses own keys, the credentials are passed to the provider
                    // @see StripeProvider::createCheckoutSession()
                    if (class_exists('StripeProvider')) {
                        $providerResult = StripeProvider::createCheckoutSession(
                            $userID,
                            0, // Not using global tierID — we pass amount via options
                            $billingCycle,
                            array_merge($options['metadata'] ?? [], [
                                'amount'         => $finalPrice,
                                'currency'       => $currency,
                                'description'    => $description,
                                'success_url'    => $successURL,
                                'cancel_url'     => $cancelURL,
                                'credentials'    => $resolved['credentials'],
                                'partnerID'      => $partnerID,
                                'partnerTierID'  => $partnerTierID,
                            ])
                        );

                        $redirectURL = $providerResult['url'] ?? $providerResult['redirectURL'] ?? '';
                    } else {
                        $providerResult = ['success' => false, 'message' => 'StripeProvider class is not available'];
                    }
                    break;

                case 'paypal':
                    // 💳 Create a PayPal Order
                    // @see PayPalProvider::createOrder()
                    if (class_exists('PayPalProvider')) {
                        $providerResult = PayPalProvider::createOrder(
                            $finalPrice,
                            $currency,
                            $description,
                            [
                                'return_url'    => $successURL,
                                'cancel_url'    => $cancelURL,
                                'userID'        => $userID,
                                'credentials'   => $resolved['credentials'],
                                'metadata'      => array_merge($options['metadata'] ?? [], [
                                    'partnerID'     => $partnerID,
                                    'partnerTierID' => $partnerTierID,
                                ]),
                            ]
                        );

                        $redirectURL = $providerResult['approvalURL'] ?? '';
                    } else {
                        $providerResult = ['success' => false, 'message' => 'PayPalProvider class is not available'];
                    }
                    break;

                case 'coinbase':
                    // 🪙 Create a Coinbase Commerce Charge
                    // @see CoinbaseProvider::createCharge()
                    if (class_exists('CoinbaseProvider')) {
                        $providerResult = CoinbaseProvider::createCharge(
                            $finalPrice,
                            $currency,
                            $description,
                            $userID,
                            array_merge($options['metadata'] ?? [], [
                                'partnerID'     => $partnerID,
                                'partnerTierID' => $partnerTierID,
                                'credentials'   => $resolved['credentials'] ? 'partner_own' : 'signula',
                            ])
                        );

                        $redirectURL = $providerResult['hostedURL'] ?? '';
                    } else {
                        $providerResult = ['success' => false, 'message' => 'CoinbaseProvider class is not available'];
                    }
                    break;
            }

            // ❌ Provider returned an error
            if (!($providerResult['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $providerResult['message'] ?? 'Payment provider returned an error',
                ];
            }

            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 6: Record the payment with partner context
            // ═══════════════════════════════════════════════════════════════

            $totalDiscount = $providerDiscountAmount + $codeDiscountAmount;

            $paymentRecord = PaymentManager::recordPayment(
                $userID,
                $finalPrice,
                $provider,
                $provider,
                [
                    'currency'       => $currency,
                    'description'    => $description . ' (Partner: ' . $partnerID . ')',
                    'discountAmount' => $totalDiscount,
                    'discountCode'   => $options['discountCode'] ?? null,
                    'metadata'       => [
                        'partnerID'              => $partnerID,
                        'partnerTierID'          => $partnerTierID,
                        'paymentContext'          => $resolved['paymentContext'],
                        'providerDiscountPercent' => $providerDiscountPercent,
                        'providerDiscountAmount'  => $providerDiscountAmount,
                        'codeDiscountAmount'      => $codeDiscountAmount,
                        'basePrice'               => $basePrice,
                        'billingCycle'            => $billingCycle,
                    ],
                ]
            );

            $paymentID = $paymentRecord['paymentID'] ?? 0;

            // 📝 Update the payment record with partnerID and paymentContext
            // These columns were added to tblPayments in migration 012
            if ($paymentID > 0) {
                Database::query(
                    "UPDATE tblPayments SET partnerID = ?, paymentContext = ? WHERE paymentID = ?",
                    [$partnerID, $resolved['paymentContext'], $paymentID],
                    'isi'
                );
            }

            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 7: Record the service fee transaction
            // ═══════════════════════════════════════════════════════════════

            if ($paymentID > 0) {
                self::recordServiceFee($paymentID, $partnerID, $finalPrice, $currency, $resolved['feeType']);
            }

            // 📝 Log the checkout session creation
            ActivityLogger::log(
                $userID,
                'partner_checkout_created',
                'payment',
                'info',
                'Partner checkout session created: partner=' . $partnerID . ', provider=' . $provider
                    . ', tier=' . ($tier['tierName'] ?? $partnerTierID)
                    . ', amount=' . $currency . ' ' . number_format($finalPrice, 2),
                [
                    'partnerID'      => $partnerID,
                    'partnerTierID'  => $partnerTierID,
                    'provider'       => $provider,
                    'billingCycle'   => $billingCycle,
                    'basePrice'      => $basePrice,
                    'finalPrice'     => $finalPrice,
                    'providerDiscount' => $providerDiscountAmount,
                    'codeDiscount'   => $codeDiscountAmount,
                    'paymentContext'  => $resolved['paymentContext'],
                    'paymentID'      => $paymentID,
                ]
            );

            // 🔗 Dispatch webhook event
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('partner.checkout.created', [
                    'partnerID'     => $partnerID,
                    'userID'        => $userID,
                    'partnerTierID' => $partnerTierID,
                    'provider'      => $provider,
                    'amount'        => $finalPrice,
                    'currency'      => $currency,
                    'paymentID'     => $paymentID,
                ]);
            }

            return [
                'success'     => true,
                'redirectURL' => $redirectURL,
                'paymentID'   => $paymentID,
                'message'     => 'Checkout session created successfully',
            ];
        } catch (\Exception $e) {
            // ❌ Catch-all error handling
            ActivityLogger::log(
                $userID,
                'partner_checkout_error',
                'payment',
                'error',
                'Exception creating partner checkout session: ' . $e->getMessage(),
                [
                    'partnerID'     => $partnerID,
                    'partnerTierID' => $partnerTierID,
                    'provider'      => $provider,
                    'billingCycle'  => $billingCycle,
                ]
            );

            return [
                'success' => false,
                'message' => 'An error occurred while creating the checkout session',
            ];
        }
    }

    // ========================================================================
    // ✅ PAYMENT COMPLETION
    // ========================================================================

    /**
     * ✅ Process a Level 2 payment completion
     *
     * Called when a partner-related payment completes (via webhook or success
     * page redirect). Handles:
     * 1. Retrieves the payment record with partnerID and paymentContext
     * 2. If paymentContext is partner-related, records the service fee
     *    (if not already recorded during checkout creation)
     * 3. Creates an invoice record in tblInvoices
     * 4. If mode is 'signula_keys' (Option 2b), schedules a remittance task
     *    for the partner payout via tblBillingSchedule
     *
     * @param int $paymentID The internal payment record ID from tblPayments
     *
     * @return array ['success' => bool, 'message' => string]
     *
     * @see PaymentManager::completePayment() For the base payment completion logic
     *
     * @example
     * ```php
     * // Called from a webhook handler after provider confirms payment
     * $result = PartnerPaymentService::processPaymentCompletion($paymentID);
     * ```
     */
    public static function processPaymentCompletion(int $paymentID): array
    {
        try {
            // 🔍 Fetch the payment record with partner context
            $payment = Database::fetchOne(
                "SELECT * FROM tblPayments WHERE paymentID = ?",
                [$paymentID],
                'i'
            );

            if (!$payment) {
                return ['success' => false, 'message' => 'Payment record not found'];
            }

            $partnerID = $payment['partnerID'] ? (int)$payment['partnerID'] : null;
            $paymentContext = $payment['paymentContext'] ?? self::CONTEXT_SIGNULA_DIRECT;

            // 🔍 Check if this is a partner-related payment
            if (!$partnerID || $paymentContext === self::CONTEXT_SIGNULA_DIRECT) {
                return [
                    'success' => true,
                    'message' => 'Not a partner payment — no Level 2 processing required',
                ];
            }

            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 1: Ensure service fee is recorded
            // ═══════════════════════════════════════════════════════════════

            // 🔍 Check if a service fee transaction already exists for this payment
            // (It may have been recorded during createCheckoutSession)
            $existingFee = Database::fetchOne(
                "SELECT feeTransactionID FROM tblServiceFeeTransactions WHERE paymentID = ?",
                [$paymentID],
                'i'
            );

            if (!$existingFee) {
                // 💰 Determine the fee type from the payment context
                $feeType = ($paymentContext === self::CONTEXT_PARTNER_OWN_KEYS)
                    ? self::FEE_TYPE_OWN_KEYS
                    : self::FEE_TYPE_SIGNULA_KEYS;

                self::recordServiceFee(
                    $paymentID,
                    $partnerID,
                    (float)$payment['amount'],
                    $payment['currency'] ?? 'GBP',
                    $feeType
                );
            }

            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 2: Create an invoice
            // ═══════════════════════════════════════════════════════════════

            self::createInvoiceForPayment($paymentID, $payment);

            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 3: Schedule remittance if using SIGNula's keys (2b)
            // ═══════════════════════════════════════════════════════════════

            if ($paymentContext === self::CONTEXT_PARTNER_SIGNULA_KEYS) {
                // 📅 Schedule a remittance task for this partner
                // The billing scheduler will batch these into periodic payouts
                // @see tblBillingSchedule.taskType = 'process_remittance'
                Database::query(
                    "INSERT INTO tblBillingSchedule
                        (taskType, targetType, targetID, scheduledFor, metadata)
                     VALUES ('process_remittance', 'partner', ?, NOW(), ?)",
                    [
                        $partnerID,
                        json_encode([
                            'paymentID'      => $paymentID,
                            'amount'         => (float)$payment['amount'],
                            'currency'       => $payment['currency'] ?? 'GBP',
                            'paymentContext'  => $paymentContext,
                        ]),
                    ],
                    'is'
                );
            }

            // 📝 Log the completion
            ActivityLogger::log(
                $payment['userID'] ? (int)$payment['userID'] : null,
                'partner_payment_completed',
                'payment',
                'info',
                'Partner payment completion processed: paymentID=' . $paymentID
                    . ', partner=' . $partnerID
                    . ', context=' . $paymentContext,
                [
                    'paymentID'      => $paymentID,
                    'partnerID'      => $partnerID,
                    'paymentContext'  => $paymentContext,
                    'amount'         => (float)$payment['amount'],
                    'currency'       => $payment['currency'] ?? 'GBP',
                ]
            );

            return [
                'success' => true,
                'message' => 'Partner payment completion processed successfully',
            ];
        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'partner_payment_completion_error',
                'payment',
                'error',
                'Exception processing partner payment completion: ' . $e->getMessage(),
                ['paymentID' => $paymentID]
            );

            return [
                'success' => false,
                'message' => 'An error occurred while processing payment completion',
            ];
        }
    }

    // ========================================================================
    // 🏷️ PARTNER SUBSCRIPTION TIER MANAGEMENT
    // ========================================================================

    /**
     * 📋 Get all subscription tiers for a partner
     *
     * Retrieves all subscription tiers defined by a specific partner from
     * tblPartnerSubscriptionTiers, ordered by displayOrder ascending.
     *
     * @param int  $partnerID       The partner's unique identifier
     * @param bool $includeInactive Include soft-deleted / inactive tiers (default: false)
     *
     * @return array List of tier records (may be empty if no tiers defined)
     *
     * @example
     * ```php
     * $tiers = PartnerPaymentService::getPartnerTiers(42);
     * foreach ($tiers as $tier) {
     *     echo $tier['tierName'] . ': ' . $tier['currency'] . ' ' . $tier['monthlyPrice'] . PHP_EOL;
     * }
     * ```
     */
    public static function getPartnerTiers(int $partnerID, bool $includeInactive = false): array
    {
        // 🔍 Build the query with optional inactive filter
        $sql = "SELECT * FROM tblPartnerSubscriptionTiers WHERE partnerID = ?";
        $params = [$partnerID];
        $types = 'i';

        if (!$includeInactive) {
            $sql .= " AND isActive = 1";
        }

        $sql .= " ORDER BY displayOrder ASC, tierName ASC";

        $tiers = Database::fetchAll($sql, $params, $types);

        // 📦 Decode JSON fields for each tier
        // features and featureLimits are stored as JSON in the database
        foreach ($tiers as &$tier) {
            if (!empty($tier['features']) && is_string($tier['features'])) {
                $tier['features'] = json_decode($tier['features'], true) ?? [];
            }
            if (!empty($tier['featureLimits']) && is_string($tier['featureLimits'])) {
                $tier['featureLimits'] = json_decode($tier['featureLimits'], true) ?? [];
            }
        }
        unset($tier); // 🧹 Break the reference from the foreach

        return $tiers;
    }

    /**
     * 🔍 Get a single partner subscription tier by ID
     *
     * Retrieves a single tier record from tblPartnerSubscriptionTiers.
     * Returns null if the tier does not exist.
     *
     * @param int $partnerTierID The tier's unique identifier (PK: tierID)
     *
     * @return array|null Tier record or null if not found
     *
     * @example
     * ```php
     * $tier = PartnerPaymentService::getPartnerTier(5);
     * if ($tier) {
     *     echo $tier['tierName'] . ' costs ' . $tier['monthlyPrice'] . '/month';
     * }
     * ```
     */
    public static function getPartnerTier(int $partnerTierID): ?array
    {
        $tier = Database::fetchOne(
            "SELECT * FROM tblPartnerSubscriptionTiers WHERE tierID = ?",
            [$partnerTierID],
            'i'
        );

        if (!$tier) {
            return null;
        }

        // 📦 Decode JSON fields
        if (!empty($tier['features']) && is_string($tier['features'])) {
            $tier['features'] = json_decode($tier['features'], true) ?? [];
        }
        if (!empty($tier['featureLimits']) && is_string($tier['featureLimits'])) {
            $tier['featureLimits'] = json_decode($tier['featureLimits'], true) ?? [];
        }

        return $tier;
    }

    /**
     * ➕ Create a new partner subscription tier
     *
     * Inserts a new tier into tblPartnerSubscriptionTiers for the given partner.
     * The tierSlug must be unique per partner (enforced by uk_partner_tier_slug).
     *
     * @param int   $partnerID The partner creating the tier
     * @param array $tierData  Tier attributes:
     *                         - 'tierName'        (string, required) Display name
     *                         - 'tierSlug'        (string, required) URL-safe identifier
     *                         - 'tierDescription'  (string) Description of tier benefits
     *                         - 'monthlyPrice'    (float)  Monthly price (default: 0.00)
     *                         - 'yearlyPrice'     (float)  Yearly price (default: 0.00)
     *                         - 'currency'        (string) ISO 4217 code (default: 'GBP')
     *                         - 'features'        (array)  Feature strings (JSON stored)
     *                         - 'featureLimits'   (array)  Numeric limits (JSON stored)
     *                         - 'trialDays'       (int)    Free trial period (default: 0)
     *                         - 'maxSubscribers'  (int)    Max subscribers (null = unlimited)
     *                         - 'displayOrder'    (int)    Sort order (default: 0)
     *
     * @return array ['success' => bool, 'partnerTierID' => ?int, 'message' => string]
     *
     * @example
     * ```php
     * $result = PartnerPaymentService::createPartnerTier(42, [
     *     'tierName'     => 'Professional',
     *     'tierSlug'     => 'professional',
     *     'monthlyPrice' => 29.99,
     *     'yearlyPrice'  => 299.99,
     *     'currency'     => 'GBP',
     *     'features'     => ['Unlimited API calls', 'Priority support'],
     *     'trialDays'    => 14,
     * ]);
     * ```
     */
    public static function createPartnerTier(int $partnerID, array $tierData): array
    {
        try {
            // 🛡️ Validate required fields
            if (empty($tierData['tierName'])) {
                return ['success' => false, 'message' => 'Tier name is required'];
            }

            if (empty($tierData['tierSlug'])) {
                return ['success' => false, 'message' => 'Tier slug is required'];
            }

            // 🔍 Sanitise the slug (lowercase, alphanumeric + hyphens only)
            // @see https://www.php.net/manual/en/function.preg-replace.php
            $tierSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower($tierData['tierSlug']));
            if (empty($tierSlug)) {
                return ['success' => false, 'message' => 'Invalid tier slug after sanitisation'];
            }

            // 📦 Encode JSON fields
            $featuresJson = !empty($tierData['features'])
                ? json_encode($tierData['features'])
                : '[]';

            $featureLimitsJson = !empty($tierData['featureLimits'])
                ? json_encode($tierData['featureLimits'])
                : null;

            // 💾 Insert the new tier record
            Database::query(
                "INSERT INTO tblPartnerSubscriptionTiers
                    (partnerID, tierName, tierSlug, tierDescription, monthlyPrice, yearlyPrice,
                     currency, features, featureLimits, trialDays, displayOrder)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $partnerID,
                    $tierData['tierName'],
                    $tierSlug,
                    $tierData['tierDescription'] ?? null,
                    (float)($tierData['monthlyPrice'] ?? 0.00),
                    (float)($tierData['yearlyPrice'] ?? 0.00),
                    $tierData['currency'] ?? 'GBP',
                    $featuresJson,
                    $featureLimitsJson,
                    (int)($tierData['trialDays'] ?? 0),
                    (int)($tierData['displayOrder'] ?? 0),
                ],
                'isssddsssii'
            );

            $partnerTierID = Database::getLastInsertId();

            // 📝 Log the tier creation
            ActivityLogger::log(
                null,
                'partner_tier_created',
                'payment',
                'info',
                'Partner subscription tier created: "' . $tierData['tierName'] . '" for partner ' . $partnerID,
                [
                    'partnerID'     => $partnerID,
                    'partnerTierID' => $partnerTierID,
                    'tierName'      => $tierData['tierName'],
                    'tierSlug'      => $tierSlug,
                    'monthlyPrice'  => (float)($tierData['monthlyPrice'] ?? 0),
                    'yearlyPrice'   => (float)($tierData['yearlyPrice'] ?? 0),
                    'currency'      => $tierData['currency'] ?? 'GBP',
                ]
            );

            return [
                'success'        => true,
                'partnerTierID'  => $partnerTierID,
                'message'        => 'Subscription tier created successfully',
            ];
        } catch (\Exception $e) {
            // 🔍 Check for duplicate slug error (unique key violation)
            if (strpos($e->getMessage(), 'Duplicate entry') !== false
                || strpos($e->getMessage(), 'uk_partner_tier_slug') !== false
            ) {
                return [
                    'success' => false,
                    'message' => 'A tier with this slug already exists for this partner',
                ];
            }

            ActivityLogger::log(
                null,
                'partner_tier_create_error',
                'payment',
                'error',
                'Failed to create partner tier: ' . $e->getMessage(),
                ['partnerID' => $partnerID, 'tierName' => $tierData['tierName'] ?? '']
            );

            return ['success' => false, 'message' => 'Failed to create subscription tier'];
        }
    }

    /**
     * ✏️ Update an existing partner subscription tier
     *
     * Updates the specified fields of an existing tier in tblPartnerSubscriptionTiers.
     * Only provided fields are updated; omitted fields retain their current values.
     *
     * @param int   $partnerTierID The tier's unique identifier (PK: tierID)
     * @param array $tierData      Fields to update (same keys as createPartnerTier)
     *
     * @return array ['success' => bool, 'message' => string]
     *
     * @example
     * ```php
     * $result = PartnerPaymentService::updatePartnerTier(5, [
     *     'monthlyPrice' => 34.99,
     *     'features'     => ['Unlimited API calls', 'Priority support', '24/7 phone support'],
     * ]);
     * ```
     */
    public static function updatePartnerTier(int $partnerTierID, array $tierData): array
    {
        try {
            // 🔍 Verify the tier exists
            $existing = self::getPartnerTier($partnerTierID);
            if (!$existing) {
                return ['success' => false, 'message' => 'Subscription tier not found'];
            }

            // 📋 Build the dynamic UPDATE query from provided fields
            // Only update fields that are explicitly provided in $tierData
            $setClauses = [];
            $params = [];
            $types = '';

            // 🔧 Map of field name → [SQL column, type character, transform function]
            $fieldMap = [
                'tierName'        => ['tierName', 's', null],
                'tierSlug'        => ['tierSlug', 's', function ($v) {
                    return preg_replace('/[^a-z0-9\-]/', '', strtolower($v));
                }],
                'tierDescription' => ['tierDescription', 's', null],
                'monthlyPrice'    => ['monthlyPrice', 'd', 'floatval'],
                'yearlyPrice'     => ['yearlyPrice', 'd', 'floatval'],
                'currency'        => ['currency', 's', null],
                'trialDays'       => ['trialDays', 'i', 'intval'],
                'displayOrder'    => ['displayOrder', 'i', 'intval'],
            ];

            foreach ($fieldMap as $key => [$column, $type, $transform]) {
                if (array_key_exists($key, $tierData)) {
                    $value = $tierData[$key];
                    if ($transform !== null) {
                        $value = is_callable($transform) ? $transform($value) : $value;
                    }
                    $setClauses[] = "`{$column}` = ?";
                    $params[] = $value;
                    $types .= $type;
                }
            }

            // 📦 Handle JSON fields separately
            if (array_key_exists('features', $tierData)) {
                $setClauses[] = "`features` = ?";
                $params[] = is_array($tierData['features']) ? json_encode($tierData['features']) : ($tierData['features'] ?? '[]');
                $types .= 's';
            }

            if (array_key_exists('featureLimits', $tierData)) {
                $setClauses[] = "`featureLimits` = ?";
                $params[] = is_array($tierData['featureLimits']) ? json_encode($tierData['featureLimits']) : $tierData['featureLimits'];
                $types .= 's';
            }

            // 🛡️ Nothing to update
            if (empty($setClauses)) {
                return ['success' => true, 'message' => 'No fields to update'];
            }

            // 📝 Add the WHERE clause parameter
            $params[] = $partnerTierID;
            $types .= 'i';

            $sql = "UPDATE tblPartnerSubscriptionTiers SET " . implode(', ', $setClauses) . " WHERE tierID = ?";
            Database::query($sql, $params, $types);

            // 📝 Log the update
            ActivityLogger::log(
                null,
                'partner_tier_updated',
                'payment',
                'info',
                'Partner subscription tier updated: tierID=' . $partnerTierID,
                [
                    'partnerTierID' => $partnerTierID,
                    'partnerID'     => $existing['partnerID'] ?? null,
                    'updatedFields' => array_keys($tierData),
                ]
            );

            return ['success' => true, 'message' => 'Subscription tier updated successfully'];
        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'partner_tier_update_error',
                'payment',
                'error',
                'Failed to update partner tier: ' . $e->getMessage(),
                ['partnerTierID' => $partnerTierID]
            );

            return ['success' => false, 'message' => 'Failed to update subscription tier'];
        }
    }

    /**
     * 🗑️ Soft-delete a partner subscription tier
     *
     * Sets isActive=0 rather than performing a hard DELETE, because existing
     * subscriptions may reference this tier. Soft-deleted tiers are excluded
     * from the default getPartnerTiers() listing but can be retrieved with
     * includeInactive=true.
     *
     * @param int $partnerTierID The tier's unique identifier (PK: tierID)
     *
     * @return array ['success' => bool, 'message' => string]
     *
     * @example
     * ```php
     * $result = PartnerPaymentService::deletePartnerTier(5);
     * // Tier is now inactive but still exists in the database
     * ```
     */
    public static function deletePartnerTier(int $partnerTierID): array
    {
        try {
            // 🔍 Verify the tier exists
            $existing = self::getPartnerTier($partnerTierID);
            if (!$existing) {
                return ['success' => false, 'message' => 'Subscription tier not found'];
            }

            // 🗑️ Soft delete: set isActive = 0
            // We don't hard DELETE because tblSubscriptions may reference this tierID
            // via the partnerTierID column added in migration 012
            Database::query(
                "UPDATE tblPartnerSubscriptionTiers SET isActive = 0 WHERE tierID = ?",
                [$partnerTierID],
                'i'
            );

            // 📝 Log the soft deletion
            ActivityLogger::log(
                null,
                'partner_tier_deleted',
                'payment',
                'info',
                'Partner subscription tier soft-deleted: "' . ($existing['tierName'] ?? $partnerTierID) . '"',
                [
                    'partnerTierID' => $partnerTierID,
                    'partnerID'     => $existing['partnerID'] ?? null,
                    'tierName'      => $existing['tierName'] ?? '',
                ]
            );

            return ['success' => true, 'message' => 'Subscription tier deactivated'];
        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'partner_tier_delete_error',
                'payment',
                'error',
                'Failed to soft-delete partner tier: ' . $e->getMessage(),
                ['partnerTierID' => $partnerTierID]
            );

            return ['success' => false, 'message' => 'Failed to deactivate subscription tier'];
        }
    }

    // ========================================================================
    // 🎁 PROVIDER DISCOUNTS
    // ========================================================================

    /**
     * 🎁 Get discount percentage for a specific payment provider
     *
     * Retrieves the discount percentage from tblProviderDiscounts for the given
     * payment provider. Uses a two-level lookup:
     * 1. First checks for a partner-specific discount (scope='partner')
     * 2. Falls back to the global discount (scope='global')
     *
     * This enables partners to offer different discounts to their customers
     * compared to SIGNula's global defaults.
     *
     * @param string   $provider  Payment method identifier (e.g., 'crypto', 'paypal', 'stripe')
     * @param int|null $partnerID Optional partner ID for partner-specific lookups
     *
     * @return float Discount percentage (e.g., 5.00 for 5%). Returns 0.00 if no discount found.
     *
     * @example
     * ```php
     * $discount = PartnerPaymentService::getProviderDiscount('crypto', 42);
     * // Returns 10.00 if partner 42 has a 10% crypto discount
     * // Falls back to global 5.00% if no partner-specific discount exists
     * ```
     */
    public static function getProviderDiscount(string $provider, ?int $partnerID = null): float
    {
        // 🔍 Step 1: Check for a partner-specific discount if partnerID is provided
        if ($partnerID !== null) {
            $partnerDiscount = Database::fetchOne(
                "SELECT discountPercentage FROM tblProviderDiscounts
                 WHERE scope = 'partner' AND partnerID = ? AND paymentMethod = ? AND isActive = 1",
                [$partnerID, $provider],
                'is'
            );

            if ($partnerDiscount) {
                return (float)$partnerDiscount['discountPercentage'];
            }
        }

        // 🔍 Step 2: Fall back to the global discount
        $globalDiscount = Database::fetchOne(
            "SELECT discountPercentage FROM tblProviderDiscounts
             WHERE scope = 'global' AND partnerID IS NULL AND paymentMethod = ? AND isActive = 1",
            [$provider],
            's'
        );

        if ($globalDiscount) {
            return (float)$globalDiscount['discountPercentage'];
        }

        // ❌ No discount found — return 0%
        return 0.00;
    }

    // ========================================================================
    // 📊 PARTNER EARNINGS & REPORTING
    // ========================================================================

    /**
     * 📊 Get partner earnings summary
     *
     * Aggregates financial data for a partner's earnings dashboard, including:
     * - Total gross revenue (from completed payments)
     * - Total service fees charged by SIGNula
     * - Total net earnings (gross - fees)
     * - Pending remittances (awaiting payout)
     * - Completed remittances (already paid out)
     *
     * @param int $partnerID The partner's unique identifier
     *
     * @return array [
     *     'totalGrossRevenue'     => float,
     *     'totalFees'             => float,
     *     'totalNetEarnings'      => float,
     *     'pendingRemittances'    => float,
     *     'completedRemittances'  => float,
     *     'transactionCount'      => int,
     * ]
     *
     * @example
     * ```php
     * $summary = PartnerPaymentService::getPartnerEarningsSummary(42);
     * echo 'Net earnings: ' . $summary['totalNetEarnings'];
     * ```
     */
    public static function getPartnerEarningsSummary(int $partnerID): array
    {
        try {
            // 💰 Query service fee transactions for aggregated earnings data
            // tblServiceFeeTransactions contains the authoritative fee records
            $feeStats = Database::fetchOne(
                "SELECT
                    COALESCE(SUM(grossAmount), 0)   AS totalGrossRevenue,
                    COALESCE(SUM(feeAmount), 0)      AS totalFees,
                    COALESCE(SUM(netToPartner), 0)   AS totalNetEarnings,
                    COUNT(*)                         AS transactionCount
                 FROM tblServiceFeeTransactions
                 WHERE partnerID = ?
                   AND status IN ('pending', 'collected', 'remitted')",
                [$partnerID],
                'i'
            );

            // 💸 Query remittance totals (pending vs. completed payouts)
            $remittanceStats = Database::fetchOne(
                "SELECT
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pendingRemittances,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS completedRemittances
                 FROM tblRemittances
                 WHERE partnerID = ?",
                [$partnerID],
                'i'
            );

            return [
                'totalGrossRevenue'    => (float)($feeStats['totalGrossRevenue'] ?? 0),
                'totalFees'            => (float)($feeStats['totalFees'] ?? 0),
                'totalNetEarnings'     => (float)($feeStats['totalNetEarnings'] ?? 0),
                'transactionCount'     => (int)($feeStats['transactionCount'] ?? 0),
                'pendingRemittances'   => (float)($remittanceStats['pendingRemittances'] ?? 0),
                'completedRemittances' => (float)($remittanceStats['completedRemittances'] ?? 0),
            ];
        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'partner_earnings_summary_error',
                'payment',
                'error',
                'Failed to retrieve partner earnings summary: ' . $e->getMessage(),
                ['partnerID' => $partnerID]
            );

            return [
                'totalGrossRevenue'    => 0.00,
                'totalFees'            => 0.00,
                'totalNetEarnings'     => 0.00,
                'transactionCount'     => 0,
                'pendingRemittances'   => 0.00,
                'completedRemittances' => 0.00,
            ];
        }
    }

    // ========================================================================
    // 🌍 COUNTRY-BASED DISCOUNT VALIDATION
    // ========================================================================

    /**
     * 🌍 Validate country eligibility for a discount code
     *
     * Implements a three-step country detection cascade to determine the user's
     * country for discount code eligibility:
     *
     * 1. **Billing address country** — Most reliable, provided at checkout
     * 2. **User profile country** — From tblUsers.country field
     * 3. **IP geolocation** — Using ip-api.com free tier as last resort
     *
     * After determining the country, checks the discount code's allowedCountries
     * and blockedCountries JSON arrays in tblDiscountCodes.
     *
     * @param string      $discountCode   The discount code to validate
     * @param string|null $billingCountry  ISO 3166-1 alpha-2 country code from billing address
     * @param int|null    $userID          User ID for profile country lookup
     * @param string|null $ipAddress       IP address for geolocation fallback
     *
     * @return array [
     *     'allowed'          => bool,
     *     'detectedCountry'  => string  (ISO 3166-1 alpha-2 code, or empty),
     *     'method'           => string  (billing_address | user_profile | ip_geolocation | unknown),
     *     'message'          => string  (reason if not allowed),
     * ]
     *
     * @see https://ip-api.com/docs/api:json (Free IP geolocation API)
     *
     * @example
     * ```php
     * $result = PartnerPaymentService::validateCountryForDiscount(
     *     'UK_ONLY_10',
     *     null,          // No billing country provided
     *     42,            // User ID for profile lookup
     *     '203.0.113.1'  // Fallback IP address
     * );
     * if ($result['allowed']) {
     *     echo 'Discount valid! Country detected: ' . $result['detectedCountry'];
     * }
     * ```
     */
    public static function validateCountryForDiscount(
        string $discountCode,
        ?string $billingCountry,
        ?int $userID,
        ?string $ipAddress
    ): array {
        try {
            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 1: Detect the user's country (cascade)
            // ═══════════════════════════════════════════════════════════════

            $detectedCountry = '';
            $detectionMethod = 'unknown';

            // 🏠 Method 1: Use the billing address country if provided
            // This is the most reliable source as it comes from the payment form
            if (!empty($billingCountry)) {
                $detectedCountry = strtoupper(trim($billingCountry));
                $detectionMethod = 'billing_address';
            }

            // 👤 Method 2: Fall back to the user's profile country
            // Query the tblUsers.country field for the user's stored country
            if (empty($detectedCountry) && $userID !== null) {
                $userProfile = Database::fetchOne(
                    "SELECT country FROM tblUsers WHERE userID = ?",
                    [$userID],
                    'i'
                );

                if ($userProfile && !empty($userProfile['country'])) {
                    $detectedCountry = strtoupper(trim($userProfile['country']));
                    $detectionMethod = 'user_profile';
                }
            }

            // 🌐 Method 3: Fall back to IP geolocation using ip-api.com
            // Free tier: max 45 requests/minute, HTTP only (no HTTPS on free tier)
            // @see https://ip-api.com/docs/api:json
            if (empty($detectedCountry) && !empty($ipAddress)) {
                $geoResult = self::geolocateIP($ipAddress);
                if (!empty($geoResult['countryCode'])) {
                    $detectedCountry = strtoupper($geoResult['countryCode']);
                    $detectionMethod = 'ip_geolocation';
                }
            }

            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 2: Look up the discount code's country restrictions
            // ═══════════════════════════════════════════════════════════════

            $discount = Database::fetchOne(
                "SELECT allowedCountries, blockedCountries FROM tblDiscountCodes WHERE code = ? AND isActive = 1",
                [$discountCode],
                's'
            );

            // ❌ Discount code not found or inactive
            if (!$discount) {
                return [
                    'allowed'         => false,
                    'detectedCountry' => $detectedCountry,
                    'method'          => $detectionMethod,
                    'message'         => 'Discount code not found or is inactive',
                ];
            }

            // 📦 Decode the country restriction JSON arrays
            $allowedCountries = null;
            $blockedCountries = null;

            if (!empty($discount['allowedCountries'])) {
                $allowedCountries = json_decode($discount['allowedCountries'], true);
                if (!is_array($allowedCountries)) {
                    $allowedCountries = null; // Invalid JSON — ignore restriction
                }
            }

            if (!empty($discount['blockedCountries'])) {
                $blockedCountries = json_decode($discount['blockedCountries'], true);
                if (!is_array($blockedCountries)) {
                    $blockedCountries = null; // Invalid JSON — ignore restriction
                }
            }

            // 🔍 If no country restrictions exist, the discount is allowed globally
            if ($allowedCountries === null && $blockedCountries === null) {
                return [
                    'allowed'         => true,
                    'detectedCountry' => $detectedCountry,
                    'method'          => $detectionMethod,
                    'message'         => '',
                ];
            }

            // ⚠️ If we couldn't detect the country and restrictions exist, deny
            if (empty($detectedCountry)) {
                return [
                    'allowed'         => false,
                    'detectedCountry' => '',
                    'method'          => $detectionMethod,
                    'message'         => 'Could not determine your country for discount eligibility',
                ];
            }

            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 3: Check the detected country against restrictions
            // ═══════════════════════════════════════════════════════════════

            // 🚫 Check blocked countries first (blocklist takes priority)
            if ($blockedCountries !== null && in_array($detectedCountry, $blockedCountries, true)) {
                return [
                    'allowed'         => false,
                    'detectedCountry' => $detectedCountry,
                    'method'          => $detectionMethod,
                    'message'         => 'This discount code is not available in your country',
                ];
            }

            // ✅ Check allowed countries (allowlist — must be in the list)
            if ($allowedCountries !== null && !in_array($detectedCountry, $allowedCountries, true)) {
                return [
                    'allowed'         => false,
                    'detectedCountry' => $detectedCountry,
                    'method'          => $detectionMethod,
                    'message'         => 'This discount code is only available in specific countries',
                ];
            }

            // ✅ All checks passed — discount is allowed for this country
            return [
                'allowed'         => true,
                'detectedCountry' => $detectedCountry,
                'method'          => $detectionMethod,
                'message'         => '',
            ];
        } catch (\Exception $e) {
            // ❌ On any error, deny the discount for safety
            ActivityLogger::log(
                $userID,
                'discount_country_validation_error',
                'payment',
                'error',
                'Exception during country validation for discount: ' . $e->getMessage(),
                [
                    'discountCode'   => $discountCode,
                    'billingCountry' => $billingCountry,
                    'ipAddress'      => $ipAddress,
                ]
            );

            return [
                'allowed'         => false,
                'detectedCountry' => '',
                'method'          => 'error',
                'message'         => 'An error occurred during country validation',
            ];
        }
    }

    // ========================================================================
    // 🔒 PRIVATE HELPER METHODS
    // ========================================================================

    /**
     * ⚖️ Check Option 2b compliance for a specific provider
     *
     * Verifies that the necessary platform features are enabled for using
     * SIGNula's payment keys on behalf of a partner (Option 2b). Each provider
     * has different regulatory/ToS requirements:
     *
     * - Stripe: Requires Stripe Connect (payment.stripe.connect_enabled)
     * - PayPal: Requires PayPal Commerce Platform (payment.paypal.commerce_platform_enabled)
     * - Coinbase: No platform feature required (operator assumes responsibility)
     *
     * @param string $provider Payment provider to check
     *
     * @return array ['allowed' => bool, 'message' => string]
     */
    private static function checkOption2bCompliance(string $provider): array
    {
        switch ($provider) {
            case 'stripe':
                // ⚖️ Stripe requires Stripe Connect for marketplace payments
                // @see https://docs.stripe.com/connect
                $connectEnabled = getSetting('payment.stripe.connect_enabled', '0');
                if ($connectEnabled !== '1') {
                    return [
                        'allowed' => false,
                        'message' => 'Stripe Connect must be enabled for SIGNula-managed partner payments. '
                            . 'Enable it in Settings > Payment > Stripe > Stripe Connect.',
                    ];
                }
                return ['allowed' => true, 'message' => ''];

            case 'paypal':
                // ⚖️ PayPal requires Commerce Platform for marketplace fee splits
                // @see https://developer.paypal.com/docs/platforms/
                $commerceEnabled = getSetting('payment.paypal.commerce_platform_enabled', '0');
                if ($commerceEnabled !== '1') {
                    return [
                        'allowed' => false,
                        'message' => 'PayPal Commerce Platform must be enabled for SIGNula-managed partner payments. '
                            . 'Enable it in Settings > Payment > PayPal > Commerce Platform.',
                    ];
                }
                return ['allowed' => true, 'message' => ''];

            case 'coinbase':
                // ⚖️ Coinbase: operator assumes full regulatory responsibility
                // No platform feature flag required — the admin must understand the
                // implications of collecting and redistributing crypto payments.
                // This should be clearly documented in the admin UI and ToS.
                return ['allowed' => true, 'message' => ''];

            default:
                return [
                    'allowed' => false,
                    'message' => 'Unknown provider: ' . $provider,
                ];
        }
    }

    /**
     * 💰 Record a service fee transaction
     *
     * Creates a record in tblServiceFeeTransactions for the fee charged on a
     * partner payment. Looks up the current active fee schedule from
     * tblServiceFees based on the fee type (partner_own_keys or partner_signula_keys).
     *
     * @param int    $paymentID The payment ID triggering the fee
     * @param int    $partnerID The partner being charged the fee
     * @param float  $grossAmount The full payment amount before fee deduction
     * @param string $currency ISO 4217 currency code
     * @param string $feeType Fee type: 'partner_own_keys' or 'partner_signula_keys'
     *
     * @return void
     */
    private static function recordServiceFee(
        int $paymentID,
        int $partnerID,
        float $grossAmount,
        string $currency,
        string $feeType
    ): void {
        try {
            // 🔍 Fetch the active fee schedule for this fee type
            // Get the most recent active fee schedule that is currently in effect
            $feeSchedule = Database::fetchOne(
                "SELECT feeID, feePercentage, fixedFeeAmount, minFee, maxFee
                 FROM tblServiceFees
                 WHERE feeType = ? AND isActive = 1 AND effectiveFrom <= CURDATE()
                   AND (effectiveUntil IS NULL OR effectiveUntil >= CURDATE())
                 ORDER BY effectiveFrom DESC
                 LIMIT 1",
                [$feeType],
                's'
            );

            // ⚠️ No active fee schedule found — use the setting defaults
            if (!$feeSchedule) {
                // 📋 Fall back to the settings-based fee percentages
                $settingKey = ($feeType === self::FEE_TYPE_OWN_KEYS)
                    ? 'payment.service_fee.own_keys_percent'
                    : 'payment.service_fee.signula_keys_percent';

                $defaultPercent = ($feeType === self::FEE_TYPE_OWN_KEYS) ? '10.00' : '30.00';
                $feePercent = (float)getSetting($settingKey, $defaultPercent);

                // 💰 Calculate the fee
                $feeAmount = round($grossAmount * ($feePercent / 100), 2);
                $netToPartner = round($grossAmount - $feeAmount, 2);

                // ⚠️ Log that we used fallback settings (no tblServiceFees record)
                ActivityLogger::log(
                    null,
                    'service_fee_fallback',
                    'payment',
                    'warning',
                    'No active fee schedule found in tblServiceFees for type "' . $feeType
                        . '"; using fallback setting: ' . $feePercent . '%',
                    [
                        'paymentID' => $paymentID,
                        'partnerID' => $partnerID,
                        'feeType'   => $feeType,
                    ]
                );

                // 💾 We can't insert into tblServiceFeeTransactions without a feeID
                // Just log the fee calculation for now
                return;
            }

            // 💰 Calculate the fee using the schedule
            $feePercent = (float)$feeSchedule['feePercentage'];
            $fixedFee = (float)($feeSchedule['fixedFeeAmount'] ?? 0);

            // 🧮 Fee = (grossAmount * percentage / 100) + fixedFeeAmount
            $feeAmount = round(($grossAmount * ($feePercent / 100)) + $fixedFee, 2);

            // 🛡️ Apply min/max fee caps if defined
            if ($feeSchedule['minFee'] !== null && $feeAmount < (float)$feeSchedule['minFee']) {
                $feeAmount = (float)$feeSchedule['minFee'];
            }
            if ($feeSchedule['maxFee'] !== null && $feeAmount > (float)$feeSchedule['maxFee']) {
                $feeAmount = (float)$feeSchedule['maxFee'];
            }

            $netToPartner = round($grossAmount - $feeAmount, 2);

            // 🛡️ Ensure net amount is not negative
            if ($netToPartner < 0) {
                $netToPartner = 0.00;
            }

            // 💾 Insert the service fee transaction record
            Database::query(
                "INSERT INTO tblServiceFeeTransactions
                    (paymentID, partnerID, feeID, grossAmount, feePercentageApplied,
                     feeAmount, netToPartner, currency, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                [
                    $paymentID,
                    $partnerID,
                    (int)$feeSchedule['feeID'],
                    $grossAmount,
                    $feePercent,
                    $feeAmount,
                    $netToPartner,
                    $currency,
                ],
                'iiiddds s'
            );

            // 📝 Log the fee transaction
            ActivityLogger::log(
                null,
                'service_fee_recorded',
                'payment',
                'info',
                'Service fee recorded: ' . $currency . ' ' . number_format($feeAmount, 2)
                    . ' (' . $feePercent . '%) on payment ' . $paymentID
                    . ' | Net to partner: ' . $currency . ' ' . number_format($netToPartner, 2),
                [
                    'paymentID'   => $paymentID,
                    'partnerID'   => $partnerID,
                    'feeID'       => (int)$feeSchedule['feeID'],
                    'grossAmount' => $grossAmount,
                    'feePercent'  => $feePercent,
                    'feeAmount'   => $feeAmount,
                    'netToPartner' => $netToPartner,
                    'currency'    => $currency,
                    'feeType'     => $feeType,
                ]
            );
        } catch (\Exception $e) {
            // ❌ Fee recording failure should NOT block the payment
            // Log the error but don't throw — the payment itself is valid
            ActivityLogger::log(
                null,
                'service_fee_record_error',
                'payment',
                'error',
                'Failed to record service fee: ' . $e->getMessage(),
                [
                    'paymentID' => $paymentID,
                    'partnerID' => $partnerID,
                    'feeType'   => $feeType,
                ]
            );
        }
    }

    /**
     * 🧾 Create an invoice record for a completed payment
     *
     * Generates an invoice in tblInvoices linked to the payment record.
     * The invoice number follows the SIGNula format: SIG-YYYYMMDD-NNNNN.
     *
     * @param int   $paymentID The payment record ID
     * @param array $payment   The full payment row from tblPayments
     *
     * @return void
     */
    private static function createInvoiceForPayment(int $paymentID, array $payment): void
    {
        try {
            // 🔍 Check if an invoice already exists for this payment
            $existing = Database::fetchOne(
                "SELECT invoiceID FROM tblInvoices WHERE paymentID = ?",
                [$paymentID],
                'i'
            );

            if ($existing) {
                return; // Invoice already exists — don't create a duplicate
            }

            // 🔢 Generate an invoice number
            $invoicePrefix = getSetting('payment.invoice_prefix', 'SIG');
            $invoiceNumber = $invoicePrefix . '-' . date('Ymd') . '-' . str_pad(
                random_int(1, 99999),
                5,
                '0',
                STR_PAD_LEFT
            );

            // 🔍 Get user details for billing information
            $user = null;
            if (!empty($payment['userID'])) {
                $user = Database::fetchOne(
                    "SELECT displayName, email FROM tblUsers WHERE userID = ?",
                    [(int)$payment['userID']],
                    'i'
                );
            }

            $billedToName = $user['displayName'] ?? 'Customer';
            $billedToEmail = $user['email'] ?? '';

            // 📋 Build the line items JSON
            $lineItems = json_encode([
                [
                    'description' => $payment['description'] ?? 'Subscription Payment',
                    'quantity'    => 1,
                    'unitPrice'   => (float)$payment['amount'],
                    'amount'      => (float)$payment['amount'],
                ],
            ]);

            // 🔧 Get the billing entity details from settings
            $billedByName = getSetting('payment.invoice.company_name', 'MWBM Partners Ltd (t/a MWservices)');

            $amount = (float)$payment['amount'];
            $discountAmount = (float)($payment['discountAmount'] ?? 0);
            $taxAmount = (float)($payment['taxAmount'] ?? 0);
            $taxRate = (float)($payment['taxRate'] ?? 0);
            $total = $amount;
            $subtotal = $amount + $discountAmount; // Subtotal before discount

            // 💾 Insert the invoice record
            Database::query(
                "INSERT INTO tblInvoices
                    (invoiceNumber, paymentID, userID, partnerID, billedToName, billedToEmail,
                     billedByName, subtotal, discountAmount, taxRate, taxAmount, total,
                     currency, lineItems, status, issuedAt, paidAt)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', NOW(), NOW())",
                [
                    $invoiceNumber,
                    $paymentID,
                    $payment['userID'] ? (int)$payment['userID'] : null,
                    $payment['partnerID'] ? (int)$payment['partnerID'] : null,
                    $billedToName,
                    $billedToEmail,
                    $billedByName,
                    $subtotal,
                    $discountAmount,
                    $taxRate,
                    $taxAmount,
                    $total,
                    $payment['currency'] ?? 'GBP',
                    $lineItems,
                ],
                'siissssdddddss'
            );

            // 📝 Log invoice creation
            ActivityLogger::log(
                $payment['userID'] ? (int)$payment['userID'] : null,
                'invoice_created',
                'payment',
                'info',
                'Invoice ' . $invoiceNumber . ' created for payment ' . $paymentID,
                [
                    'invoiceNumber' => $invoiceNumber,
                    'paymentID'     => $paymentID,
                    'amount'        => $total,
                    'currency'      => $payment['currency'] ?? 'GBP',
                ]
            );
        } catch (\Exception $e) {
            // ❌ Invoice creation failure should NOT block payment completion
            ActivityLogger::log(
                null,
                'invoice_creation_error',
                'payment',
                'error',
                'Failed to create invoice for payment ' . $paymentID . ': ' . $e->getMessage(),
                ['paymentID' => $paymentID]
            );
        }
    }

    /**
     * 🌐 Geolocate an IP address using ip-api.com
     *
     * Uses the ip-api.com free tier to determine the country associated with
     * an IP address. This is used as a last-resort fallback for country
     * detection in discount code validation.
     *
     * Rate limits: 45 requests/minute on the free tier.
     * Protocol: HTTP only (free tier does not support HTTPS).
     *
     * @param string $ipAddress IPv4 or IPv6 address to geolocate
     *
     * @return array ['countryCode' => string, 'country' => string] or empty array on failure
     *
     * @see https://ip-api.com/docs/api:json
     */
    private static function geolocateIP(string $ipAddress): array
    {
        try {
            // 🛡️ Validate the IP address format
            // @see https://www.php.net/manual/en/function.filter-var.php
            if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
                return [];
            }

            // 🛡️ Skip private/reserved IP ranges (they won't geolocate)
            if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return [];
            }

            // 🌐 Call ip-api.com (free tier — HTTP only, max 45 req/min)
            // We only request the countryCode field to minimise response size
            // @see https://ip-api.com/docs/api:json
            $url = 'http://ip-api.com/json/' . urlencode($ipAddress) . '?fields=status,countryCode,country';

            // 🔧 Use cURL for the request
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::IP_GEOLOCATION_TIMEOUT,
                CURLOPT_TIMEOUT        => self::IP_GEOLOCATION_TIMEOUT,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                // ⚠️ No SSL verification needed — free tier is HTTP-only
                // This is acceptable because we only retrieve non-sensitive country data
            ]);

            $response = curl_exec($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            // ❌ cURL error — return empty (don't block the discount flow)
            if ($curlErrno !== 0 || empty($response)) {
                return [];
            }

            // 📦 Decode the JSON response
            $data = json_decode($response, true);

            // ✅ Successful geolocation
            if (isset($data['status']) && $data['status'] === 'success' && !empty($data['countryCode'])) {
                return [
                    'countryCode' => strtoupper($data['countryCode']),
                    'country'     => $data['country'] ?? '',
                ];
            }

            return [];
        } catch (\Exception $e) {
            // ❌ Silently fail — geolocation is a best-effort fallback
            return [];
        }
    }

    /**
     * 🧪 Test Stripe credentials directly via cURL
     *
     * Performs a lightweight Stripe API call (GET /v1/balance) using the
     * provided secret key to verify the credentials are valid.
     *
     * @param string $secretKey Stripe secret key (sk_test_... or sk_live_...)
     *
     * @return array ['success' => bool, 'message' => string]
     *
     * @see https://docs.stripe.com/api/balance/balance_retrieve
     */
    private static function testStripeCredentials(string $secretKey): array
    {
        try {
            // 🔧 Use cURL to hit the Stripe balance endpoint
            // This is the lightest possible authenticated API call
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => 'https://api.stripe.com/v1/balance',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $secretKey,
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if (!empty($curlError)) {
                return ['success' => false, 'message' => 'Connection error: ' . $curlError];
            }

            if ($httpCode === 200) {
                $mode = (strpos($secretKey, 'sk_test_') === 0) ? 'test' : 'live';
                return [
                    'success' => true,
                    'message' => 'Stripe API connection successful (' . $mode . ' mode)',
                ];
            }

            // 📦 Decode error response
            $data = json_decode($response, true);
            $errorMessage = $data['error']['message'] ?? 'Unknown error (HTTP ' . $httpCode . ')';

            return ['success' => false, 'message' => 'Stripe API error: ' . $errorMessage];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
        }
    }

    /**
     * 🧪 Test PayPal credentials via OAuth token exchange
     *
     * Attempts to obtain an OAuth 2.0 access token using the provided
     * client ID and secret to verify the credentials are valid.
     *
     * @param string $clientID     PayPal Client ID
     * @param string $clientSecret PayPal Client Secret
     * @param string $mode         API environment: 'sandbox' or 'live'
     *
     * @return array ['success' => bool, 'message' => string]
     *
     * @see https://developer.paypal.com/docs/api/reference/get-an-access-token/
     */
    private static function testPayPalCredentials(string $clientID, string $clientSecret, string $mode = 'sandbox'): array
    {
        try {
            // 🌐 Determine the correct base URL based on mode
            $baseURL = ($mode === 'live')
                ? 'https://api-m.paypal.com'
                : 'https://api-m.sandbox.paypal.com';

            $url = $baseURL . '/v1/oauth2/token';

            // 🔧 Attempt OAuth token exchange
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                CURLOPT_USERPWD        => $clientID . ':' . $clientSecret,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if (!empty($curlError)) {
                return ['success' => false, 'message' => 'Connection error: ' . $curlError];
            }

            $data = json_decode($response, true);

            if ($httpCode === 200 && isset($data['access_token'])) {
                return [
                    'success' => true,
                    'message' => 'PayPal API connection successful (' . $mode . ' mode)',
                ];
            }

            $errorMessage = $data['error_description'] ?? $data['error'] ?? 'Unknown error (HTTP ' . $httpCode . ')';
            return ['success' => false, 'message' => 'PayPal API error: ' . $errorMessage];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
        }
    }

    /**
     * 🧪 Test Coinbase Commerce credentials via charge listing
     *
     * Performs a lightweight API call (GET /charges?limit=1) using the provided
     * API key to verify the credentials are valid.
     *
     * @param string $apiKey Coinbase Commerce API key
     *
     * @return array ['success' => bool, 'message' => string]
     *
     * @see https://docs.cdp.coinbase.com/commerce-onchain/reference/lists-charges
     */
    private static function testCoinbaseCredentials(string $apiKey): array
    {
        try {
            // 🔧 Attempt to list 1 charge to verify credentials
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => 'https://api.commerce.coinbase.com/charges?limit=1',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'X-CC-Api-Key: ' . $apiKey,
                    'X-CC-Version: 2018-03-22',
                    'Accept: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if (!empty($curlError)) {
                return ['success' => false, 'message' => 'Connection error: ' . $curlError];
            }

            if ($httpCode === 200) {
                return [
                    'success' => true,
                    'message' => 'Coinbase Commerce API connection successful',
                ];
            }

            $data = json_decode($response, true);
            $errorMessage = $data['error']['message'] ?? 'Unknown error (HTTP ' . $httpCode . ')';

            return ['success' => false, 'message' => 'Coinbase API error: ' . $errorMessage];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
        }
    }
}
