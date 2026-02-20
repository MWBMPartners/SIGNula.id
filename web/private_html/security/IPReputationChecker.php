<?php
/**
 * ============================================================================
 * 🌐 SIGNula - IP Reputation Checker
 * ============================================================================
 *
 * Purpose: IP reputation checking with AbuseIPDB and proxycheck.io integration,
 *          local caching, circuit breaker pattern, and IP blocklist management
 * PHP Version: 8.3+
 *
 * Checks incoming IP addresses against external reputation APIs and a local
 * blocklist. Results are cached in the database to minimise API calls. A circuit
 * breaker pattern prevents cascading failures when external APIs are unavailable.
 *
 * External APIs:
 *   - AbuseIPDB (free 4,999 checks/day) — abuse confidence scoring
 *   - proxycheck.io (free 1,000 checks/day) — proxy/VPN/Tor detection
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.6.0-beta
 *
 * @see https://www.abuseipdb.com/api
 * @see https://proxycheck.io/api/
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
 * 🌐 IP Reputation Checker
 *
 * Checks IP addresses against external reputation APIs with local caching,
 * circuit breaker pattern, and blocklist management. All methods are static
 * following the SIGNula security class pattern.
 */
class IPReputationChecker
{
    // ========================================================================
    // 🔗 API Endpoints
    // ========================================================================

    /** @var string AbuseIPDB check endpoint */
    private const ABUSEIPDB_CHECK_URL = 'https://api.abuseipdb.com/api/v2/check';

    /** @var string AbuseIPDB report endpoint */
    private const ABUSEIPDB_REPORT_URL = 'https://api.abuseipdb.com/api/v2/report';

    /** @var string proxycheck.io base URL (append IP address) */
    private const PROXYCHECK_BASE_URL = 'https://proxycheck.io/v2/';

    // ========================================================================
    // ⚙️ Configuration Constants
    // ========================================================================

    /** @var int cURL timeout for API requests (seconds) */
    private const API_TIMEOUT_SECONDS = 3;

    /** @var int Default cache TTL in seconds (24 hours) */
    private const DEFAULT_CACHE_TTL = 86400;

    /** @var int Consecutive failures before circuit opens */
    private const CIRCUIT_BREAKER_THRESHOLD = 3;

    /** @var int Default abuse score threshold (0-100) */
    private const DEFAULT_ABUSE_SCORE_THRESHOLD = 50;

    // ========================================================================
    // 🔓 Public Methods
    // ========================================================================

    /**
     * 🔍 Check if IP reputation checking is enabled
     *
     * @return bool True if enabled via tblSettings
     */
    public static function isEnabled(): bool
    {
        return (bool) getSetting('security.ip_reputation.enabled', false);
    }

