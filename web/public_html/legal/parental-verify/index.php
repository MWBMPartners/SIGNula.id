<?php
/**
 * ============================================================================
 * 👶 SIGNula - Parental Consent Verification Landing Page (G-004 Layer 4b §5.4)
 * ============================================================================
 *
 * Purpose: The link a parent/guardian clicks from the email
 *          `AgeGateService::sendParentalConsentEmail()` sends. Reads
 *          `?consentID=` + `?token=` from the query string and calls
 *          `AgeGateService::verifyParentalConsent()` — this page performs
 *          NO verification logic itself, it only renders the result.
 *
 * ⚠️ PATH DEPTH: __DIR__ here is web/public_html/legal/parental-verify — TWO
 * levels below public_html/ (legal/ + parental-verify/), so THREE levels
 * above __DIR__ reaches web/ — identical depth/reasoning to the sibling
 * web/public_html/legal/reconsent/index.php (also legal/<slug>/index.php).
 *
 * Unlike legal/reconsent/index.php, this page is DELIBERATELY unauthenticated
 * — the person clicking this link is a parent/guardian, who does not (and
 * should not need to) hold a SIGNula session of their own.
 *
 * PHP Version: 8.3+
 *
 * @see web/private_html/compliance/AgeGateService.php (verifyParentalConsent())
 * @see web/public_html/register.php — the signup hook that starts this flow
 * @see .dev-team/specs/G-004.md §5.4
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Legal\ParentalVerify
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// 🚀 Bootstrap the application.
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

if (!class_exists('AgeGateService')) {
    require_once ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'compliance'
        . DIRECTORY_SEPARATOR . 'AgeGateService.php';
}

$consentID = (int) ($_GET['consentID'] ?? 0);
$token = (string) ($_GET['token'] ?? '');

$success = false;

// 🛡️ A single, generic outcome message regardless of WHY verification
// failed (not-found / wrong status / bad token / expired) — mirrors
// DSARManager::verifyIdentity()'s own "fail closed, uniform message"
// contract, so this page never leaks which specific reason applied.
if ($consentID > 0 && $token !== '') {
    $success = AgeGateService::verifyParentalConsent($consentID, $token);
}

$pageTitle = 'Parental Consent Verification - SIGNula';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>

    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>

<div class="auth-layout">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-card-header">
                <?php if ($success): ?>
                    <div class="auth-logo" style="color: var(--success-color);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h1>Consent Confirmed</h1>
                    <p>Thank you for confirming parental consent</p>
                <?php else: ?>
                    <div class="auth-logo" style="color: var(--danger-color);">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h1>Verification Failed</h1>
                    <p>This link is invalid or has expired</p>
                <?php endif; ?>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span>Parental consent has been verified. The associated account can now proceed.</span>
                </div>
            <?php else: ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>We couldn't verify this link. It may have already been used, may have expired, or may be incorrect. Please contact support if you believe this is an error.</span>
                </div>
            <?php endif; ?>

            <div class="text-center mt-4">
                <a href="/" class="btn-link">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>
        </div>
    </div>
</div>

</body>
</html>
