/**
 * ============================================================================
 * 🪪 "Sign in with SIGNula.id" — Drop-in PKCE Initiator
 * ============================================================================
 *
 * Purpose:     Starts the OAuth 2.0 Authorization Code + PKCE (S256) flow
 *              against SIGNula's OIDC provider (G-001), from a partner
 *              (Relying Party) page — the browser-side half of the flow
 *              documented in full at /docs/signin-with-signula (see also
 *              README.md in this directory). Framework-free, ES2017+,
 *              zero dependencies (Web Crypto API only).
 *
 * Endpoint driven:
 *   GET <data-signula-base>/oauth/authorize-idp
 *     ?response_type=code&client_id=…&redirect_uri=…&scope=…
 *     &state=…&nonce=…&code_challenge=…&code_challenge_method=S256[&prompt=…]
 *
 * Usage (auto-wire — the common case):
 *   <button type="button" class="signula-btn signula-btn--light"
 *           data-client-id="YOUR_CLIENT_ID"
 *           data-redirect-uri="https://app.example.com/auth/callback">
 *     …
 *   </button>
 *   <script src="signula-signin.js"></script>
 *   Any element matching `.signula-btn[data-client-id]` present at
 *   DOMContentLoaded is wired up automatically — no extra JS required.
 *
 * Usage (programmatic):
 *   window.SignulaSignin.start({
 *     clientId:    'YOUR_CLIENT_ID',
 *     redirectUri: 'https://app.example.com/auth/callback',
 *     scope:       'openid email profile',   // optional, this is the default
 *     signulaBase: 'https://signula.id',     // optional, this is the default
 *     prompt:      'consent'                 // optional
 *   });
 *
 * sessionStorage keys (read these back on your redirect_uri page):
 *   `signula:pkce:<state>` → JSON string:
 *     { codeVerifier, nonce, redirectUri, clientId, signulaBase, createdAt }
 *   `signula:pkce:latest`  → the most recently generated `state` value
 *     (a convenience pointer ONLY — you must still key your lookup by the
 *     `state` query parameter SIGNula actually returns, see README.md
 *     "What to verify on redirect"; never trust `latest` on its own as your
 *     CSRF check).
 *
 * Security notes:
 *   - `code_verifier` NEVER leaves the browser except as its S256 hash
 *     (`code_challenge`) on this request; the raw verifier itself is sent
 *     later, over HTTPS, only in the server-side token exchange your
 *     redirect_uri handler performs against POST /oauth/token.
 *   - `state` and `nonce` are generated fresh on every call — never reused
 *     across sign-in attempts.
 *   - This file refuses to run outside a secure context (HTTPS/localhost)
 *     because `crypto.subtle` is unavailable there — see
 *     assertCryptoAvailable() below. That is a browser platform
 *     restriction, not a SIGNula-specific check.
 *
 * Docs: https://developer.mozilla.org/en-US/docs/Web/API/Crypto/getRandomValues
 *       https://developer.mozilla.org/en-US/docs/Web/API/SubtleCrypto/digest
 *       https://datatracker.ietf.org/doc/html/rfc7636 (PKCE)
 *
 * @package SIGNula
 * @since   1.0.0 (IB1)
 * ============================================================================
 */
