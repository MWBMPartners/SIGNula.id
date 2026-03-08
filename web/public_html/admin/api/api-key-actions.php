<?php
/**
 * 🔑 API Key Management Actions (Super Admin)
 *
 * RESTful AJAX endpoint for managing partner API keys.
 * Handles GET requests for key retrieval and POST requests
 * for key creation, revocation, rotation, and updates.
 *
 * Actions (GET):
 *   - get_keys        — List all keys or filter by partnerID
 *   - get_key_details — Retrieve a single key with usage stats
 *
 * Actions (POST):
 *   - create_key      — Generate a new API key for a partner
 *   - revoke_key      — Revoke an existing key with reason
 *   - rotate_key      — Regenerate key value keeping config
 *   - update_key      — Update permissions, IP whitelist, expiry
 *
 * @see https://www.php.net/manual/en/book.mysqli.php — PHP MySQLi Reference
 * @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Prevention
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status — HTTP Status Codes
 * @see https://cheatsheetseries.owasp.org/cheatsheets/REST_Security_Cheat_Sheet.html — REST Security
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.6.0-beta
 */

// ═══════════════════════════════════════════════════════════════════════════════
// 🔌 BACKEND INCLUDES — Load core configuration, database, session & access
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 📂 Load the main application configuration.
 * dirname(__DIR__, 3) navigates from /web/public_html/admin/api/ up to /web/
 * which is the project web root containing _config/.
 * @see https://www.php.net/manual/en/function.dirname.php — PHP dirname()
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

/**
 * 🗄️ Database singleton — provides MySQLi prepared statement wrappers.
 * @see /_backend/Database.php — Database class documentation
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

/**
 * 🔑 Session management — handles login state and session tokens.
 * @see /_backend/SessionManager.php — SessionManager class documentation
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';

/**
 * 🛡️ Access control — role-based permission checks.
 * @see /_backend/AccessControl.php — AccessControl class documentation
 */
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

/**
 * 🔑 APIKeyManager — backend class for partner API key operations.
 * Located in private_html directory (outside web root) for security.
 * @see /private_html/api/APIKeyManager.php — APIKeyManager class documentation
 */
$apiKeyManagerPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'APIKeyManager.php';
if (file_exists($apiKeyManagerPath)) {
    require_once $apiKeyManagerPath;
} else {
    /**
     * ⚠️ If the APIKeyManager class file is missing, return a 500 error.
     * This should never happen in production — indicates a deployment issue.
     */
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API Key Manager component is unavailable. Please contact the system administrator.'
    ]);
    exit;
}

/**
 * 📝 ActivityLogger — logs all admin actions for audit trail.
 * @see /private_html/utils/ActivityLogger.php — ActivityLogger class documentation
 */
$activityLoggerPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'ActivityLogger.php';
if (file_exists($activityLoggerPath)) {
    require_once $activityLoggerPath;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 📤 RESPONSE HEADERS — Set JSON content type for all API responses
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
// ═══════════════════════════════════════════════════════════════════════════════

header('Content-Type: application/json; charset=utf-8');

/**
 * 🛡️ Security headers — prevent MIME sniffing and embedding.
 * @see https://owasp.org/www-project-secure-headers/ — OWASP Secure Headers
 */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ═══════════════════════════════════════════════════════════════════════════════
// 🔌 INITIALISE CORE SERVICES
// ═══════════════════════════════════════════════════════════════════════════════

/** @var Database $db — Database singleton instance for all queries */
$db = Database::getInstance();

/** @var SessionManager $sessionManager — Manages user session state */
$sessionManager = new SessionManager($db);

/** @var AccessControl $accessControl — Enforces role-based access control */
$accessControl = new AccessControl($db, $sessionManager);

// ═══════════════════════════════════════════════════════════════════════════════
// 🔒 AUTHENTICATION CHECK — Reject unauthenticated requests with 401
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/401
// ═══════════════════════════════════════════════════════════════════════════════

if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Please log in to access this resource.'
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🛡️ SUPER ADMIN CHECK — Only super admins can manage API keys
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/403
// ═══════════════════════════════════════════════════════════════════════════════

$userInfo = $sessionManager->getUserInfo();
if (!isset($userInfo['isSuperAdmin']) || $userInfo['isSuperAdmin'] != 1) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Super admin access required to manage API keys.'
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 📥 REQUEST PARSING — Determine action from GET or POST data
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 📥 Parse the incoming request data.
 * GET requests use URL parameters for read-only actions.
 * POST requests use JSON body (php://input) for mutations.
 * @see https://www.php.net/manual/en/wrappers.php.php — PHP I/O Streams
 */
$input = json_decode(file_get_contents('php://input'), true);
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// ═══════════════════════════════════════════════════════════════════════════════
// 🛡️ CSRF VERIFICATION — Required for all POST requests
// @see https://owasp.org/www-community/attacks/csrf — OWASP CSRF Reference
// ═══════════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /**
     * 🔐 Extract the CSRF token from either the JSON body or the X-CSRF-TOKEN header.
     * This dual approach supports both $.ajax() with JSON body and fetch() with headers.
     * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
     */
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired CSRF token. Please refresh the page and try again.'
        ]);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🏗️ INITIALISE API KEY MANAGER
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 🔑 Create an APIKeyManager instance.
 * APIKeyManager is instance-based (not static), requires a DB connection.
 * Passing the raw MySQLi connection from the Database singleton.
 * @see /private_html/api/APIKeyManager.php — Constructor accepts optional $db param
 */
