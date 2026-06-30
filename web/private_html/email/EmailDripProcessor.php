<?php
/**
 * ============================================================================
 * 💧 SIGNula - Email Drip Campaign Processor
 * ============================================================================
 *
 * Purpose: CLI processor for drip campaign emails
 * PHP Version: 8.3+
 *
 * Usage:
 * php EmailDripProcessor.php [options]
 *
 * Options:
 * -v, --verbose    Enable verbose output
 * --limit=N        Process maximum N emails (default: 100)
 * --campaign=ID    Process only specific campaign
 *
 * Example:
 * php EmailDripProcessor.php -v --limit=50
 *
 * Cron Setup:
 * (every 15 min) php /path/to/EmailDripProcessor.php >> /var/log/drip-processor.log 2>&1
 * Crontab entry: star-slash-15 * * * * php /path/to/EmailDripProcessor.php >> /var/log/drip-processor.log 2>&1
 *
 * @package    SIGNula
 * @subpackage Email
 * @version    1.0.0
 * ============================================================================
 */

// 🚫 Prevent direct web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line');
}

// 🚀 Bootstrap application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🚫 Prevent direct access (config.php defines SIGNULA_INIT; this also guards
//    against the file being required from an uninitialised context)
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 📚 Load required classes
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EmailDripCampaign.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EmailService.php';

/**
 * 💧 Email Drip Processor
 *
 * Processes due drip campaign emails.
 */
class EmailDripProcessor
{
    /**
     * @var bool Verbose output flag
     */
    private bool $verbose = false;

    /**
     * @var int Maximum emails to process
     */
    private int $limit = 100;

    /**
     * @var int|null Specific campaign to process
     */
    private ?int $campaignID = null;

    /**
     * 🏗️ Constructor
     *
     * @param bool $verbose Enable verbose output
     * @param int $limit Maximum emails to process
     * @param int|null $campaignID Specific campaign ID
     */
    public function __construct(bool $verbose = false, int $limit = 100, ?int $campaignID = null)
    {
        $this->verbose = $verbose;
        $this->limit = $limit;
        $this->campaignID = $campaignID;
    }

    /**
     * ⚙️ Process Drip Emails
     *
     * Main processing method.
     *
     * @return array Processing statistics
     */
    public function process(): array
    {
        $this->log("💧 Email Drip Campaign Processor Started");
        $this->log(str_repeat('=', 60));
        $this->log("📅 Time: " . date('Y-m-d H:i:s'));
        $this->log("📊 Limit: {$this->limit} emails");

        if ($this->campaignID) {
            $this->log("🎯 Campaign: #{$this->campaignID}");
        }

        $this->log("");

        $startTime = microtime(true);
        $stats = [
            'processed' => 0,
            'queued' => 0,
            'skipped' => 0,
            'errors' => 0,
            'campaigns' => []
        ];

        try {
            // 🔍 Get active campaigns
            $campaigns = $this->getActiveCampaigns();

            if (empty($campaigns)) {
                $this->log("ℹ️  No active campaigns found");
                return $stats;
            }

            $this->log("📋 Found " . count($campaigns) . " active campaign(s)");
            $this->log("");

            // 📧 Process each campaign
            foreach ($campaigns as $campaign) {
                $campaignStats = $this->processCampaign($campaign);

                $stats['processed'] += $campaignStats['processed'];
                $stats['queued'] += $campaignStats['queued'];
                $stats['skipped'] += $campaignStats['skipped'];
                $stats['errors'] += $campaignStats['errors'];

                $stats['campaigns'][$campaign['campaignID']] = $campaignStats;

                // 🛑 Stop if limit reached
                if ($stats['processed'] >= $this->limit) {
                    $this->log("\n⚠️  Limit reached, stopping processing");
                    break;
                }
            }

        } catch (Exception $e) {
            $this->log("❌ Fatal error: " . $e->getMessage());
            $stats['errors']++;
        }

        // 📊 Summary
        $duration = round(microtime(true) - $startTime, 2);

        $this->log("");
        $this->log(str_repeat('=', 60));
        $this->log("✅ Processing Complete");
        $this->log("⏱️  Duration: {$duration}s");
        $this->log("📧 Processed: {$stats['processed']}");
        $this->log("📬 Queued: {$stats['queued']}");
        $this->log("⏭️  Skipped: {$stats['skipped']}");
        $this->log("❌ Errors: {$stats['errors']}");
        $this->log("");

        return $stats;
    }

