<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 🪪 SIGNula - OAuth2/OIDC Provider: Revocation Endpoint Service (G-001 Stage A4)
 * ============================================================================
 *
 * Purpose:
 *   The pure/DB-bound business logic behind `/oauth/revoke` — RFC 7009 OAuth
 *   2.0 Token Revocation. Lets an RP (that already authenticates itself, the
 *   same way it does at `/oauth/token`) proactively kill a refresh token's
 *   whole rotation family, or denylist a still-live access token's jti,
 *   BEFORE its natural expiry — e.g. because its own user just logged out
 *   inside the RP's own app. This is the RP-initiated sibling of Stage A4's
 *   user-initiated "Connected Apps" hub revoke
 *   ({@see OAuthAuthorizeService::revokeConsent()}).
 *
 * Split of responsibilities (mirrors OAuthTokenService/token.php from Stage A3):
 *   • handleRevokeRequest() — the single entry point the controller calls.
 *     Authenticates the client (reusing
 *     {@see OAuthTokenService::authenticateClient()} — EXACTLY the same
 *     client-authentication rules as `/oauth/token`), then revokes ONLY a
 *     token this SAME client owns.
 *
 * 🔒 THE core security property — NO CROSS-CLIENT REVOKE:
 *   A token presented at `/oauth/revoke` is revoked ONLY if it belongs to the
 *   client that just authenticated. A refresh token's ownership is its
 *   `tblRefreshTokens.clientID`; an access token's ownership is its own `aud`
 *   claim (verified, not merely read). Client A can never use this endpoint
 *   to kill Client B's users' sessions, even by guessing/observing a token
 *   value. A first-party token (clientID NULL / aud = jwt.audience) can never
 *   be revoked here either — it belongs to no RP client, so no authenticated
 *   RP client can ever "own" it.
 *
 * 🔒 THE other core security property — NO VALIDITY ORACLE (RFC 7009 §2.2):
 *   `handleRevokeRequest()` ALWAYS returns HTTP 200 with an empty body once
 *   the CLIENT itself has authenticated successfully — an unknown token, an
 *   already-revoked token, or someone else's token (silently refused per the
 *   rule above) are ALL indistinguishable 200s. Only a CLIENT authentication
 *   failure (RFC 6749 §5.2, not a token-validity signal) gets a different
 *   (401) response.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4).
 *
 * @package    SIGNula
 * @subpackage Auth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A4)
 * @link       https://datatracker.ietf.org/doc/html/rfc7009 (OAuth 2.0 Token Revocation)
 *
 * @see web/private_html/auth/OAuthTokenService.php       (Stage A3 — authenticateClient() reused verbatim)
 * @see web/private_html/security/TokenService.php         (G-003 — revokeFamily()/revokeAccessJti()/verifyAccessToken())
 * @see web/private_html/auth/OAuthAuthorizeService.php    (Stage A4 sibling — user-initiated consent revoke)
 * @see web/public_html/oauth/revoke.php                    (the thin controller that consumes this class)
 * @see web/private_html/api/controllers/JwtAuthController.php (revokeToken() — the shape this mirrors, first-party)
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
 * 🪪 OAuth2/OIDC revocation-endpoint engine (`/oauth/revoke`, RFC 7009).
 *
 * All methods are static. {@see self::handleRevokeRequest()} returns the SAME
 * result shape OAuthTokenService's methods do:
 *   `['status' => int, 'body' => array<string,mixed>, 'headers' => array<string,string>]`
 */
class OAuthRevocationService
{
    // ========================================================================
    // 🚦 ENTRY POINT
    // ========================================================================

