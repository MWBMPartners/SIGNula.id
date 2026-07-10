<?php
/**
 * ============================================================================
 * 🧪 TokenService Integration Tests (G-003 Stage 2 — issuance/rotation/reuse)
 * ============================================================================
 *
 * DB-bound tests for the STATEFUL half of JWT bearer auth. These exercise the
 * real tblRefreshTokens / tblRevokedTokens / tblUsers.tokensInvalidBefore
 * against the signula_test database, while the CRYPTO layer (Jwt / KeyManager)
 * runs on an in-memory key override so no real signing-key material touches the
 * DB or web/_private (same isolation JwtTest uses).
 *
 * Coverage (per the S2 spec / task):
 *   • issue → verifyAccessToken ACCEPTS a freshly-issued token.
 *   • refresh happy-path ROTATES: new pair issued in the same family, and the
 *     OLD refresh token no longer works.
 *   • 🚨 REUSE DETECTION: presenting a rotated (spent) refresh token REVOKES THE
 *     WHOLE FAMILY and RAISES A SECURITY ALERT (the critical anti-theft test).
 *   • revoke access jti → verifyAccessToken then REJECTS that token (denylist).
 *   • revokeAllForUser → older access tokens REJECTED via tokensInvalidBefore.
 *   • expired refresh token → DENIED (and, being merely aged out, does NOT
 *     trip reuse detection).
 *
 * Isolation: DatabaseTestCase truncates the touched tables before each test. As
 * with MFATest, fixtures are inserted through Database:: (the same connection
 * the code-under-test uses), so cross-connection visibility is guaranteed.
 *
 * @package    SIGNula\Tests\Integration\Security
 * @version    2.8.0-beta
 * @see        web/private_html/security/TokenService.php
 * @see        web/private_html/security/Jwt.php
 * @see        web/private_html/security/KeyManager.php
 * @see        .dev-team/specs/G-003.md §5
 */

namespace SIGNula\Tests\Integration\Security;

use SIGNula\Tests\DatabaseTestCase;
use JwtException;
use KeyManager;
use Jwt;
use TokenService;

// ============================================================================
// 📦 LOAD REQUIRED SOURCE FILES (dependency order)
// ============================================================================
// database.php        → Database wrapper (query/fetchOne/insert/getAffectedRows).
// SecurityUtils.php   → encrypt/decrypt (KeyManager), token helpers.
// JwtException.php    → the exception every failure path throws.
// JwtLibLoader.php    → loads the vendored firebase/php-jwt source.
// KeyManager.php      → RS256 keypair lifecycle (in-memory override here).
// Jwt.php             → sign/verify facade (alg-pinned).
// SecurityAlertManager.php → reuse detection raises an alert through this.
// ActivityLogger / ErrorLogger → referenced by the reuse + alert paths.
// TokenService.php    → the class under test.
requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/security/JwtException.php');
requireSource('private_html/security/JwtLibLoader.php');
requireSource('private_html/security/KeyManager.php');
requireSource('private_html/security/Jwt.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/security/SecurityAlertManager.php');
requireSource('private_html/security/TokenService.php');
// 🪪 G-001 Stage A3 gotcha-fix tests (issueForClient() / refresh() clientID
// preservation) need a REAL registered client to resolve aud on rotation.
requireSource('private_html/auth/OAuthClientManager.php');

/**
 * 🎟️ TokenService Integration test suite.
 */
class TokenServiceTest extends DatabaseTestCase
{
    /**
     * Tables reset before each test (rolled back / truncated per DatabaseTestCase).
     *
     * @var array<int,string>
     */
    protected array $truncateTables = [
        'tblRefreshTokens',
        'tblRevokedTokens',
        'tblSecurityAlerts',
        'tblActivityLog',
        'tblOAuthClientRedirectUris',
        'tblOAuthClients',
        'tblPartners',
        'tblUsers',
    ];

    /** @var string kid of the in-memory signing key. */
    private string $kid;

    /** @var string Isolated temp dir for the key-file fallback (never written to). */
    private string $keyDir;

    // ========================================================================
    // ⚙️ SETUP / TEARDOWN
    // ========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        // 📚 Load the vendored JWT library.
        \JwtLibLoader::load();

