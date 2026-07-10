<?php
/**
 * ============================================================================
 * 🎯 SIGNula - Settings Sidebar Navigation
 * ============================================================================
 *
 * Purpose: Sidebar navigation for account settings pages
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// Get current page to highlight active menu item
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>

<div class="card shadow-sm">
    <div class="card-body p-3">
        <h6 class="text-muted text-uppercase small mb-3">Account Settings</h6>

        <nav class="nav flex-column">
            <!-- Profile -->
            <a
                href="/settings/profile"
                class="nav-link <?php echo $currentPage === 'profile' ? 'active' : ''; ?>"
            >
                <span class="me-2">👤</span> Profile
            </a>

            <!-- Security -->
            <a
                href="/settings/security"
                class="nav-link <?php echo $currentPage === 'security' ? 'active' : ''; ?>"
            >
                <span class="me-2">🔐</span> Security
            </a>

            <!-- PassKeys -->
            <a
                href="/settings/passkeys"
                class="nav-link <?php echo $currentPage === 'passkeys' ? 'active' : ''; ?>"
            >
                <span class="me-2">🔑</span> PassKeys
            </a>

            <!-- MFA -->
            <a
                href="/settings/mfa"
                class="nav-link <?php echo $currentPage === 'mfa' ? 'active' : ''; ?>"
            >
                <span class="me-2">📱</span> Two-Factor Auth
            </a>

            <!-- Recovery Keys -->
            <a
                href="/settings/recovery-keys"
                class="nav-link <?php echo $currentPage === 'recovery-keys' ? 'active' : ''; ?>"
            >
                <span class="me-2">🔑</span> Recovery Keys
            </a>

            <!-- Connected Accounts -->
            <a
                href="/settings/connected-accounts"
                class="nav-link <?php echo $currentPage === 'connected-accounts' ? 'active' : ''; ?>"
            >
                <span class="me-2">🔗</span> Connected Accounts
            </a>

            <!-- Connected Apps (OAuth/OIDC Relying Parties — G-001 Stage A4) -->
            <a
                href="/settings/connected-apps"
                class="nav-link <?php echo $currentPage === 'connected-apps' ? 'active' : ''; ?>"
            >
                <span class="me-2">🔌</span> Connected Apps
            </a>

            <!-- Activity Log -->
            <a
                href="/settings/activity"
                class="nav-link <?php echo $currentPage === 'activity' ? 'active' : ''; ?>"
            >
                <span class="me-2">📊</span> Activity Log
            </a>

            <!-- Privacy -->
            <a
                href="/settings/privacy"
                class="nav-link <?php echo $currentPage === 'privacy' ? 'active' : ''; ?>"
            >
                <span class="me-2">🔒</span> Privacy
            </a>

            <!-- Notification Center -->
            <a
                href="/settings/notification-center"
                class="nav-link <?php echo $currentPage === 'notification-center' ? 'active' : ''; ?>"
            >
                <span class="me-2">🔔</span> Notifications
            </a>

            <!-- Notification Preferences -->
            <a
                href="/settings/notifications"
                class="nav-link <?php echo $currentPage === 'notifications' ? 'active' : ''; ?>"
            >
                <span class="me-2">⚙️</span> Notification Preferences
            </a>
        </nav>

        <hr class="my-3">

        <!-- Danger Zone -->
        <h6 class="text-danger text-uppercase small mb-3">Danger Zone</h6>

        <nav class="nav flex-column">
            <a
                href="/settings/export-data"
                class="nav-link text-muted"
            >
                <span class="me-2">📦</span> Export Data
            </a>

            <a
                href="/settings/delete-account"
                class="nav-link text-danger"
            >
                <span class="me-2">🗑️</span> Delete Account
            </a>
        </nav>
    </div>
</div>

<style>
.nav-link {
    padding: 0.5rem 0.75rem;
    color: #6c757d;
    border-radius: 0.375rem;
    transition: all 0.2s;
}

.nav-link:hover {
    background-color: #f8f9fa;
    color: #212529;
}

.nav-link.active {
    background-color: #007bff;
    color: white;
}

.nav-link.active:hover {
    background-color: #0056b3;
}

.nav-link.text-danger:hover {
    background-color: #fff5f5;
}
</style>