    /**
     * 🚦 Handle a `/oauth/revoke` request end to end (RFC 7009 §2).
     *
     * @param array<string,mixed> $params     Raw request parameters: `token`
     *                                          (required), `token_type_hint`
     *                                          (optional — 'refresh_token' or
     *                                          'access_token', RFC 7009 §2.1).
     * @param array<string,mixed> $clientAuth  Parsed client credentials —
     *                                          SAME shape OAuthTokenService
     *                                          expects: `method`
     *                                          ('basic'|'body'), `client_id`,
     *                                          `client_secret` (nullable).
     * @return array{status:int, body:array<string,mixed>, headers:array<string,string>}
     */
    public static function handleRevokeRequest(array $params, array $clientAuth): array
    {
        // 1️⃣ Client authentication FIRST — reuses the EXACT same rules
        //    `/oauth/token` enforces (confidential MUST present a valid
        //    secret; public clients present none). An unknown/failed client
        //    gets 401 invalid_client — the ONE response that is allowed to
        //    differ from the always-200 token-revocation outcome, because it
        //    is about the CLIENT's own identity, not any token's validity.
        if (!class_exists('OAuthTokenService')) {
            return self::errorResult(500, 'server_error', 'Client registry unavailable.');
        }
        $auth = OAuthTokenService::authenticateClient($clientAuth);
        if (($auth['ok'] ?? false) !== true) {
            return [
                'status'  => (int) ($auth['status'] ?? 401),
                'body'    => (array) ($auth['body'] ?? ['error' => 'invalid_client']),
                'headers' => (array) ($auth['headers'] ?? []),
            ];
        }
        $client = $auth['client'];

        $token = self::stringParam($params, 'token');
        $hint  = strtolower(self::stringParam($params, 'token_type_hint'));

        // 🔒 RFC 7009 §2.2: even a missing/empty token is a no-op 200, NEVER
        //    an error that would hint at what a "real" request looks like.
        if ($token !== '') {
            try {
                if (self::hintsRefresh($hint) || (!self::hintsAccess($hint) && self::looksLikeOpaqueRefresh($token))) {
                    self::revokeRefreshIfOwnedByClient($token, (int) $client['clientID']);
                } elseif (self::hintsAccess($hint) || self::looksLikeJwt($token)) {
                    self::revokeAccessIfOwnedByClient($token, (string) $client['clientIdentifier']);
                }
            } catch (\Throwable $e) {
                // 🔇 Never let a best-effort revocation failure change the
                //    (always-200) response shape or leak detail to the caller.
                error_log('OAuthRevocationService::handleRevokeRequest — ' . $e->getMessage());
            }
        }

        // ✅ RFC 7009 §2.2: always 200, empty body, no validity signal.
        return ['status' => 200, 'body' => [], 'headers' => []];
    }

    // ========================================================================
    // 🔄 REFRESH-TOKEN REVOCATION (ownership-checked)
    // ========================================================================

    /**
     * 🔄 Revoke a presented refresh token's WHOLE rotation family — but ONLY
     * if that token's `clientID` matches the AUTHENTICATED client. A token
     * belonging to a different client (or a first-party token, clientID
     * NULL) is silently refused: no revoke happens, and the caller still
     * gets the SAME 200 either way (no oracle).
     *
     * @param string $token    Opaque refresh-token plaintext presented by the caller.
     * @param int    $clientID The AUTHENTICATED client's internal clientID.
     * @return void
     */
    private static function revokeRefreshIfOwnedByClient(string $token, int $clientID): void
    {
        $hash = hash('sha256', $token);
        $row  = Database::fetchOne(
            "SELECT familyID, userID, clientID FROM tblRefreshTokens WHERE tokenHash = ? LIMIT 1",
            [$hash],
            's'
        );
        if ($row === null) {
            return; // Unknown token — no-op, no signal.
        }

        $ownerClientID = $row['clientID'] !== null ? (int) $row['clientID'] : null;
        if ($ownerClientID !== $clientID) {
            // 🔒 Belongs to a DIFFERENT client (or is first-party) — refuse.
            //    Cross-client revoke would let one RP kill another RP's (or
            //    SIGNula's own first-party) users' sessions.
            return;
        }

        TokenService::revokeFamily((string) $row['familyID'], TokenService::REASON_LOGOUT);

        if (class_exists('ActivityLogger')) {
            try {
                ActivityLogger::log(
                    isset($row['userID']) ? (int) $row['userID'] : null,
                    'oauth_refresh_revoked',
                    'auth',
                    'info',
                    'Refresh-token family revoked via /oauth/revoke',
                    ['clientID' => $clientID]
                );
            } catch (\Throwable $e) {
                error_log('OAuthRevocationService: activity log failed: ' . $e->getMessage());
            }
        }
    }

    // ========================================================================
    // 🎟️ ACCESS-TOKEN REVOCATION (ownership-checked)
    // ========================================================================

