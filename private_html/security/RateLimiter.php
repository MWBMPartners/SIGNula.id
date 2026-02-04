<?php
/**
 * Rate Limiter
 *
 * Implements token bucket algorithm for API rate limiting
 * Prevents abuse, brute force attacks, and DDoS
 *
 * Features:
 * - Token bucket algorithm
 * - Progressive blocking (1min -> 24 hours)
 * - Multi-tier support (IP, User, API Key)
 * - Burst protection
 * - Activity logging
 *
 * @package SIGNula
 * @version 2.2.0-beta
 * @since 2026-02-04
 */

class RateLimiter {
    private $db;
    private $config;

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
        $this->config = $this->loadConfig();
    }

    /**
     * Load rate limiting configuration from database
     *
     * @return array Configuration settings
     */
    private function loadConfig(): array {
        $config = [];

        $stmt = $this->db->prepare("
            SELECT settingKey, settingValue
            FROM tblSettings
            WHERE settingKey LIKE 'rate_limiting.%'
        ");

        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $key = str_replace('rate_limiting.', '', $row['settingKey']);
                $config[$key] = $row['settingValue'];
            }

            $stmt->close();
        }

        return $config;
    }

    /**
     * Check if rate limiting is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool {
        return ($this->config['enabled'] ?? '1') === '1';
    }

    /**
     * Check if request is allowed under rate limit
     *
     * @param string $identifier IP address, userID, or API key hash
     * @param string $identifierType 'ip', 'user', or 'api_key'
     * @param string $endpoint Endpoint being accessed (default: 'global')
     * @param string $tier Rate limit tier (default: 'default')
     * @return array ['allowed' => bool, 'limit' => int, 'remaining' => int, 'reset' => timestamp, 'error' => string]
     */
    public function checkLimit(string $identifier, string $identifierType, string $endpoint = 'global', string $tier = 'default'): array {
        // If rate limiting is disabled, allow all requests
        if (!$this->isEnabled()) {
            return [
                'allowed' => true,
                'limit' => PHP_INT_MAX,
                'remaining' => PHP_INT_MAX,
                'reset' => time() + 3600
            ];
        }

        // Get rate limit configuration for this request
        $limitConfig = $this->getConfig($identifierType, $endpoint, $tier);

        if (!$limitConfig) {
            // No config found, try global config
            $limitConfig = $this->getConfig($identifierType, 'global', $tier);

            if (!$limitConfig) {
                // Still no config, fail open (allow request)
                return [
                    'allowed' => true,
                    'limit' => PHP_INT_MAX,
                    'remaining' => PHP_INT_MAX,
                    'reset' => time() + 3600
                ];
            }
        }

        // Check if currently blocked
        if ($this->isBlocked($identifier, $identifierType, $endpoint)) {
            $blockedUntil = $this->getBlockedUntil($identifier, $identifierType, $endpoint);

            return [
                'allowed' => false,
                'limit' => $limitConfig['requestsPerHour'],
                'remaining' => 0,
                'reset' => $blockedUntil,
                'error' => 'Rate limit exceeded. Access blocked until ' . date('Y-m-d H:i:s', $blockedUntil)
            ];
        }

        // Calculate time windows
        $now = new DateTime();
        $hourStart = (clone $now)->modify('-1 hour');
        $minuteStart = (clone $now)->modify('-1 minute');
        $burstStart = (clone $now)->modify("-{$limitConfig['burstWindow']} seconds");

        // Get request counts for different windows
        $hourCount = $this->getRequestCount($identifier, $identifierType, $endpoint, $hourStart, $now);
        $minuteCount = $this->getRequestCount($identifier, $identifierType, $endpoint, $minuteStart, $now);
        $burstCount = $this->getRequestCount($identifier, $identifierType, $endpoint, $burstStart, $now);

        // Check if any limit is exceeded
        $hourlyExceeded = $hourCount >= $limitConfig['requestsPerHour'];
        $minuteExceeded = $minuteCount >= $limitConfig['requestsPerMinute'];
        $burstExceeded = $burstCount >= $limitConfig['burstLimit'];

        if ($hourlyExceeded || $minuteExceeded || $burstExceeded) {
            // Determine which limit was exceeded
            $limitType = $hourlyExceeded ? 'hourly' : ($minuteExceeded ? 'per-minute' : 'burst');

            // Calculate block duration (progressive blocking)
            $blockDuration = $this->calculateBlockDuration($identifier, $identifierType);

            // Block the identifier
            $this->blockIdentifier($identifier, $identifierType, $endpoint, $blockDuration, $limitType);

            return [
                'allowed' => false,
                'limit' => $limitConfig['requestsPerHour'],
                'remaining' => 0,
                'reset' => time() + $blockDuration,
                'error' => sprintf(
                    'Rate limit exceeded (%s limit). Blocked for %d minutes.',
                    $limitType,
                    ceil($blockDuration / 60)
                )
            ];
        }

        // Request is allowed, record it
        $this->recordRequest($identifier, $identifierType, $endpoint);

        // Calculate remaining requests and reset time
        $remaining = max(0, $limitConfig['requestsPerHour'] - $hourCount - 1);
        $resetTime = strtotime('+1 hour', strtotime($hourStart->format('Y-m-d H:i:s')));

        return [
            'allowed' => true,
            'limit' => $limitConfig['requestsPerHour'],
            'remaining' => $remaining,
            'reset' => $resetTime
        ];
    }

    /**
     * Get rate limit configuration for identifier type and endpoint
     *
     * @param string $identifierType
     * @param string $endpoint
     * @param string $tier
     * @return array|null Configuration or null if not found
     */
    private function getConfig(string $identifierType, string $endpoint, string $tier): ?array {
        $stmt = $this->db->prepare("
            SELECT
                requestsPerHour,
                requestsPerMinute,
                burstLimit,
                burstWindow
            FROM tblRateLimitConfig
            WHERE identifierType = ?
            AND endpoint = ?
            AND tier = ?
            AND isActive = 1
            LIMIT 1
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('sss', $identifierType, $endpoint, $tier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return null;
        }

        $config = $result->fetch_assoc();
        $stmt->close();

        return $config;
    }

    /**
     * Record a request for rate limiting
     *
     * @param string $identifier
     * @param string $identifierType
     * @param string $endpoint
     */
    private function recordRequest(string $identifier, string $identifierType, string $endpoint): void {
        $stmt = $this->db->prepare("
            INSERT INTO tblRateLimits
            (identifier, identifierType, endpoint, requestCount, windowStart, windowEnd)
            VALUES (?, ?, ?, 1, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))
            ON DUPLICATE KEY UPDATE
                requestCount = requestCount + 1,
                updatedAt = NOW()
        ");

        if ($stmt) {
            $stmt->bind_param('sss', $identifier, $identifierType, $endpoint);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Get request count in time window
     *
     * @param string $identifier
     * @param string $identifierType
     * @param string $endpoint
     * @param DateTime $start
     * @param DateTime $end
     * @return int Total request count
     */
    private function getRequestCount(string $identifier, string $identifierType, string $endpoint, DateTime $start, DateTime $end): int {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(requestCount), 0) as total
            FROM tblRateLimits
            WHERE identifier = ?
            AND identifierType = ?
            AND endpoint = ?
            AND windowStart >= ?
            AND windowStart <= ?
        ");

        if (!$stmt) {
            return 0;
        }

        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');

        $stmt->bind_param('sssss', $identifier, $identifierType, $endpoint, $startStr, $endStr);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int)($row['total'] ?? 0);
    }

    /**
     * Check if identifier is currently blocked
     *
     * @param string $identifier
     * @param string $identifierType
     * @param string $endpoint
     * @return bool
     */
    private function isBlocked(string $identifier, string $identifierType, string $endpoint): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as blocked
            FROM tblRateLimits
            WHERE identifier = ?
            AND identifierType = ?
            AND endpoint = ?
            AND isBlocked = 1
            AND (blockedUntil IS NULL OR blockedUntil > NOW())
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('sss', $identifier, $identifierType, $endpoint);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return ($row['blocked'] ?? 0) > 0;
    }

    /**
     * Get timestamp when block expires
     *
     * @param string $identifier
     * @param string $identifierType
     * @param string $endpoint
     * @return int Unix timestamp
     */
    private function getBlockedUntil(string $identifier, string $identifierType, string $endpoint): int {
        $stmt = $this->db->prepare("
            SELECT UNIX_TIMESTAMP(blockedUntil) as blockedUntil
            FROM tblRateLimits
            WHERE identifier = ?
            AND identifierType = ?
            AND endpoint = ?
            AND isBlocked = 1
            ORDER BY blockedUntil DESC
            LIMIT 1
        ");

        if (!$stmt) {
            return time() + 3600; // Default to 1 hour from now
        }

        $stmt->bind_param('sss', $identifier, $identifierType, $endpoint);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return time() + 3600;
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        return (int)($row['blockedUntil'] ?? time() + 3600);
    }

    /**
     * Block identifier for specified duration
     *
     * @param string $identifier
     * @param string $identifierType
     * @param string $endpoint
     * @param int $duration Duration in seconds
     * @param string $reason Block reason
     */
    private function blockIdentifier(string $identifier, string $identifierType, string $endpoint, int $duration, string $reason = 'Rate limit exceeded'): void {
        $stmt = $this->db->prepare("
            INSERT INTO tblRateLimits
            (identifier, identifierType, endpoint, requestCount, windowStart, windowEnd, isBlocked, blockedUntil, blockReason)
            VALUES (?, ?, ?, 0, NOW(), NOW(), 1, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)
            ON DUPLICATE KEY UPDATE
                isBlocked = 1,
                blockedUntil = DATE_ADD(NOW(), INTERVAL ? SECOND),
                blockReason = ?,
                updatedAt = NOW()
        ");

        if ($stmt) {
            $stmt->bind_param('sssisis', $identifier, $identifierType, $endpoint, $duration, $reason, $duration, $reason);
            $stmt->execute();
            $stmt->close();
        }

        // Log blocking event
        $this->logBlockEvent($identifier, $identifierType, $endpoint, $duration, $reason);
    }

    /**
     * Calculate block duration based on violation history
     * Progressive blocking: 1 min, 5 min, 15 min, 1 hour, 24 hours
     *
     * @param string $identifier
     * @param string $identifierType
     * @return int Duration in seconds
     */
    private function calculateBlockDuration(string $identifier, string $identifierType): int {
        // Check if progressive blocking is enabled
        if (($this->config['progressive_blocking'] ?? '1') !== '1') {
            return 3600; // Default 1 hour
        }

        // Get recent violations count (last 24 hours)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as violations
            FROM tblRateLimits
            WHERE identifier = ?
            AND identifierType = ?
            AND isBlocked = 1
            AND blockedUntil > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");

        if (!$stmt) {
            return 3600; // Default 1 hour
        }

        $stmt->bind_param('ss', $identifier, $identifierType);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $violations = (int)($row['violations'] ?? 0);

        // Progressive blocking durations
        $durations = [
            0 => 60,       // 1 minute (first violation)
            1 => 300,      // 5 minutes (second violation)
            2 => 900,      // 15 minutes (third violation)
            3 => 3600,     // 1 hour (fourth violation)
        ];

        // Get max block duration from config (default 24 hours)
        $maxDuration = (int)($this->config['max_block_duration_hours'] ?? 24) * 3600;

        // Return appropriate duration, capping at max
        if (isset($durations[$violations])) {
            return $durations[$violations];
        }

        return min($maxDuration, 86400); // Cap at 24 hours or config max
    }

    /**
     * Log block event to activity log
     *
     * @param string $identifier
     * @param string $identifierType
     * @param string $endpoint
     * @param int $duration
     * @param string $reason
     */
    private function logBlockEvent(string $identifier, string $identifierType, string $endpoint, int $duration, string $reason): void {
        // Only log if ActivityLogger is available
        if (!class_exists('ActivityLogger')) {
            return;
        }

        ActivityLogger::log([
            'activityType' => 'rate_limit_block',
            'activityResult' => 'blocked',
            'activityDetails' => json_encode([
                'identifier' => $identifierType === 'ip' ? $identifier : substr($identifier, 0, 16) . '...',
                'identifierType' => $identifierType,
                'endpoint' => $endpoint,
                'duration' => $duration,
                'reason' => $reason
            ]),
            'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }

    /**
     * Unblock identifier (admin function)
     *
     * @param string $identifier
     * @param string $identifierType
     * @param string $endpoint
     * @return bool Success status
     */
    public function unblock(string $identifier, string $identifierType, string $endpoint = 'global'): bool {
        $stmt = $this->db->prepare("
            UPDATE tblRateLimits
            SET isBlocked = 0,
                blockedUntil = NULL,
                blockReason = NULL
            WHERE identifier = ?
            AND identifierType = ?
            AND endpoint = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('sss', $identifier, $identifierType, $endpoint);
        $success = $stmt->execute();
        $stmt->close();

        if ($success && class_exists('ActivityLogger')) {
            ActivityLogger::log([
                'activityType' => 'rate_limit_unblock',
                'activityResult' => 'success',
                'activityDetails' => json_encode([
                    'identifier' => $identifierType === 'ip' ? $identifier : substr($identifier, 0, 16) . '...',
                    'identifierType' => $identifierType,
                    'endpoint' => $endpoint
                ]),
                'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        }

        return $success;
    }

    /**
     * Get rate limit status for identifier
     * Useful for debugging and monitoring
     *
     * @param string $identifier
     * @param string $identifierType
     * @param string $endpoint
     * @return array Status information
     */
    public function getStatus(string $identifier, string $identifierType, string $endpoint = 'global'): array {
        $now = new DateTime();
        $hourStart = (clone $now)->modify('-1 hour');

        $hourCount = $this->getRequestCount($identifier, $identifierType, $endpoint, $hourStart, $now);
        $isBlocked = $this->isBlocked($identifier, $identifierType, $endpoint);
        $blockedUntil = $isBlocked ? $this->getBlockedUntil($identifier, $identifierType, $endpoint) : null;

        $config = $this->getConfig($identifierType, $endpoint, 'default') ??
                  $this->getConfig($identifierType, 'global', 'default');

        return [
            'identifier' => $identifierType === 'ip' ? $identifier : substr($identifier, 0, 16) . '...',
            'identifierType' => $identifierType,
            'endpoint' => $endpoint,
            'requestCount' => $hourCount,
            'limit' => $config['requestsPerHour'] ?? 0,
            'remaining' => max(0, ($config['requestsPerHour'] ?? 0) - $hourCount),
            'isBlocked' => $isBlocked,
            'blockedUntil' => $blockedUntil ? date('Y-m-d H:i:s', $blockedUntil) : null
        ];
    }
}
