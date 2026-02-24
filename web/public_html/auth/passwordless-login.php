<?php
/**
 * ============================================================================
 * 🔓 SIGNula - Passwordless Login Verification
 * ============================================================================
 *
 * Purpose: Verify passwordless login token and authenticate user
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📚 Load required classes
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'PasswordlessLoginHandler.php';

// 🔄 If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: /dashboard');
    exit;
}

$pageTitle = 'Logging In...';
$error = null;
$processing = false;

// 🔍 Get token from URL
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = 'Invalid login link. Please request a new one.';
} else {
    $processing = true;

    try {
        $handler = new PasswordlessLoginHandler();
        $result = $handler->verifyLoginToken($token);

        if ($result['success']) {
            // ✅ Token valid - log in the user
            if (isset($result['userID'])) {
                // 🔐 Create session
                $_SESSION['userID'] = $result['userID'];
                $_SESSION['loginMethod'] = 'passwordless';
                $_SESSION['loginTime'] = time();

                // 🔄 Redirect to dashboard
                header('Location: /dashboard');
                exit;
            }

            // Handle special actions (like completing registration)
            if (isset($result['action'])) {
                if ($result['action'] === 'complete_registration') {
                    $_SESSION['pending_registration_email'] = $result['email'];
                    $_SESSION['registration_token'] = $result['token'];
                    header('Location: /auth/complete-registration');
                    exit;
                }
            }
        } else {
            $error = $result['error'];
            $processing = false;
        }
    } catch (Exception $e) {
        error_log("Passwordless login error: " . $e->getMessage());
        $error = 'An error occurred during login. Please try again.';
        $processing = false;
    }
}

// 🎨 Include header
include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow">
                <div class="card-body p-5 text-center">
                    <?php if ($processing): ?>
                        <!-- Processing State -->
                        <div class="mb-4">
                            <div class="spinner-border text-primary" style="width: 4rem; height: 4rem;" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                        <h2 class="h4 mb-3">Logging you in...</h2>
                        <p class="text-muted">Please wait while we verify your login link.</p>

                    <?php elseif ($error): ?>
                        <!-- Error State -->
                        <div class="mb-4">
                            <div class="display-1">❌</div>
                        </div>
                        <h2 class="h4 mb-3">Login Failed</h2>
                        <div class="alert alert-danger text-start">
                            <?php echo htmlspecialchars($error); ?>
                        </div>

                        <div class="d-grid gap-2 mt-4">
                            <a href="/auth/passwordless-request" class="btn btn-primary">
                                📧 Request New Login Link
                            </a>
                            <a href="/auth/login" class="btn btn-outline-secondary">
                                🔑 Traditional Login
                            </a>
                        </div>

                        <div class="mt-4">
                            <div class="alert alert-info small text-start">
                                <strong>ℹ️ Common issues:</strong>
                                <ul class="mb-0 mt-2 ps-3">
                                    <li>The link may have expired (links expire after 15 minutes)</li>
                                    <li>The link may have already been used</li>
                                    <li>You may have clicked an old link from a previous request</li>
                                </ul>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- Invalid State (no token) -->
                        <div class="mb-4">
                            <div class="display-1">⚠️</div>
                        </div>
                        <h2 class="h4 mb-3">Invalid Login Link</h2>
                        <p class="text-muted">
                            This login link appears to be invalid or incomplete.
                        </p>

                        <div class="d-grid gap-2 mt-4">
                            <a href="/auth/passwordless-request" class="btn btn-primary">
                                📧 Request New Login Link
                            </a>
                            <a href="/auth/login" class="btn btn-outline-secondary">
                                🔑 Traditional Login
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="text-center mt-3">
                <p class="text-muted small">
                    Don't have an account?
                    <a href="/auth/register">Create one now</a>
                </p>
            </div>
        </div>
    </div>
</div>

<?php
// 🎨 Include footer
include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'footer.php';
?>
