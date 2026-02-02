<?php
/**
 * ============================================================================
 * 📧 SIGNula - Mailgun Email Provider
 * ============================================================================
 *
 * Purpose: Send emails via Mailgun API
 * PHP Version: 8.3+
 *
 * Features:
 * - Mailgun API v3 integration
 * - Powerful email validation
 * - Advanced routing and tracking
 * - Detailed analytics
 * - EU and US region support
 *
 * Setup Requirements:
 * 1. Mailgun account
 * 2. Verified domain
 * 3. API key
 * 4. Region selection (US or EU)
 *
 * Documentation:
 * @see https://documentation.mailgun.com/en/latest/api-sending.html
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
 * 📧 Mailgun Email Provider
 *
 * Sends emails through Mailgun API.
 */
class MailgunEmailProvider extends EmailProvider
{
    /**
     * @var array API endpoints by region
     */
    private const API_ENDPOINTS = [
        'us' => 'https://api.mailgun.net/v3',
        'eu' => 'https://api.eu.mailgun.net/v3'
    ];

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('mailgun');
    }

    // ========================================================================
    // ⚙️ CONFIGURATION
    // ========================================================================

    /**
     * 📋 Load Configuration
     *
     * Loads Mailgun API configuration from database.
     */
    protected function loadConfiguration(): void
    {
        try {
            // 🔍 Get configuration from database
            $this->config['domain'] = getSetting('email.mailgun.domain', '');
            $this->config['region'] = getSetting('email.mailgun.region', 'us'); // us or eu

            $encryptedApiKey = getSetting('email.mailgun.api_key', '');
            if (!empty($encryptedApiKey)) {
                $this->config['api_key'] = SecurityUtils::decrypt($encryptedApiKey);
            }

            $this->config['send_from_email'] = getSetting('email.mailgun.from_email', '');
            $this->config['send_from_name'] = getSetting('email.mailgun.from_name', 'SIGNula');

            // ✅ Validate configuration
            $this->isConfigured = !empty($this->config['api_key']) &&
                                 !empty($this->config['domain']) &&
                                 !empty($this->config['send_from_email']);

        } catch (Exception $e) {
            error_log("Mailgun email configuration error: " . $e->getMessage());
            $this->isConfigured = false;
        }
    }

    // ========================================================================
    // 📧 EMAIL SENDING
    // ========================================================================

    /**
     * 📨 Send Email via Mailgun API
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

            // 🔗 Build API endpoint
            $region = $this->config['region'] ?? 'us';
            $apiBase = self::API_ENDPOINTS[$region] ?? self::API_ENDPOINTS['us'];
            $apiUrl = $apiBase . '/' . $this->config['domain'] . '/messages';

            // 📝 Build message data
            $postData = $this->buildMailgunMessage($emailData);

            // 🔐 Prepare headers with HTTP Basic Auth
            $headers = [
                'Authorization: Basic ' . base64_encode('api:' . $this->config['api_key'])
            ];

            // 🌐 Send via Mailgun API (using form data, not JSON)
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new RuntimeException("Mailgun API error: HTTP $httpCode - $response");
            }

            $result = json_decode($response, true);

            if (empty($result['id'])) {
                throw new RuntimeException('No message ID in Mailgun response');
            }

            $messageId = $result['id'];

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

            error_log("Mailgun send email error: " . $error);

            return [
                'success' => false,
                'messageId' => null,
                'error' => $error
            ];
        }
    }

    /**
     * 🔨 Build Mailgun Message Data
     *
     * Constructs email message in Mailgun API format.
     *
     * @param array $emailData Email data
     * @return array Mailgun API message data
     */
    private function buildMailgunMessage(array $emailData): array
    {
        // 📧 Build message data
        $message = [
            'from' => $this->formatEmailAddress(
                $emailData['fromName'] ?? $this->config['send_from_name'],
                $emailData['from'] ?? $this->config['send_from_email']
            ),
            'to' => $emailData['to'],
            'subject' => $emailData['subject']
        ];

        // 📝 Add body (Mailgun supports both text and html in same request)
        if (!empty($emailData['bodyText'])) {
            $message['text'] = $emailData['bodyText'];
        }

        if (!empty($emailData['bodyHTML'])) {
            $message['html'] = $emailData['bodyHTML'];
        }

        // 🔄 Set reply-to
        if (!empty($emailData['replyTo'])) {
            $message['h:Reply-To'] = $emailData['replyTo'];
        }

        // 📋 Add CC recipients
        if (!empty($emailData['cc']) && is_array($emailData['cc'])) {
            $message['cc'] = implode(', ', $emailData['cc']);
        }

        // 📋 Add BCC recipients
        if (!empty($emailData['bcc']) && is_array($emailData['bcc'])) {
            $message['bcc'] = implode(', ', $emailData['bcc']);
        }

        // 📎 Note: Attachments would require multipart/form-data
        // For simplicity, not implementing in this version

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
     * Returns setup instructions for Mailgun email.
     *
     * @return array Setup instructions
     */
    public static function getSetupInstructions(): array
    {
        return [
            'title' => 'Mailgun Email Setup',
            'requirements' => [
                'Mailgun account',
                'Verified domain',
                'API key',
                'DNS records configured (SPF, DKIM, MX)'
            ],
            'steps' => [
                '1. Sign up for Mailgun account (mailgun.com)',
                '2. Go to Sending > Domains',
                '3. Add and verify your domain',
                '4. Configure DNS records (SPF, DKIM, MX) as shown',
                '5. Wait for domain verification (can take 24-48 hours)',
                '6. Go to Settings > API Keys',
                '7. Copy your Private API key',
                '8. Note your region (US or EU)',
                '9. Store credentials in SIGNula database settings'
            ],
            'database_settings' => [
                'email.mailgun.domain' => 'Your verified domain (e.g., mg.yourdomain.com)',
                'email.mailgun.api_key' => 'Your Mailgun API key (will be encrypted)',
                'email.mailgun.region' => 'us or eu (default: us)',
                'email.mailgun.from_email' => 'noreply@mg.yourdomain.com',
                'email.mailgun.from_name' => 'SIGNula'
            ],
            'features' => [
                'Powerful email validation',
                'Advanced routing and filtering',
                'Detailed logs and analytics',
                'Webhook support',
                'Template support',
                'SMTP and API sending',
                'EU and US regions'
            ],
            'benefits' => [
                'Developer-friendly API',
                'Excellent deliverability',
                'Detailed tracking and logs',
                'Flexible pricing',
                'Global infrastructure',
                'GDPR compliant (EU region)'
            ],
            'notes' => [
                'Free tier: 5,000 emails/month for 3 months',
                'Paid plans start at $35/month for 50,000 emails',
                'Must verify domain with DNS records',
                'Different API endpoints for US vs EU',
                'Comprehensive webhook events available'
            ]
        ];
    }
}

// ✅ MailgunEmailProvider class loaded successfully
