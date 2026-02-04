<?php
/**
 * ============================================================================
 * 🏠 SIGNula - User Dashboard
 * ============================================================================
 *
 * Purpose: Main user dashboard
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔐 Require authentication
Auth::requireAuth();

// 👤 Get current user data
$user = Auth::getCurrentUser();

// 🏢 Check for organization membership
$organization = null;
$organizationRole = null;
if ($user['organizationID']) {
    try {
        $organization = Database::fetchOne(
            "SELECT * FROM tblOrganizations WHERE organizationID = ?",
            [$user['organizationID']],
            'i'
        );

        $membership = Database::fetchOne(
            "SELECT role FROM tblOrganizationMembers WHERE organizationID = ? AND userID = ?",
            [$user['organizationID'], $user['userID']],
            'ii'
        );

        $organizationRole = $membership['role'] ?? 'member';
    } catch (Exception $e) {
        ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
    }
}

// 📊 Get user statistics
$stats = [
    'activeSessions' => 0,
    'linkedAccounts' => 0,
    'lastLogin' => $user['lastLoginAt'] ?? null,
    'accountAge' => null
];

try {
    // Get active sessions count
    $sessionResult = Database::fetchOne(
        "SELECT COUNT(*) as count FROM tblUserSessions WHERE userID = ? AND isActive = TRUE AND expiresAt > NOW()",
        [$user['userID']],
        'i'
    );
    $stats['activeSessions'] = $sessionResult['count'] ?? 0;

    // Get linked accounts count
    $linkedResult = Database::fetchOne(
        "SELECT COUNT(*) as count FROM tblUserLinkedAccounts WHERE userID = ? AND isActive = TRUE",
        [$user['userID']],
        'i'
    );
    $stats['linkedAccounts'] = $linkedResult['count'] ?? 0;

    // Calculate account age
    $accountCreated = Database::fetchOne(
        "SELECT createdAt FROM tblUsers WHERE userID = ?",
        [$user['userID']],
        'i'
    );
    if ($accountCreated) {
        $created = new DateTime($accountCreated['createdAt']);
        $now = new DateTime();
        $diff = $created->diff($now);
        $stats['accountAge'] = $diff->days . ' days';
    }
} catch (Exception $e) {
    ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
}

// 📄 Page metadata
$pageTitle = 'Dashboard - SIGNula';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <!-- 🎨 Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">

    <!-- 🎨 Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <!-- 🎨 Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" />

    <!-- 🎨 Custom CSS -->
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body style="background: #f9fafb;">

<!-- 🧭 Top Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <div class="container-fluid">
        <a class="navbar-brand" href="/dashboard">
            <i class="fas fa-shield-alt"></i> <strong>SIGNula</strong>
        </a>
        <button class="navbar-toggler" type="button" id="mobile-menu-toggle">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; margin-right: 8px;">
                            <i class="fas fa-user"></i>
                        </div>
                        <span><?php echo htmlspecialchars($user['displayName'] ?? $user['username']); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="/account"><i class="fas fa-cog"></i> Account Settings</a></li>
                        <li><a class="dropdown-item" href="/security"><i class="fas fa-lock"></i> Security</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- 📱 Dashboard Layout -->
<div class="container-fluid" style="padding: 2rem;">
    <div class="row">
        <!-- 🏠 Main Content -->
        <div class="col-lg-9">
            <!-- 👋 Welcome Card -->
            <div class="card mb-4">
                <div class="card-body" style="padding: 2rem;">
                    <h2 style="margin: 0 0 0.5rem;">
                        Welcome back, <?php echo htmlspecialchars($user['firstName'] ?? $user['displayName'] ?? $user['username']); ?>! 👋
                    </h2>
                    <p style="color: var(--text-secondary); margin: 0;">
                        Manage your universal login account and connected services
                    </p>
                </div>
            </div>

            <!-- 📊 Statistics Cards -->
            <div class="row mb-4">
                <!-- 🎫 Active Sessions -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <div style="font-size: 2.5rem; color: var(--primary-color); margin-bottom: 0.5rem;">
                                <i class="fas fa-desktop"></i>
                            </div>
                            <h3 style="margin: 0; font-size: 2rem; font-weight: 700;">
                                <?php echo $stats['activeSessions']; ?>
                            </h3>
                            <p style="color: var(--text-secondary); margin: 0.5rem 0 0;">Active Sessions</p>
                            <a href="/security" class="btn btn-sm btn-secondary mt-2">Manage</a>
                        </div>
                    </div>
                </div>

                <!-- 🔗 Linked Accounts -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <div style="font-size: 2.5rem; color: var(--info-color); margin-bottom: 0.5rem;">
                                <i class="fas fa-link"></i>
                            </div>
                            <h3 style="margin: 0; font-size: 2rem; font-weight: 700;">
                                <?php echo $stats['linkedAccounts']; ?>
                            </h3>
                            <p style="color: var(--text-secondary); margin: 0.5rem 0 0;">Linked Accounts</p>
                            <a href="/account" class="btn btn-sm btn-secondary mt-2">Link More</a>
                        </div>
                    </div>
                </div>

                <!-- 🎂 Account Age -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <div style="font-size: 2.5rem; color: var(--success-color); margin-bottom: 0.5rem;">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <h3 style="margin: 0; font-size: 2rem; font-weight: 700;">
                                <?php echo $stats['accountAge'] ?? 'N/A'; ?>
                            </h3>
                            <p style="color: var(--text-secondary); margin: 0.5rem 0 0;">Member Since</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 🏢 Organization Membership -->
            <?php if ($organization): ?>
            <div class="card" style="margin-top: 2rem;">
                <div class="card-header">
                    <h2><i class="fas fa-building"></i> Organization</h2>
                </div>
                <div class="card-body">
                    <div style="display: flex; justify-content: space-between; align-items: start; gap: 2rem;">
                        <div style="flex: 1;">
                            <h3 style="margin: 0 0 0.5rem; font-size: 1.5rem;">
                                <?php echo htmlspecialchars($organization['name']); ?>
                            </h3>
                            <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                                <?php echo htmlspecialchars($organization['description'] ?? 'No description'); ?>
                            </p>
                            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                                <span class="badge badge-lg badge-primary">
                                    <i class="fas fa-user-tag"></i> <?php echo ucfirst($organizationRole); ?>
                                </span>
                                <span class="badge badge-lg badge-<?php echo $organization['accountStatus'] === 'active' ? 'success' : 'secondary'; ?>">
                                    <?php echo ucfirst($organization['accountStatus']); ?>
                                </span>
                            </div>
                        </div>
                        <div>
                            <a href="/organization/dashboard" class="btn btn-primary">
                                <i class="fas fa-building"></i> View Organization
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- 🔐 Security Status -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-shield-alt"></i> Security Status</h3>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-3" style="border-bottom: 1px solid var(--border-color);">
                        <div class="d-flex align-items-center">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(16, 185, 129, 0.1); display: flex; align-items: center; justify-content: center; margin-right: 1rem;">
                                <i class="fas fa-check" style="color: var(--success-color);"></i>
                            </div>
                            <div>
                                <strong>Email Verified</strong>
                                <p style="margin: 0; color: var(--text-secondary); font-size: 0.875rem;">
                                    <?php echo htmlspecialchars($user['email']); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mb-3 pb-3" style="border-bottom: 1px solid var(--border-color);">
                        <div class="d-flex align-items-center">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(239, 68, 68, 0.1); display: flex; align-items: center; justify-content: center; margin-right: 1rem;">
                                <i class="fas fa-times" style="color: var(--danger-color);"></i>
                            </div>
                            <div>
                                <strong>Two-Factor Authentication</strong>
                                <p style="margin: 0; color: var(--text-secondary); font-size: 0.875rem;">
                                    Not enabled
                                </p>
                            </div>
                        </div>
                        <a href="/security" class="btn btn-sm btn-primary">Enable</a>
                    </div>

                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(59, 130, 246, 0.1); display: flex; align-items: center; justify-content: center; margin-right: 1rem;">
                                <i class="fas fa-key" style="color: var(--info-color);"></i>
                            </div>
                            <div>
                                <strong>Password</strong>
                                <p style="margin: 0; color: var(--text-secondary); font-size: 0.875rem;">
                                    Last changed: Never
                                </p>
                            </div>
                        </div>
                        <a href="/account" class="btn btn-sm btn-secondary">Change</a>
                    </div>
                </div>
            </div>

            <!-- 📱 Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-bolt"></i> Quick Actions</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <a href="/account" class="btn btn-outline-primary btn-block" style="height: 100px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                <i class="fas fa-cog" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                                <span>Account Settings</span>
                            </a>
                        </div>
                        <div class="col-md-4 mb-3">
                            <a href="/security" class="btn btn-outline-primary btn-block" style="height: 100px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                <i class="fas fa-lock" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                                <span>Security</span>
                            </a>
                        </div>
                        <div class="col-md-4 mb-3">
                            <a href="/linked-accounts" class="btn btn-outline-primary btn-block" style="height: 100px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                <i class="fas fa-link" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                                <span>Linked Accounts</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 📊 Sidebar -->
        <div class="col-lg-3">
            <!-- 👤 Profile Card -->
            <div class="card mb-4">
                <div class="card-body text-center">
                    <div style="width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%); display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 2rem; color: white;">
                        <i class="fas fa-user"></i>
                    </div>
                    <h4 style="margin: 0 0 0.25rem; font-weight: 600;">
                        <?php echo htmlspecialchars($user['displayName'] ?? $user['username']); ?>
                    </h4>
                    <p style="color: var(--text-secondary); margin: 0 0 1rem; font-size: 0.875rem;">
                        @<?php echo htmlspecialchars($user['username']); ?>
                    </p>
                    <span class="badge" style="background: var(--success-color); color: white; padding: 0.375rem 0.75rem; border-radius: 1rem;">
                        <?php echo ucfirst($user['accountTier']); ?> Account
                    </span>
                </div>
            </div>

            <!-- 🔔 Recent Activity -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title" style="margin: 0; font-size: 1rem;">
                        <i class="fas fa-history"></i> Recent Activity
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-start mb-3">
                        <i class="fas fa-sign-in-alt" style="color: var(--success-color); margin-right: 0.75rem; margin-top: 0.25rem;"></i>
                        <div>
                            <strong style="display: block; font-size: 0.875rem;">Logged in</strong>
                            <small style="color: var(--text-muted);">
                                <?php
                                if ($stats['lastLogin']) {
                                    $lastLogin = new DateTime($stats['lastLogin']);
                                    echo $lastLogin->format('M d, Y g:i A');
                                } else {
                                    echo 'Just now';
                                }
                                ?>
                            </small>
                        </div>
                    </div>
                    <p class="text-center text-muted" style="margin: 0; font-size: 0.875rem;">
                        <a href="/activity">View all activity</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 📜 JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="/assets/js/main.js"></script>

</body>
</html>
