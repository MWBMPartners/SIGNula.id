<?php
/**
 * ============================================================================
 * 🔌 SIGNula - Connected Apps (OAuth/OIDC Relying Parties) — G-001 Stage A4
 * ============================================================================
 *
 * Purpose: The user-facing "Connected Apps" hub — lists every third-party
 * application (OAuth/OIDC Relying Party) the signed-in user has authorised
 * via "Sign in with SIGNula.id" (G-001 Stages A1-A3), with a per-app "Revoke
 * access" action.
 *
 * ⚠️ DISTINCT from `/settings/connected-accounts` — that page manages SOCIAL
 * accounts SIGNula itself CONSUMES for sign-in (Google/Microsoft/Apple/etc,
 * `tblUserLinkedAccounts`). THIS page is the OPPOSITE direction: third-party
 * apps that consume SIGNula as their identity provider (`tblOAuthConsents` /
 * `tblOAuthClients`). Do not confuse the two, and do not merge them — mirrors
 * the identical authorize.php vs authorize-idp.php distinction from Stage A2.
 *
 * Revoking an app performs TWO effects (both required — see
 * OAuthAuthorizeService::revokeConsent()):
 *   1. Marks the `tblOAuthConsents` row `revokedAt = NOW()` — the app drops
 *      off THIS list and Stage A2's auto-skip-consent check stops covering it.
 *   2. Kills every one of that client's OUTSTANDING refresh-token families
 *      for this user ({@see TokenService::revokeClientForUser()}) — the app
 *      can no longer silently mint a new access token via refresh. Its
 *      already-issued access token simply expires on its own short TTL.
 *
 * No-JS-friendly: the revoke action is a plain POST form (no AJAX
 * requirement) — works identically with JavaScript disabled, per this
 * project's house accessibility/graceful-degradation rule.
 *
 * ⚠️ Implementation note (NOT a Stage-A4 concern, flagged for the owner):
 * this page deliberately does NOT reuse the `requireLogin()` /
 * `SIGNULA_ROOT . '/private_html/layout/header.php'` pattern that several
 * SIBLING settings/*.php pages (connected-accounts.php, privacy.php, …)
 * reference — that global function and those two layout files do not
 * actually exist anywhere in this codebase (verified: `requireLogin()` has
 * no definition, and `web/private_html/layout/header.php`/`footer.php` are
 * absent), so those pages currently fatal on load. This page instead uses
 * the PROVEN-WORKING pattern from `oauth/authorize-idp.php`
 * (`Auth::isAuthenticated()` / `Auth::getCurrentUserID()` + a self-contained
 * HTML shell), while still including the REAL, existing
 * `private_html/layout/settings-sidebar.php` (via the correctly-defined
 * `ROOT_DIR` constant, not the undefined `SIGNULA_ROOT`) so the page fits
 * visually into the settings module's navigation.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4).
 *
 * @package    SIGNula
 * @subpackage Settings
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A4)
 * @see web/private_html/auth/OAuthAuthorizeService.php (revokeConsent(), scopeLabels())
 * @see web/private_html/security/TokenService.php       (revokeClientForUser())
 * @see .dev-team/specs/G-001.md §6
 * ============================================================================
 */

// 🚀 Bootstrap the application (defines SIGNULA_INIT, loads settings + autoloader).
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔐 Require an authenticated SESSION (the proven-working pattern — see the
//    class-doc note above on why this page does NOT call requireLogin()).
if (!Auth::isAuthenticated()) {
    redirect('/login?redirect=' . urlencode('/settings/connected-apps'));
}

$userID      = (int) Auth::getCurrentUserID();
$currentUser = Auth::getCurrentUser() ?? [];

$message     = null;
$messageType = null;
$csrfToken   = SecurityUtils::generateCSRFToken();

// ============================================================================
// 📝 HANDLE THE "Revoke access" ACTION
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke_app') {
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message     = 'Invalid or expired security token — please try again.';
        $messageType = 'danger';
    } else {
        $clientID = isset($_POST['clientID']) ? (int) $_POST['clientID'] : 0;

        // 🔍 Re-confirm the LIVE consent actually belongs to THIS user before
        //    doing anything — never trust a posted clientID blindly (the
        //    revokeConsent() UPDATE's own `WHERE userID = ?` already enforces
        //    this at the DB layer too, but fetching first lets us show a
        //    meaningful message and log the app's real name either way).
        $consentClient = $clientID > 0 ? Database::fetchOne(
            "SELECT cl.clientID, cl.clientName
             FROM tblOAuthConsents c
             JOIN tblOAuthClients cl ON cl.clientID = c.clientID
             WHERE c.userID = ? AND c.clientID = ? AND c.revokedAt IS NULL
             LIMIT 1",
            [$userID, $clientID],
            'ii'
        ) : null;

        if ($consentClient === null) {
            $message     = 'That connected app was not found (it may already be revoked).';
            $messageType = 'danger';
        } else {
            OAuthAuthorizeService::revokeConsent($userID, $clientID);

            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    $userID,
                    'oauth_consent_revoked',
                    'auth',
                    'info',
                    "User revoked access for client \"{$consentClient['clientName']}\" via Connected Apps",
                    ['clientID' => $clientID]
                );
            }

            $message     = 'Access revoked for ' . $consentClient['clientName'] . '.';
            $messageType = 'success';
        }
    }
}

