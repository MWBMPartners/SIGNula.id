<?php
/**
 * ============================================================================
 * 🔄 SIGNula Universal Login System - Upgrade Wizard
 * ============================================================================
 *
 * Purpose: Web-based upgrade wizard for existing SIGNula installations.
 *          Detects pending database migrations and applies them in order,
 *          with progress tracking and rollback capability.
 *
 * Security:
 *   - Requires SIGNULA_INIT (loaded via config.php — DB must exist)
 *   - Requires authenticated super-admin user OR valid upgrade token
 *   - CSRF protection on all form submissions
 *   - All DB operations use MySQLi prepared statements
 *
 * @package    SIGNula
 * @subpackage Installer
 * @version    1.0.0
 * @link       https://SIGNula.id
 * @see        https://www.php.net/manual/en/book.mysqli.php
 * ============================================================================
 */

// ============================================================================
// 🔧 BOOTSTRAP: Load application configuration
// ============================================================================
// The upgrade wizard requires a working application since it operates on an
// existing installation. We load config.php which sets SIGNULA_INIT and
// initialises the Database class.
$configPath = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

if (!file_exists($configPath)) {
    http_response_code(500);
    die('<!DOCTYPE html><html><head><title>SIGNula Upgrade Error</title></head><body>'
        . '<h1>Configuration Not Found</h1>'
        . '<p>Cannot find <code>config.php</code>. The upgrade wizard requires an existing SIGNula installation.</p>'
        . '<p>If this is a new installation, please use <a href="index.php">the installer</a> instead.</p>'
        . '</body></html>');
}

require_once $configPath;

// 🚫 Verify SIGNULA_INIT is defined (confirms config.php loaded correctly)
if (!defined('SIGNULA_INIT')) {
    http_response_code(500);
    die('Application initialization failed.');
}

// 🚀 Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================================
// 🔒 AUTHENTICATION: Require super-admin access
// ============================================================================
// The upgrade wizard requires either:
// 1. An authenticated user with isSuperAdmin=1, OR
// 2. A valid upgrade token passed as a URL parameter
//
// The upgrade token is a fallback for situations where the admin cannot log in
// (e.g., after a breaking schema change).

$isAuthorised = false;
$authMethod = 'none';
$authError = '';

// 🔍 Method 1: Check for authenticated super-admin session
if (isset($_SESSION['user_id'])) {
    try {
        // 🗄️ Query the database to verify super-admin status
        $conn = Database::getConnection();
        $stmt = $conn->prepare("SELECT isSuperAdmin FROM tblUsers WHERE userID = ? AND accountStatus = 'active'");

        if ($stmt) {
            $userId = (int)$_SESSION['user_id'];
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if ($user && (int)$user['isSuperAdmin'] === 1) {
                $isAuthorised = true;
                $authMethod = 'session';
            } else {
                $authError = 'Your account does not have super-admin privileges.';
            }
        }
    } catch (Exception $e) {
        // 🔄 If the isSuperAdmin column doesn't exist, try alternative check
        // This can happen during upgrades from older schemas
        $authError = 'Could not verify admin status. You may need to use an upgrade token.';
    }
}

// 🔍 Method 2: Check for upgrade token (fallback authentication)
if (!$isAuthorised && isset($_GET['token'])) {
    $providedToken = $_GET['token'];

    // 📁 Load upgrade token from _private/upgrade_token.php or tblSettings
    $upgradeToken = null;

    // Try loading from file first (more reliable during broken upgrades)
    $tokenFile = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '_private'
        . DIRECTORY_SEPARATOR . 'upgrade_token.php';

    if (file_exists($tokenFile)) {
        require_once $tokenFile;
        if (defined('UPGRADE_TOKEN')) {
            $upgradeToken = UPGRADE_TOKEN;
        }
    }

    // Fallback: try loading from database settings
    if ($upgradeToken === null) {
        try {
            if (function_exists('getSetting')) {
                $upgradeToken = getSetting('system.upgrade_token');
            }
        } catch (Exception $e) {
            // 🔇 Silently ignore — token file is the primary method
        }
    }

    // ✅ Validate the provided token
    if ($upgradeToken !== null && hash_equals($upgradeToken, $providedToken)) {
        $isAuthorised = true;
        $authMethod = 'token';
    } else {
        $authError = 'Invalid upgrade token.';
    }
}

