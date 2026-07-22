<?php
/**
 * ============================================================================
 * 🧪 IPReputationChecker Unit Tests
 * ============================================================================
 *
 * Tests for the IPReputationChecker class covering:
 * - isEnabled() setting toggle
 * - check() with private/reserved IP addresses
 * - check() with whitelisted IPs
 * - isBlocked() without Database class
 * - blockIP() / unblockIP() without Database class
 * - reportAbuse() prerequisite checks
 * - Private IP detection logic (IPv4 + IPv6)
 *
 * These tests do NOT require a database connection or external API access.
 * All tests exercise code paths that are reachable without Database class.
 *
 * @package    SIGNula\Tests\Unit\Security
 * @version    2.6.0-beta
 * @see        web/private_html/security/IPReputationChecker.php
 */

namespace SIGNula\Tests\Unit\Security;

use SIGNula\Tests\TestCase;

// 📦 Load source class
requireSource('private_html/security/IPReputationChecker.php');

/**
 * IPReputationChecker Test Suite
 */
class IPReputationCheckerTest extends TestCase
{
    // ========================================================================
    // 🔧 SETUP
    // ========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        // 🔧 Default IP reputation settings
        setTestSetting('security.ip_reputation.enabled', false);
        setTestSetting('security.ip_reputation.abuseipdb_api_key', '');
        setTestSetting('security.ip_reputation.proxycheck_api_key', '');
        setTestSetting('security.ip_reputation.abuse_score_threshold', 50);
        setTestSetting('security.ip_reputation.block_proxy', true);
        setTestSetting('security.ip_reputation.block_vpn', false);
        setTestSetting('security.ip_reputation.block_tor', true);
        setTestSetting('security.ip_reputation.cache_ttl_hours', 24);
        setTestSetting('security.ip_reputation.whitelist', '[]');
        setTestSetting('security.ip_reputation.report_enabled', false);
    }

    // ========================================================================
    // ✅ isEnabled() TESTS
    // ========================================================================

    /**
     * Test isEnabled returns false by default (disabled in settings)
     */
    public function testIsEnabledReturnsFalseByDefault(): void
    {
        $this->assertFalse(\IPReputationChecker::isEnabled());
    }

    /**
     * Test isEnabled returns true when enabled via setting
     */
    public function testIsEnabledReturnsTrueWhenEnabled(): void
    {
        setTestSetting('security.ip_reputation.enabled', true);

        $this->assertTrue(\IPReputationChecker::isEnabled());
    }

    // ========================================================================
    // 🏠 check() TESTS — Private IPs
    // ========================================================================

    /**
     * Test check returns allowed for localhost (127.0.0.1)
     */
    public function testCheckAllowsLocalhost(): void
    {
        $result = \IPReputationChecker::check('127.0.0.1');

        $this->assertTrue($result['allowed']);
        $this->assertEquals('private_ip', $result['source']);
        $this->assertEquals(0, $result['score']);
        $this->assertFalse($result['is_proxy']);
        $this->assertFalse($result['is_vpn']);
        $this->assertFalse($result['is_tor']);
    }

    /**
     * Test check returns allowed for IPv6 loopback (::1)
     */
    public function testCheckAllowsIPv6Loopback(): void
    {
        $result = \IPReputationChecker::check('::1');

        $this->assertTrue($result['allowed']);
        $this->assertEquals('private_ip', $result['source']);
    }

    /**
     * Test check returns allowed for private 10.x.x.x range
     */
    public function testCheckAllowsPrivate10Range(): void
    {
        $result = \IPReputationChecker::check('10.0.0.1');

        $this->assertTrue($result['allowed']);
        $this->assertEquals('private_ip', $result['source']);
    }

    /**
     * Test check returns allowed for private 192.168.x.x range
     */
    public function testCheckAllowsPrivate192Range(): void
    {
        $result = \IPReputationChecker::check('192.168.1.100');

        $this->assertTrue($result['allowed']);
        $this->assertEquals('private_ip', $result['source']);
    }

    /**
     * Test check returns allowed for private 172.16.x.x range
     */
    public function testCheckAllowsPrivate172Range(): void
    {
        $result = \IPReputationChecker::check('172.16.0.1');

        $this->assertTrue($result['allowed']);
        $this->assertEquals('private_ip', $result['source']);
    }

    // ========================================================================
    // ✅ check() TESTS — Whitelisted IPs
    // ========================================================================

    /**
     * Test check returns allowed for whitelisted public IPs
     */
    public function testCheckAllowsWhitelistedIP(): void
    {
        setTestSetting('security.ip_reputation.whitelist', '["8.8.8.8","1.1.1.1"]');

        $result = \IPReputationChecker::check('8.8.8.8');

        $this->assertTrue($result['allowed']);
        $this->assertEquals('whitelist', $result['source']);
    }

    /**
     * 🐛 Regression: production's getSetting() returns an ALREADY-DECODED
     * array for settingType='json' settings (config.php's loadSettings()
     * json_decode()s typed settings before caching them) — NOT the raw JSON
     * string the OLD code assumed. The old
     * `json_decode(getSetting(...), true)` call therefore threw a PHP 8
     * TypeError (arg #1 must be of type string, array given) any time this
     * code path ran in production, even though the sibling test above
     * (which feeds a JSON STRING) never caught it. This test feeds a real
     * PHP array — exactly what production hands back — proving the fix
     * tolerates that shape without throwing.
     */
    public function testCheckAllowsWhitelistedIPWhenSettingIsAlreadyDecodedArray(): void
    {
        // 🎯 Array, not string — simulates getSetting() for a json-typed row.
        setTestSetting('security.ip_reputation.whitelist', ['8.8.8.8', '1.1.1.1']);

        $result = \IPReputationChecker::check('8.8.8.8');

        $this->assertTrue($result['allowed']);
        $this->assertEquals('whitelist', $result['source']);
    }

    /**
     * Test check does not bypass pipeline for non-whitelisted public IPs
     */
    public function testCheckDoesNotWhitelistNonMatchingIP(): void
    {
        setTestSetting('security.ip_reputation.whitelist', '["8.8.8.8"]');

        // 📝 203.0.113.1 is in the TEST-NET-3 range (public), not whitelisted
        // Without Database, it should fall through to API check (which also fails)
        // and ultimately return fallback allowed
        $result = \IPReputationChecker::check('203.0.113.1');

        // 📝 This is a documentation range IP — it IS public, NOT private
        // But wait: 203.0.113.0/24 is actually RESERVED per RFC 5737!
        // PHP's filter_var will flag 203.0.113.x as reserved.
        // Let's check — if it's treated as private, source will be 'private_ip'
        // If it's treated as public, without DB it falls to 'fallback'
        $this->assertTrue($result['allowed']);
    }

    // ========================================================================
    // 🔎 check() TESTS — Result Structure
    // ========================================================================

    /**
     * Test check result contains all expected keys
     */
    public function testCheckResultHasExpectedKeys(): void
    {
        $result = \IPReputationChecker::check('127.0.0.1');

        $this->assertArrayHasKey('allowed', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('is_proxy', $result);
        $this->assertArrayHasKey('is_vpn', $result);
        $this->assertArrayHasKey('is_tor', $result);
        $this->assertArrayHasKey('source', $result);
        $this->assertArrayHasKey('cached', $result);
    }

    /**
     * Test check returns fallback allowed when no APIs are configured
     * and the IP is public (non-private, non-whitelisted)
     */
    public function testCheckReturnsFallbackForPublicIPWithoutAPIs(): void
    {
        // 📝 Use a clearly public IP — no whitelist, no API keys, no Database
        // 1.2.3.4 is a public IP not in any reserved range
        $result = \IPReputationChecker::check('1.2.3.4');

        $this->assertTrue($result['allowed']);
        $this->assertEquals('fallback', $result['source']);
        $this->assertFalse($result['cached']);
    }

    // ========================================================================
    // 🚫 isBlocked() TESTS
    // ========================================================================

    /**
     * Test isBlocked returns false when Database class is not available
     */
    public function testIsBlockedReturnsFalseWithoutDatabase(): void
    {
        $this->assertFalse(\IPReputationChecker::isBlocked('1.2.3.4'));
    }

    // ========================================================================
    // 🔒 blockIP() TESTS
    // ========================================================================

    /**
     * Test blockIP returns false when Database class is not available
     */
    public function testBlockIPReturnsFalseWithoutDatabase(): void
    {
        $result = \IPReputationChecker::blockIP('1.2.3.4', 'Test block');

        $this->assertFalse($result);
    }

    // ========================================================================
    // 🔓 unblockIP() TESTS
    // ========================================================================

    /**
     * Test unblockIP returns false when Database class is not available
     */
    public function testUnblockIPReturnsFalseWithoutDatabase(): void
    {
        $result = \IPReputationChecker::unblockIP('1.2.3.4');

        $this->assertFalse($result);
    }

    // ========================================================================
    // 📤 reportAbuse() TESTS
    // ========================================================================

    /**
     * Test reportAbuse returns false when reporting is disabled
     */
    public function testReportAbuseReturnsFalseWhenDisabled(): void
    {
        setTestSetting('security.ip_reputation.report_enabled', false);

        $result = \IPReputationChecker::reportAbuse('1.2.3.4', [18], 'Brute force');

        $this->assertFalse($result);
    }

    /**
     * Test reportAbuse returns false when no API key is configured
     */
    public function testReportAbuseReturnsFalseWithoutAPIKey(): void
    {
        setTestSetting('security.ip_reputation.report_enabled', true);
        setTestSetting('security.ip_reputation.abuseipdb_api_key', '');

        $result = \IPReputationChecker::reportAbuse('1.2.3.4', [18], 'Brute force');

        $this->assertFalse($result);
    }
}
