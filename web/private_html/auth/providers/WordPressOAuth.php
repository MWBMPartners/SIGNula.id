<?php
/**
 * ============================================================================
 * 🔐 SIGNula - WordPress OAuth Provider
 * ============================================================================
 *
 * Purpose: WordPress.com OAuth 2.0 authentication
 * PHP Version: 8.3+
 *
 * Features:
 * - WordPress.com accounts
 * - Profile information and avatar
 * - Email verification status
 * - Blog URL and username
 *
 * Documentation:
 * @see https://developer.wordpress.com/docs/oauth2/
 * @see https://developer.wordpress.com/docs/api/
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
 * 🔐 WordPress OAuth Provider
 *
 * Implements WordPress.com OAuth 2.0 authentication.
 * Uses the WordPress.com REST API for user profile information.
 * Note: WordPress.com OAuth does not require specific scopes — granting
 * access provides full read access to the authenticated user's profile.
 */
class WordPressOAuth extends OAuth
{
    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('wordpress');

        // 🔗 WordPress.com OAuth endpoints
        // @see https://developer.wordpress.com/docs/oauth2/
        $this->authorizationEndpoint = 'https://public-api.wordpress.com/oauth2/authorize';
        $this->tokenEndpoint = 'https://public-api.wordpress.com/oauth2/token';
        $this->userInfoEndpoint = 'https://public-api.wordpress.com/rest/v1.1/me';

        // 📋 OAuth scopes
        // WordPress.com OAuth does not require specific scopes;
        // access grants full read permissions for the user's profile
        $this->scopes = [];
    }

    /**
     * 🎨 Get Provider Display Name
     *
     * @return string Display name
     */
    public function getDisplayName(): string
    {
        return 'WordPress';
    }

    /**
     * 🔗 Get Authorization URL
     *
     * Generates WordPress.com authorization URL with additional parameters.
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
     * Retrieves user information from WordPress.com's REST API /me endpoint.
     *
     * @param string $accessToken Access token
     * @return array Normalized user data
     * @throws RuntimeException If request fails
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            // 🌐 Make request to WordPress.com's /me endpoint
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
            error_log('WordPress getUserInfo error: ' . $e->getMessage());
            throw new RuntimeException('Failed to retrieve WordPress user information');
        }
    }

    /**
     * 🔄 Normalize User Data
     *
     * Converts WordPress.com's user data format to standard format.
     *
     * WordPress.com /me response format:
     * {
     *   "ID": 12345678,
     *   "display_name": "John Doe",
     *   "username": "johndoe",
     *   "email": "user@example.com",
     *   "email_verified": true,
     *   "primary_blog": 98765432,
     *   "primary_blog_url": "https://johndoe.wordpress.com",
     *   "language": "en",
     *   "locale_variant": "en_US",
     *   "avatar_URL": "https://1.gravatar.com/avatar/...",
     *   "profile_URL": "https://gravatar.com/johndoe",
     *   "date": "2020-01-15T10:30:00+00:00"
     * }
     *
     * @param array $rawData Raw data from WordPress.com
     * @return array Normalized user data
     * @throws RuntimeException If required fields are missing
     */
    protected function normalizeUserData(array $rawData): array
    {
        // 🧩 WordPress provides display_name but not separate first/last names
        // Attempt to split display_name for first/last name fields
        $displayName = $rawData['display_name'] ?? '';
        $nameParts = $this->splitName($displayName);

        // 🔍 Extract data with fallbacks
        $normalized = [
            'provider_id' => isset($rawData['ID']) ? (string) $rawData['ID'] : '',
            'email' => $rawData['email'] ?? '',
            'email_verified' => $rawData['email_verified'] ?? false,
            'name' => $displayName,
            'first_name' => $nameParts['first_name'],
            'last_name' => $nameParts['last_name'],
            'avatar' => $rawData['avatar_URL'] ?? null,
            'username' => $rawData['username'] ?? null,
            'primary_blog_url' => $rawData['primary_blog_url'] ?? null,
            'profile_url' => $rawData['profile_URL'] ?? null,
            'language' => $rawData['language'] ?? null,
        ];

        // ✅ Validate required fields
        if (empty($normalized['provider_id']) || empty($normalized['email'])) {
            throw new RuntimeException('Invalid user data from WordPress');
        }

        return $normalized;
    }

    /**
     * ✂️ Split Display Name into First and Last Name
     *
     * Splits a display name string into first name and last name components.
     * Handles single names, multi-part names, and empty strings.
     *
     * @param string $displayName The display name to split
     * @return array Associative array with 'first_name' and 'last_name' keys
     */
    private function splitName(string $displayName): array
    {
        // 🧹 Trim and handle empty names
        $displayName = trim($displayName);

        if (empty($displayName)) {
            return [
                'first_name' => '',
                'last_name' => '',
            ];
        }

        // ✂️ Split on whitespace — first token is first name, rest is last name
        $parts = preg_split('/\s+/', $displayName, 2);

        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
        ];
    }

    /**
     * 🔄 Revoke Access
     *
     * WordPress.com does not provide a standard token revocation endpoint.
     * Users must manually revoke access via their WordPress.com account settings.
     *
     * @see https://wordpress.com/me/security/connected-applications
     *
     * @param string $accessToken Access token to revoke
     * @return bool Always returns false as WordPress.com does not support programmatic revocation
     */
    public function revokeAccess(string $accessToken): bool
    {
        // ⚠️ WordPress.com does not provide a standard token revocation endpoint
        // Users must manually revoke access at: https://wordpress.com/me/security/connected-applications
        error_log('WordPress OAuth: Programmatic token revocation is not supported. User must revoke via WordPress.com account settings.');
        return false;
    }
}

// ✅ WordPressOAuth class loaded successfully
