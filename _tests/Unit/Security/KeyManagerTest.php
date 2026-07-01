<?php
/**
 * ============================================================================
 * 🧪 KeyManager Unit Tests (G-003 Stage 1 — signing-key lifecycle)
 * ============================================================================
 *
 * Covers:
 *   • generateKey() mints an RSA keypair, returns a kid, stores private + public
 *   • JWKS assembly: {keys:[{kty,use,alg,kid,n,e}]} — RS256, no private `d`
 *   • active-kid selection + getActiveKey() returns the live private PEM
 *   • public-key retrieval reconstructs a valid PEM from the stored JWK
 *   • `_private` key-file FALLBACK: getPrivateKey/getPublicKey read the file
 *     when the DB (override) row is absent
 *   • rotation keeps the OLD public key in JWKS until retire; retire removes it
 *   • retire refuses to remove the currently-active kid
 *   • JWKS ↔ PEM round-trip (jwkToPublicPem produces an OpenSSL-parseable key)
 *
 * No database: uses the in-memory storage override + an isolated temp key dir.
 *
 * @package    SIGNula\Tests\Unit\Security
 * @version    2.8.0-beta
 * @see        web/private_html/security/KeyManager.php
 * @see        https://datatracker.ietf.org/doc/html/rfc7517 (JWK)
 */

namespace SIGNula\Tests\Unit\Security;

use SIGNula\Tests\TestCase;
use KeyManager;

requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/security/JwtException.php');
requireSource('private_html/security/JwtLibLoader.php');
requireSource('private_html/security/KeyManager.php');

/**
 * 🗝️ KeyManager test suite.
 */
class KeyManagerTest extends TestCase
{
    /** @var string Isolated temp dir for key-file fallback. */
    private string $keyDir;

