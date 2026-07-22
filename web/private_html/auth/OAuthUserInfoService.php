<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 🪪 SIGNula - OAuth2/OIDC Provider: UserInfo Endpoint Service (G-001 Stage A4)
 * ============================================================================
 *
 * Purpose:
 *   The pure/DB-bound business logic behind `/oauth/userinfo` (OIDC Core §5.3
 *   — SIGNula acting as the OAuth2/OIDC PROVIDER, telling an already-token-
 *   holding Relying Party who the user is). This class is the "engine"; the
 *   public_html page (`web/public_html/oauth/userinfo.php`) is a THIN
 *   controller that pulls the Bearer token out of the request and emits the
 *   JSON this service returns — kept that way specifically so the
 *   authentication + scope-gating logic can be exercised directly by
 *   Integration tests without a real HTTP request. Mirrors the
 *   OAuthTokenService/token.php split from Stage A3.
 *
 * 🔒 THE core security property — CLAIM GATING (never over-disclose):
 *   userinfo must return ONLY the claims the token's GRANTED scope actually
 *   covers. `sub` is always returned (OIDC Core §5.3.2 requires it); `email`/
 *   `email_verified` require the `email` scope; `name`/`given_name`/
 *   `family_name`/`preferred_username`/`picture` require the `profile` scope.
 *   A token minted with scope `openid` alone gets `sub` and NOTHING else.
 *
 * 🔒 THE authentication flow — untrusted-peek-then-verify for `aud`:
 *   An RP access token's `aud` claim is the RP's own `client_identifier`
 *   (unlike a first-party token, whose `aud` defaults to `jwt.audience`) —
 *   see {@see TokenService::mintPair()} / Jwt::sign(). Jwt::verify()
 *   ENFORCES aud against whatever `expect['aud']` the caller passes (or the
 *   `jwt.audience` default if none is passed), so we must learn the RIGHT
 *   audience to demand BEFORE we can call verify at all. We do that by
 *   base64url-decoding the token's payload segment ourselves — an UNTRUSTED
 *   read used ONLY to pick which audience to demand next. The RS256
 *   signature check inside {@see TokenService::verifyAccessToken()} is what
 *   actually PROVES we minted the token with that claimed aud: a forged or
 *   tampered aud fails signature verification regardless of what this peek
 *   said, so the peek can never be used to smuggle a false claim through.
 *
 *   Steps:
 *     1. Extract the Bearer token (Authorization header primary; `access_token`
 *        form/query param accepted per OIDC Core §5.3.1 as a fallback).
 *     2. Peek (UNVERIFIED) the token's own `aud` claim.
 *     3. `TokenService::verifyAccessToken($jwt, ['aud' => $claimedAud])` —
 *        crypto + jti-denylist + tokensInvalidBefore cutoff, all fail-closed.
 *     4. Resolve `$claimedAud` to an ACTIVE tblOAuthClients row via
 *        {@see OAuthClientManager::getClient()}. A first-party token (aud =
 *        jwt.audience, no RP client row) resolves to null here and is
 *        CORRECTLY refused — userinfo is an RP-facing OIDC surface, not a
 *        general resource-server endpoint.
 *     5. `sub` (G-001 red-team F-01 fix) — the ACCESS token's `sub` claim IS
 *        ITSELF the OIDC pairwise (or public, per policy) subject
 *        {@see TokenService::mintPair()} minted it with, the SAME opaque
 *        value the id_token used, so the two byte-match for the same
 *        user+client (OIDC Core §5.3.2) WITHOUT recomputation here. Since a
 *        pairwise subject is a ONE-WAY hash, the userID it belongs to is
 *        resolved via a `tblOAuthSubjects` lookup (migration 033, keyed on
 *        subjectHash + clientID) that TokenService::issueForClient()/
 *        refresh() populate at mint/rotation time — never by parsing the
 *        claim as a raw userID.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4).
 *
 * @package    SIGNula
 * @subpackage Auth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A4)
 * @link       https://openid.net/specs/openid-connect-core-1_0.html#UserInfo (OIDC UserInfo Endpoint)
 * @link       https://datatracker.ietf.org/doc/html/rfc6750 (Bearer Token Usage)
 *
 * @see web/private_html/auth/OAuthClientManager.php     (Stage A1 — client model, computeSubject())
 * @see web/private_html/security/TokenService.php        (G-003 — verifyAccessToken())
 * @see web/public_html/oauth/userinfo.php                (the thin controller that consumes this class)
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
 * 🪪 OAuth2/OIDC userinfo-endpoint engine (`/oauth/userinfo`).
 *
 * All methods are static (matches the OAuthClientManager / OAuthTokenService
 * house style). {@see self::handleUserInfoRequest()} returns the SAME result
 * shape OAuthTokenService's methods do:
 *   `['status' => int, 'body' => array<string,mixed>, 'headers' => array<string,string>]`
 * so the controller never has to construct a response body itself.
 */
