<?php
/**
 * ============================================================================
 * 🪪 SIGNula - OAuth2/OIDC Provider: Revocation Endpoint (G-001 Stage A4)
 * ============================================================================
 *
 * Purpose: SIGNula acting as the OAuth2/OIDC PROVIDER ("Sign in with
 * SIGNula.id") — RFC 7009 OAuth 2.0 Token Revocation. Lets an authenticated
 * RP proactively kill a refresh token's rotation family, or denylist a
 * still-live access token's jti, before its natural expiry.
 *
 * ⚠️ Like `/oauth/token`, this is a RAW RFC 7009 JSON API called by RP
 * backends — it does NOT go through this project's `api/` envelope
 * (BaseController/Response).
 *
 * URL: /oauth/revoke   (web/public_html/.htaccess's generic clean-URL rewrite
 *      — same mapping mechanism as `/oauth/token` → `oauth/token.php`.)
 * Method: POST only (RFC 7009 §2 — the client MUST use the HTTP POST method).
 *
 * Request (application/x-www-form-urlencoded body, RFC 7009 §2.1):
 *   - token           (required) The refresh token OR access token to revoke.
 *   - token_type_hint (optional) "refresh_token" or "access_token" — a HINT
 *                                 only; an incorrect/absent hint still works
 *                                 (the shape of the token itself is checked).
 *   - client_id / client_secret — via HTTP Basic OR these two body fields,
 *                                  EXACTLY as `/oauth/token` accepts them
 *                                  (RFC 6749 §2.3.1).
 *
 * 🚦 Rate limiting (B-059): per-IP AND (when a client_id was presented)
 *    per-client, BEFORE any DB-bound client/token work — see "RATE LIMITING"
 *    below. Reuses the existing `jwt.rate_limit.*` settings (G-003) under
 *    DISTINCT bucket names, mirroring `/oauth/token`'s own wiring.
 *
 * All client-authentication + ownership-checked revocation logic lives in
 * OAuthRevocationService (web/private_html/auth/OAuthRevocationService.php)
 * — this file is a THIN controller: parse request -> call the service -> emit
 * the RFC 7009 response.
 *
 * @package    SIGNula
 * @subpackage OAuth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A4)
 * @see web/private_html/auth/OAuthRevocationService.php (client auth + ownership-checked revocation)
 * @see web/private_html/auth/OAuthTokenService.php       (Stage A3 — authenticateClient() reused)
 * @see .dev-team/specs/G-001.md §4, §5
 * ============================================================================
 */

// 🚀 Initialize SIGNula (config.php defines SIGNULA_INIT and loads all classes)
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 📤 RESPONSE HEADERS — set BEFORE any output, on every path
// ============================================================================
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// ============================================================================
// 🔒 G-001 red-team F-05 fix — the `oidc.enabled` master switch
// ============================================================================
// See oauth/token.php's own "F-05 fix" comment for the full rationale.
if (!class_exists('OidcDiscoveryService') || !OidcDiscoveryService::isProviderEnabled()) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found'], JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================================
// 🔧 LOCAL HELPERS (mirror oauth/token.php's tokenParam()/resolveClientAuth())
// ============================================================================

/**
 * 🧹 Pull a single POST parameter as a trimmed string, guarding against
 * non-scalar input being silently coerced by a bare (string) cast.
 *
 * @param string $key Parameter name.
 * @return string '' if absent/non-scalar/empty.
 */
function revokeParam(string $key): string
{
    if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
        return '';
    }

    return trim((string) $_POST[$key]);
}

/**
 * 🔐 Resolve the caller's client credentials: HTTP Basic first, falling back
 * to request-body `client_id`/`client_secret` fields — identical logic to
 * `resolveClientAuth()` in oauth/token.php (kept as a separate copy here
 * rather than a shared include, matching this codebase's existing
 * token.php/authorize-idp.php precedent of small per-controller local
 * helpers over a shared-helpers file).
 *
 * @return array{method:string, client_id:string, client_secret:?string}
 */
function resolveRevokeClientAuth(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

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

        return ['method' => 'basic', 'client_id' => '', 'client_secret' => null];
    }

    return [
        'method'        => 'body',
        'client_id'     => revokeParam('client_id'),
        'client_secret' => isset($_POST['client_secret']) && is_scalar($_POST['client_secret'])
            ? (string) $_POST['client_secret']
            : null,
    ];
}

/**
 * ❌ Emit a JSON error body with the given status + optional extra headers,
 * and exit. Used for the LOCAL (non-OAuthRevocationService) failures this
 * controller itself detects: an unsupported HTTP method, and (B-059) a
 * rate-limit breach. Mirrors emitTokenError() in oauth/token.php.
 *
 * @param int    $status  HTTP status code.
 * @param string $error   OAuth error code.
 * @param string $description Human-readable description.
 * @param array<string,string> $headers Extra headers to send.
 */
function emitRevokeError(int $status, string $error, string $description, array $headers = []): never
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

// 🔒 RFC 7009 §2: the revocation endpoint is POST-only.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    emitRevokeError(405, 'invalid_request', 'Only POST is supported.', ['Allow' => 'POST']);
}

$clientAuth = resolveRevokeClientAuth();

// ============================================================================
// 🚦 RATE LIMITING (B-059) — BEFORE any DB-bound client/token work
// ============================================================================
// Same limiter (SecurityUtils::checkRateLimit(), fail-OPEN if the limiter
// store is unavailable) and the same reused jwt.rate_limit.* settings
// /oauth/token uses, under their OWN distinct bucket names so revoke traffic
// never shares a counter with the token endpoint.
$rlWindowSecs = (int) getSetting('jwt.rate_limit.window_seconds', 900);
// 🔧 refresh_per_ip (60) is reused here as the per-IP ceiling for revoke too —
//    a revoke call is a lighter-weight operation than a token mint, so the
//    existing "refresh" limit's higher allowance is the better-fitting reuse
//    (see this file's own class doc + the task report for the naming note).
$rlMaxPerIp = (int) getSetting('jwt.rate_limit.refresh_per_ip', 60);
$rlMaxPerId = (int) getSetting('jwt.rate_limit.token_per_identifier', 10);

if (!SecurityUtils::checkRateLimit(getClientIP(), 'oauth_revoke_ip', $rlMaxPerIp, $rlWindowSecs)) {
    emitRevokeError(429, 'rate_limited', 'Too many requests. Please try again later.', ['Retry-After' => (string) $rlWindowSecs]);
}
if ($clientAuth['client_id'] !== ''
    && !SecurityUtils::checkRateLimit('client:' . strtolower($clientAuth['client_id']), 'oauth_revoke_client', $rlMaxPerId, $rlWindowSecs)
) {
    emitRevokeError(429, 'rate_limited', 'Too many requests. Please try again later.', ['Retry-After' => (string) $rlWindowSecs]);
}

$params = [
    'token'           => revokeParam('token'),
    'token_type_hint' => revokeParam('token_type_hint'),
];

// 🎯 All client-authentication + ownership-checked revocation logic lives in
// the service — this controller only shapes the HTTP response from its result.
$result = OAuthRevocationService::handleRevokeRequest($params, $clientAuth);

http_response_code($result['status']);
foreach (($result['headers'] ?? []) as $name => $value) {
    header($name . ': ' . $value);
}

// 🔒 RFC 7009 §2.2: on success the response body is not meaningful — emit
// truly EMPTY content (not even "{}"), matching the spec's own example
// exchanges. An error response (client-auth failure) still carries the
// standard OAuth JSON error body.
if ($result['status'] === 200) {
    exit;
}

echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
exit;
