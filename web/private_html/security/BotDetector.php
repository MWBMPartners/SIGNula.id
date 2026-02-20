<?php
/**
 * SIGNula - Universal Single Sign-On Authentication System
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package SIGNula
 * @version 2.6.0-beta
 */

/**
 * ============================================================================
 * 🤖 SIGNula - Bot / Crawler Detection
 * ============================================================================
 *
 * Purpose: Detect and classify bots, crawlers, and automated traffic
 * PHP Version: 8.3+
 *
 * Detection pipeline (in priority order):
 * 1. CrawlerDetect library (comprehensive UA pattern matching)
 * 2. PHP Browscap / get_browser() — may be unavailable on shared hosting
 * 3. Built-in regex fallback patterns for common good and bad bots
 *
 * Features:
 * - Multi-layer bot detection with graceful fallbacks
 * - Good bot allow-listing (Googlebot, Bingbot, etc.)
 * - Bad bot blocking (scanners, scrapers, exploit tools)
 * - Optional reverse-DNS verification for trusted crawlers
 * - Configurable via tblSettings (security.bot_detection.*)
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.6.0-beta
 * @link       https://SIGNula.id
 *
 * @see https://github.com/JayBizzle/Crawler-Detect  CrawlerDetect library
 * @see https://www.php.net/manual/en/function.get-browser.php  Browscap
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🤖 Bot Detector Class
 *
 * Provides static methods to detect, classify, and optionally block
 * automated traffic (bots, crawlers, scanners). All methods are static
 * for convenient access throughout the application.
 */
class BotDetector
{
    // ========================================================================
    // 🔧 CONFIGURATION
    // ========================================================================

    /**
     * ✅ Check if bot detection is enabled
     *
     * Reads the 'security.bot_detection.enabled' setting from tblSettings.
     * Defaults to true when the setting is not present.
     *
     * @return bool True if bot detection is enabled
     *
     * @see getSetting() Defined in the global helpers / bootstrap
     */
    public static function isEnabled(): bool
    {
        return (bool) getSetting('security.bot_detection.enabled', true);
    }

    // ========================================================================
    // 🔍 PUBLIC DETECTION METHODS
    // ========================================================================

    /**
     * 🔍 Detect whether a User-Agent belongs to a bot
     *
     * Runs the full detection pipeline and returns a structured result array
     * describing what was found. The pipeline is executed in order:
     *   1. Empty-UA check
     *   2. CrawlerDetect library (if installed)
     *   3. PHP get_browser() / Browscap (if available)
     *   4. Built-in regex patterns (always available — final fallback)
     *
     * @param string|null $userAgent The User-Agent string to analyse.
     *                               When null, reads from $_SERVER['HTTP_USER_AGENT'].
     *
     * @return array{
     *     is_bot: bool,
     *     bot_name: ?string,
     *     is_good_bot: bool,
     *     is_bad_bot: bool,
     *     detection_method: string,
     *     confidence: float
     * }
     */
    public static function detect(?string $userAgent = null): array
    {
        // 📥 Resolve User-Agent — fall back to the server variable when not supplied
        if ($userAgent === null) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        }

        // ---------------------------------------------------------------
        // 1️⃣ Empty User-Agent check
        // ---------------------------------------------------------------
        if (trim($userAgent) === '') {
            $blockEmpty = (bool) getSetting('security.bot_detection.block_empty_ua', false);

            return [
                'is_bot'           => true,
                'bot_name'         => null,
                'is_good_bot'      => false,
                'is_bad_bot'       => $blockEmpty,
                'detection_method' => 'empty_ua',
                'confidence'       => 1.0,
            ];
        }

        // ---------------------------------------------------------------
        // 2️⃣ CrawlerDetect library (highest fidelity when available)
        // ---------------------------------------------------------------
        $crawlerResult = self::detectWithCrawlerDetect($userAgent);
        if ($crawlerResult !== null) {
            return $crawlerResult;
        }

        // ---------------------------------------------------------------
        // 3️⃣ PHP Browscap / get_browser()
        // ---------------------------------------------------------------
        $browscapResult = self::detectWithBrowscap($userAgent);
        if ($browscapResult !== null) {
            return $browscapResult;
        }

