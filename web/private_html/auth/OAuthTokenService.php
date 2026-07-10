<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 🪪 SIGNula - OAuth2/OIDC Provider: Token Endpoint Service (G-001 Stage A3 + A5)
 * ============================================================================
 *
 * Purpose:
 *   The pure/DB-bound business logic behind `/oauth/token` (SIGNula acting as
 *   the OAuth2/OIDC PROVIDER): the `authorization_code` grant (Stage A3,
 *   exchanging an A2 authorization code for an access token + OIDC id_token,
 *   optionally + a refresh token) AND the `refresh_token` grant (Stage A5 —
 *   wired once TokenService::refresh() gained its client-binding check,
 *   B-058). This class is the "engine"; the public_html page
 *   (`web/public_html/oauth/token.php`) is a THIN controller that parses the
 *   HTTP request, calls into here, and emits the RFC 6749 JSON response —
 *   kept that way specifically so the client-authentication and
 *   code-redemption logic can be exercised directly by Integration tests
 *   without ever spinning up a real HTTP request. This mirrors the
 *   OAuthAuthorizeService/authorize-idp.php split from Stage A2.
 *
 * Split of responsibilities:
 *   • handleTokenRequest() — the single entry point the controller calls.
 *     Authenticates the client, validates grant_type, and dispatches to the
 *     grant-specific handler. Every result (success or error) is returned as
 *     `['status' => int, 'body' => array, 'headers' => array]` so the
 *     controller only ever has to set the status code, merge any extra
 *     headers, and echo the JSON body — no OAuth error-shape decisions live
 *     in the controller.
 *   • authenticateClient() — DB-bound (OAuthClientManager). RFC 6749 §2.3/§3.2.1
 *     client authentication via HTTP Basic OR request-body credentials.
 *   • exchangeAuthorizationCode() — DB-bound. The authorization_code grant
 *     (RFC 6749 §4.1.3): atomic single-use code consumption, replay-revoke,
 *     client/redirect_uri/expiry binding checks, PKCE verification, and
 *     token + id_token minting.
 *   • exchangeRefreshToken() — DB-bound (via TokenService::refresh()). The
 *     refresh_token grant (RFC 6749 §6): rotates a refresh token BOUND to the
 *     authenticated client — TokenService rejects (before ever
 *     rotating/spending) a token that belongs to a different client, or to
 *     no client at all (a first-party token presented here).
 *
 * Security properties (see .dev-team/specs/G-001.md §5 — all mandatory):
 *   • The code is consumed via a SINGLE atomic, guarded UPDATE
 *     (`SET consumedAt = NOW() WHERE codeHash = ? AND consumedAt IS NULL`) —
 *     `Database::getAffectedRows() === 1` is the ONLY way to proceed. Two
 *     concurrent redemption attempts of the same code race on this UPDATE;
 *     exactly one wins.
 *   • REPLAY of an already-consumed code (RFC 6749 §10.5) is detected and
 *     revokes the EXACT token family that code's first legitimate exchange
 *     minted (via `tblOAuthAuthCodes.issuedFamilyID` — migration 032), never
 *     the client's/user's other, unrelated grants.
 *   • PKCE S256 verification uses `hash_equals()` (constant-time).
 *   • `redirect_uri` must be BYTE-EXACT identical to the code's stored value
 *     (no normalisation) — checked with `hash_equals()`.
 *   • The code's `clientID` must equal the AUTHENTICATED client's clientID — a
 *     code issued to client A can never be redeemed by client B.
 *   • `sub` in the id_token is the OIDC PAIRWISE subject
 *     (OAuthClientManager::computeSubject()) — never the raw userID for a
 *     pairwise-subject client.
 *   • The id_token carries NO `scope` claim (see Jwt::signIdToken()) and only
 *     the profile/email claims the GRANTED scope actually covers.
 *   • Confidential-client secrets are compared via
 *     OAuthClientManager::verifyClientSecret() (constant-time `hash_equals()`
 *     internally); a public client can never "authenticate" with a secret.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4).
 *
 * @package    SIGNula
 * @subpackage Auth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A3)
 * @link       https://datatracker.ietf.org/doc/html/rfc6749#section-4.1.3 (Access Token Request)
 * @link       https://datatracker.ietf.org/doc/html/rfc6749#section-10.5 (Authorization Codes / replay)
 * @link       https://datatracker.ietf.org/doc/html/rfc7636 (PKCE)
 * @link       https://openid.net/specs/openid-connect-core-1_0.html#TokenEndpoint (OIDC Token Endpoint)
 *
 * @see web/private_html/auth/OAuthClientManager.php     (Stage A1 — client model)
 * @see web/private_html/auth/OAuthAuthorizeService.php  (Stage A2 — issues the code this stage consumes)
 * @see web/private_html/security/TokenService.php       (G-003 — issueForClient()/mintPair())
 * @see web/private_html/security/Jwt.php                (G-003 — signIdToken())
 * @see web/public_html/oauth/token.php                  (the thin controller that consumes this class)
 * @see _database/migrations/031_oauth_provider_clients.sql (tblOAuthAuthCodes)
 * @see _database/migrations/032_oauth_token_endpoint.sql   (tblOAuthAuthCodes.issuedFamilyID)
 * @see .dev-team/specs/G-001.md §4, §5
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
 * 🪪 OAuth2/OIDC token-endpoint engine (`/oauth/token`).
 *
 * All methods are static (matches the OAuthClientManager / OAuthAuthorizeService
 * house style). Every public method returns the SAME result shape:
 *   `['status' => int, 'body' => array<string,mixed>, 'headers' => array<string,string>]`
 * so the controller never has to construct an OAuth error body itself.
 */
