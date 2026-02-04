<?php
/**
 * ============================================================================
 * 🔐 SIGNula - PassKey Management
 * ============================================================================
 *
 * Purpose: Manage user's registered PassKeys
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

// 📚 Load required classes
require_once SIGNULA_ROOT . '/private_html/auth/WebAuthnHandler.php';

$pageTitle = 'Manage PassKeys';
$user = getCurrentUser();
$userID = $_SESSION['userID'];

// 🔍 Get user's PassKeys
$handler = new WebAuthnHandler();
$passkeys = $handler->getUserCredentials($userID);

// 📝 Handle actions
$message = null;
$messageType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'rename' && isset($_POST['credentialID']) && isset($_POST['newName'])) {
        $credentialID = (int)$_POST['credentialID'];
        $newName = trim($_POST['newName']);

        if ($handler->renameCredential($credentialID, $newName)) {
            $message = 'PassKey renamed successfully';
            $messageType = 'success';
            // Refresh data
            $passkeys = $handler->getUserCredentials($userID);
        } else {
            $message = 'Failed to rename PassKey';
            $messageType = 'danger';
        }
    } elseif ($action === 'revoke' && isset($_POST['credentialID'])) {
        $credentialID = (int)$_POST['credentialID'];

        if ($handler->revokeCredential($credentialID, 'user_request')) {
            $message = 'PassKey removed successfully';
            $messageType = 'success';
            // Refresh data
            $passkeys = $handler->getUserCredentials($userID);
        } else {
            $message = 'Failed to remove PassKey';
            $messageType = 'danger';
        }
    }
}

// 🎨 Include header
include SIGNULA_ROOT . '/private_html/layout/header.php';
?>

<div class="container mt-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 mb-4">
            <?php include SIGNULA_ROOT . '/private_html/layout/settings-sidebar.php'; ?>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <div class="card shadow">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h3 mb-1">🔐 PassKeys</h1>
                            <p class="text-muted mb-0">
                                Manage your biometric authentication methods
                            </p>
                        </div>
                        <a href="/auth/passkey-register" class="btn btn-primary">
                            ➕ Add PassKey
                        </a>
                    </div>

                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($passkeys)): ?>
                        <!-- No PassKeys -->
                        <div class="text-center py-5">
                            <div class="display-1 mb-3">🔐</div>
                            <h3 class="h5 mb-3">No PassKeys Registered</h3>
                            <p class="text-muted mb-4">
                                PassKeys allow you to log in securely using your device's biometric authentication,
                                such as fingerprint, face recognition, or PIN.
                            </p>

                            <a href="/auth/passkey-register" class="btn btn-primary btn-lg">
                                ➕ Add Your First PassKey
                            </a>

                            <div class="alert alert-info mt-4 text-start">
                                <strong>✨ Benefits of PassKeys:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>✅ More secure than passwords</li>
                                    <li>🚀 Faster login experience</li>
                                    <li>🔒 Protected against phishing</li>
                                    <li>📱 Works across your devices</li>
                                    <li>🌐 Industry-standard FIDO2 technology</li>
                                </ul>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- PassKeys List -->
                        <div class="alert alert-success mb-4">
                            <strong>✅ PassKeys Enabled</strong><br>
                            You can now log in to your account using biometric authentication. No password required!
                        </div>

                        <div class="list-group">
                            <?php foreach ($passkeys as $passkey): ?>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-center">
                                                <div class="me-3 fs-3">
                                                    <?php
                                                    // Icon based on authenticator type
                                                    $icon = match($passkey['authenticatorType']) {
                                                        'platform' => '📱',
                                                        'cross-platform' => '🔑',
                                                        default => '🔐'
                                                    };
                                                    echo $icon;
                                                    ?>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1">
                                                        <?php echo htmlspecialchars($passkey['deviceName'] ?? 'Unknown Device'); ?>
                                                    </h6>
                                                    <small class="text-muted">
                                                        Added <?php echo date('M j, Y', strtotime($passkey['createdAt'])); ?>
                                                        <?php if ($passkey['lastUsedAt']): ?>
                                                            • Last used <?php echo date('M j, Y', strtotime($passkey['lastUsedAt'])); ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 text-md-center mt-2 mt-md-0">
                                            <span class="badge bg-<?php echo $passkey['authenticatorType'] === 'platform' ? 'primary' : 'secondary'; ?>">
                                                <?php echo ucfirst($passkey['authenticatorType']); ?>
                                            </span>
                                            <br>
                                            <small class="text-muted">
                                                Used <?php echo number_format($passkey['usageCount']); ?> times
                                            </small>
                                        </div>
                                        <div class="col-md-3 text-md-end mt-2 mt-md-0">
                                            <button
                                                class="btn btn-sm btn-outline-primary me-1"
                                                data-bs-toggle="modal"
                                                data-bs-target="#renameModal<?php echo $passkey['credentialID']; ?>"
                                            >
                                                ✏️ Rename
                                            </button>
                                            <button
                                                class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteModal<?php echo $passkey['credentialID']; ?>"
                                            >
                                                🗑️ Remove
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Rename Modal -->
                                <div class="modal fade" id="renameModal<?php echo $passkey['credentialID']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Rename PassKey</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="rename">
                                                    <input type="hidden" name="credentialID" value="<?php echo $passkey['credentialID']; ?>">

                                                    <div class="mb-3">
                                                        <label for="newName<?php echo $passkey['credentialID']; ?>" class="form-label">
                                                            New Name
                                                        </label>
                                                        <input
                                                            type="text"
                                                            class="form-control"
                                                            id="newName<?php echo $passkey['credentialID']; ?>"
                                                            name="newName"
                                                            value="<?php echo htmlspecialchars($passkey['deviceName'] ?? ''); ?>"
                                                            required
                                                        >
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                        Cancel
                                                    </button>
                                                    <button type="submit" class="btn btn-primary">
                                                        Save Changes
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- Delete Modal -->
                                <div class="modal fade" id="deleteModal<?php echo $passkey['credentialID']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Remove PassKey</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="revoke">
                                                    <input type="hidden" name="credentialID" value="<?php echo $passkey['credentialID']; ?>">

                                                    <div class="alert alert-warning">
                                                        <strong>⚠️ Are you sure?</strong><br>
                                                        This will remove <strong><?php echo htmlspecialchars($passkey['deviceName'] ?? 'this PassKey'); ?></strong>
                                                        from your account. You won't be able to use it to log in anymore.
                                                    </div>

                                                    <?php if (count($passkeys) === 1): ?>
                                                        <div class="alert alert-danger">
                                                            <strong>⚠️ Warning:</strong> This is your only PassKey.
                                                            Make sure you can still access your account using your password or email login.
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                        Cancel
                                                    </button>
                                                    <button type="submit" class="btn btn-danger">
                                                        Remove PassKey
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="alert alert-info mt-4">
                            <strong>ℹ️ About PassKeys:</strong>
                            <ul class="mb-0 mt-2">
                                <li>PassKeys are stored securely on your device and never shared with our servers</li>
                                <li>Each PassKey is unique to your device and cannot be copied or stolen</li>
                                <li>You can have multiple PassKeys across different devices</li>
                                <li>PassKeys use industry-standard FIDO2/WebAuthn technology</li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// 🎨 Include footer
include SIGNULA_ROOT . '/private_html/layout/footer.php';
?>
