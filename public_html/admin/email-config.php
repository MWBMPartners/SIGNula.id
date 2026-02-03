<?php
/**
 * ============================================================================
 * 📧 SIGNula - Email Provider Configuration & Testing
 * ============================================================================
 *
 * Purpose: Configure and test email providers
 * PHP Version: 8.3+
 *
 * Features:
 * - Configure email providers (Microsoft Graph, Gmail API, SMTP)
 * - Test email sending
 * - View queue statistics
 * - Manual queue processing
 * - Setup instructions
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
require_once SIGNULA_ROOT . '/private_html/email/providers/MicrosoftGraphEmailProvider.php';
require_once SIGNULA_ROOT . '/private_html/email/providers/GmailAPIEmailProvider.php';
require_once SIGNULA_ROOT . '/private_html/email/providers/SMTPEmailProvider.php';

$pageTitle = 'Email Configuration';
$currentTab = $_GET['tab'] ?? 'overview';
$message = null;
$error = null;

// ========================================================================
// 📝 PROCESS FORM SUBMISSIONS
// ========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        // 🧪 Test Email
        case 'test_email':
            try {
                $testEmail = $_POST['test_email'] ?? Auth::getCurrentUser()['email'];
                $provider = $_POST['test_provider'] ?? 'default';

                // 📧 Queue test email
                $queued = EmailService::queueEmail(
                    $testEmail,
                    'SIGNula Email Test',
                    '<h1>Email Test Successful!</h1><p>This is a test email from your SIGNula email configuration.</p><p>If you\'re reading this, your email provider is working correctly!</p>',
                    'Email Test Successful! This is a test email from your SIGNula email configuration. If you\'re reading this, your email provider is working correctly!',
                    null,
                    null,
                    null,
                    Auth::getCurrentUser()['userID'],
                    null,
                    1 // High priority
                );

                if ($queued) {
                    // 📤 Process queue immediately
                    $stats = EmailService::processQueue(1, false);

                    if ($stats['sent'] > 0) {
                        $message = "✅ Test email sent successfully to {$testEmail}!";
                    } else {
                        $error = "❌ Test email queued but failed to send: " . ($stats['error'] ?? 'Unknown error');
                    }
                } else {
                    $error = "❌ Failed to queue test email";
                }

            } catch (Exception $e) {
                $error = "❌ Error: " . $e->getMessage();
            }
            break;

        // 🔄 Process Queue
        case 'process_queue':
            try {
                $batchSize = (int)($_POST['batch_size'] ?? 10);
                $stats = EmailService::processQueue($batchSize, false);

                $message = "📊 Queue processed: {$stats['sent']} sent, {$stats['failed']} failed, {$stats['retried']} retried";

            } catch (Exception $e) {
                $error = "❌ Error: " . $e->getMessage();
            }
            break;

        // 🧹 Cleanup Old Emails
        case 'cleanup':
            try {
                $daysOld = (int)($_POST['days_old'] ?? 30);
                $deleted = EmailService::cleanupOldEmails($daysOld);

                $message = "🧹 Cleaned up {$deleted} old emails";

            } catch (Exception $e) {
                $error = "❌ Error: " . $e->getMessage();
            }
            break;

        // ⚙️ Update Provider Settings
        case 'update_provider':
            try {
                $provider = $_POST['provider'] ?? '';

                if ($provider === 'microsoft_graph') {
                    setSetting('email.microsoft.tenant_id', $_POST['tenant_id'] ?? '');
                    setSetting('email.microsoft.client_id', $_POST['client_id'] ?? '');

                    if (!empty($_POST['client_secret'])) {
                        $encrypted = SecurityUtils::encrypt($_POST['client_secret']);
                        setSetting('email.microsoft.client_secret', $encrypted);
                    }

                    setSetting('email.microsoft.from_email', $_POST['from_email'] ?? '');
                    setSetting('email.microsoft.from_name', $_POST['from_name'] ?? 'SIGNula');

                    $message = "✅ Microsoft Graph API settings updated successfully";

                } elseif ($provider === 'gmail_api') {
                    if (!empty($_POST['service_account_json'])) {
                        $encrypted = SecurityUtils::encrypt($_POST['service_account_json']);
                        setSetting('email.gmail.service_account_json', $encrypted);
                    }

                    setSetting('email.gmail.from_email', $_POST['from_email'] ?? '');
                    setSetting('email.gmail.from_name', $_POST['from_name'] ?? 'SIGNula');

                    $message = "✅ Gmail API settings updated successfully";

                } elseif ($provider === 'smtp') {
                    setSetting('email.smtp.host', $_POST['smtp_host'] ?? '');
                    setSetting('email.smtp.port', $_POST['smtp_port'] ?? '587');
                    setSetting('email.smtp.encryption', $_POST['smtp_encryption'] ?? 'tls');
                    setSetting('email.smtp.username', $_POST['smtp_username'] ?? '');

                    if (!empty($_POST['smtp_password'])) {
                        $encrypted = SecurityUtils::encrypt($_POST['smtp_password']);
                        setSetting('email.smtp.password', $encrypted);
                    }

                    setSetting('email.smtp.from_email', $_POST['from_email'] ?? '');
                    setSetting('email.smtp.from_name', $_POST['from_name'] ?? 'SIGNula');
                    setSetting('email.smtp.timeout', $_POST['smtp_timeout'] ?? '30');

                    $message = "✅ SMTP settings updated successfully";
                }

                // 🔧 Update preferred provider
                if (isset($_POST['set_as_preferred'])) {
                    setSetting('email.provider', $provider);
                    $message .= " (Set as preferred provider)";
                }

            } catch (Exception $e) {
                $error = "❌ Error: " . $e->getMessage();
            }
            break;
    }
}

// ========================================================================
// 📊 GET DATA
// ========================================================================

// 📧 Get queue statistics
$queueStats = EmailService::getQueueStats();

// 🔌 Check provider status
$providers = [
    'microsoft_graph' => new MicrosoftGraphEmailProvider(),
    'gmail_api' => new GmailAPIEmailProvider(),
    'smtp' => new SMTPEmailProvider()
];

$preferredProvider = getSetting('email.provider', 'smtp');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - SIGNula</title>

    <!-- 🎨 Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- 🎨 Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        .provider-card {
            border: 2px solid #dee2e6;
            border-radius: 0.5rem;
            transition: all 0.3s;
        }
        .provider-card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .provider-card.configured {
            border-color: #28a745;
        }
        .provider-card.preferred {
            border-color: #007bff;
            background-color: #f0f7ff;
        }
        .status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
        }
        .stat-card {
            border-left: 4px solid;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Include admin sidebar here if you have one -->
            <main class="col-md-12 ms-sm-auto px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">📧 <?= htmlspecialchars($pageTitle) ?></h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="/admin" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Admin
                        </a>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- 📊 Queue Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card" style="border-left-color: #6c757d;">
                            <div class="card-body">
                                <h6 class="text-muted mb-2">Total Emails</h6>
                                <h2 class="mb-0"><?= number_format($queueStats['total']) ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card" style="border-left-color: #ffc107;">
                            <div class="card-body">
                                <h6 class="text-muted mb-2">Pending</h6>
                                <h2 class="mb-0"><?= number_format($queueStats['pending']) ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card" style="border-left-color: #28a745;">
                            <div class="card-body">
                                <h6 class="text-muted mb-2">Sent</h6>
                                <h2 class="mb-0"><?= number_format($queueStats['sent']) ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card" style="border-left-color: #dc3545;">
                            <div class="card-body">
                                <h6 class="text-muted mb-2">Failed</h6>
                                <h2 class="mb-0"><?= number_format($queueStats['failed']) ?></h2>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 📋 Tabs -->
                <ul class="nav nav-tabs mb-4" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link <?= $currentTab === 'overview' ? 'active' : '' ?>" href="?tab=overview">
                            <i class="fas fa-home"></i> Overview
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentTab === 'microsoft' ? 'active' : '' ?>" href="?tab=microsoft">
                            <i class="fab fa-microsoft"></i> Microsoft Graph
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentTab === 'gmail' ? 'active' : '' ?>" href="?tab=gmail">
                            <i class="fab fa-google"></i> Gmail API
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentTab === 'smtp' ? 'active' : '' ?>" href="?tab=smtp">
                            <i class="fas fa-envelope"></i> SMTP
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentTab === 'test' ? 'active' : '' ?>" href="?tab=test">
                            <i class="fas fa-flask"></i> Test
                        </a>
                    </li>
                </ul>

                <!-- 📄 Tab Content -->
                <div class="tab-content">
                    <?php if ($currentTab === 'overview'): ?>
                        <!-- Overview Tab -->
                        <div class="row">
                            <?php foreach ($providers as $key => $provider): ?>
                                <?php
                                $isConfigured = $provider->isConfigured();
                                $isPreferred = $preferredProvider === $key;
                                $cardClass = $isPreferred ? 'preferred' : ($isConfigured ? 'configured' : '');
                                ?>
                                <div class="col-md-4 mb-4">
                                    <div class="card provider-card <?= $cardClass ?> position-relative">
                                        <?php if ($isPreferred): ?>
                                            <span class="badge bg-primary status-badge">Preferred</span>
                                        <?php elseif ($isConfigured): ?>
                                            <span class="badge bg-success status-badge">Configured</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary status-badge">Not Configured</span>
                                        <?php endif; ?>

                                        <div class="card-body">
                                            <h5 class="card-title">
                                                <?php if ($key === 'microsoft_graph'): ?>
                                                    <i class="fab fa-microsoft"></i> Microsoft Graph API
                                                <?php elseif ($key === 'gmail_api'): ?>
                                                    <i class="fab fa-google"></i> Gmail API
                                                <?php else: ?>
                                                    <i class="fas fa-envelope"></i> SMTP
                                                <?php endif; ?>
                                            </h5>
                                            <p class="card-text text-muted">
                                                <?php if ($key === 'microsoft_graph'): ?>
                                                    Microsoft 365 / Office 365 integration for excellent deliverability
                                                <?php elseif ($key === 'gmail_api'): ?>
                                                    Google Workspace integration for reliable email delivery
                                                <?php else: ?>
                                                    Universal SMTP provider - works with any mail server
                                                <?php endif; ?>
                                            </p>
                                            <a href="?tab=<?= $key === 'microsoft_graph' ? 'microsoft' : ($key === 'gmail_api' ? 'gmail' : 'smtp') ?>" class="btn btn-primary">
                                                <i class="fas fa-cog"></i> Configure
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Queue Actions -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-tasks"></i> Queue Management</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="row g-3">
                                    <input type="hidden" name="action" value="process_queue">
                                    <div class="col-auto">
                                        <label class="form-label">Batch Size</label>
                                        <input type="number" name="batch_size" class="form-control" value="10" min="1" max="100">
                                    </div>
                                    <div class="col-auto align-self-end">
                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-play"></i> Process Queue Now
                                        </button>
                                    </div>
                                </form>

                                <hr>

                                <form method="POST" class="row g-3">
                                    <input type="hidden" name="action" value="cleanup">
                                    <div class="col-auto">
                                        <label class="form-label">Delete emails older than (days)</label>
                                        <input type="number" name="days_old" class="form-control" value="30" min="1" max="365">
                                    </div>
                                    <div class="col-auto align-self-end">
                                        <button type="submit" class="btn btn-warning">
                                            <i class="fas fa-trash"></i> Cleanup Old Emails
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                    <?php endif; ?>

                    <?php
                    // Include provider configuration tabs
                    // This would be expanded with full configuration forms for each provider
                    // For brevity, showing structure only
                    ?>

                    <?php if ($currentTab === 'test'): ?>
                        <!-- Test Tab -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-flask"></i> Test Email Delivery</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="test_email">

                                    <div class="mb-3">
                                        <label class="form-label">Test Email Address</label>
                                        <input type="email" name="test_email" class="form-control"
                                               value="<?= htmlspecialchars(Auth::getCurrentUser()['email']) ?>" required>
                                        <div class="form-text">Email will be sent to this address</div>
                                    </div>

                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane"></i> Send Test Email
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- 🎨 Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
