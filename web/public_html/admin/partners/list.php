<?php
/**
 * Admin Partner Management
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 3) . '/_config/config.php';
require_once dirname(__DIR__, 3) . '/_backend/Database.php';
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';

$db = Database::getInstance();
$sessionManager = new SessionManager($db);

// Check admin
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

$userInfo = $sessionManager->getUserInfo();
if (!isset($userInfo['isAdmin']) || $userInfo['isAdmin'] != 1) {
    http_response_code(403);
    die('Access denied.');
}

$message = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $partnerID = (int)($_POST['partner_id'] ?? 0);

    if ($action === 'approve') {
        $db->query("UPDATE tblPartners SET status = 'active' WHERE partnerID = $partnerID");
        $message = ['type' => 'success', 'text' => 'Partner approved'];
    } elseif ($action === 'suspend') {
        $db->query("UPDATE tblPartners SET status = 'suspended' WHERE partnerID = $partnerID");
        $message = ['type' => 'warning', 'text' => 'Partner suspended'];
    } elseif ($action === 'change_tier') {
        $tier = $_POST['tier'] ?? 'free';
        $stmt = $db->prepare("UPDATE tblPartners SET tier = ? WHERE partnerID = ?");
        $stmt->bind_param('si', $tier, $partnerID);
        $stmt->execute();
        $message = ['type' => 'success', 'text' => 'Tier updated'];
    }
}

// Get all partners
$partners = $db->query("
    SELECT p.*,
           (SELECT COUNT(*) FROM tblAPIKeys WHERE partnerID = p.partnerID AND status = 'active') as apiKeyCount,
           (SELECT COUNT(*) FROM tblAPIKeyUsage u
            JOIN tblAPIKeys k ON u.apiKeyID = k.apiKeyID
            WHERE k.partnerID = p.partnerID AND DATE(u.usedAt) = CURDATE()) as usageToday
    FROM tblPartners p
    ORDER BY p.createdAt DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner Management - SIGNula Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    <style>
        body { background-color: #f8f9fa; }
        .page-header { background: linear-gradient(135deg, #0066cc, #004494); color: white; padding: 2rem; border-radius: 10px; margin-bottom: 2rem; }
        .partner-card { border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: all 0.3s; }
        .partner-card:hover { transform: translateY(-3px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .status-pending { border-left: 4px solid #ffc107; }
        .status-active { border-left: 4px solid #28a745; }
        .status-suspended { border-left: 4px solid #dc3545; }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="page-header">
            <a href="../index.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Admin
            </a>
            <h1><i class="fas fa-users me-2"></i>Partner Management</h1>
            <p class="mb-0 opacity-75">Approve, manage, and monitor partner accounts</p>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible">
            <?php echo htmlspecialchars($message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row mb-4">
            <?php
            $stats = $db->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended
                FROM tblPartners
            ")->fetch_assoc();
            ?>
            <div class="col-md-3 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 class="text-primary"><?php echo $stats['total']; ?></h3>
                        <p class="text-muted mb-0">Total Partners</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 class="text-warning"><?php echo $stats['pending']; ?></h3>
                        <p class="text-muted mb-0">Pending Approval</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 class="text-success"><?php echo $stats['active']; ?></h3>
                        <p class="text-muted mb-0">Active</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 class="text-danger"><?php echo $stats['suspended']; ?></h3>
                        <p class="text-muted mb-0">Suspended</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Partners List -->
        <h4 class="mb-3">All Partners</h4>
        <?php while ($partner = $partners->fetch_assoc()): ?>
        <div class="card partner-card status-<?php echo $partner['status']; ?> mb-3">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <h5 class="mb-1"><?php echo htmlspecialchars($partner['companyName']); ?></h5>
                        <p class="text-muted mb-0">
                            <i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($partner['email']); ?>
                        </p>
                        <p class="text-muted mb-0">
                            <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($partner['contactName']); ?>
                        </p>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted d-block">Tier</small>
                        <span class="badge bg-info"><?php echo strtoupper($partner['tier']); ?></span>
                        <small class="text-muted d-block mt-2">Status</small>
                        <span class="badge bg-<?php echo $partner['status'] === 'active' ? 'success' : ($partner['status'] === 'pending' ? 'warning' : 'danger'); ?>">
                            <?php echo strtoupper($partner['status']); ?>
                        </span>
                    </div>
                    <div class="col-md-2 text-center">
                        <h5 class="mb-0"><?php echo $partner['apiKeyCount']; ?></h5>
                        <small class="text-muted">API Keys</small>
                        <h5 class="mb-0 mt-2"><?php echo number_format($partner['usageToday']); ?></h5>
                        <small class="text-muted">Requests Today</small>
                    </div>
                    <div class="col-md-3 text-end">
                        <?php if ($partner['status'] === 'pending'): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="partner_id" value="<?php echo $partner['partnerID']; ?>">
                            <button type="submit" class="btn btn-success btn-sm">
                                <i class="fas fa-check me-1"></i>Approve
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if ($partner['status'] === 'active'): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="suspend">
                            <input type="hidden" name="partner_id" value="<?php echo $partner['partnerID']; ?>">
                            <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Suspend this partner?')">
                                <i class="fas fa-pause me-1"></i>Suspend
                            </button>
                        </form>
                        <?php endif; ?>

                        <div class="btn-group mt-2">
                            <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                Change Tier
                            </button>
                            <ul class="dropdown-menu">
                                <?php foreach (['free', 'basic', 'premium', 'enterprise'] as $tier): ?>
                                <li>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="change_tier">
                                        <input type="hidden" name="partner_id" value="<?php echo $partner['partnerID']; ?>">
                                        <input type="hidden" name="tier" value="<?php echo $tier; ?>">
                                        <button type="submit" class="dropdown-item"><?php echo ucfirst($tier); ?></button>
                                    </form>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php if ($partner['description']): ?>
                <div class="mt-3 pt-3 border-top">
                    <small class="text-muted"><strong>Description:</strong> <?php echo htmlspecialchars($partner['description']); ?></small>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endwhile; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
</body>
</html>
