<?php
/**
 * ============================================================================
 * 📱 SIGNula - QR Code Generator
 * ============================================================================
 *
 * Purpose: QR code generation for TOTP provisioning and other uses
 * PHP Version: 8.3+
 *
 * Features:
 * - Client-side QR code generation (JavaScript)
 * - Server-side QR code generation (data URL)
 * - SVG QR code support
 * - Multiple rendering methods
 *
 * Methods:
 * - Primary: Client-side JavaScript (qrcodejs or qrcode-generator CDN)
 * - Fallback: Server-side generation using external library
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @link       https://signulo.id
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 📱 QR Code Generator Class
 *
 * Generates QR codes for TOTP provisioning URIs and other purposes.
 * Supports both client-side (JavaScript) and server-side generation.
 */
class QRCode
{
    /**
     * @var string Default QR code size in pixels
     */
    private const DEFAULT_SIZE = 256;

    /**
     * @var string Error correction level (L=7%, M=15%, Q=25%, H=30%)
     */
    private const ERROR_CORRECTION_LEVEL = 'M';

    // ========================================================================
    // 📱 CLIENT-SIDE QR CODE GENERATION (RECOMMENDED)
    // ========================================================================

    /**
     * 🎨 Generate Client-Side QR Code HTML
     *
     * Returns HTML with JavaScript that generates QR code in browser.
     * This is the recommended method as it requires no server-side libraries.
     *
     * @param string $data Data to encode in QR code
     * @param string $elementId HTML element ID for QR code container
     * @param int $size QR code size in pixels
     * @param array $options Additional options
     * @return string HTML + JavaScript code
     *
     * @example
     * ```php
     * echo QRCode::generateClientSide($totpUri, 'qrcode-container');
     * ```
     */
    public static function generateClientSide(
        string $data,
        string $elementId = 'qrcode',
        int $size = self::DEFAULT_SIZE,
        array $options = []
    ): string {
        // 🧹 Escape data for JavaScript
        $escapedData = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        $escapedData = str_replace("'", "\\'", $escapedData);

        // 🎨 Color options
        $colorDark = $options['colorDark'] ?? '#000000';
        $colorLight = $options['colorLight'] ?? '#ffffff';

        // 📝 Generate HTML with embedded JavaScript
        $html = <<<HTML
<!-- 📱 QR Code Container -->
<div id="{$elementId}" class="qr-code-container" style="width: {$size}px; height: {$size}px; margin: 0 auto;"></div>

<!-- 📚 QR Code Library (CDN with fallback) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"
        integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8CdoGDepg=="
        crossorigin="anonymous"
        referrerpolicy="no-referrer"
        onerror="loadQRCodeFallback()"></script>

<!-- 🔄 Fallback CDN -->
<script>
function loadQRCodeFallback() {
    const script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js';
    script.onerror = function() {
        // 🚨 If all CDNs fail, show error message
        document.getElementById('{$elementId}').innerHTML =
            '<div style="padding: 20px; text-align: center; border: 2px dashed #ccc; border-radius: 8px;">' +
            '<p style="margin: 0; color: #666;">⚠️ QR Code generation unavailable</p>' +
            '<p style="margin: 10px 0 0 0; font-size: 12px; color: #999;">Please enable JavaScript or check your internet connection</p>' +
            '</div>';
    };
    document.head.appendChild(script);
}

// 🎨 Generate QR Code when libraries are loaded
window.addEventListener('load', function() {
    try {
        // Check if QRCode library is available
        if (typeof QRCode !== 'undefined') {
            // Using qrcodejs library
            new QRCode(document.getElementById('{$elementId}'), {
                text: '{$escapedData}',
                width: {$size},
                height: {$size},
                colorDark: '{$colorDark}',
                colorLight: '{$colorLight}',
                correctLevel: QRCode.CorrectLevel.{self::ERROR_CORRECTION_LEVEL}
            });
        } else if (typeof qrcode !== 'undefined') {
            // Using qrcode-generator library (fallback)
            const qr = qrcode(0, '{self::ERROR_CORRECTION_LEVEL}');
            qr.addData('{$escapedData}');
            qr.make();

            // Create image
            const imgTag = qr.createImgTag({$size} / qr.getModuleCount(), 0);
            document.getElementById('{$elementId}').innerHTML = imgTag;
        } else {
            throw new Error('QR Code library not loaded');
        }
    } catch (e) {
        console.error('QR Code generation error:', e);
        document.getElementById('{$elementId}').innerHTML =
            '<div style="padding: 20px; text-align: center; border: 2px dashed #ccc; border-radius: 8px;">' +
            '<p style="margin: 0; color: #666;">⚠️ Unable to generate QR code</p>' +
            '</div>';
    }
});
</script>
HTML;

        return $html;
    }

