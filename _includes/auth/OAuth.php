<?php
/**
 * ============================================================================
 * 🔐 SIGNula - OAuth Base Handler
 * ============================================================================
 *
 * Purpose: Base OAuth 2.0 authentication handler
 * PHP Version: 8.3+
 *
 * Features:
 * - OAuth 2.0 authorization code flow
 * - State token generation and verification (CSRF protection)
 * - Token exchange and storage
 * - Account creation and linking
 * - Provider abstraction
 *
 * Standards:
 * - RFC 6749: OAuth 2.0 Authorization Framework
 * - RFC 6750: OAuth 2.0 Bearer Token Usage
 *
 * @package    SIGNula
 * @subpackage Authentication
 * @version    1.0.0
 * @link       https://signulo.id
 * @see        https://www.rfc-editor.org/rfc/rfc6749
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🔐 OAuth Base Handler Class
 *
 * Abstract base class for OAuth 2.0 providers.
 * Implements common OAuth flow with provider-specific customization.
 */
abstract class OAuth
{
    /**
     * @var string OAuth provider name
     */
    protected string $providerName;

    /**
     * @var string Client ID
     */
    protected string $clientId;

    /**
     * @var string Client Secret
     */
    protected string $clientSecret;

    /**
     * @var string Redirect URI
     */
    protected string $redirectUri;

    /**
     * @var array OAuth scopes
     */
    protected array $scopes = [];

    /**
     * @var string Authorization endpoint
     */
    protected string $authorizationEndpoint;

    /**
     * @var string Token endpoint
     */
    protected string $tokenEndpoint;

    /**
     * @var string User info endpoint
     */
    protected string $userInfoEndpoint;

    /**
     * @var int State token expiration (seconds)
     */
    protected const STATE_EXPIRATION = 600; // 10 minutes

    // ========================================================================
    // 🏗️ CONSTRUCTOR
    // ========================================================================

    /**
     * 🏗️ Constructor
     *
     * @param string $providerName Provider identifier
     */
    public function __construct(string $providerName)
    {
        $this->providerName = $providerName;
        $this->loadConfiguration();
    }

    // ========================================================================
    // ⚙️ CONFIGURATION
    // ========================================================================

    /**
     * 📋 Load OAuth Configuration
     *
     * Loads OAuth credentials from database settings.
     *
     * @throws RuntimeException If configuration is missing
     */
    protected function loadConfiguration(): void
    {
        try {
            // 🔍 Get configuration from database
            $prefix = "oauth.{$this->providerName}";

            $this->clientId = getSetting("{$prefix}.client_id", '');
            $this->clientSecret = getSetting("{$prefix}.client_secret", '');
            $this->redirectUri = getSetting("{$prefix}.redirect_uri",
                getSetting('app.url', 'https://signulo.id') . '/oauth/callback'
            );

            // 🔓 Decrypt sensitive credentials
            if (!empty($this->clientSecret)) {
                $this->clientSecret = SecurityUtils::decrypt($this->clientSecret);
            }

            // ✅ Validate configuration
            if (empty($this->clientId) || empty($this->clientSecret)) {
                throw new RuntimeException("OAuth configuration missing for {$this->providerName}");
            }

        } catch (Exception $e) {
            error_log("OAuth configuration error for {$this->providerName}: " . $e->getMessage());
            throw new RuntimeException("OAuth provider not configured: {$this->providerName}");
        }
    }

    /**
     * ❓ Check if Provider is Configured
     *
     * @return bool True if provider is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    // ========================================================================
    // 🔗 AUTHORIZATION FLOW
    // ========================================================================

    /**
     * 🔗 Get Authorization URL
     *
     * Generates authorization URL for OAuth flow.
     * Includes state token for CSRF protection.
     *
     * @param array $additionalParams Additional query parameters
     * @return string Authorization URL
     *
     * @example
     * ```php
     * $oauth = new GoogleOAuth();
     * $url = $oauth->getAuthorizationUrl();
     * header("Location: $url");
     * ```
     */
    public function getAuthorizationUrl(array $additionalParams = []): string
    {
        // 🎫 Generate state token for CSRF protection
        $state = $this->generateState();

        // 📝 Build query parameters
        $params = array_merge([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes),
            'state' => $state,
        ], $additionalParams);

        // 🔗 Build authorization URL
        $url = $this->authorizationEndpoint . '?' . http_build_query($params);

