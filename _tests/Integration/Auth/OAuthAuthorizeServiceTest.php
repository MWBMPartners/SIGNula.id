<?php
/**
 * ============================================================================
 * 🧪 OAuthAuthorizeService Integration Tests (G-001 Stage A2 — /oauth/authorize-idp)
 * ============================================================================
 *
 * DB-bound tests against the real `signula_test` database. Registers REAL
 * clients via OAuthClientManager (Stage A1) and drives OAuthAuthorizeService
 * (Stage A2) — the engine behind `/oauth/authorize-idp` — end to end:
 *
 *   • validateAuthorizeRequest() against a REAL hydrated client: unknown
 *     client_id (fatal, no redirect target), a tampered/unregistered
 *     redirect_uri (fatal, no redirect to it), response_type mismatch, an
 *     out-of-allowlist scope, missing PKCE for a public client, and a
 *     disallowed `plain` PKCE method — all via the SAME entry point the
 *     controller calls.
 *   • issueAuthorizationCode() persists a HASHED code (never the plaintext)
 *     bound to client/user/redirect/scope/PKCE, with a future expiresAt and
 *     a NULL consumedAt (single-use — Stage A3 sets it on exchange), and
 *     UPSERTs a live tblOAuthConsents row.
 *   • hasCoveringConsent() lets a repeat authorize auto-skip the consent
 *     screen once a non-revoked consent already covers the requested scope,
 *     and correctly stops covering once that consent is revoked.
 *
 * @package    SIGNula\Tests\Integration\Auth
 * @version    2.9.0-beta
 * @see        web/private_html/auth/OAuthAuthorizeService.php
 * @see        web/public_html/oauth/authorize-idp.php
 * @see        _database/migrations/031_oauth_provider_clients.sql
 * @see        .dev-team/specs/G-001.md §5
 * @see        _tests/Integration/Auth/OAuthClientTest.php (fixture-helper style this mirrors)
 */

namespace SIGNula\Tests\Integration\Auth;

use SIGNula\Tests\DatabaseTestCase;
use OAuthAuthorizeService;
use OAuthClientManager;

// ============================================================================
// 📦 LOAD REQUIRED SOURCE FILES (dependency order)
// ============================================================================
requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/auth/OAuthClientManager.php');
requireSource('private_html/auth/OAuthAuthorizeService.php');

/**
 * 🪪 OAuthAuthorizeService Integration test suite.
 */
class OAuthAuthorizeServiceTest extends DatabaseTestCase
{
    /**
     * Tables reset before each test (mirrors OAuthClientTest.php's list).
     * tblSettings is deliberately NOT truncated — it holds the seeded
     * "oidc." policy rows (unused here since getSetting() is stubbed to read
     * $GLOBALS['test_settings'] in the test harness, but kept consistent
     * with the sibling suite regardless).
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
    // 🔧 FIXTURE HELPERS (mirror OAuthClientTest.php)
    // ========================================================================

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

    private function createUser(): int
    {
        $uuid     = self::generateTestUuid();
        $username = 'oas_' . bin2hex(random_bytes(4));
        $email    = 'oas_' . bin2hex(random_bytes(4)) . '@example.com';

        return \Database::insert(
            "INSERT INTO tblUsers
                (userUUID, username, email, passwordHash, displayName, accountStatus, emailVerified, mfaEnabled, createdAt)
             VALUES (?, ?, ?, ?, 'OAS User', 'active', 1, 0, NOW())",
            [$uuid, $username, $email, password_hash('x', PASSWORD_ARGON2ID)],
            'ssss'
        );
    }

    /**
     * Register a confidential, PKCE-required client with one redirect_uri
     * and the given allowedScopes, and return its HYDRATED row (as
     * OAuthClientManager::getClient() would return it — this is exactly
     * what the controller passes into validateAuthorizeRequest()).
     */
    private function registerClient(
        ?int $partnerID,
        string $redirectUri = 'https://rp.example.com/callback',
        string $allowedScopes = 'openid profile email offline_access',
        string $clientType = 'confidential'
    ): array {
        $result = \OAuthClientManager::registerClient(
            $partnerID,
            'Integration Test RP',
            $clientType,
            [$redirectUri],
            explode(' ', $allowedScopes)
        );

        $this->assertIsArray($result, 'client registration must succeed for these fixtures');

        return \OAuthClientManager::getClient($result['clientIdentifier']);
    }

