<?php
/**
 * 🔐 Mass Credential Reset Interface (Super Admin)
 *
 * Provides a secure interface for Global Admins to trigger mass credential
 * reset operations in response to security breaches or policy changes.
 *
 * Features:
 * - Mass password invalidation across all or filtered users
 * - Global salt rotation with history tracking
 * - Full credential reset (salt + password) for breach response
 * - Real-time progress tracking via AJAX polling
 * - Compliance reporting (who has/hasn't reset their password)
 * - Operation history and audit trail
 *
 * @see https://getbootstrap.com/docs/5.3/ - Bootstrap 5.3 Documentation
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Credential_Stuffing_Prevention_Cheat_Sheet.html
 *
 * Copyright 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 🔌 Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin check
$accessControl->requireSuperAdmin();

// 📊 Get user counts for preview
$userCountStmt = $db->prepare("SELECT COUNT(*) as total FROM tblUsers WHERE accountStatus != 'deleted' AND passwordHash IS NOT NULL");
$userCountStmt->execute();
$totalEligibleUsers = (int)$userCountStmt->get_result()->fetch_assoc()['total'];

// 🔐 Generate CSRF token
$csrfToken = SecurityUtils::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mass Credential Reset - SIGNula Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    <style>
        body { background-color: #f8f9fa; }

        /* 🎨 Header with danger gradient for security context */
        .page-header {
            background: linear-gradient(135deg, #dc2626, #991b1b);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        /* 📊 Type cards */
        .reset-type-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            height: 100%;
        }
        .reset-type-card:hover { border-color: #2563eb; box-shadow: 0 4px 12px rgba(37,99,235,0.15); }
        .reset-type-card.selected { border-color: #dc2626; background-color: #fef2f2; box-shadow: 0 4px 12px rgba(220,38,38,0.2); }
        .reset-type-card .icon { font-size: 2rem; margin-bottom: 0.75rem; }

        /* 📊 Progress */
        .progress-container { display: none; }
        .progress-container.active { display: block; }
        .progress { height: 24px; border-radius: 12px; }
        .progress-bar { transition: width 0.3s ease; }

        /* 📋 History table */
        .history-badge { font-size: 0.75rem; }

        /* 🔒 Confirmation overlay */
        .confirmation-overlay {
            background: rgba(0,0,0,0.5);
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: 1050;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .confirmation-overlay.active { display: flex; }
    </style>
</head>
<body>

<!-- 🔝 Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="/admin/"><i class="fas fa-shield-alt me-2"></i>SIGNula Admin</a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="/admin/"><i class="fas fa-tachometer-alt me-1"></i>Dashboard</a>
            <a class="nav-link" href="/admin/users/"><i class="fas fa-users me-1"></i>Users</a>
            <a class="nav-link active" href="/admin/security/credential-reset.php"><i class="fas fa-key me-1"></i>Credential Reset</a>
        </div>
    </div>
</nav>

<div class="container py-4">

    <!-- 🔐 Page Header -->
    <div class="page-header">
        <div class="d-flex align-items-center">
            <i class="fas fa-shield-exclamation fa-2x me-3"></i>
            <div>
                <h2 class="mb-1"><i class="fas fa-key me-2"></i>Mass Credential Reset</h2>
                <p class="mb-0 opacity-75">Trigger mass password resets and salt rotations for security breach response</p>
            </div>
        </div>
    </div>

    <!-- ⚠️ Warning Banner -->
    <div class="alert alert-danger d-flex align-items-start mb-4" role="alert">
        <i class="fas fa-exclamation-triangle fa-lg me-3 mt-1"></i>
        <div>
            <strong>Critical Security Operation</strong>
            <p class="mb-0">Mass credential reset operations are irreversible. All affected users will be required to set new passwords and will receive email notifications. Active sessions will be invalidated.</p>
        </div>
    </div>

    <!-- 📊 User Count Info -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-primary mb-1"><?php echo number_format($totalEligibleUsers); ?></h3>
                    <small class="text-muted">Eligible Users (with passwords)</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 🎯 Step 1: Select Reset Type -->
    <div class="card mb-4" id="step1">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-1 text-muted me-2"></i>Select Reset Type</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <!-- 🔑 Mass Password Reset -->
                <div class="col-md-4">
                    <div class="reset-type-card" data-type="mass_password_reset" onclick="selectResetType(this)">
                        <div class="icon text-warning"><i class="fas fa-key"></i></div>
                        <h6>Mass Password Reset</h6>
                        <p class="text-muted small mb-0">Invalidate all user passwords and require new ones. Users receive email with reset link. Recommended for suspected credential leaks.</p>
                    </div>
                </div>
                <!-- 🔄 Salt Rotation -->
                <div class="col-md-4">
                    <div class="reset-type-card" data-type="salt_rotation" onclick="selectResetType(this)">
                        <div class="icon text-info"><i class="fas fa-sync-alt"></i></div>
                        <h6>Salt Rotation</h6>
                        <p class="text-muted small mb-0">Rotate global and per-user salt values. Changes password hashing without invalidating passwords. Proactive security measure.</p>
                    </div>
                </div>
                <!-- 🚨 Full Credential Reset -->
                <div class="col-md-4">
                    <div class="reset-type-card" data-type="full_credential_reset" onclick="selectResetType(this)">
                        <div class="icon text-danger"><i class="fas fa-radiation"></i></div>
                        <h6>Full Credential Reset</h6>
                        <p class="text-muted small mb-0">Salt rotation + password invalidation. Maximum security response for confirmed data breaches. All sessions terminated immediately.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 📝 Step 2: Configure Scope & Reason -->
    <div class="card mb-4" id="step2" style="display:none;">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-2 text-muted me-2"></i>Configure Reset</h5>
        </div>
        <div class="card-body">
            <!-- 🎯 Scope Selection -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Scope</label>
                <select class="form-select" id="resetScope" onchange="toggleScopeFilter()">
                    <option value="all_users">All Users (<?php echo number_format($totalEligibleUsers); ?> users)</option>
                    <option value="filtered">Filtered Users (by status/tier)</option>
                </select>
            </div>

            <!-- 🔍 Filter Options (shown when scope=filtered) -->
            <div id="filterOptions" style="display:none;" class="mb-3 p-3 bg-light rounded">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Account Status</label>
                        <select class="form-select" id="filterStatus">
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                            <option value="locked">Locked</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Account Tier</label>
                        <select class="form-select" id="filterTier">
                            <option value="">All Tiers</option>
                            <option value="free">Free</option>
                            <option value="basic">Basic</option>
                            <option value="premium">Premium</option>
                            <option value="enterprise">Enterprise</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- 📝 Reason -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                <textarea class="form-control" id="resetReason" rows="3" required
                          placeholder="Describe the reason for this credential reset (e.g., security breach detected, routine security policy, compromised database backup)..."></textarea>
                <div class="form-text">This reason will be included in the notification email sent to affected users.</div>
            </div>

            <!-- 🚀 Execute Button -->
            <div class="d-flex justify-content-between align-items-center">
                <button class="btn btn-outline-secondary" onclick="resetForm()">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </button>
                <button class="btn btn-danger btn-lg" id="executeBtn" onclick="showConfirmation()">
                    <i class="fas fa-exclamation-triangle me-2"></i>Initiate Credential Reset
                </button>
            </div>
        </div>
    </div>

    <!-- 📊 Step 3: Progress Tracking -->
    <div class="card mb-4 progress-container" id="progressCard">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-3 text-muted me-2"></i>Processing</h5>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <div class="d-flex justify-content-between mb-1">
                    <span id="progressLabel">Initialising...</span>
                    <span id="progressPercent">0%</span>
                </div>
                <div class="progress">
                    <div class="progress-bar bg-danger progress-bar-striped progress-bar-animated"
                         role="progressbar" id="progressBar" style="width: 0%"></div>
                </div>
            </div>
            <div class="row text-center g-3" id="progressStats">
                <div class="col-md-3">
                    <div class="border rounded p-2">
                        <h5 class="mb-0" id="statProcessed">0</h5>
                        <small class="text-muted">Processed</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-2">
                        <h5 class="mb-0" id="statRemaining">0</h5>
                        <small class="text-muted">Remaining</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-2">
                        <h5 class="mb-0 text-success" id="statEmailsSent">0</h5>
                        <small class="text-muted">Emails Sent</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-2">
                        <h5 class="mb-0 text-danger" id="statEmailsFailed">0</h5>
                        <small class="text-muted">Emails Failed</small>
                    </div>
                </div>
            </div>
            <div class="mt-3 text-center" id="progressActions" style="display:none;">
                <button class="btn btn-outline-danger me-2" onclick="cancelOperation()" id="cancelBtn">
                    <i class="fas fa-stop me-1"></i>Cancel Operation
                </button>
                <button class="btn btn-success" onclick="location.reload()" id="doneBtn" style="display:none;">
                    <i class="fas fa-check me-1"></i>Done
                </button>
            </div>
        </div>
    </div>

    <!-- 📜 Operation History -->
    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Reset History</h5>
            <button class="btn btn-sm btn-outline-secondary" onclick="loadHistory()">
                <i class="fas fa-refresh me-1"></i>Refresh
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Scope</th>
                            <th>Users</th>
                            <th>Emails</th>
                            <th>Status</th>
                            <th>Initiated By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody">
                        <tr><td colspan="8" class="text-center text-muted py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 🔒 Confirmation Modal -->
<div class="confirmation-overlay" id="confirmOverlay">
    <div class="card" style="max-width: 500px; width: 90%;">
        <div class="card-header bg-danger text-white">
            <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Credential Reset</h5>
        </div>
        <div class="card-body">
            <p>You are about to initiate a <strong id="confirmType"></strong> for <strong id="confirmScope"></strong>.</p>
            <p class="text-danger fw-semibold">This action cannot be undone. All affected users will:</p>
            <ul>
                <li>Have their passwords invalidated</li>
                <li>Be required to set a new password</li>
                <li>Have their active sessions terminated</li>
                <li>Receive an email notification</li>
            </ul>
            <div class="mb-3">
                <label class="form-label">Type <code>CONFIRM RESET</code> to proceed:</label>
                <input type="text" class="form-control" id="confirmInput" placeholder="CONFIRM RESET" autocomplete="off">
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <button class="btn btn-secondary" onclick="hideConfirmation()">Cancel</button>
            <button class="btn btn-danger" id="confirmBtn" onclick="executeReset()" disabled>
                <i class="fas fa-radiation me-1"></i>Execute Reset
            </button>
        </div>
    </div>
</div>

<!-- 📦 Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script>
// ============================================================================
// 🔐 Mass Credential Reset - Client-Side Logic
// ============================================================================

const csrfToken = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
const apiEndpoint = '/admin/api/user-actions.php';

/**
 * 🔐 Escape HTML entities to prevent DOM XSS
 * All dynamic values MUST be escaped before innerHTML insertion
 * @see https://cheatsheetseries.owasp.org/cheatsheets/DOM_based_XSS_Prevention_Cheat_Sheet.html
 */
function escapeHtml(text) {
    if (text === null || text === undefined) { return ''; }
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(text)));
    return div.innerHTML;
}

let selectedType = null;
let currentResetID = null;
let processingInterval = null;

// 🎯 Step 1: Select reset type
function selectResetType(card) {
    // 🔄 Update selection UI
    document.querySelectorAll('.reset-type-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');

    selectedType = card.dataset.type;

    // 📝 Show step 2
    document.getElementById('step2').style.display = 'block';
    document.getElementById('step2').scrollIntoView({ behavior: 'smooth' });
}

// 🔍 Toggle scope filter options
function toggleScopeFilter() {
    const scope = document.getElementById('resetScope').value;
    document.getElementById('filterOptions').style.display = (scope === 'filtered') ? 'block' : 'none';
}

// 🔄 Reset form to initial state
function resetForm() {
    document.querySelectorAll('.reset-type-card').forEach(c => c.classList.remove('selected'));
    selectedType = null;
    document.getElementById('step2').style.display = 'none';
    document.getElementById('resetReason').value = '';
}

// 🔒 Show confirmation dialog
function showConfirmation() {
    const reason = document.getElementById('resetReason').value.trim();
    if (!reason) {
        alert('Please provide a reason for the credential reset.');
        return;
    }
    if (!selectedType) {
        alert('Please select a reset type.');
        return;
    }

    // 📝 Populate confirmation details
    const typeLabels = {
        'mass_password_reset': 'Mass Password Reset',
        'salt_rotation': 'Salt Rotation',
        'full_credential_reset': 'Full Credential Reset'
    };
    document.getElementById('confirmType').textContent = typeLabels[selectedType] || selectedType;

    const scope = document.getElementById('resetScope').value;
    document.getElementById('confirmScope').textContent =
        scope === 'all_users' ? 'ALL users' : 'filtered users';

    document.getElementById('confirmInput').value = '';
    document.getElementById('confirmBtn').disabled = true;
    document.getElementById('confirmOverlay').classList.add('active');
}

// 🔒 Hide confirmation dialog
function hideConfirmation() {
    document.getElementById('confirmOverlay').classList.remove('active');
}

// ✅ Enable confirm button when user types CONFIRM RESET
document.getElementById('confirmInput').addEventListener('input', function() {
    document.getElementById('confirmBtn').disabled = (this.value !== 'CONFIRM RESET');
});

// 🚀 Execute the credential reset
async function executeReset() {
    hideConfirmation();

    const scope = document.getElementById('resetScope').value;
    const reason = document.getElementById('resetReason').value.trim();

    // 📋 Build scope filter
    const scopeFilter = {};
    if (scope === 'filtered') {
        const status = document.getElementById('filterStatus').value;
        const tier = document.getElementById('filterTier').value;
        if (status) { scopeFilter.status = status; }
        if (tier) { scopeFilter.tier = tier; }
    }

    // 🔒 Disable form controls
    document.getElementById('executeBtn').disabled = true;
    document.getElementById('step1').style.opacity = '0.5';
    document.getElementById('step1').style.pointerEvents = 'none';

    // 📊 Show progress
    document.getElementById('progressCard').classList.add('active');
    document.getElementById('progressActions').style.display = 'block';
    document.getElementById('progressLabel').textContent = 'Initiating credential reset...';

    try {
        // 🚨 Initiate the reset
        const response = await fetch(apiEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'initiate_mass_reset',
                csrf_token: csrfToken,
                resetType: selectedType,
                reason: reason,
                scope: scope,
                scopeFilter: scopeFilter,
                confirmed: true
            })
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || 'Failed to initiate reset');
        }

        currentResetID = data.resetID;
        document.getElementById('statRemaining').textContent = data.totalUsers;
        document.getElementById('progressLabel').textContent =
            `Processing ${data.totalUsers} users...`;

        // 🔄 Start batch processing
        processBatches();

    } catch (error) {
        alert('Error: ' + error.message);
        document.getElementById('executeBtn').disabled = false;
        document.getElementById('step1').style.opacity = '1';
        document.getElementById('step1').style.pointerEvents = 'auto';
        document.getElementById('progressCard').classList.remove('active');
    }
}

