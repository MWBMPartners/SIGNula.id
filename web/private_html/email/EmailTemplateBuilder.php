<?php
/**
 * ============================================================================
 * 📧 SIGNula - Email Template Builder
 * ============================================================================
 *
 * Purpose: Build modern HTML emails with responsive design, dark mode support,
 *          AMP for Email compatibility, and proper MIME multipart structure.
 * PHP Version: 8.3+
 *
 * Features:
 * - Responsive HTML email templates (mobile-first)
 * - Dark mode support via prefers-color-scheme
 * - AMP for Email (text/x-amp-html) support
 * - Proper multipart/alternative MIME structure
 * - Preheader text support (preview text in email clients)
 * - List-Unsubscribe header generation
 * - Accessibility (ARIA roles, semantic HTML)
 * - Outlook/MSO conditional comments for compatibility
 * - Automatic plain-text generation from HTML
 *
 * MIME Structure (when all parts enabled):
 * ```
 * multipart/alternative
 *   ├── text/plain              (plain text fallback)
 *   ├── text/x-amp-html         (AMP interactive version)
 *   └── text/html               (HTML version - preferred)
 * ```
 *
 * @package    SIGNula
 * @subpackage Email
 * @version    1.0.0
 * @see        https://amp.dev/documentation/guides-and-tutorials/learn/email-spec/amp-email-format/
 * @see        https://www.caniemail.com/ - Email client CSS support reference
 * @see        https://litmus.com/community/learning - Email development resources
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🏗️ Email Template Builder
 *
 * Static utility class for building modern, standards-compliant HTML emails
 * with AMP support and responsive design.
 *
 * Usage:
 * ```php
 * // 📧 Build a complete HTML email
 * $html = EmailTemplateBuilder::wrapInLayout(
 *     bodyContent: '<h1>Hello!</h1><p>Welcome to SIGNula.</p>',
 *     preheaderText: 'Welcome to SIGNula - Your account is ready',
 *     headerColor: '#2563eb'
 * );
 *
 * // 📱 Generate AMP version
 * $ampHtml = EmailTemplateBuilder::buildAMPEmail(
 *     bodyContent: '<h1>Hello!</h1><p>Welcome to SIGNula.</p>',
 *     ampComponents: ['amp-form', 'amp-bind']
 * );
 *
 * // 📝 Generate plain text from HTML
 * $plainText = EmailTemplateBuilder::htmlToPlainText($html);
 *
 * // 📋 Build complete multipart email data
 * $parts = EmailTemplateBuilder::buildMultipartEmail(
 *     htmlBody: $html,
 *     plainText: $plainText,
 *     ampBody: $ampHtml
 * );
 * ```
 */
class EmailTemplateBuilder
{
    // ========================================================================
    // 📋 CONSTANTS
    // ========================================================================

    /**
     * @var string Modern email wrapper style (gradient header, card layout)
     */
    public const STYLE_MODERN = 'modern';

    /**
     * @var string Minimal email wrapper style (clean, text-focused)
     */
    public const STYLE_MINIMAL = 'minimal';

    /**
     * @var string Branded email wrapper style (logo-prominent, brand colours)
     */
    public const STYLE_BRANDED = 'branded';

    /**
     * @var string AMP runtime script URL
     * @see https://amp.dev/documentation/guides-and-tutorials/learn/email-spec/amp-email-format/
     */
    private const AMP_RUNTIME_URL = 'https://cdn.ampproject.org/v0.js';

    /**
     * @var string AMP CSS boilerplate (required by spec)
     */
    private const AMP_BOILERPLATE_CSS = 'body{-webkit-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-moz-animation:-amp-start 8s steps(1,end) 0s 1 normal both;animation:-amp-start 8s steps(1,end) 0s 1 normal both}@-webkit-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-moz-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-ms-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-o-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}';

    /**
     * @var string AMP noscript boilerplate
     */
    private const AMP_NOSCRIPT_BOILERPLATE = 'body{-webkit-animation:none;-moz-animation:none;-ms-animation:none;animation:none}';

