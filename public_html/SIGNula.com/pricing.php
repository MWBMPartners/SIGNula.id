<?php
/**
 * ============================================================================
 * 💰 SIGNula Pricing Page
 * ============================================================================
 * Pricing tiers with monthly/annual toggle
 *
 * @package    SIGNula
 * @subpackage Marketing
 * @version    1.0.0
 * ============================================================================
 */

// Page configuration
$pageTitle = 'Pricing - SIGNula Universal Authentication System';
$metaDescription = 'Choose the perfect SIGNula plan for your needs. Free tier available with premium options for advanced features and enterprise support.';
$currentPage = 'pricing';
$ogImage = '/assets/images/og-pricing.png';

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
                <h1 class="hero-title">Simple, Transparent Pricing</h1>
                <p class="hero-subtitle">
                    Choose the plan that fits your needs. Upgrade or downgrade anytime.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     PRICING SECTION
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <!-- Billing Toggle -->
        <div class="row mb-5">
            <div class="col-12 text-center">
                <div class="d-inline-flex align-items-center bg-light rounded-pill p-2">
                    <button class="btn btn-sm px-4 py-2 rounded-pill billing-toggle active" data-billing="monthly">
                        Monthly
                    </button>
                    <button class="btn btn-sm px-4 py-2 rounded-pill billing-toggle" data-billing="annual">
                        Annual
                        <span class="badge bg-success ms-2">Save 20%</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Pricing Cards -->
        <div class="row g-4 align-items-center">
            <!-- Free Tier -->
            <div class="col-lg-3 col-md-6">
                <div class="pricing-card">
                    <div class="text-center">
                        <h3 class="pricing-title">Free</h3>
                        <div class="pricing-price">
                            <span class="monthly-price">$0</span>
                            <span class="annual-price" style="display: none;">$0</span>
                            <span>/month</span>
                        </div>
                        <p class="text-secondary mb-4">Perfect for personal use</p>
                    </div>

                    <ul class="pricing-features">
                        <li><i class="fas fa-check"></i> 1 User Account</li>
                        <li><i class="fas fa-check"></i> Basic Authentication</li>
                        <li><i class="fas fa-check"></i> 2 OAuth Providers</li>
                        <li><i class="fas fa-check"></i> Email Support</li>
                        <li><i class="fas fa-check"></i> Community Access</li>
                        <li><i class="fas fa-times text-muted"></i> MFA</li>
                        <li><i class="fas fa-times text-muted"></i> PassKeys</li>
                        <li><i class="fas fa-times text-muted"></i> API Access</li>
                    </ul>

                    <div class="text-center mt-4">
                        <a href="https://signula.id/register" class="btn btn-outline-primary w-100">
                            Get Started Free
                        </a>
                    </div>
                </div>
            </div>

            <!-- Basic Tier -->
            <div class="col-lg-3 col-md-6">
                <div class="pricing-card">
                    <div class="text-center">
                        <h3 class="pricing-title">Basic</h3>
                        <div class="pricing-price">
                            <span class="monthly-price">$5</span>
                            <span class="annual-price" style="display: none;">$4</span>
                            <span>/month</span>
                        </div>
                        <p class="text-secondary mb-4 annual-savings" style="display: none;">
                            <small>$48/year (save $12)</small>
                        </p>
                        <p class="text-secondary mb-4 monthly-info">For individuals</p>
                    </div>

                    <ul class="pricing-features">
                        <li><i class="fas fa-check"></i> 1 User Account</li>
                        <li><i class="fas fa-check"></i> All Authentication Methods</li>
                        <li><i class="fas fa-check"></i> Unlimited OAuth Providers</li>
                        <li><i class="fas fa-check"></i> MFA & PassKeys</li>
                        <li><i class="fas fa-check"></i> Priority Email Support</li>
                        <li><i class="fas fa-check"></i> 5 API Applications</li>
                        <li><i class="fas fa-check"></i> Activity Logging</li>
                        <li><i class="fas fa-times text-muted"></i> Organization Support</li>
                    </ul>

                    <div class="text-center mt-4">
                        <a href="https://signula.id/register?plan=basic" class="btn btn-primary w-100">
                            Choose Basic
                        </a>
                    </div>
                </div>
            </div>

            <!-- Premium Tier (Featured) -->
            <div class="col-lg-3 col-md-6">
                <div class="pricing-card featured">
                    <div class="text-center">
                        <span class="pricing-badge">Most Popular</span>
                        <h3 class="pricing-title">Premium</h3>
                        <div class="pricing-price">
                            <span class="monthly-price">$10</span>
                            <span class="annual-price" style="display: none;">$8</span>
                            <span>/month</span>
                        </div>
                        <p class="text-secondary mb-4 annual-savings" style="display: none;">
                            <small>$96/year (save $24)</small>
                        </p>
                        <p class="text-secondary mb-4 monthly-info">For power users</p>
                    </div>

                    <ul class="pricing-features">
                        <li><i class="fas fa-check"></i> Up to 5 Team Members</li>
                        <li><i class="fas fa-check"></i> All Authentication Methods</li>
                        <li><i class="fas fa-check"></i> Unlimited OAuth Providers</li>
                        <li><i class="fas fa-check"></i> Advanced MFA Options</li>
                        <li><i class="fas fa-check"></i> Priority Support (24/7)</li>
                        <li><i class="fas fa-check"></i> Unlimited API Applications</li>
                        <li><i class="fas fa-check"></i> Advanced Analytics</li>
                        <li><i class="fas fa-check"></i> Organization Management</li>
                    </ul>

                    <div class="text-center mt-4">
                        <a href="https://signula.id/register?plan=premium" class="btn btn-primary w-100">
                            Choose Premium
                        </a>
                    </div>
                </div>
            </div>

            <!-- Enterprise Tier -->
            <div class="col-lg-3 col-md-6">
                <div class="pricing-card">
                    <div class="text-center">
                        <h3 class="pricing-title">Enterprise</h3>
                        <div class="pricing-price">
                            <span style="font-size: 1.5rem;">Custom</span>
                        </div>
                        <p class="text-secondary mb-4">For large organizations</p>
                    </div>

                    <ul class="pricing-features">
                        <li><i class="fas fa-check"></i> Unlimited Users</li>
                        <li><i class="fas fa-check"></i> All Premium Features</li>
                        <li><i class="fas fa-check"></i> Custom OAuth Providers</li>
                        <li><i class="fas fa-check"></i> SSO Integration</li>
                        <li><i class="fas fa-check"></i> Dedicated Support</li>
                        <li><i class="fas fa-check"></i> Custom SLA</li>
                        <li><i class="fas fa-check"></i> White-label Options</li>
                        <li><i class="fas fa-check"></i> On-premise Deployment</li>
                    </ul>

                    <div class="text-center mt-4">
                        <a href="/contact" class="btn btn-outline-primary w-100">
                            Contact Sales
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pricing Note -->
        <div class="row mt-5">
            <div class="col-12 text-center">
                <p class="text-secondary mb-0">
                    <small>All plans include a 14-day free trial. No credit card required.</small>
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     FEATURE COMPARISON TABLE
     ======================================================================== -->
