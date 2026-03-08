<?php
/**
 * ============================================================================
 * 💰 SIGNula Pricing Page
 * ============================================================================
 * Pricing tiers with monthly/annual toggle
 *
 * @package    SIGNula
 * @subpackage Marketing
 * @version    2.0.0
 * ============================================================================
 */

// Page configuration
$pageTitle = 'Pricing - SIGNula Universal Authentication System';
$metaDescription = 'Choose the perfect SIGNula plan for your needs. Free tier available with premium options for advanced features and enterprise support.';
$currentPage = 'pricing';
$ogImage = '/assets/images/og-pricing.png';

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

        <!-- Pricing Cards — 6 tiers: Free → Basic → Premium → Pro → Platinum → Enterprise -->
        <div class="row g-3 align-items-stretch">
            <!-- 🆓 Free Tier -->
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="pricing-card h-100 d-flex flex-column">
                    <div class="text-center">
                        <h3 class="pricing-title">Free</h3>
                        <div class="pricing-price">
                            <span class="monthly-price">&pound;0</span>
                            <span class="annual-price" style="display: none;">&pound;0</span>
                            <span>/month</span>
                        </div>
                        <p class="text-secondary mb-4">Perfect for personal use</p>
                    </div>

                    <ul class="pricing-features flex-grow-1">
                        <li><i class="fas fa-check"></i> 1 User Account</li>
                        <li><i class="fas fa-check"></i> Basic Authentication</li>
                        <li><i class="fas fa-check"></i> 2 OAuth Providers</li>
                        <li><i class="fas fa-check"></i> Community Support</li>
                        <li><i class="fas fa-check"></i> 1,000 API Calls/mo</li>
                        <li><i class="fas fa-times text-muted"></i> MFA</li>
                        <li><i class="fas fa-times text-muted"></i> PassKeys</li>
                    </ul>

                    <div class="text-center mt-4">
                        <a href="https://signula.id/register" class="btn btn-outline-primary w-100">
                            Get Started Free
                        </a>
                    </div>
                </div>
            </div>

            <!-- 💼 Basic Tier -->
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="pricing-card h-100 d-flex flex-column">
                    <div class="text-center">
                        <h3 class="pricing-title">Basic</h3>
                        <div class="pricing-price">
                            <span class="monthly-price">&pound;9.99</span>
                            <span class="annual-price" style="display: none;">&pound;8.33</span>
                            <span>/month</span>
                        </div>
                        <p class="text-secondary mb-4 annual-savings" style="display: none;">
                            <small>&pound;99.99/year (save &pound;19.89)</small>
                        </p>
                        <p class="text-secondary mb-4 monthly-info">For individuals</p>
                    </div>

                    <ul class="pricing-features flex-grow-1">
                        <li><i class="fas fa-check"></i> 10 Team Members</li>
                        <li><i class="fas fa-check"></i> All Auth Methods</li>
                        <li><i class="fas fa-check"></i> MFA &amp; PassKeys</li>
                        <li><i class="fas fa-check"></i> Email Support</li>
                        <li><i class="fas fa-check"></i> 10,000 API Calls/mo</li>
                        <li><i class="fas fa-check"></i> Custom Branding</li>
                        <li><i class="fas fa-check"></i> Webhooks</li>
                    </ul>

                    <div class="text-center mt-4">
                        <a href="https://signula.id/register?plan=basic" class="btn btn-primary w-100">
                            Choose Basic
                        </a>
                    </div>
                </div>
            </div>

            <!-- ⭐ Premium Tier (Featured) -->
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="pricing-card featured h-100 d-flex flex-column">
                    <div class="text-center">
                        <span class="pricing-badge">Most Popular</span>
                        <h3 class="pricing-title">Premium</h3>
                        <div class="pricing-price">
                            <span class="monthly-price">&pound;29.99</span>
                            <span class="annual-price" style="display: none;">&pound;24.99</span>
                            <span>/month</span>
                        </div>
                        <p class="text-secondary mb-4 annual-savings" style="display: none;">
                            <small>&pound;299.99/year (save &pound;59.89)</small>
                        </p>
                        <p class="text-secondary mb-4 monthly-info">For growing teams</p>
                    </div>

                    <ul class="pricing-features flex-grow-1">
                        <li><i class="fas fa-check"></i> 25 Team Members</li>
                        <li><i class="fas fa-check"></i> Priority Support</li>
                        <li><i class="fas fa-check"></i> 50,000 API Calls/mo</li>
                        <li><i class="fas fa-check"></i> Advanced Analytics</li>
                        <li><i class="fas fa-check"></i> SSO Enforcement</li>
                        <li><i class="fas fa-check"></i> Audit Log Export</li>
                        <li><i class="fas fa-check"></i> Organization Mgmt</li>
                    </ul>

                    <div class="text-center mt-4">
                        <a href="https://signula.id/register?plan=premium" class="btn btn-primary w-100">
                            Choose Premium
                        </a>
                    </div>
                </div>
            </div>

            <!-- 🚀 Pro Tier -->
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="pricing-card h-100 d-flex flex-column">
                    <div class="text-center">
                        <h3 class="pricing-title">Pro</h3>
                        <div class="pricing-price">
                            <span class="monthly-price">&pound;49.99</span>
                            <span class="annual-price" style="display: none;">&pound;41.66</span>
                            <span>/month</span>
                        </div>
                        <p class="text-secondary mb-4 annual-savings" style="display: none;">
                            <small>&pound;499.99/year (save &pound;99.89)</small>
                        </p>
                        <p class="text-secondary mb-4 monthly-info">For businesses</p>
                    </div>

                    <ul class="pricing-features flex-grow-1">
                        <li><i class="fas fa-check"></i> 50 Team Members</li>
                        <li><i class="fas fa-check"></i> Phone &amp; Email Support</li>
                        <li><i class="fas fa-check"></i> 200,000 API Calls/mo</li>
                        <li><i class="fas fa-check"></i> Custom Webhooks</li>
                        <li><i class="fas fa-check"></i> Role-based Access</li>
                        <li><i class="fas fa-check"></i> Usage Analytics</li>
                        <li><i class="fas fa-check"></i> Bulk User Mgmt</li>
                    </ul>

                    <div class="text-center mt-4">
                        <a href="https://signula.id/register?plan=pro" class="btn btn-primary w-100">
                            Choose Pro
                        </a>
                    </div>
                </div>
            </div>

            <!-- 💎 Platinum Tier -->
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="pricing-card featured h-100 d-flex flex-column">
                    <div class="text-center">
                        <span class="pricing-badge bg-success">Best Value</span>
                        <h3 class="pricing-title">Platinum</h3>
                        <div class="pricing-price">
                            <span class="monthly-price">&pound;79.99</span>
                            <span class="annual-price" style="display: none;">&pound;66.66</span>
                            <span>/month</span>
                        </div>
                        <p class="text-secondary mb-4 annual-savings" style="display: none;">
                            <small>&pound;799.99/year (save &pound;159.89)</small>
                        </p>
                        <p class="text-secondary mb-4 monthly-info">For scaling orgs</p>
                    </div>

                    <ul class="pricing-features flex-grow-1">
                        <li><i class="fas fa-check"></i> 200 Team Members</li>
                        <li><i class="fas fa-check"></i> Dedicated Manager</li>
                        <li><i class="fas fa-check"></i> 500,000 API Calls/mo</li>
                        <li><i class="fas fa-check"></i> SAML SSO &amp; SCIM</li>
                        <li><i class="fas fa-check"></i> Compliance Reports</li>
                        <li><i class="fas fa-check"></i> White-label Branding</li>
                        <li><i class="fas fa-check"></i> Data Residency</li>
                    </ul>

                    <div class="text-center mt-4">
                        <a href="https://signula.id/register?plan=platinum" class="btn btn-primary w-100">
                            Choose Platinum
                        </a>
                    </div>
                </div>
            </div>

            <!-- 🏢 Enterprise Tier -->
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="pricing-card h-100 d-flex flex-column">
                    <div class="text-center">
                        <span class="pricing-badge bg-dark">Custom</span>
                        <h3 class="pricing-title">Enterprise</h3>
                        <div class="pricing-price">
                            <span style="font-size: 1.5rem;">Custom</span>
                        </div>
                        <p class="text-secondary mb-4">For large organizations</p>
                    </div>

                    <ul class="pricing-features flex-grow-1">
                        <li><i class="fas fa-check"></i> Unlimited Users</li>
                        <li><i class="fas fa-check"></i> Unlimited API Calls</li>
                        <li><i class="fas fa-check"></i> Dedicated Support</li>
                        <li><i class="fas fa-check"></i> Custom SLA</li>
                        <li><i class="fas fa-check"></i> On-premise Option</li>
                        <li><i class="fas fa-check"></i> Custom Contracts</li>
                        <li><i class="fas fa-check"></i> SAML/SCIM/Custom</li>
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
                                <th scope="col" style="width: 22%;">Feature</th>
                                <th scope="col" class="text-center">Free</th>
                                <th scope="col" class="text-center">Basic</th>
                                <th scope="col" class="text-center">Premium</th>
                                <th scope="col" class="text-center">Pro</th>
                                <th scope="col" class="text-center">Platinum</th>
                                <th scope="col" class="text-center">Enterprise</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Team Members</strong></td>
                                <td class="text-center">5</td>
                                <td class="text-center">10</td>
                                <td class="text-center">25</td>
                                <td class="text-center">50</td>
                                <td class="text-center">200</td>
                                <td class="text-center">Unlimited</td>
                            </tr>
                            <tr>
                                <td><strong>API Calls / Month</strong></td>
                                <td class="text-center">1,000</td>
                                <td class="text-center">10,000</td>
                                <td class="text-center">50,000</td>
                                <td class="text-center">200,000</td>
                                <td class="text-center">500,000</td>
                                <td class="text-center">Unlimited</td>
                            </tr>
                            <tr>
                                <td><strong>Storage</strong></td>
                                <td class="text-center">1 GB</td>
                                <td class="text-center">10 GB</td>
                                <td class="text-center">50 GB</td>
                                <td class="text-center">100 GB</td>
                                <td class="text-center">500 GB</td>
                                <td class="text-center">Unlimited</td>
                            </tr>
                            <tr>
                                <td><strong>Password Authentication</strong></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
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
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>PassKeys / WebAuthn</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>OAuth Providers</strong></td>
                                <td class="text-center">2</td>
                                <td class="text-center">Unlimited</td>
                                <td class="text-center">Unlimited</td>
                                <td class="text-center">Unlimited</td>
                                <td class="text-center">Unlimited</td>
                                <td class="text-center">Unlimited + Custom</td>
                            </tr>
                            <tr>
                                <td><strong>Activity Logging</strong></td>
                                <td class="text-center">30 days</td>
                                <td class="text-center">90 days</td>
                                <td class="text-center">1 year</td>
                                <td class="text-center">2 years</td>
                                <td class="text-center">Unlimited</td>
                                <td class="text-center">Unlimited</td>
                            </tr>
                            <tr>
                                <td><strong>Support</strong></td>
                                <td class="text-center">Community</td>
                                <td class="text-center">Email</td>
                                <td class="text-center">Priority</td>
                                <td class="text-center">Phone &amp; Email</td>
                                <td class="text-center">Dedicated Mgr</td>
                                <td class="text-center">Dedicated Team</td>
                            </tr>
                            <tr>
                                <td><strong>Organization Management</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>Usage Analytics</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>Role-based Access Control</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>SAML SSO &amp; SCIM</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>White-label / Custom Branding</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>Usage-based / Hybrid Billing</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                                <td class="text-center"><i class="fas fa-check text-success"></i></td>
                            </tr>
                            <tr>
                                <td><strong>SLA Guarantee</strong></td>
                                <td class="text-center"><i class="fas fa-times text-danger"></i></td>
                                <td class="text-center">99%</td>
                                <td class="text-center">99.9%</td>
                                <td class="text-center">99.95%</td>
                                <td class="text-center">99.99%</td>
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
                                We'll notify you when you're approaching your plan limits. You can upgrade to a higher tier, or switch to usage-based or hybrid billing where you only pay for what you use (capped at your tier price). We won't automatically charge you for overages &mdash; you're always in control.
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

                    <!-- FAQ 6 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6">
                                What is usage-based and hybrid billing?
                            </button>
                        </h2>
                        <div id="faq6" class="accordion-collapse collapse" data-bs-parent="#pricingFAQ">
                            <div class="accordion-body">
                                In addition to fixed monthly/annual pricing, Premium plans and above support flexible billing modes. <strong>Usage-based</strong> billing charges only for what you use (API calls, storage, etc.). <strong>Hybrid</strong> billing combines usage-based pricing with a cap &mdash; you pay based on usage, but never more than the fixed tier price. This means low-usage months cost less, while heavy-usage months are capped at the standard rate.
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 7 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq7">
                                Can I create custom pricing tiers?
                            </button>
                        </h2>
                        <div id="faq7" class="accordion-collapse collapse" data-bs-parent="#pricingFAQ">
                            <div class="accordion-body">
                                Yes! Partners and resellers can create custom subscription tiers with bespoke pricing, features, and usage limits tailored to their customers. Custom tiers can use fixed, usage-based, or hybrid billing modes. Contact your account manager or visit the Partner portal to get started.
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
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
