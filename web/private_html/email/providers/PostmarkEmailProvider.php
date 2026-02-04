<?php
/**
 * ============================================================================
 * 📧 SIGNula - Postmark Email Provider
 * ============================================================================
 *
 * Purpose: Send emails via Postmark API
 * PHP Version: 8.3+
 *
 * Features:
 * - Postmark API integration
 * - Focus on transactional emails
 * - Excellent deliverability
 * - Beautiful email analytics
 * - Template support
 *
 * Setup Requirements:
 * 1. Postmark account
 * 2. Server (sender signature)
 * 3. API token
 * 4. Verified domain
 *
 * Documentation:
 * @see https://postmarkapp.com/developer/api/email-api
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
 * 📧 Postmark Email Provider
 *
 * Sends emails through Postmark API.
 */
class PostmarkEmailProvider extends EmailProvider
{
    /**
     * @var string Postmark API endpoint
     */
    private const POSTMARK_API_URL = 'https://api.postmarkapp.com/email';

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('postmark');
    }

    // ========================================================================
    // ⚙️ CONFIGURATION
    // ========================================================================

    /**
     * 📋 Load Configuration
     *
     * Loads Postmark API configuration from database.
     */
    protected function loadConfiguration(): void
    {
        try {
            // 🔍 Get configuration from database
            $encryptedApiToken = getSetting('email.postmark.api_token', '');
            if (!empty($encryptedApiToken)) {
                $this->config['api_token'] = SecurityUtils::decrypt($encryptedApiToken);
            }

            $this->config['send_from_email'] = getSetting('email.postmark.from_email', '');
            $this->config['send_from_name'] = getSetting('email.postmark.from_name', 'SIGNula');
            $this->config['track_opens'] = (bool)getSetting('email.postmark.track_opens', false);
            $this->config['track_links'] = (bool)getSetting('email.postmark.track_links', false);

            // ✅ Validate configuration
            $this->isConfigured = !empty($this->config['api_token']) &&
                                 !empty($this->config['send_from_email']);

        } catch (Exception $e) {
            error_log("Postmark email configuration error: " . $e->getMessage());
            $this->isConfigured = false;
        }
    }

    // ========================================================================
    // 📧 EMAIL SENDING
    // ========================================================================

    /**
     * 📨 Send Email via Postmark API
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

            // 📝 Build Postmark message
            $message = $this->buildPostmarkMessage($emailData);

            // 🌐 Send via Postmark API
            $headers = [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Postmark-Server-Token: ' . $this->config['api_token']
            ];

            $response = $this->makeHttpRequest(
                self::POSTMARK_API_URL,
                'POST',
                $message,
                $headers
            );

            // ✅ Extract message ID
            if (empty($response['MessageID'])) {
                throw new RuntimeException('No message ID in Postmark response');
            }

            $messageId = $response['MessageID'];

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

            error_log("Postmark send email error: " . $error);

            return [
                'success' => false,
                'messageId' => null,
                'error' => $error
            ];
        }
    }

    /**
     * 🔨 Build Postmark Message Object
     *
     * Constructs email message in Postmark API format.
     *
     * @param array $emailData Email data
     * @return array Postmark API message object
     */
    private function buildPostmarkMessage(array $emailData): array
    {
        // 📧 Build message structure
        $message = [
            'From' => $this->formatEmailAddress(
                $emailData['fromName'] ?? $this->config['send_from_name'],
                $emailData['from'] ?? $this->config['send_from_email']
            ),
            'To' => $emailData['to'],
            'Subject' => $emailData['subject'],
            'TrackOpens' => $this->config['track_opens'],
            'TrackLinks' => $this->config['track_links'] ? 'HtmlAndText' : 'None'
        ];

        // 📝 Add plain text body
        if (!empty($emailData['bodyText'])) {
            $message['TextBody'] = $emailData['bodyText'];
        }

        // 🎨 Add HTML body
        if (!empty($emailData['bodyHTML'])) {
            $message['HtmlBody'] = $emailData['bodyHTML'];
        }

        // 🔄 Set reply-to
        if (!empty($emailData['replyTo'])) {
            $message['ReplyTo'] = $emailData['replyTo'];
        }

        // 📋 Add CC recipients
        if (!empty($emailData['cc']) && is_array($emailData['cc'])) {
            $message['Cc'] = implode(', ', $emailData['cc']);
        }

        // 📋 Add BCC recipients
        if (!empty($emailData['bcc']) && is_array($emailData['bcc'])) {
            $message['Bcc'] = implode(', ', $emailData['bcc']);
        }

        // 📎 Add attachments
        if (!empty($emailData['attachments']) && is_array($emailData['attachments'])) {
            $message['Attachments'] = [];
            foreach ($emailData['attachments'] as $attachment) {
                if (!empty($attachment['content']) && !empty($attachment['name'])) {
                    $message['Attachments'][] = [
                        'Name' => $attachment['name'],
                        'Content' => base64_encode($attachment['content']),
                        'ContentType' => $attachment['type'] ?? 'application/octet-stream'
                    ];
                }
            }
        }

        return $message;
    }

    /**
     * 📧 Format Email Address
     *
     * Formats email address with name.
     *
     * @param string $name Display name
     * @param string $email Email address
     * @return string Formatted email address
     */
    private function formatEmailAddress(string $name, string $email): string
    {
        if (empty($name)) {
            return $email;
        }

        return $name . ' <' . $email . '>';
    }

    // ========================================================================
    // 🔧 SETUP HELPERS
    // ========================================================================

    /**
     * 📋 Get Setup Instructions
     *
     * Returns setup instructions for Postmark email.
     *
     * @return array Setup instructions
     */
    public static function getSetupInstructions(): array
    {
        return [
            'title' => 'Postmark Email Setup',
            'requirements' => [
                'Postmark account',
                'Server (sender signature)',
                'Server API token',
                'Verified sender signature or domain'
            ],
            'steps' => [
                '1. Sign up for Postmark account (postmarkapp.com)',
                '2. Create a new Server (or use default)',
                '3. Go to your Server > Settings',
                '4. Copy the Server API Token',
                '5. Go to Sender Signatures',
                '6. Add and verify your sending domain or email',
                '7. Configure DKIM records for your domain',
                '8. Enable tracking if desired (opens/clicks)',
                '9. Store credentials in SIGNula database settings'
            ],
            'database_settings' => [
                'email.postmark.api_token' => 'Your Postmark Server API Token (will be encrypted)',
                'email.postmark.from_email' => 'noreply@yourdomain.com',
                'email.postmark.from_name' => 'SIGNula',
                'email.postmark.track_opens' => 'true or false (default: false)',
                'email.postmark.track_links' => 'true or false (default: false)'
            ],
            'features' => [
                'Focus on transactional emails',
                'Excellent deliverability (45-day average)',
                'Beautiful email analytics dashboard',
                'Template support',
                'Open and click tracking',
                'Bounce handling',
                'Spam analysis tools',
                'Webhooks for events'
            ],
            'benefits' => [
                'Simple, clean API',
                'Excellent deliverability focus',
                'Great developer experience',
                'Beautiful analytics',
                'Helpful error messages',
                'Good documentation',
                'Fair pricing'
            ],
            'notes' => [
                'Free tier: 100 emails/month',
                'Paid plans start at $15/month for 10,000 emails',
                'Must verify sender signature before sending',
                'Focused on transactional emails (not marketing)',
                'Excellent support team',
                'Track opens via 1x1 pixel',
                'Track links via URL rewriting'
            ]
        ];
    }
}

// ✅ PostmarkEmailProvider class loaded successfully
