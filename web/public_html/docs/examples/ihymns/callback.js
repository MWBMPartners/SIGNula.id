/**
 * ============================================================================
 * iHymns × SIGNula.id reference integration — callback.js
 * ============================================================================
 * COPY this into the iHymns app (github.com/MWBMPartners/iHymns) as the
 * script for the page that lives at your registered redirect_uri, e.g.
 * https://ihymns.app/auth/signula/callback — NOT a live SIGNula runtime
 * route. This is the SPA/PWA variant: it reads the PKCE values
 * button-embed.html's signula-signin.js already stored in sessionStorage,
 * rather than a server-side PHP session (contrast with callback.php, the
 * server-rendered variant that pairs with login-start.php).
 *
 * Full reference this follows: SIGNula.com/docs/oidc-web.php ("Implement
 * the redirect callback" + "Secure token storage") in the SIGNula repo, and
 * https://signula.id/docs/oidc-web on the live site.
 *
 * ⚠️ RECOMMENDED PATTERN: hand the `code` + `code_verifier` to YOUR OWN
 * backend and let it perform the token exchange + id_token verification
 * server-side (Pattern A below). This keeps `code_verifier` correlated
 * with a request your server controls, lets you set httpOnly cookies
 * (tokens become inaccessible to any XSS on the page), and lets you use a
 * confidential client if iHymns' backend has one. Pattern B (pure
 * client-side exchange) is documented below too, for a fully static /
 * backend-less deployment — read its trade-offs carefully before using it.
 * ============================================================================
 */
