<?php
/**
 * ============================================================================
 * 🔐 SIGNula - LinkedIn OAuth Provider
 * ============================================================================
 *
 * Purpose: LinkedIn OAuth 2.0 authentication (Sign In with LinkedIn using OpenID Connect)
 * PHP Version: 8.3+
 *
 * Features:
 * - LinkedIn personal accounts
 * - OpenID Connect compliant
 * - Email verification status
 * - Profile information and avatar
 *
 * Documentation:
 * @see https://learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2
 * @see https://learn.microsoft.com/en-us/linkedin/shared/authentication/authorization-code-flow
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
 * 🔐 LinkedIn OAuth Provider
 *
 * Implements LinkedIn OAuth 2.0 authentication using the
 * Sign In with LinkedIn v2 (OpenID Connect) flow.
 */
class LinkedInOAuth extends OAuth
{
    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('linkedin');

        // 🔗 LinkedIn OAuth endpoints
        // @see https://learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2#authenticating-members
        $this->authorizationEndpoint = 'https://www.linkedin.com/oauth/v2/authorization';
        $this->tokenEndpoint = 'https://www.linkedin.com/oauth/v2/accessToken';
        $this->userInfoEndpoint = 'https://api.linkedin.com/v2/userinfo';

        // 📋 OAuth scopes
        // @see https://learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2#openid-connect-scopes
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
        return 'LinkedIn';
    }

    /**
     * 🔗 Get Authorization URL
     *
     * Generates LinkedIn authorization URL with additional parameters.
     *
     * @param array $additionalParams Additional parameters
     * @return string Authorization URL
     */
    public function getAuthorizationUrl(array $additionalParams = []): string
    {
        return parent::getAuthorizationUrl($additionalParams);
    }

    /**
     * 👤 Get User Info
     *
     * Retrieves user information from LinkedIn's OpenID Connect userinfo endpoint.
     *
     * @param string $accessToken Access token
     * @return array Normalized user data
     * @throws RuntimeException If request fails
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            // 🌐 Make request to LinkedIn's userinfo endpoint
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
            error_log('LinkedIn getUserInfo error: ' . $e->getMessage());
            throw new RuntimeException('Failed to retrieve LinkedIn user information');
        }
    }

    /**
     * 🔄 Normalize User Data
     *
     * Converts LinkedIn's OpenID Connect user data format to standard format.
     *
     * LinkedIn userinfo response format (OpenID Connect):
     * {
     *   "sub": "abc123def456",
     *   "email": "user@example.com",
     *   "email_verified": true,
     *   "name": "John Doe",
     *   "given_name": "John",
     *   "family_name": "Doe",
     *   "picture": "https://media.licdn.com/dms/image/...",
     *   "locale": "en-US"
     * }
     *
     * @param array $rawData Raw data from LinkedIn
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
            throw new RuntimeException('Invalid user data from LinkedIn');
        }

        return $normalized;
    }

    /**
     * 🔄 Revoke Access
     *
     * Revokes LinkedIn access token.
     * Uses LinkedIn's token revocation endpoint.
     *
     * @see https://learn.microsoft.com/en-us/linkedin/shared/authentication/token-introspection#revoking-a-token
     *
     * @param string $accessToken Access token to revoke
     * @return bool True if successful
     */
    public function revokeAccess(string $accessToken): bool
    {
        try {
            // 🌐 LinkedIn's token revocation endpoint
            $revokeEndpoint = 'https://www.linkedin.com/oauth/v2/revoke';

            // 📤 LinkedIn requires client_id and client_secret in the POST body
            $this->makeHttpRequest(
                $revokeEndpoint,
                'POST',
                [
                    'token' => $accessToken,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]
            );

            return true;

        } catch (Exception $e) {
            error_log('LinkedIn revoke access error: ' . $e->getMessage());
            return false;
        }
    }
}

// ✅ LinkedInOAuth class loaded successfully