    /**
     * @var array<string,string> Allowlist of AMP-for-Email components → script version.
     *
     * 🔐 #32: Only these officially-supported components may emit a
     * <script custom-element> tag. An unknown / attacker-supplied name is
     * dropped so it cannot be used to load an arbitrary script URL from the
     * AMP CDN path or smuggle markup into <head>.
     *
     * @see https://amp.dev/documentation/components/?format=email
     */
    private const AMP_ALLOWED_COMPONENTS = [
        'amp-accordion'       => '0.1',
        'amp-anim'            => '0.1',
        'amp-autocomplete'    => '0.1',
        'amp-bind'            => '0.1',
        'amp-carousel'        => '0.1',
        'amp-fit-text'        => '0.1',
        'amp-form'            => '0.1',
        'amp-image-lightbox'  => '0.1',
        'amp-img'             => '0.1',
        'amp-layout'          => '0.1',
        'amp-lightbox'        => '0.1',
        'amp-list'            => '0.1',
        'amp-mustache'        => '0.2',
        'amp-selector'        => '0.1',
        'amp-sidebar'         => '0.1',
        'amp-state'           => '0.1',
        'amp-timeago'         => '0.1',
    ];

    // ========================================================================
    // 🎨 HTML EMAIL WRAPPER
    // ========================================================================

    /**
     * 🎨 Wrap Content in HTML Email Layout
     *
     * Wraps raw body content in a complete, responsive HTML email layout
     * with dark mode support and cross-client compatibility.
     *
     * @param string $bodyContent    Inner HTML content
     * @param string $preheaderText  Preview text shown in email clients (hidden in body)
     * @param string $headerTitle    Header banner text
     * @param string $headerColor    Header background colour (CSS value)
     * @param string $footerContent  Custom footer HTML (uses default if null)
     * @param string $style          Layout style (modern|minimal|branded)
     * @return string Complete HTML email document
     *
     * @see https://www.caniemail.com/features/css-color-scheme/
     */
    public static function wrapInLayout(
        string $bodyContent,
        string $preheaderText = '',
        string $headerTitle = '',
        string $headerColor = '#2563eb',
        ?string $footerContent = null,
        string $style = self::STYLE_MODERN
    ): string {
        $appName = getSetting('app.name', 'SIGNula');
        $siteUrl = getSetting('site.url', 'https://SIGNula.id');
        $currentYear = date('Y');

        // 📝 Default footer
        if ($footerContent === null) {
            $footerContent = self::buildDefaultFooter($appName, $siteUrl, $currentYear);
        }

        // 🎨 Preheader (hidden preview text)
        $preheaderHtml = '';
        if (!empty($preheaderText) && getSetting('email.preheader.enabled', true)) {
            // 📝 Hidden preheader with padding to push Gmail snippet
            $preheaderHtml = '<div style="display:none;font-size:1px;color:#f3f4f6;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">'
                . htmlspecialchars($preheaderText, ENT_QUOTES, 'UTF-8')
                . str_repeat('&#847; &zwnj; &nbsp; ', 30) // Pad to prevent body text leaking into preview
                . '</div>';
        }

        // 📧 Build complete HTML document
        return '<!DOCTYPE html>' . "\n"
            . '<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n"
            . '<meta http-equiv="X-UA-Compatible" content="IE=edge">' . "\n"
            . '<meta name="color-scheme" content="light dark">' . "\n"
            . '<meta name="supported-color-schemes" content="light dark">' . "\n"
            . '<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">' . "\n"
            . '<title>' . htmlspecialchars($headerTitle ?: $appName, ENT_QUOTES, 'UTF-8') . '</title>' . "\n"
            . self::getMSOConditionalComment() . "\n"
            . '<style>' . "\n" . self::getBaseStyles($headerColor) . "\n" . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body style="margin:0;padding:0;background-color:#f3f4f6;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">' . "\n"
            . $preheaderHtml . "\n"
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">' . "\n"
            . '<tr><td align="center" style="padding:40px 20px;">' . "\n"
            . '<table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" style="border-radius:12px;overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,0.1);">' . "\n"
            . (!empty($headerTitle) ? self::buildHeader($headerTitle, $headerColor) : '')
            . '<tr><td class="content">' . "\n"
            . $bodyContent . "\n"
            . '</td></tr>' . "\n"
            . '<tr><td class="footer">' . "\n"
            . $footerContent . "\n"
            . '</td></tr>' . "\n"
            . '</table>' . "\n"
            . '</td></tr>' . "\n"
            . '</table>' . "\n"
            . '</body>' . "\n"
            . '</html>';
    }

