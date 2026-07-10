<?php
/**
 * ============================================================================
 * 🖥️ SIGNula OIDC — Server-side / Confidential Client Integration Guide
 * ============================================================================
 * Platform-specific guidance for backend services and confidential clients
 * (server-rendered apps, microservices, backend APIs that can securely store
 * a client_secret). Covers HTTP Basic auth, server-side code exchange,
 * refresh rotation, token validation, and logout.
 *
 * For the full OAuth/OIDC reference (all endpoints, parameters, security),
 * see /docs/signin-with-signula.
 *
 * @package    SIGNula
 * @subpackage Documentation
 * @version    1.0.0
 * @since      2.9.0-beta (G-001)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */
$pageTitle = 'OIDC — Server-side / Confidential Client Integration - SIGNula Documentation';
$metaDescription = 'Integrate "Sign in with SIGNula.id" in backend services. Confidential clients, HTTP Basic auth, token exchange, refresh rotation, token validation.';
$currentPage = 'docs';
$currentDoc = 'oidc-server';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>
<section class="section-padding">
    <div class="container">
        <div class="row">
            <div class="col-lg-3">
                <?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'docs-sidebar.php'; ?>
            </div>
            <div class="col-lg-9">
                <h1 class="display-5 fw-bold mb-4">Server-side / Confidential Client Integration</h1>
                <p class="lead">
                    Integrate "Sign in with SIGNula.id" in your backend service
                    or server-rendered application. Confidential clients can
                    securely hold a <code>client_secret</code> and authenticate
                    directly with SIGNula.
                </p>

                <div class="alert alert-info">
                    <strong>This is the server guide.</strong> If you&#39;re building
                    a web SPA, see
                    <a href="/docs/oidc-web" class="alert-link">Web / SPA / PWA</a>.
                    For native mobile, see
                    <a href="/docs/oidc-native" class="alert-link">Native / Mobile</a>.
                </div>

                <!-- ============================================================ -->
                <!-- CLIENT REGISTRATION -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Register as a confidential client</h2>
                <p>
                    Confidential clients can hold a <code>client_secret</code>
                    securely on the backend and authenticate directly with SIGNula.
                </p>
                <ol>
                    <li>Sign into your Partner Dashboard</li>
                    <li>Go to <strong>Dashboard &rarr; OAuth / OIDC Clients</strong></li>
                    <li>Click <strong>Register New OAuth Client</strong></li>
                    <li>Fill in:
                        <ul>
                            <li><strong>Client Name</strong>: Shown to users</li>
                            <li><strong>Client Type</strong>: <code>confidential</code></li>
                            <li><strong>Redirect URI(s)</strong>: Your backend endpoint
                                that handles the OAuth callback:
                                <code>https://app.example.com/auth/callback</code></li>
                            <li><strong>Scopes</strong>: Tick <code>profile</code>, <code>email</code>,
                                <code>offline_access</code> (if you need refresh tokens)</li>
                        </ul>
                    </li>
                    <li><strong>Copy your <code>client_id</code> and <code>client_secret</code>
                        immediately</strong> — they&#39;re shown only once. Store them securely
                        (environment variables or your backend&#39;s secure config store,
                        NEVER in source control).</li>
                </ol>

                <!-- ============================================================ -->
                <!-- INITIATING THE FLOW -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">1. Initiate the authorization flow</h2>
                <p>
                    When a user clicks "Sign in with SIGNula", either:
                </p>
                <ul>
                    <li><strong>Option A:</strong> Embed the drop-in button (same as web apps)
                        and let it handle the PKCE generation and redirect.</li>
                    <li><strong>Option B:</strong> Generate PKCE, state, and nonce server-side
                        and build the authorize URL yourself.</li>
                </ul>

                <h3 class="h5 mt-4">Option B — Server-side flow generation (PHP example)</h3>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;?php
// Generate PKCE
$codeVerifier = bin2hex(random_bytes(32));  // 64 hex = 128 bits
$codeChallenge = rtrim(
    strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'),
    '='
);

$state = bin2hex(random_bytes(16));
$nonce = bin2hex(random_bytes(16));

