<?php
/**
 * ============================================================================
 * 🔐 SIGNula - PayPal OAuth Provider
 * ============================================================================
 *
 * Purpose: PayPal OAuth 2.0 authentication (Log In with PayPal)
 * PHP Version: 8.3+
 *
 * Features:
 * - PayPal personal accounts
 * - OpenID Connect compliant
 * - Email verification status
 * - Profile information and avatar
 * - Sandbox mode support for development/testing
 * - Basic auth for token exchange (PayPal-specific requirement)
 *
 * Documentation:
 * @see https://developer.paypal.com/docs/log-in-with-paypal/
 * @see https://developer.paypal.com/docs/log-in-with-paypal/integrate/
 *
 * @package    SIGNula
 * @subpackage Authentication
 * @version    1.0.0
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 📚 Require OAuth base class
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'OAuth.php';

/**
 * 🔐 PayPal OAuth Provider
 *
 * Implements PayPal OAuth 2.0 authentication using Log In with PayPal (OpenID Connect).
 * Supports both production and sandbox environments via the 'paypal.sandbox' setting.
 * Note: PayPal requires HTTP Basic Authentication for the token exchange.
 */
class PayPalOAuth extends OAuth
{
    /**
     * 🏗️ Flag indicating whether sandbox mode is active
     *
     * @var bool
     */
    private bool $isSandbox;

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('paypal');

        // 🧪 Check if sandbox mode is enabled
        // @see https://developer.paypal.com/docs/log-in-with-paypal/integrate/#3-get-an-access-token
        $this->isSandbox = (bool) getSetting('paypal.sandbox', false);

        // 🌐 Determine base URLs based on environment
        $authBase = $this->isSandbox
            ? 'https://www.sandbox.paypal.com'    // 🧪 Sandbox environment
            : 'https://www.paypal.com';           // 🚀 Production environment

        $apiBase = $this->isSandbox
            ? 'https://api-m.sandbox.paypal.com'  // 🧪 Sandbox API
            : 'https://api-m.paypal.com';         // 🚀 Production API

        // 🔗 PayPal OAuth endpoints
        $this->authorizationEndpoint = $authBase . '/signin/authorize';
        $this->tokenEndpoint = $apiBase . '/v1/oauth2/token';
        $this->userInfoEndpoint = $apiBase . '/v1/identity/openidconnect/userinfo?schema=openid';

