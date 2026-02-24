<?php
/**
 * Transfer Partner Ownership (Root Admin Only)
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

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

// Verify ROOT admin access (not just admin)
if (!$accessControl->isPartnerRootAdmin($selectedPartnerID)) {
    http_response_code(403);
    die('Root Admin access required. Only the organization owner can transfer ownership.');
}

// Get partner details
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE partnerID = ?");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerDetails = $stmt->get_result()->fetch_assoc();

// Get eligible team members (active admins and developers)
$stmt = $db->prepare("
    SELECT tm.*, u.username, u.email, u.createdAt as userCreatedAt
    FROM tblPartnerTeamMembers tm
    JOIN tblUsers u ON tm.userID = u.userID
    WHERE tm.partnerID = ?
    AND tm.status = 'active'
    AND tm.isRootAdmin = FALSE
    AND tm.role IN ('admin', 'developer')
    ORDER BY tm.role DESC, u.username
");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$eligibleMembers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$message = '';
$success = false;

// Handle transfer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer'])) {
    $newOwnerID = $_POST['newOwnerID'] ?? 0;
    $confirmation = $_POST['confirmation'] ?? '';
    $currentUserID = $sessionManager->getUserID();

    if ($confirmation !== $partnerDetails['companyName']) {
        $message = 'Organization name confirmation does not match. Please type the exact name to confirm.';
    } elseif ($newOwnerID == $currentUserID) {
        $message = 'You cannot transfer ownership to yourself. You are already the owner.';
    } else {
        // Verify new owner is a team member
        $stmt = $db->prepare("
            SELECT memberID, role FROM tblPartnerTeamMembers
            WHERE partnerID = ? AND userID = ? AND status = 'active'
        ");
        $stmt->bind_param('ii', $selectedPartnerID, $newOwnerID);
        $stmt->execute();
        $newOwner = $stmt->get_result()->fetch_assoc();

        if (!$newOwner) {
            $message = 'Selected user is not an active team member.';
        } else {
            try {
                // Begin transaction
                $db->begin_transaction();

                // Remove root admin from current owner (becomes regular admin)
                $stmt = $db->prepare("
                    UPDATE tblPartnerTeamMembers
                    SET isRootAdmin = FALSE, role = 'admin'
                    WHERE partnerID = ? AND userID = ?
                ");
                $stmt->bind_param('ii', $selectedPartnerID, $currentUserID);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update current owner role');
                }

                // Assign root admin to new owner
                $stmt = $db->prepare("
                    UPDATE tblPartnerTeamMembers
                    SET isRootAdmin = TRUE, role = 'root-admin'
                    WHERE partnerID = ? AND userID = ?
                ");
                $stmt->bind_param('ii', $selectedPartnerID, $newOwnerID);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to assign new owner');
                }

                // Commit transaction
                $db->commit();

                // Log action
                $accessControl->logAdminAction('transfer_ownership', 'partner', $selectedPartnerID, [
                    'from_userID' => $currentUserID,
                    'to_userID' => $newOwnerID,
                    'companyName' => $partnerDetails['companyName']
                ]);

                $success = true;
                $message = 'Ownership transferred successfully! Redirecting...';

                // Redirect to dashboard after 3 seconds
                header("Refresh: 3; url=/partners/dashboard.php?partner=" . $selectedPartnerID);
            } catch (Exception $e) {
                $db->rollback();
                $message = 'Transfer failed: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Ownership - <?php echo htmlspecialchars($partner['companyName']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    <style>
        body { background-color: #f8f9fa; }
        .transfer-header {
            background: linear-gradient(135deg, #eb3349, #f45c43);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        .warning-box {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .danger-box {
            background: #f8d7da;
            border: 2px solid #dc3545;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .member-card {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        .member-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .member-card.selected {
            border-color: #667eea;
            background: #f0f4ff;
        }
        .member-card input[type="radio"] {
            transform: scale(1.3);
            cursor: pointer;
        }
        .confirmation-input {
            font-family: monospace;
            font-weight: bold;
            border: 2px solid #dc3545;
        }
    </style>
</head>
<body>
    <div class="container py-4" style="max-width: 900px;">
        <!-- Header -->
        <div class="transfer-header">
            <a href="index.php?partner=<?php echo $selectedPartnerID; ?>" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
            </a>
            <h1><i class="fas fa-crown me-2"></i>Transfer Ownership</h1>
            <p class="mb-0 opacity-75"><?php echo htmlspecialchars($partner['companyName']); ?></p>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $success ? 'success' : 'danger'; ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <!-- Danger Warning -->
        <div class="danger-box">
            <h4 class="text-danger mb-3">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>CRITICAL ACTION</strong>
            </h4>
            <p class="mb-2"><strong>This action will:</strong></p>
            <ul class="mb-3">
                <li>Transfer full ownership of <strong><?php echo htmlspecialchars($partnerDetails['companyName']); ?></strong> to another team member</li>
                <li>Remove your Root Admin status (you will become a regular Admin)</li>
                <li>Grant the new owner complete control over the organization</li>
                <li>This action <strong>CANNOT be undone</strong> by you once completed</li>
            </ul>
            <p class="mb-0 text-danger">
                <i class="fas fa-shield-alt me-2"></i>
                <strong>Only proceed if you are absolutely certain!</strong>
            </p>
        </div>

        <?php if (empty($eligibleMembers)): ?>
        <!-- No Eligible Members -->
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-users fa-4x text-muted mb-3"></i>
                <h4>No Eligible Team Members</h4>
                <p class="text-muted mb-4">
                    You need at least one Admin or Developer on your team to transfer ownership.
                </p>
                <a href="team.php?partner=<?php echo $selectedPartnerID; ?>" class="btn btn-primary">
                    <i class="fas fa-user-plus me-2"></i>Invite Team Members
                </a>
            </div>
        </div>

        <?php else: ?>
        <!-- Transfer Form -->
        <form method="POST" id="transferForm" onsubmit="return confirmTransfer()">
            <!-- Select New Owner -->
            <div class="card mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-user-check me-2"></i>Select New Owner</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Choose a trusted team member to become the new owner:</p>

                    <?php foreach ($eligibleMembers as $member): ?>
                    <label class="member-card" onclick="selectMember(<?php echo $member['memberID']; ?>)">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <input type="radio"
                                       name="newOwnerID"
                                       value="<?php echo $member['userID']; ?>"
                                       id="member_<?php echo $member['memberID']; ?>"
                                       required>
                            </div>
                            <div class="col">
                                <strong><?php echo htmlspecialchars($member['username']); ?></strong>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars($member['email']); ?></small>
                            </div>
                            <div class="col-auto">
                                <span class="badge bg-<?php echo $member['role'] == 'admin' ? 'primary' : 'info'; ?>">
                                    <?php echo strtoupper($member['role']); ?>
                                </span>
                                <br>
                                <small class="text-muted">Joined: <?php echo date('M Y', strtotime($member['joinedAt'])); ?></small>
                            </div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Confirmation -->
            <div class="card mb-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Confirmation Required</h5>
                </div>
                <div class="card-body">
                    <p class="mb-3">
                        To confirm this transfer, type your organization name exactly as shown:
                    </p>
                    <div class="alert alert-warning mb-3">
                        <code style="font-size: 1.1rem;"><?php echo htmlspecialchars($partnerDetails['companyName']); ?></code>
                    </div>
                    <input type="text"
                           name="confirmation"
                           id="confirmationInput"
                           class="form-control confirmation-input"
                           placeholder="Type organization name here..."
                           required
                           autocomplete="off">
                    <small class="text-danger">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        Name must match exactly (case-sensitive)
                    </small>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="d-grid gap-2">
                <button type="submit" name="transfer" class="btn btn-danger btn-lg" id="transferBtn" disabled>
                    <i class="fas fa-exchange-alt me-2"></i>Transfer Ownership
                </button>
                <a href="index.php?partner=<?php echo $selectedPartnerID; ?>" class="btn btn-outline-secondary btn-lg">
                    <i class="fas fa-times me-2"></i>Cancel
                </a>
            </div>
        </form>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Additional Info -->
        <div class="card mt-4">
            <div class="card-body">
                <h6 class="mb-3"><i class="fas fa-info-circle me-2"></i>What Happens After Transfer?</h6>
                <ul class="mb-0">
                    <li>The new owner will have full Root Admin privileges</li>
                    <li>You will retain Admin access unless the new owner changes it</li>
                    <li>The new owner can manage all team members, including you</li>
                    <li>Only the new owner will be able to transfer ownership again</li>
                    <li>All actions are logged in the audit trail</li>
                </ul>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <script>
        const requiredName = <?php echo json_encode($partnerDetails['companyName']); ?>;
        const confirmationInput = document.getElementById('confirmationInput');
        const transferBtn = document.getElementById('transferBtn');

        // Enable/disable transfer button based on confirmation
        confirmationInput.addEventListener('input', function() {
            if (this.value === requiredName) {
                transferBtn.disabled = false;
                transferBtn.classList.remove('btn-secondary');
                transferBtn.classList.add('btn-danger');
            } else {
                transferBtn.disabled = true;
                transferBtn.classList.remove('btn-danger');
                transferBtn.classList.add('btn-secondary');
            }
        });

        function selectMember(memberID) {
            // Remove selected class from all cards
            document.querySelectorAll('.member-card').forEach(card => {
                card.classList.remove('selected');
            });

            // Add selected class to clicked card
            const card = document.querySelector(`label[onclick*="${memberID}"]`);
            if (card) {
                card.classList.add('selected');
            }

            // Check the radio button
            const radio = document.getElementById(`member_${memberID}`);
            if (radio) {
                radio.checked = true;
            }
        }

        function confirmTransfer() {
            const selectedMember = document.querySelector('input[name="newOwnerID"]:checked');

            if (!selectedMember) {
                alert('Please select a team member to transfer ownership to.');
                return false;
            }

            if (confirmationInput.value !== requiredName) {
                alert('Organization name confirmation does not match. Please type the exact name.');
                confirmationInput.focus();
                return false;
            }

            const memberCard = selectedMember.closest('.member-card');
            const memberName = memberCard.querySelector('strong').textContent;

            return confirm(
                `⚠️ FINAL CONFIRMATION ⚠️\n\n` +
                `You are about to transfer ownership of "${requiredName}" to "${memberName}".\n\n` +
                `This action CANNOT be undone by you.\n\n` +
                `Are you absolutely sure you want to proceed?`
            );
        }
    </script>
</body>
</html>
