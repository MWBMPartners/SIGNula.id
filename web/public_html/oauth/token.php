<?php
/**
 * ============================================================================
 * 🪪 SIGNula - OAuth2/OIDC Provider: Token Endpoint (G-001 Stage A3)
 * ============================================================================
 *
 * Purpose: SIGNula acting as the OAuth2/OIDC PROVIDER ("Sign in with
 * SIGNula.id") — exchanges a Stage A2 authorization code for an access token
 * + OIDC id_token (+ refresh token), per RFC 6749 §4.1.3 / OIDC Core §3.1.3.
 *
 * ⚠️ Unlike `/oauth/authorize-idp`, this endpoint is a RAW RFC 6749 JSON API —
 * it is called by RP BACKENDS (or, for a public client, the RP's own
 * front-end), not rendered in a browser. It therefore does NOT go through
 * this project's `api/` envelope (BaseController/Response) — the response
 * shape is dictated by RFC 6749 §5, not this codebase's house JSON envelope.
 *
 * URL: /oauth/token   (web/public_html/.htaccess's generic clean-URL rewrite —
 *      `RewriteCond %{REQUEST_FILENAME}.php -f` + `RewriteRule ^(.+?)/?$ $1.php
 *      [L]` — maps this path straight to this file, exactly like the existing
 *      `/oauth/authorize-idp` → `oauth/authorize-idp.php` mapping.)
 * Method: POST only (RFC 6749 §3.2 "The client MUST use the HTTP POST
 *         method"). Any other method → 405.
 *
 * Request (application/x-www-form-urlencoded body, RFC 6749 §4.1.3):
 *   - grant_type    (required) Only "authorization_code" is currently wired.
 *   - code          (required) The Stage A2 single-use authorization code.
 *   - redirect_uri  (required) Must byte-exact match the code's bound value.
 *   - code_verifier (required*) PKCE verifier — *required whenever the code
 *                                carries a code_challenge.
 *   - client_id / client_secret — via HTTP Basic (`Authorization: Basic
 *                                  base64(client_id:client_secret)`) OR these
 *                                  two body fields (RFC 6749 §2.3.1). Basic is
 *                                  tried first; body fields are the fallback.
 *
 * All request validation + code redemption + token/id_token minting logic
 * lives in OAuthTokenService (web/private_html/auth/OAuthTokenService.php) —
 * this file is a THIN controller: parse request -> call the service -> emit
 * the RFC 6749 JSON response. Kept deliberately thin so the security-critical
 * logic is Integration-testable without a real HTTP request (mirrors the
 * OAuthAuthorizeService/authorize-idp.php split from Stage A2).
 *
 * @package    SIGNula
 * @subpackage OAuth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A3)
 * @see web/private_html/auth/OAuthTokenService.php (validation + redemption + minting)
 * @see web/private_html/auth/OAuthClientManager.php (client registration, Stage A1)
 * @see web/private_html/auth/OAuthAuthorizeService.php (issues the code this stage consumes, Stage A2)
 * @see web/private_html/security/TokenService.php (issueForClient() — access + refresh minting)
 * @see web/private_html/security/Jwt.php (signIdToken() — the OIDC id_token)
 * @see .dev-team/specs/G-001.md §4, §5
 * ============================================================================
 */

// 🚀 Initialize SIGNula (config.php defines SIGNULA_INIT and loads all classes)
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 📤 RESPONSE HEADERS — set BEFORE any output, on every path (success + error)
// ============================================================================
// RFC 6749 §5.1: "The parameters are included in the entity-body of the HTTP
// response using the "application/json" media type ... and MUST include the
// HTTP "Cache-Control" response header field with a value of "no-store" ...
// and the "Pragma" response header field with a value of "no-cache"."
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// ============================================================================
// 🔧 LOCAL HELPERS
// ============================================================================

/**
 * 🧹 Pull a single POST parameter as a trimmed string, guarding against
 * non-scalar input (e.g. a crafted `code[]=x` array parameter) being silently
 * coerced by a bare (string) cast. Mirrors authorizeIdpParam() in
 * oauth/authorize-idp.php.
 *
 * @param string $key Parameter name.
 * @return string '' if absent/non-scalar/empty.
 */
function tokenParam(string $key): string
{
    if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
        return '';
    }

    return trim((string) $_POST[$key]);
}

/**
 * 🔐 Resolve the caller's client credentials: HTTP Basic first (RFC 6749
 * §2.3.1's preferred mechanism), falling back to request-body `client_id` /
 * `client_secret` fields. Never trusts BOTH at once — Basic, when present and
 * well-formed, always wins (a body-supplied client_id alongside a Basic
 * header is simply ignored, not merged, to avoid any ambiguity about which
 * identity is actually authenticating).
 *
 * @return array{method:string, client_id:string, client_secret:?string}
 */
function resolveClientAuth(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // 🩹 Some Apache/PHP-FPM configs strip the Authorization header from
    //    $_SERVER entirely; getallheaders() (when available) is the common
    //    fallback for that gotcha.
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    if ($header !== '' && stripos($header, 'Basic ') === 0) {
        $decoded = base64_decode(trim(substr($header, 6)), true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$id, $secret] = explode(':', $decoded, 2);
            return ['method' => 'basic', 'client_id' => $id, 'client_secret' => $secret];
        }

        // Malformed Basic header (bad base64 / no colon) — still tagged
        // 'basic' so the resulting 401 carries WWW-Authenticate: Basic.
        return ['method' => 'basic', 'client_id' => '', 'client_secret' => null];
    }

    // 📝 Body-based client authentication (RFC 6749 §2.3.1).
    return [
        'method'        => 'body',
        'client_id'     => tokenParam('client_id'),
        'client_secret' => isset($_POST['client_secret']) && is_scalar($_POST['client_secret'])
            ? (string) $_POST['client_secret']
            : null,
    ];
}

/**
 * ❌ Emit a JSON error body with the given status + optional extra headers,
 * and exit. Used for the ONE local (non-OAuthTokenService) failure this
 * controller itself detects: an unsupported HTTP method.
 *
 * @param int    $status  HTTP status code.
 * @param string $error   OAuth error code.
 * @param string $description Human-readable description.
 * @param array<string,string> $headers Extra headers to send.
 */
function emitTokenError(int $status, string $error, string $description, array $headers = []): never
{
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode(['error' => $error, 'error_description' => $description], JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================================
// 🚦 MAIN FLOW
// ============================================================================

// 🔒 RFC 6749 §3.2: the token endpoint is POST-only.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    emitTokenError(405, 'invalid_request', 'Only POST is supported.', ['Allow' => 'POST']);
}

$clientAuth = resolveClientAuth();

$params = [
    'grant_type'    => tokenParam('grant_type'),
    'code'          => tokenParam('code'),
    'redirect_uri'  => tokenParam('redirect_uri'),
    'code_verifier' => tokenParam('code_verifier'),
];

// 🎯 All validation, redemption, and minting logic lives in the service — this
// controller only shapes the HTTP response from its result.
$result = OAuthTokenService::handleTokenRequest($params, $clientAuth);

http_response_code($result['status']);
foreach (($result['headers'] ?? []) as $name => $value) {
    header($name . ': ' . $value);
}
echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
exit;
