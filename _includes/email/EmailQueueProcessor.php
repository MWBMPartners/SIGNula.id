<?php
/**
 * ============================================================================
 * 📧 SIGNula - Email Queue Processor
 * ============================================================================
 *
 * Purpose: Process queued emails and send via configured providers
 * PHP Version: 8.3+
 *
 * Features:
 * - Processes emails from tblEmailQueue
 * - Provider priority system (API > SMTP fallback)
 * - Automatic retry with exponential backoff
 * - Batch processing support
 * - Status tracking and logging
 * - Dead letter queue for permanent failures
 *
 * Usage:
 * - Run via cron job: php /path/to/EmailQueueProcessor.php
 * - Run via web: include and call EmailQueueProcessor::process()
 *
 * @package    SIGNula
 * @subpackage Email
 * @version    1.0.0
 * ============================================================================
 */

// 🚫 Prevent direct access (unless called from CLI)
if (!defined('SIGNULA_INIT') && php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Direct access not permitted');
}

// 📚 Require email providers
require_once __DIR__ . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'EmailProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'MicrosoftGraphEmailProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'GmailAPIEmailProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'SMTPEmailProvider.php';

/**
 * 📧 Email Queue Processor
 *
 * Processes queued emails and sends them via configured email providers.
 */
class EmailQueueProcessor
{
    /**
     * @var int Maximum retry attempts
     */
    private const MAX_RETRY_ATTEMPTS = 3;

    /**
     * @var int Batch size for processing
     */
    private const BATCH_SIZE = 10;

    /**
     * @var array Available email providers (in priority order)
     */
    private array $providers = [];

    /**
     * @var bool Verbose output flag
     */
    private bool $verbose = false;

    /**
     * 🏗️ Constructor
     *
     * @param bool $verbose Enable verbose output
     */
    public function __construct(bool $verbose = false)
    {
        $this->verbose = $verbose;
        $this->loadProviders();
    }

    // ========================================================================
    // ⚙️ INITIALIZATION
    // ========================================================================

    /**
     * 📋 Load Email Providers
     *
     * Loads and initializes all configured email providers in priority order.
     */
    private function loadProviders(): void
    {
        // 🔍 Get preferred provider from settings
        $preferredProvider = getSetting('email.provider', 'microsoft_graph');

        // 📋 Load providers in priority order
        $providerClasses = [
            'microsoft_graph' => MicrosoftGraphEmailProvider::class,
            'gmail_api' => GmailAPIEmailProvider::class,
            'smtp' => SMTPEmailProvider::class
        ];

        // 🔝 Add preferred provider first
        if (isset($providerClasses[$preferredProvider])) {
            $provider = new $providerClasses[$preferredProvider]();
            if ($provider->isConfigured()) {
                $this->providers[] = $provider;
                $this->log("✅ Loaded preferred provider: {$provider->getProviderName()}");
            }
        }

        // 📋 Add remaining providers as fallbacks
        foreach ($providerClasses as $key => $class) {
            if ($key === $preferredProvider) {
                continue; // Skip - already added as preferred
            }

            $provider = new $class();
            if ($provider->isConfigured()) {
                $this->providers[] = $provider;
                $this->log("✅ Loaded fallback provider: {$provider->getProviderName()}");
            }
        }

        // ⚠️ No providers configured
        if (empty($this->providers)) {
            $this->log("⚠️ WARNING: No email providers configured!");
        }
    }

    // ========================================================================
    // 📧 QUEUE PROCESSING
    // ========================================================================

    /**
     * 🔄 Process Email Queue
     *
     * Processes pending emails from the queue.
     *
     * @param int|null $limit Maximum number of emails to process (null = use batch size)
     * @return array Processing statistics
     */
    public function process(?int $limit = null): array
    {
        $startTime = microtime(true);
        $limit = $limit ?? self::BATCH_SIZE;

        $this->log("\n" . str_repeat('=', 60));
        $this->log("📧 Email Queue Processor Started");
        $this->log(str_repeat('=', 60));
        $this->log("Timestamp: " . date('Y-m-d H:i:s'));
        $this->log("Batch Size: $limit");
        $this->log("Providers: " . count($this->providers));

        // 📊 Statistics
        $stats = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'retried' => 0,
            'dead_letter' => 0,
            'duration' => 0
        ];

