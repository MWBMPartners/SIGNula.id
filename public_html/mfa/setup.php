<?php
/**
 * ============================================================================
 * 🔐 SIGNula - MFA Setup Page
 * ============================================================================
 *
 * Purpose: Multi-Factor Authentication setup wizard
 * PHP Version: 8.3+
 *
 * Features:
 * - TOTP authenticator app setup with QR code
 * - Backup code generation
 * - Email OTP configuration
 * - Step-by-step setup process
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔐 Require authentication
Auth::requireAuth();

// 👤 Get current user data
$user = Auth::getCurrentUser();

// 📝 Handle form submissions
$success = null;
$error = null;
$step = $_GET['step'] ?? 'choose'; // choose, totp, verify, backup, complete
$totpSecret = null;
$qrUri = null;
$backupCodes = [];

// ❓ Check if MFA is already enabled
$mfaAlreadyEnabled = MFA::isEnabled($user['userID']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ Verify CSRF token
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            switch ($action) {
                case 'setup_totp':
                    // 🔑 Initialize TOTP setup
                    $result = MFA::enableTOTP($user['userID']);

                    if ($result['success']) {
                        $_SESSION['totp_secret'] = $result['secret'];
                        $_SESSION['totp_qr_uri'] = $result['qr_uri'];
                        header('Location: setup.php?step=verify');
                        exit;
                    } else {
                        $error = $result['message'];
                    }
                    break;

                case 'verify_totp':
                    // ✅ Verify TOTP code and activate
                    $code = $_POST['code'] ?? '';

                    if (empty($_SESSION['totp_secret'])) {
                        $error = 'Setup session expired. Please start again.';
                        header('Location: setup.php?step=choose');
                        exit;
                    }

                    $result = MFA::verifyAndActivateTOTP($user['userID'], $code);

                    if ($result['success']) {
                        $_SESSION['backup_codes'] = $result['backup_codes'];
                        unset($_SESSION['totp_secret'], $_SESSION['totp_qr_uri']);
                        header('Location: setup.php?step=backup');
                        exit;
                    } else {
                        $error = $result['message'];
                        $step = 'verify';
                    }
                    break;

                case 'regenerate_backup_codes':
                    // 🔄 Regenerate backup codes
                    if ($mfaAlreadyEnabled) {
                        $backupCodes = MFA::generateBackupCodes($user['userID'], true);
                        $success = 'New backup codes generated! Please save them securely.';
                        $step = 'backup';
                    } else {
                        $error = 'MFA must be enabled first.';
                    }
                    break;

                case 'setup_complete':
                    // ✅ Mark setup as complete
                    unset($_SESSION['backup_codes']);
                    header('Location: ../security.php?mfa_setup=success');
                    exit;
                    break;
            }

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            $error = 'An error occurred. Please try again.';
        }
    }
}

// 📊 Load session data for multi-step process
if ($step === 'verify' && !empty($_SESSION['totp_secret'])) {
    $totpSecret = $_SESSION['totp_secret'];
    $qrUri = $_SESSION['totp_qr_uri'];
}

if ($step === 'backup' && !empty($_SESSION['backup_codes'])) {
    $backupCodes = $_SESSION['backup_codes'];
}

// 📄 Page title
$pageTitle = 'Set Up Multi-Factor Authentication';
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
        /* 🎨 MFA Setup specific styles */
        .setup-wizard {
            max-width: 800px;
            margin: 0 auto;
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
            padding: 0;
            list-style: none;
        }

        .step-indicator li {
            flex: 1;
            text-align: center;
            position: relative;
            padding: 1rem 0.5rem;
        }

        .step-indicator li::before {
            content: '';
            position: absolute;
            top: 1.5rem;
            left: 0;
            right: 0;
            height: 2px;
            background: #dee2e6;
            z-index: -1;
        }

        .step-indicator li:first-child::before {
            left: 50%;
        }

        .step-indicator li:last-child::before {
            right: 50%;
        }

        .step-indicator .step-number {
            display: inline-block;
            width: 3rem;
            height: 3rem;
            line-height: 3rem;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .step-indicator li.active .step-number {
            background: #0d6efd;
            color: white;
        }

        .step-indicator li.completed .step-number {
            background: #198754;
            color: white;
        }

        .qr-code-section {
            background: #f8f9fa;
            border-radius: 0.5rem;
            padding: 2rem;
            text-align: center;
            margin: 2rem 0;
        }

        .backup-code {
            font-family: 'Courier New', monospace;
            font-size: 1.1rem;
            font-weight: bold;
            letter-spacing: 0.1em;
            padding: 0.5rem;
            background: #f8f9fa;
            border-radius: 0.25rem;
            margin: 0.5rem 0;
        }

        .secret-key {
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            padding: 1rem;
            background: #fff3cd;
            border: 1px dashed #ffc107;
            border-radius: 0.5rem;
            word-break: break-all;
        }

        @media print {
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>

<!-- 🔝 Navigation -->
<?php require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'navigation.php'; ?>

<main class="container my-5">
    <div class="setup-wizard">

        <!-- 📊 Step Indicator -->
        <ul class="step-indicator">
            <li class="<?php echo ($step === 'choose') ? 'active' : (in_array($step, ['verify', 'backup', 'complete']) ? 'completed' : ''); ?>">
                <div class="step-number">1</div>
                <div class="step-label">Choose Method</div>
            </li>
            <li class="<?php echo ($step === 'verify') ? 'active' : (in_array($step, ['backup', 'complete']) ? 'completed' : ''); ?>">
                <div class="step-number">2</div>
                <div class="step-label">Setup & Verify</div>
            </li>
            <li class="<?php echo ($step === 'backup') ? 'active' : ($step === 'complete' ? 'completed' : ''); ?>">
                <div class="step-number">3</div>
                <div class="step-label">Backup Codes</div>
            </li>
            <li class="<?php echo ($step === 'complete') ? 'active' : ''; ?>">
                <div class="step-number">4</div>
                <div class="step-label">Complete</div>
            </li>
        </ul>

        <!-- ✅ Success Message -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- ❌ Error Message -->
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 📋 STEP 1: Choose MFA Method -->
        <?php if ($step === 'choose'): ?>
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Choose Your MFA Method</h4>
                </div>
                <div class="card-body">
                    <p class="lead">Select how you'd like to secure your account with multi-factor authentication.</p>

                    <div class="row g-4 my-4">
                        <!-- 📱 TOTP Authenticator App -->
                        <div class="col-md-6">
                            <div class="card h-100 border-primary">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="fas fa-mobile-alt text-primary me-2"></i>
                                        Authenticator App
                                    </h5>
                                    <p class="card-text">Use an app like Google Authenticator, Microsoft Authenticator, or Authy to generate verification codes.</p>
                                    <ul class="text-muted small">
                                        <li>Works offline</li>
                                        <li>Most secure option</li>
                                        <li>Industry standard</li>
                                    </ul>

                                    <form method="POST" class="mt-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo SecurityUtils::generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="setup_totp">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="fas fa-qrcode me-2"></i>Set Up Authenticator App
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- 📧 Email OTP (Coming Soon) -->
                        <div class="col-md-6">
                            <div class="card h-100 border-secondary">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="fas fa-envelope text-secondary me-2"></i>
                                        Email Verification
                                    </h5>
                                    <p class="card-text">Receive verification codes via email to your registered email address.</p>
                                    <ul class="text-muted small">
                                        <li>Easy to use</li>
                                        <li>No app required</li>
                                        <li>Requires internet</li>
                                    </ul>

                                    <button type="button" class="btn btn-secondary w-100 mt-3" disabled>
                                        <i class="fas fa-clock me-2"></i>Coming Soon
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info mt-4">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Recommended:</strong> We strongly recommend using an authenticator app for the best security.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 📱 STEP 2: TOTP Setup & Verification -->
        <?php if ($step === 'verify' && $totpSecret && $qrUri): ?>
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="fas fa-qrcode me-2"></i>Scan QR Code</h4>
                </div>
                <div class="card-body">
                    <p class="lead">Scan this QR code with your authenticator app, then enter the 6-digit code to verify.</p>

                    <!-- 📱 QR Code Section -->
                    <div class="qr-code-section">
                        <h5 class="mb-3">Scan with your authenticator app:</h5>

                        <?php echo QRCode::generateClientSide($qrUri, 'qrcode-mfa', 256); ?>

                        <p class="text-muted mt-3 mb-0">
                            <small>Compatible with Google Authenticator, Microsoft Authenticator, Authy, and more</small>
                        </p>
                    </div>

                    <!-- 🔑 Manual Entry Option -->
                    <div class="accordion mb-4" id="manualEntryAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#manualEntry">
                                    <i class="fas fa-keyboard me-2"></i>Can't scan the QR code? Enter manually
                                </button>
                            </h2>
                            <div id="manualEntry" class="accordion-collapse collapse" data-bs-parent="#manualEntryAccordion">
                                <div class="accordion-body">
                                    <p>Enter this secret key into your authenticator app:</p>
                                    <div class="secret-key" id="secret-key">
                                        <?php echo htmlspecialchars($totpSecret); ?>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="copySecretKey()">
                                        <i class="fas fa-copy me-2"></i>Copy Secret Key
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ✅ Verification Form -->
                    <form method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityUtils::generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="verify_totp">

                        <div class="mb-3">
                            <label for="code" class="form-label">Enter the 6-digit code from your app:</label>
                            <input type="text" class="form-control form-control-lg text-center"
                                   id="code" name="code" required
                                   pattern="\d{6}" maxlength="6"
                                   placeholder="000000"
                                   autocomplete="off"
                                   autofocus
                                   style="letter-spacing: 0.5em; font-size: 2rem; font-family: 'Courier New', monospace;">
                            <div class="invalid-feedback">Please enter a valid 6-digit code.</div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-check-circle me-2"></i>Verify & Continue
                            </button>
                            <a href="setup.php?step=choose" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- 🎲 STEP 3: Backup Codes -->
        <?php if ($step === 'backup' && !empty($backupCodes)): ?>
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h4 class="mb-0"><i class="fas fa-key me-2"></i>Save Your Backup Codes</h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Important:</strong> Save these backup codes in a secure location.
                        Each code can only be used once. You won't be able to see them again.
                    </div>

                    <p class="lead">Use these codes if you lose access to your authenticator app:</p>

                    <div class="row my-4">
                        <?php foreach ($backupCodes as $index => $code): ?>
                            <div class="col-md-6 mb-2">
                                <div class="backup-code">
                                    <?php echo htmlspecialchars($code); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex gap-2 mb-4 no-print">
                        <button type="button" class="btn btn-outline-primary" onclick="printBackupCodes()">
                            <i class="fas fa-print me-2"></i>Print Codes
                        </button>
                        <button type="button" class="btn btn-outline-primary" onclick="downloadBackupCodes()">
                            <i class="fas fa-download me-2"></i>Download as Text
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="copyBackupCodes()">
                            <i class="fas fa-copy me-2"></i>Copy to Clipboard
                        </button>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityUtils::generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="setup_complete">

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirmSaved" required>
                            <label class="form-check-label" for="confirmSaved">
                                I have saved these backup codes in a secure location
                            </label>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-success btn-lg" id="completeButton" disabled>
                                <i class="fas fa-check-circle me-2"></i>Complete Setup
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

    </div>
</main>

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

    // 📋 Copy secret key
    function copySecretKey() {
        const secretKey = document.getElementById('secret-key').textContent.trim();
        navigator.clipboard.writeText(secretKey).then(() => {
            alert('Secret key copied to clipboard!');
        });
    }

    // 📋 Copy backup codes
    function copyBackupCodes() {
        const codes = Array.from(document.querySelectorAll('.backup-code'))
            .map(el => el.textContent.trim())
            .join('\n');

        navigator.clipboard.writeText('SIGNula Backup Codes\n' + '='.repeat(30) + '\n\n' + codes + '\n\nSave these codes in a secure location.').then(() => {
            alert('Backup codes copied to clipboard!');
        });
    }

    // 🖨️ Print backup codes
    function printBackupCodes() {
        window.print();
    }

    // 💾 Download backup codes
    function downloadBackupCodes() {
        const codes = Array.from(document.querySelectorAll('.backup-code'))
            .map(el => el.textContent.trim())
            .join('\n');

        const content = 'SIGNula Backup Codes\n' + '='.repeat(30) + '\n\n' + codes + '\n\nSave these codes in a secure location.\n';

        const blob = new Blob([content], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'signula-backup-codes-' + new Date().toISOString().split('T')[0] + '.txt';
        a.click();
        URL.revokeObjectURL(url);
    }

    // ✅ Enable complete button when checkbox is checked
    const confirmCheckbox = document.getElementById('confirmSaved');
    const completeButton = document.getElementById('completeButton');

    if (confirmCheckbox && completeButton) {
        confirmCheckbox.addEventListener('change', function() {
            completeButton.disabled = !this.checked;
        });
    }

    // 🔢 Auto-format verification code input
    const codeInput = document.getElementById('code');
    if (codeInput) {
        codeInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
        });

        // Auto-submit when 6 digits entered
        codeInput.addEventListener('input', function(e) {
            if (this.value.length === 6) {
                // Optional: auto-submit form
                // this.form.submit();
            }
        });
    }
</script>

</body>
</html>
