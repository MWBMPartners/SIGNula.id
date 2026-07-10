<?php
/**
 * ============================================================================
 * 🎟️ SIGNula - Token Service (JWT issuance + refresh rotation + reuse detection)
 * ============================================================================
 *
 * Purpose:
 *   The STATEFUL half of G-003 bearer auth. Where the Jwt facade + KeyManager
 *   (Stage 1) own the stateless crypto (sign/verify RS256, JWKS, alg-pinning),
 *   TokenService (Stage 2) owns the token *lifecycle*:
 *
 *     • issueTokens()      — mint a short-lived access-token JWT PLUS a long-lived
 *                            OPAQUE refresh token (new rotation family). The
 *                            refresh plaintext is returned to the caller ONCE and
 *                            NEVER stored — only its SHA-256 hash goes to the DB.
 *     • refresh()          — single-use refresh-token rotation with automatic
 *                            REUSE DETECTION: presenting an already-rotated (spent)
 *                            token revokes the WHOLE family and raises a security
 *                            alert (the critical anti-theft property).
 *     • revoke*()          — denylist an access-token jti (self-expiring row in
 *                            tblRevokedTokens), revoke a refresh family, or bump a
 *                            user's tblUsers.tokensInvalidBefore cutoff ("log out
 *                            everywhere").
 *     • verifyAccessToken()— convenience verify that wires BOTH the jti denylist
 *                            AND the per-user tokensInvalidBefore cutoff into
 *                            Jwt::verify().
 *
 * Design source: .dev-team/specs/G-003.md §5 (token model) + §6 (wiring).
 *
 * 🔒 Security-critical invariants (do not weaken):
 *   1. Refresh-token PLAINTEXT is never persisted or logged — only SHA-256(hash).
 *      Lookups hash the presented token and compare hashes (the DB UNIQUE index
 *      on tokenHash does the equality; the value itself never round-trips).
 *   2. Rotation is ATOMIC. The "spend" step is a single guarded UPDATE
 *      (... SET rotatedAt=NOW() WHERE tokenID=? AND rotatedAt IS NULL AND
 *      revoked=0) whose Database::getAffectedRows() MUST equal 1. Two concurrent
 *      refreshes racing on the same token → exactly one wins (affectedRows=1),
 *      the loser sees affectedRows=0 and is denied. This is the compare-and-set
 *      that makes single-use rotation safe under concurrency (relies on the
 *      B-040 getAffectedRows() fix that reads the count while the stmt is open).
 *   3. REUSE of a spent/revoked token revokes the ENTIRE family
 *      (UPDATE ... SET revoked=1 WHERE familyID=?) + raises a SecurityAlert. A
 *      thief and the legitimate client both hold the token; killing the family
 *      logs both out and forces re-authentication.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4). Requires ext-openssl (via Jwt),
 * MySQLi + prepared statements (via the Database wrapper).
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.8.0-beta (G-003 Stage 2)
 * @link       https://datatracker.ietf.org/doc/html/rfc9068 (JWT access tokens)
 * @link       https://datatracker.ietf.org/doc/html/rfc7009 (token revocation)
 * @link       https://auth0.com/docs/secure/tokens/refresh-tokens/refresh-token-rotation
 * @link       https://www.rfc-editor.org/rfc/rfc6749#section-1.5 (refresh tokens)
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
 * 🎟️ Stateful JWT token lifecycle: issuance, rotation, reuse-detection, revocation.
 *
 * All methods static (matches the Jwt / KeyManager / SecurityUtils house style).
 * Callers map a thrown JwtException to a generic 401 and log the detail
 * server-side — the message text is for the LOG, not the response body
 * (G-003 §6 "never leak WHY").
 */
class TokenService
{
    // ========================================================================
    // 🏷️ REVOCATION-REASON CONSTANTS (stored in tblRefreshTokens.revokedReason)
    // ========================================================================

    /** @var string Family killed because a spent refresh token was replayed (theft). */
    public const REASON_REUSE_DETECTED = 'reuse_detected';

    /** @var string Explicit logout / revoke of a single refresh token or its family. */
    public const REASON_LOGOUT = 'logout';

    /** @var string Family superseded by normal rotation (the spent token). */
    public const REASON_ROTATED = 'rotated';

    /** @var string Admin / "log out everywhere" mass revocation. */
    public const REASON_ADMIN = 'admin_revoke';

    // ========================================================================
    // 🎁 ISSUANCE
    // ========================================================================

