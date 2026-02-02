<?php
/**
 * ============================================================================
 * 📧 SIGNula - SMTP Email Provider
 * ============================================================================
 *
 * Purpose: Send emails via standard SMTP protocol
 * PHP Version: 8.3+
 *
 * Features:
 * - Universal SMTP support (works with any SMTP server)
 * - TLS/SSL encryption support
 * - SMTP authentication
 * - HTML and plain text support
 * - Attachment support
 * - CC/BCC support
 * - Fallback option when API providers unavailable
 *
 * Supports:
 * - Gmail SMTP (smtp.gmail.com:587)
 * - Outlook/Office 365 SMTP (smtp.office365.com:587)
 * - Yahoo SMTP (smtp.mail.yahoo.com:587)
 * - Custom SMTP servers
 *
 * Documentation:
 * @see https://www.php.net/manual/en/book.mail.php
 * @see https://datatracker.ietf.org/doc/html/rfc5321
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
 * 📧 SMTP Email Provider
 *
 * Sends emails through standard SMTP protocol.
 * Works with any SMTP server supporting SMTP AUTH.
 */
class SMTPEmailProvider extends EmailProvider
{
    /**
     * @var resource|null SMTP socket connection
     */
    private $connection = null;

    /**
     * @var bool Debug mode flag
     */
    private bool $debug = false;

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        parent::__construct('smtp');
    }

    // ========================================================================
    // ⚙️ CONFIGURATION
    // ========================================================================

    /**
     * 📋 Load Configuration
     *
     * Loads SMTP configuration from database.
     */
    protected function loadConfiguration(): void
    {
        try {
            // 🔍 Get configuration from database
            $this->config['smtp_host'] = getSetting('email.smtp.host', '');
            $this->config['smtp_port'] = (int)getSetting('email.smtp.port', 587);
            $this->config['smtp_encryption'] = getSetting('email.smtp.encryption', 'tls'); // tls, ssl, or none
            $this->config['smtp_username'] = getSetting('email.smtp.username', '');

            $encryptedPassword = getSetting('email.smtp.password', '');
            if (!empty($encryptedPassword)) {
                $this->config['smtp_password'] = SecurityUtils::decrypt($encryptedPassword);
            }

            $this->config['send_from_email'] = getSetting('email.smtp.from_email', '');
            $this->config['send_from_name'] = getSetting('email.smtp.from_name', 'SIGNula');
            $this->config['smtp_timeout'] = (int)getSetting('email.smtp.timeout', 30);

            // 🐛 Debug mode
            $this->debug = (bool)getSetting('email.smtp.debug', false);

            // ✅ Validate configuration
            $this->isConfigured = !empty($this->config['smtp_host']) &&
                                 !empty($this->config['smtp_port']) &&
                                 !empty($this->config['smtp_username']) &&
                                 !empty($this->config['smtp_password']) &&
                                 !empty($this->config['send_from_email']);

        } catch (Exception $e) {
            error_log("SMTP email configuration error: " . $e->getMessage());
            $this->isConfigured = false;
        }
    }

    // ========================================================================
    // 🔐 SMTP CONNECTION
    // ========================================================================

    /**
     * 🔌 Connect to SMTP Server
     *
     * Establishes connection to SMTP server with TLS/SSL support.
     *
     * @throws RuntimeException If connection fails
     */
    private function connect(): void
    {
        try {
            // 🔗 Build connection string
            $host = $this->config['smtp_host'];
            $port = $this->config['smtp_port'];
            $encryption = $this->config['smtp_encryption'];

            // 🔐 SSL connection
            if ($encryption === 'ssl') {
                $host = 'ssl://' . $host;
            }

            // 🌐 Open socket connection
            $this->connection = @fsockopen(
                $host,
                $port,
                $errno,
                $errstr,
                $this->config['smtp_timeout']
            );

            if ($this->connection === false) {
                throw new RuntimeException("Failed to connect to SMTP server: $errstr ($errno)");
            }

            // ⏱️ Set timeout
            stream_set_timeout($this->connection, $this->config['smtp_timeout']);

            // 👋 Get server greeting
            $response = $this->getResponse();
            if (substr($response, 0, 3) !== '220') {
                throw new RuntimeException("SMTP connection failed: $response");
            }

            // 👤 Send EHLO
            $this->sendCommand('EHLO ' . $this->getClientDomain(), '250');

            // 🔐 Start TLS if required
            if ($encryption === 'tls') {
                $this->sendCommand('STARTTLS', '220');

                if (!stream_socket_enable_crypto($this->connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Failed to enable TLS encryption');
                }

                // 👤 Send EHLO again after TLS
                $this->sendCommand('EHLO ' . $this->getClientDomain(), '250');
            }

            // 🔑 Authenticate
            $this->authenticate();

        } catch (Exception $e) {
            $this->disconnect();
            throw $e;
        }
    }

    /**
     * 🔑 Authenticate with SMTP Server
     *
     * Performs SMTP AUTH LOGIN authentication.
     *
     * @throws RuntimeException If authentication fails
     */
    private function authenticate(): void
    {
        try {
            // 🔐 AUTH LOGIN method
            $this->sendCommand('AUTH LOGIN', '334');

            // 👤 Send username (Base64 encoded)
            $this->sendCommand(base64_encode($this->config['smtp_username']), '334');

            // 🔑 Send password (Base64 encoded)
            $this->sendCommand(base64_encode($this->config['smtp_password']), '235');

        } catch (Exception $e) {
            throw new RuntimeException('SMTP authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * 🔌 Disconnect from SMTP Server
     */
    private function disconnect(): void
    {
        if ($this->connection) {
            try {
                $this->sendCommand('QUIT', '221');
            } catch (Exception $e) {
                // Ignore errors during disconnect
            }

            @fclose($this->connection);
            $this->connection = null;
        }
    }

    // ========================================================================
    // 📧 EMAIL SENDING
    // ========================================================================

    /**
     * 📨 Send Email via SMTP
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

            // 🔌 Connect to SMTP server
            $this->connect();

            // 📧 Send MAIL FROM
            $fromEmail = $emailData['from'] ?? $this->config['send_from_email'];
            $this->sendCommand('MAIL FROM:<' . $fromEmail . '>', '250');

            // 👥 Send RCPT TO (recipient)
            $this->sendCommand('RCPT TO:<' . $emailData['to'] . '>', '250');

            // 📋 Send RCPT TO for CC recipients
            if (!empty($emailData['cc']) && is_array($emailData['cc'])) {
                foreach ($emailData['cc'] as $cc) {
                    $this->sendCommand('RCPT TO:<' . $cc . '>', '250');
                }
            }

            // 📋 Send RCPT TO for BCC recipients
            if (!empty($emailData['bcc']) && is_array($emailData['bcc'])) {
                foreach ($emailData['bcc'] as $bcc) {
                    $this->sendCommand('RCPT TO:<' . $bcc . '>', '250');
                }
            }

            // 📝 Send DATA command
            $this->sendCommand('DATA', '354');

            // 🔨 Build email message
            $message = $this->buildEmailMessage($emailData);

            // 📤 Send message data
            $this->sendData($message);

            // ✅ End DATA with CRLF.CRLF
            $this->sendCommand('.', '250');

            // 🔌 Disconnect
            $this->disconnect();

            // 🆔 Generate message ID for tracking
            $messageId = uniqid('smtp_', true);

            // 📝 Log success
            $this->logEmailActivity($emailData, true, $messageId);

            return [
                'success' => true,
                'messageId' => $messageId,
                'error' => null
            ];

        } catch (Exception $e) {
            // 🔌 Ensure disconnection
            $this->disconnect();

            // ❌ Log failure
            $error = $e->getMessage();
            $this->logEmailActivity($emailData, false, null, $error);

            error_log("SMTP send email error: " . $error);

            return [
                'success' => false,
                'messageId' => null,
                'error' => $error
            ];
        }
    }

    /**
     * 🔨 Build Email Message
     *
     * Constructs complete email message with headers and body.
     *
     * @param array $emailData Email data
     * @return string Complete email message
     */
    private function buildEmailMessage(array $emailData): string
    {
        // 📧 Build headers
        $headers = [];

        // 📅 Date
        $headers[] = 'Date: ' . date('r');

        // 👤 From
        $fromName = $emailData['fromName'] ?? $this->config['send_from_name'];
        $fromEmail = $emailData['from'] ?? $this->config['send_from_email'];
        $headers[] = 'From: ' . $this->formatEmailAddress($fromName, $fromEmail);

        // 👥 To
        $headers[] = 'To: ' . $this->formatEmailAddress('', $emailData['to']);

        // 📋 CC
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

        // 🆔 Message-ID
        $messageId = uniqid('', true) . '@' . $this->getClientDomain();
        $headers[] = 'Message-ID: <' . $messageId . '>';

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
    // 🔧 SMTP PROTOCOL HELPERS
    // ========================================================================

    /**
     * 📤 Send SMTP Command
     *
     * Sends command to SMTP server and validates response.
     *
     * @param string $command SMTP command
     * @param string $expectedCode Expected response code
     * @throws RuntimeException If response doesn't match expected code
     */
    private function sendCommand(string $command, string $expectedCode): void
    {
        // 🐛 Debug output
        if ($this->debug) {
            error_log("SMTP >> " . $command);
        }

        // 📤 Send command
        fwrite($this->connection, $command . "\r\n");

        // 📥 Get response
        $response = $this->getResponse();

        // 🐛 Debug output
        if ($this->debug) {
            error_log("SMTP << " . $response);
        }

        // ✅ Validate response
        if (substr($response, 0, 3) !== $expectedCode) {
            throw new RuntimeException("SMTP command failed. Expected $expectedCode, got: $response");
        }
    }

    /**
     * 📤 Send Data
     *
     * Sends email message data.
     *
     * @param string $data Message data
     */
    private function sendData(string $data): void
    {
        // 🔐 Escape periods at start of lines (SMTP transparency)
        $data = str_replace("\r\n.", "\r\n..", $data);

        // 📤 Send data
        fwrite($this->connection, $data . "\r\n");
    }

    /**
     * 📥 Get Response from SMTP Server
     *
     * Reads response from SMTP server.
     *
     * @return string Server response
     * @throws RuntimeException If no response received
     */
    private function getResponse(): string
    {
        $response = '';

        while ($line = fgets($this->connection, 515)) {
            $response .= $line;

            // ✅ Check if this is the last line (code followed by space, not hyphen)
            if (preg_match('/^[0-9]{3} /', $line)) {
                break;
            }
        }

        if (empty($response)) {
            throw new RuntimeException('No response from SMTP server');
        }

        return trim($response);
    }

    // ========================================================================
    // 🔧 UTILITY METHODS
    // ========================================================================

    /**
     * 🌐 Get Client Domain
     *
     * Gets the client domain for EHLO/HELO command.
     *
     * @return string Client domain
     */
    private function getClientDomain(): string
    {
        // 🔍 Try to get domain from from email
        if (!empty($this->config['send_from_email'])) {
            $parts = explode('@', $this->config['send_from_email']);
            if (isset($parts[1])) {
                return $parts[1];
            }
        }

        // 🌐 Fallback to server hostname
        return gethostname() ?: 'localhost';
    }

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

    // ========================================================================
    // 🔧 SETUP HELPERS
    // ========================================================================

    /**
     * 📋 Get Setup Instructions
     *
     * Returns setup instructions for SMTP email.
     *
     * @return array Setup instructions
     */
    public static function getSetupInstructions(): array
    {
        return [
            'title' => 'SMTP Email Setup',
            'description' => 'Universal SMTP email provider - works with any SMTP server',
            'common_providers' => [
                'Gmail' => [
                    'host' => 'smtp.gmail.com',
                    'port' => 587,
                    'encryption' => 'tls',
                    'notes' => 'Requires app password if 2FA enabled'
                ],
                'Outlook/Office 365' => [
                    'host' => 'smtp.office365.com',
                    'port' => 587,
                    'encryption' => 'tls',
                    'notes' => 'Use your full email address as username'
                ],
                'Yahoo Mail' => [
                    'host' => 'smtp.mail.yahoo.com',
                    'port' => 587,
                    'encryption' => 'tls',
                    'notes' => 'Requires app password'
                ],
                'SendGrid' => [
                    'host' => 'smtp.sendgrid.net',
                    'port' => 587,
                    'encryption' => 'tls',
                    'notes' => 'Username is always "apikey"'
                ],
                'Mailgun' => [
                    'host' => 'smtp.mailgun.org',
                    'port' => 587,
                    'encryption' => 'tls',
                    'notes' => 'Use Mailgun SMTP credentials'
                ]
            ],
            'database_settings' => [
                'email.smtp.host' => 'SMTP server hostname (e.g., smtp.gmail.com)',
                'email.smtp.port' => 'SMTP port (587 for TLS, 465 for SSL, 25 for unencrypted)',
                'email.smtp.encryption' => 'Encryption type: "tls", "ssl", or "none"',
                'email.smtp.username' => 'SMTP username (usually your email address)',
                'email.smtp.password' => 'SMTP password or app password (will be encrypted)',
                'email.smtp.from_email' => 'From email address',
                'email.smtp.from_name' => 'From display name',
                'email.smtp.timeout' => 'Connection timeout in seconds (default: 30)',
                'email.smtp.debug' => 'Enable debug mode (true/false)'
            ],
            'encryption_options' => [
                'tls' => 'STARTTLS (port 587) - Most common and recommended',
                'ssl' => 'SSL/TLS (port 465) - Legacy but still supported',
                'none' => 'No encryption (port 25) - Not recommended for production'
            ],
            'benefits' => [
                'Universal compatibility (works with any SMTP server)',
                'No API dependencies',
                'Simple configuration',
                'Reliable fallback option',
                'Supports all standard email features (HTML, attachments, CC/BCC)'
            ],
            'notes' => [
                'Recommended as fallback when API providers unavailable',
                'Many email providers require app-specific passwords for SMTP',
                'Some shared hosting providers block outbound SMTP on port 25',
                'Always use TLS encryption for security',
                'Rate limits depend on your SMTP provider'
            ]
        ];
    }
}

// ✅ SMTPEmailProvider class loaded successfully
