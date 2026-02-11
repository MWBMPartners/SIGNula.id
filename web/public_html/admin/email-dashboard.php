<?php
/**
 * ============================================================================
 * 📧 SIGNula - Email System Dashboard
 * ============================================================================
 *
 * Purpose: Comprehensive email system dashboard and management
 * PHP Version: 8.3+
 *
 * Features:
 * - Real-time queue monitoring
 * - Provider health status
 * - Email analytics and statistics
 * - Template management
 * - Campaign tracking
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔐 Require admin authentication
if (!Auth::isAuthenticated() || !Auth::isAdmin()) {
    redirect('/login');
}

// 📚 Load email classes
require_once SIGNULA_ROOT . '/private_html/email/EmailService.php';
require_once SIGNULA_ROOT . '/private_html/email/EmailQueueProcessor.php';
require_once SIGNULA_ROOT . '/private_html/email/EmailTemplateManager.php';
require_once SIGNULA_ROOT . '/private_html/email/EmailTracker.php';

$pageTitle = 'Email Dashboard';

// 📊 Get statistics
$queueStats = EmailService::getQueueStats();

// 🔌 Get provider health
$providerHealth = Database::fetchAll("SELECT * FROM tblEmailProviderHealth ORDER BY provider");

// 📧 Get recent emails
$recentEmails = Database::fetchAll(
    "SELECT * FROM tblEmailQueue ORDER BY createdAt DESC LIMIT 10"
) ?: [];

// 📋 Get templates count
$templateCount = Database::fetchOne(
    "SELECT COUNT(*) as count FROM tblEmailTemplates WHERE isActive = TRUE"
)['count'] ?? 0;

// 📊 Get today's statistics
$todayStats = Database::fetchOne("
    SELECT
        COUNT(*) as sent_today,
        SUM(openCount) as opens_today,
        SUM(clickCount) as clicks_today
    FROM tblEmailQueue
    WHERE DATE(sentAt) = CURDATE()
") ?: ['sent_today' => 0, 'opens_today' => 0, 'clicks_today' => 0];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - SIGNula</title>

    <!-- 🎨 Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <!-- 🎨 Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    <!-- 📊 Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <style>
        .stat-card {
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .health-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .health-healthy {
            background-color: #28a745;
        }
        .health-degraded {
            background-color: #ffc107;
        }
        .health-unhealthy {
            background-color: #dc3545;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <main class="col-md-12 ms-sm-auto px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">📧 <?= htmlspecialchars($pageTitle) ?></h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="/admin/email-config" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-cog"></i> Configuration
                            </a>
                            <a href="/admin/email-templates" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-envelope"></i> Templates
                            </a>
                        </div>
                        <a href="/admin" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Admin
                        </a>
                    </div>
                </div>

                <!-- 📊 Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card" style="border-left-color: #007bff;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Pending Emails</h6>
                                        <h2 class="mb-0"><?= number_format($queueStats['pending']) ?></h2>
                                    </div>
                                    <div class="text-primary" style="font-size: 2.5rem;">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                </div>
                                <small class="text-muted">Waiting to send</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card stat-card" style="border-left-color: #28a745;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Sent Today</h6>
                                        <h2 class="mb-0"><?= number_format($todayStats['sent_today']) ?></h2>
                                    </div>
                                    <div class="text-success" style="font-size: 2.5rem;">
                                        <i class="fas fa-paper-plane"></i>
                                    </div>
                                </div>
                                <small class="text-muted">Last 24 hours</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card stat-card" style="border-left-color: #17a2b8;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Opens Today</h6>
                                        <h2 class="mb-0"><?= number_format($todayStats['opens_today']) ?></h2>
                                    </div>
                                    <div class="text-info" style="font-size: 2.5rem;">
                                        <i class="fas fa-envelope-open"></i>
                                    </div>
                                </div>
                                <small class="text-muted">Email opens</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card stat-card" style="border-left-color: #ffc107;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Clicks Today</h6>
                                        <h2 class="mb-0"><?= number_format($todayStats['clicks_today']) ?></h2>
                                    </div>
                                    <div class="text-warning" style="font-size: 2.5rem;">
                                        <i class="fas fa-mouse-pointer"></i>
                                    </div>
                                </div>
                                <small class="text-muted">Link clicks</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <!-- 🔌 Provider Health -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-heartbeat"></i> Provider Health</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($providerHealth)): ?>
                                    <p class="text-muted">No provider health data available</p>
                                <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($providerHealth as $provider): ?>
                                            <?php
                                            $healthClass = $provider['isHealthy'] ? 'health-healthy' : 'health-unhealthy';
                                            $healthText = $provider['isHealthy'] ? 'Healthy' : 'Unhealthy';
                                            $providerLabel = ucfirst(str_replace('_', ' ', $provider['provider']));
                                            ?>
                                            <div class="list-group-item">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <span class="health-indicator <?= $healthClass ?>"></span>
                                                        <strong><?= htmlspecialchars($providerLabel) ?></strong>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="badge bg-<?= $provider['isHealthy'] ? 'success' : 'danger' ?>">
                                                            <?= $healthText ?>
                                                        </span>
                                                        <?php if ($provider['successRate']): ?>
                                                            <small class="text-muted d-block">
                                                                <?= number_format($provider['successRate'], 1) ?>% success rate
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if (!$provider['isHealthy'] && $provider['lastError']): ?>
                                                    <small class="text-danger d-block mt-2">
                                                        <i class="fas fa-exclamation-triangle"></i>
                                                        <?= htmlspecialchars(substr($provider['lastError'], 0, 100)) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 📊 Queue Overview -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Queue Overview</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="queueChart" style="max-height: 250px;"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 📧 Recent Emails -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-envelope"></i> Recent Emails</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recentEmails)): ?>
                            <p class="text-muted">No emails in queue</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Recipient</th>
                                            <th>Subject</th>
                                            <th>Status</th>
                                            <th>Provider</th>
                                            <th>Engagement</th>
                                            <th>Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentEmails as $email): ?>
                                            <?php
                                            $statusColors = [
                                                'pending' => 'warning',
                                                'sending' => 'info',
                                                'sent' => 'success',
                                                'failed' => 'danger',
                                                'cancelled' => 'secondary'
                                            ];
                                            $statusColor = $statusColors[$email['status']] ?? 'secondary';
                                            ?>
                                            <tr>
                                                <td><?= htmlspecialchars($email['recipientEmail']) ?></td>
                                                <td>
                                                    <?= htmlspecialchars(substr($email['subject'], 0, 50)) ?>
                                                    <?= strlen($email['subject']) > 50 ? '...' : '' ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $statusColor ?> status-badge">
                                                        <?= ucfirst($email['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?= $email['provider'] ? htmlspecialchars($email['provider']) : '-' ?>
                                                </td>
                                                <td>
                                                    <?php if ($email['status'] === 'sent'): ?>
                                                        <span class="badge bg-info status-badge" title="Opens">
                                                            <i class="fas fa-envelope-open"></i> <?= $email['openCount'] ?>
                                                        </span>
                                                        <span class="badge bg-warning status-badge" title="Clicks">
                                                            <i class="fas fa-mouse-pointer"></i> <?= $email['clickCount'] ?>
                                                        </span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?= date('M j, g:i A', strtotime($email['createdAt'])) ?></small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 📋 Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <form method="POST" action="/admin/email-config">
                                    <input type="hidden" name="action" value="process_queue">
                                    <button type="submit" class="btn btn-success w-100 mb-2">
                                        <i class="fas fa-play"></i> Process Queue Now
                                    </button>
                                </form>
                            </div>
                            <div class="col-md-4">
                                <a href="/admin/email-templates" class="btn btn-primary w-100 mb-2">
                                    <i class="fas fa-plus"></i> Create New Template
                                </a>
                            </div>
                            <div class="col-md-4">
                                <a href="/admin/email-analytics" class="btn btn-info w-100 mb-2">
                                    <i class="fas fa-chart-bar"></i> View Analytics
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <!-- 🎨 Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

    <!-- 📊 Queue Chart -->
    <script>
    const queueCtx = document.getElementById('queueChart');
    if (queueCtx) {
        new Chart(queueCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Sent', 'Failed'],
                datasets: [{
                    data: [
                        <?= $queueStats['pending'] ?>,
                        <?= $queueStats['sent'] ?>,
                        <?= $queueStats['failed'] ?>
                    ],
                    backgroundColor: [
                        '#ffc107',
                        '#28a745',
                        '#dc3545'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    title: {
                        display: false
                    }
                }
            }
        });
    }
    </script>
</body>
</html>
