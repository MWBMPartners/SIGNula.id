<?php
/**
 * ============================================================================
 * 🧪 FormProtection Unit Tests
 * ============================================================================
 *
 * Tests for the FormProtection class covering:
 * - isEnabled() setting toggle
 * - renderHoneypot() HTML output
 * - renderTimingField() HTML output with HMAC
 * - renderJSChallenge() HTML output with script
 * - renderAllFields() combined output
 * - validate() with honeypot (empty vs filled)
 * - validate() with timing (fast vs slow vs tampered)
 * - validate() with JS challenge (present vs missing vs invalid)
 * - validate() combined pass/fail scenarios
 *
 * These tests do NOT require a database connection or external API access.
 *
 * @package    SIGNula\Tests\Unit\Security
 * @version    2.6.0-beta
 * @see        web/private_html/security/FormProtection.php
 */

namespace SIGNula\Tests\Unit\Security;

use SIGNula\Tests\TestCase;

// 📦 Load source class
requireSource('private_html/security/FormProtection.php');

/**
 * FormProtection Test Suite
 */
class FormProtectionTest extends TestCase
{
    // ========================================================================
    // 🔧 SETUP
    // ========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        // 🔇 Enable form protection for testing
        setTestSetting('security.form_protection.enabled', true);
        setTestSetting('security.form_protection.min_submit_time', 3);

        // 🔑 Set up a session CSRF token for HMAC key
        $_SESSION['csrf_token'] = 'test-csrf-token-for-form-protection';

        // 🧹 Clear POST data
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    // ========================================================================
    // ✅ isEnabled() TESTS
    // ========================================================================

    /**
     * Test isEnabled returns true when setting is enabled
     */
    public function testIsEnabledReturnsTrueByDefault(): void
    {
        setTestSetting('security.form_protection.enabled', true);

        $this->assertTrue(\FormProtection::isEnabled());
    }

    /**
     * Test isEnabled returns false when setting is disabled
     */
    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        setTestSetting('security.form_protection.enabled', false);

