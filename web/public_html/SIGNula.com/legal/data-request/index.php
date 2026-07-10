<?php
/**
 * ============================================================================
 * 📮 SIGNula - Public Data Request Form (G-004 Layer 1 / Cycle C)
 * ============================================================================
 *
 * Purpose: Pre-authentication "I can't log in" channel for submitting a
 *          data-subject request (access / portability / erasure /
 *          rectification). Protected by SecurityMiddleware rate-limiting +
 *          FormProtection + CAPTCHA, exactly like SIGNula.com/contact.php.
 *
 * 🔒 THE MOST IMPORTANT PROPERTY OF THIS FILE — anti-enumeration:
 * Whether or not the submitted email matches an account, this page renders
 * the EXACT SAME success message, the EXACT SAME HTTP status, with NO
 * observable timing/field/status divergence. This is enforced by
 * CONSTRUCTION, not just careful wording: the actual lookup + submission
 * logic lives entirely inside DataRequestIntake::process() (a void method
 * that never throws and never signals whether a match was found), and this
 * page ALWAYS renders DataRequestIntake::UNIFORM_SUCCESS_MESSAGE after
 * calling it — there is no branch in this file that could render anything
 * else. See web/private_html/compliance/DataRequestIntake.php for the full
 * design contract and its own test coverage of this property.
 *
 * PHP Version: 8.3+
 *
 * @see web/private_html/compliance/DataRequestIntake.php — the anti-enumeration seam
 * @see web/private_html/compliance/DSARManager.php — the DSAR tracker DataRequestIntake delegates to
 * @see web/public_html/SIGNula.com/contact.php — the form/security-middleware template this mirrors
 * @see web/public_html/SIGNula.com/legal/data-request/verify.php — the identity-verification landing page
 * @see .dev-team/specs/G-004.md §2.6, §6, risk #4
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Marketing\Legal\DataRequest
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// 🚀 Bootstrap the application (provides session, CSRF, security middleware, etc.)
// ⚠️ PATH DEPTH: __DIR__ here is web/public_html/SIGNula.com/legal/data-request
// — FOUR levels below web/ (public_html -> SIGNula.com -> legal -> data-request).
// contact.php (directly inside SIGNula.com/) only needs dirname(__DIR__, 2);
// THIS file is two directories deeper (legal/ + data-request/), so it needs
// dirname(__DIR__, 4). Verified at build time — see the build report for the
// literal resolved path proof.
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📚 The anti-enumeration seam — NOT covered by config.php's autoload dirs
// (private_html/compliance/ is outside the auto|security|utils|api|api-controllers|email|database list).
if (!class_exists('DataRequestIntake')) {
    require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'DataRequestIntake.php';
}

// Page configuration
$pageTitle = 'Request Your Data - SIGNula';
$metaDescription = 'Submit a data access, portability, erasure, or rectification request for your SIGNula account.';
$currentPage = 'data-request';

// Form state variables
$success = false;
$error = null;
$formData = [
    'requestType' => '',
    'email' => '',
    'details' => '',
];

// 🔒 The ONLY requestType values this public form may submit — mirrors
// DataRequestIntake::VALID_REQUEST_TYPES exactly (the class is the
// authoritative allowlist; this local copy exists only to build <option> tags).
$REQUEST_TYPE_LABELS = [
    'access'        => 'Access my data (see what SIGNula holds about me)',
    'portability'   => 'Portability (download a machine-readable copy)',
    'erasure'       => 'Erasure (delete my account and data)',
    'rectification' => 'Rectification (correct inaccurate information)',
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ Security middleware — rate limiting + CAPTCHA verification
    $securityCheck = SecurityMiddleware::handleFormSubmission('data_request');
    if (!$securityCheck['allowed']) {
        $error = $securityCheck['error'];
    } elseif (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        // 🛡️ Verify CSRF token
        $error = 'Invalid security token. Please try again.';
    } else {
        // 🧼 Sanitise inputs
        $formData['requestType'] = SecurityUtils::sanitizeString($_POST['requestType'] ?? '');
        $formData['email'] = SecurityUtils::sanitizeEmail($_POST['email'] ?? '') ?: '';
        $formData['details'] = SecurityUtils::sanitizeString($_POST['details'] ?? '');

        // ✅ Validate FORMAT only — these checks reveal nothing about whether
        // an account exists (empty/invalid email, missing/unrecognised
        // requestType are pure input-shape problems, not an enumeration risk).
        $errors = [];

        if (empty($formData['email']) || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (!DataRequestIntake::isValidRequestType($formData['requestType'])) {
            $errors[] = 'Please select a request type.';
        }

        if (!empty($errors)) {
            $error = implode(' ', $errors);
        } else {
            // ================================================================
            // 🔒 ANTI-ENUMERATION CRITICAL SECTION
            // ================================================================
            // DataRequestIntake::process() ALWAYS returns void and NEVER
            // throws (every internal failure — no matching account, DSARManager
            // rejecting the submission, the email queue failing — is swallowed
            // inside that method). This `else` branch therefore has EXACTLY
            // ONE possible outcome below: $success = true with the SAME
            // uniform message, unconditionally. There is no code path in this
            // file that renders a different result depending on whether the
            // email matched an account.
            DataRequestIntake::process($formData['email'], $formData['requestType'], $formData['details']);

            $success = true;

            // Clear form data on success
            $formData = ['requestType' => '', 'email' => '', 'details' => ''];

            // Regenerate CSRF token
            SecurityUtils::generateCSRFToken();
        }
    }
}

// Include header component
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>

<!-- ========================================================================
     HERO SECTION
     ======================================================================== -->
<section class="hero-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10 text-center">
                <h1 class="hero-title">Request Your Data</h1>
                <p class="hero-subtitle">
                    Can't sign in, or acting on behalf of someone who can't? Use this form to request access,
                    a copy, correction, or erasure of your data.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     DATA REQUEST FORM SECTION
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-md-5">

                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i>
                                <?php echo htmlspecialchars(\DataRequestIntake::UNIFORM_SUCCESS_MESSAGE, ENT_QUOTES, 'UTF-8'); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Error:</strong> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (!$success): ?>
                        <form method="POST" action="" id="dataRequestForm">
                            <!-- CSRF Token -->
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">

                            <!-- 🛡️ Local Form Protection (honeypot + timing + JS challenge) -->
                            <?php echo FormProtection::renderAllFields(); ?>

                            <!-- Request Type -->
                            <div class="mb-4">
                                <label for="requestType" class="form-label">What would you like to request? <span class="text-danger">*</span></label>
                                <select class="form-select" id="requestType" name="requestType" required>
                                    <option value="">Choose a request type&hellip;</option>
                                    <?php foreach ($REQUEST_TYPE_LABELS as $typeKey => $typeLabel): ?>
                                    <option value="<?php echo htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo $formData['requestType'] === $typeKey ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Email -->
                            <div class="mb-4">
                                <label for="email" class="form-label">Email Address On The Account <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?php echo htmlspecialchars($formData['email'], ENT_QUOTES, 'UTF-8'); ?>"
                                       required maxlength="255"
                                       placeholder="you@example.com">
                                <div class="form-text">We'll email a verification link to this address before acting on your request.</div>
                            </div>

                            <!-- Details -->
                            <div class="mb-4">
                                <label for="details" class="form-label">Additional Details <small class="text-muted">(optional)</small></label>
                                <textarea class="form-control" id="details" name="details" rows="4"
                                          maxlength="2000"
                                          placeholder="Anything that will help us process your request..."><?php echo htmlspecialchars($formData['details'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>

                            <!-- 🛡️ CAPTCHA Widget (if enabled) -->
                            <?php echo CaptchaVerifier::renderWidget('dataRequestForm'); ?>

                            <!-- Submit Button -->
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-paper-plane me-2"></i>Submit Request
                                </button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="text-center">
                            <a href="/legal/data-request" class="btn btn-outline-primary">
                                <i class="fas fa-plus me-2"></i>Submit Another Request
                            </a>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>

                <p class="text-muted small mt-4 text-center">
                    Already able to sign in? Manage these requests directly from your account under
                    <strong>Settings &rarr; Privacy</strong> instead — no waiting for email verification required.
                </p>
            </div>
        </div>
    </div>
</section>

<?php
// Include footer component
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