// ============================================================================
// 🔍 LOAD ACTIVE (non-revoked) CONNECTED APPS
// ============================================================================
$connectedApps = Database::fetchAll(
    "SELECT c.consentID, c.clientID, c.scope, c.grantedAt,
            cl.clientName, cl.logoURI, cl.clientURI
     FROM tblOAuthConsents c
     JOIN tblOAuthClients cl ON cl.clientID = c.clientID
     WHERE c.userID = ? AND c.revokedAt IS NULL
     ORDER BY c.grantedAt DESC",
    [$userID],
    'i'
) ?? [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage the third-party applications authorised to access your SIGNula ID">
    <title>Connected Apps - SIGNula</title>

    <!-- 🎨 Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">

    <!-- 🎨 Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <!-- 🎨 Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" />

    <!-- 🎨 Site CSS -->
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>

<div class="container mt-5 mb-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 mb-4">
            <?php
            // ✅ settings-sidebar.php DOES exist (only header.php/footer.php do
            //    not) — ROOT_DIR is the real, defined web/ root constant.
            include ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'settings-sidebar.php';
            ?>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <div class="card shadow">
                <div class="card-body p-4">
                    <h1 class="h3 mb-4">🔌 Connected Apps</h1>

                    <?php if ($message !== null): ?>
                        <div class="alert alert-<?php echo htmlspecialchars((string) $messageType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted mb-4">
                        These are the third-party applications you've authorised to sign in with your
                        SIGNula ID. Revoking access immediately blocks the app from obtaining new tokens
                        for your account; any token it currently holds still expires naturally within a
                        few minutes.
                    </p>

                    <?php if (empty($connectedApps)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> You haven't connected any third-party apps yet.
                        </div>
                    <?php else: ?>
                        <?php foreach ($connectedApps as $app): ?>
                            <?php
                            $scopeLabels = OAuthAuthorizeService::scopeLabels((string) $app['scope']);
                            $logoUri     = $app['logoURI'] ?? null;
                            ?>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="d-flex align-items-center flex-wrap">
                                        <!-- App icon -->
                                        <div class="me-3">
                                            <?php if (is_string($logoUri) && $logoUri !== ''): ?>
                                                <img src="<?php echo htmlspecialchars($logoUri, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 40px; height: 40px; border-radius: 8px; object-fit: contain;">
                                            <?php else: ?>
                                                <div class="rounded-circle d-flex align-items-center justify-content-center bg-secondary" style="width: 40px; height: 40px;">
                                                    <i class="fas fa-shield-alt text-white"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- App info -->
                                        <div class="flex-grow-1" style="min-width: 200px;">
                                            <h6 class="mb-1"><?php echo htmlspecialchars((string) $app['clientName'], ENT_QUOTES, 'UTF-8'); ?></h6>
                                            <small class="text-muted">
                                                Connected <?php echo htmlspecialchars((string) $app['grantedAt'], ENT_QUOTES, 'UTF-8'); ?>
                                            </small>
                                            <ul class="small text-muted mb-0 mt-1 ps-3">
                                                <?php foreach ($scopeLabels as $scopeItem): ?>
                                                    <li><?php echo htmlspecialchars($scopeItem['label'], ENT_QUOTES, 'UTF-8'); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>

                                        <!-- Revoke action — plain POST form, works with no JS -->
                                        <div class="ms-auto mt-2 mt-md-0">
                                            <form method="POST" action="/settings/connected-apps" onsubmit="return confirm('Revoke access for this app? It will need to be re-authorised before it can sign you in again.');">
                                                <input type="hidden" name="action" value="revoke_app">
                                                <input type="hidden" name="clientID" value="<?php echo (int) $app['clientID']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-times-circle"></i> Revoke access
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="/settings" class="btn btn-outline-secondary">
                            &larr; Back to Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 🎨 Bootstrap JS bundle (dropdowns/alerts dismiss — progressive enhancement only) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

</body>
</html>
