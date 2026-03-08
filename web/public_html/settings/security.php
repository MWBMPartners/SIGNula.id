<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Security Settings
 * ============================================================================
 *
 * Purpose: Manage account security settings
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

$pageTitle = 'Security Settings';
$user = getCurrentUser();
$userID = $_SESSION['userID'];

$message = null;
$messageType = null;

// 📝 Handle password change
// Uses Auth::changePassword() which handles password history, strength validation,
// Argon2id hashing, and security notification emails.
// @see web/private_html/auth/Auth.php — Auth::changePassword()
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    // 🛡️ Verify CSRF token
    // @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please try again.';
        $messageType = 'danger';
    } else {
        $currentPassword = $_POST['currentPassword'] ?? '';
        $newPassword = $_POST['newPassword'] ?? '';
        $confirmPassword = $_POST['confirmPassword'] ?? '';

        // ✅ Validate confirmation match before calling Auth (quick client-side check)
        if ($newPassword !== $confirmPassword) {
            $message = 'Password confirmation does not match.';
            $messageType = 'danger';
        } elseif (empty($currentPassword) || empty($newPassword)) {
            $message = 'All password fields are required.';
            $messageType = 'danger';
        } else {
            // 🔄 Delegate to Auth::changePassword() for full validation
            // Handles: current password check, strength validation, history check,
            // Argon2id hashing, activity logging, and notification email
            $result = Auth::changePassword($userID, $currentPassword, $newPassword);
            $message = htmlspecialchars($result['message']);
            $messageType = $result['success'] ? 'success' : 'danger';
        }
    }
}

// 🎫 Generate CSRF token for the password change form
$csrfToken = SecurityUtils::generateCSRFToken();

// 📊 Get security statistics
$securityStats = [
    'passkeys_count' => 0,
    'mfa_enabled' => false,
    'recent_logins' => 0,
    'passwordless_enabled' => false
];

// PassKeys
$passkeyCount = Database::fetchOne(
    "SELECT COUNT(*) as count FROM tblWebAuthnCredentials WHERE userID = ? AND isActive = 1",
    [$userID],
    'i'
);
$securityStats['passkeys_count'] = $passkeyCount['count'] ?? 0;

// MFA
$securityStats['mfa_enabled'] = !empty($user['mfaEnabled']) && $user['mfaEnabled'] == 1;

// Passwordless
$securityStats['passwordless_enabled'] = !empty($user['passwordlessEnabled']) && $user['passwordlessEnabled'] == 1;

// Recent logins
$recentLogins = Database::fetchAll(
    "SELECT * FROM tblActivityLog
     WHERE userID = ? AND activityType = 'login'
     ORDER BY loggedAt DESC
     LIMIT 5",
    [$userID],
    'i'
);
$securityStats['recent_logins'] = count($recentLogins);

