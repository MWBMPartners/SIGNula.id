<?php
/**
 * ============================================================================
 * 🌍 SIGNula - Compliance Regime Admin CRUD (G-004 Layer 3 §4.3)
 * ============================================================================
 *
 * Purpose: Super-Admin management screen over the data-driven regime model
 *          migration 042 created (`tblComplianceRegimes` + `tblRegimeRights`
 *          + `tblRegimeDisclosures` + `tblRegimeResolution`) — the tables
 *          `RegimeResolver` (web/private_html/compliance/RegimeResolver.php)
 *          reads for LIVE resolution, active-only. This page is the OTHER
 *          side of that coin: it shows/edits EVERY row, including the 19
 *          seeded regimes that ship `isActive=0, isDraft=1` with every legal
 *          value column NULL, so an operator can fill them in and — only once
 *          all three of dsarSlaDays/minAge/breachNotifyHours are non-NULL —
 *          flip a regime live.
 *
 * Design notes:
 * - The LIST + DETAIL VIEW are server-rendered from a plain GET query
 *   parameter (`?regimeID=`) — this page is fully usable with JavaScript
 *   disabled for VIEWING (per the project brief's "AJAX should not be a
 *   blocker" requirement). Only the MUTATING actions (create/update/
 *   activate/rights/disclosures/resolution rules) are AJAX, mirroring the
 *   established admin-page convention (see admin/compliance/dsar/index.php).
 * - "Cannot activate" is shown here ONLY as a UI hint (disabled button +
 *   missing-field list) computed by the LOCAL regimePageMissingActivationFields()
 *   helper below — that helper is NOT the authoritative gate. The real,
 *   only-source-of-truth enforcement is server-side in
 *   regime-actions.php::regimeCanActivate(), which re-reads the stored row
 *   before ever flipping isActive. A disabled button can always be bypassed
 *   client-side; the endpoint cannot.
 * - This page NEVER pre-fills a regime's legal values or a disclosure's
 *   title/body — every form field for those starts EMPTY (new regime/new
 *   disclosure) or shows EXACTLY what an operator has already saved (never
 *   a fabricated suggestion). The `notes` column MAY already contain
 *   migration 042's own "confirm with legal" suggested-anchor text — that is
 *   pre-existing seed data, not something this page writes.
 *
 * Database Tables: tblComplianceRegimes, tblRegimeRights, tblRegimeDisclosures,
 *                   tblRegimeResolution
 * API Endpoint:    ../api/regime-actions.php
 * Authentication:  Super Admin only (AccessControl::requireSuperAdmin())
 *
 * PHP Version: 8.3+
 *
 * @see web/private_html/compliance/RegimeResolver.php
 * @see web/public_html/admin/compliance/api/regime-actions.php
 * @see web/public_html/admin/compliance/dsar/index.php — sibling admin page this mirrors
 * @see _database/migrations/042_compliance_regimes.sql
 * @see .dev-team/specs/G-004.md §4.3, §4.4
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package    SIGNula
 * @subpackage Admin\Compliance\Regimes
 * @version    1.0.0
 * @since      2.7.0-beta
 * ============================================================================
 */

// ============================================================================
// 🛡️ SIGNULA_INIT GUARD — Prevent direct access
// ============================================================================

// ⚙️ Main configuration (defines SIGNULA_INIT constant, DB credentials, etc.)
// ⚠️ PATH DEPTH: __DIR__ here is web/public_html/admin/compliance/regimes —
// FOUR levels below web/ (public_html -> admin -> compliance -> regimes), the
// SAME depth as the sibling admin/compliance/dsar/index.php (compliance/dsar
// and compliance/regimes are both two levels below admin/), so this page
// needs dirname(__DIR__, 4), identical to that sibling. Verified at build
// time — see the build report for the literal resolved path proof.
// @see https://www.php.net/manual/en/function.dirname.php
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🗄️ Database singleton (MySQLi prepared statements)
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

