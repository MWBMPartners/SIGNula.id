<?php
/**
 * ============================================================================
 * 🎫 SIGNula - JWT Bearer-Auth API Controller (G-003 Stage 3)
 * ============================================================================
 *
 * The HTTP surface for the JWT bearer-token model built in G-003 Stages 1-2.
 * Where Jwt/KeyManager own the crypto and TokenService owns the token
 * lifecycle, THIS controller wires them to the public REST endpoints:
 *
 *   • POST /api/v1/auth/token   — issue an access + refresh pair. Issued ONLY
 *                                 after a PRIMARY credential is verified (a
 *                                 username/email+password via Auth::login(), OR
 *                                 an already-authenticated browser session).
 *   • POST /api/v1/auth/refresh — rotate: TokenService::refresh() (single-use +
 *                                 reuse-detection). Returns a new pair.
 *   • POST /api/v1/auth/revoke  — RFC 7009 revocation: denylist an access jti
 *                                 and/or revoke a refresh family. Idempotent 200.
 *
 * 🔒 THE key security property (G-003 §6 / task S3):
 *   The userID a token is minted for is NEVER taken from client input. It comes
 *   EXCLUSIVELY from a server-verified identity — the row Auth::login() matched
 *   for the supplied credential, or the current session's user id. A caller can
 *   NOT mint a token for an arbitrary userID. See issueToken() → resolveSubject().
 *
 * 🔒 Other guarantees:
 *   • Heavily rate-limited (per-IP AND per-identifier) on /token and /refresh to
 *     blunt credential stuffing / brute force (complements Auth's own lockout).
 *   • No internal detail leaked on failure: a verify/refresh failure maps to a
 *     generic 401; the JwtException message is logged server-side only.
 *   • Token responses carry Cache-Control: no-store (never cached by
 *     intermediaries) — G-003 §7.11.
 *   • Passwords / refresh tokens are never logged (redaction — G-003 §7.6).
 *
 * PHP Version: 8.3+ (developed/tested on 8.4). MySQLi + prepared statements via
 * the Database wrapper. All source files carry the SIGNULA_INIT guard.
 *
 * @package    SIGNula
 * @subpackage API\Controllers
 * @version    1.0.0
 * @since      2.8.0-beta (G-003 Stage 3)
 * @link       https://datatracker.ietf.org/doc/html/rfc9068 (JWT access tokens)
 * @link       https://datatracker.ietf.org/doc/html/rfc7009 (token revocation)
 * @see        .dev-team/specs/G-003.md §6
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
 * Class JwtAuthController
 *
 * JWT token issuance / refresh / revocation endpoints.
 */
class JwtAuthController extends BaseController
{
    /**
     * @var array<int,string> The scope vocabulary a FIRST-PARTY user credential
     *      may be granted. A caller may request a SUBSET of these; anything
     *      outside the allowlist is silently dropped (never widened). Kept in
     *      lock-step with the tblAPIKeys.permissions vocabulary so hasScope()
     *      works identically for JWT and API-key auth (G-003 §5).
     */
    private const DEFAULT_USER_SCOPES = ['user:read', 'user:write'];

    // ========================================================================
    // 🎁 POST /api/v1/auth/token — issue tokens
    // ========================================================================

