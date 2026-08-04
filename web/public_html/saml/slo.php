<?php
/**
 * ============================================================================
 * 🚪 SIGNula - SAML 2.0 Identity-Provider: SLO Endpoint  (GET /saml/slo)
 * ============================================================================
 *
 * Purpose: SP-initiated SAML 2.0 Single Logout (G-001 Phase B, #100). v1
 * scope (plan §7 "SLO (v1)"): HTTP-Redirect binding ONLY, in BOTH directions
 * — verifies the inbound `<samlp:LogoutRequest>`'s detached signature (C1)
 * against the SP's registered certificate, terminates the CURRENT SIGNula
 * session via the EXISTING, unmodified {@see Auth::logout()}, and redirects
 * back to the SP's `sloURL` with a matching, SIGNula-signed (C1)
 * `<samlp:LogoutResponse>`.
 *
 * ⚠️ v1 SIMPLIFICATION (documented, not a bug): this is SINGLE-SP logout
 * only — it does not propagate logout to any OTHER SP the user may also
 * have an active SAML session with (that is IdP-initiated multi-SP SLO,
 * explicitly v2; `tblSAMLAssertions.sessionIndex` already records what a
 * future implementation would need). It also does not attempt to match the
 * LogoutRequest's `SessionIndex`/`NameID` against a specific ledger row
 * before terminating the session — it simply ends whichever SIGNula session
 * is active in the browser making this request, which is the correct
 * behaviour for the v1 single-SP case.
 *
 * 🚦 DORMANT BY DEFAULT: gated behind `saml.enabled` (migration 050, seeded
 * '0'). See PRE_LAUNCH_REVIEW.md before ever flipping the switch.
 *
 * URL: /saml/slo (web/public_html/.htaccess's generic clean-URL rewrite).
 * Method: GET only (HTTP-Redirect binding — v1's only supported SLO transport;
 *         see class-level notes in {@see SamlLogoutService}).
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage SAML
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Phase B, #100)
 * @see web/private_html/auth/SamlLogoutService.php (parsing, validation, LogoutResponse building)
 * @see web/private_html/security/SamlRedirectBinding.php (C1 — both directions)
 * ============================================================================
 */

// 🚀 Initialize SIGNula (config.php defines SIGNULA_INIT and loads all classes)
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 🔧 LOCAL HELPERS
// ============================================================================

/**
 * ❌ Render a LOCAL, non-redirectable error page and exit — used when no
 * proven-safe `sloURL` destination exists yet.
 *
 * @param string $error       Short machine-readable error code.
 * @param string $description Human-readable description.
 * @param int    $status      HTTP status code.
 */
function renderSamlSloErrorPage(string $error, string $description, int $status = 400): never
{
    http_response_code($status);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Sign-Out Error - SIGNula</title>
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
            <h1>Sign-Out Error</h1>
            <p class="error-code"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="error-message"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></p>
            <a href="/" class="back-link">&larr; Back to Home</a>
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
// 🔒 THE DORMANT GATE — `saml.enabled` (migration 050, default '0')
// ----------------------------------------------------------------------------
if (!class_exists('SamlMetadataService') || !SamlMetadataService::isSamlEnabled()) {
    renderSamlSloErrorPage('temporarily_unavailable', 'Single sign-out via this application is not currently available.', 404);
}

// ----------------------------------------------------------------------------
// 🚧 Abuse control.
// ----------------------------------------------------------------------------
if (class_exists('SecurityUtils') && !SecurityUtils::checkRateLimit(getClientIP(), 'saml_slo', 30, 60)) {
    renderSamlSloErrorPage('rate_limited', 'Too many requests. Please try again shortly.', 429);
}

// v1: HTTP-Redirect binding ONLY (see class header) — a POST here (or a GET
// with no SAMLRequest) has nothing this endpoint can process.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    renderSamlSloErrorPage('method_not_allowed', 'Only the HTTP-Redirect binding (GET) is currently supported for logout.', 405);
}

$samlRequestB64 = isset($_GET['SAMLRequest']) && is_string($_GET['SAMLRequest']) ? trim($_GET['SAMLRequest']) : '';
if ($samlRequestB64 === '') {
    renderSamlSloErrorPage('invalid_request', 'Missing SAMLRequest parameter.', 400);
}

$xml = SamlAuthnRequestService::decodeRedirectBindingPayload($samlRequestB64);
if ($xml === null) {
    renderSamlSloErrorPage('invalid_request', 'Malformed or oversized SAMLRequest payload.', 400);
}

$dom = SamlAuthnRequestService::loadDom($xml);
if ($dom === null) {
    renderSamlSloErrorPage('invalid_request', 'Malformed LogoutRequest XML.', 400);
}

$parsed = SamlLogoutService::parseLogoutRequest($dom);
if ($parsed === null) {
    renderSamlSloErrorPage('invalid_request', 'Malformed or non-conformant LogoutRequest.', 400);
}

$rawQueryString = (string) ($_SERVER['QUERY_STRING'] ?? '');
$validated = SamlLogoutService::validateLogoutRequest($parsed, $rawQueryString);

$relayState = isset($_GET['RelayState']) && is_string($_GET['RelayState']) ? $_GET['RelayState'] : null;

// 🔑 SIGNula's OWN SAML signing key — used to sign EVERY outbound
//    LogoutResponse (C1), regardless of whether the SP requires it; this is
//    SIGNula acting as the sender now, not verifying anyone else's signature.
$activeKey = SamlKeyManager::getActiveKey();

if (!$validated['ok']) {
    if ($validated['fatal']) {
        // 🚫 No proven-safe sloURL exists — local error ONLY.
        renderSamlSloErrorPage((string) $validated['error'], (string) $validated['errorDescription'], 400);
    }

    // ✅ sloURL IS proven safe past this point.
    $errorXml = SamlLogoutService::buildLogoutResponseXml(
        (string) $validated['requestID'],
        SamlLogoutService::STATUS_REQUESTER,
        (string) $validated['sloUrl']
    );
    $encoded = SamlRedirectBinding::encodeMessage($errorXml);
    $url = SamlRedirectBinding::buildRedirectUrl(
        (string) $validated['sloUrl'],
        'SAMLResponse',
        $encoded,
        $relayState,
        $activeKey['private_pem']
    );
    redirect($url);
}

// ============================================================================
// 🚪 TERMINATE THE CURRENT SIGNULA SESSION (v1: single-SP logout only — see
//    class header for the documented scope).
// ============================================================================
$userID = Auth::getCurrentUserID(); // May be null if the browser had no live SIGNula session at all — logging out is then a harmless no-op.

if (Auth::isAuthenticated()) {
    Auth::logout();
}

ActivityLogger::log(
    $userID,
    'saml_slo_completed',
    'auth',
    'info',
    "SAML Single Logout completed for Service Provider \"{$validated['sp']['displayName']}\"",
    ['spID' => (int) $validated['sp']['spID']]
);

$successXml = SamlLogoutService::buildLogoutResponseXml(
    (string) $validated['requestID'],
    SamlLogoutService::STATUS_SUCCESS,
    (string) $validated['sloUrl']
);
$encoded = SamlRedirectBinding::encodeMessage($successXml);
$url = SamlRedirectBinding::buildRedirectUrl(
    (string) $validated['sloUrl'],
    'SAMLResponse',
    $encoded,
    $relayState,
    $activeKey['private_pem']
);

redirect($url);
