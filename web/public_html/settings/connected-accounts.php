<?php
/**
 * ============================================================================
 * 🔗 SIGNula - Connected Accounts Management
 * ============================================================================
 *
 * Purpose: Manage linked OAuth provider accounts
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

$pageTitle = 'Connected Accounts';
$user = getCurrentUser();
$userID = $_SESSION['userID'];

$message = null;
$messageType = null;

// 📝 Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'unlink_account') {
        try {
            $provider = $_POST['provider'] ?? '';
            $providerUserID = $_POST['providerUserID'] ?? '';

            // ✅ Validation
            if (empty($provider) || empty($providerUserID)) {
                throw new Exception('Invalid unlink request');
            }

            // 🔍 Verify account belongs to user
            $existing = Database::fetchOne(
                "SELECT accountID FROM tblUserLinkedAccounts
                 WHERE userID = ? AND provider = ? AND providerUserID = ?",
                [$userID, $provider, $providerUserID],
                'iss'
            );

            if (!$existing) {
                throw new Exception('Account not found or does not belong to you');
            }

            // 🗑️ Delete linked account
            $query = "DELETE FROM tblUserLinkedAccounts WHERE accountID = ?";
            $result = Database::query($query, [$existing['accountID']], 'i');

            if ($result) {
                // 📝 Log activity
                ActivityLogger::log(
                    userID: $userID,
                    activityType: 'oauth_account_unlinked',
                    activityResult: 'success',
                    activityDetails: "Unlinked {$provider} account"
                );

                $message = 'Account unlinked successfully!';
                $messageType = 'success';
            } else {
                throw new Exception('Failed to unlink account');
            }

        } catch (Exception $e) {
            error_log("Account unlink error: " . $e->getMessage());
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    } elseif ($action === 'set_primary') {
        try {
            $accountID = intval($_POST['accountID'] ?? 0);

            // 🔍 Verify account belongs to user
            $account = Database::fetchOne(
                "SELECT accountID, provider FROM tblUserLinkedAccounts WHERE accountID = ? AND userID = ?",
                [$accountID, $userID],
                'ii'
            );

            if (!$account) {
                throw new Exception('Account not found');
            }

            // 🔄 Update all accounts to not primary
            Database::query(
                "UPDATE tblUserLinkedAccounts SET isPrimary = 0 WHERE userID = ?",
                [$userID],
                'i'
            );

            // ✅ Set selected account as primary
            $result = Database::query(
                "UPDATE tblUserLinkedAccounts SET isPrimary = 1 WHERE accountID = ?",
                [$accountID],
                'i'
            );

            if ($result) {
                // 📝 Log activity
                ActivityLogger::log(
                    userID: $userID,
                    activityType: 'primary_account_changed',
                    activityResult: 'success',
                    activityDetails: "Set {$account['provider']} as primary account"
                );

                $message = 'Primary account updated successfully!';
                $messageType = 'success';
            } else {
                throw new Exception('Failed to update primary account');
            }

        } catch (Exception $e) {
            error_log("Set primary error: " . $e->getMessage());
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// 🔍 Get linked accounts
$linkedAccounts = Database::fetchAll(
    "SELECT * FROM tblUserLinkedAccounts
     WHERE userID = ?
     ORDER BY isPrimary DESC, createdAt DESC",
    [$userID],
    'i'
) ?? [];

// 📋 Define available OAuth providers
$availableProviders = [
    'google' => [
        'name' => 'Google',
        'icon' => 'fab fa-google',
        'color' => '#DB4437',
        'description' => 'Connect your Google or Google Workspace account',
        'enabled' => getSetting('oauth.google.enabled', '0') == '1'
    ],
    'microsoft' => [
        'name' => 'Microsoft',
        'icon' => 'fab fa-microsoft',
        'color' => '#00A4EF',
        'description' => 'Connect your Microsoft or Microsoft 365 account',
        'enabled' => getSetting('oauth.microsoft.enabled', '0') == '1'
    ],
    'apple' => [
        'name' => 'Apple',
        'icon' => 'fab fa-apple',
        'color' => '#000000',
        'description' => 'Connect your Apple ID',
        'enabled' => getSetting('oauth.apple.enabled', '0') == '1'
    ],
    'facebook' => [
        'name' => 'Facebook',
        'icon' => 'fab fa-facebook',
        'color' => '#1877F2',
        'description' => 'Connect your Facebook or Instagram account',
        'enabled' => getSetting('oauth.facebook.enabled', '0') == '1'
    ],
    'linkedin' => [
        'name' => 'LinkedIn',
        'icon' => 'fab fa-linkedin',
        'color' => '#0A66C2',
        'description' => 'Connect your LinkedIn account',
        'enabled' => getSetting('oauth.linkedin.enabled', '0') == '1'
    ],
    'github' => [
        'name' => 'GitHub',
        'icon' => 'fab fa-github',
        'color' => '#181717',
        'description' => 'Connect your GitHub account',
        'enabled' => getSetting('oauth.github.enabled', '0') == '1'
    ]
];

// 🔍 Create lookup for linked accounts
$linkedByProvider = [];
foreach ($linkedAccounts as $account) {
    $linkedByProvider[$account['provider']] = $account;
}

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
            <div class="card shadow">
                <div class="card-body p-4">
                    <h1 class="h3 mb-4">🔗 Connected Accounts</h1>

                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted mb-4">
                        Link your accounts from other services to sign in faster and sync your profile information.
                        You can use any linked account to sign in to SIGNula.
                    </p>

                    <!-- Linked Accounts -->
                    <?php if (!empty($linkedAccounts)): ?>
                        <div class="mb-5">
                            <h5 class="border-bottom pb-2 mb-3">Your Linked Accounts</h5>

                            <?php foreach ($linkedAccounts as $account): ?>
                                <?php
                                $provider = $availableProviders[$account['provider']] ?? null;
                                if (!$provider) continue;
                                ?>
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center">
                                            <!-- Provider Icon -->
                                            <div class="me-3">
                                                <div class="rounded-circle d-flex align-items-center justify-content-center"
                                                     style="width: 48px; height: 48px; background-color: <?php echo $provider['color']; ?>;">
                                                    <i class="<?php echo $provider['icon']; ?> text-white fs-4"></i>
                                                </div>
                                            </div>

                                            <!-- Account Info -->
                                            <div class="flex-grow-1">
                                                <div class="d-flex align-items-center">
                                                    <h6 class="mb-0 me-2"><?php echo htmlspecialchars($provider['name']); ?></h6>
                                                    <?php if ($account['isPrimary']): ?>
                                                        <span class="badge bg-primary">Primary</span>
                                                    <?php endif; ?>
                                                    <?php if ($account['emailVerified']): ?>
                                                        <span class="badge bg-success ms-1">✓ Verified</span>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($account['email']); ?>
                                                </small>
                                                <div class="mt-1">
                                                    <small class="text-muted">
                                                        <i class="fas fa-link"></i>
                                                        Linked <?php echo timeAgo($account['createdAt']); ?>
                                                    </small>
                                                </div>
                                            </div>

                                            <!-- Actions -->
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                                        type="button"
                                                        id="account<?php echo $account['accountID']; ?>Menu"
                                                        data-bs-toggle="dropdown"
                                                        aria-expanded="false">
                                                    Actions
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end"
                                                    aria-labelledby="account<?php echo $account['accountID']; ?>Menu">
                                                    <?php if (!$account['isPrimary']): ?>
                                                        <li>
                                                            <form method="POST" action="" class="d-inline">
                                                                <input type="hidden" name="action" value="set_primary">
                                                                <input type="hidden" name="accountID" value="<?php echo $account['accountID']; ?>">
                                                                <button type="submit" class="dropdown-item">
                                                                    <i class="fas fa-star text-warning"></i> Set as Primary
                                                                </button>
                                                            </form>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                    <?php endif; ?>
                                                    <li>
                                                        <a class="dropdown-item text-danger"
                                                           href="#"
                                                           data-bs-toggle="modal"
                                                           data-bs-target="#unlinkModal<?php echo $account['accountID']; ?>">
                                                            <i class="fas fa-unlink"></i> Unlink Account
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Unlink Confirmation Modal -->
                                <div class="modal fade" id="unlinkModal<?php echo $account['accountID']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Unlink <?php echo htmlspecialchars($provider['name']); ?> Account?</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="unlink_account">
                                                    <input type="hidden" name="provider" value="<?php echo htmlspecialchars($account['provider']); ?>">
                                                    <input type="hidden" name="providerUserID" value="<?php echo htmlspecialchars($account['providerUserID']); ?>">

                                                    <div class="alert alert-warning">
                                                        <strong>⚠️ Are you sure?</strong>
                                                    </div>

                                                    <p class="mb-2">You are about to unlink:</p>
                                                    <ul class="mb-3">
                                                        <li><strong>Account:</strong> <?php echo htmlspecialchars($account['email']); ?></li>
                                                        <li><strong>Provider:</strong> <?php echo htmlspecialchars($provider['name']); ?></li>
                                                    </ul>

                                                    <p class="text-muted small mb-0">
                                                        You will no longer be able to sign in using this <?php echo htmlspecialchars($provider['name']); ?> account.
                                                        You can always link it again later.
                                                    </p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                        Cancel
                                                    </button>
                                                    <button type="submit" class="btn btn-danger">
                                                        Unlink Account
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Available Providers to Link -->
                    <div class="mb-4">
                        <h5 class="border-bottom pb-2 mb-3">Available Accounts to Link</h5>

                        <div class="row">
                            <?php foreach ($availableProviders as $providerKey => $provider): ?>
                                <?php
                                $isLinked = isset($linkedByProvider[$providerKey]);
                                $isEnabled = $provider['enabled'];
                                ?>

                                <div class="col-md-6 mb-3">
                                    <div class="card h-100 <?php echo $isLinked ? 'border-success' : ''; ?>">
                                        <div class="card-body">
                                            <div class="d-flex align-items-start">
                                                <!-- Provider Icon -->
                                                <div class="me-3">
                                                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 40px; height: 40px; background-color: <?php echo $provider['color']; ?>;">
                                                        <i class="<?php echo $provider['icon']; ?> text-white"></i>
                                                    </div>
                                                </div>

                                                <!-- Provider Info -->
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1">
                                                        <?php echo htmlspecialchars($provider['name']); ?>
                                                        <?php if ($isLinked): ?>
                                                            <span class="badge bg-success">✓ Linked</span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <p class="text-muted small mb-2">
                                                        <?php echo htmlspecialchars($provider['description']); ?>
                                                    </p>

                                                    <?php if ($isLinked): ?>
                                                        <div class="alert alert-success py-2 px-3 mb-0">
                                                            <small>
                                                                <i class="fas fa-check-circle"></i>
                                                                Already linked as <?php echo htmlspecialchars($linkedByProvider[$providerKey]['email']); ?>
                                                            </small>
                                                        </div>
                                                    <?php elseif ($isEnabled): ?>
                                                        <a href="/auth/oauth-authorize?provider=<?php echo urlencode($providerKey); ?>"
                                                           class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-link"></i> Link Account
                                                        </a>
                                                    <?php else: ?>
                                                        <div class="alert alert-secondary py-2 px-3 mb-0">
                                                            <small>
                                                                <i class="fas fa-info-circle"></i>
                                                                Not currently available
                                                            </small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Info Box -->
                    <div class="alert alert-info">
                        <h6 class="alert-heading">
                            <i class="fas fa-info-circle"></i> About Connected Accounts
                        </h6>
                        <ul class="mb-0 small">
                            <li>You can use any linked account to sign in to SIGNula</li>
                            <li>Your primary account is used for your profile picture</li>
                            <li>Linking an account does not give SIGNula access to your other services</li>
                            <li>You can unlink accounts at any time</li>
                            <li>Make sure you have at least one way to access your account (password or linked account)</li>
                        </ul>
                    </div>

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

<?php
// 🎨 Include footer
include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'footer.php';
?>
