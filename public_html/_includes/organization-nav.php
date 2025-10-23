<?php
/**
 * Organization Navigation Component
 * Shared sidebar navigation for organization pages
 */

// Get current page for active state
$currentPage = basename($_SERVER['PHP_SELF']);

// Get stats for badges
$pendingInvitations = 0;
try {
    $invCount = Database::fetchOne(
        "SELECT COUNT(*) as count FROM tblOrganizationInvitations
         WHERE organizationID = ? AND status = 'pending' AND expiresAt > NOW()",
        [$organizationID ?? 0],
        'i'
    );
    $pendingInvitations = $invCount['count'] ?? 0;
} catch (Exception $e) {
    // Silently fail
}
?>
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

        <a href="/organization/dashboard.php" class="sidebar-nav-item <?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-building"></i>
            <span>Overview</span>
        </a>

        <a href="/organization/members.php" class="sidebar-nav-item <?php echo $currentPage === 'members.php' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i>
            <span>Members</span>
            <?php if ($pendingInvitations > 0): ?>
                <span class="badge"><?php echo $pendingInvitations; ?></span>
            <?php endif; ?>
        </a>

        <a href="/organization/domains.php" class="sidebar-nav-item <?php echo $currentPage === 'domains.php' ? 'active' : ''; ?>">
            <i class="fas fa-globe"></i>
            <span>Domains</span>
        </a>

        <?php if ($isAdmin ?? false): ?>
        <a href="/organization/oauth-policies.php" class="sidebar-nav-item <?php echo $currentPage === 'oauth-policies.php' ? 'active' : ''; ?>">
            <i class="fas fa-key"></i>
            <span>OAuth Policies</span>
        </a>

        <a href="/organization/settings.php" class="sidebar-nav-item <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">
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
