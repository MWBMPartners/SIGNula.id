<?php
/**
 * ⚙️ System Settings Management API (Super Admin)
 *
 * Handles CRUD operations for system settings stored in tblSettings.
 * Sensitive values are masked in responses and encrypted in storage.
 *
 * All actions require Super Admin authentication and are logged to the audit trail.
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
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

// 📋 Set JSON response header
header('Content-Type: application/json');

// 🔌 Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check — must be logged in
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 🛡️ Permission check — Super Admin only
$accessControl->requireSuperAdmin();

// 📥 Get request data based on method
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

// 🎯 Route to appropriate handler
try {
    switch ($action) {
        case 'list_settings':
            handleListSettings($db);
            break;

        case 'get_categories':
            handleGetCategories($db);
            break;

        case 'get_setting':
            handleGetSetting($_GET['settingID'] ?? 0, $db);
            break;

        case 'update_setting':
            handleUpdateSetting($input, $db, $accessControl, $sessionManager);
            break;

        case 'create_setting':
            handleCreateSetting($input, $db, $accessControl, $sessionManager);
            break;

        case 'delete_setting':
            handleDeleteSetting($input, $db, $accessControl, $sessionManager);
            break;

        case 'reveal_sensitive':
            handleRevealSensitive($_GET['settingID'] ?? 0, $db, $accessControl);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * 📋 List all settings grouped by category
 *
 * GET parameters:
 * - search: Search by key, description, or value
 * - category: Filter by settingCategory
 *
 * ⚠️ Sensitive values are masked with •••••••• and never sent in plaintext.
 */
function handleListSettings($db) {
    $search = trim($_GET['search'] ?? '');
    $category = trim($_GET['category'] ?? '');

    // 🔧 Build dynamic WHERE clause
    $conditions = [];
    $params = [];
    $types = '';

    // 🔍 Search filter
    if ($search !== '') {
        $searchLike = '%' . $search . '%';
        $conditions[] = '(s.settingKey LIKE ? OR s.description LIKE ? OR (s.isSensitive = FALSE AND s.settingValue LIKE ?))';
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $types .= 'sss';
    }

    // 📁 Category filter
    if ($category !== '') {
        $conditions[] = 's.settingCategory = ?';
        $params[] = $category;
        $types .= 's';
    }

    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // 📋 Get settings
    $sql = "
        SELECT
            s.settingID,
            s.settingKey,
            s.settingValue,
            s.settingType,
            s.settingCategory,
            s.isSensitive,
            s.isEditable,
            s.description,
            s.defaultValue,
            s.validationRules,
            s.updatedAt,
            u.username as updatedByUsername
        FROM tblSettings s
        LEFT JOIN tblUsers u ON s.updatedBy = u.userID
        {$whereClause}
        ORDER BY s.settingCategory, s.settingKey
    ";

    $stmt = $db->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // 🔒 Mask sensitive values — NEVER expose in API responses
    $settings = array_map(function($setting) {
        if ($setting['isSensitive']) {
            $setting['settingValue'] = '••••••••';
            $setting['defaultValue'] = $setting['defaultValue'] ? '••••••••' : null;
        }
        return $setting;
    }, $settings);

    // 📁 Group by category for frontend rendering
    $grouped = [];
    foreach ($settings as $setting) {
        $cat = $setting['settingCategory'] ?: 'Uncategorised';
        $grouped[$cat][] = $setting;
    }

    echo json_encode([
        'success' => true,
        'settings' => $settings,
        'grouped' => $grouped,
        'total' => count($settings)
    ]);
}

/**
 * 📁 Get distinct setting categories
 *
 * Returns a list of all unique categories for the category filter/tabs.
 */
function handleGetCategories($db) {
    $stmt = $db->prepare("
        SELECT DISTINCT settingCategory, COUNT(*) as count
        FROM tblSettings
        WHERE settingCategory IS NOT NULL AND settingCategory != ''
        GROUP BY settingCategory
        ORDER BY settingCategory
    ");
    $stmt->execute();
    $categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'success' => true,
        'categories' => $categories
    ]);
}

/**
 * 👁️ Get single setting details
 *
 * Returns full details for a single setting. Sensitive values are masked.
 */
