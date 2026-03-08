<?php
/**
 * ============================================================================
 * 🚀 SIGNula Universal Login System - Installation Wizard
 * ============================================================================
 *
 * Purpose: Web-based installation wizard for first-time SIGNula setup.
 *          Guides the administrator through system requirements, database
 *          configuration, migration execution, initial settings, and admin
 *          account creation.
 *
 * ⚠️ IMPORTANT: This file does NOT use SIGNULA_INIT guard because it runs
 *    BEFORE the application is configured. It operates independently of
 *    config.php and database.php since those require a working DB.
 *
 * Security:
 *   - Blocked by .htaccess once .installed lock file exists
 *   - Uses PHP sessions for state persistence across steps
 *   - All DB operations use MySQLi prepared statements
 *   - CSRF protection on all form submissions
 *
 * @package    SIGNula
 * @subpackage Installer
 * @version    1.0.0
 * @link       https://SIGNula.id
 * @see        https://www.php.net/manual/en/book.mysqli.php
 * ============================================================================
 */

// 🔧 Error reporting for installer (always show errors during installation)
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// 🚀 Start session for multi-step wizard state persistence
// @see https://www.php.net/manual/en/function.session-start.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================================
// 🔒 SECURITY: Check if already installed
// ============================================================================
// The .installed lock file prevents re-running the installer.
// This is a PHP-level check in addition to the .htaccess redirect.
$lockFile = __DIR__ . DIRECTORY_SEPARATOR . '.installed';
if (file_exists($lockFile)) {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><title>SIGNula - Already Installed</title></head><body>'
        . '<h1>SIGNula is already installed</h1>'
        . '<p>If you need to reinstall, please delete the <code>.installed</code> file in the install directory.</p>'
        . '<p><a href="/">Go to SIGNula</a></p></body></html>');
}

// ============================================================================
// 🛡️ CSRF Token Management
// ============================================================================
// Generate a CSRF token for form protection
// @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
if (empty($_SESSION['install_csrf_token'])) {
    $_SESSION['install_csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * 🔐 Validate CSRF token from form submission
 *
 * @param string $token The token submitted with the form
 * @return bool True if token is valid
 */
function validateCsrfToken(string $token): bool
{
    return isset($_SESSION['install_csrf_token'])
        && hash_equals($_SESSION['install_csrf_token'], $token);
}

// ============================================================================
// 📌 STEP MANAGEMENT
// ============================================================================
// Determine current installation step (1-6)
$currentStep = isset($_SESSION['install_step']) ? (int)$_SESSION['install_step'] : 1;

// 🎯 Handle AJAX requests separately (DB test, migration execution)
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    handleAjaxRequest();
    exit;
}

// 🔄 Handle form submissions for each step
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ✅ Validate CSRF token on all POST requests
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $errorMessage = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $errorMessage = handleStepSubmission($currentStep);
        if ($errorMessage === null) {
            // ➡️ Advance to next step on success
            $currentStep++;
            $_SESSION['install_step'] = $currentStep;
        }
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
    <title>SIGNula - Installation Wizard (Step <?php echo $currentStep; ?> of 6)</title>

    <!-- 🎨 Bootstrap 5.3 CDN for responsive styling -->
    <!-- @see https://getbootstrap.com/docs/5.3/ -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YcnS/1Vh6e33MGA/VDEsEe0HheFl5LBl5DAe"
          crossorigin="anonymous">

    <!-- 🎨 Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <style>
        /* 🎨 Custom installer styles */
        :root {
            --signula-primary: #4f46e5;
            --signula-primary-hover: #4338ca;
            --signula-success: #059669;
            --signula-danger: #dc2626;
            --signula-warning: #d97706;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        .installer-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .installer-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .installer-header {
            background: linear-gradient(135deg, var(--signula-primary) 0%, #7c3aed 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .installer-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .installer-body {
            padding: 2rem;
        }

        /* 📊 Step progress indicator */
        .step-progress {
            display: flex;
            justify-content: space-between;
            padding: 1.5rem 2rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            position: relative;
        }

        .step-item::after {
            content: '';
            position: absolute;
            top: 15px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: #e2e8f0;
            z-index: 0;
        }

        .step-item:last-child::after {
            display: none;
        }

        .step-item.active .step-circle,
        .step-item.completed .step-circle {
            background: var(--signula-primary);
            color: white;
        }

        .step-item.active::after,
        .step-item.completed::after {
            background: var(--signula-primary);
        }

        .step-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.85rem;
            z-index: 1;
            transition: all 0.3s ease;
        }

        .step-label {
            font-size: 0.7rem;
            color: #64748b;
            margin-top: 0.4rem;
            text-align: center;
        }

        .step-item.active .step-label {
            color: var(--signula-primary);
            font-weight: 600;
        }

        /* ✅ Requirement check items */
        .req-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .req-item:last-child {
            border-bottom: none;
        }

        .req-status {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 0.85rem;
        }

        .req-pass {
            background: #dcfce7;
            color: var(--signula-success);
        }

        .req-fail {
            background: #fee2e2;
            color: var(--signula-danger);
        }

        .req-warn {
            background: #fef3c7;
            color: var(--signula-warning);
        }

        /* 📊 Migration progress bar */
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

        /* 🔑 Password strength meter */
        .strength-meter {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .strength-meter-fill {
            height: 100%;
            border-radius: 2px;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .btn-signula {
            background: var(--signula-primary);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            transition: background 0.2s ease;
        }

        .btn-signula:hover {
            background: var(--signula-primary-hover);
            color: white;
        }

        /* 📱 Responsive adjustments */
        @media (max-width: 576px) {
            .step-label { display: none; }
            .installer-body { padding: 1.5rem; }
        }
    </style>
</head>
<body>

<div class="installer-container">
    <div class="installer-card">

        <!-- 🏷️ Header -->
        <div class="installer-header">
            <h1><i class="bi bi-shield-lock"></i> SIGNula</h1>
            <p class="mb-0 opacity-75">Installation Wizard</p>
        </div>

        <!-- 📊 Step Progress Bar -->
        <div class="step-progress">
            <?php
            // 🔢 Define step labels for the progress indicator
            $steps = [
                1 => 'Requirements',
                2 => 'Database',
                3 => 'Migrations',
                4 => 'Configuration',
                5 => 'Admin Account',
                6 => 'Complete'
            ];
            foreach ($steps as $stepNum => $stepLabel): ?>
                <div class="step-item <?php echo $stepNum < $currentStep ? 'completed' : ($stepNum === $currentStep ? 'active' : ''); ?>">
                    <div class="step-circle">
                        <?php if ($stepNum < $currentStep): ?>
                            <i class="bi bi-check"></i>
                        <?php else: ?>
                            <?php echo $stepNum; ?>
                        <?php endif; ?>
                    </div>
                    <span class="step-label"><?php echo htmlspecialchars($stepLabel); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- 📝 Step Content -->
        <div class="installer-body">

            <?php
            // ⚠️ Display error message if present
            if (!empty($errorMessage)): ?>
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div><?php echo htmlspecialchars($errorMessage); ?></div>
                </div>
            <?php endif; ?>

            <?php
            // 🎯 Render the appropriate step content
            switch ($currentStep) {
                case 1:
                    renderStep1_Requirements();
                    break;
                case 2:
                    renderStep2_Database();
                    break;
                case 3:
                    renderStep3_Migrations();
                    break;
                case 4:
                    renderStep4_Configuration();
                    break;
                case 5:
                    renderStep5_AdminAccount();
                    break;
                case 6:
                    renderStep6_Complete();
                    break;
                default:
                    // 🔄 Reset to step 1 if invalid step
                    $_SESSION['install_step'] = 1;
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
            }
            ?>

        </div>
    </div>

    <!-- 📜 Footer -->
    <div class="text-center text-white-50 mt-3 mb-4" style="font-size: 0.85rem;">
        &copy; <?php echo date('Y'); ?> MWBM Partners Ltd. All rights reserved.
    </div>
</div>

<!-- 📦 Bootstrap 5.3 JS Bundle (includes Popper.js) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>

<script>
/**
 * ============================================================================
 * 🔧 SIGNula Installer - Client-Side JavaScript
 * ============================================================================
 * Handles AJAX operations for DB testing and migration execution.
 */

/**
 * 🔗 Test database connection via AJAX
 *
 * Sends the entered DB credentials to the server for validation
 * and displays the result in the status area.
 */
function testDbConnection() {
    // 📝 Gather form field values
    const host = document.getElementById('db_host').value.trim();
    const port = document.getElementById('db_port').value.trim();
    const name = document.getElementById('db_name').value.trim();
    const user = document.getElementById('db_user').value.trim();
    const pass = document.getElementById('db_pass').value;
    const csrf = document.getElementById('csrf_token').value;

    const statusEl = document.getElementById('db-test-status');
    statusEl.innerHTML = '<div class="alert alert-info"><i class="bi bi-hourglass-split"></i> Testing connection...</div>';

    // 🌐 Send AJAX request to test connection
    // @see https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API
    fetch('?ajax=test_db', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            host: host,
            port: port,
            database: name,
            username: user,
            password: pass,
            csrf_token: csrf
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            statusEl.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> '
                + escapeHtml(data.message) + '</div>';
            // ✅ Enable the continue button on successful connection
            const continueBtn = document.getElementById('btn-continue');
            if (continueBtn) {
                continueBtn.disabled = false;
            }
        } else {
            statusEl.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> '
                + escapeHtml(data.message) + '</div>';
        }
    })
    .catch(error => {
        statusEl.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> Connection test failed: '
            + escapeHtml(error.message) + '</div>';
    });
}

/**
 * 🗄️ Run database migrations via AJAX
 *
 * Executes each pending migration one at a time with progress updates.
 * Called from Step 3 of the installation wizard.
 */
async function runMigrations() {
    const statusEl = document.getElementById('migration-status');
    const progressBar = document.getElementById('migration-progress');
    const progressBarInner = document.getElementById('migration-progress-bar');
    const btnRun = document.getElementById('btn-run-migrations');
    const btnContinue = document.getElementById('btn-continue-migrations');
    const csrf = document.getElementById('csrf_token').value;

    // 🔒 Disable run button to prevent double-clicks
    btnRun.disabled = true;
    btnRun.innerHTML = '<i class="bi bi-hourglass-split"></i> Running...';
    progressBar.style.display = 'block';

    // 📋 Get list of migration files from the data attribute
    const migrations = JSON.parse(document.getElementById('migration-list').dataset.migrations);
    let completed = 0;
    let failed = false;

    // 🔄 Execute each migration sequentially
    for (let i = 0; i < migrations.length; i++) {
        const migration = migrations[i];
        const itemEl = document.getElementById('migration-' + i);

        // ⏳ Set status to "running"
        if (itemEl) {
            itemEl.querySelector('.migration-status').innerHTML = '<div class="spinner-border spinner-border-sm text-primary" role="status"></div>';
        }

        try {
            // 🌐 Send migration execution request
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
                // ✅ Migration succeeded
                completed++;
                if (itemEl) {
                    itemEl.querySelector('.migration-status').innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
                }
            } else {
                // ❌ Migration failed
                failed = true;
                if (itemEl) {
                    itemEl.querySelector('.migration-status').innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i>';
                    itemEl.insertAdjacentHTML('afterend',
                        '<div class="alert alert-danger mt-1 mb-1 py-1 px-2" style="font-size:0.8rem;">'
                        + escapeHtml(data.message) + '</div>');
                }
                statusEl.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> Migration failed: '
                    + escapeHtml(migration) + '<br>' + escapeHtml(data.message) + '</div>';
                break;
            }
        } catch (error) {
            // 💥 Network or server error
            failed = true;
            if (itemEl) {
                itemEl.querySelector('.migration-status').innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i>';
            }
            statusEl.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> Error: '
                + escapeHtml(error.message) + '</div>';
            break;
        }

        // 📊 Update progress bar
        const progress = Math.round(((i + 1) / migrations.length) * 100);
        progressBarInner.style.width = progress + '%';
        progressBarInner.textContent = progress + '%';
        progressBarInner.setAttribute('aria-valuenow', progress);
    }

    if (!failed) {
        // 🎉 All migrations completed successfully
        statusEl.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> All '
            + completed + ' migrations completed successfully!</div>';
        progressBarInner.classList.remove('bg-primary');
        progressBarInner.classList.add('bg-success');
        // ✅ Enable continue button
        if (btnContinue) {
            btnContinue.style.display = 'inline-block';
        }
    } else {
        // ⚠️ Show retry/rollback options
        btnRun.disabled = false;
        btnRun.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Retry Migrations';
        progressBarInner.classList.remove('bg-primary');
        progressBarInner.classList.add('bg-danger');
    }
}

