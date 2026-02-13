<?php
/**
 * ============================================================================
 * 🦥 SIGNula - Billing Lazy Check (Safety Net)
 * ============================================================================
 *
 * Purpose: Tier 3 billing redundancy safety net. Included in authenticated
 *          page loads to ensure billing tasks are processed even if the
 *          external cron service (Tier 2) goes down.
 *
 * How It Works:
 *   1. On every authenticated page load, checks when the billing scheduler
 *      last ran (via tblSettings 'payment.cron.last_run')
 *   2. If last run was > 1 hour ago, processes a small batch of due tasks
 *      (default: 5 tasks) to keep the system moving
 *   3. Processing happens at the END of page generation to avoid blocking
 *      the user's page load (uses register_shutdown_function)
 *   4. Sets a per-request flag to prevent multiple lazy checks in the same
 *      request (e.g. if config.php includes this file more than once)
 *
 * Performance Impact:
 *   - When cron is healthy: Near zero (single lightweight DB query to check timestamp)
 *   - When cron is stale: Processes 5 tasks in shutdown, invisible to user
 *   - Designed to be included via require_once in config.php or page template
 *
 * Architecture (Three-Tier Billing Redundancy):
 *   Tier 1: MySQL Scheduled Events (hourly) — mark past_due, expire trials
 *   Tier 2: Web-accessible cron endpoints — main processing (every 5-15 min)
 *   Tier 3: THIS FILE — safety net piggyback on page loads (if cron > 1hr stale)
 *
 * @see /web/private_html/payments/BillingScheduler.php — Task processing engine
 * @see /web/public_html/cron/billing.php — Tier 2 cron endpoint
 * @see /_database/migrations/012_payment_expansion.sql — tblBillingSchedule schema
 *
 * PHP Version: 8.3+
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.0.0
 * @since      2.4.0-beta
 * ============================================================================
 */

// 🚫 Prevent direct access
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🦥 Billing Lazy Check Class
 *
 * Static utility that checks cron health and processes a small batch of
 * billing tasks if the cron service appears stale (> 1 hour since last run).
 *
 * Usage:
 * ```php
 * // Include in config.php or page template (after SIGNULA_INIT is defined)
 * require_once PRIVATE_DIR . '/payments/BillingLazyCheck.php';
 * BillingLazyCheck::register();
 * ```
 *
 * @since 2.4.0-beta
 */
class BillingLazyCheck
{
    /** @var int Maximum age of last cron run (in seconds) before lazy check kicks in */
    private const STALE_THRESHOLD_SECONDS = 3600; // 1 hour

    /** @var int Number of tasks to process in a lazy batch */
    private const LAZY_BATCH_SIZE = 5;

    /** @var bool Flag to prevent multiple registrations in the same request */
    private static bool $registered = false;

    /** @var bool Flag to prevent multiple executions in the same request */
    private static bool $executed = false;


    /**
     * 📝 Register the lazy check to run at shutdown
     *
     * Checks if the billing cron is stale and, if so, registers a shutdown
     * function to process a small batch of tasks after the page is sent.
     *
     * This method is safe to call multiple times — subsequent calls are no-ops.
     *
     * @return void
     *
     * @see https://www.php.net/manual/en/function.register-shutdown-function.php
     *
     * @since 2.4.0-beta
     */
    public static function register(): void
    {
        // 🚫 Only register once per request
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // 🔍 Quick check — is the cron stale?
        if (!self::isCronStale()) {
            return; // 🟢 Cron is healthy, nothing to do
        }

        // ⏰ Register shutdown function to process tasks after page is sent
        // @see https://www.php.net/manual/en/function.register-shutdown-function.php
        register_shutdown_function([self::class, 'processLazyBatch']);
    }


