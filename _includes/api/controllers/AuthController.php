<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Authentication API Controller
 * ============================================================================
 *
 * Handles all authentication-related API endpoints.
 *
 * Endpoints:
 * - POST /api/v1/auth/register - User registration
 * - POST /api/v1/auth/login - Email/password login
 * - POST /api/v1/auth/logout - Logout user
 * - POST /api/v1/auth/refresh - Refresh session/token
 * - POST /api/v1/auth/verify-email - Verify email address
 * - POST /api/v1/auth/forgot-password - Request password reset
 * - POST /api/v1/auth/reset-password - Reset password
 * - POST /api/v1/auth/passkey-register - Register PassKey
 * - POST /api/v1/auth/passkey-login - Login with PassKey
 * - POST /api/v1/auth/passwordless-request - Request magic link
 * - POST /api/v1/auth/passwordless-verify - Verify magic link
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
 * Class AuthController
 *
 * Handles authentication API endpoints
 */
class AuthController extends BaseController
{
    /**
     * 📝 POST /api/v1/auth/register
     *
     * Register new user account
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function register(array $params): void
    {
        // ✅ Validate input
        $data = $this->validate([
            'email' => 'required|email|unique:tblUsers,email',
            'password' => 'required|min:8|max:100',
            'displayName' => 'required|min:2|max:100',
            'username' => 'string|min:3|max:50|unique:tblUsers,username',
        ]);

        try {
            // 🔒 Hash password
            $passwordHash = password_hash($data['password'], PASSWORD_ARGON2ID);

            // 🆔 Generate username if not provided
            if (empty($data['username'])) {
                $data['username'] = $this->generateUsername($data['email']);
            }

            // 💾 Create user account
            $query = "
                INSERT INTO tblUsers (
                    email, passwordHash, displayName, username,
                    emailVerified, accountStatus, createdAt, lastLoginAt
                ) VALUES (?, ?, ?, ?, 0, 'active', NOW(), NULL)
            ";

            Database::query(
                $query,
                [
                    $data['email'],
                    $passwordHash,
                    $data['displayName'],
                    $data['username']
                ],
                'ssss'
            );

            $userId = Database::getLastInsertId();

            // 🎫 Generate email verification token
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $tokenQuery = "
                INSERT INTO tblVerificationTokens (
                    userID, tokenHash, tokenType, expiresAt, createdAt
                ) VALUES (?, ?, 'email_verification', ?, NOW())
            ";

            Database::query($tokenQuery, [$userId, $tokenHash, $expiresAt], 'iss');

            // 📧 Send verification email
            $verificationLink = getSetting('app.url', 'https://SIGNula.id')
                . '/api/v1/auth/verify-email?token=' . $token;

            EmailService::sendTemplate(
                $data['email'],
                'email_verification',
                [
                    'displayName' => $data['displayName'],
                    'verificationLink' => $verificationLink,
                ]
            );

            // 📊 Log activity
            ActivityLogger::log($userId, 'registration', 'success', 'User registered via API');

            // ✅ Return success response
            $this->created([
                'user_id' => $userId,
                'email' => $data['email'],
                'display_name' => $data['displayName'],
                'username' => $data['username'],
                'email_verified' => false,
                'message' => 'Verification email sent',
            ], 'Registration successful');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Registration failed', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🔑 POST /api/v1/auth/login
     *
     * Login with email and password
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function login(array $params): void
    {
        // ✅ Validate input
        $data = $this->validate([
            'email' => 'required|email',
            'password' => 'required',
            'remember' => 'boolean',
        ]);

        try {
            // 🔍 Find user by email
            $query = "SELECT * FROM tblUsers WHERE email = ?";
            $result = Database::query($query, [$data['email']], 's');
            $user = $result ? $result->fetch_assoc() : null;

            // ❌ Invalid credentials
            if (!$user || !password_verify($data['password'], $user['passwordHash'])) {
                // 📊 Log failed attempt
                ActivityLogger::logGuest('login_failed', 'failed', 'Invalid credentials for ' . $data['email']);

                sleep(1); // Prevent timing attacks
                $this->unauthorized('Invalid email or password');
                return;
            }

            // 🚫 Check account status
            if ($user['accountStatus'] !== 'active') {
                ActivityLogger::log($user['userID'], 'login_failed', 'failed', 'Account not active');
                $this->forbidden('Account is not active');
                return;
            }

            // ✅ Check if MFA is required
            if ($user['mfaEnabled']) {
                // Generate MFA session token
                $mfaToken = bin2hex(random_bytes(32));
                $mfaTokenHash = hash('sha256', $mfaToken);

                // Store MFA session temporarily
                $_SESSION['mfa_pending'] = [
                    'user_id' => $user['userID'],
                    'token' => $mfaTokenHash,
                    'expires' => time() + 300, // 5 minutes
                ];

                $this->success([
                    'requires_mfa' => true,
                    'mfa_token' => $mfaToken,
                    'message' => 'MFA verification required',
                ], 'MFA required');
                return;
            }

            // ✅ Create session
            $sessionToken = $this->createUserSession($user['userID'], $data['remember'] ?? false);

            // 📊 Update last login
            Database::query(
                "UPDATE tblUsers SET lastLoginAt = NOW() WHERE userID = ?",
                [$user['userID']],
                'i'
            );

            // 📊 Log successful login
            ActivityLogger::log($user['userID'], 'login', 'success', 'Login via API');

            // ✅ Return success response
            $this->success([
                'user_id' => $user['userID'],
                'email' => $user['email'],
                'display_name' => $user['displayName'],
                'username' => $user['username'],
                'session_token' => $sessionToken,
                'requires_mfa' => false,
            ], 'Login successful');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Login failed', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🚪 POST /api/v1/auth/logout
     *
     * Logout current user
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function logout(array $params): void
    {
        $user = $this->requireAuth();

        try {
            // 🗑️ Delete user session
            if (isset($_SESSION['session_token'])) {
                $query = "DELETE FROM tblUserSessions WHERE sessionToken = ?";
                Database::query($query, [$_SESSION['session_token']], 's');
            }

            // 🧹 Clear session
            session_destroy();

            // 📊 Log logout
            ActivityLogger::log($user['userID'], 'logout', 'success', 'Logout via API');

            // ✅ Return success
            $this->success(null, 'Logout successful');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Logout failed', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🔄 POST /api/v1/auth/refresh
     *
     * Refresh session/token
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function refresh(array $params): void
    {
        $user = $this->requireAuth();

        try {
            // 🔄 Extend session
            if (isset($_SESSION['session_token'])) {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+' . (getSetting('security.session.lifetime', 3600) . ' seconds')));

                $query = "UPDATE tblUserSessions SET expiresAt = ? WHERE sessionToken = ?";
                Database::query($query, [$expiresAt, $_SESSION['session_token']], 'ss');
            }

            // ✅ Return refreshed session info
            $this->success([
                'user_id' => $user['userID'],
                'session_extended' => true,
            ], 'Session refreshed');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Session refresh failed', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * ✉️ POST /api/v1/auth/verify-email
     *
     * Verify email address with token
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function verifyEmail(array $params): void
    {
        // ✅ Validate input
        $data = $this->validate([
            'token' => 'required|string',
        ]);

        try {
            $tokenHash = hash('sha256', $data['token']);

            // 🔍 Find valid token
            $query = "
                SELECT * FROM tblVerificationTokens
                WHERE tokenHash = ?
                AND tokenType = 'email_verification'
                AND usedAt IS NULL
                AND expiresAt > NOW()
            ";

            $result = Database::query($query, [$tokenHash], 's');
            $tokenRecord = $result ? $result->fetch_assoc() : null;

            if (!$tokenRecord) {
                $this->error('Invalid or expired verification token', Response::HTTP_BAD_REQUEST);
                return;
            }

            // ✅ Mark email as verified
            Database::query(
                "UPDATE tblUsers SET emailVerified = 1 WHERE userID = ?",
                [$tokenRecord['userID']],
                'i'
            );

            // 🗑️ Mark token as used
            Database::query(
                "UPDATE tblVerificationTokens SET usedAt = NOW() WHERE tokenID = ?",
                [$tokenRecord['tokenID']],
                'i'
            );

            // 📊 Log verification
            ActivityLogger::log($tokenRecord['userID'], 'email_verified', 'success', 'Email verified via API');

            // ✅ Return success
            $this->success([
                'email_verified' => true,
            ], 'Email verified successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Email verification failed', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 📧 POST /api/v1/auth/forgot-password
     *
     * Request password reset email
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function forgotPassword(array $params): void
    {
        // ✅ Validate input
        $data = $this->validate([
            'email' => 'required|email',
        ]);

        try {
            // 🔍 Find user by email
            $query = "SELECT * FROM tblUsers WHERE email = ?";
            $result = Database::query($query, [$data['email']], 's');
            $user = $result ? $result->fetch_assoc() : null;

            // Always return success to prevent email enumeration
            if (!$user) {
                sleep(1); // Timing attack prevention
                $this->success(null, 'If the email exists, a password reset link has been sent');
                return;
            }

            // 🎫 Generate reset token
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // 💾 Store reset token
            $tokenQuery = "
                INSERT INTO tblVerificationTokens (
                    userID, tokenHash, tokenType, expiresAt, createdAt
                ) VALUES (?, ?, 'password_reset', ?, NOW())
            ";

            Database::query($tokenQuery, [$user['userID'], $tokenHash, $expiresAt], 'iss');

            // 📧 Send reset email
            $resetLink = getSetting('app.url', 'https://SIGNula.id')
                . '/auth/reset-password?token=' . $token;

            EmailService::sendTemplate(
                $user['email'],
                'password_reset',
                [
                    'displayName' => $user['displayName'],
                    'resetLink' => $resetLink,
                ]
            );

            // 📊 Log activity
            ActivityLogger::log($user['userID'], 'password_reset_requested', 'success', 'Password reset requested via API');

            // ✅ Return success
            $this->success(null, 'If the email exists, a password reset link has been sent');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Failed to process password reset request', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🔒 POST /api/v1/auth/reset-password
     *
     * Reset password with token
     *
     * @param array $params Route parameters
     * @return void (exits via Response)
     */
    public function resetPassword(array $params): void
    {
        // ✅ Validate input
        $data = $this->validate([
            'token' => 'required|string',
            'password' => 'required|min:8|max:100',
        ]);

        try {
            $tokenHash = hash('sha256', $data['token']);

            // 🔍 Find valid token
            $query = "
                SELECT * FROM tblVerificationTokens
                WHERE tokenHash = ?
                AND tokenType = 'password_reset'
                AND usedAt IS NULL
                AND expiresAt > NOW()
            ";

            $result = Database::query($query, [$tokenHash], 's');
            $tokenRecord = $result ? $result->fetch_assoc() : null;

            if (!$tokenRecord) {
                $this->error('Invalid or expired reset token', Response::HTTP_BAD_REQUEST);
                return;
            }

            // 🔒 Hash new password
            $passwordHash = password_hash($data['password'], PASSWORD_ARGON2ID);

            // ✅ Update password
            Database::query(
                "UPDATE tblUsers SET passwordHash = ? WHERE userID = ?",
                [$passwordHash, $tokenRecord['userID']],
                'si'
            );

            // 🗑️ Mark token as used
            Database::query(
                "UPDATE tblVerificationTokens SET usedAt = NOW() WHERE tokenID = ?",
                [$tokenRecord['tokenID']],
                'i'
            );

            // 🗑️ Invalidate all user sessions for security
            Database::query(
                "DELETE FROM tblUserSessions WHERE userID = ?",
                [$tokenRecord['userID']],
                'i'
            );

            // 📊 Log password change
            ActivityLogger::log($tokenRecord['userID'], 'password_changed', 'success', 'Password reset via API');

            // ✅ Return success
            $this->success([
                'password_reset' => true,
            ], 'Password reset successfully');

        } catch (Exception $e) {
            ErrorLogger::log($e);
            $this->error('Password reset failed', Response::HTTP_INTERNAL_ERROR);
        }
    }

