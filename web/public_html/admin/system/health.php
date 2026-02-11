<?php
/**
 * System Health Dashboard
 *
 * Purpose: Monitor system health and security features status
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Admin
 * @version    1.0.0
 * @since      2.2.0-beta
 */

require_once dirname(__DIR__, 3) . '/_config/config.php';
require_once dirname(__DIR__, 3) . '/_backend/Database.php';
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';

// Initialize
$db = Database::getInstance();
$sessionManager = new SessionManager($db);

// Check admin
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$userInfo = $sessionManager->getUserInfo();
if (!isset($userInfo['isAdmin']) || $userInfo['isAdmin'] != 1) {
    http_response_code(403);
    die('Access denied.');
}

/**
 * Check if table exists
 */
function tableExists($tableName) {
    global $db;
    $result = $db->query("SHOW TABLES LIKE '$tableName'");
    return $result && $result->num_rows > 0;
}

/**
 * Get table count
 */
function getTableCount($tableName) {
    global $db;
    if (!tableExists($tableName)) return 0;

    $result = $db->query("SELECT COUNT(*) as count FROM $tableName");
    return $result ? $result->fetch_assoc()['count'] : 0;
}

// Gather system health data
$healthData = [
    'database' => [
        'connected' => $db->ping(),
        'tables' => []
    ],
    'security' => [
        'rateLimiting' => tableExists('tblRateLimits') && tableExists('tblRateLimitConfig'),
        'apiKeys' => tableExists('tblAPIKeys') && tableExists('tblPartners'),
        'activeBlocks' => 0,
        'totalPartners' => 0
    ],
    'stats' => [
        'totalUsers' => getTableCount('tblUsers'),
        'activeRateLimits' => 0,
        'apiKeyUsageToday' => 0
    ]
];

// Get active blocks
if (tableExists('tblRateLimits')) {
    $result = $db->query("SELECT COUNT(*) as count FROM tblRateLimits WHERE isBlocked = 1");
    $healthData['security']['activeBlocks'] = $result ? $result->fetch_assoc()['count'] : 0;
}

// Get total partners
if (tableExists('tblPartners')) {
    $result = $db->query("SELECT COUNT(*) as count FROM tblPartners WHERE status = 'active'");
    $healthData['security']['totalPartners'] = $result ? $result->fetch_assoc()['count'] : 0;
}