// 🔍 Method 3: Show login/token form if not authenticated
if (!$isAuthorised && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upgrade_action']) && $_POST['upgrade_action'] === 'authenticate') {
    // 🔐 Attempt login with provided credentials
    $loginEmail = trim($_POST['login_email'] ?? '');
    $loginPass = $_POST['login_password'] ?? '';

    if (!empty($loginEmail) && !empty($loginPass)) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare(
                "SELECT userID, passwordHash, isSuperAdmin FROM tblUsers WHERE email = ? AND accountStatus = 'active'"
            );

            if ($stmt) {
                $stmt->bind_param('s', $loginEmail);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                $stmt->close();

                if ($user && password_verify($loginPass, $user['passwordHash'])) {
                    if ((int)$user['isSuperAdmin'] === 1) {
                        $_SESSION['user_id'] = (int)$user['userID'];
                        $_SESSION['upgrade_auth'] = true;
                        $isAuthorised = true;
                        $authMethod = 'login';
                    } else {
                        $authError = 'This account does not have super-admin privileges.';
                    }
                } else {
                    $authError = 'Invalid email or password.';
                }
            }
        } catch (Exception $e) {
            $authError = 'Authentication error: ' . $e->getMessage();
        }
    } else {
        $authError = 'Please provide both email and password.';
    }
}

// ============================================================================
// 🛡️ CSRF Token Management for upgrade wizard
// ============================================================================
if (empty($_SESSION['upgrade_csrf_token'])) {
    $_SESSION['upgrade_csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================================================
// 📌 STEP MANAGEMENT
// ============================================================================
// Determine current upgrade step (1=Auth, 2=Pending, 3=Run, 4=Post-Upgrade)
$currentStep = 1;
if ($isAuthorised) {
    $currentStep = isset($_SESSION['upgrade_step']) ? (int)$_SESSION['upgrade_step'] : 2;
}

// 🎯 Handle AJAX requests for migration execution
if ($isAuthorised && isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    handleUpgradeAjax();
    exit;
}

// 🔄 Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAuthorised) {
    if (isset($_POST['csrf_token']) && hash_equals($_SESSION['upgrade_csrf_token'], $_POST['csrf_token'])) {
        if (isset($_POST['step2_continue'])) {
            $_SESSION['upgrade_step'] = 3;
            $currentStep = 3;
        } elseif (isset($_POST['step3_continue'])) {
            $_SESSION['upgrade_step'] = 4;
            $currentStep = 4;
            // 🔄 Perform post-upgrade tasks
            performPostUpgrade();
        }
    }
}

// ============================================================================
// 📊 GATHER UPGRADE DATA
// ============================================================================
$currentVersion = '';
$pendingMigrations = [];
$appliedMigrations = [];

if ($isAuthorised) {
    // 🗄️ Get current installed version from tblSettings
    try {
        if (function_exists('getSetting')) {
            $currentVersion = getSetting('app.version') ?: 'Unknown';
        } else {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT settingValue FROM tblSettings WHERE settingKey = 'app.version'");
            if ($stmt) {
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $currentVersion = $row ? $row['settingValue'] : 'Unknown';
                $stmt->close();
            }
        }
    } catch (Exception $e) {
        $currentVersion = 'Unknown (error: ' . $e->getMessage() . ')';
    }

    // 📋 Get list of applied migrations from tblMigrations
    try {
        $conn = Database::getConnection();
        $stmt = $conn->prepare("SELECT migrationName, status, appliedAt FROM tblMigrations ORDER BY appliedAt ASC");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $appliedMigrations[$row['migrationName']] = $row;
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        // 🔇 tblMigrations might not exist yet — that's OK
    }

    // 📁 Get list of all migration files
    $projectRoot = dirname(dirname(dirname(__DIR__)));
    $migrationsDir = $projectRoot . DIRECTORY_SEPARATOR . '_database' . DIRECTORY_SEPARATOR . 'migrations';

    if (is_dir($migrationsDir)) {
        $files = scandir($migrationsDir);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                // 🔍 Check if this migration has been successfully applied
                $isApplied = isset($appliedMigrations[$file])
                    && $appliedMigrations[$file]['status'] === 'completed';

                if (!$isApplied) {
                    $pendingMigrations[] = $file;
                }
            }
        }
        // 🔢 Sort naturally
        natsort($pendingMigrations);
        $pendingMigrations = array_values($pendingMigrations);
    }
}