class OAuthUserInfoService
{
    // ========================================================================
    // 🚦 ENTRY POINT
    // ========================================================================

    /**
     * 🚦 Handle a `/oauth/userinfo` request end to end.
     *
     * @param string|null $bearerToken The compact JWT access token presented
     *                                  by the caller (already extracted by the
     *                                  controller via
     *                                  {@see self::extractBearerToken()}), or
     *                                  null/'' if none was presented.
     * @return array{status:int, body:array<string,mixed>, headers:array<string,string>}
     */
    public static function handleUserInfoRequest(?string $bearerToken): array
    {
        if ($bearerToken === null || $bearerToken === '') {
            return self::invalidTokenResult();
        }

        // 🔎 Step 2 — UNTRUSTED peek at the token's own aud claim, ONLY to
        //    know what to demand of verifyAccessToken() next (see class doc).
        $claimedAud = self::peekAudienceUnverified($bearerToken);
        if ($claimedAud === null) {
            return self::invalidTokenResult();
        }

        // ✅ Step 3 — the REAL, crypto-verified check. Any failure (bad
        //    signature, expired, denylisted jti, mass-revoked cutoff, wrong
        //    typ) is indistinguishable to the caller — generic 401.
        if (!class_exists('TokenService')) {
            return self::invalidTokenResult();
        }
        try {
            $claims = TokenService::verifyAccessToken($bearerToken, ['aud' => $claimedAud]);
        } catch (\Throwable $e) {
            return self::invalidTokenResult();
        }

        // 🪪 Step 4 — the aud MUST resolve to an ACTIVE registered RP client.
        //    A first-party token (aud = jwt.audience, no RP row) is CORRECTLY
        //    refused here — userinfo is an RP-facing OIDC surface.
        if (!class_exists('OAuthClientManager')) {
            return self::invalidTokenResult();
        }
        $client = OAuthClientManager::getClient($claimedAud);
        if ($client === null || empty($client['isActive'])) {
            return self::invalidTokenResult();
        }

        // 🕵️ Step 5 (G-001 red-team F-01 fix) — the access token's `sub` is
        //    now ITSELF the OIDC pairwise (or public, per policy) subject
        //    (see TokenService::mintPair()/issueForClient()) — the SAME opaque
        //    value the id_token already carries — rather than the raw userID.
        //    A pairwise subject is a ONE-WAY hash, so we can no longer just
        //    cast it to an int; resolve it back to a real userID via the
        //    (subjectHash, clientID) mapping TokenService persisted at
        //    mint/rotation time (tblOAuthSubjects, migration 033). A valid
        //    RP access token ALWAYS has a mapping row (issueForClient()/
        //    refresh() always write one before returning the token) — a miss
        //    here means the token, though cryptographically valid, was never
        //    actually minted for this client (should be unreachable in
        //    practice; fail closed regardless).
        if (!isset($claims['sub']) || !is_string($claims['sub']) || $claims['sub'] === '') {
            return self::invalidTokenResult();
        }
        $sub = $claims['sub'];

        $subjectRow = Database::fetchOne(
            "SELECT userID FROM tblOAuthSubjects WHERE subjectHash = ? AND clientID = ? LIMIT 1",
            [$sub, (int) $client['clientID']],
            'si'
        );
        if ($subjectRow === null) {
            return self::invalidTokenResult();
        }
        $userID = (int) $subjectRow['userID'];
        if ($userID <= 0) {
            return self::invalidTokenResult();
        }

        $grantedScopes = self::parseScope((string) ($claims['scope'] ?? ''));

        $body = ['sub' => $sub];
        self::addGatedClaims($body, $userID, $grantedScopes);

        return ['status' => 200, 'body' => $body, 'headers' => []];
    }

    // ========================================================================
    // 🔐 REQUEST PARSING (pure — no superglobal access, fully testable)
    // ========================================================================

    /**
     * 🔐 Resolve the Bearer access token from the parsed request context.
     *
     * Precedence (OIDC Core §5.3.1 / RFC 6750 §2): the Authorization header
     * is primary; a request-body OR query-string `access_token` parameter is
     * accepted as a fallback (RFC 6750 §2.2/§2.3) but the header always wins
     * when present and well-formed, avoiding any ambiguity about which value
     * is actually authenticating.
     *
     * @param string|null          $authorizationHeader Raw `Authorization` header value, or null if absent.
     * @param array<string,mixed>  $postParams          Parsed POST body.
     * @param array<string,mixed>  $getParams           Parsed query string.
     * @return string|null The extracted token, or null if none was presented.
     */
    public static function extractBearerToken(
        ?string $authorizationHeader,
        array $postParams,
        array $getParams
    ): ?string {
        if ($authorizationHeader !== null && stripos($authorizationHeader, 'Bearer ') === 0) {
            $token = trim(substr($authorizationHeader, 7));
            return $token !== '' ? $token : null;
        }

        if (isset($postParams['access_token']) && is_string($postParams['access_token']) && $postParams['access_token'] !== '') {
            return $postParams['access_token'];
        }

        if (isset($getParams['access_token']) && is_string($getParams['access_token']) && $getParams['access_token'] !== '') {
            return $getParams['access_token'];
        }

        return null;
    }

