<?php
/**
 * Migration Deployment API
 *
 * Purpose: Deploy database migrations via admin UI
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This file provides secure migration deployment functionality for admin users.
 *
 * @package    SIGNula
 * @subpackage Admin_API
 * @version    1.0.0
 * @since      2.2.0-beta
 */

// Prevent direct access
if (!defined('INCLUDED_FROM_ADMIN')) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Direct access forbidden']));
}

require_once dirname(__DIR__, 3) . '/_config/config.php';
require_once dirname(__DIR__, 3) . '/_backend/Database.php';
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';
require_once dirname(__DIR__, 3) . '/_backend/ActivityLogger.php';

// Initialize components
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$activityLogger = new ActivityLogger($db);

// CORS headers for AJAX
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN'] ?? '*');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

/**
 * Check if user has admin privileges
 */
function isAdmin() {
    global $sessionManager;

    if (!$sessionManager->isLoggedIn()) {
        return false;
    }

    $user = $sessionManager->getUserInfo();
    return isset($user['isAdmin']) && $user['isAdmin'] == 1;
}

/**
 * Check if migration has been applied
 */
function isMigrationApplied($migrationName) {
    global $db;

    // Check if migration tracking table exists
    $result = $db->query("SHOW TABLES LIKE 'tblMigrations'");
    if ($result->num_rows === 0) {
        return false;
    }

    // Check if this migration is recorded
    $stmt = $db->prepare("SELECT migrationID FROM tblMigrations WHERE migrationName = ? AND status = 'completed'");
    $stmt->bind_param('s', $migrationName);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->num_rows > 0;
}

/**
 * Record migration in tracking table
 */
function recordMigration($migrationName, $status = 'completed') {
    global $db;

    // Create tracking table if it doesn't exist
    $db->query("
        CREATE TABLE IF NOT EXISTS `tblMigrations` (
            `migrationID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `migrationName` VARCHAR(255) NOT NULL UNIQUE,
            `status` ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
            `appliedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `appliedBy` BIGINT UNSIGNED NULL,
            `notes` TEXT NULL,
            INDEX idx_status (status),
            INDEX idx_applied (appliedAt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Record migration
    $stmt = $db->prepare("
        INSERT INTO tblMigrations (migrationName, status, appliedBy, notes)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            appliedAt = CURRENT_TIMESTAMP
    ");

    global $sessionManager;
    $userID = $sessionManager->isLoggedIn() ? $sessionManager->getUserID() : null;
    $notes = "Deployed via admin UI";

    $stmt->bind_param('ssis', $migrationName, $status, $userID, $notes);
    return $stmt->execute();
}

/**
 * Execute migration file
 */
function executeMigration($migrationFile) {
    global $db;

    // Read migration file
    $migrationPath = dirname(__DIR__, 4) . '/_database/migrations/' . $migrationFile;

    if (!file_exists($migrationPath)) {
        return ['success' => false, 'error' => 'Migration file not found'];
    }

    $sql = file_get_contents($migrationPath);

    if ($sql === false) {
        return ['success' => false, 'error' => 'Failed to read migration file'];
    }

    // Split into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) &&
                   !preg_match('/^--/', $stmt) &&
                   !preg_match('/^\/\*/', $stmt);
        }
    );

    $executedCount = 0;
    $errors = [];

    // Execute each statement
    foreach ($statements as $statement) {
        if (empty($statement)) continue;

        try {
            if (!$db->query($statement)) {
                $errors[] = 'Error: ' . $db->error . ' in statement: ' . substr($statement, 0, 100);
            } else {
                $executedCount++;
            }
        } catch (Exception $e) {
            $errors[] = 'Exception: ' . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        return [
            'success' => false,
            'error' => 'Migration partially completed with errors',
            'executed' => $executedCount,
            'errors' => $errors
        ];
    }

    return [
        'success' => true,
        'executed' => $executedCount,
        'message' => "Successfully executed $executedCount statements"
    ];
}

// ============================================================================
// MAIN EXECUTION
// ============================================================================

try {
    // Check admin privileges
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin privileges required']);
        exit;
    }

    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $migration = $input['migration'] ?? '';

    // Validate migration name
    if (!preg_match('/^\d{3}_[a-z_]+\.sql$/', $migration)) {
        throw new Exception('Invalid migration name format');
    }

    switch ($action) {
        case 'check':
            // Check if migration has been applied
            $applied = isMigrationApplied($migration);

            echo json_encode([
                'success' => true,
                'applied' => $applied,
                'migration' => $migration
            ]);
            break;

        case 'deploy':
            // Check if already applied
            if (isMigrationApplied($migration)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Migration already applied',
                    'migration' => $migration
                ]);
                exit;
            }

            // Record migration as running
            recordMigration($migration, 'running');

            // Execute migration
            $result = executeMigration($migration);

            if ($result['success']) {
                // Record as completed
                recordMigration($migration, 'completed');

                // Log activity
                $activityLogger->logActivity(
                    $sessionManager->getUserID(),
                    'migration_deployed',
                    'Migration deployed: ' . $migration,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                );

                echo json_encode([
                    'success' => true,
                    'message' => 'Migration deployed successfully',
                    'migration' => $migration,
                    'executed' => $result['executed']
                ]);
            } else {
                // Record as failed
                recordMigration($migration, 'failed');

                echo json_encode([
                    'success' => false,
                    'error' => $result['error'],
                    'details' => $result['errors'] ?? [],
                    'migration' => $migration
                ]);
            }
            break;

        case 'list':
            // Get list of available migrations
            $migrationDir = dirname(__DIR__, 4) . '/_database/migrations/';
            $migrations = [];

            if (is_dir($migrationDir)) {
                $files = scandir($migrationDir);
                foreach ($files as $file) {
                    if (preg_match('/^\d{3}_[a-z_]+\.sql$/', $file)) {
                        $applied = isMigrationApplied($file);
                        $migrations[] = [
                            'name' => $file,
                            'applied' => $applied,
                            'size' => filesize($migrationDir . $file),
                            'modified' => filemtime($migrationDir . $file)
                        ];
                    }
                }
            }

            // Sort by name
            usort($migrations, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });

            echo json_encode([
                'success' => true,
                'migrations' => $migrations
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
