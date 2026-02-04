<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Google OAuth Provider
 * ============================================================================
 *
 * Purpose: Google OAuth 2.0 authentication
 * PHP Version: 8.3+
 *
 * Features:
 * - Google Account (Personal)
 * - Google Workspace accounts
 * - Email verification status
 * - Profile information and avatar
 *
 * Documentation:
 * @see https://developers.google.com/identity/protocols/oauth2
 * @see https://developers.google.com/identity/protocols/oauth2/web-server
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
 * 🔐 Google OAuth Provider
 *
 * Implements Google OAuth 2.0 authentication for both personal
 * Google accounts and Google Workspace accounts.
 */
class GoogleOAuth extends OAuth
{
    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('google');

        // 🔗 Google OAuth endpoints
        $this->authorizationEndpoint = 'https://accounts.google.com/o/oauth2/v2/auth';
        $this->tokenEndpoint = 'https://oauth2.googleapis.com/token';
        $this->userInfoEndpoint = 'https://www.googleapis.com/oauth2/v2/userinfo';

        // 📋 OAuth scopes
        // https://developers.google.com/identity/protocols/oauth2/scopes
        $this->scopes = [
            'openid',                                    // Required for OpenID Connect
            'https://www.googleapis.com/auth/userinfo.email',    // Email address
            'https://www.googleapis.com/auth/userinfo.profile',  // Basic profile info
        ];
    }

    /**
     * 🎨 Get Provider Display Name
     *
     * @return string Display name
     */
    public function getDisplayName(): string
    {
        return 'Google';
    }

    /**
     * 🔗 Get Authorization URL
     *
     * Generates Google authorization URL with additional parameters.
     *
     * @param array $additionalParams Additional parameters
     * @return string Authorization URL
     */
    public function getAuthorizationUrl(array $additionalParams = []): string
    {
        // 🎨 Google-specific parameters
        $googleParams = [
            'access_type' => 'offline',  // Request refresh token
            'prompt' => 'consent',       // Force consent screen to get refresh token
        ];

        return parent::getAuthorizationUrl(array_merge($googleParams, $additionalParams));
    }

    /**
     * 👤 Get User Info
     *
     * Retrieves user information from Google.
     *
     * @param string $accessToken Access token
     * @return array Normalized user data
     * @throws RuntimeException If request fails
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            // 🌐 Make request to Google's userinfo endpoint
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
            error_log('Google getUserInfo error: ' . $e->getMessage());
            throw new RuntimeException('Failed to retrieve Google user information');
        }
    }

    /**
     * 🔄 Normalize User Data
     *
     * Converts Google's user data format to standard format.
     *
     * Google userinfo response format:
     * {
     *   "id": "123456789",
     *   "email": "user@example.com",
     *   "verified_email": true,
     *   "name": "John Doe",
     *   "given_name": "John",
     *   "family_name": "Doe",
     *   "picture": "https://lh3.googleusercontent.com/...",
     *   "locale": "en",
     *   "hd": "example.com"  // Hosted domain (for Workspace accounts)
     * }
     *
     * @param array $rawData Raw data from Google
     * @return array Normalized user data
     */
    protected function normalizeUserData(array $rawData): array
    {
        // 🔍 Extract data with fallbacks
        $normalized = [
            'provider_id' => $rawData['id'] ?? $rawData['sub'] ?? '',
            'email' => $rawData['email'] ?? '',
            'email_verified' => $rawData['verified_email'] ?? $rawData['email_verified'] ?? false,
            'name' => $rawData['name'] ?? '',
            'first_name' => $rawData['given_name'] ?? '',
            'last_name' => $rawData['family_name'] ?? '',
            'avatar' => $rawData['picture'] ?? null,
            'locale' => $rawData['locale'] ?? null,
            'hosted_domain' => $rawData['hd'] ?? null, // Google Workspace domain
        ];

        // 🏢 Add Workspace indicator
        $normalized['is_workspace'] = !empty($normalized['hosted_domain']);

        // ✅ Validate required fields
        if (empty($normalized['provider_id']) || empty($normalized['email'])) {
            throw new RuntimeException('Invalid user data from Google');
        }

        return $normalized;
    }

    /**
     * 🔍 Check if Email Domain is Allowed
     *
     * Checks if user's email domain is allowed for authentication.
     * Useful for restricting to specific Workspace domains.
     *
     * @param string $email Email address
     * @param array $allowedDomains Allowed domains (empty = all allowed)
     * @return bool True if allowed
     */
    public function isEmailDomainAllowed(string $email, array $allowedDomains = []): bool
    {
        // 🌍 If no restrictions, allow all
        if (empty($allowedDomains)) {
            return true;
        }

        // 📧 Extract domain from email
        $emailParts = explode('@', $email);
        if (count($emailParts) !== 2) {
            return false;
        }

        $domain = strtolower($emailParts[1]);

        // ✅ Check against allowed domains
        return in_array($domain, array_map('strtolower', $allowedDomains), true);
    }

    /**
     * 🔄 Revoke Access
     *
     * Revokes Google access token.
     * This is optional but recommended for security.
     *
     * @param string $accessToken Access token to revoke
     * @return bool True if successful
     */
    public function revokeAccess(string $accessToken): bool
    {
        try {
            // 🌐 Google's token revocation endpoint
            $revokeEndpoint = 'https://oauth2.googleapis.com/revoke';

            $this->makeHttpRequest(
                $revokeEndpoint,
                'POST',
                ['token' => $accessToken]
            );

            return true;

        } catch (Exception $e) {
            error_log('Google revoke access error: ' . $e->getMessage());
            return false;
        }
    }
}

// ✅ GoogleOAuth class loaded successfully