/**
 * 🔒 Run the base installation SQL (complete schema)
 *
 * Executes the consolidated installation SQL before running individual migrations.
 */
async function runBaseInstall() {
    const statusEl = document.getElementById('base-install-status');
    const btnInstall = document.getElementById('btn-run-base-install');
    const csrf = document.getElementById('csrf_token').value;

    btnInstall.disabled = true;
    btnInstall.innerHTML = '<i class="bi bi-hourglass-split"></i> Installing base schema...';
    statusEl.innerHTML = '<div class="alert alert-info"><i class="bi bi-hourglass-split"></i> Running base installation script. This may take a moment...</div>';

    try {
        const response = await fetch('?ajax=run_base_install', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrf })
        });

        const data = await response.json();

        if (data.success) {
            statusEl.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> '
                + escapeHtml(data.message) + '</div>';
            // 🔓 Show the migration section now
            document.getElementById('migration-section').style.display = 'block';
        } else {
            statusEl.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> '
                + escapeHtml(data.message) + '</div>';
            btnInstall.disabled = false;
            btnInstall.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Retry Base Install';
        }
    } catch (error) {
        statusEl.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> Error: '
            + escapeHtml(error.message) + '</div>';
        btnInstall.disabled = false;
        btnInstall.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Retry Base Install';
    }
}

/**
 * 🔑 Calculate and display password strength
 *
 * @param {string} password The password to evaluate
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html
 */
function checkPasswordStrength(password) {
    const meter = document.getElementById('strength-meter-fill');
    const label = document.getElementById('strength-label');
    let strength = 0;

    // 📏 Length checks
    if (password.length >= 8) strength++;
    if (password.length >= 12) strength++;
    if (password.length >= 16) strength++;

    // 🔤 Character variety checks
    if (/[a-z]/.test(password)) strength++;
    if (/[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^a-zA-Z0-9]/.test(password)) strength++;

    // 📊 Map score to visual representation
    const levels = [
        { width: '0%',   color: '#e2e8f0', text: '' },
        { width: '15%',  color: '#dc2626', text: 'Very Weak' },
        { width: '30%',  color: '#dc2626', text: 'Weak' },
        { width: '45%',  color: '#d97706', text: 'Fair' },
        { width: '60%',  color: '#d97706', text: 'Moderate' },
        { width: '75%',  color: '#059669', text: 'Strong' },
        { width: '90%',  color: '#059669', text: 'Very Strong' },
        { width: '100%', color: '#047857', text: 'Excellent' }
    ];

    const level = levels[Math.min(strength, levels.length - 1)];
    meter.style.width = level.width;
    meter.style.backgroundColor = level.color;
    label.textContent = level.text;
    label.style.color = level.color;
}

/**
 * 🛡️ Escape HTML entities to prevent XSS in dynamic content
 *
 * @param {string} text The text to escape
 * @return {string} The escaped text
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

/**
 * 🔑 Generate a random encryption key and populate the form field
 */
function generateEncryptionKey() {
    // 🎲 Generate 32 random bytes as hex (64 hex chars = 256-bit key)
    const array = new Uint8Array(32);
    crypto.getRandomValues(array);
    const key = Array.from(array, b => b.toString(16).padStart(2, '0')).join('');
    document.getElementById('encryption_key').value = key;
}
</script>

</body>
</html>
<?php
// ============================================================================
// ============================================================================
//
// 🔧 STEP RENDERING FUNCTIONS
//
// Each function renders the HTML form content for its respective step.
//
// ============================================================================
// ============================================================================

/**
 * ============================================================================
 * 📋 STEP 1: System Requirements Check
 * ============================================================================
 *
 * Checks PHP version, required/optional extensions, and directory permissions.
 * All requirements must pass before proceeding to the next step.
 */
