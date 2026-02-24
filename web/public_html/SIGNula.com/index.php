<?php
/**
 * ============================================================================
 * 🏠 SIGNula Marketing Homepage
 * ============================================================================
 * Public-facing landing page for SIGNula.com
 *
 * @package    SIGNula
 * @subpackage Marketing
 * @version    1.0.0
 * ============================================================================
 */

// Page configuration
$pageTitle = 'SIGNula - Universal Authentication System | Secure Single Sign-On';
$metaDescription = 'SIGNula provides secure, universal single sign-on authentication for web and mobile applications. Support for OAuth, MFA, PassKeys, WebAuthn, and more.';
$currentPage = 'home';
$ogImage = '/assets/images/og-home.png';

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
                <h1 class="hero-title">
                    One Account.<br>Infinite Possibilities.
                </h1>
                <p class="hero-subtitle">
                    Universal authentication system providing secure, seamless single sign-on across all your applications and services.
                </p>
                <div class="hero-cta">
                    <a href="https://signula.id/register" class="btn btn-light btn-lg px-5 py-3">
                        <i class="fas fa-rocket me-2"></i>Get Started Free
                    </a>
                    <a href="/docs/getting-started" class="btn btn-outline-light btn-lg px-5 py-3">
                        <i class="fas fa-book me-2"></i>View Documentation
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     KEY FEATURES SECTION
     ======================================================================== -->
<section class="section-padding bg-light">
    <div class="container">
        <!-- Section Header -->
        <div class="row mb-5">
            <div class="col-12">
                <h2 class="section-title gradient-text">Powerful Features</h2>
                <p class="section-subtitle">
                    Everything you need for secure, modern authentication
                </p>
            </div>
        </div>

        <!-- Feature Cards -->
        <div class="row g-4">
            <!-- Feature 1: Universal Authentication -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-globe"></i>
                    </div>
                    <h3 class="feature-title">Universal Authentication</h3>
                    <p class="feature-description">
                        One account for all your services. Link Google, Microsoft, Apple, Facebook, LinkedIn, and more.
                    </p>
                </div>
            </div>

            <!-- Feature 2: Multi-Factor Authentication -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h3 class="feature-title">Multi-Factor Authentication</h3>
                    <p class="feature-description">
                        Advanced MFA with authenticator apps, biometrics, PassKeys, and password-less login options.
                    </p>
                </div>
            </div>

            <!-- Feature 3: PassKey Support -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-key"></i>
                    </div>
                    <h3 class="feature-title">PassKey & WebAuthn</h3>
                    <p class="feature-description">
                        Next-generation authentication with PassKeys, WebAuthn, and biometric login support.
                    </p>
                </div>
            </div>

            <!-- Feature 4: Developer Friendly API -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-code"></i>
                    </div>
                    <h3 class="feature-title">Developer-Friendly API</h3>
                    <p class="feature-description">
                        RESTful API with comprehensive documentation. Easy integration with web and mobile apps.
                    </p>
                </div>
            </div>

            <!-- Feature 5: Enterprise Security -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h3 class="feature-title">Enterprise Security</h3>
                    <p class="feature-description">
                        Bank-level encryption, activity logging, IP tracking, and comprehensive audit trails.
                    </p>
                </div>
            </div>

            <!-- Feature 6: Cross-Platform -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <h3 class="feature-title">Cross-Platform</h3>
                    <p class="feature-description">
                        Works seamlessly across web browsers, iOS, Android, and native applications.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     HOW IT WORKS SECTION
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <!-- Section Header -->
        <div class="row mb-5">
            <div class="col-12">
                <h2 class="section-title gradient-text">How It Works</h2>
                <p class="section-subtitle">
                    Get started in minutes with our simple integration process
                </p>
            </div>
        </div>

        <!-- Steps -->
        <div class="row g-4">
            <!-- Step 1 -->
            <div class="col-lg-3 col-md-6 text-center">
                <div class="mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle"
                         style="width: 80px; height: 80px; font-size: 2rem; font-weight: 700;">
                        1
                    </div>
                </div>
                <h4 class="mb-3">Create Account</h4>
                <p class="text-secondary">
                    Sign up for a free SIGNula account in seconds. No credit card required.
                </p>
            </div>

            <!-- Step 2 -->
            <div class="col-lg-3 col-md-6 text-center">
                <div class="mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle"
                         style="width: 80px; height: 80px; font-size: 2rem; font-weight: 700;">
                        2
                    </div>
                </div>
                <h4 class="mb-3">Link Services</h4>
                <p class="text-secondary">
                    Connect your Google, Microsoft, Apple, or other third-party accounts.
                </p>
            </div>

            <!-- Step 3 -->
            <div class="col-lg-3 col-md-6 text-center">
                <div class="mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle"
                         style="width: 80px; height: 80px; font-size: 2rem; font-weight: 700;">
                        3
                    </div>
                </div>
                <h4 class="mb-3">Integrate Apps</h4>
                <p class="text-secondary">
                    Use our API to integrate SIGNula with your applications and services.
                </p>
            </div>

            <!-- Step 4 -->
            <div class="col-lg-3 col-md-6 text-center">
                <div class="mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle"
                         style="width: 80px; height: 80px; font-size: 2rem; font-weight: 700;">
                        4
                    </div>
                </div>
                <h4 class="mb-3">Start Using</h4>
                <p class="text-secondary">
                    Enjoy seamless authentication across all your applications and devices.
                </p>
            </div>
        </div>

        <!-- CTA -->
        <div class="row mt-5">
            <div class="col-12 text-center">
                <a href="https://signula.id/register" class="btn btn-primary btn-lg px-5 py-3">
                    <i class="fas fa-user-plus me-2"></i>Create Your Account
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     SUPPORTED PROVIDERS SECTION
     ======================================================================== -->
