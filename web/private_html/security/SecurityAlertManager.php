<?php
/**
 * ============================================================================
 * 🚨 SIGNula - Security Alert Manager
 * ============================================================================
 *
 * Purpose: Security event alerting, incident tracking, and admin notification
 * PHP Version: 8.3+
 *
 * Creates and manages security alerts for suspicious events including brute
 * force attacks, impossible travel, session hijacking, and more. Supports
 * full alert lifecycle: creation → acknowledgement → investigation → resolution.
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
 * 🚨 Security Alert Manager
 *
 * Creates, tracks, and manages security alerts for the SIGNula system.
 * All methods are static for easy access throughout the application.
 *
 * @see https://owasp.org/www-project-application-security-verification-standard/
 */
class SecurityAlertManager
{
    // ========================================================================
    // 🏷️ ALERT TYPE CONSTANTS
    // ========================================================================

    /** @var string Brute force login attempt detected */
    public const TYPE_BRUTE_FORCE = 'brute_force';

    /** @var string Login from geographically impossible location */
    public const TYPE_IMPOSSIBLE_TRAVEL = 'impossible_travel';

    /** @var string Session hijacking attempt detected */
    public const TYPE_SESSION_HIJACK = 'session_hijack';

    /** @var string IP address blocked by firewall or blocklist */
    public const TYPE_IP_BLOCKED = 'ip_blocked';

    /** @var string Suspicious account registration activity */
    public const TYPE_SUSPICIOUS_REGISTRATION = 'suspicious_registration';

    /** @var string CAPTCHA verification failure */
    public const TYPE_CAPTCHA_FAILURE = 'captcha_failure';

    /** @var string Automated bot activity detected */
    public const TYPE_BOT_DETECTED = 'bot_detected';

    /** @var string API or endpoint rate limit exceeded */
    public const TYPE_RATE_LIMIT_EXCEEDED = 'rate_limit_exceeded';

    /** @var string Multiple failed login attempts for a single account */
    public const TYPE_MULTIPLE_FAILED_LOGINS = 'multiple_failed_logins';

    /** @var string Account locked due to excessive failed attempts */
    public const TYPE_ACCOUNT_LOCKOUT = 'account_lockout';

    /** @var string Password spray attack detected (many accounts, few attempts each) */
    public const TYPE_PASSWORD_SPRAY = 'password_spray';

    // ========================================================================
    // 🔴 SEVERITY LEVEL CONSTANTS
    // ========================================================================

    /** @var string Low severity — informational, no immediate action required */
    public const SEVERITY_LOW = 'low';

    /** @var string Medium severity — warrants monitoring */
    public const SEVERITY_MEDIUM = 'medium';

    /** @var string High severity — requires prompt attention */
    public const SEVERITY_HIGH = 'high';

    /** @var string Critical severity — immediate action required */
    public const SEVERITY_CRITICAL = 'critical';

    // ========================================================================
    // 📊 SEVERITY RANKING (for threshold comparisons)
    // ========================================================================

    /**
     * @var array<string, int> Numeric ranking of severity levels for comparison
     */
    private const SEVERITY_RANK = [
        'low'      => 1,
        'medium'   => 2,
        'high'     => 3,
        'critical' => 4,
    ];

    // ========================================================================
    // ✅ ENABLED CHECK
    // ========================================================================

    /**
     * ✅ Check if Security Alerts are Enabled
     *
     * Reads the 'security.alerts.enabled' setting from the database.
     *
     * @return bool True if security alerting is enabled
     *
     * @see https://www.php.net/manual/en/function.filter-var.php
     */
    public static function isEnabled(): bool
    {
        $enabled = getSetting('security.alerts.enabled', true);

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }

    // ========================================================================
    // 🆕 ALERT CREATION
    // ========================================================================

