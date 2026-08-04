<?php
/**
 * ============================================================================
 * 🪪 SIGNula - SAML 2.0 Identity-Provider: SSO Endpoint  (GET/POST /saml/sso)
 * ============================================================================
 *
 * Purpose: SP-initiated SAML 2.0 Web Browser SSO (G-001 Phase B, #100) —
 * SIGNula acting as the SAML IdP. Validates an inbound `<samlp:AuthnRequest>`
 * (HTTP-Redirect binding via GET, or HTTP-POST binding via POST), routes the
 * visitor through SIGNula's EXISTING login/MFA if not already authenticated,
 * shows an attribute-release consent screen (or auto-skips it for a
 * first-party SP or a repeat, already-consented sign-in), and delivers a
 * signed `<samlp:Response>` back to the SP's Assertion Consumer Service
 * (ACS) via an auto-submitting HTTP-POST form.
 *
 * 🚦 DORMANT BY DEFAULT: gated behind `saml.enabled` (migration 050, seeded
 * '0'). While disabled, this endpoint 404s — see PRE_LAUNCH_REVIEW.md before
 * ever flipping the switch. NOT production-ready until staging interop +
 * red-team are evidenced on issue #100 (see the plan's Gates 2-3).
 *
 * All request validation + response issuance logic lives in
 * {@see SamlAuthnRequestService} / {@see SamlResponseBuilder} — this file is
 * a THIN controller, kept deliberately so the security-critical logic stays
 * Unit/Integration-testable without a real HTTP request (mirrors
 * `oauth/authorize-idp.php`'s split).
 *
 * URL: /saml/sso (web/public_html/.htaccess's generic clean-URL rewrite maps
 *      this straight to this file).
 * Method: GET (HTTP-Redirect binding: `?SAMLRequest=...&RelayState=...
 *         [&SigAlg=...&Signature=...]`; also used for the post-login
 *         `?resume=<handle>` round trip and the consent-screen display) and
 *         POST (HTTP-POST binding: `SAMLRequest`/`RelayState` form fields;
 *         also used for the consent-screen Allow/Deny decision, carrying
 *         `resume`+`csrf_token`+`decision`).
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage SAML
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Phase B, #100)
 * @see web/private_html/auth/SamlAuthnRequestService.php (intake, parsing, validation, pending-request store)
 * @see web/private_html/auth/SamlResponseBuilder.php (success/error Response building)
 * @see web/public_html/oauth/authorize-idp.php (the thin-controller shape this mirrors)
 * ============================================================================
 */

// 🚀 Initialize SIGNula (config.php defines SIGNULA_INIT and loads all classes)
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 🔧 LOCAL HELPERS
// ============================================================================

/**
 * 🧹 Pull a single request parameter as a trimmed string, guarding against
 * non-scalar input (mirrors `authorizeIdpParam()` in oauth/authorize-idp.php).
 *
 * @param array<string,mixed> $source  $_GET or $_POST.
 * @param string              $key     Parameter name.
 * @param string|null         $default Value to return if absent/non-scalar/empty.
 * @return string|null
 */
function samlSsoParam(array $source, string $key, ?string $default = null): ?string
{
    if (!isset($source[$key]) || !is_scalar($source[$key])) {
        return $default;
    }

    $value = trim((string) $source[$key]);

    return $value !== '' ? $value : $default;
}

/**
 * ❌ Render a LOCAL, non-redirectable/non-postable error page and exit.
 *
 * Used for every "fatal" failure (no proven-safe ACS/SLO destination
 * exists) — mirrors `renderAuthorizeIdpErrorPage()`'s inline error-box style.
 *
 * @param string $error       Short machine-readable error code.
 * @param string $description Human-readable description.
 * @param int    $status      HTTP status code.
 */
