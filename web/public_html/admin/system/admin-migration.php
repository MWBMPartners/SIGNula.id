<?php
/**
 * Admin Migration Tool
 *
 * Purpose: Migrate existing isAdmin users to new multi-tier system
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 */

require_once dirname(__DIR__, 3) . '/_config/config.php';
require_once dirname(__DIR__, 3) . '/_backend/Database.php';
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';

$db = Database::getInstance();
$sessionManager = new SessionManager($db);

// Check if logged in and is admin
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

$userInfo = $sessionManager->getUserInfo();
$hasOldAdmin = isset($userInfo['isAdmin']) && $userInfo['isAdmin'] == 1;
$hasSuperAdmin = isset($userInfo['isSuperAdmin']) && $userInfo['isSuperAdmin'] == 1;

// Only allow access if user has old admin but not super admin (migration needed)
// OR if user is already super admin (to help others migrate)
if (!$hasOldAdmin && !$hasSuperAdmin) {
    http_response_code(403);
    die('Access denied.');
}

$message = '';
$success = false;

// Handle migration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migrate'])) {
    $selectedUsers = $_POST['users'] ?? [];

    if (empty($selectedUsers)) {
        $message = 'Please select at least one user to migrate';
    } else {
        $migratedCount = 0;

        foreach ($selectedUsers as $userID) {
            $stmt = $db->prepare("UPDATE tblUsers SET isSuperAdmin = 1 WHERE userID = ? AND isAdmin = 1");
            $stmt->bind_param('i', $userID);
            if ($stmt->execute()) {
                $migratedCount++;
            }
        }

        if ($migratedCount > 0) {
            $success = true;
            $message = "Successfully migrated $migratedCount user(s) to Super Admin";

            // Reload if current user was migrated
            if (in_array($userInfo['userID'], $selectedUsers)) {
                header("Refresh: 3; url=/admin/");
            }
        }
    }
}

// Get all admins that haven't been migrated
$adminsToMigrate = $db->query("
    SELECT userID, username, email, createdAt
    FROM tblUsers
    WHERE isAdmin = 1 AND (isSuperAdmin IS NULL OR isSuperAdmin = 0)
    ORDER BY username
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Migration - SIGNula</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea, #764ba2); min-height: 100vh; }
        .migration-card { max-width: 800px; margin: 50px auto; background: white; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); }
    </style>
</head>
<body>
    <div class="container">
        <div class="card migration-card">
            <div class="card-body p-5">
                <h2 class="mb-4"><i class="fas fa-user-shield me-2 text-primary"></i>Admin Migration Tool</h2>

                <div class="alert alert-info">
                    <h5 class="alert-heading"><i class="fas fa-info-circle me-2"></i>Multi-Tier Admin System</h5>
                    <p class="mb-0">SIGNula now uses a multi-tier admin system:</p>
                    <ul class="mb-0 mt-2">
                        <li><strong>Super Admin:</strong> Full system control (SIGNula owners)</li>
                        <li><strong>Partner Admin:</strong> Organization-level control</li>
                        <li><strong>Team Members:</strong> Limited permissions</li>
                    </ul>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-<?php echo $success ? 'success' : 'warning'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <?php endif; ?>

                <?php if ($adminsToMigrate->num_rows > 0): ?>
                <form method="POST">
                    <h5 class="mb-3">Select users to convert to Super Admin:</h5>
                    <p class="text-muted">These users currently have <code>isAdmin = 1</code> but haven't been migrated to the new system.</p>

                    <div class="list-group mb-4">
                        <?php while ($user = $adminsToMigrate->fetch_assoc()): ?>
                        <label class="list-group-item">
                            <input type="checkbox" name="users[]" value="<?php echo $user['userID']; ?>" class="form-check-input me-3">
                            <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                            <br>
                            <small class="text-muted">
                                <?php echo htmlspecialchars($user['email']); ?> •
                                Created: <?php echo date('M j, Y', strtotime($user['createdAt'])); ?>
                            </small>
                        </label>
                        <?php endwhile; ?>
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="../index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                        <button type="submit" name="migrate" class="btn btn-primary btn-lg">
                            <i class="fas fa-check me-2"></i>Migrate Selected Users
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>All Done!</strong> All admin users have been migrated to the new system.
                </div>
                <a href="../index.php" class="btn btn-primary">
                    <i class="fas fa-home me-2"></i>Go to Dashboard
                </a>
                <?php endif; ?>

                <hr class="my-4">
                <h5>Migration Notes:</h5>
                <ul class="text-muted">
                    <li>Super Admins have full system control</li>
                    <li>Original <code>isAdmin</code> flag is preserved</li>
                    <li>Partner Admins are managed separately via team memberships</li>
                    <li>This migration can be run multiple times safely</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