function renderStep1_Requirements(): void
{
    // 🔍 Define requirements to check
    $requirements = checkRequirements();
    $allPassed = true;

    foreach ($requirements as $req) {
        if ($req['required'] && !$req['passed']) {
            $allPassed = false;
            break;
        }
    }
    ?>
    <h4 class="mb-3"><i class="bi bi-clipboard-check"></i> Step 1: System Requirements</h4>
    <p class="text-muted mb-4">
        Checking that your server meets the minimum requirements for SIGNula.
    </p>

    <!-- 📊 Requirements List -->
    <div class="border rounded mb-4">
        <?php foreach ($requirements as $req): ?>
            <div class="req-item">
                <div class="req-status <?php echo $req['passed'] ? 'req-pass' : ($req['required'] ? 'req-fail' : 'req-warn'); ?>">
                    <i class="bi <?php echo $req['passed'] ? 'bi-check' : ($req['required'] ? 'bi-x' : 'bi-exclamation'); ?>"></i>
                </div>
                <div class="flex-grow-1">
                    <strong><?php echo htmlspecialchars($req['name']); ?></strong>
                    <br>
                    <small class="text-muted"><?php echo htmlspecialchars($req['description']); ?></small>
                </div>
                <div class="text-end">
                    <?php if ($req['passed']): ?>
                        <span class="badge bg-success">Pass</span>
                    <?php elseif ($req['required']): ?>
                        <span class="badge bg-danger">Required</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">Optional</span>
                    <?php endif; ?>
                    <?php if (!empty($req['current'])): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($req['current']); ?></small>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ➡️ Continue button (only if all required checks pass) -->
    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['install_csrf_token']); ?>">
        <?php if ($allPassed): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill"></i>
                All required system requirements are met. You can proceed with the installation.
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" name="step1_continue" class="btn btn-signula">
                    Continue <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        <?php else: ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Some required system requirements are not met. Please resolve them before continuing.
            </div>
            <div class="d-flex justify-content-end">
                <a href="?" class="btn btn-secondary">
                    <i class="bi bi-arrow-clockwise"></i> Re-check
                </a>
            </div>
        <?php endif; ?>
    </form>
    <?php
}

/**
 * ============================================================================
 * 🗄️ STEP 2: Database Configuration
 * ============================================================================
 *
 * Collects database connection credentials and tests the connection.
 * Provides option to create the database if it doesn't exist.
 */
function renderStep2_Database(): void
{
    // 📝 Pre-populate with session values if previously entered
    $dbHost = $_SESSION['db_config']['host'] ?? 'localhost';
    $dbPort = $_SESSION['db_config']['port'] ?? '3306';
    $dbName = $_SESSION['db_config']['database'] ?? 'signula';
    $dbUser = $_SESSION['db_config']['username'] ?? '';
    $dbPass = $_SESSION['db_config']['password'] ?? '';
    $createDb = $_SESSION['db_config']['create_db'] ?? true;
    ?>
    <h4 class="mb-3"><i class="bi bi-database"></i> Step 2: Database Configuration</h4>
    <p class="text-muted mb-4">
        Enter your MySQL/MariaDB database credentials. The installer will connect and prepare the database.
    </p>

    <form method="post" action="" id="db-form">
        <input type="hidden" name="csrf_token" id="csrf_token"
               value="<?php echo htmlspecialchars($_SESSION['install_csrf_token']); ?>">

        <div class="row mb-3">
            <div class="col-md-8">
                <label for="db_host" class="form-label">Database Host</label>
                <input type="text" class="form-control" id="db_host" name="db_host"
                       value="<?php echo htmlspecialchars($dbHost); ?>"
                       placeholder="localhost" required>
                <div class="form-text">Usually <code>localhost</code> or a hostname provided by your host.</div>
            </div>
            <div class="col-md-4">
                <label for="db_port" class="form-label">Port</label>
                <input type="number" class="form-control" id="db_port" name="db_port"
                       value="<?php echo htmlspecialchars($dbPort); ?>"
                       placeholder="3306" min="1" max="65535" required>
            </div>
        </div>

        <div class="mb-3">
            <label for="db_name" class="form-label">Database Name</label>
            <input type="text" class="form-control" id="db_name" name="db_name"
                   value="<?php echo htmlspecialchars($dbName); ?>"
                   placeholder="signula" required>
        </div>

        <div class="mb-3">
            <label for="db_user" class="form-label">Database Username</label>
            <input type="text" class="form-control" id="db_user" name="db_user"
                   value="<?php echo htmlspecialchars($dbUser); ?>"
                   placeholder="Enter database username" required>
        </div>

        <div class="mb-3">
            <label for="db_pass" class="form-label">Database Password</label>
            <input type="password" class="form-control" id="db_pass" name="db_pass"
                   value="<?php echo htmlspecialchars($dbPass); ?>"
                   placeholder="Enter database password">
        </div>

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="create_db" name="create_db"
                   <?php echo $createDb ? 'checked' : ''; ?>>
            <label class="form-check-label" for="create_db">
                Create database if it doesn't exist
            </label>
        </div>

        <!-- 🔗 Test connection result area -->
        <div id="db-test-status" class="mb-3"></div>

        <div class="d-flex justify-content-between">
            <button type="button" class="btn btn-outline-secondary" onclick="testDbConnection()">
                <i class="bi bi-plug"></i> Test Connection
            </button>
            <button type="submit" name="step2_continue" id="btn-continue" class="btn btn-signula">
                Continue <i class="bi bi-arrow-right"></i>
            </button>
        </div>
    </form>
    <?php
}

/**
 * ============================================================================
 * 📦 STEP 3: Run Database Migrations
 * ============================================================================
 *
 * First runs the base installation SQL (consolidated schema), then
 * executes individual migration files in order with progress tracking.
 */
function renderStep3_Migrations(): void
{
    // 📁 Locate migration files
    // The _database directory is outside the web root
    $projectRoot = dirname(dirname(dirname(__DIR__)));
    $migrationsDir = $projectRoot . DIRECTORY_SEPARATOR . '_database' . DIRECTORY_SEPARATOR . 'migrations';
    $baseInstallFile = $projectRoot . DIRECTORY_SEPARATOR . '_database' . DIRECTORY_SEPARATOR . 'signula_complete_install_v2.5.0.sql';

    // 📋 Get sorted list of migration files
    $migrations = [];
    if (is_dir($migrationsDir)) {
        $files = scandir($migrationsDir);
        foreach ($files as $file) {
            // 🔍 Only include .sql files, skip . and ..
            if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                $migrations[] = $file;
            }
        }
        // 🔢 Sort naturally (001_ before 002_ etc.)
        natsort($migrations);
        $migrations = array_values($migrations);
    }

    $hasBaseInstall = file_exists($baseInstallFile);
    ?>
    <h4 class="mb-3"><i class="bi bi-database-gear"></i> Step 3: Database Schema & Migrations</h4>
    <p class="text-muted mb-4">
        The installer will set up the database schema and apply all migrations.
    </p>

    <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($_SESSION['install_csrf_token']); ?>">

    <?php if ($hasBaseInstall): ?>
        <!-- 🏗️ Base Installation Section -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-database-add"></i> Base Schema Installation
            </div>
            <div class="card-body">
                <p>The base schema (<code>signula_complete_install_v2.5.0.sql</code>) creates all core tables, views, and default settings.</p>
                <div id="base-install-status" class="mb-3"></div>
                <button type="button" id="btn-run-base-install" class="btn btn-primary" onclick="runBaseInstall()">
                    <i class="bi bi-play-fill"></i> Install Base Schema
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- 📦 Migrations Section (hidden until base install completes, or shown if no base install) -->
    <div id="migration-section" style="display: <?php echo $hasBaseInstall ? 'none' : 'block'; ?>;">
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-layers"></i> Database Migrations
                <span class="badge bg-primary float-end"><?php echo count($migrations); ?> migrations</span>
            </div>
            <div class="card-body">
                <?php if (empty($migrations)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No migration files found. The base schema includes all tables.
                    </div>
                <?php else: ?>
                    <!-- 📊 Progress bar -->
                    <div id="migration-progress" class="progress mb-3" style="display: none; height: 24px;">
                        <div id="migration-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                             role="progressbar" style="width: 0%"
                             aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                    </div>

                    <!-- 📋 Migration file list with status indicators -->
                    <div id="migration-list" data-migrations='<?php echo htmlspecialchars(json_encode($migrations)); ?>'
                         class="border rounded mb-3" style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($migrations as $index => $migration): ?>
                            <div class="migration-item px-3" id="migration-<?php echo $index; ?>">
                                <span class="migration-status">
                                    <i class="bi bi-circle text-muted"></i>
                                </span>
                                <code class="small"><?php echo htmlspecialchars($migration); ?></code>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" id="btn-run-migrations" class="btn btn-primary" onclick="runMigrations()">
                        <i class="bi bi-play-fill"></i> Run Migrations
                    </button>
                <?php endif; ?>

                <!-- 📊 Migration status messages -->
                <div id="migration-status" class="mt-3"></div>
            </div>
        </div>

        <!-- ➡️ Continue button (hidden until migrations complete) -->
        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['install_csrf_token']); ?>">
            <div class="d-flex justify-content-end">
                <button type="submit" name="step3_continue" id="btn-continue-migrations"
                        class="btn btn-signula" style="display: <?php echo empty($migrations) ? 'inline-block' : 'none'; ?>;">
                    Continue <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </form>
    </div>
    <?php
}

