<?php
/**
 * Feature Management API (Super Admin)
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

header('Content-Type: application/json');

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// Check if logged in and super admin
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$accessControl->requireSuperAdmin();

// Get request data
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

// 🛡️ Verify CSRF token for state-changing requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
        exit;
    }
}

try {
    switch ($action) {
        case 'toggle_global':
            handleToggleGlobal($input, $db, $accessControl, $sessionManager);
            break;

        case 'toggle_partner_control':
            handleTogglePartnerControl($input, $db, $accessControl, $sessionManager);
            break;

        case 'get_overrides':
            handleGetOverrides($_GET['featureID'] ?? 0, $db);
            break;

        case 'get_all_partners':
            handleGetAllPartners($_GET['featureID'] ?? 0, $db);
            break;

        case 'set_partner_feature':
            handleSetPartnerFeature($input, $db, $accessControl, $sessionManager);
            break;

        case 'remove_partner_override':
            handleRemovePartnerOverride($input, $db, $accessControl, $sessionManager);
            break;

        case 'remove_override':
            handleRemoveOverride($input, $db, $accessControl, $sessionManager);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Toggle feature globally
 */
function handleToggleGlobal($input, $db, $accessControl, $sessionManager) {
    $featureID = $input['featureID'] ?? 0;
    $enabled = $input['enabled'] ?? false;

    $stmt = $db->prepare("
        UPDATE tblFeatureToggles
        SET isEnabledGlobally = ?
        WHERE featureID = ?
    ");
    $stmt->bind_param('ii', $enabled, $featureID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to update feature');
    }

    // Get feature name for logging
    $stmt = $db->prepare("SELECT featureKey FROM tblFeatureToggles WHERE featureID = ?");
    $stmt->bind_param('i', $featureID);
    $stmt->execute();
    $feature = $stmt->get_result()->fetch_assoc();

    // Log action
    $accessControl->logAdminAction('toggle_global_feature', 'feature', $featureID, [
        'featureKey' => $feature['featureKey'],
        'enabled' => $enabled
    ]);

    echo json_encode(['success' => true]);
}

/**
 * Toggle whether partners can control feature
 */
function handleTogglePartnerControl($input, $db, $accessControl, $sessionManager) {
    $featureID = $input['featureID'] ?? 0;
    $allowed = $input['allowed'] ?? false;

    $stmt = $db->prepare("
        UPDATE tblFeatureToggles
        SET canPartnersToggle = ?
        WHERE featureID = ?
    ");
    $stmt->bind_param('ii', $allowed, $featureID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to update setting');
    }

    // Get feature name for logging
    $stmt = $db->prepare("SELECT featureKey FROM tblFeatureToggles WHERE featureID = ?");
    $stmt->bind_param('i', $featureID);
    $stmt->execute();
    $feature = $stmt->get_result()->fetch_assoc();

    // Log action
    $accessControl->logAdminAction('toggle_partner_control', 'feature', $featureID, [
        'featureKey' => $feature['featureKey'],
        'allowed' => $allowed
    ]);

    echo json_encode(['success' => true]);
}

/**
 * Get all overrides for a feature
 */
function handleGetOverrides($featureID, $db) {
    $stmt = $db->prepare("
        SELECT pf.*, p.companyName, p.tier, u.username as modifiedByUsername
        FROM tblPartnerFeatures pf
        JOIN tblPartners p ON pf.partnerID = p.partnerID
        LEFT JOIN tblUsers u ON pf.enabledBy = u.userID
        WHERE pf.featureID = ?
        ORDER BY p.companyName
    ");
    $stmt->bind_param('i', $featureID);
    $stmt->execute();
    $overrides = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'success' => true,
        'overrides' => array_map(function($override) {
            return [
                'partnerFeatureID' => $override['partnerFeatureID'],
                'companyName' => $override['companyName'],
                'tier' => $override['tier'],
                'isEnabled' => (bool)$override['isEnabled'],
                'modifiedAt' => $override['modifiedAt'] ? date('M j, Y g:i A', strtotime($override['modifiedAt'])) : null,
                'modifiedBy' => $override['modifiedByUsername']
            ];
        }, $overrides)
    ]);
}

/**
 * Get all partners with their feature status
 */
function handleGetAllPartners($featureID, $db) {
    // Get global setting
    $stmt = $db->prepare("SELECT isEnabledGlobally FROM tblFeatureToggles WHERE featureID = ?");
    $stmt->bind_param('i', $featureID);
    $stmt->execute();
    $globalSetting = $stmt->get_result()->fetch_assoc()['isEnabledGlobally'];

    // Get all partners with their override status
    $stmt = $db->prepare("
        SELECT
            p.partnerID,
            p.companyName,
            p.tier,
            pf.isEnabled,
            pf.partnerFeatureID
        FROM tblPartners p
        LEFT JOIN tblPartnerFeatures pf ON p.partnerID = pf.partnerID AND pf.featureID = ?
        WHERE p.status = 'active'
        ORDER BY p.companyName
    ");
    $stmt->bind_param('i', $featureID);
    $stmt->execute();
    $partners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'success' => true,
        'globalSetting' => (bool)$globalSetting,
        'partners' => array_map(function($partner) use ($globalSetting) {
            $hasOverride = !is_null($partner['partnerFeatureID']);
            return [
                'partnerID' => $partner['partnerID'],
                'companyName' => $partner['companyName'],
                'tier' => $partner['tier'],
                'hasOverride' => $hasOverride,
                'isEnabled' => $hasOverride ? (bool)$partner['isEnabled'] : (bool)$globalSetting
            ];
        }, $partners)
    ]);
}

