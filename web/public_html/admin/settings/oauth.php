<?php
/**
 * 🔗 OAuth Provider Configuration (Super Admin)
 *
 * Visual management interface for OAuth/SSO provider credentials.
 * Each provider is displayed as a card with configuration status.
 * Settings are stored in tblSettings with keys like oauth.{provider}.{field}.
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
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

// 🔗 Define supported OAuth providers with their metadata
$providers = [
    'google' => [
        'name' => 'Google',
        'icon' => 'fab fa-google',
        'color' => '#4285F4',
        'description' => 'Google Sign-In & Google Workspace (personal + work accounts)',
        'fields' => ['client_id', 'client_secret', 'redirect_uri', 'scopes'],
        'docsUrl' => 'https://developers.google.com/identity/protocols/oauth2'
    ],
    'microsoft' => [
        'name' => 'Microsoft',
        'icon' => 'fab fa-microsoft',
        'color' => '#00A4EF',
        'description' => 'Microsoft Account & Microsoft 365 (personal + work/school)',
        'fields' => ['client_id', 'client_secret', 'tenant_id', 'redirect_uri', 'scopes'],
        'docsUrl' => 'https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow'
    ],
    'apple' => [
        'name' => 'Apple',
        'icon' => 'fab fa-apple',
        'color' => '#000000',
        'description' => 'Sign in with Apple (Apple ID / Apple Account)',
        'fields' => ['client_id', 'team_id', 'key_id', 'private_key', 'redirect_uri'],
        'docsUrl' => 'https://developer.apple.com/sign-in-with-apple/'
    ],
    'facebook' => [
        'name' => 'Facebook',
        'icon' => 'fab fa-facebook',
        'color' => '#1877F2',
        'description' => 'Facebook & Instagram login (Meta accounts)',
        'fields' => ['app_id', 'app_secret', 'redirect_uri', 'scopes'],
        'docsUrl' => 'https://developers.facebook.com/docs/facebook-login/'
    ],
    'linkedin' => [
        'name' => 'LinkedIn',
        'icon' => 'fab fa-linkedin',
        'color' => '#0A66C2',
        'description' => 'LinkedIn Sign-In for professional accounts',
        'fields' => ['client_id', 'client_secret', 'redirect_uri', 'scopes'],
        'docsUrl' => 'https://learn.microsoft.com/en-us/linkedin/shared/authentication/authorization-code-flow'
    ],
    'yahoo' => [
        'name' => 'Yahoo',
        'icon' => 'fab fa-yahoo',
        'color' => '#6001D2',
        'description' => 'Yahoo Account login',
        'fields' => ['client_id', 'client_secret', 'redirect_uri'],
        'docsUrl' => 'https://developer.yahoo.com/oauth2/guide/'
    ],
    'amazon' => [
        'name' => 'Amazon',
        'icon' => 'fab fa-amazon',
        'color' => '#FF9900',
        'description' => 'Login with Amazon',
        'fields' => ['client_id', 'client_secret', 'redirect_uri', 'scopes'],
        'docsUrl' => 'https://developer.amazon.com/docs/login-with-amazon/web-docs.html'
    ],
    'paypal' => [
        'name' => 'PayPal',
        'icon' => 'fab fa-paypal',
        'color' => '#003087',
        'description' => 'PayPal Identity authentication',
        'fields' => ['client_id', 'client_secret', 'redirect_uri', 'mode'],
        'docsUrl' => 'https://developer.paypal.com/docs/log-in-with-paypal/'
    ],
    'wordpress' => [
        'name' => 'WordPress',
        'icon' => 'fab fa-wordpress',
        'color' => '#21759B',
        'description' => 'WordPress.com account login',
        'fields' => ['client_id', 'client_secret', 'redirect_uri'],
        'docsUrl' => 'https://developer.wordpress.com/docs/oauth2/'
    ]
];

// 📊 Load current OAuth settings from database to check configuration status
$stmt = $db->prepare("
    SELECT settingKey, settingValue, isSensitive
    FROM tblSettings
    WHERE settingKey LIKE 'oauth.%'
    ORDER BY settingKey
");
$stmt->execute();
$oauthSettings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 🔧 Parse settings into provider => field => value map
$configMap = [];
foreach ($oauthSettings as $setting) {
    // Parse key like oauth.google.client_id → provider=google, field=client_id
    $parts = explode('.', $setting['settingKey'], 3);
    if (count($parts) === 3) {
        $provider = $parts[1];
        $field = $parts[2];
        $configMap[$provider][$field] = [
            'value' => $setting['isSensitive'] ? '••••••••' : $setting['settingValue'],
            'isSensitive' => (bool)$setting['isSensitive'],
            'hasValue' => !empty($setting['settingValue'])
        ];
    }
}

// 📊 Count configured vs total providers
$configuredCount = 0;
foreach ($providers as $key => $provider) {
    $isConfigured = false;
    if (isset($configMap[$key])) {
        // 🔍 Check if at least client_id (or app_id/team_id) has a value
        foreach (['client_id', 'app_id', 'team_id'] as $idField) {
            if (isset($configMap[$key][$idField]) && $configMap[$key][$idField]['hasValue']) {
                $isConfigured = true;
                break;
            }
        }
    }
    $providers[$key]['isConfigured'] = $isConfigured;
    if ($isConfigured) {
        $configuredCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OAuth Providers - SIGNula Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    <style>
        body { background-color: #f8f9fa; }

        /* 🎨 Gradient header — pink-orange theme for OAuth */
        .page-header {
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        /* 🔗 Provider cards */
        .provider-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
            overflow: hidden;
            height: 100%;
            cursor: pointer;
        }
        .provider-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        }
        .provider-card-header {
            padding: 1.5rem;
            text-align: center;
            position: relative;
        }
        .provider-icon {
            font-size: 3rem;
            margin-bottom: 0.75rem;
        }
        .provider-name {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }
        .provider-description {
            font-size: 0.8rem;
            color: #6c757d;
            line-height: 1.3;
        }
        .provider-card-footer {
            padding: 0.75rem 1.5rem;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            text-align: center;
        }

        /* ✅ Configuration status badges */
        .status-configured {
            background: #d4edda;
            color: #155724;
            padding: 0.3rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-not-configured {
            background: #f8d7da;
            color: #721c24;
            padding: 0.3rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* 📊 Stats summary */
        .stats-row {
            background: white;
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
        }

        /* 📝 Modal field styles */
        .field-group {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
        }
        .field-label {
            font-family: 'SF Mono', 'Monaco', 'Menlo', monospace;
            font-size: 0.85rem;
            font-weight: 600;
            color: #495057;
        }
        .field-sensitive {
            color: #dc3545;
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
                    <h1><i class="fas fa-plug me-2"></i>OAuth Provider Configuration</h1>
                    <p class="mb-0 opacity-75">Configure third-party authentication providers for SIGNula ID</p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="d-inline-block text-start bg-white bg-opacity-25 rounded p-3">
                        <div class="fs-3 fw-bold"><?php echo $configuredCount; ?>/<?php echo count($providers); ?></div>
                        <small class="opacity-75">Providers Configured</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 📋 Alert Container -->
        <div id="alertContainer"></div>

        <!-- 📊 Info Banner -->
        <div class="alert alert-info mb-4">
            <h6 class="alert-heading"><i class="fas fa-info-circle me-2"></i>How OAuth Configuration Works</h6>
            <p class="mb-2">
                Click on any provider card to configure its credentials. Settings are stored securely in the system settings database.
                Sensitive values (client secrets, private keys) are encrypted at rest.
            </p>
            <ul class="mb-0">
                <li><strong class="text-success">Configured</strong> — Provider has valid credentials and is ready for use</li>
                <li><strong class="text-danger">Not Configured</strong> — Provider needs credentials to be set up</li>
            </ul>
        </div>

        <!-- 🔗 Provider Grid -->
        <div class="row g-4">
            <?php foreach ($providers as $key => $provider): ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
                <div class="provider-card" onclick="openProviderModal('<?php echo $key; ?>')">
                    <div class="provider-card-header">
                        <div class="provider-icon" style="color: <?php echo $provider['color']; ?>">
                            <i class="<?php echo $provider['icon']; ?>"></i>
                        </div>
                        <div class="provider-name"><?php echo htmlspecialchars($provider['name']); ?></div>
                        <div class="provider-description"><?php echo htmlspecialchars($provider['description']); ?></div>
                    </div>
                    <div class="provider-card-footer">
                        <?php if ($provider['isConfigured']): ?>
                        <span class="status-configured">
                            <i class="fas fa-check-circle me-1"></i>Configured
                        </span>
                        <?php else: ?>
                        <span class="status-not-configured">
                            <i class="fas fa-times-circle me-1"></i>Not Configured
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 📚 Documentation Links -->
        <div class="card mt-4">
            <div class="card-body">
                <h6 class="mb-3"><i class="fas fa-book me-2"></i>Provider Documentation</h6>
                <div class="row">
                    <?php foreach ($providers as $key => $provider): ?>
                    <div class="col-md-4 col-lg-3 mb-2">
                        <a href="<?php echo htmlspecialchars($provider['docsUrl']); ?>" target="_blank" rel="noopener noreferrer" class="text-decoration-none">
                            <i class="<?php echo $provider['icon']; ?> me-1" style="color: <?php echo $provider['color']; ?>"></i>
                            <?php echo htmlspecialchars($provider['name']); ?> Docs
                            <i class="fas fa-external-link-alt ms-1 text-muted" style="font-size: 0.7rem;"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ⚙️ Provider Configuration Modal -->
    <div class="modal fade" id="providerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" id="providerModalHeader">
                    <h5 class="modal-title" id="providerModalTitle"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="providerModalBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a id="providerDocsLink" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-outline-info me-auto">
                        <i class="fas fa-book me-1"></i>Documentation
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveProviderBtn" onclick="saveProviderConfig()">
                        <i class="fas fa-save me-1"></i>Save Configuration
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <!-- 🔐 CSRF Token for secure AJAX requests -->
    <script>const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';</script>
    <script>
        // 🔧 Provider metadata from PHP
        const providers = <?php echo json_encode($providers); ?>;
        const configMap = <?php echo json_encode($configMap); ?>;
        let currentProvider = null;

        /**
         * 💬 Show alert
         */
        function showAlert(message, type = 'info') {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
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
         * 🔧 Determine if a field should be treated as sensitive
         */
        function isSensitiveField(fieldName) {
            const sensitiveFields = ['client_secret', 'app_secret', 'private_key', 'webhook_secret'];
            return sensitiveFields.includes(fieldName);
        }

        /**
         * 🔧 Get human-readable label for a field
         */
        function getFieldLabel(fieldName) {
            const labels = {
                'client_id': 'Client ID',
                'client_secret': 'Client Secret',
                'app_id': 'App ID',
                'app_secret': 'App Secret',
                'redirect_uri': 'Redirect URI',
                'scopes': 'Scopes',
                'tenant_id': 'Tenant ID',
                'team_id': 'Team ID',
                'key_id': 'Key ID',
                'private_key': 'Private Key',
                'mode': 'Mode (sandbox/live)',
                'enabled': 'Enabled'
            };
            return labels[fieldName] || fieldName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        }

        /**
         * ⚙️ Open provider configuration modal
         */
        function openProviderModal(providerKey) {
            currentProvider = providerKey;
            const provider = providers[providerKey];
            const config = configMap[providerKey] || {};

            // 🎨 Set modal header colour
            const header = document.getElementById('providerModalHeader');
            header.style.background = `linear-gradient(135deg, ${provider.color}, ${provider.color}dd)`;
            header.style.color = 'white';

            document.getElementById('providerModalTitle').innerHTML =
                `<i class="${provider.icon} me-2"></i>${provider.name} Configuration`;

            document.getElementById('providerDocsLink').href = provider.docsUrl;

            // 🔧 Build form fields
            let html = `
                <div class="alert alert-light mb-3">
                    <strong>${escapeHtml(provider.name)}</strong> — ${escapeHtml(provider.description)}
                </div>

                <!-- ✅ Enable/Disable toggle -->
                <div class="field-group mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="field-enabled"
                            ${config.enabled && config.enabled.hasValue && config.enabled.value !== '0' ? 'checked' : ''}>
                        <label class="form-check-label fw-semibold" for="field-enabled">
                            <i class="fas fa-power-off me-1"></i>Enable ${escapeHtml(provider.name)} Authentication
                        </label>
                    </div>
                </div>
            `;

            // 📝 Render each field
            provider.fields.forEach(field => {
                const sensitive = isSensitiveField(field);
                const currentValue = config[field] || {};
                const hasValue = currentValue.hasValue || false;
                const displayValue = sensitive ? '' : (currentValue.value || '');
                const isTextarea = field === 'private_key' || field === 'scopes';

                html += `
                    <div class="field-group">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="field-label ${sensitive ? 'field-sensitive' : ''}" for="field-${field}">
                                ${sensitive ? '<i class="fas fa-lock me-1"></i>' : ''}${getFieldLabel(field)}
                            </label>
                            ${hasValue ? '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Set</span>' : '<span class="badge bg-secondary">Not set</span>'}
                        </div>
                        ${isTextarea ? `
                            <textarea id="field-${field}" class="form-control font-monospace" rows="3"
                                placeholder="${sensitive ? 'Enter new value (leave empty to keep current)' : `Enter ${getFieldLabel(field)}`}">${escapeHtml(displayValue)}</textarea>
                        ` : `
                            <input type="${sensitive ? 'password' : 'text'}" id="field-${field}"
                                class="form-control font-monospace"
                                value="${escapeHtml(displayValue)}"
                                placeholder="${sensitive ? 'Enter new value (leave empty to keep current)' : `Enter ${getFieldLabel(field)}`}">
                        `}
                        ${sensitive ? '<small class="text-muted"><i class="fas fa-shield-alt me-1"></i>This value is encrypted in storage. Leave empty to keep current value.</small>' : ''}
                    </div>
                `;
            });

            document.getElementById('providerModalBody').innerHTML = html;
            new bootstrap.Modal(document.getElementById('providerModal')).show();
        }

        /**
         * 💾 Save provider configuration
         */
        async function saveProviderConfig() {
            if (!currentProvider) return;

            const provider = providers[currentProvider];
            const saveBtn = document.getElementById('saveProviderBtn');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';

            let savedCount = 0;
            let errorCount = 0;

            // 💾 Save enabled status
            const enabledValue = document.getElementById('field-enabled').checked ? '1' : '0';
            await saveOAuthSetting(currentProvider, 'enabled', enabledValue, false);

            // 💾 Save each field
            for (const field of provider.fields) {
                const input = document.getElementById(`field-${field}`);
                const value = input.value.trim();
                const sensitive = isSensitiveField(field);

                // ⏭️ Skip empty sensitive fields (keep current value)
                if (sensitive && value === '') continue;

                try {
                    const success = await saveOAuthSetting(currentProvider, field, value, sensitive);
                    if (success) {
                        savedCount++;
                    } else {
                        errorCount++;
                    }
                } catch (e) {
                    errorCount++;
                }
            }

            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save me-1"></i>Save Configuration';

            bootstrap.Modal.getInstance(document.getElementById('providerModal')).hide();

            if (errorCount === 0) {
                showAlert(`${provider.name} configuration saved successfully (${savedCount} fields updated)`, 'success');
                // Reload to update status badges
                setTimeout(() => location.reload(), 1500);
            } else {
                showAlert(`Saved ${savedCount} fields, but ${errorCount} failed. Check the settings page for details.`, 'warning');
            }
        }

        /**
         * 💾 Save a single OAuth setting via the settings API
         *
         * Creates the setting if it doesn't exist, updates if it does.
         */
        async function saveOAuthSetting(provider, field, value, isSensitive) {
            const settingKey = `oauth.${provider}.${field}`;

            // 🔍 First, check if setting exists
            try {
                const listResponse = await fetch(`../api/settings-actions.php?action=list_settings&search=${encodeURIComponent(settingKey)}`);
                const listResult = await listResponse.json();

                if (listResult.success) {
                    const existing = listResult.settings.find(s => s.settingKey === settingKey);

                    if (existing) {
                        // ✏️ Update existing setting
                        const response = await fetch('../api/settings-actions.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'update_setting',
                                settingID: parseInt(existing.settingID),
                                value: value,
                                csrf_token: csrfToken
                            })
                        });
                        const result = await response.json();
                        return result.success;
                    } else {
                        // ➕ Create new setting
                        const response = await fetch('../api/settings-actions.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'create_setting',
                                key: settingKey,
                                value: value,
                                type: isSensitive ? 'encrypted' : 'string',
                                category: 'OAuth',
                                description: `${providers[provider].name} ${getFieldLabel(field)}`,
                                isSensitive: isSensitive,
                                isEditable: true,
                                csrf_token: csrfToken
                            })
                        });
                        const result = await response.json();
                        return result.success;
                    }
                }
            } catch (e) {
                console.error(`Error saving ${settingKey}:`, e);
            }
            return false;
        }
    </script>
</body>
</html>
