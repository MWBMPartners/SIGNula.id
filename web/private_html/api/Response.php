<?php
/**
 * ============================================================================
 * 📡 SIGNula - API Response Formatter
 * ============================================================================
 *
 * Standardized JSON response formatter for RESTful API endpoints.
 * Provides consistent response structure across all API endpoints.
 *
 * Features:
 * - Standardized JSON response format
 * - HTTP status code management
 * - Error response formatting
 * - Success response formatting
 * - Pagination support
 * - CORS header management
 * - Content-Type enforcement
 *
 * Response Format:
 * {
 *   "success": true|false,
 *   "message": "Human-readable message",
 *   "data": {...},
 *   "errors": [...],
 *   "meta": {
 *     "timestamp": "ISO 8601 datetime",
 *     "version": "v1",
 *     "request_id": "unique-id"
 *   },
 *   "pagination": {...}
 * }
 *
 * ============================================================================
 * 📐 CANONICAL ENVELOPE STANDARD (CAP-API audit #35 / Bucket B, item #1)
 * ============================================================================
 * This is THE standard response envelope for every SIGNula HTTP JSON
 * endpoint going forward — REST (`/api/v1/*`), legacy AJAX action handlers,
 * and standalone entry points alike. Every ERROR response, wherever it is
 * produced, MUST include at minimum these top-level keys:
 *
 *   - `success` (bool, always false for an error)
 *   - `message` (string, human-readable summary)
 *   - `errors`  (array<string>, one or more detail strings — may just be
 *                `[$message]` when there is nothing more granular to report)
 *   - `meta.timestamp` / `meta.version` / `meta.request_id`
 *
 * A handler MAY keep additional legacy/bespoke keys alongside the canonical
 * ones (e.g. a nested `error: {code, message, ...}` object, a flat
 * `timestamp`, a `rate_limit` block) — the rule is ADDITIVE ONLY: never
 * remove or rename a key an existing client already parses. This "superset"
 * approach is how the following known offenders were converged without a
 * breaking version bump:
 *
 *   - {@see RateLimitMiddleware::respondRateLimitExceeded()} — kept its
 *     nested `error{code,message,retry_after,...}` and `rate_limit{}`
 *     blocks, ADDED top-level `message`/`errors`/`meta.version`.
 *   - {@see APIKeyMiddleware::respondUnauthorized()} and
 *     {@see APIKeyMiddleware::respondForbidden()} — same treatment.
 *   - `jsonResponse()` (web/_config/config.php) — kept its flat `data` and
 *     `timestamp` (unix seconds) keys, ADDED `errors`/`meta` for error
 *     responses only (success payloads are untouched).
 *   - The standalone WebAuthn / OAuth-disconnect / export entry points under
 *     `web/public_html/api/**` — kept their hand-rolled `error` string key,
 *     ADDED `message`/`errors`/`meta`.
 *
 * SUCCESS payload shapes are explicitly OUT of scope for this convergence —
 * only the error envelope is being standardised (success bodies differ
 * legitimately by endpoint and changing them is a real contract break).
 *
 * New code should simply use this `Response` class directly, which already
 * emits the canonical shape natively (see send() below) — no superset
 * shimming needed for anything that already extends BaseController.
 * ============================================================================
 *
 * @package    SIGNula
 * @subpackage API
 * @version    1.0.0
 * @link       https://SIGNula.id
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * Class Response
 *
 * Handles all API response formatting and HTTP status codes
 */
