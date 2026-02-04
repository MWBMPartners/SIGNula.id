<?php
/**
 * ============================================================================
 * 📧 SIGNula - Resend Email Verification
 * ============================================================================
 *
 * Purpose: Resend email verification link to user
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔄 Require authentication (but skip verification check to avoid redirect loop)
Auth::requireAuth('', true);

// Get current user
$user = Auth::getCurrentUser();

// 🔄 Redirect if already verified
if ($user && $user['emailVerified']) {
    redirect('/dashboard');
}

// 📝 Handle form submission
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ Verify CSRF token
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // 🚦 Check rate limiting (max 3 requests per hour)
        $rateLimitKey = 'resend_verification_' . $user['userID'];
        if (!SecurityUtils::checkRateLimit($rateLimitKey, 'resend_verification', 3, 3600)) {
            $error = 'Too many verification emails sent. Please try again later.';
        } else {
            try {
                // 🗄️ Begin transaction
                Database::beginTransaction();

                // 🗑️ Mark old verification tokens as used
                Database::query(
                    "UPDATE tblVerificationTokens
                     SET isUsed = TRUE, usedAt = NOW()
                     WHERE userID = ?
                     AND tokenType = 'email_verification'
                     AND isUsed = FALSE",
                    [$user['userID']],
                    'i'
                );

                // 📧 Generate new verification token
                $verificationToken = SecurityUtils::generateToken();
                $verificationCode = SecurityUtils::generateNumericCode(6);
                $expiryDays = getSetting('email_verification.token_lifetime_days', 7);

                $tokenQuery = "
                    INSERT INTO tblVerificationTokens (
                        userID, token, tokenType, email, code,
                        ipAddress, userAgent, expiresAt
                    ) VALUES (?, ?, 'email_verification', ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))
                ";

                Database::query($tokenQuery, [
                    $user['userID'],
                    $verificationToken,
                    $user['email'],
                    $verificationCode,
                    getClientIP(),
                    getUserAgent(),
                    $expiryDays
                ], 'isssssi');

                // 📝 Log activity
                ActivityLogger::log(
                    $user['userID'],
                    'verification_email_resent',
                    'auth',
                    'info',
                    'Verification email resent',
                    ['email' => $user['email']]
                );

                // ✅ Commit transaction
                Database::commit();

                // 📧 Send verification email
                EmailService::sendVerificationEmail(
                    $user['email'],
                    $verificationToken,
                    $verificationCode
                );

                $success = true;

            } catch (Exception $e) {
                // ↩️ Rollback on error
                if (Database::isConnected()) {
                    Database::rollback();
                }

                ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
                $error = 'An error occurred while sending the verification email. Please try again.';
            }
        }
    }
}

// 🎫 Generate CSRF token
$csrfToken = SecurityUtils::generateCSRFToken();

// 📄 Page metadata
$pageTitle = 'Resend Verification Email - SIGNula';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Resend your email verification link">
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
        <!-- 🎴 Resend Verification Card -->
        <div class="auth-card">
            <!-- 🏠 Logo and Header -->
            <div class="auth-card-header">
                <?php if ($success): ?>
                    <div class="auth-logo" style="color: var(--success-color);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h1>Email Sent!</h1>
                    <p>Check your inbox for the verification link</p>
                <?php else: ?>
                    <div class="auth-logo">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <h1>Resend Verification Email</h1>
                    <p>Get a new verification link sent to your email</p>
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
            <?php endif; ?>

            <?php if ($success): ?>
                <!-- ✅ Success Message -->
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span>A new verification email has been sent to <strong><?php echo htmlspecialchars($user['email']); ?></strong></span>
                </div>

                <!-- ℹ️ Information Box -->
                <div style="background: var(--bg-light); padding: 1.5rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--text-primary);">
                        <i class="fas fa-info-circle"></i> What's next?
                    </h4>
                    <ol style="margin: 0; padding-left: 1.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                        <li style="margin-bottom: 0.5rem;">Check your email inbox (and spam folder)</li>
                        <li style="margin-bottom: 0.5rem;">Click the verification link in the email</li>
                        <li style="margin-bottom: 0;">Complete the verification process</li>
                    </ol>
                </div>

                <!-- 🔘 Action Buttons -->
                <div style="display: grid; gap: 0.75rem;">
                    <a href="/verify-required" class="btn btn-primary btn-block btn-lg">
                        <i class="fas fa-arrow-left"></i> Back to Verification Page
                    </a>

                    <a href="/dashboard" class="btn btn-outline btn-block">
                        <i class="fas fa-home"></i> Go to Dashboard
                    </a>
                </div>

            <?php else: ?>
                <!-- 📝 Resend Form -->
                <form method="POST" action="/resend-verification" id="resendForm">
                    <!-- 🛡️ CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                    <!-- ℹ️ Information -->
                    <div style="background: var(--bg-light); padding: 1.5rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                        <p style="margin-bottom: 0.75rem; color: var(--text-secondary); font-size: 0.875rem;">
                            A new verification link will be sent to:
                        </p>
                        <p style="font-weight: 600; margin-bottom: 1rem; color: var(--text-primary);">
                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?>
                        </p>
                        <p style="margin: 0; color: var(--text-secondary); font-size: 0.875rem;">
                            <i class="fas fa-info-circle"></i> <strong>Note:</strong> You can request up to 3 verification emails per hour.
                        </p>
                    </div>

                    <!-- ⚠️ Important Information -->
                    <div style="background: rgba(239, 68, 68, 0.05); padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; border-left: 4px solid var(--danger-color);">
                        <p style="margin: 0; font-size: 0.875rem; color: var(--text-secondary);">
                            <i class="fas fa-exclamation-triangle" style="color: var(--danger-color);"></i>
                            If your email address is incorrect, please <a href="/account?section=profile" style="color: var(--primary-color); text-decoration: underline;">update it in your account settings</a> before requesting a new verification link.
                        </p>
                    </div>

                    <!-- 🔘 Submit Button -->
                    <button type="submit" class="btn btn-primary btn-block btn-lg">
                        <i class="fas fa-paper-plane"></i> Send Verification Email
                    </button>
                </form>

                <!-- 🔗 Divider -->
                <div style="position: relative; text-align: center; margin: 2rem 0;">
                    <hr style="border-top: 1px solid var(--border-color);">
                </div>

                <!-- 🔗 Additional Links -->
                <div class="text-center">
                    <a href="/verify-required" class="btn-link" style="margin-right: 1rem;">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <a href="/logout" class="btn-link">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- 🆘 Need Help? -->
        <div class="text-center">
            <p style="color: rgba(255, 255, 255, 0.8); margin-bottom: 0.5rem; font-size: 0.875rem;">
                Having trouble?
            </p>
            <a href="mailto:support@signula.id" class="btn-link" style="color: rgba(255, 255, 255, 0.9);">
                <i class="fas fa-life-ring"></i> Contact Support
            </a>
        </div>
    </div>
</div>

<!-- 📜 JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="/assets/js/main.js"></script>

<script>
// 🔐 Form confirmation
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('resendForm');

    if (form) {
        form.addEventListener('submit', function(e) {
            // Show loading state
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            }
        });
    }
});
</script>

</body>
</html>
