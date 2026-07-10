<?php
/**
 * ============================================================================
 * 🧪 Jwt Facade Unit Tests (G-003 Stage 1 — crypto foundation)
 * ============================================================================
 *
 * Proves the RS256 JWT facade enforces SIGNula token policy and — above all —
 * defeats the algorithm-confusion / `none` attack family:
 *
 *   • sign → verify round-trip (valid ACCEPT)
 *   • expired token           → REJECT
 *   • not-yet-valid (nbf/iat) → REJECT
 *   • tampered payload        → REJECT (signature no longer matches)
 *   • tampered signature      → REJECT
 *   • alg:none                → REJECT   ← classic bypass
 *   • alg:HS256 signed with the RSA PUBLIC key as the HMAC secret → REJECT
 *                                        ← classic RS256→HS256 confusion
 *   • unknown / missing kid   → REJECT
 *   • wrong iss / wrong aud   → REJECT
 *   • clock-skew within leeway ACCEPT, beyond leeway REJECT
 *   • revoked jti (denylist)  → REJECT
 *
 * No database: KeyManager runs on its in-memory storage override with an
 * isolated temp key-dir. A real RSA keypair is minted in setUp().
 *
 * @package    SIGNula\Tests\Unit\Security
 * @version    2.8.0-beta
 * @see        web/private_html/security/Jwt.php
 * @see        web/private_html/security/KeyManager.php
 * @see        https://auth0.com/blog/critical-vulnerabilities-in-json-web-token-libraries/
 */

namespace SIGNula\Tests\Unit\Security;

use SIGNula\Tests\TestCase;
use JwtException;
use KeyManager;
use Jwt;

// 📦 Load the vendored library loader + facade + key manager + exception.
//    The loader require_once's web/_lib/jwt/src/*.php (Firebase\JWT\*).
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/security/JwtException.php');
requireSource('private_html/security/JwtLibLoader.php');
requireSource('private_html/security/KeyManager.php');
requireSource('private_html/security/Jwt.php');

/**
 * 🎫 Jwt facade test suite.
 */
class JwtTest extends TestCase
{
    /** @var string The kid of the active test key. */
    private string $kid;

    /** @var string Isolated temp dir for the key-file fallback. */
    private string $keyDir;

    /**
     * 🔧 Mint a fresh in-memory RSA key before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 📚 Ensure the vendored library is loaded.
        \JwtLibLoader::load();

        // ⚙️ Test settings the facade / manager read.
        setTestSetting('jwt.issuer', 'https://signula.id');
        setTestSetting('jwt.audience', 'https://signula.id/api');
        setTestSetting('jwt.access_ttl', 900);
        setTestSetting('jwt.leeway_seconds', 60);
        // 🚀 2048 bits keeps key generation fast in the test suite (still ≥ RSA floor).
        setTestSetting('jwt.key_bits', 2048);

        // 🧪 In-memory storage + isolated key dir (no DB, no touching _private).
        \KeyManager::resetOverrides();
        \KeyManager::setStorageOverride([]);
        $this->keyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'signula_jwt_test_' . bin2hex(random_bytes(6));
        \KeyManager::setKeyDirOverride($this->keyDir);

        // 🔑 Mint + activate a signing key.
        $this->kid = \KeyManager::generateKey(true);

        // 🚫 Start with no denylist.
        \Jwt::setDenylistChecker(null);
    }

    /**
     * 🧹 Clean up overrides + temp key files.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        \Jwt::setDenylistChecker(null);
        \KeyManager::resetOverrides();

        // 🗑️ Remove the temp key dir.
        if (isset($this->keyDir) && is_dir($this->keyDir)) {
            foreach (glob($this->keyDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->keyDir);
        }

        parent::tearDown();
    }

    // ========================================================================
    // ✅ HAPPY PATH
    // ========================================================================

    /**
     * A freshly-signed token verifies and returns the expected claims.
     */
    public function testSignVerifyRoundTripAccepts(): void
    {
        $jwt = \Jwt::sign(['sub' => '42', 'scope' => 'user:read user:write']);

        $this->assertIsString($jwt);
        $this->assertSame(3, substr_count($jwt, '.') + 1, 'JWT must have 3 dot-separated segments');

        $claims = \Jwt::verify($jwt, ['typ' => 'at+jwt']);

        $this->assertSame('42', $claims['sub']);
        $this->assertSame('user:read user:write', $claims['scope']);
        $this->assertSame('https://signula.id', $claims['iss']);
        $this->assertSame('https://signula.id/api', $claims['aud']);
        $this->assertArrayHasKey('jti', $claims);
        $this->assertArrayHasKey('exp', $claims);
        $this->assertArrayHasKey('iat', $claims);
    }

