<?php
/**
 * ============================================================================
 * 🧪 OidcDiscoveryService Unit Tests (G-001 Stage A4 — discovery document)
 * ============================================================================
 *
 * DB-FREE. buildDocument() only calls getSetting() (stubbed by the test
 * bootstrap to read $GLOBALS['test_settings']) — no Database access. Proves
 * the document is valid JSON-encodable, advertises issuer = oidc.issuer, the
 * 4 required endpoints under that issuer, subject_types_supported includes
 * 'pairwise', RS256 signing, S256 PKCE, and that grant_types_supported
 * contains BOTH 'authorization_code' AND 'refresh_token' — the latter wired
 * at `/oauth/token` in G-001 Stage A5 (B-058 follow-up).
 *
 * @package    SIGNula\Tests\Unit\Auth
 * @version    2.9.0-beta
 * @see        web/private_html/auth/OidcDiscoveryService.php
 * @see        web/public_html/.well-known/openid-configuration/index.php
 */

namespace SIGNula\Tests\Unit\Auth;

use SIGNula\Tests\TestCase;
use OidcDiscoveryService;

requireSource('private_html/auth/OidcDiscoveryService.php');

/**
 * 🪪 OidcDiscoveryService pure-builder test suite.
 */
class OidcDiscoveryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        setTestSetting('oidc.issuer', 'https://signula.id');
    }

    /**
     * The document is a valid, JSON-encodable array and round-trips through
     * json_encode()/json_decode() without loss.
     */
    public function testDocumentIsValidJsonEncodable(): void
    {
        $document = OidcDiscoveryService::buildDocument();

        $json = json_encode($document, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);

        $decoded = json_decode($json, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertIsArray($decoded);
        $this->assertSame($document, $decoded);
    }

    /**
     * issuer = oidc.issuer, and all four required endpoints are built under
     * that SAME issuer host (never a hardcoded host).
     */
    public function testIssuerAndEndpointsDeriveFromOidcIssuerSetting(): void
    {
        $document = OidcDiscoveryService::buildDocument();

        $this->assertSame('https://signula.id', $document['issuer']);
        $this->assertSame('https://signula.id/oauth/authorize-idp', $document['authorization_endpoint']);
        $this->assertSame('https://signula.id/oauth/token', $document['token_endpoint']);
        $this->assertSame('https://signula.id/oauth/userinfo', $document['userinfo_endpoint']);
        $this->assertSame('https://signula.id/.well-known/jwks.json', $document['jwks_uri']);
        $this->assertSame('https://signula.id/oauth/revoke', $document['revocation_endpoint']);
    }

    /**
     * A DIFFERENT oidc.issuer value is honoured (proves no hardcoded host).
     */
    public function testDifferentIssuerSettingIsHonoured(): void
    {
        setTestSetting('oidc.issuer', 'https://staging.signula.id');

        $document = OidcDiscoveryService::buildDocument();

        $this->assertSame('https://staging.signula.id', $document['issuer']);
        $this->assertSame('https://staging.signula.id/oauth/token', $document['token_endpoint']);
    }

    /**
     * subject_types_supported includes 'pairwise' (the default for new client
     * registrations), RS256 is the only signing alg, S256 the only PKCE
     * method, and response_types_supported is exactly ["code"].
     */
    public function testAlgorithmAndTypePolicyAdvertisedCorrectly(): void
    {
        $document = OidcDiscoveryService::buildDocument();

        $this->assertContains('pairwise', $document['subject_types_supported']);
        $this->assertContains('public', $document['subject_types_supported']);
        $this->assertSame(['RS256'], $document['id_token_signing_alg_values_supported']);
        $this->assertSame(['S256'], $document['code_challenge_methods_supported']);
        $this->assertSame(['code'], $document['response_types_supported']);
    }

    /**
     * 🔒 grant_types_supported MUST contain BOTH 'authorization_code' and
     * 'refresh_token' — the refresh_token grant is now wired at
     * /oauth/token (G-001 Stage A5, once TokenService::refresh() gained its
     * client-binding check, B-058) so advertising it here correctly informs
     * RP OIDC libraries that auto-detect supported grants.
     */
    public function testGrantTypesAdvertisesAuthorizationCodeAndRefreshToken(): void
    {
        $document = OidcDiscoveryService::buildDocument();

        $this->assertSame(['authorization_code', 'refresh_token'], $document['grant_types_supported']);
        $this->assertContains('refresh_token', $document['grant_types_supported']);
    }

    /**
     * scopes_supported includes the core OIDC scopes this provider actually
     * supports, and token_endpoint_auth_methods_supported includes 'none'
     * (for public/PKCE-only clients).
     */
    public function testScopesAndAuthMethodsAdvertised(): void
    {
        $document = OidcDiscoveryService::buildDocument();

        $this->assertContains('openid', $document['scopes_supported']);
        $this->assertContains('email', $document['scopes_supported']);
        $this->assertContains('profile', $document['scopes_supported']);

        $this->assertContains('client_secret_basic', $document['token_endpoint_auth_methods_supported']);
        $this->assertContains('client_secret_post', $document['token_endpoint_auth_methods_supported']);
        $this->assertContains('none', $document['token_endpoint_auth_methods_supported']);
    }
}