$apiKeyManager = new APIKeyManager($db->getConnection());

// ═══════════════════════════════════════════════════════════════════════════════
// 🔀 ACTION ROUTER — Route to the appropriate handler function
// ═══════════════════════════════════════════════════════════════════════════════

try {
    switch ($action) {
        // ═══════════════════════════════════════════════════════════════
        // 📡 GET ACTIONS — Data retrieval endpoints (read-only)
        // ═══════════════════════════════════════════════════════════════

        case 'get_keys':
            /**
             * 🔑 Retrieve all API keys, optionally filtered by partnerID.
             * If partnerID is provided, returns only that partner's keys.
             * Otherwise queries tblAPIKeys directly for all keys.
             * @see APIKeyManager::getPartnerKeys() — Partner-specific key retrieval
             */
            handleGetKeys($apiKeyManager, $db);
            break;

        case 'get_key_details':
            /**
             * 🔍 Retrieve detailed information for a single API key.
             * Includes key metadata, partner info, and usage statistics.
             * @see APIKeyManager::getKeyByID() — Single key retrieval
             * @see APIKeyManager::getUsageStats() — Usage statistics aggregation
             */
            handleGetKeyDetails($apiKeyManager);
            break;

        // ═══════════════════════════════════════════════════════════════
        // 📡 POST ACTIONS — Mutation endpoints (require CSRF)
        // ═══════════════════════════════════════════════════════════════

        case 'create_key':
            /**
             * ✨ Generate a new API key for a partner.
             * Returns the full raw key ONCE — it is hashed before storage
             * and cannot be retrieved again after this response.
             * @see APIKeyManager::generateKey() — Key generation with SHA-256 hashing
             */
            handleCreateKey($apiKeyManager, $input);
            break;

        case 'revoke_key':
            /**
             * 🚫 Revoke an existing API key with an optional reason.
             * Sets isActive = 0 and records revocation details.
             * @see APIKeyManager::revokeKey() — Key revocation with audit trail
             */
            handleRevokeKey($apiKeyManager, $input);
            break;

        case 'rotate_key':
            /**
             * 🔄 Regenerate an API key keeping existing configuration.
             * Revokes the old key and generates a new one with the same
             * permissions, IP whitelist, and expiry settings.
             * @see APIKeyManager::regenerateKey() — Key rotation with config preservation
             */
            handleRotateKey($apiKeyManager, $input);
            break;

        case 'update_key':
            /**
             * ✏️ Update key settings: permissions, IP whitelist, and/or expiry.
             * Does NOT change the key value itself — use rotate_key for that.
             * @see APIKeyManager::updatePermissions() — Permission updates
             * @see APIKeyManager::updateIPWhitelist() — IP whitelist updates
             */
            handleUpdateKey($apiKeyManager, $input, $db);
            break;

        default:
            /**
             * ❓ Unknown action — return 400 Bad Request.
             * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/400
             */
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Unknown action: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')
            ]);
            break;
    }
} catch (\Exception $e) {
    /**
     * 💥 Global exception handler — catch any unhandled exceptions.
     * Log the full error details but return a safe, generic message to the client.
     * @see https://www.php.net/manual/en/language.exceptions.php — PHP Exceptions
     */
    error_log('🔑 API Key Actions Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // 📝 Log the error to the activity log for admin review
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            $userInfo['userID'] ?? null,
            'api_key_action_error',
            'api_keys',
            'error',
            'API key action failed: ' . $action,
            [
                'error' => $e->getMessage(),
                'action' => $action,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        );
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An internal error occurred while processing your request. Please try again.'
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════════════════════
// 🛠️ HANDLER FUNCTIONS — One function per action for clean separation
// ═══════════════════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 🔑 Handle get_keys action — List all API keys or filter by partner
 *
 * If a partnerID is provided via GET parameter, uses APIKeyManager::getPartnerKeys()
 * to retrieve only that partner's keys. Otherwise queries tblAPIKeys directly
 * with a JOIN to tblPartners for partner name/tier info.
 *
 * @param APIKeyManager $apiKeyManager — Instance of the API key manager
 * @param Database $db — Database instance for direct queries when no filter
 * @return void — Outputs JSON response via echo
 *
 * @see APIKeyManager::getPartnerKeys() — Partner-specific retrieval
 * @see https://dev.mysql.com/doc/refman/8.0/en/join.html — MySQL JOIN syntax
 */
function handleGetKeys(APIKeyManager $apiKeyManager, $db): void
{
    // 🔍 Check if a partnerID filter was provided
    $partnerID = isset($_GET['partnerID']) ? intval($_GET['partnerID']) : null;

    // 📋 Determine whether to show only active keys or all keys
    $activeOnly = isset($_GET['activeOnly']) ? filter_var($_GET['activeOnly'], FILTER_VALIDATE_BOOLEAN) : false;

    if ($partnerID !== null && $partnerID > 0) {
        /**
         * 🏢 Partner-specific key retrieval.
         * Uses the APIKeyManager's built-in method which handles
         * the JOIN to tblPartners and optional active-only filter.
         */
        $keys = $apiKeyManager->getPartnerKeys($partnerID, $activeOnly);
    } else {
        /**
         * 📋 Retrieve ALL keys across all partners.
         * Direct query since APIKeyManager only supports per-partner retrieval.
         * Includes partner info via JOIN for display in the admin table.
         */
        $sql = "
            SELECT k.*, p.partnerName, p.accountTier
            FROM tblAPIKeys k
            JOIN tblPartners p ON k.partnerID = p.partnerID
        ";

        // 🔘 Optionally filter to active keys only
        if ($activeOnly) {
            $sql .= " WHERE k.isActive = 1";
        }

        $sql .= " ORDER BY k.createdAt DESC";

        $stmt = $db->prepare($sql);

        if (!$stmt) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to prepare query for API keys.'
            ]);
            return;
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $keys = [];
        while ($row = $result->fetch_assoc()) {
            $keys[] = $row;
        }
        $stmt->close();
    }

    /**
     * 🧹 Sanitise output — ensure no sensitive hashes leak to the frontend.
     * The keyHash is stored for validation only and should never be exposed.
     * We expose keyPrefix for display (first 20 chars of the original key).
     */
    $sanitisedKeys = array_map(function ($key) {
        // 🚫 Remove the full key hash — never expose this to the client
        unset($key['keyHash']);

        return $key;
    }, $keys);

    echo json_encode([
        'success' => true,
        'data' => $sanitisedKeys,
        'count' => count($sanitisedKeys)
    ]);
}