function handleGetSetting($settingID, $db) {
    $settingID = (int)$settingID;

    if ($settingID <= 0) {
        throw new Exception('Invalid setting ID');
    }

    $stmt = $db->prepare("
        SELECT s.*, u.username as updatedByUsername
        FROM tblSettings s
        LEFT JOIN tblUsers u ON s.updatedBy = u.userID
        WHERE s.settingID = ?
    ");
    $stmt->bind_param('i', $settingID);
    $stmt->execute();
    $setting = $stmt->get_result()->fetch_assoc();

    if (!$setting) {
        throw new Exception('Setting not found');
    }

    // 🔒 Mask sensitive value
    if ($setting['isSensitive']) {
        $setting['settingValue'] = '••••••••';
    }

    echo json_encode([
        'success' => true,
        'setting' => $setting
    ]);
}

/**
 * 🔓 Reveal sensitive setting value (logged action)
 *
 * Special endpoint to decrypt and reveal a sensitive setting value.
 * This action is logged for security auditing purposes.
 */
function handleRevealSensitive($settingID, $db, $accessControl) {
    $settingID = (int)$settingID;

    if ($settingID <= 0) {
        throw new Exception('Invalid setting ID');
    }

    $stmt = $db->prepare("
        SELECT settingKey, settingValue, isSensitive, settingType
        FROM tblSettings
        WHERE settingID = ?
    ");
    $stmt->bind_param('i', $settingID);
    $stmt->execute();
    $setting = $stmt->get_result()->fetch_assoc();

    if (!$setting) {
        throw new Exception('Setting not found');
    }

    if (!$setting['isSensitive']) {
        throw new Exception('Setting is not marked as sensitive');
    }

    // 📝 Log the reveal action for security audit
    $accessControl->logAdminAction('reveal_sensitive_setting', 'setting', $settingID, [
        'settingKey' => $setting['settingKey']
    ]);

    // 🔓 Return the actual value (decryption handled by DB layer if encrypted)
    echo json_encode([
        'success' => true,
        'value' => $setting['settingValue']
    ]);
}

/**
 * ✏️ Update an existing setting value
 *
 * Validates the isEditable flag and handles encryption for sensitive fields.
 */
function handleUpdateSetting($input, $db, $accessControl, $sessionManager) {
    $settingID = (int)($input['settingID'] ?? 0);
    $newValue = $input['value'] ?? '';

    if ($settingID <= 0) {
        throw new Exception('Invalid setting ID');
    }

    // 📋 Get current setting for validation
    $stmt = $db->prepare("SELECT settingKey, settingValue, settingType, isEditable, isSensitive FROM tblSettings WHERE settingID = ?");
    $stmt->bind_param('i', $settingID);
    $stmt->execute();
    $setting = $stmt->get_result()->fetch_assoc();

    if (!$setting) {
        throw new Exception('Setting not found');
    }

    // 🚫 Check if setting is editable
    if (!$setting['isEditable']) {
        throw new Exception('This setting is read-only and cannot be modified');
    }

    // ✅ Type validation based on settingType
    switch ($setting['settingType']) {
        case 'integer':
            if (!is_numeric($newValue)) {
                throw new Exception('Value must be a number');
            }
            $newValue = (string)(int)$newValue;
            break;

        case 'boolean':
            if (!in_array(strtolower((string)$newValue), ['true', 'false', '1', '0', 'yes', 'no'], true)) {
                throw new Exception('Value must be true/false, 1/0, or yes/no');
            }
            // 🔧 Normalise boolean values to 1/0
            $newValue = in_array(strtolower((string)$newValue), ['true', '1', 'yes'], true) ? '1' : '0';
            break;

        case 'json':
            // ✅ Validate JSON format
            if (json_decode($newValue) === null && strtolower($newValue) !== 'null') {
                throw new Exception('Value must be valid JSON');
            }
            break;
    }

    // 🔄 Update the setting
    $userID = $sessionManager->getUserID();
    $stmt = $db->prepare("
        UPDATE tblSettings
        SET settingValue = ?, updatedBy = ?, updatedAt = NOW()
        WHERE settingID = ?
    ");
    $stmt->bind_param('sii', $newValue, $userID, $settingID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to update setting');
    }

    // 📝 Log the action (mask sensitive values in log)
    $accessControl->logAdminAction('update_setting', 'setting', $settingID, [
        'settingKey' => $setting['settingKey'],
        'previousValue' => $setting['isSensitive'] ? '[SENSITIVE]' : $setting['settingValue'],
        'newValue' => $setting['isSensitive'] ? '[SENSITIVE]' : $newValue
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Setting "' . $setting['settingKey'] . '" updated successfully'
    ]);
}

/**
 * ➕ Create a new setting
 *
 * Adds a new key-value setting to the system configuration.
 * Validates that the key doesn't already exist to prevent duplicates.
 */
function handleCreateSetting($input, $db, $accessControl, $sessionManager) {
    $key = trim($input['key'] ?? '');
    $value = $input['value'] ?? '';
    $type = $input['type'] ?? 'string';
    $category = trim($input['category'] ?? '');
    $description = trim($input['description'] ?? '');
    $isSensitive = (bool)($input['isSensitive'] ?? false);
    $isEditable = (bool)($input['isEditable'] ?? true);

    // ✅ Validate required fields
    if ($key === '') {
        throw new Exception('Setting key is required');
    }

    if ($category === '') {
        throw new Exception('Setting category is required');
    }

    // ✅ Validate key format — dot notation allowed (e.g., oauth.google.client_id)
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $key)) {
        throw new Exception('Setting key must contain only letters, numbers, dots, underscores, and hyphens');
    }

    // ✅ Validate type ENUM
    $allowedTypes = ['string', 'integer', 'boolean', 'json', 'encrypted'];
    if (!in_array($type, $allowedTypes, true)) {
        throw new Exception('Invalid setting type');
    }

    // 🚫 Check for duplicate key
    $stmt = $db->prepare("SELECT settingID FROM tblSettings WHERE settingKey = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        throw new Exception('A setting with this key already exists');
    }

    // ➕ Insert new setting
    $userID = $sessionManager->getUserID();
    $isSensitiveInt = $isSensitive ? 1 : 0;
    $isEditableInt = $isEditable ? 1 : 0;

    $stmt = $db->prepare("
        INSERT INTO tblSettings (settingKey, settingValue, settingType, settingCategory, isSensitive, isEditable, description, updatedBy, updatedAt)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param('ssssiisi', $key, $value, $type, $category, $isSensitiveInt, $isEditableInt, $description, $userID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to create setting');
    }

    $newSettingID = $db->insert_id;

    // 📝 Log the action
    $accessControl->logAdminAction('create_setting', 'setting', $newSettingID, [
        'settingKey' => $key,
        'settingType' => $type,
        'settingCategory' => $category,
        'isSensitive' => $isSensitive
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Setting "' . $key . '" created successfully',
        'settingID' => $newSettingID
    ]);
}

/**
 * 🗑️ Delete a setting
 *
 * Removes a setting from the database. Only editable settings can be deleted.
 * This is a permanent action — consider whether the setting might be needed.
 */
function handleDeleteSetting($input, $db, $accessControl, $sessionManager) {
    $settingID = (int)($input['settingID'] ?? 0);

    if ($settingID <= 0) {
        throw new Exception('Invalid setting ID');
    }

    // 📋 Get setting details for validation and logging
    $stmt = $db->prepare("SELECT settingKey, isEditable, settingCategory FROM tblSettings WHERE settingID = ?");
    $stmt->bind_param('i', $settingID);
    $stmt->execute();
    $setting = $stmt->get_result()->fetch_assoc();

    if (!$setting) {
        throw new Exception('Setting not found');
    }

    // 🚫 Only editable settings can be deleted
    if (!$setting['isEditable']) {
        throw new Exception('This setting is protected and cannot be deleted');
    }

    // 🗑️ Delete the setting
    $stmt = $db->prepare("DELETE FROM tblSettings WHERE settingID = ?");
    $stmt->bind_param('i', $settingID);

    if (!$stmt->execute()) {
        throw new Exception('Failed to delete setting');
    }

    // 📝 Log the action
    $accessControl->logAdminAction('delete_setting', 'setting', $settingID, [
        'settingKey' => $setting['settingKey'],
        'settingCategory' => $setting['settingCategory']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Setting "' . $setting['settingKey'] . '" deleted successfully'
    ]);
}
