<?php
/**
 * ============================================================================
 * 📧 SIGNula - Gmail API Email Provider
 * ============================================================================
 *
 * Purpose: Send emails via Google Workspace using Gmail API
 * PHP Version: 8.3+
 *
 * Features:
 * - OAuth 2.0 service account authentication
 * - Google Workspace / G Suite integration
 * - Excellent deliverability (SPF/DKIM/DMARC compliant)
 * - Sends from your corporate domain
 * - HTML and plain text support
 * - Attachment support
 * - Threading support
 *
 * Setup Requirements:
 * 1. Google Cloud Console project
 * 2. Gmail API enabled
 * 3. Service account created
 * 4. Domain-wide delegation configured
 * 5. Service account key (JSON) downloaded
 * 6. Google Workspace admin access
 *
 * Documentation:
 * @see https://developers.google.com/gmail/api/guides/sending
 * @see https://developers.google.com/workspace/guides/create-credentials#service-account
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
 * 📧 Gmail API Email Provider
 *
 * Sends emails through Google Workspace using Gmail API.
 * Uses OAuth 2.0 service account with domain-wide delegation.
 */
class GmailAPIEmailProvider extends EmailProvider
{
    /**
     * @var string Gmail API endpoint
     */
    private const GMAIL_API_URL = 'https://gmail.googleapis.com/gmail/v1';

    /**
     * @var string OAuth token endpoint
     */
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /**
     * @var string Required OAuth scope
     */
    private const GMAIL_SEND_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    /**
     * @var array Cached access tokens per mailbox
     * Format: ['email@domain.com' => ['token' => 'token_value', 'expires' => timestamp]]
     */
    private array $accessTokens = [];

    /**
     * @var string|null Cached access token (deprecated - kept for backward compatibility)
     */
    private ?string $accessToken = null;

    /**
     * @var int Token expiration timestamp (deprecated - kept for backward compatibility)
     */
    private int $tokenExpires = 0;

