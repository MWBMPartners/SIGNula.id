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
 * 🎨 SIGNula - Patreon Payment Provider
 * ============================================================================
 *
 * Purpose: Integrates Patreon API v2 for membership/pledge-based payment
 *          processing within the SIGNula Universal SSO platform.
 *          Uses raw cURL (no Composer/SDK) for Dreamhost shared hosting
 *          compatibility.
 *
 * PHP Version: 8.3+
 *
 * Payment Flow:
 * 1. Creator configures campaign and tiers on Patreon
 * 2. Users pledge on Patreon to the creator's campaign
 * 3. Patreon sends webhook events to SIGNula for pledge lifecycle changes
 * 4. SIGNula verifies webhook using HMAC-MD5 (X-Patreon-Signature header)
 * 5. SIGNula processes the event (records payment, maps tiers, syncs members)
 *
 * Features:
 * - Webhook verification via HMAC-MD5 signature (X-Patreon-Signature header)
 * - Handles member/pledge lifecycle events (create, update, delete)
 * - Campaign tier retrieval and mapping to SIGNula tiers
 * - Member pledge status checking via API
 * - Full activity logging for all API interactions and payment events
 * - Secure credential handling via encrypted database storage
 * - Connection testing for admin configuration verification
 *
 * Patreon API v2 Details:
 * - Base URL: https://www.patreon.com/api/oauth2/v2
 * - Auth: Authorization: Bearer {access_token} header
 * - OAuth2 token URL: https://www.patreon.com/api/oauth2/token
 * - Webhook signature: HMAC-MD5 via X-Patreon-Signature header
 * - Event type from: X-Patreon-Event header
 * - JSON:API format responses (data/included/relationships pattern)
 *
 * @see https://docs.patreon.com/#apiv2-resources
 * @see https://docs.patreon.com/#webhooks
 * @see https://www.patreon.com/portal/registration/register-webhooks
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
    error_log('[SIGNula] PatreonProvider: PaymentManager.php not found at: ' . $paymentManagerPath);
}

/**
 * 🎨 Patreon Payment Provider
 *
 * Provides static methods for interacting with the Patreon API v2
 * to process membership/pledge-based payments. All methods are static
 * following the SIGNula project pattern (no instantiation required).
 *
 * Settings are stored in tblSettings and retrieved via getSetting().
 * Sensitive values (access tokens, webhook secrets) are encrypted in the
 * database and decrypted at runtime via SecurityUtils::decrypt().
 *
 * @see https://docs.patreon.com/#apiv2-resources
 */
