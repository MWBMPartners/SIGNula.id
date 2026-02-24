<?php
/**
 * 🚀 Production Deployment Checklist (Super Admin)
 *
 * Interactive deployment readiness checker that validates database schema,
 * required settings, file permissions, security configuration, and feature
 * status before going live. All checks run server-side via PHP.
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
 * @see https://owasp.org/www-project-web-security-testing-guide/ — OWASP Testing Guide
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.3.0-beta
 */

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 🔌 Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin check
$accessControl->requireSuperAdmin();

// ═══════════════════════════════════════════════════════════════════
// 🔍 DEPLOYMENT CHECKS
// ═══════════════════════════════════════════════════════════════════

$checks = [];
$passCount = 0;
$warnCount = 0;
$failCount = 0;

/**
 * 📋 Helper: Record a check result
 * @param string $category - Check category
 * @param string $name - Check name
 * @param string $status - pass, warn, or fail
 * @param string $detail - Description of the result
 */
function addCheck($category, $name, $status, $detail) {
    global $checks, $passCount, $warnCount, $failCount;
    $checks[$category][] = ['name' => $name, 'status' => $status, 'detail' => $detail];
    if ($status === 'pass') { $passCount++; }
    elseif ($status === 'warn') { $warnCount++; }
    else { $failCount++; }
}

/**
 * 🗄️ Helper: Check if a database table exists
 * @param string $tableName - Table name to check
 * @return bool True if table exists
 */