    /**
     * 🎁 Issue an access + refresh token pair.
     *
     * Two accepted grants, in this precedence:
     *   1. An already-authenticated browser SESSION (Auth::getCurrentUserID()).
     *      The "SPA bootstraps a token from its cookie session" case. No body
     *      credential needed.
     *   2. A username/email + password in the request body, verified through
     *      Auth::login() (which enforces rate-limit, lockout, account-status and
     *      the MFA gate). The userID is the one Auth matched — NOT any body field.
     *
     * ⚠️ CRITICAL: the subject is resolved by resolveSubject(); NO userID/sub is
     * ever read from client input. On MFA-required we return an mfa_required
     * challenge and DO NOT issue tokens.
     *
     * @param array $params Route parameters (unused).
     * @return void (exits via Response)
     */
    public function issueToken(array $params): void
    {
        // 🚦 Rate-limit BEFORE any credential work (credential-stuffing defence).
        //    Keyed on IP and — when a body identifier is present — the identifier
        //    too, so a single IP cannot spray many accounts and a single account
        //    cannot be pounded from many IPs.
        $body       = $this->safeBody();
        $identifier = $this->readIdentifier($body);
        $this->enforceIssueRateLimit($identifier);

        // 🔐 Resolve the SUBJECT from a server-verified identity ONLY.
        //    Returns ['userID' => int] on success, or emits a Response (401/403/
        //    mfa_required) and exits on any failure. Never trusts client-supplied
        //    userID/sub.
        $subject = $this->resolveSubject($body, $identifier);
        $userID  = $subject['userID'];

        // 🎚️ Scope: honour a requested subset, but never widen beyond what a
        //    first-party user credential is allowed.
        $scope = $this->resolveRequestedScope($body);

        try {
            // 🎫 Mint the pair (new rotation family). TokenService owns the crypto
            //    + the hashed refresh-token persistence; plaintext refresh is
            //    returned here ONCE and never stored.
            $pair = TokenService::issueTokens($userID, $scope);
        } catch (Throwable $e) {
            // 🔇 Signing/persistence failure — log detail server-side, generic 500.
            $this->logErrorSafe($e);
            $this->error('Unable to issue token', Response::HTTP_INTERNAL_ERROR);
            return;
        }

        // 📝 Audit the successful issuance (NO token material in the log).
        $this->logActivitySafe(
            $userID,
            'jwt_token_issued',
            'auth',
            'info',
            'JWT access + refresh token issued via API',
            ['jti' => $pair['jti'], 'scope' => $pair['scope']]
        );

        // 🛡️ Tokens must never be cached by any intermediary (G-003 §7.11).
        $this->sendNoStore();

        // ✅ Standard OAuth-style token response.
        $this->success([
            'access_token'  => $pair['access_token'],
            'token_type'    => $pair['token_type'],   // 'Bearer'
            'expires_in'    => $pair['expires_in'],    // access TTL seconds
            'refresh_token' => $pair['refresh_token'], // opaque, single-use
            'scope'         => $pair['scope'],
        ], 'Token issued');
    }

    // ========================================================================
    // 🔄 POST /api/v1/auth/refresh — rotate
    // ========================================================================

    /**
     * 🔄 Exchange a refresh token for a new access + refresh pair.
     *
     * Delegates to TokenService::refresh() which performs single-use rotation +
     * automatic reuse-detection (replaying a spent/revoked token revokes the
     * whole family, raises a security alert, and denies). Any failure — unknown,
     * expired, or reused — maps to a generic 401 with NO reason leaked.
     *
     * @param array $params Route parameters (unused).
     * @return void (exits via Response)
     */
    public function refreshToken(array $params): void
    {
        // 🚦 Rate-limit refresh per-IP (a refresh endpoint is a lower-value but
        //    still abusable surface — guessing opaque tokens / hammering).
        $this->enforceRefreshRateLimit();

        $body         = $this->safeBody();
        $refreshToken = isset($body['refresh_token']) && is_string($body['refresh_token'])
            ? trim($body['refresh_token'])
            : '';

        if ($refreshToken === '') {
            // Shape error is safe to surface (no token-validity signal leaked).
            $this->error('refresh_token is required', Response::HTTP_BAD_REQUEST);
            return;
        }

        try {
            $pair = TokenService::refresh($refreshToken);
        } catch (JwtException $e) {
            // 🔇 Unknown / expired / reused — indistinguishable to the client.
            //    Detail (incl. reuse-detection) is logged inside TokenService.
            $this->logActivitySafe(
                null,
                'jwt_refresh_denied',
                'auth',
                'warning',
                'JWT refresh denied'
                // Deliberately NO token material and NO reason in the payload.
            );
            $this->unauthorized('Invalid refresh token');
            return;
        } catch (Throwable $e) {
            $this->logErrorSafe($e);
            $this->error('Unable to refresh token', Response::HTTP_INTERNAL_ERROR);
            return;
        }

        // 🛡️ No-store on the rotated token pair too.
        $this->sendNoStore();

        $this->success([
            'access_token'  => $pair['access_token'],
            'token_type'    => $pair['token_type'],
            'expires_in'    => $pair['expires_in'],
            'refresh_token' => $pair['refresh_token'],
            'scope'         => $pair['scope'],
        ], 'Token refreshed');
    }

