<?php
/**
 * ============================================================================
 * 🧪 BotDetector Unit Tests
 * ============================================================================
 *
 * Tests for the BotDetector class covering:
 * - isEnabled() setting toggle
 * - detect() with various user agent strings
 * - shouldBlock() logic for good vs bad bots
 * - isGoodBot() identification of legitimate crawlers
 * - Empty user agent handling
 * - Regex pattern fallback detection
 *
 * These tests do NOT require a database connection, CrawlerDetect library,
 * or Browscap. They validate the built-in regex fallback pipeline.
 *
 * @package    SIGNula\Tests\Unit\Security
 * @version    2.6.0-beta
 * @see        web/private_html/security/BotDetector.php
 */

namespace SIGNula\Tests\Unit\Security;

use SIGNula\Tests\TestCase;

// 📦 Load source class
requireSource('private_html/security/BotDetector.php');

/**
 * BotDetector Test Suite
 */
class BotDetectorTest extends TestCase
{
    // ========================================================================
    // 🔧 SETUP
    // ========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        // 🔧 Default bot detection settings
        setTestSetting('security.bot_detection.enabled', true);
        setTestSetting('security.bot_detection.block_bad_bots', true);
        setTestSetting('security.bot_detection.allow_good_bots', true);
        setTestSetting('security.bot_detection.dns_verify', false);
        setTestSetting('security.bot_detection.block_empty_ua', false);
    }

    // ========================================================================
    // ✅ isEnabled() TESTS
    // ========================================================================

    /**
     * Test isEnabled returns true when setting is enabled
     */
    public function testIsEnabledReturnsTrue(): void
    {
        setTestSetting('security.bot_detection.enabled', true);

        $this->assertTrue(\BotDetector::isEnabled());
    }

    /**
     * Test isEnabled returns false when setting is disabled
     */
    public function testIsEnabledReturnsFalse(): void
    {
        setTestSetting('security.bot_detection.enabled', false);

        $this->assertFalse(\BotDetector::isEnabled());
    }

    // ========================================================================
    // 🔍 detect() TESTS — Normal User Agents
    // ========================================================================

    /**
     * Test detect identifies a regular Chrome browser as not a bot
     */
    public function testDetectIdentifiesChromeAsNotBot(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $result = \BotDetector::detect($ua);

        $this->assertFalse($result['is_bot']);
        $this->assertFalse($result['is_good_bot']);
        $this->assertFalse($result['is_bad_bot']);
    }

    /**
     * Test detect identifies Firefox as not a bot
     */
    public function testDetectIdentifiesFirefoxAsNotBot(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:121.0) Gecko/20100101 Firefox/121.0';

        $result = \BotDetector::detect($ua);

        $this->assertFalse($result['is_bot']);
    }

    /**
     * Test detect identifies Safari as not a bot
     */
    public function testDetectIdentifiesSafariAsNotBot(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15';

        $result = \BotDetector::detect($ua);

        $this->assertFalse($result['is_bot']);
    }

    // ========================================================================
    // 🔍 detect() TESTS — Good Bots
    // ========================================================================

    /**
     * Test detect identifies Googlebot as a good bot
     */
    public function testDetectIdentifiesGooglebotAsGoodBot(): void
    {
        $ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

        $result = \BotDetector::detect($ua);

        $this->assertTrue($result['is_bot']);
        $this->assertTrue($result['is_good_bot']);
        $this->assertFalse($result['is_bad_bot']);
        $this->assertEquals('googlebot', $result['bot_name']);
    }

    /**
     * Test detect identifies Bingbot as a good bot
     */
    public function testDetectIdentifiesBingbotAsGoodBot(): void
    {
        $ua = 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)';

        $result = \BotDetector::detect($ua);

        $this->assertTrue($result['is_bot']);
        $this->assertTrue($result['is_good_bot']);
        $this->assertEquals('bingbot', $result['bot_name']);
    }

    /**
     * Test detect identifies DuckDuckBot as a good bot
     */
    public function testDetectIdentifiesDuckDuckBotAsGoodBot(): void
    {
        $ua = 'DuckDuckBot/1.0; (+http://duckduckgo.com/duckduckbot.html)';

        $result = \BotDetector::detect($ua);

        $this->assertTrue($result['is_bot']);
        $this->assertTrue($result['is_good_bot']);
    }

    /**
     * Test detect identifies Facebot as a good bot
     */
    public function testDetectIdentifiesFacebookBotAsGoodBot(): void
    {
        $ua = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

        $result = \BotDetector::detect($ua);

        $this->assertTrue($result['is_bot']);
        $this->assertTrue($result['is_good_bot']);
    }

    // ========================================================================
    // 🔍 detect() TESTS — Bad Bots
    // ========================================================================

    /**
     * Test detect identifies Nikto scanner as a bad bot
     */
    public function testDetectIdentifiesNiktoAsBadBot(): void
    {
        $ua = 'Mozilla/5.0 (Nikto/2.1.5)';

        $result = \BotDetector::detect($ua);

        $this->assertTrue($result['is_bot']);
        $this->assertTrue($result['is_bad_bot']);
        $this->assertFalse($result['is_good_bot']);
    }

    /**
     * Test detect identifies sqlmap as a bad bot
     */
    public function testDetectIdentifiesSqlmapAsBadBot(): void
    {
        $ua = 'sqlmap/1.7.2#stable (https://sqlmap.org)';

        $result = \BotDetector::detect($ua);

        $this->assertTrue($result['is_bot']);
        $this->assertTrue($result['is_bad_bot']);
    }

    /**
     * Test detect identifies WPScan as a bad bot
     */
    public function testDetectIdentifiesWPScanAsBadBot(): void
    {
        $ua = 'WPScan v3.8.22 (https://wpscan.com/)';

        $result = \BotDetector::detect($ua);

        $this->assertTrue($result['is_bot']);
        $this->assertTrue($result['is_bad_bot']);
    }

    /**
     * Test detect identifies python-requests as a bad bot
     */
    public function testDetectIdentifiesPythonRequestsAsBadBot(): void
    {
        $ua = 'python-requests/2.31.0';

        $result = \BotDetector::detect($ua);

        $this->assertTrue($result['is_bot']);
        $this->assertTrue($result['is_bad_bot']);
    }

    /**
     * Test detect identifies Scrapy as a bad bot
     */
    public function testDetectIdentifiesScrapyAsBadBot(): void
    {
        $ua = 'Scrapy/2.11.0 (+https://scrapy.org)';

        $result = \BotDetector::detect($ua);

        $this->assertTrue($result['is_bot']);
        $this->assertTrue($result['is_bad_bot']);
    }

    // ========================================================================
    // 🔍 detect() TESTS — Empty User Agent
    // ========================================================================

    /**
     * Test detect flags empty user agent as a bot
     */
    public function testDetectFlagsEmptyUserAgentAsBot(): void
    {
        $result = \BotDetector::detect('');

        $this->assertTrue($result['is_bot']);
        $this->assertEquals('empty_ua', $result['detection_method']);
        $this->assertEquals(1.0, $result['confidence']);
    }

    /**
     * Test detect marks empty UA as bad bot when block_empty_ua is enabled
     */
    public function testDetectEmptyUaIsBadBotWhenBlockEnabled(): void
    {
        setTestSetting('security.bot_detection.block_empty_ua', true);

        $result = \BotDetector::detect('');

        $this->assertTrue($result['is_bad_bot']);
    }

    /**
     * Test detect marks empty UA as not bad bot when block_empty_ua is disabled
     */
    public function testDetectEmptyUaIsNotBadBotWhenBlockDisabled(): void
    {
        setTestSetting('security.bot_detection.block_empty_ua', false);

        $result = \BotDetector::detect('');

        $this->assertFalse($result['is_bad_bot']);
    }

    // ========================================================================
    // 🚫 shouldBlock() TESTS
    // ========================================================================

    /**
     * Test shouldBlock returns false for normal browser user agents
     */
    public function testShouldBlockReturnsFalseForBrowser(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

        $this->assertFalse(\BotDetector::shouldBlock($ua));
    }

    /**
     * Test shouldBlock returns false for good bots when allow_good_bots is enabled
     */
    public function testShouldBlockReturnsFalseForGoodBot(): void
    {
        setTestSetting('security.bot_detection.allow_good_bots', true);

        $ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

        $this->assertFalse(\BotDetector::shouldBlock($ua));
    }

    /**
     * Test shouldBlock returns true for bad bots when blocking is enabled
     */
    public function testShouldBlockReturnsTrueForBadBot(): void
    {
        setTestSetting('security.bot_detection.block_bad_bots', true);

        $ua = 'Nikto/2.1.5';

        $this->assertTrue(\BotDetector::shouldBlock($ua));
    }

    /**
     * Test shouldBlock returns false for bad bots when blocking is disabled
     */
    public function testShouldBlockReturnsFalseWhenBlockingDisabled(): void
    {
        setTestSetting('security.bot_detection.block_bad_bots', false);

        $ua = 'sqlmap/1.7.2#stable';

        $this->assertFalse(\BotDetector::shouldBlock($ua));
    }

    // ========================================================================
    // ✅ isGoodBot() TESTS
    // ========================================================================

    /**
     * Test isGoodBot returns true for Googlebot
     */
    public function testIsGoodBotReturnsTrueForGooglebot(): void
    {
        $this->assertTrue(\BotDetector::isGoodBot('Googlebot/2.1'));
    }

    /**
     * Test isGoodBot returns true for Applebot
     */
    public function testIsGoodBotReturnsTrueForApplebot(): void
    {
        $this->assertTrue(\BotDetector::isGoodBot('Applebot/0.1'));
    }

    /**
     * Test isGoodBot returns false for an unknown user agent
     */
    public function testIsGoodBotReturnsFalseForUnknown(): void
    {
        $this->assertFalse(\BotDetector::isGoodBot('CustomScraper/1.0'));
    }

    /**
     * Test isGoodBot returns false for a bad bot pretending to be generic
     */
    public function testIsGoodBotReturnsFalseForBadBot(): void
    {
        $this->assertFalse(\BotDetector::isGoodBot('sqlmap/1.7'));
    }

    // ========================================================================
    // 📋 DETECTION METHOD TESTS
    // ========================================================================

    /**
     * Test that pattern fallback method is used (no CrawlerDetect/Browscap in tests)
     */
    public function testDetectionUsesPatternFallback(): void
    {
        // 📝 In test environment, CrawlerDetect and Browscap are unavailable
        $result = \BotDetector::detect('Mozilla/5.0 (compatible; Googlebot/2.1)');

        $this->assertEquals('pattern', $result['detection_method']);
    }

    /**
     * Test detection method is 'none' for unrecognised user agents
     */
    public function testDetectionMethodIsNoneForNormalBrowser(): void
    {
        $result = \BotDetector::detect('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)');

        $this->assertEquals('none', $result['detection_method']);
        $this->assertEquals(0.0, $result['confidence']);
    }

    /**
     * Test result array contains all expected keys
     */
    public function testDetectResultHasExpectedKeys(): void
    {
        $result = \BotDetector::detect('Mozilla/5.0');

        $this->assertArrayHasKey('is_bot', $result);
        $this->assertArrayHasKey('bot_name', $result);
        $this->assertArrayHasKey('is_good_bot', $result);
        $this->assertArrayHasKey('is_bad_bot', $result);
        $this->assertArrayHasKey('detection_method', $result);
        $this->assertArrayHasKey('confidence', $result);
    }
}
