<?php
/**
 * ============================================================================
 * 🔗 SIGNula - OAuth API Controller
 * ============================================================================
 *
 * Handles OAuth account linking and management endpoints.
 *
 * Endpoints:
 * - GET /api/v1/oauth/providers - List available OAuth providers
 * - GET /api/v1/oauth/linked - Get linked OAuth accounts
 * - POST /api/v1/oauth/link - Link OAuth account
 * - DELETE /api/v1/oauth/unlink/{provider} - Unlink OAuth account
 * - POST /api/v1/oauth/set-primary - Set primary OAuth account
 *
 * @package    SIGNula
 * @subpackage API\Controllers
 * @version    1.0.0
 * @link       https://SIGNula.id
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * Class OAuthController
 *
 * Handles OAuth API endpoints
 */
class OAuthController extends BaseController
{
    /**
     * 📋 Available OAuth providers
     */
    private const PROVIDERS = [
        'google' => [
            'name' => 'Google',
            'icon' => 'fab fa-google',
            'color' => '#DB4437',
        ],
        'microsoft' => [
            'name' => 'Microsoft',
            'icon' => 'fab fa-microsoft',
            'color' => '#00A4EF',
        ],
        'apple' => [
            'name' => 'Apple',
            'icon' => 'fab fa-apple',
            'color' => '#000000',
        ],
        'facebook' => [
            'name' => 'Facebook',
            'icon' => 'fab fa-facebook',
            'color' => '#1877F2',
        ],
        'linkedin' => [
            'name' => 'LinkedIn',
            'icon' => 'fab fa-linkedin',
            'color' => '#0A66C2',
        ],
        'github' => [
            'name' => 'GitHub',
            'icon' => 'fab fa-github',
            'color' => '#181717',
        ],
    ];

