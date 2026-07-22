<?php
/**
 * ============================================================================
 * 🚀 SIGNula OIDC Integration — Quickstart (5 minutes)
 * ============================================================================
 * Fast-track guide for developers who want to add "Sign in with SIGNula.id"
 * to their app RIGHT NOW — skip the theory, get to working code.
 *
 * For the full reference (all parameters, error handling, refresh/revoke,
 * security requirements), see /docs/signin-with-signula.
 *
 * @package    SIGNula
 * @subpackage Documentation
 * @version    1.0.0
 * @since      2.9.0-beta (G-001)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */
$pageTitle = 'OIDC Quickstart (5 min) - SIGNula Documentation';
$metaDescription = 'Get "Sign in with SIGNula.id" working in 5 simple steps — register, embed, redirect, exchange, verify.';
$currentPage = 'docs';
$currentDoc = 'oidc-quickstart';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>
<section class="section-padding">
    <div class="container">
        <div class="row">
            <div class="col-lg-3">
                <?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'docs-sidebar.php'; ?>
            </div>
            <div class="col-lg-9">
                <h1 class="display-5 fw-bold mb-4">OIDC Quickstart</h1>
                <p class="lead">
                    Add "Sign in with SIGNula.id" to your app in 5 minutes flat.
                    This page skips the details — for the complete reference with
                    all parameters, error scenarios, and security requirements, see
                    <a href="/docs/signin-with-signula">Sign in with SIGNula.id</a>.
                </p>

                <div class="alert alert-info">
                    <strong>Heads up:</strong> This guide assumes a server-side
                    app (PHP, Python, Node, etc). If you&#39;re building a
                    browser-only SPA or mobile app, jump to
                    <a href="/docs/oidc-web" class="alert-link">Web / PWA</a> or
                    <a href="/docs/oidc-native" class="alert-link">Native / Mobile</a>
                    instead.
                </div>

                <!-- ============================================================ -->
                <!-- STEP 1: REGISTER -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Step 1: Register your OAuth client (2 min)</h2>
                <ol>
                    <li>Sign into your Partner Dashboard</li>
                    <li>Go to <strong>Dashboard &rarr; OAuth / OIDC Clients</strong></li>
                    <li>Click <strong>Register New OAuth Client</strong></li>
                    <li>Fill in:
                        <ul>
                            <li><strong>Client Name</strong>: Shown to users</li>
                            <li><strong>Client Type</strong>: <code>confidential</code> (you&#39;re server-side)</li>
                            <li><strong>Redirect URI</strong>: <code>https://app.example.com/auth/callback</code>
                                (must be HTTPS, must match exactly at sign-in time)</li>
                            <li><strong>Scopes</strong>: Tick <code>profile</code>, <code>email</code> (at minimum)</li>
                        </ul>
                    </li>
                    <li><strong>Copy your <code>client_id</code> and <code>client_secret</code>
                        immediately</strong> — they&#39;re shown only once. Store them securely
                        (environment variables, not source control).</li>
                </ol>

                <!-- ============================================================ -->
                <!-- STEP 2: EMBED BUTTON -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Step 2: Add the button to your login page (1 min)</h2>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;link rel="stylesheet" href="https://signula.id/assets/signin-button/signula-signin.css"&gt;

&lt;button type="button" class="signula-btn signula-btn--light"
        data-client-id="YOUR_CLIENT_ID"
        data-redirect-uri="https://app.example.com/auth/callback"&gt;
  &lt;!-- Icon markup from /assets/signin-button/README.md --&gt;
  &lt;span class="signula-btn__label"&gt;Sign in with SIGNula.id&lt;/span&gt;
&lt;/button&gt;

&lt;script src="https://signula.id/assets/signin-button/signula-signin.js"&gt;&lt;/script&gt;</code></pre>
                <p class="small text-secondary">
                    The script auto-wires any button with <code>data-client-id</code>.
                    Full snippets + variants at
                    <a href="/assets/signin-button/">/assets/signin-button/README.md</a>.
                </p>

                <!-- ============================================================ -->
                <!-- STEP 3: HANDLE REDIRECT -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Step 3: Implement your redirect handler (1 min)</h2>
                <p>
                    When the user clicks the button and signs in, SIGNula redirects
                    back to your <code>redirect_uri</code> with <code>?code=...&amp;state=...</code>.
                    Your app at that URL needs to:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;?php
// /auth/callback

if (empty($_GET['code']) || empty($_GET['state'])) {
    die('Missing code or state');
}

// Read the state/nonce from sessionStorage (see Step 5 for details)
$state = $_GET['state'];
$code = $_GET['code'];

// ... get codeVerifier + nonce from session ...
// (see Step 5 for the full flow)

// Hand off to Step 4 (exchange the code)
?&gt;</code></pre>

                <!-- ============================================================ -->
                <!-- STEP 4: EXCHANGE THE CODE -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Step 4: Exchange the code for tokens (1 min)</h2>
                <p>Server-side (never expose <code>code_verifier</code> to the browser):</p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;?php
// POST /oauth/token
$response = http_post_fields('https://signula.id/oauth/token', [
    'grant_type'   =&gt; 'authorization_code',
    'code'         =&gt; $code,
    'redirect_uri' =&gt; 'https://app.example.com/auth/callback',
    'code_verifier'=&gt; $codeVerifier,  // from sessionStorage step
], [
    'Authorization' =&gt; 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
]);

$tokens = json_decode($response, true);
// $tokens['id_token']       — JWT (verify it in Step 5)
// $tokens['access_token']   — short-lived API token
// $tokens['refresh_token']  — (if you requested offline_access)
?&gt;</code></pre>

                <!-- ============================================================ -->
                <!-- STEP 5: VERIFY & USE -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Step 5: Verify the token &amp; log the user in (1 min)</h2>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;?php
// Fetch SIGNula&#39;s public keys
$jwks = json_decode(
    file_get_contents('https://signula.id/.well-known/jwks.json'),
    true
);

$keySet = Firebase\JWT\JWK::parseKeySet($jwks);
$claims = Firebase\JWT\JWT::decode($tokens['id_token'], $keySet);

// ✅ Verify the essentials
assert($claims-&gt;iss === 'https://signula.id',  'bad issuer');
assert($claims-&gt;aud === $clientId,            'bad aud');
assert($claims-&gt;nonce === $expectedNonce,    'bad nonce');  // from sessionStorage

$userId = $claims-&gt;sub;  // stable, per-app user ID
$email  = $claims-&gt;email ?? null;
$name   = $claims-&gt;name ?? null;

// Log the user in / create account if needed
$_SESSION['user_id'] = $userId;
header('Location: /dashboard');
?&gt;</code></pre>

                <div class="alert alert-success mt-4">
                    <strong>That&#39;s it!</strong> Your user is now logged in. For
                    <strong>refresh tokens</strong>, <strong>logout</strong>,
                    <strong>advanced security</strong>, and the
                    <strong>full parameter reference</strong>, see
                    <a href="/docs/signin-with-signula" class="alert-link">Sign in with SIGNula.id</a>.
                </div>

                <!-- ============================================================ -->
                <!-- PLATFORM GUIDES -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Platform-specific guides</h2>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="fas fa-globe me-2"></i>Web / SPA / PWA
                                </h5>
                                <p class="card-text">
                                    Token storage, redirect handling, button integration, service workers.
                                </p>
                                <a href="/docs/oidc-web" class="btn btn-sm btn-outline-primary">
                                    Read guide &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="fas fa-mobile-alt me-2"></i>Native / Mobile
                                </h5>
                                <p class="card-text">
                                    iOS / Android, system browser, Keychain / Keystore, public clients.
                                </p>
                                <a href="/docs/oidc-native" class="btn btn-sm btn-outline-primary">
                                    Read guide &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="fas fa-server me-2"></i>Server-side / Backend
                                </h5>
                                <p class="card-text">
                                    Confidential clients, HTTP Basic auth, refresh rotation, token handling.
                                </p>
                                <a href="/docs/oidc-server" class="btn btn-sm btn-outline-primary">
                                    Read guide &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="fas fa-shield-alt me-2"></i>Security Checklist
                                </h5>
                                <p class="card-text">
                                    PKCE, state, nonce, redirect_uri validation, common pitfalls.
                                </p>
                                <a href="/docs/oidc-security" class="btn btn-sm btn-outline-primary">
                                    Read guide &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-secondary mt-5">
                    <strong>Full reference:</strong> See
                    <a href="/docs/signin-with-signula" class="alert-link">Sign in with SIGNula.id</a>
                    for the complete spec (all parameters, error responses,
                    refresh/revoke, UserInfo endpoint, and everything else).
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php'; ?>
