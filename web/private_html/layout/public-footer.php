<?php
/**
 * ============================================================================
 * 🎨 SIGNula Public Footer Component
 * ============================================================================
 * Reusable footer for SIGNula.com marketing pages
 *
 * @package    SIGNula
 * @subpackage Components
 * @version    1.0.0
 * ============================================================================
 */

$currentYear = date('Y');

// ============================================================================
// 🍪 SERVER-PERSISTED CONSENT BANNER (G-004 Layer 2)
// ============================================================================
// Replaces the old localStorage-only banner. web/private_html/compliance/ is
// NOT covered by config.php's spl_autoload_register() directory list, so —
// mirroring every other compliance-class call site in this codebase — it is
// explicitly required, guarded by class_exists().
if (!class_exists('ConsentCategoryService')) {
    require_once ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'compliance'
        . DIRECTORY_SEPARATOR . 'ConsentCategoryService.php';
}
if (!class_exists('ConsentManager')) {
    require_once ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'compliance'
        . DIRECTORY_SEPARATOR . 'ConsentManager.php';
}

// 👤 Resolve identity — an authenticated userID if there is one, otherwise
// the anonymous visitorID cookie (issued/refreshed here so it exists before
// the banner's own POST needs it).
$footerUserID = null;
if (class_exists('Auth') && Auth::isAuthenticated()) {
    $footerCurrentUser = Auth::getCurrentUser();
    $footerUserID = isset($footerCurrentUser['userID']) ? (int) $footerCurrentUser['userID'] : null;
}
$footerVisitorID = ConsentCategoryService::issueOrGetVisitorID();

// 🔍 Only show the banner if this subject has NO effective consent decision
// yet. 'cookies.necessary' is ALWAYS the first category recorded on any
// banner/preference-center submission (server-enforced — see
// ConsentCategoryService::recordBannerChoices()), so its presence in the
// effective-consents map is a reliable "a decision has already been made"
// signal — cheaper than checking every category individually.
$footerShowConsentBanner = false;
if ((bool) getSetting('consent.banner.enabled', '1')) {
    $footerEffectiveConsents = ConsentManager::getEffectiveConsents($footerUserID, $footerVisitorID);
    $footerShowConsentBanner = !array_key_exists('cookies.necessary', $footerEffectiveConsents);
}

