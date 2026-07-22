<?php
/**
 * ============================================================================
 * 🕵️ SIGNula - Pwned Passwords (Have I Been Pwned) Breach Checker
 * ============================================================================
 *
 * Purpose: Detect passwords that have appeared in known public data breaches
 *          using the "Have I Been Pwned" (HIBP) Pwned Passwords range API,
 *          via the k-anonymity model — the full password (and even its full
 *          hash) NEVER leaves this server.
 * PHP Version: 8.3+
 *
 * Feature: FG-009 (Issue #96) — Breached / leaked-password detection.
 *
 * 🔒 Security contract (do not weaken):
 *   1. k-ANONYMITY ONLY. We SHA-1 the candidate password locally, uppercase
 *      the hex digest, and transmit ONLY the first 5 hex characters (the
 *      "prefix") to the HIBP range endpoint. HIBP returns EVERY suffix that
 *      shares that prefix (typically several hundred to ~thousand rows); we
 *      then search the response locally for the remaining 35 hex characters
 *      (the "suffix"). Neither the plaintext password, nor its full SHA-1
 *      hash, nor anything else that identifies the exact password is ever
 *      sent off this server.
 *      @see https://haveibeenpwned.com/API/v3#PwnedPasswords
 *      @see https://en.wikipedia.org/wiki/K-anonymity
 *   2. Add-Padding HEADER. We send `Add-Padding: true` so HIBP pads the
 *      response with decoy suffixes — this defeats traffic-analysis attacks
 *      that would otherwise infer the real match from response size alone.
 *      @see https://www.troyhunt.com/enhancing-pwned-passwords-privacy-with-padding/
 *   3. FAIL OPEN, ALWAYS. Any network error, timeout, non-200 response, or
 *      response we cannot parse MUST be treated as "not breached" (allow).
 *      A third-party outage must NEVER be able to block user registration,
 *      password changes, or password resets. Every failure path below
 *      returns 'checked' => false alongside 'breached' => false so callers
 *      can tell "we don't know" apart from "we checked and it's clean" if
 *      they ever need to.
 *   4. DISABLED BY DEFAULT. isBreached() reads
 *      `security.breached_password_check.enabled` from tblSettings (via the
 *      shared getSetting() helper — see web/_config/config.php) and returns
 *      immediately, WITHOUT making any network call, unless an admin has
 *      explicitly flipped that setting on. This class ships wired into the
 *      password-set chokepoint but is a complete no-op until enabled.
 *
 * Settings read (all via getSetting(), all in the 'security' category,
 * seeded — disabled — by _database/migrations/047_breached_password_check.sql):
 *   - security.breached_password_check.enabled         (boolean, default false)
 *   - security.breached_password_check.mode            (string,  default 'block')
 *   - security.breached_password_check.min_count        (integer, default 1)
 *   - security.breached_password_check.timeout_seconds  (integer, default 3)
 *
 * Note: `mode` is intentionally NOT consulted inside this class — isBreached()
 * only ever reports the FACT ("this password was found N times, with N >=
 * the configured minimum"). What to DO about that fact (reject vs merely log/
 * warn) is a decision for the calling chokepoint (see
 * SecurityUtils::validatePassword()), so future modes (e.g. a non-blocking
 * "warn" mode) can be added there without touching this class.
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.9.0-beta
 *
 * @see https://haveibeenpwned.com/API/v3#PwnedPasswords — HIBP range API docs
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Credential_Stuffing_Prevention_Cheat_Sheet.html
 * @see web/private_html/security/IPReputationChecker.php — sibling class this
 *      one mirrors for style/conventions (settings-gated + fail-open + cURL).
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
 * 🕵️ Pwned Passwords (HIBP) Breach Checker
 *
 * All methods are static, following the SIGNula security class pattern
 * (see IPReputationChecker, CaptchaVerifier, etc.).
 */
class PwnedPasswords
{
    // ========================================================================
    // 🔗 API Endpoint
    // ========================================================================

    /** @var string HIBP Pwned Passwords k-anonymity range API base URL (append the 5-char prefix) */
    private const RANGE_API_URL = 'https://api.pwnedpasswords.com/range/';

    // ========================================================================
    // ⚙️ Fallback Configuration Constants
    // (used only if the corresponding tblSettings row is missing/invalid)
    // ========================================================================

