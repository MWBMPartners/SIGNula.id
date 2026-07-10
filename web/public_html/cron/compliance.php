<?php
/**
 * ============================================================================
 * ⏰ SIGNula - Compliance Cron Endpoint (G-004 Layer 4a §5.3)
 * ============================================================================
 *
 * Purpose: Web-accessible cron endpoint for automated privacy/compliance
 *          housekeeping. Called by an external cron service (e.g.
 *          cron-job.org, UptimeRobot) hitting this HTTPS endpoint on a
 *          schedule (recommended: hourly), mirroring cron/billing.php's
 *          Tier-2 architecture exactly.
 *
 * Tasks Processed (each wrapped so one failure never aborts the others):
 *   1. AccountManager::processScheduledDeletions() — THE GAP-CLOSER. This
 *      method has existed since the GDPR erasure work but NOTHING in the
 *      codebase ever called it — a user's grace-period-expired account
 *      deletion never actually executed. This cron is the first caller.
 *   2. AccountManager::cleanupExpiredExports() — THE OTHER GAP-CLOSER. Same
 *      situation: expired GDPR data-export files/records were never purged.
 *   3. Expire overdue DSAR identity-verification tokens (best-effort,
 *      delegates to DSARManager::transition() — never a raw duplicate UPDATE).
 *   4. RetentionManager::runDuePolicies() — G-004 Layer 4a retention/auto-purge
 *      (see that class for the full allowlist + dry-run/disabled safety contract).
 *   5. Best-effort admin notification when overdue DSARs exist.
 *
 * Authentication:
 *   Secured via a secret token stored in tblSettings (key:
 *   compliance.cron.secret_token). Unlike payment.cron.secret_token (which
 *   cron/billing.php reads via a raw `SELECT settingValue`), this setting is
 *   `isSensitive = 1` (encrypted at rest — migration 043) — it is therefore
 *   read via `getSetting()`, which decrypts it during `loadSettings()`. A raw
 *   SELECT here would compare the caller's token against AES ciphertext and
 *   always fail. The token must be passed as a GET parameter (?token=xxx) or
 *   in the Authorization header (Bearer xxx) — identical extraction shape to
 *   cron/billing.php.
 *
 * Path depth: this file lives at web/public_html/cron/, exactly like
 * cron/billing.php — `dirname(__DIR__, 2)` therefore resolves to web/,
 * identically to that sibling file.
 *
 * @see web/private_html/auth/AccountManager.php — processScheduledDeletions()/cleanupExpiredExports()
 * @see web/private_html/compliance/DSARManager.php — DSAR state machine
 * @see web/private_html/compliance/RetentionManager.php — retention/auto-purge engine
 * @see web/public_html/cron/billing.php — the sibling cron endpoint this mirrors
 * @see _database/migrations/043_retention_policies.sql — tblRetentionPolicies + this file's settings
 * @see .dev-team/specs/G-004.md §5.3
 *
 * PHP Version: 8.3+
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Cron
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// ============================================================================
// 🔐 PURE TOKEN HELPERS — declared BEFORE the live bootstrap below, with no
// dependency on config.php/Database/session state, so they are directly
// testable (mirrors regime-actions.php::regimeCanActivate()'s "declare pure
// logic first, gate the live path behind a test-seam constant" pattern —
// see web/public_html/admin/compliance/api/regime-actions.php's own docblock
// for the precedent this follows).
// ============================================================================

if (!function_exists('complianceCronExtractProvidedToken')) {
    /**
     * 🔑 Extract The Caller-Supplied Cron Token
     *
     * EXACT extraction shape cron/billing.php uses: prefer `?token=`, fall
     * back to an `Authorization: Bearer <token>` header.
     *
     * @param array<string,mixed> $get    Typically $_GET
     * @param array<string,mixed> $server Typically $_SERVER
     * @return string The provided token, or '' if none was supplied
     */
    function complianceCronExtractProvidedToken(array $get, array $server): string
    {
        $providedToken = (string) ($get['token'] ?? '');

        if ($providedToken === '') {
            $authHeader = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            if (str_starts_with($authHeader, 'Bearer ')) {
                $providedToken = substr($authHeader, 7);
            }
        }

        return $providedToken;
    }
}