<section class="section-padding bg-light">
    <div class="container">
        <!-- Section Header -->
        <div class="row mb-5">
            <div class="col-12">
                <h2 class="section-title gradient-text">Supported Providers</h2>
                <p class="section-subtitle">
                    Connect with your favorite services and platforms
                </p>
            </div>
        </div>

        <!-- Provider Icons -->
        <div class="row g-4 align-items-center justify-content-center text-center">
            <div class="col-lg-2 col-md-3 col-4">
                <i class="fab fa-google fa-4x" style="color: #4285F4;"></i>
                <p class="mt-3 mb-0 fw-semibold">Google</p>
            </div>
            <div class="col-lg-2 col-md-3 col-4">
                <i class="fab fa-microsoft fa-4x" style="color: #00A4EF;"></i>
                <p class="mt-3 mb-0 fw-semibold">Microsoft</p>
            </div>
            <div class="col-lg-2 col-md-3 col-4">
                <i class="fab fa-apple fa-4x" style="color: #000000;"></i>
                <p class="mt-3 mb-0 fw-semibold">Apple</p>
            </div>
            <div class="col-lg-2 col-md-3 col-4">
                <i class="fab fa-facebook fa-4x" style="color: #1877F2;"></i>
                <p class="mt-3 mb-0 fw-semibold">Facebook</p>
            </div>
            <div class="col-lg-2 col-md-3 col-4">
                <i class="fab fa-linkedin fa-4x" style="color: #0A66C2;"></i>
                <p class="mt-3 mb-0 fw-semibold">LinkedIn</p>
            </div>
            <div class="col-lg-2 col-md-3 col-4">
                <i class="fas fa-ellipsis-h fa-4x text-muted"></i>
                <p class="mt-3 mb-0 fw-semibold text-muted">And More</p>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     SECURITY SECTION
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <div class="row align-items-center">
            <!-- Security Content -->
            <div class="col-lg-6 mb-4 mb-lg-0">
                <h2 class="display-5 fw-bold mb-4">
                    <span class="gradient-text">Security First</span>
                </h2>
                <p class="lead text-secondary mb-4">
                    Your security and privacy are our top priorities. SIGNula uses industry-leading security practices to protect your data.
                </p>
                <ul class="list-unstyled">
                    <li class="mb-3">
                        <i class="fas fa-check-circle text-success me-3"></i>
                        <strong>End-to-End Encryption</strong> - All data encrypted in transit and at rest
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-check-circle text-success me-3"></i>
                        <strong>Activity Logging</strong> - Comprehensive audit trails and IP tracking
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-check-circle text-success me-3"></i>
                        <strong>GDPR & CCPA Compliant</strong> - Full compliance with global privacy laws
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-check-circle text-success me-3"></i>
                        <strong>Regular Security Audits</strong> - Continuous monitoring and testing
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-check-circle text-success me-3"></i>
                        <strong>Two-Factor Authentication</strong> - Multiple MFA options available
                    </li>
                </ul>
                <a href="/docs/security" class="btn btn-outline-primary mt-3">
                    <i class="fas fa-shield-alt me-2"></i>Learn More About Security
                </a>
            </div>

            <!-- Security Illustration -->
            <div class="col-lg-6 text-center">
                <i class="fas fa-shield-alt" style="font-size: 15rem; color: var(--primary-color); opacity: 0.1;"></i>
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
                <h2 class="display-4 fw-bold mb-4">Ready to Get Started?</h2>
                <p class="lead mb-5" style="opacity: 0.9;">
                    Join thousands of users who trust SIGNula for their authentication needs. Create your free account today.
                </p>
                <div class="d-flex gap-3 justify-content-center flex-wrap">
                    <a href="https://signula.id/register" class="btn btn-light btn-lg px-5 py-3">
                        <i class="fas fa-user-plus me-2"></i>Create Free Account
                    </a>
                    <a href="/pricing" class="btn btn-outline-light btn-lg px-5 py-3">
                        <i class="fas fa-dollar-sign me-2"></i>View Pricing
                    </a>
                </div>
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
