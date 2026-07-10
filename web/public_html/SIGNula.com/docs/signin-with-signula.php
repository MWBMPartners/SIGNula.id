<?php
/**
 * ============================================================================
 * 🪪 SIGNula.com Docs — "Sign in with SIGNula.id" (G-001 OIDC Provider) Guide
 * ============================================================================
 * Partner integration guide for Relying Parties (RPs) that want to let their
 * users sign in with a SIGNula ID, using SIGNula as the OAuth 2.0 / OpenID
 * Connect PROVIDER (as opposed to SIGNula consuming Google/Microsoft/etc,
 * which is the existing "OAuth Account Linking" surface documented in
 * api-reference.php).
 *
 * This page follows the same layout/skeleton as its siblings
 * (getting-started.php / integration-guide.php / api-reference.php /
 * security.php / faq.php): $pageTitle/$metaDescription/$currentPage/
 * $currentDoc set, then public-header.php, a docs-sidebar.php + content
 * two-column section, then public-footer.php.
 *
 * NOTE (docs-only change — see task scope): this page is intentionally NOT
 * wired into web/private_html/layout/docs-sidebar.php's nav list or
 * docs/index.php's card grid — both are SHARED layout files consumed by
 * every docs page and were out of scope for this docs-only change. The page
 * is fully reachable directly at /docs/signin-with-signula. Add a
 * docs-sidebar.php nav-link (mirroring its existing "API Reference" /
 * "Security" entries) and an docs/index.php quick-link card in a follow-up
 * pass.
 *
 * Content here is verified against the ACTUAL G-001 endpoint implementations
 * (web/public_html/oauth/authorize-idp.php, token.php, userinfo.php,
 * revoke.php + web/public_html/.well-known/openid-configuration/index.php
 * and their web/private_html/auth/OAuth*.php service classes) — not a
 * generic OAuth tutorial. Cross-reference _docs/setup/SECURITY_SETUP.md's
 * "OIDC Provider (G-001)" section for the server-operator side of this same
 * feature (settings, key rotation, pairwise-subject internals).
 *
 * @package    SIGNula
 * @subpackage Documentation
 * @version    1.0.0
 * @since      2.9.0-beta (G-001)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */
