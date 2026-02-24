<?php
/**
 * ============================================================================
 * 📖 SIGNula About Page
 * ============================================================================
 * Information about SIGNula, our mission, and values
 *
 * @package    SIGNula
 * @subpackage Marketing
 * @version    1.0.0
 * ============================================================================
 */

// Page configuration
$pageTitle = 'About SIGNula - Universal Authentication System';
$metaDescription = 'Learn about SIGNula, our mission to provide secure, universal authentication, and our commitment to user privacy and security.';
$currentPage = 'about';
$ogImage = '/assets/images/og-about.png';

// Include header component
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>

<!-- ========================================================================
     HERO SECTION
     ======================================================================== -->
<section class="hero-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10 text-center">
                <h1 class="hero-title">About SIGNula</h1>
                <p class="hero-subtitle">
                    Building the future of secure, universal authentication
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     MISSION SECTION
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="text-center mb-5">
                    <h2 class="display-5 fw-bold mb-4">
                        <span class="gradient-text">Our Mission</span>
                    </h2>
                    <p class="lead text-secondary">
                        To provide a secure, universal authentication system that simplifies access while maintaining the highest standards of security and privacy.
                    </p>
                </div>

                <div class="row g-4 mt-4">
                    <div class="col-md-12">
                        <p class="mb-4">
                            In today's digital world, managing multiple accounts across various services has become increasingly complex and challenging. Users are forced to remember dozens of passwords, manage multiple authentication methods, and navigate different security protocols for each service they use.
                        </p>
                        <p class="mb-4">
                            <strong>SIGNula</strong> was created to solve this problem. We believe that authentication should be simple, secure, and universal. One account, managed securely, that provides seamless access to all your applications and services.
                        </p>
                        <p class="mb-0">
                            Our platform combines cutting-edge security technologies with user-friendly design to deliver an authentication solution that works for everyone—from individual users to enterprise organizations.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     CORE VALUES SECTION
     ======================================================================== -->
<section class="section-padding bg-light">
    <div class="container">
        <!-- Section Header -->
        <div class="row mb-5">
            <div class="col-12">
                <h2 class="section-title gradient-text">Our Core Values</h2>
                <p class="section-subtitle">
                    The principles that guide everything we do
                </p>
            </div>
        </div>

        <!-- Values -->
        <div class="row g-4">
            <!-- Value 1: Security -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h3 class="feature-title">Security First</h3>
                    <p class="feature-description">
                        We prioritize security above all else. Every decision we make is evaluated through the lens of protecting our users' data and privacy.
                    </p>
                </div>
            </div>

            <!-- Value 2: Privacy -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h3 class="feature-title">Privacy by Design</h3>
                    <p class="feature-description">
                        Your data belongs to you. We collect only what's necessary, encrypt everything, and give you full control over your information.
                    </p>
                </div>
            </div>

            <!-- Value 3: Transparency -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="fas fa-eye"></i>
                    </div>
                    <h3 class="feature-title">Transparency</h3>
                    <p class="feature-description">
                        We believe in open communication. Our security practices, data handling, and policies are clearly documented and accessible.
                    </p>
                </div>
            </div>

            <!-- Value 4: User Experience -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="fas fa-heart"></i>
                    </div>
                    <h3 class="feature-title">User Experience</h3>
                    <p class="feature-description">
                        Security shouldn't be complicated. We design intuitive interfaces that make secure authentication accessible to everyone.
                    </p>
                </div>
            </div>

            <!-- Value 5: Innovation -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="fas fa-lightbulb"></i>
                    </div>
                    <h3 class="feature-title">Innovation</h3>
                    <p class="feature-description">
                        We continuously adopt and integrate the latest authentication technologies, from PassKeys to biometrics and beyond.
                    </p>
                </div>
            </div>

            <!-- Value 6: Reliability -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="feature-title">Reliability</h3>
                    <p class="feature-description">
                        You can count on us. We maintain high uptime, fast response times, and robust infrastructure to ensure you're always connected.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     WHAT MAKES US DIFFERENT
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="text-center mb-5">
                    <h2 class="display-5 fw-bold mb-4">
                        <span class="gradient-text">What Makes Us Different</span>
                    </h2>
                </div>

                <div class="row g-4">
                    <!-- Difference 1 -->
                    <div class="col-md-6">
                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center"
                                     style="width: 50px; height: 50px;">
                                    <i class="fas fa-check"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h4 class="mb-2">True Universal Access</h4>
                                <p class="text-secondary mb-0">
                                    Unlike traditional SSO solutions, SIGNula works across web, mobile, and native applications seamlessly.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Difference 2 -->
                    <div class="col-md-6">
                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center"
                                     style="width: 50px; height: 50px;">
                                    <i class="fas fa-check"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h4 class="mb-2">Developer-Friendly</h4>
                                <p class="text-secondary mb-0">
                                    Built by developers, for developers. Our API is well-documented, RESTful, and easy to integrate.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Difference 3 -->
                    <div class="col-md-6">
                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center"
                                     style="width: 50px; height: 50px;">
                                    <i class="fas fa-check"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h4 class="mb-2">Multiple Authentication Methods</h4>
                                <p class="text-secondary mb-0">
                                    Support for traditional passwords, OAuth providers, PassKeys, biometrics, and password-less login.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Difference 4 -->
                    <div class="col-md-6">
                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center"
                                     style="width: 50px; height: 50px;">
                                    <i class="fas fa-check"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h4 class="mb-2">Enterprise-Ready</h4>
                                <p class="text-secondary mb-0">
                                    From individual users to large organizations, SIGNula scales to meet your needs with flexible pricing.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     TECHNOLOGY SECTION
     ======================================================================== -->
