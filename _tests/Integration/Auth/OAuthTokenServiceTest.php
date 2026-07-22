<?php
/**
 * ============================================================================
 * 🧪 OAuthTokenService Integration Tests (G-001 Stage A3 — /oauth/token)
 * ============================================================================
 *
 * DB-bound tests against the real `signula_test` database. Registers REAL
 * clients (Stage A1), mints REAL authorization codes via the REAL Stage A2
 * engine (OAuthAuthorizeService::validateAuthorizeRequest() +
 * issueAuthorizationCode() — exactly what /oauth/authorize-idp does), then
 * drives OAuthTokenService — the engine behind /oauth/token — end to end:
 *
 *   • HAPPY PATH: code → access_token + verifiable id_token (+ refresh_token),
 *     the id_token's `sub` is the OIDC PAIRWISE subject, `aud` = client_id,
 *     `auth_time`/`nonce` carried through, header `typ` = JWT; the access
 *     token verifies with `aud` = client_id.
 *   • PAIRWISE: the same user through a different client yields a different
 *     `sub`; stable across two exchanges for the SAME client.
 *   • REPLAY: a second exchange of the same code → invalid_grant AND the
 *     token family the first exchange minted is revoked.
 *   • Expired code / wrong or missing code_verifier / redirect_uri mismatch /
 *     code redeemed by a different client → invalid_grant.
 *   • Confidential client with wrong secret → invalid_client (401); public
 *     client succeeds with NO secret + correct PKCE.
 *
 * @package    SIGNula\Tests\Integration\Auth
 * @version    2.9.0-beta
 * @see        web/private_html/auth/OAuthTokenService.php
 * @see        web/public_html/oauth/token.php
 * @see        _database/migrations/031_oauth_provider_clients.sql
 * @see        _database/migrations/032_oauth_token_endpoint.sql
 * @see        .dev-team/specs/G-001.md §5
 */

namespace SIGNula\Tests\Integration\Auth;

use SIGNula\Tests\DatabaseTestCase;
use JwtException;
use KeyManager;
use Jwt;
use TokenService;
use OAuthClientManager;
use OAuthAuthorizeService;
use OAuthTokenService;

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

/**
 * 🪪 OAuthTokenService Integration test suite.
 */