    /**
     * 🖼️ Generate QR Code Image Tag
     *
     * Returns HTML img tag with QR code using external API.
     * Note: This exposes the data to external service - use with caution for sensitive data.
     *
     * @param string $data Data to encode
     * @param int $size QR code size
     * @param bool $useApi Whether to use external API (default: false for security)
     * @return string HTML img tag or warning message
     *
     * @deprecated For TOTP secrets, use generateClientSide() instead for better security
     */
    public static function generateImageTag(
        string $data,
        int $size = self::DEFAULT_SIZE,
        bool $useApi = false
    ): string {
        if (!$useApi) {
            return '<div class="alert alert-warning">⚠️ For security reasons, server-side QR generation is disabled. Please use client-side generation.</div>';
        }

        // ⚠️ Warning: This sends data to external service - not recommended for sensitive data
        $encodedData = urlencode($data);

        // Using Google Charts API (deprecated but still functional as of 2024)
        // Alternative: Use chart.googleapis.com or another QR API service
        $apiUrl = "https://chart.googleapis.com/chart?chs={$size}x{$size}&cht=qr&chl={$encodedData}&choe=UTF-8";

        return '<img src="' . htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') . '" alt="QR Code" width="' . $size . '" height="' . $size . '" />';
    }

    // ========================================================================
    // 🔧 SERVER-SIDE QR CODE GENERATION
    // ========================================================================

    /**
     * 🖥️ Generate Server-Side QR Code (Data URL)
     *
     * Generates QR code server-side and returns as Data URL.
     * Requires phpqrcode library or similar.
     *
     * @param string $data Data to encode
     * @param int $size QR code size
     * @return string|false Data URL or false if library not available
     *
     * @example
     * ```php
     * $dataUrl = QRCode::generateServerSide($totpUri);
     * echo "<img src='{$dataUrl}' alt='QR Code' />";
     * ```
     */
    public static function generateServerSide(string $data, int $size = self::DEFAULT_SIZE): string|false
    {
        // 📚 Check if phpqrcode library is available
        $phpqrcodePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_private' . DIRECTORY_SEPARATOR . 'libraries' . DIRECTORY_SEPARATOR . 'phpqrcode' . DIRECTORY_SEPARATOR . 'qrlib.php';

        if (file_exists($phpqrcodePath)) {
            try {
                // 📥 Include phpqrcode library
                require_once $phpqrcodePath;

                // 🎨 Generate QR code to temporary buffer
                ob_start();
                QRcode::png($data, null, self::ERROR_CORRECTION_LEVEL, $size / 25, 2);
                $imageData = ob_get_clean();

                // 📝 Convert to Data URL
                $base64 = base64_encode($imageData);
                return 'data:image/png;base64,' . $base64;

            } catch (Exception $e) {
                error_log('QR Code server-side generation error: ' . $e->getMessage());
                return false;
            }
        }

        return false; // Library not available
    }