<section class="section-padding bg-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="text-center mb-5">
                    <h2 class="display-5 fw-bold mb-4">
                        <span class="gradient-text">Built on Modern Technology</span>
                    </h2>
                    <p class="lead text-secondary">
                        SIGNula leverages cutting-edge technologies to provide secure, reliable authentication
                    </p>
                </div>

                <div class="row g-4">
                    <div class="col-md-4 text-center">
                        <div class="mb-3">
                            <i class="fas fa-key fa-3x text-primary"></i>
                        </div>
                        <h5 class="mb-2">PassKeys & WebAuthn</h5>
                        <p class="text-secondary small mb-0">
                            Next-generation passwordless authentication using FIDO2 standards
                        </p>
                    </div>

                    <div class="col-md-4 text-center">
                        <div class="mb-3">
                            <i class="fas fa-fingerprint fa-3x text-primary"></i>
                        </div>
                        <h5 class="mb-2">Biometric Support</h5>
                        <p class="text-secondary small mb-0">
                            FaceID, TouchID, Windows Hello, and Android biometrics
                        </p>
                    </div>

                    <div class="col-md-4 text-center">
                        <div class="mb-3">
                            <i class="fas fa-lock fa-3x text-primary"></i>
                        </div>
                        <h5 class="mb-2">End-to-End Encryption</h5>
                        <p class="text-secondary small mb-0">
                            Industry-standard encryption for all data in transit and at rest
                        </p>
                    </div>

                    <div class="col-md-4 text-center">
                        <div class="mb-3">
                            <i class="fas fa-users-cog fa-3x text-primary"></i>
                        </div>
                        <h5 class="mb-2">OAuth 2.0</h5>
                        <p class="text-secondary small mb-0">
                            Support for Google, Microsoft, Apple, Facebook, LinkedIn, and more
                        </p>
                    </div>

                    <div class="col-md-4 text-center">
                        <div class="mb-3">
                            <i class="fas fa-mobile-alt fa-3x text-primary"></i>
                        </div>
                        <h5 class="mb-2">Multi-Factor Auth</h5>
                        <p class="text-secondary small mb-0">
                            Authenticator apps, SMS, email codes, and push notifications
                        </p>
                    </div>

                    <div class="col-md-4 text-center">
                        <div class="mb-3">
                            <i class="fas fa-cloud fa-3x text-primary"></i>
                        </div>
                        <h5 class="mb-2">RESTful API</h5>
                        <p class="text-secondary small mb-0">
                            Simple, well-documented API for easy integration
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     TEAM SECTION (Placeholder)
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10 text-center">
                <h2 class="display-5 fw-bold mb-4">
                    <span class="gradient-text">Powered by MWBMPartners</span>
                </h2>
                <p class="lead text-secondary mb-5">
                    SIGNula is developed and maintained by MWBMPartners, a team dedicated to building secure, user-friendly authentication solutions.
                </p>
                <div class="d-flex gap-3 justify-content-center flex-wrap">
                    <a href="/contact" class="btn btn-primary btn-lg">
                        <i class="fas fa-envelope me-2"></i>Contact Us
                    </a>
                    <a href="/docs" class="btn btn-outline-primary btn-lg">
                        <i class="fas fa-book me-2"></i>View Documentation
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     CTA SECTION
     ======================================================================== -->
<section class="section-padding" style="background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);">
    <div class="container">
        <div class="row justify-content-center text-center text-white">
            <div class="col-lg-8">
                <h2 class="display-4 fw-bold mb-4">Join Us on This Journey</h2>
                <p class="lead mb-5" style="opacity: 0.9;">
                    Be part of the future of authentication. Create your free SIGNula account today.
                </p>
                <a href="https://signula.id/register" class="btn btn-light btn-lg px-5 py-3">
                    <i class="fas fa-user-plus me-2"></i>Get Started Free
                </a>
                <p class="mt-4 mb-0" style="opacity: 0.8;">
                    <small>No credit card required. Free tier available forever.</small>
                </p>
            </div>
        </div>
    </div>
</section>

<?php
// Include footer component
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
