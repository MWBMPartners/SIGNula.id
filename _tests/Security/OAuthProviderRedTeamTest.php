<?php
/**
 * ============================================================================
 * 🩻 SIGNula - OAuth2/OIDC Provider RED-TEAM PoCs (independent security review)
 * ============================================================================
 *
 * Adversarial, DB-bound proof-of-concept tests against the G-001 OAuth/OIDC
 * PROVIDER surface. Originally written by an INDEPENDENT reviewer (did NOT
 * build the code) to BREAK it — the F-01/F-02/F-03 PoCs below ORIGINALLY
 * PASSED, demonstrating three confirmed findings (a fourth, F-04 getClientIP,
 * is tracked separately and out of scope for this remediation). All three
 * have since been FIXED (blue-team remediation) and the PoCs CONVERTED into
 * regression tests that now assert the SECURE outcome — i.e. every test in
 * this file passing means the corresponding attack is BLOCKED. The
 * "DEFENSE HOLDS" tests were never broken to begin with; they document
 * coverage of vectors that were refuted on first contact.
 *
 * Mirrors the fixture/setup style of
 * _tests/Integration/Auth/OAuthUserInfoServiceTest.php.
 *
 * @package SIGNula\Tests\Security
 */

namespace SIGNula\Tests\Security;

use SIGNula\Tests\DatabaseTestCase;

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
requireSource('private_html/auth/OAuthUserInfoService.php');

class OAuthProviderRedTeamTest extends DatabaseTestCase
{
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

    private string $kid;
    private string $keyDir;

    protected function setUp(): void
    {
        parent::setUp();

        \JwtLibLoader::load();

        setTestSetting('jwt.issuer', 'https://signula.id');
        setTestSetting('jwt.audience', 'https://signula.id/api');
        setTestSetting('jwt.access_ttl', 900);
        setTestSetting('jwt.refresh_ttl', 2592000);
        setTestSetting('jwt.leeway_seconds', 60);
        setTestSetting('jwt.key_bits', 2048);

        setTestSetting('oidc.issuer', 'https://signula.id');
        setTestSetting('oidc.id_token_ttl', 3600);
        setTestSetting('oidc.authcode_ttl', 60);
        setTestSetting('oidc.allow_plain_pkce', false);

        \KeyManager::resetOverrides();
        \KeyManager::setStorageOverride([]);
        $this->keyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'signula_rt_' . bin2hex(random_bytes(6));
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
    // 🔧 FIXTURES
    // ========================================================================

    private function createPartner(): int
    {
        $s = bin2hex(random_bytes(4));
        return \Database::insert(
            "INSERT INTO tblPartners (partnerName, partnerEmail, accountTier, isActive, isVerified, verifiedAt)
             VALUES (?, ?, 'premium', 1, 1, NOW())",
            ['RT Partner ' . $s, 'rt_' . $s . '@example.com'],
            'ss'
        );
    }

    private function createUser(): int
    {
        $uuid = self::generateTestUuid();
        return \Database::insert(
            "INSERT INTO tblUsers
                (userUUID, username, email, passwordHash, displayName, firstName, lastName, accountStatus, emailVerified, mfaEnabled, createdAt)
             VALUES (?, ?, ?, ?, 'RT User', 'RT', 'User', 'active', 1, 0, NOW())",
            [$uuid, 'rt_' . bin2hex(random_bytes(4)), 'rt_' . bin2hex(random_bytes(4)) . '@example.com', password_hash('x', PASSWORD_ARGON2ID)],
            'ssss'
        );
    }

    /** @return array{clientID:int,clientIdentifier:string,client:array} */
    private function registerClient(?int $partnerID, string $scopes, string $redirect = 'https://rp.example.com/callback', string $type = 'confidential'): array
    {
        $res = \OAuthClientManager::registerClient(
            $partnerID,
            'RT RP ' . bin2hex(random_bytes(3)),
            $type,
            [$redirect],
            explode(' ', $scopes)
        );
        $this->assertIsArray($res, 'client fixture registration must succeed');
        return [
            'clientID'         => (int) $res['clientID'],
            'clientIdentifier' => $res['clientIdentifier'],
            'clientSecret'     => $res['clientSecret'],
            'client'           => \OAuthClientManager::getClient($res['clientIdentifier']),
        ];
    }

    private static function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $json = \KeyManager::base64UrlDecode($parts[1]);
        return json_decode($json, true) ?: [];
    }

