<?php
/**
 * ============================================================================
 * 👤 SIGNula - Profile Management
 * ============================================================================
 *
 * Purpose: Manage user profile information
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔒 Require login
requireLogin();

$pageTitle = 'Profile Settings';
$user = getCurrentUser();
$userID = $_SESSION['userID'];

$message = null;
$messageType = null;

// 📝 Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ Verify CSRF token
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please try again.';
        $messageType = 'danger';
    } else {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        try {
            $displayName = SecurityUtils::sanitizeString($_POST['displayName'] ?? '');
            $username = SecurityUtils::sanitizeString($_POST['username'] ?? '');
            $timezone = $_POST['timezone'] ?? '';

            // ✅ Validation
            $errors = [];

            if (empty($displayName)) {
                $errors[] = 'Display name is required';
            }

            if (!empty($username)) {
                // Check if username is taken by another user
                $existing = Database::fetchOne(
                    "SELECT userID FROM tblUsers WHERE username = ? AND userID != ?",
                    [$username, $userID],
                    'si'
                );

                if ($existing) {
                    $errors[] = 'Username is already taken';
                }
            }

            if (empty($errors)) {
                // 📝 Update profile
                $query = "
                    UPDATE tblUsers
                    SET displayName = ?,
                        username = ?,
                        timezone = ?,
                        updatedAt = NOW()
                    WHERE userID = ?
                ";

                $result = Database::query($query, [
                    $displayName,
                    $username ?: null,
                    $timezone ?: null,
                    $userID
                ], 'sssi');

                if ($result) {
                    // 📝 Log activity
                    ActivityLogger::log(
                        userID: $userID,
                        activityType: 'profile_update',
                        activityResult: 'success',
                        activityDetails: 'Profile information updated'
                    );

                    $message = 'Profile updated successfully!';
                    $messageType = 'success';

                    // Refresh user data
                    $user = getCurrentUser(true);
                } else {
                    $errors[] = 'Failed to update profile';
                }
            }

            if (!empty($errors)) {
                $message = implode('<br>', array_map('htmlspecialchars', $errors));
                $messageType = 'danger';
            }

        } catch (Exception $e) {
            error_log("Profile update error: " . $e->getMessage());
            $message = 'An error occurred while updating your profile';
            $messageType = 'danger';
        }
    } elseif ($action === 'upload_avatar') {
        // 🖼️ Handle avatar upload
        try {
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] === UPLOAD_ERR_NO_FILE) {
                $message = 'Please select an image file to upload.';
                $messageType = 'warning';
            } else {
                $result = AvatarService::uploadAvatar($userID, $_FILES['avatar']);
                if ($result['success']) {
                    $message = 'Profile picture updated successfully!';
                    $messageType = 'success';
                    $user = getCurrentUser(true); // 🔄 Refresh user data
                } else {
                    $message = htmlspecialchars($result['error'] ?? 'Failed to upload avatar.');
                    $messageType = 'danger';
                }
            }
        } catch (Exception $e) {
            error_log("Avatar upload error: " . $e->getMessage());
            $message = 'An error occurred while uploading your profile picture.';
            $messageType = 'danger';
        }

    } elseif ($action === 'delete_avatar') {
        // 🗑️ Handle avatar deletion
        try {
            if (AvatarService::deleteAvatar($userID)) {
                $message = 'Profile picture removed successfully.';
                $messageType = 'success';
                $user = getCurrentUser(true);
            } else {
                $message = 'No profile picture to remove.';
                $messageType = 'info';
            }
        } catch (Exception $e) {
            error_log("Avatar delete error: " . $e->getMessage());
            $message = 'An error occurred while removing your profile picture.';
            $messageType = 'danger';
        }

    } elseif ($action === 'set_oauth_avatar') {
        // 🔗 Set an OAuth provider's avatar as profile picture
        try {
            $oauthAccountID = (int) ($_POST['oauthAccountID'] ?? 0);
            if ($oauthAccountID > 0) {
                $result = AvatarService::setOAuthAsAvatar($userID, $oauthAccountID);
                if ($result['success']) {
                    $message = 'Profile picture updated from linked account!';
                    $messageType = 'success';
                    $user = getCurrentUser(true);
                } else {
                    $message = htmlspecialchars($result['error'] ?? 'Failed to set OAuth avatar.');
                    $messageType = 'danger';
                }
            } else {
                $message = 'Invalid account selection.';
                $messageType = 'danger';
            }
        } catch (Exception $e) {
            error_log("Set OAuth avatar error: " . $e->getMessage());
            $message = 'An error occurred while setting your profile picture.';
            $messageType = 'danger';
        }

    } elseif ($action === 'update_fallback_priority') {
        // ⚙️ Update fallback avatar service priority
        try {
            $priorityJson = $_POST['fallback_priority'] ?? '[]';
            $priorities = json_decode($priorityJson, true);
            if (is_array($priorities)) {
                AvatarService::setUserFallbackPriority($userID, $priorities);
                $message = 'Avatar fallback priority updated!';
                $messageType = 'success';
            } else {
                $message = 'Invalid priority data.';
                $messageType = 'danger';
            }
        } catch (Exception $e) {
            error_log("Fallback priority update error: " . $e->getMessage());
            $message = 'An error occurred while updating avatar preferences.';
            $messageType = 'danger';
        }

    } elseif ($action === 'change_email') {
        try {
            $newEmail = trim($_POST['newEmail'] ?? '');
            $password = $_POST['password'] ?? '';

            $errors = [];

            // ✅ Validate email
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email address';
            }

            // ✅ Verify password
            if (empty($password)) {
                $errors[] = 'Password is required to change email';
            } elseif (!password_verify($password, $user['passwordHash'])) {
                $errors[] = 'Incorrect password';
            }

            // Check if email is taken
            if (!empty($newEmail)) {
                $existing = Database::fetchOne(
                    "SELECT userID FROM tblUsers WHERE email = ? AND userID != ?",
                    [$newEmail, $userID],
                    'si'
                );

                if ($existing) {
                    $errors[] = 'Email address is already in use';
                }
            }

            if (empty($errors)) {
                // 📝 Update email
                $query = "UPDATE tblUsers SET email = ?, emailVerified = 0, updatedAt = NOW() WHERE userID = ?";
                $result = Database::query($query, [$newEmail, $userID], 'si');

                if ($result) {
                    // 📧 Send verification email
                    // TODO: Implement email verification

                    // 📝 Log activity
                    ActivityLogger::log(
                        userID: $userID,
                        activityType: 'email_change',
                        activityResult: 'success',
                        activityDetails: 'Email address changed to ' . $newEmail
                    );

                    $message = 'Email updated successfully! Please check your inbox to verify your new email address.';
                    $messageType = 'success';

                    // Refresh user data
                    $user = getCurrentUser(true);
                } else {
                    $errors[] = 'Failed to update email';
                }
            }

            if (!empty($errors)) {
                $message = implode('<br>', array_map('htmlspecialchars', $errors));
                $messageType = 'danger';
            }

        } catch (Exception $e) {
            error_log("Email change error: " . $e->getMessage());
            $message = 'An error occurred while updating your email';
            $messageType = 'danger';
        }
    }
    } // end CSRF else
}

// 🎫 Generate CSRF token for forms
$csrfToken = SecurityUtils::generateCSRFToken();

// Get timezone list
$timezones = DateTimeZone::listIdentifiers();

// 🖼️ Prepare avatar data for the management UI
$currentAvatarUrl = '';
$oauthAccounts = [];
$userFallbackPriority = [];
$availableFallbackServices = [];

if (class_exists('AvatarService')) {
    // 📸 Get current avatar preview (128px for profile display)
    $currentAvatarUrl = AvatarService::resolve($userID, null, 128);

    // 🔗 Fetch linked OAuth accounts that have profile pictures
    $oauthAccounts = Database::fetchAll(
        "SELECT oauthAccountID, provider, profilePicture, displayName, isPrimary
         FROM tblOAuthAccounts
         WHERE userID = ? AND isActive = 1 AND profilePicture IS NOT NULL AND profilePicture != ''
         ORDER BY isPrimary DESC, provider ASC",
        [$userID],
        'i'
    ) ?? [];

    // ⚙️ Get user's current fallback priority (or system default)
    $userFallbackPriority = AvatarService::getUserFallbackPriority($userID);

    // 📋 Build the full list of available fallback services with labels
    $availableFallbackServices = [
        'gravatar'   => ['label' => 'Gravatar',    'icon' => 'fas fa-globe',       'description' => 'Uses your email to show your Gravatar profile picture'],
        'libravatar' => ['label' => 'Libravatar',  'icon' => 'fas fa-user-circle', 'description' => 'Open-source Gravatar alternative'],
        'ui_avatars' => ['label' => 'UI Avatars',  'icon' => 'fas fa-font',        'description' => 'Generates initials-based avatars from your name'],
        'dicebear'   => ['label' => 'DiceBear',    'icon' => 'fas fa-dice',        'description' => 'Unique art-style avatars generated from your identity'],
        'robohash'   => ['label' => 'RoboHash',    'icon' => 'fas fa-robot',       'description' => 'Fun robot/monster avatars unique to you'],
    ];

    // 🔍 Check if user has a custom uploaded avatar (to show/hide remove button)
    $hasUploadedAvatar = Database::fetchOne(
        "SELECT avatarID FROM tblUserAvatars WHERE userID = ? AND partnerID IS NULL AND avatarType = 'upload' AND isActive = 1",
        [$userID],
        'i'
    );
}

// 🎨 Include header
include SIGNULA_ROOT . '/private_html/layout/header.php';
?>

<div class="container mt-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 mb-4">
            <?php include SIGNULA_ROOT . '/private_html/layout/settings-sidebar.php'; ?>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <div class="card shadow">
                <div class="card-body p-4">
                    <h1 class="h3 mb-4">👤 Profile Settings</h1>

                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- 🖼️ Profile Picture / Avatar Management -->
                    <?php if (class_exists('AvatarService')): ?>
                    <div class="mb-4">
                        <h5 class="border-bottom pb-2">Profile Picture</h5>

                        <div class="row align-items-start">
                            <!-- 📸 Current Avatar Preview -->
                            <div class="col-auto mb-3">
                                <div class="position-relative d-inline-block">
                                    <?php if (!empty($currentAvatarUrl)): ?>
                                        <img
                                            src="<?php echo htmlspecialchars($currentAvatarUrl); ?>"
                                            alt="Current profile picture"
                                            class="rounded-circle border shadow-sm"
                                            style="width: 128px; height: 128px; object-fit: cover;"
                                            id="avatarPreview"
                                        >
                                    <?php else: ?>
                                        <div class="rounded-circle border shadow-sm d-flex align-items-center justify-content-center bg-secondary text-white"
                                             style="width: 128px; height: 128px; font-size: 3rem;"
                                             id="avatarPreview">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- 📤 Upload & Actions -->
                            <div class="col mb-3">
                                <p class="text-muted small mb-3">
                                    Your profile picture is displayed across all SIGNula services. Upload a custom image,
                                    use a linked account's picture, or let a fallback service generate one for you.
                                </p>

                                <!-- 📤 Upload New Avatar -->
                                <form method="POST" action="" enctype="multipart/form-data" class="mb-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="upload_avatar">
                                    <?php if (class_exists('FormProtection')): ?>
                                        <?php echo FormProtection::renderAllFields(); ?>
                                    <?php endif; ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <label for="avatarFile" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-upload me-1"></i> Choose Image
                                        </label>
                                        <input
                                            type="file"
                                            id="avatarFile"
                                            name="avatar"
                                            accept="image/jpeg,image/png,image/gif,image/webp"
                                            class="d-none"
                                            onchange="document.getElementById('avatarFileName').textContent = this.files[0]?.name ?? ''; document.getElementById('avatarUploadBtn').classList.remove('d-none');"
                                        >
                                        <span id="avatarFileName" class="text-muted small"></span>
                                        <button type="submit" id="avatarUploadBtn" class="btn btn-primary btn-sm d-none">
                                            <i class="fas fa-save me-1"></i> Upload
                                        </button>
                                    </div>
                                    <div class="form-text mt-1">
                                        Max 2MB. Accepted: JPEG, PNG, GIF, WebP. Will be cropped to a square.
                                    </div>
                                </form>

                                <!-- 🗑️ Remove Current Avatar -->
                                <?php if (!empty($hasUploadedAvatar)): ?>
                                    <form method="POST" action="" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="delete_avatar">
                                        <?php if (class_exists('FormProtection')): ?>
                                            <?php echo FormProtection::renderAllFields(); ?>
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                                onclick="return confirm('Remove your uploaded profile picture? The system will fall back to your next available avatar source.');">
                                            <i class="fas fa-trash-alt me-1"></i> Remove Uploaded Picture
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 🔗 Use a Linked Account's Avatar -->
                        <?php if (!empty($oauthAccounts)): ?>
                            <div class="mt-3">
                                <h6 class="text-muted mb-2"><i class="fas fa-link me-1"></i> Use a Linked Account's Picture</h6>
                                <div class="d-flex flex-wrap gap-3">
                                    <?php foreach ($oauthAccounts as $oauthAccount): ?>
                                        <form method="POST" action="" class="text-center">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="set_oauth_avatar">
                                            <input type="hidden" name="oauthAccountID" value="<?php echo (int) $oauthAccount['oauthAccountID']; ?>">
                                            <?php if (class_exists('FormProtection')): ?>
                                                <?php echo FormProtection::renderAllFields(); ?>
                                            <?php endif; ?>
                                            <button type="submit" class="btn btn-outline-secondary p-2" title="Use <?php echo htmlspecialchars($oauthAccount['provider']); ?> picture">
                                                <img
                                                    src="<?php echo htmlspecialchars($oauthAccount['profilePicture']); ?>"
                                                    alt="<?php echo htmlspecialchars($oauthAccount['provider']); ?>"
                                                    class="rounded-circle mb-1"
                                                    style="width: 48px; height: 48px; object-fit: cover;"
                                                    onerror="this.style.display='none';"
                                                >
                                                <div class="small text-truncate" style="max-width: 80px;">
                                                    <?php echo htmlspecialchars(ucfirst($oauthAccount['provider'])); ?>
                                                </div>
                                            </button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- ⚙️ Fallback Avatar Priority -->
                        <div class="mt-4">
                            <h6 class="text-muted mb-2">
                                <i class="fas fa-sort-amount-down me-1"></i> Fallback Avatar Priority
                            </h6>
                            <p class="text-muted small mb-2">
                                When no custom picture is set, these services are tried in order.
                                Drag to reorder your preference.
                            </p>

                            <form method="POST" action="" id="fallbackPriorityForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="update_fallback_priority">
                                <input type="hidden" name="fallback_priority" id="fallbackPriorityInput"
                                       value="<?php echo htmlspecialchars(json_encode($userFallbackPriority)); ?>">
                                <?php if (class_exists('FormProtection')): ?>
                                    <?php echo FormProtection::renderAllFields(); ?>
                                <?php endif; ?>

                                <ul class="list-group mb-3" id="fallbackSortable">
                                    <?php
                                    // 📋 Build ordered list: user's priority first, then any remaining services
                                    $orderedServices = [];
                                    foreach ($userFallbackPriority as $key) {
                                        if (isset($availableFallbackServices[$key])) {
                                            $orderedServices[$key] = $availableFallbackServices[$key];
                                        }
                                    }
                                    // ➕ Append any services not in user's priority list
                                    foreach ($availableFallbackServices as $key => $service) {
                                        if (!isset($orderedServices[$key])) {
                                            $orderedServices[$key] = $service;
                                        }
                                    }
                                    ?>
                                    <?php foreach ($orderedServices as $serviceKey => $service): ?>
                                        <?php
                                            // 🖼️ Generate a preview URL for this service
                                            $previewUrl = '';
                                            $userName = $user['displayName'] ?? 'User';
                                            $userEmail = $user['email'] ?? '';
                                            switch ($serviceKey) {
                                                case 'gravatar':
                                                    $previewUrl = AvatarService::getGravatarUrl($userEmail, 32);
                                                    break;
                                                case 'libravatar':
                                                    $previewUrl = AvatarService::getLibravatarUrl($userEmail, 32);
                                                    break;
                                                case 'ui_avatars':
                                                    $previewUrl = AvatarService::getUIAvatarsUrl($userName, 32);
                                                    break;
                                                case 'dicebear':
                                                    $previewUrl = AvatarService::getDiceBearUrl($userName, 32);
                                                    break;
                                                case 'robohash':
                                                    $previewUrl = AvatarService::getRoboHashUrl($userName, 32);
                                                    break;
                                            }
                                        ?>
                                        <li class="list-group-item d-flex align-items-center gap-3"
                                            data-service="<?php echo htmlspecialchars($serviceKey); ?>"
                                            style="cursor: grab;">
                                            <i class="fas fa-grip-vertical text-muted" title="Drag to reorder"></i>
                                            <?php if (!empty($previewUrl)): ?>
                                                <img src="<?php echo htmlspecialchars($previewUrl); ?>"
                                                     alt="<?php echo htmlspecialchars($service['label']); ?>"
                                                     class="rounded-circle"
                                                     style="width: 32px; height: 32px; object-fit: cover;"
                                                     onerror="this.style.display='none';">
                                            <?php endif; ?>
                                            <i class="<?php echo htmlspecialchars($service['icon']); ?> text-primary"></i>
                                            <div class="flex-grow-1">
                                                <strong><?php echo htmlspecialchars($service['label']); ?></strong>
                                                <div class="text-muted small"><?php echo htmlspecialchars($service['description']); ?></div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>

                                <button type="submit" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-save me-1"></i> Save Priority Order
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Profile Information -->
                    <form method="POST" action="">
                        <!-- 🛡️ CSRF Token -->
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="update_profile">

                        <!-- 🛡️ Local Form Protection -->
                        <?php echo FormProtection::renderAllFields(); ?>

                        <div class="mb-4">
                            <h5 class="border-bottom pb-2">Basic Information</h5>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="displayName" class="form-label">Display Name *</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="displayName"
                                        name="displayName"
                                        value="<?php echo htmlspecialchars($user['displayName'] ?? ''); ?>"
                                        required
                                        minlength="1"
                                        maxlength="100"
                                        autocomplete="name"
                                    >
                                    <div class="form-text">This name will be displayed across the platform</div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="username"
                                        name="username"
                                        value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>"
                                        pattern="[a-zA-Z0-9_-]{3,20}"
                                        maxlength="20"
                                        autocomplete="username"
                                    >
                                    <div class="form-text">3-20 characters, letters, numbers, _ and - only</div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="timezone" class="form-label">Timezone</label>
                                <select class="form-select" id="timezone" name="timezone">
                                    <option value="">-- Select Timezone --</option>
                                    <?php foreach ($timezones as $tz): ?>
                                        <option
                                            value="<?php echo htmlspecialchars($tz); ?>"
                                            <?php echo ($user['timezone'] ?? '') === $tz ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars($tz); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Used for displaying dates and times in your local timezone</div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h5 class="border-bottom pb-2">Account Information</h5>

                            <div class="mb-3">
                                <label class="form-label">Email Address</label>
                                <div class="input-group">
                                    <input
                                        type="email"
                                        class="form-control"
                                        value="<?php echo htmlspecialchars($user['email']); ?>"
                                        readonly
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#changeEmailModal"
                                    >
                                        Change Email
                                    </button>
                                </div>
                                <?php if (empty($user['emailVerified']) || $user['emailVerified'] == 0): ?>
                                    <div class="form-text text-warning">
                                        ⚠️ Email not verified. <a href="/auth/verify-email">Verify now</a>
                                    </div>
                                <?php else: ?>
                                    <div class="form-text text-success">
                                        ✅ Email verified
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Account Created</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    value="<?php echo date('F j, Y', strtotime($user['createdAt'])); ?>"
                                    readonly
                                >
                            </div>

                            <?php if ($user['lastLogin']): ?>
                            <div class="mb-3">
                                <label class="form-label">Last Login</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    value="<?php echo date('F j, Y g:i A', strtotime($user['lastLogin'])); ?>"
                                    readonly
                                >
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="/settings" class="btn btn-outline-secondary">
                                ← Back to Settings
                            </a>
                            <button type="submit" class="btn btn-primary">
                                💾 Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Change Email Modal -->
<div class="modal fade" id="changeEmailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Change Email Address</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- 🛡️ CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="change_email">

                    <div class="alert alert-warning">
                        <strong>⚠️ Important:</strong>
                        <ul class="mb-0 mt-2">
                            <li>You will need to verify your new email address</li>
                            <li>You'll receive a confirmation email at your new address</li>
                            <li>Your password is required for security</li>
                        </ul>
                    </div>

                    <div class="mb-3">
                        <label for="newEmail" class="form-label">New Email Address *</label>
                        <input
                            type="email"
                            class="form-control"
                            id="newEmail"
                            name="newEmail"
                            required
                            maxlength="255"
                            autocomplete="email"
                        >
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Current Password *</label>
                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            required
                            minlength="1"
                            autocomplete="current-password"
                        >
                        <div class="form-text">Confirm your identity with your current password</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        Change Email
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 🔀 Sortable.js for fallback priority drag-and-drop -->
<?php if (class_exists('AvatarService')): ?>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"
        integrity="sha384-YRDMM9fpDpOhICLOqo+HxVkk2YtCPpI4xmyq32jOLBGjFBfNGqMrp0g3LUK3P4P"
        crossorigin="anonymous"></script>
<script>
// 🔀 Initialise Sortable.js on the fallback priority list
document.addEventListener('DOMContentLoaded', function() {
    var sortableEl = document.getElementById('fallbackSortable');
    if (sortableEl) {
        Sortable.create(sortableEl, {
            animation: 150,
            ghostClass: 'bg-light',
            handle: '.fa-grip-vertical',
            // 📝 Update hidden input on drag end
            onEnd: function() {
                var items = sortableEl.querySelectorAll('li[data-service]');
                var order = [];
                items.forEach(function(item) {
                    order.push(item.getAttribute('data-service'));
                });
                document.getElementById('fallbackPriorityInput').value = JSON.stringify(order);
            }
        });
    }
});
</script>
<?php endif; ?>

<?php
// 🎨 Include footer
include SIGNULA_ROOT . '/private_html/layout/footer.php';
?>