    /**
     * 📋 GET /api/v1/oauth/providers
     *
     * Get list of available OAuth providers
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function getProviders(array $params): void
    {
        try {
            $providers = [];

            foreach (self::PROVIDERS as $key => $provider) {
                // Check if provider is configured
                $clientId = getSetting("oauth.{$key}.client_id", '');
                $isConfigured = !empty($clientId);

                $providers[] = [
                    'provider' => $key,
                    'name' => $provider['name'],
                    'icon' => $provider['icon'],
                    'color' => $provider['color'],
                    'configured' => $isConfigured,
                    'enabled' => $isConfigured && getSetting("oauth.{$key}.enabled", 1),
                ];
            }

            $this->success([
                'providers' => $providers,
                'total' => count($providers),
            ], 'OAuth providers retrieved successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to retrieve providers', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🔗 GET /api/v1/oauth/linked
     *
     * Get user's linked OAuth accounts
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function getLinkedAccounts(array $params): void
    {
        $user = $this->requireAuth();

        try {
            $query = "
                SELECT
                    oauthAccountID,
                    provider,
                    providerUserID,
                    email,
                    displayName,
                    isPrimary,
                    DATE_FORMAT(linkedAt, '%Y-%m-%dT%H:%i:%sZ') as linked_at,
                    DATE_FORMAT(lastUsedAt, '%Y-%m-%dT%H:%i:%sZ') as last_used_at
                FROM tblOAuthAccounts
                WHERE userID = ?
                ORDER BY isPrimary DESC, linkedAt DESC
            ";

            $result = Database::query($query, [$user['userID']], 'i');
            $accounts = [];

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $providerInfo = self::PROVIDERS[$row['provider']] ?? [
                        'name' => ucfirst($row['provider']),
                        'icon' => 'fas fa-sign-in-alt',
                        'color' => '#000000',
                    ];

                    $accounts[] = [
                        'account_id' => $row['oauthAccountID'],
                        'provider' => $row['provider'],
                        'provider_name' => $providerInfo['name'],
                        'provider_icon' => $providerInfo['icon'],
                        'provider_color' => $providerInfo['color'],
                        'provider_user_id' => $row['providerUserID'],
                        'email' => $row['email'],
                        'display_name' => $row['displayName'],
                        'is_primary' => (bool) $row['isPrimary'],
                        'linked_at' => $row['linked_at'],
                        'last_used_at' => $row['last_used_at'],
                    ];
                }
            }

            $this->success([
                'accounts' => $accounts,
                'total' => count($accounts),
            ], 'Linked accounts retrieved successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to retrieve linked accounts', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * ➕ POST /api/v1/oauth/link
     *
     * Link OAuth account
     * Note: This typically requires OAuth flow completion
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function linkAccount(array $params): void
    {
        $user = $this->requireAuth();

        // ✅ Validate input
        $data = $this->validate([
            'provider' => 'required|string|in:google,microsoft,apple,facebook,linkedin,github',
            'provider_user_id' => 'required|string',
            'email' => 'required|email',
            'display_name' => 'string',
            'access_token' => 'required|string',
            'refresh_token' => 'string',
        ]);

        try {
            // Check if provider is already linked
            $existingQuery = "
                SELECT oauthAccountID FROM tblOAuthAccounts
                WHERE userID = ? AND provider = ?
            ";

            $existingResult = Database::query(
                $existingQuery,
                [$user['userID'], $data['provider']],
                'is'
            );

            if ($existingResult && $existingResult->num_rows > 0) {
                $this->error('This provider is already linked to your account', Response::HTTP_CONFLICT);
                return;
            }

            // 🔒 Encrypt tokens
            $accessTokenEncrypted = SecurityUtils::encrypt($data['access_token']);
            $refreshTokenEncrypted = isset($data['refresh_token'])
                ? SecurityUtils::encrypt($data['refresh_token'])
                : null;

            // 💾 Store OAuth account link
            $query = "
                INSERT INTO tblOAuthAccounts (
                    userID, provider, providerUserID, email, displayName,
                    accessToken, refreshToken, isPrimary, linkedAt, lastUsedAt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
            ";

            Database::query(
                $query,
                [
                    $user['userID'],
                    $data['provider'],
                    $data['provider_user_id'],
                    $data['email'],
                    $data['display_name'] ?? '',
                    $accessTokenEncrypted,
                    $refreshTokenEncrypted
                ],
                'issssss'
            );

            $accountId = Database::getLastInsertId();

            // 📊 Log activity
            ActivityLogger::log($user['userID'], 'oauth_linked', 'success', "Linked {$data['provider']} account via API");

            $this->created([
                'account_id' => $accountId,
                'provider' => $data['provider'],
                'email' => $data['email'],
                'linked' => true,
            ], 'OAuth account linked successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to link OAuth account', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🗑️ DELETE /api/v1/oauth/unlink/{provider}
     *
     * Unlink OAuth account
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function unlinkAccount(array $params): void
    {
        $user = $this->requireAuth();
        $provider = $params['provider'] ?? null;

        if (!$provider || !isset(self::PROVIDERS[$provider])) {
            $this->error('Invalid provider', Response::HTTP_BAD_REQUEST);
            return;
        }

        try {
            // 🔒 Prevent unlinking if it's the only login method
            if (!$this->canUnlinkProvider($user['userID'], $provider)) {
                $this->error('Cannot unlink your only login method. Set a password first.', Response::HTTP_FORBIDDEN);
                return;
            }

            // 🗑️ Delete OAuth account link
            $query = "DELETE FROM tblOAuthAccounts WHERE userID = ? AND provider = ?";
            $result = Database::query($query, [$user['userID'], $provider], 'is');

            if (Database::getAffectedRows() === 0) {
                $this->notFound('OAuth account not linked');
                return;
            }

            // 📊 Log activity
            ActivityLogger::log($user['userID'], 'oauth_unlinked', 'success', "Unlinked $provider account via API");

            $this->success(null, 'OAuth account unlinked successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to unlink OAuth account', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * ⭐ POST /api/v1/oauth/set-primary
     *
     * Set primary OAuth account (for avatar, etc.)
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function setPrimary(array $params): void
    {
        $user = $this->requireAuth();

        // ✅ Validate input
        $data = $this->validate([
            'account_id' => 'required|integer',
        ]);

        try {
            // Verify account belongs to user
            $query = "SELECT * FROM tblOAuthAccounts WHERE oauthAccountID = ? AND userID = ?";
            $result = Database::query($query, [$data['account_id'], $user['userID']], 'ii');

            if (!$result || $result->num_rows === 0) {
                $this->notFound('OAuth account not found');
                return;
            }

            // Clear all primary flags for this user
            Database::query(
                "UPDATE tblOAuthAccounts SET isPrimary = 0 WHERE userID = ?",
                [$user['userID']],
                'i'
            );

            // Set new primary
            Database::query(
                "UPDATE tblOAuthAccounts SET isPrimary = 1 WHERE oauthAccountID = ?",
                [$data['account_id']],
                'i'
            );

            // 📊 Log activity
            ActivityLogger::log($user['userID'], 'oauth_primary_changed', 'success', 'Changed primary OAuth account via API');

            $this->success([
                'account_id' => $data['account_id'],
                'is_primary' => true,
            ], 'Primary OAuth account updated');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to set primary account', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🔒 Check if user can unlink provider
     *
     * @param int $userId User ID
     * @param string $provider Provider to unlink
     * @return bool True if can unlink
     */
    private function canUnlinkProvider(int $userId, string $provider): bool
    {
        try {
            // Get user
            $userQuery = "SELECT * FROM tblUsers WHERE userID = ?";
            $result = Database::query($userQuery, [$userId], 'i');
            $user = $result ? $result->fetch_assoc() : null;

            if (!$user) {
                return false;
            }

            // Has password?
            $hasPassword = !empty($user['passwordHash']);

            // Has PassKey?
            $passkeyQuery = "SELECT COUNT(*) as count FROM tblWebAuthnCredentials WHERE userID = ?";
            $result = Database::query($passkeyQuery, [$userId], 'i');
            $hasPassKey = false;

            if ($result && $row = $result->fetch_assoc()) {
                $hasPassKey = $row['count'] > 0;
            }

            // Count other OAuth accounts
            $oauthQuery = "SELECT COUNT(*) as count FROM tblOAuthAccounts WHERE userID = ? AND provider != ?";
            $result = Database::query($oauthQuery, [$userId, $provider], 'is');
            $otherOAuthCount = 0;

            if ($result && $row = $result->fetch_assoc()) {
                $otherOAuthCount = $row['count'];
            }

            // Can unlink if has password, PassKey, or other OAuth accounts
            return $hasPassword || $hasPassKey || $otherOAuthCount > 0;

        } catch (Exception $e) {
            ErrorLogger::log($e);
            return false;
        }
    }
}

// ✅ OAuthController loaded successfully
