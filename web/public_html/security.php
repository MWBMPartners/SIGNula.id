<?php
/**
 * ============================================================================
 * 🔒 SIGNula - Security Settings Page
 * ============================================================================
 *
 * Purpose: Security management - MFA, sessions, devices
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔐 Require authentication
Auth::requireAuth();

// 👤 Get current user data
$user = Auth::getCurrentUser();

// 📝 Handle form submissions
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ Verify CSRF token
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            switch ($action) {
                case 'terminate_session':
                    // 🗑️ Terminate specific session
                    $sessionID = (int)($_POST['session_id'] ?? 0);
                    $currentSessionID = $_SESSION['session_id'] ?? null;

                    if ($sessionID === $currentSessionID) {
                        $error = 'You cannot terminate your current session from here. Please use logout.';
                    } else {
                        Database::query(
                            "UPDATE tblUserSessions SET isActive = FALSE WHERE sessionID = ? AND userID = ?",
                            [$sessionID, $user['userID']],
                            'ii'
                        );

                        ActivityLogger::log($user['userID'], 'session_terminated', 'security', 'info',
                            'Session terminated manually', ['session_id' => $sessionID]);

                        $success = 'Session terminated successfully!';
                    }
                    break;

                case 'terminate_all_sessions':
                    // 🗑️ Terminate all other sessions
                    $currentSessionID = $_SESSION['session_id'] ?? null;

                    if ($currentSessionID) {
                        Database::query(
                            "UPDATE tblUserSessions SET isActive = FALSE WHERE userID = ? AND sessionID != ?",
                            [$user['userID'], $currentSessionID],
                            'ii'
                        );

                        ActivityLogger::log($user['userID'], 'all_sessions_terminated', 'security', 'warning',
                            'All other sessions terminated');

                        $success = 'All other sessions have been terminated!';
                    }
                    break;

                case 'remove_device':
                    // 🗑️ Remove trusted device
                    $deviceID = (int)($_POST['device_id'] ?? 0);

                    Database::query(
                        "UPDATE tblUserDevices SET isActive = FALSE WHERE deviceID = ? AND userID = ?",
                        [$deviceID, $user['userID']],
                        'ii'
                    );

                    ActivityLogger::log($user['userID'], 'device_removed', 'security', 'info',
                        'Trusted device removed', ['device_id' => $deviceID]);

                    $success = 'Device removed successfully!';
                    break;

                case 'disable_totp':
                    // 🚫 Disable TOTP MFA
                    MFA::disableMethod($user['userID'], 'totp');
                    $success = 'Authenticator app has been disabled!';
                    break;

                case 'regenerate_backup_codes':
                    // 🔄 Regenerate backup codes
                    if (MFA::isEnabled($user['userID'])) {
                        $newBackupCodes = MFA::generateBackupCodes($user['userID'], true);
                        $_SESSION['new_backup_codes'] = $newBackupCodes;
                        $success = 'New backup codes generated! Please save them securely.';
                    } else {
                        $error = 'MFA must be enabled to generate backup codes.';
                    }
                    break;

                case 'disable_all_mfa':
                    // 🚫 Disable all MFA methods
                    $methods = MFA::getEnabledMethods($user['userID']);
                    foreach ($methods as $method) {
                        MFA::disableMethod($user['userID'], $method);
                    }

                    // Also remove all backup codes
                    Database::query(
                        "DELETE FROM tblUserMFA WHERE userID = ? AND mfaType = 'backup_code'",
                        [$user['userID']],
                        'i'
                    );

                    ActivityLogger::log($user['userID'], 'mfa_all_disabled', 'security', 'warning',
                        'All MFA methods disabled');

                    $success = 'All multi-factor authentication methods have been disabled!';
                    break;
            }

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            $error = 'An error occurred. Please try again.';
        }
    }
}

// 📊 Get active sessions
$activeSessions = [];
try {
    $activeSessions = Database::fetchAll(
        "SELECT sessionID, deviceType, deviceName, ipAddress, browserName, osName,
                createdAt, lastActivityAt, expiresAt
         FROM tblUserSessions
         WHERE userID = ? AND isActive = TRUE AND expiresAt > NOW()
         ORDER BY lastActivityAt DESC",
        [$user['userID']],
        'i'
    );
} catch (Exception $e) {
    ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
}

// 📱 Get trusted devices
$trustedDevices = [];
try {
    $trustedDevices = Database::fetchAll(
        "SELECT deviceID, deviceName, deviceType, deviceOS, deviceModel,
                isTrusted, lastSeenAt, registeredAt
         FROM tblUserDevices
         WHERE userID = ? AND isActive = TRUE
         ORDER BY lastSeenAt DESC",
        [$user['userID']],
        'i'
    );
} catch (Exception $e) {
    ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
}

// 🔐 Get MFA status
$mfaEnabled = MFA::isEnabled($user['userID']);
$mfaMethods = MFA::getEnabledMethods($user['userID']);
$remainingBackupCodes = MFA::getRemainingBackupCodes($user['userID']);

// 🎲 Check for newly generated backup codes in session
$newBackupCodes = $_SESSION['new_backup_codes'] ?? [];
if (!empty($newBackupCodes)) {
    unset($_SESSION['new_backup_codes']); // Clear after displaying once
}

// 🎫 Generate CSRF token
$csrfToken = SecurityUtils::generateCSRFToken();

// 📄 Page metadata
$pageTitle = 'Security Settings - SIGNula';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <!-- 🎨 Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">

    <!-- 🎨 Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <!-- 🎨 Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" />

    <!-- 🎨 Custom CSS -->
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body style="background: #f9fafb;">

<!-- 🧭 Top Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <div class="container-fluid">
        <a class="navbar-brand" href="/dashboard">
            <i class="fas fa-shield-alt"></i> <strong>SIGNula</strong>
        </a>
        <button class="navbar-toggler" type="button" id="mobile-menu-toggle">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="/dashboard">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; margin-right: 8px;">
                            <i class="fas fa-user"></i>
                        </div>
                        <span><?php echo htmlspecialchars($user['displayName'] ?? $user['username']); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="/account"><i class="fas fa-cog"></i> Account Settings</a></li>
                        <li><a class="dropdown-item active" href="/security"><i class="fas fa-lock"></i> Security</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- 📱 Page Content -->
<div class="container" style="padding: 2rem 0; max-width: 1000px;">
    <!-- 📍 Breadcrumb -->
    <nav aria-label="breadcrumb" style="margin-bottom: 1.5rem;">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/dashboard">Dashboard</a></li>
            <li class="breadcrumb-item active">Security Settings</li>
        </ol>
    </nav>

    <!-- 🎯 Page Header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 style="margin: 0; font-size: 2rem; font-weight: 700;">
            <i class="fas fa-lock"></i> Security Settings
        </h1>
    </div>

    <!-- 🚨 Alert Messages -->
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
            <button type="button" class="alert-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
            <button type="button" class="alert-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- 🔐 Two-Factor Authentication -->
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-shield-alt"></i> Two-Factor Authentication</h3>
        </div>
        <div class="card-body">
            <?php if (!$mfaEnabled): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><strong>Not Protected:</strong> Enable two-factor authentication to add an extra layer of security to your account.</span>
                </div>

                <h4 style="margin-bottom: 1rem;">Available Methods:</h4>

                <!-- TOTP Authenticator App -->
                <div class="d-flex align-items-center justify-content-between mb-3 pb-3" style="border-bottom: 1px solid #dee2e6;">
                    <div class="d-flex align-items-center">
                        <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(102, 126, 234, 0.1); display: flex; align-items: center; justify-content: center; margin-right: 1rem;">
                            <i class="fas fa-mobile-alt" style="color: #667eea; font-size: 1.5rem;"></i>
                        </div>
                        <div>
                            <strong>Authenticator App</strong>
                            <p style="margin: 0; color: #6c757d; font-size: 0.875rem;">
                                Use an app like Google Authenticator or Microsoft Authenticator
                            </p>
                        </div>
                    </div>
                    <a href="/mfa/setup?step=choose" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i> Set Up
                    </a>
                </div>

                <!-- Email OTP -->
                <div class="d-flex align-items-center justify-content-between mb-3 pb-3" style="border-bottom: 1px solid #dee2e6;">
                    <div class="d-flex align-items-center">
                        <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(59, 130, 246, 0.1); display: flex; align-items: center; justify-content: center; margin-right: 1rem;">
                            <i class="fas fa-envelope" style="color: #3b82f6; font-size: 1.5rem;"></i>
                        </div>
                        <div>
                            <strong>Email Verification</strong>
                            <p style="margin: 0; color: #6c757d; font-size: 0.875rem;">
                                Receive verification codes via email (available after setting up primary method)
                            </p>
                        </div>
                    </div>
                    <button class="btn btn-secondary btn-sm" disabled>
                        <i class="fas fa-clock"></i> Available after setup
                    </button>
                </div>

                <!-- Passkeys -->
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(16, 185, 129, 0.1); display: flex; align-items: center; justify-content: center; margin-right: 1rem;">
                            <i class="fas fa-key" style="color: #10b981; font-size: 1.5rem;"></i>
                        </div>
                        <div>
                            <strong>Passkey</strong>
                            <p style="margin: 0; color: #6c757d; font-size: 0.875rem;">
                                Use your device's biometric authentication (Coming Soon)
                            </p>
                        </div>
                    </div>
                    <button class="btn btn-secondary btn-sm" disabled>
                        <i class="fas fa-clock"></i> Coming Soon
                    </button>
                </div>

            <?php else: ?>
                <!-- ✅ MFA Enabled Status -->
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><strong>Protected:</strong> Your account is secured with two-factor authentication.</span>
                </div>

                <!-- 🎲 Display Newly Generated Backup Codes -->
                <?php if (!empty($newBackupCodes)): ?>
                    <div class="alert alert-warning">
                        <h5><i class="fas fa-key me-2"></i>New Backup Codes Generated</h5>
                        <p class="mb-3"><strong>Save these codes now!</strong> You won't be able to see them again.</p>
                        <div class="row">
                            <?php foreach ($newBackupCodes as $code): ?>
                                <div class="col-md-6 mb-2">
                                    <code style="display: block; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem; font-size: 1.1rem; letter-spacing: 0.1em;">
                                        <?php echo htmlspecialchars($code); ?>
                                    </code>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="printBackupCodes()">
                            <i class="fas fa-print"></i> Print Codes
                        </button>
                    </div>
                <?php endif; ?>

                <!-- 📊 Enabled Methods -->
                <h4 style="margin-bottom: 1rem;">Enabled Methods:</h4>

                <?php if (in_array('totp', $mfaMethods)): ?>
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-3" style="border-bottom: 1px solid #dee2e6;">
                        <div class="d-flex align-items-center">
                            <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(102, 126, 234, 0.1); display: flex; align-items: center; justify-content: center; margin-right: 1rem;">
                                <i class="fas fa-mobile-alt" style="color: #667eea; font-size: 1.5rem;"></i>
                            </div>
                            <div>
                                <strong>Authenticator App</strong>
                                <span class="badge bg-success ms-2">Active</span>
                                <p style="margin: 0; color: #6c757d; font-size: 0.875rem;">
                                    TOTP codes from your authenticator app
                                </p>
                            </div>
                        </div>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo SecurityUtils::generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="disable_totp">
                            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Are you sure you want to disable authenticator app? This will reduce your account security.')">
                                <i class="fas fa-times"></i> Disable
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- 🎲 Backup Codes Section -->
                <div class="d-flex align-items-center justify-content-between mb-3 pb-3" style="border-bottom: 1px solid #dee2e6;">
                    <div class="d-flex align-items-center">
                        <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(255, 193, 7, 0.1); display: flex; align-items: center; justify-content: center; margin-right: 1rem;">
                            <i class="fas fa-key" style="color: #ffc107; font-size: 1.5rem;"></i>
                        </div>
                        <div>
                            <strong>Backup Codes</strong>
                            <?php if ($remainingBackupCodes > 0): ?>
                                <?php if ($remainingBackupCodes < 3): ?>
                                    <span class="badge bg-warning ms-2">Low</span>
                                <?php else: ?>
                                    <span class="badge bg-success ms-2">Active</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <p style="margin: 0; color: #6c757d; font-size: 0.875rem;">
                                <?php echo $remainingBackupCodes; ?> of 10 codes remaining
                            </p>
                        </div>
                    </div>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityUtils::generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="regenerate_backup_codes">
                        <button type="submit" class="btn btn-outline-primary btn-sm" onclick="return confirm('Generate new backup codes? This will invalidate your existing codes.')">
                            <i class="fas fa-sync"></i> Regenerate
                        </button>
                    </form>
                </div>

                <!-- 🚨 Disable All MFA -->
                <div class="mt-4 pt-3" style="border-top: 2px solid #dee2e6;">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityUtils::generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="disable_all_mfa">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('⚠️ WARNING: This will disable all two-factor authentication on your account and make it less secure. Are you absolutely sure?')">
                            <i class="fas fa-shield-slash"></i> Disable All MFA
                        </button>
                        <small class="text-muted d-block mt-2">
                            <i class="fas fa-exclamation-triangle"></i> This will remove all two-factor authentication from your account
                        </small>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function printBackupCodes() {
        window.print();
    }
    </script>

    <!-- 🎫 Active Sessions -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h3 class="card-title" style="margin: 0;"><i class="fas fa-desktop"></i> Active Sessions</h3>
            <?php if (count($activeSessions) > 1): ?>
                <form method="POST" action="/security" style="margin: 0;" onsubmit="return confirm('Are you sure you want to terminate all other sessions?');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="terminate_all_sessions">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-sign-out-alt"></i> Terminate All Others
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($activeSessions)): ?>
                <div style="padding: 2rem; text-align: center; color: var(--text-muted);">
                    <i class="fas fa-desktop" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                    <p style="margin: 0;">No active sessions</p>
                </div>
            <?php else: ?>
                <?php $currentSessionID = $_SESSION['session_id'] ?? null; ?>
                <?php foreach ($activeSessions as $index => $session): ?>
                    <?php $isCurrentSession = $session['sessionID'] == $currentSessionID; ?>
                    <div style="padding: 1.5rem; <?php echo $index < count($activeSessions) - 1 ? 'border-bottom: 1px solid var(--border-color);' : ''; ?>">
                        <div class="d-flex align-items-start justify-content-between">
                            <div class="d-flex align-items-start" style="flex: 1;">
                                <div style="width: 48px; height: 48px; border-radius: 50%; background: var(--bg-light); display: flex; align-items: center; justify-content: center; margin-right: 1rem; flex-shrink: 0;">
                                    <i class="fas fa-<?php
                                        echo match($session['deviceType']) {
                                            'mobile_ios', 'mobile_android' => 'mobile-alt',
                                            'tablet' => 'tablet-alt',
                                            'desktop' => 'desktop',
                                            default => 'globe'
                                        };
                                    ?>" style="font-size: 1.25rem; color: var(--primary-color);"></i>
                                </div>
                                <div style="flex: 1;">
                                    <div class="d-flex align-items-center gap-2">
                                        <strong><?php echo htmlspecialchars($session['deviceName'] ?? 'Unknown Device'); ?></strong>
                                        <?php if ($isCurrentSession): ?>
                                            <span class="badge" style="background: var(--success-color); color: white;">
                                                <i class="fas fa-check"></i> Current Session
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p style="margin: 0.25rem 0 0; color: var(--text-secondary); font-size: 0.875rem;">
                                        <i class="fas fa-globe"></i> <?php echo htmlspecialchars($session['ipAddress']); ?>
                                        <br>
                                        <i class="fas fa-browser"></i> <?php echo htmlspecialchars($session['browserName'] ?? 'Unknown'); ?>
                                        on <?php echo htmlspecialchars($session['osName'] ?? 'Unknown'); ?>
                                        <br>
                                        <i class="fas fa-clock"></i> Last active: <?php
                                            $lastActivity = new DateTime($session['lastActivityAt']);
                                            $now = new DateTime();
                                            $diff = $now->diff($lastActivity);
                                            if ($diff->days > 0) {
                                                echo $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
                                            } elseif ($diff->h > 0) {
                                                echo $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
                                            } elseif ($diff->i > 0) {
                                                echo $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
                                            } else {
                                                echo 'Just now';
                                            }
                                        ?>
                                    </p>
                                </div>
                            </div>
                            <?php if (!$isCurrentSession): ?>
                                <form method="POST" action="/security" style="margin: 0;" onsubmit="return confirm('Are you sure you want to terminate this session?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="terminate_session">
                                    <input type="hidden" name="session_id" value="<?php echo $session['sessionID']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        <i class="fas fa-times"></i> Terminate
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 📱 Trusted Devices -->
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-mobile-alt"></i> Trusted Devices</h3>
        </div>
        <div class="card-body">
            <?php if (empty($trustedDevices)): ?>
                <div style="text-align: center; color: var(--text-muted); padding: 2rem;">
                    <i class="fas fa-mobile-alt" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                    <p style="margin: 0;">No trusted devices registered</p>
                    <small style="display: block; margin-top: 0.5rem;">Devices will appear here when you register them for quick access</small>
                </div>
            <?php else: ?>
                <p style="color: var(--text-secondary); margin-bottom: 1.5rem; font-size: 0.875rem;">
                    Trusted devices can be used for faster authentication without requiring MFA each time.
                </p>
                <?php foreach ($trustedDevices as $device): ?>
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-3" style="border-bottom: 1px solid var(--border-color);">
                        <div class="d-flex align-items-center">
                            <div style="width: 48px; height: 48px; border-radius: 50%; background: var(--bg-light); display: flex; align-items: center; justify-content: center; margin-right: 1rem;">
                                <i class="fas fa-<?php
                                    echo match($device['deviceType']) {
                                        'mobile_ios', 'mobile_android' => 'mobile-alt',
                                        'tablet' => 'tablet-alt',
                                        default => 'laptop'
                                    };
                                ?>" style="font-size: 1.25rem; color: var(--primary-color);"></i>
                            </div>
                            <div>
                                <strong><?php echo htmlspecialchars($device['deviceName'] ?? 'Unknown Device'); ?></strong>
                                <?php if ($device['isTrusted']): ?>
                                    <span class="badge" style="background: var(--success-color); color: white; margin-left: 0.5rem;">
                                        <i class="fas fa-check"></i> Trusted
                                    </span>
                                <?php endif; ?>
                                <p style="margin: 0.25rem 0 0; color: var(--text-secondary); font-size: 0.875rem;">
                                    <?php echo htmlspecialchars($device['deviceOS']); ?>
                                    • Last seen: <?php echo date('M d, Y', strtotime($device['lastSeenAt'])); ?>
                                </p>
                            </div>
                        </div>
                        <form method="POST" action="/security" style="margin: 0;" onsubmit="return confirm('Are you sure you want to remove this device?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="remove_device">
                            <input type="hidden" name="device_id" value="<?php echo $device['deviceID']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i> Remove
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 📊 Security Tips -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-lightbulb"></i> Security Tips</h3>
        </div>
        <div class="card-body">
            <ul style="margin: 0; padding-left: 1.5rem;">
                <li style="margin-bottom: 0.5rem;">Enable two-factor authentication for enhanced security</li>
                <li style="margin-bottom: 0.5rem;">Use a unique, strong password for your account</li>
                <li style="margin-bottom: 0.5rem;">Review your active sessions regularly and terminate any suspicious activity</li>
                <li style="margin-bottom: 0.5rem;">Only mark devices as trusted if you're the sole user</li>
                <li style="margin-bottom: 0;">Change your password immediately if you suspect unauthorized access</li>
            </ul>
        </div>
    </div>
</div>

<!-- 📜 JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="/assets/js/main.js"></script>

</body>
</html>
