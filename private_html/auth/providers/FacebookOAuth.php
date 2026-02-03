<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Facebook OAuth Provider
 * ============================================================================
 *
 * Purpose: Facebook OAuth 2.0 authentication (Meta)
 * PHP Version: 8.3+
 *
 * Features:
 * - Facebook personal accounts
 * - Instagram integration (same OAuth flow)
 * - Email verification
 * - Profile information and avatar
 * - Long-lived access tokens
 *
 * Documentation:
 * @see https://developers.facebook.com/docs/facebook-login/web
 * @see https://developers.facebook.com/docs/graph-api/overview
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
 * 🔐 Facebook OAuth Provider
 *
 * Implements Facebook Login (Meta) OAuth 2.0 authentication.
 * Works with both Facebook and Instagram accounts.
 */
class FacebookOAuth extends OAuth
{
    /**
     * @var string Graph API version
     */
    protected string $apiVersion = 'v18.0';

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('facebook');

        // 🔗 Facebook OAuth endpoints
        $this->authorizationEndpoint = 'https://www.facebook.com/' . $this->apiVersion . '/dialog/oauth';
        $this->tokenEndpoint = 'https://graph.facebook.com/' . $this->apiVersion . '/oauth/access_token';
        $this->userInfoEndpoint = 'https://graph.facebook.com/' . $this->apiVersion . '/me';