    // ========================================================================
    // ⚡ AMP EMAIL SUPPORT
    // ========================================================================

    /**
     * ⚡ Build AMP Email Version
     *
     * Generates an AMP for Email (text/x-amp-html) version of the email.
     * AMP emails support interactive components like forms, carousels, and
     * real-time content updates.
     *
     * Supported by: Gmail, Yahoo Mail, Mail.ru, AOL
     *
     * @param string $bodyContent    AMP-compatible body content
     * @param array  $ampComponents  AMP components to include (e.g., ['amp-form', 'amp-bind'])
     * @param string $customCSS      Custom CSS for AMP email (max 75KB)
     * @return string Complete AMP HTML document
     *
     * @see https://amp.dev/documentation/guides-and-tutorials/learn/email-spec/amp-email-format/
     * @see https://amp.dev/documentation/components/email/
     */
    public static function buildAMPEmail(
        string $bodyContent,
        array $ampComponents = [],
        string $customCSS = ''
    ): string {
        $appName = getSetting('app.name', 'SIGNula');

        // 📦 Build AMP component script tags
        // 🔐 #32: Only allowlisted AMP components emit a <script> tag. Unknown
        //    names are skipped entirely so they cannot drive an arbitrary CDN
        //    script load or break out of the attribute context.
        $componentScripts = '';
        $seenComponents = [];
        foreach ($ampComponents as $component) {
            $component = strtolower(trim((string)$component));

            // ⛔ Skip unknown components and duplicates.
            if (!isset(self::AMP_ALLOWED_COMPONENTS[$component])
                || isset($seenComponents[$component])
            ) {
                continue;
            }
            $seenComponents[$component] = true;

            // 📝 Version comes from the trusted allowlist, never from caller input.
            $version = self::AMP_ALLOWED_COMPONENTS[$component];
            $componentScripts .= '<script async custom-element="' . htmlspecialchars($component, ENT_QUOTES, 'UTF-8')
                . '" src="https://cdn.ampproject.org/v0/' . htmlspecialchars($component, ENT_QUOTES, 'UTF-8')
                . '-' . $version . '.js"></script>' . "\n";
        }

        // 🔐 #31: Sanitise caller-supplied CSS before embedding it in the
        //    <style amp-custom> block so it cannot break out of the style
        //    context or inject active content.
        $customCSS = self::sanitizeCustomCSS($customCSS);

        // 📧 Build AMP document (must follow strict spec)
        return '<!doctype html>' . "\n"
            . '<html ⚡4email data-css-strict>' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<script async src="' . self::AMP_RUNTIME_URL . '"></script>' . "\n"
            . $componentScripts
            . '<style amp4email-boilerplate>' . self::AMP_BOILERPLATE_CSS . '</style>' . "\n"
            . '<style amp-custom>' . "\n"
            . self::getAMPStyles() . "\n"
            . $customCSS . "\n"
            . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . $bodyContent . "\n"
            . '</body>' . "\n"
            . '</html>';
    }

    /**
     * ✅ Check if AMP Email is Enabled
     *
     * @return bool True if AMP email support is enabled in settings
     */
    public static function isAMPEnabled(): bool
    {
        return (bool)getSetting('email.amp.enabled', false);
    }

    // ========================================================================
    // 📋 MULTIPART MIME BUILDING
    // ========================================================================

