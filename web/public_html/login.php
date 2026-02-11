<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Login Page
 * ============================================================================
 *
 * Purpose: User login with password and OAuth options
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
    $redirectTo = $_GET['redirect'] ?? '/dashboard';
    redirect($redirectTo);
}

// 📝 Handle form submission
$error = null;
$success = null;

// 🔍 Check for OAuth error from callback redirect
if (!empty($_GET['oauth_error'])) {
    $error = $_GET['oauth_error'];
}

// 🔍 Check for session-based info/success messages (from OAuth flows)
if (!empty($_SESSION['info'])) {
    $error = $_SESSION['info'];  // Display as info via error alert for now
    unset($_SESSION['info']);
}
if (!empty($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ Verify CSRF token
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $identifier = $_POST['identifier'] ?? '';
        $password = $_POST['password'] ?? '';
        $rememberMe = isset($_POST['remember_me']);

        // 🔐 Attempt login
        $result = Auth::login($identifier, $password, $rememberMe);

        if ($result['success']) {
            if ($result['requiresMFA']) {
                // Redirect to MFA verification page
                redirect('/mfa/verify');
            } else {
                // Successful login - check if email verification is required
                if (Auth::requiresEmailVerification()) {
                    // Redirect to verification required page
                    redirect('/verify-required?just_logged_in=1');
                } else {
                    // Successful login with verified email
                    $redirectTo = $_GET['redirect'] ?? '/dashboard';
                    redirect($redirectTo);
                }
            }
        } else {
            $error = $result['message'];
        }
    }
}

// 🎫 Generate CSRF token for form
$csrfToken = SecurityUtils::generateCSRFToken();

