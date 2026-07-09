<?php
/**
 * ============================================================================
 * 🪪 SIGNula - Partner OAuth Client Management (G-001 Stage A1)
 * ============================================================================
 *
 * Partner-admin CRUD UI for OAuth2/OIDC client registrations ("Sign in with
 * SIGNula.id" app registrations) — register a client, view its details, copy
 * the client_secret the ONE time it's shown, rotate a compromised secret, and
 * deactivate a retired client.
 *
 * Deliberately SELF-CONTAINED and fully usable WITHOUT JavaScript (per house
 * brief: "AJAX... should not be a blocker if a browser doesn't have JS...
 * it should still be possible to use everything... without AJAX"): all
 * mutations are plain <form method="POST"> submissions handled directly on
 * this page (mirrors partners/api-keys.php's single-file pattern). A separate
 * JSON API (partners/api/oauth-client-actions.php) additionally exists for
 * AJAX/programmatic use, but this page does not depend on it.
 *
 * ⚠️ NEEDS-LEAD-REVIEW — auth pattern deviation: this page uses
 * web/private_html/auth/Auth.php (Auth::requireAuth()/getCurrentUserID()) +
 * a small direct tblPartnerTeamMembers ownership check, INSTEAD OF
 * web/_backend/AccessControl.php + a SessionManager class the way sibling
 * files under partners/admin/ and partners/api/ do. Verified while building
 * this stage: web/_backend/SessionManager.php does not exist anywhere in this
 * repo (never committed — confirmed via `git log --all`), so every file that
 * requires it (~74 files, including partners/admin/tiers.php,
 * partners/admin/index.php, and their partners/api/*-actions.php companions)
 * fatals immediately. Separately, AccessControl::loadPartnerMemberships()
 * selects tblPartners.companyName/tier — columns that do not exist in the
 * current schema (real columns: partnerName/accountTier per migration 008) —
 * reproduced directly against signula_test, which throws an uncaught
 * mysqli_sql_exception even if the missing-file problem were bridged. Both
 * are pre-existing bugs unrelated to G-001; flagging here rather than
 * silently diverging from the "mirror the existing pattern" instruction.
 *
 * @see web/private_html/auth/OAuthClientManager.php
 * @see web/public_html/partners/api/oauth-client-actions.php
 * @see web/public_html/partners/api-keys.php (the self-contained UX pattern mirrored here)
 * @see _database/migrations/031_oauth_provider_clients.sql
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Partners\Admin
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A1)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 📦 DEPENDENCY LOADING
// ============================================================================

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'OAuthClientManager.php';

// ============================================================================
// 🛡️ SHARED PARTNER-OWNERSHIP HELPERS (see header note — working substitute
// for the broken AccessControl/SessionManager chain; identical helpers also
// live in oauth-client-actions.php since the two files never load in the same
// process).
// ============================================================================

if (!function_exists('oauthClientsUserIsSuperAdmin')) {
    function oauthClientsUserIsSuperAdmin(int $userID): bool
    {
        $row = Database::fetchOne("SELECT isSuperAdmin FROM tblUsers WHERE userID = ?", [$userID], 'i');
        return $row !== null && (bool) ($row['isSuperAdmin'] ?? false);
    }
}

if (!function_exists('oauthClientsUserIsPartnerAdmin')) {
    function oauthClientsUserIsPartnerAdmin(int $userID, int $partnerID): bool
    {
        if (oauthClientsUserIsSuperAdmin($userID)) {
            return true;
        }
        $membership = Database::fetchOne(
            "SELECT role FROM tblPartnerTeamMembers
             WHERE userID = ? AND partnerID = ? AND status = 'active' AND role IN ('root-admin', 'admin')",
            [$userID, $partnerID],
            'ii'
        );
        return $membership !== null;
    }
}

// ============================================================================
// 🔒 AUTHENTICATION
// ============================================================================

Auth::requireAuth();
$userID = Auth::getCurrentUserID();
$isSuperAdmin = oauthClientsUserIsSuperAdmin($userID);

// ============================================================================
// 🏢 PARTNER SELECTION
// ============================================================================

// 📋 Partners this user administers (root-admin|admin, active membership).
$memberships = Database::fetchAll(
    "SELECT tm.partnerID, tm.role, p.partnerName
     FROM tblPartnerTeamMembers tm
     JOIN tblPartners p ON tm.partnerID = p.partnerID
     WHERE tm.userID = ? AND tm.status = 'active' AND tm.role IN ('root-admin', 'admin')
     ORDER BY p.partnerName ASC",
    [$userID],
    'i'
);

// 👑 Super-admins may pick from EVERY partner, even with no membership row.
$allPartnersForPicker = [];
if ($isSuperAdmin) {
    $allPartnersForPicker = Database::fetchAll(
        "SELECT partnerID, partnerName FROM tblPartners ORDER BY partnerName ASC"
    );
}

if (empty($memberships) && !$isSuperAdmin) {
    redirect('/partners/register.php');
}

$pickerOptions = $isSuperAdmin ? $allPartnersForPicker : $memberships;

$requestedPartnerID = isset($_GET['partner']) ? (int) $_GET['partner'] : 0;
$selectedPartnerID = $requestedPartnerID > 0 ? $requestedPartnerID : (int) ($pickerOptions[0]['partnerID'] ?? 0);

if ($selectedPartnerID <= 0) {
    http_response_code(404);
    die('No partner organisation available to manage.');
}

if (!oauthClientsUserIsPartnerAdmin($userID, $selectedPartnerID)) {
    http_response_code(403);
    die('Admin access required for this partner.');
}

$partnerRow = Database::fetchOne(
    "SELECT partnerID, partnerName FROM tblPartners WHERE partnerID = ?",
    [$selectedPartnerID],
    'i'
);
if ($partnerRow === null) {
    http_response_code(404);
    die('Partner not found.');
}

// ============================================================================
// 📝 HANDLE FORM SUBMISSIONS (no-JS-safe — plain POST, handled inline)
// ============================================================================

$message = null;
$revealSecret = null; // ['clientIdentifier'=>..., 'clientSecret'=>..., 'clientName'=>...] shown ONCE

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = ['type' => 'danger', 'text' => 'Invalid or expired security token. Please refresh and try again.'];
    } else {
        $formAction = $_POST['action'] ?? '';

        if ($formAction === 'register') {
            $clientName = SecurityUtils::sanitizeString($_POST['client_name'] ?? '');
            $clientType = ($_POST['client_type'] ?? 'confidential') === 'public' ? 'public' : 'confidential';

            // 🔗 Redirect URIs — one per line in a textarea.
            $redirectUrisRaw = (string) ($_POST['redirect_uris'] ?? '');
            $redirectUris = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $redirectUrisRaw)));

            // 🔒 Scopes — checkbox list + optional free-text extras.
            $scopeChecks = is_array($_POST['scopes'] ?? null) ? $_POST['scopes'] : [];
            $extraScopes = preg_split('/\s+/', trim((string) ($_POST['extra_scopes'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            $allowedScopes = array_values(array_unique(array_merge(['openid'], $scopeChecks, $extraScopes ?: [])));

            $subjectType = ($_POST['subject_type'] ?? 'pairwise') === 'public' ? 'public' : 'pairwise';
            $sectorIdentifier = trim((string) ($_POST['sector_identifier'] ?? '')) ?: null;

            $opts = [
                // 🛡️ isFirstParty is a privileged trust decision — only a
                //    super-admin may set it; regular partner-admins always get
                //    isFirstParty=false regardless of what they submit.
                'isFirstParty'     => $isSuperAdmin && !empty($_POST['is_first_party']),
                'subjectType'      => $subjectType,
                'sectorIdentifier' => $sectorIdentifier,
                'logoURI'          => trim((string) ($_POST['logo_uri'] ?? '')) ?: null,
                'clientURI'        => trim((string) ($_POST['client_uri'] ?? '')) ?: null,
                'tosURI'           => trim((string) ($_POST['tos_uri'] ?? '')) ?: null,
                'policyURI'        => trim((string) ($_POST['policy_uri'] ?? '')) ?: null,
                'createdBy'        => $userID,
            ];

            if ($clientName === '') {
                $message = ['type' => 'danger', 'text' => 'Please provide a client name.'];
            } elseif (empty($redirectUris)) {
                $message = ['type' => 'danger', 'text' => 'At least one redirect URI is required (one per line).'];
            } else {
                $result = OAuthClientManager::registerClient(
                    $selectedPartnerID, $clientName, $clientType, $redirectUris, $allowedScopes, $opts
                );

                if ($result === false) {
                    $message = ['type' => 'danger', 'text' => 'Could not register the OAuth client — please check the redirect URI format and try again.'];
                } else {
                    $message = ['type' => 'success', 'text' => 'OAuth client "' . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . '" registered.'];
                    if ($result['clientSecret'] !== null) {
                        $revealSecret = [
                            'label'            => 'New client registered',
                            'clientName'       => $clientName,
                            'clientIdentifier' => $result['clientIdentifier'],
                            'clientSecret'     => $result['clientSecret'],
                        ];
                    }
                }
            }
        } elseif ($formAction === 'rotate') {
            $clientID = (int) ($_POST['client_id'] ?? 0);
            $client = OAuthClientManager::getClientByID($clientID);

            if ($client === null || (int) $client['partnerID'] !== $selectedPartnerID) {
                $message = ['type' => 'danger', 'text' => 'OAuth client not found, or does not belong to this partner.'];
            } else {
                $rotated = OAuthClientManager::rotateSecret($clientID);
                if ($rotated === false) {
                    $message = ['type' => 'danger', 'text' => 'Could not rotate secret (public clients have no secret).'];
                } else {
                    $message = ['type' => 'success', 'text' => 'Secret rotated for "' . htmlspecialchars($client['clientName'], ENT_QUOTES, 'UTF-8') . '".'];
                    $revealSecret = [
                        'label'            => 'Secret rotated',
                        'clientName'       => $client['clientName'],
                        'clientIdentifier' => $client['clientIdentifier'],
                        'clientSecret'     => $rotated['clientSecret'],
                    ];
                }
            }
        } elseif ($formAction === 'deactivate') {
            $clientID = (int) ($_POST['client_id'] ?? 0);
            $client = OAuthClientManager::getClientByID($clientID);

            if ($client === null || (int) $client['partnerID'] !== $selectedPartnerID) {
                $message = ['type' => 'danger', 'text' => 'OAuth client not found, or does not belong to this partner.'];
            } else {
                $ok = OAuthClientManager::deactivate($clientID);
                $message = $ok
                    ? ['type' => 'success', 'text' => '"' . htmlspecialchars($client['clientName'], ENT_QUOTES, 'UTF-8') . '" deactivated.']
                    : ['type' => 'danger', 'text' => 'Could not deactivate this client.'];
            }
        }
    }
}

// ============================================================================
// 📋 LOAD CURRENT CLIENT LIST
// ============================================================================

$clients = OAuthClientManager::listClients($selectedPartnerID);
$csrfToken = SecurityUtils::generateCSRFToken();

/** Human-readable scope descriptions for the checkbox list. */
$scopeCatalogue = [
    'profile'         => 'Profile — name, avatar, locale',
    'email'           => 'Email — address + verified status',
    'offline_access'  => 'Offline access — issue a refresh token',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>OAuth Clients - <?php echo htmlspecialchars($partnerRow['partnerName'], ENT_QUOTES, 'UTF-8'); ?> | SIGNula</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">
    <style>
        body { background-color: #f8f9fa; }
        .page-header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 2rem; border-radius: 10px; margin-bottom: 2rem; }
        .client-card { border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: all 0.2s; }
        .client-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .client-card.inactive { opacity: 0.6; border: 2px dashed #dee2e6; }
        .secret-reveal { background: #e7f3ff; border: 2px dashed #0066cc; padding: 1.5rem; border-radius: 10px; }
        .mono { font-family: 'Courier New', monospace; word-break: break-all; }
        .redirect-uri-list { list-style: none; padding: 0; margin: 0; }
        .redirect-uri-list li { font-family: 'Courier New', monospace; font-size: 0.85rem; padding: 0.15rem 0; word-break: break-all; }
    </style>
</head>
<body>
    <div class="container py-4">

        <div class="page-header">
            <a href="/partners/dashboard.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
            </a>
            <h1><i class="fas fa-id-badge me-2"></i>OAuth / OIDC Clients</h1>
            <p class="mb-0 opacity-75">
                "Sign in with SIGNula.id" app registrations for
                <?php echo htmlspecialchars($partnerRow['partnerName'], ENT_QUOTES, 'UTF-8'); ?>
            </p>
        </div>

        <?php if (count($pickerOptions) > 1): ?>
        <!-- 🏢 Partner picker (no-JS-safe — plain GET form) -->
        <form method="GET" class="mb-3 d-flex align-items-center gap-2">
            <label for="partner" class="fw-bold">Partner:</label>
            <select name="partner" id="partner" class="form-select w-auto" onchange="this.form.submit()">
                <?php foreach ($pickerOptions as $p): ?>
                <option value="<?php echo (int) $p['partnerID']; ?>" <?php echo (int) $p['partnerID'] === $selectedPartnerID ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($p['partnerName'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-outline-secondary btn-sm">Switch</button></noscript>
        </form>
        <?php endif; ?>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($message['type'], ENT_QUOTES, 'UTF-8'); ?> alert-dismissible">
            <?php echo htmlspecialchars($message['text'], ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($revealSecret): ?>
        <div class="secret-reveal mb-4">
            <h5 class="text-primary"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($revealSecret['label'], ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars($revealSecret['clientName'], ENT_QUOTES, 'UTF-8'); ?></h5>
            <p class="mb-2">This <strong>client_secret</strong> is shown <strong>only once</strong>. Copy it now and store it securely — SIGNula never stores or displays the plaintext again.</p>
            <div class="mb-2">
                <small class="text-muted d-block">client_id</small>
                <div class="mono"><?php echo htmlspecialchars($revealSecret['clientIdentifier'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <small class="text-muted d-block">client_secret</small>
                <div class="input-group">
                    <input type="text" class="form-control mono" value="<?php echo htmlspecialchars($revealSecret['clientSecret'], ENT_QUOTES, 'UTF-8'); ?>" id="newSecretInput" readonly>
                    <button class="btn btn-primary" type="button" onclick="var i=document.getElementById('newSecretInput'); i.select(); document.execCommand('copy');">
                        <i class="fas fa-copy me-2"></i>Copy
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ➕ Register New Client -->
        <div class="card client-card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Register New OAuth Client</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="register">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Client Name</label>
                            <input type="text" name="client_name" class="form-control" placeholder="e.g. iHymns Web App" maxlength="150" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Client Type</label>
                            <select name="client_type" class="form-select">
                                <option value="confidential">Confidential (server-side app — gets a secret)</option>
                                <option value="public">Public (SPA / native / mobile — PKCE only, no secret)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Redirect URIs <small class="text-muted">(one per line — must match EXACTLY at sign-in)</small></label>
                        <textarea name="redirect_uris" class="form-control" rows="3" placeholder="https://app.example.com/auth/callback&#10;com.example.app://oauth/callback" required></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Scopes</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" checked disabled>
                            <label class="form-check-label">openid <small class="text-muted">(always required)</small></label>
                        </div>
                        <?php foreach ($scopeCatalogue as $scopeKey => $scopeDesc): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="scopes[]" value="<?php echo htmlspecialchars($scopeKey, ENT_QUOTES, 'UTF-8'); ?>" id="scope_<?php echo htmlspecialchars($scopeKey, ENT_QUOTES, 'UTF-8'); ?>">
                            <label class="form-check-label" for="scope_<?php echo htmlspecialchars($scopeKey, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($scopeDesc, ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <div class="mt-2">
                            <label class="form-label">Additional scopes <small class="text-muted">(space-separated, optional)</small></label>
                            <input type="text" name="extra_scopes" class="form-control" placeholder="e.g. billing:read">
                        </div>
                    </div>

                    <details class="mb-3">
                        <summary class="mb-2" style="cursor:pointer;">Advanced settings</summary>
                        <div class="row mt-2">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Subject type</label>
                                <select name="subject_type" class="form-select">
                                    <option value="pairwise" selected>Pairwise (per-app opaque user ID — recommended)</option>
                                    <option value="public">Public (shared user ID across all apps)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sector identifier <small class="text-muted">(optional — defaults to the first redirect URI's host)</small></label>
                                <input type="text" name="sector_identifier" class="form-control" placeholder="app.example.com">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Logo URL</label>
                                <input type="url" name="logo_uri" class="form-control" placeholder="https://app.example.com/logo.png">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Client home page</label>
                                <input type="url" name="client_uri" class="form-control" placeholder="https://app.example.com">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Terms of Service URL</label>
                                <input type="url" name="tos_uri" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Privacy Policy URL</label>
                                <input type="url" name="policy_uri" class="form-control">
                            </div>
                            <?php if ($isSuperAdmin): ?>
                            <div class="col-12 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_first_party" value="1" id="is_first_party">
                                    <label class="form-check-label" for="is_first_party">
                                        First-party app <small class="text-muted">(super-admin only — may skip the consent screen)</small>
                                    </label>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </details>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-id-badge me-2"></i>Register Client
                    </button>
                </form>
            </div>
        </div>

        <!-- 📋 Existing Clients -->
        <h4 class="mb-3">Registered Clients</h4>
        <?php if (empty($clients)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>No OAuth clients registered yet. Register your first client above to enable "Sign in with SIGNula.id".
        </div>
        <?php else: ?>
            <?php foreach ($clients as $client): ?>
            <div class="card client-card mb-3 <?php echo $client['isActive'] ? '' : 'inactive'; ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h5 class="mb-1">
                                <i class="fas fa-id-badge me-2 text-primary"></i>
                                <?php echo htmlspecialchars($client['clientName'], ENT_QUOTES, 'UTF-8'); ?>
                            </h5>
                            <span class="badge bg-<?php echo $client['clientType'] === 'confidential' ? 'primary' : 'secondary'; ?>">
                                <?php echo strtoupper(htmlspecialchars($client['clientType'], ENT_QUOTES, 'UTF-8')); ?>
                            </span>
                            <span class="badge bg-<?php echo $client['isActive'] ? 'success' : 'danger'; ?>">
                                <?php echo $client['isActive'] ? 'ACTIVE' : 'DEACTIVATED'; ?>
                            </span>
                            <?php if ($client['isFirstParty']): ?>
                            <span class="badge bg-warning text-dark">FIRST-PARTY</span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if ($client['clientType'] === 'confidential' && $client['isActive']): ?>
                            <form method="POST" onsubmit="return confirm('Rotate this client\'s secret? The old secret stops working immediately.');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="rotate">
                                <input type="hidden" name="client_id" value="<?php echo (int) $client['clientID']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                    <i class="fas fa-rotate me-1"></i>Rotate Secret
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if ($client['isActive']): ?>
                            <form method="POST" onsubmit="return confirm('Deactivate this OAuth client? Existing sign-ins will stop working.');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="deactivate">
                                <input type="hidden" name="client_id" value="<?php echo (int) $client['clientID']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-ban me-1"></i>Deactivate
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <small class="text-muted d-block">client_id</small>
                            <div class="mono"><?php echo htmlspecialchars($client['clientIdentifier'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <small class="text-muted d-block">Scopes</small>
                            <div><?php echo htmlspecialchars($client['allowedScopes'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <small class="text-muted d-block">Redirect URIs</small>
                            <ul class="redirect-uri-list">
                                <?php foreach ($client['redirectUris'] as $uri): ?>
                                <li><?php echo htmlspecialchars($uri, ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="col-md-6 mb-2">
                            <small class="text-muted d-block">Subject type / PKCE</small>
                            <div>
                                <?php echo htmlspecialchars($client['subjectType'], ENT_QUOTES, 'UTF-8'); ?>
                                &middot; PKCE <?php echo $client['pkceRequired'] ? 'required' : 'optional'; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <small class="text-muted">Registered <?php echo date('M j, Y', strtotime($client['createdAt'])); ?></small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>
</body>
</html>
