<?php
/**
 * API Key Manager
 *
 * Handles partner API key generation, validation, and management
 * Provides secure authentication for third-party integrations
 *
 * Features:
 * - Secure key generation (SHA-256 hashing)
 * - Environment separation (test vs live)
 * - Key validation and authentication
 * - Usage tracking and analytics
 * - IP whitelist checking
 * - Automatic expiration handling
 * - Permissions and scopes management
 *
 * Key Format:
 * - Live keys: sk_live_[32 random chars]
 * - Test keys: sk_test_[32 random chars]
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

class APIKeyManager {
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
    }

    /**
     * Generate a new API key for a partner
     *
     * @param int $partnerID Partner ID
     * @param string $keyName Human-readable name for the key
     * @param string $environment 'test' or 'live'
     * @param array $options Optional configuration
     *   - permissions: array of permissions (e.g., ['users:read', 'users:write'])
     *   - ipWhitelist: array of allowed IP addresses
     *   - expiresAt: DateTime or null for no expiration
     *   - customRateLimit: int or null for default
     * @return array|false Array with 'key' and 'keyData' on success, false on failure
     */
    public function generateKey(int $partnerID, string $keyName, string $environment = 'test', array $options = []) {
        // Validate partner exists and is active
        if (!$this->validatePartner($partnerID)) {
            return false;
        }

        // Check if partner has reached max keys limit
        if ($this->hasReachedKeyLimit($partnerID)) {
            return false;
        }

        // Generate unique API key
        $apiKey = $this->generateUniqueKey($environment);
        $keyHash = hash('sha256', $apiKey);
        $keyPrefix = substr($apiKey, 0, 20); // First 20 chars for display

        // Prepare permissions and IP whitelist
        $permissions = isset($options['permissions']) ? json_encode($options['permissions']) : null;
        $ipWhitelist = isset($options['ipWhitelist']) ? json_encode($options['ipWhitelist']) : null;
        $expiresAt = isset($options['expiresAt']) ? $options['expiresAt'] : null;
        $customRateLimit = isset($options['customRateLimit']) ? $options['customRateLimit'] : null;

        // Insert into database
        $stmt = $this->db->prepare("
            INSERT INTO tblAPIKeys (
                partnerID, keyName, keyHash, keyPrefix, environment,
                permissions, ipWhitelist, expiresAt, customRateLimit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log("APIKeyManager: Failed to prepare statement: " . $this->db->error);
            return false;
        }

        $stmt->bind_param(
            'isssssssi',
            $partnerID,
            $keyName,
            $keyHash,
            $keyPrefix,
            $environment,
            $permissions,
            $ipWhitelist,
            $expiresAt,
            $customRateLimit
        );

        if (!$stmt->execute()) {
            error_log("APIKeyManager: Failed to insert key: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $keyID = $stmt->insert_id;
        $stmt->close();

        // Log audit event
        $this->logAudit($keyID, $partnerID, 'key_generated', [
            'keyName' => $keyName,
            'environment' => $environment,
            'expiresAt' => $expiresAt
        ]);

        // Get full key data
        $keyData = $this->getKeyByID($keyID);

        return [
            'key' => $apiKey,  // Full key - ONLY shown once at creation
            'keyData' => $keyData
        ];
    }

    /**
     * Validate an API key and return key data
     *
     * @param string $apiKey The API key to validate
     * @param string|null $ipAddress Optional IP to check against whitelist
     * @return array|false Key data on success, false on failure
     */
    public function validateKey(string $apiKey, ?string $ipAddress = null) {
        // Hash the provided key
        $keyHash = hash('sha256', $apiKey);

        // Look up key in database
        $stmt = $this->db->prepare("
            SELECT k.*, p.partnerName, p.accountTier, p.isActive as partnerActive
            FROM tblAPIKeys k
            JOIN tblPartners p ON k.partnerID = p.partnerID
            WHERE k.keyHash = ?
        ");

        if (!$stmt) {
            error_log("APIKeyManager: Failed to prepare validation statement: " . $this->db->error);
            return false;
        }

        $stmt->bind_param('s', $keyHash);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return false; // Key not found
        }

        $keyData = $result->fetch_assoc();
        $stmt->close();

        // Check if key is active
        if (!$keyData['isActive']) {
            return false;
        }

        // Check if partner is active
        if (!$keyData['partnerActive']) {
            return false;
        }

        // Check if key has expired
        if ($keyData['expiresAt'] && strtotime($keyData['expiresAt']) < time()) {
            // Auto-revoke expired key
            $this->revokeKey($keyData['keyID'], null, 'Automatically expired');
            return false;
        }

        // Check IP whitelist if required
        if ($keyData['requiresIPWhitelist'] && $ipAddress) {
            if (!$this->checkIPWhitelist($keyData['ipWhitelist'], $ipAddress)) {
                return false;
            }
        }

        // Update last used information
        $this->updateLastUsed($keyData['keyID'], $ipAddress);

        return $keyData;
    }

    /**
     * Revoke an API key
     *
     * @param int $keyID Key ID to revoke
     * @param int|null $revokedBy User ID who revoked the key
     * @param string|null $reason Reason for revocation
     * @return bool Success
     */
    public function revokeKey(int $keyID, ?int $revokedBy = null, ?string $reason = null): bool {
        $stmt = $this->db->prepare("
            UPDATE tblAPIKeys
            SET isActive = 0,
                revokedAt = NOW(),
                revokedBy = ?,
                revokedReason = ?
            WHERE keyID = ?
        ");

        if (!$stmt) {
            error_log("APIKeyManager: Failed to prepare revoke statement: " . $this->db->error);
            return false;
        }

        $stmt->bind_param('isi', $revokedBy, $reason, $keyID);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            // Get key data for audit
            $keyData = $this->getKeyByID($keyID);

            // Log audit event
            $this->logAudit($keyID, $keyData['partnerID'], 'key_revoked', [
                'revokedBy' => $revokedBy,
                'reason' => $reason
            ]);
        }

        return $success;
    }

    /**
     * Regenerate an API key (revoke old, create new)
     *
     * @param int $keyID Existing key ID to regenerate
     * @param int|null $performedBy User ID performing the action
     * @return array|false New key data on success, false on failure
     */
    public function regenerateKey(int $keyID, ?int $performedBy = null) {
        // Get existing key data
        $oldKeyData = $this->getKeyByID($keyID);
        if (!$oldKeyData) {
            return false;
        }

        // Revoke old key
        if (!$this->revokeKey($keyID, $performedBy, 'Regenerated')) {
            return false;
        }

        // Generate new key with same settings
        $options = [
            'permissions' => $oldKeyData['permissions'] ? json_decode($oldKeyData['permissions'], true) : null,
            'ipWhitelist' => $oldKeyData['ipWhitelist'] ? json_decode($oldKeyData['ipWhitelist'], true) : null,
            'expiresAt' => $oldKeyData['expiresAt'],
            'customRateLimit' => $oldKeyData['customRateLimit']
        ];

        $newKey = $this->generateKey(
            $oldKeyData['partnerID'],
            $oldKeyData['keyName'],
            $oldKeyData['environment'],
            $options
        );

        if ($newKey) {
            // Log audit event
            $this->logAudit($newKey['keyData']['keyID'], $oldKeyData['partnerID'], 'key_regenerated', [
                'oldKeyID' => $keyID,
                'performedBy' => $performedBy
            ]);
        }

        return $newKey;
    }

    /**
     * Get API key data by ID
     *
     * @param int $keyID Key ID
     * @return array|false Key data on success, false on failure
     */
    public function getKeyByID(int $keyID) {
        $stmt = $this->db->prepare("
            SELECT k.*, p.partnerName, p.accountTier
            FROM tblAPIKeys k
            JOIN tblPartners p ON k.partnerID = p.partnerID
            WHERE k.keyID = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $keyID);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return false;
        }

        $keyData = $result->fetch_assoc();
        $stmt->close();

        return $keyData;
    }

    /**
     * Get all API keys for a partner
     *
     * @param int $partnerID Partner ID
     * @param bool $activeOnly Only return active keys
     * @return array Array of key data
     */
    public function getPartnerKeys(int $partnerID, bool $activeOnly = true): array {
        $sql = "
            SELECT k.*, p.partnerName, p.accountTier
            FROM tblAPIKeys k
            JOIN tblPartners p ON k.partnerID = p.partnerID
            WHERE k.partnerID = ?
        ";

        if ($activeOnly) {
            $sql .= " AND k.isActive = 1";
        }

        $sql .= " ORDER BY k.createdAt DESC";

        $stmt = $this->db->prepare($sql);

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $partnerID);
        $stmt->execute();
        $result = $stmt->get_result();

        $keys = [];
        while ($row = $result->fetch_assoc()) {
            $keys[] = $row;
        }

        $stmt->close();
        return $keys;
    }

    /**
     * Update key permissions
     *
     * @param int $keyID Key ID
     * @param array $permissions Array of permissions
     * @param int|null $performedBy User ID performing the action
     * @return bool Success
     */
    public function updatePermissions(int $keyID, array $permissions, ?int $performedBy = null): bool {
        $permissionsJson = json_encode($permissions);

        $stmt = $this->db->prepare("
            UPDATE tblAPIKeys
            SET permissions = ?,
                updatedAt = NOW()
            WHERE keyID = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('si', $permissionsJson, $keyID);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            // Get key data for audit
            $keyData = $this->getKeyByID($keyID);

            // Log audit event
            $this->logAudit($keyID, $keyData['partnerID'], 'permissions_changed', [
                'permissions' => $permissions,
                'performedBy' => $performedBy
            ]);
        }

        return $success;
    }

    /**
     * Update IP whitelist for a key
     *
     * @param int $keyID Key ID
     * @param array $ipAddresses Array of allowed IP addresses
     * @param bool $requireWhitelist Whether to enforce whitelist
     * @param int|null $performedBy User ID performing the action
     * @return bool Success
     */
    public function updateIPWhitelist(int $keyID, array $ipAddresses, bool $requireWhitelist = true, ?int $performedBy = null): bool {
        $ipWhitelistJson = json_encode($ipAddresses);

        $stmt = $this->db->prepare("
            UPDATE tblAPIKeys
            SET ipWhitelist = ?,
                requiresIPWhitelist = ?,
                updatedAt = NOW()
            WHERE keyID = ?
        ");

        if (!$stmt) {
            return false;
        }

        $requireWhitelistInt = $requireWhitelist ? 1 : 0;
        $stmt->bind_param('sii', $ipWhitelistJson, $requireWhitelistInt, $keyID);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            // Get key data for audit
            $keyData = $this->getKeyByID($keyID);

            // Log audit event
            $this->logAudit($keyID, $keyData['partnerID'], 'whitelist_updated', [
                'ipAddresses' => $ipAddresses,
                'requireWhitelist' => $requireWhitelist,
                'performedBy' => $performedBy
            ]);
        }

        return $success;
    }

    /**
     * Log API key usage
     *
     * @param int $keyID Key ID
     * @param string $endpoint Endpoint accessed
     * @param string $httpMethod HTTP method (GET, POST, etc.)
     * @param int|null $statusCode Response status code
     * @param string|null $ipAddress Client IP address
     * @param int|null $responseTime Response time in milliseconds
     * @param string|null $errorCode Error code if applicable
     * @param bool $rateLimitHit Whether request was rate limited
     * @return bool Success
     */
    public function logUsage(
        int $keyID,
        string $endpoint,
        string $httpMethod,
        ?int $statusCode = null,
        ?string $ipAddress = null,
        ?int $responseTime = null,
        ?string $errorCode = null,
        bool $rateLimitHit = false
    ): bool {
        $stmt = $this->db->prepare("
            INSERT INTO tblAPIKeyUsage (
                keyID, endpoint, httpMethod, statusCode, ipAddress,
                responseTime, errorCode, rateLimitHit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log("APIKeyManager: Failed to prepare usage log statement: " . $this->db->error);
            return false;
        }

        $rateLimitHitInt = $rateLimitHit ? 1 : 0;
        $stmt->bind_param(
            'issisisi',
            $keyID,
            $endpoint,
            $httpMethod,
            $statusCode,
            $ipAddress,
            $responseTime,
            $errorCode,
            $rateLimitHitInt
        );

        $success = $stmt->execute();
        $stmt->close();

        return $success;
    }

    /**
     * Get usage statistics for a key
     *
     * @param int $keyID Key ID
     * @param int $days Number of days to look back (default 30)
     * @return array Usage statistics
     */
    public function getUsageStats(int $keyID, int $days = 30): array {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as totalRequests,
                SUM(CASE WHEN statusCode >= 200 AND statusCode < 300 THEN 1 ELSE 0 END) as successfulRequests,
                SUM(CASE WHEN statusCode >= 400 THEN 1 ELSE 0 END) as errorRequests,
                SUM(CASE WHEN rateLimitHit = 1 THEN 1 ELSE 0 END) as rateLimitedRequests,
                AVG(responseTime) as avgResponseTime,
                MAX(requestedAt) as lastRequest,
                COUNT(DISTINCT ipAddress) as uniqueIPs,
                COUNT(DISTINCT endpoint) as uniqueEndpoints
            FROM tblAPIKeyUsage
            WHERE keyID = ?
            AND requestedAt >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $keyID, $days);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();
        $stmt->close();

        return $stats ?: [];
    }

    // =====================================================
    // PRIVATE HELPER METHODS
    // =====================================================

    /**
     * Generate a unique API key
     *
     * @param string $environment 'test' or 'live'
     * @return string Unique API key
     */
    private function generateUniqueKey(string $environment): string {
        $prefix = ($environment === 'live') ? 'sk_live_' : 'sk_test_';

        do {
            // Generate 32 random characters
            $randomBytes = random_bytes(24); // 24 bytes = 32 base64 chars
            $randomString = rtrim(strtr(base64_encode($randomBytes), '+/', '-_'), '=');

            $apiKey = $prefix . $randomString;
            $keyHash = hash('sha256', $apiKey);

            // Check if hash already exists (extremely unlikely, but check anyway)
            $stmt = $this->db->prepare("SELECT keyID FROM tblAPIKeys WHERE keyHash = ?");
            $stmt->bind_param('s', $keyHash);
            $stmt->execute();
            $result = $stmt->get_result();
            $exists = $result->num_rows > 0;
            $stmt->close();

        } while ($exists);

        return $apiKey;
    }

    /**
     * Validate that a partner exists and is active
     *
     * @param int $partnerID Partner ID
     * @return bool Partner is valid
     */
    private function validatePartner(int $partnerID): bool {
        $stmt = $this->db->prepare("
            SELECT partnerID FROM tblPartners
            WHERE partnerID = ? AND isActive = 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $partnerID);
        $stmt->execute();
        $result = $stmt->get_result();
        $valid = $result->num_rows > 0;
        $stmt->close();

        return $valid;
    }

    /**
     * Check if partner has reached max API keys limit
     *
     * @param int $partnerID Partner ID
     * @return bool Has reached limit
     */
    private function hasReachedKeyLimit(int $partnerID): bool {
        // Get max keys setting from database
        $stmt = $this->db->prepare("
            SELECT settingValue FROM tblSettings
            WHERE settingKey = 'api_keys.max_keys_per_partner'
        ");

        if (!$stmt) {
            return false; // Assume no limit if can't check
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $maxKeys = $row ? intval($row['settingValue']) : 10; // Default to 10

        // Count active keys for partner
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as keyCount FROM tblAPIKeys
            WHERE partnerID = ? AND isActive = 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $partnerID);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $keyCount = $row ? intval($row['keyCount']) : 0;

        return $keyCount >= $maxKeys;
    }

    /**
     * Check if IP address is in whitelist
     *
     * @param string|null $ipWhitelistJson JSON array of allowed IPs
     * @param string $ipAddress IP address to check
     * @return bool IP is allowed
     */
    private function checkIPWhitelist(?string $ipWhitelistJson, string $ipAddress): bool {
        if (!$ipWhitelistJson) {
            return false; // No whitelist = deny
        }

        $whitelist = json_decode($ipWhitelistJson, true);
        if (!is_array($whitelist) || empty($whitelist)) {
            return false;
        }

        // Check for exact match or CIDR match
        foreach ($whitelist as $allowedIP) {
            if ($this->ipInRange($ipAddress, $allowedIP)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP is in range (supports CIDR notation)
     *
     * @param string $ip IP address to check
     * @param string $range IP or CIDR range
     * @return bool IP is in range
     */
    private function ipInRange(string $ip, string $range): bool {
        // Exact match
        if ($ip === $range) {
            return true;
        }

        // CIDR notation support (e.g., 192.168.1.0/24)
        if (strpos($range, '/') !== false) {
            list($subnet, $mask) = explode('/', $range);

            // Convert IP and subnet to long integers
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);

            if ($ipLong === false || $subnetLong === false) {
                return false;
            }

            // Calculate network mask
            $maskLong = -1 << (32 - (int)$mask);
            $subnetLong &= $maskLong; // Calculate network address

            return ($ipLong & $maskLong) === $subnetLong;
        }

        return false;
    }

    /**
     * Update last used timestamp and increment usage count
     *
     * @param int $keyID Key ID
     * @param string|null $ipAddress IP address
     * @return bool Success
     */
    private function updateLastUsed(int $keyID, ?string $ipAddress): bool {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $this->db->prepare("
            UPDATE tblAPIKeys
            SET lastUsedAt = NOW(),
                usageCount = usageCount + 1,
                lastUsedIP = ?,
                lastUsedUserAgent = ?,
                updatedAt = NOW()
            WHERE keyID = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ssi', $ipAddress, $userAgent, $keyID);
        $success = $stmt->execute();
        $stmt->close();

        return $success;
    }

    /**
     * Log audit event
     *
     * @param int|null $keyID Key ID
     * @param int $partnerID Partner ID
     * @param string $action Action performed
     * @param array $details Action details
     * @return bool Success
     */
    private function logAudit(?int $keyID, int $partnerID, string $action, array $details = []): bool {
        $performedBy = isset($_SESSION['userID']) ? $_SESSION['userID'] : null;
        $performedByIP = $_SERVER['REMOTE_ADDR'] ?? null;
        $actionDetails = json_encode($details);

        $stmt = $this->db->prepare("
            INSERT INTO tblAPIKeyAudit (
                keyID, partnerID, action, actionDetails, performedBy, performedByIP
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log("APIKeyManager: Failed to prepare audit statement: " . $this->db->error);
            return false;
        }

        $stmt->bind_param('iissis', $keyID, $partnerID, $action, $actionDetails, $performedBy, $performedByIP);
        $success = $stmt->execute();
        $stmt->close();

        return $success;
    }
}
