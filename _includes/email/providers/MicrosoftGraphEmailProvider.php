<?php
/**
 * ============================================================================
 * 📧 SIGNula - Microsoft Graph API Email Provider
 * ============================================================================
 *
 * Purpose: Send emails via Microsoft 365 using Microsoft Graph API
 * PHP Version: 8.3+
 *
 * Features:
 * - OAuth 2.0 client credentials authentication
 * - Microsoft 365 / Office 365 integration
 * - Excellent deliverability (SPF/DKIM/DMARC compliant)
 * - Sends from your corporate domain
 * - HTML and plain text support
 * - Attachment support
 *
 * Setup Requirements:
 * 1. Azure AD App Registration
 * 2. API Permissions: Mail.Send (Application permission)
 * 3. Admin consent granted
 * 4. Client ID and Client Secret
 * 5. Tenant ID
 *
 * Documentation:
 * @see https://docs.microsoft.com/en-us/graph/api/user-sendmail
 * @see https://docs.microsoft.com/en-us/graph/auth-v2-service
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
 * 📧 Microsoft Graph Email Provider
 *
 * Sends emails through Microsoft 365 using Microsoft Graph API.
 * Uses OAuth 2.0 client credentials flow (application permissions).
 */
class MicrosoftGraphEmailProvider extends EmailProvider
{
    /**
     * @var string Microsoft Graph API endpoint
     */
    private const GRAPH_API_URL = 'https://graph.microsoft.com/v1.0';

    /**
     * @var string OAuth token endpoint
     */
    private const TOKEN_ENDPOINT = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token';

    /**
     * @var string|null Cached access token
     */
    private ?string $accessToken = null;

