<?php
/**
 * ============================================================================
 * 🔓 SIGNula - Passwordless Login Handler
 * ============================================================================
 *
 * Purpose: Handle password-less login via secure email links
 * PHP Version: 8.3+
 *
 * Features:
 * - Secure tokenized email links
 * - Time-limited tokens (configurable expiration)
 * - Rate limiting
 * - IP and user agent tracking
 * - One-time use tokens
 * - Attempt count tracking
 *
 * Security:
 * - Cryptographically secure random tokens
 * - Token hashing in database
 * - Short expiration windows (default: 15 minutes)
 * - Single-use tokens
 * - Rate limiting per email/IP
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

/**
 * 🔓 Passwordless Login Handler
 *
 * Handles passwordless authentication via email links.
 */
class PasswordlessLoginHandler
{
    /**
     * @var int Token validity in minutes
     */
    private int $tokenValidity = 15;

    /**
     * @var int Maximum login attempts per token
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * @var int Rate limit per email (requests per hour)
     */
    private const RATE_LIMIT_EMAIL = 5;

    /**
     * @var int Rate limit per IP (requests per hour)
     */
    private const RATE_LIMIT_IP = 10;

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        $this->tokenValidity = (int)getSetting('auth.passwordless.token_validity', 15);
    }

    // ========================================================================
    // 📧 REQUEST LOGIN LINK
    // ========================================================================

    /**
     * 📨 Send Login Link
     *
     * Generates and sends a passwordless login link via email.
     *
     * @param string $email Email address
     * @param string $purpose Purpose (login, register, verify)
     * @return array Result with success status
     */
    public function sendLoginLink(string $email, string $purpose = 'login'): array
    {
        try {
            // ✅ Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'error' => 'Invalid email address'
                ];
            }

            // 🔒 Check rate limits
            $rateLimitCheck = $this->checkRateLimits($email);
            if (!$rateLimitCheck['allowed']) {
                return [
                    'success' => false,
                    'error' => $rateLimitCheck['message']
                ];
            }

            // 🔍 Check if user exists (for login)
            $userID = null;
            if ($purpose === 'login') {
                $user = Database::fetchOne(
                    "SELECT userID, displayName FROM tblUsers WHERE email = ?",
                    [$email],
                    's'
                );

                if (!$user) {
                    // For security, don't reveal if email exists or not
                    return [
                        'success' => true,
                        'message' => 'If an account exists with this email, you will receive a login link shortly.'
                    ];
                }

                $userID = $user['userID'];
            }

            // 🎲 Generate secure token
            $token = $this->generateToken();
            $tokenHash = $this->hashToken($token);

            // 💾 Store token
            $this->storeToken(
                token: $token,
                tokenHash: $tokenHash,
                email: $email,
                userID: $userID,
                purpose: $purpose
            );

            // 🔗 Generate login URL
            $loginUrl = $this->generateLoginUrl($token);

            // 📧 Send email
            $emailSent = $this->sendEmail(
                email: $email,
                loginUrl: $loginUrl,
                purpose: $purpose
            );

            if (!$emailSent) {
                return [
                    'success' => false,
                    'error' => 'Failed to send email. Please try again.'
                ];
            }

            return [
                'success' => true,
                'message' => 'Login link sent! Please check your email.'
            ];

        } catch (Exception $e) {
            error_log("Failed to send login link: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'An error occurred. Please try again.'
            ];
        }
    }

    /**
     * ✅ Verify Login Token
     *
     * Verifies a passwordless login token and authenticates the user.
     *
     * @param string $token Login token
     * @return array Result with user ID if successful
     */
    public function verifyLoginToken(string $token): array
    {
        try {
            // 🔍 Get token from database
            $tokenData = Database::fetchOne(
                "SELECT * FROM tblPasswordlessTokens WHERE token = ? AND isUsed = 0",
                [$token],
                's'
            );

            if (!$tokenData) {
                return [
                    'success' => false,
                    'error' => 'Invalid or expired login link'
                ];
            }

            // ⏰ Check if expired
            if (strtotime($tokenData['expiresAt']) < time()) {
                return [
                    'success' => false,
                    'error' => 'This login link has expired. Please request a new one.'
                ];
            }

            // 🔢 Check attempt count
            if ($tokenData['attemptCount'] >= self::MAX_ATTEMPTS) {
                return [
                    'success' => false,
                    'error' => 'Too many attempts. Please request a new login link.'
                ];
            }

            // 📊 Increment attempt count
            $this->incrementAttemptCount($tokenData['tokenID']);

            // ✅ Verify token hash
            if (!$this->verifyTokenHash($token, $tokenData['tokenHash'])) {
                return [
                    'success' => false,
                    'error' => 'Invalid login link'
                ];
            }

            // 🔍 Get or create user
            $userID = $tokenData['userID'];

            if (!$userID) {
                // Handle registration case if needed
                if ($tokenData['purpose'] === 'register') {
                    return [
                        'success' => true,
                        'action' => 'complete_registration',
                        'email' => $tokenData['email'],
                        'token' => $token
                    ];
                }

                return [
                    'success' => false,
                    'error' => 'User account not found'
                ];
            }

            // 📊 Mark token as used
            $this->markTokenUsed($tokenData['tokenID']);

            // 📝 Log authentication
            ActivityLogger::log(
                userID: $userID,
                activityType: 'login',
                activityResult: 'success',
                activityDetails: 'Passwordless email login',
                metadata: json_encode([
                    'token_id' => $tokenData['tokenID'],
                    'purpose' => $tokenData['purpose']
                ])
            );

            return [
                'success' => true,
                'userID' => $userID,
                'message' => 'Login successful!'
            ];

        } catch (Exception $e) {
            error_log("Token verification failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'An error occurred during login'
            ];
        }
    }

    // ========================================================================
    // 🔐 TOKEN MANAGEMENT
    // ========================================================================

    /**
     * 🎲 Generate Token
     *
     * Generates a cryptographically secure random token.
     *
     * @return string Token
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(32)); // 64 character hex string
    }

    /**
     * 🔒 Hash Token
     *
     * Hashes a token for secure storage.
     *
     * @param string $token Token
     * @return string Hashed token
     */
    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * ✅ Verify Token Hash
     *
     * Verifies a token against its hash.
     *
     * @param string $token Token
     * @param string $hash Stored hash
     * @return bool Valid
     */
    private function verifyTokenHash(string $token, string $hash): bool
    {
        return hash_equals($hash, $this->hashToken($token));
    }

    /**
     * 💾 Store Token
     *
     * Stores a token in the database.
     *
     * @param string $token Token
     * @param string $tokenHash Hashed token
     * @param string $email Email address
     * @param int|null $userID User ID
     * @param string $purpose Token purpose
     */
    private function storeToken(
        string $token,
        string $tokenHash,
        string $email,
        ?int $userID,
        string $purpose
    ): void {
        $expiresAt = new DateTime();
        $expiresAt->modify("+{$this->tokenValidity} minutes");

        $query = "
            INSERT INTO tblPasswordlessTokens (
                userID, email, token, tokenHash, purpose,
                ipAddress, userAgent, expiresAt, validityMinutes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        Database::insert($query, [
            $userID,
            $email,
            $token,
            $tokenHash,
            $purpose,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $expiresAt->format('Y-m-d H:i:s'),
            $this->tokenValidity
        ], 'isssssssi');
    }

    /**
     * 📊 Mark Token Used
     *
     * Marks a token as used.
     *
     * @param int $tokenID Token ID
     */
    private function markTokenUsed(int $tokenID): void
    {
        Database::query(
            "UPDATE tblPasswordlessTokens SET isUsed = 1, usedAt = NOW() WHERE tokenID = ?",
            [$tokenID],
            'i'
        );
    }

    /**
     * 🔢 Increment Attempt Count
     *
     * Increments the attempt count for a token.
     *
     * @param int $tokenID Token ID
     */
    private function incrementAttemptCount(int $tokenID): void
    {
        Database::query(
            "UPDATE tblPasswordlessTokens SET attemptCount = attemptCount + 1 WHERE tokenID = ?",
            [$tokenID],
            'i'
        );
    }

    // ========================================================================
    // 🔒 RATE LIMITING
    // ========================================================================

    /**
     * 🔍 Check Rate Limits
     *
     * Checks if email and IP are within rate limits.
     *
     * @param string $email Email address
     * @return array Result with allowed status
     */
    private function checkRateLimits(string $email): array
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        // Check email rate limit
        $emailCount = Database::fetchOne(
            "SELECT COUNT(*) as count FROM tblPasswordlessTokens
             WHERE email = ? AND createdAt >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [$email],
            's'
        );

        if (($emailCount['count'] ?? 0) >= self::RATE_LIMIT_EMAIL) {
            return [
                'allowed' => false,
                'message' => 'Too many requests for this email. Please try again later.'
            ];
        }

        // Check IP rate limit
        if ($ipAddress) {
            $ipCount = Database::fetchOne(
                "SELECT COUNT(*) as count FROM tblPasswordlessTokens
                 WHERE ipAddress = ? AND createdAt >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                [$ipAddress],
                's'
            );

            if (($ipCount['count'] ?? 0) >= self::RATE_LIMIT_IP) {
                return [
                    'allowed' => false,
                    'message' => 'Too many requests from your IP. Please try again later.'
                ];
            }
        }

        return ['allowed' => true];
    }

    // ========================================================================
    // 🔗 URL GENERATION
    // ========================================================================

    /**
     * 🔗 Generate Login URL
     *
     * Generates the full login URL with token.
     *
     * @param string $token Token
     * @return string Login URL
     */
    private function generateLoginUrl(string $token): string
    {
        $baseUrl = getSetting('site.url', 'https://signula.id');
        return rtrim($baseUrl, '/') . '/auth/passwordless-login?token=' . urlencode($token);
    }

    // ========================================================================
    // 📧 EMAIL SENDING
    // ========================================================================

    /**
     * 📧 Send Email
     *
     * Sends the passwordless login email.
     *
     * @param string $email Recipient email
     * @param string $loginUrl Login URL
     * @param string $purpose Email purpose
     * @return bool Success status
     */
    private function sendEmail(string $email, string $loginUrl, string $purpose): bool
    {
        try {
            // 📝 Determine subject and template based on purpose
            $subject = match($purpose) {
                'login' => 'Your login link for ' . getSetting('site.name', 'SIGNula'),
                'register' => 'Complete your registration',
                'verify' => 'Verify your email address',
                default => 'Your secure login link'
            };

            // 🎨 Build email content
            $bodyHTML = $this->buildEmailHTML($loginUrl, $purpose);
            $bodyText = $this->buildEmailText($loginUrl, $purpose);

            // 📨 Send via email service
            return (bool)EmailService::queueEmail(
                recipientEmail: $email,
                subject: $subject,
                bodyHTML: $bodyHTML,
                bodyText: $bodyText,
                priority: 1 // High priority
            );

        } catch (Exception $e) {
            error_log("Failed to send passwordless email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🎨 Build Email HTML
     *
     * Builds HTML email content.
     *
     * @param string $loginUrl Login URL
     * @param string $purpose Email purpose
     * @return string HTML content
     */
    private function buildEmailHTML(string $loginUrl, string $purpose): string
    {
        $siteName = getSetting('site.name', 'SIGNula');
        $expiryMinutes = $this->tokenValidity;

        $greeting = match($purpose) {
            'login' => 'Hello!',
            'register' => 'Welcome!',
            'verify' => 'Hello!',
            default => 'Hello!'
        };

        $mainText = match($purpose) {
            'login' => 'Click the button below to securely log in to your account. No password required!',
            'register' => 'Click the button below to complete your registration and activate your account.',
            'verify' => 'Click the button below to verify your email address.',
            default => 'Click the button below to proceed.'
        };

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        </head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background-color: #f8f9fa; padding: 30px; border-radius: 10px;'>
                <h2 style='color: #007bff; margin-top: 0;'>{$greeting}</h2>

                <p style='font-size: 16px;'>{$mainText}</p>

                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$loginUrl}' style='display: inline-block; background-color: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;'>
                        " . ($purpose === 'login' ? 'Log In' : 'Continue') . "
                    </a>
                </div>

                <p style='font-size: 14px; color: #666;'>
                    This link will expire in <strong>{$expiryMinutes} minutes</strong> for your security.
                </p>

                <p style='font-size: 14px; color: #666;'>
                    If you didn't request this, you can safely ignore this email.
                </p>

                <hr style='border: none; border-top: 1px solid #ddd; margin: 30px 0;'>

                <p style='font-size: 12px; color: #999;'>
                    If the button doesn't work, copy and paste this link into your browser:<br>
                    <a href='{$loginUrl}' style='color: #007bff; word-break: break-all;'>{$loginUrl}</a>
                </p>

                <p style='font-size: 12px; color: #999; margin-top: 20px;'>
                    © " . date('Y') . " {$siteName}. All rights reserved.
                </p>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * 📝 Build Email Text
     *
     * Builds plain text email content.
     *
     * @param string $loginUrl Login URL
     * @param string $purpose Email purpose
     * @return string Text content
     */
    private function buildEmailText(string $loginUrl, string $purpose): string
    {
        $siteName = getSetting('site.name', 'SIGNula');
        $expiryMinutes = $this->tokenValidity;

        $greeting = match($purpose) {
            'login' => 'Hello!',
            'register' => 'Welcome!',
            'verify' => 'Hello!',
            default => 'Hello!'
        };

        $mainText = match($purpose) {
            'login' => 'Click the link below to securely log in to your account. No password required!',
            'register' => 'Click the link below to complete your registration and activate your account.',
            'verify' => 'Click the link below to verify your email address.',
            default => 'Click the link below to proceed.'
        };

        return "{$greeting}\n\n" .
               "{$mainText}\n\n" .
               "{$loginUrl}\n\n" .
               "This link will expire in {$expiryMinutes} minutes for your security.\n\n" .
               "If you didn't request this, you can safely ignore this email.\n\n" .
               "© " . date('Y') . " {$siteName}. All rights reserved.";
    }

    // ========================================================================
    // 🔧 UTILITY METHODS
    // ========================================================================

    /**
     * 🧹 Cleanup Expired Tokens
     *
     * Removes expired tokens (called by cron).
     *
     * @return int Number of tokens deleted
     */
    public static function cleanupExpiredTokens(): int
    {
        $result = Database::query(
            "DELETE FROM tblPasswordlessTokens WHERE expiresAt < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        return Database::getAffectedRows();
    }

    /**
     * 📊 Get Statistics
     *
     * Gets passwordless login usage statistics.
     *
     * @return array Statistics
     */
    public static function getStatistics(): array
    {
        // Total tokens issued (last 30 days)
        $totalTokens = Database::fetchOne(
            "SELECT COUNT(*) as count FROM tblPasswordlessTokens
             WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        // Used tokens
        $usedTokens = Database::fetchOne(
            "SELECT COUNT(*) as count FROM tblPasswordlessTokens
             WHERE isUsed = 1 AND usedAt >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        // Success rate
        $total = $totalTokens['count'] ?? 0;
        $used = $usedTokens['count'] ?? 0;
        $successRate = $total > 0 ? ($used / $total) * 100 : 0;

        return [
            'total_tokens_issued' => $total,
            'tokens_used' => $used,
            'success_rate' => round($successRate, 2)
        ];
    }
}

// ✅ PasswordlessLoginHandler class loaded successfully