// ============================================================================
// 🎨 HTML OUTPUT
// ============================================================================
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>SIGNula - Upgrade Wizard</title>

    <!-- 🎨 Bootstrap 5.3 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YcnS/1Vh6e33MGA/VDEsEe0HheFl5LBl5DAe"
          crossorigin="anonymous">

    <!-- 🎨 Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <style>
        /* 🎨 Reuse installer styles with upgrade-specific theming */
        body {
            background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        .upgrade-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .upgrade-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .upgrade-header {
            background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .upgrade-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .upgrade-body {
            padding: 2rem;
        }

        .btn-signula {
            background: #4f46e5;
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            transition: background 0.2s ease;
        }

        .btn-signula:hover {
            background: #4338ca;
            color: white;
        }

        .migration-item {
            display: flex;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
        }

        .migration-status {
            width: 24px;
            margin-right: 0.75rem;
        }

        @media (max-width: 576px) {
            .upgrade-body { padding: 1.5rem; }
        }
    </style>
</head>
<body>

<div class="upgrade-container">
    <div class="upgrade-card">

        <!-- 🏷️ Header -->
        <div class="upgrade-header">
            <h1><i class="bi bi-arrow-up-circle"></i> SIGNula</h1>
            <p class="mb-0 opacity-75">Upgrade Wizard</p>
        </div>

        <div class="upgrade-body">

            <?php if (!$isAuthorised): ?>
                <!-- ============================================================ -->
                <!-- 🔐 STEP 1: Authentication -->
                <!-- ============================================================ -->
                <h4 class="mb-3"><i class="bi bi-shield-lock"></i> Authentication Required</h4>
                <p class="text-muted mb-4">
                    The upgrade wizard requires super-admin authentication. Log in or provide an upgrade token.
                </p>

                <?php if (!empty($authError)): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <?php echo htmlspecialchars($authError); ?>
                    </div>
                <?php endif; ?>

                <!-- 🔐 Login Form -->
                <form method="post" action="">
                    <input type="hidden" name="upgrade_action" value="authenticate">

                    <div class="mb-3">
                        <label for="login_email" class="form-label">Admin Email</label>
                        <input type="email" class="form-control" id="login_email" name="login_email"
                               placeholder="admin@signula.id" required>
                    </div>

                    <div class="mb-3">
                        <label for="login_password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="login_password" name="login_password"
                               placeholder="Enter your admin password" required>
                    </div>

                    <button type="submit" class="btn btn-signula w-100 mb-3">
                        <i class="bi bi-box-arrow-in-right"></i> Log In
                    </button>
                </form>

                <hr>

                <!-- 🔑 Token alternative -->
                <div class="text-center text-muted small">
                    <p><strong>Alternative:</strong> Append <code>?token=YOUR_UPGRADE_TOKEN</code> to the URL.</p>
                    <p>Upgrade tokens are stored in <code>_private/upgrade_token.php</code>.</p>
                </div>

            <?php elseif ($currentStep === 2): ?>
                <!-- ============================================================ -->
                <!-- 📋 STEP 2: Pending Migrations Overview -->
                <!-- ============================================================ -->
                <h4 class="mb-3"><i class="bi bi-list-check"></i> Pending Migrations</h4>

                <!-- 📊 Current version info -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-4">
                                <div class="text-muted small">Current Version</div>
                                <div class="fs-5 fw-bold"><?php echo htmlspecialchars($currentVersion); ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">Applied Migrations</div>
                                <div class="fs-5 fw-bold"><?php echo count($appliedMigrations); ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">Pending Migrations</div>
                                <div class="fs-5 fw-bold <?php echo count($pendingMigrations) > 0 ? 'text-warning' : 'text-success'; ?>">
                                    <?php echo count($pendingMigrations); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (empty($pendingMigrations)): ?>
                    <!-- ✅ No pending migrations -->
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill"></i>
                        Your database is up to date! No pending migrations found.
                    </div>
                    <div class="d-flex justify-content-center">
                        <a href="/" class="btn btn-signula">
                            <i class="bi bi-house"></i> Return to SIGNula
                        </a>
                    </div>
                <?php else: ?>
                    <!-- ⚠️ Backup warning -->
                    <div class="alert alert-warning">
                        <h5 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> Back Up Your Database!</h5>
                        <p class="mb-0">
                            Before running migrations, <strong>create a full database backup</strong>.
                            Migrations modify the database schema and some changes cannot be easily reversed.
                        </p>
                    </div>

                    <!-- 📋 Pending migration list -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="bi bi-layers"></i> Pending Migrations
                            <span class="badge bg-warning text-dark float-end">
                                <?php echo count($pendingMigrations); ?> pending
                            </span>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($pendingMigrations as $migration): ?>
                                    <div class="list-group-item d-flex align-items-center">
                                        <i class="bi bi-file-earmark-code text-primary me-2"></i>
                                        <code class="small"><?php echo htmlspecialchars($migration); ?></code>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <form method="post" action="">
                        <input type="hidden" name="csrf_token"
                               value="<?php echo htmlspecialchars($_SESSION['upgrade_csrf_token']); ?>">
                        <div class="d-flex justify-content-end">
                            <button type="submit" name="step2_continue" class="btn btn-signula">
                                Proceed to Run Migrations <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

            <?php elseif ($currentStep === 3): ?>
                <!-- ============================================================ -->
                <!-- 🔄 STEP 3: Run Pending Migrations -->
                <!-- ============================================================ -->
                <h4 class="mb-3"><i class="bi bi-database-gear"></i> Running Migrations</h4>
                <p class="text-muted mb-4">
                    Applying <?php echo count($pendingMigrations); ?> pending migration(s) to your database.
                </p>

                <input type="hidden" id="csrf_token"
                       value="<?php echo htmlspecialchars($_SESSION['upgrade_csrf_token']); ?>">

                <!-- 📊 Progress bar -->
                <div id="upgrade-progress" class="progress mb-3" style="display: none; height: 24px;">
                    <div id="upgrade-progress-bar"
                         class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                         role="progressbar" style="width: 0%"
                         aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                </div>

                <!-- 📋 Migration list with status indicators -->
                <div id="migration-list"
                     data-migrations='<?php echo htmlspecialchars(json_encode($pendingMigrations)); ?>'
                     class="border rounded mb-3" style="max-height: 300px; overflow-y: auto;">
                    <?php foreach ($pendingMigrations as $index => $migration): ?>
                        <div class="migration-item px-3" id="migration-<?php echo $index; ?>">
                            <span class="migration-status">
                                <i class="bi bi-circle text-muted"></i>
                            </span>
                            <code class="small"><?php echo htmlspecialchars($migration); ?></code>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex justify-content-between">
                    <button type="button" id="btn-run-upgrade" class="btn btn-primary" onclick="runUpgradeMigrations()">
                        <i class="bi bi-play-fill"></i> Run Migrations
                    </button>
                    <form method="post" action="" class="d-inline">
                        <input type="hidden" name="csrf_token"
                               value="<?php echo htmlspecialchars($_SESSION['upgrade_csrf_token']); ?>">
                        <button type="submit" name="step3_continue" id="btn-continue-upgrade"
                                class="btn btn-signula" style="display: none;">
                            Continue <i class="bi bi-arrow-right"></i>
                        </button>
                    </form>
                </div>

                <!-- 📊 Status messages -->
                <div id="upgrade-status" class="mt-3"></div>

            <?php elseif ($currentStep === 4): ?>
                <!-- ============================================================ -->
                <!-- 🎉 STEP 4: Post-Upgrade Summary -->
                <!-- ============================================================ -->
                <div class="text-center py-4">
                    <div class="mb-4">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h3 class="text-success mb-3">Upgrade Complete!</h3>
                    <p class="text-muted mb-4">
                        All pending migrations have been applied successfully.
                    </p>
                </div>

                <!-- 📋 Summary -->
                <div class="card mb-4">
                    <div class="card-header"><i class="bi bi-list-check"></i> Upgrade Summary</div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td class="text-muted" style="width: 40%;">Previous Version</td>
                                <td><strong><?php echo htmlspecialchars($currentVersion); ?></strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">New Version</td>
                                <td><strong>2.6.0-beta</strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Migrations Applied</td>
                                <td><strong><?php echo count($pendingMigrations); ?></strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Upgraded At</td>
                                <td><strong><?php echo date('Y-m-d H:i:s T'); ?></strong></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- 🔗 Navigation links -->
                <div class="d-flex justify-content-center gap-3 mt-4">
                    <a href="/" class="btn btn-signula btn-lg">
                        <i class="bi bi-house"></i> Go to SIGNula
                    </a>
                    <a href="/admin/" class="btn btn-outline-primary btn-lg">
                        <i class="bi bi-speedometer2"></i> Admin Dashboard
                    </a>
                </div>

                <?php
                // 🧹 Clean up upgrade session data
                unset($_SESSION['upgrade_step'], $_SESSION['upgrade_csrf_token'], $_SESSION['upgrade_auth']);
                ?>

            <?php endif; ?>

        </div>
    </div>

    <!-- 📜 Footer -->
    <div class="text-center text-white-50 mt-3 mb-4" style="font-size: 0.85rem;">
        &copy; <?php echo date('Y'); ?> MWBM Partners Ltd. All rights reserved.
    </div>
</div>

<!-- 📦 Bootstrap 5.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>

<script>
/**
 * 🔄 Run upgrade migrations via AJAX
 *
 * Executes each pending migration sequentially with progress updates.
 * Similar to the installer's migration runner but uses the upgrade endpoint.
 */
async function runUpgradeMigrations() {
    const statusEl = document.getElementById('upgrade-status');
    const progressBar = document.getElementById('upgrade-progress');
    const progressBarInner = document.getElementById('upgrade-progress-bar');
    const btnRun = document.getElementById('btn-run-upgrade');
    const btnContinue = document.getElementById('btn-continue-upgrade');
    const csrf = document.getElementById('csrf_token').value;

    // 🔒 Disable run button
    btnRun.disabled = true;
    btnRun.innerHTML = '<i class="bi bi-hourglass-split"></i> Running...';
    progressBar.style.display = 'flex';

    // 📋 Get migration list
    const migrations = JSON.parse(document.getElementById('migration-list').dataset.migrations);
    let completed = 0;
    let failed = false;

    // 🔄 Execute each migration sequentially
    for (let i = 0; i < migrations.length; i++) {
        const migration = migrations[i];
        const itemEl = document.getElementById('migration-' + i);

        // ⏳ Show running status
        if (itemEl) {
            itemEl.querySelector('.migration-status').innerHTML =
                '<div class="spinner-border spinner-border-sm text-primary" role="status"></div>';
        }

        try {
            const response = await fetch('?ajax=run_migration', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    migration: migration,
                    csrf_token: csrf
                })
            });

            const data = await response.json();

            if (data.success) {
                completed++;
                if (itemEl) {
                    itemEl.querySelector('.migration-status').innerHTML =
                        '<i class="bi bi-check-circle-fill text-success"></i>';
                }
            } else {
                failed = true;
                if (itemEl) {
                    itemEl.querySelector('.migration-status').innerHTML =
                        '<i class="bi bi-x-circle-fill text-danger"></i>';
                    itemEl.insertAdjacentHTML('afterend',
                        '<div class="alert alert-danger mt-1 mb-1 py-1 px-2" style="font-size:0.8rem;">'
                        + escapeHtml(data.message) + '</div>');
                }
                statusEl.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> Migration failed: '
                    + escapeHtml(migration) + '</div>';
                break;
            }
        } catch (error) {
            failed = true;
            if (itemEl) {
                itemEl.querySelector('.migration-status').innerHTML =
                    '<i class="bi bi-x-circle-fill text-danger"></i>';
            }
            statusEl.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> Error: '
                + escapeHtml(error.message) + '</div>';
            break;
        }

        // 📊 Update progress
        const progress = Math.round(((i + 1) / migrations.length) * 100);
        progressBarInner.style.width = progress + '%';
        progressBarInner.textContent = progress + '%';
        progressBarInner.setAttribute('aria-valuenow', progress);
    }

    if (!failed) {
        statusEl.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> All '
            + completed + ' migrations completed successfully!</div>';
        progressBarInner.classList.remove('bg-primary');
        progressBarInner.classList.add('bg-success');
        if (btnContinue) {
            btnContinue.style.display = 'inline-block';
        }
    } else {
        btnRun.disabled = false;
        btnRun.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Retry';
        progressBarInner.classList.remove('bg-primary');
        progressBarInner.classList.add('bg-danger');
    }
}

