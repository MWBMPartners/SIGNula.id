<?php
/**
 * ============================================================================
 * 🔄 SIGNula - Versioned Re-Consent Interstitial (G-004 Layer 2)
 * ============================================================================
 *
 * Purpose: Shown between a successful login and the user's intended
 *          destination when their recorded 'terms'/'privacy' consent
 *          policyVersion no longer matches the current version anchor —
 *          ONLY when consent.reconsent.enforce is truthy (default OFF).
 *
 * ⚠️ DELIBERATE DEVIATION FROM THE SPEC'S SUGGESTED PATH: the spec text
 * suggested `web/public_html/SIGNula.com/legal/reconsent/index.php "or
 * similar"`. This page is placed on the SIGNula.id (application) domain
 * instead — alongside login.php, mfa/verify.php and settings/* — because it
 * REQUIRES an authenticated session (requireLogin() below) and is ONLY ever
 * reached via a same-domain redirect from login.php/mfa/verify.php. Per
 * .claude/CLAUDE.md's domain split (SIGNula.com = public marketing "shop
 * window"; SIGNula.id = day-to-day sign-in/account-management), the PHP
 * session cookie is scoped to the id domain, so a page requiring that
 * session cannot correctly live under the SIGNula.com/ marketing subtree —
 * "or similar" is exercised in favour of correctness here.
 *
 * PHP Version: 8.3+
 *
 * @see web/public_html/login.php — the post-authentication gate that redirects here
 * @see web/public_html/mfa/verify.php — the post-MFA gate that redirects here
 * @see web/private_html/compliance/ConsentCategoryService.php (maybeReconsentRedirect(), userNeedsReconsent())
 * @see .dev-team/specs/G-004.md §3.2
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Legal\Reconsent
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// 🚀 Bootstrap the application.
// __DIR__ = web/public_html/legal/reconsent — TWO levels below public_html/
// (legal/ + reconsent/), so THREE levels above __DIR__ reaches web/ — mirrors
// settings/privacy.php's dirname(__DIR__, 2) plus one extra level for the
// added 'legal' nesting (same reasoning as api/v1/consent/index.php's own
// path-depth note).
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔒 Must be authenticated — this page only exists as a post-login gate.
requireLogin();

if (!class_exists('ConsentCategoryService')) {
    require_once ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'compliance'
        . DIRECTORY_SEPARATOR . 'ConsentCategoryService.php';
}
if (!class_exists('ConsentManager')) {
    require_once ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'compliance'
        . DIRECTORY_SEPARATOR . 'ConsentManager.php';
}

// 🐛 Mirrors settings/privacy.php's own convention — Auth::completeLogin()
// sets BOTH $_SESSION['user_id'] (canonical) and $_SESSION['userID'] (the
// compat alias several pages read directly).
$userID = (int) ($_SESSION['userID'] ?? 0);

$intendedRedirect = sanitizeRedirectUrl($_GET['redirect'] ?? '/dashboard');

// 🛟 NEVER trap a user in a loop: if there's nothing to resolve (bad/absent
// userID, enforcement is OFF, or this user's consent is already current —
// including a brand-new user with no prior consent at all, see
// ConsentCategoryService::userNeedsReconsent()'s own docblock), just
// continue straight to their destination. This also protects a user who
// bookmarks/reloads this URL after already accepting.
if ($userID <= 0 || !ConsentCategoryService::userNeedsReconsent($userID)) {
    redirect($intendedRedirect);
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // ✅ Record a FRESH consent row for each policy that has a current
        // version anchor configured — mechanism 'preference_center' (already
        // a member of tblConsentRecords.mechanism's ENUM; no new value needed).
        foreach (['terms', 'privacy'] as $policyKey) {
            $currentPolicy = ConsentCategoryService::getCurrentPolicyVersion($policyKey);
            if ($currentPolicy === null) {
                continue;
            }

            ConsentManager::record($userID, null, $policyKey, true, [
                'mechanism'     => 'preference_center',
                'policyVersion' => $currentPolicy['version'],
            ]);
        }

        redirect($intendedRedirect);
    }
}

$termsPolicy = ConsentCategoryService::getCurrentPolicyVersion('terms');
$privacyPolicy = ConsentCategoryService::getCurrentPolicyVersion('privacy');
$csrfToken = SecurityUtils::generateCSRFToken();

$pageTitle = 'Updated Terms & Privacy Policy - SIGNula';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Please review our updated Terms of Service and/or Privacy Policy.">
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
                <div class="auth-logo">
                    <i class="fas fa-file-signature"></i>
                </div>
                <h1>Updated Terms &amp; Privacy Policy</h1>
                <p>Please review and accept to continue to your account.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                    <button type="button" class="alert-close" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <ul class="list-group list-group-flush mb-4">
                <?php if ($termsPolicy !== null): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a href="/legal/terms" target="_blank" rel="noopener">Terms of Service</a>
                        <span class="badge bg-primary">v<?php echo htmlspecialchars((string) $termsPolicy['version'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </li>
                <?php endif; ?>
                <?php if ($privacyPolicy !== null): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a href="/legal/privacy" target="_blank" rel="noopener">Privacy Policy</a>
                        <span class="badge bg-primary">v<?php echo htmlspecialchars((string) $privacyPolicy['version'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </li>
                <?php endif; ?>
            </ul>

            <form method="POST" action="/legal/reconsent<?php echo isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : ''; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <i class="fas fa-check-circle"></i> I Accept &mdash; Continue
                </button>
            </form>

            <div class="text-center mt-4">
                <a href="/logout" class="btn-link">Sign out instead</a>
            </div>
        </div>
    </div>
</div>

</body>
</html>
