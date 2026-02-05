<?php
/**
 * Partner Team Management
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 3) . '/_config/config.php';
require_once dirname(__DIR__, 3) . '/_backend/Database.php';
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';
require_once dirname(__DIR__, 3) . '/_backend/AccessControl.php';

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// Get user's partner memberships
$memberships = $accessControl->getPartnerMemberships();

if (empty($memberships)) {
    header('Location: /partners/register.php');
    exit;
}

// Default to first partner or use selected
$selectedPartnerID = $_GET['partner'] ?? array_key_first($memberships);
$partner = $memberships[$selectedPartnerID] ?? reset($memberships);

// Verify admin access
if (!$accessControl->isPartnerAdmin($selectedPartnerID)) {
    http_response_code(403);
    die('Admin access required for this partner');
}

// Get partner details
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE partnerID = ?");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerDetails = $stmt->get_result()->fetch_assoc();

// Get team size limit
$maxTeamMembers = $accessControl->getMaxTeamMembers($partnerDetails['tier']);

// Get current team members
$stmt = $db->prepare("
    SELECT tm.*, u.username, u.email, u.createdAt as userCreatedAt
    FROM tblPartnerTeamMembers tm
    JOIN tblUsers u ON tm.userID = u.userID
    WHERE tm.partnerID = ? AND tm.status = 'active'
    ORDER BY tm.isRootAdmin DESC, tm.role, u.username
");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$teamMembers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get pending invitations
$stmt = $db->prepare("
    SELECT i.*, u.username as invitedByUsername
    FROM tblTeamInvitations i
    LEFT JOIN tblUsers u ON i.invitedBy = u.userID
    WHERE i.partnerID = ? AND i.status = 'pending' AND i.expiresAt > NOW()
    ORDER BY i.createdAt DESC
");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$pendingInvitations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$teamCount = count($teamMembers);
$canInvite = ($maxTeamMembers == 0 || $teamCount < $maxTeamMembers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Management - <?php echo htmlspecialchars($partner['companyName']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .team-header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 2rem; border-radius: 10px; margin-bottom: 2rem; }
        .member-card { border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 1rem; transition: all 0.3s; }
        .member-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .role-badge { padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
        .root-admin-badge { background: linear-gradient(135deg, #f093fb, #f5576c); color: white; }
        .team-stats { background: rgba(255,255,255,0.2); border-radius: 10px; padding: 1rem; }
        .limit-warning { color: #ffc107; }
        .limit-reached { color: #dc3545; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="team-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <a href="index.php?partner=<?php echo $selectedPartnerID; ?>" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                        <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
                    </a>
                    <h1><i class="fas fa-users me-2"></i>Team Management</h1>
                    <p class="mb-0 opacity-75"><?php echo htmlspecialchars($partner['companyName']); ?></p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="team-stats">
                        <h3 class="mb-0 <?php echo ($maxTeamMembers > 0 && $teamCount >= $maxTeamMembers) ? 'limit-reached' : (($maxTeamMembers > 0 && $teamCount >= $maxTeamMembers * 0.8) ? 'limit-warning' : ''); ?>">
                            <?php echo $teamCount; ?> / <?php echo $maxTeamMembers ?: '∞'; ?>
                        </h3>
                        <small>Team Members</small>
                        <?php if (!$canInvite): ?>
                        <div class="alert alert-warning mt-2 mb-0 p-2">
                            <small><i class="fas fa-exclamation-triangle me-1"></i>Team limit reached</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <div id="alertContainer"></div>

        <!-- Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#inviteModal" <?php echo !$canInvite ? 'disabled' : ''; ?>>
                    <i class="fas fa-user-plus me-2"></i>Invite Team Member
                </button>
                <?php if (!$canInvite): ?>
                <small class="text-muted ms-3">Upgrade to <?php echo $partnerDetails['tier'] == 'free' ? 'Basic' : 'a higher'; ?> tier to add more members</small>
                <?php endif; ?>
            </div>
        </div>

        <!-- Current Team Members -->
        <div class="card mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-users me-2"></i>Current Team Members</h5>
            </div>
            <div class="card-body">
                <?php if (empty($teamMembers)): ?>
                <p class="text-muted mb-0">No team members yet. Invite your first team member to get started!</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Role</th>
                                <th>Joined</th>
                                <th>Invited By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teamMembers as $member): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($member['username']); ?></strong>
                                    <br>
                                    <small class="text-muted"><?php echo htmlspecialchars($member['email']); ?></small>
                                </td>
                                <td>
                                    <?php if ($member['isRootAdmin']): ?>
                                    <span class="role-badge root-admin-badge">
                                        <i class="fas fa-crown me-1"></i>Root Admin
                                    </span>
                                    <?php else: ?>
                                    <span class="role-badge bg-primary text-white">
                                        <?php echo strtoupper($member['role']); ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($member['joinedAt'])); ?></td>
                                <td>
                                    <?php if ($member['invitedBy']): ?>
                                    <small class="text-muted">
                                        <?php
                                        $inviter = $db->prepare("SELECT username FROM tblUsers WHERE userID = ?");
                                        $inviter->bind_param('i', $member['invitedBy']);
                                        $inviter->execute();
                                        $inviterData = $inviter->get_result()->fetch_assoc();
                                        echo htmlspecialchars($inviterData['username'] ?? 'Unknown');
                                        ?>
                                    </small>
                                    <?php else: ?>
                                    <small class="text-muted">Founder</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$member['isRootAdmin']): ?>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" onclick="editMember(<?php echo $member['memberID']; ?>, '<?php echo htmlspecialchars($member['role'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" onclick="removeMember(<?php echo $member['memberID']; ?>, '<?php echo htmlspecialchars($member['username'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                    <?php else: ?>
                                    <small class="text-muted">Owner</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pending Invitations -->
        <?php if (!empty($pendingInvitations)): ?>
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Pending Invitations</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Invited</th>
                                <th>Expires</th>
                                <th>Invited By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingInvitations as $invitation): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($invitation['email']); ?></td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo strtoupper($invitation['role']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($invitation['createdAt'])); ?></td>
                                <td>
                                    <?php
                                    $expiresAt = strtotime($invitation['expiresAt']);
                                    $now = time();
                                    $hoursLeft = round(($expiresAt - $now) / 3600);
                                    ?>
                                    <small class="<?php echo $hoursLeft < 24 ? 'text-danger' : 'text-muted'; ?>">
                                        <?php echo date('M j, Y g:i A', $expiresAt); ?>
                                        <br>(<?php echo $hoursLeft; ?> hours left)
                                    </small>
                                </td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($invitation['invitedByUsername'] ?? 'Unknown'); ?></small></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-danger" onclick="revokeInvitation(<?php echo $invitation['invitationID']; ?>, '<?php echo htmlspecialchars($invitation['email'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-times me-1"></i>Revoke
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Invite Member Modal -->
    <div class="modal fade" id="inviteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Invite Team Member</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="inviteForm">
                        <div class="mb-3">
                            <label for="inviteEmail" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="inviteEmail" required>
                            <small class="text-muted">An invitation will be sent to this email address</small>
                        </div>
                        <div class="mb-3">
                            <label for="inviteRole" class="form-label">Role</label>
                            <select class="form-select" id="inviteRole" required>
                                <option value="">Select a role...</option>
                                <option value="admin">Admin - Full administrative access</option>
                                <option value="developer">Developer - API keys, documentation, testing</option>
                                <option value="support">Support - Support tickets, user management</option>
                                <option value="finance">Finance - Billing, usage reports, invoices</option>
                            </select>
                        </div>
                        <div class="alert alert-info">
                            <small>
                                <i class="fas fa-info-circle me-1"></i>
                                The invitation will expire in 7 days. The recipient must have a SIGNula account to accept.
                            </small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="sendInvitation()">
                        <i class="fas fa-paper-plane me-2"></i>Send Invitation
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Member Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Team Member</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editForm">
                        <input type="hidden" id="editMemberID">
                        <div class="mb-3">
                            <label for="editRole" class="form-label">Role</label>
                            <select class="form-select" id="editRole" required>
                                <option value="admin">Admin</option>
                                <option value="developer">Developer</option>
                                <option value="support">Support</option>
                                <option value="finance">Finance</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateMember()">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const partnerID = <?php echo $selectedPartnerID; ?>;
        const inviteModal = new bootstrap.Modal(document.getElementById('inviteModal'));
        const editModal = new bootstrap.Modal(document.getElementById('editModal'));

        function showAlert(message, type = 'info') {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.getElementById('alertContainer').appendChild(alert);
            setTimeout(() => alert.remove(), 5000);
        }

        async function sendInvitation() {
            const email = document.getElementById('inviteEmail').value;
            const role = document.getElementById('inviteRole').value;

            if (!email || !role) {
                showAlert('Please fill in all fields', 'warning');
                return;
            }

            try {
                const response = await fetch('../api/team-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'invite',
                        partnerID: partnerID,
                        email: email,
                        role: role
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Invitation sent successfully!', 'success');
                    inviteModal.hide();
                    document.getElementById('inviteForm').reset();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(result.message || 'Failed to send invitation', 'danger');
                }
            } catch (error) {
                showAlert('Error sending invitation. Please try again.', 'danger');
            }
        }

        function editMember(memberID, currentRole) {
            document.getElementById('editMemberID').value = memberID;
            document.getElementById('editRole').value = currentRole;
            editModal.show();
        }

        async function updateMember() {
            const memberID = document.getElementById('editMemberID').value;
            const role = document.getElementById('editRole').value;

            try {
                const response = await fetch('../api/team-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_role',
                        memberID: memberID,
                        role: role
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Role updated successfully!', 'success');
                    editModal.hide();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(result.message || 'Failed to update role', 'danger');
                }
            } catch (error) {
                showAlert('Error updating role. Please try again.', 'danger');
            }
        }

        async function removeMember(memberID, username) {
            if (!confirm(`Are you sure you want to remove ${username} from the team?\n\nThis action cannot be undone.`)) {
                return;
            }

            try {
                const response = await fetch('../api/team-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'remove',
                        memberID: memberID
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Team member removed successfully', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(result.message || 'Failed to remove team member', 'danger');
                }
            } catch (error) {
                showAlert('Error removing team member. Please try again.', 'danger');
            }
        }

        async function revokeInvitation(invitationID, email) {
            if (!confirm(`Revoke invitation to ${email}?`)) {
                return;
            }

            try {
                const response = await fetch('../api/team-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'revoke',
                        invitationID: invitationID
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Invitation revoked', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(result.message || 'Failed to revoke invitation', 'danger');
                }
            } catch (error) {
                showAlert('Error revoking invitation. Please try again.', 'danger');
            }
        }
    </script>
</body>
</html>
