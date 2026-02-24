<?php
/**
 * ============================================================================
 * 👥 SIGNula - Organization Member Management
 * ============================================================================
 *
 * Purpose: Manage organization members and invitations
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔄 Require authentication
Auth::requireAuth();

// Get current user
$user = Auth::getCurrentUser();

// 🏢 Check if user belongs to an organization
$organizationID = $user['organizationID'] ?? null;
$organization = null;
$userRole = null;
$isAdmin = false;

if ($organizationID) {
    $organization = Database::fetchOne(
        "SELECT * FROM tblOrganizations WHERE organizationID = ?",
        [$organizationID],
        'i'
    );

    $membership = Database::fetchOne(
        "SELECT role FROM tblOrganizationMembers WHERE organizationID = ? AND userID = ?",
        [$organizationID, $user['userID']],
        'ii'
    );

    $userRole = $membership['role'] ?? 'member';
    $isAdmin = in_array($userRole, ['owner', 'admin']);
}

// 🚫 Redirect if not part of organization
if (!$organization) {
    redirect('/dashboard?error=' . urlencode('You are not part of an organization.'));
}

// Get active tab
$activeTab = $_GET['tab'] ?? 'members';

// 📝 Handle form submissions
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $action = $_POST['action'] ?? '';

    // 🛡️ Verify CSRF token
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            switch ($action) {
                case 'invite_member':
                    $email = SecurityUtils::sanitizeEmail($_POST['email'] ?? '');
                    $role = $_POST['role'] ?? 'member';

                    if (!in_array($role, ['admin', 'member', 'guest'])) {
                        $role = 'member';
                    }

                    $result = Organization::inviteMember($organizationID, $email, $role, $user['userID']);

                    if ($result['success']) {
                        $success = $result['message'];
                        $activeTab = 'invitations';
                    } else {
                        $error = $result['message'];
                    }
                    break;

                case 'change_role':
                    $memberUserID = (int)($_POST['member_user_id'] ?? 0);
                    $newRole = $_POST['new_role'] ?? 'member';

                    if (!in_array($newRole, ['admin', 'member', 'guest'])) {
                        $newRole = 'member';
                    }

                    // Cannot change own role
                    if ($memberUserID === $user['userID']) {
                        $error = 'You cannot change your own role.';
                    } else {
                        // Check if member exists
                        $memberCheck = Database::fetchOne(
                            "SELECT memberID FROM tblOrganizationMembers
                             WHERE organizationID = ? AND userID = ?",
                            [$organizationID, $memberUserID],
                            'ii'
                        );

                        if ($memberCheck) {
                            Database::query(
                                "UPDATE tblOrganizationMembers SET role = ? WHERE organizationID = ? AND userID = ?",
                                [$newRole, $organizationID, $memberUserID],
                                'sii'
                            );

                            ActivityLogger::log($user['userID'], 'member_role_changed', 'organization', 'info',
                                'Member role changed', ['target_user_id' => $memberUserID, 'new_role' => $newRole]);

                            $success = 'Member role updated successfully.';
                        }
                    }
                    break;

                case 'remove_member':
                    $memberUserID = (int)($_POST['member_user_id'] ?? 0);

                    // Cannot remove self
                    if ($memberUserID === $user['userID']) {
                        $error = 'You cannot remove yourself from the organization.';
                    } else {
                        Database::beginTransaction();

                        // Remove from organization members
                        Database::query(
                            "DELETE FROM tblOrganizationMembers WHERE organizationID = ? AND userID = ?",
                            [$organizationID, $memberUserID],
                            'ii'
                        );

                        // Update user's organizationID
                        Database::query(
                            "UPDATE tblUsers SET organizationID = NULL WHERE userID = ?",
                            [$memberUserID],
                            'i'
                        );

                        Database::commit();

                        ActivityLogger::log($user['userID'], 'member_removed', 'organization', 'warning',
                            'Member removed from organization', ['target_user_id' => $memberUserID]);

                        $success = 'Member removed from organization.';
                    }
                    break;

                case 'cancel_invitation':
                    $invitationID = (int)($_POST['invitation_id'] ?? 0);

                    Database::query(
                        "UPDATE tblOrganizationInvitations SET status = 'cancelled' WHERE invitationID = ? AND organizationID = ?",
                        [$invitationID, $organizationID],
                        'ii'
                    );

                    $success = 'Invitation cancelled.';
                    $activeTab = 'invitations';
                    break;

                case 'resend_invitation':
                    $invitationID = (int)($_POST['invitation_id'] ?? 0);

                    // Get invitation details
                    $invitation = Database::fetchOne(
                        "SELECT * FROM tblOrganizationInvitations WHERE invitationID = ? AND organizationID = ?",
                        [$invitationID, $organizationID],
                        'ii'
                    );

                    if ($invitation) {
                        // Generate new token
                        $newToken = SecurityUtils::generateToken();

                        Database::query(
                            "UPDATE tblOrganizationInvitations
                             SET token = ?, expiresAt = DATE_ADD(NOW(), INTERVAL 7 DAY), updatedAt = NOW()
                             WHERE invitationID = ?",
                            [$newToken, $invitationID],
                            'si'
                        );

                        // TODO: Resend invitation email
                        EmailService::queueEmail(
                            $invitation['email'],
                            'organization_invitation',
                            [
                                'organization_name' => $organization['name'],
                                'invitation_link' => SITE_URL . '/organization/accept-invite?token=' . $newToken,
                                'role' => $invitation['role']
                            ]
                        );

                        $success = 'Invitation resent successfully.';
                        $activeTab = 'invitations';
                    }
                    break;
            }
        } catch (Exception $e) {
            if (Database::isConnected()) {
                Database::rollback();
            }
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            $error = 'An error occurred. Please try again.';
        }
    }
}

// 📋 Get organization members
$members = [];
try {
    $members = Database::fetchAll(
        "SELECT om.*, u.userID, u.email, u.username, u.displayName, u.firstName, u.lastName,
                u.profilePicture, u.lastLoginAt, u.createdAt as userCreatedAt
         FROM tblOrganizationMembers om
         JOIN tblUsers u ON om.userID = u.userID
         WHERE om.organizationID = ?
         ORDER BY
            CASE om.role
                WHEN 'owner' THEN 1
                WHEN 'admin' THEN 2
                WHEN 'member' THEN 3
                WHEN 'guest' THEN 4
            END,
            om.joinedAt DESC",
        [$organizationID],
        'i'
    );
} catch (Exception $e) {
    ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
}

// 📧 Get pending invitations
$invitations = [];
try {
    $invitations = Database::fetchAll(
        "SELECT oi.*, u.displayName as inviterName
         FROM tblOrganizationInvitations oi
         LEFT JOIN tblUsers u ON oi.invitedBy = u.userID
         WHERE oi.organizationID = ?
         AND oi.status = 'pending'
         AND oi.expiresAt > NOW()
         ORDER BY oi.createdAt DESC",
        [$organizationID],
        'i'
    );
} catch (Exception $e) {
    ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
}

// 🎫 Generate CSRF token
$csrfToken = SecurityUtils::generateCSRFToken();

// 📄 Page metadata
$pageTitle = 'Member Management - ' . $organization['name'] . ' - SIGNula';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage organization members">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <!-- 🎨 Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">

    <!-- 🎨 Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <!-- 🎨 Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" />

    <!-- 🎨 Custom CSS -->
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>

<!-- 📱 Mobile Menu Toggle -->
<button class="mobile-menu-toggle" id="mobileMenuToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- 🗂️ Sidebar Navigation -->
<?php include dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'organization-nav.php'; ?>

<!-- 📄 Main Content -->
<main class="dashboard-main">
    <!-- 🎯 Page Header -->
    <div class="page-header">
        <div>
            <h1><i class="fas fa-users"></i> Member Management</h1>
            <p class="text-muted">Manage team members for <?php echo htmlspecialchars($organization['name']); ?></p>
        </div>
        <?php if ($isAdmin): ?>
        <div class="page-header-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#inviteMemberModal">
                <i class="fas fa-user-plus"></i> Invite Member
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- 🚨 Messages -->
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
            <button type="button" class="alert-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
            <button type="button" class="alert-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- 📊 Statistics -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($members); ?></div>
                <div class="stat-label">Total Members</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($invitations); ?></div>
                <div class="stat-label">Pending Invitations</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($members, fn($m) => in_array($m['role'], ['owner', 'admin']))); ?></div>
                <div class="stat-label">Administrators</div>
            </div>
        </div>
    </div>

    <!-- 🗂️ Tabs -->
    <div class="card">
        <div class="card-tabs">
            <a href="?tab=members" class="card-tab <?php echo $activeTab === 'members' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> Members (<?php echo count($members); ?>)
            </a>
            <a href="?tab=invitations" class="card-tab <?php echo $activeTab === 'invitations' ? 'active' : ''; ?>">
                <i class="fas fa-envelope"></i> Invitations (<?php echo count($invitations); ?>)
            </a>
        </div>

        <div class="card-body" style="padding: 0;">
            <!-- 👥 Members Tab -->
            <?php if ($activeTab === 'members'): ?>
                <?php if (empty($members)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>No Members Yet</h3>
                        <p>Invite your first team member to get started.</p>
                        <?php if ($isAdmin): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#inviteMemberModal">
                            <i class="fas fa-user-plus"></i> Invite Member
                        </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Role</th>
                                    <th>Joined</th>
                                    <th>Last Login</th>
                                    <?php if ($isAdmin): ?>
                                    <th>Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($members as $member): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <div class="user-avatar">
                                                <?php
                                                    // 🖼️ Use AvatarService for full priority chain resolution
                                                    $memberAvatarUrl = class_exists('AvatarService')
                                                        ? AvatarService::resolve((int)$member['userID'], null, 48)
                                                        : ($member['profilePicture'] ?? '');
                                                ?>
                                                <?php if (!empty($memberAvatarUrl)): ?>
                                                    <img src="<?php echo htmlspecialchars($memberAvatarUrl); ?>"
                                                         alt="<?php echo htmlspecialchars($member['displayName'] ?? $member['username']); ?>"
                                                         style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;"
                                                         onerror="this.style.display='none'; this.parentElement.textContent='<?php echo htmlspecialchars(strtoupper(substr($member['displayName'] ?? $member['username'], 0, 1))); ?>';">
                                                <?php else: ?>
                                                    <?php echo strtoupper(substr($member['displayName'] ?? $member['username'], 0, 1)); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($member['displayName'] ?? $member['username']); ?></strong>
                                                <?php if ($member['userID'] === $user['userID']): ?>
                                                    <span class="badge badge-sm badge-primary">You</span>
                                                <?php endif; ?>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($member['email']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $roleColors = [
                                            'owner' => 'danger',
                                            'admin' => 'warning',
                                            'member' => 'primary',
                                            'guest' => 'secondary'
                                        ];
                                        $roleIcons = [
                                            'owner' => 'crown',
                                            'admin' => 'user-shield',
                                            'member' => 'user',
                                            'guest' => 'user-clock'
                                        ];
                                        $badgeColor = $roleColors[$member['role']] ?? 'secondary';
                                        $icon = $roleIcons[$member['role']] ?? 'user';
                                        ?>
                                        <span class="badge badge-<?php echo $badgeColor; ?>">
                                            <i class="fas fa-<?php echo $icon; ?>"></i> <?php echo ucfirst($member['role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $joined = new DateTime($member['joinedAt']);
                                        echo $joined->format('M j, Y');
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($member['lastLoginAt']): ?>
                                            <?php
                                            $lastLogin = new DateTime($member['lastLoginAt']);
                                            $now = new DateTime();
                                            $diff = $now->diff($lastLogin);

                                            if ($diff->days > 0) {
                                                echo $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
                                            } elseif ($diff->h > 0) {
                                                echo $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
                                            } else {
                                                echo 'Recently';
                                            }
                                            ?>
                                        <?php else: ?>
                                            <span class="text-muted">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($isAdmin): ?>
                                    <td>
                                        <?php if ($member['userID'] !== $user['userID'] && $member['role'] !== 'owner'): ?>
                                        <div class="btn-group-sm">
                                            <button
                                                class="btn btn-sm btn-outline"
                                                onclick="changeRole(<?php echo $member['userID']; ?>, '<?php echo htmlspecialchars($member['displayName'] ?? $member['username']); ?>', '<?php echo $member['role']; ?>')"
                                            >
                                                <i class="fas fa-user-edit"></i> Change Role
                                            </button>
                                            <button
                                                class="btn btn-sm btn-danger"
                                                onclick="removeMember(<?php echo $member['userID']; ?>, '<?php echo htmlspecialchars($member['displayName'] ?? $member['username']); ?>')"
                                            >
                                                <i class="fas fa-user-times"></i> Remove
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- 📧 Invitations Tab -->
            <?php if ($activeTab === 'invitations'): ?>
                <?php if (empty($invitations)): ?>
                    <div class="empty-state">
                        <i class="fas fa-envelope"></i>
                        <h3>No Pending Invitations</h3>
                        <p>All invitations have been accepted or expired.</p>
                        <?php if ($isAdmin): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#inviteMemberModal">
                            <i class="fas fa-user-plus"></i> Invite Member
                        </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Invited By</th>
                                    <th>Sent</th>
                                    <th>Expires</th>
                                    <?php if ($isAdmin): ?>
                                    <th>Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invitations as $invitation): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($invitation['email']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-secondary">
                                            <?php echo ucfirst($invitation['role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($invitation['inviterName'] ?? 'System'); ?>
                                    </td>
                                    <td>
                                        <?php
                                        $created = new DateTime($invitation['createdAt']);
                                        echo $created->format('M j, Y');
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $expires = new DateTime($invitation['expiresAt']);
                                        $now = new DateTime();
                                        $diff = $now->diff($expires);

                                        if ($diff->invert) {
                                            echo '<span class="text-danger">Expired</span>';
                                        } else {
                                            echo 'In ' . $diff->days . ' day' . ($diff->days !== 1 ? 's' : '');
                                        }
                                        ?>
                                    </td>
                                    <?php if ($isAdmin): ?>
                                    <td>
                                        <div class="btn-group-sm">
                                            <button
                                                class="btn btn-sm btn-primary"
                                                onclick="resendInvitation(<?php echo $invitation['invitationID']; ?>, '<?php echo htmlspecialchars($invitation['email']); ?>')"
                                            >
                                                <i class="fas fa-paper-plane"></i> Resend
                                            </button>
                                            <button
                                                class="btn btn-sm btn-danger"
                                                onclick="cancelInvitation(<?php echo $invitation['invitationID']; ?>, '<?php echo htmlspecialchars($invitation['email']); ?>')"
                                            >
                                                <i class="fas fa-times"></i> Cancel
                                            </button>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- 🔲 Invite Member Modal -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="inviteMemberModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/organization/members">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="invite_member">

                <div class="modal-header">
                    <h3><i class="fas fa-user-plus"></i> Invite Member</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label for="email" class="form-label form-label-required">Email Address</label>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            name="email"
                            placeholder="colleague@example.com"
                            required
                        >
                        <small class="form-text">An invitation will be sent to this email address</small>
                    </div>

                    <div class="form-group">
                        <label for="role" class="form-label form-label-required">Role</label>
                        <select class="form-control" id="role" name="role" required>
                            <option value="member">Member - Can access organization features</option>
                            <option value="admin">Admin - Can manage members and settings</option>
                            <option value="guest">Guest - Limited read-only access</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Invitation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 🗑️ Hidden forms for actions -->
<form id="removeMemberForm" method="POST" action="/organization/members" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="action" value="remove_member">
    <input type="hidden" name="member_user_id" id="removeMemberUserId">
</form>

<form id="changeRoleForm" method="POST" action="/organization/members" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="action" value="change_role">
    <input type="hidden" name="member_user_id" id="changeRoleMemberUserId">
    <input type="hidden" name="new_role" id="changeRoleNewRole">
</form>

<form id="cancelInvitationForm" method="POST" action="/organization/members" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="action" value="cancel_invitation">
    <input type="hidden" name="invitation_id" id="cancelInvitationId">
</form>

<form id="resendInvitationForm" method="POST" action="/organization/members" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="action" value="resend_invitation">
    <input type="hidden" name="invitation_id" id="resendInvitationId">
</form>
<?php endif; ?>

<!-- 📜 JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="/assets/js/main.js"></script>

<?php if ($isAdmin): ?>
<script>
function removeMember(userId, memberName) {
    if (confirm('Are you sure you want to remove ' + memberName + ' from the organization?\n\nThey will lose access to all organization resources.')) {
        document.getElementById('removeMemberUserId').value = userId;
        document.getElementById('removeMemberForm').submit();
    }
}

function changeRole(userId, memberName, currentRole) {
    const newRole = prompt('Enter new role for ' + memberName + ':\n\n- admin (can manage members and settings)\n- member (standard access)\n- guest (read-only access)\n\nCurrent role: ' + currentRole, currentRole);

    if (newRole && ['admin', 'member', 'guest'].includes(newRole.toLowerCase()) && newRole.toLowerCase() !== currentRole) {
        document.getElementById('changeRoleMemberUserId').value = userId;
        document.getElementById('changeRoleNewRole').value = newRole.toLowerCase();
        document.getElementById('changeRoleForm').submit();
    }
}

function cancelInvitation(invitationId, email) {
    if (confirm('Cancel invitation for ' + email + '?')) {
        document.getElementById('cancelInvitationId').value = invitationId;
        document.getElementById('cancelInvitationForm').submit();
    }
}

function resendInvitation(invitationId, email) {
    if (confirm('Resend invitation to ' + email + '?')) {
        document.getElementById('resendInvitationId').value = invitationId;
        document.getElementById('resendInvitationForm').submit();
    }
}
</script>
<?php endif; ?>

</body>
</html>
