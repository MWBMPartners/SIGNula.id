<?php
/**
 * ============================================================================
 * 🚨 SIGNula - Breach Notification Admin (G-004 Layer 4b §5.1)
 * ============================================================================
 * (the FINAL compliance slice, completes G-004)
 *
 * Purpose: Super-Admin screen to open/assess breach incidents, compute their
 *          per-regime notification deadlines, and mark notifications sent.
 *          This page — and the API it calls — is MACHINERY ONLY: it never
 *          auto-files anything with a regulator or writes notification
 *          copy. Every `summary`/`remediation`/filing `note` field is
 *          operator/DPO-authored, shown/edited EXACTLY as saved, never
 *          pre-filled or fabricated.
 *
 * Design notes (mirrors admin/compliance/regimes/index.php exactly):
 * - LIST + DETAIL VIEW are server-rendered from `?incidentID=` — usable with
 *   JavaScript disabled for VIEWING. Only mutating actions are AJAX.
 * - "Compute Deadlines" calls `BreachManager::computeNotificationDeadlines()`,
 *   which reads `RegimeResolver::breachWindowHours()` per regime — this page
 *   never computes or displays a hardcoded window itself; a NULL `dueAt` in
 *   the notifications table means the regime has no configured window yet
 *   (surfaced verbatim, not disguised).
 *
 * Database Tables: tblBreachIncidents, tblBreachNotifications
 * API Endpoint:    ../api/breach-actions.php
 * Authentication:  Super Admin only (AccessControl::requireSuperAdmin())
 *
 * PHP Version: 8.3+
 *
 * @see web/private_html/compliance/BreachManager.php
 * @see web/public_html/admin/compliance/api/breach-actions.php
 * @see web/public_html/admin/compliance/regimes/index.php — sibling admin page this mirrors
 * @see _database/migrations/044_breach_ropa_agegate.sql
 * @see .dev-team/specs/G-004.md §5.1
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\Compliance\Breaches
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// ============================================================================
// 🛡️ SIGNULA_INIT GUARD — Prevent direct access
// ============================================================================

// ⚠️ PATH DEPTH: __DIR__ here is web/public_html/admin/compliance/breaches —
// FOUR levels below web/ (public_html -> admin -> compliance -> breaches),
// the SAME depth as the sibling admin/compliance/regimes/index.php —
// dirname(__DIR__, 4) reaches web/.
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// ============================================================================
// 🔐 AUTHENTICATION & AUTHORIZATION
// ============================================================================

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin gate — breach data is higher-sensitivity than ordinary app
// data (spec §6). die()s with 403 internally if not satisfied.
$accessControl->requireSuperAdmin();

// 🛡️ Clickjacking defence on this compliance-data admin surface (spec §6 /
// house lesson #29) — every admin compliance page/endpoint sets this.
header('X-Frame-Options: DENY');

// ============================================================================
// 🎨 DISPLAY HELPER
// ============================================================================

/**
 * 🎨 Build A Bootstrap Badge Class For An Incident's Severity
 *
 * @param string $severity
 * @return string
 */
function breachSeverityBadgeClass(string $severity): string
{
    return match ($severity) {
        'critical' => 'danger',
        'high'     => 'warning',
        'medium'   => 'info',
        default    => 'secondary', // 'low'
    };
}

// ============================================================================
// 📋 LIST — every incident
// ============================================================================

$incidents = Database::fetchAll("SELECT * FROM tblBreachIncidents ORDER BY detectedAt DESC", [], '');

$totalCount = count($incidents);
$openCount = 0;
foreach ($incidents as $incidentRow) {
    if ($incidentRow['status'] !== 'closed') {
        $openCount++;
    }
}

$overdueCountRow = Database::fetchOne(
    "SELECT COUNT(*) AS cnt FROM tblBreachNotifications WHERE status = 'pending' AND dueAt IS NOT NULL AND dueAt < NOW()",
    [],
    ''
);
$overdueCount = (int) ($overdueCountRow['cnt'] ?? 0);

// ============================================================================
// 🔍 DETAIL VIEW — server-rendered from ?incidentID=N (no JS required to view)
// ============================================================================

$detailIncident = null;
$detailNotifications = [];
$viewIncidentID = (int) ($_GET['incidentID'] ?? 0);