        // 📋 OAuth scopes (permissions)
        // https://developers.facebook.com/docs/permissions/reference
        $this->scopes = [
            'email',           // Email address (requires app review for public apps)
            'public_profile',  // Basic public profile information
        ];
    }

    /**
     * 🎨 Get Provider Display Name
     *
     * @return string Display name
     */
    public function getDisplayName(): string
    {
        return 'Facebook';
    }

    /**
     * 🔗 Get Authorization URL
     *
     * Generates Facebook authorization URL with additional parameters.
     *
     * @param array $additionalParams Additional parameters
     * @return string Authorization URL
     */
    public function getAuthorizationUrl(array $additionalParams = []): string
    {
        // 🎨 Facebook-specific parameters
        $facebookParams = [
            'auth_type' => 'rerequest', // Force permission dialog even if previously declined
        ];

        return parent::getAuthorizationUrl(array_merge($facebookParams, $additionalParams));
    }

    /**
     * 👤 Get User Info
     *
     * Retrieves user information from Facebook Graph API.
     *
     * @param string $accessToken Access token
     * @return array Normalized user data
     * @throws RuntimeException If request fails
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            // 📋 Specify fields to retrieve
            // https://developers.facebook.com/docs/graph-api/reference/user
            $fields = [
                'id',              // Facebook user ID
                'email',           // Email address (requires email permission)
                'name',            // Full name
                'first_name',      // First name
                'last_name',       // Last name
                'picture.type(large)', // Profile picture (large size)
                'locale',          // User's locale
                'verified',        // Account verification status
            ];

            // 🌐 Make request to Graph API
            $rawData = $this->makeHttpRequest(
                $this->userInfoEndpoint,
                'GET',
                [
                    'fields' => implode(',', $fields),
                    'access_token' => $accessToken
                ]
            );

            // 🔄 Normalize data
            return $this->normalizeUserData($rawData);

        } catch (Exception $e) {
            error_log('Facebook getUserInfo error: ' . $e->getMessage());
            throw new RuntimeException('Failed to retrieve Facebook user information');
        }
    }

    /**
     * 🔄 Normalize User Data
     *
     * Converts Facebook's user data format to standard format.
     *
     * Facebook Graph API /me response format:
     * {
     *   "id": "123456789012345",
     *   "email": "user@example.com",
     *   "name": "John Doe",
     *   "first_name": "John",
     *   "last_name": "Doe",
     *   "picture": {
     *     "data": {
     *       "url": "https://platform-lookaside.fbsbx.com/...",
     *       "is_silhouette": false
     *     }
     *   },
     *   "locale": "en_US",
     *   "verified": true
     * }
     *
     * @param array $rawData Raw data from Facebook
     * @return array Normalized user data
     */
    protected function normalizeUserData(array $rawData): array
    {
        // 🖼️ Extract picture URL from nested structure
        $avatar = null;
        if (!empty($rawData['picture']['data']['url'])) {
            // Only use picture if it's not a silhouette (default avatar)
            if (empty($rawData['picture']['data']['is_silhouette'])) {
                $avatar = $rawData['picture']['data']['url'];
            }
        }

        // 🔍 Extract data with fallbacks
        $normalized = [
            'provider_id' => $rawData['id'] ?? '',
            'email' => $rawData['email'] ?? '',
            'email_verified' => !empty($rawData['email']), // Facebook emails are considered verified
            'name' => $rawData['name'] ?? '',
            'first_name' => $rawData['first_name'] ?? '',
            'last_name' => $rawData['last_name'] ?? '',
            'avatar' => $avatar,
            'locale' => $rawData['locale'] ?? null,
            'verified' => $rawData['verified'] ?? false, // Account verification badge
        ];

        // ✅ Validate required fields
        if (empty($normalized['provider_id'])) {
            throw new RuntimeException('Invalid user data from Facebook');
        }

        // ⚠️ Email might not be available if user didn't grant permission
        if (empty($normalized['email'])) {
            error_log('Facebook login: Email not provided (permission not granted or email not available)');
            // We'll handle this in the callback handler - might need to ask for email separately
        }

        return $normalized;
    }

    /**
     * 🔄 Exchange Short-Lived Token for Long-Lived Token
     *
     * Facebook access tokens are short-lived (1-2 hours).
     * This exchanges them for long-lived tokens (60 days).
     *
     * @param string $shortLivedToken Short-lived access token
     * @return array Token response with long-lived token
     * @throws RuntimeException If exchange fails
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        try {
            // 📝 Prepare exchange request
            $params = [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'fb_exchange_token' => $shortLivedToken
            ];

            // 🌐 Make exchange request
            $response = $this->makeHttpRequest(
                $this->tokenEndpoint,
                'GET',
                $params
            );

            return $response;

        } catch (Exception $e) {
            error_log('Facebook long-lived token exchange error: ' . $e->getMessage());
            throw new RuntimeException('Failed to exchange for long-lived token');
        }
    }

    /**
     * ✅ Debug Access Token
     *
     * Retrieves information about an access token (for debugging).
     * Useful for checking token validity, expiration, scopes, etc.
     *
     * @param string $accessToken Access token to debug
     * @return array Token information
     */
    public function debugToken(string $accessToken): array
    {
        try {
            // 🔍 Facebook's debug token endpoint
            $debugEndpoint = 'https://graph.facebook.com/' . $this->apiVersion . '/debug_token';

            // 🔑 Need app access token (app_id|app_secret)
            $appAccessToken = $this->clientId . '|' . $this->clientSecret;

            $response = $this->makeHttpRequest(
                $debugEndpoint,
                'GET',
                [
                    'input_token' => $accessToken,
                    'access_token' => $appAccessToken
                ]
            );

            return $response['data'] ?? [];

        } catch (Exception $e) {
            error_log('Facebook debug token error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 🔄 Revoke Access
     *
     * Revokes Facebook access token and permissions.
     *
     * @param string $accessToken Access token to revoke
     * @return bool True if successful
     */
    public function revokeAccess(string $accessToken): bool
    {
        try {
            // 🌐 Facebook's permissions delete endpoint
            $revokeEndpoint = 'https://graph.facebook.com/' . $this->apiVersion . '/me/permissions';

            $this->makeHttpRequest(
                $revokeEndpoint,
                'POST', // DELETE method via POST
                [
                    'access_token' => $accessToken,
                    'method' => 'delete' // Facebook uses method parameter for DELETE
                ]
            );

            return true;

        } catch (Exception $e) {
            error_log('Facebook revoke access error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 📧 Request Email Permission
     *
     * If email wasn't provided initially, this can be used to re-request
     * the email permission from the user.
     *
     * @return string Authorization URL requesting email permission
     */
    public function getEmailPermissionUrl(): string
    {
        return $this->getAuthorizationUrl([
            'auth_type' => 'rerequest',
            'scope' => 'email' // Explicitly request email
        ]);
    }

    /**
     * 📸 Get User's Instagram Accounts
     *
     * Retrieves Instagram accounts connected to Facebook account.
     * Requires 'instagram_basic' permission.
     *
     * @param string $accessToken Access token
     * @return array Instagram accounts
     */
    public function getInstagramAccounts(string $accessToken): array
    {
        try {
            $endpoint = 'https://graph.facebook.com/' . $this->apiVersion . '/me/accounts';

            $response = $this->makeHttpRequest(
                $endpoint,
                'GET',
                [
                    'fields' => 'instagram_business_account',
                    'access_token' => $accessToken
                ]
            );

            return $response['data'] ?? [];

        } catch (Exception $e) {
            error_log('Facebook get Instagram accounts error: ' . $e->getMessage());
            return [];
        }
    }
}

// ✅ FacebookOAuth class loaded successfully
