<?php
/**
 * Database Migration Manager
 *
 * Purpose: Admin interface for deploying database migrations
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This file provides a user-friendly interface for managing database migrations.
 *
 * @package    SIGNula
 * @subpackage Admin
 * @version    1.0.0
 * @since      2.2.0-beta
 */

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

// Initialize
$db = Database::getInstance();
$sessionManager = new SessionManager($db);

// Check if user is logged in and is admin
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$userInfo = $sessionManager->getUserInfo();
if (!isset($userInfo['isAdmin']) || $userInfo['isAdmin'] != 1) {
    http_response_code(403);
    die('Access denied. Admin privileges required.');
}

$pageTitle = 'Database Migrations';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - SIGNula Admin</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">

    <style>
        :root {
            --primary-color: #0066cc;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
        }

        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        .migration-card {
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }

        .migration-card.pending {
            border-left-color: var(--warning-color);
        }

        .migration-card.applied {
            border-left-color: var(--success-color);
            background-color: #f0f9f4;
        }

        .migration-card.deploying {
            border-left-color: var(--primary-color);
            background-color: #e7f3ff;
        }

        .migration-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .status-badge {
            font-size: 0.85rem;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
        }

        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
            border-width: 0.15em;
        }

        .alert-custom {
            border-radius: 10px;
            border: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .btn-deploy {
            transition: all 0.3s ease;
        }

        .btn-deploy:hover:not(:disabled) {
            transform: scale(1.05);
        }

        .migration-info {
            font-size: 0.9rem;
            color: #6c757d;
        }

        .page-header {
            background: linear-gradient(135deg, var(--primary-color), #004494);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .back-link {
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            transition: all 0.2s;
        }

        .back-link:hover {
            color: white;
            transform: translateX(-5px);
        }

        .progress-custom {
            height: 25px;
            border-radius: 10px;
            background-color: #e9ecef;
        }

        .progress-bar-custom {
            background: linear-gradient(90deg, var(--success-color), #20c997);
            transition: width 0.6s ease;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <!-- Page Header -->
        <div class="page-header">
            <a href="../index.php" class="back-link d-inline-flex align-items-center mb-3">
                <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
            </a>
            <h1 class="mb-2"><i class="fas fa-database me-2"></i><?php echo htmlspecialchars($pageTitle); ?></h1>
            <p class="mb-0 opacity-75">Deploy and manage database migrations securely from the admin interface</p>
        </div>

        <!-- Instructions -->
        <div class="alert alert-info alert-custom mb-4">
            <h5 class="alert-heading"><i class="fas fa-info-circle me-2"></i>Migration Manager</h5>
            <p class="mb-2">Deploy database migrations with one click. Migrations are executed in order and tracked automatically.</p>
            <ul class="mb-0">
                <li><strong>Pending:</strong> Migrations that haven't been applied yet</li>
                <li><strong>Applied:</strong> Migrations that have been successfully deployed</li>
                <li><strong>One-Click Deploy:</strong> Click the deploy button to apply a migration</li>
            </ul>
        </div>

        <!-- Progress Bar (hidden by default) -->
        <div id="progressContainer" class="mb-4" style="display: none;">
            <div class="progress progress-custom">
                <div id="progressBar" class="progress-bar progress-bar-custom progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">
                    <span id="progressText">0%</span>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <div id="alertContainer"></div>

        <!-- Loading State -->
        <div id="loadingState" class="text-center py-5">
            <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Loading migrations...</p>
        </div>

        <!-- Migrations List -->
        <div id="migrationsContainer" style="display: none;">
            <!-- Will be populated by JavaScript -->
        </div>

        <!-- No Migrations -->
        <div id="noMigrations" class="text-center py-5" style="display: none;">
            <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
            <h3 class="mt-3">All Migrations Applied!</h3>
            <p class="text-muted">Your database is up to date. No pending migrations.</p>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <!-- 🔐 CSRF Token for secure AJAX requests -->
    <script>const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';</script>

    <script>
        // Configuration
        const API_URL = '../api/deploy-migration.php';

        // State
        let migrations = [];

        /**
         * Show alert message
         */
        function showAlert(message, type = 'info') {
            const alertHTML = `
                <div class="alert alert-${type} alert-custom alert-dismissible fade show" role="alert">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-circle' : 'info-circle'} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            document.getElementById('alertContainer').insertAdjacentHTML('beforeend', alertHTML);

            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                const alert = document.querySelector('#alertContainer .alert:first-child');
                if (alert) {
                    const bsAlert = bootstrap.Alert.getInstance(alert) || new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            }, 5000);
        }

        /**
         * Update progress bar
         */
        function updateProgress(percent, text) {
            const container = document.getElementById('progressContainer');
            const bar = document.getElementById('progressBar');
            const textElem = document.getElementById('progressText');

            container.style.display = percent > 0 ? 'block' : 'none';
            bar.style.width = percent + '%';
            textElem.textContent = text || percent + '%';
        }

        /**
         * Load migrations list
         */
        async function loadMigrations() {
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ action: 'list', csrf_token: csrfToken })
                });

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'Failed to load migrations');
                }

                migrations = data.migrations;
                renderMigrations();

            } catch (error) {
                console.error('Error loading migrations:', error);
                showAlert('Failed to load migrations: ' + error.message, 'danger');
                document.getElementById('loadingState').style.display = 'none';
            }
        }

        /**
         * Render migrations list
         */
        function renderMigrations() {
            document.getElementById('loadingState').style.display = 'none';

            if (migrations.length === 0) {
                document.getElementById('noMigrations').style.display = 'block';
                return;
            }

            const container = document.getElementById('migrationsContainer');
            container.style.display = 'block';

            const pendingCount = migrations.filter(m => !m.applied).length;
            const appliedCount = migrations.filter(m => m.applied).length;

            let html = `
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <h2 class="display-4 text-warning mb-2">${pendingCount}</h2>
                                <p class="text-muted mb-0">Pending Migrations</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <h2 class="display-4 text-success mb-2">${appliedCount}</h2>
                                <p class="text-muted mb-0">Applied Migrations</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            migrations.forEach(migration => {
                const statusClass = migration.applied ? 'applied' : 'pending';
                const statusBadge = migration.applied
                    ? '<span class="status-badge bg-success text-white"><i class="fas fa-check me-1"></i>Applied</span>'
                    : '<span class="status-badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending</span>';

                const deployButton = !migration.applied
                    ? `<button class="btn btn-primary btn-deploy" onclick="deployMigration('${migration.name}')">
                        <i class="fas fa-rocket me-2"></i>Deploy Migration
                       </button>`
                    : '<button class="btn btn-success" disabled><i class="fas fa-check me-2"></i>Already Applied</button>';

                const fileSize = (migration.size / 1024).toFixed(2);
                const modifiedDate = new Date(migration.modified * 1000).toLocaleString();

                html += `
                    <div class="card migration-card ${statusClass} mb-3" id="migration-${migration.name}">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="card-title mb-1">
                                        <i class="fas fa-file-code me-2 text-primary"></i>
                                        ${migration.name}
                                    </h5>
                                    <div class="migration-info">
                                        <i class="fas fa-hdd me-1"></i>${fileSize} KB
                                        <span class="mx-2">•</span>
                                        <i class="fas fa-calendar me-1"></i>${modifiedDate}
                                    </div>
                                </div>
                                ${statusBadge}
                            </div>
                            <div class="d-flex justify-content-end">
                                ${deployButton}
                            </div>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        /**
         * Deploy migration
         */
        async function deployMigration(migrationName) {
            if (!confirm(`Deploy migration: ${migrationName}?\n\nThis will execute SQL statements on your database. Make sure you have a backup.`)) {
                return;
            }

            const card = document.getElementById(`migration-${migrationName}`);
            const button = card.querySelector('.btn-deploy');

            // Update UI
            card.classList.add('deploying');
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Deploying...';

            updateProgress(25, 'Preparing migration...');

            try {
                updateProgress(50, 'Executing SQL statements...');

                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'deploy',
                        migration: migrationName,
                        csrf_token: csrfToken
                    })
                });

                const data = await response.json();

                updateProgress(75, 'Verifying deployment...');

                if (data.success) {
                    updateProgress(100, 'Migration deployed successfully!');

                    // Update UI
                    setTimeout(() => {
                        card.classList.remove('deploying');
                        card.classList.add('applied');
                        button.className = 'btn btn-success';
                        button.disabled = true;
                        button.innerHTML = '<i class="fas fa-check me-2"></i>Successfully Deployed';

                        showAlert(`Migration ${migrationName} deployed successfully! Executed ${data.executed} statements.`, 'success');

                        // Reload migrations list
                        setTimeout(() => {
                            updateProgress(0);
                            loadMigrations();
                        }, 2000);
                    }, 1000);
                } else {
                    throw new Error(data.error || 'Deployment failed');
                }

            } catch (error) {
                console.error('Deployment error:', error);
                updateProgress(0);

                // Reset UI
                card.classList.remove('deploying');
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-rocket me-2"></i>Deploy Migration';

                showAlert('Deployment failed: ' + error.message, 'danger');
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            // Define constant for API
            window.INCLUDED_FROM_ADMIN = true;
            loadMigrations();
        });
    </script>
</body>
</html>