if ($viewIncidentID > 0) {
    $detailIncident = Database::fetchOne("SELECT * FROM tblBreachIncidents WHERE incidentID = ?", [$viewIncidentID], 'i');
    if ($detailIncident) {
        $detailNotifications = Database::fetchAll(
            "SELECT * FROM tblBreachNotifications WHERE incidentID = ? ORDER BY (dueAt IS NULL) ASC, dueAt ASC",
            [$viewIncidentID],
            'i'
        );
    }
}

$SEVERITY_OPTIONS = ['low', 'medium', 'high', 'critical'];
$STATUS_OPTIONS = ['detected', 'assessing', 'contained', 'notifying', 'closed'];

$csrfToken = SecurityUtils::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Breach Notifications - SIGNula Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <style>
        body { background-color: #f8f9fa; }
        .page-header {
            background: linear-gradient(135deg, #d1004b, #ff6b6b);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .stat-card { background: white; border-radius: 10px; padding: 1.25rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); text-align: center; }
        .stat-card .stat-number { font-size: 1.9rem; font-weight: 700; line-height: 1; }
        .stat-card .stat-label { color: #6c757d; font-size: 0.82rem; margin-top: 0.4rem; }
        .table-card { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
        .table-header { background: linear-gradient(135deg, #d1004b, #ff6b6b); color: white; padding: 1rem 1.5rem; }
        .toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 9999; }
        .filter-card { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 1.25rem; margin-bottom: 1.5rem; }
        .row-overdue { background-color: #fff0f0 !important; }
    </style>
</head>
<body>

<div class="toast-container" id="alertContainer"></div>

<div class="container-fluid py-4">

    <div class="page-header">
        <a href="/admin" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
            <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
        </a>
        <h1 class="mb-2"><i class="fas fa-triangle-exclamation me-2"></i>Breach Notifications</h1>
        <p class="mb-0 opacity-75">Track incidents and their per-regime notification deadlines. This tool computes deadlines and tracks status only — it never files anything with a regulator and never writes notification copy for you.</p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-number text-primary"><?php echo (int) $totalCount; ?></div>
                <div class="stat-label"><i class="fas fa-list me-1"></i>Total Incidents</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-number text-warning"><?php echo (int) $openCount; ?></div>
                <div class="stat-label"><i class="fas fa-folder-open me-1"></i>Open</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-number text-danger"><?php echo (int) $overdueCount; ?></div>
                <div class="stat-label"><i class="fas fa-clock me-1"></i>Overdue Notifications</div>
            </div>
        </div>
    </div>

    <!-- 🚨 OPEN NEW INCIDENT -->
    <div class="filter-card">
        <h5 class="mb-3"><i class="fas fa-plus me-2"></i>Open New Incident</h5>
        <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="row g-3">
            <div class="col-md-3">
                <label for="newSeverity" class="form-label small fw-bold">Severity</label>
                <select class="form-select" id="newSeverity">
                    <?php foreach ($SEVERITY_OPTIONS as $sev): ?>
                    <option value="<?php echo htmlspecialchars($sev, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $sev === 'medium' ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($sev), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="newDetectedAt" class="form-label small fw-bold">Detected At</label>
                <input type="datetime-local" class="form-control" id="newDetectedAt">
                <div class="form-text">Leave blank for now.</div>
            </div>
            <div class="col-md-6">
                <label for="newDiscoveredBy" class="form-label small fw-bold">Discovered By</label>
                <input type="text" class="form-control" id="newDiscoveredBy" maxlength="255" placeholder="e.g. automated alert, staff report">
            </div>
            <div class="col-md-6">
                <label for="newRegimeCodes" class="form-label small fw-bold">Governing Regime Codes <small class="text-muted">(comma-separated, e.g. GDPR, CCPA)</small></label>
                <input type="text" class="form-control" id="newRegimeCodes" placeholder="GDPR, CCPA">
            </div>
            <div class="col-md-6">
                <label for="newSummary" class="form-label small fw-bold">Summary</label>
                <textarea class="form-control" id="newSummary" rows="2" placeholder="Entered by you/DPO — never auto-generated"></textarea>
            </div>
            <div class="col-12">
                <button type="button" class="btn btn-danger" onclick="openIncident()">
                    <i class="fas fa-triangle-exclamation me-1"></i>Open Incident
                </button>
            </div>
        </div>
    </div>

    <!-- 📋 INCIDENTS TABLE -->
    <div class="card table-card mb-4">
        <div class="table-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Incidents</h5>
            <span class="badge bg-light text-dark"><?php echo (int) $totalCount; ?> total</span>
        </div>
        <div class="card-body">
            <?php if (empty($incidents)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-shield-halved fa-3x mb-3"></i>
                <p>No breach incidents recorded.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Severity</th>
                            <th>Status</th>
                            <th>Detected At</th>
                            <th>Affected Users</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($incidents as $row): ?>
                        <?php $rowIncidentID = (int) $row['incidentID']; ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars((string) $row['reference'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><span class="badge bg-<?php echo breachSeverityBadgeClass((string) $row['severity']); ?>"><?php echo htmlspecialchars(ucfirst((string) $row['severity']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst((string) $row['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?php echo htmlspecialchars((string) $row['detectedAt'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $row['affectedUserCount'] !== null ? (int) $row['affectedUserCount'] : '<span class="text-muted">—</span>'; ?></td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="?incidentID=<?php echo $rowIncidentID; ?>#detail">
                                    <i class="fas fa-eye me-1"></i>View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 🔍 DETAIL VIEW -->
    <?php if ($viewIncidentID > 0): ?>
    <div id="detail" class="card table-card mb-4">
        <div class="table-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-folder-open me-2"></i>Incident #<?php echo $viewIncidentID; ?></h5>
        </div>
        <div class="card-body">
            <?php if (!$detailIncident): ?>
            <div class="alert alert-warning mb-0"><i class="fas fa-triangle-exclamation me-2"></i>Breach incident not found.</div>
            <?php else: ?>
            <input type="hidden" id="detailIncidentID" value="<?php echo $viewIncidentID; ?>">

            <div class="d-flex align-items-center gap-2 mb-3">
                <code class="fs-6"><?php echo htmlspecialchars((string) $detailIncident['reference'], ENT_QUOTES, 'UTF-8'); ?></code>
                <span class="badge bg-<?php echo breachSeverityBadgeClass((string) $detailIncident['severity']); ?> fs-6"><?php echo htmlspecialchars(ucfirst((string) $detailIncident['severity']), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label for="editSeverity" class="form-label small fw-bold">Severity</label>
                    <select class="form-select" id="editSeverity">
                        <?php foreach ($SEVERITY_OPTIONS as $sev): ?>
                        <option value="<?php echo htmlspecialchars($sev, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $sev === $detailIncident['severity'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($sev), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="editStatus" class="form-label small fw-bold">Status</label>
                    <select class="form-select" id="editStatus">
                        <?php foreach ($STATUS_OPTIONS as $st): ?>
                        <option value="<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $st === $detailIncident['status'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($st), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="editAffectedUserCount" class="form-label small fw-bold">Affected Users</label>
                    <input type="number" min="0" class="form-control" id="editAffectedUserCount"
                           value="<?php echo htmlspecialchars((string) ($detailIncident['affectedUserCount'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label for="editDiscoveredBy" class="form-label small fw-bold">Discovered By</label>
                    <input type="text" class="form-control" id="editDiscoveredBy" maxlength="255"
                           value="<?php echo htmlspecialchars((string) ($detailIncident['discoveredBy'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-6">
                    <label for="editRegimeCodes" class="form-label small fw-bold">Governing Regime Codes <small class="text-muted">(comma-separated)</small></label>
                    <input type="text" class="form-control" id="editRegimeCodes"
                           value="<?php
                                $regimeCodesArr = json_decode((string) ($detailIncident['regimeCodes'] ?? '[]'), true);
                                echo htmlspecialchars(is_array($regimeCodesArr) ? implode(', ', $regimeCodesArr) : '', ENT_QUOTES, 'UTF-8');
                           ?>">
                </div>
                <div class="col-md-12">
                    <label for="editSummary" class="form-label small fw-bold">Summary</label>
                    <textarea class="form-control" id="editSummary" rows="3"><?php echo htmlspecialchars((string) ($detailIncident['summary'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="col-md-12">
                    <label for="editRemediation" class="form-label small fw-bold">Remediation</label>
                    <textarea class="form-control" id="editRemediation" rows="3"><?php echo htmlspecialchars((string) ($detailIncident['remediation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="button" class="btn btn-primary" onclick="assessIncident()">
                        <i class="fas fa-floppy-disk me-1"></i>Save
                    </button>
                    <button type="button" class="btn btn-outline-dark" onclick="computeDeadlines()">
                        <i class="fas fa-clock me-1"></i>Compute Notification Deadlines
                    </button>
                </div>
            </div>

            <!-- 📨 NOTIFICATIONS -->
            <h6 class="mb-3"><i class="fas fa-envelope me-2"></i>Notification Obligations</h6>
            <?php if (empty($detailNotifications)): ?>
            <p class="text-muted small">No notification rows yet — add regime codes above and click "Compute Notification Deadlines".</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Regime</th><th>Audience</th><th>Due At</th><th>Status</th><th>Note</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($detailNotifications as $notify): ?>
                    <?php
                        $isOverdue = $notify['status'] === 'pending' && $notify['dueAt'] !== null && strtotime((string) $notify['dueAt']) < time();
                    ?>
                    <tr class="<?php echo $isOverdue ? 'row-overdue' : ''; ?>">
                        <td><code><?php echo htmlspecialchars((string) ($notify['regimeCode'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></code></td>
                        <td><?php echo htmlspecialchars(str_replace('_', ' ', (string) $notify['audience']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($notify['dueAt'] ?? 'Not configured'), ENT_QUOTES, 'UTF-8'); ?><?php echo $isOverdue ? ' <span class="badge bg-danger">Overdue</span>' : ''; ?></td>
                        <td><span class="badge bg-<?php echo $notify['status'] === 'sent' ? 'success' : ($notify['status'] === 'not_required' ? 'secondary' : 'warning'); ?>"><?php echo htmlspecialchars((string) $notify['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td class="small text-muted"><?php echo htmlspecialchars((string) ($notify['note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if ($notify['status'] === 'pending'): ?>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="markNotified(<?php echo (int) $notify['notifyID']; ?>)">
                                <i class="fas fa-check me-1"></i>Mark Sent
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
    const breachApiEndpoint = '../api/breach-actions.php';

    function breachShowAlert(message, type) {
        type = type || 'info';
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `${breachEscapeHtml(message)}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
        document.getElementById('alertContainer').appendChild(alert);
        setTimeout(() => alert.remove(), 6000);
    }

    function breachEscapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    function breachGetCsrfToken() {
        const el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    function breachCommaListToArray(value) {
        return value.split(',').map(s => s.trim().toUpperCase()).filter(s => s.length > 0);
    }

    async function breachPostAction(action, payload, successMessage) {
        try {
            const response = await fetch(breachApiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.assign({ action: action, csrf_token: breachGetCsrfToken() }, payload)),
            });
            const result = await response.json();

            if (result.success) {
                breachShowAlert(successMessage, 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                breachShowAlert(result.error || result.message || 'Action failed.', 'danger');
            }
        } catch (error) {
            console.error('Breach admin action failed:', error);
            breachShowAlert('Network error — please try again.', 'danger');
        }
    }

    async function openIncident() {
        const severity = document.getElementById('newSeverity').value;
        const detectedAtRaw = document.getElementById('newDetectedAt').value;
        const discoveredBy = document.getElementById('newDiscoveredBy').value.trim();
        const regimeCodes = breachCommaListToArray(document.getElementById('newRegimeCodes').value);
        const summary = document.getElementById('newSummary').value;

        const payload = {
            severity: severity,
            discoveredBy: discoveredBy,
            regimeCodes: regimeCodes,
            summary: summary,
        };
        if (detectedAtRaw) {
            // datetime-local -> 'Y-m-d H:i:s'
            payload.detectedAt = detectedAtRaw.replace('T', ' ') + ':00';
        }

        await breachPostAction('open', payload, 'Incident opened.');
    }

    function breachGetDetailIncidentID() {
        const el = document.getElementById('detailIncidentID');
        return el ? parseInt(el.value, 10) : 0;
    }

    async function assessIncident() {
        const incidentID = breachGetDetailIncidentID();

        const payload = {
            incidentID: incidentID,
            severity: document.getElementById('editSeverity').value,
            status: document.getElementById('editStatus').value,
            affectedUserCount: document.getElementById('editAffectedUserCount').value,
            discoveredBy: document.getElementById('editDiscoveredBy').value.trim(),
            regimeCodes: breachCommaListToArray(document.getElementById('editRegimeCodes').value),
            summary: document.getElementById('editSummary').value,
            remediation: document.getElementById('editRemediation').value,
        };

        await breachPostAction('assess', payload, 'Incident updated.');
    }

    async function computeDeadlines() {
        const incidentID = breachGetDetailIncidentID();
        await breachPostAction('compute_deadlines', { incidentID: incidentID }, 'Notification deadlines computed.');
    }

    async function markNotified(notifyID) {
        const note = prompt('Optional filing note (e.g. "filed via ICO portal, ref #...")') || '';
        await breachPostAction('mark_notified', { notifyID: notifyID, note: note }, 'Notification marked sent.');
    }
</script>

</body>
</html>