/**
 * ============================================================================
 * ⚙️ STEP 4: Initial Configuration
 * ============================================================================
 *
 * Configures core application settings: site name, URL, encryption key,
 * default currency, and optional SMTP settings.
 */
function renderStep4_Configuration(): void
{
    // 📝 Pre-populate with session values if available
    $siteName = $_SESSION['site_config']['site_name'] ?? 'SIGNula';
    $siteUrl = $_SESSION['site_config']['site_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'signula.id'));
    $adminEmail = $_SESSION['site_config']['admin_email'] ?? '';
    $currency = $_SESSION['site_config']['currency'] ?? 'GBP';
    $smtpHost = $_SESSION['site_config']['smtp_host'] ?? '';
    $smtpPort = $_SESSION['site_config']['smtp_port'] ?? '587';
    $smtpUser = $_SESSION['site_config']['smtp_user'] ?? '';
    $smtpPass = $_SESSION['site_config']['smtp_pass'] ?? '';
    $smtpEncryption = $_SESSION['site_config']['smtp_encryption'] ?? 'tls';
    ?>
    <h4 class="mb-3"><i class="bi bi-gear"></i> Step 4: Initial Configuration</h4>
    <p class="text-muted mb-4">
        Configure the core settings for your SIGNula installation.
    </p>

    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['install_csrf_token']); ?>">

        <!-- 🏷️ Site Information -->
        <h5 class="mb-3"><i class="bi bi-globe"></i> Site Information</h5>

        <div class="row mb-3">
            <div class="col-md-6">
                <label for="site_name" class="form-label">Site Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="site_name" name="site_name"
                       value="<?php echo htmlspecialchars($siteName); ?>" required>
            </div>
            <div class="col-md-6">
                <label for="site_url" class="form-label">Site URL <span class="text-danger">*</span></label>
                <input type="url" class="form-control" id="site_url" name="site_url"
                       value="<?php echo htmlspecialchars($siteUrl); ?>"
                       placeholder="https://signula.id" required>
                <div class="form-text">Include <code>https://</code> — no trailing slash.</div>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label for="admin_email" class="form-label">Admin Email <span class="text-danger">*</span></label>
                <input type="email" class="form-control" id="admin_email" name="admin_email"
                       value="<?php echo htmlspecialchars($adminEmail); ?>"
                       placeholder="admin@signula.id" required>
            </div>
            <div class="col-md-6">
                <label for="currency" class="form-label">Default Currency</label>
                <select class="form-select" id="currency" name="currency">
                    <option value="GBP" <?php echo $currency === 'GBP' ? 'selected' : ''; ?>>GBP (£) - British Pound</option>
                    <option value="USD" <?php echo $currency === 'USD' ? 'selected' : ''; ?>>USD ($) - US Dollar</option>
                    <option value="EUR" <?php echo $currency === 'EUR' ? 'selected' : ''; ?>>EUR (€) - Euro</option>
                    <option value="AUD" <?php echo $currency === 'AUD' ? 'selected' : ''; ?>>AUD ($) - Australian Dollar</option>
                    <option value="CAD" <?php echo $currency === 'CAD' ? 'selected' : ''; ?>>CAD ($) - Canadian Dollar</option>
                    <option value="JPY" <?php echo $currency === 'JPY' ? 'selected' : ''; ?>>JPY (¥) - Japanese Yen</option>
                    <option value="CHF" <?php echo $currency === 'CHF' ? 'selected' : ''; ?>>CHF (Fr) - Swiss Franc</option>
                    <option value="NZD" <?php echo $currency === 'NZD' ? 'selected' : ''; ?>>NZD ($) - New Zealand Dollar</option>
                    <option value="INR" <?php echo $currency === 'INR' ? 'selected' : ''; ?>>INR (₹) - Indian Rupee</option>
                    <option value="BRL" <?php echo $currency === 'BRL' ? 'selected' : ''; ?>>BRL (R$) - Brazilian Real</option>
                </select>
            </div>
        </div>

        <!-- 🔐 Security Settings -->
        <h5 class="mt-4 mb-3"><i class="bi bi-shield-lock"></i> Security</h5>

        <div class="mb-3">
            <label for="encryption_key" class="form-label">
                Global Encryption Key <span class="text-danger">*</span>
            </label>
            <div class="input-group">
                <input type="text" class="form-control font-monospace" id="encryption_key" name="encryption_key"
                       value="<?php echo htmlspecialchars($_SESSION['site_config']['encryption_key'] ?? ''); ?>"
                       placeholder="Click 'Generate' to create a secure key" required>
                <button type="button" class="btn btn-outline-primary" onclick="generateEncryptionKey()">
                    <i class="bi bi-key"></i> Generate
                </button>
            </div>
            <div class="form-text">
                <i class="bi bi-exclamation-triangle text-warning"></i>
                This key is used to encrypt sensitive data (API keys, secrets, etc.).
                <strong>Store this key securely — if lost, encrypted data cannot be recovered.</strong>
            </div>
        </div>

        <!-- 📧 SMTP Settings (Optional) -->
        <h5 class="mt-4 mb-3">
            <i class="bi bi-envelope"></i> SMTP Configuration
            <span class="badge bg-secondary">Optional</span>
        </h5>
        <p class="text-muted small">You can configure email settings later from the admin panel.</p>

        <div class="row mb-3">
            <div class="col-md-8">
                <label for="smtp_host" class="form-label">SMTP Host</label>
                <input type="text" class="form-control" id="smtp_host" name="smtp_host"
                       value="<?php echo htmlspecialchars($smtpHost); ?>"
                       placeholder="smtp.example.com">
            </div>
            <div class="col-md-4">
                <label for="smtp_port" class="form-label">SMTP Port</label>
                <input type="number" class="form-control" id="smtp_port" name="smtp_port"
                       value="<?php echo htmlspecialchars($smtpPort); ?>"
                       placeholder="587" min="1" max="65535">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label for="smtp_user" class="form-label">SMTP Username</label>
                <input type="text" class="form-control" id="smtp_user" name="smtp_user"
                       value="<?php echo htmlspecialchars($smtpUser); ?>"
                       placeholder="user@example.com">
            </div>
            <div class="col-md-6">
                <label for="smtp_pass" class="form-label">SMTP Password</label>
                <input type="password" class="form-control" id="smtp_pass" name="smtp_pass"
                       value="<?php echo htmlspecialchars($smtpPass); ?>">
            </div>
        </div>

        <div class="mb-4">
            <label for="smtp_encryption" class="form-label">Encryption</label>
            <select class="form-select" id="smtp_encryption" name="smtp_encryption" style="max-width: 200px;">
                <option value="tls" <?php echo $smtpEncryption === 'tls' ? 'selected' : ''; ?>>TLS</option>
                <option value="ssl" <?php echo $smtpEncryption === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                <option value="none" <?php echo $smtpEncryption === 'none' ? 'selected' : ''; ?>>None</option>
            </select>
        </div>

        <div class="d-flex justify-content-end">
            <button type="submit" name="step4_continue" class="btn btn-signula">
                Continue <i class="bi bi-arrow-right"></i>
            </button>
        </div>
    </form>
    <?php
}

/**
 * ============================================================================
 * 👤 STEP 5: Create Admin Account
 * ============================================================================
 *
 * Creates the first super-admin user account with Argon2id password hashing.
 */