// 🔄 Process batches sequentially
async function processBatches() {
    if (!currentResetID) { return; }

    try {
        const response = await fetch(apiEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'process_reset_batch',
                csrf_token: csrfToken,
                resetID: currentResetID
            })
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || 'Batch processing failed');
        }

        // 📊 Update progress UI
        const total = data.totalUsers || 1;
        const processed = data.totalProcessed || 0;
        const percent = Math.round((processed / total) * 100);

        document.getElementById('progressBar').style.width = percent + '%';
        document.getElementById('progressPercent').textContent = percent + '%';
        document.getElementById('statProcessed').textContent = processed;
        document.getElementById('statRemaining').textContent = data.remaining || 0;
        document.getElementById('statEmailsSent').textContent =
            (parseInt(document.getElementById('statEmailsSent').textContent) || 0) + (data.emailsSent || 0);
        document.getElementById('statEmailsFailed').textContent =
            (parseInt(document.getElementById('statEmailsFailed').textContent) || 0) + (data.emailsFailed || 0);

        if (data.complete) {
            // ✅ All done
            document.getElementById('progressLabel').textContent = 'Credential reset completed!';
            document.getElementById('progressBar').classList.remove('progress-bar-animated');
            document.getElementById('progressBar').classList.add('bg-success');
            document.getElementById('cancelBtn').style.display = 'none';
            document.getElementById('doneBtn').style.display = 'inline-block';
            loadHistory();
        } else {
            // 🔄 Process next batch (small delay to prevent hammering)
            setTimeout(processBatches, 500);
        }

    } catch (error) {
        document.getElementById('progressLabel').textContent = 'Error: ' + error.message;
        document.getElementById('progressBar').classList.remove('progress-bar-animated');
        document.getElementById('progressBar').classList.add('bg-warning');
    }
}