    /**
     * 🔍 Get Active Campaigns
     *
     * Retrieves active campaigns to process.
     *
     * @return array Active campaigns
     */
    private function getActiveCampaigns(): array
    {
        if ($this->campaignID) {
            // Get specific campaign
            $campaign = EmailDripCampaign::getCampaign($this->campaignID);
            return $campaign ? [$campaign] : [];
        }

        // Get all active campaigns
        $query = "
            SELECT * FROM tblEmailDripCampaigns
            WHERE isActive = 1
            ORDER BY campaignID ASC
        ";

        return Database::fetchAll($query) ?: [];
    }

    /**
     * 📧 Process Campaign
     *
     * Processes due emails for a campaign.
     *
     * @param array $campaign Campaign data
     * @return array Campaign processing statistics
     */
    private function processCampaign(array $campaign): array
    {
        $this->log("🎯 Processing campaign: {$campaign['campaignName']} (#{$campaign['campaignID']})");

        $stats = [
            'processed' => 0,
            'queued' => 0,
            'skipped' => 0,
            'errors' => 0
        ];

        // 🔍 Get subscribers with due emails
        $subscribers = $this->getDueSubscribers($campaign['campaignID']);

        if (empty($subscribers)) {
            $this->log("   ℹ️  No due emails for this campaign");
            return $stats;
        }

        $this->log("   📧 Found " . count($subscribers) . " subscriber(s) with due emails");

        // 📨 Process each subscriber
        foreach ($subscribers as $subscriber) {
            $stats['processed']++;

            try {
                $this->log("   👤 Processing subscriber: {$subscriber['email']}");

                if (EmailDripCampaign::queueNextEmail($subscriber['subscriberID'])) {
                    $stats['queued']++;
                    $this->log("      ✅ Email queued");
                } else {
                    $stats['skipped']++;
                    $this->log("      ⏭️  Skipped (condition not met or campaign complete)");
                }

            } catch (Exception $e) {
                $stats['errors']++;
                $this->log("      ❌ Error: " . $e->getMessage());
            }

            // 🛑 Check limit
            if ($stats['processed'] >= $this->limit) {
                break;
            }
        }

        $this->log("");

        return $stats;
    }

    /**
     * 🔍 Get Due Subscribers
     *
     * Gets subscribers with emails due for sending.
     *
     * @param int $campaignID Campaign ID
     * @return array Subscribers
     */
    private function getDueSubscribers(int $campaignID): array
    {
        $query = "
            SELECT * FROM tblEmailDripSubscribers
            WHERE campaignID = ?
            AND status = 'active'
            AND nextEmailDue IS NOT NULL
            AND nextEmailDue <= NOW()
            ORDER BY nextEmailDue ASC
            LIMIT ?
        ";

        return Database::fetchAll($query, [$campaignID, $this->limit], 'ii') ?: [];
    }

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
}

// ========================================================================
// 🖥️ CLI EXECUTION
// ========================================================================

// 📝 Parse command line arguments
$options = getopt('v', ['verbose', 'limit:', 'campaign:']);

$verbose = isset($options['v']) || isset($options['verbose']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 100;
$campaignID = isset($options['campaign']) ? (int)$options['campaign'] : null;

// ⚙️ Create and run processor
$processor = new EmailDripProcessor($verbose, $limit, $campaignID);
$stats = $processor->process();

// 🚪 Exit with appropriate code
exit($stats['errors'] > 0 ? 1 : 0);