function renderStep5_AdminAccount(): void
{
    ?>
    <h4 class="mb-3"><i class="bi bi-person-badge"></i> Step 5: Create Admin Account</h4>
    <p class="text-muted mb-4">
        Create the initial super administrator account. This account will have full system access.
    </p>

    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['install_csrf_token']); ?>">

        <div class="row mb-3">
            <div class="col-md-6">
                <label for="admin_username" class="form-label">Username <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="admin_username" name="admin_username"
                       placeholder="admin" required minlength="3" maxlength="100"
                       pattern="[a-zA-Z0-9_\-\.]{3,100}"
                       title="3-100 characters: letters, numbers, underscores, hyphens, dots">
                <div class="form-text">3-100 characters. Letters, numbers, underscores, hyphens, and dots.</div>
            </div>
            <div class="col-md-6">
                <label for="admin_email" class="form-label">Email Address <span class="text-danger">*</span></label>
                <input type="email" class="form-control" id="admin_email" name="admin_email"
                       value="<?php echo htmlspecialchars($_SESSION['site_config']['admin_email'] ?? ''); ?>"
                       placeholder="admin@signula.id" required>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label for="admin_first_name" class="form-label">First Name</label>
                <input type="text" class="form-control" id="admin_first_name" name="admin_first_name"
                       placeholder="First name" maxlength="100">
            </div>
            <div class="col-md-6">
                <label for="admin_last_name" class="form-label">Last Name</label>
                <input type="text" class="form-control" id="admin_last_name" name="admin_last_name"
                       placeholder="Last name" maxlength="100">
            </div>
        </div>

        <div class="mb-3">
            <label for="admin_password" class="form-label">Password <span class="text-danger">*</span></label>
            <input type="password" class="form-control" id="admin_password" name="admin_password"
                   placeholder="Enter a strong password" required minlength="8"
                   oninput="checkPasswordStrength(this.value)">
            <!-- 🔑 Password strength meter -->
            <div class="strength-meter">
                <div id="strength-meter-fill" class="strength-meter-fill"></div>
            </div>
            <small id="strength-label" class="form-text"></small>
            <div class="form-text">Minimum 8 characters. Use a mix of uppercase, lowercase, numbers, and symbols.</div>
        </div>

        <div class="mb-4">
            <label for="admin_password_confirm" class="form-label">Confirm Password <span class="text-danger">*</span></label>
            <input type="password" class="form-control" id="admin_password_confirm" name="admin_password_confirm"
                   placeholder="Re-enter your password" required minlength="8">
        </div>

        <div class="d-flex justify-content-end">
            <button type="submit" name="step5_continue" class="btn btn-signula">
                Create Account & Complete <i class="bi bi-arrow-right"></i>
            </button>
        </div>
    </form>
    <?php
}

/**
 * ============================================================================
 * 🎉 STEP 6: Installation Complete
 * ============================================================================
 *
 * Displays success message with next steps and security reminders.
 */
function renderStep6_Complete(): void
{
    ?>
    <div class="text-center py-4">
        <div class="mb-4">
            <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
        </div>
        <h3 class="text-success mb-3">Installation Complete!</h3>
        <p class="text-muted mb-4">
            SIGNula has been successfully installed and configured.
        </p>
    </div>

    <!-- 📋 Summary -->
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-list-check"></i> Installation Summary</div>
        <div class="card-body">
            <table class="table table-sm table-borderless mb-0">
                <tr>
                    <td class="text-muted" style="width: 40%;">Site Name</td>
                    <td><strong><?php echo htmlspecialchars($_SESSION['site_config']['site_name'] ?? 'SIGNula'); ?></strong></td>
                </tr>
                <tr>
                    <td class="text-muted">Site URL</td>
                    <td><strong><?php echo htmlspecialchars($_SESSION['site_config']['site_url'] ?? ''); ?></strong></td>
                </tr>
                <tr>
                    <td class="text-muted">Admin Email</td>
                    <td><strong><?php echo htmlspecialchars($_SESSION['site_config']['admin_email'] ?? ''); ?></strong></td>
                </tr>
                <tr>
                    <td class="text-muted">Admin Username</td>
                    <td><strong><?php echo htmlspecialchars($_SESSION['admin_username'] ?? ''); ?></strong></td>
                </tr>
                <tr>
                    <td class="text-muted">Database</td>
                    <td><strong><?php echo htmlspecialchars($_SESSION['db_config']['database'] ?? ''); ?></strong></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- ⚠️ Security Reminders -->
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-exclamation"></i> Important Security Steps</h5>
        <ol class="mb-0">
            <li class="mb-2">
                <strong>Delete or restrict the <code>/install/</code> directory</strong> to prevent
                unauthorized re-installation. The <code>.installed</code> lock file provides basic protection,
                but removing the directory is recommended.
            </li>
            <li class="mb-2">
                <strong>Store your encryption key securely</strong> — it has been saved to the database
                and to <code>_private/auth.php</code>, but keep a backup in a secure location.
            </li>
            <li class="mb-2">
                <strong>Set proper file permissions</strong> on the <code>_private/</code> directory
                (recommended: <code>750</code> for directories, <code>640</code> for files).
            </li>
            <li>
                <strong>Configure HTTPS</strong> if not already done. SIGNula should only run over TLS/SSL.
            </li>
        </ol>
    </div>

    <!-- 🔗 Navigation links -->
    <div class="d-flex justify-content-center gap-3 mt-4">
        <a href="/login.php" class="btn btn-signula btn-lg">
            <i class="bi bi-box-arrow-in-right"></i> Go to Login
        </a>
        <a href="/admin/" class="btn btn-outline-primary btn-lg">
            <i class="bi bi-speedometer2"></i> Admin Dashboard
        </a>
    </div>
    <?php

    // 🧹 Clean up session data (keep only essential info)
    unset(
        $_SESSION['install_step'],
        $_SESSION['install_csrf_token'],
        $_SESSION['db_config'],
        $_SESSION['site_config'],
        $_SESSION['admin_username']
    );
}


// ============================================================================
// ============================================================================
//
// 🔧 STEP SUBMISSION HANDLERS
//
// These functions process form POST data for each installation step.
// Returns null on success, or an error message string on failure.
//
// ============================================================================
// ============================================================================

/**
 * 🔄 Route form submission to the appropriate step handler
 *
 * @param int $step The current step number
 * @return string|null Error message or null on success
 */
function handleStepSubmission(int $step): ?string
{
    switch ($step) {
        case 1:
            return handleStep1();
        case 2:
            return handleStep2();
        case 3:
            return handleStep3();
        case 4:
            return handleStep4();
        case 5:
            return handleStep5();
        default:
            return 'Invalid step.';
    }
}

/**
 * ✅ Handle Step 1: Verify requirements pass
 *
 * @return string|null Error message or null on success
 */
function handleStep1(): ?string
{
    // 🔍 Re-check all requirements
    $requirements = checkRequirements();
    foreach ($requirements as $req) {
        if ($req['required'] && !$req['passed']) {
            return 'Not all required system requirements are met. Please resolve them before continuing.';
        }
    }
    return null;
}

/**
 * 🗄️ Handle Step 2: Validate and store DB credentials
 *
 * @return string|null Error message or null on success
 */
function handleStep2(): ?string
{
    // 📝 Collect and sanitize input
    $host     = trim($_POST['db_host'] ?? '');
    $port     = (int)($_POST['db_port'] ?? 3306);
    $database = trim($_POST['db_name'] ?? '');
    $username = trim($_POST['db_user'] ?? '');
    $password = $_POST['db_pass'] ?? '';
    $createDb = isset($_POST['create_db']);

    // 🔍 Validate required fields
    if (empty($host) || empty($database) || empty($username)) {
        return 'Please fill in all required database fields.';
    }

    if ($port < 1 || $port > 65535) {
        return 'Database port must be between 1 and 65535.';
    }

    // 🔗 Test database connection
    // @see https://www.php.net/manual/en/function.mysqli-connect.php
    mysqli_report(MYSQLI_REPORT_OFF);

    if ($createDb) {
        // 🏗️ Connect without specifying database first (to create it if needed)
        $conn = @new mysqli($host, $username, $password, '', $port);
        if ($conn->connect_error) {
            return 'Database connection failed: ' . $conn->connect_error;
        }

        // 📦 Create database if it doesn't exist
        $dbNameEscaped = $conn->real_escape_string($database);
        $result = $conn->query("CREATE DATABASE IF NOT EXISTS `{$dbNameEscaped}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        if (!$result) {
            $err = $conn->error;
            $conn->close();
            return 'Failed to create database: ' . $err;
        }
        $conn->close();
    }

    // 🔗 Now connect to the specified database
    $conn = @new mysqli($host, $username, $password, $database, $port);
    if ($conn->connect_error) {
        return 'Database connection failed: ' . $conn->connect_error;
    }

    // ✅ Connection successful — store config in session
    $conn->close();

    $_SESSION['db_config'] = [
        'host'      => $host,
        'port'      => $port,
        'database'  => $database,
        'username'  => $username,
        'password'  => $password,
        'create_db' => $createDb,
    ];

    return null;
}

/**
 * 📦 Handle Step 3: Confirm migrations ran (submitted via continue button)
 *
 * @return string|null Error message or null on success
 */
function handleStep3(): ?string
{
    // ✅ Migrations are run via AJAX; the form submit just advances the step.
    // Verify we still have a valid DB connection in the session
    if (empty($_SESSION['db_config'])) {
        return 'Database configuration is missing. Please go back to Step 2.';
    }
    return null;
}

/**
 * ⚙️ Handle Step 4: Save configuration settings to database
 *
 * @return string|null Error message or null on success
 */