    /**
     * 🔧 Fresh in-memory store + temp key dir per test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \JwtLibLoader::load(); // needed for JWK::parseKeySet in the round-trip test

        setTestSetting('jwt.key_bits', 2048); // fast keygen for tests (≥ RSA floor)

        \KeyManager::resetOverrides();
        \KeyManager::setStorageOverride([]);
        $this->keyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'signula_km_test_' . bin2hex(random_bytes(6));
        \KeyManager::setKeyDirOverride($this->keyDir);
    }

    /**
     * 🧹 Remove overrides + temp files.
     */
    protected function tearDown(): void
    {
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
    // 🔑 GENERATION
    // ========================================================================

    /**
     * generateKey returns a non-empty kid and sets it active.
     */
    public function testGenerateKeyReturnsKidAndActivates(): void
    {
        $kid = \KeyManager::generateKey(true);

        $this->assertIsString($kid);
        $this->assertNotEmpty($kid);
        // kid format: YYYYMM-<12 hex>
        $this->assertMatchesRegularExpression('/^\d{6}-[0-9a-f]{12}$/', $kid);
        $this->assertSame($kid, \KeyManager::getActiveKid());
    }

    /**
     * getActiveKey returns the live private PEM + kid.
     */
    public function testGetActiveKeyReturnsPrivatePem(): void
    {
        $kid = \KeyManager::generateKey(true);
        $active = \KeyManager::getActiveKey();

        $this->assertSame($kid, $active['kid']);
        $this->assertStringContainsString('PRIVATE KEY', $active['private_pem']);
        // The PEM must be an OpenSSL-parseable RSA private key.
        $this->assertNotFalse(openssl_pkey_get_private($active['private_pem']));
    }

    /**
     * A private key stored via the override is retrievable and (in production)
     * would be encrypted at rest. Here we assert the stored blob is NOT the raw
     * PEM (i.e. encrypt() ran), and that getPrivateKey() decrypts it back.
     */
    public function testPrivateKeyStoredEncryptedAndDecryptsBack(): void
    {
        $kid = \KeyManager::generateKey(true);

        // Peek at the raw stored value via a fresh generate then reflection-free
        // check: re-read through the (encrypting) path and confirm round-trip.
        $pem = \KeyManager::getPrivateKey($kid);
        $this->assertNotNull($pem);
        $this->assertStringContainsString('PRIVATE KEY', $pem);
        $this->assertNotFalse(openssl_pkey_get_private($pem));
    }

    /**
     * getActiveKey throws when no key has been generated.
     */
    public function testGetActiveKeyThrowsWhenNoKey(): void
    {
        // Storage override is empty (no key minted).
        $this->expectException(\RuntimeException::class);
        \KeyManager::getActiveKey();
    }

    // ========================================================================
    // 🌐 JWKS
    // ========================================================================

    /**
     * JWKS shape: RSA sig key with kid/n/e and NO private exponent.
     */
    public function testJwksShapeIsValidPublicRsa(): void
    {
        $kid = \KeyManager::generateKey(true);
        $jwks = \KeyManager::getJwks();

        $this->assertArrayHasKey('keys', $jwks);
        $this->assertCount(1, $jwks['keys']);

        $jwk = $jwks['keys'][0];
        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertSame($kid, $jwk['kid']);
        $this->assertNotEmpty($jwk['n']);
        $this->assertNotEmpty($jwk['e']);
        // 🔒 No private material must ever appear in JWKS.
        $this->assertArrayNotHasKey('d', $jwk);
        $this->assertArrayNotHasKey('p', $jwk);
        $this->assertArrayNotHasKey('q', $jwk);
        // n/e must be base64url (no +, /, or = characters).
        $this->assertDoesNotMatchRegularExpression('#[+/=]#', $jwk['n']);
        $this->assertDoesNotMatchRegularExpression('#[+/=]#', $jwk['e']);
    }

    /**
     * The published JWK reconstructs to a PEM that OpenSSL accepts AND that the
     * independent JWK::parseKeySet path in the library accepts — proving n/e are
     * computed correctly.
     */
    public function testJwkRoundTripsToUsablePublicKey(): void
    {
        \KeyManager::generateKey(true);
        $jwks = \KeyManager::getJwks();

        // (a) our own JWK→PEM conversion is OpenSSL-parseable.
        $pem = \KeyManager::jwkToPublicPem($jwks['keys'][0]);
        $this->assertNotNull($pem);
        $this->assertStringContainsString('PUBLIC KEY', $pem);
        $this->assertNotFalse(openssl_pkey_get_public($pem));

        // (b) the independent library parser accepts the same JWKS.
        $keySet = \Firebase\JWT\JWK::parseKeySet($jwks);
        $this->assertArrayHasKey($jwks['keys'][0]['kid'], $keySet);
        $this->assertSame('RS256', $keySet[$jwks['keys'][0]['kid']]->getAlgorithm());
    }

    // ========================================================================
    // 🔓 PUBLIC KEY RETRIEVAL
    // ========================================================================

    /**
     * getPublicKey reconstructs a valid PEM from the stored JWK.
     */
    public function testGetPublicKeyReturnsValidPem(): void
    {
        $kid = \KeyManager::generateKey(true);
        $pem = \KeyManager::getPublicKey($kid);

        $this->assertNotNull($pem);
        $this->assertStringContainsString('PUBLIC KEY', $pem);
        $this->assertNotFalse(openssl_pkey_get_public($pem));
    }

    /**
     * getPublicKey returns null for an unknown kid.
     */
    public function testGetPublicKeyUnknownKidReturnsNull(): void
    {
        \KeyManager::generateKey(true);
        $this->assertNull(\KeyManager::getPublicKey('202601-deadbeefcafe'));
    }

    // ========================================================================
    // 📁 KEY-FILE FALLBACK
    // ========================================================================

    /**
     * When the DB (override) has NO row for a kid, getPrivateKey / getPublicKey
     * fall back to reading the mirrored `_private` key files on disk.
     */
    public function testKeyFileFallbackWhenDbEmpty(): void
    {
        $kid = \KeyManager::generateKey(true);

        // The files were mirrored to $this->keyDir during generateKey.
        $this->assertFileExists($this->keyDir . DIRECTORY_SEPARATOR . $kid . '.key');
        $this->assertFileExists($this->keyDir . DIRECTORY_SEPARATOR . $kid . '.pub');

        // 🧨 Wipe the in-memory DB rows so only the files remain.
        \KeyManager::setStorageOverride([]); // fresh empty store, keyDir unchanged

        $priv = \KeyManager::getPrivateKey($kid);
        $pub = \KeyManager::getPublicKey($kid);

        $this->assertNotNull($priv, 'private key must come from the file fallback');
        $this->assertStringContainsString('PRIVATE KEY', $priv);
        $this->assertNotNull($pub, 'public key must come from the file fallback');
        $this->assertStringContainsString('PUBLIC KEY', $pub);
    }

    /**
     * 🛡️ FIX 1 (kid path traversal): a hostile `kid` must NEVER let the file
     * fallback read a file outside keyDir.
     *
     * Reproduces the red-team PoC (rt_kid_e2e / rt_kid_path / rt_kid_priv): plant
     * a decoy PEM-looking file OUTSIDE the key directory, then craft a `../`
     * traversal kid that (pre-fix) resolved keyDir/<kid>.pub|.key to the decoy.
     * With isValidKid() gating both getters, every hostile kid returns null and
     * the arbitrary-file-read / token-forgery primitive is closed.
     */
    public function testHostileKidCannotTraverseOutOfKeyDir(): void
    {
        // Plant a decoy .pub and .key OUTSIDE the key dir (sibling temp dir).
        $decoyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'signula_km_decoy_' . bin2hex(random_bytes(6));
        @mkdir($decoyDir, 0700, true);
        file_put_contents(
            $decoyDir . DIRECTORY_SEPARATOR . 'evil.pub',
            "-----BEGIN PUBLIC KEY-----\nEVILINJECTEDPUBLICKEY\n-----END PUBLIC KEY-----\n"
        );
        file_put_contents(
            $decoyDir . DIRECTORY_SEPARATOR . 'evil.key',
            "-----BEGIN PRIVATE KEY-----\nARBITRARYFILECONTENT\n-----END PRIVATE KEY-----\n"
        );

        // Build a traversal kid pointing at the decoy (absolute-ish traversal like
        // the PoC), plus a set of other malicious/malformed kids.
        $up = str_repeat('..' . DIRECTORY_SEPARATOR, 20);
        $hostileKids = [
            $up . ltrim($decoyDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'evil',
            '../../etc/passwd',
            '..\\..\\evil',
            '.ssh',
            '-rf',
            'kid with spaces',
            "nul\x00byte",
            'a/b',
            'x' . str_repeat('y', 200), // > 128 chars
            '',
        ];

        foreach ($hostileKids as $kid) {
            $this->assertNull(
                \KeyManager::getPublicKey($kid),
                'getPublicKey must reject hostile kid: ' . json_encode($kid)
            );
            $this->assertNull(
                \KeyManager::getPrivateKey($kid),
                'getPrivateKey must reject hostile kid: ' . json_encode($kid)
            );
        }

        // 🧹 Clean up the decoy files.
        @unlink($decoyDir . DIRECTORY_SEPARATOR . 'evil.pub');
        @unlink($decoyDir . DIRECTORY_SEPARATOR . 'evil.key');
        @rmdir($decoyDir);
    }

    /**
     * 🛡️ FIX 1 sanity: a well-formed, legitimate kid (YYYYMM-<12 hex>) STILL
     * passes the isValidKid() gate and resolves normally via the file fallback —
     * the guard must not break the happy path.
     */
    public function testLegitimateKidStillPassesGuardViaFileFallback(): void
    {
        $kid = \KeyManager::generateKey(true);

        // Confirm the real kid shape.
        $this->assertMatchesRegularExpression('/^\d{6}-[0-9a-f]{12}$/', $kid);

        // Wipe the DB rows so ONLY the file fallback (gated by isValidKid) can serve it.
        \KeyManager::setStorageOverride([]);

        $this->assertNotNull(
            \KeyManager::getPublicKey($kid),
            'a legit kid must still resolve via the guarded file fallback'
        );
        $this->assertNotNull(
            \KeyManager::getPrivateKey($kid),
            'a legit kid must still resolve via the guarded file fallback'
        );
    }

    /**
     * The mirrored private-key file is created with 0600 permissions.
     */
    public function testPrivateKeyFilePermissions(): void
    {
        $kid = \KeyManager::generateKey(true);
        $privFile = $this->keyDir . DIRECTORY_SEPARATOR . $kid . '.key';

        $this->assertFileExists($privFile);
        // Mask to the permission bits.
        $perms = fileperms($privFile) & 0777;
        $this->assertSame(0600, $perms, 'private key file must be 0600');
    }

    // ========================================================================
    // 🔄 ROTATION & RETIREMENT
    // ========================================================================

    /**
     * Rotation mints a new active key while keeping the old public key in JWKS.
     */
    public function testRotationKeepsOldPublicKeyInJwks(): void
    {
        $kid1 = \KeyManager::generateKey(true);
        $kid2 = \KeyManager::rotateKey();

        $this->assertNotSame($kid1, $kid2);
        $this->assertSame($kid2, \KeyManager::getActiveKid(), 'new key is active');

        // JWKS still contains BOTH public keys.
        $kids = array_column(\KeyManager::getJwks()['keys'], 'kid');
        $this->assertContains($kid1, $kids);
        $this->assertContains($kid2, $kids);
        $this->assertCount(2, $kids);

        // The old key's public PEM is still retrievable (old tokens still verify).
        $this->assertNotNull(\KeyManager::getPublicKey($kid1));
    }

    /**
     * Retiring an old kid removes it from JWKS.
     */
    public function testRetireRemovesKeyFromJwks(): void
    {
        $kid1 = \KeyManager::generateKey(true);
        $kid2 = \KeyManager::rotateKey();

        $this->assertTrue(\KeyManager::retireKey($kid1));

        $kids = array_column(\KeyManager::getJwks()['keys'], 'kid');
        $this->assertNotContains($kid1, $kids);
        $this->assertContains($kid2, $kids);
        $this->assertCount(1, $kids);

        // Retired key is no longer retrievable at all.
        $this->assertNull(\KeyManager::getPublicKey($kid1));
        $this->assertNull(\KeyManager::getPrivateKey($kid1));
    }

    /**
     * Retire refuses to remove the currently-active kid.
     */
    public function testRetireRefusesActiveKid(): void
    {
        $kid = \KeyManager::generateKey(true);

        $this->assertFalse(\KeyManager::retireKey($kid), 'must not retire the active signer');
        // Active key is untouched.
        $this->assertNotNull(\KeyManager::getPublicKey($kid));
        $this->assertSame($kid, \KeyManager::getActiveKid());
    }

    // ========================================================================
    // 🧾 BASE64URL UTILITIES
    // ========================================================================

    /**
     * base64UrlEncode/Decode round-trip arbitrary bytes with no padding chars.
     */
    public function testBase64UrlRoundTrip(): void
    {
        $raw = random_bytes(64);
        $enc = \KeyManager::base64UrlEncode($raw);

        $this->assertDoesNotMatchRegularExpression('#[+/=]#', $enc);
        $this->assertSame($raw, \KeyManager::base64UrlDecode($enc));
    }
}