<section class="section-padding bg-light">
    <div class="container">
        <!-- Section Header -->
        <div class="row mb-5">
            <div class="col-12 text-center">
                <h2 class="display-5 fw-bold mb-3">
                    <span class="gradient-text">Detailed Feature Comparison</span>
                </h2>
                <p class="lead text-secondary">
                    Compare features across all plans
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
                                <th scope="col" class="text-center">Free</th>
                                <th scope="col" class="text-center">Basic</th>
                                <th scope="col" class="text-center">Premium</th>
                                <th scope="col" class="text-center">Enterprise</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>User Accounts</strong></td>
                                <td class="text-center">1</td>
                                <td class="text-center">1</td>
                                <td class="text-center">5</td>
                                <td class="text-center">Unlimited</td>
                            </tr>
                            <tr>
                                <td><strong>Password Authentication</strong></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>Multi-Factor Authentication</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>PassKeys / WebAuthn</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>OAuth Providers</strong></td>
                                <td class="text-center">2</td>
                                <td class="text-center">Unlimited</td>
                                <td class="text-center">Unlimited</td>
                                <td class="text-center">Unlimited + Custom</td>
                            </tr>
                            <tr>
                                <td><strong>API Applications</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center">5</td>
                                <td class="text-center">Unlimited</td>
                                <td class="text-center">Unlimited</td>
                            </tr>
                            <tr>
                                <td><strong>Activity Logging</strong></td>
                                <td class="text-center">30 days</td>
                                <td class="text-center">90 days</td>
                                <td class="text-center">1 year</td>
                                <td class="text-center">Unlimited</td>
                            </tr>
                            <tr>
                                <td><strong>Support</strong></td>
                                <td class="text-center">Community</td>
                                <td class="text-center">Email</td>
                                <td class="text-center">Priority 24/7</td>
                                <td class="text-center">Dedicated</td>
                            </tr>
                            <tr>
                                <td><strong>Organization Management</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>Advanced Analytics</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>White-label Options</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>SLA Guarantee</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center">99%</td>
                                <td class="text-center">99.9%</td>
                                <td class="text-center">Custom</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     FAQ SECTION
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <!-- Section Header -->
        <div class="row mb-5">
            <div class="col-12 text-center">
                <h2 class="display-5 fw-bold mb-3">
                    <span class="gradient-text">Frequently Asked Questions</span>
                </h2>
            </div>
        </div>

        <!-- FAQ Accordion -->
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="accordion" id="pricingFAQ">
                    <!-- FAQ 1 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                Can I change plans later?
                            </button>
                        </h2>
                        <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#pricingFAQ">
                            <div class="accordion-body">
                                Yes! You can upgrade or downgrade your plan at any time. When upgrading, you'll be charged the prorated difference. When downgrading, the credit will be applied to your next billing cycle.
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 2 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                Is there a free trial?
                            </button>
                        </h2>
                        <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#pricingFAQ">
                            <div class="accordion-body">
                                Yes! All paid plans include a 14-day free trial. No credit card required to start your trial. You can cancel anytime during the trial period without being charged.
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 3 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                What payment methods do you accept?
                            </button>
                        </h2>
                        <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#pricingFAQ">
                            <div class="accordion-body">
                                We accept all major credit cards (Visa, MasterCard, American Express), PayPal, Apple Pay, Google Pay, and cryptocurrency. Enterprise customers can also arrange for invoice billing.
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 4 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                What happens if I exceed my plan limits?
                            </button>
                        </h2>
                        <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#pricingFAQ">
                            <div class="accordion-body">
                                We'll notify you when you're approaching your plan limits. You can upgrade to a higher tier at any time. We won't automatically charge you for overages - you're always in control.
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 5 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                                Do you offer discounts for non-profits or education?
                            </button>
                        </h2>
                        <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#pricingFAQ">
                            <div class="accordion-body">
                                Yes! We offer special pricing for qualified non-profit organizations and educational institutions. Contact our sales team for more information.
                            </div>
                        </div>
                    </div>
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
                <h2 class="display-4 fw-bold mb-4">Ready to Get Started?</h2>
                <p class="lead mb-5" style="opacity: 0.9;">
                    Start with our free tier and upgrade when you're ready. No credit card required.
                </p>
                <div class="d-flex gap-3 justify-content-center flex-wrap">
                    <a href="https://signula.id/register" class="btn btn-light btn-lg px-5 py-3">
                        <i class="fas fa-user-plus me-2"></i>Start Free Trial
                    </a>
                    <a href="/contact" class="btn btn-outline-light btn-lg px-5 py-3">
                        <i class="fas fa-envelope me-2"></i>Contact Sales
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Billing Toggle JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleButtons = document.querySelectorAll('.billing-toggle');
    const monthlyPrices = document.querySelectorAll('.monthly-price');
    const annualPrices = document.querySelectorAll('.annual-price');
    const monthlyInfo = document.querySelectorAll('.monthly-info');
    const annualSavings = document.querySelectorAll('.annual-savings');

    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const billing = this.getAttribute('data-billing');

            // Update active state
            toggleButtons.forEach(btn => btn.classList.remove('active', 'btn-primary'));
            this.classList.add('active', 'btn-primary');

            // Toggle prices
            if (billing === 'annual') {
                monthlyPrices.forEach(el => el.style.display = 'none');
                annualPrices.forEach(el => el.style.display = 'inline');
                monthlyInfo.forEach(el => el.style.display = 'none');
                annualSavings.forEach(el => el.style.display = 'block');
            } else {
                monthlyPrices.forEach(el => el.style.display = 'inline');
                annualPrices.forEach(el => el.style.display = 'none');
                monthlyInfo.forEach(el => el.style.display = 'block');
                annualSavings.forEach(el => el.style.display = 'none');
            }
        });
    });
});
</script>

<style>
.billing-toggle {
    background: transparent;
    border: none;
    color: var(--text-secondary);
    transition: all var(--transition-fast);
}

.billing-toggle.active {
    background: var(--primary-color) !important;
    color: white !important;
}

.billing-toggle:hover {
    background: rgba(102, 126, 234, 0.1);
}
</style>

<?php
// Include footer component
require_once __DIR__ . '/../../private_html/layout/public-footer.php';
?>
