<?php
/**
 * ============================================================================
 * 🔒 SIGNula - Email Security Manager
 * ============================================================================
 *
 * Purpose: Security features for email system
 * PHP Version: 8.3+
 *
 * Features:
 * - Rate limiting per user/IP
 * - Enhanced email validation
 * - Spam pattern detection
 * - Suspicious activity monitoring
 * - IP-based throttling
 * - Content filtering
 *
 * @package    SIGNula
 * @subpackage Email
 * @version    1.0.0
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🔒 Email Security Manager
 *
 * Provides security features for the email system.
 */
class EmailSecurityManager
{
    /**
     * @var int Default rate limit per user (emails per hour)
     */
    private const DEFAULT_USER_RATE_LIMIT = 100;

    /**
     * @var int Default rate limit per IP (emails per hour)
     */
    private const DEFAULT_IP_RATE_LIMIT = 50;

    /**
     * @var int Spam score threshold (0-100, higher = more likely spam)
     */
    private const SPAM_THRESHOLD = 70;

    // ========================================================================
    // 🔐 RATE LIMITING
    // ========================================================================

    /**
     * ✅ Check Rate Limit
     *
     * Checks if user/IP has exceeded rate limits.
     *
     * @param int|null $userID User ID (null for anonymous)
     * @param string|null $ipAddress IP address
     * @return array Result with 'allowed' and 'reason' keys
     */
    public static function checkRateLimit(?int $userID = null, ?string $ipAddress = null): array
    {
        try {
            // 🔍 Check user rate limit
            if ($userID !== null) {
                $userLimit = getSetting('email.rate_limit.user', self::DEFAULT_USER_RATE_LIMIT);
                $userCount = self::getUserEmailCount($userID, 3600); // Last hour

                if ($userCount >= $userLimit) {
                    self::logSecurityEvent('rate_limit_exceeded', $userID, $ipAddress, [
                        'type' => 'user',
                        'limit' => $userLimit,
                        'count' => $userCount
                    ]);

                    return [
                        'allowed' => false,
                        'reason' => "Rate limit exceeded. Maximum {$userLimit} emails per hour.",
                        'retry_after' => 3600 // seconds
                    ];
                }
            }

            // 🔍 Check IP rate limit
            if ($ipAddress !== null) {
                $ipLimit = getSetting('email.rate_limit.ip', self::DEFAULT_IP_RATE_LIMIT);
                $ipCount = self::getIPEmailCount($ipAddress, 3600); // Last hour

                if ($ipCount >= $ipLimit) {
                    self::logSecurityEvent('rate_limit_exceeded', $userID, $ipAddress, [
                        'type' => 'ip',
                        'limit' => $ipLimit,
                        'count' => $ipCount
                    ]);

                    return [
                        'allowed' => false,
                        'reason' => "Rate limit exceeded from this IP. Maximum {$ipLimit} emails per hour.",
                        'retry_after' => 3600
                    ];
                }
            }

            return ['allowed' => true, 'reason' => null, 'retry_after' => null];

        } catch (Exception $e) {
            error_log("Rate limit check error: " . $e->getMessage());
            // 🔓 Fail open - allow email but log error
            return ['allowed' => true, 'reason' => null, 'retry_after' => null];
        }
    }

    /**
     * 📊 Get User Email Count
     *
     * Counts emails sent by user in time period.
     *
     * @param int $userID User ID
     * @param int $seconds Time period in seconds
     * @return int Email count
     */
    private static function getUserEmailCount(int $userID, int $seconds): int
    {
        $query = "
            SELECT COUNT(*) as count
            FROM tblEmailQueue
            WHERE userID = ?
              AND createdAt >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ";

        $result = Database::fetchOne($query, [$userID, $seconds], 'ii');
        return $result['count'] ?? 0;
    }

    /**
     * 📊 Get IP Email Count
     *
     * Counts emails sent from IP in time period.
     *
     * @param string $ipAddress IP address
     * @param int $seconds Time period in seconds
     * @return int Email count
     */
    private static function getIPEmailCount(string $ipAddress, int $seconds): int
    {
        $query = "
            SELECT COUNT(*) as count
            FROM tblEmailQueue
            WHERE JSON_EXTRACT(metadata, '$.ip_address') = ?
              AND createdAt >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ";

        $result = Database::fetchOne($query, [$ipAddress, $seconds], 'si');
        return $result['count'] ?? 0;
    }

    // ========================================================================
    // ✅ EMAIL VALIDATION
    // ========================================================================

