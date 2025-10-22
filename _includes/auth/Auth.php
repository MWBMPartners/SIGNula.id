<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Authentication System
 * ============================================================================
 *
 * Purpose: Core authentication and user management
 * PHP Version: 8.3+
 *
 * Features:
 * - User registration and login
 * - Password and passwordless authentication
 * - Session management
 * - Account lockout protection
 * - Email verification
 * - Password reset
 *
 * @package    SIGNula
 * @subpackage Authentication
 * @version    1.0.0
 * @link       https://signulo.id
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🔐 Authentication Class
 *
 * Handles all authentication-related operations including login,
 * registration, password reset, and session management.
 */
class Auth
{
    /**
     * @var int|null Currently authenticated user ID
     */
    private static ?int $currentUserID = null;

    /**
     * @var array|null Currently authenticated user data
     */
    private static ?array $currentUser = null;

    // ========================================================================
    // 👤 USER REGISTRATION
    // ========================================================================

    /**
     * 📝 Register New User
     *
     * Creates new user account with email verification.
     *
     * @param string $email Email address
     * @param string $username Username
     * @param string $password Password (optional for passwordless accounts)
     * @param array $additionalData Additional user data
     * @return array Registration result ['success' => bool, 'userID' => int|null, 'message' => string]
     *
     * @example
     * ```php
     * $result = Auth::register('user@example.com', 'username', 'password123');
     * ```
     */
    public static function register(
        string $email,
        string $username,
        string $password = '',
        array $additionalData = []
    ): array {
        try {
            // 🧹 Sanitize inputs
            $email = SecurityUtils::sanitizeEmail($email);
            $username = SecurityUtils::sanitizeString($username);

            // ✅ Validate inputs
            if (!$email) {
                return ['success' => false, 'userID' => null, 'message' => 'Invalid email address'];
            }

            if (strlen($username) < 3 || strlen($username) > 30) {
                return ['success' => false, 'userID' => null, 'message' => 'Username must be between 3 and 30 characters'];
            }

            // Check if email already exists
            $existingUser = Database::fetchOne(
                "SELECT userID FROM tblUsers WHERE email = ?",
                [$email],
                's'
            );

            if ($existingUser) {
                return ['success' => false, 'userID' => null, 'message' => 'Email address already registered'];
            }

            // Check if username already exists
            $existingUsername = Database::fetchOne(
                "SELECT userID FROM tblUsers WHERE username = ?",
                [$username],
                's'
            );

            if ($existingUsername) {
                return ['success' => false, 'userID' => null, 'message' => 'Username already taken'];
            }

            // 🔒 Validate and hash password (if provided)
            $passwordHash = null;
            if (!empty($password)) {
                $validation = SecurityUtils::validatePassword($password);
                if (!$validation['valid']) {
                    return [
                        'success' => false,
                        'userID' => null,
                        'message' => implode(', ', $validation['errors'])
                    ];
                }

                $passwordHash = SecurityUtils::hashPassword($password);
            }

            // 🆔 Generate UUID for user
            $userUUID = self::generateUUID();

            // 🗄️ Begin transaction
            Database::beginTransaction();

            // 📝 Insert user
            $query = "
                INSERT INTO tblUsers (
                    userUUID, username, email, passwordHash,
                    firstName, lastName, displayName,
                    accountStatus, accountTier, createdAt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'free', NOW())
            ";

            Database::query($query, [
                $userUUID,
                $username,
                $email,
                $passwordHash,
                $additionalData['firstName'] ?? null,
                $additionalData['lastName'] ?? null,
                $additionalData['displayName'] ?? $username,
            ], 'sssssss');

            $userID = Database::getLastInsertId();

            // 📧 Generate email verification token
            $verificationToken = SecurityUtils::generateToken();
            $verificationCode = SecurityUtils::generateNumericCode(6);

            $tokenQuery = "
                INSERT INTO tblVerificationTokens (
                    userID, token, tokenType, email, code,
                    ipAddress, userAgent, expiresAt
                ) VALUES (?, ?, 'email_verification', ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
            ";

            Database::query($tokenQuery, [
                $userID,
                $verificationToken,
                $email,
                $verificationCode,
                getClientIP(),
                getUserAgent()
            ], 'isssss');

            // 📝 Log activity
            ActivityLogger::log($userID, 'registration', 'auth', 'info', 'New user registered', [
                'email' => $email,
                'username' => $username
            ]);

            // ✅ Commit transaction
            Database::commit();

            // 📧 Send verification email
            EmailService::sendVerificationEmail($email, $verificationToken, $verificationCode);

            return [
                'success' => true,
                'userID' => $userID,
                'message' => 'Registration successful. Please check your email to verify your account.'
            ];

        } catch (Exception $e) {
            // ↩️ Rollback on error
            if (Database::isConnected()) {
                Database::rollback();
            }

            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine(), [
                'email' => $email ?? 'unknown',
                'username' => $username ?? 'unknown'
            ]);

            return [
                'success' => false,
                'userID' => null,
                'message' => 'Registration failed. Please try again later.'
            ];
        }
    }

