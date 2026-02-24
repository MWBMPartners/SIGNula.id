<?php
/**
 * Partner Dashboard
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

$db = Database::getInstance();
$sessionManager = new SessionManager($db);

// Check if logged in
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$userID = $sessionManager->getUserID();

// Get partner info
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE userID = ? OR email = (SELECT email FROM tblUsers WHERE userID = ?)");
$stmt->bind_param('ii', $userID, $userID);
$stmt->execute();
$partner = $stmt->get_result()->fetch_assoc();

if (!$partner) {
    header('Location: register.php');
    exit;
}

// Get API keys count
$stmt = $db->prepare("SELECT COUNT(*) as count FROM tblAPIKeys WHERE partnerID = ? AND status = 'active'");
$stmt->bind_param('i', $partner['partnerID']);
$stmt->execute();
$apiKeysCount = $stmt->get_result()->fetch_assoc()['count'];

// Get usage today
$stmt = $db->prepare("
    SELECT COUNT(*) as count
    FROM tblAPIKeyUsage u
    JOIN tblAPIKeys k ON u.apiKeyID = k.apiKeyID
    WHERE k.partnerID = ? AND DATE(u.usedAt) = CURDATE()
");
$stmt->bind_param('i', $partner['partnerID']);
$stmt->execute();
$usageToday = $stmt->get_result()->fetch_assoc()['count'];

// Get tier limits
$tierLimits = [
    'free' => ['requests' => 1000, 'keys' => 2],
    'basic' => ['requests' => 10000, 'keys' => 5],
    'premium' => ['requests' => 50000, 'keys' => 10],
    'enterprise' => ['requests' => 100000, 'keys' => 'Unlimited']
];

$currentTier = $tierLimits[$partner['tier']] ?? $tierLimits['free'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner Dashboard - SIGNula</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    <style>
        body { background-color: #f8f9fa; }
        .dashboard-header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 2rem; border-radius: 10px; }
        .stat-card { border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .status-badge { padding: 0.5rem 1rem; border-radius: 20px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container py-4">
        <!-- Header -->
        <div class="dashboard-header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="fas fa-tachometer-alt me-2"></i>Partner Dashboard</h1>
                    <p class="mb-0 opacity-75">Welcome back, <?php echo htmlspecialchars($partner['companyName']); ?></p>
                </div>
                <div>
                    <?php
                    $statusColors = ['pending' => 'warning', 'active' => 'success', 'suspended' => 'danger'];
                    $statusColor = $statusColors[$partner['status']] ?? 'secondary';
                    ?>
                    <span class="status-badge bg-<?php echo $statusColor; ?> text-white">
                        <?php echo strtoupper($partner['status']); ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if ($partner['status'] === 'pending'): ?>
        <div class="alert alert-warning">
            <i class="fas fa-clock me-2"></i><strong>Account Pending Approval</strong>
            <p class="mb-0 mt-2">Your partner application is under review. You'll receive an email once approved.</p>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-primary">
                    <div class="card-body text-center">
                        <h3 class="text-primary"><?php echo $apiKeysCount; ?></h3>
                        <p class="text-muted mb-0">Active API Keys</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-success">
                    <div class="card-body text-center">
                        <h3 class="text-success"><?php echo number_format($usageToday); ?></h3>
                        <p class="text-muted mb-0">Requests Today</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-info">
                    <div class="card-body text-center">
                        <h3 class="text-info"><?php echo ucfirst($partner['tier']); ?></h3>
                        <p class="text-muted mb-0">Current Tier</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-warning">
                    <div class="card-body text-center">
                        <h3 class="text-warning"><?php echo number_format($currentTier['requests']); ?></h3>
                        <p class="text-muted mb-0">Monthly Limit</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-key me-2 text-primary"></i>API Keys</h5>
                        <p class="text-muted">Manage your API keys, generate new keys, and view usage statistics.</p>
                        <a href="api-keys.php" class="btn btn-primary">
                            <i class="fas fa-cog me-2"></i>Manage API Keys
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-chart-line me-2 text-success"></i>Usage Analytics</h5>
                        <p class="text-muted">View detailed analytics and usage statistics for your API integration.</p>
                        <a href="usage.php" class="btn btn-success">
                            <i class="fas fa-chart-bar me-2"></i>View Analytics
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-book me-2 text-info"></i>Documentation</h5>
                        <p class="text-muted">Access API documentation, code examples, and integration guides.</p>
                        <a href="/api/docs/" class="btn btn-info text-white">
                            <i class="fas fa-file-alt me-2"></i>View Docs
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-life-ring me-2 text-warning"></i>Support</h5>
                        <p class="text-muted">Get help with integration, report issues, or request features.</p>
                        <a href="/support/" class="btn btn-warning">
                            <i class="fas fa-question-circle me-2"></i>Get Support
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="card mt-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent API Activity</h5>
            </div>
            <div class="card-body">
                <?php
                $stmt = $db->prepare("
                    SELECT u.*, k.keyName
                    FROM tblAPIKeyUsage u
                    JOIN tblAPIKeys k ON u.apiKeyID = k.apiKeyID
                    WHERE k.partnerID = ?
                    ORDER BY u.usedAt DESC
                    LIMIT 10
                ");
                $stmt->bind_param('i', $partner['partnerID']);
                $stmt->execute();
                $recentActivity = $stmt->get_result();

                if ($recentActivity->num_rows > 0):
                ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>API Key</th>
                                <th>Endpoint</th>
                                <th>Response Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($activity = $recentActivity->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo date('M j, Y H:i:s', strtotime($activity['usedAt'])); ?></td>
                                <td><?php echo htmlspecialchars($activity['keyName']); ?></td>
                                <td><code><?php echo htmlspecialchars($activity['endpoint']); ?></code></td>
                                <td><?php echo $activity['responseTime']; ?>ms</td>
                                <td>
                                    <span class="badge bg-<?php echo $activity['responseCode'] < 400 ? 'success' : 'danger'; ?>">
                                        <?php echo $activity['responseCode']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted text-center py-4">No recent activity. Start using your API keys to see activity here.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