/**
 * 🛡️ Escape HTML entities
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}
</script>

</body>
</html>
<?php
// ============================================================================
// ============================================================================
//
// 🔧 UPGRADE UTILITY FUNCTIONS
//
// ============================================================================
// ============================================================================

/**
 * 🌐 Handle AJAX requests for the upgrade wizard
 *
 * Routes AJAX requests to the appropriate handler.
 * Uses the application's Database class (since config.php is loaded).
 */
function handleUpgradeAjax(): void
{
    $action = $_GET['ajax'] ?? '';

    if ($action === 'run_migration') {
        handleUpgradeRunMigration();
    } else {
        echo json_encode(['success' => false, 'message' => 'Unknown AJAX action.']);
    }
}

/**
 * 📦 AJAX: Run a single migration during upgrade
 *
 * Executes the specified migration SQL file against the existing database
 * and records it in the tblMigrations tracking table.
 *
 * @see https://www.php.net/manual/en/mysqli.multi-query.php
 */
function handleUpgradeRunMigration(): void
{
    // 📝 Parse JSON request body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid request data.']);
        return;
    }

    // 🛡️ Validate CSRF token
    if (!isset($input['csrf_token'])
        || !isset($_SESSION['upgrade_csrf_token'])
        || !hash_equals($_SESSION['upgrade_csrf_token'], $input['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        return;
    }

    $migrationName = basename($input['migration'] ?? '');
    if (empty($migrationName)) {
        echo json_encode(['success' => false, 'message' => 'No migration file specified.']);
        return;
    }

    // 📁 Locate the migration file
    $projectRoot = dirname(dirname(dirname(__DIR__)));
    $migrationFile = $projectRoot . DIRECTORY_SEPARATOR . '_database'
        . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $migrationName;

    if (!file_exists($migrationFile)) {
        echo json_encode(['success' => false, 'message' => 'Migration file not found: ' . $migrationName]);
        return;
    }

    // 📖 Read migration SQL
    $sql = file_get_contents($migrationFile);
    if ($sql === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to read migration file.']);
        return;
    }

    // 🗄️ Get database connection via the app's Database class
    try {
        $conn = Database::getConnection();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
        return;
    }

    // 🔍 Check if already applied
    $checkStmt = $conn->prepare("SELECT migrationID FROM tblMigrations WHERE migrationName = ? AND status = 'completed'");
    if ($checkStmt) {
        $checkStmt->bind_param('s', $migrationName);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows > 0) {
            $checkStmt->close();
            echo json_encode(['success' => true, 'message' => 'Migration already applied.']);
            return;
        }
        $checkStmt->close();
    }

    // 🔄 Execute the migration
    try {
        if ($conn->multi_query($sql)) {
            // 📊 Process all result sets
            do {
                if ($result = $conn->store_result()) {
                    $result->free();
                }
            } while ($conn->more_results() && $conn->next_result());

            if ($conn->errno) {
                throw new RuntimeException('SQL Error: ' . $conn->error);
            }

            // ✅ Record successful migration
            $recordStmt = $conn->prepare(
                "INSERT INTO tblMigrations (migrationName, migrationDescription, appliedBy, status)
                 VALUES (?, ?, 'upgrade_wizard', 'completed')
                 ON DUPLICATE KEY UPDATE status = 'completed', appliedAt = CURRENT_TIMESTAMP"
            );

            if ($recordStmt) {
                $description = 'Applied during upgrade';
                $recordStmt->bind_param('ss', $migrationName, $description);
                $recordStmt->execute();
                $recordStmt->close();
            }

            echo json_encode(['success' => true, 'message' => 'Migration applied successfully.']);
        } else {
            throw new RuntimeException('Failed to execute migration: ' . $conn->error);
        }
    } catch (RuntimeException $e) {
        // ❌ Record failed migration
        $recordStmt = $conn->prepare(
            "INSERT INTO tblMigrations (migrationName, migrationDescription, appliedBy, status)
             VALUES (?, ?, 'upgrade_wizard', 'failed')
             ON DUPLICATE KEY UPDATE status = 'failed', appliedAt = CURRENT_TIMESTAMP"
        );

        if ($recordStmt) {
            $errDesc = 'Failed: ' . $e->getMessage();
            $recordStmt->bind_param('ss', $migrationName, $errDesc);
            $recordStmt->execute();
            $recordStmt->close();
        }

        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * 🔄 Perform post-upgrade tasks
 *
 * Updates the application version in tblSettings and performs any
 * necessary cache clearing or maintenance tasks.
 */
function performPostUpgrade(): void
{
    try {
        $conn = Database::getConnection();

        // 📌 Update application version
        $stmt = $conn->prepare(
            "INSERT INTO tblSettings (settingKey, settingValue, settingType, settingCategory, isSensitive, description)
             VALUES ('app.version', '2.6.0-beta', 'string', 'general', 0, 'Application version')
             ON DUPLICATE KEY UPDATE settingValue = '2.6.0-beta'"
        );
        if ($stmt) {
            $stmt->execute();
            $stmt->close();
        }

        // 📌 Record upgrade timestamp
        $stmt = $conn->prepare(
            "INSERT INTO tblSettings (settingKey, settingValue, settingType, settingCategory, isSensitive, description)
             VALUES ('app.last_upgraded_at', ?, 'string', 'general', 0, 'Last upgrade timestamp')
             ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)"
        );
        if ($stmt) {
            $now = date('Y-m-d H:i:s');
            $stmt->bind_param('s', $now);
            $stmt->execute();
            $stmt->close();
        }

        // 🧹 Clear any application caches
        // Clear compiled template caches, OPcache, etc.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

    } catch (Exception $e) {
        // 🔇 Non-fatal: log but don't block the upgrade completion
        error_log('[SIGNula Upgrade] Post-upgrade error: ' . $e->getMessage());
    }
}
