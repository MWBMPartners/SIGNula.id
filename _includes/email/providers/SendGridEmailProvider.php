<?php
/**
 * ============================================================================
 * 📧 SIGNula - SendGrid Email Provider
 * ============================================================================
 *
 * Purpose: Send emails via SendGrid API
 * PHP Version: 8.3+
 *
 * Features:
 * - SendGrid Web API v3 integration
 * - Excellent deliverability and reputation management
 * - Built-in analytics and tracking
 * - Template support
 * - Attachment support
 *
 * Setup Requirements:
 * 1. SendGrid account
 * 2. API key with Mail Send permissions
 * 3. Verified sender identity or domain
 *
 * Documentation:
 * @see https://docs.sendgrid.com/api-reference/mail-send/mail-send
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

// 📚 Require base class
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'EmailProvider.php';

/**
 * 📧 SendGrid Email Provider
 *
 * Sends emails through SendGrid using Web API v3.
 */
class SendGridEmailProvider extends EmailProvider
{
    /**
     * @var string SendGrid API endpoint
     */
    private const SENDGRID_API_URL = 'https://api.sendgrid.com/v3/mail/send';

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('sendgrid');
    }

    // ========================================================================
    // ⚙️ CONFIGURATION
    // ========================================================================

    /**
     * 📋 Load Configuration
     *
     * Loads SendGrid API configuration from database.
     */
    protected function loadConfiguration(): void
    {
        try {
            // 🔍 Get configuration from database
            $this->config['send_from_email'] = getSetting('email.sendgrid.from_email', '');
            $this->config['send_from_name'] = getSetting('email.sendgrid.from_name', 'SIGNula');

            $encryptedApiKey = getSetting('email.sendgrid.api_key', '');
            if (!empty($encryptedApiKey)) {
                $this->config['api_key'] = SecurityUtils::decrypt($encryptedApiKey);
            }

            // ✅ Validate configuration
            $this->isConfigured = !empty($this->config['api_key']) &&
                                 !empty($this->config['send_from_email']);

        } catch (Exception $e) {
            error_log("SendGrid email configuration error: " . $e->getMessage());
            $this->isConfigured = false;
        }
    }

    // ========================================================================
    // 📧 EMAIL SENDING
    // ========================================================================

    /**
     * 📨 Send Email via SendGrid API
     *
     * @param array $emailData Email data
     * @return array Result with success status and message ID
     */
    public function send(array $emailData): array
    {
        try {
            // ✅ Validate email data
            $validation = $this->validateEmailData($emailData);
            if (!$validation['valid']) {
                $error = implode(', ', $validation['errors']);
                $this->logEmailActivity($emailData, false, null, $error);
                return ['success' => false, 'messageId' => null, 'error' => $error];
            }

            // 📝 Build SendGrid message
            $message = $this->buildSendGridMessage($emailData);

            // 🌐 Send via SendGrid API
            $headers = [
                'Authorization: Bearer ' . $this->config['api_key'],
                'Content-Type: application/json'
            ];

            $response = $this->makeHttpRequest(
                self::SENDGRID_API_URL,
                'POST',
                $message,
                $headers
            );

            // ✅ SendGrid returns 202 Accepted on success
            // Message ID is in X-Message-Id header (we'll generate our own for tracking)
            $messageId = uniqid('sg_', true);

            // 📝 Log success
            $this->logEmailActivity($emailData, true, $messageId);

            return [
                'success' => true,
                'messageId' => $messageId,
                'error' => null
            ];

        } catch (Exception $e) {
            // ❌ Log failure
            $error = $e->getMessage();
            $this->logEmailActivity($emailData, false, null, $error);

            error_log("SendGrid send email error: " . $error);

            return [
                'success' => false,
                'messageId' => null,
                'error' => $error
            ];
        }
    }

    /**
     * 🔨 Build SendGrid Message Object
     *
     * Constructs email message in SendGrid API v3 format.
     *
     * @param array $emailData Email data
     * @return array SendGrid API message object
     */
    private function buildSendGridMessage(array $emailData): array
    {
        // 📧 Build message structure
        $message = [
            'personalizations' => [
                [
                    'to' => [
                        ['email' => $emailData['to']]
                    ]
                ]
            ],
            'from' => [
                'email' => $emailData['from'] ?? $this->config['send_from_email'],
                'name' => $emailData['fromName'] ?? $this->config['send_from_name']
            ],
            'subject' => $emailData['subject'],
            'content' => []
        ];

        // 📝 Add plain text content
        if (!empty($emailData['bodyText'])) {
            $message['content'][] = [
                'type' => 'text/plain',
                'value' => $emailData['bodyText']
            ];
        }

        // 🎨 Add HTML content
        if (!empty($emailData['bodyHTML'])) {
            $message['content'][] = [
                'type' => 'text/html',
                'value' => $emailData['bodyHTML']
            ];
        }

        // 🔄 Set reply-to
        if (!empty($emailData['replyTo'])) {
            $message['reply_to'] = [
                'email' => $emailData['replyTo']
            ];
        }

        // 📋 Add CC recipients
        if (!empty($emailData['cc']) && is_array($emailData['cc'])) {
            $message['personalizations'][0]['cc'] = [];
            foreach ($emailData['cc'] as $cc) {
                $message['personalizations'][0]['cc'][] = ['email' => $cc];
            }
        }

        // 📋 Add BCC recipients
        if (!empty($emailData['bcc']) && is_array($emailData['bcc'])) {
            $message['personalizations'][0]['bcc'] = [];
            foreach ($emailData['bcc'] as $bcc) {
                $message['personalizations'][0]['bcc'][] = ['email' => $bcc];
            }
        }

        // 📎 Add attachments
        if (!empty($emailData['attachments']) && is_array($emailData['attachments'])) {
            $message['attachments'] = [];
            foreach ($emailData['attachments'] as $attachment) {
                if (!empty($attachment['content']) && !empty($attachment['name'])) {
                    $message['attachments'][] = [
                        'content' => base64_encode($attachment['content']),
                        'filename' => $attachment['name'],
                        'type' => $attachment['type'] ?? 'application/octet-stream',
                        'disposition' => 'attachment'
                    ];
                }
            }
        }

        return $message;
    }

    // ========================================================================
    // 🔧 SETUP HELPERS
    // ========================================================================

    /**
     * 📋 Get Setup Instructions
     *
     * Returns setup instructions for SendGrid email.
     *
     * @return array Setup instructions
     */
    public static function getSetupInstructions(): array
    {
        return [
            'title' => 'SendGrid Email Setup',
            'requirements' => [
                'SendGrid account (free tier available)',
                'Verified sender identity or domain',
                'API key with Mail Send permissions'
            ],
            'steps' => [
                '1. Sign up for SendGrid account (sendgrid.com)',
                '2. Navigate to Settings > Sender Authentication',
                '3. Verify your sender identity (single sender or domain)',
                '4. Go to Settings > API Keys',
                '5. Click "Create API Key"',
                '6. Name: "SIGNula Email Service"',
                '7. Permissions: Select "Restricted Access"',
                '8. Enable "Mail Send" permission',
                '9. Click "Create & View"',
                '10. Copy the API key immediately (shown only once!)',
                '11. Store in SIGNula database settings (will be encrypted)'
            ],
            'database_settings' => [
                'email.sendgrid.api_key' => 'Your SendGrid API key (will be encrypted)',
                'email.sendgrid.from_email' => 'noreply@yourdomain.com',
                'email.sendgrid.from_name' => 'SIGNula'
            ],
            'features' => [
                'Web API v3 integration',
                'Excellent deliverability',
                'Built-in analytics dashboard',
                'Automatic list management',
                'Click and open tracking',
                'Template support',
                'Generous free tier (100 emails/day)'
            ],
            'benefits' => [
                'Easy setup - just need API key',
                'Free tier available for testing',
                'Excellent reputation management',
                'Real-time analytics',
                'Webhook support for events',
                'Global infrastructure'
            ],
            'notes' => [
                'Free tier: 100 emails/day forever',
                'Paid plans start at $14.95/month for 50,000 emails',
                'Must verify sender identity before sending',
                'API key should have Mail Send permission only',
                'Rate limits vary by plan'
            ]
        ];
    }
}

// ✅ SendGridEmailProvider class loaded successfully
