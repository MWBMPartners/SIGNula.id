<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 🪪 SIGNula - OAuth2/OIDC Provider: Authorization Request Service (G-001 Stage A2)
 * ============================================================================
 *
 * Purpose:
 *   The pure/DB-bound business logic behind `/oauth/authorize-idp` (SIGNula
 *   acting as the OAuth2/OIDC PROVIDER, issuing an authorization code to a
 *   Relying-Party client). This class is the "engine"; the public_html page
 *   is a THIN controller that parses the HTTP request, calls into here, and
 *   renders HTML/redirects — kept that way specifically so the request
 *   validation and code-issuance logic can be exercised directly by Unit
 *   (pure functions) and Integration (DB-bound functions) tests, without ever
 *   spinning up a real HTTP request.
 *
 *   ⚠️ NOT to be confused with `web/public_html/oauth/authorize.php`, which is
 *   the OPPOSITE direction — SIGNula acting as a Relying Party CONSUMING
 *   Google/Microsoft/etc for email delegation or "sign in with X" account
 *   linking. That file is untouched by this stage.
 *
 * Split of responsibilities:
 *   • validateAuthorizeRequest() — PURE (no DB call). Given the raw request
 *     params and an already-hydrated client row (or null for "unknown"),
 *     applies every RFC 6749 / RFC 7636 / OIDC Core check for A2 and returns
 *     a single discriminated-union-style result describing exactly what the
 *     caller must do next: render a local (non-redirectable) error page,
 *     302-redirect an error back to the RP, or proceed.
 *   • hasCoveringConsent() / issueAuthorizationCode() — DB-bound. Read/write
 *     tblOAuthConsents and tblOAuthAuthCodes.
 *   • buildRedirectUrl() / scopeLabels() — small PURE helpers reused by both
 *     the success and error redirect paths, and by the consent screen's
 *     human-readable scope list.
 *
 * Security properties (see .dev-team/specs/G-001.md §5 — all mandatory):
 *   • The redirect_uri EXACT-MATCH check (via OAuthClientManager) must pass
 *     BEFORE any error is ever redirected anywhere — validateAuthorizeRequest()
 *     enforces this ordering itself: a null/inactive client or a failed
 *     redirect_uri match is always `fatal => true` (render a LOCAL error
 *     page, never a redirect); every check AFTER that point is
 *     `fatal => false` (safe to 302 back to the now-proven redirect_uri).
 *   • PKCE (RFC 7636) is mandatory for public clients / pkceRequired clients /
 *     the `oidc.require_pkce` global policy (default ON), and even when NOT
 *     mandatory, a VOLUNTEERED code_challenge is still fully validated
 *     (method + charset) — never silently accepted as-is.
 *   • The `plain` PKCE method requires an EXPLICIT `code_challenge_method`
 *     (RFC 7636's legacy "default to plain when omitted" behaviour is
 *     deliberately NOT honoured here — that implicit-downgrade path is a
 *     known PKCE weakness class) and is only ever accepted when
 *     `oidc.allow_plain_pkce` is explicitly ON.
 *   • The issued code is stored ONLY as its SHA-256 hash (tblOAuthAuthCodes.
 *     codeHash) — the raw code is returned to the caller once and never
 *     persisted, mirroring tblAPIKeys.keyHash / tblOAuthClients.clientSecretHash.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4).
 *
 * @package    SIGNula
 * @subpackage Auth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A2)
 * @link       https://datatracker.ietf.org/doc/html/rfc6749#section-4.1 (Authorization Code Grant)
 * @link       https://datatracker.ietf.org/doc/html/rfc7636 (PKCE)
 * @link       https://openid.net/specs/openid-connect-core-1_0.html#CodeFlowAuth (OIDC Authorization Code Flow)
 *
 * @see web/private_html/auth/OAuthClientManager.php (Stage A1 — client registration + redirect-URI/scope checks this class calls into)
 * @see web/public_html/oauth/authorize-idp.php (the thin controller that consumes this class)
 * @see _database/migrations/031_oauth_provider_clients.sql (tblOAuthAuthCodes / tblOAuthConsents)
 * @see .dev-team/specs/G-001.md §4, §5
 * @see .dev-team/specs/G-001-integration-plan.md §2 "A2"
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🪪 OAuth2/OIDC authorization-request validation + code-issuance engine.
 *
 * All methods are static (matches the OAuthClientManager / SecurityUtils /
 * KeyManager house style).
 */