if (!function_exists('complianceCronTokenIsValid')) {
    /**
     * 🔒 Timing-Safe Token Comparison — Fails Closed
     *
     * @param string $expectedToken The DECRYPTED value read via getSetting()
     * @param string $providedToken The caller-supplied token
     * @return bool True only if a non-empty expected token exactly matches
     *              (constant-time) the provided token.
     * @see https://www.php.net/manual/en/function.hash-equals.php
     */
    function complianceCronTokenIsValid(string $expectedToken, string $providedToken): bool
    {
        if ($expectedToken === '') {
            // 🛡️ An unconfigured token must never "match" an empty provided
            // token — fail closed rather than treat "nothing vs nothing" as valid.
            return false;
        }

        return hash_equals($expectedToken, $providedToken);
    }
}

// ============================================================================
// 🧪 TEST SEAM
// ============================================================================
// Production requests never define this constant — it is not derived from
// any request input (GET/POST/header/cookie), so this branch is inert
// outside a deliberate test process. A test defines
// SIGNULA_CRON_COMPLIANCE_TEST_ONLY BEFORE require()-ing this exact file to
// load the two pure functions above without running the live bootstrap
// (config.php / Database / session / real settings load) that follows.
// ============================================================================
if (defined('SIGNULA_CRON_COMPLIANCE_TEST_ONLY')) {
    return;
}

// ============================================================================
// 🚀 BOOTSTRAP — Load core system (identical shape to cron/billing.php)
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

// 🚫 Only allow GET/POST (cron services typically use GET)
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ============================================================================
// 🔐 AUTHENTICATION — Verify cron secret token
// ============================================================================
//
// compliance.cron.secret_token is isSensitive=1 (encrypted at rest — migration
// 043). getSetting() returns the DECRYPTED value (loadSettings() decrypts every
// isSensitive row before caching it in $GLOBALS['settings']) — a raw
// `SELECT settingValue FROM tblSettings` here would return AES ciphertext and
// NEVER equal a caller's plaintext token. This is the deliberate divergence
// from cron/billing.php (whose payment.cron.secret_token is NOT sensitive).
$expectedToken = (string) getSetting('compliance.cron.secret_token', '');

// 🚫 If no token configured, cron is disabled — ships this way until an
// operator explicitly sets a strong random value via the Settings UI.
if ($expectedToken === '') {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Compliance cron not configured. Set compliance.cron.secret_token in settings.',
    ]);
    exit;
}

$providedToken = complianceCronExtractProvidedToken($_GET, $_SERVER);

// 🔒 Constant-time comparison to prevent timing attacks.
if (!complianceCronTokenIsValid($expectedToken, $providedToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing cron token']);

    // 📝 Log the failed attempt — NOT reachable/usable by a normal web user
    // without the token, but every attempt is still auditable.
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            null,
            'cron_auth_failed',
            'security',
            'warning',
            'Failed compliance cron authentication attempt from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '']
        );
    }

    exit;
}

// ============================================================================
// 📚 LOAD DEPENDENCIES — web/private_html/compliance/ and .../notifications/
// are NOT covered by config.php's spl_autoload_register() directory list
// (confirmed against DSARManager.php's/ConsentManager.php's identical note:
// only auth/security/utils/api/api-controllers/email/database autoload).
// AccountManager (under auth/) DOES autoload — required explicitly anyway,
// guarded, matching this file's belt-and-braces posture for every other
// dependency below.
// ============================================================================

$complianceDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'compliance';
$authDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth';
$notificationsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'notifications';

if (!class_exists('AccountManager')) {
    $accountManagerPath = $authDir . DIRECTORY_SEPARATOR . 'AccountManager.php';
    if (file_exists($accountManagerPath)) {
        require_once $accountManagerPath;
    }
}

