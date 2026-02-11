<?php
/**
 * ============================================================================
 * 🔓 SIGNula - Passwordless Login Request
 * ============================================================================
 *
 * Purpose: Request a passwordless login link via email
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📚 Load required classes
require_once SIGNULA_ROOT . '/private_html/auth/PasswordlessLoginHandler.php';

// 🔄 If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: /dashboard');
    exit;
}

$pageTitle = 'Passwordless Login';
$error = null;
$success = null;

// 📝 Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ Verify CSRF token
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid or expired security token. Please refresh and try again.';
    } else {
        try {
            $email = $_POST['email'] ?? '';

            if (empty($email)) {
                $error = 'Please enter your email address';
            } else {
                $handler = new PasswordlessLoginHandler();
                $result = $handler->sendLoginLink($email, 'login');

                if ($result['success']) {
                    $success = $result['message'];
                } else {
                    $error = $result['error'];
                }
            }
        } catch (Exception $e) {
            error_log("Passwordless request error: " . $e->getMessage());
            $error = 'An error occurred. Please try again.';
        }
    }
}

// 🛡️ Generate CSRF token for forms
$csrfToken = SecurityUtils::generateCSRFToken();

// 🎨 Include header
include SIGNULA_ROOT . '/private_html/layout/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <h1 class="h3">🔓 Passwordless Login</h1>
                        <p class="text-muted">Enter your email to receive a secure login link</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success" role="alert">
                            <strong>✅ Email Sent!</strong><br>
                            <?php echo htmlspecialchars($success); ?>
                            <hr>
                            <p class="mb-0 small">
                                <strong>Didn't receive it?</strong> Check your spam folder or try again in a few minutes.
                            </p>
                        </div>
                    <?php else: ?>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <div class="mb-4">
                                <label for="email" class="form-label">Email Address</label>
                                <input
                                    type="email"
                                    class="form-control form-control-lg"
                                    id="email"
                                    name="email"
                                    placeholder="you@example.com"
                                    required
                                    autocomplete="email"
                                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                >
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                📧 Send Login Link
                            </button>
                        </form>

                        <div class="mt-4">
                            <div class="alert alert-info small">
                                <strong>ℹ️ How it works:</strong>
                                <ol class="mb-0 mt-2 ps-3">
                                    <li>Enter your email address</li>
                                    <li>We'll send you a secure login link</li>
                                    <li>Click the link to log in instantly</li>
                                    <li>No password needed!</li>
                                </ol>
                            </div>
                        </div>
                    <?php endif; ?>

                    <hr class="my-4">

                    <div class="text-center">
                        <p class="text-muted small mb-2">Prefer to use a password?</p>
                        <a href="/auth/login" class="btn btn-outline-secondary btn-sm">
                            🔑 Traditional Login
                        </a>
                    </div>
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
include SIGNULA_ROOT . '/private_html/layout/footer.php';
?>
