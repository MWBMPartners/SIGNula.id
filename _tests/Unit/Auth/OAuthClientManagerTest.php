<?php
/**
 * ============================================================================
 * 🧪 OAuthClientManager Unit Tests (G-001 Stage A1 — client registration model)
 * ============================================================================
 *
 * DB-FREE. Covers only the pure functions of OAuthClientManager — no
 * Database/ActivityLogger/SecurityUtils call is ever reached by the code paths
 * exercised here:
 *
 *   • generateRawClientIdentifier() / generateClientSecret() — FORMAT only
 *     (the DB-uniqueness-checked wrapper generateClientIdentifier() and the
 *     full registerClient() flow are covered by the Integration suite).
 *   • isExactRedirectMatch() — the #1 OAuth IdP security control. Exhaustively
 *     proves the EXACT-match rule: an identical URI is accepted; a
 *     trailing-slash, case, subpath, prefix, scheme, port, or
 *     query/fragment-appended variant is REJECTED. No normalisation of any
 *     kind is applied anywhere in this method.
 *   • scopesAllowed() — requested ⊆ allowed subset check.
 *   • computeSubject() (with an explicit $pairwiseSalt override, so it never
 *     touches the database) — determinism, per-sector + per-user divergence,
 *     the 'public' vs 'pairwise' branch, and the no-sector fallback.
 *
 * @package    SIGNula\Tests\Unit\Auth
 * @version    2.9.0-beta
 * @see        web/private_html/auth/OAuthClientManager.php
 * @see        .dev-team/specs/G-001.md §5.1 (redirect-URI exact-match), §5.13 (pairwise sub)
 */

namespace SIGNula\Tests\Unit\Auth;

use SIGNula\Tests\TestCase;
use OAuthClientManager;

requireSource('private_html/auth/OAuthClientManager.php');

/**
 * 🪪 OAuthClientManager pure-function test suite.
 */
class OAuthClientManagerTest extends TestCase
{
    // ========================================================================
    // 🆔 CLIENT_ID / CLIENT_SECRET FORMAT
    // ========================================================================

