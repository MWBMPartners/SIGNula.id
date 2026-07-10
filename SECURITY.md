# Security Policy

## Supported Versions

The following versions of SIGNula are currently supported with security updates:

| Version       | Supported          | Notes                          |
| ------------- | ------------------ | ------------------------------ |
| 2.6.x-beta    | :white_check_mark: | Current development release    |
| 2.5.x-beta    | :white_check_mark: | Previous release, patch only   |
| 2.4.x-beta    | :white_check_mark: | Legacy, critical patches only  |
| < 2.4.0       | :x:                | No longer supported            |

## Reporting a Vulnerability

We take the security of SIGNula seriously. If you believe you have found a security vulnerability, please report it responsibly.

### How to Report

**DO NOT** report security vulnerabilities through public GitHub issues, discussions, or pull requests.

Instead, please use one of the following methods:

1. **GitHub Security Advisories (Preferred)**
   - Navigate to [Security Advisories](https://github.com/MWBMPartners/SIGNula.id/security/advisories/new)
   - Create a new private security advisory
   - Provide as much detail as possible

2. **Email**
   - Send details to: **security@signula.id**
   - Use our PGP key for encrypted communication (available upon request)
   - Include "[SECURITY]" in the subject line

### What to Include

When reporting a vulnerability, please include:

- **Description** of the vulnerability and its potential impact
- **Steps to reproduce** the issue (proof of concept if possible)
- **Affected component(s)** (authentication, API, payments, etc.)
- **Affected version(s)** of SIGNula
- **Any potential mitigations** you have identified
- **Your contact information** for follow-up questions

### Response Timeline

| Stage                     | Target Timeline        |
| ------------------------- | ---------------------- |
| Acknowledgement           | Within 24 hours        |
| Initial assessment        | Within 72 hours        |
| Status update             | Within 7 days          |
| Fix development           | Within 30 days*        |
| Public disclosure          | After fix is deployed  |

*Critical vulnerabilities may be addressed sooner. Complex issues may require additional time, in which case we will provide regular updates.

### What to Expect

1. **Acknowledgement** -- We will acknowledge receipt of your report within 24 hours.
2. **Assessment** -- Our security team will evaluate the report and determine its severity and scope within 72 hours.
3. **Updates** -- We will keep you informed of our progress throughout the remediation process.
4. **Resolution** -- Once a fix is developed and tested, we will deploy it and notify you.
5. **Disclosure** -- We will coordinate public disclosure with you after the fix is available.

### Severity Classification

We use the following severity levels based on [CVSS v3.1](https://www.first.org/cvss/):

| Severity   | CVSS Score | Examples                                                    |
| ---------- | ---------- | ----------------------------------------------------------- |
| Critical   | 9.0 - 10.0 | Remote code execution, authentication bypass, data breach  |
| High       | 7.0 - 8.9  | Privilege escalation, SQL injection, XSS with high impact  |
| Medium     | 4.0 - 6.9  | CSRF, information disclosure, session fixation             |
| Low        | 0.1 - 3.9  | Minor information leaks, non-exploitable issues            |

## Scope

### In Scope

The following are in scope for security reports:

- SIGNula authentication system (all MFA methods, session management, password handling)
- RESTful API endpoints and authentication
- Payment processing integration (PayPal, Stripe, Coinbase Commerce)
- OAuth provider integrations
- Admin panel and partner dashboard
- Database security (encryption, prepared statements, access control)
- Email system and template rendering (HTML, AMP for Email)
- Webhook signature verification
- Rate limiting and brute-force protection
- Mass credential reset system (password invalidation, salt rotation)
- Avatar management system (upload, OAuth, fallback services)
- Form protection (honeypot, HMAC timing, JS challenge)
- CAPTCHA integration (CloudFlare Turnstile, reCAPTCHA v3)
- IP reputation checking (AbuseIPDB, proxycheck.io)
- Bot detection (CrawlerDetect, regex fallback)
- Session fingerprinting and integrity validation

### Out of Scope

The following are out of scope:

- Third-party services and their own vulnerabilities (PayPal, Stripe, Google, Microsoft, etc.)
- Social engineering attacks against SIGNula team members
- Denial of service (DoS/DDoS) attacks
- Physical security of hosting infrastructure
- Issues in dependencies that are already publicly known and have patches available
- Vulnerabilities requiring physical access to a user's device

## Security Best Practices

SIGNula follows these security practices:

- **Encryption**: All sensitive data is encrypted at rest using industry-standard algorithms with salting
- **Prepared Statements**: All database queries use MySQLi prepared statements to prevent SQL injection
- **CSRF Protection**: All forms include CSRF token validation
- **Rate Limiting**: API endpoints and authentication attempts are rate-limited
- **Activity Logging**: All account activity is logged for security auditing
- **Session Security**: Secure session management with proper expiration and regeneration
- **Input Validation**: All user input is validated and sanitised
- **Output Encoding**: All output is properly encoded to prevent XSS
- **HTTPS**: All communications are encrypted in transit via TLS

## Recognition

We appreciate the security research community's efforts in helping keep SIGNula and its users safe. Researchers who responsibly disclose valid vulnerabilities will be:

- Credited in our security acknowledgements (unless anonymity is preferred)
- Listed in our Hall of Fame (for significant findings)
- Notified when the vulnerability is fixed and disclosed

## Contact

- **Security Reports**: security@signula.id
- **GitHub Security Advisories**: [Create Advisory](https://github.com/MWBMPartners/SIGNula.id/security/advisories/new)
- **General Security Questions**: security@signula.id

---

*This security policy is effective as of February 2026 and will be reviewed and updated periodically.*

---

# OAuth2/OIDC Provider — Red-Team Findings Register (G-001)

> Independent adversarial review of the SIGNula OAuth2/OIDC **provider** surface
> (`/oauth/authorize-idp`, `/oauth/token`, `/oauth/userinfo`, `/oauth/revoke`,
> `/.well-known/*`). Authorized testing against the local `signula_test` DB.
> Reviewer model: **Claude Fable 5**. Date: 2026-07-10.

**OVERALL VERDICT: SHIP WITH CAUTION — no auth-bypass/token-forgery Critical found,
but ONE High-impact privacy defeat (F-01) undermines the advertised `pairwise`
subject guarantee.** The crypto core (RS256 pinning, alg-confusion/none defence,
kid-traversal fix, JWKS private-material stripping), redirect_uri exact-match,
PKCE policy, atomic code single-use + replay family-revoke, and B-058 cross-client
refresh binding are all **solid and were not broken**. Single most important thing
to fix: **F-01 — stop putting the raw `userID` in the access-token `sub`; use the
same pairwise subject the id_token/userinfo already use.**

## Findings

| ID   | Severity | Title                                                                 | Status              | PoC / Evidence |
| ---- | -------- | --------------------------------------------------------------------- | ------------------- | -------------- |
| F-01 | High     | Access-token `sub` leaks raw `userID`, defeating pairwise correlation | Confirmed (PoC)     | `_tests/Security/OAuthProviderRedTeamTest.php::testAccessTokenLeaksRawUserIdDefeatingPairwiseCorrelation` — `TokenService::mintPair()` `web/private_html/security/TokenService.php:720` |
| F-02 | Low–Med  | Refresh grant re-mints scope removed from client's current allowedScopes | Confirmed (PoC)   | `...::testRefreshReminttsScopeRemovedFromClientAllowlist` — `OAuthTokenService::exchangeRefreshToken()` / `TokenService::refresh()` `web/private_html/security/TokenService.php:372` |
| F-03 | Low      | Auth-code consumed BEFORE client-binding check → foreign client burns a code (DoS) | Confirmed (PoC) | `...::testForeignClientBurnsVictimsAuthorizationCode` — `OAuthTokenService::exchangeAuthorizationCode()` `web/private_html/auth/OAuthTokenService.php:265` |
| F-04 | Medium   | Per-IP rate limit (B-059) bypassable via `X-Forwarded-For` spoofing   | Confirmed (static)  | `getClientIP()` trusts forwarded headers `web/_config/config.php:531-548` |
| F-05 | Medium   | `oidc.enabled` master switch (seeded `0`) is never enforced — IdP always live | Confirmed (static) | Only reference is the migration seed `_database/migrations/031_oauth_provider_clients.sql:297`; no code reads it |

### F-01 — Access-token `sub` leaks the raw userID (pairwise defeat) · **High** · Confirmed
- **Vector:** 8 (pairwise sub reversibility/correlation).
- **What:** `TokenService::mintPair()` signs the RS256 access token with
  `'sub' => (string)$userID` (`TokenService.php:720`). The id_token and
  `/oauth/userinfo` correctly return the **pairwise** opaque sub
  (`OAuthClientManager::computeSubject`), but a JWT is not encrypted, so any RP
  can base64url-decode the access token it is handed and read the stable raw
  internal userID. Two colluding RPs in different sectors read the **same** value.
- **Repro:** register two pairwise clients for one user, mint an access token for
  each, decode payload → both `sub == (string)$userID` (PoC asserts equality).
- **Expected vs actual:** expected each RP to see a per-sector opaque sub; actual
  both see the identical raw userID.
- **Fix sketch:** compute and set the pairwise (or public, per `subjectType`) sub
  as the access-token `sub` for RP-issued tokens (thread `computeSubject()` into
  `issueForClient`/`mintPair`), and resolve userinfo's userID via a mapping rather
  than casting `sub` back to an int.

### F-02 — Refresh grant does not re-check scope vs current allowedScopes · **Low–Med** · Confirmed
- **Vector:** 7 (scope escalation/persistence on refresh).
- **What:** `TokenService::refresh()` carries the stored token-row scope forward
  verbatim (`TokenService.php:372`); neither it nor
  `OAuthTokenService::exchangeRefreshToken()` re-validates it against the client's
  **current** `allowedScopes`. After an admin tightens a client (e.g. to revoke
  its access to `email`), outstanding refresh tokens keep minting access tokens
  carrying the removed scope until they expire (default 30 days).
- **Repro:** issue with `openid email offline_access`; `UPDATE tblOAuthClients SET
  allowedScopes='openid offline_access'`; refresh → new access token still has
  `email`.
- **Fix sketch:** intersect the rotated scope with the client's current
  `allowedScopes` before minting; drop (or reject) newly-disallowed scopes.

### F-03 — Code consumed before client-binding check (cross-client burn DoS) · **Low** · Confirmed
- **Vector:** 4 (auth-code; code consumed by a different client).
- **What:** `exchangeAuthorizationCode()` runs the atomic single-use consume
  (`SET consumedAt=NOW() WHERE codeHash=? AND consumedAt IS NULL`,
  `OAuthTokenService.php:265`) **before** the `row.clientID === client.clientID`
  check (`:306`). A different but validly-authenticated client that observes the
  code stamps `consumedAt`, then fails the binding check — so the legitimate
  client can no longer redeem its own code. (No token theft — the foreign client
  cannot mint tokens; impact is a single-attempt DoS. Requires the attacker to be
  a registered client AND to observe the front-channel code.)
- **Fix sketch:** verify client binding (and redirect_uri/expiry) on a
  non-mutating SELECT first, then perform the atomic consume — or scope the
  consume `UPDATE` with `AND clientID = ?`.

### F-04 — Per-IP rate limit bypass via forwarded-header spoofing · **Medium** · Confirmed (static)
- **Vector:** 11 (rate-limit bypass; XFF/getClientIP spoofing).
- **What:** `getClientIP()` (`config.php:531-548`) returns the first syntactically
  valid public IP from `CF-Connecting-IP` → `X-Forwarded-For` → `X-Real-IP` →
  `Client-IP` → `REMOTE_ADDR`, with no trusted-proxy allowlist. On direct-Apache
  hosting (e.g. Dreamhost shared, the stated target) these headers are fully
  attacker-controlled, so B-059's per-IP token/revoke counters are defeated by
  rotating a spoofed public IP per request. Systemic: also skews activity-log IP
  attribution, IP-reputation and impossible-travel signals. (Per-client counter
  still bounds a single presented `client_id`; and the limiter fails **open** on
  any DB error.)
- **Fix sketch:** only honour forwarded headers from a configured trusted-proxy
  list; otherwise use `REMOTE_ADDR`.

### F-05 — `oidc.enabled` master switch never enforced · **Medium** · Confirmed (static)
- **Vector:** 12/misc (config-intent / fail-open posture).
- **What:** migration 031 seeds `oidc.enabled='0'` described as the "Master switch
  for SIGNula-as-IdP (default OFF until an operator opts in)", but no endpoint or
  service reads it (grep: the seed is the only occurrence). The full provider
  (authorize-idp/token/userinfo/revoke + discovery + JWKS) is live regardless, so
  an operator who believes the IdP is off-by-default is mistaken. (To mint tokens
  an attacker still needs a registered client; discovery/JWKS are exposed
  unconditionally.)
- **Fix sketch:** gate the OAuth/OIDC controllers (and optionally discovery) on
  `getSetting('oidc.enabled')`, returning 404/`service_unavailable` when off.

## Refuted / defended vectors (coverage map)

- **1 · alg-confusion / none / kid** — REFUTED. `Jwt::verify()` pins
  `new FirebaseKey($publicPem, 'RS256')`; firebase/php-jwt 6.11.1 constant-time
  alg-match rejects RS256→HS256 (`web/_lib/jwt/src/JWT.php:143`) and has no `none`
  handler. Missing/unknown `kid` rejected (`Jwt.php:313-329`); kid path-traversal
  blocked by `KeyManager::isValidKid()` + `basename()` (`KeyManager.php:829`).
  PoC (defense holds): `testAlgConfusionHs256WithPublicKeyRejected`, `testAlgNoneRejected`.
- **2 · redirect_uri exact-match** — REFUTED. Strict `in_array(...,true)` byte-exact
  (`OAuthClientManager.php:461`), backed by `utf8mb4_bin`; fatal errors render a
  LOCAL page and never 302 (`authorize-idp.php:310-317`,
  `OAuthAuthorizeService.php:174-187`). PoC: `testRedirectUriExactMatchRejectsVariants`
  (trailing slash, query/fragment append, case-fold, suffix, userinfo-in-authority,
  percent-encode — all rejected).
- **3 · PKCE** — REFUTED. Missing challenge for a required client is rejected pre-issue
  (`OAuthAuthorizeService.php:252-260`); method must be explicit; `plain` gated by
  `oidc.allow_plain_pkce` at BOTH authorize and token time
  (`OAuthAuthorizeService.php:285`, `OAuthTokenService.php:352`); S256 verified with
  `hash_equals()` (`OAuthTokenService.php:344`).
- **4 · auth-code replay/binding** — REFUTED (except F-03 ordering). Atomic single-use
  consume; replay revokes exactly the issued family via `issuedFamilyID`
  (`OAuthTokenService.php:535-607`); cross-client redemption rejected
  (`:306`); redirect_uri byte-exact via `hash_equals` (`:320`); expiry enforced.
- **5 · cross-client refresh (B-058)** — REFUTED. `TokenService::refresh()` rejects
  `$authenticatedClientID !== $clientID` before any spend/reuse
  (`TokenService.php:306`); first-party `/api/v1/auth/refresh` passes `null`
  (`JwtAuthController.php:183`) so an RP-bound token is refused there too. PoC:
  `testCrossClientRefreshRedemptionRejected`.
- **6 · userinfo aud-confusion / over-disclosure** — REFUTED. peek-then-verify binds
  `aud` to the token's own signed claim; first-party tokens don't resolve to an RP
  client and are refused (`OAuthUserInfoService.php:130-139`); claims strictly
  scope-gated (`:213-256`); userinfo sub == id_token pairwise sub. (Note: raw
  userID still leaks via the access token itself — see F-01.)
- **7 · consent/scope at token time** — REFUTED for auth-code (scope is read from the
  code row, not the request). Refresh-time re-check gap = F-02.
- **8 · pairwise sub** — salt is 256-bit, encrypted at rest, minted at runtime
  (`OAuthClientManager.php:777`), not reversible/guessable — BUT correlation is
  achievable via F-01 (access-token sub). Same-sector clients sharing a host share a
  sub by OIDC design.
- **9 · mix-up / cut-and-paste** — REFUTED (provider scope): `state` echoed verbatim,
  `nonce` bound into the id_token; `state`/`nonce` validation is the RP's duty.
- **10 · jti/refresh reuse** — REFUTED. Reuse → whole-family revoke + alert
  (`TokenService.php:311-318`); jti denylist + `tokensInvalidBefore` cutoff wired in
  `verifyAccessToken()` (`:575-599`).
- **11 · rate-limit** — per-client bucket holds; per-IP bypass = F-04; limiter
  fails-open on DB error (`SecurityUtils.php:606-609`).
- **12 · discovery/JWKS** — REFUTED. JWKS strips `d/p/q/dp/dq/qi/oth/k` twice
  (`KeyManager.php:439`, `jwks.json/index.php:87`); issuer derived from
  `oidc.issuer` setting, NOT the Host header (`OidcDiscoveryService.php:78`) — not
  spoofable. Master-switch gap = F-05.
- **13 · misc** — REFUTED. All queries parameterised; secrets/codes/tokens compared
  via `hash_equals`; `Cache-Control: no-store` on token/userinfo/revoke; consent POST
  CSRF-checked (`authorize-idp.php:376`).

## PoC files created

- `_tests/Security/OAuthProviderRedTeamTest.php` — 7 tests, all passing (3 confirmed
  exploits F-01/F-02/F-03 + 4 defense-confirmations). Run:
  `DB_USER=root DB_NAME=signula_test php vendor/bin/phpunit _tests/Security/OAuthProviderRedTeamTest.php`
