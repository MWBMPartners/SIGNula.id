<?php
/**
 * ============================================================================
 * 🧪 OAuthAuthorizeService Unit Tests (G-001 Stage A2 — /oauth/authorize-idp)
 * ============================================================================
 *
 * DB-FREE. Covers only the PURE functions of OAuthAuthorizeService — no
 * Database call is ever reached by the code paths exercised here:
 *
 *   • validateAuthorizeRequest() — the full RFC 6749/RFC 7636/OIDC rule set:
 *     unknown/inactive client (fatal, no redirect target), missing/mismatched
 *     redirect_uri (fatal), response_type, scope subset + "openid" required,
 *     PKCE required/optional + method + charset validation, state echoing,
 *     nonce length.
 *   • buildRedirectUrl() — query-string append logic (both with and without
 *     a pre-existing query string on the redirect_uri) and null/empty
 *     filtering.
 *   • scopeLabels() — known vs. unknown scope token labelling.
 *
 * hasCoveringConsent() / issueAuthorizationCode() are DB-bound and covered by
 * _tests/Integration/Auth/OAuthAuthorizeServiceTest.php instead.
 *
 * @package    SIGNula\Tests\Unit\Auth
 * @version    2.9.0-beta
 * @see        web/private_html/auth/OAuthAuthorizeService.php
 * @see        .dev-team/specs/G-001.md §5
 */

namespace SIGNula\Tests\Unit\Auth;

use SIGNula\Tests\TestCase;
use OAuthAuthorizeService;

requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/auth/OAuthClientManager.php');
requireSource('private_html/auth/OAuthAuthorizeService.php');

/**
 * 🪪 OAuthAuthorizeService pure-function test suite.
 */
class OAuthAuthorizeServiceTest extends TestCase
{
    // ========================================================================
    // 🔧 FIXTURE HELPERS
    // ========================================================================

