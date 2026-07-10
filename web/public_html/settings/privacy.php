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
$csrfToken = SecurityUtils::generateCSRFToken();

// ============================================================================
// 🔑 PRIVACY PREFERENCE KEY MAP
// ============================================================================
// 🐛 B-066: this page used to read/write tblUserPreferences as if it were a
// WIDE table with one column per preference (profileVisibility,
// shareActivityStatus, ...). The REAL schema (verified against
// information_schema) is a generic per-user EAV key/value store —
// (preferenceID, userID, preferenceKey, preferenceValue, createdAt,
// updatedAt) — the SAME shape AvatarService.php already uses for
// 'avatar.fallback_priority' and I18n.php uses for 'i18n.locale'. The old
// `INSERT/UPDATE ... SET profileVisibility = ?, shareActivityStatus = ?, ...`
// fatals with "Unknown column 'profileVisibility'" against the real table.
//
// Each short preference name below is namespaced 'privacy.<name>' when
// stored, so it never collides with another feature's key in the same
// shared table.
//
// @see web/private_html/utils/AvatarService.php (getUserFallbackPriority()/setUserFallbackPriority() — the upsert pattern this mirrors)
$PRIVACY_PREFERENCE_KEYS = [
    'profileVisibility'     => ['prefKey' => 'privacy.profileVisibility',     'default' => 'private'],
    'shareActivityStatus'   => ['prefKey' => 'privacy.shareActivityStatus',   'default' => 0],
    'allowThirdPartyAccess' => ['prefKey' => 'privacy.allowThirdPartyAccess', 'default' => 0],
    'marketingEmails'       => ['prefKey' => 'privacy.marketingEmails',       'default' => 0],
    'analyticsTracking'     => ['prefKey' => 'privacy.analyticsTracking',     'default' => 1],
    'showOnlineStatus'      => ['prefKey' => 'privacy.showOnlineStatus',      'default' => 0],
];

// ============================================================================
// 🍪 CONSENT TYPE ALLOWLIST (G-004 Layer 1 — FG-002a)
// ============================================================================
// 🔒 The ONLY consentType values this page is permitted to record via the
// 'record_consent' POST action below. Server-authoritative closed set —
// mirrors the free-but-conventional keys documented on
// tblConsentRecords.consentType (migration 040). Also doubles as the
// display map for the "My Consent History" read-only section further down.
//
// @see web/private_html/compliance/ConsentManager.php
// @see _database/migrations/040_consent_and_dsar.sql
$CONSENT_TYPE_LABELS = [
    'cookies.analytics'  => 'Analytics Cookies',
    'cookies.marketing'  => 'Marketing Cookies',
    'cookies.functional' => 'Functional Cookies',
    'marketing.email'    => 'Marketing Emails',
];

/**
 * 📥 Load the current user's privacy preferences from the tblUserPreferences
 * EAV store, filling in defaults for any key that has never been saved.
 *
 * @param int   $userID   The user to load preferences for
 * @param array $keyMap   $PRIVACY_PREFERENCE_KEYS
 * @return array<string, mixed> Short preference name => value
 */
function loadPrivacyPreferences(int $userID, array $keyMap): array
{
    $rows = Database::fetchAll(
        "SELECT preferenceKey, preferenceValue FROM tblUserPreferences
         WHERE userID = ? AND preferenceKey LIKE 'privacy.%'",
        [$userID],
        'i'
    ) ?? [];

    $byKey = [];
    foreach ($rows as $row) {
        $byKey[$row['preferenceKey']] = $row['preferenceValue'];
    }

    $result = [];
    foreach ($keyMap as $shortName => $meta) {
        $result[$shortName] = array_key_exists($meta['prefKey'], $byKey)
            ? (is_int($meta['default']) ? (int) $byKey[$meta['prefKey']] : $byKey[$meta['prefKey']])
            : $meta['default'];
    }

    return $result;
}

/**
 * 💾 Upsert a single privacy preference into the tblUserPreferences EAV
 * store — mirrors AvatarService::setUserFallbackPriority()'s
 * INSERT ... ON DUPLICATE KEY UPDATE pattern exactly.
 *
 * @param int    $userID  The user the preference belongs to
 * @param string $prefKey Namespaced preference key (e.g. 'privacy.profileVisibility')
 * @param string $value   Preference value (scalars are stored as strings)
 * @return void
 */
function savePrivacyPreference(int $userID, string $prefKey, string $value): void
{
    Database::query(
        "INSERT INTO tblUserPreferences (userID, preferenceKey, preferenceValue)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE preferenceValue = ?, updatedAt = CURRENT_TIMESTAMP",
        [$userID, $prefKey, $value, $value],
        'isss'
    );
}