class OAuthAuthorizeService
{
    /** @var int Random bytes for the raw authorization code — 32 bytes = 256-bit, mirrors client_secret entropy. */
    private const CODE_BYTES = 32;

    /**
     * @var string PKCE code_challenge format — base64url alphabet, 43-128
     *             characters (RFC 7636 §4.2 "the un-padded, base64url-encoded
     *             SHA256 hash [...] 43 octets minimum, 128 octets maximum").
     */
    private const PKCE_CHALLENGE_REGEX = '/^[A-Za-z0-9_\-]{43,128}$/';

    /** @var string[] The only two code_challenge_method values this provider recognises. */
    private const VALID_PKCE_METHODS = ['S256', 'plain'];

    /** @var int Max length of tblOAuthAuthCodes.nonce (VARCHAR(255)). */
    private const MAX_NONCE_LENGTH = 255;

    /**
     * 🏷️ Human-readable scope descriptions shown on the consent screen.
     * Falls back to the raw scope token for anything not listed here (e.g. a
     * future/partner-defined scope) — see {@see self::scopeLabels()}.
     *
     * @var array<string,string>
     */
    private const SCOPE_LABELS = [
        'openid'         => 'Confirm it\'s really you (required to sign in)',
        'profile'        => 'Your name and basic profile information',
        'email'          => 'Your email address',
        'offline_access' => 'Continued access when you\'re not actively using the app',
    ];

    // ========================================================================
    // 🛡️ VALIDATION — pure, no DB access
    // ========================================================================

