<?php
/**
 * ============================================================================
 * 📧 SIGNula - Amazon SES Email Provider
 * ============================================================================
 *
 * Purpose: Send emails via Amazon Simple Email Service (SES)
 * PHP Version: 8.3+
 *
 * Features:
 * - AWS SES API v2 integration
 * - Cost-effective email delivery
 * - High deliverability rates
 * - AWS ecosystem integration
 * - Configurable regions
 *
 * Setup Requirements:
 * 1. AWS account
 * 2. IAM user with SES permissions
 * 3. Access key and secret key
 * 4. Verified email addresses or domain
 * 5. Region configuration
 *
 * Documentation:
 * @see https://docs.aws.amazon.com/ses/latest/APIReference/API_SendEmail.html
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
 * 📧 Amazon SES Email Provider
 *
 * Sends emails through Amazon Simple Email Service.
 */
class AmazonSESEmailProvider extends EmailProvider
{
    /**
     * @var array SES API endpoints by region
     */
    private const SES_REGIONS = [
        'us-east-1' => 'email.us-east-1.amazonaws.com',
        'us-west-2' => 'email.us-west-2.amazonaws.com',
        'eu-west-1' => 'email.eu-west-1.amazonaws.com',
        'eu-central-1' => 'email.eu-central-1.amazonaws.com',
        'ap-southeast-1' => 'email.ap-southeast-1.amazonaws.com',
        'ap-southeast-2' => 'email.ap-southeast-2.amazonaws.com',
        'ap-northeast-1' => 'email.ap-northeast-1.amazonaws.com'
    ];

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('amazon_ses');
    }

    // ========================================================================
    // ⚙️ CONFIGURATION
    // ========================================================================

    /**
     * 📋 Load Configuration
     *
     * Loads Amazon SES configuration from database.
     */
    protected function loadConfiguration(): void
    {
        try {
            // 🔍 Get configuration from database
            $this->config['region'] = getSetting('email.ses.region', 'us-east-1');
            $this->config['access_key'] = getSetting('email.ses.access_key', '');

            $encryptedSecretKey = getSetting('email.ses.secret_key', '');
            if (!empty($encryptedSecretKey)) {
                $this->config['secret_key'] = SecurityUtils::decrypt($encryptedSecretKey);
            }

            $this->config['send_from_email'] = getSetting('email.ses.from_email', '');
            $this->config['send_from_name'] = getSetting('email.ses.from_name', 'SIGNula');

            // ✅ Validate configuration
            $this->isConfigured = !empty($this->config['access_key']) &&
                                 !empty($this->config['secret_key']) &&
                                 !empty($this->config['send_from_email']);

        } catch (Exception $e) {
            error_log("Amazon SES email configuration error: " . $e->getMessage());
            $this->isConfigured = false;
        }
    }

    // ========================================================================
    // 📧 EMAIL SENDING
    // ========================================================================

    /**
     * 📨 Send Email via Amazon SES API
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

            // 📝 Build SES message
            $params = $this->buildSESMessage($emailData);

            // 🔐 Sign and send request
            $response = $this->sendSESRequest($params);

            // ✅ Extract message ID from response
            $messageId = $this->extractMessageId($response);

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

            error_log("Amazon SES send email error: " . $error);

            return [
                'success' => false,
                'messageId' => null,
                'error' => $error
            ];
        }
    }

    /**
     * 🔨 Build SES Message Parameters
     *
     * Constructs email message parameters for SES API.
     *
     * @param array $emailData Email data
     * @return array SES API parameters
     */
    private function buildSESMessage(array $emailData): array
    {
        $params = [
            'Action' => 'SendEmail',
            'Source' => $this->formatEmailAddress(
                $emailData['fromName'] ?? $this->config['send_from_name'],
                $emailData['from'] ?? $this->config['send_from_email']
            ),
            'Destination.ToAddresses.member.1' => $emailData['to'],
            'Message.Subject.Data' => $emailData['subject'],
            'Message.Subject.Charset' => 'UTF-8'
        ];

        // 📝 Add body text
        if (!empty($emailData['bodyText'])) {
            $params['Message.Body.Text.Data'] = $emailData['bodyText'];
            $params['Message.Body.Text.Charset'] = 'UTF-8';
        }

        // 🎨 Add HTML body
        if (!empty($emailData['bodyHTML'])) {
            $params['Message.Body.Html.Data'] = $emailData['bodyHTML'];
            $params['Message.Body.Html.Charset'] = 'UTF-8';
        }

        // 🔄 Set reply-to
        if (!empty($emailData['replyTo'])) {
            $params['ReplyToAddresses.member.1'] = $emailData['replyTo'];
        }

        // 📋 Add CC recipients
        if (!empty($emailData['cc']) && is_array($emailData['cc'])) {
            $ccIndex = 1;
            foreach ($emailData['cc'] as $cc) {
                $params["Destination.CcAddresses.member.{$ccIndex}"] = $cc;
                $ccIndex++;
            }
        }

        // 📋 Add BCC recipients
        if (!empty($emailData['bcc']) && is_array($emailData['bcc'])) {
            $bccIndex = 1;
            foreach ($emailData['bcc'] as $bcc) {
                $params["Destination.BccAddresses.member.{$bccIndex}"] = $bcc;
                $bccIndex++;
            }
        }

        return $params;
    }

    /**
     * 🌐 Send SES Request
     *
     * Sends signed request to Amazon SES API.
     *
     * @param array $params Request parameters
     * @return string Response body
     * @throws RuntimeException If request fails
     */
    private function sendSESRequest(array $params): string
    {
        $region = $this->config['region'];
        $host = self::SES_REGIONS[$region] ?? self::SES_REGIONS['us-east-1'];
        $url = 'https://' . $host . '/';

        // 📅 Timestamp
        $date = gmdate('D, d M Y H:i:s T');

        // 🔐 Create signature
        $signature = base64_encode(hash_hmac('sha256', $date, $this->config['secret_key'], true));

        // 📋 Headers
        $headers = [
            'Date: ' . $date,
            'Host: ' . $host,
            'Content-Type: application/x-www-form-urlencoded',
            'X-Amzn-Authorization: AWS3-HTTPS AWSAccessKeyId=' . $this->config['access_key'] .
            ', Algorithm=HmacSHA256, Signature=' . $signature
        ];

        // 🌐 Send request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new RuntimeException("SES API error: HTTP $httpCode - $response");
        }

        return $response;
    }

    /**
     * 🆔 Extract Message ID from Response
     *
     * Extracts message ID from SES XML response.
     *
     * @param string $response XML response
     * @return string Message ID
     */
    private function extractMessageId(string $response): string
    {
        // 📦 Parse XML response
        $xml = simplexml_load_string($response);

        if ($xml === false) {
            throw new RuntimeException('Failed to parse SES response');
        }

        // 🔍 Extract message ID
        $messageId = (string)$xml->SendEmailResult->MessageId;

        if (empty($messageId)) {
            throw new RuntimeException('No message ID in SES response');
        }

        return $messageId;
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
     * Returns setup instructions for Amazon SES.
     *
     * @return array Setup instructions
     */
    public static function getSetupInstructions(): array
    {
        return [
            'title' => 'Amazon SES Email Setup',
            'requirements' => [
                'AWS account',
                'IAM user with SES permissions',
                'Verified email address or domain',
                'Region selection',
                'Production access (default is sandbox mode)'
            ],
            'steps' => [
                '1. Log in to AWS Console',
                '2. Navigate to Amazon SES service',
                '3. Select your region (e.g., us-east-1)',
                '4. Go to Verified identities',
                '5. Click "Create identity"',
                '6. Verify your domain or email address',
                '7. Configure DKIM and SPF records',
                '8. Go to IAM > Users',
                '9. Create new user or select existing',
                '10. Attach policy: AmazonSESFullAccess',
                '11. Create access key',
                '12. Copy Access Key ID and Secret Access Key',
                '13. Request production access if needed (Account Dashboard)',
                '14. Store credentials in SIGNula settings'
            ],
            'database_settings' => [
                'email.ses.access_key' => 'Your AWS Access Key ID',
                'email.ses.secret_key' => 'Your AWS Secret Access Key (will be encrypted)',
                'email.ses.region' => 'AWS region (e.g., us-east-1, eu-west-1)',
                'email.ses.from_email' => 'noreply@yourdomain.com',
                'email.ses.from_name' => 'SIGNula'
            ],
            'features' => [
                'Extremely cost-effective ($0.10 per 1,000 emails)',
                'High deliverability rates',
                'AWS ecosystem integration',
                'Detailed metrics and monitoring',
                'Email receiving capabilities',
                'Dedicated IP addresses available'
            ],
            'benefits' => [
                'Very low cost',
                'Scales automatically',
                'Excellent reputation management',
                'CloudWatch integration',
                'SNS notifications for bounces/complaints',
                'Global infrastructure'
            ],
            'notes' => [
                'Default: Sandbox mode (200 emails/day, verified recipients only)',
                'Production access: Unlimited sending (with reasonable limits)',
                'Pricing: $0.10 per 1,000 emails (first 62,000 free with EC2)',
                'Must verify sender email/domain',
                'Different regions have different features',
                'Recommend requesting production access'
            ]
        ];
    }
}

// ✅ AmazonSESEmailProvider class loaded successfully
