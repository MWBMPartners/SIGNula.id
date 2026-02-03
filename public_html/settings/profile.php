<?php
/**
 * ============================================================================
 * 👤 SIGNula - Profile Management
 * ============================================================================
 *
 * Purpose: Manage user profile information
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

$pageTitle = 'Profile Settings';
$user = getCurrentUser();
$userID = $_SESSION['userID'];

$message = null;
$messageType = null;

// 📝 Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        try {
            $displayName = trim($_POST['displayName'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $timezone = $_POST['timezone'] ?? '';

            // ✅ Validation
            $errors = [];

            if (empty($displayName)) {
                $errors[] = 'Display name is required';
            }

            if (!empty($username)) {
                // Check if username is taken by another user
                $existing = Database::fetchOne(
                    "SELECT userID FROM tblUsers WHERE username = ? AND userID != ?",
                    [$username, $userID],
                    'si'
                );

                if ($existing) {
                    $errors[] = 'Username is already taken';
                }
            }

            if (empty($errors)) {
                // 📝 Update profile
                $query = "
                    UPDATE tblUsers
                    SET displayName = ?,
                        username = ?,
                        timezone = ?,
                        updatedAt = NOW()
                    WHERE userID = ?
                ";

                $result = Database::query($query, [
                    $displayName,
                    $username ?: null,
                    $timezone ?: null,
                    $userID
                ], 'sssi');

                if ($result) {
                    // 📝 Log activity
                    ActivityLogger::log(
                        userID: $userID,
                        activityType: 'profile_update',
                        activityResult: 'success',
                        activityDetails: 'Profile information updated'
                    );

                    $message = 'Profile updated successfully!';
                    $messageType = 'success';

                    // Refresh user data
                    $user = getCurrentUser(true);
                } else {
                    $errors[] = 'Failed to update profile';
                }
            }

            if (!empty($errors)) {
                $message = implode('<br>', $errors);
                $messageType = 'danger';
            }

        } catch (Exception $e) {
            error_log("Profile update error: " . $e->getMessage());
            $message = 'An error occurred while updating your profile';
            $messageType = 'danger';
        }
    } elseif ($action === 'change_email') {
        try {
            $newEmail = trim($_POST['newEmail'] ?? '');
            $password = $_POST['password'] ?? '';

            $errors = [];

            // ✅ Validate email
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email address';
            }

            // ✅ Verify password
            if (empty($password)) {
                $errors[] = 'Password is required to change email';
            } elseif (!password_verify($password, $user['passwordHash'])) {
                $errors[] = 'Incorrect password';
            }

            // Check if email is taken
            if (!empty($newEmail)) {
                $existing = Database::fetchOne(
                    "SELECT userID FROM tblUsers WHERE email = ? AND userID != ?",
                    [$newEmail, $userID],
                    'si'
                );

                if ($existing) {
                    $errors[] = 'Email address is already in use';
                }
            }

            if (empty($errors)) {
                // 📝 Update email
                $query = "UPDATE tblUsers SET email = ?, emailVerified = 0, updatedAt = NOW() WHERE userID = ?";
                $result = Database::query($query, [$newEmail, $userID], 'si');

                if ($result) {
                    // 📧 Send verification email
                    // TODO: Implement email verification

                    // 📝 Log activity
                    ActivityLogger::log(
                        userID: $userID,
                        activityType: 'email_change',
                        activityResult: 'success',
                        activityDetails: 'Email address changed to ' . $newEmail
                    );

                    $message = 'Email updated successfully! Please check your inbox to verify your new email address.';
                    $messageType = 'success';

                    // Refresh user data
                    $user = getCurrentUser(true);
                } else {
                    $errors[] = 'Failed to update email';
                }
            }

            if (!empty($errors)) {
                $message = implode('<br>', $errors);
                $messageType = 'danger';
            }

        } catch (Exception $e) {
            error_log("Email change error: " . $e->getMessage());
            $message = 'An error occurred while updating your email';
            $messageType = 'danger';
        }
    }
}

// Get timezone list
$timezones = DateTimeZone::listIdentifiers();

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
                    <h1 class="h3 mb-4">👤 Profile Settings</h1>

                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Profile Information -->
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="mb-4">
                            <h5 class="border-bottom pb-2">Basic Information</h5>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="displayName" class="form-label">Display Name *</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="displayName"
                                        name="displayName"
                                        value="<?php echo htmlspecialchars($user['displayName'] ?? ''); ?>"
                                        required
                                    >
                                    <div class="form-text">This name will be displayed across the platform</div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="username"
                                        name="username"
                                        value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>"
                                        pattern="[a-zA-Z0-9_-]{3,20}"
                                    >
                                    <div class="form-text">3-20 characters, letters, numbers, _ and - only</div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="timezone" class="form-label">Timezone</label>
                                <select class="form-select" id="timezone" name="timezone">
                                    <option value="">-- Select Timezone --</option>
                                    <?php foreach ($timezones as $tz): ?>
                                        <option
                                            value="<?php echo htmlspecialchars($tz); ?>"
                                            <?php echo ($user['timezone'] ?? '') === $tz ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars($tz); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Used for displaying dates and times in your local timezone</div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h5 class="border-bottom pb-2">Account Information</h5>

                            <div class="mb-3">
                                <label class="form-label">Email Address</label>
                                <div class="input-group">
                                    <input
                                        type="email"
                                        class="form-control"
                                        value="<?php echo htmlspecialchars($user['email']); ?>"
                                        readonly
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#changeEmailModal"
                                    >
                                        Change Email
                                    </button>
                                </div>
                                <?php if (empty($user['emailVerified']) || $user['emailVerified'] == 0): ?>
                                    <div class="form-text text-warning">
                                        ⚠️ Email not verified. <a href="/auth/verify-email">Verify now</a>
                                    </div>
                                <?php else: ?>
                                    <div class="form-text text-success">
                                        ✅ Email verified
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Account Created</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    value="<?php echo date('F j, Y', strtotime($user['createdAt'])); ?>"
                                    readonly
                                >
                            </div>

                            <?php if ($user['lastLogin']): ?>
                            <div class="mb-3">
                                <label class="form-label">Last Login</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    value="<?php echo date('F j, Y g:i A', strtotime($user['lastLogin'])); ?>"
                                    readonly
                                >
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="/settings" class="btn btn-outline-secondary">
                                ← Back to Settings
                            </a>
                            <button type="submit" class="btn btn-primary">
                                💾 Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Change Email Modal -->
<div class="modal fade" id="changeEmailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Change Email Address</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="change_email">

                    <div class="alert alert-warning">
                        <strong>⚠️ Important:</strong>
                        <ul class="mb-0 mt-2">
                            <li>You will need to verify your new email address</li>
                            <li>You'll receive a confirmation email at your new address</li>
                            <li>Your password is required for security</li>
                        </ul>
                    </div>

                    <div class="mb-3">
                        <label for="newEmail" class="form-label">New Email Address *</label>
                        <input
                            type="email"
                            class="form-control"
                            id="newEmail"
                            name="newEmail"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Current Password *</label>
                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            required
                        >
                        <div class="form-text">Confirm your identity with your current password</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        Change Email
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// 🎨 Include footer
include SIGNULA_ROOT . '/private_html/layout/footer.php';
?>
