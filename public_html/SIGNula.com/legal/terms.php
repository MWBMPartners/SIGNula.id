<?php
/**
 * ============================================================================
 * 📜 SIGNula Terms of Service
 * ============================================================================
 * Terms and conditions for using SIGNula services
 *
 * IMPORTANT: This is a template and MUST be reviewed by legal counsel
 * before production use.
 *
 * @package    SIGNula
 * @subpackage Legal
 * @version    1.0.0
 * ============================================================================
 */

// Page configuration
$pageTitle = 'Terms of Service - SIGNula';
$metaDescription = 'SIGNula Terms of Service - Legal agreement for using our universal authentication system and services.';
$currentPage = '';
$ogImage = '/assets/images/og-legal.png';

// Include header component
require_once __DIR__ . '/../../../private_html/layout/public-header.php';

$lastUpdated = '2026-02-03';
$effectiveDate = '2026-02-03';
?>

<!-- ========================================================================
     LEGAL HERO SECTION
     ======================================================================== -->
<section class="bg-light py-5">
    <div class="container">
        <div class="row">
            <div class="col-lg-8 mx-auto text-center">
                <h1 class="display-4 fw-bold mb-3">Terms of Service</h1>
                <p class="lead text-secondary">
                    Last Updated: <?php echo $lastUpdated; ?><br>
                    Effective Date: <?php echo $effectiveDate; ?>
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     TERMS CONTENT
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <div class="row">
            <!-- Table of Contents -->
            <div class="col-lg-3 d-none d-lg-block">
                <div class="sticky-top" style="top: 100px;">
                    <h5 class="mb-3">Table of Contents</h5>
                    <nav class="nav flex-column">
                        <a class="nav-link" href="#acceptance">1. Acceptance</a>
                        <a class="nav-link" href="#description">2. Service Description</a>
                        <a class="nav-link" href="#eligibility">3. Eligibility</a>
                        <a class="nav-link" href="#account">4. Account Responsibilities</a>
                        <a class="nav-link" href="#acceptable-use">5. Acceptable Use</a>
                        <a class="nav-link" href="#fees">6. Fees & Payment</a>
                        <a class="nav-link" href="#intellectual-property">7. Intellectual Property</a>
                        <a class="nav-link" href="#termination">8. Termination</a>
                        <a class="nav-link" href="#disclaimers">9. Disclaimers</a>
                        <a class="nav-link" href="#liability">10. Limitation of Liability</a>
                        <a class="nav-link" href="#indemnification">11. Indemnification</a>
                        <a class="nav-link" href="#dispute-resolution">12. Dispute Resolution</a>
                        <a class="nav-link" href="#miscellaneous">13. Miscellaneous</a>
                        <a class="nav-link" href="#contact">14. Contact</a>
                    </nav>
                </div>
            </div>

            <!-- Content -->
            <div class="col-lg-9">
                <div class="legal-content">
                    <!-- 1. Acceptance -->
                    <section id="acceptance" class="mb-5">
                        <h2 class="h3 mb-3">1. Acceptance of Terms</h2>
                        <p>
                            These Terms of Service ("Terms") constitute a legally binding agreement between you and MWBMPartners ("Company," "we," "us," or "our") regarding your use of SIGNula ("Service").
                        </p>
                        <p>
                            <strong>By accessing or using our Service, you agree to be bound by these Terms.</strong> If you do not agree to these Terms, you may not use our Service.
                        </p>
                    </section>

                    <!-- 2. Service Description -->
                    <section id="description" class="mb-5">
                        <h2 class="h3 mb-3">2. Service Description</h2>
                        <p>SIGNula provides universal authentication services including:</p>
                        <ul>
                            <li>User account creation and management</li>
                            <li>Multi-factor authentication (MFA)</li>
                            <li>PassKey/WebAuthn authentication</li>
                            <li>OAuth account linking</li>
                            <li>Single Sign-On (SSO) capabilities</li>
                            <li>API access for third-party integration</li>
                        </ul>
                        <p>
                            We reserve the right to modify, suspend, or discontinue any part of the Service at any time with or without notice.
                        </p>
                    </section>

                    <!-- 3. Eligibility -->
                    <section id="eligibility" class="mb-5">
                        <h2 class="h3 mb-3">3. Eligibility</h2>
                        <p>You must meet the following requirements to use our Service:</p>
                        <ul>
                            <li>Be at least 13 years old (16 in the European Economic Area)</li>
                            <li>Have the legal capacity to enter into binding contracts</li>
                            <li>Not be prohibited from using the Service under applicable laws</li>
                            <li>Comply with all local, state, national, and international laws</li>
                        </ul>
                        <p>
                            If you are using the Service on behalf of an organization, you represent that you have authority to bind that organization to these Terms.
                        </p>
                    </section>

                    <!-- 4. Account Responsibilities -->
                    <section id="account" class="mb-5">
                        <h2 class="h3 mb-3">4. Account Responsibilities</h2>

                        <h4 class="h5 mt-4 mb-3">4.1 Account Creation</h4>
                        <p>To use certain features, you must create an account. You agree to:</p>
                        <ul>
                            <li>Provide accurate, current, and complete information</li>
                            <li>Maintain and promptly update your account information</li>
                            <li>Keep your password secure and confidential</li>
                            <li>Not share your account credentials with others</li>
                            <li>Notify us immediately of any unauthorized access</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">4.2 Account Security</h4>
                        <p>
                            You are responsible for all activities that occur under your account. We recommend:
                        </p>
                        <ul>
                            <li>Using a strong, unique password</li>
                            <li>Enabling multi-factor authentication</li>
                            <li>Regularly reviewing account activity</li>
                            <li>Not using public or shared computers for sensitive operations</li>
                        </ul>
                    </section>

                    <!-- 5. Acceptable Use -->
                    <section id="acceptable-use" class="mb-5">
                        <h2 class="h3 mb-3">5. Acceptable Use Policy</h2>
                        <p>You agree NOT to:</p>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card border-danger">
                                    <div class="card-body">
                                        <h5 class="card-title text-danger"><i class="fas fa-ban me-2"></i>Prohibited Activities</h5>
                                        <ul class="mb-0">
                                            <li>Violate any laws or regulations</li>
                                            <li>Infringe on intellectual property rights</li>
                                            <li>Transmit malware or harmful code</li>
                                            <li>Attempt to gain unauthorized access</li>
                                            <li>Interfere with Service operation</li>
                                            <li>Engage in fraudulent activities</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-danger">
                                    <div class="card-body">
                                        <h5 class="card-title text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Restricted Actions</h5>
                                        <ul class="mb-0">
                                            <li>Scrape or data mine the Service</li>
                                            <li>Reverse engineer our software</li>
                                            <li>Create multiple fake accounts</li>
                                            <li>Impersonate others</li>
                                            <li>Spam or send unsolicited messages</li>
                                            <li>Bypass rate limiting or security measures</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p class="mt-3">
                            <strong>Violation of this policy may result in immediate account suspension or termination.</strong>
                        </p>
                    </section>

                    <!-- 6. Fees & Payment -->
                    <section id="fees" class="mb-5">
                        <h2 class="h3 mb-3">6. Fees and Payment</h2>

                        <h4 class="h5 mt-4 mb-3">6.1 Pricing</h4>
                        <p>
                            Pricing for our services is available at <a href="/pricing">signula.com/pricing</a>. We offer:
                        </p>
                        <ul>
                            <li>Free tier with limited features</li>
                            <li>Paid tiers with additional features</li>
                            <li>Enterprise custom pricing</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">6.2 Payment Terms</h4>
                        <ul>
                            <li>All fees are in U.S. Dollars (USD) unless otherwise stated</li>
                            <li>Subscription fees are billed monthly or annually in advance</li>
                            <li>Payments are non-refundable except as required by law</li>
                            <li>We reserve the right to change pricing with 30 days' notice</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">6.3 Cancellation</h4>
                        <p>
                            You may cancel your subscription at any time. Cancellation takes effect at the end of your current billing period. No refunds for partial periods.
                        </p>
                    </section>

                    <!-- 7. Intellectual Property -->
                    <section id="intellectual-property" class="mb-5">
                        <h2 class="h3 mb-3">7. Intellectual Property Rights</h2>

                        <h4 class="h5 mt-4 mb-3">7.1 Our Property</h4>
                        <p>
                            The Service and its content (excluding user content) are owned by MWBMPartners and protected by copyright, trademark, and other intellectual property laws.
                        </p>

                        <h4 class="h5 mt-4 mb-3">7.2 Your Content</h4>
                        <p>
                            You retain ownership of content you submit to the Service. By submitting content, you grant us a worldwide, non-exclusive, royalty-free license to use, store, and display your content solely for providing the Service.
                        </p>

                        <h4 class="h5 mt-4 mb-3">7.3 Trademarks</h4>
                        <p>
                            SIGNula, its logo, and related marks are trademarks of MWBMPartners. You may not use our trademarks without prior written permission.
                        </p>
                    </section>

                    <!-- 8. Termination -->
                    <section id="termination" class="mb-5">
                        <h2 class="h3 mb-3">8. Termination</h2>

                        <h4 class="h5 mt-4 mb-3">8.1 By You</h4>
                        <p>
                            You may terminate your account at any time through your account settings or by contacting support.
                        </p>

                        <h4 class="h5 mt-4 mb-3">8.2 By Us</h4>
                        <p>
                            We may suspend or terminate your access to the Service:
                        </p>
                        <ul>
                            <li>For violation of these Terms</li>
                            <li>For fraudulent, illegal, or harmful activities</li>
                            <li>If required by law</li>
                            <li>For non-payment of fees</li>
                            <li>At our sole discretion with or without cause</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">8.3 Effect of Termination</h4>
                        <p>
                            Upon termination:
                        </p>
                        <ul>
                            <li>Your right to use the Service immediately ceases</li>
                            <li>We may delete your account and data after 30 days</li>
                            <li>You remain liable for any unpaid fees</li>
                            <li>Provisions that should survive termination will continue to apply</li>
                        </ul>
                    </section>

                    <!-- 9. Disclaimers -->
                    <section id="disclaimers" class="mb-5">
                        <h2 class="h3 mb-3">9. Disclaimers</h2>
                        <div class="alert alert-warning">
                            <h5 class="alert-heading">IMPORTANT DISCLAIMERS</h5>
                            <p>
                                <strong>THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND, EITHER EXPRESS OR IMPLIED.</strong>
                            </p>
                            <p>
                                WE DISCLAIM ALL WARRANTIES, INCLUDING BUT NOT LIMITED TO:
                            </p>
                            <ul>
                                <li>MERCHANTABILITY</li>
                                <li>FITNESS FOR A PARTICULAR PURPOSE</li>
                                <li>NON-INFRINGEMENT</li>
                                <li>UNINTERRUPTED OR ERROR-FREE OPERATION</li>
                                <li>SECURITY OR FREEDOM FROM VIRUSES</li>
                            </ul>
                            <p class="mb-0">
                                <small>Some jurisdictions do not allow the exclusion of implied warranties, so some of the above exclusions may not apply to you.</small>
                            </p>
                        </div>
                    </section>

                    <!-- 10. Limitation of Liability -->
                    <section id="liability" class="mb-5">
                        <h2 class="h3 mb-3">10. Limitation of Liability</h2>
                        <div class="alert alert-danger">
                            <h5 class="alert-heading">LIABILITY LIMITATIONS</h5>
                            <p>
                                <strong>TO THE MAXIMUM EXTENT PERMITTED BY LAW, IN NO EVENT SHALL MWBMPARTNERS BE LIABLE FOR:</strong>
                            </p>
                            <ul>
                                <li>INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES</li>
                                <li>LOSS OF PROFITS, DATA, USE, OR GOODWILL</li>
                                <li>SERVICE INTERRUPTIONS OR SECURITY BREACHES</li>
                                <li>THIRD-PARTY ACTIONS OR CONTENT</li>
                            </ul>
                            <p>
                                <strong>OUR TOTAL LIABILITY SHALL NOT EXCEED THE GREATER OF:</strong>
                            </p>
                            <ul class="mb-0">
                                <li>The amount you paid us in the past 12 months, OR</li>
                                <li>$100 USD</li>
                            </ul>
                        </div>
                    </section>

                    <!-- 11. Indemnification -->
                    <section id="indemnification" class="mb-5">
                        <h2 class="h3 mb-3">11. Indemnification</h2>
                        <p>
                            You agree to indemnify, defend, and hold harmless MWBMPartners, its officers, directors, employees, and agents from any claims, damages, losses, liabilities, and expenses (including legal fees) arising from:
                        </p>
                        <ul>
                            <li>Your use of the Service</li>
                            <li>Your violation of these Terms</li>
                            <li>Your violation of any rights of another party</li>
                            <li>Your content or conduct</li>
                        </ul>
                    </section>

                    <!-- 12. Dispute Resolution -->
                    <section id="dispute-resolution" class="mb-5">
                        <h2 class="h3 mb-3">12. Dispute Resolution</h2>

                        <h4 class="h5 mt-4 mb-3">12.1 Informal Resolution</h4>
                        <p>
                            If you have a dispute, please contact us first at <a href="mailto:legal@signula.com">legal@signula.com</a>. We'll work to resolve it informally.
                        </p>

                        <h4 class="h5 mt-4 mb-3">12.2 Arbitration</h4>
                        <p>
                            If informal resolution fails, disputes will be resolved through binding arbitration rather than in court, except where prohibited by law.
                        </p>

                        <h4 class="h5 mt-4 mb-3">12.3 Governing Law</h4>
                        <p>
                            These Terms are governed by the laws of [Jurisdiction], without regard to conflict of law principles.
                        </p>
                    </section>

                    <!-- 13. Miscellaneous -->
                    <section id="miscellaneous" class="mb-5">
                        <h2 class="h3 mb-3">13. Miscellaneous</h2>

                        <h4 class="h5 mt-4 mb-3">13.1 Changes to Terms</h4>
                        <p>
                            We may modify these Terms at any time. Material changes will be notified via email or Service notice. Continued use constitutes acceptance.
                        </p>

                        <h4 class="h5 mt-4 mb-3">13.2 Severability</h4>
                        <p>
                            If any provision is found unenforceable, the remaining provisions continue in full force.
                        </p>

                        <h4 class="h5 mt-4 mb-3">13.3 Assignment</h4>
                        <p>
                            You may not assign these Terms without our consent. We may assign our rights and obligations without restriction.
                        </p>

                        <h4 class="h5 mt-4 mb-3">13.4 Entire Agreement</h4>
                        <p>
                            These Terms constitute the entire agreement between you and us regarding the Service.
                        </p>

                        <h4 class="h5 mt-4 mb-3">13.5 Waiver</h4>
                        <p>
                            Our failure to enforce any provision does not constitute a waiver of that provision.
                        </p>
                    </section>

                    <!-- 14. Contact -->
                    <section id="contact" class="mb-5">
                        <h2 class="h3 mb-3">14. Contact Information</h2>
                        <p>For questions about these Terms:</p>
                        <div class="card">
                            <div class="card-body">
                                <p class="mb-2"><strong>Email:</strong> <a href="mailto:legal@signula.com">legal@signula.com</a></p>
                                <p class="mb-2"><strong>Mail:</strong> MWBMPartners, Legal Department, [Address]</p>
                                <p class="mb-0"><strong>Contact Form:</strong> <a href="/contact?type=legal">Legal Inquiry Form</a></p>
                            </div>
                        </div>
                    </section>

                    <!-- Legal Disclaimer -->
                    <div class="alert alert-warning mt-5">
                        <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Legal Review Required</h5>
                        <p class="mb-0">
                            <strong>IMPORTANT:</strong> This Terms of Service is a template and must be reviewed and approved by qualified legal counsel before production use. Laws vary by jurisdiction, and this template may not address all legal requirements for your specific situation.
                        </p>
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

.sticky-top {
    position: -webkit-sticky;
    position: sticky;
}

.nav-link {
    color: var(--text-secondary);
    padding: 0.5rem 0;
    transition: color var(--transition-fast);
}

.nav-link:hover {
    color: var(--primary-color);
}
</style>

<?php
// Include footer component
require_once __DIR__ . '/../../../private_html/layout/public-footer.php';
?>
