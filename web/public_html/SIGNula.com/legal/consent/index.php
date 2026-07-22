<?php
/**
 * ============================================================================
 * 🍪 SIGNula - Full-Page Consent Preference Center (G-004 Layer 2)
 * ============================================================================
 *
 * Purpose: The no-JS-reachable fallback for the footer consent banner
 *          (web/private_html/layout/public-footer.php's <noscript> link
 *          points here), and a standalone "manage my cookie preferences at
 *          any time" page for both anonymous visitors and logged-in users.
 *          Self-handles its own POST (no round-trip through the API
 *          endpoint) — same category list, same
 *          ConsentCategoryService::recordBannerChoices() seam, so there is
 *          still exactly ONE place consent decisions are turned into
 *          ConsentManager::record() calls.
 *
 * PHP Version: 8.3+
 *
 * @see web/private_html/compliance/ConsentCategoryService.php — the seam this page calls
 * @see web/private_html/layout/public-footer.php — the banner this page is the fallback for
 * @see web/public_html/api/v1/consent/index.php — the AJAX/no-JS-form sibling endpoint
 * @see .dev-team/specs/G-004.md §3.1
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Marketing\Legal\Consent
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// 🚀 Bootstrap the application.
// ⚠️ PATH DEPTH: __DIR__ here is web/public_html/SIGNula.com/legal/consent —
// FOUR levels below web/ (public_html -> SIGNula.com -> legal -> consent),
// the SAME depth as web/public_html/SIGNula.com/legal/data-request/index.php
// (which uses dirname(__DIR__, 4) — confirmed against that file).
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📚 Not covered by config.php's autoloader directory list — explicit requires.
if (!class_exists('ConsentCategoryService')) {
    require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'ConsentCategoryService.php';
}
if (!class_exists('ConsentManager')) {
    require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'ConsentManager.php';
}

$pageTitle = 'Cookie & Privacy Preferences - SIGNula';
$metaDescription = 'Manage your cookie and privacy preferences for SIGNula.';
$currentPage = 'legal-consent';

// 👤 Identity — authenticated userID (if any) + the anonymous visitor cookie.
$userID = null;
if (class_exists('Auth') && Auth::isAuthenticated()) {
    $currentUser = Auth::getCurrentUser();
    $userID = isset($currentUser['userID']) ? (int) $currentUser['userID'] : null;
}
$visitorID = ConsentCategoryService::issueOrGetVisitorID();

$success = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $acceptMode = SecurityUtils::sanitizeString($_POST['accept_mode'] ?? 'custom');
        $categories = ConsentCategoryService::getActiveCategories();

        $decisions = [];
        if ($acceptMode === 'all') {
            foreach ($categories as $category) {
                $decisions[(string) $category['categoryKey']] = true;
            }
        } elseif ($acceptMode === 'necessary_only') {
            // Deliberately empty — see ConsentCategoryService::recordBannerChoices().
        } else {
            $rawCategories = $_POST['categories'] ?? [];
            if (is_array($rawCategories)) {
                foreach ($rawCategories as $key => $value) {
                    $decisions[(string) $key] = ConsentCategoryService::isTruthyInput($value);
                }
            }
        }

        ConsentCategoryService::recordBannerChoices($decisions, $userID, $visitorID);

        if ($userID !== null) {
            ConsentManager::reconcileVisitorToUser($visitorID, $userID);
        }

        $success = true;

        // 🎫 Fresh CSRF token for the re-rendered form.
        SecurityUtils::generateCSRFToken();
    }
}

// 🔍 Current effective decisions — used to pre-check the form on render
// (both on first load and after a successful save).
$effectiveConsents = ConsentManager::getEffectiveConsents($userID, $visitorID);
$consentCategories = ConsentCategoryService::getActiveCategories();
$csrfToken = SecurityUtils::generateCSRFToken();

// Include header component (SIGNula.com marketing chrome — matches
// legal/data-request/index.php's own include).
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>

<!-- ========================================================================
     HERO SECTION
     ======================================================================== -->
<section class="hero-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10 text-center">
                <h1 class="hero-title">Cookie &amp; Privacy Preferences</h1>
                <p class="hero-subtitle">
                    Choose which categories of cookies you're comfortable with. "Strictly Necessary" cookies
                    keep the site and sign-in working and can't be turned off; everything else is your choice.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     PREFERENCE FORM SECTION
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
                                Your preferences have been saved.
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

                        <form method="POST" action="" id="consentPreferencesForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                            <?php foreach ($consentCategories as $category): ?>
                                <?php
                                $categoryKey = (string) $category['categoryKey'];
                                $isNecessary = (bool) $category['isStrictlyNecessary'];
                                $isChecked = $isNecessary
                                    || ($effectiveConsents['cookies.' . $categoryKey] ?? (bool) $category['defaultGranted']);
                                $fieldId = 'consentCat_' . preg_replace('/[^a-z0-9_]/i', '', $categoryKey);
                                ?>
                                <div class="form-check form-switch mb-3 pb-3 border-bottom">
                                    <input class="form-check-input" type="checkbox"
                                           id="<?php echo htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8'); ?>"
                                           name="categories[<?php echo htmlspecialchars($categoryKey, ENT_QUOTES, 'UTF-8'); ?>]"
                                           value="1"
                                           <?php echo $isChecked ? 'checked' : ''; ?>
                                           <?php echo $isNecessary ? 'disabled' : ''; ?>>
                                    <label class="form-check-label" for="<?php echo htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8'); ?>">
                                        <strong><?php echo htmlspecialchars((string) $category['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <?php if ($isNecessary): ?><span class="badge bg-secondary ms-1">Always on</span><?php endif; ?>
                                        <?php if (!empty($category['description'])): ?>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars((string) $category['description'], ENT_QUOTES, 'UTF-8'); ?></small>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>

                            <div class="d-flex flex-wrap gap-2 mt-4">
                                <button type="submit" name="accept_mode" value="custom" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Save Preferences
                                </button>
                                <button type="submit" name="accept_mode" value="all" class="btn btn-outline-primary">
                                    Accept All
                                </button>
                                <button type="submit" name="accept_mode" value="necessary_only" class="btn btn-outline-secondary">
                                    Necessary Only
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <p class="text-muted small mt-4 text-center">
                    See our <a href="/legal/cookies">Cookie Policy</a> and <a href="/legal/privacy">Privacy Policy</a> for more,
                    or manage <a href="/legal/do-not-sell">Do Not Sell / Share My Information</a>.
                </p>
            </div>
        </div>
    </div>
</section>

<?php
// Include footer component
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
