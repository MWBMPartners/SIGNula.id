<?php
/**
 * ============================================================================
 * 🛡️ SIGNula - CAPTCHA Verifier
 * ============================================================================
 *
 * Purpose: CAPTCHA verification with CloudFlare Turnstile (primary) and
 *          Google reCAPTCHA v3 (fallback) support
 * PHP Version: 8.3+
 *
 * Provides server-side verification of CAPTCHA tokens and client-side widget
 * rendering. Supports automatic provider fallback and fail-open behaviour
 * when APIs are unreachable (legitimate users are never blocked by CAPTCHA
 * failures).
 *
 * Supported Providers:
 *   - CloudFlare Turnstile (recommended, completely free, no request limits)
 *   - Google reCAPTCHA v3 (free up to 10K assessments/month, score-based)
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.6.0-beta
 *
 * @see https://developers.cloudflare.com/turnstile/
 * @see https://developers.google.com/recaptcha/docs/v3
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
 * 🛡️ CAPTCHA Verifier
 *
 * Handles CAPTCHA verification for form submissions with automatic provider
 * fallback and graceful degradation. All methods are static following the
 * SIGNula security class pattern.
 */
class CaptchaVerifier
{
    // ========================================================================
    // 🔗 API Endpoints
    // ========================================================================

    /** @var string CloudFlare Turnstile server-side verification endpoint */
    private const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @var string Google reCAPTCHA v3 server-side verification endpoint */
    private const RECAPTCHA_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    // ========================================================================
    // ⚙️ Configuration Constants
    // ========================================================================

    /** @var int cURL timeout for API verification requests (seconds) */
    private const TIMEOUT_SECONDS = 5;

    /** @var float Default minimum reCAPTCHA v3 score (0.0 = bot, 1.0 = human) */
    private const DEFAULT_MIN_RECAPTCHA_SCORE = 0.5;

    // ========================================================================
    // 🔓 Public Methods
    // ========================================================================

    /**
     * 🔍 Check if CAPTCHA verification is required
     *
     * Returns true only if CAPTCHA is enabled AND at least one provider
     * has valid keys configured. This ensures graceful degradation when
     * no keys are set up.
     *
     * @param string|null $formName Optional form name to check against required_forms list
     * @return bool True if CAPTCHA should be shown and verified
     */
    public static function isRequired(?string $formName = null): bool
    {
        // 📋 Check global toggle
        $enabled = (bool) getSetting('captcha.enabled', false);

        if (!$enabled) {
            return false;
        }

        // 📋 If form name provided, check if it's in the required forms list
        if ($formName !== null) {
            $requiredForms = json_decode(
                getSetting('captcha.required_forms', '["login","register","forgot-password","contact"]'),
                true
            );

            if (is_array($requiredForms) && !in_array($formName, $requiredForms, true)) {
                return false;
            }
        }

        // 🔑 Check if at least one provider has keys configured
        $provider = self::getProvider();

        return ($provider !== 'none');
    }

    /**
     * 🏷️ Get the active CAPTCHA provider
     *
     * Determines which provider to use based on configuration and key
     * availability. Falls back automatically: Turnstile → reCAPTCHA → none.
     *
     * @return string Provider name: 'turnstile', 'recaptcha', or 'none'
     */
    public static function getProvider(): string
    {
        $preferred = getSetting('captcha.provider', 'turnstile');

        // 🔑 Check if preferred provider has keys
        if ($preferred === 'turnstile') {
            $siteKey = getSetting('captcha.turnstile.site_key', '');
            $secretKey = getSetting('captcha.turnstile.secret_key', '');

            if (!empty($siteKey) && !empty($secretKey)) {
                return 'turnstile';
            }

            // 🔄 Turnstile keys missing — try reCAPTCHA as fallback
            $recaptchaSiteKey = getSetting('captcha.recaptcha.site_key', '');
            $recaptchaSecretKey = getSetting('captcha.recaptcha.secret_key', '');

            if (!empty($recaptchaSiteKey) && !empty($recaptchaSecretKey)) {
                return 'recaptcha';
            }

            return 'none';
        }

        if ($preferred === 'recaptcha') {
            $siteKey = getSetting('captcha.recaptcha.site_key', '');
            $secretKey = getSetting('captcha.recaptcha.secret_key', '');

            if (!empty($siteKey) && !empty($secretKey)) {
                return 'recaptcha';
            }

            // 🔄 reCAPTCHA keys missing — try Turnstile as fallback
            $turnstileSiteKey = getSetting('captcha.turnstile.site_key', '');
            $turnstileSecretKey = getSetting('captcha.turnstile.secret_key', '');

            if (!empty($turnstileSiteKey) && !empty($turnstileSecretKey)) {
                return 'turnstile';
            }

            return 'none';
        }

        return 'none';
    }