    // ========================================================================
    // ✅ F-01 (FIXED) — PAIRWISE SUBJECT NO LONGER DEFEATED VIA ACCESS-TOKEN `sub`
    // ========================================================================
    // BEFORE the fix: the id_token and userinfo correctly returned an OPAQUE
    // pairwise sub, but the RS256 ACCESS TOKEN handed to the RP carried
    // sub = the RAW internal userID (TokenService::mintPair signed
    // 'sub' => (string)$userID unconditionally). A JWT is NOT encrypted, so ANY
    // RP could base64url-decode the access token and read the stable raw
    // userID — and two colluding RPs both read the SAME value, correlating the
    // same human across sectors.
    //
    // AFTER the fix (TokenService::issueForClient()/mintPair() now thread a
    // computed pairwise `$subject` through, resolved via a new
    // tblOAuthSubjects mapping — migration 033): the ACCESS token's `sub` is
    // the SAME opaque pairwise value the id_token/userinfo already used —
    // DIFFERENT per client/sector, and never the raw userID. This regression
    // test proves the attack is now BLOCKED.
    public function testAccessTokenNoLongerLeaksRawUserIdPairwiseCorrelationBlocked(): void
    {
        $partnerA = $this->createPartner();
        $partnerB = $this->createPartner();
        $userID   = $this->createUser();

        // Two DISTINCT pairwise clients in DIFFERENT sectors (different hosts).
        $regA = $this->registerClient($partnerA, 'openid profile email', 'https://alpha.example.com/cb');
        $regB = $this->registerClient($partnerB, 'openid profile email', 'https://beta.example.net/cb');

        // The pairwise `sub` presented to each RP (id_token / userinfo) DIFFERS.
        $subA = \OAuthClientManager::computeSubject($userID, $regA['client']);
        $subB = \OAuthClientManager::computeSubject($userID, $regB['client']);
        $this->assertNotSame($subA, $subB, 'pairwise id_token/userinfo subs correctly differ per sector');
        $this->assertNotSame((string) $userID, $subA);

        $tokA = \TokenService::issueForClient($userID, $regA['clientID'], $regA['clientIdentifier'], ['openid', 'email'])['access_token'];
        $tokB = \TokenService::issueForClient($userID, $regB['clientID'], $regB['clientIdentifier'], ['openid', 'email'])['access_token'];

        $claimsA = self::decodeJwtPayload($tokA);
        $claimsB = self::decodeJwtPayload($tokB);

        // 🔒 DEFENSE HOLDS: neither access token carries the raw userID, and
        // the two colluding RPs see DIFFERENT (pairwise, per-sector) sub
        // values — they can no longer correlate the same user.
        $this->assertNotSame((string) $userID, (string) $claimsA['sub'], 'F-01 fixed: access token must NOT leak the raw userID to RP A');
        $this->assertNotSame((string) $userID, (string) $claimsB['sub'], 'F-01 fixed: access token must NOT leak the raw userID to RP B');
        $this->assertNotSame($claimsA['sub'], $claimsB['sub'], 'F-01 fixed: colluding RPs must NOT be able to correlate via access-token sub');

        // And each access token's sub byte-matches THAT client's own pairwise
        // subject (the same value its id_token/userinfo would carry).
        $this->assertSame($subA, $claimsA['sub'], 'F-01 fixed: RP A access-token sub must equal RP A\'s pairwise subject');
        $this->assertSame($subB, $claimsB['sub'], 'F-01 fixed: RP B access-token sub must equal RP B\'s pairwise subject');

        // 🕵️ And /oauth/userinfo resolves each token back to the SAME real user.
        $infoA = \OAuthUserInfoService::handleUserInfoRequest($tokA);
        $infoB = \OAuthUserInfoService::handleUserInfoRequest($tokB);
        $this->assertSame(200, $infoA['status']);
        $this->assertSame(200, $infoB['status']);
        $this->assertSame($subA, $infoA['body']['sub']);
        $this->assertSame($subB, $infoB['body']['sub']);
    }