    /**
     * 🔍 Enhanced Email Validation
     *
     * Validates email address with additional security checks.
     *
     * @param string $email Email address
     * @return array Validation result with 'valid' and 'reason' keys
     */
    public static function validateEmail(string $email): array
    {
        // 📧 Basic format validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'reason' => 'Invalid email format'];
        }

        // 🚫 Check for disposable email domains
        if (self::isDisposableEmail($email)) {
            return ['valid' => false, 'reason' => 'Disposable email addresses are not allowed'];
        }

        // 🚫 Check for blocked domains
        if (self::isBlockedDomain($email)) {
            return ['valid' => false, 'reason' => 'Email domain is blocked'];
        }

        // 📏 Check email length
        if (strlen($email) > 255) {
            return ['valid' => false, 'reason' => 'Email address too long'];
        }

        // ✅ Valid
        return ['valid' => true, 'reason' => null];
    }

    /**
     * 🗑️ Check if Disposable Email
     *
     * Checks if email is from a disposable email service.
     *
     * @param string $email Email address
     * @return bool True if disposable
     */
    private static function isDisposableEmail(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@'), 1));

        // 📋 Common disposable email domains
        $disposableDomains = [
            'tempmail.com', '10minutemail.com', 'guerrillamail.com',
            'mailinator.com', 'throwaway.email', 'temp-mail.org'
        ];

        return in_array($domain, $disposableDomains);
    }

    /**
     * 🚫 Check if Blocked Domain
     *
     * Checks if email domain is blocked.
     *
     * @param string $email Email address
     * @return bool True if blocked
     */
    private static function isBlockedDomain(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@'), 1));

        // 🔍 Check database for blocked domains
        $query = "
            SELECT COUNT(*) as count
            FROM tblSettings
            WHERE settingKey = CONCAT('email.blocked_domain.', ?)
        ";

        $result = Database::fetchOne($query, [$domain], 's');
        return ($result['count'] ?? 0) > 0;
    }

    // ========================================================================
    // 🛡️ SPAM DETECTION
    // ========================================================================

    /**
     * 🔍 Detect Spam Content
     *
     * Analyzes email content for spam indicators.
     *
     * @param array $emailData Email data
     * @return array Detection result with 'is_spam', 'score', and 'reasons' keys
     */
    public static function detectSpam(array $emailData): array
    {
        $score = 0;
        $reasons = [];

        // 📝 Combine content for analysis
        $content = ($emailData['subject'] ?? '') . ' ' .
                   ($emailData['bodyText'] ?? '') . ' ' .
                   ($emailData['bodyHTML'] ?? '');

        $content = strtolower($content);

        // 🚨 Spam indicators
        $spamKeywords = [
            'viagra' => 30,
            'cialis' => 30,
            'casino' => 25,
            'lottery' => 25,
            'winner' => 20,
            'congratulations you won' => 30,
            'click here now' => 15,
            'act now' => 15,
            'limited time' => 10,
            'free money' => 25,
            'make money fast' => 25,
            'work from home' => 15,
            'nigerian prince' => 50,
            'inheritance' => 20
        ];

        foreach ($spamKeywords as $keyword => $points) {
            if (strpos($content, $keyword) !== false) {
                $score += $points;
                $reasons[] = "Contains spam keyword: '{$keyword}'";
            }
        }

        // 📧 Excessive capitalization
        $capsRatio = self::getCapitalizationRatio($emailData['subject'] ?? '');
        if ($capsRatio > 0.5) {
            $score += 20;
            $reasons[] = "Excessive capitalization in subject";
        }

        // ❗ Excessive exclamation marks
        $exclamationCount = substr_count($content, '!');
        if ($exclamationCount > 5) {
            $score += 10;
            $reasons[] = "Excessive exclamation marks";
        }

        // 🔗 Excessive links
        $linkCount = substr_count($content, 'http');
        if ($linkCount > 10) {
            $score += 15;
            $reasons[] = "Excessive links";
        }

        // 📊 Determine if spam
        $isSpam = $score >= self::SPAM_THRESHOLD;

        if ($isSpam) {
            self::logSecurityEvent('spam_detected', null, null, [
                'score' => $score,
                'reasons' => $reasons,
                'subject' => $emailData['subject'] ?? ''
            ]);
        }

        return [
            'is_spam' => $isSpam,
            'score' => $score,
            'reasons' => $reasons
        ];
    }

    /**
     * 📊 Get Capitalization Ratio
     *
     * Calculates ratio of capital letters in text.
     *
     * @param string $text Text to analyze
     * @return float Capitalization ratio (0-1)
     */
    private static function getCapitalizationRatio(string $text): float
    {
        $letters = preg_replace('/[^a-zA-Z]/', '', $text);
        if (empty($letters)) {
            return 0;
        }

        $capitals = preg_replace('/[^A-Z]/', '', $letters);
        return strlen($capitals) / strlen($letters);
    }

    // ========================================================================
    // 🔐 CONTENT FILTERING
    // ========================================================================

    /**
     * 🧹 Sanitize Email Content
     *
     * Sanitizes email content to remove potential threats.
     *
     * @param array $emailData Email data
     * @return array Sanitized email data
     */
    public static function sanitizeContent(array $emailData): array
    {
        // 📝 Sanitize HTML content
        if (!empty($emailData['bodyHTML'])) {
            $emailData['bodyHTML'] = self::sanitizeHTML($emailData['bodyHTML']);
        }

        // 📝 Sanitize text content
        if (!empty($emailData['bodyText'])) {
            $emailData['bodyText'] = self::sanitizeText($emailData['bodyText']);
        }

        // 📝 Sanitize subject
        if (!empty($emailData['subject'])) {
            $emailData['subject'] = self::sanitizeText($emailData['subject']);
        }

        return $emailData;
    }

    /**
     * 🧹 Sanitize HTML
     *
     * Removes potentially dangerous HTML tags and attributes.
     *
     * @param string $html HTML content
     * @return string Sanitized HTML
     */
    private static function sanitizeHTML(string $html): string
    {
        // 🚫 Strip dangerous tags
        $html = strip_tags($html, '<p><br><a><b><strong><i><em><u><h1><h2><h3><h4><h5><h6><ul><ol><li><table><tr><td><th><img><span><div>');

        // 🚫 Remove javascript: and data: URLs
        $html = preg_replace('/(javascript|data):/i', '', $html);

        // 🚫 Remove event handlers (onclick, onload, etc.)
        $html = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);

        return $html;
    }

    /**
     * 🧹 Sanitize Text
     *
     * Sanitizes plain text content.
     *
     * @param string $text Text content
     * @return string Sanitized text
     */
    private static function sanitizeText(string $text): string
    {
        // 🧹 Trim whitespace
        $text = trim($text);

        // 🚫 Remove null bytes
        $text = str_replace("\0", '', $text);

        // 🚫 Remove control characters (except tabs and newlines)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        return $text;
    }

    // ========================================================================
    // 📝 SECURITY LOGGING
    // ========================================================================

    /**
     * 📝 Log Security Event
     *
     * Logs security-related events.
     *
     * @param string $event Event type
     * @param int|null $userID User ID
     * @param string|null $ipAddress IP address
     * @param array $metadata Additional metadata
     */
    private static function logSecurityEvent(
        string $event,
        ?int $userID = null,
        ?string $ipAddress = null,
        array $metadata = []
    ): void {
        try {
            ActivityLogger::log(
                $userID,
                'email_security_' . $event,
                'email',
                'warning',
                "Email security event: {$event}",
                array_merge($metadata, ['ip_address' => $ipAddress])
            );

        } catch (Exception $e) {
            error_log("Failed to log security event: " . $e->getMessage());
        }
    }

    // ========================================================================
    // 🛡️ COMPREHENSIVE SECURITY CHECK
    // ========================================================================

    /**
     * 🛡️ Perform Security Check
     *
     * Performs comprehensive security check on email.
     *
     * @param array $emailData Email data
     * @param int|null $userID User ID
     * @param string|null $ipAddress IP address
     * @return array Security check result
     */
    public static function performSecurityCheck(
        array $emailData,
        ?int $userID = null,
        ?string $ipAddress = null
    ): array {
        // ✅ Check rate limits
        $rateLimitCheck = self::checkRateLimit($userID, $ipAddress);
        if (!$rateLimitCheck['allowed']) {
            return [
                'passed' => false,
                'reason' => $rateLimitCheck['reason'],
                'type' => 'rate_limit'
            ];
        }

        // ✅ Validate recipient email
        $emailValidation = self::validateEmail($emailData['to']);
        if (!$emailValidation['valid']) {
            return [
                'passed' => false,
                'reason' => $emailValidation['reason'],
                'type' => 'validation'
            ];
        }

        // ✅ Check for spam
        $spamCheck = self::detectSpam($emailData);
        if ($spamCheck['is_spam']) {
            return [
                'passed' => false,
                'reason' => 'Email flagged as spam (score: ' . $spamCheck['score'] . ')',
                'type' => 'spam',
                'spam_reasons' => $spamCheck['reasons']
            ];
        }

        // ✅ All checks passed
        return [
            'passed' => true,
            'reason' => null,
            'type' => null
        ];
    }
}

// ✅ EmailSecurityManager class loaded successfully