    /**
     * 🛡️ Validate an `/oauth/authorize-idp` request end to end (RFC 6749
     * §4.1.1/§4.1.2.1, RFC 7636, OIDC Core §3.1.2.1).
     *
     * PURE — takes an already-hydrated client row (as returned by
     * {@see OAuthClientManager::getClient()}), or null if the client_id did
     * not resolve to an active client. Never touches the database itself, so
     * it is fully Unit-testable.
     *
     * The returned array's `fatal` flag is the caller's contract:
     *   - `fatal === true`  → render a LOCAL error page. `redirectUri` is
     *     always null here — we do NOT yet have a proven-safe redirect
     *     target (unknown/inactive client, or a redirect_uri that failed the
     *     exact-match allowlist check), so redirecting anywhere would risk an
     *     open-redirect / covert-redirect vector.
     *   - `fatal === false` (and `ok === false`) → 302 the RP back to
     *     `redirectUri` with `error`/`error_description`/`state` — safe,
     *     because redirect_uri has ALREADY been proven to be on the client's
     *     allowlist by the time any check past that point can fail.
     *   - `ok === true` → every field needed to proceed (STEP B/C in the
     *     controller) is populated.
     *
     * @param array<string,mixed> $params Raw request parameters:
     *   client_id, redirect_uri, response_type, scope, state, code_challenge,
     *   code_challenge_method, nonce (all expected as strings or null —
     *   the controller is responsible for rejecting non-scalar input before
     *   calling in, see authorizeIdpParam() in authorize-idp.php).
     * @param array|null $client Hydrated client row from
     *                           {@see OAuthClientManager::getClient()}, or
     *                           null if client_id did not resolve.
     * @return array{
     *     ok: bool,
     *     fatal: bool,
     *     error: ?string,
     *     errorDescription: ?string,
     *     redirectUri: ?string,
     *     state: ?string,
     *     scope: ?string,
     *     codeChallenge: ?string,
     *     codeChallengeMethod: ?string,
     *     nonce: ?string
     * }
     */
    public static function validateAuthorizeRequest(array $params, ?array $client): array
    {
        // ------------------------------------------------------------------
        // 1️⃣ Client existence + active status — FATAL. No redirect target is
        //    trustworthy yet, so we must NEVER redirect an error here.
        // ------------------------------------------------------------------
        if ($client === null || empty($client['isActive'])) {
            return self::fatalError('invalid_client', 'Unknown or inactive client_id.');
        }

        // ------------------------------------------------------------------
        // 2️⃣ redirect_uri EXACT-MATCH (the #1 OAuth IdP security control) —
        //    FATAL if missing or not byte-identical to a registered URI.
        // ------------------------------------------------------------------
        $redirectUri = trim((string) ($params['redirect_uri'] ?? ''));
        $registeredUris = is_array($client['redirectUris'] ?? null) ? $client['redirectUris'] : [];

        if ($redirectUri === '' || !OAuthClientManager::isExactRedirectMatch($redirectUri, $registeredUris)) {
            return self::fatalError('invalid_request', 'Missing or unregistered redirect_uri.');
        }

        // 🎯 From here on, $redirectUri is a PROVEN member of this client's
        //    allowlist — every subsequent failure can safely 302 back to it.
        $state = self::nonEmptyStringOrNull($params['state'] ?? null);

        // ------------------------------------------------------------------
        // 3️⃣ response_type (RFC 6749 §4.1.1) — only the authorization code
        //    flow is supported by this provider.
        // ------------------------------------------------------------------
        $responseType = (string) ($params['response_type'] ?? '');
        if ($responseType !== 'code') {
            return self::redirectableError(
                $redirectUri,
                $state,
                'unsupported_response_type',
                'Only response_type=code is supported.'
            );
        }

        // ------------------------------------------------------------------
        // 4️⃣ scope — non-empty, ⊆ client.allowedScopes, and MUST include
        //    "openid" (OIDC Core §3.1.2.1: the Authorization Code Flow
        //    requires the scope parameter to contain "openid"). We report the
        //    missing-openid case as `invalid_scope` (not `invalid_request`):
        //    RFC 6749 §4.1.2.1 defines invalid_scope as "the requested scope
        //    is invalid, unknown, or malformed" — since this provider is
        //    OIDC-only (every registered client is a "Sign in with
        //    SIGNula.id" RP; see OAuthClientManager's header), a scope
        //    request lacking "openid" is a malformed scope request for THIS
        //    endpoint, not a structurally-invalid request.
        // ------------------------------------------------------------------
        $scope = trim((string) ($params['scope'] ?? ''));
        if ($scope === '') {
            return self::redirectableError($redirectUri, $state, 'invalid_scope', 'The scope parameter is required.');
        }

        if (!OAuthClientManager::scopesAllowed($scope, (string) ($client['allowedScopes'] ?? ''))) {
            return self::redirectableError(
                $redirectUri,
                $state,
                'invalid_scope',
                'One or more requested scopes are not permitted for this client.'
            );
        }

        $scopeTokens = preg_split('/\s+/', $scope, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!in_array('openid', $scopeTokens, true)) {
            return self::redirectableError($redirectUri, $state, 'invalid_scope', 'The openid scope is required.');
        }

        // ------------------------------------------------------------------
        // 5️⃣ PKCE (RFC 7636) — mandatory for public clients, clients with
        //    pkceRequired=1, or when the global oidc.require_pkce policy is
        //    on (default true). Even when NOT strictly mandatory, a
        //    VOLUNTEERED code_challenge is still fully validated — never
        //    silently accepted just because it wasn't required.
        // ------------------------------------------------------------------
        $pkceRequired = (($client['clientType'] ?? '') === 'public')
            || (bool) ($client['pkceRequired'] ?? false)
            || (bool) getSetting('oidc.require_pkce', true);

        $codeChallenge = trim((string) ($params['code_challenge'] ?? ''));
        $codeChallengeMethod = trim((string) ($params['code_challenge_method'] ?? ''));

        if ($codeChallenge === '') {
            if ($pkceRequired) {
                return self::redirectableError(
                    $redirectUri,
                    $state,
                    'invalid_request',
                    'PKCE (code_challenge) is required for this client.'
                );
            }
            // PKCE genuinely optional for this client AND not supplied.
            $codeChallengeMethod = null;
        } else {
            // 🔐 A code_challenge was supplied — the method must be EXPLICIT.
            //    RFC 7636 §4.3 lets a client omit code_challenge_method and
            //    have the server assume "plain"; we deliberately do NOT honour
            //    that implicit-downgrade fallback (a known PKCE weakness
            //    class) — an omitted method is a hard error here.
            if ($codeChallengeMethod === '') {
                return self::redirectableError(
                    $redirectUri,
                    $state,
                    'invalid_request',
                    'code_challenge_method is required when code_challenge is present.'
                );
            }
            if (!in_array($codeChallengeMethod, self::VALID_PKCE_METHODS, true)) {
                return self::redirectableError(
                    $redirectUri,
                    $state,
                    'invalid_request',
                    'Unsupported code_challenge_method.'
                );
            }
            if ($codeChallengeMethod === 'plain' && !(bool) getSetting('oidc.allow_plain_pkce', false)) {
                return self::redirectableError(
                    $redirectUri,
                    $state,
                    'invalid_request',
                    'The plain PKCE method is not permitted.'
                );
            }
            if (preg_match(self::PKCE_CHALLENGE_REGEX, $codeChallenge) !== 1) {
                return self::redirectableError(
                    $redirectUri,
                    $state,
                    'invalid_request',
                    'code_challenge is malformed (expected 43-128 base64url characters).'
                );
            }
        }

        // ------------------------------------------------------------------
        // 6️⃣ nonce — optional, opaque to us, echoed verbatim into the future
        //    id_token (Stage A3). Only a length sanity-check here
        //    (tblOAuthAuthCodes.nonce is VARCHAR(255)).
        // ------------------------------------------------------------------
        $nonce = self::nonEmptyStringOrNull($params['nonce'] ?? null);
        if ($nonce !== null && strlen($nonce) > self::MAX_NONCE_LENGTH) {
            return self::redirectableError($redirectUri, $state, 'invalid_request', 'nonce exceeds the maximum length.');
        }

        // ✅ Every check passed.
        return [
            'ok'                  => true,
            'fatal'               => false,
            'error'               => null,
            'errorDescription'    => null,
            'redirectUri'         => $redirectUri,
            'state'               => $state,
            'scope'               => $scope,
            'codeChallenge'       => $codeChallenge !== '' ? $codeChallenge : null,
            'codeChallengeMethod' => $codeChallengeMethod,
            'nonce'               => $nonce,
        ];
    }

