<?php
/**
 * ============================================================================
 * 💳 Partner Payment Provider Configuration
 * ============================================================================
 *
 * Purpose: Allows partner admins (finance role or higher) to configure how
 *          their organisation accepts payments from customers. Partners can
 *          choose between two payment modes for each supported provider:
 *
 *          Option 2a (Own Keys):   Partner provides their own Stripe/PayPal/
 *                                  Coinbase API credentials. Payments go directly
 *                                  to the partner's account. SIGNula charges a
 *                                  lower service fee (~10% default).
 *
 *          Option 2b (SIGNula Keys): Partner uses SIGNula's payment infrastructure.
 *                                    SIGNula collects full payment, deducts a higher
 *                                    service fee (~30% default), and remits the
 *                                    remainder on a scheduled basis.
 *
 * Features:
 * - Provider cards for Stripe (purple), PayPal (blue), Coinbase (orange)
 * - Toggle between own keys vs. SIGNula keys per provider
 * - Credential input with password masking and eye-toggle visibility
 * - Mode selector: Sandbox / Live
 * - Enable/Disable toggle per provider
 * - "Test Connection" button for validating own API credentials
 * - Verification status badges (Verified / Unverified / Not configured)
 * - Toast notifications for save and test results
 *
 * Access Control:
 * - Requires login (redirects to /auth/login.php)
 * - Requires partner membership (redirects to /partners/register.php)
 * - Requires finance role or higher (dies with 403 via requirePartnerPaymentAccess)
 *
 * Dependencies:
 * - /_config/config.php               — SIGNula bootstrap and constants
 * - /_backend/Database.php            — MySQLi database singleton
 * - /_backend/SessionManager.php      — Session handling and user info
 * - /_backend/AccessControl.php       — Multi-tier RBAC
 * - /private_html/payments/PartnerPaymentService.php — Payment config CRUD
 *
 * @see https://docs.stripe.com/keys                  — Stripe API Keys
 * @see https://developer.paypal.com/docs/api/        — PayPal API Reference
 * @see https://docs.cdp.coinbase.com/commerce-onchain/docs/ — Coinbase Commerce
 * @see https://getbootstrap.com/docs/5.3/             — Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons                  — FontAwesome Icon Library
 *
 * PHP Version: 8.3+ (targeting 8.4 for longevity)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @subpackage Partners\Admin
 * @version    1.0.0
 * @since      2.4.0-beta
 * ============================================================================
 */

// ============================================================================
// 📦 DEPENDENCY LOADING
// ============================================================================

// 🔧 Load core configuration (defines SIGNULA_INIT, DB credentials, SecurityUtils, etc.)
// dirname(__DIR__, 3) navigates from /web/public_html/partners/admin/ up to /web/
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton — all queries use MySQLi prepared statements
// @see https://www.php.net/manual/en/class.mysqli.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🔐 Session manager — handles login state, user info, CSRF tokens
// @see https://www.php.net/manual/en/book.session.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🛡️ Access control — multi-tier RBAC with role hierarchy
// Roles: super-admin(100) > root-admin(80) > admin(60) > developer(40) > support(30) > finance(20) > user(10)
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 💳 PartnerPaymentService — CRUD for partner payment provider configs
// Located in private_html (not web-accessible) for security
// @see /web/private_html/payments/PartnerPaymentService.php
$partnerPaymentServicePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'PartnerPaymentService.php';
if (file_exists($partnerPaymentServicePath)) {
    require_once $partnerPaymentServicePath;
} else {
    // ⚠️ PartnerPaymentService is essential for this page
    error_log('[SIGNula] payment-config.php: PartnerPaymentService.php not found at ' . $partnerPaymentServicePath);
}

// ============================================================================
// 🔌 SERVICE INITIALISATION
// ============================================================================

// 🗄️ Get the database singleton instance
$db = Database::getInstance();

// 🔐 Create session manager for authentication checks
$sessionManager = new SessionManager($db);

// 🛡️ Create access control for role-based permission checks
$accessControl = new AccessControl($db, $sessionManager);

// ============================================================================
// 🔒 AUTHENTICATION & AUTHORISATION CHECKS
// ============================================================================

// 🔒 Check if user is logged in — redirect to login if not
// @see https://www.php.net/manual/en/function.header.php
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🏢 Get user's partner memberships — redirect to registration if none
// @see AccessControl::getPartnerMemberships()
$memberships = $accessControl->getPartnerMemberships();

if (empty($memberships)) {
    header('Location: /partners/register.php');
    exit;
}

// 🔀 Default to first partner or use the one selected via URL parameter
// @see https://www.php.net/manual/en/function.array-key-first.php
$selectedPartnerID = $_GET['partner'] ?? array_key_first($memberships);
$partner = $memberships[$selectedPartnerID] ?? reset($memberships);

// 💰 Verify the user has finance role or higher for payment management
// Dies with HTTP 403 if insufficient permissions
// @see AccessControl::requirePartnerPaymentAccess()
$accessControl->requirePartnerPaymentAccess($selectedPartnerID);

// ============================================================================
// 🗄️ LOAD PARTNER DETAILS & PAYMENT CONFIGURATIONS
// ============================================================================

// 📋 Fetch partner details from tblPartners for display
// @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE partnerID = ?");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerDetails = $stmt->get_result()->fetch_assoc();

// 💳 Load existing payment configurations for all three providers
// Each returns null if no configuration exists, or an associative array with decrypted keys
// @see PartnerPaymentService::getPartnerPaymentConfig()
$stripeConfig  = null;
$paypalConfig  = null;
$coinbaseConfig = null;

// 🔍 Only attempt to load if the PartnerPaymentService class is available
if (class_exists('PartnerPaymentService')) {
    $stripeConfig   = PartnerPaymentService::getPartnerPaymentConfig($selectedPartnerID, 'stripe');
    $paypalConfig   = PartnerPaymentService::getPartnerPaymentConfig($selectedPartnerID, 'paypal');
    $coinbaseConfig = PartnerPaymentService::getPartnerPaymentConfig($selectedPartnerID, 'coinbase');
}