        try {
            // 🔍 Get pending emails from queue
            $pendingEmails = $this->getPendingEmails($limit);

            $this->log("\n📬 Found " . count($pendingEmails) . " emails to process");

            // 📧 Process each email
            foreach ($pendingEmails as $queueItem) {
                $stats['processed']++;

                $this->log("\n" . str_repeat('-', 60));
                $this->log("📧 Processing Email ID: {$queueItem['emailID']}");
                $this->log("   To: {$queueItem['recipientEmail']}");
                $this->log("   Subject: {$queueItem['subject']}");
                $this->log("   Attempts: {$queueItem['attemptCount']}");

                // 📤 Attempt to send email
                $result = $this->sendEmail($queueItem);

                if ($result['success']) {
                    // ✅ Email sent successfully
                    $this->markAsSent($queueItem['emailID'], $result['provider'], $result['messageId']);
                    $stats['sent']++;
                    $this->log("   ✅ Sent via {$result['provider']}");
                } else {
                    // ❌ Email failed
                    $attemptCount = $queueItem['attemptCount'] + 1;

                    if ($attemptCount >= self::MAX_RETRY_ATTEMPTS) {
                        // 💀 Move to dead letter queue
                        $this->moveToDeadLetter($queueItem['emailID'], $result['error']);
                        $stats['dead_letter']++;
                        $this->log("   💀 Moved to dead letter queue (max retries exceeded)");
                    } else {
                        // 🔄 Schedule retry
                        $this->scheduleRetry($queueItem['emailID'], $attemptCount, $result['error']);
                        $stats['retried']++;
                        $this->log("   🔄 Scheduled for retry (attempt {$attemptCount}/" . self::MAX_RETRY_ATTEMPTS . ")");
                    }

                    $stats['failed']++;
                    $this->log("   ❌ Error: {$result['error']}");
                }
            }

        } catch (Exception $e) {
            $this->log("\n❌ FATAL ERROR: " . $e->getMessage());
            error_log("Email queue processor error: " . $e->getMessage());
        }

        // 📊 Calculate duration
        $stats['duration'] = round(microtime(true) - $startTime, 2);

        // 📝 Summary
        $this->log("\n" . str_repeat('=', 60));
        $this->log("📊 Processing Summary");
        $this->log(str_repeat('=', 60));
        $this->log("Processed: {$stats['processed']}");
        $this->log("Sent: {$stats['sent']}");
        $this->log("Failed: {$stats['failed']}");
        $this->log("Retried: {$stats['retried']}");
        $this->log("Dead Letter: {$stats['dead_letter']}");
        $this->log("Duration: {$stats['duration']}s");
        $this->log(str_repeat('=', 60) . "\n");

