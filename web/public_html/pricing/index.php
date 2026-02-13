<?php
/**
 * ============================================================================
 * 💰 SIGNula - Public Pricing Page
 * ============================================================================
 *
 * Purpose: Display subscription tiers and pricing for SIGNula services
 * PHP Version: 8.3+
 *
 * Features:
 * - Responsive Bootstrap 5.3 pricing cards
 * - Monthly/yearly billing toggle with savings display
 * - Discount code validation (AJAX)
 * - Feature comparison for each tier
 * - CTA buttons linking to checkout flow
 * - No authentication required to view
 *
 * @package    SIGNula
 * @subpackage Public
 * @version    1.0.0
 * @since      2.3.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📚 Load payment manager for tier data
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'PaymentManager.php';

// 💰 Fetch active subscription tiers from database
$tiers = PaymentManager::getTiers(false);

// 🔧 Get default currency from settings
$defaultCurrency = getSetting('payment.default_currency', 'GBP');

// 💱 Currency symbols map
$currencySymbols = [
    'GBP' => '£',
    'USD' => '$',
    'EUR' => '€',
    'AUD' => 'A$',
    'CAD' => 'C$',
    'JPY' => '¥',
];
$currencySymbol = $currencySymbols[$defaultCurrency] ?? $defaultCurrency . ' ';

// 🔍 Check which payment providers are enabled
$stripeEnabled = getSetting('payment.stripe.enabled', '0') === '1';
$paypalEnabled = getSetting('payment.paypal.enabled', '0') === '1';
$cryptoEnabled = getSetting('payment.crypto.enabled', '0') === '1';
$cryptoDiscount = floatval(getSetting('payment.crypto.discount_percent', '0'));

// 🎨 Page metadata for header component
$pageTitle = 'Pricing - SIGNula Universal Authentication';
$metaDescription = 'Choose the right SIGNula plan for your authentication needs. From free to enterprise, secure your applications with universal single sign-on.';
$currentPage = 'pricing';

// 📄 Include public header
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>

<!-- 💰 Pricing Hero Section -->
<section class="pricing-hero py-5" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <div class="container text-center text-white py-4">
        <h1 class="display-4 fw-bold mb-3">
            <i class="fas fa-tags me-2"></i>Simple, Transparent Pricing
        </h1>
        <p class="lead mb-4 opacity-90">
            Choose the plan that fits your needs. Upgrade, downgrade, or cancel anytime.
        </p>

        <!-- 🔄 Monthly/Yearly Toggle -->
        <div class="d-inline-flex align-items-center bg-white bg-opacity-25 rounded-pill px-4 py-2">
            <span class="me-3 fw-semibold billing-label" id="monthlyLabel">Monthly</span>
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="billingToggle"
                       style="width: 3rem; height: 1.5rem; cursor: pointer;"
                       aria-label="Toggle between monthly and yearly billing">
            </div>
            <span class="ms-3 fw-semibold billing-label" id="yearlyLabel">
                Yearly <span class="badge bg-success ms-1">Save up to 17%</span>
            </span>
        </div>
    </div>
</section>

<!-- 💳 Pricing Cards Section -->
<section class="pricing-cards py-5">
    <div class="container">

        <?php if (empty($tiers)): ?>
            <!-- 📭 No tiers available -->
            <div class="alert alert-info text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i>
                Pricing plans are being prepared. Please check back soon.
            </div>
        <?php else: ?>
            <div class="row g-4 justify-content-center">
                <?php foreach ($tiers as $tier): ?>
                    <?php
                    // 📊 Calculate pricing data
                    $monthlyPrice = floatval($tier['monthlyPrice']);
                    $yearlyPrice = floatval($tier['yearlyPrice']);
                    $yearlyMonthly = $yearlyPrice > 0 ? round($yearlyPrice / 12, 2) : 0;
                    $savings = $monthlyPrice > 0 ? round((1 - ($yearlyMonthly / $monthlyPrice)) * 100) : 0;
                    $features = json_decode($tier['features'] ?? '[]', true) ?: [];
                    $isFree = ($monthlyPrice <= 0 && $yearlyPrice <= 0);
                    $hasBadge = !empty($tier['badge']);
                    $badgeColor = $tier['badgeColor'] ?? 'primary';

                    // 🎨 Determine card style based on badge/popularity
                    $isHighlighted = $hasBadge;
                    $cardClass = $isHighlighted ? 'border-primary shadow-lg' : 'border-0 shadow-sm';
                    $headerClass = $isHighlighted ? 'bg-primary text-white' : 'bg-light';
                    ?>
                    <div class="col-lg-3 col-md-6">
                        <div class="card h-100 <?php echo $cardClass; ?> pricing-card position-relative"
                             data-tier="<?php echo htmlspecialchars($tier['tierSlug']); ?>">

                            <?php if ($hasBadge): ?>
                                <!-- 🏷️ Tier Badge -->
                                <div class="position-absolute top-0 start-50 translate-middle">
                                    <span class="badge bg-<?php echo htmlspecialchars($badgeColor); ?> px-3 py-2 rounded-pill fs-6">
                                        <i class="fas fa-star me-1"></i><?php echo htmlspecialchars($tier['badge']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <!-- 📋 Card Header -->
                            <div class="card-header text-center <?php echo $headerClass; ?> py-4 <?php echo $hasBadge ? 'mt-2' : ''; ?>">
                                <h3 class="fw-bold mb-1"><?php echo htmlspecialchars($tier['tierName']); ?></h3>
                                <p class="mb-0 opacity-75 small"><?php echo htmlspecialchars($tier['tierDescription']); ?></p>
                            </div>

                            <!-- 💰 Pricing Display -->
                            <div class="card-body text-center pt-4">
                                <?php if ($isFree): ?>
                                    <div class="pricing-amount mb-3">
                                        <span class="display-5 fw-bold">Free</span>
                                        <p class="text-muted small mt-1">No credit card required</p>
                                    </div>
                                <?php else: ?>
                                    <!-- Monthly price (shown by default) -->
                                    <div class="pricing-amount monthly-price mb-3">
                                        <span class="display-5 fw-bold">
                                            <?php echo $currencySymbol . number_format($monthlyPrice, 2); ?>
                                        </span>
                                        <span class="text-muted">/month</span>
                                    </div>

                                    <!-- Yearly price (hidden by default, toggled via JS) -->
                                    <div class="pricing-amount yearly-price mb-3" style="display: none;">
                                        <span class="display-5 fw-bold">
                                            <?php echo $currencySymbol . number_format($yearlyMonthly, 2); ?>
                                        </span>
                                        <span class="text-muted">/month</span>
                                        <p class="text-success small mt-1 mb-0">
                                            <i class="fas fa-tag me-1"></i>
                                            <?php echo $currencySymbol . number_format($yearlyPrice, 2); ?>/year
                                            <?php if ($savings > 0): ?>
                                                <span class="badge bg-success-subtle text-success ms-1">Save <?php echo $savings; ?>%</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($tier['trialDays']) && $tier['trialDays'] > 0): ?>
                                    <p class="text-info small mb-3">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo intval($tier['trialDays']); ?>-day free trial
                                    </p>
                                <?php endif; ?>

                                <hr>

                                <!-- ✅ Feature List -->
                                <ul class="list-unstyled text-start mb-4">
                                    <?php foreach ($features as $feature): ?>
                                        <li class="mb-2">
                                            <i class="fas fa-check-circle text-success me-2"></i>
                                            <?php echo htmlspecialchars($feature); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <!-- 🔘 CTA Button -->
                            <div class="card-footer bg-transparent border-0 text-center pb-4">
                                <?php if ($isFree): ?>
                                    <a href="/register"
                                       class="btn btn-outline-primary btn-lg w-100 rounded-pill">
                                        <i class="fas fa-user-plus me-2"></i>Get Started Free
                                    </a>
                                <?php else: ?>
                                    <a href="/checkout?tier=<?php echo urlencode($tier['tierSlug']); ?>&cycle=monthly"
                                       class="btn <?php echo $isHighlighted ? 'btn-primary' : 'btn-outline-primary'; ?> btn-lg w-100 rounded-pill cta-button"
                                       data-tier="<?php echo htmlspecialchars($tier['tierSlug']); ?>">
                                        <i class="fas fa-rocket me-2"></i>Subscribe Now
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- 🎁 Discount Code Section -->
        <div class="row justify-content-center mt-5">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <h5 class="fw-bold mb-3">
                            <i class="fas fa-gift text-primary me-2"></i>Have a discount code?
                        </h5>
                        <div class="input-group">
                            <input type="text" class="form-control" id="discountCodeInput"
                                   placeholder="Enter your promo code" maxlength="50"
                                   aria-label="Discount code">
                            <button class="btn btn-primary" type="button" id="validateDiscountBtn">
                                <i class="fas fa-check me-1"></i>Apply
                            </button>
                        </div>
                        <div id="discountResult" class="mt-2 small" style="display: none;"></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($cryptoEnabled && $cryptoDiscount > 0): ?>
            <!-- 🪙 Crypto Discount Banner -->
            <div class="row justify-content-center mt-4">
                <div class="col-md-8">
                    <div class="alert alert-info border-0 shadow-sm d-flex align-items-center" role="alert">
                        <div class="me-3">
                            <i class="fab fa-bitcoin fa-2x text-warning"></i>
                        </div>
                        <div>
                            <strong>Pay with Crypto &amp; Save <?php echo number_format($cryptoDiscount, 0); ?>%!</strong>
                            <p class="mb-0 small">
                                We accept Bitcoin, Ethereum, USDT, and USDC. Enjoy a
                                <?php echo number_format($cryptoDiscount, 0); ?>% discount on all plans
                                when paying with cryptocurrency.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 💳 Accepted Payment Methods -->
        <div class="text-center mt-4 mb-3">
            <p class="text-muted mb-2"><small>Accepted Payment Methods</small></p>
            <div class="d-flex justify-content-center gap-3 flex-wrap">
                <?php if ($stripeEnabled): ?>
                    <span class="badge bg-light text-dark border px-3 py-2">
                        <i class="fab fa-cc-stripe me-1 text-primary"></i>Stripe
                    </span>
                    <span class="badge bg-light text-dark border px-3 py-2">
                        <i class="fas fa-link me-1 text-primary"></i>Link
                    </span>
                    <span class="badge bg-light text-dark border px-3 py-2">
                        <i class="fab fa-cc-visa me-1"></i><i class="fab fa-cc-mastercard me-1"></i>Cards
                    </span>
                    <span class="badge bg-light text-dark border px-3 py-2">
                        <i class="fab fa-apple-pay me-1"></i>Apple Pay
                    </span>
                    <span class="badge bg-light text-dark border px-3 py-2">
                        <i class="fab fa-google-pay me-1"></i>Google Pay
                    </span>
                <?php endif; ?>
                <?php if ($paypalEnabled): ?>
                    <span class="badge bg-light text-dark border px-3 py-2">
                        <i class="fab fa-paypal me-1 text-primary"></i>PayPal
                    </span>
                <?php endif; ?>
                <?php if ($cryptoEnabled): ?>
                    <span class="badge bg-light text-dark border px-3 py-2">
                        <i class="fab fa-bitcoin me-1 text-warning"></i>Crypto
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- ❓ FAQ Section -->
<section class="pricing-faq py-5 bg-light">
    <div class="container">
        <h2 class="text-center fw-bold mb-5">
            <i class="fas fa-question-circle text-primary me-2"></i>Frequently Asked Questions
        </h2>
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="accordion" id="pricingFAQ">
                    <!-- FAQ 1 -->
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed rounded" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#faq1"
                                    aria-expanded="false" aria-controls="faq1">
                                Can I change my plan later?
                            </button>
                        </h2>
                        <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#pricingFAQ">
                            <div class="accordion-body">
                                Yes! You can upgrade or downgrade your plan at any time. When upgrading,
                                you will be charged the prorated difference. When downgrading, the remaining
                                balance will be applied as credit to your next billing cycle.
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 2 -->
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed rounded" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#faq2"
                                    aria-expanded="false" aria-controls="faq2">
                                Is there a free trial?
                            </button>
                        </h2>
                        <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#pricingFAQ">
                            <div class="accordion-body">
                                Yes! Our Basic and Premium plans include a 14-day free trial, and our
                                Enterprise plan includes a 30-day trial. No credit card is required to
                                start your trial.
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 3 -->
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed rounded" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#faq3"
                                    aria-expanded="false" aria-controls="faq3">
                                What payment methods do you accept?
                            </button>
                        </h2>
                        <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#pricingFAQ">
                            <div class="accordion-body">
                                We accept credit/debit cards (Visa, Mastercard, Amex), Stripe Link for
                                accelerated checkout, Apple Pay, Google Pay, PayPal, and cryptocurrency
                                (Bitcoin, Ethereum, USDT, USDC). Pay with crypto and save
                                <?php echo number_format($cryptoDiscount, 0); ?>% on any plan!
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 4 -->
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed rounded" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#faq4"
                                    aria-expanded="false" aria-controls="faq4">
                                Can I cancel my subscription?
                            </button>
                        </h2>
                        <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#pricingFAQ">
                            <div class="accordion-body">
                                Absolutely. You can cancel your subscription at any time from your account
                                settings. Your access will continue until the end of your current billing
                                period. There are no cancellation fees or long-term contracts.
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 5 -->
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed rounded" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#faq5"
                                    aria-expanded="false" aria-controls="faq5">
                                Do you offer custom enterprise pricing?
                            </button>
                        </h2>
                        <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#pricingFAQ">
                            <div class="accordion-body">
                                Yes! For organisations with specific requirements, custom SLAs, or
                                high-volume needs, please contact our sales team. We offer bespoke
                                pricing, dedicated support, and custom integrations.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 🎨 Custom Styles -->
<style>
    /* 💰 Pricing card hover effects */
    .pricing-card {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        border-radius: 1rem !important;
        overflow: hidden;
    }

    .pricing-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.15) !important;
    }

    /* 🔄 Billing toggle styling */
    .billing-label {
        cursor: pointer;
        opacity: 0.7;
        transition: opacity 0.3s ease;
    }

    .billing-label.active {
        opacity: 1;
    }

    /* 🎯 Hero gradient animation */
    .pricing-hero {
        position: relative;
        overflow: hidden;
    }

    .pricing-hero::after {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 100%;
        height: 100%;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 60%);
        animation: float 8s ease-in-out infinite;
    }

    @keyframes float {
        0%, 100% { transform: translate(0, 0); }
        50% { transform: translate(-20px, 20px); }
    }

    /* ✅ Feature list styling */
    .pricing-card .list-unstyled li {
        padding: 0.25rem 0;
    }

    /* 📱 Responsive adjustments */
    @media (max-width: 767.98px) {
        .pricing-hero h1 {
            font-size: 1.75rem;
        }

        .pricing-amount .display-5 {
            font-size: 2rem;
        }
    }