    /**
     * The header carries the kid and typ=at+jwt; the alg is RS256.
     */
    public function testSignedHeaderPinsRs256AndCarriesKid(): void
    {
        $jwt = \Jwt::sign(['sub' => '42']);
        $header = json_decode(
            \KeyManager::base64UrlDecode(explode('.', $jwt)[0]),
            true
        );

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('at+jwt', $header['typ']);
        $this->assertSame($this->kid, $header['kid']);
    }

    /**
     * A caller cannot weaken the timing claims: even if they pass a huge exp,
     * the facade overwrites it with iat + ttl.
     */
    public function testCallerCannotOverrideExpiry(): void
    {
        $farFuture = time() + 999999999;
        $jwt = \Jwt::sign(['sub' => '42', 'exp' => $farFuture]);
        $claims = \Jwt::verify($jwt);

        $this->assertLessThan($farFuture, $claims['exp'], 'Facade must own exp, not the caller');
        $this->assertLessThanOrEqual(time() + 901, $claims['exp']);
    }

    // ========================================================================
    // ⛔ ATTACK / NEGATIVE MATRIX
    // ========================================================================

    /**
     * An expired token is rejected.
     */
    public function testExpiredTokenRejected(): void
    {
        // Negative TTL → exp already in the past (beyond leeway).
        $jwt = \Jwt::sign(['sub' => '42'], null, -1000);

        $this->expectException(JwtException::class);
        \Jwt::verify($jwt);
    }

    /**
     * A token whose nbf/iat is in the future (beyond leeway) is rejected.
     */
    public function testNotYetValidTokenRejected(): void
    {
        // Forge a token with iat/nbf far in the future, correctly signed by us,
        // by round-tripping through a hand-built payload signed via the facade's
        // active key. Easiest: sign then rewrite nbf and re-sign is not possible
        // (we don't expose the key), so assert via a large negative leeway proxy:
        // instead, sign normally then verify with the token's nbf pushed forward
        // is not doable without the key. So we verify the library path directly:
        // a token minted "now" must FAIL when we demand it be valid only in the
        // far past — emulate by signing then checking that a future-nbf token
        // (built with the real key through KeyManager) is rejected.
        $jwt = $this->signRawWithActiveKey([
            'sub' => '42',
            'iss' => 'https://signula.id',
            'aud' => 'https://signula.id/api',
            'iat' => time() + 10000,
            'nbf' => time() + 10000,
            'exp' => time() + 20000,
            'jti' => bin2hex(random_bytes(16)),
        ]);

        $this->expectException(JwtException::class);
        \Jwt::verify($jwt);
    }

    /**
     * Tampering with the payload invalidates the signature → reject.
     */
    public function testTamperedPayloadRejected(): void
    {
        $jwt = \Jwt::sign(['sub' => '42']);
        $parts = explode('.', $jwt);

        $body = json_decode(\KeyManager::base64UrlDecode($parts[1]), true);
        $body['sub'] = '999'; // 🦹 privilege escalation attempt
        $parts[1] = \KeyManager::base64UrlEncode(json_encode($body));
        $tampered = implode('.', $parts);

        $this->expectException(JwtException::class);
        \Jwt::verify($tampered);
    }