    /**
     * 🆕 Create a New Security Alert
     *
     * Inserts a new alert record into tblSecurityAlerts, logs the event via
     * ActivityLogger, and sends admin notification if severity meets threshold.
     *
     * @param string $alertType    One of the TYPE_* constants
     * @param string $severity     One of the SEVERITY_* constants
     * @param string $description  Human-readable description of the alert
     * @param array  $metadata     Additional context data (stored as JSON)
     * @param int|null $userID     Affected user ID, if applicable
     * @param string|null $ipAddress Source IP address, if known
     * @return int|false           The new alertID on success, or false on failure
     */
    public static function create(
        string $alertType,
        string $severity,
        string $description,
        array $metadata = [],
        ?int $userID = null,
        ?string $ipAddress = null
    ): int|false {
        // 🚫 Bail out early if alerting is disabled
        if (!self::isEnabled()) {
            return false;
        }

        // 🔍 Ensure Database class is available before attempting to store
        if (!class_exists('Database')) {
            return false;
        }

        try {
            // 🌐 Capture request context from server superglobals
            $userAgent  = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $requestURL = $_SERVER['REQUEST_URI'] ?? null;

            // 📦 Encode metadata array as JSON for database storage
            $metadataJSON = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // 💾 Insert the alert record into tblSecurityAlerts
            $query = "
                INSERT INTO tblSecurityAlerts (
                    alertType, severity, description, metadata,
                    userID, ipAddress, userAgent, requestURL,
                    status, notificationSent, createdAt, updatedAt
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    'new', 0, NOW(), NOW()
                )
            ";

            Database::query($query, [
                $alertType,
                $severity,
                $description,
                $metadataJSON,
                $userID,
                $ipAddress,
                $userAgent,
                $requestURL
            ], 'ssssisss');

            // 🔑 Retrieve the auto-incremented alertID
            $alertID = Database::getLastInsertId();

            // 📝 Log the alert creation to the activity log for audit trail
            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    $userID,
                    'security_alert_created',
                    'security',
                    $severity,
                    'Security alert created: ' . $description,
                    array_merge($metadata, [
                        'alert_id'   => $alertID,
                        'alert_type' => $alertType,
                        'severity'   => $severity
                    ])
                );
            }

            // 📧 Send admin notification if severity meets or exceeds threshold
            if (self::shouldNotify($severity)) {
                self::notify($alertID, $alertType, $severity, $description);
            }

