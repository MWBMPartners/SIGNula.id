<?php
/**
 * ============================================================================
 * 📚 SIGNula Documentation Sidebar
 * ============================================================================
 * Reusable sidebar navigation for documentation pages
 *
 * Expected Variables:
 * - $currentDoc (string): Current documentation page identifier
 *
 * @package    SIGNula
 * @subpackage Components
 * @version    1.0.0
 * ============================================================================
 */

// Set default if not provided
$currentDoc = $currentDoc ?? '';
?>

<div class="docs-sidebar">
    <div class="sticky-top" style="top: 100px;">
        <!-- Search Box -->
        <div class="mb-4">
            <div class="input-group">
                <input type="text" class="form-control" placeholder="Search docs..." id="docsSearch">
                <button class="btn btn-outline-secondary" type="button">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="nav flex-column">
            <!-- Getting Started -->
            <div class="nav-section">
                <h6 class="nav-section-title text-uppercase fw-bold text-muted small mb-2">
                    <i class="fas fa-rocket me-2"></i>Getting Started
                </h6>
                <a class="nav-link <?php echo ($currentDoc === 'index') ? 'active' : ''; ?>"
                   href="/docs">
                    <i class="fas fa-home me-2"></i>Documentation Home
                </a>
                <a class="nav-link <?php echo ($currentDoc === 'getting-started') ? 'active' : ''; ?>"
                   href="/docs/getting-started">
                    <i class="fas fa-play-circle me-2"></i>Quick Start
                </a>
                <a class="nav-link <?php echo ($currentDoc === 'integration-guide') ? 'active' : ''; ?>"
                   href="/docs/integration-guide">
                    <i class="fas fa-plug me-2"></i>Integration Guide
                </a>
            </div>

            <!-- API Reference -->
            <div class="nav-section mt-4">
                <h6 class="nav-section-title text-uppercase fw-bold text-muted small mb-2">
                    <i class="fas fa-code me-2"></i>API Reference
                </h6>
                <a class="nav-link <?php echo ($currentDoc === 'api-reference') ? 'active' : ''; ?>"
                   href="/docs/api-reference">
                    <i class="fas fa-book me-2"></i>API Documentation
                </a>
                <a class="nav-link" href="/docs/api-reference#authentication">
                    <i class="fas fa-angle-right me-2"></i>Authentication
                </a>
                <a class="nav-link" href="/docs/api-reference#user-management">
                    <i class="fas fa-angle-right me-2"></i>User Management
                </a>
                <a class="nav-link" href="/docs/api-reference#mfa">
                    <i class="fas fa-angle-right me-2"></i>MFA Endpoints
                </a>
                <a class="nav-link" href="/docs/api-reference#oauth">
                    <i class="fas fa-angle-right me-2"></i>OAuth Linking
                </a>
            </div>

            <!-- Security & Best Practices -->
            <div class="nav-section mt-4">
                <h6 class="nav-section-title text-uppercase fw-bold text-muted small mb-2">
                    <i class="fas fa-shield-alt me-2"></i>Security
                </h6>
                <a class="nav-link <?php echo ($currentDoc === 'security') ? 'active' : ''; ?>"
                   href="/docs/security">
                    <i class="fas fa-lock me-2"></i>Security Best Practices
                </a>
            </div>

            <!-- Support -->
            <div class="nav-section mt-4">
                <h6 class="nav-section-title text-uppercase fw-bold text-muted small mb-2">
                    <i class="fas fa-life-ring me-2"></i>Support
                </h6>
                <a class="nav-link <?php echo ($currentDoc === 'faq') ? 'active' : ''; ?>"
                   href="/docs/faq">
                    <i class="fas fa-question-circle me-2"></i>FAQ
                </a>
                <a class="nav-link" href="/contact?type=support">
                    <i class="fas fa-envelope me-2"></i>Contact Support
                </a>
            </div>

            <!-- Additional Resources -->
            <div class="nav-section mt-4">
                <h6 class="nav-section-title text-uppercase fw-bold text-muted small mb-2">
                    <i class="fas fa-external-link-alt me-2"></i>Resources
                </h6>
                <a class="nav-link" href="https://github.com/MWBMPartners/SIGNula" target="_blank" rel="noopener">
                    <i class="fab fa-github me-2"></i>GitHub Repository
                </a>
                <a class="nav-link" href="/blog">
                    <i class="fas fa-newspaper me-2"></i>Blog & Updates
                </a>
            </div>
        </nav>

        <!-- CTA Box -->
        <div class="card bg-primary text-white mt-4">
            <div class="card-body">
                <h6 class="card-title">Need Help?</h6>
                <p class="card-text small">
                    Can't find what you're looking for? Our support team is here to help.
                </p>
                <a href="/contact?type=support" class="btn btn-light btn-sm w-100">
                    <i class="fas fa-headset me-2"></i>Contact Support
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.docs-sidebar {
    background: var(--bg-white);
}

.docs-sidebar .nav-link {
    color: var(--text-secondary);
    padding: 0.5rem 1rem;
    border-radius: var(--radius-md);
    transition: all var(--transition-fast);
    font-size: 0.9rem;
}

.docs-sidebar .nav-link:hover {
    background: var(--bg-light);
    color: var(--primary-color);
}

.docs-sidebar .nav-link.active {
    background: var(--primary-color);
    color: white;
}

.docs-sidebar .nav-link i {
    width: 20px;
    text-align: center;
}

.nav-section {
    padding-left: 0;
}

.nav-section-title {
    padding: 0.5rem 1rem;
    letter-spacing: 0.05em;
}

@media (max-width: 991.98px) {
    .docs-sidebar {
        margin-bottom: 2rem;
    }

    .docs-sidebar .sticky-top {
        position: relative !important;
        top: auto !important;
    }
}
</style>
