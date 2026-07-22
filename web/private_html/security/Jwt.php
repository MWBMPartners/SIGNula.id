<?php
/**
 * ============================================================================
 * 🎫 SIGNula - JWT Facade (RS256, alg-pinned) — G-003 signing foundation
 * ============================================================================
 *
 * Purpose:
 *   The SINGLE project-owned surface over the vendored firebase/php-jwt library
 *   (web/_lib/jwt). Every other part of SIGNula — and G-001 later — signs and
 *   verifies JWTs through THIS class and never touches Firebase\JWT\* directly.
 *   That gives one swap point for the library and one place to enforce SIGNula
 *   token policy.
 *
 *   Enforced policy (G-003 §7):
 *     • Algorithm PINNED to RS256 on both sign and verify. On verify we pass a
 *       Firebase\JWT\Key($publicPem, 'RS256'); the library rejects any token
 *       whose header alg ≠ the key's algorithm BEFORE checking the signature —
 *       so `alg:none` and the RS256→HS256 confusion attack (RSA public key
 *       abused as an HMAC secret) are both rejected. We NEVER read the token's
 *       own alg header to choose the verification path.
 *     • Registered-claim validation: iss (== jwt.issuer), aud (contains the
 *       expected audience), exp / iat / nbf (with bounded clock-skew leeway),
 *       and jti presence.
 *     • jti denylist check (revocation) via an injectable callback — the
 *       stateful denylist table (tblRevokedTokens) lands in Stage 2/3; here the
 *       hook exists and is honoured so the crypto layer is revocation-aware and
 *       unit-testable today.
 *     • `kid` header is emitted on sign (so verifiers pick the right JWKS key)
 *       and is only ever used to SELECT a key from our own set — never as a
 *       path or SQL fragment.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4). Requires ext-openssl.
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.8.0-beta (G-003 Stage 1)
 * @link       https://datatracker.ietf.org/doc/html/rfc7519 (JWT)
 * @link       https://datatracker.ietf.org/doc/html/rfc9068 (JWT access tokens / typ at+jwt)
 * @link       https://auth0.com/blog/critical-vulnerabilities-in-json-web-token-libraries/ (alg confusion)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key as FirebaseKey;

/**
 * 🎫 JWT sign/verify facade with RS256 pinned and SIGNula claim policy.
 *
 * All methods static (house style). Never leaks WHY a verify failed to callers
 * that render output — callers map a thrown JwtException to a generic 401 and
 * log the detail server-side (G-003 §6 "never leak why").
 */
class Jwt
{
    /**
     * @var string The one algorithm SIGNula signs/verifies with. RS256 only —
     *             asymmetric so verifiers hold only the public key (JWKS).
     */
    public const ALG = 'RS256';

    /** @var string RFC 9068 access-token media type carried in the JWT header `typ`. */
    public const TYP_ACCESS = 'at+jwt';

    /**
     * @var string OIDC ID Token media type carried in the JWT header `typ` — a
     *             plain 'JWT', deliberately NOT the RFC 9068 'at+jwt' marker
     *             (see {@see self::signIdToken()} for why).
     */
    public const TYP_ID_TOKEN = 'JWT';

    /**
     * 🚫 Optional jti-denylist checker.
     *
     * A callable (string $jti): bool that returns TRUE when the jti is REVOKED.
     * Injected so the crypto layer is revocation-aware without a hard dependency
     * on the (Stage 2/3) tblRevokedTokens table — and so unit tests can simulate
     * a revoked jti with no DB. Null → no denylist check (crypto-only verify).
     *
     * @var callable|null
     */
    private static $denylistChecker = null;

    // ========================================================================
    // 🧪 TEST / WIRING SEAM
    // ========================================================================

    /**
     * 🔌 Register the jti denylist checker.
     *
     * Stage 2/3 wires this to TokenService::isJtiRevoked(); tests inject a
     * closure over a fixed set of revoked jtis.
     *
     * @param callable|null $checker fn(string $jti): bool  (true = revoked)
     * @return void
     */
    public static function setDenylistChecker(?callable $checker): void
    {
        self::$denylistChecker = $checker;
    }

    // ========================================================================
    // ✍️ SIGN
    // ========================================================================

