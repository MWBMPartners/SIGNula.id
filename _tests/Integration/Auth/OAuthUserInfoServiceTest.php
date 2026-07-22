<?php
/**
 * ============================================================================
 * 🧪 OAuthUserInfoService Integration Tests (G-001 Stage A4 — /oauth/userinfo)
 * ============================================================================
 *
 * DB-bound tests against the real `signula_test` database. Mints REAL access
 * tokens for a REAL registered RP client via
 * {@see \TokenService::issueForClient()} (the exact minting path
 * `/oauth/token` uses — Stage A3) and drives
 * {@see \OAuthUserInfoService::handleUserInfoRequest()} — the engine behind
 * `/oauth/userinfo` — end to end:
 *
 *   • SCOPE GATING: 'openid email' → sub + email/email_verified, NEVER
 *     profile claims; 'openid profile' → profile claims, NEVER email;
 *     'openid' alone → sub only.
 *   • The returned `sub` byte-matches the id_token's `sub` for the same
 *     user+client (OIDC Core §5.3.2 — both are the SAME pairwise subject).
 *   • REJECTIONS (all 401 invalid_token, `WWW-Authenticate: Bearer
 *     error="invalid_token"`): no token; a first-party token (aud =
 *     jwt.audience, no RP client row); an expired token; a token whose aud
 *     is an INACTIVE client.
 *
 * @package    SIGNula\Tests\Integration\Auth
 * @version    2.9.0-beta
 * @see        web/private_html/auth/OAuthUserInfoService.php
 * @see        web/public_html/oauth/userinfo.php
 * @see        .dev-team/specs/G-001.md §5
 * @see        _tests/Integration/Auth/OAuthTokenServiceTest.php (fixture-helper style this mirrors)
 */

namespace SIGNula\Tests\Integration\Auth;

use SIGNula\Tests\DatabaseTestCase;
use JwtException;
use KeyManager;
use Jwt;
use TokenService;
use OAuthClientManager;
use OAuthUserInfoService;

// ============================================================================
// 📦 LOAD REQUIRED SOURCE FILES (dependency order)
// ============================================================================
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
requireSource('private_html/auth/OAuthClientManager.php');
requireSource('private_html/auth/OAuthUserInfoService.php');

/**
 * 🪪 OAuthUserInfoService Integration test suite.
 */