class OAuthTokenService
{
    /** @var string[] The only code_challenge_method values this provider recognises (mirrors OAuthAuthorizeService). */
    private const VALID_PKCE_METHODS = ['S256', 'plain'];

    // ========================================================================
    // 🚦 ENTRY POINT — dispatches by grant_type
    // ========================================================================

    /**
     * 🚦 Handle a `/oauth/token` request end to end.
     *
     * Order of operations (deliberate — see class doc "Security properties"):
     *   1. Client authentication (RFC 6749 §4.1.3 "the client MUST authenticate
     *      ... if possible") — BEFORE any grant-specific logic, so an
     *      unauthenticated/unknown client learns nothing about a code's
     *      existence or validity.
     *   2. `grant_type` presence + support check.
     *   3. Dispatch to the grant-specific handler.
     *
     * @param array<string,mixed> $params     Raw grant parameters (grant_type,
     *                                          code, redirect_uri, code_verifier, …
     *                                          — all expected as strings; the
     *                                          controller is responsible for
     *                                          rejecting non-scalar input, see
     *                                          tokenParam() in token.php).
     * @param array<string,mixed> $clientAuth  Parsed client credentials:
     *                                          `method` ('basic'|'body'),
     *                                          `client_id`, `client_secret`
     *                                          (nullable) — see
     *                                          {@see self::authenticateClient()}.
     * @return array{status:int, body:array<string,mixed>, headers:array<string,string>}
     */
    public static function handleTokenRequest(array $params, array $clientAuth): array
    {
        // 1️⃣ Client authentication FIRST.
        $auth = self::authenticateClient($clientAuth);
        if (($auth['ok'] ?? false) !== true) {
            return self::asResult($auth);
        }
        $client = $auth['client'];

        // 2️⃣ grant_type — required. authorization_code (Stage A3) and
        //    refresh_token (Stage A5 — G-001/B-058 follow-up, now that
        //    TokenService::refresh() enforces client-binding) are wired.
        $grantType = self::stringParam($params, 'grant_type');
        if ($grantType === '') {
            return self::errorResult(400, 'invalid_request', 'grant_type is required.');
        }

        if ($grantType === 'authorization_code') {
            // 3️⃣ code — required for the authorization_code grant.
            $code = self::stringParam($params, 'code');
            if ($code === '') {
                return self::errorResult(400, 'invalid_request', 'code is required.');
            }

            return self::exchangeAuthorizationCode($client, $params);
        }

        if ($grantType === 'refresh_token') {
            // 3️⃣ refresh_token — required for the refresh_token grant.
            $refreshToken = self::stringParam($params, 'refresh_token');
            if ($refreshToken === '') {
                return self::errorResult(400, 'invalid_request', 'refresh_token is required.');
            }

            return self::exchangeRefreshToken($client, $refreshToken);
        }

        // ❌ Any other grant_type is unsupported (RFC 6749 §5.2).
        return self::errorResult(400, 'unsupported_grant_type', "Unsupported grant_type '{$grantType}'.");
    }

