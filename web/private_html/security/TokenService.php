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
     *   • the ACCESS token's `sub` claim is the OIDC pairwise (or public, per
     *     the client's subjectType) subject — {@see OAuthClientManager::computeSubject()}
     *     — the SAME opaque value the id_token/userinfo use, never the raw
     *     userID (G-001 red-team F-01 fix). The mapping is persisted to
     *     tblOAuthSubjects (migration 033) so {@see OAuthUserInfoService} can
     *     resolve it back to a real userID — a pairwise subject is a one-way
     *     hash, so this is the only way back.
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
     *                                 🔐 G-001 Stage A5: a refresh token is
     *                                 only actually MINTED when `$scope`
     *                                 contains `offline_access` — see below.
     * @return array{
     *     access_token:string,
     *     refresh_token:?string,
     *     token_type:string,
     *     expires_in:int,
     *     scope:string,
     *     family_id:string,
     *     jti:string
     * } `refresh_token` is null when the granted scope lacked `offline_access`.
     * @throws JwtException On signing failure (propagated from Jwt::sign()).
     */
    public static function issueForClient(int $userID, int $clientID, string $clientIdentifier, array $scope = []): array
    {
        // 🧵 An authorization_code exchange always starts a NEW rotation family
        //    — mirrors issueTokens(); there is no "existing family" concept at
        //    initial code exchange (only refresh() continues one).
        $familyID = self::newFamilyId();

        // 🔐 G-001 Stage A5 (B-058 follow-up) — GATE refresh-token MINTING
        //    behind the `offline_access` scope (the standard OIDC convention:
        //    without it, an RP only gets a short-lived access token and must
        //    re-run the full authorization flow when it expires; WITH it, the
        //    RP is granted a long-lived refresh token too). A client that
        //    never requested/was granted offline_access has no business
        //    holding a refresh token at all — minting one anyway would be an
        //    unused, needlessly long-lived credential sitting in the DB.
        //    First-party issueTokens() is DELIBERATELY UNCHANGED (always
        //    mints a refresh token) — it has no OIDC scope-consent model, and
        //    changing that first-party contract is out of scope here.
        $withRefresh = in_array('offline_access', $scope, true);

        // 🕵️ G-001 red-team F-01 fix — the RP access token's `sub` must be the
        //    SAME opaque (pairwise, or public-per-policy) subject the id_token
        //    and /oauth/userinfo already use, never the raw internal userID.
        //    resolveAndRecordSubject() computes it AND persists the
        //    (subjectHash -> userID, clientID) mapping tblOAuthUserInfoService
        //    needs to resolve it back, since a pairwise subject is one-way.
        $subject = self::resolveAndRecordSubject($userID, $clientID);

        return self::mintPair($userID, $familyID, $scope, $clientIdentifier, $clientID, $withRefresh, $subject);
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
     * 🔒 G-001 Stage A5 (B-058 fix) — CLIENT-BINDING enforcement:
     *   A refresh token minted via {@see self::issueForClient()} records the
     *   RP's internal clientID on the row. Before this fix, refresh() would
     *   rotate ANY structurally-valid, unexpired token regardless of WHO
     *   presented it — so a refresh token minted for Client A could be
     *   redeemed at the first-party `/api/v1/auth/refresh` endpoint (no
     *   client auth at all), or by Client B presenting ITS OWN valid
     *   credentials, as long as it got hold of Client A's token value. Now:
     *     • $authenticatedClientID MUST equal the row's clientID EXACTLY
     *       (both null, i.e. first-party token via the first-party endpoint,
     *       counts as a match). Any mismatch — wrong client, no client at
     *       all for an RP token, or an unexpected client for a first-party
     *       token — is REJECTED *before* the token is ever inspected for
     *       reuse or spent (see below for why that ordering matters).
     *   A mismatch is an AUTHORIZATION failure, not a theft signal — the
     *   token itself may still be perfectly live and legitimately held by
     *   its real owning client. So (deliberately, see handleClientBindingMismatch())
     *   a mismatch does NOT revoke the family (unlike genuine reuse below);
     *   it only refuses to let up THIS caller rotate it. Checking binding
     *   BEFORE the reuse-detection/expiry/atomic-spend steps guarantees a
     *   mismatched request can never rotate, spend, or (more importantly)
     *   trigger a false-positive family-wide revoke for tokens it has no
     *   claim to — an unauthenticated caller (or a rival client) could
     *   otherwise force a family revoke (a real user's DoS) merely by
     *   replaying an intercepted-but-still-live token with the wrong client
     *   credentials attached.
     *
     * @param string   $refreshToken         The opaque refresh-token plaintext from the client.
     * @param int|null $authenticatedClientID The CALLER's own authenticated client
     *                                        identity (tblOAuthClients.clientID),
     *                                        or null for the first-party
     *                                        `/api/v1/auth/refresh` endpoint
     *                                        (which performs no client auth at
     *                                        all). Defaults to null so every
     *                                        EXISTING first-party call site
     *                                        keeps compiling/behaving exactly
     *                                        as before.
     * @return array{
     *     access_token:string,
     *     refresh_token:string,
     *     token_type:string,
     *     expires_in:int,
     *     scope:string,
     *     family_id:string,
     *     jti:string
     * }
     * @throws JwtException On any invalid / reused / expired / client-mismatched refresh token.
     */
    public static function refresh(string $refreshToken, ?int $authenticatedClientID = null): array
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

        // 🔒 1.5 CLIENT-BINDING ENFORCEMENT (B-058) — see the method doc for
        //    the full rationale. This MUST run before reuse-detection/expiry/
        //    spend so a mismatched caller can never rotate the token NOR
        //    trigger the reuse machinery's family-wide revoke on a token it
        //    has no claim to (that revoke is reserved for PROVEN replay).
        //    A simple strict inequality covers all four cases the spec
        //    calls out: null===null (first-party @ first-party endpoint) is
        //    a match; anything else (wrong client, missing client for an
        //    RP token, or an unexpected client on a first-party token) is not.
        if ($authenticatedClientID !== $clientID) {
            self::handleClientBindingMismatch($clientID, $authenticatedClientID, $userID, $tokenID);
            throw new JwtException('Refresh token client binding failed');
        }

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
        $scopeArray = $scopeStr !== '' ? explode(' ', (string) $scopeStr) : [];

        $aud     = null;
        $subject = null;
        if ($clientID !== null && class_exists('OAuthClientManager')) {
            $client = OAuthClientManager::getClientByID($clientID);
            if ($client !== null && !empty($client['clientIdentifier'])) {
                $aud = (string) $client['clientIdentifier'];

                // 🔒 G-001 red-team F-02 fix — RE-CHECK the carried-forward
                //    scope against the client's CURRENT allowedScopes before
                //    re-minting. Without this, a refresh token minted with
                //    scope S keeps re-minting access tokens carrying S FOREVER,
                //    even after an admin tightens the client's policy and
                //    removes a scope from it — the stored scope was carried
                //    forward VERBATIM with no re-validation. Intersecting
                //    (never escalating — only ever narrows) means a scope an
                //    admin has since revoked stops being issued on the very
                //    next rotation, without needing to revoke the whole
                //    refresh-token family just to enforce a policy change.
                //    A first-party token (clientID null) is UNAFFECTED — it
                //    has no client-scoped allowedScopes concept to check.
                $allowedScopesArray = (array) ($client['allowedScopesArray'] ?? []);
                $scopeArray = array_values(array_intersect($scopeArray, $allowedScopesArray));

                // 🕵️ G-001 red-team F-01 fix — recompute + re-affirm the
                //    pairwise subject mapping so a ROTATED RP access token
                //    keeps the SAME opaque `sub` the original id_token/userinfo
                //    used (never the raw userID) — see issueForClient()'s own
                //    doc comment + resolveAndRecordSubject() for the full
                //    rationale. UPSERT is idempotent, so re-affirming an
                //    already-existing mapping on every rotation is a cheap
                //    no-op in the common case.
                $subject = self::resolveAndRecordSubject($userID, $clientID, $client);
            }
        }

        // 🎁 Issue the successor pair in the SAME family, carrying the same
        //    (policy-intersected) scope AND the same clientID (G-001 — see
        //    mintPair()'s $clientID param), so tblRefreshTokens.clientID never
        //    gets "lost" on rotation. $withRefresh is explicitly true (a
        //    rotation ALWAYS re-mints a refresh token — see mintPair()'s own
        //    doc) so the trailing $subject param can be supplied positionally.
        $pair = self::mintPair($userID, $familyID, $scopeArray, $aud, $clientID, true, $subject);

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
        //    🕵️ G-001 red-team F-01 follow-up: `sub` is the raw userID ONLY
        //    for a first-party token — an RP-issued token carries the OIDC
        //    pairwise (or public, per client policy) opaque subject (see
        //    mintPair()), which is NOT directly castable to a meaningful int.
        //    ctype_digit() distinguishes the two: a userID-string is always
        //    all-decimal-digits; a SHA-256 pairwise hex digest is 64 chars
        //    and (astronomically overwhelming odds) contains at least one
        //    a-f character.
        $iat = isset($claims['iat']) ? (int) $claims['iat'] : 0;

        if (isset($claims['sub']) && is_string($claims['sub']) && ctype_digit($claims['sub'])) {
            // 🪪 First-party token — sub IS the raw userID. UNCHANGED
            //    behaviour from before B-063.
            $sub = (int) $claims['sub'];
            if ($sub > 0 && self::isBeforeUserCutoff($sub, $iat)) {
                throw new JwtException('Access token predates user token cutoff (mass-revoked)');
            }
        } else {
            // 🕵️ B-063 (LOW) fix — an RP-issued token's `sub` is an opaque
            //    (pairwise/public) subject, not a userID. Previously this
            //    branch SKIPPED the cutoff entirely (casting the hex string
            //    blindly to (int) would have silently queried an unrelated,
            //    leading-digit-truncated userID's cutoff — a correctness bug
            //    that fix avoided, but at the cost of the mass-revoke lever
            //    never reaching already-issued RP access tokens at all).
            //    Now: resolve the REAL userID via the SAME
            //    (subjectHash, clientID) -> userID mapping
            //    OAuthUserInfoService uses (tblOAuthSubjects, migration
            //    033/035), keyed on this token's `sub` + the client its
            //    `aud` claim resolves to, and apply the IDENTICAL cutoff
            //    check first-party tokens get.
            //
            //    ADDITIVE / FAIL-SAFE ONLY: this can only ever ADD a
            //    rejection, never remove one that would otherwise have
            //    happened. If the aud does not resolve to a known client, or
            //    no subject-mapping row is found (should not happen for a
            //    legitimately-issued RP token — issueForClient()/refresh()
            //    always write the mapping BEFORE returning the token), we do
            //    NOT newly-reject — the token's OTHER checks (signature,
            //    exp/nbf, jti denylist) already ran above and still fully
            //    apply, and RP tokens retain their own scoped revocation
            //    levers (jti denylist via /oauth/revoke, and
            //    TokenService::revokeClientForUser()'s refresh-family
            //    revoke). We deliberately never let a transient or missing
            //    lookup turn an otherwise cryptographically valid token into
            //    a rejected one — we only ever STRENGTHEN the check when
            //    resolution succeeds.
            $resolvedUserID = self::resolveUserIdForRpSubject($claims);
            if ($resolvedUserID !== null && self::isBeforeUserCutoff($resolvedUserID, $iat)) {
                throw new JwtException('Access token predates user token cutoff (mass-revoked)');
            }
        }

        return $claims;
    }

    /**
     * 🕵️ B-063 helper — resolve an RP-issued access token's opaque `sub`
     * (pairwise or public subject) back to the real userID it belongs to, via
     * the SAME `tblOAuthSubjects` (subjectHash, clientID) mapping
     * {@see OAuthUserInfoService} uses. The client is identified from the
     * token's OWN (already-crypto-verified, by the time this runs)
     * `aud` claim.
     *
     * Fails soft (returns null) on ANY ambiguity — an unresolvable aud, an
     * inactive/missing client, or no matching subject row — so the caller
     * ({@see self::verifyAccessToken()}) can apply the "never newly-reject on
     * a lookup miss" fail-safe rule described there.
     *
     * @param array<string,mixed> $claims The already-verified access-token claims.
     * @return int|null The resolved userID, or null if it could not be resolved.
     */
    private static function resolveUserIdForRpSubject(array $claims): ?int
    {
        if (!isset($claims['sub']) || !is_string($claims['sub']) || $claims['sub'] === '') {
            return null;
        }
        if (!isset($claims['aud']) || !is_string($claims['aud']) || $claims['aud'] === '') {
            return null;
        }
        if (!class_exists('OAuthClientManager')) {
            return null;
        }

        // 🪪 Resolve aud -> the RP's internal clientID (mirrors
        //    OAuthUserInfoService::handleUserInfoRequest()'s own aud -> client
        //    resolution, minus its isActive gate — a deactivated client's
        //    ALREADY-ISSUED tokens should still be reachable by the mass-
        //    revoke cutoff, not silently exempted from it).
        $client = OAuthClientManager::getClient($claims['aud']);
        if ($client === null || empty($client['clientID'])) {
            return null;
        }

        $row = Database::fetchOne(
            "SELECT userID FROM tblOAuthSubjects WHERE subjectHash = ? AND clientID = ? LIMIT 1",
            [$claims['sub'], (int) $client['clientID']],
            'si'
        );
        if ($row === null) {
            return null;
        }

        $userID = (int) $row['userID'];
        return $userID > 0 ? $userID : null;
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
     * @param bool               $withRefresh Whether to actually MINT + persist
     *                                     a refresh token at all. Defaults to
     *                                     true — issueTokens() (first-party)
     *                                     and refresh() (rotation ALWAYS
     *                                     re-mints, since possessing a live
     *                                     refresh token to rotate already
     *                                     proves offline_access was granted at
     *                                     issuance) both rely on this default
     *                                     and never pass it explicitly.
     *                                     issueForClient() passes false when
     *                                     the granted scope lacks
     *                                     `offline_access` (G-001 Stage A5 —
     *                                     see its own doc comment) — when
     *                                     false, NO row is inserted and the
     *                                     returned 'refresh_token'/
     *                                     '_new_token_id' are both null.
     * @param string|null        $subject  G-001 red-team F-01 fix: overrides the
     *                                     access token's `sub` claim. Defaults
     *                                     to null, in which case `sub` falls
     *                                     back to `(string) $userID` — the
     *                                     ORIGINAL, UNCHANGED first-party
     *                                     behaviour (issueTokens() never passes
     *                                     this). issueForClient()/refresh()
     *                                     pass the OIDC pairwise (or public,
     *                                     per policy) subject computed by
     *                                     {@see self::resolveAndRecordSubject()}
     *                                     so an RP's access token carries the
     *                                     SAME opaque `sub` its id_token/
     *                                     userinfo already use — never the raw
     *                                     userID.
     * @return array<string,mixed> access_token/refresh_token/… + _new_token_id.
     * @throws JwtException On signing failure.
     */
    private static function mintPair(
        int $userID,
        string $familyID,
        array $scope,
        ?string $aud,
        ?int $clientID = null,
        bool $withRefresh = true,
        ?string $subject = null
    ): array {
        // 📏 Normalise the scope to a single space-delimited string (same
        //    vocabulary as tblAPIKeys.permissions — §5 coexistence).
        $scopeStr = self::normaliseScope($scope);

        // ⏱️ Access-token lifetime (for the expires_in hint returned to clients).
        $accessTtl = (int) getSetting('jwt.access_ttl', 900);

        // 🎟️ Sign the access-token JWT. The facade owns jti/iat/nbf/exp; we pass
        //    sub (as a STRING, per JWT convention) + scope.
        //    🕵️ G-001 red-team F-01 fix: `sub` is `$subject` when the caller
        //    supplied one (an RP-issued pair — always the OIDC pairwise/public
        //    subject, NEVER the raw userID) — falls back to the raw userID
        //    ONLY for a first-party token, exactly as before.
        $accessToken = Jwt::sign(
            [
                'sub'   => $subject ?? (string) $userID,
                'scope' => $scopeStr,
            ],
            $aud,
            $accessTtl
        );

        // 🔎 Pull the jti back out of the freshly-signed token so callers can
        //    later revoke THIS exact access token. Decoding our own just-signed
        //    token is cheap and avoids duplicating jti generation.
        $jti = self::extractJti($accessToken);

        // 🔐 G-001 Stage A5 — refresh-token MINTING is gated by $withRefresh.
        //    When false (RP issuance WITHOUT offline_access), skip the mint +
        //    persistence entirely: no plaintext generated, no row inserted, no
        //    orphaned/unusable refresh token left in the DB. The access token
        //    is still returned normally — a caller without offline_access
        //    still gets a working (short-lived) access token, just no way to
        //    silently refresh it later.
        $refreshPlain = null;
        $newTokenId   = null;

        if ($withRefresh) {
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

            // 💾 Persist the refresh row (hash only). The UNIQUE index on
            //    tokenHash guarantees no duplicate; a collision is
            //    astronomically improbable (256-bit token) and would surface
            //    as a query exception, not silent. clientID (G-001) is NULL
            //    for a first-party token — the column has existed since
            //    migration 030 ("Reserved for G-001 RP clients") and is
            //    populated here now that issueForClient()/refresh() supply it.
            $newTokenId = Database::insert(
                "INSERT INTO tblRefreshTokens
                    (userID, familyID, tokenHash, clientID, scope, issuedAt, expiresAt, ipAddress, userAgent, createdAt)
                 VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW())",
                [$userID, $familyID, $refreshHash, $clientID, $scopeStr, $expiresAtSql, $ip, $ua],
                'ississss'
            );
        }

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshPlain,   // ← returned ONCE, never stored; null when $withRefresh===false
            'token_type'    => 'Bearer',
            'expires_in'    => $accessTtl,
            'scope'         => $scopeStr,
            'family_id'     => $familyID,
            'jti'           => $jti,
            'user_id'       => $userID,        // internal — convenience for callers that need it post-mint (e.g. audit logging)
            '_new_token_id' => $newTokenId,     // internal — stripped by refresh(); null when $withRefresh===false
        ];
    }

    /**
     * 🕵️ Resolve the OIDC subject (pairwise, or public per the client's
     * subjectType) for a userID+clientID, and UPSERT the
     * (subjectHash → userID, clientID) mapping into tblOAuthSubjects
     * (migration 033) so {@see OAuthUserInfoService} can resolve an access
     * token's opaque `sub` claim back to a real userID — a PAIRWISE subject is
     * a one-way hash (sha256(sector|userID|salt)), so this table is the only
     * way back. Shared by {@see self::issueForClient()} (initial mint) and
     * {@see self::refresh()} (rotation) so an RP's access token ALWAYS carries
     * the exact same `sub` its id_token/userinfo already use (G-001 red-team
     * F-01 fix).
     *
     * Fails CLOSED (throws) rather than silently falling back to a raw-userID
     * `sub` for an RP token if the client cannot be resolved — that fallback
     * would quietly re-introduce the exact pairwise-correlation leak this fix
     * closes. In practice this should never happen: every caller already
     * holds a clientID it just validated (an authenticated client at
     * issuance, or a live tblRefreshTokens.clientID at rotation), and
     * tblOAuthClients rows are never hard-deleted while grants exist.
     *
     * @param int        $userID   Owning user.
     * @param int        $clientID The RP's internal clientID (tblOAuthClients.clientID).
     * @param array|null $client   Optional already-hydrated client row (avoids
     *                             a redundant getClientByID() lookup when the
     *                             caller — e.g. refresh() — already fetched
     *                             one for the audience resolution). Resolved
     *                             internally when omitted.
     * @return string The computed subject (the value to sign into `sub`).
     * @throws JwtException If OAuthClientManager is unavailable or the client
     *                       cannot be resolved.
     */
    private static function resolveAndRecordSubject(int $userID, int $clientID, ?array $client = null): string
    {
        if (!class_exists('OAuthClientManager')) {
            // Should never happen in production (autoloaded); fail closed
            // rather than silently mint a raw-userID sub for an RP token.
            throw new JwtException('OAuthClientManager unavailable — cannot compute OIDC subject');
        }

        $client ??= OAuthClientManager::getClientByID($clientID);
        if ($client === null) {
            throw new JwtException('Unknown OAuth client — cannot compute OIDC subject');
        }

        $subject = OAuthClientManager::computeSubject($userID, $client);

        // 💾 UPSERT — idempotent. A pairwise subject is deterministic for a
        //    given (sector, userID, salt), so re-affirming an already-existing
        //    mapping (a repeat issuance, or a rotation) is a cheap no-op; the
        //    write only actually changes anything the FIRST time this exact
        //    subject is minted.
        //    🔧 B-062 fix (migration 035): tblOAuthSubjects' UNIQUE key is now
        //    COMPOSITE — (clientID, subjectHash), not subjectHash alone — so
        //    this UPSERT triggers on the (clientID, subjectHash) PAIR. That is
        //    exactly right: a re-affirm for the SAME (client, subject) updates
        //    userID in place, while TWO DIFFERENT clients that happen to mint
        //    the SAME subjectHash (only possible for a 'public' subjectType
        //    client, whose sub is the raw, client-unscoped userID — see
        //    OAuthClientManager::computeSubject()) now INSERT two SEPARATE
        //    rows instead of colliding and overwriting each other's clientID.
        //    `clientID = VALUES(clientID)` is technically redundant now
        //    (clientID is part of the key that decided WHICH row got
        //    upserted, so it can never actually change on that row) — kept
        //    for clarity/defence-in-depth and because it is harmless either
        //    way; only `userID` can meaningfully change on a re-affirm.
        Database::query(
            "INSERT INTO tblOAuthSubjects (subjectHash, userID, clientID)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE userID = VALUES(userID), clientID = VALUES(clientID)",
            [$subject, $userID, $clientID],
            'sii'
        );

        return $subject;
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
     * 🔒 Handle a refresh-token CLIENT-BINDING MISMATCH (B-058): the caller's
     * authenticated client identity does not match the token's owning client
     * (or a first-party token was presented alongside an unexpected client).
     *
     * Unlike {@see self::handleReuse()}, this does NOT revoke the family. A
     * binding mismatch means THIS caller has no right to rotate THIS token —
     * it says nothing about whether the token itself has been compromised.
     * The token's real owner (the correct client, or the first-party
     * endpoint with no client) can still redeem it normally afterwards.
     * Revoking on mismatch would hand an unauthenticated (or wrong-client)
     * caller a trivial DoS: replay any observed/guessed-at live token with
     * the "wrong" client attached, repeatedly, to force-revoke a stranger's
     * session — that must NOT be possible. We do raise a (lower-severity)
     * security alert, since a mismatch is still a signal worth an operator's
     * attention (a legitimate integration bug, or a genuine cross-client
     * probing attempt), best-effort so alerting can never block the deny.
     *
     * @param int|null $tokenClientID          The row's OWNING clientID (null = first-party).
     * @param int|null $authenticatedClientID  The CALLER's authenticated clientID (null = none/first-party).
     * @param int      $userID                 The token's owning user (for the alert).
     * @param int      $tokenID                The specific token row (for the alert metadata).
     * @return void
     */
    private static function handleClientBindingMismatch(
        ?int $tokenClientID,
        ?int $authenticatedClientID,
        int $userID,
        int $tokenID
    ): void {
        // 🚨 Best-effort security alert — SEVERITY_MEDIUM (below reuse's HIGH):
        //    an authz mismatch is not yet proven theft.
        if (class_exists('SecurityAlertManager')) {
            try {
                SecurityAlertManager::create(
                    // No dedicated TYPE_* exists for this (mirrors handleReuse()'s
                    // own note) — TYPE_SESSION_HIJACK is the closest semantic
                    // (an unauthorized party attempting to use a credential that
                    // is not theirs).
                    SecurityAlertManager::TYPE_SESSION_HIJACK,
                    SecurityAlertManager::SEVERITY_MEDIUM,
                    'Refresh-token client-binding mismatch for user ID ' . $userID
                        . ' — presented client did not match the token owner (token NOT rotated).',
                    [
                        'event'                    => 'refresh_token_client_mismatch',
                        'token_id'                 => $tokenID,
                        'user_id'                  => $userID,
                        'token_client_id'          => $tokenClientID,
                        'authenticated_client_id'  => $authenticatedClientID,
                        'action_taken'             => 'rejected_no_rotation_no_revoke',
                    ],
                    $userID,
                    self::clientIp()
                );
            } catch (\Throwable $e) {
                error_log('TokenService::handleClientBindingMismatch alert failed: ' . $e->getMessage());
            }
        }

        // 📝 Best-effort activity log (audit trail).
        if (class_exists('ActivityLogger')) {
            try {
                ActivityLogger::log(
                    $userID,
                    'refresh_token_client_mismatch',
                    'security',
                    'warning',
                    'Refresh-token client-binding mismatch — request denied, token left untouched.',
                    [
                        'token_id'                => $tokenID,
                        'token_client_id'         => $tokenClientID,
                        'authenticated_client_id' => $authenticatedClientID,
                    ]
                );
            } catch (\Throwable $e) {
                error_log('TokenService::handleClientBindingMismatch activity log failed: ' . $e->getMessage());
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
