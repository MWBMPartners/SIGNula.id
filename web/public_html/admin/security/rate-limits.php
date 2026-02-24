<?php
/**
 * Rate Limit Monitoring Dashboard
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'RateLimiter.php';

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$rateLimiter = new RateLimiter($db);

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

// Handle unblock action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unblock'])) {
    $identifier = $_POST['identifier'] ?? '';
    $identifierType = $_POST['identifier_type'] ?? 'ip';

    $result = $rateLimiter->unblock($identifier, $identifierType);
    $message = $result['success']
        ? ['type' => 'success', 'text' => 'Successfully unblocked ' . htmlspecialchars($identifier)]
        : ['type' => 'danger', 'text' => 'Failed to unblock: ' . $result['error']];
}

// Get statistics
$stats = [
    'activeBlocks' => $db->query("SELECT COUNT(*) as count FROM tblRateLimits WHERE isBlocked = 1")->fetch_assoc()['count'],
    'requestsToday' => $db->query("SELECT COUNT(*) as count FROM tblRateLimits WHERE DATE(createdAt) = CURDATE()")->fetch_assoc()['count'],
    'violationsToday' => $db->query("SELECT COUNT(*) as count FROM tblRateLimits WHERE isBlocked = 1 AND DATE(createdAt) = CURDATE()")->fetch_assoc()['count'],
    'uniqueIPs' => $db->query("SELECT COUNT(DISTINCT identifier) as count FROM tblRateLimits WHERE identifierType = 'ip' AND DATE(createdAt) = CURDATE()")->fetch_assoc()['count']
];

// Get recent blocks
$recentBlocks = $db->query("
    SELECT *
    FROM tblRateLimits
    WHERE isBlocked = 1
    ORDER BY blockedUntil DESC
    LIMIT 20
");

// Get recent activity (last hour)
$recentActivity = $db->query("
    SELECT identifier, identifierType, endpoint, COUNT(*) as requestCount,
           MAX(createdAt) as lastRequest
    FROM tblRateLimits
    WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    GROUP BY identifier, identifierType, endpoint
    ORDER BY requestCount DESC
    LIMIT 15
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Limit Monitoring - SIGNula Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    <style>
        body { background-color: #f8f9fa; }
        .page-header { background: linear-gradient(135deg, #dc3545, #c82333); color: white; padding: 2rem; border-radius: 10px; margin-bottom: 2rem; }
        .stat-card { border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .block-card { border-left: 4px solid #dc3545; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: all 0.3s; }
        .block-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="page-header">
            <a href="../index.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Admin
            </a>
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1><i class="fas fa-shield-alt me-2"></i>Rate Limit Monitoring</h1>
                    <p class="mb-0 opacity-75">Monitor API rate limits, blocks, and violations</p>
                </div>
                <div class="col-md-4 text-end">
                    <button class="btn btn-light" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-2"></i>Refresh
                    </button>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible">
            <?php echo $message['text']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-danger">
                    <div class="card-body text-center">
                        <h2 class="text-danger mb-2"><?php echo number_format($stats['activeBlocks']); ?></h2>
                        <p class="text-muted mb-0">Active Blocks</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-primary">
                    <div class="card-body text-center">
                        <h2 class="text-primary mb-2"><?php echo number_format($stats['requestsToday']); ?></h2>
                        <p class="text-muted mb-0">Requests Today</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-warning">
                    <div class="card-body text-center">
                        <h2 class="text-warning mb-2"><?php echo number_format($stats['violationsToday']); ?></h2>
                        <p class="text-muted mb-0">Violations Today</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-info">
                    <div class="card-body text-center">
                        <h2 class="text-info mb-2"><?php echo number_format($stats['uniqueIPs']); ?></h2>
                        <p class="text-muted mb-0">Unique IPs Today</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Active Blocks -->
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-ban me-2"></i>Active Blocks</h5>
                    </div>
                    <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                        <?php if ($recentBlocks->num_rows > 0): ?>
                            <?php while ($block = $recentBlocks->fetch_assoc()): ?>
                            <div class="block-card card mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1">
                                                <i class="fas fa-<?php echo $block['identifierType'] === 'ip' ? 'network-wired' : 'user'; ?> me-2"></i>
                                                <code><?php echo htmlspecialchars($block['identifier']); ?></code>
                                            </h6>
                                            <small class="text-muted d-block">
                                                Type: <?php echo strtoupper($block['identifierType']); ?> |
                                                Violations: <?php echo $block['violationCount']; ?> |
                                                Endpoint: <code><?php echo htmlspecialchars($block['endpoint'] ?: 'global'); ?></code>
                                            </small>
                                            <small class="text-muted d-block">
                                                Blocked until: <?php echo date('M j, Y H:i', strtotime($block['blockedUntil'])); ?>
                                            </small>
                                            <?php if ($block['blockReason']): ?>
                                            <small class="text-danger d-block mt-1">
                                                <strong>Reason:</strong> <?php echo htmlspecialchars($block['blockReason']); ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                        <form method="POST" class="ms-3">
                                            <input type="hidden" name="identifier" value="<?php echo htmlspecialchars($block['identifier']); ?>">
                                            <input type="hidden" name="identifier_type" value="<?php echo htmlspecialchars($block['identifierType']); ?>">
                                            <button type="submit" name="unblock" class="btn btn-sm btn-success" onclick="return confirm('Unblock this <?php echo $block['identifierType']; ?>?')">
                                                <i class="fas fa-unlock me-1"></i>Unblock
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="text-muted text-center py-4">
                                <i class="fas fa-check-circle text-success fa-3x mb-3"></i><br>
                                No active blocks. System is running smoothly!
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Recent Activity (Last Hour)</h5>
                    </div>
                    <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                        <?php if ($recentActivity->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Identifier</th>
                                            <th>Type</th>
                                            <th>Endpoint</th>
                                            <th class="text-end">Requests</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($activity = $recentActivity->fetch_assoc()): ?>
                                        <tr>
                                            <td><code class="small"><?php echo htmlspecialchars(substr($activity['identifier'], 0, 20)); ?></code></td>
                                            <td><span class="badge bg-secondary"><?php echo strtoupper($activity['identifierType']); ?></span></td>
                                            <td><code class="small"><?php echo htmlspecialchars($activity['endpoint'] ?: 'global'); ?></code></td>
                                            <td class="text-end">
                                                <strong class="<?php echo $activity['requestCount'] > 100 ? 'text-warning' : ''; ?>">
                                                    <?php echo number_format($activity['requestCount']); ?>
                                                </strong>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center py-4">No recent activity in the last hour.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Configuration -->
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-cog me-2"></i>Rate Limit Configuration</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <?php
                    $configs = $db->query("SELECT * FROM tblRateLimitConfig ORDER BY identifierType, tier");
                    ?>
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Tier</th>
                                <th>Endpoint</th>
                                <th class="text-end">Hourly</th>
                                <th class="text-end">Per Minute</th>
                                <th class="text-end">Burst</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($config = $configs->fetch_assoc()): ?>
                            <tr>
                                <td><span class="badge bg-info"><?php echo strtoupper($config['identifierType']); ?></span></td>
                                <td><span class="badge bg-secondary"><?php echo strtoupper($config['tier']); ?></span></td>
                                <td><code><?php echo $config['endpoint'] ?: 'global'; ?></code></td>
                                <td class="text-end"><?php echo number_format($config['requestsPerHour']); ?></td>
                                <td class="text-end"><?php echo number_format($config['requestsPerMinute']); ?></td>
                                <td class="text-end"><?php echo number_format($config['burstLimit']); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => location.reload(), 30000);
    </script>
</body>
</html>
