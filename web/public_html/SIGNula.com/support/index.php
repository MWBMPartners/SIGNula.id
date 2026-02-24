<?php
/**
 * Support Home Page
 * Knowledge base, FAQs, and ticket submission portal
 * 
 * @package SIGNula
 * @version 1.0.0
 */

// Page configuration
$pageTitle = 'Support - SIGNula';
$metaDescription = 'Get help with SIGNula. Browse our knowledge base, FAQs, or submit a support ticket.';
$currentPage = 'support';

// Include database configuration
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

// Get support categories
$categoriesQuery = "SELECT * FROM tblSupportCategories WHERE isActive = TRUE ORDER BY displayOrder";
$categoriesResult = $mysqli->query($categoriesQuery);
$categories = $categoriesResult->fetch_all(MYSQLI_ASSOC);

// Include public header
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>

<section class="section-padding bg-light">
    <div class="container">
        <!-- Hero Section -->
        <div class="row mb-5">
            <div class="col-lg-8 mx-auto text-center">
                <h1 class="display-4 fw-bold mb-3">How can we help you?</h1>
                <p class="lead text-muted">Search our knowledge base or get in touch with our support team.</p>
                
                <!-- Search Box -->
                <div class="mt-4">
                    <form action="/support/search" method="GET" class="d-flex gap-2 mx-auto" style="max-width: 600px;">
                        <input type="text" class="form-control form-control-lg" name="q" placeholder="Search for help..." required>
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mb-5">
            <div class="col-md-4 mb-3">
                <div class="card h-100 text-center shadow-sm hover-lift">
                    <div class="card-body">
                        <div class="display-4 text-primary mb-3">
                            <i class="fas fa-book"></i>
                        </div>
                        <h3 class="h5 card-title">Knowledge Base</h3>
                        <p class="card-text text-muted">Browse articles and guides</p>
                        <a href="#knowledge-base" class="btn btn-outline-primary">Browse Articles</a>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <div class="card h-100 text-center shadow-sm hover-lift">
                    <div class="card-body">
                        <div class="display-4 text-success mb-3">
                            <i class="fas fa-comments"></i>
                        </div>
                        <h3 class="h5 card-title">Community Forum</h3>
                        <p class="card-text text-muted">Ask questions and share ideas</p>
                        <a href="/community" class="btn btn-outline-success">Visit Forum</a>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <div class="card h-100 text-center shadow-sm hover-lift">
                    <div class="card-body">
                        <div class="display-4 text-info mb-3">
                            <i class="fas fa-ticket-alt"></i>
                        </div>
                        <h3 class="h5 card-title">Submit a Ticket</h3>
                        <p class="card-text text-muted">Get personalized support</p>
                        <a href="https://signula.id/support/ticket" class="btn btn-outline-info">Create Ticket</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Knowledge Base Categories -->
        <div id="knowledge-base" class="mb-5">
            <h2 class="h3 text-center mb-4">Knowledge Base</h2>
            <div class="row">
                <?php foreach ($categories as $category): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card h-100 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-start mb-3">
                                    <div class="display-6 text-primary me-3">
                                        <i class="fas <?php echo htmlspecialchars($category['icon'] ?? 'fa-folder'); ?>"></i>
                                    </div>
                                    <div>
                                        <h3 class="h5 card-title mb-2"><?php echo htmlspecialchars($category['categoryName']); ?></h3>
                                        <p class="card-text text-muted small"><?php echo htmlspecialchars($category['description']); ?></p>
                                        <a href="/support/category/<?php echo htmlspecialchars($category['categorySlug']); ?>" class="stretched-link">
                                            View <?php echo $category['articleCount']; ?> articles <i class="fas fa-arrow-right ms-1"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Popular Articles -->
        <div class="mb-5">
            <h2 class="h3 text-center mb-4">Popular Articles</h2>
            <div class="row">
                <div class="col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="h6 card-title">
                                <a href="/docs/getting-started" class="text-decoration-none">
                                    <i class="far fa-file-alt me-2 text-primary"></i>Getting Started with SIGNula
                                </a>
                            </h4>
                            <small class="text-muted">Learn how to set up your SIGNula account in minutes</small>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="h6 card-title">
                                <a href="/docs/api-reference" class="text-decoration-none">
                                    <i class="far fa-file-alt me-2 text-primary"></i>API Integration Guide
                                </a>
                            </h4>
                            <small class="text-muted">Integrate SIGNula authentication into your application</small>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="h6 card-title">
                                <a href="/docs/security#passkeys" class="text-decoration-none">
                                    <i class="far fa-file-alt me-2 text-primary"></i>Setting Up PassKeys
                                </a>
                            </h4>
                            <small class="text-muted">Enable passwordless authentication with PassKeys</small>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="h6 card-title">
                                <a href="/docs/security" class="text-decoration-none">
                                    <i class="far fa-file-alt me-2 text-primary"></i>Security Best Practices
                                </a>
                            </h4>
                            <small class="text-muted">Keep your account and integrations secure</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- FAQ Section -->
        <div class="mb-5">
            <h2 class="h3 text-center mb-4">Frequently Asked Questions</h2>
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="accordion" id="supportFAQ">
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                    How do I reset my password?
                                </button>
                            </h3>
                            <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#supportFAQ">
                                <div class="accordion-body">
                                    Visit the <a href="https://signula.id/forgot-password">password reset page</a>, enter your email, and follow the instructions sent to your inbox.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                    How can I contact support?
                                </button>
                            </h3>
                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#supportFAQ">
                                <div class="accordion-body">
                                    You can <a href="https://signula.id/support/ticket">submit a support ticket</a> or email us at <a href="mailto:support@signula.com">support@signula.com</a>. Premium and Enterprise customers can call our priority support line.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                    What are your support hours?
                                </button>
                            </h3>
                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#supportFAQ">
                                <div class="accordion-body">
                                    Our support team is available Monday-Friday, 9 AM - 6 PM EST. Premium and Enterprise customers have access to 24/7 emergency support.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                    Do you offer phone support?
                                </button>
                            </h3>
                            <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#supportFAQ">
                                <div class="accordion-body">
                                    Phone support is available for Premium and Enterprise plan customers. Free and Basic tier users can access email and ticket support.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                                    How long does it take to get a response?
                                </button>
                            </h3>
                            <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#supportFAQ">
                                <div class="accordion-body">
                                    Response times depend on your plan and ticket priority:
                                    <ul class="mt-2">
                                        <li><strong>Urgent:</strong> 4 hours</li>
                                        <li><strong>High:</strong> 24 hours</li>
                                        <li><strong>Normal:</strong> 48 hours</li>
                                        <li><strong>Low:</strong> 72 hours</li>
                                    </ul>
                                    Premium and Enterprise customers receive faster response times.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-center mt-4">
                        <a href="/docs/faq" class="btn btn-outline-primary">View All FAQs</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contact Support CTA -->
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="card bg-gradient text-white" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <div class="card-body text-center p-5">
                        <h2 class="h3 mb-3">Still need help?</h2>
                        <p class="mb-4">Can't find what you're looking for? Our support team is here to help.</p>
                        <div class="d-flex gap-3 justify-content-center flex-wrap">
                            <a href="https://signula.id/support/ticket" class="btn btn-light btn-lg">
                                <i class="fas fa-ticket-alt me-2"></i>Submit a Ticket
                            </a>
                            <a href="/contact" class="btn btn-outline-light btn-lg">
                                <i class="fas fa-envelope me-2"></i>Contact Us
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php'; ?>
