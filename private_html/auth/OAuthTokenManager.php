<?php
/**
 * ============================================================================
 * 🔐 SIGNula - OAuth Token Manager
 * ============================================================================
 *
 * Purpose: Manage user OAuth tokens for delegated permissions
 * PHP Version: 8.3+
 *
 * Features:
 * - Store and retrieve user OAuth tokens
 * - Automatic token refresh
 * - Token expiration handling
 * - Multi-provider support (Microsoft, Google)
 * - Encrypted token storage
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
 * 🔑 OAuth Token Manager
 *
 * Manages user OAuth tokens for delegate mailbox sending.
 */
class OAuthTokenManager
{
    /**
     * 💾 Store User OAuth Token
     *
     * Stores or updates a user's OAuth token for a specific provider.
     *
     * @param int $userID User ID
     * @param string $provider Provider name ('microsoft_graph', 'gmail_api')
     * @param string $mailboxEmail Mailbox email address
     * @param string $accessToken OAuth access token
     * @param string|null $refreshToken OAuth refresh token (optional)
     * @param int $expiresIn Token lifetime in seconds
     * @param string|null $scope Granted OAuth scopes
     * @param string|null $mailboxName Display name of mailbox owner
     * @return bool Success status
     */
    public static function storeToken(
        int $userID,
        string $provider,
        string $mailboxEmail,
        string $accessToken,
        ?string $refreshToken = null,
        int $expiresIn = 3600,
        ?string $scope = null,
        ?string $mailboxName = null
    ): bool {
        try {
            // 🔐 Encrypt tokens
            $encryptedAccessToken = SecurityUtils::encrypt($accessToken);
            $encryptedRefreshToken = $refreshToken ? SecurityUtils::encrypt($refreshToken) : null;

            // 📅 Calculate expiration
            $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

            // 🔍 Check if token already exists for this user/provider/mailbox
            $existing = Database::fetchOne(
                "SELECT tokenID FROM tblUserOAuthTokens
                 WHERE userID = ? AND provider = ? AND mailboxEmail = ?",
                [$userID, $provider, $mailboxEmail],
                'iss'
            );

            if ($existing) {
                // 🔄 Update existing token
                $query = "
                    UPDATE tblUserOAuthTokens SET
                        accessToken = ?,
                        refreshToken = ?,
                        expiresAt = ?,
                        scope = ?,
                        mailboxName = ?,
                        isActive = TRUE,
                        errorCount = 0,
                        lastError = NULL,
                        lastErrorAt = NULL,
                        updatedAt = CURRENT_TIMESTAMP
                    WHERE tokenID = ?
                ";

                Database::execute($query, [
                    $encryptedAccessToken,
                    $encryptedRefreshToken,
                    $expiresAt,
                    $scope,
                    $mailboxName,
                    $existing['tokenID']
                ], 'sssssi');

                ActivityLogger::log(
                    'USER_OAUTH_TOKEN_UPDATED',
                    $userID,
                    json_encode([
                        'provider' => $provider,
                        'mailboxEmail' => $mailboxEmail,
                        'expiresAt' => $expiresAt
                    ])
                );

            } else {
                // ➕ Insert new token
                $query = "
                    INSERT INTO tblUserOAuthTokens (
                        userID, provider, accessToken, refreshToken,
                        tokenType, expiresAt, scope,
                        mailboxEmail, mailboxName,
                        isActive, createdAt, updatedAt
                    ) VALUES (?, ?, ?, ?, 'Bearer', ?, ?, ?, ?, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ";

                Database::execute($query, [
                    $userID,
                    $provider,
                    $encryptedAccessToken,
                    $encryptedRefreshToken,
                    $expiresAt,
                    $scope,
                    $mailboxEmail,
                    $mailboxName
                ], 'issssssss');

                ActivityLogger::log(
                    'USER_OAUTH_TOKEN_CREATED',
                    $userID,
                    json_encode([
                        'provider' => $provider,
                        'mailboxEmail' => $mailboxEmail,
                        'expiresAt' => $expiresAt
                    ])
                );
            }

            return true;

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }

    /**
     * 🔍 Get User OAuth Token
     *
     * Retrieves a user's OAuth token. Automatically refreshes if expired.
     *
     * @param int $userID User ID
     * @param string $provider Provider name
     * @param string $mailboxEmail Mailbox email address
     * @return array|null Token data or null if not found
     */
    public static function getToken(int $userID, string $provider, string $mailboxEmail): ?array
    {
        try {
            // 🔍 Fetch token from database
            $token = Database::fetchOne(
                "SELECT * FROM tblUserOAuthTokens
                 WHERE userID = ? AND provider = ? AND mailboxEmail = ? AND isActive = TRUE",
                [$userID, $provider, $mailboxEmail],
                'iss'
            );

            if (!$token) {
                return null;
            }

            // 🔐 Decrypt tokens
            $token['accessToken'] = SecurityUtils::decrypt($token['accessToken']);
            if ($token['refreshToken']) {
                $token['refreshToken'] = SecurityUtils::decrypt($token['refreshToken']);
            }

            // ⏰ Check if token is expired or expiring soon
            $expiresAt = strtotime($token['expiresAt']);
            $refreshThreshold = (int) getSetting('email.delegate.token_refresh_threshold', 300); // 5 minutes

            if (time() >= ($expiresAt - $refreshThreshold)) {
                // 🔄 Token expired or expiring soon - try to refresh
                if ($token['refreshToken']) {
                    $refreshed = self::refreshToken($token['tokenID'], $provider, $token['refreshToken']);
                    if ($refreshed) {
                        // 🔄 Fetch updated token
                        return self::getToken($userID, $provider, $mailboxEmail);
                    } else {
                        // ❌ Refresh failed - token is invalid
                        return null;
                    }
                } else {
                    // ❌ No refresh token - token is expired
                    self::markTokenInactive($token['tokenID'], 'Token expired and no refresh token available');
                    return null;
                }
            }

            // ✅ Token is valid
            // 📝 Update last used timestamp
            Database::execute(
                "UPDATE tblUserOAuthTokens SET lastUsedAt = CURRENT_TIMESTAMP WHERE tokenID = ?",
                [$token['tokenID']],
                'i'
            );

            return $token;

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return null;
        }
    }

    /**
     * 🔄 Refresh OAuth Token
     *
     * Refreshes an expired or expiring OAuth token.
     *
     * @param int $tokenID Token ID
     * @param string $provider Provider name
     * @param string $refreshToken Refresh token
     * @return bool Success status
     */
    public static function refreshToken(int $tokenID, string $provider, string $refreshToken): bool
    {
        try {
            // 🔄 Provider-specific refresh logic
            switch ($provider) {
                case 'microsoft_graph':
                    return self::refreshMicrosoftToken($tokenID, $refreshToken);

                case 'gmail_api':
                    // Gmail API uses service account - no refresh needed
                    return false;

                default:
                    ErrorLogger::logError('OAuth', "Unsupported provider for token refresh: {$provider}");
                    return false;
            }

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }

    /**
     * 🔄 Refresh Microsoft Graph Token
     *
     * Refreshes a Microsoft Graph OAuth token using refresh token.
     *
     * @param int $tokenID Token ID
     * @param string $refreshToken Refresh token
     * @return bool Success status
     */
    private static function refreshMicrosoftToken(int $tokenID, string $refreshToken): bool
    {
        try {
            // 🔍 Get Microsoft OAuth configuration
            $clientID = getSetting('email.microsoft.client_id', '');
            $clientSecret = SecurityUtils::decrypt(getSetting('email.microsoft.client_secret', ''));
            $tenantID = getSetting('email.microsoft.tenant_id', '');

            if (empty($clientID) || empty($clientSecret) || empty($tenantID)) {
                throw new RuntimeException('Microsoft OAuth configuration missing');
            }

            // 🔗 Token endpoint
            $tokenUrl = "https://login.microsoftonline.com/{$tenantID}/oauth2/v2.0/token";

            // 📝 Prepare refresh request
            $postData = [
                'client_id' => $clientID,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
                'scope' => 'https://graph.microsoft.com/Mail.Send offline_access'
            ];

            // 🌐 Make refresh request
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $tokenUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new RuntimeException("Token refresh failed: HTTP {$httpCode} - {$response}");
            }

            $tokenData = json_decode($response, true);

            if (empty($tokenData['access_token'])) {
                throw new RuntimeException('No access token in refresh response');
            }

            // 🔐 Encrypt new tokens
            $encryptedAccessToken = SecurityUtils::encrypt($tokenData['access_token']);
            $encryptedRefreshToken = isset($tokenData['refresh_token'])
                ? SecurityUtils::encrypt($tokenData['refresh_token'])
                : null;

            // 📅 Calculate expiration
            $expiresAt = date('Y-m-d H:i:s', time() + ($tokenData['expires_in'] ?? 3600));

            // 💾 Update token in database
            $query = "
                UPDATE tblUserOAuthTokens SET
                    accessToken = ?,
                    refreshToken = COALESCE(?, refreshToken),
                    expiresAt = ?,
                    errorCount = 0,
                    lastError = NULL,
                    lastErrorAt = NULL,
                    updatedAt = CURRENT_TIMESTAMP
                WHERE tokenID = ?
            ";

            Database::execute($query, [
                $encryptedAccessToken,
                $encryptedRefreshToken,
                $expiresAt,
                $tokenID
            ], 'sssi');

            // 📝 Log successful refresh
            ActivityLogger::log(
                'USER_OAUTH_TOKEN_REFRESHED',
                null,
                json_encode([
                    'tokenID' => $tokenID,
                    'provider' => 'microsoft_graph',
                    'expiresAt' => $expiresAt
                ])
            );

            return true;

        } catch (Exception $e) {
            // ❌ Refresh failed
            self::recordTokenError($tokenID, $e->getMessage());
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }

    /**
     * ❌ Mark Token as Inactive
     *
     * Marks a token as inactive (e.g., expired, revoked, invalid).
     *
     * @param int $tokenID Token ID
     * @param string $reason Reason for inactivation
     */
    private static function markTokenInactive(int $tokenID, string $reason): void
    {
        try {
            Database::execute(
                "UPDATE tblUserOAuthTokens SET
                 isActive = FALSE,
                 lastError = ?,
                 lastErrorAt = CURRENT_TIMESTAMP
                 WHERE tokenID = ?",
                [$reason, $tokenID],
                'si'
            );

            ActivityLogger::log(
                'USER_OAUTH_TOKEN_DEACTIVATED',
                null,
                json_encode(['tokenID' => $tokenID, 'reason' => $reason])
            );

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
        }
    }

    /**
     * 📝 Record Token Error
     *
     * Records an error for a token (e.g., refresh failure).
     *
     * @param int $tokenID Token ID
     * @param string $error Error message
     */
    private static function recordTokenError(int $tokenID, string $error): void
    {
        try {
            Database::execute(
                "UPDATE tblUserOAuthTokens SET
                 errorCount = errorCount + 1,
                 lastError = ?,
                 lastErrorAt = CURRENT_TIMESTAMP
                 WHERE tokenID = ?",
                [$error, $tokenID],
                'si'
            );

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
        }
    }

    /**
     * 🗑️ Revoke User Token
     *
     * Revokes a user's OAuth token (marks as inactive).
     *
     * @param int $userID User ID
     * @param string $provider Provider name
     * @param string $mailboxEmail Mailbox email address
     * @return bool Success status
     */
    public static function revokeToken(int $userID, string $provider, string $mailboxEmail): bool
    {
        try {
            Database::execute(
                "UPDATE tblUserOAuthTokens SET
                 isActive = FALSE,
                 lastError = 'Revoked by user',
                 lastErrorAt = CURRENT_TIMESTAMP
                 WHERE userID = ? AND provider = ? AND mailboxEmail = ?",
                [$userID, $provider, $mailboxEmail],
                'iss'
            );

            ActivityLogger::log(
                'USER_OAUTH_TOKEN_REVOKED',
                $userID,
                json_encode([
                    'provider' => $provider,
                    'mailboxEmail' => $mailboxEmail
                ])
            );

            return true;

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }

    /**
     * 📋 Get User Tokens
     *
     * Gets all OAuth tokens for a user.
     *
     * @param int $userID User ID
     * @param bool $activeOnly Only return active tokens
     * @return array Array of tokens
     */
    public static function getUserTokens(int $userID, bool $activeOnly = true): array
    {
        try {
            $query = "SELECT tokenID, provider, mailboxEmail, mailboxName, expiresAt,
                             isActive, errorCount, lastUsedAt, createdAt
                      FROM tblUserOAuthTokens
                      WHERE userID = ?";

            if ($activeOnly) {
                $query .= " AND isActive = TRUE";
            }

            $query .= " ORDER BY createdAt DESC";

            return Database::fetchAll($query, [$userID], 'i') ?: [];

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return [];
        }
    }

    /**
     * 🧹 Cleanup Expired Tokens
     *
     * Removes expired tokens with no refresh token.
     *
     * @param int $daysOld Number of days to keep (default: 90)
     * @return int Number of tokens deleted
     */
    public static function cleanupExpiredTokens(int $daysOld = 90): int
    {
        try {
            $query = "
                DELETE FROM tblUserOAuthTokens
                WHERE isActive = FALSE
                  AND expiresAt < DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND (refreshToken IS NULL OR refreshToken = '')
            ";

            $result = Database::execute($query, [$daysOld], 'i');
            $deletedCount = Database::getAffectedRows();

            if ($deletedCount > 0) {
                ActivityLogger::log(
                    'USER_OAUTH_TOKENS_CLEANED',
                    null,
                    json_encode(['deleted' => $deletedCount, 'daysOld' => $daysOld])
                );
            }

            return $deletedCount;

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return 0;
        }
    }
}

// ✅ OAuthTokenManager loaded successfully
