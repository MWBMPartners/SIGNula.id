<!--
============================================================================
iHymns × SIGNula.id reference integration — copy into the iHymns app;
NOT a live SIGNula runtime route.
============================================================================
This directory (web/public_html/docs/examples/ihymns/ in the SIGNula repo)
is a self-contained, copy-ready reference showing the iHymns team
(github.com/MWBMPartners/iHymns) exactly how to wire up "Sign in with
SIGNula.id" — SIGNula acting as OAuth 2.0 / OpenID Connect PROVIDER (G-001)
for the iHymns Relying Party (RP). Every file here is meant to be copied
into the iHymns codebase and adapted; nothing in this directory is called
by, linked from, or wired into SIGNula's own router/config/settings.

It is accurate to the REAL, built G-001 provider as of this writing:
  - Authorize:  GET  https://signula.id/oauth/authorize-idp
  - Token:      POST https://signula.id/oauth/token
  - UserInfo:   GET  https://signula.id/oauth/userinfo
  - Revoke:     POST https://signula.id/oauth/revoke
  - JWKS:       GET  https://signula.id/.well-known/jwks.json
  - Discovery:  GET  https://signula.id/.well-known/openid-configuration

Cross-reference the canonical docs (these are the source of truth — this
README summarises + adapts them for iHymns specifically):
  - /docs/signin-with-signula   — full parameter/endpoint reference
  - /docs/oidc-server            — confidential/server-side integration
  - /docs/oidc-web                — SPA/PWA integration
  - /docs/oidc-native             — iOS/Android integration
  - /docs/oidc-security            — security checklist
  - /assets/signin-button/README.md — the drop-in button contract
  - /api/docs/openapi.yaml (OIDC Provider tag) — exact request/response schemas
-->

# Sign in with SIGNula.id — iHymns reference integration

This is the **iHymns-specific**, end-to-end walkthrough for adding
"Sign in with SIGNula.id" to iHymns (web/PWA and native apps). It reuses
SIGNula's real G-001 OIDC provider and the official drop-in button —
nothing here is bespoke to iHymns except the registered `client_id`(s),
redirect URIs, and the `signula_sub` → iHymns-user mapping.

Every code file in this folder has a header comment identifying it as a
reference to copy into the iHymns repo, not a live SIGNula route. Treat
`IHYMNS_CLIENT_ID`, `ihymns.app`, and `com.mwbm.ihymns` throughout as
**placeholders** — substitute your real registered values once you have
them (Step 1).

## Files in this reference

| File               | Purpose |
|---------------------|---------|
| `README.md`          | This walkthrough. |
| `button-embed.html`  | The exact drop-in button snippet, pre-filled with iHymns config. |
| `login-start.php`    | Server-side flow initiator — generates PKCE/state/nonce, stores them in the PHP session, redirects to `/oauth/authorize-idp`. Use this for a traditional server-rendered callback; skip it if you use the button + client-side `sessionStorage` flow instead (see `callback.js`). |
| `callback.php`       | Worked PHP redirect handler — verifies `state`, exchanges the code, verifies the `id_token`, optionally calls UserInfo, maps `sub` → an iHymns user, and establishes the iHymns session. |
| `callback.js`        | SPA/PWA variant — reads `code`/`state` from the URL and `sessionStorage`, then either hands off to your backend (recommended) or performs a pure-client-side exchange (documented trade-offs). |
| `native-notes.md`    | iOS (`ASWebAuthenticationSession`) + Android (Custom Tabs) integration notes, redirect scheme/App Link registration, Keychain/Keystore storage. |
| `schema.sql`         | The one column iHymns needs on its own `users` table (`signula_sub`) — an iHymns-side reference, **not** a SIGNula migration. |

---

## Step 1 — Register the iHymns OAuth/OIDC client(s)

Every Relying Party must register before it can use the flow. Registration
is self-service, from the **iHymns MWBM partner account**:

1. Sign in to the SIGNula Partner Dashboard and open
   **Dashboard → OAuth / OIDC Clients**
   (`https://signula.id/partners/admin/oauth-clients`).
