<?php
/**
 * ============================================================================
 * 💰 SIGNula - Subscription Tier Management (Super Admin)
 * ============================================================================
 *
 * Purpose: Super Admin dashboard for managing ALL subscription tiers — both
 *          global default tiers and custom partner-created tiers. Provides
 *          full CRUD operations, tier duplication, reordering, and settings
 *          management via AJAX calls to /admin/api/tier-actions.php.
 *
 * Features:
 *   - 📊 Stats cards: Total Tiers, Active Tiers, Custom Tiers, Default Tier
 *   - 🌐 Tab 1 — Global Tiers: Card grid with pricing, features, badges
 *   - 🏢 Tab 2 — Custom Tiers: DataTable with partner name, billing mode
 *   - ⚙️ Tab 3 — Tier Settings: Form for tiers.custom.* settings
 *   - 📝 Create/Edit modal with live preview card
 *   - 🏷️ Tag-based feature input
 *   - 🔄 AJAX-driven CRUD, activate/deactivate, set default, duplicate
 *
 * Database Table: tblSubscriptionTiers
 * API Endpoint:   /admin/api/tier-actions.php
 *
 * Authentication: Super Admin only (isSuperAdmin = 1)
 *
 * PHP Version: 8.3+
 *
 * @see /_database/migrations/010_webhooks_and_payments.sql (table creation)
 * @see /_database/migrations/019_tier_expansion.sql (custom tier columns)
 * @see https://getbootstrap.com/docs/5.3/          — Bootstrap 5.3 Docs
 * @see https://fontawesome.com/icons                — FontAwesome 6.4
 * @see https://datatables.net/                      — DataTables Plugin
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\Payments\Tiers
 * @version    1.0.0
 * @since      2.7.0-beta
 * @link       https://SIGNula.id
 * ============================================================================
 */

// ============================================================================
// 🛡️ SIGNULA_INIT GUARD — Prevent direct access
// ============================================================================

// ⚙️ Main configuration (defines SIGNULA_INIT constant, DB credentials, etc.)
// dirname(__DIR__, 3) resolves to /web/ from /web/public_html/admin/payments/
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton (MySQLi prepared statements)
// @see web/_backend/Database.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🎫 Session manager (authentication state)
// @see web/_backend/SessionManager.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🔐 Access control (role-based permissions)
// @see web/_backend/AccessControl.php
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// ============================================================================
// 🔐 AUTHENTICATION & AUTHORIZATION
// ============================================================================

// 🗄️ Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check — user must be logged in
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin check — only super admins can manage global tiers
$accessControl->requireSuperAdmin();

// ============================================================================
// 📊 SERVER-SIDE STATISTICS — Quick stats for initial page render
// These are rendered server-side so the page displays data immediately,
// without waiting for the first AJAX call to complete.
// ============================================================================

/**
 * 📊 Total tier count (all tiers including inactive)
 * @see https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html
 */
$totalStmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM tblSubscriptionTiers"
);
$totalStmt->execute();
$totalTiers = (int)($totalStmt->get_result()->fetch_assoc()['cnt'] ?? 0);

/**
 * ✅ Active tier count
 */
$activeStmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM tblSubscriptionTiers WHERE isActive = 1"
);
$activeStmt->execute();
$activeTiers = (int)($activeStmt->get_result()->fetch_assoc()['cnt'] ?? 0);

/**
 * 🏢 Custom tier count (partner-created tiers)
 */
$customStmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM tblSubscriptionTiers WHERE isCustom = 1"
);
$customStmt->execute();
$customTiers = (int)($customStmt->get_result()->fetch_assoc()['cnt'] ?? 0);

/**
 * ⭐ Default tier name
 */
$defaultStmt = $db->prepare(
    "SELECT tierName FROM tblSubscriptionTiers WHERE isDefault = 1 AND isActive = 1 LIMIT 1"
);
$defaultStmt->execute();
$defaultTierRow = $defaultStmt->get_result()->fetch_assoc();
$defaultTierName = $defaultTierRow['tierName'] ?? 'None';

/**
 * 🌐 Load all GLOBAL tiers (isCustom = 0) for the card grid, including inactive
 * Ordered by displayOrder for consistent presentation
 */
$globalStmt = $db->prepare(
    "SELECT * FROM tblSubscriptionTiers WHERE isCustom = 0 ORDER BY displayOrder ASC, tierName ASC"
);
$globalStmt->execute();
$globalTiers = $globalStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/**
 * 🏢 Load all CUSTOM tiers with partner names for the DataTable
 */
$customListStmt = $db->prepare(
    "SELECT t.*, p.companyName AS partnerName
     FROM tblSubscriptionTiers t
     LEFT JOIN tblPartners p ON t.createdByPartnerID = p.partnerID
     WHERE t.isCustom = 1
     ORDER BY t.createdAt DESC"
);
$customListStmt->execute();
$customTiersList = $customListStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/**
 * 🌐 Load all global (non-custom) active tiers for the parent tier dropdown
 */
$parentStmt = $db->prepare(
    "SELECT tierID, tierName FROM tblSubscriptionTiers WHERE isCustom = 0 AND isActive = 1 ORDER BY displayOrder ASC"
);
$parentStmt->execute();
$parentTiers = $parentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/**
 * ⚙️ Load tier settings from tblSettings
 */
