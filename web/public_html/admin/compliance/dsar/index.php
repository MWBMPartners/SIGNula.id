<?php
/**
 * ============================================================================
 * 📋 SIGNula - Data Subject Request (DSAR) Admin Queue (G-004 Layer 1 / Cycle C)
 * ============================================================================
 *
 * Purpose: Super-Admin dashboard for triaging + fulfilling data-subject
 *          requests tracked by DSARManager (web/private_html/compliance/
 *          DSARManager.php). Lists the queue (filterable by status/requestType/
 *          overdue, bounded pagination), shows a per-request detail view with
 *          its full tblDataSubjectRequestEvents audit timeline, and exposes
 *          fulfil/transition controls wired to the AJAX endpoint at
 *          ../api/dsar-actions.php.
 *
 * Design notes:
 * - The LIST + FILTERS + DETAIL VIEW are ALL server-rendered from plain GET
 *   query parameters — this page is fully usable with JavaScript disabled
 *   (per the project brief's "AJAX should not be a blocker" requirement).
 *   Only the MUTATING actions (transition / fulfil / start verification) are
 *   AJAX, mirroring the established admin-page convention (see
 *   admin/payments/tiers.php).
 * - Password-gated fulfilment (portability/access/erasure) is DELIBERATELY
 *   NOT wired to a one-click "fulfil" button here — see dsar-actions.php's
 *   docblock for why an admin cannot supply the subject's password, and how
 *   this page instead offers a "mark awaiting the subject" tracking action.
 *
 * Database Tables: tblDataSubjectRequests, tblDataSubjectRequestEvents
 * API Endpoint:    ../api/dsar-actions.php
 * Authentication:  Super Admin only (AccessControl::requireSuperAdmin())
 *
 * PHP Version: 8.3+
 *
 * @see web/private_html/compliance/DSARManager.php
 * @see web/public_html/admin/compliance/api/dsar-actions.php
 * @see web/public_html/admin/payments/tiers.php — sibling admin page this mirrors
 * @see _database/migrations/040_consent_and_dsar.sql
 * @see .dev-team/specs/G-004.md §2.6, §6
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\Compliance\DSAR
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// ============================================================================
// 🛡️ SIGNULA_INIT GUARD — Prevent direct access
// ============================================================================

// ⚙️ Main configuration (defines SIGNULA_INIT constant, DB credentials, etc.)
// ⚠️ PATH DEPTH: __DIR__ here is web/public_html/admin/compliance/dsar — FOUR
// levels below web/ (public_html -> admin -> compliance -> dsar), so this
// page needs dirname(__DIR__, 4), NOT the dirname(__DIR__, 3) that shallower
// sibling pages like admin/payments/tiers.php use (that page is only THREE
// levels below web/). Verified at build time — see the build report for the
// literal resolved path proof.
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton (MySQLi prepared statements)
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🎫 Session manager (authentication state)
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🔐 Access control (role-based permissions)
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// ============================================================================
// 🔐 AUTHENTICATION & AUTHORIZATION
// ============================================================================

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check — user must be logged in
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin gate — compliance data is higher-sensitivity than ordinary
// app data (spec §6: "all admin compliance UI/endpoints gate via AccessControl;
// require super-admin"). die()s with 403 internally if not satisfied.
$accessControl->requireSuperAdmin();

// 🛡️ Clickjacking defence on this compliance-data admin surface (spec §6 /
// house lesson #29) — every admin compliance page/endpoint sets this.
header('X-Frame-Options: DENY');

// ============================================================================
// 📚 LOAD DSARManager (NOT covered by config.php's autoloader dir list)
// ============================================================================
if (!class_exists('DSARManager')) {
    require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'DSARManager.php';
}

// ============================================================================
// 🔎 FILTERS + BOUNDED PAGINATION — GET-driven, works without JavaScript
// ============================================================================

/**
 * ✅ Display-only allowlists mirroring DSARManager's own private consts
 * (VALID_STATUSES / VALID_REQUEST_TYPES) — duplicated here ONLY for building
 * <select> options and validating incoming GET filter values before they
 * reach getQueue(); DSARManager::getQueue() itself is what actually enforces
 * safe SQL construction (these are a UI-layer allowlist, defence in depth).
 */
