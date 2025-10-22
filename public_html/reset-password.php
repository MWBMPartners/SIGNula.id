<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Reset Password Page
 * ============================================================================
 *
 * Purpose: Reset password with token
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔄 Redirect if already logged in
if (Auth::isAuthenticated()) {
    redirect('/dashboard');
}

// 📝 Handle form submission
$error = null;
$success = null;
$tokenValid = false;
$tokenData = null;

// Get token from URL
$token = $_GET['token'] ?? '';

if (!empty($token)) {
    // 🔍 Validate token
    try {
        $tokenData = Database::fetchOne(
            "SELECT vt.*, u.email, u.displayName
             FROM tblVerificationTokens vt
             JOIN tblUsers u ON vt.userID = u.userID
             WHERE vt.token = ?
             AND vt.tokenType = 'password_reset'
             AND vt.isUsed = FALSE
             AND vt.expiresAt > NOW()
             AND u.deletedAt IS NULL",
            [$token],
            's'
        );

        if ($tokenData) {
            $tokenValid = true;
        } else {
            $error = 'Invalid or expired password reset link. Please request a new one.';
        }
    } catch (Exception $e) {
        ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
        $error = 'An error occurred. Please try again.';
    }
} else {
    $error = 'No reset token provided.';
}

// Handle password reset submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    // 🛡️ Verify CSRF token
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // ✅ Validate passwords
        if ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            // 🔒 Validate password strength
            $validation = SecurityUtils::validatePassword($password);

            if (!$validation['valid']) {
                $error = implode(' ', $validation['errors']);
            } else {
                try {
                    // 🔄 Begin transaction
                    Database::beginTransaction();

                    // 🔐 Hash new password
                    $passwordHash = SecurityUtils::hashPassword($password);

                    // 💾 Update user password
                    Database::query(
                        "UPDATE tblUsers
                         SET passwordHash = ?, failedLoginAttempts = 0, lastFailedLogin = NULL
                         WHERE userID = ?",
                        [$passwordHash, $tokenData['userID']],
                        'si'
                    );

                    // 🎫 Mark token as used
                    Database::query(
                        "UPDATE tblVerificationTokens
                         SET isUsed = TRUE, usedAt = NOW()
                         WHERE tokenID = ?",
                        [$tokenData['tokenID']],
                        'i'
                    );

                    // 🗑️ Invalidate all existing sessions (force re-login)
                    Database::query(
                        "UPDATE tblUserSessions
                         SET isActive = FALSE
                         WHERE userID = ?",
                        [$tokenData['userID']],
                        'i'
                    );

                    // 📝 Log activity
                    ActivityLogger::log(
                        $tokenData['userID'],
                        'password_reset_completed',
                        'auth',
                        'info',
                        'Password was reset successfully'
                    );

                    // ✅ Commit transaction
                    Database::commit();

                    $success = true;

                } catch (Exception $e) {
                    // ↩️ Rollback on error
                    if (Database::isConnected()) {
                        Database::rollback();
                    }

                    ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
                    $error = 'An error occurred while resetting your password. Please try again.';
                }
            }
        }
    }
}

// 🎫 Generate CSRF token
$csrfToken = SecurityUtils::generateCSRFToken();

// 📄 Page metadata
$pageTitle = 'Reset Password - SIGNula';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Reset your SIGNula password">
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
<body>

