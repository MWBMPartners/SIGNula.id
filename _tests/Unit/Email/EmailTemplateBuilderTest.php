<?php
/**
 * ============================================================================
 * 🧪 EmailTemplateBuilder Unit Tests
 * ============================================================================
 *
 * Tests for the EmailTemplateBuilder class covering:
 * - HTML to plain text conversion
 * - HTML email layout wrapping
 * - AMP email generation
 * - Button and alert box builders
 * - Info table generation
 * - Preheader text handling
 * - Dark mode CSS inclusion
 * - Email header generation
 * - Multipart email assembly
 *
 * @package    SIGNula\Tests\Unit\Email
 * @version    2.6.0-beta
 * @see        web/private_html/email/EmailTemplateBuilder.php
 */

namespace SIGNula\Tests\Unit\Email;

use SIGNula\Tests\TestCase;

// 📦 Load source class
requireSource('private_html/email/EmailTemplateBuilder.php');

/**
 * EmailTemplateBuilder Test Suite
 */
class EmailTemplateBuilderTest extends TestCase
{
    // ========================================================================
    // 📝 HTML TO PLAIN TEXT CONVERSION
    // ========================================================================

    /**
     * Test basic HTML to plain text strips tags
     */
    public function testHtmlToPlainTextStripsBasicTags(): void
    {
        $html = '<p>Hello <strong>World</strong></p>';
        $text = \EmailTemplateBuilder::htmlToPlainText($html);

        $this->assertStringContainsString('Hello', $text);
        $this->assertStringContainsString('World', $text);
        $this->assertStringNotContainsString('<p>', $text);
        $this->assertStringNotContainsString('<strong>', $text);
    }

    /**
     * Test HTML to plain text preserves links with URL
     */
    public function testHtmlToPlainTextPreservesLinks(): void
    {
        $html = '<a href="https://example.com">Click Here</a>';
        $text = \EmailTemplateBuilder::htmlToPlainText($html);

        $this->assertStringContainsString('Click Here', $text);
        $this->assertStringContainsString('https://example.com', $text);
    }

    /**
     * Test link conversion does not duplicate when text equals URL
     */
    public function testHtmlToPlainTextLinkNoDuplicateWhenTextIsUrl(): void
    {
        $html = '<a href="https://example.com">https://example.com</a>';
        $text = \EmailTemplateBuilder::htmlToPlainText($html);

        // 📝 Should appear exactly once, not as "https://example.com (https://example.com)"
        $this->assertEquals(1, substr_count($text, 'https://example.com'));
    }

    /**
     * Test HTML to plain text converts headers to uppercase
     */
    public function testHtmlToPlainTextConvertsHeaders(): void
    {
        $html = '<h1>Important Title</h1>';
        $text = \EmailTemplateBuilder::htmlToPlainText($html);

        $this->assertStringContainsString('IMPORTANT TITLE', $text);
    }

    /**
     * Test HTML to plain text converts list items
     */
    public function testHtmlToPlainTextConvertsList(): void
    {
        $html = '<ul><li>Item One</li><li>Item Two</li></ul>';
        $text = \EmailTemplateBuilder::htmlToPlainText($html);

        $this->assertStringContainsString('Item One', $text);
        $this->assertStringContainsString('Item Two', $text);
        $this->assertStringContainsString('-', $text);
    }

    /**
     * Test HTML to plain text handles line breaks
     */
    public function testHtmlToPlainTextHandlesLineBreaks(): void
    {
        $html = 'Line one<br>Line two<br/>Line three';
        $text = \EmailTemplateBuilder::htmlToPlainText($html);

        $this->assertStringContainsString("Line one\n", $text);
        $this->assertStringContainsString('Line two', $text);
        $this->assertStringContainsString('Line three', $text);
    }

    /**
     * Test HTML to plain text removes hidden preheader divs
     */
    public function testHtmlToPlainTextRemovesHiddenContent(): void
    {
        $html = '<div style="display:none;">Hidden preheader</div><p>Visible content</p>';
        $text = \EmailTemplateBuilder::htmlToPlainText($html);

        $this->assertStringNotContainsString('Hidden preheader', $text);
        $this->assertStringContainsString('Visible content', $text);
    }