/**
 * 🔍 Handle get_key_details action — Retrieve single key with usage stats
 *
 * Fetches detailed information for a specific API key by its keyID,
 * including partner metadata and usage statistics for the last 30 days.
 *
 * @param APIKeyManager $apiKeyManager — Instance of the API key manager
 * @return void — Outputs JSON response via echo
 *
 * @see APIKeyManager::getKeyByID() — Key retrieval by ID
 * @see APIKeyManager::getUsageStats() — Usage aggregation (30-day window)
 */
function handleGetKeyDetails(APIKeyManager $apiKeyManager): void
{
    // 📥 Get keyID from GET parameters and validate it
    $keyID = isset($_GET['keyID']) ? intval($_GET['keyID']) : 0;

    if ($keyID <= 0) {
        /**
         * ❌ Invalid or missing keyID parameter.
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/400
         */
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'A valid keyID parameter is required.'
        ]);
        return;
    }

    // 🔑 Fetch key details from the database
    $keyData = $apiKeyManager->getKeyByID($keyID);

    if (!$keyData) {
        /**
         * ❌ Key not found in the database.
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/404
         */
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'API key not found.'
        ]);
        return;
    }

    /**
     * 📊 Fetch usage statistics for the key (last 30 days).
     * Includes: totalRequests, successfulRequests, errorRequests,
     * rateLimitedRequests, avgResponseTime, lastRequest, uniqueIPs, uniqueEndpoints.
     */
    $usageStats = $apiKeyManager->getUsageStats($keyID, 30);

    // 🚫 Remove the key hash — never expose this to the client
    unset($keyData['keyHash']);

    echo json_encode([
        'success' => true,
        'data' => [
            'key' => $keyData,
            'usageStats' => $usageStats
        ]
    ]);
}