        // 📋 OAuth scopes
        // @see https://developer.paypal.com/docs/log-in-with-paypal/integrate/#step-1-get-set-up
        $this->scopes = [
            'openid',   // Required for OpenID Connect
            'profile',  // Basic profile information
            'email',    // Email address
        ];
    }

    /**
     * 🎨 Get Provider Display Name
     *
     * @return string Display name
     */
    public function getDisplayName(): string
    {
        return 'PayPal';
    }

    /**
     * 🧪 Check if Sandbox Mode is Active
     *
     * @return bool True if sandbox mode is enabled
     */
    public function isSandboxMode(): bool
    {
        return $this->isSandbox;
    }

    /**
     * 🔗 Get Authorization URL
     *
     * Generates PayPal authorization URL with additional parameters.
     *
     * @param array $additionalParams Additional parameters
     * @return string Authorization URL
     */
    public function getAuthorizationUrl(array $additionalParams = []): string
    {
        return parent::getAuthorizationUrl($additionalParams);
    }

    /**
     * 🔑 Exchange Authorization Code for Token
     *
     * Overrides the parent method because PayPal requires HTTP Basic Authentication
     * (Base64-encoded client_id:client_secret) in the Authorization header
     * for the token exchange request, rather than sending credentials in the POST body.
     *
     * @see https://developer.paypal.com/docs/log-in-with-paypal/integrate/#3-get-an-access-token
     *
     * @param string $code Authorization code
     * @return array Token response containing access_token, refresh_token, etc.
     * @throws RuntimeException If token exchange fails
     */
    public function exchangeCodeForToken(string $code): array
    {
        try {
            // 🔐 Build Basic Auth header (PayPal-specific requirement)
            // PayPal expects client credentials as HTTP Basic Authentication
            $basicAuth = base64_encode($this->clientId . ':' . $this->clientSecret);

            // 📋 Token request headers with Basic Authentication
            $headers = [
                'Authorization: Basic ' . $basicAuth,
                'Content-Type: application/x-www-form-urlencoded',
            ];

            // 📤 Token request body (no client_id/client_secret in body)
            $postData = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri,
            ];

            // 🌐 Make request to PayPal's token endpoint
            $response = $this->makeHttpRequest(
                $this->tokenEndpoint,
                'POST',
                $postData,
                $headers
            );

            // ✅ Validate response contains access token
            if (empty($response['access_token'])) {
                throw new RuntimeException('PayPal token response missing access_token');
            }

            return $response;

        } catch (Exception $e) {
            error_log('PayPal exchangeCodeForToken error: ' . $e->getMessage());
            throw new RuntimeException('Failed to exchange PayPal authorization code for token');
        }
    }

    /**
     * 👤 Get User Info
     *
     * Retrieves user information from PayPal's OpenID Connect userinfo endpoint.
     *
     * @param string $accessToken Access token
     * @return array Normalized user data
     * @throws RuntimeException If request fails
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            // 🌐 Make request to PayPal's userinfo endpoint
            $headers = [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ];

            $rawData = $this->makeHttpRequest(
                $this->userInfoEndpoint,
                'GET',
                [],
                $headers
            );

            // 🔄 Normalize data
            return $this->normalizeUserData($rawData);

        } catch (Exception $e) {
            error_log('PayPal getUserInfo error: ' . $e->getMessage());
            throw new RuntimeException('Failed to retrieve PayPal user information');
        }
    }

    /**
     * 🔄 Normalize User Data
     *
     * Converts PayPal's OpenID Connect user data format to standard format.
     *
     * PayPal userinfo response format (OpenID Connect):
     * {
     *   "user_id": "https://www.paypal.com/webapps/auth/identity/user/ABCDEF123456",
     *   "sub": "ABCDEF123456",
     *   "email": "user@example.com",
     *   "email_verified": true,
     *   "name": "John Doe",
     *   "given_name": "John",
     *   "family_name": "Doe",
     *   "picture": "https://www.paypalobjects.com/...",
     *   "payer_id": "PAYERID123"
     * }
     *
     * @param array $rawData Raw data from PayPal
     * @return array Normalized user data
     * @throws RuntimeException If required fields are missing
     */
    protected function normalizeUserData(array $rawData): array
    {
        // 🔍 Extract provider ID — prefer 'sub', fallback to 'user_id'
        $providerId = $rawData['sub'] ?? $rawData['user_id'] ?? '';

        // 🔍 Extract data with fallbacks
        $normalized = [
            'provider_id' => $providerId,
            'email' => $rawData['email'] ?? '',
            'email_verified' => $rawData['email_verified'] ?? false,
            'name' => $rawData['name'] ?? '',
            'first_name' => $rawData['given_name'] ?? '',
            'last_name' => $rawData['family_name'] ?? '',
            'avatar' => $rawData['picture'] ?? null,
            'payer_id' => $rawData['payer_id'] ?? null,
        ];

        // ✅ Validate required fields
        if (empty($normalized['provider_id']) || empty($normalized['email'])) {
            throw new RuntimeException('Invalid user data from PayPal');
        }

        return $normalized;
    }

    /**
     * 🔄 Revoke Access
     *
     * PayPal does not provide a standard token revocation endpoint
     * for Log In with PayPal tokens. Users must manage connected
     * applications via their PayPal account settings.
     *
     * @see https://www.paypal.com/myaccount/privacy/permissions
     *
     * @param string $accessToken Access token to revoke
     * @return bool Always returns false as PayPal does not support programmatic revocation
     */
    public function revokeAccess(string $accessToken): bool
    {
        // ⚠️ PayPal does not provide a standard token revocation endpoint for LIPP
        // Users must manually revoke access at: https://www.paypal.com/myaccount/privacy/permissions
        error_log('PayPal OAuth: Programmatic token revocation is not supported. User must revoke via PayPal account settings.');
        return false;
    }
}

// ✅ PayPalOAuth class loaded successfully
