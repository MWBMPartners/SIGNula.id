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
     * @param int $priority Priority
     * @param \DateTime|null $scheduledFor Scheduled send time
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
        ?\DateTime $scheduledFor = null
    ): bool {
        try {
            $fromEmail = $fromEmail ?? getSetting('email.from.address', 'noreply@signulo.id');
            $fromName = $fromName ?? getSetting('email.from.name', 'SIGNula');

            $query = "
                INSERT INTO tblEmailQueue (
                    userID, templateID, recipientEmail, recipientName,
                    subject, bodyHTML, bodyText,
                    fromEmail, fromName, replyTo,
                    priority, status, scheduledFor
                ) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
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
                $priority,
                $scheduledFor ? $scheduledFor->format('Y-m-d H:i:s') : null
            ], 'iissssssssis');

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
     * Processes pending emails in the queue.
     * Should be called by cron job or scheduled task.
     *
     * @param int $batchSize Number of emails to process
     * @return int Number of emails sent
     */
    public static function processQueue(int $batchSize = 50): int
    {
        try {
            // Get pending emails
            $query = "
                SELECT *
                FROM tblEmailQueue
                WHERE status = 'pending'
                AND attempts < maxAttempts
                AND (scheduledFor IS NULL OR scheduledFor <= NOW())
                ORDER BY priority ASC, createdAt ASC
                LIMIT ?
            ";

            $emails = Database::fetchAll($query, [$batchSize], 'i');
            $sentCount = 0;

            foreach ($emails as $email) {
                if (self::sendEmail($email)) {
                    $sentCount++;
                }
            }

            return $sentCount;

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return 0;
        }
    }

    /**
     * 📧 Send Individual Email
     *
     * @param array $emailData Email data from queue
     * @return bool Success status
     */
    private static function sendEmail(array $emailData): bool
    {
        try {
            // Update status to sending
            Database::query(
                "UPDATE tblEmailQueue SET status = 'sending', attempts = attempts + 1, lastAttemptAt = NOW() WHERE queueID = ?",
                [$emailData['queueID']],
                'i'
            );

            $headers = [
                'From' => $emailData['fromName'] ? "{$emailData['fromName']} <{$emailData['fromEmail']}>" : $emailData['fromEmail'],
                'Reply-To' => $emailData['replyTo'] ?? $emailData['fromEmail'],
                'MIME-Version' => '1.0',
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Mailer' => 'SIGNula Mailer'
            ];

            $headerString = '';
            foreach ($headers as $key => $value) {
                $headerString .= "{$key}: {$value}\r\n";
            }

            // Send email using PHP mail() function
            // For production, consider using SMTP or email service API
            $sent = mail(
                $emailData['recipientEmail'],
                $emailData['subject'],
                $emailData['bodyHTML'] ?? $emailData['bodyText'],
                $headerString
            );

            if ($sent) {
                // Mark as sent
                Database::query(
                    "UPDATE tblEmailQueue SET status = 'sent', sentAt = NOW() WHERE queueID = ?",
                    [$emailData['queueID']],
                    'i'
                );
                return true;
            } else {
                // Mark as failed
                Database::query(
                    "UPDATE tblEmailQueue SET status = 'failed', errorMessage = ? WHERE queueID = ?",
                    ['Failed to send email', $emailData['queueID']],
                    'si'
                );
                return false;
            }

        } catch (Exception $e) {
            // Mark as failed with error message
            Database::query(
                "UPDATE tblEmailQueue SET status = 'failed', errorMessage = ? WHERE queueID = ?",
                [$e->getMessage(), $emailData['queueID']],
                'si'
            );

            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }
}

// ✅ EmailService loaded successfully
