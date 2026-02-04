<?php
/**
 * ============================================================================
 * 🏢 SIGNula - Organization Dashboard
 * ============================================================================
 *
 * Purpose: Main dashboard for organization management
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔄 Require authentication
Auth::requireAuth();

// Get current user
$user = Auth::getCurrentUser();

// 🏢 Check if user belongs to an organization
$organizationID = $user['organizationID'] ?? null;
$organization = null;
$userRole = null;
$isAdmin = false;

if ($organizationID) {
    // Get organization details
    $organization = Database::fetchOne(
        "SELECT * FROM tblOrganizations WHERE organizationID = ?",
        [$organizationID],
        'i'
    );

    // Get user's role in organization
    $membership = Database::fetchOne(
        "SELECT role FROM tblOrganizationMembers WHERE organizationID = ? AND userID = ?",
        [$organizationID, $user['userID']],
        'ii'
    );

    $userRole = $membership['role'] ?? 'member';
    $isAdmin = in_array($userRole, ['owner', 'admin']);
}

// 🚫 Redirect if not part of organization
if (!$organization) {
    redirect('/dashboard?error=' . urlencode('You are not part of an organization.'));
}

// 📊 Get organization statistics
$stats = [
    'members' => 0,
    'domains' => 0,
    'verifiedDomains' => 0,
    'pendingInvitations' => 0,
    'linkedAccounts' => 0
];

try {
    // Member count
    $memberCount = Database::fetchOne(
        "SELECT COUNT(*) as count FROM tblOrganizationMembers WHERE organizationID = ?",
        [$organizationID],
        'i'
    );
    $stats['members'] = $memberCount['count'] ?? 0;

    // Domain statistics
    $domainStats = Database::fetchOne(
        "SELECT
            COUNT(*) as total,
            SUM(CASE WHEN isVerified = TRUE THEN 1 ELSE 0 END) as verified
         FROM tblOrganizationDomains
         WHERE organizationID = ?",
        [$organizationID],
        'i'
    );
    $stats['domains'] = $domainStats['total'] ?? 0;
    $stats['verifiedDomains'] = $domainStats['verified'] ?? 0;

    // Pending invitations
    $invitations = Database::fetchOne(
        "SELECT COUNT(*) as count
         FROM tblOrganizationInvitations
         WHERE organizationID = ?
         AND status = 'pending'
         AND expiresAt > NOW()",
        [$organizationID],
        'i'
    );
    $stats['pendingInvitations'] = $invitations['count'] ?? 0;

    // Linked accounts from organization members
    $linkedAccounts = Database::fetchOne(
        "SELECT COUNT(DISTINCT ula.linkedAccountID) as count
         FROM tblUserLinkedAccounts ula
         JOIN tblUsers u ON ula.userID = u.userID
         WHERE u.organizationID = ?",
        [$organizationID],
        'i'
    );
    $stats['linkedAccounts'] = $linkedAccounts['count'] ?? 0;

} catch (Exception $e) {
    ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
}

// 📋 Get recent activity (last 10 items)
$recentActivity = [];
try {
    $recentActivity = Database::fetchAll(
        "SELECT al.*, u.displayName, u.email
         FROM tblActivityLog al
         LEFT JOIN tblUsers u ON al.userID = u.userID
         WHERE u.organizationID = ?
         AND al.category IN ('organization', 'auth', 'security')
         ORDER BY al.createdAt DESC
         LIMIT 10",
        [$organizationID],
        'i'
    );
} catch (Exception $e) {
    ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
}

// 📄 Page metadata
$pageTitle = $organization['name'] . ' - Organization Dashboard - SIGNula';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage your organization">
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
<body>

<!-- 📱 Mobile Menu Toggle -->
<button class="mobile-menu-toggle" id="mobileMenuToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- 🗂️ Sidebar Navigation -->
<aside class="dashboard-sidebar" id="dashboardSidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <i class="fas fa-shield-alt"></i>
            <span>SIGNula</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <a href="/dashboard" class="sidebar-nav-item">
            <i class="fas fa-home"></i>
            <span>My Dashboard</span>
        </a>

        <div class="sidebar-nav-divider">Organization</div>

        <a href="/organization/dashboard" class="sidebar-nav-item active">
            <i class="fas fa-building"></i>
            <span>Overview</span>
        </a>

        <a href="/organization/members" class="sidebar-nav-item">
            <i class="fas fa-users"></i>
            <span>Members</span>
            <?php if ($stats['pendingInvitations'] > 0): ?>
                <span class="badge"><?php echo $stats['pendingInvitations']; ?></span>
            <?php endif; ?>
        </a>

        <a href="/organization/domains" class="sidebar-nav-item">
            <i class="fas fa-globe"></i>
            <span>Domains</span>
        </a>

        <?php if ($isAdmin): ?>
        <a href="/organization/oauth-policies" class="sidebar-nav-item">
            <i class="fas fa-key"></i>
            <span>OAuth Policies</span>
        </a>

        <a href="/organization/settings" class="sidebar-nav-item">
            <i class="fas fa-cog"></i>
            <span>Settings</span>
        </a>
        <?php endif; ?>

        <div class="sidebar-nav-divider">Account</div>

        <a href="/account" class="sidebar-nav-item">
            <i class="fas fa-user-circle"></i>
            <span>My Account</span>
        </a>

        <a href="/security" class="sidebar-nav-item">
            <i class="fas fa-lock"></i>
            <span>Security</span>
        </a>

        <a href="/logout" class="sidebar-nav-item text-danger">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </nav>
</aside>

<!-- 📄 Main Content -->
<main class="dashboard-main">
    <!-- 🎯 Page Header -->
    <div class="page-header">
        <div>
            <h1><i class="fas fa-building"></i> <?php echo htmlspecialchars($organization['name']); ?></h1>
            <p class="text-muted">Manage your organization settings and members</p>
        </div>
        <div class="page-header-actions">
            <span class="badge badge-lg" style="background: var(--primary-color);">
                <i class="fas fa-user-tag"></i> <?php echo ucfirst($userRole); ?>
            </span>
        </div>
    </div>

    <!-- 📊 Statistics Cards -->
    <div class="dashboard-grid">
        <!-- Members Count -->
        <div class="dashboard-card">
            <div class="card-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <i class="fas fa-users"></i>
            </div>
            <div class="card-content">
                <h3><?php echo number_format($stats['members']); ?></h3>
                <p>Team Members</p>
            </div>
            <a href="/organization/members" class="card-link">
                View all <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <!-- Domains Count -->
        <div class="dashboard-card">
            <div class="card-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <i class="fas fa-globe"></i>
            </div>
            <div class="card-content">
                <h3><?php echo number_format($stats['domains']); ?></h3>
                <p>Registered Domains</p>
                <small class="text-muted"><?php echo $stats['verifiedDomains']; ?> verified</small>
            </div>
            <a href="/organization/domains" class="card-link">
                Manage <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <!-- Pending Invitations -->
        <div class="dashboard-card">
            <div class="card-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <i class="fas fa-envelope"></i>
            </div>
            <div class="card-content">
                <h3><?php echo number_format($stats['pendingInvitations']); ?></h3>
                <p>Pending Invitations</p>
            </div>
            <a href="/organization/members?tab=invitations" class="card-link">
                View <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <!-- Linked Accounts -->
        <div class="dashboard-card">
            <div class="card-icon" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                <i class="fas fa-link"></i>
            </div>
            <div class="card-content">
                <h3><?php echo number_format($stats['linkedAccounts']); ?></h3>
                <p>OAuth Connections</p>
            </div>
            <a href="/organization/oauth-policies" class="card-link">
                Manage <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>

    <!-- 🔍 Organization Info & Quick Actions -->
    <div class="row" style="margin-top: 2rem;">
        <!-- Organization Information -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-info-circle"></i> Organization Information</h2>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <label><i class="fas fa-building"></i> Organization Name</label>
                            <div class="info-value"><?php echo htmlspecialchars($organization['name']); ?></div>
                        </div>

                        <div class="info-item">
                            <label><i class="fas fa-link"></i> Organization Slug</label>
                            <div class="info-value"><?php echo htmlspecialchars($organization['slug']); ?></div>
                        </div>

                        <div class="info-item">
                            <label><i class="fas fa-check-circle"></i> Status</label>
                            <div class="info-value">
                                <?php
                                $statusColors = [
                                    'active' => 'success',
                                    'suspended' => 'warning',
                                    'pending' => 'info'
                                ];
                                $badgeColor = $statusColors[$organization['accountStatus']] ?? 'secondary';
                                ?>
                                <span class="badge badge-<?php echo $badgeColor; ?>">
                                    <?php echo ucfirst($organization['accountStatus']); ?>
                                </span>
                            </div>
                        </div>

                        <div class="info-item">
                            <label><i class="fas fa-calendar"></i> Created</label>
                            <div class="info-value">
                                <?php
                                $created = new DateTime($organization['createdAt']);
                                echo $created->format('F j, Y');
                                ?>
                            </div>
                        </div>

                        <?php if ($organization['website']): ?>
                        <div class="info-item">
                            <label><i class="fas fa-external-link-alt"></i> Website</label>
                            <div class="info-value">
                                <a href="<?php echo htmlspecialchars($organization['website']); ?>" target="_blank" rel="noopener">
                                    <?php echo htmlspecialchars($organization['website']); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($organization['description']): ?>
                        <div class="info-item" style="grid-column: 1 / -1;">
                            <label><i class="fas fa-align-left"></i> Description</label>
                            <div class="info-value"><?php echo nl2br(htmlspecialchars($organization['description'])); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                </div>
                <div class="card-body">
                    <div class="quick-actions">
                        <?php if ($isAdmin): ?>
                        <a href="/organization/members?action=invite" class="btn btn-primary btn-block">
                            <i class="fas fa-user-plus"></i> Invite Member
                        </a>

                        <a href="/organization/domains?action=add" class="btn btn-outline btn-block">
                            <i class="fas fa-plus-circle"></i> Add Domain
                        </a>

                        <a href="/organization/settings" class="btn btn-outline btn-block">
                            <i class="fas fa-cog"></i> Organization Settings
                        </a>
                        <?php else: ?>
                        <a href="/organization/members" class="btn btn-primary btn-block">
                            <i class="fas fa-users"></i> View Team
                        </a>
                        <?php endif; ?>

                        <a href="/dashboard" class="btn btn-outline btn-block">
                            <i class="fas fa-home"></i> My Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 📜 Recent Activity -->
    <?php if (!empty($recentActivity)): ?>
    <div class="card" style="margin-top: 2rem;">
        <div class="card-header">
            <h2><i class="fas fa-history"></i> Recent Activity</h2>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="activity-timeline">
                <?php foreach ($recentActivity as $activity): ?>
                <div class="activity-item">
                    <div class="activity-icon">
                        <?php
                        $iconMap = [
                            'login' => 'sign-in-alt',
                            'logout' => 'sign-out-alt',
                            'registration' => 'user-plus',
                            'password_change' => 'key',
                            'mfa_enabled' => 'shield-alt',
                            'organization' => 'building'
                        ];
                        $icon = $iconMap[$activity['activityType']] ?? 'info-circle';
                        ?>
                        <i class="fas fa-<?php echo $icon; ?>"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-description">
                            <strong><?php echo htmlspecialchars($activity['displayName'] ?? $activity['email']); ?></strong>
                            <?php echo htmlspecialchars($activity['description']); ?>
                        </div>
                        <div class="activity-meta">
                            <?php
                            $timestamp = new DateTime($activity['createdAt']);
                            $now = new DateTime();
                            $diff = $now->diff($timestamp);

                            if ($diff->days > 0) {
                                echo $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
                            } elseif ($diff->h > 0) {
                                echo $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
                            } elseif ($diff->i > 0) {
                                echo $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
                            } else {
                                echo 'Just now';
                            }
                            ?>
                            • <?php echo htmlspecialchars($activity['ipAddress']); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<!-- 📜 JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="/assets/js/main.js"></script>

</body>
</html>