/**
 * Set partner-specific feature setting
 */
function handleSetPartnerFeature($input, $db, $accessControl, $sessionManager) {
    $featureID = $input['featureID'] ?? 0;
    $partnerID = $input['partnerID'] ?? 0;
    $enabled = $input['enabled'] ?? false;
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
        throw new Exception('Failed to set partner feature');
    }

    // Get details for logging
    $stmt = $db->prepare("
        SELECT f.featureKey, p.companyName
        FROM tblFeatureToggles f, tblPartners p
        WHERE f.featureID = ? AND p.partnerID = ?
    ");
    $stmt->bind_param('ii', $featureID, $partnerID);
    $stmt->execute();
    $details = $stmt->get_result()->fetch_assoc();

    // Log action
    $accessControl->logAdminAction('set_partner_feature', 'partner', $partnerID, [
        'featureKey' => $details['featureKey'],
        'companyName' => $details['companyName'],
        'enabled' => $enabled
    ]);

    echo json_encode(['success' => true]);
}

/**
 * Remove partner override
 */
function handleRemovePartnerOverride($input, $db, $accessControl, $sessionManager) {
    $featureID = $input['featureID'] ?? 0;
    $partnerID = $input['partnerID'] ?? 0;

    // Get details before deletion
    $stmt = $db->prepare("
        SELECT f.featureKey, p.companyName
        FROM tblFeatureToggles f, tblPartners p
        WHERE f.featureID = ? AND p.partnerID = ?
    ");
    $stmt->bind_param('ii', $featureID, $partnerID);
    $stmt->execute();
    $details = $stmt->get_result()->fetch_assoc();

    // Delete override
    $stmt = $db->prepare("
        DELETE FROM tblPartnerFeatures
        WHERE partnerID = ? AND featureID = ?
    ");
    $stmt->bind_param('ii', $partnerID, $featureID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to remove override');
    }

    // Log action
    $accessControl->logAdminAction('remove_partner_override', 'partner', $partnerID, [
        'featureKey' => $details['featureKey'],
        'companyName' => $details['companyName']
    ]);

    echo json_encode(['success' => true]);
}

/**
 * Remove specific override by ID
 */
function handleRemoveOverride($input, $db, $accessControl, $sessionManager) {
    $partnerFeatureID = $input['partnerFeatureID'] ?? 0;

    // Get details before deletion
    $stmt = $db->prepare("
        SELECT pf.*, f.featureKey, p.companyName
        FROM tblPartnerFeatures pf
        JOIN tblFeatureToggles f ON pf.featureID = f.featureID
        JOIN tblPartners p ON pf.partnerID = p.partnerID
        WHERE pf.partnerFeatureID = ?
    ");
    $stmt->bind_param('i', $partnerFeatureID);
    $stmt->execute();
    $details = $stmt->get_result()->fetch_assoc();

    if (!$details) {
        throw new Exception('Override not found');
    }

    // Delete override
    $stmt = $db->prepare("DELETE FROM tblPartnerFeatures WHERE partnerFeatureID = ?");
    $stmt->bind_param('i', $partnerFeatureID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to remove override');
    }

    // Log action
    $accessControl->logAdminAction('remove_partner_override', 'partner', $details['partnerID'], [
        'featureKey' => $details['featureKey'],
        'companyName' => $details['companyName']
    ]);

    echo json_encode(['success' => true]);
}