// Get active rate limits (last hour)
if (tableExists('tblRateLimits')) {
    $result = $db->query("SELECT COUNT(*) as count FROM tblRateLimits WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $healthData['stats']['activeRateLimits'] = $result ? $result->fetch_assoc()['count'] : 0;
}

// Get API usage today
if (tableExists('tblAPIKeyUsage')) {
    $result = $db->query("SELECT COUNT(*) as count FROM tblAPIKeyUsage WHERE DATE(usedAt) = CURDATE()");
    $healthData['stats']['apiKeyUsageToday'] = $result ? $result->fetch_assoc()['count'] : 0;
}

// Calculate security score
$securityScore = 0;
$maxScore = 100;

if ($healthData['database']['connected']) $securityScore += 20;
if ($healthData['security']['rateLimiting']) $securityScore += 40;
if ($healthData['security']['apiKeys']) $securityScore += 40;

$pageTitle = 'System Health';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - SIGNula Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">

    <style>
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        .page-header {
            background: linear-gradient(135deg, #0066cc, #004494);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .health-card {
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: none;
        }

        .health-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.15);
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 1.5rem;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
        }

        .security-score {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: bold;
            margin: 0 auto;
        }

        .score-excellent { background: linear-gradient(135deg, #11998e, #38ef7d); color: white; }
        .score-good { background: linear-gradient(135deg, #f093fb, #f5576c); color: white; }
        .score-warning { background: linear-gradient(135deg, #fa709a, #fee140); color: white; }

        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }

        .status-online { background-color: #28a745; }
        .status-offline { background-color: #dc3545; }
    </style>
</head>
<body>
    <div class="container py-4">
        <!-- Page Header -->
        <div class="page-header">
            <a href="../index.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
            </a>
            <h1 class="mb-2"><i class="fas fa-heartbeat me-2"></i><?php echo htmlspecialchars($pageTitle); ?></h1>
            <p class="mb-0 opacity-75">Monitor system status and security features</p>
        </div>

        <!-- Security Score -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card health-card">
                    <div class="card-body text-center py-5">
                        <h3 class="mb-4">Security Score</h3>
                        <div class="security-score <?php
                            if ($securityScore >= 90) echo 'score-excellent';
                            elseif ($securityScore >= 70) echo 'score-good';
                            else echo 'score-warning';
                        ?>">
                            <?php echo $securityScore; ?>%
                        </div>
                        <p class="mt-4 text-muted mb-0">
                            <?php
                            if ($securityScore >= 90) echo '✅ Excellent - All security features active';
                            elseif ($securityScore >= 70) echo '⚠️ Good - Some features may need attention';
                            else echo '❌ Warning - Security features need configuration';
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Status -->
        <div class="row mb-4">
            <div class="col-md-6 mb-3">
                <div class="card health-card">
                    <div class="card-body">
                        <h5 class="card-title mb-3"><i class="fas fa-database me-2 text-primary"></i>Database</h5>
                        <div class="d-flex align-items-center">
                            <span class="status-indicator <?php echo $healthData['database']['connected'] ? 'status-online' : 'status-offline'; ?>"></span>
                            <strong><?php echo $healthData['database']['connected'] ? 'Connected' : 'Disconnected'; ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="card health-card">
                    <div class="card-body">
                        <h5 class="card-title mb-3"><i class="fas fa-shield-alt me-2 text-primary"></i>Rate Limiting</h5>
                        <div class="d-flex align-items-center">
                            <span class="status-indicator <?php echo $healthData['security']['rateLimiting'] ? 'status-online' : 'status-offline'; ?>"></span>
                            <strong><?php echo $healthData['security']['rateLimiting'] ? 'Active' : 'Not Deployed'; ?></strong>
                        </div>
                        <?php if ($healthData['security']['rateLimiting']): ?>
                        <small class="text-muted d-block mt-2">
                            <?php echo number_format($healthData['security']['activeBlocks']); ?> active blocks
                        </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="card health-card">
                    <div class="card-body">
                        <h5 class="card-title mb-3"><i class="fas fa-key me-2 text-primary"></i>API Keys</h5>
                        <div class="d-flex align-items-center">
                            <span class="status-indicator <?php echo $healthData['security']['apiKeys'] ? 'status-online' : 'status-offline'; ?>"></span>
                            <strong><?php echo $healthData['security']['apiKeys'] ? 'Active' : 'Not Deployed'; ?></strong>
                        </div>
                        <?php if ($healthData['security']['apiKeys']): ?>
                        <small class="text-muted d-block mt-2">
                            <?php echo number_format($healthData['security']['totalPartners']); ?> active partners
                        </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="card health-card">
                    <div class="card-body">
                        <h5 class="card-title mb-3"><i class="fas fa-users me-2 text-primary"></i>User Accounts</h5>
                        <div class="d-flex align-items-center">
                            <span class="status-indicator status-online"></span>
                            <strong><?php echo number_format($healthData['stats']['totalUsers']); ?> Users</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="stat-card">
                    <h6 class="opacity-75 mb-2">Rate Limits (Last Hour)</h6>
                    <div class="stat-value"><?php echo number_format($healthData['stats']['activeRateLimits']); ?></div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <h6 class="opacity-75 mb-2">API Requests Today</h6>
                    <div class="stat-value"><?php echo number_format($healthData['stats']['apiKeyUsageToday']); ?></div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                    <h6 class="opacity-75 mb-2">Active Blocks</h6>
                    <div class="stat-value"><?php echo number_format($healthData['security']['activeBlocks']); ?></div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card health-card">
            <div class="card-body">
                <h5 class="card-title mb-3"><i class="fas fa-tools me-2 text-primary"></i>Quick Actions</h5>
                <div class="d-flex flex-wrap gap-2">
                    <a href="migrations.php" class="btn btn-primary">
                        <i class="fas fa-database me-2"></i>Deploy Migrations
                    </a>
                    <a href="../partners/list.php" class="btn btn-info text-white">
                        <i class="fas fa-users me-2"></i>Manage Partners
                    </a>
                    <a href="../security/rate-limits.php" class="btn btn-warning">
                        <i class="fas fa-shield-alt me-2"></i>Rate Limit Monitor
                    </a>
                    <a href="test-security.php" class="btn btn-success">
                        <i class="fas fa-vial me-2"></i>Test Security
                    </a>
                </div>
            </div>
        </div>

        <!-- Refresh Notice -->
        <div class="alert alert-info mt-4">
            <i class="fas fa-info-circle me-2"></i>
            This dashboard refreshes on page load. For real-time monitoring, consider implementing WebSocket updates.
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
</body>
</html>
