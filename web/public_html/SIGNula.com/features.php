<?php
/**
 * ============================================================================
 * ✨ SIGNula Features Page
 * ============================================================================
 * Comprehensive feature showcase with comparison table
 *
 * @package    SIGNula
 * @subpackage Marketing
 * @version    1.0.0
 * ============================================================================
 */

// Page configuration
$pageTitle = 'Features - SIGNula Universal Authentication System';
$metaDescription = 'Explore SIGNula\'s comprehensive authentication features including MFA, PassKeys, OAuth integration, biometric login, and enterprise security.';
$currentPage = 'features';
$ogImage = '/assets/images/og-features.png';

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
                <h1 class="hero-title">Powerful Features</h1>
                <p class="hero-subtitle">
                    Everything you need for modern, secure authentication
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     CORE AUTHENTICATION FEATURES
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <!-- Section Header -->
        <div class="row mb-5">
            <div class="col-12 text-center">
                <h2 class="display-5 fw-bold mb-3">
                    <span class="gradient-text">Core Authentication</span>
                </h2>
                <p class="lead text-secondary">
                    Multiple authentication methods to suit every use case
                </p>
            </div>
        </div>

        <!-- Feature Grid -->
        <div class="row g-4">
            <!-- Feature 1: Traditional Login -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h3 class="feature-title">Traditional Login</h3>
                    <p class="feature-description mb-3">
                        Secure password-based authentication with Argon2id hashing and industry-standard security practices.
                    </p>
                    <ul class="list-unstyled small text-secondary">
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Argon2id password hashing</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Brute force protection</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Account lockout after failed attempts</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Password strength requirements</li>
                    </ul>
                </div>
            </div>

            <!-- Feature 2: Multi-Factor Authentication -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h3 class="feature-title">Multi-Factor Authentication</h3>
                    <p class="feature-description mb-3">
                        Add an extra layer of security with TOTP authenticator apps and backup recovery codes.
                    </p>
                    <ul class="list-unstyled small text-secondary">
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Google Authenticator support</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Microsoft Authenticator support</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Backup recovery codes</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Push notification MFA (coming soon)</li>
                    </ul>
                </div>
            </div>

            <!-- Feature 3: PassKeys / WebAuthn -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="fas fa-key"></i>
                    </div>
                    <h3 class="feature-title">PassKeys & WebAuthn</h3>
                    <p class="feature-description mb-3">
                        Next-generation passwordless authentication using FIDO2/WebAuthn standards and biometrics.
                    </p>
                    <ul class="list-unstyled small text-secondary">
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>TouchID & FaceID support</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Windows Hello integration</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Android biometric support</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Hardware security keys</li>
                    </ul>
                </div>
            </div>

            <!-- Feature 4: Passwordless Email -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <h3 class="feature-title">Passwordless Email Login</h3>
                    <p class="feature-description mb-3">
                        Secure magic link authentication sent directly to your email. No passwords to remember.
                    </p>
                    <ul class="list-unstyled small text-secondary">
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>One-click login via email</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>15-minute token expiry</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Rate limiting protection</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Single-use tokens</li>
                    </ul>
                </div>
            </div>

            <!-- Feature 5: OAuth Integration -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="fas fa-link"></i>
                    </div>
                    <h3 class="feature-title">OAuth Account Linking</h3>
                    <p class="feature-description mb-3">
                        Connect your existing accounts from major providers for seamless single sign-on.
                    </p>
                    <ul class="list-unstyled small text-secondary">
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Google (Personal & Workspace)</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Microsoft (Personal & 365)</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Apple ID</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Facebook, LinkedIn, GitHub</li>
                    </ul>
                </div>
            </div>

            <!-- Feature 6: Biometric Support -->
            <div class="col-lg-4 col-md-6">
                <div class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="fas fa-fingerprint"></i>
                    </div>
                    <h3 class="feature-title">Biometric Authentication</h3>
                    <p class="feature-description mb-3">
                        Native biometric support across all major platforms and devices for secure, convenient login.
                    </p>
                    <ul class="list-unstyled small text-secondary">
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Fingerprint recognition</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Facial recognition</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Cross-platform support</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Hardware-backed security</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     DEVELOPER FEATURES
     ======================================================================== -->
<section class="section-padding bg-light">
    <div class="container">
        <!-- Section Header -->
        <div class="row mb-5">
            <div class="col-12 text-center">
                <h2 class="display-5 fw-bold mb-3">
                    <span class="gradient-text">Developer-Friendly API</span>
                </h2>
                <p class="lead text-secondary">
                    Built for developers, easy to integrate
                </p>
            </div>
        </div>

        <!-- API Features -->
        <div class="row g-4">
            <div class="col-lg-3 col-md-6">
                <div class="text-center">
                    <div class="mb-3">
                        <i class="fas fa-code fa-3x text-primary"></i>
                    </div>
                    <h5 class="mb-2">RESTful API</h5>
                    <p class="text-secondary small mb-0">
                        Clean, well-documented JSON API following REST principles
                    </p>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="text-center">
                    <div class="mb-3">
                        <i class="fas fa-book fa-3x text-primary"></i>
                    </div>
                    <h5 class="mb-2">Comprehensive Docs</h5>
                    <p class="text-secondary small mb-0">
                        Detailed documentation with code examples and guides
                    </p>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="text-center">
                    <div class="mb-3">
                        <i class="fas fa-puzzle-piece fa-3x text-primary"></i>
                    </div>
                    <h5 class="mb-2">Easy Integration</h5>
                    <p class="text-secondary small mb-0">
                        Simple SDK and libraries for popular frameworks
                    </p>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="text-center">
                    <div class="mb-3">
                        <i class="fas fa-tachometer-alt fa-3x text-primary"></i>
                    </div>
                    <h5 class="mb-2">Rate Limiting</h5>
                    <p class="text-secondary small mb-0">
                        Built-in rate limiting and throttling protection
                    </p>
                </div>
            </div>
        </div>

        <!-- API Example -->
        <div class="row mt-5">
            <div class="col-lg-8 mx-auto">
                <div class="card shadow-sm">
                    <div class="card-header bg-dark text-white">
                        <i class="fas fa-terminal me-2"></i>API Example
                    </div>
                    <div class="card-body bg-dark text-white" style="font-family: 'Courier New', monospace;">