    /**
     * @var int Token expiration timestamp
     */
    private int $tokenExpires = 0;

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('microsoft_graph');
    }

    // ========================================================================
    // ⚙️ CONFIGURATION
    // ========================================================================

    /**
     * 📋 Load Configuration
     *
     * Loads Microsoft Graph API configuration from database.
     */
    protected function loadConfiguration(): void
    {
        try {
            // 🔍 Get configuration from database
            $this->config['tenant_id'] = getSetting('email.microsoft.tenant_id', '');
            $this->config['client_id'] = getSetting('email.microsoft.client_id', '');

            $encryptedSecret = getSetting('email.microsoft.client_secret', '');
            if (!empty($encryptedSecret)) {
                $this->config['client_secret'] = SecurityUtils::decrypt($encryptedSecret);
            }

            $this->config['send_from_email'] = getSetting('email.microsoft.from_email', '');
            $this->config['send_from_name'] = getSetting('email.microsoft.from_name', 'SIGNula');

            // ✅ Validate configuration
            $this->isConfigured = !empty($this->config['tenant_id']) &&
                                 !empty($this->config['client_id']) &&
                                 !empty($this->config['client_secret']) &&
                                 !empty($this->config['send_from_email']);

        } catch (Exception $e) {
            error_log("Microsoft Graph email configuration error: " . $e->getMessage());
            $this->isConfigured = false;
        }
    }

    // ========================================================================
    // 🔐 AUTHENTICATION
    // ========================================================================

    /**
     * 🔑 Get Access Token
     *
     * Retrieves access token using OAuth 2.0 client credentials flow.
     * Tokens are cached until expiration.
     *
     * @return string Access token
     * @throws RuntimeException If authentication fails
     */
    private function getAccessToken(): string
    {
        // ♻️ Return cached token if still valid
        if ($this->accessToken && time() < $this->tokenExpires) {
            return $this->accessToken;
        }

        try {
            // 🔗 Build token endpoint URL
            $tokenUrl = str_replace('{tenant}', $this->config['tenant_id'], self::TOKEN_ENDPOINT);

            // 📝 Prepare token request
            $postData = [
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials'
            ];

            // 🌐 Make token request
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $tokenUrl);
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

            // 💾 Cache token
            $this->accessToken = $tokenData['access_token'];
            $this->tokenExpires = time() + ($tokenData['expires_in'] ?? 3600) - 60; // 60s buffer

            return $this->accessToken;

        } catch (Exception $e) {
            error_log("Microsoft Graph authentication error: " . $e->getMessage());
            throw new RuntimeException("Failed to authenticate with Microsoft Graph API");
        }
    }

    // ========================================================================
    // 📧 EMAIL SENDING
    // ========================================================================

    /**
     * 📨 Send Email via Microsoft Graph API
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

            // 🔑 Get access token
            $accessToken = $this->getAccessToken();

            // 📝 Build email message
            $message = $this->buildGraphMessage($emailData);

            // 🌐 Send via Microsoft Graph API
            $sendEndpoint = self::GRAPH_API_URL . '/users/' . urlencode($this->config['send_from_email']) . '/sendMail';

            $headers = [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ];

            $response = $this->makeHttpRequest(
                $sendEndpoint,
                'POST',
                ['message' => $message],
                $headers
            );

            // ✅ Microsoft Graph sendMail returns 202 Accepted with no body on success
            // The email is queued for delivery

            // 📝 Log success
            $messageId = uniqid('msg_', true); // Generate unique ID for tracking
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

            error_log("Microsoft Graph send email error: " . $error);

            return [
                'success' => false,
                'messageId' => null,
                'error' => $error
            ];
        }
    }

    /**
     * 🔨 Build Microsoft Graph Message Object
     *
     * Constructs email message in Microsoft Graph API format.
     *
     * @param array $emailData Email data
     * @return array Graph API message object
     */
    private function buildGraphMessage(array $emailData): array
    {
        // 📧 Build message structure
        $message = [
            'subject' => $emailData['subject'],
            'toRecipients' => [
                [
                    'emailAddress' => [
                        'address' => $emailData['to']
                    ]
                ]
            ]
        ];

        // 📝 Set body (prefer HTML if available)
        if (!empty($emailData['bodyHTML'])) {
            $message['body'] = [
                'contentType' => 'HTML',
                'content' => $emailData['bodyHTML']
            ];
        } elseif (!empty($emailData['bodyText'])) {
            $message['body'] = [
                'contentType' => 'Text',
                'content' => $emailData['bodyText']
            ];
        }

        // 👤 Set from (optional - defaults to authenticated user)
        if (!empty($emailData['fromName'])) {
            $message['from'] = [
                'emailAddress' => [
                    'name' => $emailData['fromName'],
                    'address' => $emailData['from'] ?? $this->config['send_from_email']
                ]
            ];
        }

        // 🔄 Set reply-to
        if (!empty($emailData['replyTo'])) {
            $message['replyTo'] = [
                [
                    'emailAddress' => [
                        'address' => $emailData['replyTo']
                    ]
                ]
            ];
        }

        // 📋 Add CC recipients
        if (!empty($emailData['cc']) && is_array($emailData['cc'])) {
            $message['ccRecipients'] = [];
            foreach ($emailData['cc'] as $cc) {
                $message['ccRecipients'][] = [
                    'emailAddress' => ['address' => $cc]
                ];
            }
        }

        // 📋 Add BCC recipients
        if (!empty($emailData['bcc']) && is_array($emailData['bcc'])) {
            $message['bccRecipients'] = [];
            foreach ($emailData['bcc'] as $bcc) {
                $message['bccRecipients'][] = [
                    'emailAddress' => ['address' => $bcc]
                ];
            }
        }

        // 📎 Add attachments (if any)
        if (!empty($emailData['attachments']) && is_array($emailData['attachments'])) {
            $message['attachments'] = [];
            foreach ($emailData['attachments'] as $attachment) {
                if (!empty($attachment['content']) && !empty($attachment['name'])) {
                    $message['attachments'][] = [
                        '@odata.type' => '#microsoft.graph.fileAttachment',
                        'name' => $attachment['name'],
                        'contentType' => $attachment['type'] ?? 'application/octet-stream',
                        'contentBytes' => base64_encode($attachment['content'])
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
     * Returns setup instructions for Microsoft Graph email.
     *
     * @return array Setup instructions
     */
    public static function getSetupInstructions(): array
    {
        return [
            'title' => 'Microsoft 365 Graph API Email Setup',
            'requirements' => [
                'Microsoft 365 subscription (Business or Enterprise)',
                'Azure AD tenant',
                'Global Administrator access',
                'Dedicated sending mailbox (e.g., noreply@yourdomain.com)'
            ],
            'steps' => [
                '1. Sign in to Azure Portal (portal.azure.com)',
                '2. Navigate to Azure Active Directory > App registrations',
                '3. Click "New registration"',
                '4. Name: "SIGNula Email Service"',
                '5. Supported account types: "Accounts in this organizational directory only"',
                '6. Click "Register"',
                '7. Note the Application (client) ID and Directory (tenant) ID',
                '8. Go to "Certificates & secrets" > "New client secret"',
                '9. Description: "Email sending secret", Expires: 24 months',
                '10. Copy the secret value immediately (you can only see it once!)',
                '11. Go to "API permissions" > "Add a permission"',
                '12. Select "Microsoft Graph" > "Application permissions"',
                '13. Add permission: "Mail.Send"',
                '14. Click "Grant admin consent" for your tenant',
                '15. Create a dedicated mailbox in Microsoft 365 for sending (e.g., noreply@yourdomain.com)',
                '16. Store credentials in SIGNula database settings'
            ],
            'database_settings' => [
                'email.microsoft.tenant_id' => 'Your Directory (tenant) ID from Azure AD',
                'email.microsoft.client_id' => 'Your Application (client) ID',
                'email.microsoft.client_secret' => 'Your client secret (will be encrypted)',
                'email.microsoft.from_email' => 'noreply@yourdomain.com',
                'email.microsoft.from_name' => 'SIGNula'
            ],
            'permissions' => [
                'Required: Mail.Send (Application permission)',
                'Admin consent: Required',
                'Scope: Allows sending email as any user in the organization'
            ],
            'benefits' => [
                'Excellent deliverability (sent from your Microsoft 365 tenant)',
                'Automatic SPF/DKIM/DMARC compliance',
                'Professional appearance (emails from your domain)',
                'Microsoft spam filtering and security',
                'Delivery reports and tracking'
            ],
            'notes' => [
                'Access token is cached for 1 hour to reduce API calls',
                'Uses client credentials flow (application permissions, not delegated)',
                'Emails are sent from the configured mailbox',
                'Supports HTML, plain text, CC, BCC, and attachments',
                'Microsoft Graph API has rate limits - monitor usage'
            ]
        ];
    }
}

// ✅ MicrosoftGraphEmailProvider class loaded successfully