    /**
     * Test HTML to plain text decodes entities
     */
    public function testHtmlToPlainTextDecodesEntities(): void
    {
        $html = '<p>Price: &pound;100 &amp; free shipping</p>';
        $text = \EmailTemplateBuilder::htmlToPlainText($html);

        $this->assertStringContainsString('£100', $text);
        $this->assertStringContainsString('& free shipping', $text);
    }

    /**
     * Test HTML to plain text handles empty input
     */
    public function testHtmlToPlainTextHandlesEmptyInput(): void
    {
        $text = \EmailTemplateBuilder::htmlToPlainText('');
        $this->assertEquals('', $text);
    }

    // ========================================================================
    // 🎨 HTML EMAIL LAYOUT WRAPPING
    // ========================================================================

    /**
     * Test wrapInLayout produces valid HTML document
     */
    public function testWrapInLayoutProducesValidHtml(): void
    {
        $html = \EmailTemplateBuilder::wrapInLayout(
            bodyContent: '<p>Hello World</p>',
            preheaderText: 'Preview text',
            headerTitle: 'Test Email'
        );

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('</html>', $html);
        $this->assertStringContainsString('Hello World', $html);
    }

    /**
     * Test layout includes meta viewport for responsiveness
     */
    public function testLayoutIncludesViewportMeta(): void
    {
        $html = \EmailTemplateBuilder::wrapInLayout(bodyContent: '<p>Test</p>');

        $this->assertStringContainsString('viewport', $html);
        $this->assertStringContainsString('width=device-width', $html);
    }

    /**
     * Test layout includes dark mode support
     */
    public function testLayoutIncludesDarkModeSupport(): void
    {
        $html = \EmailTemplateBuilder::wrapInLayout(bodyContent: '<p>Test</p>');

        $this->assertStringContainsString('prefers-color-scheme', $html);
        $this->assertStringContainsString('color-scheme', $html);
    }

    /**
     * Test layout includes MSO conditional comments for Outlook
     */
    public function testLayoutIncludesMSOConditionalComments(): void
    {
        $html = \EmailTemplateBuilder::wrapInLayout(bodyContent: '<p>Test</p>');

        $this->assertStringContainsString('<!--[if mso]>', $html);
        $this->assertStringContainsString('OfficeDocumentSettings', $html);
    }

    /**
     * Test layout includes preheader text when provided
     */
    public function testLayoutIncludesPreheaderText(): void
    {
        $html = \EmailTemplateBuilder::wrapInLayout(
            bodyContent: '<p>Test</p>',
            preheaderText: 'This is preview text'
        );

        $this->assertStringContainsString('This is preview text', $html);
        $this->assertStringContainsString('display:none', $html);
    }

    /**
     * Test layout uses custom header colour
     */
    public function testLayoutUsesCustomHeaderColour(): void
    {
        $html = \EmailTemplateBuilder::wrapInLayout(
            bodyContent: '<p>Test</p>',
            headerTitle: 'Custom Colour',
            headerColor: '#dc2626'
        );

        $this->assertStringContainsString('#dc2626', $html);
    }

    /**
     * Test layout includes footer with copyright
     */
    public function testLayoutIncludesFooter(): void
    {
        $html = \EmailTemplateBuilder::wrapInLayout(bodyContent: '<p>Test</p>');

        $this->assertStringContainsString('&copy;', $html);
        $this->assertStringContainsString(date('Y'), $html);
    }

    /**
     * Test layout includes responsive media queries
     */
    public function testLayoutIncludesResponsiveMediaQueries(): void
    {
        $html = \EmailTemplateBuilder::wrapInLayout(bodyContent: '<p>Test</p>');

        $this->assertStringContainsString('@media', $html);
        $this->assertStringContainsString('max-width: 620px', $html);
    }

    // ========================================================================
    // ⚡ AMP EMAIL TESTS
    // ========================================================================

    /**
     * Test AMP email generation includes required boilerplate
     */
    public function testAmpEmailIncludesRequiredBoilerplate(): void
    {
        $amp = \EmailTemplateBuilder::buildAMPEmail(
            bodyContent: '<p>AMP Content</p>'
        );

        // ⚡ Must include AMP4Email doctype
        $this->assertStringContainsString('⚡4email', $amp);
        $this->assertStringContainsString('amp4email-boilerplate', $amp);
        $this->assertStringContainsString('cdn.ampproject.org', $amp);
    }

