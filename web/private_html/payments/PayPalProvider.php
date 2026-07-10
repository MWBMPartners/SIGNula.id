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
 * 💳 SIGNula - PayPal Payment Provider
 * ============================================================================
 *
 * Purpose: PayPal REST API v2 integration for payment processing, subscriptions,
 *          webhooks, and refunds. Uses raw cURL (no Composer/SDK dependency).
 * PHP Version: 8.3+
 *
 * Features:
 * - OAuth 2.0 access token management with caching and expiry
 * - Order creation (one-time payments) via PayPal Checkout
 * - Order capture (complete payments after buyer approval)
 * - Subscription/billing plan creation and management
 * - Webhook signature verification for secure event handling
 * - Refund processing (full and partial)
 * - Sandbox and live mode support (configurable via database settings)
 * - Idempotency key support for safe retries
 * - Comprehensive error handling and activity logging
 *
 * @see https://developer.paypal.com/docs/api/overview/                     (API Overview)
 * @see https://developer.paypal.com/docs/api/reference/get-an-access-token/ (Authentication)
 * @see https://developer.paypal.com/docs/api/orders/v2/                    (Orders API v2)
 * @see https://developer.paypal.com/docs/api/subscriptions/v1/             (Subscriptions API)
 * @see https://developer.paypal.com/docs/api/payments/v2/                  (Payments/Refunds)
 * @see https://developer.paypal.com/docs/api/webhooks/v1/                  (Webhooks)
 *
 * v2.4.0 Changes:
 * - Added optional $credentials parameter to apiRequest() and getAccessToken()
 * - Added getPartnerAccessToken() for partner API key flow (Level 2, Option 2a)
 * - Per-client-ID token caching to support multiple partners in one request lifecycle
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

// 🚫 Prevent direct access — all SIGNula files must be loaded via the bootstrap
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 📦 Load the PaymentManager class (core payment recording, subscriptions, discounts)
// This file resides in the same directory as PayPalProvider
$paymentManagerPath = __DIR__ . DIRECTORY_SEPARATOR . 'PaymentManager.php';
if (file_exists($paymentManagerPath)) {
    require_once $paymentManagerPath;
} else {
    // ⚠️ PaymentManager is essential for recording payments and managing subscriptions
    error_log('[SIGNula] PayPalProvider: PaymentManager.php not found at ' . $paymentManagerPath);
    throw new RuntimeException('PaymentManager.php is required but was not found');
}

// 🛡️ Load the BillingMode TEST-MODE guard (G-002 Stage S1 — see BillingMode.php
// for the full rationale). Every money-moving method below calls
// BillingMode::assertTestMode('paypal') before making any PayPal API request.
$billingModePath = __DIR__ . DIRECTORY_SEPARATOR . 'BillingMode.php';
if (file_exists($billingModePath)) {
    require_once $billingModePath;
} else {
    // ⚠️ The guard is a hard safety requirement — refuse to load without it
    error_log('[SIGNula] PayPalProvider: BillingMode.php not found at ' . $billingModePath);
    throw new RuntimeException('BillingMode.php is required but was not found');
}

/**
 * 💳 PayPalProvider
 *
 * Static utility class providing all PayPal REST API v2 interactions for SIGNula.
 * All methods are public static following the project-wide convention (no instances).
 *
 * Configuration is read from the tblSettings database table via getSetting():
 *   - payment.paypal.enabled       (0 or 1)
 *   - payment.paypal.client_id     (isSensitive=1, encrypted in DB)
 *   - payment.paypal.client_secret (isSensitive=1, encrypted in DB)
 *   - payment.paypal.mode          ('sandbox' or 'live')
 *   - payment.paypal.webhook_id    (isSensitive=1, encrypted in DB)
 *
 * @see https://developer.paypal.com/docs/api/overview/
 */
class PayPalProvider
{
    // ========================================================================
    // 🔧 STATIC PROPERTIES (Token Cache)
    // ========================================================================

    /**
     * @var string|null Cached OAuth 2.0 access token
     * Avoids repeated token requests within the same PHP execution lifecycle.
     * @see https://developer.paypal.com/docs/api/reference/get-an-access-token/
     */
    private static ?string $cachedAccessToken = null;

    /**
     * @var int Timestamp (Unix) when the cached access token expires.
     * Token is considered expired if current time >= this value.
     * PayPal tokens typically last ~32,400 seconds (9 hours).
     */
    private static int $tokenExpiresAt = 0;

    /**
     * @var array Cached billing plan IDs keyed by "tierID_billingCycle".
     * Prevents recreating plans for the same tier/cycle combination.
     * @see https://developer.paypal.com/docs/api/subscriptions/v1/#plans_create
     */
    private static array $billingPlanCache = [];

    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /** @var string PayPal Sandbox API base URL */
    const SANDBOX_BASE_URL = 'https://api-m.sandbox.paypal.com';

    /** @var string PayPal Live (Production) API base URL */
    const LIVE_BASE_URL = 'https://api-m.paypal.com';

    /** @var string Brand name displayed to buyers during PayPal checkout */
    const BRAND_NAME = 'SIGNula';

    /** @var int cURL connection timeout in seconds */
    const CURL_CONNECT_TIMEOUT = 10;

    /** @var int cURL request timeout in seconds */
    const CURL_TIMEOUT = 30;

    /** @var int Safety margin (in seconds) subtracted from token expiry to avoid edge-case failures */
    const TOKEN_EXPIRY_BUFFER = 60;

    // ========================================================================
    // 🔒 PRIVATE HELPERS
    // ========================================================================

    /**
     * 🌐 Get PayPal API Base URL
     *
     * Determines the correct PayPal API endpoint based on the configured mode
     * (sandbox or live) stored in the database setting 'payment.paypal.mode'.
     *
     * @return string The base URL for PayPal API requests
     *
     * @see https://developer.paypal.com/docs/api/overview/#make-api-calls
     *
     * @example
     * ```php
     * // Returns 'https://api-m.sandbox.paypal.com' in sandbox mode
     * // Returns 'https://api-m.paypal.com' in live/production mode
     * $baseURL = self::getBaseURL();
     * ```
     */
    private static function getBaseURL(): string
    {
        // 🔍 Retrieve the PayPal mode from database settings
        // Valid values: 'sandbox' (for testing) or 'live' (for production)
        $mode = getSetting('payment.paypal.mode', 'sandbox');

        // 🔀 Return the appropriate base URL based on mode
        if ($mode === 'live') {
            return self::LIVE_BASE_URL;
        }

        // 🛡️ Default to sandbox for safety — prevents accidental production charges
        return self::SANDBOX_BASE_URL;
    }

