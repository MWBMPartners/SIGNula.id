<?php
/**
 * Accept Team Invitation
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 2) . '/_config/config.php';
require_once dirname(__DIR__, 2) . '/_backend/Database.php';
require_once dirname(__DIR__, 2) . '/_backend/SessionManager.php';
require_once dirname(__DIR__, 2) . '/_backend/AccessControl.php';
require_once dirname(__DIR__, 2) . '/_backend/ActivityLogger.php';

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);
$activityLogger = new ActivityLogger($db, $sessionManager);

$token = $_GET['token'] ?? '';
$message = '';
$success = false;
$invitation = null;

// Validate token
if ($token) {
    $stmt = $db->prepare("
        SELECT i.*, p.companyName, p.tier
        FROM tblTeamInvitations i
        JOIN tblPartners p ON i.partnerID = p.partnerID
        WHERE i.invitationToken = ? AND i.status = 'pending'
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $invitation = $stmt->get_result()->fetch_assoc();

    if (!$invitation) {
        $message = 'This invitation is invalid or has already been used.';
    } elseif (strtotime($invitation['expiresAt']) < time()) {
        $message = 'This invitation has expired. Please request a new invitation from your team administrator.';
    }
}

// Handle acceptance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept']) && $invitation) {
    // Must be logged in
    if (!$sessionManager->isLoggedIn()) {
        header('Location: /auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }

    $userID = $sessionManager->getUserID();
    $userInfo = $sessionManager->getUserInfo();

    // Verify email matches
    if (strtolower($userInfo['email']) !== strtolower($invitation['email'])) {
        $message = 'This invitation was sent to ' . htmlspecialchars($invitation['email']) . ' but you are logged in as ' . htmlspecialchars($userInfo['email']) . '. Please log in with the correct account.';
    } else {
        try {
            // Check if already a member
            $stmt = $db->prepare("
                SELECT memberID FROM tblPartnerTeamMembers
                WHERE partnerID = ? AND userID = ? AND status = 'active'
            ");
            $stmt->bind_param('ii', $invitation['partnerID'], $userID);
            $stmt->execute();

            if ($stmt->get_result()->num_rows > 0) {
                throw new Exception('You are already a member of this organization.');
            }

            // Check team size limit
            $maxTeamMembers = $accessControl->getMaxTeamMembers($invitation['tier']);

            $stmt = $db->prepare("
                SELECT COUNT(*) as count FROM tblPartnerTeamMembers
                WHERE partnerID = ? AND status = 'active'
            ");
            $stmt->bind_param('i', $invitation['partnerID']);
            $stmt->execute();
            $currentCount = $stmt->get_result()->fetch_assoc()['count'];

            if ($maxTeamMembers > 0 && $currentCount >= $maxTeamMembers) {
                throw new Exception('This organization has reached its team size limit. Please contact your administrator to upgrade.');
            }

            // Add team member
            $stmt = $db->prepare("
                INSERT INTO tblPartnerTeamMembers
                (partnerID, userID, role, status, invitedBy, joinedAt)
                VALUES (?, ?, ?, 'active', ?, NOW())
            ");
            $stmt->bind_param('iisi',
                $invitation['partnerID'],
                $userID,
                $invitation['role'],
                $invitation['invitedBy']
            );

            if (!$stmt->execute()) {
                throw new Exception('Failed to add you to the team. Please try again or contact support.');
            }

            // Mark invitation as accepted
            $stmt = $db->prepare("
                UPDATE tblTeamInvitations
                SET status = 'accepted', acceptedAt = NOW(), acceptedBy = ?
                WHERE invitationID = ?
            ");
            $stmt->bind_param('ii', $userID, $invitation['invitationID']);
            $stmt->execute();

            // Log activity
            $accessControl->logAdminAction('accept_invitation', 'partner', $invitation['partnerID'], [
                'userID' => $userID,
                'email' => $userInfo['email'],
                'role' => $invitation['role']
            ]);

            $activityLogger->logActivity(
                'team_invitation_accepted',
                'User accepted team invitation',
                ['partnerID' => $invitation['partnerID'], 'role' => $invitation['role']]
            );

            $success = true;
            $message = 'Welcome to ' . htmlspecialchars($invitation['companyName']) . '! You have been added as a ' . htmlspecialchars($invitation['role']) . '.';

            // Redirect to partner dashboard after 3 seconds
            header("Refresh: 3; url=/partners/dashboard.php?partner=" . $invitation['partnerID']);
        } catch (Exception $e) {
            $message = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Team Invitation - SIGNula</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea, #764ba2);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .invite-card {
            max-width: 600px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .invite-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2rem;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }
        .invite-icon {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
        }
        .role-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            background: rgba(255,255,255,0.2);
            display: inline-block;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="invite-card">
            <?php if ($invitation && !$success): ?>
            <!-- Valid Invitation -->
            <div class="invite-header">
                <div class="invite-icon">
                    <i class="fas fa-envelope-open fa-3x"></i>
                </div>
                <h2>You're Invited!</h2>
                <p class="mb-0">Join <strong><?php echo htmlspecialchars($invitation['companyName']); ?></strong> on SIGNula</p>
                <span class="role-badge"><?php echo strtoupper($invitation['role']); ?></span>
            </div>

            <div class="card-body p-4">
                <?php if ($message): ?>
                <div class="alert alert-<?php echo $success ? 'success' : 'warning'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <?php endif; ?>

                <div class="mb-4">
                    <h5><i class="fas fa-info-circle text-primary me-2"></i>Invitation Details</h5>
                    <ul class="list-unstyled ms-4">
                        <li class="mb-2">
                            <strong>Organization:</strong>
                            <?php echo htmlspecialchars($invitation['companyName']); ?>
                        </li>
                        <li class="mb-2">
                            <strong>Role:</strong>
                            <?php echo ucfirst($invitation['role']); ?>
                        </li>
                        <li class="mb-2">
                            <strong>Email:</strong>
                            <?php echo htmlspecialchars($invitation['email']); ?>
                        </li>
                        <li class="mb-2">
                            <strong>Expires:</strong>
                            <?php echo date('F j, Y g:i A', strtotime($invitation['expiresAt'])); ?>
                        </li>
                    </ul>
                </div>

                <div class="alert alert-info">
                    <small>
                        <i class="fas fa-shield-alt me-1"></i>
                        By accepting this invitation, you'll gain access to <?php echo htmlspecialchars($invitation['companyName']); ?>'s resources based on your assigned role.
                    </small>
                </div>

                <?php if ($sessionManager->isLoggedIn()): ?>
                    <?php
                    $userInfo = $sessionManager->getUserInfo();
                    if (strtolower($userInfo['email']) === strtolower($invitation['email'])):
                    ?>
                    <!-- Logged in with correct account -->
                    <form method="POST">
                        <div class="d-grid gap-2">
                            <button type="submit" name="accept" class="btn btn-primary btn-lg">
                                <i class="fas fa-check me-2"></i>Accept Invitation
                            </button>
                            <a href="/partners/dashboard.php" class="btn btn-outline-secondary">
                                Maybe Later
                            </a>
                        </div>
                    </form>
                    <?php else: ?>
                    <!-- Wrong account -->
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This invitation is for <strong><?php echo htmlspecialchars($invitation['email']); ?></strong>,
                        but you're logged in as <strong><?php echo htmlspecialchars($userInfo['email']); ?></strong>.
                    </div>
                    <div class="d-grid gap-2">
                        <a href="/auth/logout.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-primary">
                            <i class="fas fa-sign-out-alt me-2"></i>Log Out & Sign In With Correct Account
                        </a>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Not logged in -->
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        You need to sign in with a SIGNula account to accept this invitation.
                    </div>
                    <div class="d-grid gap-2">
                        <a href="/auth/login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-primary btn-lg">
                            <i class="fas fa-sign-in-alt me-2"></i>Sign In to Accept
                        </a>
                        <a href="/auth/register.php?email=<?php echo urlencode($invitation['email']); ?>&redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-outline-primary">
                            <i class="fas fa-user-plus me-2"></i>Create Account
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php elseif ($success): ?>
            <!-- Invitation Accepted -->
            <div class="invite-header">
                <div class="invite-icon">
                    <i class="fas fa-check-circle fa-3x"></i>
                </div>
                <h2>Welcome Aboard!</h2>
            </div>

            <div class="card-body p-4 text-center">
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>

                <p class="text-muted">Redirecting you to your dashboard...</p>

                <div class="spinner-border text-primary mt-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>

            <?php else: ?>
            <!-- Invalid/Expired Invitation -->
            <div class="invite-header">
                <div class="invite-icon">
                    <i class="fas fa-exclamation-triangle fa-3x"></i>
                </div>
                <h2>Invalid Invitation</h2>
            </div>

            <div class="card-body p-4">
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle me-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>

                <div class="d-grid gap-2">
                    <a href="/partners/dashboard.php" class="btn btn-primary">
                        <i class="fas fa-home me-2"></i>Go to Dashboard
                    </a>
                    <a href="mailto:support@signulo.id" class="btn btn-outline-secondary">
                        <i class="fas fa-envelope me-2"></i>Contact Support
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="text-center mt-4">
            <a href="https://signulo.com" class="text-white text-decoration-none">
                <small>&copy; 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.</small>
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