    /**
     * Tampering with the signature bytes → reject.
     */
    public function testTamperedSignatureRejected(): void
    {
        $jwt = \Jwt::sign(['sub' => '42']);
        $parts = explode('.', $jwt);

        // Flip the signature to a valid-length but wrong value.
        $sig = \KeyManager::base64UrlDecode($parts[2]);
        $sig[0] = $sig[0] === "\x00" ? "\x01" : "\x00";
        $parts[2] = \KeyManager::base64UrlEncode($sig);
        $bad = implode('.', $parts);

        $this->expectException(JwtException::class);
        \Jwt::verify($bad);
    }

    /**
     * 🚨 alg:none must be rejected (the classic auth-bypass).
     */
    public function testAlgNoneRejected(): void
    {
        $header = \KeyManager::base64UrlEncode(json_encode([
            'alg' => 'none', 'typ' => 'at+jwt', 'kid' => $this->kid,
        ]));
        $payload = \KeyManager::base64UrlEncode(json_encode([
            'sub' => '42',
            'iss' => 'https://signula.id',
            'aud' => 'https://signula.id/api',
            'exp' => time() + 900,
            'iat' => time(),
            'jti' => bin2hex(random_bytes(16)),
        ]));
        $noneToken = $header . '.' . $payload . '.'; // empty signature

        $this->expectException(JwtException::class);
        \Jwt::verify($noneToken);
    }

    /**
     * 🚨 THE ALGORITHM-CONFUSION ATTACK.
     *
     * An attacker takes our PUBLIC key (published via JWKS), and forges a token
     * with alg:HS256 signed with that public-key PEM as the HMAC secret. A naive
     * verifier that reads alg from the header and reuses the public key would
     * ACCEPT it. Our facade pins RS256 on the Key, so the library rejects it at
     * the "Incorrect key for this algorithm" check — BEFORE any HMAC compare.
     */
    public function testAlgorithmConfusionHs256WithPublicKeyRejected(): void
    {
        $publicPem = \KeyManager::getPublicKey($this->kid);
        $this->assertNotNull($publicPem, 'Public key must be retrievable for the attack setup');

        $header = \KeyManager::base64UrlEncode(json_encode([
            'alg' => 'HS256', 'typ' => 'at+jwt', 'kid' => $this->kid,
        ]));
        $payload = \KeyManager::base64UrlEncode(json_encode([
            'sub' => '42',
            'iss' => 'https://signula.id',
            'aud' => 'https://signula.id/api',
            'exp' => time() + 900,
            'iat' => time(),
            'jti' => bin2hex(random_bytes(16)),
        ]));
        $signingInput = $header . '.' . $payload;

        // 🦹 HMAC-SHA256 over the input using the RSA PUBLIC KEY PEM as the secret.
        $forgedSig = \KeyManager::base64UrlEncode(
            hash_hmac('sha256', $signingInput, $publicPem, true)
        );
        $forged = $signingInput . '.' . $forgedSig;

        $this->expectException(JwtException::class);
        \Jwt::verify($forged);
    }

    /**
     * An unknown kid (not in our key set) → reject.
     */
    public function testUnknownKidRejected(): void
    {
        // Retire the only key so its kid becomes unknown, but first mint a token.
        $jwt = \Jwt::sign(['sub' => '42']);

        // Rotate to a new active key, then retire the original kid → its public
        // key leaves the set, so the original token's kid is now unknown.
        \KeyManager::rotateKey();
        \KeyManager::retireKey($this->kid);

        $this->expectException(JwtException::class);
        \Jwt::verify($jwt);
    }

    /**
     * A token with no kid header → reject.
     */
    public function testMissingKidRejected(): void
    {
        // Build a well-formed but kid-less token signed by the active key.
        $jwt = $this->signRawWithActiveKey(
            [
                'sub' => '42',
                'iss' => 'https://signula.id',
                'aud' => 'https://signula.id/api',
                'exp' => time() + 900,
                'iat' => time(),
                'jti' => bin2hex(random_bytes(16)),
            ],
            includeKid: false
        );

        $this->expectException(JwtException::class);
        \Jwt::verify($jwt);
    }