    /**
     * A minimal, hydrated confidential client row (as OAuthClientManager::
     * getClient() would return), PKCE required by default.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function makeClient(array $overrides = []): array
    {
        $defaults = [
            'clientID'         => 1,
            'clientIdentifier' => str_repeat('a', 32),
            'clientName'       => 'Test RP',
            'clientType'       => 'confidential',
            'isActive'         => true,
            'pkceRequired'     => true,
            'allowedScopes'    => 'openid profile email offline_access',
            'redirectUris'     => ['https://rp.example.com/callback'],
            'logoURI'          => null,
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * A minimal valid request params array (all required fields present).
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function makeParams(array $overrides = []): array
    {
        $defaults = [
            'client_id'             => str_repeat('a', 32),
            'redirect_uri'          => 'https://rp.example.com/callback',
            'response_type'         => 'code',
            'scope'                 => 'openid profile',
            'state'                 => 'xyz123',
            'code_challenge'        => str_repeat('A', 43),
            'code_challenge_method' => 'S256',
            'nonce'                 => 'nonce-value',
        ];

        return array_merge($defaults, $overrides);
    }

    // ========================================================================
    // 🚫 CLIENT / REDIRECT_URI — FATAL (no redirect target proven yet)
    // ========================================================================

    public function testNullClientIsFatalInvalidClient(): void
    {
        $result = OAuthAuthorizeService::validateAuthorizeRequest($this->makeParams(), null);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['fatal'], 'unknown client must be FATAL — never redirect anywhere');
        $this->assertSame('invalid_client', $result['error']);
        $this->assertNull($result['redirectUri']);
    }

    public function testInactiveClientIsFatalInvalidClient(): void
    {
        $client = $this->makeClient(['isActive' => false]);
        $result = OAuthAuthorizeService::validateAuthorizeRequest($this->makeParams(), $client);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['fatal']);
        $this->assertSame('invalid_client', $result['error']);
    }

    public function testMissingRedirectUriIsFatalInvalidRequest(): void
    {
        $client = $this->makeClient();
        $params = $this->makeParams(['redirect_uri' => '']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['fatal']);
        $this->assertSame('invalid_request', $result['error']);
        $this->assertNull($result['redirectUri']);
    }

    public function testMismatchedRedirectUriIsFatalInvalidRequest(): void
    {
        $client = $this->makeClient();
        $params = $this->makeParams(['redirect_uri' => 'https://evil.example.com/callback']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['fatal'], 'an unregistered redirect_uri must NEVER be redirected to');
        $this->assertSame('invalid_request', $result['error']);
        $this->assertNull($result['redirectUri']);
    }

    public function testTrailingSlashRedirectUriVariantIsFatal(): void
    {
        // 🎯 Exact-match, not prefix-match — a trailing slash must still fail.
        $client = $this->makeClient();
        $params = $this->makeParams(['redirect_uri' => 'https://rp.example.com/callback/']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertTrue($result['fatal']);
        $this->assertSame('invalid_request', $result['error']);
    }

    // ========================================================================
    // 🚦 response_type
    // ========================================================================

    public function testUnsupportedResponseTypeRedirectsWithEchoedState(): void
    {
        $client = $this->makeClient();
        $params = $this->makeParams(['response_type' => 'token', 'state' => 'my-state']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['fatal'], 'past the redirect_uri check, errors must be redirectable');
        $this->assertSame('unsupported_response_type', $result['error']);
        $this->assertSame('https://rp.example.com/callback', $result['redirectUri']);
        $this->assertSame('my-state', $result['state']);
    }

    // ========================================================================
    // 🔒 SCOPE
    // ========================================================================

    public function testEmptyScopeIsInvalidScope(): void
    {
        $client = $this->makeClient();
        $params = $this->makeParams(['scope' => '']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['fatal']);
        $this->assertSame('invalid_scope', $result['error']);
    }

    public function testScopeNotSubsetOfAllowedIsInvalidScope(): void
    {
        $client = $this->makeClient(['allowedScopes' => 'openid profile']);
        $params = $this->makeParams(['scope' => 'openid admin']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_scope', $result['error']);
    }

    public function testScopeMissingOpenidIsInvalidScope(): void
    {
        $client = $this->makeClient();
        $params = $this->makeParams(['scope' => 'profile email']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_scope', $result['error'], 'this OIDC-only provider requires the openid scope');
    }

    // ========================================================================
    // 🔐 PKCE
    // ========================================================================

    public function testMissingCodeChallengeForPublicClientIsInvalidRequest(): void
    {
        $client = $this->makeClient(['clientType' => 'public', 'pkceRequired' => true]);
        $params = $this->makeParams(['code_challenge' => '', 'code_challenge_method' => '']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['fatal']);
        $this->assertSame('invalid_request', $result['error']);
    }

    public function testMissingCodeChallengeWhenRequiredByGlobalSettingIsInvalidRequest(): void
    {
        // Confidential client that does NOT itself force PKCE, but the global
        // oidc.require_pkce policy (default true — no fixture overrides it) does.
        $client = $this->makeClient(['clientType' => 'confidential', 'pkceRequired' => false]);
        $params = $this->makeParams(['code_challenge' => '', 'code_challenge_method' => '']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_request', $result['error']);
    }

    public function testPkceOptionalWhenNotRequiredAnywhereAndOmitted(): void
    {
        setTestSetting('oidc.require_pkce', false);
        $client = $this->makeClient(['clientType' => 'confidential', 'pkceRequired' => false]);
        $params = $this->makeParams(['code_challenge' => '', 'code_challenge_method' => '']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertTrue($result['ok'], 'PKCE must be genuinely optional when nothing requires it');
        $this->assertNull($result['codeChallenge']);
        $this->assertNull($result['codeChallengeMethod']);
    }

    public function testCodeChallengeWithoutMethodIsInvalidRequest(): void
    {
        // RFC 7636's implicit "assume plain when omitted" fallback is
        // deliberately NOT honoured — an omitted method is a hard error.
        $client = $this->makeClient();
        $params = $this->makeParams(['code_challenge_method' => '']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_request', $result['error']);
    }

    public function testUnsupportedCodeChallengeMethodIsInvalidRequest(): void
    {
        $client = $this->makeClient();
        $params = $this->makeParams(['code_challenge_method' => 'md5']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_request', $result['error']);
    }

    public function testPlainMethodRejectedWhenAllowPlainPkceIsFalse(): void
    {
        // Default test setting: oidc.allow_plain_pkce is NOT set -> getSetting()
        // falls back to the false default, matching migration 031's seed.
        $client = $this->makeClient();
        $params = $this->makeParams(['code_challenge_method' => 'plain']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_request', $result['error']);
    }

    public function testPlainMethodAcceptedWhenAllowPlainPkceIsTrue(): void
    {
        setTestSetting('oidc.allow_plain_pkce', true);
        $client = $this->makeClient();
        $params = $this->makeParams(['code_challenge_method' => 'plain']);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertTrue($result['ok']);
        $this->assertSame('plain', $result['codeChallengeMethod']);
    }

    public function testMalformedCodeChallengeIsInvalidRequest(): void
    {
        $client = $this->makeClient();

        // Too short (< 43 chars).
        $result = OAuthAuthorizeService::validateAuthorizeRequest(
            $this->makeParams(['code_challenge' => str_repeat('A', 10)]),
            $client
        );
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_request', $result['error']);

        // Invalid characters (base64url only — no '+', '/', '=').
        $result2 = OAuthAuthorizeService::validateAuthorizeRequest(
            $this->makeParams(['code_challenge' => str_repeat('A', 42) . '+']),
            $client
        );
        $this->assertFalse($result2['ok']);
        $this->assertSame('invalid_request', $result2['error']);
    }

    // ========================================================================
    // ✅ HAPPY PATH
    // ========================================================================

    public function testValidRequestReturnsOkWithAllFieldsPopulated(): void
    {
        $client = $this->makeClient();
        $params = $this->makeParams();

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['fatal']);
        $this->assertNull($result['error']);
        $this->assertSame('https://rp.example.com/callback', $result['redirectUri']);
        $this->assertSame('openid profile', $result['scope']);
        $this->assertSame('xyz123', $result['state']);
        $this->assertSame(str_repeat('A', 43), $result['codeChallenge']);
        $this->assertSame('S256', $result['codeChallengeMethod']);
        $this->assertSame('nonce-value', $result['nonce']);
    }

    public function testAbsentStateIsNullNotEmptyString(): void
    {
        $client = $this->makeClient();
        $params = $this->makeParams(['state' => null]);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['state']);
    }

    // ========================================================================
    // 📏 NONCE
    // ========================================================================

    public function testOversizedNonceIsInvalidRequest(): void
    {
        $client = $this->makeClient();
        $params = $this->makeParams(['nonce' => str_repeat('n', 256)]);

        $result = OAuthAuthorizeService::validateAuthorizeRequest($params, $client);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_request', $result['error']);
    }

    // ========================================================================
    // 🔗 buildRedirectUrl()
    // ========================================================================

    public function testBuildRedirectUrlAppendsQuestionMarkWhenNoExistingQuery(): void
    {
        $url = OAuthAuthorizeService::buildRedirectUrl('https://rp.example.com/callback', [
            'code' => 'abc123',
            'state' => 'xyz',
        ]);

        $this->assertSame('https://rp.example.com/callback?code=abc123&state=xyz', $url);
    }

    public function testBuildRedirectUrlAppendsAmpersandWhenQueryAlreadyExists(): void
    {
        $url = OAuthAuthorizeService::buildRedirectUrl('https://rp.example.com/callback?foo=bar', [
            'code' => 'abc123',
        ]);

        $this->assertSame('https://rp.example.com/callback?foo=bar&code=abc123', $url);
    }

    public function testBuildRedirectUrlFiltersNullAndEmptyValues(): void
    {
        $url = OAuthAuthorizeService::buildRedirectUrl('https://rp.example.com/callback', [
            'code'  => 'abc123',
            'state' => null,
            'extra' => '',
        ]);

        $this->assertSame('https://rp.example.com/callback?code=abc123', $url);
    }

    public function testBuildRedirectUrlReturnsBareUriWhenAllParamsFiltered(): void
    {
        $url = OAuthAuthorizeService::buildRedirectUrl('https://rp.example.com/callback', [
            'state' => null,
        ]);

        $this->assertSame('https://rp.example.com/callback', $url);
    }

    // ========================================================================
    // 🏷️ scopeLabels()
    // ========================================================================

    public function testScopeLabelsMapsKnownScopesToHumanReadableText(): void
    {
        $labels = OAuthAuthorizeService::scopeLabels('openid email');

        $this->assertCount(2, $labels);
        $this->assertSame('openid', $labels[0]['scope']);
        $this->assertStringContainsString('sign in', $labels[0]['label']);
        $this->assertSame('email', $labels[1]['scope']);
        $this->assertStringContainsString('email address', $labels[1]['label']);
    }

    public function testScopeLabelsFallsBackToRawTokenForUnknownScope(): void
    {
        $labels = OAuthAuthorizeService::scopeLabels('some_future_scope');

        $this->assertCount(1, $labels);
        $this->assertSame('some_future_scope', $labels[0]['scope']);
        $this->assertSame('some_future_scope', $labels[0]['label']);
    }
}
