<?php
/**
 * ============================================================================
 * 📝 SIGNula - Activity Logger
 * ============================================================================
 *
 * Purpose: Log user and system activities for audit and debugging
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Utils
 * @version    1.0.0
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 📊 Activity Logger Class
 *
 * Logs all user and system activities to database for audit trail.
 */
class ActivityLogger
{
    /**
     * 📝 Log Activity
     *
     * @param int|null $userID User ID (null for system events)
     * @param string $activityType Activity type
     * @param string $category Activity category
     * @param string $severity Severity level
     * @param string $description Activity description
     * @param array $metadata Additional metadata
     * @param int|null $sessionID Session ID
     * @return bool Success status
     */
    public static function log(
        ?int $userID,
        string $activityType,
        string $category = 'other',
        string $severity = 'info',
        string $description = '',
        array $metadata = [],
        ?int $sessionID = null
    ): bool {
        try {
            // Get session ID from current session if not provided
            if ($sessionID === null && isset($_SESSION['session_id'])) {
                $sessionID = $_SESSION['session_id'];
            }

            // Get request information
            $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
            $requestURL = getCurrentURL();
            $requestHeaders = self::getRequestHeaders();

            $query = "
                INSERT INTO tblActivityLog (
                    userID, sessionID, activityType, activityCategory,
                    severity, description, metadata,
                    ipAddress, userAgent, requestMethod, requestURL,
                    requestHeaders, success, createdAt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, NOW())
            ";

            Database::query($query, [
                $userID,
                $sessionID,
                $activityType,
                $category,
                $severity,
                $description,
                json_encode($metadata, JSON_UNESCAPED_UNICODE),
                getClientIP(),
                getUserAgent(),
                $requestMethod,
                $requestURL,
                json_encode($requestHeaders, JSON_UNESCAPED_UNICODE)
            ], 'iissssssssss');

            return true;

        } catch (Exception $e) {
            error_log("Activity logging failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 📋 Get Request Headers
     *
     * @return array Request headers
     */
    private static function getRequestHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[$headerName] = $value;
            }
        }

        return $headers;
    }
}

// ✅ ActivityLogger loaded successfully
