<?php
/**
 * SIGNula - Universal Single Sign-On Authentication System
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package SIGNula
 * @version 2.6.0-beta
 */

/**
 * ============================================================================
 * 🔑 SIGNula - Recovery Keys Management
 * ============================================================================
 *
 * Purpose: Manage MFA recovery/backup codes for account recovery
 * PHP Version: 8.3+
 *
 * Recovery keys are one-time-use backup codes that allow users to regain
 * access to their account if they lose access to their primary 2FA method
 * (e.g. authenticator app, security key). Since codes are stored as one-way
 * hashes, they can only be viewed at generation time.
 *
 * Flow:
 *   1. User views remaining code count (always visible)
 *   2. User clicks "Generate New Recovery Keys"
 *   3. Password confirmation modal prompts for current password
 *   4. On success, new codes are generated and displayed once
 *   5. User must save codes (copy/download/print) before dismissing
 *
 * @package    SIGNula
 * @subpackage Settings
 * @version    1.0.0
 * @link       https://SIGNula.id
 * @see        web/private_html/security/MFA.php — MFA::generateBackupCodes()
 * @see        web/private_html/security/MFA.php — MFA::getRemainingBackupCodes()
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    // 🚀 Bootstrap the application
    // @see web/_config/config.php — Application bootstrap and global function definitions
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
}

// 🔒 Require authenticated session
// @see web/_config/config.php — requireLogin() redirects unauthenticated users to /login
requireLogin();

// 📄 Page metadata
$pageTitle = 'Recovery Keys';

// 👤 Load current user data and session user ID
$user = getCurrentUser();
$userID = $_SESSION['userID'];

// 🔐 Load MFA class for backup code management
// @see web/private_html/security/MFA.php — Static class for all MFA operations
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'MFA.php';

// 📝 Feedback message variables
$message = null;
$messageType = null;

// ========================================================================
// 📬 POST REQUEST HANDLING
// ========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ Verify CSRF token on all POST requests
    // @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please try again.';
        $messageType = 'danger';
    } else {
        // 🎯 Route to appropriate action handler
        $action = $_POST['action'] ?? '';

        switch ($action) {
            // ================================================================
            // 🔄 ACTION: regenerate_keys — Generate new recovery keys
            // ================================================================
            case 'regenerate_keys':
                // 🔐 Sanitize and validate password input
                // @see web/private_html/security/SecurityUtils.php — sanitizeString()
                $password = $_POST['password'] ?? '';

                if (empty($password)) {
                    $message = 'Password is required to generate new recovery keys.';
                    $messageType = 'danger';
                    break;
                }

                // 🔒 Verify current password against stored Argon2id hash
                // @see https://www.php.net/manual/en/function.password-verify.php
                if (!password_verify($password, $user['passwordHash'])) {
                    // 📊 Log failed password verification attempt for security auditing
                    ActivityLogger::log(
                        $userID,
                        'recovery_keys_password_failed',
                        'Failed password verification when attempting to regenerate recovery keys',
                        [
                            'ipAddress' => getClientIP(),
                            'userAgent' => getUserAgent()
                        ]
                    );

                    $message = 'Incorrect password. Please try again.';
                    $messageType = 'danger';
                    break;
                }

                // ✅ Check that MFA is enabled before generating recovery keys
                // Recovery keys are meaningless without an active 2FA method
                $mfaEnabled = !empty($user['mfaEnabled']) && $user['mfaEnabled'] == 1;

                if (!$mfaEnabled) {
                    $message = 'Two-factor authentication must be enabled before generating recovery keys.';
                    $messageType = 'warning';
                    break;
                }

                try {
                    // 🎲 Generate new backup codes (replaces any existing codes)
                    // @see web/private_html/security/MFA.php — MFA::generateBackupCodes()
                    // The $regenerate=true flag deletes all existing codes before creating new ones
                    $newCodes = MFA::generateBackupCodes($userID, true);

                    // 💾 Store raw codes temporarily in session for one-time display
                    // These are the ONLY time the user can see the plain-text codes,
                    // since they are stored as one-way Argon2id hashes in the database
                    $_SESSION['recovery_keys_display'] = $newCodes;
                    $_SESSION['recovery_keys_generated_at'] = time();

                    // 📊 Log successful regeneration for security auditing
                    ActivityLogger::log(
                        $userID,
                        'recovery_keys_regenerated',
                        'Recovery keys regenerated successfully. Previous keys invalidated.',
                        [
                            'ipAddress' => getClientIP(),
                            'userAgent' => getUserAgent(),
                            'codesGenerated' => count($newCodes)
                        ]
                    );

                    // 📧 Send security notification email if NotificationService is available
                    // @see web/private_html/email/EmailService.php — sendSecurityAlertEmail()
                    if (class_exists('EmailService') && method_exists('EmailService', 'sendSecurityAlertEmail')) {
                        EmailService::sendSecurityAlertEmail(
                            $user['email'],
                            'Recovery Keys Regenerated',
                            'Your SIGNula recovery keys have been regenerated. All previous recovery keys have been invalidated. If you did not make this change, please secure your account immediately.',
                            [
                                'ipAddress' => getClientIP(),
                                'userAgent' => getUserAgent(),
                                'timestamp' => date('Y-m-d H:i:s T')
                            ]
                        );
                    }

                    $message = 'New recovery keys have been generated. Please save them now — you will not be able to view them again.';
                    $messageType = 'success';

                } catch (Exception $e) {
                    // 🚨 Log error for debugging
                    // @see web/private_html/logging/ErrorLogger.php — logError()
                    if (class_exists('ErrorLogger')) {
                        ErrorLogger::logError(
                            'recovery_keys_generation_failed',
                            $e->getMessage(),
                            $e->getFile(),
                            $e->getLine(),
                            $e->getTraceAsString()
                        );
                    }

                    $message = 'An error occurred while generating recovery keys. Please try again.';
                    $messageType = 'danger';
                }
                break;

            // ================================================================
            // ✅ ACTION: codes_saved — User confirms they saved their codes
            // ================================================================
            case 'codes_saved':
                // 🧹 Clear the temporary codes from session
                unset($_SESSION['recovery_keys_display']);
                unset($_SESSION['recovery_keys_generated_at']);

                $message = 'Recovery keys have been saved. Keep them in a secure location.';
                $messageType = 'info';
                break;

            // ================================================================
            // ❓ Unknown action
            // ================================================================
            default:
                $message = 'Unknown action requested.';
                $messageType = 'warning';
                break;
        }
    }
}

// ========================================================================
// 📊 PAGE DATA PREPARATION
// ========================================================================

// 🎫 Generate CSRF token for forms
// @see web/private_html/security/SecurityUtils.php — generateCSRFToken()
$csrfToken = SecurityUtils::generateCSRFToken();

// 📱 Check if MFA (2FA) is currently enabled for this user
$mfaEnabled = !empty($user['mfaEnabled']) && $user['mfaEnabled'] == 1;

// 🔢 Get count of remaining unused recovery codes
// @see web/private_html/security/MFA.php — MFA::getRemainingBackupCodes()
$remainingCodes = MFA::getRemainingBackupCodes($userID);

// 🔑 Check if codes are available for display (just generated)
$displayCodes = $_SESSION['recovery_keys_display'] ?? null;
$codesGeneratedAt = $_SESSION['recovery_keys_generated_at'] ?? null;

// ⏰ Auto-expire displayed codes after 15 minutes for security
// If the user has had codes in session for over 15 minutes, clear them
if ($displayCodes !== null && $codesGeneratedAt !== null) {
    $codeDisplayExpiry = (int) getSetting('security.recovery_keys.display_expiry', '900');
    if ((time() - $codesGeneratedAt) > $codeDisplayExpiry) {
        unset($_SESSION['recovery_keys_display']);
        unset($_SESSION['recovery_keys_generated_at']);
        $displayCodes = null;
        $codesGeneratedAt = null;
    }
}

// 🎨 Include header layout
// @see web/private_html/layout/header.php — HTML <head>, navbar, opening <body>
include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🖨️ Print-specific styles: hide non-essential elements when printing recovery codes -->
<style>
    @media print {
        /* 🚫 Hide navigation, sidebar, buttons, and other non-essential UI during print */
        .no-print,
        .navbar,
        .settings-sidebar,
        .alert,
        .modal,
        .modal-backdrop {
            display: none !important;
        }

        /* 📄 Ensure recovery codes grid does not break across pages */
        .recovery-codes-grid {
            break-inside: avoid;
        }

        /* 🖨️ Clean print layout */
        .print-header {
            display: block !important;
            text-align: center;
            margin-bottom: 1rem;
        }

        /* 📐 Adjust container width for print */
        .col-md-9 {
            width: 100% !important;
            max-width: 100% !important;
        }

        body {
            font-size: 12pt;
        }
    }

    /* 🖥️ Hide print-only header on screen */
    @media screen {
        .print-header {
            display: none !important;
        }
    }

    /* 🔑 Recovery code styling — monospace for clarity */
    .recovery-code {
        font-family: 'Courier New', Courier, monospace;
        font-size: 1.1rem;
        font-weight: 600;
        letter-spacing: 0.15em;
        padding: 0.5rem 0.75rem;
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
        text-align: center;
        user-select: all;
        transition: background-color 0.2s, border-color 0.2s;
    }

    /* ✨ Hover effect for individual code blocks */
    .recovery-code:hover {
        background-color: #e9ecef;
        border-color: #adb5bd;
        cursor: pointer;
    }

    /* 🎯 Focus/selected state */
    .recovery-code:focus {
        outline: 2px solid #007bff;
        outline-offset: 2px;
    }

    /* ⚠️ Warning banner for code display */
    .codes-warning-banner {
        background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%);
        border-left: 4px solid #ffc107;
        padding: 1rem 1.25rem;
        border-radius: 0.375rem;
        margin-bottom: 1.5rem;
    }

    /* 📊 Status indicator badges */
    .status-indicator {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.25rem 0.625rem;
        border-radius: 1rem;
        font-size: 0.875rem;
        font-weight: 500;
    }

    .status-indicator.healthy {
        background-color: #d1e7dd;
        color: #0f5132;
    }

    .status-indicator.warning {
        background-color: #fff3cd;
        color: #664d03;
    }

    .status-indicator.critical {
        background-color: #f8d7da;
        color: #842029;
    }

    /* 🌙 Dark mode support via CSS custom properties */
    @media (prefers-color-scheme: dark) {
        .recovery-code {
            background-color: #2b3035;
            border-color: #495057;
            color: #e9ecef;
        }

        .recovery-code:hover {
            background-color: #343a40;
            border-color: #6c757d;
        }

        .codes-warning-banner {
            background: linear-gradient(135deg, #332701 0%, #3d2e00 100%);
            border-left-color: #ffc107;
            color: #ffeeba;
        }
    }
</style>

<div class="container mt-5">
    <div class="row">
        <!-- 📋 Settings Sidebar (col-md-3) -->
        <div class="col-md-3 mb-4 settings-sidebar no-print">
            <?php
            // @see web/private_html/layout/settings-sidebar.php — Shared settings navigation
            include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'settings-sidebar.php';
            ?>
        </div>

        <!-- 📄 Main Content (col-md-9) -->
        <div class="col-md-9">

            <!-- 🖨️ Print-only header (visible only when printing) -->
            <div class="print-header">
                <h2>SIGNula Recovery Keys</h2>
                <p>Generated: <?php echo date('F j, Y \a\t g:i A T'); ?></p>
                <p><strong>Keep these codes in a safe place. Each code can only be used once.</strong></p>
                <hr>
            </div>

            <!-- 💬 Flash message / feedback alert -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show no-print" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- ================================================================ -->
            <!-- 📘 INFO CARD — What are recovery keys?                           -->
            <!-- ================================================================ -->
            <div class="card shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="d-flex align-items-start">
                        <div class="me-3 fs-3">🔑</div>
                        <div class="flex-grow-1">
                            <h5 class="card-title mb-2">Recovery Keys</h5>
                            <p class="text-muted mb-3">
                                Recovery keys are one-time-use backup codes that allow you to access your account
                                if you lose access to your authenticator app or other two-factor authentication methods.
                                Each code can only be used once, and generating new codes will invalidate all existing ones.
                            </p>

                            <?php if ($mfaEnabled): ?>
                                <!-- 📊 Remaining codes status indicator -->
                                <div class="d-flex align-items-center gap-3">
                                    <span class="fw-semibold">Remaining codes:</span>

                                    <?php if ($remainingCodes >= 5): ?>
                                        <!-- ✅ Healthy: 5 or more codes remaining -->
                                        <span class="status-indicator healthy">
                                            <span>✅</span>
                                            <?php echo (int) $remainingCodes; ?> of 10 available
                                        </span>
                                    <?php elseif ($remainingCodes >= 3): ?>
                                        <!-- ⚠️ Warning: fewer than 5 codes -->
                                        <span class="status-indicator warning">
                                            <span>⚠️</span>
                                            <?php echo (int) $remainingCodes; ?> of 10 available
                                        </span>
                                    <?php elseif ($remainingCodes > 0): ?>
                                        <!-- 🚨 Critical: fewer than 3 codes -->
                                        <span class="status-indicator critical">
                                            <span>🚨</span>
                                            <?php echo (int) $remainingCodes; ?> of 10 available — generate new codes soon!
                                        </span>
                                    <?php else: ?>
                                        <!-- ❌ No codes available -->
                                        <span class="status-indicator critical">
                                            <span>❌</span>
                                            No recovery codes available
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <!-- 🔒 MFA not enabled — recovery keys require 2FA -->
                                <div class="alert alert-warning mb-0">
                                    <strong>📱 Two-factor authentication required</strong><br>
                                    Recovery keys are only available when two-factor authentication (2FA) is enabled.
                                    <a href="/settings/mfa" class="alert-link">Enable 2FA now</a> to generate recovery keys.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($displayCodes !== null && is_array($displayCodes) && count($displayCodes) > 0): ?>
                <!-- ================================================================ -->
                <!-- 🔑 DISPLAY GENERATED CODES — Shown only once after generation    -->
                <!-- ================================================================ -->

                <!-- ⚠️ Warning banner: codes are one-time viewable -->
                <div class="codes-warning-banner no-print">
                    <div class="d-flex align-items-start">
                        <span class="fs-4 me-3">⚠️</span>
                        <div>
                            <strong>Save these codes now!</strong><br>
                            <span class="small">
                                You will not be able to view these recovery codes again. Store them securely — for example,
                                in a password manager, a printed document in a safe location, or an encrypted file.
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 🔑 Recovery codes grid (2 columns x 5 rows) -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h5 class="card-title mb-3">🔑 Your New Recovery Keys</h5>

                        <div class="recovery-codes-grid">
                            <div class="row g-3 mb-4">
                                <?php
                                // 📋 Render codes in a 2-column grid layout
                                // Each code is displayed in a monospace font block for clarity
                                foreach ($displayCodes as $index => $code):
                                    // 📐 Determine column: first 5 codes in left column, next 5 in right
                                ?>
                                    <div class="col-sm-6">
                                        <div class="d-flex align-items-center gap-2">
                                            <!-- 🔢 Code index number for easy reference -->
                                            <span class="text-muted fw-semibold" style="min-width: 1.5rem;">
                                                <?php echo ($index + 1); ?>.
                                            </span>
                                            <!-- 🔑 The recovery code itself -->
                                            <div
                                                class="recovery-code flex-grow-1"
                                                id="code-<?php echo $index; ?>"
                                                tabindex="0"
                                                role="button"
                                                aria-label="Recovery code <?php echo ($index + 1); ?>: <?php echo htmlspecialchars($code); ?>"
                                                title="Click to select this code"
                                            >
                                                <?php echo htmlspecialchars($code); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- 🛠️ Action buttons: Copy, Download, Print -->
                        <div class="d-flex flex-wrap gap-2 mb-4 no-print">
                            <!-- 📋 Copy All to clipboard -->
                            <button
                                type="button"
                                class="btn btn-outline-primary"
                                onclick="copyAllCodes()"
                                id="btnCopyAll"
                                aria-label="Copy all recovery codes to clipboard"
                            >
                                <span class="me-1">📋</span> Copy All
                            </button>

                            <!-- 💾 Download as TXT file -->
                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                onclick="downloadCodes()"
                                aria-label="Download recovery codes as text file"
                            >
                                <span class="me-1">💾</span> Download as TXT
                            </button>

                            <!-- 🖨️ Print codes -->
                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                onclick="printCodes()"
                                aria-label="Print recovery codes"
                            >
                                <span class="me-1">🖨️</span> Print
                            </button>
                        </div>

                        <!-- ✅ "I've saved my codes" confirmation form -->
                        <form method="POST" action="" class="no-print">
                            <input type="hidden" name="action" value="codes_saved">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <button type="submit" class="btn btn-success btn-lg w-100">
                                ✅ I've Saved My Recovery Keys
                            </button>
                        </form>
                    </div>
                </div>

            <?php elseif ($mfaEnabled): ?>
                <!-- ================================================================ -->
                <!-- 🔄 NORMAL VIEW — Generate/regenerate recovery keys              -->
                <!-- ================================================================ -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h5 class="card-title mb-3">🔄 Generate Recovery Keys</h5>

                        <?php if ($remainingCodes > 0): ?>
                            <!-- ℹ️ Existing codes warning -->
                            <div class="alert alert-info">
                                <strong>ℹ️ You have <?php echo (int) $remainingCodes; ?> recovery key(s) remaining.</strong><br>
                                <span class="small">
                                    Generating new recovery keys will <strong>invalidate all existing keys</strong>.
                                    Only do this if you have lost your current keys or have used most of them.
                                </span>
                            </div>
                        <?php else: ?>
                            <!-- 🚨 No codes available -->
                            <div class="alert alert-danger">
                                <strong>🚨 No recovery keys available.</strong><br>
                                <span class="small">
                                    You currently have no recovery keys. If you lose access to your authenticator app,
                                    you may be locked out of your account. Generate new recovery keys now.
                                </span>
                            </div>
                        <?php endif; ?>

                        <p class="text-muted mb-3">
                            When you generate new recovery keys, you will receive 10 one-time-use codes.
                            Each code can be used once in place of your regular two-factor authentication code.
                        </p>

                        <!-- 🔘 Trigger password confirmation modal -->
                        <button
                            type="button"
                            class="btn btn-<?php echo ($remainingCodes === 0) ? 'danger' : 'primary'; ?>"
                            data-bs-toggle="modal"
                            data-bs-target="#passwordConfirmModal"
                        >
                            🔑 <?php echo ($remainingCodes > 0) ? 'Generate New Recovery Keys' : 'Generate Recovery Keys'; ?>
                        </button>
                    </div>
                </div>

                <!-- 📚 Best practices card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h5 class="card-title mb-3">💡 Best Practices</h5>
                        <ul class="mb-0">
                            <li class="mb-2">
                                <strong>Store codes securely</strong> — Use a password manager, a printed copy in a safe,
                                or an encrypted file on a secure device.
                            </li>
                            <li class="mb-2">
                                <strong>Do not share your codes</strong> — Recovery keys are as powerful as your password.
                                Never share them with anyone, including SIGNula support.
                            </li>
                            <li class="mb-2">
                                <strong>Regenerate when running low</strong> — If you have fewer than 3 codes remaining,
                                consider generating a new set.
                            </li>
                            <li class="mb-0">
                                <strong>Keep your authenticator app updated</strong> — Recovery keys are a last resort.
                                Ensure your primary 2FA method is always accessible.
                            </li>
                        </ul>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php if ($mfaEnabled && $displayCodes === null): ?>
    <!-- ================================================================ -->
    <!-- 🔐 PASSWORD CONFIRMATION MODAL                                   -->
    <!-- ================================================================ -->
    <!-- Bootstrap 5 modal for password verification before generating    -->
    <!-- new recovery keys. Requires current password to prevent          -->
    <!-- unauthorized regeneration by session hijackers.                   -->
    <!-- @see https://getbootstrap.com/docs/5.3/components/modal/         -->
    <!-- ================================================================ -->
    <div
        class="modal fade"
        id="passwordConfirmModal"
        tabindex="-1"
        aria-labelledby="passwordConfirmModalLabel"
        aria-hidden="true"
        data-bs-backdrop="static"
        data-bs-keyboard="false"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="" id="regenerateKeysForm">
                    <!-- 🔒 Hidden fields: action type and CSRF protection -->
                    <input type="hidden" name="action" value="regenerate_keys">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                    <!-- 📋 Modal header -->
                    <div class="modal-header">
                        <h5 class="modal-title" id="passwordConfirmModalLabel">
                            🔐 Confirm Your Password
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <!-- 📝 Modal body: password input -->
                    <div class="modal-body">
                        <p class="text-muted mb-3">
                            For your security, please enter your current password to generate new recovery keys.
                        </p>

                        <?php if ($remainingCodes > 0): ?>
                            <!-- ⚠️ Warn that existing codes will be invalidated -->
                            <div class="alert alert-warning small mb-3">
                                <strong>⚠️ Warning:</strong> Generating new recovery keys will
                                <strong>permanently invalidate</strong> your existing
                                <?php echo (int) $remainingCodes; ?> recovery key(s).
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="modalPassword" class="form-label">Current Password</label>
                            <input
                                type="password"
                                class="form-control"
                                id="modalPassword"
                                name="password"
                                required
                                autocomplete="current-password"
                                placeholder="Enter your current password"
                                aria-describedby="passwordHelpText"
                            >
                            <div id="passwordHelpText" class="form-text">
                                Your password is required to verify your identity.
                            </div>
                        </div>
                    </div>

                    <!-- 🔘 Modal footer: action buttons -->
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" id="btnConfirmRegenerate">
                            🔑 Generate New Keys
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($displayCodes !== null && is_array($displayCodes) && count($displayCodes) > 0): ?>
    <!-- ================================================================ -->
    <!-- 📜 JavaScript: Code management utilities                         -->
    <!-- ================================================================ -->
    <script>
    /**
     * 📋 Copy all recovery codes to the clipboard
     *
     * Uses the modern Clipboard API (navigator.clipboard.writeText) with
     * a fallback to the legacy document.execCommand('copy') method for
     * older browsers.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Clipboard/writeText
     */
    function copyAllCodes() {
        // 🔑 Collect all recovery codes from the DOM
        var codes = <?php echo json_encode(array_values($displayCodes), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        // 📝 Format codes with numbered list
        var formattedCodes = codes.map(function(code, index) {
            return (index + 1) + '. ' + code;
        }).join('\n');

        var textToCopy = 'SIGNula Recovery Keys\n'
            + 'Generated: ' + new Date().toLocaleString() + '\n\n'
            + formattedCodes + '\n\n'
            + 'Each code can only be used once.\n'
            + 'Keep these codes in a secure location.';

        // 📋 Attempt modern Clipboard API first
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(textToCopy).then(function() {
                showCopySuccess();
            }).catch(function() {
                // 🔄 Fallback to legacy method
                fallbackCopy(textToCopy);
            });
        } else {
            // 🔄 Use fallback for non-HTTPS or older browsers
            fallbackCopy(textToCopy);
        }
    }

    /**
     * 🔄 Fallback copy method using temporary textarea
     *
     * Creates a hidden textarea element, selects its content, and uses
     * the deprecated document.execCommand('copy') for browsers that do
     * not support the Clipboard API.
     *
     * @param {string} text — The text to copy to clipboard
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Document/execCommand
     */
    function fallbackCopy(text) {
        var textArea = document.createElement('textarea');
        textArea.value = text;

        // 🙈 Position off-screen to avoid visual disruption
        textArea.style.position = 'fixed';
        textArea.style.left = '-9999px';
        textArea.style.top = '-9999px';
        textArea.style.opacity = '0';

        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            document.execCommand('copy');
            showCopySuccess();
        } catch (err) {
            // 🚨 Alert user if copy failed entirely
            alert('Unable to copy codes. Please select and copy them manually.');
        }

        document.body.removeChild(textArea);
    }

    /**
     * ✅ Show copy success feedback
     *
     * Temporarily updates the "Copy All" button text and style to indicate
     * a successful clipboard copy operation.
     */
    function showCopySuccess() {
        var btn = document.getElementById('btnCopyAll');
        var originalHTML = btn.innerHTML;

        // ✅ Update button to show success state
        btn.innerHTML = '<span class="me-1">✅</span> Copied!';
        btn.classList.remove('btn-outline-primary');
        btn.classList.add('btn-success');

        // ⏱️ Revert button after 2 seconds
        setTimeout(function() {
            btn.innerHTML = originalHTML;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-primary');
        }, 2000);
    }

    /**
     * 💾 Download recovery codes as a .txt file
     *
     * Generates a plain text file containing all recovery codes with
     * metadata, and triggers a browser download using a Blob URL.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Blob
     * @see https://developer.mozilla.org/en-US/docs/Web/API/URL/createObjectURL
     */
    function downloadCodes() {
        var codes = <?php echo json_encode(array_values($displayCodes), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        // 📝 Build file content with header and numbered codes
        var content = '================================================================\n'
            + '  SIGNula Recovery Keys\n'
            + '================================================================\n\n'
            + '  Generated: ' + new Date().toLocaleString() + '\n'
            + '  Account:   <?php echo htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>\n\n'
            + '  IMPORTANT: Each code can only be used once.\n'
            + '  Keep these codes in a secure location.\n'
            + '  Do NOT share these codes with anyone.\n\n'
            + '================================================================\n\n';

        // 🔢 Append numbered codes
        codes.forEach(function(code, index) {
            var num = (index + 1).toString().padStart(2, ' ');
            content += '  ' + num + '.  ' + code + '\n';
        });

        content += '\n================================================================\n'
            + '  https://SIGNula.id — Universal Authentication\n'
            + '================================================================\n';

        // 📦 Create downloadable Blob and trigger download
        var blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
        var url = URL.createObjectURL(blob);

        var link = document.createElement('a');
        link.href = url;
        link.download = 'signula-recovery-keys-' + new Date().toISOString().slice(0, 10) + '.txt';

        // 🖱️ Programmatically click the link to trigger download
        document.body.appendChild(link);
        link.click();

        // 🧹 Clean up
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    /**
     * 🖨️ Print recovery codes
     *
     * Triggers the browser's native print dialog. The @media print CSS
     * rules hide navigation and non-essential UI elements, showing only
     * the recovery codes and print header.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Window/print
     */
    function printCodes() {
        window.print();
    }
    </script>
<?php endif; ?>

<?php if ($mfaEnabled && $displayCodes === null): ?>
    <!-- ================================================================ -->
    <!-- 📜 JavaScript: Modal interaction handling                        -->
    <!-- ================================================================ -->
    <script>
    /**
     * 🎯 Modal event handling
     *
     * Auto-focus the password field when the modal is shown, and prevent
     * double-submission of the regeneration form.
     */
    document.addEventListener('DOMContentLoaded', function() {
        // 📱 Get modal element reference
        var modal = document.getElementById('passwordConfirmModal');

        if (modal) {
            // 🎯 Auto-focus password field when modal opens
            // @see https://getbootstrap.com/docs/5.3/components/modal/#events
            modal.addEventListener('shown.bs.modal', function() {
                var passwordField = document.getElementById('modalPassword');
                if (passwordField) {
                    passwordField.focus();
                }
            });

            // 🧹 Clear password field when modal is closed
            modal.addEventListener('hidden.bs.modal', function() {
                var passwordField = document.getElementById('modalPassword');
                if (passwordField) {
                    passwordField.value = '';
                }
            });
        }

        // 🛡️ Prevent double-submission of the regeneration form
        var form = document.getElementById('regenerateKeysForm');
        if (form) {
            form.addEventListener('submit', function() {
                var btn = document.getElementById('btnConfirmRegenerate');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Generating...';
                }
            });
        }
    });
    </script>
<?php endif; ?>

<?php
// 🎨 Include footer layout
// @see web/private_html/layout/footer.php — Closing </body>, scripts, footer HTML
include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'footer.php';
?>