    // ========================================================================
    // 🔑 USER LOGIN
    // ========================================================================

    /**
     * 🔓 User Login
     *
     * Authenticates user with email/username and password.
     *
     * @param string $identifier Email or username
     * @param string $password Password
     * @param bool $rememberMe Remember me option
     * @return array Login result ['success' => bool, 'userID' => int|null, 'message' => string, 'requiresMFA' => bool]
     */
    public static function login(string $identifier, string $password, bool $rememberMe = false): array
    {
        try {
            // 🚦 Check rate limiting
            $clientIP = getClientIP();
            $maxAttempts = getSetting('security.login.max_attempts', 5);
            $lockoutDuration = getSetting('security.login.lockout_duration', 1800);

            if (!SecurityUtils::checkRateLimit($clientIP, 'login', $maxAttempts, $lockoutDuration)) {
                ActivityLogger::log(null, 'login_rate_limited', 'security', 'warning',
                    'Login rate limit exceeded', ['ip' => $clientIP]);

                return [
                    'success' => false,
                    'userID' => null,
                    'message' => 'Too many login attempts. Please try again later.',
                    'requiresMFA' => false
                ];
            }

            // 🔍 Find user by email or username
            $identifier = SecurityUtils::sanitizeString($identifier);

            $query = "
                SELECT userID, userUUID, username, email, passwordHash,
                       displayName, accountStatus, accountTier,
                       failedLoginAttempts, lastFailedLogin,
                       emailVerified, requiresPasswordChange
                FROM tblUsers
                WHERE (email = ? OR username = ?)
                AND deletedAt IS NULL
            ";

            $user = Database::fetchOne($query, [$identifier, $identifier], 'ss');

            if (!$user) {
                // 📝 Log failed attempt
                ActivityLogger::log(null, 'login_failed', 'auth', 'warning',
                    'Login failed: User not found', ['identifier' => $identifier]);

                return [
                    'success' => false,
                    'userID' => null,
                    'message' => 'Invalid credentials',
                    'requiresMFA' => false
                ];
            }

            // 🔒 Check account status
            if ($user['accountStatus'] === 'locked') {
                ActivityLogger::log($user['userID'], 'login_locked', 'security', 'warning',
                    'Login attempt on locked account');

                return [
                    'success' => false,
                    'userID' => null,
                    'message' => 'Account is locked. Please contact support.',
                    'requiresMFA' => false
                ];
            }

            if ($user['accountStatus'] === 'suspended') {
                return [
                    'success' => false,
                    'userID' => null,
                    'message' => 'Account is suspended. Please contact support.',
                    'requiresMFA' => false
                ];
            }

            if ($user['accountStatus'] === 'pending' && !$user['emailVerified']) {
                return [
                    'success' => false,
                    'userID' => null,
                    'message' => 'Please verify your email address before logging in.',
                    'requiresMFA' => false
                ];
            }

            // ✅ Verify password
            if (empty($user['passwordHash']) || !SecurityUtils::verifyPassword($password, $user['passwordHash'])) {
                // 📈 Increment failed attempts
                self::handleFailedLogin($user['userID']);

                ActivityLogger::log($user['userID'], 'login_failed', 'auth', 'warning',
                    'Login failed: Invalid password');

                return [
                    'success' => false,
                    'userID' => null,
                    'message' => 'Invalid credentials',
                    'requiresMFA' => false
                ];
            }

            // 🔄 Check if password needs rehash
            if (SecurityUtils::needsRehash($user['passwordHash'])) {
                $newHash = SecurityUtils::hashPassword($password);
                Database::query(
                    "UPDATE tblUsers SET passwordHash = ? WHERE userID = ?",
                    [$newHash, $user['userID']],
                    'si'
                );
            }

            // 🔐 Check if MFA is enabled
            $mfaEnabled = Database::fetchOne(
                "SELECT COUNT(*) as count FROM tblUserMFA WHERE userID = ? AND isEnabled = TRUE",
                [$user['userID']],
                'i'
            );

            if ($mfaEnabled && $mfaEnabled['count'] > 0) {
                // Store user ID in session for MFA verification
                $_SESSION['mfa_user_id'] = $user['userID'];
                $_SESSION['mfa_remember_me'] = $rememberMe;

                return [
                    'success' => true,
                    'userID' => $user['userID'],
                    'message' => 'MFA verification required',
                    'requiresMFA' => true
                ];
            }

            // ✅ Complete login (no MFA required)
            self::completeLogin($user['userID'], $rememberMe);

            return [
                'success' => true,
                'userID' => $user['userID'],
                'message' => 'Login successful',
                'requiresMFA' => false
            ];

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());

            return [
                'success' => false,
                'userID' => null,
                'message' => 'Login failed. Please try again later.',
                'requiresMFA' => false
            ];
        }
    }

    /**
     * ✅ Complete Login Process
     *
     * Finalizes login after password (and optionally MFA) verification.
     *
     * @param int $userID User ID
     * @param bool $rememberMe Remember me option
     * @return bool Success status
     */
    private static function completeLogin(int $userID, bool $rememberMe = false): bool
    {
        try {
            // 🔄 Reset failed login attempts
            Database::query(
                "UPDATE tblUsers SET failedLoginAttempts = 0, lastFailedLogin = NULL WHERE userID = ?",
                [$userID],
                'i'
            );

            // 🎫 Create session
            $sessionToken = SecurityUtils::generateToken();
            $deviceInfo = self::getDeviceInfo();

            $sessionLifetime = $rememberMe ?
                getSetting('security.session.remember_lifetime', 2592000) : // 30 days
                getSetting('security.session.lifetime', 86400); // 24 hours

            $expiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);

            $sessionQuery = "
                INSERT INTO tblUserSessions (
                    sessionToken, userID, deviceType, deviceName,
                    ipAddress, userAgent, browserName, browserVersion,
                    osName, osVersion, isMobile, expiresAt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            Database::query($sessionQuery, [
                hash('sha256', $sessionToken),
                $userID,
                $deviceInfo['deviceType'],
                $deviceInfo['deviceName'],
                getClientIP(),
                getUserAgent(),
                $deviceInfo['browserName'],
                $deviceInfo['browserVersion'],
                $deviceInfo['osName'],
                $deviceInfo['osVersion'],
                $deviceInfo['isMobile'],
                $expiresAt
            ], 'sissssssssii');

            $sessionID = Database::getLastInsertId();

            // 💾 Store in PHP session
            $_SESSION['user_id'] = $userID;
            $_SESSION['session_id'] = $sessionID;
            $_SESSION['session_token'] = $sessionToken;

            // 🍪 Set cookie for remember me
            if ($rememberMe) {
                setcookie(
                    'signula_session',
                    $sessionToken,
                    [
                        'expires' => time() + $sessionLifetime,
                        'path' => '/',
                        'domain' => '',
                        'secure' => ENVIRONMENT === 'production',
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]
                );
            }

            // 📝 Update last login
            Database::query(
                "UPDATE tblUsers SET lastLoginAt = NOW(), lastLoginIP = ?, lastLoginUserAgent = ? WHERE userID = ?",
                [getClientIP(), getUserAgent(), $userID],
                'ssi'
            );

            // 📝 Log successful login
            ActivityLogger::log($userID, 'login_success', 'auth', 'info', 'User logged in successfully', [
                'session_id' => $sessionID,
                'remember_me' => $rememberMe
            ]);

            return true;

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }

    /**
     * ❌ Handle Failed Login Attempt
     *
     * Increments failed login counter and locks account if necessary.
     *
     * @param int $userID User ID
     */
    private static function handleFailedLogin(int $userID): void
    {
        try {
            // Increment failed attempts
            Database::query(
                "UPDATE tblUsers SET failedLoginAttempts = failedLoginAttempts + 1, lastFailedLogin = NOW() WHERE userID = ?",
                [$userID],
                'i'
            );

            // Check if account should be locked
            $maxAttempts = getSetting('security.login.max_attempts', 5);

            $user = Database::fetchOne(
                "SELECT failedLoginAttempts FROM tblUsers WHERE userID = ?",
                [$userID],
                'i'
            );

            if ($user && $user['failedLoginAttempts'] >= $maxAttempts) {
                // Lock account
                Database::query(
                    "UPDATE tblUsers SET accountStatus = 'locked' WHERE userID = ?",
                    [$userID],
                    'i'
                );

                ActivityLogger::log($userID, 'account_locked', 'security', 'critical',
                    'Account locked due to multiple failed login attempts');

                // TODO: Send email notification about account lockout
            }

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
        }
    }

    // ========================================================================
    // 🚪 USER LOGOUT
    // ========================================================================

    /**
     * 🚪 Logout User
     *
     * Terminates current session and logs out user.
     *
     * @return bool Success status
     */
    public static function logout(): bool
    {
        try {
            $userID = self::getCurrentUserID();
            $sessionID = $_SESSION['session_id'] ?? null;

            // 🗑️ Invalidate session in database
            if ($sessionID) {
                Database::query(
                    "UPDATE tblUserSessions SET isActive = FALSE WHERE sessionID = ?",
                    [$sessionID],
                    'i'
                );
            }

            // 📝 Log logout
            if ($userID) {
                ActivityLogger::log($userID, 'logout', 'auth', 'info', 'User logged out');
            }

            // 🧹 Clear session
            $_SESSION = [];

            // 🍪 Delete cookie
            if (isset($_COOKIE['signula_session'])) {
                setcookie('signula_session', '', time() - 3600, '/');
            }

            // 💥 Destroy session
            session_destroy();

            return true;

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }

    // ========================================================================
    // ✅ SESSION VALIDATION
    // ========================================================================

    /**
     * ✅ Check if User is Authenticated
     *
     * @return bool Authentication status
     */
    public static function isAuthenticated(): bool
    {
        return self::getCurrentUserID() !== null;
    }

    /**
     * 👤 Get Current User ID
     *
     * @return int|null User ID or null if not authenticated
     */
    public static function getCurrentUserID(): ?int
    {
        if (self::$currentUserID !== null) {
            return self::$currentUserID;
        }

        // Check PHP session
        if (!empty($_SESSION['user_id']) && !empty($_SESSION['session_token'])) {
            $sessionToken = hash('sha256', $_SESSION['session_token']);

            $session = Database::fetchOne(
                "SELECT userID FROM tblUserSessions WHERE sessionToken = ? AND isActive = TRUE AND expiresAt > NOW()",
                [$sessionToken],
                's'
            );

            if ($session) {
                self::$currentUserID = (int)$session['userID'];
                return self::$currentUserID;
            }
        }

        // Check cookie (for remember me)
        if (!empty($_COOKIE['signula_session'])) {
            $sessionToken = hash('sha256', $_COOKIE['signula_session']);

            $session = Database::fetchOne(
                "SELECT userID, sessionID FROM tblUserSessions WHERE sessionToken = ? AND isActive = TRUE AND expiresAt > NOW()",
                [$sessionToken],
                's'
            );

            if ($session) {
                // Restore session
                $_SESSION['user_id'] = $session['userID'];
                $_SESSION['session_id'] = $session['sessionID'];
                $_SESSION['session_token'] = $_COOKIE['signula_session'];

                self::$currentUserID = (int)$session['userID'];
                return self::$currentUserID;
            }
        }

        return null;
    }

    /**
     * 👥 Get Current User Data
     *
     * @return array|null User data or null if not authenticated
     */
    public static function getCurrentUser(): ?array
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        $userID = self::getCurrentUserID();

        if ($userID === null) {
            return null;
        }

        $query = "
            SELECT userID, userUUID, username, email, firstName, lastName,
                   displayName, profilePicture, phoneNumber, locale, timezone,
                   accountStatus, accountTier, emailVerified, lastLoginAt
            FROM tblUsers
            WHERE userID = ? AND deletedAt IS NULL
        ";

        self::$currentUser = Database::fetchOne($query, [$userID], 'i');

        return self::$currentUser;
    }

    /**
     * 🔄 Require Authentication
     *
     * Redirects to login if not authenticated.
     *
     * @param string $redirectTo URL to redirect after login
     */
    public static function requireAuth(string $redirectTo = ''): void
    {
        if (!self::isAuthenticated()) {
            $loginURL = '/login';

            if (!empty($redirectTo)) {
                $loginURL .= '?redirect=' . urlencode($redirectTo);
            } else {
                $currentURL = getCurrentURL();
                $loginURL .= '?redirect=' . urlencode($currentURL);
            }

            redirect($loginURL);
        }
    }

    // ========================================================================
    // 🔧 UTILITY FUNCTIONS
    // ========================================================================

    /**
     * 🆔 Generate UUID v4
     *
     * @return string UUID
     */
    private static function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * 📱 Get Device Information
     *
     * @return array Device information
     */
    private static function getDeviceInfo(): array
    {
        $userAgent = getUserAgent();

        // Basic device detection (can be enhanced with a library)
        $deviceInfo = [
            'deviceType' => 'web',
            'deviceName' => 'Web Browser',
            'browserName' => 'Unknown',
            'browserVersion' => '',
            'osName' => 'Unknown',
            'osVersion' => '',
            'isMobile' => false
        ];

        // Detect mobile
        if (preg_match('/mobile|android|iphone|ipad|ipod/i', $userAgent)) {
            $deviceInfo['isMobile'] = true;
            $deviceInfo['deviceType'] = preg_match('/ipad|tablet/i', $userAgent) ? 'tablet' : 'mobile_ios';
        }

        // Detect browser
        if (preg_match('/Firefox\/([0-9.]+)/i', $userAgent, $matches)) {
            $deviceInfo['browserName'] = 'Firefox';
            $deviceInfo['browserVersion'] = $matches[1];
        } elseif (preg_match('/Chrome\/([0-9.]+)/i', $userAgent, $matches)) {
            $deviceInfo['browserName'] = 'Chrome';
            $deviceInfo['browserVersion'] = $matches[1];
        } elseif (preg_match('/Safari\/([0-9.]+)/i', $userAgent, $matches)) {
            $deviceInfo['browserName'] = 'Safari';
            $deviceInfo['browserVersion'] = $matches[1];
        }

        // Detect OS
        if (preg_match('/Windows NT ([0-9.]+)/i', $userAgent, $matches)) {
            $deviceInfo['osName'] = 'Windows';
            $deviceInfo['osVersion'] = $matches[1];
        } elseif (preg_match('/Mac OS X ([0-9._]+)/i', $userAgent, $matches)) {
            $deviceInfo['osName'] = 'macOS';
            $deviceInfo['osVersion'] = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/Linux/i', $userAgent)) {
            $deviceInfo['osName'] = 'Linux';
        }

        return $deviceInfo;
    }
}

// ✅ Auth class loaded successfully