        // ⚙️ JWT policy settings the facade / service read (via the bootstrap
        //    getSetting() stub — these do NOT touch tblSettings).
        setTestSetting('jwt.issuer', 'https://signula.id');
        setTestSetting('jwt.audience', 'https://signula.id/api');
        setTestSetting('jwt.access_ttl', 900);
        setTestSetting('jwt.refresh_ttl', 2592000);
        setTestSetting('jwt.leeway_seconds', 60);
        setTestSetting('jwt.key_bits', 2048); // fast key-gen for tests (≥ RSA floor)

        // 🧪 In-memory key store + isolated key dir → crypto is DB-independent.
        \KeyManager::resetOverrides();
        \KeyManager::setStorageOverride([]);
        $this->keyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'signula_ts_test_' . bin2hex(random_bytes(6));
        \KeyManager::setKeyDirOverride($this->keyDir);

        // 🔑 Mint + activate a signing key for this test.
        $this->kid = \KeyManager::generateKey(true);

        // 🚫 Start each test with no denylist checker installed.
        \Jwt::setDenylistChecker(null);
    }

    protected function tearDown(): void
    {
        \Jwt::setDenylistChecker(null);
        \KeyManager::resetOverrides();

        if (isset($this->keyDir) && is_dir($this->keyDir)) {
            foreach (glob($this->keyDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->keyDir);
        }

        parent::tearDown();
    }

    // ========================================================================
    // 🔧 FIXTURE HELPERS
    // ========================================================================

    /**
     * Create a minimal active user THROUGH Database:: so the code-under-test
     * (which also uses Database::) sees it on the same connection.
     *
     * @return int The new userID.
     */
    private function createUser(): int
    {
        $uuid     = self::generateTestUuid();
        $username = 'ts_' . bin2hex(random_bytes(4));
        $email    = 'ts_' . bin2hex(random_bytes(4)) . '@example.com';

        return \Database::insert(
            "INSERT INTO tblUsers
                (userUUID, username, email, passwordHash, displayName, accountStatus, emailVerified, mfaEnabled, createdAt)
             VALUES (?, ?, ?, ?, 'TS User', 'active', 1, 0, NOW())",
            [$uuid, $username, $email, password_hash('x', PASSWORD_ARGON2ID)],
            'ssss'
        );
    }

    /**
     * Count the family members for a given familyID.
     */
    private function familyCount(string $familyID): int
    {
        $row = \Database::fetchOne(
            "SELECT COUNT(*) AS c FROM tblRefreshTokens WHERE familyID = ?",
            [$familyID],
            's'
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Count the NON-revoked family members for a given familyID.
     */
    private function activeFamilyCount(string $familyID): int
    {
        $row = \Database::fetchOne(
            "SELECT COUNT(*) AS c FROM tblRefreshTokens WHERE familyID = ? AND revoked = 0",
            [$familyID],
            's'
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Register a real, active, confidential OAuth client (G-001 Stage A1) so
     * the clientID-preservation tests can resolve a REAL clientIdentifier via
     * OAuthClientManager::getClientByID() (exactly as TokenService::refresh()
     * does in production).
     *
     * @return array{clientID:int,clientIdentifier:string}
     */
    private function createClient(): array
    {
        $partnerID = \Database::insert(
            "INSERT INTO tblPartners (partnerName, partnerEmail, accountTier, isActive, isVerified, verifiedAt)
             VALUES (?, ?, 'premium', 1, 1, NOW())",
            ['TS Partner ' . bin2hex(random_bytes(4)), 'ts_partner_' . bin2hex(random_bytes(4)) . '@example.com'],
            'ss'
        );

        $result = \OAuthClientManager::registerClient(
            $partnerID,
            'TS Test RP',
            'confidential',
            ['https://rp.example.com/callback'],
            ['openid', 'profile']
        );

        $this->assertIsArray($result, 'client registration must succeed for this fixture');

        return [
            'clientID'         => (int) $result['clientID'],
            'clientIdentifier' => (string) $result['clientIdentifier'],
        ];
    }

    // ========================================================================
    // 🎁 ISSUANCE → VERIFY
    // ========================================================================

    /**
     * issueTokens() returns a well-formed pair and verifyAccessToken accepts it.
     */
    public function testIssueTokensProducesVerifiableAccessToken(): void
    {
        $userID = $this->createUser();

        $pair = \TokenService::issueTokens($userID, ['user:read', 'user:write']);

        // Shape.
        $this->assertIsString($pair['access_token']);
        $this->assertSame(3, substr_count($pair['access_token'], '.') + 1);
        $this->assertSame(64, strlen($pair['refresh_token']), 'Opaque refresh = 32 bytes hex = 64 chars');
        $this->assertSame('Bearer', $pair['token_type']);
        $this->assertSame(900, $pair['expires_in']);
        $this->assertSame('user:read user:write', $pair['scope']);
        $this->assertSame(32, strlen($pair['family_id']), 'familyID is 16 bytes hex');

        // 🔒 The plaintext refresh token is NEVER stored — only its SHA-256 hash.
        $plainRow = \Database::fetchOne(
            "SELECT COUNT(*) AS c FROM tblRefreshTokens WHERE tokenHash = ?",
            [$pair['refresh_token']],
            's'
        );
        $this->assertSame(0, (int) $plainRow['c'], 'Plaintext must never appear as a stored hash');

        $hashRow = \Database::fetchOne(
            "SELECT userID, familyID, revoked, rotatedAt FROM tblRefreshTokens WHERE tokenHash = ?",
            [hash('sha256', $pair['refresh_token'])],
            's'
        );
        $this->assertIsArray($hashRow, 'The SHA-256 hash of the refresh token IS stored');
        $this->assertSame($userID, (int) $hashRow['userID']);
        $this->assertSame(0, (int) $hashRow['revoked']);
        $this->assertNull($hashRow['rotatedAt']);

        // ✅ verifyAccessToken accepts the freshly-issued access token.
        $claims = \TokenService::verifyAccessToken($pair['access_token']);
        $this->assertSame((string) $userID, $claims['sub']);
        $this->assertSame('user:read user:write', $claims['scope']);
        $this->assertSame($pair['jti'], $claims['jti']);
    }

    // ========================================================================
    // 🔄 REFRESH — HAPPY PATH ROTATION
    // ========================================================================

    /**
     * refresh() rotates single-use: a NEW pair in the SAME family is issued, the
     * old token is marked rotated + linked to its replacement, and the old
     * refresh token can no longer be exchanged.
     */
    public function testRefreshRotatesAndInvalidatesOldToken(): void
    {
        $userID = $this->createUser();

        $first  = \TokenService::issueTokens($userID, ['user:read']);
        $family = $first['family_id'];

        // 🔄 Rotate.
        $second = \TokenService::refresh($first['refresh_token']);

        // New tokens, same family, scope carried forward.
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertSame($family, $second['family_id'], 'Rotation stays in the same family');
        $this->assertSame('user:read', $second['scope'], 'Scope carried through rotation');

        // Family now has 2 rows, both currently non-revoked.
        $this->assertSame(2, $this->familyCount($family));
        $this->assertSame(2, $this->activeFamilyCount($family));

        // Old row is marked rotated + replacedByID points at the successor.
        $oldRow = \Database::fetchOne(
            "SELECT rotatedAt, replacedByID, revokedReason FROM tblRefreshTokens WHERE tokenHash = ?",
            [hash('sha256', $first['refresh_token'])],
            's'
        );
        $this->assertNotNull($oldRow['rotatedAt'], 'Old token marked rotated');
        $this->assertNotNull($oldRow['replacedByID'], 'Old token linked to its replacement');
        $this->assertSame(\TokenService::REASON_ROTATED, $oldRow['revokedReason']);

        // ✅ The NEW refresh token works.
        $third = \TokenService::refresh($second['refresh_token']);
        $this->assertSame($family, $third['family_id']);

        // ❌ The FIRST (already-rotated) refresh token is now reuse — throws.
        //    (Covered in depth by the reuse test; here we just confirm the old
        //    token no longer yields a valid rotation.)
        $this->expectException(JwtException::class);
        \TokenService::refresh($first['refresh_token']);
    }

    // ========================================================================
    // 🚨 REUSE DETECTION — the critical anti-theft property
    // ========================================================================

    /**
     * 🚨 Presenting an ALREADY-ROTATED refresh token revokes the WHOLE FAMILY
     * and raises a security alert.
     *
     * Scenario: the legitimate client rotates (token A → B). An attacker who
     * stole token A replays it. TokenService must detect the reuse, revoke every
     * token in the family (so even B is dead), raise a HIGH-severity alert, and
     * deny the request.
     */
    public function testReusedRefreshTokenRevokesFamilyAndRaisesAlert(): void
    {
        $userID = $this->createUser();

        $tokenA = \TokenService::issueTokens($userID, ['user:read']);
        $family = $tokenA['family_id'];

        // Legitimate rotation: A → B (family now has 2 live tokens).
        $tokenB = \TokenService::refresh($tokenA['refresh_token']);
        $this->assertSame($family, $tokenB['family_id']);
        $this->assertSame(2, $this->activeFamilyCount($family), 'Both A and B live before the attack');

        $alertsBefore = $this->countRecords('tblSecurityAlerts');

        // 🦹 Attacker replays the SPENT token A → reuse detection must fire.
        $threw = false;
        try {
            \TokenService::refresh($tokenA['refresh_token']);
        } catch (JwtException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Replaying a spent refresh token must be denied');

        // 🧵 The ENTIRE family is now revoked (0 live tokens) — including B.
        $this->assertSame(0, $this->activeFamilyCount($family), 'Reuse revokes the whole family');

        // All family rows carry the reuse_detected reason.
        $reasons = \Database::fetchAll(
            "SELECT revokedReason FROM tblRefreshTokens WHERE familyID = ?",
            [$family],
            's'
        );
        foreach ($reasons as $r) {
            $this->assertSame(\TokenService::REASON_REUSE_DETECTED, $r['revokedReason']);
        }

        // 🚨 A security alert was raised for the theft.
        $alertsAfter = $this->countRecords('tblSecurityAlerts');
        $this->assertSame($alertsBefore + 1, $alertsAfter, 'Reuse must raise exactly one security alert');

        $alert = \Database::fetchOne(
            "SELECT alertType, severity, userID, metadata
             FROM tblSecurityAlerts ORDER BY alertID DESC LIMIT 1",
            [],
            ''
        );
        $this->assertSame(\SecurityAlertManager::SEVERITY_HIGH, $alert['severity']);
        $this->assertSame($userID, (int) $alert['userID']);
        $this->assertStringContainsString('refresh_token_reuse', (string) $alert['metadata']);

        // ✅ Now that the family is dead, EVEN token B (the legitimately-rotated
        //    token) can no longer be used — the theft locked everyone out.
        $this->expectException(JwtException::class);
        \TokenService::refresh($tokenB['refresh_token']);
    }

    /**
     * Presenting a token from an explicitly-revoked family is also reuse → deny.
     */
    public function testRefreshOnRevokedFamilyDenied(): void
    {
        $userID = $this->createUser();
        $pair   = \TokenService::issueTokens($userID, ['user:read']);

        // Revoke the family directly (e.g. a logout).
        $revoked = \TokenService::revokeFamily($pair['family_id'], \TokenService::REASON_LOGOUT);
        $this->assertSame(1, $revoked);

        $this->expectException(JwtException::class);
        \TokenService::refresh($pair['refresh_token']);
    }

    // ========================================================================
    // 🗑️ ACCESS-TOKEN REVOCATION (jti denylist)
    // ========================================================================

    /**
     * Revoking an access token's jti causes verifyAccessToken to reject it.
     */
    public function testRevokedJtiRejectedByVerify(): void
    {
        $userID = $this->createUser();
        $pair   = \TokenService::issueTokens($userID, ['user:read']);

        // ✅ Accepted before revocation.
        $claims = \TokenService::verifyAccessToken($pair['access_token']);
        $this->assertSame((string) $userID, $claims['sub']);

        // 🚫 Revoke this exact access token by jti.
        \TokenService::revokeAccessJti($pair['jti'], $userID, $claims['exp']);

        // The denylist row exists and is unexpired.
        $this->assertTrue(\TokenService::isJtiRevoked($pair['jti']));

        // ❌ Now rejected by verifyAccessToken (denylist wired into verify).
        $this->expectException(JwtException::class);
        \TokenService::verifyAccessToken($pair['access_token']);
    }

    /**
     * A different (non-revoked) token is still accepted while a denylist exists.
     */
    public function testUnrevokedJtiStillAccepted(): void
    {
        $userID = $this->createUser();

        $a = \TokenService::issueTokens($userID, ['user:read']);
        $b = \TokenService::issueTokens($userID, ['user:read']);

        // Revoke only A.
        \TokenService::revokeAccessJti($a['jti'], $userID);

        // B still verifies.
        $claims = \TokenService::verifyAccessToken($b['access_token']);
        $this->assertSame((string) $userID, $claims['sub']);
    }

    // ========================================================================
    // 🌐 revokeAllForUser → tokensInvalidBefore cutoff
    // ========================================================================

    /**
     * revokeAllForUser() bumps tokensInvalidBefore so an access token issued
     * BEFORE the cutoff is rejected, while one issued AFTER is accepted.
     */
    public function testRevokeAllForUserRejectsOlderAccessTokens(): void
    {
        $userID = $this->createUser();

        // 🕰️ Craft an access token whose iat is safely in the past (10 min ago)
        //    by signing directly with the active key, so it is unambiguously
        //    older than a NOW() cutoff regardless of second-level timing.
        $past = time() - 600;
        $oldToken = $this->signRawWithActiveKey([
            'sub'   => (string) $userID,
            'iss'   => 'https://signula.id',
            'aud'   => 'https://signula.id/api',
            'iat'   => $past,
            'nbf'   => $past,
            'exp'   => time() + 900,
            'jti'   => bin2hex(random_bytes(16)),
            'scope' => 'user:read',
        ]);

        // ✅ Accepted before mass-revoke.
        $this->assertSame((string) $userID, \TokenService::verifyAccessToken($oldToken)['sub']);

        // 🌐 "Log out everywhere" — sets tokensInvalidBefore = NOW().
        \TokenService::revokeAllForUser($userID);

        // The cutoff column is populated.
        $u = \Database::fetchOne(
            "SELECT tokensInvalidBefore FROM tblUsers WHERE userID = ?",
            [$userID],
            'i'
        );
        $this->assertNotEmpty($u['tokensInvalidBefore']);

        // A NEWLY-issued token (iat = now ≥ cutoff) is still accepted.
        $fresh = \TokenService::issueTokens($userID, ['user:read']);
        $this->assertSame((string) $userID, \TokenService::verifyAccessToken($fresh['access_token'])['sub']);

        // ❌ The OLD token (iat in the past < cutoff) is now rejected.
        $this->expectException(JwtException::class);
        \TokenService::verifyAccessToken($oldToken);
    }

    /**
     * revokeAllForUser() also revokes the user's refresh tokens.
     */
    public function testRevokeAllForUserRevokesRefreshTokens(): void
    {
        $userID = $this->createUser();
        $pair   = \TokenService::issueTokens($userID, ['user:read']);

        \TokenService::revokeAllForUser($userID);

        // The refresh token is now dead (its family was revoked).
        $this->expectException(JwtException::class);
        \TokenService::refresh($pair['refresh_token']);
    }

    // ========================================================================
    // ⏳ EXPIRED REFRESH TOKEN
    // ========================================================================

    /**
     * An expired (but never rotated/revoked) refresh token is DENIED — and it is
     * treated as aged-out, NOT as reuse (no family revoke, no alert).
     */
    public function testExpiredRefreshTokenDenied(): void
    {
        $userID = $this->createUser();
        $pair   = \TokenService::issueTokens($userID, ['user:read']);
        $family = $pair['family_id'];

        // ⏳ Force the row to be expired (issued/expired in the past) directly.
        \Database::query(
            "UPDATE tblRefreshTokens
             SET issuedAt = DATE_SUB(NOW(), INTERVAL 40 DAY),
                 expiresAt = DATE_SUB(NOW(), INTERVAL 10 DAY)
             WHERE tokenHash = ?",
            [hash('sha256', $pair['refresh_token'])],
            's'
        );

        $alertsBefore = $this->countRecords('tblSecurityAlerts');

        // ❌ Denied.
        $threw = false;
        try {
            \TokenService::refresh($pair['refresh_token']);
        } catch (JwtException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Expired refresh token must be denied');

        // 🟢 It was aged out, not reused: the family is NOT mass-revoked and NO
        //    alert was raised.
        $this->assertSame(1, $this->activeFamilyCount($family), 'Expiry must not revoke the family');
        $this->assertSame(
            $alertsBefore,
            $this->countRecords('tblSecurityAlerts'),
            'Expiry must not raise a reuse alert'
        );
    }

    /**
     * An entirely unknown refresh token is denied.
     */
    public function testUnknownRefreshTokenDenied(): void
    {
        $this->expectException(JwtException::class);
        \TokenService::refresh(bin2hex(random_bytes(32)));
    }

    // ========================================================================
    // 🪪 G-001 STAGE A3 GOTCHA FIXES — issueForClient() + clientID preservation
    // ========================================================================

    /**
     * issueForClient() mints an access token whose `aud` is the CLIENT's
     * clientIdentifier (not the default jwt.audience), and the refresh row
     * persists clientID (the column reserved since migration 030).
     */
    public function testIssueForClientSetsClientAudienceAndPersistsClientId(): void
    {
        $userID = $this->createUser();
        $client = $this->createClient();

        // 🔧 G-001 Stage A5: refresh-token MINTING is now gated behind the
        //    `offline_access` scope — include it so this fixture still gets a
        //    refresh row to assert on (this test predates that gating).
        $pair = \TokenService::issueForClient($userID, $client['clientID'], $client['clientIdentifier'], ['openid', 'profile', 'offline_access']);

        // ✅ The access token verifies ONLY against the CLIENT's audience —
        //    not the default first-party jwt.audience.
        $claims = \Jwt::verify($pair['access_token'], ['aud' => $client['clientIdentifier']]);
        $this->assertSame((string) $userID, $claims['sub']);

        try {
            \Jwt::verify($pair['access_token']); // default aud (jwt.audience) must NOT match
            $this->fail('An RP-issued access token must NOT verify against the default first-party audience');
        } catch (JwtException $e) {
            // expected — aud mismatch
        }

        // 💾 The refresh row records the RP's clientID (previously always NULL).
        $row = \Database::fetchOne(
            "SELECT clientID FROM tblRefreshTokens WHERE tokenHash = ?",
            [hash('sha256', $pair['refresh_token'])],
            's'
        );
        $this->assertSame($client['clientID'], (int) $row['clientID']);
    }

    /**
     * refresh() on an RP-issued token PRESERVES both the audience (re-resolved
     * fresh from the client's clientIdentifier) and the clientID linkage on
     * the newly-minted refresh row — an RP's rotated token must still target
     * the SAME resource server and stay attributable to the SAME client.
     */
    public function testRefreshPreservesClientIdAndAudienceAcrossRotation(): void
    {
        $userID = $this->createUser();
        $client = $this->createClient();

        // 🔧 G-001 Stage A5: offline_access is required to get a refresh token
        //    at all now (mintPair()'s $withRefresh gate).
        $first = \TokenService::issueForClient($userID, $client['clientID'], $client['clientIdentifier'], ['openid', 'offline_access']);

        // 🔒 B-058: refresh() now enforces client-binding — pass the OWNING
        //    client's id as $authenticatedClientID (exactly what
        //    OAuthTokenService::exchangeRefreshToken() does in production)
        //    so this rotation is the legitimate, correctly-authenticated case.
        $second = \TokenService::refresh($first['refresh_token'], $client['clientID']);

        // ✅ The ROTATED access token still verifies against the SAME client
        //    audience — the clientID → clientIdentifier resolution survived
        //    rotation.
        $claims = \Jwt::verify($second['access_token'], ['aud' => $client['clientIdentifier']]);
        $this->assertSame((string) $userID, $claims['sub']);

        // 💾 The NEW refresh row also carries the same clientID.
        $row = \Database::fetchOne(
            "SELECT clientID FROM tblRefreshTokens WHERE tokenHash = ?",
            [hash('sha256', $second['refresh_token'])],
            's'
        );
        $this->assertSame($client['clientID'], (int) $row['clientID']);
    }

    /**
     * A first-party token (issueTokens(), no client) is completely unaffected
     * by the clientID threading — clientID stays NULL and refresh() behaves
     * exactly as before (default audience).
     */
    public function testFirstPartyTokenClientIdStaysNullThroughRefresh(): void
    {
        $userID = $this->createUser();

        $pair = \TokenService::issueTokens($userID, ['user:read']);

        $row = \Database::fetchOne(
            "SELECT clientID FROM tblRefreshTokens WHERE tokenHash = ?",
            [hash('sha256', $pair['refresh_token'])],
            's'
        );
        $this->assertNull($row['clientID'], 'a first-party token must never gain a clientID');

        $rotated = \TokenService::refresh($pair['refresh_token']);
        $row2 = \Database::fetchOne(
            "SELECT clientID FROM tblRefreshTokens WHERE tokenHash = ?",
            [hash('sha256', $rotated['refresh_token'])],
            's'
        );
        $this->assertNull($row2['clientID'], 'clientID must stay NULL across rotation for a first-party token');

        // Still verifies against the DEFAULT (first-party) audience.
        $claims = \Jwt::verify($rotated['access_token']);
        $this->assertSame((string) $userID, $claims['sub']);
    }

    // ========================================================================
    // 🔒 G-001 STAGE A5 (B-058) — CLIENT-BINDING ENFORCEMENT ON refresh()
    // ========================================================================

    /**
     * An RP-bound refresh token presented with NO authenticated client
     * (exactly what /api/v1/auth/refresh — the first-party endpoint — would
     * do) is REJECTED, and the token is NOT rotated/spent (it can still be
     * redeemed by the CORRECT client afterwards).
     */
    public function testRpBoundRefreshTokenRejectedWithNoAuthenticatedClient(): void
    {
        $userID = $this->createUser();
        $client = $this->createClient();

        $pair = \TokenService::issueForClient($userID, $client['clientID'], $client['clientIdentifier'], ['openid', 'offline_access']);

        $threw = false;
        try {
            \TokenService::refresh($pair['refresh_token']); // no $authenticatedClientID
        } catch (JwtException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'An RP-bound refresh token presented with no client auth must be rejected');

        // 🟢 NOT spent — the family is still fully live (no rotation happened).
        $this->assertSame(1, $this->activeFamilyCount($pair['family_id']));

        // ✅ The CORRECT client can still redeem it afterwards.
        $rotated = \TokenService::refresh($pair['refresh_token'], $client['clientID']);
        $this->assertIsString($rotated['access_token']);
    }

    /**
     * An RP-bound refresh token presented by the WRONG (but validly resolved)
     * client is REJECTED, and — critically — does NOT revoke the family (a
     * binding mismatch is an authz failure, not proof of theft); the owning
     * client can still redeem the SAME still-live token afterwards.
     */
    public function testRpBoundRefreshTokenRejectedForWrongClient(): void
    {
        $userID  = $this->createUser();
        $clientA = $this->createClient();
        $clientB = $this->createClient();

        $pair = \TokenService::issueForClient($userID, $clientA['clientID'], $clientA['clientIdentifier'], ['openid', 'offline_access']);

        $alertsBefore = $this->countRecords('tblSecurityAlerts');

        $threw = false;
        try {
            \TokenService::refresh($pair['refresh_token'], $clientB['clientID']); // wrong client
        } catch (JwtException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'A refresh token presented by the WRONG client must be rejected');

        // 🟢 A mismatch alert MAY be raised, but the family must NOT be revoked
        //    (unlike genuine reuse) — the legitimate client A can still use it.
        $this->assertSame(1, $this->activeFamilyCount($pair['family_id']), 'a client mismatch must NOT revoke the family');
        $this->assertGreaterThanOrEqual($alertsBefore, $this->countRecords('tblSecurityAlerts'));

        // ✅ Client A (the ACTUAL owner) can still redeem the SAME token.
        $rotated = \TokenService::refresh($pair['refresh_token'], $clientA['clientID']);
        $this->assertIsString($rotated['access_token']);
    }

    /**
     * An RP-bound refresh token presented WITH the CORRECT client id rotates
     * normally, and the newly-minted successor token is STILL bound to the
     * SAME client (the binding survives rotation, exactly like clientID/aud
     * preservation already does).
     */
    public function testRpBoundRefreshTokenRotatesForCorrectClientAndStaysBound(): void
    {
        $userID = $this->createUser();
        $client = $this->createClient();

        $first = \TokenService::issueForClient($userID, $client['clientID'], $client['clientIdentifier'], ['openid', 'offline_access']);

        $second = \TokenService::refresh($first['refresh_token'], $client['clientID']);
        $this->assertIsString($second['access_token']);

        $row = \Database::fetchOne(
            "SELECT clientID FROM tblRefreshTokens WHERE tokenHash = ?",
            [hash('sha256', $second['refresh_token'])],
            's'
        );
        $this->assertSame($client['clientID'], (int) $row['clientID'], 'the ROTATED token must still be bound to the same client');

        // ✅ The chain continues: client can rotate again.
        $third = \TokenService::refresh($second['refresh_token'], $client['clientID']);
        $this->assertIsString($third['access_token']);
    }

    /**
     * A first-party refresh token (issueTokens(), no client) still rotates
     * via refresh($tok) with NO $authenticatedClientID — completely unchanged
     * by the B-058 binding check (null === null is a match).
     */
    public function testFirstPartyRefreshTokenStillRotatesUnchanged(): void
    {
        $userID = $this->createUser();
        $pair    = \TokenService::issueTokens($userID, ['user:read']);

        $rotated = \TokenService::refresh($pair['refresh_token']);
        $this->assertIsString($rotated['access_token']);
        $this->assertNotSame($pair['refresh_token'], $rotated['refresh_token']);
    }

    /**
     * A first-party refresh token presented with a (bogus) authenticated
     * client id is rejected — a first-party token has no business being
     * redeemed with client credentials attached at all.
     */
    public function testFirstPartyRefreshTokenRejectedWhenAnAuthenticatedClientIsPresented(): void
    {
        $userID = $this->createUser();
        $pair   = \TokenService::issueTokens($userID, ['user:read']);

        $this->expectException(JwtException::class);
        \TokenService::refresh($pair['refresh_token'], 999999);
    }

    // ========================================================================
    // 🔐 G-001 STAGE A5 — offline_access GATING OF REFRESH-TOKEN MINTING
    // ========================================================================

    /**
     * issueForClient() WITHOUT `offline_access` in the granted scope mints
     * NO refresh token at all (null, not merely withheld from a response) —
     * still returns a perfectly usable access token.
     */
    public function testIssueForClientWithoutOfflineAccessMintsNoRefreshToken(): void
    {
        $userID = $this->createUser();
        $client = $this->createClient();

        $pair = \TokenService::issueForClient($userID, $client['clientID'], $client['clientIdentifier'], ['openid', 'profile']);

        $this->assertIsString($pair['access_token']);
        $this->assertNull($pair['refresh_token'], 'no offline_access scope => no refresh token minted');

        // 💾 No row was persisted for this family at all.
        $this->assertSame(0, $this->familyCount($pair['family_id']));
    }

    /**
     * issueForClient() WITH `offline_access` in the granted scope DOES mint
     * a refresh token (the existing, already-covered happy path — reasserted
     * here explicitly alongside its "without" sibling for contrast).
     */
    public function testIssueForClientWithOfflineAccessMintsRefreshToken(): void
    {
        $userID = $this->createUser();
        $client = $this->createClient();

        $pair = \TokenService::issueForClient($userID, $client['clientID'], $client['clientIdentifier'], ['openid', 'offline_access']);

        $this->assertIsString($pair['access_token']);
        $this->assertIsString($pair['refresh_token']);
        $this->assertSame(64, strlen($pair['refresh_token']));
        $this->assertSame(1, $this->familyCount($pair['family_id']));
    }

    /**
     * issueTokens() (first-party) is COMPLETELY UNAFFECTED by the
     * offline_access gate — it always mints a refresh token, scope or not.
     */
    public function testIssueTokensFirstPartyAlwaysMintsRefreshTokenRegardlessOfScope(): void
    {
        $userID = $this->createUser();

        $pair = \TokenService::issueTokens($userID, ['user:read']); // no offline_access

        $this->assertIsString($pair['refresh_token']);
        $this->assertSame(64, strlen($pair['refresh_token']));
    }

    // ========================================================================
    // 🔧 TEST HELPER
    // ========================================================================

    /**
     * Sign an arbitrary claim set with the active test key via the library
     * directly, so tests can mint tokens with deliberately-old iat values.
     *
     * @param array<string,mixed> $claims
     * @return string
     */
    private function signRawWithActiveKey(array $claims): string
    {
        $active = \KeyManager::getActiveKey();
        return \Firebase\JWT\JWT::encode(
            $claims,
            $active['private_pem'],
            'RS256',
            $active['kid'],
            ['typ' => 'at+jwt']
        );
    }
}