// Store in session for later verification
$_SESSION['oauth'] = [
    'code_verifier' =&gt; $codeVerifier,
    'state'         =&gt; $state,
    'nonce'         =&gt; $nonce,
    'created_at'    =&gt; time(),
];

// Build the authorize URL
$authorizeUrl = 'https://signula.id/oauth/authorize-idp?' . http_build_query([
    'client_id'              =&gt; getenv('SIGNULA_CLIENT_ID'),
    'redirect_uri'           =&gt; 'https://app.example.com/auth/callback',
    'response_type'          =&gt; 'code',
    'scope'                  =&gt; 'openid email profile offline_access',
    'code_challenge'         =&gt; $codeChallenge,
    'code_challenge_method'  =&gt; 'S256',
    'state'                  =&gt; $state,
    'nonce'                  =&gt; $nonce,
]);

header('Location: ' . $authorizeUrl);
?&gt;</code></pre>

                <!-- ============================================================ -->
                <!-- TOKEN EXCHANGE -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">2. Exchange the code (server-side)</h2>
                <p>
                    Your redirect handler receives <code>?code=...&amp;state=...</code>.
                    Verify the state, then exchange the code for tokens.
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;?php
// /auth/callback

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;

if (!$code || !$state) {
    die('Missing code or state');
}

// Verify state (CSRF protection)
if ($state !== ($_SESSION['oauth']['state'] ?? null)) {
    die('State mismatch — CSRF check failed');
}

// Retrieve stored values
$codeVerifier = $_SESSION['oauth']['code_verifier'];
$expectedNonce = $_SESSION['oauth']['nonce'];

// Exchange the code for tokens using HTTP Basic auth
$clientId = getenv('SIGNULA_CLIENT_ID');
$clientSecret = getenv('SIGNULA_CLIENT_SECRET');
$basicAuth = base64_encode(&quot;{$clientId}:{$clientSecret}&quot;);

$tokenRequest = [
    'grant_type'    =&gt; 'authorization_code',
    'code'          =&gt; $code,
    'redirect_uri'  =&gt; 'https://app.example.com/auth/callback',
    'code_verifier' =&gt; $codeVerifier,
];

$response = http_post_fields('https://signula.id/oauth/token', $tokenRequest, [
    'Authorization' =&gt; &quot;Basic {$basicAuth}&quot;,
    'Content-Type'  =&gt; 'application/x-www-form-urlencoded',
]);

if (!$response) {
    die('Token exchange failed');
}

$tokens = json_decode($response, true);

// $tokens = [
//   'access_token'  =&gt; '...',
//   'token_type'    =&gt; 'Bearer',
//   'expires_in'    =&gt; 900,
//   'id_token'      =&gt; '...',
//   'refresh_token' =&gt; '...'  (if offline_access was granted)
// ]

?&gt;</code></pre>

                <!-- ============================================================ -->
                <!-- VERIFY TOKEN -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">3. Verify the id_token</h2>
                <p>
                    Before trusting any claims, verify the JWT signature and
                    the essential claims:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;?php
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;

// Fetch JWKS
$jwksData = json_decode(
    file_get_contents('https://signula.id/.well-known/jwks.json'),
    true
);
$jwks = JWK::parseKeySet($jwksData);

try {
    // Verify signature and decode
    $decoded = JWT::decode($tokens['id_token'], $jwks);

    // Verify issuer
    if ($decoded-&gt;iss !== 'https://signula.id') {
        throw new Exception('Invalid issuer');
    }

    // Verify audience (your client_id)
    if ($decoded-&gt;aud !== getenv('SIGNULA_CLIENT_ID')) {
        throw new Exception('Invalid audience');
    }

    // Verify nonce (replay protection)
    if (!hash_equals($expectedNonce, $decoded-&gt;nonce ?? '')) {
        throw new Exception('Invalid nonce');
    }

    // Verify expiration (allow 60s clock skew)
    $now = time();
    if ($decoded-&gt;exp &lt; $now - 60) {
        throw new Exception('Token expired');
    }

    // Token is valid, use the claims
    $userId = $decoded-&gt;sub;      // stable, per-app user identifier
    $email = $decoded-&gt;email ?? null;
    $name = $decoded-&gt;name ?? null;

    // Log the user in
    $_SESSION['user_id'] = $userId;
    $_SESSION['email'] = $email;

} catch (Exception $e) {
    die('Token verification failed: ' . $e-&gt;getMessage());
}