<pre class="mb-0" style="color: #f8f9fa;"><code>POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "secure_password"
}

// Response
{
  "success": true,
  "data": {
    "user_id": 123,
    "session_token": "abc123..."
  }
}</code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     SECURITY FEATURES
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <!-- Section Header -->
        <div class="row mb-5">
            <div class="col-12 text-center">
                <h2 class="display-5 fw-bold mb-3">
                    <span class="gradient-text">Enterprise Security</span>
                </h2>
                <p class="lead text-secondary">
                    Bank-level security to protect your users
                </p>
            </div>
        </div>

        <!-- Security Features Grid -->
        <div class="row g-4">
            <div class="col-md-6">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center"
                             style="width: 50px; height: 50px;">
                            <i class="fas fa-lock"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h5 class="mb-2">End-to-End Encryption</h5>
                        <p class="text-secondary mb-0">
                            AES-256-CBC encryption for sensitive data, with secure key management and salting.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center"
                             style="width: 50px; height: 50px;">
                            <i class="fas fa-user-shield"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h5 class="mb-2">CSRF Protection</h5>
                        <p class="text-secondary mb-0">
                            All forms protected with CSRF tokens to prevent cross-site request forgery attacks.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center"
                             style="width: 50px; height: 50px;">
                            <i class="fas fa-database"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h5 class="mb-2">SQL Injection Prevention</h5>
                        <p class="text-secondary mb-0">
                            All database queries use prepared statements to prevent SQL injection vulnerabilities.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center"
                             style="width: 50px; height: 50px;">
                            <i class="fas fa-history"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h5 class="mb-2">Activity Logging</h5>
                        <p class="text-secondary mb-0">
                            Comprehensive logging of all user activities, login attempts, and security events.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center"
                             style="width: 50px; height: 50px;">
                            <i class="fas fa-ban"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h5 class="mb-2">Rate Limiting</h5>
                        <p class="text-secondary mb-0">
                            Protection against brute force attacks with intelligent rate limiting and throttling.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center"
                             style="width: 50px; height: 50px;">
                            <i class="fas fa-network-wired"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h5 class="mb-2">IP Tracking</h5>
                        <p class="text-secondary mb-0">
                            IPv4 and IPv6 support with geolocation tracking for security monitoring.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     COMPARISON TABLE
     ======================================================================== -->
<section class="section-padding bg-light">
    <div class="container">
        <!-- Section Header -->
        <div class="row mb-5">
            <div class="col-12 text-center">
                <h2 class="display-5 fw-bold mb-3">
                    <span class="gradient-text">Feature Comparison</span>
                </h2>
                <p class="lead text-secondary">
                    Compare SIGNula with traditional authentication solutions
                </p>
            </div>
        </div>

        <!-- Comparison Table -->
        <div class="row">
            <div class="col-12">
                <div class="table-responsive">
                    <table class="table table-bordered bg-white">
                        <thead class="table-primary">
                            <tr>
                                <th scope="col" style="width: 40%;">Feature</th>
                                <th scope="col" class="text-center" style="width: 20%;">Traditional Auth</th>
                                <th scope="col" class="text-center" style="width: 20%;">Basic SSO</th>
                                <th scope="col" class="text-center" style="width: 20%;">
                                    <strong>SIGNula</strong>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Password Authentication</strong></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>Multi-Factor Authentication</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>PassKeys / WebAuthn</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>Biometric Login</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>Passwordless Email Login</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>OAuth Provider Integration</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center">1-2 providers</td>
                                <td class="text-center">6+ providers</td>
                            </tr>
                            <tr>
                                <td><strong>Multi-Account OAuth Support</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>RESTful API</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center">Limited</td>
                                <td class="text-center">30+ endpoints</td>
                            </tr>
                            <tr>
                                <td><strong>Activity Logging</strong></td>
                                <td class="text-center">Basic</td>
                                <td class="text-center">Basic</td>
                                <td class="text-center">Comprehensive</td>
                            </tr>
                            <tr>
                                <td><strong>Cross-Platform Support</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>GDPR Compliance Tools</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center">Partial</td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr class="table-light">
                                <td><strong>Monthly Cost (per user)</strong></td>
                                <td class="text-center">Variable</td>
                                <td class="text-center">$5-15</td>
                                <td class="text-center"><strong>$0-10</strong></td>
                            </tr>
                        </tbody>
                    </table>
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
                <h2 class="display-4 fw-bold mb-4">Ready to Experience SIGNula?</h2>
                <p class="lead mb-5" style="opacity: 0.9;">
                    Get started with our free tier and explore all features. No credit card required.
                </p>
                <div class="d-flex gap-3 justify-content-center flex-wrap">
                    <a href="https://signula.id/register" class="btn btn-light btn-lg px-5 py-3">
                        <i class="fas fa-user-plus me-2"></i>Start Free Trial
                    </a>
                    <a href="/docs/getting-started" class="btn btn-outline-light btn-lg px-5 py-3">
                        <i class="fas fa-book me-2"></i>View Documentation
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
// Include footer component
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
