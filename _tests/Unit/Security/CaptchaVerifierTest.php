<?php
/**
 * ============================================================================
 * 🧪 CaptchaVerifier Unit Tests
 * ============================================================================
 *
 * Tests for the CaptchaVerifier class covering:
 * - isRequired() toggle and form-specific checks
 * - getProvider() auto-detection and fallback logic
 * - renderWidget() HTML output for each provider
 * - getRequiredScripts() script tag generation
 * - verify() with disabled CAPTCHA (fail-open)
 *
 * These tests do NOT require a database connection or external API access.
 *
 * @package    SIGNula\Tests\Unit\Security
 * @version    2.6.0-beta
 * @see        web/private_html/security/CaptchaVerifier.php
 */

namespace SIGNula\Tests\Unit\Security;

use SIGNula\Tests\TestCase;

// 📦 Load source class
requireSource('private_html/security/CaptchaVerifier.php');

/**
 * CaptchaVerifier Test Suite
 */
class CaptchaVerifierTest extends TestCase
{
    // ========================================================================
    // 🔧 SETUP
    // ========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        // 🔇 Default: CAPTCHA disabled (matches fixture defaults)
        setTestSetting('captcha.enabled', false);
        setTestSetting('captcha.provider', 'none');
        setTestSetting('captcha.turnstile.site_key', '');
        setTestSetting('captcha.turnstile.secret_key', '');
        setTestSetting('captcha.recaptcha.site_key', '');
        setTestSetting('captcha.recaptcha.secret_key', '');
        setTestSetting('captcha.recaptcha.min_score', 0.5);
        setTestSetting('captcha.required_forms', '["login","register","forgot-password","contact"]');
    }

    // ========================================================================
    // ✅ isRequired() TESTS
    // ========================================================================

    /**
     * Test isRequired returns false when CAPTCHA is globally disabled
     */
    public function testIsRequiredReturnsFalseWhenDisabled(): void
    {
        setTestSetting('captcha.enabled', false);

        $this->assertFalse(\CaptchaVerifier::isRequired());
    }

    /**
     * Test isRequired returns false when enabled but no provider keys configured
     */
    public function testIsRequiredReturnsFalseWhenNoKeysConfigured(): void
    {
        setTestSetting('captcha.enabled', true);
        setTestSetting('captcha.provider', 'turnstile');
        // 🔑 No keys set — provider should resolve to 'none'

        $this->assertFalse(\CaptchaVerifier::isRequired());
    }

    /**
     * Test isRequired returns true when enabled with Turnstile keys
     */
    public function testIsRequiredReturnsTrueWithTurnstileKeys(): void
    {
        setTestSetting('captcha.enabled', true);
        setTestSetting('captcha.provider', 'turnstile');
        setTestSetting('captcha.turnstile.site_key', 'test-site-key');
        setTestSetting('captcha.turnstile.secret_key', 'test-secret-key');

        $this->assertTrue(\CaptchaVerifier::isRequired());
    }

    /**
     * Test isRequired returns true when enabled with reCAPTCHA keys
     */
    public function testIsRequiredReturnsTrueWithRecaptchaKeys(): void
    {
        setTestSetting('captcha.enabled', true);
        setTestSetting('captcha.provider', 'recaptcha');
        setTestSetting('captcha.recaptcha.site_key', 'test-site-key');
        setTestSetting('captcha.recaptcha.secret_key', 'test-secret-key');

        $this->assertTrue(\CaptchaVerifier::isRequired());
    }

    /**
     * Test isRequired returns false for forms not in the required_forms list
     */
    public function testIsRequiredReturnsFalseForNonRequiredForm(): void
    {
        setTestSetting('captcha.enabled', true);
        setTestSetting('captcha.provider', 'turnstile');
        setTestSetting('captcha.turnstile.site_key', 'test-site-key');
        setTestSetting('captcha.turnstile.secret_key', 'test-secret-key');
        setTestSetting('captcha.required_forms', '["login","register"]');

        // 📝 'contact' is NOT in the required_forms list
        $this->assertFalse(\CaptchaVerifier::isRequired('contact'));
    }

    /**
     * Test isRequired returns true for forms in the required_forms list
     */
    public function testIsRequiredReturnsTrueForRequiredForm(): void
    {
        setTestSetting('captcha.enabled', true);
        setTestSetting('captcha.provider', 'turnstile');
        setTestSetting('captcha.turnstile.site_key', 'test-site-key');
        setTestSetting('captcha.turnstile.secret_key', 'test-secret-key');
        setTestSetting('captcha.required_forms', '["login","register","contact"]');

        $this->assertTrue(\CaptchaVerifier::isRequired('login'));
    }

    // ========================================================================
    // 🏷️ getProvider() TESTS
    // ========================================================================

    /**
     * Test getProvider returns 'none' when no keys are configured
     */
    public function testGetProviderReturnsNoneWithoutKeys(): void
    {
        setTestSetting('captcha.provider', 'turnstile');

        $this->assertEquals('none', \CaptchaVerifier::getProvider());
    }

    /**
     * Test getProvider returns 'turnstile' when Turnstile keys are configured
     */
    public function testGetProviderReturnsTurnstileWithKeys(): void
    {
        setTestSetting('captcha.provider', 'turnstile');
        setTestSetting('captcha.turnstile.site_key', 'test-site-key');
        setTestSetting('captcha.turnstile.secret_key', 'test-secret-key');

        $this->assertEquals('turnstile', \CaptchaVerifier::getProvider());
    }

    /**
     * Test getProvider falls back to reCAPTCHA when Turnstile preferred but no keys
     */
    public function testGetProviderFallsBackToRecaptcha(): void
    {
        setTestSetting('captcha.provider', 'turnstile');
        // 🔑 Turnstile has no keys, but reCAPTCHA does
        setTestSetting('captcha.recaptcha.site_key', 'test-recaptcha-key');
        setTestSetting('captcha.recaptcha.secret_key', 'test-recaptcha-secret');

        $this->assertEquals('recaptcha', \CaptchaVerifier::getProvider());
    }

    /**
     * Test getProvider falls back to Turnstile when reCAPTCHA preferred but no keys
     */
    public function testGetProviderFallsBackToTurnstileFromRecaptcha(): void
    {
        setTestSetting('captcha.provider', 'recaptcha');
        // 🔑 reCAPTCHA has no keys, but Turnstile does
        setTestSetting('captcha.turnstile.site_key', 'test-turnstile-key');
        setTestSetting('captcha.turnstile.secret_key', 'test-turnstile-secret');

        $this->assertEquals('turnstile', \CaptchaVerifier::getProvider());
    }

    // ========================================================================
    // 🎨 renderWidget() TESTS
    // ========================================================================

    /**
     * Test renderWidget returns empty string when CAPTCHA is disabled
     */
    public function testRenderWidgetReturnsEmptyWhenDisabled(): void
    {
        setTestSetting('captcha.enabled', false);

        $this->assertEquals('', \CaptchaVerifier::renderWidget('myForm'));
    }

    /**
     * Test renderWidget returns Turnstile div when Turnstile is active
     */
    public function testRenderWidgetReturnsTurnstileHtml(): void
    {
        setTestSetting('captcha.enabled', true);
        setTestSetting('captcha.provider', 'turnstile');
        setTestSetting('captcha.turnstile.site_key', 'test-site-key-123');
        setTestSetting('captcha.turnstile.secret_key', 'test-secret-key');

        $html = \CaptchaVerifier::renderWidget('loginForm');

        $this->assertStringContainsString('cf-turnstile', $html);
        $this->assertStringContainsString('test-site-key-123', $html);
        $this->assertStringContainsString('data-sitekey', $html);
    }

    /**
     * Test renderWidget returns reCAPTCHA hidden input when reCAPTCHA is active
     */
    public function testRenderWidgetReturnsRecaptchaHtml(): void
    {
        setTestSetting('captcha.enabled', true);
        setTestSetting('captcha.provider', 'recaptcha');
        setTestSetting('captcha.recaptcha.site_key', 'test-recaptcha-key-456');
        setTestSetting('captcha.recaptcha.secret_key', 'test-secret-key');

        $html = \CaptchaVerifier::renderWidget('registerForm');

        $this->assertStringContainsString('g-recaptcha-response', $html);
        $this->assertStringContainsString('recaptchaResponse', $html);
        $this->assertStringContainsString('test-recaptcha-key-456', $html);
        $this->assertStringContainsString('grecaptcha', $html);
    }

    // ========================================================================
    // 📜 getRequiredScripts() TESTS
    // ========================================================================

    /**
     * Test getRequiredScripts returns empty when disabled
     */
    public function testGetRequiredScriptsReturnsEmptyWhenDisabled(): void
    {
        setTestSetting('captcha.enabled', false);

        $this->assertEquals('', \CaptchaVerifier::getRequiredScripts());
    }

    /**
     * Test getRequiredScripts returns Turnstile script tag
     */
    public function testGetRequiredScriptsReturnsTurnstileScript(): void
    {
        setTestSetting('captcha.enabled', true);
        setTestSetting('captcha.provider', 'turnstile');
        setTestSetting('captcha.turnstile.site_key', 'test-key');
        setTestSetting('captcha.turnstile.secret_key', 'test-secret');

        $scripts = \CaptchaVerifier::getRequiredScripts();

        $this->assertStringContainsString('challenges.cloudflare.com', $scripts);
        $this->assertStringContainsString('turnstile', $scripts);
        $this->assertStringContainsString('<script', $scripts);
    }

    /**
     * Test getRequiredScripts returns reCAPTCHA script tag with site key
     */
    public function testGetRequiredScriptsReturnsRecaptchaScript(): void
    {
        setTestSetting('captcha.enabled', true);
        setTestSetting('captcha.provider', 'recaptcha');
        setTestSetting('captcha.recaptcha.site_key', 'test-recaptcha-key');
        setTestSetting('captcha.recaptcha.secret_key', 'test-secret');

        $scripts = \CaptchaVerifier::getRequiredScripts();

        $this->assertStringContainsString('www.google.com/recaptcha', $scripts);
        $this->assertStringContainsString('test-recaptcha-key', $scripts);
        $this->assertStringContainsString('<script', $scripts);
    }

    // ========================================================================
    // ✅ verify() TESTS
    // ========================================================================

    /**
     * Test verify returns success when CAPTCHA is disabled (fail-open)
     */
    public function testVerifyReturnsSuccessWhenDisabled(): void
    {
        setTestSetting('captcha.enabled', false);

        $result = \CaptchaVerifier::verify('any-token');

        $this->assertTrue($result['success']);
        $this->assertEquals('none', $result['provider']);
        $this->assertNull($result['error']);
    }

    /**
     * Test verify returns failure when required but no token provided
     */
    public function testVerifyReturnsFailureWhenNoToken(): void
    {
        setTestSetting('captcha.enabled', true);
        setTestSetting('captcha.provider', 'turnstile');
        setTestSetting('captcha.turnstile.site_key', 'test-key');
        setTestSetting('captcha.turnstile.secret_key', 'test-secret');

        // 📝 Empty POST data — no token available
        $_POST = [];

        $result = \CaptchaVerifier::verify();

        $this->assertFalse($result['success']);
        $this->assertEquals('turnstile', $result['provider']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('CAPTCHA', $result['error']);
    }
}
