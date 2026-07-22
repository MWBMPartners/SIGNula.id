<?php
/**
 * ============================================================================
 * 👤 SIGNula - User Management API Controller
 * ============================================================================
 *
 * Handles user profile and account management endpoints.
 *
 * Endpoints:
 * - GET /api/v1/user/profile - Get user profile (includes avatar_url)
 * - PUT /api/v1/user/profile - Update user profile
 * - GET /api/v1/user/sessions - Get active sessions
 * - DELETE /api/v1/user/session/{id} - Terminate session
 * - GET /api/v1/user/activity - Get activity log
 * - GET /api/v1/user/preferences - Get preferences
 * - PUT /api/v1/user/preferences - Update preferences
 * - POST /api/v1/user/change-password - Change password
 * - POST /api/v1/user/change-email - Change email address
 * - DELETE /api/v1/user/account - Delete account
 * - POST /api/v1/user/avatar - Upload avatar (multipart/form-data)
 * - DELETE /api/v1/user/avatar - Remove avatar
 * - PUT /api/v1/user/avatar/source - Set avatar source (OAuth)
 * - GET /api/v1/user/avatar/sources - Get available avatar sources
 * - PUT /api/v1/user/avatar/fallback-priority - Update fallback order
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
 * Class UserController
 *
 * Handles user management API endpoints
 */