/**
 * ✨ Handle create_key action — Generate a new API key for a partner
 *
 * Creates a new API key with the specified configuration. The full raw key
 * is returned ONCE in the response — it is SHA-256 hashed before storage
 * and cannot be retrieved again after this point.
 *
 * Required POST params:
 *   - partnerID (int) — ID of the partner to create the key for
 *   - keyName (string) — Human-readable name/label for the key
 *
 * Optional POST params:
 *   - environment (string) — 'test' or 'live' (default: 'test')
 *   - permissions (array) — JSON array of permission strings
 *   - ipWhitelist (string) — Comma-separated IP addresses/CIDR ranges
 *   - expiresAt (string) — ISO date string for key expiration
 *
 * @param APIKeyManager $apiKeyManager — Instance of the API key manager
 * @param array|null $input — Parsed JSON request body
 * @return void — Outputs JSON response with the raw key (shown ONCE)
 *
 * @see APIKeyManager::generateKey() — Key generation with SHA-256 hashing
 * @see https://www.php.net/manual/en/function.random-bytes.php — Secure random generation
 */
function handleCreateKey(APIKeyManager $apiKeyManager, ?array $input): void
{
    global $userInfo;

    // ═══════════════════════════════════════════════════════════════
    // 📥 VALIDATE REQUIRED PARAMETERS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🏢 partnerID — The partner this key belongs to.
     * Must be a positive integer referencing an active partner in tblPartners.
     */
    $partnerID = isset($input['partnerID']) ? intval($input['partnerID']) : 0;

    /**
     * 🏷️ keyName — Human-readable label for the API key.
     * Sanitised to prevent XSS and injection attacks.
     * @see SecurityUtils::sanitizeString() — Input sanitisation
     */
    $keyName = isset($input['keyName']) ? SecurityUtils::sanitizeString($input['keyName']) : '';

    if ($partnerID <= 0 || empty($keyName)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'partnerID and keyName are required fields.'
        ]);
        return;
    }

    // ═══════════════════════════════════════════════════════════════
    // 📥 PARSE OPTIONAL PARAMETERS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🌍 environment — 'test' or 'live'.
     * Determines the key prefix: sk_test_ or sk_live_.
     * Defaults to 'test' for safety.
     */
    $environment = isset($input['environment']) && $input['environment'] === 'live' ? 'live' : 'test';

    /**
     * 🔐 permissions — Array of permission scopes (e.g., ['users:read', 'users:write']).
     * Stored as JSON in the database.
     */
    $permissions = isset($input['permissions']) && is_array($input['permissions'])
        ? $input['permissions']
        : null;

    /**
     * 🌐 ipWhitelist — Comma or newline separated list of IP addresses/CIDR ranges.
     * Parsed into an array and stored as JSON in the database.
     * @see https://en.wikipedia.org/wiki/Classless_Inter-Domain_Routing — CIDR notation
     */
    $ipWhitelist = null;
    if (!empty($input['ipWhitelist'])) {
        // 🧹 Split by comma or newline, trim whitespace, filter empties
        $ipWhitelist = array_values(array_filter(
            array_map('trim', preg_split('/[,\n]+/', $input['ipWhitelist'])),
            function ($ip) {
                return !empty($ip);
            }
        ));
    }

    /**
     * ⏰ expiresAt — Optional expiration date in ISO format (Y-m-d or Y-m-d H:i:s).
     * Null means the key never expires (until manually revoked).
     */
    $expiresAt = !empty($input['expiresAt'])
        ? SecurityUtils::sanitizeString($input['expiresAt'])
        : null;

    // ═══════════════════════════════════════════════════════════════
    // 🏗️ BUILD OPTIONS ARRAY AND GENERATE KEY
    // ═══════════════════════════════════════════════════════════════

    $options = [];
    if ($permissions !== null) {
        $options['permissions'] = $permissions;
    }
    if ($ipWhitelist !== null) {
        $options['ipWhitelist'] = $ipWhitelist;
    }
    if ($expiresAt !== null) {
        $options['expiresAt'] = $expiresAt;
    }

    /**
     * 🔑 Generate the new API key via APIKeyManager.
     * Returns ['key' => 'sk_test_...', 'keyData' => [...]] on success.
     * The raw key is SHA-256 hashed before storage — this is the ONLY time
     * the full key is available in plaintext.
     */
    $result = $apiKeyManager->generateKey($partnerID, $keyName, $environment, $options);

    if (!$result) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to generate API key. The partner may be inactive or has reached the maximum key limit.'
        ]);
        return;
    }

    // 📝 Log the key creation in the activity log for audit trail
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            $userInfo['userID'] ?? null,
            'api_key_created',
            'api_keys',
            'info',
            'New API key created: ' . $keyName . ' (environment: ' . $environment . ')',
            [
                'keyID' => $result['keyData']['keyID'] ?? null,
                'partnerID' => $partnerID,
                'keyName' => $keyName,
                'environment' => $environment,
                'hasPermissions' => $permissions !== null,
                'hasIPWhitelist' => $ipWhitelist !== null,
                'expiresAt' => $expiresAt
            ]
        );
    }

    // 🚫 Remove the key hash from the response data
    if (isset($result['keyData']['keyHash'])) {
        unset($result['keyData']['keyHash']);
    }

    /**
     * ✅ Return the full raw key to the client.
     * ⚠️ IMPORTANT: This is the ONLY time the full key is returned.
     * After this response, only the keyPrefix (first 20 chars) is available.
     */
    echo json_encode([
        'success' => true,
        'message' => 'API key generated successfully. Copy it now — it will not be shown again.',
        'data' => [
            'rawKey' => $result['key'],
            'keyData' => $result['keyData']
        ]
    ]);
}