// 🚫 Cancel operation
async function cancelOperation() {
    if (!currentResetID) { return; }
    if (!confirm('Are you sure you want to cancel? Users already processed will NOT be reverted.')) {
        return;
    }

    try {
        const response = await fetch(apiEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'cancel_reset',
                csrf_token: csrfToken,
                resetID: currentResetID
            })
        });

        const data = await response.json();
        alert(data.message);

        document.getElementById('progressLabel').textContent = 'Operation cancelled';
        document.getElementById('progressBar').classList.remove('progress-bar-animated');
        document.getElementById('progressBar').classList.add('bg-secondary');
        document.getElementById('cancelBtn').style.display = 'none';
        document.getElementById('doneBtn').style.display = 'inline-block';
        loadHistory();

    } catch (error) {
        alert('Cancel error: ' + error.message);
    }
}

// 📜 Load operation history
async function loadHistory() {
    try {
        const response = await fetch(apiEndpoint + '?action=list_reset_operations&page=1&perPage=20');
        const data = await response.json();

        const tbody = document.getElementById('historyTableBody');

        if (!data.success || !data.operations || data.operations.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No credential reset operations found</td></tr>';
            return;
        }

        const typeLabels = {
            'mass_password_reset': '<span class="badge bg-warning text-dark">Password Reset</span>',
            'salt_rotation': '<span class="badge bg-info">Salt Rotation</span>',
            'full_credential_reset': '<span class="badge bg-danger">Full Reset</span>'
        };

        const statusLabels = {
            'pending': '<span class="badge bg-secondary">Pending</span>',
            'in_progress': '<span class="badge bg-primary">In Progress</span>',
            'completed': '<span class="badge bg-success">Completed</span>',
            'failed': '<span class="badge bg-danger">Failed</span>',
            'cancelled': '<span class="badge bg-warning text-dark">Cancelled</span>'
        };

        // 🔐 All dynamic values escaped via escapeHtml() to prevent DOM XSS
        tbody.innerHTML = data.operations.map(op => `
            <tr>
                <td><small>${escapeHtml(new Date(op.createdAt).toLocaleString())}</small></td>
                <td>${typeLabels[op.resetType] || escapeHtml(op.resetType)}</td>
                <td><small>${escapeHtml(op.scope)}</small></td>
                <td>${escapeHtml(op.processedUsers)}/${escapeHtml(op.totalUsers)}</td>
                <td>
                    <span class="text-success">${escapeHtml(op.emailsSent)}</span> /
                    <span class="text-danger">${escapeHtml(op.emailsFailed)}</span>
                </td>
                <td>${statusLabels[op.status] || escapeHtml(op.status)}</td>
                <td><small>${escapeHtml(op.initiatedByUsername)}</small></td>
                <td>
                    <button class="btn btn-sm btn-outline-secondary" onclick="viewResetDetails(${parseInt(op.resetID) || 0})"
                            title="View Details"><i class="fas fa-eye"></i></button>
                </td>
            </tr>
        `).join('');

    } catch (error) {
        document.getElementById('historyTableBody').innerHTML =
            '<tr><td colspan="8" class="text-center text-danger py-3">Failed to load history</td></tr>';
    }
}

// 👁️ View reset operation details
async function viewResetDetails(resetID) {
    try {
        const response = await fetch(apiEndpoint + '?action=get_compliance_report&resetID=' + resetID);
        const data = await response.json();

        if (data.success) {
            const r = data.report;
            alert(
                `Compliance Report\n` +
                `─────────────────\n` +
                `Total Users: ${r.totalUsers}\n` +
                `Password Changed: ${r.compliant} (${r.compliancePercent}%)\n` +
                `Pending: ${r.pending}\n` +
                `Email Failed: ${r.emailFailed}`
            );
        }
    } catch (error) {
        alert('Failed to load details: ' + error.message);
    }
}

// 🚀 Load history on page load
document.addEventListener('DOMContentLoaded', loadHistory);
</script>
</body>
</html>