</style>

<!-- ⚡ JavaScript: Billing Toggle & Discount Validation -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // ========================================================================
    // 🔄 Monthly/Yearly Billing Toggle
    // ========================================================================

    const billingToggle = document.getElementById('billingToggle');
    const monthlyLabel = document.getElementById('monthlyLabel');
    const yearlyLabel = document.getElementById('yearlyLabel');
    const monthlyPrices = document.querySelectorAll('.monthly-price');
    const yearlyPrices = document.querySelectorAll('.yearly-price');
    const ctaButtons = document.querySelectorAll('.cta-button');

    if (billingToggle) {
        // 🎯 Set initial state
        monthlyLabel.classList.add('active');

        billingToggle.addEventListener('change', function() {
            const isYearly = this.checked;

            // 🔄 Toggle price visibility
            monthlyPrices.forEach(function(el) {
                el.style.display = isYearly ? 'none' : '';
            });
            yearlyPrices.forEach(function(el) {
                el.style.display = isYearly ? '' : 'none';
            });

            // 🎨 Update label active states
            monthlyLabel.classList.toggle('active', !isYearly);
            yearlyLabel.classList.toggle('active', isYearly);

            // 🔗 Update CTA button URLs
            ctaButtons.forEach(function(btn) {
                const tier = btn.getAttribute('data-tier');
                const cycle = isYearly ? 'yearly' : 'monthly';
                btn.href = '/checkout?tier=' + encodeURIComponent(tier) + '&cycle=' + cycle;
            });
        });

        // 🖱️ Click labels to toggle
        monthlyLabel.addEventListener('click', function() {
            billingToggle.checked = false;
            billingToggle.dispatchEvent(new Event('change'));
        });

        yearlyLabel.addEventListener('click', function() {
            billingToggle.checked = true;
            billingToggle.dispatchEvent(new Event('change'));
        });
    }

    // ========================================================================
    // 🎁 Discount Code Validation
    // ========================================================================

    const discountInput = document.getElementById('discountCodeInput');
    const validateBtn = document.getElementById('validateDiscountBtn');
    const discountResult = document.getElementById('discountResult');

    if (validateBtn && discountInput) {
        validateBtn.addEventListener('click', function() {
            const code = discountInput.value.trim();
            if (code.length === 0) {
                showDiscountResult('Please enter a discount code.', 'text-warning');
                return;
            }

            // 🔄 Show loading state
            validateBtn.disabled = true;
            validateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Checking...';

            // 📡 AJAX request to validate discount code
            fetch('/api/validate-discount', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ code: code })
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.valid) {
                    // ✅ Valid discount
                    var message = '<i class="fas fa-check-circle me-1"></i>';
                    if (data.type === 'percentage') {
                        message += data.value + '% off — code applied!';
                    } else if (data.type === 'fixed_amount') {
                        message += '<?php echo $currencySymbol; ?>' + parseFloat(data.value).toFixed(2) + ' off — code applied!';
                    } else if (data.type === 'free_trial_days') {
                        message += data.value + ' extra free trial days — code applied!';
                    }
                    showDiscountResult(message, 'text-success');

                    // 💾 Store code for checkout
                    sessionStorage.setItem('signula_discount_code', code);
                } else {
                    // ❌ Invalid discount
                    showDiscountResult(
                        '<i class="fas fa-times-circle me-1"></i>' + (data.message || 'Invalid or expired discount code.'),
                        'text-danger'
                    );
                    sessionStorage.removeItem('signula_discount_code');
                }
            })
            .catch(function(error) {
                showDiscountResult(
                    '<i class="fas fa-exclamation-triangle me-1"></i>Unable to validate code. Please try again.',
                    'text-danger'
                );
            })
            .finally(function() {
                // 🔄 Reset button state
                validateBtn.disabled = false;
                validateBtn.innerHTML = '<i class="fas fa-check me-1"></i>Apply';
            });
        });

        // ⌨️ Allow Enter key to validate
        discountInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                validateBtn.click();
            }
        });
    }

    /**
     * 📝 Show discount validation result
     *
     * @param {string} message Message to display
     * @param {string} cssClass CSS class for styling
     */
    function showDiscountResult(message, cssClass) {
        if (discountResult) {
            discountResult.innerHTML = '<span class="' + cssClass + '">' + message + '</span>';
            discountResult.style.display = '';
        }
    }
});
</script>

<?php
// 📄 Include public footer
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