    /**
     * ✍️ Sign a set of claims into an RS256 access-token JWT.
     *
     * The active signing key (KeyManager::getActiveKey) provides the private PEM
     * and the `kid` header. Registered timing claims (iat/nbf/exp) and a random
     * jti are filled in automatically when not supplied. `iss`/`aud` default to
     * the jwt.issuer / jwt.audience settings.
     *
     * @param array<string,mixed> $claims  Custom + registered claims. `sub`
     *                                      (the userID) is expected; iss/aud/
     *                                      exp/iat/nbf/jti are auto-filled if
     *                                      absent.
     * @param string|null         $aud     Override audience (else jwt.audience).
     * @param int|null            $ttl     Access-token lifetime in seconds (else
     *                                      jwt.access_ttl, default 900).
     * @return string The signed compact JWT.
     * @throws JwtException On signing failure / missing active key.
     */
    public static function sign(array $claims, ?string $aud = null, ?int $ttl = null): string
    {
        self::ensureLibrary();

        try {
            $active = KeyManager::getActiveKey();
        } catch (\Throwable $e) {
            throw new JwtException('No active signing key available', 0, $e);
        }

        $now = time();
        $ttl = $ttl ?? (int) getSetting('jwt.access_ttl', 900);

        // 🧱 Merge SIGNula defaults UNDER any explicit caller claims, then FORCE
        //    the timing/identity claims we own so a caller can never weaken them.
        $payload = array_merge(
            [
                'iss'   => (string) getSetting('jwt.issuer', 'https://signula.id'),
                'aud'   => $aud ?? (string) getSetting('jwt.audience', 'https://signula.id/api'),
                'scope' => '',
            ],
            $claims,
            [
                'iat' => $now,
                'nbf' => $now,
                'exp' => $now + $ttl,
                'jti' => $claims['jti'] ?? SecurityUtils::generateToken(32), // 16 bytes → 32 hex
            ]
        );

        // 🔖 Header: pin alg + carry kid + mark as an RFC 9068 access token.
        $header = ['typ' => self::TYP_ACCESS];

        try {
            return FirebaseJWT::encode(
                $payload,
                $active['private_pem'],
                self::ALG,          // 🔒 alg pinned — never taken from input
                $active['kid'],     // 🆔 kid header
                $header
            );
        } catch (\Throwable $e) {
            throw new JwtException('JWT signing failed', 0, $e);
        }
    }

    // ========================================================================
    // 🪪 SIGN — OIDC ID TOKEN (G-001 Stage A3)
    // ========================================================================

    /**
     * 🪪 Sign a set of claims into an OIDC id_token (RS256).
     *
     * Distinct from {@see self::sign()} (which mints RFC 9068 ACCESS tokens) in
     * three deliberate ways — id_tokens are OIDC identity ASSERTIONS a Relying
     * Party verifies, not OAuth access grants a resource server enforces scope
     * against:
     *   • header `typ` = {@see self::TYP_ID_TOKEN} ('JWT'), NOT 'at+jwt'. RFC
     *     9068 §2.1 defines 'at+jwt' specifically for OAuth ACCESS tokens;
     *     marking an id_token that way would incorrectly imply RFC 9068
     *     access-token semantics to any RP OIDC library that inspects `typ`.
     *   • NO `scope` claim is ever carried — any caller-supplied 'scope' entry
     *     in `$claims` is deliberately stripped before signing. Scope belongs
     *     on the access token; an id_token carrying it too could be misread by
     *     an RP OIDC library as an authorization signal rather than the pure
     *     identity assertion OIDC Core defines it as.
     *   • `iss` defaults from the `oidc.issuer` setting (falling back to
     *     `jwt.issuer` if unset) and `ttl` from `oidc.id_token_ttl` (default
     *     3600s) — id_tokens are a G-001/Stage-A3 concept with their own
     *     policy knobs (migration 031), distinct from the G-003 `jwt.*` ones
     *     `sign()` reads for access tokens.
     *
     * `aud` is REQUIRED (unlike sign()'s optional override) and is FORCED —
     * placed in the claim set AFTER `$claims` so a caller cannot smuggle a
     * different audience through the claims array. Per OIDC Core §2, an
     * id_token's audience is ALWAYS the requesting RP's client_id.
     *
     * `nbf` is not a standard OIDC id_token claim, but we set it (= `iat`)
     * anyway for defence-in-depth consistency with the access-token signer —
     * a harmless extra restriction (rejects a not-yet-valid token) that any
     * spec-compliant verifier simply ignores if it does not check `nbf`.
     *
     * @param array<string,mixed> $claims Custom + registered claims (`sub`,
     *                                     and any of the standard OIDC identity
     *                                     claims the caller wants asserted —
     *                                     `auth_time`, `nonce`, `name`,
     *                                     `email`, …). `iss`/`iat`/`nbf`/`exp`/
     *                                     `jti` are auto-filled/forced; any
     *                                     `scope`/`aud` entry is stripped/
     *                                     overridden (see above).
     * @param string   $aud The client_id of the Relying Party this id_token is
     *                       issued to (required, always forced).
     * @param int|null $ttl id_token lifetime in seconds (else
     *                       `oidc.id_token_ttl`, default 3600).
     * @return string The signed compact JWT.
     * @throws JwtException On signing failure / missing active key.
     */
    public static function signIdToken(array $claims, string $aud, ?int $ttl = null): string
    {
        self::ensureLibrary();

        try {
            $active = KeyManager::getActiveKey();
        } catch (\Throwable $e) {
            throw new JwtException('No active signing key available', 0, $e);
        }

        $now = time();
        $ttl = $ttl ?? (int) getSetting('oidc.id_token_ttl', 3600);

        // 🚫 id_tokens never carry a scope claim — strip any caller-supplied
        //    value before merging (see method-level rationale above).
        unset($claims['scope']);

        $payload = array_merge(
            [
                'iss' => (string) getSetting('oidc.issuer', getSetting('jwt.issuer', 'https://signula.id')),
            ],
            $claims,
            [
                'aud' => $aud, // 🔒 forced — always the RP client_id, never caller-overridable via $claims
                'iat' => $now,
                'nbf' => $now,
                'exp' => $now + $ttl,
                'jti' => $claims['jti'] ?? SecurityUtils::generateToken(32), // 16 bytes → 32 hex
            ]
        );

        // 🔖 Header: pin alg + carry kid + mark as a plain OIDC id_token (NOT
        //    the RFC 9068 access-token typ — see method doc above).
        $header = ['typ' => self::TYP_ID_TOKEN];

        try {
            return FirebaseJWT::encode(
                $payload,
                $active['private_pem'],
                self::ALG,          // 🔒 alg pinned — never taken from input
                $active['kid'],     // 🆔 kid header
                $header
            );
        } catch (\Throwable $e) {
            throw new JwtException('id_token signing failed', 0, $e);
        }
    }