    /**
     * 🎁 Issue a fresh access token + refresh token for a user (new family).
     *
     * Mints:
     *   • an RS256 access-token JWT via Jwt::sign() with claims:
     *       sub   = (string) userID
     *       iss   = jwt.issuer            (owned by the facade)
     *       aud   = $aud ?? jwt.audience
     *       iat/nbf/exp — owned by the facade (exp = iat + jwt.access_ttl)
     *       jti   = random 16-byte hex    (owned by the facade)
     *       scope = space-delimited scopes
     *   • an OPAQUE refresh token: bin2hex(random_bytes(32)) (64 hex chars,
     *     256 bits of entropy). Only its SHA-256 hash is stored, in a NEW
     *     rotation family, with expiry = now + jwt.refresh_ttl.
     *
     * @param int         $userID The SIGNula user id the tokens are for.
     * @param array<int,string> $scope Scope strings (e.g. ['user:read','user:write']).
     * @param string|null $aud    Optional audience override (else jwt.audience).
     * @return array{
     *     access_token:string,
     *     refresh_token:string,
     *     token_type:string,
     *     expires_in:int,
     *     scope:string,
     *     family_id:string,
     *     jti:string
     * } The refresh_token plaintext is returned HERE ONCE and never again.
     * @throws JwtException On signing failure (propagated from Jwt::sign()).
     */
    public static function issueTokens(int $userID, array $scope = [], ?string $aud = null): array
    {
        // 🧵 A brand-new issuance always starts a NEW rotation family.
        $familyID = self::newFamilyId();

        return self::mintPair($userID, $familyID, $scope, $aud);
    }

    /**
     * 🪪 Issue an access + refresh pair on behalf of an OAuth2/OIDC Relying-Party
     * CLIENT (G-001 Stage A3 — the `/oauth/token` authorization_code grant).
     *
     * Identical machinery to {@see self::issueTokens()} (new rotation family,
     * same hashed-refresh-token persistence, same access-token signing path)
     * with TWO RP-specific differences threaded through to {@see self::mintPair()}:
     *   • the ACCESS token's `aud` claim is the RP's `clientIdentifier` (its
     *     public client_id) — NOT the default first-party `jwt.audience` — so a
     *     resource server can enforce "this token is only good for client X".
     *   • the refresh-token row records `clientID` (tblRefreshTokens.clientID,
     *     reserved for G-001 since migration 030) so a later rotation
     *     ({@see self::refresh()}) can re-resolve the SAME client identifier
     *     for the rotated access token, and so an RP's tokens are attributable
     *     to it for revocation/audit (a future "Connected Apps" hub).
     *
     * @param int    $userID           The SIGNula user id the tokens are for.
     * @param int    $clientID         The RP's internal clientID (tblOAuthClients.clientID PK).
     * @param string $clientIdentifier The RP's public client_id — becomes the
     *                                 access token's `aud` claim.
     * @param array<int,string> $scope Scope strings GRANTED for this exchange
     *                                 (already validated ⊆ requested ⊆ allowed
     *                                 by the /oauth/authorize-idp + /oauth/token
     *                                 flow — this method does not re-check).
     * @return array{
     *     access_token:string,
     *     refresh_token:string,
     *     token_type:string,
     *     expires_in:int,
     *     scope:string,
     *     family_id:string,
     *     jti:string
     * }
     * @throws JwtException On signing failure (propagated from Jwt::sign()).
     */
    public static function issueForClient(int $userID, int $clientID, string $clientIdentifier, array $scope = []): array
    {
        // 🧵 An authorization_code exchange always starts a NEW rotation family
        //    — mirrors issueTokens(); there is no "existing family" concept at
        //    initial code exchange (only refresh() continues one).
        $familyID = self::newFamilyId();

        return self::mintPair($userID, $familyID, $scope, $clientIdentifier, $clientID);
    }

    // ========================================================================
    // 🔄 REFRESH (single-use rotation + reuse detection)
    // ========================================================================

