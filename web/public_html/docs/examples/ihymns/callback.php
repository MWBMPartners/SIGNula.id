<?php

/**
 * ============================================================================
 * iHymns × SIGNula.id reference integration — callback.php
 * ============================================================================
 * COPY this into the iHymns app (github.com/MWBMPartners/iHymns) at the
 * path matching your registered redirect_uri, e.g.
 * /auth/signula/callback.php — NOT a live SIGNula runtime route. Nothing in
 * this file is called by, or affects, SIGNula's own router/config/settings;
 * it lives in the SIGNula repo purely as an accurate, copy-ready worked
 * example of the server-side redirect handler for the flow that
 * login-start.php (or the button in button-embed.html) kicks off.
 *
 * This is the SERVER-RENDERED variant (PHP session holds the PKCE state).
 * For the SPA/PWA variant (sessionStorage + fetch), see callback.js.
 *
 * Full reference this follows: SIGNula.com/docs/signin-with-signula.php
 * (steps 3-4) and SIGNula.com/docs/oidc-server.php (worked PHP samples) —
 * both in the SIGNula repo, and https://signula.id/docs/oidc-server on the
 * live site.
 *
 * @package iHymns
 * @see     https://datatracker.ietf.org/doc/html/rfc6749 (OAuth 2.0)
 * @see     https://openid.net/specs/openid-connect-core-1_0.html (OIDC Core)
 * ============================================================================
 */

declare(strict_types=1);

// -----------------------------------------------------------------------
// TODO(iHymns): require your app bootstrap here (session config already
// applied, DB connection available as e.g. $pdo, error/logging setup).
// require_once __DIR__ . '/../../bootstrap.php';
// -----------------------------------------------------------------------

// -----------------------------------------------------------------------
// 📦 Pull in firebase/php-jwt for id_token verification. SIGNula's own
// server vendors the SAME library source-only (no Composer in production,
// Dreamhost shared hosting) at web/_lib/jwt/ — see that directory's
// VERSION file for the exact pinned release (6.11.1 at time of writing)
// and web/private_html/security/JwtLibLoader.php for the require order if
// you want to mirror the vendoring approach 1:1 instead of Composer.
//
// EITHER (recommended if your host has Composer):
//   composer require firebase/php-jwt
//   require_once __DIR__ . '/vendor/autoload.php';
//
// OR (source-only vendoring, no Composer needed — SIGNula's own approach):
//   Copy web/_lib/jwt/src/*.php from the SIGNula repo into your own
//   "_lib/jwt/src/" and require_once them in this exact dependency order:
//     Key.php, BeforeValidException.php, ExpiredException.php,
//     SignatureInvalidException.php, JWTExceptionWithPayloadInterface.php,
//     JWT.php, JWK.php
//   (BSD-3-Clause licensed; see web/_lib/jwt/LICENSE in the SIGNula repo)
// -----------------------------------------------------------------------
require_once __DIR__ . '/vendor/autoload.php'; // TODO(iHymns): adjust path, or swap for the vendored require_once chain above

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

// -----------------------------------------------------------------------
// 🔧 Configuration — substitute your REAL registered values (Step 1 of
// README.md). Use environment variables / your secure config store in
// production; hardcoded here only for clarity in this reference file.
// -----------------------------------------------------------------------
const SIGNULA_BASE          = 'https://signula.id';
const SIGNULA_ISSUER        = 'https://signula.id'; // the id_token's `iss` claim must equal this exactly
const IHYMNS_CLIENT_ID      = 'IHYMNS_CLIENT_ID';    // TODO(iHymns): your real client_id
const IHYMNS_REDIRECT_URI   = 'https://ihymns.app/auth/signula/callback'; // must match registration byte-for-byte

// TODO(iHymns): ONLY set this (non-null) if you registered a CONFIDENTIAL
// client for a server backend. For the PUBLIC client used by the web/PWA
// + native apps (the default this file is written against), leave this
// null — PKCE alone is the proof of possession, and a client_secret must
// NEVER be embedded in anything you can't fully control server-side.
const SIGNULA_CLIENT_SECRET = null;

// -----------------------------------------------------------------------
// 🍪 Resume the session login-start.php created (or the one your own
// framework manages) — this is where the PKCE code_verifier/nonce live,
// keyed by `state`.
// -----------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_secure'   => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ]);
}

