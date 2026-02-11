<?php
/**
 * Partner Admin Dashboard
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

// Verify access
if (!$accessControl->isPartnerAdmin($selectedPartnerID)) {
    http_response_code(403);
    die('Admin access required for this partner');
}

// Get partner details
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE partnerID = ?");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerDetails = $stmt->get_result()->fetch_assoc();

// Get team member count
$stmt = $db->prepare("
    SELECT COUNT(*) as count FROM tblPartnerTeamMembers
    WHERE partnerID = ? AND status = 'active'
");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$teamCount = $stmt->get_result()->fetch_assoc()['count'];

// Get API key count
$stmt = $db->prepare("
    SELECT COUNT(*) as count FROM tblAPIKeys
    WHERE partnerID = ? AND status = 'active'
");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$apiKeyCount = $stmt->get_result()->fetch_assoc()['count'];

// Get pending invitations
$stmt = $db->prepare("
    SELECT COUNT(*) as count FROM tblTeamInvitations
    WHERE partnerID = ? AND status = 'pending' AND expiresAt > NOW()
");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$pendingInvites = $stmt->get_result()->fetch_assoc()['count'];

// Get team size limit
$maxTeamMembers = $accessControl->getMaxTeamMembers($partnerDetails['tier']);

// Check enabled features
$features = ['rate_limiting', 'api_keys', 'email_campaigns', 'usage_analytics'];
$enabledFeatures = [];
foreach ($features as $feature) {
    $enabledFeatures[$feature] = $accessControl->isFeatureEnabled($feature, $selectedPartnerID);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner Admin - <?php echo htmlspecialchars($partner['companyName']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    <style>
        body { background-color: #f8f9fa; }
        .admin-header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 2rem; border-radius: 10px; margin-bottom: 2rem; }
        .feature-card { border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: all 0.3s; height: 100%; }
        .feature-card:hover { transform: translateY(-3px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .stat-card { border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .partner-selector { background: rgba(255,255,255,0.2); border: none; color: white; border-radius: 5px; padding: 0.5rem 1rem; }
        .partner-selector option { color: #333; }
        .role-badge { padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="admin-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <a href="../dashboard.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                        <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                    </a>
                    <h1><i class="fas fa-user-cog me-2"></i>Partner Administration</h1>

                    <?php if (count($memberships) > 1): ?>
                    <div class="mt-3">
                        <label class="text-white opacity-75 mb-2">Select Organization:</label>
                        <select class="partner-selector form-select" onchange="window.location.href='?partner='+this.value">
                            <?php foreach ($memberships as $pid => $p): ?>
                            <option value="<?php echo $pid; ?>" <?php echo $pid == $selectedPartnerID ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['companyName']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <p class="mb-0 opacity-75 mt-2"><?php echo htmlspecialchars($partner['companyName']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-end">
                    <span class="role-badge bg-white text-primary">
                        <?php echo $partner['isRootAdmin'] ? '👑 Root Admin' : strtoupper($partner['role']); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-primary">
                    <div class="card-body text-center">
                        <h3 class="text-primary"><?php echo $teamCount; ?> / <?php echo $maxTeamMembers ?: '∞'; ?></h3>
                        <p class="text-muted mb-0">Team Members</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-success">
                    <div class="card-body text-center">
                        <h3 class="text-success"><?php echo $apiKeyCount; ?></h3>
                        <p class="text-muted mb-0">Active API Keys</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-warning">
                    <div class="card-body text-center">
                        <h3 class="text-warning"><?php echo $pendingInvites; ?></h3>
                        <p class="text-muted mb-0">Pending Invites</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-info">
                    <div class="card-body text-center">
                        <h3 class="text-info"><?php echo strtoupper($partnerDetails['tier']); ?></h3>
                        <p class="text-muted mb-0">Current Tier</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Admin Actions -->
        <h4 class="mb-3">Administration</h4>
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <a href="team.php?partner=<?php echo $selectedPartnerID; ?>" class="text-decoration-none">
                    <div class="card feature-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="me-3" style="width: 50px; height: 50px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-users text-white fa-2x"></i>
                                </div>
                                <h5 class="mb-0">Team Management</h5>
                            </div>
                            <p class="text-muted mb-3">Invite members, assign roles, manage access</p>
                            <span class="badge bg-primary"><?php echo $teamCount; ?> Members</span>
                            <?php if ($pendingInvites > 0): ?>
                            <span class="badge bg-warning"><?php echo $pendingInvites; ?> Pending</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-4 mb-3">
                <a href="../api-keys.php" class="text-decoration-none">
                    <div class="card feature-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="me-3" style="width: 50px; height: 50px; background: linear-gradient(135deg, #11998e, #38ef7d); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-key text-white fa-2x"></i>
                                </div>
                                <h5 class="mb-0">API Keys</h5>
                            </div>
                            <p class="text-muted mb-3">Generate and manage API keys</p>
                            <span class="badge bg-success"><?php echo $apiKeyCount; ?> Active</span>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-4 mb-3">
                <a href="features.php?partner=<?php echo $selectedPartnerID; ?>" class="text-decoration-none">
                    <div class="card feature-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="me-3" style="width: 50px; height: 50px; background: linear-gradient(135deg, #fa709a, #fee140); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-toggle-on text-white fa-2x"></i>
                                </div>
                                <h5 class="mb-0">Feature Toggles</h5>
                            </div>
                            <p class="text-muted mb-3">Enable/disable features for your org</p>
                            <span class="badge bg-success"><?php echo count(array_filter($enabledFeatures)); ?> Enabled</span>
                        </div>
                    </div>
                </a>
            </div>

            <?php if ($partner['isRootAdmin']): ?>
            <div class="col-md-4 mb-3">
                <a href="transfer-ownership.php?partner=<?php echo $selectedPartnerID; ?>" class="text-decoration-none">
                    <div class="card feature-card border-danger">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="me-3" style="width: 50px; height: 50px; background: linear-gradient(135deg, #eb3349, #f45c43); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-crown text-white fa-2x"></i>
                                </div>
                                <h5 class="mb-0">Transfer Ownership</h5>
                            </div>
                            <p class="text-muted mb-3">Transfer root admin to another member</p>
                            <span class="badge bg-danger">Root Admin Only</span>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <div class="col-md-4 mb-3">
                <a href="../usage.php" class="text-decoration-none">
                    <div class="card feature-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="me-3" style="width: 50px; height: 50px; background: linear-gradient(135deg, #4facfe, #00f2fe); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-chart-line text-white fa-2x"></i>
                                </div>
                                <h5 class="mb-0">Usage Analytics</h5>
                            </div>
                            <p class="text-muted mb-3">View detailed usage statistics</p>
                            <span class="badge bg-info">Reports</span>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-4 mb-3">
                <a href="settings.php?partner=<?php echo $selectedPartnerID; ?>" class="text-decoration-none">
                    <div class="card feature-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="me-3" style="width: 50px; height: 50px; background: linear-gradient(135deg, #a8edea, #fed6e3); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-cog text-dark fa-2x"></i>
                                </div>
                                <h5 class="mb-0">Organization Settings</h5>
                            </div>
                            <p class="text-muted mb-3">Update company details and preferences</p>
                            <span class="badge bg-secondary">Settings</span>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Feature Status -->
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-list-check me-2"></i>Feature Status</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <i class="fas fa-<?php echo $enabledFeatures['rate_limiting'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> me-2"></i>
                                Rate Limiting
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-<?php echo $enabledFeatures['api_keys'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> me-2"></i>
                                API Key Management
                            </li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <i class="fas fa-<?php echo $enabledFeatures['email_campaigns'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> me-2"></i>
                                Email Campaigns
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-<?php echo $enabledFeatures['usage_analytics'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> me-2"></i>
                                Usage Analytics
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
</body>
</html>
