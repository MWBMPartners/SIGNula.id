# Sign in with SIGNula.id — Drop-in Button (IB1)

A framework-free, copy-paste button any Relying Party (RP) embeds to let its
users authenticate with a SIGNula ID — the same UX pattern as "Sign in with
Google" / "Sign in with Apple" / "Sign in with Microsoft".

This is the browser-side entry point into SIGNula's OAuth 2.0 / OpenID
Connect **provider** (G-001). The full server-side flow this button starts —
registering a client, exchanging the code, verifying the `id_token`,
refresh/revoke — is documented in detail at
**[`/docs/signin-with-signula`](../../SIGNula.com/docs/signin-with-signula.php)**.
This README covers only the button/browser half; read the docs guide before
building your `redirect_uri` handler.

No dependencies: no Bootstrap, no FontAwesome, no jQuery, no build step. Two
files, native Web Crypto API, ES2017+.

---

## Files in this directory

| File                   | Purpose                                                             |
|------------------------|----------------------------------------------------------------------|
| `signula-signin.css`   | Button styles — variants, sizes, states, accessibility.             |
| `signula-signin.js`    | PKCE (S256) flow initiator — generates + navigates to the authorize URL. |
| `demo.html`            | Visual gallery of every variant/size + a non-navigating live preview. |
| `README.md`            | This file.                                                            |

Self-host both `signula-signin.css` and `signula-signin.js` if you don't
want to load them from `signula.id` at runtime — they have zero external
references (no `@import`, no CDN fetches, no fonts).

---

## Copy-paste snippet

```html
<link rel="stylesheet" href="https://signula.id/assets/signin-button/signula-signin.css">

<button type="button" class="signula-btn signula-btn--light"
        data-client-id="YOUR_CLIENT_ID"
        data-redirect-uri="https://app.example.com/auth/callback">
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

<script src="https://signula.id/assets/signin-button/signula-signin.js"></script>
```

That's it — `signula-signin.js` auto-wires any
`.signula-btn[data-client-id]` element on `DOMContentLoaded`. Replace
`YOUR_CLIENT_ID` and `data-redirect-uri` with the values from your OAuth
client registration (`/docs/signin-with-signula#register`).

### Minimal / no-inline-SVG snippet

If you'd rather not paste the inline `<svg>` (e.g. a CMS that strips SVG
tags), use the `<img>` + base64 data-URI fallback instead — same idea as
`AvatarService::generateInitialsAvatar()`'s
`'data:image/svg+xml;base64,' . base64_encode($svg)` pattern used elsewhere
in SIGNula. This version is visually static (flat colours baked in — it
cannot theme via CSS custom properties the way the inline `<svg>` can):

```html
<button type="button" class="signula-btn signula-btn--light"
        data-client-id="YOUR_CLIENT_ID"
        data-redirect-uri="https://app.example.com/auth/callback">
  <span class="signula-btn__icon" aria-hidden="true">
    <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIiByb2xlPSJpbWciIGFyaWEtbGFiZWw9IlNJR051bGEuaWQiPjxjaXJjbGUgY3g9IjE3IiBjeT0iNTAiIHI9IjkiIGZpbGw9Im5vbmUiIHN0cm9rZT0iIzU1NjhkMyIgc3Ryb2tlLXdpZHRoPSI2IiBzdHJva2UtbGluZWNhcD0icm91bmQiLz48cmVjdCB4PSIyNiIgeT0iNDciIHdpZHRoPSIzNCIgaGVpZ2h0PSI3IiByeD0iMS41IiBmaWxsPSIjNTU2OGQzIi8+PHJlY3QgeD0iNDYiIHk9IjU0IiB3aWR0aD0iNSIgaGVpZ2h0PSI2IiBmaWxsPSIjNTU2OGQzIi8+PHJlY3QgeD0iNTQiIHk9IjU0IiB3aWR0aD0iNiIgaGVpZ2h0PSI5IiBmaWxsPSIjNTU2OGQzIi8+PHJlY3QgeD0iNjAiIHk9IjE4IiB3aWR0aD0iMjgiIGhlaWdodD0iNjQiIHJ4PSI4IiBmaWxsPSIjNTU2OGQzIi8+PGNpcmNsZSBjeD0iNzAiIGN5PSI0OCIgcj0iNiIgZmlsbD0iI2ZmZmZmZiIvPjxwYXRoIGQ9Ik0gNjQuNSA0OSBMIDc1LjUgNDkgTCA3MiA2MyBMIDY4IDYzIFoiIGZpbGw9IiNmZmZmZmYiLz48Y2lyY2xlIGN4PSI4MCIgY3k9Ijc1IiByPSIxMyIgZmlsbD0iI2ZmZmZmZiIgc3Ryb2tlPSIjNTU2OGQzIiBzdHJva2Utd2lkdGg9IjEuNSIvPjxwYXRoIGQ9Ik0gNzMuNSA3NSBMIDc4LjUgODAgTCA4OC41IDY3LjUiIGZpbGw9Im5vbmUiIHN0cm9rZT0iIzc2NGJhMiIgc3Ryb2tlLXdpZHRoPSI0LjUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIvPjwvc3ZnPg=="
         width="20" height="20" alt="">
  </span>
  <span class="signula-btn__label">Sign in with SIGNula.id</span>
</button>

<script src="https://signula.id/assets/signin-button/signula-signin.js"></script>
```

