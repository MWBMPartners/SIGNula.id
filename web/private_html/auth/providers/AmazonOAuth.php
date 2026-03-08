<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Amazon OAuth Provider
 * ============================================================================
 *
 * Purpose: Amazon OAuth 2.0 authentication (Login with Amazon)
 * PHP Version: 8.3+
 *
 * Features:
 * - Amazon personal accounts
 * - Profile information (name, email, user_id)
 * - Name splitting into first/last name
 *
 * Documentation:
 * @see https://developer.amazon.com/docs/login-with-amazon/web-docs.html
 * @see https://developer.amazon.com/docs/login-with-amazon/authorization-code-grant.html
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
 * 🔐 Amazon OAuth Provider
 *
 * Implements Login with Amazon OAuth 2.0 authentication.
 * Note: Amazon does not provide avatar URLs or email verification status
 * in its standard profile response.
 */
class AmazonOAuth extends OAuth
{
    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('amazon');

        // 🔗 Amazon OAuth endpoints
        // @see https://developer.amazon.com/docs/login-with-amazon/authorization-code-grant.html
        $this->authorizationEndpoint = 'https://www.amazon.com/ap/oa';
        $this->tokenEndpoint = 'https://api.amazon.com/auth/o2/token';
        $this->userInfoEndpoint = 'https://api.amazon.com/user/profile';

        // 📋 OAuth scopes
        // @see https://developer.amazon.com/docs/login-with-amazon/customer-profile.html
        $this->scopes = [
            'profile',          // Basic profile information (name, email)
            'profile:user_id',  // Amazon user ID
        ];
    }

    /**
     * 🎨 Get Provider Display Name
     *
     * @return string Display name
     */
    public function getDisplayName(): string
    {
        return 'Amazon';
    }

    /**
     * 🔗 Get Authorization URL
     *
     * Generates Amazon authorization URL with additional parameters.
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
     * Retrieves user information from Amazon's profile endpoint.
     *
     * @param string $accessToken Access token
     * @return array Normalized user data
     * @throws RuntimeException If request fails
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            // 🌐 Make request to Amazon's user profile endpoint
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
            error_log('Amazon getUserInfo error: ' . $e->getMessage());
            throw new RuntimeException('Failed to retrieve Amazon user information');
        }
    }

    /**
     * 🔄 Normalize User Data
     *
     * Converts Amazon's user data format to standard format.
     * Note: Amazon's profile response does not include an avatar URL,
     * so the avatar field will always be null.
     *
     * Amazon profile response format:
     * {
     *   "user_id": "amzn1.account.ABCDEF123456",
     *   "email": "user@example.com",
     *   "name": "John Doe",
     *   "postal_code": "12345"
     * }
     *
     * @param array $rawData Raw data from Amazon
     * @return array Normalized user data
     * @throws RuntimeException If required fields are missing
     */
    protected function normalizeUserData(array $rawData): array
    {
        // 🧩 Split full name into first and last name
        $fullName = $rawData['name'] ?? '';
        $nameParts = $this->splitName($fullName);

        // 🔍 Extract data with fallbacks
        $normalized = [
            'provider_id' => $rawData['user_id'] ?? '',
            'email' => $rawData['email'] ?? '',
            'email_verified' => null,  // ⚠️ Amazon does not provide email verification status
            'name' => $fullName,
            'first_name' => $nameParts['first_name'],
            'last_name' => $nameParts['last_name'],
            'avatar' => null,          // ⚠️ Amazon does not provide avatar URLs
            'postal_code' => $rawData['postal_code'] ?? null,
        ];

        // ✅ Validate required fields
        if (empty($normalized['provider_id']) || empty($normalized['email'])) {
            throw new RuntimeException('Invalid user data from Amazon');
        }

        return $normalized;
    }

    /**
     * ✂️ Split Full Name into First and Last Name
     *
     * Splits a full name string into first name and last name components.
     * Handles single names, multi-part names, and empty strings.
     *
     * @param string $fullName The full name to split
     * @return array Associative array with 'first_name' and 'last_name' keys
     */
    private function splitName(string $fullName): array
    {
        // 🧹 Trim and handle empty names
        $fullName = trim($fullName);

        if (empty($fullName)) {
            return [
                'first_name' => '',
                'last_name' => '',
            ];
        }

        // ✂️ Split on whitespace
        $parts = preg_split('/\s+/', $fullName, 2);

        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',  // Everything after the first space is the last name
        ];
    }

    /**
     * 🔄 Revoke Access
     *
     * Amazon does not provide a standard token revocation endpoint.
     * Users must manually revoke access via their Amazon account settings.
     *
     * @see https://www.amazon.com/gp/help/customer/display.html?nodeId=201362380
     *
     * @param string $accessToken Access token to revoke
     * @return bool Always returns false as Amazon does not support programmatic revocation
     */
    public function revokeAccess(string $accessToken): bool
    {
        // ⚠️ Amazon does not provide a standard token revocation endpoint
        // Users must manually revoke access at: https://www.amazon.com/ap/adam
        error_log('Amazon OAuth: Programmatic token revocation is not supported. User must revoke via Amazon account settings.');
        return false;
    }
}

// ✅ AmazonOAuth class loaded successfully
