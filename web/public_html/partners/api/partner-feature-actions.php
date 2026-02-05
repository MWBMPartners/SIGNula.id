<?php
/**
 * Partner Feature Management API
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
require_once dirname(__DIR__, 3) . '/_backend/ActivityLogger.php';

header('Content-Type: application/json');

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);
$activityLogger = new ActivityLogger($db, $sessionManager);

// Check if logged in
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'toggle_feature':
            handleToggleFeature($input, $db, $accessControl, $sessionManager, $activityLogger);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Toggle feature for partner
 */
function handleToggleFeature($input, $db, $accessControl, $sessionManager, $activityLogger) {
    $partnerID = $input['partnerID'] ?? 0;
    $featureID = $input['featureID'] ?? 0;
    $enabled = $input['enabled'] ?? false;

    // Verify partner admin access
    if (!$accessControl->isPartnerAdmin($partnerID)) {
        throw new Exception('Admin access required');
    }

    // Get feature details
    $stmt = $db->prepare("
        SELECT featureKey, featureName, canPartnersToggle, isEnabledGlobally
        FROM tblFeatureToggles
        WHERE featureID = ?
    ");
    $stmt->bind_param('i', $featureID);
    $stmt->execute();
    $feature = $stmt->get_result()->fetch_assoc();

    if (!$feature) {
        throw new Exception('Feature not found');
    }

    // Check if globally disabled
    if (!$feature['isEnabledGlobally']) {
        throw new Exception('This feature is disabled globally and cannot be enabled');
    }

    // Check if partners can toggle this feature
    if (!$feature['canPartnersToggle']) {
        throw new Exception('You do not have permission to toggle this feature. Contact support for assistance.');
    }

    $userID = $sessionManager->getUserID();

    // Insert or update partner feature
    $stmt = $db->prepare("
        INSERT INTO tblPartnerFeatures (partnerID, featureID, isEnabled, enabledBy, modifiedAt)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            isEnabled = VALUES(isEnabled),
            enabledBy = VALUES(enabledBy),
            modifiedAt = NOW()
    ");
    $stmt->bind_param('iiii', $partnerID, $featureID, $enabled, $userID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to update feature setting');
    }

    // Log action
    $accessControl->logAdminAction('toggle_partner_feature', 'partner', $partnerID, [
        'featureKey' => $feature['featureKey'],
        'featureName' => $feature['featureName'],
        'enabled' => $enabled
    ]);

    $activityLogger->logActivity(
        'partner_feature_toggled',
        'Feature ' . $feature['featureName'] . ' ' . ($enabled ? 'enabled' : 'disabled'),
        ['partnerID' => $partnerID, 'featureID' => $featureID, 'enabled' => $enabled]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Feature ' . ($enabled ? 'enabled' : 'disabled') . ' successfully'
    ]);
}