// =========================================================================
// 🚦 Step 0 — SIGNula reports an error (e.g. the user clicked Deny) BEFORE
// checking for `code` at all. Never assume `code` is present just because
// this handler was reached.
// =========================================================================
if (isset($_GET['error'])) {
    $error = (string) $_GET['error'];
    $errorDescription = isset($_GET['error_description']) ? (string) $_GET['error_description'] : '';

    // TODO(iHymns): log $error/$errorDescription to your own activity log,
    // then redirect to a friendly "sign-in was cancelled/failed" page.
    error_log(sprintf('[iHymns][SIGNula OAuth] error=%s description=%s', $error, $errorDescription));
    header('Location: /login?signula_error=' . rawurlencode($error), true, 302);
    exit;
}

// =========================================================================
// 🔑 Step 1 — Read `code` and `state` from the query string.
// =========================================================================
$code = isset($_GET['code']) ? (string) $_GET['code'] : '';
$returnedState = isset($_GET['state']) ? (string) $_GET['state'] : '';

if ($code === '' || $returnedState === '') {
    http_response_code(400);
    exit('Missing code or state parameter.');
}

// =========================================================================
// 🛡️ Step 2 — Verify `state` FIRST, before anything else touches the
// database or the network. This is the CSRF defence: only proceed if
// `state` resolves to an entry WE stored at flow-start (login-start.php).
// Reject outright on any mismatch/absence — never fall back to "just use
// whatever's in the session" as a substitute for keying the lookup by the
// actual returned `state`.
// =========================================================================
$pending = $_SESSION['signula_oauth'][$returnedState] ?? null;

if ($pending === null) {
    http_response_code(400);
    error_log('[iHymns][SIGNula OAuth] state mismatch — no matching pending flow for returned state');
    exit('Invalid or expired sign-in session — please try signing in again.');
}

// Single-use: consume the pending entry immediately so a replayed callback
// URL (e.g. from browser history, or an attacker capturing the redirect)
// cannot be processed twice.
unset($_SESSION['signula_oauth'][$returnedState]);

$codeVerifier  = $pending['code_verifier'];
$expectedNonce = $pending['nonce'];
$redirectUri   = $pending['redirect_uri'];

// =========================================================================
// 🔁 Step 3 — Exchange the authorization code for tokens, server-side.
// The raw code_verifier is sent ONLY here, over HTTPS, never before.
// =========================================================================
$tokenRequestFields = [
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => $redirectUri,
    'code_verifier' => $codeVerifier,
];

$tokenRequestHeaders = [
    'Content-Type: application/x-www-form-urlencoded',
];

if (SIGNULA_CLIENT_SECRET !== null) {
    // Confidential-client variant ONLY: authenticate with HTTP Basic
    // (client_secret_basic, RFC 6749 §2.3.1) — preferred over sending the
    // secret as a body field. Never reachable for the public-client
    // default this file ships with (SIGNULA_CLIENT_SECRET === null above).
    $basicAuth = base64_encode(IHYMNS_CLIENT_ID . ':' . SIGNULA_CLIENT_SECRET);
    $tokenRequestHeaders[] = 'Authorization: Basic ' . $basicAuth;
} else {
    // Public client: client_id travels in the body (no secret exists —
    // PKCE's code_verifier above is the proof of possession).
    $tokenRequestFields['client_id'] = IHYMNS_CLIENT_ID;
}

$tokenResponseRaw = httpPostForm(SIGNULA_BASE . '/oauth/token', $tokenRequestFields, $tokenRequestHeaders);

if ($tokenResponseRaw === null) {
    http_response_code(502);
    error_log('[iHymns][SIGNula OAuth] token exchange request failed (network/HTTP error)');
    exit('Sign-in failed — could not reach SIGNula. Please try again.');
}

$tokens = json_decode($tokenResponseRaw, true, 512, JSON_THROW_ON_ERROR);

if (!isset($tokens['access_token'], $tokens['id_token'])) {
    // e.g. {"error":"invalid_grant","error_description":"..."}
    http_response_code(400);
    error_log('[iHymns][SIGNula OAuth] token exchange returned no access_token/id_token: ' . $tokenResponseRaw);
    exit('Sign-in failed — the authorization code could not be exchanged.');
}