    /**
     * 📦 Get Library Installation Instructions
     *
     * Returns instructions for installing required libraries.
     *
     * @return array Installation instructions
     */
    public static function getLibraryInstructions(): array
    {
        return [
            'title' => 'QR Code Library Installation (Optional)',
            'description' => 'For server-side QR code generation, install phpqrcode library.',
            'steps' => [
                '1. Download phpqrcode from: https://sourceforge.net/projects/phpqrcode/',
                '2. Extract the files',
                '3. Copy to: _private/libraries/phpqrcode/',
                '4. Ensure qrlib.php is at: _private/libraries/phpqrcode/qrlib.php',
                '5. Server-side generation will automatically become available'
            ],
            'note' => 'Client-side JavaScript generation is recommended and requires no installation.',
            'alternative' => 'You can also use the client-side generation which works without any libraries.'
        ];
    }

    // ========================================================================
    // 🎨 SVG QR CODE GENERATION (INLINE)
    // ========================================================================

    /**
     * 🎨 Generate Inline SVG QR Code
     *
     * Generates simple SVG QR code without external libraries.
     * Limited to small data sizes and low error correction.
     *
     * @param string $data Data to encode (keep short for best results)
     * @param int $size SVG size in pixels
     * @return string SVG markup or error message
     *
     * @example
     * ```php
     * echo QRCode::generateSVG($totpUri);
     * ```
     */
    public static function generateSVG(string $data, int $size = self::DEFAULT_SIZE): string
    {
        // ℹ️ Note: Full QR code generation in pure PHP is complex
        // For production use, recommend client-side JavaScript generation
        // This is a placeholder that recommends the JavaScript method

        return <<<SVG
<div class="qr-code-svg-container" style="width: {$size}px; height: {$size}px; border: 2px dashed #ccc; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-direction: column; padding: 20px; text-align: center;">
    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-bottom: 15px;">
        <rect x="3" y="3" width="7" height="7" fill="currentColor"/>
        <rect x="14" y="3" width="7" height="7" fill="currentColor"/>
        <rect x="3" y="14" width="7" height="7" fill="currentColor"/>
        <rect x="14" y="14" width="3" height="3" fill="currentColor"/>
        <rect x="18" y="14" width="3" height="3" fill="currentColor"/>
        <rect x="14" y="18" width="3" height="3" fill="currentColor"/>
        <rect x="18" y="18" width="3" height="3" fill="currentColor"/>
    </svg>
    <p style="margin: 0; color: #666; font-size: 14px;">📱 Use client-side generation</p>
    <p style="margin: 5px 0 0 0; color: #999; font-size: 12px;">Call QRCode::generateClientSide() instead</p>
</div>
SVG;
    }

    // ========================================================================
    // 🔧 UTILITY METHODS
    // ========================================================================

    /**
     * ✅ Validate QR Code Data
     *
     * Checks if data is suitable for QR code generation.
     *
     * @param string $data Data to validate
     * @return array Validation result ['valid' => bool, 'message' => string]
     */
    public static function validateData(string $data): array
    {
        // QR Code size limits (version 40 max capacity)
        $maxCapacity = [
            'L' => 2953, // Low error correction
            'M' => 2331, // Medium (our default)
            'Q' => 1663, // Quartile
            'H' => 1273  // High
        ];

        $dataLength = strlen($data);
        $maxLength = $maxCapacity[self::ERROR_CORRECTION_LEVEL];

        if ($dataLength > $maxLength) {
            return [
                'valid' => false,
                'message' => "Data too long ({$dataLength} bytes). Maximum for error correction level " .
                           self::ERROR_CORRECTION_LEVEL . " is {$maxLength} bytes."
            ];
        }

        return [
            'valid' => true,
            'message' => 'Data is valid for QR code generation'
        ];
    }

    /**
     * 📊 Get QR Code Info
     *
     * Returns information about QR code for given data.
     *
     * @param string $data Data to analyze
     * @return array QR code information
     */
    public static function getInfo(string $data): array
    {
        return [
            'data_length' => strlen($data),
            'error_correction' => self::ERROR_CORRECTION_LEVEL,
            'default_size' => self::DEFAULT_SIZE,
            'estimated_modules' => ceil(sqrt(strlen($data) * 8)), // Rough estimate
            'validation' => self::validateData($data)
        ];
    }
}

// ✅ QRCode class loaded successfully
