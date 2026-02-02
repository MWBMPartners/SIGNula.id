<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Apple Sign In Provider
 * ============================================================================
 *
 * Purpose: Apple Sign In OAuth 2.0 authentication
 * PHP Version: 8.3+
 *
 * Features:
 * - Apple ID authentication
 * - Private email relay support
 * - JWT-based client authentication
 * - Native iOS/macOS integration ready
 * - Enhanced privacy features
 *
 * Documentation:
 * @see https://developer.apple.com/documentation/sign_in_with_apple
 * @see https://developer.apple.com/documentation/sign_in_with_apple/sign_in_with_apple_rest_api
 *
 * Setup Requirements:
 * 1. Apple Developer Account
 * 2. App ID configured with Sign in with Apple capability
 * 3. Services ID (Client ID)
 * 4. Private Key (.p8 file) from Apple Developer Portal
 * 5. Team ID and Key ID
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
 * 🔐 Apple Sign In Provider
 *
 * Implements Apple Sign In using OAuth 2.0 with JWT client authentication.
 * Provides enhanced privacy features including private email relay.
 */
class AppleOAuth extends OAuth
{
    /**
     * @var string Apple Team ID
     */
    protected string $teamId;

    /**
     * @var string Key ID for private key
     */
    protected string $keyId;

    /**
     * @var string Private key content (PEM format)
     */
    protected string $privateKey;

    /**
     * @var int JWT expiration time (max 6 months)
     */
    protected const JWT_EXPIRATION = 15777000; // 6 months in seconds

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('apple');

        // 🔗 Apple Sign In endpoints
        $this->authorizationEndpoint = 'https://appleid.apple.com/auth/authorize';
        $this->tokenEndpoint = 'https://appleid.apple.com/auth/token';
        $this->userInfoEndpoint = ''; // Apple doesn't have a separate userinfo endpoint

        // 📋 OAuth scopes
        $this->scopes = [
            'name',   // User's name (only provided on first sign-in)
            'email',  // Email address (may be private relay)
        ];

