<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Microsoft OAuth Provider
 * ============================================================================
 *
 * Purpose: Microsoft OAuth 2.0 authentication (Azure AD/Microsoft Identity Platform)
 * PHP Version: 8.3+
 *
 * Features:
 * - Personal Microsoft Account
 * - Microsoft 365 Work/School accounts
 * - Azure AD integration
 * - Microsoft Graph API access
 *
 * Documentation:
 * @see https://docs.microsoft.com/en-us/azure/active-directory/develop/v2-oauth2-auth-code-flow
 * @see https://docs.microsoft.com/en-us/graph/auth-v2-user
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
 * 🔐 Microsoft OAuth Provider
 *
 * Implements Microsoft identity platform OAuth 2.0 authentication.
 * Supports both personal Microsoft accounts and organizational accounts (Microsoft 365/Azure AD).
 */
class MicrosoftOAuth extends OAuth
{
    /**
     * @var string Tenant ID (common, organizations, consumers, or specific tenant ID)
     */
    protected string $tenant = 'common';

    /**
     * 🏗️ Constructor
     *
     * @param string $tenant Tenant type:
     *                      - 'common': Both personal and work/school accounts (default)
     *                      - 'organizations': Work/school accounts only
     *                      - 'consumers': Personal Microsoft accounts only
     *                      - '{tenant-id}': Specific Azure AD tenant
     */
    public function __construct(string $tenant = 'common')
    {
        $this->tenant = $tenant;

        parent::__construct('microsoft');

        // 🔗 Microsoft identity platform endpoints
        // Using v2.0 endpoints for broader compatibility
        $this->authorizationEndpoint = "https://login.microsoftonline.com/{$this->tenant}/oauth2/v2.0/authorize";
        $this->tokenEndpoint = "https://login.microsoftonline.com/{$this->tenant}/oauth2/v2.0/token";
        $this->userInfoEndpoint = 'https://graph.microsoft.com/v1.0/me';

        // 📋 OAuth scopes for Microsoft Graph
        // https://docs.microsoft.com/en-us/graph/permissions-reference
        $this->scopes = [
            'openid',              // Required for OpenID Connect
            'profile',             // Basic profile information
            'email',               // Email address
            'User.Read',           // Read user's profile (Microsoft Graph)
            'offline_access',      // Refresh token
        ];
    }

    /**
     * 🎨 Get Provider Display Name
     *
     * @return string Display name
     */
    public function getDisplayName(): string
    {
        return 'Microsoft';
    }

    /**
     * 👤 Get User Info
     *
     * Retrieves user information from Microsoft Graph API.
     *
     * @param string $accessToken Access token
     * @return array Normalized user data
     * @throws RuntimeException If request fails
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            // 🌐 Make request to Microsoft Graph API
            $headers = [
                'Authorization: Bearer ' . $accessToken
            ];

            // 👤 Get basic user info
            $rawData = $this->makeHttpRequest(
                $this->userInfoEndpoint,
                'GET',
                [],
                $headers
            );

            // 📸 Get user's profile photo (optional)
            try {
                $photoData = $this->makeHttpRequest(
                    'https://graph.microsoft.com/v1.0/me/photo/$value',
                    'GET',
                    [],
                    $headers
                );

                // 🖼️ Photo is returned as binary data, need to convert to data URL
                if (!empty($photoData)) {
                    // For simplicity, we'll use the Graph API endpoint URL
                    $rawData['photo'] = 'https://graph.microsoft.com/v1.0/me/photo/$value';
                }
            } catch (Exception $e) {
                // Photo might not exist, that's okay
                error_log('Microsoft photo fetch failed (this is normal): ' . $e->getMessage());
            }

            // 🔄 Normalize data
            return $this->normalizeUserData($rawData);

        } catch (Exception $e) {
            error_log('Microsoft getUserInfo error: ' . $e->getMessage());
            throw new RuntimeException('Failed to retrieve Microsoft user information');
        }
    }

    /**
     * 🔄 Normalize User Data
     *
     * Converts Microsoft Graph user data format to standard format.
     *
     * Microsoft Graph /me response format:
     * {
     *   "id": "12345678-1234-1234-1234-123456789abc",
     *   "userPrincipalName": "user@example.com",
     *   "mail": "user@example.com",
     *   "displayName": "John Doe",
     *   "givenName": "John",
     *   "surname": "Doe",
     *   "jobTitle": "Developer",
     *   "mobilePhone": "+1 234 567 8900",
     *   "officeLocation": "Seattle",
     *   "preferredLanguage": "en-US"
     * }
     *
     * @param array $rawData Raw data from Microsoft
     * @return array Normalized user data
     */
    protected function normalizeUserData(array $rawData): array
    {
        // 📧 Email can be in 'mail' or 'userPrincipalName'
        $email = $rawData['mail'] ?? $rawData['userPrincipalName'] ?? '';

        // 🔍 Extract data with fallbacks
        $normalized = [
            'provider_id' => $rawData['id'] ?? '',
            'email' => $email,
            'email_verified' => !empty($email), // Microsoft accounts are considered verified
            'name' => $rawData['displayName'] ?? '',
            'first_name' => $rawData['givenName'] ?? '',
            'last_name' => $rawData['surname'] ?? '',
            'avatar' => $rawData['photo'] ?? null,
            'locale' => $rawData['preferredLanguage'] ?? null,
            'job_title' => $rawData['jobTitle'] ?? null,
            'office_location' => $rawData['officeLocation'] ?? null,
        ];

        // 🏢 Determine if this is an organizational account
        $normalized['is_organizational'] = $this->isOrganizationalAccount($email, $rawData);

        // ✅ Validate required fields
        if (empty($normalized['provider_id']) || empty($normalized['email'])) {
            throw new RuntimeException('Invalid user data from Microsoft');
        }

        return $normalized;
    }

