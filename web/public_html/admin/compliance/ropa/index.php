<?php
/**
 * ============================================================================
 * 📋 SIGNula - RoPA (Records of Processing Activities) Admin CRUD
 * ============================================================================
 * (G-004 Layer 4b §5.2 — the FINAL compliance slice, completes G-004)
 *
 * Purpose: Super-Admin CRUD screen over `tblProcessingActivities` (migration
 *          044). This register ships with ZERO seeded rows — every
 *          purpose/lawfulBasis/dataCategories/recipients/retentionPeriod
 *          value is operator/DPO-authored. This page provides STRUCTURE +
 *          CRUD only; it never pre-fills or fabricates a value for any of
 *          those columns — a new activity's optional fields all start
 *          completely empty, and an existing activity's fields show EXACTLY
 *          what an operator has already saved.
 *
 * Design notes (mirrors admin/compliance/regimes/index.php exactly):
 * - The LIST + DETAIL VIEW are server-rendered from a plain GET query
 *   parameter (`?activityID=`) — usable with JavaScript disabled for
 *   VIEWING. Only the MUTATING actions (create/update/delete) are AJAX.
 * - JSON-array columns (dataCategories, dataSubjectCategories, recipients,
 *   transfersOutsideRegion, regimeCodes) are edited as one-item-per-line
 *   textareas for operator friendliness — JS splits on newlines, trims, and
 *   drops blank lines before POSTing an array; ropa-actions.php's
 *   ropaEncodeJsonArray() does the same normalisation server-side as a
 *   defence-in-depth backstop for any caller that bypasses this UI.
 *
 * Database Table: tblProcessingActivities
 * API Endpoint:   ../api/ropa-actions.php
 * Authentication: Super Admin only (AccessControl::requireSuperAdmin())
 *
 * PHP Version: 8.3+
 *
 * @see web/private_html/compliance/RegimeResolver.php (regimeCodes column is a soft link to tblComplianceRegimes.regimeCode)
 * @see web/public_html/admin/compliance/api/ropa-actions.php
 * @see web/public_html/admin/compliance/regimes/index.php — sibling admin page this mirrors
 * @see _database/migrations/044_breach_ropa_agegate.sql
 * @see .dev-team/specs/G-004.md §5.2
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\Compliance\RoPA
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// ============================================================================
// 🛡️ SIGNULA_INIT GUARD — Prevent direct access
// ============================================================================

// ⚠️ PATH DEPTH: __DIR__ here is web/public_html/admin/compliance/ropa — FOUR
// levels below web/ (public_html -> admin -> compliance -> ropa), the SAME
// depth as the sibling admin/compliance/regimes/index.php — dirname(__DIR__, 4).
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

// 🛡️ Super Admin gate — the RoPA register is higher-sensitivity than
// ordinary app data (spec §6: "all admin compliance UI/endpoints gate via
// AccessControl; require super-admin"). die()s with 403 internally if not
// satisfied.
$accessControl->requireSuperAdmin();

// 🛡️ Clickjacking defence on this compliance-data admin surface (spec §6 /
// house lesson #29) — every admin compliance page/endpoint sets this.
header('X-Frame-Options: DENY');

// ============================================================================
// 🔧 DISPLAY HELPERS
// ============================================================================

/**
 * 🔧 Decode A `tblProcessingActivities` JSON TEXT Column Into A Newline-Joined String For Textarea Display
 *
 * @param mixed $value Raw column value (JSON string or already-decoded array)
 * @return string
 */
function ropaJsonToLines(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $decoded = is_array($value) ? $value : json_decode((string) $value, true);
    if (!is_array($decoded)) {
        return '';
    }

    return implode("\n", array_map('strval', $decoded));
}

// ============================================================================
// 📋 LIST — every processing activity
// ============================================================================

$activities = Database::fetchAll("SELECT * FROM tblProcessingActivities ORDER BY name ASC", [], '');

$totalCount = count($activities);
$activeCount = 0;
foreach ($activities as $activityRow) {
    if (!empty($activityRow['isActive'])) {
        $activeCount++;
    }
}

// ============================================================================
// 🔍 DETAIL VIEW — server-rendered from ?activityID=N (no JS required to view)
// ============================================================================

$detailActivity = null;
$viewActivityID = (int) ($_GET['activityID'] ?? 0);

if ($viewActivityID > 0) {
    $detailActivity = Database::fetchOne("SELECT * FROM tblProcessingActivities WHERE activityID = ?", [$viewActivityID], 'i');
}