    /**
     * generateRawClientIdentifier() returns 32 lowercase hex chars (16 random
     * bytes) and is not trivially repeatable.
     */
    public function testGenerateRawClientIdentifierFormat(): void
    {
        $a = \OAuthClientManager::generateRawClientIdentifier();
        $b = \OAuthClientManager::generateRawClientIdentifier();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $b);
        $this->assertNotSame($a, $b, 'two consecutive generations must not collide');
    }

    /**
     * generateClientSecret() returns 64 lowercase hex chars (32 random bytes /
     * 256 bits) — mirrors the G-003 refresh-token entropy convention.
     */
    public function testGenerateClientSecretFormat(): void
    {
        $a = \OAuthClientManager::generateClientSecret();
        $b = \OAuthClientManager::generateClientSecret();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $b);
        $this->assertNotSame($a, $b);
    }

    // ========================================================================
    // 🎯 REDIRECT-URI EXACT-MATCH — the #1 OAuth IdP security control
    // ========================================================================

    /**
     * A byte-identical candidate is accepted.
     */
    public function testIsExactRedirectMatchAcceptsByteIdenticalUri(): void
    {
        $registered = ['https://app.example.com/callback', 'ihymns://auth/callback'];

        $this->assertTrue(\OAuthClientManager::isExactRedirectMatch('https://app.example.com/callback', $registered));
        $this->assertTrue(\OAuthClientManager::isExactRedirectMatch('ihymns://auth/callback', $registered));
    }

    /**
     * 🚫 A trailing slash is NOT tolerated — must fail unless byte-identical.
     */
    public function testIsExactRedirectMatchRejectsTrailingSlash(): void
    {
        $registered = ['https://app.example.com/callback'];

        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch('https://app.example.com/callback/', $registered));
    }

    /**
     * 🚫 Case differences (host or path) are NOT tolerated.
     */
    public function testIsExactRedirectMatchRejectsCaseDifference(): void
    {
        $registered = ['https://app.example.com/callback'];

        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch('https://App.Example.com/callback', $registered));
        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch('https://app.example.com/Callback', $registered));
        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch('HTTPS://app.example.com/callback', $registered));
    }

    /**
     * 🚫 A registered exact URI does NOT authorise a deeper subpath
     * (covert-redirect via path traversal within the same origin).
     */
    public function testIsExactRedirectMatchRejectsSubpath(): void
    {
        $registered = ['https://app.example.com/callback'];

        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch('https://app.example.com/callback/extra', $registered));
        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch('https://app.example.com/callback/../admin', $registered));
    }

    /**
     * 🚫 A registered URI is not a PREFIX match — appending characters directly
     * onto the registered value (no separator) must still fail.
     */
    public function testIsExactRedirectMatchRejectsPrefixExtension(): void
    {
        $registered = ['https://app.example.com/callback'];

        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch('https://app.example.com/callbackXYZ', $registered));
        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch('https://app.example.com.evil.com/callback', $registered));
    }

    /**
     * 🚫 Scheme swap (https registered → http candidate, or vice versa) fails.
     */
    public function testIsExactRedirectMatchRejectsSchemeSwap(): void
    {
        $registeredHttps = ['https://app.example.com/callback'];
        $registeredHttp  = ['http://app.example.com/callback'];

        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch('http://app.example.com/callback', $registeredHttps));
        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch('https://app.example.com/callback', $registeredHttp));
    }

    /**
     * 🚫 A different (or additional/missing) port fails.
     */
    public function testIsExactRedirectMatchRejectsPortDifference(): void
    {
        $registered = ['https://app.example.com:8443/callback'];

        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch('https://app.example.com:9443/callback', $registered));
        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch('https://app.example.com/callback', $registered));
    }

    /**
     * 🚫 Open-redirect attempts via an appended query string or fragment must
     * fail — a query/fragment is part of the exact string, not "extra" data
     * layered onto a matching prefix.
     */
    public function testIsExactRedirectMatchRejectsOpenRedirectAppendage(): void
    {
        $registered = ['https://app.example.com/callback'];

        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch(
            'https://app.example.com/callback?next=https://evil.example.com',
            $registered
        ));
        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch(
            'https://app.example.com/callback#https://evil.example.com',
            $registered
        ));
        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch(
            'https://app.example.com@evil.example.com/callback',
            $registered
        ));
    }

    /**
     * An entirely unregistered URI (or an empty allowlist) never matches.
     */
    public function testIsExactRedirectMatchRejectsUnregisteredUri(): void
    {
        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch('https://evil.example.com/callback', []));
        $this->assertFalse(\OAuthClientManager::isExactRedirectMatch(
            'https://evil.example.com/callback',
            ['https://app.example.com/callback']
        ));
    }

    // ========================================================================
    // 🔒 SCOPE SUBSET CHECK
    // ========================================================================

    /**
     * A requested scope set that is a subset of the allowed set passes.
     */
    public function testScopesAllowedAcceptsSubset(): void
    {
        $this->assertTrue(\OAuthClientManager::scopesAllowed('openid profile', 'openid profile email offline_access'));
        $this->assertTrue(\OAuthClientManager::scopesAllowed('openid', 'openid'));
    }

    /**
     * A requested scope NOT present in the allowed set is rejected (no scope
     * escalation beyond what the client registered for).
     */
    public function testScopesAllowedRejectsSuperset(): void
    {
        $this->assertFalse(\OAuthClientManager::scopesAllowed('openid admin', 'openid profile email'));
        $this->assertFalse(\OAuthClientManager::scopesAllowed('openid offline_access', 'openid profile'));
    }

    /**
     * An empty request is vacuously allowed (nothing to check).
     */
    public function testScopesAllowedVacuouslyTrueForEmptyRequest(): void
    {
        $this->assertTrue(\OAuthClientManager::scopesAllowed('', 'openid profile'));
        $this->assertTrue(\OAuthClientManager::scopesAllowed('   ', 'openid profile'));
    }

    /**
     * Extra whitespace between scope tokens does not affect the result.
     */
    public function testScopesAllowedHandlesExtraWhitespace(): void
    {
        $this->assertTrue(\OAuthClientManager::scopesAllowed('  openid   profile ', 'openid  profile   email'));
    }

    // ========================================================================
    // 🕵️ PAIRWISE SUBJECT IDENTIFIERS
    // ========================================================================

    /**
     * subjectType 'public' returns the raw (stringified) userID, ignoring any
     * salt/sector.
     */
    public function testComputeSubjectPublicReturnsRawUserID(): void
    {
        $client = ['subjectType' => 'public', 'sectorIdentifier' => 'app.example.com', 'clientIdentifier' => 'abc123'];

        $this->assertSame('42', \OAuthClientManager::computeSubject(42, $client, 'irrelevant-salt'));
    }

    /**
     * Pairwise subject computation is DETERMINISTIC: same user + same client +
     * same salt ⇒ same sub, every time.
     */
    public function testComputeSubjectPairwiseIsDeterministic(): void
    {
        $client = ['subjectType' => 'pairwise', 'sectorIdentifier' => 'app.example.com', 'clientIdentifier' => 'abc123'];
        $salt   = 'fixed-test-salt-for-determinism';

        $subA = \OAuthClientManager::computeSubject(7, $client, $salt);
        $subB = \OAuthClientManager::computeSubject(7, $client, $salt);

        $this->assertSame($subA, $subB);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $subA, 'sub is a SHA-256 hex digest');
    }

    /**
     * 🕵️ Pairwise subs DIFFER per sector (privacy property: two RPs in
     * different sectors cannot correlate the same user via a shared sub).
     */
    public function testComputeSubjectPairwiseDiffersPerSector(): void
    {
        $salt = 'fixed-test-salt';
        $clientSectorA = ['subjectType' => 'pairwise', 'sectorIdentifier' => 'appA.example.com'];
        $clientSectorB = ['subjectType' => 'pairwise', 'sectorIdentifier' => 'appB.example.com'];

        $subA = \OAuthClientManager::computeSubject(99, $clientSectorA, $salt);
        $subB = \OAuthClientManager::computeSubject(99, $clientSectorB, $salt);

        $this->assertNotSame($subA, $subB, 'same user, different sector, must yield DIFFERENT subs');
    }

    /**
     * Pairwise subs DIFFER per user within the same sector.
     */
    public function testComputeSubjectPairwiseDiffersPerUser(): void
    {
        $client = ['subjectType' => 'pairwise', 'sectorIdentifier' => 'app.example.com'];
        $salt   = 'fixed-test-salt';

        $subUser1 = \OAuthClientManager::computeSubject(1, $client, $salt);
        $subUser2 = \OAuthClientManager::computeSubject(2, $client, $salt);

        $this->assertNotSame($subUser1, $subUser2);
    }

    /**
     * Pairwise subs also differ per SALT (defence: rotating the server-side
     * salt invalidates every previously-issued pairwise sub).
     */
    public function testComputeSubjectPairwiseDiffersPerSalt(): void
    {
        $client = ['subjectType' => 'pairwise', 'sectorIdentifier' => 'app.example.com'];

        $subSalt1 = \OAuthClientManager::computeSubject(1, $client, 'salt-one');
        $subSalt2 = \OAuthClientManager::computeSubject(1, $client, 'salt-two');

        $this->assertNotSame($subSalt1, $subSalt2);
    }

    /**
     * When no sectorIdentifier is configured, the client falls back to a
     * client-scoped sector (`client:<clientIdentifier>`) — so two sectorless
     * clients STILL get different subs for the same user, rather than
     * silently degrading to a shared/public-like identifier.
     */
    public function testComputeSubjectPairwiseFallsBackToClientScopedSectorWhenSectorNull(): void
    {
        $clientA = ['subjectType' => 'pairwise', 'sectorIdentifier' => null, 'clientIdentifier' => 'client-aaa'];
        $clientB = ['subjectType' => 'pairwise', 'sectorIdentifier' => null, 'clientIdentifier' => 'client-bbb'];
        $salt    = 'fixed-test-salt';

        $subA = \OAuthClientManager::computeSubject(5, $clientA, $salt);
        $subB = \OAuthClientManager::computeSubject(5, $clientB, $salt);

        $this->assertNotSame($subA, $subB, 'sectorless clients must still be distinguished from one another');
    }

    /**
     * A client array with NO 'subjectType' key at all defaults to 'pairwise'
     * (matches the tblOAuthClients.subjectType column DEFAULT).
     */
    public function testComputeSubjectDefaultsToPairwiseWhenSubjectTypeKeyMissing(): void
    {
        $clientNoType   = ['sectorIdentifier' => 'app.example.com', 'clientIdentifier' => 'abc'];
        $clientExplicit = ['subjectType' => 'pairwise', 'sectorIdentifier' => 'app.example.com', 'clientIdentifier' => 'abc'];
        $salt = 'fixed-test-salt';

        $this->assertSame(
            \OAuthClientManager::computeSubject(3, $clientExplicit, $salt),
            \OAuthClientManager::computeSubject(3, $clientNoType, $salt),
            'omitting subjectType must behave identically to explicit "pairwise"'
        );
        // And, critically, it must NOT behave like 'public' (raw userID).
        $this->assertNotSame('3', \OAuthClientManager::computeSubject(3, $clientNoType, $salt));
    }
}