    // ========================================================================
    // 🔐 CLIENT AUTHENTICATION (RFC 6749 §2.3 / §3.2.1)
    // ========================================================================

    /**
     * 🔐 Authenticate the calling client (HTTP Basic OR body credentials).
     *
     * @param array<string,mixed> $clientAuth `method` ('basic'|'body'),
     *                                          `client_id`, `client_secret'.
     * @return array{ok:bool, client?:array, status?:int, body?:array, headers?:array}
     *         `ok === true` → `client` is the hydrated, active client row.
     *         `ok === false` → the OTHER keys are already a full result shape
     *         (status/body/headers) the caller can return as-is.
     */
    public static function authenticateClient(array $clientAuth): array
    {
        $viaBasic = (string) ($clientAuth['method'] ?? 'body') === 'basic';

        $clientId = isset($clientAuth['client_id']) ? trim((string) $clientAuth['client_id']) : '';
        $clientSecret = isset($clientAuth['client_secret']) && $clientAuth['client_secret'] !== null
            ? (string) $clientAuth['client_secret']
            : null;

        if ($clientId === '') {
            return self::clientAuthFailure($viaBasic, 'client_id is required.');
        }

        if (!class_exists('OAuthClientManager')) {
            // Should never happen in production (autoloaded); fail closed.
            return self::clientAuthFailure($viaBasic, 'Client registry unavailable.');
        }

        $client = OAuthClientManager::getClient($clientId);
        if ($client === null || empty($client['isActive'])) {
            return self::clientAuthFailure($viaBasic, 'Unknown or inactive client.');
        }

        if ((string) ($client['clientType'] ?? '') === 'confidential') {
            // 🔑 Confidential clients MUST present a valid secret on every
            //    request. verifyClientSecret() itself uses hash_equals() and
            //    refuses to "verify" anything for a non-confidential client
            //    (defence-in-depth mirrored here by the clientType check).
            if ($clientSecret === null || $clientSecret === ''
                || !OAuthClientManager::verifyClientSecret($clientId, $clientSecret)
            ) {
                return self::clientAuthFailure($viaBasic, 'Client authentication failed.');
            }
        }
        // 🛡️ Public clients present NO usable secret — PKCE is their proof of
        //    possession instead. We deliberately never call
        //    verifyClientSecret() for a public client here (it would always
        //    return false anyway — confidential/public confusion defence —
        //    but a public client legitimately sends no secret at all, and
        //    that must NOT be treated as an authentication failure).

        return ['ok' => true, 'client' => $client];
    }

    // ========================================================================
    // 🎫 AUTHORIZATION_CODE GRANT (RFC 6749 §4.1.3, RFC 7636, OIDC Core §3.1.3)
    // ========================================================================