    // ========================================================================
    // ✅ VERIFY
    // ========================================================================

    /**
     * ✅ Verify + decode an RS256 JWT under SIGNula policy.
     *
     * Steps (fail-closed at every one):
     *   1. Load the library; read the (untrusted) header ONLY to select the kid.
     *   2. Look up OUR public key for that kid; build a Key pinned to RS256.
     *      → Unknown kid = reject. This also means the header alg is checked
     *        against RS256 inside the library BEFORE signature verification,
     *        defeating alg:none and RS256→HS256 confusion.
     *   3. Let firebase/php-jwt verify signature + exp/nbf/iat (with leeway).
     *   4. Enforce SIGNula claim policy: iss, aud, jti presence, optional typ.
     *   5. Denylist check the jti (revocation).
     *
     * @param string              $jwt    Compact JWT.
     * @param array<string,mixed> $expect Optional expectations:
     *                                     - 'aud'  string  required audience (else jwt.audience)
     *                                     - 'iss'  string  required issuer   (else jwt.issuer)
     *                                     - 'typ'  string  required header typ (e.g. 'at+jwt')
     *                                     - 'leeway' int   clock-skew seconds (else jwt.leeway_seconds)
     * @return array<string,mixed> The validated claims as an associative array.
     * @throws JwtException On ANY validation failure (caller maps to 401; the
     *                      message is for the SERVER LOG, not the response body).
     */
    public static function verify(string $jwt, array $expect = []): array
    {
        self::ensureLibrary();

        // 🔎 1. Structural pre-check + read header for kid/typ. We parse the
        //    header ourselves ONLY to select the key + inspect typ; the alg is
        //    NEVER trusted from here to choose verification.
        $header = self::readHeaderUnverified($jwt);

        $kid = isset($header['kid']) && is_string($header['kid']) ? $header['kid'] : '';
        if ($kid === '') {
            throw new JwtException('Missing kid header');
        }

        // 🔖 Optional typ enforcement (e.g. require access tokens 'at+jwt').
        if (isset($expect['typ'])) {
            $typ = $header['typ'] ?? '';
            if (!is_string($typ) || !hash_equals((string) $expect['typ'], $typ)) {
                throw new JwtException('Unexpected token typ');
            }
        }

        // 🗝️ 2. Look up OUR public key for this kid. Unknown kid → reject.
        $publicPem = KeyManager::getPublicKey($kid);
        if ($publicPem === null) {
            throw new JwtException('Unknown signing key id (kid)');
        }

        // ⏱️ Apply bounded clock-skew leeway (never larger than policy).
        $leeway = isset($expect['leeway'])
            ? (int) $expect['leeway']
            : (int) getSetting('jwt.leeway_seconds', 60);
        if ($leeway < 0) {
            $leeway = 0;
        }
        // Static prop on the library — set per-verify then reset in finally.
        $previousLeeway = FirebaseJWT::$leeway;
        FirebaseJWT::$leeway = $leeway;

        try {
            // 🔒 3. Key PINNED to RS256 — this is the alg-confusion / none defence.
            //    The library throws 'Incorrect key for this algorithm' if the
            //    token header alg ≠ RS256, and 'Algorithm not supported' for
            //    'none' (firebase/php-jwt has no 'none' handler at all).
            $decoded = FirebaseJWT::decode($jwt, new FirebaseKey($publicPem, self::ALG));
        } catch (\Firebase\JWT\ExpiredException $e) {
            throw new JwtException('Token expired', 0, $e);
        } catch (\Firebase\JWT\BeforeValidException $e) {
            throw new JwtException('Token not yet valid', 0, $e);
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            throw new JwtException('Signature invalid', 0, $e);
        } catch (\Throwable $e) {
            // UnexpectedValueException (alg mismatch/none/unknown-kid/bad-encoding), etc.
            throw new JwtException('Token verification failed', 0, $e);
        } finally {
            FirebaseJWT::$leeway = $previousLeeway;
        }

        // 📦 Normalise to an associative array.
        $claims = self::objectToArray($decoded);

        // 🧾 4. SIGNula claim policy.
        $expectedIss = isset($expect['iss'])
            ? (string) $expect['iss']
            : (string) getSetting('jwt.issuer', 'https://signula.id');
        if (!isset($claims['iss']) || !hash_equals($expectedIss, (string) $claims['iss'])) {
            throw new JwtException('Issuer (iss) mismatch');
        }

        $expectedAud = isset($expect['aud'])
            ? (string) $expect['aud']
            : (string) getSetting('jwt.audience', 'https://signula.id/api');
        if (!self::audienceContains($claims['aud'] ?? null, $expectedAud)) {
            throw new JwtException('Audience (aud) mismatch');
        }

        if (empty($claims['jti']) || !is_string($claims['jti'])) {
            throw new JwtException('Missing jti');
        }

        // 🚫 5. Revocation / denylist.
        if (self::$denylistChecker !== null) {
            $revoked = (bool) (self::$denylistChecker)($claims['jti']);
            if ($revoked) {
                throw new JwtException('Token revoked (jti denylisted)');
            }
        }

        return $claims;
    }