/**
 * 🚫 Handle revoke_key action — Revoke an existing API key
 *
 * Marks the key as inactive with a timestamp and optional reason.
 * Revoked keys cannot be used for authentication and cannot be un-revoked.
 *
 * Required POST params:
 *   - keyID (int) — ID of the key to revoke
 *
 * Optional POST params:
 *   - reason (string) — Human-readable reason for revocation
 *
 * @param APIKeyManager $apiKeyManager — Instance of the API key manager
 * @param array|null $input — Parsed JSON request body
 * @return void — Outputs JSON response
 *
 * @see APIKeyManager::revokeKey() — Key revocation with audit logging
 */
function handleRevokeKey(APIKeyManager $apiKeyManager, ?array $input): void
{
    global $userInfo;

    // 📥 Validate required keyID parameter
    $keyID = isset($input['keyID']) ? intval($input['keyID']) : 0;

    if ($keyID <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'A valid keyID is required to revoke a key.'
        ]);
        return;
    }

    /**
     * 📝 Optional reason for revocation.
     * Sanitised to prevent XSS/injection — stored in tblAPIKeys.revokedReason.
     * @see SecurityUtils::sanitizeString() — Input sanitisation
     */
    $reason = isset($input['reason']) ? SecurityUtils::sanitizeString($input['reason']) : null;

    /**
     * 👤 The current admin user performing the revocation.
     * Stored in tblAPIKeys.revokedBy for audit trail.
     */
    $revokedBy = $userInfo['userID'] ?? null;

    // 🚫 Revoke the key via APIKeyManager
    $success = $apiKeyManager->revokeKey($keyID, $revokedBy, $reason);

    if (!$success) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to revoke API key. It may not exist or may already be revoked.'
        ]);
        return;
    }

    // 📝 Log the revocation in the activity log
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            $revokedBy,
            'api_key_revoked',
            'api_keys',
            'warning',
            'API key revoked (keyID: ' . $keyID . ')',
            [
                'keyID' => $keyID,
                'reason' => $reason,
                'revokedBy' => $revokedBy
            ]
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'API key has been revoked successfully.'
    ]);
}