    /**
     * @var array Service account credentials
     */
    private array $serviceAccountCredentials = [];

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('gmail_api');
    }

    // ========================================================================
    // ⚙️ CONFIGURATION
    // ========================================================================

    /**
     * 📋 Load Configuration
     *
     * Loads Gmail API configuration from database.
     */
    protected function loadConfiguration(): void
    {
        try {
            // 🔍 Get configuration from database
            $this->config['send_from_email'] = getSetting('email.gmail.from_email', '');
            $this->config['send_from_name'] = getSetting('email.gmail.from_name', 'SIGNula');

            // 🔐 Get encrypted service account JSON
            $encryptedServiceAccount = getSetting('email.gmail.service_account_json', '');
            if (!empty($encryptedServiceAccount)) {
                $decrypted = SecurityUtils::decrypt($encryptedServiceAccount);
                $this->serviceAccountCredentials = json_decode($decrypted, true);
            }

            // ✅ Validate configuration
            $this->isConfigured = !empty($this->config['send_from_email']) &&
                                 !empty($this->serviceAccountCredentials['private_key']) &&
                                 !empty($this->serviceAccountCredentials['client_email']);

        } catch (Exception $e) {
            error_log("Gmail API email configuration error: " . $e->getMessage());
            $this->isConfigured = false;
        }
    }

    // ========================================================================
    // 🔐 AUTHENTICATION
    // ========================================================================

    /**
     * 🔑 Get Access Token
     *
     * Retrieves access token using OAuth 2.0 service account with JWT.
     * Uses domain-wide delegation to send as the specified user.
     * Tokens are cached per mailbox until expiration.
     *
     * @param string|null $impersonateEmail Email address to impersonate (defaults to configured send_from_email)
     * @return string Access token
     * @throws RuntimeException If authentication fails
     */
    private function getAccessToken(?string $impersonateEmail = null): string
    {
        // 🎯 Use configured email as default
        $impersonateEmail = $impersonateEmail ?? $this->config['send_from_email'];

        // ♻️ Return cached token if still valid for this mailbox
        if (isset($this->accessTokens[$impersonateEmail])) {
            $cached = $this->accessTokens[$impersonateEmail];
            if (time() < $cached['expires']) {
                return $cached['token'];
            }
        }

        // 🔙 Backward compatibility: check old cache
        if ($this->accessToken && time() < $this->tokenExpires && $impersonateEmail === $this->config['send_from_email']) {
            return $this->accessToken;
        }

        try {
            // 🔑 Create JWT assertion for specific mailbox
            $jwt = $this->createJWTAssertion($impersonateEmail);

            // 📝 Prepare token request
            $postData = [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ];

            // 🌐 Make token request
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::TOKEN_ENDPOINT);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new RuntimeException("Token request failed: HTTP $httpCode - $response");
            }

            $tokenData = json_decode($response, true);

            if (empty($tokenData['access_token'])) {
                throw new RuntimeException("No access token in response");
            }

            $expiresAt = time() + ($tokenData['expires_in'] ?? 3600) - 60; // 60s buffer

            // 💾 Cache token per mailbox
            $this->accessTokens[$impersonateEmail] = [
                'token' => $tokenData['access_token'],
                'expires' => $expiresAt
            ];

            // 🔙 Backward compatibility: update old cache if default mailbox
            if ($impersonateEmail === $this->config['send_from_email']) {
                $this->accessToken = $tokenData['access_token'];
                $this->tokenExpires = $expiresAt;
            }

            return $tokenData['access_token'];

        } catch (Exception $e) {
            error_log("Gmail API authentication error for {$impersonateEmail}: " . $e->getMessage());
            throw new RuntimeException("Failed to authenticate with Gmail API");
        }
    }

    /**
     * 🔑 Create JWT Assertion
     *
     * Creates a signed JWT assertion for service account authentication.
     * Uses domain-wide delegation to impersonate the specified user.
     *
     * @param string $impersonateEmail Email address to impersonate
     * @return string Signed JWT token
     * @throws RuntimeException If JWT creation fails
     */
    private function createJWTAssertion(string $impersonateEmail): string
    {
        try {
            // 📅 Token timestamps
            $now = time();
            $expiration = $now + 3600; // 1 hour

            // 📝 JWT Header
            $header = [
                'alg' => 'RS256',
                'typ' => 'JWT'
            ];

            // 📝 JWT Payload (Claims) - DYNAMIC SUBJECT for delegate sending! 🎯
            $payload = [
                'iss' => $this->serviceAccountCredentials['client_email'], // Issuer (service account)
                'sub' => $impersonateEmail,                                // Subject (impersonated user) - DYNAMIC!
                'scope' => self::GMAIL_SEND_SCOPE,                         // Gmail send scope
                'aud' => self::TOKEN_ENDPOINT,                             // Audience
                'iat' => $now,                                              // Issued at
                'exp' => $expiration                                        // Expiration
            ];

            // 🔐 Create JWT
            $headerEncoded = $this->base64UrlEncode(json_encode($header));
            $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

            $signatureInput = $headerEncoded . '.' . $payloadEncoded;

            // ✍️ Sign with private key
            $privateKey = openssl_pkey_get_private($this->serviceAccountCredentials['private_key']);

            if ($privateKey === false) {
                throw new RuntimeException('Invalid service account private key');
            }

            $signature = '';
            $success = openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

            if (!$success) {
                throw new RuntimeException('Failed to sign JWT');
            }

            $signatureEncoded = $this->base64UrlEncode($signature);

            // 🎫 Complete JWT
            $jwt = $signatureInput . '.' . $signatureEncoded;

            return $jwt;

        } catch (Exception $e) {
            error_log('Gmail JWT creation error: ' . $e->getMessage());
            throw new RuntimeException('Failed to create JWT assertion');
        }
    }

    // ========================================================================
    // 📧 EMAIL SENDING
    // ========================================================================

    /**
     * 📨 Send Email via Gmail API
     *
     * @param array $emailData Email data (including optional 'sendAsEmail' for delegate sending)
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

            // 🎯 Extract delegate mailbox (if provided)
            $sendAsEmail = $emailData['sendAsEmail'] ?? $this->config['send_from_email'];

            // 🔑 Get access token for specific mailbox (domain-wide delegation)
            $accessToken = $this->getAccessToken($sendAsEmail);

            // 📝 Build RFC 2822 email message
            $rawMessage = $this->buildRFC2822Message($emailData);

            // 🔐 Encode message in Base64URL format
            $encodedMessage = $this->base64UrlEncode($rawMessage);

            // 🌐 Send via Gmail API using delegate mailbox
            $sendEndpoint = self::GMAIL_API_URL . '/users/' . urlencode($sendAsEmail) . '/messages/send';

            $headers = [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ];

            $response = $this->makeHttpRequest(
                $sendEndpoint,
                'POST',
                ['raw' => $encodedMessage],
                $headers
            );

            // ✅ Gmail API returns message ID on success
            if (empty($response['id'])) {
                throw new RuntimeException('No message ID in response');
            }

            $messageId = $response['id'];

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

            error_log("Gmail API send email error: " . $error);

            return [
                'success' => false,
                'messageId' => null,
                'error' => $error
            ];
        }
    }

    /**
     * 🔨 Build RFC 2822 Email Message
     *
     * Constructs email message in RFC 2822 format required by Gmail API.
     *
     * @param array $emailData Email data
     * @return string RFC 2822 formatted message
     */
    private function buildRFC2822Message(array $emailData): string
    {
        // 📧 Build headers
        $headers = [];

        // 👤 From
        $fromName = $emailData['fromName'] ?? $this->config['send_from_name'];
        $fromEmail = $emailData['from'] ?? $this->config['send_from_email'];
        $headers[] = 'From: ' . $this->formatEmailAddress($fromName, $fromEmail);

        // 👥 To
        $headers[] = 'To: ' . $this->formatEmailAddress('', $emailData['to']);

        // 📋 CC recipients
        if (!empty($emailData['cc']) && is_array($emailData['cc'])) {
            $ccList = array_map(fn($cc) => $this->formatEmailAddress('', $cc), $emailData['cc']);
            $headers[] = 'Cc: ' . implode(', ', $ccList);
        }

        // 🔄 Reply-To
        if (!empty($emailData['replyTo'])) {
            $headers[] = 'Reply-To: ' . $this->formatEmailAddress('', $emailData['replyTo']);
        }

        // 📝 Subject
        $headers[] = 'Subject: ' . $this->encodeHeaderValue($emailData['subject']);

        // 📅 Date
        $headers[] = 'Date: ' . date('r');

        // 🆔 Message-ID
        $headers[] = 'Message-ID: <' . uniqid('', true) . '@' . $this->getDomainFromEmail($fromEmail) . '>';

        // 📮 MIME Version
        $headers[] = 'MIME-Version: 1.0';

        // 🎨 Content type
        $boundary = '----=_Part_' . md5(uniqid('', true));

        if (!empty($emailData['bodyHTML']) && !empty($emailData['bodyText'])) {
            // 📧 Multipart alternative (HTML + Text)
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        } elseif (!empty($emailData['bodyHTML'])) {
            // 🎨 HTML only
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        } else {
            // 📝 Plain text only
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        }

        // 🔗 Join headers
        $message = implode("\r\n", $headers) . "\r\n\r\n";

        // 📝 Body
        if (!empty($emailData['bodyHTML']) && !empty($emailData['bodyText'])) {
            // 📧 Multipart message
            $message .= '--' . $boundary . "\r\n";
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $message .= quoted_printable_encode($emailData['bodyText']) . "\r\n\r\n";

            $message .= '--' . $boundary . "\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $message .= quoted_printable_encode($emailData['bodyHTML']) . "\r\n\r\n";

            $message .= '--' . $boundary . '--';
        } elseif (!empty($emailData['bodyHTML'])) {
            // 🎨 HTML only
            $message .= quoted_printable_encode($emailData['bodyHTML']);
        } else {
            // 📝 Plain text only
            $message .= quoted_printable_encode($emailData['bodyText']);
        }

        return $message;
    }

    // ========================================================================
    // 🔧 HELPER METHODS
    // ========================================================================

    /**
     * 📧 Format Email Address
     *
     * Formats email address with optional display name.
     *
     * @param string $name Display name (optional)
     * @param string $email Email address
     * @return string Formatted email address
     */
    private function formatEmailAddress(string $name, string $email): string
    {
        if (empty($name)) {
            return $email;
        }

        // 🔐 Encode name if it contains special characters
        $encodedName = $this->encodeHeaderValue($name);
        return $encodedName . ' <' . $email . '>';
    }

    /**
     * 🔐 Encode Header Value
     *
     * Encodes header value using RFC 2047 if needed.
     *
     * @param string $value Value to encode
     * @return string Encoded value
     */
    private function encodeHeaderValue(string $value): string
    {
        // 🔍 Check if encoding is needed (non-ASCII characters)
        if (!preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }

        // 🔐 RFC 2047 encoding
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * 🌐 Get Domain from Email
     *
     * Extracts domain from email address.
     *
     * @param string $email Email address
     * @return string Domain
     */
    private function getDomainFromEmail(string $email): string
    {
        $parts = explode('@', $email);
        return $parts[1] ?? 'localhost';
    }

    /**
     * 🔐 Base64 URL Encode
     *
     * URL-safe base64 encoding for Gmail API.
     *
     * @param string $data Data to encode
     * @return string Encoded data
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ========================================================================
    // 🔧 SETUP HELPERS
    // ========================================================================

    /**
     * 📋 Get Setup Instructions
     *
     * Returns setup instructions for Gmail API email.
     *
     * @return array Setup instructions
     */
    public static function getSetupInstructions(): array
    {
        return [
            'title' => 'Google Workspace Gmail API Email Setup',
            'requirements' => [
                'Google Workspace subscription (Business or Enterprise)',
                'Google Cloud Console access',
                'Workspace admin access',
                'Dedicated sending mailbox (e.g., noreply@yourdomain.com)'
            ],
            'steps' => [
                '1. Go to Google Cloud Console (console.cloud.google.com)',
                '2. Create a new project or select existing',
                '3. Enable Gmail API for the project',
                '4. Navigate to "APIs & Services" > "Credentials"',
                '5. Click "Create Credentials" > "Service account"',
                '6. Name: "SIGNula Email Service"',
                '7. Grant role: "Project" > "Editor" (or custom role with Gmail API access)',
                '8. Click "Done"',
                '9. Click on the created service account',
                '10. Go to "Keys" tab > "Add Key" > "Create new key"',
                '11. Choose "JSON" format and download the key file',
                '12. Copy the "client_email" from the JSON file',
                '13. Go to Google Workspace Admin Console (admin.google.com)',
                '14. Navigate to "Security" > "API Controls" > "Domain-wide Delegation"',
                '15. Click "Add new"',
                '16. Paste the "client_id" from JSON file',
                '17. Add OAuth scope: https://www.googleapis.com/auth/gmail.send',
                '18. Click "Authorize"',
                '19. Store the entire JSON file contents in SIGNula database settings (will be encrypted)'
            ],
            'database_settings' => [
                'email.gmail.service_account_json' => 'Entire contents of downloaded JSON key file (will be encrypted)',
                'email.gmail.from_email' => 'noreply@yourdomain.com',
                'email.gmail.from_name' => 'SIGNula'
            ],
            'permissions' => [
                'Required: gmail.send scope',
                'Domain-wide delegation: Required',
                'Scope: Allows sending email as any user in the organization'
            ],
            'benefits' => [
                'Excellent deliverability (sent from your Google Workspace tenant)',
                'Automatic SPF/DKIM/DMARC compliance',
                'Professional appearance (emails from your domain)',
                'Google spam filtering and security',
                'Delivery reports and tracking via Gmail'
            ],
            'notes' => [
                'Access tokens are cached per mailbox for 1 hour to reduce API calls',
                'Uses service account with domain-wide delegation',
                'Emails are sent from the configured mailbox by default',
                'SUPPORTS DELEGATE SENDING: Can send from ANY mailbox in your domain via sendAsEmail parameter',
                'Tokens are cached per delegate mailbox for optimal performance',
                'Supports HTML, plain text, CC, BCC (attachments can be added)',
                'Gmail API has generous rate limits (1 billion quota units/day)'
            ]
        ];
    }
}

// ✅ GmailAPIEmailProvider class loaded successfully