function handleStep4(): ?string
{
    // 📝 Collect form data
    $siteName       = trim($_POST['site_name'] ?? '');
    $siteUrl        = rtrim(trim($_POST['site_url'] ?? ''), '/');
    $adminEmail     = trim($_POST['admin_email'] ?? '');
    $encryptionKey  = trim($_POST['encryption_key'] ?? '');
    $currency       = trim($_POST['currency'] ?? 'GBP');
    $smtpHost       = trim($_POST['smtp_host'] ?? '');
    $smtpPort       = (int)($_POST['smtp_port'] ?? 587);
    $smtpUser       = trim($_POST['smtp_user'] ?? '');
    $smtpPass       = $_POST['smtp_pass'] ?? '';
    $smtpEncryption = $_POST['smtp_encryption'] ?? 'tls';

    // 🔍 Validate required fields
    if (empty($siteName)) {
        return 'Site name is required.';
    }
    if (empty($siteUrl) || !filter_var($siteUrl, FILTER_VALIDATE_URL)) {
        return 'A valid site URL is required (including https://).';
    }
    if (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        return 'A valid admin email address is required.';
    }
    if (empty($encryptionKey) || strlen($encryptionKey) < 32) {
        return 'Encryption key must be at least 32 characters. Use the Generate button.';
    }

    // 💾 Store in session for use in DB operations
    $_SESSION['site_config'] = [
        'site_name'       => $siteName,
        'site_url'        => $siteUrl,
        'admin_email'     => $adminEmail,
        'encryption_key'  => $encryptionKey,
        'currency'        => $currency,
        'smtp_host'       => $smtpHost,
        'smtp_port'       => $smtpPort,
        'smtp_user'       => $smtpUser,
        'smtp_pass'       => $smtpPass,
        'smtp_encryption' => $smtpEncryption,
    ];

    // 🗄️ Save settings to database
    $conn = getInstallerDbConnection();
    if ($conn === null) {
        return 'Cannot connect to database. Please go back to Step 2.';
    }

    // 📝 Settings to update/insert via prepared statements
    // @see https://www.php.net/manual/en/mysqli.prepare.php
    $settings = [
        ['app.name',                  $siteName,       'string',  'general',  false],
        ['app.url',                   $siteUrl,        'string',  'general',  false],
        ['app.admin_email',           $adminEmail,     'string',  'general',  false],
        ['app.currency',              $currency,       'string',  'general',  false],
        ['app.version',               '2.6.0-beta',   'string',  'general',  false],
        ['app.installed_at',          date('Y-m-d H:i:s'), 'string', 'general', false],
        ['security.encryption.key',   $encryptionKey,  'encrypted', 'security', true],
        ['security.encryption.salt',  bin2hex(random_bytes(16)), 'encrypted', 'security', true],
    ];

    // 📧 Add SMTP settings if provided
    if (!empty($smtpHost)) {
        $settings[] = ['email.smtp.host',       $smtpHost,       'string',  'email', false];
        $settings[] = ['email.smtp.port',       (string)$smtpPort, 'integer', 'email', false];
        $settings[] = ['email.smtp.username',   $smtpUser,       'string',  'email', true];
        $settings[] = ['email.smtp.password',   $smtpPass,       'encrypted', 'email', true];
        $settings[] = ['email.smtp.encryption', $smtpEncryption, 'string',  'email', false];
    }

    // 🔄 Insert or update each setting using prepared statements
    $stmt = $conn->prepare(
        "INSERT INTO tblSettings (settingKey, settingValue, settingType, settingCategory, isSensitive, description)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), settingType = VALUES(settingType), isSensitive = VALUES(isSensitive)"
    );

    if (!$stmt) {
        $err = $conn->error;
        $conn->close();
        return 'Failed to prepare settings statement: ' . $err;
    }

    foreach ($settings as $setting) {
        $key         = $setting[0];
        $value       = $setting[1];
        $type        = $setting[2];
        $category    = $setting[3];
        $isSensitive = $setting[4] ? 1 : 0;
        $desc        = 'Configured during installation';

        $stmt->bind_param('ssssis', $key, $value, $type, $category, $isSensitive, $desc);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            $conn->close();
            return 'Failed to save setting "' . $key . '": ' . $err;
        }
    }

    $stmt->close();

    // 📁 Also write auth.php with DB credentials and encryption key
    // This serves as the primary configuration file loaded by database.php
    $authContent = writeAuthFile($encryptionKey);
    if ($authContent !== null) {
        // ⚠️ Non-fatal: warn but continue (settings are in DB)
        // The auth.php write is for convenience/fallback
    }

    $conn->close();
    return null;
}

/**
 * 👤 Handle Step 5: Create super admin account
 *
 * @return string|null Error message or null on success
 */
function handleStep5(): ?string
{
    // 📝 Collect form data
    $username  = trim($_POST['admin_username'] ?? '');
    $email     = trim($_POST['admin_email'] ?? '');
    $firstName = trim($_POST['admin_first_name'] ?? '');
    $lastName  = trim($_POST['admin_last_name'] ?? '');
    $password  = $_POST['admin_password'] ?? '';
    $confirm   = $_POST['admin_password_confirm'] ?? '';

    // 🔍 Validate required fields
    if (empty($username)) {
        return 'Username is required.';
    }
    if (strlen($username) < 3 || strlen($username) > 100) {
        return 'Username must be between 3 and 100 characters.';
    }
    if (!preg_match('/^[a-zA-Z0-9_\-\.]{3,100}$/', $username)) {
        return 'Username can only contain letters, numbers, underscores, hyphens, and dots.';
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'A valid email address is required.';
    }
    if (empty($password)) {
        return 'Password is required.';
    }
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if ($password !== $confirm) {
        return 'Passwords do not match.';
    }

    // 🗄️ Connect to database
    $conn = getInstallerDbConnection();
    if ($conn === null) {
        return 'Cannot connect to database. Please go back to Step 2.';
    }

    // 🔐 Hash the password using Argon2id (industry-standard for password storage)
    // @see https://www.php.net/manual/en/function.password-hash.php
    // @see https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html
    $passwordHash = password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,   // 64 MB
        'time_cost'   => 4,       // 4 iterations
        'threads'     => 1,       // 1 parallel thread (safe for shared hosting)
    ]);

    // 🎲 Generate a unique UUID for the user (RFC 4122 v4)
    // @see https://www.php.net/manual/en/function.random-bytes.php
    $uuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );

    // 🔑 Generate a password salt (additional security layer)
    $passwordSalt = bin2hex(random_bytes(32));

    // 📝 Build the display name from first/last name or username
    $displayName = trim($firstName . ' ' . $lastName);
    if (empty($displayName)) {
        $displayName = $username;
    }

    // 🗄️ Insert admin user using prepared statement
    // Using isSuperAdmin=1 to grant full system access
    $stmt = $conn->prepare(
        "INSERT INTO tblUsers (
            userUUID, username, email, emailVerified, emailVerifiedAt,
            passwordHash, passwordSalt, firstName, lastName, displayName,
            accountStatus, accountTier, isSuperAdmin, createdAt
        ) VALUES (
            ?, ?, ?, 1, NOW(),
            ?, ?, ?, ?, ?,
            'active', 'admin', 1, NOW()
        )"
    );

    if (!$stmt) {
        // 🔄 If isSuperAdmin column doesn't exist (older schema), try without it
        $stmt = $conn->prepare(
            "INSERT INTO tblUsers (
                userUUID, username, email, emailVerified, emailVerifiedAt,
                passwordHash, passwordSalt, firstName, lastName, displayName,
                accountStatus, accountTier, createdAt
            ) VALUES (
                ?, ?, ?, 1, NOW(),
                ?, ?, ?, ?, ?,
                'active', 'admin', NOW()
            )"
        );

        if (!$stmt) {
            $err = $conn->error;
            $conn->close();
            return 'Failed to prepare admin account statement: ' . $err;
        }
    }

    $stmt->bind_param(
        'ssssssss',
        $uuid, $username, $email,
        $passwordHash, $passwordSalt, $firstName, $lastName, $displayName
    );

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        $conn->close();

        // 🔍 Check for duplicate entry errors
        if (str_contains($err, 'Duplicate entry')) {
            if (str_contains($err, 'username')) {
                return 'This username is already taken. Please choose another.';
            }
            if (str_contains($err, 'email')) {
                return 'This email address is already registered.';
            }
        }

        return 'Failed to create admin account: ' . $err;
    }

    $stmt->close();

    // 💾 Store admin username in session for the summary page
    $_SESSION['admin_username'] = $username;

    // 🔒 Create the .installed lock file to prevent re-running the installer
    $lockFile = __DIR__ . DIRECTORY_SEPARATOR . '.installed';
    $lockContent = json_encode([
        'installed_at' => date('Y-m-d H:i:s T'),
        'version'      => '2.6.0-beta',
        'admin_user'   => $username,
        'php_version'  => PHP_VERSION,
    ], JSON_PRETTY_PRINT);
    file_put_contents($lockFile, $lockContent);

    $conn->close();
    return null;
}