class Response
{
    /**
     * 🎯 HTTP Status Codes
     */
    public const HTTP_OK = 200;                    // Success
    public const HTTP_CREATED = 201;               // Resource created
    public const HTTP_ACCEPTED = 202;              // Accepted for processing
    public const HTTP_NO_CONTENT = 204;            // Success with no content
    public const HTTP_BAD_REQUEST = 400;           // Client error
    public const HTTP_UNAUTHORIZED = 401;          // Authentication required
    public const HTTP_FORBIDDEN = 403;             // Access denied
    public const HTTP_NOT_FOUND = 404;             // Resource not found
    public const HTTP_METHOD_NOT_ALLOWED = 405;    // HTTP method not allowed
    public const HTTP_CONFLICT = 409;              // Conflict (duplicate)
    public const HTTP_UNPROCESSABLE = 422;         // Validation failed
    public const HTTP_TOO_MANY_REQUESTS = 429;     // Rate limit exceeded
    public const HTTP_INTERNAL_ERROR = 500;        // Server error
    public const HTTP_SERVICE_UNAVAILABLE = 503;   // Service unavailable

    /**
     * 📊 Response metadata
     */
    private static string $version = 'v1';
    private static ?string $requestId = null;

    /**
     * 🚀 Send successful JSON response
     *
     * @param mixed $data Response data
     * @param string $message Success message
     * @param int $statusCode HTTP status code (default: 200)
     * @param array|null $pagination Pagination data (optional)
     * @return void (exits script)
     */
    public static function success(
        mixed $data = null,
        string $message = 'Success',
        int $statusCode = self::HTTP_OK,
        ?array $pagination = null
    ): void {
        self::send(true, $message, $data, [], $statusCode, $pagination);
    }

    /**
     * ❌ Send error JSON response
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code (default: 400)
     * @param array $errors Array of error details
     * @param mixed $data Optional data to include
     * @return void (exits script)
     */
    public static function error(
        string $message,
        int $statusCode = self::HTTP_BAD_REQUEST,
        array $errors = [],
        mixed $data = null
    ): void {
        self::send(false, $message, $data, $errors, $statusCode);
    }

    /**
     * ✅ Send created response (201)
     *
     * @param mixed $data Created resource data
     * @param string $message Success message
     * @return void (exits script)
     */
    public static function created(mixed $data, string $message = 'Resource created successfully'): void
    {
        self::success($data, $message, self::HTTP_CREATED);
    }

    /**
     * 🗑️ Send no content response (204)
     *
     * @return void (exits script)
     */
    public static function noContent(): void
    {
        http_response_code(self::HTTP_NO_CONTENT);
        self::setCorsHeaders();
        self::setSecurityHeaders();
        exit;
    }

    /**
     * 🔒 Send unauthorized response (401)
     *
     * @param string $message Error message
     * @return void (exits script)
     */
    public static function unauthorized(string $message = 'Authentication required'): void
    {
        self::error($message, self::HTTP_UNAUTHORIZED);
    }

    /**
     * 🚫 Send forbidden response (403)
     *
     * @param string $message Error message
     * @return void (exits script)
     */
    public static function forbidden(string $message = 'Access denied'): void
    {
        self::error($message, self::HTTP_FORBIDDEN);
    }

    /**
     * 🔍 Send not found response (404)
     *
     * @param string $message Error message
     * @return void (exits script)
     */
    public static function notFound(string $message = 'Resource not found'): void
    {
        self::error($message, self::HTTP_NOT_FOUND);
    }

    /**
     * ❗ Send validation error response (422)
     *
     * @param string $message Error message
     * @param array $errors Validation errors
     * @return void (exits script)
     */
    public static function validationError(string $message = 'Validation failed', array $errors = []): void
    {
        self::error($message, self::HTTP_UNPROCESSABLE, $errors);
    }

