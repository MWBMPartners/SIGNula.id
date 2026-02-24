<?php
/**
 * ============================================================================
 * ⚙️ SIGNula - Account Settings Dashboard
 * ============================================================================
 *
 * Purpose: Main account settings overview page
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔒 Require login
requireLogin();

$pageTitle = 'Account Settings';
$user = getCurrentUser();
$userID = $_SESSION['userID'];

// 📊 Get account statistics
$stats = [
    'passkeys' => 0,
    'mfa_enabled' => false,
    'connected_accounts' => 0,
    'recent_activity' => 0
];

// Get PassKey count
$passkeyCount = Database::fetchOne(
    "SELECT COUNT(*) as count FROM tblWebAuthnCredentials WHERE userID = ? AND isActive = 1",
    [$userID],
    'i'
);
$stats['passkeys'] = $passkeyCount['count'] ?? 0;

// Check MFA status
$stats['mfa_enabled'] = !empty($user['mfaEnabled']) && $user['mfaEnabled'] == 1;

// Get connected accounts count
$connectedAccounts = Database::fetchOne(
    "SELECT COUNT(*) as count FROM tblOAuthTokens WHERE userID = ? AND isActive = 1",
    [$userID],
    'i'
);
$stats['connected_accounts'] = $connectedAccounts['count'] ?? 0;

// Get recent activity count (last 7 days)
$recentActivity = Database::fetchOne(
    "SELECT COUNT(*) as count FROM tblActivityLog
     WHERE userID = ? AND loggedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
    [$userID],
    'i'
);
$stats['recent_activity'] = $recentActivity['count'] ?? 0;

// 🎨 Include header
include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container mt-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 mb-4">
            <?php include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'settings-sidebar.php'; ?>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <!-- Welcome Banner -->
            <div class="card shadow mb-4">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 64px; height: 64px; font-size: 32px;">
                                👤
                            </div>
                        </div>
                        <div class="flex-grow-1">
                            <h1 class="h3 mb-1">
                                <?php echo htmlspecialchars($user['displayName'] ?? 'User'); ?>
                            </h1>
                            <p class="text-muted mb-0">
                                <?php echo htmlspecialchars($user['email']); ?>
                            </p>
                        </div>
                        <div>
                            <a href="/settings/profile" class="btn btn-primary">
                                ✏️ Edit Profile
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="row mb-4">
                <div class="col-md-6 col-lg-3 mb-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="display-4 mb-2">🔑</div>
                            <h5 class="card-title mb-1"><?php echo $stats['passkeys']; ?></h5>
                            <p class="text-muted small mb-0">PassKeys</p>
                            <a href="/settings/passkeys" class="btn btn-sm btn-outline-primary mt-2">
                                Manage
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3 mb-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="display-4 mb-2">
                                <?php echo $stats['mfa_enabled'] ? '✅' : '⚠️'; ?>
                            </div>
                            <h5 class="card-title mb-1">
                                <?php echo $stats['mfa_enabled'] ? 'Enabled' : 'Disabled'; ?>
                            </h5>
                            <p class="text-muted small mb-0">Two-Factor Auth</p>
                            <a href="/settings/mfa" class="btn btn-sm btn-outline-primary mt-2">
                                Configure
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3 mb-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="display-4 mb-2">🔗</div>
                            <h5 class="card-title mb-1"><?php echo $stats['connected_accounts']; ?></h5>
                            <p class="text-muted small mb-0">Connected Accounts</p>
                            <a href="/settings/connected-accounts" class="btn btn-sm btn-outline-primary mt-2">
                                Manage
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3 mb-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="display-4 mb-2">📊</div>
                            <h5 class="card-title mb-1"><?php echo $stats['recent_activity']; ?></h5>
                            <p class="text-muted small mb-0">Recent Activities</p>
                            <a href="/settings/activity" class="btn btn-sm btn-outline-primary mt-2">
                                View Log
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">⚡ Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-start">
                                <div class="me-3 fs-3">🔐</div>
                                <div>
                                    <h6 class="mb-1">Change Password</h6>
                                    <p class="text-muted small mb-2">Update your account password</p>
                                    <a href="/settings/security#change-password" class="btn btn-sm btn-primary">
                                        Change Password
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-start">
                                <div class="me-3 fs-3">📱</div>
                                <div>
                                    <h6 class="mb-1">Two-Factor Authentication</h6>
                                    <p class="text-muted small mb-2">
                                        <?php if ($stats['mfa_enabled']): ?>
                                            Currently enabled - Manage your MFA devices
                                        <?php else: ?>
                                            Add an extra layer of security
                                        <?php endif; ?>
                                    </p>
                                    <a href="/settings/mfa" class="btn btn-sm btn-<?php echo $stats['mfa_enabled'] ? 'outline-primary' : 'warning'; ?>">
                                        <?php echo $stats['mfa_enabled'] ? 'Manage MFA' : 'Enable MFA'; ?>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-start">
                                <div class="me-3 fs-3">🔑</div>
                                <div>
                                    <h6 class="mb-1">PassKeys</h6>
                                    <p class="text-muted small mb-2">
                                        <?php if ($stats['passkeys'] > 0): ?>
                                            You have <?php echo $stats['passkeys']; ?> PassKey(s) registered
                                        <?php else: ?>
                                            Login with biometrics - No password needed
                                        <?php endif; ?>
                                    </p>
                                    <a href="<?php echo $stats['passkeys'] > 0 ? '/settings/passkeys' : '/auth/passkey-register'; ?>" class="btn btn-sm btn-primary">
                                        <?php echo $stats['passkeys'] > 0 ? 'Manage PassKeys' : 'Add PassKey'; ?>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-start">
                                <div class="me-3 fs-3">🔗</div>
                                <div>
                                    <h6 class="mb-1">Connected Accounts</h6>
                                    <p class="text-muted small mb-2">
                                        Link Google, Microsoft, and other accounts
                                    </p>
                                    <a href="/settings/connected-accounts" class="btn btn-sm btn-primary">
                                        Manage Connections
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Recommendations -->
            <?php if (!$stats['mfa_enabled'] || $stats['passkeys'] === 0): ?>
            <div class="card shadow border-warning mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0">⚠️ Security Recommendations</h5>
                </div>
                <div class="card-body">
                    <p class="mb-3">
                        <strong>Strengthen your account security:</strong>
                    </p>

                    <ul class="mb-3">
                        <?php if (!$stats['mfa_enabled']): ?>
                        <li>
                            <strong>Enable Two-Factor Authentication</strong> - Add an extra layer of security to your account
                            <a href="/settings/mfa" class="btn btn-sm btn-warning ms-2">Enable Now</a>
                        </li>
                        <?php endif; ?>

                        <?php if ($stats['passkeys'] === 0): ?>
                        <li class="mt-2">
                            <strong>Add a PassKey</strong> - Login quickly and securely with biometrics
                            <a href="/auth/passkey-register" class="btn btn-sm btn-primary ms-2">Add PassKey</a>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <div class="alert alert-info small mb-0">
                        <strong>ℹ️ Why this matters:</strong> Enabling multiple authentication methods significantly reduces the risk of unauthorized access to your account.
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Activity Preview -->
            <div class="card shadow">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">📊 Recent Activity</h5>
                    <a href="/settings/activity" class="btn btn-sm btn-outline-primary">
                        View All
                    </a>
                </div>
                <div class="card-body">
                    <?php
                    // Get recent activity
                    $recentActivities = Database::fetchAll(
                        "SELECT * FROM tblActivityLog
                         WHERE userID = ?
                         ORDER BY loggedAt DESC
                         LIMIT 5",
                        [$userID],
                        'i'
                    );

                    if (empty($recentActivities)):
                    ?>
                        <p class="text-muted text-center py-3">No recent activity</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentActivities as $activity): ?>
                                <div class="list-group-item px-0">
                                    <div class="d-flex align-items-start">
                                        <div class="me-3">
                                            <?php
                                            $icon = match($activity['activityType']) {
                                                'login' => '🔐',
                                                'logout' => '🚪',
                                                'password_change' => '🔑',
                                                'mfa_enabled' => '📱',
                                                'mfa_disabled' => '❌',
                                                'profile_update' => '👤',
                                                default => '📌'
                                            };
                                            echo $icon;
                                            ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between">
                                                <div>
                                                    <strong><?php echo ucwords(str_replace('_', ' ', $activity['activityType'])); ?></strong>
                                                    <?php if ($activity['activityResult']): ?>
                                                        <span class="badge bg-<?php echo $activity['activityResult'] === 'success' ? 'success' : 'danger'; ?> ms-2">
                                                            <?php echo ucfirst($activity['activityResult']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo timeAgo($activity['loggedAt']); ?>
                                                </small>
                                            </div>
                                            <?php if ($activity['activityDetails']): ?>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($activity['activityDetails']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// 🎨 Include footer
include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'footer.php';
?>