// ============================================================================
// ============================================================================
//
// 🔧 UTILITY FUNCTIONS
//
// ============================================================================
// ============================================================================

/**
 * 📋 Check all system requirements
 *
 * Returns an array of requirement checks with name, description,
 * required (bool), passed (bool), and optional current value.
 *
 * @return array List of requirement check results
 */
function checkRequirements(): array
{
    // 📁 Determine the _private directory path
    $projectRoot = dirname(dirname(dirname(__DIR__)));
    $privateDir = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '_private';

    $requirements = [];

    // 🐘 PHP Version Check (>= 8.3 required)
    // @see https://www.php.net/releases/
    $requirements[] = [
        'name'        => 'PHP Version',
        'description' => 'PHP 8.3 or higher is required (8.4+ recommended)',
        'required'    => true,
        'passed'      => version_compare(PHP_VERSION, '8.3.0', '>='),
        'current'     => PHP_VERSION,
    ];

    // 📦 Required PHP Extensions
    // @see https://www.php.net/manual/en/function.extension-loaded.php
    $requiredExtensions = [
        'mysqli'   => 'MySQLi database driver for MySQL/MariaDB connections',
        'openssl'  => 'Cryptographic operations (encryption, hashing, TLS)',
        'json'     => 'JSON encoding/decoding for API and data interchange',
        'mbstring' => 'Multi-byte string handling (UTF-8 support)',
        'zip'      => 'ZIP archive handling for exports/imports',
        'gd'       => 'Image processing for avatars and CAPTCHA',
    ];

    foreach ($requiredExtensions as $ext => $desc) {
        $requirements[] = [
            'name'        => "PHP Extension: {$ext}",
            'description' => $desc,
            'required'    => true,
            'passed'      => extension_loaded($ext),
            'current'     => extension_loaded($ext) ? 'Loaded' : 'Not loaded',
        ];
    }

    // 📦 Optional PHP Extensions (nice to have)
    $optionalExtensions = [
        'curl'    => 'HTTP client for OAuth, API calls, and external service integration',
        'intl'    => 'Internationalization for locale-aware formatting and validation',
        'sodium'  => 'Modern cryptographic library (libsodium) for enhanced encryption',
    ];

    foreach ($optionalExtensions as $ext => $desc) {
        $requirements[] = [
            'name'        => "PHP Extension: {$ext}",
            'description' => $desc . ' (optional but recommended)',
            'required'    => false,
            'passed'      => extension_loaded($ext),
            'current'     => extension_loaded($ext) ? 'Loaded' : 'Not loaded',
        ];
    }

    // 📁 Write Permissions Check
    $requirements[] = [
        'name'        => 'Write Permission: _private/',
        'description' => 'The _private directory must be writable for keys, uploads, and logs',
        'required'    => true,
        'passed'      => is_dir($privateDir) ? is_writable($privateDir) : is_writable(dirname($privateDir)),
        'current'     => is_dir($privateDir) ? (is_writable($privateDir) ? 'Writable' : 'Not writable') : 'Directory does not exist (will be created)',
    ];

    // 📁 Install directory write permission (for .installed lock file)
    $requirements[] = [
        'name'        => 'Write Permission: install/',
        'description' => 'The install directory must be writable to create the .installed lock file',
        'required'    => true,
        'passed'      => is_writable(__DIR__),
        'current'     => is_writable(__DIR__) ? 'Writable' : 'Not writable',
    ];

    // 🔐 Argon2id support check
    // @see https://www.php.net/manual/en/function.password-hash.php
    $requirements[] = [
        'name'        => 'Password Hashing: Argon2id',
        'description' => 'Argon2id algorithm for secure password hashing',
        'required'    => true,
        'passed'      => defined('PASSWORD_ARGON2ID'),
        'current'     => defined('PASSWORD_ARGON2ID') ? 'Supported' : 'Not supported',
    ];

    // 🎲 CSPRNG check
    // @see https://www.php.net/manual/en/function.random-bytes.php
    $requirements[] = [
        'name'        => 'Cryptographic Random',
        'description' => 'Cryptographically secure random number generation',
        'required'    => true,
        'passed'      => function_exists('random_bytes'),
        'current'     => function_exists('random_bytes') ? 'Available' : 'Not available',
    ];

    return $requirements;
}

/**
 * 🔗 Get a mysqli connection using installer session config
 *
 * Creates a new database connection using credentials stored in the session
 * during Step 2. This is used instead of the app's Database class since
 * the application hasn't been fully configured yet.
 *
 * @return mysqli|null Connection instance or null on failure
 * @see https://www.php.net/manual/en/class.mysqli.php
 */
function getInstallerDbConnection(): ?mysqli
{
    // 🔍 Ensure DB config exists in session
    if (empty($_SESSION['db_config'])) {
        return null;
    }

    $config = $_SESSION['db_config'];

    // 🔇 Suppress automatic error reporting to handle errors manually
    mysqli_report(MYSQLI_REPORT_OFF);

    // 🔗 Create connection using session-stored credentials
    $conn = @new mysqli(
        $config['host'],
        $config['username'],
        $config['password'],
        $config['database'],
        (int)$config['port']
    );

    // ❌ Check for connection errors
    if ($conn->connect_error) {
        return null;
    }

    // 🔤 Set character encoding to utf8mb4 for full Unicode support
    $conn->set_charset('utf8mb4');

    return $conn;
}

/**
 * 📁 Write the auth.php configuration file
 *
 * Creates the _private/auth.php file with database credentials and
 * encryption key. This file is loaded by database.php as a fallback
 * configuration source.
 *
 * @param string $encryptionKey The global encryption key
 * @return string|null Error message or null on success
 */
function writeAuthFile(string $encryptionKey): ?string
{
    if (empty($_SESSION['db_config'])) {
        return 'Database configuration not available.';
    }

    $config = $_SESSION['db_config'];

    // 📁 Determine _private directory path
    $privateDir = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '_private';

    // 🏗️ Create _private directory if it doesn't exist
    if (!is_dir($privateDir)) {
        if (!mkdir($privateDir, 0750, true)) {
            return 'Failed to create _private directory.';
        }
    }

    $authFile = $privateDir . DIRECTORY_SEPARATOR . 'auth.php';

    // 📝 Build auth.php content with proper escaping
    $content = '<?php' . PHP_EOL;
    $content .= '/**' . PHP_EOL;
    $content .= ' * ============================================================================' . PHP_EOL;
    $content .= ' * SIGNula Database & Security Configuration' . PHP_EOL;
    $content .= ' * ============================================================================' . PHP_EOL;
    $content .= ' * Auto-generated by the SIGNula installer on ' . date('Y-m-d H:i:s T') . PHP_EOL;
    $content .= ' *' . PHP_EOL;
    $content .= ' * WARNING: This file contains sensitive credentials. Keep it secure!' . PHP_EOL;
    $content .= ' * - Never commit this file to version control' . PHP_EOL;
    $content .= ' * - Set file permissions to 640 (owner read/write, group read)' . PHP_EOL;
    $content .= ' * ============================================================================' . PHP_EOL;
    $content .= ' */' . PHP_EOL . PHP_EOL;
    $content .= '// 🚫 Prevent direct access' . PHP_EOL;
    $content .= 'if (!defined(\'SIGNULA_INIT\') && php_sapi_name() !== \'cli\') {' . PHP_EOL;
    $content .= '    http_response_code(403);' . PHP_EOL;
    $content .= '    die(\'Direct access not permitted\');' . PHP_EOL;
    $content .= '}' . PHP_EOL . PHP_EOL;
    $content .= '// 🗄️ Database Connection Credentials' . PHP_EOL;
    $content .= 'define(\'DB_HOST\', ' . var_export($config['host'], true) . ');' . PHP_EOL;
    $content .= 'define(\'DB_PORT\', ' . var_export((int)$config['port'], true) . ');' . PHP_EOL;
    $content .= 'define(\'DB_NAME\', ' . var_export($config['database'], true) . ');' . PHP_EOL;
    $content .= 'define(\'DB_USER\', ' . var_export($config['username'], true) . ');' . PHP_EOL;
    $content .= 'define(\'DB_PASS\', ' . var_export($config['password'], true) . ');' . PHP_EOL;
    $content .= 'define(\'DB_CHARSET\', \'utf8mb4\');' . PHP_EOL . PHP_EOL;
    $content .= '// 🔐 Encryption Key (used for encrypting sensitive settings)' . PHP_EOL;
    $content .= 'define(\'ENCRYPTION_KEY\', ' . var_export($encryptionKey, true) . ');' . PHP_EOL;

    // 💾 Write the file
    if (file_put_contents($authFile, $content) === false) {
        return 'Failed to write auth.php file.';
    }

    // 🔒 Set secure file permissions (owner read/write, group read)
    @chmod($authFile, 0640);

    return null;
}


