<?php
/**
 * ============================================================================
 * 📚 SIGNula Documentation Home
 * ============================================================================
 * Main documentation portal landing page
 *
 * @package    SIGNula
 * @subpackage Documentation
 * @version    1.0.0
 * ============================================================================
 */

// Page configuration
$pageTitle = 'Documentation - SIGNula Universal Authentication';
$metaDescription = 'Complete documentation for SIGNula universal authentication system. API reference, guides, tutorials, and best practices.';
$currentPage = 'docs';
$currentDoc = 'index';
$ogImage = '/assets/images/og-docs.png';

// Include header component
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>

<!-- Hero Section -->
<section class="bg-light py-5">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h1 class="display-4 fw-bold mb-3">
                    <i class="fas fa-book me-3 text-primary"></i>Documentation
                </h1>
                <p class="lead text-secondary mb-4">
                    Everything you need to integrate SIGNula into your applications
                </p>
                <div class="d-flex gap-3 flex-wrap">
                    <a href="/docs/getting-started" class="btn btn-primary btn-lg">
                        <i class="fas fa-rocket me-2"></i>Get Started
                    </a>
                    <a href="/docs/api-reference" class="btn btn-outline-primary btn-lg">
                        <i class="fas fa-code me-2"></i>API Reference
                    </a>
                </div>
            </div>
            <div class="col-lg-4 d-none d-lg-block text-center">
                <i class="fas fa-file-code" style="font-size: 10rem; color: var(--primary-color); opacity: 0.1;"></i>
            </div>
        </div>
    </div>
</section>

<!-- Main Content -->
<section class="section-padding">
    <div class="container">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-3">
                <?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'docs-sidebar.php'; ?>
            </div>

            <!-- Content -->
            <div class="col-lg-9">
                <!-- Quick Links -->
                <div class="row g-4 mb-5">
                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-primary text-white rounded p-3 me-3">
                                        <i class="fas fa-rocket fa-2x"></i>
                                    </div>
                                    <h3 class="h5 mb-0">Getting Started</h3>
                                </div>
                                <p class="text-secondary mb-3">
                                    New to SIGNula? Start here with our quick start guide and learn how to create your first integration.
                                </p>
                                <a href="/docs/getting-started" class="btn btn-outline-primary">
                                    Quick Start Guide <i class="fas fa-arrow-right ms-2"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-success text-white rounded p-3 me-3">
                                        <i class="fas fa-code fa-2x"></i>
                                    </div>
                                    <h3 class="h5 mb-0">API Reference</h3>
                                </div>
                                <p class="text-secondary mb-3">
                                    Complete API documentation with endpoints, parameters, examples, and response formats.
                                </p>
                                <a href="/docs/api-reference" class="btn btn-outline-success">
                                    View API Docs <i class="fas fa-arrow-right ms-2"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-info text-white rounded p-3 me-3">
                                        <i class="fas fa-plug fa-2x"></i>
                                    </div>
                                    <h3 class="h5 mb-0">Integration Guide</h3>
                                </div>
                                <p class="text-secondary mb-3">
                                    Step-by-step guides for integrating SIGNula with popular frameworks and platforms.
                                </p>
                                <a href="/docs/integration-guide" class="btn btn-outline-info">
                                    Integration Guide <i class="fas fa-arrow-right ms-2"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-danger text-white rounded p-3 me-3">
                                        <i class="fas fa-shield-alt fa-2x"></i>
                                    </div>
                                    <h3 class="h5 mb-0">Security</h3>
                                </div>
                                <p class="text-secondary mb-3">
                                    Security best practices, guidelines, and recommendations for implementing authentication.
                                </p>
                                <a href="/docs/security" class="btn btn-outline-danger">
                                    Security Guide <i class="fas fa-arrow-right ms-2"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Popular Topics -->
                <div class="mb-5">
                    <h2 class="h3 mb-4">Popular Topics</h2>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <a href="/docs/getting-started#authentication" class="text-decoration-none">
                                <div class="p-3 bg-light rounded hover-shadow">
                                    <i class="fas fa-key text-primary me-2"></i>
                                    <strong>Password Authentication</strong>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="/docs/getting-started#mfa" class="text-decoration-none">
                                <div class="p-3 bg-light rounded hover-shadow">
                                    <i class="fas fa-mobile-alt text-primary me-2"></i>
                                    <strong>Multi-Factor Authentication</strong>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="/docs/api-reference#oauth" class="text-decoration-none">
                                <div class="p-3 bg-light rounded hover-shadow">
                                    <i class="fas fa-link text-primary me-2"></i>
                                    <strong>OAuth Account Linking</strong>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="/docs/security#passkeys" class="text-decoration-none">
                                <div class="p-3 bg-light rounded hover-shadow">
                                    <i class="fas fa-fingerprint text-primary me-2"></i>
                                    <strong>PassKeys & WebAuthn</strong>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- FAQ Preview -->
                <div>
                    <h2 class="h3 mb-4">Frequently Asked Questions</h2>
                    <div class="accordion" id="faqPreview">
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                    How do I get API credentials?
                                </button>
                            </h3>
                            <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqPreview">
                                <div class="accordion-body">
                                    Create a free account at <a href="https://signula.id/register">signula.id/register</a>, then navigate to Settings → API to generate your API keys.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                    Is SIGNula free to use?
                                </button>
                            </h2>
                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqPreview">
                                <div class="accordion-body">
                                    Yes! We offer a free tier with basic features. See our <a href="/pricing">pricing page</a> for details on paid tiers with advanced features.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <a href="/docs/faq" class="btn btn-link">View All FAQs <i class="fas fa-arrow-right ms-2"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
.hover-shadow {
    transition: all var(--transition-fast);
}

.hover-shadow:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
</style>

<?php
// Include footer component
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
