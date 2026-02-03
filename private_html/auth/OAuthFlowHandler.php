<?php
/**
 * ============================================================================
 * 🔐 SIGNula - OAuth Flow Handler
 * ============================================================================
 *
 * Purpose: Handle OAuth 2.0 authorization flow for delegated permissions
 * PHP Version: 8.3+
 *
 * Features:
 * - Generate authorization URLs with state tokens
 * - Handle OAuth callbacks
 * - Exchange authorization codes for tokens
 * - CSRF protection via state tokens
 * - Multi-provider support (Microsoft, Google)
 *
 * @package    SIGNula
 * @subpackage Auth
 * @version    2.0.0
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🔐 OAuth Flow Handler
 *
 * Manages OAuth 2.0 authorization code flow for user consent.
 */
class OAuthFlowHandler
{
    /**
     * @var array Provider configurations
     */
    private const PROVIDERS = [
        'microsoft_graph' => [
            'name' => 'Microsoft 365',
            'authorize_endpoint' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
            'token_endpoint' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
            'scopes' => ['https://graph.microsoft.com/Mail.Send', 'offline_access'],
            'requires_tenant' => true
        ],
        'google_workspace' => [
            'name' => 'Google Workspace',
            'authorize_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'scopes' => ['https://www.googleapis.com/auth/gmail.send'],
            'requires_tenant' => false
        ]
    ];

    /**
     * 🔗 Generate Authorization URL
     *
     * Creates an OAuth authorization URL with CSRF-protected state token.
     *
     * @param string $provider Provider name ('microsoft_graph', 'google_workspace')
     * @param int $userID User ID
     * @param string $mailboxEmail Mailbox email to authorize
     * @param string|null $redirectUri Custom redirect URI (optional)
     * @return array ['url' => string, 'state' => string] or ['error' => string]
     */
    public static function generateAuthorizationUrl(
        string $provider,
        int $userID,
        string $mailboxEmail,
        ?string $redirectUri = null
    ): array {
        try {
            // ✅ Validate provider
            if (!isset(self::PROVIDERS[$provider])) {
                return ['error' => "Unsupported provider: {$provider}"];
            }

            $config = self::PROVIDERS[$provider];

            // 🔍 Get provider-specific configuration
            switch ($provider) {
                case 'microsoft_graph':
                    $clientID = getSetting('email.microsoft.client_id', '');
                    $tenantID = getSetting('email.microsoft.tenant_id', '');
                    $redirectUri = $redirectUri ?? getSetting('email.microsoft.delegated_redirect_uri', '');

                    if (empty($clientID) || empty($tenantID)) {
                        return ['error' => 'Microsoft OAuth configuration missing'];
                    }

                    $authorizeEndpoint = str_replace('{tenant}', $tenantID, $config['authorize_endpoint']);
                    break;

                case 'google_workspace':
                    $clientID = getSetting('email.google.client_id', '');
                    $redirectUri = $redirectUri ?? getSetting('email.google.redirect_uri', '');

                    if (empty($clientID)) {
                        return ['error' => 'Google OAuth configuration missing'];
                    }

                    $authorizeEndpoint = $config['authorize_endpoint'];
                    break;

                default:
                    return ['error' => 'Provider configuration not implemented'];
            }

            if (empty($redirectUri)) {
                return ['error' => 'Redirect URI not configured'];
            }

            // 🔐 Generate state token (CSRF protection)
            $state = self::generateStateToken($userID, $provider, $mailboxEmail);

            // 📝 Build authorization URL
            $params = [
                'client_id' => $clientID,
                'response_type' => 'code',
                'redirect_uri' => $redirectUri,
                'scope' => implode(' ', $config['scopes']),
                'state' => $state,
                'access_type' => 'offline',  // Request refresh token
                'prompt' => 'consent'  // Force consent screen
            ];

            // 📧 Add login hint for better UX
            if ($mailboxEmail) {
                $params['login_hint'] = $mailboxEmail;
            }

            $authorizationUrl = $authorizeEndpoint . '?' . http_build_query($params);

            // 📝 Log authorization URL generation
            ActivityLogger::log(
                'USER_OAUTH_AUTH_INITIATED',
                $userID,
                json_encode([
                    'provider' => $provider,
                    'mailboxEmail' => $mailboxEmail,
                    'state' => substr($state, 0, 10) . '...'
                ])
            );

            return [
                'url' => $authorizationUrl,
                'state' => $state,
                'provider' => $provider
            ];

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return ['error' => 'Failed to generate authorization URL'];
        }
    }