// ============================================================================
// ============================================================================
//
// 🌐 AJAX REQUEST HANDLERS
//
// These functions handle asynchronous requests for database testing
// and migration execution, returning JSON responses.
//
// ============================================================================
// ============================================================================

/**
 * 🌐 Route AJAX requests to the appropriate handler
 *
 * Reads the 'ajax' query parameter and dispatches to the correct function.
 * All responses are JSON formatted.
 */
function handleAjaxRequest(): void
{
    $action = $_GET['ajax'] ?? '';

    switch ($action) {
        case 'test_db':
            handleAjaxTestDb();
            break;
        case 'run_migration':
            handleAjaxRunMigration();
            break;
        case 'run_base_install':
            handleAjaxRunBaseInstall();
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown AJAX action.']);
    }
}

/**
 * 🔗 AJAX: Test database connection
 *
 * Accepts JSON POST body with DB credentials and attempts a connection.
 * Returns JSON success/failure with a message.
 */
function handleAjaxTestDb(): void
{
    // 📝 Parse JSON request body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid request data.']);
        return;
    }

    // 🛡️ Validate CSRF token
    if (!isset($input['csrf_token']) || !validateCsrfToken($input['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        return;
    }

    $host     = $input['host'] ?? 'localhost';
    $port     = (int)($input['port'] ?? 3306);
    $database = $input['database'] ?? '';
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';

    // 🔇 Suppress automatic error reporting
    mysqli_report(MYSQLI_REPORT_OFF);

    // 🔗 Attempt connection
    // @see https://www.php.net/manual/en/mysqli.construct.php
    $conn = @new mysqli($host, $username, $password, '', $port);

    if ($conn->connect_error) {
        echo json_encode([
            'success' => false,
            'message' => 'Connection failed: ' . $conn->connect_error,
        ]);
        return;
    }

    // 🔍 Check if specified database exists
    $dbExists = false;
    $result = $conn->query("SHOW DATABASES LIKE '" . $conn->real_escape_string($database) . "'");
    if ($result && $result->num_rows > 0) {
        $dbExists = true;
    }

    // 📊 Get MySQL/MariaDB server version
    $serverVersion = $conn->server_info;

    $conn->close();

    $message = "Connection successful! Server: MySQL/MariaDB {$serverVersion}";
    if ($dbExists) {
        $message .= " | Database '{$database}' exists.";
    } else {
        $message .= " | Database '{$database}' does not exist (will be created if option is checked).";
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
    ]);
}

/**
 * 🏗️ AJAX: Run base installation SQL
 *
 * Executes the consolidated installation SQL file that creates all core
 * tables, views, and default settings.
 */
function handleAjaxRunBaseInstall(): void
{
    // 📝 Parse JSON request body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid request data.']);
        return;
    }

    // 🛡️ Validate CSRF token
    if (!isset($input['csrf_token']) || !validateCsrfToken($input['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        return;
    }

    // 📁 Locate the base installation SQL file
    $projectRoot = dirname(dirname(dirname(__DIR__)));
    $sqlFile = $projectRoot . DIRECTORY_SEPARATOR . '_database' . DIRECTORY_SEPARATOR . 'signula_complete_install_v2.5.0.sql';

    if (!file_exists($sqlFile)) {
        echo json_encode(['success' => false, 'message' => 'Base installation SQL file not found at: ' . $sqlFile]);
        return;
    }

    // 📖 Read the SQL file content
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to read base installation SQL file.']);
        return;
    }

    // 🗄️ Get database connection
    $conn = getInstallerDbConnection();
    if ($conn === null) {
        echo json_encode(['success' => false, 'message' => 'Cannot connect to database.']);
        return;
    }

    // 🔄 Execute the SQL using multi_query for multi-statement scripts
    // @see https://www.php.net/manual/en/mysqli.multi-query.php
    $conn->begin_transaction();

    try {
        if ($conn->multi_query($sql)) {
            // 📊 Process all result sets (required by multi_query)
            do {
                if ($result = $conn->store_result()) {
                    $result->free();
                }
            } while ($conn->more_results() && $conn->next_result());

            // ❌ Check for errors in the last query
            if ($conn->errno) {
                throw new RuntimeException('SQL Error: ' . $conn->error);
            }

            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Base schema installed successfully. All core tables and default settings have been created.',
            ]);
        } else {
            throw new RuntimeException('Failed to execute SQL: ' . $conn->error);
        }
    } catch (RuntimeException $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

    $conn->close();
}

/**
 * 📦 AJAX: Run a single migration file
 *
 * Executes the specified migration SQL file and records it in the
 * tblMigrations tracking table.
 *
 * @see https://www.php.net/manual/en/mysqli.multi-query.php
 */
function handleAjaxRunMigration(): void
{
    // 📝 Parse JSON request body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid request data.']);
        return;
    }

    // 🛡️ Validate CSRF token
    if (!isset($input['csrf_token']) || !validateCsrfToken($input['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        return;
    }

    $migrationName = $input['migration'] ?? '';
    if (empty($migrationName)) {
        echo json_encode(['success' => false, 'message' => 'No migration file specified.']);
        return;
    }

    // 🔒 Security: prevent directory traversal attacks
    // @see https://owasp.org/www-community/attacks/Path_Traversal
    $migrationName = basename($migrationName);

    // 📁 Locate the migration file
    $projectRoot = dirname(dirname(dirname(__DIR__)));
    $migrationFile = $projectRoot . DIRECTORY_SEPARATOR . '_database'
        . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $migrationName;

    if (!file_exists($migrationFile)) {
        echo json_encode(['success' => false, 'message' => 'Migration file not found: ' . $migrationName]);
        return;
    }

    // 📖 Read the migration SQL content
    $sql = file_get_contents($migrationFile);
    if ($sql === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to read migration file: ' . $migrationName]);
        return;
    }

    // 🗄️ Get database connection
    $conn = getInstallerDbConnection();
    if ($conn === null) {
        echo json_encode(['success' => false, 'message' => 'Cannot connect to database.']);
        return;
    }

    // 🔍 Check if this migration has already been applied
    $checkStmt = $conn->prepare("SELECT migrationID FROM tblMigrations WHERE migrationName = ? AND status = 'completed'");
    if ($checkStmt) {
        $checkStmt->bind_param('s', $migrationName);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows > 0) {
            $checkStmt->close();
            $conn->close();
            echo json_encode([
                'success' => true,
                'message' => 'Migration already applied: ' . $migrationName,
            ]);
            return;
        }
        $checkStmt->close();
    }

    // 🔄 Execute the migration SQL
    try {
        if ($conn->multi_query($sql)) {
            // 📊 Process all result sets
            do {
                if ($result = $conn->store_result()) {
                    $result->free();
                }
            } while ($conn->more_results() && $conn->next_result());

            // ❌ Check for errors
            if ($conn->errno) {
                throw new RuntimeException('SQL Error: ' . $conn->error);
            }

            // ✅ Record successful migration in tblMigrations
            $recordStmt = $conn->prepare(
                "INSERT INTO tblMigrations (migrationName, migrationDescription, appliedBy, status)
                 VALUES (?, ?, 'installer', 'completed')
                 ON DUPLICATE KEY UPDATE status = 'completed', appliedAt = CURRENT_TIMESTAMP"
            );

            if ($recordStmt) {
                $description = 'Applied during installation';
                $recordStmt->bind_param('ss', $migrationName, $description);
                $recordStmt->execute();
                $recordStmt->close();
            }

            echo json_encode([
                'success' => true,
                'message' => 'Migration applied successfully: ' . $migrationName,
            ]);
        } else {
            throw new RuntimeException('Failed to execute migration: ' . $conn->error);
        }
    } catch (RuntimeException $e) {
        // ❌ Record failed migration
        $recordStmt = $conn->prepare(
            "INSERT INTO tblMigrations (migrationName, migrationDescription, appliedBy, status)
             VALUES (?, ?, 'installer', 'failed')
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

    $conn->close();
}