$settingsStmt = $db->prepare(
    "SELECT settingKey, settingValue FROM tblSettings WHERE settingKey LIKE 'tiers.custom.%'"
);
$settingsStmt->execute();
$settingsResult = $settingsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$tierSettings = [];
foreach ($settingsResult as $row) {
    $tierSettings[$row['settingKey']] = $row['settingValue'];
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
    <title>Subscription Tiers - SIGNula Admin</title>

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

    <!-- 📋 DataTables CSS (CDN) — for sortable, searchable tables          -->
    <!-- @see https://datatables.net/ — DataTables Documentation            -->
    <link rel="stylesheet"
          href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css"
          crossorigin="anonymous">

    <!-- ================================================================== -->
    <!-- 🎨 CUSTOM STYLES                                                   -->
    <!-- ================================================================== -->
    <style>
        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Base body styling — light grey background matching admin    */
        /* ═══════════════════════════════════════════════════════════════ */
        body { background-color: #f8f9fa; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎨 Page header — green/teal gradient matching payments theme   */
        /* Identical to usage-metrics.php and payments/index.php headers  */
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
        /* 📊 Stat cards — summary statistics at top of page              */
        /* Replicates .stat-card pattern from payments/index.php          */
        /* ═══════════════════════════════════════════════════════════════ */
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
            text-align: center;
            cursor: default;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        }
        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-card .stat-label {
            color: #6c757d;
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 💰 Tier card — individual subscription tier display            */
        /* Matches partner admin tiers.php card styling                   */
        /* ═══════════════════════════════════════════════════════════════ */
        .tier-card {
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
            position: relative;
        }
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

        /* ═══════════════════════════════════════════════════════════════ */
        /* 💵 Price display — large bold text for monthly pricing         */
        /* ═══════════════════════════════════════════════════════════════ */
        .tier-price {
            font-size: 2rem;
            font-weight: 700;
            color: #333;
        }
        .tier-price .period {
            font-size: 0.9rem;
            font-weight: 400;
            color: #6c757d;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📋 Feature list — clean list styling for tier features         */
        /* ═══════════════════════════════════════════════════════════════ */
        .tier-features {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .tier-features li {
            padding: 0.35rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .tier-features li:last-child {
            border-bottom: none;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🏷️ Feature tag — styled tag for feature input                  */
        /* ═══════════════════════════════════════════════════════════════ */
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

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔮 Live preview card in modal — shows real-time tier card      */
        /* ═══════════════════════════════════════════════════════════════ */
        .preview-card {
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
            min-height: 300px;
            transition: all 0.3s;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔧 Feature limit row — key-value pair styling                  */
        /* ═══════════════════════════════════════════════════════════════ */
        .feature-limit-row,
        .usage-limit-row {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📋 Table card — white container with gradient header           */
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

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎴 Tab navigation — custom styling with teal active indicator  */
        /* @see https://getbootstrap.com/docs/5.3/components/navs-tabs/  */
        /* ═══════════════════════════════════════════════════════════════ */
        .nav-tabs .nav-link {
            color: #495057;
            font-weight: 500;
            border: none;
            padding: 0.75rem 1.25rem;
        }
        .nav-tabs .nav-link.active {
            color: #11998e;
            border-bottom: 3px solid #11998e;
            background: transparent;
        }
        .nav-tabs .nav-link:hover:not(.active) {
            color: #11998e;
            border-color: transparent;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🏷️ Status badges — colour-coded for quick scanning             */
        /* ═══════════════════════════════════════════════════════════════ */
        .badge-active   { background-color: #28a745; }
        .badge-inactive { background-color: #6c757d; }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📱 Modal header gradient — consistent with other admin modals  */
        /* ═══════════════════════════════════════════════════════════════ */
        .modal-header-gradient {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🎛️ Modal — wider for better form layout                        */
        /* ═══════════════════════════════════════════════════════════════ */
        .modal-xl-custom {
            max-width: 1050px;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🔄 Loading overlay — spinner shown during AJAX operations      */
        /* ═══════════════════════════════════════════════════════════════ */
        .loading-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(255,255,255,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 🍞 Toast container — fixed position for notifications          */
        /* ═══════════════════════════════════════════════════════════════ */
        .toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 9999;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📋 DataTables overrides — match admin theme styling            */
        /* ═══════════════════════════════════════════════════════════════ */
        .dataTables_wrapper .dataTables_filter input {
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
        .dataTables_wrapper .dataTables_length select {
            border-radius: 6px;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* ⚙️ Settings card — white background with teal accent           */
        /* ═══════════════════════════════════════════════════════════════ */
        .settings-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 1.5rem;
        }

        /* ═══════════════════════════════════════════════════════════════ */
        /* 📱 Responsive adjustments for smaller screens                  */
        /* ═══════════════════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .stat-card .stat-number { font-size: 1.5rem; }
            .stat-card { padding: 1rem; }
            .page-header { padding: 1.5rem; }
            .nav-tabs .nav-link { padding: 0.5rem 0.75rem; font-size: 0.9rem; }
            .tier-price { font-size: 1.5rem; }
        }
    </style>
</head>
<body>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- 🍞 TOAST NOTIFICATION CONTAINER                                -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="toast-container" id="toastContainer"></div>

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
                        <i class="fas fa-layer-group me-2"></i>Subscription Tiers
                    </h1>
                    <p class="mb-0 opacity-75">
                        Manage global default tiers and custom partner-created tiers across the entire platform.
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <!-- ➕ Create New Tier button -->
                    <button class="btn btn-outline-light btn-lg" onclick="openCreateModal()"
                            data-bs-toggle="modal" data-bs-target="#tierModal">
                        <i class="fas fa-plus-circle me-2"></i>Create Tier
                    </button>
                </div>
            </div>
        </div>

        <!-- 💬 Alert notification container — dynamically populated by showAlert() -->
        <div id="alertContainer"></div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📊 STATS CARDS — Summary statistics row                       -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="row g-3 mb-4">
            <!-- 📊 Total tiers -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-primary" id="stat-total-tiers">
                        <?php echo (int)$totalTiers; ?>
                    </div>
                    <div class="stat-label">
                        <i class="fas fa-layer-group me-1"></i>Total Tiers
                    </div>
                </div>
            </div>

            <!-- ✅ Active tiers -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-success" id="stat-active-tiers">
                        <?php echo (int)$activeTiers; ?>
                    </div>
                    <div class="stat-label">
                        <i class="fas fa-check-circle me-1"></i>Active Tiers
                    </div>
                </div>
            </div>

            <!-- 🏢 Custom tiers -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-warning" id="stat-custom-tiers">
                        <?php echo (int)$customTiers; ?>
                    </div>
                    <div class="stat-label">
                        <i class="fas fa-puzzle-piece me-1"></i>Custom Tiers
                    </div>
                </div>
            </div>

            <!-- ⭐ Default tier -->
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number text-info" id="stat-default-tier" style="font-size: 1.4rem;">
                        <?php echo htmlspecialchars($defaultTierName); ?>
                    </div>
                    <div class="stat-label">
                        <i class="fas fa-star me-1"></i>Default Tier
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 🎴 TABBED INTERFACE — Three main content tabs                  -->
        <!-- @see https://getbootstrap.com/docs/5.3/components/navs-tabs/  -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <ul class="nav nav-tabs mb-4" id="mainTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-global" data-bs-toggle="tab"
                        data-bs-target="#pane-global" type="button" role="tab"
                        aria-controls="pane-global" aria-selected="true">
                    <i class="fas fa-globe me-1"></i>Global Tiers
                    <span class="badge bg-primary ms-1"><?php echo count($globalTiers); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-custom" data-bs-toggle="tab"
                        data-bs-target="#pane-custom" type="button" role="tab"
                        aria-controls="pane-custom" aria-selected="false">
                    <i class="fas fa-puzzle-piece me-1"></i>Custom Tiers
                    <span class="badge bg-warning text-dark ms-1"><?php echo count($customTiersList); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-settings" data-bs-toggle="tab"
                        data-bs-target="#pane-settings" type="button" role="tab"
                        aria-controls="pane-settings" aria-selected="false">
                    <i class="fas fa-cog me-1"></i>Tier Settings
                </button>
            </li>
        </ul>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 📋 TAB CONTENT PANES                                          -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="tab-content" id="mainTabContent">

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 🌐 TAB 1 — GLOBAL TIERS (Card Grid)                    -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade show active" id="pane-global" role="tabpanel" aria-labelledby="tab-global">

                <!-- ℹ️ Info box for global tiers -->
                <div class="alert alert-info mb-4">
                    <h6 class="alert-heading"><i class="fas fa-info-circle me-2"></i>About Global Tiers</h6>
                    <p class="mb-2">
                        Global tiers are the default pricing plans available to all users and partners.
                        They are ordered by display order and shown on the public pricing page.
                    </p>
                    <ul class="mb-0">
                        <li><strong>Default Tier:</strong> Automatically assigned to new subscribers (typically Free)</li>
                        <li><strong>Features:</strong> List the benefits included in each tier</li>
                        <li><strong>Duplicate:</strong> Clone any tier to quickly create a new one with similar settings</li>
                    </ul>
                </div>

                <?php if (empty($globalTiers)): ?>
                <!-- 📭 Empty state — no global tiers -->
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-layer-group fa-4x text-muted mb-3"></i>
                        <h4>No Global Tiers Yet</h4>
                        <p class="text-muted">Create your first global subscription tier to get started.</p>
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#tierModal"
                                onclick="openCreateModal()">
                            <i class="fas fa-plus-circle me-2"></i>Create Your First Tier
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <div class="row" id="globalTiersGrid">
                    <?php foreach ($globalTiers as $tier): ?>
                    <?php
                        // 💱 Resolve the currency symbol for display
                        $currSymbol = $currencySymbols[$tier['currency'] ?? 'GBP'] ?? '£';

                        // 📦 Decode features JSON into an array
                        $features = [];
                        if (!empty($tier['features'])) {
                            $decoded = is_string($tier['features']) ? json_decode($tier['features'], true) : $tier['features'];
                            if (is_array($decoded)) {
                                $features = $decoded;
                            }
                        }

                        // 🏷️ Determine badge colour class (fallback to 'primary')
                        $badgeClass = !empty($tier['badgeColor']) ? $tier['badgeColor'] : 'primary';

                        // 📊 Decode feature limits for display
                        $featureLimits = [];
                        if (!empty($tier['featureLimits'])) {
                            $decoded = is_string($tier['featureLimits']) ? json_decode($tier['featureLimits'], true) : $tier['featureLimits'];
                            if (is_array($decoded)) {
                                $featureLimits = $decoded;
                            }
                        }
                    ?>
                    <!-- 💰 Tier Card: <?php echo htmlspecialchars($tier['tierName']); ?> -->
                    <div class="col-xl-4 col-lg-6 mb-4" id="tierCard-<?php echo (int)$tier['tierID']; ?>">
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

                                <!-- 🔖 Slug, status, and billing mode -->
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
                                    <?php if (!empty($tier['billingMode']) && $tier['billingMode'] !== 'fixed'): ?>
                                    <span class="badge bg-info ms-1">
                                        <?php echo htmlspecialchars(ucfirst($tier['billingMode'])); ?>
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

                                <!-- 📊 Limits summary (team, API, storage) -->
                                <div class="mb-3">
                                    <small class="text-muted d-block">
                                        <i class="fas fa-users me-1"></i>Team:
                                        <?php echo $tier['teamMemberLimit'] !== null ? (int)$tier['teamMemberLimit'] : 'Unlimited'; ?>
                                    </small>
                                    <small class="text-muted d-block">
                                        <i class="fas fa-bolt me-1"></i>API:
                                        <?php echo $tier['apiCallsPerMonth'] !== null ? number_format((int)$tier['apiCallsPerMonth']) . '/mo' : 'Unlimited'; ?>
                                    </small>
                                    <small class="text-muted d-block">
                                        <i class="fas fa-database me-1"></i>Storage:
                                        <?php echo $tier['storageGB'] !== null ? number_format((float)$tier['storageGB'], 1) . ' GB' : 'Unlimited'; ?>
                                    </small>
                                </div>

                                <!-- ✅ Features list (show first 5) -->
                                <?php if (!empty($features)): ?>
                                <ul class="tier-features mb-3">
                                    <?php foreach (array_slice($features, 0, 5) as $feature): ?>
                                    <li>
                                        <i class="fas fa-check text-success me-2"></i>
                                        <?php echo htmlspecialchars($feature); ?>
                                    </li>
                                    <?php endforeach; ?>
                                    <?php if (count($features) > 5): ?>
                                    <li class="text-muted">
                                        <i class="fas fa-ellipsis-h me-2"></i>
                                        +<?php echo count($features) - 5; ?> more features
                                    </li>
                                    <?php endif; ?>
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

                                    <!-- 📋 Duplicate button -->
                                    <button class="btn btn-sm btn-outline-secondary"
                                            onclick="duplicateTier(<?php echo (int)$tier['tierID']; ?>, '<?php echo htmlspecialchars($tier['tierName'], ENT_QUOTES); ?>')"
                                            title="Duplicate this tier">
                                        <i class="fas fa-copy me-1"></i>Duplicate
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
                                    <!-- ⭐ Set as default button -->
                                    <button class="btn btn-sm btn-outline-success"
                                            onclick="setDefaultTier(<?php echo (int)$tier['tierID']; ?>, '<?php echo htmlspecialchars($tier['tierName'], ENT_QUOTES); ?>')"
                                            title="Set as default tier for new subscribers">
                                        <i class="fas fa-star me-1"></i>Set Default
                                    </button>
                                    <?php endif; ?>

                                    <!-- 🗑️ Delete button -->
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="deleteTier(<?php echo (int)$tier['tierID']; ?>, '<?php echo htmlspecialchars($tier['tierName'], ENT_QUOTES); ?>')"
                                            title="Permanently delete this tier">
                                        <i class="fas fa-trash me-1"></i>Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- 🏢 TAB 2 — CUSTOM TIERS (DataTable)                    -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pane-custom" role="tabpanel" aria-labelledby="tab-custom">

                <!-- ℹ️ Info box for custom tiers -->
                <div class="alert alert-warning mb-4">
                    <h6 class="alert-heading"><i class="fas fa-info-circle me-2"></i>About Custom Tiers</h6>
                    <p class="mb-0">
                        Custom tiers are created by partners to offer tailored pricing to their customers.
                        Super Admins can review, edit, activate/deactivate, or delete custom tiers from here.
                    </p>
                </div>

                <div class="card table-card position-relative">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-puzzle-piece me-2"></i>Custom Tiers</h5>
                        <span class="badge bg-light text-dark"><?php echo count($customTiersList); ?> total</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="customTiersTable" class="table table-striped table-hover w-100">
                                <thead>
                                    <tr>
                                        <th>Tier Name</th>
                                        <th>Partner</th>
                                        <th>Monthly</th>
                                        <th>Yearly</th>
                                        <th>Billing Mode</th>
                                        <th>Parent Tier</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customTiersList as $cTier): ?>
                                    <?php
                                        $cSymbol = $currencySymbols[$cTier['currency'] ?? 'GBP'] ?? '£';
                                    ?>
                                    <tr>
                                        <!-- 🏷️ Tier name with slug -->
                                        <td>
                                            <strong><?php echo htmlspecialchars($cTier['tierName']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($cTier['tierSlug']); ?></small>
                                        </td>
                                        <!-- 🏢 Partner name -->
                                        <td>
                                            <?php echo htmlspecialchars($cTier['partnerName'] ?? 'Unknown'); ?>
                                        </td>
                                        <!-- 💵 Monthly price -->
                                        <td><?php echo htmlspecialchars($cSymbol); ?><?php echo number_format((float)$cTier['monthlyPrice'], 2); ?></td>
                                        <!-- 📅 Yearly price -->
                                        <td><?php echo htmlspecialchars($cSymbol); ?><?php echo number_format((float)$cTier['yearlyPrice'], 2); ?></td>
                                        <!-- 📊 Billing mode -->
                                        <td>
                                            <span class="badge bg-<?php echo ($cTier['billingMode'] ?? 'fixed') === 'fixed' ? 'secondary' : (($cTier['billingMode'] ?? '') === 'hybrid' ? 'warning' : 'info'); ?>">
                                                <?php echo htmlspecialchars(ucfirst($cTier['billingMode'] ?? 'fixed')); ?>
                                            </span>
                                        </td>
                                        <!-- 🔗 Parent tier -->
                                        <td>
                                            <?php
                                                if (!empty($cTier['parentTierID'])) {
                                                    // 🔍 Find parent tier name from parentTiers list
                                                    $parentName = 'ID#' . (int)$cTier['parentTierID'];
                                                    foreach ($parentTiers as $pt) {
                                                        if ((int)$pt['tierID'] === (int)$cTier['parentTierID']) {
                                                            $parentName = htmlspecialchars($pt['tierName']);
                                                            break;
                                                        }
                                                    }
                                                    echo $parentName;
                                                } else {
                                                    echo '<span class="text-muted">—</span>';
                                                }
                                            ?>
                                        </td>
                                        <!-- 🟢 Status -->
                                        <td>
                                            <?php if (!empty($cTier['isActive'])): ?>
                                            <span class="badge badge-active">Active</span>
                                            <?php else: ?>
                                            <span class="badge badge-inactive">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <!-- 📅 Created date -->
                                        <td>
                                            <small><?php echo htmlspecialchars(date('d M Y', strtotime($cTier['createdAt']))); ?></small>
                                        </td>
                                        <!-- 🔧 Actions -->
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button class="btn btn-outline-primary" onclick="openEditModal(<?php echo (int)$cTier['tierID']; ?>)"
                                                        title="Edit"><i class="fas fa-edit"></i></button>
                                                <?php if (!empty($cTier['isActive'])): ?>
                                                <button class="btn btn-outline-warning"
                                                        onclick="toggleTierStatus(<?php echo (int)$cTier['tierID']; ?>, '<?php echo htmlspecialchars($cTier['tierName'], ENT_QUOTES); ?>', false)"
                                                        title="Deactivate"><i class="fas fa-eye-slash"></i></button>
                                                <?php else: ?>
                                                <button class="btn btn-outline-success"
                                                        onclick="toggleTierStatus(<?php echo (int)$cTier['tierID']; ?>, '<?php echo htmlspecialchars($cTier['tierName'], ENT_QUOTES); ?>', true)"
                                                        title="Activate"><i class="fas fa-eye"></i></button>
                                                <?php endif; ?>
                                                <button class="btn btn-outline-danger"
                                                        onclick="deleteTier(<?php echo (int)$cTier['tierID']; ?>, '<?php echo htmlspecialchars($cTier['tierName'], ENT_QUOTES); ?>')"
                                                        title="Delete"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- ⚙️ TAB 3 — TIER SETTINGS                               -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pane-settings" role="tabpanel" aria-labelledby="tab-settings">

                <div class="settings-card">
                    <h5 class="mb-4"><i class="fas fa-cog me-2 text-success"></i>Custom Tier Settings</h5>
                    <p class="text-muted mb-4">
                        Configure how custom tiers are handled across the platform. These settings apply to
                        all partners creating custom subscription tiers.
                    </p>

                    <form id="tierSettingsForm" novalidate>
                        <!-- 🔒 CSRF Token -->
                        <input type="hidden" name="csrf_token" id="settingsCsrfToken"
                               value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">

                        <!-- ✅ Custom Tiers Enabled -->
                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="settingCustomEnabled"
                                       role="switch"
                                       <?php echo ($tierSettings['tiers.custom.enabled'] ?? 'true') === 'true' ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="settingCustomEnabled">
                                    <i class="fas fa-toggle-on me-1 text-success"></i>Enable Custom Tiers
                                </label>
                            </div>
                            <div class="form-text">
                                When enabled, partners can create their own custom subscription tiers.
                                Disabling this will not affect existing custom tiers.
                            </div>
                        </div>

                        <!-- 📊 Max Custom Tiers Per Partner -->
                        <div class="mb-4">
                            <label for="settingMaxPerPartner" class="form-label fw-bold">
                                <i class="fas fa-hashtag me-1 text-primary"></i>Max Custom Tiers Per Partner
                            </label>
                            <input type="number" class="form-control" id="settingMaxPerPartner"
                                   value="<?php echo htmlspecialchars($tierSettings['tiers.custom.max_per_partner'] ?? '10'); ?>"
                                   min="1" max="100" step="1" style="max-width: 200px;">
                            <div class="form-text">
                                Maximum number of custom tiers each partner can create (1-100).
                            </div>
                        </div>

                        <!-- 🛡️ Require Approval -->
                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="settingRequireApproval"
                                       role="switch"
                                       <?php echo ($tierSettings['tiers.custom.require_approval'] ?? 'false') === 'true' ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="settingRequireApproval">
                                    <i class="fas fa-shield-alt me-1 text-warning"></i>Require Admin Approval
                                </label>
                            </div>
                            <div class="form-text">
                                When enabled, custom tiers created by partners will require Super Admin approval
                                before they can be activated. Partners will receive a notification when approved.
                            </div>
                        </div>

                        <!-- 💾 Save settings button -->
                        <button type="button" class="btn btn-success" id="saveSettingsBtn" onclick="saveSettings()">
                            <i class="fas fa-save me-2"></i>Save Settings
                        </button>
                    </form>
                </div>
            </div>

        </div><!-- /.tab-content -->

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
                <div class="modal-header modal-header-gradient">
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
                                <!-- 🔒 Hidden fields -->
                                <input type="hidden" id="formTierID" value="">
                                <input type="hidden" id="formCsrfToken"
                                       value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">

                                <!-- 🏷️ Tier Name -->
                                <div class="mb-3">
                                    <label for="formTierName" class="form-label fw-bold">
                                        <i class="fas fa-heading me-1"></i>Tier Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="formTierName"
                                           placeholder="e.g. Professional, Enterprise, Starter"
                                           required maxlength="100"
                                           oninput="updateSlugFromName(); updatePreview();">
                                    <div class="form-text">The display name shown on the pricing page.</div>
                                </div>

                                <!-- 🔗 Tier Slug (auto-generated from name) -->
                                <div class="mb-3">
                                    <label for="formTierSlug" class="form-label fw-bold">
                                        <i class="fas fa-link me-1"></i>Tier Slug <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="formTierSlug"
                                           placeholder="auto-generated-from-name"
                                           required maxlength="50" pattern="[a-z0-9\-]+"
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
                                    <textarea class="form-control" id="formTierDescription" rows="3"
                                              placeholder="Describe the benefits of this tier..."
                                              maxlength="500" oninput="updatePreview();"></textarea>
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
                                            <input type="number" class="form-control" id="formMonthlyPrice"
                                                   value="0.00" min="0" step="0.01" oninput="updatePreview();">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="formYearlyPrice" class="form-label fw-bold">
                                            <i class="fas fa-calendar me-1"></i>Yearly Price
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text" id="currencySymbolDisplay2">£</span>
                                            <input type="number" class="form-control" id="formYearlyPrice"
                                                   value="0.00" min="0" step="0.01" oninput="updatePreview();">
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
                                    <div id="featureTagsContainer" class="border rounded p-2 mb-2" style="min-height: 44px;">
                                        <span class="text-muted small">No features added yet</span>
                                    </div>
                                    <!-- ➕ Add feature input -->
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="featureInput"
                                               placeholder="Type a feature and press Enter or click Add..."
                                               maxlength="100">
                                        <button class="btn btn-outline-success" type="button" onclick="addFeatureTag()">
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
                                    <button class="btn btn-sm btn-outline-secondary mt-2" type="button" onclick="addFeatureLimitRow()">
                                        <i class="fas fa-plus me-1"></i>Add Limit
                                    </button>
                                    <div class="form-text">
                                        Define numeric limits (e.g. api_calls: 1000, storage_gb: 5). Stored as JSON.
                                    </div>
                                </div>

                                <!-- 📊 Usage Limits (Key-Value Pairs) -->
                                <div class="mb-3">
                                    <label class="form-label fw-bold">
                                        <i class="fas fa-gauge-high me-1"></i>Usage Limits <small class="text-muted">(Optional)</small>
                                    </label>
                                    <div id="usageLimitsContainer">
                                        <!-- 🔧 Usage limit rows are dynamically inserted here -->
                                    </div>
                                    <button class="btn btn-sm btn-outline-secondary mt-2" type="button" onclick="addUsageLimitRow()">
                                        <i class="fas fa-plus me-1"></i>Add Usage Limit
                                    </button>
                                    <div class="form-text">
                                        Per-metric usage limits for this tier (e.g. api_calls: 5000, storage_gb: 10).
                                    </div>
                                </div>

                                <!-- 📊 Billing Mode -->
                                <div class="mb-3">
                                    <label for="formBillingMode" class="form-label fw-bold">
                                        <i class="fas fa-file-invoice-dollar me-1"></i>Billing Mode
                                    </label>
                                    <select class="form-select" id="formBillingMode">
                                        <option value="fixed">Fixed — Flat monthly/yearly fee</option>
                                        <option value="usage">Usage — Pay per usage only</option>
                                        <option value="hybrid">Hybrid — Base fee + usage overage</option>
                                    </select>
                                    <div class="form-text">How subscribers on this tier are billed.</div>
                                </div>

                                <!-- 📊 Team / API / Storage Row -->
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label for="formTeamMemberLimit" class="form-label fw-bold">
                                            <i class="fas fa-users me-1"></i>Team Limit
                                        </label>
                                        <input type="number" class="form-control" id="formTeamMemberLimit"
                                               placeholder="Unlimited" min="0" step="1">
                                        <div class="form-text">Leave empty for unlimited.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="formApiCallsPerMonth" class="form-label fw-bold">
                                            <i class="fas fa-bolt me-1"></i>API Calls/Month
                                        </label>
                                        <input type="number" class="form-control" id="formApiCallsPerMonth"
                                               placeholder="Unlimited" min="0" step="100">
                                        <div class="form-text">Leave empty for unlimited.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="formStorageGB" class="form-label fw-bold">
                                            <i class="fas fa-database me-1"></i>Storage (GB)
                                        </label>
                                        <input type="number" class="form-control" id="formStorageGB"
                                               placeholder="Unlimited" min="0" step="0.5">
                                        <div class="form-text">Leave empty for unlimited.</div>
                                    </div>
                                </div>

                                <!-- 📊 Display Order + Trial Days Row -->
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="formDisplayOrder" class="form-label fw-bold">
                                            <i class="fas fa-sort-numeric-up me-1"></i>Display Order
                                        </label>
                                        <input type="number" class="form-control" id="formDisplayOrder"
                                               value="0" min="0" step="1">
                                        <div class="form-text">Lower numbers appear first on the pricing page.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="formTrialDays" class="form-label fw-bold">
                                            <i class="fas fa-clock me-1"></i>Trial Days
                                        </label>
                                        <input type="number" class="form-control" id="formTrialDays"
                                               value="0" min="0" step="1" oninput="updatePreview();">
                                        <div class="form-text">Free trial period in days (0 = no trial).</div>
                                    </div>
                                </div>

                                <!-- 🏷️ Badge Row (Text + Colour) -->
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="formBadge" class="form-label fw-bold">
                                            <i class="fas fa-tag me-1"></i>Badge Text
                                        </label>
                                        <input type="text" class="form-control" id="formBadge"
                                               placeholder="e.g. Popular, Best Value, New"
                                               maxlength="50" oninput="updatePreview();">
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

                                <!-- 🏢 Is Custom + Parent Tier Row -->
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="formIsCustom" role="switch">
                                            <label class="form-check-label fw-bold" for="formIsCustom">
                                                <i class="fas fa-puzzle-piece me-1 text-warning"></i>Custom Tier
                                            </label>
                                        </div>
                                        <div class="form-text">
                                            Mark as custom if this tier was created for a specific partner.
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="formParentTierID" class="form-label fw-bold">
                                            <i class="fas fa-sitemap me-1"></i>Parent Tier
                                        </label>
                                        <select class="form-select" id="formParentTierID">
                                            <option value="">None (Standalone)</option>
                                            <?php foreach ($parentTiers as $pt): ?>
                                            <option value="<?php echo (int)$pt['tierID']; ?>">
                                                <?php echo htmlspecialchars($pt['tierName']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Optional base tier this is derived from.</div>
                                    </div>
                                </div>

                                <!-- ⭐ Is Default Checkbox -->
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="formIsDefault" role="switch">
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

    <!-- 🔧 jQuery 3.7.1 — required for DataTables                          -->
    <!-- @see https://jquery.com/                                            -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
            integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
            crossorigin="anonymous"></script>

    <!-- 🎨 Bootstrap 5.3.2 JS Bundle (includes Popper.js for dropdowns)    -->
    <!-- @see https://getbootstrap.com/docs/5.3/getting-started/introduction/ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <!-- 📋 DataTables JS (CDN) — sortable, searchable tables                -->
    <!-- @see https://datatables.net/                                        -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

    <!-- ================================================================== -->
    <!-- 🧠 APPLICATION JAVASCRIPT                                          -->
    <!-- ================================================================== -->
    <script>
        // ================================================================
        // 🔧 CONFIGURATION & STATE
        // ================================================================

        /** @type {string} CSRF token for AJAX requests */
        const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';

        /** @type {Bootstrap.Modal} Reference to the tier modal instance */
        const tierModal = new bootstrap.Modal(document.getElementById('tierModal'));

        /** @type {string[]} Array of current feature strings in the form */
        let currentFeatures = [];

        /** @type {Object} Currency code to symbol mapping */
        const currencySymbols = <?php echo json_encode($currencySymbols); ?>;

        /** @type {string} API endpoint for tier actions */
        const apiEndpoint = '/admin/api/tier-actions.php';

        // ================================================================
        // 📋 INITIALISE DATATABLES
        // ================================================================

        /**
         * 📋 Initialise the custom tiers DataTable when the tab is first shown
         * Lazy initialisation avoids rendering issues with hidden tabs
         * @see https://datatables.net/examples/basic_init/zero_configuration.html
         */
        let customTable = null;
        document.getElementById('tab-custom').addEventListener('shown.bs.tab', function() {
            if (!customTable) {
                customTable = $('#customTiersTable').DataTable({
                    pageLength: 25,
                    order: [[7, 'desc']], // 📅 Sort by created date descending
                    columnDefs: [
                        { orderable: false, targets: [8] } // 🔧 Actions column not sortable
                    ],
                    language: {
                        emptyTable: '<i class="fas fa-info-circle me-2"></i>No custom tiers found',
                        zeroRecords: '<i class="fas fa-search me-2"></i>No matching custom tiers'
                    }
                });
            }
        });

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
            return String(text).replace(/[&<>"']/g, m => map[m]);
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
         * @param {number} index - Zero-based index of the feature to remove
         */
        function removeFeatureTag(index) {
            currentFeatures.splice(index, 1);
            renderFeatureTags();
            updatePreview();
        }

        /**
         * 🎨 Render all feature tags in the container
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
         * @returns {Object} Feature limits as key-value pairs
         */
        function collectFeatureLimits() {
            const limits = {};
            const rows = document.querySelectorAll('#featureLimitsContainer .feature-limit-row');
            rows.forEach(row => {
                const key = row.querySelector('.limit-key').value.trim();
                const val = row.querySelector('.limit-value').value.trim();
                if (key !== '') {
                    limits[key] = val !== '' ? Number(val) : 0;
                }
            });
            return limits;
        }

        // ================================================================
        // 📊 USAGE LIMITS (KEY-VALUE PAIRS)
        // ================================================================

        /**
         * ➕ Add a new usage limit key-value input row
         *
         * @param {string} [key='']   - Pre-filled key name
         * @param {string} [value=''] - Pre-filled numeric value
         */
        function addUsageLimitRow(key = '', value = '') {
            const container = document.getElementById('usageLimitsContainer');
            const row = document.createElement('div');
            row.className = 'usage-limit-row';
            row.innerHTML = `
                <input type="text" class="form-control form-control-sm usage-key"
                       placeholder="Key (e.g. api_calls)" value="${escapeHtml(key)}" style="flex: 1;">
                <input type="number" class="form-control form-control-sm usage-value"
                       placeholder="Value" value="${escapeHtml(String(value))}" min="0" style="flex: 1;">
                <button class="btn btn-sm btn-outline-danger" type="button" onclick="this.parentElement.remove();"
                        title="Remove this limit">
                    <i class="fas fa-times"></i>
                </button>
            `;
            container.appendChild(row);
        }

        /**
         * 📦 Collect usage limits from all key-value rows
         *
         * @returns {Object} Usage limits as key-value pairs
         */
        function collectUsageLimits() {
            const limits = {};
            const rows = document.querySelectorAll('#usageLimitsContainer .usage-limit-row');
            rows.forEach(row => {
                const key = row.querySelector('.usage-key').value.trim();
                const val = row.querySelector('.usage-value').value.trim();
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
         * and strips non-alphanumeric characters (except hyphens).
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/replace
         */
        function updateSlugFromName() {
            const nameInput = document.getElementById('formTierName');
            const slugInput = document.getElementById('formTierSlug');

            // 🔧 Generate slug: lowercase, spaces→hyphens, strip non-alphanumeric
            slugInput.value = nameInput.value
                .toLowerCase()
                .replace(/\s+/g, '-')
                .replace(/[^a-z0-9\-]/g, '')
                .replace(/-+/g, '-')       // 🔄 Collapse consecutive hyphens
                .replace(/^-|-$/g, '');     // ✂️ Trim leading/trailing hyphens
        }

        // ================================================================
        // 💱 CURRENCY SYMBOL UPDATER
        // ================================================================

        /**
         * 💱 Update the currency symbol displayed in the price input fields
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
        // 📝 MODAL MANAGEMENT
        // ================================================================

        /**
         * ➕ Open the modal in "Create" mode
         *
         * Resets all form fields to their default/empty values, clears
         * feature tags and limits, updates the modal title, and resets preview.
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
            document.getElementById('formBillingMode').value = 'fixed';
            document.getElementById('formTeamMemberLimit').value = '';
            document.getElementById('formApiCallsPerMonth').value = '';
            document.getElementById('formStorageGB').value = '';
            document.getElementById('formDisplayOrder').value = '0';
            document.getElementById('formTrialDays').value = '0';
            document.getElementById('formBadge').value = '';
            document.getElementById('formBadgeColor').value = '';
            document.getElementById('formIsCustom').checked = false;
            document.getElementById('formParentTierID').value = '';
            document.getElementById('formIsDefault').checked = false;

            // 🧹 Reset feature tags, feature limits, and usage limits
            currentFeatures = [];
            renderFeatureTags();
            document.getElementById('featureLimitsContainer').innerHTML = '';
            document.getElementById('usageLimitsContainer').innerHTML = '';

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
                const response = await fetch(`${apiEndpoint}?action=get_tier&tierID=${tierID}`);
                const result = await response.json();

                if (!result.success) {
                    showAlert(result.message || 'Failed to load tier data.', 'danger');
                    return;
                }

                const tier = result.data;

                // 🏷️ Update modal title and save button text
                document.getElementById('modalTitleText').textContent = 'Edit Tier: ' + escapeHtml(tier.tierName);
                document.getElementById('saveBtnText').textContent = 'Save Changes';

                // 📝 Populate form fields with existing tier data
                document.getElementById('formTierID').value = tier.tierID;
                document.getElementById('formTierName').value = tier.tierName || '';
                document.getElementById('formTierSlug').value = tier.tierSlug || '';
                document.getElementById('formTierDescription').value = tier.tierDescription || '';
                document.getElementById('formMonthlyPrice').value = parseFloat(tier.monthlyPrice || 0).toFixed(2);
                document.getElementById('formYearlyPrice').value = parseFloat(tier.yearlyPrice || 0).toFixed(2);
                document.getElementById('formCurrency').value = tier.currency || 'GBP';
                document.getElementById('formBillingMode').value = tier.billingMode || 'fixed';
                document.getElementById('formTeamMemberLimit').value = tier.teamMemberLimit ?? '';
                document.getElementById('formApiCallsPerMonth').value = tier.apiCallsPerMonth ?? '';
                document.getElementById('formStorageGB').value = tier.storageGB ?? '';
                document.getElementById('formDisplayOrder').value = tier.displayOrder || 0;
                document.getElementById('formTrialDays').value = tier.trialDays || 0;
                document.getElementById('formBadge').value = tier.badge || '';
                document.getElementById('formBadgeColor').value = tier.badgeColor || '';
                document.getElementById('formIsCustom').checked = !!tier.isCustom;
                document.getElementById('formParentTierID').value = tier.parentTierID || '';
                document.getElementById('formIsDefault').checked = !!tier.isDefault;

                // 🏷️ Populate feature tags
                let features = tier.features;
                if (typeof features === 'string') {
                    try { features = JSON.parse(features); } catch(e) { features = []; }
                }
                currentFeatures = Array.isArray(features) ? [...features] : [];
                renderFeatureTags();

                // ⚙️ Populate feature limit rows
                document.getElementById('featureLimitsContainer').innerHTML = '';
                let featureLimits = tier.featureLimits;
                if (typeof featureLimits === 'string') {
                    try { featureLimits = JSON.parse(featureLimits); } catch(e) { featureLimits = {}; }
                }
                if (featureLimits && typeof featureLimits === 'object') {
                    Object.entries(featureLimits).forEach(([key, value]) => {
                        addFeatureLimitRow(key, value);
                    });
                }

                // 📊 Populate usage limit rows
                document.getElementById('usageLimitsContainer').innerHTML = '';
                let usageLimits = tier.usageLimits;
                if (typeof usageLimits === 'string') {
                    try { usageLimits = JSON.parse(usageLimits); } catch(e) { usageLimits = {}; }
                }
                if (usageLimits && typeof usageLimits === 'object') {
                    Object.entries(usageLimits).forEach(([key, value]) => {
                        addUsageLimitRow(key, value);
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
         * sends a POST request to the API endpoint, and handles the response.
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
            const billingMode = document.getElementById('formBillingMode').value;
            const teamMemberLimit = document.getElementById('formTeamMemberLimit').value.trim();
            const apiCallsPerMonth = document.getElementById('formApiCallsPerMonth').value.trim();
            const storageGB = document.getElementById('formStorageGB').value.trim();
            const displayOrder = parseInt(document.getElementById('formDisplayOrder').value) || 0;
            const trialDays = parseInt(document.getElementById('formTrialDays').value) || 0;
            const badge = document.getElementById('formBadge').value.trim();
            const badgeColor = document.getElementById('formBadgeColor').value;
            const isCustom = document.getElementById('formIsCustom').checked;
            const parentTierID = document.getElementById('formParentTierID').value;
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

            // ⚙️ Collect limits from key-value rows
            const featureLimits = collectFeatureLimits();
            const usageLimits = collectUsageLimits();

            // 📦 Build the request payload
            const payload = {
                action: tierID ? 'update_tier' : 'create_tier',
                csrf_token: csrfToken,
                tierData: {
                    tierName: tierName,
                    tierSlug: tierSlug,
                    tierDescription: tierDescription,
                    monthlyPrice: monthlyPrice,
                    yearlyPrice: yearlyPrice,
                    currency: currency,
                    billingMode: billingMode,
                    features: currentFeatures,
                    featureLimits: Object.keys(featureLimits).length > 0 ? featureLimits : null,
                    usageLimits: Object.keys(usageLimits).length > 0 ? usageLimits : null,
                    teamMemberLimit: teamMemberLimit !== '' ? parseInt(teamMemberLimit) : null,
                    apiCallsPerMonth: apiCallsPerMonth !== '' ? parseInt(apiCallsPerMonth) : null,
                    storageGB: storageGB !== '' ? parseFloat(storageGB) : null,
                    displayOrder: displayOrder,
                    trialDays: trialDays,
                    badge: badge || null,
                    badgeColor: badgeColor || null,
                    isCustom: isCustom,
                    parentTierID: parentTierID ? parseInt(parentTierID) : null,
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
                const response = await fetch(apiEndpoint, {
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
         * @param {number}  tierID   - The ID of the tier to toggle
         * @param {string}  tierName - The tier name (for confirmation dialog)
         * @param {boolean} activate - true to activate, false to deactivate
         */
        async function toggleTierStatus(tierID, tierName, activate) {
            const actionVerb = activate ? 'activate' : 'deactivate';
            if (!confirm(`Are you sure you want to ${actionVerb} the "${tierName}" tier?\n\n${activate
                ? 'This will make the tier visible again.'
                : 'Deactivating will hide this tier from new subscribers, but existing subscriptions continue.'}`)) {
                return;
            }

            try {
                const response = await fetch(apiEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: activate ? 'activate_tier' : 'deactivate_tier',
                        tierID: tierID,
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
         * @param {number} tierID   - The ID of the tier to set as default
         * @param {string} tierName - The tier name (for confirmation dialog)
         */
        async function setDefaultTier(tierID, tierName) {
            if (!confirm(`Set "${tierName}" as the default tier?\n\nNew subscribers will be automatically assigned to this tier. Any existing default tier will be unset.`)) {
                return;
            }

            try {
                const response = await fetch(apiEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'set_default',
                        tierID: tierID,
                        csrf_token: csrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(result.message || `"${escapeHtml(tierName)}" is now the default tier!`, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(result.message || 'Failed to set default tier.', 'danger');
                }
            } catch (error) {
                console.error('Error setting default tier:', error);
                showAlert('Error setting default tier. Please try again.', 'danger');
            }
        }

        // ================================================================
        // 📋 DUPLICATE TIER
        // ================================================================

        /**
         * 📋 Duplicate an existing tier
         *
         * Creates a copy of the specified tier with " (Copy)" appended
         * to the name and a "-copy" suffix on the slug.
         *
         * @param {number} tierID   - The ID of the tier to duplicate
         * @param {string} tierName - The tier name (for confirmation dialog)
         */
        async function duplicateTier(tierID, tierName) {
            if (!confirm(`Duplicate the "${tierName}" tier?\n\nA new tier will be created with the same settings.`)) {
                return;
            }

            try {
                const response = await fetch(apiEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'duplicate_tier',
                        tierID: tierID,
                        csrf_token: csrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(result.message || 'Tier duplicated successfully!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(result.message || 'Failed to duplicate tier.', 'danger');
                }
            } catch (error) {
                console.error('Error duplicating tier:', error);
                showAlert('Error duplicating tier. Please try again.', 'danger');
            }
        }

        // ================================================================
        // 🗑️ DELETE TIER
        // ================================================================

        /**
         * 🗑️ Permanently delete a tier
         *
         * Prompts for double confirmation before sending the delete request.
         * This is a destructive action — the tier cannot be recovered.
         *
         * @param {number} tierID   - The ID of the tier to delete
         * @param {string} tierName - The tier name (for confirmation dialog)
         */
        async function deleteTier(tierID, tierName) {
            if (!confirm(`⚠️ PERMANENTLY DELETE the "${tierName}" tier?\n\nThis cannot be undone. Existing subscriptions on this tier may be affected.`)) {
                return;
            }

            // 🛡️ Double confirmation for safety
            if (!confirm(`This is your FINAL confirmation.\n\nAre you absolutely sure you want to delete "${tierName}"?`)) {
                return;
            }

            try {
                const response = await fetch(apiEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete_tier',
                        tierID: tierID,
                        csrf_token: csrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(result.message || 'Tier deleted successfully!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(result.message || 'Failed to delete tier.', 'danger');
                }
            } catch (error) {
                console.error('Error deleting tier:', error);
                showAlert('Error deleting tier. Please try again.', 'danger');
            }
        }

        // ================================================================
        // 🔄 REORDER TIERS
        // ================================================================

        /**
         * 🔄 Reorder tiers by updating their display order values
         *
         * Sends an array of {tierID, displayOrder} objects to the API.
         * Called after drag-and-drop reordering (if implemented) or
         * manually via the edit modal.
         *
         * @param {Array<{tierID: number, displayOrder: number}>} orderData - New order
         */
        async function reorderTiers(orderData) {
            try {
                const response = await fetch(apiEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'reorder_tiers',
                        orders: orderData,
                        csrf_token: csrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(result.message || 'Tier order updated!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(result.message || 'Failed to reorder tiers.', 'danger');
                }
            } catch (error) {
                console.error('Error reordering tiers:', error);
                showAlert('Error reordering tiers. Please try again.', 'danger');
            }
        }

        // ================================================================
        // ⚙️ SAVE TIER SETTINGS
        // ================================================================

        /**
         * ⚙️ Save the tier settings form (tiers.custom.* settings)
         *
         * Collects all settings values and sends them to the API
         * for batch updating in the tblSettings table.
         */
        async function saveSettings() {
            const saveBtn = document.getElementById('saveSettingsBtn');
            const originalBtnHTML = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';

            // 📝 Collect settings values
            const settings = {
                'tiers.custom.enabled': document.getElementById('settingCustomEnabled').checked ? 'true' : 'false',
                'tiers.custom.max_per_partner': document.getElementById('settingMaxPerPartner').value.trim() || '10',
                'tiers.custom.require_approval': document.getElementById('settingRequireApproval').checked ? 'true' : 'false'
            };

            try {
                const response = await fetch(apiEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_tier_settings',
                        settings: settings,
                        csrf_token: document.getElementById('settingsCsrfToken').value
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(result.message || 'Settings saved successfully!', 'success');
                } else {
                    showAlert(result.message || 'Failed to save settings.', 'danger');
                }
            } catch (error) {
                console.error('Error saving settings:', error);
                showAlert('Error saving settings. Please try again.', 'danger');
            } finally {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalBtnHTML;
            }
        }

        // ================================================================
        // 📡 LOAD TIERS (AJAX REFRESH)
        // ================================================================

        /**
         * 📡 Reload all tier data via AJAX without full page refresh
         *
         * Fetches updated tier data from the API and refreshes the
         * global tier grid and custom tier DataTable.
         */
        async function loadTiers() {
            try {
                const response = await fetch(`${apiEndpoint}?action=list_tiers`);
                const result = await response.json();

                if (result.success) {
                    // 🔄 For a full refresh, reload the page
                    // Future enhancement: dynamically update DOM elements
                    location.reload();
                } else {
                    showAlert(result.message || 'Failed to load tiers.', 'danger');
                }
            } catch (error) {
                console.error('Error loading tiers:', error);
                showAlert('Error loading tiers. Please try again.', 'danger');
            }
        }
    </script>
</body>
</html>
