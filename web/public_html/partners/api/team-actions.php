<?php
/**
 * Team Management API
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
require_once dirname(__DIR__, 3) . '/_backend/ActivityLogger.php';

header('Content-Type: application/json');

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);
$activityLogger = new ActivityLogger($db, $sessionManager);

// Check if logged in
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// 🛡️ Verify CSRF token for state-changing requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
        exit;
    }
}

try {
    switch ($action) {
        case 'invite':
            handleInvite($input, $db, $accessControl, $sessionManager, $activityLogger);
            break;

        case 'update_role':
            handleUpdateRole($input, $db, $accessControl, $activityLogger);
            break;

        case 'remove':
            handleRemove($input, $db, $accessControl, $activityLogger);
            break;

        case 'revoke':
            handleRevoke($input, $db, $accessControl, $activityLogger);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Handle team member invitation
 */
function handleInvite($input, $db, $accessControl, $sessionManager, $activityLogger) {
    $partnerID = $input['partnerID'] ?? 0;
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $role = $input['role'] ?? '';

    // Validate inputs
    if (!$email) {
        throw new Exception('Valid email address required');
    }

    if (!in_array($role, ['admin', 'developer', 'support', 'finance'])) {
        throw new Exception('Invalid role specified');
    }

    // Verify admin access
    if (!$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Admin access required');
    }

    // Get partner details
    $stmt = $db->prepare("SELECT tier, companyName FROM tblPartners WHERE partnerID = ?");
    $stmt->bind_param('i', $partnerID);
    $stmt->execute();
    $partner = $stmt->get_result()->fetch_assoc();

    if (!$partner) {
        throw new Exception('Partner not found');
    }

    // Check team size limit
    $maxTeamMembers = $accessControl->getMaxTeamMembers($partner['tier']);

    $stmt = $db->prepare("
        SELECT COUNT(*) as count FROM tblPartnerTeamMembers
        WHERE partnerID = ? AND status = 'active'
    ");
    $stmt->bind_param('i', $partnerID);
    $stmt->execute();
    $currentCount = $stmt->get_result()->fetch_assoc()['count'];

    if ($maxTeamMembers > 0 && $currentCount >= $maxTeamMembers) {
        throw new Exception('Team size limit reached. Please upgrade to add more members.');
    }

    // Check if user already exists as team member
    $stmt = $db->prepare("
        SELECT tm.memberID FROM tblPartnerTeamMembers tm
        JOIN tblUsers u ON tm.userID = u.userID
        WHERE tm.partnerID = ? AND u.email = ? AND tm.status = 'active'
    ");
    $stmt->bind_param('is', $partnerID, $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        throw new Exception('This user is already a team member');
    }

    // Check if there's already a pending invitation
    $stmt = $db->prepare("
        SELECT invitationID FROM tblTeamInvitations
        WHERE partnerID = ? AND email = ? AND status = 'pending' AND expiresAt > NOW()
    ");
    $stmt->bind_param('is', $partnerID, $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        throw new Exception('An invitation has already been sent to this email address');
    }

    // Generate secure invitation token
    $invitationToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    $invitedBy = $sessionManager->getUserID();

    // Insert invitation
    $stmt = $db->prepare("
        INSERT INTO tblTeamInvitations
        (partnerID, email, role, invitedBy, invitationToken, expiresAt, status)
        VALUES (?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param('ississ', $partnerID, $email, $role, $invitedBy, $invitationToken, $expiresAt);

    if (!$stmt->execute()) {
        throw new Exception('Failed to create invitation');
    }

    // Log activity
    $accessControl->logAdminAction('invite_team_member', 'partner', $partnerID, [
        'email' => $email,
        'role' => $role,
        'expiresAt' => $expiresAt
    ]);

    $activityLogger->logActivity(
        'team_invitation_sent',
        'Invitation sent to ' . $email . ' for role: ' . $role,
        ['partnerID' => $partnerID, 'email' => $email, 'role' => $role]
    );

    // Send invitation email
    $inviteLink = "https://" . $_SERVER['HTTP_HOST'] . "/partners/accept-invite.php?token=" . $invitationToken;

    // TODO: Implement email sending via your email system
    // For now, we'll just return success with the link
    // In production, this should integrate with your email system

    /*
    Example email content:

    Subject: You've been invited to join {$partner['companyName']} on SIGNula

    Hello,

    You've been invited to join {$partner['companyName']} as a {$role} on SIGNula.

    Click the link below to accept this invitation:
    {$inviteLink}

    This invitation will expire in 7 days.

    If you don't have a SIGNula account yet, you'll need to create one first.
    */

    echo json_encode([
        'success' => true,
        'message' => 'Invitation sent successfully',
        'inviteLink' => $inviteLink // Remove this in production
    ]);
}

/**
 * Handle role update
 */
function handleUpdateRole($input, $db, $accessControl, $activityLogger) {
    $memberID = $input['memberID'] ?? 0;
    $newRole = $input['role'] ?? '';

    if (!in_array($newRole, ['admin', 'developer', 'support', 'finance'])) {
        throw new Exception('Invalid role specified');
    }

    // Get member details
    $stmt = $db->prepare("
        SELECT tm.*, p.tier FROM tblPartnerTeamMembers tm
        JOIN tblPartners p ON tm.partnerID = p.partnerID
        WHERE tm.memberID = ?
    ");
    $stmt->bind_param('i', $memberID);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();

    if (!$member) {
        throw new Exception('Team member not found');
    }

    // Verify admin access
    if (!$accessControl->isPartnerAdmin($member['partnerID'])) {
        throw new Exception('Admin access required');
    }

    // Cannot change root admin role
    if ($member['isRootAdmin']) {
        throw new Exception('Cannot change root admin role');
    }

    // Update role
    $stmt = $db->prepare("UPDATE tblPartnerTeamMembers SET role = ? WHERE memberID = ?");
    $stmt->bind_param('si', $newRole, $memberID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to update role');
    }

    // Log activity
    $accessControl->logAdminAction('update_member_role', 'team_member', $memberID, [
        'old_role' => $member['role'],
        'new_role' => $newRole
    ]);

    $activityLogger->logActivity(
        'team_member_role_updated',
        'Team member role updated to ' . $newRole,
        ['memberID' => $memberID, 'partnerID' => $member['partnerID']]
    );

    echo json_encode(['success' => true, 'message' => 'Role updated successfully']);
}

/**
 * Handle team member removal
 */
function handleRemove($input, $db, $accessControl, $activityLogger) {
    $memberID = $input['memberID'] ?? 0;

    // Get member details
    $stmt = $db->prepare("
        SELECT tm.*, u.username, u.email FROM tblPartnerTeamMembers tm
        JOIN tblUsers u ON tm.userID = u.userID
        WHERE tm.memberID = ?
    ");
    $stmt->bind_param('i', $memberID);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();

    if (!$member) {
        throw new Exception('Team member not found');
    }

    // Verify admin access
    if (!$accessControl->isPartnerAdmin($member['partnerID'])) {
        throw new Exception('Admin access required');
    }

    // Cannot remove root admin
    if ($member['isRootAdmin']) {
        throw new Exception('Cannot remove root admin. Transfer ownership first.');
    }

    // Update status to removed
    $stmt = $db->prepare("
        UPDATE tblPartnerTeamMembers
        SET status = 'removed', removedAt = NOW()
        WHERE memberID = ?
    ");
    $stmt->bind_param('i', $memberID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to remove team member');
    }

    // Log activity
    $accessControl->logAdminAction('remove_team_member', 'team_member', $memberID, [
        'username' => $member['username'],
        'email' => $member['email']
    ]);

    $activityLogger->logActivity(
        'team_member_removed',
        'Team member removed: ' . $member['username'],
        ['memberID' => $memberID, 'partnerID' => $member['partnerID']]
    );

    echo json_encode(['success' => true, 'message' => 'Team member removed successfully']);
}

/**
 * Handle invitation revocation
 */
function handleRevoke($input, $db, $accessControl, $activityLogger) {
    $invitationID = $input['invitationID'] ?? 0;

    // Get invitation details
    $stmt = $db->prepare("
        SELECT * FROM tblTeamInvitations WHERE invitationID = ?
    ");
    $stmt->bind_param('i', $invitationID);
    $stmt->execute();
    $invitation = $stmt->get_result()->fetch_assoc();

    if (!$invitation) {
        throw new Exception('Invitation not found');
    }

    // Verify admin access
    if (!$accessControl->isPartnerAdmin($invitation['partnerID'])) {
        throw new Exception('Admin access required');
    }

    // Update invitation status
    $stmt = $db->prepare("
        UPDATE tblTeamInvitations
        SET status = 'revoked', revokedAt = NOW()
        WHERE invitationID = ?
    ");
    $stmt->bind_param('i', $invitationID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to revoke invitation');
    }

    // Log activity
    $accessControl->logAdminAction('revoke_invitation', 'invitation', $invitationID, [
        'email' => $invitation['email']
    ]);

    $activityLogger->logActivity(
        'invitation_revoked',
        'Invitation revoked for ' . $invitation['email'],
        ['invitationID' => $invitationID, 'partnerID' => $invitation['partnerID']]
    );

    echo json_encode(['success' => true, 'message' => 'Invitation revoked successfully']);
}
