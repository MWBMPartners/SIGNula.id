<?php
/**
 * ============================================================================
 * 🪪 SIGNula - OAuth2/OIDC Provider: Authorization Endpoint (G-001 Stage A2)
 * ============================================================================
 *
 * Purpose: SIGNula acting as the OAuth2/OIDC PROVIDER ("Sign in with
 * SIGNula.id") — validates an authorization request from a Relying-Party
 * (RP) client, shows the user a consent screen (or auto-skips it on a
 * repeat, already-consented sign-in), and issues a single-use, PKCE-bound
 * authorization code back to the RP's redirect_uri.
 *
 * ⚠️ DISTINCT FROM `web/public_html/oauth/authorize.php` — that file is
 * SIGNula acting as a Relying Party CONSUMING Google/Microsoft/etc (email
 * delegation + "sign in with X" account linking). THIS file is the OPPOSITE
 * direction: SIGNula is the identity provider being consumed BY other apps.
 * Do not confuse the two, and do not merge them — see
 * .dev-team/specs/G-001-integration-plan.md §7.
 *
 * PHP Version: 8.3+
 *
 * URL: /oauth/authorize-idp   (web/public_html/.htaccess's generic clean-URL
 *      rewrite — `RewriteCond %{REQUEST_FILENAME}.php -f` +
 *      `RewriteRule ^(.+?)/?$ $1.php [L]` — maps this path straight to this
 *      file; no dedicated rewrite rule needed, exactly like the existing
 *      `/oauth/authorize` → `oauth/authorize.php` mapping.)
 * Method: GET  (validate the request + show consent, or auto-issue a code if
 *               the user already consented) and POST (submit the user's
 *               Allow/Deny decision from the consent screen).
 *
 * Query/body parameters (RFC 6749 §4.1.1 + RFC 7636 + OIDC Core §3.1.2.1):
 *   - client_id             (required) Public client_id.
 *   - redirect_uri          (required) Must byte-exact match a registered URI.
 *   - response_type         (required) Must be "code".
 *   - scope                 (required) Space-delimited; must include "openid".
 *   - state                 (optional) Opaque RP value, echoed back verbatim.
 *   - code_challenge        (required*) PKCE challenge — *required for public/
 *                                       pkceRequired clients, or when the
 *                                       oidc.require_pkce policy is on (default).
 *   - code_challenge_method (required if code_challenge present) "S256" or "plain".
 *   - nonce                 (optional) Echoed into the future id_token (Stage A3).
 *   - prompt                (optional) "consent" forces the consent screen even
 *                                      if a covering consent already exists.
 *
 * All request validation + code issuance logic lives in
 * OAuthAuthorizeService (web/private_html/auth/OAuthAuthorizeService.php) —
 * this file is a THIN controller: parse request -> call the service -> render
 * HTML or redirect. Kept deliberately thin so the security-critical logic is
 * Unit/Integration-testable without a real HTTP request.
 *
 * @package    SIGNula
 * @subpackage OAuth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A2)
 * @see web/private_html/auth/OAuthAuthorizeService.php (validation + code issuance)
 * @see web/private_html/auth/OAuthClientManager.php (client registration, Stage A1)
 * @see .dev-team/specs/G-001.md §4, §5
 * @see .dev-team/specs/G-001-integration-plan.md §2 "A2"
 * ============================================================================
 */

// 🚀 Initialize SIGNula (config.php defines SIGNULA_INIT and loads all classes)
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 🔧 LOCAL HELPERS
// ============================================================================

/**
 * 🧹 Pull a single request parameter as a trimmed string, guarding against
 * non-scalar input (e.g. a crafted `client_id[]=x` array parameter) being
 * silently coerced by a bare (string) cast.
 *
 * @param array<string,mixed> $source  $_GET or $_POST.
 * @param string              $key     Parameter name.
 * @param string|null         $default Value to return if absent/non-scalar/empty.
 * @return string|null
 */
function authorizeIdpParam(array $source, string $key, ?string $default = null): ?string
{
    if (!isset($source[$key]) || !is_scalar($source[$key])) {
        return $default;
    }

    $value = trim((string) $source[$key]);

    return $value !== '' ? $value : $default;
}

/**
 * ❌ Render a LOCAL, non-redirectable error page (fully escaped) and exit.
 *
 * Used ONLY for the two "fatal" failure modes (unknown/inactive client_id,
 * or a redirect_uri that failed the exact-match allowlist) where we do NOT
 * yet have a proven-safe redirect target — mirrors the inline error-box
 * style already used by `oauth/authorize.php`.
 *
 * @param string $error       OAuth error code (e.g. "invalid_client").
 * @param string $description Human-readable description.
 * @param int    $status      HTTP status code (default 400 — the existing
 *                             "fatal validation failure" callers). The G-001
 *                             red-team F-05 provider-disabled gate passes 404
 *                             instead, so a disabled provider does not even
 *                             confirm this endpoint exists.
 */
