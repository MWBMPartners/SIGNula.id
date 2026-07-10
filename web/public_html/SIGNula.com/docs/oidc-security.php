<?php
/**
 * ============================================================================
 * 🔒 SIGNula OIDC — Security Best Practices & Checklist
 * ============================================================================
 * Security requirements, common pitfalls, and advanced hardening techniques
 * for OAuth2/OIDC integrations. Complements the full reference guide at
 * /docs/signin-with-signula.
 *
 * @package    SIGNula
 * @subpackage Documentation
 * @version    1.0.0
 * @since      2.9.0-beta (G-001)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */
$pageTitle = 'OIDC Security Checklist - SIGNula Documentation';
$metaDescription = 'Security best practices for "Sign in with SIGNula.id" integration. PKCE, state, nonce, redirect validation, token handling, common pitfalls.';
$currentPage = 'docs';
$currentDoc = 'oidc-security';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>
<section class="section-padding">
    <div class="container">
        <div class="row">
            <div class="col-lg-3">
                <?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'docs-sidebar.php'; ?>
            </div>
            <div class="col-lg-9">
                <h1 class="display-5 fw-bold mb-4">OIDC Security Checklist</h1>
                <p class="lead">
                    Security requirements and common pitfalls for SIGNula OIDC
                    integration. Use this checklist before taking your app to
                    production and when reviewing OAuth implementations.
                </p>

                <div class="alert alert-info">
                    <strong>This is a security-focused guide.</strong> It assumes
                    you&#39;ve already read the full
                    <a href="/docs/signin-with-signula" class="alert-link">Sign in with SIGNula.id</a>
                    reference. For platform-specific examples (web/mobile/server),
                    see the integration guides.
                </div>

                <!-- ============================================================ -->
                <!-- REGISTRATION CHECKLIST -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Client registration checklist</h2>
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-exact-uri">
                            <label class="form-check-label" for="check-exact-uri">
                                <strong>Exact redirect URI(s)</strong> — No wildcards,
                                trailing slashes, or path fragments. Each environment
                                (dev, staging, prod) has its own exact registration.
                                Test by pasting the exact URI from your app into the
                                authorization request.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-https">
                            <label class="form-check-label" for="check-https">
                                <strong>HTTPS only</strong> (or <code>http://localhost</code>
                                for dev). Non-secure origins are rejected at registration time.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-client-type">
                            <label class="form-check-label" for="check-client-type">
                                <strong>Correct client type:</strong>
                                <code>confidential</code> if your app has a backend,
                                <code>public</code> if it&#39;s a browser SPA or native app
                                (no client secret is ever issued for public clients).
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-scopes">
                            <label class="form-check-label" for="check-scopes">
                                <strong>Minimal scopes:</strong> Request only what you need
                                (<code>openid email profile offline_access</code> are common).
                                Users are shown every scope you request.
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="check-subject-type">
                            <label class="form-check-label" for="check-subject-type">
                                <strong>Subject type:</strong> Use <code>pairwise</code>
                                (default and recommended). Do NOT switch to <code>public</code>
                                unless you have a specific reason — pairwise prevents user
                                correlation across apps.
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- PKCE CHECKLIST -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">PKCE (Proof Key for Code Exchange) checklist</h2>
                <div class="alert alert-danger">
                    <strong>⚠️ PKCE is mandatory and non-negotiable.</strong> Always
                    generate it, always use <code>S256</code> (never <code>plain</code>),
                    always verify the returned code matches your verifier.
                </div>
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-verifier-entropy">
                            <label class="form-check-label" for="check-verifier-entropy">
                                <strong>High-entropy code_verifier:</strong> 43–128 characters,
                                base64url-encoded random bytes (or equivalent). Use
                                <code>crypto.getRandomValues()</code> (browser),
                                <code>random_bytes()</code> (PHP), or your language&#39;s
                                cryptographically secure RNG — <strong>never</strong>
                                Math.random() or predictable sequences.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-s256">
                            <label class="form-check-label" for="check-s256">
                                <strong>Use S256 only:</strong>
                                <code>code_challenge = BASE64URL(SHA256(code_verifier))</code>.
                                Never send <code>code_challenge_method=plain</code> — it&#39;s
                                rejected by default at the SIGNula server.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-verifier-storage">
                            <label class="form-check-label" for="check-verifier-storage">
                                <strong>Store the verifier securely:</strong> For browser apps,
                                use <code>sessionStorage</code> (lost on page close, HTTPS only).
                                For backends, use the session or a secure cache (expires after
                                a few minutes). Never log it or expose it in URLs.
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="check-verifier-use">
                            <label class="form-check-label" for="check-verifier-use">
                                <strong>Verifier is single-use:</strong> Once you exchange the code
                                using the verifier, do not reuse it. If you need to retry the
                                token exchange, generate a fresh code and verifier (and restart
                                the authorization flow).
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- STATE & NONCE CHECKLIST -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">State & Nonce checklist (CSRF + replay protection)</h2>
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-state-gen">
                            <label class="form-check-label" for="check-state-gen">
                                <strong>Generate fresh state:</strong> Every authorization
                                request gets a new, random <code>state</code> value (16+ bytes).
                                Do NOT reuse state values across sign-in attempts.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-state-verify">
                            <label class="form-check-label" for="check-state-verify">
                                <strong>Verify state on every callback:</strong> Extract
                                <code>state</code> from the query parameter and check it
                                matches exactly what you sent. <strong>Reject the callback
                                if state is missing or does not match</strong> — this is your
                                CSRF defence. Do this <strong>before</strong> reading any other
                                parameter.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-state-timing">
                            <label class="form-check-label" for="check-state-timing">
                                <strong>State timing check:</strong> If you store state with
                                a <code>created_at</code> timestamp, reject it if it&#39;s
                                older than 10–15 minutes. This adds a layer of protection
                                against replay attacks over long time windows.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-nonce-gen">
                            <label class="form-check-label" for="check-nonce-gen">
                                <strong>Generate fresh nonce:</strong> Every authorization
                                request gets a new random <code>nonce</code> (16+ bytes).
                                <code>nonce</code> is echoed into the <code>id_token</code> and
                                prevents an attacker from substituting a token obtained from
                                a different authorization request.
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="check-nonce-verify">
                            <label class="form-check-label" for="check-nonce-verify">
                                <strong>Verify nonce in the id_token:</strong> After verifying
                                the token signature, check that the <code>nonce</code> claim
                                matches exactly what you sent. <strong>Reject the token if
                                nonce is missing or does not match.</strong> Use
                                <code>hash_equals()</code> (or equivalent) to prevent timing
                                attacks.
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- TOKEN VALIDATION CHECKLIST -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">id_token validation checklist</h2>
                <p>
                    Before trusting any claim in the <code>id_token</code>, verify
                    these checks in order:
                </p>
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-sig-alg">
                            <label class="form-check-label" for="check-sig-alg">
                                <strong>Signature algorithm:</strong> Verify the token
                                header&#39;s <code>alg</code> is <code>RS256</code>.
                                <strong>Reject any other algorithm.</strong> (Never accept
                                <code>none</code>, <code>HS256</code>, or any symmetric alg.)
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-sig-key">
                            <label class="form-check-label" for="check-sig-key">
                                <strong>Signature verification:</strong> Fetch the public
                                key from <code>https://signula.id/.well-known/jwks.json</code>
                                using the <code>kid</code> header from the token. Use a
                                robust JWT library (e.g., <code>firebase/php-jwt</code>,
                                <code>PyJWT</code>, <code>jsonwebtoken</code> for Node).
                                <strong>Never implement signature verification yourself.</strong>
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-iss">
                            <label class="form-check-label" for="check-iss">
                                <strong>Verify issuer:</strong>
                                <code>iss === "https://signula.id"</code> exactly.
                                <strong>Reject if it does not match.</strong>
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-aud">
                            <label class="form-check-label" for="check-aud">
                                <strong>Verify audience:</strong>
                                <code>aud === your_client_id</code> exactly.
                                <strong>Reject if it does not match.</strong> This ensures
                                the token was issued for your app, not another.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-exp">
                            <label class="form-check-label" for="check-exp">
                                <strong>Verify expiration:</strong> Check
                                <code>exp > current_time</code> (allow 30–60 seconds clock
                                skew). <strong>Reject if token has expired.</strong>
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="check-nonce-token">
                            <label class="form-check-label" for="check-nonce-token">
                                <strong>Verify nonce (again):</strong>
                                <code>nonce === expected_nonce</code> (use <code>hash_equals()</code>).
                                <strong>Reject if nonce is missing or does not match.</strong>
                                This is critical even if you validated state — nonce protects
                                against token substitution.
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- CLIENT SECRETS -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Client secret handling</h2>
                <div class="alert alert-danger">
                    <strong>⚠️ Client secrets are SECRET.</strong> Never commit them
                    to source control. Never embed them in browser-side code or
                    mobile app binaries.
                </div>
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-secret-env">
                            <label class="form-check-label" for="check-secret-env">
                                <strong>Store secrets in environment variables:</strong>
                                Use <code>getenv('SIGNULA_CLIENT_SECRET')</code> in PHP,
                                <code>process.env.SIGNULA_CLIENT_SECRET</code> in Node, etc.
                                Never hardcode them.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-secret-public">
                            <label class="form-check-label" for="check-secret-public">
                                <strong>Public clients (browser/mobile) never have secrets:</strong>
                                If your app is a SPA or native mobile app, register it as
                                <code>public</code>. No secret is issued. PKCE is your proof
                                of possession instead.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-secret-rotation">
                            <label class="form-check-label" for="check-secret-rotation">
                                <strong>Rotate secrets regularly:</strong> If a secret is
                                compromised or suspected leaked, rotate it immediately from
                                your partner dashboard. The old secret is invalidated the
                                instant a new one is issued.
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="check-secret-auth">
                            <label class="form-check-label" for="check-secret-auth">
                                <strong>Use HTTP Basic auth at token endpoints:</strong>
                                If you must send your secret to SIGNula, use HTTP Basic auth
                                (Authorization: Basic base64(client_id:client_secret)) over HTTPS.
                                Do NOT send it as a query parameter or in the request body.
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- TOKEN STORAGE & USAGE -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Token storage & usage checklist</h2>
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-no-localstorage">
                            <label class="form-check-label" for="check-no-localstorage">
                                <strong>Never use localStorage for tokens (browser/SPA):</strong>
                                Use <code>sessionStorage</code> (lost on browser close, HTTPS-only).
                                Better yet, use an httpOnly Secure cookie set by your backend
                                token-exchange endpoint.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-httponly-cookie">
                            <label class="form-check-label" for="check-httponly-cookie">
                                <strong>httpOnly + Secure + SameSite cookies:</strong>
                                If storing tokens in cookies, set:
                                <code>httponly=true</code>, <code>secure=true</code> (HTTPS only),
                                <code>samesite=Strict</code> (CSRF protection).
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-short-lived">
                            <label class="form-check-label" for="check-short-lived">
                                <strong>Access tokens are short-lived:</strong>
                                SIGNula access tokens expire after 15 minutes. Do not attempt
                                to extend their lifetime. Implement a refresh mechanism
                                (automatic or on-demand) using the <code>refresh_token</code>.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-refresh-single-use">
                            <label class="form-check-label" for="check-refresh-single-use">
                                <strong>Refresh tokens are single-use with rotation:</strong>
                                Each refresh response includes a new <code>refresh_token</code>.
                                Update your storage immediately. Replaying an old refresh token
                                (or using a spent one) is treated as a security incident — the
                                entire token family is revoked.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-token-sub">
                            <label class="form-check-label" for="check-token-sub">
                                <strong>Treat <code>sub</code> as opaque and app-local:</strong>
                                The <code>sub</code> claim is a stable, per-app identifier for
                                the user. Do NOT attempt to reverse it or expect it to match
                                across different Relying Parties. It cannot be correlated with
                                another app&#39;s user ID (pairwise property).
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="check-no-token-logging">
                            <label class="form-check-label" for="check-no-token-logging">
                                <strong>Never log raw tokens:</strong> Do not print access tokens,
                                refresh tokens, or id_tokens to stdout, log files, or debugging
                                tools. Log only the subject (<code>sub</code>) and expiry for
                                audit purposes.
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- LOGOUT & REVOCATION -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Logout & revocation checklist</h2>
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-revoke-on-logout">
                            <label class="form-check-label" for="check-revoke-on-logout">
                                <strong>Revoke refresh tokens on logout:</strong>
                                Call <code>POST /oauth/revoke</code> with the <code>refresh_token</code>
                                when the user logs out. This revokes the entire token family.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-revoke-always-200">
                            <label class="form-check-label" for="check-revoke-always-200">
                                <strong>/oauth/revoke always returns 200:</strong> Even if the
                                token is unknown or already revoked, the endpoint returns 200
                                with an empty body (per RFC 7009). Do not treat a 200 as a
                                guarantee the token existed.
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="check-clear-local-data">
                            <label class="form-check-label" for="check-clear-local-data">
                                <strong>Clear local tokens after revoke:</strong> Once you revoke
                                the token server-side, clear it from session, cookies, or local
                                storage. Do not rely on token expiry.
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="check-revoke-on-compromise">
                            <label class="form-check-label" for="check-revoke-on-compromise">
                                <strong>Revoke on suspected compromise:</strong> If you suspect
                                a token has been leaked (e.g., via logs, a vulnerability, or
                                user report), revoke it immediately. Users will need to sign in
                                again, but the attacker can no longer use the old token.
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- COMMON PITFALLS -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Common security pitfalls (avoid these!)</h2>

                <div class="alert alert-danger">
                    <strong>1. Skipping signature verification "for speed"</strong><br>
                    If you do not verify the JWT signature, an attacker can forge any
                    claims they want. Always verify. Always. Use a robust JWT library
                    (never implement verification yourself).
                </div>

                <div class="alert alert-danger">
                    <strong>2. Accepting any <code>alg</code> value</strong><br>
                    Check that the token&#39;s <code>alg</code> header is <code>RS256</code>.
                    Reject <code>HS256</code>, <code>none</code>, or any symmetric algorithm
                    — these can be forged by an attacker who doesn&#39;t have the private key.
                </div>

                <div class="alert alert-danger">
                    <strong>3. Trusting claims without verifying nonce</strong><br>
                    If an attacker obtains a valid id_token from a different authorization flow,
                    they can replay it to your app. The <code>nonce</code> check prevents this.
                    Always generate it, send it, and verify it.
                </div>

                <div class="alert alert-danger">
                    <strong>4. Reusing or hardcoding state / nonce</strong><br>
                    Generate fresh random values for every authorization request. Reusing them
                    defeats their purpose (CSRF + replay protection).
                </div>

                <div class="alert alert-danger">
                    <strong>5. Storing tokens in localStorage</strong><br>
                    Any JavaScript on the page (including injected scripts from compromised
                    third-party libraries) can read localStorage and steal tokens. Use httpOnly
                    cookies or sessionStorage instead.
                </div>

                <div class="alert alert-danger">
                    <strong>6. Embedding client secrets in client-side code</strong><br>
                    If you build a SPA or mobile app, register it as a <code>public</code>
                    client — never embed a secret in JavaScript or a binary. Secrets belong
                    on the backend only.
                </div>

                <div class="alert alert-danger">
                    <strong>7. Falling back to <code>plain</code> PKCE</strong><br>
                    Always use <code>S256</code>. <code>plain</code> is rejected by default
                    at SIGNula and provides no security benefit.
                </div>

                <div class="alert alert-danger">
                    <strong>8. Not handling refresh token rotation</strong><br>
                    When you refresh an access token, a new refresh token is issued.
                    If you continue using the old one, it will eventually fail with a security
                    incident. Always update your stored refresh token.
                </div>

                <!-- ============================================================ -->
                <!-- CROSS-LINKS -->
                <!-- ============================================================ -->
                <div class="alert alert-secondary mt-5">
                    <strong>Full reference:</strong>
                    <a href="/docs/signin-with-signula" class="alert-link">Sign in with SIGNula.id</a>
                    (all endpoints, parameters, error responses).
                    <br>
                    <strong>Platform guides:</strong>
                    <a href="/docs/oidc-web" class="alert-link">Web / SPA</a>,
                    <a href="/docs/oidc-native" class="alert-link">Mobile</a>,
                    <a href="/docs/oidc-server" class="alert-link">Server-side</a>.
                    <br>
                    <strong>Security incident?</strong> Contact us at
                    <a href="mailto:security@signula.id" class="alert-link">security@signula.id</a>.
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php'; ?>
