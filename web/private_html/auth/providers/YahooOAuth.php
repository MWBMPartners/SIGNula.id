<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Yahoo OAuth Provider
 * ============================================================================
 *
 * Purpose: Yahoo OAuth 2.0 authentication (Sign In with Yahoo)
 * PHP Version: 8.3+
 *
 * Features:
 * - Yahoo personal accounts
 * - OpenID Connect compliant
 * - Email verification status
 * - Profile information and avatar
 * - Basic auth for token exchange (Yahoo-specific requirement)
 *
 * Documentation:
 * @see https://developer.yahoo.com/sign-in-with-yahoo/
 * @see https://developer.yahoo.com/oauth2/guide/
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
 * 🔐 Yahoo OAuth Provider
 *
 * Implements Yahoo OAuth 2.0 authentication using OpenID Connect.
 * Note: Yahoo requires HTTP Basic Authentication for the token exchange,
 * which is handled via an override of exchangeCodeForToken().
 */
class YahooOAuth extends OAuth
{
    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('yahoo');

        // 🔗 Yahoo OAuth endpoints
        // @see https://developer.yahoo.com/oauth2/guide/flows_authcode/
        $this->authorizationEndpoint = 'https://api.login.yahoo.com/oauth2/request_auth';
        $this->tokenEndpoint = 'https://api.login.yahoo.com/oauth2/get_token';
        $this->userInfoEndpoint = 'https://api.login.yahoo.com/openid/v1/userinfo';

        // 📋 OAuth scopes
        // @see https://developer.yahoo.com/oauth2/guide/yahoo_scopes/
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
        return 'Yahoo';
    }

    /**
     * 🔗 Get Authorization URL
     *
     * Generates Yahoo authorization URL with additional parameters.
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
     * Overrides the parent method because Yahoo requires HTTP Basic Authentication
     * (Base64-encoded client_id:client_secret) in the Authorization header
     * for the token exchange request, rather than sending credentials in the POST body.
     *
     * @see https://developer.yahoo.com/oauth2/guide/flows_authcode/#step-4-exchange-authorization-code-for-access-token
     *
     * @param string $code Authorization code
     * @return array Token response containing access_token, refresh_token, etc.
     * @throws RuntimeException If token exchange fails
     */
    public function exchangeCodeForToken(string $code): array
    {
        try {
            // 🔐 Build Basic Auth header (Yahoo-specific requirement)
            // Yahoo expects client credentials as HTTP Basic Authentication
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

            // 🌐 Make request to Yahoo's token endpoint
            $response = $this->makeHttpRequest(
                $this->tokenEndpoint,
                'POST',
                $postData,
                $headers
            );

            // ✅ Validate response contains access token
            if (empty($response['access_token'])) {
                throw new RuntimeException('Yahoo token response missing access_token');
            }

            return $response;

        } catch (Exception $e) {
            error_log('Yahoo exchangeCodeForToken error: ' . $e->getMessage());
            throw new RuntimeException('Failed to exchange Yahoo authorization code for token');
        }
    }

    /**
     * 👤 Get User Info
     *
     * Retrieves user information from Yahoo's OpenID Connect userinfo endpoint.
     *
     * @param string $accessToken Access token
     * @return array Normalized user data
     * @throws RuntimeException If request fails
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            // 🌐 Make request to Yahoo's userinfo endpoint
            $headers = [
                'Authorization: Bearer ' . $accessToken
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
            error_log('Yahoo getUserInfo error: ' . $e->getMessage());
            throw new RuntimeException('Failed to retrieve Yahoo user information');
        }
    }

    /**
     * 🔄 Normalize User Data
     *
     * Converts Yahoo's OpenID Connect user data format to standard format.
     *
     * Yahoo userinfo response format (OpenID Connect):
     * {
     *   "sub": "ABCDEF123456",
     *   "email": "user@yahoo.com",
     *   "email_verified": true,
     *   "name": "John Doe",
     *   "given_name": "John",
     *   "family_name": "Doe",
     *   "picture": "https://s.yimg.com/...",
     *   "locale": "en-US"
     * }
     *
     * @param array $rawData Raw data from Yahoo
     * @return array Normalized user data
     * @throws RuntimeException If required fields are missing
     */
    protected function normalizeUserData(array $rawData): array
    {
        // 🔍 Extract data with fallbacks
        $normalized = [
            'provider_id' => $rawData['sub'] ?? '',
            'email' => $rawData['email'] ?? '',
            'email_verified' => $rawData['email_verified'] ?? false,
            'name' => $rawData['name'] ?? '',
            'first_name' => $rawData['given_name'] ?? '',
            'last_name' => $rawData['family_name'] ?? '',
            'avatar' => $rawData['picture'] ?? null,
            'locale' => $rawData['locale'] ?? null,
        ];

        // ✅ Validate required fields
        if (empty($normalized['provider_id']) || empty($normalized['email'])) {
            throw new RuntimeException('Invalid user data from Yahoo');
        }

        return $normalized;
    }

    /**
     * 🔄 Revoke Access
     *
     * Yahoo does not provide a standard token revocation endpoint.
     * Users must manually revoke access via their Yahoo account settings.
     *
     * @see https://help.yahoo.com/kb/SLN2091.html
     *
     * @param string $accessToken Access token to revoke
     * @return bool Always returns false as Yahoo does not support programmatic revocation
     */
    public function revokeAccess(string $accessToken): bool
    {
        // ⚠️ Yahoo does not provide a standard token revocation endpoint
        // Users must manually revoke access at: https://login.yahoo.com/account/security
        error_log('Yahoo OAuth: Programmatic token revocation is not supported. User must revoke via Yahoo account settings.');
        return false;
    }
}

// ✅ YahooOAuth class loaded successfully