    /**
     * 🆔 Generate unique username from email
     *
     * @param string $email Email address
     * @return string Generated username
     */
    private function generateUsername(string $email): string
    {
        $base = explode('@', $email)[0];
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower($base));
        $username = $base;
        $counter = 1;

        // Ensure uniqueness
        while ($this->usernameExists($username)) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * 🔍 Check if username exists
     *
     * @param string $username Username to check
     * @return bool True if exists
     */
    private function usernameExists(string $username): bool
    {
        $query = "SELECT COUNT(*) as count FROM tblUsers WHERE username = ?";
        $result = Database::query($query, [$username], 's');

        if ($result && $row = $result->fetch_assoc()) {
            return $row['count'] > 0;
        }

        return false;
    }

    /**
     * 🎫 Create user session
     *
     * @param int $userId User ID
     * @param bool $remember Remember me flag
     * @return string Session token
     */
    private function createUserSession(int $userId, bool $remember = false): string
    {
        $sessionToken = bin2hex(random_bytes(32));
        $lifetime = $remember ? 2592000 : (int) getSetting('security.session.lifetime', 3600); // 30 days or config
        $expiresAt = date('Y-m-d H:i:s', time() + $lifetime);

        $query = "
            INSERT INTO tblUserSessions (
                userID, sessionToken, ipAddress, userAgent, expiresAt, createdAt
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ";

        Database::query(
            $query,
            [
                $userId,
                hash('sha256', $sessionToken),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                $expiresAt
            ],
            'issss'
        );

        // Set session variables
        $_SESSION['user_id'] = $userId;
        $_SESSION['session_token'] = hash('sha256', $sessionToken);

        return $sessionToken;
    }
}

// ✅ AuthController loaded successfully
