<?php
/**
 * ============================================================================
 * ✅ SIGNula - Email Verification Handler
 * ============================================================================
 *
 * Purpose: Verify user email address via token
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📝 Handle verification
$success = false;
$error = null;
$message = null;

// Get token from URL
$token = $_GET['token'] ?? '';

if (!empty($token)) {
    try {
        // 🔍 Lookup verification token
        $tokenData = Database::fetchOne(
            "SELECT * FROM tblVerificationTokens
             WHERE token = ?
             AND tokenType = 'email_verification'
             AND isUsed = FALSE
             AND expiresAt > NOW()",
            [$token],
            's'
        );

        if (!$tokenData) {
            $error = 'Invalid or expired verification link. Please request a new verification email.';
        } else {
            // 🔄 Begin transaction
            Database::beginTransaction();

            // ✅ Mark email as verified
            Database::query(
                "UPDATE tblUsers
                 SET emailVerified = TRUE, emailVerifiedAt = NOW(), accountStatus = 'active'
                 WHERE userID = ?",
                [$tokenData['userID']],
                'i'
            );

            // 🎫 Mark token as used
            Database::query(
                "UPDATE tblVerificationTokens
                 SET isUsed = TRUE, usedAt = NOW()
                 WHERE tokenID = ?",
                [$tokenData['tokenID']],
                'i'
            );

            // 📝 Log activity
            ActivityLogger::log(
                $tokenData['userID'],
                'email_verified',
                'auth',
                'info',
                'Email address verified successfully'
            );

            // ✅ Commit transaction
            Database::commit();

            $success = true;
            $message = 'Your email has been verified successfully! You can now sign in to your account.';
        }

    } catch (Exception $e) {
        // ↩️ Rollback on error
        if (Database::isConnected()) {
            Database::rollback();
        }

        ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
        $error = 'An error occurred during verification. Please try again or contact support.';
    }
} else {
    $error = 'No verification token provided.';
}

// 📄 Page metadata
$pageTitle = 'Email Verification - SIGNula';
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
<body>

<div class="auth-layout">
    <div class="auth-container">
        <!-- 🎴 Verification Card -->
        <div class="auth-card">
            <div class="auth-card-header">
                <?php if ($success): ?>
                    <div class="auth-logo" style="color: var(--success-color);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h1>Email Verified!</h1>
                    <p>Your account is now active</p>
                <?php else: ?>
                    <div class="auth-logo" style="color: var(--danger-color);">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h1>Verification Failed</h1>
                    <p>Unable to verify your email</p>
                <?php endif; ?>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>

                <div class="text-center">
                    <a href="/login" class="btn btn-primary btn-lg btn-block">
                        <i class="fas fa-sign-in-alt"></i> Sign In Now
                    </a>
                </div>

            <?php else: ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>

                <div class="text-center">
                    <a href="/resend-verification" class="btn btn-primary btn-block mb-3">
                        <i class="fas fa-envelope"></i> Resend Verification Email
                    </a>
                    <a href="/login" class="btn btn-secondary btn-block">
                        <i class="fas fa-sign-in-alt"></i> Back to Login
                    </a>
                </div>
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

</body>
</html>