    /** @var int Fallback cURL timeout in seconds — short by design (fail-open, must never hang a login/registration request) */
    private const DEFAULT_TIMEOUT_SECONDS = 3;

    /** @var int Fallback minimum occurrence count for a hash to be considered "breached" */
    private const DEFAULT_MIN_COUNT = 1;

    // ========================================================================
    // 🔓 Public Methods
    // ========================================================================

    /**
     * 🔍 Check if breached-password checking is enabled
     *
     * @return bool True if enabled via tblSettings (`security.breached_password_check.enabled`)
     */
    public static function isEnabled(): bool
    {
        return (bool) getSetting('security.breached_password_check.enabled', false);
    }

    /**
     * 🕵️ Check a Candidate Password Against Known Breaches
     *
     * Performs a k-anonymity lookup against the HIBP Pwned Passwords range
     * API. See the class-level docblock above for the full security
     * contract (k-anonymity, Add-Padding, fail-open, disabled-by-default).
     *
     * This method NEVER throws — every failure path (disabled setting,
     * missing cURL extension, network error, timeout, non-200 response,
     * unparsable response) is caught and converted into a fail-open result.
     *
     * @param string $password The candidate plaintext password to check.
     *                          Only its local SHA-1 prefix is ever transmitted.
     * @return array{breached: bool, count: int, checked: bool}
     *         - breached: true only if the password's hash suffix was found
     *           in the HIBP response with an occurrence count >= the
     *           configured minimum.
     *         - count: the occurrence count HIBP reported (0 if not found,
     *           or if the check could not be completed).
     *         - checked: true only if a real, successfully-parsed HIBP
     *           lookup was performed. False whenever the check is disabled,
     *           skipped, or failed open for any reason — callers should NOT
     *           treat checked=false as "definitely not breached", only as
     *           "we could not determine this, so we are allowing it".
     *
     * @example
     * ```php
     * $result = PwnedPasswords::isBreached('P@ssw0rd123!');
     * if ($result['checked'] && $result['breached']) {
     *     // Password was found $result['count'] times in known breaches
     * }
     * ```
     */
    public static function isBreached(string $password): array
    {
        // 🧾 Default result — fail-open shape returned from every early-exit
        // and error path below.
        $result = [
            'breached' => false,
            'count'    => 0,
            'checked'  => false,
        ];

        // 🔒 DISABLED BY DEFAULT — the single most important line in this
        // class. No setting flip, no network call, no behaviour change.
        if (!self::isEnabled()) {
            return $result;
        }

        // 🧹 Nothing meaningful to check for an empty password — let the
        // ordinary length/strength validators handle that case instead.
        if ($password === '') {
            return $result;
        }

        // 🧯 cURL is a hard requirement on this codebase's shared-hosting
        // target (mirrors IPReputationChecker's guard) — if it's somehow
        // unavailable, fail open rather than fatal.
        if (!function_exists('curl_init')) {
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError(
                    'Warning',
                    'PwnedPasswords: cURL extension unavailable — failing open (not breached)',
                    __FILE__,
                    __LINE__
                );
            }
            return $result;
        }

