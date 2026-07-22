<?php
/**
 * ============================================================================
 * 🧪 OAuth Consent Revocation Integration Tests (G-001 Stage A4 — "Connected Apps")
 * ============================================================================
 *
 * DB-bound tests against the real `signula_test` database. Covers the TWO
 * pieces the `/settings/connected-apps` page's "Revoke access" action relies
 * on, exercised DIRECTLY (no HTTP request needed — the page itself is a thin
 * wrapper around these calls):
 *
 *   • {@see \TokenService::revokeClientForUser()} — the NEW per-(user,client)
 *     refresh-family revoke lever (narrower than revokeAllForUser(), which
 *     would incorrectly log the user out of EVERY client). Scoped strictly to
 *     the given clientID; a DIFFERENT client's tokens for the SAME user are
 *     untouched.
 *   • {@see \OAuthAuthorizeService::revokeConsent()} — bundles the consent-row
 *     revoke (tblOAuthConsents.revokedAt) with the token-family revoke above,
 *     so a single "Revoke access" button press has BOTH effects: the app
 *     disappears from the active Connected Apps list, AND its refresh tokens
 *     stop working (a subsequent refresh() throws).
 *
 * @package    SIGNula\Tests\Integration\Auth
 * @version    2.9.0-beta
 * @see        web/private_html/security/TokenService.php (revokeClientForUser())
 * @see        web/private_html/auth/OAuthAuthorizeService.php (revokeConsent())
 * @see        web/public_html/settings/connected-apps.php (the page that wires these together)
 * @see        .dev-team/specs/G-001.md §6
 */

namespace SIGNula\Tests\Integration\Auth;

use SIGNula\Tests\DatabaseTestCase;
use JwtException;
use KeyManager;
use Jwt;
use TokenService;
use OAuthClientManager;
use OAuthAuthorizeService;

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

/**
 * 🔌 "Connected Apps" consent-revocation test suite.
 */
