<?php
/**
 * Partner Feature Toggles
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

// Get user's partner memberships
$memberships = $accessControl->getPartnerMemberships();

if (empty($memberships)) {
    header('Location: /partners/register.php');
    exit;
}

// Default to first partner or use selected
$selectedPartnerID = $_GET['partner'] ?? array_key_first($memberships);
$partner = $memberships[$selectedPartnerID] ?? reset($memberships);

// Verify admin access
if (!$accessControl->isPartnerAdmin($selectedPartnerID)) {
    http_response_code(403);
    die('Admin access required for this partner');
}

// Get partner details
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE partnerID = ?");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$partnerDetails = $stmt->get_result()->fetch_assoc();

// Get all features with their status for this partner
$stmt = $db->prepare("
    SELECT
        f.*,
        pf.isEnabled as partnerEnabled,
        pf.partnerFeatureID
    FROM tblFeatureToggles f
    LEFT JOIN tblPartnerFeatures pf ON f.featureID = pf.featureID AND pf.partnerID = ?
    WHERE f.isEnabledGlobally = TRUE
    ORDER BY f.category, f.featureName
");
$stmt->bind_param('i', $selectedPartnerID);
$stmt->execute();
$features = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Group features by category
$featuresByCategory = [];
foreach ($features as $feature) {
    $category = $feature['category'] ?: 'Other';
    $featuresByCategory[$category][] = $feature;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feature Toggles - <?php echo htmlspecialchars($partner['companyName']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .features-header {
            background: linear-gradient(135deg, #fa709a, #fee140);
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
            background: linear-gradient(135deg, #fa709a, #fee140);
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
        .feature-row.disabled {
            opacity: 0.6;
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
        input:disabled + .toggle-slider {
            cursor: not-allowed;
            opacity: 0.5;
        }
        .locked-badge {
            background: #ff6b6b;
            color: white;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="features-header">
            <div class="row align-items-center">
                <div class="col-md-10">
                    <a href="index.php?partner=<?php echo $selectedPartnerID; ?>" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                        <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
                    </a>
                    <h1><i class="fas fa-toggle-on me-2"></i>Feature Toggles</h1>
                    <p class="mb-0 opacity-75"><?php echo htmlspecialchars($partner['companyName']); ?></p>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <div id="alertContainer"></div>

        <!-- Info Box -->
        <div class="alert alert-info mb-4">
            <h6 class="alert-heading"><i class="fas fa-info-circle me-2"></i>About Feature Toggles</h6>
            <p class="mb-2">
                Control which features are active for your organization. Some features may be locked by system administrators.
            </p>
            <ul class="mb-0">
                <li><strong>Unlocked:</strong> You can toggle these features on/off</li>
                <li><strong>Locked:</strong> Contact support to enable these features</li>
                <li><strong>Globally Disabled:</strong> Not available (system-wide)</li>
            </ul>
        </div>

        <?php if (empty($features)): ?>
        <!-- No Features Available -->
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-toggle-off fa-4x text-muted mb-3"></i>
                <h4>No Features Available</h4>
                <p class="text-muted">
                    No features are currently enabled globally. Contact support for more information.
                </p>
            </div>
        </div>
        <?php else: ?>

        <!-- Features by Category -->
        <?php foreach ($featuresByCategory as $category => $categoryFeatures): ?>
        <div class="category-section">
            <div class="category-header">
                <i class="fas fa-folder me-2"></i><?php echo htmlspecialchars($category); ?>
            </div>
            <div class="category-body">
                <?php foreach ($categoryFeatures as $feature): ?>
                <?php
                $canToggle = $feature['canPartnersToggle'];
                $hasOverride = !is_null($feature['partnerFeatureID']);
                $isEnabled = $hasOverride ? $feature['partnerEnabled'] : true;
                ?>
                <div class="feature-row <?php echo $canToggle ? '' : 'disabled'; ?>">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h6 class="mb-1"><?php echo htmlspecialchars($feature['featureName']); ?></h6>
                            <small class="text-muted">
                                <?php echo htmlspecialchars($feature['description'] ?: 'No description available'); ?>
                            </small>
                        </div>
                        <div class="col-md-3 text-center">
                            <?php if ($canToggle): ?>
                            <label class="feature-toggle">
                                <input type="checkbox"
                                       data-feature-id="<?php echo $feature['featureID']; ?>"
                                       <?php echo $isEnabled ? 'checked' : ''; ?>
                                       onchange="toggleFeature(<?php echo $feature['featureID']; ?>, this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <br>
                            <small class="text-muted">
                                <?php echo $isEnabled ? 'Enabled' : 'Disabled'; ?>
                            </small>
                            <?php else: ?>
                            <span class="locked-badge">
                                <i class="fas fa-lock me-1"></i>Locked
                            </span>
                            <br>
                            <small class="text-muted">Contact support</small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3 text-end">
                            <?php if ($hasOverride): ?>
                            <span class="badge bg-info">
                                <i class="fas fa-cog me-1"></i>Custom Setting
                            </span>
                            <br>
                            <small class="text-muted">Using override</small>
                            <?php else: ?>
                            <span class="badge bg-secondary">
                                <i class="fas fa-globe me-1"></i>Default
                            </span>
                            <br>
                            <small class="text-muted">Using global setting</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- Help Card -->
        <div class="card">
            <div class="card-body">
                <h6 class="mb-3"><i class="fas fa-question-circle me-2"></i>Need Help?</h6>
                <div class="row">
                    <div class="col-md-6">
                        <h6>Locked Features</h6>
                        <p class="text-muted small">
                            Some features are controlled by system administrators for security or compliance reasons.
                            If you need access to a locked feature, please contact support.
                        </p>
                    </div>
                    <div class="col-md-6">
                        <h6>Custom Settings</h6>
                        <p class="text-muted small">
                            When you toggle a feature, it creates a custom setting for your organization.
                            This overrides the global default until you change it back.
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const partnerID = <?php echo $selectedPartnerID; ?>;

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

        async function toggleFeature(featureID, enabled) {
            try {
                const response = await fetch('../api/partner-feature-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'toggle_feature',
                        partnerID: partnerID,
                        featureID: featureID,
                        enabled: enabled
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(`Feature ${enabled ? 'enabled' : 'disabled'} successfully`, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(result.message || 'Failed to update feature', 'danger');
                    location.reload();
                }
            } catch (error) {
                showAlert('Error updating feature. Please try again.', 'danger');
                location.reload();
            }
        }
    </script>
</body>
</html>