    // ========================================================================
    // 🗑️ POST /api/v1/auth/revoke — logout / revoke (RFC 7009)
    // ========================================================================

    /**
     * 🗑️ Revoke a presented access jti and/or a refresh family.
     *
     * RFC 7009 shape: { "token", "token_type_hint": "access|refresh" }. Also
     * accepts the caller's own Bearer access token (Authorization header) so a
     * "log me out" call can denylist the presenting access token even with no
     * body. Revocation is IDEMPOTENT and ALWAYS returns 200 — it must not leak
     * whether the token was valid (RFC 7009 §2.2).
     *
     * Only tokens the caller actually PRESENTS are revoked (their own refresh
     * family / their own access jti) — there is no cross-user revoke here.
     *
     * @param array $params Route parameters (unused).
     * @return void (exits via Response)
     */
    public function revokeToken(array $params): void
    {
        $body = $this->safeBody();

        $token = isset($body['token']) && is_string($body['token']) ? trim($body['token']) : '';
        $hint  = isset($body['token_type_hint']) && is_string($body['token_type_hint'])
            ? strtolower(trim($body['token_type_hint']))
            : '';

        // 🎫 If no body token, fall back to the caller's own Bearer access token.
        $bearer = $this->router->getBearerToken();

        try {
            // 1️⃣ Explicit refresh-token revoke (hint = refresh, or looks opaque).
            if ($token !== '' && ($hint === 'refresh' || $this->looksLikeOpaqueRefresh($token))) {
                $this->revokeRefreshByPresentedToken($token);
            }

            // 2️⃣ Explicit access-token revoke (hint = access, or looks like a JWT).
            if ($token !== '' && ($hint === 'access' || $this->looksLikeJwt($token))) {
                $this->revokeAccessByPresentedToken($token);
            }

            // 3️⃣ No body token → revoke the caller's own presented Bearer access token.
            if ($token === '' && $bearer !== null && $this->looksLikeJwt($bearer)) {
                $this->revokeAccessByPresentedToken($bearer);
            }
        } catch (Throwable $e) {
            // 🔇 Never leak — but also never fail a revoke on a best-effort error.
            $this->logErrorSafe($e);
        }

        // ✅ RFC 7009: always 200, no validity signal.
        $this->sendNoStore();
        $this->success(null, 'Token revoked');
    }

    // ========================================================================
    // 🔐 SUBJECT RESOLUTION — the primary-credential authz gate
    // ========================================================================