    /**
     * 🎫 Redeem a single-use authorization code for an access + id_token (+
     * refresh token) pair, for the ALREADY-AUTHENTICATED `$client`.
     *
     * @param array<string,mixed> $client Hydrated, authenticated client row
     *                                     (from {@see self::authenticateClient()}).
     * @param array<string,mixed> $params Raw grant parameters (code,
     *                                     redirect_uri, code_verifier).
     * @return array{status:int, body:array<string,mixed>, headers:array<string,string>}
     */
    public static function exchangeAuthorizationCode(array $client, array $params): array
    {
        $code         = self::stringParam($params, 'code');
        $redirectUri  = self::stringParam($params, 'redirect_uri');
        $codeVerifier = self::stringParam($params, 'code_verifier');

        $codeHash = hash('sha256', $code);

        // ------------------------------------------------------------------
        // 3️⃣ ATOMIC SINGLE-USE CONSUME (RFC 6749 §10.5). This is THE security
        //    control: whichever caller's UPDATE actually flips consumedAt from
        //    NULL wins; everyone else (including a genuine replay) sees 0
        //    affected rows and is denied below.
        // ------------------------------------------------------------------
        Database::query(
            "UPDATE tblOAuthAuthCodes SET consumedAt = NOW() WHERE codeHash = ? AND consumedAt IS NULL",
            [$codeHash],
            's'
        );

        if (Database::getAffectedRows() !== 1) {
            // Either the codeHash is entirely unknown, OR it was already
            // consumed by an earlier exchange (REPLAY). Either way the caller
            // gets the SAME generic invalid_grant — but a genuine replay
            // triggers a real security response (family revoke) internally.
            self::handlePossibleReplay($codeHash);

            return self::errorResult(400, 'invalid_grant', 'The authorization code is invalid or has already been used.');
        }

        // ------------------------------------------------------------------
        // 4️⃣ Re-SELECT the now-consumed row for its bound request context.
        // ------------------------------------------------------------------
        $row = Database::fetchOne(
            "SELECT codeID, clientID, userID, redirectURI, scope, codeChallenge,
                    codeChallengeMethod, nonce, authTime, expiresAt
             FROM tblOAuthAuthCodes
             WHERE codeHash = ?
             LIMIT 1",
            [$codeHash],
            's'
        );

        if ($row === null) {
            // Unreachable in practice (we just set consumedAt on this exact
            // row within the same request) — fail closed regardless.
            return self::errorResult(400, 'invalid_grant', 'The authorization code is invalid.');
        }

        // ------------------------------------------------------------------
        // 5️⃣ BINDINGS — client, expiry, redirect_uri.
        // ------------------------------------------------------------------

        // 🔗 A code issued to client A can never be redeemed by client B, even
        //    if B authenticates perfectly with its OWN credentials.
        if ((int) $row['clientID'] !== (int) $client['clientID']) {
            return self::errorResult(400, 'invalid_grant', 'The authorization code was not issued to this client.');
        }

        // ⏳ Expiry — compared as unix timestamps (the DB connection is pinned
        //    to UTC in database.php, matching PHP's UTC default here).
        $expiresAtTs = strtotime((string) $row['expiresAt'] . ' UTC');
        if ($expiresAtTs === false || $expiresAtTs <= time()) {
            return self::errorResult(400, 'invalid_grant', 'The authorization code has expired.');
        }

        // 🎯 redirect_uri must be BYTE-EXACT identical to the value recorded
        //    at /oauth/authorize-idp time — no normalisation of any kind.
        //    hash_equals() also gives a constant-time comparison.
        if ($redirectUri === '' || !hash_equals((string) $row['redirectURI'], $redirectUri)) {
            return self::errorResult(400, 'invalid_grant', 'redirect_uri does not match the authorization request.');
        }

        // ------------------------------------------------------------------
        // 6️⃣ PKCE (RFC 7636 §4.6) — required whenever the code carries a
        //    code_challenge (OAuthAuthorizeService already enforced it MUST
        //    for public/pkceRequired clients; a volunteered challenge is
        //    verified here regardless of whether it was mandatory).
        // ------------------------------------------------------------------
        $codeChallenge = $row['codeChallenge'] !== null ? (string) $row['codeChallenge'] : '';
        if ($codeChallenge !== '') {
            if ($codeVerifier === '') {
                return self::errorResult(400, 'invalid_grant', 'code_verifier is required.');
            }

            $method = (string) ($row['codeChallengeMethod'] ?? 'S256');
            if (!in_array($method, self::VALID_PKCE_METHODS, true)) {
                return self::errorResult(400, 'invalid_grant', 'Unsupported PKCE method recorded for this code.');
            }

            if ($method === 'S256') {
                // 🔐 BASE64URL(SHA256(code_verifier)) — RFC 7636 §4.2.
                $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
                if (!hash_equals($codeChallenge, $computed)) {
                    return self::errorResult(400, 'invalid_grant', 'code_verifier does not match code_challenge.');
                }
            } else {
                // 'plain' — only ever reachable if OAuthAuthorizeService
                // accepted it at /authorize-idp time (gated there by
                // oidc.allow_plain_pkce); re-check the policy here too so a
                // policy flip between authorize and token time fails closed.
                if (!(bool) getSetting('oidc.allow_plain_pkce', false) || !hash_equals($codeChallenge, $codeVerifier)) {
                    return self::errorResult(400, 'invalid_grant', 'code_verifier does not match code_challenge.');
                }
            }
        }

        // ------------------------------------------------------------------
        // 7️⃣ MINT — access + refresh (TokenService) + id_token (Jwt).
        // ------------------------------------------------------------------
        $userID           = (int) $row['userID'];
        $clientID         = (int) $client['clientID'];
        $clientIdentifier = (string) $client['clientIdentifier'];
        $scopeStr         = (string) ($row['scope'] ?? '');
        $scopeArray       = $scopeStr !== '' ? preg_split('/\s+/', trim($scopeStr), -1, PREG_SPLIT_NO_EMPTY) : [];
        $scopeArray       = $scopeArray !== false ? $scopeArray : [];

        // 🕵️ OIDC pairwise (or public) subject — never the raw userID for a
        //    pairwise-subject client (the default for new registrations).
        $sub = OAuthClientManager::computeSubject($userID, $client);

        $pair = TokenService::issueForClient($userID, $clientID, $clientIdentifier, $scopeArray);

        // 🔗 Record the newly-minted family on the (already-consumed) code row
        //    so a FUTURE replay of this exact code can find + revoke it.
        Database::query(
            "UPDATE tblOAuthAuthCodes SET issuedFamilyID = ? WHERE codeID = ?",
            [$pair['family_id'], (int) $row['codeID']],
            'si'
        );

        // 🪪 id_token claims: sub + auth_time (+ nonce, if the RP sent one),
        //    plus profile/email claims GATED strictly by the GRANTED scope —
        //    never more than what the user actually consented to.
        $authTimeTs = strtotime((string) $row['authTime'] . ' UTC');
        $idClaims = [
            'sub'       => $sub,
            'auth_time' => $authTimeTs !== false ? $authTimeTs : time(),
        ];
        if (!empty($row['nonce'])) {
            $idClaims['nonce'] = (string) $row['nonce'];
        }
        self::addProfileClaims($idClaims, $userID, $scopeArray);

        $idToken = Jwt::signIdToken($idClaims, $clientIdentifier);

        // 📝 Best-effort audit (no token material logged).
        if (class_exists('ActivityLogger')) {
            try {
                ActivityLogger::log(
                    $userID,
                    'oauth_token_issued',
                    'auth',
                    'info',
                    "OAuth access/id token issued to client \"{$client['clientName']}\"",
                    ['clientID' => $clientID, 'scope' => $scopeStr, 'familyID' => $pair['family_id']]
                );
            } catch (\Throwable $e) {
                error_log('OAuthTokenService: activity log failed: ' . $e->getMessage());
            }
        }

        $body = [
            'access_token' => $pair['access_token'],
            'token_type'   => 'Bearer',
            'expires_in'   => $pair['expires_in'],
            'scope'        => $pair['scope'],
            'id_token'     => $idToken,
        ];
        // 🔁 G-001 Stage A5: TokenService::issueForClient() now GATES the
        //    refresh-token mint behind the `offline_access` scope (see its own
        //    doc comment) — `$pair['refresh_token']` is null when it was not
        //    granted, so this conditional naturally omits the field from the
        //    response in that case (rather than the previous "always present"
        //    behaviour, back when TokenService had no such lever).
        if (!empty($pair['refresh_token'])) {
            $body['refresh_token'] = $pair['refresh_token'];
        }

        return ['status' => 200, 'body' => $body, 'headers' => []];
    }

