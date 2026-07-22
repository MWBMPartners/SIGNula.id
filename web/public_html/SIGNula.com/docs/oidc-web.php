<?php
/**
 * ============================================================================
 * 🌐 SIGNula OIDC — Web / SPA / PWA Integration Guide
 * ============================================================================
 * Platform-specific guidance for browser-based apps (client-side SPAs,
 * server-rendered pages, Progressive Web Apps). Covers the button,
 * PKCE flow, token storage, redirect handling, and PWA considerations.
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
$pageTitle = 'OIDC — Web / SPA / PWA Integration - SIGNula Documentation';
$metaDescription = 'Integrate "Sign in with SIGNula.id" in web apps (SPAs, PWAs, server-rendered). Button drop-in, redirect handling, secure token storage.';
$currentPage = 'docs';
$currentDoc = 'oidc-web';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>
<section class="section-padding">
    <div class="container">
        <div class="row">
            <div class="col-lg-3">
                <?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'docs-sidebar.php'; ?>
            </div>
            <div class="col-lg-9">
                <h1 class="display-5 fw-bold mb-4">Web / SPA / PWA Integration</h1>
                <p class="lead">
                    Add "Sign in with SIGNula.id" to your web app — whether
                    a React/Vue/Svelte SPA, server-rendered PHP/Node app, or
                    Progressive Web App.
                </p>

                <div class="alert alert-info">
                    <strong>This is the web guide.</strong> If you&#39;re building
                    a native iOS/Android app, see
                    <a href="/docs/oidc-native" class="alert-link">Native / Mobile</a>.
                    For server-backend details, see
                    <a href="/docs/oidc-server" class="alert-link">Server-side</a>.
                </div>

                <!-- ============================================================ -->
                <!-- DROP-IN BUTTON -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">The drop-in button</h2>
                <p>
                    The easiest way to start is the pre-built button that lives at
                    <code>/assets/signin-button/</code>. It handles PKCE generation,
                    state/nonce generation, and the redirect for you — just embed it
                    and implement the callback handler.
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;link rel="stylesheet" href="https://signula.id/assets/signin-button/signula-signin.css"&gt;

&lt;button type="button" class="signula-btn signula-btn--light"
        data-client-id="YOUR_CLIENT_ID"
        data-redirect-uri="https://app.example.com/auth/callback"&gt;
  &lt;span class="signula-btn__icon" aria-hidden="true"&gt;
    &lt;svg class="signula-btn__icon-svg" viewBox="0 0 100 100" width="20" height="20" aria-hidden="true" focusable="false"&gt;
      &lt;!-- icon markup from /assets/signin-button/README.md --&gt;
    &lt;/svg&gt;
  &lt;/span&gt;
  &lt;span class="signula-btn__label"&gt;Sign in with SIGNula.id&lt;/span&gt;
&lt;/button&gt;

&lt;script src="https://signula.id/assets/signin-button/signula-signin.js"&gt;&lt;/script&gt;</code></pre>

                <p class="small text-secondary">
                    <strong>Zero dependencies:</strong> No Bootstrap, jQuery, or build
                    step required. Two CSS/JS files, native Web Crypto API, ES2017+.
                    Full technical reference at
                    <a href="/assets/signin-button/README.md">/assets/signin-button/README.md</a>.
                </p>

                <h3 class="h5 mt-4">Button variants</h3>
                <p>
                    The button ships in four colour variants (all WCAG AA compliant):
                </p>
                <ul>
                    <li><code>signula-btn--light</code> &mdash; white background,
                        dark text, brand-coloured icon (default for light pages)</li>
                    <li><code>signula-btn--dark</code> &mdash; dark background,
                        light text/icon (default for dark-mode detection)</li>
                    <li><code>signula-btn--brand</code> &mdash; gradient fill,
                        white text/icon (standalone, eye-catching)</li>
                    <li><code>signula-btn--mono</code> &mdash; high-contrast, black-on-white
                        (no hue dependency; colour-blind-safe and accessible)</li>
                </ul>
                <p>
                    Also available: <code>--large</code> (52px), <code>--icon</code>
                    (icon-only), and auto dark-mode detection. See
                    <a href="/assets/signin-button/demo.html">/assets/signin-button/demo.html</a>
                    for a live gallery.
                </p>

                <!-- ============================================================ -->
                <!-- REDIRECT HANDLER -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Implement the redirect callback</h2>
                <p>
                    When the user signs in, SIGNula redirects back to your
                    <code>redirect_uri</code> (registered during client setup)
                    with <code>?code=...&amp;state=...</code> in the URL.
                </p>

                <h3 class="h5 mt-4">Step 1: Verify <code>state</code> (CSRF protection)</h3>
                <p>
                    <strong>Always verify <code>state</code> first, before anything else.</strong>
                    The button stored it in <code>sessionStorage</code> under the key
                    <code>signula:pkce:&lt;state&gt;</code>.
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;?php
// At /auth/callback
$returnedState = $_GET['state'] ?? null;
if (!$returnedState) {
    die('Missing state parameter — CSRF check failed');
}

// Retrieve the stored state + nonce + codeVerifier from sessionStorage
// (The button stores: sessionStorage["signula:pkce:&lt;state&gt;"] = JSON {...})
// You&#39;ll read this JavaScript-side and pass it to your backend.
?&gt;</code></pre>

                <p>
                    <strong>For SPAs / browser-only apps:</strong> The state/nonce/verifier
                    live in <code>sessionStorage</code> — read them on the client and
                    pass to a tiny backend endpoint that performs the token exchange.
                </p>

                <pre class="bg-dark text-white p-3 rounded"><code>// JavaScript at /auth/callback (or wherever you land after the redirect)
const urlParams = new URLSearchParams(window.location.search);
const code  = urlParams.get('code');
const state = urlParams.get('state');

if (!code || !state) {
    alert('Missing code or state');
    window.location = '/';
}

const stored = JSON.parse(
    sessionStorage.getItem(`signula:pkce:${state}`)
);

if (!stored) {
    alert('State mismatch — CSRF check failed');
    window.location = '/';
}

const { codeVerifier, nonce, redirectUri, clientId } = stored;

// POST the code + verifier to your backend for exchange
fetch('/api/auth/exchange', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        code, codeVerifier, nonce, redirectUri, clientId
    })
})
.then(r =&gt; r.json())
.then(({ accessToken, idToken }) =&gt; {
    // Store tokens securely (see next section)
    sessionStorage.removeItem(`signula:pkce:${state}`);  // clean up
    window.location = '/dashboard';
})
.catch(err =&gt; {
    console.error(err);
    window.location = '/auth/error';
});</code></pre>

                <!-- ============================================================ -->
                <!-- TOKEN STORAGE -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Secure token storage</h2>
                <div class="alert alert-warning">
                    <strong>⚠️ Never store tokens in <code>localStorage</code>.</strong>
                    It&#39;s vulnerable to XSS (any script on the page can read it).
                    Instead, use one of these patterns:
                </div>

                <h3 class="h5 mt-4">Option A: httpOnly cookie (recommended for most apps)</h3>
                <p>
                    Your backend receives the <code>code</code> + <code>code_verifier</code>
                    from the frontend, exchanges it for tokens, and sets them in an
                    <code>httpOnly</code>, <code>Secure</code>, <code>SameSite=Strict</code> cookie.
                    The browser automatically includes it with every request, and
                    JavaScript can never access it.
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;?php
// Backend token-exchange endpoint (/api/auth/exchange)

$code        = $_POST['code'] ?? $_GET['code'];
$codeVerifier = $_POST['code_verifier'] ?? $_GET['code_verifier'];
$nonce       = $_POST['nonce'];

// Exchange code for tokens (see /docs/signin-with-signula section 3)
$tokens = exchangeCode($code, $codeVerifier);

// Set httpOnly cookie (inaccessible to JavaScript)
setcookie(
    'signula_auth',
    $tokens['access_token'],
    [
        'expires'  =&gt; time() + 900,  // 15 min (matches token expiry)
        'path'     =&gt; '/',
        'domain'   =&gt; 'app.example.com',
        'secure'   =&gt; true,          // HTTPS only
        'httponly' =&gt; true,          // NO JavaScript access
        'samesite' =&gt; 'Strict',      // CSRF protection
    ]
);

// Also set the id_token so you can verify claims if needed
setcookie(
    'signula_id',
    $tokens['id_token'],
    [ /* same options */ ]
);