        $this->assertFalse(\FormProtection::isEnabled());
    }

    // ========================================================================
    // 🍯 renderHoneypot() TESTS
    // ========================================================================

    /**
     * Test renderHoneypot returns HTML containing the hidden input
     */
    public function testRenderHoneypotReturnsHTML(): void
    {
        $html = \FormProtection::renderHoneypot();

        $this->assertStringContainsString('website_url', $html);
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('autocomplete="off"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('left:-9999px', $html);
    }

    /**
     * Test renderHoneypot returns empty string when disabled
     */
    public function testRenderHoneypotReturnsEmptyWhenDisabled(): void
    {
        setTestSetting('security.form_protection.enabled', false);

        $this->assertEmpty(\FormProtection::renderHoneypot());
    }

    // ========================================================================
    // ⏱️ renderTimingField() TESTS
    // ========================================================================

    /**
     * Test renderTimingField returns HTML with timestamp and HMAC
     */
    public function testRenderTimingFieldReturnsHTML(): void
    {
        $html = \FormProtection::renderTimingField();

        $this->assertStringContainsString('_form_ts', $html);
        $this->assertStringContainsString('_form_sig', $html);
        $this->assertStringContainsString('type="hidden"', $html);

        // 📝 Check that the timestamp value is a valid Unix timestamp
        preg_match('/name="_form_ts"\s+value="(\d+)"/', $html, $matches);
        $this->assertNotEmpty($matches[1]);
        $this->assertGreaterThan(0, (int) $matches[1]);
    }

    /**
     * Test renderTimingField returns empty string when disabled
     */
    public function testRenderTimingFieldReturnsEmptyWhenDisabled(): void
    {
        setTestSetting('security.form_protection.enabled', false);

        $this->assertEmpty(\FormProtection::renderTimingField());
    }

    // ========================================================================
    // 🔧 renderJSChallenge() TESTS
    // ========================================================================

    /**
     * Test renderJSChallenge returns HTML with script tag
     */
    public function testRenderJSChallengeReturnsHTML(): void
    {
        $html = \FormProtection::renderJSChallenge();

        $this->assertStringContainsString('_js_check', $html);
        $this->assertStringContainsString('<script>', $html);
        $this->assertStringContainsString('</script>', $html);
        $this->assertStringContainsString('DOMContentLoaded', $html);
        $this->assertStringContainsString('ok_', $html);
    }

    /**
     * Test renderJSChallenge returns empty string when disabled
     */
    public function testRenderJSChallengeReturnsEmptyWhenDisabled(): void
    {
        setTestSetting('security.form_protection.enabled', false);

        $this->assertEmpty(\FormProtection::renderJSChallenge());
    }

    // ========================================================================
    // 🔒 renderAllFields() TESTS
    // ========================================================================

    /**
     * Test renderAllFields combines all three protection fields
     */
    public function testRenderAllFieldsCombinesAll(): void
    {
        $html = \FormProtection::renderAllFields();

        // 📝 Check all three components are present
        $this->assertStringContainsString('website_url', $html);       // Honeypot
        $this->assertStringContainsString('_form_ts', $html);          // Timing
        $this->assertStringContainsString('_js_check', $html);         // JS Challenge
        $this->assertStringContainsString('Local Form Protection', $html); // Comment marker
    }

    /**
     * Test renderAllFields returns empty string when disabled
     */
    public function testRenderAllFieldsReturnsEmptyWhenDisabled(): void
    {
        setTestSetting('security.form_protection.enabled', false);

        $this->assertEmpty(\FormProtection::renderAllFields());
    }

    // ========================================================================
    // 🍯 validate() — HONEYPOT TESTS
    // ========================================================================

    /**
     * Test validate passes when honeypot field is empty (human behavior)
     */
    public function testValidateHoneypotPassesWhenEmpty(): void
    {
        $_POST['website_url'] = '';

        $result = \FormProtection::validate();

        $this->assertTrue($result['passed']);
        $this->assertNull($result['reason']);
    }

    /**
     * Test validate fails when honeypot field is filled (bot behavior)
     */
    public function testValidateHoneypotFailsWhenFilled(): void
    {
        $_POST['website_url'] = 'http://spam-site.com';

        $result = \FormProtection::validate();

        $this->assertFalse($result['passed']);
        $this->assertEquals('honeypot', $result['reason']);
    }

    // ========================================================================
    // ⏱️ validate() — TIMING TESTS
    // ========================================================================

    /**
     * Test validate passes when enough time has elapsed
     */
    public function testValidateTimingPassesWhenSlow(): void
    {
        // 📝 Simulate form loaded 10 seconds ago
        $timestamp = (string) (time() - 10);
        $hmac = hash_hmac('sha256', $timestamp, $_SESSION['csrf_token']);

        $_POST['_form_ts'] = $timestamp;
        $_POST['_form_sig'] = $hmac;

        $result = \FormProtection::validate();

        $this->assertTrue($result['passed']);
    }

    /**
     * Test validate fails when form submitted too fast
     */
    public function testValidateTimingFailsWhenFast(): void
    {
        // 📝 Simulate form loaded just now (0 seconds ago)
        $timestamp = (string) time();
        $hmac = hash_hmac('sha256', $timestamp, $_SESSION['csrf_token']);

        $_POST['_form_ts'] = $timestamp;
        $_POST['_form_sig'] = $hmac;

        $result = \FormProtection::validate();

        $this->assertFalse($result['passed']);
        $this->assertEquals('timing', $result['reason']);
    }

    /**
     * Test validate fails when HMAC signature is tampered with
     */
    public function testValidateTimingFailsOnTamperedHMAC(): void
    {
        // 📝 Use a valid old timestamp but a fake HMAC
        $timestamp = (string) (time() - 10);

        $_POST['_form_ts'] = $timestamp;
        $_POST['_form_sig'] = 'tampered-signature-value';

        $result = \FormProtection::validate();

        $this->assertFalse($result['passed']);
        $this->assertEquals('timing', $result['reason']);
    }

    /**
     * Test validate passes when timing fields are missing (graceful)
     */
    public function testValidateTimingPassesWhenFieldsMissing(): void
    {
        // 📝 No _form_ts or _form_sig in POST — should skip timing check
        $result = \FormProtection::validate();

        $this->assertTrue($result['passed']);
    }

    // ========================================================================
    // 🔧 validate() — JS CHALLENGE TESTS
    // ========================================================================

    /**
     * Test validate passes when JS challenge has correct prefix
     */
    public function testValidateJSChallengePassesWhenPresent(): void
    {
        // 📝 Simulate JS populating the field with ok_<timestamp>
        $_POST['_js_check'] = 'ok_1708387200000';

        // 📝 Also need valid timing fields to not fail on timing
        $timestamp = (string) (time() - 10);
        $hmac = hash_hmac('sha256', $timestamp, $_SESSION['csrf_token']);
        $_POST['_form_ts'] = $timestamp;
        $_POST['_form_sig'] = $hmac;

        $result = \FormProtection::validate();

        $this->assertTrue($result['passed']);
    }

    /**
     * Test validate passes when JS challenge field is missing (non-JS browser)
     */
    public function testValidateJSChallengeSkipsWhenMissing(): void
    {
        // 📝 No _js_check field — graceful degradation for non-JS browsers
        $result = \FormProtection::validate();

        $this->assertTrue($result['passed']);
    }

    /**
     * Test validate fails when JS challenge field has wrong value
     */
    public function testValidateJSChallengeFailsWhenInvalid(): void
    {
        // 📝 Bot fills field with garbage that doesn't match expected prefix
        $_POST['_js_check'] = 'bot_garbage_value';

        // 📝 Valid timing fields so we reach the JS check
        $timestamp = (string) (time() - 10);
        $hmac = hash_hmac('sha256', $timestamp, $_SESSION['csrf_token']);
        $_POST['_form_ts'] = $timestamp;
        $_POST['_form_sig'] = $hmac;

        $result = \FormProtection::validate();

        $this->assertFalse($result['passed']);
        $this->assertEquals('js_challenge', $result['reason']);
    }

    // ========================================================================
    // 🔍 validate() — COMBINED TESTS
    // ========================================================================

    /**
     * Test validate returns passed=true when all checks pass
     */
    public function testValidateReturnsPassedWhenAllPass(): void
    {
        // 📝 Set up all three valid fields
        $_POST['website_url'] = '';
        $timestamp = (string) (time() - 10);
        $_POST['_form_ts'] = $timestamp;
        $_POST['_form_sig'] = hash_hmac('sha256', $timestamp, $_SESSION['csrf_token']);
        $_POST['_js_check'] = 'ok_1708387200000';

        $result = \FormProtection::validate();

        $this->assertTrue($result['passed']);
        $this->assertNull($result['reason']);
    }

    /**
     * Test validate always passes when feature is disabled
     */
    public function testValidateAlwaysPassesWhenDisabled(): void
    {
        setTestSetting('security.form_protection.enabled', false);

        // 📝 Even with a filled honeypot, should pass when disabled
        $_POST['website_url'] = 'http://spam-site.com';

        $result = \FormProtection::validate();

        $this->assertTrue($result['passed']);
        $this->assertNull($result['reason']);
    }

    /**
     * Test validate result structure has expected keys
     */
    public function testValidateResultHasExpectedKeys(): void
    {
        $result = \FormProtection::validate();

        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('reason', $result);
    }
}