    /**
     * 🔎 Perform a full IP reputation check
     *
     * Pipeline:
     * 1. Skip private/reserved IPs (always allowed)
     * 2. Check tblBlockedIPs for explicit blocks
     * 3. Check tblIPReputationCache for cached results
     * 4. Query AbuseIPDB (if API key configured + circuit closed)
     * 5. Query proxycheck.io (if API key configured + circuit closed)
     * 6. Cache results and return decision
     *
     * Graceful degradation: if all APIs fail, returns allowed=true
     *
     * @param string $ip IPv4 or IPv6 address to check
     * @return array{allowed: bool, reason: ?string, score: ?int, is_proxy: ?bool, is_vpn: ?bool, is_tor: ?bool, source: string, cached: bool}
     */
    public static function check(string $ip): array
    {
        // 🏠 Skip private/reserved IPs — they're always local/trusted
        if (self::isPrivateIP($ip)) {
            return [
                'allowed'  => true,
                'reason'   => null,
                'score'    => 0,
                'is_proxy' => false,
                'is_vpn'   => false,
                'is_tor'   => false,
                'source'   => 'private_ip',
                'cached'   => false,
            ];
        }

        // ✅ Check whitelist
        $whitelist = json_decode(getSetting('security.ip_reputation.whitelist', '[]'), true);
        if (is_array($whitelist) && in_array($ip, $whitelist, true)) {
            return [
                'allowed'  => true,
                'reason'   => null,
                'score'    => 0,
                'is_proxy' => false,
                'is_vpn'   => false,
                'is_tor'   => false,
                'source'   => 'whitelist',
                'cached'   => false,
            ];
        }

        // 🚫 Check explicit IP blocklist first (fastest — DB only)
        if (self::isBlocked($ip)) {
            return [
                'allowed'  => false,
                'reason'   => 'IP address is blocked',
                'score'    => 100,
                'is_proxy' => null,
                'is_vpn'   => null,
                'is_tor'   => null,
                'source'   => 'blocklist',
                'cached'   => false,
            ];
        }

        // 📦 Check cache for recent API results
        $cached = self::checkCache($ip);
        if ($cached !== null) {
            return $cached;
        }

        // 📡 Query external APIs (in order of preference)
        $result = null;

        // 🔍 Try AbuseIPDB first
        $abuseipdbKey = getSetting('security.ip_reputation.abuseipdb_api_key', '');
        if (!empty($abuseipdbKey) && !self::isCircuitOpen('abuseipdb')) {
            $result = self::queryAbuseIPDB($ip, $abuseipdbKey);
        }

        // 🔍 Try proxycheck.io (for proxy/VPN/Tor detection or as fallback)
        $proxycheckKey = getSetting('security.ip_reputation.proxycheck_api_key', '');
        if (!empty($proxycheckKey) && !self::isCircuitOpen('proxycheck')) {
            $proxyResult = self::queryProxyCheck($ip, $proxycheckKey);

            if ($proxyResult !== null) {
                if ($result !== null) {
                    // 🔀 Merge proxy data into AbuseIPDB result
                    $result['is_proxy'] = $proxyResult['is_proxy'] ?? $result['is_proxy'];
                    $result['is_vpn'] = $proxyResult['is_vpn'] ?? $result['is_vpn'];
                    $result['is_tor'] = $proxyResult['is_tor'] ?? $result['is_tor'];
                    $result['source'] = 'abuseipdb+proxycheck';
                } else {
                    // 📌 proxycheck.io is our only data source
                    $result = $proxyResult;
                }
            }
        }

        // ⚠️ No API data available — fail open
        if ($result === null) {
            return [
                'allowed'  => true,
                'reason'   => null,
                'score'    => null,
                'is_proxy' => null,
                'is_vpn'   => null,
                'is_tor'   => null,
                'source'   => 'fallback',
                'cached'   => false,
            ];
        }

        // 💾 Cache the result
        self::saveCache($ip, $result);

        // 📊 Apply blocking rules
        $result['allowed'] = self::evaluateResult($result);
        if (!$result['allowed']) {
            $result['reason'] = self::buildBlockReason($result);
        }
        $result['cached'] = false;

        return $result;
    }

    /**
     * 🚫 Quick check if an IP is in the blocklist
     *
     * DB-only check (no API calls) for use in hot paths where
     * API latency is unacceptable.
     *
     * @param string $ip IPv4 or IPv6 address
     * @return bool True if the IP is actively blocked
     */
    public static function isBlocked(string $ip): bool
    {
        if (!class_exists('Database')) {
            return false;
        }

        try {
            $result = Database::fetchOne(
                "SELECT `blockID` FROM `tblBlockedIPs`
                 WHERE `ipAddress` = ? AND `isActive` = 1
                 AND (`expiresAt` IS NULL OR `expiresAt` > NOW())
                 LIMIT 1",
                [$ip],
                's'
            );

            return ($result !== null);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 🔒 Block an IP address
     *
     * @param string   $ip              IPv4 or IPv6 address
     * @param string   $reason          Human-readable reason for blocking
     * @param int|null $durationSeconds  Block duration in seconds (null = permanent)
     * @param int|null $blockedByUserID  Admin user ID (null = auto-block)
     * @param string   $source          Block source: manual, rate_limit, ip_reputation, etc.
     * @return bool True if the block was created successfully
     */
    public static function blockIP(
        string $ip,
        string $reason,
        ?int $durationSeconds = null,
        ?int $blockedByUserID = null,
        string $source = 'manual'
    ): bool {
        if (!class_exists('Database')) {
            return false;
        }

        try {
            // 🔧 Determine block type
            $blockType = ($durationSeconds === null) ? 'permanent' : 'temporary';
            if ($blockedByUserID === null && $blockType === 'temporary') {
                $blockType = 'auto';
            }

            // ⏱️ Calculate expiry
            $expiresAt = null;
            if ($durationSeconds !== null) {
                $expiresAt = date('Y-m-d H:i:s', time() + $durationSeconds);
            }

            // 💾 Insert or update the block
            Database::query(
                "INSERT INTO `tblBlockedIPs` (`ipAddress`, `blockType`, `reason`, `source`, `blockedByUserID`, `expiresAt`, `isActive`)
                 VALUES (?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    `reason` = VALUES(`reason`),
                    `source` = VALUES(`source`),
                    `blockType` = VALUES(`blockType`),
                    `expiresAt` = VALUES(`expiresAt`),
                    `blockedByUserID` = VALUES(`blockedByUserID`),
                    `isActive` = 1,
                    `blockedAt` = NOW()",
                [$ip, $blockType, $reason, $source, $blockedByUserID, $expiresAt],
                'ssssis'
            );

            // 📋 Log the action
            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    $blockedByUserID,
                    'ip_blocked',
                    'security',
                    'warning',
                    'IP address blocked: ' . $ip . ' — Reason: ' . $reason,
                    [
                        'ipAddress'   => $ip,
                        'reason'      => $reason,
                        'source'      => $source,
                        'blockType'   => $blockType,
                        'expiresAt'   => $expiresAt,
                        'durationSec' => $durationSeconds,
                    ]
                );
            }

            return true;
        } catch (\Exception $e) {
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError(
                    'ip_block_failed',
                    'Failed to block IP ' . $ip . ': ' . $e->getMessage(),
                    __FILE__,
                    __LINE__,
                    'error'
                );
            }
            return false;
        }
    }