// ============================================================================
// 📥 HANDLE DATA EXPORT DOWNLOAD (GET with token)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['action']) && $_GET['action'] === 'download_export') {
    $downloadToken = $_GET['token'] ?? '';
    if (!empty($downloadToken)) {
        // 🔍 Look up export by token
        if (!class_exists('AccountManager')) {
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html'
                . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'AccountManager.php';
        }
        $export = AccountManager::getExportByToken($downloadToken);
        if ($export && $export['userID'] == $userID) {
            AccountManager::serveExportFile($export['exportID']);
            // serveExportFile() exits
        }
    }
    $message = 'Export not found or has expired.';
    $messageType = 'danger';
}

// 📝 Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_privacy') {
        // 🛡️ B-066: this branch (and revoke_app_access below) had NO CSRF
        // check at all — added to match export_data/request_deletion/
        // cancel_deletion below and the project's standing CSRF requirement.
        if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = 'Invalid security token. Please try again.';
            $messageType = 'danger';
        } else {
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

            // 💾 Upsert each preference individually into the tblUserPreferences
            // EAV store — see the $PRIVACY_PREFERENCE_KEYS map + savePrivacyPreference()
            // above for why (B-066: tblUserPreferences has no per-preference columns).
            $newValues = [
                'profileVisibility'     => $profileVisibility,
                'shareActivityStatus'   => $shareActivityStatus,
                'allowThirdPartyAccess' => $allowThirdPartyAccess,
                'marketingEmails'       => $marketingEmails,
                'analyticsTracking'     => $analyticsTracking,
                'showOnlineStatus'      => $showOnlineStatus,
            ];

            foreach ($newValues as $shortName => $value) {
                savePrivacyPreference($userID, $PRIVACY_PREFERENCE_KEYS[$shortName]['prefKey'], (string) $value);
            }

            // 📝 Log activity
            // 🐛 B-066: ActivityLogger::log() has no $activityResult/$activityDetails
            // params — see profile.php's B-066 note. Mapped to $description.
            ActivityLogger::log(
                userID: $userID,
                activityType: 'privacy_settings_updated',
                description: 'Privacy settings updated'
            );

            $message = 'Privacy settings updated successfully!';
            $messageType = 'success';

        } catch (Exception $e) {
            error_log("Privacy settings update error: " . $e->getMessage());
            $message = $e->getMessage();
            $messageType = 'danger';
        }
        } // end CSRF else (update_privacy)
    } elseif ($action === 'revoke_app_access') {
        // 🛡️ B-066: see the update_privacy branch above — this action had
        // no CSRF check either.
        if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = 'Invalid security token. Please try again.';
            $messageType = 'danger';
        } else {
        // 🐛 B-066: tblAPIKeys has NO `userID` column (verified against
        // information_schema — API keys in this schema belong to a PARTNER
        // via `partnerID`, they are not scoped to an individual SIGNula
        // user), so `WHERE keyID = ? AND userID = ?` fatals with "Unknown
        // column 'userID'" on every GET load below, before this POST branch
        // is even reached. "Third-party apps with access to MY account" is a
        // per-USER, revocable grant — that IS exactly what tblOAuthConsents
        // (userID, clientID, scope, grantedAt, revokedAt — built for the
        // G-001 OAuth provider work) models. Retargeted the whole
        // "Third-Party App Access" section to tblOAuthConsents + tblOAuthClients.
        try {
            $consentID = intval($_POST['consentID'] ?? 0);

            // 🔍 Verify consent belongs to user and is not already revoked
            $consent = Database::fetchOne(
                "SELECT c.consentID, cl.clientName
                 FROM tblOAuthConsents c
                 INNER JOIN tblOAuthClients cl ON cl.clientID = c.clientID
                 WHERE c.consentID = ? AND c.userID = ? AND c.revokedAt IS NULL",
                [$consentID, $userID],
                'ii'
            );

            if (!$consent) {
                throw new Exception('Application access not found or does not belong to you');
            }

            // 🗑️ Revoke consent (tblOAuthConsents has no isActive flag —
            // revokedAt IS NULL is "active", mirroring tblOAuthAccessGrants)
            $query = "UPDATE tblOAuthConsents SET revokedAt = NOW() WHERE consentID = ?";
            $result = Database::query($query, [$consentID], 'i');

            if ($result) {
                // 📝 Log activity
                ActivityLogger::log(
                    userID: $userID,
                    activityType: 'api_access_revoked',
                    description: "Revoked API access for: {$consent['clientName']}"
                );

                $message = 'Third-party access revoked successfully!';
                $messageType = 'success';
            } else {
                throw new Exception('Failed to revoke access');
            }

        } catch (Exception $e) {
            error_log("Revoke access error: " . $e->getMessage());
            $message = htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
        } // end CSRF else (revoke_app_access)

    // ====================================================================
    // 📦 EXPORT USER DATA (GDPR Article 20)
    // ====================================================================
    } elseif ($action === 'export_data') {
        if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = 'Invalid security token. Please try again.';
            $messageType = 'danger';
        } else {
            $password = $_POST['export_password'] ?? '';
            if (empty($password)) {
                $message = 'Password is required to export your data.';
                $messageType = 'danger';
            } else {
                if (!class_exists('AccountManager')) {
                    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html'
                        . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'AccountManager.php';
                }
                $result = AccountManager::exportUserData($userID, $password);
                $message = htmlspecialchars($result['message']);
                $messageType = $result['success'] ? 'success' : 'danger';
            }
        }

    // ====================================================================
    // 🗑️ REQUEST ACCOUNT DELETION (GDPR Article 17)
    // ====================================================================
    } elseif ($action === 'request_deletion') {
        if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = 'Invalid security token. Please try again.';
            $messageType = 'danger';
        } else {
            $password = $_POST['deletion_password'] ?? '';
            $reason = $_POST['deletion_reason'] ?? '';
            $confirmText = $_POST['confirm_deletion'] ?? '';

            if ($confirmText !== 'DELETE MY ACCOUNT') {
                $message = 'Please type "DELETE MY ACCOUNT" to confirm.';
                $messageType = 'danger';
            } elseif (empty($password)) {
                $message = 'Password is required to delete your account.';
                $messageType = 'danger';
            } else {
                if (!class_exists('AccountManager')) {
                    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html'
                        . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'AccountManager.php';
                }
                $result = AccountManager::requestAccountDeletion($userID, $password, $reason);
                $message = htmlspecialchars($result['message']);
                $messageType = $result['success'] ? 'warning' : 'danger';
            }
        }

    // ====================================================================
    // ↩️ CANCEL ACCOUNT DELETION
    // ====================================================================
    } elseif ($action === 'cancel_deletion') {
        if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = 'Invalid security token. Please try again.';
            $messageType = 'danger';
        } else {
            if (!class_exists('AccountManager')) {
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html'
                    . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'AccountManager.php';
            }
            $result = AccountManager::cancelAccountDeletion($userID);
            $message = htmlspecialchars($result['message']);
            $messageType = $result['success'] ? 'success' : 'danger';
            // 🔄 Refresh user data after cancellation
            $user = getCurrentUser();
        }

    // ====================================================================
    // 🍪 RECORD CONSENT DECISION (G-004 Layer 1 — FG-002a)
    // ====================================================================
    // Server-persisted grant/withdraw for a cookie/marketing consent type.
    // This is the authenticated "preference center" surface — the
    // localStorage-only footer banner (public-footer.php) is replaced with
    // a properly server-persisted, granular flow in a later G-004 cycle
    // (Layer 2); this action gives logged-in users a working, auditable
    // toggle today.
    } elseif ($action === 'record_consent') {
        if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = 'Invalid security token. Please try again.';
            $messageType = 'danger';
        } else {
            // 🧼 Sanitise, then validate against the server-authoritative
            // allowlist ($CONSENT_TYPE_LABELS above) — this form must never
            // be able to write an arbitrary consentType into the audit trail.
            $consentType = SecurityUtils::sanitizeString($_POST['consent_type'] ?? '');
            $granted = ($_POST['granted'] ?? '') === '1';

            if (!array_key_exists($consentType, $CONSENT_TYPE_LABELS)) {
                $message = 'Unrecognised consent type.';
                $messageType = 'danger';
            } else {
                if (!class_exists('ConsentManager')) {
                    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html'
                        . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'ConsentManager.php';
                }

                // 📝 visitorID is null here — this action only fires for an
                // authenticated user (requireLogin() above), so the decision
                // is recorded straight against userID. Pre-login/anonymous
                // consent capture (visitorID cookie) is a Layer 2 concern.
                ConsentManager::record(
                    $userID,
                    null,
                    $consentType,
                    $granted,
                    ['mechanism' => 'preference_center']
                );

                $message = 'Your consent preference has been saved.';
                $messageType = 'success';
            }
        }
    }
}