    /**
     * ✅ Verify a CAPTCHA token from a form submission
     *
     * Verifies the token with the active provider's API. Supports automatic
     * fallback and fail-open behaviour:
     * - If CAPTCHA is disabled or no keys configured → returns success
     * - If API is unreachable → returns success (fail-open)
     * - If token is invalid → returns failure
     *
     * @param string|null $token CAPTCHA token. If null, reads from POST data automatically.
     * @return array{success: bool, provider: string, score: ?float, error: ?string}
     */
    public static function verify(?string $token = null): array
    {
        // 📋 If CAPTCHA is not required, always return success
        if (!self::isRequired()) {
            return [
                'success'  => true,
                'provider' => 'none',
                'score'    => null,
                'error'    => null,
            ];
        }

        $provider = self::getProvider();

        // 🔍 Auto-detect token from POST data if not provided
        if ($token === null) {
            if ($provider === 'turnstile') {
                $token = $_POST['cf-turnstile-response'] ?? '';
            } elseif ($provider === 'recaptcha') {
                $token = $_POST['g-recaptcha-response'] ?? '';
            }
        }

        // ❌ No token provided
        if (empty($token)) {
            return [
                'success'  => false,
                'provider' => $provider,
                'score'    => null,
                'error'    => 'CAPTCHA verification required. Please complete the challenge.',
            ];
        }

        // 🔒 Verify with the active provider
        if ($provider === 'turnstile') {
            $result = self::verifyTurnstile($token);

            // 🔄 If Turnstile fails due to API error (not invalid token), try reCAPTCHA
            if (!$result['success'] && $result['error'] === 'api_error') {
                $recaptchaToken = $_POST['g-recaptcha-response'] ?? '';
                if (!empty($recaptchaToken)) {
                    $result = self::verifyRecaptcha($recaptchaToken);
                }
            }

            return $result;
        }

        if ($provider === 'recaptcha') {
            return self::verifyRecaptcha($token);
        }

        // 🔓 No valid provider — fail open
        return [
            'success'  => true,
            'provider' => 'none',
            'score'    => null,
            'error'    => null,
        ];
    }

    /**
     * 🎨 Render the CAPTCHA widget HTML for a form
     *
     * Returns the HTML snippet to embed in a form. The widget type depends
     * on the active provider:
     * - Turnstile: visible challenge widget
     * - reCAPTCHA v3: hidden (score-based, no visible challenge)
     *
     * @param string $formId HTML form ID (used for reCAPTCHA v3 action binding)
     * @return string HTML snippet, or empty string if CAPTCHA is disabled
     */
    public static function renderWidget(string $formId = ''): string
    {
        if (!self::isRequired()) {
            return '';
        }

        $provider = self::getProvider();

        if ($provider === 'turnstile') {
            $siteKey = htmlspecialchars(getSetting('captcha.turnstile.site_key', ''), ENT_QUOTES, 'UTF-8');

            return '<div class="cf-turnstile mb-3" data-sitekey="' . $siteKey . '" '
                . 'data-theme="auto" data-language="auto"></div>' . PHP_EOL;
        }

        if ($provider === 'recaptcha') {
            $siteKey = htmlspecialchars(getSetting('captcha.recaptcha.site_key', ''), ENT_QUOTES, 'UTF-8');
            $action = !empty($formId) ? htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') : 'submit';

            // 🔵 reCAPTCHA v3 is invisible — inject hidden input + JS to populate it on submit
            $html = '<input type="hidden" name="g-recaptcha-response" id="recaptchaResponse">' . PHP_EOL;
            $html .= '<script>' . PHP_EOL;
            $html .= '  document.addEventListener("DOMContentLoaded", function() {' . PHP_EOL;
            $html .= '    var form = document.getElementById("' . $action . '");' . PHP_EOL;
            $html .= '    if (form) {' . PHP_EOL;
            $html .= '      form.addEventListener("submit", function(e) {' . PHP_EOL;
            $html .= '        e.preventDefault();' . PHP_EOL;
            $html .= '        grecaptcha.ready(function() {' . PHP_EOL;
            $html .= '          grecaptcha.execute("' . $siteKey . '", {action: "' . $action . '"}).then(function(token) {' . PHP_EOL;
            $html .= '            document.getElementById("recaptchaResponse").value = token;' . PHP_EOL;
            $html .= '            form.submit();' . PHP_EOL;
            $html .= '          });' . PHP_EOL;
            $html .= '        });' . PHP_EOL;
            $html .= '      });' . PHP_EOL;
            $html .= '    }' . PHP_EOL;
            $html .= '  });' . PHP_EOL;
            $html .= '</script>' . PHP_EOL;

            return $html;
        }

        return '';
    }