        return $url;
    }

    /**
     * 🎫 Generate State Token
     *
     * Generates and stores state token for CSRF protection.
     *
     * @return string State token
     */
    protected function generateState(): string
    {
        // 🎲 Generate random state token
        $state = SecurityUtils::generateToken(32);

        // 💾 Store in session with expiration
        $_SESSION['oauth_state'] = [
            'token' => $state,
            'provider' => $this->providerName,
            'expires' => time() + self::STATE_EXPIRATION
        ];

        return $state;
    }

    /**
     * ✅ Verify State Token
     *
     * Verifies state token matches stored value.
     *
     * @param string $state State token to verify
     * @return bool True if valid
     */
    protected function verifyState(string $state): bool
    {
        // 🔍 Check session data exists
        if (empty($_SESSION['oauth_state'])) {
            return false;
        }

        $stored = $_SESSION['oauth_state'];

        // ⏰ Check expiration
        if (time() > $stored['expires']) {
            unset($_SESSION['oauth_state']);
            return false;
        }

        // ✅ Verify token matches and provider matches
        $valid = hash_equals($stored['token'], $state) &&
                 $stored['provider'] === $this->providerName;

        // 🗑️ Clear state after verification
        if ($valid) {
            unset($_SESSION['oauth_state']);
        }

        return $valid;
    }

    // ========================================================================
    // 🎫 TOKEN EXCHANGE
    // ========================================================================

    /**
     * 🔄 Exchange Authorization Code for Access Token
     *
     * Exchanges authorization code for access token.
     *
     * @param string $code Authorization code
     * @return array Token response ['access_token', 'refresh_token', 'expires_in', etc.]
     * @throws RuntimeException If exchange fails
     */
    public function exchangeCodeForToken(string $code): array
    {
        try {
            // 📝 Prepare token request
            $params = [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'redirect_uri' => $this->redirectUri,
                'grant_type' => 'authorization_code'
            ];

            // 🌐 Make token request
            $response = $this->makeHttpRequest($this->tokenEndpoint, 'POST', $params);

            // ✅ Validate response
            if (empty($response['access_token'])) {
                throw new RuntimeException('Invalid token response: missing access_token');
            }

            return $response;

        } catch (Exception $e) {
            error_log("Token exchange error for {$this->providerName}: " . $e->getMessage());
            throw new RuntimeException('Failed to exchange authorization code for token');
        }
    }

    /**
     * 🔄 Refresh Access Token
     *
     * Refreshes access token using refresh token.
     *
     * @param string $refreshToken Refresh token
     * @return array New token response
     * @throws RuntimeException If refresh fails
     */
    public function refreshToken(string $refreshToken): array
    {
        try {
            // 📝 Prepare refresh request
            $params = [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token'
            ];

            // 🌐 Make refresh request
            $response = $this->makeHttpRequest($this->tokenEndpoint, 'POST', $params);

            return $response;

        } catch (Exception $e) {
            error_log("Token refresh error for {$this->providerName}: " . $e->getMessage());
            throw new RuntimeException('Failed to refresh access token');
        }
    }

    // ========================================================================
    // 👤 USER INFO
    // ========================================================================

    /**
     * 👤 Get User Info
     *
     * Retrieves user information from OAuth provider.
     * Must be implemented by each provider.
     *
     * @param string $accessToken Access token
     * @return array Normalized user data
     */
    abstract public function getUserInfo(string $accessToken): array;

    /**
     * 🔄 Normalize User Data
     *
     * Converts provider-specific user data to standard format.
     * Must be implemented by each provider.
     *
     * @param array $rawData Raw user data from provider
     * @return array Normalized user data with keys:
     *               - provider_id: Unique user ID from provider
     *               - email: Email address
     *               - name: Full name
     *               - first_name: First name
     *               - last_name: Last name
     *               - avatar: Profile picture URL
     *               - email_verified: Whether email is verified
     */
    abstract protected function normalizeUserData(array $rawData): array;

    // ========================================================================
    // 🔗 ACCOUNT LINKING
    // ========================================================================

    /**
     * 🔗 Link OAuth Account to User
     *
     * Links OAuth provider account to SIGNula user.
     *
     * @param int $userID SIGNula user ID
     * @param array $userData Normalized user data from provider
     * @param array $tokenData Token data (access_token, refresh_token, etc.)
     * @return bool True if successful
     */
    public function linkAccount(int $userID, array $userData, array $tokenData): bool
    {
        try {
            // 🔒 Encrypt tokens before storage
            $encryptedAccessToken = SecurityUtils::encrypt($tokenData['access_token']);
            $encryptedRefreshToken = !empty($tokenData['refresh_token'])
                ? SecurityUtils::encrypt($tokenData['refresh_token'])
                : null;

            // 📅 Calculate token expiration
            $expiresAt = null;
            if (!empty($tokenData['expires_in'])) {
                $expiresAt = date('Y-m-d H:i:s', time() + $tokenData['expires_in']);
            }

            // 💾 Store in database
            $query = "
                INSERT INTO tblUserLinkedAccounts
                (userID, provider, providerUserID, email, displayName,
                 accessToken, refreshToken, tokenExpiresAt, profilePicture,
                 emailVerified, accountData, createdAt)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                accessToken = ?,
                refreshToken = ?,
                tokenExpiresAt = ?,
                displayName = ?,
                profilePicture = ?,
                emailVerified = ?,
                accountData = ?,
                updatedAt = NOW()
            ";

            $accountDataJson = json_encode($userData);

            Database::query(
                $query,
                [
                    $userID,
                    $this->providerName,
                    $userData['provider_id'],
                    $userData['email'],
                    $userData['name'],
                    $encryptedAccessToken,
                    $encryptedRefreshToken,
                    $expiresAt,
                    $userData['avatar'] ?? null,
                    $userData['email_verified'] ? 1 : 0,
                    $accountDataJson,
                    // ON DUPLICATE KEY UPDATE values
                    $encryptedAccessToken,
                    $encryptedRefreshToken,
                    $expiresAt,
                    $userData['name'],
                    $userData['avatar'] ?? null,
                    $userData['email_verified'] ? 1 : 0,
                    $accountDataJson
                ],
                'issssssssisssssss'
            );

            // 📝 Log activity
            ActivityLogger::log($userID, 'oauth_account_linked', 'auth', 'info',
                "Linked {$this->providerName} account", [
                    'provider' => $this->providerName,
                    'provider_email' => $userData['email']
                ]);

            return true;

        } catch (Exception $e) {
            error_log("Account linking error for {$this->providerName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🔍 Find User by Provider Account
     *
     * Finds SIGNula user by linked OAuth account.
     *
     * @param string $providerUserID Provider's user ID
     * @return int|null SIGNula user ID or null if not found
     */
    public function findUserByProviderAccount(string $providerUserID): ?int
    {
        try {
            $result = Database::fetchOne(
                "SELECT userID FROM tblUserLinkedAccounts WHERE provider = ? AND providerUserID = ?",
                [$this->providerName, $providerUserID],
                'ss'
            );

            return $result['userID'] ?? null;

        } catch (Exception $e) {
            error_log("Find user error: " . $e->getMessage());
            return null;
        }
    }

    // ========================================================================
    // 🌐 HTTP HELPER METHODS
    // ========================================================================

    /**
     * 🌐 Make HTTP Request
     *
     * Makes HTTP request to OAuth provider.
     *
     * @param string $url URL to request
     * @param string $method HTTP method (GET, POST)
     * @param array $params Request parameters
     * @param array $headers Additional headers
     * @return array Response data
     * @throws RuntimeException If request fails
     */
    protected function makeHttpRequest(
        string $url,
        string $method = 'GET',
        array $params = [],
        array $headers = []
    ): array {
        try {
            // 🔧 Initialize cURL
            $ch = curl_init();

            // 📝 Default headers
            $defaultHeaders = [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded'
            ];

            $allHeaders = array_merge($defaultHeaders, $headers);

            // ⚙️ Configure cURL
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
                curl_setopt($ch, CURLOPT_URL, $url);
            } else {
                $queryString = !empty($params) ? '?' . http_build_query($params) : '';
                curl_setopt($ch, CURLOPT_URL, $url . $queryString);
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            // 🌐 Execute request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            curl_close($ch);

            // ❌ Check for errors
            if ($response === false) {
                throw new RuntimeException("HTTP request failed: $error");
            }

            if ($httpCode >= 400) {
                throw new RuntimeException("HTTP error $httpCode: $response");
            }

            // 📦 Parse JSON response
            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException("Invalid JSON response: " . json_last_error_msg());
            }

            return $data;

        } catch (Exception $e) {
            error_log("HTTP request error: " . $e->getMessage());
            throw new RuntimeException("HTTP request failed: " . $e->getMessage());
        }
    }

    // ========================================================================
    // 🔧 UTILITY METHODS
    // ========================================================================

    /**
     * 📛 Get Provider Name
     *
     * @return string Provider name
     */
    public function getProviderName(): string
    {
        return $this->providerName;
    }

    /**
     * 🎨 Get Provider Display Name
     *
     * Returns user-friendly provider name.
     * Can be overridden by providers.
     *
     * @return string Display name
     */
    public function getDisplayName(): string
    {
        return ucfirst($this->providerName);
    }

    /**
     * 🎨 Get Provider Icon Class
     *
     * Returns Font Awesome icon class for provider.
     * Can be overridden by providers.
     *
     * @return string Icon class
     */
    public function getIconClass(): string
    {
        $icons = [
            'google' => 'fab fa-google',
            'microsoft' => 'fab fa-microsoft',
            'apple' => 'fab fa-apple',
            'facebook' => 'fab fa-facebook',
            'linkedin' => 'fab fa-linkedin',
            'github' => 'fab fa-github',
            'twitter' => 'fab fa-twitter',
        ];

        return $icons[$this->providerName] ?? 'fas fa-sign-in-alt';
    }
}

// ✅ OAuth base class loaded successfully
