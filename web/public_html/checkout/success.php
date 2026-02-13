<?php
/**
 * ============================================================================
 * ✅ SIGNula - Payment Success Page
 * ============================================================================
 *
 * Purpose: Display payment confirmation after successful checkout
 * PHP Version: 8.3+
 *
 * This page handles the return redirect from payment providers:
 * - Stripe: Verifies checkout session, captures payment
 * - PayPal: Captures the approved order
 * - Coinbase: Displays pending confirmation (blockchain settlement)
 *
 * Calls PaymentManager::completePayment() and createSubscription()
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

// 🔒 Authentication required
if (!Auth::isAuthenticated()) {
    header('Location: /login');
    exit;
}

$currentUser = Auth::getCurrentUser();
$userID = (int) $currentUser['userID'];
$provider = trim($_GET['provider'] ?? '');

// ========================================================================
// ⚙️ PROVIDER-SPECIFIC POST-PAYMENT PROCESSING
// ========================================================================

$paymentStatus = 'unknown';
$statusMessage = '';
$statusIcon = 'fas fa-question-circle';
$statusColor = 'warning';
$receiptURL = null;
$subscriptionCreated = false;

try {
    switch ($provider) {

        // ==============================================================
        // 🔵 STRIPE — Verify checkout session
        // ==============================================================
        case 'stripe':
            $sessionID = trim($_GET['session_id'] ?? '');

            if (!empty($sessionID)) {
                require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'StripeProvider.php';

                // 🔍 Retrieve session details from Stripe
                $session = StripeProvider::retrieveCheckoutSession($sessionID);

                if (($session['payment_status'] ?? '') === 'paid' || ($session['status'] ?? '') === 'complete') {
                    $paymentStatus = 'success';
                    $statusMessage = 'Your payment has been processed successfully!';
                    $statusIcon = 'fas fa-check-circle';
                    $statusColor = 'success';
                    $receiptURL = $session['payment_intent']['charges']['data'][0]['receipt_url'] ?? null;

                    // 📊 Log success
                    ActivityLogger::log(
                        $userID,
                        'checkout_stripe_completed',
                        'payment',
                        'info',
                        'Stripe checkout completed successfully',
                        ['sessionID' => $sessionID]
                    );
                } else {
                    $paymentStatus = 'pending';
                    $statusMessage = 'Your payment is being processed. You will receive a confirmation email shortly.';
                    $statusIcon = 'fas fa-hourglass-half';
                    $statusColor = 'info';
                }
            }
            break;

        // ==============================================================
        // 🟡 PAYPAL — Capture approved order
        // ==============================================================
        case 'paypal':
            $paypalOrderID = $_SESSION['paypal_order_id'] ?? '';

            if (!empty($paypalOrderID)) {
                require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'PayPalProvider.php';

                // 💰 Capture the approved order
                $captureResult = PayPalProvider::captureOrder($paypalOrderID);

                if ($captureResult['success'] ?? false) {
                    $paymentStatus = 'success';
                    $statusMessage = 'Your PayPal payment has been processed successfully!';
                    $statusIcon = 'fas fa-check-circle';
                    $statusColor = 'success';

                    // 🔗 Create subscription if applicable
                    $tierID = $_SESSION['paypal_tier_id'] ?? null;
                    $billingCycle = $_SESSION['paypal_billing_cycle'] ?? 'monthly';

                    if ($tierID) {
                        $subResult = PaymentManager::createSubscription(
                            $userID,
                            $tierID,
                            $billingCycle,
                            'paypal'
                        );
                        $subscriptionCreated = $subResult['success'] ?? false;
                    }

                    // 🧹 Clean up session
                    unset($_SESSION['paypal_order_id'], $_SESSION['paypal_tier_id'],
                          $_SESSION['paypal_billing_cycle'], $_SESSION['paypal_amount']);

                    ActivityLogger::log(
                        $userID,
                        'checkout_paypal_completed',
                        'payment',
                        'info',
                        'PayPal checkout completed and captured',
                        ['orderID' => $paypalOrderID, 'subscriptionCreated' => $subscriptionCreated]
                    );
                } else {
                    $paymentStatus = 'failed';
                    $statusMessage = 'We were unable to capture your PayPal payment. Please try again.';
                    $statusIcon = 'fas fa-times-circle';
                    $statusColor = 'danger';
                }
            }
            break;

        // ==============================================================
        // 🟠 COINBASE — Display pending (blockchain confirmation needed)
        // ==============================================================
        case 'coinbase':
            $paymentStatus = 'pending';
            $statusMessage = 'Your cryptocurrency payment has been initiated. '
                . 'It may take a few minutes for the blockchain to confirm the transaction. '
                . 'You will receive an email once your payment is confirmed.';
            $statusIcon = 'fas fa-hourglass-half';
            $statusColor = 'info';

            // 🧹 Clean up session
            unset($_SESSION['coinbase_charge_id'], $_SESSION['coinbase_tier_id'],
                  $_SESSION['coinbase_billing_cycle']);

            ActivityLogger::log(
                $userID,
                'checkout_crypto_returned',
                'payment',
                'info',
                'User returned from Coinbase Commerce checkout (awaiting blockchain confirmation)',
                []
            );
            break;

        default:
            $paymentStatus = 'unknown';
            $statusMessage = 'Payment status could not be determined. If you were charged, your subscription will be activated shortly.';
            $statusIcon = 'fas fa-question-circle';
            $statusColor = 'warning';
    }

} catch (\Throwable $e) {
    $paymentStatus = 'error';
    $statusMessage = 'An error occurred verifying your payment. If you were charged, please contact support.';
    $statusIcon = 'fas fa-exclamation-triangle';
    $statusColor = 'danger';

    ActivityLogger::log(
        $userID,
        'checkout_success_error',
        'payment',
        'error',
        'Error on payment success page: ' . $e->getMessage(),
        ['provider' => $provider, 'file' => $e->getFile(), 'line' => $e->getLine()]
    );
}

// 🎨 Page metadata
$pageTitle = 'Payment ' . ucfirst($paymentStatus) . ' - SIGNula';
$metaDescription = 'Your SIGNula payment status.';
$currentPage = 'pricing';

require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>

<!-- ✅ Payment Status Section -->
<section class="payment-status py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6 text-center">

                <!-- 🎯 Status Icon -->
                <div class="mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-<?php echo $statusColor; ?> bg-opacity-10"
                         style="width: 120px; height: 120px;">
                        <i class="<?php echo $statusIcon; ?> text-<?php echo $statusColor; ?>" style="font-size: 3rem;"></i>
                    </div>
                </div>

                <!-- 📋 Status Message -->
                <h1 class="h2 fw-bold mb-3">
                    <?php
                    switch ($paymentStatus) {
                        case 'success':
                            echo 'Payment Successful!';
                            break;
                        case 'pending':
                            echo 'Payment Processing';
                            break;
                        case 'failed':
                            echo 'Payment Failed';
                            break;
                        default:
                            echo 'Payment Status';
                    }
                    ?>
                </h1>

                <p class="text-muted lead mb-4">
                    <?php echo htmlspecialchars($statusMessage); ?>
                </p>

                <?php if ($subscriptionCreated): ?>
                    <div class="alert alert-success border-0" role="alert">
                        <i class="fas fa-crown me-2"></i>
                        <strong>Your subscription is now active!</strong>
                        You can manage it from your account settings.
                    </div>
                <?php endif; ?>

                <?php if ($receiptURL): ?>
                    <a href="<?php echo htmlspecialchars($receiptURL); ?>" target="_blank" rel="noopener noreferrer"
                       class="btn btn-outline-primary mb-3">
                        <i class="fas fa-receipt me-2"></i>View Receipt
                    </a>
                <?php endif; ?>

                <!-- 🔗 Action Buttons -->
                <div class="d-flex justify-content-center gap-3 mt-4">
                    <a href="/dashboard" class="btn btn-primary btn-lg rounded-pill">
                        <i class="fas fa-tachometer-alt me-2"></i>Go to Dashboard
                    </a>
                    <a href="/settings" class="btn btn-outline-secondary btn-lg rounded-pill">
                        <i class="fas fa-cog me-2"></i>Account Settings
                    </a>
                </div>

                <?php if ($paymentStatus === 'failed'): ?>
                    <div class="mt-4">
                        <a href="/pricing" class="btn btn-warning rounded-pill">
                            <i class="fas fa-redo me-2"></i>Try Again
                        </a>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</section>

<?php
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