    // ========================================================================
    // 🔄 REFRESH_TOKEN GRANT (RFC 6749 §6 — G-001 Stage A5 / B-058 follow-up)
    // ========================================================================

    /**
     * 🔄 Rotate a refresh token for the ALREADY-AUTHENTICATED `$client` (RFC
     * 6749 §6 "Refreshing an Access Token").
     *
     * Delegates the actual rotation to {@see TokenService::refresh()}, passing
     * the AUTHENTICATED client's internal clientID so TokenService's B-058
     * client-binding check can enforce that this token actually BELONGS to
     * this client — a refresh token minted for Client A can never be rotated
     * by Client B, even with B's own perfectly valid credentials (see
     * TokenService::refresh()'s own doc comment for the full rationale).
     *
     * @param array<string,mixed> $client       Hydrated, authenticated client row.
     * @param string              $refreshToken The opaque refresh-token plaintext presented by the caller.
     * @return array{status:int, body:array<string,mixed>, headers:array<string,string>}
     */
    public static function exchangeRefreshToken(array $client, string $refreshToken): array
    {
        $clientID = (int) $client['clientID'];

        try {
            // 🔒 B-058: TokenService rejects (BEFORE rotating/spending) any
            //    token whose owning clientID does not equal $clientID —
            //    including a first-party token (owning clientID NULL) or a
            //    token minted for a DIFFERENT client. Reuse (a spent/revoked
            //    token replayed) is still detected and still revokes the
            //    family exactly as the authorization_code grant's replay
            //    handling does.
            $pair = \TokenService::refresh($refreshToken, $clientID);
        } catch (\JwtException $e) {
            // 🔇 Unknown / expired / reused / client-mismatched — ALL
            //    indistinguishable to the caller (never leak WHY; detail is
            //    logged inside TokenService). RFC 6749 §5.2: invalid_grant.
            return self::errorResult(
                400,
                'invalid_grant',
                'The refresh token is invalid, expired, or does not belong to this client.'
            );
        }

        // 📝 Best-effort audit (no token material logged). $pair['user_id'] is
        //    TokenService's own internal convenience field (see mintPair()) —
        //    avoids an extra DB round trip just to attribute this log entry.
        if (class_exists('ActivityLogger')) {
            try {
                ActivityLogger::log(
                    isset($pair['user_id']) ? (int) $pair['user_id'] : null,
                    'oauth_token_refreshed',
                    'auth',
                    'info',
                    "OAuth refresh-token rotated for client \"{$client['clientName']}\"",
                    ['clientID' => $clientID, 'scope' => $pair['scope'], 'familyID' => $pair['family_id']]
                );
            } catch (\Throwable $e) {
                error_log('OAuthTokenService: activity log failed: ' . $e->getMessage());
            }
        }

        $body = [
            'access_token' => $pair['access_token'],
            'token_type'   => 'Bearer',
            'expires_in'   => $pair['expires_in'],
            'scope'        => $pair['scope'],
        ];
        // 🔁 A rotated token ALWAYS re-mints a refresh token (TokenService's
        //    refresh() defaults $withRefresh=true unconditionally — possessing
        //    a live refresh token to rotate already proves offline_access was
        //    granted at the original issuance), so this is always present.
        if (!empty($pair['refresh_token'])) {
            $body['refresh_token'] = $pair['refresh_token'];
        }
        // 🪪 id_token: OIDC Core §12.2 permits (does not require) a fresh
        //    id_token on refresh. Deliberately OMITTED here — re-minting it
        //    would need to re-resolve the pairwise `sub` + re-fetch profile/
        //    email claims for a userID/scope pair that would cost an extra
        //    query this grant does not otherwise need; RFC 6749/OIDC do not
        //    require it, so this is a valid, conformant, minimal response.
        //    Flagged as a possible follow-up, not a gap in THIS stage.

        return ['status' => 200, 'body' => $body, 'headers' => []];
    }

