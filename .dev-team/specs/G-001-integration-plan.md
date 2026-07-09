# G-001 Integration Layer — "Sign in with SIGNula.id" (Issue #87)

> **Deep-planning pass** (Fable 5, 2026-07-09). Author altitude: **build contract** — written so a
> cheaper implementation model builds each stage "right the first time." **No code written here.**
> **Gate item:** GitHub #87 · **Depends on:** **G-001 provider** (`.dev-team/specs/G-001.md`), which
> depends on **G-003 JWT** (BUILT). This document REFINES G-001 provider stages A1–A5 into
> cycle-sized units AND adds the partner-facing integration layer (button · guides · iHymns · SDK).
> **Brand domains:** `SIGNulo.com` (marketing "shop window") / `SIGNulo.id` (auth hub, day-to-day).
> The codebase currently hardcodes `signula.id` / `SIGNula.id` in issuer + settings; this plan keeps
> those strings and flags the `SIGNulo` vs `SIGNula` spelling as an **owner decision** (§Open questions).

---

## 0. What already exists (verified — cite before you build)

| Thing you'll reuse | Where (absolute-ish path under `web/`) | Note |
|---|---|---|
| JWT signing facade | `private_html/security/Jwt.php` — `Jwt::sign($claims,$aud,$ttl)` / `Jwt::verify($jwt,$expect)` | RS256 pinned. **`sign()` hardcodes header `typ: at+jwt`** — see §A3 GOTCHA. |
| Signing keys + rotation | `private_html/security/KeyManager.php` — `getActiveKey/getPublicKey/getJwks` | encrypted priv key in `tblSettings` isSensitive + `_private` 0600 fallback. |
| Token issuance / refresh | `private_html/security/TokenService.php` — `issueTokens/refresh/revokeAccessJti/revokeFamily/verifyAccessToken` | opaque refresh, SHA-256 hashed, family rotation + reuse-detection. **`mintPair()` does NOT set `clientID`/`aud` on the refresh row** — see §A3 GOTCHA. |
| Public keys endpoint | `public_html/.well-known/jwks.json/index.php` (dir literally named `jwks.json`) + its own `.htaccess` | discovery `jwks_uri` points here. Root `.htaccess` line 29 re-allows the path. |
| Refresh-token table (RP-ready) | mig `030_jwt_authentication.sql` — `tblRefreshTokens` already has `clientID BIGINT UNSIGNED NULL COMMENT 'Reserved for G-001 RP clients (tblOAuthClients)'` + `scope` + `aud` | offline_access refresh tokens for RPs reuse this table with `clientID` set. |
| Revocation denylist | mig 030 — `tblRevokedTokens` (jti) + `tblUsers.tokensInvalidBefore` | consent-revoke → denylist jti + revoke family. |
| RP owner entity | mig `008_partner_api_keys.sql` — `tblPartners` (`partnerID BIGINT UNSIGNED`) + `tblAPIKeys` (secret stored as SHA-256 `keyHash`, shown once) | **client_secret follows the `tblAPIKeys` precedent exactly.** |
| Existing session / login | `Auth::isAuthenticated()`, `Auth::getCurrentUserID()`, `Auth::getCurrentUser()`; MFA/passkey/passwordless flows | authorize page drives these unchanged. |
| Open-redirect guard | `config.php` `sanitizeRedirectUrl()` | authorize uses **exact allowlist**, stricter than this. |
| Consumer OAuth (the inverse) | `public_html/oauth/authorize.php` (redirect-only, `purpose=email|signin`), `oauth/callback.php`, `api/oauth/disconnect.php`, `private_html/auth/OAuth*.php`, `auth/providers/*` | **DO NOT clobber** — provider endpoints get new filenames. Reuse the error-box HTML + state-token UX. |
| Clean-URL rewrite | `public_html/.htaccess` — `RewriteRule ^(.+?)/?$ $1.php` maps `/oauth/token` → `oauth/token.php`; static assets short-circuit (line 88); `.well-known/*.php` allowed (line 57) | new `.well-known/openid-configuration` needs the same directory-index trick as jwks. |
| API front-controller (JSON) | `public_html/api/v1/index.php` + `Router` + `BaseController` + `Response` | token/userinfo can be **standalone page handlers** (mirroring the JWKS handler) OR router controllers; §7 recommends standalone for `.well-known` + a controller for `/oauth/token`+`/oauth/userinfo`. |

### Brand / UI reality (verified — drives the button design decision)
- **NO SIGNula brand SVG logo/mark exists anywhere.** The "logo" is a Font Awesome glyph
  (`<i class="fas fa-shield-alt">`) + a text wordmark `SIGNula` (`private_html/layout/public-header.php:112`).
  Every page links `/assets/images/favicon.svg` + `.ico` and PWA PNG icons that **do not exist**
  (`assets/` holds only `css/` + `js/`). **→ a brand mark must be designed (stage IB0).**
- **Brand palette** (`public_html/assets/css/main.css:14`): `--primary-color:#667eea` (indigo),
  `--secondary-color:#764ba2` (purple), `--accent-color:#f093fb`; brand gradient
  `linear-gradient(135deg,var(--primary-color),var(--secondary-color))`. **No `--signula-*` vars** — vars are generic.
  (PWA `manifest.json` uses a *different* `theme_color:#007bff` — inconsistency flagged.)
- **Dark mode** = attribute selector `[data-theme="dark"]` in `assets/css/dark-mode.css` + `js/theme-toggle.js`
  (NOT `prefers-color-scheme`). The button's dark variant should honour the same `[data-theme="dark"]` hook,
  and *also* `prefers-color-scheme` for embedding on partner sites that don't set the attribute.