// 📄 Page metadata
$pageTitle = 'Sign In - SIGNula';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sign in to your SIGNula account">
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
        <!-- 🎴 Login Card -->
        <div class="auth-card">
            <!-- 🏠 Logo and Header -->
            <div class="auth-card-header">
                <div class="auth-logo">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h1>Welcome Back</h1>
                <p>Sign in to your SIGNula account</p>
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
                <div class="alert alert-success alert-dismissible">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                    <button type="button" class="alert-close" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <!-- 📝 Login Form -->
            <form method="POST" action="/login<?php echo isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : ''; ?>" id="loginForm">
                <!-- 🛡️ CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <!-- 📧 Email or Username -->
                <div class="form-group">
                    <label for="identifier" class="form-label form-label-required">Email or Username</label>
                    <div class="input-group">
                        <span class="input-icon">
                            <i class="fas fa-user"></i>
                        </span>
                        <input
                            type="text"
                            class="form-control"
                            id="identifier"
                            name="identifier"
                            placeholder="Enter your email or username"
                            required
                            autofocus
                            autocomplete="username"
                            value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>"
                        >
                    </div>
                    <div class="invalid-feedback"></div>
                </div>

                <!-- 🔑 Password -->
                <div class="form-group">
                    <label for="password" class="form-label form-label-required">Password</label>
                    <div class="input-group">
                        <span class="input-icon">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" class="password-toggle input-icon-right" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="invalid-feedback"></div>
                </div>

                <!-- 🔄 Remember Me & Forgot Password -->
                <div class="d-flex justify-between align-center mb-4">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me">
                        <label class="form-check-label" for="remember_me">
                            Remember me
                        </label>
                    </div>
                    <a href="/forgot-password" class="btn-link text-sm">
                        Forgot password?
                    </a>
                </div>

                <!-- 🔘 Submit Button -->
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>

            <!-- 🔗 Divider -->
            <div style="position: relative; text-align: center; margin: 2rem 0;">
                <hr style="border-top: 1px solid var(--border-color);">
                <span style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 0 1rem; color: var(--text-muted); font-size: 0.875rem;">
                    or continue with
                </span>
            </div>

            <!-- 🔗 OAuth Login Buttons -->
            <div class="oauth-buttons" style="display: flex; flex-direction: column; gap: 0.75rem;">
                <!-- Google Sign In -->
                <a href="/oauth/authorize?provider=google&amp;purpose=signin" class="oauth-btn oauth-btn-google" style="display: flex; align-items: center; justify-content: center; padding: 0.75rem 1rem; border: 1px solid #ddd; border-radius: 0.5rem; text-decoration: none; font-weight: 500; transition: all 0.2s; background: white; color: #333;">
                    <svg style="width: 20px; height: 20px; margin-right: 0.75rem;" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                    </svg>
                    Sign in with Google
                </a>

                <!-- Microsoft Sign In -->
                <a href="/oauth/authorize?provider=microsoft&amp;purpose=signin" class="oauth-btn oauth-btn-microsoft" style="display: flex; align-items: center; justify-content: center; padding: 0.75rem 1rem; border: 1px solid #ddd; border-radius: 0.5rem; text-decoration: none; font-weight: 500; transition: all 0.2s; background: white; color: #333;">
                    <svg style="width: 20px; height: 20px; margin-right: 0.75rem;" viewBox="0 0 23 23">
                        <path fill="#f35325" d="M1 1h10v10H1z"/>
                        <path fill="#81bc06" d="M12 1h10v10H12z"/>
                        <path fill="#05a6f0" d="M1 12h10v10H1z"/>
                        <path fill="#ffba08" d="M12 12h10v10H12z"/>
                    </svg>
                    Sign in with Microsoft
                </a>

                <!-- Apple Sign In -->
                <a href="/oauth/authorize?provider=apple&amp;purpose=signin" class="oauth-btn oauth-btn-apple" style="display: flex; align-items: center; justify-content: center; padding: 0.75rem 1rem; border: 1px solid #000; border-radius: 0.5rem; text-decoration: none; font-weight: 500; transition: all 0.2s; background: #000; color: white;">
                    <i class="fab fa-apple" style="font-size: 20px; margin-right: 0.75rem;"></i>
                    Sign in with Apple
                </a>

                <!-- Facebook Sign In -->
                <a href="/oauth/authorize?provider=facebook&amp;purpose=signin" class="oauth-btn oauth-btn-facebook" style="display: flex; align-items: center; justify-content: center; padding: 0.75rem 1rem; border: 1px solid #1877f2; border-radius: 0.5rem; text-decoration: none; font-weight: 500; transition: all 0.2s; background: #1877f2; color: white;">
                    <i class="fab fa-facebook" style="font-size: 20px; margin-right: 0.75rem;"></i>
                    Sign in with Facebook
                </a>
            </div>

            <style>
                .oauth-btn:hover {
                    transform: translateY(-1px);
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                }

                .oauth-btn-google:hover {
                    background: #f8f9fa !important;
                }

                .oauth-btn-microsoft:hover {
                    background: #f8f9fa !important;
                }

                .oauth-btn-apple:hover {
                    background: #333 !important;
                }

                .oauth-btn-facebook:hover {
                    background: #166fe5 !important;
                }
            </style>

            <!-- 📧 Passwordless Login -->
            <div class="text-center mt-4">
                <a href="/passwordless-login" class="btn-link">
                    <i class="fas fa-envelope"></i> Send me a magic link instead
                </a>
            </div>
        </div>

        <!-- 📝 Sign Up Link -->
        <div class="text-center" style="color: white;">
            Don't have an account?
            <a href="/register<?php echo isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : ''; ?>" style="color: white; font-weight: 600;">
                Sign up now <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <!-- 🏠 Back to Home -->
        <div class="text-center mt-3">
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
// 🔐 Additional login page specific JavaScript
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('loginForm');

    // Add custom validation on submit
    form.addEventListener('submit', function(e) {
        let isValid = true;

        const identifier = document.getElementById('identifier');
        const password = document.getElementById('password');

        // Validate identifier
        if (!identifier.value.trim()) {
            SIGNula.FormValidator.setInvalid(identifier, 'Please enter your email or username');
            isValid = false;
        }

        // Validate password
        if (!password.value) {
            SIGNula.FormValidator.setInvalid(password, 'Please enter your password');
            isValid = false;
        }

        if (!isValid) {
            e.preventDefault();
        }
    });

    // Clear validation on input
    form.querySelectorAll('input').forEach(input => {
        input.addEventListener('input', function() {
            if (this.classList.contains('is-invalid')) {
                SIGNula.FormValidator.clearValidation(this);
            }
        });
    });
});
</script>

</body>
</html>