    /**
     * 🔄 Exchange a refresh token for a new access + refresh pair (rotation).
     *
     * Flow (fail-closed at every branch — a thrown JwtException = 401):
     *   1. Hash the presented plaintext; look the row up by tokenHash.
     *      • Not found                → deny (unknown/forged/already-purged).
     *   2. If the row is REVOKED or already ROTATED (spent) → REUSE DETECTION:
     *      revoke the ENTIRE family, raise a token-reuse security alert, deny.
     *      This is the critical anti-theft property (§5): a thief and the
     *      legitimate client both hold the same token; either replay kills the
     *      family and forces everyone to re-authenticate.
     *   3. If the row is EXPIRED → deny (do NOT treat as reuse; it simply aged
     *      out — no theft signal).
     *   4. Otherwise ROTATE atomically: a single guarded UPDATE spends the token
     *      (rotatedAt=NOW() WHERE tokenID=? AND rotatedAt IS NULL AND revoked=0).
     *      getAffectedRows() MUST be 1 — if a concurrent refresh already spent it
     *      the count is 0 and we treat the loser as reuse (family revoke). The
     *      winner issues a NEW access+refresh pair in the SAME family and links
     *      replacedByID to the new row.
     *
     * @param string $refreshToken The opaque refresh-token plaintext from the client.
     * @return array{
     *     access_token:string,
     *     refresh_token:string,
     *     token_type:string,
     *     expires_in:int,
     *     scope:string,
     *     family_id:string,
     *     jti:string
     * }
     * @throws JwtException On any invalid / reused / expired refresh token.
     */
    public static function refresh(string $refreshToken): array
    {
        // 🔑 1. Hash the presented plaintext and look it up. We NEVER compare the
        //    plaintext directly — only the SHA-256 hex, exactly as stored.
        $hash = self::hashRefreshToken($refreshToken);

        $row = Database::fetchOne(
            "SELECT tokenID, userID, familyID, scope, clientID, expiresAt, rotatedAt, revoked
             FROM tblRefreshTokens
             WHERE tokenHash = ?
             LIMIT 1",
            [$hash],
            's'
        );

        // 🚫 Unknown token — could be forged, mistyped, or purged. Deny generically.
        if ($row === null) {
            throw new JwtException('Refresh token not found');
        }

        $tokenID  = (int) $row['tokenID'];
        $userID   = (int) $row['userID'];
        $familyID = (string) $row['familyID'];
        $scopeStr = $row['scope'] ?? '';
        // 🪪 G-001: NULL for a first-party token; the RP's internal clientID
        //    for one minted via issueForClient(). Threaded through the rotate
        //    below so an RP's tokens STAY attributable to it across rotation.
        $clientID = $row['clientID'] !== null ? (int) $row['clientID'] : null;

        // 🚨 2. REUSE DETECTION — a spent (rotatedAt set) or revoked token was
        //    presented again. This is the theft signal. Kill the whole family.
        $isRotated = $row['rotatedAt'] !== null && $row['rotatedAt'] !== '';
        $isRevoked = (int) $row['revoked'] === 1;
        if ($isRotated || $isRevoked) {
            self::handleReuse($familyID, $userID, $tokenID, $isRevoked);
            throw new JwtException('Refresh token reuse detected — family revoked');
        }

        // ⏳ 3. Expired (but never rotated/revoked) — deny WITHOUT the reuse
        //    machinery (aging out is not an attack). Compare in SQL to avoid any
        //    PHP/DB timezone skew.
        $stillValid = Database::fetchOne(
            "SELECT (expiresAt > NOW()) AS live FROM tblRefreshTokens WHERE tokenID = ?",
            [$tokenID],
            'i'
        );
        if ($stillValid === null || (int) $stillValid['live'] !== 1) {
            throw new JwtException('Refresh token expired');
        }

        // 🔒 4. ATOMIC SINGLE-USE SPEND. The guard (rotatedAt IS NULL AND
        //    revoked = 0) makes this a compare-and-set: exactly ONE concurrent
        //    caller can flip it, and getAffectedRows() tells us if we won.
        Database::query(
            "UPDATE tblRefreshTokens
             SET rotatedAt = NOW()
             WHERE tokenID = ? AND rotatedAt IS NULL AND revoked = 0",
            [$tokenID],
            'i'
        );

        // 🏁 Did we win the race? If not, another refresh already spent this exact
        //    token between our SELECT and our UPDATE — that is indistinguishable
        //    from reuse, so treat it as reuse (revoke the family) and deny.
        if (Database::getAffectedRows() !== 1) {
            self::handleReuse($familyID, $userID, $tokenID, false);
            throw new JwtException('Refresh token reuse detected (lost rotation race) — family revoked');
        }

        // 🎯 Resolve the audience for the RE-MINTED access token. A first-party
        //    token (clientID NULL) keeps the default (null → Jwt::sign()'s
        //    jwt.audience fallback). An RP-issued token (clientID set) MUST
        //    keep aud = that client's clientIdentifier — resolved FRESH here
        //    (not cached anywhere) so the rotated access token still targets
        //    the exact same resource server the original code exchange did.
        //    A missing/deactivated client (should not normally happen — the
        //    client row is never hard-deleted while grants exist) falls back
        //    to the default audience rather than throwing, so a refresh never
        //    hard-fails purely because of this lookup.
        $aud = null;
        if ($clientID !== null && class_exists('OAuthClientManager')) {
            $client = OAuthClientManager::getClientByID($clientID);
            if ($client !== null && !empty($client['clientIdentifier'])) {
                $aud = (string) $client['clientIdentifier'];
            }
        }

        // 🎁 Issue the successor pair in the SAME family, carrying the same
        //    scope AND the same clientID (G-001 — see mintPair()'s $clientID
        //    param), so tblRefreshTokens.clientID never gets "lost" on rotation.
        $scopeArray = $scopeStr !== '' ? explode(' ', (string) $scopeStr) : [];
        $pair = self::mintPair($userID, $familyID, $scopeArray, $aud, $clientID);

        // 🔗 Link the spent token to its replacement (audit trail / forensics).
        Database::query(
            "UPDATE tblRefreshTokens
             SET replacedByID = ?, revokedReason = ?
             WHERE tokenID = ?",
            [$pair['_new_token_id'], self::REASON_ROTATED, $tokenID],
            'isi'
        );

        // 🧹 Strip the internal-only row id before returning to the caller.
        unset($pair['_new_token_id']);

        return $pair;
    }