$footerConsentCategories = $footerShowConsentBanner ? ConsentCategoryService::getActiveCategories() : [];
$footerConsentCsrfToken = $footerShowConsentBanner ? SecurityUtils::generateCSRFToken() : '';
?>

    </main> <!-- Close main content wrapper from header -->

    <!-- Public Footer -->
    <footer class="public-footer">
        <div class="container">
            <!-- Main Footer Content -->
            <div class="row g-4 py-5">
                <!-- Column 1: Brand & About -->
                <div class="col-lg-4 col-md-6">
                    <div class="footer-brand mb-3">
                        <i class="fas fa-shield-alt me-2" style="font-size: 1.5rem;"></i>
                        <strong style="font-size: 1.25rem;">SIGNula</strong>
                    </div>
                    <p class="text-light-muted mb-3">
                        Universal authentication system providing secure single sign-on for web and mobile applications.
                    </p>
                    <!-- Social Media Icons -->
                    <div class="social-links">
                        <a href="#" class="social-link" aria-label="Twitter" title="Follow us on Twitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="social-link" aria-label="LinkedIn" title="Connect on LinkedIn">
                            <i class="fab fa-linkedin"></i>
                        </a>
                        <a href="#" class="social-link" aria-label="GitHub" title="View on GitHub">
                            <i class="fab fa-github"></i>
                        </a>
                        <a href="#" class="social-link" aria-label="YouTube" title="Watch on YouTube">
                            <i class="fab fa-youtube"></i>
                        </a>
                    </div>
                </div>

                <!-- Column 2: Product -->
                <div class="col-lg-2 col-md-6">
                    <h5 class="footer-heading">Product</h5>
                    <ul class="footer-links">
                        <li><a href="/features">Features</a></li>
                        <li><a href="/pricing">Pricing</a></li>
                        <li><a href="/docs/security">Security</a></li>
                        <li><a href="/docs/api-reference">API</a></li>
                        <li><a href="/blog">Blog</a></li>
                    </ul>
                </div>

                <!-- Column 3: Resources -->
                <div class="col-lg-2 col-md-6">
                    <h5 class="footer-heading">Resources</h5>
                    <ul class="footer-links">
                        <li><a href="/docs">Documentation</a></li>
                        <li><a href="/docs/getting-started">Getting Started</a></li>
                        <li><a href="/docs/integration-guide">Integration Guide</a></li>
                        <li><a href="/docs/faq">FAQ</a></li>
                        <li><a href="/support">Support</a></li>
                    </ul>
                </div>

                <!-- Column 4: Company -->
                <div class="col-lg-2 col-md-6">
                    <h5 class="footer-heading">Company</h5>
                    <ul class="footer-links">
                        <li><a href="/about">About Us</a></li>
                        <li><a href="/contact">Contact</a></li>
                        <li><a href="https://signula.id/register">Sign Up</a></li>
                        <li><a href="https://signula.id/login">Login</a></li>
                    </ul>
                </div>

                <!-- Column 5: Legal -->
                <div class="col-lg-2 col-md-6">
                    <h5 class="footer-heading">Legal</h5>
                    <ul class="footer-links">
                        <li><a href="/legal/privacy">Privacy Policy</a></li>
                        <li><a href="/legal/terms">Terms of Service</a></li>
                        <li><a href="/legal/cookies">Cookie Policy</a></li>
                        <li><a href="/legal/copyright">Copyright</a></li>
                    </ul>
                </div>
            </div>

            <!-- Footer Bottom Bar -->
            <div class="footer-bottom py-4 border-top border-secondary">
                <div class="row align-items-center">
                    <div class="col-md-6 text-center text-md-start mb-3 mb-md-0">
                        <p class="mb-0 text-light-muted">
                            &copy; <?php echo $currentYear; ?> MWBMPartners. All rights reserved.
                        </p>
                    </div>
                    <div class="col-md-6 text-center text-md-end">
                        <p class="mb-0 text-light-muted">
                            Powered by SIGNula v<?php echo APP_VERSION ?? '2.0.1'; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap 5.3.2 JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- Custom JavaScript -->
    <script src="/assets/js/main.js"></script>

    <!-- ========================================================================
         🍪 SERVER-PERSISTED CONSENT BANNER (G-004 Layer 2)
         ========================================================================
         Rendered server-side from tblConsentCategories (via
         ConsentCategoryService::getActiveCategories()) — NOT a hardcoded
         markup string. Works WITHOUT JavaScript: this is a real
         <form method="POST" action="/api/v1/consent"> that posts and gets a
         303 redirect back with ?consent=saved. The <script> block below is
         PURE progressive enhancement — it intercepts the same submit and
         does it via fetch() instead, so there is no full page reload for
         JS-capable browsers. localStorage is only a UX mirror; the
         tblConsentRecords row written by the endpoint is the source of truth. -->
    <?php if ($footerShowConsentBanner): ?>
    <div class="cookie-consent-banner" id="signulaConsentBanner" role="region" aria-label="Cookie and privacy preferences">
        <div class="container">
            <form method="POST" action="/api/v1/consent" id="signulaConsentForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($footerConsentCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8'); ?>">
                <div class="row align-items-start">
                    <div class="col-lg-8 mb-3 mb-lg-0">
                        <p class="mb-2">
                            <i class="fas fa-cookie-bite me-2"></i>
                            We use cookies. Some are strictly necessary; others help us improve the site or personalise your
                            experience — you choose which. <a href="/legal/cookies" class="text-white text-decoration-underline">Learn more</a>
                        </p>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($footerConsentCategories as $footerCategory): ?>
                                <?php
                                $footerCategoryKey = (string) $footerCategory['categoryKey'];
                                $footerIsNecessary = (bool) $footerCategory['isStrictlyNecessary'];
                                $footerPreChecked = $footerIsNecessary || (bool) $footerCategory['defaultGranted'];
                                $footerFieldId = 'signulaConsentCat_' . preg_replace('/[^a-z0-9_]/i', '', $footerCategoryKey);
                                ?>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox"
                                           id="<?php echo htmlspecialchars($footerFieldId, ENT_QUOTES, 'UTF-8'); ?>"
                                           name="categories[<?php echo htmlspecialchars($footerCategoryKey, ENT_QUOTES, 'UTF-8'); ?>]"
                                           value="1"
                                           <?php echo $footerPreChecked ? 'checked' : ''; ?>
                                           <?php echo $footerIsNecessary ? 'disabled' : ''; ?>>
                                    <label class="form-check-label text-white" for="<?php echo htmlspecialchars($footerFieldId, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string) $footerCategory['label'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if ($footerIsNecessary): ?><small>(always on)</small><?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        <button type="submit" name="accept_mode" value="all" class="btn btn-light btn-sm me-2">
                            Accept All
                        </button>
                        <button type="submit" name="accept_mode" value="custom" class="btn btn-outline-light btn-sm">
                            Save Preferences
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <!-- ♿ No-JS fallback — a real, fully-working full-page preference center. -->
    <noscript>
        <div class="container mt-2 text-center">
            <a href="/legal/consent" class="text-white">Manage your cookie preferences</a>
        </div>
    </noscript>
    <script>
    // 🍪 Progressive enhancement ONLY — see the block comment above. The
    // <form> already works without this script (native POST + 303 redirect).
    (function () {
        var form = document.getElementById('signulaConsentForm');
        var banner = document.getElementById('signulaConsentBanner');
        if (!form || !banner) { return; }

        // 📌 Track which submit button triggered the submission — a plain
        // FormData(form) does NOT include the clicked button's name/value,
        // only a real browser form submission does that automatically.
        var lastClickedButton = null;
        var buttons = form.querySelectorAll('button[type="submit"]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function (evt) {
                lastClickedButton = evt.currentTarget;
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var formData = new FormData(form);
            if (lastClickedButton && lastClickedButton.name) {
                formData.append(lastClickedButton.name, lastClickedButton.value);
            }

            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (response) {
                if (!response.ok) { throw new Error('consent save failed'); }
                return response.json();
            }).then(function () {
                // 🪞 UX mirror only — the server row is the source of truth.
                try { localStorage.setItem('cookie_consent', 'true'); } catch (storageError) { /* ignore */ }
                banner.style.opacity = '0';
                setTimeout(function () { banner.remove(); }, 300);
            }).catch(function () {
                // 🛟 Fall back to a genuine form submission (still works).
                form.submit();
            });
        });
    })();
    </script>
    <?php endif; ?>

    <!-- 📱 Service Worker Registration -->
    <!-- @see https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerContainer/register -->
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('/sw.js', { scope: '/' })
                .then(function(registration) {
                    // 🔄 Check for updates periodically
                    registration.addEventListener('updatefound', function() {
                        var newWorker = registration.installing;
                        if (newWorker) {
                            newWorker.addEventListener('statechange', function() {
                                if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                    // 🆕 New version available — notify user
                                    console.log('[SIGNula SW] New version available');
                                }
                            });
                        }
                    });
                })
                .catch(function(error) {
                    console.log('[SIGNula SW] Registration failed:', error);
                });
        });
    }
    </script>

</body>
</html>
