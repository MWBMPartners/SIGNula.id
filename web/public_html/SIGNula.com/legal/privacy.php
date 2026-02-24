<?php
/**
 * ============================================================================
 * 🔒 SIGNula Privacy Policy
 * ============================================================================
 * Comprehensive privacy policy compliant with global privacy regulations
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
$pageTitle = 'Privacy Policy - SIGNula';
$metaDescription = 'SIGNula Privacy Policy - How we collect, use, and protect your personal information in compliance with GDPR, CCPA, and global privacy regulations.';
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
                <h1 class="display-4 fw-bold mb-3">Privacy Policy</h1>
                <p class="lead text-secondary">
                    Last Updated: <?php echo $lastUpdated; ?>
                </p>
                <div class="alert alert-info mt-4" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Note:</strong> This privacy policy is compliant with GDPR, CCPA, and other global privacy regulations.
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     PRIVACY POLICY CONTENT
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <div class="row">
            <!-- Table of Contents -->
            <div class="col-lg-3 d-none d-lg-block">
                <div class="sticky-top" style="top: 100px;">
                    <h5 class="mb-3">Table of Contents</h5>
                    <nav class="nav flex-column">
                        <a class="nav-link" href="#introduction">1. Introduction</a>
                        <a class="nav-link" href="#information-we-collect">2. Information We Collect</a>
                        <a class="nav-link" href="#how-we-use">3. How We Use Information</a>
                        <a class="nav-link" href="#data-sharing">4. Data Sharing</a>
                        <a class="nav-link" href="#your-rights">5. Your Rights</a>
                        <a class="nav-link" href="#data-security">6. Data Security</a>
                        <a class="nav-link" href="#international-transfers">7. International Transfers</a>
                        <a class="nav-link" href="#retention">8. Data Retention</a>
                        <a class="nav-link" href="#cookies">9. Cookies</a>
                        <a class="nav-link" href="#children">10. Children's Privacy</a>
                        <a class="nav-link" href="#changes">11. Policy Changes</a>
                        <a class="nav-link" href="#contact">12. Contact Us</a>
                    </nav>
                </div>
            </div>

            <!-- Content -->
            <div class="col-lg-9">
                <div class="legal-content">
                    <!-- 1. Introduction -->
                    <section id="introduction" class="mb-5">
                        <h2 class="h3 mb-3">1. Introduction</h2>
                        <p>
                            MWBMPartners ("we," "us," or "our") operates SIGNula, a universal authentication system. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our services.
                        </p>
                        <p>
                            By using SIGNula, you agree to the collection and use of information in accordance with this policy. If you do not agree with our policies and practices, do not use our services.
                        </p>
                        <div class="alert alert-warning">
                            <strong>Legal Compliance:</strong> This policy complies with:
                            <ul class="mb-0 mt-2">
                                <li>General Data Protection Regulation (GDPR) - European Union</li>
                                <li>California Consumer Privacy Act (CCPA) - United States</li>
                                <li>Personal Information Protection and Electronic Documents Act (PIPEDA) - Canada</li>
                                <li>Australian Privacy Act 1988</li>
                                <li>And other applicable data protection laws worldwide</li>
                            </ul>
                        </div>
                    </section>

                    <!-- 2. Information We Collect -->
                    <section id="information-we-collect" class="mb-5">
                        <h2 class="h3 mb-3">2. Information We Collect</h2>

                        <h4 class="h5 mt-4 mb-3">2.1 Information You Provide</h4>
                        <p>We collect information that you voluntarily provide when you:</p>
                        <ul>
                            <li><strong>Create an Account:</strong> Email address, display name, username, password</li>
                            <li><strong>Complete Your Profile:</strong> Profile picture, timezone, language preferences</li>
                            <li><strong>Link OAuth Accounts:</strong> Information from third-party providers (Google, Microsoft, Apple, etc.)</li>
                            <li><strong>Contact Us:</strong> Name, email, message content when you use our contact form</li>
                            <li><strong>Use Support Services:</strong> Support ticket information and communication history</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">2.2 Automatically Collected Information</h4>
                        <p>When you use SIGNula, we automatically collect:</p>
                        <ul>
                            <li><strong>Device Information:</strong> IP address (IPv4/IPv6), browser type, operating system, device identifiers</li>
                            <li><strong>Usage Data:</strong> Login times, authentication methods used, API requests, feature usage</li>
                            <li><strong>Log Data:</strong> Activity logs, error logs, security event logs</li>
                            <li><strong>Location Data:</strong> Approximate geographic location based on IP address</li>
                            <li><strong>Cookies:</strong> See our <a href="/legal/cookies">Cookie Policy</a> for details</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">2.3 Biometric Data</h4>
                        <p>
                            If you use PassKeys or WebAuthn:
                        </p>
                        <ul>
                            <li>Biometric authentication data (fingerprints, facial recognition) is processed <strong>locally on your device</strong></li>
                            <li>We <strong>do not</strong> store biometric data on our servers</li>
                            <li>We only store public cryptographic keys associated with your authenticators</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">2.4 Information from Third Parties</h4>
                        <p>When you link OAuth accounts, we receive:</p>
                        <ul>
                            <li>Basic profile information (name, email, profile picture)</li>
                            <li>Account type and email domain (for organizational filtering)</li>
                            <li>We only request the minimum permissions necessary</li>
                        </ul>
                    </section>

                    <!-- 3. How We Use Information -->
                    <section id="how-we-use" class="mb-5">
                        <h2 class="h3 mb-3">3. How We Use Your Information</h2>
                        <p>We use collected information for:</p>

                        <h4 class="h5 mt-4 mb-3">3.1 Service Provision</h4>
                        <ul>
                            <li>Creating and managing your account</li>
                            <li>Authenticating your identity</li>
                            <li>Processing your requests and transactions</li>
                            <li>Providing customer support</li>
                            <li>Sending service-related notifications</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">3.2 Security & Fraud Prevention</h4>
                        <ul>
                            <li>Detecting and preventing fraud, abuse, and security incidents</li>
                            <li>Monitoring for suspicious activity</li>
                            <li>Enforcing our Terms of Service</li>
                            <li>Maintaining activity logs for security audits</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">3.3 Improvement & Analytics</h4>
                        <ul>
                            <li>Analyzing usage patterns to improve our services</li>
                            <li>Developing new features</li>
                            <li>Conducting research and analytics</li>
                            <li>Measuring service performance</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">3.4 Communication</h4>
                        <ul>
                            <li>Sending important service updates</li>
                            <li>Responding to your inquiries</li>
                            <li>Sending marketing communications (with your consent)</li>
                            <li>Security alerts and notifications</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">3.5 Legal Compliance</h4>
                        <ul>
                            <li>Complying with legal obligations</li>
                            <li>Responding to lawful requests from authorities</li>
                            <li>Protecting our rights and property</li>
                            <li>Enforcing our agreements</li>
                        </ul>
                    </section>

                    <!-- 4. Data Sharing -->
                    <section id="data-sharing" class="mb-5">
                        <h2 class="h3 mb-3">4. How We Share Your Information</h2>
                        <p>We do not sell your personal information. We may share your information with:</p>

                        <h4 class="h5 mt-4 mb-3">4.1 Service Providers</h4>
                        <p>We share data with trusted third-party service providers who assist us in:</p>
                        <ul>
                            <li>Email delivery (transactional and marketing)</li>
                            <li>Payment processing</li>
                            <li>Analytics and monitoring</li>
                            <li>Customer support tools</li>
                            <li>Infrastructure and hosting</li>
                        </ul>
                        <p><small class="text-muted">All service providers are contractually obligated to protect your data and use it only for specified purposes.</small></p>

                        <h4 class="h5 mt-4 mb-3">4.2 Third-Party Applications</h4>
                        <p>If you use SIGNula to authenticate with third-party applications:</p>
                        <ul>
                            <li>We share only the information necessary for authentication</li>
                            <li>You can review and revoke access at any time in your settings</li>
                            <li>Third-party applications have their own privacy policies</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">4.3 Business Transfers</h4>
                        <p>
                            In the event of a merger, acquisition, or sale of assets, your information may be transferred to the acquiring entity. We will notify you before your information is transferred and becomes subject to a different privacy policy.
                        </p>

                        <h4 class="h5 mt-4 mb-3">4.4 Legal Requirements</h4>
                        <p>We may disclose your information when required by law or in response to:</p>
                        <ul>
                            <li>Valid legal requests (subpoenas, court orders)</li>
                            <li>National security or law enforcement requirements</li>
                            <li>Protection of our rights, property, or safety</li>
                            <li>Emergency situations involving danger to persons</li>
                        </ul>
                    </section>

                    <!-- 5. Your Rights -->
                    <section id="your-rights" class="mb-5">
                        <h2 class="h3 mb-3">5. Your Privacy Rights</h2>
                        <p>Depending on your location, you have the following rights:</p>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h5 class="card-title"><i class="fas fa-eye me-2 text-primary"></i>Right to Access</h5>
                                <p class="card-text">Request copies of your personal data</p>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h5 class="card-title"><i class="fas fa-edit me-2 text-primary"></i>Right to Rectification</h5>
                                <p class="card-text">Correct inaccurate or incomplete data</p>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h5 class="card-title"><i class="fas fa-trash me-2 text-primary"></i>Right to Erasure</h5>
                                <p class="card-text">Request deletion of your personal data ("right to be forgotten")</p>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h5 class="card-title"><i class="fas fa-ban me-2 text-primary"></i>Right to Restriction</h5>
                                <p class="card-text">Request limitation of processing of your data</p>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h5 class="card-title"><i class="fas fa-download me-2 text-primary"></i>Right to Data Portability</h5>
                                <p class="card-text">Receive your data in a machine-readable format</p>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h5 class="card-title"><i class="fas fa-times-circle me-2 text-primary"></i>Right to Object</h5>
                                <p class="card-text">Object to processing of your data for certain purposes</p>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h5 class="card-title"><i class="fas fa-user-slash me-2 text-primary"></i>Right to Opt-Out (CCPA)</h5>
                                <p class="card-text">California residents can opt-out of the sale of personal information</p>
                            </div>
                        </div>

                        <h4 class="h5 mt-4 mb-3">Exercising Your Rights</h4>
                        <p>To exercise any of these rights:</p>
                        <ul>
                            <li>Visit your account settings at <a href="https://signula.id/settings/privacy">Settings → Privacy</a></li>
                            <li>Email us at <a href="mailto:privacy@signula.com">privacy@signula.com</a></li>
                            <li>Use our <a href="/contact">contact form</a></li>
                        </ul>
                        <p><small class="text-muted">We will respond to your request within 30 days (or as required by applicable law).</small></p>
                    </section>

                    <!-- 6. Data Security -->
                    <section id="data-security" class="mb-5">
                        <h2 class="h3 mb-3">6. Data Security</h2>
                        <p>We implement industry-standard security measures to protect your data:</p>

                        <h4 class="h5 mt-4 mb-3">Technical Measures</h4>
                        <ul>
                            <li><strong>Encryption:</strong> AES-256-CBC encryption for sensitive data at rest</li>
                            <li><strong>TLS/SSL:</strong> All data transmitted over HTTPS</li>
                            <li><strong>Password Hashing:</strong> Argon2id algorithm for password storage</li>
                            <li><strong>Access Controls:</strong> Role-based access control (RBAC)</li>
                            <li><strong>Regular Audits:</strong> Security audits and penetration testing</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">Organizational Measures</h4>
                        <ul>
                            <li>Employee training on data protection</li>
                            <li>Confidentiality agreements with staff</li>
                            <li>Incident response procedures</li>
                            <li>Regular security policy reviews</li>
                        </ul>

                        <div class="alert alert-info">
                            <i class="fas fa-shield-alt me-2"></i>
                            While we strive to protect your information, no security system is impenetrable. We cannot guarantee absolute security.
                        </div>
                    </section>

                    <!-- 7. International Transfers -->
                    <section id="international-transfers" class="mb-5">
                        <h2 class="h3 mb-3">7. International Data Transfers</h2>
                        <p>
                            Your information may be transferred to and processed in countries other than your country of residence. We ensure appropriate safeguards are in place:
                        </p>
                        <ul>
                            <li>Standard Contractual Clauses (SCCs) approved by the European Commission</li>
                            <li>Adequacy decisions by relevant data protection authorities</li>
                            <li>Privacy Shield certification (where applicable)</li>
                            <li>Other lawful transfer mechanisms</li>
                        </ul>
                    </section>

                    <!-- 8. Data Retention -->
                    <section id="retention" class="mb-5">
                        <h2 class="h3 mb-3">8. Data Retention</h2>
                        <p>We retain your personal information for as long as necessary to:</p>
                        <ul>
                            <li>Provide our services to you</li>
                            <li>Comply with legal obligations</li>
                            <li>Resolve disputes</li>
                            <li>Enforce our agreements</li>
                        </ul>

                        <h4 class="h5 mt-4 mb-3">Retention Periods</h4>
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Data Type</th>
                                    <th>Retention Period</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Account Information</td>
                                    <td>Duration of account + 30 days after deletion</td>
                                </tr>
                                <tr>
                                    <td>Activity Logs</td>
                                    <td>Free: 30 days | Basic: 90 days | Premium: 1 year | Enterprise: Custom</td>
                                </tr>
                                <tr>
                                    <td>Security Logs</td>
                                    <td>Minimum 1 year, up to 7 years for compliance</td>
                                </tr>
                                <tr>
                                    <td>Contact Form Submissions</td>
                                    <td>3 years from last contact</td>
                                </tr>
                                <tr>
                                    <td>Payment Records</td>
                                    <td>7 years (tax and accounting requirements)</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>

                    <!-- 9. Cookies -->
                    <section id="cookies" class="mb-5">
                        <h2 class="h3 mb-3">9. Cookies and Tracking</h2>
                        <p>
                            We use cookies and similar tracking technologies to enhance your experience. For detailed information, please see our <a href="/legal/cookies">Cookie Policy</a>.
                        </p>
                    </section>

                    <!-- 10. Children's Privacy -->
                    <section id="children" class="mb-5">
                        <h2 class="h3 mb-3">10. Children's Privacy</h2>
                        <p>
                            SIGNula is not intended for children under 13 years of age (or 16 in the European Economic Area). We do not knowingly collect personal information from children.
                        </p>
                        <p>
                            If you believe we have collected information from a child, please contact us immediately at <a href="mailto:privacy@signula.com">privacy@signula.com</a>, and we will delete it promptly.
                        </p>
                    </section>

                    <!-- 11. Changes to Policy -->
                    <section id="changes" class="mb-5">
                        <h2 class="h3 mb-3">11. Changes to This Policy</h2>
                        <p>
                            We may update this Privacy Policy from time to time. When we make material changes, we will:
                        </p>
                        <ul>
                            <li>Update the "Last Updated" date at the top of this policy</li>
                            <li>Notify you via email (if you have an account)</li>
                            <li>Display a prominent notice on our website</li>
                            <li>Require your consent if legally required</li>
                        </ul>
                        <p>
                            Continued use of our services after changes constitutes acceptance of the updated policy.
                        </p>
                    </section>

                    <!-- 12. Contact Us -->
                    <section id="contact" class="mb-5">
                        <h2 class="h3 mb-3">12. Contact Us</h2>
                        <p>For questions about this Privacy Policy or to exercise your rights:</p>

                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Data Protection Officer</h5>
                                <p class="mb-2"><strong>Email:</strong> <a href="mailto:privacy@signula.com">privacy@signula.com</a></p>
                                <p class="mb-2"><strong>Mail:</strong> MWBMPartners, Data Protection Officer, [Address]</p>
                                <p class="mb-0"><strong>Contact Form:</strong> <a href="/contact?type=privacy">Privacy Inquiry Form</a></p>
                            </div>
                        </div>

                        <div class="alert alert-info mt-4">
                            <h5 class="alert-heading">Supervisory Authority</h5>
                            <p class="mb-0">
                                If you are in the EEA, you have the right to lodge a complaint with your local data protection authority. For a list of authorities, visit: <a href="https://edpb.europa.eu/about-edpb/board/members_en" target="_blank" rel="noopener">https://edpb.europa.eu</a>
                            </p>
                        </div>
                    </section>

                    <!-- Legal Disclaimer -->
                    <div class="alert alert-warning mt-5">
                        <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Legal Review Required</h5>
                        <p class="mb-0">
                            <strong>IMPORTANT:</strong> This privacy policy is a template and must be reviewed and approved by qualified legal counsel before production use. Laws vary by jurisdiction, and this template may not address all legal requirements for your specific situation.
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
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