// 🎫 Session manager (authentication state)
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// 🔐 Access control (role-based permissions)
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// ============================================================================
// 🔐 AUTHENTICATION & AUTHORIZATION
// ============================================================================

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check — user must be logged in
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin gate — regime configuration governs legal SLA/age/breach
// values for every future data-subject request (spec §6: "all admin
// compliance UI/endpoints gate via AccessControl; require super-admin").
// die()s with 403 internally if not satisfied.
$accessControl->requireSuperAdmin();

// 🛡️ Clickjacking defence on this compliance-data admin surface (spec §6 /
// house lesson #29) — every admin compliance page/endpoint sets this.
header('X-Frame-Options: DENY');

// ============================================================================
// 🧮 DISPLAY-ONLY ACTIVATION HINT — NOT the authoritative gate
// ============================================================================

/**
 * 🧮 Which Required Activation Fields Are Still Missing? (UI hint only)
 *
 * Deliberately duplicated (not shared) from regime-actions.php's
 * regimeCanActivate() — this copy exists ONLY to grey out the Activate
 * button and show a "still missing: ..." hint; it has ZERO enforcement
 * authority. The real gate lives entirely in regime-actions.php's
 * handleActivateRegime(), which re-reads the row from the database itself
 * before ever flipping isActive — a disabled button here changes nothing
 * about what the server will accept.
 *
 * @param array<string,mixed> $row A tblComplianceRegimes row
 * @return array<int,string> Missing field names (empty = activatable)
 */
function regimePageMissingActivationFields(array $row): array
{
    $missing = [];
    foreach (['dsarSlaDays', 'minAge', 'breachNotifyHours'] as $field) {
        if (!array_key_exists($field, $row) || $row[$field] === null) {
            $missing[] = $field;
        }
    }

    return $missing;
}

/**
 * 🎨 Build A Bootstrap Badge Class For A Regime's Active/Draft State
 *
 * @param array<string,mixed> $regime
 * @return array{class:string,label:string}
 */
function regimeStatusBadge(array $regime): array
{
    if (!empty($regime['isActive'])) {
        return ['class' => 'success', 'label' => 'Active'];
    }
    if (!empty($regime['isDraft'])) {
        return ['class' => 'warning', 'label' => 'Draft'];
    }

    return ['class' => 'secondary', 'label' => 'Inactive'];
}

// ============================================================================
// 📋 LIST — EVERY regime row, incl draft/inactive (admin sees ALL of them)
// ============================================================================

$regimes = Database::fetchAll("SELECT * FROM tblComplianceRegimes ORDER BY regimeCode ASC", [], '');

$totalCount = count($regimes);
$activeCount = 0;
$draftCount = 0;
$incompleteCount = 0;
foreach ($regimes as $regimeRow) {
    if (!empty($regimeRow['isActive'])) {
        $activeCount++;
    }
    if (!empty($regimeRow['isDraft'])) {
        $draftCount++;
    }
    if (!empty(regimePageMissingActivationFields($regimeRow))) {
        $incompleteCount++;
    }
}

// ============================================================================
// 🔍 DETAIL VIEW — server-rendered from ?regimeID=N (no JS required to view)
// ============================================================================

$detailRegime = null;
$detailRights = [];
$detailDisclosures = [];
$detailResolutionRules = [];
$viewRegimeID = (int) ($_GET['regimeID'] ?? 0);

if ($viewRegimeID > 0) {
    $detailRegime = Database::fetchOne("SELECT * FROM tblComplianceRegimes WHERE regimeID = ?", [$viewRegimeID], 'i');

    if ($detailRegime) {
        $rightRows = Database::fetchAll(
            "SELECT `right` FROM tblRegimeRights WHERE regimeID = ? ORDER BY `right` ASC",
            [$viewRegimeID],
            'i'
        );
        $detailRights = array_map(static fn(array $r): string => (string) $r['right'], $rightRows);

        $detailDisclosures = Database::fetchAll(
            "SELECT * FROM tblRegimeDisclosures WHERE regimeID = ? ORDER BY disclosureKey ASC",
            [$viewRegimeID],
            'i'
        );

        $detailResolutionRules = Database::fetchAll(
            "SELECT * FROM tblRegimeResolution WHERE regimeID = ? ORDER BY priority ASC, ruleID ASC",
            [$viewRegimeID],
            'i'
        );
    }
}