    /**
     * Test AMP email includes component scripts when requested
     */
    public function testAmpEmailIncludesComponentScripts(): void
    {
        $amp = \EmailTemplateBuilder::buildAMPEmail(
            bodyContent: '<p>AMP Content</p>',
            ampComponents: ['amp-form', 'amp-bind']
        );

        $this->assertStringContainsString('amp-form', $amp);
        $this->assertStringContainsString('amp-bind', $amp);
        $this->assertStringContainsString('custom-element', $amp);
    }

    /**
     * Test AMP email includes custom CSS
     */
    public function testAmpEmailIncludesCustomCSS(): void
    {
        $amp = \EmailTemplateBuilder::buildAMPEmail(
            bodyContent: '<p>AMP Content</p>',
            customCSS: '.custom-class { color: red; }'
        );

        $this->assertStringContainsString('.custom-class { color: red; }', $amp);
        $this->assertStringContainsString('amp-custom', $amp);
    }

    /**
     * Test isAMPEnabled returns bool from settings
     */
    public function testIsAmpEnabledReturnsBool(): void
    {
        // 📝 Default setting should be '0' (disabled)
        $result = \EmailTemplateBuilder::isAMPEnabled();
        $this->assertIsBool($result);
    }

    // ========================================================================
    // 📋 MULTIPART EMAIL BUILDING
    // ========================================================================

    /**
     * Test buildMultipartEmail returns all required parts
     */
    public function testBuildMultipartEmailReturnsAllParts(): void
    {
        $result = \EmailTemplateBuilder::buildMultipartEmail(
            htmlBody: '<p>HTML content</p>',
            plainText: 'Plain text content',
            ampBody: '<p>AMP content</p>'
        );

        $this->assertArrayHasKey('bodyHTML', $result);
        $this->assertArrayHasKey('bodyText', $result);
        $this->assertArrayHasKey('bodyAMP', $result);
        $this->assertArrayHasKey('headers', $result);

        $this->assertEquals('<p>HTML content</p>', $result['bodyHTML']);
        $this->assertEquals('Plain text content', $result['bodyText']);
        $this->assertEquals('<p>AMP content</p>', $result['bodyAMP']);
    }

    /**
     * Test buildMultipartEmail auto-generates plain text from HTML
     */
    public function testBuildMultipartEmailAutoGeneratesPlainText(): void
    {
        $result = \EmailTemplateBuilder::buildMultipartEmail(
            htmlBody: '<p>Auto-generated <strong>plain text</strong></p>'
        );

        $this->assertNotEmpty($result['bodyText']);
        $this->assertStringContainsString('Auto-generated', $result['bodyText']);
        $this->assertStringContainsString('plain text', $result['bodyText']);
        $this->assertStringNotContainsString('<p>', $result['bodyText']);
    }

    /**
     * Test buildMultipartEmail includes unsubscribe header when enabled
     */
    public function testBuildMultipartEmailIncludesUnsubscribeHeader(): void
    {
        setTestSetting('email.unsubscribe.header_enabled', true);

        $result = \EmailTemplateBuilder::buildMultipartEmail(
            htmlBody: '<p>Test</p>'
        );

        $this->assertArrayHasKey('List-Unsubscribe', $result['headers']);
        $this->assertArrayHasKey('List-Unsubscribe-Post', $result['headers']);
    }

    // ========================================================================
    // 🏗️ CONTENT HELPER TESTS
    // ========================================================================

    /**
     * Test buildButton generates valid HTML with correct URL
     */
    public function testBuildButtonGeneratesValidHtml(): void
    {
        $button = \EmailTemplateBuilder::buildButton(
            text: 'Click Me',
            url: 'https://example.com/action'
        );

        $this->assertStringContainsString('Click Me', $button);
        $this->assertStringContainsString('https://example.com/action', $button);
        $this->assertStringContainsString('<a ', $button);
        $this->assertStringContainsString('href=', $button);
    }

    /**
     * Test buildButton uses custom colour
     */
    public function testBuildButtonUsesCustomColour(): void
    {
        $button = \EmailTemplateBuilder::buildButton(
            text: 'Danger',
            url: 'https://example.com',
            color: '#dc2626'
        );

        $this->assertStringContainsString('#dc2626', $button);
    }

    /**
     * Test buildButton escapes HTML in text and URL
     */
    public function testBuildButtonEscapesHtml(): void
    {
        $button = \EmailTemplateBuilder::buildButton(
            text: 'Click & Go',
            url: 'https://example.com?a=1&b=2'
        );

        $this->assertStringContainsString('Click &amp; Go', $button);
        $this->assertStringContainsString('a=1&amp;b=2', $button);
    }