if (!class_exists('InvalidDSARTransitionException')) {
    $exceptionPath = $complianceDir . DIRECTORY_SEPARATOR . 'InvalidDSARTransitionException.php';
    if (file_exists($exceptionPath)) {
        require_once $exceptionPath;
    }
}

if (!class_exists('DSARManager')) {
    $dsarManagerPath = $complianceDir . DIRECTORY_SEPARATOR . 'DSARManager.php';
    if (file_exists($dsarManagerPath)) {
        require_once $dsarManagerPath;
    }
}

if (!class_exists('RetentionManager')) {
    $retentionManagerPath = $complianceDir . DIRECTORY_SEPARATOR . 'RetentionManager.php';
    if (file_exists($retentionManagerPath)) {
        require_once $retentionManagerPath;
    }
}

if (!class_exists('NotificationService')) {
    $notificationServicePath = $notificationsDir . DIRECTORY_SEPARATOR . 'NotificationService.php';
    if (file_exists($notificationServicePath)) {
        require_once $notificationServicePath;
    }
}

// ============================================================================
// ⏰ PROCESS COMPLIANCE TASKS
// ============================================================================

$startTime = microtime(true);

$deletions = ['processed' => 0, 'deleted' => 0, 'errors' => 0];
$exportsCleaned = 0;
$dsarTokensExpired = 0;
$retention = [];
$adminsNotified = 0;

// ── 1. Scheduled account deletions — THE GAP-CLOSER ────────────────────────
try {
    if (class_exists('AccountManager')) {
        $deletions = AccountManager::processScheduledDeletions();
    }
} catch (\Throwable $e) {
    if (class_exists('ErrorLogger')) {
        ErrorLogger::logError('Exception', 'Compliance cron: processScheduledDeletions() failed: ' . $e->getMessage(), $e->getFile(), $e->getLine());
    }
}

// ── 2. Expired data-export cleanup — THE OTHER GAP-CLOSER ──────────────────
try {
    if (class_exists('AccountManager')) {
        $exportsCleaned = AccountManager::cleanupExpiredExports();
    }
} catch (\Throwable $e) {
    if (class_exists('ErrorLogger')) {
        ErrorLogger::logError('Exception', 'Compliance cron: cleanupExpiredExports() failed: ' . $e->getMessage(), $e->getFile(), $e->getLine());
    }
}

// ── 3. Expire overdue DSAR identity-verification tokens (best-effort) ──────
try {
    if (class_exists('DSARManager')) {
        $ttlMinutes = (int) getSetting('dsar.identity_token.ttl_minutes', 30);

        // 🔎 Bounded lookup: DSARs still 'identity_pending' whose MOST RECENT
        // identity_pending event is older than the TTL — the same TTL anchor
        // DSARManager::verifyIdentity() itself reads (there is no dedicated
        // tokenIssuedAt/expiresAt column on tblDataSubjectRequests).
        $overdueTokens = Database::fetchAll(
            "SELECT dsr.requestID
             FROM tblDataSubjectRequests dsr
             INNER JOIN (
                 SELECT requestID, MAX(createdAt) AS lastIssuedAt
                 FROM tblDataSubjectRequestEvents
                 WHERE toStatus = 'identity_pending'
                 GROUP BY requestID
             ) evt ON evt.requestID = dsr.requestID
             WHERE dsr.status = 'identity_pending'
               AND evt.lastIssuedAt < DATE_SUB(NOW(), INTERVAL ? MINUTE)
             LIMIT 200",
            [$ttlMinutes],
            'i'
        );

        foreach ($overdueTokens as $row) {
            try {
                // 🔄 Delegates to the guarded state machine — never a raw
                // duplicate UPDATE — so the transition is validated, logged,
                // and appended to tblDataSubjectRequestEvents exactly like
                // every other DSAR status change.
                DSARManager::transition(
                    (int) $row['requestID'],
                    'expired',
                    'cron',
                    null,
                    'Identity verification token expired (compliance cron auto-purge)'
                );
                $dsarTokensExpired++;
            } catch (\Throwable $e) {
                // 🛡️ One bad DSAR must never abort the rest of the batch.
                continue;
            }
        }
    }
} catch (\Throwable $e) {
    if (class_exists('ErrorLogger')) {
        ErrorLogger::logError('Exception', 'Compliance cron: DSAR identity-token expiry failed: ' . $e->getMessage(), $e->getFile(), $e->getLine());
    }
}