(function (window, document) {
    'use strict';

    // ========================================================================
    // 🔧 Configuration defaults
    // ========================================================================
    var DEFAULT_SCOPE = 'openid email profile';
    var DEFAULT_BASE = 'https://signula.id';
    var STORAGE_PREFIX = 'signula:pkce:';
    var STORAGE_LATEST_KEY = STORAGE_PREFIX + 'latest';
    var AUTHORIZE_PATH = '/oauth/authorize-idp';

    // ========================================================================
    // 🔐 PKCE / random-value helpers
    // ========================================================================

    /**
     * 🧵 base64url-encode an ArrayBuffer/TypedArray (no padding, URL-safe).
     * Mirrors the exact technique already published in the partner guide at
     * /docs/signin-with-signula (Step 1 code sample) for consistency between
     * the docs and this drop-in.
     * @param {ArrayBuffer|Uint8Array} buffer
     * @returns {string}
     */
    function base64url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary)
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/, '');
    }

    /**
     * 🎲 Generate `n` cryptographically-random bytes, base64url-encoded.
     * @param {number} n Number of random bytes.
     * @returns {string}
     */
    function randomBase64url(n) {
        var bytes = new Uint8Array(n);
        window.crypto.getRandomValues(bytes);
        return base64url(bytes);
    }

    /**
     * 🔑 Generate a PKCE code_verifier — RFC 7636 §4.1 requires 43-128
     * characters from the unreserved URL-safe charset. 32 random bytes,
     * base64url-encoded, yields exactly 43 characters — the same choice
     * used in the /docs/signin-with-signula sample.
     * @returns {string}
     */
    function generateCodeVerifier() {
        return randomBase64url(32);
    }

    /**
     * #️⃣ Compute the S256 PKCE code_challenge for a given verifier:
     * BASE64URL( SHA-256( ASCII(verifier) ) ), per RFC 7636 §4.2.
     * @param {string} verifier
     * @returns {Promise<string>}
     */
    function s256(verifier) {
        var data = new TextEncoder().encode(verifier);
        return window.crypto.subtle.digest('SHA-256', data).then(function (digest) {
            return base64url(digest);
        });
    }

    /**
     * 🧭 Generate a random `state` or `nonce` value (16 bytes → 22 chars
     * base64url — ample entropy for a CSRF/replay token).
     * @returns {string}
     */
    function randomToken() {
        return randomBase64url(16);
    }

    // ========================================================================
    // 🛡️ Environment guard
    // ========================================================================

    /**
     * ✅ Confirm the Web Crypto API this flow depends on is actually
     * available. `crypto.subtle` is ONLY exposed in a secure context
     * (HTTPS, or http://localhost during development) — see
     * https://developer.mozilla.org/en-US/docs/Web/Security/Secure_Contexts
     * Failing fast here with a clear message beats letting the redirect
     * silently break (or throwing a cryptic "Cannot read properties of
     * undefined (reading 'digest')" deep inside a promise chain).
     * @throws {Error}
     */
    function assertCryptoAvailable() {
        if (!window.isSecureContext) {
            throw new Error(
                'SignulaSignin: this page is not a secure context (HTTPS or ' +
                'localhost). The Web Crypto API required for PKCE is only ' +
                'available on secure origins — serve this page over HTTPS.'
            );
        }
        if (!window.crypto || !window.crypto.subtle || !window.crypto.getRandomValues) {
            throw new Error(
                'SignulaSignin: this browser does not support the Web Crypto ' +
                'API (crypto.subtle). Sign-in with SIGNula.id cannot proceed.'
            );
        }
    }

    // ========================================================================
    // 🚀 Core flow
    // ========================================================================

    /**
     * ▶️ Start the Authorization Code + PKCE flow: generate verifier/
     * challenge/state/nonce, persist them to sessionStorage, and navigate
     * the browser to SIGNula's authorize-idp endpoint.
     *
     * @param {Object} config
     * @param {string} config.clientId         Required. Your registered client_id.
     * @param {string} config.redirectUri      Required. Must exactly match a
     *                                          URI registered for this client.
     * @param {string} [config.scope]          Default: "openid email profile".
     * @param {string} [config.signulaBase]    Default: "https://signula.id".
     * @param {string} [config.prompt]         Optional, e.g. "consent"/"login".
     * @returns {Promise<void>} Resolves just before navigation; rejects with
     *                          a descriptive Error if the flow cannot start
     *                          (missing config, insecure context, etc.) — the
     *                          browser is NOT redirected in that case.
     */
    function start(config) {
        config = config || {};
        return Promise.resolve().then(function () {
            assertCryptoAvailable();

            var clientId = (config.clientId || '').trim();
            var redirectUri = (config.redirectUri || '').trim();
            var scope = (config.scope || DEFAULT_SCOPE).trim();
            var signulaBase = (config.signulaBase || DEFAULT_BASE).replace(/\/+$/, '');
            var prompt = (config.prompt || '').trim();

            if (!clientId) {
                throw new Error('SignulaSignin: "clientId" (data-client-id) is required.');
            }
            if (!redirectUri) {
                throw new Error('SignulaSignin: "redirectUri" (data-redirect-uri) is required.');
            }

            var codeVerifier = generateCodeVerifier();
            var state = randomToken();
            var nonce = randomToken();

            return s256(codeVerifier).then(function (codeChallenge) {
                // 💾 Persist everything the redirect_uri handler will need to
                // (a) verify `state` (CSRF), (b) verify `nonce` inside the
                // returned id_token, and (c) complete the token exchange with
                // the raw code_verifier. See the header comment above for the
                // exact key format and README.md for the retrieval snippet.
                try {
                    var record = {
                        codeVerifier: codeVerifier,
                        nonce: nonce,
                        redirectUri: redirectUri,
                        clientId: clientId,
                        signulaBase: signulaBase,
                        createdAt: Date.now()
                    };
                    window.sessionStorage.setItem(STORAGE_PREFIX + state, JSON.stringify(record));
                    window.sessionStorage.setItem(STORAGE_LATEST_KEY, state);
                } catch (storageError) {
                    // 🚫 sessionStorage can throw in locked-down contexts (e.g.
                    // some in-app browsers with storage disabled, or quota
                    // exceeded). Without it we cannot complete the token
                    // exchange later, so treat this as fatal rather than
                    // silently redirecting into a dead end.
                    throw new Error(
                        'SignulaSignin: sessionStorage is unavailable, so the ' +
                        'PKCE code_verifier cannot be safely stored. Sign-in ' +
                        'cannot proceed in this browsing context.'
                    );
                }

                // 🔗 Build the authorize-idp URL. Every value is individually
                // URL-encoded via URLSearchParams (space → "+"; functionally
                // identical to "%20" per the application/x-www-form-urlencoded
                // and query-string conventions the server already parses).
                var params = new URLSearchParams();
                params.set('response_type', 'code');
                params.set('client_id', clientId);
                params.set('redirect_uri', redirectUri);
                params.set('scope', scope);
                params.set('state', state);
                params.set('nonce', nonce);
                params.set('code_challenge', codeChallenge);
                params.set('code_challenge_method', 'S256');
                if (prompt) {
                    params.set('prompt', prompt);
                }

                var authorizeUrl = signulaBase + AUTHORIZE_PATH + '?' + params.toString();
                window.location.assign(authorizeUrl);
            });
        });
    }

    // ========================================================================
    // 🖱️ Auto-wiring — any `.signula-btn[data-client-id]` element gets a
    // click handler for free; no extra script required beyond the tag copied
    // from README.md / demo.html.
    // ========================================================================

    /**
     * 🖊️ Show a minimal inline error state on a button that failed to start
     * the flow (e.g. missing config, insecure context) — never leaves the
     * user clicking a button that silently does nothing.
     * @param {HTMLElement} button
     * @param {Error} error
     */
    function showButtonError(button, error) {
        // eslint-disable-next-line no-console
        console.error('[SignulaSignin]', error.message);
        button.setAttribute('aria-disabled', 'true');
        button.disabled = true;
        var label = button.querySelector('.signula-btn__label');
        if (label && !button.hasAttribute('data-signula-original-label')) {
            button.setAttribute('data-signula-original-label', label.textContent);
            label.textContent = 'Sign-in unavailable';
        }
    }

    /**
     * 🔌 Wire a single button element to the flow above, reading its
     * `data-*` attributes for configuration.
     * @param {HTMLElement} button
     */
    function wireButton(button) {
        if (button.hasAttribute('data-signula-wired')) {
            return; // already wired — avoid double-binding on repeated calls
        }
        button.setAttribute('data-signula-wired', 'true');

        button.addEventListener('click', function (event) {
            event.preventDefault();
            if (button.disabled || button.getAttribute('aria-disabled') === 'true') {
                return;
            }
            button.setAttribute('aria-busy', 'true');

            start({
                clientId: button.getAttribute('data-client-id'),
                redirectUri: button.getAttribute('data-redirect-uri'),
                scope: button.getAttribute('data-scope'),
                signulaBase: button.getAttribute('data-signula-base'),
                prompt: button.getAttribute('data-prompt')
            }).catch(function (error) {
                button.removeAttribute('aria-busy');
                showButtonError(button, error);
            });
            // 🕒 Note: on the success path we deliberately do NOT clear
            // aria-busy — the browser is navigating away momentarily, so
            // leaving the button in its "busy" state avoids a flash of an
            // interactive button right before the page unloads.
        });
    }

    /**
     * 🔍 Find and wire every `.signula-btn[data-client-id]` currently in
     * the document. Safe to call more than once (wireButton() is
     * idempotent per-element).
     */
    function autoWire() {
        var buttons = document.querySelectorAll('.signula-btn[data-client-id]');
        for (var i = 0; i < buttons.length; i++) {
            wireButton(buttons[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoWire);
    } else {
        // Script loaded after DOMContentLoaded already fired (e.g. injected
        // dynamically, or placed at the very end of <body>) — wire immediately.
        autoWire();
    }

    // ========================================================================
    // 📦 Public API
    // ========================================================================
    window.SignulaSignin = {
        VERSION: '1.0.0',
        start: start,
        autoWire: autoWire,
        // 🧪 Exposed for integration testing / advanced use — not required
        // for the standard drop-in flow.
        generateCodeVerifier: generateCodeVerifier,
        s256: s256
    };
})(window, document);
