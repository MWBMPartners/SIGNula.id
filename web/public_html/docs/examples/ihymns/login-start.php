<?php

/**
 * ============================================================================
 * iHymns × SIGNula.id reference integration — login-start.php
 * ============================================================================
 * COPY this into the iHymns app (github.com/MWBMPartners/iHymns) — e.g. at
 * /auth/signula/login.php or wired to your router as /auth/signula/login —
 * NOT a live SIGNula runtime route. Pairs with callback.php in this same
 * directory for the server-rendered flow variant (no client-side JS
 * required at all — use this instead of button-embed.html if you need
 * sign-in to work with JavaScript disabled, per
 * /docs/signin-with-signula "JavaScript required" note on the button).
 *
 * Purpose: generate PKCE (S256) + state + nonce, persist them in the PHP
 * session, and redirect the browser to SIGNula's authorize-idp endpoint.
 * This mirrors exactly what /assets/signin-button/signula-signin.js does
 * client-side, but server-side — same algorithm, same RFC 7636 shapes,
 * just PHP's random_bytes()/hash() instead of Web Crypto.
 *
 * Reference: SIGNula.com/docs/oidc-server.php "1. Initiate the
 * authorization flow — Option B" (this file follows that pattern exactly).
 *
 * @package iHymns
 * @see     https://datatracker.ietf.org/doc/html/rfc7636 (PKCE)
 * ============================================================================
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// TODO(iHymns): require your app bootstrap here instead (session config,
// error handling, etc.) — this file assumes nothing about your framework.
// require_once __DIR__ . '/../../bootstrap.php';
// ---------------------------------------------------------------------------

// -----------------------------------------------------------------------
// 🔧 Configuration — substitute your REAL registered values (Step 1 of
// README.md). Keep these in environment variables / your secure config
// store in production, not hardcoded — shown as constants here only for
// clarity in this reference file.
// -----------------------------------------------------------------------
const SIGNULA_BASE          = 'https://signula.id';
const IHYMNS_CLIENT_ID      = 'IHYMNS_CLIENT_ID'; // TODO(iHymns): your real client_id
const IHYMNS_REDIRECT_URI   = 'https://ihymns.app/auth/signula/callback'; // must match registration byte-for-byte
const IHYMNS_SCOPE          = 'openid email profile'; // add "offline_access" if you need refresh tokens

// -----------------------------------------------------------------------
// 🍪 Start (or resume) the PHP session with hardened cookie flags — the
// session is where callback.php will look up the values we're about to
// generate. See /docs/oidc-server "5. Secure token storage — Option A".
// -----------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_secure'   => true,     // HTTPS only
        'cookie_httponly' => true,     // no JavaScript access
        'cookie_samesite' => 'Strict', // CSRF hardening
    ]);
}

// -----------------------------------------------------------------------
// 🔐 Step 1 — Generate PKCE code_verifier / code_challenge, plus state
// and nonce. Mirrors /docs/oidc-server.php's PHP sample exactly.
// -----------------------------------------------------------------------
$codeVerifier = bin2hex(random_bytes(32)); // 64 hex chars = 256 bits of entropy, well within RFC 7636's 43-128 char range
$codeChallenge = rtrim(
    strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'),
    '='
);

$state = bin2hex(random_bytes(16));
$nonce = bin2hex(random_bytes(16));

// -----------------------------------------------------------------------
// 💾 Step 2 — Persist for callback.php to retrieve after the redirect.
// Keying by `state` (rather than a single top-level slot) means a second
// tab/attempt started before the first completes doesn't clobber the
// first attempt's verifier/nonce — callback.php looks this up by the
// `state` query parameter SIGNula actually returns.
// -----------------------------------------------------------------------
$_SESSION['signula_oauth'][$state] = [
    'code_verifier' => $codeVerifier,
    'nonce'         => $nonce,
    'redirect_uri'  => IHYMNS_REDIRECT_URI,
    'created_at'    => time(),
];

// -----------------------------------------------------------------------
// 🔗 Step 3 — Build the authorize URL and redirect.
// -----------------------------------------------------------------------
$authorizeUrl = SIGNULA_BASE . '/oauth/authorize-idp?' . http_build_query([
    'response_type'         => 'code',
    'client_id'              => IHYMNS_CLIENT_ID,
    'redirect_uri'           => IHYMNS_REDIRECT_URI,
    'scope'                  => IHYMNS_SCOPE,
    'code_challenge'         => $codeChallenge,
    'code_challenge_method'  => 'S256',
    'state'                  => $state,
    'nonce'                  => $nonce,
    // Optional: uncomment to force the consent screen even on a repeat
    // sign-in (e.g. a "switch account" link).
    // 'prompt'              => 'consent',
]);

header('Location: ' . $authorizeUrl, true, 302);
exit;
