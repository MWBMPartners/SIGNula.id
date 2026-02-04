<?php
/**
 * ============================================================================
 * 🔐 SIGNula - MFA Verification Page
 * ============================================================================
 *
 * Purpose: Verify second factor during login
 * PHP Version: 8.3+
 *
 * Features:
 * - TOTP code verification
 * - Backup code verification
 * - Email OTP verification
 * - Trusted device option
 * - Multiple verification methods
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔐 Check if user has pending MFA verification
if (empty($_SESSION['mfa_user_id']) || empty($_SESSION['mfa_required'])) {
    // No pending MFA verification - redirect to login
    header('Location: ../login.php');
    exit;
}

$userID = $_SESSION['mfa_user_id'];
$rememberDevice = false;

// 📝 Handle form submissions
$error = null;
$emailOTPSent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ Verify CSRF token
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            switch ($action) {
                case 'verify_code':
                    // ✅ Verify MFA code (TOTP or backup code)
                    $code = $_POST['code'] ?? '';
                    $trustDevice = isset($_POST['trust_device']);
                    $verified = false;

                    // Try TOTP first
                    if (MFA::verifyTOTP($userID, $code)) {
                        $verified = true;
                        ActivityLogger::log($userID, 'mfa_totp_login_success', 'User verified TOTP during login');
                    }
                    // Try backup code if TOTP fails
                    elseif (MFA::verifyBackupCode($userID, $code)) {
                        $verified = true;
                        ActivityLogger::log($userID, 'mfa_backup_code_login_success', 'User verified backup code during login');

                        // Warn about remaining backup codes
                        $remaining = MFA::getRemainingBackupCodes($userID);
                        if ($remaining < 3) {
                            $_SESSION['warning'] = "You have only {$remaining} backup codes remaining. Please generate new codes.";
                        }
                    }

                    if ($verified) {
                        // 🎉 MFA verification successful
                        unset($_SESSION['mfa_user_id'], $_SESSION['mfa_required']);

                        // 🍪 Trust device if requested
                        if ($trustDevice) {
                            $deviceFingerprint = getClientIP() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '');
                            MFA::trustDevice($userID, $deviceFingerprint);
                        }

                        // ✅ Complete login
                        $redirect = $_SESSION['redirect_after_login'] ?? 'dashboard.php';
                        unset($_SESSION['redirect_after_login']);

                        header('Location: ../' . $redirect);
                        exit;
                    } else {
                        $error = 'Invalid verification code. Please try again.';
                        ActivityLogger::log($userID, 'mfa_verification_failed', 'Failed MFA verification attempt');
                    }
                    break;

                case 'send_email_otp':
                    // 📧 Send email OTP
                    $result = MFA::sendEmailOTP($userID);

                    if ($result['success']) {
                        $emailOTPSent = true;
                    } else {
                        $error = $result['message'];
                    }
                    break;

                case 'cancel':
                    // ❌ Cancel login
                    unset($_SESSION['mfa_user_id'], $_SESSION['mfa_required'], $_SESSION['redirect_after_login']);
                    session_destroy();
                    header('Location: ../login.php?cancelled=1');
                    exit;
                    break;
            }

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            $error = 'An error occurred. Please try again.';
        }
    }
}

// 📊 Get user's enabled MFA methods
$mfaMethods = MFA::getEnabledMethods($userID);
$hasTOTP = in_array('totp', $mfaMethods);
$hasEmailOTP = in_array('email', $mfaMethods);

// 📄 Page title
$pageTitle = 'Two-Factor Authentication';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <title><?php echo htmlspecialchars($pageTitle); ?> - SIGNula</title>

    <!-- 📱 Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <!-- 🎨 Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
          integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous">

    <!-- 🎨 Custom CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        /* 🎨 MFA Verify specific styles */
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .mfa-container {
            max-width: 500px;
            width: 100%;
            padding: 2rem;
        }

        .mfa-card {
            border-radius: 1rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        .mfa-icon {
            font-size: 4rem;
            color: #667eea;
            margin-bottom: 1rem;
        }

        .code-input {
            font-family: 'Courier New', monospace;
            font-size: 2rem;
            letter-spacing: 0.5em;
            text-align: center;
            padding: 1rem;
        }

        .method-divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 2rem 0;
        }

        .method-divider::before,
        .method-divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #dee2e6;
        }

        .method-divider span {
            padding: 0 1rem;
            color: #6c757d;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>

<div class="mfa-container">
    <div class="card mfa-card">
        <div class="card-body p-5">

            <!-- 🔐 Header -->
            <div class="text-center mb-4">
                <i class="fas fa-shield-alt mfa-icon"></i>
                <h2 class="mb-2">Two-Factor Authentication</h2>
                <p class="text-muted">Enter your verification code to continue</p>
            </div>

            <!-- ✅ Email OTP Sent Message -->
            <?php if ($emailOTPSent): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    Verification code sent to your email!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- ❌ Error Message -->
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- 📱 Main Verification Form -->
            <form method="POST" id="verifyForm" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo SecurityUtils::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="verify_code">

                <div class="mb-4">
                    <label for="code" class="form-label">
                        <?php if ($hasTOTP): ?>
                            <i class="fas fa-mobile-alt me-2"></i>Authenticator Code
                        <?php else: ?>
                            <i class="fas fa-key me-2"></i>Verification Code
                        <?php endif; ?>
                    </label>
                    <input type="text" class="form-control code-input"
                           id="code" name="code" required
                           pattern="\d{6}|\w{8}" maxlength="8"
                           placeholder="000000"
                           autocomplete="off"
                           autofocus>
                    <div class="form-text text-center">
                        <?php if ($hasTOTP): ?>
                            Enter the 6-digit code from your authenticator app
                        <?php else: ?>
                            Enter your verification code
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 🍪 Trust Device Option -->
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" id="trust_device" name="trust_device" value="1">
                    <label class="form-check-label" for="trust_device">
                        <i class="fas fa-laptop me-2"></i>Trust this device for 30 days
                        <small class="text-muted d-block">Don't ask for codes on this device</small>
                    </label>
                </div>

                <!-- ✅ Submit Button -->
                <div class="d-grid gap-2 mb-3">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-check-circle me-2"></i>Verify
                    </button>
                </div>
            </form>

            <!-- 🔄 Alternative Methods -->
            <div class="method-divider">
                <span>OR</span>
            </div>

            <!-- 🎲 Backup Code Option -->
            <div class="d-grid gap-2 mb-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#backupCodeInfo">
                    <i class="fas fa-key me-2"></i>Use a Backup Code
                </button>
            </div>

            <div class="collapse" id="backupCodeInfo">
                <div class="alert alert-info mt-3">
                    <small>
                        <i class="fas fa-info-circle me-2"></i>
                        Enter one of your backup codes in the field above. Backup codes are 8 characters long.
                    </small>
                </div>
            </div>

            <!-- 📧 Email OTP Option -->
            <?php if ($hasEmailOTP || true): // Allow email OTP as fallback ?>
                <form method="POST" class="mb-2">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityUtils::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="send_email_otp">
                    <div class="d-grid">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="fas fa-envelope me-2"></i>Send Code via Email
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <!-- ❌ Cancel Login -->
            <form method="POST" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityUtils::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="cancel">
                <div class="d-grid">
                    <button type="submit" class="btn btn-link text-muted">
                        <i class="fas fa-times me-2"></i>Cancel and return to login
                    </button>
                </div>
            </form>

            <!-- 🆘 Help Link -->
            <div class="text-center mt-4">
                <small class="text-muted">
                    <a href="#" data-bs-toggle="modal" data-bs-target="#helpModal">
                        <i class="fas fa-question-circle me-1"></i>Having trouble?
                    </a>
                </small>
            </div>

        </div>
    </div>
</div>

<!-- 🆘 Help Modal -->
<div class="modal fade" id="helpModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i>Need Help?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h6><i class="fas fa-mobile-alt me-2"></i>Can't access your authenticator app?</h6>
                <p>Use one of your backup codes instead. Each backup code can only be used once.</p>

                <h6 class="mt-3"><i class="fas fa-key me-2"></i>Lost your backup codes?</h6>
                <p>Contact support for account recovery assistance.</p>

                <h6 class="mt-3"><i class="fas fa-envelope me-2"></i>Email not arriving?</h6>
                <ul>
                    <li>Check your spam/junk folder</li>
                    <li>Wait a few minutes for delivery</li>
                    <li>Make sure your email address is correct</li>
                </ul>

                <div class="alert alert-info mt-3">
                    <i class="fas fa-life-ring me-2"></i>
                    For additional help, please contact support at <strong>support@SIGNula.id</strong>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- 🔗 Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

<!-- 🎨 Custom JavaScript -->
<script>
    // 📋 Form validation
    (function() {
        'use strict';
        const forms = document.querySelectorAll('.needs-validation');
        Array.from(forms).forEach(form => {
            form.addEventListener('submit', event => {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    })();

    // 🔢 Auto-format code input
    const codeInput = document.getElementById('code');
    if (codeInput) {
        codeInput.addEventListener('input', function(e) {
            // Remove non-alphanumeric characters
            this.value = this.value.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();

            // Limit to 8 characters (backup codes) or 6 digits (TOTP)
            if (/^\d+$/.test(this.value)) {
                this.value = this.value.slice(0, 6);
            } else {
                this.value = this.value.slice(0, 8);
            }
        });

        // Auto-submit when 6 digits entered (TOTP)
        codeInput.addEventListener('input', function(e) {
            if (/^\d{6}$/.test(this.value)) {
                // Optionally auto-submit after brief delay
                setTimeout(() => {
                    if (this.value.length === 6 && /^\d{6}$/.test(this.value)) {
                        // this.form.submit(); // Uncomment to enable auto-submit
                    }
                }, 500);
            }
        });
    }
</script>

</body>
</html>