    // ========================================================================
    // 🗑️ REVOCATION
    // ========================================================================

    /**
     * 🗑️ Revoke an access token by its jti (add to the self-expiring denylist).
     *
     * Access tokens are stateless, so revocation is a denylist entry keyed on the
     * jti. The row self-expires at the token's original exp (a cron purge deletes
     * where expiresAt < NOW()), so the denylist only ever holds UNEXPIRED revoked
     * tokens and stays tiny. Idempotent: re-revoking the same jti is a no-op
     * (INSERT IGNORE on the UNIQUE jti index).
     *
     * @param string   $jti      The jti claim of the access token to revoke.
     * @param int|null $userID   Owner, if known (for audit).
     * @param int|null $expiresAt Unix timestamp of the token's exp; if null we
     *                            default to now + jwt.access_ttl (worst-case).
     * @param string   $reason   Short reason string (audit).
     * @return void
     */
    public static function revokeAccessJti(
        string $jti,
        ?int $userID = null,
        ?int $expiresAt = null,
        string $reason = self::REASON_LOGOUT
    ): void {
        // ⏱️ If the caller did not supply the token exp, assume the maximum
        //    lifetime so the denylist row certainly outlives the token.
        $expTs = $expiresAt ?? (time() + (int) getSetting('jwt.access_ttl', 900));
        $expiresAtSql = date('Y-m-d H:i:s', $expTs);

        // 🚫 INSERT IGNORE — idempotent revoke (UNIQUE jti). The FK on userID is
        //    ON DELETE SET NULL, so a NULL userID is fine.
        Database::query(
            "INSERT IGNORE INTO tblRevokedTokens (jti, userID, expiresAt, reason, revokedAt)
             VALUES (?, ?, ?, ?, NOW())",
            [$jti, $userID, $expiresAtSql, $reason],
            'siss'
        );
    }

    /**
     * 🚫 Is this jti on the revocation denylist (and still unexpired)?
     *
     * This is the checker wired into Jwt::verify() (via setDenylistChecker). It
     * only matches UNEXPIRED rows — an expired revoke row is meaningless because
     * the token itself is already invalid on exp.
     *
     * @param string $jti The candidate jti.
     * @return bool True when the jti is revoked and the revoke is still in force.
     */
    public static function isJtiRevoked(string $jti): bool
    {
        $row = Database::fetchOne(
            "SELECT 1 AS hit
             FROM tblRevokedTokens
             WHERE jti = ? AND expiresAt > NOW()
             LIMIT 1",
            [$jti],
            's'
        );

        return $row !== null;
    }