    // ========================================================================
    // 🚨 REPLAY HANDLING
    // ========================================================================

    /**
     * 🚨 A codeHash failed the atomic single-use consume (0 rows affected).
     * Determine whether that is a genuine REPLAY (the row exists and is
     * already consumed) and, if a token family was minted from it, revoke
     * EXACTLY that family (never a broader (clientID,userID) sweep — see
     * migration 032's header for the rationale).
     *
     * A codeHash with NO matching row at all (never issued / long since
     * purged) is a silent no-op — there is nothing to revoke.
     *
     * @param string $codeHash SHA-256 hex of the presented code.
     * @return void
     */
    private static function handlePossibleReplay(string $codeHash): void
    {
        $row = Database::fetchOne(
            "SELECT codeID, clientID, userID, issuedFamilyID, consumedAt
             FROM tblOAuthAuthCodes
             WHERE codeHash = ?
             LIMIT 1",
            [$codeHash],
            's'
        );

        if ($row === null || empty($row['consumedAt'])) {
            // Unknown code, OR (extremely unlikely race) a row that is
            // somehow NOT consumed despite our UPDATE losing — nothing to
            // revoke either way; the caller already denies with invalid_grant.
            return;
        }

        $familyID = $row['issuedFamilyID'] !== null ? (string) $row['issuedFamilyID'] : '';
        if ($familyID === '') {
            // The code was consumed but no family was ever minted from it
            // (e.g. a first exchange that failed a LATER check — client
            // mismatch/expiry/PKCE — after the atomic consume). Nothing to
            // revoke; the code simply stays dead, which is correct.
            return;
        }

        $userID = isset($row['userID']) ? (int) $row['userID'] : null;

        // 🧵 Revoke EXACTLY the family this specific code produced.
        if (class_exists('TokenService')) {
            TokenService::revokeFamily($familyID, TokenService::REASON_REUSE_DETECTED);
        }

        // 🚨 Best-effort security alert (mirrors TokenService::handleReuse()'s
        //    own best-effort alerting — the revoke above is the actual
        //    security control; this is the notification).
        if (class_exists('SecurityAlertManager')) {
            try {
                SecurityAlertManager::create(
                    SecurityAlertManager::TYPE_SESSION_HIJACK,
                    SecurityAlertManager::SEVERITY_HIGH,
                    'OAuth authorization-code replay detected — token family revoked (possible code/token theft).',
                    [
                        'event'        => 'oauth_authcode_replay',
                        'codeID'       => (int) $row['codeID'],
                        'clientID'     => isset($row['clientID']) ? (int) $row['clientID'] : null,
                        'family_id'    => $familyID,
                        'action_taken' => 'family_revoked',
                    ],
                    $userID,
                    function_exists('getClientIP') ? getClientIP() : null
                );
            } catch (\Throwable $e) {
                error_log('OAuthTokenService::handlePossibleReplay alert failed: ' . $e->getMessage());
            }
        }

        if (class_exists('ActivityLogger')) {
            try {
                ActivityLogger::log(
                    $userID,
                    'oauth_authcode_replay_detected',
                    'auth',
                    'critical',
                    'OAuth authorization-code replay detected - token family revoked.',
                    ['family_id' => $familyID, 'codeID' => (int) $row['codeID']]
                );
            } catch (\Throwable $e) {
                error_log('OAuthTokenService::handlePossibleReplay activity log failed: ' . $e->getMessage());
            }
        }
    }