    /**
     * A minimal, valid request-params array targeting the given client's
     * (already registered) redirect_uri, with a well-formed S256 challenge.
     */
    private function makeValidParams(string $redirectUri, string $scope = 'openid profile'): array
    {
        return [
            'client_id'             => '', // filled in by the caller with the real clientIdentifier
            'redirect_uri'          => $redirectUri,
            'response_type'         => 'code',
            'scope'                 => $scope,
            'state'                 => 'state-' . bin2hex(random_bytes(4)),
            'code_challenge'        => str_repeat('A', 43),
            'code_challenge_method' => 'S256',
            'nonce'                 => 'nonce-' . bin2hex(random_bytes(4)),
        ];
    }

    // ========================================================================
    // ✅ HAPPY PATH — full validate + issue, real DB round-trip
    // ========================================================================

    /**
     * A fully valid request (registered client, exact redirect_uri, S256
     * PKCE, openid scope) validates OK, and issuing the code persists a
     * HASHED, correctly-bound, not-yet-consumed tblOAuthAuthCodes row plus a
     * live tblOAuthConsents row.
     */
    public function testHappyPathValidatesAndIssuesCodeCorrectly(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $client    = $this->registerClient($partnerID);

        $params = $this->makeValidParams('https://rp.example.com/callback');
        $params['client_id'] = $client['clientIdentifier'];

        $validated = \OAuthAuthorizeService::validateAuthorizeRequest($params, $client);
        $this->assertTrue($validated['ok'], 'a fully valid request must validate OK');
        $this->assertFalse($validated['fatal']);

        $issued = \OAuthAuthorizeService::issueAuthorizationCode($client, $userID, $validated, '203.0.113.7');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $issued['code'], 'the raw code is 256 bits of hex');
        $this->assertSame(hash('sha256', $issued['code']), $issued['codeHash']);

        // --- tblOAuthAuthCodes row -------------------------------------------------
        $row = $this->getRecord('tblOAuthAuthCodes', ['codeID' => $issued['codeID']]);
        $this->assertIsArray($row);
        $this->assertSame($issued['codeHash'], $row['codeHash']);
        $this->assertSame((int) $client['clientID'], (int) $row['clientID']);
        $this->assertSame($userID, (int) $row['userID']);
        $this->assertSame('https://rp.example.com/callback', $row['redirectURI']);
        $this->assertSame('openid profile', $row['scope']);
        $this->assertSame(str_repeat('A', 43), $row['codeChallenge']);
        $this->assertSame('S256', $row['codeChallengeMethod']);
        $this->assertSame($params['nonce'], $row['nonce']);
        $this->assertSame('203.0.113.7', $row['ipAddress']);
        $this->assertNull($row['consumedAt'], 'a freshly-issued code must NOT be pre-consumed');
        $this->assertGreaterThan(time(), strtotime($row['expiresAt']), 'expiresAt must be in the future');

        // --- the RAW code is never stored — only its hash ---------------------------
        $rawAsHash = \Database::fetchOne(
            "SELECT codeID FROM tblOAuthAuthCodes WHERE codeHash = ?",
            [$issued['code']],
            's'
        );
        $this->assertNull($rawAsHash, 'the raw plaintext code must never appear as a stored "hash"');