- **Social buttons** = `.oauth-btn .oauth-btn-{provider}` in `login.php` (data-driven `$oauthProviders`, filtered to
  configured `client_id`) + `register.php` (hardcoded). **Styles are inline `style="…"` (no shared CSS file).**
  Google/Microsoft ship **inline multi-path colour SVG**; the other 7 use Font Awesome `fab` glyphs.
  **No base64 fallback is used for these.** The only base64-SVG precedent is `AvatarService.php:892`
  (`'data:image/svg+xml;base64,'.base64_encode($svg)`) → the pattern to copy for the button's `<img>` fallback.
- **Consent screen: does NOT exist.** `oauth/authorize.php` only ever renders an inline error box (redirect-only). Build fresh.
- **Bootstrap 5.3.2 + Font Awesome 6.4.2 load from CDN** (not self-hosted yet) on auth pages; auth pages **inline their own `<head>`**
  and do NOT use `layout/public-header.php`. The button snippet must be **framework-free** (no Bootstrap/FA dependency) so partners can drop it anywhere.
- **Docs live in two trees:** marketing/dev docs `public_html/SIGNula.com/docs/` (`index.php`, `getting-started.php`,
  `integration-guide.php`, `api-reference.php`, `security.php`, `faq.php`); API spec `public_html/api/docs/`
  (`openapi.yaml` ~122KB + Swagger UI); provider **setup** HTML guides `public_html/docs/setup/*.html`.
- **Partner admin** = `public_html/partners/admin/*.php` (tiers, features, …) with `public_html/partners/api/*-actions.php`
  AJAX handlers + `partners/api-keys.php`. **OAuth-client CRUD slots in beside these, same UX.**
- **User settings hub** = `public_html/settings/*.php`; `connected-accounts.php` already lists **social logins SIGNula consumes** →
  new `connected-apps.php` lists **RP apps the user authorised** (distinct concept, distinct page).