    // ========================================================================
    // 🪪 PROFILE / EMAIL CLAIMS (scope-gated)
    // ========================================================================

    /**
     * 🪪 Add profile/email claims to the id_token claim set — STRICTLY gated
     * by the granted scope (never more than what was actually consented to).
     *
     * @param array<string,mixed> &$claims    id_token claims, mutated in place.
     * @param int                  $userID    Subject's internal userID (for the
     *                                        tblUsers lookup — NOT used as the
     *                                        `sub` claim itself).
     * @param array<int,string>    $scopeArray Granted scope tokens.
     * @return void
     */
    private static function addProfileClaims(array &$claims, int $userID, array $scopeArray): void
    {
        $wantsEmail   = in_array('email', $scopeArray, true);
        $wantsProfile = in_array('profile', $scopeArray, true);

        if (!$wantsEmail && !$wantsProfile) {
            return;
        }

        $user = Database::fetchOne(
            "SELECT email, emailVerified, displayName, firstName, lastName FROM tblUsers WHERE userID = ? LIMIT 1",
            [$userID],
            'i'
        );
        if ($user === null) {
            return;
        }

        if ($wantsEmail) {
            $claims['email']          = (string) $user['email'];
            $claims['email_verified'] = (bool) $user['emailVerified'];
        }

        if ($wantsProfile) {
            if (!empty($user['displayName'])) {
                $claims['name'] = (string) $user['displayName'];
            }
            if (!empty($user['firstName'])) {
                $claims['given_name'] = (string) $user['firstName'];
            }
            if (!empty($user['lastName'])) {
                $claims['family_name'] = (string) $user['lastName'];
            }
        }
    }

