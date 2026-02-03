<?php
/**
 * ============================================================================
 * 📧 SIGNula - Email Provider Base Class
 * ============================================================================
 *
 * Purpose: Abstract base class for email delivery providers
 * PHP Version: 8.3+
 *
 * Supports:
 * - Microsoft Graph API (Microsoft 365)
 * - Gmail API (Google Workspace)
 * - SMTP (Universal fallback)
 *
 * Benefits:
 * - Better deliverability (emails come from your corporate domain)
 * - SPF/DKIM/DMARC compliance
 * - Professional appearance
 * - Reduced spam flags
 * - Tracking and analytics
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
 * 📧 Email Provider Abstract Class
 *
 * Base class for email delivery providers.
 * Each provider implements the send() method for their specific API/protocol.
 */
abstract class EmailProvider
{
    /**
     * @var string Provider name
     */
    protected string $providerName;

    /**
     * @var array Provider configuration
     */
    protected array $config = [];

    /**
     * @var bool Whether provider is configured and ready
     */
    protected bool $isConfigured = false;

    // ========================================================================
    // 🏗️ CONSTRUCTOR
    // ========================================================================

    /**
     * 🏗️ Constructor
     *
     * @param string $providerName Provider identifier
     */
    public function __construct(string $providerName)
    {
        $this->providerName = $providerName;
        $this->loadConfiguration();
    }

    // ========================================================================
    // ⚙️ CONFIGURATION
    // ========================================================================

    /**
     * 📋 Load Provider Configuration
     *
     * Loads configuration from database settings.
     * Should be implemented by each provider for their specific config needs.
     */
    abstract protected function loadConfiguration(): void;

    /**
     * ✅ Check if Provider is Configured
     *
     * @return bool True if provider is ready to send emails
     */
    public function isConfigured(): bool
    {
        return $this->isConfigured;
    }

    /**
     * 📛 Get Provider Name
     *
     * @return string Provider name
     */
    public function getProviderName(): string
    {
        return $this->providerName;
    }

    // ========================================================================
    // 📧 EMAIL SENDING
    // ========================================================================

    /**
     * 📨 Send Email
     *
     * Must be implemented by each provider.
     *
     * @param array $emailData Email data with keys:
     *                        - to: string (required) - Recipient email
     *                        - subject: string (required) - Email subject
     *                        - bodyHTML: string (optional) - HTML body
     *                        - bodyText: string (required) - Plain text body
     *                        - from: string (optional) - From email
     *                        - fromName: string (optional) - From name
     *                        - replyTo: string (optional) - Reply-to email
     *                        - cc: array (optional) - CC recipients
     *                        - bcc: array (optional) - BCC recipients
     *                        - attachments: array (optional) - Attachments
     * @return array Result ['success' => bool, 'messageId' => string|null, 'error' => string|null]
     */
    abstract public function send(array $emailData): array;

    // ========================================================================
    // 🔧 HELPER METHODS
    // ========================================================================

    /**
     * ✅ Validate Email Data
     *
     * Validates required email fields.
     *
     * @param array $emailData Email data to validate
     * @return array Validation result ['valid' => bool, 'errors' => array]
     */
    protected function validateEmailData(array $emailData): array
    {
        $errors = [];

        // ✅ Required fields
        if (empty($emailData['to'])) {
            $errors[] = 'Recipient email (to) is required';
        } elseif (!filter_var($emailData['to'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid recipient email address';
        }

        if (empty($emailData['subject'])) {
            $errors[] = 'Email subject is required';
        }

        if (empty($emailData['bodyText']) && empty($emailData['bodyHTML'])) {
            $errors[] = 'Email body (text or HTML) is required';
        }

        // ✅ Validate email addresses if provided
        if (!empty($emailData['from']) && !filter_var($emailData['from'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid from email address';
        }

        if (!empty($emailData['replyTo']) && !filter_var($emailData['replyTo'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid reply-to email address';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * 📝 Log Email Activity
     *
     * Logs email sending activity.
     *
     * @param array $emailData Email data
     * @param bool $success Whether send was successful
     * @param string|null $messageId Message ID from provider
     * @param string|null $error Error message if failed
     */
    protected function logEmailActivity(
        array $emailData,
        bool $success,
        ?string $messageId = null,
        ?string $error = null
    ): void {
        try {
            $query = "
                INSERT INTO tblActivityLog
                (userID, activity, module, severity, description, metadata, createdAt)
                VALUES (NULL, ?, 'email', ?, ?, ?, NOW())
            ";

            $activity = $success ? 'email_sent' : 'email_failed';
            $severity = $success ? 'info' : 'error';
            $description = $success
                ? "Email sent via {$this->providerName} to {$emailData['to']}"
                : "Email failed via {$this->providerName}: {$error}";

            $metadata = json_encode([
                'provider' => $this->providerName,
                'to' => $emailData['to'],
                'subject' => $emailData['subject'],
                'message_id' => $messageId,
                'error' => $error
            ]);

            Database::query(
                $query,
                [$activity, $severity, $description, $metadata],
                'ssss'
            );

        } catch (Exception $e) {
            error_log("Failed to log email activity: " . $e->getMessage());
        }
    }

    /**
     * 🌐 Make HTTP Request
     *
     * Makes HTTP request to email provider API.
     *
     * @param string $url URL to request
     * @param string $method HTTP method (GET, POST, etc.)
     * @param array $data Request data
     * @param array $headers HTTP headers
     * @return array Response data
     * @throws RuntimeException If request fails
     */
    protected function makeHttpRequest(
        string $url,
        string $method = 'GET',
        array $data = [],
        array $headers = []
    ): array {
        try {
            // 🔧 Initialize cURL
            $ch = curl_init();

            // ⚙️ Configure cURL
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            // 📝 Set method and data
            if ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

                if (!empty($data)) {
                    $jsonData = json_encode($data);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);

                    // Add Content-Type header if not present
                    $hasContentType = false;
                    foreach ($headers as $header) {
                        if (stripos($header, 'Content-Type:') === 0) {
                            $hasContentType = true;
                            break;
                        }
                    }

                    if (!$hasContentType) {
                        $headers[] = 'Content-Type: application/json';
                    }
                }
            }

            // 📋 Set headers
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            // 🌐 Execute request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            curl_close($ch);

            // ❌ Check for errors
            if ($response === false) {
                throw new RuntimeException("HTTP request failed: $error");
            }

            if ($httpCode >= 400) {
                throw new RuntimeException("HTTP error $httpCode: $response");
            }

            // 📦 Parse JSON response
            $result = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // Not JSON - return raw response
                return ['raw' => $response];
            }

            return $result;

        } catch (Exception $e) {
            error_log("HTTP request error: " . $e->getMessage());
            throw new RuntimeException("HTTP request failed: " . $e->getMessage());
        }
    }
}

// ✅ EmailProvider base class loaded successfully