// ============================================================================
// 🔧 HELPER: Mask credential values for display
// ============================================================================

/**
 * 🔒 Mask a credential string for safe display in the UI
 *
 * Shows only the last 4 characters, replacing the rest with bullet characters.
 * Returns an empty string if the credential is empty/null.
 *
 * @param string|null $credential The credential value to mask
 * @return string Masked credential (e.g., "••••••••abcd") or empty string
 *
 * @see https://www.php.net/manual/en/function.str-repeat.php
 * @see https://www.php.net/manual/en/function.substr.php
 */
function maskCredential(?string $credential): string
{
    // ❌ Return empty if no credential provided
    if (empty($credential)) {
        return '';
    }

    // 🔒 Show bullets + last 4 characters for identification without full exposure
    $visibleChars = 4;
    $masked = str_repeat("\u{2022}", 8); // 8 bullet characters (•)

    if (strlen($credential) > $visibleChars) {
        $masked .= substr($credential, -$visibleChars);
    } else {
        // 🔒 Very short credentials: mask entirely
        $masked .= str_repeat("\u{2022}", strlen($credential));
    }

    return $masked;
}

/**
 * 🏷️ Get the verification status badge HTML for a provider config
 *
 * Returns a Bootstrap badge indicating the verification status of the
 * payment provider configuration (Verified, Unverified, or Not Configured).
 *
 * @param array|null $config The provider configuration array, or null if not configured
 * @return string HTML string for the status badge
 */