$STATUS_OPTIONS = [
    'submitted', 'identity_pending', 'verified', 'in_progress', 'awaiting_user',
    'fulfilled', 'rejected', 'partially_fulfilled', 'expired', 'withdrawn',
];
$REQUEST_TYPE_OPTIONS = [
    'access', 'portability', 'erasure', 'rectification', 'restriction',
    'object', 'do_not_sell', 'withdraw_consent', 'automated_decision_optout',
];
$TERMINAL_STATUSES = ['fulfilled', 'rejected', 'partially_fulfilled', 'expired', 'withdrawn'];
$PASSWORD_GATED_TYPES = ['access', 'portability', 'erasure'];

$filterStatus = SecurityUtils::sanitizeString((string) ($_GET['status'] ?? ''));
if (!in_array($filterStatus, $STATUS_OPTIONS, true)) {
    $filterStatus = '';
}

$filterType = SecurityUtils::sanitizeString((string) ($_GET['requestType'] ?? ''));
if (!in_array($filterType, $REQUEST_TYPE_OPTIONS, true)) {
    $filterType = '';
}

$filterOverdue = !empty($_GET['overdue']);

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$filters = [];
if ($filterStatus !== '') {
    $filters['status'] = $filterStatus;
}
if ($filterType !== '') {
    $filters['requestType'] = $filterType;
}
if ($filterOverdue) {
    $filters['overdue'] = true;
}

// 📋 getQueue() itself clamps $limit to [1, MAX_QUEUE_LIMIT] and floors
// $offset at 0 — no unbounded LIMIT/OFFSET risk here (spec §2.7 item 7).
$queue = DSARManager::getQueue($filters, $limit, $offset);
$hasNextPage = count($queue) === $limit;

// 📊 Compliance summary panel (last 30 days) — reuses getComplianceReport()
// rather than hand-rolling separate summary queries.
$reportFrom = date('Y-m-d', strtotime('-30 days'));
$reportTo = date('Y-m-d');
$report = DSARManager::getComplianceReport($reportFrom, $reportTo);
$newInWindow = 0;
foreach ($report['countsByTypeAndStatus'] as $row) {
    $newInWindow += (int) $row['total'];
}

// ============================================================================
// 🔍 DETAIL VIEW — server-rendered from ?requestID=N (no JS required to view)
// ============================================================================
$detailRequest = null;
$detailEvents = [];
$viewRequestID = (int) ($_GET['requestID'] ?? 0);

if ($viewRequestID > 0) {
    $detailRequest = Database::fetchOne(
        "SELECT * FROM tblDataSubjectRequests WHERE requestID = ?",
        [$viewRequestID],
        'i'
    );

    if ($detailRequest) {
        $detailEvents = Database::fetchAll(
            "SELECT * FROM tblDataSubjectRequestEvents WHERE requestID = ? ORDER BY createdAt ASC, eventID ASC",
            [$viewRequestID],
            'i'
        );
    }
}

$csrfToken = SecurityUtils::generateCSRFToken();

/**
 * 🔧 Format A Packed VARBINARY(16) IP Column For Display
 *
 * tblDataSubjectRequests.ipAddress is stored as packed bytes via
 * inet_pton() (spec §6: "PII minimisation... IPs stored packed"). Never echo
 * the raw bytes — always inet_ntop() first, guarding the documented false
 * return for a non-IP-shaped value.
 *
 * @param string|null $packed Raw column value
 * @return string Human-readable IP, or an em-dash placeholder
 *
 * @see https://www.php.net/manual/en/function.inet-ntop.php
 */
function dsarQueueFormatIP(?string $packed): string
{
    if (empty($packed)) {
        return '—';
    }

    $ip = @inet_ntop($packed);

    return $ip !== false ? $ip : '—';
}

/**
 * 🔧 Build A Bootstrap Badge Class For A DSAR Status
 *
 * @param string $status
 * @return string Bootstrap colour class suffix
 */
function dsarQueueStatusBadgeClass(string $status): string
{
    return match ($status) {
        'fulfilled' => 'success',
        'partially_fulfilled' => 'info',
        'rejected', 'expired' => 'danger',
        'withdrawn' => 'secondary',
        'awaiting_user' => 'warning',
        'verified', 'in_progress' => 'primary',
        default => 'secondary', // submitted, identity_pending
    };
}