class UserController extends BaseController
{
    /**
     * 📋 GET /api/v1/user/profile
     *
     * Get current user's profile
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function getProfile(array $params): void
    {
        $user = $this->requireAuth();

        try {
            // 🔍 Get complete user profile
            $query = "
                SELECT
                    userID, email, displayName, username, timezone,
                    emailVerified, mfaEnabled, webauthnEnabled,
                    passwordlessEnabled, accountStatus,
                    DATE_FORMAT(createdAt, '%Y-%m-%dT%H:%i:%sZ') as created_at,
                    DATE_FORMAT(lastLoginAt, '%Y-%m-%dT%H:%i:%sZ') as last_login_at
                FROM tblUsers
                WHERE userID = ?
            ";

            $result = Database::query($query, [$user['userID']], 'i');
            $profile = $result ? $result->fetch_assoc() : null;

            if (!$profile) {
                $this->notFound('User profile not found');
                return;
            }

            // 🖼️ Add avatar URLs if AvatarService is available
            if (class_exists('AvatarService')) {
                $profile['avatar_url'] = AvatarService::resolve($user['userID'], null, 96);
                $profile['avatar_urls'] = [
                    'small'  => AvatarService::resolve($user['userID'], null, 32),
                    'medium' => AvatarService::resolve($user['userID'], null, 96),
                    'large'  => AvatarService::resolve($user['userID'], null, 256),
                ];
            }

            // 🔢 Get additional stats
            $stats = $this->getUserStats($user['userID']);

            $this->success([
                'profile' => $profile,
                'stats' => $stats,
            ], 'Profile retrieved successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to retrieve profile', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * ✏️ PUT /api/v1/user/profile
     *
     * Update user profile
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function updateProfile(array $params): void
    {
        $user = $this->requireAuth();

        // ✅ Validate input
        $data = $this->validate([
            'displayName' => 'string|min:2|max:100',
            'username' => 'string|min:3|max:50',
            'timezone' => 'string',
        ]);

        try {
            $updates = [];
            $values = [];
            $types = '';

            // Build dynamic update query
            if (isset($data['displayName'])) {
                $updates[] = 'displayName = ?';
                $values[] = $data['displayName'];
                $types .= 's';
            }

            if (isset($data['username'])) {
                // Check username uniqueness
                if ($this->usernameExists($data['username'], $user['userID'])) {
                    $this->error('Username already exists', Response::HTTP_CONFLICT);
                    return;
                }

                $updates[] = 'username = ?';
                $values[] = $data['username'];
                $types .= 's';
            }

            if (isset($data['timezone'])) {
                $updates[] = 'timezone = ?';
                $values[] = $data['timezone'];
                $types .= 's';
            }

            if (empty($updates)) {
                $this->error('No valid fields to update', Response::HTTP_BAD_REQUEST);
                return;
            }

            // Add userID for WHERE clause
            $values[] = $user['userID'];
            $types .= 'i';

            // 💾 Update profile
            $query = "UPDATE tblUsers SET " . implode(', ', $updates) . " WHERE userID = ?";
            Database::query($query, $values, $types);

            // 📊 Log activity
            ActivityLogger::log($user['userID'], 'profile_updated', 'success', 'Profile updated via API');

            // ✅ Return updated profile
            $this->getProfile($params);

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to update profile', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🖥️ GET /api/v1/user/sessions
     *
     * Get all active sessions
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function getSessions(array $params): void
    {
        $user = $this->requireAuth();

        try {
            $query = "
                SELECT
                    sessionID,
                    ipAddress,
                    userAgent,
                    DATE_FORMAT(createdAt, '%Y-%m-%dT%H:%i:%sZ') as created_at,
                    DATE_FORMAT(lastActivityAt, '%Y-%m-%dT%H:%i:%sZ') as last_activity_at,
                    DATE_FORMAT(expiresAt, '%Y-%m-%dT%H:%i:%sZ') as expires_at,
                    CASE
                        WHEN sessionToken = ? THEN 1
                        ELSE 0
                    END as is_current
                FROM tblUserSessions
                WHERE userID = ?
                AND expiresAt > NOW()
                ORDER BY lastActivityAt DESC
            ";

            $currentSessionToken = $_SESSION['session_token'] ?? '';

            $result = Database::query($query, [$currentSessionToken, $user['userID']], 'si');
            $sessions = [];

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $sessions[] = [
                        'session_id' => $row['sessionID'],
                        'ip_address' => $row['ipAddress'],
                        'user_agent' => $row['userAgent'],
                        'created_at' => $row['created_at'],
                        'last_activity_at' => $row['last_activity_at'],
                        'expires_at' => $row['expires_at'],
                        'is_current' => (bool) $row['is_current'],
                    ];
                }
            }

            $this->success([
                'sessions' => $sessions,
                'total' => count($sessions),
            ], 'Sessions retrieved successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to retrieve sessions', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🗑️ DELETE /api/v1/user/session/{id}
     *
     * Terminate a specific session
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function deleteSession(array $params): void
    {
        $user = $this->requireAuth();
        $sessionId = $params['id'] ?? null;

        if (!$sessionId) {
            $this->error('Session ID required', Response::HTTP_BAD_REQUEST);
            return;
        }

        try {
            // 🗑️ Delete session (only if it belongs to the user)
            $query = "DELETE FROM tblUserSessions WHERE sessionID = ? AND userID = ?";
            $result = Database::query($query, [$sessionId, $user['userID']], 'ii');

            if (Database::getAffectedRows() === 0) {
                $this->notFound('Session not found or already terminated');
                return;
            }

            // 📊 Log activity
            ActivityLogger::log($user['userID'], 'session_terminated', 'success', "Session $sessionId terminated via API");

            $this->success(null, 'Session terminated successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to terminate session', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 📊 GET /api/v1/user/activity
     *
     * Get user activity log
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function getActivity(array $params): void
    {
        $user = $this->requireAuth();

        try {
            // Get filter parameters
            $activityType = $this->query('type');
            $result = $this->query('result');
            $fromDate = $this->query('from_date');
            $toDate = $this->query('to_date');
            $limit = min(100, max(1, (int) $this->query('limit', 25)));
            $page = max(1, (int) $this->query('page', 1));

            // Build query
            $where = ['userID = ?'];
            $params = [$user['userID']];
            $types = 'i';

            if ($activityType) {
                $where[] = 'activityType = ?';
                $params[] = $activityType;
                $types .= 's';
            }

            if ($result) {
                $where[] = 'result = ?';
                $params[] = $result;
                $types .= 's';
            }

            if ($fromDate) {
                $where[] = 'loggedAt >= ?';
                $params[] = $fromDate;
                $types .= 's';
            }

            if ($toDate) {
                $where[] = 'loggedAt <= ?';
                $params[] = $toDate;
                $types .= 's';
            }

            $whereClause = implode(' AND ', $where);

            $query = "
                SELECT
                    activityLogID,
                    activityType,
                    result,
                    details,
                    ipAddress,
                    userAgent,
                    DATE_FORMAT(loggedAt, '%Y-%m-%dT%H:%i:%sZ') as logged_at
                FROM tblActivityLog
                WHERE $whereClause
                ORDER BY loggedAt DESC
            ";

            // Paginate results
            $pagination = $this->paginate($query, $params, $types, $page, $limit);

            $this->sendPaginated(
                $pagination['items'],
                $pagination['total'],
                $pagination['page'],
                $pagination['per_page'],
                'Activity log retrieved successfully'
            );

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to retrieve activity log', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * ⚙️ GET /api/v1/user/preferences
     *
     * Get user preferences
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function getPreferences(array $params): void
    {
        $user = $this->requireAuth();

        try {
            $query = "SELECT preferences FROM tblUserPreferences WHERE userID = ?";
            $result = Database::query($query, [$user['userID']], 'i');

            $preferences = [];

            if ($result && $row = $result->fetch_assoc()) {
                $preferences = json_decode($row['preferences'], true) ?? [];
            }

            $this->success([
                'preferences' => $preferences,
            ], 'Preferences retrieved successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to retrieve preferences', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * ⚙️ PUT /api/v1/user/preferences
     *
     * Update user preferences
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function updatePreferences(array $params): void
    {
        $user = $this->requireAuth();

        $preferences = $this->input();

        if (!is_array($preferences)) {
            $this->error('Invalid preferences data', Response::HTTP_BAD_REQUEST);
            return;
        }

        try {
            $preferencesJson = json_encode($preferences);

            $query = "
                INSERT INTO tblUserPreferences (userID, preferences, updatedAt)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    preferences = VALUES(preferences),
                    updatedAt = NOW()
            ";

            Database::query($query, [$user['userID'], $preferencesJson], 'is');

            // 📊 Log activity
            ActivityLogger::log($user['userID'], 'preferences_updated', 'success', 'Preferences updated via API');

            $this->success([
                'preferences' => $preferences,
            ], 'Preferences updated successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to update preferences', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🔒 POST /api/v1/user/change-password
     *
     * Change user password
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function changePassword(array $params): void
    {
        $user = $this->requireAuth();

        // ✅ Validate input
        $data = $this->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8|max:100',
        ]);

        // 🔒 Enforce the SAME password policy gate the web change-password flow
        // uses (Auth::changePassword() -> SecurityUtils::validatePassword()).
        // The `validate()` rule above only checks length bounds (min:8|max:100);
        // without this call the API could be used to set a weaker new password
        // than the browser flow allows.
        // @see web/private_html/security/SecurityUtils.php::validatePassword()
        $passwordCheck = SecurityUtils::validatePassword($data['new_password']);
        if (!$passwordCheck['valid']) {
            Response::validationError('Password does not meet security policy', $passwordCheck['errors']);
            return;
        }

        try {
            // 🔍 Verify current password
            if (!password_verify($data['current_password'], $user['passwordHash'])) {
                $this->error('Current password is incorrect', Response::HTTP_UNAUTHORIZED);
                return;
            }

            // ❌ Prevent using same password
            if (password_verify($data['new_password'], $user['passwordHash'])) {
                $this->error('New password must be different from current password', Response::HTTP_BAD_REQUEST);
                return;
            }

            // 🔒 Hash new password
            $newPasswordHash = password_hash($data['new_password'], PASSWORD_ARGON2ID);

            // 💾 Update password
            Database::query(
                "UPDATE tblUsers SET passwordHash = ? WHERE userID = ?",
                [$newPasswordHash, $user['userID']],
                'si'
            );

            // 📊 Log activity
            ActivityLogger::log($user['userID'], 'password_changed', 'success', 'Password changed via API');

            $this->success(null, 'Password changed successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to change password', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 📧 POST /api/v1/user/change-email
     *
     * Change user email address
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function changeEmail(array $params): void
    {
        $user = $this->requireAuth();

        // ✅ Validate input
        $data = $this->validate([
            'new_email' => 'required|email|unique:tblUsers,email',
            'password' => 'required',
        ]);

        try {
            // 🔍 Verify password
            if (!password_verify($data['password'], $user['passwordHash'])) {
                $this->error('Password is incorrect', Response::HTTP_UNAUTHORIZED);
                return;
            }

            // 💾 Update email and set as unverified
            Database::query(
                "UPDATE tblUsers SET email = ?, emailVerified = 0 WHERE userID = ?",
                [$data['new_email'], $user['userID']],
                'si'
            );

            // 🎫 Generate verification token
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $tokenQuery = "
                INSERT INTO tblVerificationTokens (
                    userID, tokenHash, tokenType, expiresAt, createdAt
                ) VALUES (?, ?, 'email_verification', ?, NOW())
            ";

            Database::query($tokenQuery, [$user['userID'], $tokenHash, $expiresAt], 'iss');

            // 📧 Send verification email
            // 🔒 B-039: rawurlencode() the token in the query string. Current
            //    tokens are hex (bin2hex → URL-safe, so a no-op today) but this
            //    hardens the link against any future token format containing
            //    URL-reserved characters. No double-encoding risk — hex passes
            //    through rawurlencode() unchanged.
            $verificationLink = getSetting('app.url', 'https://SIGNula.id')
                . '/api/v1/auth/verify-email?token=' . rawurlencode($token);

            EmailService::sendTemplate(
                $data['new_email'],
                'email_verification',
                [
                    'displayName' => $user['displayName'],
                    'verificationLink' => $verificationLink,
                ]
            );

            // 📊 Log activity
            ActivityLogger::log($user['userID'], 'email_changed', 'success', 'Email changed to ' . $data['new_email']);

            $this->success([
                'new_email' => $data['new_email'],
                'email_verified' => false,
                'verification_sent' => true,
            ], 'Email changed successfully. Please verify your new email address.');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to change email', Response::HTTP_INTERNAL_ERROR);
        }
    }

    // ============================================================================
    // 🖼️ AVATAR / PROFILE PICTURE ENDPOINTS
    // ============================================================================

    /**
     * 📤 POST /api/v1/user/avatar
     *
     * Upload a new profile picture via multipart/form-data.
     * Expects a 'file' field with the image.
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function uploadAvatar(array $params): void
    {
        $user = $this->requireAuth();

        if (!class_exists('AvatarService')) {
            $this->error('Avatar service unavailable', Response::HTTP_INTERNAL_ERROR);
            return;
        }

        try {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
                $this->error('No image file provided. Send a multipart/form-data request with a "file" field.', Response::HTTP_BAD_REQUEST);
                return;
            }

            $result = AvatarService::uploadAvatar($user['userID'], $_FILES['file']);

            if ($result['success']) {
                $this->success([
                    'avatar_url' => AvatarService::resolve($user['userID'], null, 96),
                    'avatar_urls' => [
                        'small'  => AvatarService::resolve($user['userID'], null, 32),
                        'medium' => AvatarService::resolve($user['userID'], null, 96),
                        'large'  => AvatarService::resolve($user['userID'], null, 256),
                    ],
                ], 'Avatar uploaded successfully');
            } else {
                $this->error($result['error'] ?? 'Failed to upload avatar', Response::HTTP_BAD_REQUEST);
            }

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to upload avatar', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🗑️ DELETE /api/v1/user/avatar
     *
     * Remove the current uploaded/custom avatar, reverting to fallback chain.
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function deleteAvatar(array $params): void
    {
        $user = $this->requireAuth();

        if (!class_exists('AvatarService')) {
            $this->error('Avatar service unavailable', Response::HTTP_INTERNAL_ERROR);
            return;
        }

        try {
            $deleted = AvatarService::deleteAvatar($user['userID']);

            if ($deleted) {
                $this->success([
                    'avatar_url' => AvatarService::resolve($user['userID'], null, 96),
                ], 'Avatar removed successfully. Fallback avatar will be used.');
            } else {
                $this->success([
                    'avatar_url' => AvatarService::resolve($user['userID'], null, 96),
                ], 'No custom avatar to remove');
            }

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to delete avatar', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🔄 PUT /api/v1/user/avatar/source
     *
     * Set avatar source (e.g., use an OAuth provider's picture).
     * Body: { "source": "oauth", "oauth_account_id": 123 }
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function setAvatarSource(array $params): void
    {
        $user = $this->requireAuth();

        if (!class_exists('AvatarService')) {
            $this->error('Avatar service unavailable', Response::HTTP_INTERNAL_ERROR);
            return;
        }

        $data = $this->input();
        $source = $data['source'] ?? '';

        try {
            if ($source === 'oauth') {
                $oauthAccountID = (int) ($data['oauth_account_id'] ?? 0);
                if ($oauthAccountID <= 0) {
                    $this->error('Valid oauth_account_id is required', Response::HTTP_BAD_REQUEST);
                    return;
                }

                $result = AvatarService::setOAuthAsAvatar($user['userID'], $oauthAccountID);

                if ($result['success']) {
                    $this->success([
                        'avatar_url' => AvatarService::resolve($user['userID'], null, 96),
                    ], 'Avatar set from OAuth provider');
                } else {
                    $this->error($result['error'] ?? 'Failed to set avatar source', Response::HTTP_BAD_REQUEST);
                }
            } else {
                $this->error('Invalid source. Supported: "oauth"', Response::HTTP_BAD_REQUEST);
            }

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to set avatar source', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 📋 GET /api/v1/user/avatar/sources
     *
     * Get available avatar sources for the current user
     * (uploads, OAuth accounts, fallback services).
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function getAvatarSources(array $params): void
    {
        $user = $this->requireAuth();

        if (!class_exists('AvatarService')) {
            $this->error('Avatar service unavailable', Response::HTTP_INTERNAL_ERROR);
            return;
        }

        try {
            // 📸 Current avatar
            $currentAvatar = AvatarService::resolve($user['userID'], null, 96);

            // 🗄️ Check for uploaded avatar
            $uploadedAvatar = Database::fetchOne(
                "SELECT avatarID, avatarType, avatarURL, createdAt
                 FROM tblUserAvatars
                 WHERE userID = ? AND partnerID IS NULL AND isActive = 1",
                [$user['userID']],
                'i'
            );

            // 🔗 OAuth accounts with profile pictures
            $oauthAccounts = Database::fetchAll(
                "SELECT oauthAccountID, provider, profilePicture, displayName, isPrimary
                 FROM tblOAuthAccounts
                 WHERE userID = ? AND isActive = 1 AND profilePicture IS NOT NULL AND profilePicture != ''
                 ORDER BY isPrimary DESC, provider ASC",
                [$user['userID']],
                'i'
            ) ?? [];

            // ⚙️ Fallback services and priority
            $fallbackPriority = AvatarService::getUserFallbackPriority($user['userID']);

            $this->success([
                'current_avatar_url' => $currentAvatar,
                'uploaded_avatar' => $uploadedAvatar ? [
                    'avatar_id' => $uploadedAvatar['avatarID'],
                    'type' => $uploadedAvatar['avatarType'],
                    'url' => $uploadedAvatar['avatarURL'],
                    'created_at' => $uploadedAvatar['createdAt'],
                ] : null,
                'oauth_accounts' => array_map(function ($account) {
                    return [
                        'oauth_account_id' => $account['oauthAccountID'],
                        'provider' => $account['provider'],
                        'profile_picture' => $account['profilePicture'],
                        'display_name' => $account['displayName'],
                        'is_primary' => (bool) $account['isPrimary'],
                    ];
                }, $oauthAccounts),
                'fallback_priority' => $fallbackPriority,
                'available_fallback_services' => ['gravatar', 'libravatar', 'ui_avatars', 'dicebear', 'robohash'],
            ], 'Avatar sources retrieved successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to retrieve avatar sources', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * ⚙️ PUT /api/v1/user/avatar/fallback-priority
     *
     * Update the fallback avatar service priority order.
     * Body: { "priority": ["gravatar", "ui_avatars", "dicebear", "libravatar", "robohash"] }
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function updateFallbackPriority(array $params): void
    {
        $user = $this->requireAuth();

        if (!class_exists('AvatarService')) {
            $this->error('Avatar service unavailable', Response::HTTP_INTERNAL_ERROR);
            return;
        }

        $data = $this->input();
        $priority = $data['priority'] ?? null;

        if (!is_array($priority)) {
            $this->error('priority must be an array of service names', Response::HTTP_BAD_REQUEST);
            return;
        }

        // ✅ Validate service names
        $validServices = ['gravatar', 'libravatar', 'ui_avatars', 'dicebear', 'robohash'];
        foreach ($priority as $service) {
            if (!in_array($service, $validServices, true)) {
                $this->error("Invalid service: {$service}. Valid: " . implode(', ', $validServices), Response::HTTP_BAD_REQUEST);
                return;
            }
        }

        try {
            AvatarService::setUserFallbackPriority($user['userID'], $priority);

            $this->success([
                'fallback_priority' => $priority,
            ], 'Fallback priority updated successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to update fallback priority', Response::HTTP_INTERNAL_ERROR);
        }
    }

    // ============================================================================
    // 🔧 PRIVATE HELPER METHODS
    // ============================================================================

    /**
     * 🔍 Check if username exists (excluding current user)
     *
     * @param string $username Username to check
     * @param int $excludeUserId User ID to exclude
     * @return bool True if exists
     */
    private function usernameExists(string $username, int $excludeUserId): bool
    {
        $query = "SELECT COUNT(*) as count FROM tblUsers WHERE username = ? AND userID != ?";
        $result = Database::query($query, [$username, $excludeUserId], 'si');

        if ($result && $row = $result->fetch_assoc()) {
            return $row['count'] > 0;
        }

        return false;
    }

