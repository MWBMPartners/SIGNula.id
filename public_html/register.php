<?php
/**
 * ============================================================================
 * 📝 SIGNula - Registration Page
 * ============================================================================
 *
 * Purpose: New user registration
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
$fieldErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ Verify CSRF token
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $email = $_POST['email'] ?? '';
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $firstName = $_POST['first_name'] ?? '';
        $lastName = $_POST['last_name'] ?? '';
        $agreeToTerms = isset($_POST['agree_terms']);

        // 🔍 Validate inputs
        $isValid = true;

        if (!$agreeToTerms) {
            $error = 'You must agree to the Terms of Service and Privacy Policy.';
            $isValid = false;
        }

        if ($password !== $confirmPassword) {
            $fieldErrors['confirm_password'] = 'Passwords do not match';
            $isValid = false;
        }

        if ($isValid) {
            // 📝 Attempt registration
            $result = Auth::register($email, $username, $password, [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'displayName' => trim($firstName . ' ' . $lastName) ?: $username
            ]);

            if ($result['success']) {
                $success = $result['message'];
                // Clear form data on success
                $_POST = [];
            } else {
                $error = $result['message'];
            }
        }
    }
}

// 🎫 Generate CSRF token for form
$csrfToken = SecurityUtils::generateCSRFToken();

// 📄 Page metadata
$pageTitle = 'Create Account - SIGNula';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create your free SIGNula account">
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
        <!-- 🎴 Registration Card -->
        <div class="auth-card">
            <!-- 🏠 Logo and Header -->
            <div class="auth-card-header">
                <div class="auth-logo">
                    <i class="fas fa-user-plus"></i>
                </div>
                <h1>Create Account</h1>
                <p>Join SIGNula and get started for free</p>
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
                    <div class="mt-3">
                        <a href="/login" class="btn btn-success">
                            <i class="fas fa-sign-in-alt"></i> Go to Login
                        </a>
                    </div>
                </div>
            <?php else: ?>

            <!-- 📝 Registration Form -->
            <form method="POST" action="/register" id="registerForm">
                <!-- 🛡️ CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <!-- 👤 Name Fields -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="first_name" class="form-label">First Name</label>
                            <input
                                type="text"
                                class="form-control"
                                id="first_name"
                                name="first_name"
                                placeholder="John"
                                autocomplete="given-name"
                                value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
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
                                placeholder="Doe"
                                autocomplete="family-name"
                                value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                            >
                        </div>
                    </div>
                </div>

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
                            autocomplete="email"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        >
                    </div>
                    <div class="invalid-feedback"></div>
                    <small class="form-text">We'll send a verification link to this email</small>
                </div>

                <!-- 👤 Username -->
                <div class="form-group">
                    <label for="username" class="form-label form-label-required">Username</label>
                    <div class="input-group">
                        <span class="input-icon">
                            <i class="fas fa-user"></i>
                        </span>
                        <input
                            type="text"
                            class="form-control"
                            id="username"
                            name="username"
                            placeholder="johndoe"
                            required
                            autocomplete="username"
                            pattern="[a-zA-Z0-9_]{3,30}"
                            value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                        >
                    </div>
                    <div class="invalid-feedback"></div>
                    <small class="form-text">3-30 characters, letters, numbers, and underscores only</small>
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
                            placeholder="Create a strong password"
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
                    <label for="confirm_password" class="form-label form-label-required">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-icon">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input
                            type="password"
                            class="form-control <?php echo isset($fieldErrors['confirm_password']) ? 'is-invalid' : ''; ?>"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="Re-enter your password"
                            required
                            autocomplete="new-password"
                            data-match="#password"
                        >
                    </div>
                    <div class="invalid-feedback">
                        <?php echo $fieldErrors['confirm_password'] ?? ''; ?>
                    </div>
                </div>

                <!-- ✅ Terms and Conditions -->
                <div class="form-check mb-4">
                    <input type="checkbox" class="form-check-input" id="agree_terms" name="agree_terms" required>
                    <label class="form-check-label" for="agree_terms">
                        I agree to the <a href="/terms" target="_blank">Terms of Service</a> and
                        <a href="/privacy" target="_blank">Privacy Policy</a>
                    </label>
                </div>

                <!-- 🔘 Submit Button -->
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>
            </form>

            <!-- 🔗 Divider -->
            <div style="position: relative; text-align: center; margin: 2rem 0;">
                <hr style="border-top: 1px solid var(--border-color);">
                <span style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 0 1rem; color: var(--text-muted); font-size: 0.875rem;">
                    or sign up with
                </span>
            </div>

            <!-- 🔗 OAuth Registration Buttons -->
            <div class="d-flex flex-column gap-3">
                <?php if (getSetting('oauth.google.enabled', false)): ?>
                <a href="/oauth/google?action=register" class="btn btn-secondary btn-block">
                    <i class="fab fa-google"></i> Sign up with Google
                </a>
                <?php endif; ?>

                <?php if (getSetting('oauth.microsoft.enabled', false)): ?>
                <a href="/oauth/microsoft?action=register" class="btn btn-secondary btn-block">
                    <i class="fab fa-microsoft"></i> Sign up with Microsoft
                </a>
                <?php endif; ?>

                <?php if (getSetting('oauth.apple.enabled', false)): ?>
                <a href="/oauth/apple?action=register" class="btn btn-secondary btn-block">
                    <i class="fab fa-apple"></i> Sign up with Apple
                </a>
                <?php endif; ?>
            </div>

            <?php endif; ?>
        </div>

        <!-- 📝 Sign In Link -->
        <div class="text-center" style="color: white;">
            Already have an account?
            <a href="/login" style="color: white; font-weight: 600;">
                Sign in <i class="fas fa-arrow-right"></i>
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
// 🔐 Additional registration page specific JavaScript
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('registerForm');

    if (!form) return;

    // Username validation (alphanumeric and underscore only)
    const usernameInput = document.getElementById('username');
    usernameInput.addEventListener('input', SIGNula.debounce(function() {
        const username = this.value.trim();

        if (username.length < 3) {
            SIGNula.FormValidator.setInvalid(this, 'Username must be at least 3 characters');
        } else if (username.length > 30) {
            SIGNula.FormValidator.setInvalid(this, 'Username must not exceed 30 characters');
        } else if (!/^[a-zA-Z0-9_]+$/.test(username)) {
            SIGNula.FormValidator.setInvalid(this, 'Username can only contain letters, numbers, and underscores');
        } else {
            SIGNula.FormValidator.setValid(this);
        }
    }, 500));

    // Form submission validation
    form.addEventListener('submit', function(e) {
        let isValid = true;

        const email = document.getElementById('email');
        const username = document.getElementById('username');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const agreeTerms = document.getElementById('agree_terms');

        // Validate email
        if (!email.value.trim()) {
            SIGNula.FormValidator.setInvalid(email, 'Email is required');
            isValid = false;
        } else if (!SIGNula.FormValidator.isValidEmail(email.value)) {
            SIGNula.FormValidator.setInvalid(email, 'Please enter a valid email address');
            isValid = false;
        }

        // Validate username
        if (!username.value.trim()) {
            SIGNula.FormValidator.setInvalid(username, 'Username is required');
            isValid = false;
        } else if (username.value.length < 3 || username.value.length > 30) {
            SIGNula.FormValidator.setInvalid(username, 'Username must be 3-30 characters');
            isValid = false;
        }

        // Validate password
        if (!password.value) {
            SIGNula.FormValidator.setInvalid(password, 'Password is required');
            isValid = false;
        } else {
            const passwordValidation = SIGNula.FormValidator.validatePassword(password.value);
            if (!passwordValidation.valid) {
                SIGNula.FormValidator.setInvalid(password, passwordValidation.errors[0]);
                isValid = false;
            }
        }

        // Validate password confirmation
        if (password.value !== confirmPassword.value) {
            SIGNula.FormValidator.setInvalid(confirmPassword, 'Passwords do not match');
            isValid = false;
        }

        // Validate terms agreement
        if (!agreeTerms.checked) {
            SIGNula.AlertManager.show('You must agree to the Terms of Service and Privacy Policy', 'danger');
            isValid = false;
        }

        if (!isValid) {
            e.preventDefault();
        }
    });
});
</script>

</body>
</html>
