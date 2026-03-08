<?php
/**
 * ============================================================================
 * 📧 SIGNula - Email Queue Admin API Actions
 * ============================================================================
 *
 * AJAX endpoint for admin email queue management operations.
 *
 * Actions:
 *   - process_queue    — Manually trigger queue processing
 *   - retry_failed     — Re-queue all failed emails for retry
 *   - cancel_email     — Cancel a specific pending email
 *   - get_queue_stats  — Get current queue statistics
 *   - cleanup_old      — Clean up old sent/failed emails
 *
 * Authentication: Super admin required
 * Method: POST (except get_queue_stats which accepts GET)
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Admin\API
 * @version    1.0.0
 * @see        web/private_html/email/EmailQueueProcessor.php
 * @see        web/private_html/email/EmailService.php
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 🛡️ SIGNULA INIT GUARD & CONFIG
// ============================================================================
if (!defined('SIGNULA_INIT')) {
    define('SIGNULA_INIT', true);
}

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📋 Set JSON response header
header('Content-Type: application/json; charset=utf-8');

// ============================================================================
// 🔐 AUTHENTICATION — Super Admin Required
// ============================================================================
if (!Auth::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

// 🔍 Verify super admin role
$adminUser = Auth::getCurrentUser();
if (empty($adminUser['isSuperAdmin']) || $adminUser['isSuperAdmin'] != 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Super admin access required.']);
    exit;
}

// ============================================================================
// 📋 GET ACTION PARAMETER
// ============================================================================
$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = SecurityUtils::sanitizeString($input['action'] ?? $_POST['action'] ?? '');
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = SecurityUtils::sanitizeString($_GET['action'] ?? '');
}

if (empty($action)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing action parameter.']);
    exit;
}

// ============================================================================
// 📂 LOAD EMAIL QUEUE PROCESSOR
// ============================================================================
if (!class_exists('EmailQueueProcessor')) {
    $processorPath = dirname(__DIR__, 3)
        . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'email'
        . DIRECTORY_SEPARATOR . 'EmailQueueProcessor.php';

    if (file_exists($processorPath)) {
        require_once $processorPath;
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Email queue processor not available.']);
        exit;
    }
}

// ============================================================================
// 🚀 ROUTE ACTION
// ============================================================================
try {
    switch ($action) {

        // =====================================================================
        // 📤 PROCESS QUEUE — Manually trigger email queue processing
        // =====================================================================
        case 'process_queue':
            $batchSize = (int) ($input['batch_size'] ?? 50);
            $batchSize = max(1, min($batchSize, 200)); // 🔒 Clamp to 1–200

            $processor = new EmailQueueProcessor(verbose: false);
            $stats = $processor->process($batchSize);

            jsonResponse(true, 'Queue processed successfully.', $stats);
            break;

        // =====================================================================
        // 🔄 RETRY FAILED — Re-queue all failed emails
        // =====================================================================
        case 'retry_failed':
            $maxRetries = (int) ($input['max_retries'] ?? 3);
            $maxRetries = max(1, min($maxRetries, 10)); // 🔒 Clamp to 1–10

            $result = EmailQueueProcessor::retryFailed($maxRetries);

            jsonResponse(true, "Re-queued {$result['requeued']} failed email(s).", $result);
            break;

        // =====================================================================
        // ❌ CANCEL EMAIL — Cancel a specific pending email
        // =====================================================================
        case 'cancel_email':
            $queueID = (int) ($input['queue_id'] ?? 0);

            if ($queueID <= 0) {
                jsonResponse(false, 'Invalid queue ID.', null, 400);
                break;
            }

            $cancelled = EmailQueueProcessor::cancelEmail($queueID);

            if ($cancelled) {
                // 📝 Log cancellation
                if (class_exists('ActivityLogger')) {
                    ActivityLogger::log(
                        userID: Auth::getCurrentUserID(),
                        activityType: 'email_queue_cancel',
                        activityResult: 'success',
                        activityDetails: "Admin cancelled queued email #{$queueID}"
                    );
                }
                jsonResponse(true, "Email #{$queueID} cancelled.");
            } else {
                jsonResponse(false, 'Could not cancel email. It may already be processing, sent, or failed.');
            }
            break;

        // =====================================================================
        // 📊 GET QUEUE STATS — Return current queue statistics
        // =====================================================================
        case 'get_queue_stats':
            $stats = EmailQueueProcessor::getQueueStatistics();
            jsonResponse(true, 'Queue statistics retrieved.', $stats);
            break;

        // =====================================================================
        // 🧹 CLEANUP OLD — Remove old sent/failed emails
        // =====================================================================
        case 'cleanup_old':
            $daysToKeep = (int) ($input['days_to_keep'] ?? 0);
            $daysToKeep = ($daysToKeep > 0) ? $daysToKeep : null; // null = use setting

            $deleted = EmailQueueProcessor::cleanupOldEmails($daysToKeep);

            jsonResponse(true, "Cleaned up {$deleted} old email(s).", ['deleted' => $deleted]);
            break;

        // =====================================================================
        // ❓ UNKNOWN ACTION
        // =====================================================================
        default:
            jsonResponse(false, 'Unknown action: ' . htmlspecialchars($action), null, 400);
            break;
    }

} catch (Exception $e) {
    // ============================================================================
    // ❌ GLOBAL ERROR HANDLER
    // ============================================================================
    if (class_exists('ErrorLogger')) {
        ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
    }

    jsonResponse(false, 'An error occurred while processing the request.', null, 500);
}
