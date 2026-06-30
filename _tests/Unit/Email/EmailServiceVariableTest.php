<?php
/**
 * ============================================================================
 * 🧪 EmailService Variable-Replacement Characterization Tests
 * ============================================================================
 * (STABILIZE-2 safety net)
 *
 * Characterizes EmailService::replaceVariables() — the template-variable
 * substitution used by every templated email. This is the anchor for the C2
 * XSS fix: HTML bodies must be escaped, plain-text/subject bodies must not be.
 * Pinning this protects the fix when the OAuth/JWT builds add new emails
 * (link-confirmation, security alerts) that flow through the same substitution.
 *
 * replaceVariables() is private and pure, so it is exercised via reflection
 * (no database required). The DB-coupled paths — queueEmail() recipient
 * handling (B-012) and sendTemplateEmail() dispatch — are pinned in the
 * companion Integration test (Integration/Email/EmailServiceQueueTest.php).
 *
 * @package    SIGNula\Tests\Unit\Email
 * @version    2.7.0-beta
 * @see        web/private_html/email/EmailService.php
 * @see        https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Scripting_Prevention_Cheat_Sheet.html
 */

namespace SIGNula\Tests\Unit\Email;

use SIGNula\Tests\TestCase;
use ReflectionMethod;

// 📦 Load source class. EmailService references Database/ErrorLogger only at
// call time inside other methods, so loading it alone is safe.
requireSource('private_html/email/EmailService.php');

/**
 * EmailService::replaceVariables() Characterization Test Suite
 */
class EmailServiceVariableTest extends TestCase
{
    /**
     * Invoke the private static replaceVariables().
     *
     * @param string $template Template with {{placeholders}}
     * @param array  $vars     name => value map
     * @param bool   $isHtml   Whether to HTML-escape values
     * @return string Rendered template
     */
    private function replace(string $template, array $vars, bool $isHtml): string
    {
        $ref = new ReflectionMethod(\EmailService::class, 'replaceVariables');
        $ref->setAccessible(true);
        return $ref->invoke(null, $template, $vars, $isHtml);
    }

    // ========================================================================
    // 🔄 BASIC SUBSTITUTION
    // ========================================================================

    /**
     * Placeholders are replaced by their values.
     */
    public function testReplacesPlaceholders(): void
    {
        $out = $this->replace('Hello {{name}}, welcome to {{app}}.', [
            'name' => 'Jane',
            'app'  => 'SIGNula',
        ], false);

        $this->assertSame('Hello Jane, welcome to SIGNula.', $out);
    }

    /**
     * Unknown placeholders are left untouched (no value provided).
     */
    public function testLeavesUnknownPlaceholdersIntact(): void
    {
        $out = $this->replace('Hi {{name}}, code {{code}}', ['name' => 'Sam'], false);

        $this->assertSame('Hi Sam, code {{code}}', $out);
    }

    /**
     * Non-string values are coerced to string before substitution.
     */
    public function testCoercesNonStringValues(): void
    {
        $out = $this->replace('Count: {{n}} / Flag: {{f}}', [
            'n' => 42,
            'f' => true,
        ], false);

        // (string) 42 = "42"; (string) true = "1".
        $this->assertSame('Count: 42 / Flag: 1', $out);
    }

    // ========================================================================
    // 🔐 HTML ESCAPING (C2 anchor)
    // ========================================================================

    /**
     * In HTML context, values are escaped with htmlspecialchars(ENT_QUOTES).
     *
     * This is the core C2 protection: an attacker-controlled display name or
     * email cannot inject markup into an HTML email body.
     */
    public function testHtmlContextEscapesDangerousValues(): void
    {
        $out = $this->replace('<p>Hi {{name}}</p>', [
            'name' => '<script>alert(1)</script>',
        ], true);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
    }

    /**
     * ENT_QUOTES escapes both single and double quotes in HTML context.
     */
    public function testHtmlContextEscapesQuotes(): void
    {
        $out = $this->replace('<a title="{{t}}">x</a>', [
            't' => 'a"b\'c',
        ], true);

        $this->assertStringContainsString('&quot;', $out);
        $this->assertStringContainsString('&#039;', $out);
        $this->assertStringNotContainsString('"b', $out);
    }

    /**
     * Ampersands are escaped in HTML context.
     */
    public function testHtmlContextEscapesAmpersand(): void
    {
        $out = $this->replace('{{v}}', ['v' => 'Tom & Jerry'], true);

        $this->assertSame('Tom &amp; Jerry', $out);
    }

    // ========================================================================
    // 📄 PLAIN-TEXT CONTEXT (no escaping — by design)
    // ========================================================================

    /**
     * In non-HTML context, values are inserted raw (subject + text bodies).
     *
     * This pins the deliberate asymmetry: text/subject must NOT be escaped, or
     * users would see literal &lt; entities in plain-text mail.
     */
    public function testPlainTextContextDoesNotEscape(): void
    {
        $raw = '<not-really-a-tag> & "quotes"';
        $out = $this->replace('{{v}}', ['v' => $raw], false);

        $this->assertSame($raw, $out, 'Plain-text context must leave values unescaped');
    }

    /**
     * The same payload is escaped in HTML but raw in text — proving the branch.
     */
    public function testHtmlVsPlainTextDiverge(): void
    {
        $vars = ['v' => '<b>x</b>'];

        $html = $this->replace('{{v}}', $vars, true);
        $text = $this->replace('{{v}}', $vars, false);

        $this->assertNotSame($html, $text);
        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertSame('<b>x</b>', $text);
    }
}