### JavaScript required

This drop-in fundamentally needs browser JavaScript — S256 PKCE requires
computing a SHA-256 digest client-side before the redirect fires, which is
not possible with a plain HTML anchor. If you need the sign-in link to work
with JavaScript disabled, drive the same flow **server-side** instead:
`code_verifier`/`code_challenge` generation is just `random-bytes` +
`SHA-256`, both trivial in PHP (or any backend) — build the
`/oauth/authorize-idp` redirect yourself per
[`/docs/signin-with-signula`](../../SIGNula.com/docs/signin-with-signula.php)
step 2, with no client-side script involved at all.

---

## `data-*` options

Set these on the `.signula-btn` element itself:

| Attribute              | Required | Default                     | Notes |
|-------------------------|:--------:|------------------------------|-------|
| `data-client-id`        | **Yes**  | —                             | Your registered OAuth/OIDC `client_id`. |
| `data-redirect-uri`     | **Yes**  | —                             | Must match a URI registered for this client **byte-for-byte** — no wildcard, no trailing-slash normalisation (see `/docs/signin-with-signula#register`). |
| `data-scope`            | No       | `openid email profile`       | Space-delimited. `openid` is always required by the server regardless of what you send. |
| `data-signula-base`     | No       | `https://signula.id`         | Override for a staging/self-hosted SIGNula instance. |
| `data-prompt`           | No       | *(unset)*                    | e.g. `consent` to force the consent screen even on a repeat sign-in, or `login` to force re-authentication. |

