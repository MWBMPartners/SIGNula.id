<?php
/**
 * ============================================================================
 * 🧪 OAuthRevocationService Integration Tests (G-001 Stage A4 — /oauth/revoke)
 * ============================================================================
 *
 * DB-bound tests against the real `signula_test` database. Registers REAL
 * clients (Stage A1) and mints REAL access+refresh pairs via
 * {@see \TokenService::issueForClient()} (Stage A3's minting path), then
 * drives {@see \OAuthRevocationService::handleRevokeRequest()} — the engine
 * behind `/oauth/revoke` (RFC 7009) — end to end:
 *
 *   • The OWNING client can revoke ITS OWN refresh-token family (a
 *     subsequent TokenService::refresh() then fails) and its OWN access
 *     token's jti (a subsequent TokenService::verifyAccessToken() then fails).
 *   • A DIFFERENT (authenticated, valid-credential) client CANNOT revoke
 *     another client's token — the response is STILL 200 (no validity
 *     oracle), but the token is UNTOUCHED and keeps working.
 *   • A confidential client with the WRONG secret is refused 401
 *     invalid_client (client-auth failure — the one signal allowed to differ).
 *   • A PUBLIC client can revoke its own token with NO secret at all.
 *   • An entirely UNKNOWN token is a silent 200 no-op.
 *
 * @package    SIGNula\Tests\Integration\Auth
 * @version    2.9.0-beta
 * @see        web/private_html/auth/OAuthRevocationService.php
 * @see        web/public_html/oauth/revoke.php
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
use OAuthTokenService;
use OAuthRevocationService;

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
requireSource('private_html/auth/OAuthAuthorizeService.php');
requireSource('private_html/auth/OAuthTokenService.php');
requireSource('private_html/auth/OAuthRevocationService.php');

/**
 * 🪪 OAuthRevocationService Integration test suite.
 */
