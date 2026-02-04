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
     * @var string Authentication mode: 'application' or 'delegated'
     * Application: Uses client credentials (service account)
     * Delegated: Uses per-user OAuth tokens
     */
    private string $authMode = 'auto'; // 'auto', 'application', 'delegated'

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('microsoft_graph');

        // 🔍 Determine default auth mode from settings
        $useDelegated = getSetting('email.microsoft.use_delegated_permissions', 'false');
        $this->authMode = ($useDelegated === 'true') ? 'delegated' : 'auto';
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

    /**
     * 🔍 Determine Authentication Mode
     *
     * Intelligently chooses between application and delegated auth modes:
     * - AUTO: Try user OAuth first, fallback to application
     * - APPLICATION: Always use application credentials (for shared mailboxes)
     * - DELEGATED: Only use user OAuth (fail if not available)
     *
     * @param int|null $userID User ID (for user OAuth lookup)
     * @param string $sendAsEmail Mailbox to send from
     * @return array ['accessToken' => string, 'useUserAuth' => bool, 'mode' => string]
     * @throws RuntimeException If authentication fails
     */
    private function determineAuthMode(?int $userID, string $sendAsEmail): array
    {
        // 🔍 Check auth mode preference
        $preferredMode = $this->authMode;

        // 🎯 AUTO MODE: Try user OAuth first, fallback to application
        if ($preferredMode === 'auto' && $userID) {
            // 🔍 Try to get user OAuth token
            require_once dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'OAuthTokenManager.php';

            $userToken = OAuthTokenManager::getToken($userID, 'microsoft_graph', $sendAsEmail);

            if ($userToken && !empty($userToken['accessToken'])) {
                // ✅ User OAuth token available - use delegated permissions
                return [
                    'accessToken' => $userToken['accessToken'],
                    'useUserAuth' => true,
                    'mode' => 'delegated'
                ];
            }

            // ⚠️ User token not available - fallback to application
            // This is normal for system emails or unlicensed/shared mailboxes
        }

        // 👤 DELEGATED MODE: Only use user OAuth
        if ($preferredMode === 'delegated') {
            if (!$userID) {
                throw new RuntimeException('Delegated auth mode requires userID');
            }

            require_once dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'OAuthTokenManager.php';

            $userToken = OAuthTokenManager::getToken($userID, 'microsoft_graph', $sendAsEmail);

            if (!$userToken || empty($userToken['accessToken'])) {
                throw new RuntimeException("User OAuth token not found for userID {$userID} and mailbox {$sendAsEmail}");
            }

            return [
                'accessToken' => $userToken['accessToken'],
                'useUserAuth' => true,
                'mode' => 'delegated'
            ];
        }

        // 🏢 APPLICATION MODE (default): Use client credentials
        // Perfect for:
        // - System/transactional emails
        // - Shared/unlicensed mailboxes (e.g., noreply@, support@, info@)
        // - When user OAuth not available or not needed
        return [
            'accessToken' => $this->getAccessToken(),
            'useUserAuth' => false,
            'mode' => 'application'
        ];
    }

    // ========================================================================
    // 📧 EMAIL SENDING
    // ========================================================================

    /**
     * 📨 Send Email via Microsoft Graph API
     *
     * Supports DUAL-MODE authentication:
     * - Application mode: Uses client credentials (shared/unlicensed mailboxes)
     * - Delegated mode: Uses per-user OAuth tokens (user mailboxes)
     * - Auto mode: Tries delegated first, falls back to application
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

            // 🎯 Extract delegate mailbox and user ID
            $sendAsEmail = $emailData['sendAsEmail'] ?? $this->config['send_from_email'];
            $userID = $emailData['userID'] ?? null;

            // 🔑 Determine authentication mode and get access token
            $authResult = $this->determineAuthMode($userID, $sendAsEmail);
            $accessToken = $authResult['accessToken'];
            $useUserAuth = $authResult['useUserAuth'];
            $authMode = $authResult['mode'];

            // 📝 Build email message
            $message = $this->buildGraphMessage($emailData);

            // 🌐 Choose appropriate endpoint based on auth mode
            if ($useUserAuth) {
                // 👤 Delegated permissions: Send as authenticated user
                $sendEndpoint = self::GRAPH_API_URL . '/me/sendMail';
                $logContext = "user OAuth (userID: {$userID})";
            } else {
                // 🏢 Application permissions: Send from delegate mailbox
                // Note: Requires Mail.Send.Shared + Exchange "Send As" permission
                $sendEndpoint = self::GRAPH_API_URL . '/users/' . urlencode($sendAsEmail) . '/sendMail';
                $logContext = "application credentials (mailbox: {$sendAsEmail})";
            }

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

            // 📝 Log success with authentication context
            $messageId = uniqid('msg_', true); // Generate unique ID for tracking
            $this->logEmailActivity($emailData, true, $messageId, null, [
                'authMode' => $authMode,
                'useUserAuth' => $useUserAuth,
                'sendAsEmail' => $sendAsEmail,
                'context' => $logContext
            ]);

            return [
                'success' => true,
                'messageId' => $messageId,
                'error' => null,
                'authMode' => $authMode  // For debugging/monitoring
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
                '13. Add permissions: "Mail.Send" and "Mail.Send.Shared" (for delegate mailbox sending)',
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
                'Optional: Mail.Send.Shared (for delegate mailbox sending)',
                'Admin consent: Required for both permissions',
                'Exchange: "Send As" permission required for each delegate mailbox',
                'Scope: Allows sending email from service mailbox and delegate mailboxes'
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
                'Emails are sent from the configured mailbox by default',
                'SUPPORTS DELEGATE SENDING: Can send from different mailboxes via sendAsEmail parameter',
                'Delegate sending requires Mail.Send.Shared permission + Exchange "Send As" permission',
                'See _docs/MICROSOFT_DELEGATE_MAILBOX_SETUP.md for configuration instructions',
                'Supports HTML, plain text, CC, BCC, and attachments',
                'Microsoft Graph API has rate limits - monitor usage'
            ]
        ];
    }
}

// ✅ MicrosoftGraphEmailProvider class loaded successfully
