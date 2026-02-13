<?php
/**
 * ============================================================================
 * ⚙️ SIGNula - Payment Processor
 * ============================================================================
 *
 * Purpose: Route checkout form submissions to the appropriate payment provider
 * PHP Version: 8.3+
 *
 * This endpoint receives POST requests from the checkout page and:
 * 1. Validates CSRF token and request parameters
 * 2. Records a pending payment in tblPayments
 * 3. Creates a checkout session/order with the selected provider
 * 4. Redirects the user to the provider's hosted checkout
 *
 * Supported Providers:
 * - stripe → StripeProvider::createCheckoutSession()
 * - paypal → PayPalProvider::createOrder()
 * - coinbase → CoinbaseProvider::createCharge()
 *
 * @package    SIGNula
 * @subpackage Payments
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

// 🔒 POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /pricing');
    exit;
}

// 🔒 Authentication required
if (!Auth::isAuthenticated()) {
    header('Location: /login');
    exit;
}

// 🔐 CSRF validation
$csrfToken = $_POST['csrf_token'] ?? '';
if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
    $_SESSION['checkout_error'] = 'Security validation failed. Please try again.';
    header('Location: /checkout?' . http_build_query(['tier' => $_POST['tier'] ?? '', 'cycle' => $_POST['cycle'] ?? '']));
    exit;
}

// 👤 Current user
$currentUser = Auth::getCurrentUser();
$userID = (int) $currentUser['userID'];
$userEmail = $currentUser['email'] ?? '';

// ========================================================================
// 📋 VALIDATE PARAMETERS
// ========================================================================

$provider = trim($_POST['provider'] ?? '');
$tierSlug = trim($_POST['tier'] ?? '');
$billingCycle = trim($_POST['cycle'] ?? 'monthly');
$discountCode = trim($_POST['discount_code'] ?? '');

// 🔍 Validate provider
$validProviders = ['stripe', 'paypal', 'coinbase'];
if (!in_array($provider, $validProviders, true)) {
    $_SESSION['checkout_error'] = 'Invalid payment provider selected.';
    header('Location: /pricing');
    exit;
}

// 🔍 Validate billing cycle
if (!in_array($billingCycle, ['monthly', 'yearly'], true)) {
    $billingCycle = 'monthly';
}

// 🔍 Fetch tier
$tier = PaymentManager::getTier($tierSlug);
if ($tier === null) {
    $_SESSION['checkout_error'] = 'Invalid subscription plan selected.';
    header('Location: /pricing');
    exit;
}

// ========================================================================
// 💰 CALCULATE AMOUNTS
// ========================================================================

$price = ($billingCycle === 'yearly')
    ? floatval($tier['yearlyPrice'])
    : floatval($tier['monthlyPrice']);

$currency = $tier['currency'] ?? 'GBP';
$discountAmount = 0;

// 🎁 Validate discount code
if (!empty($discountCode)) {
    $discountResult = PaymentManager::validateDiscountCode($discountCode, $tierSlug, $provider, $price);
    if ($discountResult['valid'] ?? false) {
        $discountAmount = floatval($discountResult['discountAmount'] ?? 0);
    }
}

// 💵 Tax
$taxEnabled = getSetting('payment.tax_enabled', '1') === '1';
$taxRate = floatval(getSetting('payment.tax_rate', '20.00'));
$subtotal = $price - $discountAmount;
$taxAmount = $taxEnabled ? round($subtotal * ($taxRate / 100), 2) : 0;
$total = round($subtotal + $taxAmount, 2);

// 🪙 Apply crypto discount if applicable
if ($provider === 'coinbase') {
    $cryptoDiscount = floatval(getSetting('payment.crypto.discount_percent', '0'));
    if ($cryptoDiscount > 0) {
        $total = round($total * (1 - $cryptoDiscount / 100), 2);
    }
}

// 🔍 Determine base URLs for callbacks
$baseURL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'account.signula.id');

$successURL = $baseURL . '/checkout/success';
$cancelURL = $baseURL . '/checkout/cancel';

// ========================================================================
// ⚙️ ROUTE TO PROVIDER
// ========================================================================

try {
    switch ($provider) {

        // ==============================================================
        // 🔵 STRIPE
        // ==============================================================
        case 'stripe':
            require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'StripeProvider.php';

            if (!StripeProvider::isEnabled()) {
                throw new \RuntimeException('Stripe payments are not currently available.');
            }

            $result = StripeProvider::createCheckoutSession(
                $userID,
                (int) $tier['tierID'],
                $billingCycle,
                [
                    'customer_email' => $userEmail,
                    'success_url' => $successURL . '?session_id={CHECKOUT_SESSION_ID}&provider=stripe',
                    'cancel_url' => $cancelURL . '?provider=stripe',
                    'discount_code' => $discountCode,
                    'discount_amount' => $discountAmount,
                    'tax_amount' => $taxAmount,
                ]
            );

            if ($result['success'] ?? false) {
                // 📊 Log checkout initiation
                ActivityLogger::log(
                    $userID,
                    'checkout_stripe_initiated',
                    'payment',
                    'info',
                    'Stripe checkout session created for ' . $tier['tierName'] . ' (' . $billingCycle . ')',
                    [
                        'sessionID' => $result['sessionID'] ?? '',
                        'tierSlug' => $tierSlug,
                        'amount' => $total,
                    ]
                );

                // 🔗 Redirect to Stripe Checkout
                header('Location: ' . $result['url']);
                exit;
            }

            throw new \RuntimeException($result['error'] ?? 'Failed to create Stripe checkout session.');

        // ==============================================================
        // 🟡 PAYPAL
        // ==============================================================
        case 'paypal':
            require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'PayPalProvider.php';

            if (!PayPalProvider::isEnabled()) {
                throw new \RuntimeException('PayPal payments are not currently available.');
            }

            $description = 'SIGNula ' . $tier['tierName'] . ' Plan (' . ucfirst($billingCycle) . ')';

            $result = PayPalProvider::createOrder(
                $total,
                $currency,
                $description,
                [
                    'user_id' => $userID,
                    'tier_id' => (int) $tier['tierID'],
                    'tier_slug' => $tierSlug,
                    'billing_cycle' => $billingCycle,
                    'discount_code' => $discountCode,
                    'return_url' => $successURL . '?provider=paypal',
                    'cancel_url' => $cancelURL . '?provider=paypal',
                ]
            );

            if ($result['success'] ?? false) {
                // 💾 Store order ID in session for capture on return
                $_SESSION['paypal_order_id'] = $result['orderID'];
                $_SESSION['paypal_tier_id'] = (int) $tier['tierID'];
                $_SESSION['paypal_billing_cycle'] = $billingCycle;
                $_SESSION['paypal_amount'] = $total;

                ActivityLogger::log(
                    $userID,
                    'checkout_paypal_initiated',
                    'payment',
                    'info',
                    'PayPal order created for ' . $tier['tierName'] . ' (' . $billingCycle . ')',
                    [
                        'orderID' => $result['orderID'] ?? '',
                        'tierSlug' => $tierSlug,
                        'amount' => $total,
                    ]
                );

                // 🔗 Redirect to PayPal approval page
                header('Location: ' . $result['approvalURL']);
                exit;
            }

            throw new \RuntimeException($result['error'] ?? 'Failed to create PayPal order.');

        // ==============================================================
        // 🟠 COINBASE (Crypto)
        // ==============================================================
        case 'coinbase':
            require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'CoinbaseProvider.php';

            if (!CoinbaseProvider::isEnabled()) {
                throw new \RuntimeException('Cryptocurrency payments are not currently available.');
            }

            $description = 'SIGNula ' . $tier['tierName'] . ' Plan (' . ucfirst($billingCycle) . ')';

            $result = CoinbaseProvider::createCharge(
                $total,
                $currency,
                $description,
                $userID,
                [
                    'tier_id' => (int) $tier['tierID'],
                    'tier_slug' => $tierSlug,
                    'billing_cycle' => $billingCycle,
                    'discount_code' => $discountCode,
                    'redirect_url' => $successURL . '?provider=coinbase',
                    'cancel_url' => $cancelURL . '?provider=coinbase',
                ]
            );

            if ($result['success'] ?? false) {
                $_SESSION['coinbase_charge_id'] = $result['chargeID'];
                $_SESSION['coinbase_tier_id'] = (int) $tier['tierID'];
                $_SESSION['coinbase_billing_cycle'] = $billingCycle;

                ActivityLogger::log(
                    $userID,
                    'checkout_crypto_initiated',
                    'payment',
                    'info',
                    'Coinbase charge created for ' . $tier['tierName'] . ' (' . $billingCycle . ')',
                    [
                        'chargeID' => $result['chargeID'] ?? '',
                        'chargeCode' => $result['chargeCode'] ?? '',
                        'tierSlug' => $tierSlug,
                        'amount' => $total,
                    ]
                );

                // 🔗 Redirect to Coinbase Commerce hosted checkout
                header('Location: ' . $result['hostedURL']);
                exit;
            }

            throw new \RuntimeException($result['error'] ?? 'Failed to create crypto charge.');

        default:
            throw new \RuntimeException('Unsupported payment provider: ' . $provider);
    }

} catch (\Throwable $e) {
    // ========================================================================
    // 🚨 ERROR HANDLING
    // ========================================================================

    // 📊 Log the error
    ActivityLogger::log(
        $userID,
        'checkout_error',
        'payment',
        'error',
        'Checkout processing error: ' . $e->getMessage(),
        [
            'provider' => $provider,
            'tierSlug' => $tierSlug,
            'billingCycle' => $billingCycle,
            'amount' => $total,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]
    );

    // 🔙 Redirect back to checkout with error
    $_SESSION['checkout_error'] = 'An error occurred processing your payment. Please try again.';
    header('Location: /checkout?' . http_build_query([
        'tier' => $tierSlug,
        'cycle' => $billingCycle,
        'discount' => $discountCode,
    ]));
    exit;
}