/**
 * 🔄 Handle rotate_key action — Regenerate key value keeping configuration
 *
 * Revokes the existing key and generates a new one with the same
 * permissions, IP whitelist, expiry, and rate limit settings.
 * The new raw key is returned ONCE — it cannot be retrieved again.
 *
 * Required POST params:
 *   - keyID (int) — ID of the key to rotate
 *
 * @param APIKeyManager $apiKeyManager — Instance of the API key manager
 * @param array|null $input — Parsed JSON request body
 * @return void — Outputs JSON response with the new raw key (shown ONCE)
 *
 * @see APIKeyManager::regenerateKey() — Key rotation with config preservation
 */
function handleRotateKey(APIKeyManager $apiKeyManager, ?array $input): void
{
    global $userInfo;

    // 📥 Validate required keyID parameter
    $keyID = isset($input['keyID']) ? intval($input['keyID']) : 0;

    if ($keyID <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'A valid keyID is required to rotate a key.'
        ]);
        return;
    }

    /**
     * 👤 The current admin user performing the rotation.
     * Passed to regenerateKey() for audit trail logging.
     */
    $performedBy = $userInfo['userID'] ?? null;

    /**
     * 🔄 Regenerate the key via APIKeyManager.
     * This revokes the old key and creates a new one with identical settings.
     * Returns ['key' => 'sk_..._new', 'keyData' => [...]] on success.
     */
    $result = $apiKeyManager->regenerateKey($keyID, $performedBy);

    if (!$result) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to rotate API key. The key may not exist, be already revoked, or the partner may be inactive.'
        ]);
        return;
    }

    // 📝 Log the key rotation in the activity log
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            $performedBy,
            'api_key_rotated',
            'api_keys',
            'info',
            'API key rotated (old keyID: ' . $keyID . ', new keyID: ' . ($result['keyData']['keyID'] ?? 'unknown') . ')',
            [
                'oldKeyID' => $keyID,
                'newKeyID' => $result['keyData']['keyID'] ?? null,
                'performedBy' => $performedBy
            ]
        );
    }

    // 🚫 Remove the key hash from the response data
    if (isset($result['keyData']['keyHash'])) {
        unset($result['keyData']['keyHash']);
    }

    /**
     * ✅ Return the new full raw key to the client.
     * ⚠️ IMPORTANT: This is the ONLY time the new key is returned.
     * The old key has been revoked and is no longer valid.
     */
    echo json_encode([
        'success' => true,
        'message' => 'API key rotated successfully. Copy the new key now — it will not be shown again.',
        'data' => [
            'rawKey' => $result['key'],
            'keyData' => $result['keyData']
        ]
    ]);
}

/**
 * ✏️ Handle update_key action — Update key permissions, IP whitelist, and/or expiry
 *
 * Updates the configuration of an existing API key without changing the key
 * value itself. To change the actual key, use the rotate_key action instead.
 *
 * Required POST params:
 *   - keyID (int) — ID of the key to update
 *
 * Optional POST params (at least one required):
 *   - permissions (array) — New permission scopes to set
 *   - ipWhitelist (string) — New comma-separated IP whitelist
 *   - requireIPWhitelist (bool) — Whether to enforce the IP whitelist
 *   - expiresAt (string|null) — New expiration date (null to remove)
 *
 * @param APIKeyManager $apiKeyManager — Instance of the API key manager
 * @param array|null $input — Parsed JSON request body
 * @param Database $db — Database instance for direct expiry updates
 * @return void — Outputs JSON response
 *
 * @see APIKeyManager::updatePermissions() — Permission update method
 * @see APIKeyManager::updateIPWhitelist() — IP whitelist update method
 */