function renderSamlErrorPage(string $error, string $description, int $status = 400): never
{
    http_response_code($status);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Sign-In Error - SIGNula</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; background-color: #f5f5f5; }
            .error-box { background: white; padding: 30px; border-radius: 8px; border-left: 4px solid #e74c3c; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            h1 { color: #e74c3c; margin-top: 0; }
            .error-code { font-family: monospace; color: #7f8c8d; font-size: 0.9rem; }
            .error-message { color: #333; margin: 15px 0; }
            .back-link { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; }
            .back-link:hover { background: #2980b9; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h1>Sign-In Error</h1>
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
 * 📤 Render the auto-submitting HTTP-POST-binding delivery form and exit.
 *
 * Per SAML 2.0 Bindings §3.5.4 + this codebase's "no-JS-blocker" house rule:
 * a self-submitting form via a tiny inline script, with a `<noscript>`
 * fallback submit button — the SAML flow completes identically with
 * JavaScript disabled, just with one extra click.
 *
 * @param string      $acsUrl        The PROVEN destination (ACS URL).
 * @param string      $samlResponseB64 Base64-encoded `<samlp:Response>` XML.
 * @param string|null $relayState    Opaque RelayState to echo verbatim, or null.
 */
function renderAutoPostForm(string $acsUrl, string $samlResponseB64, ?string $relayState): never
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Signing you in&hellip; - SIGNula</title>
    </head>
    <body onload="document.forms[0].submit();">
        <noscript>
            <p>JavaScript is disabled — click the button below to continue.</p>
        </noscript>
        <p>Signing you in&hellip;</p>
        <form method="POST" action="<?php echo htmlspecialchars($acsUrl, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="SAMLResponse" value="<?php echo htmlspecialchars($samlResponseB64, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($relayState !== null && $relayState !== ''): ?>
                <input type="hidden" name="RelayState" value="<?php echo htmlspecialchars($relayState, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
            <noscript>
                <button type="submit">Continue</button>
            </noscript>
        </form>
    </body>
    </html>
    <?php
    exit;
}

/**
 * 🤝 Render the attribute-release consent screen (Allow/Deny) and exit.
 * No-JS fallback: a plain POST form with two submit buttons sharing
 * `name="decision"` — mirrors `renderOAuthConsentScreen()`.
 *
 * @param array<string,mixed> $sp        Hydrated SP row.
 * @param string               $resumeHandle The pending request's resume handle.
 * @param string[]             $attributeNames The attribute names about to be released.
 * @param string               $csrfToken SecurityUtils::generateCSRFToken().
 */
function renderSamlConsentScreen(array $sp, string $resumeHandle, array $attributeNames, string $csrfToken): never
{
    $displayName = (string) ($sp['displayName'] ?? 'This application');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="description" content="Authorize an application to access your SIGNula account via SAML">
        <title>Authorize Access - SIGNula</title>
        <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" />
        <link rel="stylesheet" href="/assets/css/main.css">
    </head>
    <body>
    <div class="auth-layout">
        <div class="auth-container">
            <div class="auth-card">
                <div class="auth-card-header">
                    <div class="auth-logo"><i class="fas fa-shield-alt"></i></div>
                    <h1><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p>wants to access your SIGNula ID (via SAML)</p>
                </div>

                <?php if (!empty($attributeNames)): ?>
                <p style="font-weight: 600; margin-bottom: 0.5rem;">
                    This will share the following information:
                </p>
                <ul style="margin-bottom: 1.5rem;">
                    <?php foreach ($attributeNames as $attr): ?>
                        <li><?php echo htmlspecialchars($attr, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <form method="POST" action="/saml/sso">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="resume" value="<?php echo htmlspecialchars($resumeHandle, ENT_QUOTES, 'UTF-8'); ?>">
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

/**
 * ✅ Shared final step — build the signed success Response and deliver it.
 * Called from BOTH the auto-skip-consent path and the explicit-Allow path.
 *
 * @param array  $sp        Hydrated SP row.
 * @param array  $consumed  The consumed tblSAMLAuthnRequests row.
 * @param int    $userID    Authenticated userID.
 */
function issueSamlSuccessResponse(array $sp, array $consumed, int $userID): never
{
    // 🔗 Ties this assertion to the CURRENT SIGNula session for SLO
    //    correlation — falls back to a fresh random value in the
    //    (should-be-unreachable) case no session_id is set despite
    //    Auth::isAuthenticated() having already been checked true.
    $sessionIndex = isset($_SESSION['session_id']) && $_SESSION['session_id'] !== ''
        ? (string) $_SESSION['session_id']
        : bin2hex(random_bytes(16));

    $result = SamlResponseBuilder::buildSuccessResponse(
        $sp,
        $userID,
        (string) $consumed['requestID'],
        (string) $consumed['acsURL'],
        $sessionIndex,
        getClientIP()
    );

    ActivityLogger::log(
        $userID,
        'saml_sso_issued',
        'auth',
        'info',
        "SAML assertion issued to Service Provider \"{$sp['displayName']}\"",
        ['spID' => (int) $sp['spID'], 'assertionID' => $result['assertionID']]
    );

    renderAutoPostForm((string) $consumed['acsURL'], base64_encode($result['xml']), $consumed['relayState'] ?? null);
}

// ============================================================================
// 🚦 MAIN FLOW
// ============================================================================

// ----------------------------------------------------------------------------
// 🔒 THE DORMANT GATE — `saml.enabled` (migration 050, default '0')
// ----------------------------------------------------------------------------
if (!class_exists('SamlMetadataService') || !SamlMetadataService::isSamlEnabled()) {
    renderSamlErrorPage('temporarily_unavailable', 'Single sign-on via this application is not currently available.', 404);
}

// ----------------------------------------------------------------------------
// 🚧 Abuse control — mirrors the login-endpoint rate-limit precedent.
// ----------------------------------------------------------------------------
if (class_exists('SecurityUtils') && !SecurityUtils::checkRateLimit(getClientIP(), 'saml_sso', 30, 60)) {
    renderSamlErrorPage('rate_limited', 'Too many requests. Please try again shortly.', 429);
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$resumeHandle = $isPost
    ? (string) samlSsoParam($_POST, 'resume', '')
    : (string) samlSsoParam($_GET, 'resume', '');

// ============================================================================
// 🔁 RESUME PATH — after the /login?redirect=… round trip, or the consent
//    screen's own POST decision.
// ============================================================================
if ($resumeHandle !== '') {
    if (!Auth::isAuthenticated()) {
        // Still not authenticated (e.g. a stale/bookmarked resume link) —
        // route through login again with the SAME resume target.
        redirect('/login?redirect=' . urlencode('/saml/sso?resume=' . urlencode($resumeHandle)));
    }

    $userID = Auth::getCurrentUserID();

    if ($isPost) {
        // ---- Consent DECISION submitted ----
        if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            renderSamlErrorPage('invalid_request', 'Invalid or expired security token — please try again.');
        }

        $consumed = SamlAuthnRequestService::consumePendingRequest($resumeHandle);
        if ($consumed === null) {
            renderSamlErrorPage('invalid_request', 'This sign-in request has expired or already been used. Please try again from the application you were signing in to.', 400);
        }

        $sp = SamlServiceProviderManager::getBySpID((int) $consumed['spID']);
        if ($sp === null || empty($sp['isActive'])) {
            renderSamlErrorPage('unknown_sp', 'Unknown or inactive SAML Service Provider.', 400);
        }

        $decision = samlSsoParam($_POST, 'decision', '');

        if ($decision !== 'approve') {
            // 🔒 Fail-closed: any non-"approve" value is a denial.
            ActivityLogger::log(
                $userID,
                'saml_consent_denied',
                'auth',
                'info',
                "User denied SAML consent for Service Provider \"{$sp['displayName']}\"",
                ['spID' => (int) $sp['spID']]
            );

            $errResult = SamlResponseBuilder::buildErrorResponse(
                $sp,
                (string) $consumed['acsURL'],
                (string) $consumed['requestID'],
                SamlResponseBuilder::STATUS_REQUESTER,
                'The user denied the authorization request.'
            );
            renderAutoPostForm((string) $consumed['acsURL'], base64_encode($errResult['xml']), $consumed['relayState'] ?? null);
        }

        $attributeNames = is_array($sp['attributeMap'] ?? null) ? array_values($sp['attributeMap']) : [];
        SamlAuthnRequestService::recordConsent($userID, (int) $sp['spID'], $attributeNames, getClientIP());

        issueSamlSuccessResponse($sp, $consumed, $userID);
    }

    // ---- GET — display consent screen, or auto-skip it ----
    $pending = SamlAuthnRequestService::peekPendingRequest($resumeHandle);
    if ($pending === null) {
        renderSamlErrorPage('invalid_request', 'This sign-in request has expired or already been used. Please try again from the application you were signing in to.', 400);
    }

    $sp = SamlServiceProviderManager::getBySpID((int) $pending['spID']);
    if ($sp === null || empty($sp['isActive'])) {
        renderSamlErrorPage('unknown_sp', 'Unknown or inactive SAML Service Provider.', 400);
    }

    $attributeNames = is_array($sp['attributeMap'] ?? null) ? array_values($sp['attributeMap']) : [];
    $hasConsent = SamlAuthnRequestService::hasCoveringConsent($userID, (int) $sp['spID'], $attributeNames);
    $skipConsent = !empty($sp['isFirstParty']) || empty($sp['requireConsent']) || $hasConsent;

    if (!$skipConsent) {
        $csrfToken = SecurityUtils::generateCSRFToken();
        renderSamlConsentScreen($sp, $resumeHandle, $attributeNames, $csrfToken);
    }

    // ✅ Auto-skip — consume now (this IS the terminal decision) and issue.
    $consumed = SamlAuthnRequestService::consumePendingRequest($resumeHandle);
    if ($consumed === null) {
        renderSamlErrorPage('invalid_request', 'This sign-in request has expired or already been used. Please try again from the application you were signing in to.', 400);
    }

    issueSamlSuccessResponse($sp, $consumed, $userID);
}

// ============================================================================
// 🆕 FRESH REQUEST — no resume handle: a brand-new inbound AuthnRequest.
// ============================================================================
$binding = $isPost ? 'post' : 'redirect';
$encodedMessage = $isPost
    ? samlSsoParam($_POST, 'SAMLRequest', '')
    : samlSsoParam($_GET, 'SAMLRequest', '');
$relayState = $isPost
    ? samlSsoParam($_POST, 'RelayState')
    : samlSsoParam($_GET, 'RelayState');

if ($encodedMessage === '' || $encodedMessage === null) {
    renderSamlErrorPage('invalid_request', 'Missing SAMLRequest parameter.', 400);
}

$xml = $binding === 'redirect'
    ? SamlAuthnRequestService::decodeRedirectBindingPayload($encodedMessage)
    : SamlAuthnRequestService::decodePostBindingPayload($encodedMessage);

if ($xml === null) {
    renderSamlErrorPage('invalid_request', 'Malformed or oversized SAMLRequest payload.', 400);
}

$dom = SamlAuthnRequestService::loadDom($xml);
if ($dom === null) {
    renderSamlErrorPage('invalid_request', 'Malformed SAML request XML.', 400);
}

$parsed = SamlAuthnRequestService::parseAuthnRequest($dom);
if ($parsed === null) {
    renderSamlErrorPage('invalid_request', 'Malformed or non-conformant AuthnRequest.', 400);
}

$rawQueryString = $binding === 'redirect' ? (string) ($_SERVER['QUERY_STRING'] ?? '') : '';
$validated = SamlAuthnRequestService::validateAuthnRequest($parsed, $binding, $rawQueryString);

if (!$validated['ok']) {
    if ($validated['fatal']) {
        // 🚫 No proven-safe ACS destination exists — local error ONLY.
        renderSamlErrorPage((string) $validated['error'], (string) $validated['errorDescription'], 400);
    }

    // ✅ ACS IS proven safe past this point — deliver a SIGNED SAML error
    //    Response there instead of a local page.
    $errResult = SamlResponseBuilder::buildErrorResponse(
        $validated['sp'],
        (string) $validated['acsUrl'],
        $validated['requestID'],
        SamlResponseBuilder::STATUS_REQUESTER,
        (string) $validated['errorDescription']
    );
    renderAutoPostForm((string) $validated['acsUrl'], base64_encode($errResult['xml']), $relayState);
}

// ============================================================================
// 🔐 AUTHENTICATION
// ============================================================================
if (!Auth::isAuthenticated()) {
    if ($validated['isPassive']) {
        // 🚫 IsPassive forbids ANY interactive prompt — respond immediately
        //    with the spec-correct nested NoPassive status (SAML Core §3.2.2.2).
        $errResult = SamlResponseBuilder::buildErrorResponse(
            $validated['sp'],
            (string) $validated['acsUrl'],
            $validated['requestID'],
            SamlResponseBuilder::STATUS_RESPONDER,
            'Passive authentication was requested but the user is not already signed in.',
            SamlResponseBuilder::STATUS_NO_PASSIVE
        );
        renderAutoPostForm((string) $validated['acsUrl'], base64_encode($errResult['xml']), $relayState);
    }

    $handle = SamlAuthnRequestService::issuePendingRequest($validated, (string) ($relayState ?? ''), getClientIP());
    redirect('/login?redirect=' . urlencode('/saml/sso?resume=' . urlencode($handle)));
}

// ---- Already authenticated ----
if ($validated['forceAuthn']) {
    // 🔁 ForceAuthn (SAML Core §3.4.1) demands FRESH credential entry for
    //    THIS SSO transaction. SIGNula's login.php auto-redirects an
    //    already-authenticated visitor straight back to `?redirect=…`
    //    WITHOUT re-prompting (verified — see login.php's "redirect if
    //    already logged in" guard), so merely sending the visitor there
    //    would not honour ForceAuthn at all. Per this task's explicit
    //    instruction NOT to modify login.php/Auth.php for SAML, the only
    //    available lever is ending the CURRENT session via the EXISTING,
    //    unmodified `Auth::logout()` before redirecting — a documented v1
    //    trade-off (it ends the user's whole SIGNula session, not just
    //    this SAML transaction's notion of "freshness"). Revisit if/when a
    //    more granular "force re-auth for this flow only" primitive exists.
    Auth::logout();
    $handle = SamlAuthnRequestService::issuePendingRequest($validated, (string) ($relayState ?? ''), getClientIP());
    redirect('/login?redirect=' . urlencode('/saml/sso?resume=' . urlencode($handle)));
}

// 🔁 Unify with the resume-path logic above (single source of truth for
//    consent-skip + issuance) rather than duplicating it here.
$handle = SamlAuthnRequestService::issuePendingRequest($validated, (string) ($relayState ?? ''), getClientIP());
redirect('/saml/sso?resume=' . urlencode($handle));
