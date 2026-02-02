<?php
/**
 * ============================================================================
 * 📱 SIGNula - Two-Factor Authentication Management
 * ============================================================================
 *
 * Purpose: Manage MFA settings and devices
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.5.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔒 Require login
requireLogin();

$pageTitle = 'Two-Factor Authentication';
$user = getCurrentUser();
$userID = $_SESSION['userID'];

$message = null;
$messageType = null;

// 📝 Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'disable_mfa') {
        try {
            $password = $_POST['password'] ?? '';

            // ✅ Verify password
            if (empty($password)) {
                throw new Exception('Password is required to disable MFA');
            }

            if (!password_verify($password, $user['passwordHash'])) {
                throw new Exception('Incorrect password');
            }

            // 🔄 Disable MFA
            $query = "UPDATE tblUsers SET mfaEnabled = 0, mfaSecret = NULL, updatedAt = NOW() WHERE userID = ?";
            $result = Database::query($query, [$userID], 'i');

            if ($result) {
                // 🗑️ Remove all backup codes
                Database::query("DELETE FROM tblMFABackupCodes WHERE userID = ?", [$userID], 'i');

                // 📝 Log activity
                ActivityLogger::log(
                    userID: $userID,
                    activityType: 'mfa_disabled',
                    activityResult: 'success',
                    activityDetails: 'Two-factor authentication disabled'
                );

                $message = 'Two-factor authentication has been disabled.';
                $messageType = 'success';

                // Refresh user data
                $user = getCurrentUser(true);
            } else {
                throw new Exception('Failed to disable MFA');
            }

        } catch (Exception $e) {
            error_log("MFA disable error: " . $e->getMessage());
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    } elseif ($action === 'regenerate_codes') {
        try {
            // ✅ Verify MFA is enabled
            if (empty($user['mfaEnabled']) || $user['mfaEnabled'] != 1) {
                throw new Exception('MFA must be enabled to regenerate codes');
            }

            // 🗑️ Delete existing codes
            Database::query("DELETE FROM tblMFABackupCodes WHERE userID = ?", [$userID], 'i');

            // 🎲 Generate new backup codes
            $backupCodes = [];
            for ($i = 0; $i < 10; $i++) {
                $code = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(6))), 0, 8);
                $backupCodes[] = strtoupper($code);

                // 💾 Store hashed code
                $hashedCode = password_hash($code, PASSWORD_ARGON2ID);
                Database::query(
                    "INSERT INTO tblMFABackupCodes (userID, codeHash, createdAt) VALUES (?, ?, NOW())",
                    [$userID, $hashedCode],
                    'is'
                );
            }

            // 📝 Log activity
            ActivityLogger::log(
                userID: $userID,
                activityType: 'mfa_backup_codes_regenerated',
                activityResult: 'success',
                activityDetails: 'Backup recovery codes regenerated'
            );

            $message = 'New backup codes generated successfully. Please save them securely.';
            $messageType = 'success';

            // Store codes in session for display
            $_SESSION['new_backup_codes'] = $backupCodes;

        } catch (Exception $e) {
            error_log("Backup code regeneration error: " . $e->getMessage());
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// 🔍 Get MFA status
$mfaEnabled = !empty($user['mfaEnabled']) && $user['mfaEnabled'] == 1;
$mfaSecret = $user['mfaSecret'] ?? null;

// 🔍 Get backup codes count
$backupCodesCount = 0;
if ($mfaEnabled) {
    $result = Database::fetchOne(
        "SELECT COUNT(*) as count FROM tblMFABackupCodes WHERE userID = ? AND isUsed = 0",
        [$userID],
        'i'
    );
    $backupCodesCount = $result['count'] ?? 0;
}

// 🔍 Get last MFA usage
$lastMFAUsage = null;
if ($mfaEnabled) {
    $lastUsage = Database::fetchOne(
        "SELECT loggedAt FROM tblActivityLog
         WHERE userID = ? AND activityType = 'mfa_verified'
         ORDER BY loggedAt DESC LIMIT 1",
        [$userID],
        'i'
    );
    $lastMFAUsage = $lastUsage['loggedAt'] ?? null;
}

// 🎨 Include header
include SIGNULA_ROOT . '/_includes/layout/header.php';
?>

<div class="container mt-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 mb-4">
            <?php include SIGNULA_ROOT . '/_includes/layout/settings-sidebar.php'; ?>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <div class="card shadow">
                <div class="card-body p-4">
                    <h1 class="h3 mb-4">📱 Two-Factor Authentication</h1>

                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted mb-4">
                        Add an extra layer of security to your account by requiring a verification code in addition to your password.
                    </p>

                    <!-- MFA Status Card -->
                    <div class="card mb-4 border-<?php echo $mfaEnabled ? 'success' : 'warning'; ?>">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="me-3">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center <?php echo $mfaEnabled ? 'bg-success' : 'bg-warning'; ?>"
                                         style="width: 64px; height: 64px;">
                                        <i class="<?php echo $mfaEnabled ? 'fas fa-shield-alt' : 'fas fa-exclamation-triangle'; ?> text-white fs-3"></i>
                                    </div>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="mb-1">
                                        <?php if ($mfaEnabled): ?>
                                            Two-Factor Authentication is <span class="text-success">Enabled</span>
                                        <?php else: ?>
                                            Two-Factor Authentication is <span class="text-warning">Disabled</span>
                                        <?php endif; ?>
                                    </h5>
                                    <p class="text-muted mb-0">
                                        <?php if ($mfaEnabled): ?>
                                            Your account is protected with an additional security layer.
                                            <?php if ($lastMFAUsage): ?>
                                                Last used <?php echo timeAgo($lastMFAUsage); ?>.
                                            <?php endif; ?>
                                        <?php else: ?>
                                            We recommend enabling two-factor authentication to enhance your account security.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($mfaEnabled): ?>
                        <!-- MFA Enabled - Management Options -->

                        <!-- Authenticator App Section -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">
                                <i class="fas fa-mobile-alt"></i> Authenticator App
                            </h5>

                            <div class="alert alert-success">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-check-circle fs-3 me-3"></i>
                                    <div>
                                        <strong>Authenticator app is configured</strong>
                                        <p class="mb-0 small">
                                            You can use apps like Google Authenticator, Microsoft Authenticator, or Authy
                                            to generate verification codes.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-warning">
                                <strong><i class="fas fa-exclamation-triangle"></i> Important:</strong>
                                <p class="mb-0 small">
                                    If you lose access to your authenticator app, you can use your backup recovery codes
                                    to sign in. Make sure to save your backup codes in a secure location.
                                </p>
                            </div>
                        </div>

                        <!-- Backup Codes Section -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">
                                <i class="fas fa-key"></i> Backup Recovery Codes
                            </h5>

                            <?php if (isset($_SESSION['new_backup_codes'])): ?>
                                <!-- Display New Codes -->
                                <div class="alert alert-info">
                                    <h6 class="alert-heading">
                                        <i class="fas fa-exclamation-circle"></i> Save Your Backup Codes
                                    </h6>
                                    <p class="mb-2">
                                        These codes can be used to access your account if you lose your authenticator device.
                                        <strong>Each code can only be used once.</strong>
                                    </p>
                                    <p class="mb-3">
                                        <strong class="text-danger">
                                            Store these codes in a secure location. You won't be able to see them again!
                                        </strong>
                                    </p>

                                    <div class="card bg-dark text-white">
                                        <div class="card-body">
                                            <div class="row">
                                                <?php foreach ($_SESSION['new_backup_codes'] as $index => $code): ?>
                                                    <div class="col-md-6 mb-2">
                                                        <code class="text-white"><?php echo $index + 1; ?>. <?php echo $code; ?></code>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <button class="btn btn-sm btn-primary" onclick="printBackupCodes()">
                                            <i class="fas fa-print"></i> Print Codes
                                        </button>
                                        <button class="btn btn-sm btn-secondary" onclick="copyBackupCodes()">
                                            <i class="fas fa-copy"></i> Copy to Clipboard
                                        </button>
                                    </div>
                                </div>

                                <?php
                                // Clear codes from session after display
                                unset($_SESSION['new_backup_codes']);
                                ?>
                            <?php else: ?>
                                <!-- Backup Codes Status -->
                                <div class="card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1">Backup Recovery Codes</h6>
                                                <p class="text-muted small mb-0">
                                                    You have <strong><?php echo $backupCodesCount; ?></strong> unused backup code(s).
                                                </p>
                                            </div>
                                            <form method="POST" action="" class="d-inline"
                                                  onsubmit="return confirm('This will invalidate your existing backup codes. Are you sure?');">
                                                <input type="hidden" name="action" value="regenerate_codes">
                                                <button type="submit" class="btn btn-outline-primary">
                                                    <i class="fas fa-sync-alt"></i> Regenerate Codes
                                                </button>
                                            </form>
                                        </div>

                                        <?php if ($backupCodesCount < 3): ?>
                                            <div class="alert alert-warning mt-3 mb-0">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                <strong>Running low on backup codes!</strong>
                                                Consider regenerating new codes to ensure you can always access your account.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Disable MFA Section -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3 text-danger">
                                <i class="fas fa-exclamation-triangle"></i> Danger Zone
                            </h5>

                            <div class="card border-danger">
                                <div class="card-body">
                                    <h6 class="card-title">Disable Two-Factor Authentication</h6>
                                    <p class="text-muted small">
                                        This will remove the additional security layer from your account. You will only need
                                        your password to sign in.
                                    </p>
                                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#disableMFAModal">
                                        <i class="fas fa-times-circle"></i> Disable MFA
                                    </button>
                                </div>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- MFA Disabled - Setup Instructions -->

                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">How Two-Factor Authentication Works</h5>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="text-center">
                                        <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-3"
                                             style="width: 64px; height: 64px;">
                                            <span class="fs-3 fw-bold">1</span>
                                        </div>
                                        <h6>Install an Authenticator App</h6>
                                        <p class="text-muted small">
                                            Download Google Authenticator, Microsoft Authenticator, or Authy on your mobile device.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <div class="text-center">
                                        <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-3"
                                             style="width: 64px; height: 64px;">
                                            <span class="fs-3 fw-bold">2</span>
                                        </div>
                                        <h6>Scan QR Code</h6>
                                        <p class="text-muted small">
                                            Use your authenticator app to scan the QR code we'll provide during setup.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <div class="text-center">
                                        <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-3"
                                             style="width: 64px; height: 64px;">
                                            <span class="fs-3 fw-bold">3</span>
                                        </div>
                                        <h6>Enter Verification Code</h6>
                                        <p class="text-muted small">
                                            Each time you sign in, you'll enter the 6-digit code from your authenticator app.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">Benefits of Two-Factor Authentication</h5>

                            <ul class="list-unstyled">
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <strong>Enhanced Security:</strong> Protects your account even if your password is compromised
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <strong>Industry Standard:</strong> Used by major services like Google, Microsoft, and Facebook
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <strong>Easy to Use:</strong> Takes just a few seconds to enter a code when signing in
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <strong>Backup Codes:</strong> Receive backup codes in case you lose access to your authenticator
                                </li>
                            </ul>
                        </div>

                        <div class="text-center">
                            <a href="/auth/mfa-setup" class="btn btn-primary btn-lg">
                                <i class="fas fa-shield-alt"></i> Enable Two-Factor Authentication
                            </a>
                        </div>

                    <?php endif; ?>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="/settings" class="btn btn-outline-secondary">
                            ← Back to Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Disable MFA Modal -->
<?php if ($mfaEnabled): ?>
<div class="modal fade" id="disableMFAModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Disable Two-Factor Authentication</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="disable_mfa">

                    <div class="alert alert-danger">
                        <strong>⚠️ Warning!</strong>
                        <p class="mb-0">
                            Disabling two-factor authentication will make your account less secure.
                            You will only need your password to sign in.
                        </p>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Current Password *</label>
                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            required
                            autocomplete="current-password"
                        >
                        <div class="form-text">Enter your password to confirm this action</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        Disable MFA
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// 📋 Copy backup codes to clipboard
function copyBackupCodes() {
    const codes = [
        <?php
        if (isset($_SESSION['new_backup_codes'])) {
            echo "'" . implode("', '", $_SESSION['new_backup_codes']) . "'";
        }
        ?>
    ];

    const text = codes.map((code, index) => `${index + 1}. ${code}`).join('\n');

    navigator.clipboard.writeText(text).then(() => {
        alert('Backup codes copied to clipboard!');
    }).catch(err => {
        console.error('Failed to copy:', err);
        alert('Failed to copy codes to clipboard');
    });
}

// 🖨️ Print backup codes
function printBackupCodes() {
    window.print();
}
</script>

<style>
@media print {
    /* Hide everything except backup codes when printing */
    body * {
        visibility: hidden;
    }

    .alert-info, .alert-info * {
        visibility: visible;
    }

    .alert-info {
        position: absolute;
        left: 0;
        top: 0;
    }

    .btn {
        display: none !important;
    }
}
</style>

<?php
// 🎨 Include footer
include SIGNULA_ROOT . '/_includes/layout/footer.php';
?>