// 🔍 Get current privacy preferences (see $PRIVACY_PREFERENCE_KEYS / loadPrivacyPreferences() above)
$preferences = loadPrivacyPreferences($userID, $PRIVACY_PREFERENCE_KEYS);

// 🔍 Get third-party apps with access — tblOAuthConsents (per-user, revocable
// grants to an OAuth CLIENT app), joined to tblOAuthClients for display
// details. `cl.isFirstParty = 0` excludes SIGNula's own first-party
// apps/services — this section is specifically "Third-Party App Access".
$thirdPartyApps = Database::fetchAll(
    "SELECT c.consentID, c.clientID, c.scope, c.grantedAt,
            cl.clientName, cl.logoURI
     FROM tblOAuthConsents c
     INNER JOIN tblOAuthClients cl ON cl.clientID = c.clientID
     WHERE c.userID = ? AND c.revokedAt IS NULL AND cl.isFirstParty = 0
     ORDER BY c.grantedAt DESC",
    [$userID],
    'i'
) ?? [];

// 🔍 Get the user's current effective consent decisions (latest per type) —
// used by the "My Consent History" read-only section below (G-004 Layer 1).
// ConsentManager lives under private_html/compliance/, which is NOT one of
// the directories web/_config/config.php's spl_autoload_register() scans
// (auth/security/utils/api/email/database only), so it must be required
// explicitly — same class_exists()-guarded lazy-require idiom used for
// AccountManager above.
if (!class_exists('ConsentManager')) {
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'compliance' . DIRECTORY_SEPARATOR . 'ConsentManager.php';
}
$consentDecisions = ConsentManager::getEffectiveConsents($userID, null);

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
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

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

            <!-- ============================================================ -->
            <!-- 🍪 CONSENT PREFERENCES + HISTORY (G-004 Layer 1 — FG-002a)   -->
            <!-- ============================================================ -->
            <div class="card shadow mt-4">
                <div class="card-body p-4">
                    <h5 class="mb-3"><i class="fas fa-clipboard-check text-primary"></i> My Consent History</h5>
                    <p class="text-muted small mb-3">
                        A record of your consent decisions for cookies and marketing communications.
                        Every change is written as a new, timestamped entry — nothing is ever silently
                        overwritten, so this is also your audit trail.
                    </p>

                    <div class="list-group">
                        <?php foreach ($CONSENT_TYPE_LABELS as $consentTypeKey => $consentTypeLabel): ?>
                            <?php
                            // 🔍 Latest decision for this type — absent from the map means
                            // "no decision recorded yet", which we treat as not-granted.
                            $isConsentGranted = $consentDecisions[$consentTypeKey] ?? false;
                            ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?php echo htmlspecialchars($consentTypeLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <div class="mt-1">
                                        <span class="badge bg-<?php echo $isConsentGranted ? 'success' : 'secondary'; ?>">
                                            <?php echo $isConsentGranted ? 'Granted' : 'Not granted'; ?>
                                        </span>
                                    </div>
                                </div>

                                <form method="POST" action="" class="d-inline">
                                    <input type="hidden" name="action" value="record_consent">
                                    <input type="hidden" name="consent_type"
                                           value="<?php echo htmlspecialchars($consentTypeKey, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="granted"
                                           value="<?php echo $isConsentGranted ? '0' : '1'; ?>">
                                    <input type="hidden" name="csrf_token"
                                           value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-<?php echo $isConsentGranted ? 'danger' : 'success'; ?>">
                                        <?php echo $isConsentGranted ? 'Withdraw' : 'Grant'; ?>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
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
                                            <h6 class="mb-1"><?php echo htmlspecialchars($app['clientName']); ?></h6>
                                            <small class="text-muted">
                                                <i class="fas fa-calendar"></i>
                                                Access granted <?php echo timeAgo($app['grantedAt']); ?>
                                            </small>
                                            <?php // 🐛 B-066: tblOAuthConsents has no lastUsedAt column — dropped
                                                  // rather than invent one. ?>

                                            <?php if (!empty($app['scope'])): ?>
                                                <div class="mt-2">
                                                    <small class="text-muted">
                                                        <strong>Permissions:</strong>
                                                        <?php
                                                        // 🐛 B-066: tblOAuthConsents.scope is a space-separated OAuth2
                                                        // scope string (VARCHAR(512)), NOT JSON — matches
                                                        // tblOAuthAccessGrants.scope's same storage convention.
                                                        $permissions = preg_split('/\s+/', trim($app['scope']));
                                                        echo htmlspecialchars(implode(', ', $permissions));
                                                        ?>
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <form method="POST" action="" class="d-inline"
                                              onsubmit="return confirm('Are you sure you want to revoke access for this application?');">
                                            <input type="hidden" name="action" value="revoke_app_access">
                                            <input type="hidden" name="consentID" value="<?php echo (int) $app['consentID']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
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

            <!-- ============================================================ -->
            <!-- 📦 DATA EXPORT (GDPR Article 20 — Right to Data Portability) -->
            <!-- ============================================================ -->
            <div class="card shadow mt-4">
                <div class="card-body p-4">
                    <h5 class="mb-3"><i class="fas fa-download text-primary"></i> Export Your Data</h5>
                    <p class="text-muted small mb-3">
                        Download a copy of all your personal data stored in SIGNula in JSON format.
                        This includes your profile, activity log, linked accounts, payments, preferences, and more.
                        <br><small>Per GDPR Article 20 (Right to Data Portability).</small>
                    </p>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="export_data">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                        <div class="mb-3">
                            <label for="export_password" class="form-label">Confirm your password to export</label>
                            <input type="password" class="form-control" id="export_password" name="export_password"
                                   required autocomplete="current-password" placeholder="Enter your password">
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-download me-1"></i> Export My Data
                        </button>
                    </form>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- 🗑️ ACCOUNT DELETION (GDPR Article 17 — Right to Erasure) -->
            <!-- ============================================================ -->
            <div class="card shadow mt-4 border-danger">
                <div class="card-body p-4">
                    <?php if (!empty($user['deletionRequestedAt'])): ?>
                        <!-- ⏰ Deletion Already Scheduled -->
                        <h5 class="mb-3 text-danger"><i class="fas fa-exclamation-triangle"></i> Account Deletion Pending</h5>
                        <div class="alert alert-warning">
                            <p class="mb-1"><strong>Your account is scheduled for deletion on:</strong></p>
                            <p class="mb-2 fs-5"><?php echo date('j F Y', strtotime($user['deletionScheduledFor'])); ?></p>
                            <p class="mb-0 small">You can cancel this request before the scheduled date.</p>
                        </div>

                        <form method="POST" action="">
                            <input type="hidden" name="action" value="cancel_deletion">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-undo me-1"></i> Cancel Deletion
                            </button>
                        </form>

                    <?php else: ?>
                        <!-- 🗑️ Request Deletion Form -->
                        <h5 class="mb-3 text-danger"><i class="fas fa-trash-alt"></i> Delete Your Account</h5>
                        <div class="alert alert-danger mb-3">
                            <strong>Warning:</strong> This action will permanently delete your account and all associated data
                            after a <?php echo htmlspecialchars(getSetting('gdpr.deletion.grace_period_days', '30')); ?>-day grace period.
                            This cannot be undone.
                        </div>

                        <form method="POST" action="" id="deletionForm">
                            <input type="hidden" name="action" value="request_deletion">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                            <div class="mb-3">
                                <label for="deletion_reason" class="form-label">Reason for leaving (optional)</label>
                                <textarea class="form-control" id="deletion_reason" name="deletion_reason"
                                          rows="2" placeholder="Help us improve — why are you deleting your account?"></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="deletion_password" class="form-label">Confirm your password</label>
                                <input type="password" class="form-control" id="deletion_password" name="deletion_password"
                                       required autocomplete="current-password">
                            </div>

                            <div class="mb-3">
                                <label for="confirm_deletion" class="form-label">
                                    Type <strong>DELETE MY ACCOUNT</strong> to confirm
                                </label>
                                <input type="text" class="form-control" id="confirm_deletion" name="confirm_deletion"
                                       required placeholder="DELETE MY ACCOUNT"
                                       pattern="DELETE MY ACCOUNT" title="Type DELETE MY ACCOUNT exactly">
                            </div>

                            <button type="submit" class="btn btn-danger" id="deleteBtn" disabled>
                                <i class="fas fa-trash-alt me-1"></i> Request Account Deletion
                            </button>
                        </form>

                        <script>
                        // 🔒 Enable delete button only when confirmation text matches
                        document.getElementById('confirm_deletion')?.addEventListener('input', function() {
                            document.getElementById('deleteBtn').disabled = (this.value !== 'DELETE MY ACCOUNT');
                        });
                        </script>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- ℹ️ PRIVACY INFORMATION -->
            <!-- ============================================================ -->
            <div class="card shadow mt-4">
                <div class="card-body p-4">
                    <div class="alert alert-info mb-0">
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