// ✅ The DSAR-right union (mirrors DSARManager::VALID_REQUEST_TYPES /
// tblDataSubjectRequests.requestType — migration 040) — drives the rights
// checkbox list.
$REQUEST_TYPE_OPTIONS = [
    'access', 'portability', 'erasure', 'rectification', 'restriction',
    'object', 'do_not_sell', 'withdraw_consent', 'automated_decision_optout',
];

// ✅ tblRegimeResolution.matchType ENUM (migration 042).
$MATCH_TYPE_OPTIONS = ['country', 'region', 'partner', 'default'];

$csrfToken = SecurityUtils::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Compliance Regimes - SIGNula Admin</title>

    <!-- 🎨 Bootstrap 5.3.2 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- 🎨 FontAwesome 6.4.2 -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <style>
        body { background-color: #f8f9fa; }
        .page-header {
            background: linear-gradient(135deg, #4361ee, #4895ef);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            text-align: center;
        }
        .stat-card .stat-number { font-size: 1.9rem; font-weight: 700; line-height: 1; }
        .stat-card .stat-label { color: #6c757d; font-size: 0.82rem; margin-top: 0.4rem; }
        .table-card { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
        .table-header { background: linear-gradient(135deg, #4361ee, #4895ef); color: white; padding: 1rem 1.5rem; }
        .row-incomplete { background-color: #fffaf0 !important; }
        .toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 9999; }
        .filter-card { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 1.25rem; margin-bottom: 1.5rem; }
        .sub-panel { border: 1px solid #e9ecef; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; background: #fbfbfd; }
    </style>
</head>
<body>

<div class="toast-container" id="alertContainer"></div>

<div class="container-fluid py-4">

    <!-- ==================================================================== -->
    <!-- 🎨 PAGE HEADER -->
    <!-- ==================================================================== -->
    <div class="page-header">
        <a href="/admin" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
            <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
        </a>
        <h1 class="mb-2"><i class="fas fa-globe me-2"></i>Compliance Regimes</h1>
        <p class="mb-0 opacity-75">Configure the data-protection regimes (GDPR, CCPA, LGPD, ...) that govern DSAR SLAs, minimum age, and breach-notification windows. A regime cannot go live until its legal values are confirmed.</p>
    </div>

    <!-- ==================================================================== -->
    <!-- 📊 SUMMARY STATS -->
    <!-- ==================================================================== -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-number text-primary"><?php echo (int) $totalCount; ?></div>
                <div class="stat-label"><i class="fas fa-list me-1"></i>Total Regimes</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-number text-success"><?php echo (int) $activeCount; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle me-1"></i>Active</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-number text-warning"><?php echo (int) $draftCount; ?></div>
                <div class="stat-label"><i class="fas fa-file-pen me-1"></i>Draft</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-number text-danger"><?php echo (int) $incompleteCount; ?></div>
                <div class="stat-label"><i class="fas fa-triangle-exclamation me-1"></i>Incomplete (cannot activate)</div>
            </div>
        </div>
    </div>

    <!-- ==================================================================== -->
    <!-- ➕ ADD NEW REGIME -->
    <!-- ==================================================================== -->
    <div class="filter-card">
        <h5 class="mb-3"><i class="fas fa-plus me-2"></i>Add New Regime</h5>
        <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="newRegimeCode" class="form-label small fw-bold">Regime Code</label>
                <input type="text" class="form-control" id="newRegimeCode" maxlength="16" placeholder="e.g. GDPR" style="text-transform:uppercase;">
                <div class="form-text">1-16 uppercase letters/digits/underscore. Immutable once created.</div>
            </div>
            <div class="col-md-4">
                <label for="newRegimeDisplayName" class="form-label small fw-bold">Display Name</label>
                <input type="text" class="form-control" id="newRegimeDisplayName" maxlength="128" placeholder="e.g. General Data Protection Regulation">
            </div>
            <div class="col-md-3">
                <label for="newRegimeJurisdiction" class="form-label small fw-bold">Jurisdiction</label>
                <input type="text" class="form-control" id="newRegimeJurisdiction" maxlength="64" placeholder="e.g. EU/EEA">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-primary w-100" onclick="createRegime()">
                    <i class="fas fa-plus me-1"></i>Create
                </button>
            </div>
        </div>
        <div class="form-text mt-2">A new regime always ships as a Draft with every legal value blank — nothing is pre-filled.</div>
    </div>

    <!-- ==================================================================== -->
    <!-- 📋 REGIMES TABLE -->
    <!-- ==================================================================== -->
    <div class="card table-card mb-4">
        <div class="table-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Regimes</h5>
            <span class="badge bg-light text-dark"><?php echo (int) $totalCount; ?> total</span>
        </div>
        <div class="card-body">
            <?php if (empty($regimes)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-globe fa-3x mb-3"></i>
                <p>No regimes configured yet.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Display Name</th>
                            <th>Jurisdiction</th>
                            <th>Status</th>
                            <th>SLA Days</th>
                            <th>Min Age</th>
                            <th>Breach Hrs</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regimes as $row): ?>
                        <?php
                            $rowRegimeID = (int) $row['regimeID'];
                            $badge = regimeStatusBadge($row);
                            $missingFields = regimePageMissingActivationFields($row);
                            $isIncomplete = !empty($missingFields);
                        ?>
                        <tr class="<?php echo $isIncomplete ? 'row-incomplete' : ''; ?>">
                            <td><code><?php echo htmlspecialchars((string) $row['regimeCode'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo htmlspecialchars((string) $row['displayName'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($row['jurisdiction'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $badge['class']; ?>"><?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($isIncomplete): ?>
                                <span class="badge bg-danger" title="Missing: <?php echo htmlspecialchars(implode(', ', $missingFields), ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas fa-triangle-exclamation"></i> Incomplete
                                </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $row['dsarSlaDays'] !== null ? (int) $row['dsarSlaDays'] : '<span class="text-muted">—</span>'; ?></td>
                            <td><?php echo $row['minAge'] !== null ? (int) $row['minAge'] : '<span class="text-muted">—</span>'; ?></td>
                            <td><?php echo $row['breachNotifyHours'] !== null ? (int) $row['breachNotifyHours'] : '<span class="text-muted">—</span>'; ?></td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="?regimeID=<?php echo $rowRegimeID; ?>#detail">
                                    <i class="fas fa-pen-to-square me-1"></i>Edit
                                </a>
                                <?php if (!empty($row['isActive'])): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deactivateRegime(<?php echo $rowRegimeID; ?>)">
                                    <i class="fas fa-power-off me-1"></i>Deactivate
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="activateRegime(<?php echo $rowRegimeID; ?>)" <?php echo $isIncomplete ? 'disabled title="Fill in: ' . htmlspecialchars(implode(', ', $missingFields), ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                                    <i class="fas fa-check me-1"></i>Activate
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================================================================== -->
    <!-- 🔍 DETAIL VIEW -->
    <!-- ==================================================================== -->
    <?php if ($viewRegimeID > 0): ?>
    <div id="detail" class="card table-card mb-4">
        <div class="table-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-folder-open me-2"></i>Regime #<?php echo $viewRegimeID; ?></h5>
        </div>
        <div class="card-body">
            <?php if (!$detailRegime): ?>
            <div class="alert alert-warning mb-0"><i class="fas fa-triangle-exclamation me-2"></i>Regime not found.</div>
            <?php else: ?>
            <?php
                $dMissing = regimePageMissingActivationFields($detailRegime);
                $dIsIncomplete = !empty($dMissing);
                $dBadge = regimeStatusBadge($detailRegime);
            ?>
            <input type="hidden" id="detailRegimeID" value="<?php echo $viewRegimeID; ?>">

            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="badge bg-<?php echo $dBadge['class']; ?> fs-6"><?php echo htmlspecialchars($dBadge['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                <code class="fs-6"><?php echo htmlspecialchars((string) $detailRegime['regimeCode'], ENT_QUOTES, 'UTF-8'); ?></code>
                <?php if (empty($detailRegime['isActive'])): ?>
                <button type="button" class="btn btn-sm btn-success" onclick="activateRegime(<?php echo $viewRegimeID; ?>)" <?php echo $dIsIncomplete ? 'disabled' : ''; ?>>
                    <i class="fas fa-check me-1"></i>Activate
                </button>
                <?php if ($dIsIncomplete): ?>
                <span class="text-danger small"><i class="fas fa-triangle-exclamation me-1"></i>Still missing: <?php echo htmlspecialchars(implode(', ', $dMissing), ENT_QUOTES, 'UTF-8'); ?> (this is a UI hint only — the server re-checks the stored row independently)</span>
                <?php endif; ?>
                <?php else: ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deactivateRegime(<?php echo $viewRegimeID; ?>)">
                    <i class="fas fa-power-off me-1"></i>Deactivate
                </button>
                <?php endif; ?>
            </div>

            <!-- ✏️ REGIME EDIT FORM -->
            <h6 class="mb-3"><i class="fas fa-pen-to-square me-2"></i>Regime Details</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label for="editDisplayName" class="form-label small fw-bold">Display Name</label>
                    <input type="text" class="form-control" id="editDisplayName" maxlength="128"
                           value="<?php echo htmlspecialchars((string) $detailRegime['displayName'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-6">
                    <label for="editJurisdiction" class="form-label small fw-bold">Jurisdiction</label>
                    <input type="text" class="form-control" id="editJurisdiction" maxlength="64"
                           value="<?php echo htmlspecialchars((string) ($detailRegime['jurisdiction'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label for="editDsarSlaDays" class="form-label small fw-bold">DSAR SLA (days) <span class="text-danger">*</span></label>
                    <input type="number" min="0" class="form-control" id="editDsarSlaDays"
                           value="<?php echo htmlspecialchars((string) ($detailRegime['dsarSlaDays'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label for="editDsarExtensionDays" class="form-label small fw-bold">SLA Extension (days)</label>
                    <input type="number" min="0" class="form-control" id="editDsarExtensionDays"
                           value="<?php echo htmlspecialchars((string) ($detailRegime['dsarExtensionDays'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label for="editMinAge" class="form-label small fw-bold">Minimum Age <span class="text-danger">*</span></label>
                    <input type="number" min="0" class="form-control" id="editMinAge"
                           value="<?php echo htmlspecialchars((string) ($detailRegime['minAge'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label for="editBreachNotifyHours" class="form-label small fw-bold">Breach Notify (hours) <span class="text-danger">*</span></label>
                    <input type="number" min="0" class="form-control" id="editBreachNotifyHours"
                           value="<?php echo htmlspecialchars((string) ($detailRegime['breachNotifyHours'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-8">
                    <label for="editBreachNotifyAuthority" class="form-label small fw-bold">Breach Notify Authority</label>
                    <input type="text" class="form-control" id="editBreachNotifyAuthority" maxlength="255"
                           value="<?php echo htmlspecialchars((string) ($detailRegime['breachNotifyAuthority'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-12">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="editHonorsGPC" <?php echo !empty($detailRegime['honorsGPC']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="editHonorsGPC">Honors Global Privacy Control</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="editRequiresDoNotSell" <?php echo !empty($detailRegime['requiresDoNotSell']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="editRequiresDoNotSell">Requires Do-Not-Sell mechanism</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="editLawfulBasisRequired" <?php echo !empty($detailRegime['lawfulBasisRequired']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="editLawfulBasisRequired">Requires recorded lawful basis</label>
                    </div>
                </div>
                <div class="col-md-12">
                    <label for="editNotes" class="form-label small fw-bold">Notes</label>
                    <textarea class="form-control" id="editNotes" rows="3"><?php echo htmlspecialchars((string) ($detailRegime['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="col-12">
                    <button type="button" class="btn btn-primary" onclick="saveRegimeDetails()">
                        <i class="fas fa-floppy-disk me-1"></i>Save Regime Details
                    </button>
                </div>
            </div>

            <!-- ☑️ RIGHTS -->
            <h6 class="mb-3"><i class="fas fa-scale-balanced me-2"></i>Rights Granted</h6>
            <div class="sub-panel">
                <?php foreach ($REQUEST_TYPE_OPTIONS as $rightOption): ?>
                <div class="form-check form-check-inline">
                    <input class="form-check-input regime-right-checkbox" type="checkbox"
                           id="right_<?php echo htmlspecialchars($rightOption, ENT_QUOTES, 'UTF-8'); ?>"
                           value="<?php echo htmlspecialchars($rightOption, ENT_QUOTES, 'UTF-8'); ?>"
                           <?php echo in_array($rightOption, $detailRights, true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="right_<?php echo htmlspecialchars($rightOption, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $rightOption)), ENT_QUOTES, 'UTF-8'); ?>
                    </label>
                </div>
                <?php endforeach; ?>
                <div class="mt-2">
                    <button type="button" class="btn btn-sm btn-primary" onclick="saveRights()">
                        <i class="fas fa-floppy-disk me-1"></i>Save Rights
                    </button>
                </div>
            </div>

            <!-- 📜 DISCLOSURES -->
            <h6 class="mb-3"><i class="fas fa-file-contract me-2"></i>Required Disclosures</h6>
            <div class="sub-panel">
                <?php if (empty($detailDisclosures)): ?>
                <p class="text-muted small mb-3">No disclosure sections yet — add one below. Title/body are entered by you (or your legal team); nothing is pre-filled.</p>
                <?php else: ?>
                <?php foreach ($detailDisclosures as $disclosure): ?>
                <div class="mb-3 border-bottom pb-3">
                    <label class="form-label small fw-bold">Key: <code><?php echo htmlspecialchars((string) $disclosure['disclosureKey'], ENT_QUOTES, 'UTF-8'); ?></code></label>
                    <input type="hidden" class="disclosure-key-input" value="<?php echo htmlspecialchars((string) $disclosure['disclosureKey'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="text" class="form-control mb-2 disclosure-title-input" maxlength="255" placeholder="Section title"
                           value="<?php echo htmlspecialchars((string) ($disclosure['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <textarea class="form-control mb-2 disclosure-body-input" rows="3" placeholder="Disclosure text — entered by you/legal, never auto-generated"><?php echo htmlspecialchars((string) ($disclosure['body'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="saveDisclosureFromRow(this)">
                        <i class="fas fa-floppy-disk me-1"></i>Save This Disclosure
                    </button>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <div class="mt-3">
                    <p class="fw-bold small mb-2">Add New Disclosure</p>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <input type="text" class="form-control form-control-sm" id="newDisclosureKey" maxlength="64" placeholder="disclosure key (e.g. categories_collected)">
                        </div>
                        <div class="col-md-4">
                            <input type="text" class="form-control form-control-sm" id="newDisclosureTitle" maxlength="255" placeholder="Section title">
                        </div>
                        <div class="col-md-5">
                            <textarea class="form-control form-control-sm" id="newDisclosureBody" rows="2" placeholder="Disclosure text (empty by default — enter your own/legal copy)"></textarea>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary mt-2" onclick="addNewDisclosure()">
                        <i class="fas fa-plus me-1"></i>Add Disclosure
                    </button>
                </div>
            </div>

            <!-- 🗺️ RESOLUTION RULES -->
            <h6 class="mb-3"><i class="fas fa-map me-2"></i>Resolution Rules Targeting This Regime</h6>
            <div class="sub-panel">
                <?php if (empty($detailResolutionRules)): ?>
                <p class="text-muted small mb-3">No resolution rules target this regime yet — a rule maps a country/region/partner (or the global default) to this regime.</p>
                <?php else: ?>
                <div class="table-responsive mb-3">
                    <table class="table table-sm">
                        <thead><tr><th>Match Type</th><th>Match Value</th><th>Priority</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($detailResolutionRules as $rule): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $rule['matchType'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($rule['matchValue'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo (int) $rule['priority']; ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteResolutionRule(<?php echo (int) $rule['ruleID']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <p class="fw-bold small mb-2">Add New Resolution Rule</p>
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small">Match Type</label>
                        <select class="form-select form-select-sm" id="newRuleMatchType">
                            <?php foreach ($MATCH_TYPE_OPTIONS as $matchTypeOption): ?>
                            <option value="<?php echo htmlspecialchars($matchTypeOption, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($matchTypeOption), ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Match Value</label>
                        <input type="text" class="form-control form-control-sm" id="newRuleMatchValue" maxlength="64" placeholder="e.g. DE, EU, partnerID">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Priority (lower = first)</label>
                        <input type="number" class="form-control form-control-sm" id="newRulePriority" value="100">
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-sm btn-primary w-100" onclick="addResolutionRule()">
                            <i class="fas fa-plus me-1"></i>Add
                        </button>
                    </div>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.container-fluid -->

<script>
    // ============================================================================
    // 🔧 CONFIGURATION
    // ============================================================================
    const apiEndpoint = '../api/regime-actions.php';

    // ============================================================================
    // 🔔 ALERT HELPER (mirrors admin/compliance/dsar/index.php's showAlert())
    // ============================================================================
    function showAlert(message, type) {
        type = type || 'info';
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `${escapeHtml(message)}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
        document.getElementById('alertContainer').appendChild(alert);
        setTimeout(() => alert.remove(), 6000);
    }

    // 🛡️ HTML-escape utility
    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    function getCsrfToken() {
        const el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    function getDetailRegimeID() {
        const el = document.getElementById('detailRegimeID');
        return el ? parseInt(el.value, 10) : 0;
    }

    // ============================================================================
    // 📡 SHARED POST HELPER
    // ============================================================================
    async function postAction(action, payload, successMessage) {
        try {
            const response = await fetch(apiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.assign({ action: action, csrf_token: getCsrfToken() }, payload)),
            });
            const result = await response.json();

            if (result.success) {
                showAlert(successMessage, 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                showAlert(result.error || result.message || 'Action failed.', 'danger');
            }
        } catch (error) {
            console.error('Regime admin action failed:', error);
            showAlert('Network error — please try again.', 'danger');
        }
    }

    // ============================================================================
    // ➕ CREATE REGIME
    // ============================================================================
    async function createRegime() {
        const regimeCode = document.getElementById('newRegimeCode').value.trim().toUpperCase();
        const displayName = document.getElementById('newRegimeDisplayName').value.trim();
        const jurisdiction = document.getElementById('newRegimeJurisdiction').value.trim();

        if (!regimeCode || !displayName) {
            showAlert('regimeCode and displayName are required.', 'warning');
            return;
        }

        await postAction('create_regime', { regimeCode: regimeCode, displayName: displayName, jurisdiction: jurisdiction }, 'Regime created.');
    }

    // ============================================================================
    // ✏️ SAVE REGIME DETAILS
    // ============================================================================
    async function saveRegimeDetails() {
        const regimeID = getDetailRegimeID();

        const payload = {
            regimeID: regimeID,
            displayName: document.getElementById('editDisplayName').value.trim(),
            jurisdiction: document.getElementById('editJurisdiction').value.trim(),
            dsarSlaDays: document.getElementById('editDsarSlaDays').value.trim(),
            dsarExtensionDays: document.getElementById('editDsarExtensionDays').value.trim(),
            minAge: document.getElementById('editMinAge').value.trim(),
            breachNotifyHours: document.getElementById('editBreachNotifyHours').value.trim(),
            breachNotifyAuthority: document.getElementById('editBreachNotifyAuthority').value.trim(),
            honorsGPC: document.getElementById('editHonorsGPC').checked ? 1 : 0,
            requiresDoNotSell: document.getElementById('editRequiresDoNotSell').checked ? 1 : 0,
            lawfulBasisRequired: document.getElementById('editLawfulBasisRequired').checked ? 1 : 0,
            notes: document.getElementById('editNotes').value,
        };

        await postAction('update_regime', payload, 'Regime details saved.');
    }

    // ============================================================================
    // ☑️ SAVE RIGHTS
    // ============================================================================
    async function saveRights() {
        const regimeID = getDetailRegimeID();
        const rights = Array.from(document.querySelectorAll('.regime-right-checkbox:checked')).map(el => el.value);

        await postAction('set_rights', { regimeID: regimeID, rights: rights }, 'Rights saved.');
    }

    // ============================================================================
    // 📜 DISCLOSURES
    // ============================================================================
    async function saveDisclosureFromRow(buttonEl) {
        const regimeID = getDetailRegimeID();
        const row = buttonEl.closest('div.mb-3');
        const disclosureKey = row.querySelector('.disclosure-key-input').value;
        const title = row.querySelector('.disclosure-title-input').value;
        const body = row.querySelector('.disclosure-body-input').value;

        await postAction('save_disclosure', { regimeID: regimeID, disclosureKey: disclosureKey, title: title, body: body }, 'Disclosure saved.');
    }

    async function addNewDisclosure() {
        const regimeID = getDetailRegimeID();
        const disclosureKey = document.getElementById('newDisclosureKey').value.trim();
        const title = document.getElementById('newDisclosureTitle').value;
        const body = document.getElementById('newDisclosureBody').value;

        if (!disclosureKey) {
            showAlert('disclosureKey is required.', 'warning');
            return;
        }

        await postAction('save_disclosure', { regimeID: regimeID, disclosureKey: disclosureKey, title: title, body: body }, 'Disclosure added.');
    }

    // ============================================================================
    // 🗺️ RESOLUTION RULES
    // ============================================================================
    async function addResolutionRule() {
        const regimeID = getDetailRegimeID();
        const matchType = document.getElementById('newRuleMatchType').value;
        const matchValue = document.getElementById('newRuleMatchValue').value.trim();
        const priority = document.getElementById('newRulePriority').value;

        await postAction('save_resolution', { regimeID: regimeID, matchType: matchType, matchValue: matchValue, priority: priority }, 'Resolution rule added.');
    }

    async function deleteResolutionRule(ruleID) {
        const regimeID = getDetailRegimeID();
        if (!confirm('Delete this resolution rule?')) {
            return;
        }

        await postAction('delete_resolution', { regimeID: regimeID, ruleID: ruleID }, 'Resolution rule deleted.');
    }

    // ============================================================================
    // 🟢 ACTIVATE / 🔴 DEACTIVATE
    // ============================================================================
    async function activateRegime(regimeID) {
        await postAction('activate_regime', { regimeID: regimeID }, 'Regime activated.');
    }

    async function deactivateRegime(regimeID) {
        if (!confirm('Deactivate this regime? Live requests will stop using it immediately.')) {
            return;
        }
        await postAction('deactivate_regime', { regimeID: regimeID }, 'Regime deactivated.');
    }
</script>

</body>
</html>