// =========================================================================
// ✅ Step 4 — Verify the id_token before trusting ANY of its claims.
// =========================================================================
try {
    $claims = verifySignulaIdToken(
        (string) $tokens['id_token'],
        IHYMNS_CLIENT_ID,
        $expectedNonce
    );
} catch (Throwable $verificationError) {
    http_response_code(401);
    // Never log the raw token itself — only the failure reason.
    error_log('[iHymns][SIGNula OAuth] id_token verification failed: ' . $verificationError->getMessage());
    exit('Sign-in failed — the identity token could not be verified.');
}

// $claims->sub is iHymns' pairwise, per-client identifier for this user —
// NOT an email address, and not comparable to the sub SIGNula issues to a
// different Relying Party for the same human being. Treat it as the
// durable primary key.
$signulaSub = (string) $claims->sub;

// =========================================================================
// 👤 Step 5 (optional) — Call UserInfo for fresh profile claims. Skip this
// if the id_token's own claims (requested via `scope`) are already enough
// for your app — it's an extra round trip.
// =========================================================================
$userInfoRaw = httpGetBearer(SIGNULA_BASE . '/oauth/userinfo', (string) $tokens['access_token']);
$userInfo = $userInfoRaw !== null
    ? json_decode($userInfoRaw, true, 512, JSON_THROW_ON_ERROR)
    : null;

// Prefer the freshest UserInfo claims where available; fall back to the
// id_token's own claims (both are scope-gated the same way).
$email = $userInfo['email'] ?? ($claims->email ?? null);
$name  = $userInfo['name'] ?? ($claims->name ?? null);

// =========================================================================
// 🧑‍🤝‍🧑 Step 6 — Map the verified SIGNula identity onto an iHymns user.
// First login for this `sub` auto-provisions; every later login matches
// on `sub` (never on email — see README.md Step 4 for why).
// =========================================================================
$ihymnsUser = mapSignulaSubjectToIhymnsUser($signulaSub, $email, $name);

// =========================================================================
// 🔓 Step 7 — Establish the iHymns session and redirect into the app.
// =========================================================================
session_regenerate_id(true); // rotate the session ID on privilege change (login)
$_SESSION['ihymns_user_id'] = $ihymnsUser['id'];
$_SESSION['signula_sub']    = $signulaSub;
// TODO(iHymns): store $tokens['access_token'] / $tokens['refresh_token']
// per your own token-storage strategy if iHymns needs to call SIGNula APIs
// on the user's behalf later — see /docs/oidc-server "5. Secure token
// storage" for httpOnly-cookie vs database-encrypted-at-rest options. Do
// NOT store the raw code_verifier or nonce beyond this request; both are
// already consumed.

header('Location: /dashboard', true, 302);
exit;

// =========================================================================
// 🔧 Helper functions
// =========================================================================

/**
 * POST application/x-www-form-urlencoded fields to a URL and return the
 * raw response body, or null on any transport/HTTP-level failure.
 *
 * @param string   $url
 * @param string[] $fields
 * @param string[] $headers
 */
function httpPostForm(string $url, array $fields, array $headers): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        error_log('[iHymns][SIGNula OAuth] curl error: ' . $curlError);
        return null;
    }
    // 4xx/5xx still returns a body worth inspecting (e.g. {"error":"..."})
    // — the caller decides what to do with it; we only fail hard on a
    // transport-level error above.
    unset($status);
    return (string) $body;
}

/**
 * GET a URL with a Bearer token and return the raw response body, or null
 * on failure. Used for /oauth/userinfo.
 */
function httpGetBearer(string $url, string $accessToken): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        error_log('[iHymns][SIGNula OAuth] userinfo fetch failed: ' . $curlError);
        return null;
    }
    return (string) $body;
}

/**
 * Fetch SIGNula's JWKS, with a simple time-based file cache (SIGNula
 * rotates signing keys infrequently — no need to hit the network on every
 * single login). Adjust the cache path/TTL to fit your deployment; on
 * Dreamhost-style shared hosting a private, non-web-accessible directory
 * (outside public_html) is appropriate, matching SIGNula's own convention
 * of underscore-prefixed private directories.
 *
 * @return array<string,mixed> Decoded JWKS document (the `keys` array).
 */
