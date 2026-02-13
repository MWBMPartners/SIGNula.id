<?php
/**
 * ============================================================================
 * 💸 SIGNula - Remittance Cron Endpoint
 * ============================================================================
 *
 * Purpose: Web-accessible cron endpoint for processing pending partner payouts.
 *          Called by an external cron service on a schedule (recommended: daily
 *          or weekly depending on payout frequency).
 *
 * Tasks Processed:
 *   - Auto-process pending remittances that meet minimum payout thresholds
 *   - Batch payouts to reduce transaction costs
 *   - Update remittance statuses
 *
 * Authentication:
 *   Same secret token as billing.php — payment.cron.secret_token in tblSettings.
 *
 * @see /web/private_html/payments/ServiceFeeManager.php — Remittance processing
 * @see /_database/migrations/012_payment_expansion.sql — tblRemittances schema
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
// 🚀 BOOTSTRAP
// ============================================================================

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

header('Content-Type: application/json');

// 🚫 Only allow GET/POST
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}


// ============================================================================
// 🔐 AUTHENTICATION
// ============================================================================

$db = Database::getInstance();

// 📖 Get the cron secret token
$tokenStmt = $db->prepare("
    SELECT settingValue
    FROM tblSettings
    WHERE settingKey = 'payment.cron.secret_token'
    LIMIT 1
");
$tokenStmt->execute();
$tokenResult = $tokenStmt->get_result()->fetch_assoc();
$expectedToken = $tokenResult['settingValue'] ?? '';

if (empty($expectedToken)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Remittance cron not configured']);
    exit;
}

// 🔑 Extract token from request
$providedToken = $_GET['token'] ?? '';
if (empty($providedToken)) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($authHeader, 'Bearer ')) {
        $providedToken = substr($authHeader, 7);
    }
}

// 🔒 Constant-time comparison
// @see https://www.php.net/manual/en/function.hash-equals.php
if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing cron token']);
    exit;
}


// ============================================================================
// 💸 PROCESS PENDING REMITTANCES
// ============================================================================

// 🔌 Load ServiceFeeManager
$sfmPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'ServiceFeeManager.php';

if (!file_exists($sfmPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ServiceFeeManager not available']);
    exit;
}

require_once $sfmPath;

$startTime = microtime(true);

try {
    // 📊 Get pending remittances
    $pending = ServiceFeeManager::getPendingRemittances();

    // 📋 Get minimum payout threshold from settings
    $thresholdStmt = $db->prepare("
        SELECT settingValue FROM tblSettings
        WHERE settingKey = 'payment.remittance.min_payout'
        LIMIT 1
    ");
    $thresholdStmt->execute();
    $thresholdResult = $thresholdStmt->get_result()->fetch_assoc();
    $minPayout = (float)($thresholdResult['settingValue'] ?? 50.00);

    // 📋 Get auto-process setting
    $autoStmt = $db->prepare("
        SELECT settingValue FROM tblSettings
        WHERE settingKey = 'payment.remittance.auto_process'
        LIMIT 1
    ");
    $autoStmt->execute();
    $autoResult = $autoStmt->get_result()->fetch_assoc();
    $autoProcess = ($autoResult['settingValue'] ?? '0') === '1';

    $processed  = 0;
    $succeeded  = 0;
    $failed     = 0;
    $skipped    = 0;
    $results    = [];

    if ($autoProcess && !empty($pending)) {
        foreach ($pending as $remittance) {
            // 🚫 Skip remittances below minimum threshold
            if ((float)$remittance['amount'] < $minPayout) {
                $skipped++;
                continue;
            }

            $processed++;

            try {
                // 💸 Process the remittance
                // Uses system user ID 0 as the processor (automated)
                $result = ServiceFeeManager::processRemittance(
                    (int)$remittance['remittanceID'],
                    0 // 🤖 System/automated processor
                );

                if ($result['success'] ?? false) {
                    $succeeded++;
                } else {
                    $failed++;
                }

                $results[] = [
                    'remittanceID' => $remittance['remittanceID'],
                    'partnerID'    => $remittance['partnerID'] ?? null,
                    'amount'       => $remittance['amount'],
                    'status'       => ($result['success'] ?? false) ? 'processed' : 'failed',
                ];

            } catch (\Exception $e) {
                $failed++;
                $results[] = [
                    'remittanceID' => $remittance['remittanceID'],
                    'status'       => 'error',
                    'error'        => $e->getMessage(),
                ];
            }
        }
    }

    // ⏱️ Calculate execution time
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    // ✅ Return processing summary
    echo json_encode([
        'success'       => true,
        'autoProcess'   => $autoProcess,
        'pendingCount'  => count($pending),
        'processed'     => $processed,
        'succeeded'     => $succeeded,
        'failed'        => $failed,
        'skipped'       => $skipped,
        'minPayout'     => $minPayout,
        'executionMs'   => $executionTime,
        'timestamp'     => date('Y-m-d H:i:s'),
    ]);

    // 📝 Log execution
    if (function_exists('logActivity') && $processed > 0) {
        logActivity(null, 'cron_remittance_executed', 'system', 'info',
            "Remittance cron processed {$processed} payouts ({$succeeded} succeeded, {$failed} failed, {$skipped} below threshold)",
            ['processed' => $processed, 'succeeded' => $succeeded, 'failed' => $failed, 'skipped' => $skipped]
        );
    }

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'message'   => 'Remittance cron execution failed',
        'timestamp' => date('Y-m-d H:i:s'),
    ]);

    if (function_exists('logActivity')) {
        logActivity(null, 'cron_remittance_error', 'system', 'error',
            'Remittance cron failed: ' . $e->getMessage(),
            ['exception' => $e->getMessage()]
        );
    }
}