    /**
     * 🔓 Unblock an IP address
     *
     * @param string   $ip                IPv4 or IPv6 address
     * @param int|null $unblockedByUserID  Admin user ID
     * @param string   $reason            Reason for unblocking
     * @return bool True if the block was removed successfully
     */
    public static function unblockIP(string $ip, ?int $unblockedByUserID = null, string $reason = ''): bool
    {
        if (!class_exists('Database')) {
            return false;
        }

        try {
            Database::query(
                "UPDATE `tblBlockedIPs`
                 SET `isActive` = 0, `unblockedAt` = NOW(), `unblockedByUserID` = ?, `unblockedReason` = ?
                 WHERE `ipAddress` = ? AND `isActive` = 1",
                [$unblockedByUserID, $reason, $ip],
                'iss'
            );

            // 📋 Log the action
            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    $unblockedByUserID,
                    'ip_unblocked',
                    'security',
                    'info',
                    'IP address unblocked: ' . $ip,
                    ['ipAddress' => $ip, 'reason' => $reason]
                );
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 📤 Report an abusive IP to AbuseIPDB
     *
     * @see https://www.abuseipdb.com/categories
     *
     * @param string $ip         IP address to report
     * @param array  $categories AbuseIPDB category IDs (e.g. [18] for brute force)
     * @param string $comment    Description of the abuse
     * @return bool True if report was submitted successfully
     */
    public static function reportAbuse(string $ip, array $categories, string $comment): bool
    {
        // 📋 Check if reporting is enabled
        if (!(bool) getSetting('security.ip_reputation.report_enabled', false)) {
            return false;
        }

        $apiKey = getSetting('security.ip_reputation.abuseipdb_api_key', '');
        if (empty($apiKey)) {
            return false;
        }

        if (!function_exists('curl_init')) {
            return false;
        }

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => self::ABUSEIPDB_REPORT_URL,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'ip'         => $ip,
                    'categories' => implode(',', $categories),
                    'comment'    => $comment,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::API_TIMEOUT_SECONDS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => [
                    'Key: ' . $apiKey,
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ($response !== false && $httpCode === 200);
        } catch (\Exception $e) {
            return false;
        }
    }

    // ========================================================================
    // 🔒 Private Methods — Cache
    // ========================================================================

    /**
     * 📦 Check the cache for recent IP reputation data
     *
     * @param string $ip IP address to look up
     * @return array|null Cached result array, or null if not found/expired
     */
    private static function checkCache(string $ip): ?array
    {
        if (!class_exists('Database')) {
            return null;
        }

        try {
            $row = Database::fetchOne(
                "SELECT `abuseConfidenceScore`, `totalReports`, `isProxy`, `isVPN`, `isTor`,
                        `proxyType`, `provider`, `countryCode`, `apiSource`
                 FROM `tblIPReputationCache`
                 WHERE `ipAddress` = ? AND `expiresAt` > NOW()
                 ORDER BY `cachedAt` DESC
                 LIMIT 1",
                [$ip],
                's'
            );

            if ($row === null) {
                return null;
            }

            $result = [
                'score'    => $row['abuseConfidenceScore'] !== null ? (int) $row['abuseConfidenceScore'] : null,
                'is_proxy' => (bool) $row['isProxy'],
                'is_vpn'   => (bool) $row['isVPN'],
                'is_tor'   => (bool) $row['isTor'],
                'source'   => $row['apiSource'] . '_cache',
                'cached'   => true,
                'reason'   => null,
            ];

            // 📊 Apply blocking rules to cached data
            $result['allowed'] = self::evaluateResult($result);
            if (!$result['allowed']) {
                $result['reason'] = self::buildBlockReason($result);
            }

            return $result;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 💾 Save IP reputation data to the cache
     *
     * @param string $ip     IP address
     * @param array  $result Reputation data to cache
     */
    private static function saveCache(string $ip, array $result): void
    {
        if (!class_exists('Database')) {
            return;
        }

        try {
            $cacheTTL = (int) getSetting('security.ip_reputation.cache_ttl_hours', 24) * 3600;
            $expiresAt = date('Y-m-d H:i:s', time() + $cacheTTL);
            $apiSource = str_replace('_cache', '', $result['source'] ?? 'local');

            // 🔀 Normalise source name for ENUM column
            if (strpos($apiSource, '+') !== false) {
                $apiSource = 'abuseipdb'; // Use primary source for combined results
            }
            if (!in_array($apiSource, ['abuseipdb', 'proxycheck', 'local', 'manual'], true)) {
                $apiSource = 'local';
            }

            Database::query(
                "INSERT INTO `tblIPReputationCache`
                    (`ipAddress`, `abuseConfidenceScore`, `totalReports`, `isProxy`, `isVPN`, `isTor`,
                     `proxyType`, `apiSource`, `expiresAt`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    `abuseConfidenceScore` = VALUES(`abuseConfidenceScore`),
                    `totalReports` = VALUES(`totalReports`),
                    `isProxy` = VALUES(`isProxy`),
                    `isVPN` = VALUES(`isVPN`),
                    `isTor` = VALUES(`isTor`),
                    `proxyType` = VALUES(`proxyType`),
                    `cachedAt` = NOW(),
                    `expiresAt` = VALUES(`expiresAt`)",
                [
                    $ip,
                    $result['score'] ?? null,
                    $result['total_reports'] ?? 0,
                    $result['is_proxy'] ? 1 : 0,
                    $result['is_vpn'] ? 1 : 0,
                    $result['is_tor'] ? 1 : 0,
                    $result['proxy_type'] ?? null,
                    $apiSource,
                    $expiresAt,
                ],
                'siiiisss'
            );
        } catch (\Exception $e) {
            // 📝 Cache save failure is non-critical — log but don't propagate
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError(
                    'ip_cache_save_failed',
                    'Failed to cache IP reputation for ' . $ip . ': ' . $e->getMessage(),
                    __FILE__,
                    __LINE__,
                    'warning'
                );
            }
        }
    }

    // ========================================================================
    // 🔒 Private Methods — API Queries
    // ========================================================================

    /**
     * 🔍 Query AbuseIPDB for IP reputation data
     *
     * @see https://docs.abuseipdb.com/#check-endpoint
     *
     * @param string $ip     IP address to check
     * @param string $apiKey AbuseIPDB API key
     * @return array|null Result array, or null on failure
     */
    private static function queryAbuseIPDB(string $ip, string $apiKey): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        try {
            $url = self::ABUSEIPDB_CHECK_URL . '?' . http_build_query([
                'ipAddress'    => $ip,
                'maxAgeInDays' => 90,
                'verbose'      => '',
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::API_TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => self::API_TIMEOUT_SECONDS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => [
                    'Key: ' . $apiKey,
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // ⚠️ Request failed
            if ($response === false || !empty($curlError) || $httpCode !== 200) {
                self::recordCircuitFailure('abuseipdb');
                return null;
            }

            // 📋 Parse response
            $data = json_decode($response, true);
            if (!is_array($data) || !isset($data['data'])) {
                self::recordCircuitFailure('abuseipdb');
                return null;
            }

            // ✅ Success — reset circuit
            self::resetCircuit('abuseipdb');

            $apiData = $data['data'];

            return [
                'score'         => (int) ($apiData['abuseConfidenceScore'] ?? 0),
                'total_reports' => (int) ($apiData['totalReports'] ?? 0),
                'is_proxy'      => false, // AbuseIPDB doesn't directly detect proxies
                'is_vpn'        => false,
                'is_tor'        => (bool) ($apiData['isTor'] ?? false),
                'proxy_type'    => null,
                'usage_type'    => $apiData['usageType'] ?? null,
                'country_code'  => $apiData['countryCode'] ?? null,
                'source'        => 'abuseipdb',
                'reason'        => null,
                'allowed'       => true,
            ];
        } catch (\Exception $e) {
            self::recordCircuitFailure('abuseipdb');
            return null;
        }
    }

    /**
     * 🔍 Query proxycheck.io for proxy/VPN/Tor detection
     *
     * @see https://proxycheck.io/api/
     *
     * @param string $ip     IP address to check
     * @param string $apiKey proxycheck.io API key
     * @return array|null Result array, or null on failure
     */
    private static function queryProxyCheck(string $ip, string $apiKey): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        try {
            $url = self::PROXYCHECK_BASE_URL . urlencode($ip) . '?' . http_build_query([
                'key'  => $apiKey,
                'vpn'  => 1,
                'asn'  => 1,
                'risk' => 1,
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::API_TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => self::API_TIMEOUT_SECONDS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // ⚠️ Request failed
            if ($response === false || !empty($curlError) || $httpCode !== 200) {
                self::recordCircuitFailure('proxycheck');
                return null;
            }

            // 📋 Parse response
            $data = json_decode($response, true);
            if (!is_array($data) || ($data['status'] ?? '') !== 'ok') {
                self::recordCircuitFailure('proxycheck');
                return null;
            }

            // ✅ Success — reset circuit
            self::resetCircuit('proxycheck');

            // 📌 proxycheck.io nests data under the IP key
            $ipData = $data[$ip] ?? [];
            $proxyType = strtolower($ipData['type'] ?? '');

            return [
                'score'      => null,
                'is_proxy'   => ($ipData['proxy'] ?? 'no') === 'yes',
                'is_vpn'     => ($proxyType === 'vpn'),
                'is_tor'     => ($proxyType === 'tor'),
                'proxy_type' => $ipData['type'] ?? null,
                'source'     => 'proxycheck',
                'reason'     => null,
                'allowed'    => true,
            ];
        } catch (\Exception $e) {
            self::recordCircuitFailure('proxycheck');
            return null;
        }
    }

    // ========================================================================
    // 🔒 Private Methods — Circuit Breaker
    // ========================================================================

    /**
     * ⚡ Check if a circuit breaker is open (API should be skipped)
     *
     * @param string $service Service name (e.g. 'abuseipdb', 'proxycheck')
     * @return bool True if circuit is open (skip API calls)
     */
    private static function isCircuitOpen(string $service): bool
    {
        if (!class_exists('Database')) {
            return false;
        }

        try {
            $row = Database::fetchOne(
                "SELECT `circuitState`, `failureCount`
                 FROM `tblCircuitBreaker`
                 WHERE `serviceName` = ?
                 LIMIT 1",
                [$service],
                's'
            );

            if ($row === null) {
                return false;
            }

            // 🔴 Open circuit — skip API calls
            if ($row['circuitState'] === 'open') {
                return true;
            }

            // 🟡 Half-open — allow one test request through
            // (handled normally; if it fails, circuit re-opens)
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * ❌ Record a circuit breaker failure
     *
     * Increments failure count. If threshold reached, opens the circuit.
     *
     * @param string $service Service name
     */
    private static function recordCircuitFailure(string $service): void
    {
        if (!class_exists('Database')) {
            return;
        }

        try {
            // 📊 Increment failure count
            Database::query(
                "UPDATE `tblCircuitBreaker`
                 SET `failureCount` = `failureCount` + 1,
                     `lastFailureAt` = NOW()
                 WHERE `serviceName` = ?",
                [$service],
                's'
            );

            // 🔴 Check if threshold reached — open circuit
            $row = Database::fetchOne(
                "SELECT `failureCount` FROM `tblCircuitBreaker` WHERE `serviceName` = ?",
                [$service],
                's'
            );

            if ($row !== null && (int) $row['failureCount'] >= self::CIRCUIT_BREAKER_THRESHOLD) {
                Database::query(
                    "UPDATE `tblCircuitBreaker`
                     SET `circuitState` = 'open', `stateChangedAt` = NOW()
                     WHERE `serviceName` = ?",
                    [$service],
                    's'
                );
            }
        } catch (\Exception $e) {
            // 📝 Circuit breaker failure is non-critical
        }
    }

    /**
     * ✅ Reset a circuit breaker after a successful API call
     *
     * @param string $service Service name
     */
    private static function resetCircuit(string $service): void
    {
        if (!class_exists('Database')) {
            return;
        }

        try {
            Database::query(
                "UPDATE `tblCircuitBreaker`
                 SET `failureCount` = 0,
                     `circuitState` = 'closed',
                     `lastSuccessAt` = NOW(),
                     `stateChangedAt` = NOW()
                 WHERE `serviceName` = ?",
                [$service],
                's'
            );
        } catch (\Exception $e) {
            // 📝 Non-critical
        }
    }

    // ========================================================================
    // 🔒 Private Methods — Decision Logic
    // ========================================================================

    /**
     * 📊 Evaluate API results against configured blocking thresholds
     *
     * @param array $result Reputation data
     * @return bool True if the IP should be allowed
     */
    private static function evaluateResult(array $result): bool
    {
        // 📊 Check abuse score threshold
        $scoreThreshold = (int) getSetting(
            'security.ip_reputation.abuse_score_threshold',
            self::DEFAULT_ABUSE_SCORE_THRESHOLD
        );

        if ($result['score'] !== null && (int) $result['score'] >= $scoreThreshold) {
            return false;
        }

        // 🔍 Check proxy blocking
        if ($result['is_proxy'] && (bool) getSetting('security.ip_reputation.block_proxy', true)) {
            return false;
        }

        // 🔍 Check VPN blocking
        if ($result['is_vpn'] && (bool) getSetting('security.ip_reputation.block_vpn', false)) {
            return false;
        }

        // 🔍 Check Tor blocking
        if ($result['is_tor'] && (bool) getSetting('security.ip_reputation.block_tor', true)) {
            return false;
        }

        return true;
    }

    /**
     * 📝 Build a human-readable block reason from the result data
     *
     * @param array $result Reputation data
     * @return string Block reason
     */
    private static function buildBlockReason(array $result): string
    {
        $reasons = [];

        if ($result['score'] !== null) {
            $threshold = (int) getSetting(
                'security.ip_reputation.abuse_score_threshold',
                self::DEFAULT_ABUSE_SCORE_THRESHOLD
            );
            if ((int) $result['score'] >= $threshold) {
                $reasons[] = 'abuse score ' . $result['score'] . '/100 (threshold: ' . $threshold . ')';
            }
        }

        if ($result['is_proxy']) {
            $reasons[] = 'detected as proxy';
        }

        if ($result['is_vpn']) {
            $reasons[] = 'detected as VPN';
        }

        if ($result['is_tor']) {
            $reasons[] = 'detected as Tor exit node';
        }

        if (empty($reasons)) {
            return 'IP reputation check failed';
        }

        return 'IP blocked: ' . implode(', ', $reasons);
    }

    /**
     * 🏠 Check if an IP address is private/reserved
     *
     * Private IPs are always trusted (localhost, LAN, etc.) and should
     * never be checked against external APIs.
     *
     * @param string $ip IPv4 or IPv6 address
     * @return bool True if the IP is private/reserved
     */
    private static function isPrivateIP(string $ip): bool
    {
        // 🔍 Use PHP's built-in filter for comprehensive private/reserved detection
        // FILTER_FLAG_NO_PRIV_RANGE: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, fc00::/7
        // FILTER_FLAG_NO_RES_RANGE: 0.0.0.0/8, 169.254.0.0/16, 127.0.0.0/8, 240.0.0.0/4, ::1, etc.
        $result = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        // 📌 If filter returns false, the IP IS private/reserved
        return ($result === false);
    }
}