(function () {
    'use strict';

    // -----------------------------------------------------------------------
    // 🔧 Configuration — substitute your REAL registered values.
    // -----------------------------------------------------------------------
    var SIGNULA_BASE = 'https://signula.id';
    var IHYMNS_CLIENT_ID = 'IHYMNS_CLIENT_ID'; // TODO(iHymns): your real client_id
    var STORAGE_PREFIX = 'signula:pkce:';

    // TODO(iHymns): point this at your own backend token-exchange endpoint.
    // It should implement the SAME steps as callback.php's Steps 3-6
    // (exchange code, verify id_token server-side, map sub -> user, set an
    // httpOnly session cookie) and return only what the frontend needs to
    // proceed (e.g. { success: true } or a redirect target) — never the
    // raw access_token/id_token/refresh_token back to client JS if you can
    // avoid it. Set this to null/'' ONLY for a fully static, backend-less
    // deployment — that switches run() below to Pattern B
    // (exchangeClientSideFallback), whose trade-offs are documented on
    // that function's doc comment. Prefer keeping this set.
    var BACKEND_EXCHANGE_ENDPOINT = '/api/auth/signula/exchange';

    /**
     * 🔍 Step 1 — Read `code`/`state`/`error` from the URL. Always check
     * `error` before assuming `code` is present (e.g. the user clicked
     * Deny on the SIGNula consent screen).
     */
    function readCallbackParams() {
        var params = new URLSearchParams(window.location.search);
        return {
            code: params.get('code'),
            state: params.get('state'),
            error: params.get('error'),
            errorDescription: params.get('error_description')
        };
    }

    /**
     * 🛡️ Step 2 — Verify `state` and retrieve the PKCE record the button
     * stored. Returns null (and surfaces an error) if `state` is missing
     * or doesn't resolve to a stored entry — this is the CSRF defence;
     * never fall back to `signula:pkce:latest` here, only the actual
     * returned `state` is a valid lookup key.
     */
    function loadPendingFlow(state) {
        if (!state) {
            return null;
        }
        var raw = window.sessionStorage.getItem(STORAGE_PREFIX + state);
        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (parseError) {
            return null;
        }
    }

    /**
     * 🧹 Remove the consumed PKCE record — the authorization code is
     * single-use server-side regardless, but clearing it locally avoids
     * stale entries accumulating across sign-in attempts.
     */
    function clearPendingFlow(state) {
        window.sessionStorage.removeItem(STORAGE_PREFIX + state);
    }

    // =========================================================================
    // ✅ Pattern A (RECOMMENDED) — hand off to your own backend
    // =========================================================================
    /**
     * POST the authorization code + PKCE verifier to iHymns' own backend,
     * which performs the exchange + id_token verification server-side and
     * sets an httpOnly session cookie. See callback.php in this same
     * reference directory for what that backend endpoint should do.
     *
     * @param {string} code
     * @param {Object} pending {codeVerifier, nonce, redirectUri, clientId}
     * @returns {Promise<void>}
     */
    function exchangeViaBackend(code, pending) {
        return fetch(BACKEND_EXCHANGE_ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin', // send/receive the session cookie
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                code: code,
                codeVerifier: pending.codeVerifier,
                nonce: pending.nonce,
                redirectUri: pending.redirectUri,
                clientId: pending.clientId
            })
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Backend token exchange failed (HTTP ' + response.status + ')');
            }
            return response.json();
        }).then(function () {
            // The backend already set the session cookie — nothing further
            // to store client-side. Redirect into the app.
            window.location.assign('/dashboard');
        });
    }

    // =========================================================================
    // ⚠️ Pattern B (FALLBACK) — pure client-side exchange, no backend
    // =========================================================================
    /**
     * Exchange the code directly against SIGNula's /oauth/token from the
     * browser. Only viable because iHymns' web/PWA client is registered as
     * a PUBLIC client (no client_secret exists to protect) — this is NOT
     * an option for a confidential client, whose secret must never reach
     * client-side JS.
     *
     * TRADE-OFFS vs Pattern A (read before using this in production):
     *   - Tokens land in browser memory / must be stored somewhere the
     *     page's own JS can reach (there is no httpOnly option available
     *     to pure client-side code) — a stricter CSP and diligent XSS
     *     hygiene become load-bearing security controls, not just
     *     defence-in-depth.
     *   - id_token signature verification client-side is possible (fetch
     *     the JWKS, verify with a JS JWT library) but adds a dependency
     *     and attack surface most apps don't need — this fallback instead
     *     treats the token exchange response as already-authenticated
     *     transport (HTTPS from SIGNula directly to this page) and relies
     *     on calling /oauth/userinfo with the returned access_token as a
     *     second factor of confirmation (a forged/tampered id_token would
     *     not carry a matching valid access_token). This is a WEAKER
     *     guarantee than full signature verification — do full
     *     verification server-side (Pattern A / callback.php) wherever
     *     you possibly can.
     *   - No refresh-token rotation loop is shown here — a page reload
     *     requires signing in again unless you additionally implement
     *     silent refresh (see /docs/oidc-web.php "Option B: In-memory +
     *     refresh").
     *
     * @param {string} code
     * @param {Object} pending
     * @returns {Promise<void>}
     */
    function exchangeClientSideFallback(code, pending) {
        return fetch(SIGNULA_BASE + '/oauth/token', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                grant_type: 'authorization_code',
                code: code,
                redirect_uri: pending.redirectUri,
                code_verifier: pending.codeVerifier,
                client_id: pending.clientId || IHYMNS_CLIENT_ID
            })
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Token exchange failed (HTTP ' + response.status + ')');
            }
            return response.json();
        }).then(function (tokens) {
            // Lightweight secondary check: confirm the access_token is
            // actually accepted by SIGNula before trusting the id_token's
            // claims — see the trade-offs note above.
            return fetch(SIGNULA_BASE + '/oauth/userinfo', {
                headers: { 'Authorization': 'Bearer ' + tokens.access_token }
            }).then(function (userInfoResponse) {
                if (!userInfoResponse.ok) {
                    throw new Error('UserInfo confirmation failed — token rejected by SIGNula.');
                }
                return userInfoResponse.json();
            }).then(function (userInfo) {
                // Decode the id_token payload WITHOUT verifying its
                // signature (base64url JSON — a convenience read only,
                // never treat unverified claims as authoritative for
                // anything security-sensitive beyond what userInfo above
                // already confirmed).
                var idTokenPayload = decodeJwtPayloadUnverified(tokens.id_token);

                if (idTokenPayload.nonce !== pending.nonce) {
                    throw new Error('id_token nonce mismatch — possible replay/substitution.');
                }
                if (idTokenPayload.aud !== (pending.clientId || IHYMNS_CLIENT_ID)) {
                    throw new Error('id_token audience mismatch.');
                }
                if (idTokenPayload.iss !== SIGNULA_BASE) {
                    throw new Error('id_token issuer mismatch.');
                }

                // TODO(iHymns): store tokens.access_token / refresh_token
                // per your own strategy (in-memory + silent refresh is the
                // least-bad option for a pure-SPA deployment — see
                // /docs/oidc-web.php "Option B"). Do NOT use localStorage.
                // TODO(iHymns): call your own /api/auth/session-from-sub
                // style endpoint with userInfo.sub to establish/provision
                // the local iHymns user (same mapSignulaSubjectToIhymnsUser
                // logic as callback.php, just invoked from a different
                // trigger point).
                window.location.assign('/dashboard');
            });
        });
    }

    /**
     * Decode a JWT's payload segment WITHOUT verifying its signature.
     * Only ever use this for the informational/secondary check described
     * in exchangeClientSideFallback()'s doc comment above — never as a
     * substitute for real signature verification.
     * @param {string} jwt
     * @returns {Object}
     */
    function decodeJwtPayloadUnverified(jwt) {
        var payloadSegment = jwt.split('.')[1] || '';
        var base64 = payloadSegment.replace(/-/g, '+').replace(/_/g, '/');
        var padded = base64 + '==='.slice((base64.length + 3) % 4);
        return JSON.parse(window.atob(padded));
    }

    // =========================================================================
    // 🚀 Entry point
    // =========================================================================
    function run() {
        var params = readCallbackParams();

        if (params.error) {
            // TODO(iHymns): show a friendly "sign-in was cancelled" message
            // instead of a redirect, if you'd rather not bounce again.
            console.error('[iHymns][SIGNula OAuth]', params.error, params.errorDescription);
            window.location.assign('/login?signula_error=' + encodeURIComponent(params.error));
            return;
        }

        if (!params.code || !params.state) {
            window.location.assign('/login?signula_error=missing_callback_params');
            return;
        }

        var pending = loadPendingFlow(params.state);
        if (!pending) {
            // state didn't resolve to a stored entry — reject outright
            // (CSRF defence). Do NOT fall back to any other stored value.
            console.error('[iHymns][SIGNula OAuth] state mismatch — no matching pending flow.');
            window.location.assign('/login?signula_error=state_mismatch');
            return;
        }

        // Prefer Pattern A whenever a backend endpoint is available.
        var exchange = BACKEND_EXCHANGE_ENDPOINT
            ? exchangeViaBackend(params.code, pending)
            : exchangeClientSideFallback(params.code, pending);

        exchange
            .then(function () {
                clearPendingFlow(params.state);
            })
            .catch(function (error) {
                clearPendingFlow(params.state);
                console.error('[iHymns][SIGNula OAuth]', error);
                window.location.assign('/login?signula_error=exchange_failed');
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