$pageTitle = 'Sign in with SIGNula.id - SIGNula Documentation';
$metaDescription = 'Integrate "Sign in with SIGNula.id" — register an OAuth/OIDC client, run the Authorization Code + PKCE flow, verify the id_token, and call the UserInfo endpoint.';
$currentPage = 'docs';
$currentDoc = 'signin-with-signula';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>
<section class="section-padding">
    <div class="container">
        <div class="row">
            <div class="col-lg-3">
                <?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'docs-sidebar.php'; ?>
            </div>
            <div class="col-lg-9">
                <h1 class="display-5 fw-bold mb-4">Sign in with SIGNula.id</h1>
                <p class="lead">
                    Let your users sign in with their SIGNula ID using standard
                    OAuth 2.0 Authorization Code + PKCE and OpenID Connect —
                    SIGNula acting as your Identity Provider.
                </p>

                <div class="alert alert-info">
                    <strong>Issuer:</strong> <code>https://signula.id</code> &middot;
                    <strong>Discovery document:</strong>
                    <code>GET https://signula.id/.well-known/openid-configuration</code>
                </div>

                <div class="alert alert-warning">
                    <strong>Getting a 404 on every endpoint below?</strong>
                    The provider ships with a master switch (<code>oidc.enabled</code>)
                    OFF by default. Ask your SIGNula operator to confirm it has been
                    turned on — see the &ldquo;OIDC Provider (G-001)&rdquo; section of
                    <code>SECURITY_SETUP.md</code>.
                </div>

                <div class="alert alert-secondary">
                    <strong>Not the flow you're looking for?</strong> This page covers
                    SIGNula acting as the <em>Identity Provider</em> for YOUR app. If
                    instead you want SIGNula's own accounts to sign in <em>via</em>
                    Google/Microsoft/Apple/etc, see the
                    <a href="/docs/api-reference#oauth">OAuth Account Linking</a>
                    endpoints instead — that is the opposite direction.
                </div>

                <!-- ============================================================ -->
                <!-- 0. DROP-IN BUTTON (IB1) -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3" id="button">Drop-in button</h2>
                <p>
                    Don't want to hand-roll Step 1/2 below yourself? A
                    framework-free, copy-paste button that runs the whole
                    Authorization Code + PKCE redirect for you (no
                    Bootstrap/jQuery/build-step dependency — safe to embed on
                    any site) ships at
                    <code>/assets/signin-button/</code>. It's rendered below
                    using the real button stylesheet (link only — the click
                    handler script is intentionally omitted on this page so
                    the example isn't wired to a live, unregistered
                    <code>client_id</code>):
                </p>
                <link rel="stylesheet" href="/assets/signin-button/signula-signin.css">
                <div class="card mb-3">
                    <div class="card-body d-flex flex-wrap align-items-center gap-3">
                        <button type="button" class="signula-btn signula-btn--light" tabindex="-1" aria-hidden="true">
                            <span class="signula-btn__icon" aria-hidden="true">
                                <svg class="signula-btn__icon-svg" viewBox="0 0 100 100" width="20" height="20" aria-hidden="true" focusable="false">
                                    <g class="signula-btn__icon-key">
                                        <circle class="signula-btn__icon-ring" cx="17" cy="50" r="9"/>
                                        <rect class="signula-btn__icon-fill" x="26" y="47" width="34" height="7" rx="1.5"/>
                                        <rect class="signula-btn__icon-fill" x="46" y="54" width="5" height="6"/>
                                        <rect class="signula-btn__icon-fill" x="54" y="54" width="6" height="9"/>
                                    </g>
                                    <g class="signula-btn__icon-lock">
                                        <rect class="signula-btn__icon-fill" x="60" y="18" width="28" height="64" rx="8"/>
                                        <circle class="signula-btn__icon-punch" cx="70" cy="48" r="6"/>
                                        <path class="signula-btn__icon-punch" d="M 64.5 49 L 75.5 49 L 72 63 L 68 63 Z"/>
                                    </g>
                                    <g class="signula-btn__icon-check">
                                        <circle class="signula-btn__icon-punch" cx="80" cy="75" r="13"/>
                                        <circle class="signula-btn__icon-badge-ring" cx="80" cy="75" r="13"/>
                                        <path class="signula-btn__icon-tick" d="M 73.5 75 L 78.5 80 L 88.5 67.5"/>
                                    </g>
                                </svg>
                            </span>
                            <span class="signula-btn__label">Sign in with SIGNula.id</span>
                        </button>
                        <button type="button" class="signula-btn signula-btn--brand" tabindex="-1" aria-hidden="true">
                            <span class="signula-btn__icon" aria-hidden="true">
                                <svg class="signula-btn__icon-svg" viewBox="0 0 100 100" width="20" height="20" aria-hidden="true" focusable="false">
                                    <g class="signula-btn__icon-key">
                                        <circle class="signula-btn__icon-ring" cx="17" cy="50" r="9"/>
                                        <rect class="signula-btn__icon-fill" x="26" y="47" width="34" height="7" rx="1.5"/>
                                        <rect class="signula-btn__icon-fill" x="46" y="54" width="5" height="6"/>
                                        <rect class="signula-btn__icon-fill" x="54" y="54" width="6" height="9"/>
                                    </g>
                                    <g class="signula-btn__icon-lock">
                                        <rect class="signula-btn__icon-fill" x="60" y="18" width="28" height="64" rx="8"/>
                                        <circle class="signula-btn__icon-punch" cx="70" cy="48" r="6"/>
                                        <path class="signula-btn__icon-punch" d="M 64.5 49 L 75.5 49 L 72 63 L 68 63 Z"/>
                                    </g>
                                    <g class="signula-btn__icon-check">
                                        <circle class="signula-btn__icon-punch" cx="80" cy="75" r="13"/>
                                        <circle class="signula-btn__icon-badge-ring" cx="80" cy="75" r="13"/>
                                        <path class="signula-btn__icon-tick" d="M 73.5 75 L 78.5 80 L 88.5 67.5"/>
                                    </g>
                                </svg>
                            </span>
                            <span class="signula-btn__label">Sign in with SIGNula.id</span>
                        </button>
                    </div>
                </div>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;link rel="stylesheet" href="https://signula.id/assets/signin-button/signula-signin.css"&gt;