    /**
     * 🏢 Check if Account is Organizational
     *
     * Determines if the Microsoft account is organizational (Microsoft 365/Azure AD).
     *
     * @param string $email Email address
     * @param array $userData User data from Microsoft
     * @return bool True if organizational account
     */
    protected function isOrganizationalAccount(string $email, array $userData): bool
    {
        // 🔍 Check if userPrincipalName contains tenant domain
        if (!empty($userData['userPrincipalName']) && !empty($userData['mail'])) {
            // If UPN and mail differ, likely an organizational account
            if ($userData['userPrincipalName'] !== $userData['mail']) {
                return true;
            }
        }

        // 🏢 Check for organizational indicators
        if (!empty($userData['jobTitle']) || !empty($userData['officeLocation'])) {
            return true;
        }

        // 📧 Check email domain (common consumer domains)
        $consumerDomains = [
            'outlook.com',
            'hotmail.com',
            'live.com',
            'msn.com',
            'passport.com'
        ];

        $emailParts = explode('@', $email);
        if (count($emailParts) === 2) {
            $domain = strtolower($emailParts[1]);
            if (in_array($domain, $consumerDomains, true)) {
                return false; // Consumer account
            }
        }

        // 🤷 Default to true if uncertain
        return true;
    }

    /**
     * 🔄 Revoke Access
     *
     * Revokes Microsoft access token.
     *
     * Note: Microsoft doesn't have a direct revocation endpoint.
     * Users must revoke access through their Microsoft account settings.
     *
     * @param string $accessToken Access token to revoke
     * @return bool Always returns true (no-op)
     */
    public function revokeAccess(string $accessToken): bool
    {
        // ℹ️ Microsoft doesn't provide a programmatic token revocation endpoint
        // Users must revoke access manually through:
        // https://account.microsoft.com/privacy/app-access

        error_log('Microsoft token revocation must be done manually by user');

        return true;
    }

    /**
     * 📧 Get User's Email from Microsoft Graph
     *
     * Specifically fetches user's email if not provided in basic profile.
     *
     * @param string $accessToken Access token
     * @return string|null Email address or null
     */
    public function getUserEmail(string $accessToken): ?string
    {
        try {
            $headers = [
                'Authorization: Bearer ' . $accessToken
            ];

            $data = $this->makeHttpRequest(
                'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName',
                'GET',
                [],
                $headers
            );

            return $data['mail'] ?? $data['userPrincipalName'] ?? null;

        } catch (Exception $e) {
            error_log('Microsoft getUserEmail error: ' . $e->getMessage());
            return null;
        }
    }
}

// ✅ MicrosoftOAuth class loaded successfully