// 🔗 Preserve current filters across pagination/detail links.
$queryBase = [];
if ($filterStatus !== '') {
    $queryBase['status'] = $filterStatus;
}
if ($filterType !== '') {
    $queryBase['requestType'] = $filterType;
}
if ($filterOverdue) {
    $queryBase['overdue'] = '1';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Data Subject Requests - SIGNula Admin</title>

    <!-- 🎨 Bootstrap 5.3.2 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- 🎨 FontAwesome 6.4.2 -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <style>
        body { background-color: #f8f9fa; }
        .page-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            text-align: center;
        }
        .stat-card .stat-number { font-size: 1.9rem; font-weight: 700; line-height: 1; }
        .stat-card .stat-label { color: #6c757d; font-size: 0.82rem; margin-top: 0.4rem; }
        .table-card { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
        .table-header { background: linear-gradient(135deg, #11998e, #38ef7d); color: white; padding: 1rem 1.5rem; }
        .row-overdue { background-color: #fff3f3 !important; }
        .badge-overdue { animation: pulse-overdue 2s infinite; }
        @keyframes pulse-overdue { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        .toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 9999; }
        .timeline-item { border-left: 3px solid #11998e; padding: 0.5rem 0 0.5rem 1rem; margin-bottom: 0.5rem; }
        .filter-card { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 1.25rem; margin-bottom: 1.5rem; }
    </style>
</head>
<body>

<div class="toast-container" id="alertContainer"></div>

<div class="container-fluid py-4">

    <!-- ==================================================================== -->
    <!-- 🎨 PAGE HEADER -->
    <!-- ==================================================================== -->
    <div class="page-header">
        <a href="/admin" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
            <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
        </a>
        <h1 class="mb-2"><i class="fas fa-user-shield me-2"></i>Data Subject Requests</h1>
        <p class="mb-0 opacity-75">Triage and fulfil data-subject access requests (GDPR Art.12-22 and equivalents).</p>
    </div>

    <!-- ==================================================================== -->
    <!-- 📊 SUMMARY STATS (last 30 days) -->
    <!-- ==================================================================== -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-number text-danger"><?php echo (int) count($report['overdue']); ?></div>
                <div class="stat-label"><i class="fas fa-exclamation-triangle me-1"></i>Overdue Now</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-number text-primary"><?php echo (int) $newInWindow; ?></div>
                <div class="stat-label"><i class="fas fa-inbox me-1"></i>Requests (30d)</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-number text-success"><?php echo (int) $report['totalClosed']; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle me-1"></i>Closed (30d)</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-number text-info">
                    <?php echo $report['slaMetPercent'] !== null ? htmlspecialchars((string) $report['slaMetPercent'], ENT_QUOTES, 'UTF-8') . '%' : '—'; ?>
                </div>
                <div class="stat-label"><i class="fas fa-stopwatch me-1"></i>SLA Met (30d)</div>
            </div>
        </div>
    </div>

    <!-- ==================================================================== -->
    <!-- 🔎 FILTER BAR (GET form — works without JS) -->
    <!-- ==================================================================== -->
    <div class="filter-card">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="filterStatus" class="form-label fw-bold">Status</label>
                <select class="form-select" id="filterStatus" name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($STATUS_OPTIONS as $statusOption): ?>
                    <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo $filterStatus === $statusOption ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $statusOption)), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="filterType" class="form-label fw-bold">Request Type</label>
                <select class="form-select" id="filterType" name="requestType">
                    <option value="">All types</option>
                    <?php foreach ($REQUEST_TYPE_OPTIONS as $typeOption): ?>
                    <option value="<?php echo htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo $filterType === $typeOption ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $typeOption)), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="filterOverdue" name="overdue" value="1"
                           <?php echo $filterOverdue ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="filterOverdue">Overdue only</label>
                </div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-success"><i class="fas fa-filter me-1"></i>Apply Filters</button>
                <a href="?" class="btn btn-outline-secondary"><i class="fas fa-rotate-left me-1"></i>Reset</a>
            </div>
        </form>
    </div>

    <!-- ==================================================================== -->
    <!-- 📋 QUEUE TABLE -->
    <!-- ==================================================================== -->
    <div class="card table-card mb-4">
        <div class="table-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Queue</h5>
            <span class="badge bg-light text-dark"><?php echo count($queue); ?> shown</span>
        </div>
        <div class="card-body">
            <?php if (empty($queue)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-inbox fa-3x mb-3"></i>
                <p>No requests match the current filters.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Due Date</th>
                            <th>Requester</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($queue as $row): ?>
                        <?php
                            $rowRequestID = (int) $row['requestID'];
                            $rowStatus = (string) $row['status'];
                            $rowIsOverdue = !empty($row['dueDate'])
                                && strtotime((string) $row['dueDate']) < strtotime(date('Y-m-d'))
                                && !in_array($rowStatus, $TERMINAL_STATUSES, true);
                            $detailQuery = array_merge($queryBase, ['requestID' => $rowRequestID]);
                        ?>
                        <tr class="<?php echo $rowIsOverdue ? 'row-overdue' : ''; ?>">
                            <td>#<?php echo $rowRequestID; ?></td>
                            <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $row['requestType'])), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span class="badge bg-<?php echo dsarQueueStatusBadgeClass($rowStatus); ?>">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $rowStatus)), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($rowIsOverdue): ?>
                                <span class="badge bg-danger badge-overdue"><i class="fas fa-exclamation-triangle me-1"></i>
                                    <?php echo htmlspecialchars((string) $row['dueDate'], ENT_QUOTES, 'UTF-8'); ?> (overdue)
                                </span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars((string) ($row['dueDate'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['requesterEmail'])): ?>
                                    <?php echo htmlspecialchars((string) $row['requesterEmail'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php elseif (!empty($row['userID'])): ?>
                                    User #<?php echo (int) $row['userID']; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo htmlspecialchars((string) $row['submittedAt'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="?<?php echo htmlspecialchars(http_build_query($detailQuery), ENT_QUOTES, 'UTF-8'); ?>#detail">
                                    <i class="fas fa-eye me-1"></i>View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- 📄 Pagination -->
            <nav class="d-flex justify-content-between align-items-center mt-3">
                <?php
                    $prevQuery = array_merge($queryBase, ['page' => max(1, $page - 1)]);
                    $nextQuery = array_merge($queryBase, ['page' => $page + 1]);
                ?>
                <a class="btn btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>"
                   href="?<?php echo htmlspecialchars(http_build_query($prevQuery), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fas fa-chevron-left me-1"></i>Previous
                </a>
                <span class="text-muted">Page <?php echo (int) $page; ?></span>
                <a class="btn btn-outline-secondary <?php echo !$hasNextPage ? 'disabled' : ''; ?>"
                   href="?<?php echo htmlspecialchars(http_build_query($nextQuery), ENT_QUOTES, 'UTF-8'); ?>">
                    Next<i class="fas fa-chevron-right ms-1"></i>
                </a>
            </nav>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================================================================== -->
    <!-- 🔍 DETAIL VIEW -->
    <!-- ==================================================================== -->
    <?php if ($viewRequestID > 0): ?>
    <div id="detail" class="card table-card mb-4">
        <div class="table-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-folder-open me-2"></i>Request #<?php echo $viewRequestID; ?></h5>
        </div>
        <div class="card-body">
            <?php if (!$detailRequest): ?>
            <div class="alert alert-warning mb-0"><i class="fas fa-triangle-exclamation me-2"></i>Request not found.</div>
            <?php else: ?>
            <?php
                $dStatus = (string) $detailRequest['status'];
                $dType = (string) $detailRequest['requestType'];
                $isPasswordGated = in_array($dType, $PASSWORD_GATED_TYPES, true);
            ?>
            <div class="row mb-4">
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr><th style="width:40%">Type</th><td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $dType)), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                        <tr><th>Status</th><td><span class="badge bg-<?php echo dsarQueueStatusBadgeClass($dStatus); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $dStatus)), ENT_QUOTES, 'UTF-8'); ?></span></td></tr>
                        <tr><th>Due Date</th><td><?php echo htmlspecialchars((string) ($detailRequest['dueDate'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                        <tr><th>User ID</th><td><?php echo $detailRequest['userID'] !== null ? (int) $detailRequest['userID'] : '<span class="text-muted">unlinked</span>'; ?></td></tr>
                        <tr><th>Requester Email</th><td><?php echo htmlspecialchars((string) ($detailRequest['requesterEmail'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr><th style="width:40%">Submitted</th><td><?php echo htmlspecialchars((string) $detailRequest['submittedAt'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                        <tr><th>Verified At</th><td><?php echo htmlspecialchars((string) ($detailRequest['verifiedAt'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                        <tr><th>Fulfilled At</th><td><?php echo htmlspecialchars((string) ($detailRequest['fulfilledAt'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                        <tr><th>IP Address</th><td><?php echo htmlspecialchars(dsarQueueFormatIP($detailRequest['ipAddress'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                        <tr><th>Resolution Note</th><td><?php echo nl2br(htmlspecialchars((string) ($detailRequest['resolutionNote'] ?? '—'), ENT_QUOTES, 'UTF-8')); ?></td></tr>
                    </table>
                </div>
            </div>

            <?php if (!empty($detailRequest['details'])): ?>
            <div class="alert alert-secondary">
                <strong>Request details (as submitted):</strong><br>
                <?php echo nl2br(htmlspecialchars((string) $detailRequest['details'], ENT_QUOTES, 'UTF-8')); ?>
            </div>
            <?php endif; ?>

            <!-- 🕒 EVENT TIMELINE -->
            <h6 class="mt-4 mb-3"><i class="fas fa-timeline me-2"></i>Audit Timeline</h6>
            <?php if (empty($detailEvents)): ?>
            <p class="text-muted">No events recorded.</p>
            <?php else: ?>
            <?php foreach ($detailEvents as $event): ?>
            <div class="timeline-item">
                <strong>
                    <?php echo $event['fromStatus'] !== null ? htmlspecialchars(ucwords(str_replace('_', ' ', (string) $event['fromStatus'])), ENT_QUOTES, 'UTF-8') . ' &rarr; ' : ''; ?>
                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $event['toStatus'])), ENT_QUOTES, 'UTF-8'); ?>
                </strong>
                <span class="text-muted small">
                    by <?php echo htmlspecialchars((string) $event['actorType'], ENT_QUOTES, 'UTF-8'); ?><?php echo $event['actorID'] !== null ? ' #' . (int) $event['actorID'] : ''; ?>
                    on <?php echo htmlspecialchars((string) $event['createdAt'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php if (!empty($event['note'])): ?>
                <div><?php echo nl2br(htmlspecialchars((string) $event['note'], ENT_QUOTES, 'UTF-8')); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- ⚙️ ACTIONS -->
            <h6 class="mt-4 mb-3"><i class="fas fa-gears me-2"></i>Actions</h6>
            <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" id="detailRequestID" value="<?php echo $viewRequestID; ?>">

            <?php if (in_array($dStatus, ['submitted', 'identity_pending'], true)): ?>
            <button type="button" class="btn btn-outline-primary mb-3" onclick="startVerification()">
                <i class="fas fa-envelope-circle-check me-1"></i>Send / Re-send Identity Verification Email
            </button>
            <?php endif; ?>

            <?php if ($isPasswordGated): ?>
            <div class="alert alert-info">
                <i class="fas fa-circle-info me-2"></i>
                <strong><?php echo htmlspecialchars(ucwords($dType), ENT_QUOTES, 'UTF-8'); ?> requests are password-gated.</strong>
                Export and erasure are verified against the data subject's OWN password inside
                <code>AccountManager</code> — an admin cannot supply it on their behalf. Once the subject's identity
                is verified, ask them to complete this from <em>Settings &rarr; Privacy</em>. Use the button below to
                mark this request as awaiting the subject, or use the transition control to track progress.
                <?php if (!in_array($dStatus, ['fulfilled', 'partially_fulfilled', 'rejected', 'expired', 'withdrawn', 'awaiting_user'], true)): ?>
                <div class="mt-2">
                    <button type="button" class="btn btn-sm btn-warning" onclick="markAwaitingSubject('<?php echo htmlspecialchars($dType, ENT_QUOTES, 'UTF-8'); ?>')">
                        <i class="fas fa-user-clock me-1"></i>Mark Awaiting Subject
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($dType === 'rectification' && !in_array($dStatus, $TERMINAL_STATUSES, true)): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="card-title">Apply Rectification</h6>
                    <p class="text-muted small">Only allowlisted fields may be corrected. Leave a field blank to skip it.</p>
                    <div class="row g-2">
                        <div class="col-md-6"><input type="text" class="form-control form-control-sm" id="rectFirstName" placeholder="First name"></div>
                        <div class="col-md-6"><input type="text" class="form-control form-control-sm" id="rectLastName" placeholder="Last name"></div>
                        <div class="col-md-6"><input type="text" class="form-control form-control-sm" id="rectDisplayName" placeholder="Display name"></div>
                        <div class="col-md-6"><input type="text" class="form-control form-control-sm" id="rectPhoneNumber" placeholder="Phone number"></div>
                    </div>
                    <button type="button" class="btn btn-sm btn-success mt-2" onclick="applyRectification()">
                        <i class="fas fa-check me-1"></i>Apply Rectification
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Change Status</h6>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label for="transitionTarget" class="form-label small">New status</label>
                            <select class="form-select form-select-sm" id="transitionTarget">
                                <?php foreach ($STATUS_OPTIONS as $statusOption): ?>
                                <?php if ($statusOption === $dStatus) { continue; } ?>
                                <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $statusOption)), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="transitionNote" class="form-label small">Note (optional)</label>
                            <input type="text" class="form-control form-control-sm" id="transitionNote" maxlength="500">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-sm btn-primary w-100" onclick="applyTransition()">
                                <i class="fas fa-arrow-right-arrow-left me-1"></i>Apply
                            </button>
                        </div>
                    </div>
                    <div class="form-text">The server enforces the legal DSAR lifecycle — an illegal transition is rejected, not silently applied.</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.container-fluid -->

<script>
    // ============================================================================
    // 🔧 CONFIGURATION
    // ============================================================================
    const apiEndpoint = '../api/dsar-actions.php';

    // ============================================================================
    // 🔔 ALERT HELPER (mirrors admin/payments/tiers.php's showAlert())
    // ============================================================================
    function showAlert(message, type) {
        type = type || 'info';
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `${escapeHtml(message)}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
        document.getElementById('alertContainer').appendChild(alert);
        setTimeout(() => alert.remove(), 6000);
    }

    // 🛡️ HTML-escape utility (mirrors admin/payments/tiers.php's escapeHtml())
    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    function getCsrfToken() {
        const el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    function getDetailRequestID() {
        const el = document.getElementById('detailRequestID');
        return el ? parseInt(el.value, 10) : 0;
    }

    // ============================================================================
    // 🔄 TRANSITION
    // ============================================================================
    async function applyTransition() {
        const requestID = getDetailRequestID();
        const toStatus = document.getElementById('transitionTarget').value;
        const note = document.getElementById('transitionNote').value;

        await postAction('transition', { requestID: requestID, toStatus: toStatus, note: note }, 'Status updated.');
    }

    // ============================================================================
    // 🔐 IDENTITY VERIFICATION
    // ============================================================================
    async function startVerification() {
        const requestID = getDetailRequestID();
        await postAction('start_identity_verification', { requestID: requestID }, 'Verification email sent.');
    }

    // ============================================================================
    // ✏️ RECTIFICATION
    // ============================================================================
    async function applyRectification() {
        const requestID = getDetailRequestID();
        const approvedChanges = {};
        const fields = {
            rectFirstName: 'firstName',
            rectLastName: 'lastName',
            rectDisplayName: 'displayName',
            rectPhoneNumber: 'phoneNumber',
        };
        for (const [elementID, column] of Object.entries(fields)) {
            const value = document.getElementById(elementID).value.trim();
            if (value !== '') {
                approvedChanges[column] = value;
            }
        }
        if (Object.keys(approvedChanges).length === 0) {
            showAlert('Enter at least one field to rectify.', 'warning');
            return;
        }

        await postAction('fulfil_rectification', { requestID: requestID, approvedChanges: approvedChanges }, 'Rectification applied.');
    }

    // ============================================================================
    // 🔒 PASSWORD-GATED TYPES — track only, never fabricate a password
    // ============================================================================
    async function markAwaitingSubject(requestType) {
        const requestID = getDetailRequestID();
        const actionName = 'fulfil_' + requestType; // fulfil_access | fulfil_portability | fulfil_erasure
        await postAction(actionName, { requestID: requestID }, 'Marked as awaiting the subject.');
    }

    // ============================================================================
    // 📡 SHARED POST HELPER
    // ============================================================================
    async function postAction(action, payload, successMessage) {
        try {
            const response = await fetch(apiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.assign({ action: action, csrf_token: getCsrfToken() }, payload)),
            });
            const result = await response.json();

            if (result.success) {
                showAlert(successMessage, 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                showAlert(result.error || result.message || 'Action failed.', 'danger');
            }
        } catch (error) {
            console.error('DSAR admin action failed:', error);
            showAlert('Network error — please try again.', 'danger');
        }
    }
</script>

</body>
</html>