    /**
     * 🔄 Handle OAuth Callback
     *
     * Processes OAuth callback and exchanges code for tokens.
     *
     * @param string $provider Provider name
     * @param string $code Authorization code from callback
     * @param string $state State token from callback
     * @param string|null $error Error from provider (if any)
     * @return array ['success' => bool, 'userID' => int, 'mailboxEmail' => string] or ['error' => string]
     */
    public static function handleCallback(
        string $provider,
        string $code,
        string $state,
        ?string $error = null
    ): array {
        try {
            // ❌ Check for provider errors
            if ($error) {
                return ['error' => "OAuth error: {$error}"];
            }

            // ✅ Validate state token (CSRF protection)
            $stateData = self::validateStateToken($state);

            if (!$stateData) {
                return ['error' => 'Invalid or expired state token (CSRF check failed)'];
            }

            $userID = $stateData['userID'];
            $mailboxEmail = $stateData['mailboxEmail'];
            $stateProvider = $stateData['provider'];

            // ✅ Verify provider matches
            if ($provider !== $stateProvider) {
                return ['error' => 'Provider mismatch'];
            }

            // 🔄 Exchange authorization code for tokens
            $tokens = self::exchangeCodeForTokens($provider, $code);

            if (isset($tokens['error'])) {
                return $tokens;
            }

            // 💾 Store tokens using OAuthTokenManager
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'OAuthTokenManager.php';

            $stored = OAuthTokenManager::storeToken(
                userID: $userID,
                provider: $provider,
                mailboxEmail: $mailboxEmail,
                accessToken: $tokens['access_token'],
                refreshToken: $tokens['refresh_token'] ?? null,
                expiresIn: $tokens['expires_in'] ?? 3600,
                scope: $tokens['scope'] ?? null,
                mailboxName: null  // Will be fetched separately if needed
            );

            if (!$stored) {
                return ['error' => 'Failed to store OAuth tokens'];
            }

            // 📝 Log successful authorization
            ActivityLogger::log(
                'USER_OAUTH_AUTH_SUCCESS',
                $userID,
                json_encode([
                    'provider' => $provider,
                    'mailboxEmail' => $mailboxEmail,
                    'hasRefreshToken' => !empty($tokens['refresh_token'])
                ])
            );

            return [
                'success' => true,
                'userID' => $userID,
                'mailboxEmail' => $mailboxEmail,
                'provider' => $provider
            ];

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return ['error' => 'Failed to handle OAuth callback'];
        }
    }

    /**
     * 🔐 Generate State Token
     *
     * Creates a cryptographically secure state token for CSRF protection.
     *
     * @param int $userID User ID
     * @param string $provider Provider name
     * @param string $mailboxEmail Mailbox email
     * @return string State token
     */
    private static function generateStateToken(int $userID, string $provider, string $mailboxEmail): string
    {
        // 🔐 Generate random state
        $randomBytes = random_bytes(32);
        $state = bin2hex($randomBytes);

        // 📝 Store state in session with metadata
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['oauth_state'][$state] = [
            'userID' => $userID,
            'provider' => $provider,
            'mailboxEmail' => $mailboxEmail,
            'created_at' => time(),
            'expires_at' => time() + 600  // 10 minutes
        ];

        // 🧹 Cleanup old states
        self::cleanupExpiredStates();

        return $state;
    }

    /**
     * ✅ Validate State Token
     *
     * Validates state token and returns associated data.
     *
     * @param string $state State token to validate
     * @return array|null State data or null if invalid
     */
    private static function validateStateToken(string $state): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 🔍 Check if state exists
        if (!isset($_SESSION['oauth_state'][$state])) {
            return null;
        }

        $stateData = $_SESSION['oauth_state'][$state];

        // ⏰ Check if expired
        if (time() > $stateData['expires_at']) {
            unset($_SESSION['oauth_state'][$state]);
            return null;
        }