        // --- tblOAuthConsents row upserted -------------------------------------------
        $consent = $this->getRecord('tblOAuthConsents', ['userID' => $userID, 'clientID' => (int) $client['clientID']]);
        $this->assertIsArray($consent);
        $this->assertSame('openid profile', $consent['scope']);
        $this->assertNull($consent['revokedAt']);
    }

    // ========================================================================
    // 🚫 REJECTIONS
    // ========================================================================

    /**
     * An unknown client_id resolves to a null client (exactly what
     * OAuthClientManager::getClient() returns for a non-existent identifier)
     * — validateAuthorizeRequest() must report FATAL invalid_client with NO
     * redirect target.
     */
    public function testUnknownClientIdIsFatalInvalidClientWithNoRedirectTarget(): void
    {
        $client = \OAuthClientManager::getClient(str_repeat('f', 32)); // never registered
        $this->assertNull($client);

        $params = $this->makeValidParams('https://anything.example.com/callback');
        $params['client_id'] = str_repeat('f', 32);

        $result = \OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['fatal'], 'unknown client must be FATAL — the controller must render a local error page, never redirect');
        $this->assertSame('invalid_client', $result['error']);
        $this->assertNull($result['redirectUri']);
    }

    /**
     * A redirect_uri that does not byte-exactly match ANY of the real,
     * DB-registered URIs for this client is FATAL — no redirect anywhere,
     * including NOT to the tampered value itself.
     */
    public function testTamperedRedirectUriIsFatalWithNoRedirectTarget(): void
    {
        $partnerID = $this->createPartner();
        $client    = $this->registerClient($partnerID, 'https://rp.example.com/callback');

        $params = $this->makeValidParams('https://evil.example.com/callback');
        $params['client_id'] = $client['clientIdentifier'];

        $result = \OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['fatal']);
        $this->assertSame('invalid_request', $result['error']);
        $this->assertNull($result['redirectUri'], 'no redirect target — must never redirect to the unregistered URI');
    }

    /**
     * response_type other than "code" is rejected AFTER redirect_uri has
     * been proven safe — so it IS redirectable, with the RP's state echoed.
     */
    public function testUnsupportedResponseTypeIsRedirectableWithEchoedState(): void
    {
        $partnerID = $this->createPartner();
        $client    = $this->registerClient($partnerID);

        $params = $this->makeValidParams('https://rp.example.com/callback');
        $params['client_id'] = $client['clientIdentifier'];
        $params['response_type'] = 'token';
        $params['state'] = 'preserve-me';

        $result = \OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['fatal']);
        $this->assertSame('unsupported_response_type', $result['error']);
        $this->assertSame('https://rp.example.com/callback', $result['redirectUri']);
        $this->assertSame('preserve-me', $result['state']);
    }

    /**
     * A scope outside the client's registered allowedScopes is rejected as
     * invalid_scope.
     */
    public function testScopeOutsideAllowedScopesIsInvalidScope(): void
    {
        $partnerID = $this->createPartner();
        $client    = $this->registerClient($partnerID, 'https://rp.example.com/callback', 'openid profile');

        $params = $this->makeValidParams('https://rp.example.com/callback', 'openid admin');
        $params['client_id'] = $client['clientIdentifier'];

        $result = \OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_scope', $result['error']);
    }

    /**
     * A PUBLIC client (PKCE forced ON at registration, per OAuthClientManager)
     * without a code_challenge is rejected as invalid_request.
     */
    public function testMissingPkceForPublicClientIsInvalidRequest(): void
    {
        $partnerID = $this->createPartner();
        $client    = $this->registerClient($partnerID, 'https://spa.example.com/callback', 'openid profile', 'public');

        $params = $this->makeValidParams('https://spa.example.com/callback');
        $params['client_id'] = $client['clientIdentifier'];
        $params['code_challenge'] = '';
        $params['code_challenge_method'] = '';

        $result = \OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['fatal']);
        $this->assertSame('invalid_request', $result['error']);
    }

    /**
     * The `plain` PKCE method is rejected while oidc.allow_plain_pkce is off
     * (the harness default — nothing sets it, so getSetting() falls back to
     * the `false` default matching migration 031's seed).
     */
    public function testPlainPkceMethodRejectedWhenNotAllowed(): void
    {
        $partnerID = $this->createPartner();
        $client    = $this->registerClient($partnerID);

        $params = $this->makeValidParams('https://rp.example.com/callback');
        $params['client_id'] = $client['clientIdentifier'];
        $params['code_challenge_method'] = 'plain';

        $result = \OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_request', $result['error']);
    }

    // ========================================================================
    // 🤝 CONSENT PERSISTENCE — auto-skip on a repeat authorize
    // ========================================================================

    /**
     * After a first successful issuance, a SECOND authorize for the same
     * user+client+covered-scope finds a covering consent (hasCoveringConsent
     * === true) and STILL successfully issues a fresh code when the
     * controller falls through to issueAuthorizationCode() — proving the
     * auto-skip path (no re-shown consent screen) still ends in a working
     * code, and the consent row is refreshed (not duplicated).
     */
    public function testSecondAuthorizeWithCoveringConsentAutoSkipsAndStillIssuesCode(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $client    = $this->registerClient($partnerID);
        $clientID  = (int) $client['clientID'];

        $params = $this->makeValidParams('https://rp.example.com/callback');
        $params['client_id'] = $client['clientIdentifier'];
        $validated = \OAuthAuthorizeService::validateAuthorizeRequest($params, $client);
        $this->assertTrue($validated['ok']);

        // --- first issuance (as if the user had just clicked "Allow") ------------
        $first = \OAuthAuthorizeService::issueAuthorizationCode($client, $userID, $validated, '127.0.0.1');
        $this->assertNotEmpty($first['code']);

        // --- a covering consent now exists for the SAME (or a narrower) scope ----
        $this->assertTrue(
            \OAuthAuthorizeService::hasCoveringConsent($userID, $clientID, 'openid profile'),
            'the just-recorded consent must cover a repeat request for the same scope'
        );
        $this->assertTrue(
            \OAuthAuthorizeService::hasCoveringConsent($userID, $clientID, 'openid'),
            'a covering consent also covers any NARROWER subsequent request'
        );
        $this->assertFalse(
            \OAuthAuthorizeService::hasCoveringConsent($userID, $clientID, 'openid profile email offline_access'),
            'a consent does NOT cover a request for scopes beyond what was originally granted'
        );

        // --- controller's auto-skip path: fall through straight to a second issue ---
        $second = \OAuthAuthorizeService::issueAuthorizationCode($client, $userID, $validated, '127.0.0.1');
        $this->assertNotEmpty($second['code']);
        $this->assertNotSame($first['code'], $second['code'], 'each issuance mints a genuinely NEW single-use code');

        // Two distinct auth-code rows now exist...
        $count = $this->countRecords('tblOAuthAuthCodes', ['clientID' => $clientID, 'userID' => $userID]);
        $this->assertSame(2, $count, 'both issuances must be persisted as separate single-use codes');

        // ...but the consent was UPSERTED, not duplicated (UNIQUE(userID,clientID)).
        $consentCount = $this->countRecords('tblOAuthConsents', ['userID' => $userID, 'clientID' => $clientID]);
        $this->assertSame(1, $consentCount, 'consent is refreshed in place, never duplicated');
    }

    /**
     * Once a consent is REVOKED (revokedAt set — e.g. via a future
     * "Connected Apps" hub), it no longer counts as covering, even for the
     * exact same scope it used to grant.
     */
    public function testRevokedConsentNoLongerCovers(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $client    = $this->registerClient($partnerID);
        $clientID  = (int) $client['clientID'];

        $params = $this->makeValidParams('https://rp.example.com/callback');
        $params['client_id'] = $client['clientIdentifier'];
        $validated = \OAuthAuthorizeService::validateAuthorizeRequest($params, $client);
        \OAuthAuthorizeService::issueAuthorizationCode($client, $userID, $validated, '127.0.0.1');

        $this->assertTrue(\OAuthAuthorizeService::hasCoveringConsent($userID, $clientID, 'openid profile'));

        \Database::query(
            "UPDATE tblOAuthConsents SET revokedAt = NOW() WHERE userID = ? AND clientID = ?",
            [$userID, $clientID],
            'ii'
        );

        $this->assertFalse(
            \OAuthAuthorizeService::hasCoveringConsent($userID, $clientID, 'openid profile'),
            'a revoked consent must no longer be treated as covering'
        );
    }

    /**
     * A user with NO prior consent at all is correctly reported as
     * not-covered (the very first authorize for a client must always show
     * the consent screen).
     */
    public function testNoConsentRowMeansNotCovered(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $client    = $this->registerClient($partnerID);

        $this->assertFalse(
            \OAuthAuthorizeService::hasCoveringConsent($userID, (int) $client['clientID'], 'openid')
        );
    }

    // ========================================================================
    // 🔗 END-TO-END STATE ECHO ON THE SUCCESS REDIRECT
    // ========================================================================

    /**
     * The final success redirect (built from a real, DB-issued code) carries
     * BOTH the code and the RP's originally-supplied state.
     */
    public function testSuccessRedirectUrlCarriesIssuedCodeAndEchoedState(): void
    {
        $partnerID = $this->createPartner();
        $userID    = $this->createUser();
        $client    = $this->registerClient($partnerID);

        $params = $this->makeValidParams('https://rp.example.com/callback');
        $params['client_id'] = $client['clientIdentifier'];
        $params['state'] = 'end-to-end-state';

        $validated = \OAuthAuthorizeService::validateAuthorizeRequest($params, $client);
        $issued = \OAuthAuthorizeService::issueAuthorizationCode($client, $userID, $validated, '127.0.0.1');

        $url = \OAuthAuthorizeService::buildRedirectUrl($validated['redirectUri'], [
            'code'  => $issued['code'],
            'state' => $validated['state'],
        ]);

        $this->assertSame(
            'https://rp.example.com/callback?code=' . $issued['code'] . '&state=end-to-end-state',
            $url
        );
    }
}
