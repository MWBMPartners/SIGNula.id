<?php
/**
 * ============================================================================
 * ❌ SIGNula - Payment Cancelled Page
 * ============================================================================
 *
 * Purpose: Display cancellation message when user cancels checkout
 * PHP Version: 8.3+
 *
 * Shown when a user clicks "Cancel" or navigates away from a
 * payment provider's checkout (Stripe, PayPal, or Coinbase).
 * No payment is charged. Offers option to return to pricing.
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

// 📊 Log cancellation
$provider = trim($_GET['provider'] ?? 'unknown');
if (Auth::isAuthenticated()) {
    $currentUser = Auth::getCurrentUser();
    ActivityLogger::log(
        (int) $currentUser['userID'],
        'checkout_cancelled',
        'payment',
        'info',
        'User cancelled checkout from provider: ' . $provider,
        ['provider' => $provider]
    );
}

// 🎨 Page metadata
$pageTitle = 'Checkout Cancelled - SIGNula';
$metaDescription = 'Your checkout has been cancelled. No payment was charged.';
$currentPage = 'pricing';

require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>

<!-- ❌ Cancellation Section -->
<section class="payment-cancelled py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6 text-center">

                <!-- 🎯 Cancel Icon -->
                <div class="mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning bg-opacity-10"
                         style="width: 120px; height: 120px;">
                        <i class="fas fa-times-circle text-warning" style="font-size: 3rem;"></i>
                    </div>
                </div>

                <!-- 📋 Cancellation Message -->
                <h1 class="h2 fw-bold mb-3">Checkout Cancelled</h1>

                <p class="text-muted lead mb-4">
                    Your checkout has been cancelled and no payment was charged.
                    You can return to the pricing page to choose a plan whenever you are ready.
                </p>

                <!-- 🔗 Action Buttons -->
                <div class="d-flex justify-content-center gap-3 mt-4">
                    <a href="/pricing" class="btn btn-primary btn-lg rounded-pill">
                        <i class="fas fa-tags me-2"></i>View Plans
                    </a>
                    <a href="/dashboard" class="btn btn-outline-secondary btn-lg rounded-pill">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                </div>

                <!-- 💬 Help Section -->
                <div class="mt-5 p-4 bg-light rounded-3">
                    <h5 class="fw-bold mb-2">
                        <i class="fas fa-question-circle text-primary me-2"></i>Need Help?
                    </h5>
                    <p class="text-muted mb-0">
                        If you experienced any issues during checkout, please
                        <a href="/docs/faq">check our FAQ</a> or contact our support team.
                    </p>
                </div>

            </div>
        </div>
    </div>
</section>

<?php
require_once SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
