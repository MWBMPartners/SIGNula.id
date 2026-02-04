<?php
/**
 * ============================================================================
 * 📧 SIGNula - Email Verification Required
 * ============================================================================
 *
 * Purpose: Notify users they must verify email before accessing features
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

// Check if user just logged in
$justLoggedIn = isset($_GET['just_logged_in']);

// 📊 Get verification token info
$tokenInfo = null;
$tokenExpiry = null;
$daysRemaining = null;

try {
    $tokenInfo = Database::fetchOne(
        "SELECT token, expiresAt, createdAt
         FROM tblVerificationTokens
         WHERE userID = ?
         AND tokenType = 'email_verification'
         AND isUsed = FALSE
         AND expiresAt > NOW()
         ORDER BY createdAt DESC
         LIMIT 1",
        [$user['userID']],
        'i'
    );

    if ($tokenInfo) {
        $tokenExpiry = new DateTime($tokenInfo['expiresAt']);
        $now = new DateTime();
        $diff = $now->diff($tokenExpiry);
        $daysRemaining = $diff->days;
    }
} catch (Exception $e) {
    ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
}

// 🎫 Generate CSRF token
$csrfToken = SecurityUtils::generateCSRFToken();

// 📄 Page metadata
$pageTitle = 'Email Verification Required - SIGNula';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Verify your email to access SIGNula">
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
        <!-- 🎴 Verification Required Card -->
        <div class="auth-card">
            <!-- 🏠 Logo and Header -->
            <div class="auth-card-header">
                <div class="auth-logo" style="color: var(--warning-color);">
                    <i class="fas fa-envelope-open-text"></i>
                </div>
                <h1>Email Verification Required</h1>
                <p>Please verify your email address to access your account</p>
            </div>

            <!-- ✅ Welcome Message (if just logged in) -->
            <?php if ($justLoggedIn): ?>
                <div class="alert alert-success" style="margin-bottom: 1.5rem;">
                    <i class="fas fa-check-circle"></i>
                    <span><strong>Welcome back!</strong> You've successfully logged in. Please verify your email to access all features.</span>
                </div>
            <?php endif; ?>

            <!-- ℹ️ Information Box -->
            <div style="background: var(--bg-light); padding: 1.5rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; border-left: 4px solid var(--warning-color);">
                <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--warning-color);">
                    <i class="fas fa-info-circle"></i> Verification Needed
                </h3>
                <p style="margin-bottom: 0.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                    A verification email has been sent to:
                </p>
                <p style="font-weight: 600; margin-bottom: 1rem; color: var(--text-primary);">
                    <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?>
                </p>

                <?php if ($tokenInfo && $daysRemaining !== null): ?>
                    <p style="margin: 0; color: var(--text-secondary); font-size: 0.875rem;">
                        <i class="fas fa-clock"></i>
                        Your verification link will expire in <strong><?php echo $daysRemaining; ?> day<?php echo $daysRemaining !== 1 ? 's' : ''; ?></strong>
                    </p>
                <?php endif; ?>
            </div>

            <!-- 📝 Instructions -->
            <div style="margin-bottom: 1.5rem;">
                <h4 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--text-primary);">
                    What to do next:
                </h4>
                <ol style="margin: 0; padding-left: 1.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                    <li style="margin-bottom: 0.5rem;">Check your email inbox for a message from SIGNula</li>
                    <li style="margin-bottom: 0.5rem;">Click the verification link in the email</li>
                    <li style="margin-bottom: 0.5rem;">Return to this page or login again</li>
                </ol>
            </div>

            <!-- ⚠️ Didn't receive email? -->
            <div style="background: rgba(99, 102, 241, 0.05); padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                <p style="margin: 0 0 0.75rem; font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                    <i class="fas fa-question-circle"></i> Didn't receive the email?
                </p>
                <ul style="margin: 0; padding-left: 1.5rem; font-size: 0.875rem; color: var(--text-secondary);">
                    <li>Check your spam or junk folder</li>
                    <li>Make sure <?php echo htmlspecialchars($user['email']); ?> is correct</li>
                    <li>Click the button below to resend the verification email</li>
                </ul>
            </div>

            <!-- 🔘 Action Buttons -->
            <div style="display: grid; gap: 0.75rem;">
                <a href="/resend-verification" class="btn btn-primary btn-block btn-lg">
                    <i class="fas fa-paper-plane"></i> Resend Verification Email
                </a>

                <a href="/account?section=profile" class="btn btn-outline btn-block">
                    <i class="fas fa-user-edit"></i> Update Email Address
                </a>

                <div style="position: relative; text-align: center; margin: 1rem 0;">
                    <hr style="border-top: 1px solid var(--border-color);">
                </div>

                <a href="/logout" class="btn btn-link" style="color: var(--text-secondary);">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <!-- 🆘 Need Help? -->
        <div class="text-center">
            <p style="color: rgba(255, 255, 255, 0.8); margin-bottom: 0.5rem; font-size: 0.875rem;">
                Still having trouble?
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

</body>
</html>
