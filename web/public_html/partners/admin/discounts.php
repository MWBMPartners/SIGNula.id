<?php
/**
 * ============================================================================
 * 🏷️ SIGNula - Partner Discount & Promo Code Management
 * ============================================================================
 *
 * Purpose: Admin UI for creating, editing, and managing discount/promo codes
 *          for a partner organization. Supports both universal codes (anyone
 *          can use) and assigned codes (specific users only). Includes country
 *          restrictions, tier applicability, provider-specific rules, and
 *          min purchase requirements.
 *
 * Features:
 *   - Create/edit discount codes with percentage or fixed amount discounts
 *   - Universal codes (available to all) and assigned codes (user-specific)
 *   - Date-range validity (validFrom / validTo) with optional max uses
 *   - Country allow/block lists (ISO 3166-1 alpha-2 codes stored as JSON)
 *   - Multi-select applicable tiers from the partner's subscription tiers
 *   - Payment provider restrictions (Stripe, PayPal, Coinbase, or All)
 *   - Minimum purchase amount threshold
 *   - User assignment modal: search by email, assign, and remove assignments
 *   - AJAX-driven DataTable with real-time status badges
 *   - Copy-to-clipboard for discount codes
 *   - Auto-generate random 8-character alphanumeric codes
 *
 * Database Tables:
 *   - tblDiscountCodes       — Stores discount code definitions (per partner)
 *   - tblDiscountCodeAssignments — Maps assigned codes to specific users
 *   - tblSubscriptionTiers   — Reference for applicable tier multi-select
 *
 * Authentication: Partner admin role required (isPartnerAdmin check)
 * CSRF Protection: Token generated via SecurityUtils::generateCSRFToken()
 *
 * PHP Version: 8.3+
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Partners\Admin\Discounts
 * @version    1.0.0
 * @since      2.4.0-beta
 * @link       https://SIGNula.id
 * @see        https://getbootstrap.com/docs/5.3/getting-started/introduction/ (Bootstrap 5.3)
 * @see        https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php (MySQLi Prepared Statements)
 * ============================================================================
 */

// ============================================================================
// 🚀 BOOTSTRAP — Load core system files
// ============================================================================

// ⚙️ Main configuration (defines SIGNULA_INIT, loads settings, starts session)
// @see web/_config/config.php — Central configuration loader
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton (MySQLi prepared statements)
// @see web/_config/database.php — Database abstraction layer
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🎫 Session manager (authentication state management)
// @see web/_backend/SessionManager.php — Handles session lifecycle
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

// 🔐 Check authentication — user must be logged in
// Unauthenticated users are redirected to the login page
// @see https://www.php.net/manual/en/function.header.php
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// ============================================================================
// 🏢 PARTNER SELECTION
// ============================================================================

// 📋 Get user's partner memberships — returns array of partner orgs they belong to
// @see AccessControl::getPartnerMemberships() — Returns [partnerID => membershipData]
$memberships = $accessControl->getPartnerMemberships();

// 🚫 If user has no partner memberships, redirect to registration
if (empty($memberships)) {
    header('Location: /partners/register.php');
    exit;
}

// 🔄 Default to first partner or use the one specified in the URL query string
// @see https://www.php.net/manual/en/function.array-key-first.php (PHP 7.3+)
$selectedPartnerID = $_GET['partner'] ?? array_key_first($memberships);
$partner = $memberships[$selectedPartnerID] ?? reset($memberships);

// ============================================================================
// 🛡️ ADMIN ACCESS VERIFICATION
// ============================================================================

// 🔐 Verify the current user has admin access to this specific partner org
// Non-admins receive a 403 Forbidden response
if (!$accessControl->isPartnerAdmin($selectedPartnerID)) {
    http_response_code(403);
    die('Admin access required for this partner');
}

// ============================================================================
// 📊 DATA LOADING — Partner details & subscription tiers
// ============================================================================

// 🏢 Get partner details for display in the page header
// @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE partnerID = ?");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerDetails = $stmt->get_result()->fetch_assoc();

