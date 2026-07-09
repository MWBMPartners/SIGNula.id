<?php
/**
 * ============================================================================
 * 🧪 OAuthClientManager Integration Tests (G-001 Stage A1 — client registration)
 * ============================================================================
 *
 * DB-bound tests against the real `signula_test` database (migration
 * 031_oauth_provider_clients.sql). Covers the full registration → validation →
 * rotation → verification → listing lifecycle end to end:
 *
 *   • registerClient() persists a HASHED secret — the plaintext is returned
 *     once and never appears anywhere in the stored row.
 *   • getClient()/getClientByID() round-trip the hydrated row (redirect URIs +
 *     parsed scope/grant arrays).
 *   • validateRedirectUri() EXACT match against the real
 *     tblOAuthClientRedirectUris table — accepts the byte-identical URI and
 *     REJECTS every trailing-slash / subpath / prefix / scheme-swap /
 *     port-difference / open-redirect-appendage variant.
 *   • rotateSecret() invalidates the OLD secret immediately.
 *   • verifyClientSecret() accepts the right secret, rejects a wrong one, and
 *     refuses to "verify" anything for a PUBLIC client (confidential/public
 *     confusion defence).
 *   • listClients() is scoped strictly to the requested partnerID — a
 *     partner's clients never leak into another partner's list.
 *   • The pairwise-subject salt is minted at runtime, ENCRYPTED at rest, and
 *     stable across repeated computeSubject() calls (no explicit salt).
 *
 * @package    SIGNula\Tests\Integration\Auth
 * @version    2.9.0-beta
 * @see        web/private_html/auth/OAuthClientManager.php
 * @see        _database/migrations/031_oauth_provider_clients.sql
 * @see        .dev-team/specs/G-001.md §5
 */

namespace SIGNula\Tests\Integration\Auth;

use SIGNula\Tests\DatabaseTestCase;
use OAuthClientManager;

// ============================================================================
// 📦 LOAD REQUIRED SOURCE FILES (dependency order)
// ============================================================================
requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/auth/OAuthClientManager.php');

/**
 * 🪪 OAuthClientManager Integration test suite.
 */
class OAuthClientTest extends DatabaseTestCase
{
    /**
     * Tables reset before each test (truncated — see DatabaseTestCase /
     * TokenServiceTest for why TRUNCATE, not just rollback, is used here).
     * tblSettings is deliberately NOT truncated — it holds the seeded
     * "oidc." and "jwt." policy rows other tests rely on (mirrors
     * reset_database()'s own exclusion list).
     *
     * @var array<int,string>
     */
    protected array $truncateTables = [
        'tblOAuthAccessGrants',
        'tblOAuthConsents',
        'tblOAuthAuthCodes',
        'tblOAuthClientRedirectUris',
        'tblOAuthClients',
        'tblActivityLog',
        'tblUsers',
        'tblPartners',
    ];

    // ========================================================================
    // 🔧 FIXTURE HELPERS
    // ========================================================================

    /**
     * Create a minimal active partner THROUGH Database:: (same connection the
     * code-under-test uses) and return its partnerID.
     */
    private function createPartner(string $name = 'Test Partner'): int
    {
        $suffix = bin2hex(random_bytes(4));

        return \Database::insert(
            "INSERT INTO tblPartners (partnerName, partnerEmail, accountTier, isActive, isVerified, verifiedAt)
             VALUES (?, ?, 'premium', 1, 1, NOW())",
            [$name . ' ' . $suffix, 'partner_' . $suffix . '@example.com'],
            'ss'
        );
    }

    /**
     * Create a minimal active user (mirrors TokenServiceTest::createUser()).
     */
    private function createUser(): int
    {
        $uuid     = self::generateTestUuid();
        $username = 'oc_' . bin2hex(random_bytes(4));
        $email    = 'oc_' . bin2hex(random_bytes(4)) . '@example.com';

        return \Database::insert(
            "INSERT INTO tblUsers
                (userUUID, username, email, passwordHash, displayName, accountStatus, emailVerified, mfaEnabled, createdAt)
             VALUES (?, ?, ?, ?, 'OC User', 'active', 1, 0, NOW())",
            [$uuid, $username, $email, password_hash('x', PASSWORD_ARGON2ID)],
            'ssss'
        );
    }

    // ========================================================================
    // 📝 REGISTRATION — secret hashing
    // ========================================================================