function handleUpdateKey(APIKeyManager $apiKeyManager, ?array $input, $db): void
{
    global $userInfo;

    // 📥 Validate required keyID parameter
    $keyID = isset($input['keyID']) ? intval($input['keyID']) : 0;

    if ($keyID <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'A valid keyID is required to update a key.'
        ]);
        return;
    }

    /**
     * 👤 The current admin user performing the update.
     * Used for audit logging in each update method.
     */
    $performedBy = $userInfo['userID'] ?? null;

    // 🔄 Track what was updated for the response message
    $updatedFields = [];
    $allSuccess = true;

    // ═══════════════════════════════════════════════════════════════
    // 🔐 UPDATE PERMISSIONS (if provided)
    // ═══════════════════════════════════════════════════════════════

    if (isset($input['permissions']) && is_array($input['permissions'])) {
        /**
         * 🔐 Update the key's permission scopes.
         * Permissions are stored as a JSON array in tblAPIKeys.permissions.
         * @see APIKeyManager::updatePermissions()
         */
        $success = $apiKeyManager->updatePermissions($keyID, $input['permissions'], $performedBy);
        if ($success) {
            $updatedFields[] = 'permissions';
        } else {
            $allSuccess = false;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 🌐 UPDATE IP WHITELIST (if provided)
    // ═══════════════════════════════════════════════════════════════

    if (isset($input['ipWhitelist'])) {
        /**
         * 🌐 Parse and update the IP whitelist.
         * Accepts comma or newline separated IP addresses/CIDR ranges.
         * Empty string clears the whitelist.
         */
        $ipAddresses = [];
        if (!empty($input['ipWhitelist'])) {
            $ipAddresses = array_values(array_filter(
                array_map('trim', preg_split('/[,\n]+/', $input['ipWhitelist'])),
                function ($ip) {
                    return !empty($ip);
                }
            ));
        }

        /**
         * 🔘 Whether to enforce the IP whitelist.
         * If true, requests from IPs not in the whitelist will be rejected.
         * Defaults to true if any IPs are provided.
         */
        $requireWhitelist = isset($input['requireIPWhitelist'])
            ? filter_var($input['requireIPWhitelist'], FILTER_VALIDATE_BOOLEAN)
            : !empty($ipAddresses);

        $success = $apiKeyManager->updateIPWhitelist($keyID, $ipAddresses, $requireWhitelist, $performedBy);
        if ($success) {
            $updatedFields[] = 'ipWhitelist';
        } else {
            $allSuccess = false;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ⏰ UPDATE EXPIRY DATE (if provided)
    // ═══════════════════════════════════════════════════════════════

    if (array_key_exists('expiresAt', $input ?? [])) {
        /**
         * ⏰ Update the key's expiration date.
         * Set to null to remove expiration (key never expires).
         * APIKeyManager doesn't have a dedicated method for this,
         * so we update tblAPIKeys directly via a prepared statement.
         */
        $expiresAt = !empty($input['expiresAt'])
            ? SecurityUtils::sanitizeString($input['expiresAt'])
            : null;

        $stmt = $db->prepare("
            UPDATE tblAPIKeys
            SET expiresAt = ?,
                updatedAt = NOW()
            WHERE keyID = ?
        ");

        if ($stmt) {
            $stmt->bind_param('si', $expiresAt, $keyID);
            $success = $stmt->execute();
            $stmt->close();

            if ($success) {
                $updatedFields[] = 'expiresAt';
            } else {
                $allSuccess = false;
            }
        } else {
            $allSuccess = false;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 📤 RESPONSE
    // ═══════════════════════════════════════════════════════════════

    if (empty($updatedFields)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No valid fields provided to update. Provide at least one of: permissions, ipWhitelist, expiresAt.'
        ]);
        return;
    }

    // 📝 Log the key update in the activity log
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log(
            $performedBy,
            'api_key_updated',
            'api_keys',
            'info',
            'API key updated (keyID: ' . $keyID . ', fields: ' . implode(', ', $updatedFields) . ')',
            [
                'keyID' => $keyID,
                'updatedFields' => $updatedFields,
                'performedBy' => $performedBy
            ]
        );
    }

    echo json_encode([
        'success' => $allSuccess,
        'message' => $allSuccess
            ? 'API key updated successfully. Fields updated: ' . implode(', ', $updatedFields) . '.'
            : 'Some fields were updated but errors occurred. Updated: ' . implode(', ', $updatedFields) . '.',
        'data' => [
            'updatedFields' => $updatedFields
        ]
    ]);
}