    /**
     * Wrong issuer → reject.
     */
    public function testWrongIssuerRejected(): void
    {
        $jwt = $this->signRawWithActiveKey([
            'sub' => '42',
            'iss' => 'https://evil.example',
            'aud' => 'https://signula.id/api',
            'exp' => time() + 900,
            'iat' => time(),
            'jti' => bin2hex(random_bytes(16)),
        ]);

        $this->expectException(JwtException::class);
        \Jwt::verify($jwt);
    }

    /**
     * Wrong audience → reject (via the aud override on sign()).
     */
    public function testWrongAudienceRejected(): void
    {
        $jwt = \Jwt::sign(['sub' => '42'], 'https://evil.example');

        $this->expectException(JwtException::class);
        \Jwt::verify($jwt); // expects the default jwt.audience
    }

    /**
     * Explicitly demanding a matching aud accepts; a mismatching aud rejects.
     */
    public function testExpectedAudienceEnforced(): void
    {
        $jwt = \Jwt::sign(['sub' => '42'], 'https://signula.id/custom');

        // ✅ Correct expectation accepts.
        $claims = \Jwt::verify($jwt, ['aud' => 'https://signula.id/custom']);
        $this->assertSame('42', $claims['sub']);

        // ❌ Wrong expectation rejects.
        $this->expectException(JwtException::class);
        \Jwt::verify($jwt, ['aud' => 'https://signula.id/api']);
    }

    // ========================================================================
    // ⏱️ CLOCK-SKEW LEEWAY
    // ========================================================================

    /**
     * A token expired by less than the leeway is still accepted; beyond it,
     * rejected.
     */
    public function testClockSkewLeewayBoundary(): void
    {
        // Expired 30s ago. With 60s leeway → accepted.
        $slightlyExpired = $this->signRawWithActiveKey([
            'sub' => '42',
            'iss' => 'https://signula.id',
            'aud' => 'https://signula.id/api',
            'iat' => time() - 100,
            'nbf' => time() - 100,
            'exp' => time() - 30,
            'jti' => bin2hex(random_bytes(16)),
        ]);

        $claims = \Jwt::verify($slightlyExpired, ['leeway' => 60]);
        $this->assertSame('42', $claims['sub'], 'Within leeway should be accepted');

        // Same token, zero leeway → rejected.
        $this->expectException(JwtException::class);
        \Jwt::verify($slightlyExpired, ['leeway' => 0]);
    }

    // ========================================================================
    // 🚫 REVOCATION / DENYLIST
    // ========================================================================

    /**
     * A jti on the denylist is rejected even though the signature is valid.
     */
    public function testRevokedJtiRejected(): void
    {
        $jwt = \Jwt::sign(['sub' => '42']);
        $jti = \Jwt::verify($jwt)['jti']; // valid before revocation

        // 🚫 Install a denylist that revokes exactly this jti.
        \Jwt::setDenylistChecker(static fn(string $candidate): bool => $candidate === $jti);

        $this->expectException(JwtException::class);
        \Jwt::verify($jwt);
    }

    /**
     * A non-revoked jti still passes with a denylist installed.
     */
    public function testNonRevokedJtiAcceptedWithDenylistInstalled(): void
    {
        \Jwt::setDenylistChecker(static fn(string $candidate): bool => $candidate === 'some-other-jti');

        $jwt = \Jwt::sign(['sub' => '42']);
        $claims = \Jwt::verify($jwt);
        $this->assertSame('42', $claims['sub']);
    }

    // ========================================================================
    // 🪪 signIdToken() — OIDC ID TOKEN (G-001 Stage A3, gotcha fix 1a)
    // ========================================================================