    /**
     * 📜 Get required script tags for the active CAPTCHA provider
     *
     * Returns `<script>` tags to include in the `<head>` section of pages
     * that use CAPTCHA. Must be called before renderWidget().
     *
     * @return string Script tag(s), or empty string if CAPTCHA is disabled
     */
    public static function getRequiredScripts(): string
    {
        if (!self::isRequired()) {
            return '';
        }

        $provider = self::getProvider();

        if ($provider === 'turnstile') {
            // ☁️ CloudFlare Turnstile API script
            // @see https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/
            return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>' . PHP_EOL;
        }

        if ($provider === 'recaptcha') {
            // 🔵 Google reCAPTCHA v3 API script
            // @see https://developers.google.com/recaptcha/docs/v3#programmatically_invoke_the_challenge
            $siteKey = htmlspecialchars(getSetting('captcha.recaptcha.site_key', ''), ENT_QUOTES, 'UTF-8');
            return '<script src="https://www.google.com/recaptcha/api.js?render=' . $siteKey . '"></script>' . PHP_EOL;
        }

        return '';
    }

    // ========================================================================
    // 🔒 Private Methods
    // ========================================================================

    /**
     * ☁️ Verify a token with CloudFlare Turnstile
     *
     * @see https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
     *
     * @param string $token The cf-turnstile-response token from the form
     * @return array{success: bool, provider: string, score: ?float, error: ?string}
     */
    private static function verifyTurnstile(string $token): array
    {
        $secretKey = getSetting('captcha.turnstile.secret_key', '');

        if (empty($secretKey)) {
            // 🔓 No secret key — fail open
            return [
                'success'  => true,
                'provider' => 'turnstile',
                'score'    => null,
                'error'    => null,
            ];
        }

        // 📡 POST to Turnstile verification endpoint
        $postData = [
            'secret'   => $secretKey,
            'response' => $token,
        ];

        // 🌐 Include client IP if available for additional verification
        if (function_exists('getClientIP')) {
            $postData['remoteip'] = getClientIP();
        }

        $response = self::makeCurlRequest(self::TURNSTILE_VERIFY_URL, $postData);

        // ⚠️ API unreachable — fail open
        if ($response === null) {
            return [
                'success'  => true,
                'provider' => 'turnstile',
                'score'    => null,
                'error'    => 'api_error',
            ];
        }

        // ✅ Check verification result
        if (isset($response['success']) && $response['success'] === true) {
            return [
                'success'  => true,
                'provider' => 'turnstile',
                'score'    => null,
                'error'    => null,
            ];
        }

        // ❌ Verification failed
        $errorCodes = $response['error-codes'] ?? [];

        return [
            'success'  => false,
            'provider' => 'turnstile',
            'score'    => null,
            'error'    => 'CAPTCHA verification failed. Please try again. (' . implode(', ', $errorCodes) . ')',
        ];
    }

