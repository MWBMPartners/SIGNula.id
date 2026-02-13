<?php
/**
 * ============================================================================
 * 📄 SIGNula - TCPDF Library Loader
 * ============================================================================
 *
 * Purpose: Load TCPDF library for PDF invoice generation
 * PHP Version: 8.3+
 *
 * TCPDF is a pure-PHP PDF generation library that requires NO Composer.
 * This makes it ideal for Dreamhost shared hosting environments.
 *
 * Installation Instructions:
 * 1. Download TCPDF v6.7+ from: https://github.com/tecnickcom/TCPDF/releases
 * 2. Extract the archive
 * 3. Upload the 'tcpdf' folder contents to: web/_lib/tcpdf/
 *    (the tcpdf.php file should be at: web/_lib/tcpdf/tcpdf.php)
 * 4. Ensure the 'fonts/' subdirectory is writable by the web server
 *
 * Alternatively, use the built-in download helper at:
 *   /admin/payments/setup-tcpdf (requires super admin access)
 *
 * @see https://tcpdf.org/
 * @see https://github.com/tecnickcom/TCPDF
 *
 * @package    SIGNula
 * @subpackage Libraries
 * @version    1.0.0
 * @since      2.4.0-beta
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
 * 📄 TCPDF Library Loader
 *
 * Provides methods to check for TCPDF availability and load it on demand.
 * Falls back gracefully when TCPDF is not installed (HTML-only invoices).
 */
class TCPDFLoader
{
    /** @var bool Whether TCPDF has been loaded in the current request */
    private static bool $loaded = false;

    /** @var string Path to the TCPDF main class file */
    private static string $tcpdfPath = '';

    /**
     * 🔍 Check if TCPDF library is installed
     *
     * @return bool True if tcpdf.php exists in the expected location
     */
    public static function isAvailable(): bool
    {
        // 📁 Build the path to the TCPDF main class file
        // Uses LIB_DIR constant defined in config.php
        $path = self::getTCPDFPath();

        return file_exists($path);
    }

    /**
     * 📦 Load the TCPDF library
     *
     * @return bool True if TCPDF was loaded successfully
     */
    public static function load(): bool
    {
        // ⏩ Skip if already loaded
        if (self::$loaded) {
            return true;
        }

        $path = self::getTCPDFPath();

        if (!file_exists($path)) {
            // ⚠️ TCPDF not installed — log warning but don't throw
            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    null,
                    'tcpdf_not_found',
                    'system',
                    'warning',
                    'TCPDF library not found at: ' . $path . ' — PDF generation will be unavailable',
                    ['expectedPath' => $path]
                );
            }
            return false;
        }

        // 📚 Include the TCPDF main class
        require_once $path;

        // ✅ Verify the class was loaded
        if (class_exists('TCPDF')) {
            self::$loaded = true;
            return true;
        }

        return false;
    }

    /**
     * 📁 Get the path to the TCPDF main class file
     *
     * @return string Absolute path to tcpdf.php
     */
    private static function getTCPDFPath(): string
    {
        if (empty(self::$tcpdfPath)) {
            // 📁 TCPDF should be at web/_lib/tcpdf/tcpdf.php
            // LIB_DIR is defined in config.php as web/_lib
            if (defined('LIB_DIR')) {
                self::$tcpdfPath = LIB_DIR . DIRECTORY_SEPARATOR . 'tcpdf' . DIRECTORY_SEPARATOR . 'tcpdf.php';
            } else {
                // 🔄 Fallback: calculate path relative to this file
                self::$tcpdfPath = __DIR__ . DIRECTORY_SEPARATOR . 'tcpdf.php';
            }
        }

        return self::$tcpdfPath;
    }

    /**
     * 📋 Get installation status info
     *
     * Returns detailed information about TCPDF installation status
     * for display in admin settings pages.
     *
     * @return array Installation status details
     */
    public static function getStatus(): array
    {
        $path = self::getTCPDFPath();
        $installed = file_exists($path);
        $version = null;

        // 🔍 Try to detect TCPDF version if installed
        if ($installed && !self::$loaded) {
            // 📖 Read just enough of the file to find the version constant
            $contents = file_get_contents($path, false, null, 0, 5000);
            if ($contents !== false && preg_match("/define\s*\(\s*'K_TCPDF_VERSION'\s*,\s*'([^']+)'/", $contents, $matches)) {
                $version = $matches[1];
            }
        } elseif (self::$loaded && defined('K_TCPDF_VERSION')) {
            $version = K_TCPDF_VERSION;
        }

        // 📁 Check for writable fonts directory
        $fontsDir = dirname($path) . DIRECTORY_SEPARATOR . 'fonts';
        $fontsWritable = is_dir($fontsDir) && is_writable($fontsDir);

        return [
            'installed'      => $installed,
            'loaded'         => self::$loaded,
            'version'        => $version,
            'path'           => $path,
            'fontsWritable'  => $fontsWritable,
            'downloadURL'    => 'https://github.com/tecnickcom/TCPDF/releases',
        ];
    }
}
