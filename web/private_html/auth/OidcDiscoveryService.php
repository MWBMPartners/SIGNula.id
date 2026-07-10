<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 🪪 SIGNula - OAuth2/OIDC Provider: Discovery Document Builder (G-001 Stage A4)
 * ============================================================================
 *
 * Purpose:
 *   Pure builder for the OIDC discovery document (OpenID Connect Discovery
 *   1.0 §3 "OpenID Provider Metadata") served at
 *   `GET /.well-known/openid-configuration`. Kept as a standalone, DB-free
 *   (beyond `getSetting()`) builder — not a method on OAuthClientManager or
 *   any other class — so it is trivially Unit-testable (call
 *   {@see self::buildDocument()} directly, assert the array) without needing
 *   a database or an HTTP request. Mirrors the "thin controller, testable
 *   engine" split every other G-001 stage uses (OAuthTokenService/token.php,
 *   OAuthUserInfoService/userinfo.php, OAuthRevocationService/revoke.php).
 *
 * 🔒 What is DELIBERATELY advertised vs withheld:
 *   • `grant_types_supported` = ["authorization_code", "refresh_token"]. The
 *     `refresh_token` grant was wired at `/oauth/token` in G-001 Stage A5
 *     once TokenService::refresh() gained its presented-refresh-token↔
 *     authenticated-client binding check (B-058) — see
 *     OAuthTokenService::exchangeRefreshToken(). Advertising it here now
 *     correctly informs RP OIDC libraries that auto-detect supported grants
 *     from this document.
 *   • `token_endpoint_auth_methods_supported` includes "none" — PUBLIC
 *     clients (SPA/native/mobile) authenticate via PKCE, not a client secret
 *     (see OAuthClientManager/OAuthTokenService::authenticateClient()).
 *   • `subject_types_supported` includes BOTH "pairwise" (the default for new
 *     client registrations) and "public" (still a valid, supported
 *     `subjectType` value — see OAuthClientManager::computeSubject()).
 *
 * PHP Version: 8.3+ (developed/tested on 8.4).
 *
 * @package    SIGNula
 * @subpackage Auth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A4)
 * @link       https://openid.net/specs/openid-connect-discovery-1_0.html (OIDC Discovery 1.0)
 *
 * @see web/public_html/.well-known/openid-configuration/index.php (the thin controller that consumes this class)
 * @see web/public_html/.well-known/jwks.json/index.php            (the sibling public-JWKS endpoint this URL is advertised alongside)
 * @see _database/migrations/031_oauth_provider_clients.sql        (oidc.issuer + other oidc.* policy settings)
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
 * 🪪 OIDC discovery-document builder (`GET /.well-known/openid-configuration`).
 */
class OidcDiscoveryService
{
    /**
     * 🔒 Is the OAuth2/OIDC PROVIDER surface enabled at all? (G-001 red-team
     * F-05 fix)
     *
     * Migration 031 seeds `oidc.enabled = '0'` ("master switch, default OFF
     * until an operator opts in") but — before this fix — NOTHING ever read
     * it: every provider endpoint (`/oauth/authorize-idp`, `/oauth/token`,
     * `/oauth/userinfo`, `/oauth/revoke`, the discovery document) was fully
     * live regardless of the setting. This is the single shared gate every
     * one of those controllers calls at the very top of the request, BEFORE
     * any DB-bound client/grant work — so the whole "Sign in with
     * SIGNula.id" surface stays off until an operator explicitly flips
     * `oidc.enabled` to `1`.
     *
     * Deliberately NOT applied to `/.well-known/jwks.json` — that endpoint
     * is SHARED with the first-party G-003 JWT bearer-auth stack (which has
     * its own, separate `jwt.enabled` concern) and must keep serving keys
     * regardless of the OIDC-provider switch.
     *
     * `filter_var(..., FILTER_VALIDATE_BOOLEAN)` (not a bare truthy check)
     * so every shape `getSetting()` can hand back — a real PHP bool (the
     * normal runtime path, since `loadSettings()` casts a `settingType =
     * 'boolean'` row via the SAME filter_var call), or a raw string ('0'/'1'/
     * 'true'/'false') a test harness might set directly — resolves to the
     * SAME correct boolean. Defaults to `false` (OFF) when the setting is
     * entirely absent, matching the seeded default's intent.
     *
     * @return bool True only when the provider is explicitly enabled.
     */
    public static function isProviderEnabled(): bool
    {
        return filter_var(getSetting('oidc.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 🏗️ Build the full OIDC discovery document.
     *
     * Every endpoint URL is derived from `oidc.issuer` (never a hardcoded
     * host) so the document stays correct across environments (dev/staging/
     * prod) without a code change — mirrors how Jwt::sign()/signIdToken()
     * derive `iss` from settings rather than a constant.
     *
     * @return array<string,mixed> The discovery document (JSON-encodable as-is).
     */
    public static function buildDocument(): array
    {
        // 🌐 `oidc.issuer` is REQUIRED to already equal `jwt.issuer` (migration
        //    031's seed comment) — trailing slash stripped defensively so
        //    every endpoint URL below is built with exactly one '/' joiner.
        $issuer = rtrim((string) getSetting('oidc.issuer', 'https://signula.id'), '/');

        return [
            'issuer'                                => $issuer,
            'authorization_endpoint'                 => $issuer . '/oauth/authorize-idp',
            'token_endpoint'                          => $issuer . '/oauth/token',
            'userinfo_endpoint'                       => $issuer . '/oauth/userinfo',
            'jwks_uri'                                 => $issuer . '/.well-known/jwks.json',
            'revocation_endpoint'                     => $issuer . '/oauth/revoke',
            'response_types_supported'                => ['code'],
            // ✅ refresh_token wired at /oauth/token (G-001 Stage A5) — see class doc.
            'grant_types_supported'                   => ['authorization_code', 'refresh_token'],
            'subject_types_supported'                 => ['pairwise', 'public'],
            'id_token_signing_alg_values_supported'    => ['RS256'],
            'scopes_supported'                         => ['openid', 'email', 'profile', 'offline_access'],
            'token_endpoint_auth_methods_supported'    => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported'         => ['S256'],
            'claims_supported'                         => [
                'sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce',
                'email', 'email_verified',
                'name', 'given_name', 'family_name', 'preferred_username', 'picture',
            ],
        ];
    }
}

// ✅ OidcDiscoveryService loaded successfully