    /**
     * 🔑 Get PayPal OAuth 2.0 Access Token
     *
     * Authenticates with the PayPal API using client credentials (OAuth 2.0).
     * Implements token caching to avoid unnecessary API calls within the same
     * PHP execution context. Tokens are cached with an expiry buffer to prevent
     * edge-case failures from clock skew or network latency.
     *
     * PayPal access tokens typically expire after ~32,400 seconds (9 hours),
     * but we subtract TOKEN_EXPIRY_BUFFER seconds as a safety margin.
     *
     * @return string Valid access token for PayPal API requests
     * @throws RuntimeException If authentication fails or credentials are missing
     *
     * @see https://developer.paypal.com/docs/api/reference/get-an-access-token/
     * @see https://developer.paypal.com/docs/api/overview/#authentication
     *
     * @example
     * ```php
     * $token = self::getAccessToken();
     * // Use in Authorization header: "Bearer {$token}"
     * ```
     */
    private static function getAccessToken(?array $credentials = null): string
    {
        // 🏢 If partner credentials are provided, use a separate token flow
        // Partner tokens are NOT cached in the global static properties to avoid cross-contamination
        if ($credentials !== null && !empty($credentials['client_id']) && !empty($credentials['client_secret'])) {
            return self::getPartnerAccessToken($credentials);
        }

        // ⏱️ Check if we have a cached token that hasn't expired
        // We use a buffer to avoid using a token that's about to expire
        if (self::$cachedAccessToken !== null && time() < self::$tokenExpiresAt) {
            return self::$cachedAccessToken;
        }

        // 🔐 Retrieve and decrypt PayPal API credentials from database settings
        // These are stored as isSensitive=1 in tblSettings and encrypted at rest
        // @see SecurityUtils::decrypt() for the decryption implementation
        $clientID = SecurityUtils::decrypt(getSetting('payment.paypal.client_id', ''));
        $clientSecret = SecurityUtils::decrypt(getSetting('payment.paypal.client_secret', ''));

        // ✅ Validate that credentials are present
        if (empty($clientID) || empty($clientSecret)) {
            throw new RuntimeException(
                'PayPal API credentials not configured. '
                . 'Ensure payment.paypal.client_id and payment.paypal.client_secret are set in tblSettings.'
            );
        }

        // 🌐 Build the token endpoint URL
        $url = self::getBaseURL() . '/v1/oauth2/token';

        // 🔧 Initialise cURL for the OAuth token request
        // @see https://www.php.net/manual/en/function.curl-init.php
        $ch = curl_init();

        // 📋 Configure cURL options for PayPal OAuth
        // PayPal uses HTTP Basic Auth with base64-encoded client_id:client_secret
        // @see https://developer.paypal.com/docs/api/reference/get-an-access-token/
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,                              // 📥 Return response as string
            CURLOPT_POST           => true,                              // 📤 Use POST method
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',   // 🔑 OAuth 2.0 client credentials grant
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',                              // 📄 Request JSON response
                'Accept-Language: en_US',                                // 🌍 Language preference
                'Content-Type: application/x-www-form-urlencoded',       // 📝 Form-encoded body
            ],
            CURLOPT_USERPWD        => $clientID . ':' . $clientSecret,   // 🔐 Basic Auth credentials
            CURLOPT_SSL_VERIFYPEER => true,                              // 🔒 Verify SSL certificate (CRITICAL for security)
            CURLOPT_SSL_VERIFYHOST => 2,                                 // 🔒 Verify SSL hostname matches certificate
            CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,        // ⏱️ Connection timeout
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,                // ⏱️ Total request timeout
        ]);

        // 🚀 Execute the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        // ❌ Handle cURL-level errors (network failures, DNS resolution, timeouts, etc.)
        if ($curlErrno !== 0) {
            // 📝 Log the error for debugging
            ActivityLogger::log(
                null,
                'paypal_auth_error',
                'payment',
                'error',
                'PayPal OAuth token request failed (cURL error): ' . $curlError,
                [
                    'curlErrno'  => $curlErrno,
                    'curlError'  => $curlError,
                    'endpoint'   => '/v1/oauth2/token',
                ]
            );

            throw new RuntimeException('PayPal API connection failed: ' . $curlError);
        }

        // 📦 Decode the JSON response
        $data = json_decode($response, true);

        // ❌ Handle HTTP-level errors (authentication failures, rate limiting, etc.)
        if ($httpCode !== 200 || !isset($data['access_token'])) {
            // 📝 Log the error for debugging (do NOT log credentials)
            $errorMessage = $data['error_description'] ?? $data['error'] ?? 'Unknown error';

            ActivityLogger::log(
                null,
                'paypal_auth_error',
                'payment',
                'error',
                'PayPal OAuth token request failed (HTTP ' . $httpCode . '): ' . $errorMessage,
                [
                    'httpCode'     => $httpCode,
                    'error'        => $data['error'] ?? 'unknown',
                    'errorMessage' => $errorMessage,
                    'endpoint'     => '/v1/oauth2/token',
                ]
            );

            throw new RuntimeException('PayPal authentication failed: ' . $errorMessage);
        }

        // ✅ Cache the access token with a safety buffer before expiry
        // PayPal returns 'expires_in' as seconds from now (typically 32400 = 9 hours)
        self::$cachedAccessToken = $data['access_token'];
        self::$tokenExpiresAt = time() + (int)($data['expires_in'] ?? 3600) - self::TOKEN_EXPIRY_BUFFER;

        return self::$cachedAccessToken;
    }

    /**
     * 🏢 Get PayPal OAuth 2.0 Access Token for Partner Credentials
     *
     * Separate token flow for partner-provided API keys (Level 2, Option 2a).
     * Partner tokens are NOT cached in the global static properties to avoid
     * cross-contamination between SIGNula's own tokens and partner tokens.
     *
     * Uses a per-client-ID cache keyed by the client_id to support multiple
     * partners making requests in the same PHP lifecycle.
     *
     * @param array $credentials Partner credentials: ['client_id' => '...', 'client_secret' => '...']
     * @return string Valid access token for the partner's PayPal account
     * @throws RuntimeException If authentication fails
     *
     * @since 2.4.0-beta
     * @see https://developer.paypal.com/docs/api/reference/get-an-access-token/
     */
    private static array $partnerTokenCache = [];

    private static function getPartnerAccessToken(array $credentials): string
    {
        $clientID = $credentials['client_id'];
        $clientSecret = $credentials['client_secret'];

        // ⏱️ Check per-partner token cache
        $cacheKey = md5($clientID);
        if (isset(self::$partnerTokenCache[$cacheKey]) && time() < self::$partnerTokenCache[$cacheKey]['expiresAt']) {
            return self::$partnerTokenCache[$cacheKey]['token'];
        }

        // 🌐 Build the token endpoint URL
        $url = self::getBaseURL() . '/v1/oauth2/token';

        // 🔧 Initialise cURL for the OAuth token request
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Accept-Language: en_US',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_USERPWD        => $clientID . ':' . $clientSecret,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno !== 0) {
            throw new RuntimeException('PayPal partner API connection failed: ' . $curlError);
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !isset($data['access_token'])) {
            $errorMessage = $data['error_description'] ?? $data['error'] ?? 'Unknown error';
            throw new RuntimeException('PayPal partner authentication failed: ' . $errorMessage);
        }

        // ✅ Cache the partner token
        self::$partnerTokenCache[$cacheKey] = [
            'token'     => $data['access_token'],
            'expiresAt' => time() + (int)($data['expires_in'] ?? 3600) - self::TOKEN_EXPIRY_BUFFER,
        ];

        return $data['access_token'];
    }

    /**
     * 🌐 Make an Authenticated PayPal API Request
     *
     * Generic method for all PayPal REST API calls. Handles authentication,
     * request building, JSON serialisation, error handling, and logging.
     *
     * Supports all HTTP methods (GET, POST, PATCH, DELETE) with optional
     * request body and idempotency key for safe retries.
     *
     * @param string      $method         HTTP method (GET, POST, PATCH, DELETE)
     * @param string      $endpoint       API endpoint path (e.g., '/v2/checkout/orders')
     * @param array|null  $data           Request body data (will be JSON-encoded for POST/PATCH)
     * @param string|null $idempotencyKey Optional PayPal-Request-Id for idempotent requests
     * @return array Decoded JSON response, or error array with 'error' key
     *
     * @see https://developer.paypal.com/docs/api/reference/api-requests/
     * @see https://developer.paypal.com/docs/api/reference/api-responses/
     *
     * @example
     * ```php
     * // GET request
     * $order = self::apiRequest('GET', '/v2/checkout/orders/' . $orderID);
     *
     * // POST request with body and idempotency key
     * $result = self::apiRequest('POST', '/v2/checkout/orders', $orderData, 'unique-key-123');
     * ```
     */
    private static function apiRequest(
        string $method,
        string $endpoint,
        ?array $data = null,
        ?string $idempotencyKey = null,
        ?array $credentials = null
    ): array {
        try {
            // 🔑 Obtain a valid access token (uses cache if available)
            // If partner credentials are provided (Level 2, Option 2a), use partner token flow
            $accessToken = self::getAccessToken($credentials);
        } catch (RuntimeException $e) {
            // ❌ Authentication failure — return error array rather than throwing
            return [
                'error'   => true,
                'message' => 'Authentication failed: ' . $e->getMessage(),
            ];
        }

        // 🌐 Build the full URL: base URL + endpoint path
        $url = self::getBaseURL() . $endpoint;

        // 📋 Build request headers
        // @see https://developer.paypal.com/docs/api/reference/api-requests/#http-request-headers
        $headers = [
            'Authorization: Bearer ' . $accessToken,    // 🔐 OAuth 2.0 Bearer token
            'Content-Type: application/json',            // 📄 JSON request body
            'Accept: application/json',                  // 📄 JSON response expected
            'Prefer: return=representation',             // 📦 Return full resource in response
        ];

        // 🔄 Add idempotency key if provided — ensures safe retries for POST requests
        // @see https://developer.paypal.com/docs/api/reference/api-requests/#paypal-request-id
        if ($idempotencyKey !== null) {
            $headers[] = 'PayPal-Request-Id: ' . $idempotencyKey;
        }

        // 🔧 Initialise cURL handle
        $ch = curl_init();

        // 📋 Configure base cURL options
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,                          // 📥 Return response as string
            CURLOPT_HTTPHEADER     => $headers,                      // 📋 Request headers
            CURLOPT_SSL_VERIFYPEER => true,                          // 🔒 Verify SSL certificate
            CURLOPT_SSL_VERIFYHOST => 2,                             // 🔒 Verify SSL hostname
            CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,    // ⏱️ Connection timeout
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,            // ⏱️ Total request timeout
        ]);

        // 🔀 Configure HTTP method and body
        // PayPal uses JSON for request bodies (unlike Stripe which uses form-encoded)
        $upperMethod = strtoupper($method);
        switch ($upperMethod) {
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;

            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;

            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;

            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;

            default:
                // ⚠️ Unsupported HTTP method
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $upperMethod);
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
        }

        // 🚀 Execute the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        // ❌ Handle cURL-level errors (network failures, DNS, timeouts)
        if ($curlErrno !== 0) {
            ActivityLogger::log(
                null,
                'paypal_api_error',
                'payment',
                'error',
                'PayPal API request failed (cURL error): ' . $curlError,
                [
                    'method'     => $upperMethod,
                    'endpoint'   => $endpoint,
                    'curlErrno'  => $curlErrno,
                    'curlError'  => $curlError,
                ]
            );

            return [
                'error'   => true,
                'message' => 'PayPal API connection error: ' . $curlError,
            ];
        }

        // 📦 Decode the JSON response body
        // Some endpoints (like capture with 204 No Content) may return empty body
        $decoded = !empty($response) ? json_decode($response, true) : [];

        // ❌ Handle HTTP error responses (4xx, 5xx)
        // @see https://developer.paypal.com/docs/api/reference/api-responses/#http-status-codes
        if ($httpCode >= 400) {
            // 📝 Extract PayPal error details from response
            $errorName = $decoded['name'] ?? 'UNKNOWN_ERROR';
            $errorMessage = $decoded['message'] ?? 'Unknown PayPal API error';
            $errorDetails = $decoded['details'] ?? [];

            // 📝 Log the error for debugging and auditing
            ActivityLogger::log(
                null,
                'paypal_api_error',
                'payment',
                'error',
                'PayPal API error (HTTP ' . $httpCode . '): ' . $errorName . ' - ' . $errorMessage,
                [
                    'method'       => $upperMethod,
                    'endpoint'     => $endpoint,
                    'httpCode'     => $httpCode,
                    'errorName'    => $errorName,
                    'errorMessage' => $errorMessage,
                    'errorDetails' => $errorDetails,
                ]
            );

            return [
                'error'        => true,
                'httpCode'     => $httpCode,
                'errorName'    => $errorName,
                'message'      => $errorMessage,
                'errorDetails' => $errorDetails,
            ];
        }

        // ✅ Successful response — return decoded JSON
        return $decoded ?? [];
    }

    // ========================================================================
    // ✅ PUBLIC METHODS
    // ========================================================================

    /**
     * 🔍 Check if PayPal is Enabled
     *
     * Verifies that PayPal payment processing is enabled and properly configured
     * by checking the database setting and ensuring API credentials are present.
     *
     * @return bool True if PayPal is enabled and client_id is configured
     *
     * @example
     * ```php
     * if (PayPalProvider::isEnabled()) {
     *     // Show PayPal payment button
     * }
     * ```
     */
    public static function isEnabled(): bool
    {
        // 🔍 Check the enabled flag in tblSettings
        $enabled = getSetting('payment.paypal.enabled', '0');

        // 🔍 Also verify that the client_id is actually configured (non-empty)
        // This prevents showing PayPal as an option when credentials haven't been set up
        $clientID = getSetting('payment.paypal.client_id', '');

        return ($enabled === '1' || $enabled === 1 || $enabled === true) && !empty($clientID);
    }

    /**
     * 🛒 Create a PayPal Order (One-Time Payment)
     *
     * Creates a PayPal checkout order for a one-time payment. The buyer will
     * be redirected to PayPal to approve the payment, then returned to the
     * return_url where you capture the order with captureOrder().
     *
     * Flow: createOrder() -> buyer approves on PayPal -> captureOrder()
     *
     * @param float  $amount      Payment amount (will be formatted to 2 decimal places)
     * @param string $currency    ISO 4217 currency code (e.g., 'GBP', 'USD', 'EUR')
     * @param string $description Brief description of the purchase shown to buyer
     * @param array  $options     Optional parameters:
     *                            - 'return_url'  (string) URL to redirect after approval
     *                            - 'cancel_url'  (string) URL to redirect if buyer cancels
     *                            - 'custom_id'   (string) Your internal reference (e.g., invoiceNumber)
     *                            - 'userID'      (int)    User ID for payment recording
     *                            - 'metadata'    (array)  Additional metadata to store
     * @return array Result array with keys:
     *               - success (bool)
     *               - orderID (string) PayPal order ID
     *               - approvalURL (string) URL to redirect buyer to
     *               - paymentID (int) Internal payment record ID
     *               OR error keys on failure
     *
     * @see https://developer.paypal.com/docs/api/orders/v2/#orders_create
     * @see https://developer.paypal.com/docs/checkout/standard/integrate/
     *
     * @example
     * ```php
     * $result = PayPalProvider::createOrder(29.99, 'GBP', 'SIGNula Pro Monthly', [
     *     'return_url' => 'https://signula.id/payments/complete',
     *     'cancel_url' => 'https://signula.id/payments/cancel',
     *     'custom_id'  => 'SIG-20260213-00001',
     *     'userID'     => 42,
     * ]);
     *
     * if ($result['success']) {
     *     // Redirect buyer to $result['approvalURL']
     *     header('Location: ' . $result['approvalURL']);
     * }
     * ```
     */
    public static function createOrder(
        float $amount,
        string $currency,
        string $description,
        array $options = []
    ): array {
        try {
            // 🔍 Verify PayPal is enabled before attempting API call
            if (!self::isEnabled()) {
                return [
                    'success' => false,
                    'message' => 'PayPal is not enabled or not configured',
                ];
            }

            // 💰 Format amount to 2 decimal places as required by PayPal API
            // @see https://developer.paypal.com/docs/api/orders/v2/#definition-money
            $formattedAmount = number_format($amount, 2, '.', '');

            // 📋 Build the order request body
            // @see https://developer.paypal.com/docs/api/orders/v2/#orders_create
            $orderData = [
                // 🎯 CAPTURE intent means payment is captured immediately after buyer approval
                // Use AUTHORIZE if you want to capture later (e.g., after shipping)
                'intent' => 'CAPTURE',

                // 🛒 Purchase units — each unit represents a payable item/group
                'purchase_units' => [
                    [
                        // 💰 Amount details
                        'amount' => [
                            'currency_code' => strtoupper($currency),
                            'value'         => $formattedAmount,
                        ],

                        // 📝 Description visible to the buyer on PayPal
                        'description' => mb_substr($description, 0, 127),  // PayPal limit: 127 chars
                    ],
                ],

                // 🔗 Payment source with redirect URLs and display preferences
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            // 🏷️ Brand name displayed on PayPal checkout page
                            'brand_name'          => self::BRAND_NAME,

                            // 🌐 Locale for the PayPal checkout experience
                            'locale'              => 'en-GB',

                            // 🎯 PAY_NOW shows "Pay Now" button instead of "Continue"
                            'user_action'         => 'PAY_NOW',

                            // 📤 NO_SHIPPING hides shipping address collection
                            // (SIGNula is a digital service — no physical shipping)
                            'shipping_preference' => 'NO_SHIPPING',

                            // 🔗 URL to return the buyer after they approve the payment
                            'return_url'          => $options['return_url'] ?? getSetting('payment.paypal.return_url', 'https://signula.id/payments/complete'),

                            // 🔗 URL to return the buyer if they cancel
                            'cancel_url'          => $options['cancel_url'] ?? getSetting('payment.paypal.cancel_url', 'https://signula.id/payments/cancel'),
                        ],
                    ],
                ],
            ];

            // 🏷️ Add custom_id if provided (your internal reference, returned in webhooks)
            if (!empty($options['custom_id'])) {
                $orderData['purchase_units'][0]['custom_id'] = $options['custom_id'];
            }

            // 🔄 Generate an idempotency key to prevent duplicate orders on retry
            // @see https://developer.paypal.com/docs/api/reference/api-requests/#paypal-request-id
            $idempotencyKey = 'order_' . ($options['custom_id'] ?? uniqid('sig_', true));

            // 🚀 Send the create order request to PayPal
            $response = self::apiRequest('POST', '/v2/checkout/orders', $orderData, $idempotencyKey);

            // ❌ Handle API-level errors
            if (isset($response['error']) && $response['error'] === true) {
                return [
                    'success' => false,
                    'message' => $response['message'] ?? 'Failed to create PayPal order',
                ];
            }

            // 🔍 Extract the approval URL from the response links
            // PayPal returns a 'links' array with HATEOAS links; we need the 'approve' link
            // @see https://developer.paypal.com/docs/api/reference/api-responses/#hateoas-links
            $approvalURL = null;
            if (isset($response['links']) && is_array($response['links'])) {
                foreach ($response['links'] as $link) {
                    if (isset($link['rel']) && $link['rel'] === 'approve') {
                        $approvalURL = $link['href'];
                        break;
                    }
                }
            }

            // ❌ If no approval URL found, something went wrong
            if (empty($approvalURL)) {
                ActivityLogger::log(
                    $options['userID'] ?? null,
                    'paypal_order_error',
                    'payment',
                    'error',
                    'PayPal order created but no approval URL found',
                    [
                        'orderID'  => $response['id'] ?? 'unknown',
                        'response' => $response,
                    ]
                );

                return [
                    'success' => false,
                    'message' => 'PayPal order created but approval URL not found in response',
                ];
            }

            // 💾 Record the pending payment in our database via PaymentManager
            $paymentRecord = PaymentManager::recordPayment(
                (int)($options['userID'] ?? 0),
                $amount,
                'paypal',
                'paypal',
                [
                    'transactionID'  => $response['id'] ?? null,
                    'description'    => $description,
                    'currency'       => $currency,
                    'subscriptionID' => $options['subscriptionID'] ?? null,
                    'metadata'       => array_merge(
                        $options['metadata'] ?? [],
                        [
                            'paypal_order_id' => $response['id'] ?? null,
                            'paypal_status'   => $response['status'] ?? 'CREATED',
                        ]
                    ),
                ]
            );

            // 📝 Log the successful order creation
            ActivityLogger::log(
                $options['userID'] ?? null,
                'paypal_order_created',
                'payment',
                'info',
                'PayPal order created: ' . ($response['id'] ?? 'unknown') . ' for ' . $currency . ' ' . $formattedAmount,
                [
                    'orderID'     => $response['id'] ?? null,
                    'amount'      => $formattedAmount,
                    'currency'    => $currency,
                    'description' => $description,
                    'paymentID'   => $paymentRecord['paymentID'] ?? null,
                ]
            );

            // ✅ Return success with all relevant identifiers
            return [
                'success'     => true,
                'orderID'     => $response['id'],
                'approvalURL' => $approvalURL,
                'status'      => $response['status'] ?? 'CREATED',
                'paymentID'   => $paymentRecord['paymentID'] ?? null,
            ];

        } catch (\Exception $e) {
            // ❌ Catch-all error handling
            ActivityLogger::log(
                $options['userID'] ?? null,
                'paypal_order_error',
                'payment',
                'error',
                'Exception creating PayPal order: ' . $e->getMessage(),
                [
                    'amount'   => $amount,
                    'currency' => $currency,
                ]
            );

            return [
                'success' => false,
                'message' => 'An error occurred while creating the PayPal order',
            ];
        }
    }

    /**
     * 💰 Capture a PayPal Order (Complete Payment)
     *
     * Captures (finalises) a PayPal order after the buyer has approved it.
     * This is the second step in the payment flow: createOrder() -> approve -> captureOrder().
     *
     * After capturing, the funds are transferred from the buyer to your PayPal account.
     * The capture contains the transaction ID needed for refunds.
     *
     * @param string $orderID PayPal order ID (returned from createOrder())
     * @return array Result array with keys:
     *               - success (bool)
     *               - transactionID (string) PayPal capture/transaction ID
     *               - status (string) Capture status (COMPLETED, PENDING, etc.)
     *               - payerEmail (string) Buyer's PayPal email
     *               OR error keys on failure
     *
     * @see https://developer.paypal.com/docs/api/orders/v2/#orders_capture
     *
     * @example
     * ```php
     * // After buyer returns from PayPal approval
     * $result = PayPalProvider::captureOrder($_GET['token']); // PayPal passes order ID as 'token'
     *
     * if ($result['success']) {
     *     echo "Payment complete! Transaction: " . $result['transactionID'];
     * }
     * ```
     */
    public static function captureOrder(string $orderID): array
    {
        try {
            // 🔍 Validate the order ID
            if (empty($orderID)) {
                return [
                    'success' => false,
                    'message' => 'Order ID is required',
                ];
            }

            // 🚀 Send the capture request to PayPal
            // POST with empty body — PayPal captures the full authorized amount
            // @see https://developer.paypal.com/docs/api/orders/v2/#orders_capture
            $response = self::apiRequest('POST', '/v2/checkout/orders/' . urlencode($orderID) . '/capture');

            // ❌ Handle API-level errors
            if (isset($response['error']) && $response['error'] === true) {
                // 📝 Log the capture failure
                ActivityLogger::log(
                    null,
                    'paypal_capture_error',
                    'payment',
                    'error',
                    'PayPal order capture failed for order: ' . $orderID,
                    [
                        'orderID'  => $orderID,
                        'response' => $response,
                    ]
                );

                return [
                    'success' => false,
                    'message' => $response['message'] ?? 'Failed to capture PayPal order',
                ];
            }

            // 🔍 Extract capture details from the response
            // The capture ID is nested under purchase_units[0].payments.captures[0]
            $transactionID = null;
            $captureStatus = null;
            $captureAmount = null;
            $captureCurrency = null;

            if (isset($response['purchase_units'][0]['payments']['captures'][0])) {
                $capture = $response['purchase_units'][0]['payments']['captures'][0];
                $transactionID = $capture['id'] ?? null;
                $captureStatus = $capture['status'] ?? null;
                $captureAmount = $capture['amount']['value'] ?? null;
                $captureCurrency = $capture['amount']['currency_code'] ?? null;
            }

            // 🔍 Extract payer information
            $payerEmail = $response['payer']['email_address'] ?? null;
            $payerName = trim(
                ($response['payer']['name']['given_name'] ?? '') . ' '
                . ($response['payer']['name']['surname'] ?? '')
            );

            // ✅ If capture was successful, update our internal payment record
            if ($captureStatus === 'COMPLETED') {
                // 🔍 Find the internal payment record by the PayPal order ID (stored as transactionID)
                $payment = Database::fetchOne(
                    "SELECT paymentID FROM tblPayments WHERE transactionID = ? AND status = 'pending' ORDER BY createdAt DESC LIMIT 1",
                    [$orderID],
                    's'
                );

                if ($payment) {
                    // ✅ Mark the internal payment as completed with the capture transaction ID
                    PaymentManager::completePayment(
                        (int)$payment['paymentID'],
                        $transactionID,    // The capture ID (used for refunds later)
                        null               // No receipt URL from PayPal captures
                    );
                }
            }

            // 📝 Log the capture result
            ActivityLogger::log(
                null,
                'paypal_order_captured',
                'payment',
                $captureStatus === 'COMPLETED' ? 'info' : 'warning',
                'PayPal order captured: ' . $orderID . ' | Status: ' . ($captureStatus ?? 'unknown')
                    . ' | Amount: ' . ($captureCurrency ?? '') . ' ' . ($captureAmount ?? '0.00'),
                [
                    'orderID'       => $orderID,
                    'transactionID' => $transactionID,
                    'captureStatus' => $captureStatus,
                    'amount'        => $captureAmount,
                    'currency'      => $captureCurrency,
                    'payerEmail'    => $payerEmail,
                    'payerName'     => $payerName,
                ]
            );

            // ✅ Return the capture result
            return [
                'success'       => ($captureStatus === 'COMPLETED'),
                'orderID'       => $orderID,
                'transactionID' => $transactionID,
                'status'        => $captureStatus ?? ($response['status'] ?? 'UNKNOWN'),
                'amount'        => $captureAmount,
                'currency'      => $captureCurrency,
                'payerEmail'    => $payerEmail,
                'payerName'     => $payerName,
            ];

        } catch (\Exception $e) {
            // ❌ Catch-all error handling
            ActivityLogger::log(
                null,
                'paypal_capture_error',
                'payment',
                'error',
                'Exception capturing PayPal order: ' . $e->getMessage(),
                ['orderID' => $orderID]
            );

            return [
                'success' => false,
                'message' => 'An error occurred while capturing the PayPal order',
            ];
        }
    }

    /**
     * 🔍 Get PayPal Order Details
     *
     * Retrieves the full details of a PayPal order by its ID. Useful for
     * checking order status, verifying payment amounts, or displaying
     * order information to the user.
     *
     * @param string $orderID PayPal order ID
     * @return array Full order details from PayPal, or error array
     *
     * @see https://developer.paypal.com/docs/api/orders/v2/#orders_get
     *
     * @example
     * ```php
     * $details = PayPalProvider::getOrderDetails('5O190127TN364715T');
     * echo "Order status: " . ($details['status'] ?? 'unknown');
     * ```
     */
    public static function getOrderDetails(string $orderID): array
    {
        // 🔍 Validate the order ID
        if (empty($orderID)) {
            return [
                'error'   => true,
                'message' => 'Order ID is required',
            ];
        }

        // 🚀 GET request to retrieve order details
        // @see https://developer.paypal.com/docs/api/orders/v2/#orders_get
        return self::apiRequest('GET', '/v2/checkout/orders/' . urlencode($orderID));
    }

    /**
     * 🔄 Create a PayPal Subscription
     *
     * Creates a recurring subscription via PayPal's Subscriptions API.
     * First creates (or retrieves a cached) billing plan for the specified tier
     * and billing cycle, then creates the subscription.
     *
     * The buyer must approve the subscription on PayPal before it becomes active.
     *
     * @param int    $tierID      Subscription tier ID from tblSubscriptionTiers
     * @param string $billingCycle Billing frequency ('monthly' or 'yearly')
     * @param string $returnURL   URL to redirect after buyer approves
     * @param string $cancelURL   URL to redirect if buyer cancels
     * @param array  $options     Optional parameters:
     *                            - 'userID' (int)    User ID
     *                            - 'email'  (string) Subscriber email for PayPal
     *                            - 'custom_id' (string) Your internal reference
     * @return array Result array with keys:
     *               - success (bool)
     *               - subscriptionID (string) PayPal subscription ID
     *               - approvalURL (string) URL to redirect buyer to
     *               OR error keys on failure
     *
     * @see https://developer.paypal.com/docs/api/subscriptions/v1/
     * @see https://developer.paypal.com/docs/api/subscriptions/v1/#plans_create
     * @see https://developer.paypal.com/docs/api/subscriptions/v1/#subscriptions_create
     *
     * @example
     * ```php
     * $result = PayPalProvider::createSubscription(
     *     tierID: 2,
     *     billingCycle: 'monthly',
     *     returnURL: 'https://signula.id/payments/subscription-complete',
     *     cancelURL: 'https://signula.id/payments/subscription-cancel',
     *     options: ['userID' => 42, 'email' => 'user@example.com']
     * );
     *
     * if ($result['success']) {
     *     header('Location: ' . $result['approvalURL']);
     * }
     * ```
     */
    public static function createSubscription(
        int $tierID,
        string $billingCycle,
        string $returnURL,
        string $cancelURL,
        array $options = []
    ): array {
        try {
            // 🛡️ TEST-MODE GUARD (G-002 §0/§9.1) — checked FIRST, before any other
            // logic or PayPal API call. If billing.test_mode_guard is ON and
            // payment.paypal.mode is 'live', this throws BillingModeException,
            // which the catch(\Exception) block below converts into the usual
            // ['success' => false, 'message' => …] response — no HTTP call is
            // ever reached. @see BillingMode::assertTestMode()
            BillingMode::assertTestMode('paypal');

            // 🔍 Verify PayPal is enabled
            if (!self::isEnabled()) {
                return [
                    'success' => false,
                    'message' => 'PayPal is not enabled or not configured',
                ];
            }

            // 🔍 Get the subscription tier details from our database
            $tier = PaymentManager::getTier($tierID);
            if (!$tier) {
                return [
                    'success' => false,
                    'message' => 'Invalid subscription tier (ID: ' . $tierID . ')',
                ];
            }

            // 💰 Determine the price based on billing cycle
            $amount = match ($billingCycle) {
                'yearly'  => (float)($tier['yearlyPrice'] ?? 0),
                'monthly' => (float)($tier['monthlyPrice'] ?? 0),
                default   => (float)($tier['monthlyPrice'] ?? 0),
            };

            // 🔍 Validate the amount is greater than zero (free tiers don't need PayPal)
            if ($amount <= 0) {
                return [
                    'success' => false,
                    'message' => 'Cannot create a PayPal subscription for a free tier',
                ];
            }

            $currency = $tier['currency'] ?? 'GBP';
            $formattedAmount = number_format($amount, 2, '.', '');

            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 1: Create or retrieve the billing plan
            // ═══════════════════════════════════════════════════════════════

            // 🔍 Check the cache for an existing plan ID
            $cacheKey = $tierID . '_' . $billingCycle;

            if (!isset(self::$billingPlanCache[$cacheKey])) {
                // 📋 Build the billing plan request body
                // @see https://developer.paypal.com/docs/api/subscriptions/v1/#plans_create
                $planData = [
                    // 🏷️ Product/plan name — visible to subscribers
                    'product_id'  => 'SIGNULA-TIER-' . $tierID,
                    'name'        => self::BRAND_NAME . ' - ' . ($tier['tierName'] ?? 'Subscription') . ' (' . ucfirst($billingCycle) . ')',
                    'description' => 'SIGNula ' . ($tier['tierName'] ?? 'Subscription') . ' - ' . ucfirst($billingCycle) . ' billing',
                    'status'      => 'ACTIVE',

                    // 💰 Billing cycles — defines how often and how much to charge
                    'billing_cycles' => [
                        [
                            'frequency' => [
                                'interval_unit'  => ($billingCycle === 'yearly') ? 'YEAR' : 'MONTH',
                                'interval_count' => 1,
                            ],
                            'tenure_type'  => 'REGULAR',
                            'sequence'     => 1,
                            'total_cycles' => 0,  // 0 = infinite (until cancelled)
                            'pricing_scheme' => [
                                'fixed_price' => [
                                    'value'         => $formattedAmount,
                                    'currency_code' => strtoupper($currency),
                                ],
                            ],
                        ],
                    ],

                    // 💳 Payment preferences
                    'payment_preferences' => [
                        'auto_bill_outstanding'     => true,
                        'payment_failure_threshold' => 3,   // Cancel after 3 consecutive failures
                        'setup_fee' => [
                            'value'         => '0.00',
                            'currency_code' => strtoupper($currency),
                        ],
                    ],
                ];

                // 🔍 First, ensure the product exists (PayPal requires a product for plans)
                // Create a catalogue product for this tier if it doesn't exist
                $productData = [
                    'name'        => self::BRAND_NAME . ' - ' . ($tier['tierName'] ?? 'Subscription'),
                    'description' => 'SIGNula subscription tier: ' . ($tier['tierName'] ?? 'Unknown'),
                    'type'        => 'SERVICE',
                    'category'    => 'SOFTWARE',
                    'id'          => 'SIGNULA-TIER-' . $tierID,
                ];

                // 🚀 Create the product (PayPal returns existing product if ID matches)
                $productResponse = self::apiRequest(
                    'POST',
                    '/v1/catalogs/products',
                    $productData,
                    'product_signula_tier_' . $tierID
                );

                // ⚠️ If product creation fails (and it's not a duplicate error), log but continue
                // PayPal returns 422 if the product already exists — that's fine
                if (isset($productResponse['error']) && $productResponse['error'] === true) {
                    if (($productResponse['httpCode'] ?? 0) !== 422) {
                        ActivityLogger::log(
                            $options['userID'] ?? null,
                            'paypal_product_error',
                            'payment',
                            'warning',
                            'PayPal product creation warning: ' . ($productResponse['message'] ?? 'unknown'),
                            ['tierID' => $tierID, 'response' => $productResponse]
                        );
                    }
                    // Product may already exist — continue with plan creation
                }

                // 🚀 Create the billing plan
                $planResponse = self::apiRequest(
                    'POST',
                    '/v1/billing/plans',
                    $planData,
                    'plan_signula_' . $cacheKey
                );

                if (isset($planResponse['error']) && $planResponse['error'] === true) {
                    return [
                        'success' => false,
                        'message' => 'Failed to create PayPal billing plan: ' . ($planResponse['message'] ?? 'unknown'),
                    ];
                }

                // 💾 Cache the plan ID for reuse within this request lifecycle
                if (isset($planResponse['id'])) {
                    self::$billingPlanCache[$cacheKey] = $planResponse['id'];
                } else {
                    return [
                        'success' => false,
                        'message' => 'PayPal billing plan created but no plan ID returned',
                    ];
                }
            }

            $planID = self::$billingPlanCache[$cacheKey];

            // ═══════════════════════════════════════════════════════════════
            // 📋 STEP 2: Create the subscription
            // ═══════════════════════════════════════════════════════════════

            // 📋 Build the subscription request body
            // @see https://developer.paypal.com/docs/api/subscriptions/v1/#subscriptions_create
            $subscriptionData = [
                'plan_id'    => $planID,
                'start_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 minute')),  // Start slightly in the future

                // 📧 Subscriber information
                'subscriber' => [
                    'name' => [
                        'given_name' => $options['firstName'] ?? '',
                        'surname'    => $options['lastName'] ?? '',
                    ],
                ],

                // 🔗 Application context — redirect URLs and display preferences
                'application_context' => [
                    'brand_name'          => self::BRAND_NAME,
                    'locale'              => 'en-GB',
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action'         => 'SUBSCRIBE_NOW',
                    'return_url'          => $returnURL,
                    'cancel_url'          => $cancelURL,
                ],
            ];

            // 📧 Add subscriber email if provided
            if (!empty($options['email'])) {
                $subscriptionData['subscriber']['email_address'] = $options['email'];
            }

            // 🏷️ Add custom_id for internal tracking
            if (!empty($options['custom_id'])) {
                $subscriptionData['custom_id'] = $options['custom_id'];
            }

            // 🚀 Create the subscription on PayPal
            $subResponse = self::apiRequest(
                'POST',
                '/v1/billing/subscriptions',
                $subscriptionData,
                'sub_signula_' . ($options['userID'] ?? 0) . '_' . $tierID . '_' . time()
            );

            // ❌ Handle errors
            if (isset($subResponse['error']) && $subResponse['error'] === true) {
                return [
                    'success' => false,
                    'message' => 'Failed to create PayPal subscription: ' . ($subResponse['message'] ?? 'unknown'),
                ];
            }

            // 🔍 Extract the approval URL from the response links
            $approvalURL = null;
            if (isset($subResponse['links']) && is_array($subResponse['links'])) {
                foreach ($subResponse['links'] as $link) {
                    if (isset($link['rel']) && $link['rel'] === 'approve') {
                        $approvalURL = $link['href'];
                        break;
                    }
                }
            }

            // 📝 Log the subscription creation
            ActivityLogger::log(
                $options['userID'] ?? null,
                'paypal_subscription_created',
                'payment',
                'info',
                'PayPal subscription created: ' . ($subResponse['id'] ?? 'unknown')
                    . ' | Plan: ' . $planID
                    . ' | Tier: ' . ($tier['tierName'] ?? $tierID),
                [
                    'subscriptionID'       => $subResponse['id'] ?? null,
                    'planID'               => $planID,
                    'tierID'               => $tierID,
                    'billingCycle'         => $billingCycle,
                    'amount'               => $formattedAmount,
                    'currency'             => $currency,
                    'paypalSubscriptionID' => $subResponse['id'] ?? null,
                ]
            );

            // ✅ Return success with subscription details
            return [
                'success'        => true,
                'subscriptionID' => $subResponse['id'] ?? null,
                'approvalURL'    => $approvalURL,
                'planID'         => $planID,
                'status'         => $subResponse['status'] ?? 'APPROVAL_PENDING',
            ];

        } catch (\Exception $e) {
            // ❌ Catch-all error handling
            ActivityLogger::log(
                $options['userID'] ?? null,
                'paypal_subscription_error',
                'payment',
                'error',
                'Exception creating PayPal subscription: ' . $e->getMessage(),
                ['tierID' => $tierID, 'billingCycle' => $billingCycle]
            );

            return [
                'success' => false,
                'message' => 'An error occurred while creating the PayPal subscription',
            ];
        }
    }

    /**
     * 🔒 Verify PayPal Webhook Signature
     *
     * Verifies the authenticity of a PayPal webhook event by sending the
     * event headers and payload to PayPal's signature verification endpoint.
     * This prevents processing forged/spoofed webhook events.
     *
     * IMPORTANT: Always verify webhooks before processing them!
     *
     * @param string $payload  Raw JSON payload body from the webhook request
     * @param array  $headers  HTTP request headers (typically from getallheaders())
     *                         Required headers from PayPal:
     *                         - PAYPAL-AUTH-ALGO
     *                         - PAYPAL-CERT-URL
     *                         - PAYPAL-TRANSMISSION-ID
     *                         - PAYPAL-TRANSMISSION-SIG
     *                         - PAYPAL-TRANSMISSION-TIME
     * @return bool True if the webhook signature is valid, false otherwise
     *
     * @see https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature_post
     * @see https://developer.paypal.com/docs/api-basics/notifications/webhooks/
     *
     * @example
     * ```php
     * $payload = file_get_contents('php://input');
     * $headers = getallheaders();
     *
     * if (PayPalProvider::verifyWebhookSignature($payload, $headers)) {
     *     $event = json_decode($payload, true);
     *     PayPalProvider::handleWebhookEvent($event);
     * } else {
     *     http_response_code(401);
     *     die('Invalid webhook signature');
     * }
     * ```
     */
    public static function verifyWebhookSignature(string $payload, array $headers): bool
    {
        try {
            // 🔍 Normalise header keys to uppercase for consistent access
            // Different web servers may provide headers in different cases
            $normalizedHeaders = [];
            foreach ($headers as $key => $value) {
                $normalizedHeaders[strtoupper($key)] = $value;
            }

            // 🔍 Extract required PayPal headers
            $authAlgo        = $normalizedHeaders['PAYPAL-AUTH-ALGO'] ?? '';
            $certURL         = $normalizedHeaders['PAYPAL-CERT-URL'] ?? '';
            $transmissionID  = $normalizedHeaders['PAYPAL-TRANSMISSION-ID'] ?? '';
            $transmissionSig = $normalizedHeaders['PAYPAL-TRANSMISSION-SIG'] ?? '';
            $transmissionTime = $normalizedHeaders['PAYPAL-TRANSMISSION-TIME'] ?? '';

            // ❌ Validate all required headers are present
            if (empty($authAlgo) || empty($certURL) || empty($transmissionID)
                || empty($transmissionSig) || empty($transmissionTime)
            ) {
                ActivityLogger::log(
                    null,
                    'paypal_webhook_invalid',
                    'payment',
                    'warning',
                    'PayPal webhook verification failed: missing required headers',
                    [
                        'hasAuthAlgo'        => !empty($authAlgo),
                        'hasCertURL'         => !empty($certURL),
                        'hasTransmissionID'  => !empty($transmissionID),
                        'hasTransmissionSig' => !empty($transmissionSig),
                        'hasTransmissionTime' => !empty($transmissionTime),
                    ]
                );

                return false;
            }

            // 🔐 Retrieve the webhook ID from database settings (encrypted)
            $webhookID = SecurityUtils::decrypt(getSetting('payment.paypal.webhook_id', ''));
            if (empty($webhookID)) {
                ActivityLogger::log(
                    null,
                    'paypal_webhook_config_error',
                    'payment',
                    'error',
                    'PayPal webhook verification failed: webhook_id not configured',
                    []
                );

                return false;
            }

            // 📦 Parse the webhook event payload
            $webhookEvent = json_decode($payload, true);
            if ($webhookEvent === null) {
                ActivityLogger::log(
                    null,
                    'paypal_webhook_invalid',
                    'payment',
                    'warning',
                    'PayPal webhook verification failed: invalid JSON payload',
                    []
                );

                return false;
            }

            // 📋 Build the verification request body
            // @see https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature_post
            $verificationData = [
                'auth_algo'         => $authAlgo,
                'cert_url'          => $certURL,
                'transmission_id'   => $transmissionID,
                'transmission_sig'  => $transmissionSig,
                'transmission_time' => $transmissionTime,
                'webhook_id'        => $webhookID,
                'webhook_event'     => $webhookEvent,
            ];

            // 🚀 Send verification request to PayPal
            $response = self::apiRequest('POST', '/v1/notifications/verify-webhook-signature', $verificationData);

            // 🔍 Check the verification result
            // PayPal returns verification_status: 'SUCCESS' or 'FAILURE'
            $isValid = (
                isset($response['verification_status'])
                && $response['verification_status'] === 'SUCCESS'
            );

            // 📝 Log the verification result
            ActivityLogger::log(
                null,
                $isValid ? 'paypal_webhook_verified' : 'paypal_webhook_invalid',
                'payment',
                $isValid ? 'info' : 'warning',
                'PayPal webhook signature verification: ' . ($isValid ? 'SUCCESS' : 'FAILED'),
                [
                    'transmissionID'     => $transmissionID,
                    'verificationStatus' => $response['verification_status'] ?? 'unknown',
                    'eventType'          => $webhookEvent['event_type'] ?? 'unknown',
                ]
            );

            return $isValid;

        } catch (\Exception $e) {
            // ❌ Any exception during verification should fail safely (deny)
            ActivityLogger::log(
                null,
                'paypal_webhook_error',
                'payment',
                'error',
                'Exception during PayPal webhook verification: ' . $e->getMessage(),
                []
            );

            return false;
        }
    }

    /**
     * 📩 Handle a PayPal Webhook Event
     *
     * Processes a verified PayPal webhook event and takes appropriate action
     * in the SIGNula system (completing payments, updating subscriptions, etc.).
     *
     * IMPORTANT: Always call verifyWebhookSignature() before this method!
     *
     * Supported event types:
     * - PAYMENT.CAPTURE.COMPLETED  — Payment captured successfully
     * - PAYMENT.CAPTURE.DENIED     — Payment capture denied
     * - PAYMENT.CAPTURE.REFUNDED   — Payment was refunded
     * - BILLING.SUBSCRIPTION.ACTIVATED  — Subscription activated
     * - BILLING.SUBSCRIPTION.CANCELLED  — Subscription cancelled
     * - BILLING.SUBSCRIPTION.SUSPENDED  — Subscription suspended
     * - BILLING.SUBSCRIPTION.PAYMENT.FAILED — Subscription payment failed
     *
     * @param array $event Decoded webhook event payload (from json_decode)
     * @return array Processing result with 'handled' (bool) and 'message' (string)
     *
     * @see https://developer.paypal.com/docs/api-basics/notifications/webhooks/event-names/
     *
     * @example
     * ```php
     * $event = json_decode(file_get_contents('php://input'), true);
     * $result = PayPalProvider::handleWebhookEvent($event);
     * // Returns ['handled' => true, 'message' => '...']
     * ```
     */
    public static function handleWebhookEvent(array $event): array
    {
        // 🔍 Extract the event type and resource
        $eventType = $event['event_type'] ?? '';
        $resource = $event['resource'] ?? [];
        $eventID = $event['id'] ?? 'unknown';

        // 📝 Log the incoming webhook event
        ActivityLogger::log(
            null,
            'paypal_webhook_received',
            'payment',
            'info',
            'PayPal webhook event received: ' . $eventType . ' (Event ID: ' . $eventID . ')',
            [
                'eventID'   => $eventID,
                'eventType' => $eventType,
                'summary'   => $event['summary'] ?? '',
            ]
        );

        // 🔀 Process the event based on its type
        switch ($eventType) {

            // ═══════════════════════════════════════════════════════════
            // 💰 PAYMENT.CAPTURE.COMPLETED — Payment successfully captured
            // ═══════════════════════════════════════════════════════════
            case 'PAYMENT.CAPTURE.COMPLETED':
                $captureID = $resource['id'] ?? null;
                $amount = $resource['amount']['value'] ?? null;
                $currency = $resource['amount']['currency_code'] ?? null;
                $customID = $resource['custom_id'] ?? null;

                // 🔍 Find the pending payment in our database by custom_id or capture's
                // supplementary_data (PayPal may include the order ID)
                if ($captureID) {
                    $payment = Database::fetchOne(
                        "SELECT paymentID, userID FROM tblPayments WHERE transactionID = ? AND status IN ('pending', 'processing') ORDER BY createdAt DESC LIMIT 1",
                        [$captureID],
                        's'
                    );

                    if ($payment) {
                        PaymentManager::completePayment(
                            (int)$payment['paymentID'],
                            $captureID,
                            null
                        );
                    }
                }

                ActivityLogger::log(
                    null,
                    'paypal_payment_completed',
                    'payment',
                    'info',
                    'PayPal payment captured: ' . ($currency ?? '') . ' ' . ($amount ?? '0.00') . ' | Capture ID: ' . ($captureID ?? 'unknown'),
                    [
                        'captureID' => $captureID,
                        'amount'    => $amount,
                        'currency'  => $currency,
                        'customID'  => $customID,
                    ]
                );

                return [
                    'handled' => true,
                    'message' => 'Payment capture completed processed',
                ];

            // ═══════════════════════════════════════════════════════════
            // ❌ PAYMENT.CAPTURE.DENIED — Payment capture was denied
            // ═══════════════════════════════════════════════════════════
            case 'PAYMENT.CAPTURE.DENIED':
                $captureID = $resource['id'] ?? null;

                // 🔍 Find and mark the payment as failed
                if ($captureID) {
                    $payment = Database::fetchOne(
                        "SELECT paymentID FROM tblPayments WHERE transactionID = ? AND status IN ('pending', 'processing') ORDER BY createdAt DESC LIMIT 1",
                        [$captureID],
                        's'
                    );

                    if ($payment) {
                        Database::query(
                            "UPDATE tblPayments SET status = 'failed' WHERE paymentID = ?",
                            [(int)$payment['paymentID']],
                            'i'
                        );
                    }
                }

                ActivityLogger::log(
                    null,
                    'paypal_payment_denied',
                    'payment',
                    'warning',
                    'PayPal payment capture denied | Capture ID: ' . ($captureID ?? 'unknown'),
                    [
                        'captureID' => $captureID,
                        'resource'  => $resource,
                    ]
                );

                return [
                    'handled' => true,
                    'message' => 'Payment capture denied processed',
                ];

            // ═══════════════════════════════════════════════════════════
            // 💸 PAYMENT.CAPTURE.REFUNDED — Payment was refunded
            // ═══════════════════════════════════════════════════════════
            case 'PAYMENT.CAPTURE.REFUNDED':
                $refundID = $resource['id'] ?? null;
                $amount = $resource['amount']['value'] ?? null;
                $currency = $resource['amount']['currency_code'] ?? null;

                // 🔍 The refund resource links back to the original capture
                // Extract the capture ID from the links array
                $captureID = null;
                if (isset($resource['links']) && is_array($resource['links'])) {
                    foreach ($resource['links'] as $link) {
                        if (isset($link['rel']) && $link['rel'] === 'up') {
                            // The 'up' link points to the parent capture
                            // Extract the capture ID from the URL
                            $parts = explode('/', $link['href']);
                            $captureID = end($parts);
                            break;
                        }
                    }
                }

                // 🔍 Find the original payment and mark as refunded
                if ($captureID) {
                    $payment = Database::fetchOne(
                        "SELECT paymentID, amount FROM tblPayments WHERE transactionID = ? ORDER BY createdAt DESC LIMIT 1",
                        [$captureID],
                        's'
                    );

                    if ($payment && $amount !== null) {
                        $isPartial = ((float)$amount < (float)$payment['amount']);
                        $status = $isPartial ? 'partially_refunded' : 'refunded';

                        Database::query(
                            "UPDATE tblPayments SET status = ?, refundedAt = NOW(), refundAmount = ? WHERE paymentID = ?",
                            [$status, (float)$amount, (int)$payment['paymentID']],
                            'sdi'
                        );
                    }
                }

                ActivityLogger::log(
                    null,
                    'paypal_payment_refunded',
                    'payment',
                    'warning',
                    'PayPal payment refunded: ' . ($currency ?? '') . ' ' . ($amount ?? '0.00') . ' | Refund ID: ' . ($refundID ?? 'unknown'),
                    [
                        'refundID'  => $refundID,
                        'captureID' => $captureID,
                        'amount'    => $amount,
                        'currency'  => $currency,
                    ]
                );

                return [
                    'handled' => true,
                    'message' => 'Payment refund processed',
                ];

            // ═══════════════════════════════════════════════════════════
            // ✅ BILLING.SUBSCRIPTION.ACTIVATED — Subscription activated
            // ═══════════════════════════════════════════════════════════
            case 'BILLING.SUBSCRIPTION.ACTIVATED':
                $paypalSubID = $resource['id'] ?? null;
                $planID = $resource['plan_id'] ?? null;
                $customID = $resource['custom_id'] ?? null;

                // 🔍 Find the local subscription by PayPal subscription ID or custom_id
                // and activate it
                if ($paypalSubID) {
                    $localSub = Database::fetchOne(
                        "SELECT subscriptionID, userID FROM tblSubscriptions WHERE externalSubscriptionID = ? AND subscriptionStatus IN ('pending', 'trial') ORDER BY createdAt DESC LIMIT 1",
                        [$paypalSubID],
                        's'
                    );

                    if ($localSub) {
                        Database::query(
                            "UPDATE tblSubscriptions SET subscriptionStatus = 'active' WHERE subscriptionID = ?",
                            [(int)$localSub['subscriptionID']],
                            'i'
                        );

                        ActivityLogger::log(
                            (int)$localSub['userID'],
                            'subscription_activated',
                            'payment',
                            'info',
                            'Subscription activated via PayPal webhook | PayPal Sub ID: ' . $paypalSubID,
                            [
                                'subscriptionID'       => $localSub['subscriptionID'],
                                'paypalSubscriptionID' => $paypalSubID,
                                'planID'               => $planID,
                            ]
                        );
                    }
                }

                return [
                    'handled' => true,
                    'message' => 'Subscription activation processed',
                ];

            // ═══════════════════════════════════════════════════════════
            // ❌ BILLING.SUBSCRIPTION.CANCELLED — Subscription cancelled
            // ═══════════════════════════════════════════════════════════
            case 'BILLING.SUBSCRIPTION.CANCELLED':
                $paypalSubID = $resource['id'] ?? null;

                if ($paypalSubID) {
                    $localSub = Database::fetchOne(
                        "SELECT subscriptionID, userID FROM tblSubscriptions WHERE externalSubscriptionID = ? AND subscriptionStatus IN ('active', 'past_due') ORDER BY createdAt DESC LIMIT 1",
                        [$paypalSubID],
                        's'
                    );

                    if ($localSub) {
                        Database::query(
                            "UPDATE tblSubscriptions SET subscriptionStatus = 'cancelled', cancelledAt = NOW(), cancellationReason = 'Cancelled via PayPal' WHERE subscriptionID = ?",
                            [(int)$localSub['subscriptionID']],
                            'i'
                        );

                        ActivityLogger::log(
                            (int)$localSub['userID'],
                            'subscription_cancelled',
                            'payment',
                            'info',
                            'Subscription cancelled via PayPal webhook | PayPal Sub ID: ' . $paypalSubID,
                            [
                                'subscriptionID'       => $localSub['subscriptionID'],
                                'paypalSubscriptionID' => $paypalSubID,
                            ]
                        );
                    }
                }

                return [
                    'handled' => true,
                    'message' => 'Subscription cancellation processed',
                ];

            // ═══════════════════════════════════════════════════════════
            // ⏸️ BILLING.SUBSCRIPTION.SUSPENDED — Subscription suspended
            // ═══════════════════════════════════════════════════════════
            case 'BILLING.SUBSCRIPTION.SUSPENDED':
                $paypalSubID = $resource['id'] ?? null;

                if ($paypalSubID) {
                    $localSub = Database::fetchOne(
                        "SELECT subscriptionID, userID FROM tblSubscriptions WHERE externalSubscriptionID = ? AND subscriptionStatus = 'active' ORDER BY createdAt DESC LIMIT 1",
                        [$paypalSubID],
                        's'
                    );

                    if ($localSub) {
                        Database::query(
                            "UPDATE tblSubscriptions SET subscriptionStatus = 'paused', pausedAt = NOW() WHERE subscriptionID = ?",
                            [(int)$localSub['subscriptionID']],
                            'i'
                        );

                        ActivityLogger::log(
                            (int)$localSub['userID'],
                            'subscription_suspended',
                            'payment',
                            'warning',
                            'Subscription suspended via PayPal webhook | PayPal Sub ID: ' . $paypalSubID,
                            [
                                'subscriptionID'       => $localSub['subscriptionID'],
                                'paypalSubscriptionID' => $paypalSubID,
                            ]
                        );
                    }
                }

                return [
                    'handled' => true,
                    'message' => 'Subscription suspension processed',
                ];

            // ═══════════════════════════════════════════════════════════
            // ⚠️ BILLING.SUBSCRIPTION.PAYMENT.FAILED — Payment failed
            // ═══════════════════════════════════════════════════════════
            case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
                $paypalSubID = $resource['id'] ?? null;

                if ($paypalSubID) {
                    $localSub = Database::fetchOne(
                        "SELECT subscriptionID, userID FROM tblSubscriptions WHERE externalSubscriptionID = ? AND subscriptionStatus IN ('active', 'past_due') ORDER BY createdAt DESC LIMIT 1",
                        [$paypalSubID],
                        's'
                    );

                    if ($localSub) {
                        // 📅 Mark as past_due — the subscription is still technically active
                        // but the latest payment attempt failed. PayPal will retry.
                        Database::query(
                            "UPDATE tblSubscriptions SET subscriptionStatus = 'past_due' WHERE subscriptionID = ?",
                            [(int)$localSub['subscriptionID']],
                            'i'
                        );

                        ActivityLogger::log(
                            (int)$localSub['userID'],
                            'subscription_payment_failed',
                            'payment',
                            'warning',
                            'Subscription payment failed via PayPal webhook | PayPal Sub ID: ' . $paypalSubID,
                            [
                                'subscriptionID'       => $localSub['subscriptionID'],
                                'paypalSubscriptionID' => $paypalSubID,
                            ]
                        );
                    }
                }

                return [
                    'handled' => true,
                    'message' => 'Subscription payment failure processed',
                ];

            // ═══════════════════════════════════════════════════════════
            // ❓ UNKNOWN EVENT — Log but don't process
            // ═══════════════════════════════════════════════════════════
            default:
                ActivityLogger::log(
                    null,
                    'paypal_webhook_unhandled',
                    'payment',
                    'info',
                    'Unhandled PayPal webhook event type: ' . $eventType,
                    [
                        'eventID'   => $eventID,
                        'eventType' => $eventType,
                    ]
                );

                return [
                    'handled' => false,
                    'message' => 'Unhandled event type: ' . $eventType,
                ];
        }
    }

    /**
     * 💸 Process a PayPal Refund
     *
     * Refunds a captured PayPal payment, either fully or partially.
     * Uses the Captures API to issue the refund.
     *
     * @param string      $captureID PayPal capture/transaction ID (from captureOrder() result)
     * @param float|null  $amount    Refund amount (null = full refund)
     * @param string      $currency  ISO 4217 currency code (default: 'GBP')
     * @param string      $reason    Reason for the refund (stored internally and sent to PayPal)
     * @return array Result array with keys:
     *               - success (bool)
     *               - refundID (string) PayPal refund ID
     *               - status (string) Refund status
     *               - amount (string) Refunded amount
     *               OR error keys on failure
     *
     * @see https://developer.paypal.com/docs/api/payments/v2/#captures_refund
     *
     * @example
     * ```php
     * // Full refund
     * $result = PayPalProvider::refund('2GG279541U471931P');
     *
     * // Partial refund of GBP 10.00
     * $result = PayPalProvider::refund('2GG279541U471931P', 10.00, 'GBP', 'Partial service credit');
     *
     * if ($result['success']) {
     *     echo "Refund ID: " . $result['refundID'];
     * }
     * ```
     */
    public static function refund(
        string $captureID,
        ?float $amount = null,
        string $currency = 'GBP',
        string $reason = ''
    ): array {
        try {
            // 🛡️ TEST-MODE GUARD (G-002 §0/§9.1) — see createSubscription() above
            // for the full rationale. Checked first, before any PayPal API call.
            BillingMode::assertTestMode('paypal');

            // 🔍 Validate the capture ID
            if (empty($captureID)) {
                return [
                    'success' => false,
                    'message' => 'Capture ID is required for refund',
                ];
            }

            // 📋 Build the refund request body
            // @see https://developer.paypal.com/docs/api/payments/v2/#captures_refund
            $refundData = [];

            // 💰 If amount is specified, it's a partial refund
            // If null, PayPal issues a full refund for the captured amount
            if ($amount !== null) {
                $refundData['amount'] = [
                    'value'         => number_format($amount, 2, '.', ''),
                    'currency_code' => strtoupper($currency),
                ];
            }

            // 📝 Add the refund reason as a note to payer (visible to buyer)
            if (!empty($reason)) {
                $refundData['note_to_payer'] = mb_substr($reason, 0, 255);  // PayPal limit: 255 chars
            }

            // 🔄 Generate an idempotency key for safe retries
            $idempotencyKey = 'refund_' . $captureID . '_' . time();

            // 🚀 Send the refund request to PayPal
            $response = self::apiRequest(
                'POST',
                '/v2/payments/captures/' . urlencode($captureID) . '/refund',
                !empty($refundData) ? $refundData : null,
                $idempotencyKey
            );

            // ❌ Handle API-level errors
            if (isset($response['error']) && $response['error'] === true) {
                ActivityLogger::log(
                    null,
                    'paypal_refund_error',
                    'payment',
                    'error',
                    'PayPal refund failed for capture: ' . $captureID . ' — ' . ($response['message'] ?? 'unknown'),
                    [
                        'captureID' => $captureID,
                        'amount'    => $amount,
                        'currency'  => $currency,
                        'response'  => $response,
                    ]
                );

                return [
                    'success' => false,
                    'message' => $response['message'] ?? 'Failed to process PayPal refund',
                ];
            }

            // 🔍 Extract refund details
            $refundID = $response['id'] ?? null;
            $refundStatus = $response['status'] ?? null;
            $refundedAmount = $response['amount']['value'] ?? ($amount !== null ? number_format($amount, 2, '.', '') : 'full');
            $refundedCurrency = $response['amount']['currency_code'] ?? $currency;

            // 📝 Log the successful refund
            ActivityLogger::log(
                null,
                'paypal_refund_completed',
                'payment',
                'warning',
                'PayPal refund processed: ' . $refundedCurrency . ' ' . $refundedAmount
                    . ' | Refund ID: ' . ($refundID ?? 'unknown')
                    . ' | Capture: ' . $captureID,
                [
                    'refundID'  => $refundID,
                    'captureID' => $captureID,
                    'amount'    => $refundedAmount,
                    'currency'  => $refundedCurrency,
                    'status'    => $refundStatus,
                    'reason'    => $reason,
                ]
            );

            // ✅ Return refund result
            return [
                'success'  => ($refundStatus === 'COMPLETED' || $refundStatus === 'PENDING'),
                'refundID' => $refundID,
                'status'   => $refundStatus,
                'amount'   => $refundedAmount,
                'currency' => $refundedCurrency,
            ];

        } catch (\Exception $e) {
            // ❌ Catch-all error handling
            ActivityLogger::log(
                null,
                'paypal_refund_error',
                'payment',
                'error',
                'Exception processing PayPal refund: ' . $e->getMessage(),
                ['captureID' => $captureID, 'amount' => $amount]
            );

            return [
                'success' => false,
                'message' => 'An error occurred while processing the PayPal refund',
            ];
        }
    }

    /**
     * 🧪 Test PayPal API Connection
     *
     * Verifies that PayPal API credentials are valid and the API is reachable
     * by attempting to obtain an access token. Useful for the admin settings
     * page to validate configuration.
     *
     * @return array Result array:
     *               - success (bool)
     *               - message (string) Human-readable status
     *               - mode (string) Current mode ('sandbox' or 'live')
     *               OR error details on failure
     *
     * @example
     * ```php
     * $result = PayPalProvider::testConnection();
     * if ($result['success']) {
     *     echo "Connected to PayPal (" . $result['mode'] . " mode)";
     * } else {
     *     echo "Connection failed: " . $result['message'];
     * }
     * ```
     */
    public static function testConnection(): array
    {
        try {
            // 🔍 Check if PayPal is enabled
            if (!self::isEnabled()) {
                return [
                    'success' => false,
                    'message' => 'PayPal is not enabled. Enable it in Settings > Payment > PayPal.',
                ];
            }

            // 🔑 Attempt to obtain an access token
            // This validates both the credentials and network connectivity
            $token = self::getAccessToken();

            // 🔍 Determine the current mode
            $mode = getSetting('payment.paypal.mode', 'sandbox');

            // 📝 Log the successful connection test
            ActivityLogger::log(
                null,
                'paypal_connection_test',
                'payment',
                'info',
                'PayPal API connection test successful (' . $mode . ' mode)',
                ['mode' => $mode]
            );

            // ✅ Connection successful
            return [
                'success' => true,
                'message' => 'PayPal API connection successful (' . $mode . ' mode)',
                'mode'    => $mode,
            ];

        } catch (RuntimeException $e) {
            // ❌ Authentication or connection failure
            $mode = getSetting('payment.paypal.mode', 'sandbox');

            ActivityLogger::log(
                null,
                'paypal_connection_test_failed',
                'payment',
                'error',
                'PayPal API connection test failed: ' . $e->getMessage(),
                ['mode' => $mode]
            );

            return [
                'success' => false,
                'message' => 'PayPal API connection failed: ' . $e->getMessage(),
                'mode'    => $mode,
            ];
        } catch (\Exception $e) {
            // ❌ Unexpected error
            return [
                'success' => false,
                'message' => 'Unexpected error during PayPal connection test',
            ];
        }
    }
}