        try {
            // 1️⃣ SHA-1 the password locally, uppercase hex per HIBP's spec.
            //    @see https://haveibeenpwned.com/API/v3#PwnedPasswords
            $hash = strtoupper(sha1($password));

            // 2️⃣ k-anonymity split: only the first 5 chars ("prefix") are
            //    ever sent over the wire. The remaining 35 chars ("suffix")
            //    stay local and are matched against the response body.
            $prefix = substr($hash, 0, 5);
            $suffix = substr($hash, 5);

            // ⏱️ Timeout is DB-configurable but always clamped to a short,
            // sane value — this call sits in the hot path of registration/
            // password-change/reset and must never hang those requests.
            $timeout = (int) getSetting(
                'security.breached_password_check.timeout_seconds',
                self::DEFAULT_TIMEOUT_SECONDS
            );
            if ($timeout <= 0) {
                $timeout = self::DEFAULT_TIMEOUT_SECONDS;
            }

            // 📡 Query the HIBP range endpoint — GET /range/{PREFIX}
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => self::RANGE_API_URL . $prefix,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => [
                    // 🎭 Ask HIBP to pad the response with decoy suffixes so
                    // response size cannot be used to infer a match.
                    // @see https://www.troyhunt.com/enhancing-pwned-passwords-privacy-with-padding/
                    'Add-Padding: true',
                    'Accept: text/plain',
                    // 🏷️ HIBP asks integrators to identify their application.
                    'User-Agent: SIGNula-PwnedPasswords-Check',
                ],
            ]);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // ⚠️ Network error, timeout, or non-200 — FAIL OPEN.
            if ($response === false || $curlError !== '' || $httpCode !== 200) {
                if (class_exists('ErrorLogger')) {
                    ErrorLogger::logError(
                        'Warning',
                        'PwnedPasswords: HIBP range API call failed (HTTP '
                            . $httpCode . ($curlError !== '' ? ', cURL error: ' . $curlError : '')
                            . ') — failing open (not breached)',
                        __FILE__,
                        __LINE__
                    );
                }
                return $result;
            }

            // 3️⃣ Parse the response body and look for our suffix.
            $count = self::findSuffixCount((string) $response, $suffix);

            // 🚧 A null return means the response could not be parsed as the
            // expected "SUFFIX:COUNT" line format at all — treat this as a
            // malformed/unexpected response and FAIL OPEN, same as a
            // transport-level failure.
            if ($count === null) {
                if (class_exists('ErrorLogger')) {
                    ErrorLogger::logError(
                        'Warning',
                        'PwnedPasswords: HIBP range API returned an unparsable response — failing open (not breached)',
                        __FILE__,
                        __LINE__
                    );
                }
                return $result;
            }

            // ✅ A real, successfully-parsed lookup was performed.
            $result['checked'] = true;

            // 📊 Minimum occurrence threshold — some deployments may only
            // want to flag passwords seen many times, not a single obscure
            // breach hit. Defaults to 1 (any occurrence counts).
            $minCount = (int) getSetting(
                'security.breached_password_check.min_count',
                self::DEFAULT_MIN_COUNT
            );
            if ($minCount < 1) {
                $minCount = self::DEFAULT_MIN_COUNT;
            }

            if ($count >= $minCount) {
                $result['breached'] = true;
                $result['count']    = $count;
            }

            return $result;
        } catch (\Throwable $e) {
            // 🧯 Absolutely anything unexpected also fails open — this
            // check must never be able to break registration/password
            // flows, no matter what goes wrong.
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError(
                    'Exception',
                    'PwnedPasswords: unexpected error during breach check — failing open (not breached): '
                        . $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                );
            }
            return $result;
        }
    }

    // ========================================================================
    // 🔒 Private Methods
    // ========================================================================

    /**
     * 🔎 Find a Suffix's Occurrence Count in the HIBP Range Response
     *
     * The HIBP range API returns a plain-text body of `SUFFIX:COUNT` pairs
     * (one per line, CRLF-separated), e.g.:
     *   0018A45C4D1DEF81644B54AB7F969B88D65:1
     *   00D4F6E8FA6EECAD2A3AA415EEC418D38EC:2
     *   ...
     * @see https://haveibeenpwned.com/API/v3#PwnedPasswords
     *
     * @param string $response The raw HIBP response body.
     * @param string $suffix   The 35-char uppercase-hex suffix to search for.
     * @return int|null The occurrence count if the suffix was found (0 if the
     *                  response was valid but the suffix was simply absent),
     *                  or NULL if the response could not be parsed as the
     *                  expected format at all (treated as a failure upstream).
     */
    private static function findSuffixCount(string $response, string $suffix): ?int
    {
        $response = trim($response);

        // 🚧 An empty body is never valid — HIBP's Add-Padding guarantees a
        // substantial, non-trivial response body.
        if ($response === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $response);
        if (!is_array($lines) || empty($lines)) {
            return null;
        }

        $validLineSeen = false;

        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            // ✅ At least one well-formed "SUFFIX:COUNT" line confirms this
            // is a genuine HIBP range response, not garbage/an error page.
            $validLineSeen = true;

            if (strcasecmp(trim($parts[0]), $suffix) === 0) {
                return (int) trim($parts[1]);
            }
        }

        // 📭 Well-formed response, our suffix just wasn't present — 0 hits.
        // 🚧 No well-formed lines at all — something we don't understand.
        return $validLineSeen ? 0 : null;
    }
}