    /**
     * Test buildAlertBox generates correct type styling
     */
    public function testBuildAlertBoxGeneratesCorrectTypes(): void
    {
        $types = ['danger', 'info', 'success', 'warning'];

        foreach ($types as $type) {
            $alert = \EmailTemplateBuilder::buildAlertBox(
                title: 'Alert Title',
                message: 'Alert message',
                type: $type
            );

            $this->assertStringContainsString('Alert Title', $alert);
            $this->assertStringContainsString('Alert message', $alert);
            $this->assertStringContainsString("alert-box-{$type}", $alert);
        }
    }

    /**
     * Test buildAlertBox escapes title HTML
     */
    public function testBuildAlertBoxEscapesTitle(): void
    {
        $alert = \EmailTemplateBuilder::buildAlertBox(
            title: 'Title with <script>',
            message: 'Safe message',
            type: 'danger'
        );

        $this->assertStringNotContainsString('<script>', $alert);
        $this->assertStringContainsString('&lt;script&gt;', $alert);
    }

    /**
     * Test buildInfoTable generates table with correct data
     */
    public function testBuildInfoTableGeneratesCorrectData(): void
    {
        $table = \EmailTemplateBuilder::buildInfoTable([
            'Name' => 'John Doe',
            'Email' => 'john@example.com',
            'Status' => 'Active',
        ]);

        $this->assertStringContainsString('Name', $table);
        $this->assertStringContainsString('John Doe', $table);
        $this->assertStringContainsString('Email', $table);
        $this->assertStringContainsString('john@example.com', $table);
        $this->assertStringContainsString('<table', $table);
        $this->assertStringContainsString('</table>', $table);
    }

    /**
     * Test buildInfoTable escapes HTML in values
     */
    public function testBuildInfoTableEscapesValues(): void
    {
        $table = \EmailTemplateBuilder::buildInfoTable([
            'HTML' => '<b>Bold</b>',
        ]);

        $this->assertStringNotContainsString('<b>Bold</b>', $table);
        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $table);
    }

    // ========================================================================
    // 📧 EMAIL HEADER TESTS
    // ========================================================================

    /**
     * Test getEmailHeaders includes X-Mailer
     */
    public function testGetEmailHeadersIncludesXMailer(): void
    {
        $headers = \EmailTemplateBuilder::getEmailHeaders();

        $this->assertArrayHasKey('X-Mailer', $headers);
        $this->assertStringContainsString('Email Service', $headers['X-Mailer']);
    }

    /**
     * Test getEmailHeaders includes AMP indicator when requested
     */
    public function testGetEmailHeadersIncludesAmpIndicator(): void
    {
        $headers = \EmailTemplateBuilder::getEmailHeaders(includeAMP: true);

        $this->assertArrayHasKey('X-AMP-Email', $headers);
        $this->assertEquals('true', $headers['X-AMP-Email']);
    }

    /**
     * Test getEmailHeaders includes unsubscribe when requested
     */
    public function testGetEmailHeadersIncludesUnsubscribe(): void
    {
        $headers = \EmailTemplateBuilder::getEmailHeaders(includeUnsubscribe: true);

        $this->assertArrayHasKey('List-Unsubscribe', $headers);
        $this->assertArrayHasKey('List-Unsubscribe-Post', $headers);
        $this->assertStringContainsString('unsubscribe', $headers['List-Unsubscribe']);
    }

    /**
     * Test getEmailHeaders sets transactional stream headers
     */
    public function testGetEmailHeadersSetsTransactionalHeaders(): void
    {
        $headers = \EmailTemplateBuilder::getEmailHeaders(category: 'transactional');

        $this->assertArrayHasKey('X-PM-Message-Stream', $headers);
        $this->assertEquals('outbound', $headers['X-PM-Message-Stream']);
    }

    // ========================================================================
    // 📋 STYLE CONSTANTS
    // ========================================================================

    /**
     * Test style constants are defined
     */
    public function testStyleConstantsAreDefined(): void
    {
        $this->assertEquals('modern', \EmailTemplateBuilder::STYLE_MODERN);
        $this->assertEquals('minimal', \EmailTemplateBuilder::STYLE_MINIMAL);
        $this->assertEquals('branded', \EmailTemplateBuilder::STYLE_BRANDED);
    }
}
