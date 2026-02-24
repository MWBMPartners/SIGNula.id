<?php
/**
 * ============================================================================
 * 🏷️ Provider-Specific Discounts Management (Super Admin)
 * ============================================================================
 *
 * Purpose: Super Admin UI page for managing payment provider-specific discounts.
 *          Allows configuration of global and partner-specific discount rates
 *          that incentivize customers to use preferred payment methods.
 *
 * Features:
 *   - Three provider cards (Stripe, PayPal, Coinbase) with brand-themed headers
 *   - Global discount rate configuration per provider
 *   - Partner-specific discount overrides with table view
 *   - Add/Edit/Delete partner override functionality via modals
 *   - Status badges: active (green), expired (grey), upcoming (blue), inactive (red)
 *   - AJAX-powered data loading and management via provider-discount-actions.php
 *   - CSRF-protected POST requests using jQuery $.ajax()
 *
 * Database Table: tblProviderDiscounts (see provider-discount-actions.php)
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons — FontAwesome 6.4 Icon Library
 * @see https://api.jquery.com/jquery.ajax/ — jQuery AJAX Documentation
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\Payments\ProviderDiscounts
 * @version    1.0.0
 * @since      2.3.0-beta
 * @link       https://SIGNula.id
 * ============================================================================
 */

// ============================================================================
// 🚀 BOOTSTRAP — Load core system files
// ============================================================================

// ⚙️ Main configuration (defines constants, loads settings, initializes session)
// @see web/_config/config.php — Central configuration loader
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton (MySQLi prepared statements)
// @see web/_backend/Database.php — Database abstraction layer
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🎫 Session manager (authentication state management)
// @see web/_backend/SessionManager.php — Session lifecycle handler
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🔐 Access control (role-based permissions enforcement)
// @see web/_backend/AccessControl.php — RBAC implementation
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// ============================================================================
// 🔐 AUTHENTICATION & AUTHORIZATION
// ============================================================================