    // ========================================================================
    // 🏷️ SCOPE-GATED CLAIMS
    // ========================================================================

    /**
     * 🏷️ Add profile/email claims to the userinfo body — STRICTLY gated by
     * the GRANTED scope (mirrors OAuthTokenService::addProfileClaims() for
     * the id_token, kept as a SEPARATE implementation here deliberately —
     * userinfo additionally exposes `preferred_username`/`picture`, which the
     * id_token does not, per this task's build contract).
     *
     * @param array<string,mixed> &$body       Response body, mutated in place.
     * @param int                  $userID      Subject's internal userID.
     * @param array<int,string>    $grantedScopes Granted scope tokens.
     * @return void
     */
    private static function addGatedClaims(array &$body, int $userID, array $grantedScopes): void
    {
        $wantsEmail   = in_array('email', $grantedScopes, true);
        $wantsProfile = in_array('profile', $grantedScopes, true);

        if (!$wantsEmail && !$wantsProfile) {
            return; // 🔒 openid-only grant — sub is ALL that goes out.
        }

        $user = Database::fetchOne(
            "SELECT email, emailVerified, displayName, firstName, lastName, username, profilePicture
             FROM tblUsers WHERE userID = ? LIMIT 1",
            [$userID],
            'i'
        );
        if ($user === null) {
            return;
        }

        if ($wantsEmail) {
            $body['email']          = (string) $user['email'];
            $body['email_verified'] = (bool) $user['emailVerified'];
        }

        if ($wantsProfile) {
            // Only include fields that actually exist/are non-null — never
            // emit an empty-string claim just because the column is present.
            if (!empty($user['displayName'])) {
                $body['name'] = (string) $user['displayName'];
            }
            if (!empty($user['firstName'])) {
                $body['given_name'] = (string) $user['firstName'];
            }
            if (!empty($user['lastName'])) {
                $body['family_name'] = (string) $user['lastName'];
            }
            if (!empty($user['username'])) {
                $body['preferred_username'] = (string) $user['username'];
            }
            if (!empty($user['profilePicture'])) {
                $body['picture'] = (string) $user['profilePicture'];
            }
        }
    }

    // ========================================================================
    // 🔧 INTERNAL HELPERS
    // ========================================================================

    /**
     * 🔎 UNTRUSTED peek at a JWT's `aud` claim, WITHOUT verifying anything.
     *
     * Used ONLY to learn which audience to demand of
     * {@see TokenService::verifyAccessToken()} next — see the class doc's
     * "authentication flow" section for why this can never be used to smuggle
     * a false claim through (the subsequent RS256 signature check is what
     * actually proves the aud is genuine).
     *
     * @param string $jwt Compact JWT.
     * @return string|null The claimed aud (a non-empty string), or null if the
     *                      token is malformed or aud is absent/non-string.
     */
    private static function peekAudienceUnverified(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        if (!class_exists('KeyManager')) {
            return null;
        }

        $json = KeyManager::base64UrlDecode($parts[1]);
        if ($json === '') {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['aud']) || !is_string($payload['aud'])) {
            // 🔒 SIGNula only ever mints a single-string aud (Jwt::sign());
            //    an array-form aud is not a shape we issue — reject rather
            //    than guess which entry to use.
            return null;
        }

        return $payload['aud'] !== '' ? $payload['aud'] : null;
    }

    /**
     * 📏 Parse a space-delimited scope string into a clean token array.
     *
     * @param string $scopeStr
     * @return array<int,string>
     */
    private static function parseScope(string $scopeStr): array
    {
        $scopeStr = trim($scopeStr);
        if ($scopeStr === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $scopeStr, -1, PREG_SPLIT_NO_EMPTY);

        return $parts !== false ? $parts : [];
    }

    /**
     * ❌ The standard RFC 6750 §3 "invalid_token" 401 result.
     *
     * @return array{status:int, body:array, headers:array}
     */
    private static function invalidTokenResult(): array
    {
        return [
            'status'  => 401,
            'body'    => ['error' => 'invalid_token'],
            'headers' => ['WWW-Authenticate' => 'Bearer error="invalid_token"'],
        ];
    }
}

// ✅ OAuthUserInfoService loaded successfully