2. Click **Register New OAuth Client**. Register (at minimum) one **public**
   client covering the iHymns web/PWA app and native apps together (they
   can share a single public client if they share a `client_id` scheme, or
   you can register one per platform — either is supported, register
   whatever maps cleanly onto your build/release process):

   | Field | Value for iHymns |
   |-------|-------------------|
   | Client Name | `iHymns` (shown on the SIGNula consent screen) |
   | Client Type | `public` — **no `client_secret`** is issued; PKCE (`S256`) is the only proof of possession. Required for the web/PWA app and both native apps because none of them can hold a secret safely (a PWA is fully client-side; a native binary is reverse-engineerable). |
   | Redirect URIs | One **exact, byte-for-byte** URI per platform — see table below. Registering the wrong scheme/path/trailing-slash will cause every callback for that platform to be rejected at `/oauth/authorize-idp`. |
   | Scopes | `openid email profile` (tick `offline_access` too if iHymns needs a `refresh_token` to keep users signed in across app restarts — likely yes for the native apps). |
   | Subject type | Leave on `pairwise` (default) — iHymns receives a stable, **iHymns-only** opaque `sub` for each user; it will differ from the `sub` any other SIGNula-integrated app sees for the same person. This is the intended privacy property (see Step 4). |

   **Redirect URIs to register (substitute your real values, then treat
   these exact strings as fixed once registered):**

   | Platform | `redirect_uri` |
   |----------|------------------|
   | Web / PWA | `https://ihymns.app/auth/signula/callback` |
   | iOS (custom scheme) | `com.mwbm.ihymns:/oauth/callback` |
   | Android (custom scheme) | `com.mwbm.ihymns://oauth/callback` |
   | Android/iOS (https App Link — preferred over custom scheme, see `native-notes.md`) | `https://ihymns.app/auth/signula/callback` (same URI as web is fine if your app-link/universal-link config intercepts it before it reaches the browser) |

3. **Only if iHymns later adds a server backend that needs to call SIGNula
   APIs on its own behalf** (not just sign users in) would you additionally
   register a **confidential** client (server-side, holds a
   `client_secret`, HTTP Basic auth to `/oauth/token`). For the sign-in flow
   itself, the public client above is sufficient and is the client type
   `callback.php` in this reference is written against by default (it has
   a clearly-marked branch for the confidential variant too, see the
   `SIGNULA_CLIENT_SECRET` constant).
4. Submit. Copy the `client_id` (and `client_secret`, if you registered a
   confidential client — shown **exactly once**) into your app's config
   (environment variables / secure config store — **never commit these to
   source control**).
5. **Confirm the provider is enabled.** If every endpoint below 404s,
   `oidc.enabled` is off on the SIGNula side — ask the SIGNula operator
   (MWBM) to confirm it's turned on (see `_docs/setup/SECURITY_SETUP.md`,
   "OIDC Provider (G-001)" section, in the SIGNula repo).

---

## Step 2 — Drop in the button

Copy `button-embed.html` into your iHymns login page (web/PWA). It's the
exact `/assets/signin-button` drop-in
(see `web/public_html/assets/signin-button/README.md` in the SIGNula repo
for the full contract), pre-filled with:

```html
data-client-id="IHYMNS_CLIENT_ID"
data-redirect-uri="https://ihymns.app/auth/signula/callback"
data-scope="openid email profile"
data-signula-base="https://signula.id"
```

No build step, no dependencies — it self-wires on `DOMContentLoaded` and
generates PKCE (`S256`), `state`, and `nonce` client-side via the Web
Crypto API, storing them in
`sessionStorage["signula:pkce:<state>"]` before redirecting to
`https://signula.id/oauth/authorize-idp`.

If you'd rather drive the whole flow **server-side** (no client-side
JavaScript at all — e.g. so sign-in still works with JS disabled), use
`login-start.php` instead of the button: it generates the same PKCE
values in PHP and stores them in the PHP session rather than
`sessionStorage`.

---

## Step 3 — Deploy the callback

Pick ONE of the two callback patterns (or support both, for
JS-disabled/JS-enabled users respectively):