// Calculate security score
$securityScore = 0;
if (!empty($user['passwordHash'])) $securityScore += 25;
if ($securityStats['mfa_enabled']) $securityScore += 25;
if ($securityStats['passkeys_count'] > 0) $securityScore += 25;
if ($securityStats['passwordless_enabled']) $securityScore += 15;
if (!empty($user['emailVerified'])) $securityScore += 10;

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
            <!-- Security Score -->
            <div class="card shadow mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">🛡️ Security Score</h5>

                    <div class="progress mb-3" style="height: 30px;">
                        <div
                            class="progress-bar <?php echo $securityScore >= 75 ? 'bg-success' : ($securityScore >= 50 ? 'bg-warning' : 'bg-danger'); ?>"
                            role="progressbar"
                            style="width: <?php echo $securityScore; ?>%"
                            aria-valuenow="<?php echo $securityScore; ?>"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        >
                            <strong><?php echo $securityScore; ?>%</strong>
                        </div>
                    </div>

                    <p class="mb-0">
                        <?php if ($securityScore >= 75): ?>
                            <strong class="text-success">✅ Excellent!</strong> Your account security is strong.
                        <?php elseif ($securityScore >= 50): ?>
                            <strong class="text-warning">⚠️ Good</strong> but there's room for improvement.
                        <?php else: ?>
                            <strong class="text-danger">❌ Weak</strong> - Please strengthen your account security.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Change Password -->
            <div class="card shadow mb-4" id="change-password">
                <div class="card-body p-4">
                    <h5 class="card-title mb-4">🔐 Change Password</h5>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="change_password">
                        <!-- 🛡️ CSRF Token -->
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                        <div class="mb-3">
                            <label for="currentPassword" class="form-label">Current Password *</label>
                            <input
                                type="password"
                                class="form-control"
                                id="currentPassword"
                                name="currentPassword"
                                required
                                autocomplete="current-password"
                            >
                        </div>

                        <div class="mb-3">
                            <label for="newPassword" class="form-label">New Password *</label>
                            <input
                                type="password"
                                class="form-control"
                                id="newPassword"
                                name="newPassword"
                                required
                                minlength="8"
                                autocomplete="new-password"
                            >
                            <div class="form-text">Minimum 8 characters</div>
                        </div>

                        <div class="mb-3">
                            <label for="confirmPassword" class="form-label">Confirm New Password *</label>
                            <input
                                type="password"
                                class="form-control"
                                id="confirmPassword"
                                name="confirmPassword"
                                required
                                minlength="8"
                                autocomplete="new-password"
                            >
                        </div>

                        <div class="alert alert-info small">
                            <strong>💡 Password Requirements:</strong>
                            <ul class="mb-0 mt-2">
                                <li>At least 12 characters long</li>
                                <li>At least one uppercase letter</li>
                                <li>At least one lowercase letter</li>
                                <li>At least one number</li>
                                <li>At least one special character</li>
                                <li>Cannot reuse your last <?php echo htmlspecialchars(getSetting('security.password.history_count', '5')); ?> passwords</li>
                            </ul>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            🔐 Change Password
                        </button>
                    </form>
                </div>
            </div>

            <!-- Authentication Methods -->
            <div class="card shadow mb-4">
                <div class="card-body p-4">
                    <h5 class="card-title mb-4">🔑 Authentication Methods</h5>

                    <!-- PassKeys -->
                    <div class="d-flex align-items-start mb-4 pb-4 border-bottom">
                        <div class="me-3 fs-3">🔑</div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">PassKeys</h6>
                            <p class="text-muted small mb-2">
                                Use your device's biometric authentication to log in securely
                            </p>
                            <?php if ($securityStats['passkeys_count'] > 0): ?>
                                <span class="badge bg-success me-2">✅ Enabled</span>
                                <span class="text-muted small"><?php echo $securityStats['passkeys_count']; ?> PassKey(s) registered</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">❌ Not Configured</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <a href="/settings/passkeys" class="btn btn-sm btn-outline-primary">
                                Manage
                            </a>
                        </div>
                    </div>

                    <!-- Two-Factor Authentication -->
                    <div class="d-flex align-items-start mb-4 pb-4 border-bottom">
                        <div class="me-3 fs-3">📱</div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Two-Factor Authentication (2FA)</h6>
                            <p class="text-muted small mb-2">
                                Add an extra layer of security with authenticator apps
                            </p>
                            <?php if ($securityStats['mfa_enabled']): ?>
                                <span class="badge bg-success">✅ Enabled</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">⚠️ Disabled</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <a href="/settings/mfa" class="btn btn-sm btn-<?php echo $securityStats['mfa_enabled'] ? 'outline-primary' : 'warning'; ?>">
                                <?php echo $securityStats['mfa_enabled'] ? 'Manage' : 'Enable'; ?>
                            </a>
                        </div>
                    </div>

                    <!-- Passwordless Login -->
                    <div class="d-flex align-items-start">
                        <div class="me-3 fs-3">📧</div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Passwordless Email Login</h6>
                            <p class="text-muted small mb-2">
                                Receive secure login links via email
                            </p>
                            <?php if ($securityStats['passwordless_enabled']): ?>
                                <span class="badge bg-success">✅ Enabled</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">❌ Disabled</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-secondary" disabled>
                                Always Available
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Login Activity -->
            <div class="card shadow">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="card-title mb-0">🔐 Recent Login Activity</h5>
                        <a href="/settings/activity" class="btn btn-sm btn-outline-primary">
                            View All Activity
                        </a>
                    </div>

                    <?php if (empty($recentLogins)): ?>
                        <p class="text-muted text-center py-3">No recent login activity</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($recentLogins as $login): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="d-flex align-items-center mb-1">
                                                <?php if ($login['activityResult'] === 'success'): ?>
                                                    <span class="badge bg-success me-2">✅ Success</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger me-2">❌ Failed</span>
                                                <?php endif; ?>
                                                <strong><?php echo date('M j, Y g:i A', strtotime($login['loggedAt'])); ?></strong>
                                            </div>
                                            <?php if ($login['activityDetails']): ?>
                                                <small class="text-muted d-block">
                                                    <?php echo htmlspecialchars($login['activityDetails']); ?>
                                                </small>
                                            <?php endif; ?>
                                            <?php if ($login['ipAddress']): ?>
                                                <small class="text-muted d-block">
                                                    IP: <?php echo htmlspecialchars($login['ipAddress']); ?>
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