    // ========================================================================
    // 🤝 CONSENT — DB-bound
    // ========================================================================

    /**
     * 🤝 Does the user already have a live (non-revoked) consent for this
     * client that COVERS every requested scope?
     *
     * Used by the controller to auto-skip the consent screen on a repeat
     * sign-in (unless the RP forces `prompt=consent`).
     *
     * @param int    $userID         Authenticated user.
     * @param int    $clientID       Internal clientID (PK).
     * @param string $requestedScope Space-delimited requested scopes.
     * @return bool True if a non-revoked consent row exists whose stored
     *              scope is a superset of `$requestedScope`.
     */
    public static function hasCoveringConsent(int $userID, int $clientID, string $requestedScope): bool
    {
        $row = Database::fetchOne(
            "SELECT scope FROM tblOAuthConsents WHERE userID = ? AND clientID = ? AND revokedAt IS NULL",
            [$userID, $clientID],
            'ii'
        );

        if ($row === null) {
            return false;
        }

        // 🔒 Reuse the SAME subset-check OAuthClientManager uses for
        //    requested⊆allowed — here it's requested⊆previously-granted.
        return OAuthClientManager::scopesAllowed($requestedScope, (string) $row['scope']);
    }

    // ========================================================================
    // 🎫 CODE ISSUANCE — DB-bound
    // ========================================================================