    /**
     * 🔐 Resolve the token SUBJECT (userID) from a SERVER-VERIFIED identity only.
     *
     * Precedence:
     *   1. An active browser session (Auth::getCurrentUserID()). SPA bootstrap.
     *   2. email/username + password verified via Auth::login(). On MFA-required,
     *      emit an mfa_required challenge and DO NOT issue (returns nothing — the
     *      Response has already exited). On any credential failure, Auth returns
     *      a generic failure which we map to 401.
     *
     * 🔒 The returned userID is ALWAYS the one the server matched/authenticated.
     * There is NO path by which a client-supplied userID/sub reaches token
     * issuance — that is the core anti-forgery property of G-003 §6.
     *
     * @param array<string,mixed> $body       Parsed request body.
     * @param string              $identifier Sanitised login identifier ('' if none).
     * @return array{userID:int}  On success. On failure, emits a Response and exits.
     */
    private function resolveSubject(array $body, string $identifier): array
    {
        // 1️⃣ Session grant — an already-authenticated user bootstrapping a token.
        if (class_exists('Auth') && Auth::isAuthenticated()) {
            $sessionUserID = Auth::getCurrentUserID();
            if ($sessionUserID !== null && $sessionUserID > 0) {
                return ['userID' => (int) $sessionUserID];
            }
        }

        // 2️⃣ Password grant — requires BOTH an identifier and a password.
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
        if ($identifier === '' || $password === '') {
            // No session and no usable credential → unauthenticated.
            $this->unauthorized('Authentication required');
            // (Response::unauthorized exits; the return below is for static analysis.)
            return ['userID' => 0];
        }

        // 🔑 Verify the primary credential. Auth::login() does the DB lookup,
        //    password verify, lockout, account-status and MFA gating. The userID
        //    it returns is the row it matched — never any client field.
        $result = Auth::login($identifier, $password, false);

        // 🔐 MFA GATE: a correct password with MFA enabled is NOT sufficient to
        //    mint tokens. Return an mfa_required challenge; the client must
        //    complete MFA (existing MFA flow) before requesting a token again.
        if (($result['requiresMFA'] ?? false) === true) {
            $this->sendNoStore();
            // 202-ish semantics via a success=false-ish challenge; we use a 401 so
            // an unauthenticated client treats it as "not yet authorised", with a
            // machine-readable flag so a smart client can drive the MFA step.
            $this->error(
                'MFA verification required',
                Response::HTTP_UNAUTHORIZED,
                ['mfa_required' => true]
            );
            return ['userID' => 0];
        }

        // ❌ Any non-success (bad password, locked, suspended, rate-limited) →
        //    generic 401. Auth has already logged the specific reason.
        if (($result['success'] ?? false) !== true || empty($result['userID'])) {
            $this->unauthorized('Invalid credentials');
            return ['userID' => 0];
        }

        return ['userID' => (int) $result['userID']];
    }

    // ========================================================================
    // 🎚️ SCOPE
    // ========================================================================

    /**
     * 🎚️ Resolve the granted scope as a SUBSET of what this credential may hold.
     *
     * A caller may request scopes (body `scope`, space-delimited, or a JSON
     * array). We intersect the request with DEFAULT_USER_SCOPES and NEVER widen
     * beyond it. If nothing valid was requested, we grant the full default set
     * (the historical, least-surprising behaviour for a first-party login).
     *
     * @param array<string,mixed> $body Parsed request body.
     * @return array<int,string>  The granted scope subset.
     */
    private function resolveRequestedScope(array $body): array
    {
        $requested = $body['scope'] ?? null;

        // Normalise to an array of scope strings.
        if (is_string($requested)) {
            $requested = $requested === '' ? [] : preg_split('/\s+/', trim($requested));
        }
        if (!is_array($requested) || $requested === []) {
            // Nothing requested → grant the default set.
            return self::DEFAULT_USER_SCOPES;
        }

        // Intersect (allowlist) — silently drop anything outside the vocabulary.
        $granted = [];
        foreach ($requested as $s) {
            $s = is_string($s) ? trim($s) : '';
            if ($s !== '' && in_array($s, self::DEFAULT_USER_SCOPES, true) && !in_array($s, $granted, true)) {
                $granted[] = $s;
            }
        }

        // If the caller requested only invalid scopes, fall back to the default
        // (do not issue a zero-scope token that would surprise the integrator).
        return $granted === [] ? self::DEFAULT_USER_SCOPES : $granted;
    }

    // ========================================================================
    // 🚦 RATE LIMITING
    // ========================================================================

