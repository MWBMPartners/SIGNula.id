<?php
/**
 * API Key Middleware
 *
 * Authenticates API requests using partner API keys
 * Integrates with Router to provide secure third-party access
 *
 * Features:
 * - API key detection (header or query parameter)
 * - Key validation and authentication
 * - Partner context injection
 * - Usage logging
 * - HTTP 401 response for invalid keys
 * - Permissions checking
 * - IP whitelist enforcement
 *
 * Authentication Methods:
 * - Header: X-API-Key: sk_live_xxxxx
 * - Query param: ?api_key=sk_live_xxxxx (less secure, but supported)
 *
 * @package SIGNula
 * @version 2.2.0-beta
 * @since 2026-02-04
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class APIKeyMiddleware {
    private $apiKeyManager;
    private $db;
    private $authenticated = false;
    private $keyData = null;
    private $requestStartTime;

    /**
     * Constructor
     *
     * @param mysqli $db Database connection
     */
    public function __construct($db = null) {
        if ($db === null) {
            global $db;
        }
        $this->db = $db;

        // Initialize API key manager
        require_once __DIR__ . '/APIKeyManager.php';
        $this->apiKeyManager = new APIKeyManager($this->db);

        // Track request start time for response time logging
        $this->requestStartTime = microtime(true);
    }

    /**
     * Handle API key authentication for current request
     * Call this before processing API request (but after rate limiting)
     *
     * @param bool $required If true, exits with 401 if no valid API key found
     * @return void May exit with 401 if authentication required and fails
     */
    public function handle(bool $required = false): void {
        // Check if API keys are enabled
        if (!$this->isEnabled()) {
            if ($required) {
                $this->respondUnauthorized('API key authentication is currently disabled');
            }
            return;
        }

        // Get API key from request
        $apiKey = $this->getAPIKey();

        // If no API key provided and it's required, deny access
        if (!$apiKey && $required) {
            $this->respondUnauthorized('API key is required for this endpoint');
            return;
        }

        // If no API key provided and it's optional, allow through
        if (!$apiKey && !$required) {
            return;
        }

        // Validate API key
        $ipAddress = $this->getClientIP();
        $keyData = $this->apiKeyManager->validateKey($apiKey, $ipAddress);

        if (!$keyData) {
            $this->respondUnauthorized('Invalid or expired API key');
            return;
        }

        // Store authenticated key data
        $this->authenticated = true;
        $this->keyData = $keyData;

        // Inject partner context into global scope for controllers to use
        global $apiKeyContext;
        $apiKeyContext = [
            'authenticated' => true,
            'keyID' => $keyData['keyID'],
            'partnerID' => $keyData['partnerID'],
            'partnerName' => $keyData['partnerName'],
            'accountTier' => $keyData['accountTier'],
            'environment' => $keyData['environment'],
            'permissions' => $keyData['permissions'] ? json_decode($keyData['permissions'], true) : []
        ];
    }

    /**
     * Check if API key authentication is enabled
     *
     * @return bool Enabled status
     */
    public function isEnabled(): bool {
        $stmt = $this->db->prepare("
            SELECT settingValue FROM tblSettings
            WHERE settingKey = 'api_keys.enabled'
        ");

        if (!$stmt) {
            return true; // Default to enabled if can't check
        }

        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return true;
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        return $row['settingValue'] === '1' || $row['settingValue'] === 'true';
    }

    /**
     * Check if current request has a specific permission
     *
     * @param string $permission Permission to check (e.g., 'users:write')
     * @return bool Has permission
     */
    public function hasPermission(string $permission): bool {
        if (!$this->authenticated || !$this->keyData) {
            return false;
        }

        // If no permissions set, allow all (legacy keys)
        if (!$this->keyData['permissions']) {
            return true;
        }

        $permissions = json_decode($this->keyData['permissions'], true);

        // Check for exact match
        if (in_array($permission, $permissions)) {
            return true;
        }

        // Check for wildcard (e.g., 'users:*' allows 'users:read', 'users:write')
        list($resource, $action) = explode(':', $permission, 2);
        if (in_array($resource . ':*', $permissions)) {
            return true;
        }

        // Check for global wildcard
        if (in_array('*', $permissions)) {
            return true;
        }

        return false;
    }

    /**
     * Require a specific permission (exits with 403 if not allowed)
     *
     * @param string $permission Permission to require
     * @return void May exit with 403
     */
    public function requirePermission(string $permission): void {
        if (!$this->hasPermission($permission)) {
            $this->respondForbidden("This API key does not have permission: $permission");
        }
    }

    /**
     * Log API usage for the current request
     * Call this after request is processed
     *
     * @param int $statusCode HTTP status code
     * @param string|null $errorCode Optional error code
     * @param bool $rateLimitHit Whether request was rate limited
     * @return void
     */
    public function logUsage(int $statusCode, ?string $errorCode = null, bool $rateLimitHit = false): void {
        // Only log if authenticated with API key
        if (!$this->authenticated || !$this->keyData) {
            return;
        }

        // Check if usage logging is enabled
        if (!$this->shouldLogUsage()) {
            return;
        }

        // Calculate response time
        $responseTime = round((microtime(true) - $this->requestStartTime) * 1000); // Convert to milliseconds

        // Get request details
        $endpoint = $this->getEndpoint();
        $httpMethod = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $ipAddress = $this->getClientIP();

        // Log to database
        $this->apiKeyManager->logUsage(
            $this->keyData['keyID'],
            $endpoint,
            $httpMethod,
            $statusCode,
            $ipAddress,
            $responseTime,
            $errorCode,
            $rateLimitHit
        );
    }

    /**
     * Get current authentication status
     *
     * @return array Authentication status
     */
    public function getAuthStatus(): array {
        return [
            'authenticated' => $this->authenticated,
            'keyData' => $this->keyData,
            'method' => 'api_key'
        ];
    }

    /**
     * Get authenticated partner data
     *
     * @return array|null Partner data or null if not authenticated
     */
    public function getPartnerData(): ?array {
        if (!$this->authenticated || !$this->keyData) {
            return null;
        }

        return [
            'partnerID' => $this->keyData['partnerID'],
            'partnerName' => $this->keyData['partnerName'],
            'accountTier' => $this->keyData['accountTier'],
            'environment' => $this->keyData['environment']
        ];
    }

    // =====================================================
    // PRIVATE HELPER METHODS
    // =====================================================

    /**
     * Get API key from request (header or query param)
     *
     * @return string|null API key or null if not found
     */
    private function getAPIKey(): ?string {
        // Check for API key in X-API-Key header (preferred method)
        if (isset($_SERVER['HTTP_X_API_KEY']) && !empty($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }

        // Check for API key in Authorization header (Bearer token style)
        // 🔒 G-003: a `Authorization: Bearer <jwt>` header carries a JWT ACCESS
        //    TOKEN, NOT an API key. A compact JWT is three base64url segments
        //    (two dots). If we returned it here, handle() would try to validate a
        //    JWT as an API key, fail, and reject every Bearer-JWT request with a
        //    401 — breaking BaseController's JWT auth path. So we IGNORE anything
        //    that looks like a JWT and only treat a NON-JWT Bearer value as an API
        //    key (SIGNula API keys are opaque `sk_...` strings with no dots).
        //    JWT bearer tokens are handled downstream by BaseController::getUserByToken().
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $matches)) {
                $bearerValue = trim($matches[1]);
                // Only treat it as an API key if it is NOT a compact JWT.
                if (substr_count($bearerValue, '.') !== 2) {
                    return $bearerValue;
                }
            }
        }

        // Check for API key in query parameter (less secure, but supported)
        if (isset($_GET['api_key']) && !empty($_GET['api_key'])) {
            return trim($_GET['api_key']);
        }

        return null;
    }

    /**
     * Get client IP address
     * Handles proxy headers and IPv6
     *
     * @return string IP address
     */
    private function getClientIP(): string {
        // Check for IP in various proxy headers (in order of trust)
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_REAL_IP',          // Nginx proxy
            'HTTP_X_FORWARDED_FOR',    // Standard proxy header
            'HTTP_CLIENT_IP',          // Rare
            'REMOTE_ADDR'              // Direct connection
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // Handle comma-separated IPs (X-Forwarded-For can have multiple)
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]); // First IP is the client
                }

                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Get endpoint being accessed
     *
     * @return string Endpoint path
     */
    private function getEndpoint(): string {
        $requestUri = $_SERVER['REQUEST_URI'] ?? 'unknown';

        // Remove query string
        $endpoint = parse_url($requestUri, PHP_URL_PATH);

        // Normalize endpoint
        $endpoint = rtrim($endpoint, '/');

        return $endpoint ?: 'unknown';
    }

    /**
     * Check if usage logging is enabled
     *
     * @return bool Logging enabled
     */
    private function shouldLogUsage(): bool {
        $stmt = $this->db->prepare("
            SELECT settingValue FROM tblSettings
            WHERE settingKey = 'api_keys.log_usage'
        ");

        if (!$stmt) {
            return true; // Default to enabled if can't check
        }

        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return true;
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        return $row['settingValue'] === '1' || $row['settingValue'] === 'true';
    }

    /**
     * Respond with HTTP 401 (Unauthorized)
     *
     * @param string $message Error message
     * @return void Never returns (exits)
     */
    private function respondUnauthorized(string $message = 'Authentication required'): void {
        // Set HTTP status code
        http_response_code(401);

        // Set WWW-Authenticate header
        header('WWW-Authenticate: Bearer realm="SIGNula API"');

        // Set content type
        header('Content-Type: application/json');

        // Build error response
        $response = [
            'success' => false,
            'error' => [
                'code' => 'UNAUTHORIZED',
                'message' => $message,
                'documentation' => 'https://docs.signulo.id/api/authentication'
            ],
            'meta' => [
                'timestamp' => date('c'),
                'request_id' => $this->generateRequestID()
            ]
        ];

        // Output JSON response
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Log if authenticated (failed permission check)
        if ($this->authenticated) {
            $this->logUsage(401, 'UNAUTHORIZED');
        }

        // Exit to prevent further processing
        exit;
    }

    /**
     * Respond with HTTP 403 (Forbidden)
     *
     * @param string $message Error message
     * @return void Never returns (exits)
     */
    private function respondForbidden(string $message = 'Insufficient permissions'): void {
        // Set HTTP status code
        http_response_code(403);

        // Set content type
        header('Content-Type: application/json');

        // Build error response
        $response = [
            'success' => false,
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => $message,
                'documentation' => 'https://docs.signulo.id/api/permissions'
            ],
            'meta' => [
                'timestamp' => date('c'),
                'request_id' => $this->generateRequestID()
            ]
        ];

        // Output JSON response
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Log usage
        $this->logUsage(403, 'FORBIDDEN');

        // Exit to prevent further processing
        exit;
    }

    /**
     * Generate unique request ID for tracking
     *
     * @return string Request ID
     */
    private function generateRequestID(): string {
        return bin2hex(random_bytes(8));
    }
}