    /**
     * 🔍 Check if the billing cron is stale
     *
     * Queries tblSettings for the last cron run timestamp and compares
     * against the stale threshold (default: 1 hour).
     *
     * @return bool True if cron is stale and lazy check should run
     *
     * @since 2.4.0-beta
     */
    public static function isCronStale(): bool
    {
        try {
            $db = Database::getInstance();

            // 📖 Get last cron run time
            $stmt = $db->prepare("
                SELECT settingValue
                FROM tblSettings
                WHERE settingKey = 'payment.cron.last_run'
                LIMIT 1
            ");
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();

            // 🚫 If no last run recorded, cron has never run — it's definitely stale
            if (!$result || empty($result['settingValue'])) {
                return true;
            }

            // ⏰ Parse the last run timestamp
            $lastRun = strtotime($result['settingValue']);
            if ($lastRun === false) {
                return true;
            }

            // 📊 Check if last run exceeds threshold
            $elapsed = time() - $lastRun;
            return $elapsed > self::STALE_THRESHOLD_SECONDS;

        } catch (\Exception $e) {
            // 🚨 On error, don't trigger lazy check to avoid cascading failures
            return false;
        }
    }


    /**
     * 🔄 Process a small batch of due billing tasks
     *
     * Called via register_shutdown_function after the page has been sent
     * to the browser. Processes up to LAZY_BATCH_SIZE tasks.
     *
     * This method:
     *   1. Flushes output buffers so the user sees the page immediately
     *   2. Loads BillingScheduler if not already loaded
     *   3. Processes a small batch of due tasks
     *   4. Updates the last run timestamp
     *   5. Logs the result
     *
     * @return void
     *
     * @since 2.4.0-beta
     */
    public static function processLazyBatch(): void
    {
        // 🚫 Only execute once per request
        if (self::$executed) {
            return;
        }
        self::$executed = true;

        try {
            // 📤 Flush output buffers so the user gets the page immediately
            // @see https://www.php.net/manual/en/function.ob-end-flush.php
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();

            // 🔌 Load BillingScheduler if not already loaded
            if (!class_exists('BillingScheduler')) {
                $schedulerPath = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'BillingScheduler.php';
                if (!file_exists($schedulerPath)) {
                    return; // 🚫 BillingScheduler not available
                }
                require_once $schedulerPath;
            }

            // 🔄 Process a small batch of due tasks
            $result = BillingScheduler::processLazyBatch(self::LAZY_BATCH_SIZE);

            // 📝 Log if tasks were processed
            if (($result['processed'] ?? 0) > 0 && function_exists('logActivity')) {
                logActivity(null, 'billing_lazy_check', 'system', 'info',
                    "Lazy check processed {$result['processed']} tasks (cron appeared stale)",
                    [
                        'processed' => $result['processed'] ?? 0,
                        'succeeded' => $result['succeeded'] ?? 0,
                        'failed'    => $result['failed'] ?? 0,
                    ]
                );
            }

        } catch (\Exception $e) {
            // 🚨 Silently fail — lazy check errors should never affect user experience
            // Log if possible
            if (function_exists('logActivity')) {
                logActivity(null, 'billing_lazy_check_error', 'system', 'warning',
                    'Lazy check failed: ' . $e->getMessage(),
                    ['exception' => $e->getMessage()]
                );
            }
        }
    }


    /**
     * 📊 Get lazy check status information
     *
     * Returns diagnostic information about the lazy check system,
     * useful for the admin dashboard.
     *
     * @return array Status information
     *
     * @since 2.4.0-beta
     */
    public static function getStatus(): array
    {
        try {
            $db = Database::getInstance();

            // 📖 Get last cron run time
            $stmt = $db->prepare("
                SELECT settingValue
                FROM tblSettings
                WHERE settingKey = 'payment.cron.last_run'
                LIMIT 1
            ");
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();

            $lastRun = $result['settingValue'] ?? null;
            $isStale = self::isCronStale();

            // 📊 Count pending tasks
            $pendingStmt = $db->prepare("
                SELECT COUNT(*) AS pending
                FROM tblBillingSchedule
                WHERE status = 'pending' AND scheduledDate <= NOW()
            ");
            $pendingStmt->execute();
            $pendingCount = (int)($pendingStmt->get_result()->fetch_assoc()['pending'] ?? 0);

            return [
                'lastCronRun'     => $lastRun,
                'isCronStale'     => $isStale,
                'staleThreshold'  => self::STALE_THRESHOLD_SECONDS,
                'lazyBatchSize'   => self::LAZY_BATCH_SIZE,
                'pendingTasks'    => $pendingCount,
                'registered'      => self::$registered,
                'executed'        => self::$executed,
            ];

        } catch (\Exception $e) {
            return [
                'error'   => 'Unable to get lazy check status',
                'message' => $e->getMessage(),
            ];
        }
    }
}
