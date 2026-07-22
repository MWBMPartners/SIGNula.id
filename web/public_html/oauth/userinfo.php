<?php
/**
 * ============================================================================
 * 🪪 SIGNula - OAuth2/OIDC Provider: UserInfo Endpoint (G-001 Stage A4)
 * ============================================================================
 *
 * Purpose: SIGNula acting as the OAuth2/OIDC PROVIDER ("Sign in with
 * SIGNula.id") — OIDC Core §5.3's UserInfo Endpoint. A Relying Party presents
 * the access token it obtained from `/oauth/token` (Stage A3) and receives
 * back the identity claims covered by that token's GRANTED scope. NEVER more
 * than that — see OAuthUserInfoService's class doc for the claim-gating rule.
 *
 * ⚠️ This is a RAW OIDC JSON API — called by RP backends/front-ends, not
 * rendered in a browser — so, like `/oauth/token`, it does NOT go through this
 * project's `api/` envelope (BaseController/Response); the response shape is
 * dictated by OIDC Core §5.3.2, not this codebase's house JSON envelope.
 *
 * URL: /oauth/userinfo   (web/public_html/.htaccess's generic clean-URL
 *      rewrite — `RewriteCond %{REQUEST_FILENAME}.php -f` + `RewriteRule
 *      ^(.+?)/?$ $1.php [L]` — maps this path straight to this file, exactly
 *      like the existing `/oauth/token` → `oauth/token.php` mapping.)
 * Method: GET and POST (OIDC Core §5.3.1 permits either).
 *
 * Auth: `Authorization: Bearer <access_token>` (primary). A body/query
 * `access_token` parameter is accepted as a fallback (RFC 6750 §2.2/§2.3),
 * but the header always wins when present.
 *
 * All auth + scope-gating logic lives in OAuthUserInfoService
 * (web/private_html/auth/OAuthUserInfoService.php) — this file is a THIN
 * controller: extract the Bearer token -> call the service -> emit JSON.
 * Kept deliberately thin so the security-critical logic is Integration-
 * testable without a real HTTP request (mirrors the OAuthTokenService/
 * token.php split from Stage A3).
 *
 * @package    SIGNula
 * @subpackage OAuth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A4)
 * @see web/private_html/auth/OAuthUserInfoService.php (auth + claim-gating logic)
 * @see web/private_html/auth/OAuthClientManager.php   (Stage A1 — client registration, computeSubject())
 * @see web/private_html/security/TokenService.php      (G-003 — verifyAccessToken())
 * @see .dev-team/specs/G-001.md §4, §5
 * ============================================================================
 */

// 🚀 Initialize SIGNula (config.php defines SIGNULA_INIT and loads all classes)
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 📤 RESPONSE HEADERS — set BEFORE any output, on every path (success + error)
// ============================================================================
// UserInfo responses carry identity claims and must never be cached by any
// intermediary (mirrors the G-003 token responses' no-store policy).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// ============================================================================
// 🔒 G-001 red-team F-05 fix — the `oidc.enabled` master switch
// ============================================================================
// See oauth/token.php's own "F-05 fix" comment for the full rationale. This
// endpoint is refused BEFORE it ever looks at the presented Bearer token.
if (!class_exists('OidcDiscoveryService') || !OidcDiscoveryService::isProviderEnabled()) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found'], JSON_UNESCAPED_SLASHES);
    exit;
}

// 🔒 OIDC Core §5.3.1 permits GET or POST; anything else is not meaningful here.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'Only GET and POST are supported.'], JSON_UNESCAPED_SLASHES);
    exit;
}

// 🔎 Read the Authorization header defensively — some Apache/PHP-FPM configs
//    strip it from $_SERVER entirely; getallheaders() is the common fallback
//    (mirrors resolveClientAuth() in oauth/token.php).
$authorizationHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authorizationHeader === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) {
            $authorizationHeader = (string) $value;
            break;
        }
    }
}

$bearerToken = OAuthUserInfoService::extractBearerToken(
    $authorizationHeader !== '' ? $authorizationHeader : null,
    $_POST,
    $_GET
);

// 🎯 All authentication, claim-gating, and error-shape logic lives in the
// service — this controller only shapes the HTTP response from its result.
$result = OAuthUserInfoService::handleUserInfoRequest($bearerToken);

http_response_code($result['status']);
foreach (($result['headers'] ?? []) as $name => $value) {
    header($name . ': ' . $value);
}
echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
exit;
