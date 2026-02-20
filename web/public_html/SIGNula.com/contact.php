<?php
/**
 * ============================================================================
 * 📧 SIGNula Contact Page
 * ============================================================================
 * Contact form with validation, CSRF protection, and email integration
 *
 * @package    SIGNula
 * @subpackage Marketing
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application (provides session, CSRF, security middleware, etc.)
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// Page configuration
$pageTitle = 'Contact Us - SIGNula Universal Authentication System';
$metaDescription = 'Get in touch with the SIGNula team. We\'re here to help with questions, support, and enterprise inquiries.';
$currentPage = 'contact';
$ogImage = '/assets/images/og-contact.png';

// Form state variables
$success = false;
$error = null;
$formData = [
    'name' => '',
    'email' => '',
    'subject' => '',
    'message' => '',
    'type' => 'general'
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ Security middleware — rate limiting + CAPTCHA verification
    $securityCheck = SecurityMiddleware::handleFormSubmission('contact');
    if (!$securityCheck['allowed']) {
        $error = $securityCheck['error'];
    } elseif (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        // 🛡️ Verify CSRF token
        $error = 'Invalid security token. Please try again.';
    } else {
        // Get and sanitize form data
        $formData['name'] = SecurityUtils::sanitizeString($_POST['name'] ?? '');
        $formData['email'] = SecurityUtils::sanitizeEmail($_POST['email'] ?? '') ?: '';
        $formData['subject'] = SecurityUtils::sanitizeString($_POST['subject'] ?? '');
        $formData['message'] = SecurityUtils::sanitizeString($_POST['message'] ?? '');
        $formData['type'] = SecurityUtils::sanitizeString($_POST['type'] ?? 'general');

        // Validate form data
        $errors = [];

        if (empty($formData['name'])) {
            $errors[] = 'Name is required.';
        } elseif (strlen($formData['name']) < 2) {
            $errors[] = 'Name must be at least 2 characters.';
        }

        if (empty($formData['email'])) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (empty($formData['subject'])) {
            $errors[] = 'Subject is required.';
        } elseif (strlen($formData['subject']) < 5) {
            $errors[] = 'Subject must be at least 5 characters.';
        }

        if (empty($formData['message'])) {
            $errors[] = 'Message is required.';
        } elseif (strlen($formData['message']) < 20) {
            $errors[] = 'Message must be at least 20 characters.';
        }

        if (!empty($errors)) {
            $error = implode(' ', $errors);
        } else {
            // TODO: Save to database when tblContactSubmissions is created
            // TODO: Send email notification

            // For now, just show success
            $success = true;

            // Clear form data on success
            $formData = [
                'name' => '',
                'email' => '',
                'subject' => '',
                'message' => '',
                'type' => 'general'
            ];

            // Regenerate CSRF token
            SecurityUtils::generateCSRFToken();
        }
    }
}

// Include header component
require_once __DIR__ . '/../../private_html/layout/public-header.php';
?>

<!-- ========================================================================
     HERO SECTION
     ======================================================================== -->
<section class="hero-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10 text-center">
                <h1 class="hero-title">Get in Touch</h1>
                <p class="hero-subtitle">
                    Have questions? We're here to help.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     CONTACT SECTION
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <div class="row g-5">
            <!-- Contact Form -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-md-5">
                        <h2 class="h3 mb-4">Send us a Message</h2>

                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>Message sent successfully!</strong> We'll get back to you within 24 hours.
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="contactForm">
                            <!-- CSRF Token -->
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken()); ?>">

                            <!-- 🛡️ Local Form Protection (honeypot + timing + JS challenge) -->
                            <?php echo FormProtection::renderAllFields(); ?>

                            <!-- Inquiry Type -->
                            <div class="mb-4">
                                <label for="type" class="form-label">Inquiry Type</label>
                                <select class="form-select" id="type" name="type" required>
                                    <option value="general" <?php echo ($formData['type'] === 'general') ? 'selected' : ''; ?>>General Inquiry</option>
                                    <option value="support" <?php echo ($formData['type'] === 'support') ? 'selected' : ''; ?>>Technical Support</option>
                                    <option value="sales" <?php echo ($formData['type'] === 'sales') ? 'selected' : ''; ?>>Sales & Enterprise</option>
                                    <option value="partnership" <?php echo ($formData['type'] === 'partnership') ? 'selected' : ''; ?>>Partnership Opportunity</option>
                                    <option value="feedback" <?php echo ($formData['type'] === 'feedback') ? 'selected' : ''; ?>>Feedback</option>
                                </select>
                            </div>

                            <!-- Name -->
                            <div class="mb-4">
                                <label for="name" class="form-label">Your Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name"
                                       value="<?php echo htmlspecialchars($formData['name']); ?>"
                                       required minlength="2" maxlength="100"
                                       placeholder="John Doe">
                            </div>

                            <!-- Email -->
                            <div class="mb-4">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?php echo htmlspecialchars($formData['email']); ?>"
                                       required maxlength="255"
                                       placeholder="john@example.com">
                            </div>

                            <!-- Subject -->
                            <div class="mb-4">
                                <label for="subject" class="form-label">Subject <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="subject" name="subject"
                                       value="<?php echo htmlspecialchars($formData['subject']); ?>"
                                       required minlength="5" maxlength="200"
                                       placeholder="How can we help?">
                            </div>

                            <!-- Message -->
                            <div class="mb-4">
                                <label for="message" class="form-label">Message <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="message" name="message" rows="6"
                                          required minlength="20" maxlength="2000"
                                          placeholder="Please provide details about your inquiry..."><?php echo htmlspecialchars($formData['message']); ?></textarea>
                                <div class="form-text">
                                    <span id="charCount">0</span> / 2000 characters
                                </div>
                            </div>

                            <!-- 🛡️ CAPTCHA Widget (if enabled) -->
                            <?php echo CaptchaVerifier::renderWidget('contactForm'); ?>

                            <!-- Submit Button -->
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-paper-plane me-2"></i>Send Message
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Contact Information -->
            <div class="col-lg-5">
                <!-- Contact Details -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h3 class="h4 mb-4">Contact Information</h3>

                        <div class="d-flex mb-3">
                            <div class="flex-shrink-0">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center"
                                     style="width: 45px; height: 45px;">
                                    <i class="fas fa-envelope"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h5 class="mb-1">Email</h5>
                                <a href="mailto:support@signula.com" class="text-decoration-none">
                                    support@signula.com
                                </a>
                            </div>
                        </div>

                        <div class="d-flex mb-3">
                            <div class="flex-shrink-0">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center"
                                     style="width: 45px; height: 45px;">
                                    <i class="fas fa-headset"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h5 class="mb-1">Support Hours</h5>
                                <p class="mb-0 text-secondary">
                                    Monday - Friday<br>
                                    9:00 AM - 6:00 PM EST
                                </p>
                            </div>
                        </div>

                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center"
                                     style="width: 45px; height: 45px;">
                                    <i class="fas fa-building"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h5 class="mb-1">Enterprise Sales</h5>
                                <a href="mailto:sales@signula.com" class="text-decoration-none">
                                    sales@signula.com
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h3 class="h4 mb-4">Quick Links</h3>
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2">
                                <a href="/docs/faq" class="text-decoration-none">
                                    <i class="fas fa-question-circle me-2 text-primary"></i>Frequently Asked Questions
                                </a>
                            </li>
                            <li class="mb-2">
                                <a href="/docs" class="text-decoration-none">
                                    <i class="fas fa-book me-2 text-primary"></i>Documentation
                                </a>
                            </li>
                            <li class="mb-2">
                                <a href="/docs/api-reference" class="text-decoration-none">
                                    <i class="fas fa-code me-2 text-primary"></i>API Reference
                                </a>
                            </li>
                            <li class="mb-2">
                                <a href="/support" class="text-decoration-none">
                                    <i class="fas fa-life-ring me-2 text-primary"></i>Support Center
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Social Media -->
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h3 class="h4 mb-4">Follow Us</h3>
                        <div class="d-flex gap-3">
                            <a href="#" class="btn btn-outline-primary btn-sm" aria-label="Twitter">
                                <i class="fab fa-twitter"></i>
                            </a>
                            <a href="#" class="btn btn-outline-primary btn-sm" aria-label="LinkedIn">
                                <i class="fab fa-linkedin"></i>
                            </a>
                            <a href="#" class="btn btn-outline-primary btn-sm" aria-label="GitHub">
                                <i class="fab fa-github"></i>
                            </a>
                            <a href="#" class="btn btn-outline-primary btn-sm" aria-label="YouTube">
                                <i class="fab fa-youtube"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Character Counter Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const messageField = document.getElementById('message');
    const charCount = document.getElementById('charCount');

    function updateCharCount() {
        const count = messageField.value.length;
        charCount.textContent = count;

        if (count > 2000) {
            charCount.classList.add('text-danger');
        } else if (count > 1800) {
            charCount.classList.add('text-warning');
            charCount.classList.remove('text-danger');
        } else {
            charCount.classList.remove('text-warning', 'text-danger');
        }
    }

    messageField.addEventListener('input', updateCharCount);
    updateCharCount(); // Initial count
});
</script>

<?php
// Include footer component
require_once __DIR__ . '/../../private_html/layout/public-footer.php';
?>