Also present on the element after signula-signin.js wires it up (internal
bookkeeping — don't set these yourself):
`data-signula-wired`, `aria-busy`, `data-signula-original-label`.

### Programmatic API

```html
<script src="signula-signin.js"></script>
<script>
  document.getElementById('myCustomButton').addEventListener('click', function () {
    window.SignulaSignin.start({
      clientId:    'YOUR_CLIENT_ID',
      redirectUri: 'https://app.example.com/auth/callback',
      scope:       'openid email profile',   // optional
      signulaBase: 'https://signula.id',     // optional
      prompt:      'consent'                 // optional
    }).catch(function (err) {
      // Rejects (does NOT navigate) if config is invalid, the context is
      // insecure, or sessionStorage is unavailable — see err.message.
      console.error(err);
    });
  });
</script>
```

`window.SignulaSignin` also exposes `generateCodeVerifier()` and
`s256(verifier)` (returns a `Promise<string>`) for testing/advanced use —
see `demo.html`'s "Live technical preview" section for a working example
that calls these directly.

---

## Variants and sizes

| Class                        | Purpose |
|-------------------------------|---------|
| `.signula-btn`                | Base class — required on every instance. Alone (no colour modifier), it auto-follows OS dark-mode (`prefers-color-scheme: dark`) and an ancestor `[data-theme="dark"]`. |
| `.signula-btn--light`         | Explicit light pin — white/near-white background, dark text, brand-coloured icon. Overrides auto dark-mode detection. |
| `.signula-btn--dark`          | Explicit dark pin — dark background, light text/icon. |
| `.signula-btn--brand`         | Filled two-stop brand gradient (`#667eea` → `#764ba2`), white text/icon. |
| `.signula-btn--mono`          | Colour-blind-safe / high-contrast — no hue at all, black-on-white (auto-inverts to white-on-black under dark mode), 2px border. Use this if your page's own design system avoids relying on colour to convey meaning. |
| `.signula-btn--large`         | 52px tall, larger icon/text (default is 42px, 44px min tap target either way). |
| `.signula-btn--icon`          | Icon-only, square/circular tap target (44px, or 52px combined with `--large`). Label is kept for screen readers via a visually-hidden span, not removed. |

All variants meet **WCAG 2.2 AA** contrast for text and icon, have a visible
`:focus-visible` ring, distinct `:hover`/`:active` states, a minimum 44×44px
tap target, and respect `prefers-reduced-motion` (the button itself has a
subtle hover-lift transition that is disabled under reduced-motion; the
embedded icon ships fully static — see the "Icon" note in
`signula-signin.css`'s header comment for why it deliberately does **not**
reuse the looping key-slide animation from `/assets/brand/symbol-*.svg`).

---

## What to store / verify on redirect

`signula-signin.js` persists everything your `redirect_uri` handler needs
into `sessionStorage`, keyed by the `state` value it generated:

```
sessionStorage["signula:pkce:<state>"] = JSON.stringify({
  codeVerifier, nonce, redirectUri, clientId, signulaBase, createdAt
})
sessionStorage["signula:pkce:latest"] = "<state>"   // convenience pointer only
```

On your `redirect_uri` page (or your backend, if you prefer to run this
step server-side), after SIGNula redirects back with
`?code=...&state=...`:

1. **Verify `state` first, before anything else.** Read the `state` query
   parameter from the URL and look up
   `sessionStorage["signula:pkce:" + returnedState]`. If nothing is found,
   **reject the callback outright** — this is CSRF protection; do not fall
   back to trusting `signula:pkce:latest` as if it were the check itself
   (it's a convenience pointer, not a substitute for keying the lookup by
   the actual returned `state`).
2. Parse the stored JSON to get `codeVerifier` and `nonce`.
3. Exchange the code **server-side** (never expose `code_verifier` to a
   third party or log it):
   ```
   POST https://signula.id/oauth/token
   Content-Type: application/x-www-form-urlencoded

   grant_type=authorization_code
   &code=AUTH_CODE
   &redirect_uri=https://app.example.com/auth/callback
   &code_verifier=STORED_CODE_VERIFIER
   ```
4. Verify the returned `id_token` — signature (RS256, via
   `https://signula.id/.well-known/jwks.json`), `iss`, `aud`, `exp`, and
   that its `nonce` claim equals the `nonce` you stored in step 2.
5. Remove the `sessionStorage["signula:pkce:<state>"]` entry once consumed
   — the code is single-use server-side regardless, but clearing it locally
   avoids stale entries accumulating across sign-in attempts.

**Full reference for steps 3–5** (confidential vs public client
authentication, exact success/error response shapes, refresh/revoke,
UserInfo): **[`/docs/signin-with-signula`](../../SIGNula.com/docs/signin-with-signula.php)**,
sections 2–5. This button only ever performs step 2 of that guide
(building and navigating to the authorize URL) — everything from the code
exchange onward is your `redirect_uri` handler's responsibility, exactly as
described there.

---

## Security notes

- **PKCE is mandatory and non-negotiable** — this button only ever
  generates `S256` challenges; the server rejects `plain` by default (see
  `/docs/signin-with-signula#security`).
- **`redirect_uri` must match your registration exactly** (byte-for-byte —
  no wildcard, no trailing-slash or scheme normalisation). A mismatch is
  rejected by the authorize endpoint before any redirect happens.
- **`code_verifier` never leaves the browser** except as its SHA-256 hash
  (`code_challenge`) on the initial redirect. The raw verifier is only ever
  sent later, over HTTPS, in your server-side `POST /oauth/token` call.
- **`state` defeats CSRF; `nonce` defeats `id_token` replay/substitution.**
  Both are freshly randomised (16 bytes via `crypto.getRandomValues`) on
  every click — never reused across sign-in attempts. Reject the callback
  if `state` doesn't resolve to a stored entry (see previous section).
- **Confidential-client secrets never belong in this button** — this
  drop-in only ever sends `client_id` (no secret), which is correct for a
  browser context. If your app is a confidential client (server-rendered,
  holds a `client_secret`), that secret is used only in your backend's
  `POST /oauth/token` call in step 3 above, never in this JS file.
- **Secure context required.** `crypto.subtle` (needed for the SHA-256
  digest) is only available on `https://` or `http://localhost`. On an
  insecure origin, `signula-signin.js` refuses to proceed and surfaces a
  clear error instead of attempting a broken/insecure redirect — see
  `assertCryptoAvailable()` in the source.