    /**
     * 📊 Get user statistics
     *
     * @param int $userId User ID
     * @return array Statistics
     */
    private function getUserStats(int $userId): array
    {
        $stats = [
            'passkeys_count' => 0,
            'oauth_accounts_count' => 0,
            'active_sessions_count' => 0,
            'total_activities_count' => 0,
        ];

        try {
            // PassKeys count
            $result = Database::query(
                "SELECT COUNT(*) as count FROM tblWebAuthnCredentials WHERE userID = ?",
                [$userId],
                'i'
            );
            if ($result && $row = $result->fetch_assoc()) {
                $stats['passkeys_count'] = (int) $row['count'];
            }

            // OAuth accounts count
            $result = Database::query(
                "SELECT COUNT(*) as count FROM tblOAuthAccounts WHERE userID = ?",
                [$userId],
                'i'
            );
            if ($result && $row = $result->fetch_assoc()) {
                $stats['oauth_accounts_count'] = (int) $row['count'];
            }

            // Active sessions count
            $result = Database::query(
                "SELECT COUNT(*) as count FROM tblUserSessions WHERE userID = ? AND expiresAt > NOW()",
                [$userId],
                'i'
            );
            if ($result && $row = $result->fetch_assoc()) {
                $stats['active_sessions_count'] = (int) $row['count'];
            }

            // Total activities count
            $result = Database::query(
                "SELECT COUNT(*) as count FROM tblActivityLog WHERE userID = ?",
                [$userId],
                'i'
            );
            if ($result && $row = $result->fetch_assoc()) {
                $stats['total_activities_count'] = (int) $row['count'];
            }

        } catch (Exception $e) {
            ErrorLogger::log($e);
        }

        return $stats;
    }
}

// ✅ UserController loaded successfully
