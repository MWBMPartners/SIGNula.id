<?php
/**
 * ============================================================================
 * 🛡️ SIGNula — Local Form Protection (Honeypot, Timing, JS Challenge)
 * ============================================================================
 *
 * Provides three always-on, local-only bot protection layers that work
 * independently of external CAPTCHA APIs:
 *
 * 1. **Honeypot Field** — hidden field that only bots fill in
 * 2. **Timing Validation** — rejects forms submitted faster than a threshold
 * 3. **JavaScript Challenge** — hidden field computed by JS on page load
 *
 * These protections are designed to have zero false positives for real users
 * and zero external dependencies. They complement (not replace) CAPTCHA.
 *
 * @package    SIGNula\Security
 * @version    2.6.0-beta
 * @see        https://owasp.org/www-community/controls/Blocking_Brute_Force_Attacks
 * @see        https://cheatsheetseries.owasp.org/cheatsheets/Bot_Prevention_Cheat_Sheet.html
 *
 * Copyright (c) 2024-2026 MWBM Partners Ltd. All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

/**
 * FormProtection — Local bot protection for form submissions
 *
 * All methods are static, consistent with the SIGNula security class pattern.
 * This class does NOT require a database connection or external API access.
 */
class FormProtection
{
    // ========================================================================
    // 📋 CONSTANTS
    // ========================================================================

    /**
     * 🍯 Honeypot field name — intentionally looks like a real field to bait bots
     * @see https://en.wikipedia.org/wiki/Honeypot_(computing)
     */
    private const HONEYPOT_FIELD_NAME = 'website_url';

    /**
     * ⏱️ Timing field names
     */
    private const TIMING_FIELD_NAME = '_form_ts';
    private const TIMING_HMAC_FIELD_NAME = '_form_sig';

    /**
     * 🔧 JavaScript challenge field name and expected prefix
     */
    private const JS_CHALLENGE_FIELD_NAME = '_js_check';
    private const JS_CHALLENGE_PREFIX = 'ok_';

    /**
     * ⏱️ Default minimum submit time in seconds
     */
    private const DEFAULT_MIN_SUBMIT_TIME = 3;

    // ========================================================================
    // ✅ PUBLIC METHODS
    // ========================================================================

    /**
     * ✅ Check if form protection is enabled
     *
     * @return bool True if the feature is enabled in settings
     */
    public static function isEnabled(): bool
    {
        if (function_exists('getSetting')) {
            return (bool) getSetting('security.form_protection.enabled', true);
        }

        return true;
    }

    /**
     * 🍯 Render the honeypot hidden field
     *
     * Outputs a form field that is invisible to real users (hidden via CSS)
     * but visible to bots that parse the raw HTML and auto-fill all fields.
     * If a bot fills this field, the submission will be rejected.
     *
     * Accessibility:
     * - `aria-hidden="true"` hides from screen readers
     * - `tabindex="-1"` prevents keyboard navigation to the field
     * - `autocomplete="off"` prevents browser autofill
     *
     * @return string HTML for the honeypot field, or empty string if disabled
     */
    public static function renderHoneypot(): string
    {
        if (!self::isEnabled()) {
            return '';
        }

        // 🎨 CSS hides the field visually; bots that don't render CSS will see it
        $html = '<div style="position:absolute;left:-9999px;opacity:0;height:0;width:0;overflow:hidden;" aria-hidden="true">' . PHP_EOL;
        $html .= '    <label for="' . self::HONEYPOT_FIELD_NAME . '">Leave this field blank</label>' . PHP_EOL;
        $html .= '    <input'
            . ' type="text"'
            . ' name="' . self::HONEYPOT_FIELD_NAME . '"'
            . ' id="' . self::HONEYPOT_FIELD_NAME . '"'
            . ' value=""'
            . ' tabindex="-1"'
            . ' autocomplete="off"'
            . '>' . PHP_EOL;
        $html .= '</div>' . PHP_EOL;

        return $html;
    }