        return $stats;
    }

    /**
     * 📬 Get Pending Emails
     *
     * Retrieves pending emails from queue.
     *
     * @param int $limit Maximum number to retrieve
     * @return array Pending emails
     */
    private function getPendingEmails(int $limit): array
    {
        $query = "
            SELECT *
            FROM tblEmailQueue
            WHERE status = 'pending'
              AND (scheduledAt IS NULL OR scheduledAt <= NOW())
              AND attemptCount < ?
            ORDER BY priority DESC, createdAt ASC
            LIMIT ?
        ";

        return Database::fetchAll(
            $query,
            [self::MAX_RETRY_ATTEMPTS, $limit],
            'ii'
        ) ?: [];
    }

    /**
     * 📤 Send Email
     *
     * Attempts to send email using available providers.
     *
     * @param array $queueItem Queue item data
     * @return array Result with success, provider, messageId, and error
     */
    private function sendEmail(array $queueItem): array
    {
        // ⚠️ No providers available
        if (empty($this->providers)) {
            return [
                'success' => false,
                'provider' => null,
                'messageId' => null,
                'error' => 'No email providers configured'
            ];
        }

        // 📝 Prepare email data
        $emailData = [
            'to' => $queueItem['recipientEmail'],
            'subject' => $queueItem['subject'],
            'bodyText' => $queueItem['bodyText'],
            'bodyHTML' => $queueItem['bodyHTML'],
            'from' => $queueItem['fromEmail'],
            'fromName' => $queueItem['fromName'],
            'replyTo' => $queueItem['replyToEmail'],
            'cc' => !empty($queueItem['ccRecipients']) ? json_decode($queueItem['ccRecipients'], true) : [],
            'bcc' => !empty($queueItem['bccRecipients']) ? json_decode($queueItem['bccRecipients'], true) : [],
            'attachments' => !empty($queueItem['attachments']) ? json_decode($queueItem['attachments'], true) : []
        ];

        // 🔄 Try each provider in order
        $lastError = null;

        foreach ($this->providers as $provider) {
            try {
                $this->log("   🔄 Trying provider: {$provider->getProviderName()}");

                $result = $provider->send($emailData);

                if ($result['success']) {
                    return [
                        'success' => true,
                        'provider' => $provider->getProviderName(),
                        'messageId' => $result['messageId'],
                        'error' => null
                    ];
                }

                $lastError = $result['error'];
                $this->log("      ❌ Failed: {$lastError}");

            } catch (Exception $e) {
                $lastError = $e->getMessage();
                $this->log("      ❌ Exception: {$lastError}");
            }
        }

        // ❌ All providers failed
        return [
            'success' => false,
            'provider' => null,
            'messageId' => null,
            'error' => $lastError ?? 'All email providers failed'
        ];
    }

    // ========================================================================
    // 📊 STATUS UPDATES
    // ========================================================================

    /**
     * ✅ Mark Email as Sent
     *
     * Updates queue item status to sent.
     *
     * @param int $emailID Email ID
     * @param string $provider Provider name
     * @param string|null $messageId Message ID from provider
     */
    private function markAsSent(int $emailID, string $provider, ?string $messageId): void
    {
        $query = "
            UPDATE tblEmailQueue
            SET status = 'sent',
                sentAt = NOW(),
                provider = ?,
                providerMessageID = ?,
                lastError = NULL
            WHERE emailID = ?
        ";

        Database::query(
            $query,
            [$provider, $messageId, $emailID],
            'ssi'
        );
    }

    /**
     * 🔄 Schedule Retry
     *
     * Schedules email for retry with exponential backoff.
     *
     * @param int $emailID Email ID
     * @param int $attemptCount Current attempt count
     * @param string|null $error Error message
     */
    private function scheduleRetry(int $emailID, int $attemptCount, ?string $error): void
    {
        // ⏰ Calculate next retry time (exponential backoff: 5min, 15min, 30min)
        $backoffMinutes = [5, 15, 30];
        $backoffIndex = min($attemptCount - 1, count($backoffMinutes) - 1);
        $retryDelay = $backoffMinutes[$backoffIndex];

        $query = "
            UPDATE tblEmailQueue
            SET status = 'pending',
                attemptCount = ?,
                scheduledAt = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                lastAttemptAt = NOW(),
                lastError = ?
            WHERE emailID = ?
        ";

        Database::query(
            $query,
            [$attemptCount, $retryDelay, $error, $emailID],
            'iisi'
        );
    }

    /**
     * 💀 Move to Dead Letter Queue
     *
     * Moves permanently failed email to dead letter queue.
     *
     * @param int $emailID Email ID
     * @param string|null $error Final error message
     */
    private function moveToDeadLetter(int $emailID, ?string $error): void
    {
        $query = "
            UPDATE tblEmailQueue
            SET status = 'failed',
                lastAttemptAt = NOW(),
                lastError = ?
            WHERE emailID = ?
        ";

        Database::query(
            $query,
            [$error, $emailID],
            'si'
        );

        // 📝 Log to activity log
        ActivityLogger::log(
            null,
            'email_dead_letter',
            'email',
            'error',
            "Email moved to dead letter queue after " . self::MAX_RETRY_ATTEMPTS . " failed attempts",
            [
                'email_id' => $emailID,
                'error' => $error
            ]
        );
    }

    // ========================================================================
    // 🔧 UTILITY METHODS
    // ========================================================================

    /**
     * 📝 Log Message
     *
     * Outputs log message if verbose mode enabled.
     *
     * @param string $message Message to log
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo $message . PHP_EOL;
        }
    }

    /**
     * 📊 Get Queue Statistics
     *
     * Returns current queue statistics.
     *
     * @return array Statistics
     */
    public static function getQueueStatistics(): array
    {
        $query = "
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN attemptCount > 0 THEN 1 ELSE 0 END) as retried,
                MIN(createdAt) as oldest_pending,
                MAX(sentAt) as last_sent
            FROM tblEmailQueue
        ";

        $stats = Database::fetchOne($query);

        return $stats ?: [
            'total' => 0,
            'pending' => 0,
            'sent' => 0,
            'failed' => 0,
            'retried' => 0,
            'oldest_pending' => null,
            'last_sent' => null
        ];
    }

    /**
     * 🧹 Cleanup Old Emails
     *
     * Removes old sent emails from queue (for housekeeping).
     *
     * @param int $daysOld Number of days to keep
     * @return int Number of emails deleted
     */
    public static function cleanupOldEmails(int $daysOld = 30): int
    {
        $query = "
            DELETE FROM tblEmailQueue
            WHERE status = 'sent'
              AND sentAt < DATE_SUB(NOW(), INTERVAL ? DAY)
        ";

        $result = Database::query($query, [$daysOld], 'i');

        return $result ? Database::getAffectedRows() : 0;
    }
}

// ✅ EmailQueueProcessor class loaded successfully

// ========================================================================
// 🖥️ CLI EXECUTION
// ========================================================================

// 🔍 Check if running from command line
if (php_sapi_name() === 'cli' && !defined('SIGNULA_INIT')) {
    // 🚀 Bootstrap application
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

    // 📝 Parse command line arguments
    $options = getopt('v', ['verbose', 'limit:', 'cleanup:']);

    $verbose = isset($options['v']) || isset($options['verbose']);
    $limit = isset($options['limit']) ? (int)$options['limit'] : null;
    $cleanup = isset($options['cleanup']) ? (int)$options['cleanup'] : null;

    // 🧹 Cleanup mode
    if ($cleanup !== null) {
        echo "🧹 Cleaning up emails older than $cleanup days...\n";
        $deleted = EmailQueueProcessor::cleanupOldEmails($cleanup);
        echo "✅ Deleted $deleted old emails\n";
        exit(0);
    }

    // 📧 Process queue
    $processor = new EmailQueueProcessor($verbose);
    $stats = $processor->process($limit);

    // 🚪 Exit with appropriate code
    exit($stats['failed'] > 0 ? 1 : 0);
}
