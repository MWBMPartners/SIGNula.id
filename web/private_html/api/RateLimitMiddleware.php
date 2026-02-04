<?php
/**
 * Rate Limit Middleware
 *
 * Applies rate limiting to all API requests before processing
 * Integrates with Router to protect all endpoints
 *
 * Features:
 * - Automatic identifier detection (IP, User, API Key)
 * - Rate limit headers (X-RateLimit-*)
 * - HTTP 429 response for exceeded limits
 * - Retry-After header
 *
 * @package SIGNula
 * @version 2.2.0-beta
 * @since 2026-02-04
 */

class RateLimitMiddleware {
    private $rateLimiter;
    private $db;

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

        // Initialize rate limiter
        require_once __DIR__ . '/../../private_html/security/RateLimiter.php';
        $this->rateLimiter = new RateLimiter($this->db);
    }

    /**
     * Handle rate limiting for current request
     * Call this before processing API request
     *
     * @return void Exits with 429 if rate limit exceeded
     */
    public function handle(): void {
        // Skip if rate limiting is disabled
        if (!$this->rateLimiter->isEnabled()) {
            return;
        }

        // Determine identifier and type
        list($identifier, $identifierType) = $this->getIdentifier();

        // Get endpoint being accessed
        $endpoint = $this->getEndpoint();

        // Get tier for this request
        $tier = $this->getTier($identifierType);

        // Check rate limit
        $result = $this->rateLimiter->checkLimit($identifier, $identifierType, $endpoint, $tier);

        // Add rate limit headers to response
        $this->setRateLimitHeaders($result);

        // Block if rate limit exceeded
        if (!$result['allowed']) {
            $this->respondRateLimitExceeded($result);
        }
    }

    /**
     * Get identifier and type for current request
     *
     * @return array [identifier, identifierType]
     */
    private function getIdentifier(): array {
        // Check for API key in header
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
        if (!$apiKey) {
            // Check in query parameter (less secure, but supported)
            $apiKey = $_GET['api_key'] ?? null;
        }

        if ($apiKey) {
            // Hash the API key for privacy
            return [hash('sha256', $apiKey), 'api_key'];
        }

        // Check for authenticated user (session-based)
        if (isset($_SESSION['userID']) && $_SESSION['userID']) {
            return ['user_' . $_SESSION['userID'], 'user'];
        }

        // Fall back to IP address
        $ipAddress = $this->getClientIP();
        return [$ipAddress, 'ip'];
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
        $requestUri = $_SERVER['REQUEST_URI'] ?? 'global';

        // Remove query string
        $endpoint = parse_url($requestUri, PHP_URL_PATH);

        // Normalize endpoint
        $endpoint = rtrim($endpoint, '/');

        // Return empty as 'global'
        return $endpoint ?: 'global';
    }

    /**
     * Get rate limit tier for identifier type
     *
     * @param string $identifierType
     * @return string Tier name
     */
    private function getTier(string $identifierType): string {
        switch ($identifierType) {
            case 'api_key':
                // Look up API key tier from database (if APIKeyManager available)
                if (class_exists('APIKeyManager')) {
                    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
                    if ($apiKey) {
                        $keyManager = new APIKeyManager($this->db);
                        $keyData = $keyManager->validateKey($apiKey);
                        if ($keyData) {
                            return $keyData['accountTier'] ?? 'free';
                        }
                    }
                }
                return 'free'; // Default for API keys

            case 'user':
                // Get user's account tier from session or database
                if (isset($_SESSION['accountTier'])) {
                    return $_SESSION['accountTier'];
                }

                // Look up from database
                if (isset($_SESSION['userID'])) {
                    $stmt = $this->db->prepare("SELECT accountTier FROM tblUsers WHERE userID = ?");
                    if ($stmt) {
                        $stmt->bind_param('i', $_SESSION['userID']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($row = $result->fetch_assoc()) {
                            return $row['accountTier'] ?? 'free';
                        }
                        $stmt->close();
                    }
                }
                return 'free'; // Default for users

            case 'ip':
            default:
                return 'default'; // IP-based requests use default tier
        }
    }

    /**
     * Set rate limit headers in response
     *
     * @param array $result Rate limit check result
     */
    private function setRateLimitHeaders(array $result): void {
        // Standard rate limit headers
        header('X-RateLimit-Limit: ' . ($result['limit'] ?? 0));
        header('X-RateLimit-Remaining: ' . ($result['remaining'] ?? 0));
        header('X-RateLimit-Reset: ' . ($result['reset'] ?? time()));

        // If not allowed, add Retry-After
        if (!$result['allowed']) {
            $retryAfter = max(0, ($result['reset'] ?? time()) - time());
            header('Retry-After: ' . $retryAfter);
        }
    }

    /**
     * Respond with HTTP 429 (Too Many Requests)
     *
     * @param array $result Rate limit check result
     * @return void Never returns (exits)
     */
    private function respondRateLimitExceeded(array $result): void {
        // Set HTTP status code
        http_response_code(429);

        // Set content type
        header('Content-Type: application/json');

        // Calculate retry after
        $retryAfter = max(0, ($result['reset'] ?? time()) - time());

        // Build error response
        $response = [
            'success' => false,
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => $result['error'] ?? 'Too many requests. Please try again later.',
                'retry_after' => $retryAfter,
                'retry_after_human' => $this->formatDuration($retryAfter)
            ],
            'rate_limit' => [
                'limit' => $result['limit'] ?? 0,
                'remaining' => 0,
                'reset' => $result['reset'] ?? time(),
                'reset_human' => date('Y-m-d H:i:s', $result['reset'] ?? time())
            ],
            'meta' => [
                'timestamp' => date('c'),
                'request_id' => $this->generateRequestID()
            ]
        ];

        // Output JSON response
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Exit to prevent further processing
        exit;
    }

    /**
     * Format duration in seconds to human-readable string
     *
     * @param int $seconds Duration in seconds
     * @return string Formatted duration
     */
    private function formatDuration(int $seconds): string {
        if ($seconds < 60) {
            return $seconds . ' second' . ($seconds !== 1 ? 's' : '');
        }

        $minutes = floor($seconds / 60);
        if ($minutes < 60) {
            return $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
        }

        $hours = floor($minutes / 60);
        if ($hours < 24) {
            return $hours . ' hour' . ($hours !== 1 ? 's' : '');
        }

        $days = floor($hours / 24);
        return $days . ' day' . ($days !== 1 ? 's' : '');
    }

    /**
     * Generate unique request ID for tracking
     *
     * @return string Request ID
     */
    private function generateRequestID(): string {
        return bin2hex(random_bytes(8));
    }

    /**
     * Get current rate limit status (for debugging/monitoring)
     *
     * @return array Status information
     */
    public function getStatus(): array {
        list($identifier, $identifierType) = $this->getIdentifier();
        $endpoint = $this->getEndpoint();

        return $this->rateLimiter->getStatus($identifier, $identifierType, $endpoint);
    }
}