// Clean up OAuth session data
unset($_SESSION['oauth']);
header('Location: /dashboard');
?&gt;</code></pre>

                <!-- ============================================================ -->
                <!-- REFRESH TOKENS -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">4. Refresh tokens (single-use rotation)</h2>
                <p>
                    Access tokens expire after 15 minutes. Use the refresh token
                    to get a new access token. <strong>Each refresh issues a new
                    refresh token</strong> (single-use rotation) — update your storage.
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;?php
// Refresh the access token
$clientId = getenv('SIGNULA_CLIENT_ID');
$clientSecret = getenv('SIGNULA_CLIENT_SECRET');
$basicAuth = base64_encode(&quot;{$clientId}:{$clientSecret}&quot;);

$refreshRequest = [
    'grant_type'    =&gt; 'refresh_token',
    'refresh_token' =&gt; $_SESSION['refresh_token'],
];

$response = http_post_fields('https://signula.id/oauth/token', $refreshRequest, [
    'Authorization' =&gt; &quot;Basic {$basicAuth}&quot;,
    'Content-Type'  =&gt; 'application/x-www-form-urlencoded',
]);

if (!$response) {
    // Refresh failed — user must sign in again
    unset($_SESSION['user_id']);
    header('Location: /login?reason=session_expired');
    exit;
}

$newTokens = json_decode($response, true);

// Update session with new tokens
$_SESSION['access_token'] = $newTokens['access_token'];
if (!empty($newTokens['refresh_token'])) {
    $_SESSION['refresh_token'] = $newTokens['refresh_token'];  // rotation
}
$_SESSION['token_expiry'] = time() + $newTokens['expires_in'];

?&gt;</code></pre>

                <div class="alert alert-warning">
                    <strong>⚠️ Refresh token reuse is a security incident:</strong>
                    If an attacker replays an already-used refresh token, SIGNula
                    revokes the entire token family. Both the attacker and the
                    legitimate holder are signed out.
                </div>

                <!-- ============================================================ -->
                <!-- TOKEN STORAGE -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">5. Secure token storage</h2>
                <p>
                    Store tokens securely on your backend (database, cache, file).
                    Options depend on your app architecture:
                </p>

                <h3 class="h5 mt-4">Option A: Server-side session</h3>
                <p>
                    Store tokens in the PHP session (or equivalent in your framework).
                    The session cookie itself is httpOnly, Secure, and SameSite=Strict.
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;?php
session_start([
    'cookie_secure'   =&gt; true,     // HTTPS only
    'cookie_httponly' =&gt; true,     // No JavaScript access
    'cookie_samesite' =&gt; 'Strict', // CSRF protection
]);

$_SESSION['access_token'] = $tokens['access_token'];
$_SESSION['refresh_token'] = $tokens['refresh_token'];
$_SESSION['token_expiry'] = time() + $tokens['expires_in'];
?&gt;</code></pre>

                <h3 class="h5 mt-4">Option B: Database (for stateless backends / microservices)</h3>
                <p>
                    Store tokens in your database, encrypted at rest. Use
                    per-user token storage and rotate refresh tokens on each use.
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;?php
// INSERT / UPDATE user token storage
$stmt = $db-&gt;prepare(&quot;
    INSERT INTO user_tokens (user_id, access_token, refresh_token, expires_at)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        access_token = VALUES(access_token),
        refresh_token = VALUES(refresh_token),
        expires_at = VALUES(expires_at),
        updated_at = NOW()
&quot;);

// Encrypt tokens before storage (see note below)
$encryptedAccess = encryptAES256($tokens['access_token']);
$encryptedRefresh = encryptAES256($tokens['refresh_token']);

