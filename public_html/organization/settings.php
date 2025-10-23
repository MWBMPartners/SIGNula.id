<?php
/**
 * Organization Settings
 * Manage organization profile and settings
 */

require_once dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
Auth::requireAuth();

$user = Auth::getCurrentUser();
$organizationID = $user['organizationID'] ?? null;

if (!$organizationID) {
    redirect('/dashboard?error=' . urlencode('You are not part of an organization.'));
}

$organization = Database::fetchOne("SELECT * FROM tblOrganizations WHERE organizationID = ?", [$organizationID], 'i');
$membership = Database::fetchOne("SELECT role FROM tblOrganizationMembers WHERE organizationID = ? AND userID = ?", [$organizationID, $user['userID']], 'ii');
$isAdmin = in_array($membership['role'] ?? '', ['owner', 'admin']);

if (!$isAdmin) {
    redirect('/organization/dashboard?error=' . urlencode('Access denied.'));
}

echo '<!DOCTYPE html><html><head><title>Settings - ' . htmlspecialchars($organization['name']) . '</title>';
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
echo '<link rel="stylesheet" href="/assets/css/main.css"></head><body>';
echo '<div class="dashboard-main" style="padding: 2rem;"><div class="container">';
echo '<h1>Organization Settings</h1>';
echo '<p class="text-muted">Manage ' . htmlspecialchars($organization['name']) . ' settings</p>';
echo '<div class="alert alert-info"><i class="fas fa-info-circle"></i> Organization settings management coming soon. This feature will allow you to update organization details, branding, and policies.</div>';
echo '<a href="/organization/dashboard" class="btn btn-primary">Back to Dashboard</a>';
echo '</div></div></body></html>';