    /**
     * 🚦 Rate-limit the token endpoint by IP and (if present) identifier.
     *
     * Uses SecurityUtils::checkRateLimit (the same limiter Auth::login() uses),
     * so the two layers compound rather than conflict. Emits 429 + Retry-After
     * on breach.
     *
     * @param string $identifier Sanitised login identifier ('' if none).
     * @return void
     */
    private function enforceIssueRateLimit(string $identifier): void
    {
        $ip          = getClientIP();
        $maxPerIp    = (int) getSetting('jwt.rate_limit.token_per_ip', 30);
        $maxPerId    = (int) getSetting('jwt.rate_limit.token_per_identifier', 10);
        $windowSecs  = (int) getSetting('jwt.rate_limit.window_seconds', 900); // 15 min

        // Per-IP.
        if (!SecurityUtils::checkRateLimit($ip, 'jwt_token', $maxPerIp, $windowSecs)) {
            $this->rateLimited($windowSecs);
            return;
        }

        // Per-identifier (only when the caller supplied one).
        if ($identifier !== ''
            && !SecurityUtils::checkRateLimit('id:' . strtolower($identifier), 'jwt_token', $maxPerId, $windowSecs)) {
            $this->rateLimited($windowSecs);
            return;
        }
    }

    /**
     * 🚦 Rate-limit the refresh endpoint by IP.
     *
     * @return void
     */
    private function enforceRefreshRateLimit(): void
    {
        $ip         = getClientIP();
        $maxPerIp   = (int) getSetting('jwt.rate_limit.refresh_per_ip', 60);
        $windowSecs = (int) getSetting('jwt.rate_limit.window_seconds', 900);

        if (!SecurityUtils::checkRateLimit($ip, 'jwt_refresh', $maxPerIp, $windowSecs)) {
            $this->rateLimited($windowSecs);
        }
    }

    /**
     * ⏱️ Emit a 429 with Retry-After and exit.
     *
     * @param int $retryAfter Seconds hint.
     * @return void
     */
    private function rateLimited(int $retryAfter): void
    {
        Response::rateLimitExceeded('Too many token requests. Please try again later.', $retryAfter);
    }

    // ========================================================================
    // 🗑️ REVOCATION HELPERS
    // ========================================================================

    /**
     * 🗑️ Revoke the family of a PRESENTED opaque refresh token.
     *
     * Looks the token up by hash; if found, revokes its whole family. A miss is
     * a silent no-op (idempotent, no validity signal).
     *
     * @param string $refreshToken The opaque plaintext presented by the caller.
     * @return void
     */
    private function revokeRefreshByPresentedToken(string $refreshToken): void
    {
        $hash = hash('sha256', $refreshToken);
        $row  = Database::fetchOne(
            "SELECT familyID, userID FROM tblRefreshTokens WHERE tokenHash = ? LIMIT 1",
            [$hash],
            's'
        );
        if ($row === null) {
            return; // unknown → no-op
        }

        TokenService::revokeFamily((string) $row['familyID'], TokenService::REASON_LOGOUT);

        $this->logActivitySafe(
            isset($row['userID']) ? (int) $row['userID'] : null,
            'jwt_refresh_revoked',
            'auth',
            'info',
            'Refresh-token family revoked via /auth/revoke'
        );
    }

    /**
     * 🗑️ Revoke a PRESENTED access token by verifying it and denylisting its jti.
     *
     * We verify the token (so we only ever denylist a token whose jti/exp we can
     * trust and whose owner we know) and add the jti to the self-expiring
     * denylist with the token's real exp. A token that fails verification is a
     * silent no-op (idempotent, no signal).
     *
     * @param string $accessToken The Bearer access token presented by the caller.
     * @return void
     */
    private function revokeAccessByPresentedToken(string $accessToken): void
    {
        try {
            // Verify to obtain a TRUSTED jti/exp/sub. (Already-expired/forged →
            // JwtException → no-op below.)
            $claims = TokenService::verifyAccessToken($accessToken);
        } catch (Throwable $e) {
            return; // not a valid token we own → nothing to revoke
        }

        $jti = isset($claims['jti']) && is_string($claims['jti']) ? $claims['jti'] : '';
        if ($jti === '') {
            return;
        }
        $exp    = isset($claims['exp']) ? (int) $claims['exp'] : null;
        $userID = isset($claims['sub']) ? (int) $claims['sub'] : null;

        TokenService::revokeAccessJti($jti, $userID, $exp, TokenService::REASON_LOGOUT);

        $this->logActivitySafe(
            $userID,
            'jwt_access_revoked',
            'auth',
            'info',
            'Access-token jti denylisted via /auth/revoke',
            ['jti' => $jti]
        );
    }