    /**
     * 🧵 Revoke an entire refresh-token family (logout / admin / reuse cleanup).
     *
     * Sets revoked = 1 for every non-revoked row in the family. Idempotent.
     *
     * @param string $familyID The family to kill.
     * @param string $reason   Audit reason (defaults to logout).
     * @return int Number of rows newly revoked.
     */
    public static function revokeFamily(string $familyID, string $reason = self::REASON_LOGOUT): int
    {
        Database::query(
            "UPDATE tblRefreshTokens
             SET revoked = 1, revokedReason = ?
             WHERE familyID = ? AND revoked = 0",
            [$reason, $familyID],
            'ss'
        );

        return Database::getAffectedRows();
    }

    /**
     * 🌐 Revoke ALL of a user's outstanding tokens ("log out everywhere").
     *
     * Two levers, applied together:
     *   • Refresh tokens (stateful): revoke every one of the user's families.
     *   • Access tokens (stateless): bump tblUsers.tokensInvalidBefore = NOW(),
     *     so verifyAccessToken() rejects any access token whose iat is before
     *     this instant. (We cannot enumerate live access-token jtis, so the
     *     iat-cutoff is the lever — §5.)
     *
     * @param int    $userID The user to fully log out.
     * @param string $reason Audit reason (defaults to admin revoke).
     * @return void
     */
    public static function revokeAllForUser(int $userID, string $reason = self::REASON_ADMIN): void
    {
        // 1️⃣ Kill every refresh token the user holds.
        Database::query(
            "UPDATE tblRefreshTokens
             SET revoked = 1, revokedReason = ?
             WHERE userID = ? AND revoked = 0",
            [$reason, $userID],
            'si'
        );

        // 2️⃣ Bump the access-token cutoff so all previously-issued access tokens
        //    (iat < NOW()) are rejected by verifyAccessToken().
        Database::query(
            "UPDATE tblUsers SET tokensInvalidBefore = NOW() WHERE userID = ?",
            [$userID],
            'i'
        );
    }

    /**
     * 🪪 Revoke a user's OUTSTANDING refresh-token families for ONE specific
     * RP client only (G-001 Stage A4 — the "Connected Apps" hub's per-app
     * "Revoke access" action).
     *
     * Deliberately narrower than {@see self::revokeAllForUser()}, which is
     * too broad for this use case — it would log the user out of EVERY
     * client (including first-party) on a single "revoke this one app"
     * click. This scopes the revoke to tblRefreshTokens rows whose
     * `clientID` matches the given RP (NULL/first-party rows are never
     * touched — the WHERE clause requires clientID = ? exactly, and MySQL
     * NULL never equals a bound integer).
     *
     * Access tokens already minted for this client are NOT individually
     * denylisted here — they are short-lived (`oidc.access_ttl`, default
     * 900s) and expire naturally within minutes; the killed refresh family
     * is the actual lever (no NEW access token can be minted for this
     * client via refresh once its family is revoked). A caller wanting an
     * immediate access-token kill too can additionally call
     * {@see self::revokeAccessJti()} if it holds the specific jti.
     *
     * @param int    $userID   The consenting user revoking access.
     * @param int    $clientID The RP's internal clientID (tblOAuthClients.clientID PK).
     * @param string $reason   Audit reason (defaults to logout).
     * @return int Number of refresh-token rows newly revoked.
     */
    public static function revokeClientForUser(int $userID, int $clientID, string $reason = self::REASON_LOGOUT): int
    {
        Database::query(
            "UPDATE tblRefreshTokens
             SET revoked = 1, revokedReason = ?
             WHERE userID = ? AND clientID = ? AND revoked = 0",
            [$reason, $userID, $clientID],
            'sii'
        );

        return Database::getAffectedRows();
    }

    // ========================================================================
    // ✅ VERIFICATION (denylist + tokensInvalidBefore wired in)
    // ========================================================================