        // 🔑 Load Apple-specific configuration
        $this->loadAppleConfiguration();
    }

    /**
     * ⚙️ Load Apple-Specific Configuration
     *
     * Loads Team ID, Key ID, and Private Key from database.
     *
     * @throws RuntimeException If configuration is missing
     */
    protected function loadAppleConfiguration(): void
    {
        try {
            // 🔍 Get Apple-specific settings
            $this->teamId = getSetting('oauth.apple.team_id', '');
            $this->keyId = getSetting('oauth.apple.key_id', '');

            // 🔐 Get encrypted private key
            $encryptedKey = getSetting('oauth.apple.private_key', '');
            if (!empty($encryptedKey)) {
                $this->privateKey = SecurityUtils::decrypt($encryptedKey);
            }

            // ✅ Validate Apple configuration
            if (empty($this->teamId) || empty($this->keyId) || empty($this->privateKey)) {
                throw new RuntimeException('Apple Sign In configuration incomplete');
            }

        } catch (Exception $e) {
            error_log('Apple configuration error: ' . $e->getMessage());
            // Don't throw - allow object creation but mark as not configured
        }
    }

    /**
     * ❓ Check if Provider is Configured
     *
     * @return bool True if provider is configured
     */
    public function isConfigured(): bool
    {
        return parent::isConfigured() &&
               !empty($this->teamId) &&
               !empty($this->keyId) &&
               !empty($this->privateKey);
    }

    /**
     * 🎨 Get Provider Display Name
     *
     * @return string Display name
     */
    public function getDisplayName(): string
    {
        return 'Apple';
    }

    /**
     * 🔗 Get Authorization URL
     *
     * Generates Apple authorization URL with additional parameters.
     *
     * @param array $additionalParams Additional parameters
     * @return string Authorization URL
     */
    public function getAuthorizationUrl(array $additionalParams = []): string
    {
        // 🎨 Apple-specific parameters
        $appleParams = [
            'response_mode' => 'form_post', // Apple recommends form_post for web
        ];

        return parent::getAuthorizationUrl(array_merge($appleParams, $additionalParams));
    }

    /**
     * 🔄 Exchange Authorization Code for Access Token
     *
     * Apple requires JWT-based client authentication instead of client_secret.
     *
     * @param string $code Authorization code
     * @return array Token response
     * @throws RuntimeException If exchange fails
     */
    public function exchangeCodeForToken(string $code): array
    {
        try {
            // 🔑 Generate client secret (JWT)
            $clientSecret = $this->generateClientSecret();

            // 📝 Prepare token request
            $params = [
                'client_id' => $this->clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri
            ];

            // 🌐 Make token request
            $response = $this->makeHttpRequest($this->tokenEndpoint, 'POST', $params);

            // ✅ Validate response
            if (empty($response['id_token'])) {
                throw new RuntimeException('Invalid token response: missing id_token');
            }

            return $response;

        } catch (Exception $e) {
            error_log('Apple token exchange error: ' . $e->getMessage());
            throw new RuntimeException('Failed to exchange authorization code for token');
        }
    }

    /**
     * 🔑 Generate Client Secret (JWT)
     *
     * Apple uses a JWT as the client secret, signed with your private key.
     *
     * @return string Signed JWT token
     * @throws RuntimeException If JWT generation fails
     */
    protected function generateClientSecret(): string
    {
        try {
            // 📅 Token timestamps
            $now = time();
            $expiration = $now + self::JWT_EXPIRATION;

            // 📝 JWT Header
            $header = [
                'alg' => 'ES256',  // ECDSA using P-256 and SHA-256
                'kid' => $this->keyId
            ];

            // 📝 JWT Payload (Claims)
            $payload = [
                'iss' => $this->teamId,           // Issuer (Team ID)
                'iat' => $now,                     // Issued at
                'exp' => $expiration,              // Expiration (max 6 months)
                'aud' => 'https://appleid.apple.com', // Audience
                'sub' => $this->clientId           // Subject (Services ID)
            ];

            // 🔐 Create JWT
            $headerEncoded = $this->base64UrlEncode(json_encode($header));
            $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

            $signatureInput = $headerEncoded . '.' . $payloadEncoded;

            // ✍️ Sign with private key
            $signature = '';
            $privateKeyResource = openssl_pkey_get_private($this->privateKey);

            if ($privateKeyResource === false) {
                throw new RuntimeException('Invalid private key');
            }

            $success = openssl_sign(
                $signatureInput,
                $signature,
                $privateKeyResource,
                OPENSSL_ALGO_SHA256
            );

            if (!$success) {
                throw new RuntimeException('Failed to sign JWT');
            }

            $signatureEncoded = $this->base64UrlEncode($signature);

            // 🎫 Complete JWT
            $jwt = $signatureInput . '.' . $signatureEncoded;

            return $jwt;

        } catch (Exception $e) {
            error_log('Apple JWT generation error: ' . $e->getMessage());
            throw new RuntimeException('Failed to generate client secret JWT');
        }
    }

    /**
     * 👤 Get User Info
     *
     * Apple provides user info in the ID token, not via a separate endpoint.
     * User info is also sent in the authorization response (only on first sign-in).
     *
     * @param string $idToken ID token (JWT)
     * @return array Normalized user data
     * @throws RuntimeException If token validation fails
     */
    public function getUserInfo(string $idToken): array
    {
        try {
            // 🔓 Decode and validate ID token
            $tokenData = $this->decodeIdToken($idToken);

            // 🔄 Normalize data
            return $this->normalizeUserData($tokenData);

        } catch (Exception $e) {
            error_log('Apple getUserInfo error: ' . $e->getMessage());
            throw new RuntimeException('Failed to decode Apple ID token');
        }
    }

    /**
     * 🔓 Decode and Validate ID Token
     *
     * Decodes the JWT ID token from Apple.
     * In production, you should validate the signature using Apple's public keys.
     *
     * @param string $idToken ID token (JWT)
     * @return array Token payload
     */
    protected function decodeIdToken(string $idToken): array
    {
        // 📦 Split JWT into parts
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid JWT format');
        }

        // 🔓 Decode payload
        $payload = json_decode($this->base64UrlDecode($parts[1]), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JWT payload');
        }

        // ⏰ Validate expiration
        if (!empty($payload['exp']) && time() > $payload['exp']) {
            throw new RuntimeException('ID token has expired');
        }

        // ✅ Validate audience
        if (empty($payload['aud']) || $payload['aud'] !== $this->clientId) {
            throw new RuntimeException('Invalid audience in ID token');
        }

        // 🔍 Validate issuer
        if (empty($payload['iss']) || $payload['iss'] !== 'https://appleid.apple.com') {
            throw new RuntimeException('Invalid issuer in ID token');
        }

        return $payload;
    }

    /**
     * 🔄 Normalize User Data
     *
     * Converts Apple's ID token data to standard format.
     *
     * Apple ID token payload:
     * {
     *   "iss": "https://appleid.apple.com",
     *   "aud": "com.example.app",
     *   "exp": 1234567890,
     *   "iat": 1234567800,
     *   "sub": "001234.abc123def456.7890",
     *   "email": "user@example.com",
     *   "email_verified": true,
     *   "is_private_email": false
     * }
     *
     * Note: Name is NOT in ID token - it's sent separately in authorization response.
     *
     * @param array $rawData Raw data from Apple ID token
     * @return array Normalized user data
     */
    protected function normalizeUserData(array $rawData): array
    {
        // 🔍 Extract data with fallbacks
        $normalized = [
            'provider_id' => $rawData['sub'] ?? '',
            'email' => $rawData['email'] ?? '',
            'email_verified' => $rawData['email_verified'] ?? true,
            'is_private_email' => $rawData['is_private_email'] ?? false,
            'name' => '', // Not in ID token - need to get from auth response
            'first_name' => '',
            'last_name' => '',
            'avatar' => null, // Apple doesn't provide profile pictures
            'locale' => null,
        ];

        // ✅ Validate required fields
        if (empty($normalized['provider_id']) || empty($normalized['email'])) {
            throw new RuntimeException('Invalid user data from Apple');
        }

        // 📧 Private email relay indicator
        if ($normalized['is_private_email']) {
            error_log('Apple login: Using private email relay - ' . $normalized['email']);
        }

        return $normalized;
    }

    /**
     * 👤 Process Authorization Response User Data
     *
     * Apple sends user's name in the authorization response (form_post),
     * but only on the FIRST sign-in. Must be captured then.
     *
     * @param array $authResponse Authorization response data
     * @return array User data with name
     */
    public function processAuthorizationResponse(array $authResponse): array
    {
        $userData = [];

        // 📝 Extract user data if provided (only on first sign-in)
        if (!empty($authResponse['user'])) {
            $user = json_decode($authResponse['user'], true);

            if (!empty($user['name'])) {
                $userData['first_name'] = $user['name']['firstName'] ?? '';
                $userData['last_name'] = $user['name']['lastName'] ?? '';
                $userData['name'] = trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? ''));
            }

            if (!empty($user['email'])) {
                $userData['email'] = $user['email'];
            }
        }

        return $userData;
    }

    /**
     * 🔄 Revoke Access
     *
     * Apple provides a revocation endpoint.
     *
     * @param string $accessToken Access token or refresh token to revoke
     * @return bool True if successful
     */
    public function revokeAccess(string $accessToken): bool
    {
        try {
            // 🔑 Generate client secret
            $clientSecret = $this->generateClientSecret();

            // 🌐 Apple's token revocation endpoint
            $revokeEndpoint = 'https://appleid.apple.com/auth/revoke';

            $this->makeHttpRequest(
                $revokeEndpoint,
                'POST',
                [
                    'client_id' => $this->clientId,
                    'client_secret' => $clientSecret,
                    'token' => $accessToken,
                    'token_type_hint' => 'access_token'
                ]
            );

            return true;

        } catch (Exception $e) {
            error_log('Apple revoke access error: ' . $e->getMessage());
            return false;
        }
    }

    // ========================================================================
    // 🔧 UTILITY METHODS
    // ========================================================================

    /**
     * 🔐 Base64 URL Encode
     *
     * URL-safe base64 encoding for JWT.
     *
     * @param string $data Data to encode
     * @return string Encoded data
     */
    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * 🔓 Base64 URL Decode
     *
     * URL-safe base64 decoding for JWT.
     *
     * @param string $data Data to decode
     * @return string Decoded data
     */
    protected function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padLength = 4 - $remainder;
            $data .= str_repeat('=', $padLength);
        }

        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * 📋 Get Setup Instructions
     *
     * Returns setup instructions for Apple Sign In.
     *
     * @return array Setup instructions
     */
    public static function getSetupInstructions(): array
    {
        return [
            'title' => 'Apple Sign In Setup',
            'requirements' => [
                'Apple Developer Account (paid membership required)',
                'App ID configured with Sign in with Apple capability',
                'Services ID (acts as OAuth Client ID)',
                'Private Key (.p8 file) from Apple Developer Portal',
                'Team ID from Apple Developer Portal',
                'Key ID for your private key'
            ],
            'steps' => [
                '1. Log in to Apple Developer Portal',
                '2. Create an App ID with Sign in with Apple capability',
                '3. Create a Services ID (this is your Client ID)',
                '4. Configure Services ID with your domain and redirect URL',
                '5. Create a Key with Sign in with Apple capability',
                '6. Download the private key (.p8 file) - you can only do this once!',
                '7. Note your Team ID, Key ID, and Services ID',
                '8. Store these in SIGNula settings (Settings > OAuth Providers > Apple)'
            ],
            'database_settings' => [
                'oauth.apple.client_id' => 'Your Services ID (e.g., com.example.signula)',
                'oauth.apple.team_id' => 'Your 10-character Team ID',
                'oauth.apple.key_id' => 'Your 10-character Key ID',
                'oauth.apple.private_key' => 'Contents of .p8 file (will be encrypted)',
                'oauth.apple.redirect_uri' => 'Your redirect URL (e.g., https://signulo.id/oauth/callback)'
            ],
            'notes' => [
                'The private key (.p8 file) can only be downloaded once - store it securely!',
                'User name is only provided on first sign-in - you must capture it then',
                'Email may be a private relay address (e.g., abc123@privaterelay.appleid.com)',
                'Apple doesn\'t provide profile pictures',
                'Refresh tokens are valid for 6 months'
            ]
        ];
    }
}

// ✅ AppleOAuth class loaded successfully