// ── 4. Retention / auto-purge policies (G-004 Layer 4a) ────────────────────
try {
    if (class_exists('RetentionManager')) {
        $retention = RetentionManager::runDuePolicies();
    }
} catch (\Throwable $e) {
    if (class_exists('ErrorLogger')) {
        ErrorLogger::logError('Exception', 'Compliance cron: RetentionManager::runDuePolicies() failed: ' . $e->getMessage(), $e->getFile(), $e->getLine());
    }
}

// ── 5. Best-effort admin notification for overdue DSARs ────────────────────
try {
    if (class_exists('DSARManager') && class_exists('NotificationService')) {
        $overdueDsars = DSARManager::getQueue(['overdue' => true], 50, 0);

        // 🛡️ Mirrors DSARManager::notifyAdminsOfNewRequest()'s
        // NOTIFICATION_SERVICE_VALID_TYPES_MIRROR guardrail exactly: this
        // ENUM is checked directly against NotificationService.php before
        // ever calling create(), so a future edit that removes 'system'
        // from NotificationService degrades this to a no-op instead of a
        // silently-swallowed create() failure. ActivityLogger's audit trail
        // (written throughout this cron regardless) remains the reliable
        // signal either way.
        $notificationServiceValidTypesMirror = ['security', 'account', 'system', 'billing', 'social'];
        $suitableType = 'system';

        if (!empty($overdueDsars) && in_array($suitableType, $notificationServiceValidTypesMirror, true)) {
            $admins = Database::fetchAll(
                "SELECT userID FROM tblUsers WHERE isSuperAdmin = 1 AND accountStatus = 'active'",
                [],
                ''
            );

            foreach ($admins as $admin) {
                try {
                    NotificationService::create(
                        (int) $admin['userID'],
                        $suitableType,
                        'Overdue Data Subject Requests',
                        count($overdueDsars) . ' DSAR(s) are past their SLA due date and need review.',
                        ['actionUrl' => '/admin/compliance/dsar', 'priority' => 'high']
                    );
                    $adminsNotified++;
                } catch (\Throwable $e) {
                    continue; // best-effort — one bad notification never blocks the rest
                }
            }
        }
    }
} catch (\Throwable $e) {
    // 🛡️ Notification is entirely best-effort — never let it affect the
    // cron's success response; ActivityLogger below still records the run.
}

// ============================================================================
// ✅ RESPOND
// ============================================================================

$executionTime = round((microtime(true) - $startTime) * 1000, 2);

echo json_encode([
    'success' => true,
    'data'    => [
        'deletions'         => $deletions,
        'exportsCleaned'    => $exportsCleaned,
        'dsarTokensExpired' => $dsarTokensExpired,
        'retention'         => $retention,
    ],
    'adminsNotified' => $adminsNotified,
    'executionMs'    => $executionTime,
    'timestamp'      => date('Y-m-d H:i:s'),
]);

// 📝 Log a run summary via ActivityLogger (best-effort).
if (class_exists('ActivityLogger')) {
    ActivityLogger::log(
        null,
        'cron_compliance_executed',
        'privacy',
        'info',
        "Compliance cron: {$deletions['deleted']} account(s) deleted, {$exportsCleaned} export(s) cleaned, "
            . "{$dsarTokensExpired} DSAR token(s) expired, " . count($retention) . " retention polic(y/ies) processed.",
        [
            'deletions'         => $deletions,
            'exportsCleaned'    => $exportsCleaned,
            'dsarTokensExpired' => $dsarTokensExpired,
            'retention'         => $retention,
            'adminsNotified'    => $adminsNotified,
            'executionMs'       => $executionTime,
        ]
    );
}