    /**
     * 🎟️ Denylist a presented access token's jti — but ONLY if the token's
     * OWN `aud` claim (verified, not merely read) equals the AUTHENTICATED
     * client's `clientIdentifier`.
     *
     * Uses the SAME untrusted-peek-then-verify pattern as
     * {@see OAuthUserInfoService}: the peeked aud is used ONLY to decide
     * whether to even attempt a verify (cheap short-circuit for an obviously
     * foreign token); the crypto verify is what actually proves ownership —
     * a forged aud claim fails signature verification regardless.
     *
     * @param string $token             Compact access-token JWT presented by the caller.
     * @param string $clientIdentifier  The AUTHENTICATED client's public client_id.
     * @return void
     */
    private static function revokeAccessIfOwnedByClient(string $token, string $clientIdentifier): void
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || !class_exists('KeyManager')) {
            return;
        }

        $json = KeyManager::base64UrlDecode($parts[1]);
        $payload = $json !== '' ? json_decode($json, true) : null;
        $claimedAud = is_array($payload) && isset($payload['aud']) && is_string($payload['aud'])
            ? $payload['aud']
            : null;

        if ($claimedAud === null || !hash_equals($clientIdentifier, $claimedAud)) {
            // Peeked aud isn't even this client — refuse without spending a
            // full crypto verify (cheap, safe short-circuit; the REAL
            // ownership proof below still re-derives everything from the
            // verified claims, never trusts this peek for the actual revoke).
            return;
        }

        try {
            $claims = TokenService::verifyAccessToken($token, ['aud' => $claimedAud]);
        } catch (\Throwable $e) {
            return; // Not a token we can trust → nothing to revoke.
        }

        $jti = isset($claims['jti']) && is_string($claims['jti']) ? $claims['jti'] : '';
        if ($jti === '') {
            return;
        }

        $exp    = isset($claims['exp']) ? (int) $claims['exp'] : null;
        $userID = isset($claims['sub']) ? (int) $claims['sub'] : null;

        TokenService::revokeAccessJti($jti, $userID, $exp, TokenService::REASON_LOGOUT);

        if (class_exists('ActivityLogger')) {
            try {
                ActivityLogger::log(
                    $userID,
                    'oauth_access_revoked',
                    'auth',
                    'info',
                    'Access-token jti denylisted via /oauth/revoke',
                    ['jti' => $jti]
                );
            } catch (\Throwable $e) {
                error_log('OAuthRevocationService: activity log failed: ' . $e->getMessage());
            }
        }
    }

    // ========================================================================
    // 🔧 INTERNAL HELPERS
    // ========================================================================

    /**
     * 🧪 Does the token_type_hint indicate a refresh token? Accepts both the
     * RFC 7009 canonical value ('refresh_token') and the shorthand this
     * codebase's own `/api/v1/auth/revoke` uses ('refresh') for consistency.
     *
     * @param string $hint Lowercased token_type_hint.
     * @return bool
     */
    private static function hintsRefresh(string $hint): bool
    {
        return $hint === 'refresh_token' || $hint === 'refresh';
    }

    /**
     * 🧪 Does the token_type_hint indicate an access token? Accepts both the
     * RFC 7009 canonical value ('access_token') and this codebase's
     * shorthand ('access').
     *
     * @param string $hint Lowercased token_type_hint.
     * @return bool
     */
    private static function hintsAccess(string $hint): bool
    {
        return $hint === 'access_token' || $hint === 'access';
    }

    /**
     * 🧪 Does a string look like a compact JWT (three dot-separated segments)?
     *
     * @param string $token
     * @return bool
     */
    private static function looksLikeJwt(string $token): bool
    {
        return substr_count($token, '.') === 2;
    }

    /**
     * 🧪 Does a string look like an opaque refresh token (64 lowercase hex)?
     *
     * @param string $token
     * @return bool
     */
    private static function looksLikeOpaqueRefresh(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $token);
    }

    /**
     * ❌ Build a standard OAuth error result (used only for the client-
     * registry-unavailable defensive branch — a should-never-happen path).
     *
     * @param int    $status
     * @param string $error
     * @param string $description
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
     * 🧹 Coerce a raw param value to a trimmed string, guarding against
     * non-scalar input being silently stringified.
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

// ✅ OAuthRevocationService loaded successfully