class OAuthUserInfoServiceTest extends DatabaseTestCase
{
    /**
     * Tables reset before each test.
     *
     * @var array<int,string>
     */
    protected array $truncateTables = [
        'tblOAuthAccessGrants',
        'tblOAuthConsents',
        'tblOAuthAuthCodes',
        'tblOAuthSubjects',
        'tblOAuthClientRedirectUris',
        'tblOAuthClients',
        'tblRefreshTokens',
        'tblRevokedTokens',
        'tblSecurityAlerts',
        'tblActivityLog',
        'tblUsers',
        'tblPartners',
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

        \JwtLibLoader::load();

        setTestSetting('jwt.issuer', 'https://signula.id');
        setTestSetting('jwt.audience', 'https://signula.id/api');
        setTestSetting('jwt.access_ttl', 900);
        setTestSetting('jwt.refresh_ttl', 2592000);
        setTestSetting('jwt.leeway_seconds', 60);
        setTestSetting('jwt.key_bits', 2048); // fast key-gen for tests

        setTestSetting('oidc.issuer', 'https://signula.id');
        setTestSetting('oidc.id_token_ttl', 3600);

        \KeyManager::resetOverrides();
        \KeyManager::setStorageOverride([]);
        $this->keyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'signula_uis_test_' . bin2hex(random_bytes(6));
        \KeyManager::setKeyDirOverride($this->keyDir);

        $this->kid = \KeyManager::generateKey(true);

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

    private function createPartner(): int
    {
        $suffix = bin2hex(random_bytes(4));

        return \Database::insert(
            "INSERT INTO tblPartners (partnerName, partnerEmail, accountTier, isActive, isVerified, verifiedAt)
             VALUES (?, ?, 'premium', 1, 1, NOW())",
            ['UIS Partner ' . $suffix, 'uis_partner_' . $suffix . '@example.com'],
            'ss'
        );
    }

    private function createUser(): int
    {
        $uuid     = self::generateTestUuid();
        $username = 'uis_' . bin2hex(random_bytes(4));
        $email    = 'uis_' . bin2hex(random_bytes(4)) . '@example.com';

        return \Database::insert(
            "INSERT INTO tblUsers
                (userUUID, username, email, passwordHash, displayName, firstName, lastName, accountStatus, emailVerified, mfaEnabled, createdAt)
             VALUES (?, ?, ?, ?, 'UIS User', 'UIS', 'User', 'active', 1, 0, NOW())",
            [$uuid, $username, $email, password_hash('x', PASSWORD_ARGON2ID)],
            'ssss'
        );
    }

    /**
     * @return array{clientID:int,clientIdentifier:string,client:array}
     */
    private function registerClient(?int $partnerID, string $scopes = 'openid profile email'): array
    {
        $result = \OAuthClientManager::registerClient(
            $partnerID,
            'UIS Test RP',
            'confidential',
            ['https://rp.example.com/callback'],
            explode(' ', $scopes)
        );
        $this->assertIsArray($result, 'confidential client fixture registration must succeed');
        $client = \OAuthClientManager::getClient($result['clientIdentifier']);

        return [
            'clientID'         => (int) $result['clientID'],
            'clientIdentifier' => $result['clientIdentifier'],
            'client'           => $client,
        ];
    }

    /**
     * 🕵️ B-062: register a client with subjectType='public' (the non-default
     * OIDC subject policy whose collision this fixture exercises —
     * OAuthClientManager::computeSubject() returns the raw, client-UNSCOPED
     * userID for a 'public' client, unlike the default 'pairwise' sector-
     * scoped hash).
     *
     * @return array{clientID:int,clientIdentifier:string,client:array}
     */
    private function registerPublicClient(?int $partnerID, string $scopes = 'openid profile email'): array
    {
        $result = \OAuthClientManager::registerClient(
            $partnerID,
            'UIS Public-Subject Test RP',
            'confidential',
            ['https://rp.example.com/callback'],
            explode(' ', $scopes),
            ['subjectType' => 'public']
        );
        $this->assertIsArray($result, 'public-subject client fixture registration must succeed');
        $client = \OAuthClientManager::getClient($result['clientIdentifier']);

        return [
            'clientID'         => (int) $result['clientID'],
            'clientIdentifier' => $result['clientIdentifier'],
            'client'           => $client,
        ];
    }

    /**
     * Mint a REAL RP access token (Stage A3's minting path) with the given
     * scope, for the given user+client.
     */
    private function mintAccessToken(int $userID, array $reg, string $scope): string
    {
        $pair = \TokenService::issueForClient(
            $userID,
            $reg['clientID'],
            $reg['clientIdentifier'],
            explode(' ', $scope)
        );

        return $pair['access_token'];
    }

    // ========================================================================
    // 🏷️ SCOPE GATING
    // ========================================================================

    /**
     * 'openid email' → sub + email/email_verified, NEVER profile claims.
     */
    public function testScopeGatingEmailOnlyReturnsSubAndEmailNotProfile(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerClient($partnerID);

        $accessToken = $this->mintAccessToken($userID, $reg, 'openid email');

        $result = \OAuthUserInfoService::handleUserInfoRequest($accessToken);

        $this->assertSame(200, $result['status']);
        $body = $result['body'];

        $this->assertArrayHasKey('sub', $body);
        $this->assertArrayHasKey('email', $body);
        $this->assertArrayHasKey('email_verified', $body);
        $this->assertIsBool($body['email_verified']);

        // 🔒 NO profile claims — 'profile' scope was never granted.
        $this->assertArrayNotHasKey('name', $body);
        $this->assertArrayNotHasKey('given_name', $body);
        $this->assertArrayNotHasKey('family_name', $body);
        $this->assertArrayNotHasKey('preferred_username', $body);
        $this->assertArrayNotHasKey('picture', $body);
    }

    /**
     * 'openid profile' → profile claims, NEVER email.
     */
    public function testScopeGatingProfileOnlyReturnsProfileNotEmail(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerClient($partnerID);

        $accessToken = $this->mintAccessToken($userID, $reg, 'openid profile');

        $result = \OAuthUserInfoService::handleUserInfoRequest($accessToken);

        $this->assertSame(200, $result['status']);
        $body = $result['body'];

        $this->assertArrayHasKey('sub', $body);
        $this->assertSame('UIS User', $body['name']);
        $this->assertSame('UIS', $body['given_name']);
        $this->assertSame('User', $body['family_name']);
        $this->assertArrayHasKey('preferred_username', $body);

        // 🔒 NO email claims — 'email' scope was never granted.
        $this->assertArrayNotHasKey('email', $body);
        $this->assertArrayNotHasKey('email_verified', $body);
    }

    /**
     * 'openid' alone → sub ONLY, no email/profile claims at all.
     */
    public function testScopeGatingOpenidOnlyReturnsSubOnly(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerClient($partnerID, 'openid');

        $accessToken = $this->mintAccessToken($userID, $reg, 'openid');

        $result = \OAuthUserInfoService::handleUserInfoRequest($accessToken);

        $this->assertSame(200, $result['status']);
        $this->assertSame(['sub'], array_keys($result['body']), 'openid-only scope must yield ONLY the sub claim');
    }

    // ========================================================================
    // 🕵️ SUB MATCHES ID_TOKEN SUB
    // ========================================================================

    /**
     * userinfo's `sub` MUST byte-match the id_token's `sub` for the same
     * user+client (OIDC Core §5.3.2).
     */
    public function testUserInfoSubMatchesIdTokenSub(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerClient($partnerID, 'openid');
        $client    = $reg['client'];

        $accessToken = $this->mintAccessToken($userID, $reg, 'openid');
        $result = \OAuthUserInfoService::handleUserInfoRequest($accessToken);
        $this->assertSame(200, $result['status']);

        // Mint an id_token exactly the way OAuthTokenService does at Stage A3.
        $expectedSub = \OAuthClientManager::computeSubject($userID, $client);
        $idToken = \Jwt::signIdToken(['sub' => $expectedSub, 'auth_time' => time()], $client['clientIdentifier']);
        $idClaims = \Jwt::verify($idToken, ['typ' => 'JWT', 'aud' => $client['clientIdentifier']]);

        $this->assertSame($idClaims['sub'], $result['body']['sub'], 'userinfo sub must byte-match the id_token sub');
        $this->assertSame($expectedSub, $result['body']['sub']);
        $this->assertNotSame((string) $userID, $result['body']['sub'], 'pairwise sub must NOT be the raw userID');
    }

    // ========================================================================
    // 🕵️ B-062 — public-subject clients: userinfo resolves BOTH independently
    // ========================================================================

    /**
     * 🕵️ B-062 regression: TWO different subjectType='public' clients for the
     * SAME user compute the EXACT SAME subjectHash (a 'public' subject is the
     * raw userID, unscoped by client). Before migration 035's composite
     * UNIQUE key, the SECOND issuance's UPSERT collided on that shared
     * subjectHash and OVERWROTE the FIRST client's row — after which
     * userinfo for the first client's (still perfectly valid) access token
     * 401'd. Both clients must now resolve correctly and independently.
     */
    public function testPublicSubjectClientsResolveIndependentlyViaUserinfo(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();

        $regA = $this->registerPublicClient($partnerID, 'openid profile');
        $regB = $this->registerPublicClient($partnerID, 'openid profile');

        // Mint client A's token FIRST, then client B's — the ORDER matters:
        // under the old bug, B's UPSERT (issued second) would overwrite A's
        // row, so A's userinfo call would 401 if checked AFTER B is minted.
        $tokenA = $this->mintAccessToken($userID, $regA, 'openid profile');
        $tokenB = $this->mintAccessToken($userID, $regB, 'openid profile');

        $resultA = \OAuthUserInfoService::handleUserInfoRequest($tokenA);
        $resultB = \OAuthUserInfoService::handleUserInfoRequest($tokenB);

        $this->assertSame(200, $resultA['status'], 'client A userinfo must still resolve, not 401 (B-062 regression)');
        $this->assertSame(200, $resultB['status'], 'client B userinfo must resolve');

        // Both resolve to the SAME underlying user (same sub value — both are
        // 'public' subjectType) and both correctly recover the profile claims.
        $this->assertSame((string) $userID, $resultA['body']['sub']);
        $this->assertSame((string) $userID, $resultB['body']['sub']);
        $this->assertSame($resultA['body']['sub'], $resultB['body']['sub'], 'public sub is unscoped by client');
        $this->assertSame('UIS User', $resultA['body']['name']);
        $this->assertSame('UIS User', $resultB['body']['name']);

        // 💾 Confirm the underlying storage-level fix directly too: TWO
        //    separate tblOAuthSubjects rows, one per client, both pointing
        //    at the correct userID.
        $rows = \Database::fetchAll(
            "SELECT clientID, userID FROM tblOAuthSubjects WHERE subjectHash = ? ORDER BY clientID ASC",
            [(string) $userID],
            's'
        );
        $this->assertCount(2, $rows, 'two public clients must leave two separate subject rows');
        foreach ($rows as $row) {
            $this->assertSame($userID, (int) $row['userID']);
        }
    }

    // ========================================================================
    // 🚫 REJECTIONS
    // ========================================================================

    /**
     * No Bearer token presented → 401 invalid_token.
     */
    public function testNoTokenRejected(): void
    {
        $result = \OAuthUserInfoService::handleUserInfoRequest(null);

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_token', $result['body']['error']);
        $this->assertSame('Bearer error="invalid_token"', $result['headers']['WWW-Authenticate']);
    }

    /**
     * A FIRST-PARTY token (aud = jwt.audience default, no RP client row) is
     * refused — userinfo is an RP-facing OIDC surface, not a general
     * resource-server endpoint.
     */
    public function testFirstPartyTokenRejected(): void
    {
        $userID = $this->createUser();

        $pair = \TokenService::issueTokens($userID, ['user:read']);

        $result = \OAuthUserInfoService::handleUserInfoRequest($pair['access_token']);

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_token', $result['body']['error']);
    }

    /**
     * An EXPIRED access token is rejected.
     */
    public function testExpiredTokenRejected(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerClient($partnerID, 'openid');

        // 🕰️ Mint with a TTL far enough in the past that it is expired even
        //    after the 60s leeway (jwt.leeway_seconds) is applied — a small
        //    negative TTL (e.g. -10) would still verify OK, since
        //    exp + leeway (-10 + 60 = +50s) is still in the future.
        setTestSetting('jwt.access_ttl', -120);
        $accessToken = $this->mintAccessToken($userID, $reg, 'openid');
        setTestSetting('jwt.access_ttl', 900);

        $result = \OAuthUserInfoService::handleUserInfoRequest($accessToken);

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_token', $result['body']['error']);
    }

    /**
     * A token whose aud resolves to an INACTIVE client is rejected.
     */
    public function testInactiveClientRejected(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerClient($partnerID, 'openid');

        $accessToken = $this->mintAccessToken($userID, $reg, 'openid');

        // 🚫 Deactivate the client AFTER the token was minted.
        \OAuthClientManager::deactivate($reg['clientID']);

        $result = \OAuthUserInfoService::handleUserInfoRequest($accessToken);

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_token', $result['body']['error']);
    }

    /**
     * A token whose aud is an entirely UNKNOWN client is rejected.
     */
    public function testUnknownClientTokenRejected(): void
    {
        $userID = $this->createUser();

        // Mint via Jwt::sign() directly with a made-up RP-shaped aud.
        $bogusAud = bin2hex(random_bytes(16));
        $accessToken = \Jwt::sign(['sub' => (string) $userID, 'scope' => 'openid'], $bogusAud, 900);

        $result = \OAuthUserInfoService::handleUserInfoRequest($accessToken);

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_token', $result['body']['error']);
    }
}