    /**
     * ⏱️ Send rate limit response (429)
     *
     * @param string $message Error message
     * @param int|null $retryAfter Seconds until retry allowed
     * @return void (exits script)
     */
    public static function rateLimitExceeded(string $message = 'Rate limit exceeded', ?int $retryAfter = null): void
    {
        if ($retryAfter !== null) {
            header("Retry-After: $retryAfter");
        }
        self::error($message, self::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * 💥 Send internal server error response (500)
     *
     * @param string $message Error message
     * @param array $errors Error details (only in dev mode)
     * @return void (exits script)
     */
    public static function internalError(
        string $message = 'Internal server error',
        array $errors = []
    ): void {
        // 🔒 In production, hide detailed error information
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            $errors = [];
            $message = 'An unexpected error occurred. Please try again later.';
        }

        self::error($message, self::HTTP_INTERNAL_ERROR, $errors);
    }

    /**
     * 📄 Send paginated response
     *
     * @param array $items Array of items
     * @param int $total Total number of items
     * @param int $page Current page
     * @param int $perPage Items per page
     * @param string $message Success message
     * @return void (exits script)
     */
    public static function paginated(
        array $items,
        int $total,
        int $page,
        int $perPage,
        string $message = 'Success'
    ): void {
        $totalPages = (int) ceil($total / $perPage);

        $pagination = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_more' => $page < $totalPages,
        ];

        self::success($items, $message, self::HTTP_OK, $pagination);
    }

    /**
     * 📤 Core send method - formats and outputs JSON response
     *
     * @param bool $success Success status
     * @param string $message Response message
     * @param mixed $data Response data
     * @param array $errors Error details
     * @param int $statusCode HTTP status code
     * @param array|null $pagination Pagination data
     * @return void (exits script)
     */
    private static function send(
        bool $success,
        string $message,
        mixed $data,
        array $errors,
        int $statusCode,
        ?array $pagination = null
    ): void {
        // 🎯 Set HTTP status code
        http_response_code($statusCode);

        // 🌐 Set CORS headers
        self::setCorsHeaders();

        // 🛡️ Set baseline security headers (clickjacking defence — issue #29)
        self::setSecurityHeaders();

        // 📋 Set content type
        header('Content-Type: application/json; charset=utf-8');

        // 🏗️ Build response structure
        $response = [
            'success' => $success,
            'message' => $message,
        ];

        // Add data if present
        if ($data !== null) {
            $response['data'] = $data;
        }

        // Add errors if present
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        // Add metadata
        $response['meta'] = [
            'timestamp' => gmdate('c'), // ISO 8601 format
            'version' => self::$version,
            'request_id' => self::getRequestId(),
        ];

        // Add pagination if present
        if ($pagination !== null) {
            $response['pagination'] = $pagination;
        }

        // 📤 Output JSON response
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * 🌐 Set CORS headers for cross-origin requests
     *
     * 🔒 B-024 fix: NEVER pair `Access-Control-Allow-Origin: *` with
     * `Access-Control-Allow-Credentials: true`. That combination is both
     * invalid per the Fetch standard (browsers reject it) AND a security risk.
     *
     * Behaviour (fail-safe):
     *  - Read a configurable allowlist from settings
     *    (`api.cors.allowed_origins`): a PHP array, a JSON array, or a
     *    comma-separated string.
     *  - If the request `Origin` is in the allowlist, echo it back EXACTLY and
     *    THEN send `Allow-Credentials: true` (the spec-compliant way to allow
     *    credentialed cross-origin requests).
     *  - If the origin is not allowed (or no allowlist is configured), we do NOT
     *    emit any `Access-Control-Allow-Origin` header and we do NOT send
     *    `Allow-Credentials` — the browser blocks the cross-origin read. This is
     *    the safe default; a wildcard with credentials is never emitted.
     *  - `Vary: Origin` is always sent so caches don't serve one origin's CORS
     *    decision to another.
     *
     * To intentionally allow ALL origins WITHOUT credentials, set the allowlist
     * to the literal string "*"; we then send `Allow-Origin: *` and OMIT
     * `Allow-Credentials` (valid, anonymous CORS).
     *
     * @see https://fetch.spec.whatwg.org/#cors-protocol-and-credentials
     * @return void
     */
    private static function setCorsHeaders(): void
    {
        // 🔁 Always vary on Origin so caches never cross-contaminate decisions.
        header('Vary: Origin');

        // 🌐 The browser-supplied request origin (may be absent for same-origin).
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // ⚙️ Pull the configured allowlist. Default null = "no CORS configured".
        $configured = getSetting('api.cors.allowed_origins', null);

        // 🌍 Explicit anonymous wildcard: allow all, but NEVER with credentials.
        if ($configured === '*') {
            header('Access-Control-Allow-Origin: *');
            // ❗ Deliberately NO Access-Control-Allow-Credentials here.
            self::setCorsCommonHeaders();
            return;
        }

        // 📋 Normalise the allowlist into an array of exact origin strings.
        $allowedOrigins = self::normaliseAllowedOrigins($configured);

        // ✅ Echo back the origin ONLY if it is explicitly allowlisted, then —
        //    and only then — permit credentials.
        if ($requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $requestOrigin);
            header('Access-Control-Allow-Credentials: true');
            self::setCorsCommonHeaders();
            return;
        }

        // 🚫 Fail safe: origin not allowed / nothing configured. Emit the method,
        //    header and max-age hints but NO Allow-Origin and NO credentials, so
        //    the browser blocks any cross-origin read. (A wildcard-with-creds is
        //    never produced by this method.)
        self::setCorsCommonHeaders();
    }

