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
 * @version 2.3.0-beta
 */

/**
 * ============================================================================
 * :coin: SIGNula - Coinbase Commerce Payment Provider
 * ============================================================================
 *
 * Purpose: Integrates Coinbase Commerce API for cryptocurrency payment
 *          processing within the SIGNula Universal SSO platform.
 *          Uses raw cURL (no Composer/SDK) for Dreamhost shared hosting
 *          compatibility.
 *
 * PHP Version: 8.3+
 *
 * Features:
 * - Create charges for cryptocurrency payments (BTC, ETH, USDT, USDC, etc.)
 * - Webhook verification and event handling for payment lifecycle
 * - Automatic crypto discount application (configurable percentage)
 * - Charge management (create, retrieve, list, cancel)
 * - Connection testing for admin configuration verification
 * - Full activity logging for all API interactions and payment events
 * - Secure API key handling via encrypted database storage
 *
 * Coinbase Commerce API:
 * - Base URL: https://api.commerce.coinbase.com
 * - Auth: X-CC-Api-Key header
 * - API Version: 2018-03-22
 * - All request/response bodies are JSON
 *
 * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/welcome
 * @see https://docs.cdp.coinbase.com/commerce-onchain/reference/creates-a-charge
 * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/webhooks-security
 *
 * v2.4.0 Changes:
 * - Added optional $credentials parameter to apiRequest() for partner API keys
 * - Supports Level 2 payments (Option 2a: partner uses own Coinbase keys)
 * - Backward compatible — all existing calls continue to use global SIGNula keys
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.1.0
 * @since      2.3.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// :no_entry_sign: Prevent direct access - this file must be included via the SIGNula bootstrap
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// :package: Require the core PaymentManager class for payment recording and management
// PaymentManager handles tblPayments CRUD, subscription management, and invoice generation
$paymentManagerPath = __DIR__ . DIRECTORY_SEPARATOR . 'PaymentManager.php';
if (file_exists($paymentManagerPath)) {
    require_once $paymentManagerPath;
} else {
    // :warning: PaymentManager is a critical dependency - log error if missing
    error_log('[SIGNula] CoinbaseProvider: PaymentManager.php not found at: ' . $paymentManagerPath);
}

// 🛡️ Load the BillingMode TEST-MODE guard (G-002 Stage S1 — see BillingMode.php
// for the full rationale). Every money-moving method below calls
// BillingMode::assertTestMode('coinbase') before making any Coinbase Commerce API request.
$billingModePath = __DIR__ . DIRECTORY_SEPARATOR . 'BillingMode.php';
if (file_exists($billingModePath)) {
    require_once $billingModePath;
} else {
    // ⚠️ The guard is a hard safety requirement — refuse to load without it
    error_log('[SIGNula] CoinbaseProvider: BillingMode.php not found at: ' . $billingModePath);
    throw new RuntimeException('Required dependency BillingMode.php is not available');
}

/**
 * :coin: Coinbase Commerce Payment Provider
 *
 * Provides static methods for interacting with the Coinbase Commerce API
 * to process cryptocurrency payments. All methods are static following
 * the SIGNula project pattern (no instantiation required).
 *
 * Settings are stored in tblSettings and retrieved via getSetting().
 * Sensitive values (API keys, webhook secrets) are encrypted in the
 * database and decrypted at runtime via SecurityUtils::decrypt().
 *
 * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/welcome
 */
class CoinbaseProvider
{
    // ========================================================================
    // :wrench: CONSTANTS
    // ========================================================================

    /**
     * @var string Coinbase Commerce API base URL
     * @see https://docs.cdp.coinbase.com/commerce-onchain/reference/
     */
    private const API_BASE_URL = 'https://api.commerce.coinbase.com';

    /**
     * @var string Coinbase Commerce API version header value
     * The API version is sent via X-CC-Version header on every request.
     * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/versioning
     */
    private const API_VERSION = '2018-03-22';

    /**
     * @var int cURL connection timeout in seconds
     * Prevents hanging requests if the Coinbase API is unresponsive.
     * @see https://www.php.net/manual/en/function.curl-setopt.php (CURLOPT_CONNECTTIMEOUT)
     */
    private const CONNECT_TIMEOUT = 10;

    /**
     * @var int cURL total request timeout in seconds
     * Maximum time the entire cURL transfer is allowed to take.
     * @see https://www.php.net/manual/en/function.curl-setopt.php (CURLOPT_TIMEOUT)
     */
    private const REQUEST_TIMEOUT = 30;

    /**
     * @var string Payment provider identifier used in tblPayments records
     */
    private const PROVIDER_NAME = 'coinbase_commerce';

    /**
     * @var string Default payment method label for crypto payments
     * This may be updated to a specific currency (e.g., 'crypto_btc')
     * once the actual cryptocurrency used is known from the webhook.
     */
    private const DEFAULT_PAYMENT_METHOD = 'crypto';

    // ========================================================================
    // :closed_lock_with_key: PRIVATE HELPERS
    // ========================================================================