- **Server-rendered app (recommended default for iHymns' server-side
  pages):** copy `callback.php` to
  `https://ihymns.app/auth/signula/callback` (must exactly match the
  registered `redirect_uri`). Pair it with `login-start.php` as the flow
  initiator if you're not using the button.
- **SPA/PWA client route:** copy `callback.js`, loaded from the page that
  lives at `https://ihymns.app/auth/signula/callback`. It reads the PKCE
  values the button already stored in `sessionStorage` and either posts
  the code to a small iHymns backend endpoint (recommended — keeps tokens
  server-side) or performs the exchange directly in the browser (documented
  fallback, with trade-offs called out in the file).

Both variants:
1. Verify `state` **before anything else** (CSRF defence).
2. Exchange the authorization `code` + `code_verifier` + `redirect_uri`
   (no `client_secret` for the public client) at
   `POST https://signula.id/oauth/token`.
3. Verify the returned `id_token`'s signature (RS256, via
   `https://signula.id/.well-known/jwks.json`), `iss`, `aud`, `exp`, and
   `nonce`.
4. Extract `sub` (and `email`/profile claims, either from the `id_token`
   directly or via `GET /oauth/userinfo`).

---

## Step 4 — Map `sub` → an iHymns user

`callback.php`'s `mapSignulaSubjectToIhymnsUser($sub, $claims)` is the
piece iHymns actually implements (it's stubbed with clear `TODO(iHymns)`
markers pointing at your own user model/DB layer):

- **First login for this `sub`:** auto-provision a new iHymns local user,
  storing the SIGNula `sub` in a **`UNIQUE`** `signula_sub` column on your
  `users` table (see `schema.sql`). Backfill `email`/`name` from the
  `id_token`/UserInfo claims as a starting profile — but the `sub`, not the
  email, is the durable link.
- **Every subsequent login:** look the user up **by `sub`**, not by email.
  A SIGNula user's email address can change (they can update it from their
  SIGNula account); the pairwise `sub` for a given (user, client) pair does
  not change for the lifetime of the client registration. Matching on
  email would silently reassign an iHymns account to a different SIGNula
  identity if an email were ever reused/changed — matching on `sub` avoids
  that entirely.
- Remember: this `sub` is **pairwise per-client** — it is only ever
  meaningful within iHymns' own client registration. It is not the same
  value another SIGNula-integrated app sees for the same human, and it is
  not (and must not be treated as) an email address or a SIGNula internal
  user ID.

---

## Step 5 — Test

1. Click the button (or hit `login-start.php`) from a page served over
   HTTPS (or `http://localhost` while developing — `crypto.subtle`/PKCE
   requires a secure context).
2. Confirm you land on the SIGNula consent screen, and that **Allow**
   redirects back to your registered `redirect_uri` with
   `?code=...&state=...` matching what you generated.
3. Confirm `callback.php`/`callback.js` rejects a **forged/missing
   `state`** (try hitting the callback URL directly with a bogus `state`
   — it must refuse the callback, not silently proceed).
4. Confirm a **first-time** SIGNula user provisions a new iHymns account,
   and a **repeat** sign-in reuses the same iHymns account (check your
   `users.signula_sub` column).
5. Confirm **Deny** on the consent screen round-trips
   `?error=access_denied&error_description=...&state=...` and your handler
   surfaces a clean error instead of crashing.
6. If you registered `offline_access`, confirm a refresh
   (`grant_type=refresh_token`) works and rotates the `refresh_token`
   (single-use — the old one is now spent).

---

## Full reference / cross-links

- **Full endpoint/parameter/error reference:**
  `/docs/signin-with-signula` on the SIGNula site.
- **Platform-specific guides** (same patterns this reference adapts for
  iHymns): `/docs/oidc-server`, `/docs/oidc-web`, `/docs/oidc-native`.
- **Security checklist:** `/docs/oidc-security`.
- **Machine-readable spec** (exact request/response JSON schemas, error
  shapes, every parameter): `/api/docs/openapi.yaml` — `OIDC Provider` tag.
- **Button contract** (the `data-*` attributes, the
  `sessionStorage["signula:pkce:<state>"]` shape,
  `window.SignulaSignin.start()` programmatic API):
  `/assets/signin-button/README.md`.