    // ========================================================================
    // ✅ F-02 (FIXED) — REFRESH GRANT NOW RE-CHECKS SCOPE VS CLIENT'S CURRENT
    //                    allowedScopes (stale over-broad scope closed)
    // ========================================================================
    // BEFORE the fix: a refresh token minted with scope S kept re-minting
    // access tokens with S forever, even after an admin REMOVED a scope from
    // the client's allowedScopes — exchangeRefreshToken() carried the stored
    // scope forward verbatim and never re-validated it against current policy.
    //
    // AFTER the fix (TokenService::refresh() now intersects the stored scope
    // with the client's CURRENT allowedScopesArray before re-minting): a scope
    // an admin has since revoked stops being issued on the very next rotation.
    public function testRefreshNoLongerReMintsScopeRemovedFromClientAllowlist(): void
    {
        $partner = $this->createPartner();
        $userID  = $this->createUser();
        $reg     = $this->registerClient($partner, 'openid email offline_access');

        // Original issuance WITH email + offline_access (mints a refresh token).
        $pair = \TokenService::issueForClient($userID, $reg['clientID'], $reg['clientIdentifier'], ['openid', 'email', 'offline_access']);
        $this->assertNotEmpty($pair['refresh_token'], 'offline_access must mint a refresh token');
        $this->assertStringContainsString('email', $pair['scope']);

        // 🔧 Admin TIGHTENS the client: 'email' is no longer permitted.
        \Database::query(
            "UPDATE tblOAuthClients SET allowedScopes = 'openid offline_access' WHERE clientID = ?",
            [$reg['clientID']],
            'i'
        );
        $tightened = \OAuthClientManager::getClient($reg['clientIdentifier']);
        $this->assertFalse(
            \OAuthClientManager::scopesAllowed('openid email', (string) $tightened['allowedScopes']),
            'email is now OUTSIDE the client policy'
        );

        // 🔒 DEFENSE HOLDS: refresh no longer carries the now-disallowed scope
        // forward — it is silently narrowed (intersected) rather than denied
        // outright, and the client can still use its remaining allowed scopes.
        $result = \OAuthTokenService::exchangeRefreshToken($tightened, (string) $pair['refresh_token']);
        $this->assertSame(200, $result['status']);
        $this->assertStringNotContainsString(
            'email',
            (string) $result['body']['scope'],
            'F-02 fixed: refresh must NOT re-mint a scope the client is no longer allowed to request'
        );
        $this->assertStringContainsString('openid', (string) $result['body']['scope'], 'still-allowed scopes survive the intersection');
        $this->assertStringContainsString('offline_access', (string) $result['body']['scope'], 'still-allowed scopes survive the intersection');

        $newAccess = self::decodeJwtPayload((string) $result['body']['access_token']);
        $this->assertStringNotContainsString('email', (string) $newAccess['scope'], 'F-02 fixed: the re-minted ACCESS TOKEN must not carry the revoked scope either');
    }

    // ========================================================================
    // ✅ F-03 (FIXED) — CODE CONSUME NOW BINDS TO THE AUTHENTICATED CLIENT →
    //                    a foreign client can no longer BURN another's code (DoS closed)
    // ========================================================================
    // BEFORE the fix: exchangeAuthorizationCode() ran the atomic single-use
    // consume UPDATE BEFORE the "code was issued to this client" check. A
    // different (validly authenticated) client that observed client A's code
    // could present it — failing the binding check, but only AFTER
    // consumedAt was already stamped — so the legitimate client A could no
    // longer redeem its own code (a single-shot DoS).
    //
    // AFTER the fix (the atomic consume UPDATE now filters on
    // `clientID = <authenticated client>` too): a foreign client's attempt
    // matches ZERO rows and leaves the victim's code completely untouched —
    // still redeemable by its real owner afterwards.
    public function testForeignClientCanNoLongerBurnVictimsAuthorizationCode(): void
    {
        $partner = $this->createPartner();
        $userID  = $this->createUser();

        $victim   = $this->registerClient($partner, 'openid email', 'https://victim.example.com/cb');
        $attacker = $this->registerClient($partner, 'openid email', 'https://attacker.example.com/cb');

        // A real code issued to the VICTIM client (no PKCE for this confidential PoC).
        $validated = [
            'redirectUri'         => 'https://victim.example.com/cb',
            'scope'               => 'openid email',
            'codeChallenge'       => null,
            'codeChallengeMethod' => null,
            'nonce'               => null,
        ];
        $issued = \OAuthAuthorizeService::issueAuthorizationCode($victim['client'], $userID, $validated, '203.0.113.9');
        $rawCode = $issued['code'];

        // 1) Attacker (authenticated as ITSELF) presents the victim's code —
        //    correctly refused, and — critically — the code is NOT burned.
        $attackerAttempt = \OAuthTokenService::exchangeAuthorizationCode($attacker['client'], [
            'code'         => $rawCode,
            'redirect_uri' => 'https://victim.example.com/cb',
        ]);
        $this->assertSame(400, $attackerAttempt['status']);
        $this->assertSame('invalid_grant', $attackerAttempt['body']['error'], 'cross-client redemption correctly refused');

        // 2) 🔒 DEFENSE HOLDS: the LEGITIMATE victim client can STILL redeem
        //    its own code — the attacker's failed attempt left it untouched.
        $victimAttempt = \OAuthTokenService::exchangeAuthorizationCode($victim['client'], [
            'code'         => $rawCode,
            'redirect_uri' => 'https://victim.example.com/cb',
        ]);
        $this->assertSame(200, $victimAttempt['status'], 'F-03 fixed: victim must still be able to redeem its own code after a foreign client\'s failed attempt');
        $this->assertIsString($victimAttempt['body']['access_token']);
        $this->assertIsString($victimAttempt['body']['id_token']);
    }