### Migration number
Highest applied migration is **`030_jwt_authentication.sql`**. G-001 provider spec §4 tentatively said `026` — **stale**
(026 is `026_user_mfa_columns.sql`). **Use `031_oauth_idp.sql`.** (PROJECT.md: "migration numbers are conductor-assigned
sequentially AT BUILD TIME.")

---

## 1. Two GOTCHAS the token stage MUST handle (read before A3)

**GOTCHA-1 — `id_token` needs `typ: JWT`, but `Jwt::sign()` forces `typ: at+jwt`.**
`Jwt::sign()` (line 156) hardcodes `$header = ['typ' => self::TYP_ACCESS]` (`at+jwt`, RFC 9068 — correct for *access* tokens,
wrong for an OIDC *id_token*, which is a plain `JWT` per RFC 7519). It also defaults `iss`→`jwt.issuer`, `aud`→`jwt.audience`,
`scope`→''. **Build contract for A3:** add a minimal, RS256-pinned id_token minter to the facade — recommended
`Jwt::signIdToken(array $claims, string $aud, ?int $ttl = null): string` that reuses `KeyManager::getActiveKey()` +
`FirebaseJWT::encode(...)` with `$header = ['typ' => 'JWT']` (NOT `at+jwt`) and does **not** inject `scope`. Keep alg pinned
to `self::ALG` (RS256) and `kid` from the active key — identical to `sign()` except the header `typ` and the claim defaults.
Do **not** reuse `TokenService::issueTokens()` for the id_token (that mints an access+refresh pair). The RP **access token** is
fine as `at+jwt` via `Jwt::sign(..., $aud=client_id)`.

**GOTCHA-2 — RP refresh tokens must carry `clientID` (and ideally `aud`); `mintPair()` doesn't set them.**
`TokenService::mintPair()` INSERTs `tblRefreshTokens(userID,familyID,tokenHash,scope,…)` — it does **not** populate the
`clientID`/`aud` columns mig 030 reserved for RP tokens. **Build contract for A3:** add an RP issuance path — recommended
`TokenService::issueForClient(int $userID, int $clientID, array $scope, string $aud): array` (a thin wrapper/variant of
`mintPair` that also writes `clientID` + `aud`), and have `refresh()` preserve `clientID`/`aud` across rotation. Reuse the
existing family-rotation + reuse-detection machinery verbatim (do not fork it). The RP access token's `aud` = `client_id`.

**GOTCHA-3 — verifying an RP access token at `/userinfo`.** `Jwt::verify()`/`TokenService::verifyAccessToken()` default
`expect['aud']` to `jwt.audience` (the first-party API audience). An RP access token has `aud = client_id`. **`/userinfo` must
verify with `expect=['aud' => <the token's client_id>, 'typ' => 'at+jwt']`** — read the untrusted `aud` only to select which
client to check, then verify. Also ensure `oidc.issuer` (settings, default `https://signula.id`) **equals** `jwt.issuer`
(default `https://signula.id`) so `iss` is consistent across OIDC + G-003, or the id_token `iss` and discovery `issuer` will
mismatch. **Pin both to the same value at A1.**

---

## 2. Provider stages A1–A5 (refined from `G-001.md` §8; each = one autopilot cycle / one commit)

> **Hard prerequisite:** G-003 (BUILT ✅). All new files: `SIGNULA_INIT`-guard, `DIRECTORY_SEPARATOR`, prepared statements via
> `Database`, `htmlspecialchars(...,ENT_QUOTES,'UTF-8')` on all reflected output, PHP 8.3+ clean, emoji-annotated docblocks.
> All the §5 security items of `G-001.md` are **mandatory**, not optional.

### A1 — Client registration data model + partner admin
- **Goal:** a partner can register an OIDC client (public or confidential); redirect-URI exact-match + secret hash/verify are tested.
- **Key files:**
  - `_database/migrations/031_oauth_idp.sql` — `tblOAuthClients`, `tblOAuthAuthCodes`, `tblOAuthConsents`, `tblOAuthAccessGrants`
    + `oidc.*` settings + `INSERT INTO tblMigrations`. Copy the schema in `G-001.md` §4 verbatim **but**: (a) FK types match
    `tblPartners.partnerID` = **BIGINT UNSIGNED** (not INT); (b) set `oidc.issuer` to the SAME value as `jwt.issuer`; (c) add
    `oidc.access_ttl` (default 900) + `oidc.id_token_ttl` (default 3600) + `oidc.refresh_enabled` settings.
  - `private_html/oauth/OAuthClientManager.php` (NEW) — static class: `createClient()`, `getByIdentifier()`,
    `verifySecret(hash_equals)`, `redirectUriAllowed()` (**byte-exact** match against the JSON allowlist — no normalisation,
    no trailing-slash tolerance, no wildcard), `scopeAllowed()` (requested ⊆ allowedScopes), `regenerateSecret()` (returns
    plaintext once, stores `hash('sha256',…)`). Mirror `APIKeyManager`.
  - `public_html/partners/admin/oauth-clients.php` (NEW) — Bootstrap CRUD UI (list/create/edit/regenerate/deactivate),
    secret shown **once** in a copy box. Mirror `partners/api-keys.php`.
  - `public_html/partners/api/oauth-client-actions.php` (NEW) — AJAX handlers (CSRF-guarded POST), mirror `partners/api/*-actions.php`.
  - `_tests/Unit/OAuthClientManagerTest.php` (NEW) — redirect-URI exact-match incl. trailing-slash / case / port / scheme edge
    cases (all must FAIL to match unless byte-identical); scope subset; secret hash+verify; public-client-has-no-secret.
- **Acceptance:** register a client → row persisted, `clientIdentifier` random/unique, `clientSecretHash` set (confidential) or
  NULL (public), `requirePkce` defaults 1; `redirectUriAllowed()` rejects every non-byte-exact variant; unit tests green.
- **Depends on:** G-003 (done), `tblPartners`.
- **Model:** **Sonnet** (security-touching: secret hashing, exact-match allowlist). **Checker:** normal + a *targeted* redirect-URI
  bypass mini-review (not a full red-team — that's A5).

### A2 — `/oauth/authorize` + PKCE(S256) validation + consent screen + code issuance
- **Goal:** a logged-in user consents and a single-use PKCE-bound code round-trips to a registered redirect_uri with `state` echoed.
- **Key files:**
  - `public_html/oauth/authorize-idp.php` (NEW — URL `/oauth/authorize-idp`; **do NOT reuse `authorize.php`**; see §7 for URL
    decision). Validates `client_id` → active client; **byte-exact** `redirect_uri`; `scope` ⊆ allowedScopes; `response_type=code`;
    PKCE present + `code_challenge_method=S256` (reject `plain` when `oidc.allow_plain_pkce=0`; reject missing PKCE for public
    clients / when `oidc.require_pkce=1`). If `!Auth::isAuthenticated()` → redirect to `/login?redirect=<self>` (reuse existing
    login/MFA/passkey/passwordless — unchanged). Then render the **consent screen** (skip if `isFirstParty=1` OR a live
    `tblOAuthConsents` row already covers the requested scopes). On consent POST (CSRF via `SecurityUtils::generateCSRFToken`):
    mint a high-entropy code, store **SHA-256 hash** in `tblOAuthAuthCodes` bound to `clientID+userID+redirectURI+scope+nonce+
    codeChallenge+authTime`, TTL `oidc.authcode_ttl` (60s), then 302 to `redirect_uri?code=…&state=…`.
    **Fail-closed rule:** validate `redirect_uri` against the allowlist BEFORE emitting any redirect or error that includes it —
    an invalid `redirect_uri`/`client_id` renders a *local* error page (escaped), never a redirect.
  - `private_html/layout/oauth-consent.php` (NEW partial) OR inline — RP name/logo (escaped), plain-language scope list
    (map `openid/profile/email/offline_access` → human strings), Allow/Deny, "remember my decision" checkbox. **No-JS fallback**
    (plain form POST). WCAG: labelled buttons, focus order, 44px targets.
  - `private_html/oauth/AuthCodeService.php` (NEW) — `issueCode()`, `consumeCode()` (atomic single-use: `UPDATE … SET consumedAt=NOW()
    WHERE codeHash=? AND consumedAt IS NULL`, check `getAffectedRows()===1`), TTL check. Reused by A3.
  - `private_html/oauth/ConsentService.php` (NEW) — `hasConsent()`, `recordConsent()`, `revokeConsent()` over `tblOAuthConsents`.
  - Tests: `_tests/Unit/PkceTest.php` (S256 verify vector, plain rejected), `_tests/Unit/AuthCodeTest.php` (single-use consume,
    TTL expiry, client+redirect binding).
- **Acceptance:** unknown client / non-exact redirect / out-of-allowlist scope / missing-PKCE-public / plain-in-prod all rejected
  with a local escaped error; happy path records consent + redirects with `code`+echoed `state`; code stored hashed, 60s TTL.
- **Depends on:** A1.
- **Model:** **Sonnet** (browser-facing auth, PKCE, consent, CSRF). **Checker:** normal + consent-CSRF + open-redirect spot-check.

### A3 — `/oauth/token` + `id_token` + refresh (the crown jewel)
- **Goal:** the full auth-code+PKCE exchange yields access_token + id_token + (offline_access) refresh_token that verify against
  JWKS; a replayed code revokes the grant.
- **Key files:**
  - `public_html/oauth/token.php` (NEW — URL `/oauth/token`, JSON, POST only). Grants: `authorization_code` (validate via
    `AuthCodeService::consumeCode` — single-use, TTL, `redirect_uri` byte-match, `client_id` match, **PKCE verify**
    `hash_equals(base64url(sha256(code_verifier)), stored code_challenge)`; confidential-client auth via
    `OAuthClientManager::verifySecret` + `hash_equals`) and `refresh_token` (via `TokenService::refresh`, preserving clientID/aud).
    On success mint: RP **access_token** = `Jwt::sign(['sub'=>…,'scope'=>…], $aud=client_id, oidc.access_ttl)`; **id_token** =
    **`Jwt::signIdToken`** (GOTCHA-1) with OIDC claims `iss,sub,aud=client_id,exp,iat,auth_time,nonce`(echoed) + profile/email
    claims per granted scope; **refresh_token** (if `offline_access`) via `TokenService::issueForClient` (GOTCHA-2). Record
    `tblOAuthAccessGrants` (userID,clientID,scope,accessJti,refreshTokenID,expiresAt). Response includes `iss` (mix-up defence).
    **Code replay:** a second `consumeCode` returns 0 rows → revoke the grant's jti (`TokenService::revokeAccessJti`) + refresh
    family (`revokeFamily`) + raise a HIGH `SecurityAlertManager` alert (RFC 6749 §4.1.2 / §10.5).
  - `private_html/security/Jwt.php` — **ADD `signIdToken()`** (GOTCHA-1). Small, surgical; do not touch `sign()`/`verify()`.
  - `private_html/security/TokenService.php` — **ADD `issueForClient()`** + make `refresh()` carry `clientID`/`aud` (GOTCHA-2).
  - `private_html/oauth/IdTokenBuilder.php` (NEW) — assembles OIDC claims for a userID+scope (profile→name/picture/…, email→email/
    email_verified), honours `oidc.subject_type` (public = raw userID; pairwise = per-RP opaque `sub` = `hash_hmac('sha256',
    userID.'|'.clientID, <server pairwise salt>)`).
  - Tests: `_tests/Unit/IdTokenTest.php` (claims present, nonce echo, aud=client_id, verifies via G-003 JWKS — reuse G-003
    fixtures); `_tests/Integration/OAuthFlowTest.php` (authorize→consent→code→token→verify; refresh grant; **code replay revokes
    grant**; code minted for client A rejected for client B / different redirect_uri).
- **Acceptance:** all §9 token criteria of `G-001.md`; tokens verify in an independent OIDC client against `/.well-known/jwks.json`;
  replay revokes grant + alert; cross-client/redirect code redemption denied.
- **Depends on:** A2, G-003.
- **Model:** **Sonnet.** *Justification for not using Opus:* the crypto primitives (RS256 sign/verify, PKCE hash, refresh
  rotation, jti denylist) are all **already built + red-teamed in G-003** and reused verbatim; A3 is composition + two small,
  fully-specified facade additions (GOTCHA-1/2). If an implementer stumbles on the `signIdToken`/`issueForClient` seams,
  **escalate only those two methods to Opus** — not the whole stage. **Checker:** normal now; the mandatory red-team is A5.

### A4 — `/oauth/userinfo` + OIDC discovery + revocation + user "Connected Apps" hub + admin oversight
- **Goal:** an external OIDC RP completes login end-to-end; a user can view + revoke an app.
- **Key files:**
  - `public_html/oauth/userinfo.php` (NEW — `/oauth/userinfo`, GET, Bearer). Verify access token with `expect['aud']` = its own
    `client_id` (GOTCHA-3) + `typ=at+jwt`; return claims **for granted scopes only** (reuse `IdTokenBuilder` claim maps).
  - `public_html/.well-known/openid-configuration/index.php` (NEW — dir literally named `openid-configuration`, mirroring the
    jwks dir trick) + co-located `.htaccess`. Emit discovery JSON: `issuer` (=`oidc.issuer`), `authorization_endpoint`,
    `token_endpoint`, `userinfo_endpoint`, `jwks_uri` → **existing** `/.well-known/jwks.json`, `response_types_supported:["code"]`,
    `grant_types_supported:["authorization_code","refresh_token"]`, `scopes_supported`, `id_token_signing_alg_values_supported:["RS256"]`,
    `code_challenge_methods_supported:["S256"]`, `subject_types_supported`, `revocation_endpoint`. Add root `.htaccess` re-allow
    for the new dot-dir (copy line 29 pattern).
  - `public_html/oauth/revoke.php` (NEW — `/oauth/revoke`, RFC 7009) OR fold into `token.php`. Client-authenticated token revocation.
  - `public_html/settings/connected-apps.php` (NEW — distinct from `connected-accounts.php`): list `tblOAuthConsents` +
    `tblOAuthAccessGrants`; **Revoke** → `ConsentService::revokeConsent` + denylist jti (`TokenService::revokeAccessJti`) +
    `TokenService::revokeFamily` for offline_access. Uses `settings-sidebar.php`.
  - `public_html/admin/oauth-clients.php` (NEW) — global admin: list/disable any client, view grants (oversight).
  - Tests: extend `OAuthFlowTest` — userinfo returns only granted-scope claims; discovery doc valid; consent revoke denylists tokens.
- **Acceptance:** discovery doc valid (external validator); userinfo scope-gated; hub revoke stops the RP acting for the user.
- **Depends on:** A3.
- **Model:** **Sonnet** (userinfo scope-gating + revocation are security-touching). *The discovery JSON + hub table are mechanical,
  but keep the stage Sonnet for the revocation wiring.* **Checker:** normal.

### A5 — Hardening + mandatory red-team + provider docs
- **Goal:** red-team round is dry; provider docs let an RP integrate unaided.
- **Key files:** rate-limiting on `authorize-idp`/`token`/`revoke` (reuse `SecurityUtils::checkRateLimit` — the windowed-SUM
  limiter, per B-049 fix); full `G-001.md` §5 pass; `public_html/api/docs/openapi.yaml` += `/oauth/*` paths + schemas; discovery
  doc cross-check; `CHANGELOG.md` / `PROJECT_PROGRESS.md` / `_docs/setup/SECURITY_SETUP.md` (OIDC provider section).
- **Acceptance:** `G-001.md` §9 fully met; red-team dry.
- **Depends on:** A4.
- **Model:** **Sonnet** for the build/doc work; **RED-TEAM by Fable/Opus reasoning** (route via `dev-team-security`) — attack:
  redirect-URI allowlist bypass (normalisation/encoding tricks), PKCE downgrade/strip, code replay + cross-client redemption,
  scope escalation beyond consent/allowlist, IdP mix-up (`iss` check), open-redirect via the error path, consent-CSRF, confidential-
  vs-public client confusion (public client presenting a secret), pairwise-sub correlation. **This is a HARD red-team gate.**

**Provider build order:** A1 → A2 → A3 → A4 → A5. **The integration layer (below) can start once A1–A3 exist** (button + guides
need a working authorize+token+PKCE flow to demonstrate; A4 userinfo/discovery is needed for the server-verification guide).

---

## 3. Integration layer stages (Issue #87)

### IB0 — SIGNula brand mark (SVG) + button icon asset
- **Goal:** a distinctive, reusable SIGNula SVG mark exists (none does today) to put in the button + favicon + PWA.
- **Decision:** **NO brand asset exists → design a simple, distinctive placeholder mark** (owner can replace later; §Open questions).
  **Proposed mark spec** — a rounded-square "identity badge" that reads at 16px and mono:
  - `viewBox="0 0 32 32"`; rounded-square tile (`rx=7`) filled with the **brand gradient**
    `linear-gradient(135deg,#667eea,#764ba2)` (defined as an SVG `<linearGradient id="sg">`).
  - A negative-space **"S" monogram** OR a **keyhole/shield-key** glyph (nods to the existing `fa-shield-alt` stand-in and the
    "identity/sign-in" meaning) in white (`#fff`), centered, ~62% of the tile, single path, even-odd fill so it works as a mask.
  - Provide **three files** under `public_html/assets/images/brand/`:
    `signula-mark.svg` (gradient, full colour), `signula-mark-mono.svg` (single `currentColor` path, for icon-only/dark),
    `signula-mark-white.svg` (white on transparent, for coloured buttons). Each ≤ ~2KB, no external refs.
  - **Also emit** `signula-mark.png` (512px, for PWA/OG) + a `favicon.svg` + `favicon.ico` to fix the repo-wide broken references
    (§0). Wire `manifest.json` `theme_color` to `#667eea` (fix the `#007bff` drift) — flag as a small bonus fix.
- **Key files:** `public_html/assets/images/brand/*` (NEW dir — first real `assets/images/`); optional favicon/PWA icon fixes.
- **Acceptance:** mark renders crisply at 16/24/48/512px in light+dark; validates (no external refs); ≤2KB each SVG.
- **Depends on:** none (pure asset). **Model:** **Haiku** (static SVG/asset authoring; deterministic spec above). **Checker:** normal
  (visual sanity + validate SVG). **Owner sign-off:** flag the mark for owner approval before it's treated as final brand.

### IB1 — "Sign in with SIGNula.id" button (asset + CSS + variants + self-serve page)
- **Goal:** a branded, WCAG-AA, framework-free button with all standard variants + a hosted "get the button" page.
- **Design (matches Apple/Google/Microsoft conventions + SIGNula brand):**
  - **Base:** the SIGNula mark (IB0) + label "Sign in with SIGNula.id" (or "Continue with SIGNula.id"). Left-aligned mark, centered
    label, min-height **44px** (touch target), `border-radius` variants below.
  - **Variants (data-attributes or CSS classes):** theme `light` (white bg, `#1a1d23` text, 1px `#dadce0` border) / `dark`
    (`#1a1d23` bg, white text) / `brand` (gradient bg, white text, white mark); shape `rounded` (8px) / `pill` (999px);
    size `sm`/`md`/`lg`; `icon-only` (square, mark only, `aria-label="Sign in with SIGNula.id"`); `full-width`.
    Honour BOTH `[data-theme="dark"]` (SIGNula's hook) and `@media (prefers-color-scheme: dark)` for auto-theming on partner sites.
  - **SVG-with-base64 fallback:** inline `<svg>` primary; for the `<img>`-based drop-in, ship
    `src="…/signula-mark.svg"` with a base64 `onerror` fallback (the `AvatarService.php:892` pattern). Document both.
  - **WCAG AA:** text/bg contrast ≥ 4.5:1 for every variant (verify each), visible `:focus-visible` ring (2px, `--accent`),
    `aria-label` on icon-only, `role`/`<button>` or `<a>` semantics, keyboard-activatable, `prefers-reduced-motion` disables the hover transform.
  - **Drop-in snippet:** framework-free HTML + minimal JS that starts auth-code + **PKCE(S256)**: generate `code_verifier`
    (43–128 char, `crypto.getRandomValues`), `code_challenge=base64url(SHA-256(verifier))` via WebCrypto `subtle.digest`,
    random `state` + `nonce`, stash verifier+state+nonce in `sessionStorage`, then redirect to
    `https://signula.id/oauth/authorize-idp?response_type=code&client_id=…&redirect_uri=…&scope=openid profile email&state=…&code_challenge=…&code_challenge_method=S256&nonce=…`.
    **No client secret in the browser.** Provide a no-WebCrypto note (must be HTTPS; WebCrypto is standard on all target browsers).
- **Key files:**
  - `public_html/assets/css/signula-button.css` (NEW — self-contained, no Bootstrap/FA dep).
  - `public_html/assets/js/signula-button.js` (NEW — the PKCE-redirect helper; also usable standalone, see IB2).
  - `public_html/SIGNula.com/buttons/index.php` (NEW — a **self-serve "get the button" page**): live previews of every variant,
    copy-paste HTML/CSS/JS, a config box (client_id, redirect_uri, scopes), download links for the assets, and brand-usage guidelines
    (clear space, min size, do/don't, don't recolor the mark, don't rename). Mirrors Google's "Sign in with Google" branding page.
  - Hosted assets served from `SIGNulo.id/assets/…` (self-host; CDN-optional per house rule).
- **Acceptance:** every variant renders + passes contrast; button starts a *working* PKCE flow against A2/A3; icon-only has
  `aria-label`; keyboard + reduced-motion honoured; "get the button" page shows copy-paste snippets.
- **Depends on:** IB0; **A2+A3** (needs a live authorize+token flow to actually work). **Model:** **Haiku** for the CSS/SVG/variants/
  branding page (mechanical, spec'd); **Sonnet** for `signula-button.js` (the PKCE/WebCrypto logic is security-sensitive — verifier
  entropy, S256 correctness, state/nonce handling). **Split the commit or tag the JS file Sonnet.** **Checker:** normal + a JS-PKCE correctness review.

### IB2 — Optional tiny vanilla-JS SDK helper (PKCE + redirect + callback)
- **Goal:** a self-hostable ~3KB `signula.js` that does PKCE-redirect (IB1's start) **and** callback handling (parse `?code&state`,
  check `state` from sessionStorage, POST to the partner's own backend token-exchange, expose `onLogin`).
- **Key files:** `public_html/assets/js/signula-sdk.js` (NEW) + a usage snippet on the buttons page + in the Web/PWA guide.
  Document that **token exchange happens server-side** for confidential clients; for public SPA clients the SDK can call `/oauth/token`
  directly (PKCE, no secret) but **should still keep tokens out of `localStorage`** (memory or same-site cookie via the partner backend).
- **Acceptance:** SDK completes a round-trip against a test client; state mismatch rejected; documented for both public + confidential.
- **Depends on:** IB1, A3. **Model:** **Sonnet** (client-side auth/token handling is security-sensitive). **Checker:** normal + PKCE/state review.

### IG1 — Integration guides part 1: Quickstart + Web/PWA
- **Goal:** a partner can go zero-to-login by following prose.
- **Content:**
  - **Quickstart:** register a client (link to `partners/admin/oauth-clients.php`) → add the button (IB1) → the PKCE redirect →
    token exchange (`/oauth/token`) → `/oauth/userinfo` → logout/revoke. End-to-end, one page, copy-paste.
  - **Web/PWA guide:** redirect + PKCE in the browser, session handling, PWA specifics (service-worker doesn't intercept the OAuth
    redirect; `redirect_uri` must be a real navigable URL; handling the callback in an installed PWA), confidential (server exchange)
    vs public (SPA direct) split.
- **Key files:** `public_html/SIGNula.com/docs/integration-quickstart.php` (NEW) + extend the existing
  `SIGNula.com/docs/integration-guide.php` (Web/PWA) + add nav entries to `docs/index.php`. (Match the existing docs page style/layout.)
- **Acceptance:** a reader can register→button→flow→tokens→userinfo→logout from the page alone; snippets match IB1/IB2.
- **Depends on:** IB1, A1–A4. **Model:** **Haiku** (docs prose; the technical decisions are all made above). **Checker:** normal (accuracy pass).

### IG2 — Integration guides part 2: Native (iOS+Android) + Server-side verification + Security best-practice
- **Goal:** cover mobile PKCE + deep-link redirects, JWKS/id_token verification, and the security checklist.
- **Content:**
  - **Native (iOS + Android):** **public client, no secret, PKCE mandatory.** iOS = `ASWebAuthenticationSession` + a custom-scheme
    or **Universal Link** `redirect_uri`; Android = **Custom Tabs** + custom-scheme or **App Links** `redirect_uri`; note App-Links/
    Universal-Links verification files. The RP registers the app's deep-link `redirect_uri`(s) in `tblOAuthClients` (exact match).
    Warn against embedded WebViews (blocked/insecure).
  - **Server-side verification:** fetch `/.well-known/openid-configuration` → `jwks_uri` → verify id_token signature (RS256),
    check `iss` (= `oidc.issuer`), `aud` (= your client_id), `exp`, `nonce` (matches the one you sent), `auth_time`. Cache JWKS,
    refetch on unknown `kid`. Provide PHP + Node + Python snippets (using each ecosystem's JWKS lib).
  - **Security best-practice:** always `state` (CSRF) + `nonce` (replay) + PKCE(S256); exact `redirect_uri` registration; token
    storage (never `localStorage` for refresh tokens; server-side or secure cookie; mobile keychain/keystore); short access-token
    life + refresh rotation; logout = revoke.
- **Key files:** `public_html/SIGNula.com/docs/integration-native.php` (NEW), `docs/integration-verify.php` (NEW), extend
  `SIGNula.com/docs/security.php` with the OIDC checklist; nav entries.
- **Acceptance:** native guide covers both platforms + deep-link redirect + no-secret PKCE; verification guide has working
  multi-language snippets; security page has the full checklist.
- **Depends on:** A4 (discovery+userinfo), IB1. **Model:** **Haiku** (docs prose; snippets are standard, spec'd here). **Checker:** normal + snippet accuracy.

### II1 — iHymns first-party reference integration (GUIDE + copy-paste example — does NOT modify the iHymns repo)
- **Goal:** a concrete worked example wiring iHymns (web/PWA + native) to "Sign in with SIGNula.id."
- **Context (verified via GitHub):** `MWBMPartners/iHymns` — PHP-heavy web app under `appWeb/` (`public_html/` with `.htaccess`,
  `api.php`, `manifest.json`, `service-worker.js.php`, `css/`, `js/`, `includes/`), a `.well-known/` dir, plus native `appApple/`
  (Swift) + `appAndroid/` (Kotlin). So iHymns already has the web/PWA + native surfaces the guide targets. **This repo delivers the
  guide + copy-paste code; it does NOT commit into the iHymns repo.**
- **Content:**
  - **What iHymns registers** in `tblOAuthClients`: a **first-party** client (`isFirstParty=1` → consent can be skipped) — public
    (SPA/PWA) + native redirect URIs: e.g. web `https://ihymns.<domain>/auth/callback`; native `ihymns://auth/callback` (custom
    scheme) + Universal/App-Link `https://ihymns.<domain>/app/auth/callback`; scopes `openid profile email`; `offline_access` if
    iHymns wants long-lived sessions.
  - **iHymns web/PWA worked example:** drop-in button (IB1) + `signula-sdk.js` (IB2) callback handling → server-side token exchange
    in a small `appWeb/public_html/auth/signula-callback.php`-style handler (example code, provided as a copy-paste block in the
    guide) → verify id_token (IG2 PHP snippet) → create/link the iHymns local session.
  - **iHymns native worked example:** iOS `ASWebAuthenticationSession` (Swift snippet) + Android Custom Tabs (Kotlin snippet), PKCE,
    deep-link redirect back into the app, token exchange, keychain/keystore storage.
- **Key files:** `public_html/SIGNula.com/docs/integration-ihymns.php` (NEW — the worked example, self-contained copy-paste code) +
  nav entry. (All example code lives INSIDE this SIGNula docs page — nothing is written to the iHymns repo.)
- **Acceptance:** the guide is a complete, copy-paste iHymns integration (web/PWA + native) citing the exact client registration
  (redirect URIs + scopes) and reusing IB1/IB2/IG2; explicitly states it does not modify the iHymns repo.
- **Depends on:** IB1, IB2, IG1, IG2, A1–A4. **Model:** **Sonnet** (the worked example includes security-sensitive token-exchange +
  id_token-verification code that must be correct — it's the reference others copy). **Checker:** normal + a correctness pass on the example.

---

## 4. Full ordered stage list + model tags (the sequencing ask)

| # | Stage | Goal (1-line) | Model | Red-team? | Depends on |
|---|---|---|---|---|---|
| 1 | **A1** | Client reg data model (`tblOAuthClients` +3, mig 031) + partner admin CRUD + `OAuthClientManager` | **Sonnet** | targeted redirect/secret mini-review | G-003 ✅ |
| 2 | **A2** | `/oauth/authorize-idp` + PKCE(S256) validate + consent screen + hashed single-use code | **Sonnet** | consent-CSRF + open-redirect spot-check | A1 |
| 3 | **A3** | `/oauth/token`: code+PKCE+refresh → access + **id_token** (+`Jwt::signIdToken`, `TokenService::issueForClient`); replay revokes grant | **Sonnet** (escalate only the 2 facade methods to Opus if stuck) | at A5 | A2, G-003 |
| 4 | **A4** | `/oauth/userinfo` + `/.well-known/openid-configuration` + `/oauth/revoke` + user "Connected Apps" hub + admin oversight | **Sonnet** | normal | A3 |
| 5 | **IB0** | SIGNula brand SVG mark (design — none exists) + favicon/PWA fix | **Haiku** | normal | — |
| 6 | **IB1** | "Sign in with SIGNula.id" button (CSS+variants+branding page = Haiku; PKCE `signula-button.js` = Sonnet) | **Haiku + Sonnet** | JS-PKCE review | IB0, A2, A3 |
| 7 | **A5** | Rate-limit + full §5 hardening + **mandatory red-team** + provider OpenAPI/docs | **Sonnet build + Fable/Opus red-team** | **YES (hard gate)** | A4 |
| 8 | **IB2** | Optional vanilla-JS SDK (`signula-sdk.js`) — PKCE + callback handling | **Sonnet** | PKCE/state review | IB1, A3 |
| 9 | **IG1** | Guides: Quickstart + Web/PWA | **Haiku** | normal | IB1, A1–A4 |
| 10 | **IG2** | Guides: Native (iOS+Android) + Server-side verify + Security best-practice | **Haiku** | normal | A4, IB1 |
| 11 | **II1** | iHymns reference integration (guide + copy-paste example; no iHymns repo change) | **Sonnet** | correctness pass | IB1, IB2, IG1, IG2, A1–A4 |

**Notes on ordering:** the provider core (A1→A2→A3→A4) must lead. **IB0/IB1 can interleave after A3** (a real flow to demo), which is
why IB0/IB1 sit before A5 in the table — they don't depend on hardening. **A5's red-team is the one hard gate** (Fable/Opus). Guides
(IG1/IG2) + iHymns (II1) come last because they document the finished surface. If cycles are scarce, IB2 (optional SDK) can be deferred
without blocking anything except a nicer II1.

**Model-policy rationale:** Haiku for static assets / SVG / CSS / docs prose / config (IB0, IB1-CSS, IG1, IG2). Sonnet for every
`/oauth/*` endpoint, PKCE, consent, token exchange, and any browser/mobile auth JS (A1–A5 build, IB1-JS, IB2, II1). **No stage
warrants Opus wholesale** — the sharp crypto is inherited from the red-teamed G-003 foundation; the only Opus call-out is a *targeted*
escalation for the two small facade seams in A3 if an implementer stalls, plus **Fable/Opus reasoning for the A5 red-team** (attacker
mindset, not code volume).

---

## 5. Button design decision (summary)
- **Brand asset:** **NONE exists** (Font Awesome `fa-shield-alt` glyph + "SIGNula" wordmark stand in; all favicon.svg/.ico + PWA PNGs
  are broken references). → **Design a placeholder mark** (IB0): a 32×32 rounded-square gradient tile (`#667eea`→`#764ba2`) with a
  white "S"/keyhole monogram, shipped as full-colour + mono + white SVGs (+PNG/favicon). **Owner sign-off needed before it's "the brand."**
- **Button variants:** theme `light`/`dark`/`brand`(gradient); shape `rounded`/`pill`; size `sm`/`md`/`lg`; `icon-only`; `full-width`;
  auto-theme via `[data-theme=dark]` **and** `prefers-color-scheme`. WCAG AA (≥4.5:1 contrast per variant, `:focus-visible`, 44px,
  `aria-label` on icon-only, reduced-motion). Framework-free (no Bootstrap/FA dependency). SVG primary + base64 `<img>` fallback
  (the `AvatarService` pattern). Hosted at `SIGNulo.id/assets/{css,js,images/brand}` + a self-serve "get the button" page at
  `SIGNula.com/buttons/`.

## 6. Guides structure (summary)
Live under **`public_html/SIGNula.com/docs/`** (matches the existing dev-docs tree; add nav entries to `docs/index.php`):
- `integration-quickstart.php` (IG1) · extend `integration-guide.php` for Web/PWA (IG1)
- `integration-native.php` (IG2) · `integration-verify.php` (IG2) · extend `security.php` with the OIDC checklist (IG2)
- `integration-ihymns.php` (II1)
- Machine reference: `/oauth/*` paths added to `public_html/api/docs/openapi.yaml` (A5) + the OIDC discovery doc is itself self-describing.

## 7. Key path/URL decisions baked in
- **Provider endpoints (new files, never clobber the consumer stack):** `/oauth/authorize-idp` (`oauth/authorize-idp.php`),
  `/oauth/token` (`oauth/token.php`), `/oauth/userinfo` (`oauth/userinfo.php`), `/oauth/revoke` (`oauth/revoke.php`),
  `/.well-known/openid-configuration` (dir-index handler + `.htaccess`), `/.well-known/jwks.json` (**existing**).
  - **Open naming question:** the OIDC spec/consent UX would ideally live at the clean `/oauth/authorize`, but that path is taken by
    the consumer redirect endpoint. **Options (owner/lead decide at A2):** (a) ship as `/oauth/authorize-idp` (safe, zero-risk,
    recommended for first build); (b) later add an `.htaccess` branch on the `provider`/`client_id` param to serve the IdP handler
    from `/oauth/authorize` and rename the consumer to `/oauth/link`. **Plan assumes (a).** Discovery doc advertises whatever is chosen.
- **Migration:** `031_oauth_idp.sql` (NOT 026 — stale in the spec). FK types = BIGINT UNSIGNED to match `tblPartners`.
- **Issuer consistency:** `oidc.issuer` MUST equal `jwt.issuer` (both default `https://signula.id`) — pin at A1.

## 8. Open questions / decisions for the owner
1. **Brand spelling + mark.** The brief's domains are **SIGNulo.com / SIGNulo.id**, but the codebase + issuer settings say
   **SIGNula / signula.id**. Which is canonical for the button label + `oidc.issuer` + docs? (Affects every user-facing string +
   the id_token `iss`.) **And:** approve/replace the proposed placeholder SVG mark (IB0) before it's treated as final brand.
2. **Docs URL home.** Confirm guides live at `SIGNula.com/docs/` (marketing tree) vs a dedicated `developers.SIGNulo.id` subdomain.
   Plan assumes `SIGNula.com/docs/`.
3. **`subject_type`.** `public` (raw userID `sub`) vs `pairwise` (per-RP opaque `sub`, privacy-preserving). Plan implements both,
   default `public` per the spec; owner may prefer `pairwise` default for a privacy-first CIAM stance.
4. **`client_credentials` grant.** `G-001.md` §2 makes it optional (per-client flag) for pure M2M RPs. Include in A3 or defer? Plan defers (auth_code+refresh first).
5. **First-party consent skip.** Confirm iHymns (and other first-party apps) get `isFirstParty=1` (consent screen skipped, consent
   still recorded). Plan assumes yes.
6. **Optional SDK (IB2).** Ship the tiny `signula-sdk.js`, or leave partners with the copy-paste snippets only? Plan includes it but marks it optional.
7. **`/oauth/authorize` naming** (see §7) — accept `-idp` suffix for the first build, or invest in the `.htaccess` param-branch now?
8. **PWA/favicon fix scope.** IB0 can also fix the repo-wide broken `favicon.svg`/`.ico` + PWA PNG references + the `theme_color`
   drift (`#007bff`→`#667eea`) as a bonus — confirm that's in scope or keep IB0 to just the button mark.

## 9. Cross-references
- Provider backend spec: `.dev-team/specs/G-001.md` (§4 schema, §5 security — all mandatory, §8 original stages).
- JWT foundation: `.dev-team/specs/G-003.md`; built in `private_html/security/{Jwt,KeyManager,TokenService}.php`.
- Issue: GitHub #87. Related: #86 (API completeness + OpenAPI), #80 (OpenAPI docs).
- iHymns: `github.com/MWBMPartners/iHymns` (`appWeb/` PHP+PWA, `appApple/` Swift, `appAndroid/` Kotlin) — reference only, not modified.