// 📦 Get available subscription tiers for the "Applicable Tiers" multi-select
// Only active tiers are shown, sorted by display order
// @see _database/migrations/010_webhooks_and_payments.sql — tblSubscriptionTiers definition
$stmt = $db->prepare("
    SELECT tierID, tierName, tierSlug
    FROM tblSubscriptionTiers
    WHERE isActive = 1
    ORDER BY displayOrder ASC
");
$stmt->execute();
$subscriptionTiers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 📊 Get quick stats — total discount codes and active codes for this partner
$stmt = $db->prepare("
    SELECT
        COUNT(*) AS totalCodes,
        SUM(CASE WHEN isActive = 1 AND (validTo IS NULL OR validTo >= CURDATE()) THEN 1 ELSE 0 END) AS activeCodes
    FROM tblDiscountCodes
    WHERE partnerID = ?
");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$discountStats = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ================================================================== -->
    <!-- 📄 Meta Tags & Page Title                                          -->
    <!-- ================================================================== -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Discount & Promo Codes - <?php echo htmlspecialchars($partner['companyName']); ?> - SIGNula</title>

    <!-- ================================================================== -->
    <!-- 📦 External CSS — Bootstrap 5.3.2 & FontAwesome 6.4.2 via CDN     -->
    <!-- SRI hashes for subresource integrity                               -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/download/   -->
    <!-- @see https://fontawesome.com/docs/web/setup/get-started            -->
    <!-- ================================================================== -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <!-- ================================================================== -->
    <!-- 🎨 Page-Specific CSS                                               -->
    <!-- ================================================================== -->
    <style>
        /* 🎨 Base body background — light grey to differentiate from cards */
        body {
            background-color: #f8f9fa;
        }

        /* 🎨 Page header — gradient with the discount/promo theme (teal-green) */
        .discounts-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        /* 📊 Stat cards — subtle shadow with border accent */
        .stat-card {
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        /* 📋 DataTable container — white background with rounded corners */
        .discount-table-card {
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        /* 🏷️ Status badges — consistent sizing */
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* 🏷️ Code display — monospace for discount codes */
        .discount-code {
            font-family: 'Courier New', Courier, monospace;
            font-weight: 700;
            font-size: 0.95rem;
            letter-spacing: 1px;
        }

        /* 📋 Copy button hover effect */
        .btn-copy:hover {
            color: #198754;
        }

        /* 🏷️ Tag input container for country codes */
        .tag-input-container {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 0.375rem;
            min-height: 42px;
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            cursor: text;
            background: #fff;
        }
        .tag-input-container:focus-within {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        .tag-input-container .tag {
            background: #0d6efd;
            color: white;
            border-radius: 4px;
            padding: 2px 8px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .tag-input-container .tag .tag-remove {
            cursor: pointer;
            font-weight: bold;
            opacity: 0.7;
        }
        .tag-input-container .tag .tag-remove:hover {
            opacity: 1;
        }
        .tag-input-container input {
            border: none;
            outline: none;
            flex: 1;
            min-width: 60px;
            font-size: 0.9rem;
            padding: 2px 4px;
        }

        /* 🔄 Loading spinner overlay for modals */
        .modal-loading {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(255, 255, 255, 0.85);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            border-radius: 0.5rem;
        }

        /* 👤 Assignment list items */
        .assignment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid #e9ecef;
            transition: background-color 0.15s;
        }
        .assignment-item:hover {
            background-color: #f8f9fa;
        }
        .assignment-item:last-child {
            border-bottom: none;
        }

        /* 📱 Responsive table adjustments */
        @media (max-width: 768px) {
            .discounts-header h1 {
                font-size: 1.5rem;
            }
            .btn-group-sm .btn {
                padding: 0.25rem 0.4rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">

        <!-- ============================================================== -->
        <!-- 🏷️ PAGE HEADER — Gradient banner with back link and title      -->
        <!-- ============================================================== -->
        <div class="discounts-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <!-- 🔙 Back navigation to admin dashboard -->
                    <a href="index.php?partner=<?php echo (int) $selectedPartnerID; ?>"
                       class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                        <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
                    </a>
                    <h1><i class="fas fa-tags me-2"></i>Discount &amp; Promo Codes</h1>
                    <p class="mb-0 opacity-75"><?php echo htmlspecialchars($partner['companyName']); ?></p>
                </div>
                <div class="col-md-4 text-end">
                    <!-- 📊 Quick stats display -->
                    <div style="background: rgba(255,255,255,0.2); border-radius: 10px; padding: 1rem;">
                        <h3 class="mb-0"><?php echo (int) ($discountStats['activeCodes'] ?? 0); ?></h3>
                        <small>Active Codes</small>
                        <span class="d-block mt-1 opacity-75">
                            <small><?php echo (int) ($discountStats['totalCodes'] ?? 0); ?> total</small>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- 🚨 ALERT CONTAINER — Dynamic alerts injected via JavaScript    -->
        <!-- ============================================================== -->
        <div id="alertContainer"></div>

        <!-- ============================================================== -->
        <!-- ➕ ACTION BAR — Create Discount Code button                    -->
        <!-- ============================================================== -->
        <div class="row mb-4">
            <div class="col-md-12">
                <button class="btn btn-primary btn-lg"
                        data-bs-toggle="modal"
                        data-bs-target="#discountModal"
                        onclick="openCreateModal()">
                    <i class="fas fa-plus-circle me-2"></i>Create Discount Code
                </button>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- 📋 DISCOUNT CODES TABLE — AJAX-loaded data with status badges  -->
        <!-- ============================================================== -->
        <div class="card discount-table-card mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Discount Codes</h5>
                <!-- 🔄 Manual refresh button -->
                <button class="btn btn-sm btn-outline-secondary" onclick="loadDiscounts()" title="Refresh">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
            <div class="card-body">
                <!-- 📊 Table with responsive wrapper for small screens -->
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="discountsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Type</th>
                                <th>Discount</th>
                                <th>Valid From</th>
                                <th>Valid To</th>
                                <th>Uses / Max</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="discountsTableBody">
                            <!-- 🔄 Rows loaded dynamically via AJAX (loadDiscounts()) -->
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="fas fa-spinner fa-spin me-2"></i>Loading discount codes...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- ℹ️ HELP CARD — Contextual guidance for admins                  -->
        <!-- ============================================================== -->
        <div class="card">
            <div class="card-body">
                <h6 class="mb-3"><i class="fas fa-question-circle me-2"></i>About Discount Codes</h6>
                <div class="row">
                    <div class="col-md-4">
                        <h6><i class="fas fa-globe me-2 text-primary"></i>Universal Codes</h6>
                        <p class="text-muted small">
                            Universal codes can be used by anyone with the code. Share these publicly
                            for promotions, marketing campaigns, and general offers.
                        </p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-user-tag me-2 text-success"></i>Assigned Codes</h6>
                        <p class="text-muted small">
                            Assigned codes are restricted to specific users. Use the "Manage Assignments"
                            button to assign codes to individual users by their email address.
                        </p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-earth-americas me-2 text-info"></i>Country Restrictions</h6>
                        <p class="text-muted small">
                            Use ISO 3166-1 alpha-2 country codes (e.g., GB, US, DE, FR) to restrict
                            where codes can be used. Leave both lists empty for no restrictions.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.container-fluid -->


    <!-- ================================================================== -->
    <!-- 🏗️ MODAL: Create / Edit Discount Code                             -->
    <!-- Large modal with tabbed sections for all discount code fields      -->
    <!-- ================================================================== -->
    <div class="modal fade" id="discountModal" tabindex="-1" aria-labelledby="discountModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <!-- 🏷️ Modal Header -->
                <div class="modal-header">
                    <h5 class="modal-title" id="discountModalLabel">
                        <i class="fas fa-tag me-2"></i>Create Discount Code
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- 📝 Modal Body — Discount code form -->
                <div class="modal-body position-relative">
                    <!-- 🆔 Hidden field for edit mode (stores codeID when editing) -->
                    <input type="hidden" id="editCodeID" value="">

                    <form id="discountForm" novalidate>
                        <!-- ======================================== -->
                        <!-- 📝 SECTION: Code & Basic Settings        -->
                        <!-- ======================================== -->
                        <h6 class="text-muted mb-3 border-bottom pb-2">
                            <i class="fas fa-barcode me-1"></i> Code & Basic Settings
                        </h6>

                        <!-- 🏷️ Discount Code + Auto-Generate Button -->
                        <div class="mb-3">
                            <label for="discountCode" class="form-label fw-semibold">
                                Discount Code <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="text"
                                       class="form-control discount-code"
                                       id="discountCode"
                                       placeholder="e.g. SAVE20"
                                       maxlength="50"
                                       required
                                       style="text-transform: uppercase;">
                                <button class="btn btn-outline-secondary" type="button" onclick="generateCode()">
                                    <i class="fas fa-random me-1"></i>Auto-Generate
                                </button>
                            </div>
                            <small class="text-muted">Alphanumeric characters only. Will be stored in uppercase.</small>
                        </div>

                        <!-- 🔀 Code Type — Universal or Assigned -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="codeType" class="form-label fw-semibold">
                                    Code Type <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="codeType" required>
                                    <option value="universal">Universal (anyone can use)</option>
                                    <option value="assigned">Assigned (specific users only)</option>
                                </select>
                                <small class="text-muted">Assigned codes require user-specific assignments.</small>
                            </div>

                            <!-- 💰 Discount Type — Percentage or Fixed -->
                            <div class="col-md-6">
                                <label for="discountType" class="form-label fw-semibold">
                                    Discount Type <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="discountType" required>
                                    <option value="percentage">Percentage (%)</option>
                                    <option value="fixed">Fixed Amount</option>
                                </select>
                            </div>
                        </div>

                        <!-- 💲 Discount Value -->
                        <div class="mb-3">
                            <label for="discountValue" class="form-label fw-semibold">
                                Discount Value <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text" id="discountValuePrefix">%</span>
                                <input type="number"
                                       class="form-control"
                                       id="discountValue"
                                       min="0"
                                       step="0.01"
                                       placeholder="e.g. 20"
                                       required>
                            </div>
                            <small class="text-muted" id="discountValueHelp">
                                Enter a value between 0 and 100 for percentages.
                            </small>
                        </div>

                        <!-- ======================================== -->
                        <!-- 📅 SECTION: Validity Period               -->
                        <!-- ======================================== -->
                        <h6 class="text-muted mb-3 mt-4 border-bottom pb-2">
                            <i class="fas fa-calendar-alt me-1"></i> Validity Period
                        </h6>

                        <div class="row mb-3">
                            <!-- 📅 Valid From -->
                            <div class="col-md-6">
                                <label for="validFrom" class="form-label fw-semibold">
                                    Valid From <span class="text-danger">*</span>
                                </label>
                                <input type="date" class="form-control" id="validFrom" required>
                            </div>

                            <!-- 📅 Valid To -->
                            <div class="col-md-6">
                                <label for="validTo" class="form-label fw-semibold">
                                    Valid To
                                </label>
                                <input type="date" class="form-control" id="validTo">
                                <small class="text-muted">Leave empty for no expiry.</small>
                            </div>
                        </div>

                        <!-- 🔢 Max Uses -->
                        <div class="mb-3">
                            <label for="maxUses" class="form-label fw-semibold">
                                Maximum Uses
                            </label>
                            <input type="number"
                                   class="form-control"
                                   id="maxUses"
                                   min="0"
                                   value="0"
                                   placeholder="0 = Unlimited">
                            <small class="text-muted">Set to 0 for unlimited uses.</small>
                        </div>

                        <!-- 💷 Minimum Purchase Amount -->
                        <div class="mb-3">
                            <label for="minPurchase" class="form-label fw-semibold">
                                Minimum Purchase Amount
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&pound;</span>
                                <input type="number"
                                       class="form-control"
                                       id="minPurchase"
                                       min="0"
                                       step="0.01"
                                       value="0"
                                       placeholder="0.00">
                            </div>
                            <small class="text-muted">Set to 0 for no minimum purchase requirement.</small>
                        </div>

                        <!-- ======================================== -->
                        <!-- 📦 SECTION: Tier & Provider Restrictions  -->
                        <!-- ======================================== -->
                        <h6 class="text-muted mb-3 mt-4 border-bottom pb-2">
                            <i class="fas fa-filter me-1"></i> Tier & Provider Restrictions
                        </h6>

                        <!-- 📦 Applicable Tiers — Multi-select from subscription tiers -->
                        <div class="mb-3">
                            <label for="applicableTiers" class="form-label fw-semibold">
                                Applicable Tiers
                            </label>
                            <select class="form-select" id="applicableTiers" multiple size="4">
                                <?php foreach ($subscriptionTiers as $tier): ?>
                                <option value="<?php echo (int) $tier['tierID']; ?>">
                                    <?php echo htmlspecialchars($tier['tierName']); ?>
                                    (<?php echo htmlspecialchars($tier['tierSlug']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">
                                Hold Ctrl/Cmd to select multiple. Leave empty to apply to all tiers.
                            </small>
                        </div>

                        <!-- 💳 Applicable Providers — Checkboxes -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Applicable Payment Providers</label>
                            <div class="row">
                                <div class="col-auto">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                               id="providerAll" value="all" checked
                                               onchange="toggleProviderAll(this)">
                                        <label class="form-check-label" for="providerAll">
                                            <i class="fas fa-check-double me-1"></i>All Providers
                                        </label>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="form-check">
                                        <input class="form-check-input provider-checkbox" type="checkbox"
                                               id="providerStripe" value="stripe"
                                               onchange="updateProviderAll()">
                                        <label class="form-check-label" for="providerStripe">
                                            <i class="fab fa-stripe me-1"></i>Stripe
                                        </label>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="form-check">
                                        <input class="form-check-input provider-checkbox" type="checkbox"
                                               id="providerPaypal" value="paypal"
                                               onchange="updateProviderAll()">
                                        <label class="form-check-label" for="providerPaypal">
                                            <i class="fab fa-paypal me-1"></i>PayPal
                                        </label>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="form-check">
                                        <input class="form-check-input provider-checkbox" type="checkbox"
                                               id="providerCoinbase" value="coinbase"
                                               onchange="updateProviderAll()">
                                        <label class="form-check-label" for="providerCoinbase">
                                            <i class="fab fa-bitcoin me-1"></i>Coinbase
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <small class="text-muted">Select specific providers or "All" for universal acceptance.</small>
                        </div>

                        <!-- ======================================== -->
                        <!-- 🌍 SECTION: Country Restrictions          -->
                        <!-- ======================================== -->
                        <h6 class="text-muted mb-3 mt-4 border-bottom pb-2">
                            <i class="fas fa-earth-americas me-1"></i> Country Restrictions
                        </h6>

                        <!-- ✅ Allowed Countries — Tag input -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Allowed Countries
                            </label>
                            <div class="tag-input-container" id="allowedCountriesContainer" onclick="this.querySelector('input').focus()">
                                <input type="text"
                                       id="allowedCountriesInput"
                                       placeholder="Type ISO code (e.g. GB) and press Enter"
                                       maxlength="2"
                                       style="text-transform: uppercase;">
                            </div>
                            <small class="text-muted">
                                ISO 3166-1 alpha-2 codes. Leave empty to allow all countries.
                            </small>
                        </div>

                        <!-- 🚫 Blocked Countries — Tag input -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Blocked Countries
                            </label>
                            <div class="tag-input-container" id="blockedCountriesContainer" onclick="this.querySelector('input').focus()">
                                <input type="text"
                                       id="blockedCountriesInput"
                                       placeholder="Type ISO code (e.g. RU) and press Enter"
                                       maxlength="2"
                                       style="text-transform: uppercase;">
                            </div>
                            <small class="text-muted">
                                ISO 3166-1 alpha-2 codes. Leave empty to block no countries.
                            </small>
                        </div>

                    </form>
                </div>

                <!-- 🔘 Modal Footer — Action buttons -->
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-primary" onclick="saveDiscount()" id="saveDiscountBtn">
                        <i class="fas fa-save me-2"></i>Save Discount Code
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ================================================================== -->
    <!-- 👤 MODAL: Manage Assignments (for assigned-type codes)             -->
    <!-- Shows current assignments and allows adding/removing users         -->
    <!-- ================================================================== -->
    <div class="modal fade" id="assignmentsModal" tabindex="-1" aria-labelledby="assignmentsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <!-- 🏷️ Modal Header -->
                <div class="modal-header">
                    <h5 class="modal-title" id="assignmentsModalLabel">
                        <i class="fas fa-user-tag me-2"></i>Manage Assignments
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- 📝 Modal Body -->
                <div class="modal-body position-relative">
                    <!-- 🆔 Hidden field to track which code we're managing -->
                    <input type="hidden" id="assignmentCodeID" value="">

                    <!-- 🏷️ Code info display -->
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Managing assignments for code: <strong id="assignmentCodeName" class="discount-code"></strong>
                    </div>

                    <!-- ➕ Assign to User form -->
                    <div class="card mb-3">
                        <div class="card-header bg-white">
                            <h6 class="mb-0"><i class="fas fa-user-plus me-2"></i>Assign to User</h6>
                        </div>
                        <div class="card-body">
                            <div class="input-group">
                                <input type="email"
                                       class="form-control"
                                       id="assignUserEmail"
                                       placeholder="Enter user email address"
                                       aria-label="User email for assignment">
                                <button class="btn btn-primary" type="button" onclick="assignCode()">
                                    <i class="fas fa-plus me-1"></i>Assign
                                </button>
                            </div>
                            <small class="text-muted">
                                The user must have an existing SIGNula account.
                            </small>
                        </div>
                    </div>

                    <!-- 📋 Current Assignments List -->
                    <div class="card">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="fas fa-users me-2"></i>Current Assignments</h6>
                            <span class="badge bg-primary" id="assignmentCount">0</span>
                        </div>
                        <div class="card-body p-0" id="assignmentsList">
                            <!-- 🔄 Assignments loaded dynamically via AJAX -->
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-spinner fa-spin me-2"></i>Loading assignments...
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 🔘 Modal Footer -->
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ================================================================== -->
    <!-- 📦 External JS — Bootstrap 5.3.2 Bundle (includes Popper.js)      -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/download/   -->
    <!-- ================================================================== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- ================================================================== -->
    <!-- 🔐 CSRF Token — Generated server-side for secure AJAX requests    -->
    <!-- @see SecurityUtils::generateCSRFToken() — Returns a unique token   -->
    <!-- ================================================================== -->
    <script>const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';</script>

    <!-- ================================================================== -->
    <!-- 📜 PAGE JAVASCRIPT — All discount management logic                 -->
    <!-- ================================================================== -->
    <script>
        // ============================================================
        // 🔧 GLOBAL CONSTANTS & STATE
        // ============================================================

        /** @type {number} The currently selected partner's ID */
        const partnerID = <?php echo (int) $selectedPartnerID; ?>;

        /** @type {string} Base URL for the discount actions API */
        const apiURL = '../api/discount-actions.php';

        /** @type {bootstrap.Modal} Bootstrap modal instance for create/edit */
        const discountModal = new bootstrap.Modal(document.getElementById('discountModal'));

        /** @type {bootstrap.Modal} Bootstrap modal instance for assignments */
        const assignmentsModal = new bootstrap.Modal(document.getElementById('assignmentsModal'));

        /** @type {Array<string>} Allowed countries tag list (ISO alpha-2 codes) */
        let allowedCountriesTags = [];

        /** @type {Array<string>} Blocked countries tag list (ISO alpha-2 codes) */
        let blockedCountriesTags = [];


        // ============================================================
        // 🚀 INITIALIZATION — Load data on page load
        // ============================================================

        /**
         * 📋 Load discount codes when the DOM is fully ready
         */
        document.addEventListener('DOMContentLoaded', function() {
            // 🔄 Load all discount codes into the table
            loadDiscounts();

            // 📅 Set default validFrom to today's date
            // @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date/toISOString
            document.getElementById('validFrom').value = new Date().toISOString().split('T')[0];

            // 🔀 Listen for discount type changes to update the value prefix
            document.getElementById('discountType').addEventListener('change', updateDiscountPrefix);

            // 🏷️ Initialize tag input event listeners for country codes
            initTagInput('allowedCountriesInput', 'allowedCountriesContainer', allowedCountriesTags);
            initTagInput('blockedCountriesInput', 'blockedCountriesContainer', blockedCountriesTags);
        });


        // ============================================================
        // 🚨 UTILITY: Alert Messages
        // ============================================================

        /**
         * 🚨 Display a dismissible Bootstrap alert at the top of the page
         *
         * @param {string} message  - Alert text content (HTML supported)
         * @param {string} type     - Bootstrap alert type (success, danger, warning, info)
         */
        function showAlert(message, type = 'info') {
            const alertEl = document.createElement('div');
            alertEl.className = `alert alert-${type} alert-dismissible fade show`;
            alertEl.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            document.getElementById('alertContainer').appendChild(alertEl);

            // ⏰ Auto-dismiss after 5 seconds
            setTimeout(() => {
                if (alertEl.parentNode) {
                    alertEl.remove();
                }
            }, 5000);
        }


        // ============================================================
        // 📋 DISCOUNT TABLE: Load & Render
        // ============================================================

        /**
         * 📋 Load all discount codes from the API and render them in the table
         *
         * Makes a GET request to discount-actions.php?action=get_discounts
         * and populates the #discountsTableBody with formatted rows.
         *
         * @returns {Promise<void>}
         */
        async function loadDiscounts() {
            const tbody = document.getElementById('discountsTableBody');

            // 🔄 Show loading state
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        <i class="fas fa-spinner fa-spin me-2"></i>Loading discount codes...
                    </td>
                </tr>
            `;

            try {
                // 📡 Fetch discount codes from the API
                const response = await fetch(`${apiURL}?action=get_discounts&partnerID=${partnerID}`);
                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.message || 'Failed to load discount codes');
                }

                const discounts = result.discounts || [];

                // 📭 Handle empty state
                if (discounts.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <i class="fas fa-tags fa-3x text-muted mb-3 d-block"></i>
                                <h5 class="text-muted">No Discount Codes Yet</h5>
                                <p class="text-muted">
                                    Create your first discount code to get started.
                                </p>
                            </td>
                        </tr>
                    `;
                    return;
                }

                // 🏗️ Build table rows for each discount code
                let html = '';
                discounts.forEach(function(discount) {
                    // 🏷️ Determine status badge based on current state
                    const statusBadge = getStatusBadge(discount);

                    // 💰 Format discount value display
                    const discountDisplay = discount.discountType === 'percentage'
                        ? `${parseFloat(discount.discountValue).toFixed(1)}%`
                        : `&pound;${parseFloat(discount.discountValue).toFixed(2)}`;

                    // 🔀 Code type badge
                    const typeBadge = discount.codeType === 'universal'
                        ? '<span class="badge bg-primary"><i class="fas fa-globe me-1"></i>Universal</span>'
                        : '<span class="badge bg-success"><i class="fas fa-user-tag me-1"></i>Assigned</span>';

                    // 📅 Format dates
                    const validFrom = discount.validFrom ? formatDate(discount.validFrom) : 'N/A';
                    const validTo   = discount.validTo   ? formatDate(discount.validTo)   : 'No Expiry';

                    // 🔢 Usage display
                    const maxUsesDisplay = parseInt(discount.maxUses) === 0
                        ? '&infin;'
                        : discount.maxUses;
                    const usageDisplay = `${discount.currentUses || 0} / ${maxUsesDisplay}`;

                    // 🔘 Toggle button text
                    const isActive = parseInt(discount.isActive) === 1;
                    const toggleBtn = isActive
                        ? `<button class="btn btn-outline-danger btn-sm" onclick="toggleDiscount(${discount.codeID}, 0)" title="Disable"><i class="fas fa-ban"></i></button>`
                        : `<button class="btn btn-outline-success btn-sm" onclick="toggleDiscount(${discount.codeID}, 1)" title="Enable"><i class="fas fa-check"></i></button>`;

                    // 👤 Assignment button (only for assigned-type codes)
                    const assignBtn = discount.codeType === 'assigned'
                        ? `<button class="btn btn-outline-info btn-sm" onclick="openAssignments(${discount.codeID}, '${escapeHtml(discount.code)}')" title="Manage Assignments"><i class="fas fa-user-tag"></i></button>`
                        : '';

                    // 🏗️ Build the table row
                    html += `
                        <tr class="${!isActive ? 'table-secondary' : ''}">
                            <td>
                                <span class="discount-code">${escapeHtml(discount.code)}</span>
                                <button class="btn btn-sm btn-link btn-copy p-0 ms-2"
                                        onclick="copyCode('${escapeHtml(discount.code)}')"
                                        title="Copy code to clipboard">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </td>
                            <td>${typeBadge}</td>
                            <td><strong>${discountDisplay}</strong></td>
                            <td><small>${validFrom}</small></td>
                            <td><small>${validTo}</small></td>
                            <td>${usageDisplay}</td>
                            <td>${statusBadge}</td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button class="btn btn-outline-primary btn-sm"
                                            onclick="editDiscount(${discount.codeID})"
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    ${toggleBtn}
                                    ${assignBtn}
                                    <button class="btn btn-outline-secondary btn-sm"
                                            onclick="copyCode('${escapeHtml(discount.code)}')"
                                            title="Copy Code">
                                        <i class="fas fa-clipboard"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                });

                tbody.innerHTML = html;

            } catch (error) {
                // 🚨 Display error state in the table
                console.error('Error loading discounts:', error);
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center text-danger py-4">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error loading discount codes. Please try again.
                        </td>
                    </tr>
                `;
                showAlert('Failed to load discount codes: ' + error.message, 'danger');
            }
        }


        // ============================================================
        // 🏷️ STATUS BADGE LOGIC
        // ============================================================

        /**
         * 🏷️ Generate the appropriate Bootstrap status badge for a discount
         *
         * Status priority:
         *   1. Disabled (isActive = 0) -> danger
         *   2. Expired (validTo < today) -> secondary
         *   3. Scheduled (validFrom > today) -> info
         *   4. Active -> success
         *
         * @param {Object} discount - The discount code data object
         * @returns {string} HTML string for the status badge
         */
        function getStatusBadge(discount) {
            const now   = new Date();
            const today = now.toISOString().split('T')[0];

            // 🚫 Disabled takes priority
            if (parseInt(discount.isActive) !== 1) {
                return '<span class="badge bg-danger status-badge"><i class="fas fa-ban me-1"></i>Disabled</span>';
            }

            // ⏰ Expired — validTo is in the past
            if (discount.validTo && discount.validTo < today) {
                return '<span class="badge bg-secondary status-badge"><i class="fas fa-clock me-1"></i>Expired</span>';
            }

            // 📅 Scheduled — validFrom is in the future
            if (discount.validFrom && discount.validFrom > today) {
                return '<span class="badge bg-info status-badge"><i class="fas fa-calendar me-1"></i>Scheduled</span>';
            }

            // ✅ Active
            return '<span class="badge bg-success status-badge"><i class="fas fa-check-circle me-1"></i>Active</span>';
        }


        // ============================================================
        // ✏️ CREATE / EDIT MODAL LOGIC
        // ============================================================

        /**
         * ➕ Open the modal in create mode (reset all fields)
         */
        function openCreateModal() {
            // 🔄 Reset form and hidden fields
            document.getElementById('discountForm').reset();
            document.getElementById('editCodeID').value = '';
            document.getElementById('discountModalLabel').innerHTML =
                '<i class="fas fa-plus-circle me-2"></i>Create Discount Code';
            document.getElementById('saveDiscountBtn').innerHTML =
                '<i class="fas fa-save me-2"></i>Create Discount Code';

            // 📅 Set default validFrom to today
            document.getElementById('validFrom').value = new Date().toISOString().split('T')[0];

            // 🔄 Reset provider checkboxes
            document.getElementById('providerAll').checked = true;
            document.querySelectorAll('.provider-checkbox').forEach(cb => cb.checked = false);

            // 🏷️ Clear country tags
            allowedCountriesTags = [];
            blockedCountriesTags = [];
            renderTags('allowedCountriesContainer', 'allowedCountriesInput', allowedCountriesTags);
            renderTags('blockedCountriesContainer', 'blockedCountriesInput', blockedCountriesTags);

            // 💰 Reset discount prefix
            updateDiscountPrefix();
        }

        /**
         * ✏️ Open the modal in edit mode — fetch discount details and populate
         *
         * @param {number} codeID - The ID of the discount code to edit
         */
        async function editDiscount(codeID) {
            try {
                // 📡 Fetch the discount details from the API
                const response = await fetch(`${apiURL}?action=get_discount&codeID=${codeID}&partnerID=${partnerID}`);
                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.message || 'Failed to load discount details');
                }

                const d = result.discount;

                // 🔄 Update modal title and button for edit mode
                document.getElementById('discountModalLabel').innerHTML =
                    '<i class="fas fa-edit me-2"></i>Edit Discount Code';
                document.getElementById('saveDiscountBtn').innerHTML =
                    '<i class="fas fa-save me-2"></i>Update Discount Code';

                // 📝 Populate form fields
                document.getElementById('editCodeID').value     = d.codeID;
                document.getElementById('discountCode').value   = d.code;
                document.getElementById('codeType').value       = d.codeType;
                document.getElementById('discountType').value   = d.discountType;
                document.getElementById('discountValue').value  = d.discountValue;
                document.getElementById('validFrom').value      = d.validFrom || '';
                document.getElementById('validTo').value        = d.validTo || '';
                document.getElementById('maxUses').value        = d.maxUses || 0;
                document.getElementById('minPurchase').value    = d.minPurchase || 0;

                // 📦 Populate applicable tiers (multi-select)
                const tiersSelect = document.getElementById('applicableTiers');
                const selectedTiers = d.applicableTiers ? d.applicableTiers.split(',') : [];
                Array.from(tiersSelect.options).forEach(function(opt) {
                    opt.selected = selectedTiers.includes(opt.value);
                });

                // 💳 Populate provider checkboxes
                const providers = d.applicableProviders ? d.applicableProviders.split(',') : ['all'];
                if (providers.includes('all')) {
                    document.getElementById('providerAll').checked = true;
                    document.querySelectorAll('.provider-checkbox').forEach(cb => cb.checked = false);
                } else {
                    document.getElementById('providerAll').checked = false;
                    document.getElementById('providerStripe').checked  = providers.includes('stripe');
                    document.getElementById('providerPaypal').checked  = providers.includes('paypal');
                    document.getElementById('providerCoinbase').checked = providers.includes('coinbase');
                }

                // 🌍 Populate country tags
                allowedCountriesTags = d.allowedCountries ? (typeof d.allowedCountries === 'string' ? JSON.parse(d.allowedCountries) : d.allowedCountries) : [];
                blockedCountriesTags = d.blockedCountries ? (typeof d.blockedCountries === 'string' ? JSON.parse(d.blockedCountries) : d.blockedCountries) : [];
                renderTags('allowedCountriesContainer', 'allowedCountriesInput', allowedCountriesTags);
                renderTags('blockedCountriesContainer', 'blockedCountriesInput', blockedCountriesTags);

                // 💰 Update discount prefix indicator
                updateDiscountPrefix();

                // 🏗️ Show the modal
                discountModal.show();

            } catch (error) {
                console.error('Error loading discount:', error);
                showAlert('Failed to load discount details: ' + error.message, 'danger');
            }
        }


        // ============================================================
        // 💾 SAVE DISCOUNT CODE — Create or Update
        // ============================================================

        /**
         * 💾 Save the discount code (create or update based on editCodeID)
         *
         * Gathers all form field values, validates required fields,
         * and sends a POST request to the discount-actions.php API.
         *
         * @returns {Promise<void>}
         */
        async function saveDiscount() {
            // ============================================================
            // ✅ CLIENT-SIDE VALIDATION
            // ============================================================

            const code          = document.getElementById('discountCode').value.trim().toUpperCase();
            const codeType      = document.getElementById('codeType').value;
            const discountType  = document.getElementById('discountType').value;
            const discountValue = document.getElementById('discountValue').value;
            const validFrom     = document.getElementById('validFrom').value;
            const validTo       = document.getElementById('validTo').value;
            const maxUses       = document.getElementById('maxUses').value || 0;
            const minPurchase   = document.getElementById('minPurchase').value || 0;
            const editCodeID    = document.getElementById('editCodeID').value;

            // 🔍 Validate required fields
            if (!code) {
                showAlert('Discount code is required.', 'warning');
                return;
            }
            if (!discountValue || parseFloat(discountValue) <= 0) {
                showAlert('Discount value must be greater than 0.', 'warning');
                return;
            }
            if (discountType === 'percentage' && parseFloat(discountValue) > 100) {
                showAlert('Percentage discount cannot exceed 100%.', 'warning');
                return;
            }
            if (!validFrom) {
                showAlert('Valid From date is required.', 'warning');
                return;
            }
            if (validTo && validTo < validFrom) {
                showAlert('Valid To date cannot be before Valid From date.', 'warning');
                return;
            }

            // 📦 Gather applicable tiers
            const tiersSelect = document.getElementById('applicableTiers');
            const selectedTiers = Array.from(tiersSelect.selectedOptions).map(opt => opt.value).join(',');

            // 💳 Gather applicable providers
            let applicableProviders = 'all';
            if (!document.getElementById('providerAll').checked) {
                const selectedProviders = [];
                document.querySelectorAll('.provider-checkbox:checked').forEach(function(cb) {
                    selectedProviders.push(cb.value);
                });
                if (selectedProviders.length === 0) {
                    showAlert('Please select at least one payment provider or choose "All".', 'warning');
                    return;
                }
                applicableProviders = selectedProviders.join(',');
            }

            // ============================================================
            // 📡 SEND API REQUEST
            // ============================================================

            // 🔄 Determine action: create or update
            const action = editCodeID ? 'update_discount' : 'create_discount';

            // 📦 Build the request payload
            const payload = {
                action:              action,
                partnerID:           partnerID,
                code:                code,
                codeType:            codeType,
                discountType:        discountType,
                discountValue:       parseFloat(discountValue),
                validFrom:           validFrom,
                validTo:             validTo || null,
                maxUses:             parseInt(maxUses),
                applicableTiers:     selectedTiers,
                applicableProviders: applicableProviders,
                allowedCountries:    allowedCountriesTags,
                blockedCountries:    blockedCountriesTags,
                minPurchase:         parseFloat(minPurchase),
                csrf_token:          csrfToken
            };

            // 🔑 Include codeID for updates
            if (editCodeID) {
                payload.codeID = parseInt(editCodeID);
            }

            try {
                // 📡 Send the POST request
                const response = await fetch(apiURL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(result.message || 'Discount code saved successfully!', 'success');
                    discountModal.hide();

                    // 🔄 Reload the table to show the new/updated code
                    loadDiscounts();
                } else {
                    showAlert(result.message || 'Failed to save discount code.', 'danger');
                }
            } catch (error) {
                console.error('Error saving discount:', error);
                showAlert('Error saving discount code. Please try again.', 'danger');
            }
        }


        // ============================================================
        // 🔀 TOGGLE DISCOUNT — Enable / Disable
        // ============================================================

        /**
         * 🔀 Toggle a discount code's active state (enable/disable)
         *
         * @param {number} codeID  - The ID of the discount code
         * @param {number} isActive - 1 to enable, 0 to disable
         * @returns {Promise<void>}
         */
        async function toggleDiscount(codeID, isActive) {
            const actionWord = isActive ? 'enable' : 'disable';
            if (!confirm(`Are you sure you want to ${actionWord} this discount code?`)) {
                return;
            }

            try {
                const response = await fetch(apiURL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action:     'toggle_discount',
                        partnerID:  partnerID,
                        codeID:     codeID,
                        isActive:   isActive,
                        csrf_token: csrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(`Discount code ${actionWord}d successfully.`, 'success');
                    loadDiscounts();
                } else {
                    showAlert(result.message || `Failed to ${actionWord} discount code.`, 'danger');
                }
            } catch (error) {
                console.error('Error toggling discount:', error);
                showAlert(`Error ${actionWord}ing discount code. Please try again.`, 'danger');
            }
        }


        // ============================================================
        // 🎲 AUTO-GENERATE CODE
        // ============================================================

        /**
         * 🎲 Generate a random 8-character alphanumeric code via the API
         *
         * Calls the generate_code action and populates the discount code field.
         *
         * @returns {Promise<void>}
         */
        async function generateCode() {
            try {
                const response = await fetch(apiURL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action:     'generate_code',
                        partnerID:  partnerID,
                        csrf_token: csrfToken
                    })
                });

                const result = await response.json();

                if (result.success && result.code) {
                    document.getElementById('discountCode').value = result.code;
                } else {
                    showAlert('Failed to generate code.', 'warning');
                }
            } catch (error) {
                console.error('Error generating code:', error);
                showAlert('Error generating code. Please try again.', 'danger');
            }
        }


        // ============================================================
        // 📋 CLIPBOARD: Copy Discount Code
        // ============================================================

        /**
         * 📋 Copy a discount code to the user's clipboard
         *
         * Uses the Clipboard API with a fallback for older browsers.
         * @see https://developer.mozilla.org/en-US/docs/Web/API/Clipboard/writeText
         *
         * @param {string} code - The discount code to copy
         */
        function copyCode(code) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(function() {
                    showAlert(`Code <strong>${escapeHtml(code)}</strong> copied to clipboard!`, 'success');
                }).catch(function() {
                    fallbackCopy(code);
                });
            } else {
                fallbackCopy(code);
            }
        }

        /**
         * 📋 Fallback copy method using a temporary textarea element
         *
         * @param {string} text - The text to copy
         */
        function fallbackCopy(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                showAlert(`Code <strong>${escapeHtml(text)}</strong> copied to clipboard!`, 'success');
            } catch (err) {
                showAlert('Failed to copy code. Please copy it manually.', 'warning');
            }
            document.body.removeChild(textarea);
        }


        // ============================================================
        // 👤 ASSIGNMENTS: Open, Load, Assign, Remove
        // ============================================================

        /**
         * 👤 Open the Manage Assignments modal for a specific discount code
         *
         * @param {number} codeID - The discount code ID
         * @param {string} codeName - The discount code string (for display)
         */
        function openAssignments(codeID, codeName) {
            document.getElementById('assignmentCodeID').value   = codeID;
            document.getElementById('assignmentCodeName').textContent = codeName;
            document.getElementById('assignUserEmail').value    = '';

            // 🔄 Load current assignments
            loadAssignments(codeID);

            // 🏗️ Show the modal
            assignmentsModal.show();
        }

        /**
         * 📋 Load current assignments for a discount code
         *
         * @param {number} codeID - The discount code ID
         * @returns {Promise<void>}
         */
        async function loadAssignments(codeID) {
            const listContainer  = document.getElementById('assignmentsList');
            const countBadge     = document.getElementById('assignmentCount');

            // 🔄 Show loading state
            listContainer.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="fas fa-spinner fa-spin me-2"></i>Loading assignments...
                </div>
            `;

            try {
                const response = await fetch(`${apiURL}?action=get_assignments&codeID=${codeID}&partnerID=${partnerID}`);
                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.message || 'Failed to load assignments');
                }

                const assignments = result.assignments || [];
                countBadge.textContent = assignments.length;

                if (assignments.length === 0) {
                    listContainer.innerHTML = `
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-user-slash fa-2x mb-2 d-block"></i>
                            <p class="mb-0">No users assigned to this code yet.</p>
                        </div>
                    `;
                    return;
                }

                // 🏗️ Build assignment list
                let html = '';
                assignments.forEach(function(a) {
                    const usedLabel = a.usedAt
                        ? `<span class="badge bg-success"><i class="fas fa-check me-1"></i>Used ${formatDate(a.usedAt)}</span>`
                        : '<span class="badge bg-secondary">Not used</span>';

                    html += `
                        <div class="assignment-item">
                            <div>
                                <strong>${escapeHtml(a.username || a.email)}</strong>
                                <br>
                                <small class="text-muted">${escapeHtml(a.email)}</small>
                                <small class="text-muted ms-2">Assigned: ${formatDate(a.assignedAt)}</small>
                                ${usedLabel}
                            </div>
                            <button class="btn btn-sm btn-outline-danger"
                                    onclick="removeAssignment(${a.assignmentID}, '${escapeHtml(a.email)}')"
                                    title="Remove assignment">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    `;
                });

                listContainer.innerHTML = html;

            } catch (error) {
                console.error('Error loading assignments:', error);
                listContainer.innerHTML = `
                    <div class="text-center text-danger py-4">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Error loading assignments.
                    </div>
                `;
            }
        }

        /**
         * ➕ Assign a discount code to a user by email
         *
         * @returns {Promise<void>}
         */
        async function assignCode() {
            const email  = document.getElementById('assignUserEmail').value.trim();
            const codeID = document.getElementById('assignmentCodeID').value;

            // ✅ Validate email
            if (!email || !email.includes('@')) {
                showAlert('Please enter a valid email address.', 'warning');
                return;
            }

            try {
                const response = await fetch(apiURL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action:     'assign_code',
                        partnerID:  partnerID,
                        codeID:     parseInt(codeID),
                        email:      email,
                        csrf_token: csrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(result.message || 'User assigned successfully!', 'success');
                    document.getElementById('assignUserEmail').value = '';

                    // 🔄 Reload assignments list
                    loadAssignments(codeID);
                } else {
                    showAlert(result.message || 'Failed to assign code.', 'danger');
                }
            } catch (error) {
                console.error('Error assigning code:', error);
                showAlert('Error assigning code. Please try again.', 'danger');
            }
        }

        /**
         * 🗑️ Remove a user assignment from a discount code
         *
         * @param {number} assignmentID - The assignment record ID
         * @param {string} email - The user's email (for confirmation display)
         * @returns {Promise<void>}
         */
        async function removeAssignment(assignmentID, email) {
            if (!confirm(`Remove assignment for ${email}?\n\nThis will revoke their access to this discount code.`)) {
                return;
            }

            const codeID = document.getElementById('assignmentCodeID').value;

            try {
                const response = await fetch(apiURL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action:       'remove_assignment',
                        partnerID:    partnerID,
                        assignmentID: assignmentID,
                        csrf_token:   csrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Assignment removed successfully.', 'success');
                    loadAssignments(codeID);
                } else {
                    showAlert(result.message || 'Failed to remove assignment.', 'danger');
                }
            } catch (error) {
                console.error('Error removing assignment:', error);
                showAlert('Error removing assignment. Please try again.', 'danger');
            }
        }


        // ============================================================
        // 💳 PROVIDER CHECKBOX LOGIC
        // ============================================================

        /**
         * 💳 When "All Providers" is checked, uncheck individual providers
         *
         * @param {HTMLInputElement} checkbox - The "All" checkbox element
         */
        function toggleProviderAll(checkbox) {
            if (checkbox.checked) {
                document.querySelectorAll('.provider-checkbox').forEach(function(cb) {
                    cb.checked = false;
                });
            }
        }

        /**
         * 💳 When an individual provider is checked, uncheck "All"
         */
        function updateProviderAll() {
            const anyChecked = document.querySelectorAll('.provider-checkbox:checked').length > 0;
            document.getElementById('providerAll').checked = !anyChecked;
        }


        // ============================================================
        // 💰 DISCOUNT TYPE PREFIX UPDATER
        // ============================================================

        /**
         * 💰 Update the discount value input prefix based on discount type
         *
         * Shows "%" for percentage type and the currency symbol for fixed amounts.
         */
        function updateDiscountPrefix() {
            const discountType = document.getElementById('discountType').value;
            const prefix       = document.getElementById('discountValuePrefix');
            const helpText     = document.getElementById('discountValueHelp');

            if (discountType === 'percentage') {
                prefix.textContent    = '%';
                helpText.textContent  = 'Enter a value between 0 and 100 for percentages.';
            } else {
                prefix.innerHTML      = '&pound;';
                helpText.textContent  = 'Enter the fixed discount amount in GBP.';
            }
        }


        // ============================================================
        // 🏷️ TAG INPUT — Country Code Management
        // ============================================================

        /**
         * 🏷️ Initialize a tag input field for ISO country codes
         *
         * Listens for Enter and comma keys to add tags, and Backspace to remove
         * the last tag. Tags are validated as 2-character uppercase alpha codes.
         *
         * @param {string} inputID     - The ID of the text input element
         * @param {string} containerID - The ID of the tag container div
         * @param {Array}  tagsArray   - Reference to the tags array (allowed or blocked)
         */
        function initTagInput(inputID, containerID, tagsArray) {
            const input = document.getElementById(inputID);

            input.addEventListener('keydown', function(e) {
                const value = input.value.trim().toUpperCase();

                // ✅ Add tag on Enter or comma
                if ((e.key === 'Enter' || e.key === ',') && value.length > 0) {
                    e.preventDefault();

                    // 🔍 Validate: must be exactly 2 uppercase letters (ISO 3166-1 alpha-2)
                    if (/^[A-Z]{2}$/.test(value)) {
                        // 🚫 Prevent duplicates
                        if (inputID === 'allowedCountriesInput') {
                            if (!allowedCountriesTags.includes(value)) {
                                allowedCountriesTags.push(value);
                                renderTags(containerID, inputID, allowedCountriesTags);
                            }
                        } else {
                            if (!blockedCountriesTags.includes(value)) {
                                blockedCountriesTags.push(value);
                                renderTags(containerID, inputID, blockedCountriesTags);
                            }
                        }
                        input.value = '';
                    } else {
                        showAlert('Country code must be exactly 2 letters (e.g. GB, US, DE).', 'warning');
                    }
                }

                // ⬅️ Remove last tag on Backspace when input is empty
                if (e.key === 'Backspace' && value.length === 0) {
                    if (inputID === 'allowedCountriesInput' && allowedCountriesTags.length > 0) {
                        allowedCountriesTags.pop();
                        renderTags(containerID, inputID, allowedCountriesTags);
                    } else if (inputID === 'blockedCountriesInput' && blockedCountriesTags.length > 0) {
                        blockedCountriesTags.pop();
                        renderTags(containerID, inputID, blockedCountriesTags);
                    }
                }
            });
        }

        /**
         * 🏗️ Render tags inside the tag container
         *
         * Rebuilds the entire tag display from the tags array. Each tag has
         * a remove button (X) that filters it out of the array.
         *
         * @param {string} containerID - The ID of the tag container div
         * @param {string} inputID     - The ID of the text input element
         * @param {Array}  tagsArray   - The current tags array
         */
        function renderTags(containerID, inputID, tagsArray) {
            const container = document.getElementById(containerID);
            const input     = document.getElementById(inputID);

            // 🗑️ Remove existing tags (but keep the input)
            container.querySelectorAll('.tag').forEach(tag => tag.remove());

            // 🏗️ Insert tags before the input element
            tagsArray.forEach(function(tag, index) {
                const tagEl = document.createElement('span');
                tagEl.className = 'tag';
                tagEl.innerHTML = `${escapeHtml(tag)} <span class="tag-remove" data-index="${index}">&times;</span>`;

                // 🗑️ Click handler to remove this specific tag
                tagEl.querySelector('.tag-remove').addEventListener('click', function() {
                    const idx = parseInt(this.getAttribute('data-index'));
                    if (inputID === 'allowedCountriesInput') {
                        allowedCountriesTags.splice(idx, 1);
                        renderTags(containerID, inputID, allowedCountriesTags);
                    } else {
                        blockedCountriesTags.splice(idx, 1);
                        renderTags(containerID, inputID, blockedCountriesTags);
                    }
                });

                container.insertBefore(tagEl, input);
            });
        }


        // ============================================================
        // 🛠️ UTILITY FUNCTIONS
        // ============================================================

        /**
         * 📅 Format an ISO date string to a human-readable format
         *
         * @param {string} dateStr - ISO date string (YYYY-MM-DD or datetime)
         * @returns {string} Formatted date (e.g. "13 Feb 2026")
         */
        function formatDate(dateStr) {
            if (!dateStr) {
                return 'N/A';
            }
            try {
                const date = new Date(dateStr + (dateStr.includes('T') ? '' : 'T00:00:00'));
                return date.toLocaleDateString('en-GB', {
                    day:   'numeric',
                    month: 'short',
                    year:  'numeric'
                });
            } catch (e) {
                return dateStr;
            }
        }

        /**
         * 🛡️ Escape HTML special characters to prevent XSS
         *
         * @param {string} text - Raw text to escape
         * @returns {string} HTML-escaped text
         *
         * @see https://owasp.org/www-community/xss-filter-evasion-cheatsheet (OWASP XSS Prevention)
         */
        function escapeHtml(text) {
            if (!text) {
                return '';
            }
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }
    </script>
</body>
</html>
