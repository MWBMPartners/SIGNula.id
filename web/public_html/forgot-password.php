<?php
/**
 * ============================================================================
 * 🔑 SIGNula - Forgot Password Page
 * ============================================================================
 *
 * Purpose: Request password reset link
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ Security middleware — rate limiting + CAPTCHA verification
    $securityCheck = SecurityMiddleware::handleFormSubmission('forgot-password');
    if (!$securityCheck['allowed']) {
        $error = $securityCheck['error'];
    } elseif (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        // 🛡️ Verify CSRF token
        $error = 'Invalid security token. Please try again.';
    } else {
        $email = SecurityUtils::sanitizeEmail($_POST['email'] ?? '');

        if (!$email) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                // 🔍 Find user by email
                $user = Database::fetchOne(
                    "SELECT userID, email, displayName FROM tblUsers WHERE email = ? AND deletedAt IS NULL",
                    [$email],
                    's'
                );

                // ⚠️ Always show success message (security: don't reveal if email exists)
                $success = 'If an account exists with this email address, you will receive password reset instructions.';

                if ($user) {
                    // 🎫 Generate reset token
                    $resetToken = SecurityUtils::generateToken();
                    $resetCode = SecurityUtils::generateNumericCode(6);
                    $expiryMinutes = getSetting('mfa.otp.lifetime', 900) / 60; // Default 15 minutes

                    // 💾 Store reset token
                    Database::query(
                        "INSERT INTO tblVerificationTokens
                        (userID, token, tokenType, email, code, ipAddress, userAgent, expiresAt)
                        VALUES (?, ?, 'password_reset', ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))",
                        [
                            $user['userID'],
                            $resetToken,
                            $email,
                            $resetCode,
                            getClientIP(),
                            getUserAgent(),
                            $expiryMinutes
                        ],
                        'isssssi'
                    );

                    // 📧 Send password reset email
                    EmailService::sendPasswordResetEmail(
                        $email,
                        $user['displayName'] ?? 'User',
                        $resetToken,
                        $resetCode
                    );

                    // 📝 Log activity
                    ActivityLogger::log(
                        $user['userID'],
                        'password_reset_requested',
                        'auth',
                        'info',
                        'Password reset requested',
                        ['email' => $email]
                    );
                }

            } catch (Exception $e) {
                ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
                $error = 'An error occurred. Please try again later.';
            }
        }
    }
}

// 🎫 Generate CSRF token
$csrfToken = SecurityUtils::generateCSRFToken();

// 📄 Page metadata
$pageTitle = 'Forgot Password - SIGNula';
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

    <!-- 🛡️ CAPTCHA Scripts (if enabled) -->
    <?php echo CaptchaVerifier::getRequiredScripts(); ?>
</head>
<body>

<div class="auth-layout">
    <div class="auth-container">
        <!-- 🎴 Forgot Password Card -->
        <div class="auth-card">
            <!-- 🏠 Logo and Header -->
            <div class="auth-card-header">
                <div class="auth-logo">
                    <i class="fas fa-key"></i>
                </div>
                <h1>Forgot Password?</h1>
                <p>Enter your email and we'll send you reset instructions</p>
            </div>

            <!-- 🚨 Error/Success Messages -->
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                    <button type="button" class="alert-close" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
                <div class="text-center">
                    <p class="text-muted mb-3">Please check your email for password reset instructions.</p>
                    <a href="/login" class="btn btn-primary btn-block">
                        <i class="fas fa-sign-in-alt"></i> Back to Login
                    </a>
                </div>
            <?php else: ?>

            <!-- 📝 Forgot Password Form -->
            <form method="POST" action="/forgot-password" id="forgotPasswordForm">
                <!-- 🛡️ CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <!-- 🛡️ Local Form Protection (honeypot + timing + JS challenge) -->
                <?php echo FormProtection::renderAllFields(); ?>

                <!-- 📧 Email -->
                <div class="form-group">
                    <label for="email" class="form-label form-label-required">Email Address</label>
                    <div class="input-group">
                        <span class="input-icon">
                            <i class="fas fa-envelope"></i>
                        </span>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            name="email"
                            placeholder="your.email@example.com"
                            required
                            autofocus
                            autocomplete="email"
                            maxlength="255"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        >
                    </div>
                    <div class="invalid-feedback"></div>
                </div>

                <!-- 🛡️ CAPTCHA Widget (if enabled) -->
                <?php echo CaptchaVerifier::renderWidget('forgotPasswordForm'); ?>

                <!-- 🔘 Submit Button -->
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <i class="fas fa-paper-plane"></i> Send Reset Link
                </button>
            </form>

            <?php endif; ?>

            <!-- 🔗 Divider -->
            <div style="position: relative; text-align: center; margin: 2rem 0;">
                <hr style="border-top: 1px solid var(--border-color);">
            </div>

            <!-- 📝 Back to Login -->
            <div class="text-center">
                <p style="color: var(--text-secondary); margin-bottom: 0.5rem;">
                    Remember your password?
                </p>
                <a href="/login" class="btn-link">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>
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
    const form = document.getElementById('forgotPasswordForm');

    if (form) {
        form.addEventListener('submit', function(e) {
            const email = document.getElementById('email');

            if (!email.value.trim()) {
                e.preventDefault();
                SIGNula.FormValidator.setInvalid(email, 'Email address is required');
            } else if (!SIGNula.FormValidator.isValidEmail(email.value)) {
                e.preventDefault();
                SIGNula.FormValidator.setInvalid(email, 'Please enter a valid email address');
            }
        });
    }
});
</script>

</body>
</html>
