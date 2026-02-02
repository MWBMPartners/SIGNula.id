<?php
/**
 * ============================================================================
 * 📧 SIGNula - Email Service
 * ============================================================================
 *
 * Purpose: Handle email sending with template support
 * PHP Version: 8.3+
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
 * 📨 Email Service Class
 *
 * Handles email sending with template support and queue management.
 */
class EmailService
{
    /**
     * 📧 Send Email Using Template
     *
     * @param string $recipientEmail Recipient email address
     * @param string $templateKey Template key from tblEmailTemplates
     * @param array $variables Template variables
     * @param int|null $userID User ID (optional)
     * @param int $priority Email priority (1=highest, 10=lowest)
     * @return bool Success status
     */
    public static function sendTemplateEmail(
        string $recipientEmail,
        string $templateKey,
        array $variables = [],
        ?int $userID = null,
        int $priority = 5
    ): bool {
        try {
            // 🔍 Fetch email template
            $template = Database::fetchOne(
                "SELECT * FROM tblEmailTemplates WHERE templateKey = ? AND isActive = TRUE",
                [$templateKey],
                's'
            );

            if (!$template) {
                ErrorLogger::logError('EMAIL_ERROR', "Template not found: {$templateKey}");
                return false;
            }

            // 🔄 Replace variables in template
            $subject = self::replaceVariables($template['subject'], $variables);
            $bodyHTML = self::replaceVariables($template['bodyHTML'], $variables);
            $bodyText = self::replaceVariables($template['bodyText'], $variables);

            // 📬 Queue email for sending
            return self::queueEmail(
                $recipientEmail,
                $subject,
                $bodyHTML,
                $bodyText,
                $template['fromEmail'] ?? getSetting('email.from.address'),
                $template['fromName'] ?? getSetting('email.from.name'),
                $template['replyTo'],
                $userID,
                $template['templateID'],
                $priority
            );

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }

    /**
     * 📬 Queue Email for Sending
     *
     * @param string $recipientEmail Recipient email
     * @param string $subject Email subject
     * @param string|null $bodyHTML HTML body
     * @param string $bodyText Plain text body
     * @param string|null $fromEmail From email
     * @param string|null $fromName From name
     * @param string|null $replyTo Reply-to email
     * @param int|null $userID User ID
     * @param int|null $templateID Template ID
     * @param int $priority Priority (1=highest, 10=lowest)
     * @param \DateTime|null $scheduledFor Scheduled send time
     * @param array $cc CC recipients (array of email addresses)
     * @param array $bcc BCC recipients (array of email addresses)
     * @param array $attachments Attachments (array of attachment data)
     * @return bool Success status
     */
    public static function queueEmail(
        string $recipientEmail,
        string $subject,
        ?string $bodyHTML,
        string $bodyText,
        ?string $fromEmail = null,
        ?string $fromName = null,
        ?string $replyTo = null,
        ?int $userID = null,
        ?int $templateID = null,
        int $priority = 5,
        ?\DateTime $scheduledFor = null,
        array $cc = [],
        array $bcc = [],
        array $attachments = []
    ): bool {
        try {
            $fromEmail = $fromEmail ?? getSetting('email.from.address', 'noreply@signulo.id');
            $fromName = $fromName ?? getSetting('email.from.name', 'SIGNula');

            // 📋 Encode arrays as JSON
            $ccJson = !empty($cc) ? json_encode($cc) : null;
            $bccJson = !empty($bcc) ? json_encode($bcc) : null;
            $attachmentsJson = !empty($attachments) ? json_encode($attachments) : null;

            $query = "
                INSERT INTO tblEmailQueue (
                    userID, templateID, recipientEmail, recipientName,
                    subject, bodyHTML, bodyText,
                    fromEmail, fromName, replyToEmail,
                    ccRecipients, bccRecipients, attachments,
                    priority, status, scheduledAt
                ) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
            ";

            Database::query($query, [
                $userID,
                $templateID,
                $recipientEmail,
                $subject,
                $bodyHTML,
                $bodyText,
                $fromEmail,
                $fromName,
                $replyTo,
                $ccJson,
                $bccJson,
                $attachmentsJson,
                $priority,
                $scheduledFor ? $scheduledFor->format('Y-m-d H:i:s') : null
            ], 'iissssssssssiss');

            return true;

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }

    /**
     * 📧 Send Verification Email
     *
     * @param string $email Recipient email
     * @param string $token Verification token
     * @param string $code Verification code
     * @return bool Success status
     */
    public static function sendVerificationEmail(string $email, string $token, string $code): bool
    {
        $baseURL = getSetting('url.base', 'https://signulo.id');
        $verificationURL = "{$baseURL}/verify-email?token={$token}";

        $variables = [
            'displayName' => 'User',
            'email' => $email,
            'verificationUrl' => $verificationURL,
            'verificationCode' => $code,
            'expiryMinutes' => '24 hours'
        ];

        return self::sendTemplateEmail($email, 'email_verification', $variables);
    }

    /**
     * 🔑 Send Password Reset Email
     *
     * @param string $email Recipient email
     * @param string $displayName User display name
     * @param string $token Reset token
     * @param string $code Reset code
     * @return bool Success status
     */
    public static function sendPasswordResetEmail(
        string $email,
        string $displayName,
        string $token,
        string $code
    ): bool {
        $baseURL = getSetting('url.base', 'https://signulo.id');
        $resetURL = "{$baseURL}/reset-password?token={$token}";

        $variables = [
            'displayName' => $displayName,
            'email' => $email,
            'resetUrl' => $resetURL,
            'resetCode' => $code,
            'expiryMinutes' => '30'
        ];

        return self::sendTemplateEmail($email, 'password_reset', $variables);
    }

    /**
     * 🔓 Send Passwordless Login Email
     *
     * @param string $email Recipient email
     * @param string $displayName User display name
     * @param string $token Login token
     * @param string $code Login code
     * @return bool Success status
     */
    public static function sendPasswordlessLoginEmail(
        string $email,
        string $displayName,
        string $token,
        string $code
    ): bool {
        $baseURL = getSetting('url.base', 'https://signulo.id');
        $loginURL = "{$baseURL}/passwordless-login?token={$token}";

        $variables = [
            'displayName' => $displayName,
            'email' => $email,
            'loginUrl' => $loginURL,
            'loginCode' => $code,
            'expiryMinutes' => '15'
        ];

        return self::sendTemplateEmail($email, 'passwordless_login', $variables);
    }

    /**
     * 🔄 Replace Variables in Template
     *
     * @param string $template Template string
     * @param array $variables Variables to replace
     * @return string Processed template
     */
    private static function replaceVariables(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace("{{{$key}}}", $value, $template);
        }

        return $template;
    }

    /**
     * 📤 Process Email Queue
     *
     * Processes pending emails in the queue using configured email providers.
     * Should be called by cron job or scheduled task.
     *
     * Uses the EmailQueueProcessor which supports:
     * - Multiple email providers (Microsoft Graph, Gmail API, SMTP)
     * - Provider priority and fallback
     * - Automatic retry with exponential backoff
     * - Dead letter queue for permanent failures
     *
     * @param int $batchSize Number of emails to process
     * @param bool $verbose Enable verbose output (useful for debugging)
     * @return array Processing statistics
     */
    public static function processQueue(int $batchSize = 50, bool $verbose = false): array
    {
        try {
            // 🚀 Load email queue processor
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'EmailQueueProcessor.php';

            // 📧 Process queue
            $processor = new EmailQueueProcessor($verbose);
            $stats = $processor->process($batchSize);

            return $stats;

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'retried' => 0,
                'dead_letter' => 0,
                'duration' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 📊 Get Queue Statistics
     *
     * Returns current email queue statistics.
     *
     * @return array Queue statistics
     */
    public static function getQueueStats(): array
    {
        try {
            // 🚀 Load email queue processor
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'EmailQueueProcessor.php';

            return EmailQueueProcessor::getQueueStatistics();

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return [
                'total' => 0,
                'pending' => 0,
                'sent' => 0,
                'failed' => 0,
                'retried' => 0,
                'oldest_pending' => null,
                'last_sent' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 🧹 Cleanup Old Sent Emails
     *
     * Removes old sent emails from queue for housekeeping.
     *
     * @param int $daysOld Number of days to keep (default: 30)
     * @return int Number of emails deleted
     */
    public static function cleanupOldEmails(int $daysOld = 30): int
    {
        try {
            // 🚀 Load email queue processor
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'EmailQueueProcessor.php';

            return EmailQueueProcessor::cleanupOldEmails($daysOld);

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return 0;
        }
    }
}

// ✅ EmailService loaded successfully