    // ========================================================================
    // 🔧 RESULT-SHAPE HELPERS
    // ========================================================================

    /**
     * ❌ A client-authentication failure result (401 invalid_client).
     *
     * Per RFC 6749 §5.2: `WWW-Authenticate: Basic` is included ONLY when the
     * client attempted Basic authentication — a body-based attempt gets no
     * such header (there is no "scheme" to advertise for it).
     *
     * @param bool   $viaBasic    Whether the client used the Basic auth header.
     * @param string $description Human-readable description.
     * @return array{ok:bool, status:int, body:array, headers:array}
     */
    private static function clientAuthFailure(bool $viaBasic, string $description): array
    {
        $headers = $viaBasic ? ['WWW-Authenticate' => 'Basic realm="SIGNula"'] : [];

        return [
            'ok'      => false,
            'status'  => 401,
            'body'    => ['error' => 'invalid_client', 'error_description' => $description],
            'headers' => $headers,
        ];
    }

    /**
     * ❌ Build a standard OAuth error result.
     *
     * @param int    $status      HTTP status code.
     * @param string $error       OAuth error code.
     * @param string $description Human-readable description.
     * @return array{status:int, body:array, headers:array}
     */
    private static function errorResult(int $status, string $error, string $description): array
    {
        return [
            'status'  => $status,
            'body'    => ['error' => $error, 'error_description' => $description],
            'headers' => [],
        ];
    }

    /**
     * 🔄 Normalise an authenticateClient() failure (`ok/status/body/headers`)
     * down to the plain `status/body/headers` shape every OTHER method here
     * returns.
     *
     * @param array<string,mixed> $auth
     * @return array{status:int, body:array, headers:array}
     */
    private static function asResult(array $auth): array
    {
        return [
            'status'  => (int) ($auth['status'] ?? 400),
            'body'    => (array) ($auth['body'] ?? ['error' => 'invalid_client', 'error_description' => 'Client authentication failed.']),
            'headers' => (array) ($auth['headers'] ?? []),
        ];
    }

    /**
     * 🧹 Coerce a raw param value to a trimmed string, guarding against
     * non-scalar input (e.g. a crafted `code[]=x` array parameter) being
     * silently stringified.
     *
     * @param array<string,mixed> $params
     * @param string              $key
     * @return string '' if absent/non-scalar.
     */
    private static function stringParam(array $params, string $key): string
    {
        if (!isset($params[$key]) || !is_scalar($params[$key])) {
            return '';
        }

        return trim((string) $params[$key]);
    }
}

// ✅ OAuthTokenService loaded successfully
