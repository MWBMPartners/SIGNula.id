<?php
/**
 * Admin Dashboard
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 2) . '/_config/config.php';
require_once dirname(__DIR__, 2) . '/_backend/Database.php';
require_once dirname(__DIR__, 2) . '/_backend/SessionManager.php';

$db = Database::getInstance();
$sessionManager = new SessionManager($db);

if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

$userInfo = $sessionManager->getUserInfo();
if (!isset($userInfo['isAdmin']) || $userInfo['isAdmin'] != 1) {
    http_response_code(403);
    die('Access denied.');
}

// Check if security tables exist
$securityFeatures = [
    'rateLimiting' => $db->query("SHOW TABLES LIKE 'tblRateLimits'")->num_rows > 0,
    'apiKeys' => $db->query("SHOW TABLES LIKE 'tblAPIKeys'")->num_rows > 0,
];

// Get quick stats
$stats = [
    'totalUsers' => $db->query("SELECT COUNT(*) as c FROM tblUsers")->fetch_assoc()['c'],
    'activePartners' => $securityFeatures['apiKeys'] ? $db->query("SELECT COUNT(*) as c FROM tblPartners WHERE status = 'active'")->fetch_assoc()['c'] : 0,
    'pendingPartners' => $securityFeatures['apiKeys'] ? $db->query("SELECT COUNT(*) as c FROM tblPartners WHERE status = 'pending'")->fetch_assoc()['c'] : 0,
    'activeBlocks' => $securityFeatures['rateLimiting'] ? $db->query("SELECT COUNT(*) as c FROM tblRateLimits WHERE isBlocked = 1")->fetch_assoc()['c'] : 0,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - SIGNula</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .dashboard-container { max-width: 1400px; margin: 0 auto; padding: 2rem; }
        .welcome-card { background: white; border-radius: 15px; padding: 2rem; box-shadow: 0 10px 40px rgba(0,0,0,0.2); margin-bottom: 2rem; }
        .feature-card { background: white; border-radius: 15px; padding: 1.5rem; box-shadow: 0 4px 20px rgba(0,0,0,0.1); transition: all 0.3s; height: 100%; }
        .feature-card:hover { transform: translateY(-5px); box-shadow: 0 8px 30px rgba(0,0,0,0.15); }
        .feature-icon { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 1rem; }
        .stat-badge { font-size: 2rem; font-weight: bold; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Welcome Header -->
        <div class="welcome-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="mb-2"><i class="fas fa-tachometer-alt me-2 text-primary"></i>Admin Dashboard</h1>
                    <p class="text-muted mb-0">Welcome back, <?php echo htmlspecialchars($userInfo['username']); ?>!</p>
                </div>
                <div>
                    <a href="/settings/" class="btn btn-outline-primary me-2">
                        <i class="fas fa-cog me-2"></i>Settings
                    </a>
                    <a href="/auth/logout.php" class="btn btn-outline-danger">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="feature-card text-center">
                    <div class="stat-badge text-primary"><?php echo number_format($stats['totalUsers']); ?></div>
                    <p class="text-muted mb-0">Total Users</p>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="feature-card text-center">
                    <div class="stat-badge text-success"><?php echo number_format($stats['activePartners']); ?></div>
                    <p class="text-muted mb-0">Active Partners</p>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="feature-card text-center">
                    <div class="stat-badge text-warning"><?php echo number_format($stats['pendingPartners']); ?></div>
                    <p class="text-muted mb-0">Pending Approvals</p>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="feature-card text-center">
                    <div class="stat-badge text-danger"><?php echo number_format($stats['activeBlocks']); ?></div>
                    <p class="text-muted mb-0">Active Blocks</p>
                </div>
            </div>
        </div>

        <!-- System Management -->
        <h3 class="text-white mb-3"><i class="fas fa-tools me-2"></i>System Management</h3>
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <a href="system/migrations.php" class="text-decoration-none">
                    <div class="feature-card">
                        <div class="feature-icon bg-primary text-white">
                            <i class="fas fa-database"></i>
                        </div>
                        <h5>Database Migrations</h5>
                        <p class="text-muted">Deploy database migrations with one click. No command-line required!</p>
                        <span class="badge bg-primary">Deploy Now</span>
                    </div>
                </a>
            </div>
            <div class="col-md-4 mb-3">
                <a href="system/health.php" class="text-decoration-none">
                    <div class="feature-card">
                        <div class="feature-icon bg-success text-white">
                            <i class="fas fa-heartbeat"></i>
                        </div>
                        <h5>System Health</h5>
                        <p class="text-muted">Monitor system status, security features, and performance metrics.</p>
                        <span class="badge bg-success">View Status</span>
                    </div>
                </a>
            </div>
            <div class="col-md-4 mb-3">
                <a href="system/test-security.php" class="text-decoration-none">
                    <div class="feature-card">
                        <div class="feature-icon bg-info text-white">
                            <i class="fas fa-vial"></i>
                        </div>
                        <h5>Security Testing</h5>
                        <p class="text-muted">Test rate limiting, API keys, and security features from the UI.</p>
                        <span class="badge bg-info">Run Tests</span>
                    </div>
                </a>
            </div>
        </div>

        <!-- Partner & Security Management -->
        <h3 class="text-white mb-3"><i class="fas fa-shield-alt me-2"></i>Security & Partners</h3>
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <a href="partners/list.php" class="text-decoration-none">
                    <div class="feature-card">
                        <div class="feature-icon bg-warning text-white">
                            <i class="fas fa-users"></i>
                        </div>
                        <h5>Partner Management</h5>
                        <p class="text-muted">Approve partners, manage tiers, and monitor API usage.</p>
                        <?php if ($stats['pendingPartners'] > 0): ?>
                        <span class="badge bg-warning"><?php echo $stats['pendingPartners']; ?> Pending</span>
                        <?php else: ?>
                        <span class="badge bg-success">All Approved</span>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
            <div class="col-md-4 mb-3">
                <a href="security/rate-limits.php" class="text-decoration-none">
                    <div class="feature-card">
                        <div class="feature-icon bg-danger text-white">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h5>Rate Limit Monitor</h5>
                        <p class="text-muted">Monitor rate limits, view blocks, and manage violations.</p>
                        <?php if ($stats['activeBlocks'] > 0): ?>
                        <span class="badge bg-danger"><?php echo $stats['activeBlocks']; ?> Active Blocks</span>
                        <?php else: ?>
                        <span class="badge bg-success">No Blocks</span>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
            <div class="col-md-4 mb-3">
                <a href="../docs/api/" class="text-decoration-none">
                    <div class="feature-card">
                        <div class="feature-icon bg-secondary text-white">
                            <i class="fas fa-book"></i>
                        </div>
                        <h5>API Documentation</h5>
                        <p class="text-muted">View comprehensive API documentation for partners.</p>
                        <span class="badge bg-secondary">View Docs</span>
                    </div>
                </a>
            </div>
        </div>

        <!-- User & Settings Management -->
        <h3 class="text-white mb-3"><i class="fas fa-cogs me-2"></i>Users & Settings</h3>
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <a href="users/" class="text-decoration-none">
                    <div class="feature-card">
                        <div class="feature-icon text-white" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                            <i class="fas fa-users-cog"></i>
                        </div>
                        <h5>User Management</h5>
                        <p class="text-muted">Manage user accounts, statuses, tiers, and super admin access.</p>
                        <span class="badge bg-primary"><?php echo number_format($stats['totalUsers']); ?> Users</span>
                    </div>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="settings/" class="text-decoration-none">
                    <div class="feature-card">
                        <div class="feature-icon text-white" style="background: linear-gradient(135deg, #11998e, #38ef7d);">
                            <i class="fas fa-cogs"></i>
                        </div>
                        <h5>System Settings</h5>
                        <p class="text-muted">View and edit all system configuration values by category.</p>
                        <span class="badge bg-success">Manage</span>
                    </div>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="settings/oauth.php" class="text-decoration-none">
                    <div class="feature-card">
                        <div class="feature-icon text-white" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                            <i class="fas fa-plug"></i>
                        </div>
                        <h5>OAuth Providers</h5>
                        <p class="text-muted">Configure Google, Microsoft, Apple, and other SSO providers.</p>
                        <span class="badge bg-info">Configure</span>
                    </div>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="logs/" class="text-decoration-none">
                    <div class="feature-card">
                        <div class="feature-icon text-white" style="background: linear-gradient(135deg, #eb3349, #f45c43);">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h5>System Logs</h5>
                        <p class="text-muted">View activity, error, and admin audit logs with filtering.</p>
                        <span class="badge bg-danger">View Logs</span>
                    </div>
                </a>
            </div>
        </div>

        <!-- Feature Management -->
        <h3 class="text-white mb-3"><i class="fas fa-toggle-on me-2"></i>Feature Management</h3>
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <a href="features/global.php" class="text-decoration-none">
                    <div class="feature-card">
                        <div class="feature-icon text-white" style="background: linear-gradient(135deg, #fa709a, #fee140);">
                            <i class="fas fa-toggle-on"></i>
                        </div>
                        <h5>Global Feature Toggles</h5>
                        <p class="text-muted">Enable/disable features globally and control partner access.</p>
                        <span class="badge bg-warning text-dark">Manage Features</span>
                    </div>
                </a>
            </div>
        </div>

        <!-- Security Status -->
        <?php if (!$securityFeatures['rateLimiting'] || !$securityFeatures['apiKeys']): ?>
        <div class="alert alert-warning">
            <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Security Features Pending</h5>
            <p class="mb-0">
                <?php if (!$securityFeatures['rateLimiting']): ?>
                    ❌ Rate Limiting - <a href="system/migrations.php" class="alert-link">Deploy Migration 007</a><br>
                <?php endif; ?>
                <?php if (!$securityFeatures['apiKeys']): ?>
                    ❌ API Keys - <a href="system/migrations.php" class="alert-link">Deploy Migration 008</a>
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>
        <div class="alert alert-success">
            <h5 class="alert-heading"><i class="fas fa-check-circle me-2"></i>All Security Features Active</h5>
            <p class="mb-0">✅ Rate Limiting | ✅ API Key Management | Security Score: 95%+</p>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
