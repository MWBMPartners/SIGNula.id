<?php
/**
 * ============================================================================
 * 🛡️ SIGNula - Security Middleware
 * ============================================================================
 *
 * Purpose: Unified security request pipeline orchestrating IP blocklist,
 *          bot detection, IP reputation, session fingerprinting, rate limiting,
 *          and CAPTCHA verification
 * PHP Version: 8.3+
 *
 * This middleware provides two entry points:
 *   - handle()              — Called on every page load from config.php bootstrap
 *   - handleFormSubmission() — Called before processing POST form data
 *
 * Each security check is independently toggleable via tblSettings and fails
 * open (allows through) when disabled or when external APIs are unavailable.
 *
 * Pipeline order (handle):
 *   1. IP blocklist check    (DB-only, fastest)
 *   2. Bot detection         (local library, no API calls)
 *   3. IP reputation check   (cached API calls)
 *
 * Pipeline order (handleFormSubmission):
 *   1. Rate limiting         (DB-based, per-form configuration)
 *   2. CAPTCHA verification  (external API, fail-open)
 *
 * @package    SIGNula
 * @subpackage Security
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
 * 🛡️ Security Middleware
 *
 * Orchestrates all security checks into a unified pipeline for both
 * page requests and form submissions. All methods are static following
 * the SIGNula security class pattern.
 */
class SecurityMiddleware
{
    // ========================================================================
    // 🔓 Public Methods
    // ========================================================================

    /**
     * 🚀 Handle a page request through the security pipeline
     *
     * Called from config.php bootstrap on every request. Runs lightweight
     * checks that don't significantly impact page load time.
     *
     * Pipeline:
     * 1. IP blocklist check (IPReputationChecker::isBlocked) — DB query only
     * 2. Bot detection (BotDetector::shouldBlock) — local regex, no API
     * 3. IP reputation check (IPReputationChecker::check) — cached API results
     *
     * Each step is independently toggleable. On block, renders a user-friendly
     * error page (web context) or returns JSON error (API context).
     *
     * @param string $context Request context: 'web' for page requests, 'api' for API requests
     */
    public static function handle(string $context = 'web'): void
    {
        // 🌐 Get the client IP address
        $ip = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        // ====================================================================
        // 🔍 Step 1: IP Blocklist Check (fastest — DB query only)
        // ====================================================================

        if (class_exists('IPReputationChecker') && IPReputationChecker::isEnabled()) {
            // 🚫 Check if IP is explicitly blocked in tblBlockedIPs
            if (IPReputationChecker::isBlocked($ip)) {
                // 📋 Log the blocked request
                if (class_exists('ActivityLogger')) {
                    ActivityLogger::log(
                        null,
                        'request_blocked_ip',
                        'security',
                        'warning',
                        'Request blocked: IP is on the blocklist',
                        [
                            'ipAddress' => $ip,
                            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                            'requestURI' => $_SERVER['REQUEST_URI'] ?? '/',
                            'step' => 'ip_blocklist',
                        ]
                    );
                }

                self::renderBlockPage(
                    'Your IP address has been temporarily blocked due to suspicious activity.',
                    403,
                    $context
                );
                return;
            }
        }

        // ====================================================================
        // 🤖 Step 2: Bot Detection (local — no API calls)
        // ====================================================================

        if (class_exists('BotDetector') && BotDetector::isEnabled()) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            if (BotDetector::shouldBlock($userAgent)) {
                // 📋 Log the blocked bot
                if (class_exists('ActivityLogger')) {
                    $botInfo = BotDetector::detect($userAgent);
                    ActivityLogger::log(
                        null,
                        'request_blocked_bot',
                        'security',
                        'info',
                        'Request blocked: Bad bot detected (' . ($botInfo['bot_name'] ?? 'unknown') . ')',
                        [
                            'ipAddress' => $ip,
                            'userAgent' => $userAgent,
                            'botName' => $botInfo['bot_name'] ?? null,
                            'detectionMethod' => $botInfo['detection_method'] ?? null,
                            'step' => 'bot_detection',
                        ]
                    );
                }

                // 🚨 Create security alert for bot detection
                if (class_exists('SecurityAlertManager') && SecurityAlertManager::isEnabled()) {
                    SecurityAlertManager::create(
                        SecurityAlertManager::TYPE_BOT_DETECTED,
                        SecurityAlertManager::SEVERITY_LOW,
                        'Bad bot blocked: ' . ($botInfo['bot_name'] ?? 'unknown'),
                        [
                            'userAgent' => $userAgent,
                            'detectionMethod' => $botInfo['detection_method'] ?? null,
                        ],
                        null,
                        $ip
                    );
                }

                self::renderBlockPage(
                    'Access denied.',
                    403,
                    $context
                );
                return;
            }
        }

