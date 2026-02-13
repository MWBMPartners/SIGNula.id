<?php
/**
 * ============================================================================
 * 🛒 SIGNula - Checkout Page
 * ============================================================================
 *
 * Purpose: Subscription checkout with payment method selection
 * PHP Version: 8.3+
 *
 * Features:
 * - Tier selection and pricing summary
 * - Multiple payment provider tabs (Stripe/Link, PayPal, Crypto)
 * - Stripe Payment Element for card/Link/Apple Pay/Google Pay
 * - PayPal JS SDK buttons
 * - Coinbase Commerce redirect for crypto
 * - Discount code application (AJAX)
 * - Order summary with real-time total updates
 * - CSRF protection on all forms
 *
 * URL: /checkout?tier=basic&cycle=monthly&discount=CODE
 *
 * @see https://docs.stripe.com/payments/payment-element (Stripe Payment Element)
 * @see https://developer.paypal.com/sdk/js/ (PayPal JS SDK)
 * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/welcome (Coinbase Commerce)
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

// 📚 Load required classes
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'PaymentManager.php';

// 🔒 Authentication required for checkout
if (!Auth::isAuthenticated()) {
    // 🔗 Redirect to login with return URL
    $returnURL = '/checkout?' . http_build_query($_GET);
    header('Location: /login?redirect=' . urlencode($returnURL));
    exit;
}

// 👤 Get current user
$currentUser = Auth::getCurrentUser();

// ========================================================================
// 📋 VALIDATE REQUEST PARAMETERS
// ========================================================================

$tierSlug = trim($_GET['tier'] ?? '');
$billingCycle = trim($_GET['cycle'] ?? 'monthly');
$discountCode = trim($_GET['discount'] ?? '');

// 🔍 Validate billing cycle
$validCycles = ['monthly', 'yearly'];
if (!in_array($billingCycle, $validCycles, true)) {
    $billingCycle = 'monthly';
}

// 🔍 Validate and fetch tier
if (empty($tierSlug)) {
    header('Location: /pricing');
    exit;
}

$tier = PaymentManager::getTier($tierSlug);

if ($tier === null) {
    header('Location: /pricing');
    exit;
}

// 🆓 Free tier doesn't need checkout
if (floatval($tier['monthlyPrice']) <= 0 && floatval($tier['yearlyPrice']) <= 0) {
    header('Location: /pricing');
    exit;
}

// ========================================================================
// 💰 CALCULATE PRICING
// ========================================================================

$price = ($billingCycle === 'yearly')
    ? floatval($tier['yearlyPrice'])
    : floatval($tier['monthlyPrice']);

$currency = $tier['currency'] ?? 'GBP';
$currencySymbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
$currencySymbol = $currencySymbols[$currency] ?? $currency . ' ';

// 🎁 Apply discount if provided
$discountInfo = null;
$discountAmount = 0;

if (!empty($discountCode)) {
    $discountResult = PaymentManager::validateDiscountCode($discountCode, $tierSlug, '', $price);
    if ($discountResult['valid'] ?? false) {
        $discountInfo = $discountResult;
        $discountAmount = floatval($discountResult['discountAmount'] ?? 0);
    }
}

// 💵 Tax calculation
$taxEnabled = getSetting('payment.tax_enabled', '1') === '1';
$taxRate = floatval(getSetting('payment.tax_rate', '20.00'));
$subtotal = $price - $discountAmount;
$taxAmount = $taxEnabled ? round($subtotal * ($taxRate / 100), 2) : 0;
$total = round($subtotal + $taxAmount, 2);

// 🔧 Payment provider availability
$stripeEnabled = getSetting('payment.stripe.enabled', '0') === '1';
$paypalEnabled = getSetting('payment.paypal.enabled', '0') === '1';
$cryptoEnabled = getSetting('payment.crypto.enabled', '0') === '1';
$cryptoDiscount = floatval(getSetting('payment.crypto.discount_percent', '0'));
$stripePublishableKey = getSetting('payment.stripe.publishable_key', '');

// 🔐 CSRF token for forms
$csrfToken = SecurityUtils::generateCSRFToken();

// 🎨 Page metadata
$pageTitle = 'Checkout - ' . htmlspecialchars($tier['tierName']) . ' Plan - SIGNula';
$metaDescription = 'Complete your subscription to SIGNula ' . htmlspecialchars($tier['tierName']) . ' plan.';
$currentPage = 'pricing';

// 📄 Include header
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>

<!-- 🛒 Checkout Header -->
<section class="checkout-header py-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <div class="container text-white">
        <h1 class="h3 fw-bold mb-1">
            <i class="fas fa-shopping-cart me-2"></i>Checkout
        </h1>
        <p class="mb-0 opacity-75">
            <a href="/pricing" class="text-white text-decoration-none">
                <i class="fas fa-arrow-left me-1"></i>Back to pricing
            </a>
        </p>
    </div>
</section>

<!-- 🛒 Checkout Content -->
<section class="checkout-content py-5">
    <div class="container">
        <div class="row g-4">

            <!-- ========================================================== -->
            <!-- 💳 Payment Methods (Left Column) -->
            <!-- ========================================================== -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="fw-bold mb-0">
                            <i class="fas fa-credit-card me-2 text-primary"></i>Payment Method
                        </h5>
                    </div>
                    <div class="card-body p-4">

                        <?php if (!$stripeEnabled && !$paypalEnabled && !$cryptoEnabled): ?>
                            <!-- 🚫 No payment methods available -->
                            <div class="alert alert-warning" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Payment processing is currently unavailable. Please try again later or contact support.
                            </div>
                        <?php else: ?>

                            <!-- 📑 Payment Method Tabs -->
                            <ul class="nav nav-pills nav-fill mb-4" id="paymentTabs" role="tablist">
                                <?php if ($stripeEnabled): ?>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="stripe-tab" data-bs-toggle="pill"
                                                data-bs-target="#stripe-pane" type="button" role="tab"
                                                aria-controls="stripe-pane" aria-selected="true">
                                            <i class="fab fa-cc-stripe me-1"></i>Card / Link
                                        </button>
                                    </li>
                                <?php endif; ?>
                                <?php if ($paypalEnabled): ?>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link <?php echo !$stripeEnabled ? 'active' : ''; ?>"
                                                id="paypal-tab" data-bs-toggle="pill"
                                                data-bs-target="#paypal-pane" type="button" role="tab"
                                                aria-controls="paypal-pane"
                                                aria-selected="<?php echo !$stripeEnabled ? 'true' : 'false'; ?>">
                                            <i class="fab fa-paypal me-1"></i>PayPal
                                        </button>
                                    </li>
                                <?php endif; ?>
                                <?php if ($cryptoEnabled): ?>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link <?php echo (!$stripeEnabled && !$paypalEnabled) ? 'active' : ''; ?>"
                                                id="crypto-tab" data-bs-toggle="pill"
                                                data-bs-target="#crypto-pane" type="button" role="tab"
                                                aria-controls="crypto-pane"
                                                aria-selected="<?php echo (!$stripeEnabled && !$paypalEnabled) ? 'true' : 'false'; ?>">
                                            <i class="fab fa-bitcoin me-1"></i>Crypto
                                            <?php if ($cryptoDiscount > 0): ?>
                                                <span class="badge bg-success ms-1">-<?php echo number_format($cryptoDiscount, 0); ?>%</span>
                                            <?php endif; ?>
                                        </button>
                                    </li>
                                <?php endif; ?>
                            </ul>

                            <!-- 📑 Payment Method Tab Content -->
                            <div class="tab-content" id="paymentTabContent">

                                <?php if ($stripeEnabled): ?>
                                    <!-- 🔵 Stripe / Card / Link -->
                                    <div class="tab-pane fade show active" id="stripe-pane" role="tabpanel"
                                         aria-labelledby="stripe-tab">
                                        <p class="text-muted mb-3">
                                            <i class="fas fa-lock me-1"></i>
                                            Pay securely with your credit/debit card, Stripe Link, Apple Pay, or Google Pay.
                                        </p>

                                        <form id="stripeCheckoutForm" method="POST" action="/checkout/process">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="provider" value="stripe">
                                            <input type="hidden" name="tier" value="<?php echo htmlspecialchars($tierSlug); ?>">
                                            <input type="hidden" name="cycle" value="<?php echo htmlspecialchars($billingCycle); ?>">
                                            <input type="hidden" name="discount_code" value="<?php echo htmlspecialchars($discountCode); ?>">

                                            <!-- Stripe redirects to hosted checkout -->
                                            <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill" id="stripePayBtn">
                                                <i class="fas fa-lock me-2"></i>
                                                Pay <?php echo $currencySymbol . number_format($total, 2); ?>
                                            </button>
                                        </form>

                                        <div class="text-center mt-3">
                                            <small class="text-muted">
                                                <i class="fas fa-shield-alt me-1"></i>
                                                Powered by Stripe — PCI DSS Level 1 certified
                                            </small>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($paypalEnabled): ?>
                                    <!-- 🟡 PayPal -->
                                    <div class="tab-pane fade <?php echo !$stripeEnabled ? 'show active' : ''; ?>"
                                         id="paypal-pane" role="tabpanel" aria-labelledby="paypal-tab">
                                        <p class="text-muted mb-3">
                                            <i class="fas fa-lock me-1"></i>
                                            Pay securely with your PayPal account.
                                        </p>

                                        <form id="paypalCheckoutForm" method="POST" action="/checkout/process">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="provider" value="paypal">
                                            <input type="hidden" name="tier" value="<?php echo htmlspecialchars($tierSlug); ?>">
                                            <input type="hidden" name="cycle" value="<?php echo htmlspecialchars($billingCycle); ?>">
                                            <input type="hidden" name="discount_code" value="<?php echo htmlspecialchars($discountCode); ?>">

                                            <button type="submit" class="btn btn-warning btn-lg w-100 rounded-pill" id="paypalPayBtn">
                                                <i class="fab fa-paypal me-2"></i>
                                                Pay with PayPal — <?php echo $currencySymbol . number_format($total, 2); ?>
                                            </button>
                                        </form>

                                        <div class="text-center mt-3">
                                            <small class="text-muted">
                                                <i class="fas fa-shield-alt me-1"></i>
                                                You will be redirected to PayPal to complete payment
                                            </small>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($cryptoEnabled): ?>
                                    <!-- 🟠 Cryptocurrency -->
                                    <?php
                                    $cryptoTotal = $cryptoDiscount > 0
                                        ? round($total * (1 - $cryptoDiscount / 100), 2)
                                        : $total;
                                    ?>
                                    <div class="tab-pane fade <?php echo (!$stripeEnabled && !$paypalEnabled) ? 'show active' : ''; ?>"
                                         id="crypto-pane" role="tabpanel" aria-labelledby="crypto-tab">
                                        <p class="text-muted mb-3">
                                            <i class="fas fa-lock me-1"></i>
                                            Pay with Bitcoin, Ethereum, USDT, or USDC.
                                        </p>

                                        <?php if ($cryptoDiscount > 0): ?>
                                            <div class="alert alert-success border-0" role="alert">
                                                <i class="fas fa-tag me-2"></i>
                                                <strong><?php echo number_format($cryptoDiscount, 0); ?>% crypto discount applied!</strong>
                                                <span class="text-decoration-line-through ms-2">
                                                    <?php echo $currencySymbol . number_format($total, 2); ?>
                                                </span>
                                                <strong class="ms-1">
                                                    <?php echo $currencySymbol . number_format($cryptoTotal, 2); ?>
                                                </strong>
                                            </div>
                                        <?php endif; ?>

                                        <form id="cryptoCheckoutForm" method="POST" action="/checkout/process">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="provider" value="coinbase">
                                            <input type="hidden" name="tier" value="<?php echo htmlspecialchars($tierSlug); ?>">
                                            <input type="hidden" name="cycle" value="<?php echo htmlspecialchars($billingCycle); ?>">
                                            <input type="hidden" name="discount_code" value="<?php echo htmlspecialchars($discountCode); ?>">

                                            <button type="submit" class="btn btn-dark btn-lg w-100 rounded-pill" id="cryptoPayBtn">
                                                <i class="fab fa-bitcoin me-2"></i>
                                                Pay with Crypto — <?php echo $currencySymbol . number_format($cryptoTotal, 2); ?>
                                            </button>
                                        </form>

                                        <div class="text-center mt-3">
                                            <small class="text-muted">
                                                <i class="fas fa-shield-alt me-1"></i>
                                                Powered by Coinbase Commerce — you will be redirected to complete payment
                                            </small>
                                        </div>
                                    </div>
                                <?php endif; ?>

                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 🎁 Discount Code -->
                <div class="card border-0 shadow-sm mt-4">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-3">
                            <i class="fas fa-gift text-primary me-2"></i>Discount Code
                        </h6>
                        <div class="input-group">
                            <input type="text" class="form-control" id="checkoutDiscountCode"
                                   placeholder="Enter promo code" maxlength="50"
                                   value="<?php echo htmlspecialchars($discountCode); ?>"
                                   aria-label="Discount code">
                            <button class="btn btn-outline-primary" type="button" id="applyDiscountBtn">
                                <i class="fas fa-check me-1"></i>Apply
                            </button>
                        </div>
                        <div id="discountMessage" class="mt-2 small" style="display: none;"></div>
                    </div>
                </div>
            </div>

            <!-- ========================================================== -->
            <!-- 📋 Order Summary (Right Column) -->
            <!-- ========================================================== -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm sticky-top" style="top: 5rem;">
                    <div class="card-header bg-primary text-white">
                        <h5 class="fw-bold mb-0">
                            <i class="fas fa-receipt me-2"></i>Order Summary
                        </h5>
                    </div>
                    <div class="card-body p-4">

                        <!-- 📦 Plan Details -->
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h6 class="fw-bold mb-0"><?php echo htmlspecialchars($tier['tierName']); ?> Plan</h6>
                                <small class="text-muted">
                                    Billed <?php echo htmlspecialchars($billingCycle); ?>
                                </small>
                            </div>
                            <span class="fw-bold">
                                <?php echo $currencySymbol . number_format($price, 2); ?>
                            </span>
                        </div>

                        <?php if ($discountAmount > 0 && $discountInfo): ?>
                            <!-- 🎁 Discount -->
                            <div class="d-flex justify-content-between text-success mb-2">
                                <span>
                                    <i class="fas fa-tag me-1"></i>
                                    Discount (<?php echo htmlspecialchars($discountCode); ?>)
                                </span>
                                <span>-<?php echo $currencySymbol . number_format($discountAmount, 2); ?></span>
                            </div>
                        <?php endif; ?>

                        <hr>

                        <!-- 💵 Subtotal -->
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal</span>
                            <span><?php echo $currencySymbol . number_format($subtotal, 2); ?></span>
                        </div>

                        <?php if ($taxEnabled && $taxAmount > 0): ?>
                            <!-- 🏛️ Tax -->
                            <div class="d-flex justify-content-between mb-2">
                                <span>VAT (<?php echo number_format($taxRate, 0); ?>%)</span>
                                <span><?php echo $currencySymbol . number_format($taxAmount, 2); ?></span>
                            </div>
                        <?php endif; ?>

                        <hr>

                        <!-- 💰 Total -->
                        <div class="d-flex justify-content-between mb-3">
                            <span class="fw-bold fs-5">Total</span>
                            <span class="fw-bold fs-5 text-primary" id="orderTotal">
                                <?php echo $currencySymbol . number_format($total, 2); ?>
                            </span>
                        </div>

                        <?php if (!empty($tier['trialDays']) && $tier['trialDays'] > 0): ?>
                            <div class="alert alert-info border-0 py-2 mb-3" role="alert">
                                <small>
                                    <i class="fas fa-clock me-1"></i>
                                    Your <?php echo intval($tier['trialDays']); ?>-day free trial starts today.
                                    You will not be charged until the trial ends.
                                </small>
                            </div>
                        <?php endif; ?>

                        <!-- ✅ Features Preview -->
                        <div class="mt-3">
                            <h6 class="fw-bold small text-muted mb-2">WHAT YOU GET</h6>
                            <?php
                            $features = json_decode($tier['features'] ?? '[]', true) ?: [];
                            $showFeatures = array_slice($features, 0, 5);
                            ?>
                            <ul class="list-unstyled small">
                                <?php foreach ($showFeatures as $feature): ?>
                                    <li class="mb-1">
                                        <i class="fas fa-check text-success me-2"></i>
                                        <?php echo htmlspecialchars($feature); ?>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (count($features) > 5): ?>
                                    <li class="text-muted">
                                        <i class="fas fa-ellipsis-h me-2"></i>
                                        and <?php echo count($features) - 5; ?> more features
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>

                        <!-- 🔒 Trust Signals -->
                        <div class="text-center mt-4 pt-3 border-top">
                            <div class="d-flex justify-content-center gap-3 text-muted small">
                                <span><i class="fas fa-lock me-1"></i>Secure</span>
                                <span><i class="fas fa-undo me-1"></i>Refundable</span>
                                <span><i class="fas fa-times-circle me-1"></i>Cancel anytime</span>
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
    .nav-pills .nav-link {
        border-radius: 0.5rem;
        padding: 0.75rem 1rem;
        font-weight: 500;
    }

    .nav-pills .nav-link:not(.active) {
        background: #f8f9fa;
        color: #495057;
    }

    .nav-pills .nav-link:not(.active):hover {
        background: #e9ecef;
    }

    .checkout-content .card {
        border-radius: 1rem;
        overflow: hidden;
    }
</style>

<!-- ⚡ JavaScript: Discount Code & Form Handling -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // ========================================================================
    // 🎁 Discount Code Application
    // ========================================================================

    var applyBtn = document.getElementById('applyDiscountBtn');
    var discountInput = document.getElementById('checkoutDiscountCode');
    var discountMsg = document.getElementById('discountMessage');

    if (applyBtn && discountInput) {
        applyBtn.addEventListener('click', function() {
            var code = discountInput.value.trim();
            if (code.length === 0) {
                return;
            }

            // 🔄 Reload page with discount code
            var url = new URL(window.location.href);
            url.searchParams.set('discount', code);
            window.location.href = url.toString();
        });

        discountInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyBtn.click();
            }
        });
    }

    // ========================================================================
    // 🔵 Stripe Form Handling
    // ========================================================================

    var stripeForm = document.getElementById('stripeCheckoutForm');
    if (stripeForm) {
        stripeForm.addEventListener('submit', function(e) {
            var btn = document.getElementById('stripePayBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Redirecting to Stripe...';
            }
        });
    }

    // ========================================================================
    // 🟡 PayPal Form Handling
    // ========================================================================

    var paypalForm = document.getElementById('paypalCheckoutForm');
    if (paypalForm) {
        paypalForm.addEventListener('submit', function(e) {
            var btn = document.getElementById('paypalPayBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Redirecting to PayPal...';
            }
        });
    }

    // ========================================================================
    // 🟠 Crypto Form Handling
    // ========================================================================

    var cryptoForm = document.getElementById('cryptoCheckoutForm');
    if (cryptoForm) {
        cryptoForm.addEventListener('submit', function(e) {
            var btn = document.getElementById('cryptoPayBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Redirecting to Coinbase...';
            }
        });
    }
});
</script>

<?php
// 📄 Include footer
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