function getVerificationBadge(?array $config): string
{
    // ❌ No configuration exists at all
    if ($config === null) {
        return '<span class="badge bg-secondary"><i class="fas fa-minus-circle me-1"></i>Not Configured</span>';
    }

    // ✅ Configuration exists and has been verified
    if (!empty($config['isVerified'])) {
        $verifiedAt = !empty($config['verifiedAt'])
            ? ' on ' . date('M j, Y g:i A', strtotime($config['verifiedAt']))
            : '';
        return '<span class="badge bg-success" title="Verified' . htmlspecialchars($verifiedAt) . '"><i class="fas fa-check-circle me-1"></i>Verified</span>';
    }

    // ⚠️ Configuration exists but has not been verified (or verification failed)
    return '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>Unverified</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📄 Page metadata and viewport for responsive design               -->
    <!-- @see https://developer.mozilla.org/en-US/docs/Web/HTML/Viewport_meta_tag -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Configuration - <?php echo htmlspecialchars($partner['companyName']); ?> - SIGNula</title>

    <!-- 🎨 Bootstrap 5.3.2 CSS (CDN with SRI for integrity verification) -->
    <!-- @see https://getbootstrap.com/docs/5.3/ — Bootstrap Documentation -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- 🎯 FontAwesome 6.4.2 CSS (CDN with SRI for integrity verification) -->
    <!-- @see https://fontawesome.com/icons — FontAwesome Icon Library -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <style>
        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Base body styling — matches existing partner admin pages   */
        /* ═══════════════════════════════════════════════════════════════ */
        body { background-color: #f8f9fa; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Page header — green/teal gradient matching payments theme  */
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
        /* 💳 Provider cards — main configuration cards for each gateway */
        /* ═══════════════════════════════════════════════════════════════ */
        .provider-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
            margin-bottom: 2rem;
        }
        .provider-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
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
        /* 🎨 Provider-specific card header colour themes                */
        /* ═══════════════════════════════════════════════════════════════ */

        /* 💜 Stripe — official brand colour #635bff */
        /* @see https://stripe.com/docs/stripe-js — Stripe.js Reference */
        .stripe-header { background: linear-gradient(135deg, #635bff, #7a73ff); }

        /* 🔵 PayPal — official brand colour #003087 */
        /* @see https://developer.paypal.com/docs/api/ — PayPal API Reference */
        .paypal-header { background: linear-gradient(135deg, #003087, #0070ba); }

        /* 🟠 Coinbase / Cryptocurrency — Bitcoin orange theme #f7931a */
        /* @see https://docs.cdp.coinbase.com/commerce-onchain/docs/ — Coinbase Commerce */
        .crypto-header { background: linear-gradient(135deg, #f7931a, #ffb347); }

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
        /* 🔀 Mode toggle — own keys vs SIGNula keys radio card styling  */
        /* ═══════════════════════════════════════════════════════════════ */
        .mode-option {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        .mode-option:hover {
            border-color: #adb5bd;
        }
        .mode-option.selected {
            border-color: #0d6efd;
            background-color: #f0f7ff;
        }
        .mode-option .fee-badge {
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔐 Credential section — collapsible area for API key inputs   */
        /* ═══════════════════════════════════════════════════════════════ */
        .credentials-section {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1.25rem;
            margin-top: 1rem;
            background-color: #fafbfc;
            transition: opacity 0.3s;
        }
        .credentials-section.disabled-section {
            opacity: 0.5;
            pointer-events: none;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🟢 Status indicator dot — shows provider enabled/disabled     */
        /* ═══════════════════════════════════════════════════════════════ */
        .provider-status {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
        }
        .provider-status.active   { background-color: #28a745; }
        .provider-status.inactive { background-color: #dc3545; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔄 Loading spinner overlay — used during save/test operations */
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
            border-radius: 12px;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🍞 Toast notification positioning — top-right corner          */
        /* @see https://getbootstrap.com/docs/5.3/components/toasts/     */
        /* ═══════════════════════════════════════════════════════════════ */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1100;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📱 Responsive adjustments for smaller screens                 */
        /* ═══════════════════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .provider-card .card-body {
                padding: 1rem;
            }
            .mode-option {
                padding: 0.75rem;
                margin-bottom: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🎨 PAGE HEADER — Green gradient matching payments theme       -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="page-header">
            <!-- 🔙 Back navigation link to partner admin dashboard -->
            <a href="index.php?partner=<?php echo (int)$selectedPartnerID; ?>"
               class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
            </a>
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">
                        <i class="fas fa-credit-card me-2"></i>Payment Configuration
                    </h1>
                    <p class="mb-0 opacity-75">
                        <?php echo htmlspecialchars($partner['companyName']); ?>
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <!-- 🔄 Refresh button — reloads the page to show latest configs -->
                    <button class="btn btn-light btn-lg" onclick="location.reload()">
                        <i class="fas fa-sync me-2"></i>Refresh
                    </button>
                </div>
            </div>
        </div>

        <!-- 💬 Alert notification container — dynamically populated by showAlert() -->
        <div id="alertContainer"></div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📋 EXPLANATION CARD — Describes Option 2a vs Option 2b        -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="alert alert-info mb-4">
            <h6 class="alert-heading">
                <i class="fas fa-info-circle me-2"></i>About Payment Configuration
            </h6>
            <p class="mb-2">
                Choose how to accept payments from your customers. You can use your own payment provider
                API keys (lower fees) or let SIGNula handle payments on your behalf.
            </p>
            <ul class="mb-0">
                <li>
                    <strong><i class="fas fa-key me-1"></i>Own API Keys (Option 2a):</strong>
                    Provide your own Stripe, PayPal, or Coinbase credentials. Payments go directly to your
                    account. SIGNula charges a lower service fee (~10%).
                </li>
                <li>
                    <strong><i class="fas fa-shield-alt me-1"></i>SIGNula Keys (Option 2b):</strong>
                    Let SIGNula process payments on your behalf. No API key setup needed.
                    SIGNula charges a higher service fee (~30%) and remits the remainder to you.
                </li>
            </ul>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 💜 STRIPE CONFIGURATION CARD                                   -->
        <!-- @see https://docs.stripe.com/api — Stripe API Documentation   -->
        <!-- @see https://docs.stripe.com/keys — Stripe API Keys           -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="provider-card" id="stripe-card">
            <!-- 💜 Stripe card header with brand colour and status -->
            <div class="card-header stripe-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fa-brands fa-stripe fa-lg me-2"></i>Stripe
                </div>
                <div class="d-flex align-items-center gap-2">
                    <!-- 🏷️ Verification status badge -->
                    <?php echo getVerificationBadge($stripeConfig); ?>
                    <!-- 🟢 Enabled/disabled indicator -->
                    <?php if ($stripeConfig && !empty($stripeConfig['isEnabled'])): ?>
                        <span class="badge bg-light text-success"><i class="fas fa-check me-1"></i>Enabled</span>
                    <?php elseif ($stripeConfig): ?>
                        <span class="badge bg-light text-danger"><i class="fas fa-times me-1"></i>Disabled</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <!-- 🔘 Enable/Disable toggle for this provider -->
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="stripe-enabled" role="switch"
                           <?php echo ($stripeConfig && !empty($stripeConfig['isEnabled'])) ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-semibold" for="stripe-enabled">
                        <i class="fas fa-power-off me-1"></i>Enable Stripe Payments
                    </label>
                </div>

                <!-- 🔀 Payment Mode Selection: Own Keys vs SIGNula Keys -->
                <label class="form-label fw-semibold mb-2">
                    <i class="fas fa-exchange-alt me-1"></i>Payment Mode
                </label>
                <div class="row g-3 mb-3">
                    <!-- 🔑 Option 2a: Use Own API Keys -->
                    <div class="col-md-6">
                        <div class="mode-option <?php echo ($stripeConfig && !empty($stripeConfig['usesOwnKeys'])) ? 'selected' : ''; ?>"
                             id="stripe-mode-own"
                             onclick="selectMode('stripe', 'own_keys')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="stripe-mode-radio"
                                       id="stripe-radio-own" value="own_keys"
                                       <?php echo ($stripeConfig && !empty($stripeConfig['usesOwnKeys'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="stripe-radio-own">
                                    <i class="fas fa-key me-1"></i>Use Own API Keys
                                </label>
                            </div>
                            <small class="text-muted d-block mt-1">Payments go directly to your Stripe account</small>
                            <span class="badge bg-success fee-badge mt-2">
                                <i class="fas fa-percentage me-1"></i>Service fee: ~10%
                            </span>
                        </div>
                    </div>
                    <!-- 🛡️ Option 2b: Use SIGNula Keys -->
                    <div class="col-md-6">
                        <div class="mode-option <?php echo (!$stripeConfig || empty($stripeConfig['usesOwnKeys'])) ? 'selected' : ''; ?>"
                             id="stripe-mode-signula"
                             onclick="selectMode('stripe', 'signula_keys')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="stripe-mode-radio"
                                       id="stripe-radio-signula" value="signula_keys"
                                       <?php echo (!$stripeConfig || empty($stripeConfig['usesOwnKeys'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="stripe-radio-signula">
                                    <i class="fas fa-shield-alt me-1"></i>Use SIGNula's Keys
                                </label>
                            </div>
                            <small class="text-muted d-block mt-1">SIGNula handles all payment processing</small>
                            <span class="badge bg-warning text-dark fee-badge mt-2">
                                <i class="fas fa-percentage me-1"></i>Service fee: ~30%
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 🔐 Credential Input Fields (shown only when Own Keys selected) -->
                <div class="credentials-section <?php echo (!$stripeConfig || empty($stripeConfig['usesOwnKeys'])) ? 'disabled-section' : ''; ?>"
                     id="stripe-credentials">
                    <h6 class="mb-3"><i class="fas fa-key me-2"></i>Stripe API Credentials</h6>

                    <!-- 🔑 Publishable Key — shown in full (client-side key, safe to display) -->
                    <!-- @see https://docs.stripe.com/keys — Stripe API Keys -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="fas fa-globe me-1"></i>Publishable Key</label>
                        <div class="credential-field">
                            <input type="password" id="stripe-publishable-key" class="form-control"
                                   placeholder="pk_test_..."
                                   value="<?php echo htmlspecialchars(maskCredential($stripeConfig['publishableKey'] ?? '')); ?>"
                                   autocomplete="off">
                            <button type="button" class="toggle-visibility"
                                    onclick="togglePasswordVisibility('stripe-publishable-key')"
                                    title="Toggle visibility"
                                    aria-label="Toggle publishable key visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">Your Stripe publishable key (starts with pk_test_ or pk_live_)</small>
                    </div>

                    <!-- 🔒 API Secret Key — masked by default with reveal toggle -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="fas fa-lock me-1"></i>Secret Key</label>
                        <div class="credential-field">
                            <input type="password" id="stripe-api-key" class="form-control"
                                   placeholder="sk_test_..."
                                   value="<?php echo htmlspecialchars(maskCredential($stripeConfig['apiKey'] ?? '')); ?>"
                                   autocomplete="off">
                            <button type="button" class="toggle-visibility"
                                    onclick="togglePasswordVisibility('stripe-api-key')"
                                    title="Toggle visibility"
                                    aria-label="Toggle secret key visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">Your Stripe secret key (starts with sk_test_ or sk_live_)</small>
                    </div>

                    <!-- 🔒 API Secret — optional, used for additional authentication -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="fas fa-user-secret me-1"></i>API Secret</label>
                        <div class="credential-field">
                            <input type="password" id="stripe-api-secret" class="form-control"
                                   placeholder="Optional API secret..."
                                   value="<?php echo htmlspecialchars(maskCredential($stripeConfig['apiSecret'] ?? '')); ?>"
                                   autocomplete="off">
                            <button type="button" class="toggle-visibility"
                                    onclick="togglePasswordVisibility('stripe-api-secret')"
                                    title="Toggle visibility"
                                    aria-label="Toggle API secret visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">Optional additional API secret for enhanced authentication</small>
                    </div>

                    <!-- 🔒 Webhook Secret — for validating incoming Stripe webhooks -->
                    <!-- @see https://docs.stripe.com/webhooks — Stripe Webhooks -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="fas fa-satellite-dish me-1"></i>Webhook Secret</label>
                        <div class="credential-field">
                            <input type="password" id="stripe-webhook-secret" class="form-control"
                                   placeholder="whsec_..."
                                   value="<?php echo htmlspecialchars(maskCredential($stripeConfig['webhookSecret'] ?? '')); ?>"
                                   autocomplete="off">
                            <button type="button" class="toggle-visibility"
                                    onclick="togglePasswordVisibility('stripe-webhook-secret')"
                                    title="Toggle visibility"
                                    aria-label="Toggle webhook secret visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">Used to verify webhook events from Stripe (starts with whsec_)</small>
                    </div>
                </div>

                <!-- 🔀 API Mode selector: Sandbox / Live -->
                <!-- @see https://docs.stripe.com/test-mode — Stripe Test Mode -->
                <div class="mb-3 mt-3">
                    <label class="form-label fw-semibold"><i class="fas fa-toggle-on me-1"></i>Environment Mode</label>
                    <select id="stripe-env-mode" class="form-select">
                        <option value="sandbox" <?php echo ($stripeConfig && ($stripeConfig['mode'] ?? 'sandbox') === 'sandbox') ? 'selected' : ''; ?>>
                            Sandbox / Test Mode
                        </option>
                        <option value="live" <?php echo ($stripeConfig && ($stripeConfig['mode'] ?? '') === 'live') ? 'selected' : ''; ?>>
                            Live / Production
                        </option>
                    </select>
                    <small class="text-muted">Use Sandbox mode for testing before going live</small>
                </div>

                <!-- 🔘 Action buttons: Test Connection + Save -->
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button class="btn btn-outline-primary" onclick="testConnection('stripe')"
                            id="stripe-test-btn"
                            <?php echo (!$stripeConfig || empty($stripeConfig['usesOwnKeys'])) ? 'disabled' : ''; ?>>
                        <i class="fas fa-plug me-1"></i>Test Connection
                    </button>
                    <button class="btn btn-primary" onclick="saveConfig('stripe')">
                        <i class="fas fa-save me-1"></i>Save Configuration
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🔵 PAYPAL CONFIGURATION CARD                                   -->
        <!-- @see https://developer.paypal.com/docs/api/ — PayPal API Docs  -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="provider-card" id="paypal-card">
            <!-- 🔵 PayPal card header with brand colour and status -->
            <div class="card-header paypal-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fa-brands fa-paypal fa-lg me-2"></i>PayPal
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php echo getVerificationBadge($paypalConfig); ?>
                    <?php if ($paypalConfig && !empty($paypalConfig['isEnabled'])): ?>
                        <span class="badge bg-light text-success"><i class="fas fa-check me-1"></i>Enabled</span>
                    <?php elseif ($paypalConfig): ?>
                        <span class="badge bg-light text-danger"><i class="fas fa-times me-1"></i>Disabled</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <!-- 🔘 Enable/Disable toggle -->
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="paypal-enabled" role="switch"
                           <?php echo ($paypalConfig && !empty($paypalConfig['isEnabled'])) ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-semibold" for="paypal-enabled">
                        <i class="fas fa-power-off me-1"></i>Enable PayPal Payments
                    </label>
                </div>

                <!-- 🔀 Payment Mode Selection -->
                <label class="form-label fw-semibold mb-2">
                    <i class="fas fa-exchange-alt me-1"></i>Payment Mode
                </label>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="mode-option <?php echo ($paypalConfig && !empty($paypalConfig['usesOwnKeys'])) ? 'selected' : ''; ?>"
                             id="paypal-mode-own"
                             onclick="selectMode('paypal', 'own_keys')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="paypal-mode-radio"
                                       id="paypal-radio-own" value="own_keys"
                                       <?php echo ($paypalConfig && !empty($paypalConfig['usesOwnKeys'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="paypal-radio-own">
                                    <i class="fas fa-key me-1"></i>Use Own API Keys
                                </label>
                            </div>
                            <small class="text-muted d-block mt-1">Payments go directly to your PayPal account</small>
                            <span class="badge bg-success fee-badge mt-2">
                                <i class="fas fa-percentage me-1"></i>Service fee: ~10%
                            </span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mode-option <?php echo (!$paypalConfig || empty($paypalConfig['usesOwnKeys'])) ? 'selected' : ''; ?>"
                             id="paypal-mode-signula"
                             onclick="selectMode('paypal', 'signula_keys')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="paypal-mode-radio"
                                       id="paypal-radio-signula" value="signula_keys"
                                       <?php echo (!$paypalConfig || empty($paypalConfig['usesOwnKeys'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="paypal-radio-signula">
                                    <i class="fas fa-shield-alt me-1"></i>Use SIGNula's Keys
                                </label>
                            </div>
                            <small class="text-muted d-block mt-1">SIGNula handles all payment processing</small>
                            <span class="badge bg-warning text-dark fee-badge mt-2">
                                <i class="fas fa-percentage me-1"></i>Service fee: ~30%
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 🔐 PayPal Credential Input Fields -->
                <div class="credentials-section <?php echo (!$paypalConfig || empty($paypalConfig['usesOwnKeys'])) ? 'disabled-section' : ''; ?>"
                     id="paypal-credentials">
                    <h6 class="mb-3"><i class="fas fa-key me-2"></i>PayPal API Credentials</h6>

                    <!-- 🔑 Client ID — PayPal application Client ID -->
                    <!-- @see https://developer.paypal.com/api/rest/#link-getclientidandclientsecret -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="fas fa-id-card me-1"></i>Client ID (API Key)</label>
                        <div class="credential-field">
                            <input type="password" id="paypal-api-key" class="form-control"
                                   placeholder="PayPal Client ID..."
                                   value="<?php echo htmlspecialchars(maskCredential($paypalConfig['apiKey'] ?? '')); ?>"
                                   autocomplete="off">
                            <button type="button" class="toggle-visibility"
                                    onclick="togglePasswordVisibility('paypal-api-key')"
                                    title="Toggle visibility"
                                    aria-label="Toggle Client ID visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">Your PayPal application Client ID</small>
                    </div>

                    <!-- 🔒 Client Secret — PayPal application secret -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="fas fa-lock me-1"></i>Client Secret (API Secret)</label>
                        <div class="credential-field">
                            <input type="password" id="paypal-api-secret" class="form-control"
                                   placeholder="PayPal Client Secret..."
                                   value="<?php echo htmlspecialchars(maskCredential($paypalConfig['apiSecret'] ?? '')); ?>"
                                   autocomplete="off">
                            <button type="button" class="toggle-visibility"
                                    onclick="togglePasswordVisibility('paypal-api-secret')"
                                    title="Toggle visibility"
                                    aria-label="Toggle Client Secret visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">Your PayPal application Client Secret</small>
                    </div>

                    <!-- 🔒 Webhook ID — PayPal webhook verification -->
                    <!-- @see https://developer.paypal.com/docs/api/webhooks/ — PayPal Webhooks -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="fas fa-satellite-dish me-1"></i>Webhook Secret</label>
                        <div class="credential-field">
                            <input type="password" id="paypal-webhook-secret" class="form-control"
                                   placeholder="PayPal Webhook ID..."
                                   value="<?php echo htmlspecialchars(maskCredential($paypalConfig['webhookSecret'] ?? '')); ?>"
                                   autocomplete="off">
                            <button type="button" class="toggle-visibility"
                                    onclick="togglePasswordVisibility('paypal-webhook-secret')"
                                    title="Toggle visibility"
                                    aria-label="Toggle Webhook Secret visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">Your PayPal Webhook ID for event verification</small>
                    </div>
                </div>

                <!-- 🔀 API Mode selector -->
                <div class="mb-3 mt-3">
                    <label class="form-label fw-semibold"><i class="fas fa-toggle-on me-1"></i>Environment Mode</label>
                    <select id="paypal-env-mode" class="form-select">
                        <option value="sandbox" <?php echo ($paypalConfig && ($paypalConfig['mode'] ?? 'sandbox') === 'sandbox') ? 'selected' : ''; ?>>
                            Sandbox / Test Mode
                        </option>
                        <option value="live" <?php echo ($paypalConfig && ($paypalConfig['mode'] ?? '') === 'live') ? 'selected' : ''; ?>>
                            Live / Production
                        </option>
                    </select>
                    <small class="text-muted">Use Sandbox mode for testing before going live</small>
                </div>

                <!-- 🔘 Action buttons -->
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button class="btn btn-outline-primary" onclick="testConnection('paypal')"
                            id="paypal-test-btn"
                            <?php echo (!$paypalConfig || empty($paypalConfig['usesOwnKeys'])) ? 'disabled' : ''; ?>>
                        <i class="fas fa-plug me-1"></i>Test Connection
                    </button>
                    <button class="btn btn-primary" onclick="saveConfig('paypal')">
                        <i class="fas fa-save me-1"></i>Save Configuration
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🟠 COINBASE / CRYPTOCURRENCY CONFIGURATION CARD                -->
        <!-- @see https://docs.cdp.coinbase.com/commerce-onchain/docs/     -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="provider-card" id="coinbase-card">
            <!-- 🟠 Coinbase card header with brand colour and status -->
            <div class="card-header crypto-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fa-brands fa-bitcoin fa-lg me-2"></i>Coinbase Commerce
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php echo getVerificationBadge($coinbaseConfig); ?>
                    <?php if ($coinbaseConfig && !empty($coinbaseConfig['isEnabled'])): ?>
                        <span class="badge bg-light text-success"><i class="fas fa-check me-1"></i>Enabled</span>
                    <?php elseif ($coinbaseConfig): ?>
                        <span class="badge bg-light text-danger"><i class="fas fa-times me-1"></i>Disabled</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <!-- 🔘 Enable/Disable toggle -->
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="coinbase-enabled" role="switch"
                           <?php echo ($coinbaseConfig && !empty($coinbaseConfig['isEnabled'])) ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-semibold" for="coinbase-enabled">
                        <i class="fas fa-power-off me-1"></i>Enable Cryptocurrency Payments
                    </label>
                </div>

                <!-- 🔀 Payment Mode Selection -->
                <label class="form-label fw-semibold mb-2">
                    <i class="fas fa-exchange-alt me-1"></i>Payment Mode
                </label>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="mode-option <?php echo ($coinbaseConfig && !empty($coinbaseConfig['usesOwnKeys'])) ? 'selected' : ''; ?>"
                             id="coinbase-mode-own"
                             onclick="selectMode('coinbase', 'own_keys')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="coinbase-mode-radio"
                                       id="coinbase-radio-own" value="own_keys"
                                       <?php echo ($coinbaseConfig && !empty($coinbaseConfig['usesOwnKeys'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="coinbase-radio-own">
                                    <i class="fas fa-key me-1"></i>Use Own API Keys
                                </label>
                            </div>
                            <small class="text-muted d-block mt-1">Crypto payments go directly to your Coinbase account</small>
                            <span class="badge bg-success fee-badge mt-2">
                                <i class="fas fa-percentage me-1"></i>Service fee: ~10%
                            </span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mode-option <?php echo (!$coinbaseConfig || empty($coinbaseConfig['usesOwnKeys'])) ? 'selected' : ''; ?>"
                             id="coinbase-mode-signula"
                             onclick="selectMode('coinbase', 'signula_keys')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="coinbase-mode-radio"
                                       id="coinbase-radio-signula" value="signula_keys"
                                       <?php echo (!$coinbaseConfig || empty($coinbaseConfig['usesOwnKeys'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="coinbase-radio-signula">
                                    <i class="fas fa-shield-alt me-1"></i>Use SIGNula's Keys
                                </label>
                            </div>
                            <small class="text-muted d-block mt-1">SIGNula handles all cryptocurrency processing</small>
                            <span class="badge bg-warning text-dark fee-badge mt-2">
                                <i class="fas fa-percentage me-1"></i>Service fee: ~30%
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 🔐 Coinbase Credential Input Fields -->
                <div class="credentials-section <?php echo (!$coinbaseConfig || empty($coinbaseConfig['usesOwnKeys'])) ? 'disabled-section' : ''; ?>"
                     id="coinbase-credentials">
                    <h6 class="mb-3"><i class="fas fa-key me-2"></i>Coinbase Commerce Credentials</h6>

                    <!-- 🔑 API Key — Coinbase Commerce API key -->
                    <!-- @see https://docs.cdp.coinbase.com/commerce-onchain/docs/welcome -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="fas fa-key me-1"></i>API Key</label>
                        <div class="credential-field">
                            <input type="password" id="coinbase-api-key" class="form-control"
                                   placeholder="Coinbase Commerce API Key..."
                                   value="<?php echo htmlspecialchars(maskCredential($coinbaseConfig['apiKey'] ?? '')); ?>"
                                   autocomplete="off">
                            <button type="button" class="toggle-visibility"
                                    onclick="togglePasswordVisibility('coinbase-api-key')"
                                    title="Toggle visibility"
                                    aria-label="Toggle API key visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">Your Coinbase Commerce API key</small>
                    </div>

                    <!-- 🔒 API Secret — Coinbase Commerce shared secret -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="fas fa-lock me-1"></i>API Secret</label>
                        <div class="credential-field">
                            <input type="password" id="coinbase-api-secret" class="form-control"
                                   placeholder="Coinbase Commerce API Secret..."
                                   value="<?php echo htmlspecialchars(maskCredential($coinbaseConfig['apiSecret'] ?? '')); ?>"
                                   autocomplete="off">
                            <button type="button" class="toggle-visibility"
                                    onclick="togglePasswordVisibility('coinbase-api-secret')"
                                    title="Toggle visibility"
                                    aria-label="Toggle API secret visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">Your Coinbase Commerce shared secret for webhook verification</small>
                    </div>

                    <!-- 🔒 Webhook Secret — Coinbase webhook shared secret -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="fas fa-satellite-dish me-1"></i>Webhook Secret</label>
                        <div class="credential-field">
                            <input type="password" id="coinbase-webhook-secret" class="form-control"
                                   placeholder="Coinbase Webhook Shared Secret..."
                                   value="<?php echo htmlspecialchars(maskCredential($coinbaseConfig['webhookSecret'] ?? '')); ?>"
                                   autocomplete="off">
                            <button type="button" class="toggle-visibility"
                                    onclick="togglePasswordVisibility('coinbase-webhook-secret')"
                                    title="Toggle visibility"
                                    aria-label="Toggle webhook secret visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">Used to verify incoming webhook notifications from Coinbase</small>
                    </div>
                </div>

                <!-- 🔀 API Mode selector -->
                <div class="mb-3 mt-3">
                    <label class="form-label fw-semibold"><i class="fas fa-toggle-on me-1"></i>Environment Mode</label>
                    <select id="coinbase-env-mode" class="form-select">
                        <option value="sandbox" <?php echo ($coinbaseConfig && ($coinbaseConfig['mode'] ?? 'sandbox') === 'sandbox') ? 'selected' : ''; ?>>
                            Sandbox / Test Mode
                        </option>
                        <option value="live" <?php echo ($coinbaseConfig && ($coinbaseConfig['mode'] ?? '') === 'live') ? 'selected' : ''; ?>>
                            Live / Production
                        </option>
                    </select>
                    <small class="text-muted">Use Sandbox mode for testing before going live</small>
                </div>

                <!-- 🔘 Action buttons -->
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button class="btn btn-outline-primary" onclick="testConnection('coinbase')"
                            id="coinbase-test-btn"
                            <?php echo (!$coinbaseConfig || empty($coinbaseConfig['usesOwnKeys'])) ? 'disabled' : ''; ?>>
                        <i class="fas fa-plug me-1"></i>Test Connection
                    </button>
                    <button class="btn btn-primary" onclick="saveConfig('coinbase')">
                        <i class="fas fa-save me-1"></i>Save Configuration
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- ❓ HELP CARD — Additional guidance for users                   -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="card">
            <div class="card-body">
                <h6 class="mb-3"><i class="fas fa-question-circle me-2"></i>Need Help?</h6>
                <div class="row">
                    <div class="col-md-4">
                        <h6><i class="fa-brands fa-stripe me-1 text-primary"></i>Stripe Setup</h6>
                        <p class="text-muted small">
                            Get your Stripe API keys from the
                            <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener noreferrer">
                                Stripe Dashboard <i class="fas fa-external-link-alt fa-xs"></i>
                            </a>.
                            Use test keys for sandbox mode.
                        </p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fa-brands fa-paypal me-1 text-primary"></i>PayPal Setup</h6>
                        <p class="text-muted small">
                            Create a PayPal app in the
                            <a href="https://developer.paypal.com/dashboard/applications" target="_blank" rel="noopener noreferrer">
                                PayPal Developer Dashboard <i class="fas fa-external-link-alt fa-xs"></i>
                            </a>
                            to get your Client ID and Secret.
                        </p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fa-brands fa-bitcoin me-1 text-warning"></i>Coinbase Setup</h6>
                        <p class="text-muted small">
                            Get your Coinbase Commerce API key from the
                            <a href="https://commerce.coinbase.com/dashboard/settings" target="_blank" rel="noopener noreferrer">
                                Commerce Dashboard <i class="fas fa-external-link-alt fa-xs"></i>
                            </a>.
                        </p>
                    </div>
                </div>
                <div class="mt-3">
                    <a href="mailto:support@signulo.id" class="btn btn-outline-primary">
                        <i class="fas fa-envelope me-2"></i>Contact Support
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 🍞 Toast notification container — positioned top-right             -->
    <!-- @see https://getbootstrap.com/docs/5.3/components/toasts/          -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- 📦 JAVASCRIPT DEPENDENCIES                                         -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->

    <!-- 🎨 Bootstrap 5.3.2 JS bundle (includes Popper.js for dropdowns/toasts) -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- 🔐 CSRF Token for secure AJAX requests -->
    <!-- Generated server-side by SecurityUtils::generateCSRFToken() -->
    <script>const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';</script>

    <script>
        // ============================================================================
        // 🔧 CONFIGURATION CONSTANTS
        // ============================================================================

        /** @var {number} partnerID — The currently selected partner's ID */
        const partnerID = <?php echo (int)$selectedPartnerID; ?>;

        /** @var {string} apiEndpoint — Path to the payment config API endpoint */
        const apiEndpoint = '../api/payment-config-actions.php';

        // ============================================================================
        // 🍞 TOAST NOTIFICATION SYSTEM
        // ============================================================================

        /**
         * 🍞 Show a toast notification
         *
         * Creates and displays a Bootstrap toast notification in the top-right corner.
         * Toasts auto-dismiss after 5 seconds.
         *
         * @param {string} message  — The notification message to display
         * @param {string} type     — Bootstrap colour class: 'success', 'danger', 'warning', 'info'
         * @param {string} title    — Optional toast title (default: capitalised type)
         *
         * @see https://getbootstrap.com/docs/5.3/components/toasts/
         */
        function showToast(message, type = 'info', title = '') {
            // 🎨 Determine the icon based on notification type
            const icons = {
                success: 'fas fa-check-circle',
                danger:  'fas fa-exclamation-circle',
                warning: 'fas fa-exclamation-triangle',
                info:    'fas fa-info-circle'
            };

            // 🏷️ Default title to capitalised type if not provided
            if (!title) {
                title = type.charAt(0).toUpperCase() + type.slice(1);
            }

            // 🔨 Create the toast HTML element
            const toastEl = document.createElement('div');
            toastEl.className = `toast align-items-center text-bg-${type} border-0`;
            toastEl.setAttribute('role', 'alert');
            toastEl.setAttribute('aria-live', 'assertive');
            toastEl.setAttribute('aria-atomic', 'true');
            toastEl.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="${icons[type] || icons.info} me-2"></i>
                        <strong>${title}:</strong> ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto"
                            data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            `;

            // 📌 Append to the toast container and show
            document.getElementById('toastContainer').appendChild(toastEl);
            const toast = new bootstrap.Toast(toastEl, { delay: 5000 });
            toast.show();

            // 🧹 Remove the toast element from DOM after it hides
            toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
        }

        /**
         * 💬 Show an inline alert message (legacy fallback)
         *
         * Creates a dismissible Bootstrap alert in the #alertContainer.
         *
         * @param {string} message — Alert message HTML
         * @param {string} type    — Bootstrap colour class
         */
        function showAlert(message, type = 'info') {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            document.getElementById('alertContainer').appendChild(alert);
            // 🕐 Auto-dismiss after 6 seconds
            setTimeout(() => alert.remove(), 6000);
        }

        // ============================================================================
        // 🔀 MODE SELECTION (Own Keys vs SIGNula Keys)
        // ============================================================================

        /**
         * 🔀 Select payment mode for a provider
         *
         * Toggles the visual state of the mode option cards and enables/disables
         * the credentials section and test connection button accordingly.
         *
         * @param {string} provider — Provider key: 'stripe', 'paypal', or 'coinbase'
         * @param {string} mode     — Mode: 'own_keys' or 'signula_keys'
         */
        function selectMode(provider, mode) {
            // 🎨 Update visual selection state of mode cards
            const ownCard    = document.getElementById(`${provider}-mode-own`);
            const signulaCard = document.getElementById(`${provider}-mode-signula`);
            const credSection = document.getElementById(`${provider}-credentials`);
            const testBtn     = document.getElementById(`${provider}-test-btn`);

            if (mode === 'own_keys') {
                // ✅ Own keys selected — highlight own card, show credentials
                ownCard.classList.add('selected');
                signulaCard.classList.remove('selected');
                document.getElementById(`${provider}-radio-own`).checked = true;

                // 🔓 Enable credentials section
                credSection.classList.remove('disabled-section');

                // 🔓 Enable test connection button
                if (testBtn) {
                    testBtn.disabled = false;
                }
            } else {
                // ✅ SIGNula keys selected — highlight SIGNula card, hide credentials
                signulaCard.classList.add('selected');
                ownCard.classList.remove('selected');
                document.getElementById(`${provider}-radio-signula`).checked = true;

                // 🔒 Disable credentials section (greyed out)
                credSection.classList.add('disabled-section');

                // 🔒 Disable test connection button (not needed for SIGNula keys)
                if (testBtn) {
                    testBtn.disabled = true;
                }
            }
        }

        // ============================================================================
        // 👁️ PASSWORD VISIBILITY TOGGLE
        // ============================================================================

        /**
         * 👁️ Toggle visibility of a password/credential input field
         *
         * Switches between type="password" (hidden) and type="text" (visible),
         * and updates the eye icon accordingly.
         *
         * @param {string} inputId — The DOM ID of the input element to toggle
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/input/password
         */
        function togglePasswordVisibility(inputId) {
            const input  = document.getElementById(inputId);
            const button = input.parentElement.querySelector('.toggle-visibility i');

            if (input.type === 'password') {
                // 👁️ Show the credential value
                input.type = 'text';
                button.className = 'fas fa-eye-slash';
            } else {
                // 🔒 Hide the credential value
                input.type = 'password';
                button.className = 'fas fa-eye';
            }
        }

        // ============================================================================
        // 💾 SAVE CONFIGURATION
        // ============================================================================

        /**
         * 💾 Save payment provider configuration
         *
         * Collects all field values for the specified provider and sends them
         * to the API endpoint via a POST request. Shows a toast on success/failure.
         *
         * @param {string} provider — Provider key: 'stripe', 'paypal', or 'coinbase'
         *
         * @see payment-config-actions.php (save_config action)
         */
        async function saveConfig(provider) {
            // 📋 Determine which mode is selected (own_keys or signula_keys)
            const usesOwnKeys = document.querySelector(`input[name="${provider}-mode-radio"]:checked`)?.value === 'own_keys';

            // 🔘 Get the enabled state from the toggle switch
            const isEnabled = document.getElementById(`${provider}-enabled`).checked;

            // 🔀 Get the environment mode (sandbox or live)
            const envMode = document.getElementById(`${provider}-env-mode`).value;

            // 🔑 Collect credential values from the input fields
            // Note: these may contain masked values (••••abcd) if user hasn't changed them
            const apiKey        = document.getElementById(`${provider}-api-key`)?.value || '';
            const apiSecret     = document.getElementById(`${provider}-api-secret`)?.value || '';
            const webhookSecret = document.getElementById(`${provider}-webhook-secret`)?.value || '';
            const publishableKey = document.getElementById(`${provider}-publishable-key`)?.value || '';

            // 📦 Build the request payload
            const payload = {
                action:         'save_config',
                partnerID:      partnerID,
                provider:       provider,
                usesOwnKeys:    usesOwnKeys,
                apiKey:         apiKey,
                apiSecret:      apiSecret,
                webhookSecret:  webhookSecret,
                publishableKey: publishableKey,
                mode:           envMode,
                isEnabled:      isEnabled,
                csrf_token:     csrfToken
            };

            try {
                // 📡 Send the save request to the API
                const response = await fetch(apiEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const result = await response.json();

                if (result.success) {
                    // ✅ Configuration saved successfully
                    showToast(result.message || 'Configuration saved successfully', 'success', 'Saved');
                    // 🔄 Reload page after a short delay to show updated state
                    setTimeout(() => location.reload(), 2000);
                } else {
                    // ❌ Save failed — show error message
                    showToast(result.message || 'Failed to save configuration', 'danger', 'Error');
                }
            } catch (error) {
                // ❌ Network or parsing error
                console.error('[SIGNula] Save config error:', error);
                showToast('An error occurred while saving. Please try again.', 'danger', 'Error');
            }
        }

        // ============================================================================
        // 🧪 TEST CONNECTION
        // ============================================================================

        /**
         * 🧪 Test payment provider connection
         *
         * Sends a test_connection request to the API endpoint for the specified
         * provider. Only available when "Use Own Keys" is selected, as SIGNula's
         * own keys don't need per-partner testing.
         *
         * @param {string} provider — Provider key: 'stripe', 'paypal', or 'coinbase'
         *
         * @see payment-config-actions.php (test_connection action)
         * @see PartnerPaymentService::testPartnerConnection()
         */
        async function testConnection(provider) {
            // 🔒 Get the test button and show loading state
            const testBtn = document.getElementById(`${provider}-test-btn`);
            const originalHTML = testBtn.innerHTML;
            testBtn.disabled = true;
            testBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Testing...';

            try {
                // 📡 Send the test connection request
                const response = await fetch(apiEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action:     'test_connection',
                        partnerID:  partnerID,
                        provider:   provider,
                        csrf_token: csrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    // ✅ Connection test passed
                    showToast(result.message || 'Connection verified successfully!', 'success', 'Connection Test');
                    // 🔄 Reload to update verification badge
                    setTimeout(() => location.reload(), 2000);
                } else {
                    // ❌ Connection test failed
                    showToast(result.message || 'Connection test failed. Please check your credentials.', 'danger', 'Connection Test');
                }
            } catch (error) {
                // ❌ Network or parsing error
                console.error('[SIGNula] Test connection error:', error);
                showToast('An error occurred while testing the connection.', 'danger', 'Error');
            } finally {
                // 🔓 Restore the test button to its original state
                testBtn.disabled = false;
                testBtn.innerHTML = originalHTML;
            }
        }
    </script>
</body>
</html>