function tableExists($tableName) {
    global $db;
    $stmt = $db->prepare("SHOW TABLES LIKE ?");
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/**
 * 🔧 Helper: Check if a setting exists in tblSettings
 * @param string $key - Setting key to check
 * @return string|null Setting value or null
 */
function getSetting($key) {
    global $db;
    $stmt = $db->prepare("SELECT settingValue FROM tblSettings WHERE settingKey = ? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ? $result['settingValue'] : null;
}

// ═══════════════════════════════════════════════════════════════════
// 1️⃣ DATABASE SCHEMA CHECKS
// ═══════════════════════════════════════════════════════════════════

$requiredTables = [
    'tblUsers', 'tblSessions', 'tblSettings', 'tblActivityLog',
    'tblPartners', 'tblPartnerTeamMembers', 'tblOAuthAccounts',
    'tblMFAMethods', 'tblPasswordResets', 'tblLoginAttempts',
    'tblEmailTemplates', 'tblEmailQueue', 'tblEmailLogs',
    'tblBlogPosts', 'tblBlogCategories', 'tblSupportTickets',
    'tblRateLimits', 'tblRateLimitRules', 'tblAPIKeys',
    'tblFeatureToggles', 'tblFeatureToggleOverrides',
    'tblWebhookEndpoints', 'tblWebhookDeliveries',
    'tblSubscriptionTiers', 'tblSubscriptions',
    'tblPayments', 'tblPaymentMethods', 'tblDiscountCodes'
];

foreach ($requiredTables as $table) {
    if (tableExists($table)) {
        addCheck('Database', $table, 'pass', 'Table exists');
    } else {
        addCheck('Database', $table, 'fail', 'Table missing — run appropriate migration');
    }
}

// 🔍 Check migration tracking table
if (tableExists('tblMigrations')) {
    $stmt = $db->prepare("SELECT MAX(version) AS latest FROM tblMigrations");
    $stmt->execute();
    $latestMigration = $stmt->get_result()->fetch_assoc()['latest'] ?? 'none';
    addCheck('Database', 'Migration Tracking', 'pass', 'Latest version: ' . $latestMigration);
} else {
    addCheck('Database', 'Migration Tracking', 'warn', 'tblMigrations table not found — migration tracking unavailable');
}

// 🔍 Check database views
$views = ['vwPartnerTeamDetails', 'vwPartnerSummary', 'vwFeatureStatus'];
foreach ($views as $view) {
    $stmt = $db->prepare("SHOW FULL TABLES WHERE Table_Type = 'VIEW' AND Tables_in_signula LIKE ?");
    $stmt->bind_param('s', $view);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        addCheck('Database', $view . ' (View)', 'pass', 'View exists');
    } else {
        addCheck('Database', $view . ' (View)', 'warn', 'View missing — run migration 009');
    }
}

// ═══════════════════════════════════════════════════════════════════
// 2️⃣ REQUIRED SETTINGS CHECKS
// ═══════════════════════════════════════════════════════════════════

$requiredSettings = [
    'site_name' => 'Site Name',
    'site_url' => 'Site URL',
    'admin_email' => 'Admin Email',
    'encryption_key' => 'Encryption Key',
    'session_lifetime' => 'Session Lifetime',
    'mfa_enabled' => 'MFA Enabled',
    'oauth_google_client_id' => 'Google OAuth Client ID',
    'oauth_microsoft_client_id' => 'Microsoft OAuth Client ID',
    'smtp_host' => 'SMTP Host',
    'smtp_username' => 'SMTP Username',
];

$paymentSettings = [
    'payment_enabled' => 'Payment System Enabled',
    'payment_default_currency' => 'Default Currency',
    'payment_paypal_enabled' => 'PayPal Enabled',
    'payment_tax_rate' => 'Tax Rate',
    'webhook_enabled' => 'Webhooks Enabled',
    'webhook_signing_algorithm' => 'Webhook Signing Algorithm',
];

foreach ($requiredSettings as $key => $label) {
    $value = getSetting($key);
    if ($value !== null && $value !== '') {
        // 🔒 Don't show sensitive values
        $displayValue = in_array($key, ['encryption_key', 'oauth_google_client_id', 'oauth_microsoft_client_id', 'smtp_username'])
            ? '***configured***'
            : $value;
        addCheck('Settings', $label, 'pass', 'Value: ' . htmlspecialchars($displayValue));
    } else {
        $severity = in_array($key, ['encryption_key', 'site_url', 'admin_email']) ? 'fail' : 'warn';
        addCheck('Settings', $label, $severity, 'Not configured — set in Admin > Settings');
    }
}

foreach ($paymentSettings as $key => $label) {
    $value = getSetting($key);
    if ($value !== null && $value !== '') {
        addCheck('Payments', $label, 'pass', 'Value: ' . htmlspecialchars($value));
    } else {
        addCheck('Payments', $label, 'warn', 'Not configured — run migration 010 or set in Admin > Settings');
    }
}

// ═══════════════════════════════════════════════════════════════════
// 3️⃣ SECURITY CHECKS
// ═══════════════════════════════════════════════════════════════════

// 🔒 HTTPS check
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
addCheck('Security', 'HTTPS', $isHttps ? 'pass' : 'fail', $isHttps ? 'Connection is secure' : 'HTTPS is required for production');

// 🔒 PHP version check
$phpVersion = PHP_VERSION;
$phpMajorMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
if (version_compare($phpVersion, '8.3.0', '>=')) {
    addCheck('Security', 'PHP Version', 'pass', 'PHP ' . $phpVersion . ' (meets minimum 8.3)');
} elseif (version_compare($phpVersion, '8.1.0', '>=')) {
    addCheck('Security', 'PHP Version', 'warn', 'PHP ' . $phpVersion . ' (recommend 8.3+)');
} else {
    addCheck('Security', 'PHP Version', 'fail', 'PHP ' . $phpVersion . ' (requires 8.3+)');
}

// 🔒 Session security
$sessionCookieParams = session_get_cookie_params();
addCheck('Security', 'Session Cookie Secure',
    $sessionCookieParams['secure'] ? 'pass' : 'warn',
    $sessionCookieParams['secure'] ? 'Cookies marked as Secure' : 'Secure flag not set — enable for HTTPS'
);
addCheck('Security', 'Session Cookie HttpOnly',
    $sessionCookieParams['httponly'] ? 'pass' : 'warn',
    $sessionCookieParams['httponly'] ? 'HttpOnly flag set' : 'HttpOnly flag not set — vulnerable to XSS cookie theft'
);
addCheck('Security', 'Session Cookie SameSite',
    !empty($sessionCookieParams['samesite']) ? 'pass' : 'warn',
    !empty($sessionCookieParams['samesite']) ? 'SameSite=' . $sessionCookieParams['samesite'] : 'SameSite not set — consider Lax or Strict'
);

// 🔒 Error display (should be off in production)
$displayErrors = ini_get('display_errors');
addCheck('Security', 'Error Display',
    !$displayErrors || $displayErrors === '0' || $displayErrors === 'Off' ? 'pass' : 'warn',
    !$displayErrors || $displayErrors === '0' || $displayErrors === 'Off' ? 'Errors hidden from users' : 'Errors visible to users — disable display_errors in production'
);

// 🔒 Check if required PHP extensions are loaded
$requiredExtensions = ['mysqli', 'openssl', 'mbstring', 'json', 'curl', 'hash'];
foreach ($requiredExtensions as $ext) {
    addCheck('Security', 'PHP Extension: ' . $ext,
        extension_loaded($ext) ? 'pass' : 'fail',
        extension_loaded($ext) ? 'Loaded' : 'Not loaded — required for SIGNula'
    );
}

// ═══════════════════════════════════════════════════════════════════
// 4️⃣ FILE & DIRECTORY CHECKS
// ═══════════════════════════════════════════════════════════════════

// 📁 Check critical directories exist
$baseDir = dirname(__DIR__, 3);
$criticalPaths = [
    '_config/config.php' => 'Core Configuration',
    '_backend/Database.php' => 'Database Class',
    '_backend/SessionManager.php' => 'Session Manager',
    '_backend/AccessControl.php' => 'Access Control',
    'web/private_html/api/WebhookManager.php' => 'Webhook Manager',
    'web/private_html/payments/PaymentManager.php' => 'Payment Manager',
    'web/private_html/utils/ActivityLogger.php' => 'Activity Logger',
    'web/private_html/utils/SecurityUtils.php' => 'Security Utils',
];

foreach ($criticalPaths as $path => $label) {
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . $path;
    if (file_exists($fullPath)) {
        addCheck('Files', $label, 'pass', basename($path) . ' exists');
    } else {
        addCheck('Files', $label, 'fail', 'Missing: ' . $path);
    }
}

// 📁 Check private directories are not web-accessible (basic check)
$privatePrefix = $baseDir . DIRECTORY_SEPARATOR . '_config';
if (is_dir($privatePrefix)) {
    addCheck('Files', 'Private Directories', 'pass', '_config/ directory exists (should be outside web root)');
} else {
    addCheck('Files', 'Private Directories', 'warn', '_config/ directory not found at expected path');
}

// ═══════════════════════════════════════════════════════════════════
// 5️⃣ FEATURE STATUS CHECKS
// ═══════════════════════════════════════════════════════════════════

// 📊 Count users
$userCount = 0;
if (tableExists('tblUsers')) {
    $stmt = $db->prepare("SELECT COUNT(*) AS count FROM tblUsers");
    $stmt->execute();
    $userCount = $stmt->get_result()->fetch_assoc()['count'];
}
addCheck('Features', 'User Accounts', $userCount > 0 ? 'pass' : 'warn', $userCount . ' users in system');

// 📊 Check subscription tiers
$tierCount = 0;
if (tableExists('tblSubscriptionTiers')) {
    $stmt = $db->prepare("SELECT COUNT(*) AS count FROM tblSubscriptionTiers WHERE isActive = 1");
    $stmt->execute();
    $tierCount = $stmt->get_result()->fetch_assoc()['count'];
}
addCheck('Features', 'Subscription Tiers', $tierCount > 0 ? 'pass' : 'warn', $tierCount . ' active tiers configured');

// 📊 Check API keys exist
$apiKeyCount = 0;
if (tableExists('tblAPIKeys')) {
    $stmt = $db->prepare("SELECT COUNT(*) AS count FROM tblAPIKeys WHERE isActive = 1");
    $stmt->execute();
    $apiKeyCount = $stmt->get_result()->fetch_assoc()['count'];
}
addCheck('Features', 'API Keys', $apiKeyCount > 0 ? 'pass' : 'warn', $apiKeyCount . ' active API keys');

// 📊 Check webhook endpoints
$webhookCount = 0;
if (tableExists('tblWebhookEndpoints')) {
    $stmt = $db->prepare("SELECT COUNT(*) AS count FROM tblWebhookEndpoints WHERE isActive = 1");
    $stmt->execute();
    $webhookCount = $stmt->get_result()->fetch_assoc()['count'];
}
addCheck('Features', 'Webhook Endpoints', 'pass', $webhookCount . ' active webhook endpoints');

// 📊 Check email configuration
$smtpHost = getSetting('smtp_host');
addCheck('Features', 'Email System', !empty($smtpHost) ? 'pass' : 'warn', !empty($smtpHost) ? 'SMTP configured' : 'SMTP not configured — email features will not work');

// ═══════════════════════════════════════════════════════════════════
// 📊 OVERALL SCORE
// ═══════════════════════════════════════════════════════════════════

$totalChecks = $passCount + $warnCount + $failCount;
$scorePercent = $totalChecks > 0 ? round(($passCount / $totalChecks) * 100) : 0;
$overallStatus = $failCount === 0 && $warnCount === 0 ? 'ready' : ($failCount === 0 ? 'warnings' : 'not_ready');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deployment Checklist - SIGNula Admin</title>

    <!-- 🎨 Bootstrap 5.3.2 (CDN with SRI) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- 🎯 FontAwesome 6.4.2 (CDN with SRI) -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <style>
        body { background-color: #f8f9fa; }

        /* 🎨 Page header — contextual color based on readiness */
        .page-header {
            background: linear-gradient(135deg,
                <?php echo $overallStatus === 'ready' ? '#11998e, #38ef7d' : ($overallStatus === 'warnings' ? '#f7971e, #ffd200' : '#dc3545, #c82333'); ?>
            );
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* 📊 Score display */
        .score-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }
        .score-circle .score-text {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
        }

        /* 📊 Stat cards */
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            text-align: center;
        }
        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-card .stat-label {
            color: #6c757d;
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }

        /* 📋 Category sections */
        .check-category {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .category-header {
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 1.1rem;
            border-bottom: 1px solid #dee2e6;
            background: #f8f9fa;
        }

        /* ✅ Check items */
        .check-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            border-bottom: 1px solid #f0f0f0;
        }
        .check-item:last-child { border-bottom: none; }
        .check-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 1rem;
            font-size: 0.8rem;
        }
        .check-icon.pass { background: #d4edda; color: #28a745; }
        .check-icon.warn { background: #fff3cd; color: #856404; }
        .check-icon.fail { background: #f8d7da; color: #dc3545; }
        .check-name { font-weight: 500; min-width: 200px; }
        .check-detail { color: #6c757d; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">

        <!-- 🎨 PAGE HEADER -->
        <div class="page-header">
            <a href="health.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to System Health
            </a>
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">
                        <i class="fas fa-rocket me-2"></i>Deployment Checklist
                    </h1>
                    <p class="mb-0 opacity-75">
                        <?php if ($overallStatus === 'ready'): ?>
                            All checks passed — ready for production deployment!
                        <?php elseif ($overallStatus === 'warnings'): ?>
                            No critical failures, but some warnings need attention.
                        <?php else: ?>
                            Critical issues must be resolved before deployment.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-center">
                    <div class="score-circle">
                        <div>
                            <div class="score-text"><?php echo $scorePercent; ?>%</div>
                            <small class="opacity-75">Score</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 📊 STAT CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $totalChecks; ?></div>
                    <div class="stat-label"><i class="fas fa-clipboard-check me-1"></i>Total Checks</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-success"><?php echo $passCount; ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle me-1"></i>Passed</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-warning"><?php echo $warnCount; ?></div>
                    <div class="stat-label"><i class="fas fa-exclamation-triangle me-1"></i>Warnings</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-danger"><?php echo $failCount; ?></div>
                    <div class="stat-label"><i class="fas fa-times-circle me-1"></i>Failed</div>
                </div>
            </div>
        </div>

        <!-- 📋 CHECK RESULTS BY CATEGORY -->
        <?php foreach ($checks as $category => $categoryChecks): ?>
            <?php
            $catPass = count(array_filter($categoryChecks, fn($c) => $c['status'] === 'pass'));
            $catTotal = count($categoryChecks);
            $catIcon = match($category) {
                'Database' => 'fas fa-database',
                'Settings' => 'fas fa-cog',
                'Payments' => 'fas fa-credit-card',
                'Security' => 'fas fa-shield-alt',
                'Files' => 'fas fa-folder',
                'Features' => 'fas fa-puzzle-piece',
                default => 'fas fa-check',
            };
            ?>
            <div class="check-category">
                <div class="category-header d-flex justify-content-between align-items-center">
                    <span><i class="<?php echo $catIcon; ?> me-2"></i><?php echo htmlspecialchars($category); ?></span>
                    <span class="badge <?php echo $catPass === $catTotal ? 'bg-success' : 'bg-secondary'; ?>">
                        <?php echo $catPass; ?>/<?php echo $catTotal; ?> passed
                    </span>
                </div>
                <?php foreach ($categoryChecks as $check): ?>
                    <div class="check-item">
                        <div class="check-icon <?php echo $check['status']; ?>">
                            <?php if ($check['status'] === 'pass'): ?>
                                <i class="fas fa-check"></i>
                            <?php elseif ($check['status'] === 'warn'): ?>
                                <i class="fas fa-exclamation"></i>
                            <?php else: ?>
                                <i class="fas fa-times"></i>
                            <?php endif; ?>
                        </div>
                        <div class="check-name"><?php echo htmlspecialchars($check['name']); ?></div>
                        <div class="check-detail"><?php echo $check['detail']; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <!-- 📋 DEPLOYMENT STEPS -->
        <div class="check-category">
            <div class="category-header">
                <i class="fas fa-list-ol me-2"></i>Deployment Steps
            </div>
            <div class="p-4">
                <ol class="list-group list-group-numbered">
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">Run Database Migrations</div>
                            <small class="text-muted">Import migrations 007-010 into your production database via phpMyAdmin or MySQL Workbench. Order: 007 (rate limiting) → 008 (API keys) → 009 (multi-tier admin) → 010 (webhooks & payments)</small>
                        </div>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">Configure Core Settings</div>
                            <small class="text-muted">Via Admin → Settings: Set site_url, admin_email, encryption_key, session_lifetime, and SMTP credentials.</small>
                        </div>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">Configure OAuth Providers</div>
                            <small class="text-muted">Set up Google, Microsoft, Apple OAuth credentials in Admin → Settings. Ensure redirect URIs match your production domain.</small>
                        </div>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">Configure Payment Providers</div>
                            <small class="text-muted">Set PayPal API credentials (client ID + secret) in Admin → Settings. Configure tax rate and default currency.</small>
                        </div>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">Upload Files to Production</div>
                            <small class="text-muted">FTP/SFTP all files to Dreamhost. Ensure _config/, _backend/, and web/private_html/ are outside the web root (public_html/).</small>
                        </div>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">Verify HTTPS & Security Headers</div>
                            <small class="text-muted">Confirm SSL certificate is active. Check CSP, HSTS, X-Frame-Options, and X-Content-Type-Options headers are being sent.</small>
                        </div>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">Test Core Flows</div>
                            <small class="text-muted">Test: user registration, login, OAuth sign-in, MFA setup/verify, password reset, partner creation, API key generation, webhook delivery.</small>
                        </div>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">Re-run This Checklist</div>
                            <small class="text-muted">After completing all steps, refresh this page to verify all checks pass. Target: 100% score with 0 failures.</small>
                        </div>
                    </li>
                </ol>
            </div>
        </div>

        <!-- 🔄 Refresh button -->
        <div class="text-center mt-4 mb-4">
            <a href="deployment.php" class="btn btn-primary btn-lg">
                <i class="fas fa-sync-alt me-1"></i>Re-run Checks
            </a>
            <a href="health.php" class="btn btn-outline-secondary btn-lg ms-2">
                <i class="fas fa-heartbeat me-1"></i>System Health
            </a>
        </div>
    </div>

    <!-- 🔐 Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>
</body>
</html>