            return $alertID;

        } catch (\Exception $e) {
            // ⚠️ Log failure but do not let alerting errors break the application
            error_log('SecurityAlertManager::create() failed: ' . $e->getMessage());
            return false;
        }
    }

    // ========================================================================
    // 🔍 THREAT DETECTION CHECKS
    // ========================================================================

    /**
     * 🔨 Check for Brute Force Attack
     *
     * Creates a high-severity alert when the number of failed login attempts
     * from a single IP exceeds the configured threshold.
     *
     * @param string $ip             Source IP address
     * @param int    $failedAttempts Number of consecutive failed attempts observed
     * @return void
     *
     * @see https://owasp.org/www-community/controls/Blocking_Brute_Force_Attacks
     */
    public static function checkBruteForce(string $ip, int $failedAttempts): void
    {
        // 📊 Retrieve the configurable threshold from database settings
        $threshold = (int) getSetting('security.alerts.brute_force_threshold', 5);

        // 🚨 Only trigger if failed attempts meet or exceed the threshold
        if ($failedAttempts >= $threshold) {
            self::create(
                self::TYPE_BRUTE_FORCE,
                self::SEVERITY_HIGH,
                'Brute force attack detected from IP: ' . $ip . ' (' . $failedAttempts . ' failed attempts)',
                [
                    'ip_address'      => $ip,
                    'failed_attempts' => $failedAttempts,
                    'threshold'       => $threshold
                ],
                null,
                $ip
            );
        }
    }

    /**
     * ✈️ Check for Impossible Travel
     *
     * Detects logins from geographically improbable locations by comparing the
     * current login IP with the user's most recent successful login. Uses the
     * Haversine formula to calculate great-circle distance and compares against
     * configurable distance and time thresholds.
     *
     * @param int    $userID    The user ID to check
     * @param string $currentIP The IP address of the current login attempt
     * @return void
     *
     * @see https://en.wikipedia.org/wiki/Haversine_formula
     */
    public static function checkImpossibleTravel(int $userID, string $currentIP): void
    {
        if (!class_exists('Database')) {
            return;
        }

        try {
            // 📊 Load configurable thresholds from database settings
            $distanceThresholdKm = (int) getSetting('security.alerts.impossible_travel_km', 500);
            $timeThresholdMinutes = (int) getSetting('security.alerts.impossible_travel_minutes', 60);

            // 🔍 Fetch the user's most recent successful login from the activity log
            $lastLogin = Database::fetchOne(
                "SELECT ipAddress, createdAt
                 FROM tblActivityLog
                 WHERE activityType = 'login_success'
                   AND userID = ?
                 ORDER BY createdAt DESC
                 LIMIT 1",
                [$userID],
                'i'
            );

            // ⏭️ Skip check if there is no previous login record to compare against
            if (!$lastLogin) {
                return;
            }

            $lastIP = $lastLogin['ipAddress'];

            // ⏭️ Skip if the IP address has not changed since last login
            if ($lastIP === $currentIP) {
                return;
            }

            // 🌍 Resolve geographic coordinates for both IP addresses
            $lastGeo    = self::getGeoLocation($lastIP);
            $currentGeo = self::getGeoLocation($currentIP);

            // ⏭️ Gracefully skip if either geo lookup fails (do not block the user)
            if ($lastGeo === null || $currentGeo === null) {
                return;
            }

            // 📐 Calculate the great-circle distance between the two locations
            $distanceKm = self::calculateDistance(
                $lastGeo['lat'],
                $lastGeo['lon'],
                $currentGeo['lat'],
                $currentGeo['lon']
            );

            // ⏱️ Calculate time elapsed since the last login in minutes
            $lastLoginTime = new \DateTime($lastLogin['createdAt']);
            $currentTime   = new \DateTime();
            $timeDiffMinutes = ($currentTime->getTimestamp() - $lastLoginTime->getTimestamp()) / 60;

            // 🚨 Trigger alert if distance exceeds threshold AND time is too short
            if ($distanceKm > $distanceThresholdKm && $timeDiffMinutes < $timeThresholdMinutes) {
                self::create(
                    self::TYPE_IMPOSSIBLE_TRAVEL,
                    self::SEVERITY_CRITICAL,
                    'Impossible travel detected for user ID ' . $userID
                        . ': ' . round($distanceKm) . ' km in ' . round($timeDiffMinutes) . ' minutes',
                    [
                        'user_id'           => $userID,
                        'last_ip'           => $lastIP,
                        'current_ip'        => $currentIP,
                        'last_location'     => $lastGeo['city'] . ', ' . $lastGeo['country'],
                        'current_location'  => $currentGeo['city'] . ', ' . $currentGeo['country'],
                        'distance_km'       => round($distanceKm, 2),
                        'time_diff_minutes' => round($timeDiffMinutes, 2),
                        'threshold_km'      => $distanceThresholdKm,
                        'threshold_minutes' => $timeThresholdMinutes
                    ],
                    $userID,
                    $currentIP
                );
            }

        } catch (\Exception $e) {
            // ⚠️ Impossible travel check should never break the login flow
            error_log('SecurityAlertManager::checkImpossibleTravel() failed: ' . $e->getMessage());
        }
    }

    /**
     * 🔑 Check for Password Spray Attack
     *
     * Detects when a single IP address attempts to log in to many distinct
     * user accounts within a configurable time window — a hallmark of
     * password spray attacks.
     *
     * @param string $ip                  Source IP address
     * @param int    $timeWindowMinutes   Time window to check (0 or less uses setting default)
     * @return void
     *
     * @see https://owasp.org/www-community/attacks/Password_Spraying_Attack
     */
    public static function checkPasswordSpray(string $ip, int $timeWindowMinutes = 30): void
    {
        if (!class_exists('Database')) {
            return;
        }

        try {
            // 📊 Use setting default if provided value is invalid
            if ($timeWindowMinutes <= 0) {
                $timeWindowMinutes = (int) getSetting('security.alerts.password_spray_window_minutes', 30);
            }

            $threshold = (int) getSetting('security.alerts.password_spray_threshold', 10);

            // 🔍 Count distinct userIDs targeted by failed logins from this IP
            $result = Database::fetchOne(
                "SELECT COUNT(DISTINCT userID) AS distinctUsers
                 FROM tblActivityLog
                 WHERE activityType = 'login_failed'
                   AND ipAddress = ?
                   AND createdAt >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$ip, $timeWindowMinutes],
                'si'
            );

            $distinctUsers = (int) ($result['distinctUsers'] ?? 0);

            // 🚨 Trigger alert if distinct targeted accounts exceed threshold
            if ($distinctUsers > $threshold) {
                self::create(
                    self::TYPE_PASSWORD_SPRAY,
                    self::SEVERITY_HIGH,
                    'Password spray attack detected from IP: ' . $ip
                        . ' (' . $distinctUsers . ' distinct accounts targeted in ' . $timeWindowMinutes . ' minutes)',
                    [
                        'ip_address'          => $ip,
                        'distinct_users'      => $distinctUsers,
                        'threshold'           => $threshold,
                        'time_window_minutes' => $timeWindowMinutes
                    ],
                    null,
                    $ip
                );
            }

        } catch (\Exception $e) {
            // ⚠️ Password spray detection should never break the login flow
            error_log('SecurityAlertManager::checkPasswordSpray() failed: ' . $e->getMessage());
        }
    }

    // ========================================================================
    // 📋 ALERT RETRIEVAL
    // ========================================================================

    /**
     * 📋 Get Recent Security Alerts
     *
     * Retrieves the most recent alerts, optionally filtered by severity level.
     *
     * @param int         $limit    Maximum number of alerts to return (default: 50)
     * @param string|null $severity Filter by severity level, or null for all
     * @return array                Array of alert records from tblSecurityAlerts
     */
    public static function getRecentAlerts(int $limit = 50, ?string $severity = null): array
    {
        if (!class_exists('Database')) {
            return [];
        }

        try {
            // 🔍 Build query with optional severity filter
            if ($severity !== null) {
                $query = "
                    SELECT *
                    FROM tblSecurityAlerts
                    WHERE severity = ?
                    ORDER BY createdAt DESC
                    LIMIT ?
                ";

                $results = Database::fetchAll($query, [$severity, $limit], 'si');
            } else {
                $query = "
                    SELECT *
                    FROM tblSecurityAlerts
                    ORDER BY createdAt DESC
                    LIMIT ?
                ";

                $results = Database::fetchAll($query, [$limit], 'i');
            }

            return $results ?: [];

        } catch (\Exception $e) {
            error_log('SecurityAlertManager::getRecentAlerts() failed: ' . $e->getMessage());
            return [];
        }
    }

    // ========================================================================
    // ✅ ALERT LIFECYCLE MANAGEMENT
    // ========================================================================

    /**
     * 👁️ Acknowledge a Security Alert
     *
     * Transitions alert status from 'new' to 'acknowledged' and records
     * which admin user acknowledged it and when.
     *
     * @param int $alertID              The alert to acknowledge
     * @param int $acknowledgedByUserID The admin user acknowledging the alert
     * @return bool                     True on success, false on failure
     */
    public static function acknowledge(int $alertID, int $acknowledgedByUserID): bool
    {
        if (!class_exists('Database')) {
            return false;
        }

        try {
            $query = "
                UPDATE tblSecurityAlerts
                SET status = 'acknowledged',
                    acknowledgedAt = NOW(),
                    acknowledgedByUserID = ?,
                    updatedAt = NOW()
                WHERE alertID = ?
            ";

            Database::query($query, [$acknowledgedByUserID, $alertID], 'ii');

            // 📝 Log the acknowledgement action for audit trail
            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    $acknowledgedByUserID,
                    'security_alert_acknowledged',
                    'security',
                    'info',
                    'Security alert #' . $alertID . ' acknowledged',
                    ['alert_id' => $alertID]
                );
            }

            return true;

        } catch (\Throwable $e) {
            error_log('SecurityAlertManager::acknowledge() failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * ✅ Resolve a Security Alert
     *
     * Transitions alert status to 'resolved' and records the resolution
     * details, the resolving admin, and the timestamp.
     *
     * @param int    $alertID            The alert to resolve
     * @param int    $resolvedByUserID   The admin user resolving the alert
     * @param string $resolution         Description of how the alert was resolved
     * @return bool                      True on success, false on failure
     */
    public static function resolve(int $alertID, int $resolvedByUserID, string $resolution): bool
    {
        if (!class_exists('Database')) {
            return false;
        }

        try {
            $query = "
                UPDATE tblSecurityAlerts
                SET status = 'resolved',
                    resolvedAt = NOW(),
                    resolvedByUserID = ?,
                    resolution = ?,
                    updatedAt = NOW()
                WHERE alertID = ?
            ";

            Database::query($query, [$resolvedByUserID, $resolution, $alertID], 'isi');

            // 📝 Log the resolution action for audit trail
            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    $resolvedByUserID,
                    'security_alert_resolved',
                    'security',
                    'info',
                    'Security alert #' . $alertID . ' resolved: ' . $resolution,
                    [
                        'alert_id'   => $alertID,
                        'resolution' => $resolution
                    ]
                );
            }

            return true;

        } catch (\Throwable $e) {
            error_log('SecurityAlertManager::resolve() failed: ' . $e->getMessage());
            return false;
        }
    }

    // ========================================================================
    // 📧 PRIVATE — ADMIN NOTIFICATION
    // ========================================================================

    /**
     * 📧 Send Admin Notification for Alert
     *
     * Sends email notification to configured admin addresses. Prefers the
     * EmailService class if available, falling back to PHP's built-in mail()
     * function. Notification failures are logged but never break the app.
     *
     * @param int    $alertID     The alert ID for reference
     * @param string $alertType   The type of alert (e.g. TYPE_BRUTE_FORCE)
     * @param string $severity    The severity level
     * @param string $description Human-readable alert description
     * @return void
     */
    private static function notify(int $alertID, string $alertType, string $severity, string $description): void
    {
        try {
            // 📋 Retrieve admin email addresses from settings (stored as JSON array)
            $adminEmailsJSON = getSetting('security.alerts.admin_emails', '[]');
            $adminEmails = json_decode($adminEmailsJSON, true);

            // ⏭️ Skip notification if no admin emails are configured
            if (empty($adminEmails) || !is_array($adminEmails)) {
                return;
            }

            // 📝 Build the notification email subject and body
            $severityUpper = strtoupper($severity);
            $subject = '[SIGNula Security] ' . $severityUpper . ' Alert: ' . $alertType . ' (#' . $alertID . ')';

            $body = 'SIGNula Security Alert' . PHP_EOL
                  . str_repeat('=', 40) . PHP_EOL . PHP_EOL
                  . 'Alert ID:    ' . $alertID . PHP_EOL
                  . 'Type:        ' . $alertType . PHP_EOL
                  . 'Severity:    ' . $severityUpper . PHP_EOL
                  . 'Time:        ' . date('Y-m-d H:i:s T') . PHP_EOL . PHP_EOL
                  . 'Description:' . PHP_EOL
                  . $description . PHP_EOL . PHP_EOL
                  . str_repeat('-', 40) . PHP_EOL
                  . 'This is an automated alert from SIGNula Security.' . PHP_EOL
                  . 'Please review this alert in the admin dashboard.' . PHP_EOL;

            // 📧 Send to each admin email address
            foreach ($adminEmails as $adminEmail) {
                if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                // 🔌 Prefer EmailService if available, otherwise fall back to mail()
                if (class_exists('EmailService')) {
                    EmailService::sendTemplateEmail(
                        $adminEmail,
                        'security_alert',
                        [
                            'alert_id'    => $alertID,
                            'alert_type'  => $alertType,
                            'severity'    => $severityUpper,
                            'description' => $description,
                            'timestamp'   => date('Y-m-d H:i:s T')
                        ],
                        null,
                        1  // 🔴 Highest priority for security alerts
                    );
                } else {
                    // 📬 Fallback to PHP's built-in mail() function
                    $headers = 'From: noreply@signula.id' . "\r\n"
                             . 'Content-Type: text/plain; charset=UTF-8' . "\r\n";

                    mail($adminEmail, $subject, $body, $headers);
                }
            }

            // ✅ Mark the alert as notification sent in the database
            Database::query(
                "UPDATE tblSecurityAlerts
                 SET notificationSent = 1,
                     notificationSentAt = NOW(),
                     updatedAt = NOW()
                 WHERE alertID = ?",
                [$alertID],
                'i'
            );

        } catch (\Exception $e) {
            // ⚠️ Notification failure must never break the application
            error_log('SecurityAlertManager::notify() failed: ' . $e->getMessage());
        }
    }

    // ========================================================================
    // 🌍 PRIVATE — GEOLOCATION
    // ========================================================================

    /**
     * 🌍 Get Geographic Location for an IP Address
     *
     * Uses the ip-api.com free API to resolve latitude, longitude, country,
     * and city for a given IP address. Respects a circuit breaker pattern
     * (via tblCircuitBreaker) to avoid hammering a failing external service.
     *
     * @param string $ip IP address to look up
     * @return array|null Associative array with lat, lon, country, city — or null on failure
     *
     * @see https://ip-api.com/docs/api:json
     */
    private static function getGeoLocation(string $ip): ?array
    {
        try {
            // 🔌 Check circuit breaker status before making external API call
            $circuitBreaker = Database::fetchOne(
                "SELECT failureCount, lastFailureAt, isOpen
                 FROM tblCircuitBreaker
                 WHERE serviceName = 'geoip'
                 LIMIT 1",
                [],
                ''
            );

            // 🚫 If circuit is open (too many recent failures), skip the API call
            if ($circuitBreaker && !empty($circuitBreaker['isOpen'])) {
                return null;
            }

            // 🌐 Make HTTP request to ip-api.com with a short timeout
            $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,lat,lon,country,city';

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);         // ⏱️ 3-second timeout
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);   // ⏱️ 3-second connection timeout
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'SIGNula Security/1.0');

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // ❌ Handle cURL or HTTP errors
            if ($response === false || $httpCode !== 200) {
                // 📝 Record failure in circuit breaker
                self::recordCircuitBreakerFailure($curlError ?: 'HTTP ' . $httpCode);
                return null;
            }

            // 📦 Decode JSON response
            $data = json_decode($response, true);

            // ❌ Handle API-level failures
            if (!$data || ($data['status'] ?? '') !== 'success') {
                self::recordCircuitBreakerFailure('API returned non-success status');
                return null;
            }

            // ✅ Reset circuit breaker on successful response
            self::resetCircuitBreaker();

            return [
                'lat'     => (float) $data['lat'],
                'lon'     => (float) $data['lon'],
                'country' => (string) ($data['country'] ?? 'Unknown'),
                'city'    => (string) ($data['city'] ?? 'Unknown')
            ];

        } catch (\Exception $e) {
            // ⚠️ Geo lookup failure should not propagate
            error_log('SecurityAlertManager::getGeoLocation() failed: ' . $e->getMessage());
            self::recordCircuitBreakerFailure($e->getMessage());
            return null;
        }
    }

    /**
     * ❌ Record a Circuit Breaker Failure
     *
     * Increments the failure count for the 'geoip' circuit breaker record.
     *
     * @param string $error Error message to store
     * @return void
     */
    private static function recordCircuitBreakerFailure(string $error): void
    {
        try {
            Database::query(
                "INSERT INTO tblCircuitBreaker (serviceName, failureCount, lastFailureAt, lastError, isOpen, updatedAt)
                 VALUES ('geoip', 1, NOW(), ?, 0, NOW())
                 ON DUPLICATE KEY UPDATE
                     failureCount = failureCount + 1,
                     lastFailureAt = NOW(),
                     lastError = ?,
                     isOpen = IF(failureCount + 1 >= 5, 1, 0),
                     updatedAt = NOW()",
                [$error, $error],
                'ss'
            );
        } catch (\Exception $e) {
            error_log('SecurityAlertManager: circuit breaker update failed: ' . $e->getMessage());
        }
    }

    /**
     * ✅ Reset the Circuit Breaker
     *
     * Resets failure count and closes the circuit on a successful geo lookup.
     *
     * @return void
     */
    private static function resetCircuitBreaker(): void
    {
        try {
            Database::query(
                "INSERT INTO tblCircuitBreaker (serviceName, failureCount, isOpen, lastSuccessAt, updatedAt)
                 VALUES ('geoip', 0, 0, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                     failureCount = 0,
                     isOpen = 0,
                     lastSuccessAt = NOW(),
                     updatedAt = NOW()",
                [],
                ''
            );
        } catch (\Exception $e) {
            error_log('SecurityAlertManager: circuit breaker reset failed: ' . $e->getMessage());
        }
    }

    // ========================================================================
    // 📐 PRIVATE — DISTANCE CALCULATION
    // ========================================================================

    /**
     * 📐 Calculate Distance Between Two Geographic Coordinates
     *
     * Uses the Haversine formula to compute the great-circle distance between
     * two points on the Earth's surface, given their latitude and longitude
     * in decimal degrees.
     *
     * @param float $lat1 Latitude of point 1 (degrees)
     * @param float $lon1 Longitude of point 1 (degrees)
     * @param float $lat2 Latitude of point 2 (degrees)
     * @param float $lon2 Longitude of point 2 (degrees)
     * @return float Distance in kilometres
     *
     * @see https://en.wikipedia.org/wiki/Haversine_formula
     */
    private static function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        // 🌍 Earth's mean radius in kilometres
        $earthRadiusKm = 6371.0;

        // 📐 Convert degrees to radians
        $lat1Rad = deg2rad($lat1);
        $lon1Rad = deg2rad($lon1);
        $lat2Rad = deg2rad($lat2);
        $lon2Rad = deg2rad($lon2);

        // 📐 Compute differences
        $deltaLat = $lat2Rad - $lat1Rad;
        $deltaLon = $lon2Rad - $lon1Rad;

        // 📐 Haversine formula
        $a = sin($deltaLat / 2) * sin($deltaLat / 2)
           + cos($lat1Rad) * cos($lat2Rad)
           * sin($deltaLon / 2) * sin($deltaLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    // ========================================================================
    // 📊 PRIVATE — NOTIFICATION THRESHOLD CHECK
    // ========================================================================

    /**
     * 📊 Determine if a Severity Level Should Trigger Notification
     *
     * Compares the provided severity's numeric rank against the configured
     * notification threshold rank. Returns true if the severity meets or
     * exceeds the threshold.
     *
     * @param string $severity The severity level to evaluate
     * @return bool True if notification should be sent
     */
    private static function shouldNotify(string $severity): bool
    {
        // 📊 Get the configured minimum severity for email notifications
        $notifyThreshold = getSetting('security.alerts.notify_threshold', 'high');

        // 🔢 Resolve numeric ranks for comparison
        $severityRank  = self::SEVERITY_RANK[$severity] ?? 0;
        $thresholdRank = self::SEVERITY_RANK[$notifyThreshold] ?? self::SEVERITY_RANK['high'];

        return $severityRank >= $thresholdRank;
    }
}

// ✅ SecurityAlertManager class loaded successfully