    /**
     * 📋 Build Multipart Email Data
     *
     * Assembles the MIME multipart structure for an email with
     * plain text, HTML, and optional AMP parts.
     *
     * MIME structure:
     * ```
     * multipart/alternative
     *   ├── text/plain
     *   ├── text/x-amp-html (if AMP enabled and provided)
     *   └── text/html
     * ```
     *
     * @param string      $htmlBody     HTML email body
     * @param string|null $plainText    Plain text body (auto-generated from HTML if null)
     * @param string|null $ampBody      AMP HTML body (optional)
     * @param array       $extraHeaders Additional email headers
     * @return array Email data with 'bodyHTML', 'bodyText', 'bodyAMP', 'headers'
     */
    public static function buildMultipartEmail(
        string $htmlBody,
        ?string $plainText = null,
        ?string $ampBody = null,
        array $extraHeaders = []
    ): array {
        // 📝 Auto-generate plain text from HTML if not provided
        if ($plainText === null) {
            $plainText = self::htmlToPlainText($htmlBody);
        }

        // 📋 Build additional headers
        $headers = $extraHeaders;

        // 📧 Add List-Unsubscribe header if configured
        if (getSetting('email.unsubscribe.header_enabled', true)) {
            $siteUrl = getSetting('site.url', 'https://SIGNula.id');
            $headers['List-Unsubscribe'] = '<' . $siteUrl . '/unsubscribe>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return [
            'bodyHTML' => $htmlBody,
            'bodyText' => $plainText,
            'bodyAMP' => $ampBody,
            'headers' => $headers,
        ];
    }

    // ========================================================================
    // 📝 PLAIN TEXT CONVERSION
    // ========================================================================

    /**
     * 📝 Convert HTML to Plain Text
     *
     * Intelligently converts HTML email content to readable plain text.
     * Preserves structure, links, and formatting where possible.
     *
     * @param string $html HTML content to convert
     * @return string Plain text version
     */
    public static function htmlToPlainText(string $html): string
    {
        // 🧹 Remove preheader/hidden content
        $text = preg_replace('/<div[^>]*style="[^"]*display:\s*none[^"]*"[^>]*>.*?<\/div>/si', '', $html);

        // 📝 Convert line breaks
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);

        // 📝 Convert paragraphs to double newlines
        $text = preg_replace('/<\/p>/i', "\n\n", $text);

        // 📝 Convert headers to uppercase with underlines
        $text = preg_replace_callback(
            '/<h[1-3][^>]*>(.*?)<\/h[1-3]>/si',
            function ($matches) {
                $heading = strip_tags($matches[1]);
                return "\n" . strtoupper($heading) . "\n" . str_repeat('=', strlen($heading)) . "\n";
            },
            $text
        );

        // 📝 Convert lists
        $text = preg_replace('/<li[^>]*>/i', '  - ', $text);
        $text = preg_replace('/<\/li>/i', "\n", $text);

        // 🔗 Convert links: <a href="url">text</a> → text (url)
        $text = preg_replace_callback(
            '/<a[^>]+href="([^"]*)"[^>]*>(.*?)<\/a>/si',
            function ($matches) {
                $url = $matches[1];
                $linkText = strip_tags($matches[2]);
                // 📝 Don't duplicate if link text is the URL
                if (trim($linkText) === trim($url)) {
                    return $url;
                }
                return $linkText . ' (' . $url . ')';
            },
            $text
        );

        // 📝 Convert horizontal rules
        $text = preg_replace('/<hr[^>]*>/i', "\n" . str_repeat('-', 60) . "\n", $text);

        // 📝 Convert table cells to pipe-separated
        $text = preg_replace('/<td[^>]*>/i', ' | ', $text);
        $text = preg_replace('/<\/tr>/i', "\n", $text);

        // 🧹 Remove all remaining HTML tags
        $text = strip_tags($text);

        // 🧹 Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // 🧹 Normalise whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);          // Collapse spaces
        $text = preg_replace('/\n{3,}/', "\n\n", $text);       // Max 2 newlines
        $text = preg_replace('/^\s+$/m', '', $text);           // Remove blank-only lines