// 🗄️ Initialize core services — database, session, and access control
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check — redirect unauthenticated users to login
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin check — only super admins can manage provider discounts
// This uses AccessControl::requireSuperAdmin() which handles the 403 response
$accessControl->requireSuperAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- 📄 Page title — displayed in browser tab -->
    <title>Provider-Specific Discounts - SIGNula Admin</title>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- 🎨 Bootstrap 5.3.2 CSS (CDN with Subresource Integrity)       -->
    <!-- @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Docs  -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- 🎯 FontAwesome 6.4.2 CSS (CDN with Subresource Integrity)     -->
    <!-- @see https://fontawesome.com/icons — FontAwesome Icon Library  -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- 🎨 PAGE-SPECIFIC STYLES                                        -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <style>
        /* 🎨 Base body styling — light background matching admin pages */
        body {
            background-color: #f8f9fa;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Page header — green/teal gradient (payments dashboard theme) */
        /* @see providers.php — Uses the same gradient for consistency    */
        /* ═══════════════════════════════════════════════════════════════ */
        .page-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔌 Provider cards — main discount configuration cards          */
        /* Same styling pattern as providers.php for visual consistency   */
        /* ═══════════════════════════════════════════════════════════════ */
        .provider-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: transform 0.2s;
            margin-bottom: 1.5rem;
        }
        .provider-card:hover {
            transform: translateY(-2px);
        }
        .provider-card .card-header {
            padding: 1.25rem 1.5rem;
            color: white;
            font-weight: 600;
        }
        .provider-card .card-body {
            padding: 1.5rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Provider-specific card header colour themes                 */
        /* Brand colours matching providers.php for consistency           */
        /* ═══════════════════════════════════════════════════════════════ */

        /* 💜 Stripe — official brand colour #635bff */
        /* @see https://stripe.com/docs/stripe-js — Stripe.js Reference */
        .stripe-header {
            background: linear-gradient(135deg, #635bff, #7a73ff);
        }

        /* 🔵 PayPal — official brand colour #003087 */
        /* @see https://developer.paypal.com/docs/api/ — PayPal API Ref */
        .paypal-header {
            background: linear-gradient(135deg, #003087, #0070ba);
        }

        /* 🟠 Coinbase — Bitcoin/crypto orange theme #f7931a */
        /* @see https://docs.cdp.coinbase.com/commerce-onchain/docs/    */
        .coinbase-header {
            background: linear-gradient(135deg, #f7931a, #ffb347);
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📊 Global discount section — highlighted area at top of card   */
        /* ═══════════════════════════════════════════════════════════════ */
        .global-discount-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            border: 1px solid #e9ecef;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📋 Override table — partner-specific discount overrides table  */
        /* ═══════════════════════════════════════════════════════════════ */
        .override-table {
            font-size: 0.9rem;
        }
        .override-table th {
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 2px solid #dee2e6;
        }
        .override-table td {
            vertical-align: middle;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🏷️ Status badges — colour-coded discount status indicators    */
        /* ═══════════════════════════════════════════════════════════════ */
        .badge-active {
            background-color: #28a745;
        }
        .badge-expired {
            background-color: #6c757d;
        }
        .badge-upcoming {
            background-color: #17a2b8;
        }
        .badge-inactive {
            background-color: #dc3545;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📭 Empty state — shown when no overrides exist for a provider  */
        /* ═══════════════════════════════════════════════════════════════ */
        .empty-overrides {
            text-align: center;
            padding: 2rem 1rem;
            color: #adb5bd;
        }
        .empty-overrides i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔄 Loading overlay — identical to payments dashboard pattern   */
        /* Covers the provider card during AJAX operations               */
        /* ═══════════════════════════════════════════════════════════════ */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📱 Responsive adjustments for smaller screens                  */
        /* @see https://getbootstrap.com/docs/5.3/layout/breakpoints/    */
        /* ═══════════════════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .provider-card .card-body {
                padding: 1rem;
            }
            .override-table {
                font-size: 0.8rem;
            }
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Modal header — green/teal gradient matching page theme      */
        /* ═══════════════════════════════════════════════════════════════ */
        .modal-header-themed {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🎨 PAGE HEADER — Green gradient matching payments dashboard   -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="page-header">
            <!-- 🔙 Back navigation link to payments dashboard -->
            <a href="index.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Payments Dashboard
            </a>
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">
                        <i class="fas fa-percent me-2"></i>Provider-Specific Discounts
                    </h1>
                    <p class="mb-0 opacity-75">
                        Configure discounts offered when customers pay using specific payment providers.
                        These discounts incentivize use of preferred payment methods.
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <!-- 🔄 Refresh button — reloads all discount data via AJAX -->
                    <button class="btn btn-light btn-lg" id="btnRefreshAll" onclick="loadAllDiscounts()">
                        <i class="fas fa-sync me-2"></i>Refresh
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 💬 Alert notification container — dynamically populated by    -->
        <!-- the showAlert() JavaScript function                           -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div id="alertContainer"></div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🔐 CSRF Token — hidden input for jQuery AJAX requests         -->
        <!-- @see https://owasp.org/www-community/attacks/csrf             -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🔌 PROVIDER DISCOUNT CARDS                                    -->
        <!-- Three full-width cards: Stripe, PayPal, Coinbase              -->
        <!-- Each contains global discount config + partner overrides table -->
        <!-- ═══════════════════════════════════════════════════════════════ -->

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 💜 STRIPE DISCOUNT CARD                                       -->
        <!-- @see https://docs.stripe.com/api — Stripe API Documentation  -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="provider-card position-relative" id="stripe-card">
            <!-- 💜 Stripe card header with brand gradient -->
            <div class="card-header stripe-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fa-brands fa-stripe fa-lg me-2"></i>Stripe Discounts
                </div>
                <span class="badge bg-light text-dark" id="stripe-discount-count">0 overrides</span>
            </div>
            <div class="card-body">

                <!-- 📊 Global Discount Section for Stripe -->
                <div class="global-discount-section">
                    <h6 class="fw-bold mb-3">
                        <i class="fas fa-globe me-1"></i>Global Discount
                        <small class="text-muted fw-normal ms-2">Applies to all partners unless overridden</small>
                    </h6>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <!-- 💰 Global discount percentage input -->
                            <label class="form-label fw-semibold">
                                <i class="fas fa-percentage me-1"></i>Discount %
                            </label>
                            <div class="input-group">
                                <input type="number" id="stripe-global-percent" class="form-control"
                                       min="0" max="100" step="0.01" value="0" placeholder="0.00">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <!-- 📝 Description for the global discount -->
                            <label class="form-label fw-semibold">
                                <i class="fas fa-align-left me-1"></i>Description
                            </label>
                            <input type="text" id="stripe-global-description" class="form-control"
                                   placeholder="e.g. Stripe card payment discount">
                        </div>
                        <div class="col-md-2">
                            <!-- 📅 Effective date for the global discount -->
                            <label class="form-label fw-semibold">
                                <i class="fas fa-calendar-check me-1"></i>Effective From
                            </label>
                            <input type="date" id="stripe-global-effective" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <!-- 📅 Expiry date for the global discount (optional) -->
                            <label class="form-label fw-semibold">
                                <i class="fas fa-calendar-times me-1"></i>Expires At
                            </label>
                            <input type="date" id="stripe-global-expires" class="form-control">
                        </div>
                        <div class="col-md-1">
                            <!-- 🔘 Active toggle for the global discount -->
                            <label class="form-label fw-semibold">
                                <i class="fas fa-toggle-on me-1"></i>Active
                            </label>
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" id="stripe-global-active" checked>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <!-- 💾 Save global discount button -->
                            <button class="btn btn-success w-100" onclick="saveGlobalDiscount('stripe')">
                                <i class="fas fa-save me-1"></i>Save Global
                            </button>
                        </div>
                    </div>
                    <!-- 🔑 Hidden discountID field for tracking the global discount record -->
                    <input type="hidden" id="stripe-global-id" value="">
                </div>

                <!-- 📋 Partner-Specific Overrides Table for Stripe -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-building me-1"></i>Partner-Specific Overrides
                    </h6>
                    <!-- ➕ Add Partner Override button — opens the modal -->
                    <button class="btn btn-sm btn-outline-primary" onclick="showAddOverrideModal('stripe')">
                        <i class="fas fa-plus me-1"></i>Add Partner Override
                    </button>
                </div>

                <!-- 📋 Stripe partner overrides table -->
                <div class="table-responsive">
                    <table class="table table-hover override-table mb-0">
                        <thead>
                            <tr>
                                <th>Partner Name</th>
                                <th>Discount %</th>
                                <th>Description</th>
                                <th>Effective From</th>
                                <th>Expires At</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="stripe-overrides-tbody">
                            <!-- 🔄 Populated dynamically by JavaScript -->
                            <tr>
                                <td colspan="7" class="empty-overrides">
                                    <i class="fas fa-spinner fa-spin"></i>
                                    Loading...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🔵 PAYPAL DISCOUNT CARD                                       -->
        <!-- @see https://developer.paypal.com/docs/api/ — PayPal API     -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="provider-card position-relative" id="paypal-card">
            <!-- 🔵 PayPal card header with brand gradient -->
            <div class="card-header paypal-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fa-brands fa-paypal fa-lg me-2"></i>PayPal Discounts
                </div>
                <span class="badge bg-light text-dark" id="paypal-discount-count">0 overrides</span>
            </div>
            <div class="card-body">

                <!-- 📊 Global Discount Section for PayPal -->
                <div class="global-discount-section">
                    <h6 class="fw-bold mb-3">
                        <i class="fas fa-globe me-1"></i>Global Discount
                        <small class="text-muted fw-normal ms-2">Applies to all partners unless overridden</small>
                    </h6>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-percentage me-1"></i>Discount %
                            </label>
                            <div class="input-group">
                                <input type="number" id="paypal-global-percent" class="form-control"
                                       min="0" max="100" step="0.01" value="0" placeholder="0.00">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-align-left me-1"></i>Description
                            </label>
                            <input type="text" id="paypal-global-description" class="form-control"
                                   placeholder="e.g. PayPal payment discount">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-calendar-check me-1"></i>Effective From
                            </label>
                            <input type="date" id="paypal-global-effective" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-calendar-times me-1"></i>Expires At
                            </label>
                            <input type="date" id="paypal-global-expires" class="form-control">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-toggle-on me-1"></i>Active
                            </label>
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" id="paypal-global-active" checked>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-success w-100" onclick="saveGlobalDiscount('paypal')">
                                <i class="fas fa-save me-1"></i>Save Global
                            </button>
                        </div>
                    </div>
                    <input type="hidden" id="paypal-global-id" value="">
                </div>

                <!-- 📋 Partner-Specific Overrides Table for PayPal -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-building me-1"></i>Partner-Specific Overrides
                    </h6>
                    <button class="btn btn-sm btn-outline-primary" onclick="showAddOverrideModal('paypal')">
                        <i class="fas fa-plus me-1"></i>Add Partner Override
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover override-table mb-0">
                        <thead>
                            <tr>
                                <th>Partner Name</th>
                                <th>Discount %</th>
                                <th>Description</th>
                                <th>Effective From</th>
                                <th>Expires At</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="paypal-overrides-tbody">
                            <tr>
                                <td colspan="7" class="empty-overrides">
                                    <i class="fas fa-spinner fa-spin"></i>
                                    Loading...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🟠 COINBASE DISCOUNT CARD                                     -->
        <!-- @see https://docs.cdp.coinbase.com/commerce-onchain/docs/    -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="provider-card position-relative" id="coinbase-card">
            <!-- 🟠 Coinbase card header with crypto orange gradient -->
            <div class="card-header coinbase-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fa-brands fa-bitcoin fa-lg me-2"></i>Coinbase / Crypto Discounts
                </div>
                <span class="badge bg-light text-dark" id="coinbase-discount-count">0 overrides</span>
            </div>
            <div class="card-body">

                <!-- 📊 Global Discount Section for Coinbase -->
                <div class="global-discount-section">
                    <h6 class="fw-bold mb-3">
                        <i class="fas fa-globe me-1"></i>Global Discount
                        <small class="text-muted fw-normal ms-2">Applies to all partners unless overridden</small>
                    </h6>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-percentage me-1"></i>Discount %
                            </label>
                            <div class="input-group">
                                <input type="number" id="coinbase-global-percent" class="form-control"
                                       min="0" max="100" step="0.01" value="0" placeholder="0.00">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-align-left me-1"></i>Description
                            </label>
                            <input type="text" id="coinbase-global-description" class="form-control"
                                   placeholder="e.g. 5% discount for crypto payments">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-calendar-check me-1"></i>Effective From
                            </label>
                            <input type="date" id="coinbase-global-effective" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-calendar-times me-1"></i>Expires At
                            </label>
                            <input type="date" id="coinbase-global-expires" class="form-control">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-toggle-on me-1"></i>Active
                            </label>
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" id="coinbase-global-active" checked>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-success w-100" onclick="saveGlobalDiscount('coinbase')">
                                <i class="fas fa-save me-1"></i>Save Global
                            </button>
                        </div>
                    </div>
                    <input type="hidden" id="coinbase-global-id" value="">
                </div>

                <!-- 📋 Partner-Specific Overrides Table for Coinbase -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-building me-1"></i>Partner-Specific Overrides
                    </h6>
                    <button class="btn btn-sm btn-outline-primary" onclick="showAddOverrideModal('coinbase')">
                        <i class="fas fa-plus me-1"></i>Add Partner Override
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover override-table mb-0">
                        <thead>
                            <tr>
                                <th>Partner Name</th>
                                <th>Discount %</th>
                                <th>Description</th>
                                <th>Effective From</th>
                                <th>Expires At</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="coinbase-overrides-tbody">
                            <tr>
                                <td colspan="7" class="empty-overrides">
                                    <i class="fas fa-spinner fa-spin"></i>
                                    Loading...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div><!-- /.container-fluid -->


    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📱 MODALS                                                          -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- ➕ ADD / EDIT PARTNER OVERRIDE MODAL                               -->
    <!-- Used for both creating new and editing existing partner overrides  -->
    <!-- @see https://getbootstrap.com/docs/5.3/components/modal/           -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="overrideModal" tabindex="-1" aria-labelledby="overrideModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <!-- 🎨 Modal header with green/teal gradient -->
                <div class="modal-header modal-header-themed">
                    <h5 class="modal-title" id="overrideModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>Add Partner Override
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- 🔑 Hidden fields for tracking the discount record and provider -->
                    <input type="hidden" id="override-discountID" value="">
                    <input type="hidden" id="override-provider" value="">

                    <div class="row g-3">
                        <!-- 🏢 Partner selection dropdown -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="override-partnerID">
                                <i class="fas fa-building me-1"></i>Partner
                            </label>
                            <select id="override-partnerID" class="form-select">
                                <option value="">-- Select Partner --</option>
                                <!-- 🔄 Options populated dynamically by loadPartnersList() -->
                            </select>
                            <small class="form-text text-muted">
                                Select the partner organization this override applies to.
                            </small>
                        </div>

                        <!-- 💰 Discount percentage input -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="override-discountPercent">
                                <i class="fas fa-percentage me-1"></i>Discount Percentage
                            </label>
                            <div class="input-group">
                                <input type="number" id="override-discountPercent" class="form-control"
                                       min="0" max="100" step="0.01" value="0" placeholder="0.00"
                                       required>
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="form-text text-muted">
                                Enter a value between 0.00 and 100.00.
                            </small>
                        </div>

                        <!-- 📝 Description text field -->
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="override-description">
                                <i class="fas fa-align-left me-1"></i>Description
                            </label>
                            <textarea id="override-description" class="form-control" rows="2"
                                      placeholder="e.g. Special crypto discount for Premium Partners"></textarea>
                        </div>

                        <!-- 📅 Effective date -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="override-effectiveDate">
                                <i class="fas fa-calendar-check me-1"></i>Effective From
                            </label>
                            <input type="date" id="override-effectiveDate" class="form-control" required>
                        </div>

                        <!-- 📅 Expiry date (optional) -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="override-expiresAt">
                                <i class="fas fa-calendar-times me-1"></i>Expires At
                                <small class="text-muted fw-normal">(optional)</small>
                            </label>
                            <input type="date" id="override-expiresAt" class="form-control">
                            <small class="form-text text-muted">
                                Leave blank for no expiration.
                            </small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <!-- ❌ Cancel button -->
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <!-- 💾 Save override button -->
                    <button type="button" class="btn btn-success" id="btnSaveOverride" onclick="saveOverrideDiscount()">
                        <i class="fas fa-save me-1"></i>Save Override
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🗑️ DELETE CONFIRMATION MODAL                                       -->
    <!-- Confirms deactivation before executing the soft-delete             -->
    <!-- @see https://getbootstrap.com/docs/5.3/components/modal/           -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <!-- 🔴 Danger-themed modal header -->
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel">
                        <i class="fas fa-exclamation-triangle me-2"></i>Confirm Deactivation
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- 🔑 Hidden field to track which discount to deactivate -->
                    <input type="hidden" id="delete-discountID" value="">
                    <p class="mb-2">Are you sure you want to deactivate this provider discount?</p>
                    <p class="text-muted small mb-0" id="delete-detail-text">
                        <!-- 📝 Populated dynamically with discount details -->
                    </p>
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        This is a soft-delete operation. The discount will be marked as inactive but not permanently removed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger" id="btnConfirmDelete" onclick="confirmDeleteDiscount()">
                        <i class="fas fa-trash me-1"></i>Deactivate
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📦 JAVASCRIPT LIBRARIES                                            -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->

    <!-- 🔗 jQuery 3.7.1 (CDN) — Required for $.ajax() calls -->
    <!-- @see https://api.jquery.com/ — jQuery API Documentation -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"
            crossorigin="anonymous"></script>

    <!-- 🔐 Bootstrap 5.3.2 JS Bundle (CDN with SRI) — includes Popper.js -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🧠 PAGE LOGIC — Client-side JavaScript for discount management     -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <script>
    /**
     * ============================================================================
     * 🏷️ Provider-Specific Discounts — Client-Side Logic
     * ============================================================================
     *
     * Handles AJAX loading, creation, editing, and deactivation of payment
     * provider discounts. Communicates with the backend API at:
     *   ../api/provider-discount-actions.php
     *
     * Uses jQuery $.ajax() for all API communications, with CSRF tokens
     * read from the hidden #csrf_token input field.
     *
     * @see https://api.jquery.com/jquery.ajax/ — jQuery AJAX Documentation
     * @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Prevention
     * ============================================================================
     */

    // ═══════════════════════════════════════════════════════════════
    // 🔧 CONSTANTS & CONFIGURATION
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔗 Base URL for the provider discount management API
     * All AJAX calls in this page target this endpoint
     */
    const API_URL = '../api/provider-discount-actions.php';

    /**
     * 🏷️ List of valid provider identifiers
     * Must match the ENUM values in tblProviderDiscounts.provider column
     */
    const PROVIDERS = ['stripe', 'paypal', 'coinbase'];

    /**
     * 🎨 Provider display configuration — icons and names for UI rendering
     */
    const PROVIDER_DISPLAY = {
        'stripe':   { name: 'Stripe',   icon: 'fa-brands fa-stripe'  },
        'paypal':   { name: 'PayPal',   icon: 'fa-brands fa-paypal'  },
        'coinbase': { name: 'Coinbase', icon: 'fa-brands fa-bitcoin' }
    };

    /** @type {Array} Cached list of partners for dropdown population */
    let cachedPartners = [];


    // ═══════════════════════════════════════════════════════════════
    // 🛠️ UTILITY FUNCTIONS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔒 Escape HTML entities to prevent Cross-Site Scripting (XSS) attacks.
     * Creates a temporary text node to safely encode user-supplied content.
     *
     * @param {string} text — Raw text to escape
     * @returns {string} HTML-safe escaped string
     *
     * @see https://owasp.org/www-community/attacks/xss/ — OWASP XSS Prevention
     */
    function escapeHtml(text) {
        if (!text && text !== 0) {
            return '';
        }
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    /**
     * 💬 Show a Bootstrap alert notification in the #alertContainer.
     * Alerts auto-dismiss after 5 seconds. Multiple alerts can stack.
     *
     * @param {string} message — Alert message content (can include safe HTML)
     * @param {string} type — Bootstrap alert type: 'success', 'danger', 'warning', 'info'
     *
     * @see https://getbootstrap.com/docs/5.3/components/alerts/ — Bootstrap Alerts
     */
    function showAlert(message, type) {
        type = type || 'info';
        const alertEl = document.createElement('div');
        alertEl.className = 'alert alert-' + type + ' alert-dismissible fade show';
        alertEl.innerHTML =
            message +
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        document.getElementById('alertContainer').appendChild(alertEl);

        // ⏰ Auto-dismiss after 5 seconds to prevent alert stacking
        setTimeout(function() {
            if (alertEl.parentNode) {
                alertEl.remove();
            }
        }, 5000);
    }

    /**
     * 📅 Format a date string (YYYY-MM-DD) for human-readable display.
     * Returns an en-dash for null/empty values.
     *
     * @param {string|null} dateStr — Date string in YYYY-MM-DD format
     * @returns {string} Formatted date (e.g., "13 Feb 2026") or em-dash
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date/toLocaleDateString
     */
    function formatDate(dateStr) {
        if (!dateStr) {
            return '<span class="text-muted">&mdash;</span>';
        }
        // 📅 Parse the date and format using locale-aware formatting
        var d = new Date(dateStr + 'T00:00:00'); // 🔧 Append time to avoid timezone issues
        return d.toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
    }

    /**
     * 🏷️ Generate an HTML status badge based on computed status.
     *
     * Status-to-badge mapping:
     *   - 'active'   → green (bg-success)
     *   - 'expired'  → grey (bg-secondary)
     *   - 'upcoming' → blue (bg-info)
     *   - 'inactive' → red (bg-danger)
     *
     * @param {string} status — Computed status from the API
     * @returns {string} HTML string for the Bootstrap badge
     */
    function statusBadge(status) {
        var badgeClass = 'bg-secondary'; // Default fallback
        var label = status || 'unknown';

        switch ((status || '').toLowerCase()) {
            case 'active':
                badgeClass = 'badge-active';
                label = 'Active';
                break;
            case 'expired':
                badgeClass = 'badge-expired';
                label = 'Expired';
                break;
            case 'upcoming':
                badgeClass = 'badge-upcoming';
                label = 'Upcoming';
                break;
            case 'inactive':
                badgeClass = 'badge-inactive';
                label = 'Inactive';
                break;
        }

        return '<span class="badge ' + badgeClass + '">' + escapeHtml(label) + '</span>';
    }

    /**
     * 🔐 Get the current CSRF token value from the hidden input field.
     *
     * @returns {string} The CSRF token string
     */
    function getCSRFToken() {
        return $('#csrf_token').val();
    }

    /**
     * 📅 Get today's date in YYYY-MM-DD format for default field values.
     *
     * @returns {string} Today's date as YYYY-MM-DD
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date/toISOString
     */
    function getTodayDateString() {
        return new Date().toISOString().split('T')[0];
    }


    // ═══════════════════════════════════════════════════════════════
    // 📥 LOAD ALL DISCOUNTS — Main data loading function
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📥 Load all provider discounts from the API and populate the UI.
     *
     * Makes a GET request to the provider-discount-actions.php API,
     * retrieves discounts grouped by provider, then calls the appropriate
     * render functions for each provider's global discount and overrides table.
     *
     * Also loads the partner list for the dropdown if not already cached.
     *
     * @see ../api/provider-discount-actions.php?action=get_provider_discounts
     */
    function loadAllDiscounts() {
        // 🔄 Show loading state on the refresh button
        var $refreshBtn = $('#btnRefreshAll');
        var originalHtml = $refreshBtn.html();
        $refreshBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Loading...');

        // 📥 AJAX GET request to fetch all discounts
        // @see https://api.jquery.com/jquery.ajax/ — jQuery AJAX
        $.ajax({
            url: API_URL,
            method: 'GET',
            data: {
                action: 'get_provider_discounts'
            },
            dataType: 'json',

            /**
             * ✅ Success callback — discounts loaded successfully
             * @param {Object} response — API response with grouped discounts
             */
            success: function(response) {
                if (response.success) {
                    // 🔌 Process each provider's discounts
                    PROVIDERS.forEach(function(provider) {
                        var providerDiscounts = response.discounts[provider] || [];
                        populateProviderCard(provider, providerDiscounts);
                    });
                } else {
                    // ⚠️ API returned success: false
                    showAlert(
                        '<i class="fas fa-exclamation-triangle me-1"></i> ' +
                        escapeHtml(response.message || 'Failed to load provider discounts.'),
                        'danger'
                    );
                }
            },

            /**
             * ❌ Error callback — network or server error
             * @param {Object} jqXHR — jQuery XHR object
             * @param {string} textStatus — Error type (timeout, error, abort, etc.)
             * @param {string} errorThrown — HTTP error message
             */
            error: function(jqXHR, textStatus, errorThrown) {
                showAlert(
                    '<i class="fas fa-times-circle me-1"></i> Error loading discounts: ' +
                    escapeHtml(textStatus + ' — ' + errorThrown),
                    'danger'
                );
            },

            /**
             * 🔄 Complete callback — always runs after success or error
             * Restores the refresh button to its original state
             */
            complete: function() {
                $refreshBtn.prop('disabled', false).html(originalHtml);
            }
        });

        // 🏢 Also load the partners list for the dropdown (if not cached)
        if (cachedPartners.length === 0) {
            loadPartnersList();
        }
    }


    // ═══════════════════════════════════════════════════════════════
    // 🏗️ POPULATE PROVIDER CARDS — Render data into the UI
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🏗️ Populate a provider card with discount data.
     *
     * Separates the discounts into:
     *   - Global discount (partnerID IS NULL) — populates the global section
     *   - Partner overrides (partnerID IS NOT NULL) — populates the overrides table
     *
     * @param {string} provider — Provider identifier: 'stripe', 'paypal', or 'coinbase'
     * @param {Array} discounts — Array of discount objects for this provider
     */
    function populateProviderCard(provider, discounts) {
        // 🔪 Separate global discount from partner-specific overrides
        var globalDiscount = null;
        var overrides = [];

        discounts.forEach(function(d) {
            if (d.partnerID === null || d.partnerID === '' || d.partnerID === 0 || d.partnerID === '0') {
                // 🌐 This is the global discount for this provider
                // If multiple globals exist (shouldn't happen), use the first active one
                if (!globalDiscount || (d.isActive == 1 && globalDiscount.isActive != 1)) {
                    globalDiscount = d;
                }
            } else {
                // 🏢 This is a partner-specific override
                overrides.push(d);
            }
        });

        // 📊 Populate the global discount section
        populateGlobalSection(provider, globalDiscount);

        // 📋 Populate the partner overrides table
        renderOverridesTable(provider, overrides);

        // 🏷️ Update the override count badge in the card header
        var activeOverrides = overrides.filter(function(o) {
            return o.computedStatus === 'active' || o.computedStatus === 'upcoming';
        });
        $('#' + provider + '-discount-count').text(
            overrides.length + ' override' + (overrides.length !== 1 ? 's' : '') +
            ' (' + activeOverrides.length + ' active)'
        );
    }

    /**
     * 📊 Populate the global discount section for a provider.
     *
     * If a global discount record exists, fills in the form fields.
     * If none exists, resets the fields to empty/default values.
     *
     * @param {string} provider — Provider identifier
     * @param {Object|null} globalDiscount — Global discount object or null
     */
    function populateGlobalSection(provider, globalDiscount) {
        if (globalDiscount) {
            // 📊 Fill in the global discount form fields with existing data
            $('#' + provider + '-global-id').val(globalDiscount.discountID || '');
            $('#' + provider + '-global-percent').val(parseFloat(globalDiscount.discountPercent) || 0);
            $('#' + provider + '-global-description').val(globalDiscount.description || '');
            $('#' + provider + '-global-effective').val(globalDiscount.effectiveDate || '');
            $('#' + provider + '-global-expires').val(globalDiscount.expiresAt || '');
            $('#' + provider + '-global-active').prop('checked', globalDiscount.isActive == 1);
        } else {
            // 📭 No global discount exists — set defaults
            $('#' + provider + '-global-id').val('');
            $('#' + provider + '-global-percent').val(0);
            $('#' + provider + '-global-description').val('');
            $('#' + provider + '-global-effective').val(getTodayDateString());
            $('#' + provider + '-global-expires').val('');
            $('#' + provider + '-global-active').prop('checked', true);
        }
    }

    /**
     * 📋 Render the partner-specific overrides table for a provider.
     *
     * Generates HTML table rows for each partner override, including
     * partner name, discount %, description, dates, status badge,
     * and action buttons (Edit, Delete).
     *
     * Shows an empty state message if no overrides exist.
     *
     * @param {string} provider — Provider identifier
     * @param {Array} overrides — Array of partner override discount objects
     */
    function renderOverridesTable(provider, overrides) {
        var $tbody = $('#' + provider + '-overrides-tbody');

        // 📭 Handle empty state — no partner overrides for this provider
        if (!overrides || overrides.length === 0) {
            $tbody.html(
                '<tr>' +
                    '<td colspan="7" class="empty-overrides">' +
                        '<i class="fas fa-inbox"></i>' +
                        '<p class="mb-0">No partner-specific overrides configured for ' +
                        escapeHtml(PROVIDER_DISPLAY[provider].name) + '.</p>' +
                        '<small class="text-muted">Click "Add Partner Override" to create one.</small>' +
                    '</td>' +
                '</tr>'
            );
            return;
        }

        // 📋 Build table rows for each partner override
        var rowsHtml = '';
        overrides.forEach(function(override) {
            rowsHtml +=
                '<tr data-discount-id="' + escapeHtml(override.discountID) + '">' +
                    // 🏢 Partner Name column
                    '<td>' +
                        '<strong>' + escapeHtml(override.partnerName || 'Unknown Partner') + '</strong>' +
                        '<br><small class="text-muted">ID: ' + escapeHtml(override.partnerID) + '</small>' +
                    '</td>' +
                    // 💰 Discount Percentage column
                    '<td>' +
                        '<span class="fw-bold text-success">' +
                            escapeHtml(parseFloat(override.discountPercent).toFixed(2)) + '%' +
                        '</span>' +
                    '</td>' +
                    // 📝 Description column (truncated for long text)
                    '<td>' +
                        '<span title="' + escapeHtml(override.description || '') + '">' +
                            escapeHtml(truncateText(override.description || '', 50)) +
                        '</span>' +
                    '</td>' +
                    // 📅 Effective From column
                    '<td>' + formatDate(override.effectiveDate) + '</td>' +
                    // 📅 Expires At column
                    '<td>' + formatDate(override.expiresAt) + '</td>' +
                    // 🏷️ Status badge column
                    '<td>' + statusBadge(override.computedStatus) + '</td>' +
                    // 🔧 Actions column (Edit + Delete buttons)
                    '<td class="text-center">' +
                        '<div class="btn-group btn-group-sm" role="group">' +
                            // ✏️ Edit button
                            '<button class="btn btn-outline-primary" ' +
                                'onclick="showEditOverrideModal(' + escapeHtml(override.discountID) + ', \'' + escapeHtml(provider) + '\')" ' +
                                'title="Edit this override">' +
                                '<i class="fas fa-edit"></i>' +
                            '</button>' +
                            // 🗑️ Delete (deactivate) button
                            '<button class="btn btn-outline-danger" ' +
                                'onclick="showDeleteModal(' + escapeHtml(override.discountID) + ', \'' + escapeHtml(provider) + '\', \'' + escapeHtml(override.partnerName || 'Unknown') + '\')" ' +
                                'title="Deactivate this override">' +
                                '<i class="fas fa-trash"></i>' +
                            '</button>' +
                        '</div>' +
                    '</td>' +
                '</tr>';
        });

        $tbody.html(rowsHtml);
    }

    /**
     * ✂️ Truncate text to a maximum length with ellipsis.
     *
     * @param {string} text — Text to truncate
     * @param {number} maxLength — Maximum character length
     * @returns {string} Truncated text with "..." appended if needed
     */
    function truncateText(text, maxLength) {
        if (!text) {
            return '';
        }
        if (text.length <= maxLength) {
            return text;
        }
        return text.substring(0, maxLength) + '...';
    }


    // ═══════════════════════════════════════════════════════════════
    // 🏢 LOAD PARTNERS LIST — For dropdown population
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🏢 Load the list of active partners from the API.
     *
     * Fetches partner data and caches it in the cachedPartners variable
     * for reuse without additional API calls. The data is used to
     * populate the partner selection dropdown in the override modal.
     *
     * @see ../api/provider-discount-actions.php?action=get_partners_list
     */
    function loadPartnersList() {
        $.ajax({
            url: API_URL,
            method: 'GET',
            data: {
                action: 'get_partners_list'
            },
            dataType: 'json',

            /**
             * ✅ Success — partners loaded and cached
             * @param {Object} response — API response with partners array
             */
            success: function(response) {
                if (response.success && response.partners) {
                    // 📦 Cache the partners list for future modal opens
                    cachedPartners = response.partners;
                }
            },

            /**
             * ❌ Error — failed to load partners (non-critical, modal will show warning)
             */
            error: function() {
                // ⚠️ Silently fail — the modal will show "No partners available" if needed
                console.warn('Failed to load partners list for dropdown.');
            }
        });
    }

    /**
     * 🏢 Populate the partner dropdown in the override modal.
     *
     * Uses the cached partners list to build <option> elements.
     * Optionally pre-selects a specific partnerID (for edit mode).
     *
     * @param {number|null} selectedPartnerID — ID to pre-select (null for none)
     */
    function populatePartnerDropdown(selectedPartnerID) {
        var $select = $('#override-partnerID');

        // 🧹 Clear existing options (keep the placeholder)
        $select.html('<option value="">-- Select Partner --</option>');

        // 📭 Handle case where no partners are available
        if (!cachedPartners || cachedPartners.length === 0) {
            $select.append('<option value="" disabled>No partners available</option>');
            return;
        }

        // 🏗️ Build option elements from cached partner data
        cachedPartners.forEach(function(partner) {
            var isSelected = (selectedPartnerID && partner.partnerID == selectedPartnerID) ? ' selected' : '';
            $select.append(
                '<option value="' + escapeHtml(partner.partnerID) + '"' + isSelected + '>' +
                    escapeHtml(partner.organizationName) +
                '</option>'
            );
        });
    }


    // ═══════════════════════════════════════════════════════════════
    // 💾 SAVE GLOBAL DISCOUNT — Create/Update global provider discount
    // ═══════════════════════════════════════════════════════════════

    /**
     * 💾 Save the global discount for a specific provider.
     *
     * Reads form field values from the provider's global discount section,
     * validates them, and sends a POST request to the save_provider_discount
     * API action. If a discountID exists (hidden field), it performs an UPDATE;
     * otherwise, it performs an INSERT.
     *
     * @param {string} provider — Provider identifier: 'stripe', 'paypal', or 'coinbase'
     *
     * @see ../api/provider-discount-actions.php — action: save_provider_discount
     */
    function saveGlobalDiscount(provider) {
        // 📥 Read form field values for this provider's global discount
        var discountID      = $('#' + provider + '-global-id').val();
        var discountPercent = parseFloat($('#' + provider + '-global-percent').val()) || 0;
        var description     = $('#' + provider + '-global-description').val().trim();
        var effectiveDate   = $('#' + provider + '-global-effective').val();
        var expiresAt       = $('#' + provider + '-global-expires').val();
        var isActive        = $('#' + provider + '-global-active').is(':checked') ? 1 : 0;

        // ✅ Client-side validation — effective date is required
        if (!effectiveDate) {
            showAlert(
                '<i class="fas fa-exclamation-triangle me-1"></i> Please enter an effective date for the ' +
                escapeHtml(PROVIDER_DISPLAY[provider].name) + ' global discount.',
                'warning'
            );
            return;
        }

        // ✅ Client-side validation — discount must be between 0 and 100
        if (discountPercent < 0 || discountPercent > 100) {
            showAlert(
                '<i class="fas fa-exclamation-triangle me-1"></i> Discount percentage must be between 0 and 100.',
                'warning'
            );
            return;
        }

        // 🏗️ Build the request payload
        var payload = {
            action:          'save_provider_discount',
            provider:        provider,
            partnerID:       null,       // 🌐 NULL = global discount
            discountPercent: discountPercent,
            description:     description,
            effectiveDate:   effectiveDate,
            expiresAt:       expiresAt || null,
            isActive:        isActive,
            csrf_token:      getCSRFToken()
        };

        // 🔑 Include discountID if updating an existing record
        if (discountID) {
            payload.discountID = parseInt(discountID);
        }

        // 📤 Send POST request via jQuery AJAX
        $.ajax({
            url: API_URL,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            dataType: 'json',

            /**
             * ✅ Success — global discount saved
             */
            success: function(response) {
                if (response.success) {
                    showAlert(
                        '<i class="fas fa-check-circle me-1"></i> ' +
                        escapeHtml(PROVIDER_DISPLAY[provider].name) +
                        ' global discount saved successfully.',
                        'success'
                    );
                    // 🔑 Store the returned discountID for future updates
                    if (response.discountID) {
                        $('#' + provider + '-global-id').val(response.discountID);
                    }
                    // 🔄 Reload all discounts to refresh the UI
                    loadAllDiscounts();
                } else {
                    showAlert(
                        '<i class="fas fa-times-circle me-1"></i> ' +
                        escapeHtml(response.message || 'Failed to save global discount.'),
                        'danger'
                    );
                }
            },

            /**
             * ❌ Error — network or server error
             */
            error: function(jqXHR, textStatus, errorThrown) {
                showAlert(
                    '<i class="fas fa-times-circle me-1"></i> Error saving global discount: ' +
                    escapeHtml(textStatus + ' — ' + errorThrown),
                    'danger'
                );
            }
        });
    }


    // ═══════════════════════════════════════════════════════════════
    // ➕ ADD PARTNER OVERRIDE — Show modal for new override
    // ═══════════════════════════════════════════════════════════════

    /**
     * ➕ Show the "Add Partner Override" modal for a specific provider.
     *
     * Resets all modal form fields to their default values and sets the
     * provider context. The partner dropdown is populated from the cached
     * partners list.
     *
     * @param {string} provider — Provider identifier: 'stripe', 'paypal', or 'coinbase'
     */
    function showAddOverrideModal(provider) {
        // 🏷️ Update the modal title to indicate "Add" mode
        $('#overrideModalLabel').html(
            '<i class="fas fa-plus-circle me-2"></i>Add Partner Override — ' +
            escapeHtml(PROVIDER_DISPLAY[provider].name)
        );

        // 🧹 Reset all form fields to defaults
        $('#override-discountID').val('');
        $('#override-provider').val(provider);
        $('#override-discountPercent').val('');
        $('#override-description').val('');
        $('#override-effectiveDate').val(getTodayDateString());
        $('#override-expiresAt').val('');

        // 🏢 Populate the partner dropdown (no pre-selection)
        populatePartnerDropdown(null);

        // 🔓 Enable the partner dropdown (it's disabled in edit mode)
        $('#override-partnerID').prop('disabled', false);

        // 💾 Update the save button text
        $('#btnSaveOverride').html('<i class="fas fa-save me-1"></i>Save Override');

        // 📱 Show the modal
        // @see https://getbootstrap.com/docs/5.3/components/modal/#via-javascript
        new bootstrap.Modal(document.getElementById('overrideModal')).show();
    }


    // ═══════════════════════════════════════════════════════════════
    // ✏️ EDIT PARTNER OVERRIDE — Show modal for editing existing override
    // ═══════════════════════════════════════════════════════════════

    /**
     * ✏️ Show the "Edit Partner Override" modal with pre-filled data.
     *
     * Fetches all discounts from the API, finds the matching record by
     * discountID, and populates the modal form fields with its values.
     * The partner dropdown is disabled in edit mode (partner cannot change).
     *
     * @param {number} discountID — The discount record ID to edit
     * @param {string} provider — Provider identifier for context
     */
    function showEditOverrideModal(discountID, provider) {
        // 📥 Fetch current data to populate the edit form
        // We could store the data client-side, but fetching ensures we have the latest
        $.ajax({
            url: API_URL,
            method: 'GET',
            data: {
                action: 'get_provider_discounts'
            },
            dataType: 'json',

            /**
             * ✅ Success — find the matching discount and populate the modal
             */
            success: function(response) {
                if (!response.success) {
                    showAlert(
                        '<i class="fas fa-times-circle me-1"></i> Failed to load discount data for editing.',
                        'danger'
                    );
                    return;
                }

                // 🔍 Find the specific discount record by ID
                var targetDiscount = null;
                PROVIDERS.forEach(function(p) {
                    (response.discounts[p] || []).forEach(function(d) {
                        if (d.discountID == discountID) {
                            targetDiscount = d;
                        }
                    });
                });

                if (!targetDiscount) {
                    showAlert(
                        '<i class="fas fa-times-circle me-1"></i> Discount record not found (ID: ' +
                        escapeHtml(discountID) + ').',
                        'danger'
                    );
                    return;
                }

                // 🏷️ Update the modal title to indicate "Edit" mode
                $('#overrideModalLabel').html(
                    '<i class="fas fa-edit me-2"></i>Edit Partner Override — ' +
                    escapeHtml(PROVIDER_DISPLAY[provider].name)
                );

                // 📝 Populate form fields with existing data
                $('#override-discountID').val(targetDiscount.discountID);
                $('#override-provider').val(provider);
                $('#override-discountPercent').val(parseFloat(targetDiscount.discountPercent) || 0);
                $('#override-description').val(targetDiscount.description || '');
                $('#override-effectiveDate').val(targetDiscount.effectiveDate || '');
                $('#override-expiresAt').val(targetDiscount.expiresAt || '');

                // 🏢 Populate and pre-select the partner dropdown
                populatePartnerDropdown(targetDiscount.partnerID);

                // 🔒 Disable the partner dropdown in edit mode (partner cannot change)
                $('#override-partnerID').prop('disabled', true);

                // 💾 Update the save button text
                $('#btnSaveOverride').html('<i class="fas fa-save me-1"></i>Update Override');

                // 📱 Show the modal
                new bootstrap.Modal(document.getElementById('overrideModal')).show();
            },

            /**
             * ❌ Error — failed to load discount data
             */
            error: function(jqXHR, textStatus, errorThrown) {
                showAlert(
                    '<i class="fas fa-times-circle me-1"></i> Error loading discount data: ' +
                    escapeHtml(textStatus),
                    'danger'
                );
            }
        });
    }


    // ═══════════════════════════════════════════════════════════════
    // 💾 SAVE OVERRIDE DISCOUNT — Create or update partner override
    // ═══════════════════════════════════════════════════════════════

    /**
     * 💾 Save a partner-specific discount override.
     *
     * Reads values from the override modal form, validates them,
     * and sends a POST request to the save_provider_discount API action.
     * After success, closes the modal and reloads all discounts.
     *
     * @see ../api/provider-discount-actions.php — action: save_provider_discount
     */
    function saveOverrideDiscount() {
        // 📥 Read form field values from the modal
        var discountID      = $('#override-discountID').val();
        var provider        = $('#override-provider').val();
        var partnerID       = $('#override-partnerID').val();
        var discountPercent = parseFloat($('#override-discountPercent').val()) || 0;
        var description     = $('#override-description').val().trim();
        var effectiveDate   = $('#override-effectiveDate').val();
        var expiresAt       = $('#override-expiresAt').val();

        // ════════════════════════════════════════════════════════════
        // ✅ CLIENT-SIDE VALIDATION
        // ════════════════════════════════════════════════════════════

        // 🏢 Partner is required for overrides
        if (!partnerID) {
            showAlert(
                '<i class="fas fa-exclamation-triangle me-1"></i> Please select a partner.',
                'warning'
            );
            return;
        }

        // 💰 Discount percentage must be a valid number between 0 and 100
        if (discountPercent <= 0 || discountPercent > 100) {
            showAlert(
                '<i class="fas fa-exclamation-triangle me-1"></i> Discount percentage must be between 0.01 and 100.',
                'warning'
            );
            return;
        }

        // 📅 Effective date is required
        if (!effectiveDate) {
            showAlert(
                '<i class="fas fa-exclamation-triangle me-1"></i> Please enter an effective date.',
                'warning'
            );
            return;
        }

        // 📅 If both dates are provided, expiry must be on or after effective date
        if (expiresAt && expiresAt < effectiveDate) {
            showAlert(
                '<i class="fas fa-exclamation-triangle me-1"></i> Expiry date cannot be before the effective date.',
                'warning'
            );
            return;
        }

        // ════════════════════════════════════════════════════════════
        // 📤 BUILD AND SEND THE REQUEST
        // ════════════════════════════════════════════════════════════

        // 🏗️ Build the request payload
        var payload = {
            action:          'save_provider_discount',
            provider:        provider,
            partnerID:       parseInt(partnerID),
            discountPercent: discountPercent,
            description:     description,
            effectiveDate:   effectiveDate,
            expiresAt:       expiresAt || null,
            isActive:        1,    // 🔘 New overrides are always active
            csrf_token:      getCSRFToken()
        };

        // 🔑 Include discountID if updating an existing record
        if (discountID) {
            payload.discountID = parseInt(discountID);
        }

        // 🔄 Disable the save button to prevent double-clicks
        var $saveBtn = $('#btnSaveOverride');
        var originalBtnHtml = $saveBtn.html();
        $saveBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Saving...');

        // 📤 Send POST request via jQuery AJAX
        $.ajax({
            url: API_URL,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            dataType: 'json',

            /**
             * ✅ Success — override saved
             */
            success: function(response) {
                if (response.success) {
                    showAlert(
                        '<i class="fas fa-check-circle me-1"></i> ' +
                        escapeHtml(response.message || 'Partner override saved successfully.'),
                        'success'
                    );
                    // 📱 Close the modal
                    bootstrap.Modal.getInstance(document.getElementById('overrideModal')).hide();
                    // 🔄 Reload all discounts to refresh the UI
                    loadAllDiscounts();
                } else {
                    showAlert(
                        '<i class="fas fa-times-circle me-1"></i> ' +
                        escapeHtml(response.message || 'Failed to save partner override.'),
                        'danger'
                    );
                }
            },

            /**
             * ❌ Error — network or server error
             */
            error: function(jqXHR, textStatus, errorThrown) {
                // 🔍 Try to parse the error response for a more specific message
                var errorMsg = textStatus + ' — ' + errorThrown;
                try {
                    var errResponse = JSON.parse(jqXHR.responseText);
                    if (errResponse.message) {
                        errorMsg = errResponse.message;
                    }
                } catch (e) {
                    // 🔄 Fallback to generic error message
                }
                showAlert(
                    '<i class="fas fa-times-circle me-1"></i> Error saving override: ' +
                    escapeHtml(errorMsg),
                    'danger'
                );
            },

            /**
             * 🔄 Complete — restore save button state
             */
            complete: function() {
                $saveBtn.prop('disabled', false).html(originalBtnHtml);
            }
        });
    }


    // ═══════════════════════════════════════════════════════════════
    // 🗑️ DELETE (DEACTIVATE) DISCOUNT — Confirmation and execution
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🗑️ Show the delete confirmation modal for a specific discount.
     *
     * Populates the modal with details about the discount being deactivated
     * and stores the discountID for the confirmation action.
     *
     * @param {number} discountID — The discount record ID to deactivate
     * @param {string} provider — Provider name for display purposes
     * @param {string} partnerName — Partner name for display purposes
     */
    function showDeleteModal(discountID, provider, partnerName) {
        // 🔑 Store the discount ID in the hidden field
        $('#delete-discountID').val(discountID);

        // 📝 Populate the detail text with discount information
        $('#delete-detail-text').html(
            '<strong>Provider:</strong> ' + escapeHtml(PROVIDER_DISPLAY[provider].name) + '<br>' +
            '<strong>Partner:</strong> ' + escapeHtml(partnerName) + '<br>' +
            '<strong>Discount ID:</strong> ' + escapeHtml(discountID)
        );

        // 📱 Show the delete confirmation modal
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    /**
     * 🗑️ Confirm and execute the discount deactivation.
     *
     * Called when the user clicks "Deactivate" in the delete confirmation modal.
     * Sends a POST request to the delete_provider_discount API action.
     *
     * @see ../api/provider-discount-actions.php — action: delete_provider_discount
     */
    function confirmDeleteDiscount() {
        var discountID = parseInt($('#delete-discountID').val());

        // ✅ Validate — discountID must be a positive integer
        if (!discountID || discountID <= 0) {
            showAlert(
                '<i class="fas fa-exclamation-triangle me-1"></i> Invalid discount ID for deactivation.',
                'warning'
            );
            return;
        }

        // 🔄 Disable the confirm button to prevent double-clicks
        var $confirmBtn = $('#btnConfirmDelete');
        var originalBtnHtml = $confirmBtn.html();
        $confirmBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Deactivating...');

        // 📤 Send POST request to deactivate the discount
        $.ajax({
            url: API_URL,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action:     'delete_provider_discount',
                discountID: discountID,
                csrf_token: getCSRFToken()
            }),
            dataType: 'json',

            /**
             * ✅ Success — discount deactivated
             */
            success: function(response) {
                if (response.success) {
                    showAlert(
                        '<i class="fas fa-check-circle me-1"></i> ' +
                        escapeHtml(response.message || 'Discount deactivated successfully.'),
                        'success'
                    );
                    // 📱 Close the modal
                    bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
                    // 🔄 Reload all discounts to refresh the UI
                    loadAllDiscounts();
                } else {
                    showAlert(
                        '<i class="fas fa-times-circle me-1"></i> ' +
                        escapeHtml(response.message || 'Failed to deactivate discount.'),
                        'danger'
                    );
                }
            },

            /**
             * ❌ Error — network or server error
             */
            error: function(jqXHR, textStatus, errorThrown) {
                var errorMsg = textStatus + ' — ' + errorThrown;
                try {
                    var errResponse = JSON.parse(jqXHR.responseText);
                    if (errResponse.message) {
                        errorMsg = errResponse.message;
                    }
                } catch (e) {
                    // 🔄 Fallback to generic error message
                }
                showAlert(
                    '<i class="fas fa-times-circle me-1"></i> Error deactivating discount: ' +
                    escapeHtml(errorMsg),
                    'danger'
                );
            },

            /**
             * 🔄 Complete — restore button state
             */
            complete: function() {
                $confirmBtn.prop('disabled', false).html(originalBtnHtml);
            }
        });
    }


    // ═══════════════════════════════════════════════════════════════
    // 🚀 INITIAL LOAD — Load all data when the page is ready
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🎯 Initialize the page by loading all provider discount data.
     *
     * Uses jQuery's document ready handler to ensure the DOM is fully
     * loaded before making AJAX calls and manipulating DOM elements.
     *
     * @see https://api.jquery.com/ready/ — jQuery .ready() Documentation
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Document/DOMContentLoaded_event
     */
    $(document).ready(function() {
        // 📥 Load all provider discounts and partners list
        loadAllDiscounts();

        // ⌨️ Enter key handler for discount percentage fields
        // Pressing Enter in a global discount field triggers save
        PROVIDERS.forEach(function(provider) {
            $('#' + provider + '-global-percent').on('keypress', function(e) {
                if (e.which === 13) { // 13 = Enter key
                    e.preventDefault();
                    saveGlobalDiscount(provider);
                }
            });
        });
    });
    </script>
</body>
</html>
