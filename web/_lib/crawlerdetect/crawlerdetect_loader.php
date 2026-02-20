<?php
/**
 * ============================================================================
 * 🤖 SIGNula - CrawlerDetect Library Loader
 * ============================================================================
 *
 * Purpose: Load CrawlerDetect library for bot/crawler User-Agent detection
 * PHP Version: 8.3+
 *
 * CrawlerDetect is a pure-PHP bot detection library that requires NO Composer
 * and makes NO external API calls. It detects 1,000+ bots by analysing
 * User-Agent strings using regularly updated regex patterns.
 *
 * Installation Instructions:
 * 1. Download CrawlerDetect from: https://github.com/JayBizzle/Crawler-Detect/releases
 * 2. Extract the archive
 * 3. Upload the 'src/' folder contents to: web/_lib/crawlerdetect/
 *    Required files:
 *      - web/_lib/crawlerdetect/CrawlerDetect.php
 *      - web/_lib/crawlerdetect/Fixtures/Crawlers.php
 *      - web/_lib/crawlerdetect/Fixtures/Exclusions.php
 *      - web/_lib/crawlerdetect/Fixtures/Headers.php
 * 4. No further configuration needed
 *
 * @see https://github.com/JayBizzle/Crawler-Detect
 * @see https://crawlerdetect.io/
 *
 * @package    SIGNula
 * @subpackage Libraries
 * @version    1.0.0
 * @since      2.6.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🤖 CrawlerDetect Library Loader
 *
 * Provides methods to check for CrawlerDetect availability and load it on demand.
 * Falls back gracefully when the library is not installed (uses regex patterns instead).
 *
 * @see https://github.com/JayBizzle/Crawler-Detect
 */
class CrawlerDetectLoader
{
    /** @var bool Whether CrawlerDetect has been loaded in the current request */
    private static bool $loaded = false;

    /** @var string Path to the CrawlerDetect main class file */
    private static string $crawlerDetectPath = '';

    /**
     * 🔍 Check if CrawlerDetect library is installed
     *
     * @return bool True if CrawlerDetect.php and its Fixtures exist
     */
    public static function isAvailable(): bool
    {
        $path = self::getCrawlerDetectPath();

        // 📁 Check main class file exists
        if (!file_exists($path)) {
            return false;
        }

        // 📁 Check fixture files exist (required for pattern matching)
        $fixturesDir = dirname($path) . DIRECTORY_SEPARATOR . 'Fixtures';
        $requiredFixtures = ['Crawlers.php', 'Exclusions.php', 'Headers.php'];

        foreach ($requiredFixtures as $fixture) {
            if (!file_exists($fixturesDir . DIRECTORY_SEPARATOR . $fixture)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 📦 Load the CrawlerDetect library
     *
     * @return bool True if CrawlerDetect was loaded successfully
     */
    public static function load(): bool
    {
        // ⏩ Skip if already loaded
        if (self::$loaded) {
            return true;
        }

        if (!self::isAvailable()) {
            // ⚠️ Library not installed — log but don't throw
            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    null,
                    'crawlerdetect_not_found',
                    'system',
                    'info',
                    'CrawlerDetect library not found — bot detection will use regex fallback patterns',
                    ['expectedPath' => self::getCrawlerDetectPath()]
                );
            }
            return false;
        }

        $path = self::getCrawlerDetectPath();

        // 📚 Include the CrawlerDetect fixture files first (they define the pattern arrays)
        $fixturesDir = dirname($path) . DIRECTORY_SEPARATOR . 'Fixtures';
        require_once $fixturesDir . DIRECTORY_SEPARATOR . 'Crawlers.php';
        require_once $fixturesDir . DIRECTORY_SEPARATOR . 'Exclusions.php';
        require_once $fixturesDir . DIRECTORY_SEPARATOR . 'Headers.php';

        // 📚 Include the main CrawlerDetect class
        require_once $path;

        // ✅ Verify the class was loaded
        // CrawlerDetect uses the namespace Jaybizzle\CrawlerDetect
        if (class_exists('Jaybizzle\\CrawlerDetect\\CrawlerDetect')) {
            self::$loaded = true;
            return true;
        }

        return false;
    }

    /**
     * 📁 Get the path to the CrawlerDetect main class file
     *
     * @return string Absolute path to CrawlerDetect.php
     */
    private static function getCrawlerDetectPath(): string
    {
        if (empty(self::$crawlerDetectPath)) {
            if (defined('LIB_DIR')) {
                self::$crawlerDetectPath = LIB_DIR . DIRECTORY_SEPARATOR
                    . 'crawlerdetect' . DIRECTORY_SEPARATOR . 'CrawlerDetect.php';
            } else {
                // 🔄 Fallback: calculate path relative to this file
                self::$crawlerDetectPath = __DIR__ . DIRECTORY_SEPARATOR . 'CrawlerDetect.php';
            }
        }

        return self::$crawlerDetectPath;
    }

    /**
     * 📋 Get installation status info
     *
     * Returns detailed information about CrawlerDetect installation status
     * for display in admin settings pages.
     *
     * @return array{installed: bool, loaded: bool, path: string, fixturesFound: bool, downloadURL: string}
     */
    public static function getStatus(): array
    {
        $path = self::getCrawlerDetectPath();
        $mainFileExists = file_exists($path);

        // 📁 Check fixture files
        $fixturesDir = dirname($path) . DIRECTORY_SEPARATOR . 'Fixtures';
        $fixturesFound = is_dir($fixturesDir)
            && file_exists($fixturesDir . DIRECTORY_SEPARATOR . 'Crawlers.php')
            && file_exists($fixturesDir . DIRECTORY_SEPARATOR . 'Exclusions.php')
            && file_exists($fixturesDir . DIRECTORY_SEPARATOR . 'Headers.php');

        return [
            'installed'     => $mainFileExists && $fixturesFound,
            'loaded'        => self::$loaded,
            'path'          => $path,
            'fixturesFound' => $fixturesFound,
            'downloadURL'   => 'https://github.com/JayBizzle/Crawler-Detect/releases',
        ];
    }
}