$csrfToken = SecurityUtils::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Records of Processing Activities - SIGNula Admin</title>

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
            background: linear-gradient(135deg, #4361ee, #4895ef);
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
        .table-header { background: linear-gradient(135deg, #4361ee, #4895ef); color: white; padding: 1rem 1.5rem; }
        .toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 9999; }
        .filter-card { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 1.25rem; margin-bottom: 1.5rem; }
    </style>
</head>
<body>

<div class="toast-container" id="alertContainer"></div>

<div class="container-fluid py-4">

    <div class="page-header">
        <a href="/admin" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
            <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
        </a>
        <h1 class="mb-2"><i class="fas fa-clipboard-list me-2"></i>Records of Processing Activities</h1>
        <p class="mb-0 opacity-75">Maintain your RoPA register. Every purpose, lawful basis, recipient list, and retention period below is entered by you (or your DPO/legal team) — nothing here is auto-generated.</p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-number text-primary"><?php echo (int) $totalCount; ?></div>
                <div class="stat-label"><i class="fas fa-list me-1"></i>Total Activities</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-number text-success"><?php echo (int) $activeCount; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle me-1"></i>Active</div>
            </div>
        </div>
    </div>

    <!-- ➕ ADD NEW ACTIVITY -->
    <div class="filter-card">
        <h5 class="mb-3"><i class="fas fa-plus me-2"></i>Add New Processing Activity</h5>
        <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="newActivityKey" class="form-label small fw-bold">Activity Key</label>
                <input type="text" class="form-control" id="newActivityKey" maxlength="64" placeholder="e.g. user_account_management">
                <div class="form-text">Stable short key. Letters, digits, underscore, hyphen, dot. Immutable once created.</div>
            </div>
            <div class="col-md-5">
                <label for="newActivityName" class="form-label small fw-bold">Name</label>
                <input type="text" class="form-control" id="newActivityName" maxlength="255" placeholder="e.g. User Account Management">
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-primary w-100" onclick="createActivity()">
                    <i class="fas fa-plus me-1"></i>Create
                </button>
            </div>
        </div>
        <div class="form-text mt-2">A new activity ships with every other field blank — nothing is pre-filled.</div>
    </div>

    <!-- 📋 ACTIVITIES TABLE -->
    <div class="card table-card mb-4">
        <div class="table-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Processing Activities</h5>
            <span class="badge bg-light text-dark"><?php echo (int) $totalCount; ?> total</span>
        </div>
        <div class="card-body">
            <?php if (empty($activities)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-clipboard-list fa-3x mb-3"></i>
                <p>No processing activities recorded yet.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Key</th>
                            <th>Name</th>
                            <th>Lawful Basis</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activities as $row): ?>
                        <?php $rowActivityID = (int) $row['activityID']; ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars((string) $row['activityKey'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($row['lawfulBasis'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span class="badge bg-<?php echo !empty($row['isActive']) ? 'success' : 'secondary'; ?>">
                                    <?php echo !empty($row['isActive']) ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="?activityID=<?php echo $rowActivityID; ?>#detail">
                                    <i class="fas fa-pen-to-square me-1"></i>Edit
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteActivity(<?php echo $rowActivityID; ?>)">
                                    <i class="fas fa-trash me-1"></i>Delete
                                </button>
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
    <?php if ($viewActivityID > 0): ?>
    <div id="detail" class="card table-card mb-4">
        <div class="table-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-folder-open me-2"></i>Activity #<?php echo $viewActivityID; ?></h5>
        </div>
        <div class="card-body">
            <?php if (!$detailActivity): ?>
            <div class="alert alert-warning mb-0"><i class="fas fa-triangle-exclamation me-2"></i>Processing activity not found.</div>
            <?php else: ?>
            <input type="hidden" id="detailActivityID" value="<?php echo $viewActivityID; ?>">

            <div class="d-flex align-items-center gap-2 mb-3">
                <code class="fs-6"><?php echo htmlspecialchars((string) $detailActivity['activityKey'], ENT_QUOTES, 'UTF-8'); ?></code>
                <span class="badge bg-<?php echo !empty($detailActivity['isActive']) ? 'success' : 'secondary'; ?>">
                    <?php echo !empty($detailActivity['isActive']) ? 'Active' : 'Inactive'; ?>
                </span>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <label for="editName" class="form-label small fw-bold">Name</label>
                    <input type="text" class="form-control" id="editName" maxlength="255"
                           value="<?php echo htmlspecialchars((string) $detailActivity['name'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="editIsActive" <?php echo !empty($detailActivity['isActive']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="editIsActive">Active</label>
                    </div>
                </div>
                <div class="col-md-12">
                    <label for="editPurpose" class="form-label small fw-bold">Purpose</label>
                    <textarea class="form-control" id="editPurpose" rows="3" placeholder="Entered by you/DPO — never auto-generated"><?php echo htmlspecialchars((string) ($detailActivity['purpose'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label for="editLawfulBasis" class="form-label small fw-bold">Lawful Basis</label>
                    <input type="text" class="form-control" id="editLawfulBasis" maxlength="128"
                           value="<?php echo htmlspecialchars((string) ($detailActivity['lawfulBasis'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-6">
                    <label for="editRetentionPeriod" class="form-label small fw-bold">Retention Period</label>
                    <input type="text" class="form-control" id="editRetentionPeriod" maxlength="128" placeholder="e.g. 7 years after account closure"
                           value="<?php echo htmlspecialchars((string) ($detailActivity['retentionPeriod'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-6">
                    <label for="editDataCategories" class="form-label small fw-bold">Data Categories <small class="text-muted">(one per line)</small></label>
                    <textarea class="form-control" id="editDataCategories" rows="3"><?php echo htmlspecialchars(ropaJsonToLines($detailActivity['dataCategories'] ?? null), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label for="editDataSubjectCategories" class="form-label small fw-bold">Data Subject Categories <small class="text-muted">(one per line)</small></label>
                    <textarea class="form-control" id="editDataSubjectCategories" rows="3"><?php echo htmlspecialchars(ropaJsonToLines($detailActivity['dataSubjectCategories'] ?? null), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label for="editRecipients" class="form-label small fw-bold">Recipients / Third-Party Processors <small class="text-muted">(one per line)</small></label>
                    <textarea class="form-control" id="editRecipients" rows="3"><?php echo htmlspecialchars(ropaJsonToLines($detailActivity['recipients'] ?? null), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label for="editTransfersOutsideRegion" class="form-label small fw-bold">Transfers Outside Region <small class="text-muted">(one per line)</small></label>
                    <textarea class="form-control" id="editTransfersOutsideRegion" rows="3"><?php echo htmlspecialchars(ropaJsonToLines($detailActivity['transfersOutsideRegion'] ?? null), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label for="editRegimeCodes" class="form-label small fw-bold">Governing Regime Codes <small class="text-muted">(one per line, e.g. GDPR)</small></label>
                    <textarea class="form-control" id="editRegimeCodes" rows="3"><?php echo htmlspecialchars(ropaJsonToLines($detailActivity['regimeCodes'] ?? null), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="col-12">
                    <button type="button" class="btn btn-primary" onclick="saveActivity()">
                        <i class="fas fa-floppy-disk me-1"></i>Save
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
    const ropaApiEndpoint = '../api/ropa-actions.php';

    function ropaShowAlert(message, type) {
        type = type || 'info';
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `${ropaEscapeHtml(message)}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
        document.getElementById('alertContainer').appendChild(alert);
        setTimeout(() => alert.remove(), 6000);
    }

    function ropaEscapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    function ropaGetCsrfToken() {
        const el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    function ropaLinesToArray(textareaID) {
        const el = document.getElementById(textareaID);
        if (!el) { return []; }
        return el.value.split('\n').map(s => s.trim()).filter(s => s.length > 0);
    }

    async function ropaPostAction(action, payload, successMessage) {
        try {
            const response = await fetch(ropaApiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.assign({ action: action, csrf_token: ropaGetCsrfToken() }, payload)),
            });
            const result = await response.json();

            if (result.success) {
                ropaShowAlert(successMessage, 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                ropaShowAlert(result.error || result.message || 'Action failed.', 'danger');
            }
        } catch (error) {
            console.error('RoPA admin action failed:', error);
            ropaShowAlert('Network error — please try again.', 'danger');
        }
    }

    async function createActivity() {
        const activityKey = document.getElementById('newActivityKey').value.trim();
        const name = document.getElementById('newActivityName').value.trim();

        if (!activityKey || !name) {
            ropaShowAlert('activityKey and name are required.', 'warning');
            return;
        }

        await ropaPostAction('create', { activityKey: activityKey, name: name }, 'Processing activity created.');
    }

    function ropaGetDetailActivityID() {
        const el = document.getElementById('detailActivityID');
        return el ? parseInt(el.value, 10) : 0;
    }

    async function saveActivity() {
        const activityID = ropaGetDetailActivityID();

        const payload = {
            activityID: activityID,
            name: document.getElementById('editName').value.trim(),
            isActive: document.getElementById('editIsActive').checked ? 1 : 0,
            purpose: document.getElementById('editPurpose').value,
            lawfulBasis: document.getElementById('editLawfulBasis').value.trim(),
            retentionPeriod: document.getElementById('editRetentionPeriod').value.trim(),
            dataCategories: ropaLinesToArray('editDataCategories'),
            dataSubjectCategories: ropaLinesToArray('editDataSubjectCategories'),
            recipients: ropaLinesToArray('editRecipients'),
            transfersOutsideRegion: ropaLinesToArray('editTransfersOutsideRegion'),
            regimeCodes: ropaLinesToArray('editRegimeCodes'),
        };

        await ropaPostAction('update', payload, 'Processing activity saved.');
    }

    async function deleteActivity(activityID) {
        if (!confirm('Delete this processing activity? This cannot be undone.')) {
            return;
        }
        await ropaPostAction('delete', { activityID: activityID }, 'Processing activity deleted.');
    }
</script>

</body>
</html>
