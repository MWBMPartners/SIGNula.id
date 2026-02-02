<?php
/**
 * ============================================================================
 * ⚙️ SIGNula - Account Settings Page
 * ============================================================================
 *
 * Purpose: User account management - profile, email, password
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
                case 'update_profile':
                    // 📝 Update profile information
                    $firstName = SecurityUtils::sanitizeString($_POST['first_name'] ?? '');
                    $lastName = SecurityUtils::sanitizeString($_POST['last_name'] ?? '');
                    $displayName = SecurityUtils::sanitizeString($_POST['display_name'] ?? '');

                    Database::query(
                        "UPDATE tblUsers SET firstName = ?, lastName = ?, displayName = ? WHERE userID = ?",
                        [$firstName, $lastName, $displayName, $user['userID']],
                        'sssi'
                    );

                    ActivityLogger::log($user['userID'], 'profile_updated', 'account', 'info', 'Profile information updated');
                    $success = 'Profile updated successfully!';

                    // Refresh user data
                    Auth::$currentUser = null;
                    $user = Auth::getCurrentUser();
                    break;

                case 'change_email':
                    // 📧 Change email address
                    $newEmail = SecurityUtils::sanitizeEmail($_POST['new_email'] ?? '');
                    $password = $_POST['current_password'] ?? '';

                    if (!$newEmail) {
                        $error = 'Please enter a valid email address.';
                    } elseif (!SecurityUtils::verifyPassword($password, $user['passwordHash'] ?? '')) {
                        $error = 'Current password is incorrect.';
                    } else {
                        // Check if email already exists
                        $existingEmail = Database::fetchOne(
                            "SELECT userID FROM tblUsers WHERE email = ? AND userID != ?",
                            [$newEmail, $user['userID']],
                            'si'
                        );

                        if ($existingEmail) {
                            $error = 'This email address is already in use.';
                        } else {
                            Database::query(
                                "UPDATE tblUsers SET email = ?, emailVerified = FALSE WHERE userID = ?",
                                [$newEmail, $user['userID']],
                                'si'
                            );

                            // TODO: Send verification email to new address
                            ActivityLogger::log($user['userID'], 'email_changed', 'account', 'info', 'Email address changed', [
                                'old_email' => $user['email'],
                                'new_email' => $newEmail
                            ]);

                            $success = 'Email address updated! Please check your new email for verification.';

                            // Refresh user data
                            Auth::$currentUser = null;
                            $user = Auth::getCurrentUser();
                        }
                    }
                    break;

                case 'change_password':
                    // 🔑 Change password
                    $currentPassword = $_POST['current_password'] ?? '';
                    $newPassword = $_POST['new_password'] ?? '';
                    $confirmPassword = $_POST['confirm_password'] ?? '';

                    if (!SecurityUtils::verifyPassword($currentPassword, $user['passwordHash'] ?? '')) {
                        $error = 'Current password is incorrect.';
                    } elseif ($newPassword !== $confirmPassword) {
                        $error = 'New passwords do not match.';
                    } else {
                        $validation = SecurityUtils::validatePassword($newPassword);
                        if (!$validation['valid']) {
                            $error = implode(' ', $validation['errors']);
                        } else {
                            $passwordHash = SecurityUtils::hashPassword($newPassword);

                            Database::query(
                                "UPDATE tblUsers SET passwordHash = ? WHERE userID = ?",
                                [$passwordHash, $user['userID']],
                                'si'
                            );

                            // Invalidate all other sessions (keep current session)
                            $currentSessionID = $_SESSION['session_id'] ?? null;
                            if ($currentSessionID) {
                                Database::query(
                                    "UPDATE tblUserSessions SET isActive = FALSE WHERE userID = ? AND sessionID != ?",
                                    [$user['userID'], $currentSessionID],
                                    'ii'
                                );
                            }

                            ActivityLogger::log($user['userID'], 'password_changed', 'security', 'info', 'Password changed');
                            $success = 'Password changed successfully! All other sessions have been logged out.';
                        }
                    }
                    break;

                case 'unlink_oauth':
                    // 🔗 Unlink OAuth provider account
                    $provider = $_POST['provider'] ?? '';

                    if (empty($provider)) {
                        $error = 'Provider not specified.';
                    } else {
                        // Delete linked account
                        $deleted = Database::query(
                            "DELETE FROM tblUserLinkedAccounts WHERE userID = ? AND provider = ?",
                            [$user['userID'], $provider],
                            'is'
                        );

                        if ($deleted) {
                            ActivityLogger::log($user['userID'], 'oauth_unlinked', 'account', 'info',
                                "Unlinked {$provider} account", ['provider' => $provider]);

                            $success = ucfirst($provider) . ' account unlinked successfully!';
                        } else {
                            $error = 'Failed to unlink account.';
                        }
                    }
                    break;
            }

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            $error = 'An error occurred. Please try again.';
        }
    }
}

// 🎫 Generate CSRF token
$csrfToken = SecurityUtils::generateCSRFToken();

// 🔗 Get linked OAuth accounts
$linkedAccounts = [];
try {
    $linkedAccounts = Database::fetchAll(
        "SELECT provider, email, displayName, profilePicture, createdAt, updatedAt
         FROM tblUserLinkedAccounts
         WHERE userID = ?
         ORDER BY createdAt DESC",
        [$user['userID']],
        'i'
    );
} catch (Exception $e) {
    ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
}

// 📄 Page metadata
$pageTitle = 'Account Settings - SIGNula';
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
                        <li><a class="dropdown-item active" href="/account"><i class="fas fa-cog"></i> Account Settings</a></li>
                        <li><a class="dropdown-item" href="/security"><i class="fas fa-lock"></i> Security</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- 📱 Page Content -->
<div class="container" style="padding: 2rem 0; max-width: 900px;">
    <!-- 📍 Breadcrumb -->
    <nav aria-label="breadcrumb" style="margin-bottom: 1.5rem;">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/dashboard">Dashboard</a></li>
            <li class="breadcrumb-item active">Account Settings</li>
        </ol>
    </nav>

    <!-- 🎯 Page Header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 style="margin: 0; font-size: 2rem; font-weight: 700;">
            <i class="fas fa-cog"></i> Account Settings
        </h1>
    </div>

    <!-- 🚨 Alert Messages -->
    <div id="alert-container"></div>

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

    <!-- 👤 Profile Information -->
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-user"></i> Profile Information</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="/account" id="profileForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="update_profile">

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="first_name" class="form-label">First Name</label>
                            <input
                                type="text"
                                class="form-control"
                                id="first_name"
                                name="first_name"
                                value="<?php echo htmlspecialchars($user['firstName'] ?? ''); ?>"
                            >
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input
                                type="text"
                                class="form-control"
                                id="last_name"
                                name="last_name"
                                value="<?php echo htmlspecialchars($user['lastName'] ?? ''); ?>"
                            >
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="display_name" class="form-label">Display Name</label>
                    <input
                        type="text"
                        class="form-control"
                        id="display_name"
                        name="display_name"
                        value="<?php echo htmlspecialchars($user['displayName'] ?? ''); ?>"
                    >
                    <small class="form-text">This is how your name will be displayed across the platform</small>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Username</label>
                    <div style="padding: 0.75rem 1rem; background: var(--bg-light); border: 1px solid var(--border-color); border-radius: var(--radius-md); color: var(--text-secondary);">
                        <i class="fas fa-user"></i> @<?php echo htmlspecialchars($user['username']); ?>
                        <small class="d-block mt-1" style="font-size: 0.875rem;">Username cannot be changed</small>
                    </div>
                </div>

                <hr style="margin: 1.5rem 0;">

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </form>
        </div>
    </div>

    <!-- 📧 Email Address -->
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-envelope"></i> Email Address</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="/account" id="emailForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="change_email">

                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div>
                        <strong>Current Email:</strong>
                        <p style="margin: 0.25rem 0 0; color: var(--text-secondary);">
                            <?php echo htmlspecialchars($user['email']); ?>
                            <?php if ($user['emailVerified']): ?>
                                <span class="badge" style="background: var(--success-color); color: white; margin-left: 0.5rem;">
                                    <i class="fas fa-check"></i> Verified
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background: var(--warning-color); color: white; margin-left: 0.5rem;">
                                    <i class="fas fa-exclamation-triangle"></i> Not Verified
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="new_email" class="form-label">New Email Address</label>
                    <input
                        type="email"
                        class="form-control"
                        id="new_email"
                        name="new_email"
                        placeholder="Enter new email address"
                    >
                    <div class="invalid-feedback"></div>
                </div>

                <div class="form-group">
                    <label for="current_password_email" class="form-label form-label-required">Current Password</label>
                    <input
                        type="password"
                        class="form-control"
                        id="current_password_email"
                        name="current_password"
                        placeholder="Enter your current password"
                        autocomplete="current-password"
                    >
                    <div class="invalid-feedback"></div>
                    <small class="form-text">Required to verify your identity</small>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-envelope"></i> Update Email
                </button>
            </form>
        </div>
    </div>

    <!-- 🔑 Change Password -->
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-lock"></i> Change Password</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="/account" id="passwordForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label for="current_password_pwd" class="form-label form-label-required">Current Password</label>
                    <div class="input-group">
                        <span class="input-icon">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input
                            type="password"
                            class="form-control"
                            id="current_password_pwd"
                            name="current_password"
                            placeholder="Enter current password"
                            autocomplete="current-password"
                        >
                        <button type="button" class="password-toggle input-icon-right" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="invalid-feedback"></div>
                </div>

                <div class="form-group">
                    <label for="new_password" class="form-label form-label-required">New Password</label>
                    <div class="input-group">
                        <span class="input-icon">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input
                            type="password"
                            class="form-control"
                            id="new_password"
                            name="new_password"
                            placeholder="Enter new password"
                            autocomplete="new-password"
                            data-validate-password
                            data-strength-meter
                        >
                        <button type="button" class="password-toggle input-icon-right" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="invalid-feedback"></div>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label form-label-required">Confirm New Password</label>
                    <div class="input-group">
                        <span class="input-icon">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input
                            type="password"
                            class="form-control"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="Re-enter new password"
                            autocomplete="new-password"
                            data-match="#new_password"
                        >
                        <button type="button" class="password-toggle input-icon-right" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="invalid-feedback"></div>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <span><strong>Note:</strong> Changing your password will log out all other active sessions on other devices.</span>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-key"></i> Change Password
                </button>
            </form>
        </div>
    </div>

    <!-- 🔗 Linked Accounts -->
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-link"></i> Linked Accounts</h3>
        </div>
        <div class="card-body">
            <p class="text-muted mb-4">
                Connect your SIGNula account with third-party providers for easier sign-in and account recovery.
            </p>

            <?php if (!empty($linkedAccounts)): ?>
                <!-- 📊 Linked Accounts List -->
                <div class="mb-4">
                    <h5 class="mb-3">Connected Accounts</h5>
                    <?php foreach ($linkedAccounts as $account): ?>
                        <?php
                        $providerIcons = [
                            'google' => 'fab fa-google',
                            'microsoft' => 'fab fa-microsoft',
                            'apple' => 'fab fa-apple',
                            'facebook' => 'fab fa-facebook',
                        ];
                        $icon = $providerIcons[$account['provider']] ?? 'fas fa-link';
                        $providerName = ucfirst($account['provider']);
                        ?>
                        <div class="d-flex align-items-center justify-content-between p-3 mb-2" style="border: 1px solid #dee2e6; border-radius: 0.5rem; background: #f8f9fa;">
                            <div class="d-flex align-items-center">
                                <div style="width: 48px; height: 48px; border-radius: 50%; background: white; display: flex; align-items: center; justify-content: center; margin-right: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                    <?php if (!empty($account['profilePicture'])): ?>
                                        <img src="<?php echo htmlspecialchars($account['profilePicture']); ?>" alt="<?php echo htmlspecialchars($providerName); ?>" style="width: 48px; height: 48px; border-radius: 50%;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <i class="<?php echo $icon; ?>" style="font-size: 24px; display: none;"></i>
                                    <?php else: ?>
                                        <i class="<?php echo $icon; ?>" style="font-size: 24px;"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <strong><?php echo htmlspecialchars($providerName); ?></strong>
                                    <p style="margin: 0; color: #6c757d; font-size: 0.875rem;">
                                        <?php echo htmlspecialchars($account['email'] ?? $account['displayName']); ?>
                                    </p>
                                    <small style="color: #999; font-size: 0.75rem;">
                                        Connected <?php echo date('M j, Y', strtotime($account['createdAt'])); ?>
                                    </small>
                                </div>
                            </div>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="unlink_oauth">
                                <input type="hidden" name="provider" value="<?php echo htmlspecialchars($account['provider']); ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Are you sure you want to unlink your <?php echo htmlspecialchars($providerName); ?> account?');">
                                    <i class="fas fa-unlink"></i> Unlink
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- 🔗 Available Providers to Link -->
            <h5 class="mb-3"><?php echo empty($linkedAccounts) ? 'Connect an Account' : 'Connect More Accounts'; ?></h5>

            <?php
            // 📋 Get already linked providers
            $linkedProviders = array_column($linkedAccounts, 'provider');
            $availableProviders = [
                ['id' => 'google', 'name' => 'Google', 'icon' => 'fab fa-google', 'color' => '#4285F4'],
                ['id' => 'microsoft', 'name' => 'Microsoft', 'icon' => 'fab fa-microsoft', 'color' => '#00A4EF'],
                ['id' => 'apple', 'name' => 'Apple', 'icon' => 'fab fa-apple', 'color' => '#000'],
                ['id' => 'facebook', 'name' => 'Facebook', 'icon' => 'fab fa-facebook', 'color' => '#1877F2'],
            ];
            ?>

            <div class="row">
                <?php foreach ($availableProviders as $provider): ?>
                    <?php if (!in_array($provider['id'], $linkedProviders)): ?>
                        <div class="col-md-6 mb-3">
                            <a href="/oauth/authorize?provider=<?php echo $provider['id']; ?>&mode=link" class="btn btn-outline-secondary w-100" style="padding: 0.75rem;">
                                <i class="<?php echo $provider['icon']; ?> me-2" style="color: <?php echo $provider['color']; ?>;"></i>
                                Connect <?php echo $provider['name']; ?>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if (count($linkedProviders) === count($availableProviders)): ?>
                    <div class="col-12">
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            All available providers are connected!
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="alert alert-info mt-3">
                <i class="fas fa-info-circle"></i>
                <strong>Tip:</strong> Linking multiple accounts makes it easier to sign in and helps with account recovery if you forget your password.
            </div>
        </div>
    </div>

    <!-- 🗑️ Danger Zone -->
    <div class="card" style="border-color: var(--danger-color);">
        <div class="card-header" style="background: rgba(239, 68, 68, 0.1); border-bottom: 1px solid var(--danger-color);">
            <h3 class="card-title" style="color: var(--danger-color);">
                <i class="fas fa-exclamation-triangle"></i> Danger Zone
            </h3>
        </div>
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <strong>Delete Account</strong>
                    <p style="margin: 0.25rem 0 0; color: var(--text-secondary); font-size: 0.875rem;">
                        Permanently delete your account and all associated data. This action cannot be undone.
                    </p>
                </div>
                <button class="btn btn-danger" onclick="alert('Account deletion feature coming soon!')">
                    <i class="fas fa-trash"></i> Delete Account
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 📜 JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="/assets/js/main.js"></script>

<script>
// 📧 Email form validation
document.getElementById('emailForm').addEventListener('submit', function(e) {
    let isValid = true;
    const newEmail = document.getElementById('new_email');
    const currentPassword = document.getElementById('current_password_email');

    if (!newEmail.value.trim() || !SIGNula.FormValidator.isValidEmail(newEmail.value)) {
        SIGNula.FormValidator.setInvalid(newEmail, 'Please enter a valid email address');
        isValid = false;
    }

    if (!currentPassword.value) {
        SIGNula.FormValidator.setInvalid(currentPassword, 'Current password is required');
        isValid = false;
    }

    if (!isValid) {
        e.preventDefault();
    }
});

// 🔑 Password form validation
document.getElementById('passwordForm').addEventListener('submit', function(e) {
    let isValid = true;
    const currentPassword = document.getElementById('current_password_pwd');
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');

    if (!currentPassword.value) {
        SIGNula.FormValidator.setInvalid(currentPassword, 'Current password is required');
        isValid = false;
    }

    if (!newPassword.value) {
        SIGNula.FormValidator.setInvalid(newPassword, 'New password is required');
        isValid = false;
    } else {
        const validation = SIGNula.FormValidator.validatePassword(newPassword.value);
        if (!validation.valid) {
            SIGNula.FormValidator.setInvalid(newPassword, validation.errors[0]);
            isValid = false;
        }
    }

    if (newPassword.value !== confirmPassword.value) {
        SIGNula.FormValidator.setInvalid(confirmPassword, 'Passwords do not match');
        isValid = false;
    }

    if (!isValid) {
        e.preventDefault();
    }
});
</script>

</body>
</html>