    /**
     * ⏱️ Render the timing validation hidden field
     *
     * Outputs a hidden field containing the current Unix timestamp, plus
     * an HMAC signature to prevent bots from forging the timestamp.
     * The HMAC uses the CSRF token as the key (already in the session).
     *
     * @return string HTML for the timing hidden fields, or empty string if disabled
     */
    public static function renderTimingField(): string
    {
        if (!self::isEnabled()) {
            return '';
        }

        $timestamp = (string) time();
        $hmacKey = self::getHMACKey();
        $signature = hash_hmac('sha256', $timestamp, $hmacKey);

        $html = '<input type="hidden" name="' . self::TIMING_FIELD_NAME . '" value="' . htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
        $html .= '<input type="hidden" name="' . self::TIMING_HMAC_FIELD_NAME . '" value="' . htmlspecialchars($signature, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;

        return $html;
    }

    /**
     * 🔧 Render the JavaScript challenge hidden field
     *
     * Outputs a hidden field that JavaScript populates with a computed value
     * on DOMContentLoaded. Bots that don't execute JavaScript will leave the
     * field empty. Non-JS browsers are handled gracefully (check is skipped).
     *
     * @return string HTML for the JS challenge field + script, or empty string if disabled
     */
    public static function renderJSChallenge(): string
    {
        if (!self::isEnabled()) {
            return '';
        }

        // 🔧 The challenge value is a simple prefix + timestamp hash
        // This proves JS executed in the browser context
        $html = '<input type="hidden" name="' . self::JS_CHALLENGE_FIELD_NAME . '" id="' . self::JS_CHALLENGE_FIELD_NAME . '" value="">' . PHP_EOL;
        $html .= '<script>' . PHP_EOL;
        $html .= '(function(){' . PHP_EOL;
        $html .= '  document.addEventListener("DOMContentLoaded",function(){' . PHP_EOL;
        $html .= '    var f=document.getElementById("' . self::JS_CHALLENGE_FIELD_NAME . '");' . PHP_EOL;
        $html .= '    if(f){f.value="' . self::JS_CHALLENGE_PREFIX . '"+Date.now();}' . PHP_EOL;
        $html .= '  });' . PHP_EOL;
        $html .= '})();' . PHP_EOL;
        $html .= '</script>' . PHP_EOL;

        return $html;
    }

    /**
     * 🔒 Render all protection fields combined
     *
     * Convenience method that outputs honeypot + timing + JS challenge.
     * Call this inside each `<form>` tag.
     *
     * @return string Combined HTML for all protection fields
     */
    public static function renderAllFields(): string
    {
        if (!self::isEnabled()) {
            return '';
        }

        $html = '<!-- 🛡️ Local Form Protection -->' . PHP_EOL;
        $html .= self::renderHoneypot();
        $html .= self::renderTimingField();
        $html .= self::renderJSChallenge();

        return $html;
    }

    /**
     * 🔍 Validate all form protection checks
     *
     * Checks honeypot, timing, and JS challenge. Returns a result array
     * indicating whether the submission passed all checks.
     *
     * @return array{passed: bool, reason: ?string} Validation result
     */
    public static function validate(): array
    {
        if (!self::isEnabled()) {
            return [
                'passed' => true,
                'reason' => null,
            ];
        }

        // 🍯 Check 1: Honeypot must be empty
        if (!self::validateHoneypot()) {
            return [
                'passed' => false,
                'reason' => 'honeypot',
            ];
        }

        // ⏱️ Check 2: Timing must be above threshold
        if (!self::validateTiming()) {
            return [
                'passed' => false,
                'reason' => 'timing',
            ];
        }

        // 🔧 Check 3: JavaScript challenge (graceful — only fails if field has wrong value)
        if (!self::validateJSChallenge()) {
            return [
                'passed' => false,
                'reason' => 'js_challenge',
            ];
        }

        return [
            'passed' => true,
            'reason' => null,
        ];
    }

    // ========================================================================
    // 🔒 PRIVATE METHODS
    // ========================================================================

    /**
     * 🍯 Validate the honeypot field is empty
     *
     * @return bool True if honeypot is empty (human), false if filled (bot)
     */
    private static function validateHoneypot(): bool
    {
        $honeypotValue = $_POST[self::HONEYPOT_FIELD_NAME] ?? '';

        // 📝 If the field has ANY value, it was filled by a bot
        return empty($honeypotValue);
    }

    /**
     * ⏱️ Validate the form submission timing
     *
     * Checks that:
     * 1. The timestamp field exists and is a valid integer
     * 2. The HMAC signature matches (prevents timestamp forging)
     * 3. The elapsed time since form render is >= the minimum threshold
     *
     * @return bool True if timing is valid, false if too fast or tampered
     */
    private static function validateTiming(): bool
    {
        $timestamp = $_POST[self::TIMING_FIELD_NAME] ?? '';
        $signature = $_POST[self::TIMING_HMAC_FIELD_NAME] ?? '';

        // 📝 If timing fields are missing, skip check (form may have been
        // rendered before FormProtection was integrated)
        if (empty($timestamp) || empty($signature)) {
            return true;
        }

        // 🔍 Verify the timestamp is a valid integer
        if (!ctype_digit($timestamp)) {
            return false;
        }

        // 🔒 Verify HMAC signature (timing-safe comparison)
        $hmacKey = self::getHMACKey();
        $expectedSignature = hash_hmac('sha256', $timestamp, $hmacKey);

        if (!hash_equals($expectedSignature, $signature)) {
            // ⚠️ HMAC mismatch — timestamp was tampered with
            return false;
        }

        // ⏱️ Check elapsed time
        $elapsed = time() - (int) $timestamp;
        $minTime = self::getMinSubmitTime();

        return ($elapsed >= $minTime);
    }

    /**
     * 🔧 Validate the JavaScript challenge field
     *
     * This check is intentionally lenient:
     * - If the field is MISSING or EMPTY → pass (non-JS browser, graceful degradation)
     * - If the field has a value but it doesn't match the expected pattern → fail
     *
     * @return bool True if JS challenge passed or was skipped
     */
    private static function validateJSChallenge(): bool
    {
        $challengeValue = $_POST[self::JS_CHALLENGE_FIELD_NAME] ?? '';

        // 📝 Empty = JS didn't execute or field not present → graceful pass
        if (empty($challengeValue)) {
            return true;
        }

        // 🔍 If a value IS present, it must start with the expected prefix
        // This catches bots that fill the field with garbage
        return (strpos($challengeValue, self::JS_CHALLENGE_PREFIX) === 0);
    }

    /**
     * 🔑 Get the HMAC key for timing field signatures
     *
     * Uses the CSRF token from the session as the HMAC key.
     * Falls back to a session-derived key if no CSRF token exists.
     *
     * @return string HMAC key
     */
    private static function getHMACKey(): string
    {
        // 📋 Use the CSRF token if available (it's already a cryptographic random value)
        if (isset($_SESSION['csrf_token']) && !empty($_SESSION['csrf_token'])) {
            return $_SESSION['csrf_token'];
        }

        // 🔄 Fallback: use session ID as HMAC key (always available after session_start)
        if (session_status() === PHP_SESSION_ACTIVE) {
            return session_id();
        }

        // ⚠️ Last resort: use a constant derived from server config
        // This is less secure but ensures timing validation still works
        return hash('sha256', (__DIR__) . ($_SERVER['SERVER_NAME'] ?? 'signula'));
    }

    /**
     * ⏱️ Get the minimum submit time threshold (in seconds)
     *
     * @return int Minimum seconds between form render and submit
     */
    private static function getMinSubmitTime(): int
    {
        if (function_exists('getSetting')) {
            return (int) getSetting('security.form_protection.min_submit_time', self::DEFAULT_MIN_SUBMIT_TIME);
        }

        return self::DEFAULT_MIN_SUBMIT_TIME;
    }
}

// ✅ FormProtection loaded successfully