    // ========================================================================
    // 🔧 INTERNAL HELPERS
    // ========================================================================

    /**
     * 📦 Ensure the vendored library is loaded (throws if unavailable).
     *
     * @return void
     * @throws JwtException
     */
    private static function ensureLibrary(): void
    {
        if (!class_exists('Firebase\\JWT\\JWT', false)) {
            if (!class_exists('JwtLibLoader')) {
                throw new JwtException('JwtLibLoader not available');
            }
            try {
                JwtLibLoader::load();
            } catch (\Throwable $e) {
                throw new JwtException('JWT library failed to load', 0, $e);
            }
        }
    }

    /**
     * 🔎 Read the JWT header WITHOUT verifying anything.
     *
     * Used ONLY to select the key (kid) and inspect typ. Its output is never
     * trusted for the crypto decision — the actual verify re-parses everything
     * inside the library with the RS256-pinned Key.
     *
     * @param string $jwt Compact JWT.
     * @return array<string,mixed> Decoded header.
     * @throws JwtException On malformed structure.
     */
    private static function readHeaderUnverified(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new JwtException('Malformed token (segments)');
        }

        $json = KeyManager::base64UrlDecode($parts[0]);
        if ($json === '') {
            throw new JwtException('Malformed token (header encoding)');
        }

        $header = json_decode($json, true);
        if (!is_array($header)) {
            throw new JwtException('Malformed token (header json)');
        }

        return $header;
    }

    /**
     * 🎯 Does the token audience satisfy the expected audience?
     *
     * `aud` may be a string or an array of strings (RFC 7519 §4.1.3). Uses
     * constant-time compares.
     *
     * @param mixed  $aud      The token's aud claim.
     * @param string $expected The audience we require.
     * @return bool
     */
    private static function audienceContains(mixed $aud, string $expected): bool
    {
        if (is_string($aud)) {
            return hash_equals($expected, $aud);
        }
        if (is_array($aud)) {
            foreach ($aud as $candidate) {
                if (is_string($candidate) && hash_equals($expected, $candidate)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 🔄 Recursively convert stdClass (from the library) to associative arrays.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function objectToArray(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            return array_map([self::class, 'objectToArray'], $value);
        }
        return $value;
    }
}

// ✅ Jwt facade loaded successfully