class PatreonProvider
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /**
     * @var string Payment provider identifier used in tblPayments records
     * This value is stored in the paymentProvider column to identify
     * payments processed via Patreon.
     */
    private const PROVIDER_NAME = 'patreon';

    /**
     * @var string Default payment method label for Patreon payments
     * Patreon handles the payment method internally (card, PayPal, etc.),
     * so from SIGNula's perspective the method is simply 'patreon'.
     */
    private const DEFAULT_PAYMENT_METHOD = 'patreon';

    /**
     * @var string Patreon API v2 base URL
     * @see https://docs.patreon.com/#apiv2-resources
     */
    private const API_BASE_URL = 'https://www.patreon.com/api/oauth2/v2';

    /**
     * @var int cURL connection timeout in seconds
     * Prevents hanging requests if the Patreon API is unresponsive.
     * @see https://www.php.net/manual/en/function.curl-setopt.php (CURLOPT_CONNECTTIMEOUT)
     */
    private const CONNECT_TIMEOUT = 10;

    /**
     * @var int cURL total request timeout in seconds
     * Maximum time the entire cURL transfer is allowed to take.
     * @see https://www.php.net/manual/en/function.curl-setopt.php (CURLOPT_TIMEOUT)
     */
    private const REQUEST_TIMEOUT = 30;

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
     * @param string $key     Setting key (e.g., 'payment.patreon.enabled')
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

    /**
     * 📡 Make an API request to the Patreon API v2
     *
     * Handles all HTTP communication with the Patreon REST API using
     * PHP's cURL extension. Automatically attaches Bearer token
     * authentication headers and handles JSON encoding/decoding.
     * Patreon API v2 returns JSON:API format responses.
     *
     * Request flow:
     * 1. Decrypt creator access token from database (stored encrypted in tblSettings)
     * 2. Build cURL request with proper headers and SSL verification
     * 3. Execute request and parse JSON:API response
     * 4. Log errors via ActivityLogger if request fails
     *
     * @param string     $method      HTTP method: 'GET', 'POST', 'PATCH', 'DELETE'
     * @param string     $endpoint    API endpoint path (e.g., '/campaigns/{id}')
     * @param array|null $data        Optional request body data (will be JSON-encoded)
     * @param array|null $credentials Optional partner credentials for Level 2 payments
     *
     * @return array Decoded JSON response array, or error array with 'error' key
     *
     * @see https://docs.patreon.com/#apiv2-resources
     * @see https://www.php.net/manual/en/book.curl.php
     */
    private static function apiRequest(string $method, string $endpoint, ?array $data = null, ?array $credentials = null): array
    {
        try {
            // 🔑 Resolve the access token — either from partner credentials or global settings
            $accessToken = null;

            if ($credentials !== null && !empty($credentials['access_token'])) {
                // 🏢 Using partner-provided access token (Level 2)
                $accessToken = $credentials['access_token'];
            } else {
                // 🌐 Using SIGNula's global Patreon creator access token
                $encryptedToken = getSetting('payment.patreon.creator_access_token');
                if (empty($encryptedToken)) {
                    // ❌ No access token configured
                    ActivityLogger::log(
                        null,
                        'patreon_api_error',
                        'payment',
                        'error',
                        'Patreon creator access token is not configured in tblSettings',
                        ['endpoint' => $endpoint, 'method' => $method]
                    );
                    return ['error' => 'Patreon creator access token is not configured'];
                }

                // 🔓 Decrypt the access token using SecurityUtils
                // @see SecurityUtils::decrypt() for AES-256-CBC decryption details
                $accessToken = SecurityUtils::decrypt($encryptedToken);
            }

            if (empty($accessToken)) {
                // ❌ Decryption failed or returned empty
                ActivityLogger::log(
                    null,
                    'patreon_api_error',
                    'payment',
                    'error',
                    'Patreon creator access token is empty — decryption may have failed',
                    ['endpoint' => $endpoint, 'method' => $method]
                );
                return ['error' => 'Patreon creator access token is not available'];
            }

            // 🌐 Build the full request URL
            // Example: https://www.patreon.com/api/oauth2/v2/campaigns/{id}
            $url = self::API_BASE_URL . $endpoint;

            // 📄 Construct request headers
            // Authorization: Bearer token for Patreon API v2
            // @see https://docs.patreon.com/#making-api-requests
            $headers = [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ];

            // ⚙️ Initialise cURL handle
            // @see https://www.php.net/manual/en/function.curl-init.php
            $ch = curl_init();

            // 🔗 Set the target URL
            curl_setopt($ch, CURLOPT_URL, $url);

            // 📃 Return the response body as a string instead of outputting it
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // 🛡️ Enable SSL certificate verification for security
            // @see https://www.php.net/manual/en/function.curl-setopt.php
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            // 🕐 Set connection and request timeouts to prevent hanging
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT);

            // 🏷️ Attach the authentication and content-type headers
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // 🔄 Configure the HTTP method and request body
            $method = strtoupper($method);

            if ($method === 'POST') {
                // 📤 POST request with JSON-encoded body
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            } elseif ($method === 'PATCH') {
                // ✏️ PATCH request with JSON-encoded body
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            } elseif ($method === 'DELETE') {
                // 🗑️ DELETE request
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }
            // GET is the default cURL method, no special configuration needed

            // 🚀 Execute the cURL request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);

            // 🧹 Close the cURL handle to free resources
            curl_close($ch);

            // ❌ Check for cURL-level errors (network issues, DNS failures, timeouts, etc.)
            if ($curlErrno !== 0) {
                ActivityLogger::log(
                    null,
                    'patreon_api_error',
                    'payment',
                    'error',
                    'Patreon API cURL error: ' . $curlError,
                    [
                        'endpoint'  => $endpoint,
                        'method'    => $method,
                        'curlErrno' => $curlErrno,
                        'curlError' => $curlError,
                    ]
                );
                return ['error' => 'API request failed: ' . $curlError];
            }

            // 📄 Decode the JSON response body
            // @see https://www.php.net/manual/en/function.json-decode.php
            $decoded = json_decode($response, true);

            // ⚠️ Check for JSON decoding errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                ActivityLogger::log(
                    null,
                    'patreon_api_error',
                    'payment',
                    'error',
                    'Patreon API returned invalid JSON: ' . json_last_error_msg(),
                    [
                        'endpoint'    => $endpoint,
                        'method'      => $method,
                        'httpCode'    => $httpCode,
                        'rawResponse' => mb_substr($response, 0, 500),
                    ]
                );
                return ['error' => 'Invalid JSON response from Patreon API'];
            }

            // ❌ Check for HTTP error status codes (4xx, 5xx)
            if ($httpCode >= 400) {
                // Patreon API v2 returns errors in JSON:API format
                $errorMessage = 'Unknown API error';
                $errorDetail = '';
                if (isset($decoded['errors']) && is_array($decoded['errors']) && !empty($decoded['errors'])) {
                    $firstError = $decoded['errors'][0];
                    $errorMessage = $firstError['title'] ?? $firstError['detail'] ?? 'Unknown API error';
                    $errorDetail = $firstError['detail'] ?? '';
                }

                ActivityLogger::log(
                    null,
                    'patreon_api_error',
                    'payment',
                    'warning',
                    'Patreon API error (HTTP ' . $httpCode . '): ' . $errorMessage,
                    [
                        'endpoint'    => $endpoint,
                        'method'      => $method,
                        'httpCode'    => $httpCode,
                        'errorMsg'    => $errorMessage,
                        'errorDetail' => $errorDetail,
                    ]
                );
                return [
                    'error'    => $errorMessage,
                    'httpCode' => $httpCode,
                    'detail'   => $errorDetail,
                ];
            }

            // ✅ Return the decoded JSON response
            return $decoded;

        } catch (\Exception $e) {
            // 🚨 Catch any unexpected exceptions
            ActivityLogger::log(
                null,
                'patreon_api_exception',
                'payment',
                'error',
                'Unexpected exception during Patreon API request: ' . $e->getMessage(),
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

    // ========================================================================
    // ✅ STATUS & CONFIGURATION METHODS
    // ========================================================================

    /**
     * 🔌 Check if the Patreon payment provider is enabled
     *
     * Verifies two conditions:
     * 1. The 'payment.patreon.enabled' setting is set to '1' in tblSettings
     * 2. A non-empty creator access token is configured
     *
     * Both conditions must be true for Patreon integration to be available.
     *
     * @return bool True if Patreon payments are enabled and configured, false otherwise
     *
     * @example
     * ```php
     * if (PatreonProvider::isEnabled()) {
     *     // Show Patreon membership options to the user
     * }
     * ```
     */
    public static function isEnabled(): bool
    {
        // 🔍 Check the global enable/disable toggle
        $enabled = getSetting('payment.patreon.enabled');
        if ($enabled !== '1') {
            return false;
        }

        // 🔑 Verify that a creator access token is actually configured
        $accessToken = getSetting('payment.patreon.creator_access_token');
        if (empty($accessToken)) {
            return false;
        }

        return true;
    }

    // ========================================================================
    // 🛡️ WEBHOOK VERIFICATION
    // ========================================================================

    /**
     * 🔒 Verify a Patreon webhook signature
     *
     * Verifies the authenticity of an incoming webhook request by comparing
     * the HMAC-MD5 signature in the X-Patreon-Signature header against a
     * signature computed from the raw request body and the shared webhook
     * secret.
     *
     * IMPORTANT: Patreon uses HMAC-MD5 (NOT SHA256) for webhook signatures.
     * This is specified by Patreon's webhook documentation.
     *
     * This MUST be called before processing any webhook event to prevent
     * forged/spoofed webhook attacks.
     *
     * Signature verification process:
     * 1. Retrieve and decrypt the webhook secret from tblSettings
     * 2. Compute HMAC-MD5 of the raw payload using the secret
     * 3. Compare computed signature with the provided signature using
     *    hash_equals() to prevent timing attacks
     *
     * @param string $payload         Raw request body (the JSON string, NOT decoded)
     * @param string $signatureHeader Value of the X-Patreon-Signature HTTP header
     *
     * @return bool True if the signature is valid, false otherwise
     *
     * @see https://docs.patreon.com/#webhooks
     * @see https://www.php.net/manual/en/function.hash-hmac.php
     * @see https://www.php.net/manual/en/function.hash-equals.php
     *
     * @example
     * ```php
     * $payload = file_get_contents('php://input');
     * $signature = $_SERVER['HTTP_X_PATREON_SIGNATURE'] ?? '';
     *
     * if (!PatreonProvider::verifyWebhookSignature($payload, $signature)) {
     *     http_response_code(401);
     *     die('Invalid signature');
     * }
     * ```
     */
    public static function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        try {
            // 🛡️ Reject empty payloads or signatures immediately
            if (empty($payload) || empty($signatureHeader)) {
                ActivityLogger::log(
                    null,
                    'patreon_webhook_invalid',
                    'payment',
                    'warning',
                    'Patreon webhook verification failed: empty payload or signature',
                    [
                        'hasPayload'   => !empty($payload),
                        'hasSignature' => !empty($signatureHeader),
                    ]
                );
                return false;
            }

            // 🔑 Retrieve and decrypt the webhook shared secret
            // The secret is stored encrypted (isSensitive=1) in tblSettings
            $encryptedSecret = getSetting('payment.patreon.webhook_secret');
            if (empty($encryptedSecret)) {
                ActivityLogger::log(
                    null,
                    'patreon_webhook_error',
                    'payment',
                    'error',
                    'Patreon webhook secret is not configured in tblSettings',
                    []
                );
                return false;
            }

            // 🔓 Decrypt the webhook secret
            // @see SecurityUtils::decrypt() for AES-256-CBC decryption details
            $secret = SecurityUtils::decrypt($encryptedSecret);
            if (empty($secret)) {
                ActivityLogger::log(
                    null,
                    'patreon_webhook_error',
                    'payment',
                    'error',
                    'Failed to decrypt Patreon webhook secret',
                    []
                );
                return false;
            }

            // 🧮 Compute the expected HMAC-MD5 signature
            // IMPORTANT: Patreon uses MD5, not SHA256
            // @see https://docs.patreon.com/#webhooks
            // @see https://www.php.net/manual/en/function.hash-hmac.php
            $computedSignature = hash_hmac('md5', $payload, $secret);

            // 🔐 Use timing-safe comparison to prevent timing attacks
            // hash_equals() compares strings in constant time regardless of
            // where the first difference occurs
            // @see https://www.php.net/manual/en/function.hash-equals.php
            $isValid = hash_equals($computedSignature, $signatureHeader);

            // 📝 Log the verification result
            if (!$isValid) {
                ActivityLogger::log(
                    null,
                    'patreon_webhook_signature_mismatch',
                    'payment',
                    'warning',
                    'Patreon webhook signature verification failed - possible forged request',
                    [
                        'providedSignature' => mb_substr($signatureHeader, 0, 16) . '...',
                        'payloadLength'     => strlen($payload),
                        'remoteIP'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ]
                );
            }

            return $isValid;

        } catch (\Exception $e) {
            // 🚨 Log any unexpected exceptions during verification
            ActivityLogger::log(
                null,
                'patreon_webhook_exception',
                'payment',
                'error',
                'Exception during Patreon webhook signature verification: ' . $e->getMessage(),
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
     * 🔔 Handle a verified Patreon webhook event
     *
     * Processes webhook events from Patreon to record payments and manage
     * member/pledge lifecycle in the SIGNula database. This method should
     * ONLY be called AFTER the webhook signature has been verified via
     * verifyWebhookSignature().
     *
     * The event type is provided separately (from the X-Patreon-Event header),
     * while the event data is the decoded JSON body in JSON:API format.
     *
     * Supported event types:
     * - members:pledge:create  — New pledge created (record payment)
     * - members:pledge:update  — Pledge amount/tier changed
     * - members:pledge:delete  — Pledge cancelled
     * - members:create         — New member joined campaign
     * - members:update         — Member details updated
     * - members:delete         — Member removed from campaign
     *
     * @param array  $event     The decoded webhook event payload (JSON:API format)
     * @param string $eventType The event type from X-Patreon-Event header
     *
     * @return array Result with ['success' => bool, 'message' => string, 'eventType' => string]
     *
     * @see https://docs.patreon.com/#webhooks
     *
     * @example
     * ```php
     * $payload = file_get_contents('php://input');
     * $signature = $_SERVER['HTTP_X_PATREON_SIGNATURE'] ?? '';
     * $eventType = $_SERVER['HTTP_X_PATREON_EVENT'] ?? '';
     *
     * if (PatreonProvider::verifyWebhookSignature($payload, $signature)) {
     *     $event = json_decode($payload, true);
     *     $result = PatreonProvider::handleWebhookEvent($event, $eventType);
     * }
     * ```
     */
    public static function handleWebhookEvent(array $event, string $eventType): array
    {
        try {
            // 🔍 Extract data from JSON:API format response
            // Patreon webhooks follow JSON:API spec with data/attributes/relationships
            $memberData = $event['data'] ?? [];
            $attributes = $memberData['attributes'] ?? [];
            $relationships = $memberData['relationships'] ?? [];
            $included = $event['included'] ?? [];

            // 👤 Extract member details from attributes
            $patronEmail = $attributes['email'] ?? '';
            $fullName = $attributes['full_name'] ?? '';
            $patronStatus = $attributes['patron_status'] ?? '';
            $pledgeAmountCents = (int)($attributes['pledge_amount_cents'] ?? 0);
            $pledgeAmount = round($pledgeAmountCents / 100, 2);
            $memberId = $memberData['id'] ?? '';

            // 🔍 Extract tier information from included resources
            $currentTiers = [];
            $tierRelationships = $relationships['currently_entitled_tiers']['data'] ?? [];
            foreach ($tierRelationships as $tierRef) {
                $tierId = $tierRef['id'] ?? '';
                // 🔎 Look up the full tier details from the included array
                foreach ($included as $includedResource) {
                    if (($includedResource['type'] ?? '') === 'tier' && ($includedResource['id'] ?? '') === $tierId) {
                        $currentTiers[] = [
                            'id'    => $tierId,
                            'title' => $includedResource['attributes']['title'] ?? '',
                        ];
                        break;
                    }
                }
            }

            // 👤 Attempt to match the Patreon member to a SIGNula user by email
            $userID = null;
            if (!empty($patronEmail) && filter_var($patronEmail, FILTER_VALIDATE_EMAIL)) {
                $user = Database::fetchOne(
                    "SELECT userID FROM tblUsers WHERE email = ? LIMIT 1",
                    [$patronEmail],
                    's'
                );

                if ($user) {
                    $userID = (int)$user['userID'];
                }
            }

            // 📝 Check if we should log all events (configurable via settings)
            $logAllEvents = getSetting('payment.patreon.log_all_events') === '1';

            // 📝 Log the incoming webhook event
            ActivityLogger::log(
                $userID,
                'patreon_webhook_received',
                'payment',
                'info',
                'Patreon webhook event received: ' . $eventType . ' for member ' . $fullName,
                [
                    'eventType'    => $eventType,
                    'memberId'     => $memberId,
                    'patronEmail'  => $patronEmail,
                    'fullName'     => $fullName,
                    'patronStatus' => $patronStatus,
                    'pledgeAmount' => $pledgeAmount,
                    'tiers'        => $currentTiers,
                    'matchedUser'  => $userID,
                ]
            );

            // 🔀 Process the event based on its type
            // @see https://docs.patreon.com/#webhooks
            switch ($eventType) {

                // =====================================================
                // 💰 members:pledge:create — New pledge created
                // =====================================================
                case 'members:pledge:create':
                    // A new patron has pledged to the campaign
                    // Record the pledge as a payment in tblPayments

                    // 🛡️ Validate pledge amount
                    if ($pledgeAmount <= 0) {
                        ActivityLogger::log(
                            $userID,
                            'patreon_pledge_invalid',
                            'payment',
                            'warning',
                            'Patreon pledge:create with zero/negative amount for member: ' . $fullName,
                            ['memberId' => $memberId, 'pledgeAmountCents' => $pledgeAmountCents]
                        );

                        return [
                            'success'   => false,
                            'message'   => 'Invalid pledge amount',
                            'eventType' => $eventType,
                        ];
                    }

                    // 💾 Record the payment via PaymentManager
                    $paymentResult = PaymentManager::recordPayment(
                        $userID ?? 0,
                        $pledgeAmount,
                        self::DEFAULT_PAYMENT_METHOD,
                        self::PROVIDER_NAME,
                        [
                            'currency'      => 'USD',
                            'transactionID' => 'patreon_pledge_' . $memberId . '_' . time(),
                            'description'   => 'Patreon Pledge from ' . $fullName
                                . (!empty($currentTiers) ? ' (Tier: ' . $currentTiers[0]['title'] . ')' : ''),
                            'metadata'      => [
                                'memberId'     => $memberId,
                                'fullName'     => $fullName,
                                'email'        => $patronEmail,
                                'patronStatus' => $patronStatus,
                                'pledgeAmount' => $pledgeAmount,
                                'tiers'        => $currentTiers,
                                'eventType'    => $eventType,
                            ],
                        ]
                    );

                    $paymentID = $paymentResult['paymentID'] ?? null;

                    // ✅ Patreon webhooks indicate the pledge has been created,
                    // mark the payment as completed
                    if ($paymentID) {
                        PaymentManager::completePayment(
                            $paymentID,
                            'patreon_pledge_' . $memberId,
                            null
                        );
                    }

                    ActivityLogger::log(
                        $userID,
                        'patreon_pledge_created',
                        'payment',
                        'info',
                        'Patreon pledge created: $' . number_format($pledgeAmount, 2)
                            . ' from ' . $fullName,
                        [
                            'memberId'  => $memberId,
                            'paymentID' => $paymentID,
                            'amount'    => $pledgeAmount,
                            'tiers'     => $currentTiers,
                        ]
                    );

                    // 🔗 Dispatch webhook event for integrating services
                    if (class_exists('WebhookManager')) {
                        WebhookManager::dispatch('payment.patreon.pledge_created', [
                            'paymentID'    => $paymentID,
                            'userID'       => $userID,
                            'memberId'     => $memberId,
                            'fullName'     => $fullName,
                            'pledgeAmount' => $pledgeAmount,
                            'tiers'        => $currentTiers,
                        ]);
                    }

                    return [
                        'success'   => true,
                        'message'   => 'Pledge created and payment recorded',
                        'eventType' => $eventType,
                        'paymentID' => $paymentID,
                    ];

                // =====================================================
                // 🔄 members:pledge:update — Pledge amount/tier changed
                // =====================================================
                case 'members:pledge:update':
                    // An existing patron has changed their pledge amount or tier

                    ActivityLogger::log(
                        $userID,
                        'patreon_pledge_updated',
                        'payment',
                        'info',
                        'Patreon pledge updated for member ' . $fullName
                            . ': $' . number_format($pledgeAmount, 2),
                        [
                            'memberId'     => $memberId,
                            'pledgeAmount' => $pledgeAmount,
                            'patronStatus' => $patronStatus,
                            'tiers'        => $currentTiers,
                            'matchedUser'  => $userID,
                        ]
                    );

                    // 🔗 Dispatch webhook event for integrating services
                    if (class_exists('WebhookManager')) {
                        WebhookManager::dispatch('payment.patreon.pledge_updated', [
                            'userID'       => $userID,
                            'memberId'     => $memberId,
                            'fullName'     => $fullName,
                            'pledgeAmount' => $pledgeAmount,
                            'patronStatus' => $patronStatus,
                            'tiers'        => $currentTiers,
                        ]);
                    }

                    return [
                        'success'   => true,
                        'message'   => 'Pledge update recorded',
                        'eventType' => $eventType,
                    ];

                // =====================================================
                // ❌ members:pledge:delete — Pledge cancelled
                // =====================================================
                case 'members:pledge:delete':
                    // A patron has cancelled their pledge

                    ActivityLogger::log(
                        $userID,
                        'patreon_pledge_deleted',
                        'payment',
                        'warning',
                        'Patreon pledge cancelled by member ' . $fullName,
                        [
                            'memberId'     => $memberId,
                            'patronEmail'  => $patronEmail,
                            'patronStatus' => $patronStatus,
                            'matchedUser'  => $userID,
                        ]
                    );

                    // 🔗 Dispatch webhook event for integrating services
                    if (class_exists('WebhookManager')) {
                        WebhookManager::dispatch('payment.patreon.pledge_deleted', [
                            'userID'      => $userID,
                            'memberId'    => $memberId,
                            'fullName'    => $fullName,
                            'patronEmail' => $patronEmail,
                        ]);
                    }

                    return [
                        'success'   => true,
                        'message'   => 'Pledge cancellation recorded',
                        'eventType' => $eventType,
                    ];

                // =====================================================
                // 👤 members:create — New member joined campaign
                // =====================================================
                case 'members:create':
                    if ($logAllEvents) {
                        ActivityLogger::log(
                            $userID,
                            'patreon_member_created',
                            'payment',
                            'info',
                            'New Patreon member joined campaign: ' . $fullName . ' (' . $patronEmail . ')',
                            [
                                'memberId'     => $memberId,
                                'patronEmail'  => $patronEmail,
                                'fullName'     => $fullName,
                                'patronStatus' => $patronStatus,
                                'matchedUser'  => $userID,
                            ]
                        );
                    }

                    return [
                        'success'   => true,
                        'message'   => 'Member creation logged',
                        'eventType' => $eventType,
                    ];

                // =====================================================
                // 🔄 members:update — Member details updated
                // =====================================================
                case 'members:update':
                    if ($logAllEvents) {
                        ActivityLogger::log(
                            $userID,
                            'patreon_member_updated',
                            'payment',
                            'info',
                            'Patreon member updated: ' . $fullName,
                            [
                                'memberId'     => $memberId,
                                'patronEmail'  => $patronEmail,
                                'patronStatus' => $patronStatus,
                                'matchedUser'  => $userID,
                            ]
                        );
                    }

                    return [
                        'success'   => true,
                        'message'   => 'Member update logged',
                        'eventType' => $eventType,
                    ];

                // =====================================================
                // 🗑️ members:delete — Member removed from campaign
                // =====================================================
                case 'members:delete':
                    ActivityLogger::log(
                        $userID,
                        'patreon_member_deleted',
                        'payment',
                        'info',
                        'Patreon member removed from campaign: ' . $fullName . ' (' . $patronEmail . ')',
                        [
                            'memberId'     => $memberId,
                            'patronEmail'  => $patronEmail,
                            'fullName'     => $fullName,
                            'matchedUser'  => $userID,
                        ]
                    );

                    // 🔗 Dispatch webhook event for integrating services
                    if (class_exists('WebhookManager')) {
                        WebhookManager::dispatch('payment.patreon.member_deleted', [
                            'userID'      => $userID,
                            'memberId'    => $memberId,
                            'fullName'    => $fullName,
                            'patronEmail' => $patronEmail,
                        ]);
                    }

                    return [
                        'success'   => true,
                        'message'   => 'Member removal logged',
                        'eventType' => $eventType,
                    ];

                // =====================================================
                // ❓ Unrecognised event type
                // =====================================================
                default:
                    ActivityLogger::log(
                        $userID,
                        'patreon_webhook_unknown',
                        'payment',
                        'warning',
                        'Received unrecognised Patreon webhook event type: ' . $eventType,
                        [
                            'eventType' => $eventType,
                            'memberId'  => $memberId,
                            'fullName'  => $fullName,
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
                'patreon_webhook_exception',
                'payment',
                'error',
                'Exception processing Patreon webhook event: ' . $e->getMessage(),
                [
                    'eventType' => $eventType ?? 'unknown',
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]
            );

            return [
                'success'   => false,
                'message'   => 'Unexpected error processing webhook event',
                'eventType' => $eventType ?? 'unknown',
            ];
        }
    }

    // ========================================================================
    // 🎯 CAMPAIGN & MEMBER API METHODS
    // ========================================================================

    /**
     * 🏆 Fetch campaign tiers from the Patreon API
     *
     * Retrieves the list of tiers (reward levels) configured for the campaign
     * specified in the 'payment.patreon.campaign_id' setting. This is used
     * to display available Patreon tiers and map them to SIGNula subscription
     * tiers.
     *
     * Uses JSON:API include/fields sparse fieldsets to request only needed data.
     *
     * @return array Array of tier objects with id, title, amount, description
     *               or error array with 'error' key on failure
     *
     * @see https://docs.patreon.com/#get-api-oauth2-v2-campaigns-campaign_id
     *
     * @example
     * ```php
     * $tiers = PatreonProvider::getCampaignTiers();
     * if (!isset($tiers['error'])) {
     *     foreach ($tiers as $tier) {
     *         echo $tier['title'] . ': $' . number_format($tier['amount'], 2) . PHP_EOL;
     *     }
     * }
     * ```
     */
    public static function getCampaignTiers(): array
    {
        try {
            // 📥 Get the campaign ID from settings
            $campaignId = getSetting('payment.patreon.campaign_id');
            if (empty($campaignId)) {
                ActivityLogger::log(
                    null,
                    'patreon_config_error',
                    'payment',
                    'error',
                    'Patreon campaign_id is not configured in tblSettings',
                    []
                );
                return ['error' => 'Patreon campaign ID is not configured'];
            }

            // 🚀 Fetch campaign with included tiers using sparse fieldsets
            // @see https://docs.patreon.com/#get-api-oauth2-v2-campaigns-campaign_id
            $endpoint = '/campaigns/' . urlencode($campaignId)
                . '?include=tiers'
                . '&fields%5Btier%5D=title,amount_cents,description,published';

            $response = self::apiRequest('GET', $endpoint);

            // ❌ Check for API errors
            if (isset($response['error'])) {
                return $response;
            }

            // 📋 Parse the JSON:API included resources to extract tier data
            $tiers = [];
            $included = $response['included'] ?? [];

            foreach ($included as $resource) {
                if (($resource['type'] ?? '') === 'tier') {
                    $tierAttrs = $resource['attributes'] ?? [];
                    $amountCents = (int)($tierAttrs['amount_cents'] ?? 0);

                    $tiers[] = [
                        'id'          => $resource['id'] ?? '',
                        'title'       => $tierAttrs['title'] ?? '',
                        'amount'      => round($amountCents / 100, 2),
                        'description' => $tierAttrs['description'] ?? '',
                        'published'   => $tierAttrs['published'] ?? false,
                    ];
                }
            }

            // 📝 Log the successful tier retrieval
            ActivityLogger::log(
                null,
                'patreon_tiers_fetched',
                'payment',
                'info',
                'Patreon campaign tiers fetched: ' . count($tiers) . ' tiers found',
                [
                    'campaignId' => $campaignId,
                    'tierCount'  => count($tiers),
                ]
            );

            return $tiers;

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'patreon_tiers_exception',
                'payment',
                'error',
                'Exception fetching Patreon campaign tiers: ' . $e->getMessage(),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );
            return ['error' => 'Failed to fetch campaign tiers'];
        }
    }

    /**
     * 👤 Get a specific patron's pledge/membership details
     *
     * Retrieves the current pledge status and tier information for a
     * specific Patreon member by their member ID. This can be used to
     * verify a patron's active status and entitled tiers.
     *
     * @param string $patronId The Patreon member ID
     *
     * @return array|null Member details array or null if not found/error
     *
     * @see https://docs.patreon.com/#get-api-oauth2-v2-members-id
     *
     * @example
     * ```php
     * $pledge = PatreonProvider::getMemberPledge('12345678');
     * if ($pledge !== null) {
     *     echo 'Status: ' . $pledge['patron_status'] . PHP_EOL;
     *     echo 'Amount: $' . number_format($pledge['pledge_amount'], 2) . PHP_EOL;
     * }
     * ```
     */
    public static function getMemberPledge(string $patronId): ?array
    {
        try {
            // 🛡️ Validate the patron ID is not empty
            if (empty($patronId)) {
                return null;
            }

            // 🚀 Fetch member details with entitled tiers using sparse fieldsets
            // @see https://docs.patreon.com/#get-api-oauth2-v2-members-id
            $endpoint = '/members/' . urlencode($patronId)
                . '?include=currently_entitled_tiers'
                . '&fields%5Bmember%5D=full_name,email,patron_status,pledge_amount_cents';

            $response = self::apiRequest('GET', $endpoint);

            // ❌ Check for API errors
            if (isset($response['error'])) {
                ActivityLogger::log(
                    null,
                    'patreon_member_fetch_error',
                    'payment',
                    'warning',
                    'Failed to fetch Patreon member: ' . $patronId . ' - ' . ($response['error'] ?? ''),
                    ['patronId' => $patronId, 'error' => $response['error'] ?? '']
                );
                return null;
            }

            // 📋 Parse member attributes from JSON:API response
            $memberData = $response['data'] ?? [];
            $attributes = $memberData['attributes'] ?? [];
            $pledgeAmountCents = (int)($attributes['pledge_amount_cents'] ?? 0);

            // 🔍 Extract entitled tiers from included resources
            $entitledTiers = [];
            $included = $response['included'] ?? [];
            foreach ($included as $resource) {
                if (($resource['type'] ?? '') === 'tier') {
                    $entitledTiers[] = [
                        'id'    => $resource['id'] ?? '',
                        'title' => $resource['attributes']['title'] ?? '',
                    ];
                }
            }

            return [
                'memberId'     => $memberData['id'] ?? $patronId,
                'fullName'     => $attributes['full_name'] ?? '',
                'email'        => $attributes['email'] ?? '',
                'patronStatus' => $attributes['patron_status'] ?? '',
                'pledgeAmount' => round($pledgeAmountCents / 100, 2),
                'tiers'        => $entitledTiers,
            ];

        } catch (\Exception $e) {
            ActivityLogger::log(
                null,
                'patreon_member_exception',
                'payment',
                'error',
                'Exception fetching Patreon member pledge: ' . $e->getMessage(),
                [
                    'patronId' => $patronId,
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine(),
                ]
            );
            return null;
        }
    }

    // ========================================================================
    // 🧪 CONNECTION TESTING
    // ========================================================================

    /**
     * 🩺 Test the Patreon API connection
     *
     * Performs a lightweight API call (fetching campaign info) to verify that
     * the configured creator access token is valid and the Patreon API is
     * reachable. This is used by the admin settings UI to validate
     * configuration before saving.
     *
     * @return array ['success' => bool, 'message' => string, 'responseTime' => float]
     *
     * @example
     * ```php
     * $test = PatreonProvider::testConnection();
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
            // ⏱️ Record the start time to measure API response time
            $startTime = microtime(true);

            // 📥 Get the campaign ID from settings
            $campaignId = getSetting('payment.patreon.campaign_id');
            if (empty($campaignId)) {
                return [
                    'success'      => false,
                    'message'      => 'Patreon campaign ID is not configured',
                    'responseTime' => 0,
                ];
            }

            // 🚀 Attempt to fetch the campaign as a connectivity and auth test
            $response = self::apiRequest('GET', '/campaigns/' . urlencode($campaignId));

            // ⏱️ Calculate the response time in milliseconds
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            // 🔍 Check if the response indicates an error
            if (isset($response['error'])) {
                ActivityLogger::log(
                    null,
                    'patreon_connection_test_failed',
                    'payment',
                    'warning',
                    'Patreon connection test failed: ' . ($response['error'] ?? 'Unknown error'),
                    [
                        'responseTime' => $responseTime,
                        'httpCode'     => $response['httpCode'] ?? null,
                    ]
                );

                return [
                    'success'      => false,
                    'message'      => 'Connection test failed: ' . ($response['error'] ?? 'Unknown error'),
                    'responseTime' => $responseTime,
                ];
            }

            // ✅ Connection successful
            $campaignName = $response['data']['attributes']['creation_name'] ?? 'Unknown Campaign';

            ActivityLogger::log(
                null,
                'patreon_connection_test_success',
                'payment',
                'info',
                'Patreon connection test passed (' . $responseTime . 'ms) — Campaign: ' . $campaignName,
                [
                    'responseTime' => $responseTime,
                    'campaignId'   => $campaignId,
                    'campaignName' => $campaignName,
                ]
            );

            return [
                'success'      => true,
                'message'      => 'Successfully connected to Patreon API — Campaign: ' . $campaignName,
                'responseTime' => $responseTime,
            ];

        } catch (\Exception $e) {
            // 🚨 Unexpected exception during connection test
            ActivityLogger::log(
                null,
                'patreon_connection_test_exception',
                'payment',
                'error',
                'Exception during Patreon connection test: ' . $e->getMessage(),
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
}