function renderAuthorizeIdpErrorPage(string $error, string $description, int $status = 400): never
{
    http_response_code($status);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Authorization Error - SIGNula</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                max-width: 600px;
                margin: 50px auto;
                padding: 20px;
                background-color: #f5f5f5;
            }
            .error-box {
                background: white;
                padding: 30px;
                border-radius: 8px;
                border-left: 4px solid #e74c3c;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            h1 { color: #e74c3c; margin-top: 0; }
            .error-code { font-family: monospace; color: #7f8c8d; font-size: 0.9rem; }
            .error-message { color: #333; margin: 15px 0; }
            .back-link {
                display: inline-block;
                margin-top: 20px;
                padding: 10px 20px;
                background: #3498db;
                color: white;
                text-decoration: none;
                border-radius: 4px;
            }
            .back-link:hover { background: #2980b9; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h1>Authorization Error</h1>
            <p class="error-code"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="error-message"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></p>
            <a href="/" class="back-link">&larr; Back to Home</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * 🤝 Render the consent screen (Allow/Deny) and exit. No-JS fallback: a
 * plain POST form with two submit buttons sharing `name="decision"`
 * (value="approve"/"deny") — works identically with JavaScript disabled.
 *
 * @param array<string,mixed> $client      Hydrated client row (OAuthClientManager::getClient()).
 * @param array<string,mixed> $validated   The `ok === true` result of
 *                                         OAuthAuthorizeService::validateAuthorizeRequest().
 * @param array<string,mixed> $currentUser Auth::getCurrentUser() result.
 * @param string              $csrfToken   SecurityUtils::generateCSRFToken().
 */
function renderOAuthConsentScreen(array $client, array $validated, array $currentUser, string $csrfToken): never
{
    $clientName  = (string) ($client['clientName'] ?? 'This application');
    $logoUri     = $client['logoURI'] ?? null;
    $scopes      = OAuthAuthorizeService::scopeLabels((string) $validated['scope']);
    $displayName = (string) ($currentUser['displayName'] ?? $currentUser['username'] ?? $currentUser['email'] ?? 'your account');
    $userEmail   = (string) ($currentUser['email'] ?? '');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="description" content="Authorize an application to access your SIGNula account">
        <title>Authorize Access - SIGNula</title>

        <!-- 🎨 Favicon -->
        <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">

        <!-- 🎨 Bootstrap CSS -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

        <!-- 🎨 Font Awesome -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" />

        <!-- 🎨 Custom CSS (reuses the .auth-layout / .auth-card / .btn styles login.php already relies on) -->
        <link rel="stylesheet" href="/assets/css/main.css">
    </head>
    <body>

    <div class="auth-layout">
        <div class="auth-container">
            <div class="auth-card">
                <!-- 🏠 Client identity -->
                <div class="auth-card-header">
                    <div class="auth-logo">
                        <?php if (is_string($logoUri) && $logoUri !== ''): ?>
                            <img src="<?php echo htmlspecialchars($logoUri, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 48px; height: 48px; border-radius: 8px; object-fit: contain;">
                        <?php else: ?>
                            <!-- 🛡️ Neutral placeholder when the client has no registered logo -->
                            <i class="fas fa-shield-alt"></i>
                        <?php endif; ?>
                    </div>
                    <h1><?php echo htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p>wants to access your SIGNula ID</p>
                </div>

                <!-- 👤 Signed-in-as identity -->
                <p style="text-align: center; color: var(--text-muted, #6c757d); margin-bottom: 1.5rem;">
                    Signed in as
                    <strong><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ($userEmail !== ''): ?>
                        (<?php echo htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?>)
                    <?php endif; ?>
                </p>

                <!-- 📋 Requested scopes, plain-language -->
                <p style="font-weight: 600; margin-bottom: 0.5rem;">
                    This will allow <?php echo htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8'); ?> to:
                </p>
                <ul style="margin-bottom: 1.5rem;">
                    <?php foreach ($scopes as $scopeItem): ?>
                        <li><?php echo htmlspecialchars($scopeItem['label'], ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>

                <!-- 📝 Consent decision form (no-JS fallback: plain POST, two submit buttons) -->
                <form method="POST" action="/oauth/authorize-idp">
                    <!-- 🛡️ CSRF token -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                    <!-- 🔁 Carry the VALIDATED request forward — re-validated in full on POST, never trusted as-is -->
                    <input type="hidden" name="client_id" value="<?php echo htmlspecialchars((string) $client['clientIdentifier'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="redirect_uri" value="<?php echo htmlspecialchars((string) $validated['redirectUri'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="response_type" value="code">
                    <input type="hidden" name="scope" value="<?php echo htmlspecialchars((string) $validated['scope'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($validated['state'] !== null): ?>
                        <input type="hidden" name="state" value="<?php echo htmlspecialchars((string) $validated['state'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <?php if ($validated['codeChallenge'] !== null): ?>
                        <input type="hidden" name="code_challenge" value="<?php echo htmlspecialchars((string) $validated['codeChallenge'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="code_challenge_method" value="<?php echo htmlspecialchars((string) $validated['codeChallengeMethod'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <?php if ($validated['nonce'] !== null): ?>
                        <input type="hidden" name="nonce" value="<?php echo htmlspecialchars((string) $validated['nonce'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>

                    <div class="d-flex" style="gap: 0.75rem; margin-top: 1.5rem;">
                        <button type="submit" name="decision" value="deny" class="btn btn-secondary btn-block" style="min-height: 44px;">
                            <i class="fas fa-times"></i> Deny
                        </button>
                        <button type="submit" name="decision" value="approve" class="btn btn-primary btn-block" style="min-height: 44px;">
                            <i class="fas fa-check"></i> Allow
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    </body>
    </html>
    <?php
    exit;
}

// ============================================================================
// 🚦 MAIN FLOW
// ============================================================================

// ----------------------------------------------------------------------------
// 🔒 G-001 red-team F-05 fix — the `oidc.enabled` master switch
// ----------------------------------------------------------------------------
// Migration 031 seeded `oidc.enabled = '0'` (default OFF until an operator
// opts in) but nothing ever enforced it — see oauth/token.php's own "F-05
// fix" comment for the full rationale. This is the FIRST check in the whole
// flow — before even parsing client_id/redirect_uri — so a disabled provider
// never does any DB-bound work at all. Uses the LOCAL, non-redirectable error
// page (never a redirect — no redirect_uri has been proven safe yet at this
// point) at 404, so a disabled provider does not even confirm this endpoint
// exists.
if (!class_exists('OidcDiscoveryService') || !OidcDiscoveryService::isProviderEnabled()) {
    renderAuthorizeIdpErrorPage(
        'temporarily_unavailable',
        'Sign-in via this application is not currently available.',
        404
    );
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$source = $isPost ? $_POST : $_GET;

// 📝 Gather + defensively coerce every request parameter. Both GET and POST
// funnel through the SAME validation below — the POST path re-derives
// everything from the posted hidden fields rather than trusting anything
// carried over from an earlier GET (STEP 8 of the build contract: "never
// trust POST").
$params = [
    'client_id'             => authorizeIdpParam($source, 'client_id', ''),
    'redirect_uri'          => authorizeIdpParam($source, 'redirect_uri', ''),
    'response_type'         => authorizeIdpParam($source, 'response_type', ''),
    'scope'                 => authorizeIdpParam($source, 'scope', ''),
    'state'                 => authorizeIdpParam($source, 'state'),
    'code_challenge'        => authorizeIdpParam($source, 'code_challenge', ''),
    'code_challenge_method' => authorizeIdpParam($source, 'code_challenge_method', ''),
    'nonce'                 => authorizeIdpParam($source, 'nonce'),
];

// `prompt` only ever matters for the GET auto-skip decision below — it is
// NOT part of the POST hidden-field contract.
$prompt = authorizeIdpParam($_GET, 'prompt', '');

// 🔍 Resolve the client FRESH every time (GET and POST alike) — the POST path
// re-fetches from the POSTed client_id rather than trusting anything about a
// previous GET, so a tampered hidden field can never bypass re-validation.
$client = $params['client_id'] !== ''
    ? OAuthClientManager::getClient((string) $params['client_id'])
    : null;

// 🛡️ Validate the ENTIRE request (client, redirect_uri, response_type, scope,
// PKCE) — see OAuthAuthorizeService for the full rule set + the fatal vs.
// redirectable-error distinction.
$validated = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

if (!$validated['ok']) {
    if ($validated['fatal']) {
        // 🚫 No proven-safe redirect target exists (unknown/inactive client,
        //    or redirect_uri failed the exact-match allowlist) — render a
        //    LOCAL error page. NEVER redirect here (open-redirect /
        //    covert-redirect defence).
        renderAuthorizeIdpErrorPage((string) $validated['error'], (string) $validated['errorDescription']);
    }

    // ✅ redirect_uri IS proven safe past this point — 302 the error back to
    //    the RP with the echoed state (RFC 6749 §4.1.2.1).
    redirect(OAuthAuthorizeService::buildRedirectUrl((string) $validated['redirectUri'], [
        'error'             => $validated['error'],
        'error_description' => $validated['errorDescription'],
        'state'             => $validated['state'],
    ]));
}

// ============================================================================
// 🔐 AUTHENTICATION — never show consent to an anonymous user
// ============================================================================
if (!Auth::isAuthenticated()) {
    // 🔁 Return-after-login: mirrors the EXISTING, working pattern already
    // used by `oauth/authorize.php` (`/login?redirect=<relative-URI>`). This
    // is honoured by BOTH of login.php's success paths:
    //   - Non-MFA: login.php reads $_GET['redirect'] through
    //     sanitizeRedirectUrl(), which REJECTS absolute (scheme://) URLs — so
    //     we deliberately pass a *relative* path+query (never scheme+host).
    //   - MFA required: Auth::login() independently copies that SAME
    //     $_GET['redirect'] into $_SESSION['redirect_after_login'], which
    //     mfa/verify.php reads back verbatim after a successful MFA check.
    // Auth::requireAuth()'s default (getCurrentURL(), an ABSOLUTE URL) is
    // deliberately NOT used here — it would fail sanitizeRedirectUrl() on the
    // non-MFA path and silently fall back to /dashboard, losing the return trip.
    if ($isPost) {
        // REQUEST_URI on a POST carries no query string — rebuild the
        // GET-equivalent authorize-idp URL from the (already re-validated)
        // POSTed fields so the user lands back on THIS request after login.
        $returnTo = '/oauth/authorize-idp?' . http_build_query(array_filter(
            [
                'client_id'             => $params['client_id'],
                'redirect_uri'          => $params['redirect_uri'],
                'response_type'         => 'code',
                'scope'                 => $params['scope'],
                'state'                 => $params['state'],
                'code_challenge'        => $params['code_challenge'],
                'code_challenge_method' => $params['code_challenge_method'],
                'nonce'                 => $params['nonce'],
            ],
            static fn(mixed $value): bool => $value !== null && $value !== ''
        ));
    } else {
        $returnTo = $_SERVER['REQUEST_URI'];
    }

    redirect('/login?redirect=' . urlencode($returnTo));
}

$userID   = Auth::getCurrentUserID();
$clientID = (int) $client['clientID'];

// ============================================================================
// 🤝 CONSENT DECISION (POST) / AUTO-SKIP CHECK (GET)
// ============================================================================
if ($isPost) {
    // 🛡️ CSRF — verify BEFORE acting on the decision.
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        renderAuthorizeIdpErrorPage('invalid_request', 'Invalid or expired security token — please try again.');
    }

    $decision = authorizeIdpParam($_POST, 'decision', '');

    if ($decision !== 'approve') {
        // 🔒 Fail-closed: ANY non-"approve" value (explicit Deny, a tampered
        //    value, or a missing field) is treated as a denial — a code is
        //    NEVER issued unless the user explicitly clicked Allow.
        ActivityLogger::log(
            $userID,
            'oauth_consent_denied',
            'auth',
            'info',
            "User denied OAuth consent for client \"{$client['clientName']}\"",
            ['clientID' => $clientID, 'scope' => $validated['scope']]
        );

        redirect(OAuthAuthorizeService::buildRedirectUrl((string) $validated['redirectUri'], [
            'error'             => 'access_denied',
            'error_description' => 'The user denied the authorization request.',
            'state'             => $validated['state'],
        ]));
    }

    // decision === 'approve' — fall through to code issuance below.
} else {
    // 📋 GET — auto-skip the consent screen if a covering, non-revoked
    //    consent already exists AND the RP did not force `prompt=consent`.
    $hasConsent   = OAuthAuthorizeService::hasCoveringConsent($userID, $clientID, (string) $validated['scope']);
    $forceConsent = $prompt === 'consent';

    if (!$hasConsent || $forceConsent) {
        $csrfToken   = SecurityUtils::generateCSRFToken();
        $currentUser = Auth::getCurrentUser() ?? [];
        renderOAuthConsentScreen($client, $validated, $currentUser, $csrfToken);
    }

    // ✅ Covering consent found and not force-refreshed — fall through to
    //    code issuance below without ever showing the screen.
}

// ============================================================================
// 🎫 ISSUE THE AUTHORIZATION CODE (approve path OR auto-skip path)
// ============================================================================
$issued = OAuthAuthorizeService::issueAuthorizationCode($client, $userID, $validated, getClientIP());

ActivityLogger::log(
    $userID,
    'oauth_authcode_issued',
    'auth',
    'info',
    "Authorization code issued to client \"{$client['clientName']}\"",
    ['clientID' => $clientID, 'scope' => $validated['scope']]
);

redirect(OAuthAuthorizeService::buildRedirectUrl((string) $validated['redirectUri'], [
    'code'  => $issued['code'],
    'state' => $validated['state'],
]));
