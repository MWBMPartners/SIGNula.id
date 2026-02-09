<?php
/**
 * ⚙️ System Settings Management (Super Admin)
 *
 * Provides a UI for viewing and editing all system settings stored in tblSettings.
 * Settings are grouped by category with support for inline editing, sensitive value masking,
 * and adding new settings via modal.
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
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

// 🔌 Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin check
$accessControl->requireSuperAdmin();

// 📁 Get all categories for tab navigation
$catStmt = $db->prepare("
    SELECT DISTINCT settingCategory, COUNT(*) as count
    FROM tblSettings
    WHERE settingCategory IS NOT NULL AND settingCategory != ''
    GROUP BY settingCategory
    ORDER BY settingCategory
");
$catStmt->execute();
$categories = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 📊 Total settings count
$totalStmt = $db->prepare("SELECT COUNT(*) as total FROM tblSettings");
$totalStmt->execute();
$totalSettings = $totalStmt->get_result()->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - SIGNula Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }

        /* 🎨 Gradient header — green-teal theme for settings */
        .page-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        /* 📁 Category tabs */
        .category-tabs {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
            padding: 1rem;
        }
        .category-tabs .nav-link {
            color: #495057;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            margin-right: 0.25rem;
            font-weight: 500;
        }
        .category-tabs .nav-link.active {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
        }
        .category-tabs .nav-link .badge {
            font-size: 0.7rem;
        }

        /* ⚙️ Settings cards */
        .settings-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .settings-card-header {
            background: linear-gradient(135deg, #11998e, #38ef7d);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
        }

        /* 📋 Setting row */
        .setting-row {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e9ecef;
            transition: background-color 0.2s;
        }
        .setting-row:last-child {
            border-bottom: none;
        }
        .setting-row:hover {
            background-color: #f8f9fa;
        }
        .setting-key {
            font-family: 'SF Mono', 'Monaco', 'Menlo', monospace;
            font-size: 0.9rem;
            color: #495057;
            font-weight: 600;
        }
        .setting-description {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .setting-value {
            font-family: 'SF Mono', 'Monaco', 'Menlo', monospace;
            font-size: 0.85rem;
            background: #f1f3f5;
            padding: 0.3rem 0.6rem;
            border-radius: 4px;
            max-width: 400px;
            word-break: break-all;
        }
        .setting-value.sensitive {
            color: #6c757d;
            font-style: italic;
        }

        /* 🏷️ Type badges */
        .type-badge {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-weight: 600;
        }
        .type-string { background: #e3f2fd; color: #1565c0; }
        .type-integer { background: #e8f5e9; color: #2e7d32; }
        .type-boolean { background: #fff3e0; color: #e65100; }
        .type-json { background: #f3e5f5; color: #7b1fa2; }
        .type-encrypted { background: #fce4ec; color: #c62828; }

        /* ✏️ Inline edit */
        .edit-input {
            font-family: 'SF Mono', 'Monaco', 'Menlo', monospace;
            font-size: 0.85rem;
        }

        /* 🔍 Filter bar */
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 1rem 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
        }

        /* 🔒 Read-only indicator */
        .readonly-badge {
            background: #e9ecef;
            color: #6c757d;
            padding: 0.15rem 0.4rem;
            border-radius: 8px;
            font-size: 0.7rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- 🎨 Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <a href="../index.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                        <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
                    </a>
                    <h1><i class="fas fa-cogs me-2"></i>System Settings</h1>
                    <p class="mb-0 opacity-75">Manage all system configuration values</p>
                </div>
                <div class="col-md-4 text-end">
                    <button class="btn btn-light btn-lg" onclick="openAddModal()">
                        <i class="fas fa-plus me-2"></i>Add Setting
                    </button>
                </div>
            </div>
        </div>

        <!-- 📋 Alert Container -->
        <div id="alertContainer"></div>

        <!-- 🔍 Search Bar -->
        <div class="filter-bar">
            <div class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label fw-semibold"><i class="fas fa-search me-1"></i>Search Settings</label>
                    <input type="text" id="searchInput" class="form-control" placeholder="Search by key, description, or value...">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" onclick="loadSettings()">
                        <i class="fas fa-search me-1"></i>Search
                    </button>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-outline-secondary w-100" onclick="clearSearch()">
                        <i class="fas fa-times me-1"></i>Clear
                    </button>
                </div>
            </div>
        </div>

        <!-- 📁 Category Tabs -->
        <div class="category-tabs">
            <nav>
                <ul class="nav nav-pills flex-wrap" id="categoryTabs">
                    <li class="nav-item">
                        <a class="nav-link active" href="javascript:void(0)" onclick="selectCategory('')" data-category="">
                            All <span class="badge bg-white text-dark ms-1"><?php echo $totalSettings; ?></span>
                        </a>
                    </li>
                    <?php foreach ($categories as $cat): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="javascript:void(0)" onclick="selectCategory('<?php echo htmlspecialchars($cat['settingCategory'], ENT_QUOTES, 'UTF-8'); ?>')" data-category="<?php echo htmlspecialchars($cat['settingCategory'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($cat['settingCategory']); ?>
                            <span class="badge bg-light text-dark ms-1"><?php echo $cat['count']; ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </div>

        <!-- ⚙️ Settings Container -->
        <div id="settingsContainer">
            <div class="text-center py-5">
                <div class="spinner-border text-primary"></div>
                <p class="mt-2">Loading settings...</p>
            </div>
        </div>
    </div>

    <!-- ➕ Add Setting Modal -->
    <div class="modal fade" id="addSettingModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #11998e, #38ef7d); color: white;">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Setting</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Setting Key <span class="text-danger">*</span></label>
                        <input type="text" id="newSettingKey" class="form-control font-monospace" placeholder="e.g. oauth.google.client_id">
                        <small class="text-muted">Use dot notation for grouping (letters, numbers, dots, underscores, hyphens)</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Value</label>
                        <textarea id="newSettingValue" class="form-control font-monospace" rows="2" placeholder="Setting value"></textarea>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                            <select id="newSettingType" class="form-select">
                                <option value="string">String</option>
                                <option value="integer">Integer</option>
                                <option value="boolean">Boolean</option>
                                <option value="json">JSON</option>
                                <option value="encrypted">Encrypted</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                            <input type="text" id="newSettingCategory" class="form-control" placeholder="e.g. OAuth" list="categoryList">
                            <datalist id="categoryList">
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['settingCategory']); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea id="newSettingDescription" class="form-control" rows="2" placeholder="What does this setting control?"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="newSettingSensitive">
                                <label class="form-check-label" for="newSettingSensitive">
                                    <i class="fas fa-lock me-1"></i>Sensitive Value
                                </label>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="newSettingEditable" checked>
                                <label class="form-check-label" for="newSettingEditable">
                                    <i class="fas fa-edit me-1"></i>Editable
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="createSetting()">
                        <i class="fas fa-plus me-1"></i>Create Setting
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ✏️ Edit Setting Modal -->
    <div class="modal fade" id="editSettingModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #11998e, #38ef7d); color: white;">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Setting</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Setting Key</label>
                        <input type="text" id="editSettingKey" class="form-control font-monospace" readonly disabled>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Description</label>
                        <p id="editSettingDescription" class="text-muted small"></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Value</label>
                        <textarea id="editSettingValue" class="form-control font-monospace" rows="3"></textarea>
                        <small class="text-muted" id="editSettingTypeHint"></small>
                    </div>
                    <input type="hidden" id="editSettingID">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveSettingEdit()">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 🔧 State
        let selectedCategory = '';
        let allSettings = [];

        // 🏷️ Type badge CSS classes
        const typeBadges = {
            'string': 'type-string',
            'integer': 'type-integer',
            'boolean': 'type-boolean',
            'json': 'type-json',
            'encrypted': 'type-encrypted'
        };

        /**
         * 💬 Show alert message
         */
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

        /**
         * 🔒 Escape HTML
         */
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * 📁 Select category tab
         */
        function selectCategory(category) {
            selectedCategory = category;

            // Update active tab
            document.querySelectorAll('#categoryTabs .nav-link').forEach(tab => {
                tab.classList.toggle('active', tab.dataset.category === category);
            });

            loadSettings();
        }

        /**
         * 📋 Load settings from API
         */
        async function loadSettings() {
            const search = document.getElementById('searchInput').value.trim();
            const container = document.getElementById('settingsContainer');
            container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2">Loading settings...</p></div>';

            try {
                const params = new URLSearchParams({ action: 'list_settings' });
                if (search) params.append('search', search);
                if (selectedCategory) params.append('category', selectedCategory);

                const response = await fetch(`../api/settings-actions.php?${params}`);
                const result = await response.json();

                if (result.success) {
                    allSettings = result.settings;
                    renderSettings(result.grouped);
                } else {
                    container.innerHTML = `<div class="alert alert-danger">${escapeHtml(result.message)}</div>`;
                }
            } catch (error) {
                container.innerHTML = '<div class="alert alert-danger">Error loading settings. Please try again.</div>';
            }
        }

        /**
         * ⚙️ Render settings grouped by category
         */
        function renderSettings(grouped) {
            const container = document.getElementById('settingsContainer');

            if (Object.keys(grouped).length === 0) {
                container.innerHTML = `
                    <div class="settings-card">
                        <div class="text-center py-5">
                            <i class="fas fa-cog fa-3x text-muted mb-3"></i>
                            <h5>No Settings Found</h5>
                            <p class="text-muted">Try adjusting your search or category filter</p>
                        </div>
                    </div>
                `;
                return;
            }

            let html = '';

            for (const [category, settings] of Object.entries(grouped)) {
                html += `
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <i class="fas fa-folder me-2"></i>${escapeHtml(category)}
                            <span class="badge bg-light text-dark ms-2">${settings.length}</span>
                        </div>
                        <div class="settings-body">
                `;

                settings.forEach(setting => {
                    const typeBadge = typeBadges[setting.settingType] || 'type-string';
                    const isReadOnly = !setting.isEditable;
                    const isSensitive = setting.isSensitive;

                    html += `
                        <div class="setting-row">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <div class="setting-key">${escapeHtml(setting.settingKey)}</div>
                                    <div class="setting-description">${escapeHtml(setting.description || 'No description')}</div>
                                    <div class="mt-1">
                                        <span class="type-badge ${typeBadge}">${escapeHtml(setting.settingType)}</span>
                                        ${isSensitive ? '<span class="type-badge type-encrypted ms-1"><i class="fas fa-lock me-1"></i>Sensitive</span>' : ''}
                                        ${isReadOnly ? '<span class="readonly-badge ms-1"><i class="fas fa-ban me-1"></i>Read-only</span>' : ''}
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <div class="setting-value ${isSensitive ? 'sensitive' : ''}" id="value-${setting.settingID}">
                                        ${isSensitive ? '<i class="fas fa-lock me-1"></i>••••••••' : escapeHtml(truncateValue(setting.settingValue))}
                                    </div>
                                    ${setting.updatedAt ? `<small class="text-muted">Updated: ${new Date(setting.updatedAt).toLocaleDateString()} ${setting.updatedByUsername ? 'by ' + escapeHtml(setting.updatedByUsername) : ''}</small>` : ''}
                                </div>
                                <div class="col-md-3 text-end">
                                    ${isSensitive ? `
                                        <button class="btn btn-sm btn-outline-warning me-1" onclick="revealSensitive(${setting.settingID})" title="Reveal value">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    ` : ''}
                                    ${!isReadOnly ? `
                                        <button class="btn btn-sm btn-outline-primary me-1" onclick="openEditModal(${setting.settingID})" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteSetting(${setting.settingID}, '${escapeHtml(setting.settingKey)}')" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    ` : `
                                        <span class="text-muted"><i class="fas fa-lock"></i></span>
                                    `}
                                </div>
                            </div>
                        </div>
                    `;
                });

                html += '</div></div>';
            }

            container.innerHTML = html;
        }

        /**
         * 📏 Truncate long values for display
         */
        function truncateValue(value) {
            if (!value) return '(empty)';
            if (value.length > 100) return value.substring(0, 100) + '...';
            return value;
        }

        /**
         * 🔓 Reveal sensitive value (logged action)
         */
        async function revealSensitive(settingID) {
            if (!confirm('Revealing this sensitive value will be logged for security auditing. Continue?')) {
                return;
            }

            try {
                const response = await fetch(`../api/settings-actions.php?action=reveal_sensitive&settingID=${settingID}`);
                const result = await response.json();

                if (result.success) {
                    const valueEl = document.getElementById(`value-${settingID}`);
                    valueEl.classList.remove('sensitive');
                    valueEl.innerHTML = escapeHtml(result.value || '(empty)');

                    // 🔒 Re-mask after 10 seconds for security
                    setTimeout(() => {
                        valueEl.classList.add('sensitive');
                        valueEl.innerHTML = '<i class="fas fa-lock me-1"></i>••••••••';
                    }, 10000);
                } else {
                    showAlert(result.message || 'Failed to reveal value', 'danger');
                }
            } catch (error) {
                showAlert('Error revealing sensitive value', 'danger');
            }
        }

        /**
         * ✏️ Open edit modal for a setting
         */
        function openEditModal(settingID) {
            const setting = allSettings.find(s => s.settingID == settingID);
            if (!setting) return;

            document.getElementById('editSettingID').value = settingID;
            document.getElementById('editSettingKey').value = setting.settingKey;
            document.getElementById('editSettingDescription').textContent = setting.description || 'No description';
            document.getElementById('editSettingValue').value = setting.isSensitive ? '' : (setting.settingValue || '');

            // 📝 Type hint
            const hints = {
                'string': 'Any text value',
                'integer': 'Whole number only',
                'boolean': 'true/false, 1/0, or yes/no',
                'json': 'Valid JSON (e.g. {"key": "value"})',
                'encrypted': 'Value will be encrypted before storage'
            };
            document.getElementById('editSettingTypeHint').textContent =
                `Type: ${setting.settingType} — ${hints[setting.settingType] || ''}` +
                (setting.isSensitive ? ' (leave empty to keep current value)' : '');

            new bootstrap.Modal(document.getElementById('editSettingModal')).show();
        }

        /**
         * 💾 Save setting edit
         */
        async function saveSettingEdit() {
            const settingID = document.getElementById('editSettingID').value;
            const value = document.getElementById('editSettingValue').value;

            // ⚠️ Don't update sensitive settings with empty value (keep existing)
            const setting = allSettings.find(s => s.settingID == settingID);
            if (setting && setting.isSensitive && value === '') {
                showAlert('No changes made (empty value for sensitive field is ignored)', 'info');
                bootstrap.Modal.getInstance(document.getElementById('editSettingModal')).hide();
                return;
            }

            try {
                const response = await fetch('../api/settings-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'update_setting', settingID: parseInt(settingID), value: value })
                });
                const result = await response.json();

                bootstrap.Modal.getInstance(document.getElementById('editSettingModal')).hide();

                if (result.success) {
                    showAlert(result.message, 'success');
                    loadSettings();
                } else {
                    showAlert(result.message || 'Failed to update setting', 'danger');
                }
            } catch (error) {
                showAlert('Error updating setting', 'danger');
            }
        }

        /**
         * ➕ Open add setting modal
         */
        function openAddModal() {
            // Clear form fields
            document.getElementById('newSettingKey').value = '';
            document.getElementById('newSettingValue').value = '';
            document.getElementById('newSettingType').value = 'string';
            document.getElementById('newSettingCategory').value = '';
            document.getElementById('newSettingDescription').value = '';
            document.getElementById('newSettingSensitive').checked = false;
            document.getElementById('newSettingEditable').checked = true;

            new bootstrap.Modal(document.getElementById('addSettingModal')).show();
        }

        /**
         * ➕ Create new setting
         */
        async function createSetting() {
            const key = document.getElementById('newSettingKey').value.trim();
            const value = document.getElementById('newSettingValue').value;
            const type = document.getElementById('newSettingType').value;
            const category = document.getElementById('newSettingCategory').value.trim();
            const description = document.getElementById('newSettingDescription').value.trim();
            const isSensitive = document.getElementById('newSettingSensitive').checked;
            const isEditable = document.getElementById('newSettingEditable').checked;

            if (!key) {
                showAlert('Setting key is required', 'warning');
                return;
            }
            if (!category) {
                showAlert('Category is required', 'warning');
                return;
            }

            try {
                const response = await fetch('../api/settings-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create_setting',
                        key, value, type, category, description, isSensitive, isEditable
                    })
                });
                const result = await response.json();

                bootstrap.Modal.getInstance(document.getElementById('addSettingModal')).hide();

                if (result.success) {
                    showAlert(result.message, 'success');
                    // Reload page to update category tabs
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert(result.message || 'Failed to create setting', 'danger');
                }
            } catch (error) {
                showAlert('Error creating setting', 'danger');
            }
        }

        /**
         * 🗑️ Delete a setting
         */
        async function deleteSetting(settingID, settingKey) {
            if (!confirm(`Delete setting "${settingKey}"?\n\nThis action cannot be undone.`)) {
                return;
            }

            try {
                const response = await fetch('../api/settings-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_setting', settingID: settingID })
                });
                const result = await response.json();

                if (result.success) {
                    showAlert(result.message, 'success');
                    loadSettings();
                } else {
                    showAlert(result.message || 'Failed to delete setting', 'danger');
                }
            } catch (error) {
                showAlert('Error deleting setting', 'danger');
            }
        }

        /**
         * 🧹 Clear search
         */
        function clearSearch() {
            document.getElementById('searchInput').value = '';
            loadSettings();
        }

        // ⌨️ Search on Enter key
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                loadSettings();
            }
        });

        // 🚀 Initial load
        loadSettings();
    </script>
</body>
</html>
