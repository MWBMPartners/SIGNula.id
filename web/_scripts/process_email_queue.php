<?php
/**
 * ============================================================================
 * 📧 SIGNula - Email Queue Processing Script
 * ============================================================================
 *
 * CLI script for processing the email queue. Can be called via cron job
 * or manually from the command line.
 *
 * Usage:
 *   php process_email_queue.php                    # Process queue (default batch size)
 *   php process_email_queue.php -v                 # Verbose output
 *   php process_email_queue.php --limit=100        # Process up to 100 emails
 *   php process_email_queue.php --cleanup=30       # Delete emails older than 30 days
 *   php process_email_queue.php --retry            # Retry failed emails
 *   php process_email_queue.php --stats            # Show queue statistics
 *
 * Cron Example (every 5 minutes):
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * /usr/bin/php /path/to/process_email_queue.php >> /path/to/logs/email_queue.log 2>&1
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Scripts
 * @version    1.0.0
 * @see        web/private_html/email/EmailQueueProcessor.php
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 🔒 CLI-ONLY EXECUTION GUARD
// ============================================================================
// This script must only be run from the command line for security.
// @see https://www.php.net/manual/en/function.php-sapi-name.php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

// ============================================================================
// 🛡️ SIGNULA INIT & BOOTSTRAP
// ============================================================================
if (!defined('SIGNULA_INIT')) {
    define('SIGNULA_INIT', true);
}

// Navigate from web/_scripts/ → web/_config/
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 📋 PARSE COMMAND LINE ARGUMENTS
// ============================================================================
// @see https://www.php.net/manual/en/function.getopt.php
$options = getopt('vh', ['verbose', 'help', 'limit:', 'cleanup:', 'retry', 'stats']);

$verbose = isset($options['v']) || isset($options['verbose']);
$showHelp = isset($options['h']) || isset($options['help']);
$limit = isset($options['limit']) ? (int) $options['limit'] : null;
$cleanupDays = isset($options['cleanup']) ? (int) $options['cleanup'] : null;
$retryFailed = isset($options['retry']);
$showStats = isset($options['stats']);

// ============================================================================
// ❓ HELP
// ============================================================================
if ($showHelp) {
    echo <<<HELP
📧 SIGNula Email Queue Processor

Usage:
  php process_email_queue.php [options]

Options:
  -v, --verbose       Enable verbose output
  -h, --help          Show this help message
  --limit=N           Process up to N emails (default: from settings)
  --cleanup=N         Delete sent/failed emails older than N days
  --retry             Re-queue all failed emails for retry
  --stats             Show current queue statistics

Examples:
  php process_email_queue.php                  Process pending emails
  php process_email_queue.php -v --limit=100   Process 100 emails with verbose output
  php process_email_queue.php --cleanup=30     Delete old emails (>30 days)
  php process_email_queue.php --retry          Retry all failed emails
  php process_email_queue.php --stats          Show queue statistics

HELP;
    exit(0);
}

// ============================================================================
// 📂 LOAD EMAIL QUEUE PROCESSOR
// ============================================================================
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private_html'
    . DIRECTORY_SEPARATOR . 'email'
    . DIRECTORY_SEPARATOR . 'EmailQueueProcessor.php';

// ============================================================================
// 📊 STATS MODE
// ============================================================================
if ($showStats) {
    $stats = EmailQueueProcessor::getQueueStatistics();
    echo "📊 Email Queue Statistics\n";
    echo str_repeat('─', 40) . "\n";
    echo "  Total:        {$stats['total']}\n";
    echo "  Pending:      {$stats['pending']}\n";
    echo "  Processing:   " . ($stats['processing'] ?? 0) . "\n";
    echo "  Sent:         {$stats['sent']}\n";
    echo "  Failed:       {$stats['failed']}\n";
    echo "  Cancelled:    " . ($stats['cancelled'] ?? 0) . "\n";
    echo str_repeat('─', 40) . "\n";
    echo "  Oldest Pending: " . ($stats['oldest_pending'] ?? 'N/A') . "\n";
    echo "  Last Sent:      " . ($stats['last_sent'] ?? 'N/A') . "\n";
    if (isset($stats['avg_delivery_seconds'])) {
        echo "  Avg Delivery:   {$stats['avg_delivery_seconds']}s\n";
    }
    exit(0);
}

// ============================================================================
// 🧹 CLEANUP MODE
// ============================================================================
if ($cleanupDays !== null) {
    echo "🧹 Cleaning up emails older than {$cleanupDays} days...\n";
    $deleted = EmailQueueProcessor::cleanupOldEmails($cleanupDays);
    echo "✅ Deleted {$deleted} old email(s)\n";
    exit(0);
}

// ============================================================================
// 🔄 RETRY MODE
// ============================================================================
if ($retryFailed) {
    echo "🔄 Re-queuing failed emails...\n";
    $result = EmailQueueProcessor::retryFailed(3);
    echo "✅ Re-queued: {$result['requeued']}, Skipped: {$result['skipped']}\n";
    if (isset($result['error'])) {
        echo "❌ Error: {$result['error']}\n";
    }
    exit($result['requeued'] >= 0 ? 0 : 1);
}

// ============================================================================
// 📧 PROCESS QUEUE (DEFAULT MODE)
// ============================================================================
echo "📧 SIGNula Email Queue Processor — " . date('Y-m-d H:i:s') . "\n";

$processor = new EmailQueueProcessor($verbose);
$stats = $processor->process($limit);

// 📊 Summary output
if (!$verbose) {
    echo "Processed: {$stats['processed']}, Sent: {$stats['sent']}, Failed: {$stats['failed']}, Duration: {$stats['duration']}s\n";
}

// 🚪 Exit with appropriate code (non-zero if failures occurred)
exit($stats['failed'] > 0 ? 1 : 0);