    /**
     * 🧰 Normalise the configured CORS allowlist into an array of origins.
     *
     * Accepts a PHP array, a JSON array string, or a comma-separated string.
     * Each entry is trimmed; empties are dropped. Returns an empty array when
     * nothing usable is configured (which makes the caller fail safe).
     *
     * @param mixed $configured Raw value from getSetting().
     * @return array<int,string> Exact-match origin allowlist.
     */
    private static function normaliseAllowedOrigins(mixed $configured): array
    {
        if (is_array($configured)) {
            $list = $configured;
        } elseif (is_string($configured) && $configured !== '') {
            // Try JSON array first, then fall back to a comma-separated list.
            $decoded = json_decode($configured, true);
            $list = is_array($decoded) ? $decoded : explode(',', $configured);
        } else {
            return [];
        }

        $origins = [];
        foreach ($list as $origin) {
            $origin = trim((string) $origin);
            if ($origin !== '' && $origin !== '*') {
                // A literal "*" in a list is ignored — wildcard+creds is unsafe.
                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }

    /**
     * 🧩 Emit the non-origin CORS headers shared by every CORS response.
     *
     * @return void
     */
    private static function setCorsCommonHeaders(): void
    {
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Key');
        header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours
    }

    /**
     * 🛡️ Set baseline security headers on every API response (issue #29).
     *
     * API responses are JSON and must never be framed. Sending both the legacy
     * `X-Frame-Options: DENY` and the modern CSP `frame-ancestors 'none'`
     * directive covers old and new browsers. `X-Content-Type-Options: nosniff`
     * stops the browser MIME-sniffing a JSON body into something executable.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Frame-Options
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy/frame-ancestors
     * @return void
     */
    private static function setSecurityHeaders(): void
    {
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
    }

    /**
     * 🆔 Generate or retrieve unique request ID
     *
     * @return string Request ID
     */
    private static function getRequestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = bin2hex(random_bytes(16));
        }
        return self::$requestId;
    }

    /**
     * 🔧 Set API version
     *
     * @param string $version API version (e.g., 'v1', 'v2')
     * @return void
     */
    public static function setVersion(string $version): void
    {
        self::$version = $version;
    }

    /**
     * 🔧 Set request ID (useful for logging correlation)
     *
     * @param string $requestId Request ID
     * @return void
     */
    public static function setRequestId(string $requestId): void
    {
        self::$requestId = $requestId;
    }

    /**
     * ⚙️ Handle OPTIONS preflight requests
     *
     * @return void (exits script)
     */
    public static function handlePreflight(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(self::HTTP_NO_CONTENT);
            self::setCorsHeaders();
            self::setSecurityHeaders();
            exit;
        }
    }
}

// ✅ Response class loaded successfully