        // ---------------------------------------------------------------
        // 4️⃣ Built-in regex patterns (final fallback — always returns)
        // ---------------------------------------------------------------
        return self::detectWithPatterns($userAgent);
    }

    /**
     * 🚫 Determine whether the current request should be blocked
     *
     * A request is blocked only when ALL of the following are true:
     *  - The UA is classified as a bad bot
     *  - The 'security.bot_detection.block_bad_bots' setting is enabled
     *
     * Good bots are never blocked when 'security.bot_detection.allow_good_bots'
     * is true (the default).
     *
     * @param string|null $userAgent User-Agent to evaluate (null = auto-detect)
     *
     * @return bool True if the request should be blocked
     */
    public static function shouldBlock(?string $userAgent = null): bool
    {
        $result = self::detect($userAgent);

        // ✅ Never block good bots when the allow setting is on
        if ($result['is_good_bot'] === true) {
            $allowGood = (bool) getSetting('security.bot_detection.allow_good_bots', true);
            if ($allowGood) {
                return false;
            }
        }

        // 🚫 Block only when flagged as bad AND the block setting is enabled
        if ($result['is_bad_bot'] === true) {
            return (bool) getSetting('security.bot_detection.block_bad_bots', true);
        }

        return false;
    }

    /**
     * ✅ Check if a User-Agent belongs to a known good bot
     *
     * Matches against the internal good-bot pattern list. When DNS verification
     * is enabled (security.bot_detection.dns_verify) AND a client IP is available,
     * the bot's identity is confirmed via reverse + forward DNS lookup.
     *
     * @param string $userAgent The User-Agent string to check
     *
     * @return bool True if the UA matches a known good bot
     */
    public static function isGoodBot(string $userAgent): bool
    {
        $goodBots = self::getGoodBotPatterns();
        $lowerUA  = strtolower($userAgent);

        foreach ($goodBots as $botKey => $botInfo) {
            if (preg_match($botInfo['pattern'], $lowerUA)) {
                // 🌐 Optional DNS verification for extra trust
                $dnsVerify = (bool) getSetting('security.bot_detection.dns_verify', false);

                if ($dnsVerify && !empty($_SERVER['REMOTE_ADDR'])) {
                    // Verify at least one of the expected domains
                    foreach ($botInfo['domains'] as $domain) {
                        if (self::verifyGoodBotDNS($_SERVER['REMOTE_ADDR'], $domain)) {
                            return true;
                        }
                    }
                    // DNS verification failed — the UA claims to be a good bot but
                    // the IP does not resolve to an expected domain
                    return false;
                }

                // Without DNS verification we trust the UA string
                return true;
            }
        }

        return false;
    }

    // ========================================================================
    // 🔬 PRIVATE DETECTION METHODS
    // ========================================================================

    /**
     * 🤖 Detect using the CrawlerDetect library
     *
     * Attempts to load the CrawlerDetect library via CrawlerDetectLoader.
     * Returns null when the library is unavailable so the pipeline continues.
     *
     * @param string $userAgent User-Agent string
     *
     * @return array|null Detection result or null if library is not installed
     *
     * @see CrawlerDetectLoader Located at web/_lib/crawlerdetect/crawlerdetect_loader.php
     * @see https://github.com/JayBizzle/Crawler-Detect
     */
    private static function detectWithCrawlerDetect(string $userAgent): ?array
    {
        // ⏩ Skip if the loader class is not available
        if (!class_exists('CrawlerDetectLoader')) {
            return null;
        }

        // 📦 Attempt to load the library
        if (!CrawlerDetectLoader::isAvailable() || !CrawlerDetectLoader::load()) {
            return null;
        }

        // 🤖 Run CrawlerDetect analysis
        $cd       = new \Jaybizzle\CrawlerDetect\CrawlerDetect();
        $isCrawler = $cd->isCrawler($userAgent);

        if (!$isCrawler) {
            return null; // Not detected — let next layer try
        }

        // 📝 Extract matched bot name
        $botName   = $cd->getMatches() ?: 'unknown';
        $isGoodBot = self::isGoodBot($userAgent);
        $isBadBot  = !$isGoodBot && self::matchesBadBotPattern($userAgent);

        return [
            'is_bot'           => true,
            'bot_name'         => $botName,
            'is_good_bot'      => $isGoodBot,
            'is_bad_bot'       => $isBadBot,
            'detection_method' => 'crawlerdetect',
            'confidence'       => 0.95,
        ];
    }

    /**
     * 🌐 Detect using PHP's get_browser() / Browscap
     *
     * get_browser() relies on a browscap.ini file configured in php.ini.
     * On many shared hosting environments this is unavailable, so the call
     * is wrapped in a try/catch to degrade gracefully.
     *
     * @param string $userAgent User-Agent string
     *
     * @return array|null Detection result or null if Browscap is not available
     *
     * @see https://www.php.net/manual/en/function.get-browser.php
     */
    private static function detectWithBrowscap(string $userAgent): ?array
    {
        try {
            // 🔍 Attempt to get browser info — may throw or return false
            $browserInfo = @get_browser($userAgent, true);

            if ($browserInfo === false || !is_array($browserInfo)) {
                return null;
            }

            // 🤖 Check the 'crawler' property set by Browscap
            if (!empty($browserInfo['crawler'])) {
                $botName   = $browserInfo['browser'] ?? 'unknown';
                $isGoodBot = self::isGoodBot($userAgent);
                $isBadBot  = !$isGoodBot && self::matchesBadBotPattern($userAgent);

                return [
                    'is_bot'           => true,
                    'bot_name'         => $botName,
                    'is_good_bot'      => $isGoodBot,
                    'is_bad_bot'       => $isBadBot,
                    'detection_method' => 'browscap',
                    'confidence'       => 0.85,
                ];
            }

            // Browscap says it is not a crawler — return null to let patterns run
            return null;

        } catch (\Throwable $e) {
            // ⚠️ get_browser() is not available on this host — degrade silently
            return null;
        }
    }

    /**
     * 🔤 Detect using built-in regex patterns (final fallback)
     *
     * This method always returns a result. It checks against the good-bot
     * patterns first, then the bad-bot patterns, and finally returns a
     * not-a-bot result if nothing matches.
     *
     * @param string $userAgent User-Agent string
     *
     * @return array Detection result (never null)
     */
    private static function detectWithPatterns(string $userAgent): array
    {
        $lowerUA = strtolower($userAgent);

        // ✅ Check good bots first
        $goodBots = self::getGoodBotPatterns();
        foreach ($goodBots as $botKey => $botInfo) {
            if (preg_match($botInfo['pattern'], $lowerUA)) {
                return [
                    'is_bot'           => true,
                    'bot_name'         => $botKey,
                    'is_good_bot'      => true,
                    'is_bad_bot'       => false,
                    'detection_method' => 'pattern',
                    'confidence'       => 0.75,
                ];
            }
        }

        // 🚫 Check bad bots
        $badPatterns = self::getBadBotPatterns();
        foreach ($badPatterns as $pattern) {
            if (preg_match($pattern, $lowerUA)) {
                return [
                    'is_bot'           => true,
                    'bot_name'         => null,
                    'is_good_bot'      => false,
                    'is_bad_bot'       => true,
                    'detection_method' => 'pattern',
                    'confidence'       => 0.70,
                ];
            }
        }

        // 🟢 Nothing matched — assume legitimate human traffic
        return [
            'is_bot'           => false,
            'bot_name'         => null,
            'is_good_bot'      => false,
            'is_bad_bot'       => false,
            'detection_method' => 'none',
            'confidence'       => 0.0,
        ];
    }

    // ========================================================================
    // 🌐 DNS VERIFICATION
    // ========================================================================

    /**
     * 🌐 Verify a good bot's identity via reverse + forward DNS
     *
     * Steps:
     *  1. Reverse-resolve the IP to a hostname (gethostbyaddr)
     *  2. Check that the hostname ends with the expected domain
     *  3. Forward-resolve the hostname back to an IP (gethostbyname)
     *  4. Confirm the forward-resolved IP matches the original IP
     *
     * This prevents bad actors from spoofing a good-bot User-Agent string
     * while connecting from an unrelated IP address.
     *
     * @param string $ip             Client IP address (IPv4 or IPv6)
     * @param string $expectedDomain Expected domain suffix (e.g. 'googlebot.com')
     *
     * @return bool True if the IP verifies against the expected domain
     *
     * @see https://developers.google.com/search/docs/crawling-indexing/verifying-googlebot
     */
    private static function verifyGoodBotDNS(string $ip, string $expectedDomain): bool
    {
        // 🔄 Step 1: Reverse DNS lookup (IP -> hostname)
        $hostname = @gethostbyaddr($ip);

        // gethostbyaddr returns the original IP on failure
        if ($hostname === false || $hostname === $ip) {
            return false;
        }

        // 🔍 Step 2: Check that the hostname ends with the expected domain
        $expectedSuffix = '.' . ltrim($expectedDomain, '.');
        if (!str_ends_with(strtolower($hostname), strtolower($expectedSuffix))) {
            return false;
        }

        // 🔄 Step 3: Forward DNS lookup (hostname -> IP) to confirm
        $forwardIP = @gethostbyname($hostname);

        // gethostbyname returns the hostname on failure
        if ($forwardIP === $hostname) {
            return false;
        }

        // ✅ Step 4: Verify the forward-resolved IP matches the original
        return ($forwardIP === $ip);
    }

    // ========================================================================
    // 📋 PATTERN LISTS
    // ========================================================================

    /**
     * ✅ Get good bot patterns
     *
     * Returns an associative array of known good/legitimate bots.
     * Each entry contains a regex pattern and the expected DNS domains
     * for reverse-DNS verification.
     *
     * @return array<string, array{pattern: string, domains: string[]}>
     */
    private static function getGoodBotPatterns(): array
    {
        return [
            'googlebot' => [
                'pattern' => '/googlebot/i',
                'domains' => ['googlebot.com', 'google.com'],
            ],
            'bingbot' => [
                'pattern' => '/bingbot/i',
                'domains' => ['search.msn.com'],
            ],
            'yandexbot' => [
                'pattern' => '/yandexbot/i',
                'domains' => ['yandex.ru', 'yandex.net', 'yandex.com'],
            ],
            'duckduckbot' => [
                'pattern' => '/duckduckbot/i',
                'domains' => ['duckduckgo.com'],
            ],
            'slurp' => [
                'pattern' => '/slurp/i',
                'domains' => ['crawl.yahoo.net'],
            ],
            'baiduspider' => [
                'pattern' => '/baiduspider/i',
                'domains' => ['baidu.com', 'baidu.jp'],
            ],
            'facebot' => [
                'pattern' => '/facebot|facebookexternalhit/i',
                'domains' => ['facebook.com', 'fbcdn.net'],
            ],
            'ia_archiver' => [
                'pattern' => '/ia_archiver/i',
                'domains' => ['archive.org'],
            ],
            'applebot' => [
                'pattern' => '/applebot/i',
                'domains' => ['apple.com', 'applebot.apple.com'],
            ],
            'twitterbot' => [
                'pattern' => '/twitterbot/i',
                'domains' => ['twitter.com', 'twttr.com'],
            ],
            'linkedinbot' => [
                'pattern' => '/linkedinbot/i',
                'domains' => ['linkedin.com'],
            ],
            'pinterestbot' => [
                'pattern' => '/pinterest/i',
                'domains' => ['pinterest.com'],
            ],
            'msnbot' => [
                'pattern' => '/msnbot/i',
                'domains' => ['search.msn.com'],
            ],
        ];
    }

    /**
     * 🚫 Get bad bot patterns
     *
     * Returns an array of regex patterns that match known malicious bots,
     * vulnerability scanners, scrapers, and spam tools.
     *
     * @return string[] Array of regex patterns
     *
     * @see https://owasp.org/www-community/Automated_Threats_to_Web_Applications
     */
    private static function getBadBotPatterns(): array
    {
        return [
            // 🔓 Vulnerability scanners
            '/nikto/i',
            '/sqlmap/i',
            '/nmap/i',
            '/masscan/i',
            '/zgrab/i',
            '/acunetix/i',
            '/nessus/i',
            '/openvas/i',
            '/arachni/i',
            '/w3af/i',
            '/burpsuite|burp\s?suite/i',
            '/havij/i',

            // 🔎 Directory / path brute-forcers
            '/gobuster/i',
            '/dirbuster/i',
            '/dirb[\s\/]/i',
            '/feroxbuster/i',
            '/ffuf/i',
            '/wfuzz/i',

            // 🔌 CMS-specific scanners
            '/wpscan/i',
            '/joomscan/i',
            '/droopescan/i',

            // 🕷️ Aggressive scrapers and harvesters
            '/wget[\s\/]/i',
            '/python-requests/i',
            '/scrapy/i',
            '/httpclient/i',
            '/harvest/i',
            '/emailsiphon/i',
            '/extractorpro/i',
            '/webbandit/i',
            '/emailcollector/i',

            // 📨 Spam bots
            '/xrumer/i',
            '/senuke/i',
            '/scrapebox/i',
            '/gsabot/i',
            '/semrushbot/i',

            // 🛠️ Generic attack tools
            '/metasploit/i',
            '/hydra/i',
            '/medusa/i',
            '/zap[\s\/]/i',
            '/commix/i',
            '/jbrofuzz/i',
        ];
    }

    /**
     * 🚫 Check if a User-Agent matches any bad bot pattern
     *
     * Utility method used internally to classify bots detected by
     * CrawlerDetect or Browscap that are not in the good-bot list.
     *
     * @param string $userAgent User-Agent string to check
     *
     * @return bool True if the UA matches a known bad bot pattern
     */
    private static function matchesBadBotPattern(string $userAgent): bool
    {
        $lowerUA    = strtolower($userAgent);
        $badPatterns = self::getBadBotPatterns();

        foreach ($badPatterns as $pattern) {
            if (preg_match($pattern, $lowerUA)) {
                return true;
            }
        }

        return false;
    }
}

// ✅ BotDetector loaded successfully