&lt;button type="button" class="signula-btn signula-btn--light"
        data-client-id="YOUR_CLIENT_ID"
        data-redirect-uri="https://app.example.com/auth/callback"&gt;
  &lt;!-- icon markup — full snippet in /assets/signin-button/README.md --&gt;
  &lt;span class="signula-btn__label"&gt;Sign in with SIGNula.id&lt;/span&gt;
&lt;/button&gt;

&lt;script src="https://signula.id/assets/signin-button/signula-signin.js"&gt;&lt;/script&gt;</code></pre>
                <p class="small text-secondary">
                    See <code>/assets/signin-button/README.md</code> for the
                    full copy-paste snippet, every <code>data-*</code>
                    option, all variants (<code>--light</code>/
                    <code>--dark</code>/<code>--brand</code>/
                    <code>--mono</code> colour-blind-safe) and sizes, and
                    <code>/assets/signin-button/demo.html</code> for a live
                    visual gallery. The button runs exactly Step 1 and 2
                    below for you (PKCE generation + the
                    <code>/oauth/authorize-idp</code> redirect) — you still
                    implement Steps 3&ndash;5 (code exchange, token
                    verification, UserInfo) yourself, in your
                    <code>redirect_uri</code> handler.
                </p>

                <!-- ============================================================ -->
                <!-- 1. REGISTER A CLIENT -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3" id="register">1. Register your application</h2>
                <p>
                    Every Relying Party (RP) must be registered before it can use
                    the flow below. Registration is self-service from your
                    partner dashboard:
                </p>
                <ol>
                    <li>Sign in to your Partner account and open
                        <strong>Dashboard &rarr; OAuth / OIDC Clients</strong>
                        (<code>/partners/admin/oauth-clients</code>).</li>
                    <li>Click <strong>Register New OAuth Client</strong> and fill in:
                        <ul>
                            <li><strong>Client Name</strong> &mdash; shown to your users on the consent screen.</li>
                            <li><strong>Client Type</strong> &mdash;
                                <code>confidential</code> for a server-side app (you
                                will receive a <code>client_secret</code>), or
                                <code>public</code> for a SPA / native / mobile app
                                (PKCE is your only proof of identity &mdash; no
                                usable secret is ever issued).</li>
                            <li><strong>Redirect URIs</strong> &mdash; one per line.
                                These must match <strong>byte-for-byte</strong> at
                                sign-in time &mdash; no wildcard, no trailing-slash
                                or scheme normalisation. Register every exact URI
                                you will actually use (including any per-environment
                                variants).</li>
                            <li><strong>Scopes</strong> &mdash; <code>openid</code> is
                                always included. Tick <code>profile</code> /
                                <code>email</code> / <code>offline_access</code>
                                (issues a refresh token) as needed.</li>
                            <li><strong>Advanced &rarr; Subject type</strong> &mdash;
                                leave on <code>pairwise</code> (the default and
                                recommended setting): your app receives a stable,
                                per-app opaque user identifier that cannot be
                                correlated with the same user's identifier at a
                                <em>different</em> RP.</li>
                        </ul>
                    </li>
                    <li>Submit the form. Your <code>client_id</code> and (for
                        confidential clients) <code>client_secret</code> are shown
                        <strong>exactly once</strong> &mdash; copy both immediately.
                        SIGNula stores only a salted hash of the secret and can
                        never display the plaintext again. If it is lost, rotate it
                        from the same page (the old secret is invalidated the
                        instant a new one is issued).</li>
                </ol>

                <!-- ============================================================ -->
                <!-- 2. AUTHORIZATION CODE + PKCE FLOW -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3" id="flow">2. Run the Authorization Code + PKCE flow</h2>
                <p>
                    PKCE (RFC 7636, <code>S256</code> only) is <strong>mandatory</strong>
                    on every request &mdash; always for public clients, and for
                    confidential clients too under the default server policy.
                </p>

                <h3 class="h5 mt-4">Step 1 &mdash; Generate <code>code_verifier</code> / <code>code_challenge</code></h3>
                <p>
                    <code>code_verifier</code> is a high-entropy random string
                    (43&ndash;128 characters, unreserved base64url charset).
                    <code>code_challenge</code> is
                    <code>BASE64URL( SHA256( code_verifier ) )</code>, unpadded.
                    Generate a fresh <code>state</code> and <code>nonce</code> too
                    &mdash; both are opaque values <em>you</em> mint and must verify
                    on return (CSRF and replay protection respectively).
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>// Browser / SPA example (Web Crypto API)
function base64url(buffer) {
  return btoa(String.fromCharCode(...new Uint8Array(buffer)))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

const verifierBytes = crypto.getRandomValues(new Uint8Array(32));
const codeVerifier = base64url(verifierBytes);          // store for Step 4
const challengeBuf = await crypto.subtle.digest(
  'SHA-256', new TextEncoder().encode(codeVerifier)
);
const codeChallenge = base64url(challengeBuf);

const state = base64url(crypto.getRandomValues(new Uint8Array(16)));
const nonce = base64url(crypto.getRandomValues(new Uint8Array(16)));

// Persist codeVerifier + state + nonce for this browser session
// (sessionStorage, or a server-side session for a confidential client) —
// you will need all three again after the redirect back.</code></pre>

                <h3 class="h5 mt-4">Step 2 &mdash; Redirect the user to the authorization endpoint</h3>
                <pre class="bg-dark text-white p-3 rounded"><code>GET https://signula.id/oauth/authorize-idp
  ?client_id=YOUR_CLIENT_ID
  &redirect_uri=https%3A%2F%2Fapp.example.com%2Fauth%2Fcallback
  &response_type=code
  &scope=openid%20profile%20email%20offline_access
  &state=STATE_FROM_STEP_1
  &code_challenge=CHALLENGE_FROM_STEP_1
  &code_challenge_method=S256
  &nonce=NONCE_FROM_STEP_1</code></pre>
                <p>
                    The user is shown a SIGNula consent screen (skipped
                    automatically on a repeat sign-in with an already-covering,
                    non-revoked consent, unless you add <code>&amp;prompt=consent</code>
                    to force it). On <strong>Allow</strong>, SIGNula issues a
                    single-use authorization code and redirects back to your
                    <code>redirect_uri</code>:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>302 Found
Location: https://app.example.com/auth/callback?code=AUTH_CODE&amp;state=STATE_FROM_STEP_1</code></pre>
                <p>
                    On <strong>Deny</strong> (or any other failure past the point a
                    safe redirect target was established), you instead receive
                    <code>?error=access_denied&amp;error_description=...&amp;state=...</code>.
                </p>

                <h3 class="h5 mt-4">Step 3 &mdash; Verify <code>state</code>, then exchange the code</h3>
                <p>
                    <strong>Before doing anything else</strong>, confirm the
                    returned <code>state</code> matches exactly what you generated
                    in Step 1 &mdash; reject the callback outright if it does not.
                    Then exchange the code server-side:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>POST https://signula.id/oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&amp;code=AUTH_CODE
&amp;redirect_uri=https%3A%2F%2Fapp.example.com%2Fauth%2Fcallback
&amp;code_verifier=VERIFIER_FROM_STEP_1</code></pre>
                <p>
                    <strong>Confidential clients</strong> additionally authenticate
                    with HTTP Basic (preferred) or body fields:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code># HTTP Basic (client_secret_basic) — preferred
curl -s https://signula.id/oauth/token \
  -u "YOUR_CLIENT_ID:YOUR_CLIENT_SECRET" \
  -d grant_type=authorization_code \
  -d code=AUTH_CODE \
  -d redirect_uri=https://app.example.com/auth/callback \
  -d code_verifier=VERIFIER_FROM_STEP_1</code></pre>
                <p>
                    <strong>Public clients</strong> send <code>client_id</code> only
                    (no secret exists) &mdash; PKCE is the proof of possession.
                </p>
                <p>A successful exchange returns:</p>
                <pre class="bg-dark text-white p-3 rounded"><code>{
  "access_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6ImF0K2p3dCIsImtpZCI6Ii4uLiJ9...",
  "token_type": "Bearer",
  "expires_in": 900,
  "scope": "openid profile email offline_access",
  "id_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...",
  "refresh_token": "a3f8c2d1e4b5..."
}</code></pre>
                <p class="small text-secondary">
                    <code>refresh_token</code> is present only if you requested
                    <em>and</em> were granted <code>offline_access</code>. The code
                    is single-use &mdash; replaying it revokes the whole token
                    family it originally minted (security incident, not a
                    convenience for retries).
                </p>

                <!-- ============================================================ -->
                <!-- 3. VERIFY THE ID TOKEN -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3" id="verify">3. Verify the <code>id_token</code></h2>
                <p>
                    The <code>id_token</code> is an RS256-signed JWT. Verify it
                    against SIGNula's published public keys before trusting any of
                    its claims:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>GET https://signula.id/.well-known/jwks.json</code></pre>
                <p>Check, in order:</p>
                <ol>
                    <li>The signature verifies against the key matching the
                        token's <code>kid</code> header, using <strong>RS256
                        only</strong> (reject any other <code>alg</code>).</li>
                    <li><code>iss</code> equals exactly <code>https://signula.id</code>.</li>
                    <li><code>aud</code> equals exactly <em>your</em> <code>client_id</code>.</li>
                    <li><code>exp</code> has not passed (allow a small clock-skew
                        leeway, e.g. 60 seconds).</li>
                    <li><code>nonce</code> equals exactly the value you generated
                        in Step 1 &mdash; reject the token if it is missing or does
                        not match.</li>
                    <li><code>sub</code> is your app's <strong>pairwise</strong>
                        (per-client, opaque, salted) identifier for this user by
                        default &mdash; treat it as a stable primary key for this
                        user <em>within your app only</em>. It will legitimately be
                        a <em>different</em> value than the <code>sub</code> another
                        RP sees for the same human being &mdash; that is the
                        intended privacy property, not a bug.</li>
                </ol>
                <p>Example verification (PHP, using the same <code>firebase/php-jwt</code> library SIGNula itself vendors):</p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;?php
$jwks = json_decode(file_get_contents('https://signula.id/.well-known/jwks.json'), true);
$keySet = Firebase\JWT\JWK::parseKeySet($jwks);
$claims = Firebase\JWT\JWT::decode($idToken, $keySet); // throws on bad signature/exp

if ($claims->iss !== 'https://signula.id') { throw new RuntimeException('bad iss'); }
if ($claims->aud !== $yourClientId)        { throw new RuntimeException('bad aud'); }
if (!hash_equals($expectedNonce, $claims->nonce ?? '')) { throw new RuntimeException('bad nonce'); }

$pairwiseUserId = $claims->sub; // your local key for this user
?&gt;</code></pre>

                <!-- ============================================================ -->
                <!-- 4. USERINFO -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3" id="userinfo">4. Call the UserInfo endpoint (optional)</h2>
                <p>
                    If the <code>id_token</code>'s claims are enough for your app,
                    you can skip this step. Otherwise, fetch the same (and only
                    the same) claims your granted scope covers:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>curl -s https://signula.id/oauth/userinfo \
  -H "Authorization: Bearer ACCESS_TOKEN"</code></pre>
                <pre class="bg-dark text-white p-3 rounded"><code>{
  "sub": "3f9b1c8e2a7d4f6b0c1e5a8d9f2b3c4e...",
  "email": "user@example.com",
  "email_verified": true,
  "name": "Jane Doe",
  "given_name": "Jane",
  "family_name": "Doe",
  "preferred_username": "janedoe",
  "picture": "https://signula.id/avatar/3f9b1c8e2a7d/256"
}</code></pre>
                <p class="small text-secondary">
                    A token minted with <code>openid</code> alone returns
                    <code>sub</code> and nothing else &mdash; request
                    <code>email</code>/<code>profile</code> at Step 2 if your app
                    needs those fields.
                </p>

                <!-- ============================================================ -->
                <!-- 5. REFRESH + REVOKE -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3" id="refresh-revoke">5. Refresh and revoke tokens</h2>

                <h3 class="h5 mt-4">Refreshing an access token</h3>
                <p>Only possible if you were granted <code>offline_access</code> and received a <code>refresh_token</code>:</p>
                <pre class="bg-dark text-white p-3 rounded"><code>curl -s https://signula.id/oauth/token \
  -u "YOUR_CLIENT_ID:YOUR_CLIENT_SECRET" \
  -d grant_type=refresh_token \
  -d refresh_token=REFRESH_TOKEN</code></pre>
                <p class="small text-secondary">
                    Single-use rotation: the response contains a NEW
                    <code>refresh_token</code>; the one you presented is now spent.
                    A new <code>id_token</code> is <strong>not</strong> re-minted on
                    refresh (OIDC Core does not require it) &mdash; a token you
                    already verified in Step 3 remains valid until its own
                    <code>exp</code>. Replaying an already-spent or revoked refresh
                    token is treated as a security incident: your entire token
                    family is revoked and both requester and legitimate holder are
                    signed out.
                </p>

                <h3 class="h5 mt-4">Revoking a token (e.g. on your user's logout)</h3>
                <pre class="bg-dark text-white p-3 rounded"><code>curl -s https://signula.id/oauth/revoke \
  -u "YOUR_CLIENT_ID:YOUR_CLIENT_SECRET" \
  -d token=REFRESH_TOKEN \
  -d token_type_hint=refresh_token</code></pre>
                <p class="small text-secondary">
                    Per RFC 7009, this always returns <code>200</code> with an
                    empty body once your client itself authenticates &mdash; an
                    unknown or already-revoked token is indistinguishable from a
                    fresh one, by design (no information is ever leaked about
                    token validity). You may only ever revoke a token that
                    belongs to your own client.
                </p>

                <!-- ============================================================ -->
                <!-- 6. SECURITY REQUIREMENTS -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3" id="security">6. Security requirements checklist</h2>
                <div class="card mb-4">
                    <div class="card-body">
                        <ul class="mb-0">
                            <li><strong>Register the exact <code>redirect_uri</code>(s)</strong>
                                you will send &mdash; matching is byte-for-byte, with
                                no normalisation. A mismatched trailing slash, an
                                extra query parameter, or a different scheme/port
                                will all be rejected.</li>
                            <li><strong>PKCE (<code>S256</code>) is mandatory</strong> on
                                every authorization request. Do not fall back to
                                <code>plain</code> &mdash; it is rejected by default
                                and is not a supported production configuration.</li>
                            <li><strong>Always generate, send, and verify
                                <code>state</code></strong> &mdash; your CSRF defence
                                against a forged callback.</li>
                            <li><strong>Always generate, send, and verify
                                <code>nonce</code></strong> &mdash; your defence
                                against a replayed/substituted <code>id_token</code>.</li>
                            <li><strong>Confidential-client secrets are server-side
                                only</strong> &mdash; never embed a
                                <code>client_secret</code> in a SPA, a mobile app
                                binary, or any client you cannot fully control.
                                Register those as <code>public</code> clients and
                                rely on PKCE instead.</li>
                            <li><strong>Verify the <code>id_token</code> signature
                                and every claim</strong> listed in Section 3 before
                                trusting it &mdash; never skip signature
                                verification "for now."</li>
                            <li><strong>Treat <code>sub</code> as opaque and
                                app-local</strong> &mdash; do not expect it to match
                                across different RPs, and do not attempt to reverse
                                it back to a SIGNula internal user ID.</li>
                        </ul>
                    </div>
                </div>

                <div class="alert alert-success mt-5">
                    <strong>Full parameter/response reference:</strong> see the
                    <a href="/api/docs/openapi.yaml" class="alert-link">OpenAPI
                    specification</a> (<code>OIDC Provider</code> tag) for every
                    parameter, request/response schema, and error shape across
                    all five endpoints.
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php'; ?>