class OAuthConsentRevocationTest extends DatabaseTestCase
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

        \KeyManager::resetOverrides();
        \KeyManager::setStorageOverride([]);
        $this->keyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'signula_ocr_test_' . bin2hex(random_bytes(6));
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
            ['OCR Partner ' . $suffix, 'ocr_partner_' . $suffix . '@example.com'],
            'ss'
        );
    }

    private function createUser(): int
    {
        $uuid     = self::generateTestUuid();
        $username = 'ocr_' . bin2hex(random_bytes(4));
        $email    = 'ocr_' . bin2hex(random_bytes(4)) . '@example.com';

        return \Database::insert(
            "INSERT INTO tblUsers
                (userUUID, username, email, passwordHash, displayName, accountStatus, emailVerified, mfaEnabled, createdAt)
             VALUES (?, ?, ?, ?, 'OCR User', 'active', 1, 0, NOW())",
            [$uuid, $username, $email, password_hash('x', PASSWORD_ARGON2ID)],
            'ssss'
        );
    }

    private function registerClient(?int $partnerID, string $name = 'OCR Test RP'): array
    {
        $result = \OAuthClientManager::registerClient(
            $partnerID,
            $name,
            'confidential',
            ['https://rp.example.com/callback'],
            ['openid', 'profile']
        );
        $this->assertIsArray($result, 'client fixture registration must succeed');

        return [
            'clientID'         => (int) $result['clientID'],
            'clientIdentifier' => $result['clientIdentifier'],
        ];
    }

    private function insertLiveConsent(int $userID, int $clientID, string $scope = 'openid profile'): void
    {
        \Database::query(
            "INSERT INTO tblOAuthConsents (userID, clientID, scope, grantedAt, revokedAt, ipAddress)
             VALUES (?, ?, ?, NOW(), NULL, '203.0.113.5')",
            [$userID, $clientID, $scope],
            'iis'
        );
    }

    // ========================================================================
    // 🚫 revokeConsent() — consent-row effect
    // ========================================================================

    /**
     * revokeConsent() stamps revokedAt and the app disappears from the
     * covering-consent check (the "active list" the Connected Apps page reads).
     */
    public function testRevokeConsentRemovesAppFromActiveList(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerClient($partnerID);

        $this->insertLiveConsent($userID, $reg['clientID']);

        $this->assertTrue(
            \OAuthAuthorizeService::hasCoveringConsent($userID, $reg['clientID'], 'openid'),
            'a freshly-inserted live consent must cover the request'
        );

        $wasRevoked = \OAuthAuthorizeService::revokeConsent($userID, $reg['clientID']);
        $this->assertTrue($wasRevoked, 'revoking a LIVE consent must report true');

        $this->assertFalse(
            \OAuthAuthorizeService::hasCoveringConsent($userID, $reg['clientID'], 'openid'),
            'a revoked consent must no longer cover ANY request — the app is gone from the active list'
        );

        $row = \Database::fetchOne(
            "SELECT revokedAt FROM tblOAuthConsents WHERE userID = ? AND clientID = ?",
            [$userID, $reg['clientID']],
            'ii'
        );
        $this->assertNotNull($row['revokedAt'], 'the consent row itself must carry a revokedAt timestamp');
    }

    /**
     * Revoking an ALREADY-revoked (or never-granted) consent is a safe,
     * idempotent no-op that reports false (no LIVE row was actually revoked).
     */
    public function testRevokeConsentIsIdempotent(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerClient($partnerID);

        $this->insertLiveConsent($userID, $reg['clientID']);

        $first  = \OAuthAuthorizeService::revokeConsent($userID, $reg['clientID']);
        $second = \OAuthAuthorizeService::revokeConsent($userID, $reg['clientID']);

        $this->assertTrue($first);
        $this->assertFalse($second, 'a SECOND revoke of an already-revoked consent must report false');
    }

    // ========================================================================
    // 🧵 revokeConsent() → TokenService::revokeClientForUser() — token effect
    // ========================================================================

    /**
     * revokeConsent() ALSO kills the client's outstanding refresh-token
     * family for this user — a subsequent refresh() then fails.
     */
    public function testRevokeConsentKillsRefreshTokenFamilyForThatClient(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $reg       = $this->registerClient($partnerID);

        $this->insertLiveConsent($userID, $reg['clientID']);

        // 🔧 G-001 Stage A5: offline_access is required to get a refresh token.
        $pair = \TokenService::issueForClient($userID, $reg['clientID'], $reg['clientIdentifier'], ['openid', 'offline_access']);

        \OAuthAuthorizeService::revokeConsent($userID, $reg['clientID']);

        // 🔒 B-058: pass the OWNING client's id so this exercises the
        //    "family revoked by consent-revoke" path specifically, not a
        //    client-binding mismatch.
        $this->expectException(JwtException::class);
        \TokenService::refresh($pair['refresh_token'], $reg['clientID']);
    }

    /**
     * revokeConsent() for Client A does NOT touch the SAME user's active
     * refresh-token family for a DIFFERENT Client B (scoped revoke, not
     * revokeAllForUser()'s too-broad "log out everywhere").
     */
    public function testRevokeConsentDoesNotAffectDifferentClientsTokens(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $regA      = $this->registerClient($partnerID, 'OCR Test RP A');
        $regB      = $this->registerClient($partnerID, 'OCR Test RP B');

        $this->insertLiveConsent($userID, $regA['clientID']);
        $this->insertLiveConsent($userID, $regB['clientID']);

        // 🔧 G-001 Stage A5: offline_access is required to get a refresh token.
        $pairA = \TokenService::issueForClient($userID, $regA['clientID'], $regA['clientIdentifier'], ['openid', 'offline_access']);
        $pairB = \TokenService::issueForClient($userID, $regB['clientID'], $regB['clientIdentifier'], ['openid', 'offline_access']);

        \OAuthAuthorizeService::revokeConsent($userID, $regA['clientID']);

        // Client A's family is dead… (pass A's own id — B-058 client binding)
        try {
            \TokenService::refresh($pairA['refresh_token'], $regA['clientID']);
            $this->fail('Client A refresh token must be revoked');
        } catch (JwtException $e) {
            // expected
        }

        // …but Client B's family is UNTOUCHED.
        $rotatedB = \TokenService::refresh($pairB['refresh_token'], $regB['clientID']);
        $this->assertIsString($rotatedB['access_token']);

        // And Client B's consent is still live.
        $this->assertTrue(\OAuthAuthorizeService::hasCoveringConsent($userID, $regB['clientID'], 'openid'));
    }

    // ========================================================================
    // 🎯 TokenService::revokeClientForUser() — direct, scope-isolation proof
    // ========================================================================

    /**
     * revokeClientForUser() revokes ONLY the rows matching BOTH userID and
     * clientID — a DIFFERENT user's tokens for the SAME client are untouched,
     * and it returns the count of rows it actually revoked.
     */
    public function testRevokeClientForUserIsScopedToUserAndClientOnly(): void
    {
        $partnerID = $this->createPartner();
        $userA     = $this->createUser();
        $userB     = $this->createUser();
        $reg       = $this->registerClient($partnerID);

        // 🔧 G-001 Stage A5: offline_access is required to get a refresh token.
        $pairUserA = \TokenService::issueForClient($userA, $reg['clientID'], $reg['clientIdentifier'], ['openid', 'offline_access']);
        $pairUserB = \TokenService::issueForClient($userB, $reg['clientID'], $reg['clientIdentifier'], ['openid', 'offline_access']);

        $revokedCount = \TokenService::revokeClientForUser($userA, $reg['clientID']);
        $this->assertSame(1, $revokedCount, 'exactly one row (user A + this client) must be revoked');

        // User A's family for this client is dead… (pass this client's own id)
        try {
            \TokenService::refresh($pairUserA['refresh_token'], $reg['clientID']);
            $this->fail('User A refresh token for this client must be revoked');
        } catch (JwtException $e) {
            // expected
        }

        // …but User B's family for the SAME client is untouched.
        $rotatedB = \TokenService::refresh($pairUserB['refresh_token'], $reg['clientID']);
        $this->assertIsString($rotatedB['access_token']);
    }
}