        // ✅ Valid state - remove it (one-time use)
        unset($_SESSION['oauth_state'][$state]);

        return $stateData;
    }

    /**
     * 🧹 Cleanup Expired State Tokens
     *
     * Removes expired state tokens from session.
     */
    private static function cleanupExpiredStates(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['oauth_state'])) {
            return;
        }

        $now = time();
        foreach ($_SESSION['oauth_state'] as $state => $data) {
            if ($now > $data['expires_at']) {
                unset($_SESSION['oauth_state'][$state]);
            }
        }
    }

    /**
     * 🔄 Exchange Authorization Code for Tokens
     *
     * Exchanges authorization code for access and refresh tokens.
     *
     * @param string $provider Provider name
     * @param string $code Authorization code
     * @return array Token response or error
     */
    private static function exchangeCodeForTokens(string $provider, string $code): array
    {
        try {
            // 🔍 Get provider-specific configuration
            switch ($provider) {
                case 'microsoft_graph':
                    $clientID = getSetting('email.microsoft.client_id', '');
                    $clientSecret = SecurityUtils::decrypt(getSetting('email.microsoft.client_secret', ''));
                    $tenantID = getSetting('email.microsoft.tenant_id', '');
                    $redirectUri = getSetting('email.microsoft.delegated_redirect_uri', '');
                    $tokenEndpoint = str_replace('{tenant}', $tenantID, self::PROVIDERS[$provider]['token_endpoint']);
                    break;

                case 'google_workspace':
                    $clientID = getSetting('email.google.client_id', '');
                    $clientSecret = SecurityUtils::decrypt(getSetting('email.google.client_secret', ''));
                    $redirectUri = getSetting('email.google.redirect_uri', '');
                    $tokenEndpoint = self::PROVIDERS[$provider]['token_endpoint'];
                    break;

                default:
                    return ['error' => 'Provider not implemented'];
            }

            if (empty($clientID) || empty($clientSecret)) {
                return ['error' => 'OAuth configuration missing'];
            }

            // 📝 Prepare token request
            $postData = [
                'client_id' => $clientID,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code'
            ];

            // 🌐 Make token request
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $tokenEndpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode !== 200) {
                ErrorLogger::logError('OAuth', "Token exchange failed: HTTP {$httpCode} - {$response}");
                return ['error' => "Token exchange failed: HTTP {$httpCode}"];
            }

            if ($curlError) {
                ErrorLogger::logError('OAuth', "Token exchange cURL error: {$curlError}");
                return ['error' => "Network error: {$curlError}"];
            }

            $tokenData = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['error' => 'Invalid JSON response from provider'];
            }

            if (empty($tokenData['access_token'])) {
                return ['error' => 'No access token in response'];
            }

            return $tokenData;

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return ['error' => 'Failed to exchange code for tokens'];
        }
    }

    /**
     * 📋 Get Provider Info
     *
     * Returns information about a provider.
     *
     * @param string $provider Provider name
     * @return array|null Provider info or null if not found
     */
    public static function getProviderInfo(string $provider): ?array
    {
        return self::PROVIDERS[$provider] ?? null;
    }

    /**
     * 📋 Get All Providers
     *
     * Returns all available providers.
     *
     * @return array All providers
     */
    public static function getAllProviders(): array
    {
        return self::PROVIDERS;
    }

    /**
     * 🔐 Revoke User Authorization
     *
     * Revokes user's OAuth authorization and removes tokens.
     *
     * @param int $userID User ID
     * @param string $provider Provider name
     * @param string $mailboxEmail Mailbox email
     * @return bool Success status
     */
    public static function revokeAuthorization(int $userID, string $provider, string $mailboxEmail): bool
    {
        try {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'OAuthTokenManager.php';

            $revoked = OAuthTokenManager::revokeToken($userID, $provider, $mailboxEmail);

            if ($revoked) {
                ActivityLogger::log(
                    'USER_OAUTH_AUTH_REVOKED',
                    $userID,
                    json_encode([
                        'provider' => $provider,
                        'mailboxEmail' => $mailboxEmail
                    ])
                );
            }

            return $revoked;

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }
}

// ✅ OAuthFlowHandler loaded successfully
