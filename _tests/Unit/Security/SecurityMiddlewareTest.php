<?php
/**
 * ============================================================================
 * 🧪 SecurityMiddleware Unit Tests
 * ============================================================================
 *
 * Tests for the SecurityMiddleware class covering:
 * - handleFormSubmission() return structure
 * - handleFormSubmission() with all security features disabled
 * - handleFormSubmission() with CAPTCHA disabled
 * - handleFormSubmission() return keys validation
 *
 * Note: handle() is not tested directly because it calls exit() on block.
 * Integration tests should cover the full page-request pipeline.
 *
 * These tests do NOT require a database connection or external API access.
 *
 * @package    SIGNula\Tests\Unit\Security
 * @version    2.6.0-beta
 * @see        web/private_html/security/SecurityMiddleware.php
 */

namespace SIGNula\Tests\Unit\Security;

use SIGNula\Tests\TestCase;

// 📦 Load source classes (SecurityMiddleware depends on CaptchaVerifier)
requireSource('private_html/security/CaptchaVerifier.php');
requireSource('private_html/security/SecurityMiddleware.php');

/**
 * SecurityMiddleware Test Suite
 */
class SecurityMiddlewareTest extends TestCase
{
    // ========================================================================
    // 🔧 SETUP
    // ========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        // 🔇 Disable all security features for clean unit testing
        setTestSetting('captcha.enabled', false);
        setTestSetting('captcha.provider', 'none');
        setTestSetting('rate_limiting.enabled', true);
        setTestSetting('security.ip_reputation.enabled', false);
        setTestSetting('security.bot_detection.enabled', false);
        setTestSetting('security.alerts.enabled', false);

        // 🌐 Set minimal server superglobals
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/TestAgent';
        $_SERVER['REQUEST_URI'] = '/test';
    }

    // ========================================================================
    // 📝 handleFormSubmission() TESTS
    // ========================================================================

    /**
     * Test handleFormSubmission returns allowed when all checks pass
     */
    public function testHandleFormSubmissionReturnsAllowedByDefault(): void
    {
        $result = \SecurityMiddleware::handleFormSubmission('login');

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['error']);
    }

    /**
     * Test handleFormSubmission returns correct array keys
     */
    public function testHandleFormSubmissionReturnExpectedKeys(): void
    {
        $result = \SecurityMiddleware::handleFormSubmission('register');

        $this->assertArrayHasKey('allowed', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('captcha_required', $result);
    }

    /**
     * Test handleFormSubmission returns captcha_required=false when CAPTCHA is disabled
     */
    public function testHandleFormSubmissionCaptchaNotRequiredWhenDisabled(): void
    {
        setTestSetting('captcha.enabled', false);

        $result = \SecurityMiddleware::handleFormSubmission('login');

        $this->assertFalse($result['captcha_required']);
    }

    /**
     * Test handleFormSubmission works for different form names
     */
    public function testHandleFormSubmissionWorksForDifferentForms(): void
    {
        $formNames = ['login', 'register', 'forgot-password', 'contact'];

        foreach ($formNames as $formName) {
            $result = \SecurityMiddleware::handleFormSubmission($formName);

            $this->assertTrue($result['allowed'], "Form '{$formName}' should be allowed when all checks disabled");
            $this->assertNull($result['error'], "Form '{$formName}' should have no error when all checks disabled");
        }
    }

    /**
     * Test handleFormSubmission skips rate limiting when RateLimiter class is unavailable
     * (RateLimiter is not loaded in unit test context)
     */
    public function testHandleFormSubmissionSkipsRateLimitingGracefully(): void
    {
        // 📝 RateLimiter class is not loaded in unit tests
        // handleFormSubmission should skip it without error
        $result = \SecurityMiddleware::handleFormSubmission('login');

        $this->assertTrue($result['allowed']);
    }

    /**
     * Test handleFormSubmission skips CAPTCHA when disabled
     */
    public function testHandleFormSubmissionSkipsCaptchaWhenDisabled(): void
    {
        setTestSetting('captcha.enabled', false);

        $result = \SecurityMiddleware::handleFormSubmission('register');

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['captcha_required']);
    }

    /**
     * Test handleFormSubmission indicates CAPTCHA is required when enabled with keys
     */
    public function testHandleFormSubmissionIndicatesCaptchaRequired(): void
    {
        setTestSetting('captcha.enabled', true);
        setTestSetting('captcha.provider', 'turnstile');
        setTestSetting('captcha.turnstile.site_key', 'test-key');
        setTestSetting('captcha.turnstile.secret_key', 'test-secret');
        setTestSetting('captcha.required_forms', '["login","register","forgot-password","contact"]');

        // 📝 Provide a token in POST to avoid "no token" failure
        $_POST['cf-turnstile-response'] = 'test-token-value';

        $result = \SecurityMiddleware::handleFormSubmission('login');

        // 📝 The CAPTCHA will fail verification (test token won't validate against real API)
        // but captcha_required should be true
        $this->assertTrue($result['captcha_required']);
    }

    // ========================================================================
    // 🔍 EDGE CASE TESTS
    // ========================================================================

    /**
     * Test handleFormSubmission handles unknown form names gracefully
     */
    public function testHandleFormSubmissionHandlesUnknownFormName(): void
    {
        $result = \SecurityMiddleware::handleFormSubmission('nonexistent-form');

        // 📝 Should still work — rate limiter might not have config for this form
        // but it should not throw an error
        $this->assertIsArray($result);
        $this->assertArrayHasKey('allowed', $result);
    }

    /**
     * Test handleFormSubmission handles empty form name
     */
    public function testHandleFormSubmissionHandlesEmptyFormName(): void
    {
        $result = \SecurityMiddleware::handleFormSubmission('');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('allowed', $result);
    }
}