        return trim($text);
    }

    // ========================================================================
    // 🎨 CSS STYLES
    // ========================================================================

    /**
     * 🎨 Get Base Email CSS Styles
     *
     * Returns cross-client compatible CSS for HTML emails.
     * Includes dark mode support via prefers-color-scheme.
     *
     * @param string $accentColor Primary accent colour
     * @return string CSS stylesheet
     */
    private static function getBaseStyles(string $accentColor = '#2563eb'): string
    {
        // 🔐 Sanitise color parameter to prevent CSS injection
        $accentColor = self::sanitizeColor($accentColor);
        return <<<CSS
:root { color-scheme: light dark; supported-color-schemes: light dark; }
body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
img { border: 0; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; max-width: 100%; height: auto; }
a { color: {$accentColor}; text-decoration: none; }
a:hover { text-decoration: underline; }
.container { max-width: 600px; margin: 0 auto; }
.header { background: linear-gradient(135deg, {$accentColor} 0%, {$accentColor}dd 100%); padding: 30px 40px; text-align: center; }
.header h1 { color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 24px; margin: 0; font-weight: 700; }
.content { background-color: #ffffff; padding: 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #1f2937; }
.content p { margin: 0 0 16px 0; }
.content h2 { font-size: 20px; color: #111827; margin: 24px 0 12px 0; }
.content h3 { font-size: 18px; color: #1f2937; margin: 20px 0 10px 0; }
.btn { display: inline-block; background-color: {$accentColor}; color: #ffffff !important; padding: 14px 32px; border-radius: 8px; font-weight: 600; font-size: 16px; text-decoration: none !important; margin: 20px 0; text-align: center; }
.btn:hover { opacity: 0.9; }
.btn-danger { background-color: #dc2626; }
.btn-success { background-color: #059669; }
.alert-box { border-radius: 8px; padding: 20px; margin: 20px 0; }
.alert-box-danger { background-color: #fef2f2; border: 1px solid #fecaca; border-left: 4px solid #dc2626; }
.alert-box-info { background-color: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #2563eb; }
.alert-box-success { background-color: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #059669; }
.alert-box-warning { background-color: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #d97706; }
.info-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
.info-table td { padding: 10px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
.info-table td:first-child { font-weight: 600; color: #6b7280; width: 140px; }
.footer { background-color: #f9fafb; padding: 24px 40px; text-align: center; font-size: 12px; color: #9ca3af; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; border-top: 1px solid #e5e7eb; }
.footer a { color: #6b7280; }
.muted { color: #6b7280; font-size: 14px; }
.small { font-size: 13px; color: #9ca3af; }
@media (prefers-color-scheme: dark) {
    body { background-color: #111827 !important; }
    .content { background-color: #1f2937 !important; color: #e5e7eb !important; }
    .content h2, .content h3 { color: #f3f4f6 !important; }
    .alert-box-danger { background-color: #451a1a !important; border-color: #7f1d1d !important; }
    .alert-box-info { background-color: #1e3a5f !important; border-color: #1e40af !important; }
    .alert-box-success { background-color: #064e3b !important; border-color: #047857 !important; }
    .alert-box-warning { background-color: #451a03 !important; border-color: #92400e !important; }
    .info-table td { border-color: #374151 !important; }
    .footer { background-color: #111827 !important; color: #6b7280 !important; border-color: #374151 !important; }
    .muted { color: #9ca3af !important; }
}
@media only screen and (max-width: 620px) {
    .container { width: 100% !important; }
    .content { padding: 24px !important; }
    .header { padding: 20px 24px !important; }
    .footer { padding: 20px 24px !important; }
    .btn { display: block !important; text-align: center !important; }
}
CSS;
    }

    /**
     * ⚡ Get AMP Email Styles
     *
     * Returns CSS compatible with AMP for Email restrictions.
     * AMP emails have strict CSS limits (max 75KB, no !important, no external fonts).
     *
     * @return string AMP-compatible CSS
     * @see https://amp.dev/documentation/guides-and-tutorials/learn/email-spec/amp-email-css/
     */
    private static function getAMPStyles(): string
    {
        return <<<CSS
body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #1f2937; background-color: #f3f4f6; }
.container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; }
.header { background-color: #2563eb; padding: 30px 40px; text-align: center; }
.header h1 { color: #ffffff; font-size: 24px; margin: 0; font-weight: 700; }
.content { padding: 40px; }
.content p { margin: 0 0 16px 0; }
.btn { display: inline-block; background-color: #2563eb; color: #ffffff; padding: 14px 32px; border-radius: 8px; font-weight: 600; font-size: 16px; text-decoration: none; margin: 20px 0; }
.footer { background-color: #f9fafb; padding: 24px 40px; text-align: center; font-size: 12px; color: #9ca3af; }
CSS;
    }

    // ========================================================================
    // 🔧 HELPER METHODS
    // ========================================================================

    /**
     * 📝 Build Header Banner
     *
     * @param string $title    Header title text
     * @param string $bgColor  Background colour
     * @return string HTML for header section
     */
    private static function buildHeader(string $title, string $bgColor): string
    {
        // 🔐 Sanitise color to prevent CSS injection
        $bgColor = self::sanitizeColor($bgColor);
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        return '<tr><td class="header" style="background:linear-gradient(135deg,' . $bgColor . ' 0%,' . $bgColor . 'dd 100%);padding:30px 40px;text-align:center;">' . "\n"
            . '<h1 style="color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;font-size:24px;margin:0;font-weight:700;">'
            . $escapedTitle . '</h1>' . "\n"
            . '</td></tr>' . "\n";
    }

    /**
     * 🦶 Build Default Footer
     *
     * @param string $appName    Application name
     * @param string $siteUrl    Site URL
     * @param string $year       Current year
     * @return string HTML footer content
     */
    private static function buildDefaultFooter(string $appName, string $siteUrl, string $year): string
    {
        return '<p>&copy; ' . htmlspecialchars($year, ENT_QUOTES, 'UTF-8')
            . ' ' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8')
            . '. All rights reserved.</p>' . "\n"
            . '<p><a href="' . htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') . '/support" style="color:#6b7280;">Contact Support</a>'
            . ' &middot; <a href="' . htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') . '/privacy" style="color:#6b7280;">Privacy Policy</a></p>';
    }

    /**
     * 🖥️ Get MSO Conditional Comment
     *
     * Outlook-specific VML and table rendering settings.
     *
     * @return string MSO conditional comment HTML
     * @see https://learn.microsoft.com/en-us/previous-versions/windows/desktop/ms536497(v=vs.85)
     */
    private static function getMSOConditionalComment(): string
    {
        return '<!--[if mso]>' . "\n"
            . '<noscript>' . "\n"
            . '<xml>' . "\n"
            . '<o:OfficeDocumentSettings>' . "\n"
            . '<o:PixelsPerInch>96</o:PixelsPerInch>' . "\n"
            . '</o:OfficeDocumentSettings>' . "\n"
            . '</xml>' . "\n"
            . '</noscript>' . "\n"
            . '<![endif]-->';
    }

    // ========================================================================
    // 📧 EMAIL HEADER UTILITIES
    // ========================================================================

    /**
     * 📋 Generate Email Headers for HTML/AMP
     *
     * Builds additional MIME headers for modern email features.
     *
     * @param bool   $includeAMP       Whether AMP part is included
     * @param string $category         Email category (transactional|marketing|notification)
     * @param bool   $includeUnsubscribe Include List-Unsubscribe headers
     * @return array Associative array of header name => value
     */
    public static function getEmailHeaders(
        bool $includeAMP = false,
        string $category = 'transactional',
        bool $includeUnsubscribe = false
    ): array {
        $headers = [];

        // 🏷️ #33: Do NOT advertise the mailer library or version in X-Mailer —
        //    that is information disclosure that helps an attacker fingerprint
        //    the stack and target known weaknesses. Emit a generic, version-less
        //    value (configurable, defaulting to the application name only).
        $xMailer = (string)getSetting('email.xmailer', getSetting('app.name', 'SIGNula'));
        if ($xMailer !== '') {
            $headers['X-Mailer'] = $xMailer;
        }

        // 📋 Category for ISP filtering
        if ($category === 'transactional') {
            $headers['X-PM-Message-Stream'] = 'outbound'; // Postmark compatible
            $headers['X-SES-MESSAGE-TAGS'] = 'type=transactional'; // SES compatible
        }

        // ⚡ AMP email indicator
        if ($includeAMP) {
            $headers['X-AMP-Email'] = 'true';
        }

        // 📧 Unsubscribe headers (RFC 2369, RFC 8058)
        if ($includeUnsubscribe) {
            $siteUrl = getSetting('site.url', 'https://SIGNula.id');
            $headers['List-Unsubscribe'] = '<' . $siteUrl . '/unsubscribe>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return $headers;
    }

    // ========================================================================
    // 🏗️ CONTENT HELPER BUILDERS
    // ========================================================================

    /**
     * 🔘 Build CTA Button HTML
     *
     * Generates a cross-client compatible call-to-action button.
     *
     * @param string $text   Button text
     * @param string $url    Button link URL
     * @param string $color  Button background colour (css value)
     * @param string $align  Alignment (center|left|right)
     * @return string HTML for the button
     */
    public static function buildButton(
        string $text,
        string $url,
        string $color = '#2563eb',
        string $align = 'center'
    ): string {
        // 🔐 Validate and sanitise parameters to prevent CSS/attribute injection
        $color = self::sanitizeColor($color);
        $validAligns = ['center', 'left', 'right'];
        $align = in_array($align, $validAligns, true) ? $align : 'center';

        $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td align="' . $align . '">'
            . '<a href="' . $escapedUrl . '" class="btn" style="display:inline-block;background-color:' . $color
            . ';color:#ffffff !important;padding:14px 32px;border-radius:8px;font-weight:600;font-size:16px;text-decoration:none;margin:20px 0;">'
            . $escapedText . '</a>'
            . '</td></tr></table>';
    }

    /**
     * ⚠️ Build Alert Box HTML
     *
     * @param string $title   Alert title
     * @param string $message Alert message
     * @param string $type    Alert type (danger|info|success|warning)
     * @return string HTML for the alert box
     */
    public static function buildAlertBox(string $title, string $message, string $type = 'info', bool $messageIsHtml = false): string
    {
        // 🔐 Validate $type against allowlist to prevent CSS injection
        $validTypes = ['danger', 'info', 'success', 'warning'];
        $type = in_array($type, $validTypes, true) ? $type : 'info';

        $colours = [
            'danger' => ['bg' => '#fef2f2', 'border' => '#fecaca', 'accent' => '#dc2626', 'title' => '#991b1b'],
            'info' => ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'accent' => '#2563eb', 'title' => '#1e40af'],
            'success' => ['bg' => '#f0fdf4', 'border' => '#bbf7d0', 'accent' => '#059669', 'title' => '#047857'],
            'warning' => ['bg' => '#fffbeb', 'border' => '#fde68a', 'accent' => '#d97706', 'title' => '#92400e'],
        ];

        $c = $colours[$type];

        // 🔐 Escape message unless caller explicitly passes pre-sanitised HTML
        $safeMessage = $messageIsHtml ? $message : htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return '<div class="alert-box alert-box-' . $type
            . '" style="background-color:' . $c['bg'] . ';border:1px solid ' . $c['border']
            . ';border-left:4px solid ' . $c['accent'] . ';border-radius:8px;padding:20px;margin:20px 0;">'
            . '<h3 style="color:' . $c['title'] . ';margin:0 0 10px 0;font-size:18px;">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>'
            . '<p style="margin:0;">' . $safeMessage . '</p>'
            . '</div>';
    }

    /**
     * 📊 Build Info Table HTML
     *
     * @param array $rows Associative array of label => value pairs
     * @return string HTML for the info table
     */
    public static function buildInfoTable(array $rows): string
    {
        $html = '<table class="info-table" role="presentation" style="width:100%;border-collapse:collapse;margin:20px 0;">';

        foreach ($rows as $label => $value) {
            $html .= '<tr>'
                . '<td style="padding:10px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;font-weight:600;color:#6b7280;width:140px;">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:10px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;">'
                . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }

        $html .= '</table>';
        return $html;
    }

    // ========================================================================
    // 🔐 SECURITY HELPERS
    // ========================================================================

    /**
     * 🔐 Sanitise a CSS colour value
     *
     * Validates that a colour string is safe for injection into CSS/HTML attributes.
     * Prevents CSS injection attacks via crafted colour values.
     *
     * Allowed formats:
     * - Hex: #RGB, #RRGGBB, #RRGGBBAA
     * - Named colours: red, blue, etc. (max 20 chars, letters only)
     * - rgb/rgba/hsl/hsla functions (basic validation)
     *
     * @param string $color The colour value to sanitise
     * @return string Sanitised colour value, or '#333333' if invalid
     */
    private static function sanitizeColor(string $color): string
    {
        $color = trim($color);

        // ✅ Hex colours (#RGB, #RRGGBB, #RRGGBBAA)
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            return $color;
        }

        // ✅ Named CSS colours (letters only, max 20 chars)
        if (preg_match('/^[a-zA-Z]{1,20}$/', $color)) {
            return $color;
        }

        // ✅ rgb/rgba/hsl/hsla functions (digits, commas, spaces, dots, percent only)
        if (preg_match('/^(rgb|rgba|hsl|hsla)\([0-9,.\s%]+\)$/', $color)) {
            return $color;
        }

        // 🔐 Default fallback for invalid/malicious values
        return '#333333';
    }

    /**
     * 🔐 Sanitise caller-supplied AMP custom CSS (#31)
     *
     * The string is embedded verbatim inside a <style amp-custom> block, so a
     * crafted value could otherwise:
     *   • close the style element early (`</style>`) and inject markup/script;
     *   • run JavaScript via legacy `expression()` or `javascript:` URLs;
     *   • pull in remote/active content via `@import`, `url(...)`, or IE
     *     `behavior:` / `-moz-binding:`.
     *
     * This strips those dangerous constructs while leaving ordinary CSS intact.
     * It is a defence-in-depth filter (AMP itself also rejects most of these);
     * the goal is to make breaking out of the style context impossible.
     *
     * @param string $css Raw CSS supplied by the caller
     * @return string CSS with dangerous constructs removed
     *
     * @see https://owasp.org/www-community/attacks/Cross_Site_Scripting_via_CSS
     */
    private static function sanitizeCustomCSS(string $css): string
    {
        if ($css === '') {
            return '';
        }

        // 🚫 Neutralise any attempt to close the <style> element (or open a new
        //    tag) and break out of the CSS context. Strip the angle brackets so
        //    the residue can never form a tag.
        $css = preg_replace('#</?\s*style[^>]*>#i', '', $css);
        $css = str_replace(['<', '>'], '', $css);

        // 🚫 Remove CSS comments — they are a common obfuscation vector
        //    (e.g. `expr/* */ession(...)`).
        $css = preg_replace('#/\*.*?\*/#s', '', $css);

        // 🚫 Strip known active-content constructs (case-insensitive).
        $dangerous = [
            '/expression\s*\(/i',   // IE expression()
            '/@import\b/i',          // remote stylesheet import
            '/javascript\s*:/i',     // javascript: URLs
            '/vbscript\s*:/i',       // vbscript: URLs
            '/behaviou?r\s*:/i',     // IE behavior / behaviour
            '/-moz-binding\s*:/i',   // legacy Firefox XBL binding
            '/url\s*\(/i',           // any url(...) (blocks remote fetch + data: payloads)
        ];
        $css = preg_replace($dangerous, '', $css);

        return trim((string)$css);
    }
}

// ✅ EmailTemplateBuilder loaded successfully
