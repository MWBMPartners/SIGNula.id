<?php
/**
 * ============================================================================
 * 🚫 SIGNula - "Do Not Sell or Share My Personal Information" (G-004 Layer 2)
 * ============================================================================
 *
 * Purpose: The conspicuous public link/page CCPA/CPRA-style regimes expect
 *          for opting out of the sale/sharing of personal information. This
 *          page is FUNCTIONAL UI, not legal text — it explains what the
 *          toggle does and links to where it's exercised:
 *            - Logged-in users: settings/privacy.php's "Do Not Sell My
 *              Information" toggle (the authoritative control surface).
 *            - Anonymous visitors: a short form on THIS page that records
 *              the opt-out against their visitorID cookie directly, via the
 *              SAME ConsentManager seam (consentType='do_not_sell').
 *
 * PHP Version: 8.3+
 *
 * @see web/public_html/settings/privacy.php — the authenticated toggle (set_do_not_sell action)
 * @see web/private_html/compliance/ConsentCategoryService.php
 * @see web/private_html/compliance/ConsentManager.php
 * @see .dev-team/specs/G-004.md §3.3
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Marketing\Legal\DoNotSell
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// 🚀 Bootstrap the application.
// ⚠️ PATH DEPTH: same as legal/consent/index.php and legal/data-request/index.php
// — FOUR levels below web/ (public_html -> SIGNula.com -> legal -> do-not-sell).
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

if (!class_exists('ConsentCategoryService')) {
    require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'ConsentCategoryService.php';
}
if (!class_exists('ConsentManager')) {
    require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'ConsentManager.php';
}

$pageTitle = 'Do Not Sell or Share My Personal Information - SIGNula';
$metaDescription = 'Opt out of the sale or sharing of your personal information.';
$currentPage = 'legal-do-not-sell';

$doNotSellEnabled = (bool) getSetting('consent.do_not_sell.enabled', '1');

$userID = null;
if (class_exists('Auth') && Auth::isAuthenticated()) {
    $currentUser = Auth::getCurrentUser();
    $userID = isset($currentUser['userID']) ? (int) $currentUser['userID'] : null;
}
$visitorID = ConsentCategoryService::issueOrGetVisitorID();

$success = false;
$error = null;

if ($doNotSellEnabled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // 🚫 granted=false means "opted OUT of sale/sharing" — see
        // ConsentManager::record()'s consentType docblock + spec §3.3.
        ConsentManager::record($userID, $visitorID, 'do_not_sell', false, ['mechanism' => 'preference_center']);
        $success = true;
        SecurityUtils::generateCSRFToken();
    }
}

$currentDoNotSell = ConsentManager::getCurrent($userID, $visitorID, 'do_not_sell');
$isOptedOut = $currentDoNotSell !== null && (int) $currentDoNotSell['granted'] === 0;
$csrfToken = SecurityUtils::generateCSRFToken();

require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>

<section class="hero-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10 text-center">
                <h1 class="hero-title">Do Not Sell or Share My Personal Information</h1>
                <p class="hero-subtitle">
                    Tell us not to sell or share your personal information. This is separate from cookie
                    preferences — you can set it here even if you never sign in.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="section-padding">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-md-5">

                        <?php if (!$doNotSellEnabled): ?>
                            <div class="alert alert-info mb-0">
                                This option is not currently enabled.
                            </div>
                        <?php else: ?>

                            <?php if ($success): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="fas fa-check-circle me-2"></i>
                                    Your preference has been saved. We will not sell or share your personal information.
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

                            <p>
                                Current status:
                                <span class="badge bg-<?php echo $isOptedOut ? 'success' : 'secondary'; ?>">
                                    <?php echo $isOptedOut ? 'Opted out (not sold/shared)' : 'Not opted out'; ?>
                                </span>
                            </p>

                            <?php if ($isOptedOut): ?>
                                <p class="text-muted small">
                                    You have already opted out. If you're signed in, you can review or change this
                                    any time under <strong>Settings &rarr; Privacy</strong>.
                                </p>
                            <?php else: ?>
                                <form method="POST" action="">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-ban me-2"></i>Do Not Sell or Share My Information
                                    </button>
                                </form>
                            <?php endif; ?>

                            <p class="text-muted small mt-4 mb-0">
                                Already have a SIGNula account? Manage this and your other privacy choices under
                                <strong>Settings &rarr; Privacy</strong> instead.
                            </p>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