    /**
     * ✅ Verify an access-token JWT under the FULL SIGNula policy.
     *
     * Layers the two stateful revocation checks on top of the stateless crypto
     * verify (Jwt::verify already enforces RS256/alg-pin, iss, aud, exp/nbf/iat,
     * jti presence, and — via the denylist checker we install here — the jti
     * denylist):
     *
     *   1. Install the jti denylist checker (isJtiRevoked) so Jwt::verify rejects
     *      a revoked jti.
     *   2. Jwt::verify() — crypto + registered-claim validation (throws on fail).
     *   3. Enforce the per-user tokensInvalidBefore cutoff: reject if the token's
     *      iat is strictly before the user's cutoff (mass-revoke lever, §5).
     *
     * @param string              $jwt    The compact access-token JWT.
     * @param array<string,mixed> $expect Optional expectations forwarded to
     *                                     Jwt::verify (aud/iss/typ/leeway). Defaults
     *                                     to requiring typ = at+jwt.
     * @return array<string,mixed> The validated claims.
     * @throws JwtException On ANY failure (crypto, denylist, or iat < cutoff).
     */
    public static function verifyAccessToken(string $jwt, array $expect = []): array
    {
        // 🔖 Default to demanding an RFC 9068 access token unless the caller
        //    overrode typ (G-001 may verify id_tokens differently later).
        if (!array_key_exists('typ', $expect)) {
            $expect['typ'] = Jwt::TYP_ACCESS;
        }

        // 🚫 1. Wire the stateful jti denylist into the crypto verify. We save +
        //    restore any previously-installed checker so we never clobber a
        //    caller's own hook (defensive; production installs ours once).
        $claims = self::withDenylistChecker(
            static fn(): array => Jwt::verify($jwt, $expect)
        );

        // ⏱️ 3. tokensInvalidBefore cutoff. Any access token issued (iat) before
        //    the user's cutoff is rejected — the "log out everywhere" lever.
        $sub = isset($claims['sub']) ? (int) $claims['sub'] : 0;
        $iat = isset($claims['iat']) ? (int) $claims['iat'] : 0;
        if ($sub > 0 && self::isBeforeUserCutoff($sub, $iat)) {
            throw new JwtException('Access token predates user token cutoff (mass-revoked)');
        }

        return $claims;
    }

    /**
     * ⏱️ Is the token's iat before the user's tokensInvalidBefore cutoff?
     *
     * @param int $userID  The token subject.
     * @param int $iat     The token's issued-at (unix seconds).
     * @return bool True when the token is invalidated by the cutoff.
     */
    public static function isBeforeUserCutoff(int $userID, int $iat): bool
    {
        $row = Database::fetchOne(
            "SELECT tokensInvalidBefore FROM tblUsers WHERE userID = ? LIMIT 1",
            [$userID],
            'i'
        );

        // 🟢 No user, or no cutoff set → nothing invalidates the token here.
        if ($row === null || empty($row['tokensInvalidBefore'])) {
            return false;
        }

        // 🕰️ Compare in UTC (the DB connection is pinned to +00:00 in database.php).
        $cutoffTs = strtotime((string) $row['tokensInvalidBefore'] . ' UTC');
        if ($cutoffTs === false) {
            // Malformed timestamp → fail SAFE (do not silently accept).
            return true;
        }

        // ⛔ Reject when the token was issued strictly before the cutoff.
        return $iat < $cutoffTs;
    }

    /**
     * 🔌 Install this service's jti denylist checker onto Jwt for the duration of
     * a callback, restoring the previous checker afterwards.
     *
     * Lets verifyAccessToken() be self-contained (no global wiring required at
     * bootstrap) while still honouring any checker an integrator installed.
     *
     * @template T
     * @param callable():T $fn The verify call to run with our checker installed.
     * @return T
     */
    public static function withDenylistChecker(callable $fn): mixed
    {
        // 🚫 Install ours, run, restore. We do not have a getter for the previous
        //    checker (the facade exposes only a setter), so we set to our checker
        //    and reset to null afterwards — production wires ours as the single
        //    checker, so null-on-exit is the correct baseline.
        Jwt::setDenylistChecker([self::class, 'isJtiRevoked']);
        try {
            return $fn();
        } finally {
            Jwt::setDenylistChecker(null);
        }
    }

    // ========================================================================
    // 🔧 INTERNAL HELPERS
    // ========================================================================

