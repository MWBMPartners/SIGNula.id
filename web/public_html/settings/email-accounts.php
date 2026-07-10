<?php
/**
 * ============================================================================
 * 📧 SIGNula - Connected Email Accounts
 * ============================================================================
 *
 * Purpose: Manage OAuth-connected email accounts for delegate sending
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Settings
 * @version    2.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
//
// 🐛 B-066: this page used to `require_once .../private_html/bootstrap.php`
// (a file that does not exist anywhere in the codebase — there is no
// web/private_html/bootstrap.php) and called isUserLoggedIn()/
// getCurrentUserID(), neither of which is defined either. Every load
// fataled with "Failed opening required .../bootstrap.php" before a single
// byte of output. Only ONE other file in the whole tree
// (web/public_html/api/oauth/disconnect.php) references that same missing
// bootstrap.php — not enough call sites to justify inventing a shim file
// (and disconnect.php is a separate, out-of-scope API endpoint for this
// fix) — so this migrates the prologue to the SAME working pattern every
// sibling settings/*.php page already uses: config.php + requireLogin() +
// getCurrentUser(), both real, defined functions (see web/_config/config.php).
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔒 Require login
requireLogin();

$user = getCurrentUser();
$userID = $_SESSION['userID'];

// 🔍 Load OAuth Token Manager
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'OAuthTokenManager.php';

// 📋 Get user's connected accounts
$connectedAccounts = OAuthTokenManager::getUserTokens($userID, true);

// 🗂️ Organize by provider
$microsoftAccount = null;
$googleAccount = null;

foreach ($connectedAccounts as $account) {
    if ($account['provider'] === 'microsoft_graph') {
        $microsoftAccount = $account;
    } elseif ($account['provider'] === 'gmail_api' || $account['provider'] === 'google_workspace') {
        $googleAccount = $account;
    }
}

// 📝 Get current auth mode
$authMode = getSetting('email.microsoft.auth_mode', 'auto');

$pageTitle = 'Connected Email Accounts';

// 🎨 Include header
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container mt-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 mb-4">
            <?php include dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'settings-sidebar.php'; ?>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <div class="card shadow">
                <div class="card-body p-4">
                    <h1 class="h3 mb-4">📧 Connected Email Accounts</h1>

                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted mb-4">
                        Connect your email accounts to enable sending emails from your mailboxes.
                        This allows you to send emails as yourself using SIGNula's email system.
                    </p>

                    <!-- Microsoft 365 Account Card -->
                    <div class="card mb-4 border">
                        <div class="card-body">
                            <div class="d-flex align-items-start">
                                <!-- Provider Icon -->
                                <div class="me-3">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                                         style="width: 56px; height: 56px; background-color: #00A4EF;">
                                        <i class="fab fa-microsoft text-white fs-3"></i>
                                    </div>
                                </div>

                                <!-- Account Info -->
                                <div class="flex-grow-1">
                                    <h5 class="mb-2">
                                        <i class="fab fa-microsoft text-primary"></i> Microsoft 365
                                    </h5>

                                    <?php if ($microsoftAccount): ?>
                                        <!-- Connected Status -->
                                        <div class="alert alert-success py-2 px-3 mb-3">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-check-circle me-2"></i>
                                                <strong>Connected</strong>
                                            </div>
                                        </div>

                                        <!-- Account Details -->
                                        <div class="mb-2">
                                            <small class="text-muted">Mailbox:</small>
                                            <div class="fw-bold"><?php echo htmlspecialchars($microsoftAccount['mailboxEmail']); ?></div>
                                        </div>

                                        <?php if (!empty($microsoftAccount['mailboxName'])): ?>
                                            <div class="mb-2">
                                                <small class="text-muted">Display Name:</small>
                                                <div><?php echo htmlspecialchars($microsoftAccount['mailboxName']); ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="mb-2">
                                            <small class="text-muted">Connected:</small>
                                            <div><?php echo date('M j, Y g:i A', strtotime($microsoftAccount['createdAt'])); ?></div>
                                        </div>

                                        <?php if ($microsoftAccount['lastUsedAt']): ?>
                                            <div class="mb-2">
                                                <small class="text-muted">Last Used:</small>
                                                <div><?php echo date('M j, Y g:i A', strtotime($microsoftAccount['lastUsedAt'])); ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($microsoftAccount['expiresAt']): ?>
                                            <div class="mb-3">
                                                <small class="text-muted">Token Expires:</small>
                                                <div>
                                                    <?php
                                                    $expiresAt = strtotime($microsoftAccount['expiresAt']);
                                                    $isExpired = $expiresAt < time();
                                                    $expiresClass = $isExpired ? 'text-danger' : 'text-success';
                                                    ?>
                                                    <span class="<?php echo $expiresClass; ?>">
                                                        <?php echo date('M j, Y g:i A', $expiresAt); ?>
                                                        <?php if ($isExpired): ?>
                                                            <i class="fas fa-exclamation-triangle"></i> Expired
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Actions -->
                                        <div class="btn-group" role="group">
                                            <a href="/oauth/authorize?provider=microsoft_graph&purpose=email&mailbox=<?php echo urlencode($microsoftAccount['mailboxEmail']); ?>"
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-sync-alt"></i> Reconnect
                                            </a>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    onclick="disconnectAccount('microsoft_graph', '<?php echo htmlspecialchars($microsoftAccount['mailboxEmail'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-unlink"></i> Disconnect
                                            </button>
                                        </div>

                                    <?php else: ?>
                                        <!-- Not Connected -->
                                        <p class="text-muted mb-3">
                                            Connect your Microsoft 365 or Outlook.com account to send emails from your mailbox.
                                        </p>

                                        <a href="/oauth/authorize?provider=microsoft_graph&purpose=email&mailbox=<?php echo urlencode($user['email']); ?>"
                                           class="btn btn-primary">
                                            <i class="fab fa-microsoft"></i> Connect Microsoft 365
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Google Workspace Account Card -->
                    <div class="card mb-4 border">
                        <div class="card-body">
                            <div class="d-flex align-items-start">
                                <!-- Provider Icon -->
                                <div class="me-3">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                                         style="width: 56px; height: 56px; background-color: #DB4437;">
                                        <i class="fab fa-google text-white fs-3"></i>
                                    </div>
                                </div>

                                <!-- Account Info -->
                                <div class="flex-grow-1">
                                    <h5 class="mb-2">
                                        <i class="fab fa-google text-danger"></i> Google Workspace
                                    </h5>

                                    <?php if ($googleAccount): ?>
                                        <!-- Connected Status -->
                                        <div class="alert alert-success py-2 px-3 mb-3">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-check-circle me-2"></i>
                                                <strong>Connected</strong>
                                            </div>
                                        </div>

                                        <!-- Account Details -->
                                        <div class="mb-2">
                                            <small class="text-muted">Mailbox:</small>
                                            <div class="fw-bold"><?php echo htmlspecialchars($googleAccount['mailboxEmail']); ?></div>
                                        </div>

                                        <?php if (!empty($googleAccount['mailboxName'])): ?>
                                            <div class="mb-2">
                                                <small class="text-muted">Display Name:</small>
                                                <div><?php echo htmlspecialchars($googleAccount['mailboxName']); ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="mb-2">
                                            <small class="text-muted">Connected:</small>
                                            <div><?php echo date('M j, Y g:i A', strtotime($googleAccount['createdAt'])); ?></div>
                                        </div>

                                        <?php if ($googleAccount['lastUsedAt']): ?>
                                            <div class="mb-3">
                                                <small class="text-muted">Last Used:</small>
                                                <div><?php echo date('M j, Y g:i A', strtotime($googleAccount['lastUsedAt'])); ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Actions -->
                                        <div class="btn-group" role="group">
                                            <a href="/oauth/authorize?provider=google_workspace&purpose=email&mailbox=<?php echo urlencode($googleAccount['mailboxEmail']); ?>"
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-sync-alt"></i> Reconnect
                                            </a>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    onclick="disconnectAccount('google_workspace', '<?php echo htmlspecialchars($googleAccount['mailboxEmail'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-unlink"></i> Disconnect
                                            </button>
                                        </div>

                                    <?php else: ?>
                                        <!-- Not Connected -->
                                        <p class="text-muted mb-3">
                                            Connect your Google Workspace or Gmail account to send emails from your mailbox.
                                        </p>

                                        <a href="/oauth/authorize?provider=google_workspace&purpose=email&mailbox=<?php echo urlencode($user['email']); ?>"
                                           class="btn btn-danger">
                                            <i class="fab fa-google"></i> Connect Google Workspace
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Info Box -->
                    <div class="alert alert-info">
                        <h6 class="alert-heading">
                            <i class="fas fa-info-circle"></i> About Connected Email Accounts
                        </h6>
                        <ul class="mb-0 small">
                            <li>Connect your email accounts to send emails from your mailboxes</li>
                            <li>Your tokens are encrypted and stored securely</li>
                            <li>Tokens are automatically refreshed when they expire</li>
                            <li>You can disconnect accounts at any time</li>
                            <li>For Microsoft 365: Requires Mail.Send delegated permission</li>
                            <li>For Google: Uses OAuth 2.0 with gmail.send scope</li>
                        </ul>
                    </div>

                    <!-- Current Auth Mode Display (for debugging) -->
                    <?php if ($authMode !== 'auto'): ?>
                        <div class="alert alert-secondary mt-3">
                            <small>
                                <i class="fas fa-cog"></i> <strong>Current Auth Mode:</strong>
                                <code><?php echo htmlspecialchars($authMode); ?></code>
                            </small>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="/settings" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Disconnect an email account
 * @param {string} provider - Provider name (microsoft_graph, google_workspace)
 * @param {string} mailboxEmail - Mailbox email address
 */
function disconnectAccount(provider, mailboxEmail) {
    if (!confirm('Are you sure you want to disconnect this email account?\n\nYou will no longer be able to send emails from this mailbox until you reconnect.')) {
        return;
    }

    // Show loading state
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Disconnecting...';

    // Send disconnect request
    fetch('/api/oauth/disconnect', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            provider: provider,
            mailboxEmail: mailboxEmail
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Success - reload page to show updated status
            window.location.reload();
        } else {
            // Error
            alert('Failed to disconnect account: ' + (data.error || 'Unknown error'));
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
    })
    .catch(error => {
        console.error('Disconnect error:', error);
        alert('Failed to disconnect account. Please try again.');
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    });
}
</script>

<?php
// 🎨 Include footer
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'footer.php';
?>
