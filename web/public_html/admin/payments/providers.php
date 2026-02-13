<?php
/**
 * 🔌 Payment Provider Configuration (Super Admin)
 *
 * Configure Stripe, PayPal, and Coinbase Commerce payment provider
 * credentials, modes, and connection settings.
 *
 * @see https://docs.stripe.com/api — Stripe API
 * @see https://developer.paypal.com/docs/api/ — PayPal API
 * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/ — Coinbase Commerce
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.3.0-beta
 */

require_once dirname(__DIR__, 3) . '/_config/config.php';
require_once dirname(__DIR__, 3) . '/_backend/Database.php';
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';
require_once dirname(__DIR__, 3) . '/_backend/AccessControl.php';

// 🔌 Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin check
$accessControl->requireSuperAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Providers - SIGNula Admin</title>

    <!-- 🎨 Bootstrap 5.3.2 (CDN with SRI) -->
    <!-- @see https://getbootstrap.com/docs/5.3/ — Bootstrap Documentation -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- 🎯 FontAwesome 6.4.2 (CDN with SRI) -->
    <!-- @see https://fontawesome.com/icons — FontAwesome Icon Library -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <style>
        /* 🎨 Base body styling — matches existing admin pages */
        body { background-color: #f8f9fa; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Page header — green/teal theme matching payments dashboard */
        /* ═══════════════════════════════════════════════════════════════ */
        .page-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔌 Provider cards — main configuration cards for each gateway */
        /* ═══════════════════════════════════════════════════════════════ */
        .provider-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: transform 0.2s;
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
        /* 🔑 Credential fields — password inputs with visibility toggle */
        /* @see https://owasp.org/www-community/controls/Credential_Management */
        /* ═══════════════════════════════════════════════════════════════ */
        .credential-field {
            position: relative;
        }
        .credential-field .toggle-visibility {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            z-index: 5;
            padding: 0.25rem 0.5rem;
        }
        .credential-field .toggle-visibility:hover {
            color: #212529;
        }
        .credential-field input {
            padding-right: 2.5rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🟢 Status indicator dot — shows provider active/inactive state */
        /* ═══════════════════════════════════════════════════════════════ */
        .provider-status {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
        }
        .provider-status.active { background-color: #28a745; }
        .provider-status.inactive { background-color: #dc3545; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📋 Webhook logs table card — matches table-card from index.php */
        /* ═══════════════════════════════════════════════════════════════ */
        .table-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .table-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            padding: 1rem 1.5rem;
        }

        /* 🏷️ Webhook status badges */
        .badge-processed { background-color: #28a745; }
        .badge-failed { background-color: #dc3545; }
        .badge-pending { background-color: #ffc107; color: #333; }
        .badge-duplicate { background-color: #6c757d; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔄 Loading overlay — identical to payments dashboard pattern */
        /* ═══════════════════════════════════════════════════════════════ */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Provider-specific card header colour themes                */
        /* ═══════════════════════════════════════════════════════════════ */

        /* 💜 Stripe — official brand colour #635bff */
        /* @see https://stripe.com/docs/stripe-js — Stripe.js Reference */
        .stripe-header { background: linear-gradient(135deg, #635bff, #7a73ff); }

        /* 🔵 PayPal — official brand colour #003087 */
        /* @see https://developer.paypal.com/docs/api/ — PayPal API Reference */
        .paypal-header { background: linear-gradient(135deg, #003087, #0070ba); }

        /* 🟠 Cryptocurrency — Bitcoin orange theme #f7931a */
        /* @see https://docs.cdp.coinbase.com/commerce-onchain/docs/ — Coinbase Commerce API */
        .crypto-header { background: linear-gradient(135deg, #f7931a, #ffb347); }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📱 Responsive form adjustments for smaller screens            */
        /* ═══════════════════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .provider-card .card-body {
                padding: 1rem;
            }
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
                        <i class="fas fa-plug me-2"></i>Payment Providers
                    </h1>
                    <p class="mb-0 opacity-75">Configure Stripe, PayPal, and cryptocurrency payment processing</p>
                </div>
                <div class="col-md-4 text-end">
                    <!-- 🔄 Refresh button — reloads all provider data via AJAX -->
                    <button class="btn btn-light btn-lg" onclick="loadProviders()">
                        <i class="fas fa-sync me-2"></i>Refresh
                    </button>
                </div>
            </div>
        </div>

        <!-- 💬 Alert notification container — dynamically populated by showAlert() -->
        <div id="alertContainer"></div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🔌 PROVIDER CONFIGURATION CARDS                               -->
        <!-- Three cards in a row: Stripe, PayPal, Cryptocurrency          -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="row g-4 mb-4">

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 💜 STRIPE CONFIGURATION CARD                           -->
            <!-- @see https://docs.stripe.com/api — Stripe API Docs    -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="col-lg-4">
                <div class="provider-card">
                    <!-- 💜 Stripe card header with brand colour -->
                    <div class="card-header stripe-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fa-brands fa-stripe fa-lg me-2"></i>Stripe
                        </div>
                        <!-- 🏷️ Enabled/Disabled badge — updated dynamically -->
                        <span id="stripe-status-badge" class="badge bg-secondary">Loading...</span>
                    </div>
                    <div class="card-body">
                        <!-- 🔘 Enabled toggle switch -->
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="stripe-enabled" role="switch">
                            <label class="form-check-label fw-semibold" for="stripe-enabled">
                                <i class="fas fa-power-off me-1"></i>Enable Stripe
                            </label>
                        </div>

                        <!-- 🔀 Mode selector: Test / Live -->
                        <!-- @see https://docs.stripe.com/test-mode — Stripe Test Mode -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-toggle-on me-1"></i>Mode</label>
                            <select id="stripe-mode" class="form-select">
                                <option value="test">Test (Sandbox)</option>
                                <option value="live">Live (Production)</option>
                            </select>
                        </div>

                        <!-- 🔑 Publishable Key — shown in full (not masked) -->
                        <!-- @see https://docs.stripe.com/keys — Stripe API Keys -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-key me-1"></i>Publishable Key</label>
                            <input type="text" id="stripe-publishable-key" class="form-control" placeholder="pk_test_..." autocomplete="off">
                        </div>

                        <!-- 🔒 Secret Key — masked by default with reveal toggle -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-lock me-1"></i>Secret Key</label>
                            <div class="credential-field">
                                <input type="password" id="stripe-secret-key" class="form-control" placeholder="sk_test_..." autocomplete="off">
                                <button type="button" class="toggle-visibility" onclick="togglePasswordVisibility('stripe-secret-key')" title="Toggle visibility">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- 🔒 Webhook Secret — masked by default with reveal toggle -->
                        <!-- @see https://docs.stripe.com/webhooks — Stripe Webhooks -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-satellite-dish me-1"></i>Webhook Secret</label>
                            <div class="credential-field">
                                <input type="password" id="stripe-webhook-secret" class="form-control" placeholder="whsec_..." autocomplete="off">
                                <button type="button" class="toggle-visibility" onclick="togglePasswordVisibility('stripe-webhook-secret')" title="Toggle visibility">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- 🔗 Stripe Link toggle — enables Stripe Link for faster checkout -->
                        <!-- @see https://docs.stripe.com/payments/link — Stripe Link -->
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="stripe-link-enabled" role="switch">
                            <label class="form-check-label fw-semibold" for="stripe-link-enabled">
                                <i class="fas fa-link me-1"></i>Enable Stripe Link
                            </label>
                        </div>

                        <!-- 💳 Accepted payment methods — checkboxes -->
                        <!-- @see https://docs.stripe.com/payments/payment-methods — Stripe Payment Methods -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-wallet me-1"></i>Payment Methods</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="stripe-method-card" checked>
                                        <label class="form-check-label" for="stripe-method-card">
                                            <i class="fas fa-credit-card me-1"></i>Card
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="stripe-method-link">
                                        <label class="form-check-label" for="stripe-method-link">
                                            <i class="fas fa-link me-1"></i>Link
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="stripe-method-apple-pay">
                                        <label class="form-check-label" for="stripe-method-apple-pay">
                                            <i class="fa-brands fa-apple-pay me-1"></i>Apple Pay
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="stripe-method-google-pay">
                                        <label class="form-check-label" for="stripe-method-google-pay">
                                            <i class="fa-brands fa-google-pay me-1"></i>Google Pay
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 🔧 Action buttons — Test Connection + Save -->
                        <div class="d-grid gap-2">
                            <button id="test-stripe-btn" class="btn btn-outline-primary" onclick="testConnection('stripe')">
                                <i class="fas fa-plug me-1"></i>Test Connection
                            </button>
                            <button class="btn btn-success" onclick="saveProvider('stripe')">
                                <i class="fas fa-save me-1"></i>Save Stripe Settings
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 🔵 PAYPAL CONFIGURATION CARD                           -->
            <!-- @see https://developer.paypal.com/docs/api/ — PayPal  -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="col-lg-4">
                <div class="provider-card">
                    <!-- 🔵 PayPal card header with brand colour -->
                    <div class="card-header paypal-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fa-brands fa-paypal fa-lg me-2"></i>PayPal
                        </div>
                        <!-- 🏷️ Enabled/Disabled badge — updated dynamically -->
                        <span id="paypal-status-badge" class="badge bg-secondary">Loading...</span>
                    </div>
                    <div class="card-body">
                        <!-- 🔘 Enabled toggle switch -->
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="paypal-enabled" role="switch">
                            <label class="form-check-label fw-semibold" for="paypal-enabled">
                                <i class="fas fa-power-off me-1"></i>Enable PayPal
                            </label>
                        </div>

                        <!-- 🔀 Mode selector: Sandbox / Live -->
                        <!-- @see https://developer.paypal.com/tools/sandbox/ — PayPal Sandbox -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-toggle-on me-1"></i>Mode</label>
                            <select id="paypal-mode" class="form-select">
                                <option value="sandbox">Sandbox (Testing)</option>
                                <option value="live">Live (Production)</option>
                            </select>
                        </div>

                        <!-- 🔑 Client ID — shown in full (not masked) -->
                        <!-- @see https://developer.paypal.com/api/rest/#link-getcredentials — PayPal Credentials -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-id-badge me-1"></i>Client ID</label>
                            <input type="text" id="paypal-client-id" class="form-control" placeholder="AaBbCc..." autocomplete="off">
                        </div>

                        <!-- 🔒 Client Secret — masked by default with reveal toggle -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-lock me-1"></i>Client Secret</label>
                            <div class="credential-field">
                                <input type="password" id="paypal-client-secret" class="form-control" placeholder="EeMmNn..." autocomplete="off">
                                <button type="button" class="toggle-visibility" onclick="togglePasswordVisibility('paypal-client-secret')" title="Toggle visibility">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- 📝 Webhook ID — plain text input -->
                        <!-- @see https://developer.paypal.com/docs/api/webhooks/v1/ — PayPal Webhooks API -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-satellite-dish me-1"></i>Webhook ID</label>
                            <input type="text" id="paypal-webhook-id" class="form-control" placeholder="WH-xxxxxxxx..." autocomplete="off">
                        </div>

                        <!-- 💰 Funding Sources — checkboxes for available payment options -->
                        <!-- @see https://developer.paypal.com/sdk/js/configuration/#link-fundingsource — Funding Sources -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-wallet me-1"></i>Funding Sources</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="paypal-fund-paypal" checked>
                                        <label class="form-check-label" for="paypal-fund-paypal">
                                            <i class="fa-brands fa-paypal me-1"></i>PayPal
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="paypal-fund-venmo">
                                        <label class="form-check-label" for="paypal-fund-venmo">
                                            <i class="fas fa-money-bill me-1"></i>Venmo
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="paypal-fund-paylater">
                                        <label class="form-check-label" for="paypal-fund-paylater">
                                            <i class="fas fa-clock me-1"></i>Pay Later
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="paypal-fund-card">
                                        <label class="form-check-label" for="paypal-fund-card">
                                            <i class="fas fa-credit-card me-1"></i>Card
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 🔧 Action buttons — Test Connection + Save -->
                        <div class="d-grid gap-2">
                            <button id="test-paypal-btn" class="btn btn-outline-primary" onclick="testConnection('paypal')">
                                <i class="fas fa-plug me-1"></i>Test Connection
                            </button>
                            <button class="btn btn-success" onclick="saveProvider('paypal')">
                                <i class="fas fa-save me-1"></i>Save PayPal Settings
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 🟠 CRYPTOCURRENCY / COINBASE COMMERCE CARD             -->
            <!-- @see https://docs.cdp.coinbase.com/commerce-onchain/docs/ -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="col-lg-4">
                <div class="provider-card">
                    <!-- 🟠 Crypto card header with Bitcoin orange theme -->
                    <div class="card-header crypto-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fa-brands fa-bitcoin fa-lg me-2"></i>Cryptocurrency
                        </div>
                        <!-- 🏷️ Enabled/Disabled badge — updated dynamically -->
                        <span id="crypto-status-badge" class="badge bg-secondary">Loading...</span>
                    </div>
                    <div class="card-body">
                        <!-- 🔘 Enabled toggle switch -->
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="crypto-enabled" role="switch">
                            <label class="form-check-label fw-semibold" for="crypto-enabled">
                                <i class="fas fa-power-off me-1"></i>Enable Crypto Payments
                            </label>
                        </div>

                        <!-- 🔒 API Key — masked by default with reveal toggle -->
                        <!-- @see https://docs.cdp.coinbase.com/commerce-onchain/docs/getting-started — Coinbase API Keys -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-key me-1"></i>API Key</label>
                            <div class="credential-field">
                                <input type="password" id="crypto-api-key" class="form-control" placeholder="Coinbase Commerce API Key..." autocomplete="off">
                                <button type="button" class="toggle-visibility" onclick="togglePasswordVisibility('crypto-api-key')" title="Toggle visibility">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- 🔒 Webhook Secret — masked by default with reveal toggle -->
                        <!-- @see https://docs.cdp.coinbase.com/commerce-onchain/docs/webhooks — Coinbase Webhooks -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-satellite-dish me-1"></i>Webhook Secret</label>
                            <div class="credential-field">
                                <input type="password" id="crypto-webhook-secret" class="form-control" placeholder="Coinbase Webhook Secret..." autocomplete="off">
                                <button type="button" class="toggle-visibility" onclick="togglePasswordVisibility('crypto-webhook-secret')" title="Toggle visibility">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- 🪙 Supported Cryptocurrencies — checkboxes for accepted coins -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-coins me-1"></i>Supported Currencies</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="crypto-currency-btc" checked>
                                        <label class="form-check-label" for="crypto-currency-btc">
                                            <i class="fa-brands fa-bitcoin me-1"></i>BTC
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="crypto-currency-eth" checked>
                                        <label class="form-check-label" for="crypto-currency-eth">
                                            <i class="fa-brands fa-ethereum me-1"></i>ETH
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="crypto-currency-usdt">
                                        <label class="form-check-label" for="crypto-currency-usdt">
                                            <i class="fas fa-dollar-sign me-1"></i>USDT
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="crypto-currency-usdc">
                                        <label class="form-check-label" for="crypto-currency-usdc">
                                            <i class="fas fa-dollar-sign me-1"></i>USDC
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 💰 Crypto Discount — percentage discount for crypto payments -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="fas fa-percentage me-1"></i>Crypto Payment Discount</label>
                            <div class="input-group">
                                <input type="number" id="crypto-discount" class="form-control" min="0" max="100" step="0.1" value="0" placeholder="0">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="form-text text-muted">Discount applied when paying with cryptocurrency</small>
                        </div>

                        <!-- 🔧 Action buttons — Test Connection + Save -->
                        <div class="d-grid gap-2">
                            <button id="test-crypto-btn" class="btn btn-outline-primary" onclick="testConnection('crypto')">
                                <i class="fas fa-plug me-1"></i>Test Connection
                            </button>
                            <button class="btn btn-success" onclick="saveProvider('crypto')">
                                <i class="fas fa-save me-1"></i>Save Crypto Settings
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📋 WEBHOOK LOGS SECTION — Collapsible table of inbound events -->
        <!-- @see https://getbootstrap.com/docs/5.3/components/collapse/   -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="card table-card position-relative">
            <!-- 📋 Webhook logs header with collapse toggle -->
            <div class="table-header d-flex justify-content-between align-items-center"
                 data-bs-toggle="collapse"
                 data-bs-target="#webhookLogsBody"
                 aria-expanded="false"
                 aria-controls="webhookLogsBody"
                 style="cursor: pointer;">
                <h5 class="mb-0">
                    <i class="fas fa-satellite-dish me-2"></i>Recent Webhook Logs
                    <i class="fas fa-chevron-down ms-2 small" id="webhookCollapseIcon"></i>
                </h5>
                <div>
                    <!-- 🔄 Refresh button (stops event propagation to prevent collapse toggle) -->
                    <button class="btn btn-sm btn-light" onclick="event.stopPropagation(); loadWebhookLogs();" title="Refresh logs">
                        <i class="fas fa-sync"></i>
                    </button>
                </div>
            </div>

            <!-- 📋 Collapsible webhook logs content -->
            <div class="collapse" id="webhookLogsBody">
                <!-- 🔄 Loading overlay for webhook logs -->
                <div id="webhookLoading" class="loading-overlay" style="display: none;">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <!-- 📋 Webhook logs table -->
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Provider</th>
                                <th>Event Type</th>
                                <th>Event ID</th>
                                <th>Status</th>
                                <th>Verified</th>
                                <th>IP</th>
                                <th>Time</th>
                                <th>Processing Time</th>
                            </tr>
                        </thead>
                        <tbody id="webhookLogsTableBody">
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fas fa-satellite-dish fa-2x mb-2 d-block opacity-50"></i>
                                    Click to expand and load webhook logs
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 📄 Load More button for pagination -->
                <div class="d-flex justify-content-center p-3 border-top">
                    <button id="loadMoreWebhooksBtn" class="btn btn-outline-success btn-sm" onclick="loadMoreWebhookLogs()" style="display: none;">
                        <i class="fas fa-arrow-down me-1"></i>Load More
                    </button>
                    <span id="webhookLogCount" class="text-muted small ms-3"></span>
                </div>
            </div>
        </div>

    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- 🔐 Bootstrap 5.3.2 JS Bundle (CDN with SRI)                   -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- 🔐 CSRF Token — generated server-side for form protection -->
    <!-- @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Prevention -->
    <script>const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';</script>

    <script>
    /**
     * 🔌 Payment Provider Configuration — Client-Side Logic
     *
     * Handles AJAX loading, saving, and testing of payment provider settings.
     * Communicates with the backend API at ../api/provider-actions.php
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API — Fetch API Reference
     * @see https://docs.stripe.com/api — Stripe API Documentation
     * @see https://developer.paypal.com/docs/api/ — PayPal API Documentation
     * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/ — Coinbase Commerce Docs
     */

    // ═══════════════════════════════════════════════════════════════
    // 🔧 STATE TRACKING
    // ═══════════════════════════════════════════════════════════════

    /** @type {number} Current webhook log offset for pagination */
    let webhookLogOffset = 0;

    /** @type {number} Number of webhook logs to fetch per request */
    const webhookLogLimit = 50;

    // ═══════════════════════════════════════════════════════════════
    // 🛠️ UTILITY FUNCTIONS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔒 Escape HTML entities to prevent XSS attacks
     * Creates a temporary text node to safely encode user-supplied content.
     *
     * @param {string} text — Raw text to escape
     * @returns {string} HTML-safe escaped string
     * @see https://owasp.org/www-community/attacks/xss/ — OWASP XSS Prevention
     */
    function escapeHtml(text) {
        if (!text) { return ''; }
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * 💬 Show a Bootstrap alert notification in the alert container.
     * Alerts auto-dismiss after 5 seconds.
     *
     * @param {string} message — Alert message content (can include HTML)
     * @param {string} type — Bootstrap alert type: success, danger, warning, info
     * @see https://getbootstrap.com/docs/5.3/components/alerts/ — Bootstrap Alerts
     */
    function showAlert(message, type = 'info') {
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.getElementById('alertContainer').appendChild(alert);

        // ⏰ Auto-dismiss after 5 seconds
        setTimeout(() => {
            if (alert.parentNode) { alert.remove(); }
        }, 5000);
    }

    /**
     * 📅 Format an ISO date string for human-readable display.
     *
     * @param {string} dateStr — ISO 8601 date string from the API
     * @returns {string} Formatted date (e.g., "13 Feb 2026, 14:30:00")
     * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date/toLocaleDateString
     */
    function formatDateTime(dateStr) {
        if (!dateStr) { return '—'; }
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    }

    /**
     * 👁️ Toggle password/text visibility on credential input fields.
     * Switches the input type between 'password' and 'text' and
     * toggles the eye/eye-slash icon accordingly.
     *
     * @param {string} inputId — The ID of the input element to toggle
     * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/input/password
     */
    function togglePasswordVisibility(inputId) {
        const input = document.getElementById(inputId);
        // 🔄 Find the icon element within the toggle button (sibling of input)
        const button = input.parentElement.querySelector('.toggle-visibility');
        const icon = button.querySelector('i');

        if (input.type === 'password') {
            // 👁️ Show the value — change to text input
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            // 🔒 Hide the value — change back to password input
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    /**
     * 🏷️ Update a provider's status badge to show Enabled or Disabled.
     *
     * @param {string} provider — Provider identifier (stripe, paypal, crypto)
     * @param {boolean} isEnabled — Whether the provider is currently enabled
     */
    function updateStatusBadge(provider, isEnabled) {
        const badge = document.getElementById(`${provider}-status-badge`);
        if (badge) {
            if (isEnabled) {
                badge.className = 'badge bg-success';
                badge.textContent = 'Enabled';
            } else {
                badge.className = 'badge bg-secondary';
                badge.textContent = 'Disabled';
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 📥 LOAD PROVIDER CONFIGURATION
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📥 Load all provider configurations from the backend API.
     * Fetches settings for Stripe, PayPal, and Crypto and populates
     * the corresponding form fields.
     *
     * @see ../api/provider-actions.php?action=get_providers — Backend endpoint
     */
    async function loadProviders() {
        try {
            const response = await fetch('../api/provider-actions.php?action=get_providers');
            const result = await response.json();

            if (result.success) {
                // 🔌 Populate each provider's form with returned data
                populateStripeForm(result.providers.stripe || {});
                populatePayPalForm(result.providers.paypal || {});
                populateCryptoForm(result.providers.crypto || {});
                showAlert('<i class="fas fa-check-circle me-1"></i> Provider settings loaded successfully', 'success');
            } else {
                showAlert(result.message || 'Failed to load provider settings', 'danger');
            }
        } catch (error) {
            // ⚠️ Network/parsing error — show user-friendly message
            showAlert('Error loading provider settings: ' + escapeHtml(error.message), 'danger');
        }
    }

    /**
     * 💜 Populate the Stripe configuration form with data from the API.
     * Sets toggle switches, mode selector, credential fields, and payment method checkboxes.
     *
     * @param {Object} data — Stripe provider settings object from the API
     * @see https://docs.stripe.com/api — Stripe API Reference
     */
    function populateStripeForm(data) {
        // 🔘 Set the enabled toggle
        document.getElementById('stripe-enabled').checked = data.enabled === true || data.enabled === 1 || data.enabled === '1';

        // 🏷️ Update the status badge in the card header
        updateStatusBadge('stripe', document.getElementById('stripe-enabled').checked);

        // 🔀 Set the mode (test or live)
        if (data.mode) {
            document.getElementById('stripe-mode').value = data.mode;
        }

        // 🔑 Set credential fields — publishable key shown in full
        if (data.publishable_key) {
            document.getElementById('stripe-publishable-key').value = data.publishable_key;
        }

        // 🔒 Secret key — may be masked from API (e.g., "••••...xxxx")
        if (data.secret_key) {
            document.getElementById('stripe-secret-key').value = data.secret_key;
        }

        // 🔒 Webhook secret — may be masked from API
        if (data.webhook_secret) {
            document.getElementById('stripe-webhook-secret').value = data.webhook_secret;
        }

        // 🔗 Stripe Link toggle
        document.getElementById('stripe-link-enabled').checked = data.link_enabled === true || data.link_enabled === 1 || data.link_enabled === '1';

        // 💳 Payment method checkboxes — parse from comma-separated string or array
        const methods = Array.isArray(data.payment_methods)
            ? data.payment_methods
            : (data.payment_methods || 'card').split(',').map(m => m.trim());

        document.getElementById('stripe-method-card').checked = methods.includes('card');
        document.getElementById('stripe-method-link').checked = methods.includes('link');
        document.getElementById('stripe-method-apple-pay').checked = methods.includes('apple_pay');
        document.getElementById('stripe-method-google-pay').checked = methods.includes('google_pay');
    }

    /**
     * 🔵 Populate the PayPal configuration form with data from the API.
     *
     * @param {Object} data — PayPal provider settings object from the API
     * @see https://developer.paypal.com/docs/api/ — PayPal API Reference
     */
    function populatePayPalForm(data) {
        // 🔘 Set the enabled toggle
        document.getElementById('paypal-enabled').checked = data.enabled === true || data.enabled === 1 || data.enabled === '1';

        // 🏷️ Update the status badge
        updateStatusBadge('paypal', document.getElementById('paypal-enabled').checked);

        // 🔀 Set the mode (sandbox or live)
        if (data.mode) {
            document.getElementById('paypal-mode').value = data.mode;
        }

        // 🔑 Set credential fields — Client ID shown in full
        if (data.client_id) {
            document.getElementById('paypal-client-id').value = data.client_id;
        }

        // 🔒 Client Secret — may be masked from API
        if (data.client_secret) {
            document.getElementById('paypal-client-secret').value = data.client_secret;
        }

        // 📝 Webhook ID — plain text
        if (data.webhook_id) {
            document.getElementById('paypal-webhook-id').value = data.webhook_id;
        }

        // 💰 Funding source checkboxes — parse from comma-separated string or array
        const funds = Array.isArray(data.funding_sources)
            ? data.funding_sources
            : (data.funding_sources || 'paypal').split(',').map(f => f.trim());

        document.getElementById('paypal-fund-paypal').checked = funds.includes('paypal');
        document.getElementById('paypal-fund-venmo').checked = funds.includes('venmo');
        document.getElementById('paypal-fund-paylater').checked = funds.includes('paylater');
        document.getElementById('paypal-fund-card').checked = funds.includes('card');
    }

    /**
     * 🟠 Populate the Crypto/Coinbase configuration form with data from the API.
     *
     * @param {Object} data — Crypto provider settings object from the API
     * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/ — Coinbase Commerce Docs
     */
    function populateCryptoForm(data) {
        // 🔘 Set the enabled toggle
        document.getElementById('crypto-enabled').checked = data.enabled === true || data.enabled === 1 || data.enabled === '1';

        // 🏷️ Update the status badge
        updateStatusBadge('crypto', document.getElementById('crypto-enabled').checked);

        // 🔒 API Key — masked by default
        if (data.api_key) {
            document.getElementById('crypto-api-key').value = data.api_key;
        }

        // 🔒 Webhook Secret — masked by default
        if (data.webhook_secret) {
            document.getElementById('crypto-webhook-secret').value = data.webhook_secret;
        }

        // 🪙 Supported currency checkboxes — parse from comma-separated string or array
        const currencies = Array.isArray(data.supported_currencies)
            ? data.supported_currencies
            : (data.supported_currencies || 'BTC,ETH').split(',').map(c => c.trim());

        document.getElementById('crypto-currency-btc').checked = currencies.includes('BTC');
        document.getElementById('crypto-currency-eth').checked = currencies.includes('ETH');
        document.getElementById('crypto-currency-usdt').checked = currencies.includes('USDT');
        document.getElementById('crypto-currency-usdc').checked = currencies.includes('USDC');

        // 💰 Crypto discount percentage
        if (data.crypto_discount !== undefined && data.crypto_discount !== null) {
            document.getElementById('crypto-discount').value = data.crypto_discount;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 💾 SAVE PROVIDER SETTINGS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📤 Collect all form values for a specific provider into a settings object.
     * Skips masked values (starting with "••••") to avoid overwriting
     * actual credentials with masked placeholders.
     *
     * @param {string} provider — Provider identifier: 'stripe', 'paypal', or 'crypto'
     * @returns {Object} Settings object ready to send to the API
     */
    function collectProviderSettings(provider) {
        const settings = {};

        if (provider === 'stripe') {
            // 💜 Collect Stripe-specific settings
            settings.enabled = document.getElementById('stripe-enabled').checked;
            settings.mode = document.getElementById('stripe-mode').value;

            // 🔑 Only send credentials that aren't masked placeholders
            const publishableKey = document.getElementById('stripe-publishable-key').value.trim();
            if (publishableKey && !publishableKey.startsWith('••••')) {
                settings.publishable_key = publishableKey;
            }

            const secretKey = document.getElementById('stripe-secret-key').value.trim();
            if (secretKey && !secretKey.startsWith('••••')) {
                settings.secret_key = secretKey;
            }

            const webhookSecret = document.getElementById('stripe-webhook-secret').value.trim();
            if (webhookSecret && !webhookSecret.startsWith('••••')) {
                settings.webhook_secret = webhookSecret;
            }

            // 🔗 Stripe Link toggle
            settings.link_enabled = document.getElementById('stripe-link-enabled').checked;

            // 💳 Collect enabled payment methods as comma-separated string
            const methods = [];
            if (document.getElementById('stripe-method-card').checked) { methods.push('card'); }
            if (document.getElementById('stripe-method-link').checked) { methods.push('link'); }
            if (document.getElementById('stripe-method-apple-pay').checked) { methods.push('apple_pay'); }
            if (document.getElementById('stripe-method-google-pay').checked) { methods.push('google_pay'); }
            settings.payment_methods = methods.join(',');

        } else if (provider === 'paypal') {
            // 🔵 Collect PayPal-specific settings
            settings.enabled = document.getElementById('paypal-enabled').checked;
            settings.mode = document.getElementById('paypal-mode').value;

            // 🔑 Only send credentials that aren't masked placeholders
            const clientId = document.getElementById('paypal-client-id').value.trim();
            if (clientId && !clientId.startsWith('••••')) {
                settings.client_id = clientId;
            }

            const clientSecret = document.getElementById('paypal-client-secret').value.trim();
            if (clientSecret && !clientSecret.startsWith('••••')) {
                settings.client_secret = clientSecret;
            }

            // 📝 Webhook ID — plain text, always send if present
            const webhookId = document.getElementById('paypal-webhook-id').value.trim();
            if (webhookId) {
                settings.webhook_id = webhookId;
            }

            // 💰 Collect enabled funding sources as comma-separated string
            const funds = [];
            if (document.getElementById('paypal-fund-paypal').checked) { funds.push('paypal'); }
            if (document.getElementById('paypal-fund-venmo').checked) { funds.push('venmo'); }
            if (document.getElementById('paypal-fund-paylater').checked) { funds.push('paylater'); }
            if (document.getElementById('paypal-fund-card').checked) { funds.push('card'); }
            settings.funding_sources = funds.join(',');

        } else if (provider === 'crypto') {
            // 🟠 Collect Crypto/Coinbase-specific settings
            settings.enabled = document.getElementById('crypto-enabled').checked;

            // 🔒 Only send credentials that aren't masked placeholders
            const apiKey = document.getElementById('crypto-api-key').value.trim();
            if (apiKey && !apiKey.startsWith('••••')) {
                settings.api_key = apiKey;
            }

            const webhookSecret = document.getElementById('crypto-webhook-secret').value.trim();
            if (webhookSecret && !webhookSecret.startsWith('••••')) {
                settings.webhook_secret = webhookSecret;
            }

            // 🪙 Collect supported currencies as comma-separated string
            const currencies = [];
            if (document.getElementById('crypto-currency-btc').checked) { currencies.push('BTC'); }
            if (document.getElementById('crypto-currency-eth').checked) { currencies.push('ETH'); }
            if (document.getElementById('crypto-currency-usdt').checked) { currencies.push('USDT'); }
            if (document.getElementById('crypto-currency-usdc').checked) { currencies.push('USDC'); }
            settings.supported_currencies = currencies.join(',');

            // 💰 Crypto discount percentage
            settings.crypto_discount = parseFloat(document.getElementById('crypto-discount').value) || 0;
        }

        return settings;
    }

    /**
     * 💾 Save a specific provider's settings to the backend via POST request.
     * Collects the form values, sends them to the API, and shows a success/error alert.
     *
     * @param {string} provider — Provider identifier: 'stripe', 'paypal', or 'crypto'
     * @see ../api/provider-actions.php — Backend API endpoint
     */
    async function saveProvider(provider) {
        // 📤 Collect all settings for this provider
        const settings = collectProviderSettings(provider);

        try {
            const response = await fetch('../api/provider-actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_provider',
                    provider: provider,
                    settings: settings,
                    csrf_token: csrfToken
                })
            });

            const result = await response.json();

            if (result.success) {
                // ✅ Success — update the status badge and show notification
                updateStatusBadge(provider, settings.enabled);
                showAlert(
                    '<i class="fas fa-check-circle me-1"></i> ' +
                    escapeHtml(provider.charAt(0).toUpperCase() + provider.slice(1)) +
                    ' settings saved successfully',
                    'success'
                );
            } else {
                // ❌ API returned an error
                showAlert(result.message || 'Failed to save ' + escapeHtml(provider) + ' settings', 'danger');
            }
        } catch (error) {
            // ⚠️ Network/parsing error
            showAlert('Error saving ' + escapeHtml(provider) + ' settings: ' + escapeHtml(error.message), 'danger');
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 🔌 TEST PROVIDER CONNECTION
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔌 Test the connection to a specific payment provider.
     * Shows a loading spinner on the button during the test,
     * then displays a success or failure alert.
     *
     * @param {string} provider — Provider identifier: 'stripe', 'paypal', or 'crypto'
     * @see ../api/provider-actions.php?action=test_connection — Backend endpoint
     */
    async function testConnection(provider) {
        // 🔄 Get the test button and show loading state
        const btn = document.getElementById(`test-${provider}-btn`);
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Testing...';

        try {
            const response = await fetch('../api/provider-actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'test_connection',
                    provider: provider,
                    csrf_token: csrfToken
                })
            });

            const result = await response.json();

            if (result.success) {
                // ✅ Connection successful
                showAlert(
                    '<i class="fas fa-check-circle me-1"></i> ' +
                    escapeHtml(provider.charAt(0).toUpperCase() + provider.slice(1)) +
                    ' connection test successful! ' +
                    (result.details ? '<small class="d-block text-muted mt-1">' + escapeHtml(result.details) + '</small>' : ''),
                    'success'
                );
            } else {
                // ❌ Connection failed
                showAlert(
                    '<i class="fas fa-times-circle me-1"></i> ' +
                    escapeHtml(provider.charAt(0).toUpperCase() + provider.slice(1)) +
                    ' connection test failed: ' +
                    escapeHtml(result.message || 'Unknown error'),
                    'danger'
                );
            }
        } catch (error) {
            // ⚠️ Network error during test
            showAlert(
                '<i class="fas fa-exclamation-triangle me-1"></i> Error testing ' +
                escapeHtml(provider) + ' connection: ' + escapeHtml(error.message),
                'danger'
            );
        } finally {
            // 🔄 Restore the button to its original state
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 📋 WEBHOOK LOGS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 📋 Load recent webhook logs from the backend API.
     * Resets the offset and fetches the first batch of logs.
     *
     * @see ../api/provider-actions.php?action=get_webhook_logs — Backend endpoint
     */
    async function loadWebhookLogs() {
        // 🔄 Reset pagination offset
        webhookLogOffset = 0;

        // 🔄 Show loading overlay
        document.getElementById('webhookLoading').style.display = 'flex';

        try {
            const params = new URLSearchParams({
                action: 'get_webhook_logs',
                limit: webhookLogLimit,
                offset: 0
            });

            const response = await fetch(`../api/provider-actions.php?${params}`);
            const result = await response.json();

            if (result.success) {
                // 📋 Render the webhook logs into the table
                renderWebhookLogs(result.logs || [], false);

                // 📊 Update the log count display
                const count = result.total || (result.logs || []).length;
                document.getElementById('webhookLogCount').textContent = `Showing ${(result.logs || []).length} of ${count} logs`;

                // 📄 Show/hide the "Load More" button based on available data
                const loadMoreBtn = document.getElementById('loadMoreWebhooksBtn');
                if ((result.logs || []).length >= webhookLogLimit && (result.logs || []).length < count) {
                    loadMoreBtn.style.display = 'inline-block';
                } else {
                    loadMoreBtn.style.display = 'none';
                }

                // 📊 Update offset for next pagination request
                webhookLogOffset = (result.logs || []).length;
            } else {
                showAlert(result.message || 'Failed to load webhook logs', 'danger');
            }
        } catch (error) {
            showAlert('Error loading webhook logs: ' + escapeHtml(error.message), 'danger');
        } finally {
            // 🔄 Hide loading overlay
            document.getElementById('webhookLoading').style.display = 'none';
        }
    }

    /**
     * 📋 Load additional webhook logs (pagination — "Load More" button).
     * Appends new rows to the existing table without replacing current content.
     */
    async function loadMoreWebhookLogs() {
        // 🔄 Show loading overlay
        document.getElementById('webhookLoading').style.display = 'flex';

        try {
            const params = new URLSearchParams({
                action: 'get_webhook_logs',
                limit: webhookLogLimit,
                offset: webhookLogOffset
            });

            const response = await fetch(`../api/provider-actions.php?${params}`);
            const result = await response.json();

            if (result.success) {
                // 📋 Append new rows to the table (don't replace existing)
                renderWebhookLogs(result.logs || [], true);

                // 📊 Update the count and offset
                const totalShown = webhookLogOffset + (result.logs || []).length;
                const totalAvailable = result.total || totalShown;
                document.getElementById('webhookLogCount').textContent = `Showing ${totalShown} of ${totalAvailable} logs`;
                webhookLogOffset = totalShown;

                // 📄 Show/hide the "Load More" button
                const loadMoreBtn = document.getElementById('loadMoreWebhooksBtn');
                if ((result.logs || []).length >= webhookLogLimit && totalShown < totalAvailable) {
                    loadMoreBtn.style.display = 'inline-block';
                } else {
                    loadMoreBtn.style.display = 'none';
                }
            } else {
                showAlert(result.message || 'Failed to load more webhook logs', 'danger');
            }
        } catch (error) {
            showAlert('Error loading more webhook logs: ' + escapeHtml(error.message), 'danger');
        } finally {
            document.getElementById('webhookLoading').style.display = 'none';
        }
    }

    /**
     * 📋 Render webhook log rows into the table.
     *
     * @param {Array} logs — Array of webhook log objects from the API
     * @param {boolean} append — If true, append to existing rows; if false, replace all rows
     */
    function renderWebhookLogs(logs, append = false) {
        const tbody = document.getElementById('webhookLogsTableBody');

        // 📭 Handle empty state
        if (!logs || logs.length === 0) {
            if (!append) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fas fa-satellite-dish fa-3x mb-3 d-block opacity-50"></i>
                            <h5>No Webhook Logs</h5>
                            <p>No webhook events have been received yet.</p>
                        </td>
                    </tr>`;
            }
            return;
        }

        // 📋 Build the HTML for each webhook log row
        const rowsHtml = logs.map(log => {
            // 🏷️ Determine the status badge class
            const status = (log.status || '').toLowerCase();
            const statusClass = status === 'processed' ? 'badge-processed'
                : status === 'failed' ? 'badge-failed'
                : status === 'pending' ? 'badge-pending'
                : status === 'duplicate' ? 'badge-duplicate'
                : 'bg-secondary';

            // ✅ Verified indicator
            const verifiedIcon = log.verified
                ? '<i class="fas fa-check-circle text-success" title="Signature verified"></i>'
                : '<i class="fas fa-times-circle text-danger" title="Unverified"></i>';

            // 🏷️ Provider badge colour
            const providerLower = (log.provider || '').toLowerCase();
            const providerColor = providerLower === 'stripe' ? '#635bff'
                : providerLower === 'paypal' ? '#003087'
                : providerLower === 'coinbase' || providerLower === 'crypto' ? '#f7931a'
                : '#6c757d';

            // ⏱️ Processing time display
            const processingTime = log.processing_time_ms
                ? log.processing_time_ms + 'ms'
                : '—';

            return `
                <tr>
                    <td>
                        <span class="badge" style="background-color: ${providerColor};">
                            ${escapeHtml(log.provider || 'Unknown')}
                        </span>
                    </td>
                    <td><code class="small">${escapeHtml(log.event_type || '—')}</code></td>
                    <td><code class="small text-muted">${escapeHtml(log.event_id || '—')}</code></td>
                    <td><span class="badge ${statusClass}">${escapeHtml(log.status || 'unknown')}</span></td>
                    <td class="text-center">${verifiedIcon}</td>
                    <td><small class="text-muted">${escapeHtml(log.ip_address || '—')}</small></td>
                    <td><small>${formatDateTime(log.created_at)}</small></td>
                    <td><small class="text-muted">${processingTime}</small></td>
                </tr>`;
        }).join('');

        if (append) {
            // 📋 Append to existing rows
            tbody.insertAdjacentHTML('beforeend', rowsHtml);
        } else {
            // 📋 Replace all rows
            tbody.innerHTML = rowsHtml;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 🔄 COLLAPSE ICON TOGGLE — rotate chevron when section expands
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔄 Listen for the Bootstrap collapse events to toggle the chevron icon
     * and auto-load webhook logs when the section is first opened.
     *
     * @see https://getbootstrap.com/docs/5.3/components/collapse/#events — Bootstrap Collapse Events
     */
    const webhookCollapse = document.getElementById('webhookLogsBody');
    if (webhookCollapse) {
        // 📂 When the section opens — rotate icon and load logs
        webhookCollapse.addEventListener('shown.bs.collapse', function() {
            const icon = document.getElementById('webhookCollapseIcon');
            if (icon) {
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            }
            // 📥 Auto-load logs on first open
            if (webhookLogOffset === 0) {
                loadWebhookLogs();
            }
        });

        // 📁 When the section closes — rotate icon back
        webhookCollapse.addEventListener('hidden.bs.collapse', function() {
            const icon = document.getElementById('webhookCollapseIcon');
            if (icon) {
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // 🔘 ENABLED TOGGLE BADGE SYNC — update badges in real-time
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🔘 Sync the provider status badges when enabled toggles change.
     * Provides immediate visual feedback without requiring a save.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/EventTarget/addEventListener
     */
    ['stripe', 'paypal', 'crypto'].forEach(function(provider) {
        const toggle = document.getElementById(`${provider}-enabled`);
        if (toggle) {
            toggle.addEventListener('change', function() {
                updateStatusBadge(provider, this.checked);
            });
        }
    });

    // ═══════════════════════════════════════════════════════════════
    // 🚀 INITIAL LOAD — Load all data when the page is ready
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🎯 Initialise the page by loading all provider configurations.
     * Webhook logs are lazy-loaded when the user expands the section.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Document/DOMContentLoaded_event
     */
    document.addEventListener('DOMContentLoaded', function() {
        // 📥 Load all provider settings via AJAX
        loadProviders();

        // 📋 Note: Webhook logs are loaded lazily when the collapse section opens
        // This avoids unnecessary API calls on initial page load
    });
    </script>
</body>
</html>