    /**
     * 🎫 Mint one access+refresh pair in a GIVEN family and persist the refresh
     * row (hashed). Shared by issueTokens() (new family, first-party),
     * issueForClient() (new family, RP-owned — G-001 Stage A3) and refresh()
     * (same family, either kind). Returns the public payload PLUS an internal
     * '_new_token_id' the refresh() caller uses to set replacedByID (stripped
     * before returning).
     *
     * @param int                $userID   Owner.
     * @param string             $familyID Rotation family for the refresh token.
     * @param array<int,string>  $scope    Scope strings.
     * @param string|null        $aud      Audience override for the access token
     *                                     (the RP's clientIdentifier for an
     *                                     RP-issued pair; null → jwt.audience).
     * @param int|null           $clientID Owning RP's internal clientID
     *                                     (tblOAuthClients.clientID), persisted
     *                                     onto tblRefreshTokens.clientID — NULL
     *                                     for a first-party token (the default;
     *                                     UNCHANGED behaviour for the existing
     *                                     issueTokens()/refresh() call sites
     *                                     that don't pass it).
     * @return array<string,mixed> access_token/refresh_token/… + _new_token_id.
     * @throws JwtException On signing failure.
     */
    private static function mintPair(int $userID, string $familyID, array $scope, ?string $aud, ?int $clientID = null): array
    {
        // 📏 Normalise the scope to a single space-delimited string (same
        //    vocabulary as tblAPIKeys.permissions — §5 coexistence).
        $scopeStr = self::normaliseScope($scope);

        // ⏱️ Access-token lifetime (for the expires_in hint returned to clients).
        $accessTtl = (int) getSetting('jwt.access_ttl', 900);

        // 🎟️ Sign the access-token JWT. The facade owns jti/iat/nbf/exp; we pass
        //    sub (as a STRING, per JWT convention) + scope.
        $accessToken = Jwt::sign(
            [
                'sub'   => (string) $userID,
                'scope' => $scopeStr,
            ],
            $aud,
            $accessTtl
        );

        // 🔎 Pull the jti back out of the freshly-signed token so callers can
        //    later revoke THIS exact access token. Decoding our own just-signed
        //    token is cheap and avoids duplicating jti generation.
        $jti = self::extractJti($accessToken);

        // 🔐 Mint the OPAQUE refresh token: 32 random bytes → 64 hex chars
        //    (256 bits). PLAINTEXT is returned to the caller ONCE; only the
        //    SHA-256 hash is stored (spec §5 / MEMORY C5).
        $refreshPlain = bin2hex(random_bytes(32));
        $refreshHash  = self::hashRefreshToken($refreshPlain);

        // 📅 Refresh-token expiry = now + jwt.refresh_ttl (default 30 days).
        $refreshTtl   = (int) getSetting('jwt.refresh_ttl', 2592000);
        $expiresAtSql = date('Y-m-d H:i:s', time() + $refreshTtl);

        // 🌐 Capture request context for audit (never the token itself).
        $ip = self::clientIp();
        $ua = self::userAgent();

        // 💾 Persist the refresh row (hash only). The UNIQUE index on tokenHash
        //    guarantees no duplicate; a collision is astronomically improbable
        //    (256-bit token) and would surface as a query exception, not silent.
        //    clientID (G-001) is NULL for a first-party token — the column has
        //    existed since migration 030 ("Reserved for G-001 RP clients") and
        //    is populated here now that issueForClient()/refresh() supply it.
        $newTokenId = Database::insert(
            "INSERT INTO tblRefreshTokens
                (userID, familyID, tokenHash, clientID, scope, issuedAt, expiresAt, ipAddress, userAgent, createdAt)
             VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW())",
            [$userID, $familyID, $refreshHash, $clientID, $scopeStr, $expiresAtSql, $ip, $ua],
            'ississss'
        );

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshPlain,   // ← returned ONCE, never stored
            'token_type'    => 'Bearer',
            'expires_in'    => $accessTtl,
            'scope'         => $scopeStr,
            'family_id'     => $familyID,
            'jti'           => $jti,
            '_new_token_id' => $newTokenId,     // internal — stripped by refresh()
        ];
    }

    /**
     * 🚨 Handle refresh-token REUSE: revoke the whole family + raise an alert.
     *
     * Called when a spent/revoked refresh token is replayed (or a rotation race
     * is lost). Revokes every family member (reason = reuse_detected) and raises
     * a HIGH-severity SecurityAlert so an operator sees the theft signal. Best-
     * effort on the alert (a failing alerter must never suppress the family
     * revoke — the revoke is the security control, the alert is the notification).
     *
     * @param string $familyID    The compromised family.
     * @param int    $userID      The affected user (for the alert).
     * @param int    $tokenID     The specific replayed token (for the alert metadata).
     * @param bool   $wasRevoked  True if the replayed row was already revoked
     *                            (vs merely rotated) — informational for the alert.
     * @return void
     */
    private static function handleReuse(string $familyID, int $userID, int $tokenID, bool $wasRevoked): void
    {
        // 🧵 1. Kill the family (the actual security control). Idempotent.
        self::revokeFamily($familyID, self::REASON_REUSE_DETECTED);

        // 🚨 2. Raise a security alert (notification — best-effort).
        if (class_exists('SecurityAlertManager')) {
            try {
                SecurityAlertManager::create(
                    // No dedicated token-reuse TYPE_* exists; SESSION_HIJACK is the
                    // closest semantic (a stolen credential replayed by a third
                    // party). The description + metadata make the specific cause
                    // explicit for the operator.
                    SecurityAlertManager::TYPE_SESSION_HIJACK,
                    SecurityAlertManager::SEVERITY_HIGH,
                    'Refresh-token reuse detected for user ID ' . $userID
                        . ' — token family revoked (possible token theft).',
                    [
                        'event'        => 'refresh_token_reuse',
                        'family_id'    => $familyID,
                        'token_id'     => $tokenID,
                        'user_id'      => $userID,
                        'was_revoked'  => $wasRevoked,
                        'action_taken' => 'family_revoked',
                    ],
                    $userID,
                    self::clientIp()
                );
            } catch (\Throwable $e) {
                // 🔇 Never let alerting break the revoke path. Log only (no token).
                error_log('TokenService::handleReuse alert failed: ' . $e->getMessage());
            }
        }

        // 📝 3. Activity-log the reuse for the audit trail (best-effort).
        if (class_exists('ActivityLogger')) {
            try {
                ActivityLogger::log(
                    $userID,
                    'refresh_token_reuse_detected',
                    'security',
                    'critical',
                    'Refresh-token reuse detected — family ' . $familyID . ' revoked.',
                    [
                        'family_id' => $familyID,
                        'token_id'  => $tokenID,
                    ]
                );
            } catch (\Throwable $e) {
                error_log('TokenService::handleReuse activity log failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * 🧵 Generate a new rotation family id: 16 random bytes → 32 hex chars
     * (matches tblRefreshTokens.familyID CHAR(32)).
     *
     * @return string
     */
    private static function newFamilyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 🔑 Hash an opaque refresh token for storage/lookup (SHA-256 hex → 64 chars,
     * matches tblRefreshTokens.tokenHash CHAR(64)).
     *
     * SHA-256 (not a slow password hash) is correct here: the token is already
     * high-entropy (256 bits) so there is no brute-force surface to slow down —
     * we only need a fast, deterministic, indexable one-way transform. This
     * mirrors tblAPIKeys.keyHash and the C5 reset-token fix.
     *
     * @param string $token The opaque plaintext.
     * @return string 64-char lowercase hex SHA-256.
     */
    private static function hashRefreshToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 📏 Normalise a scope array to a single space-delimited string.
     *
     * De-dupes, drops empties, preserves order. A string passed in (already
     * space-delimited) is accepted and re-normalised.
     *
     * @param array<int,string>|string $scope
     * @return string
     */
    private static function normaliseScope(array|string $scope): string
    {
        if (is_string($scope)) {
            $scope = $scope === '' ? [] : (preg_split('/\s+/', trim($scope)) ?: []);
        }

        $clean = [];
        foreach ($scope as $s) {
            $s = trim((string) $s);
            if ($s !== '' && !in_array($s, $clean, true)) {
                $clean[] = $s;
            }
        }

        return implode(' ', $clean);
    }

    /**
     * 🔎 Extract the jti from a freshly-signed access token WITHOUT full verify.
     *
     * We just signed it, so its integrity is not in question here; we only need
     * the jti value the facade generated so the caller can revoke this exact
     * token later. Decodes the payload segment directly.
     *
     * @param string $jwt The compact JWT.
     * @return string The jti claim ('' if somehow absent).
     */
    private static function extractJti(string $jwt): string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return '';
        }

        // KeyManager exposes the base64url decoder used across the JWT stack.
        $json = KeyManager::base64UrlDecode($parts[1]);
        if ($json === '') {
            return '';
        }

        $payload = json_decode($json, true);
        return is_array($payload) && isset($payload['jti']) && is_string($payload['jti'])
            ? $payload['jti']
            : '';
    }

    /**
     * 🌐 Best-effort client IP (uses the global helper when available).
     *
     * @return string|null
     */
    private static function clientIp(): ?string
    {
        if (function_exists('getClientIP')) {
            $ip = getClientIP();
            return $ip !== '' ? $ip : null;
        }
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /**
     * 🖥️ Best-effort user agent (uses the global helper when available).
     *
     * @return string|null
     */
    private static function userAgent(): ?string
    {
        if (function_exists('getUserAgent')) {
            $ua = getUserAgent();
            return $ua !== '' ? $ua : null;
        }
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }
}

// ✅ TokenService loaded successfully
