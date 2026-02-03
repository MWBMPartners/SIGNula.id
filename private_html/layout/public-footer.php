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

    <!-- Cookie Consent Banner (if not already accepted) -->
    <script>
    // Simple cookie consent check
    (function() {
        const cookieConsent = localStorage.getItem('cookie_consent');
        if (!cookieConsent) {
            // Show cookie banner
            const banner = document.createElement('div');
            banner.className = 'cookie-consent-banner';
            banner.innerHTML = `
                <div class="container">
                    <div class="row align-items-center">
                        <div class="col-md-9 mb-3 mb-md-0">
                            <p class="mb-0">
                                <i class="fas fa-cookie-bite me-2"></i>
                                We use cookies to enhance your experience. By continuing to visit this site you agree to our use of cookies.
                                <a href="/legal/cookies" class="text-white text-decoration-underline">Learn more</a>
                            </p>
                        </div>
                        <div class="col-md-3 text-md-end">
                            <button onclick="acceptCookies()" class="btn btn-light btn-sm">
                                Accept
                            </button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(banner);
        }
    })();

    function acceptCookies() {
        localStorage.setItem('cookie_consent', 'true');
        const banner = document.querySelector('.cookie-consent-banner');
        if (banner) {
            banner.style.opacity = '0';
            setTimeout(() => banner.remove(), 300);
        }
    }
    </script>

</body>
</html>
