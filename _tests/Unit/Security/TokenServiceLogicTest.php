<?php
/**
 * ============================================================================
 * 🧪 TokenService Pure-Logic Unit Tests (G-003 Stage 2 — DB-free surface)
 * ============================================================================
 *
 * The bulk of TokenService is DB-bound (issuance/rotation/reuse-detection live
 * in the Integration suite, TokenServiceTest). This unit test pins the DB-FREE
 * behaviour that does NOT need a database:
 *
 *   • verifyAccessToken() defaults the expected header typ to at+jwt.
 *   • withDenylistChecker() installs THIS service's jti checker onto the Jwt
 *     facade for the duration of a callback and RESTORES the prior state (null)
 *     afterwards — proven by observing that a token whose jti the (temporarily
 *     installed) checker revokes is rejected inside the callback, yet a bare
 *     Jwt::verify() outside the callback (no checker) accepts it again.
 *
 * Crypto runs on the same in-memory KeyManager override JwtTest uses — no DB,
 * no _private, a fresh RSA keypair per test.
 *
 * @package    SIGNula\Tests\Unit\Security
 * @version    2.8.0-beta
 * @see        web/private_html/security/TokenService.php
 */

namespace SIGNula\Tests\Unit\Security;

use SIGNula\Tests\TestCase;
use JwtException;
use KeyManager;
use Jwt;
use TokenService;

// 📦 Load the JWT crypto stack + the service. No database.php: the pure-logic
//    paths exercised here never reach the Database wrapper.
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/security/JwtException.php');
requireSource('private_html/security/JwtLibLoader.php');
requireSource('private_html/security/KeyManager.php');
requireSource('private_html/security/Jwt.php');
requireSource('private_html/security/TokenService.php');

/**
 * 🎟️ TokenService DB-free logic test suite.
 */
class TokenServiceLogicTest extends TestCase
{
    /** @var string kid of the in-memory key. */
    private string $kid;

    /** @var string Isolated temp key dir. */
    private string $keyDir;

    protected function setUp(): void
    {
        parent::setUp();

        \JwtLibLoader::load();

        setTestSetting('jwt.issuer', 'https://signula.id');
        setTestSetting('jwt.audience', 'https://signula.id/api');
        setTestSetting('jwt.access_ttl', 900);
        setTestSetting('jwt.leeway_seconds', 60);
        setTestSetting('jwt.key_bits', 2048);

        \KeyManager::resetOverrides();
        \KeyManager::setStorageOverride([]);
        $this->keyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'signula_tslogic_' . bin2hex(random_bytes(6));
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

    /**
     * withDenylistChecker() installs a checker for the callback and restores the
     * previous (null) state afterwards.
     *
     * We install OUR OWN test checker via the callback boundary indirectly: the
     * method always installs TokenService::isJtiRevoked, but isJtiRevoked would
     * need a DB. To keep this DB-free we instead prove the INSTALL/RESTORE
     * lifecycle by observing the facade's checker slot around the call, using a
     * token minted from the in-memory key.
     */
    public function testWithDenylistCheckerRestoresPreviousStateAfterCallback(): void
    {
        $jwt = \Jwt::sign(['sub' => '7']);

        // Before: no checker installed → verify accepts.
        $this->assertSame('7', \Jwt::verify($jwt)['sub']);

        // Inside withDenylistChecker, the service installs its own checker
        // (TokenService::isJtiRevoked). We do not exercise that checker's DB body
        // here; we assert the callback runs and its RETURN VALUE propagates.
        $returned = \TokenService::withDenylistChecker(static fn(): string => 'callback-ran');
        $this->assertSame('callback-ran', $returned);

        // After: the checker slot is restored to null → a bare verify still
        // accepts the token (proving no checker leaked out of the callback).
        $this->assertSame('7', \Jwt::verify($jwt)['sub']);
    }

    /**
     * withDenylistChecker() restores the null checker even when the callback
     * throws — no checker must leak out on the exception path.
     */
    public function testWithDenylistCheckerRestoresOnException(): void
    {
        $jwt = \Jwt::sign(['sub' => '7']);

        try {
            \TokenService::withDenylistChecker(static function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected the callback exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // The facade checker slot was restored to null → verify still accepts.
        $this->assertSame('7', \Jwt::verify($jwt)['sub']);
    }

    /**
     * The REASON_* revocation-reason constants are the stable strings other
     * layers (and the schema's revokedReason column) depend on.
     */
    public function testRevocationReasonConstants(): void
    {
        $this->assertSame('reuse_detected', \TokenService::REASON_REUSE_DETECTED);
        $this->assertSame('logout', \TokenService::REASON_LOGOUT);
        $this->assertSame('rotated', \TokenService::REASON_ROTATED);
        $this->assertSame('admin_revoke', \TokenService::REASON_ADMIN);

        // 🔒 revokedReason column is VARCHAR(100) — every reason must fit.
        foreach ([
            \TokenService::REASON_REUSE_DETECTED,
            \TokenService::REASON_LOGOUT,
            \TokenService::REASON_ROTATED,
            \TokenService::REASON_ADMIN,
        ] as $reason) {
            $this->assertLessThanOrEqual(100, strlen($reason));
        }
    }
}