<div class="auth-layout">
    <div class="auth-container">
        <!-- 🎴 Reset Password Card -->
        <div class="auth-card">
            <!-- 🏠 Logo and Header -->
            <div class="auth-card-header">
                <?php if ($success): ?>
                    <div class="auth-logo" style="color: var(--success-color);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h1>Password Reset!</h1>
                    <p>Your password has been updated</p>
                <?php else: ?>
                    <div class="auth-logo">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h1>Reset Password</h1>
                    <p>Choose a new password for your account</p>
                <?php endif; ?>
            </div>

            <!-- 🚨 Error Messages -->
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                    <button type="button" class="alert-close" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <?php if (!$tokenValid): ?>
                    <div class="text-center">
                        <a href="/forgot-password" class="btn btn-primary btn-block">
                            <i class="fas fa-key"></i> Request New Reset Link
                        </a>
                    </div>
                <?php endif; ?>

            <?php elseif ($success): ?>
                <!-- ✅ Success Message -->
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span>Your password has been reset successfully! You can now sign in with your new password.</span>
                </div>

                <div class="text-center">
                    <a href="/login" class="btn btn-primary btn-lg btn-block">
                        <i class="fas fa-sign-in-alt"></i> Sign In Now
                    </a>
                </div>

            <?php elseif ($tokenValid): ?>
                <!-- 📝 Reset Password Form -->
                <form method="POST" action="/reset-password?token=<?php echo urlencode($token); ?>" id="resetPasswordForm">
                    <!-- 🛡️ CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                    <!-- 👤 Email Display (read-only) -->
                    <div class="form-group">
                        <label class="form-label">Resetting password for:</label>
                        <div style="padding: 0.75rem 1rem; background: var(--bg-light); border: 1px solid var(--border-color); border-radius: var(--radius-md); color: var(--text-secondary);">
                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($tokenData['email']); ?>
                        </div>
                    </div>

                    <!-- 🔑 New Password -->
                    <div class="form-group">
                        <label for="password" class="form-label form-label-required">New Password</label>
                        <div class="input-group">
                            <span class="input-icon">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                placeholder="Enter your new password"
                                required
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

                    <!-- 🔑 Confirm Password -->
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
                                placeholder="Re-enter your new password"
                                required
                                autocomplete="new-password"
                                data-match="#password"
                            >
                            <button type="button" class="password-toggle input-icon-right" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="invalid-feedback"></div>
                    </div>

                    <!-- ℹ️ Password Requirements -->
                    <div style="background: var(--bg-light); padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                        <p style="margin: 0 0 0.5rem; font-weight: 600; font-size: 0.875rem;">
                            <i class="fas fa-info-circle"></i> Password Requirements:
                        </p>
                        <ul style="margin: 0; padding-left: 1.5rem; font-size: 0.875rem; color: var(--text-secondary);">
                            <li>At least 12 characters long</li>
                            <li>At least one uppercase letter</li>
                            <li>At least one lowercase letter</li>
                            <li>At least one number</li>
                            <li>At least one special character</li>
                        </ul>
                    </div>

                    <!-- 🔘 Submit Button -->
                    <button type="submit" class="btn btn-primary btn-block btn-lg">
                        <i class="fas fa-check"></i> Reset Password
                    </button>
                </form>

            <?php endif; ?>
        </div>

        <!-- 🏠 Back to Home -->
        <div class="text-center">
            <a href="/" class="btn-link" style="color: rgba(255, 255, 255, 0.8);">
                <i class="fas fa-home"></i> Back to Home
            </a>
        </div>
    </div>
</div>

<!-- 📜 JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="/assets/js/main.js"></script>

<script>
// 🔐 Form validation
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('resetPasswordForm');

    if (form) {
        form.addEventListener('submit', function(e) {
            let isValid = true;

            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirm_password');

            // Validate password
            if (!password.value) {
                SIGNula.FormValidator.setInvalid(password, 'Password is required');
                isValid = false;
            } else {
                const validation = SIGNula.FormValidator.validatePassword(password.value);
                if (!validation.valid) {
                    SIGNula.FormValidator.setInvalid(password, validation.errors[0]);
                    isValid = false;
                }
            }

            // Validate password confirmation
            if (password.value !== confirmPassword.value) {
                SIGNula.FormValidator.setInvalid(confirmPassword, 'Passwords do not match');
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();
            }
        });
    }
});
</script>

</body>
</html>
