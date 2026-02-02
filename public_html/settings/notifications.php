<?php
/**
 * ============================================================================
 * 🔔 SIGNula - Notification Preferences
 * ============================================================================
 *
 * Purpose: Manage notification preferences and alerts
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

$pageTitle = 'Notification Preferences';
$user = getCurrentUser();
$userID = $_SESSION['userID'];

$message = null;
$messageType = null;

// 📝 Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_notifications') {
        try {
            // 📧 Email Notifications
            $emailLoginAlerts = isset($_POST['emailLoginAlerts']) ? 1 : 0;
            $emailSecurityAlerts = isset($_POST['emailSecurityAlerts']) ? 1 : 0;
            $emailPasswordChanges = isset($_POST['emailPasswordChanges']) ? 1 : 0;
            $emailAccountChanges = isset($_POST['emailAccountChanges']) ? 1 : 0;
            $emailNewDeviceLogin = isset($_POST['emailNewDeviceLogin']) ? 1 : 0;
            $emailMFAChanges = isset($_POST['emailMFAChanges']) ? 1 : 0;
            $emailOAuthChanges = isset($_POST['emailOAuthChanges']) ? 1 : 0;
            $emailWeeklySummary = isset($_POST['emailWeeklySummary']) ? 1 : 0;
            $emailProductUpdates = isset($_POST['emailProductUpdates']) ? 1 : 0;

            // 🔔 Push Notifications (for PWA/future mobile apps)
            $pushLoginAlerts = isset($_POST['pushLoginAlerts']) ? 1 : 0;
            $pushSecurityAlerts = isset($_POST['pushSecurityAlerts']) ? 1 : 0;

            // 📱 SMS Notifications (if enabled)
            $smsSecurityAlerts = isset($_POST['smsSecurityAlerts']) ? 1 : 0;
            $smsLoginAlerts = isset($_POST['smsLoginAlerts']) ? 1 : 0;

            // 💾 Get existing preferences or create new
            $existingPrefs = Database::fetchOne(
                "SELECT * FROM tblUserPreferences WHERE userID = ?",
                [$userID],
                'i'
            );

            if ($existingPrefs) {
                // 🔄 Update existing preferences
                $query = "
                    UPDATE tblUserPreferences
                    SET emailLoginAlerts = ?,
                        emailSecurityAlerts = ?,
                        emailPasswordChanges = ?,
                        emailAccountChanges = ?,
                        emailNewDeviceLogin = ?,
                        emailMFAChanges = ?,
                        emailOAuthChanges = ?,
                        emailWeeklySummary = ?,
                        emailProductUpdates = ?,
                        pushLoginAlerts = ?,
                        pushSecurityAlerts = ?,
                        smsSecurityAlerts = ?,
                        smsLoginAlerts = ?,
                        updatedAt = NOW()
                    WHERE userID = ?
                ";

                Database::query(
                    $query,
                    [
                        $emailLoginAlerts,
                        $emailSecurityAlerts,
                        $emailPasswordChanges,
                        $emailAccountChanges,
                        $emailNewDeviceLogin,
                        $emailMFAChanges,
                        $emailOAuthChanges,
                        $emailWeeklySummary,
                        $emailProductUpdates,
                        $pushLoginAlerts,
                        $pushSecurityAlerts,
                        $smsSecurityAlerts,
                        $smsLoginAlerts,
                        $userID
                    ],
                    'iiiiiiiiiiiiii'
                );
            } else {
                // 📝 Create new preferences
                $query = "
                    INSERT INTO tblUserPreferences
                    (userID, emailLoginAlerts, emailSecurityAlerts, emailPasswordChanges,
                     emailAccountChanges, emailNewDeviceLogin, emailMFAChanges, emailOAuthChanges,
                     emailWeeklySummary, emailProductUpdates, pushLoginAlerts, pushSecurityAlerts,
                     smsSecurityAlerts, smsLoginAlerts, createdAt)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ";

                Database::query(
                    $query,
                    [
                        $userID,
                        $emailLoginAlerts,
                        $emailSecurityAlerts,
                        $emailPasswordChanges,
                        $emailAccountChanges,
                        $emailNewDeviceLogin,
                        $emailMFAChanges,
                        $emailOAuthChanges,
                        $emailWeeklySummary,
                        $emailProductUpdates,
                        $pushLoginAlerts,
                        $pushSecurityAlerts,
                        $smsSecurityAlerts,
                        $smsLoginAlerts
                    ],
                    'iiiiiiiiiiiiii'
                );
            }

            // 📝 Log activity
            ActivityLogger::log(
                userID: $userID,
                activityType: 'notification_preferences_updated',
                activityResult: 'success',
                activityDetails: 'Notification preferences updated'
            );

            $message = 'Notification preferences updated successfully!';
            $messageType = 'success';

        } catch (Exception $e) {
            error_log("Notification preferences update error: " . $e->getMessage());
            $message = 'An error occurred while updating your preferences.';
            $messageType = 'danger';
        }
    }
}

// 🔍 Get current notification preferences with defaults
$preferences = Database::fetchOne(
    "SELECT * FROM tblUserPreferences WHERE userID = ?",
    [$userID],
    'i'
) ?? [
    'emailLoginAlerts' => 0,
    'emailSecurityAlerts' => 1,  // Default ON
    'emailPasswordChanges' => 1,  // Default ON
    'emailAccountChanges' => 1,   // Default ON
    'emailNewDeviceLogin' => 1,   // Default ON
    'emailMFAChanges' => 1,        // Default ON
    'emailOAuthChanges' => 1,      // Default ON
    'emailWeeklySummary' => 0,
    'emailProductUpdates' => 0,
    'pushLoginAlerts' => 0,
    'pushSecurityAlerts' => 1,     // Default ON
    'smsSecurityAlerts' => 0,
    'smsLoginAlerts' => 0
];

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
                    <h1 class="h3 mb-4">🔔 Notification Preferences</h1>

                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted mb-4">
                        Choose how and when you want to receive notifications about your account activity.
                    </p>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_notifications">

                        <!-- Email Notifications - Security -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">
                                <i class="fas fa-envelope"></i> Email Notifications - Security
                            </h5>

                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Important:</strong> We strongly recommend keeping security notifications enabled
                                to protect your account.
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="emailSecurityAlerts"
                                       name="emailSecurityAlerts" value="1"
                                       <?php echo $preferences['emailSecurityAlerts'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="emailSecurityAlerts">
                                    <strong>Security alerts</strong>
                                    <small class="text-muted d-block">
                                        Suspicious activity, failed login attempts, and security threats
                                    </small>
                                </label>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="emailPasswordChanges"
                                       name="emailPasswordChanges" value="1"
                                       <?php echo $preferences['emailPasswordChanges'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="emailPasswordChanges">
                                    <strong>Password changes</strong>
                                    <small class="text-muted d-block">
                                        When your password is changed or reset
                                    </small>
                                </label>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="emailNewDeviceLogin"
                                       name="emailNewDeviceLogin" value="1"
                                       <?php echo $preferences['emailNewDeviceLogin'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="emailNewDeviceLogin">
                                    <strong>New device login</strong>
                                    <small class="text-muted d-block">
                                        When you sign in from a new device or location
                                    </small>
                                </label>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="emailMFAChanges"
                                       name="emailMFAChanges" value="1"
                                       <?php echo $preferences['emailMFAChanges'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="emailMFAChanges">
                                    <strong>Two-factor authentication changes</strong>
                                    <small class="text-muted d-block">
                                        When MFA is enabled, disabled, or backup codes are regenerated
                                    </small>
                                </label>
                            </div>
                        </div>

                        <!-- Email Notifications - Account Activity -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">
                                <i class="fas fa-envelope"></i> Email Notifications - Account Activity
                            </h5>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="emailLoginAlerts"
                                       name="emailLoginAlerts" value="1"
                                       <?php echo $preferences['emailLoginAlerts'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="emailLoginAlerts">
                                    <strong>Login notifications</strong>
                                    <small class="text-muted d-block">
                                        Get notified every time you sign in (can be frequent)
                                    </small>
                                </label>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="emailAccountChanges"
                                       name="emailAccountChanges" value="1"
                                       <?php echo $preferences['emailAccountChanges'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="emailAccountChanges">
                                    <strong>Account changes</strong>
                                    <small class="text-muted d-block">
                                        Profile updates, email changes, and other account modifications
                                    </small>
                                </label>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="emailOAuthChanges"
                                       name="emailOAuthChanges" value="1"
                                       <?php echo $preferences['emailOAuthChanges'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="emailOAuthChanges">
                                    <strong>Connected account changes</strong>
                                    <small class="text-muted d-block">
                                        When you link or unlink third-party accounts (Google, Microsoft, etc.)
                                    </small>
                                </label>
                            </div>
                        </div>

                        <!-- Email Notifications - Updates & Marketing -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">
                                <i class="fas fa-envelope"></i> Email Notifications - Updates & Information
                            </h5>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="emailWeeklySummary"
                                       name="emailWeeklySummary" value="1"
                                       <?php echo $preferences['emailWeeklySummary'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="emailWeeklySummary">
                                    <strong>Weekly activity summary</strong>
                                    <small class="text-muted d-block">
                                        Get a weekly summary of your account activity
                                    </small>
                                </label>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="emailProductUpdates"
                                       name="emailProductUpdates" value="1"
                                       <?php echo $preferences['emailProductUpdates'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="emailProductUpdates">
                                    <strong>Product updates and news</strong>
                                    <small class="text-muted d-block">
                                        Learn about new features, improvements, and tips
                                    </small>
                                </label>
                            </div>
                        </div>

                        <!-- Push Notifications -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">
                                <i class="fas fa-mobile-alt"></i> Push Notifications
                            </h5>

                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Browser notifications:</strong> Allow notifications in your browser settings
                                to receive real-time alerts.
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="pushSecurityAlerts"
                                       name="pushSecurityAlerts" value="1"
                                       <?php echo $preferences['pushSecurityAlerts'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="pushSecurityAlerts">
                                    <strong>Security alerts</strong>
                                    <small class="text-muted d-block">
                                        Instant notifications for security events
                                    </small>
                                </label>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="pushLoginAlerts"
                                       name="pushLoginAlerts" value="1"
                                       <?php echo $preferences['pushLoginAlerts'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="pushLoginAlerts">
                                    <strong>Login alerts</strong>
                                    <small class="text-muted d-block">
                                        Get notified when someone signs into your account
                                    </small>
                                </label>
                            </div>
                        </div>

                        <!-- SMS Notifications -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">
                                <i class="fas fa-sms"></i> SMS Notifications
                            </h5>

                            <div class="alert alert-secondary">
                                <i class="fas fa-info-circle"></i>
                                <strong>SMS notifications:</strong> Add a phone number in your profile to enable SMS alerts.
                                Standard messaging rates may apply.
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="smsSecurityAlerts"
                                       name="smsSecurityAlerts" value="1"
                                       <?php echo $preferences['smsSecurityAlerts'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="smsSecurityAlerts">
                                    <strong>Security alerts via SMS</strong>
                                    <small class="text-muted d-block">
                                        Critical security notifications sent to your phone
                                    </small>
                                </label>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="smsLoginAlerts"
                                       name="smsLoginAlerts" value="1"
                                       <?php echo $preferences['smsLoginAlerts'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="smsLoginAlerts">
                                    <strong>Login alerts via SMS</strong>
                                    <small class="text-muted d-block">
                                        Text message when you sign in from a new device
                                    </small>
                                </label>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">Quick Actions</h5>

                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="enableAll()">
                                    <i class="fas fa-check-double"></i> Enable All
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="disableAll()">
                                    <i class="fas fa-times"></i> Disable All
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="enableSecurityOnly()">
                                    <i class="fas fa-shield-alt"></i> Security Only
                                </button>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="/settings" class="btn btn-outline-secondary">
                                ← Back to Settings
                            </a>
                            <button type="submit" class="btn btn-primary">
                                💾 Save Preferences
                            </button>
                        </div>
                    </form>

                    <!-- Info Box -->
                    <div class="alert alert-info mt-4">
                        <h6 class="alert-heading">
                            <i class="fas fa-info-circle"></i> About Notifications
                        </h6>
                        <ul class="mb-0 small">
                            <li>You can change these preferences at any time</li>
                            <li>Critical security alerts are always sent regardless of settings</li>
                            <li>Unsubscribe links are included in all marketing emails</li>
                            <li>Push notifications require browser permission</li>
                            <li>SMS notifications may incur standard messaging rates</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// 🔧 Quick action functions
function enableAll() {
    document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
        checkbox.checked = true;
    });
}

function disableAll() {
    document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
        checkbox.checked = false;
    });
}

function enableSecurityOnly() {
    // Disable all first
    disableAll();

    // Enable only security-related notifications
    const securityCheckboxes = [
        'emailSecurityAlerts',
        'emailPasswordChanges',
        'emailNewDeviceLogin',
        'emailMFAChanges',
        'emailAccountChanges',
        'emailOAuthChanges',
        'pushSecurityAlerts',
        'smsSecurityAlerts'
    ];

    securityCheckboxes.forEach(id => {
        const checkbox = document.getElementById(id);
        if (checkbox) {
            checkbox.checked = true;
        }
    });
}
</script>

<?php
// 🎨 Include footer
include SIGNULA_ROOT . '/_includes/layout/footer.php';
?>