    // ========================================================================
    // 🔧 INTERNAL HELPERS
    // ========================================================================

    /**
     * 📥 Parse the request body defensively (always an array).
     *
     * The Router emits a 400 on malformed JSON before we get here; this just
     * coerces a non-array (e.g. a raw string body) to [].
     *
     * @return array<string,mixed>
     */
    private function safeBody(): array
    {
        $body = $this->input();
        return is_array($body) ? $body : [];
    }

    /**
     * 🆔 Read + sanitise the login identifier (email or username) from the body.
     *
     * Accepts `email` (preferred) or `username`. Returns '' when neither present.
     *
     * @param array<string,mixed> $body Parsed body.
     * @return string Sanitised identifier ('' if none).
     */
    private function readIdentifier(array $body): string
    {
        $raw = '';
        if (isset($body['email']) && is_string($body['email']) && trim($body['email']) !== '') {
            $raw = $body['email'];
        } elseif (isset($body['username']) && is_string($body['username'])) {
            $raw = $body['username'];
        }

        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // Reuse the project's sanitiser (strip tags / encode) — the exact same
        // treatment Auth::login() applies internally to the identifier.
        return SecurityUtils::sanitizeString($raw);
    }

    /**
     * 🧪 Does a string look like a compact JWT (three dot-separated segments)?
     *
     * @param string $token
     * @return bool
     */
    private function looksLikeJwt(string $token): bool
    {
        return substr_count($token, '.') === 2;
    }

    /**
     * 🧪 Does a string look like an opaque refresh token (64 lowercase hex)?
     *
     * @param string $token
     * @return bool
     */
    private function looksLikeOpaqueRefresh(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $token);
    }

    /**
     * 🛡️ Emit Cache-Control: no-store (+ nosniff) so tokens are never cached.
     *
     * Response::send() also sets nosniff; we set no-store here BEFORE it runs
     * (headers are additive and flushed together at send()).
     *
     * @return void
     */
    private function sendNoStore(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store');
            header('Pragma: no-cache');
        }
    }

    /**
     * 📝 Best-effort activity log (never throws into the request path).
     *
     * @param int|null $userID
     * @param string   $type
     * @param string   $category
     * @param string   $severity
     * @param string   $description
     * @param array<string,mixed> $metadata
     * @return void
     */
    private function logActivitySafe(
        ?int $userID,
        string $type,
        string $category,
        string $severity,
        string $description,
        array $metadata = []
    ): void {
        if (!class_exists('ActivityLogger')) {
            return;
        }
        try {
            ActivityLogger::log($userID, $type, $category, $severity, $description, $metadata);
        } catch (Throwable $e) {
            // Swallow — logging must never break auth.
            error_log('JwtAuthController: activity log failed: ' . $e->getMessage());
        }
    }

    /**
     * 🪵 Best-effort error log (never throws; never logs token material).
     *
     * @param Throwable $e
     * @return void
     */
    private function logErrorSafe(Throwable $e): void
    {
        if (class_exists('ErrorLogger')) {
            try {
                ErrorLogger::log($e);
                return;
            } catch (Throwable $ignored) {
                // fall through to error_log
            }
        }
        error_log('JwtAuthController error: ' . $e->getMessage());
    }
}

// ✅ JwtAuthController loaded successfully