        // ====================================================================
        // 🌐 Step 3: IP Reputation Check (cached API results)
        // ====================================================================

        if (class_exists('IPReputationChecker') && IPReputationChecker::isEnabled()) {
            $ipCheck = IPReputationChecker::check($ip);

            if (!$ipCheck['allowed']) {
                // 📋 Log the blocked request
                if (class_exists('ActivityLogger')) {
                    ActivityLogger::log(
                        null,
                        'request_blocked_reputation',
                        'security',
                        'warning',
                        'Request blocked by IP reputation: ' . ($ipCheck['reason'] ?? 'unknown'),
                        [
                            'ipAddress' => $ip,
                            'score' => $ipCheck['score'] ?? null,
                            'isProxy' => $ipCheck['is_proxy'] ?? null,
                            'isVPN' => $ipCheck['is_vpn'] ?? null,
                            'isTor' => $ipCheck['is_tor'] ?? null,
                            'source' => $ipCheck['source'] ?? null,
                            'cached' => $ipCheck['cached'] ?? null,
                            'step' => 'ip_reputation',
                        ]
                    );
                }

                // 🚨 Create security alert
                if (class_exists('SecurityAlertManager') && SecurityAlertManager::isEnabled()) {
                    SecurityAlertManager::create(
                        SecurityAlertManager::TYPE_IP_BLOCKED,
                        SecurityAlertManager::SEVERITY_MEDIUM,
                        'IP blocked by reputation check: ' . ($ipCheck['reason'] ?? 'unknown'),
                        [
                            'score' => $ipCheck['score'] ?? null,
                            'isProxy' => $ipCheck['is_proxy'] ?? null,
                            'source' => $ipCheck['source'] ?? null,
                        ],
                        null,
                        $ip
                    );
                }

                self::renderBlockPage(
                    'Access has been restricted for security reasons. If you believe this is an error, please contact support.',
                    403,
                    $context
                );
                return;
            }
        }
    }

    /**
     * 📝 Handle a form submission through additional security checks
     *
     * Called before processing POST form data in individual form pages.
     * Performs checks on top of handle() which already ran during bootstrap.
     *
     * Pipeline:
     * 1. Rate limiting for the specific form
     * 2. CAPTCHA verification (if enabled and required for this form)
     *
     * @param string $formName Form identifier: 'login', 'register', 'forgot-password', 'contact'
     * @return array{allowed: bool, error: ?string, captcha_required: bool}
     */
    public static function handleFormSubmission(string $formName): array
    {
        $ip = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        // ====================================================================
        // ⏱️ Step 1: Rate Limiting
        // ====================================================================

        if (class_exists('RateLimiter')) {
            // 📋 Map form name to rate limit endpoint path
            $endpoint = '/' . ltrim($formName, '/');

            try {
                $rateLimitResult = RateLimiter::checkLimit($ip, 'ip', $endpoint);

                if (isset($rateLimitResult['blocked']) && $rateLimitResult['blocked']) {
                    // 📋 Log rate limit hit
                    if (class_exists('ActivityLogger')) {
                        ActivityLogger::log(
                            null,
                            'form_rate_limited',
                            'security',
                            'warning',
                            'Form submission rate limited: ' . $formName,
                            [
                                'formName' => $formName,
                                'ipAddress' => $ip,
                                'endpoint' => $endpoint,
                            ]
                        );
                    }

                    // 🚨 Create alert if this is a repeated pattern
                    if (class_exists('SecurityAlertManager') && SecurityAlertManager::isEnabled()) {
                        SecurityAlertManager::create(
                            SecurityAlertManager::TYPE_RATE_LIMIT_EXCEEDED,
                            SecurityAlertManager::SEVERITY_LOW,
                            'Rate limit exceeded on form: ' . $formName,
                            ['formName' => $formName, 'endpoint' => $endpoint],
                            null,
                            $ip
                        );
                    }

                    return [
                        'allowed'          => false,
                        'error'            => 'Too many attempts. Please wait a moment before trying again.',
                        'captcha_required' => false,
                    ];
                }
            } catch (\Exception $e) {
                // ⚠️ Rate limiter failure should not block the form
                if (class_exists('ErrorLogger')) {
                    ErrorLogger::logError(
                        'rate_limit_check_failed',
                        'Rate limit check failed for ' . $formName . ': ' . $e->getMessage(),
                        __FILE__,
                        __LINE__,
                        'warning'
                    );
                }
            }
        }

        // ====================================================================
        // 🛡️ Step 2: Local Form Protection (Honeypot + Timing + JS Challenge)
        // ====================================================================
        // 📝 These checks are always-on, local-only, and independent of CAPTCHA.
        // They provide bot protection even when external CAPTCHA APIs are down.

        if (class_exists('FormProtection') && FormProtection::isEnabled()) {
            $protectionResult = FormProtection::validate();

            if (!$protectionResult['passed']) {
                // 📋 Log the form protection failure
                if (class_exists('ActivityLogger')) {
                    ActivityLogger::log(
                        null,
                        'form_protection_failed',
                        'security',
                        'warning',
                        'Local form protection failed on form: ' . $formName . ' (reason: ' . ($protectionResult['reason'] ?? 'unknown') . ')',
                        [
                            'formName' => $formName,
                            'reason' => $protectionResult['reason'] ?? 'unknown',
                            'ipAddress' => $ip,
                        ]
                    );
                }

                // 🚨 Create security alert for bot-like submissions
                if (class_exists('SecurityAlertManager') && SecurityAlertManager::isEnabled()) {
                    SecurityAlertManager::create(
                        SecurityAlertManager::TYPE_BOT_DETECTED,
                        SecurityAlertManager::SEVERITY_LOW,
                        'Automated form submission detected on ' . $formName . ' (' . ($protectionResult['reason'] ?? 'unknown') . ')',
                        [
                            'formName' => $formName,
                            'reason' => $protectionResult['reason'] ?? null,
                        ],
                        null,
                        $ip
                    );
                }

                return [
                    'allowed'          => false,
                    'error'            => 'Form submission could not be verified. Please try again.',
                    'captcha_required' => false,
                ];
            }
        }

        // ====================================================================
        // 🛡️ Step 3: CAPTCHA Verification
        // ====================================================================

        $captchaRequired = false;

        if (class_exists('CaptchaVerifier')) {
            $captchaRequired = CaptchaVerifier::isRequired($formName);

            if ($captchaRequired) {
                $captchaResult = CaptchaVerifier::verify();

                if (!$captchaResult['success']) {
                    // 📋 Log CAPTCHA failure
                    if (class_exists('ActivityLogger')) {
                        ActivityLogger::log(
                            null,
                            'captcha_failed',
                            'security',
                            'info',
                            'CAPTCHA verification failed on form: ' . $formName,
                            [
                                'formName' => $formName,
                                'provider' => $captchaResult['provider'] ?? 'unknown',
                                'score' => $captchaResult['score'] ?? null,
                                'error' => $captchaResult['error'] ?? null,
                                'ipAddress' => $ip,
                            ]
                        );
                    }

                    // 🚨 Create alert for repeated CAPTCHA failures
                    if (class_exists('SecurityAlertManager') && SecurityAlertManager::isEnabled()) {
                        SecurityAlertManager::create(
                            SecurityAlertManager::TYPE_CAPTCHA_FAILURE,
                            SecurityAlertManager::SEVERITY_LOW,
                            'CAPTCHA failed on ' . $formName . ' (' . ($captchaResult['provider'] ?? 'unknown') . ')',
                            [
                                'formName' => $formName,
                                'provider' => $captchaResult['provider'] ?? null,
                                'score' => $captchaResult['score'] ?? null,
                            ],
                            null,
                            $ip
                        );
                    }

                    return [
                        'allowed'          => false,
                        'error'            => $captchaResult['error'] ?? 'CAPTCHA verification failed. Please try again.',
                        'captcha_required' => true,
                    ];
                }
            }
        }

        // ✅ All checks passed
        return [
            'allowed'          => true,
            'error'            => null,
            'captcha_required' => $captchaRequired,
        ];
    }

    // ========================================================================
    // 🔒 Private Methods
    // ========================================================================

    /**
     * 🚫 Render a block/error page
     *
     * For web context: renders a user-friendly HTML error page.
     * For API context: returns a JSON error response.
     *
     * @param string $reason   Human-readable reason for blocking
     * @param int    $statusCode HTTP status code (default: 403)
     * @param string $context  'web' or 'api'
     */
    private static function renderBlockPage(string $reason, int $statusCode = 403, string $context = 'web'): void
    {
        // 📡 API context — return JSON
        if ($context === 'api') {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => [
                    'code'    => $statusCode,
                    'message' => $reason,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 🌐 Web context — render HTML error page
        http_response_code($statusCode);

        // 🎨 Use a simple, styled error page that doesn't depend on templates
        $appName = defined('APP_NAME') ? APP_NAME : 'SIGNula';
        $escapedReason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
        $escapedAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');

        echo '<!DOCTYPE html>' . PHP_EOL;
        echo '<html lang="en">' . PHP_EOL;
        echo '<head>' . PHP_EOL;
        echo '    <meta charset="UTF-8">' . PHP_EOL;
        echo '    <meta name="viewport" content="width=device-width, initial-scale=1.0">' . PHP_EOL;
        echo '    <title>Access Denied - ' . $escapedAppName . '</title>' . PHP_EOL;
        echo '    <style>' . PHP_EOL;
        echo '        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; ';
        echo 'display: flex; align-items: center; justify-content: center; min-height: 100vh; ';
        echo 'margin: 0; background: #f8f9fa; color: #333; }' . PHP_EOL;
        echo '        .container { text-align: center; max-width: 500px; padding: 2rem; }' . PHP_EOL;
        echo '        .icon { font-size: 4rem; margin-bottom: 1rem; }' . PHP_EOL;
        echo '        h1 { font-size: 1.5rem; margin-bottom: 0.5rem; color: #dc3545; }' . PHP_EOL;
        echo '        p { color: #666; line-height: 1.6; }' . PHP_EOL;
        echo '        .code { font-size: 0.9rem; color: #999; margin-top: 1.5rem; }' . PHP_EOL;
        echo '        a { color: #0d6efd; text-decoration: none; }' . PHP_EOL;
        echo '        a:hover { text-decoration: underline; }' . PHP_EOL;
        echo '    </style>' . PHP_EOL;
        echo '</head>' . PHP_EOL;
        echo '<body>' . PHP_EOL;
        echo '    <div class="container">' . PHP_EOL;
        echo '        <div class="icon">&#128274;</div>' . PHP_EOL;
        echo '        <h1>Access Denied</h1>' . PHP_EOL;
        echo '        <p>' . $escapedReason . '</p>' . PHP_EOL;
        echo '        <p>If you believe this is an error, please <a href="/contact">contact support</a>.</p>' . PHP_EOL;
        echo '        <p class="code">Error ' . $statusCode . '</p>' . PHP_EOL;
        echo '    </div>' . PHP_EOL;
        echo '</body>' . PHP_EOL;
        echo '</html>';

        exit;
    }
}