    /**
     * 🔵 Verify a token with Google reCAPTCHA v3
     *
     * @see https://developers.google.com/recaptcha/docs/verify
     *
     * @param string $token The g-recaptcha-response token from the form
     * @return array{success: bool, provider: string, score: ?float, error: ?string}
     */
    private static function verifyRecaptcha(string $token): array
    {
        $secretKey = getSetting('captcha.recaptcha.secret_key', '');

        if (empty($secretKey)) {
            // 🔓 No secret key — fail open
            return [
                'success'  => true,
                'provider' => 'recaptcha',
                'score'    => null,
                'error'    => null,
            ];
        }

        // 📡 POST to reCAPTCHA verification endpoint
        $postData = [
            'secret'   => $secretKey,
            'response' => $token,
        ];

        // 🌐 Include client IP if available
        if (function_exists('getClientIP')) {
            $postData['remoteip'] = getClientIP();
        }

        $response = self::makeCurlRequest(self::RECAPTCHA_VERIFY_URL, $postData);

        // ⚠️ API unreachable — fail open
        if ($response === null) {
            return [
                'success'  => true,
                'provider' => 'recaptcha',
                'score'    => null,
                'error'    => 'api_error',
            ];
        }

        // ✅ Check verification result
        if (isset($response['success']) && $response['success'] === true) {
            $score = $response['score'] ?? null;
            $minScore = (float) getSetting('captcha.recaptcha.min_score', self::DEFAULT_MIN_RECAPTCHA_SCORE);

            // 📊 Score-based verification — scores below threshold indicate bot
            if ($score !== null && $score < $minScore) {
                return [
                    'success'  => false,
                    'provider' => 'recaptcha',
                    'score'    => $score,
                    'error'    => 'Verification score too low. Please try again.',
                ];
            }

            return [
                'success'  => true,
                'provider' => 'recaptcha',
                'score'    => $score,
                'error'    => null,
            ];
        }

        // ❌ Verification failed
        $errorCodes = $response['error-codes'] ?? [];

        return [
            'success'  => false,
            'provider' => 'recaptcha',
            'score'    => $response['score'] ?? null,
            'error'    => 'CAPTCHA verification failed. Please try again. (' . implode(', ', $errorCodes) . ')',
        ];
    }

    /**
     * 📡 Make a cURL POST request to a CAPTCHA verification endpoint
     *
     * Shared cURL helper with timeout, SSL verification, and JSON parsing.
     * Returns null on any failure (network, timeout, parse error) to allow
     * callers to implement fail-open behaviour.
     *
     * @param string $url    API endpoint URL
     * @param array  $postData POST parameters
     * @return array|null Decoded JSON response, or null on failure
     */
    private static function makeCurlRequest(string $url, array $postData): ?array
    {
        // 🔧 Ensure cURL extension is available
        if (!function_exists('curl_init')) {
            // ⚠️ cURL not available — log error and fail open
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError(
                    'captcha_curl_unavailable',
                    'cURL extension is not available. CAPTCHA verification cannot proceed.',
                    __FILE__,
                    __LINE__,
                    'warning'
                );
            }
            return null;
        }

        try {
            // 📡 Initialize cURL session
            $ch = curl_init();

            // ⚙️ Set cURL options
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($postData),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_SSL_VERIFYPEER => true,     // 🔒 Always verify SSL certificates
                CURLOPT_SSL_VERIFYHOST => 2,         // 🔒 Verify hostname matches certificate
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
                CURLOPT_USERAGENT      => 'SIGNula/' . (defined('APP_VERSION') ? APP_VERSION : '2.6.0'),
            ]);

            // 🚀 Execute request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            curl_close($ch);

            // ⚠️ cURL error (network failure, timeout, SSL error, etc.)
            if ($response === false || !empty($curlError)) {
                if (class_exists('ErrorLogger')) {
                    ErrorLogger::logError(
                        'captcha_curl_error',
                        'CAPTCHA API request failed: ' . $curlError . ' (URL: ' . $url . ')',
                        __FILE__,
                        __LINE__,
                        'warning'
                    );
                }
                return null;
            }

            // ⚠️ Non-200 HTTP response
            if ($httpCode !== 200) {
                if (class_exists('ErrorLogger')) {
                    ErrorLogger::logError(
                        'captcha_http_error',
                        'CAPTCHA API returned HTTP ' . $httpCode . ' (URL: ' . $url . ')',
                        __FILE__,
                        __LINE__,
                        'warning'
                    );
                }
                return null;
            }

            // 📋 Parse JSON response
            $decoded = json_decode($response, true);

            if (!is_array($decoded)) {
                if (class_exists('ErrorLogger')) {
                    ErrorLogger::logError(
                        'captcha_json_error',
                        'Failed to parse CAPTCHA API response as JSON (URL: ' . $url . ')',
                        __FILE__,
                        __LINE__,
                        'warning'
                    );
                }
                return null;
            }

            return $decoded;

        } catch (\Exception $e) {
            // ⚠️ Unexpected error — fail open
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError(
                    'captcha_exception',
                    'CAPTCHA verification exception: ' . $e->getMessage(),
                    __FILE__,
                    __LINE__,
                    'error'
                );
            }
            return null;
        }
    }
}