http_response_code(200);
echo json_encode(['success' =&gt; true]);
?&gt;</code></pre>

                <h3 class="h5 mt-4">Option B: In-memory + refresh (for pure SPAs)</h3>
                <p>
                    Store tokens in a JavaScript variable (lost on page reload, but
                    your <code>refresh_token</code> keeps you logged in). On page load,
                    check if you have a refresh token, and silently refresh the access token.
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>// SPA token manager (pseudo-code)

class TokenManager {
  constructor() {
    this.accessToken = null;      // in-memory only
    this.refreshToken = null;     // stored securely (Secure cookie or IndexedDB)
  }

  async refreshAccessToken() {
    const response = await fetch('https://signula.id/oauth/token', {
      method: 'POST',
      body: new URLSearchParams({
        grant_type: 'refresh_token',
        refresh_token: this.refreshToken,
      }),
      headers: {
        'Authorization': 'Basic ' + btoa(clientId + ':'),
      }
    });
    const { access_token, refresh_token } = await response.json();
    this.accessToken = access_token;
    if (refresh_token) this.refreshToken = refresh_token; // rotation
    return this.accessToken;
  }

  async getAccessToken() {
    if (!this.accessToken) {
      return this.refreshAccessToken();
    }
    return this.accessToken;
  }
}