    // ========================================================================
    // ✅ DEFENSE HOLDS — alg-confusion (RS256→HS256) is rejected
    // ========================================================================
    public function testAlgConfusionHs256WithPublicKeyRejected(): void
    {
        $publicPem = \KeyManager::getPublicKey($this->kid);
        $this->assertNotNull($publicPem);

        $header  = ['alg' => 'HS256', 'typ' => 'at+jwt', 'kid' => $this->kid];
        $now = time();
        $payload = [
            'iss' => 'https://signula.id', 'aud' => 'https://signula.id/api',
            'sub' => '1', 'scope' => 'openid', 'iat' => $now, 'nbf' => $now,
            'exp' => $now + 900, 'jti' => bin2hex(random_bytes(16)),
        ];
        $h = \KeyManager::base64UrlEncode(json_encode($header));
        $p = \KeyManager::base64UrlEncode(json_encode($payload));
        $sig = \KeyManager::base64UrlEncode(hash_hmac('sha256', "$h.$p", $publicPem, true));
        $forged = "$h.$p.$sig";

        $this->expectException(\JwtException::class);
        \TokenService::verifyAccessToken($forged, ['aud' => 'https://signula.id/api']);
    }

    // ========================================================================
    // ✅ DEFENSE HOLDS — alg:none is rejected
    // ========================================================================
    public function testAlgNoneRejected(): void
    {
        $header  = ['alg' => 'none', 'typ' => 'at+jwt', 'kid' => $this->kid];
        $now = time();
        $payload = [
            'iss' => 'https://signula.id', 'aud' => 'https://signula.id/api',
            'sub' => '1', 'scope' => 'openid', 'iat' => $now, 'nbf' => $now,
            'exp' => $now + 900, 'jti' => bin2hex(random_bytes(16)),
        ];
        $h = \KeyManager::base64UrlEncode(json_encode($header));
        $p = \KeyManager::base64UrlEncode(json_encode($payload));
        $forged = "$h.$p.";

        $this->expectException(\JwtException::class);
        \TokenService::verifyAccessToken($forged, ['aud' => 'https://signula.id/api']);
    }

    // ========================================================================
    // ✅ DEFENSE HOLDS — B-058 cross-client refresh redemption is rejected
    // ========================================================================
    public function testCrossClientRefreshRedemptionRejected(): void
    {
        $partnerA = $this->createPartner();
        $partnerB = $this->createPartner();
        $userID   = $this->createUser();

        $regA = $this->registerClient($partnerA, 'openid offline_access', 'https://a.example.com/cb');
        $regB = $this->registerClient($partnerB, 'openid offline_access', 'https://b.example.com/cb');

        $pairA = \TokenService::issueForClient($userID, $regA['clientID'], $regA['clientIdentifier'], ['openid', 'offline_access']);
        $this->assertNotEmpty($pairA['refresh_token']);

        // Client B (its own valid identity) tries to rotate A's refresh token.
        $asB = \OAuthTokenService::exchangeRefreshToken($regB['client'], (string) $pairA['refresh_token']);
        $this->assertSame(400, $asB['status']);
        $this->assertSame('invalid_grant', $asB['body']['error'], 'B-058: cross-client refresh correctly refused');

        // And the first-party path (no client) also refuses an RP-bound token.
        $this->expectException(\JwtException::class);
        \TokenService::refresh((string) $pairA['refresh_token'], null);
    }

    // ========================================================================
    // ✅ DEFENSE HOLDS — redirect_uri exact-match rejects near-miss variants
    // ========================================================================
    public function testRedirectUriExactMatchRejectsVariants(): void
    {
        $registered = ['https://rp.example.com/callback'];
        $variants = [
            'https://rp.example.com/callback/',        // trailing slash
            'https://rp.example.com/callback?x=1',     // query append
            'https://rp.example.com/callback#x',       // fragment append
            'https://rp.example.com:443/callback',     // explicit port
            'https://RP.example.com/callback',         // case-fold host
            'https://rp.example.com/Callback',         // case-fold path
            'https://rp.example.com.attacker.com/callback', // suffix
            'https://attacker.com/callback',           // different host
            'https://rp.example.com/callback%2e',      // percent-encode
            'https://rp.example.com@evil.com/callback',// userinfo-in-authority
        ];
        foreach ($variants as $v) {
            $this->assertFalse(
                \OAuthClientManager::isExactRedirectMatch($v, $registered),
                "variant must be rejected: {$v}"
            );
        }
        $this->assertTrue(\OAuthClientManager::isExactRedirectMatch('https://rp.example.com/callback', $registered));
    }
}
