<?php
/**
 * ============================================================================
 * 🍪 SIGNula Cookie Policy
 * ============================================================================
 * Information about cookies and tracking technologies
 *
 * @package    SIGNula
 * @subpackage Legal
 * @version    1.0.0
 * ============================================================================
 */

// Page configuration
$pageTitle = 'Cookie Policy - SIGNula';
$metaDescription = 'Learn about how SIGNula uses cookies and similar tracking technologies to improve your experience.';
$currentPage = '';
$ogImage = '/assets/images/og-legal.png';

// Include header component
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';

$lastUpdated = '2026-02-03';
?>

<!-- ========================================================================
     LEGAL HERO SECTION
     ======================================================================== -->
<section class="bg-light py-5">
    <div class="container">
        <div class="row">
            <div class="col-lg-8 mx-auto text-center">
                <h1 class="display-4 fw-bold mb-3">
                    <i class="fas fa-cookie-bite me-3"></i>Cookie Policy
                </h1>
                <p class="lead text-secondary">
                    Last Updated: <?php echo $lastUpdated; ?>
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     COOKIE POLICY CONTENT
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <div class="row">
            <!-- Content -->
            <div class="col-lg-10 mx-auto">
                <div class="legal-content">
                    <!-- Introduction -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">What Are Cookies?</h2>
                        <p>
                            Cookies are small text files that are placed on your device when you visit a website. They help websites remember your preferences, improve your experience, and provide analytics about how the site is used.
                        </p>
                        <p>
                            This Cookie Policy explains how SIGNula ("we," "us," or "our") uses cookies and similar tracking technologies on our website and services.
                        </p>
                    </section>

                    <!-- Types of Cookies -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Types of Cookies We Use</h2>

                        <div class="accordion" id="cookieAccordion">
                            <!-- Essential Cookies -->
                            <div class="accordion-item">
                                <h3 class="accordion-header">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#essential">
                                        <i class="fas fa-shield-alt me-2 text-success"></i>
                                        <strong>1. Essential Cookies</strong>
                                        <span class="badge bg-success ms-2">Required</span>
                                    </button>
                                </h3>
                                <div id="essential" class="accordion-collapse collapse show" data-bs-parent="#cookieAccordion">
                                    <div class="accordion-body">
                                        <p><strong>Purpose:</strong> These cookies are necessary for the website to function and cannot be disabled.</p>
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Cookie Name</th>
                                                    <th>Purpose</th>
                                                    <th>Duration</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><code>session_id</code></td>
                                                    <td>Maintains your login session</td>
                                                    <td>Session</td>
                                                </tr>
                                                <tr>
                                                    <td><code>csrf_token</code></td>
                                                    <td>Security protection against cross-site request forgery</td>
                                                    <td>Session</td>
                                                </tr>
                                                <tr>
                                                    <td><code>cookie_consent</code></td>
                                                    <td>Remembers your cookie preferences</td>
                                                    <td>1 year</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Functional Cookies -->
                            <div class="accordion-item">
                                <h3 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#functional">
                                        <i class="fas fa-cog me-2 text-primary"></i>
                                        <strong>2. Functional Cookies</strong>
                                        <span class="badge bg-primary ms-2">Optional</span>
                                    </button>
                                </h3>
                                <div id="functional" class="accordion-collapse collapse" data-bs-parent="#cookieAccordion">
                                    <div class="accordion-body">
                                        <p><strong>Purpose:</strong> These cookies enable enhanced functionality and personalization.</p>
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Cookie Name</th>
                                                    <th>Purpose</th>
                                                    <th>Duration</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><code>language_pref</code></td>
                                                    <td>Remembers your language preference</td>
                                                    <td>1 year</td>
                                                </tr>
                                                <tr>
                                                    <td><code>theme</code></td>
                                                    <td>Stores your theme preference (light/dark)</td>
                                                    <td>1 year</td>
                                                </tr>
                                                <tr>
                                                    <td><code>timezone</code></td>
                                                    <td>Remembers your timezone setting</td>
                                                    <td>1 year</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Analytics Cookies -->
                            <div class="accordion-item">
                                <h3 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#analytics">
                                        <i class="fas fa-chart-line me-2 text-info"></i>
                                        <strong>3. Analytics Cookies</strong>
                                        <span class="badge bg-info ms-2">Optional</span>
                                    </button>
                                </h3>
                                <div id="analytics" class="accordion-collapse collapse" data-bs-parent="#cookieAccordion">
                                    <div class="accordion-body">
                                        <p><strong>Purpose:</strong> These cookies help us understand how visitors interact with our website.</p>
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Cookie Name</th>
                                                    <th>Purpose</th>
                                                    <th>Duration</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><code>_ga</code></td>
                                                    <td>Google Analytics - tracks user interactions</td>
                                                    <td>2 years</td>
                                                </tr>
                                                <tr>
                                                    <td><code>_gid</code></td>
                                                    <td>Google Analytics - distinguishes users</td>
                                                    <td>24 hours</td>
                                                </tr>
                                                <tr>
                                                    <td><code>_gat</code></td>
                                                    <td>Google Analytics - throttles request rate</td>
                                                    <td>1 minute</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Marketing Cookies -->
                            <div class="accordion-item">
                                <h3 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#marketing">
                                        <i class="fas fa-bullhorn me-2 text-warning"></i>
                                        <strong>4. Marketing Cookies</strong>
                                        <span class="badge bg-warning ms-2">Optional</span>
                                    </button>
                                </h3>
                                <div id="marketing" class="accordion-collapse collapse" data-bs-parent="#cookieAccordion">
                                    <div class="accordion-body">
                                        <p><strong>Purpose:</strong> These cookies are used to deliver relevant advertisements.</p>
                                        <p class="mb-2"><strong>We currently do NOT use marketing cookies.</strong> If we implement them in the future, we will update this policy and request your consent.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Managing Cookies -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Managing Cookie Preferences</h2>

                        <h4 class="h5 mt-4 mb-3">On Our Website</h4>
                        <p>You can manage your cookie preferences by:</p>
                        <ul>
                            <li>Clicking the cookie settings button (usually in the footer)</li>
                            <li>Visiting your <a href="https://signula.id/settings/privacy">Privacy Settings</a> when logged in</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">In Your Browser</h4>
                        <p>You can also control cookies through your browser settings:</p>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="fab fa-chrome me-2"></i>Chrome</h6>
                                        <p class="small mb-0">
                                            Settings → Privacy and security → Cookies and other site data
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="fab fa-firefox me-2"></i>Firefox</h6>
                                        <p class="small mb-0">
                                            Settings → Privacy & Security → Cookies and Site Data
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="fab fa-safari me-2"></i>Safari</h6>
                                        <p class="small mb-0">
                                            Preferences → Privacy → Manage Website Data
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6><i class="fab fa-edge me-2"></i>Edge</h6>
                                        <p class="small mb-0">
                                            Settings → Cookies and site permissions → Manage and delete cookies
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-warning mt-4">
                            <strong>Note:</strong> Blocking all cookies may affect the functionality of our website and prevent you from using certain features.
                        </div>
                    </section>

                    <!-- Other Tracking Technologies -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Other Tracking Technologies</h2>

                        <h4 class="h5 mt-4 mb-3">Local Storage</h4>
                        <p>
                            We use browser local storage to save user preferences and improve performance. This data remains on your device and is not transmitted to our servers.
                        </p>

                        <h4 class="h5 mt-4 mb-3">Web Beacons</h4>
                        <p>
                            We may use web beacons (also called "pixels") in our emails to track email opens and link clicks. This helps us understand email engagement and improve our communications.
                        </p>

                        <h4 class="h5 mt-4 mb-3">Server Logs</h4>
                        <p>
                            Our servers automatically log information including IP addresses, browser types, referring pages, and access times for security and analytics purposes.
                        </p>
                    </section>

                    <!-- Third-Party Cookies -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Third-Party Cookies</h2>
                        <p>
                            When you link OAuth accounts (Google, Microsoft, Apple, etc.), those services may set their own cookies. We do not control these third-party cookies. Please review their privacy policies:
                        </p>
                        <ul>
                            <li><a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Google Privacy Policy</a></li>
                            <li><a href="https://privacy.microsoft.com" target="_blank" rel="noopener">Microsoft Privacy Statement</a></li>
                            <li><a href="https://www.apple.com/privacy" target="_blank" rel="noopener">Apple Privacy Policy</a></li>
                            <li><a href="https://www.facebook.com/privacy" target="_blank" rel="noopener">Facebook Privacy Policy</a></li>
                        </ul>
                    </section>

                    <!-- Updates -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Updates to This Policy</h2>
                        <p>
                            We may update this Cookie Policy from time to time to reflect changes in our practices or legal requirements. We will notify you of any material changes by updating the "Last Updated" date at the top of this page.
                        </p>
                    </section>

                    <!-- Contact -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Questions?</h2>
                        <p>
                            If you have questions about our use of cookies, please contact us:
                        </p>
                        <div class="card">
                            <div class="card-body">
                                <p class="mb-2"><strong>Email:</strong> <a href="mailto:privacy@signula.com">privacy@signula.com</a></p>
                                <p class="mb-0"><strong>Contact Form:</strong> <a href="/contact?type=privacy">Privacy Inquiry</a></p>
                            </div>
                        </div>
                    </section>

                    <!-- Related Policies -->
                    <div class="card bg-light">
                        <div class="card-body">
                            <h5 class="card-title">Related Policies</h5>
                            <ul class="mb-0">
                                <li><a href="/legal/privacy">Privacy Policy</a> - How we handle your personal information</li>
                                <li><a href="/legal/terms">Terms of Service</a> - Terms for using our services</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
.legal-content h2 {
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 2px solid var(--border-color);
}

.legal-content h2:first-child {
    margin-top: 0;
    padding-top: 0;
    border-top: none;
}

.accordion-button:not(.collapsed) {
    background-color: var(--bs-light);
    color: var(--text-primary);
}
</style>

<?php
// Include footer component
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