$stmt-&gt;bind_param(
    'isss',
    $userId,
    $encryptedAccess,
    $encryptedRefresh,
    $expiresAt
);
$stmt-&gt;execute();
?&gt;</code></pre>

                <p class="small text-secondary">
                    <strong>Note on encryption:</strong> The <code>access_token</code>
                    itself is a JWT and already signed (not encrypted). The main reason
                    to encrypt it at rest is to prevent token disclosure if your
                    database is compromised. Use your backend&#39;s built-in encryption
                    (e.g. SIGNula&#39;s own <code>Crypto\AES256::encrypt()</code>, or
                    libsodium&#39;s <code>sodium_crypto_secretbox()</code>).
                </p>

                <!-- ============================================================ -->
                <!-- LOGOUT -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">6. Logout / token revocation</h2>
                <p>
                    When the user logs out, revoke their refresh token
                    (and the entire token family it represents):
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;?php
// /logout handler

$refreshToken = $_SESSION['refresh_token'] ?? null;

if ($refreshToken) {
    $clientId = getenv('SIGNULA_CLIENT_ID');
    $clientSecret = getenv('SIGNULA_CLIENT_SECRET');
    $basicAuth = base64_encode(&quot;{$clientId}:{$clientSecret}&quot;);

    $revokeRequest = [
        'token'           =&gt; $refreshToken,
        'token_type_hint' =&gt; 'refresh_token',
    ];

    // POST to revoke endpoint
    http_post_fields('https://signula.id/oauth/revoke', $revokeRequest, [
        'Authorization' =&gt; &quot;Basic {$basicAuth}&quot;,
        'Content-Type'  =&gt; 'application/x-www-form-urlencoded',
    ]);
    // Note: /oauth/revoke always returns 200 (even for unknown tokens).
    // This is intentional per RFC 7009 — no information about token validity
    // is leaked to an attacker.
}

// Clear session / database tokens
unset($_SESSION['user_id']);
unset($_SESSION['access_token']);
unset($_SESSION['refresh_token']);

header('Location: /');
?&gt;</code></pre>

                <!-- ============================================================ -->
                <!-- BEST PRACTICES -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Best practices for server backends</h2>
                <ul>
                    <li><strong>Use HTTP Basic auth for token endpoints</strong>
                        (RFC 6749 §2.3.1). It&#39;s simpler and more widely compatible
                        than body-based auth.</li>
                    <li><strong>Always verify the id_token signature</strong> before
                        trusting any claims. Never skip verification "for speed."</li>
                    <li><strong>Encrypt refresh tokens at rest</strong> if storing
                        them in a database or cache.</li>
                    <li><strong>Monitor refresh token failures.</strong> A spike in
                        failed refreshes may indicate token theft or an attacker
                        attempting to use a compromised refresh token.</li>
                    <li><strong>Implement a token refresh middleware / interceptor</strong>
                        that automatically refreshes the access token when it&#39;s
                        close to expiry (before it actually expires).</li>
                    <li><strong>Never log raw tokens</strong> to stdout or log files.
                        Log only the token&#39;s subject and expiry for audit purposes.</li>
                    <li><strong>Use <code>hash_equals()</code></strong> (or equivalent
                        in your language) to compare the <code>nonce</code> and
                        <code>state</code> values — protects against timing attacks.</li>
                </ul>

                <!-- ============================================================ -->
                <!-- CROSS-LINKS -->
                <!-- ============================================================ -->
                <div class="alert alert-secondary mt-5">
                    <strong>Full reference:</strong>
                    <a href="/docs/signin-with-signula" class="alert-link">Sign in with SIGNula.id</a>
                    (all parameters, error responses, UserInfo endpoint, OIDC spec).
                    <br>
                    <strong>For web SPAs?</strong>
                    <a href="/docs/oidc-web" class="alert-link">Web / SPA / PWA guide</a>.
                    <br>
                    <strong>For mobile apps?</strong>
                    <a href="/docs/oidc-native" class="alert-link">Native / Mobile guide</a>.
                    <br>
                    <strong>Security best practices:</strong>
                    <a href="/docs/oidc-security" class="alert-link">Security Checklist</a>.
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php'; ?>
