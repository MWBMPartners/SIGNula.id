<?php
/**
 * ============================================================================
 * ⏰ SIGNula - Billing Cron Endpoint
 * ============================================================================
 *
 * Purpose: Web-accessible cron endpoint for automated billing task processing.
 *          Called by an external cron service (e.g. cron-job.org, UptimeRobot)
 *          hitting this HTTPS endpoint on a schedule (recommended: every 5-15 min).
 *
 * Tasks Processed:
 *   - Subscription charge attempts (subscription_charge)
 *   - Payment reminder emails (payment_reminder)
 *   - Auto-suspension of past-due accounts (auto_suspension)
 *   - Trial expiration handling (trial_expiration)
 *
 * Authentication:
 *   Secured via a secret token stored in tblSettings (key: payment.cron.secret_token).
 *   The token must be passed as a GET parameter (?token=xxx) or in the
 *   Authorization header (Bearer xxx).
 *
 * Architecture:
 *   This is Tier 2 of the three-tier billing redundancy system:
 *     Tier 1: MySQL Scheduled Events (hourly, mark past_due / expire trials)
 *     Tier 2: This web-accessible cron endpoint (every 5-15 min)
 *     Tier 3: BillingLazyCheck safety net (piggybacks on authenticated page loads)
 *
 * @see /web/private_html/payments/BillingScheduler.php — Task processing engine
 * @see /_database/migrations/012_payment_expansion.sql — tblBillingSchedule schema
 * @see https://cron-job.org/ — Free external cron service
 * @see https://uptimerobot.com/ — Alternative cron/monitoring service
 *
 * PHP Version: 8.3+
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Cron
 * @version    1.0.0
 * @since      2.4.0-beta
 * ============================================================================
 */

// ============================================================================
// 🚀 BOOTSTRAP — Load core system
// ============================================================================

// ⚙️ Main configuration (defines constants, loads settings, initializes session)
// @see web/_config/config.php
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton
// @see web/_backend/Database.php
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 📤 Set JSON response content type
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
header('Content-Type: application/json');

// 🚫 Only allow GET requests (cron services typically use GET)
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}


// ============================================================================
// 🔐 AUTHENTICATION — Verify cron secret token
// ============================================================================

// 📖 Get the secret token from the database
// Stored in tblSettings with key 'payment.cron.secret_token'
$db = Database::getInstance();

$tokenStmt = $db->prepare("
    SELECT settingValue
    FROM tblSettings
    WHERE settingKey = 'payment.cron.secret_token'
    LIMIT 1
");
$tokenStmt->execute();
$tokenResult = $tokenStmt->get_result()->fetch_assoc();
$expectedToken = $tokenResult['settingValue'] ?? '';

// 🚫 If no token configured, cron is disabled
if (empty($expectedToken)) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Billing cron not configured. Set payment.cron.secret_token in settings.',
    ]);
    exit;
}

// 🔑 Extract token from request (GET param or Authorization header)
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Authorization
$providedToken = $_GET['token'] ?? '';

if (empty($providedToken)) {
    // 🔍 Check Authorization header (Bearer token)
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($authHeader, 'Bearer ')) {
        $providedToken = substr($authHeader, 7);
    }
}

// 🔒 Constant-time comparison to prevent timing attacks
// @see https://www.php.net/manual/en/function.hash-equals.php
if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing cron token']);

    // 📝 Log the failed attempt
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            null,
            'cron_auth_failed',
            'security',
            'warning',
            'Failed billing cron authentication attempt from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '']
        );
    }

    exit;
}


// ============================================================================
// ⏰ PROCESS BILLING TASKS
// ============================================================================

// 🔌 Load the BillingScheduler class
// @see web/private_html/payments/BillingScheduler.php
$schedulerPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'BillingScheduler.php';

if (!file_exists($schedulerPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'BillingScheduler not available']);
    exit;
}

require_once $schedulerPath;

// 📊 Get optional batch size from request (default: 25)
$batchSize = max(1, min(100, (int)($_GET['batch'] ?? 25)));

// ⏱️ Record start time for performance tracking
$startTime = microtime(true);

try {
    // 🔄 Process all due billing tasks
    // This handles: subscription charges, payment reminders, auto-suspensions, trial expirations
    $result = BillingScheduler::processDueTasks($batchSize);

    // ⏱️ Calculate execution time
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    // 📊 Get scheduler health for monitoring
    $health = BillingScheduler::getSchedulerHealth();

    // ✅ Return success with processing summary
    echo json_encode([
        'success'       => true,
        'processed'     => $result['processed'] ?? 0,
        'succeeded'     => $result['succeeded'] ?? 0,
        'failed'        => $result['failed'] ?? 0,
        'skipped'       => $result['skipped'] ?? 0,
        'executionMs'   => $executionTime,
        'health'        => $health,
        'timestamp'     => date('Y-m-d H:i:s'),
        'batchSize'     => $batchSize,
    ]);

    // 📝 Log successful cron execution
    if (function_exists('logActivity')) {
        logActivity(null, 'cron_billing_executed', 'system', 'info',
            "Billing cron processed {$result['processed']} tasks ({$result['succeeded']} succeeded, {$result['failed']} failed)",
            [
                'processed'    => $result['processed'] ?? 0,
                'succeeded'    => $result['succeeded'] ?? 0,
                'failed'       => $result['failed'] ?? 0,
                'executionMs'  => $executionTime,
            ]
        );
    }

} catch (\Exception $e) {
    // 🚨 Error during processing
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'message'   => 'Billing cron execution failed',
        'timestamp' => date('Y-m-d H:i:s'),
    ]);

    // 📝 Log the error
    if (function_exists('logActivity')) {
        logActivity(null, 'cron_billing_error', 'system', 'error',
            'Billing cron failed: ' . $e->getMessage(),
            ['exception' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
        );
    }
}
