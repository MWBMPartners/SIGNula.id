<?php
/**
 * API Key Management - Partner View
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 2) . '/_config/config.php';
require_once dirname(__DIR__, 2) . '/_backend/Database.php';
require_once dirname(__DIR__, 2) . '/_backend/SessionManager.php';
require_once dirname(__DIR__, 2) . '/_backend/APIKeyManager.php';

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$apiKeyManager = new APIKeyManager($db);

if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

$userID = $sessionManager->getUserID();

// Get partner info
$stmt = $db->prepare("SELECT * FROM tblPartners WHERE userID = ? OR email = (SELECT email FROM tblUsers WHERE userID = ?)");
$stmt->bind_param('ii', $userID, $userID);
$stmt->execute();
$partner = $stmt->get_result()->fetch_assoc();

if (!$partner || $partner['status'] !== 'active') {
    die('Partner account not active');
}

$message = '';
$newKey = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ Verify CSRF token
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = ['type' => 'danger', 'text' => 'Invalid or expired security token. Please refresh and try again.'];
    } else {
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $keyName = trim($_POST['key_name'] ?? '');
        $environment = $_POST['environment'] ?? 'test';
        $expiresInDays = (int)($_POST['expires_days'] ?? 365);

        if (empty($keyName)) {
            $message = ['type' => 'danger', 'text' => 'Please provide a key name'];
        } else {
            $result = $apiKeyManager->generateKey(
                $partner['partnerID'],
                $keyName,
                $environment,
                null, // permissions
                $expiresInDays
            );

            if ($result['success']) {
                $newKey = $result['apiKey']; // Show once!
                $message = ['type' => 'success', 'text' => 'API key generated successfully'];
            } else {
                $message = ['type' => 'danger', 'text' => $result['error']];
            }
        }
    } elseif ($action === 'revoke') {
        $keyID = (int)($_POST['key_id'] ?? 0);
        $result = $apiKeyManager->revokeKey($keyID);
        $message = $result['success']
            ? ['type' => 'success', 'text' => 'API key revoked']
            : ['type' => 'danger', 'text' => $result['error']];
    }
    } // end CSRF else
}

// Get all keys
$stmt = $db->prepare("
    SELECT apiKeyID, keyName, environment, keyPreview, status,
           permissions, ipWhitelist, expiresAt, createdAt, lastUsedAt
    FROM tblAPIKeys
    WHERE partnerID = ?
    ORDER BY createdAt DESC
");
$stmt->bind_param('i', $partner['partnerID']);
$stmt->execute();
$apiKeys = $stmt->get_result();

// 🛡️ Generate CSRF token for forms
$csrfToken = SecurityUtils::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Keys - SIGNula Partner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    <style>
        body { background-color: #f8f9fa; }
        .page-header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 2rem; border-radius: 10px; margin-bottom: 2rem; }
        .key-card { border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: all 0.3s; }
        .key-card:hover { transform: translateY(-3px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .new-key-display { background: #e7f3ff; border: 2px dashed #0066cc; padding: 1.5rem; border-radius: 10px; }
        .key-preview { font-family: 'Courier New', monospace; background: #f8f9fa; padding: 0.5rem; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container py-4">
        <!-- Header -->
        <div class="page-header">
            <a href="dashboard.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
            </a>
            <h1><i class="fas fa-key me-2"></i>API Key Management</h1>
            <p class="mb-0 opacity-75">Generate and manage your API keys for accessing SIGNula services</p>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible">
            <?php echo htmlspecialchars($message['text']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($newKey): ?>
        <div class="new-key-display mb-4">
            <h5 class="text-primary"><i class="fas fa-exclamation-triangle me-2"></i>Important: Save Your API Key</h5>
            <p class="mb-3">This key will only be shown <strong>once</strong>. Please copy it now and store it securely.</p>
            <div class="input-group">
                <input type="text" class="form-control form-control-lg" value="<?php echo htmlspecialchars($newKey); ?>" id="newKeyInput" readonly>
                <button class="btn btn-primary" onclick="copyKey()">
                    <i class="fas fa-copy me-2"></i>Copy
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Generate New Key -->
        <div class="card key-card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Generate New API Key</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="generate">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Key Name</label>
                            <input type="text" name="key_name" class="form-control" placeholder="Production API" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Environment</label>
                            <select name="environment" class="form-select">
                                <option value="test">Test (sk_test_xxx)</option>
                                <option value="live">Live (sk_live_xxx)</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Expires In</label>
                            <select name="expires_days" class="form-select">
                                <option value="30">30 days</option>
                                <option value="90">90 days</option>
                                <option value="365" selected>1 year</option>
                                <option value="730">2 years</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-key me-2"></i>Generate API Key
                    </button>
                </form>
            </div>
        </div>

        <!-- Existing Keys -->
        <h4 class="mb-3">Your API Keys</h4>
        <?php if ($apiKeys->num_rows > 0): ?>
            <?php while ($key = $apiKeys->fetch_assoc()): ?>
            <div class="card key-card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h5 class="mb-1">
                                <i class="fas fa-key me-2 text-primary"></i>
                                <?php echo htmlspecialchars($key['keyName']); ?>
                            </h5>
                            <span class="badge bg-<?php echo $key['environment'] === 'live' ? 'danger' : 'info'; ?>">
                                <?php echo strtoupper($key['environment']); ?>
                            </span>
                            <span class="badge bg-<?php echo $key['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                <?php echo strtoupper($key['status']); ?>
                            </span>
                        </div>
                        <form method="POST" onsubmit="return confirm('Revoke this API key? This cannot be undone.')">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="key_id" value="<?php echo $key['apiKeyID']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger" <?php echo $key['status'] !== 'active' ? 'disabled' : ''; ?>>
                                <i class="fas fa-trash me-1"></i>Revoke
                            </button>
                        </form>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <small class="text-muted">Key Preview:</small>
                            <div class="key-preview"><?php echo htmlspecialchars($key['keyPreview']); ?>••••••••</div>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Created: <?php echo date('M j, Y', strtotime($key['createdAt'])); ?></small>
                            <small class="text-muted d-block">Expires: <?php echo $key['expiresAt'] ? date('M j, Y', strtotime($key['expiresAt'])) : 'Never'; ?></small>
                            <small class="text-muted d-block">Last Used: <?php echo $key['lastUsedAt'] ? date('M j, Y H:i', strtotime($key['lastUsedAt'])) : 'Never'; ?></small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>No API keys yet. Generate your first key to get started!
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <script>
        function copyKey() {
            const input = document.getElementById('newKeyInput');
            input.select();
            document.execCommand('copy');
            alert('API key copied to clipboard!');
        }
    </script>
</body>
</html>