    /**
     * 🎫 Mint a single-use authorization code + (re)record consent, inside
     * one call. Called on EITHER: (a) the user submitting "Allow" on the
     * consent screen, or (b) an auto-skip because a covering consent already
     * existed — both paths execute the SAME logic here (issuing a code and
     * refreshing consent is idempotent and always safe).
     *
     * @param array  $client    Hydrated client row (needs `clientID`).
     * @param int    $userID    Authenticated user the code is issued to.
     * @param array  $validated The `ok === true` result of
     *                          {@see self::validateAuthorizeRequest()}
     *                          (reads redirectUri/scope/codeChallenge/
     *                          codeChallengeMethod/nonce).
     * @param string $ipAddress Requesting IP (IPv4 or IPv6) — audit trail.
     * @return array{code:string,codeID:int,codeHash:string,expiresInSeconds:int}
     *         `code` is the RAW, single-use, plaintext code — return it to
     *         the RP ONCE via the redirect; only its SHA-256 hash is stored.
     */
    public static function issueAuthorizationCode(array $client, int $userID, array $validated, string $ipAddress): array
    {
        $rawCode = self::generateRawCode();
        $codeHash = hash('sha256', $rawCode);
        $ttlSeconds = (int) getSetting('oidc.authcode_ttl', 60);
        $clientID = (int) $client['clientID'];

        // 💾 codeHash is the ONLY form of the code ever persisted — mirrors
        //    tblAPIKeys.keyHash / tblOAuthClients.clientSecretHash. authTime
        //    is stamped NOW() (the moment of consent/auto-issue) — it becomes
        //    the future id_token's `auth_time` claim (Stage A3); a more
        //    precise "moment the user actually entered credentials" value
        //    isn't tracked yet, so NOW() is the closest available proxy.
        Database::query(
            "INSERT INTO tblOAuthAuthCodes
                (codeHash, clientID, userID, redirectURI, scope, codeChallenge, codeChallengeMethod, nonce, authTime, expiresAt, ipAddress)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND), ?)",
            [
                $codeHash,
                $clientID,
                $userID,
                $validated['redirectUri'],
                $validated['scope'],
                $validated['codeChallenge'],
                $validated['codeChallengeMethod'],
                $validated['nonce'],
                $ttlSeconds,
                $ipAddress,
            ],
            'siisssssis'
        );
        $codeID = Database::getLastInsertId();

        // 🤝 UPSERT consent — REPLACES the stored scope with the scope just
        //    granted (rather than merging with any earlier grant). The
        //    consent screen always shows the FULL current requested-scope
        //    set, so the recorded consent should reflect exactly what the
        //    user approved just now, not a stale union with a prior,
        //    possibly narrower or unrelated, grant.
        Database::query(
            "INSERT INTO tblOAuthConsents (userID, clientID, scope, grantedAt, revokedAt, ipAddress)
             VALUES (?, ?, ?, NOW(), NULL, ?)
             ON DUPLICATE KEY UPDATE scope = VALUES(scope), grantedAt = NOW(), revokedAt = NULL, ipAddress = VALUES(ipAddress)",
            [$userID, $clientID, $validated['scope'], $ipAddress],
            'iiss'
        );

        return [
            'code'             => $rawCode,
            'codeID'           => $codeID,
            'codeHash'         => $codeHash,
            'expiresInSeconds' => $ttlSeconds,
        ];
    }

    // ========================================================================
    // 🔧 SMALL PURE HELPERS (reused by the controller)
    // ========================================================================

    /**
     * 🔗 Append query parameters onto a redirect_uri, correctly handling a
     * redirect_uri that already carries its own query string (RFC 6749
     * §3.1.2 permits this). Drops null/empty entries (e.g. an absent
     * `state`) so they never appear as a bare `key=` in the final URL.
     *
     * @param string $redirectUri Base redirect_uri (already validated).
     * @param array<string,mixed> $queryParams Params to append.
     * @return string
     */
    public static function buildRedirectUrl(string $redirectUri, array $queryParams): string
    {
        $queryParams = array_filter(
            $queryParams,
            static fn(mixed $value): bool => $value !== null && $value !== ''
        );

        if (empty($queryParams)) {
            return $redirectUri;
        }

        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return $redirectUri . $separator . http_build_query($queryParams);
    }

    /**
     * 🏷️ Turn a space-delimited scope string into a list of
     * `['scope' => ..., 'label' => ...]` pairs for the consent screen.
     * Unknown scopes fall back to their raw token as the label.
     *
     * @param string $scopeString Space-delimited scopes.
     * @return array<int,array{scope:string,label:string}>
     */
    public static function scopeLabels(string $scopeString): array
    {
        $tokens = preg_split('/\s+/', trim($scopeString), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $out = [];
        foreach ($tokens as $token) {
            $out[] = [
                'scope' => $token,
                'label' => self::SCOPE_LABELS[$token] ?? $token,
            ];
        }

        return $out;
    }

    // ========================================================================
    // 🔧 PRIVATE HELPERS
    // ========================================================================

    /**
     * 🎲 Generate a high-entropy raw authorization code. Pure (DB-free) — no
     * uniqueness check is needed (256 bits of entropy makes a collision
     * cryptographically negligible, and the DB column is UNIQUE on the hash
     * regardless, so a collision would simply fail the INSERT rather than
     * silently overwrite another code).
     *
     * @return string 64-char lowercase hex (32 random bytes / 256 bits).
     */
    private static function generateRawCode(): string
    {
        return bin2hex(random_bytes(self::CODE_BYTES));
    }

    /**
     * 🧹 Coerce a raw request value to a trimmed non-empty string, or null.
     * Guards against non-scalar input (e.g. a crafted `state[]=x` array
     * parameter) being silently stringified.
     *
     * @param mixed $value Raw value.
     * @return string|null
     */
    private static function nonEmptyStringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * ❌ Build a FATAL result — the caller must render a local error page and
     * must NEVER redirect anywhere (no proven-safe redirect target exists).
     *
     * @param string $error       OAuth error code.
     * @param string $description Human-readable description.
     * @return array Shape matches {@see self::validateAuthorizeRequest()}'s return type.
     */
    private static function fatalError(string $error, string $description): array
    {
        return [
            'ok'                  => false,
            'fatal'               => true,
            'error'               => $error,
            'errorDescription'    => $description,
            'redirectUri'         => null,
            'state'               => null,
            'scope'               => null,
            'codeChallenge'       => null,
            'codeChallengeMethod' => null,
            'nonce'               => null,
        ];
    }

    /**
     * ❌ Build a REDIRECTABLE result — the caller may safely 302 the RP back
     * to `$redirectUri` with the error + echoed state, because redirect_uri
     * has ALREADY been proven to be on the client's allowlist by this point.
     *
     * @param string      $redirectUri Proven, validated redirect_uri.
     * @param string|null $state       Echoed state (may be absent).
     * @param string      $error       OAuth error code.
     * @param string      $description Human-readable description.
     * @return array Shape matches {@see self::validateAuthorizeRequest()}'s return type.
     */
    private static function redirectableError(string $redirectUri, ?string $state, string $error, string $description): array
    {
        return [
            'ok'                  => false,
            'fatal'               => false,
            'error'               => $error,
            'errorDescription'    => $description,
            'redirectUri'         => $redirectUri,
            'state'               => $state,
            'scope'               => null,
            'codeChallenge'       => null,
            'codeChallengeMethod' => null,
            'nonce'               => null,
        ];
    }
}

// ✅ OAuthAuthorizeService loaded successfully
