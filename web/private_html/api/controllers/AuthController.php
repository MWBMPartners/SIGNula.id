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

        // 🔒 Enforce the SAME password policy gate the web registration flow
        // uses (Auth::register() -> SecurityUtils::validatePassword()). The
        // `validate()` rule above only checks length bounds (min:8|max:100);
        // without this call the API could be used to set a weaker password
        // (shorter, no complexity, common/breached) than the browser allows.
        // @see web/private_html/security/SecurityUtils.php::validatePassword()
        $passwordCheck = SecurityUtils::validatePassword($data['password']);
        if (!$passwordCheck['valid']) {
            Response::validationError('Password does not meet security policy', $passwordCheck['errors']);
            return;
        }

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
            // 🔒 B-039: rawurlencode() the token in the query string (hardening;
            //    a no-op for the current hex tokens, safe against future formats,
            //    no double-encoding since hex is unchanged by rawurlencode()).
            $verificationLink = getSetting('app.url', 'https://SIGNula.id')
                . '/api/v1/auth/verify-email?token=' . rawurlencode($token);

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

        // 🚦 Rate-limit BEFORE any credential work (Finding MED-4). Mirrors
        // JwtAuthController::enforceIssueRateLimit() — the SAME
        // SecurityUtils::checkRateLimit() gate, keyed on IP AND on the
        // supplied email, so this endpoint isn't left relying solely on the
        // coarse, IP/endpoint-tier global RateLimitMiddleware for brute-force
        // defence.
        // @see web/private_html/api/controllers/JwtAuthController.php::enforceIssueRateLimit()
        $this->enforceLoginRateLimit($data['email']);

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

        // 🚦 Rate-limit BEFORE any DB work (Finding MED-4). Same pattern as
        // login() — keeps this endpoint from being used as an unthrottled
        // email-enumeration / mail-bombing oracle beyond the coarse global
        // RateLimitMiddleware.
        // @see web/private_html/api/controllers/JwtAuthController.php::enforceIssueRateLimit()
        $this->enforceForgotPasswordRateLimit($data['email']);

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
            // 🔒 B-039: rawurlencode() the token in the query string (hardening;
            //    no-op for current hex tokens, safe against future formats, no
            //    double-encoding since hex is unchanged by rawurlencode()).
            $resetLink = getSetting('app.url', 'https://SIGNula.id')
                . '/auth/reset-password?token=' . rawurlencode($token);

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

        // 🔒 Enforce the SAME password policy gate the web reset flow uses
        // (web/public_html/reset-password.php -> SecurityUtils::validatePassword()).
        // The `validate()` rule above only checks length bounds (min:8|max:100);
        // without this call a client could reset an account to a weaker
        // password than the browser flow allows.
        // @see web/private_html/security/SecurityUtils.php::validatePassword()
        $passwordCheck = SecurityUtils::validatePassword($data['password']);
        if (!$passwordCheck['valid']) {
            Response::validationError('Password does not meet security policy', $passwordCheck['errors']);
            return;
        }

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

    // ========================================================================
    // 🚦 RATE LIMITING (Finding MED-4)
    // ========================================================================
    // /api/v1/auth/login and /api/v1/auth/forgot-password previously relied
    // solely on the coarse, IP/endpoint-tier RateLimitMiddleware applied
    // globally in v1/index.php — no per-account lockout escalation, unlike
    // JwtAuthController (per-IP AND per-identifier) or the web Auth::login()
    // flow (account lockout). These two helpers close that gap using the
    // SAME SecurityUtils::checkRateLimit() gate JwtAuthController uses, so
    // the layers compound rather than conflict.
    // @see web/private_html/api/controllers/JwtAuthController.php::enforceIssueRateLimit()

    /**
     * 🚦 Rate-limit /api/v1/auth/login by IP and by the supplied email.
     *
     * Keyed on IP AND on 'id:'.strtolower($email) so neither a single IP
     * spraying many accounts nor many IPs pounding one account slip past
     * this limiter. Additive — does not replace the global limiter.
     *
     * @param string $email Email address supplied in the login request.
     * @return void (emits 429 + Retry-After and exits on breach)
     */
    private function enforceLoginRateLimit(string $email): void
    {
        $ip         = getClientIP();
        $maxPerIp   = (int) getSetting('api.rate_limit.login_per_ip', 20);
        $maxPerId   = (int) getSetting('api.rate_limit.login_per_identifier', 10);
        $windowSecs = (int) getSetting('api.rate_limit.window_seconds', 900); // 15 min

        // Per-IP.
        if (!SecurityUtils::checkRateLimit($ip, 'api_login', $maxPerIp, $windowSecs)) {
            $this->rateLimited($windowSecs, 'Too many login attempts. Please try again later.');
            return;
        }

        // Per-identifier (only when an email was actually supplied).
        if ($email !== ''
            && !SecurityUtils::checkRateLimit('id:' . strtolower($email), 'api_login', $maxPerId, $windowSecs)) {
            $this->rateLimited($windowSecs, 'Too many login attempts. Please try again later.');
            return;
        }
    }

    /**
     * 🚦 Rate-limit /api/v1/auth/forgot-password by IP and by the supplied email.
     *
     * Same pattern as enforceLoginRateLimit() — protects against using this
     * endpoint as an unthrottled email-enumeration / mail-bombing oracle.
     *
     * @param string $email Email address supplied in the forgot-password request.
     * @return void (emits 429 + Retry-After and exits on breach)
     */
    private function enforceForgotPasswordRateLimit(string $email): void
    {
        $ip         = getClientIP();
        $maxPerIp   = (int) getSetting('api.rate_limit.forgot_password_per_ip', 20);
        $maxPerId   = (int) getSetting('api.rate_limit.forgot_password_per_identifier', 5);
        $windowSecs = (int) getSetting('api.rate_limit.window_seconds', 900); // 15 min

        // Per-IP.
        if (!SecurityUtils::checkRateLimit($ip, 'api_forgot_password', $maxPerIp, $windowSecs)) {
            $this->rateLimited($windowSecs, 'Too many password reset requests. Please try again later.');
            return;
        }

        // Per-identifier (only when an email was actually supplied).
        if ($email !== ''
            && !SecurityUtils::checkRateLimit('id:' . strtolower($email), 'api_forgot_password', $maxPerId, $windowSecs)) {
            $this->rateLimited($windowSecs, 'Too many password reset requests. Please try again later.');
            return;
        }
    }

    /**
     * ⏱️ Emit a 429 with Retry-After and exit.
     *
     * @param int $retryAfter Seconds hint.
     * @param string $message Error message.
     * @return void (exits via Response)
     */
    private function rateLimited(int $retryAfter, string $message = 'Too many requests. Please try again later.'): void
    {
        Response::rateLimitExceeded($message, $retryAfter);
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
        // 🐛 B-065: camelCase alias so a browser that authenticates through the
        // API and then navigates to a settings/*.php page (which read
        // `$_SESSION['userID']`) resolves the same user. Additive — the
        // snake_case `user_id` every other reader uses is left untouched.
        $_SESSION['userID'] = $userId;
        $_SESSION['session_token'] = hash('sha256', $sessionToken);

        return $sessionToken;
    }
}

// ✅ AuthController loaded successfully