    /**
     * signIdToken() sets header typ=JWT (NOT at+jwt), carries NO scope claim,
     * defaults iss from oidc.issuer (falling back to jwt.issuer), and forces
     * aud to the given client_id — verifiable via Jwt::verify() itself when
     * given the matching expectations.
     */
    public function testSignIdTokenSetsTypJwtNoScopeAndVerifiableClaims(): void
    {
        setTestSetting('oidc.issuer', 'https://signula.id');
        setTestSetting('oidc.id_token_ttl', 3600);

        $jwt = \Jwt::signIdToken(
            ['sub' => 'pairwise-sub-abc', 'auth_time' => 1234567890, 'nonce' => 'nonce-xyz'],
            'client-abc-123'
        );

        // 🔖 Header: typ = JWT (a plain OIDC id_token), NOT the RFC 9068
        //    at+jwt access-token marker.
        $header = json_decode(\KeyManager::base64UrlDecode(explode('.', $jwt)[0]), true);
        $this->assertSame('JWT', $header['typ']);
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame($this->kid, $header['kid']);

        // ✅ Verifies with the id_token's OWN typ/iss/aud expectations.
        $claims = \Jwt::verify($jwt, ['typ' => 'JWT', 'iss' => 'https://signula.id', 'aud' => 'client-abc-123']);

        $this->assertSame('pairwise-sub-abc', $claims['sub']);
        $this->assertSame('https://signula.id', $claims['iss']);
        $this->assertSame('client-abc-123', $claims['aud']);
        $this->assertSame(1234567890, $claims['auth_time']);
        $this->assertSame('nonce-xyz', $claims['nonce']);
        $this->assertArrayHasKey('iat', $claims);
        $this->assertArrayHasKey('exp', $claims);
        $this->assertArrayHasKey('jti', $claims);

        // 🚫 NO scope claim is ever carried on an id_token.
        $this->assertArrayNotHasKey('scope', $claims);
    }

    /**
     * A caller-supplied 'scope' entry is stripped, and a caller-supplied 'aud'
     * entry is OVERRIDDEN by the forced $aud parameter (never smuggled through
     * $claims).
     */
    public function testSignIdTokenStripsScopeAndForcesAudienceOverCallerSuppliedValue(): void
    {
        $jwt = \Jwt::signIdToken(
            ['sub' => '99', 'scope' => 'user:read user:write', 'aud' => 'attacker-supplied-aud'],
            'real-client-id'
        );

        $claims = \Jwt::verify($jwt, ['typ' => 'JWT', 'aud' => 'real-client-id']);

        $this->assertArrayNotHasKey('scope', $claims, 'id_token must never carry a scope claim');
        $this->assertSame('real-client-id', $claims['aud'], 'aud must be the forced parameter, not the caller-supplied claim');
    }

    /**
     * oidc.issuer, when set, takes precedence over jwt.issuer for the
     * id_token's iss claim.
     */
    public function testSignIdTokenPrefersOidcIssuerOverJwtIssuer(): void
    {
        setTestSetting('oidc.issuer', 'https://oidc.signula.id');
        setTestSetting('jwt.issuer', 'https://signula.id'); // still set from setUp(), kept distinct here

        $jwt = \Jwt::signIdToken(['sub' => '1'], 'some-client');
        $claims = \Jwt::verify($jwt, ['typ' => 'JWT', 'iss' => 'https://oidc.signula.id', 'aud' => 'some-client']);

        $this->assertSame('https://oidc.signula.id', $claims['iss']);
    }

    // ========================================================================
    // 🧱 MALFORMED INPUT
    // ========================================================================

    /**
     * Structurally malformed tokens are rejected (not fatal).
     */
    public function testMalformedTokenRejected(): void
    {
        $this->expectException(JwtException::class);
        \Jwt::verify('not-a-jwt');
    }

    // ========================================================================
    // 🔧 TEST HELPERS
    // ========================================================================

    /**
     * 🛠️ Sign an arbitrary claim set with the ACTIVE test key, using the real
     * library directly (bypassing the facade's claim defaults) so tests can mint
     * tokens with deliberately-hostile claims (future iat, wrong iss, no kid).
     *
     * @param array<string,mixed> $claims
     * @param bool                $includeKid Emit the kid header (default true).
     * @return string Signed JWT.
     */
    private function signRawWithActiveKey(array $claims, bool $includeKid = true): string
    {
        $active = \KeyManager::getActiveKey();
        $head = ['typ' => 'at+jwt'];
        $kid = $includeKid ? $active['kid'] : null;

        return \Firebase\JWT\JWT::encode(
            $claims,
            $active['private_pem'],
            'RS256',
            $kid,
            $head
        );
    }
}