class OAuthTokenServiceTest extends DatabaseTestCase
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

        // ⚙️ JWT + OIDC policy settings the facade/service/authorize-engine read
        //    (via the bootstrap getSetting() stub — these do NOT touch tblSettings).
        setTestSetting('jwt.issuer', 'https://signula.id');
        setTestSetting('jwt.audience', 'https://signula.id/api');
        setTestSetting('jwt.access_ttl', 900);
        setTestSetting('jwt.refresh_ttl', 2592000);
        setTestSetting('jwt.leeway_seconds', 60);
        setTestSetting('jwt.key_bits', 2048); // fast key-gen for tests

        setTestSetting('oidc.issuer', 'https://signula.id');
        setTestSetting('oidc.id_token_ttl', 3600);
        setTestSetting('oidc.authcode_ttl', 60);
        setTestSetting('oidc.require_pkce', true);
        setTestSetting('oidc.allow_plain_pkce', false);

        \KeyManager::resetOverrides();
        \KeyManager::setStorageOverride([]);
        $this->keyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'signula_ots_test_' . bin2hex(random_bytes(6));
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
            ['OTS Partner ' . $suffix, 'ots_partner_' . $suffix . '@example.com'],
            'ss'
        );
    }

    private function createUser(): int
    {
        $uuid     = self::generateTestUuid();
        $username = 'ots_' . bin2hex(random_bytes(4));
        $email    = 'ots_' . bin2hex(random_bytes(4)) . '@example.com';

        return \Database::insert(
            "INSERT INTO tblUsers
                (userUUID, username, email, passwordHash, displayName, firstName, lastName, accountStatus, emailVerified, mfaEnabled, createdAt)
             VALUES (?, ?, ?, ?, 'OTS User', 'OTS', 'User', 'active', 1, 0, NOW())",
            [$uuid, $username, $email, password_hash('x', PASSWORD_ARGON2ID)],
            'ssss'
        );
    }

    /**
     * @return array{client:array,secret:string}
     */
    private function registerConfidentialClient(
        ?int $partnerID,
        string $redirectUri = 'https://rp.example.com/callback',
        string $scopes = 'openid profile email offline_access'
    ): array {
        $result = \OAuthClientManager::registerClient(
            $partnerID,
            'OTS Test RP',
            'confidential',
            [$redirectUri],
            explode(' ', $scopes)
        );
        $this->assertIsArray($result, 'confidential client fixture registration must succeed');

        return [
            'client' => \OAuthClientManager::getClient($result['clientIdentifier']),
            'secret' => $result['clientSecret'],
        ];
    }

    /**
     * @return array{client:array}
     */
    private function registerPublicClient(
        ?int $partnerID,
        string $redirectUri = 'https://spa.example.com/callback',
        string $scopes = 'openid profile'
    ): array {
        $result = \OAuthClientManager::registerClient(
            $partnerID,
            'OTS Test SPA',
            'public',
            [$redirectUri],
            explode(' ', $scopes)
        );
        $this->assertIsArray($result, 'public client fixture registration must succeed');

        return ['client' => \OAuthClientManager::getClient($result['clientIdentifier'])];
    }

    /**
     * RFC 7636 §4.1 code_verifier: 43-128 chars of [A-Za-z0-9-._~]. 32 random
     * bytes base64url-encoded (no padding) → 43 chars.
     */
    private static function pkceVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Mint a REAL, single-use authorization code via the REAL Stage A2 engine
     * (validateAuthorizeRequest() + issueAuthorizationCode()) — exactly what
     * /oauth/authorize-idp does on a real "Allow" click.
     *
     * @return array{code:string,redirectUri:string,codeVerifier:string,nonce:string}
     */
    private function issueRealCode(
        array $client,
        int $userID,
        string $scope,
        ?string $codeVerifier = null,
        ?string $redirectUri = null
    ): array {
        $codeVerifier ??= self::pkceVerifier();
        $redirectUri ??= $client['redirectUris'][0];
        $nonce = 'nonce-' . bin2hex(random_bytes(6));

        $params = [
            'client_id'             => $client['clientIdentifier'],
            'redirect_uri'          => $redirectUri,
            'response_type'         => 'code',
            'scope'                 => $scope,
            'state'                 => 'state-' . bin2hex(random_bytes(4)),
            'code_challenge'        => self::pkceChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
            'nonce'                 => $nonce,
        ];

        $validated = \OAuthAuthorizeService::validateAuthorizeRequest($params, $client);
        $this->assertTrue($validated['ok'], 'fixture authorize request must validate OK: ' . (string) ($validated['error'] ?? ''));

        $issued = \OAuthAuthorizeService::issueAuthorizationCode($client, $userID, $validated, '203.0.113.9');

        return [
            'code'         => $issued['code'],
            'redirectUri'  => $redirectUri,
            'codeVerifier' => $codeVerifier,
            'nonce'        => $nonce,
        ];
    }

    /**
     * Shorthand exchange call against the SERVICE (not the HTTP controller).
     */
    private function exchange(array $client, string $code, string $redirectUri, string $codeVerifier, ?string $secret): array
    {
        return \OAuthTokenService::handleTokenRequest(
            [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
                'code_verifier' => $codeVerifier,
            ],
            [
                'method'        => 'body',
                'client_id'     => $client['clientIdentifier'],
                'client_secret' => $secret,
            ]
        );
    }

    // ========================================================================
    // ✅ HAPPY PATH
    // ========================================================================

    /**
     * A valid authorization_code exchange returns an access_token + verifiable
     * id_token (+ refresh_token). The id_token's sub is the OIDC pairwise
     * subject, aud = client_id, auth_time/nonce are carried through, and the
     * header typ = JWT (not at+jwt). The access token verifies with
     * aud = client_id.
     */
    public function testHappyPathIssuesVerifiableAccessAndIdToken(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerConfidentialClient($partnerID);
        $client    = $reg['client'];

        $issued = $this->issueRealCode($client, $userID, 'openid profile email offline_access');

        $result = $this->exchange($client, $issued['code'], $issued['redirectUri'], $issued['codeVerifier'], $reg['secret']);

        $this->assertSame(200, $result['status']);
        $body = $result['body'];
        $this->assertSame('Bearer', $body['token_type']);
        $this->assertIsString($body['access_token']);
        $this->assertIsString($body['id_token']);
        $this->assertIsString($body['refresh_token']);
        $this->assertSame('openid profile email offline_access', $body['scope']);

        // --- access token: verifies with aud = client_id --------------------
        // 🕵️ G-001 red-team F-01 fix: the RP ACCESS token's `sub` is now the
        //    OIDC PAIRWISE subject too — the SAME opaque value the id_token
        //    carries — never the raw userID (previously it leaked it).
        $accessClaims = \Jwt::verify($body['access_token'], ['aud' => $client['clientIdentifier']]);
        $expectedSub = \OAuthClientManager::computeSubject($userID, $client);
        $this->assertSame($expectedSub, $accessClaims['sub'], 'F-01: access token sub must be the pairwise subject (matches id_token), not the raw userID');
        $this->assertNotSame((string) $userID, $accessClaims['sub'], 'F-01: access token must never leak the raw userID');

        // --- id_token: verifies with typ=JWT, iss, aud, pairwise sub --------
        $idHeader = json_decode(\KeyManager::base64UrlDecode(explode('.', $body['id_token'])[0]), true);
        $this->assertSame('JWT', $idHeader['typ']);

        $idClaims = \Jwt::verify($body['id_token'], [
            'typ' => 'JWT',
            'iss' => 'https://signula.id',
            'aud' => $client['clientIdentifier'],
        ]);

        $this->assertSame($expectedSub, $idClaims['sub'], 'id_token sub must be the OIDC pairwise subject');
        $this->assertNotSame((string) $userID, $idClaims['sub'], 'pairwise sub must NOT be the raw userID');
        $this->assertSame($accessClaims['sub'], $idClaims['sub'], 'F-01: access-token sub and id_token sub must byte-match (same pairwise subject)');
        $this->assertArrayHasKey('auth_time', $idClaims);
        $this->assertSame($issued['nonce'], $idClaims['nonce']);
        $this->assertArrayNotHasKey('scope', $idClaims, 'id_token must never carry a scope claim');

        // email/profile scope claims present.
        $this->assertArrayHasKey('email', $idClaims);
        $this->assertArrayHasKey('email_verified', $idClaims);
        $this->assertSame('OTS User', $idClaims['name']);
    }

    // ========================================================================
    // 🕵️ PAIRWISE SUBJECT
    // ========================================================================

    /**
     * The SAME user through a DIFFERENT client yields a DIFFERENT sub, and the
     * sub is stable across two exchanges for the SAME client.
     */
    public function testPairwiseSubjectDiffersPerClientAndIsStablePerClient(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();

        $regA = $this->registerConfidentialClient($partnerID, 'https://a.example.com/callback');
        $regB = $this->registerConfidentialClient($partnerID, 'https://b.example.com/callback');

        $issuedA1 = $this->issueRealCode($regA['client'], $userID, 'openid');
        $resultA1 = $this->exchange($regA['client'], $issuedA1['code'], $issuedA1['redirectUri'], $issuedA1['codeVerifier'], $regA['secret']);
        $subA1 = \Jwt::verify($resultA1['body']['id_token'], ['typ' => 'JWT', 'aud' => $regA['client']['clientIdentifier']])['sub'];

        $issuedA2 = $this->issueRealCode($regA['client'], $userID, 'openid');
        $resultA2 = $this->exchange($regA['client'], $issuedA2['code'], $issuedA2['redirectUri'], $issuedA2['codeVerifier'], $regA['secret']);
        $subA2 = \Jwt::verify($resultA2['body']['id_token'], ['typ' => 'JWT', 'aud' => $regA['client']['clientIdentifier']])['sub'];

        $issuedB = $this->issueRealCode($regB['client'], $userID, 'openid');
        $resultB = $this->exchange($regB['client'], $issuedB['code'], $issuedB['redirectUri'], $issuedB['codeVerifier'], $regB['secret']);
        $subB = \Jwt::verify($resultB['body']['id_token'], ['typ' => 'JWT', 'aud' => $regB['client']['clientIdentifier']])['sub'];

        $this->assertSame($subA1, $subA2, 'the SAME client must yield a STABLE sub across separate exchanges');
        $this->assertNotSame($subA1, $subB, 'DIFFERENT clients must yield DIFFERENT subs for the same user');
    }

    // ========================================================================
    // 🚨 REPLAY
    // ========================================================================

    /**
     * A SECOND exchange of the same code is denied (invalid_grant) AND the
     * token family the FIRST exchange minted is revoked.
     */
    public function testReplayedCodeDeniedAndFirstExchangeFamilyRevoked(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerConfidentialClient($partnerID);
        $client    = $reg['client'];

        // 🔧 G-001 Stage A5: refresh-token minting is now gated behind
        //    `offline_access` — include it so this fixture still gets a
        //    refresh token to exercise below (this test predates that gate).
        $issued = $this->issueRealCode($client, $userID, 'openid profile offline_access');
        $first  = $this->exchange($client, $issued['code'], $issued['redirectUri'], $issued['codeVerifier'], $reg['secret']);
        $this->assertSame(200, $first['status']);

        $codeHash = hash('sha256', $issued['code']);
        $codeRow  = \Database::fetchOne("SELECT issuedFamilyID FROM tblOAuthAuthCodes WHERE codeHash = ?", [$codeHash], 's');
        $familyID = (string) $codeRow['issuedFamilyID'];
        $this->assertNotEmpty($familyID, 'the first exchange must record its minted family on the code row');

        $activeBefore = (int) \Database::fetchOne(
            "SELECT COUNT(*) AS c FROM tblRefreshTokens WHERE familyID = ? AND revoked = 0",
            [$familyID],
            's'
        )['c'];
        $this->assertSame(1, $activeBefore, 'the first exchange must have minted exactly one live refresh token');

        $alertsBefore = $this->countRecords('tblSecurityAlerts');

        // 🦹 REPLAY — same code, same verifier, same everything.
        $second = $this->exchange($client, $issued['code'], $issued['redirectUri'], $issued['codeVerifier'], $reg['secret']);

        $this->assertSame(400, $second['status']);
        $this->assertSame('invalid_grant', $second['body']['error']);

        $activeAfter = (int) \Database::fetchOne(
            "SELECT COUNT(*) AS c FROM tblRefreshTokens WHERE familyID = ? AND revoked = 0",
            [$familyID],
            's'
        )['c'];
        $this->assertSame(0, $activeAfter, 'REPLAY must revoke the ENTIRE family the first exchange minted');

        $this->assertSame($alertsBefore + 1, $this->countRecords('tblSecurityAlerts'), 'replay must raise exactly one security alert');

        // The first exchange's refresh token is now dead too (family revoked).
        // Pass the OWNING client's id so this exercises the "family revoked"
        // reuse path specifically (not merely a B-058 client-binding mismatch).
        $this->expectException(JwtException::class);
        \TokenService::refresh($first['body']['refresh_token'], (int) $client['clientID']);
    }

    // ========================================================================
    // 🚫 REJECTIONS
    // ========================================================================

    /**
     * An expired code is denied as invalid_grant.
     */
    public function testExpiredCodeDenied(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerConfidentialClient($partnerID);
        $client    = $reg['client'];

        $issued = $this->issueRealCode($client, $userID, 'openid');

        \Database::query(
            "UPDATE tblOAuthAuthCodes SET expiresAt = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE codeHash = ?",
            [hash('sha256', $issued['code'])],
            's'
        );

        $result = $this->exchange($client, $issued['code'], $issued['redirectUri'], $issued['codeVerifier'], $reg['secret']);

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_grant', $result['body']['error']);
    }

    /**
     * A wrong code_verifier is denied as invalid_grant.
     */
    public function testWrongCodeVerifierDenied(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerConfidentialClient($partnerID);
        $client    = $reg['client'];

        $issued = $this->issueRealCode($client, $userID, 'openid');

        $result = $this->exchange($client, $issued['code'], $issued['redirectUri'], self::pkceVerifier(), $reg['secret']);

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_grant', $result['body']['error']);
    }

    /**
     * A MISSING code_verifier (when the code is PKCE-bound) is denied.
     */
    public function testMissingCodeVerifierDenied(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerConfidentialClient($partnerID);
        $client    = $reg['client'];

        $issued = $this->issueRealCode($client, $userID, 'openid');

        $result = $this->exchange($client, $issued['code'], $issued['redirectUri'], '', $reg['secret']);

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_grant', $result['body']['error']);
    }

    /**
     * A redirect_uri that does not byte-exactly match the code's bound value
     * is denied.
     */
    public function testRedirectUriMismatchDenied(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerConfidentialClient($partnerID);
        $client    = $reg['client'];

        $issued = $this->issueRealCode($client, $userID, 'openid');

        $result = $this->exchange($client, $issued['code'], 'https://evil.example.com/callback', $issued['codeVerifier'], $reg['secret']);

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_grant', $result['body']['error']);
    }

    /**
     * A code issued to client A cannot be redeemed by client B, even with B's
     * own valid credentials.
     */
    public function testCodeRedeemedByDifferentClientDenied(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();

        $regA = $this->registerConfidentialClient($partnerID, 'https://a.example.com/callback');
        $regB = $this->registerConfidentialClient($partnerID, 'https://b.example.com/callback');

        $issued = $this->issueRealCode($regA['client'], $userID, 'openid');

        // Attempt to redeem client A's code while authenticating as client B.
        $result = $this->exchange($regB['client'], $issued['code'], $issued['redirectUri'], $issued['codeVerifier'], $regB['secret']);

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_grant', $result['body']['error']);
    }

    /**
     * A confidential client presenting the WRONG secret is denied invalid_client (401).
     */
    public function testConfidentialClientWrongSecretIsInvalidClient401(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerConfidentialClient($partnerID);
        $client    = $reg['client'];

        $issued = $this->issueRealCode($client, $userID, 'openid');

        $result = $this->exchange($client, $issued['code'], $issued['redirectUri'], $issued['codeVerifier'], 'totally-wrong-secret');

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_client', $result['body']['error']);
    }

    /**
     * A public client succeeds with NO client_secret at all, given the
     * correct PKCE code_verifier (PKCE is its proof of possession).
     */
    public function testPublicClientSucceedsWithNoSecretAndCorrectPkce(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $regResult = $this->registerPublicClient($partnerID);
        $client    = $regResult['client'];

        $issued = $this->issueRealCode($client, $userID, 'openid');

        $result = $this->exchange($client, $issued['code'], $issued['redirectUri'], $issued['codeVerifier'], null);

        $this->assertSame(200, $result['status']);
        $this->assertIsString($result['body']['access_token']);
        $this->assertIsString($result['body']['id_token']);
    }

    /**
     * A confidential client with an entirely unknown client_id is denied
     * invalid_client (401).
     */
    public function testUnknownClientIdIsInvalidClient401(): void
    {
        $result = \OAuthTokenService::handleTokenRequest(
            [
                'grant_type'    => 'authorization_code',
                'code'          => bin2hex(random_bytes(32)),
                'redirect_uri'  => 'https://rp.example.com/callback',
                'code_verifier' => self::pkceVerifier(),
            ],
            ['method' => 'body', 'client_id' => str_repeat('f', 32), 'client_secret' => 'whatever']
        );

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_client', $result['body']['error']);
    }

    /**
     * An unsupported grant_type is rejected.
     */
    public function testUnsupportedGrantTypeRejected(): void
    {
        $partnerID = $this->createPartner();
        $reg       = $this->registerConfidentialClient($partnerID);

        $result = \OAuthTokenService::handleTokenRequest(
            ['grant_type' => 'client_credentials'],
            ['method' => 'body', 'client_id' => $reg['client']['clientIdentifier'], 'client_secret' => $reg['secret']]
        );

        $this->assertSame(400, $result['status']);
        $this->assertSame('unsupported_grant_type', $result['body']['error']);
    }

    /**
     * A missing `code` for the authorization_code grant is invalid_request.
     */
    public function testMissingCodeIsInvalidRequest(): void
    {
        $partnerID = $this->createPartner();
        $reg       = $this->registerConfidentialClient($partnerID);

        $result = \OAuthTokenService::handleTokenRequest(
            ['grant_type' => 'authorization_code', 'redirect_uri' => 'https://rp.example.com/callback', 'code_verifier' => 'x'],
            ['method' => 'body', 'client_id' => $reg['client']['clientIdentifier'], 'client_secret' => $reg['secret']]
        );

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_request', $result['body']['error']);
    }

    // ========================================================================
    // 🔄 refresh_token GRANT (G-001 Stage A5 / B-058 follow-up)
    // ========================================================================

    /**
     * Exchange a code (WITH offline_access) for a pair, then use the
     * refresh_token grant end-to-end via handleTokenRequest() — the happy
     * path rotates for the OWNING client and returns a fresh pair.
     */
    public function testRefreshTokenGrantHappyPathRotatesForOwningClient(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerConfidentialClient($partnerID);
        $client    = $reg['client'];

        $issued = $this->issueRealCode($client, $userID, 'openid profile offline_access');
        $first  = $this->exchange($client, $issued['code'], $issued['redirectUri'], $issued['codeVerifier'], $reg['secret']);
        $this->assertSame(200, $first['status']);
        $this->assertIsString($first['body']['refresh_token']);

        $result = \OAuthTokenService::handleTokenRequest(
            ['grant_type' => 'refresh_token', 'refresh_token' => $first['body']['refresh_token']],
            ['method' => 'body', 'client_id' => $client['clientIdentifier'], 'client_secret' => $reg['secret']]
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame('Bearer', $result['body']['token_type']);
        $this->assertIsString($result['body']['access_token']);
        $this->assertIsString($result['body']['refresh_token']);
        $this->assertNotSame($first['body']['refresh_token'], $result['body']['refresh_token']);
        $this->assertSame('openid profile offline_access', $result['body']['scope']);

        // The rotated access token still verifies against this client's audience.
        $claims = \Jwt::verify($result['body']['access_token'], ['aud' => $client['clientIdentifier']]);

        // 🕵️ G-001 red-team F-01 fix: the rotated RP access token still
        //    carries the pairwise subject, never the raw userID.
        $expectedSub = \OAuthClientManager::computeSubject($userID, $client);
        $this->assertSame($expectedSub, $claims['sub'], 'F-01: rotated RP access token sub must stay pairwise');
        $this->assertNotSame((string) $userID, $claims['sub'], 'F-01: rotated RP access token must never leak the raw userID');
    }

    /**
     * Presenting an ALREADY-ROTATED (spent) refresh token via the
     * refresh_token grant still triggers reuse-detection — the whole family
     * is revoked, denying even the rotated successor.
     */
    public function testRefreshTokenGrantReuseDetectionRevokesFamily(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerConfidentialClient($partnerID);
        $client    = $reg['client'];

        $issued = $this->issueRealCode($client, $userID, 'openid offline_access');
        $first  = $this->exchange($client, $issued['code'], $issued['redirectUri'], $issued['codeVerifier'], $reg['secret']);

        $auth = ['method' => 'body', 'client_id' => $client['clientIdentifier'], 'client_secret' => $reg['secret']];

        // Legitimate rotation: spend the first refresh token.
        $second = \OAuthTokenService::handleTokenRequest(
            ['grant_type' => 'refresh_token', 'refresh_token' => $first['body']['refresh_token']],
            $auth
        );
        $this->assertSame(200, $second['status']);

        $alertsBefore = $this->countRecords('tblSecurityAlerts');

        // 🦹 REPLAY the now-spent first refresh token via the SAME grant.
        $replay = \OAuthTokenService::handleTokenRequest(
            ['grant_type' => 'refresh_token', 'refresh_token' => $first['body']['refresh_token']],
            $auth
        );
        $this->assertSame(400, $replay['status']);
        $this->assertSame('invalid_grant', $replay['body']['error']);
        $this->assertGreaterThan($alertsBefore, $this->countRecords('tblSecurityAlerts'), 'reuse via the grant must still raise a security alert');

        // The whole family is dead — even the legitimately-rotated successor
        // (from the SAME owning client) is now denied too.
        $thirdAttempt = \OAuthTokenService::handleTokenRequest(
            ['grant_type' => 'refresh_token', 'refresh_token' => $second['body']['refresh_token']],
            $auth
        );
        $this->assertSame(400, $thirdAttempt['status']);
        $this->assertSame('invalid_grant', $thirdAttempt['body']['error']);
    }

    /**
     * A DIFFERENT (validly-authenticated) client cannot use Client A's
     * refresh token via the refresh_token grant — invalid_grant, and the
     * token is left untouched (Client A can still use it afterwards).
     */
    public function testRefreshTokenGrantCrossClientDenied(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $regA      = $this->registerConfidentialClient($partnerID, 'https://a.example.com/callback');
        $regB      = $this->registerConfidentialClient($partnerID, 'https://b.example.com/callback');

        $issued = $this->issueRealCode($regA['client'], $userID, 'openid offline_access');
        $first  = $this->exchange($regA['client'], $issued['code'], $issued['redirectUri'], $issued['codeVerifier'], $regA['secret']);
        $this->assertSame(200, $first['status']);

        // 🦹 Client B (validly authenticating as ITSELF) tries to rotate A's token.
        $crossAttempt = \OAuthTokenService::handleTokenRequest(
            ['grant_type' => 'refresh_token', 'refresh_token' => $first['body']['refresh_token']],
            ['method' => 'body', 'client_id' => $regB['client']['clientIdentifier'], 'client_secret' => $regB['secret']]
        );
        $this->assertSame(400, $crossAttempt['status']);
        $this->assertSame('invalid_grant', $crossAttempt['body']['error']);

        // ✅ Client A (the real owner) can still redeem it normally.
        $ownAttempt = \OAuthTokenService::handleTokenRequest(
            ['grant_type' => 'refresh_token', 'refresh_token' => $first['body']['refresh_token']],
            ['method' => 'body', 'client_id' => $regA['client']['clientIdentifier'], 'client_secret' => $regA['secret']]
        );
        $this->assertSame(200, $ownAttempt['status']);
        $this->assertIsString($ownAttempt['body']['access_token']);
    }

    /**
     * A missing `refresh_token` param for the refresh_token grant is
     * invalid_request.
     */
    public function testRefreshTokenGrantMissingRefreshTokenIsInvalidRequest(): void
    {
        $partnerID = $this->createPartner();
        $reg       = $this->registerConfidentialClient($partnerID);

        $result = \OAuthTokenService::handleTokenRequest(
            ['grant_type' => 'refresh_token'],
            ['method' => 'body', 'client_id' => $reg['client']['clientIdentifier'], 'client_secret' => $reg['secret']]
        );

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_request', $result['body']['error']);
    }

    /**
     * A client exchanging a code WITHOUT offline_access gets no refresh_token
     * at all — so there is nothing to rotate via the refresh_token grant
     * (the RP must re-run the authorization_code flow instead).
     */
    public function testAuthorizationCodeExchangeWithoutOfflineAccessHasNoRefreshToken(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerConfidentialClient($partnerID);
        $client    = $reg['client'];

        $issued = $this->issueRealCode($client, $userID, 'openid profile'); // no offline_access
        $result = $this->exchange($client, $issued['code'], $issued['redirectUri'], $issued['codeVerifier'], $reg['secret']);

        $this->assertSame(200, $result['status']);
        $this->assertIsString($result['body']['access_token']);
        $this->assertArrayNotHasKey('refresh_token', $result['body'], 'no offline_access => no refresh_token in the response at all');
    }
}