function fetchSignulaJwks(): array
{
    // TODO(iHymns): point this at a real, non-web-accessible cache
    // directory in your own deployment.
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ihymns_signula_jwks.json';
    $cacheTtlSeconds = 3600; // 1 hour — matches typical JWKS rotation cadence

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtlSeconds) {
        $cached = file_get_contents($cacheFile);
        if ($cached !== false) {
            return json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    $ch = curl_init(SIGNULA_BASE . '/.well-known/jwks.json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    if ($body === false) {
        // Fall back to a stale cache rather than fail outright, if one exists.
        if (is_file($cacheFile)) {
            $stale = file_get_contents($cacheFile);
            if ($stale !== false) {
                return json_decode($stale, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        throw new RuntimeException('Unable to fetch SIGNula JWKS and no cache available.');
    }

    // Best-effort cache write — don't fail the request if this doesn't work.
    @file_put_contents($cacheFile, $body);

    return json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Verify a SIGNula id_token: signature (RS256, via JWKS), issuer,
 * audience, expiry (enforced by the library), and nonce. Throws on any
 * failure — callers must not swallow the exception and proceed.
 *
 * @param string $idToken        The raw JWT from the token endpoint.
 * @param string $expectedAud    Your registered client_id.
 * @param string $expectedNonce  The nonce YOU generated at flow-start.
 * @return object Decoded, verified claims (stdClass — matches firebase/php-jwt's return type).
 */
function verifySignulaIdToken(string $idToken, string $expectedAud, string $expectedNonce): object
{
    $jwksDocument = fetchSignulaJwks();
    $keySet = JWK::parseKeySet($jwksDocument);

    // JWT::decode() verifies the signature against the key matching the
    // token's `kid` header (RS256 only, per the Key objects JWK::parseKeySet
    // produces) AND enforces `exp`/`nbf` internally — both throw
    // ExpiredException/BeforeValidException/SignatureInvalidException on
    // failure, which we let propagate to the caller's catch block.
    $claims = JWT::decode($idToken, $keySet);

    if (($claims->iss ?? null) !== SIGNULA_ISSUER) {
        throw new RuntimeException('id_token has an unexpected issuer.');
    }

    if (($claims->aud ?? null) !== $expectedAud) {
        throw new RuntimeException('id_token has an unexpected audience.');
    }

    // hash_equals() for timing-safe comparison, per SIGNula's own
    // documented best practice (/docs/oidc-server "Best practices").
    if (!hash_equals($expectedNonce, (string) ($claims->nonce ?? ''))) {
        throw new RuntimeException('id_token nonce does not match — possible replay/substitution.');
    }

    if (($claims->sub ?? '') === '') {
        throw new RuntimeException('id_token is missing sub.');
    }

    return $claims;
}

/**
 * Map a verified SIGNula `sub` onto an iHymns local user, auto-provisioning
 * on first login. `sub` is the durable key — email is a mutable profile
 * field only.
 *
 * TODO(iHymns): replace the body of this function with your real DB layer
 * / user model. The shape below (a `users` table with a UNIQUE
 * `signula_sub` column) matches schema.sql in this same reference
 * directory — adapt to your actual schema/ORM.
 *
 * @return array{id:int,email:?string,display_name:?string}
 */
function mapSignulaSubjectToIhymnsUser(string $sub, ?string $email, ?string $name): array
{
    // TODO(iHymns): obtain your real DB connection however your bootstrap
    // provides it (dependency injection, a global, a service locator —
    // whatever iHymns already uses). $pdo is illustrative only.
    /** @var PDO $pdo */
    global $pdo;

    $lookup = $pdo->prepare('SELECT id, email, display_name FROM users WHERE signula_sub = :sub LIMIT 1');
    $lookup->execute([':sub' => $sub]);
    $existing = $lookup->fetch(PDO::FETCH_ASSOC);

    if ($existing !== false) {
        // Repeat sign-in — matched by sub, exactly as intended. We
        // deliberately do NOT re-match/re-link by email here: email is
        // mutable on the SIGNula side and must never override the
        // established sub-based link.
        return $existing;
    }

    // First login for this SIGNula identity — auto-provision.
    $insert = $pdo->prepare(
        'INSERT INTO users (signula_sub, email, display_name, created_at) VALUES (:sub, :email, :name, NOW())'
    );
    $insert->execute([
        ':sub'   => $sub,
        ':email' => $email,
        ':name'  => $name ?? 'iHymns User',
    ]);

    return [
        'id'           => (int) $pdo->lastInsertId(),
        'email'        => $email,
        'display_name' => $name ?? 'iHymns User',
    ];
}