    /**
     * :satellite: Make an API request to the Coinbase Commerce API
     *
     * Handles all HTTP communication with the Coinbase Commerce REST API
     * using PHP's cURL extension. Automatically attaches authentication
     * headers and handles JSON encoding/decoding.
     *
     * Request flow:
     * 1. Decrypt API key from database (stored encrypted in tblSettings)
     * 2. Build cURL request with proper headers and SSL verification
     * 3. Execute request and parse JSON response
     * 4. Log errors via ActivityLogger if request fails
     *
     * @param string     $method   HTTP method: 'GET', 'POST', 'PUT', 'DELETE'
     * @param string     $endpoint API endpoint path (e.g., '/charges', '/charges/{id}')
     * @param array|null $data     Optional request body data (will be JSON-encoded for POST/PUT)
     *
     * @return array Decoded JSON response array, or error array with 'error' key
     *
     * @see https://docs.cdp.coinbase.com/commerce-onchain/reference/
     * @see https://www.php.net/manual/en/book.curl.php
     * @see https://www.php.net/manual/en/function.curl-setopt.php
     */
    private static function apiRequest(string $method, string $endpoint, ?array $data = null, ?array $credentials = null): array
    {
        try {
            // 🔑 Resolve the API key — either from partner credentials or global settings
            // Partner credentials are provided when processing Level 2 payments (Option 2a: own_keys)
            // @see PartnerPaymentService::resolveApiKeys() for credential resolution logic
            $apiKey = null;

            if ($credentials !== null && !empty($credentials['api_key'])) {
                // 🏢 Using partner-provided API key (Level 2, Option 2a: own_keys)
                // The key has already been decrypted by PartnerPaymentService::resolveApiKeys()
                $apiKey = $credentials['api_key'];
            } else {
                // 🌐 Using SIGNula's global Coinbase Commerce API key
                $encryptedApiKey = getSetting('payment.crypto.api_key');
                if (empty($encryptedApiKey)) {
                    // ❌ No API key configured - cannot make API requests
                    ActivityLogger::log(
                        null,
                        'crypto_api_error',
                        'payment',
                        'error',
                        'Coinbase Commerce API key is not configured in tblSettings',
                        ['endpoint' => $endpoint, 'method' => $method]
                    );
                    return ['error' => 'Coinbase Commerce API key is not configured'];
                }

                // 🔓 Decrypt the API key using SecurityUtils
                // @see SecurityUtils::decrypt() for AES-256-CBC decryption details
                $apiKey = SecurityUtils::decrypt($encryptedApiKey);
            }

            if (empty($apiKey)) {
                // ❌ Decryption failed or returned empty - key may be corrupted
                ActivityLogger::log(
                    null,
                    'crypto_api_error',
                    'payment',
                    'error',
                    'Coinbase Commerce API key is empty — decryption may have failed',
                    ['endpoint' => $endpoint, 'method' => $method]
                );
                return ['error' => 'Coinbase Commerce API key is not available'];
            }

            // :globe_with_meridians: Build the full request URL
            // Example: https://api.commerce.coinbase.com/charges
            $url = self::API_BASE_URL . $endpoint;

            // :page_facing_up: Construct request headers
            // X-CC-Api-Key: Authentication header for Coinbase Commerce
            // X-CC-Version: API version for consistent response format
            // Content-Type: JSON for request bodies
            // Accept: JSON for response bodies
            // @see https://docs.cdp.coinbase.com/commerce-onchain/docs/welcome
            $headers = [
                'X-CC-Api-Key: ' . $apiKey,
                'X-CC-Version: ' . self::API_VERSION,
                'Content-Type: application/json',
                'Accept: application/json',
            ];

            // :gear: Initialise cURL handle
            // @see https://www.php.net/manual/en/function.curl-init.php
            $ch = curl_init();

            // :link: Set the target URL
            curl_setopt($ch, CURLOPT_URL, $url);

            // :page_with_curl: Return the response body as a string instead of outputting it
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // :shield: Enable SSL certificate verification for security
            // CURLOPT_SSL_VERIFYPEER: Verify the peer's SSL certificate
            // CURLOPT_SSL_VERIFYHOST: Verify that the certificate matches the hostname (2 = strict)
            // @see https://www.php.net/manual/en/function.curl-setopt.php
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            // :clock1: Set connection and request timeouts to prevent hanging
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT);

            // :label: Attach the authentication and content-type headers
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // :arrows_counterclockwise: Configure the HTTP method and request body
            $method = strtoupper($method);

            if ($method === 'POST') {
                // :outbox_tray: POST request with JSON-encoded body
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            } elseif ($method === 'PUT') {
                // :pencil2: PUT request with JSON-encoded body
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            } elseif ($method === 'DELETE') {
                // :wastebasket: DELETE request
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }
            // GET is the default cURL method, no special configuration needed

            // :rocket: Execute the cURL request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);

            // :broom: Close the cURL handle to free resources
            curl_close($ch);

            // :x: Check for cURL-level errors (network issues, DNS failures, timeouts, etc.)
            if ($curlErrno !== 0) {
                ActivityLogger::log(
                    null,
                    'crypto_api_error',
                    'payment',
                    'error',
                    'Coinbase Commerce API cURL error: ' . $curlError,
                    [
                        'endpoint'  => $endpoint,
                        'method'    => $method,
                        'curlErrno' => $curlErrno,
                        'curlError' => $curlError,
                    ]
                );
                return ['error' => 'API request failed: ' . $curlError];
            }

            // :page_facing_up: Decode the JSON response body
            // @see https://www.php.net/manual/en/function.json-decode.php
            $decoded = json_decode($response, true);

            // :warning: Check for JSON decoding errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                ActivityLogger::log(
                    null,
                    'crypto_api_error',
                    'payment',
                    'error',
                    'Coinbase Commerce API returned invalid JSON: ' . json_last_error_msg(),
                    [
                        'endpoint'     => $endpoint,
                        'method'       => $method,
                        'httpCode'     => $httpCode,
                        'rawResponse'  => mb_substr($response, 0, 500), // Truncate for safety
                    ]
                );
                return ['error' => 'Invalid JSON response from Coinbase Commerce API'];
            }

            // :x: Check for HTTP error status codes (4xx, 5xx)
            if ($httpCode >= 400) {
                $errorMessage = $decoded['error']['message'] ?? 'Unknown API error';
                $errorType = $decoded['error']['type'] ?? 'unknown';

                ActivityLogger::log(
                    null,
                    'crypto_api_error',
                    'payment',
                    'warning',
                    'Coinbase Commerce API error (HTTP ' . $httpCode . '): ' . $errorMessage,
                    [
                        'endpoint'  => $endpoint,
                        'method'    => $method,
                        'httpCode'  => $httpCode,
                        'errorType' => $errorType,
                        'errorMsg'  => $errorMessage,
                    ]
                );
                return [
                    'error'    => $errorMessage,
                    'httpCode' => $httpCode,
                    'type'     => $errorType,
                ];
            }

            // :white_check_mark: Return the decoded JSON response
            return $decoded;

        } catch (\Exception $e) {
            // :rotating_light: Catch any unexpected exceptions (should be rare)
            ActivityLogger::log(
                null,
                'crypto_api_exception',
                'payment',
                'error',
                'Unexpected exception during Coinbase Commerce API request: ' . $e->getMessage(),
                [
                    'endpoint' => $endpoint,
                    'method'   => $method,
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine(),
                ]
            );
            return ['error' => 'Unexpected error during API request'];
        }
    }

    /**
     * :gear: Get a setting value from tblSettings
     *
     * Convenience wrapper that queries tblSettings directly using the
     * Database class. This provides a consistent pattern with other
     * payment-related classes (PaymentManager, WebhookManager).
     *
     * @param string $key     Setting key (e.g., 'payment.crypto.enabled')
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
    // :white_check_mark: STATUS & CONFIGURATION METHODS
    // ========================================================================

    /**
     * :electric_plug: Check if the Coinbase Commerce crypto payment provider is enabled
     *
     * Verifies two conditions:
     * 1. The 'payment.crypto.enabled' setting is set to '1' in tblSettings
     * 2. A non-empty API key is configured in 'payment.crypto.api_key'
     *
     * Both conditions must be true for crypto payments to be available.
     * This method should be called before presenting crypto payment options
     * to users or processing any crypto-related operations.
     *
     * @return bool True if crypto payments are enabled and configured, false otherwise
     *
     * @example
     * ```php
     * if (CoinbaseProvider::isEnabled()) {
     *     // Show cryptocurrency payment options to the user
     * }
     * ```
     */
    public static function isEnabled(): bool
    {
        // :mag: Check the global enable/disable toggle
        $enabled = getSetting('payment.crypto.enabled');
        if ($enabled !== '1') {
            return false;
        }

        // :key: Verify that an API key is actually configured
        // An empty API key means the provider hasn't been set up yet
        $apiKey = getSetting('payment.crypto.api_key');
        if (empty($apiKey)) {
            return false;
        }

        return true;
    }

    /**
     * :moneybag: Get the list of supported cryptocurrency currencies
     *
     * Retrieves the JSON-encoded list of supported cryptocurrencies from
     * the 'payment.crypto.supported_currencies' setting in tblSettings.
     * Defaults to ['BTC', 'ETH', 'USDT', 'USDC'] if the setting is
     * missing or contains invalid JSON.
     *
     * These currency codes correspond to the cryptocurrencies that users
     * can select when making a payment via Coinbase Commerce.
     *
     * @return array List of supported cryptocurrency ticker symbols (e.g., ['BTC', 'ETH', 'USDT', 'USDC'])
     *
     * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/supported-networks
     *
     * @example
     * ```php
     * $currencies = CoinbaseProvider::getSupportedCurrencies();
     * // Returns: ['BTC', 'ETH', 'USDT', 'USDC']
     * ```
     */
    public static function getSupportedCurrencies(): array
    {
        // :inbox_tray: Retrieve the JSON-encoded currency list from settings
        $currenciesJson = getSetting('payment.crypto.supported_currencies');

        // :warning: Handle missing or empty setting with sensible defaults
        if (empty($currenciesJson)) {
            return ['BTC', 'ETH', 'USDT', 'USDC'];
        }

        // :page_facing_up: Decode the JSON string into a PHP array
        // @see https://www.php.net/manual/en/function.json-decode.php
        $decoded = json_decode($currenciesJson, true);

        // :shield: Validate that JSON decoding produced a valid array
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            // :warning: Invalid JSON in settings - fall back to defaults and log a warning
            ActivityLogger::log(
                null,
                'crypto_config_warning',
                'payment',
                'warning',
                'Invalid JSON in payment.crypto.supported_currencies setting, using defaults',
                ['rawValue' => mb_substr($currenciesJson, 0, 200)]
            );
            return ['BTC', 'ETH', 'USDT', 'USDC'];
        }

        return $decoded;
    }

    /**
     * :label: Get the configured crypto payment discount percentage
     *
     * Retrieves the discount percentage applied to payments made via
     * cryptocurrency. This incentivises users to pay with crypto by
     * offering a configurable discount (default: 5.00%).
     *
     * The discount is stored as a string in tblSettings under the key
     * 'payment.crypto.discount_percent' and is cast to a float.
     *
     * @return float Discount percentage (e.g., 5.00 for a 5% discount)
     *
     * @example
     * ```php
     * $discount = CoinbaseProvider::getDiscountPercent();
     * // Returns: 5.00
     * $discountedPrice = $originalPrice * (1 - ($discount / 100));
     * ```
     */
    public static function getDiscountPercent(): float
    {
        // :inbox_tray: Retrieve the discount percentage from settings
        // Default to '5.00' (5%) if not configured
        $discount = getSetting('payment.crypto.discount_percent');

        // :shield: Handle missing or empty setting
        if (empty($discount) && $discount !== '0' && $discount !== '0.00') {
            return 5.00;
        }

        return (float)$discount;
    }

    // ========================================================================
    // :credit_card: CHARGE MANAGEMENT
    // ========================================================================

    /**
     * :zap: Create a new Coinbase Commerce charge for cryptocurrency payment
     *
     * Creates a charge on the Coinbase Commerce API that the user can pay
     * using any supported cryptocurrency. The charge generates a hosted
     * payment page URL where the user completes the payment.
     *
     * Flow:
     * 1. Validate that crypto payments are enabled
     * 2. Apply the configured crypto discount to the amount
     * 3. Send POST /charges to the Coinbase Commerce API
     * 4. Record the pending payment via PaymentManager::recordPayment()
     * 5. Return charge details including the hosted payment URL
     *
     * The cryptocurrency type (BTC, ETH, etc.) is NOT determined at charge
     * creation time - the user selects their preferred crypto on the
     * Coinbase Commerce hosted payment page. The actual crypto used is
     * reported back via the webhook when the payment confirms.
     *
     * @param float  $amount      Original payment amount (before crypto discount)
     * @param string $currency    ISO 4217 currency code for the price (e.g., 'GBP', 'USD')
     * @param string $description Human-readable description of what is being purchased
     * @param int    $userID      SIGNula user ID making the payment
     * @param array  $metadata    Optional additional metadata (subscriptionID, tierSlug, etc.)
     *
     * @return array On success: ['success' => true, 'chargeID' => string, 'chargeCode' => string,
     *               'hostedURL' => string, 'expiresAt' => string, 'paymentID' => int,
     *               'originalAmount' => float, 'discountedAmount' => float, 'discountPercent' => float]
     *               On failure: ['success' => false, 'message' => string]
     *
     * @see https://docs.cdp.coinbase.com/commerce-onchain/reference/creates-a-charge
     *
     * @example
     * ```php
     * $result = CoinbaseProvider::createCharge(
     *     29.99,
     *     'GBP',
     *     'SIGNula Pro - Monthly Subscription',
     *     $userID,
     *     ['subscriptionID' => 42, 'tierSlug' => 'pro']
     * );
     *
     * if ($result['success']) {
     *     // Redirect user to $result['hostedURL'] for payment
     *     header('Location: ' . $result['hostedURL']);
     * }
     * ```
     */
    public static function createCharge(
        float $amount,
        string $currency,
        string $description,
        int $userID,
        array $metadata = []
    ): array {
        try {
            // 🛡️ TEST-MODE GUARD (G-002 §0/§9.1) — checked FIRST, before any other
            // logic or Coinbase Commerce API call. If billing.test_mode_guard is ON
            // and payment.coinbase.mode is 'live', this throws BillingModeException,
            // which the catch(\Exception) block below converts into the usual
            // ['success' => false, 'message' => …] response — no HTTP call is
            // ever reached. @see BillingMode::assertTestMode()
            BillingMode::assertTestMode('coinbase');

            // :shield: Verify that crypto payments are enabled and configured
            if (!self::isEnabled()) {
                return [
                    'success' => false,
                    'message' => 'Cryptocurrency payments are not currently enabled',
                ];
            }

            // :money_with_wings: Apply the crypto discount
            // The discount incentivises crypto payments and is stored in tblSettings
            $discountPercent = self::getDiscountPercent();
            $discountAmount = round($amount * ($discountPercent / 100), 2);
            $discountedAmount = round($amount - $discountAmount, 2);

            // :shield: Ensure the discounted amount is positive
            if ($discountedAmount <= 0) {
                return [
                    'success' => false,
                    'message' => 'Invalid payment amount after discount applied',
                ];
            }

            // :globe_with_meridians: Determine redirect URLs for after payment
            // These URLs are shown on the Coinbase Commerce hosted payment page
            $baseUrl = getSetting('app.base_url', 'https://signula.id');
            $successUrl = rtrim($baseUrl, '/') . '/payments/crypto/success';
            $cancelUrl = rtrim($baseUrl, '/') . '/payments/crypto/cancel';

            // :package: Build the charge payload for the Coinbase Commerce API
            // @see https://docs.cdp.coinbase.com/commerce-onchain/reference/creates-a-charge
            $chargeData = [
                'name'         => $description,
                'description'  => $description . ' (Crypto discount: ' . $discountPercent . '% applied)',
                'pricing_type' => 'fixed_price',
                'local_price'  => [
                    'amount'   => number_format($discountedAmount, 2, '.', ''),
                    'currency' => strtoupper($currency),
                ],
                'metadata' => array_merge([
                    'userID'           => (string)$userID,
                    'originalAmount'   => (string)$amount,
                    'discountPercent'  => (string)$discountPercent,
                    'discountAmount'   => (string)$discountAmount,
                    'source'           => 'signula',
                ], $metadata),
                'redirect_url' => $successUrl,
                'cancel_url'   => $cancelUrl,
            ];

            // :rocket: Send the charge creation request to Coinbase Commerce
            $response = self::apiRequest('POST', '/charges', $chargeData);

            // :x: Check for API errors
            if (isset($response['error'])) {
                ActivityLogger::log(
                    $userID,
                    'crypto_charge_failed',
                    'payment',
                    'error',
                    'Failed to create Coinbase Commerce charge: ' . ($response['error'] ?? 'Unknown error'),
                    [
                        'amount'          => $amount,
                        'discountedAmount'=> $discountedAmount,
                        'currency'        => $currency,
                        'errorDetail'     => $response['error'] ?? '',
                    ]
                );
                return [
                    'success' => false,
                    'message' => 'Failed to create cryptocurrency payment charge',
                ];
            }

            // :white_check_mark: Extract charge details from the API response
            $chargeID = $response['data']['id'] ?? '';
            $chargeCode = $response['data']['code'] ?? '';
            $hostedURL = $response['data']['hosted_url'] ?? '';
            $expiresAt = $response['data']['expires_at'] ?? '';

            // :shield: Validate that we received the expected response fields
            if (empty($chargeID) || empty($hostedURL)) {
                ActivityLogger::log(
                    $userID,
                    'crypto_charge_invalid_response',
                    'payment',
                    'error',
                    'Coinbase Commerce returned incomplete charge data',
                    ['response_keys' => array_keys($response['data'] ?? [])]
                );
                return [
                    'success' => false,
                    'message' => 'Received incomplete response from payment provider',
                ];
            }

            // :floppy_disk: Record the pending payment in tblPayments via PaymentManager
            // The paymentMethod will be updated to the specific crypto (e.g., 'crypto_btc')
            // once the webhook confirms which cryptocurrency was actually used
            $paymentResult = PaymentManager::recordPayment(
                $userID,
                $discountedAmount,
                self::DEFAULT_PAYMENT_METHOD,
                self::PROVIDER_NAME,
                [
                    'currency'       => $currency,
                    'transactionID'  => $chargeID,
                    'description'    => $description,
                    'discountAmount' => $discountAmount,
                    'discountCode'   => 'CRYPTO_' . (int)$discountPercent . 'PCT',
                    'metadata'       => [
                        'chargeID'        => $chargeID,
                        'chargeCode'      => $chargeCode,
                        'hostedURL'       => $hostedURL,
                        'expiresAt'       => $expiresAt,
                        'originalAmount'  => $amount,
                        'discountPercent' => $discountPercent,
                        'discountAmount'  => $discountAmount,
                        'subscriptionID'  => $metadata['subscriptionID'] ?? null,
                    ],
                    'subscriptionID' => $metadata['subscriptionID'] ?? null,
                ]
            );

            // :memo: Log the successful charge creation
            ActivityLogger::log(
                $userID,
                'crypto_charge_created',
                'payment',
                'info',
                'Coinbase Commerce charge created: ' . $chargeCode . ' for ' . $currency . ' '
                    . number_format($discountedAmount, 2) . ' (original: ' . number_format($amount, 2)
                    . ', ' . $discountPercent . '% crypto discount)',
                [
                    'chargeID'        => $chargeID,
                    'chargeCode'      => $chargeCode,
                    'hostedURL'       => $hostedURL,
                    'expiresAt'       => $expiresAt,
                    'originalAmount'  => $amount,
                    'discountedAmount'=> $discountedAmount,
                    'discountPercent' => $discountPercent,
                    'currency'        => $currency,
                    'paymentID'       => $paymentResult['paymentID'] ?? null,
                ]
            );

            // :white_check_mark: Return the charge details for the calling code
            return [
                'success'          => true,
                'chargeID'         => $chargeID,
                'chargeCode'       => $chargeCode,
                'hostedURL'        => $hostedURL,
                'expiresAt'        => $expiresAt,
                'paymentID'        => $paymentResult['paymentID'] ?? null,
                'invoiceNumber'    => $paymentResult['invoiceNumber'] ?? null,
                'originalAmount'   => $amount,
                'discountedAmount' => $discountedAmount,
                'discountPercent'  => $discountPercent,
            ];

        } catch (\Exception $e) {
            // :rotating_light: Catch any unexpected exceptions
            ActivityLogger::log(
                $userID,
                'crypto_charge_exception',
                'payment',
                'error',
                'Exception creating Coinbase Commerce charge: ' . $e->getMessage(),
                [
                    'amount'   => $amount,
                    'currency' => $currency,
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine(),
                ]
            );
            return [
                'success' => false,
                'message' => 'An unexpected error occurred while creating the payment',
            ];
        }
    }

    /**
     * :mag: Retrieve details for a specific Coinbase Commerce charge
     *
     * Fetches the current state and details of a charge by its unique ID.
     * This is useful for checking payment status, verifying charge details,
     * or displaying payment information to the user.
     *
     * @param string $chargeID The unique Coinbase Commerce charge ID (UUID format)
     *
     * @return array The charge data from the API, or an error array with 'error' key
     *
     * @see https://docs.cdp.coinbase.com/commerce-onchain/reference/gets-a-charge
     *
     * @example
     * ```php
     * $charge = CoinbaseProvider::getCharge('f765421f-fec4-4d12-b5d0-1234567890ab');
     * if (!isset($charge['error'])) {
     *     $status = $charge['data']['timeline'][0]['status'] ?? 'unknown';
     * }
     * ```
     */
    public static function getCharge(string $chargeID): array
    {
        // :shield: Validate the charge ID is not empty
        if (empty($chargeID)) {
            return ['error' => 'Charge ID is required'];
        }

        // :rocket: Fetch the charge from the Coinbase Commerce API
        // GET /charges/{chargeID}
        // @see https://docs.cdp.coinbase.com/commerce-onchain/reference/gets-a-charge
        $response = self::apiRequest('GET', '/charges/' . urlencode($chargeID));

        return $response;
    }

    /**
     * :scroll: List recent charges from Coinbase Commerce
     *
     * Retrieves a paginated list of charges created through the
     * Coinbase Commerce API. Useful for admin dashboards, reconciliation,
     * and auditing purposes.
     *
     * @param int $limit Maximum number of charges to return (1-100, default 25)
     *
     * @return array List of charge records, or an error array with 'error' key
     *
     * @see https://docs.cdp.coinbase.com/commerce-onchain/reference/lists-charges
     *
     * @example
     * ```php
     * $charges = CoinbaseProvider::listCharges(50);
     * if (!isset($charges['error'])) {
     *     foreach ($charges['data'] as $charge) {
     *         echo $charge['code'] . ': ' . $charge['timeline'][0]['status'] . PHP_EOL;
     *     }
     * }
     * ```
     */
    public static function listCharges(int $limit = 25): array
    {
        // :shield: Clamp the limit to the valid range (1-100)
        // Coinbase Commerce API supports a maximum of 100 charges per request
        $limit = max(1, min(100, $limit));

        // :rocket: Fetch the list of charges from the API
        // GET /charges?limit={limit}
        // @see https://docs.cdp.coinbase.com/commerce-onchain/reference/lists-charges
        $response = self::apiRequest('GET', '/charges?limit=' . $limit);

        return $response;
    }

    /**
     * :no_entry: Cancel an existing Coinbase Commerce charge
     *
     * Cancels a charge that has not yet been completed. This is useful
     * when a user decides not to proceed with a crypto payment, or when
     * an admin needs to void a pending charge.
     *
     * Note: Only charges in 'NEW' or 'PENDING' status can be cancelled.
     * Completed or expired charges cannot be cancelled.
     *
     * @param string $chargeID The unique Coinbase Commerce charge ID to cancel
     *
     * @return array API response, or error array with 'error' key
     *
     * @see https://docs.cdp.coinbase.com/commerce-onchain/reference/cancels-a-charge
     *
     * @example
     * ```php
     * $result = CoinbaseProvider::cancelCharge($chargeID);
     * if (!isset($result['error'])) {
     *     echo 'Charge cancelled successfully';
     * }
     * ```
     */
    public static function cancelCharge(string $chargeID): array
    {
        // :shield: Validate the charge ID is not empty
        if (empty($chargeID)) {
            return ['error' => 'Charge ID is required'];
        }

        // :rocket: Send the cancellation request to the Coinbase Commerce API
        // POST /charges/{chargeID}/cancel
        // @see https://docs.cdp.coinbase.com/commerce-onchain/reference/cancels-a-charge
        $response = self::apiRequest('POST', '/charges/' . urlencode($chargeID) . '/cancel');

        // :memo: Log the cancellation attempt
        if (!isset($response['error'])) {
            ActivityLogger::log(
                null,
                'crypto_charge_cancelled',
                'payment',
                'info',
                'Coinbase Commerce charge cancelled: ' . $chargeID,
                ['chargeID' => $chargeID]
            );
        } else {
            ActivityLogger::log(
                null,
                'crypto_charge_cancel_failed',
                'payment',
                'warning',
                'Failed to cancel Coinbase Commerce charge: ' . $chargeID . ' - ' . ($response['error'] ?? ''),
                ['chargeID' => $chargeID, 'error' => $response['error'] ?? '']
            );
        }

        return $response;
    }

    // ========================================================================
    // :bell: WEBHOOK HANDLING
    // ========================================================================

    /**
     * :shield: Verify a Coinbase Commerce webhook signature
     *
     * Verifies the authenticity of an incoming webhook request by comparing
     * the HMAC-SHA256 signature in the X-CC-Webhook-Signature header against
     * a signature computed from the raw request body and the shared webhook
     * secret.
     *
     * This MUST be called before processing any webhook event to prevent
     * forged/spoofed webhook attacks. If verification fails, the webhook
     * payload should be rejected and the event discarded.
     *
     * Signature verification process:
     * 1. Retrieve and decrypt the webhook secret from tblSettings
     * 2. Compute HMAC-SHA256 of the raw payload using the secret
     * 3. Compare computed signature with the provided signature using
     *    hash_equals() to prevent timing attacks
     *
     * @param string $payload         Raw request body (the JSON string, NOT decoded)
     * @param string $signatureHeader Value of the X-CC-Webhook-Signature HTTP header
     *
     * @return bool True if the signature is valid, false otherwise
     *
     * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/webhooks-security
     * @see https://www.php.net/manual/en/function.hash-hmac.php
     * @see https://www.php.net/manual/en/function.hash-equals.php
     *
     * @example
     * ```php
     * $payload = file_get_contents('php://input');
     * $signature = $_SERVER['HTTP_X_CC_WEBHOOK_SIGNATURE'] ?? '';
     *
     * if (!CoinbaseProvider::verifyWebhookSignature($payload, $signature)) {
     *     http_response_code(401);
     *     die('Invalid signature');
     * }
     * ```
     */
    public static function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        try {
            // :shield: Reject empty payloads or signatures immediately
            if (empty($payload) || empty($signatureHeader)) {
                ActivityLogger::log(
                    null,
                    'crypto_webhook_invalid',
                    'payment',
                    'warning',
                    'Coinbase webhook verification failed: empty payload or signature',
                    [
                        'hasPayload'   => !empty($payload),
                        'hasSignature' => !empty($signatureHeader),
                    ]
                );
                return false;
            }

            // :key: Retrieve and decrypt the webhook shared secret
            // The secret is stored encrypted (isSensitive=1) in tblSettings
            $encryptedSecret = getSetting('payment.crypto.webhook_secret');
            if (empty($encryptedSecret)) {
                ActivityLogger::log(
                    null,
                    'crypto_webhook_error',
                    'payment',
                    'error',
                    'Coinbase webhook secret is not configured in tblSettings',
                    []
                );
                return false;
            }

            // :unlock: Decrypt the webhook secret
            $secret = SecurityUtils::decrypt($encryptedSecret);
            if (empty($secret)) {
                ActivityLogger::log(
                    null,
                    'crypto_webhook_error',
                    'payment',
                    'error',
                    'Failed to decrypt Coinbase webhook secret',
                    []
                );
                return false;
            }

            // :abacus: Compute the expected HMAC-SHA256 signature
            // @see https://www.php.net/manual/en/function.hash-hmac.php
            $computedSignature = hash_hmac('sha256', $payload, $secret);

            // :lock: Use timing-safe comparison to prevent timing attacks
            // hash_equals() compares strings in constant time regardless of
            // where the first difference occurs
            // @see https://www.php.net/manual/en/function.hash-equals.php
            $isValid = hash_equals($computedSignature, $signatureHeader);

            // :memo: Log the verification result
            if (!$isValid) {
                ActivityLogger::log(
                    null,
                    'crypto_webhook_signature_mismatch',
                    'payment',
                    'warning',
                    'Coinbase webhook signature verification failed - possible forged request',
                    [
                        'providedSignature' => mb_substr($signatureHeader, 0, 16) . '...',
                        'payloadLength'     => strlen($payload),
                        'remoteIP'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ]
                );
            }

            return $isValid;

        } catch (\Exception $e) {
            // :rotating_light: Log any unexpected exceptions during verification
            ActivityLogger::log(
                null,
                'crypto_webhook_exception',
                'payment',
                'error',
                'Exception during Coinbase webhook signature verification: ' . $e->getMessage(),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );
            return false;
        }
    }

    /**
     * :bell: Handle a verified Coinbase Commerce webhook event
     *
     * Processes webhook events from Coinbase Commerce to update payment
     * status in the SIGNula database. This method should ONLY be called
     * AFTER the webhook signature has been verified via verifyWebhookSignature().
     *
     * Supported event types:
     * - charge:confirmed  - Payment confirmed on blockchain (complete payment)
     * - charge:failed     - Payment failed or expired (mark as failed)
     * - charge:delayed    - Underpaid or delayed payment (log warning)
     * - charge:pending    - Payment detected, awaiting confirmations (update to processing)
     * - charge:resolved   - Previously unresolved charge resolved (complete payment)
     *
     * The cryptocurrency type (BTC, ETH, etc.) is determined from the
     * event data at confirmation time and used to update the payment
     * method in tblPayments for accurate reporting.
     *
     * @param array $event The decoded webhook event payload (must contain 'type' and 'data' keys)
     *
     * @return array Result with ['success' => bool, 'message' => string, 'eventType' => string]
     *
     * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/webhooks-events
     * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/webhooks-security
     *
     * @example
     * ```php
     * $payload = file_get_contents('php://input');
     * $signature = $_SERVER['HTTP_X_CC_WEBHOOK_SIGNATURE'] ?? '';
     *
     * if (CoinbaseProvider::verifyWebhookSignature($payload, $signature)) {
     *     $event = json_decode($payload, true)['event'] ?? [];
     *     $result = CoinbaseProvider::handleWebhookEvent($event);
     * }
     * ```
     */
    public static function handleWebhookEvent(array $event): array
    {
        try {
            // :mag: Extract the event type and charge data
            $eventType = $event['type'] ?? '';
            $chargeData = $event['data'] ?? [];
            $chargeID = $chargeData['id'] ?? '';
            $chargeCode = $chargeData['code'] ?? '';
            $chargeMetadata = $chargeData['metadata'] ?? [];

            // :bust_in_silhouette: Extract the user ID from the charge metadata
            // This was set when the charge was created via createCharge()
            $userID = !empty($chargeMetadata['userID']) ? (int)$chargeMetadata['userID'] : null;

            // :coin: Determine which cryptocurrency was used for payment
            // The payments array contains details of detected blockchain payments
            // The network field indicates which crypto network was used
            $cryptoCurrency = self::extractCryptoCurrency($chargeData);
            $paymentMethodLabel = !empty($cryptoCurrency)
                ? 'crypto_' . strtolower($cryptoCurrency)
                : self::DEFAULT_PAYMENT_METHOD;

            // :mag: Look up the corresponding payment record in tblPayments
            // We match on the charge ID stored as transactionID during createCharge()
            $payment = null;
            if (!empty($chargeID)) {
                $payment = Database::fetchOne(
                    "SELECT paymentID, status, userID FROM tblPayments WHERE transactionID = ? AND paymentProvider = ? ORDER BY createdAt DESC LIMIT 1",
                    [$chargeID, self::PROVIDER_NAME],
                    'ss'
                );
            }

            $paymentID = $payment ? (int)$payment['paymentID'] : null;

            // :arrows_counterclockwise: If we didn't get a userID from metadata, try from the payment record
            if ($userID === null && $payment) {
                $userID = (int)$payment['userID'];
            }

            // :memo: Log the incoming webhook event (all events are logged for auditing)
            ActivityLogger::log(
                $userID,
                'crypto_webhook_received',
                'payment',
                'info',
                'Coinbase Commerce webhook event received: ' . $eventType . ' for charge ' . $chargeCode,
                [
                    'eventType'      => $eventType,
                    'chargeID'       => $chargeID,
                    'chargeCode'     => $chargeCode,
                    'cryptoCurrency' => $cryptoCurrency,
                    'paymentID'      => $paymentID,
                ]
            );

            // :arrows_counterclockwise: Process the event based on its type
            // @see https://docs.cdp.coinbase.com/commerce-onchain/docs/webhooks-events
            switch ($eventType) {

                // =====================================================
                // :white_check_mark: charge:confirmed - Payment confirmed on blockchain
                // =====================================================
                case 'charge:confirmed':
                    // The payment has been confirmed with sufficient blockchain confirmations
                    // Mark the payment as completed in tblPayments

                    if ($paymentID) {
                        // :arrows_counterclockwise: Update the payment method to reflect the specific cryptocurrency used
                        if (!empty($cryptoCurrency)) {
                            Database::query(
                                "UPDATE tblPayments SET paymentMethod = ? WHERE paymentID = ?",
                                [$paymentMethodLabel, $paymentID],
                                'si'
                            );
                        }

                        // :white_check_mark: Complete the payment via PaymentManager
                        // This updates status to 'completed', sets paidAt, and activates linked subscriptions
                        $completeResult = PaymentManager::completePayment(
                            $paymentID,
                            $chargeID,
                            $chargeData['hosted_url'] ?? null
                        );

                        ActivityLogger::log(
                            $userID,
                            'crypto_payment_confirmed',
                            'payment',
                            'info',
                            'Cryptocurrency payment confirmed on blockchain: ' . $chargeCode
                                . ' via ' . strtoupper($cryptoCurrency ?: 'unknown'),
                            [
                                'chargeID'       => $chargeID,
                                'chargeCode'     => $chargeCode,
                                'paymentID'      => $paymentID,
                                'cryptoCurrency' => $cryptoCurrency,
                                'completeResult' => $completeResult['success'] ?? false,
                            ]
                        );

                        // :link: Dispatch webhook event for integrating services
                        if (class_exists('WebhookManager')) {
                            WebhookManager::dispatch('payment.crypto.confirmed', [
                                'paymentID'      => $paymentID,
                                'userID'         => $userID,
                                'chargeID'       => $chargeID,
                                'chargeCode'     => $chargeCode,
                                'cryptoCurrency' => $cryptoCurrency,
                            ]);
                        }

                        return [
                            'success'   => true,
                            'message'   => 'Payment confirmed and completed',
                            'eventType' => $eventType,
                            'paymentID' => $paymentID,
                        ];
                    }

                    // :warning: No matching payment record found - log for manual review
                    ActivityLogger::log(
                        $userID,
                        'crypto_webhook_orphan',
                        'payment',
                        'warning',
                        'Received charge:confirmed webhook but no matching payment found for charge: ' . $chargeCode,
                        ['chargeID' => $chargeID, 'chargeCode' => $chargeCode]
                    );

                    return [
                        'success'   => false,
                        'message'   => 'No matching payment record found for confirmed charge',
                        'eventType' => $eventType,
                    ];

                // =====================================================
                // :x: charge:failed - Payment failed or expired
                // =====================================================
                case 'charge:failed':
                    // The charge has failed - either the payment window expired
                    // or the payment was otherwise unsuccessful

                    if ($paymentID) {
                        // :x: Update payment status to 'failed' in tblPayments
                        Database::query(
                            "UPDATE tblPayments SET status = 'failed' WHERE paymentID = ? AND status IN ('pending', 'processing')",
                            [$paymentID],
                            'i'
                        );

                        ActivityLogger::log(
                            $userID,
                            'crypto_payment_failed',
                            'payment',
                            'warning',
                            'Cryptocurrency payment failed/expired for charge: ' . $chargeCode,
                            [
                                'chargeID'   => $chargeID,
                                'chargeCode' => $chargeCode,
                                'paymentID'  => $paymentID,
                            ]
                        );

                        // :link: Dispatch webhook event for integrating services
                        if (class_exists('WebhookManager')) {
                            WebhookManager::dispatch('payment.crypto.failed', [
                                'paymentID'  => $paymentID,
                                'userID'     => $userID,
                                'chargeID'   => $chargeID,
                                'chargeCode' => $chargeCode,
                            ]);
                        }

                        return [
                            'success'   => true,
                            'message'   => 'Payment marked as failed',
                            'eventType' => $eventType,
                            'paymentID' => $paymentID,
                        ];
                    }

                    return [
                        'success'   => false,
                        'message'   => 'No matching payment record found for failed charge',
                        'eventType' => $eventType,
                    ];

                // =====================================================
                // :hourglass_flowing_sand: charge:pending - Payment detected, awaiting confirmations
                // =====================================================
                case 'charge:pending':
                    // A payment has been detected on the blockchain but has not yet
                    // received enough confirmations to be considered final

                    if ($paymentID) {
                        // :arrows_counterclockwise: Update payment status to 'processing'
                        Database::query(
                            "UPDATE tblPayments SET status = 'processing' WHERE paymentID = ? AND status = 'pending'",
                            [$paymentID],
                            'i'
                        );

                        // :arrows_counterclockwise: Update the payment method with the detected cryptocurrency
                        if (!empty($cryptoCurrency)) {
                            Database::query(
                                "UPDATE tblPayments SET paymentMethod = ? WHERE paymentID = ?",
                                [$paymentMethodLabel, $paymentID],
                                'si'
                            );
                        }

                        ActivityLogger::log(
                            $userID,
                            'crypto_payment_pending',
                            'payment',
                            'info',
                            'Cryptocurrency payment detected on blockchain, awaiting confirmations: ' . $chargeCode
                                . ' via ' . strtoupper($cryptoCurrency ?: 'unknown'),
                            [
                                'chargeID'       => $chargeID,
                                'chargeCode'     => $chargeCode,
                                'paymentID'      => $paymentID,
                                'cryptoCurrency' => $cryptoCurrency,
                            ]
                        );

                        return [
                            'success'   => true,
                            'message'   => 'Payment status updated to processing (awaiting confirmations)',
                            'eventType' => $eventType,
                            'paymentID' => $paymentID,
                        ];
                    }

                    return [
                        'success'   => false,
                        'message'   => 'No matching payment record found for pending charge',
                        'eventType' => $eventType,
                    ];

                // =====================================================
                // :warning: charge:delayed - Underpaid or delayed payment
                // =====================================================
                case 'charge:delayed':
                    // The payment was either underpaid (sent less crypto than required)
                    // or arrived after the charge's payment window expired.
                    // This requires manual review/resolution.

                    ActivityLogger::log(
                        $userID,
                        'crypto_payment_delayed',
                        'payment',
                        'warning',
                        'Cryptocurrency payment delayed or underpaid for charge: ' . $chargeCode
                            . ' - requires manual review',
                        [
                            'chargeID'       => $chargeID,
                            'chargeCode'     => $chargeCode,
                            'paymentID'      => $paymentID,
                            'cryptoCurrency' => $cryptoCurrency,
                            'payments'       => $chargeData['payments'] ?? [],
                        ]
                    );

                    // :link: Dispatch webhook for admin notification
                    if (class_exists('WebhookManager')) {
                        WebhookManager::dispatch('payment.crypto.delayed', [
                            'paymentID'  => $paymentID,
                            'userID'     => $userID,
                            'chargeID'   => $chargeID,
                            'chargeCode' => $chargeCode,
                        ]);
                    }

                    return [
                        'success'   => true,
                        'message'   => 'Delayed payment event logged for manual review',
                        'eventType' => $eventType,
                        'paymentID' => $paymentID,
                    ];

                // =====================================================
                // :white_check_mark: charge:resolved - Previously unresolved charge resolved
                // =====================================================
                case 'charge:resolved':
                    // A previously unresolved charge (e.g., one marked as delayed)
                    // has been resolved by the merchant or automatically.
                    // Treat this as a successful payment completion.

                    if ($paymentID) {
                        // :arrows_counterclockwise: Update the payment method with the crypto used
                        if (!empty($cryptoCurrency)) {
                            Database::query(
                                "UPDATE tblPayments SET paymentMethod = ? WHERE paymentID = ?",
                                [$paymentMethodLabel, $paymentID],
                                'si'
                            );
                        }

                        // :white_check_mark: Complete the payment via PaymentManager
                        $completeResult = PaymentManager::completePayment(
                            $paymentID,
                            $chargeID,
                            $chargeData['hosted_url'] ?? null
                        );

                        ActivityLogger::log(
                            $userID,
                            'crypto_payment_resolved',
                            'payment',
                            'info',
                            'Previously unresolved cryptocurrency payment resolved: ' . $chargeCode,
                            [
                                'chargeID'       => $chargeID,
                                'chargeCode'     => $chargeCode,
                                'paymentID'      => $paymentID,
                                'cryptoCurrency' => $cryptoCurrency,
                                'completeResult' => $completeResult['success'] ?? false,
                            ]
                        );

                        // :link: Dispatch webhook event for integrating services
                        if (class_exists('WebhookManager')) {
                            WebhookManager::dispatch('payment.crypto.resolved', [
                                'paymentID'      => $paymentID,
                                'userID'         => $userID,
                                'chargeID'       => $chargeID,
                                'chargeCode'     => $chargeCode,
                                'cryptoCurrency' => $cryptoCurrency,
                            ]);
                        }

                        return [
                            'success'   => true,
                            'message'   => 'Resolved charge payment completed',
                            'eventType' => $eventType,
                            'paymentID' => $paymentID,
                        ];
                    }

                    return [
                        'success'   => false,
                        'message'   => 'No matching payment record found for resolved charge',
                        'eventType' => $eventType,
                    ];

                // =====================================================
                // :question: Unrecognised event type
                // =====================================================
                default:
                    // Log unrecognised event types for future investigation
                    // New event types may be added to the Coinbase Commerce API
                    ActivityLogger::log(
                        $userID,
                        'crypto_webhook_unknown',
                        'payment',
                        'warning',
                        'Received unrecognised Coinbase Commerce webhook event type: ' . $eventType,
                        [
                            'eventType'  => $eventType,
                            'chargeID'   => $chargeID,
                            'chargeCode' => $chargeCode,
                        ]
                    );

                    return [
                        'success'   => false,
                        'message'   => 'Unrecognised webhook event type: ' . $eventType,
                        'eventType' => $eventType,
                    ];
            }

        } catch (\Exception $e) {
            // :rotating_light: Catch any unexpected exceptions during webhook processing
            ActivityLogger::log(
                null,
                'crypto_webhook_exception',
                'payment',
                'error',
                'Exception processing Coinbase Commerce webhook event: ' . $e->getMessage(),
                [
                    'eventType' => $event['type'] ?? 'unknown',
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]
            );

            return [
                'success'   => false,
                'message'   => 'Unexpected error processing webhook event',
                'eventType' => $event['type'] ?? 'unknown',
            ];
        }
    }

    // ========================================================================
    // :test_tube: CONNECTION TESTING
    // ========================================================================

    /**
     * :stethoscope: Test the Coinbase Commerce API connection
     *
     * Performs a lightweight API call (listing 1 charge) to verify that
     * the configured API key is valid and the Coinbase Commerce API is
     * reachable. This is used by the admin settings UI to validate
     * configuration before saving.
     *
     * @return array ['success' => bool, 'message' => string, 'responseTime' => float]
     *
     * @example
     * ```php
     * $test = CoinbaseProvider::testConnection();
     * if ($test['success']) {
     *     echo 'Connected! Response time: ' . $test['responseTime'] . 'ms';
     * } else {
     *     echo 'Connection failed: ' . $test['message'];
     * }
     * ```
     */
    public static function testConnection(): array
    {
        try {
            // :stopwatch: Record the start time to measure API response time
            $startTime = microtime(true);

            // :rocket: Attempt to list 1 charge as a connectivity and auth test
            // This is the lightest possible API call that still validates the API key
            $response = self::listCharges(1);

            // :stopwatch: Calculate the response time in milliseconds
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            // :mag: Check if the response indicates an error
            if (isset($response['error'])) {
                ActivityLogger::log(
                    null,
                    'crypto_connection_test_failed',
                    'payment',
                    'warning',
                    'Coinbase Commerce connection test failed: ' . ($response['error'] ?? 'Unknown error'),
                    [
                        'responseTime' => $responseTime,
                        'httpCode'     => $response['httpCode'] ?? null,
                        'errorType'    => $response['type'] ?? null,
                    ]
                );

                return [
                    'success'      => false,
                    'message'      => 'Connection test failed: ' . ($response['error'] ?? 'Unknown error'),
                    'responseTime' => $responseTime,
                ];
            }

            // :white_check_mark: Connection successful
            ActivityLogger::log(
                null,
                'crypto_connection_test_success',
                'payment',
                'info',
                'Coinbase Commerce connection test passed (' . $responseTime . 'ms)',
                ['responseTime' => $responseTime]
            );

            return [
                'success'      => true,
                'message'      => 'Successfully connected to Coinbase Commerce API',
                'responseTime' => $responseTime,
            ];

        } catch (\Exception $e) {
            // :rotating_light: Unexpected exception during connection test
            ActivityLogger::log(
                null,
                'crypto_connection_test_exception',
                'payment',
                'error',
                'Exception during Coinbase Commerce connection test: ' . $e->getMessage(),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            return [
                'success'      => false,
                'message'      => 'Connection test failed due to an unexpected error',
                'responseTime' => 0,
            ];
        }
    }

    // ========================================================================
    // :wrench: UTILITY METHODS
    // ========================================================================

    /**
     * :coin: Extract the cryptocurrency type from a charge's payment data
     *
     * Parses the charge data returned by Coinbase Commerce to determine
     * which cryptocurrency was used for the payment. The crypto type is
     * found in the payments array within the charge data.
     *
     * Coinbase Commerce structures payment info differently depending
     * on the API version and charge state. This method checks multiple
     * possible locations for maximum compatibility:
     * 1. $data['payments'][0]['network'] (primary - blockchain network name)
     * 2. $data['payments'][0]['value']['crypto']['currency'] (alternative)
     * 3. Falls back to empty string if no payment data is available
     *
     * @param array $chargeData The 'data' portion of a Coinbase Commerce charge/event
     *
     * @return string Cryptocurrency ticker symbol (e.g., 'BTC', 'ETH', 'USDT') or empty string
     *
     * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/webhooks-events
     */
    private static function extractCryptoCurrency(array $chargeData): string
    {
        // :mag: Check the payments array for blockchain network information
        // The payments array is populated when a user sends crypto to the charge address
        $payments = $chargeData['payments'] ?? [];

        if (!empty($payments) && is_array($payments)) {
            // :one: Primary location: the 'network' field of the first payment
            // This contains the blockchain network name (e.g., 'bitcoin', 'ethereum')
            $firstPayment = $payments[0] ?? [];

            if (!empty($firstPayment['network'])) {
                // :arrows_counterclockwise: Map network names to standard ticker symbols
                return self::mapNetworkToCurrency($firstPayment['network']);
            }

            // :two: Alternative location: nested crypto currency field
            if (!empty($firstPayment['value']['crypto']['currency'])) {
                return strtoupper($firstPayment['value']['crypto']['currency']);
            }
        }

        // :three: Fallback: check the pricing information for accepted currencies
        // This doesn't tell us which was USED, just what was offered
        // Only useful as a last resort when there's a single accepted currency
        $pricing = $chargeData['pricing'] ?? [];
        if (count($pricing) === 1) {
            $keys = array_keys($pricing);
            $singleCurrency = $keys[0] ?? '';
            if (!empty($singleCurrency) && $singleCurrency !== 'local') {
                return strtoupper($singleCurrency);
            }
        }

        // :shrug: Could not determine the cryptocurrency - return empty
        return '';
    }

    /**
     * :arrows_counterclockwise: Map a Coinbase Commerce network name to a standard currency ticker
     *
     * Coinbase Commerce returns blockchain network names (e.g., 'bitcoin',
     * 'ethereum') rather than standard ticker symbols. This method maps
     * those names to their conventional ticker equivalents.
     *
     * @param string $network The blockchain network name from Coinbase Commerce
     *
     * @return string Standard cryptocurrency ticker symbol (e.g., 'BTC', 'ETH')
     *
     * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/supported-networks
     */
    private static function mapNetworkToCurrency(string $network): string
    {
        // :world_map: Network name to ticker symbol mapping
        // These mappings cover the most common Coinbase Commerce supported networks
        // @see https://docs.cdp.coinbase.com/commerce-onchain/docs/supported-networks
        $networkMap = [
            'bitcoin'          => 'BTC',
            'ethereum'         => 'ETH',
            'litecoin'         => 'LTC',
            'bitcoin_cash'     => 'BCH',
            'dogecoin'         => 'DOGE',
            'solana'           => 'SOL',
            'polygon'          => 'MATIC',
            'avalanche'        => 'AVAX',
            'base'             => 'ETH',   // Base is an Ethereum L2, payments typically in ETH/USDC
            'arbitrum'         => 'ETH',   // Arbitrum is an Ethereum L2
            'optimism'         => 'ETH',   // Optimism is an Ethereum L2
            'tron'             => 'TRX',
            'stellar'          => 'XLM',
            'ripple'           => 'XRP',
        ];

        // :mag: Look up the network name (case-insensitive)
        $normalised = strtolower(trim($network));

        if (isset($networkMap[$normalised])) {
            return $networkMap[$normalised];
        }

        // :warning: Unknown network - return the network name uppercased as a best-effort ticker
        // This handles any new networks Coinbase may add in the future
        return strtoupper($normalised);
    }
}
