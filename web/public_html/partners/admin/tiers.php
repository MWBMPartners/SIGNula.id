<?php
/**
 * ============================================================================
 * 💰 SIGNula - Partner Subscription Tier Management
 * ============================================================================
 *
 * Admin UI for managing partner subscription tiers (pricing plans that
 * partners offer to their own customers). Provides full CRUD operations
 * via AJAX calls to the tier-actions.php API endpoint.
 *
 * Features:
 * - Grid of tier cards with name, pricing, features, badge, status
 * - Create/Edit modal with live preview card
 * - Tag-based feature input (add/remove feature strings)
 * - Activate/Deactivate (soft-delete) tiers
 * - Set default tier for new subscribers
 * - Display order management
 * - CSRF-protected AJAX form submissions
 *
 * Database Table: tblPartnerSubscriptionTiers
 * Backend Class: PartnerPaymentService (web/private_html/payments/)
 * API Endpoint:  /partners/api/tier-actions.php
 *
 * @see /web/private_html/payments/PartnerPaymentService.php
 * @see /_database/migrations/012_payment_expansion.sql
 * @see https://getbootstrap.com/docs/5.3/                    (Bootstrap 5.3)
 * @see https://fontawesome.com/icons                          (FontAwesome 6.4)
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Partners\Admin
 * @version    1.0.0
 * @since      2.4.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 📦 DEPENDENCY LOADING
// ============================================================================

// 🔧 Load core configuration (defines SIGNULA_INIT constant, DB credentials, etc.)
// dirname(__DIR__, 3) resolves to /web/ from /web/public_html/partners/admin/
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 3) . '/_config/config.php';

// 🗄️ Database singleton — provides MySQLi prepared statement interface
// @see https://www.php.net/manual/en/book.mysqli.php
require_once dirname(__DIR__, 3) . '/_backend/Database.php';

// 🔐 Session management — handles login state, session tokens, regeneration
// @see https://www.php.net/manual/en/book.session.php
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';

// 🛡️ Access control — role-based permission checks for partner admin actions
require_once dirname(__DIR__, 3) . '/_backend/AccessControl.php';

// 💰 Partner payment service — subscription tier CRUD operations
// @see /web/private_html/payments/PartnerPaymentService.php
require_once dirname(__DIR__, 3) . '/private_html/payments/PartnerPaymentService.php';

// ============================================================================
// 🚀 INITIALISATION
// ============================================================================

// 📡 Instantiate core services via singleton/DI pattern
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// ============================================================================
// 🔒 AUTHENTICATION CHECK
// ============================================================================

// 🚫 Redirect unauthenticated users to the login page
// @see https://www.php.net/manual/en/function.header.php
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// ============================================================================
// 🏢 PARTNER SELECTION & VALIDATION
// ============================================================================

// 📋 Get all partner organisations the current user belongs to
// Returns associative array keyed by partnerID with membership details
$memberships = $accessControl->getPartnerMemberships();

// 🚫 If user has no partner memberships, redirect to registration
if (empty($memberships)) {
    header('Location: /partners/register.php');
    exit;
}

// 🎯 Determine which partner is currently selected
// Priority: URL parameter > first available membership
// @see https://www.php.net/manual/en/function.array-key-first.php
$selectedPartnerID = $_GET['partner'] ?? array_key_first($memberships);
$partner = $memberships[$selectedPartnerID] ?? reset($memberships);

// 🛡️ Verify the current user has admin-level access for this partner
// Non-admins (developer, support, finance roles) cannot manage tiers
if (!$accessControl->isPartnerAdmin($selectedPartnerID)) {
    http_response_code(403);
    die('Admin access required for this partner');
}

// ============================================================================
// 📊 DATA LOADING
// ============================================================================

// 🏢 Fetch full partner details from tblPartners
// Used for displaying partner name, tier info, and configuration
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE partnerID = ?");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerDetails = $stmt->get_result()->fetch_assoc();

// 💰 Load all subscription tiers for this partner (including inactive ones)
// Admin view shows ALL tiers so admins can reactivate deactivated tiers
// The PartnerPaymentService handles JSON decoding of features/featureLimits
// @see PartnerPaymentService::getPartnerTiers()
$tiers = PartnerPaymentService::getPartnerTiers((int)$selectedPartnerID, true);

// 📊 Count active vs inactive tiers for summary statistics
$activeTierCount = 0;
$inactiveTierCount = 0;
foreach ($tiers as $tier) {
    if (!empty($tier['isActive'])) {
        $activeTierCount++;
    } else {
        $inactiveTierCount++;
    }
}

// 🌐 Supported currencies for the dropdown selector
// ISO 4217 currency codes — expand as needed
// @see https://en.wikipedia.org/wiki/ISO_4217
$supportedCurrencies = [
    'GBP' => 'GBP (£) - British Pound',
    'USD' => 'USD ($) - US Dollar',
    'EUR' => 'EUR (€) - Euro',
    'CAD' => 'CAD ($) - Canadian Dollar',
    'AUD' => 'AUD ($) - Australian Dollar',
    'NZD' => 'NZD ($) - New Zealand Dollar',
    'JPY' => 'JPY (¥) - Japanese Yen',
    'CHF' => 'CHF (Fr) - Swiss Franc',
    'SEK' => 'SEK (kr) - Swedish Krona',
    'NOK' => 'NOK (kr) - Norwegian Krone',
    'DKK' => 'DKK (kr) - Danish Krone',
    'SGD' => 'SGD ($) - Singapore Dollar',
    'HKD' => 'HKD ($) - Hong Kong Dollar',
    'INR' => 'INR (₹) - Indian Rupee',
    'BRL' => 'BRL (R$) - Brazilian Real',
    'ZAR' => 'ZAR (R) - South African Rand',
];

// 🎨 Badge colour options for tier cards
// Maps to Bootstrap CSS classes for consistent styling
// @see https://getbootstrap.com/docs/5.3/components/badge/
$badgeColors = [
    'primary'   => 'Primary (Blue)',
    'success'   => 'Success (Green)',
    'warning'   => 'Warning (Yellow)',
    'danger'    => 'Danger (Red)',
    'info'      => 'Info (Teal)',
    'secondary' => 'Secondary (Grey)',
    'dark'      => 'Dark',
    'light'     => 'Light',
];

// 💱 Currency symbol map for display in tier cards
$currencySymbols = [
    'GBP' => '£',
    'USD' => '$',
    'EUR' => '€',
    'CAD' => 'C$',
    'AUD' => 'A$',
    'NZD' => 'NZ$',
    'JPY' => '¥',
    'CHF' => 'Fr',
    'SEK' => 'kr',
    'NOK' => 'kr',
    'DKK' => 'kr',
    'SGD' => 'S$',
    'HKD' => 'HK$',
    'INR' => '₹',
    'BRL' => 'R$',
    'ZAR' => 'R',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ================================================================== -->
    <!-- 📄 META & TITLE                                                    -->
    <!-- ================================================================== -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Subscription Tiers - <?php echo htmlspecialchars($partner['companyName']); ?> | SIGNula</title>

    <!-- ================================================================== -->
    <!-- 📦 CSS DEPENDENCIES (CDN with SRI)                                 -->
    <!-- ================================================================== -->

    <!-- 🎨 Bootstrap 5.3.2 — responsive grid, components, utilities        -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- 🎨 FontAwesome 6.4.2 — icon library for UI elements               -->
    <!-- @see https://fontawesome.com/icons                                  -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <!-- ================================================================== -->
    <!-- 🎨 CUSTOM STYLES                                                   -->
    <!-- ================================================================== -->
    <style>
        /* 🖼️ Page background — light grey to provide visual contrast */
        body {
            background-color: #f8f9fa;
        }

        /* 🎨 Page header — gradient banner matching partner admin theme */
        .tiers-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        /* 💰 Tier card — individual subscription tier display */
        .tier-card {
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
            position: relative;
        }

        /* ✨ Tier card hover effect — subtle lift animation */
        .tier-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        /* 🚫 Inactive tier card — reduced opacity to indicate deactivated */
        .tier-card.inactive {
            opacity: 0.65;
            border: 2px dashed #dee2e6;
        }

        /* ⭐ Default tier card — highlighted border to stand out */
        .tier-card.is-default {
            border: 2px solid #198754;
        }

        /* 🏷️ Default badge — positioned at top-right corner of card */
        .default-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 2;
        }

        /* 💵 Price display — large bold text for monthly pricing */
        .tier-price {
            font-size: 2rem;
            font-weight: 700;
            color: #333;
        }

        /* 📏 Price period — smaller text for "/month" suffix */
        .tier-price .period {
            font-size: 0.9rem;
            font-weight: 400;
            color: #6c757d;
        }

        /* 📋 Feature list — clean list styling for tier features */
        .tier-features {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        /* ✅ Feature list item — green checkmark prefix */
        .tier-features li {
            padding: 0.35rem 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .tier-features li:last-child {
            border-bottom: none;
        }

        /* 🏷️ Feature tag — styled tag for feature input */
        .feature-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: #e8f5e9;
            color: #2e7d32;
            padding: 0.3rem 0.65rem;
            border-radius: 20px;
            font-size: 0.85rem;
            margin: 0.2rem;
            transition: all 0.2s;
        }

        /* ❌ Feature tag remove button */
        .feature-tag .remove-tag {
            cursor: pointer;
            color: #c62828;
            font-weight: bold;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .feature-tag .remove-tag:hover {
            opacity: 1;
        }

        /* 🔮 Live preview card in modal — shows real-time tier card */
        .preview-card {
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
            min-height: 300px;
            transition: all 0.3s;
        }

        /* 📊 Stats bar in header — semi-transparent background */
        .tier-stats {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 1rem;
        }

        /* 🔧 Feature limit row — key-value pair styling */
        .feature-limit-row {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        /* 🎛️ Modal — wider for better form layout */
        .modal-xl-custom {
            max-width: 950px;
        }

        /* 📱 Responsive adjustments for mobile viewports */
        @media (max-width: 768px) {
            .tiers-header {
                padding: 1.25rem;
            }
            .tier-price {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- ================================================================== -->
    <!-- 📄 MAIN CONTENT CONTAINER                                          -->
    <!-- ================================================================== -->
    <div class="container-fluid py-4">

        <!-- ============================================================== -->
        <!-- 🎨 PAGE HEADER — Gradient banner with title and stats          -->
        <!-- ============================================================== -->
        <div class="tiers-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <!-- 🔙 Back navigation to partner admin dashboard -->
                    <a href="index.php?partner=<?php echo (int)$selectedPartnerID; ?>"
                       class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                        <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
                    </a>
                    <h1><i class="fas fa-layer-group me-2"></i>Subscription Tiers</h1>
                    <p class="mb-0 opacity-75">
                        <?php echo htmlspecialchars($partner['companyName']); ?>
                        &mdash; Manage the pricing plans you offer to your customers
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <!-- 📊 Tier statistics summary -->
                    <div class="tier-stats">
                        <h3 class="mb-0"><?php echo $activeTierCount; ?></h3>
                        <small>Active Tiers</small>
                        <?php if ($inactiveTierCount > 0): ?>
                        <br>
                        <small class="opacity-75">
                            <i class="fas fa-eye-slash me-1"></i><?php echo $inactiveTierCount; ?> inactive
                        </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- ⚠️ ALERT MESSAGES — Dynamic AJAX response container            -->
        <!-- ============================================================== -->
        <div id="alertContainer"></div>

        <!-- ============================================================== -->
        <!-- ℹ️ INFO BOX — Help text explaining subscription tiers          -->
        <!-- ============================================================== -->
        <div class="alert alert-info mb-4">
            <h6 class="alert-heading"><i class="fas fa-info-circle me-2"></i>About Subscription Tiers</h6>
            <p class="mb-2">
                Define the pricing plans your customers can subscribe to. Each tier can have its own monthly/yearly
                pricing, feature list, trial period, and display badge.
            </p>
            <ul class="mb-0">
                <li><strong>Default Tier:</strong> Automatically assigned to new subscribers (typically a free/basic tier)</li>
                <li><strong>Features:</strong> List the benefits included in each tier</li>
                <li><strong>Trial Days:</strong> Offer a free trial period before billing begins</li>
                <li><strong>Inactive Tiers:</strong> Deactivated tiers are hidden from customers but existing subscriptions continue</li>
            </ul>
        </div>

        <!-- ============================================================== -->
        <!-- ➕ CREATE NEW TIER BUTTON                                      -->
        <!-- ============================================================== -->
        <div class="row mb-4">
            <div class="col-md-12">
                <button class="btn btn-success btn-lg"
                        data-bs-toggle="modal"
                        data-bs-target="#tierModal"
                        onclick="openCreateModal()">
                    <i class="fas fa-plus-circle me-2"></i>Create New Tier
                </button>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- 💰 TIER CARDS GRID — Displays all subscription tiers           -->
        <!-- ============================================================== -->
        <?php if (empty($tiers)): ?>
        <!-- 📭 Empty state — no tiers created yet -->
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-layer-group fa-4x text-muted mb-3"></i>
                <h4>No Subscription Tiers Yet</h4>
                <p class="text-muted">
                    Create your first subscription tier to start offering pricing plans to your customers.
                </p>
                <button class="btn btn-success"
                        data-bs-toggle="modal"
                        data-bs-target="#tierModal"
                        onclick="openCreateModal()">
                    <i class="fas fa-plus-circle me-2"></i>Create Your First Tier
                </button>
            </div>
        </div>
        <?php else: ?>
        <div class="row" id="tiersGrid">
            <?php foreach ($tiers as $tier): ?>
            <?php
                // 💱 Resolve the currency symbol for display
                $currSymbol = $currencySymbols[$tier['currency'] ?? 'GBP'] ?? '£';

                // 📦 Ensure features is an array (PartnerPaymentService decodes JSON)
                $features = is_array($tier['features']) ? $tier['features'] : [];

                // 🏷️ Determine badge colour class (fallback to 'primary')
                $badgeClass = !empty($tier['badgeColor']) ? $tier['badgeColor'] : 'primary';
            ?>
            <!-- 💰 Tier Card: <?php echo htmlspecialchars($tier['tierName']); ?> -->
            <div class="col-lg-4 col-md-6 mb-4" id="tierCard-<?php echo (int)$tier['tierID']; ?>">
                <div class="card tier-card <?php echo empty($tier['isActive']) ? 'inactive' : ''; ?> <?php echo !empty($tier['isDefault']) ? 'is-default' : ''; ?>">

                    <?php if (!empty($tier['isDefault'])): ?>
                    <!-- ⭐ Default tier indicator badge -->
                    <span class="default-badge badge bg-success">
                        <i class="fas fa-star me-1"></i>Default
                    </span>
                    <?php endif; ?>

                    <div class="card-body">
                        <!-- 🏷️ Tier name and optional badge -->
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h5 class="card-title mb-0">
                                <?php echo htmlspecialchars($tier['tierName']); ?>
                            </h5>
                            <?php if (!empty($tier['badge'])): ?>
                            <span class="badge bg-<?php echo htmlspecialchars($badgeClass); ?>">
                                <?php echo htmlspecialchars($tier['badge']); ?>
                            </span>
                            <?php endif; ?>
                        </div>

                        <!-- 🔖 Slug and status -->
                        <div class="mb-3">
                            <small class="text-muted">
                                <i class="fas fa-link me-1"></i><?php echo htmlspecialchars($tier['tierSlug']); ?>
                            </small>
                            <?php if (empty($tier['isActive'])): ?>
                            <span class="badge bg-danger ms-2">
                                <i class="fas fa-eye-slash me-1"></i>Inactive
                            </span>
                            <?php else: ?>
                            <span class="badge bg-success ms-2">
                                <i class="fas fa-check-circle me-1"></i>Active
                            </span>
                            <?php endif; ?>
                        </div>

                        <!-- 💵 Pricing display -->
                        <div class="mb-3">
                            <div class="tier-price">
                                <?php echo htmlspecialchars($currSymbol); ?><?php echo number_format((float)$tier['monthlyPrice'], 2); ?>
                                <span class="period">/month</span>
                            </div>
                            <?php if ((float)$tier['yearlyPrice'] > 0): ?>
                            <small class="text-muted">
                                <i class="fas fa-calendar me-1"></i>
                                <?php echo htmlspecialchars($currSymbol); ?><?php echo number_format((float)$tier['yearlyPrice'], 2); ?>/year
                                <?php
                                    // 📊 Calculate yearly savings percentage vs monthly*12
                                    $monthlyTotal = (float)$tier['monthlyPrice'] * 12;
                                    if ($monthlyTotal > 0 && (float)$tier['yearlyPrice'] < $monthlyTotal) {
                                        $savings = round(100 - ((float)$tier['yearlyPrice'] / $monthlyTotal * 100));
                                        echo '<span class="text-success fw-bold">(Save ' . $savings . '%)</span>';
                                    }
                                ?>
                            </small>
                            <?php endif; ?>
                        </div>

                        <!-- 🎁 Trial days indicator -->
                        <?php if ((int)($tier['trialDays'] ?? 0) > 0): ?>
                        <div class="mb-3">
                            <span class="badge bg-info">
                                <i class="fas fa-clock me-1"></i><?php echo (int)$tier['trialDays']; ?>-day free trial
                            </span>
                        </div>
                        <?php endif; ?>

                        <!-- 📝 Description -->
                        <?php if (!empty($tier['tierDescription'])): ?>
                        <p class="card-text text-muted small mb-3">
                            <?php echo htmlspecialchars($tier['tierDescription']); ?>
                        </p>
                        <?php endif; ?>

                        <!-- ✅ Features list -->
                        <?php if (!empty($features)): ?>
                        <ul class="tier-features mb-3">
                            <?php foreach ($features as $feature): ?>
                            <li>
                                <i class="fas fa-check text-success me-2"></i>
                                <?php echo htmlspecialchars($feature); ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                        <p class="text-muted small mb-3">
                            <i class="fas fa-info-circle me-1"></i>No features listed
                        </p>
                        <?php endif; ?>

                        <!-- 📊 Display order -->
                        <div class="mb-3">
                            <small class="text-muted">
                                <i class="fas fa-sort-numeric-up me-1"></i>Display Order: <?php echo (int)$tier['displayOrder']; ?>
                            </small>
                        </div>
                    </div>

                    <!-- 🔧 Action buttons footer -->
                    <div class="card-footer bg-white">
                        <div class="d-flex flex-wrap gap-2">
                            <!-- ✏️ Edit button -->
                            <button class="btn btn-sm btn-outline-primary"
                                    onclick="openEditModal(<?php echo (int)$tier['tierID']; ?>)"
                                    title="Edit this tier">
                                <i class="fas fa-edit me-1"></i>Edit
                            </button>

                            <?php if (!empty($tier['isActive'])): ?>
                            <!-- 🚫 Deactivate button (only for active tiers) -->
                            <button class="btn btn-sm btn-outline-warning"
                                    onclick="toggleTierStatus(<?php echo (int)$tier['tierID']; ?>, '<?php echo htmlspecialchars($tier['tierName'], ENT_QUOTES); ?>', false)"
                                    title="Deactivate this tier">
                                <i class="fas fa-eye-slash me-1"></i>Deactivate
                            </button>
                            <?php else: ?>
                            <!-- ✅ Activate button (only for inactive tiers) -->
                            <button class="btn btn-sm btn-outline-success"
                                    onclick="toggleTierStatus(<?php echo (int)$tier['tierID']; ?>, '<?php echo htmlspecialchars($tier['tierName'], ENT_QUOTES); ?>', true)"
                                    title="Reactivate this tier">
                                <i class="fas fa-eye me-1"></i>Activate
                            </button>
                            <?php endif; ?>

                            <?php if (empty($tier['isDefault']) && !empty($tier['isActive'])): ?>
                            <!-- ⭐ Set as default button (only for non-default active tiers) -->
                            <button class="btn btn-sm btn-outline-success"
                                    onclick="setDefaultTier(<?php echo (int)$tier['tierID']; ?>, '<?php echo htmlspecialchars($tier['tierName'], ENT_QUOTES); ?>')"
                                    title="Set as default tier for new subscribers">
                                <i class="fas fa-star me-1"></i>Set Default
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================== -->
        <!-- ❓ HELP CARD — Additional guidance for administrators           -->
        <!-- ============================================================== -->
        <div class="card mt-4">
            <div class="card-body">
                <h6 class="mb-3"><i class="fas fa-question-circle me-2"></i>Need Help?</h6>
                <div class="row">
                    <div class="col-md-4">
                        <h6><i class="fas fa-layer-group me-1 text-primary"></i> Tier Structure</h6>
                        <p class="text-muted small">
                            Create multiple tiers (e.g. Free, Basic, Professional, Enterprise) to offer
                            different levels of access and features to your customers.
                        </p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-star me-1 text-warning"></i> Default Tier</h6>
                        <p class="text-muted small">
                            The default tier is automatically assigned to new subscribers.
                            This is typically a free or basic tier. Only one tier can be the default.
                        </p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-eye-slash me-1 text-danger"></i> Deactivation</h6>
                        <p class="text-muted small">
                            Deactivating a tier hides it from new customers but does not affect
                            existing subscriptions. You can reactivate it at any time.
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

    </div><!-- /.container-fluid -->

    <!-- ================================================================== -->
    <!-- 💰 CREATE/EDIT TIER MODAL                                          -->
    <!-- ================================================================== -->
    <!-- Bootstrap modal for creating and editing subscription tiers.        -->
    <!-- Uses a two-column layout: form fields on the left, live preview     -->
    <!-- card on the right that updates in real-time as fields change.       -->
    <!-- @see https://getbootstrap.com/docs/5.3/components/modal/           -->
    <div class="modal fade" id="tierModal" tabindex="-1" aria-labelledby="tierModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl-custom modal-dialog-scrollable">
            <div class="modal-content">

                <!-- 🏷️ Modal Header -->
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="tierModalLabel">
                        <i class="fas fa-layer-group me-2"></i>
                        <span id="modalTitleText">Create New Tier</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- 📝 Modal Body -->
                <div class="modal-body">
                    <div class="row">

                        <!-- ============================================== -->
                        <!-- 📝 FORM FIELDS (Left Column)                   -->
                        <!-- ============================================== -->
                        <div class="col-md-7">
                            <form id="tierForm" novalidate>
                                <!-- 🔒 Hidden field: tierID (populated when editing) -->
                                <input type="hidden" id="formTierID" value="">

                                <!-- 🏷️ Tier Name -->
                                <div class="mb-3">
                                    <label for="formTierName" class="form-label fw-bold">
                                        <i class="fas fa-heading me-1"></i>Tier Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text"
                                           class="form-control"
                                           id="formTierName"
                                           placeholder="e.g. Professional, Enterprise, Starter"
                                           required
                                           maxlength="100"
                                           oninput="updateSlugFromName(); updatePreview();">
                                    <div class="form-text">The display name shown to your customers.</div>
                                </div>

                                <!-- 🔗 Tier Slug (auto-generated from name) -->
                                <div class="mb-3">
                                    <label for="formTierSlug" class="form-label fw-bold">
                                        <i class="fas fa-link me-1"></i>Tier Slug <span class="text-danger">*</span>
                                    </label>
                                    <input type="text"
                                           class="form-control"
                                           id="formTierSlug"
                                           placeholder="auto-generated-from-name"
                                           required
                                           maxlength="50"
                                           pattern="[a-z0-9\-]+"
                                           oninput="updatePreview();">
                                    <div class="form-text">
                                        URL-safe identifier (lowercase, numbers, hyphens only). Auto-generated from tier name.
                                    </div>
                                </div>

                                <!-- 📝 Description -->
                                <div class="mb-3">
                                    <label for="formTierDescription" class="form-label fw-bold">
                                        <i class="fas fa-align-left me-1"></i>Description
                                    </label>
                                    <textarea class="form-control"
                                              id="formTierDescription"
                                              rows="3"
                                              placeholder="Describe the benefits of this tier..."
                                              maxlength="500"
                                              oninput="updatePreview();"></textarea>
                                    <div class="form-text">Optional description of tier benefits (max 500 characters).</div>
                                </div>

                                <!-- 💰 Pricing Row (Monthly + Yearly + Currency) -->
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label for="formMonthlyPrice" class="form-label fw-bold">
                                            <i class="fas fa-calendar-day me-1"></i>Monthly Price
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text" id="currencySymbolDisplay">£</span>
                                            <input type="number"
                                                   class="form-control"
                                                   id="formMonthlyPrice"
                                                   value="0.00"
                                                   min="0"
                                                   step="0.01"
                                                   oninput="updatePreview();">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="formYearlyPrice" class="form-label fw-bold">
                                            <i class="fas fa-calendar me-1"></i>Yearly Price
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text" id="currencySymbolDisplay2">£</span>
                                            <input type="number"
                                                   class="form-control"
                                                   id="formYearlyPrice"
                                                   value="0.00"
                                                   min="0"
                                                   step="0.01"
                                                   oninput="updatePreview();">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="formCurrency" class="form-label fw-bold">
                                            <i class="fas fa-coins me-1"></i>Currency
                                        </label>
                                        <select class="form-select" id="formCurrency" onchange="updateCurrencySymbol(); updatePreview();">
                                            <?php foreach ($supportedCurrencies as $code => $label): ?>
                                            <option value="<?php echo htmlspecialchars($code); ?>"
                                                    <?php echo $code === 'GBP' ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- ✅ Features (Tag Input) -->
                                <div class="mb-3">
                                    <label class="form-label fw-bold">
                                        <i class="fas fa-list-check me-1"></i>Features
                                    </label>
                                    <!-- 🏷️ Feature tags container -->
                                    <div id="featureTagsContainer" class="border rounded p-2 mb-2 min-vh-5" style="min-height: 44px;">
                                        <!-- Feature tags are dynamically inserted here -->
                                    </div>
                                    <!-- ➕ Add feature input -->
                                    <div class="input-group">
                                        <input type="text"
                                               class="form-control"
                                               id="featureInput"
                                               placeholder="Type a feature and press Enter or click Add..."
                                               maxlength="100">
                                        <button class="btn btn-outline-success"
                                                type="button"
                                                onclick="addFeatureTag()">
                                            <i class="fas fa-plus me-1"></i>Add
                                        </button>
                                    </div>
                                    <div class="form-text">Press Enter or click Add to include a feature. Click the X on a tag to remove it.</div>
                                </div>

                                <!-- ⚙️ Feature Limits (Key-Value Pairs) -->
                                <div class="mb-3">
                                    <label class="form-label fw-bold">
                                        <i class="fas fa-sliders me-1"></i>Feature Limits <small class="text-muted">(Optional)</small>
                                    </label>
                                    <div id="featureLimitsContainer">
                                        <!-- 🔧 Feature limit rows are dynamically inserted here -->
                                    </div>
                                    <button class="btn btn-sm btn-outline-secondary mt-2"
                                            type="button"
                                            onclick="addFeatureLimitRow()">
                                        <i class="fas fa-plus me-1"></i>Add Limit
                                    </button>
                                    <div class="form-text">
                                        Define numeric limits (e.g. api_calls: 1000, storage_gb: 5). Stored as JSON.
                                    </div>
                                </div>

                                <!-- 📊 Display Order + Trial Days Row -->
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="formDisplayOrder" class="form-label fw-bold">
                                            <i class="fas fa-sort-numeric-up me-1"></i>Display Order
                                        </label>
                                        <input type="number"
                                               class="form-control"
                                               id="formDisplayOrder"
                                               value="0"
                                               min="0"
                                               step="1">
                                        <div class="form-text">Lower numbers appear first on the pricing page.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="formTrialDays" class="form-label fw-bold">
                                            <i class="fas fa-clock me-1"></i>Trial Days
                                        </label>
                                        <input type="number"
                                               class="form-control"
                                               id="formTrialDays"
                                               value="0"
                                               min="0"
                                               step="1"
                                               oninput="updatePreview();">
                                        <div class="form-text">Free trial period in days (0 = no trial).</div>
                                    </div>
                                </div>

                                <!-- 🏷️ Badge Row (Text + Colour) -->
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="formBadge" class="form-label fw-bold">
                                            <i class="fas fa-tag me-1"></i>Badge Text
                                        </label>
                                        <input type="text"
                                               class="form-control"
                                               id="formBadge"
                                               placeholder="e.g. Popular, Best Value, New"
                                               maxlength="50"
                                               oninput="updatePreview();">
                                        <div class="form-text">Optional badge displayed on the tier card.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="formBadgeColor" class="form-label fw-bold">
                                            <i class="fas fa-palette me-1"></i>Badge Colour
                                        </label>
                                        <select class="form-select" id="formBadgeColor" onchange="updatePreview();">
                                            <option value="">No Badge</option>
                                            <?php foreach ($badgeColors as $colorKey => $colorLabel): ?>
                                            <option value="<?php echo htmlspecialchars($colorKey); ?>">
                                                <?php echo htmlspecialchars($colorLabel); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- ⭐ Is Default Checkbox -->
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               id="formIsDefault"
                                               role="switch">
                                        <label class="form-check-label fw-bold" for="formIsDefault">
                                            <i class="fas fa-star me-1 text-warning"></i>Set as Default Tier
                                        </label>
                                    </div>
                                    <div class="form-text">
                                        Default tiers are automatically assigned to new subscribers.
                                        Setting this will unset any existing default tier.
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- ============================================== -->
                        <!-- 🔮 LIVE PREVIEW (Right Column)                 -->
                        <!-- ============================================== -->
                        <div class="col-md-5">
                            <h6 class="fw-bold mb-3">
                                <i class="fas fa-eye me-2"></i>Live Preview
                            </h6>
                            <div class="card preview-card" id="livePreviewCard">
                                <div class="card-body">
                                    <!-- 🏷️ Preview tier name and badge -->
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <h5 class="card-title mb-0" id="previewName">
                                            <span class="text-muted">Tier Name</span>
                                        </h5>
                                        <span id="previewBadge" class="badge" style="display: none;"></span>
                                    </div>

                                    <!-- 🔖 Preview slug -->
                                    <div class="mb-3">
                                        <small class="text-muted">
                                            <i class="fas fa-link me-1"></i><span id="previewSlug">tier-slug</span>
                                        </small>
                                    </div>

                                    <!-- 💵 Preview pricing -->
                                    <div class="mb-3">
                                        <div class="tier-price">
                                            <span id="previewCurrency">£</span><span id="previewMonthlyPrice">0.00</span>
                                            <span class="period">/month</span>
                                        </div>
                                        <small class="text-muted" id="previewYearlyContainer" style="display: none;">
                                            <i class="fas fa-calendar me-1"></i>
                                            <span id="previewCurrency2">£</span><span id="previewYearlyPrice">0.00</span>/year
                                            <span class="text-success fw-bold" id="previewSavings"></span>
                                        </small>
                                    </div>

                                    <!-- 🎁 Preview trial days -->
                                    <div class="mb-3" id="previewTrialContainer" style="display: none;">
                                        <span class="badge bg-info">
                                            <i class="fas fa-clock me-1"></i><span id="previewTrialDays">0</span>-day free trial
                                        </span>
                                    </div>

                                    <!-- 📝 Preview description -->
                                    <p class="card-text text-muted small mb-3" id="previewDescription" style="display: none;"></p>

                                    <!-- ✅ Preview features -->
                                    <ul class="tier-features mb-0" id="previewFeatures">
                                        <li class="text-muted">
                                            <i class="fas fa-info-circle me-2"></i>Add features to see them here
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <div class="alert alert-light mt-3 small">
                                <i class="fas fa-info-circle me-1"></i>
                                This preview updates in real-time as you fill in the form fields.
                            </div>
                        </div>

                    </div><!-- /.row -->
                </div>

                <!-- 🔘 Modal Footer -->
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-success" id="saveTierBtn" onclick="saveTier()">
                        <i class="fas fa-save me-2"></i><span id="saveBtnText">Create Tier</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================== -->
    <!-- 📦 JAVASCRIPT DEPENDENCIES                                         -->
    <!-- ================================================================== -->

    <!-- 🎨 Bootstrap 5.3.2 JS Bundle (includes Popper.js for dropdowns)    -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- 🔐 CSRF Token for secure AJAX requests                             -->
    <!-- Generated server-side and embedded for use in all POST requests     -->
    <!-- @see SecurityUtils::generateCSRFToken()                             -->
    <script>const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';</script>

    <!-- ================================================================== -->
    <!-- 🧠 APPLICATION JAVASCRIPT                                          -->
    <!-- ================================================================== -->
    <script>
        // ================================================================
        // 🔧 CONFIGURATION & STATE
        // ================================================================

        /** @type {number} The currently selected partner ID */
        const partnerID = <?php echo (int)$selectedPartnerID; ?>;

        /** @type {Bootstrap.Modal} Reference to the tier modal instance */
        const tierModal = new bootstrap.Modal(document.getElementById('tierModal'));

        /** @type {string[]} Array of current feature strings in the form */
        let currentFeatures = [];

        /** @type {Object} Key-value map of feature limits */
        let currentFeatureLimits = {};

        /** @type {Object} Currency code to symbol mapping */
        const currencySymbols = <?php echo json_encode($currencySymbols); ?>;

        // ================================================================
        // 🔔 ALERT HELPER
        // ================================================================

        /**
         * 🔔 Display a dismissible alert message at the top of the page
         *
         * Creates a Bootstrap alert element, appends it to the alert container,
         * and auto-removes it after 5 seconds.
         *
         * @param {string} message - The alert message HTML content
         * @param {string} type    - Bootstrap alert type: success, danger, warning, info
         *
         * @see https://getbootstrap.com/docs/5.3/components/alerts/
         */
        function showAlert(message, type = 'info') {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            document.getElementById('alertContainer').appendChild(alert);

            // ⏰ Auto-dismiss after 5 seconds
            setTimeout(() => alert.remove(), 5000);
        }

        // ================================================================
        // 🏷️ FEATURE TAG INPUT SYSTEM
        // ================================================================

        /**
         * ➕ Add a feature tag from the input field
         *
         * Reads the value from the featureInput field, validates it,
         * adds it to the currentFeatures array, and re-renders all tags.
         * Also updates the live preview card.
         */
        function addFeatureTag() {
            const input = document.getElementById('featureInput');
            const value = input.value.trim();

            // 🛡️ Validate: non-empty and not already in the list
            if (value === '') {
                return;
            }

            if (currentFeatures.includes(value)) {
                showAlert('This feature is already in the list.', 'warning');
                return;
            }

            // ➕ Add to array and re-render
            currentFeatures.push(value);
            renderFeatureTags();
            input.value = '';
            input.focus();

            // 🔮 Update the live preview
            updatePreview();
        }

        /**
         * ❌ Remove a feature tag by its index
         *
         * Removes the feature at the specified index from the array,
         * re-renders all tags, and updates the live preview.
         *
         * @param {number} index - Zero-based index of the feature to remove
         */
        function removeFeatureTag(index) {
            currentFeatures.splice(index, 1);
            renderFeatureTags();
            updatePreview();
        }

        /**
         * 🎨 Render all feature tags in the container
         *
         * Clears the container and re-creates DOM elements for each
         * feature in the currentFeatures array. Each tag includes a
         * clickable X button for removal.
         */
        function renderFeatureTags() {
            const container = document.getElementById('featureTagsContainer');
            container.innerHTML = '';

            if (currentFeatures.length === 0) {
                container.innerHTML = '<span class="text-muted small">No features added yet</span>';
                return;
            }

            currentFeatures.forEach((feature, index) => {
                const tag = document.createElement('span');
                tag.className = 'feature-tag';
                tag.innerHTML = `
                    <i class="fas fa-check-circle"></i>
                    ${escapeHtml(feature)}
                    <span class="remove-tag" onclick="removeFeatureTag(${index})" title="Remove this feature">
                        <i class="fas fa-times"></i>
                    </span>
                `;
                container.appendChild(tag);
            });
        }

        // 🎹 Listen for Enter key on the feature input field
        // @see https://developer.mozilla.org/en-US/docs/Web/API/KeyboardEvent/key
        document.getElementById('featureInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault(); // 🚫 Prevent form submission
                addFeatureTag();
            }
        });

        // ================================================================
        // ⚙️ FEATURE LIMITS (KEY-VALUE PAIRS)
        // ================================================================

        /**
         * ➕ Add a new feature limit key-value input row
         *
         * Appends a row to the feature limits container with fields
         * for the limit key (e.g. "api_calls") and numeric value (e.g. 1000).
         *
         * @param {string} [key='']   - Pre-filled key name
         * @param {string} [value=''] - Pre-filled numeric value
         */
        function addFeatureLimitRow(key = '', value = '') {
            const container = document.getElementById('featureLimitsContainer');
            const row = document.createElement('div');
            row.className = 'feature-limit-row';
            row.innerHTML = `
                <input type="text" class="form-control form-control-sm limit-key"
                       placeholder="Key (e.g. api_calls)" value="${escapeHtml(key)}" style="flex: 1;">
                <input type="number" class="form-control form-control-sm limit-value"
                       placeholder="Value" value="${escapeHtml(String(value))}" min="0" style="flex: 1;">
                <button class="btn btn-sm btn-outline-danger" type="button" onclick="this.parentElement.remove();"
                        title="Remove this limit">
                    <i class="fas fa-times"></i>
                </button>
            `;
            container.appendChild(row);
        }

        /**
         * 📦 Collect feature limits from all key-value rows
         *
         * Iterates over all feature limit rows, reads their key/value pairs,
         * and returns them as a plain object. Skips rows with empty keys.
         *
         * @returns {Object} Feature limits as key-value pairs (e.g. {api_calls: 1000})
         */
        function collectFeatureLimits() {
            const limits = {};
            const rows = document.querySelectorAll('.feature-limit-row');

            rows.forEach(row => {
                const key = row.querySelector('.limit-key').value.trim();
                const val = row.querySelector('.limit-value').value.trim();

                // 🛡️ Only include rows with a non-empty key
                if (key !== '') {
                    limits[key] = val !== '' ? Number(val) : 0;
                }
            });

            return limits;
        }

        // ================================================================
        // 🔗 SLUG GENERATION
        // ================================================================

        /**
         * 🔗 Auto-generate a URL-safe slug from the tier name
         *
         * Converts the tier name to lowercase, replaces spaces with hyphens,
         * and strips non-alphanumeric characters (except hyphens). Called on
         * every keystroke in the tier name input field.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/replace
         */
        function updateSlugFromName() {
            const nameInput = document.getElementById('formTierName');
            const slugInput = document.getElementById('formTierSlug');

            // 🔧 Generate slug: lowercase, spaces->hyphens, strip non-alphanumeric
            slugInput.value = nameInput.value
                .toLowerCase()
                .replace(/\s+/g, '-')
                .replace(/[^a-z0-9\-]/g, '')
                .replace(/-+/g, '-')       // Collapse consecutive hyphens
                .replace(/^-|-$/g, '');     // Trim leading/trailing hyphens
        }

        // ================================================================
        // 💱 CURRENCY SYMBOL UPDATER
        // ================================================================

        /**
         * 💱 Update the currency symbol displayed in the price input fields
         *
         * Reads the selected currency from the dropdown and updates the
         * input group text prefix for both monthly and yearly price fields.
         */
        function updateCurrencySymbol() {
            const currency = document.getElementById('formCurrency').value;
            const symbol = currencySymbols[currency] || '£';

            document.getElementById('currencySymbolDisplay').textContent = symbol;
            document.getElementById('currencySymbolDisplay2').textContent = symbol;
        }

        // ================================================================
        // 🔮 LIVE PREVIEW UPDATER
        // ================================================================

        /**
         * 🔮 Update the live preview card to reflect current form values
         *
         * Called on every input/change event in the form. Reads all form
         * fields and updates the corresponding elements in the preview card.
         * This provides immediate visual feedback as the admin fills in the form.
         */
        function updatePreview() {
            // 🏷️ Tier name
            const name = document.getElementById('formTierName').value.trim();
            document.getElementById('previewName').innerHTML = name
                ? escapeHtml(name)
                : '<span class="text-muted">Tier Name</span>';

            // 🔗 Slug
            document.getElementById('previewSlug').textContent =
                document.getElementById('formTierSlug').value.trim() || 'tier-slug';

            // 💵 Monthly price
            const monthlyPrice = parseFloat(document.getElementById('formMonthlyPrice').value) || 0;
            document.getElementById('previewMonthlyPrice').textContent = monthlyPrice.toFixed(2);

            // 💱 Currency symbol
            const currency = document.getElementById('formCurrency').value;
            const symbol = currencySymbols[currency] || '£';
            document.getElementById('previewCurrency').textContent = symbol;
            document.getElementById('previewCurrency2').textContent = symbol;

            // 📅 Yearly price + savings calculation
            const yearlyPrice = parseFloat(document.getElementById('formYearlyPrice').value) || 0;
            const yearlyContainer = document.getElementById('previewYearlyContainer');

            if (yearlyPrice > 0) {
                yearlyContainer.style.display = '';
                document.getElementById('previewYearlyPrice').textContent = yearlyPrice.toFixed(2);

                // 📊 Calculate savings vs monthly * 12
                const monthlyTotal = monthlyPrice * 12;
                const savingsEl = document.getElementById('previewSavings');
                if (monthlyTotal > 0 && yearlyPrice < monthlyTotal) {
                    const savings = Math.round(100 - (yearlyPrice / monthlyTotal * 100));
                    savingsEl.textContent = `(Save ${savings}%)`;
                } else {
                    savingsEl.textContent = '';
                }
            } else {
                yearlyContainer.style.display = 'none';
            }

            // 🎁 Trial days
            const trialDays = parseInt(document.getElementById('formTrialDays').value) || 0;
            const trialContainer = document.getElementById('previewTrialContainer');
            if (trialDays > 0) {
                trialContainer.style.display = '';
                document.getElementById('previewTrialDays').textContent = trialDays;
            } else {
                trialContainer.style.display = 'none';
            }

            // 📝 Description
            const description = document.getElementById('formTierDescription').value.trim();
            const descEl = document.getElementById('previewDescription');
            if (description) {
                descEl.style.display = '';
                descEl.textContent = description;
            } else {
                descEl.style.display = 'none';
            }

            // 🏷️ Badge
            const badgeText = document.getElementById('formBadge').value.trim();
            const badgeColor = document.getElementById('formBadgeColor').value;
            const badgeEl = document.getElementById('previewBadge');

            if (badgeText && badgeColor) {
                badgeEl.style.display = '';
                badgeEl.className = `badge bg-${badgeColor}`;
                badgeEl.textContent = badgeText;
            } else {
                badgeEl.style.display = 'none';
            }

            // ✅ Features list
            const featuresEl = document.getElementById('previewFeatures');
            if (currentFeatures.length > 0) {
                featuresEl.innerHTML = currentFeatures.map(f =>
                    `<li><i class="fas fa-check text-success me-2"></i>${escapeHtml(f)}</li>`
                ).join('');
            } else {
                featuresEl.innerHTML = '<li class="text-muted"><i class="fas fa-info-circle me-2"></i>Add features to see them here</li>';
            }
        }

        // ================================================================
        // 🛡️ HTML ESCAPING UTILITY
        // ================================================================

        /**
         * 🛡️ Escape HTML special characters to prevent XSS
         *
         * Replaces &, <, >, ", and ' with their HTML entity equivalents.
         * Used throughout the JS to safely render user-provided text.
         *
         * @param {string} text - The raw text to escape
         * @returns {string} The escaped HTML-safe string
         *
         * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
         */
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }

        // ================================================================
        // 📝 MODAL MANAGEMENT
        // ================================================================

        /**
         * ➕ Open the modal in "Create" mode
         *
         * Resets all form fields to their default/empty values, clears
         * feature tags and limits, updates the modal title, and shows the modal.
         */
        function openCreateModal() {
            // 🏷️ Update modal title and save button text
            document.getElementById('modalTitleText').textContent = 'Create New Tier';
            document.getElementById('saveBtnText').textContent = 'Create Tier';

            // 🧹 Clear all form fields
            document.getElementById('formTierID').value = '';
            document.getElementById('formTierName').value = '';
            document.getElementById('formTierSlug').value = '';
            document.getElementById('formTierDescription').value = '';
            document.getElementById('formMonthlyPrice').value = '0.00';
            document.getElementById('formYearlyPrice').value = '0.00';
            document.getElementById('formCurrency').value = 'GBP';
            document.getElementById('formDisplayOrder').value = '0';
            document.getElementById('formTrialDays').value = '0';
            document.getElementById('formBadge').value = '';
            document.getElementById('formBadgeColor').value = '';
            document.getElementById('formIsDefault').checked = false;

            // 🧹 Reset feature tags and limits
            currentFeatures = [];
            renderFeatureTags();
            document.getElementById('featureLimitsContainer').innerHTML = '';

            // 💱 Reset currency symbol display
            updateCurrencySymbol();

            // 🔮 Update preview to blank state
            updatePreview();
        }

        /**
         * ✏️ Open the modal in "Edit" mode for a specific tier
         *
         * Fetches the tier data from the API, populates all form fields
         * with the existing values, and shows the modal in edit mode.
         *
         * @param {number} tierID - The ID of the tier to edit
         */
        async function openEditModal(tierID) {
            try {
                // 📡 Fetch the tier data from the API endpoint
                const response = await fetch(`../api/tier-actions.php?action=get_tier&tierID=${tierID}&partnerID=${partnerID}`);
                const result = await response.json();

                if (!result.success) {
                    showAlert(result.message || 'Failed to load tier data.', 'danger');
                    return;
                }

                const tier = result.data;

                // 🏷️ Update modal title and save button text
                document.getElementById('modalTitleText').textContent = 'Edit Tier: ' + tier.tierName;
                document.getElementById('saveBtnText').textContent = 'Save Changes';

                // 📝 Populate form fields with existing tier data
                document.getElementById('formTierID').value = tier.tierID;
                document.getElementById('formTierName').value = tier.tierName || '';
                document.getElementById('formTierSlug').value = tier.tierSlug || '';
                document.getElementById('formTierDescription').value = tier.tierDescription || '';
                document.getElementById('formMonthlyPrice').value = parseFloat(tier.monthlyPrice || 0).toFixed(2);
                document.getElementById('formYearlyPrice').value = parseFloat(tier.yearlyPrice || 0).toFixed(2);
                document.getElementById('formCurrency').value = tier.currency || 'GBP';
                document.getElementById('formDisplayOrder').value = tier.displayOrder || 0;
                document.getElementById('formTrialDays').value = tier.trialDays || 0;
                document.getElementById('formBadge').value = tier.badge || '';
                document.getElementById('formBadgeColor').value = tier.badgeColor || '';
                document.getElementById('formIsDefault').checked = !!tier.isDefault;

                // 🏷️ Populate feature tags
                currentFeatures = Array.isArray(tier.features) ? [...tier.features] : [];
                renderFeatureTags();

                // ⚙️ Populate feature limit rows
                document.getElementById('featureLimitsContainer').innerHTML = '';
                if (tier.featureLimits && typeof tier.featureLimits === 'object') {
                    Object.entries(tier.featureLimits).forEach(([key, value]) => {
                        addFeatureLimitRow(key, value);
                    });
                }

                // 💱 Update currency symbol display
                updateCurrencySymbol();

                // 🔮 Update live preview with populated data
                updatePreview();

                // 📺 Show the modal
                tierModal.show();

            } catch (error) {
                console.error('Error loading tier:', error);
                showAlert('Error loading tier data. Please try again.', 'danger');
            }
        }

        // ================================================================
        // 💾 SAVE / CREATE / UPDATE TIER
        // ================================================================

        /**
         * 💾 Save the tier form (create new or update existing)
         *
         * Collects all form field values, validates required fields,
         * sends a POST request to the tier-actions.php API endpoint,
         * and handles the response (success: reload page, error: show alert).
         */
        async function saveTier() {
            // 📝 Collect form data
            const tierID = document.getElementById('formTierID').value;
            const tierName = document.getElementById('formTierName').value.trim();
            const tierSlug = document.getElementById('formTierSlug').value.trim();
            const tierDescription = document.getElementById('formTierDescription').value.trim();
            const monthlyPrice = parseFloat(document.getElementById('formMonthlyPrice').value) || 0;
            const yearlyPrice = parseFloat(document.getElementById('formYearlyPrice').value) || 0;
            const currency = document.getElementById('formCurrency').value;
            const displayOrder = parseInt(document.getElementById('formDisplayOrder').value) || 0;
            const trialDays = parseInt(document.getElementById('formTrialDays').value) || 0;
            const badge = document.getElementById('formBadge').value.trim();
            const badgeColor = document.getElementById('formBadgeColor').value;
            const isDefault = document.getElementById('formIsDefault').checked;

            // 🛡️ Client-side validation
            if (!tierName) {
                showAlert('Tier name is required.', 'warning');
                document.getElementById('formTierName').focus();
                return;
            }

            if (!tierSlug) {
                showAlert('Tier slug is required.', 'warning');
                document.getElementById('formTierSlug').focus();
                return;
            }

            // 🔒 Validate slug format (lowercase, alphanumeric, hyphens only)
            if (!/^[a-z0-9\-]+$/.test(tierSlug)) {
                showAlert('Tier slug must contain only lowercase letters, numbers, and hyphens.', 'warning');
                document.getElementById('formTierSlug').focus();
                return;
            }

            // ⚙️ Collect feature limits from key-value rows
            const featureLimits = collectFeatureLimits();

            // 📦 Build the request payload
            const payload = {
                action: tierID ? 'update_tier' : 'create_tier',
                partnerID: partnerID,
                csrf_token: csrfToken,
                tierData: {
                    tierName: tierName,
                    tierSlug: tierSlug,
                    tierDescription: tierDescription,
                    monthlyPrice: monthlyPrice,
                    yearlyPrice: yearlyPrice,
                    currency: currency,
                    features: currentFeatures,
                    featureLimits: Object.keys(featureLimits).length > 0 ? featureLimits : null,
                    displayOrder: displayOrder,
                    trialDays: trialDays,
                    badge: badge || null,
                    badgeColor: badgeColor || null,
                    isDefault: isDefault
                }
            };

            // 📎 Include tierID for update operations
            if (tierID) {
                payload.tierID = parseInt(tierID);
            }

            // 🔄 Show loading state on save button
            const saveBtn = document.getElementById('saveTierBtn');
            const originalBtnHTML = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';

            try {
                // 📡 Send the request to the API endpoint
                const response = await fetch('../api/tier-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const result = await response.json();

                if (result.success) {
                    // ✅ Success — hide modal and reload to show updated data
                    tierModal.hide();
                    showAlert(result.message || 'Tier saved successfully!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    // ❌ API returned an error
                    showAlert(result.message || 'Failed to save tier.', 'danger');
                }
            } catch (error) {
                console.error('Error saving tier:', error);
                showAlert('Error saving tier. Please check your connection and try again.', 'danger');
            } finally {
                // 🔄 Restore save button to its original state
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalBtnHTML;
            }
        }

        // ================================================================
        // 🔀 ACTIVATE / DEACTIVATE TIER
        // ================================================================

        /**
         * 🔀 Toggle a tier's active/inactive status
         *
         * Prompts for confirmation, then sends a request to activate
         * or deactivate (soft-delete) the specified tier.
         *
         * @param {number}  tierID   - The ID of the tier to toggle
         * @param {string}  tierName - The tier name (for confirmation dialog)
         * @param {boolean} activate - true to activate, false to deactivate
         */
        async function toggleTierStatus(tierID, tierName, activate) {
            // 🛡️ Confirm the action with the user
            const actionVerb = activate ? 'activate' : 'deactivate';
            if (!confirm(`Are you sure you want to ${actionVerb} the "${tierName}" tier?\n\n${activate
                ? 'This will make the tier visible to your customers again.'
                : 'Deactivating will hide this tier from new customers, but existing subscriptions will not be affected.'}`)) {
                return;
            }

            try {
                // 📡 Send the toggle request to the API
                const response = await fetch('../api/tier-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: activate ? 'activate_tier' : 'delete_tier',
                        tierID: tierID,
                        partnerID: partnerID,
                        csrf_token: csrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(result.message || `Tier ${actionVerb}d successfully!`, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(result.message || `Failed to ${actionVerb} tier.`, 'danger');
                }
            } catch (error) {
                console.error(`Error ${actionVerb}ing tier:`, error);
                showAlert(`Error ${actionVerb}ing tier. Please try again.`, 'danger');
            }
        }

        // ================================================================
        // ⭐ SET DEFAULT TIER
        // ================================================================

        /**
         * ⭐ Set a tier as the default for new subscribers
         *
         * Prompts for confirmation, then sends a request to mark the
         * specified tier as the default. Only one tier can be default
         * at a time — the API will unset any existing default.
         *
         * @param {number} tierID   - The ID of the tier to set as default
         * @param {string} tierName - The tier name (for confirmation dialog)
         */
        async function setDefaultTier(tierID, tierName) {
            // 🛡️ Confirm the action
            if (!confirm(`Set "${tierName}" as the default tier?\n\nNew subscribers will be automatically assigned to this tier. Any existing default tier will be unset.`)) {
                return;
            }

            try {
                // 📡 Send the set-default request to the API
                const response = await fetch('../api/tier-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'set_default',
                        tierID: tierID,
                        partnerID: partnerID,
                        csrf_token: csrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(result.message || `"${tierName}" is now the default tier!`, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(result.message || 'Failed to set default tier.', 'danger');
                }
            } catch (error) {
                console.error('Error setting default tier:', error);
                showAlert('Error setting default tier. Please try again.', 'danger');
            }
        }
    </script>
</body>
</html>