// On page load, restore and refresh silently
const tokens = new TokenManager();
tokens.refreshToken = readRefreshTokenFromCookie();
await tokens.refreshAccessToken();
</code></pre>

                <!-- ============================================================ -->
                <!-- PWA / SERVICE WORKER -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Progressive Web Apps (PWAs)</h2>
                <p>
                    If your app is a PWA (has a service worker), take care during
                    the OAuth redirect back from SIGNula:
                </p>
                <ul>
                    <li><strong>The redirect must open in the main window,</strong>
                        not intercepted/cached by the service worker. Ensure your
                        service worker does NOT cache the redirect endpoint
                        (<code>/auth/callback</code>) or the OAuth authorize/token
                        endpoints.</li>
                    <li><strong>Use versioned service workers:</strong> When you
                        update your app, bump the service worker version so new
                        clients fetch the latest code.</li>
                    <li><strong>Token refresh in the background:</strong> Periodically
                        refresh your access token in the service worker before it
                        expires — the user will stay logged in even across page reloads
                        or app restarts.</li>
                </ul>

                <!-- ============================================================ -->
                <!-- ERROR HANDLING -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Error handling</h2>
                <p>
                    When the user denies consent or an error occurs, SIGNula
                    redirects back with error parameters:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>https://app.example.com/auth/callback
  ?error=access_denied
  &amp;error_description=User%20denied%20consent
  &amp;state=STATE_FROM_STEP_1</code></pre>
                <p>
                    Always check for <code>?error</code> before reading <code>?code</code>:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>const urlParams = new URLSearchParams(window.location.search);

if (urlParams.has('error')) {
    const error = urlParams.get('error');
    const desc = urlParams.get('error_description');
    console.error(`Auth failed: ${error} — ${desc}`);
    window.location = '/auth/error?reason=' + encodeURIComponent(error);
} else if (urlParams.has('code')) {
    // Proceed with token exchange...
}</code></pre>

                <!-- ============================================================ -->
                <!-- LOGOUT / REVOKE -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Logout / token revocation</h2>
                <p>
                    When the user clicks "Log Out", revoke their tokens:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>// Backend logout handler
async function logout(req, res) {
  const refreshToken = getCookieOrStoredValue('signula_refresh');

  if (refreshToken) {
    // Revoke the refresh token (and its entire token family)
    await fetch('https://signula.id/oauth/revoke', {
      method: 'POST',
      body: new URLSearchParams({
        token: refreshToken,
        token_type_hint: 'refresh_token',
      }),
      headers: {
        'Authorization': 'Basic ' + btoa(clientId + ':' + clientSecret),
      }
    });
  }

  // Clear cookies / session
  res.clearCookie('signula_auth');
  res.clearCookie('signula_id');
  res.redirect('/');
}
</code></pre>

                <!-- ============================================================ -->
                <!-- CROSS-LINKS -->
                <!-- ============================================================ -->
                <div class="alert alert-secondary mt-5">
                    <strong>Full reference:</strong>
                    <a href="/docs/signin-with-signula" class="alert-link">Sign in with SIGNula.id</a>
                    (all parameters, token claims, refresh flow, security).
                    <br>
                    <strong>Button gallery:</strong>
                    <a href="/assets/signin-button/demo.html" class="alert-link">/assets/signin-button/demo.html</a>.
                    <br>
                    <strong>For mobile?</strong>
                    <a href="/docs/oidc-native" class="alert-link">Native / Mobile guide</a>.
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php'; ?>
