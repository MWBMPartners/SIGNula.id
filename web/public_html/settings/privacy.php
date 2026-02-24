<?php
/**
 * ============================================================================
 * 🔒 SIGNula - Privacy Settings
 * ============================================================================
 *
 * Purpose: Manage privacy preferences and data sharing
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.5.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔒 Require login
requireLogin();

$pageTitle = 'Privacy Settings';
$user = getCurrentUser();
$userID = $_SESSION['userID'];

$message = null;
$messageType = null;

// 📝 Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_privacy') {
        try {
            // 📊 Get privacy preferences
            $profileVisibility = $_POST['profileVisibility'] ?? 'private';
            $shareActivityStatus = isset($_POST['shareActivityStatus']) ? 1 : 0;
            $allowThirdPartyAccess = isset($_POST['allowThirdPartyAccess']) ? 1 : 0;
            $marketingEmails = isset($_POST['marketingEmails']) ? 1 : 0;
            $analyticsTracking = isset($_POST['analyticsTracking']) ? 1 : 0;
            $showOnlineStatus = isset($_POST['showOnlineStatus']) ? 1 : 0;

            // ✅ Validate profile visibility
            $validVisibility = ['private', 'friends', 'public'];
            if (!in_array($profileVisibility, $validVisibility)) {
                throw new Exception('Invalid profile visibility setting');
            }

            // 💾 Get existing preferences or create new
            $existingPrefs = Database::fetchOne(
                "SELECT * FROM tblUserPreferences WHERE userID = ?",
                [$userID],
                'i'
            );

            if ($existingPrefs) {
                // 🔄 Update existing preferences
                $query = "
                    UPDATE tblUserPreferences
                    SET profileVisibility = ?,
                        shareActivityStatus = ?,
                        allowThirdPartyAccess = ?,
                        marketingEmails = ?,
                        analyticsTracking = ?,
                        showOnlineStatus = ?,
                        updatedAt = NOW()
                    WHERE userID = ?
                ";

                Database::query(
                    $query,
                    [
                        $profileVisibility,
                        $shareActivityStatus,
                        $allowThirdPartyAccess,
                        $marketingEmails,
                        $analyticsTracking,
                        $showOnlineStatus,
                        $userID
                    ],
                    'siiiii'
                );
            } else {
                // 📝 Create new preferences
                $query = "
                    INSERT INTO tblUserPreferences
                    (userID, profileVisibility, shareActivityStatus, allowThirdPartyAccess,
                     marketingEmails, analyticsTracking, showOnlineStatus, createdAt)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ";

                Database::query(
                    $query,
                    [
                        $userID,
                        $profileVisibility,
                        $shareActivityStatus,
                        $allowThirdPartyAccess,
                        $marketingEmails,
                        $analyticsTracking,
                        $showOnlineStatus
                    ],
                    'isiiii'
                );
            }

            // 📝 Log activity
            ActivityLogger::log(
                userID: $userID,
                activityType: 'privacy_settings_updated',
                activityResult: 'success',
                activityDetails: 'Privacy settings updated'
            );

            $message = 'Privacy settings updated successfully!';
            $messageType = 'success';

        } catch (Exception $e) {
            error_log("Privacy settings update error: " . $e->getMessage());
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    } elseif ($action === 'revoke_app_access') {
        try {
            $apiKeyID = intval($_POST['apiKeyID'] ?? 0);

            // 🔍 Verify API key belongs to user
            $apiKey = Database::fetchOne(
                "SELECT * FROM tblAPIKeys WHERE keyID = ? AND userID = ?",
                [$apiKeyID, $userID],
                'ii'
            );

            if (!$apiKey) {
                throw new Exception('API key not found or does not belong to you');
            }

            // 🗑️ Revoke API key
            $query = "UPDATE tblAPIKeys SET isActive = 0, revokedAt = NOW() WHERE keyID = ?";
            $result = Database::query($query, [$apiKeyID], 'i');

            if ($result) {
                // 📝 Log activity
                ActivityLogger::log(
                    userID: $userID,
                    activityType: 'api_access_revoked',
                    activityResult: 'success',
                    activityDetails: "Revoked API access for: {$apiKey['keyName']}"
                );

                $message = 'Third-party access revoked successfully!';
                $messageType = 'success';
            } else {
                throw new Exception('Failed to revoke access');
            }

        } catch (Exception $e) {
            error_log("Revoke access error: " . $e->getMessage());
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// 🔍 Get current privacy preferences
$preferences = Database::fetchOne(
    "SELECT * FROM tblUserPreferences WHERE userID = ?",
    [$userID],
    'i'
) ?? [
    'profileVisibility' => 'private',
    'shareActivityStatus' => 0,
    'allowThirdPartyAccess' => 0,
    'marketingEmails' => 0,
    'analyticsTracking' => 1,
    'showOnlineStatus' => 0
];

// 🔍 Get third-party apps with access
$thirdPartyApps = Database::fetchAll(
    "SELECT * FROM tblAPIKeys
     WHERE userID = ? AND isActive = 1
     ORDER BY createdAt DESC",
    [$userID],
    'i'
) ?? [];

// 🎨 Include header
include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container mt-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 mb-4">
            <?php include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'settings-sidebar.php'; ?>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <div class="card shadow">
                <div class="card-body p-4">
                    <h1 class="h3 mb-4">🔒 Privacy Settings</h1>

                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted mb-4">
                        Control how your information is shared and who can access your data.
                    </p>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_privacy">

                        <!-- Profile Visibility -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">Profile Visibility</h5>

                            <div class="mb-3">
                                <label class="form-label">Who can view your profile?</label>

                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="profileVisibility"
                                           id="visibilityPrivate" value="private"
                                           <?php echo $preferences['profileVisibility'] === 'private' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="visibilityPrivate">
                                        <strong>Private</strong> - Only you can view your profile
                                        <small class="text-muted d-block">Most secure option</small>
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="profileVisibility"
                                           id="visibilityFriends" value="friends"
                                           <?php echo $preferences['profileVisibility'] === 'friends' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="visibilityFriends">
                                        <strong>Friends Only</strong> - Connected accounts and trusted users
                                        <small class="text-muted d-block">Share with people you trust</small>
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="profileVisibility"
                                           id="visibilityPublic" value="public"
                                           <?php echo $preferences['profileVisibility'] === 'public' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="visibilityPublic">
                                        <strong>Public</strong> - Anyone can view your basic profile
                                        <small class="text-muted d-block">Basic information only</small>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Activity & Status -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">Activity & Status</h5>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="shareActivityStatus"
                                       name="shareActivityStatus" value="1"
                                       <?php echo $preferences['shareActivityStatus'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="shareActivityStatus">
                                    <strong>Share activity status</strong>
                                    <small class="text-muted d-block">
                                        Let connected apps see when you're active
                                    </small>
                                </label>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="showOnlineStatus"
                                       name="showOnlineStatus" value="1"
                                       <?php echo $preferences['showOnlineStatus'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="showOnlineStatus">
                                    <strong>Show online status</strong>
                                    <small class="text-muted d-block">
                                        Display when you're online to other users
                                    </small>
                                </label>
                            </div>
                        </div>

                        <!-- Data Sharing -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">Data Sharing</h5>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="allowThirdPartyAccess"
                                       name="allowThirdPartyAccess" value="1"
                                       <?php echo $preferences['allowThirdPartyAccess'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="allowThirdPartyAccess">
                                    <strong>Allow third-party app access</strong>
                                    <small class="text-muted d-block">
                                        Permit approved third-party applications to access your basic profile
                                    </small>
                                </label>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="analyticsTracking"
                                       name="analyticsTracking" value="1"
                                       <?php echo $preferences['analyticsTracking'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="analyticsTracking">
                                    <strong>Help improve SIGNula</strong>
                                    <small class="text-muted d-block">
                                        Share anonymous usage data to help us improve the service
                                    </small>
                                </label>
                            </div>
                        </div>

                        <!-- Communications -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2 mb-3">Communications</h5>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="marketingEmails"
                                       name="marketingEmails" value="1"
                                       <?php echo $preferences['marketingEmails'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="marketingEmails">
                                    <strong>Receive marketing emails</strong>
                                    <small class="text-muted d-block">
                                        Get updates about new features, tips, and special offers
                                    </small>
                                </label>
                            </div>

                            <div class="alert alert-info">
                                <small>
                                    <i class="fas fa-info-circle"></i>
                                    <strong>Note:</strong> You will always receive important security and account-related emails
                                    regardless of this setting.
                                </small>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="/settings" class="btn btn-outline-secondary">
                                ← Back to Settings
                            </a>
                            <button type="submit" class="btn btn-primary">
                                💾 Save Privacy Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Third-Party App Access -->
            <div class="card shadow mt-4">
                <div class="card-body p-4">
                    <h5 class="mb-3">🔗 Third-Party App Access</h5>

                    <?php if (empty($thirdPartyApps)): ?>
                        <div class="alert alert-info text-center">
                            <i class="fas fa-shield-alt fs-3 mb-3 d-block"></i>
                            <p class="mb-0">
                                No third-party applications currently have access to your account.
                            </p>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-3">
                            These applications have been granted access to your SIGNula account.
                            You can revoke access at any time.
                        </p>

                        <div class="list-group">
                            <?php foreach ($thirdPartyApps as $app): ?>
                                <div class="list-group-item">
                                    <div class="d-flex align-items-start">
                                        <div class="me-3">
                                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                                                 style="width: 48px; height: 48px;">
                                                <i class="fas fa-puzzle-piece"></i>
                                            </div>
                                        </div>

                                        <div class="flex-grow-1">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($app['keyName']); ?></h6>
                                            <small class="text-muted">
                                                <i class="fas fa-calendar"></i>
                                                Access granted <?php echo timeAgo($app['createdAt']); ?>
                                            </small>
                                            <?php if ($app['lastUsedAt']): ?>
                                                <small class="text-muted ms-2">
                                                    <i class="fas fa-clock"></i>
                                                    Last used <?php echo timeAgo($app['lastUsedAt']); ?>
                                                </small>
                                            <?php endif; ?>

                                            <?php if ($app['permissions']): ?>
                                                <div class="mt-2">
                                                    <small class="text-muted">
                                                        <strong>Permissions:</strong>
                                                        <?php
                                                        $permissions = json_decode($app['permissions'], true) ?? [];
                                                        echo htmlspecialchars(implode(', ', $permissions));
                                                        ?>
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <form method="POST" action="" class="d-inline"
                                              onsubmit="return confirm('Are you sure you want to revoke access for this application?');">
                                            <input type="hidden" name="action" value="revoke_app_access">
                                            <input type="hidden" name="apiKeyID" value="<?php echo $app['keyID']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-times"></i> Revoke
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Data Rights & Privacy Information -->
            <div class="card shadow mt-4">
                <div class="card-body p-4">
                    <h5 class="mb-3">📋 Your Data Rights</h5>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="card h-100 border-primary">
                                <div class="card-body">
                                    <h6 class="card-title">
                                        <i class="fas fa-download text-primary"></i> Export Your Data
                                    </h6>
                                    <p class="card-text small text-muted">
                                        Download a copy of all your SIGNula data in JSON or CSV format.
                                    </p>
                                    <a href="/settings/export-data" class="btn btn-sm btn-outline-primary">
                                        Export Data
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <div class="card h-100 border-danger">
                                <div class="card-body">
                                    <h6 class="card-title">
                                        <i class="fas fa-trash-alt text-danger"></i> Delete Account
                                    </h6>
                                    <p class="card-text small text-muted">
                                        Permanently delete your account and all associated data.
                                    </p>
                                    <a href="/settings/delete-account" class="btn btn-sm btn-outline-danger">
                                        Delete Account
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        <h6 class="alert-heading">
                            <i class="fas fa-shield-alt"></i> We Respect Your Privacy
                        </h6>
                        <ul class="mb-0 small">
                            <li>We never sell your personal information</li>
                            <li>Your data is encrypted both in transit and at rest</li>
                            <li>You can export or delete your data at any time</li>
                            <li>We comply with GDPR, CCPA, and other privacy regulations</li>
                            <li>Read our <a href="/legal/privacy-policy" target="_blank">Privacy Policy</a> for more details</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// 🎨 Include footer
include SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'footer.php';
?>