class OAuthRevocationServiceTest extends DatabaseTestCase
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

        \KeyManager::resetOverrides();
        \KeyManager::setStorageOverride([]);
        $this->keyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'signula_ors_test_' . bin2hex(random_bytes(6));
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
            ['ORS Partner ' . $suffix, 'ors_partner_' . $suffix . '@example.com'],
            'ss'
        );
    }

    private function createUser(): int
    {
        $uuid     = self::generateTestUuid();
        $username = 'ors_' . bin2hex(random_bytes(4));
        $email    = 'ors_' . bin2hex(random_bytes(4)) . '@example.com';

        return \Database::insert(
            "INSERT INTO tblUsers
                (userUUID, username, email, passwordHash, displayName, accountStatus, emailVerified, mfaEnabled, createdAt)
             VALUES (?, ?, ?, ?, 'ORS User', 'active', 1, 0, NOW())",
            [$uuid, $username, $email, password_hash('x', PASSWORD_ARGON2ID)],
            'ssss'
        );
    }

    /**
     * @return array{clientID:int,clientIdentifier:string,secret:string}
     */
    private function registerConfidentialClient(?int $partnerID): array
    {
        $result = \OAuthClientManager::registerClient(
            $partnerID,
            'ORS Test RP',
            'confidential',
            ['https://rp.example.com/callback'],
            ['openid', 'profile', 'email']
        );
        $this->assertIsArray($result, 'confidential client fixture registration must succeed');

        return [
            'clientID'         => (int) $result['clientID'],
            'clientIdentifier' => $result['clientIdentifier'],
            'secret'           => $result['clientSecret'],
        ];
    }

    /**
     * @return array{clientID:int,clientIdentifier:string}
     */
    private function registerPublicClient(?int $partnerID): array
    {
        $result = \OAuthClientManager::registerClient(
            $partnerID,
            'ORS Test SPA',
            'public',
            ['https://spa.example.com/callback'],
            ['openid', 'profile']
        );
        $this->assertIsArray($result, 'public client fixture registration must succeed');

        return [
            'clientID'         => (int) $result['clientID'],
            'clientIdentifier' => $result['clientIdentifier'],
        ];
    }

    /**
     * @return array{access_token:string,refresh_token:string}
     */
    private function mintPair(int $userID, int $clientID, string $clientIdentifier): array
    {
        // 🔧 G-001 Stage A5: refresh-token minting is gated behind
        //    `offline_access` — every test in this suite needs a refresh
        //    token to revoke/rotate, so it is always included here.
        return \TokenService::issueForClient($userID, $clientID, $clientIdentifier, ['openid', 'offline_access']);
    }

    // ========================================================================
    // ✅ OWNING CLIENT CAN REVOKE ITS OWN TOKENS
    // ========================================================================

    /**
     * The owning client can revoke its OWN refresh token — the whole family
     * dies, so a subsequent refresh() fails.
     */
    public function testOwningClientRevokesItsOwnRefreshTokenFamily(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerConfidentialClient($partnerID);

        $pair = $this->mintPair($userID, $reg['clientID'], $reg['clientIdentifier']);

        $result = \OAuthRevocationService::handleRevokeRequest(
            ['token' => $pair['refresh_token'], 'token_type_hint' => 'refresh_token'],
            ['method' => 'body', 'client_id' => $reg['clientIdentifier'], 'client_secret' => $reg['secret']]
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['body']);

        // 🔒 B-058: pass the owning client's id — this must fail due to the
        //    revoked family, not a client-binding mismatch.
        $this->expectException(JwtException::class);
        \TokenService::refresh($pair['refresh_token'], $reg['clientID']);
    }

    /**
     * The owning client can revoke its OWN access token — the jti is
     * denylisted, so a subsequent verifyAccessToken() fails.
     */
    public function testOwningClientRevokesItsOwnAccessToken(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerConfidentialClient($partnerID);

        $pair = $this->mintPair($userID, $reg['clientID'], $reg['clientIdentifier']);

        $result = \OAuthRevocationService::handleRevokeRequest(
            ['token' => $pair['access_token'], 'token_type_hint' => 'access_token'],
            ['method' => 'body', 'client_id' => $reg['clientIdentifier'], 'client_secret' => $reg['secret']]
        );

        $this->assertSame(200, $result['status']);

        $this->expectException(JwtException::class);
        \TokenService::verifyAccessToken($pair['access_token'], ['aud' => $reg['clientIdentifier']]);
    }

    /**
     * A token's SHAPE (not just the hint) drives dispatch — an access JWT is
     * still correctly revoked even when the caller sends no/mismatched hint.
     */
    public function testAccessTokenRevokedWithoutHintByShapeDetection(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerConfidentialClient($partnerID);

        $pair = $this->mintPair($userID, $reg['clientID'], $reg['clientIdentifier']);

        $result = \OAuthRevocationService::handleRevokeRequest(
            ['token' => $pair['access_token'], 'token_type_hint' => ''],
            ['method' => 'body', 'client_id' => $reg['clientIdentifier'], 'client_secret' => $reg['secret']]
        );

        $this->assertSame(200, $result['status']);

        $this->expectException(JwtException::class);
        \TokenService::verifyAccessToken($pair['access_token'], ['aud' => $reg['clientIdentifier']]);
    }

    // ========================================================================
    // 🚫 CROSS-CLIENT REVOKE REFUSED
    // ========================================================================

    /**
     * A DIFFERENT (validly-authenticated) client CANNOT revoke Client A's
     * refresh token — the response is still 200 (no oracle), but the token
     * is untouched and a subsequent refresh() STILL succeeds.
     */
    public function testDifferentClientCannotRevokeAnotherClientsRefreshToken(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $regA      = $this->registerConfidentialClient($partnerID);
        $regB      = $this->registerConfidentialClient($partnerID);

        $pairA = $this->mintPair($userID, $regA['clientID'], $regA['clientIdentifier']);

        // 🦹 Client B (validly authenticating as ITSELF) tries to revoke A's token.
        $result = \OAuthRevocationService::handleRevokeRequest(
            ['token' => $pairA['refresh_token'], 'token_type_hint' => 'refresh_token'],
            ['method' => 'body', 'client_id' => $regB['clientIdentifier'], 'client_secret' => $regB['secret']]
        );

        // Still 200 (RFC 7009 — no validity oracle)…
        $this->assertSame(200, $result['status']);

        // …but the token is UNTOUCHED — refresh() still succeeds (does not
        // throw) for the ACTUAL owning client (regA — B-058 client binding).
        $rotated = \TokenService::refresh($pairA['refresh_token'], $regA['clientID']);
        $this->assertIsString($rotated['access_token']);
    }

    /**
     * A DIFFERENT client cannot revoke Client A's access token either — the
     * jti stays un-denylisted, so verifyAccessToken() STILL succeeds.
     */
    public function testDifferentClientCannotRevokeAnotherClientsAccessToken(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $regA      = $this->registerConfidentialClient($partnerID);
        $regB      = $this->registerConfidentialClient($partnerID);

        $pairA = $this->mintPair($userID, $regA['clientID'], $regA['clientIdentifier']);

        $result = \OAuthRevocationService::handleRevokeRequest(
            ['token' => $pairA['access_token'], 'token_type_hint' => 'access_token'],
            ['method' => 'body', 'client_id' => $regB['clientIdentifier'], 'client_secret' => $regB['secret']]
        );

        $this->assertSame(200, $result['status']);

        // Untouched — verify still succeeds.
        $claims = \TokenService::verifyAccessToken($pairA['access_token'], ['aud' => $regA['clientIdentifier']]);

        // 🕵️ G-001 red-team F-01 fix: an RP access token's `sub` is the OIDC
        //    pairwise subject, never the raw userID.
        $clientA = \OAuthClientManager::getClientByID($regA['clientID']);
        $expectedSub = \OAuthClientManager::computeSubject($userID, $clientA);
        $this->assertSame($expectedSub, $claims['sub'], 'F-01: RP access token sub must be the pairwise subject');
        $this->assertNotSame((string) $userID, $claims['sub'], 'F-01: RP access token must never leak the raw userID');
    }

    // ========================================================================
    // 🔐 CLIENT AUTHENTICATION
    // ========================================================================

    /**
     * A confidential client presenting the WRONG secret is denied
     * invalid_client (401) — no token is ever inspected.
     */
    public function testWrongClientSecretIsInvalidClient401(): void
    {
        $partnerID = $this->createPartner();
        $reg       = $this->registerConfidentialClient($partnerID);

        $result = \OAuthRevocationService::handleRevokeRequest(
            ['token' => 'irrelevant-value', 'token_type_hint' => 'refresh_token'],
            ['method' => 'body', 'client_id' => $reg['clientIdentifier'], 'client_secret' => 'totally-wrong-secret']
        );

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_client', $result['body']['error']);
    }

    /**
     * A PUBLIC client can revoke its own token with NO secret at all (PKCE
     * clients have no usable secret to present).
     */
    public function testPublicClientRevokesOwnTokenWithNoSecret(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerPublicClient($partnerID);

        $pair = $this->mintPair($userID, $reg['clientID'], $reg['clientIdentifier']);

        $result = \OAuthRevocationService::handleRevokeRequest(
            ['token' => $pair['refresh_token'], 'token_type_hint' => 'refresh_token'],
            ['method' => 'body', 'client_id' => $reg['clientIdentifier'], 'client_secret' => null]
        );

        $this->assertSame(200, $result['status']);

        // 🔒 B-058: pass the owning (public) client's id.
        $this->expectException(JwtException::class);
        \TokenService::refresh($pair['refresh_token'], $reg['clientID']);
    }

    // ========================================================================
    // 🕳️ NO VALIDITY ORACLE
    // ========================================================================

    /**
     * An entirely UNKNOWN token is a silent 200 no-op.
     */
    public function testUnknownTokenIsSilentNoOp200(): void
    {
        $partnerID = $this->createPartner();
        $reg       = $this->registerConfidentialClient($partnerID);

        $result = \OAuthRevocationService::handleRevokeRequest(
            ['token' => bin2hex(random_bytes(32)), 'token_type_hint' => 'refresh_token'],
            ['method' => 'body', 'client_id' => $reg['clientIdentifier'], 'client_secret' => $reg['secret']]
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['body']);
    }

    /**
     * A missing `token` parameter is still 200 (no leak of expected shape).
     */
    public function testMissingTokenIsSilentNoOp200(): void
    {
        $partnerID = $this->createPartner();
        $reg       = $this->registerConfidentialClient($partnerID);

        $result = \OAuthRevocationService::handleRevokeRequest(
            ['token' => '', 'token_type_hint' => ''],
            ['method' => 'body', 'client_id' => $reg['clientIdentifier'], 'client_secret' => $reg['secret']]
        );

        $this->assertSame(200, $result['status']);
    }
}
