<?php
/**
 * Global Feature Toggles Management (Super Admin)
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 3) . '/_config/config.php';
require_once dirname(__DIR__, 3) . '/_backend/Database.php';
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';
require_once dirname(__DIR__, 3) . '/_backend/AccessControl.php';

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// Require super admin access
$accessControl->requireSuperAdmin();

// Get all features
$features = $db->query("
    SELECT * FROM tblFeatureToggles
    ORDER BY category, featureName
")->fetch_all(MYSQLI_ASSOC);

// Group features by category
$featuresByCategory = [];
foreach ($features as $feature) {
    $category = $feature['category'] ?: 'Other';
    $featuresByCategory[$category][] = $feature;
}

// Get partner override counts
$overrideCounts = [];
foreach ($features as $feature) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as count
        FROM tblPartnerFeatures
        WHERE featureID = ?
    ");
    $stmt->bind_param('i', $feature['featureID']);
    $stmt->execute();
    $overrideCounts[$feature['featureID']] = $stmt->get_result()->fetch_assoc()['count'];
}

// Get total partner count
$totalPartners = $db->query("SELECT COUNT(*) as count FROM tblPartners WHERE status = 'active'")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global Feature Management - SIGNula Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .admin-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        .category-section {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        .category-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
        }
        .feature-row {
            padding: 1.5rem;
            border-bottom: 1px solid #e0e0e0;
            transition: background-color 0.2s;
        }
        .feature-row:last-child {
            border-bottom: none;
        }
        .feature-row:hover {
            background-color: #f8f9fa;
        }
        .feature-toggle {
            width: 60px;
            height: 30px;
            position: relative;
            display: inline-block;
        }
        .feature-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .toggle-slider {
            background-color: #28a745;
        }
        input:checked + .toggle-slider:before {
            transform: translateX(30px);
        }
        .override-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            background: #e3f2fd;
            color: #1976d2;
            cursor: pointer;
        }
        .override-badge:hover {
            background: #bbdefb;
        }
        .stats-card {
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="admin-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <a href="../index.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                        <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
                    </a>
                    <h1><i class="fas fa-toggle-on me-2"></i>Global Feature Management</h1>
                    <p class="mb-0 opacity-75">Control features across the entire SIGNula platform</p>
                </div>
                <div class="col-md-4">
                    <div class="stats-card">
                        <h3 class="mb-0"><?php echo $totalPartners; ?></h3>
                        <small>Active Partners</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <div id="alertContainer"></div>

        <!-- Features by Category -->
        <?php foreach ($featuresByCategory as $category => $categoryFeatures): ?>
        <div class="category-section">
            <div class="category-header">
                <i class="fas fa-folder me-2"></i><?php echo htmlspecialchars($category); ?>
            </div>
            <div class="category-body">
                <?php foreach ($categoryFeatures as $feature): ?>
                <div class="feature-row">
                    <div class="row align-items-center">
                        <div class="col-md-5">
                            <h6 class="mb-1"><?php echo htmlspecialchars($feature['featureName']); ?></h6>
                            <small class="text-muted">
                                <?php echo htmlspecialchars($feature['description'] ?: 'No description available'); ?>
                            </small>
                            <br>
                            <small class="text-muted">
                                <code><?php echo htmlspecialchars($feature['featureKey']); ?></code>
                            </small>
                        </div>
                        <div class="col-md-2 text-center">
                            <label class="feature-toggle">
                                <input type="checkbox"
                                       data-feature-id="<?php echo $feature['featureID']; ?>"
                                       <?php echo $feature['isEnabledGlobally'] ? 'checked' : ''; ?>
                                       onchange="toggleGlobal(<?php echo $feature['featureID']; ?>, this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <br>
                            <small class="text-muted">
                                <?php echo $feature['isEnabledGlobally'] ? 'Enabled' : 'Disabled'; ?>
                            </small>
                        </div>
                        <div class="col-md-2 text-center">
                            <label class="feature-toggle">
                                <input type="checkbox"
                                       data-feature-id="<?php echo $feature['featureID']; ?>"
                                       <?php echo $feature['canPartnersToggle'] ? 'checked' : ''; ?>
                                       onchange="togglePartnerControl(<?php echo $feature['featureID']; ?>, this.checked)">
                                <span class="toggle-slider" style="background-color: <?php echo $feature['canPartnersToggle'] ? '#2196f3' : '#ccc'; ?>;"></span>
                            </label>
                            <br>
                            <small class="text-muted">Partners Control</small>
                        </div>
                        <div class="col-md-3 text-end">
                            <?php
                            $overrideCount = $overrideCounts[$feature['featureID']];
                            if ($overrideCount > 0):
                            ?>
                            <span class="override-badge" onclick="viewOverrides(<?php echo $feature['featureID']; ?>, '<?php echo htmlspecialchars($feature['featureName'], ENT_QUOTES); ?>')">
                                <i class="fas fa-list me-1"></i>
                                <?php echo $overrideCount; ?> Override<?php echo $overrideCount > 1 ? 's' : ''; ?>
                            </span>
                            <?php else: ?>
                            <small class="text-muted">No overrides</small>
                            <?php endif; ?>
                            <br>
                            <button class="btn btn-sm btn-outline-primary mt-2" onclick="managePartners(<?php echo $feature['featureID']; ?>, '<?php echo htmlspecialchars($feature['featureName'], ENT_QUOTES); ?>')">
                                <i class="fas fa-cog me-1"></i>Manage Partners
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Legend -->
        <div class="card">
            <div class="card-body">
                <h6 class="mb-3"><i class="fas fa-info-circle me-2"></i>Feature Toggle Guide</h6>
                <div class="row">
                    <div class="col-md-4">
                        <strong>Global Enable/Disable:</strong>
                        <p class="text-muted small">
                            When disabled globally, the feature is OFF for all partners regardless of overrides.
                            When enabled globally, partners can use the feature (subject to their overrides).
                        </p>
                    </div>
                    <div class="col-md-4">
                        <strong>Partners Control:</strong>
                        <p class="text-muted small">
                            When enabled, partner admins can toggle this feature for their organization.
                            When disabled, only super admins can control this feature.
                        </p>
                    </div>
                    <div class="col-md-4">
                        <strong>Partner Overrides:</strong>
                        <p class="text-muted small">
                            Individual partners can have custom settings that override the global default.
                            Click "Manage Partners" to set per-partner overrides.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Partner Overrides Modal -->
    <div class="modal fade" id="overridesModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-list me-2"></i>Partner Overrides</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="overridesList">
                        <div class="text-center py-4">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Manage Partners Modal -->
    <div class="modal fade" id="managePartnersModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-cog me-2"></i>Manage Partner Access</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="partnersList">
                        <div class="text-center py-4">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const overridesModal = new bootstrap.Modal(document.getElementById('overridesModal'));
        const managePartnersModal = new bootstrap.Modal(document.getElementById('managePartnersModal'));

        function showAlert(message, type = 'info') {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.getElementById('alertContainer').appendChild(alert);
            setTimeout(() => alert.remove(), 5000);
        }

        async function toggleGlobal(featureID, enabled) {
            try {
                const response = await fetch('../api/feature-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'toggle_global',
                        featureID: featureID,
                        enabled: enabled
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(`Feature ${enabled ? 'enabled' : 'disabled'} globally`, 'success');
                } else {
                    showAlert(result.message || 'Failed to update feature', 'danger');
                    location.reload();
                }
            } catch (error) {
                showAlert('Error updating feature. Please try again.', 'danger');
                location.reload();
            }
        }

        async function togglePartnerControl(featureID, allowed) {
            try {
                const response = await fetch('../api/feature-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'toggle_partner_control',
                        featureID: featureID,
                        allowed: allowed
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(`Partner control ${allowed ? 'enabled' : 'disabled'}`, 'success');
                } else {
                    showAlert(result.message || 'Failed to update setting', 'danger');
                    location.reload();
                }
            } catch (error) {
                showAlert('Error updating setting. Please try again.', 'danger');
                location.reload();
            }
        }

        async function viewOverrides(featureID, featureName) {
            document.querySelector('#overridesModal .modal-title').innerHTML =
                `<i class="fas fa-list me-2"></i>Overrides: ${featureName}`;

            overridesModal.show();

            try {
                const response = await fetch(`../api/feature-actions.php?action=get_overrides&featureID=${featureID}`);
                const result = await response.json();

                if (result.success) {
                    let html = '<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Partner</th><th>Setting</th><th>Modified</th><th>Modified By</th><th>Actions</th></tr></thead><tbody>';

                    result.overrides.forEach(override => {
                        html += `
                            <tr>
                                <td><strong>${override.companyName}</strong><br><small class="text-muted">${override.tier.toUpperCase()}</small></td>
                                <td><span class="badge bg-${override.isEnabled ? 'success' : 'danger'}">${override.isEnabled ? 'Enabled' : 'Disabled'}</span></td>
                                <td><small class="text-muted">${override.modifiedAt || 'N/A'}</small></td>
                                <td><small class="text-muted">${override.modifiedBy || 'System'}</small></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-danger" onclick="removeOverride(${override.partnerFeatureID}, ${featureID})">
                                        <i class="fas fa-times"></i> Remove
                                    </button>
                                </td>
                            </tr>
                        `;
                    });

                    html += '</tbody></table></div>';
                    document.getElementById('overridesList').innerHTML = html;
                } else {
                    document.getElementById('overridesList').innerHTML = `<div class="alert alert-warning">Failed to load overrides</div>`;
                }
            } catch (error) {
                document.getElementById('overridesList').innerHTML = `<div class="alert alert-danger">Error loading overrides</div>`;
            }
        }

        async function managePartners(featureID, featureName) {
            document.querySelector('#managePartnersModal .modal-title').innerHTML =
                `<i class="fas fa-cog me-2"></i>Manage Access: ${featureName}`;

            managePartnersModal.show();

            try {
                const response = await fetch(`../api/feature-actions.php?action=get_all_partners&featureID=${featureID}`);
                const result = await response.json();

                if (result.success) {
                    let html = '<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Partner</th><th>Tier</th><th>Current Setting</th><th>Actions</th></tr></thead><tbody>';

                    result.partners.forEach(partner => {
                        const hasOverride = partner.hasOverride;
                        const isEnabled = partner.isEnabled;
                        const usingGlobal = !hasOverride;

                        html += `
                            <tr>
                                <td><strong>${partner.companyName}</strong></td>
                                <td><span class="badge bg-secondary">${partner.tier.toUpperCase()}</span></td>
                                <td>
                                    ${usingGlobal ? '<span class="badge bg-info">Using Global</span>' : ''}
                                    <span class="badge bg-${isEnabled ? 'success' : 'danger'}">${isEnabled ? 'Enabled' : 'Disabled'}</span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-success" onclick="setPartnerFeature(${featureID}, ${partner.partnerID}, true)">
                                            <i class="fas fa-check"></i> Enable
                                        </button>
                                        <button class="btn btn-outline-danger" onclick="setPartnerFeature(${featureID}, ${partner.partnerID}, false)">
                                            <i class="fas fa-times"></i> Disable
                                        </button>
                                        ${hasOverride ? `<button class="btn btn-outline-secondary" onclick="removePartnerOverride(${featureID}, ${partner.partnerID})"><i class="fas fa-undo"></i> Reset</button>` : ''}
                                    </div>
                                </td>
                            </tr>
                        `;
                    });

                    html += '</tbody></table></div>';
                    document.getElementById('partnersList').innerHTML = html;
                } else {
                    document.getElementById('partnersList').innerHTML = `<div class="alert alert-warning">Failed to load partners</div>`;
                }
            } catch (error) {
                document.getElementById('partnersList').innerHTML = `<div class="alert alert-danger">Error loading partners</div>`;
            }
        }

        async function setPartnerFeature(featureID, partnerID, enabled) {
            try {
                const response = await fetch('../api/feature-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'set_partner_feature',
                        featureID: featureID,
                        partnerID: partnerID,
                        enabled: enabled
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Partner feature setting updated', 'success');
                    // Refresh the manage partners view
                    managePartners(featureID, '');
                } else {
                    showAlert(result.message || 'Failed to update setting', 'danger');
                }
            } catch (error) {
                showAlert('Error updating setting. Please try again.', 'danger');
            }
        }

        async function removePartnerOverride(featureID, partnerID) {
            if (!confirm('Remove this partner\'s custom setting? They will revert to the global default.')) {
                return;
            }

            try {
                const response = await fetch('../api/feature-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'remove_partner_override',
                        featureID: featureID,
                        partnerID: partnerID
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Override removed', 'success');
                    managePartners(featureID, '');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(result.message || 'Failed to remove override', 'danger');
                }
            } catch (error) {
                showAlert('Error removing override. Please try again.', 'danger');
            }
        }

        async function removeOverride(partnerFeatureID, featureID) {
            if (!confirm('Remove this override?')) {
                return;
            }

            try {
                const response = await fetch('../api/feature-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'remove_override',
                        partnerFeatureID: partnerFeatureID
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Override removed', 'success');
                    viewOverrides(featureID, '');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(result.message || 'Failed to remove override', 'danger');
                }
            } catch (error) {
                showAlert('Error removing override. Please try again.', 'danger');
            }
        }
    </script>
</body>
</html>