    /**
     * Registering a CONFIDENTIAL client persists only the SHA-256 HASH of the
     * secret — the plaintext returned to the caller never appears in the
     * stored row.
     */
    public function testRegisterConfidentialClientPersistsHashedSecretNotPlaintext(): void
    {
        $partnerID = $this->createPartner();

        $result = \OAuthClientManager::registerClient(
            $partnerID,
            'Test Confidential App',
            'confidential',
            ['https://app.example.com/callback'],
            ['openid', 'profile', 'email']
        );

        $this->assertIsArray($result);
        $this->assertNotEmpty($result['clientSecret'], 'plaintext secret must be returned ONCE at registration');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['clientSecret']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result['clientIdentifier']);

        // 🔒 The raw DB row holds ONLY the hash — never the plaintext.
        $row = \Database::fetchOne(
            "SELECT clientSecretHash FROM tblOAuthClients WHERE clientID = ?",
            [$result['clientID']],
            'i'
        );
        $this->assertSame(hash('sha256', $result['clientSecret']), $row['clientSecretHash']);
        $this->assertNotSame($result['clientSecret'], $row['clientSecretHash'], 'plaintext must never equal the stored hash');

        // 🔎 Defence in depth: the plaintext literally does not appear as a
        //    stored hash anywhere (i.e. it wasn't accidentally hashed with a
        //    no-op or stored as-is elsewhere).
        $plainAsHash = \Database::fetchOne(
            "SELECT clientID FROM tblOAuthClients WHERE clientSecretHash = ?",
            [$result['clientSecret']],
            's'
        );
        $this->assertNull($plainAsHash, 'the plaintext secret must never be stored as a "hash"');
    }

    /**
     * A PUBLIC client has NO secret at all (clientSecretHash IS NULL) and
     * PKCE is FORCED on even if the caller tries to opt out.
     */
    public function testRegisterPublicClientHasNoSecretAndForcesPkce(): void
    {
        $partnerID = $this->createPartner();

        $result = \OAuthClientManager::registerClient(
            $partnerID,
            'Test SPA',
            'public',
            ['https://spa.example.com/callback'],
            ['openid'],
            ['pkceRequired' => false] // 🚫 must be ignored for public clients
        );

        $this->assertIsArray($result);
        $this->assertNull($result['clientSecret'], 'public clients receive no secret');

        $row = \Database::fetchOne(
            "SELECT clientSecretHash, pkceRequired, clientType FROM tblOAuthClients WHERE clientID = ?",
            [$result['clientID']],
            'i'
        );
        $this->assertNull($row['clientSecretHash']);
        $this->assertSame('public', $row['clientType']);
        $this->assertSame(1, (int) $row['pkceRequired'], 'PKCE must be forced ON for public clients regardless of opts');
    }

    /**
     * Multiple redirect URIs (web callback + native custom-scheme deep link)
     * are all persisted and returned exactly as registered.
     */
    public function testRegisterPersistsMultipleRedirectUrisExactly(): void
    {
        $partnerID = $this->createPartner();
        $uris = ['https://app.example.com/callback', 'ihymns://auth/callback'];

        $result = \OAuthClientManager::registerClient(
            $partnerID, 'Multi-URI App', 'confidential', $uris, ['openid']
        );

        $client = \OAuthClientManager::getClientByID($result['clientID']);
        $this->assertCount(2, $client['redirectUris']);
        $this->assertSame($uris, $client['redirectUris']);
    }

    /**
     * A first-party/global client may register with partnerID = null.
     */
    public function testRegisterFirstPartyClientAllowsNullPartnerID(): void
    {
        $result = \OAuthClientManager::registerClient(
            null,
            'First-Party App',
            'confidential',
            ['https://first-party.example.com/callback'],
            ['openid'],
            ['isFirstParty' => true]
        );

        $this->assertIsArray($result);
        $client = \OAuthClientManager::getClientByID($result['clientID']);
        $this->assertNull($client['partnerID']);
        $this->assertTrue($client['isFirstParty']);
    }

    /**
     * Registration rejects an unknown clientType (validation failure ⇒ false,
     * mirrors APIKeyManager's return-false-on-validation-failure convention).
     */
    public function testRegisterRejectsInvalidClientType(): void
    {
        $partnerID = $this->createPartner();

        $result = \OAuthClientManager::registerClient(
            $partnerID, 'Bad Type', 'not-a-real-type', ['https://app.example.com/cb'], ['openid']
        );

        $this->assertFalse($result);
    }

    /**
     * Registration rejects an empty redirect-URI list.
     */
    public function testRegisterRejectsEmptyRedirectUris(): void
    {
        $partnerID = $this->createPartner();

        $result = \OAuthClientManager::registerClient(
            $partnerID, 'No Redirects', 'confidential', [], ['openid']
        );

        $this->assertFalse($result);
    }

    // ========================================================================
    // 🔍 RETRIEVAL
    // ========================================================================

    /**
     * getClient() returns null for an unknown client_id.
     */
    public function testGetClientReturnsNullForUnknownIdentifier(): void
    {
        $this->assertNull(\OAuthClientManager::getClient(str_repeat('a', 32)));
    }

    /**
     * Scopes are persisted space-delimited and parsed back into an array.
     */
    public function testScopesPersistedAndParsedIntoArray(): void
    {
        $partnerID = $this->createPartner();
        $result = \OAuthClientManager::registerClient(
            $partnerID, 'Scoped App', 'confidential',
            ['https://app.example.com/cb'], ['openid', 'profile', 'email', 'offline_access']
        );

        $client = \OAuthClientManager::getClient($result['clientIdentifier']);
        $this->assertSame('openid profile email offline_access', $client['allowedScopes']);
        $this->assertSame(['openid', 'profile', 'email', 'offline_access'], $client['allowedScopesArray']);
    }

    // ========================================================================
    // 🎯 REDIRECT-URI EXACT-MATCH (DB-backed)
    // ========================================================================

    /**
     * validateRedirectUri() accepts the byte-identical registered URI and
     * REJECTS every trailing-slash / subpath / prefix / scheme-swap /
     * port-difference / open-redirect-appendage variant, against the REAL
     * tblOAuthClientRedirectUris table.
     */
    public function testValidateRedirectUriExactMatchAcceptsAndRejectsVariants(): void
    {
        $partnerID = $this->createPartner();
        $result = \OAuthClientManager::registerClient(
            $partnerID, 'Redirect Test App', 'confidential',
            ['https://app.example.com:8443/callback'], ['openid']
        );
        $clientID = $result['clientID'];

        // ✅ Byte-identical accepted.
        $this->assertTrue(\OAuthClientManager::validateRedirectUri($clientID, 'https://app.example.com:8443/callback'));

        // 🚫 Trailing slash.
        $this->assertFalse(\OAuthClientManager::validateRedirectUri($clientID, 'https://app.example.com:8443/callback/'));

        // 🚫 Subpath.
        $this->assertFalse(\OAuthClientManager::validateRedirectUri($clientID, 'https://app.example.com:8443/callback/extra'));

        // 🚫 Prefix extension (no separator).
        $this->assertFalse(\OAuthClientManager::validateRedirectUri($clientID, 'https://app.example.com:8443/callbackXYZ'));

        // 🚫 Scheme swap.
        $this->assertFalse(\OAuthClientManager::validateRedirectUri($clientID, 'http://app.example.com:8443/callback'));

        // 🚫 Port difference (and no-port).
        $this->assertFalse(\OAuthClientManager::validateRedirectUri($clientID, 'https://app.example.com:9443/callback'));
        $this->assertFalse(\OAuthClientManager::validateRedirectUri($clientID, 'https://app.example.com/callback'));

        // 🚫 Case difference.
        $this->assertFalse(\OAuthClientManager::validateRedirectUri($clientID, 'https://App.Example.com:8443/callback'));

        // 🚫 Open-redirect appendage (query / fragment / userinfo tricks).
        $this->assertFalse(\OAuthClientManager::validateRedirectUri(
            $clientID,
            'https://app.example.com:8443/callback?next=https://evil.example.com'
        ));
        $this->assertFalse(\OAuthClientManager::validateRedirectUri(
            $clientID,
            'https://app.example.com:8443@evil.example.com/callback'
        ));

        // 🚫 An entirely different (unregistered) host.
        $this->assertFalse(\OAuthClientManager::validateRedirectUri($clientID, 'https://evil.example.com/callback'));
    }

    /**
     * A code minted / validated against one client's redirect URI must not be
     * satisfied by a DIFFERENT client's registered URI, even if the strings
     * happen to be registered on both — validateRedirectUri() is always
     * scoped to the given clientID.
     */
    public function testValidateRedirectUriIsScopedPerClient(): void
    {
        $partnerID = $this->createPartner();
        $clientA = \OAuthClientManager::registerClient(
            $partnerID, 'Client A', 'confidential', ['https://a.example.com/cb'], ['openid']
        );
        $clientB = \OAuthClientManager::registerClient(
            $partnerID, 'Client B', 'confidential', ['https://b.example.com/cb'], ['openid']
        );

        $this->assertTrue(\OAuthClientManager::validateRedirectUri($clientA['clientID'], 'https://a.example.com/cb'));
        $this->assertFalse(\OAuthClientManager::validateRedirectUri($clientA['clientID'], 'https://b.example.com/cb'));
        $this->assertFalse(\OAuthClientManager::validateRedirectUri($clientB['clientID'], 'https://a.example.com/cb'));
    }

    // ========================================================================
    // 🔄 SECRET ROTATION
    // ========================================================================

    /**
     * rotateSecret() mints a new secret and invalidates the OLD one
     * immediately (the old plaintext no longer verifies).
     */
    public function testRotateSecretInvalidatesOldSecret(): void
    {
        $partnerID = $this->createPartner();
        $registered = \OAuthClientManager::registerClient(
            $partnerID, 'Rotate Me', 'confidential', ['https://app.example.com/cb'], ['openid']
        );
        $oldSecret = $registered['clientSecret'];
        $identifier = $registered['clientIdentifier'];

        $this->assertTrue(\OAuthClientManager::verifyClientSecret($identifier, $oldSecret), 'old secret verifies before rotation');

        $rotated = \OAuthClientManager::rotateSecret($registered['clientID']);
        $this->assertIsArray($rotated);
        $newSecret = $rotated['clientSecret'];

        $this->assertNotSame($oldSecret, $newSecret);
        $this->assertTrue(\OAuthClientManager::verifyClientSecret($identifier, $newSecret), 'new secret verifies after rotation');
        $this->assertFalse(\OAuthClientManager::verifyClientSecret($identifier, $oldSecret), 'OLD secret must be dead after rotation');
    }

    /**
     * rotateSecret() refuses to rotate a PUBLIC client (nothing to rotate).
     */
    public function testRotateSecretRefusesPublicClient(): void
    {
        $partnerID = $this->createPartner();
        $result = \OAuthClientManager::registerClient(
            $partnerID, 'Public No Rotate', 'public', ['https://spa.example.com/cb'], ['openid']
        );

        $this->assertFalse(\OAuthClientManager::rotateSecret($result['clientID']));
    }

    // ========================================================================
    // 🔑 SECRET VERIFICATION
    // ========================================================================

    /**
     * verifyClientSecret() accepts the correct secret and rejects a wrong one
     * (including a near-miss / truncated / extended variant).
     */
    public function testVerifyClientSecretAcceptsCorrectRejectsWrong(): void
    {
        $partnerID = $this->createPartner();
        $result = \OAuthClientManager::registerClient(
            $partnerID, 'Verify Me', 'confidential', ['https://app.example.com/cb'], ['openid']
        );

        $this->assertTrue(\OAuthClientManager::verifyClientSecret($result['clientIdentifier'], $result['clientSecret']));
        $this->assertFalse(\OAuthClientManager::verifyClientSecret($result['clientIdentifier'], 'totally-wrong-secret'));
        $this->assertFalse(\OAuthClientManager::verifyClientSecret($result['clientIdentifier'], substr($result['clientSecret'], 0, -1)));
        $this->assertFalse(\OAuthClientManager::verifyClientSecret($result['clientIdentifier'], $result['clientSecret'] . 'x'));
    }

    /**
     * 🛡️ Confidential/public confusion defence: a PUBLIC client can NEVER
     * "verify" any secret (it has none) — this must fail closed, not throw or
     * accidentally match on a null-hash comparison.
     */
    public function testVerifyClientSecretRejectsPublicClientPresentingAnySecret(): void
    {
        $partnerID = $this->createPartner();
        $result = \OAuthClientManager::registerClient(
            $partnerID, 'Public Confusion Test', 'public', ['https://spa.example.com/cb'], ['openid']
        );

        $this->assertFalse(\OAuthClientManager::verifyClientSecret($result['clientIdentifier'], ''));
        $this->assertFalse(\OAuthClientManager::verifyClientSecret($result['clientIdentifier'], 'anything'));
        $this->assertFalse(\OAuthClientManager::verifyClientSecret($result['clientIdentifier'], hash('sha256', '')));
    }

    /**
     * verifyClientSecret() on an unknown client_id fails closed.
     */
    public function testVerifyClientSecretRejectsUnknownClient(): void
    {
        $this->assertFalse(\OAuthClientManager::verifyClientSecret(str_repeat('b', 32), 'whatever'));
    }

    // ========================================================================
    // 📋 LISTING — ownership scoping
    // ========================================================================

    /**
     * listClients() is scoped strictly to the requested partnerID — one
     * partner's clients never appear in another partner's list.
     */
    public function testListClientsScopedToPartner(): void
    {
        $partnerA = $this->createPartner('Partner A');
        $partnerB = $this->createPartner('Partner B');

        \OAuthClientManager::registerClient($partnerA, 'A1', 'confidential', ['https://a1.example.com/cb'], ['openid']);
        \OAuthClientManager::registerClient($partnerA, 'A2', 'public', ['https://a2.example.com/cb'], ['openid']);
        \OAuthClientManager::registerClient($partnerB, 'B1', 'confidential', ['https://b1.example.com/cb'], ['openid']);

        $listA = \OAuthClientManager::listClients($partnerA);
        $listB = \OAuthClientManager::listClients($partnerB);

        $this->assertCount(2, $listA);
        $this->assertCount(1, $listB);

        foreach ($listA as $client) {
            $this->assertSame($partnerA, (int) $client['partnerID']);
        }
        $this->assertSame($partnerB, (int) $listB[0]['partnerID']);

        // 🛡️ Cross-check: none of partner A's client identifiers appear in
        //    partner B's list (no ownership leakage).
        $identifiersA = array_column($listA, 'clientIdentifier');
        $identifiersB = array_column($listB, 'clientIdentifier');
        $this->assertEmpty(array_intersect($identifiersA, $identifiersB));
    }

    // ========================================================================
    // 🚫 DEACTIVATION
    // ========================================================================

    /**
     * deactivate() sets isActive = 0.
     */
    public function testDeactivateSetsIsActiveFalse(): void
    {
        $partnerID = $this->createPartner();
        $result = \OAuthClientManager::registerClient(
            $partnerID, 'Deactivate Me', 'confidential', ['https://app.example.com/cb'], ['openid']
        );

        $this->assertTrue(\OAuthClientManager::deactivate($result['clientID']));

        $client = \OAuthClientManager::getClientByID($result['clientID']);
        $this->assertFalse($client['isActive']);
    }

    /**
     * deactivate() on an unknown clientID returns false (0 rows affected).
     */
    public function testDeactivateUnknownClientReturnsFalse(): void
    {
        $this->assertFalse(\OAuthClientManager::deactivate(999999999));
    }

    // ========================================================================
    // 🕵️ PAIRWISE SALT — runtime creation (DB path)
    // ========================================================================

    /**
     * computeSubject() WITHOUT an explicit salt reads/creates the real
     * `oauth.pairwise_salt` tblSettings row. The value is ENCRYPTED at rest
     * (never the raw salt) and the resulting sub is stable across calls.
     */
    public function testPairwiseSaltIsCreatedEncryptedAndPersistsAcrossCalls(): void
    {
        $client = ['subjectType' => 'pairwise', 'sectorIdentifier' => 'persist.example.com'];

        $subA = \OAuthClientManager::computeSubject(555555, $client); // no $pairwiseSalt -> hits the DB
        $subB = \OAuthClientManager::computeSubject(555555, $client);

        $this->assertSame($subA, $subB, 'the DB-backed pairwise salt must be stable across calls');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $subA);

        $row = \Database::fetchOne(
            "SELECT settingValue, isSensitive, settingCategory FROM tblSettings WHERE settingKey = ?",
            ['oauth.pairwise_salt'],
            's'
        );
        $this->assertNotNull($row, 'oauth.pairwise_salt setting row must exist after first use');
        $this->assertSame(1, (int) $row['isSensitive'], 'the salt must be flagged isSensitive');
        $this->assertSame('oauth', $row['settingCategory']);

        $decrypted = \SecurityUtils::decrypt($row['settingValue']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $decrypted, 'decrypted salt is 32 random bytes of hex');
        $this->assertNotSame($decrypted, $row['settingValue'], 'the stored value must be ENCRYPTED, not the raw salt');
    }

    /**
     * A different sector (DB-backed salt path) still yields a different sub
     * for the same user — the runtime-created salt is applied consistently.
     */
    public function testPairwiseSaltDbPathDiffersPerSector(): void
    {
        $clientA = ['subjectType' => 'pairwise', 'sectorIdentifier' => 'db-sector-a.example.com'];
        $clientB = ['subjectType' => 'pairwise', 'sectorIdentifier' => 'db-sector-b.example.com'];

        $subA = \OAuthClientManager::computeSubject(777, $clientA);
        $subB = \OAuthClientManager::computeSubject(777, $clientB);

        $this->assertNotSame($subA, $subB);
    }
}
